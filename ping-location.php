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

verifyCsrf();

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

echo json_encode(['ok' => true, 'ts' => date('H:i:s')]);
