<?php
/**
 * VolunteerOps - Quick Participation Actions
 * Handle quick approve/reject actions from dashboard
 */

// Enable error reporting for debugging
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Log to file for debugging
$logFile = __DIR__ . '/participation_action_debug.log';
file_put_contents($logFile, date('Y-m-d H:i:s') . " - Request started\n", FILE_APPEND);
file_put_contents($logFile, "ID: " . ($_GET['id'] ?? 'none') . ", Action: " . ($_GET['action'] ?? 'none') . "\n", FILE_APPEND);

try {
    require_once __DIR__ . '/bootstrap.php';
    file_put_contents($logFile, "Bootstrap loaded\n", FILE_APPEND);
    
    requireRole([ROLE_SYSTEM_ADMIN, ROLE_DEPARTMENT_ADMIN]);
    file_put_contents($logFile, "Role check passed\n", FILE_APPEND);

$id = get('id');
$action = get('action');

if (!$id || !in_array($action, ['approve', 'reject'])) {
    setFlash('error', 'Μη έγκυρη ενέργεια.');
    redirect('dashboard.php');
}

$request = dbFetchOne(
    "SELECT pr.*, u.name as volunteer_name, u.email as volunteer_email, 
            s.start_time, s.end_time, m.title as mission_title, m.department_id, m.location
     FROM participation_requests pr
     JOIN users u ON pr.volunteer_id = u.id
     JOIN shifts s ON pr.shift_id = s.id
     JOIN missions m ON s.mission_id = m.id
     WHERE pr.id = ?",
    [$id]
);

if (!$request) {
    setFlash('error', 'Δεν βρέθηκε η αίτηση.');
    redirect('dashboard.php');
}

// Check department access for department admins
$currentUser = getCurrentUser();
if ($currentUser['role'] === ROLE_DEPARTMENT_ADMIN && $currentUser['department_id']) {
    if ($request['department_id'] != $currentUser['department_id']) {
        setFlash('error', 'Δεν έχετε δικαίωμα πρόσβασης σε αυτή την αίτηση.');
        redirect('dashboard.php');
    }
}

if ($request['status'] !== PARTICIPATION_PENDING) {
    setFlash('error', 'Η αίτηση έχει ήδη επεξεργαστεί.');
    redirect('dashboard.php');
}

if ($action === 'approve') {
    // Check if shift is full
    $currentCount = dbFetchValue(
        "SELECT COUNT(*) FROM participation_requests 
         WHERE shift_id = ? AND status = ?",
        [$request['shift_id'], PARTICIPATION_APPROVED]
    );
    
    $maxVolunteers = dbFetchValue(
        "SELECT max_volunteers FROM shifts WHERE id = ?",
        [$request['shift_id']]
    );
    
    if ($currentCount >= $maxVolunteers) {
        setFlash('error', 'Η βάρδια είναι πλήρης.');
        redirect('dashboard.php');
    }
    
    // Approve the request
    dbExecute(
        "UPDATE participation_requests 
         SET status = ?, decided_by = ?, decided_at = NOW() 
         WHERE id = ?",
        [PARTICIPATION_APPROVED, getCurrentUserId(), $id]
    );
    
    // Send notification
    if (isNotificationEnabled('participation_approved')) {
        // Get volunteer info
        $volunteer = dbFetchOne("SELECT name, email FROM users WHERE id = ?", [$request['volunteer_id']]);
        
        // Send email
        sendNotificationEmail('participation_approved', $volunteer['email'], [
            'user_name' => $volunteer['name'],
            'mission_title' => $request['mission_title'],
            'shift_date' => formatDateTime($request['start_time'], 'd/m/Y'),
            'shift_time' => formatDateTime($request['start_time'], 'H:i'),
            'location' => $request['location'] ?: 'Θα ανακοινωθεί'
        ]);
        
        // Send in-app notification
        sendNotification(
            $request['volunteer_id'],
            'Η αίτησή σας εγκρίθηκε',
            'Η αίτησή σας για τη βάρδια "' . $request['mission_title'] . '" στις ' . 
            formatDateTime($request['start_time']) . ' εγκρίθηκε.'
        );
        file_put_contents($logFile, "Notification sent\n", FILE_APPEND);
    }
    
    // Log audit
    logAudit('approve_participation', 'participation_requests', $id);
    file_put_contents($logFile, "Audit logged\n", FILE_APPEND);
    
    setFlash('success', 'Η αίτηση εγκρίθηκε επιτυχώς.');
    
} else if ($action === 'reject') {
    // For quick rejection from dashboard, we'll use a generic reason
    dbExecute(
        "UPDATE participation_requests 
         SET status = ?, decided_by = ?, decided_at = NOW(), rejection_reason = ? 
         WHERE id = ?",
        [PARTICIPATION_REJECTED, getCurrentUserId(), 'Η αίτηση απορρίφθηκε από τον διαχειριστή', $id]
    );
    
    // Send notification
    if (isNotificationEnabled('participation_rejected')) {
        // Get volunteer info
        $volunteer = dbFetchOne("SELECT name, email FROM users WHERE id = ?", [$request['volunteer_id']]);
        
        // Send email
        sendNotificationEmail('participation_rejected', $volunteer['email'], [
            'user_name' => $volunteer['name'],
            'mission_title' => $request['mission_title'],
            'shift_date' => formatDateTime($request['start_time'], 'd/m/Y'),
            'rejection_reason' => 'Η αίτηση απορρίφθηκε από τον διαχειριστή'
        ]);
        
        // Send in-app notification
        sendNotification(
            $request['volunteer_id'],
            'Η αίτησή σας απορρίφθηκε',
            'Η αίτησή σας για τη βάρδια "' . $request['mission_title'] . '" στις ' . 
            formatDateTime($request['start_time']) . ' απορρίφθηκε. Λόγος: Η αίτηση απορρίφθηκε από τον διαχειριστή'
        );
    }
    
    // Log audit
    logAudit('reject_participation', 'participation_requests', $id);
    
    setFlash('warning', 'Η αίτηση απορρίφθηκε.');
}

file_put_contents($logFile, "Redirecting to dashboard\n", FILE_APPEND);
redirect('dashboard.php');

} catch (Exception $e) {
    file_put_contents($logFile, "ERROR: " . $e->getMessage() . "\n", FILE_APPEND);
    file_put_contents($logFile, "Stack trace: " . $e->getTraceAsString() . "\n", FILE_APPEND);
    die("Error: " . $e->getMessage());
}
