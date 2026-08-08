<?php
/**
 * VolunteerOps - Battery Alert Endpoint
 * War Room: command staff sends a single specific volunteer a "please charge
 * your phone" order from the low-battery badge on their GPS pin popup.
 * Rides on mission_orders (order_type='charge_phone') purely to inherit
 * push/banner/beep/"Ελήφθη" for free, same rationale as mission-route.php's
 * order_type='route'. The acknowledge side (and the notify-sender-back on
 * ack) is handled by mission-order.php, not here — this file only ever
 * creates the order. POST only, AJAX, admin-only.
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();

header('Content-Type: application/json');

$userId = getCurrentUserId();

if (!isPost()) {
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string) $_POST['csrf_token'])) {
    echo json_encode(['ok' => false, 'error' => t('common.invalid_request')]);
    exit;
}

$action = post('action');
$missionId = (int) post('mission_id');
$targetUserId = (int) post('user_id');

$mission = dbFetchOne(
    "SELECT id, title, status, show_in_ops, responsible_user_id FROM missions WHERE id = ? AND deleted_at IS NULL",
    [$missionId]
);
if (!$mission || $mission['status'] !== STATUS_OPEN || empty($mission['show_in_ops'])) {
    echo json_encode(['ok' => false, 'error' => t('common.mission_not_found_or_inactive')]);
    exit;
}

$canManageWarRoom = canManageActionRoom($mission['responsible_user_id'] ? (int) $mission['responsible_user_id'] : null, (int) $userId);
if (!$canManageWarRoom) {
    echo json_encode(['ok' => false, 'error' => t('battery_alert.no_manage_permission')]);
    exit;
}

if ($action === 'send') {
    $isApprovedTarget = (bool) dbFetchValue(
        "SELECT COUNT(*) FROM participation_requests pr
         JOIN shifts s ON s.id = pr.shift_id
         WHERE s.mission_id = ? AND pr.volunteer_id = ? AND pr.status = ?",
        [$missionId, $targetUserId, PARTICIPATION_APPROVED]
    );
    if (!$targetUserId || !$isApprovedTarget) {
        echo json_encode(['ok' => false, 'error' => t('battery_alert.recipient_not_found')]);
        exit;
    }

    // Never trust a client-supplied battery % — re-derive fresh from the
    // volunteer's latest ping, same "server independently re-checks
    // everything" principle every other admin action here already follows.
    $target = dbFetchOne(
        "SELECT vp.battery_level, u.name
         FROM volunteer_pings vp
         JOIN shifts s ON s.id = vp.shift_id
         JOIN users u ON u.id = vp.user_id
         WHERE s.mission_id = ? AND vp.user_id = ?
         ORDER BY vp.id DESC LIMIT 1",
        [$missionId, $targetUserId]
    );
    if (!$target || $target['battery_level'] === null) {
        echo json_encode(['ok' => false, 'error' => t('battery_alert.no_battery_data')]);
        exit;
    }
    $batteryLevel = (int) $target['battery_level'];

    // Server-side re-check of the same fixed bar the popup button's own
    // active/inactive state uses (CHARGE_ALERT_THRESHOLD_PCT, config.php) —
    // the client-side "inactive" state is deliberately not a native disabled
    // attribute (so a click can still explain why via a popup), which means
    // a determined client could still POST here directly; never trust that
    // gate alone.
    if ($batteryLevel >= CHARGE_ALERT_THRESHOLD_PCT) {
        echo json_encode(['ok' => false, 'error' => t('battery_alert.battery_not_low_enough', ['pct' => CHARGE_ALERT_THRESHOLD_PCT])]);
        exit;
    }

    // Dedup guard: a well-meaning admin re-clicking (or reopening the popup
    // later while the volunteer still hasn't acknowledged) reuses the still-
    // open order instead of spamming a second beep+banner at the same
    // volunteer. Once acknowledged, a fresh click creates a genuinely new
    // order — command can still re-nag if the battery is still low later.
    $pendingOrderId = dbFetchValue(
        "SELECT o.id FROM mission_orders o
         JOIN mission_order_recipients r ON r.order_id = o.id
         WHERE o.mission_id = ? AND o.order_type = 'charge_phone' AND r.user_id = ? AND r.acknowledged_at IS NULL
         ORDER BY o.id DESC LIMIT 1",
        [$missionId, $targetUserId]
    );
    if ($pendingOrderId) {
        echo json_encode(['ok' => true]);
        exit;
    }

    $orderId = createMissionOrderAndNotify(
        $missionId, $mission['title'], 'charge_phone', $userId, [$targetUserId],
        'order.charge_phone.title', [],
        null, 'order.charge_phone.message', ['mission' => $mission['title'], 'pct' => $batteryLevel],
        t('battery_alert.history_note', ['pct' => $batteryLevel])
    );
    logAudit('send_battery_alert', 'mission_orders', $orderId, null, ['mission_id' => $missionId, 'recipient_id' => $targetUserId, 'battery_level' => $batteryLevel]);

    echo json_encode(['ok' => true]);
    exit;
}

echo json_encode(['ok' => false, 'error' => t('common.unknown_action')]);
