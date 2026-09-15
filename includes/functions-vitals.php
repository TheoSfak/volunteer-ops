<?php
/**
 * VolunteerOps - Rescuer vitals (heart rate) core
 *
 * One pipeline feeds two consumers: the Action Room's live badge next to a
 * volunteer's name, and the post-mission report's heart-rate curve. Both read
 * the same volunteer_vitals rows, which is the whole point — there is no
 * separate "live" store that later has to be reconciled with a "history" one.
 *
 * Deliberately NOT a Huawei/Health-Kit integration. Huawei's own docs give
 * heart rate a "Data Timeliness: In hours" rating on both the cloud REST API
 * and the on-device SDK, and Wear Engine exposes no BPM value at all (only
 * high/low heart-rate ALERTS, and only to enterprise developers). What this
 * reads instead is the standard Bluetooth LE Heart Rate Service (0x180D /
 * characteristic 0x2A37) — the same profile Polar/Garmin/Wahoo straps and
 * Huawei's own "HR Data Broadcast" mode speak. So the client side filters on
 * a service UUID, never on a vendor name, and any standards-compliant sensor
 * works without a server change.
 *
 * Lives in its own file rather than in functions-warroom.php because that one
 * is already ~5.500 lines; this is a self-contained sub-domain with its own
 * table, settings and lifecycle.
 */

if (!defined('VOLUNTEEROPS')) {
    die('Direct access not permitted');
}

/**
 * Hard physiological bounds for a single accepted sample. Anything outside
 * these is a sensor artefact (a strap losing contact reports 0; a bad optical
 * read on a wrist can spike into the 300s), not a reading worth storing — and
 * a stored artefact is worse than a gap, because the live badge would paint
 * it red and the report's curve would carry a spike that never happened.
 */
const VITALS_MIN_BPM = 25;
const VITALS_MAX_BPM = 240;

/**
 * Largest batch a single ingest call may carry. At the default 5-second
 * sampling window this is 20 minutes of buffered data, which is enough to
 * cover a volunteer walking out of coverage and flushing on reconnect,
 * while still bounding the work one request can ask of the database.
 */
const VITALS_MAX_BATCH = 240;

/**
 * Oldest sample an ingest call may backfill, in seconds. A buffer that has
 * been sitting offline for longer than this is no longer operationally
 * interesting and, more importantly, an unbounded window would let a crafted
 * request write rows into an arbitrary point of a mission's history.
 */
const VITALS_MAX_BACKFILL_SECONDS = 7200; // 2 hours

/**
 * All vitals tuning in one request-cached read, with the same defaults the
 * settings form advertises. Every consumer (ingest validation, zone
 * colouring, staleness, retention) goes through here so there is exactly one
 * place where a default lives.
 */
function vitalsConfig(): array {
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    $config = [
        'enabled'         => getSetting('vitals_enabled', '0') === '1',
        // Seconds of sensor data the CLIENT aggregates into one stored row.
        // The sensor itself notifies about once a second; storing every one
        // of those would mean ~21.600 rows per volunteer per six-hour shift
        // for a curve the eye cannot tell apart from this one.
        'sample_seconds'  => max(1, min(60, (int) getSetting('vitals_sample_seconds', '5'))),
        // Percentages of the person's estimated maximum heart rate.
        'elevated_pct'    => max(40, min(100, (int) getSetting('vitals_elevated_pct', '75'))),
        'critical_pct'    => max(50, min(100, (int) getSetting('vitals_critical_pct', '88'))),
        // An absolute floor, not a percentage: bradycardia is dangerous at
        // the same number whatever your age or fitness.
        'low_bpm'         => max(VITALS_MIN_BPM, min(60, (int) getSetting('vitals_low_bpm', '40'))),
        // Age the zone thresholds are computed against. This app stores no
        // date of birth for a user — birth_date exists only on
        // volunteer_applications/citizens, never on users — so the zones are
        // the same for everyone until such a field exists, and this setting
        // is how an org tunes them to the age profile of its own roster.
        'reference_age'   => max(16, min(90, (int) getSetting('vitals_reference_age', '40'))),
        // After this long with no sample the badge goes grey. Deliberately
        // shorter than the GPS ping staleness threshold: a heart-rate sensor
        // that stops reporting usually means the strap came off or the
        // Bluetooth link dropped, both of which command staff want to see
        // quickly rather than after the next GPS interval.
        'stale_seconds'   => max(30, min(1800, (int) getSetting('vitals_stale_seconds', '120'))),
        'retention_days'  => max(7, min(3650, (int) getSetting('vitals_retention_days', '365'))),
    ];

    // A critical threshold at or below the elevated one would make "elevated"
    // unreachable and paint everything red. Clamp rather than reject: these
    // are two independent number inputs on an admin form, and a momentarily
    // inconsistent pair must not break the live view for everyone.
    if ($config['critical_pct'] <= $config['elevated_pct']) {
        $config['critical_pct'] = min(100, $config['elevated_pct'] + 5);
    }

    return $config;
}

/**
 * Whether the feature is switched on at all. Every render site and the ingest
 * endpoint check this, so an org that does not use heart-rate monitoring pays
 * nothing: no extra query on the 5-second Action Room poll, no badge, no
 * settings surface beyond the single toggle.
 */
function vitalsEnabled(): bool {
    return vitalsConfig()['enabled'];
}

/**
 * Estimated maximum heart rate for zone colouring.
 *
 * The plain 220-minus-age formula, chosen on purpose over the more accurate
 * Tanaka/Gellish variants: this drives a three-colour operational badge, not
 * a training prescription, and 220-age is the number a rescue team's own
 * first-aid training already uses, so the thresholds match what the people
 * reading the screen expect.
 *
 * $birthDate is null at every call site today, because this app has no date
 * of birth for a user: birth_date exists on volunteer_applications, citizens
 * and citizen_certificates, but never on users — a candidate's application is
 * transcribed into a user row by hand and that field is not among the ones
 * carried over. So every volunteer is scored against the configurable
 * reference age instead. The parameter stays because the day a DOB lands on
 * users, per-person zones become one changed call site rather than a rewrite.
 */
function vitalsMaxHeartRate(?string $birthDate = null): int {
    $age = vitalsConfig()['reference_age'];
    if (!empty($birthDate) && $birthDate !== '0000-00-00') {
        $ts = strtotime($birthDate);
        if ($ts !== false) {
            $computed = (int) floor((time() - $ts) / (365.25 * 86400));
            if ($computed >= 14 && $computed <= 100) {
                $age = $computed;
            }
        }
    }
    return 220 - $age;
}

/**
 * Operational zone for one reading: 'low' | 'ok' | 'elevated' | 'critical'.
 * The caller decides what grey (no data / stale) looks like — that is a
 * property of the timestamp, not of the value, so it is not this function's
 * business.
 */
function vitalsZone(int $bpm, int $maxHeartRate, ?array $config = null): string {
    $config = $config ?? vitalsConfig();

    // Checked before the high thresholds: a dangerously low heart rate in
    // someone who is working is its own emergency, and it must not be
    // reported as a comfortable "ok" just because it is below every
    // percentage cut-off.
    if ($bpm <= $config['low_bpm']) {
        return 'low';
    }

    $pct = $maxHeartRate > 0 ? ($bpm / $maxHeartRate) * 100 : 0;
    if ($pct >= $config['critical_pct']) {
        return 'critical';
    }
    if ($pct >= $config['elevated_pct']) {
        return 'elevated';
    }
    return 'ok';
}

/**
 * Store a batch of heart-rate samples for one volunteer on one shift.
 *
 * Shares recordVolunteerPing()'s authorisation shape exactly — an APPROVED
 * participation on a shift of an OPEN, shown-in-ops mission — so a volunteer
 * can never write vitals into a mission they are not actually deployed on,
 * and vitals stop being accepted the moment a mission closes.
 *
 * $samples is a list of [clientTimestampMs, bpm] or [clientTimestampMs, bpm,
 * min, max]. The client's clock is never trusted as an absolute: the caller
 * passes $clientNowMs (that same clock read at the instant of sending) and
 * every sample is shifted by the measured skew between it and the server's
 * clock. A phone whose clock is two hours off therefore still lands its
 * samples at the right point in the mission's timeline, and a retry of the
 * same buffer computes the same second and is swallowed by the unique key
 * rather than drawing the curve twice.
 */
function recordVolunteerVitals(array $user, int $shiftId, array $samples, ?float $clientNowMs, ?string $deviceName, string $source = 'ble'): array {
    $userId = (int) $user['id'];
    $lang   = $user['language'] ?? DEFAULT_LANGUAGE;

    if (!vitalsEnabled()) {
        return ['ok' => false, 'error' => t('vitals.disabled', [], $lang)];
    }
    if (!$samples) {
        return ['ok' => false, 'error' => t('vitals.no_samples', [], $lang)];
    }
    if (!in_array($source, ['ble', 'manual', 'simulated'], true)) {
        $source = 'ble';
    }

    $participation = dbFetchOne(
        "SELECT pr.id, s.mission_id FROM participation_requests pr
         JOIN shifts s ON pr.shift_id = s.id
         JOIN missions m ON s.mission_id = m.id
         WHERE pr.shift_id = ? AND pr.volunteer_id = ? AND pr.status = ?
           AND m.status = ? AND m.show_in_ops = 1 AND m.deleted_at IS NULL",
        [$shiftId, $userId, PARTICIPATION_APPROVED, STATUS_OPEN]
    );
    if (!$participation) {
        return ['ok' => false, 'error' => t('ping.mission_not_open_or_not_approved', [], $lang)];
    }

    $serverNowMs = microtime(true) * 1000;
    // No client clock reading at all (a curl test, a minimal client): treat
    // the batch as ending now, which is what a sender with no clock means.
    $skewMs = $clientNowMs !== null ? ($serverNowMs - $clientNowMs) : 0.0;

    $device = $deviceName !== null ? mb_substr(trim($deviceName), 0, 64) : null;
    if ($device === '') {
        $device = null;
    }

    $rows     = [];
    $binds    = [];
    $latest   = null;
    $rejected = 0;

    foreach (array_slice($samples, 0, VITALS_MAX_BATCH) as $sample) {
        if (!is_array($sample) || count($sample) < 2) {
            $rejected++;
            continue;
        }

        $bpm = (int) round((float) $sample[1]);
        if ($bpm < VITALS_MIN_BPM || $bpm > VITALS_MAX_BPM) {
            // Reject, never clamp — same reasoning as the battery level in
            // ping-location.php. A clamped artefact is indistinguishable from
            // a real reading at the boundary, and this one would trip an alarm.
            $rejected++;
            continue;
        }

        $recordedMs = ((float) $sample[0]) + $skewMs;
        $ageSeconds = ($serverNowMs - $recordedMs) / 1000;
        // A sample from the future is a clock artefact; one older than the
        // backfill window is not worth writing. Both are dropped rather than
        // pinned to the boundary, for the same reason as the bpm bounds.
        if ($ageSeconds < -60 || $ageSeconds > VITALS_MAX_BACKFILL_SECONDS) {
            $rejected++;
            continue;
        }

        $recordedAt = date('Y-m-d H:i:s', (int) round($recordedMs / 1000));

        $min = isset($sample[2]) && is_numeric($sample[2]) ? (int) round((float) $sample[2]) : null;
        $max = isset($sample[3]) && is_numeric($sample[3]) ? (int) round((float) $sample[3]) : null;
        if ($min !== null && ($min < VITALS_MIN_BPM || $min > VITALS_MAX_BPM)) $min = null;
        if ($max !== null && ($max < VITALS_MIN_BPM || $max > VITALS_MAX_BPM)) $max = null;

        $rows[] = '(?, ?, ?, ?, ?, ?, ?, ?)';
        array_push($binds, $userId, $shiftId, $bpm, $min, $max, $source, $device, $recordedAt);

        if ($latest === null || $recordedMs > $latest['ms']) {
            $latest = ['ms' => $recordedMs, 'bpm' => $bpm];
        }
    }

    if (!$rows) {
        return ['ok' => false, 'error' => t('vitals.no_valid_samples', [], $lang), 'rejected' => $rejected];
    }

    try {
        // INSERT IGNORE against uk_vitals_sample: a client that flushes the
        // same buffer twice (lost response, app restart mid-send) writes the
        // batch once. Idempotent retries are the reason the unique key exists.
        //
        // The row count it returns is the number actually written, which is
        // what the response reports — so a client can tell a real write from
        // a swallowed replay instead of being told "stored 6" both times.
        $stored = (int) dbExecute(
            "INSERT IGNORE INTO volunteer_vitals
                (user_id, shift_id, bpm, bpm_min, bpm_max, source, device_name, recorded_at)
             VALUES " . implode(', ', $rows),
            $binds
        );
    } catch (Exception $e) {
        return ['ok' => false, 'error' => t('vitals.storage_unavailable', [], $lang)];
    }

    $maxHr = vitalsMaxHeartRate();

    return [
        'ok'       => true,
        'accepted' => count($rows),
        'stored'   => $stored,
        'rejected' => $rejected,
        'bpm'      => $latest['bpm'] ?? null,
        'zone'     => $latest ? vitalsZone($latest['bpm'], $maxHr) : null,
        'ts'       => date('H:i:s'),
    ];
}

/**
 * Latest reading per volunteer for one mission, keyed by volunteer id.
 *
 * Takes the mission's shift ids as already-resolved constants for the same
 * hard reason every ping query on war-room.php does: MySQL only answers
 * "latest row per volunteer" from an index when the shift ids arrive as
 * literals, and reaching them through a join puts a full scan of the
 * mission's entire vitals history into the 5-second poll. The derived table
 * below is the same loose-index-scan shape as the last_ping_at lookup it
 * rides alongside — MAX(id) off idx_vitals_user_shift, then one primary-key
 * read for that row's values.
 *
 * Returns [] when the feature is off, so the caller can merge unconditionally.
 */
function loadLatestVitalsByVolunteerId(int $missionId, array $shiftBinds, string $shiftPlaceholders): array {
    if (!vitalsEnabled()) {
        return [];
    }

    $config = vitalsConfig();
    $out    = [];

    try {
        // No join to users: the only thing it would have carried is a date of
        // birth this app does not store, and the 5-second poll should not pay
        // for a join that returns nothing it uses.
        $rows = dbFetchAll(
            "SELECT v.user_id, v.bpm, v.recorded_at, v.device_name
             FROM (SELECT user_id, shift_id, MAX(id) AS max_id
                     FROM volunteer_vitals
                    WHERE shift_id IN ({$shiftPlaceholders})
                    GROUP BY user_id, shift_id) l
             JOIN volunteer_vitals v ON v.id = l.max_id",
            $shiftBinds
        );
    } catch (Exception $e) {
        // Same degradation rule as k9Handlers(): a vitals table that is not
        // there yet (migration still in its failure cooldown) must cost the
        // Action Room its badge, not its poll.
        return [];
    }

    foreach ($rows as $row) {
        $userId    = (int) $row['user_id'];
        $bpm       = (int) $row['bpm'];
        $recorded  = strtotime($row['recorded_at']);
        $ageSecs   = time() - $recorded;
        $isStale   = $ageSecs > $config['stale_seconds'];

        // A volunteer can appear once per shift; on a multi-shift mission keep
        // the most recent of those rather than whichever the driver returned last.
        if (isset($out[$userId]) && $out[$userId]['recorded_ts'] >= $recorded) {
            continue;
        }

        $out[$userId] = [
            'bpm'         => $bpm,
            'zone'        => $isStale ? 'stale' : vitalsZone($bpm, vitalsMaxHeartRate(), $config),
            'is_stale'    => $isStale,
            'age_seconds' => max(0, $ageSecs),
            'recorded_ts' => $recorded,
            'recorded_at' => formatDateTime($row['recorded_at'], 'H:i:s'),
            'device'      => $row['device_name'],
        ];
    }

    return $out;
}

/**
 * The ♥ badge that rides next to a volunteer's name in the Action Room.
 *
 * Mirrors k9BadgeHtml()/captainBadgeHtml()'s contract — returns '' when there
 * is nothing to show, so every call site can append it unconditionally — but
 * takes the reading as an argument instead of looking it up. Those two badges
 * describe a standing property of a person and can afford one org-wide query
 * behind a static cache; this one is per-mission live data that the caller
 * already has in hand from loadLatestVitalsByVolunteerId(), and re-querying
 * per name would put one round trip per volunteer into every 5-second poll.
 *
 * Passing $userId switches it to the roster's "live slot" mode: the span is
 * emitted even with no reading yet, carrying id="vitals-badge-{id}" and
 * d-none, so the 5-second poll has a node to fill for someone whose sensor
 * connects after the page was rendered. Without that the first reading of a
 * deployment would only appear on a manual reload — the same reason the
 * fatigue badge is always in the roster markup and only its class is toggled.
 */
function vitalsBadgeHtml(?array $reading, ?string $lang = null, ?int $userId = null): string {
    if (!vitalsEnabled() || (!$reading && $userId === null)) {
        return '';
    }

    $bpm  = $reading ? (int) $reading['bpm'] : null;
    $zone = $reading ? (string) $reading['zone'] : 'stale';

    if ($reading) {
        $tooltip = $reading['is_stale']
            ? t('vitals.badge_stale_tooltip', ['bpm' => $bpm, 'time' => $reading['recorded_at']], $lang)
            : t('vitals.badge_tooltip', ['bpm' => $bpm, 'zone' => t('vitals.zone_' . $zone, [], $lang)], $lang);
    } else {
        $tooltip = '';
    }

    return '<span' . ($userId !== null ? ' id="vitals-badge-' . $userId . '"' : '')
        . ' class="vitals-badge vitals-zone-' . h($zone) . ($reading ? '' : ' d-none') . '"'
        . ' title="' . h($tooltip) . '">'
        . '<i class="bi bi-heart-pulse-fill"></i> ' . ($bpm !== null ? $bpm : '') . '</span>';
}

/**
 * Heart rate collapsed to one value per user per minute, for laying vitals
 * over the GPS trail ("Πορεία Ομάδων"): [userId][minuteBucket] => [bpm, zone].
 *
 * Per minute rather than per sample because of what it is joined against. GPS
 * pings arrive every few minutes (war_room_auto_ping_seconds defaults to 180),
 * vitals every five seconds, so a trail point only ever needs "what was their
 * heart rate around then" — and matching each ping to its nearest individual
 * sample would mean either a correlated subquery per point (thousands of them)
 * or pulling every sample of the mission into PHP (a six-hour deployment of
 * twelve people is ~50.000 rows) to throw almost all of it away. The GROUP BY
 * does that collapse in the database and returns at most 60 rows per user per
 * hour.
 *
 * The zone comes from the minute's average, not its peak: this is an overlay
 * on a route, answering "roughly what was happening here", and a single
 * five-second spike promoted to a red marker on the map would send command
 * staff looking for an emergency that a person's own pulse produces
 * routinely. The full sample-by-sample series, where a genuine spike is
 * visible, is loadVitalsSeriesForMission()'s job.
 *
 * No per-viewer gate here, unlike the live map: every caller of this is behind
 * mission-track.php's hard canManageActionRoom() check, so there is no
 * volunteer-facing path to leak through. That is a property of the caller, so
 * anything new that calls this must re-check it.
 */
function loadVitalsByMinuteForMission(int $missionId): array {
    if (!vitalsEnabled()) {
        return [];
    }

    $shiftIds = array_column(dbFetchAll("SELECT id FROM shifts WHERE mission_id = ?", [$missionId]), 'id');
    if (!$shiftIds) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($shiftIds), '?'));

    try {
        $rows = dbFetchAll(
            "SELECT user_id,
                    FLOOR(UNIX_TIMESTAMP(recorded_at) / 60) AS minute_bucket,
                    ROUND(AVG(bpm)) AS bpm
             FROM volunteer_vitals
             WHERE shift_id IN ({$placeholders})
             GROUP BY user_id, minute_bucket",
            $shiftIds
        );
    } catch (Exception $e) {
        return [];
    }

    $maxHr  = vitalsMaxHeartRate();
    $config = vitalsConfig();
    $out    = [];

    foreach ($rows as $row) {
        $bpm = (int) $row['bpm'];
        $out[(int) $row['user_id']][(int) $row['minute_bucket']] = [
            'bpm'  => $bpm,
            'zone' => vitalsZone($bpm, $maxHr, $config),
        ];
    }

    return $out;
}

/**
 * Full per-volunteer sample series for one mission, for the post-mission
 * report. Ordered oldest-first so a chart can consume it directly.
 *
 * This is the half of the feature that Huawei's cloud API was never going to
 * deliver: because the live stream is stored as it arrives, the debrief gets
 * the real second-by-second shape of the deployment rather than an hourly
 * aggregate synced some time after everyone went home.
 */
function loadVitalsSeriesForMission(int $missionId): array {
    if (!vitalsEnabled()) {
        return [];
    }

    $shiftIds = array_column(dbFetchAll("SELECT id FROM shifts WHERE mission_id = ?", [$missionId]), 'id');
    if (!$shiftIds) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($shiftIds), '?'));

    try {
        $rows = dbFetchAll(
            "SELECT v.user_id, u.name, v.bpm, v.bpm_min, v.bpm_max, v.recorded_at
             FROM volunteer_vitals v
             JOIN users u ON u.id = v.user_id
             WHERE v.shift_id IN ({$placeholders})
             ORDER BY v.user_id, v.recorded_at",
            $shiftIds
        );
    } catch (Exception $e) {
        return [];
    }

    $config = vitalsConfig();
    $series = [];

    foreach ($rows as $row) {
        $userId = (int) $row['user_id'];
        if (!isset($series[$userId])) {
            $series[$userId] = [
                'user_id'    => $userId,
                'name'       => $row['name'],
                'max_hr'     => vitalsMaxHeartRate(),
                'samples'    => [],
                'bpm_min'    => null,
                'bpm_max'    => null,
                'bpm_sum'    => 0,
                'zone_secs'  => ['low' => 0, 'ok' => 0, 'elevated' => 0, 'critical' => 0],
            ];
        }

        $bpm = (int) $row['bpm'];
        $entry = &$series[$userId];
        $entry['samples'][] = ['t' => $row['recorded_at'], 'bpm' => $bpm];
        $entry['bpm_sum']  += $bpm;
        $entry['bpm_min']   = $entry['bpm_min'] === null ? $bpm : min($entry['bpm_min'], $bpm);
        $entry['bpm_max']   = $entry['bpm_max'] === null ? $bpm : max($entry['bpm_max'], $bpm);
        // Each stored row represents one sampling window, so time-in-zone is
        // sample count x window length — not wall-clock between samples, which
        // would silently charge a coverage gap to whatever zone preceded it.
        $entry['zone_secs'][vitalsZone($bpm, $entry['max_hr'], $config)] += $config['sample_seconds'];
        unset($entry);
    }

    foreach ($series as &$entry) {
        $count = count($entry['samples']);
        $entry['bpm_avg'] = $count ? (int) round($entry['bpm_sum'] / $count) : null;
        unset($entry['bpm_sum']);
    }
    unset($entry);

    return array_values($series);
}

/**
 * Retention sweep, called from the daily cron alongside the other health
 * cleanups. Vitals are the densest table this app writes — one row per
 * volunteer per sampling window — and they are also health data under Article
 * 9 GDPR, so keeping them forever is both a storage and a compliance problem.
 * Deletes in bounded chunks so a long-neglected install cannot lock the table
 * for the length of one enormous DELETE.
 */
function purgeOldVitals(): int {
    $days    = vitalsConfig()['retention_days'];
    $deleted = 0;

    try {
        do {
            $affected = dbExecute(
                "DELETE FROM volunteer_vitals WHERE recorded_at < DATE_SUB(NOW(), INTERVAL ? DAY) LIMIT 5000",
                [$days]
            );
            $affected = (int) $affected;
            $deleted += $affected;
        } while ($affected === 5000);
    } catch (Exception $e) {
        return $deleted;
    }

    return $deleted;
}
