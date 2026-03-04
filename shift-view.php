<?php
/**
 * VolunteerOps - Shift View & Manage Participants
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();

$id = (int) get('id');
if (!$id) {
    redirect('shifts.php');
}

$shift = dbFetchOne(
    "SELECT s.*, m.title as mission_title, m.status as mission_status, m.department_id,
            m.description, m.location, m.end_datetime as mission_end_datetime,
            m.mission_type_id, m.responsible_user_id
     FROM shifts s
     JOIN missions m ON s.mission_id = m.id
     WHERE s.id = ?",
    [$id]
);

if (!$shift) {
    setFlash('error', 'Η βάρδια δεν βρέθηκε.');
    redirect('shifts.php');
}

$pageTitle = $shift['mission_title'] . ' - ' . formatDateTime($shift['start_time']);
$user = getCurrentUser();
$missionCompleted = ($shift['mission_status'] === STATUS_COMPLETED);

// Τ.Ε.Π.: αποκλεισμός βάρδιας Τ.Ε.Π. για μη-εξουσιοδοτημένους
$missionTypeId = (int) $shift['mission_type_id'];
$missionResponsible = (int) $shift['responsible_user_id'];
if (isTepMission($missionTypeId) && !canSeeTep($missionResponsible)) {
    setFlash('error', 'Δεν έχετε πρόσβαση σε βάρδιες αποστολών Τ.Ε.Π.');
    redirect('missions.php');
}
$canManage = (isAdmin() || hasRole(ROLE_SHIFT_LEADER)) && !$missionCompleted;

// Get participants
$participants = dbFetchAll(
    "SELECT pr.*, u.name, u.email, u.phone, u.volunteer_type
     FROM participation_requests pr
     JOIN users u ON pr.volunteer_id = u.id
     WHERE pr.shift_id = ?
     ORDER BY pr.status ASC, pr.created_at ASC",
    [$id]
);

// Check if current user has applied
$myParticipation = null;
if (!isAdmin()) {
    foreach ($participants as $p) {
        if ($p['volunteer_id'] == $user['id']) {
            $myParticipation = $p;
            break;
        }
    }
}

// Handle actions
if (isPost()) {
    verifyCsrf();
    $action = post('action');
    $prId = post('participation_id');

    // Block all management actions when mission is COMPLETED
    $adminActions = ['approve', 'reject', 'reactivate', 'mark_attended', 'delete', 'add_volunteer', 'update_notes', 'approve_swap', 'reject_swap'];
    if ($missionCompleted && in_array($action, $adminActions)) {
        setFlash('error', 'Η αποστολή είναι ολοκληρωμένη. Αλλάξτε πρώτα την κατάσταση σε «Κλειστή» για να κάνετε αλλαγές.');
        redirect('shift-view.php?id=' . $id);
    }

    switch ($action) {
        case 'apply':
            $missionExpired = in_array($shift['mission_status'], [STATUS_OPEN, STATUS_CLOSED]) && strtotime($shift['mission_end_datetime']) < time();
            if ($missionExpired && !isAdmin()) {
                setFlash('error', 'Η αποστολή είναι ακόμα ανοιχτή αλλά ο χρόνος διεξαγωγής έχει παρέλθει. Δεν μπορείτε να υποβάλετε αίτηση.');
            } elseif ($shift['mission_status'] !== STATUS_OPEN) {
                setFlash('error', 'Η αποστολή δεν δέχεται αιτήσεις.');
            } elseif ($myParticipation) {
                setFlash('error', 'Έχετε ήδη υποβάλει αίτηση.');
            } else {
                dbInsert(
                    "INSERT INTO participation_requests 
                     (shift_id, volunteer_id, status, notes, created_at, updated_at)
                     VALUES (?, ?, ?, ?, NOW(), NOW())",
                    [$id, $user['id'], PARTICIPATION_PENDING, post('notes')]
                );
                logAudit('apply', 'participation_requests', null, "Shift $id");
                setFlash('success', 'Η αίτησή σας υποβλήθηκε.');
            }
            break;
            
        case 'cancel':
            if ($myParticipation && $myParticipation['status'] === PARTICIPATION_PENDING) {
                dbExecute(
                    "UPDATE participation_requests SET status = '" . PARTICIPATION_CANCELED_BY_USER . "', updated_at = NOW() WHERE id = ?",
                    [$myParticipation['id']]
                );
                logAudit('cancel', 'participation_requests', $myParticipation['id']);
                setFlash('success', 'Η αίτησή σας ακυρώθηκε.');
            }
            break;
            
        case 'approve':
            if ($canManage) {
                // Get participation and volunteer info for notification
                $prInfo = dbFetchOne(
                    "SELECT pr.volunteer_id, u.name, u.email 
                     FROM participation_requests pr 
                     JOIN users u ON pr.volunteer_id = u.id 
                     WHERE pr.id = ?",
                    [$prId]
                );
                
                dbExecute(
                    "UPDATE participation_requests SET status = ?, decided_by = ?, decided_at = NOW(), updated_at = NOW() WHERE id = ?",
                    [PARTICIPATION_APPROVED, $user['id'], $prId]
                );
                
                // Send notification
                if ($prInfo && isNotificationEnabled('participation_approved')) {
                    $gcalLink = buildGcalLink($shift['mission_title'], $shift['start_time'], $shift['end_time'], $shift['location'] ?: '');
                    // Send email
                    sendNotificationEmail('participation_approved', $prInfo['email'], [
                        'user_name'     => $prInfo['name'],
                        'mission_title' => $shift['mission_title'],
                        'shift_date'    => formatDateTime($shift['start_time'], 'd/m/Y'),
                        'shift_time'    => formatDateTime($shift['start_time'], 'H:i'),
                        'location'      => $shift['location'] ?: 'Θα ανακοινωθεί',
                        'gcal_link'     => $gcalLink,
                    ]);
                    
                    // Send in-app notification
                    sendNotification(
                        $prInfo['volunteer_id'],
                        'Η αίτησή σας εγκρίθηκε',
                        'Η αίτησή σας για τη βάρδια "' . $shift['mission_title'] . '" στις ' . 
                        formatDateTime($shift['start_time']) . ' εγκρίθηκε.'
                    );
                }
                
                logAudit('approve', 'participation_requests', $prId);
                setFlash('success', 'Η αίτηση εγκρίθηκε.');
            }
            break;
            
        case 'reject':
            if ($canManage) {
                $reason = post('reason');
                
                // Get volunteer info for notification
                $prInfo = dbFetchOne(
                    "SELECT pr.volunteer_id, u.name FROM participation_requests pr JOIN users u ON pr.volunteer_id = u.id WHERE pr.id = ?",
                    [$prId]
                );
                
                dbExecute(
                    "UPDATE participation_requests SET status = '" . PARTICIPATION_REJECTED . "', rejection_reason = ?, decided_by = ?, decided_at = NOW(), updated_at = NOW() WHERE id = ?",
                    [$reason, $user['id'], $prId]
                );
                
                // Send notification to volunteer
                if ($prInfo && isNotificationEnabled('participation_rejected')) {
                    // Get volunteer email
                    $volunteer = dbFetchOne("SELECT name, email FROM users WHERE id = ?", [$prInfo['volunteer_id']]);
                    
                    // Send email
                    sendNotificationEmail('participation_rejected', $volunteer['email'], [
                        'user_name' => $volunteer['name'],
                        'mission_title' => $shift['mission_title'],
                        'shift_date' => formatDateTime($shift['start_time'], 'd/m/Y'),
                        'rejection_reason' => $reason ?: 'Δεν αναφέρθηκε λόγος'
                    ]);
                    
                    // Send in-app notification
                    $message = 'Η αίτηση συμμετοχής σας στη βάρδια "' . $shift['mission_title'] . '" (' . formatDateTime($shift['start_time']) . ') απορρίφθηκε.';
                    if ($reason) {
                        $message .= ' Αιτία: ' . $reason;
                    }
                    dbInsert(
                        "INSERT INTO notifications (user_id, type, title, message, data, created_at) VALUES (?, 'participation_rejected', ?, ?, ?, NOW())",
                        [
                            $prInfo['volunteer_id'],
                            'Απόρριψη Αίτησης',
                            $message,
                            json_encode(['shift_id' => $id, 'reason' => $reason])
                        ]
                    );
                }
                
                logAudit('reject', 'participation_requests', $prId, $reason);
                setFlash('success', 'Η αίτηση απορρίφθηκε και ο εθελοντής ειδοποιήθηκε.');
            }
            break;

        case 'reactivate':
            if ($canManage) {
                $pr = dbFetchOne(
                    "SELECT pr.*, u.name, u.email FROM participation_requests pr JOIN users u ON pr.volunteer_id = u.id WHERE pr.id = ?",
                    [$prId]
                );

                if (!$pr) {
                    setFlash('error', 'Δεν βρέθηκε η αίτηση.');
                } else {
                    dbExecute(
                        "UPDATE participation_requests SET status = ?, rejection_reason = NULL, decided_by = ?, decided_at = NOW(), updated_at = NOW() WHERE id = ?",
                        [PARTICIPATION_APPROVED, $user['id'], $prId]
                    );

                    // Send email notification (reuse participation_approved template)
                    if (isNotificationEnabled('participation_approved')) {
                        $gcalLink = buildGcalLink($shift['mission_title'], $shift['start_time'], $shift['end_time'], $shift['location'] ?: '');
                        sendNotificationEmail('participation_approved', $pr['email'], [
                            'user_name'     => $pr['name'],
                            'mission_title' => $shift['mission_title'],
                            'shift_date'    => formatDateTime($shift['start_time'], 'd/m/Y'),
                            'shift_time'    => formatDateTime($shift['start_time'], 'H:i'),
                            'location'      => $shift['location'] ?: 'Θα ανακοινωθεί',
                            'gcal_link'     => $gcalLink,
                        ]);
                    }

                    // In-app notification
                    sendNotification(
                        (int) $pr['volunteer_id'],
                        'Επανενεργοποίηση Συμμετοχής',
                        'Η συμμετοχή σας στη βάρδια "' . $shift['mission_title'] . '" (' . formatDateTime($shift['start_time']) . ') επανενεργοποιήθηκε και είναι πλέον εγκεκριμένη.'
                    );

                    logAudit('reactivate', 'participation_requests', $prId, "Volunteer: {$pr['volunteer_id']}");
                    setFlash('success', 'Ο εθελοντής επανενεργοποιήθηκε και ειδοποιήθηκε.');
                }
            }
            break;
            
        case 'mark_attended':
            if ($canManage) {
                $actualHours = (float) post('actual_hours');

                db()->beginTransaction();
                try {
                    dbExecute(
                        "UPDATE participation_requests SET attended = 1, actual_hours = ?, updated_at = NOW() WHERE id = ?",
                        [$actualHours, $prId]
                    );

                    // Award points
                    $pr = dbFetchOne("SELECT * FROM participation_requests WHERE id = ?", [$prId]);
                    if ($pr) {
                        $hours = $actualHours ?: calculateShiftHours($shift);
                        $points = calculatePoints($shift, $hours);

                        dbInsert(
                            "INSERT INTO volunteer_points 
                             (user_id, points, reason, description, pointable_type, pointable_id, created_at)
                             VALUES (?, ?, ?, ?, 'App\\\\Models\\\\Shift', ?, NOW())",
                            [$pr['volunteer_id'], $points, 'shift_attendance', "Βάρδια: " . $shift['mission_title'], $id]
                        );

                        dbExecute(
                            "UPDATE users SET total_points = total_points + ? WHERE id = ?",
                            [$points, $pr['volunteer_id']]
                        );

                        logAudit('mark_attended', 'participation_requests', $prId, "Points: $points");
                    }
                    db()->commit();

                    // Check and award any newly earned achievements for the volunteer
                    if ($pr) {
                        checkAndAwardAchievements((int)$pr['volunteer_id']);
                    }

                    setFlash('success', 'Η παρουσία καταγράφηκε και δόθηκαν πόντοι.');
                } catch (Exception $e) {
                    db()->rollBack();
                    setFlash('error', 'Σφάλμα κατά την καταγραφή παρουσίας. Παρακαλώ δοκιμάστε ξανά.');
                }
            }
            break;
            
        case 'delete':
            if ($canManage) {
                // Get all participants to notify them
                $affectedParticipants = dbFetchAll(
                    "SELECT pr.*, u.name, u.email 
                     FROM participation_requests pr 
                     JOIN users u ON pr.volunteer_id = u.id 
                     WHERE pr.shift_id = ? AND pr.status IN (?, ?)",
                    [$id, PARTICIPATION_PENDING, PARTICIPATION_APPROVED]
                );
                
                db()->beginTransaction();
                try {
                    // Send bulk notification to affected volunteers
                    if (isNotificationEnabled('mission_cancelled') && !empty($affectedParticipants)) {
                        $userIds = array_column($affectedParticipants, 'volunteer_id');
                        sendBulkNotifications(
                            $userIds,
                            'Ακύρωση Βάρδιας',
                            'Η βάρδια στην αποστολή "' . $shift['mission_title'] . '" (' . formatDateTime($shift['start_time']) . ') διαγράφηκε. Η αίτησή σας ακυρώθηκε αυτόματα.'
                        );
                    }
                    
                    // Delete participation requests first
                    dbExecute("DELETE FROM participation_requests WHERE shift_id = ?", [$id]);
                    
                    // Delete the shift
                    dbExecute("DELETE FROM shifts WHERE id = ?", [$id]);
                    logAudit('delete', 'shifts', $id, 'Notified ' . count($affectedParticipants) . ' volunteers');
                    
                    db()->commit();
                    
                    $msg = 'Η βάρδια διαγράφηκε.';
                    if (count($affectedParticipants) > 0) {
                        $msg .= ' Ειδοποιήθηκαν ' . count($affectedParticipants) . ' εθελοντές.';
                    }
                    setFlash('success', $msg);
                } catch (Exception $e) {
                    db()->rollBack();
                    setFlash('error', 'Σφάλμα κατά τη διαγραφή της βάρδιας.');
                }
                redirect('mission-view.php?id=' . $shift['mission_id']);
            }
            break;
            
        case 'add_volunteer':
            if ($canManage) {
                $volunteerId = post('volunteer_id');
                $notes = post('admin_notes');
                
                // Check if already in this shift (any status)
                $exists = dbFetchOne(
                    "SELECT id, status FROM participation_requests WHERE shift_id = ? AND volunteer_id = ?",
                    [$id, $volunteerId]
                );
                
                if ($exists && in_array($exists['status'], [PARTICIPATION_APPROVED, PARTICIPATION_PENDING])) {
                    // Already active — block
                    setFlash('error', 'Ο εθελοντής έχει ήδη ενεργή αίτηση σε αυτή τη βάρδια.');
                } else {
                    if ($exists) {
                        // Reactivate existing rejected/canceled record
                        dbExecute(
                            "UPDATE participation_requests 
                             SET status = ?, rejection_reason = NULL, admin_notes = ?, 
                                 decided_by = ?, decided_at = NOW(), updated_at = NOW() 
                             WHERE id = ?",
                            [PARTICIPATION_APPROVED, $notes, $user['id'], $exists['id']]
                        );
                    } else {
                        // Insert new record
                        dbInsert(
                            "INSERT INTO participation_requests 
                             (shift_id, volunteer_id, status, admin_notes, decided_by, decided_at, created_at, updated_at)
                             VALUES (?, ?, ?, ?, ?, NOW(), NOW(), NOW())",
                            [$id, $volunteerId, PARTICIPATION_APPROVED, $notes, $user['id']]
                        );
                    }
                    
                    // Fetch volunteer info for notification
                    $volunteerInfo = dbFetchOne("SELECT name, email FROM users WHERE id = ?", [$volunteerId]);
                    
                    // Send email notification
                    if ($volunteerInfo && !empty($volunteerInfo['email']) && isNotificationEnabled('admin_added_volunteer')) {
                        $gcalLink = 'https://calendar.google.com/calendar/render?action=TEMPLATE'
                            . '&text=' . rawurlencode($shift['mission_title'])
                            . '&dates=' . date('Ymd\THis', strtotime($shift['start_time'])) . '/' . date('Ymd\THis', strtotime($shift['end_time']))
                            . '&details=' . rawurlencode('Βάρδια εθελοντισμού')
                            . '&location=' . rawurlencode($shift['location'] ?: '');
                        sendNotificationEmail(
                            'admin_added_volunteer',
                            $volunteerInfo['email'],
                            [
                                'user_name'     => $volunteerInfo['name'],
                                'mission_title' => $shift['mission_title'],
                                'shift_date'    => formatDateTime($shift['start_time'], 'd/m/Y'),
                                'shift_time'    => formatDateTime($shift['start_time'], 'H:i') . ' - ' . formatDateTime($shift['end_time'], 'H:i'),
                                'location'      => $shift['location'] ?: 'Θα ανακοινωθεί',
                                'admin_notes'   => $notes ?: 'Προστεθήκατε από τον διαχειριστή.',
                                'gcal_link'     => $gcalLink,
                            ]
                        );
                    }
                    
                    // In-app notification
                    sendNotification(
                        (int) $volunteerId,
                        'Τοποθετήθηκατε σε βάρδια',
                        'Ο διαχειριστής σας τοποθέτησε στη βάρδια: ' . $shift['mission_title'] . ' - ' . formatDateTime($shift['start_time'])
                    );
                    
                    logAudit('add_volunteer', 'participation_requests', null, "Shift $id, User $volunteerId");
                    setFlash('success', 'Ο εθελοντής προστέθηκε στη βάρδια και ενημερώθηκε με email.');
                }
            }
            break;
            
        case 'mass_add_volunteers':
            if ($canManage) {
                $volunteerIds = post('volunteer_ids');
                $notes = post('admin_notes');
                
                if (empty($volunteerIds) || !is_array($volunteerIds)) {
                    setFlash('error', 'Δεν επιλέξατε κανέναν εθελοντή.');
                    redirect('shift-view.php?id=' . $id);
                }
                
                $addedCount = 0;
                $skippedCount = 0;
                
                foreach ($volunteerIds as $volunteerId) {
                    $volunteerId = (int) $volunteerId;
                    if (!$volunteerId) continue;
                    
                    // Check if already in this shift (any status)
                    $exists = dbFetchOne(
                        "SELECT id, status FROM participation_requests WHERE shift_id = ? AND volunteer_id = ?",
                        [$id, $volunteerId]
                    );
                    
                    if ($exists && in_array($exists['status'], [PARTICIPATION_APPROVED, PARTICIPATION_PENDING])) {
                        // Already active — skip
                        $skippedCount++;
                        continue;
                    }
                    
                    if ($exists) {
                        // Reactivate existing rejected/canceled record
                        dbExecute(
                            "UPDATE participation_requests 
                             SET status = ?, rejection_reason = NULL, admin_notes = ?, 
                                 decided_by = ?, decided_at = NOW(), updated_at = NOW() 
                             WHERE id = ?",
                            [PARTICIPATION_APPROVED, $notes, $user['id'], $exists['id']]
                        );
                    } else {
                        // Insert new record
                        dbInsert(
                            "INSERT INTO participation_requests 
                             (shift_id, volunteer_id, status, admin_notes, decided_by, decided_at, created_at, updated_at)
                             VALUES (?, ?, ?, ?, ?, NOW(), NOW(), NOW())",
                            [$id, $volunteerId, PARTICIPATION_APPROVED, $notes, $user['id']]
                        );
                    }
                    
                    $addedCount++;
                    
                    // Fetch volunteer info for notification
                    $volunteerInfo = dbFetchOne("SELECT name, email FROM users WHERE id = ?", [$volunteerId]);
                    
                    // Send email notification
                    if ($volunteerInfo && !empty($volunteerInfo['email']) && isNotificationEnabled('admin_added_volunteer')) {
                        $gcalLink = buildGcalLink($shift['mission_title'], $shift['start_time'], $shift['end_time'], $shift['location'] ?: '');
                        sendNotificationEmail(
                            'admin_added_volunteer',
                            $volunteerInfo['email'],
                            [
                                'user_name'     => $volunteerInfo['name'],
                                'mission_title' => $shift['mission_title'],
                                'shift_date'    => formatDateTime($shift['start_time'], 'd/m/Y'),
                                'shift_time'    => formatDateTime($shift['start_time'], 'H:i') . ' - ' . formatDateTime($shift['end_time'], 'H:i'),
                                'location'      => $shift['location'] ?: 'Θα ανακοινωθεί',
                                'admin_notes'   => $notes ?: 'Προστεθήκατε από τον διαχειριστή.',
                                'gcal_link'     => $gcalLink,
                            ]
                        );
                    }
                    
                    // In-app notification
                    sendNotification(
                        (int) $volunteerId,
                        'Τοποθετήθηκατε σε βάρδια',
                        'Ο διαχειριστής σας τοποθέτησε στη βάρδια: ' . $shift['mission_title'] . ' - ' . formatDateTime($shift['start_time'])
                    );
                }
                
                if ($addedCount > 0) {
                    logAudit('mass_add_volunteers', 'participation_requests', null, "Shift $id, Added $addedCount users");
                    $msg = "Προστέθηκαν επιτυχώς $addedCount εθελοντές.";
                    if ($skippedCount > 0) {
                        $msg .= " Παραλείφθηκαν $skippedCount που ήταν ήδη στη βάρδια.";
                    }
                    setFlash('success', $msg);
                } else {
                    setFlash('warning', 'Δεν προστέθηκε κανένας εθελοντής (ήταν όλοι ήδη στη βάρδια).');
                }
            }
            break;
            
        case 'update_notes':
            if ($canManage) {
                $newNotes = post('admin_notes');
                dbExecute(
                    "UPDATE participation_requests SET admin_notes = ?, updated_at = NOW() WHERE id = ?",
                    [$newNotes ?: null, $prId]
                );
                logAudit('update_notes', 'participation_requests', $prId);
                setFlash('success', 'Το σχόλιο ενημερώθηκε.');
            }
            break;

        case 'approve_swap':
            if ($canManage) {
                $swapId = (int) post('swap_id');
                $swap = dbFetchOne(
                    "SELECT ssr.*, fu.name as from_name, fu.email as from_email, tu.name as to_name, tu.email as to_email
                     FROM shift_swap_requests ssr
                     JOIN users fu ON ssr.from_volunteer_id = fu.id
                     JOIN users tu ON ssr.to_volunteer_id = tu.id
                     WHERE ssr.id = ? AND ssr.shift_id = ? AND ssr.status = ?",
                    [$swapId, $id, SWAP_ACCEPTED]
                );
                if ($swap) {
                    $db = db();
                    $db->beginTransaction();
                    try {
                        // Cancel original volunteer's participation
                        dbExecute(
                            "UPDATE participation_requests SET status = ?, updated_at = NOW() WHERE id = ?",
                            [PARTICIPATION_CANCELED_BY_USER, $swap['participation_id']]
                        );
                        // Approve replacement volunteer (upsert)
                        $existingPr = dbFetchOne(
                            "SELECT id FROM participation_requests WHERE shift_id = ? AND volunteer_id = ?",
                            [$id, $swap['to_volunteer_id']]
                        );
                        if ($existingPr) {
                            dbExecute(
                                "UPDATE participation_requests SET status = ?, decided_by = ?, decided_at = NOW(), updated_at = NOW() WHERE id = ?",
                                [PARTICIPATION_APPROVED, $user['id'], $existingPr['id']]
                            );
                        } else {
                            dbInsert(
                                "INSERT INTO participation_requests (shift_id, volunteer_id, status, decided_by, decided_at, created_at, updated_at) VALUES (?,?,?,?,NOW(),NOW(),NOW())",
                                [$id, $swap['to_volunteer_id'], PARTICIPATION_APPROVED, $user['id']]
                            );
                        }
                        // Mark swap as approved
                        dbExecute(
                            "UPDATE shift_swap_requests SET status = ?, decided_by = ?, decided_at = NOW(), updated_at = NOW() WHERE id = ?",
                            [SWAP_APPROVED, $user['id'], $swapId]
                        );
                        $db->commit();
                        logAudit('approve_swap', 'shift_swap_requests', $swapId);

                        // Notify both volunteers
                        $shiftInfo = $shift['start_time'];
                        $missionTitle = $shift['mission_title'] ?? 'Αποστολή';
                        $vars = [
                            'mission_title' => $missionTitle,
                            'shift_date'    => formatDateTime($shift['start_time'], 'd/m/Y'),
                            'shift_time'    => formatDateTime($shift['start_time'], 'H:i') . ' - ' . formatDateTime($shift['end_time'], 'H:i'),
                        ];
                        if (!empty($swap['from_email']) && isNotificationEnabled('shift_swap_approved')) {
                            sendNotificationEmail('shift_swap_approved', $swap['from_email'],
                                array_merge($vars, ['user_name' => $swap['from_name'], 'replacement_name' => $swap['to_name']]));
                        }
                        if (!empty($swap['to_email']) && isNotificationEnabled('shift_swap_approved')) {
                            sendNotificationEmail('shift_swap_approved', $swap['to_email'],
                                array_merge($vars, ['user_name' => $swap['to_name'], 'replacement_name' => $swap['to_name']]));
                        }
                        sendNotification($swap['from_volunteer_id'], 'Αντικατάσταση Εγκρίθηκε',
                            'Η αντικατάστασή σας από τον/την ' . $swap['to_name'] . ' εγκρίθηκε από τον διαχειριστή.');
                        sendNotification($swap['to_volunteer_id'], 'Εγκρίθηκε η Συμμετοχή σας',
                            'Εγκριθήκατε ως αντικατάσταση για τη βάρδια της αποστολής: ' . $missionTitle . '.');

                        setFlash('success', 'Η αντικατάσταση εγκρίθηκε επιτυχώς.');
                    } catch (Exception $e) {
                        $db->rollBack();
                        setFlash('error', 'Σφάλμα κατά την έγκριση αντικατάστασης.');
                    }
                } else {
                    setFlash('error', 'Δεν βρέθηκε το αίτημα αντικατάστασης.');
                }
            }
            break;

        case 'reject_swap':
            if ($canManage) {
                $swapId = (int) post('swap_id');
                $swap = dbFetchOne(
                    "SELECT ssr.*, fu.name as from_name, tu.name as to_name
                     FROM shift_swap_requests ssr
                     JOIN users fu ON ssr.from_volunteer_id = fu.id
                     JOIN users tu ON ssr.to_volunteer_id = tu.id
                     WHERE ssr.id = ? AND ssr.shift_id = ? AND ssr.status = ?",
                    [$swapId, $id, SWAP_ACCEPTED]
                );
                if ($swap) {
                    dbExecute(
                        "UPDATE shift_swap_requests SET status = ?, decided_by = ?, decided_at = NOW(), updated_at = NOW() WHERE id = ?",
                        [SWAP_REJECTED, $user['id'], $swapId]
                    );
                    logAudit('reject_swap', 'shift_swap_requests', $swapId);
                    // Notify both
                    sendNotification($swap['from_volunteer_id'], 'Αίτημα Αντικατάστασης Απορρίφθηκε',
                        'Ο διαχειριστής απέρριψε το αίτημα αντικατάστασης σας.');
                    sendNotification($swap['to_volunteer_id'], 'Αίτημα Αντικατάστασης Απορρίφθηκε',
                        'Ο διαχειριστής απέρριψε το αίτημα αντικατάστασης.');
                    setFlash('warning', 'Το αίτημα αντικατάστασης απορρίφθηκε.');
                }
            }
            break;
    }
    
    redirect('shift-view.php?id=' . $id);
}

// Helpers
function calculateShiftHours($shift) {
    return (strtotime($shift['end_time']) - strtotime($shift['start_time'])) / 3600;
}

function calculatePoints($shift, $hours) {
    $points = $hours * POINTS_PER_HOUR;
    
    // Weekend multiplier
    $dayOfWeek = date('N', strtotime($shift['start_time']));
    if ($dayOfWeek >= 6) {
        $points *= WEEKEND_MULTIPLIER;
    }
    
    // Night multiplier (shifts starting after 22:00 or before 06:00)
    $hour = (int) date('H', strtotime($shift['start_time']));
    if ($hour >= 22 || $hour < 6) {
        $points *= NIGHT_MULTIPLIER;
    }
    
    return round($points);
}

// Counts
$approvedCount = 0;
$pendingCount = 0;
foreach ($participants as $p) {
    if ($p['status'] === PARTICIPATION_APPROVED) $approvedCount++;
    if ($p['status'] === PARTICIPATION_PENDING) $pendingCount++;
}

$isPast = strtotime($shift['end_time']) < time();
$isActive = strtotime($shift['start_time']) <= time() && strtotime($shift['end_time']) >= time();
$missionOverdue = in_array($shift['mission_status'], [STATUS_OPEN, STATUS_CLOSED]) && strtotime($shift['mission_end_datetime']) < time();

// Swap history for this shift — all statuses, visible to all users
$swapHistory = dbFetchAll(
    "SELECT ssr.*, fu.name as from_name, tu.name as to_name
     FROM shift_swap_requests ssr
     JOIN users fu ON ssr.from_volunteer_id = fu.id
     JOIN users tu ON ssr.to_volunteer_id = tu.id
     WHERE ssr.shift_id = ?
     ORDER BY ssr.created_at DESC",
    [$id]
);
// Pending swap requests awaiting admin approval (both parties agreed)
$pendingSwaps = [];
if ($canManage) {
    $pendingSwaps = array_values(array_filter($swapHistory, fn($s) => $s['status'] === SWAP_ACCEPTED));
}

// Get available volunteers for manual add (exclude only PENDING/APPROVED — rejected/canceled can be re-added)
$availableVolunteers = [];
if ($canManage) {
    $activeIds = [];
    foreach ($participants as $p) {
        if (in_array($p['status'], [PARTICIPATION_PENDING, PARTICIPATION_APPROVED])) {
            $activeIds[] = (int) $p['volunteer_id'];
        }
    }
    $excludeClause = '';
    if (!empty($activeIds)) {
        $excludeClause = 'AND id NOT IN (' . implode(',', $activeIds) . ')';
    }
    $availableVolunteers = dbFetchAll(
        "SELECT id, name, email, role FROM users 
         WHERE is_active = 1
           $excludeClause 
         ORDER BY name"
    );
}

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-0"><?= h($pageTitle) ?></h1>
        <small class="text-muted">
            Αποστολή: <a href="mission-view.php?id=<?= $shift['mission_id'] ?>"><?= h($shift['mission_title']) ?></a>
        </small>
    </div>
    <div>
        <?php if ($canManage): ?>
            <a href="shift-form.php?id=<?= $id ?>" class="btn btn-outline-primary">
                <i class="bi bi-pencil me-1"></i>Επεξεργασία
            </a>
        <?php endif; ?>
        <a href="mission-view.php?id=<?= $shift['mission_id'] ?>" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Πίσω
        </a>
    </div>
</div>

<?= showFlash() ?>

<?php if ($missionCompleted && (isAdmin() || hasRole(ROLE_SHIFT_LEADER))): ?>
<div class="alert alert-warning d-flex align-items-center gap-2 mb-4">
    <i class="bi bi-lock-fill fs-5"></i>
    <div>
        <strong>Η αποστολή είναι ολοκληρωμένη.</strong>
        Δεν μπορείτε να προσθέσετε/αφαιρέσετε εθελοντές ή να αλλάξετε παρουσίες.
        <a href="mission-view.php?id=<?= $shift['mission_id'] ?>" class="alert-link">Αλλάξτε πρώτα την κατάσταση σε «Κλειστή»</a> για να κάνετε αλλαγές.
    </div>
</div>
<?php endif; ?>

<div class="row">
    <div class="col-lg-8">
        <!-- Shift Details -->
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-clock me-1"></i>Στοιχεία Βάρδιας</h5>
                <?php if ($isActive): ?>
                    <span class="badge bg-success fs-6"><i class="bi bi-play-fill me-1"></i>Σε εξέλιξη</span>
                <?php elseif ($isPast): ?>
                    <span class="badge bg-secondary fs-6"><i class="bi bi-check me-1"></i>Ολοκληρώθηκε</span>
                <?php else: ?>
                    <span class="badge bg-primary fs-6"><i class="bi bi-clock me-1"></i>Επερχόμενη</span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if (!empty($shift['description'])): ?>
                    <p><?= nl2br(h($shift['description'])) ?></p>
                <?php endif; ?>
                
                <div class="row">
                    <div class="col-md-6">
                        <p><strong><i class="bi bi-calendar me-1"></i>Έναρξη:</strong><br>
                        <?= formatDateTime($shift['start_time']) ?></p>
                    </div>
                    <div class="col-md-6">
                        <p><strong><i class="bi bi-calendar-check me-1"></i>Λήξη:</strong><br>
                        <?= formatDateTime($shift['end_time']) ?></p>
                    </div>
                </div>
                
                <p><strong><i class="bi bi-hourglass me-1"></i>Διάρκεια:</strong> 
                    <?= number_format(calculateShiftHours($shift), 1) ?> ώρες</p>
                
                <?php if (!empty($shift['location'])): ?>
                    <p><strong><i class="bi bi-geo-alt me-1"></i>Τοποθεσία:</strong> <?= h($shift['location']) ?></p>
                <?php endif; ?>
                
                <p><strong><i class="bi bi-people me-1"></i>Εθελοντές:</strong> 
                    <?= $approvedCount ?> / <?= $shift['max_volunteers'] ?>
                    (ελάχ. <?= $shift['min_volunteers'] ?>)
                </p>
                
                <?php if (!empty($shift['required_skills'])): 
                    $skillIds = json_decode($shift['required_skills'], true);
                    if ($skillIds):
                        $shiftSkills = dbFetchAll(
                            "SELECT name FROM skills WHERE id IN (" . implode(',', array_map('intval', $skillIds)) . ")"
                        );
                ?>
                    <p><strong><i class="bi bi-tools me-1"></i>Απαιτούμενες Δεξιότητες:</strong><br>
                        <?php foreach ($shiftSkills as $sk): ?>
                            <span class="badge bg-info"><?= h($sk['name']) ?></span>
                        <?php endforeach; ?>
                    </p>
                <?php endif; endif; ?>
                
                <?php if ($shift['notes'] && $canManage): ?>
                    <div class="alert alert-secondary">
                        <strong>Σημειώσεις:</strong><br>
                        <?= nl2br(h($shift['notes'])) ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Participants -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="bi bi-people me-1"></i>Εθελοντές
                    <?php if ($pendingCount > 0): ?>
                        <span class="badge bg-warning"><?= $pendingCount ?> εκκρεμεί</span>
                    <?php endif; ?>
                </h5>
            </div>
            <div class="card-body">
                <?php if (empty($participants)): ?>
                    <p class="text-muted">Δεν υπάρχουν αιτήσεις.</p>
                <?php else: ?>
                    <?php
                    // Determine border accent colour per participation status
                    $statusBorderMap = [
                        PARTICIPATION_APPROVED           => '#198754',  // green
                        PARTICIPATION_PENDING            => '#f0ad4e',  // yellow
                        PARTICIPATION_REJECTED           => '#dc3545',  // red
                        PARTICIPATION_CANCELED_BY_USER   => '#6c757d',  // grey
                        PARTICIPATION_CANCELED_BY_ADMIN  => '#6c757d',  // grey
                    ];
                    ?>
                    <div class="row row-cols-1 row-cols-sm-2 row-cols-xl-3 g-3">
                        <?php foreach ($participants as $p):
                            $accentColor = $statusBorderMap[$p['status']] ?? '#6c757d';
                        ?>
                        <div class="col">
                            <div class="card h-100 shadow-sm" style="border-left:4px solid <?= $accentColor ?>;border-radius:.6rem;">
                                <div class="card-body pb-2">
                                    <!-- Name row -->
                                    <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                                        <div>
                                            <strong class="fs-6"><?= h($p['name']) ?></strong>
                                            <?= volunteerTypeBadge($p['volunteer_type'] ?? VTYPE_RESCUER) ?>
                                        </div>
                                        <div class="d-flex flex-column align-items-end gap-1">
                                            <?= statusBadge($p['status'], 'participation') ?>
                                            <?php if ($p['attended']): ?>
                                                <span class="badge bg-success"><i class="bi bi-check2-circle me-1"></i>Παρών</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <!-- Phone (visible to all) -->
                                    <?php if ($p['phone']): ?>
                                    <div class="small mb-1">
                                        <i class="bi bi-telephone me-1 text-muted"></i><a href="tel:<?= h($p['phone']) ?>" class="text-decoration-none"><?= h($p['phone']) ?></a>
                                    </div>
                                    <?php endif; ?>
                                    <!-- Email (admins/shift leaders only) -->
                                    <?php if ($canManage): ?>
                                    <div class="text-muted small mb-1">
                                        <i class="bi bi-envelope me-1"></i><?= h($p['email']) ?>
                                    </div>
                                    <?php endif; ?>

                                    <!-- Application date -->
                                    <div class="text-muted small">
                                        <i class="bi bi-calendar3 me-1"></i><?= formatDate($p['created_at']) ?>
                                    </div>

                                    <!-- Volunteer notes -->
                                    <?php if ($p['notes']): ?>
                                    <div class="mt-2 p-2 rounded small" style="background:#f8f9fa;border-left:3px solid #adb5bd">
                                        <i class="bi bi-quote me-1 text-muted"></i><?= h($p['notes']) ?>
                                    </div>
                                    <?php endif; ?>

                                    <!-- Admin notes -->
                                    <?php if ($canManage && $p['admin_notes']): ?>
                                    <div class="mt-2 p-2 rounded small" style="background:#fff8e1;border-left:3px solid #f0ad4e">
                                        <i class="bi bi-chat-left-text me-1 text-warning"></i><?= h($p['admin_notes']) ?>
                                    </div>
                                    <?php endif; ?>

                                    <!-- Actual hours (if attended) -->
                                    <?php if ($p['attended'] && $p['actual_hours']): ?>
                                    <div class="mt-2 text-success small fw-semibold">
                                        <i class="bi bi-clock me-1"></i><?= number_format($p['actual_hours'], 1) ?> ώρες καταγράφηκαν
                                    </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Action footer (admins/shift leaders only) -->
                                <?php if ($canManage): ?>
                                <div class="card-footer bg-transparent pt-2 pb-2 d-flex gap-2 flex-wrap align-items-center">
                                    <!-- Admin notes button -->
                                    <button type="button" class="btn btn-sm btn-outline-secondary edit-notes-btn"
                                            data-id="<?= $p['id'] ?>"
                                            data-name="<?= h($p['name']) ?>"
                                            data-notes="<?= h($p['admin_notes']) ?>"
                                            title="Σχόλιο διαχειριστή">
                                        <i class="bi bi-chat-left-text <?= $p['admin_notes'] ? 'text-info' : '' ?>"></i>
                                    </button>

                                    <?php if ($p['status'] === PARTICIPATION_PENDING): ?>
                                        <form method="post" class="d-inline">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="approve">
                                            <input type="hidden" name="participation_id" value="<?= $p['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-success" title="Έγκριση">
                                                <i class="bi bi-check-lg me-1"></i>Έγκριση
                                            </button>
                                        </form>
                                        <button type="button" class="btn btn-sm btn-danger reject-btn"
                                                data-id="<?= $p['id'] ?>"
                                                data-name="<?= h($p['name']) ?>"
                                                data-type="reject"
                                                title="Απόρριψη">
                                            <i class="bi bi-x-lg me-1"></i>Απόρριψη
                                        </button>
                                    <?php elseif ($p['status'] === PARTICIPATION_APPROVED && !$p['attended']): ?>
                                        <?php if ($isPast): ?>
                                        <button type="button" class="btn btn-sm btn-primary attend-btn"
                                                data-id="<?= $p['id'] ?>"
                                                data-name="<?= h($p['name']) ?>"
                                                data-hours="<?= number_format(calculateShiftHours($shift), 1) ?>">
                                            <i class="bi bi-check2-circle me-1"></i>Παρουσία
                                        </button>
                                        <?php endif; ?>
                                        <button type="button" class="btn btn-sm btn-outline-danger reject-btn"
                                                data-id="<?= $p['id'] ?>"
                                                data-name="<?= h($p['name']) ?>"
                                                data-type="cancel"
                                                title="Ακύρωση έγκρισης">
                                            <i class="bi bi-x-lg me-1"></i>Ακύρωση
                                        </button>
                                    <?php elseif (in_array($p['status'], [PARTICIPATION_REJECTED, PARTICIPATION_CANCELED_BY_ADMIN, PARTICIPATION_CANCELED_BY_USER]) && !$p['attended']): ?>
                                        <button type="button" class="btn btn-sm btn-outline-success reactivate-btn"
                                                data-id="<?= $p['id'] ?>"
                                                data-name="<?= h($p['name']) ?>"
                                                title="Επανενεργοποίηση">
                                            <i class="bi bi-arrow-clockwise me-1"></i>Επανενεργοποίηση
                                        </button>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <div class="col-lg-4">
        <!-- Apply / My Status -->
        <?php if (!isAdmin()): ?>
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-hand-index me-1"></i>Η Αίτησή μου</h5>
                </div>
                <div class="card-body">
                    <?php if ($myParticipation): ?>
                        <p>Κατάσταση: <?= statusBadge($myParticipation['status']) ?></p>
                        
                        <?php if ($myParticipation['status'] === PARTICIPATION_PENDING): ?>
                            <form method="post">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="cancel">
                                <button type="submit" class="btn btn-outline-danger w-100" 
                                        onclick="return confirm('Ακύρωση της αίτησής σας;')">
                                    <i class="bi bi-x-circle me-1"></i>Ακύρωση Αίτησης
                                </button>
                            </form>
                        <?php elseif ($myParticipation['status'] === PARTICIPATION_APPROVED): ?>
                            <div class="alert alert-success mb-0">
                                <i class="bi bi-check-circle me-1"></i>
                                Η συμμετοχή σας έχει εγκριθεί!
                            </div>
                        <?php elseif ($myParticipation['status'] === PARTICIPATION_REJECTED): ?>
                            <div class="alert alert-danger mb-0">
                                <i class="bi bi-x-circle me-1"></i>
                                Η αίτηση απορρίφθηκε.
                                <?php if ($myParticipation['rejection_reason']): ?>
                                    <br><small><?= h($myParticipation['rejection_reason']) ?></small>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    <?php elseif ($shift['mission_status'] === STATUS_OPEN && !$isPast && !$missionOverdue): ?>
                        <?php if ($approvedCount >= $shift['max_volunteers']): ?>
                            <div class="alert alert-warning mb-0">
                                <i class="bi bi-exclamation-circle me-1"></i>
                                Η βάρδια είναι πλήρης.
                            </div>
                        <?php else: ?>
                            <form method="post">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="apply">
                                <div class="mb-3">
                                    <label class="form-label">Σημειώσεις (προαιρετικά)</label>
                                    <textarea class="form-control" name="notes" rows="2"></textarea>
                                </div>
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="bi bi-send me-1"></i>Υποβολή Αίτησης
                                </button>
                            </form>
                        <?php endif; ?>
                    <?php elseif ($missionOverdue && !isAdmin()): ?>
                        <div class="alert alert-warning mb-0">
                            <i class="bi bi-clock-history me-1"></i>
                            Η αποστολή είναι ακόμα ανοιχτή αλλά <strong>ο χρόνος διεξαγωγής έχει παρέλθει</strong>. Δεν μπορείτε να υποβάλετε αίτηση.
                        </div>
                    <?php else: ?>
                        <p class="text-muted mb-0">Δεν μπορείτε να υποβάλετε αίτηση αυτή τη στιγμή.</p>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
        
        <!-- Points Preview -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-star me-1"></i>Πόντοι</h5>
            </div>
            <div class="card-body text-center">
                <h2 class="text-warning"><?= calculatePoints($shift, calculateShiftHours($shift)) ?></h2>
                <small class="text-muted">πόντοι για αυτή τη βάρδια</small>
            </div>
        </div>
        
        <!-- Pending Swap Requests -->
        <?php if ($canManage && !empty($pendingSwaps)): ?>
        <div class="card mb-4 border-warning">
            <div class="card-header bg-warning text-dark">
                <h5 class="mb-0"><i class="bi bi-arrow-left-right me-1"></i>Αιτήματα Αντικατάστασης &mdash; Αναμένουν Έγκριση (<?= count($pendingSwaps) ?>)</h5>
            </div>
            <div class="list-group list-group-flush">
                <?php foreach ($pendingSwaps as $sw): ?>
                <div class="list-group-item">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                        <div>
                            <strong><?= h($sw['from_name']) ?></strong>
                            <i class="bi bi-arrow-right mx-2 text-muted"></i>
                            <strong><?= h($sw['to_name']) ?></strong>
                            <?php if ($sw['message']): ?>
                            <div class="mt-1 p-2 rounded" style="background:#fff8e1;font-size:.85rem;border-left:3px solid #f0ad4e">
                                <i class="bi bi-quote me-1"></i><?= h($sw['message']) ?>
                            </div>
                            <?php endif; ?>
                            <small class="text-muted d-block mt-1">Απαντήθηκε: <?= $sw['to_volunteer_responded_at'] ? formatDateTime($sw['to_volunteer_responded_at']) : '&mdash;' ?></small>
                        </div>
                        <div class="d-flex gap-2">
                            <form method="post">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="approve_swap">
                                <input type="hidden" name="swap_id" value="<?= $sw['id'] ?>">
                                <button class="btn btn-sm btn-success">
                                    <i class="bi bi-check-lg me-1"></i>Έγκριση
                                </button>
                            </form>
                            <form method="post" onsubmit="return confirm('Απόρριψη αιτήματος;')">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="reject_swap">
                                <input type="hidden" name="swap_id" value="<?= $sw['id'] ?>">
                                <button class="btn btn-sm btn-outline-danger">
                                    <i class="bi bi-x-lg me-1"></i>Απόρριψη
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Swap History (visible to all users) -->
        <?php if (!empty($swapHistory)): ?>
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-arrow-left-right me-1"></i>Ιστορικό Αντικαταστάσεων</h5>
            </div>
            <div class="list-group list-group-flush">
                <?php 
                $swapStatusLabels = [
                    SWAP_PENDING_RESPONSE => ['label' => 'Αναμονή αποδοχής', 'class' => 'bg-secondary'],
                    SWAP_ACCEPTED         => ['label' => 'Αναμένει έγκριση',  'class' => 'bg-warning text-dark'],
                    SWAP_APPROVED         => ['label' => 'Εγκρίθηκε',         'class' => 'bg-success'],
                    SWAP_REJECTED         => ['label' => 'Απορρίφθηκε',       'class' => 'bg-danger'],
                    SWAP_CANCELED         => ['label' => 'Ακυρώθηκε',         'class' => 'bg-secondary'],
                ];
                foreach ($swapHistory as $sw): 
                    $sl = $swapStatusLabels[$sw['status']] ?? ['label' => $sw['status'], 'class' => 'bg-secondary'];
                ?>
                <div class="list-group-item">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-1 mb-1">
                        <small>
                            <strong><?= h($sw['from_name']) ?></strong>
                            <i class="bi bi-arrow-right mx-1 text-muted"></i>
                            <strong><?= h($sw['to_name']) ?></strong>
                        </small>
                        <span class="badge <?= $sl['class'] ?>"><?= $sl['label'] ?></span>
                    </div>
                    <?php if ($sw['message']): ?>
                    <div class="p-2 rounded mt-1" style="background:#fff8e1;font-size:.8rem;border-left:3px solid #f0ad4e">
                        <i class="bi bi-quote me-1 text-muted"></i><?= h($sw['message']) ?>
                    </div>
                    <?php endif; ?>
                    <small class="text-muted d-block mt-1"><?= formatDateTime($sw['created_at']) ?></small>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Admin Actions -->
        <?php if ($canManage): ?>
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-person-plus me-1"></i>Προσθήκη Εθελοντών</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($availableVolunteers)): ?>
                        <p class="text-muted mb-0">Δεν υπάρχουν διαθέσιμοι εθελοντές.</p>
                    <?php elseif ($approvedCount >= $shift['max_volunteers']): ?>
                        <p class="text-warning mb-0"><i class="bi bi-exclamation-triangle me-1"></i>Η βάρδια είναι πλήρης.</p>
                    <?php else: ?>
                        <div class="d-grid gap-2">
                            <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addVolunteerModal">
                                <i class="bi bi-person-plus me-1"></i>Μεμονωμένη Προσθήκη
                            </button>
                            <button type="button" class="btn btn-outline-success" data-bs-toggle="modal" data-bs-target="#massAddVolunteerModal">
                                <i class="bi bi-people me-1"></i>Μαζική Προσθήκη
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="card border-danger">
                <div class="card-header bg-danger text-white">
                    <h5 class="mb-0"><i class="bi bi-exclamation-triangle me-1"></i>Επικίνδυνες Ενέργειες</h5>
                </div>
                <div class="card-body">
                    <button type="button" class="btn btn-outline-danger w-100" data-bs-toggle="modal" data-bs-target="#deleteShiftModal">
                        <i class="bi bi-trash me-1"></i>Διαγραφή Βάρδιας
                    </button>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Add Volunteer Modal -->
<?php if ($canManage && !empty($availableVolunteers)): ?>
<div class="modal fade" id="addVolunteerModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="add_volunteer">
                
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-person-plus me-1"></i>Προσθήκη Εθελοντή</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
                    <link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
                    <div class="mb-3">
                        <label class="form-label">Επιλογή Χρήστη <span class="text-danger">*</span></label>
                        <select class="form-select" name="volunteer_id" required id="volunteerSelect">
                            <option value="">Αναζήτηση χρήστη...</option>
                            <?php foreach ($availableVolunteers as $v): ?>
                                <option value="<?= $v['id'] ?>"><?= h($v['name']) ?> (<?= h($v['email']) ?>) — <?= h(ROLE_LABELS[$v['role']] ?? $v['role']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Σημειώσεις</label>
                        <textarea class="form-control" name="admin_notes" rows="2" placeholder="Λόγος χειροκίνητης ανάθεσης..."></textarea>
                    </div>
                    <div class="alert alert-info mb-0">
                        <i class="bi bi-info-circle me-1"></i>
                        Ο εθελοντής θα προστεθεί απευθείας ως <strong>ΕΓΚΕΚΡΙΜΕΝΟΣ</strong>.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Ακύρωση</button>
                    <button type="submit" class="btn btn-success">Προσθήκη</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Mass Add Volunteers Modal -->
<div class="modal fade" id="massAddVolunteerModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="mass_add_volunteers">
                
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-people me-1"></i>Μαζική Προσθήκη Εθελοντών</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <input type="text" class="form-control" id="massAddSearch" placeholder="Αναζήτηση με όνομα ή email...">
                    </div>
                    
                    <div class="mb-3 d-flex justify-content-between align-items-center">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="selectAllVolunteers">
                            <label class="form-check-label fw-bold" for="selectAllVolunteers">
                                Επιλογή Όλων
                            </label>
                        </div>
                        <span class="badge bg-primary" id="selectedCountBadge">0 επιλεγμένοι</span>
                    </div>
                    
                    <div class="list-group mb-3" style="max-height: 300px; overflow-y: auto;" id="massAddList">
                        <?php foreach ($availableVolunteers as $v): ?>
                            <label class="list-group-item d-flex gap-2 align-items-center volunteer-item">
                                <input class="form-check-input flex-shrink-0 volunteer-checkbox" type="checkbox" name="volunteer_ids[]" value="<?= $v['id'] ?>">
                                <span>
                                    <span class="volunteer-name fw-bold"><?= h($v['name']) ?></span>
                                    <small class="text-muted volunteer-email d-block"><?= h($v['email']) ?> — <?= h(ROLE_LABELS[$v['role']] ?? $v['role']) ?></small>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Σημειώσεις (κοινές για όλους)</label>
                        <textarea class="form-control" name="admin_notes" rows="2" placeholder="Λόγος χειροκίνητης ανάθεσης..."></textarea>
                    </div>
                    <div class="alert alert-info mb-0">
                        <i class="bi bi-info-circle me-1"></i>
                        Οι εθελοντές θα προστεθούν απευθείας ως <strong>ΕΓΚΕΚΡΙΜΕΝΟΙ</strong> και θα λάβουν ειδοποίηση.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Ακύρωση</button>
                    <button type="submit" class="btn btn-success" id="massAddSubmitBtn" disabled>Προσθήκη Επιλεγμένων</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Single Notes Modal -->
<?php if ($canManage): ?>
<div class="modal fade" id="editNotesModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" id="notesForm">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="update_notes">
                <input type="hidden" name="participation_id" id="notesParticipationId">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-chat-left-text me-1"></i>Σχόλιο Διαχειριστή</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Σχόλιο για: <strong id="notesVolunteerName"></strong></p>
                    <textarea class="form-control" name="admin_notes" id="notesTextarea" rows="3" 
                              placeholder="Σχόλιο διαχειριστή..."></textarea>
                    <small class="text-muted">Αφήστε κενό για διαγραφή του σχολίου.</small>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Ακύρωση</button>
                    <button type="submit" class="btn btn-primary">Αποθήκευση</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Single Reject Modal -->
<div class="modal fade" id="rejectModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" id="rejectForm">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="participation_id" id="rejectParticipationId">
                <div class="modal-header">
                    <h5 class="modal-title" id="rejectModalTitle">Απόρριψη Αίτησης</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p id="rejectModalText">Απόρριψη αίτησης του <strong id="rejectVolunteerName"></strong>;</p>
                    <div class="mb-3">
                        <label class="form-label">Αιτιολογία (προαιρετική)</label>
                        <textarea class="form-control" name="reason" id="rejectReason" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Ακύρωση</button>
                    <button type="submit" class="btn btn-danger" id="rejectSubmitBtn">Ακύρωση Συμμετοχής</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Single Attendance Modal -->
<div class="modal fade" id="attendModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" id="attendForm">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="mark_attended">
                <input type="hidden" name="participation_id" id="attendParticipationId">
                <div class="modal-header">
                    <h5 class="modal-title">Καταγραφή Παρουσίας</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Καταγραφή παρουσίας για <strong id="attendVolunteerName"></strong></p>
                    <div class="mb-3">
                        <label class="form-label">Πραγματικές Ώρες</label>
                        <input type="number" step="0.5" class="form-control" name="actual_hours" id="attendHours">
                        <small class="text-muted">Προγραμματισμένες: <span id="attendScheduledHours"></span> ώρες</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Ακύρωση</button>
                    <button type="submit" class="btn btn-success">Καταγραφή</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Reactivate Volunteer Modal -->
<div class="modal fade" id="reactivateModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" id="reactivateForm">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="reactivate">
                <input type="hidden" name="participation_id" id="reactivateParticipationId">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="bi bi-arrow-clockwise me-1"></i>Επανενεργοποίηση Εθελοντή</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Θέλετε να επανενεργοποιήσετε τη συμμετοχή του <strong id="reactivateVolunteerName"></strong>;</p>
                    <div class="alert alert-info mb-0">
                        <i class="bi bi-info-circle me-1"></i>
                        Η αίτηση θα οριστεί ως <strong>ΕΓΚΕΚΡΙΜΕΝΗ</strong> και ο εθελοντής θα ειδοποιηθεί με email.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Άκυρο</button>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-arrow-clockwise me-1"></i>Επανενεργοποίηση
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Shift Confirmation Modal -->
<?php 
$activeParticipants = array_filter($participants, function($p) {
    return in_array($p['status'], [PARTICIPATION_PENDING, PARTICIPATION_APPROVED]);
});
?>
<div class="modal fade" id="deleteShiftModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-1"></i>Διαγραφή Βάρδιας</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-circle me-1"></i>
                        <strong>Προσοχή!</strong> Αυτή η ενέργεια δεν μπορεί να αναιρεθεί.
                    </div>
                    
                    <?php if (count($activeParticipants) > 0): ?>
                        <div class="alert alert-warning">
                            <i class="bi bi-people me-1"></i>
                            <strong>Οι παρακάτω <?= count($activeParticipants) ?> αιτήσεις θα ακυρωθούν:</strong>
                        </div>
                        <ul class="list-group mb-3">
                            <?php foreach ($activeParticipants as $p): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <span>
                                        <i class="bi bi-person me-1"></i><?= h($p['name']) ?>
                                    </span>
                                    <?php if ($p['status'] === PARTICIPATION_APPROVED): ?>
                                        <span class="badge bg-success">Εγκεκριμένη</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning">Εκκρεμεί</span>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <p class="text-muted mb-0">
                            <i class="bi bi-bell me-1"></i>
                            Οι εθελοντές θα ειδοποιηθούν αυτόματα για την ακύρωση.
                        </p>
                    <?php else: ?>
                        <p class="mb-0">Η βάρδια δεν έχει ενεργές αιτήσεις συμμετοχής.</p>
                    <?php endif; ?>
                    
                    <hr>
                    <p class="mb-0"><strong>Είστε σίγουροι ότι θέλετε να διαγράψετε τη βάρδια;</strong></p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Ακύρωση</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-trash me-1"></i>Διαγραφή
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>

<?php if ($canManage): ?>
<script>
document.querySelectorAll('.edit-notes-btn').forEach(function(btn) {
    btn.addEventListener('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        
        var id = this.getAttribute('data-id');
        var name = this.getAttribute('data-name');
        var notes = this.getAttribute('data-notes');
        
        document.getElementById('notesParticipationId').value = id;
        document.getElementById('notesVolunteerName').textContent = name;
        document.getElementById('notesTextarea').value = notes || '';
        
        var modal = new bootstrap.Modal(document.getElementById('editNotesModal'));
        modal.show();
    });
});

// Reject buttons
document.querySelectorAll('.reject-btn').forEach(function(btn) {
    btn.addEventListener('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        
        var id = this.getAttribute('data-id');
        var name = this.getAttribute('data-name');
        var type = this.getAttribute('data-type');
        
        document.getElementById('rejectParticipationId').value = id;
        document.getElementById('rejectVolunteerName').textContent = name;
        document.getElementById('rejectReason').value = '';
        
        if (type === 'cancel') {
            document.getElementById('rejectModalTitle').textContent = 'Ακύρωση Έγκρισης';
            document.getElementById('rejectSubmitBtn').textContent = 'Ακύρωση';
        } else {
            document.getElementById('rejectModalTitle').textContent = 'Απόρριψη Αίτησης';
            document.getElementById('rejectSubmitBtn').textContent = 'Απόρριψη';
        }
        
        var modal = new bootstrap.Modal(document.getElementById('rejectModal'));
        modal.show();
    });
});

// Reactivate buttons
document.querySelectorAll('.reactivate-btn').forEach(function(btn) {
    btn.addEventListener('click', function(e) {
        e.preventDefault();
        e.stopPropagation();

        document.getElementById('reactivateParticipationId').value = this.getAttribute('data-id');
        document.getElementById('reactivateVolunteerName').textContent = this.getAttribute('data-name');

        new bootstrap.Modal(document.getElementById('reactivateModal')).show();
    });
});

// Attend buttons
document.querySelectorAll('.attend-btn').forEach(function(btn) {
    btn.addEventListener('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        
        var id = this.getAttribute('data-id');
        var name = this.getAttribute('data-name');
        var hours = this.getAttribute('data-hours');
        
        document.getElementById('attendParticipationId').value = id;
        document.getElementById('attendVolunteerName').textContent = name;
        document.getElementById('attendHours').value = hours;
        document.getElementById('attendScheduledHours').textContent = hours;
        
        var modal = new bootstrap.Modal(document.getElementById('attendModal'));
        modal.show();
    });
});
</script>
<?php endif; ?>

<?php if ($canManage && !empty($availableVolunteers)): ?>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
$(document).ready(function() {
    $('#volunteerSelect').select2({
        theme: 'bootstrap-5',
        dropdownParent: $('#addVolunteerModal'),
        placeholder: 'Πληκτρολογήστε για αναζήτηση...',
        allowClear: true,
        language: {
            noResults: function() { return 'Δεν βρέθηκαν αποτελέσματα'; },
            searching: function() { return 'Αναζήτηση...'; }
        }
    });
});

// Mass Add Volunteers Logic
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('massAddSearch');
    const selectAllCheckbox = document.getElementById('selectAllVolunteers');
    const volunteerCheckboxes = document.querySelectorAll('.volunteer-checkbox');
    const volunteerItems = document.querySelectorAll('.volunteer-item');
    const selectedCountBadge = document.getElementById('selectedCountBadge');
    const submitBtn = document.getElementById('massAddSubmitBtn');

    if (!searchInput) return;

    // Filter volunteers
    searchInput.addEventListener('input', function() {
        const term = this.value.toLowerCase();
        volunteerItems.forEach(item => {
            const name = item.querySelector('.volunteer-name').textContent.toLowerCase();
            const email = item.querySelector('.volunteer-email').textContent.toLowerCase();
            if (name.includes(term) || email.includes(term)) {
                item.style.display = '';
            } else {
                item.style.display = 'none';
            }
        });
        updateSelectAllState();
    });

    // Select All (only visible items)
    selectAllCheckbox.addEventListener('change', function() {
        const isChecked = this.checked;
        volunteerItems.forEach(item => {
            if (item.style.display !== 'none') {
                const cb = item.querySelector('.volunteer-checkbox');
                cb.checked = isChecked;
            }
        });
        updateCount();
    });

    // Individual checkbox change
    volunteerCheckboxes.forEach(cb => {
        cb.addEventListener('change', function() {
            updateCount();
            updateSelectAllState();
        });
    });

    function updateCount() {
        const count = document.querySelectorAll('.volunteer-checkbox:checked').length;
        selectedCountBadge.textContent = count + (count === 1 ? ' επιλεγμένος' : ' επιλεγμένοι');
        submitBtn.disabled = count === 0;
    }

    function updateSelectAllState() {
        const visibleItems = Array.from(volunteerItems).filter(item => item.style.display !== 'none');
        const visibleChecked = visibleItems.filter(item => item.querySelector('.volunteer-checkbox').checked);
        
        if (visibleItems.length === 0) {
            selectAllCheckbox.checked = false;
            selectAllCheckbox.indeterminate = false;
        } else if (visibleChecked.length === visibleItems.length) {
            selectAllCheckbox.checked = true;
            selectAllCheckbox.indeterminate = false;
        } else if (visibleChecked.length > 0) {
            selectAllCheckbox.checked = false;
            selectAllCheckbox.indeterminate = true;
        } else {
            selectAllCheckbox.checked = false;
            selectAllCheckbox.indeterminate = false;
        }
    }
});
</script>
<?php endif; ?>
