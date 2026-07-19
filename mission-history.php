<?php
/**
 * VolunteerOps - Mission History Endpoint
 * War Room: timeline of dispatch points/areas sent and team arrival reports,
 * scoped to whatever the requesting user can already see on the live map.
 * GET only, AJAX.
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();

header('Content-Type: application/json');

$userId = getCurrentUserId();

$missionId = (int) get('mission_id');

$mission = dbFetchOne(
    "SELECT id, title, status, show_in_ops, responsible_user_id FROM missions WHERE id = ? AND deleted_at IS NULL",
    [$missionId]
);
if (!$mission || $mission['status'] !== STATUS_OPEN || empty($mission['show_in_ops'])) {
    echo json_encode(['ok' => false, 'error' => 'Η αποστολή δεν βρέθηκε ή δεν είναι ενεργή στο Επιχειρησιακό.']);
    exit;
}

$canManageWarRoom = hasPagePermission('missions_manage') || (int)$mission['responsible_user_id'] === (int)$userId;
$isApprovedParticipant = (bool) dbFetchValue(
    "SELECT COUNT(*) FROM participation_requests pr
     JOIN shifts s ON s.id = pr.shift_id
     WHERE s.mission_id = ? AND pr.volunteer_id = ? AND pr.status = ?",
    [$missionId, $userId, PARTICIPATION_APPROVED]
);
if (!$canManageWarRoom && !$isApprovedParticipant) {
    echo json_encode(['ok' => false, 'error' => 'Δεν έχετε πρόσβαση στο War Room αυτής της αποστολής.']);
    exit;
}

$scopeSql = "(d.team_id IS NULL OR ? = 1 OR d.team_id IN (SELECT team_id FROM mission_team_members WHERE user_id = ?))";

$sentRows = dbFetchAll(
    "SELECT d.id, d.type, d.label, d.created_at, d.team_id, mt.codename, mt.team_number, u.name AS actor_name
     FROM mission_dispatch_points d
     LEFT JOIN mission_teams mt ON mt.id = d.team_id
     JOIN users u ON u.id = d.created_by
     WHERE d.mission_id = ? AND $scopeSql",
    [$missionId, $canManageWarRoom ? 1 : 0, $userId]
);

$arrivedRows = dbFetchAll(
    "SELECT a.created_at, a.team_id AS ack_team_id, amt.codename AS ack_codename, amt.team_number AS ack_team_number,
            au.name AS actor_name, d.label AS dispatch_label
     FROM mission_dispatch_acks a
     JOIN mission_dispatch_points d ON d.id = a.dispatch_id
     JOIN users au ON au.id = a.user_id
     LEFT JOIN mission_teams amt ON amt.id = a.team_id
     WHERE d.mission_id = ? AND $scopeSql",
    [$missionId, $canManageWarRoom ? 1 : 0, $userId]
);

$events = [];
foreach ($sentRows as $row) {
    $teamLabel = $row['team_id'] ? ($row['codename'] . ' ' . $row['team_number']) : 'όλες τις ομάδες';
    $kind = $row['type'] === 'point' ? 'σημείο' : 'περιοχή';
    $events[] = [
        'icon' => '📍',
        'text' => h($row['actor_name']) . ' έστειλε ' . $kind . ' στη ' . h($teamLabel)
            . ($row['label'] ? ' — «' . h($row['label']) . '»' : ''),
        'time' => date('d/m H:i', strtotime($row['created_at'])),
        'ts'   => strtotime($row['created_at']),
    ];
}
foreach ($arrivedRows as $row) {
    $teamLabel = $row['ack_team_id'] ? ($row['ack_codename'] . ' ' . $row['ack_team_number']) : null;
    $events[] = [
        'icon' => '✅',
        'text' => ($teamLabel ? 'Η ομάδα ' . h($teamLabel) : h($row['actor_name'])) . ' ανέφερε άφιξη'
            . ($row['dispatch_label'] ? ' στο «' . h($row['dispatch_label']) . '»' : '')
            . ($teamLabel ? ' (' . h($row['actor_name']) . ')' : ''),
        'time' => date('d/m H:i', strtotime($row['created_at'])),
        'ts'   => strtotime($row['created_at']),
    ];
}

usort($events, fn($a, $b) => $b['ts'] <=> $a['ts']);
$events = array_slice($events, 0, 100);

echo json_encode([
    'ok'     => true,
    'events' => array_map(fn($e) => ['icon' => $e['icon'], 'text' => $e['text'], 'time' => $e['time']], $events),
]);
