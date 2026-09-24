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
 * Per-participant GPS overview for the whole mission. Every Action Room
 * participant appears, including somebody who never produced a single fix —
 * that absence is itself the finding.
 */
function loadMissionGpsQuality(int $missionId): array {
    $people = [];
    foreach (dbFetchAll(
        "SELECT arp.user_id, u.name, arp.last_gps_error
           FROM mission_action_room_participants arp
           JOIN users u ON u.id = arp.user_id
          WHERE arp.mission_id = ?
          ORDER BY u.name",
        [$missionId]
    ) as $row) {
        $people[(int) $row['user_id']] = [
            'user_id' => (int) $row['user_id'], 'name' => $row['name'],
            'fixes' => 0, 'native' => 0, 'browser' => 0,
            'acc' => [], 'shift' => [], 'refusals' => [], 'refused_total' => 0,
            'device' => null, 'last_gps_error' => $row['last_gps_error'],
        ];
    }

    // One narrow row per fix: the claimed accuracy (the device's, not the
    // filter's), and how far the filter moved it. The distance is done in SQL
    // so a long mission's 100.000 fixes cost four numbers each, not a row.
    $rows = dbFetchAll(
        "SELECT vp.user_id, vp.via,
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
        if (!isset($people[$uid])) {
            continue; // unticked since; their fixes are not the Action Room's any more
        }
        $people[$uid]['fixes']++;
        if ($row['via'] === 'native') $people[$uid]['native']++;
        elseif ($row['via'] === 'browser') $people[$uid]['browser']++;
        if ($row['acc'] !== null) $people[$uid]['acc'][] = (float) $row['acc'];
        if ($row['shift_m'] !== null) $people[$uid]['shift'][] = (float) $row['shift_m'];
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
        unset($p['acc'], $p['shift']);
    }
    unset($p);
    return array_values($people);
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
