<?php
/**
 * VolunteerOps - Ground level for the barometer floor («Επίπεδο εδάφους»), v3.426.0.
 *
 * POST only, AJAX, command staff only. A phone's air pressure means nothing
 * until it is compared with the SAME phone's reading at ground level, so
 * command stands the people at the entrance and sets it here. Everybody else
 * only reads the floor, from the Action Room's own poll (war-room.php, key
 * `participantLive`, field `floor`). See includes/functions-baro.php for the
 * reasoning.
 *
 *   candidates  who can be set, grouped by team: whether their phone has sent a
 *               reading in the last two minutes, whether it has a barometer at
 *               all, and when their ground level was set
 *   set         user_ids[] (+ optional ref_user_id, a phone that stays outside
 *               and cancels the weather). Everybody with a recent reading is
 *               set; the rest are named back with the reason.
 *   clear       user_ids[]: take the ground level off, the floor goes away
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

// Nothing below writes the session, and the window must not wait on the same
// browser's poll holding the session lock.
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
    echo json_encode(['ok' => false, 'error' => t('floor.err_manage')]);
    exit;
}

$action = post('action');
$postedIds = isset($_POST['user_ids']) && is_array($_POST['user_ids']) ? $_POST['user_ids'] : [];

if ($action === 'candidates') {
    echo json_encode(['ok' => true] + floorBaselineCandidates($missionId), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'set') {
    if (!$postedIds) {
        echo json_encode(['ok' => false, 'error' => t('floor.err_nobody')]);
        exit;
    }
    $refUserId = (int) post('ref_user_id') > 0 ? (int) post('ref_user_id') : null;
    $result = setFloorBaselines($missionId, $postedIds, $refUserId, $userId);
    if (!$result['ok']) {
        echo json_encode(['ok' => false, 'error' => t('floor.err_' . $result['error'])], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $skipped = [];
    if ($result['skipped']) {
        $marks = implode(',', array_fill(0, count($result['skipped']), '?'));
        $names = [];
        foreach (dbFetchAll("SELECT id, name FROM users WHERE id IN ({$marks})", array_keys($result['skipped'])) as $row) {
            $names[(int) $row['id']] = $row['name'];
        }
        $hasBaro = loadHasBarometerByUser(array_keys($result['skipped']));
        foreach ($result['skipped'] as $skippedId => $reason) {
            // A phone known to have no barometer is told apart from one that
            // simply has not sent a reading lately: the fix for the second is
            // to wait or open the app, for the first there is none.
            if ($reason === 'no_reading' && ($hasBaro[$skippedId] ?? null) === 0) {
                $reason = 'no_barometer';
            }
            $skipped[] = ['user_id' => (int) $skippedId, 'name' => $names[$skippedId] ?? ('#' . $skippedId), 'reason' => $reason];
        }
    }
    if ($result['set']) {
        logAudit('floor_baseline_set', 'missions', $missionId, null, ['user_ids' => $result['set'], 'ref_user_id' => $refUserId]);
    }
    echo json_encode(['ok' => true, 'set' => $result['set'], 'skipped' => $skipped], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'clear') {
    $cleared = clearFloorBaselines($missionId, $postedIds);
    if ($cleared > 0) {
        logAudit('floor_baseline_clear', 'missions', $missionId, null, ['user_ids' => array_map('intval', $postedIds)]);
    }
    echo json_encode(['ok' => true, 'cleared' => $cleared]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Unknown action']);
