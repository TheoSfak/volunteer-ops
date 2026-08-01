<?php
/**
 * VolunteerOps - Mobile App Token Issuance
 * Called once from inside the native Android app's WebView, right after a
 * normal login, so its background-location plugin has a long-lived
 * credential that doesn't depend on the page's live session/CSRF token —
 * which a detached native process (running while the WebView is suspended
 * or the app is backgrounded) can never reach. See mobile-ping-location.php
 * for the endpoint this token is actually used against.
 * AJAX POST only.
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();

header('Content-Type: application/json');

if (!isPost()) {
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

// AJAX-safe CSRF check (verifyCsrf() redirects on failure which breaks fetch)
if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string) $_POST['csrf_token'])) {
    echo json_encode(['ok' => false, 'error' => t('common.invalid_request')]);
    exit;
}

$userId = getCurrentUserId();

$deviceLabel = trim((string) post('device_label'));
$deviceLabel = $deviceLabel !== '' ? mb_substr($deviceLabel, 0, 100) : 'Android app';

$token = bin2hex(random_bytes(32));
$tokenHash = hash('sha256', $token);

// Re-issuing on the same device (reinstall, cleared app storage, re-login)
// replaces rather than accumulates — revoke any prior token for this exact
// user+device pairing first.
dbExecute(
    "UPDATE mobile_api_tokens SET revoked_at = NOW() WHERE user_id = ? AND device_label = ? AND revoked_at IS NULL",
    [$userId, $deviceLabel]
);

dbInsert(
    "INSERT INTO mobile_api_tokens (user_id, token_hash, device_label, created_at) VALUES (?, ?, ?, NOW())",
    [$userId, $tokenHash, $deviceLabel]
);

// Raw token is returned exactly once — only its hash is ever persisted.
echo json_encode(['ok' => true, 'token' => $token]);
