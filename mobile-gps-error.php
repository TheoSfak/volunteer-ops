<?php
/**
 * VolunteerOps - Android app GPS failure report (bearer-token auth)
 *
 * mission-gps-error.php's twin for the native background service. The page
 * can only report a failure while it is running, and a screen-off phone is
 * exactly when it is not — so a volunteer whose location switch got turned
 * off in a pocket, or whose battery saver cut GPS with the screen dark, just
 * went quiet on the roster, reading like somebody resting. The service keeps
 * running through both, notices that no fix has arrived, works out why, and
 * says so here.
 *
 * Same rules as the session endpoint: it reports about the TOKEN'S OWN user
 * only (no target id), only named client-reportable reasons are accepted, and
 * the participation gate is recordVolunteerGpsErrorReason()'s own. Cleared by
 * recordVolunteerPing() the moment a fix lands, never here.
 *
 * shift_id rides the query string exactly as it does for
 * mobile-ping-location.php — the service derives this URL from that one.
 * POST only, JSON body {"reason": "..."}.
 */

require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json');

if (!isPost()) {
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

// Same bearer check as mobile-ping-location.php and mobile-alerts.php.
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if (!preg_match('/^Bearer\s+([A-Za-z0-9]+)$/', trim($authHeader), $matches)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Missing or malformed bearer token']);
    exit;
}
$tokenRow = dbFetchOne(
    "SELECT user_id FROM mobile_api_tokens WHERE token_hash = ? AND revoked_at IS NULL",
    [hash('sha256', $matches[1])]
);
$user = $tokenRow
    ? dbFetchOne("SELECT id FROM users WHERE id = ? AND deleted_at IS NULL AND is_active = 1", [(int) $tokenRow['user_id']])
    : null;
if (!$user) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Invalid or revoked token']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
$reason = is_array($body) && isset($body['reason']) && is_string($body['reason']) ? $body['reason'] : '';
if (!in_array($reason, VOLUNTEER_GPS_CLIENT_REASONS, true)) {
    echo json_encode(['ok' => false, 'error' => 'Unknown reason']);
    exit;
}

// The shift must be one of the user's own approved ones on an open Action
// Room mission — otherwise a token could flag a mission it has no part in.
$missionId = dbFetchValue(
    "SELECT s.mission_id FROM participation_requests pr
       JOIN shifts s ON s.id = pr.shift_id
       JOIN missions m ON m.id = s.mission_id
      WHERE pr.shift_id = ? AND pr.volunteer_id = ? AND pr.status = ?
        AND m.status = ? AND m.show_in_ops = 1 AND m.deleted_at IS NULL",
    [(int) get('shift_id'), (int) $user['id'], PARTICIPATION_APPROVED, STATUS_OPEN]
);
if (!$missionId) {
    echo json_encode(['ok' => false, 'error' => 'Not an open mission for this user']);
    exit;
}

echo json_encode(['ok' => recordVolunteerGpsErrorReason((int) $missionId, (int) $user['id'], $reason)]);
