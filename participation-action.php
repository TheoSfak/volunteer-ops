<?php
/**
 * VolunteerOps - Quick Participation Actions
 * Handle quick approve/reject actions from dashboard
 * Uses POST + CSRF for security
 */

require_once __DIR__ . '/bootstrap.php';
requireRole([ROLE_SYSTEM_ADMIN, ROLE_DEPARTMENT_ADMIN]);

if (!isPost()) {
    setFlash('error', 'Μη έγκυρη ενέργεια.');
    redirect('dashboard.php');
}

verifyCsrf();

$id = post('id');
$action = post('action');

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

try {
    if ($action === 'approve') {
        // Use transaction with row lock to prevent race condition (over-approval)
        db()->beginTransaction();
        try {
            // Lock approved rows for this shift to get accurate count
            $currentCount = dbFetchValue(
                "SELECT COUNT(*) FROM participation_requests 
                 WHERE shift_id = ? AND status = ? FOR UPDATE",
                [$request['shift_id'], PARTICIPATION_APPROVED]
            );
            
            $maxVolunteers = dbFetchValue(
                "SELECT max_volunteers FROM shifts WHERE id = ?",
                [$request['shift_id']]
            );
            
            if ($currentCount >= $maxVolunteers) {
                db()->rollBack();
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
            db()->commit();
        } catch (Exception $txEx) {
            db()->rollBack();
            throw $txEx;
        }
        
        // Send notification
        if (isNotificationEnabled('participation_approved')) {
            $volunteer = dbFetchOne("SELECT name, email FROM users WHERE id = ?", [$request['volunteer_id']]);
            
            sendNotificationEmail('participation_approved', $volunteer['email'], [
                'user_name' => $volunteer['name'],
                'mission_title' => $request['mission_title'],
                'shift_date' => formatDateTime($request['start_time'], 'd/m/Y'),
                'shift_time' => formatDateTime($request['start_time'], 'H:i'),
                'location' => $request['location'] ?: 'Θα ανακοινωθεί'
            ]);
            
            sendNotification(
                $request['volunteer_id'],
                'Η αίτησή σας εγκρίθηκε',
                'Η αίτησή σας για τη βάρδια "' . $request['mission_title'] . '" στις ' . 
                formatDateTime($request['start_time']) . ' εγκρίθηκε.'
            );
        }
        
        logAudit('approve_participation', 'participation_requests', $id);
        setFlash('success', 'Η αίτηση εγκρίθηκε επιτυχώς.');
        
    } else if ($action === 'reject') {
        dbExecute(
            "UPDATE participation_requests 
             SET status = ?, decided_by = ?, decided_at = NOW(), rejection_reason = ? 
             WHERE id = ?",
            [PARTICIPATION_REJECTED, getCurrentUserId(), 'Η αίτηση απορρίφθηκε από τον διαχειριστή', $id]
        );
        
        if (isNotificationEnabled('participation_rejected')) {
            $volunteer = dbFetchOne("SELECT name, email FROM users WHERE id = ?", [$request['volunteer_id']]);
            
            sendNotificationEmail('participation_rejected', $volunteer['email'], [
                'user_name' => $volunteer['name'],
                'mission_title' => $request['mission_title'],
                'shift_date' => formatDateTime($request['start_time'], 'd/m/Y'),
                'rejection_reason' => 'Η αίτηση απορρίφθηκε από τον διαχειριστή'
            ]);
            
            sendNotification(
                $request['volunteer_id'],
                'Η αίτησή σας απορρίφθηκε',
                'Η αίτησή σας για τη βάρδια "' . $request['mission_title'] . '" στις ' . 
                formatDateTime($request['start_time']) . ' απορρίφθηκε. Λόγος: Η αίτηση απορρίφθηκε από τον διαχειριστή'
            );
        }
        
        logAudit('reject_participation', 'participation_requests', $id);
        setFlash('warning', 'Η αίτηση απορρίφθηκε.');
    }
} catch (Exception $e) {
    error_log('Participation action error: ' . $e->getMessage());
    setFlash('error', 'Παρουσιάστηκε σφάλμα. Παρακαλώ δοκιμάστε ξανά.');
}

redirect('dashboard.php');
