<?php
/**
 * VolunteerOps - GPS quality report (v3.321.0)
 *
 * The measuring half of the Action Room position work. Everything else in the
 * position pipeline decides what to believe; this answers how right it was.
 * Two questions:
 *
 *  1. Per phone, over the whole mission: how many fixes, from which client,
 *     how accurate did the phone CLAIM to be, how often did the server refuse
 *     it and why, and how far did the filter move its fixes.
 *  2. The drill: everybody stands at a known point for a few minutes. Against
 *     that ground truth, how far off was each phone really — typical and
 *     worst case — was its own "±N m" honest, and was it consistently off in
 *     one direction (a bias no averaging can remove). And did the filtered
 *     estimate land closer than the raw fix, which is the only evidence that
 *     the smoothing earns its place.
 *
 * Loaded by mission-gps-quality.php and its tests only; nothing on the live
 * poll path calls into this file.
 */

if (!defined('VOLUNTEEROPS')) {
    die('Direct access not permitted');
}

/** Nearest-rank percentile of an ascending-sorted list; null when empty. */
function gpsPercentile(array $sorted, float $p): ?float {
    $n = count($sorted);
    if ($n === 0) {
        return null;
    }
    $rank = (int) ceil($p / 100 * $n);
    return (float) $sorted[max(0, min($n - 1, $rank - 1))];
}

/**
 * Per-phone GPS overview for the whole mission. Every Action Room participant
 * appears, including somebody who never produced a single fix — that absence
 * is itself the finding.
 *
 * So does everybody who produced fixes and has had their GPS tick removed
 * since ('ticked' => false), v3.336.3. They used to vanish: the list was only
 * the people ticked NOW, so a phone handed over mid-mission lost its row, and
 * after the end-of-mission untick the whole report came back empty — which is
 * exactly when the reference-point drill below is meant to be read. Every
 * stored fix was taken while its owner was ticked (recordVolunteerPing()
 * refuses the rest at the door), so these are the Action Room's own records.
 */
function loadMissionGpsQuality(int $missionId): array {
    $people = [];
    $person = fn(int $uid, string $name, ?string $lastError, bool $ticked) => [
        'user_id' => $uid, 'name' => $name, 'ticked' => $ticked,
        'fixes' => 0, 'native' => 0, 'browser' => 0,
        'acc' => [], 'shift' => [], 'refusals' => [], 'refused_total' => 0,
        // v3.337.0, Android app only: satellites used, signal strength,
        // second frequency, and fixes with no satellite at all.
        'sats' => [], 'cn0' => [], 'dual_known' => 0, 'dual_yes' => 0, 'no_sat' => 0,
        'device' => null, 'last_gps_error' => $lastError,
    ];
    foreach (dbFetchAll(
        "SELECT arp.user_id, u.name, arp.last_gps_error
           FROM mission_action_room_participants arp
           JOIN users u ON u.id = arp.user_id
          WHERE arp.mission_id = ?
          ORDER BY u.name",
        [$missionId]
    ) as $row) {
        $people[(int) $row['user_id']] = $person((int) $row['user_id'], $row['name'], $row['last_gps_error'], true);
    }
    foreach (dbFetchAll(
        "SELECT DISTINCT u.id, u.name
           FROM volunteer_pings vp
           JOIN shifts s ON s.id = vp.shift_id
           JOIN users u ON u.id = vp.user_id
          WHERE s.mission_id = ?
          ORDER BY u.name",
        [$missionId]
    ) as $row) {
        if (!isset($people[(int) $row['id']])) {
            $people[(int) $row['id']] = $person((int) $row['id'], $row['name'], null, false);
        }
    }

    // One narrow row per fix: the claimed accuracy (the device's, not the
    // filter's), and how far the filter moved it. The distance is done in SQL
    // so a long mission's 100.000 fixes cost four numbers each, not a row.
    $rows = dbFetchAll(
        "SELECT vp.user_id, vp.via, vp.gnss_used, vp.gnss_cn0, vp.gnss_dual,
                COALESCE(vp.raw_accuracy_m, vp.accuracy_meters) AS acc,
                CASE WHEN vp.raw_lat IS NULL THEN NULL ELSE
                    SQRT(POW((vp.lat - vp.raw_lat) * 111320, 2)
                       + POW((vp.lng - vp.raw_lng) * 111320 * COS(RADIANS(vp.lat)), 2)) END AS shift_m
           FROM volunteer_pings vp
           JOIN shifts s ON s.id = vp.shift_id
          WHERE s.mission_id = ?",
        [$missionId]
    );
    foreach ($rows as $row) {
        $uid = (int) $row['user_id'];
        $people[$uid]['fixes']++;
        if ($row['via'] === 'native') $people[$uid]['native']++;
        elseif ($row['via'] === 'browser') $people[$uid]['browser']++;
        if ($row['acc'] !== null) $people[$uid]['acc'][] = (float) $row['acc'];
        if ($row['shift_m'] !== null) $people[$uid]['shift'][] = (float) $row['shift_m'];
        if ($row['gnss_used'] !== null) {
            $people[$uid]['sats'][] = (int) $row['gnss_used'];
            if ((int) $row['gnss_used'] === 0) $people[$uid]['no_sat']++;
        }
        if ($row['gnss_cn0'] !== null) $people[$uid]['cn0'][] = (float) $row['gnss_cn0'];
        if ($row['gnss_dual'] !== null) {
            $people[$uid]['dual_known']++;
            if ((int) $row['gnss_dual'] === 1) $people[$uid]['dual_yes']++;
        }
    }

    try {
        foreach (dbFetchAll(
            "SELECT user_id, reason, refused_count FROM volunteer_ping_refusals WHERE mission_id = ?",
            [$missionId]
        ) as $row) {
            $uid = (int) $row['user_id'];
            if (!isset($people[$uid])) continue;
            $people[$uid]['refusals'][$row['reason']] = (int) $row['refused_count'];
            $people[$uid]['refused_total'] += (int) $row['refused_count'];
        }
    } catch (Exception $e) {
        // Before migration 163 there is no refusal count to show.
    }

    // The phone model is known only for the Android app (its token carries
    // it since v3.320.0); a browser does not say which phone it runs on.
    $nativeIds = array_keys(array_filter($people, fn($p) => $p['native'] > 0));
    if ($nativeIds) {
        $ph = implode(',', array_fill(0, count($nativeIds), '?'));
        foreach (dbFetchAll(
            "SELECT user_id, device_label FROM mobile_api_tokens
              WHERE user_id IN ($ph) AND revoked_at IS NULL
              ORDER BY last_used_at IS NULL, last_used_at DESC",
            $nativeIds
        ) as $row) {
            $uid = (int) $row['user_id'];
            if ($people[$uid]['device'] === null) $people[$uid]['device'] = $row['device_label'];
        }
    }

    foreach ($people as &$p) {
        sort($p['acc']);
        sort($p['shift']);
        $p['acc_median'] = gpsPercentile($p['acc'], 50);
        $p['acc_p90'] = gpsPercentile($p['acc'], 90);
        $p['shift_median'] = gpsPercentile($p['shift'], 50);
        sort($p['sats']);
        sort($p['cn0']);
        $p['sats_median'] = gpsPercentile($p['sats'], 50);
        $p['cn0_median'] = gpsPercentile($p['cn0'], 50);
        $p['sats_reported'] = count($p['sats']);
        $p['dual_pct'] = $p['dual_known'] > 0 ? (int) round(100 * $p['dual_yes'] / $p['dual_known']) : null;
        unset($p['acc'], $p['shift'], $p['sats'], $p['cn0']);
    }
    unset($p);
    return array_values($people);
}

/**
 * Every stretch where somebody's position went stale — no fix stored for
 * $minGapSeconds or longer — and what the server turned away inside it
 * (v3.338.0). The one question a trail with a hole in it raises: did the
 * phone send nothing, or did it send fixes that a gate refused? The two need
 * opposite fixes (where the phone is carried, or the app, versus a limit in
 * Settings), and before volunteer_ping_refusal_log they looked identical.
 *
 * Newest first, at most $limit. Each gap: user_id, name, from, to (unix
 * seconds of the fixes either side), seconds, line_m (the straight line the
 * trail draws across it), refused (reason => count, oldest reason first),
 * acc_min/acc_max (the phone's own ± over the fixes refused as imprecise),
 * kmh_max (the fastest impossible jump), and logged — true only when the
 * refusal log was already running when the gap began, so that an empty
 * 'refused' really does mean nothing arrived.
 */
function loadMissionGpsGaps(int $missionId, int $minGapSeconds, int $limit = 100): array {
    $byUser = [];
    foreach (dbFetchAll(
        "SELECT vp.user_id, u.name, UNIX_TIMESTAMP(vp.created_at) AS ts, vp.lat, vp.lng
           FROM volunteer_pings vp
           JOIN shifts s ON s.id = vp.shift_id
           JOIN users u ON u.id = vp.user_id
          WHERE s.mission_id = ?
          ORDER BY vp.user_id, vp.created_at, vp.id",
        [$missionId]
    ) as $row) {
        $byUser[(int) $row['user_id']][] = $row;
    }

    $refused = [];
    $logSince = null; // null = no log at all: no gap can be judged
    try {
        foreach (dbFetchAll(
            "SELECT user_id, reason, UNIX_TIMESTAMP(fix_at) AS ts, accuracy_m, implied_kmh
               FROM volunteer_ping_refusal_log
              WHERE mission_id = ?
              ORDER BY user_id, fix_at, id",
            [$missionId]
        ) as $row) {
            $refused[(int) $row['user_id']][] = $row;
        }
        // Read directly, not through getSetting()'s per-request cache. No
        // row means an install created from schema.sql with the table
        // already in it: the log has been running from the start.
        $since = dbFetchValue("SELECT setting_value FROM settings WHERE setting_key = 'gps_refusal_log_since'");
        $logSince = $since ? (int) strtotime((string) $since) : 0;
    } catch (Exception $e) {
        // Before migration 171.
    }

    $gaps = [];
    foreach ($byUser as $uid => $fixes) {
        $log = $refused[$uid] ?? [];
        $next = 0;
        for ($i = 1, $n = count($fixes); $i < $n; $i++) {
            $from = (int) $fixes[$i - 1]['ts'];
            $to   = (int) $fixes[$i]['ts'];
            if ($to - $from < $minGapSeconds) {
                continue;
            }
            // Both lists run oldest first, so one pointer serves every gap.
            while ($next < count($log) && (int) $log[$next]['ts'] <= $from) {
                $next++;
            }
            $counts = [];
            $accMin = $accMax = $kmhMax = null;
            for ($j = $next; $j < count($log) && (int) $log[$j]['ts'] < $to; $j++) {
                $r = $log[$j];
                $counts[$r['reason']] = ($counts[$r['reason']] ?? 0) + 1;
                if ($r['reason'] === 'imprecise' && $r['accuracy_m'] !== null) {
                    $a = (float) $r['accuracy_m'];
                    $accMin = $accMin === null ? $a : min($accMin, $a);
                    $accMax = $accMax === null ? $a : max($accMax, $a);
                }
                if ($r['implied_kmh'] !== null) {
                    $kmhMax = max($kmhMax ?? 0.0, (float) $r['implied_kmh']);
                }
            }
            $gaps[] = [
                'user_id' => $uid,
                'name'    => $fixes[$i]['name'],
                'from'    => $from,
                'to'      => $to,
                'seconds' => $to - $from,
                'line_m'  => gpsDistanceMeters(
                    (float) $fixes[$i - 1]['lat'], (float) $fixes[$i - 1]['lng'],
                    (float) $fixes[$i]['lat'], (float) $fixes[$i]['lng']
                ),
                'refused' => $counts,
                'acc_min' => $accMin,
                'acc_max' => $accMax,
                'kmh_max' => $kmhMax,
                'logged'  => $logSince !== null && $from >= $logSince,
            ];
        }
    }

    usort($gaps, fn($a, $b) => $b['to'] <=> $a['to']);
    return ['gaps' => array_slice($gaps, 0, $limit), 'total' => count($gaps)];
}

/**
 * The drill: every fix taken inside [$from, $to] measured against a known
 * reference point. Returns one row per phone plus an 'all' summary, each with
 * the raw fix's error (median, p95), the filtered estimate's error (median,
 * p95), how often the device's own ±N m actually contained the truth, and the
 * mean offset — a bias — as metres and bearing.
 *
 * "Honest" is judged against 68%: a phone's reported accuracy is a one-sigma
 * radius, so about two fixes in three should fall within it. Far below that
 * and the phone is overconfident — the most dangerous kind, because every
 * gate here trusts that number.
 */
function computeGpsCalibration(int $missionId, float $refLat, float $refLng, string $from, string $to): array {
    $rows = dbFetchAll(
        "SELECT vp.user_id, u.name,
                COALESCE(vp.raw_lat, vp.lat) AS rlat, COALESCE(vp.raw_lng, vp.lng) AS rlng,
                COALESCE(vp.raw_accuracy_m, vp.accuracy_meters) AS racc,
                vp.lat AS elat, vp.lng AS elng
           FROM volunteer_pings vp
           JOIN shifts s ON s.id = vp.shift_id
           JOIN users u ON u.id = vp.user_id
          WHERE s.mission_id = ? AND vp.created_at BETWEEN ? AND ?
          ORDER BY u.name, vp.created_at",
        [$missionId, $from, $to]
    );

    $mPerDegLat = 111320.0;
    $mPerDegLng = 111320.0 * cos(deg2rad($refLat));
    $acc = [];
    $add = function (string $key, string $name, array $row) use (&$acc, $refLat, $refLng, $mPerDegLat, $mPerDegLng) {
        if (!isset($acc[$key])) {
            $acc[$key] = ['name' => $name, 'raw' => [], 'est' => [], 'honest' => 0, 'with_acc' => 0, 'dn' => 0.0, 'de' => 0.0];
        }
        $rawErr = gpsDistanceMeters($refLat, $refLng, (float) $row['rlat'], (float) $row['rlng']);
        $acc[$key]['raw'][] = $rawErr;
        $acc[$key]['est'][] = gpsDistanceMeters($refLat, $refLng, (float) $row['elat'], (float) $row['elng']);
        if ($row['racc'] !== null) {
            $acc[$key]['with_acc']++;
            if ($rawErr <= (float) $row['racc']) $acc[$key]['honest']++;
        }
        $acc[$key]['dn'] += ((float) $row['rlat'] - $refLat) * $mPerDegLat;
        $acc[$key]['de'] += ((float) $row['rlng'] - $refLng) * $mPerDegLng;
    };
    foreach ($rows as $row) {
        $add('u' . $row['user_id'], $row['name'], $row);
        $add('all', '', $row);
    }

    $out = ['people' => [], 'all' => null];
    foreach ($acc as $key => $a) {
        $n = count($a['raw']);
        sort($a['raw']);
        sort($a['est']);
        $dn = $a['dn'] / $n;
        $de = $a['de'] / $n;
        $summary = [
            'name'       => $a['name'],
            'fixes'      => $n,
            'raw_median' => gpsPercentile($a['raw'], 50),
            'raw_p95'    => gpsPercentile($a['raw'], 95),
            'est_median' => gpsPercentile($a['est'], 50),
            'est_p95'    => gpsPercentile($a['est'], 95),
            'honest_pct' => $a['with_acc'] > 0 ? (int) round(100 * $a['honest'] / $a['with_acc']) : null,
            'bias_m'     => sqrt($dn * $dn + $de * $de),
            'bias_deg'   => fmod(rad2deg(atan2($de, $dn)) + 360.0, 360.0),
        ];
        if ($key === 'all') $out['all'] = $summary;
        else $out['people'][] = $summary;
    }
    return $out;
}

/**
 * Delete refused-fix log rows older than $days days (the admin's manual
 * "Καθαρισμός" button on Ρυθμίσεις -> Υγεία Εφαρμογής).
 *
 * loadMissionGpsGaps() treats a gap as "judged" when the log was already
 * running when it began (setting gps_refusal_log_since), and then reads an
 * empty refusal list as "nothing arrived - the phone sent nothing". After a
 * purge that is no longer true for a gap older than the cutoff: its refusals
 * were deleted, not absent. So whenever rows are deleted the setting is raised
 * to the cutoff (never lowered), and gaps from before it come back as not
 * judged instead of wrongly blaming the phone.
 *
 * Deleted in chunks, oldest id first: the table has no index on fix_at, and one
 * big DELETE would scan it while holding row locks that a refusal being logged
 * by a ping request has to wait for.
 *
 * @return array{deleted:int,complete:bool,cutoff:?string}
 */
function purgeGpsRefusalLog(int $days, int $timeBudgetSeconds = 20, int $chunk = 5000): array
{
    $days = max(1, $days);
    $started = microtime(true);
    $deleted = 0;
    $complete = true;
    $cutoff = (string) dbFetchValue("SELECT DATE_SUB(NOW(), INTERVAL ? DAY)", [$days]);

    try {
        do {
            $n = (int) dbExecute(
                "DELETE FROM volunteer_ping_refusal_log WHERE fix_at < ? ORDER BY id LIMIT " . (int) $chunk,
                [$cutoff]
            );
            $deleted += $n;
            if ($n >= $chunk && (microtime(true) - $started) > $timeBudgetSeconds) {
                $complete = false;
                break;
            }
        } while ($n >= $chunk);
    } catch (Exception $e) {
        // Before migration 171 there is no table, so nothing to purge.
        return ['deleted' => 0, 'complete' => true, 'cutoff' => null];
    }

    if ($deleted > 0) {
        dbExecute(
            "INSERT INTO settings (setting_key, setting_value) VALUES ('gps_refusal_log_since', ?)
             ON DUPLICATE KEY UPDATE setting_value = IF(setting_value < VALUES(setting_value), VALUES(setting_value), setting_value)",
            [$cutoff]
        );
    }
    return ['deleted' => $deleted, 'complete' => $complete, 'cutoff' => $cutoff];
}
