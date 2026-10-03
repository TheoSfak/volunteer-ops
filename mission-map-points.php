<?php
/**
 * VolunteerOps - Map points endpoint (v3.361.0): the reference points command
 * loads onto the Action Room map. POST only, AJAX.
 *
 *   list     the mission's points, for the page to adopt (every approved
 *            participant, and command staff)
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

// Nothing below writes the session, and an import of a few hundred lines must
// not hold the lock the same browser's poll is waiting on.
session_write_close();

$missionId = (int) post('mission_id');
$mission = dbFetchOne(
    "SELECT id, responsible_user_id FROM missions
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

$action = post('action');

$answer = function (array $extra = []) use ($missionId) {
    return json_encode(array_merge([
        'ok' => true,
        'mapPoints' => loadMissionMapPoints($missionId),
        'mapPointsVersion' => missionMapPointsVersion($missionId),
    ], $extra), JSON_UNESCAPED_UNICODE);
};

if ($action === 'list') {
    echo $answer();
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
