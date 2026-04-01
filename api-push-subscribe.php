<?php
/**
 * VolunteerOps - Push Subscription API
 * AJAX endpoint for managing push notification subscriptions
 * 
 * POST: Subscribe (save endpoint + keys)
 * DELETE: Unsubscribe (remove endpoint)
 */

require_once __DIR__ . '/bootstrap.php';

// Must be logged in
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

$userId = (int) getCurrentUserId();
$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

if ($method === 'POST' && ($_GET['action'] ?? '') === 'test') {
    // ── Send Test Push ──
    $count = sendPushToUser($userId, 'Δοκιμαστική Ειδοποίηση', 'Οι push ειδοποιήσεις λειτουργούν σωστά!', [
        'url' => 'notification-preferences.php',
        'tag' => 'vo-test-' . time()
    ]);
    echo json_encode(['success' => $count > 0, 'sent' => $count,
        'message' => $count > 0 ? 'Εστάλη σε ' . $count . ' συσκευή/ές' : 'Δεν βρέθηκε ενεργή συνδρομή push']);
    exit;
}

if ($method === 'POST') {
    // ── Subscribe ──
    $endpoint = $input['endpoint'] ?? '';
    $p256dh   = $input['keys']['p256dh'] ?? '';
    $auth     = $input['keys']['auth'] ?? '';

    if (!$endpoint || !$p256dh || !$auth) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing subscription data']);
        exit;
    }

    // Validate endpoint URL
    if (!filter_var($endpoint, FILTER_VALIDATE_URL)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid endpoint URL']);
        exit;
    }

    // Check if this exact subscription already exists
    $existing = dbFetchOne(
        "SELECT id FROM push_subscriptions WHERE user_id = ? AND endpoint = ?",
        [$userId, $endpoint]
    );

    if ($existing) {
        // Update existing subscription (keys might have changed)
        dbExecute(
            "UPDATE push_subscriptions SET p256dh_key = ?, auth_key = ?, user_agent = ?, updated_at = NOW() WHERE id = ?",
            [$p256dh, $auth, $_SERVER['HTTP_USER_AGENT'] ?? '', $existing['id']]
        );
    } else {
        // Create new subscription
        dbInsert(
            "INSERT INTO push_subscriptions (user_id, endpoint, p256dh_key, auth_key, user_agent) VALUES (?, ?, ?, ?, ?)",
            [$userId, $endpoint, $p256dh, $auth, $_SERVER['HTTP_USER_AGENT'] ?? '']
        );
    }

    echo json_encode(['success' => true, 'message' => 'Subscription saved']);
    exit;
}

if ($method === 'DELETE') {
    // ── Unsubscribe ──
    $endpoint = $input['endpoint'] ?? '';

    if ($endpoint) {
        dbExecute(
            "DELETE FROM push_subscriptions WHERE user_id = ? AND endpoint = ?",
            [$userId, $endpoint]
        );
    } else {
        // Remove all subscriptions for this user
        dbExecute("DELETE FROM push_subscriptions WHERE user_id = ?", [$userId]);
    }

    echo json_encode(['success' => true, 'message' => 'Unsubscribed']);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
