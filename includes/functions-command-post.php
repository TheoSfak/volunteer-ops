<?php
/**
 * VolunteerOps - The mission's command post («Συντονιστικό») on the live map.
 *
 * One point per mission. Command places it at the start of an operation and
 * moves it whenever the command post itself moves, which is often: it is
 * frequently a vehicle. Every participant sees it on the map, with directions
 * to it, and is told when it is set up and when it moves somewhere else.
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
        "SELECT cp.lat, cp.lng, cp.note, cp.last_action, cp.placed_at, u.name AS placed_by_name
         FROM mission_command_posts cp
         LEFT JOIN users u ON u.id = cp.placed_by
         WHERE cp.mission_id = ?",
        [$missionId]
    );
    if (!$row) {
        return null;
    }
    $placedTs = strtotime((string) $row['placed_at']);
    return [
        'lat' => (float) $row['lat'],
        'lng' => (float) $row['lng'],
        'note' => $row['note'] !== null && $row['note'] !== '' ? (string) $row['note'] : null,
        'action' => $row['last_action'] === 'moved' ? 'moved' : 'set',
        // A mission can run past midnight; an hour alone would then be read
        // as today's.
        'at' => date(date('Y-m-d', $placedTs) === date('Y-m-d') ? 'H:i' : 'd/m H:i', $placedTs),
        'by' => $row['placed_by_name'] !== null ? (string) $row['placed_by_name'] : null,
    ];
}

/**
 * Put the command post at a point: the first placement, or a move. Returns
 * ['changed' => bool, 'action' => 'set'|'moved', 'moved_m' => int|null].
 * Placing it where it already is (a double tap, a drag that ended where it
 * began) changes nothing and logs nothing.
 */
function setMissionCommandPost(int $missionId, float $lat, float $lng, int $userId): array {
    $current = dbFetchOne("SELECT lat, lng FROM mission_command_posts WHERE mission_id = ?", [$missionId]);
    $movedM = $current ? gpsDistanceMeters((float) $current['lat'], (float) $current['lng'], $lat, $lng) : null;
    if ($movedM !== null && $movedM < 1) {
        return ['changed' => false, 'action' => 'moved', 'moved_m' => 0];
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
        "INSERT INTO mission_command_post_log (mission_id, action, lat, lng, moved_m, user_id, created_at)
         VALUES (?, ?, ?, ?, ?, ?, NOW())",
        [$missionId, $action, $lat, $lng, $movedRounded, $userId]
    );
    return ['changed' => true, 'action' => $action, 'moved_m' => $movedRounded];
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
    $warRoomUrl = rtrim(BASE_URL, '/') . '/war-room.php?id=' . $missionId;
    $langs = getUserLanguages($ids);
    foreach ($ids as $id) {
        $lang = $langs[$id] ?? DEFAULT_LANGUAGE;
        $message = $moved
            ? t('cp.notify_moved_message', ['mission' => $mission['title'], 'distance' => commandPostDistanceText((int) $result['moved_m'], $lang)], $lang)
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
        "SELECT l.action, l.lat, l.lng, l.moved_m, l.note, l.created_at, u.name AS actor
         FROM mission_command_post_log l LEFT JOIN users u ON u.id = l.user_id
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
            'lat' => $row['lat'] !== null ? (float) $row['lat'] : null,
            'lng' => $row['lng'] !== null ? (float) $row['lng'] : null,
        ];
    }
    return $events;
}

/** Icon for one of the events above, the same in both timelines. */
function commandPostActivityIcon(array $e): string {
    return ['cp_set' => '📡', 'cp_moved' => '🚐', 'cp_note' => '📝', 'cp_cleared' => '✖️'][$e['kind']] ?? '📡';
}

/** One activity line, in the viewer's language. Plain text: callers escape. */
function commandPostActivityText(array $e, ?string $lang = null): string {
    $name = $e['actor'] ?? '—';
    switch ($e['kind']) {
        case 'cp_set':
            return t('cp.act_set', ['name' => $name], $lang);
        case 'cp_moved':
            return t('cp.act_moved', ['name' => $name, 'distance' => commandPostDistanceText((int) $e['moved_m'], $lang)], $lang);
        case 'cp_note':
            return $e['note'] !== null && $e['note'] !== ''
                ? t('cp.act_note', ['name' => $name, 'note' => $e['note']], $lang)
                : t('cp.act_note_cleared', ['name' => $name], $lang);
        case 'cp_cleared':
            return t('cp.act_cleared', ['name' => $name], $lang);
    }
    return '';
}
