<?php
/**
 * VolunteerOps - War Room Card Layout Save Endpoint
 * Saves the current user's drag-and-drop card arrangement AND show/hide
 * choices for the Action Room admin view. Global per-user, not per-mission —
 * see includes/war-room-layout.php for the shared whitelist/reconciliation
 * logic this endpoint shares with war-room.php's own load path.
 * POST only, AJAX (mirrors mission-shortage.php's wire format, not
 * api-push-subscribe.php's, since this is war-room.php's own sibling
 * endpoint, not a for-everyone feature).
 * The client always sends its complete current state (order + hidden set)
 * on every save, never a delta — that's what lets a single endpoint/row
 * safely serve both the drag-reorder and the visibility-toggle UI without
 * either one's save clobbering the other's most recent change.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/war-room-layout.php';
requireLogin();

header('Content-Type: application/json');

$userId = (int) getCurrentUserId();

if (!isPost()) {
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string) $_POST['csrf_token'])) {
    echo json_encode(['ok' => false, 'error' => t('common.invalid_request')]);
    exit;
}

// Mission-independent check — canManageActionRoom(null, $userId) would wrongly
// exclude a shift leader who manages their own mission's Action Room via
// responsible_user_id but holds no sitewide missions_manage permission.
if (!canManageAnyActionRoom($userId)) {
    echo json_encode(['ok' => false, 'error' => t('common.invalid_request')]);
    exit;
}

$rawLayout = (string) post('layout_json');
if ($rawLayout === '' || strlen($rawLayout) > 10000) {
    echo json_encode(['ok' => false, 'error' => 'Invalid payload size']);
    exit;
}

$layout = json_decode($rawLayout, true);
if (!is_array($layout) || !isset($layout['main']) || !isset($layout['sidebar'])
    || count(array_diff(array_keys($layout), ['main', 'sidebar', 'hidden', 'half'])) > 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid layout shape']);
    exit;
}

$main = $layout['main'];
$sidebar = $layout['sidebar'];
if (!is_array($main) || !is_array($sidebar) || array_values($main) !== $main || array_values($sidebar) !== $sidebar) {
    echo json_encode(['ok' => false, 'error' => 'Invalid layout shape']);
    exit;
}

$combined = array_merge($main, $sidebar);
if (count($combined) > 100) {
    echo json_encode(['ok' => false, 'error' => 'Too many cards']);
    exit;
}

$allIds = warRoomAllCardIds();
foreach ($combined as $id) {
    if (!is_string($id) || !in_array($id, $allIds, true)) {
        echo json_encode(['ok' => false, 'error' => 'Unknown card id']);
        exit;
    }
}

if (count($combined) !== count(array_unique($combined))) {
    echo json_encode(['ok' => false, 'error' => 'Duplicate card id']);
    exit;
}

// 'hidden' is optional (older client, or nothing hidden) and orthogonal to
// main/sidebar — it doesn't partition $combined, it's a subset marker of it.
$hidden = $layout['hidden'] ?? [];
if (!is_array($hidden) || array_values($hidden) !== $hidden) {
    echo json_encode(['ok' => false, 'error' => 'Invalid layout shape']);
    exit;
}
foreach ($hidden as $id) {
    if (!is_string($id) || !in_array($id, $allIds, true)) {
        echo json_encode(['ok' => false, 'error' => 'Unknown card id']);
        exit;
    }
}
if (count($hidden) !== count(array_unique($hidden))) {
    echo json_encode(['ok' => false, 'error' => 'Duplicate card id']);
    exit;
}
if (count(array_diff($hidden, $combined)) > 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid layout shape']);
    exit;
}

// 'half' — which left-zone cards render at half the column's width. Same
// shape and same rules as 'hidden' above: an optional flat subset marker over
// $combined, not a partition of it. Optional on purpose, which is what keeps
// this backward compatible with a client (or a saved row) from before the
// feature existed. An id that currently sits in the sidebar is still accepted:
// the class is inert there (CSS scopes it to #wrZoneMain), and keeping it
// means dragging the card back to the left column restores its chosen width.
$half = $layout['half'] ?? [];
if (!is_array($half) || array_values($half) !== $half) {
    echo json_encode(['ok' => false, 'error' => 'Invalid layout shape']);
    exit;
}
foreach ($half as $id) {
    if (!is_string($id) || !in_array($id, $allIds, true)) {
        echo json_encode(['ok' => false, 'error' => 'Unknown card id']);
        exit;
    }
}
if (count($half) !== count(array_unique($half))) {
    echo json_encode(['ok' => false, 'error' => 'Duplicate card id']);
    exit;
}
if (count(array_diff($half, $combined)) > 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid layout shape']);
    exit;
}

saveWarRoomLayoutForUser($userId, [
    'main' => array_values($main),
    'sidebar' => array_values($sidebar),
    'hidden' => array_values($hidden),
    'half' => array_values($half),
]);
echo json_encode(['ok' => true]);
