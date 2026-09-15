<?php
/**
 * VolunteerOps - Rescuer Vitals Ingest Endpoint
 *
 * Receives batches of heart-rate samples read from a standard Bluetooth LE
 * Heart Rate Service sensor (0x180D) and stores them for the Action Room's
 * live badge and the post-mission report. AJAX POST only.
 *
 * Dual auth on purpose, unlike the GPS ping which needed two separate files:
 *   - Session + CSRF: the normal path. The BLE read runs in JavaScript inside
 *     the Capacitor WebView (which holds the same session as the website) or,
 *     as a fallback, in Chrome/Samsung Internet on Android via Web Bluetooth.
 *   - Authorization: Bearer <token>: reserved for the day the BLE loop moves
 *     into a native foreground service and posts from detached code with no
 *     session to hand off, exactly like mobile-ping-location.php does today.
 * Supporting both from the start means that move is a client change only,
 * with no second endpoint to keep in step.
 *
 * Note on Web Bluetooth: it is NOT available in the Android WebView (the
 * WebBluetoothCG implementation status still lists it as "will be supported
 * in the future"), so inside the APK the reading must come from the native
 * BLE plugin and only the POST below is shared. That is a client concern —
 * this endpoint does not care which stack produced the numbers.
 *
 * Batch contract (JSON body or form fields):
 *   shift_id : the volunteer's approved shift on an open mission
 *   now      : the CLIENT's clock in epoch ms at the moment of sending,
 *              used only to measure skew — never trusted as absolute time
 *   device   : sensor name as advertised over BLE, for the tooltip (optional)
 *   samples  : [[tMs, bpm], ...] or [[tMs, bpm, min, max], ...]
 */

require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json');

if (!isPost()) {
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

// ── Authentication ──────────────────────────────────────────────────────────
// The bearer branch is checked first and, crucially, before requireLogin():
// that helper redirects a session-less request to the login page, which a
// detached native caller would receive as an HTML body where it expects JSON.
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');

if (preg_match('/^Bearer\s+([A-Za-z0-9]+)$/', trim($authHeader), $matches)) {
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

    $user = dbFetchOne(
        "SELECT * FROM users WHERE id = ? AND deleted_at IS NULL AND is_active = 1",
        [(int) $tokenRow['user_id']]
    );
    if (!$user) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Account not found or inactive']);
        exit;
    }

    dbExecute("UPDATE mobile_api_tokens SET last_used_at = NOW() WHERE token_hash = ?", [$tokenHash]);
} else {
    requireLogin();

    // AJAX-safe CSRF check — verifyCsrf() redirects on failure, which breaks fetch().
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string) $_POST['csrf_token'])) {
        echo json_encode(['ok' => false, 'error' => t('common.invalid_request')]);
        exit;
    }

    $user = getCurrentUser();
}

// ── Payload ─────────────────────────────────────────────────────────────────
// Same branch-once-on-Content-Type shape as mobile-ping-location.php: a JSON
// body from the app, plain form fields from a browser or a curl test.
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';

if (stripos($contentType, 'application/json') !== false) {
    $body      = json_decode(file_get_contents('php://input'), true) ?? [];
    $shiftId   = (int) ($body['shift_id'] ?? 0);
    $rawNow    = $body['now'] ?? null;
    $device    = isset($body['device']) ? (string) $body['device'] : null;
    $samples   = is_array($body['samples'] ?? null) ? $body['samples'] : [];
    $source    = (string) ($body['source'] ?? 'ble');
} else {
    $shiftId   = (int) post('shift_id');
    $rawNow    = post('now');
    $device    = post('device');
    // Form transport can only carry a string, so the batch arrives JSON-encoded
    // in one field rather than as samples[0][0]-style array notation — that
    // notation would make a 240-sample batch into ~960 separate POST fields,
    // past the default max_input_vars of 1000.
    $decoded   = json_decode((string) post('samples'), true);
    $samples   = is_array($decoded) ? $decoded : [];
    $source    = (string) (post('source') ?: 'ble');
}

$clientNowMs = ($rawNow !== null && $rawNow !== '' && is_numeric($rawNow)) ? (float) $rawNow : null;

echo json_encode(recordVolunteerVitals($user, $shiftId, $samples, $clientNowMs, $device, $source));
