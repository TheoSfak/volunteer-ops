<?php
/**
 * VolunteerOps - Mission GPS Trail Endpoint ("Πορεία Ομάδων")
 * War Room: admin-only historical GPS trail per volunteer for a mission —
 * one or all teams, optionally including auto-captured pings (hidden by
 * default everywhere else). GET only, AJAX.
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
if (!$canManageWarRoom) {
    echo json_encode(['ok' => false, 'error' => 'Η πορεία ομάδων είναι διαθέσιμη μόνο σε διαχειριστές.']);
    exit;
}

$teamId = (int) get('team_id');
$includeAuto = get('include_auto') === '1';

echo json_encode([
    'ok'     => true,
    'trails' => loadMissionTrailForMission($missionId, $teamId, $includeAuto),
]);
