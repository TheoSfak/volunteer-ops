<?php
/**
 * VolunteerOps - War Room server-side keepalive
 *
 * Pinged unconditionally — regardless of tab visibility — by every open
 * Action Room tab (see includes/inactivity-timeout.php's $__voIsWarRoomPage
 * branch, the only caller) purely so hitting bootstrap.php's initSession()
 * refreshes $_SESSION['last_activity']. war-room.php's own live-data polls
 * (pollWarRoomData/pollRoom/loadActivity) already do that too, but all three
 * gate themselves on `!document.hidden` and stop the instant this tab isn't
 * the focused one — confirmed live: with Action Room backgrounded, none of
 * them fired again after initial load, so last_activity just aged until a
 * request from a genuinely idle OTHER tab found it stale and killed the
 * whole shared session, Action Room included. This endpoint does nothing
 * else on purpose, so it keeps working when every visibility-gated poll on
 * the page has gone quiet.
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

// Being here at all — past requireLogin() and bootstrap.php's initSession()
// — has already refreshed $_SESSION['last_activity']. Nothing else to do.
echo json_encode(['ok' => true]);
