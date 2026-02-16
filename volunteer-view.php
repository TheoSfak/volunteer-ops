<?php
/**
 * VolunteerOps - Volunteer View
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();
requireRole([ROLE_SYSTEM_ADMIN, ROLE_DEPARTMENT_ADMIN, ROLE_SHIFT_LEADER]);

$id = get('id');
if (!$id) {
    redirect('volunteers.php');
}

$volunteer = dbFetchOne(
    "SELECT u.*, d.name as department_name 
     FROM users u 
     LEFT JOIN departments d ON u.department_id = d.id 
     WHERE u.id = ?",
    [$id]
);

if (!$volunteer) {
    setFlash('error', 'Ο εθελοντής δεν βρέθηκε.');
    redirect('volunteers.php');
}

$pageTitle = $volunteer['name'];

// Handle actions
if (isPost()) {
    verifyCsrf();
    $action = post('action');
    
    if ($action === 'delete_personal_data') {
        // GDPR-compliant personal data deletion
        // Only system admins can delete personal data
        if (!isSystemAdmin()) {
            setFlash('error', 'Δεν έχετε δικαίωμα για αυτή την ενέργεια.');
            redirect('volunteer-view.php?id=' . $id);
        }
        
        // Anonymize user data
        $anonymizedEmail = 'deleted_' . $id . '_' . time() . '@deleted.local';
        dbExecute(
            "UPDATE users SET 
             name = ?, 
             email = ?, 
             phone = NULL, 
             is_active = 0,
             updated_at = NOW() 
             WHERE id = ?",
            ['[Διαγραμμένος Χρήστης]', $anonymizedEmail, $id]
        );
        
        // Delete volunteer profile
        dbExecute("DELETE FROM volunteer_profiles WHERE user_id = ?", [$id]);
        
        // Delete user skills
        dbExecute("DELETE FROM user_skills WHERE user_id = ?", [$id]);
        
        // Delete user achievements
        dbExecute("DELETE FROM user_achievements WHERE user_id = ?", [$id]);
        
        // Delete notifications
        dbExecute("DELETE FROM notifications WHERE user_id = ?", [$id]);
        
        logAudit('delete_personal_data', 'users', $id, 'GDPR data deletion');
        setFlash('success', 'Τα προσωπικά δεδομένα διαγράφηκαν επιτυχώς.');
        redirect('volunteers.php');
    }
}

// Get profile
$profile = dbFetchOne("SELECT * FROM volunteer_profiles WHERE user_id = ?", [$id]);

// Get skills
$skills = dbFetchAll(
    "SELECT s.*, us.level FROM skills s 
     JOIN user_skills us ON s.id = us.skill_id 
     WHERE us.user_id = ?
     ORDER BY s.category, s.name",
    [$id]
);

// Get achievements
$achievements = dbFetchAll(
    "SELECT a.*, ua.earned_at FROM achievements a
     JOIN user_achievements ua ON a.id = ua.achievement_id
     WHERE ua.user_id = ?
     ORDER BY ua.earned_at DESC",
    [$id]
);

// Get recent participations
$participations = dbFetchAll(
    "SELECT pr.*, s.start_time, s.end_time, m.title as mission_title
     FROM participation_requests pr
     JOIN shifts s ON pr.shift_id = s.id
     JOIN missions m ON s.mission_id = m.id
     WHERE pr.volunteer_id = ?
     ORDER BY s.start_time DESC
     LIMIT 20",
    [$id]
);

// Get stats
$stats = [
    'total_shifts' => dbFetchValue(
        "SELECT COUNT(*) FROM participation_requests WHERE volunteer_id = ? AND status = 'APPROVED'",
        [$id]
    ),
    'attended_shifts' => dbFetchValue(
        "SELECT COUNT(*) FROM participation_requests WHERE volunteer_id = ? AND attended = 1",
        [$id]
    ),
    'total_hours' => dbFetchValue(
        "SELECT COALESCE(SUM(actual_hours), 0) FROM participation_requests WHERE volunteer_id = ? AND attended = 1",
        [$id]
    ),
    'pending_requests' => dbFetchValue(
        "SELECT COUNT(*) FROM participation_requests WHERE volunteer_id = ? AND status = 'PENDING'",
        [$id]
    ),
];

// Points history
$pointsHistory = dbFetchAll(
    "SELECT vp.*, 
            CASE WHEN vp.pointable_type = 'App\\\\Models\\\\Shift' THEN s.id ELSE NULL END as shift_id,
            CASE WHEN vp.pointable_type = 'App\\\\Models\\\\Shift' THEN m.title ELSE NULL END as shift_title
     FROM volunteer_points vp
     LEFT JOIN shifts s ON vp.pointable_type = 'App\\\\Models\\\\Shift' AND vp.pointable_id = s.id
     LEFT JOIN missions m ON s.mission_id = m.id
     WHERE vp.user_id = ?
     ORDER BY vp.created_at DESC
     LIMIT 10",
    [$id]
);

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-0"><?= h($volunteer['name']) ?><?= volunteerTypeBadge($volunteer['volunteer_type'] ?? VTYPE_VOLUNTEER) ?></h1>
        <small class="text-muted"><?= h($volunteer['email']) ?></small>
    </div>
    <div>
        <a href="volunteer-form.php?id=<?= $id ?>" class="btn btn-outline-primary">
            <i class="bi bi-pencil me-1"></i>Επεξεργασία
        </a>        <?php if (isSystemAdmin()): ?>
            <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteDataModal">
                <i class="bi bi-shield-x me-1"></i>Διαγραφή Προσωπικών Δεδομένων
            </button>
        <?php endif; ?>        <a href="volunteers.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Πίσω
        </a>
    </div>
</div>

<!-- Stats Cards -->
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card stats-card primary">
            <div class="card-body text-center">
                <h3 class="mb-0"><?= $stats['total_shifts'] ?></h3>
                <small class="text-muted">Βάρδιες (εγκεκ.)</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stats-card success">
            <div class="card-body text-center">
                <h3 class="mb-0"><?= $stats['attended_shifts'] ?></h3>
                <small class="text-muted">Παρουσίες</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stats-card info">
            <div class="card-body text-center">
                <h3 class="mb-0"><?= number_format($stats['total_hours'], 1) ?></h3>
                <small class="text-muted">Ώρες</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stats-card warning">
            <div class="card-body text-center">
                <h3 class="mb-0"><?= number_format($volunteer['total_points']) ?></h3>
                <small class="text-muted">Πόντοι</small>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-lg-8">
        <!-- Profile Info -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-person me-1"></i>Στοιχεία Προφίλ</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <p><strong>Τηλέφωνο:</strong> <?= h($volunteer['phone'] ?: '-') ?></p>
                        <p><strong>Τμήμα:</strong> <?= h($volunteer['department_name'] ?: '-') ?></p>
                        <p><strong>Ρόλος:</strong> <?= roleBadge($volunteer['role']) ?></p>
                        <p><strong>Κατάσταση:</strong> 
                            <?php if ($volunteer['is_active']): ?>
                                <span class="badge bg-success">Ενεργός</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Ανενεργός</span>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="col-md-6">
                        <?php if ($profile): ?>
                            <p><strong>Πόλη:</strong> <?= h($profile['city'] ?: '-') ?></p>
                            <p><strong>Ομάδα Αίματος:</strong> <?= h($profile['blood_type'] ?: '-') ?></p>
                            <p><strong>Επαφή Έκτακτης Ανάγκης:</strong><br>
                                <?= h($profile['emergency_contact_name'] ?: '-') ?> 
                                <?= $profile['emergency_contact_phone'] ? '(' . h($profile['emergency_contact_phone']) . ')' : '' ?>
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <?php if ($profile && $profile['bio']): ?>
                    <hr>
                    <p><strong>Βιογραφικό:</strong></p>
                    <p><?= nl2br(h($profile['bio'])) ?></p>
                <?php endif; ?>
                
                <?php if ($profile): ?>
                    <hr>
                    <p><strong>Διαθεσιμότητα:</strong></p>
                    <p>
                        <?php if ($profile['available_weekdays']): ?><span class="badge bg-info">Καθημερινές</span><?php endif; ?>
                        <?php if ($profile['available_weekends']): ?><span class="badge bg-info">Σαββατοκύριακα</span><?php endif; ?>
                        <?php if ($profile['available_nights']): ?><span class="badge bg-info">Νυχτερινές</span><?php endif; ?>
                        <?php if ($profile['has_driving_license']): ?><span class="badge bg-secondary">Δίπλωμα Οδήγησης</span><?php endif; ?>
                        <?php if ($profile['has_first_aid']): ?><span class="badge bg-success">Πρώτες Βοήθειες</span><?php endif; ?>
                    </p>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Participations -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-calendar-check me-1"></i>Πρόσφατες Συμμετοχές</h5>
            </div>
            <div class="card-body">
                <?php if (empty($participations)): ?>
                    <p class="text-muted">Δεν υπάρχουν συμμετοχές.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Βάρδια</th>
                                    <th>Αποστολή</th>
                                    <th>Ημ/νία</th>
                                    <th>Κατάσταση</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($participations as $p): ?>
                                    <tr>
                                        <td>
                                            <a href="shift-view.php?id=<?= $p['shift_id'] ?>">
                                                <?= h($p['mission_title']) ?> - <?= formatDateTime($p['start_time'], 'H:i') ?>
                                            </a>
                                        </td>
                                        <td><?= h($p['mission_title']) ?></td>
                                        <td><?= formatDate($p['start_time']) ?></td>
                                        <td>
                                            <?= statusBadge($p['status']) ?>
                                            <?php if ($p['attended']): ?>
                                                <span class="badge bg-success"><i class="bi bi-check"></i></span>
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
        <!-- Skills -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-tools me-1"></i>Δεξιότητες</h5>
            </div>
            <div class="card-body">
                <?php if (empty($skills)): ?>
                    <p class="text-muted">Δεν έχουν καταχωρηθεί δεξιότητες.</p>
                <?php else: ?>
                    <?php 
                    $currentCat = '';
                    foreach ($skills as $sk): 
                        if ($sk['category'] !== $currentCat):
                            $currentCat = $sk['category'];
                    ?>
                        <strong class="text-muted small d-block mt-2"><?= h($currentCat) ?></strong>
                    <?php endif; ?>
                        <span class="badge bg-primary mb-1">
                            <?= h($sk['name']) ?>
                            <?php if ($sk['level']): ?>
                                <span class="badge bg-light text-dark"><?= $sk['level'] ?></span>
                            <?php endif; ?>
                        </span>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Achievements -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-trophy me-1"></i>Επιτεύγματα</h5>
            </div>
            <div class="card-body">
                <?php if (empty($achievements)): ?>
                    <p class="text-muted">Δεν υπάρχουν επιτεύγματα.</p>
                <?php else: ?>
                    <?php foreach ($achievements as $ach): ?>
                        <div class="d-flex align-items-center mb-2">
                            <span class="fs-4 me-2"><?= $ach['icon'] ?: '🏆' ?></span>
                            <div>
                                <strong><?= h($ach['name']) ?></strong>
                                <br><small class="text-muted"><?= formatDate($ach['earned_at']) ?></small>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Points History -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-star me-1"></i>Ιστορικό Πόντων</h5>
            </div>
            <div class="card-body">
                <?php if (empty($pointsHistory)): ?>
                    <p class="text-muted">Δεν υπάρχουν πόντοι.</p>
                <?php else: ?>
                    <ul class="list-unstyled mb-0">
                        <?php foreach ($pointsHistory as $ph): ?>
                            <li class="mb-2 pb-2 border-bottom">
                                <strong class="text-success">+<?= $ph['points'] ?> πόντοι</strong>
                                <br><small class="text-muted">
                                    <?= h($ph['shift_title'] ?: $ph['description']) ?>
                                    <br><?= formatDate($ph['created_at']) ?>
                                </small>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Delete Personal Data Modal -->
<div class="modal fade" id="deleteDataModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="bi bi-shield-x me-2"></i>Διαγραφή Προσωπικών Δεδομένων (GDPR)</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    <strong>Προειδοποίηση:</strong> Αυτή η ενέργεια είναι μόνιμη και δεν μπορεί να αναιρεθεί!
                </div>
                <p><strong>Θα διαγραφούν τα ακόλουθα δεδομένα:</strong></p>
                <ul>
                    <li>Όνομα και στοιχεία επικοινωνίας (θα αντικατασταθούν με "[Διαγραμμένος Χρήστης]")</li>
                    <li>Προφίλ εθελοντή (βιογραφικό, διεύθυνση, επαφή έκτακτης ανάγκης)</li>
                    <li>Δεξιότητες και επιτεύγματα</li>
                    <li>Ειδοποιήσεις</li>
                </ul>
                <p class="text-muted"><small><i class="bi bi-info-circle me-1"></i>Οι συμμετοχές σε αποστολές και βάρδιες θα διατηρηθούν για στατιστικούς λόγους, αλλά θα συνδεθούν με ανωνυμοποιημένο χρήστη.</small></p>
                <p class="mb-0">Είστε σίγουροι ότι θέλετε να διαγράψετε τα προσωπικά δεδομένα του <strong><?= h($volunteer['name']) ?></strong>;</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="bi bi-x me-1"></i>Ακύρωση
                </button>
                <form method="post" class="d-inline">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="delete_personal_data">
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-shield-x me-1"></i>Οριστική Διαγραφή Δεδομένων
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
