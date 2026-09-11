<?php
/**
 * VolunteerOps - Action Room live video endpoint
 *
 * Same shape as mission-battery-alert.php: POST only, AJAX, CSRF-checked,
 * mission must be OPEN and shown in ops before anything happens.
 *
 * Stopping is the part that matters most here, so it is worth being explicit
 * about what "stop" means: the authoritative action is kicking the publisher
 * off LiveKit (livekitRemoveParticipant), NOT asking the phone to stop. The
 * device may be wedged, out of signal or backgrounded; command staff pressing
 * stop must end the feed regardless. The volunteer's own client learns about
 * it from LiveKit's disconnect event, not from us.
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

$action    = post('action');
$missionId = (int) post('mission_id');

$mission = dbFetchOne(
    "SELECT id, title, status, show_in_ops, responsible_user_id FROM missions WHERE id = ? AND deleted_at IS NULL",
    [$missionId]
);
if (!$mission || $mission['status'] !== STATUS_OPEN || empty($mission['show_in_ops'])) {
    echo json_encode(['ok' => false, 'error' => t('common.mission_not_found_or_inactive')]);
    exit;
}

$canManageWarRoom = canManageActionRoom(
    $mission['responsible_user_id'] ? (int) $mission['responsible_user_id'] : null,
    (int) $userId
);

if ($action === 'accept') {
    // The volunteer accepting a live request. Nothing here is optional: they
    // must be an approved participant on a shift that is running right now,
    // and there must be an actual open request addressed to them. Live video
    // is never something that can start without the person agreeing to it.
    if (!livekitConfigured()) {
        echo json_encode(['ok' => false, 'error' => t('live.not_configured')]);
        exit;
    }

    $isActiveParticipant = (bool) dbFetchValue(
        "SELECT COUNT(*) FROM participation_requests pr
         JOIN shifts s ON s.id = pr.shift_id
         WHERE s.mission_id = ? AND pr.volunteer_id = ? AND pr.status = ?
           AND s.start_time <= NOW() AND s.end_time > NOW()",
        [$missionId, $userId, PARTICIPATION_APPROVED]
    );
    if (!$isActiveParticipant) {
        echo json_encode(['ok' => false, 'error' => t('live.not_active_participant')]);
        exit;
    }

    // Already publishing (a reload, a second tab, a dropped connection they
    // are retrying) -> hand back a fresh token for the SAME row rather than
    // refusing. Refusing would strand a volunteer whose page reloaded mid-
    // stream with no way back on air.
    $stream = dbFetchOne(
        "SELECT id, status, order_id FROM mission_live_streams
         WHERE mission_id = ? AND user_id = ? AND status <> 'ended'
         ORDER BY id DESC LIMIT 1",
        [$missionId, $userId]
    );
    if (!$stream) {
        echo json_encode(['ok' => false, 'error' => t('live.no_request_for_you')]);
        exit;
    }

    if ($stream['status'] === 'requested') {
        dbExecute(
            "UPDATE mission_live_streams SET status = 'live', started_at = NOW() WHERE id = ?",
            [$stream['id']]
        );
        // Accepting IS the acknowledgement — making them press "Ελήφθη" too
        // would be a second tap for no new information.
        if (!empty($stream['order_id'])) {
            // Acknowledged AND fulfilled in one go: going on air is the whole
            // of what was asked. Leaving fulfilled_at null would park the row
            // in "Οι Εντολές μου" as permanently outstanding, and would also
            // count against the mission's response-time scoring.
            dbExecute(
                "UPDATE mission_order_recipients
                 SET acknowledged_at = COALESCE(acknowledged_at, NOW()),
                     fulfilled_at    = COALESCE(fulfilled_at, NOW())
                 WHERE order_id = ? AND user_id = ?",
                [$stream['order_id'], $userId]
            );
        }
        logAudit('accept_mission_live', 'mission_live_streams', (int) $stream['id'], null, ['mission_id' => $missionId]);

        // Going on air matters more to command than the acknowledgement does:
        // it is the moment there is actually something to look at. Fired only
        // on the requested -> live transition, so a reconnect after a dropped
        // signal does not re-announce the same stream over and over.
        notifyCommandStaffBanner(
            $missionId, $mission['title'],
            $mission['responsible_user_id'] ? (int) $mission['responsible_user_id'] : null, $userId,
            'mission_live_started', 'live.notify_started_title', [],
            'live.notify_started_message',
            ['name' => (string) (getCurrentUser()['name'] ?? ''), 'mission' => $mission['title']]
        );
    }

    $me = getCurrentUser();
    $creds = livekitCreds();
    $secondsLeft = (int) dbFetchValue(
        "SELECT GREATEST(0, ? - TIMESTAMPDIFF(SECOND, started_at, NOW())) FROM mission_live_streams WHERE id = ?",
        [MISSION_LIVE_MAX_SECONDS, $stream['id']]
    );

    echo json_encode([
        'ok'          => true,
        'url'         => $creds['host'],
        // TTL generously exceeds the cap: the token only governs JOINING, and
        // a token expiring mid-stream would kill a session the server has not
        // decided to end. The cap is enforced by the server ending the row.
        'token'       => livekitToken($missionId, (int) $userId, (string) ($me['name'] ?? 'volunteer'), 'publisher', MISSION_LIVE_MAX_SECONDS + 900),
        'room'        => livekitRoomName($missionId),
        'streamId'    => (int) $stream['id'],
        'secondsLeft' => $secondsLeft,
    ]);
    exit;
}

if ($action === 'viewer_token') {
    // Command staff only — this is the decision recorded in the plan: the
    // audience for a live incident feed is the people running the operation,
    // not every participant.
    if (!$canManageWarRoom) {
        echo json_encode(['ok' => false, 'error' => t('live.no_view_permission')]);
        exit;
    }
    if (!livekitConfigured()) {
        echo json_encode(['ok' => false, 'error' => t('live.not_configured')]);
        exit;
    }
    $me = getCurrentUser();
    $creds = livekitCreds();
    echo json_encode([
        'ok'    => true,
        'url'   => $creds['host'],
        'token' => livekitToken($missionId, (int) $userId, (string) ($me['name'] ?? 'command'), 'viewer', 3600),
        'room'  => livekitRoomName($missionId),
    ]);
    exit;
}

if ($action === 'release') {
    // The volunteer accepted, then never got on air — camera permission
    // refused, no camera, capture failed. Ending the stream here (what we used
    // to do) also destroyed the REQUEST, so a retry answered "no active
    // request for you" and the only way back was for command to ask again.
    //
    // The honest state is the one before they pressed start: the request still
    // stands, they are simply not live. Roll the row back to 'requested' so
    // both the retry and command's card tell the truth.
    $stream = dbFetchOne(
        "SELECT id FROM mission_live_streams
         WHERE mission_id = ? AND user_id = ? AND status = 'live'
         ORDER BY id DESC LIMIT 1",
        [$missionId, $userId]
    );
    if ($stream) {
        dbExecute(
            "UPDATE mission_live_streams SET status = 'requested', started_at = NULL WHERE id = ?",
            [$stream['id']]
        );
    }
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'stop') {
    $streamId = (int) post('stream_id');
    $stream = dbFetchOne(
        "SELECT id, user_id, status FROM mission_live_streams WHERE id = ? AND mission_id = ?",
        [$streamId, $missionId]
    );
    if (!$stream) {
        echo json_encode(['ok' => false, 'error' => t('live.stream_not_found')]);
        exit;
    }

    // Command staff may stop anyone's stream; a volunteer may stop their own
    // and nobody else's. The volunteer's right to stop is never conditional —
    // they may have walked into something that must not be broadcast.
    $isOwner = ((int) $stream['user_id'] === (int) $userId);
    if (!$canManageWarRoom && !$isOwner) {
        echo json_encode(['ok' => false, 'error' => t('live.no_stop_permission')]);
        exit;
    }

    if ($stream['status'] !== 'ended') {
        // Only an actually-publishing stream has a LiveKit participant to
        // remove. A 'requested' row is a pending invitation nobody accepted —
        // cancelling it touches only our own database, and calling LiveKit
        // there would fail for the most boring possible reason and surface a
        // scary "did not respond" warning for a perfectly normal action.
        $kicked = true;
        if ($stream['status'] === 'live') {
            // Kick first, record second: if LiveKit is unreachable we still
            // close the row rather than leave it looking live forever, but we
            // must not report success for a feed that might still be flowing.
            $kicked = livekitRemoveParticipant($missionId, livekitIdentity((int) $stream['user_id'], 'publisher'));
        }

        dbExecute(
            "UPDATE mission_live_streams
             SET status = 'ended', ended_at = NOW(), end_reason = ?, ended_by = ?
             WHERE id = ? AND status <> 'ended'",
            // Whose stream it is, not what role the actor holds: someone who
            // is both an admin and the person on camera stopping their own
            // feed is a volunteer decision, and the history should say so.
            [$isOwner ? 'volunteer' : 'command', $userId, $streamId]
        );
        logAudit('stop_mission_live', 'mission_live_streams', $streamId, null, [
            'mission_id' => $missionId,
            'stopped_by' => $userId,
            'livekit_ack' => $kicked,
        ]);

        if (!$kicked) {
            echo json_encode(['ok' => true, 'warning' => t('live.stop_unconfirmed')]);
            exit;
        }
    }

    echo json_encode(['ok' => true]);
    exit;
}

echo json_encode(['ok' => false, 'error' => t('common.unknown_action')]);
