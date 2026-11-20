<?php
/**
 * VolunteerOps - Training case endpoint (v3.505.0).
 * The volunteer's side of a training case (load the snapshot, start a run, answer a step) and command's overview.
 * AJAX only, JSON out. The rules (who may play, the scoring) are in includes/functions-training-case-runs.php; nothing
 * here trusts a rating or a score from the request.
 *
 *   GET  ?action=load&mission_id=&placement_id=      the snapshot and my run (audience only, no arrival needed)
 *   POST action=start  mission_id, placement_id      start (or return) my run
 *   POST action=answer mission_id, placement_id, step_id, choice_id, ms_taken
 *   GET  ?action=overview&mission_id=                command's view of every volunteer's run
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();

header('Content-Type: application/json');

$userId = (int) getCurrentUserId();
$missionId = (int) (isPost() ? post('mission_id') : get('mission_id'));

$mission = dbFetchOne(
    "SELECT id, title, status, show_in_ops, responsible_user_id FROM missions WHERE id = ? AND deleted_at IS NULL",
    [$missionId]
);
if (!$mission || $mission['status'] !== STATUS_OPEN || empty($mission['show_in_ops'])) {
    echo json_encode(['ok' => false, 'error' => t('common.mission_not_found_or_inactive')]);
    exit;
}
// Cases exist only in drills.
if (!isTrainingMission($missionId)) {
    echo json_encode(['ok' => false, 'error' => t('case.err_not_training')]);
    exit;
}

$action = isPost() ? post('action') : get('action');

// ── Command's overview ─────────────────────────────────────────────────────
if ($action === 'overview') {
    if (!canManageActionRoom($mission['responsible_user_id'] ? (int) $mission['responsible_user_id'] : null, $userId)) {
        echo json_encode(['ok' => false, 'error' => t('common.no_access_action_room')]);
        exit;
    }
    echo json_encode(['ok' => true] + trainingCaseOverview($missionId), JSON_UNESCAPED_UNICODE);
    exit;
}

if (isPost() && (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string) $_POST['csrf_token']))) {
    echo json_encode(['ok' => false, 'error' => t('common.invalid_request')]);
    exit;
}

$placementId = (int) (isPost() ? post('placement_id') : get('placement_id'));
$placement = $placementId > 0 ? trainingCasePlacement($placementId) : null;
if (!$placement || (int) $placement['mission_id'] !== $missionId) {
    echo json_encode(['ok' => false, 'error' => t('case.err_gone')]);
    exit;
}

// ── Load: the snapshot and my run, for the phone to keep ───────────────────
// The whole document is sent, ratings and feedback included, on purpose: the phone caches it so the case plays
// offline. It is for the audience only (same gate as playing, minus the arrival, so it can be cached before the team
// gets there). The run is read from the server; if it LAGS behind an answer already stored (a request that died
// between the answer and the run's update), the player may simply replay that answer - trainingCaseAnswer() heals the
// run and returns the stored result - so load/start do not repair anything themselves.
if ($action === 'load' && !isPost()) {
    $gate = trainingCaseGate($placement, $userId, false);
    if ($gate !== null) {
        echo json_encode(['ok' => false, 'error' => t($gate)]);
        exit;
    }
    echo json_encode([
        'ok' => true,
        'placement' => ['id' => (int) $placement['id'], 'title' => $placement['title'], 'briefing' => (string) $placement['briefing']],
        'document' => $placement['doc'],
        'run' => trainingCaseRunState((int) $placement['id'], $userId),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Start and answer change data: POST only (the CSRF check above covers every POST).
if ($action === 'start' && isPost()) {
    echo json_encode(trainingCaseStartRun((int) $placement['id'], $userId), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'answer' && isPost()) {
    $ms = post('ms_taken');
    $msTaken = $ms !== '' && $ms !== null ? (int) $ms : null;
    // Two first answers at once (a double tap, a phone replaying its queue) can deadlock in MySQL (1213) or time out
    // on the lock (1205): the transaction is rolled back whole, so one retry is safe; the answer is idempotent.
    $reply = null;
    for ($attempt = 1; $attempt <= 2 && $reply === null; $attempt++) {
        try {
            $reply = trainingCaseAnswer((int) $placement['id'], $userId, (string) post('step_id'), (string) post('choice_id'), $msTaken);
        } catch (PDOException $e) {
            if (!trainingCaseIsLockError($e) || $attempt === 2) {
                error_log('[training-cases] answer failed (attempt ' . $attempt . '): ' . $e->getMessage());
                $reply = ['ok' => false, 'error' => t('common.error')];
            }
        }
    }
    echo json_encode($reply, JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['ok' => false, 'error' => t('common.unknown_action')]);
