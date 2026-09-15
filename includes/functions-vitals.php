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
        // the same number whatever your age or fitness. 45 rather than the
        // textbook 40 because of who this is for — 42 bpm in a resting, fit
        // adult is unremarkable, but in someone carrying a stretcher up a
        // gorge it is worth a look, and this app only ever measures the
        // second kind of person.
        'low_bpm'         => max(VITALS_MIN_BPM, min(60, (int) getSetting('vitals_low_bpm', '45'))),
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
        'tachy_minutes'   => max(1, min(120, (int) getSetting('vitals_episode_tachy_minutes', '10'))),
        'brady_minutes'   => max(1, min(120, (int) getSetting('vitals_episode_brady_minutes', '5'))),
        'strain_minutes'  => max(5, min(240, (int) getSetting('vitals_episode_strain_minutes', '20'))),
    ];

    // A critical threshold at or below the elevated one would make "elevated"
    // unreachable and paint everything red. Clamp rather than reject: these
    // are two independent number inputs on an admin form, and a momentarily
    // inconsistent pair must not break the live view for everyone.
    if ($config['critical_pct'] <= $config['elevated_pct']) {
        $config['critical_pct'] = min(100, $config['elevated_pct'] + 5);
    }

    // Episode thresholds are the ZONE thresholds. Only the duration is an
    // episode's own idea.
    //
    // This started out as two more settings — "tachycardia = 150 bpm", set
    // independently of the zone — and the first test showed why that is a
    // trap: a volunteer at 41 bpm sat in a table headed «Φυσιολογικοί»
    // directly above an active episode headed «Βραδυκαρδία», because 41 was
    // above the zone's floor of 40 and below the episode's of 45. Two numbers
    // for one word, disagreeing on screen, in a report someone reads to decide
    // whether to pull a person out of a gorge.
    //
    // So there is one line per band, and everything uses it: the badge on the
    // roster, the colour of the map pin, the ring on the trail, the report's
    // curve and the episodes below it. An org that wants tachycardia to mean
    // 150 moves the critical threshold and the whole app moves with it.
    // Inlined rather than vitalsMaxHeartRate(), which calls this function —
    // same formula, no recursion.
    $maxHeartRate = 220 - $config['reference_age'];
    $config['tachy_bpm'] = (int) ceil($maxHeartRate * $config['critical_pct'] / 100);
    $config['brady_bpm'] = $config['low_bpm'];

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
/**
 * The lowest whole heart rate that reaches a given percentage of the maximum —
 * the number to PRINT when explaining a zone.
 *
 * ceil, not round, and that is not pedantry. vitalsZone() tests
 * bpm / maxHeartRate * 100 >= pct, so at a maximum of 180 and a critical
 * threshold of 88% the first qualifying reading is 159, while round() prints
 * 158. A report whose caption says the red line is at 158 while its episode
 * list says 159 teaches the reader that the numbers are approximate, and the
 * whole value of this page is that they are not.
 */
function vitalsZoneBpm(int $percent, ?int $maxHeartRate = null): int {
    $maxHeartRate = $maxHeartRate ?? vitalsMaxHeartRate();
    return (int) ceil($maxHeartRate * $percent / 100);
}

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
 * Heart rate collapsed to one value per user per time bucket:
 * [userId][bucketIndex] => [bpm, zone], where bucketIndex is
 * floor(unixTime / (bucketMinutes * 60)).
 *
 * The GPS trail ("Πορεία Ομάδων") uses the default one-minute bucket; the
 * mission report widens it so a long deployment still fits a readable number
 * of points on one axis.
 *
 * Bucketed rather than per sample because of what it is joined against. GPS
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
 * visible, is loadVitalsReportForMission()'s job.
 *
 * No per-viewer gate here, unlike the live map: every caller of this is behind
 * mission-track.php's hard canManageActionRoom() check, so there is no
 * volunteer-facing path to leak through. That is a property of the caller, so
 * anything new that calls this must re-check it.
 */
function loadVitalsBucketedForMission(int $missionId, int $bucketMinutes = 1): array {
    if (!vitalsEnabled()) {
        return [];
    }

    $bucketMinutes = vitalsNormalizeBucketMinutes($bucketMinutes);

    $shiftIds = array_column(dbFetchAll("SELECT id FROM shifts WHERE mission_id = ?", [$missionId]), 'id');
    if (!$shiftIds) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($shiftIds), '?'));

    try {
        $rows = dbFetchAll(
            "SELECT user_id,
                    DATE_FORMAT(recorded_at - INTERVAL (MINUTE(recorded_at) % ?) MINUTE, '%Y-%m-%d %H:%i') AS bucket_key,
                    ROUND(AVG(bpm)) AS bpm
             FROM volunteer_vitals
             WHERE shift_id IN ({$placeholders})
             GROUP BY user_id, bucket_key",
            array_merge([$bucketMinutes], $shiftIds)
        );
    } catch (Exception $e) {
        return [];
    }

    $maxHr  = vitalsMaxHeartRate();
    $config = vitalsConfig();
    $out    = [];

    foreach ($rows as $row) {
        $bpm = (int) $row['bpm'];
        $out[(int) $row['user_id']][$row['bucket_key']] = [
            'bpm'  => $bpm,
            'zone' => vitalsZone($bpm, $maxHr, $config),
        ];
    }

    return $out;
}

/**
 * Bucket widths are restricted to divisors of 60 because the SQL above floors
 * within the hour (MINUTE(x) % n). 7-minute buckets would restart at the top
 * of every hour and produce a short, misaligned bucket there.
 */
function vitalsNormalizeBucketMinutes(int $bucketMinutes): int {
    $allowed = [1, 2, 5, 10, 15, 30, 60];
    return in_array($bucketMinutes, $allowed, true) ? $bucketMinutes : 1;
}

/**
 * The bucket key a given instant falls in — the PHP twin of the DATE_FORMAT
 * expression in loadVitalsBucketedForMission(), and the reason both sides
 * agree.
 *
 * Keys are local-time strings ('2026-02-15 11:07') rather than epoch-derived
 * integers, and that is a bug fix, not a style choice. The first version of
 * this bucketed with FLOOR(UNIX_TIMESTAMP(recorded_at) / n) in SQL while PHP
 * computed the matching index with floor(strtotime(...) / n). MySQL's
 * UNIX_TIMESTAMP() converts a DATETIME using the MySQL session's time zone and
 * PHP's strtotime() uses PHP's own — and on this stack those disagree for a
 * date recorded under a different DST offset than the one in force when the
 * report is opened. A February mission read back in September came out exactly
 * 60 buckets adrift: every heart rate silently vanished from the second half
 * of the chart, and the GPS trail overlay would have dropped every point of
 * any winter mission reviewed in summer. Formatting on one side and formatting
 * on the other removes the conversion entirely — neither side ever computes an
 * epoch, so neither side can disagree about which epoch it is.
 *
 * (An operation running across the instant the clocks change still has one
 * ambiguous hour, where two different instants format to the same local key
 * and average together. That is one hour, twice a year, and it degrades to a
 * slightly smoothed line rather than to missing data.)
 */
function vitalsBucketKey(int $unixTs, int $bucketMinutes = 1): string {
    $bucketMinutes = vitalsNormalizeBucketMinutes($bucketMinutes);
    $minute = (int) date('i', $unixTs);
    // Seconds are dropped by the format itself, so only the minute needs
    // flooring; subtracting them as well would be a no-op that reads as if it
    // mattered.
    return date('Y-m-d H:i', $unixTs - (($minute % $bucketMinutes) * 60));
}

/**
 * Everything the post-mission report needs about heart rate, in one call:
 * a shared time axis, one aligned series per volunteer, and per-volunteer
 * totals. Returns [] when the feature is off or the mission recorded nothing.
 *
 * Two different resolutions on purpose, because the chart and the table are
 * answering different questions:
 *
 *   - The CHART reads bucketed averages. A six-hour deployment of twelve
 *     people is ~50.000 samples; drawing every one of them would ship a
 *     megabyte of JSON into the page to paint lines a few hundred pixels wide,
 *     where dozens of samples land on the same pixel column anyway.
 *   - The TOTALS (average, lowest, highest, time in each zone) are computed by
 *     the database over every individual sample. Deriving them from the same
 *     bucketed averages would quietly erase exactly what a reader opens this
 *     section to find: the peak. A minute averaging 140 can contain a 30-second
 *     burst at 170, and that burst is the whole story.
 *
 * The bucket width adapts to the mission's length so the axis stays readable —
 * one minute for a short callout, up to an hour for a multi-day operation —
 * rather than being a fixed value that is too coarse for one and unusable for
 * the other.
 *
 * The zone CASE expressions below mirror vitalsZone() deliberately, including
 * its order: low is tested first (a dangerous bradycardia must not be reported
 * as a comfortable "ok" merely because it is under every percentage cut-off),
 * then critical, then elevated, then ok. They compare the same
 * bpm / maxHeartRate * 100 percentage rather than a pre-multiplied bpm
 * threshold, so no rounding can put a sample in a different zone here than the
 * live badge put it in. If the ladder in vitalsZone() ever changes, this
 * changes with it.
 */
function loadVitalsReportForMission(int $missionId, ?int $sinceTs = null): array {
    if (!vitalsEnabled()) {
        return [];
    }

    $shiftIds = array_column(dbFetchAll("SELECT id FROM shifts WHERE mission_id = ?", [$missionId]), 'id');
    if (!$shiftIds) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($shiftIds), '?'));

    $config = vitalsConfig();
    $maxHr  = vitalsMaxHeartRate();

    try {
        $span = dbFetchOne(
            "SELECT MIN(recorded_at) AS first_at, MAX(recorded_at) AS last_at
             FROM volunteer_vitals WHERE shift_id IN ({$placeholders})",
            $shiftIds
        );
    } catch (Exception $e) {
        return [];
    }
    if (!$span || empty($span['first_at'])) {
        return [];
    }

    $firstTs = strtotime($span['first_at']);
    $lastTs  = strtotime($span['last_at']);

    // An optional window, applied to the AXIS only — the per-volunteer totals
    // below still come from every sample the mission ever recorded, because
    // "his highest all day was 171" does not stop being true because you are
    // currently looking at the last two hours.
    //
    // It exists for the live case. A mission that was reopened weeks later, or
    // simply ran across three days, otherwise squeezes the hours anyone
    // actually cares about into a few pixels at the right-hand edge while a
    // fortnight of nothing occupies the rest of the chart.
    if ($sinceTs !== null && $sinceTs > $firstTs && $sinceTs < $lastTs) {
        $firstTs = $sinceTs;
    }

    $spanMinutes = max(1, (int) ceil(($lastTs - $firstTs) / 60));

    // Widen the bucket until the axis holds at most ~480 points — about one
    // per pixel-and-a-half on a full-width chart, past which more points only
    // cost payload. Candidates are divisors of 60, see
    // vitalsNormalizeBucketMinutes().
    $bucketMinutes = 60;
    foreach ([1, 2, 5, 10, 15, 30, 60] as $candidate) {
        $bucketMinutes = $candidate;
        if ($spanMinutes / $candidate <= 480) {
            break;
        }
    }
    $bucketSeconds = $bucketMinutes * 60;

    // A mission crossing midnight (or a multi-day search) needs the date on
    // the axis, or 02:00 on day two is indistinguishable from 02:00 on day one.
    $labelFormat = ($lastTs - $firstTs) > 86400 ? 'd/m H:i' : 'H:i';

    // The axis is built by walking bucket starts and formatting each one the
    // same way the database did, so a label and its data share a key by
    // construction rather than by both happening to agree about epochs.
    $bucketKeys = [];
    $labels     = [];
    $cursor     = strtotime(vitalsBucketKey($firstTs, $bucketMinutes));
    $lastKey    = vitalsBucketKey($lastTs, $bucketMinutes);
    while (true) {
        $key          = vitalsBucketKey($cursor, $bucketMinutes);
        $bucketKeys[] = $key;
        $labels[]     = date($labelFormat, $cursor);
        if ($key === $lastKey || count($bucketKeys) > 5000) {
            break; // the count guard is a runaway stop, not an expected exit
        }
        $cursor += $bucketSeconds;
    }

    try {
        $totals = dbFetchAll(
            "SELECT v.user_id, u.name,
                    COUNT(*) AS samples,
                    ROUND(AVG(v.bpm)) AS bpm_avg,
                    MIN(v.bpm) AS bpm_min,
                    MAX(v.bpm) AS bpm_max,
                    SUM(CASE WHEN v.bpm <= ? THEN 1 ELSE 0 END) AS n_low,
                    SUM(CASE WHEN v.bpm > ? AND (v.bpm * 100.0 / ?) >= ? THEN 1 ELSE 0 END) AS n_critical,
                    SUM(CASE WHEN v.bpm > ? AND (v.bpm * 100.0 / ?) <  ? AND (v.bpm * 100.0 / ?) >= ? THEN 1 ELSE 0 END) AS n_elevated,
                    SUM(CASE WHEN v.bpm > ? AND (v.bpm * 100.0 / ?) <  ? THEN 1 ELSE 0 END) AS n_ok
             FROM volunteer_vitals v
             JOIN users u ON u.id = v.user_id
             WHERE v.shift_id IN ({$placeholders})
             GROUP BY v.user_id, u.name
             ORDER BY u.name",
            array_merge(
                [
                    $config['low_bpm'],
                    $config['low_bpm'], $maxHr, $config['critical_pct'],
                    $config['low_bpm'], $maxHr, $config['critical_pct'], $maxHr, $config['elevated_pct'],
                    $config['low_bpm'], $maxHr, $config['elevated_pct'],
                ],
                $shiftIds
            )
        );
    } catch (Exception $e) {
        return [];
    }

    $buckets = loadVitalsBucketedForMission($missionId, $bucketMinutes);

    $volunteers = [];
    foreach ($totals as $row) {
        $userId = (int) $row['user_id'];

        // null, not zero, for a bucket with no sample: the volunteer was not
        // wearing a sensor then (or was out of Bluetooth range), and a zero
        // would draw their line diving to the floor and back as if their heart
        // had stopped.
        $series = [];
        foreach ($bucketKeys as $key) {
            $series[] = isset($buckets[$userId][$key]) ? (int) $buckets[$userId][$key]['bpm'] : null;
        }

        $volunteers[] = [
            'user_id'   => $userId,
            'name'      => $row['name'],
            'samples'   => (int) $row['samples'],
            'bpm_avg'   => (int) $row['bpm_avg'],
            'bpm_min'   => (int) $row['bpm_min'],
            'bpm_max'   => (int) $row['bpm_max'],
            'zone_secs' => [
                'low'      => (int) $row['n_low']      * $config['sample_seconds'],
                'ok'       => (int) $row['n_ok']       * $config['sample_seconds'],
                'elevated' => (int) $row['n_elevated'] * $config['sample_seconds'],
                'critical' => (int) $row['n_critical'] * $config['sample_seconds'],
            ],
            'series'    => $series,
        ];
    }

    return [
        'labels'         => $labels,
        'bucket_minutes' => $bucketMinutes,
        'max_hr'         => $maxHr,
        'sample_seconds' => $config['sample_seconds'],
        'first_at'       => $span['first_at'],
        'last_at'        => $span['last_at'],
        // Drawn as horizontal guide lines on the chart. Rounded only here, for
        // display — every actual classification above compares percentages.
        'thresholds'     => [
            'low'      => $config['low_bpm'],
            'elevated' => vitalsZoneBpm($config['elevated_pct'], $maxHr),
            'critical' => vitalsZoneBpm($config['critical_pct'], $maxHr),
        ],
        'volunteers'     => $volunteers,
    ];
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
