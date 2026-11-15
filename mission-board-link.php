<?php
/**
 * VolunteerOps - Board View Link Admin Action Endpoint (v3.499.0)
 * Command staff create/list/revoke the read-only «Οθόνη τοίχου» kiosk links
 * (board-view.php). Mirrors mission-locate-link.php's shape.
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();

header('Content-Type: application/json');

$userId = getCurrentUserId();
$missionId = (int) (isPost() ? post('mission_id') : get('mission_id'));

$mission = dbFetchOne("SELECT id, responsible_user_id FROM missions WHERE id = ? AND deleted_at IS NULL", [$missionId]);
if (!$mission) {
    echo json_encode(['ok' => false, 'error' => t('common.mission_not_found')]);
    exit;
}

$canManageWarRoom = canManageActionRoom($mission['responsible_user_id'] ? (int) $mission['responsible_user_id'] : null, (int) $userId);
if (!$canManageWarRoom) {
    echo json_encode(['ok' => false, 'error' => t('board.no_manage_permission')]);
    exit;
}

$action = isPost() ? post('action') : get('action');

if ($action === 'list') {
    echo json_encode(['ok' => true, 'links' => loadMissionBoardLinks($missionId)]);
    exit;
}

if (!isPost()) {
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string) $_POST['csrf_token'])) {
    echo json_encode(['ok' => false, 'error' => t('common.invalid_request')]);
    exit;
}

if ($action === 'create') {
    $label = trim((string) post('label'));
    if (mb_strlen($label) > 120) {
        $label = mb_substr($label, 0, 120);
    }
    $durationRaw = post('duration_hours');
    $durationHours = ($durationRaw === '' || $durationRaw === null) ? null : max(1, min(72, (int) $durationRaw));
    $link = createMissionBoardLink($missionId, $label, (int) $userId, $durationHours);
    logAudit('create_board_link', 'mission_board_links', $link['id'], null, ['mission_id' => $missionId]);
    echo json_encode(['ok' => true, 'id' => $link['id'], 'token' => $link['token'], 'url' => rtrim(BASE_URL, '/') . '/board-view.php?token=' . $link['token']]);
    exit;
}

if ($action === 'revoke') {
    $linkId = (int) post('link_id');
    if (revokeMissionBoardLink($linkId, $missionId)) {
        logAudit('revoke_board_link', 'mission_board_links', $linkId, null, ['mission_id' => $missionId]);
    }
    echo json_encode(['ok' => true]);
    exit;
}

echo json_encode(['ok' => false, 'error' => t('common.unknown_action')]);
