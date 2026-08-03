<?php
/**
 * VolunteerOps - Mobile App GPS Ping Endpoint (bearer-token auth)
 * Called by the native Android app's background-location plugin
 * (@capgo/background-geolocation, patched — see the patches/ folder in
 * mobile-app/ and mobile-app-yphresies/), which
 * posts from detached native code — no live browser session or CSRF token to
 * use, see mobile-token-issue.php for how the app obtains its token and
 * passes it as a real Authorization header (never the URL — that would land
 * in the web server's access log). Session-authed pings from a live
 * war-room.php tab keep using ping-location.php; both share their core logic
 * via recordVolunteerPing() in includes/functions-warroom.php.
 * AJAX POST only.
 */

require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json');

if (!isPost()) {
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if (!preg_match('/^Bearer\s+([A-Za-z0-9]+)$/', trim($authHeader), $matches)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Missing or malformed bearer token']);
    exit;
}
$tokenHash = hash('sha256', $matches[1]);

$tokenRow = dbFetchOne(
    "SELECT user_id FROM mobile_api_tokens WHERE token_hash = ? AND revoked_at IS NULL",
    [$tokenHash]
);
if (!$tokenRow) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Invalid or revoked token']);
    exit;
}

$user = dbFetchOne("SELECT * FROM users WHERE id = ? AND deleted_at IS NULL AND is_active = 1", [(int) $tokenRow['user_id']]);
if (!$user) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Account not found or inactive']);
    exit;
}

dbExecute("UPDATE mobile_api_tokens SET last_used_at = NOW() WHERE token_hash = ?", [$tokenHash]);

// Two request shapes land here: plain form fields (curl/manual testing) and
// @capgo/background-geolocation's native JSON body (native HTTP delivery,
// posted straight from the Android foreground service — see
// mobile-app*/patches/ for why: upstream doesn't support extra JSON fields,
// so shift_id rides the URL's query string instead of the body — it's just a
// numeric ID, not a secret, unlike the bearer token above, which is exactly
// why that one is a header and never a query param). Branch once on
// Content-Type rather than probing both shapes for every field.
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (stripos($contentType, 'application/json') !== false) {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];

    $shiftId  = (int) get('shift_id');
    $lat      = (float) ($body['latitude'] ?? 0);
    $lng      = (float) ($body['longitude'] ?? 0);
    $source   = 'auto'; // this path is only ever the passive background watcher, never the manual "send now" button
    $rawAccuracy = $body['accuracy'] ?? null;
} else {
    $shiftId  = (int) post('shift_id');
    $lat      = (float) post('lat');
    $lng      = (float) post('lng');
    $source   = post('source') === 'auto' ? 'auto' : 'manual';
    $rawAccuracy = post('accuracy');
}

$accuracy = ($rawAccuracy !== null && $rawAccuracy !== '' && is_numeric($rawAccuracy))
    ? min((float) $rawAccuracy, 5000)
    : null;

echo json_encode(recordVolunteerPing($user, $shiftId, $lat, $lng, $accuracy, $source));
