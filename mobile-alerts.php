<?php
/**
 * VolunteerOps - Pending alerts for the native Android app.
 *
 * Exists because the app cannot receive Web Push at all: Android's WebView
 * implements neither the Push API nor the Notifications API (verified on a
 * real device - window.PushManager and window.Notification are both
 * undefined inside the Capacitor WebView), and the app registers no FCM
 * plugin. Meanwhile every alert surface in war-room.php - the banner, the
 * beep, the SOS siren - is page JavaScript driven by a poll, and the OS
 * freezes that the instant the screen goes off. So an admin request reached
 * a locked phone through no route whatsoever.
 *
 * The background-GPS foreground service is already awake with the screen off,
 * so it polls this endpoint and raises a real Android notification itself.
 * Bearer-token authed exactly like mobile-ping-location.php - no session, no
 * CSRF, since a detached background service has neither. Deliberately NOT in
 * bootstrap.php's $__extAllowed for the same reason that file isn't.
 *
 * Cursor design: the client owns the cursor (its last seen notification id)
 * rather than the server marking rows delivered, which keeps this a pure read
 * and needs no schema change. Two safeguards make that safe:
 *  - No cursor (first run, or a reinstall that wiped it) returns the current
 *    max id and ZERO alerts, so a fresh install never replays history.
 *  - Even with a cursor, only rows newer than ALERT_MAX_AGE_MINUTES are
 *    returned, so a phone that was off for hours comes back to whatever is
 *    still current instead of a burst of stale ones.
 */
require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json');

// How far back a returning phone is allowed to be notified about. An order
// from an hour ago is history, not an alert - the volunteer will see it in
// the page's own list when they next open it.
const ALERT_MAX_AGE_MINUTES = 15;
// Bounds one response so a burst can never turn into a notification storm.
const ALERT_MAX_BATCH = 5;

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
$userId = (int) $tokenRow['user_id'];

$sinceId = isset($_GET['since_id']) ? (int) $_GET['since_id'] : 0;

// First contact: hand back where the log currently is and nothing else, so
// installing the app never fires a backlog of notifications at someone.
if ($sinceId <= 0) {
    $maxRow = dbFetchOne("SELECT COALESCE(MAX(id), 0) AS max_id FROM notifications WHERE user_id = ?", [$userId]);
    echo json_encode([
        'ok' => true,
        'cursor' => (int) ($maxRow['max_id'] ?? 0),
        'alerts' => [],
    ]);
    exit;
}

$rows = dbFetchAll(
    "SELECT id, title, message, data
       FROM notifications
      WHERE user_id = ?
        AND id > ?
        AND created_at >= DATE_SUB(NOW(), INTERVAL " . ALERT_MAX_AGE_MINUTES . " MINUTE)
   ORDER BY id ASC
      LIMIT " . ALERT_MAX_BATCH,
    [$userId, $sinceId]
);

$alerts = [];
$cursor = $sinceId;
foreach ($rows as $row) {
    $cursor = max($cursor, (int) $row['id']);
    // `data` carries the same pushData the web banner uses. bannerMission is
    // what marks a notification as an operational alert (orders, dispatch,
    // global messages) as opposed to routine account noise, so it doubles as
    // the "is this worth waking someone up for" test - same signal the page
    // already uses, rather than a second, drifting definition.
    $data = $row['data'] ? json_decode($row['data'], true) : null;
    $alerts[] = [
        'id' => (int) $row['id'],
        'title' => (string) $row['title'],
        'message' => (string) $row['message'],
        'urgent' => is_array($data) && isset($data['bannerMission']),
    ];
}

echo json_encode([
    'ok' => true,
    'cursor' => $cursor,
    'alerts' => $alerts,
]);
