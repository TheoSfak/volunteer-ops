<?php
/**
 * VolunteerOps - Action Room assistant endpoint
 *
 * Four actions, POST only, AJAX, command staff only:
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
 *
 *   handover - the shift-handover brief. Same digest, its own prompt and its
 *          own fixed shape. Produced and shown; never sent to anyone.
 *
 *   draft - turns the coordinator's rough note into the wording of an order.
 *          RETURNS TEXT AND NOTHING ELSE: it creates no order, resolves no
 *          recipient and submits no form. The request_task / request_speak /
 *          global_message handlers in war-room.php stay the only things in
 *          this app that can send anything to anybody.
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

    // What this coordinator's last question left behind: a handful of counters
    // and a timestamp, so the answer can open with what has MOVED rather than
    // repeating what still holds. Read here, before the lock is released, and
    // written back at the very end.
    //
    // In the session rather than a table on purpose. It is per-coordinator by
    // nature ("since YOU asked"), it is worthless once the shift ends, and the
    // rule this whole feature lives under is that an AI answer never becomes
    // part of the operational record. Counters are facts the mission's own
    // tables already hold; none of the model's words are kept.
    $snapshotKey = 'ai_live_snapshot_' . $missionId;
    $snapshot = $_SESSION[$snapshotKey] ?? null;

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

    $result = askMissionAiLive(
        $missionId, $mission, $missionShiftIds, (string) post('question'), $focusPoint, $history, $snapshot
    );

    // Re-open only to store it, long after the provider has answered — the
    // lock is held for the length of one array write, not for the length of
    // the call, which is the whole reason it was released above. Only on a
    // real answer: a coordinator who got an error has not seen anything, and
    // advancing the mark would hide whatever moved while they were failing to
    // get one.
    //
    // cache_limiter '' and the headers_sent() guard are both load-bearing. A
    // second session_start() re-sends Cache-Control/Expires — which this app
    // has been bitten by before on a JSON response — and warns three times
    // over if anything has already been output. display_errors is ON in
    // production, so those warnings would be printed INSIDE the JSON and
    // break a response the coordinator has already paid a provider call for.
    // If output has somehow begun, the memory simply does not advance, which
    // costs one comparison and nothing else.
    if (!empty($result['ok']) && isset($result['snapshot']) && !headers_sent()) {
        session_start(['cache_limiter' => '']);
        $_SESSION[$snapshotKey] = $result['snapshot'];
        session_write_close();
    }
    // Never shipped to the client: it is bookkeeping, and the browser has no
    // use for it that would not also let it lie about what it has been told.
    unset($result['snapshot']);

    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'handover') {
    require_once __DIR__ . '/includes/ai-live.php';

    // Same budget as a question: a handover is one provider call, and a
    // coordinator producing them in a loop is the same runaway to guard against.
    $wait = aiLiveRateLimit($missionId);
    if ($wait !== null) {
        echo json_encode(['ok' => false, 'error' => t('assistant.rate_limited', ['n' => (int) ceil($wait / 60)])]);
        exit;
    }
    session_write_close();

    $mission = dbFetchOne("SELECT * FROM missions WHERE id = ?", [$missionId]);
    $missionShiftIds = array_column(
        dbFetchAll("SELECT id FROM shifts WHERE mission_id = ?", [$missionId]),
        'id'
    );

    echo json_encode(
        generateShiftHandover($missionId, $mission, $missionShiftIds),
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

if ($action === 'draft') {
    require_once __DIR__ . '/includes/ai-live.php';

    $wait = aiLiveRateLimit($missionId);
    if ($wait !== null) {
        echo json_encode(['ok' => false, 'error' => t('assistant.rate_limited', ['n' => (int) ceil($wait / 60)])]);
        exit;
    }
    session_write_close();

    $mission = dbFetchOne("SELECT * FROM missions WHERE id = ?", [$missionId]);
    $missionShiftIds = array_column(
        dbFetchAll("SELECT id FROM shifts WHERE mission_id = ?", [$missionId]),
        'id'
    );

    // Returns TEXT and nothing else. No order is created here and no recipient
    // is resolved - the existing request_task / request_speak / global_message
    // handlers in war-room.php remain the only things that can send anything,
    // and they are reached the way they always were: by the coordinator
    // pressing their own send button on a form they filled in themselves.
    echo json_encode(
        draftMissionMessage(
            $missionId,
            $mission,
            $missionShiftIds,
            (string) post('kind'),
            (string) post('rough')
        ),
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

echo json_encode(['ok' => false, 'error' => t('common.invalid_request')]);
