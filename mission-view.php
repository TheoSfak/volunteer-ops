<?php
/**
 * VolunteerOps - View Mission
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();

$id = (int) get('id');
if (!$id) {
    redirect('missions.php');
}

$mission = dbFetchOne(
    "SELECT m.*, d.name as department_name, u.name as creator_name, r.name as responsible_name,
            mt.name as type_name, mt.color as type_color, mt.icon as type_icon
     FROM missions m
     LEFT JOIN departments d ON m.department_id = d.id
     LEFT JOIN users u ON m.created_by = u.id
     LEFT JOIN users r ON m.responsible_user_id = r.id
     LEFT JOIN mission_types mt ON m.mission_type_id = mt.id
     WHERE m.id = ?",
    [$id]
);

if (!$mission) {
    setFlash('error', 'Η αποστολή δεν βρέθηκε.');
    redirect('missions.php');
}

$pageTitle = $mission['title'];
$user = getCurrentUser();

// Τ.Ε.Π.: αποκλεισμός για μη-δόκιμους / μη-admins που δεν είναι υπεύθυνοι αποστολής
if (isTepMission((int)($mission['mission_type_id'] ?? 0)) && !canSeeTep($mission['responsible_user_id'])) {
    setFlash('error', 'Δεν έχετε πρόσβαση σε αποστολές Τ.Ε.Π.');
    redirect('missions.php');
}

// Get shifts
$shifts = dbFetchAll(
    "SELECT s.*,
            (SELECT COUNT(*) FROM participation_requests WHERE shift_id = s.id AND status = '" . PARTICIPATION_APPROVED . "') as approved_count,
            (SELECT COUNT(*) FROM participation_requests WHERE shift_id = s.id AND status = '" . PARTICIPATION_PENDING . "') as pending_count
     FROM shifts s
     WHERE s.mission_id = ?
     ORDER BY s.start_time ASC",
    [$id]
);

// Check if current user has applied to any shift
$userParticipations = [];
if (!isAdmin()) {
    $userParticipations = dbFetchAll(
        "SELECT shift_id, status FROM participation_requests WHERE volunteer_id = ? AND shift_id IN (SELECT id FROM shifts WHERE mission_id = ?)",
        [$user['id'], $id]
    );
    $userParticipations = array_column($userParticipations, 'status', 'shift_id');
}

// Check if user has approved participation (for chat access)
$isApprovedParticipant = false;
if (!isAdmin()) {
    $isApprovedParticipant = dbFetchValue(
        "SELECT COUNT(*) FROM participation_requests pr
         INNER JOIN shifts s ON pr.shift_id = s.id
         WHERE s.mission_id = ? AND pr.volunteer_id = ? AND pr.status = ?",
        [$id, $user['id'], PARTICIPATION_APPROVED]
    ) > 0;
}
$canAccessChat = isAdmin() || $isApprovedParticipant;

// Get chat messages if user has access
$chatMessages = [];
if ($canAccessChat) {
    $chatMessages = dbFetchAll(
        "SELECT m.*, u.name as user_name 
         FROM mission_chat_messages m
         INNER JOIN users u ON m.user_id = u.id
         WHERE m.mission_id = ?
         ORDER BY m.created_at ASC",
        [$id]
    );
}

// Get available volunteers for admin manual add
$availableVolunteers = [];
if (isAdmin()) {
    $availableVolunteers = dbFetchAll(
        "SELECT id, name, email, role FROM users 
         WHERE is_active = 1
         ORDER BY name"
    );
}

// Get debrief if it exists (even if status is not completed yet, e.g. reopened)
$debrief = dbFetchOne(
    "SELECT md.*, COALESCE(u.name, 'Άγνωστος') as submitter_name 
     FROM mission_debriefs md
     LEFT JOIN users u ON md.submitted_by = u.id
     WHERE md.mission_id = ?",
    [$id]
);

// Handle actions
if (isPost()) {
    verifyCsrf();
    $action = post('action');
    
    switch ($action) {
        case 'delete_chat_message':
            if (isAdmin() && $canAccessChat) {
                $messageId = post('message_id');
                if ($messageId) {
                    dbExecute("DELETE FROM mission_chat_messages WHERE id = ? AND mission_id = ?", [$messageId, $id]);
                    setFlash('success', 'Το μήνυμα διαγράφηκε.');
                }
                redirect('mission-view.php?id=' . $id . '#chat');
            }
            break;
            
        case 'send_chat_message':
            if ($canAccessChat) {
                $message = trim(post('message'));
                if (!empty($message)) {
                    dbInsert(
                        "INSERT INTO mission_chat_messages (mission_id, user_id, message) VALUES (?, ?, ?)",
                        [$id, $user['id'], $message]
                    );
                    setFlash('success', 'Το μήνυμα στάλθηκε.');
                }
                redirect('mission-view.php?id=' . $id . '#chat');
            }
            break;
            
        case 'resend_email':
            if (isAdmin() && $mission['status'] === STATUS_OPEN) {
                $allUsers = dbFetchAll(
                    "SELECT id, name, email FROM users WHERE is_active = 1 AND deleted_at IS NULL"
                );
                $missionUrl = rtrim(BASE_URL, '/') . '/mission-view.php?id=' . $id;
                $appName = getSetting('app_name', 'VolunteerOps');
                $sent = 0;
                
                $userIds = array_column($allUsers, 'id');
                if (!empty($userIds)) {
                    sendBulkNotifications(
                        $userIds,
                        'Υπενθύμιση Αποστολής: ' . $mission['title'],
                        'Η αποστολή είναι ακόμα ανοιχτή και αναζητά εθελοντές. Δείτε τις διαθέσιμες βάρδιες.'
                    );
                }
                
                foreach ($allUsers as $v) {
                    if (!empty($v['email'])) {
                        sendNotificationEmail('mission_reminder', $v['email'], [
                            'user_name'           => $v['name'],
                            'mission_title'       => $mission['title'],
                            'mission_description' => $mission['description'] ?? '',
                            'mission_url'         => $missionUrl,
                            'app_name'            => $appName,
                        ]);
                        $sent++;
                    }
                }
                logAudit('resend_mission_email', 'missions', $id);
                setFlash('success', 'Στάλθηκε υπενθύμιση σε ' . $sent . ' χρήστες.');
            }
            break;

        case 'publish':
            if (isAdmin() && $mission['status'] === STATUS_DRAFT) {
                dbExecute("UPDATE missions SET status = ?, updated_at = NOW() WHERE id = ?", [STATUS_OPEN, $id]);
                logAudit('publish', 'missions', $id);

                // Auto-create a shift if none exist
                $shiftCount = (int) dbFetchValue("SELECT COUNT(*) FROM shifts WHERE mission_id = ?", [$id]);
                if ($shiftCount === 0) {
                    dbInsert(
                        "INSERT INTO shifts (mission_id, start_time, end_time, max_volunteers, min_volunteers, created_at, updated_at)
                         VALUES (?, ?, ?, 5, 1, NOW(), NOW())",
                        [$id, $mission['start_datetime'], $mission['end_datetime']]
                    );
                    logAudit('auto_create_shift', 'shifts', $id, 'Αυτόματη δημιουργία βαρδίας κατά τη δημοσίευση');
                }

                // Notify volunteers based on targeting selection
                if (isset($_POST['notify_volunteers'])) {
                    $notifyTarget = post('notify_target', 'all');
                    $whereFilter  = "is_active = 1 AND deleted_at IS NULL";
                    $filterParams = [];

                    if ($notifyTarget === 'roles' && !empty($_POST['notify_roles'])) {
                        $allowedRoles = [ROLE_SYSTEM_ADMIN, ROLE_DEPARTMENT_ADMIN, ROLE_SHIFT_LEADER, ROLE_VOLUNTEER];
                        $roles = array_values(array_filter((array)$_POST['notify_roles'], fn($r) => in_array($r, $allowedRoles)));
                        if (!empty($roles)) {
                            $placeholders  = implode(',', array_fill(0, count($roles), '?'));
                            $whereFilter  .= " AND role IN ($placeholders)";
                            $filterParams  = $roles;
                        }
                    } elseif ($notifyTarget === 'vtypes' && !empty($_POST['notify_vtypes'])) {
                        $allowedVtypes = [VTYPE_VOLUNTEER, VTYPE_TRAINEE, VTYPE_RESCUER];
                        $vtypes = array_values(array_filter((array)$_POST['notify_vtypes'], fn($v) => in_array($v, $allowedVtypes)));
                        if (!empty($vtypes)) {
                            $placeholders  = implode(',', array_fill(0, count($vtypes), '?'));
                            $whereFilter  .= " AND volunteer_type IN ($placeholders)";
                            $filterParams  = $vtypes;
                        }
                    }

                    $volunteers = dbFetchAll(
                        "SELECT id, name, email FROM users WHERE $whereFilter",
                        $filterParams
                    );
                    $missionUrl = rtrim(BASE_URL, '/') . '/mission-view.php?id=' . $id;
                    $appName = getSetting('app_name', 'VolunteerOps');

                    // Human-readable label for flash message
                    $targetLabel = 'όλους τους χρήστες';
                    if ($notifyTarget === 'roles' && !empty($roles ?? [])) {
                        $roleLabels = array_map(fn($r) => ROLE_LABELS[$r] ?? $r, $roles);
                        $targetLabel = implode(', ', $roleLabels);
                    } elseif ($notifyTarget === 'vtypes' && !empty($vtypes ?? [])) {
                        $vtypeLabels = array_map(fn($v) => VOLUNTEER_TYPE_LABELS[$v] ?? $v, $vtypes);
                        $targetLabel = implode(', ', $vtypeLabels);
                    }
                    
                    $userIds = array_column($volunteers, 'id');
                    if (!empty($userIds)) {
                        sendBulkNotifications(
                            $userIds,
                            'Νέα Αποστολή: ' . $mission['title'],
                            'Μια νέα αποστολή δημοσιεύτηκε και αναζητά εθελοντές. Δείτε τις διαθέσιμες βάρδιες.'
                        );
                    }
                    
                    $sent = 0; $failed = 0; $lastError = '';
                    foreach ($volunteers as $v) {
                        if (!empty($v['email'])) {
                            $result = sendNotificationEmail('new_mission', $v['email'], [
                                'user_name'           => $v['name'],
                                'mission_title'       => $mission['title'],
                                'mission_description' => $mission['description'] ?? '',
                                'location'            => $mission['location'] ?? 'Θα ανακοινωθεί',
                                'start_date'          => formatDate($mission['start_datetime']),
                                'end_date'            => formatDate($mission['end_datetime']),
                                'mission_url'         => $missionUrl,
                                'app_name'            => $appName,
                            ]);
                            if ($result['success']) {
                                $sent++;
                            } else {
                                $failed++;
                                $lastError = $result['message'];
                            }
                        }
                    }
                    if ($failed > 0) {
                        logAudit('email_send_error', 'missions', $id, 'Sent:' . $sent . ' Failed:' . $failed . ' Error:' . $lastError);
                        setFlash('warning', 'Η αποστολή δημοσιεύτηκε. Emails: ' . $sent . ' εστάλησαν σε (' . $targetLabel . '), ' . $failed . ' απέτυχαν (δείτε Audit Log).');
                    } else {
                        setFlash('success', 'Η αποστολή δημοσιεύτηκε και στάλθηκε email σε ' . $sent . ' χρήστες (' . $targetLabel . ').');
                    }
                } else {
                    setFlash('success', 'Η αποστολή δημοσιεύτηκε.');
                }

                redirect('mission-view.php?id=' . $id);
            }
            break;
            
        case 'close':
            if (isAdmin() && $mission['status'] === STATUS_OPEN) {
                dbExecute("UPDATE missions SET status = ?, updated_at = NOW() WHERE id = ?", [STATUS_CLOSED, $id]);
                logAudit('close', 'missions', $id);
                setFlash('success', 'Η αποστολή έκλεισε.');
                redirect('mission-view.php?id=' . $id);
            }
            break;
            
        case 'complete':
            if (isAdmin() && $mission['status'] === STATUS_CLOSED) {
                // Redirect to debrief form instead of completing immediately
                redirect('mission-debrief.php?id=' . $id);
            }
            break;
            
        case 'complete_only':
            if (isAdmin() && $mission['status'] === STATUS_CLOSED) {
                dbExecute("UPDATE missions SET status = ?, updated_at = NOW() WHERE id = ?", [STATUS_COMPLETED, $id]);
                logAudit('complete_only', 'missions', $id);
                setFlash('success', 'Η αποστολή ολοκληρώθηκε (χωρίς αλλαγή στην αναφορά).');
                redirect('mission-view.php?id=' . $id);
            }
            break;
            
        case 'reopen_to_closed':
            if (isAdmin() && $mission['status'] === STATUS_COMPLETED) {
                dbExecute("UPDATE missions SET status = ?, updated_at = NOW() WHERE id = ?", [STATUS_CLOSED, $id]);
                logAudit('reopen_to_closed', 'missions', $id);
                setFlash('success', 'Η αποστολή επέστρεψε σε «Κλειστή». Μπορείτε να αλλάξετε παρουσίες και εθελοντές.');
                redirect('mission-view.php?id=' . $id);
            }
            break;
            
        case 'cancel':
            if (isAdmin() && in_array($mission['status'], [STATUS_DRAFT, STATUS_OPEN])) {
                $reason = post('cancellation_reason');
                
                db()->beginTransaction();
                try {
                    dbExecute(
                        "UPDATE missions SET status = ?, cancellation_reason = ?, canceled_by = ?, canceled_at = NOW(), updated_at = NOW() WHERE id = ?",
                        [STATUS_CANCELED, $reason, $user['id'], $id]
                    );
                    logAudit('cancel', 'missions', $id, null, ['reason' => $reason]);

                    // Notify all volunteers with PENDING or APPROVED participation in any shift
                    $cancelShifts = dbFetchAll("SELECT id FROM shifts WHERE mission_id = ?", [$id]);
                    $cancelShiftIds = array_column($cancelShifts, 'id');
                    $notifiedCancel = [];
                    if (!empty($cancelShiftIds)) {
                        $ph = implode(',', array_fill(0, count($cancelShiftIds), '?'));
                        $cancelParticipants = dbFetchAll(
                            "SELECT DISTINCT pr.volunteer_id, u.name, u.email
                             FROM participation_requests pr
                             JOIN users u ON pr.volunteer_id = u.id
                             WHERE pr.shift_id IN ($ph) AND pr.status IN ('PENDING','APPROVED')",
                            $cancelShiftIds
                        );
                        
                        $userIds = array_column($cancelParticipants, 'volunteer_id');
                        if (!empty($userIds)) {
                            sendBulkNotifications(
                                $userIds,
                                'Ακύρωση Αποστολής: ' . $mission['title'],
                                'Η αποστολή ακυρώθηκε' . ($reason ? '. Λόγος: ' . $reason : '') . '. Οι αιτήσεις σας ακυρώθηκαν αυτόματα.'
                            );
                        }
                        
                        foreach ($cancelParticipants as $cp) {
                            if (in_array($cp['volunteer_id'], $notifiedCancel)) continue;
                            $notifiedCancel[] = $cp['volunteer_id'];
                            // Email
                            if (!empty($cp['email'])) {
                                sendNotificationEmail('mission_canceled', $cp['email'], [
                                    'user_name'     => $cp['name'],
                                    'mission_title' => $mission['title'],
                                    'reason'        => $reason ?: 'Δεν δόθηκε αιτιολογία.',
                                ]);
                            }
                        }
                    }
                    
                    db()->commit();
                    
                    $msg = 'Η αποστολή ακυρώθηκε.';
                    if (!empty($notifiedCancel)) {
                        $msg .= ' Ειδοποιήθηκαν ' . count($notifiedCancel) . ' εθελοντές.';
                    }
                    setFlash('success', $msg);
                } catch (Exception $e) {
                    db()->rollBack();
                    setFlash('error', 'Σφάλμα κατά την ακύρωση της αποστολής.');
                }
                redirect('missions.php');
            }
            break;
            
        case 'delete':
            if (isAdmin()) {
                // Get all shifts for this mission
                $missionShifts = dbFetchAll("SELECT id FROM shifts WHERE mission_id = ?", [$id]);
                $shiftIds = array_column($missionShifts, 'id');
                $notifiedUsers = [];

                db()->beginTransaction();
                try {
                    if (!empty($shiftIds)) {
                        // Get all affected participants (PENDING or APPROVED)
                        $placeholders = implode(',', array_fill(0, count($shiftIds), '?'));
                        $affectedParticipants = dbFetchAll(
                            "SELECT DISTINCT pr.volunteer_id, u.name, u.email
                             FROM participation_requests pr 
                             JOIN users u ON pr.volunteer_id = u.id 
                             WHERE pr.shift_id IN ($placeholders) AND pr.status IN ('PENDING', 'APPROVED')",
                            $shiftIds
                        );
                        
                        // Bulk in-app notification
                        $notifiedUsers = array_column($affectedParticipants, 'volunteer_id');
                        if (!empty($notifiedUsers)) {
                            sendBulkNotifications(
                                $notifiedUsers,
                                'Διαγραφή Αποστολής: ' . $mission['title'],
                                'Η αποστολή "' . $mission['title'] . '" διαγράφηκε. Όλες οι αιτήσεις σας ακυρώθηκαν αυτόματα.'
                            );
                        }
                        
                        // Email each affected volunteer
                        foreach ($affectedParticipants as $participant) {
                            if (!empty($participant['email'])) {
                                sendNotificationEmail('mission_canceled', $participant['email'], [
                                    'user_name'     => $participant['name'],
                                    'mission_title' => $mission['title'],
                                    'reason'        => 'Η αποστολή διαγράφηκε από διαχειριστή.',
                                ]);
                            }
                        }
                        
                        // Delete participation requests
                        dbExecute("DELETE FROM participation_requests WHERE shift_id IN ($placeholders)", $shiftIds);
                        
                        // Delete shifts
                        dbExecute("DELETE FROM shifts WHERE mission_id = ?", [$id]);
                    }
                    
                    // Soft delete mission
                    dbExecute("UPDATE missions SET deleted_at = NOW(), updated_at = NOW() WHERE id = ?", [$id]);
                    logAudit('delete', 'missions', $id, 'Notified ' . count($notifiedUsers) . ' volunteers');
                    
                    db()->commit();
                    
                    $msg = 'Η αποστολή διαγράφηκε.';
                    if (!empty($notifiedUsers)) {
                        $msg .= ' Ειδοποιήθηκαν ' . count($notifiedUsers) . ' εθελοντές.';
                    }
                    setFlash('success', $msg);
                } catch (Exception $e) {
                    db()->rollBack();
                    setFlash('error', 'Σφάλμα κατά τη διαγραφή της αποστολής.');
                }
                redirect('missions.php');
            }
            break;
            
        case 'apply':
            $shiftId = post('shift_id');
            $volunteerNotes = post('volunteer_notes');
            // Block volunteer apply if mission has expired
            $missionExpired = in_array($mission['status'], [STATUS_OPEN, STATUS_CLOSED]) && strtotime($mission['end_datetime']) < time();
            if ($missionExpired && !isAdmin()) {
                setFlash('error', 'Η αποστολή είναι ακόμα ανοιχτή αλλά ο χρόνος διεξαγωγής έχει παρέλθει. Δεν μπορείτε να υποβάλετε αίτηση.');
                redirect('mission-view.php?id=' . $id);
            }
            if ($shiftId && !isAdmin()) {
                // Check if already applied
                $existing = dbFetchValue(
                    "SELECT COUNT(*) FROM participation_requests WHERE volunteer_id = ? AND shift_id = ?",
                    [$user['id'], $shiftId]
                );
                
                if (!$existing) {
                    dbInsert(
                        "INSERT INTO participation_requests (volunteer_id, shift_id, status, notes, created_at, updated_at) VALUES (?, ?, ?, ?, NOW(), NOW())",
                        [$user['id'], $shiftId, PARTICIPATION_PENDING, $volunteerNotes ?: null]
                    );
                    logAudit('apply', 'participation_requests', null, null, ['shift_id' => $shiftId]);
                    setFlash('success', 'Η αίτησή σας υποβλήθηκε.');
                } else {
                    setFlash('error', 'Έχετε ήδη υποβάλει αίτηση για αυτή τη βάρδια.');
                }
                redirect('mission-view.php?id=' . $id);
            }
            break;
            
        case 'manual_add_volunteer':
            if (isAdmin() && $mission['status'] !== STATUS_COMPLETED) {
                $shiftIds = post('shift_ids', []); // Array of shift IDs
                $volunteerId = post('volunteer_id');
                $adminNotes = post('admin_notes');
                
                if (!empty($shiftIds) && $volunteerId) {
                    $addedCount = 0;
                    $skippedCount = 0;
                    
                    // Get volunteer info once
                    $volunteer = dbFetchOne("SELECT name, email FROM users WHERE id = ?", [$volunteerId]);
                    
                    foreach ($shiftIds as $shiftId) {
                        // Check if already has an active (PENDING/APPROVED) participation
                        $existingPr = dbFetchOne(
                            "SELECT id, status FROM participation_requests WHERE volunteer_id = ? AND shift_id = ?",
                            [$volunteerId, $shiftId]
                        );
                        
                        if ($existingPr && in_array($existingPr['status'], [PARTICIPATION_PENDING, PARTICIPATION_APPROVED])) {
                            // Already active — skip
                            $skippedCount++;
                        } else {
                            if ($existingPr) {
                                // Reactivate existing rejected/canceled record
                                dbExecute(
                                    "UPDATE participation_requests 
                                     SET status = ?, rejection_reason = NULL, admin_notes = ?, 
                                         decided_by = ?, decided_at = NOW(), updated_at = NOW() 
                                     WHERE id = ?",
                                    [PARTICIPATION_APPROVED, $adminNotes, $user['id'], $existingPr['id']]
                                );
                                $prId = $existingPr['id'];
                            } else {
                                // Insert new record
                                $prId = dbInsert(
                                    "INSERT INTO participation_requests 
                                     (volunteer_id, shift_id, status, admin_notes, decided_by, decided_at, created_at, updated_at) 
                                     VALUES (?, ?, ?, ?, ?, NOW(), NOW(), NOW())",
                                    [$volunteerId, $shiftId, PARTICIPATION_APPROVED, $adminNotes, $user['id']]
                                );
                            }
                            
                            // Get shift info with mission details
                            $shift = dbFetchOne(
                                "SELECT s.*, m.title as mission_title, m.location 
                                 FROM shifts s 
                                 JOIN missions m ON s.mission_id = m.id 
                                 WHERE s.id = ?",
                                [$shiftId]
                            );
                            
                            // Send notification email
                            if ($volunteer && !empty($volunteer['email']) && isNotificationEnabled('admin_added_volunteer')) {
                                $gcalLink = buildGcalLink($shift['mission_title'], $shift['start_time'], $shift['end_time'], $shift['location'] ?: '');
                                sendNotificationEmail(
                                    'admin_added_volunteer',
                                    $volunteer['email'],
                                    [
                                        'user_name'     => $volunteer['name'],
                                        'mission_title' => $shift['mission_title'],
                                        'shift_date'    => formatDateTime($shift['start_time'], 'd/m/Y'),
                                        'shift_time'    => formatDateTime($shift['start_time'], 'H:i') . ' - ' . formatDateTime($shift['end_time'], 'H:i'),
                                        'location'      => $shift['location'] ?: 'Θα ανακοινωθεί',
                                        'admin_notes'   => $adminNotes ?: 'Προστεθήκατε από τον διαχειριστή.',
                                        'gcal_link'     => $gcalLink,
                                    ]
                                );
                            }
                            
                            // Send in-app notification
                            sendNotification(
                                $volunteerId,
                                'Τοποθετήθηκατε σε βάρδια',
                                'Ο διαχειριστής σας τοποθέτησε στη βάρδια: ' . $shift['mission_title'] . ' - ' . formatDateTime($shift['start_time'])
                            );
                            
                            logAudit('manual_add_volunteer', 'participation_requests', $prId);
                            $addedCount++;
                        }
                    }
                    
                    $message = '';
                    if ($addedCount > 0) {
                        $message = "Ο εθελοντής προστέθηκε σε {$addedCount} βάρδια/ες.";
                    }
                    if ($skippedCount > 0) {
                        $message .= " {$skippedCount} βάρδια/ες παραλείφθηκαν (ήδη συμμετέχει).";
                    }
                    
                    setFlash($addedCount > 0 ? 'success' : 'warning', $message);
                }
                redirect('mission-view.php?id=' . $id);
            }
            break;
    }
}

// Detect overdue mission (past end date but not closed/completed/canceled)
$isOverdue = in_array($mission['status'], [STATUS_OPEN, STATUS_CLOSED]) 
    && strtotime($mission['end_datetime']) < time();

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-0">
            <?= h($mission['title']) ?>
            <?php if (!empty($mission['type_name'])): ?>
                <span class="badge bg-<?= h($mission['type_color'] ?? 'secondary') ?>">
                    <i class="bi <?= h($mission['type_icon'] ?? 'bi-flag') ?>"></i>
                    <?= h($mission['type_name']) ?>
                </span>
            <?php endif; ?>
            <?php if ($mission['is_urgent']): ?>
                <span class="badge bg-danger">Επείγον</span>
            <?php endif; ?>
        </h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="missions.php">Αποστολές</a></li>
                <li class="breadcrumb-item active"><?= h($mission['title']) ?></li>
            </ol>
        </nav>
    </div>
    <div>
        <?php if (isAdmin()): ?>
            <a href="mission-form.php?id=<?= $mission['id'] ?>" class="btn btn-outline-primary">
                <i class="bi bi-pencil me-1"></i>Επεξεργασία
            </a>
        <?php endif; ?>
    </div>
</div>

<?php if ($isOverdue && !isAdmin()): ?>
<div class="alert alert-warning d-flex align-items-center gap-2 mb-4">
    <i class="bi bi-clock-history fs-4 text-warning"></i>
    <div>
        Η αποστολή είναι ακόμα ανοιχτή αλλά <strong>ο χρόνος διεξαγωγής έχει παρέλθει</strong>.
        Δεν μπορείτε να υποβάλετε αίτηση συμμετοχής.
    </div>
</div>
<?php endif; ?>

<?php if ($isOverdue && isAdmin()):
    $elapsed = time() - strtotime($mission['end_datetime']);
    $days = floor($elapsed / 86400);
    $hours = floor(($elapsed % 86400) / 3600);
    $elapsedText = '';
    if ($days > 0) $elapsedText .= $days . ' μέρ' . ($days == 1 ? 'α' : 'ες');
    if ($hours > 0) $elapsedText .= ($days > 0 ? ' και ' : '') . $hours . ' ώρ' . ($hours == 1 ? 'α' : 'ες');
    if (!$elapsedText) $elapsedText = 'Μόλις τώρα';
?>
<div class="alert alert-danger d-flex align-items-center gap-2 mb-4 shadow-sm border-danger" style="border-left: 5px solid #dc3545 !important;">
    <i class="bi bi-exclamation-triangle-fill fs-4 text-danger"></i>
    <div>
        <strong>Η αποστολή έχει λήξει!</strong>
        Η ημερομηνία λήξης (<?= formatDateTime($mission['end_datetime']) ?>) έχει παρέλθει — <strong class="text-danger">πριν <?= h($elapsedText) ?></strong>.
        <?php if ($mission['status'] === STATUS_OPEN): ?>
            Παρακαλώ <strong>κλείστε</strong> την αποστολή και στη συνέχεια <strong>ολοκληρώστε</strong> την.
        <?php else: ?>
            Παρακαλώ <strong>ολοκληρώστε</strong> την αποστολή.
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($mission['status'] === STATUS_COMPLETED && isAdmin()): ?>
<div class="alert alert-info d-flex align-items-center gap-2 mb-4">
    <i class="bi bi-lock-fill fs-5"></i>
    <div>
        <strong>Η αποστολή είναι ολοκληρωμένη.</strong>
        Δεν μπορείτε να προσθέσετε εθελοντές ή να αλλάξετε παρουσίες. Αλλάξτε την κατάσταση σε «Κλειστή» για αλλαγές.
    </div>
</div>
<?php endif; ?>

<div class="row">
    <div class="col-lg-8">
        <!-- Mission Details -->
        <?php
        $startDt = new DateTime($mission['start_datetime']);
        $endDt   = new DateTime($mission['end_datetime']);
        $diff    = $startDt->diff($endDt);
        $totalHours = round(($diff->days * 24) + $diff->h + ($diff->i / 60), 1);
        ?>
        <div class="card mb-4" style="border:2px solid #0d6efd;border-radius:10px;overflow:hidden">
            <div class="card-header d-flex justify-content-between align-items-center py-2" style="background:linear-gradient(135deg,#dbeafe 0%,#eff6ff 100%);border-bottom:2px solid #0d6efd">
                <h6 class="mb-0 fw-bold text-dark"><i class="bi bi-info-circle-fill me-2 text-primary"></i>Λεπτομέρειες</h6>
                <?= statusBadge($mission['status']) ?>
            </div>
            <div class="card-body py-3 px-3">

                <?php if ($mission['status'] === STATUS_CANCELED && $mission['cancellation_reason']): ?>
                    <div class="alert alert-danger py-2 mb-3">
                        <i class="bi bi-x-circle-fill me-1"></i>
                        <strong>Λόγος Ακύρωσης:</strong> <?= h($mission['cancellation_reason']) ?>
                    </div>
                <?php endif; ?>

                <?php if ($mission['description']): ?>
                    <div class="mb-3 p-2 rounded-2 text-dark" style="background:#f0f6ff;border:1px solid #bfdbfe;font-size:.93rem">
                        <?= nl2br(h($mission['description'])) ?>
                    </div>
                <?php endif; ?>

                <!-- Info grid -->
                <div class="row g-2 mb-3">
                    <!-- Location -->
                    <div class="col-sm-6">
                        <div class="d-flex align-items-start gap-2 p-2 rounded-2 h-100" style="background:#f8faff;border:1px solid #dbeafe">
                            <div class="text-primary mt-1" style="font-size:1.1rem"><i class="bi bi-geo-alt-fill"></i></div>
                            <div>
                                <div class="text-muted small fw-semibold text-uppercase" style="letter-spacing:.05em;font-size:.72rem">Τοποθεσία</div>
                                <div class="fw-semibold text-dark" style="font-size:.9rem"><?= h($mission['location']) ?></div>
                                <?php if ($mission['location_details']): ?>
                                    <div class="text-muted" style="font-size:.8rem"><?= h($mission['location_details']) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <!-- Department -->
                    <div class="col-sm-6">
                        <div class="d-flex align-items-start gap-2 p-2 rounded-2 h-100" style="background:#f8faff;border:1px solid #dbeafe">
                            <div class="text-primary mt-1" style="font-size:1.1rem"><i class="bi bi-building-fill"></i></div>
                            <div>
                                <div class="text-muted small fw-semibold text-uppercase" style="letter-spacing:.05em;font-size:.72rem">Τμήμα</div>
                                <div class="fw-semibold text-dark" style="font-size:.9rem"><?= h($mission['department_name'] ?? '-') ?></div>
                            </div>
                        </div>
                    </div>
                    <!-- Type -->
                    <div class="col-sm-6">
                        <div class="d-flex align-items-start gap-2 p-2 rounded-2 h-100" style="background:#f8faff;border:1px solid #dbeafe">
                            <div class="text-primary mt-1" style="font-size:1.1rem"><i class="bi bi-tag-fill"></i></div>
                            <div>
                                <div class="text-muted small fw-semibold text-uppercase" style="letter-spacing:.05em;font-size:.72rem">Τύπος</div>
                                <div class="fw-semibold text-dark" style="font-size:.9rem"><?= h($mission['type_name'] ?? $mission['type'] ?? 'Άγνωστος') ?></div>
                            </div>
                        </div>
                    </div>
                    <!-- Creator -->
                    <div class="col-sm-6">
                        <div class="d-flex align-items-start gap-2 p-2 rounded-2 h-100" style="background:#f8faff;border:1px solid #dbeafe">
                            <div class="text-primary mt-1" style="font-size:1.1rem"><i class="bi bi-person-fill"></i></div>
                            <div>
                                <div class="text-muted small fw-semibold text-uppercase" style="letter-spacing:.05em;font-size:.72rem">Δημιουργός</div>
                                <div class="fw-semibold text-dark" style="font-size:.9rem"><?= h($mission['creator_name'] ?? '-') ?></div>
                            </div>
                        </div>
                    </div>
                    <!-- Start datetime -->
                    <div class="col-sm-6">
                        <div class="d-flex align-items-start gap-2 p-2 rounded-2 h-100" style="background:#f0fdf4;border:1px solid #bbf7d0">
                            <div class="text-success mt-1" style="font-size:1.1rem"><i class="bi bi-calendar-event-fill"></i></div>
                            <div>
                                <div class="text-muted small fw-semibold text-uppercase" style="letter-spacing:.05em;font-size:.72rem">Έναρξη</div>
                                <div class="fw-semibold text-dark" style="font-size:.9rem"><?= formatDateGreek($mission['start_datetime']) ?></div>
                                <div class="text-success fw-bold" style="font-size:.9rem"><?= formatDateTime($mission['start_datetime'], 'H:i') ?></div>
                            </div>
                        </div>
                    </div>
                    <!-- End datetime -->
                    <div class="col-sm-6">
                        <div class="d-flex align-items-start gap-2 p-2 rounded-2 h-100" style="background:#fff7ed;border:1px solid #fed7aa">
                            <div class="text-warning mt-1" style="font-size:1.1rem"><i class="bi bi-calendar-check-fill"></i></div>
                            <div>
                                <div class="text-muted small fw-semibold text-uppercase" style="letter-spacing:.05em;font-size:.72rem">Λήξη</div>
                                <div class="fw-semibold text-dark" style="font-size:.9rem"><?= formatDateGreek($mission['end_datetime']) ?></div>
                                <div class="fw-bold" style="font-size:.9rem;color:#d97706"><?= formatDateTime($mission['end_datetime'], 'H:i') ?></div>
                            </div>
                        </div>
                    </div>
                    <?php if ($mission['responsible_user_id']): ?>
                    <!-- Responsible -->
                    <div class="col-sm-6">
                        <div class="d-flex align-items-start gap-2 p-2 rounded-2 h-100" style="background:#fefce8;border:1px solid #fde68a">
                            <div class="mt-1" style="font-size:1.1rem;color:#d97706"><i class="bi bi-star-fill"></i></div>
                            <div>
                                <div class="text-muted small fw-semibold text-uppercase" style="letter-spacing:.05em;font-size:.72rem">Υπεύθυνος</div>
                                <span class="badge bg-warning text-dark mt-1"><?= h($mission['responsible_name']) ?></span>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Hours banner -->
                <div class="d-flex align-items-center justify-content-center gap-2 rounded-2 py-2 px-3" style="background:linear-gradient(90deg,#0d6efd18 0%,#0d6efd0a 100%);border:1.5px solid #bfdbfe">
                    <i class="bi bi-clock-fill text-primary" style="font-size:1.1rem"></i>
                    <span class="fw-bold text-primary" style="font-size:1rem">Διάρκεια Αποστολής:</span>
                    <span class="badge bg-primary px-3 py-2" style="font-size:.95rem"><?= $totalHours ?> ώρες</span>
                </div>

                <?php if ($mission['requirements']): ?>
                    <div class="mt-3">
                        <div class="text-muted small fw-semibold mb-1 text-uppercase" style="letter-spacing:.05em"><i class="bi bi-list-check me-1"></i>Απαιτήσεις</div>
                        <div class="p-2 rounded-2 text-dark" style="background:#f8faff;border:1px solid #dbeafe;font-size:.93rem">
                            <?= nl2br(h($mission['requirements'])) ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($mission['notes']): ?>
                    <div class="mt-3">
                        <div class="text-muted small fw-semibold mb-1 text-uppercase" style="letter-spacing:.05em"><i class="bi bi-sticky-fill me-1"></i>Σημειώσεις</div>
                        <div class="p-2 rounded-2 text-dark" style="background:#fffdf0;border-left:3px solid #fbbf24;font-size:.93rem">
                            <?= nl2br(h($mission['notes'])) ?>
                        </div>
                    </div>
                <?php endif; ?>

            </div>
        </div>
        
        <?php if ($debrief): ?>
        <!-- Mission Debrief -->
        <div class="card mb-4" style="border:2px solid #198754;border-radius:10px;overflow:hidden">
            <div class="card-header d-flex justify-content-between align-items-center py-2" style="background:linear-gradient(135deg,#d1f0e0 0%,#eafaf1 100%);border-bottom:2px solid #198754">
                <h6 class="mb-0 fw-bold text-dark"><i class="bi bi-clipboard-check-fill me-2 text-success"></i>Αναφορά Μετά την Αποστολή (Debrief)</h6>
                <span class="badge text-dark fw-normal" style="background:#b7e4c7;font-size:0.78rem"><i class="bi bi-person-fill me-1"></i><?= h($debrief['submitter_name']) ?></span>
            </div>
            <div class="card-body py-3 px-3">
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <div class="p-2 rounded-2 text-center" style="background:#f0fdf4;border:1px solid #bbf7d0">
                            <div class="text-muted small mb-1">Επίτευξη Στόχων</div>
                            <?php if ($debrief['objectives_met'] === 'YES'): ?>
                                <span class="badge bg-success px-2 py-1"><i class="bi bi-check-circle-fill me-1"></i>Πλήρως</span>
                            <?php elseif ($debrief['objectives_met'] === 'PARTIAL'): ?>
                                <span class="badge bg-warning text-dark px-2 py-1"><i class="bi bi-exclamation-circle-fill me-1"></i>Μερικώς</span>
                            <?php else: ?>
                                <span class="badge bg-danger px-2 py-1"><i class="bi bi-x-circle-fill me-1"></i>Όχι</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="p-2 rounded-2 text-center" style="background:#f0fdf4;border:1px solid #bbf7d0">
                            <div class="text-muted small mb-1">Αξιολόγηση</div>
                            <span class="text-warning fw-bold" style="font-size:1rem;letter-spacing:1px"><?= str_repeat('★', $debrief['rating']) ?><span style="opacity:.3"><?= str_repeat('★', 5 - $debrief['rating']) ?></span></span>
                            <span class="text-muted small ms-1"><?= $debrief['rating'] ?>/5</span>
                        </div>
                    </div>
                </div>

                <div class="mb-2">
                    <div class="text-muted small fw-semibold mb-1 text-uppercase" style="letter-spacing:.05em"><i class="bi bi-text-paragraph me-1"></i>Σύνοψη</div>
                    <div class="p-2 rounded-2 text-dark" style="background:#f8fffe;border:1px solid #d1f0e0;font-size:.93rem">
                        <?= nl2br(h($debrief['summary'])) ?>
                    </div>
                </div>

                <?php if ($debrief['incidents']): ?>
                <div class="mb-2">
                    <div class="text-danger small fw-semibold mb-1 text-uppercase" style="letter-spacing:.05em"><i class="bi bi-exclamation-triangle-fill me-1"></i>Συμβάντα / Ατυχήματα</div>
                    <div class="p-2 rounded-2 text-dark" style="background:#fff5f5;border-left:3px solid #dc3545;font-size:.93rem">
                        <?= nl2br(h($debrief['incidents'])) ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($debrief['equipment_issues']): ?>
                <div class="mb-2">
                    <div class="small fw-semibold mb-1 text-uppercase" style="letter-spacing:.05em;color:#856404"><i class="bi bi-tools me-1"></i>Προβλήματα Εξοπλισμού</div>
                    <div class="p-2 rounded-2 text-dark" style="background:#fffdf0;border-left:3px solid #ffc107;font-size:.93rem">
                        <?= nl2br(h($debrief['equipment_issues'])) ?>
                    </div>
                </div>
                <?php endif; ?>

                <div class="text-muted mt-2" style="font-size:.78rem;text-align:right">
                    <i class="bi bi-clock me-1"></i>Υποβλήθηκε: <?= formatDateTime($debrief['created_at']) ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Shifts -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-calendar3 me-1"></i>Βάρδιες</h5>
                <?php if (isAdmin() && $mission['status'] !== STATUS_COMPLETED): ?>
                    <a href="shift-form.php?mission_id=<?= $mission['id'] ?>" class="btn btn-sm btn-primary">
                        <i class="bi bi-plus-lg"></i> Νέα Βάρδια
                    </a>
                <?php endif; ?>
            </div>
            <div class="card-body p-0">
                <?php if (empty($shifts)): ?>
                    <p class="text-muted text-center py-4">Δεν υπάρχουν βάρδιες.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Ώρες</th>
                                    <th>Θέσεις</th>
                                    <th>Κατάσταση</th>
                                    <th class="text-end">Ενέργειες</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($shifts as $shift): ?>
                                    <tr>
                                        <td>
                                            <strong><?= formatDateTime($shift['start_time'], 'd/m/Y') ?></strong><br>
                                            <small><?= formatDateTime($shift['start_time'], 'H:i') ?> - <?= formatDateTime($shift['end_time'], 'H:i') ?></small>
                                        </td>
                                        <td>
                                            <span class="badge bg-success"><?= $shift['approved_count'] ?></span>
                                            <span class="text-muted">/</span>
                                            <span class="badge bg-secondary"><?= $shift['max_volunteers'] ?></span>
                                            <?php if ($shift['pending_count'] > 0): ?>
                                                <span class="badge bg-warning ms-1"><?= $shift['pending_count'] ?> εκκρεμείς</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php
                                            $isFull = $shift['approved_count'] >= $shift['max_volunteers'];
                                            $isPast = strtotime($shift['end_time']) < time();
                                            
                                            if ($isPast) {
                                                echo '<span class="badge bg-secondary">Ολοκληρώθηκε</span>';
                                            } elseif ($isFull) {
                                                echo '<span class="badge bg-danger">Πλήρης</span>';
                                            } else {
                                                echo '<span class="badge bg-success">Διαθέσιμη</span>';
                                            }
                                            ?>
                                        </td>
                                        <td class="text-end">
                                            <?php if (!isAdmin()): ?>
                                                <?php if (isset($userParticipations[$shift['id']])): ?>
                                                    <?= statusBadge($userParticipations[$shift['id']], 'participation') ?>
                                                <?php elseif (!$isPast && !$isFull && $mission['status'] === STATUS_OPEN && !$isOverdue): ?>
                                                    <button type="button" class="btn btn-sm btn-primary apply-btn" 
                                                            data-shift-id="<?= $shift['id'] ?>"
                                                            data-shift-date="<?= formatDateTime($shift['start_time'], 'd/m/Y H:i') ?>">
                                                        <i class="bi bi-hand-index"></i> Αίτηση
                                                    </button>
                                                <?php elseif ($isOverdue && $mission['status'] === STATUS_OPEN): ?>
                                                    <span class="badge bg-secondary" title="Ο χρόνος διεξαγωγής έχει παρέλθει" data-bs-toggle="tooltip">
                                                        <i class="bi bi-clock-history me-1"></i>Έληξε
                                                    </span>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <a href="shift-view.php?id=<?= $shift['id'] ?>" class="btn btn-sm btn-outline-primary">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                                <a href="shift-form.php?id=<?= $shift['id'] ?>" class="btn btn-sm btn-outline-secondary">
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <div class="col-lg-4">
        <!-- Actions -->
        <?php if (isAdmin()): ?>
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Ενέργειες</h5>
                </div>
                <div class="card-body d-grid gap-2">
                    <?php if ($mission['status'] !== STATUS_COMPLETED): ?>
                    <button type="button" class="btn btn-success w-100" data-bs-toggle="modal" data-bs-target="#addVolunteerModal">
                        <i class="bi bi-person-plus me-1"></i>Προσθήκη Εθελοντή
                    </button>
                    <?php endif; ?>
                    
                    <?php if ($mission['status'] === STATUS_DRAFT): ?>
                        <form method="post" id="publishForm">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="publish">

                            <!-- Notify toggle -->
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="notify_volunteers"
                                       id="notifyVolunteers" value="1" checked
                                       onchange="toggleNotifyPanel(this.checked)">
                                <label class="form-check-label small fw-semibold" for="notifyVolunteers">
                                    <i class="bi bi-envelope me-1"></i>Αποστολή ειδοποίησης Email
                                </label>
                            </div>

                            <!-- Targeting panel (visible when checked) -->
                            <div id="notifyPanel" class="border rounded p-2 mb-3 bg-light small">
                                <div class="mb-2 text-muted fw-semibold">Παραλήπτες:</div>

                                <!-- All -->
                                <div class="form-check mb-1">
                                    <input class="form-check-input" type="radio" name="notify_target"
                                           id="targetAll" value="all" checked
                                           onchange="toggleTargetGroups()">
                                    <label class="form-check-label" for="targetAll">
                                        <i class="bi bi-people me-1 text-primary"></i>Όλοι οι ενεργοί χρήστες
                                    </label>
                                </div>

                                <!-- By Role -->
                                <div class="form-check mb-1">
                                    <input class="form-check-input" type="radio" name="notify_target"
                                           id="targetRoles" value="roles"
                                           onchange="toggleTargetGroups()">
                                    <label class="form-check-label" for="targetRoles">
                                        <i class="bi bi-shield-check me-1 text-success"></i>Ανά Ρόλο
                                    </label>
                                </div>
                                <div id="rolesGroup" class="ps-3 mb-2 d-none">
                                    <div class="form-check form-check-sm">
                                        <input class="form-check-input" type="checkbox" name="notify_roles[]" value="SHIFT_LEADER" id="roleShiftLeader">
                                        <label class="form-check-label" for="roleShiftLeader">Αρχηγοί Βάρδιας</label>
                                    </div>
                                    <div class="form-check form-check-sm">
                                        <input class="form-check-input" type="checkbox" name="notify_roles[]" value="VOLUNTEER" id="roleVolunteer">
                                        <label class="form-check-label" for="roleVolunteer">Εθελοντές</label>
                                    </div>
                                    <div class="form-check form-check-sm">
                                        <input class="form-check-input" type="checkbox" name="notify_roles[]" value="DEPARTMENT_ADMIN" id="roleDeptAdmin">
                                        <label class="form-check-label" for="roleDeptAdmin">Διαχ. Τμήματος</label>
                                    </div>
                                    <div class="form-check form-check-sm">
                                        <input class="form-check-input" type="checkbox" name="notify_roles[]" value="SYSTEM_ADMIN" id="roleSysAdmin">
                                        <label class="form-check-label" for="roleSysAdmin">Διαχ. Συστήματος</label>
                                    </div>
                                </div>

                                <!-- By Volunteer Type -->
                                <div class="form-check mb-1">
                                    <input class="form-check-input" type="radio" name="notify_target"
                                           id="targetVtypes" value="vtypes"
                                           onchange="toggleTargetGroups()">
                                    <label class="form-check-label" for="targetVtypes">
                                        <i class="bi bi-person-badge me-1 text-warning"></i>Ανά Τύπο Εθελοντή
                                    </label>
                                </div>
                                <div id="vtypesGroup" class="ps-3 d-none">
                                    <div class="form-check form-check-sm">
                                        <input class="form-check-input" type="checkbox" name="notify_vtypes[]" value="TRAINEE_RESCUER" id="vtypeTrainee">
                                        <label class="form-check-label" for="vtypeTrainee">Δόκιμοι Διασώστες</label>
                                    </div>
                                    <div class="form-check form-check-sm">
                                        <input class="form-check-input" type="checkbox" name="notify_vtypes[]" value="RESCUER" id="vtypeRescuer">
                                        <label class="form-check-label" for="vtypeRescuer">Εθελοντές Διασώστες</label>
                                    </div>
                                    <div class="form-check form-check-sm">
                                        <input class="form-check-input" type="checkbox" name="notify_vtypes[]" value="VOLUNTEER" id="vtypeVolunteer">
                                        <label class="form-check-label" for="vtypeVolunteer">Εθελοντές</label>
                                    </div>
                                </div>
                            </div>

                            <button type="submit" class="btn btn-success w-100">
                                <i class="bi bi-send me-1"></i>Δημοσίευση
                            </button>
                        </form>
                        <script>
                        function toggleNotifyPanel(checked) {
                            document.getElementById('notifyPanel').style.display = checked ? '' : 'none';
                        }
                        function toggleTargetGroups() {
                            var target = document.querySelector('input[name="notify_target"]:checked').value;
                            document.getElementById('rolesGroup').classList.toggle('d-none', target !== 'roles');
                            document.getElementById('vtypesGroup').classList.toggle('d-none', target !== 'vtypes');
                        }
                        </script>
                    <?php endif; ?>
                    
                    <?php if ($mission['status'] === STATUS_OPEN): ?>
                        <form method="post">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="close">
                            <button type="submit" class="btn btn-warning w-100">
                                <i class="bi bi-lock me-1"></i>Κλείσιμο Αιτήσεων
                            </button>
                        </form>
                        <form method="post">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="resend_email">
                            <button type="submit" class="btn btn-outline-primary w-100">
                                <i class="bi bi-envelope-arrow-up me-1"></i>Επαναποστολή Email
                            </button>
                        </form>
                    <?php endif; ?>
                    
                    <?php if ($mission['status'] === STATUS_CLOSED): ?>
                        <a href="attendance.php?mission_id=<?= $mission['id'] ?>" class="btn btn-info w-100">
                            <i class="bi bi-clipboard-check me-1"></i>Διαχείριση Παρουσιών
                        </a>
                        <?php if ($debrief): ?>
                            <button type="button" class="btn btn-primary w-100" onclick="confirmDebriefEdit()">
                                <i class="bi bi-check-circle me-1"></i>Ολοκλήρωση & Αναφορά
                            </button>
                            <script>
                            function confirmDebriefEdit() {
                                if (confirm('Υπάρχει ήδη αναφορά (debrief) για αυτή την αποστολή.\n\nΘέλετε να την επεξεργαστείτε;\n\nΠατήστε "OK" για επεξεργασία, ή "Ακύρωση" για απλή ολοκλήρωση της αποστολής χωρίς αλλαγή στην αναφορά.')) {
                                    window.location.href = 'mission-debrief.php?id=<?= $mission['id'] ?>';
                                } else {
                                    document.getElementById('completeOnlyForm').submit();
                                }
                            }
                            </script>
                            <form id="completeOnlyForm" method="post" style="display:none;">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="complete_only">
                            </form>
                        <?php else: ?>
                            <form method="post">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="complete">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="bi bi-check-circle me-1"></i>Ολοκλήρωση & Αναφορά
                                </button>
                            </form>
                        <?php endif; ?>
                    <?php endif; ?>
                    
                    <?php if ($mission['status'] === STATUS_COMPLETED): ?>
                        <form method="post">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="reopen_to_closed">
                            <button type="submit" class="btn btn-warning w-100">
                                <i class="bi bi-unlock me-1"></i>Επαναφορά σε «Κλειστή»
                            </button>
                        </form>
                    <?php endif; ?>
                    
                    <?php if (in_array($mission['status'], [STATUS_DRAFT, STATUS_OPEN])): ?>
                        <hr>
                        <button type="button" class="btn btn-outline-danger w-100 mb-2" data-bs-toggle="modal" data-bs-target="#cancelModal">
                            <i class="bi bi-x-circle me-1"></i>Ακύρωση Αποστολής
                        </button>
                    <?php endif; ?>
                    
                    <button type="button" class="btn btn-danger w-100" data-bs-toggle="modal" data-bs-target="#deleteMissionModal">
                        <i class="bi bi-trash me-1"></i>Διαγραφή Αποστολής
                    </button>
                </div>
            </div>
        <?php endif; ?>
        
        <!-- Quick Stats -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">Στατιστικά</h5>
            </div>
            <div class="card-body">
                <div class="d-flex justify-content-between mb-2">
                    <span>Σύνολο Βαρδιών:</span>
                    <strong><?= count($shifts) ?></strong>
                </div>
                <div class="d-flex justify-content-between mb-2">
                    <span>Εγκεκριμένοι Εθελοντές:</span>
                    <strong><?= array_sum(array_column($shifts, 'approved_count')) ?></strong>
                </div>
                <div class="d-flex justify-content-between">
                    <span>Εκκρεμείς Αιτήσεις:</span>
                    <strong><?= array_sum(array_column($shifts, 'pending_count')) ?></strong>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Cancel Modal -->
<div class="modal fade" id="cancelModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="cancel">
                <div class="modal-header">
                    <h5 class="modal-title">Ακύρωση Αποστολής</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        Η ακύρωση θα ειδοποιήσει όλους τους εγγεγραμμένους εθελοντές.
                    </div>
                    <div class="mb-3">
                        <label for="cancellation_reason" class="form-label">Λόγος Ακύρωσης</label>
                        <textarea class="form-control" id="cancellation_reason" name="cancellation_reason" rows="3" required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Κλείσιμο</button>
                    <button type="submit" class="btn btn-danger">Ακύρωση Αποστολής</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Mission Modal -->
<?php
$allMissionParticipants = [];
foreach ($shifts as $s) {
    $shiftParticipants = dbFetchAll(
        "SELECT DISTINCT u.name, pr.status 
         FROM participation_requests pr 
         JOIN users u ON pr.volunteer_id = u.id 
         WHERE pr.shift_id = ? AND pr.status IN ('PENDING', 'APPROVED')",
        [$s['id']]
    );
    $allMissionParticipants = array_merge($allMissionParticipants, $shiftParticipants);
}
?>
<div class="modal fade" id="deleteMissionModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-1"></i>Διαγραφή Αποστολής</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-circle me-1"></i>
                        <strong>Προσοχή!</strong> Αυτή η ενέργεια δεν μπορεί να αναιρεθεί.
                    </div>
                    
                    <?php if (count($allMissionParticipants) > 0): ?>
                        <div class="alert alert-warning">
                            <i class="bi bi-people me-1"></i>
                            <strong>Οι παρακάτω <?= count($allMissionParticipants) ?> αιτήσεις θα ακυρωθούν:</strong>
                        </div>
                        <ul class="list-group mb-3" style="max-height: 200px; overflow-y: auto;">
                            <?php foreach ($allMissionParticipants as $p): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center py-2">
                                    <span>
                                        <i class="bi bi-person me-1"></i><?= h($p['name']) ?>
                                    </span>
                                    <?php if ($p['status'] === 'APPROVED'): ?>
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
                        <p>Η αποστολή δεν έχει ενεργές αιτήσεις συμμετοχής.</p>
                    <?php endif; ?>
                    
                    <hr>
                    <p class="mb-0"><strong>Είστε σίγουροι ότι θέλετε να διαγράψετε την αποστολή "<?= h($mission['title']) ?>";</strong></p>
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

<!-- Apply Modal (for volunteers) -->
<?php if (!isAdmin()): ?>
<div class="modal fade" id="applyModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="apply">
                <input type="hidden" name="shift_id" id="applyShiftId">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-hand-index me-1"></i>Αίτηση Συμμετοχής</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Αίτηση για τη βάρδια: <strong id="applyShiftDate"></strong></p>
                    <div class="mb-3">
                        <label class="form-label">Σημειώσεις (προαιρετικά)</label>
                        <textarea class="form-control" name="volunteer_notes" rows="3" 
                                  placeholder="Π.χ. διαθεσιμότητα, εμπειρία, ειδικές δεξιότητες..."></textarea>
                        <small class="text-muted">Οι σημειώσεις θα είναι ορατές στους διαχειριστές.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Ακύρωση</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-send me-1"></i>Υποβολή Αίτησης
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.querySelectorAll('.apply-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        document.getElementById('applyShiftId').value = this.getAttribute('data-shift-id');
        document.getElementById('applyShiftDate').textContent = this.getAttribute('data-shift-date');
        var modal = new bootstrap.Modal(document.getElementById('applyModal'));
        modal.show();
    });
});
</script>
<?php endif; ?>

<!-- Add Volunteer Modal (for admins) -->
<?php if (isAdmin()): ?>
<div class="modal fade" id="addVolunteerModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="manual_add_volunteer">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-person-plus me-1"></i>Προσθήκη Εθελοντή</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Επιλογή Χρήστη</label>
                        <select class="form-select" name="volunteer_id" required>
                            <option value="">-- Επιλέξτε χρήστη --</option>
                            <?php foreach ($availableVolunteers as $vol): ?>
                                <option value="<?= $vol['id'] ?>">
                                    <?= h($vol['name']) ?> (<?= h($vol['email']) ?>) — <?= h(ROLE_LABELS[$vol['role']] ?? $vol['role']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <hr>
                    
                    <div class="mb-3">
                        <label class="form-label"><strong>Επιλογή Βαρδιών</strong> (μπορείτε να επιλέξετε πολλές)</label>
                        <?php if (empty($shifts)): ?>
                            <p class="text-muted">Δεν υπάρχουν διαθέσιμες βάρδιες.</p>
                        <?php else: ?>
                            <div class="list-group" style="max-height: 300px; overflow-y: auto;">
                                <?php foreach ($shifts as $shift): ?>
                                    <label class="list-group-item d-flex align-items-start">
                                        <input class="form-check-input me-2 mt-1" type="checkbox" 
                                               name="shift_ids[]" value="<?= $shift['id'] ?>">
                                        <div class="flex-grow-1">
                                            <div><strong><?= formatDateTime($shift['start_time'], 'd/m/Y') ?></strong></div>
                                            <small class="text-muted">
                                                <?= formatDateTime($shift['start_time'], 'H:i') ?> - 
                                                <?= formatDateTime($shift['end_time'], 'H:i') ?>
                                                | <?= $shift['approved_count'] ?>/<?= $shift['max_volunteers'] ?> εθελοντές
                                            </small>
                                        </div>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Σημειώσεις Διαχειριστή (προαιρετικά)</label>
                        <textarea class="form-control" name="admin_notes" rows="2" 
                                  placeholder="Λόγος χειροκίνητης ανάθεσης..."></textarea>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-1"></i>
                        Ο εθελοντής θα προστεθεί αυτόματα ως <strong>εγκεκριμένος</strong> σε όλες τις επιλεγμένες βάρδιες και θα λάβει ειδοποιήσεις.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="bi bi-x me-1"></i>Ακύρωση
                    </button>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-person-plus me-1"></i>Προσθήκη σε Επιλεγμένες Βάρδιες
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Mission Chat Section -->
<?php if ($canAccessChat): ?>
<div class="card shadow-sm mb-4" id="chat">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0">
            <i class="bi bi-chat-dots me-2"></i>Συζήτηση Αποστολής
            <?php if (!isAdmin()): ?>
                <small class="ms-2">(Μόνο εγκεκριμένοι εθελοντές)</small>
            <?php endif; ?>
        </h5>
    </div>
    <div class="card-body">
        <div class="chat-messages mb-3" style="max-height: 400px; overflow-y: auto; border: 1px solid #dee2e6; border-radius: 8px; padding: 15px; background: #f8f9fa;">
            <?php if (empty($chatMessages)): ?>
                <p class="text-muted text-center mb-0">
                    <i class="bi bi-chat-square-text me-2"></i>Δεν υπάρχουν μηνύματα ακόμα. Ξεκινήστε τη συζήτηση!
                </p>
            <?php else: ?>
                <?php foreach ($chatMessages as $msg): ?>
                    <div class="chat-message mb-3 <?= $msg['user_id'] == $user['id'] ? 'text-end' : '' ?>">
                        <div class="d-inline-block position-relative <?= $msg['user_id'] == $user['id'] ? 'bg-primary text-white' : 'bg-white border' ?>" 
                             style="max-width: 70%; padding: 10px 15px; border-radius: 15px; box-shadow: 0 1px 2px rgba(0,0,0,0.1);">
                            <?php if (isAdmin()): ?>
                                <form method="post" class="position-absolute" style="top: 5px; right: 5px;" onsubmit="return confirm('Θέλετε να διαγράψετε αυτό το μήνυμα;');">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="delete_chat_message">
                                    <input type="hidden" name="message_id" value="<?= $msg['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger" style="padding: 2px 6px; font-size: 0.7rem;">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            <?php endif; ?>
                            <div class="fw-bold mb-1" style="font-size: 0.85rem;">
                                <?= h($msg['user_name']) ?>
                                <?= $msg['user_id'] == $user['id'] ? '(Εσείς)' : '' ?>
                            </div>
                            <div style="word-wrap: break-word;"><?= $msg['message'] ?></div>
                            <div class="text-<?= $msg['user_id'] == $user['id'] ? 'white-50' : 'muted' ?> mt-1" style="font-size: 0.75rem;">
                                <?= formatDateTime($msg['created_at']) ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
            <div id="chatEnd"></div>
        </div>
        
        <form method="post" id="chatForm">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="send_chat_message">
            <div class="mb-2">
                <textarea class="form-control" name="message" id="chatMessage" rows="3" placeholder="Γράψτε το μήνυμά σας..." required style="resize: vertical;"></textarea>
            </div>
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-send"></i> Αποστολή
            </button>
        </form>
    </div>
</div>

<script>
// Auto-scroll to bottom of chat on load
document.addEventListener('DOMContentLoaded', function() {
    const chatMessages = document.querySelector('.chat-messages');
    if (chatMessages) {
        chatMessages.scrollTop = chatMessages.scrollHeight;
    }
    
    // Scroll to chat if hash is #chat
    if (window.location.hash === '#chat') {
        document.getElementById('chat').scrollIntoView({ behavior: 'smooth' });
    }
});
</script>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
