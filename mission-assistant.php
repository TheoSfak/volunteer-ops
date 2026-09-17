<?php
/**
 * VolunteerOps - Action Room assistant endpoint
 *
 * Two actions, POST only, AJAX, command staff only:
 *
 *   seen - advance this coordinator's «Το είδα» checkpoint. The «Τι μου
 *          ξέφυγε» panel's CONTENTS never come through here: they ride the
 *          Action Room's existing 5s poll payload, so the popup opens with no
 *          network round trip and its badge count cannot disagree with what
 *          the panel shows, because both read the same object. The checkpoint
 *          moves only on an explicit press, never on opening the panel - you
 *          open it, you get called away, and everything unread would be gone.
 *
 *   ask  - one free question about the live mission, answered by the provider
 *          chain (includes/ai-live.php). Deliberately on its own on-demand
 *          endpoint and NEVER on the 5s poll: an AI call holds a connection
 *          for tens of seconds, and this app has already been taken down once
 *          by connection exhaustion during a live exercise.
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

$action = post('action');

if ($action === 'seen') {
    setMissionAssistantCheckpoint($missionId, (int) $userId);

    // No audit log entry: this records what one person has read, not anything
    // they did to the operation, and an audit trail of "coordinator scrolled"
    // is noise in a log that exists to answer who changed what.
    echo json_encode(['ok' => true, 'seen_ts' => time()]);
    exit;
}

if ($action === 'ask') {
    require_once __DIR__ . '/includes/ai-live.php';

    // Throttle BEFORE releasing the session, because the counter lives in it.
    $wait = aiLiveRateLimit($missionId);
    if ($wait !== null) {
        echo json_encode(['ok' => false, 'error' => t('assistant.rate_limited', ['n' => (int) ceil($wait / 60)])]);
        exit;
    }

    // The provider call can take a minute. PHP's default session handler holds
    // an exclusive lock on this session's file for the whole request, so
    // without this every other request from the same browser - the 5s poll
    // included - would queue behind one question. Nothing below reads the
    // session again.
    session_write_close();

    $mission = dbFetchOne("SELECT * FROM missions WHERE id = ?", [$missionId]);
    $missionShiftIds = array_column(
        dbFetchAll("SELECT id FROM shifts WHERE mission_id = ?", [$missionId]),
        'id'
    );

    // The point the coordinator is looking at, if the client sent one. Parsed
    // defensively: a malformed pair means "no focus point", never an error -
    // the question is still worth answering without it.
    $focusPoint = null;
    $lat = post('focus_lat');
    $lng = post('focus_lng');
    if (is_numeric($lat) && is_numeric($lng)
        && abs((float) $lat) <= 90 && abs((float) $lng) <= 180) {
        $focusPoint = ['lat' => (float) $lat, 'lng' => (float) $lng];
    }

    // History is context for pronouns only ("και η άλλη ομάδα;"). It arrives
    // from the client, so it is treated as untrusted text: it goes through the
    // same pseudonymisation and the same leak gate as the question itself, and
    // the prompt labels it as conversation rather than instruction.
    $history = [];
    $rawHistory = json_decode((string) post('history'), true);
    if (is_array($rawHistory)) {
        foreach ($rawHistory as $turn) {
            if (!is_array($turn) || !isset($turn['content']) || !is_string($turn['content'])) continue;
            $history[] = [
                'role'    => ($turn['role'] ?? '') === 'assistant' ? 'assistant' : 'user',
                'content' => mb_substr(trim($turn['content']), 0, AI_LIVE_QUESTION_CAP, 'UTF-8'),
            ];
        }
    }

    echo json_encode(
        askMissionAiLive($missionId, $mission, $missionShiftIds, (string) post('question'), $focusPoint, $history),
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

echo json_encode(['ok' => false, 'error' => t('common.invalid_request')]);
