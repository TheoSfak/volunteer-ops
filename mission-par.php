<?php
/**
 * VolunteerOps - Automatic PAR (Personnel Accountability Report) endpoint
 * Two actions, both POST/AJAX:
 *   save_settings — command-only: turns the mission's dead-man's switch on or
 *                   off and sets its interval in minutes (the checkbox +
 *                   number field in the console's Προετοιμασία panel).
 *   checkin       — any Action Room participant: «Είμαστε ΟΚ», resets their
 *                   own clock. Every participant answers for themselves.
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();

header('Content-Type: application/json');

$userId = getCurrentUserId();

if (!isPost()) {
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string) $_POST['csrf_token'])) {
    echo json_encode(['ok' => false, 'error' => t('common.invalid_request')]);
    exit;
}

$missionId = (int) post('mission_id');
$action    = (string) post('action');

$mission = dbFetchOne(
    "SELECT id, status, responsible_user_id FROM missions WHERE id = ? AND deleted_at IS NULL",
    [$missionId]
);
if (!$mission) {
    echo json_encode(['ok' => false, 'error' => t('common.mission_not_found')]);
    exit;
}

if ($action === 'save_settings') {
    if (!canManageActionRoom($mission['responsible_user_id'] ? (int) $mission['responsible_user_id'] : null, (int) $userId)) {
        echo json_encode(['ok' => false, 'error' => t('gps_participant.no_manage_permission')]);
        exit;
    }
    $enabled = post('enabled') === '1';
    $minutes = max(5, min(180, (int) post('interval_minutes')));
    dbExecute(
        "UPDATE missions SET par_enabled = ?, par_interval_minutes = ? WHERE id = ?",
        [$enabled ? 1 : 0, $minutes, $missionId]
    );
    logAudit($enabled ? 'par_enabled' : 'par_disabled', 'missions', $missionId, null, ['interval_minutes' => $minutes]);
    echo json_encode(['ok' => true, 'enabled' => $enabled, 'interval_minutes' => $minutes]);
    exit;
}

if ($action === 'checkin') {
    if (!isActionRoomParticipant($missionId, (int) $userId)) {
        echo json_encode(['ok' => false, 'error' => t('ping.not_action_room_participant')]);
        exit;
    }
    dbExecute(
        "INSERT INTO mission_par_checkins (mission_id, user_id, last_ok_at, last_prompted_at)
         VALUES (?, ?, NOW(), NULL)
         ON DUPLICATE KEY UPDATE last_ok_at = NOW(), last_prompted_at = NULL",
        [$missionId, $userId]
    );
    echo json_encode(['ok' => true]);
    exit;
}

echo json_encode(['ok' => false, 'error' => t('common.invalid_request')]);
