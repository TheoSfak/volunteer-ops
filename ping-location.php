<?php
/**
 * VolunteerOps - Volunteer GPS Ping Endpoint
 * Εθελοντής στέλνει θέση GPS κατά τη διάρκεια βάρδιας.
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
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
    echo json_encode(['ok' => false, 'error' => 'Μη έγκυρο αίτημα. Ανανεώστε τη σελίδα.']);
    exit;
}
// Rotate token and return new one so JS stays in sync
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$newCsrf = $_SESSION['csrf_token'];

$userId  = getCurrentUserId();
$shiftId = (int) post('shift_id');
$lat     = (float) post('lat');
$lng     = (float) post('lng');

// Validate coordinates
if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || ($lat == 0 && $lng == 0)) {
    echo json_encode(['ok' => false, 'error' => 'Μη έγκυρες συντεταγμένες']);
    exit;
}

// Verify user has an APPROVED participation for this shift
$pr = dbFetchOne(
    "SELECT pr.id FROM participation_requests pr
     JOIN shifts s ON pr.shift_id = s.id
     WHERE pr.shift_id = ? AND pr.volunteer_id = ? AND pr.status = '" . PARTICIPATION_APPROVED . "'",
    [$shiftId, $userId]
);

if (!$pr) {
    echo json_encode(['ok' => false, 'error' => 'Δεν έχετε εγκεκριμένη συμμετοχή σε αυτή τη βάρδια']);
    exit;
}

// Insert ping
try {
    dbInsert(
        "INSERT INTO volunteer_pings (user_id, shift_id, lat, lng, created_at) VALUES (?, ?, ?, ?, NOW())",
        [$userId, $shiftId, $lat, $lng]
    );
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => 'Η λειτουργία GPS δεν είναι διαθέσιμη ακόμη (χρειάζεται migration βάσης).']);
    exit;
}

echo json_encode(['ok' => true, 'ts' => date('H:i:s'), 'new_csrf' => $newCsrf]);
