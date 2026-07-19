<?php
/**
 * VolunteerOps - Mission Order Acknowledgment Endpoint
 * War Room: a recipient of a location/photo/video request marks it "Ελήφθη"
 * (received). Fulfillment itself is never posted here — it's stamped
 * automatically by ping-location.php / mission-photo.php when the recipient
 * actually responds. POST only, AJAX.
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
    echo json_encode(['ok' => false, 'error' => 'Μη έγκυρο αίτημα. Ανανεώστε τη σελίδα.']);
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
        echo json_encode(['ok' => false, 'error' => 'Δεν βρέθηκε αίτημα για εσάς.']);
        exit;
    }

    if (!$recipient['acknowledged_at']) {
        dbExecute("UPDATE mission_order_recipients SET acknowledged_at = NOW() WHERE id = ?", [$recipient['id']]);
        logAudit('acknowledge_mission_order', 'mission_order_recipients', $recipient['id'], null, ['order_id' => $orderId]);
    }

    echo json_encode(['ok' => true]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Άγνωστη ενέργεια.']);
