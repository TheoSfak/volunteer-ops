<?php
/**
 * VolunteerOps - The floor a person is on, from the phone's barometer (v3.426.0).
 *
 * GPS cannot tell a second floor from the ground, and inside a building it
 * often cannot tell much at all. The Android app reads the phone's air
 * pressure and sends it with every fix, and on its own when no fix has come
 * for a while (recordVolunteerPressure() in functions-warroom.php). This file
 * turns those readings into "about floor +2, +6 m".
 *
 * Why it is built the way it is:
 *
 *  - Pressure is only ever compared with the SAME phone's own earlier reading.
 *    Two phones differ by up to a hectopascal for the same air, which is eight
 *    metres or two or three floors, so a raw pressure from one phone against a
 *    raw pressure from another means nothing.
 *  - So command sets a GROUND LEVEL for a person: that phone's own reading,
 *    taken while they stand at the entrance (a median of the last two
 *    minutes). The floor is the height difference between their pressure now
 *    and that reading.
 *  - The weather moves the pressure too, about a hectopascal an hour (eight
 *    metres). Command can name a REFERENCE phone that stays outside; its
 *    change since the baseline is taken off every other phone's reading, which
 *    cancels the weather. Without one, a baseline older than twenty minutes
 *    is flagged as drifting.
 *  - A floor is a guess (smoke, doors, ventilation and wind move the pressure
 *    by tenths of a hectopascal, a floor is about 0.35), so the metres are
 *    always shown beside it, and in a collapse the metres are the more honest
 *    figure.
 *
 * Relative height only, Android only (a browser has no barometer API), and a
 * phone without the sensor shows nothing: unknown is never zero.
 */

/** A ground-level reading is the median of the person's readings from this long before it was set. */
const BARO_BASELINE_SAMPLE_SECONDS = 120;
/** The reference phone's newest reading may be at most this old to cancel the weather. */
const BARO_REFERENCE_MAX_AGE_SECONDS = 600;
/** Without a reference, a ground level older than this is flagged as drifting. */
const BARO_DRIFT_AFTER_SECONDS = 1200;
/** Readings kept in view when a median is taken: well over two minutes at the fastest cadence. */
const BARO_MEDIAN_ROWS = 300;

/** The International Standard Atmosphere: height above the reference pressure, in metres. */
const BARO_ISA_SCALE_M = 44330.77;
const BARO_ISA_EXPONENT = 0.190263;

/** A stored floor height as a number of metres: anything outside what a building can be is 3. */
function baroFloorHeightFromSetting($stored): float {
    $metres = (float) str_replace(',', '.', (string) $stored);
    if ($metres < 2.0 || $metres > 6.0) {
        return 3.0;
    }
    return $metres;
}

/** One floor, in metres (Settings → Action Room). */
function baroFloorHeightM(): float {
    return baroFloorHeightFromSetting(getSetting('war_room_floor_height_m', '3'));
}

/** Height above the ground-level pressure, in metres (negative = below it). */
function baroHeightMetres(float $pressureHpa, float $groundHpa): float {
    if ($pressureHpa <= 0.0 || $groundHpa <= 0.0) {
        return 0.0;
    }
    return BARO_ISA_SCALE_M * (1.0 - pow($pressureHpa / $groundHpa, BARO_ISA_EXPONENT));
}

/** The floor a height falls on: 0 the ground, negative a basement. */
function baroFloorOfHeight(float $metres, float $floorHeightM): int {
    return (int) round($metres / max(0.5, $floorHeightM));
}

/**
 * The pressure the ground has NOW for one person, and how far to trust it.
 *
 * $baseline: ['hpa0', 'ref_user_id', 'ref_hpa0', 'age_s'] (the ground-level
 * reading and how long ago command set it).
 * $reference: the reference phone's newest reading, ['hpa', 'age_s'], or null.
 *
 * With a reference reading no older than BARO_REFERENCE_MAX_AGE_SECONDS the
 * ground pressure follows the weather: the baseline plus how much the
 * reference phone's pressure has changed since. Otherwise it is the baseline
 * as set, which is 'ok' while it is recent and 'drift' once it is not.
 *
 * Returns ['hpa' => float, 'conf' => 'ok'|'drift'].
 */
function baroGroundPressure(array $baseline, ?array $reference): array {
    $hpa0 = (float) $baseline['hpa0'];
    $refHpa0 = $baseline['ref_hpa0'] ?? null;
    if (($baseline['ref_user_id'] ?? null) !== null && $refHpa0 !== null && $reference !== null
        && (int) $reference['age_s'] <= BARO_REFERENCE_MAX_AGE_SECONDS) {
        return ['hpa' => $hpa0 + ((float) $reference['hpa'] - (float) $refHpa0), 'conf' => 'ok'];
    }
    return ['hpa' => $hpa0, 'conf' => (int) $baseline['age_s'] > BARO_DRIFT_AFTER_SECONDS ? 'drift' : 'ok'];
}

/**
 * Where one person stands, from their newest reading against their ground
 * level. $latest: ['hpa', 'age_s', 'at'] (at = the reading's own time, H:i).
 * Metres are rounded to a whole metre, which is also what keeps a person
 * standing still from changing the poll payload on every reading.
 *
 * Returns ['m' => int, 'floor' => int, 'conf' => 'ok'|'drift', 'at' => string,
 * 'stale' => bool] - 'stale' when the reading is older than $staleSeconds, so
 * the screen shows it as the last known floor rather than the current one.
 */
function baroFloorReading(array $latest, array $baseline, ?array $reference, float $floorHeightM, int $staleSeconds): array {
    $ground = baroGroundPressure($baseline, $reference);
    $metres = baroHeightMetres((float) $latest['hpa'], $ground['hpa']);
    return [
        'm'     => (int) round($metres),
        'floor' => baroFloorOfHeight($metres, $floorHeightM),
        'conf'  => $ground['conf'],
        'at'    => (string) $latest['at'],
        'stale' => (int) $latest['age_s'] > $staleSeconds,
    ];
}

/**
 * The newest reading of every person on a mission: [userId => ['hpa', 'age_s',
 * 'at']]. One indexed read (the loose scan of MAX(id) per user off
 * idx_pressure_mission_user, then one primary-key read per row) - this runs on
 * the five second poll. [] when the table is not there yet.
 */
function loadLatestPressureByUser(int $missionId): array {
    try {
        $rows = dbFetchAll(
            "SELECT p.user_id, p.hpa, p.created_at, TIMESTAMPDIFF(SECOND, p.created_at, NOW()) AS age_s
             FROM (SELECT user_id, MAX(id) AS max_id FROM volunteer_pressure WHERE mission_id = ? GROUP BY user_id) l
             JOIN volunteer_pressure p ON p.id = l.max_id",
            [$missionId]
        );
    } catch (Exception $e) {
        return [];
    }
    $out = [];
    foreach ($rows as $row) {
        $out[(int) $row['user_id']] = [
            'hpa'   => (float) $row['hpa'],
            'age_s' => max(0, (int) $row['age_s']),
            'at'    => formatDateTime($row['created_at'], 'H:i'),
        ];
    }
    return $out;
}

/**
 * The ground level command set for each person: the newest row per person,
 * [userId => ['hpa0', 'ref_user_id', 'ref_hpa0', 'age_s', 'set_at']].
 */
function loadFloorBaselinesByUser(int $missionId): array {
    try {
        $rows = dbFetchAll(
            "SELECT b.user_id, b.hpa0, b.ref_user_id, b.ref_hpa0, b.created_at,
                    TIMESTAMPDIFF(SECOND, b.created_at, NOW()) AS age_s
             FROM (SELECT user_id, MAX(id) AS max_id FROM volunteer_floor_baselines WHERE mission_id = ? GROUP BY user_id) l
             JOIN volunteer_floor_baselines b ON b.id = l.max_id",
            [$missionId]
        );
    } catch (Exception $e) {
        return [];
    }
    $out = [];
    foreach ($rows as $row) {
        $out[(int) $row['user_id']] = [
            'hpa0'        => (float) $row['hpa0'],
            'ref_user_id' => $row['ref_user_id'] !== null ? (int) $row['ref_user_id'] : null,
            'ref_hpa0'    => $row['ref_hpa0'] !== null ? (float) $row['ref_hpa0'] : null,
            'age_s'       => max(0, (int) $row['age_s']),
            'set_at'      => formatDateTime($row['created_at'], 'H:i'),
        ];
    }
    return $out;
}

/**
 * The floor of everyone on the mission who has both a ground level and a
 * reading: [userId => baroFloorReading()]. A mission that never set a ground
 * level costs this one small query and nothing else.
 */
function loadMissionFloors(int $missionId): array {
    $baselines = loadFloorBaselinesByUser($missionId);
    if (!$baselines) {
        return [];
    }
    $latest = loadLatestPressureByUser($missionId);
    $floorHeight = baroFloorHeightM();
    $staleSeconds = warRoomPingStaleThresholdSeconds();
    $out = [];
    foreach ($baselines as $userId => $baseline) {
        if (!isset($latest[$userId])) {
            continue;
        }
        $reference = $baseline['ref_user_id'] !== null ? ($latest[$baseline['ref_user_id']] ?? null) : null;
        $out[$userId] = baroFloorReading($latest[$userId], $baseline, $reference, $floorHeight, $staleSeconds);
    }
    return $out;
}

/**
 * A person's own pressure as it has been: the median of their readings from
 * the last $seconds, with how many there were and how old the newest is -
 * ['hpa', 'n', 'newest_age_s'] - or null when there is none that recent. The
 * median, not the mean: a door slamming moves the pressure for a second.
 */
function recentPressureMedian(int $missionId, int $userId, int $seconds = BARO_BASELINE_SAMPLE_SECONDS): ?array {
    try {
        $rows = dbFetchAll(
            "SELECT hpa, age_s FROM (
                 SELECT hpa, TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age_s FROM volunteer_pressure
                  WHERE mission_id = ? AND user_id = ? ORDER BY id DESC LIMIT " . BARO_MEDIAN_ROWS . "
             ) recent WHERE age_s <= ?",
            [$missionId, $userId, $seconds]
        );
    } catch (Exception $e) {
        return null;
    }
    if (!$rows) {
        return null;
    }
    $values = array_map(fn($r) => (float) $r['hpa'], $rows);
    sort($values);
    $n = count($values);
    $median = $n % 2 === 1 ? $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2.0;
    return [
        'hpa'          => round($median, 2),
        'n'            => $n,
        'newest_age_s' => (int) min(array_map(fn($r) => (int) $r['age_s'], $rows)),
    ];
}

/**
 * Set the ground level of $userIds from what each phone reads right now.
 * Optionally $refUserId names a phone that stays outside, whose reading at
 * this moment is kept so the weather can be taken off later.
 *
 * Nobody is half set: a person who is not in the Action Room, or has no
 * reading from the last two minutes, is skipped and named in 'skipped' with a
 * reason ('not_participant', 'no_reading'), and everybody else is set. A
 * reference that cannot be used (not a participant, no recent reading, or
 * itself in the list) stops the whole call before anything is written.
 *
 * Returns ['ok' => bool, 'set' => [userId...], 'skipped' => [userId => reason],
 * 'error' => 'ref_*' (only when ok is false)].
 */
function setFloorBaselines(int $missionId, array $userIds, ?int $refUserId, int $byUserId): array {
    $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), fn($id) => $id > 0)));
    $participants = actionRoomParticipantIds($missionId);

    $refMedian = null;
    if ($refUserId !== null && $refUserId > 0) {
        if (in_array($refUserId, $userIds, true)) {
            return ['ok' => false, 'set' => [], 'skipped' => [], 'error' => 'ref_in_list'];
        }
        if (!in_array($refUserId, $participants, true)) {
            return ['ok' => false, 'set' => [], 'skipped' => [], 'error' => 'ref_not_participant'];
        }
        $refMedian = recentPressureMedian($missionId, $refUserId);
        if ($refMedian === null) {
            return ['ok' => false, 'set' => [], 'skipped' => [], 'error' => 'ref_no_reading'];
        }
    } else {
        $refUserId = null;
    }

    $set = [];
    $skipped = [];
    foreach ($userIds as $userId) {
        if (!in_array($userId, $participants, true)) {
            $skipped[$userId] = 'not_participant';
            continue;
        }
        $median = recentPressureMedian($missionId, $userId);
        if ($median === null) {
            $skipped[$userId] = 'no_reading';
            continue;
        }
        dbInsert(
            "INSERT INTO volunteer_floor_baselines (mission_id, user_id, hpa0, ref_user_id, ref_hpa0, set_by)
             VALUES (?, ?, ?, ?, ?, ?)",
            [$missionId, $userId, $median['hpa'], $refUserId, $refMedian['hpa'] ?? null, $byUserId]
        );
        $set[] = $userId;
    }
    return ['ok' => true, 'set' => $set, 'skipped' => $skipped];
}

/** Take the ground level off $userIds (their floor is then no longer shown). Returns how many rows went. */
function clearFloorBaselines(int $missionId, array $userIds): int {
    $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), fn($id) => $id > 0)));
    if (!$userIds) {
        return 0;
    }
    $marks = implode(',', array_fill(0, count($userIds), '?'));
    return (int) dbExecute(
        "DELETE FROM volunteer_floor_baselines WHERE mission_id = ? AND user_id IN ({$marks})",
        array_merge([$missionId], $userIds)
    );
}

/**
 * Whether the phone each person last used has a barometer: [userId => 1|0],
 * people who never said are left out. From the most recently used live token.
 */
function loadHasBarometerByUser(array $userIds): array {
    $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), fn($id) => $id > 0)));
    if (!$userIds) {
        return [];
    }
    $marks = implode(',', array_fill(0, count($userIds), '?'));
    try {
        $rows = dbFetchAll(
            "SELECT user_id, has_barometer FROM mobile_api_tokens
             WHERE user_id IN ({$marks}) AND revoked_at IS NULL AND has_barometer IS NOT NULL
             ORDER BY last_used_at ASC, id ASC",
            $userIds
        );
    } catch (Exception $e) {
        return [];
    }
    $out = [];
    foreach ($rows as $row) {
        $out[(int) $row['user_id']] = (int) $row['has_barometer']; // the later (more recently used) row wins
    }
    return $out;
}

/**
 * Everyone command can set a ground level for, for the «Επίπεδο εδάφους» window:
 * the Action Room participants grouped by team (people on no team last), each
 * with whether the phone has sent a reading in the last two minutes
 * ('fresh'), whether it has a barometer at all ('has_baro': 1, 0 or null =
 * never said) and when their ground level was set ('baseline_at').
 */
function floorBaselineCandidates(int $missionId): array {
    $participants = actionRoomParticipantIds($missionId);
    if (!$participants) {
        return ['groups' => [], 'floor_height_m' => baroFloorHeightM()];
    }
    $marks = implode(',', array_fill(0, count($participants), '?'));
    $names = [];
    foreach (dbFetchAll("SELECT id, name FROM users WHERE id IN ({$marks})", $participants) as $row) {
        $names[(int) $row['id']] = $row['name'];
    }
    $latest = loadLatestPressureByUser($missionId);
    $baselines = loadFloorBaselinesByUser($missionId);
    $hasBaro = loadHasBarometerByUser($participants);

    $personOf = function (int $userId) use ($names, $latest, $baselines, $hasBaro): array {
        return [
            'user_id'     => $userId,
            'name'        => $names[$userId] ?? ('#' . $userId),
            'fresh'       => isset($latest[$userId]) && $latest[$userId]['age_s'] <= BARO_BASELINE_SAMPLE_SECONDS,
            'has_baro'    => $hasBaro[$userId] ?? null,
            'baseline_at' => $baselines[$userId]['set_at'] ?? null,
        ];
    };

    $groups = [];
    $placed = [];
    foreach (loadMissionTeamsForMission($missionId) as $team) {
        $members = [];
        foreach ($team['members'] as $member) {
            $uid = (int) $member['user_id'];
            if (in_array($uid, $participants, true) && !isset($placed[$uid])) {
                $members[] = $personOf($uid);
                $placed[$uid] = true;
            }
        }
        if ($members) {
            $groups[] = [
                'team_id' => (int) $team['id'],
                'label'   => teamLabel($team['codename'], $team['team_number']),
                'color'   => $team['color'],
                'members' => $members,
            ];
        }
    }
    $loose = [];
    foreach ($participants as $uid) {
        if (!isset($placed[$uid])) {
            $loose[] = $personOf((int) $uid);
        }
    }
    if ($loose) {
        usort($loose, fn($a, $b) => strcasecmp($a['name'], $b['name']));
        $groups[] = ['team_id' => 0, 'label' => null, 'color' => null, 'members' => $loose];
    }
    return ['groups' => $groups, 'floor_height_m' => baroFloorHeightM()];
}
