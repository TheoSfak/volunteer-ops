<?php
/**
 * VolunteerOps - Whose is the app token on this phone?
 *
 * The Android app keeps ONE bearer token (mobile-token-issue.php) and hands it
 * to its background service. Until v3.336.2 nothing recorded whose it was, so
 * when a second person logged in on the same phone - a team phone, a handed-
 * over phone, an admin testing two accounts - the service went on reporting
 * as the FIRST person: the second person's fixes were refused (not their
 * shift), the server told the service to stop within 30 seconds, and the
 * lock-screen orders it raised were the first person's.
 *
 * The page now keeps the owner's id next to the token. For a token with no
 * recorded owner (every install before v3.336.2) or another person's, it asks
 * here. Session + CSRF like mobile-token-issue.php, with the phone's token as
 * a bearer header:
 *
 *   {valid: true,  mine: true}   - it is the logged-in user's; keep it.
 *   {valid: false}               - unknown or already revoked; issue a new one.
 *   {valid: false, revoked: true} - somebody else's. Revoked here and now, so
 *                                   the service still holding it stops
 *                                   delivering that person's orders and
 *                                   positions from a phone they no longer hold.
 *
 * Revoking another account's token is safe to allow: only whoever holds the
 * token can present it, and holding it means holding the phone.
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();

header('Content-Type: application/json');

if (!isPost()) {
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string) $_POST['csrf_token'])) {
    echo json_encode(['ok' => false, 'error' => t('common.invalid_request')]);
    exit;
}

$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if (!preg_match('/^Bearer\s+([A-Za-z0-9]+)$/', trim($authHeader), $matches)) {
    echo json_encode(['ok' => true, 'valid' => false]);
    exit;
}
$tokenHash = hash('sha256', $matches[1]);

$owner = dbFetchValue(
    "SELECT user_id FROM mobile_api_tokens WHERE token_hash = ? AND revoked_at IS NULL",
    [$tokenHash]
);
if (!$owner) {
    echo json_encode(['ok' => true, 'valid' => false]);
    exit;
}

if ((int) $owner === (int) getCurrentUserId()) {
    echo json_encode(['ok' => true, 'valid' => true, 'mine' => true]);
    exit;
}

dbExecute("UPDATE mobile_api_tokens SET revoked_at = NOW() WHERE token_hash = ? AND revoked_at IS NULL", [$tokenHash]);
echo json_encode(['ok' => true, 'valid' => false, 'revoked' => true]);
