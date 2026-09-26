<?php
/**
 * VolunteerOps - «Δεν μπορώ» on an order (v3.334.0)
 * Action Room: the recipient of an order — or, for a dispatch, route or
 * sector, any member of the team it went to — hands it back to command with a
 * reason (`decline`), or takes that back with «Τελικά μπορώ» (`withdraw`).
 * The rules live in declineMissionOrder() / withdrawOrderDecline() in
 * includes/functions-warroom.php. POST only, AJAX.
 *
 * Answers with the caller's four order lists, freshly loaded, in the poll's
 * own key names, so the page redraws from the server's state rather than from
 * a guess about it — a teammate may have answered for the team a moment ago.
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();

header('Content-Type: application/json');

$userId = (int) getCurrentUserId();
$user = getCurrentUser();

if (!isPost()) {
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}
if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string) $_POST['csrf_token'])) {
    echo json_encode(['ok' => false, 'error' => t('common.invalid_request')]);
    exit;
}

$missionId = (int) post('mission_id');
$mission = dbFetchOne(
    "SELECT id, title, status, show_in_ops, responsible_user_id FROM missions WHERE id = ? AND deleted_at IS NULL",
    [$missionId]
);
if (!$mission || $mission['status'] !== STATUS_OPEN || empty($mission['show_in_ops'])) {
    echo json_encode(['ok' => false, 'error' => t('common.mission_not_found_or_inactive')]);
    exit;
}

$canManageWarRoom = canManageActionRoom($mission['responsible_user_id'] ? (int) $mission['responsible_user_id'] : null, $userId);
$isApprovedParticipant = (bool) dbFetchValue(
    "SELECT COUNT(*) FROM participation_requests pr
     JOIN shifts s ON s.id = pr.shift_id
     WHERE s.mission_id = ? AND pr.volunteer_id = ? AND pr.status = ?",
    [$missionId, $userId, PARTICIPATION_APPROVED]
);

$action = post('action');
$kind = (string) post('kind');
$id = (int) post('id');
if (!in_array($kind, ['order', 'dispatch', 'route', 'sector'], true) || $id <= 0) {
    echo json_encode(['ok' => false, 'error' => t('common.invalid_request')]);
    exit;
}

if ($action === 'decline') {
    $error = declineMissionOrder($mission, $kind, $id, $userId, $user['name'] ?? '', (string) post('reason'), (string) post('note'), $isApprovedParticipant);
} elseif ($action === 'withdraw') {
    $error = withdrawOrderDecline($mission, $kind, $id, $userId, $user['name'] ?? '', $isApprovedParticipant);
} else {
    $error = t('common.unknown_action');
}
if ($error !== null) {
    echo json_encode(['ok' => false, 'error' => $error]);
    exit;
}

echo json_encode([
    'ok'         => true,
    'myTasks'    => loadMyTaskOrdersForUser($missionId, $userId),
    'dispatches' => loadMissionDispatchesForUser($missionId, $userId, $canManageWarRoom, $isApprovedParticipant),
    'routes'     => loadRoutesForUser($missionId, $userId, $canManageWarRoom),
    'sectors'    => loadMissionSectorsForUser($missionId, $userId, $canManageWarRoom, $isApprovedParticipant),
]);
