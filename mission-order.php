<?php
/**
 * VolunteerOps - Mission Order Acknowledgment Endpoint
 * War Room: a recipient of a location/photo/video request marks it "Ελήφθη"
 * (received). Fulfillment itself is never posted here — it's stamped
 * automatically by ping-location.php / mission-photo.php when the recipient
 * actually responds. POST only, AJAX.
 *
 * Exception: task orders (order_type='task') have no automatic fulfillment
 * signal — there's no real-world action to detect — so the `complete` action
 * below is the recipient manually self-reporting "done" for that one type only.
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();

header('Content-Type: application/json');

$userId = getCurrentUserId();

if (!isPost()) {
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
    echo json_encode(['ok' => false, 'error' => t('common.invalid_request')]);
    exit;
}

$action = post('action');

if ($action === 'acknowledge') {
    $orderId = (int) post('order_id');

    $recipient = dbFetchOne(
        "SELECT id, acknowledged_at FROM mission_order_recipients WHERE order_id = ? AND user_id = ?",
        [$orderId, $userId]
    );
    if (!$recipient) {
        echo json_encode(['ok' => false, 'error' => t('order.no_request_for_you')]);
        exit;
    }

    if (!$recipient['acknowledged_at']) {
        dbExecute("UPDATE mission_order_recipients SET acknowledged_at = NOW() WHERE id = ?", [$recipient['id']]);
        logAudit('acknowledge_mission_order', 'mission_order_recipients', $recipient['id'], null, ['order_id' => $orderId]);
    }

    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'complete') {
    $orderId = (int) post('order_id');

    $recipient = dbFetchOne(
        "SELECT r.id, r.fulfilled_at, o.order_type
         FROM mission_order_recipients r
         JOIN mission_orders o ON o.id = r.order_id
         WHERE r.order_id = ? AND r.user_id = ?",
        [$orderId, $userId]
    );
    if (!$recipient) {
        echo json_encode(['ok' => false, 'error' => t('order.no_request_for_you')]);
        exit;
    }
    if ($recipient['order_type'] !== 'task') {
        echo json_encode(['ok' => false, 'error' => t('order.complete_not_supported')]);
        exit;
    }

    if (!$recipient['fulfilled_at']) {
        dbExecute(
            "UPDATE mission_order_recipients SET acknowledged_at = COALESCE(acknowledged_at, NOW()), fulfilled_at = NOW() WHERE id = ?",
            [$recipient['id']]
        );
        logAudit('complete_mission_order', 'mission_order_recipients', $recipient['id'], null, ['order_id' => $orderId]);
    }

    echo json_encode(['ok' => true]);
    exit;
}

echo json_encode(['ok' => false, 'error' => t('common.unknown_action')]);
