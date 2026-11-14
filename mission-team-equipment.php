<?php
/**
 * VolunteerOps - Team equipment endpoint (v3.488.0)
 *
 * POST only, AJAX. Which of the mission's equipment types (AED, a stretcher, ...) a team carries right now -
 * changeable by command staff for ANY team of the mission, or by any member of a team for THEIR OWN team alone
 * (whoever is actually holding the gear in the field is rarely the leader, so this is not limited to the leader).
 * Used by both the War Room's Teams card (war-room.php) and the «Ομάδες» page (mission-teams-view.php).
 *
 * Posts the whole set every time (not a diff): team_id, equipment_type_ids[] (every id now checked; none at all
 * clears the team's equipment). Answers with that team's own equipment, as it now is on the server.
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();

header('Content-Type: application/json');

$userId = (int) getCurrentUserId();

if (!isPost()) {
    echo json_encode(['ok' => false, 'error' => t('common.invalid_request')]);
    exit;
}

if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string) $_POST['csrf_token'])) {
    echo json_encode(['ok' => false, 'error' => t('common.invalid_request')]);
    exit;
}

$missionId = (int) post('mission_id');
$mission = dbFetchOne(
    "SELECT id, status, show_in_ops, responsible_user_id FROM missions WHERE id = ? AND deleted_at IS NULL",
    [$missionId]
);
if (!$mission || $mission['status'] !== STATUS_OPEN || empty($mission['show_in_ops'])) {
    echo json_encode(['ok' => false, 'error' => t('common.mission_not_found_or_inactive')]);
    exit;
}

$teamId = (int) post('team_id');
$canManage = canManageActionRoom($mission['responsible_user_id'] ? (int) $mission['responsible_user_id'] : null, $userId);
$myTeamId = getUserTeamIdForMission($missionId, $userId);
if (!$canManage && $teamId !== $myTeamId) {
    echo json_encode(['ok' => false, 'error' => t('teamsp.no_permission')]);
    exit;
}

$equipmentTypeIds = array_map('intval', (array) ($_POST['equipment_type_ids'] ?? []));
$error = setMissionTeamEquipment($missionId, $teamId, $equipmentTypeIds, $userId);
if ($error !== null) {
    echo json_encode(['ok' => false, 'error' => $error]);
    exit;
}

$equipment = missionTeamsEquipmentByTeam($missionId);
echo json_encode(['ok' => true, 'equipment' => $equipment[$teamId] ?? []]);
