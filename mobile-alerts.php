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
 *
 * v3.332.0: each alert also says where tapping it lands (`url`, relative, with
 * ?op= naming the order) and, for an order still waiting for «Ελήφθη», which
 * order a button on the notification confirms (`ack`, sent back to
 * mobile-order-ack.php). Older apps ignore both.
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

// v3.330.0: the same poll carries what the background service should be doing
// for the shift it tracks — 'track', 'pause' or 'stop'
// (nativeTrackingInstruction()). Only when the app says which shift (older
// APKs do not, and get no field), and never on a failed lookup: a database
// hiccup must not be the reason somebody's GPS switches off mid-search.
$trackingShiftId = isset($_GET['shift_id']) ? (int) $_GET['shift_id'] : 0;
$tracking = null;
if ($trackingShiftId > 0) {
    try {
        $tracking = nativeTrackingInstruction($userId, $trackingShiftId);
    } catch (Throwable $e) {
        $tracking = null;
    }
}

// First contact: hand back where the log currently is and nothing else, so
// installing the app never fires a backlog of notifications at someone.
if ($sinceId <= 0) {
    $maxRow = dbFetchOne("SELECT COALESCE(MAX(id), 0) AS max_id FROM notifications WHERE user_id = ?", [$userId]);
    echo json_encode(array_filter([
        'ok' => true,
        'cursor' => (int) ($maxRow['max_id'] ?? 0),
        'alerts' => [],
        'tracking' => $tracking,
    ], fn($v) => $v !== null));
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

/**
 * Where tapping the notification should land, relative to the site (the app
 * resolves it against its own base and refuses anything that leaves it).
 * notificationTargetUrl() has already checked that an absolute link is this
 * installation's; only its path and query are kept.
 */
function mobileAlertPath(array $row): ?string {
    $url = notificationTargetUrl($row);
    if ($url === null) {
        return null;
    }
    $parts = parse_url($url);
    $path = $parts['path'] ?? '';
    if (isset($parts['host']) || str_starts_with($path, '/')) {
        $basePath = rtrim((string) parse_url(BASE_URL, PHP_URL_PATH), '/');
        if ($basePath !== '' && str_starts_with($path, $basePath . '/')) {
            $path = substr($path, strlen($basePath));
        }
    }
    $path = ltrim(preg_replace('#^\./#', '', $path), '/');
    if ($path === '') {
        return null;
    }
    return $path . (isset($parts['query']) ? '?' . $parts['query'] : '');
}

/**
 * The order an «Ελήφθη» button on this notification would confirm, or null
 * when there should be no button: not an order of this person's, already
 * received, or an order whose only step is doing it — a location request is
 * answered by sending the location, and a tick from the lock screen would
 * only look like an answer. A sector sent back for a recheck has nothing to
 * receive either (the page's $needsAcknowledgeFirst, same rule).
 *
 * v3.333.0: an arrival question («Έφτασες;») gets «Έφτασα» instead, while the
 * team has not arrived — but only for an app that can label its button per
 * alert ($appLevel >= 2). An older app would put «Ελήφθη» on it.
 */
function mobileAlertAck(?array $ref, int $userId, string $lang, int $appLevel): ?array {
    if (!$ref) {
        return null;
    }
    if ($ref['kind'] === 'arrive') {
        if ($appLevel < 2 || !arrivalPromptStillOpen($ref, $userId)) {
            return null;
        }
        return [
            'kind' => $ref['target'] === 'waypoint' ? 'waypoint_arrive' : 'dispatch_arrive',
            'id' => $ref['id'],
            'label' => t('bgtrack.arrive_ack', [], $lang),
            'acked' => t('bgtrack.arrive_acked', [], $lang),
        ];
    }
    if ($ref['kind'] === 'order') {
        $row = dbFetchOne(
            "SELECT o.order_type, r.acknowledged_at FROM mission_order_recipients r
             JOIN mission_orders o ON o.id = r.order_id
             WHERE r.order_id = ? AND r.user_id = ?",
            [$ref['id'], $userId]
        );
        $offer = $row && !$row['acknowledged_at'] && $row['order_type'] !== 'location';
    } elseif ($ref['kind'] === 'dispatch') {
        $offer = !dbFetchValue(
            "SELECT COUNT(*) FROM mission_dispatch_receipts WHERE dispatch_id = ? AND user_id = ?",
            [$ref['id'], $userId]
        );
    } elseif ($ref['kind'] === 'sector') {
        $row = dbFetchOne("SELECT status, acknowledged_at FROM mission_search_sectors WHERE id = ?", [$ref['id']]);
        $offer = $row && $row['status'] === 'assigned' && !$row['acknowledged_at'];
    } else {
        $offer = false;
    }
    return $offer ? ['kind' => $ref['kind'], 'id' => $ref['id']] : null;
}

/**
 * The ?op= that opens what a notification is about (war-room.php's opRequest):
 * the order itself, or for an arrival question the dispatch or route it asks
 * about. Null for a notice, which has nothing to open.
 */
function mobileAlertOp(?array $ref): ?string {
    if (!$ref || $ref['kind'] === 'info') {
        return null;
    }
    if ($ref['kind'] === 'arrive') {
        if ($ref['target'] === 'waypoint') {
            return !empty($ref['routeId']) ? 'route:' . (int) $ref['routeId'] : null;
        }
        return 'dispatch:' . (int) $ref['id'];
    }
    return $ref['kind'] . ':' . (int) $ref['id'];
}

// What the app understands beyond v3.332.0: 2 = a button labelled per alert
// (the «Έφτασα» of an arrival question). Sent by the app itself; an older
// app sends nothing and gets exactly what it got before.
$appLevel = (int) ($_GET['vops'] ?? 0);
$lang = getUserLanguage($userId);

$alerts = [];
$cursor = $sinceId;
foreach ($rows as $row) {
    $cursor = max($cursor, (int) $row['id']);
    // `data` carries the same pushData the web banner uses. bannerMission is
    // what marks a notification as an operational alert (orders, dispatch,
    // global messages) as opposed to routine account noise, so it doubles as
    // the "is this worth waking someone up for" test - same signal the page
    // already uses, rather than a second, drifting definition. Since v3.332.0
    // it also picks the app's channel: «Εντολές» (heads-up, and an
    // alarm-strength buzz since v3.332.1) or «Ενημερώσεις» (an ordinary
    // notification).
    $data = $row['data'] ? json_decode($row['data'], true) : null;
    $ref = notificationPopupRef($data);
    $path = mobileAlertPath($row);
    // Tapping an order's notification opens that order (war-room.php's
    // opRequest), not just the Action Room.
    $op = mobileAlertOp($ref);
    if ($path !== null && $op !== null && preg_match('#^war-room\.php(\?|$)#', $path)) {
        $path .= (str_contains($path, '?') ? '&' : '?') . 'op=' . $op;
    }
    $alerts[] = array_filter([
        'id' => (int) $row['id'],
        'title' => (string) $row['title'],
        'message' => (string) $row['message'],
        'urgent' => is_array($data) && isset($data['bannerMission']),
        'url' => $path,
        'ack' => mobileAlertAck($ref, $userId, $lang, $appLevel),
    ], fn($v) => $v !== null);
}

// The words the app puts on and after the «Ελήφθη» button, in this person's
// language. The app keeps no strings of its own for alerts.
$texts = null;
if ($alerts) {
    $texts = [
        'ack' => t('bgtrack.alert_ack', [], $lang),
        'acked' => t('bgtrack.alert_acked', [], $lang),
        'ack_retry' => t('bgtrack.alert_ack_retry', [], $lang),
        'ack_open_app' => t('bgtrack.alert_ack_open_app', [], $lang),
    ];
}

echo json_encode(array_filter([
    'ok' => true,
    'cursor' => $cursor,
    'alerts' => $alerts,
    'texts' => $texts,
    'tracking' => $tracking,
], fn($v) => $v !== null), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
