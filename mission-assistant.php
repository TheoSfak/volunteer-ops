<?php
/**
 * VolunteerOps - Action Room assistant endpoint
 *
 * One job: advance this coordinator's «Το είδα» checkpoint. The panel's
 * CONTENTS never come through here - they ride the Action Room's existing 5s
 * poll payload, so the popup opens with no network round trip and its badge
 * count cannot disagree with what the panel then shows, because both read the
 * same object.
 *
 * The checkpoint is advanced only by an explicit press, never by opening the
 * panel: you open it, you get called away, and everything you had not read
 * yet would be gone.
 *
 * POST only, AJAX, command staff only.
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

$mission = dbFetchOne(
    "SELECT id, status, show_in_ops, responsible_user_id FROM missions WHERE id = ? AND deleted_at IS NULL",
    [$missionId]
);
if (!$mission || $mission['status'] !== STATUS_OPEN || empty($mission['show_in_ops'])) {
    echo json_encode(['ok' => false, 'error' => t('common.mission_not_found_or_inactive')]);
    exit;
}

if (!canManageActionRoom($mission['responsible_user_id'] ? (int) $mission['responsible_user_id'] : null, (int) $userId)) {
    echo json_encode(['ok' => false, 'error' => t('assistant.no_permission')]);
    exit;
}

if (post('action') !== 'seen') {
    echo json_encode(['ok' => false, 'error' => t('common.invalid_request')]);
    exit;
}

setMissionAssistantCheckpoint($missionId, (int) $userId);

// No audit log entry: this records what one person has read, not anything
// they did to the operation, and an audit trail of "coordinator scrolled" is
// noise in a log that exists to answer who changed what.
echo json_encode(['ok' => true, 'seen_ts' => time()]);
