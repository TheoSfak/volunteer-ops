<?php
/**
 * VolunteerOps - Locate Link Admin Action Endpoint (v3.499.0)
 * Command staff create/list/revoke the consent links sent out to a missing
 * person/caller's own phone (locate.php), and poll the links' live positions
 * for the map. Mirrors mission-sos.php's shape. POST for create/revoke, GET
 * for list/positions (read-only, no state change).
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
    echo json_encode(['ok' => false, 'error' => t('locate.no_manage_permission')]);
    exit;
}

$action = isPost() ? post('action') : get('action');

if ($action === 'list') {
    echo json_encode(['ok' => true, 'links' => loadMissionLocateLinks($missionId)]);
    exit;
}

if ($action === 'positions') {
    echo json_encode(['ok' => true, 'positions' => loadActiveLocateLinkPositions($missionId)]);
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
    $link = createMissionLocateLink($missionId, $label, (int) $userId, $durationHours);
    logAudit('create_locate_link', 'mission_locate_links', $link['id'], null, ['mission_id' => $missionId]);
    echo json_encode(['ok' => true, 'id' => $link['id'], 'token' => $link['token'], 'url' => rtrim(BASE_URL, '/') . '/locate.php?token=' . $link['token']]);
    exit;
}

if ($action === 'revoke') {
    $linkId = (int) post('link_id');
    if (revokeMissionLocateLink($linkId, $missionId)) {
        logAudit('revoke_locate_link', 'mission_locate_links', $linkId, null, ['mission_id' => $missionId]);
    }
    echo json_encode(['ok' => true]);
    exit;
}

echo json_encode(['ok' => false, 'error' => t('common.unknown_action')]);
