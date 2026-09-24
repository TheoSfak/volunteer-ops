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

// Which phone this is, as the app reports it ("samsung SM-A525F · Android 14").
// Kept on the token rather than on every ping: it identifies the device and
// does not change between fixes, and the token row is already written on every
// request, so recording it costs no extra query. Before v3.320.0 every label
// was the literal 'Android', which made "which phones give bad positions"
// unanswerable.
$touchToken = function ($rawDevice) use ($tokenHash): void {
    $device = is_string($rawDevice) ? trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $rawDevice)) : '';
    dbExecute(
        "UPDATE mobile_api_tokens SET last_used_at = NOW(), device_label = COALESCE(?, device_label) WHERE token_hash = ?",
        [$device !== '' ? mb_substr($device, 0, 100) : null, $tokenHash]
    );
};

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
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) {
        $body = [];
    }
    $shiftId = (int) get('shift_id');

    // A JSON LIST is the backlog the app kept while it had no signal
    // (v3.322.0), oldest first. One request for up to 50 fixes, so the phone
    // catches up in seconds once signal returns instead of replaying one POST
    // per fix — which is what used to hold its CURRENT position back behind
    // the backlog. The reply says "batch": an older server would answer a
    // list with a single "invalid coordinates", and the app reads the missing
    // "batch" as "resend these one at a time" rather than as delivered.
    if ($body !== [] && array_is_list($body)) {
        $last = end($body);
        $touchToken(is_array($last) ? ($last['device'] ?? null) : null);
        $results = recordNativePingBatch($user, $shiftId, $body);
        echo json_encode(['ok' => true, 'batch' => count($results), 'results' => $results]);
        exit;
    }

    $touchToken($body['device'] ?? null);
    // 'native': bearer-token auth means this can only be the Capacitor Android
    // background-location plugin, which keeps reporting with the screen off.
    echo json_encode(recordNativePingFromJson($user, $shiftId, $body));
    exit;
}

$touchToken(null);
$shiftId  = (int) post('shift_id');
$source   = post('source') === 'auto' ? 'auto' : 'manual';
$rawAccuracy = post('accuracy');
$accuracy = ($rawAccuracy !== null && $rawAccuracy !== '' && is_numeric($rawAccuracy))
    ? min((float) $rawAccuracy, 5000)
    : null;
// Same hard-bound reject-not-clamp rule as ping-location.php — see the
// comment there for why an out-of-range battery value is dropped, not capped.
$rawBattery = post('battery_level');
$batteryLevel = ($rawBattery !== null && $rawBattery !== '' && is_numeric($rawBattery) && (int) $rawBattery >= 0 && (int) $rawBattery <= 100)
    ? (int) $rawBattery
    : null;
echo json_encode(recordVolunteerPing(
    $user, $shiftId, (float) post('lat'), (float) post('lng'), $accuracy, $batteryLevel, $source, 'native',
    parseFixAgeMs(post('fix_age_ms')), false, parseSpeedMps(post('speed'))
));
