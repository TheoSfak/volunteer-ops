<?php
/**
 * VolunteerOps - Map points endpoint (v3.361.0): the reference points command
 * loads onto the Action Room map. POST only, AJAX.
 *
 *   list     the mission's points, for the page to adopt (every approved
 *            participant, and command staff)
 *   connect  road routes (Google) joining the points in import order, at most
 *            MAP_POINT_LINK_MAX of them; open to everyone who can see the
 *            points, and counted per person because every leg is billed
 *   preview  read a pasted list and say what would be imported, change nothing
 *   import   read a pasted list and add its points
 *   update   one point's name, altitude, access and note
 *   delete   one point
 *   clear    every point of the mission
 *
 * Everything but `list` is command staff only. Every answer carries the
 * points and their version, as the poll does (war-room.php, key
 * `mapPointsVersion`). Server side: includes/functions-map-points.php.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/route-distance.php';
requireLogin();

header('Content-Type: application/json');

$userId = (int) getCurrentUserId();

if (!isPost()) {
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string) $_POST['csrf_token'])) {
    echo json_encode(['ok' => false, 'error' => t('common.invalid_request')]);
    exit;
}

// Nothing below writes the session except the connect quota, and an import of
// a few hundred lines must not hold the lock the same browser's poll is waiting
// on. `connect` releases it itself, after counting its legs.
$action = post('action');
if ($action !== 'connect') {
    session_write_close();
}

$missionId = (int) post('mission_id');
$mission = dbFetchOne(
    "SELECT id, title, responsible_user_id FROM missions
     WHERE id = ? AND status = ? AND show_in_ops = 1 AND deleted_at IS NULL",
    [$missionId, STATUS_OPEN]
);
if (!$mission) {
    echo json_encode(['ok' => false, 'error' => t('common.mission_not_found')]);
    exit;
}

$canManage = canManageActionRoom($mission['responsible_user_id'] ? (int) $mission['responsible_user_id'] : null, $userId);
$isApprovedParticipant = (bool) dbFetchValue(
    "SELECT COUNT(*) FROM participation_requests pr
     JOIN shifts s ON s.id = pr.shift_id
     WHERE s.mission_id = ? AND pr.volunteer_id = ? AND pr.status = ?",
    [$missionId, $userId, PARTICIPATION_APPROVED]
);
if (!$canManage && !$isApprovedParticipant) {
    echo json_encode(['ok' => false, 'error' => t('mp.err_access')]);
    exit;
}

$answer = function (array $extra = []) use ($missionId, $canManage) {
    return json_encode(array_merge([
        'ok' => true,
        'mapPoints' => loadMissionMapPoints($missionId, $canManage),
        'mapPointsVersion' => missionMapPointsVersion($missionId),
    ], $extra), JSON_UNESCAPED_UNICODE);
};

if ($action === 'list') {
    echo $answer();
    exit;
}

if ($action === 'connect') {
    $apiKey = trim((string) getSetting('google_maps_api_key', ''));
    if ($apiKey === '' || !routeDistanceAvailable()) {
        echo json_encode(['ok' => false, 'error' => t('mp.link_no_google')]);
        exit;
    }
    $filter = in_array(post('filter'), ['foot', 'vehicle'], true) ? post('filter') : 'all';
    $mode = post('mode') === 'vehicle' ? 'vehicle' : 'foot';
    $all = mapPointsForLinking($missionId, $filter);
    $used = array_slice($all, 0, MAP_POINT_LINK_MAX);
    if (count($used) < 2) {
        echo json_encode(['ok' => false, 'error' => t('mp.link_need_two')]);
        exit;
    }

    // Counted in the session, like the measuring tool's own limit, before the
    // session is released: every leg is a call billed to the organisation.
    $now = time();
    $key = 'map_points_link_' . $missionId;
    $calls = array_values(array_filter((array) ($_SESSION[$key] ?? []), fn($ts) => is_int($ts) && $ts > $now - MAP_POINT_LINK_RATE_WINDOW));
    $cost = count($used) - 1;
    if (count($calls) + $cost > MAP_POINT_LINK_RATE_MAX) {
        $wait = max(1, MAP_POINT_LINK_RATE_WINDOW - ($now - ($calls[0] ?? $now)));
        echo json_encode(['ok' => false, 'error' => t('mp.err_rate', ['n' => (int) ceil($wait / 60)])]);
        exit;
    }
    $_SESSION[$key] = array_merge($calls, array_fill(0, $cost, $now));
    session_write_close();

    $result = mapPointsLinkLegs($used, $mode, $apiKey);
    echo json_encode([
        'ok' => true,
        'mode' => $mode,
        'filter' => $filter,
        'total' => count($all),
        'used' => count($used),
        'legs' => $result['legs'],
        'meters' => $result['meters'],
        'minutes' => $result['minutes'],
        'unrouted' => $result['unrouted'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!$canManage) {
    echo json_encode(['ok' => false, 'error' => t('mp.err_manage')]);
    exit;
}

if ($action === 'preview' || $action === 'import') {
    $text = (string) ($_POST['text'] ?? '');
    if ($text === '' || trim($text) === '') {
        echo json_encode(['ok' => false, 'error' => t('mp.err_empty')]);
        exit;
    }
    if (strlen($text) > MAP_POINT_MAX_TEXT_BYTES) {
        echo json_encode(['ok' => false, 'error' => t('mp.err_too_big')]);
        exit;
    }
    $parsed = parseMapPointText($text);
    if (count($parsed['rows']) > MAP_POINT_MAX_IMPORT) {
        echo json_encode(['ok' => false, 'error' => t('mp.err_too_many', ['max' => MAP_POINT_MAX_IMPORT, 'n' => count($parsed['rows'])])]);
        exit;
    }
    // A short list of what could not be read, translated here so the page
    // prints it as it is.
    $problems = [];
    foreach (array_slice($parsed['errors'], 0, 20) as $e) {
        $problems[] = ['line' => $e['line'], 'text' => $e['text'], 'reason' => t($e['reason'])];
    }
    $summary = [
        'valid' => count($parsed['rows']),
        'invalid' => count($parsed['errors']),
        'problems' => $problems,
    ];

    if ($action === 'preview') {
        echo $answer(['summary' => $summary]);
        exit;
    }

    if (!$parsed['rows']) {
        echo json_encode(['ok' => false, 'error' => t('mp.err_none_valid'), 'summary' => $summary], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $result = importMissionMapPoints($missionId, $parsed['rows'], $userId);
    if ($result['added'] > 0) {
        logAudit('map_points_imported', 'missions', $missionId, null, ['added' => $result['added'], 'duplicates' => $result['duplicates'], 'invalid' => $summary['invalid']]);
    }
    echo $answer(['summary' => $summary, 'added' => $result['added'], 'duplicates' => $result['duplicates'], 'overLimit' => $result['over_limit']]);
    exit;
}

if ($action === 'assign') {
    $ids = json_decode((string) post('ids'), true);
    if (!is_array($ids) || !$ids || count($ids) > MAP_POINT_ASSIGN_MAX) {
        echo json_encode(['ok' => false, 'error' => t('mp.err_assign_none')]);
        exit;
    }
    $user = getCurrentUser();
    $result = assignMapPointsToTeam($mission, (int) post('team_id'), $ids, $userId, (string) ($user['name'] ?? ''));
    if (isset($result['error'])) {
        echo json_encode(['ok' => false, 'error' => $result['error']]);
        exit;
    }
    echo $answer([
        'assigned' => $result['assigned'],
        'skipped' => $result['skipped'],
        // The page's own order list, so the new orders show at once.
        'dispatches' => loadMissionDispatchesForUser($missionId, $userId, true, $isApprovedParticipant),
    ]);
    exit;
}

if ($action === 'update') {
    // The popup no longer sends an altitude (it is looked up when the point is
    // opened); an update without one keeps what an imported file gave it.
    $elevationText = trim((string) post('elevation'));
    $elevation = isset($_POST['elevation']) ? null : dbFetchValue(
        "SELECT elevation_m FROM mission_map_points WHERE id = ? AND mission_id = ?",
        [(int) post('id'), $missionId]
    );
    $elevation = $elevation !== null && $elevation !== false ? (int) $elevation : null;
    if ($elevationText !== '') {
        $elevation = mapPointParseElevation($elevationText);
        if ($elevation === null || !mapPointElevationInRange($elevation)) {
            echo json_encode(['ok' => false, 'error' => t('mp.err_line_elevation')]);
            exit;
        }
    }
    $found = updateMissionMapPoint(
        $missionId, (int) post('id'), (string) post('name'), $elevation,
        (string) post('access') !== '' ? (string) post('access') : null,
        (string) post('note'), $userId
    );
    if (!$found) {
        echo json_encode(['ok' => false, 'error' => t('mp.err_not_found')]);
        exit;
    }
    logAudit('map_point_updated', 'missions', $missionId, null, ['id' => (int) post('id')]);
    echo $answer();
    exit;
}

if ($action === 'delete') {
    if (deleteMissionMapPoint($missionId, (int) post('id'))) {
        logAudit('map_point_deleted', 'missions', $missionId, null, ['id' => (int) post('id')]);
    }
    echo $answer();
    exit;
}

if ($action === 'clear') {
    $removed = clearMissionMapPoints($missionId);
    if ($removed > 0) {
        logAudit('map_points_cleared', 'missions', $missionId, null, ['removed' => $removed]);
    }
    echo $answer(['removed' => $removed]);
    exit;
}

echo json_encode(['ok' => false, 'error' => t('common.unknown_action')]);
