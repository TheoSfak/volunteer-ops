<?php
/**
 * VolunteerOps - Volunteer Field Status Endpoint
 * Εθελοντής ενημερώνει κατάσταση: Σε Κίνηση / Επί Τόπου / Χρειάζεται Βοήθεια.
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
$prId    = (int) post('pr_id');
$status  = post('status');

$allowedStatuses = ['on_way', 'on_site', 'needs_help'];
if (!in_array($status, $allowedStatuses, true)) {
    echo json_encode(['ok' => false, 'error' => 'Μη έγκυρη κατάσταση']);
    exit;
}

// Verify the PR belongs to this user and is APPROVED
$pr = dbFetchOne(
    "SELECT pr.id, pr.shift_id, m.title as mission_title, m.id as mission_id
     FROM participation_requests pr
     JOIN shifts s ON pr.shift_id = s.id
     JOIN missions m ON s.mission_id = m.id
     WHERE pr.id = ? AND pr.volunteer_id = ? AND pr.status = '" . PARTICIPATION_APPROVED . "'",
    [$prId, $userId]
);

if (!$pr) {
    echo json_encode(['ok' => false, 'error' => 'Δεν βρέθηκε η αίτηση']);
    exit;
}

// Update field status
try {
    dbExecute(
        "UPDATE participation_requests SET field_status = ?, field_status_updated_at = NOW() WHERE id = ?",
        [$status, $prId]
    );
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => 'Η λειτουργία status δεν είναι διαθέσιμη ακόμη (χρειάζεται migration βάσης).']);
    exit;
}

// If needs_help → notify all admins + shift leader
if ($status === 'needs_help') {
    $currentUser = getCurrentUser();
    $notifyTitle   = '🆘 Χρειάζεται Βοήθεια!';
    $notifyMessage = h($currentUser['name']) . ' χρειάζεται βοήθεια στην αποστολή «' . $pr['mission_title'] . '». Ανοίξτε το Επιχ. Dashboard.';

    // Notify all system/department admins
    $admins = dbFetchAll(
        "SELECT id FROM users WHERE role IN ('" . ROLE_SYSTEM_ADMIN . "', '" . ROLE_DEPARTMENT_ADMIN . "') AND is_active = 1",
        []
    );
    foreach ($admins as $admin) {
        sendNotification($admin['id'], $notifyTitle, $notifyMessage, 'danger');
    }

    // Also notify shift leaders approved for same mission
    $leaders = dbFetchAll(
        "SELECT DISTINCT u.id FROM users u
         JOIN participation_requests pr2 ON pr2.volunteer_id = u.id
         JOIN shifts s ON pr2.shift_id = s.id
         WHERE s.mission_id = ? AND u.role = '" . ROLE_SHIFT_LEADER . "' AND u.is_active = 1 AND pr2.status = '" . PARTICIPATION_APPROVED . "'",
        [$pr['mission_id']]
    );
    foreach ($leaders as $leader) {
        sendNotification($leader['id'], $notifyTitle, $notifyMessage, 'danger');
    }

    logAudit('needs_help', 'participation_requests', $prId);
}

$labels = [
    'on_way'      => '🚗 Σε Κίνηση',
    'on_site'     => '✅ Επί Τόπου',
    'needs_help'  => '🆘 Χρειάζεται Βοήθεια',
];

echo json_encode(['ok' => true, 'label' => $labels[$status], 'status' => $status, 'new_csrf' => $newCsrf]);
