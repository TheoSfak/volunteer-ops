<?php
/**
 * VolunteerOps - LiveKit webhook receiver (Action Room live video)
 *
 * This is the answer to the failure nobody presses a button for: the phone
 * dies, the signal drops, the app is killed. Without it the Action Room card
 * would keep showing a live tile and a running countdown for a feed that
 * stopped minutes ago — and a frozen last frame that looks live is worse than
 * no picture at all when someone is making decisions from it.
 *
 * Server-to-server: no session, no CSRF, no login. Authentication is the
 * LiveKit signature on the request itself (livekitVerifyWebhook), which checks
 * both the HMAC and that the body hash matches the signed claim — a captured
 * header cannot be replayed against a different body.
 *
 * Configure in the LiveKit project as: https://<site>/mission-live-webhook.php
 */

require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json');

// Always 200, even on rejection: LiveKit retries non-2xx, and retrying a
// request we will never accept is just noise in someone's logs. What matters
// is that a bad signature changes nothing.
function webhookDone(string $note): void {
    echo json_encode(['ok' => true, 'note' => $note]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    webhookDone('ignored: not a POST');
}

$rawBody = file_get_contents('php://input') ?: '';
// Apache strips Authorization by default; .htaccess already forwards it as
// HTTP_AUTHORIZATION for the mobile app's bearer tokens, and that same rule
// is what makes this work.
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');

$event = livekitVerifyWebhook($rawBody, (string) $auth);
if ($event === null) {
    error_log('mission-live-webhook: rejected an unverifiable request');
    webhookDone('rejected');
}

$type      = (string) ($event['event'] ?? '');
$roomName  = (string) ($event['room']['name'] ?? '');
$identity  = (string) ($event['participant']['identity'] ?? '');

// Room names are "<siteKey>-mission-<id>". Deriving the mission from the name
// is safe only because the site key prefix is verified too: without that check
// a second deployment sharing this LiveKit project could close our streams.
$siteKey = trim((string) getSetting('livekit_site_key', ''));
$missionId = 0;
if ($siteKey !== '' && preg_match('/^' . preg_quote($siteKey, '/') . '-mission-(\d+)$/', $roomName, $m)) {
    $missionId = (int) $m[1];
}
if ($missionId <= 0) {
    webhookDone('ignored: room ' . $roomName . ' is not ours');
}

/** Close one publisher's open stream for this mission. */
function endStreamByIdentity(int $missionId, string $identity, string $reason): string {
    if (!preg_match('/^v(\d+)$/', $identity, $m)) {
        // A viewer (c<id>) leaving is entirely normal and means nothing here.
        return 'ignored: identity ' . $identity . ' is not a publisher';
    }
    $userId = (int) $m[1];
    $affected = dbExecute(
        "UPDATE mission_live_streams
         SET status = 'ended', ended_at = NOW(), end_reason = ?
         WHERE mission_id = ? AND user_id = ? AND status <> 'ended'",
        [$reason, $missionId, $userId]
    );
    if ($affected) {
        logAudit('live_stream_disconnected', 'mission_live_streams', null, null, [
            'mission_id' => $missionId,
            'user_id'    => $userId,
            'reason'     => $reason,
        ]);
    }
    return $affected ? 'closed stream for user ' . $userId : 'nothing open for user ' . $userId;
}

switch ($type) {
    case 'participant_left':
    case 'track_unpublished':
        webhookDone(endStreamByIdentity($missionId, $identity, 'disconnect'));
        // no break needed; webhookDone exits

    case 'room_finished':
        // Everyone is gone. Close whatever is still marked open rather than
        // waiting for individual participant events we may have missed.
        $open = dbFetchAll(
            "SELECT id FROM mission_live_streams WHERE mission_id = ? AND status <> 'ended'",
            [$missionId]
        );
        if ($open) {
            dbExecute(
                "UPDATE mission_live_streams SET status = 'ended', ended_at = NOW(), end_reason = 'disconnect'
                 WHERE mission_id = ? AND status <> 'ended'",
                [$missionId]
            );
        }
        webhookDone('room finished, closed ' . count($open) . ' stream(s)');

    default:
        webhookDone('ignored event: ' . $type);
}
