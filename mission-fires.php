<?php
/**
 * VolunteerOps - Wildfire Overlay Toggle Endpoint
 * War Room: admin turns the NASA FIRMS fire-hotspot map overlay on/off for
 * this mission. Write-only — every viewer's copy of the overlay data itself
 * rides the existing ajax=1 poll (war-room.php), same as the weather
 * compass; this endpoint only flips the shared missions.fires_overlay_enabled
 * flag so the next poll tick picks it up for everyone.
 */
require_once __DIR__ . '/bootstrap.php';
requireLogin();

header('Content-Type: application/json');

$userId = getCurrentUserId();

$missionId = (int) post('mission_id');

$mission = dbFetchOne(
    "SELECT id, status, show_in_ops, responsible_user_id FROM missions WHERE id = ? AND deleted_at IS NULL",
    [$missionId]
);
if (!$mission || $mission['status'] !== STATUS_OPEN || empty($mission['show_in_ops'])) {
    echo json_encode(['ok' => false, 'error' => t('common.mission_not_found_or_inactive')]);
    exit;
}

$canManageWarRoom = canManageActionRoom($mission['responsible_user_id'] ? (int)$mission['responsible_user_id'] : null, (int)$userId);
if (!$canManageWarRoom) {
    echo json_encode(['ok' => false, 'error' => t('common.no_access_action_room')]);
    exit;
}

if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string) $_POST['csrf_token'])) {
    echo json_encode(['ok' => false, 'error' => t('common.invalid_request')]);
    exit;
}

// Explicit 0/1 rather than a blind toggle, so a double-click or two admins
// racing each other can't flip-flop the shared state unpredictably.
$enabled = post('enabled') === '1' ? 1 : 0;

if ($enabled && empty(trim(getSetting('nasa_firms_api_key', '')))) {
    echo json_encode(['ok' => false, 'error' => t('fires.no_api_key')]);
    exit;
}

// Wrapped: a DB hiccup here (e.g. a pending migration not applied yet) must
// never dump a raw PHP error into this response — that corrupts the JSON
// for the fetch() caller just like the str_getcsv bug did for wildfire.php,
// same lesson, applied here proactively rather than after another report.
try {
    dbExecute("UPDATE missions SET fires_overlay_enabled = ? WHERE id = ?", [$enabled, $missionId]);
    logAudit('toggle_fires_overlay', 'missions', $missionId, $enabled ? 'on' : 'off');
} catch (Throwable $e) {
    error_log('mission-fires.php toggle failed (mission ' . $missionId . '): ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => t('fires.toggle_failed')]);
    exit;
}

echo json_encode(['ok' => true, 'enabled' => (bool) $enabled]);
