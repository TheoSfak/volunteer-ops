<?php
/**
 * VolunteerOps - The mission's command post («Συντονιστικό») on the live map.
 *
 * One point per mission. Command places it at the start of an operation and
 * moves it whenever the command post itself moves, which is often: it is
 * frequently a vehicle. Every participant sees it on the map, with directions
 * to it, and is told when it is set up and when it moves somewhere else.
 * Since v3.347.0 it can instead follow the GPS of a participant's device in
 * that vehicle (startCommandPostFollow(), commandPostFollowFix()).
 *
 * mission_command_posts holds where it is now (one row per mission, deleted
 * when command takes it off the map). mission_command_post_log keeps every
 * placement, move, note change and removal for the two activity timelines —
 * the live «Δραστηριότητα» tab (mission-history.php) and the mission report
 * (loadMissionActivityEventsForReport()) — which both read it through
 * loadCommandPostActivityEvents() and word it through
 * commandPostActivityText(), so they cannot tell it differently.
 */

if (!defined('VOLUNTEEROPS')) {
    die('Direct access not permitted');
}

/**
 * A move shorter than this is command nudging the pin into place, not the
 * command post going somewhere: it is logged, but nobody is interrupted for
 * it. Fifty metres is about where a person walking to the old spot would no
 * longer find it by looking around.
 */
const COMMAND_POST_NOTIFY_MIN_M = 50;

/** Same limit as the column. */
const COMMAND_POST_NOTE_MAX = 255;

/**
 * Following a device (v3.347.0). The pin follows every fix, live, but
 * nobody is told while the vehicle is on the road: only once it has stood
 * within COMMAND_POST_FOLLOW_STILL_M of one spot for COMMAND_POST_FOLLOW_SETTLE_S,
 * at least COMMAND_POST_NOTIFY_MIN_M from where everybody was last told it is.
 * Three minutes: longer than a red light or a junction, short enough that the
 * teams hear of the new spot before anyone sets off for the old one. Forty
 * metres: a parked phone's fixes wander by less than that, a vehicle creeping
 * through a village does not.
 */
const COMMAND_POST_FOLLOW_STILL_M = 40;
const COMMAND_POST_FOLLOW_SETTLE_S = 180;

/** A point command can mean: on the globe, and not the 0,0 an empty form sends. */
function commandPostValidLatLng($lat, $lng): bool {
    return is_numeric($lat) && is_numeric($lng)
        && (float) $lat >= -90 && (float) $lat <= 90
        && (float) $lng >= -180 && (float) $lng <= 180
        && !((float) $lat === 0.0 && (float) $lng === 0.0);
}

/**
 * Where the command post is now, as the map shows it, or null when it has not
 * been placed. `action` says how it got there ('set' the first time, 'moved'
 * after), `at` when and `by` who, so the popup can say «Μετακινήθηκε 10:40 ·
 * Γιάννης». No raw timestamp: this rides every poll, and the page compares
 * polls by their JSON.
 */
function loadMissionCommandPost(int $missionId): ?array {
    $row = dbFetchOne(
        "SELECT cp.lat, cp.lng, cp.note, cp.last_action, cp.placed_at, u.name AS placed_by_name,
                cp.follow_user_id, f.name AS follow_name, cp.fix_at, UNIX_TIMESTAMP(cp.fix_at) AS fix_ts
         FROM mission_command_posts cp
         LEFT JOIN users u ON u.id = cp.placed_by
         LEFT JOIN users f ON f.id = cp.follow_user_id
         WHERE cp.mission_id = ?",
        [$missionId]
    );
    if (!$row) {
        return null;
    }
    // A mission can run past midnight; an hour alone would then be read as
    // today's.
    $clock = fn(int $ts) => date(date('Y-m-d', $ts) === date('Y-m-d') ? 'H:i' : 'd/m H:i', $ts);
    return [
        'lat' => (float) $row['lat'],
        'lng' => (float) $row['lng'],
        'note' => $row['note'] !== null && $row['note'] !== '' ? (string) $row['note'] : null,
        'action' => $row['last_action'] === 'moved' ? 'moved' : 'set',
        'at' => $clock(strtotime((string) $row['placed_at'])),
        'by' => $row['placed_by_name'] !== null ? (string) $row['placed_by_name'] : null,
        // Whose device it follows, and when that device last gave a fix: the
        // page dims the pin and says how old it is once the fix is stale,
        // rather than show a place it may long have left as current. ts is
        // the database's clock; the page corrects for its own.
        'follow' => $row['follow_user_id'] !== null ? [
            'user_id' => (int) $row['follow_user_id'],
            'name' => (string) ($row['follow_name'] ?? '—'),
            'at' => $row['fix_at'] !== null ? $clock(strtotime((string) $row['fix_at'])) : null,
            'ts' => $row['fix_ts'] !== null ? (int) $row['fix_ts'] : null,
        ] : null,
    ];
}

/**
 * Put the command post at a point: the first placement, or a move. Returns
 * ['changed' => bool, 'action' => 'set'|'moved', 'moved_m' => int|null].
 * Placing it where it already is (a double tap, a drag that ended where it
 * began) changes nothing and logs nothing.
 */
function setMissionCommandPost(int $missionId, float $lat, float $lng, int $userId): array {
    $current = dbFetchOne("SELECT lat, lng, follow_user_id FROM mission_command_posts WHERE mission_id = ?", [$missionId]);
    $movedM = $current ? gpsDistanceMeters((float) $current['lat'], (float) $current['lng'], $lat, $lng) : null;
    if ($movedM !== null && $movedM < 1) {
        return ['changed' => false, 'action' => 'moved', 'moved_m' => 0];
    }
    // Put somewhere by hand, it no longer goes where the device goes — or the
    // next fix would carry it straight back. Said in the record, too.
    if ($current && $current['follow_user_id'] !== null) {
        stopCommandPostFollow($missionId, $userId);
    }
    $action = $current ? 'moved' : 'set';
    // Upsert rather than INSERT-or-UPDATE on what was read above: two
    // coordinators placing it in the same second must not collide on the key.
    dbExecute(
        "INSERT INTO mission_command_posts (mission_id, lat, lng, last_action, placed_at, placed_by)
         VALUES (?, ?, ?, ?, NOW(), ?)
         ON DUPLICATE KEY UPDATE lat = VALUES(lat), lng = VALUES(lng), last_action = VALUES(last_action),
                                 placed_at = VALUES(placed_at), placed_by = VALUES(placed_by)",
        [$missionId, $lat, $lng, $action, $userId]
    );
    $movedRounded = $movedM !== null ? (int) round($movedM) : null;
    dbInsert(
        "INSERT INTO mission_command_post_log (mission_id, action, lat, lng, moved_m, user_id, via, created_at)
         VALUES (?, ?, ?, ?, ?, ?, 'hand', NOW())",
        [$missionId, $action, $lat, $lng, $movedRounded, $userId]
    );
    return ['changed' => true, 'action' => $action, 'moved_m' => $movedRounded];
}

/**
 * The newest fix stored for this person on this mission, or null:
 * ['lat', 'lng', 'at' (DATETIME), 'age_s' (by the database's clock)].
 * lat/lng are the filtered estimate, the position every other part of the
 * map shows for them.
 */
function commandPostLatestFix(int $missionId, int $userId): ?array {
    $row = dbFetchOne(
        "SELECT vp.lat, vp.lng, vp.created_at, TIMESTAMPDIFF(SECOND, vp.created_at, NOW()) AS age_s
         FROM volunteer_pings vp
         JOIN shifts s ON s.id = vp.shift_id
         WHERE s.mission_id = ? AND vp.user_id = ?
         ORDER BY vp.id DESC LIMIT 1",
        [$missionId, $userId]
    );
    return $row ? [
        'lat' => (float) $row['lat'], 'lng' => (float) $row['lng'],
        'at' => (string) $row['created_at'], 'age_s' => (int) $row['age_s'],
    ] : null;
}

/**
 * Make the command post follow a participant's device — a phone or tablet in
 * the command vehicle. It goes to that device's latest fix straight away, as
 * a placement or a move would, and from then on every fix from it moves the
 * pin (commandPostFollowFix()).
 *
 * Returns ['ok' => true, 'changed' => bool, 'action' => set|moved,
 * 'moved_m' => int|null, 'lat', 'lng'] — the same shape setMissionCommandPost()
 * answers with, so the caller announces it the same way — or ['ok' => false,
 * 'error' => lang key]. changed is false when it already followed this device
 * or the device already stood on the pin.
 */
function startCommandPostFollow(int $missionId, int $followUserId, int $actorId): array {
    if (!isActionRoomParticipant($missionId, $followUserId)) {
        return ['ok' => false, 'error' => 'cp.err_follow_not_participant'];
    }
    $fix = commandPostLatestFix($missionId, $followUserId);
    if (!$fix) {
        return ['ok' => false, 'error' => 'cp.err_follow_no_fix'];
    }
    // A device that has gone quiet is not a place to put the command post:
    // the pin would jump to wherever it was hours ago. Same line as every pin
    // on the map uses for a stale position.
    if ($fix['age_s'] > warRoomPingStaleThresholdSeconds()) {
        $ts = strtotime($fix['at']);
        return ['ok' => false, 'error' => 'cp.err_follow_stale_fix',
                'vars' => ['time' => date(date('Y-m-d', $ts) === date('Y-m-d') ? 'H:i' : 'd/m H:i', $ts)]];
    }
    $current = dbFetchOne("SELECT lat, lng, follow_user_id FROM mission_command_posts WHERE mission_id = ?", [$missionId]);
    if ($current && (int) $current['follow_user_id'] === $followUserId) {
        return ['ok' => true, 'changed' => false, 'action' => 'moved', 'moved_m' => 0, 'lat' => (float) $current['lat'], 'lng' => (float) $current['lng']];
    }
    if ($current && $current['follow_user_id'] !== null) {
        stopCommandPostFollow($missionId, $actorId);
    }
    $movedM = $current ? gpsDistanceMeters((float) $current['lat'], (float) $current['lng'], $fix['lat'], $fix['lng']) : null;
    $moves = $current === null || $movedM >= 1;
    $action = $current ? 'moved' : 'set';
    if ($current) {
        dbExecute(
            "UPDATE mission_command_posts
             SET lat = ?, lng = ?, follow_user_id = ?, fix_at = ?, anchor_lat = ?, anchor_lng = ?,
                 cand_lat = NULL, cand_lng = NULL, cand_since = NULL"
                . ($moves ? ", last_action = 'moved', placed_at = NOW(), placed_by = ?" : '')
                . " WHERE mission_id = ?",
            array_merge(
                [$fix['lat'], $fix['lng'], $followUserId, $fix['at'], $fix['lat'], $fix['lng']],
                $moves ? [$actorId] : [],
                [$missionId]
            )
        );
    } else {
        dbExecute(
            "INSERT INTO mission_command_posts (mission_id, lat, lng, last_action, placed_at, placed_by, follow_user_id, fix_at, anchor_lat, anchor_lng)
             VALUES (?, ?, ?, 'set', NOW(), ?, ?, ?, ?, ?)",
            [$missionId, $fix['lat'], $fix['lng'], $actorId, $followUserId, $fix['at'], $fix['lat'], $fix['lng']]
        );
    }
    dbInsert(
        "INSERT INTO mission_command_post_log (mission_id, action, lat, lng, user_id, followed_user_id, created_at)
         VALUES (?, 'follow', ?, ?, ?, ?, NOW())",
        [$missionId, $fix['lat'], $fix['lng'], $actorId, $followUserId]
    );
    $movedRounded = $movedM !== null ? (int) round($movedM) : null;
    if ($moves) {
        dbInsert(
            "INSERT INTO mission_command_post_log (mission_id, action, lat, lng, moved_m, user_id, via, followed_user_id, created_at)
             VALUES (?, ?, ?, ?, ?, ?, 'follow', ?, NOW())",
            [$missionId, $action, $fix['lat'], $fix['lng'], $movedRounded, $actorId, $followUserId]
        );
    }
    return ['ok' => true, 'changed' => $moves, 'action' => $action, 'moved_m' => $movedRounded, 'lat' => $fix['lat'], 'lng' => $fix['lng']];
}

/**
 * Stop following: the command post stays where the device last put it, as a
 * fixed point. Returns whether it was following anything.
 */
function stopCommandPostFollow(int $missionId, int $actorId): bool {
    $current = dbFetchOne("SELECT lat, lng, follow_user_id FROM mission_command_posts WHERE mission_id = ?", [$missionId]);
    if (!$current || $current['follow_user_id'] === null) {
        return false;
    }
    dbExecute(
        "UPDATE mission_command_posts
         SET follow_user_id = NULL, fix_at = NULL, anchor_lat = NULL, anchor_lng = NULL,
             cand_lat = NULL, cand_lng = NULL, cand_since = NULL
         WHERE mission_id = ?",
        [$missionId]
    );
    dbInsert(
        "INSERT INTO mission_command_post_log (mission_id, action, lat, lng, user_id, followed_user_id, created_at)
         VALUES (?, 'unfollow', ?, ?, ?, ?, NOW())",
        [$missionId, (float) $current['lat'], (float) $current['lng'], $actorId, (int) $current['follow_user_id']]
    );
    return true;
}

/**
 * One accepted fix from a participant's device (recordVolunteerPing()). If a
 * command post follows that device, the pin moves to the fix; and once the
 * device has stood still somewhere new long enough, that spot becomes where
 * the command post IS, and everybody is told — once, not on every fix of the
 * drive there. Returns the announcement it made
 * (['moved_m', 'lat', 'lng', 'notified']), or null.
 *
 * $fixAgeSeconds: how old the fix was when it arrived (the Android app
 * queues fixes through a dead zone); times are taken from the database's
 * clock minus that, like the ping's own row. A fix older than the one the
 * pin already shows is ignored.
 */
function commandPostFollowFix(int $missionId, int $userId, float $lat, float $lng, int $fixAgeSeconds): ?array {
    // Cheap for everybody else: one primary-key read, and nothing more
    // unless this is the device being followed.
    $follows = dbFetchValue(
        "SELECT 1 FROM mission_command_posts WHERE mission_id = ? AND follow_user_id = ?",
        [$missionId, $userId]
    );
    if (!$follows) {
        return null;
    }

    $settled = null;
    $pdo = db();
    // Its own transaction unless the caller already has one (the tests do).
    $ownTransaction = !$pdo->inTransaction();
    if ($ownTransaction) {
        $pdo->beginTransaction();
    }
    try {
        // Locked: the page and the Android app can both deliver a fix within
        // the same second, and two of them must not both settle one stop.
        $cp = dbFetchOne(
            "SELECT lat, lng, anchor_lat, anchor_lng, cand_lat, cand_lng,
                    TIMESTAMPDIFF(SECOND, fix_at, NOW()) AS fix_age_s,
                    TIMESTAMPDIFF(SECOND, cand_since, NOW()) AS cand_age_s
             FROM mission_command_posts WHERE mission_id = ? AND follow_user_id = ? FOR UPDATE",
            [$missionId, $userId]
        );
        if (!$cp || ($cp['fix_age_s'] !== null && $fixAgeSeconds > (int) $cp['fix_age_s'])) {
            if ($ownTransaction) {
                $pdo->commit();
            }
            return null;
        }
        $anchorLat = $cp['anchor_lat'] !== null ? (float) $cp['anchor_lat'] : (float) $cp['lat'];
        $anchorLng = $cp['anchor_lng'] !== null ? (float) $cp['anchor_lng'] : (float) $cp['lng'];
        $sets = ['lat = ?', 'lng = ?', 'fix_at = DATE_SUB(NOW(), INTERVAL ? SECOND)', 'anchor_lat = ?', 'anchor_lng = ?'];
        $binds = [$lat, $lng, $fixAgeSeconds, $anchorLat, $anchorLng];

        if (gpsDistanceMeters($anchorLat, $anchorLng, $lat, $lng) < COMMAND_POST_NOTIFY_MIN_M) {
            // Still where everybody thinks it is (or back there).
            $sets[] = 'cand_lat = NULL, cand_lng = NULL, cand_since = NULL';
        } elseif ($cp['cand_lat'] === null
            || gpsDistanceMeters((float) $cp['cand_lat'], (float) $cp['cand_lng'], $lat, $lng) > COMMAND_POST_FOLLOW_STILL_M) {
            // Moving, or just stopped: this is where it might be staying.
            $sets[] = 'cand_lat = ?, cand_lng = ?, cand_since = DATE_SUB(NOW(), INTERVAL ? SECOND)';
            array_push($binds, $lat, $lng, $fixAgeSeconds);
        } elseif ((int) $cp['cand_age_s'] - $fixAgeSeconds >= COMMAND_POST_FOLLOW_SETTLE_S) {
            // Stood still there long enough: the command post has moved.
            $spotLat = (float) $cp['cand_lat'];
            $spotLng = (float) $cp['cand_lng'];
            $movedM = (int) round(gpsDistanceMeters($anchorLat, $anchorLng, $spotLat, $spotLng));
            $sets = ['lat = ?', 'lng = ?', 'fix_at = DATE_SUB(NOW(), INTERVAL ? SECOND)', 'anchor_lat = ?', 'anchor_lng = ?',
                     "cand_lat = NULL, cand_lng = NULL, cand_since = NULL, last_action = 'moved', placed_at = DATE_SUB(NOW(), INTERVAL ? SECOND), placed_by = ?"];
            $binds = [$lat, $lng, $fixAgeSeconds, $spotLat, $spotLng, max(0, (int) $cp['cand_age_s']), $userId];
            dbInsert(
                "INSERT INTO mission_command_post_log (mission_id, action, lat, lng, moved_m, user_id, via, followed_user_id, created_at)
                 VALUES (?, 'moved', ?, ?, ?, ?, 'follow', ?, NOW())",
                [$missionId, $spotLat, $spotLng, $movedM, $userId, $userId]
            );
            $settled = ['moved_m' => $movedM, 'lat' => $spotLat, 'lng' => $spotLng];
        }
        $binds[] = $missionId;
        dbExecute("UPDATE mission_command_posts SET " . implode(', ', $sets) . " WHERE mission_id = ?", $binds);
        if ($ownTransaction) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($ownTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    if (!$settled) {
        return null;
    }
    // After the commit: telling thirty people is not something to hold a row
    // lock through.
    $mission = dbFetchOne("SELECT id, title, responsible_user_id FROM missions WHERE id = ?", [$missionId]);
    $note = dbFetchValue("SELECT note FROM mission_command_posts WHERE mission_id = ?", [$missionId]);
    $settled['notified'] = $mission ? notifyCommandPostPlaced(
        $mission, ['changed' => true, 'action' => 'moved', 'moved_m' => $settled['moved_m'], 'via' => 'follow'],
        $settled['lat'], $settled['lng'], $note !== null && $note !== false ? (string) $note : null, $userId
    ) : 0;
    return $settled;
}

/**
 * Whether a placement is worth telling everybody about: the first one always,
 * a move once it is far enough to send someone to the wrong place.
 */
function commandPostChangeIsNews(array $result): bool {
    if (empty($result['changed'])) {
        return false;
    }
    return $result['action'] === 'set' || (int) $result['moved_m'] >= COMMAND_POST_NOTIFY_MIN_M;
}

/**
 * The note beside the pin («Λευκό βαν ΕΚΑΒ, πίσω από το δημαρχείο») — how to
 * find it once you are there. Blank clears it. Returns whether it changed;
 * false as well when there is no command post to write it on.
 */
function setMissionCommandPostNote(int $missionId, string $note, int $userId): bool {
    $note = mb_substr(trim(preg_replace('/\s+/u', ' ', $note)), 0, COMMAND_POST_NOTE_MAX);
    $current = dbFetchOne("SELECT note FROM mission_command_posts WHERE mission_id = ?", [$missionId]);
    if (!$current || (string) $current['note'] === $note) {
        return false;
    }
    dbExecute("UPDATE mission_command_posts SET note = ? WHERE mission_id = ?", [$note !== '' ? $note : null, $missionId]);
    dbInsert(
        "INSERT INTO mission_command_post_log (mission_id, action, note, user_id, created_at) VALUES (?, 'note', ?, ?, NOW())",
        [$missionId, $note !== '' ? $note : null, $userId]
    );
    return true;
}

/** Take it off the map. Returns whether there was one to take off. */
function clearMissionCommandPost(int $missionId, int $userId): bool {
    $removed = dbExecute("DELETE FROM mission_command_posts WHERE mission_id = ?", [$missionId]);
    if (!$removed) {
        return false;
    }
    dbInsert(
        "INSERT INTO mission_command_post_log (mission_id, action, user_id, created_at) VALUES (?, 'cleared', ?, NOW())",
        [$missionId, $userId]
    );
    return true;
}

/** «~350 μ.» / «~1,2 χλμ.» in the reader's language. */
function commandPostDistanceText(int $metres, ?string $lang = null): string {
    if ($metres < 1000) {
        return t('cp.distance_m', ['n' => (string) (int) (round($metres / 10) * 10)], $lang);
    }
    $km = round($metres / 1000, 1);
    $lang = $lang ?? (getCurrentUser()['language'] ?? DEFAULT_LANGUAGE);
    $decimal = $lang === 'en' ? '.' : ',';
    return t('cp.distance_km', ['n' => number_format($km, $km < 10 ? 1 : 0, $decimal, '')], $lang);
}

/**
 * Everyone who should know the command post is somewhere new: every Action
 * Room participant in the field and every other coordinator — the same people
 * a Μαζικό Συμβάν reaches — except whoever moved it. It arrives as a notice in
 * the order popup («Κατάλαβα») with a small map of the new spot and
 * directions, and as a push on the phone; the tag makes a second move replace
 * the first there instead of stacking. Returns how many were told.
 */
function notifyCommandPostPlaced(array $mission, array $result, float $lat, float $lng, ?string $note, int $actorId): int {
    $missionId = (int) $mission['id'];
    $responsibleId = !empty($mission['responsible_user_id']) ? (int) $mission['responsible_user_id'] : null;
    $ids = array_values(array_unique(array_merge(
        actionRoomNotifyRecipientIds($missionId, null, $actorId),
        getMissionCommandStaffIds($missionId, $responsibleId, $actorId)
    )));
    if (!$ids) {
        return 0;
    }
    $moved = $result['action'] === 'moved';
    // Moved by command, or stopped somewhere new with the device it follows.
    $movedKey = ($result['via'] ?? 'hand') === 'follow' ? 'cp.notify_arrived_message' : 'cp.notify_moved_message';
    $warRoomUrl = rtrim(BASE_URL, '/') . '/war-room.php?id=' . $missionId;
    $langs = getUserLanguages($ids);
    foreach ($ids as $id) {
        $lang = $langs[$id] ?? DEFAULT_LANGUAGE;
        $message = $moved
            ? t($movedKey, ['mission' => $mission['title'], 'distance' => commandPostDistanceText((int) $result['moved_m'], $lang)], $lang)
            : t('cp.notify_set_message', ['mission' => $mission['title']], $lang);
        if ($note !== null && $note !== '') {
            $message .= ' ' . t('cp.notify_note_suffix', ['note' => $note], $lang);
        }
        sendNotification($id, t($moved ? 'cp.notify_moved_title' : 'cp.notify_set_title', [], $lang), $message, 'info', 'mission_command_post', [
            'url' => $warRoomUrl,
            'tag' => 'command-post-mission-' . $missionId,
            'bannerMission' => $missionId,
            // A notice in the order popup, with the new spot on its map —
            // see notificationPopupRef().
            'popupInfo' => 'mission_command_post',
            'popupLat' => round($lat, 7),
            'popupLng' => round($lng, 7),
        ]);
    }
    return count($ids);
}

/**
 * Every command post event for the two activity timelines, oldest first:
 * rows of ['kind' => cp_set|cp_moved|cp_note|cp_cleared, 'ts', 'actor',
 * 'moved_m', 'note', 'lat', 'lng'].
 */
function loadCommandPostActivityEvents(int $missionId): array {
    $events = [];
    foreach (dbFetchAll(
        "SELECT l.action, l.lat, l.lng, l.moved_m, l.note, l.via, l.created_at, u.name AS actor, f.name AS device
         FROM mission_command_post_log l
         LEFT JOIN users u ON u.id = l.user_id
         LEFT JOIN users f ON f.id = l.followed_user_id
         WHERE l.mission_id = ?
         ORDER BY l.created_at, l.id",
        [$missionId]
    ) as $row) {
        $events[] = [
            'kind' => 'cp_' . $row['action'],
            'ts' => strtotime((string) $row['created_at']),
            'actor' => $row['actor'],
            'moved_m' => $row['moved_m'] !== null ? (int) $row['moved_m'] : null,
            'note' => $row['note'],
            // 'follow' on a move: it arrived there with the device it follows.
            'via' => $row['via'],
            // Whose device, on follow / unfollow / a move with the device.
            'device' => $row['device'],
            'lat' => $row['lat'] !== null ? (float) $row['lat'] : null,
            'lng' => $row['lng'] !== null ? (float) $row['lng'] : null,
        ];
    }
    return $events;
}

/** Icon for one of the events above, the same in both timelines. */
function commandPostActivityIcon(array $e): string {
    return ['cp_set' => '📍', 'cp_moved' => '🚐', 'cp_note' => '📝', 'cp_cleared' => '✖️',
            'cp_follow' => '📡', 'cp_unfollow' => '📌'][$e['kind']] ?? '📍';
}

/** One activity line, in the viewer's language. Plain text: callers escape. */
function commandPostActivityText(array $e, ?string $lang = null): string {
    $name = $e['actor'] ?? '—';
    $device = $e['device'] ?? '—';
    switch ($e['kind']) {
        case 'cp_set':
            return t('cp.act_set', ['name' => $name], $lang);
        case 'cp_moved':
            return ($e['via'] ?? null) === 'follow'
                ? t('cp.act_moved_follow', ['device' => $device, 'distance' => commandPostDistanceText((int) $e['moved_m'], $lang)], $lang)
                : t('cp.act_moved', ['name' => $name, 'distance' => commandPostDistanceText((int) $e['moved_m'], $lang)], $lang);
        case 'cp_follow':
            return t('cp.act_follow', ['name' => $name, 'device' => $device], $lang);
        case 'cp_unfollow':
            return t('cp.act_unfollow', ['name' => $name, 'device' => $device], $lang);
        case 'cp_note':
            return $e['note'] !== null && $e['note'] !== ''
                ? t('cp.act_note', ['name' => $name, 'note' => $e['note']], $lang)
                : t('cp.act_note_cleared', ['name' => $name], $lang);
        case 'cp_cleared':
            return t('cp.act_cleared', ['name' => $name], $lang);
    }
    return '';
}
