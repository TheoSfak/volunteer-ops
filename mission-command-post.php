<?php
/**
 * VolunteerOps - The mission's command post («Συντονιστικό») endpoint.
 *
 * POST only, AJAX, command staff only. Everybody else only reads the command
 * post, and reads it from the Action Room's own poll (war-room.php, key
 * `commandPost`).
 *
 *   set       place it, or move it (lat, lng). The first placement, and any
 *             move of COMMAND_POST_NOTIFY_MIN_M or more, is announced to every
 *             participant — see notifyCommandPostPlaced(). Stops following.
 *   follow    follow a participant's device (user_id), v3.347.0
 *   unfollow  stay where it is, as a fixed point
 *   note      how to find it on the spot («Λευκό βαν ΕΚΑΒ»); blank clears it
 *   clear     take it off the map
 *
 * Every answer carries the command post as it now is, for the page to adopt.
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

// Nothing below writes the session, and moving the pin must not wait on the
// same browser's poll holding the session lock.
session_write_close();

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
if (!canManageActionRoom($mission['responsible_user_id'] ? (int) $mission['responsible_user_id'] : null, $userId)) {
    echo json_encode(['ok' => false, 'error' => t('cp.err_manage')]);
    exit;
}

$action = post('action');

if ($action === 'set') {
    if (!commandPostValidLatLng(post('lat'), post('lng'))) {
        echo json_encode(['ok' => false, 'error' => t('dispatch.invalid_point')]);
        exit;
    }
    $lat = (float) post('lat');
    $lng = (float) post('lng');
    $result = setMissionCommandPost($missionId, $lat, $lng, $userId);
    $notified = 0;
    $announced = commandPostChangeIsNews($result);
    if ($result['changed']) {
        logAudit('command_post_' . $result['action'], 'missions', $missionId, null, ['lat' => $lat, 'lng' => $lng, 'moved_m' => $result['moved_m']]);
        if ($announced) {
            $current = loadMissionCommandPost($missionId);
            $notified = notifyCommandPostPlaced($mission, $result, $lat, $lng, $current['note'] ?? null, $userId);
        }
    }
    echo json_encode([
        'ok' => true,
        'changed' => $result['changed'],
        'action' => $result['action'],
        // False for a nudge under COMMAND_POST_NOTIFY_MIN_M, so command is told
        // why nobody was notified.
        'announced' => $announced,
        'notified' => $notified,
        'commandPost' => loadMissionCommandPost($missionId),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Follow a participant's device (v3.347.0): the command post goes to its
// latest fix now — announced like any placement or move — and moves with it
// from then on. user_id 0 is the same as unfollow.
if ($action === 'follow' && (int) post('user_id') > 0) {
    $result = startCommandPostFollow($missionId, (int) post('user_id'), $userId);
    if (!$result['ok']) {
        echo json_encode(['ok' => false, 'error' => t($result['error'], $result['vars'] ?? [])]);
        exit;
    }
    $notified = 0;
    $announced = commandPostChangeIsNews($result);
    logAudit('command_post_follow', 'missions', $missionId, null, ['user_id' => (int) post('user_id'), 'moved_m' => $result['moved_m']]);
    if ($announced) {
        $current = loadMissionCommandPost($missionId);
        $notified = notifyCommandPostPlaced($mission, $result, $result['lat'], $result['lng'], $current['note'] ?? null, $userId);
    }
    echo json_encode([
        'ok' => true, 'changed' => $result['changed'], 'action' => $result['action'],
        'announced' => $announced, 'notified' => $notified,
        'commandPost' => loadMissionCommandPost($missionId),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'unfollow' || $action === 'follow') {
    if (stopCommandPostFollow($missionId, $userId)) {
        logAudit('command_post_unfollow', 'missions', $missionId);
    }
    echo json_encode(['ok' => true, 'commandPost' => loadMissionCommandPost($missionId)], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'note') {
    $changed = setMissionCommandPostNote($missionId, (string) post('note'), $userId);
    if ($changed) {
        logAudit('command_post_note', 'missions', $missionId);
    }
    echo json_encode(['ok' => true, 'changed' => $changed, 'commandPost' => loadMissionCommandPost($missionId)], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'clear') {
    if (clearMissionCommandPost($missionId, $userId)) {
        logAudit('command_post_cleared', 'missions', $missionId);
    }
    echo json_encode(['ok' => true, 'commandPost' => null]);
    exit;
}

echo json_encode(['ok' => false, 'error' => t('common.unknown_action')]);
