<?php
/**
 * VolunteerOps - Announcement Dismiss API
 * AJAX endpoint: mark one or more pending announcements as dismissed for the current user.
 */

require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$userId = (int) getCurrentUserId();
$input = json_decode(file_get_contents('php://input'), true) ?: [];

$sessionToken = $_SESSION['csrf_token'] ?? '';
$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf_token'] ?? '');
if ($sessionToken === '' || !hash_equals($sessionToken, (string) $csrfToken)) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid CSRF token']);
    exit;
}

// footer.php already recorded what it showed, at the moment it showed it
// (v3.396.0), so this only repeats that for the ids on the button the user
// pressed. Kept because it is harmless and idempotent, not because anything
// depends on it arriving.
markAnnouncementsDismissed($userId, $input['ids'] ?? []);

echo json_encode(['success' => true]);
