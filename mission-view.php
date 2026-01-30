<?php
/**
 * VolunteerOps - View Mission
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();

$id = get('id');
if (!$id) {
    redirect('missions.php');
}

$mission = dbFetchOne(
    "SELECT m.*, d.name as department_name, u.name as creator_name
     FROM missions m
     LEFT JOIN departments d ON m.department_id = d.id
     LEFT JOIN users u ON m.created_by = u.id
     WHERE m.id = ?",
    [$id]
);

if (!$mission) {
    setFlash('error', 'Η αποστολή δεν βρέθηκε.');
    redirect('missions.php');
}

$pageTitle = $mission['title'];
$user = getCurrentUser();

// Get shifts
$shifts = dbFetchAll(
    "SELECT s.*,
            (SELECT COUNT(*) FROM participation_requests WHERE shift_id = s.id AND status = 'APPROVED') as approved_count,
            (SELECT COUNT(*) FROM participation_requests WHERE shift_id = s.id AND status = 'PENDING') as pending_count
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

// Handle actions
if (isPost()) {
    verifyCsrf();
    $action = post('action');
    
    switch ($action) {
        case 'publish':
            if (isAdmin() && $mission['status'] === STATUS_DRAFT) {
                dbExecute("UPDATE missions SET status = ?, updated_at = NOW() WHERE id = ?", [STATUS_OPEN, $id]);
                logAudit('publish', 'missions', $id);
                setFlash('success', 'Η αποστολή δημοσιεύτηκε.');
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
                dbExecute("UPDATE missions SET status = ?, updated_at = NOW() WHERE id = ?", [STATUS_COMPLETED, $id]);
                logAudit('complete', 'missions', $id);
                setFlash('success', 'Η αποστολή ολοκληρώθηκε.');
                redirect('mission-view.php?id=' . $id);
            }
            break;
            
        case 'cancel':
            if (isAdmin() && in_array($mission['status'], [STATUS_DRAFT, STATUS_OPEN])) {
                $reason = post('cancellation_reason');
                dbExecute(
                    "UPDATE missions SET status = ?, cancellation_reason = ?, canceled_by = ?, canceled_at = NOW(), updated_at = NOW() WHERE id = ?",
                    [STATUS_CANCELED, $reason, $user['id'], $id]
                );
                logAudit('cancel', 'missions', $id, null, ['reason' => $reason]);
                setFlash('success', 'Η αποστολή ακυρώθηκε.');
                redirect('missions.php');
            }
            break;
            
        case 'apply':
            $shiftId = post('shift_id');
            if ($shiftId && !isAdmin()) {
                // Check if already applied
                $existing = dbFetchValue(
                    "SELECT COUNT(*) FROM participation_requests WHERE volunteer_id = ? AND shift_id = ?",
                    [$user['id'], $shiftId]
                );
                
                if (!$existing) {
                    dbInsert(
                        "INSERT INTO participation_requests (volunteer_id, shift_id, status, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())",
                        [$user['id'], $shiftId, PARTICIPATION_PENDING]
                    );
                    logAudit('apply', 'participation_requests', null, null, ['shift_id' => $shiftId]);
                    setFlash('success', 'Η αίτησή σας υποβλήθηκε.');
                } else {
                    setFlash('error', 'Έχετε ήδη υποβάλει αίτηση για αυτή τη βάρδια.');
                }
                redirect('mission-view.php?id=' . $id);
            }
            break;
    }
}

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-0">
            <?= h($mission['title']) ?>
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

<div class="row">
    <div class="col-lg-8">
        <!-- Mission Details -->
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Λεπτομέρειες</h5>
                <?= statusBadge($mission['status']) ?>
            </div>
            <div class="card-body">
                <?php if ($mission['description']): ?>
                    <p><?= nl2br(h($mission['description'])) ?></p>
                    <hr>
                <?php endif; ?>
                
                <div class="row">
                    <div class="col-md-6">
                        <p><strong><i class="bi bi-geo-alt me-1"></i>Τοποθεσία:</strong><br>
                        <?= h($mission['location']) ?>
                        <?php if ($mission['location_details']): ?>
                            <br><small class="text-muted"><?= h($mission['location_details']) ?></small>
                        <?php endif; ?>
                        </p>
                        
                        <p><strong><i class="bi bi-building me-1"></i>Τμήμα:</strong><br>
                        <?= h($mission['department_name'] ?? '-') ?></p>
                        
                        <p><strong><i class="bi bi-tag me-1"></i>Τύπος:</strong><br>
                        <?= h($GLOBALS['MISSION_TYPES'][$mission['type']] ?? $mission['type']) ?></p>
                    </div>
                    <div class="col-md-6">
                        <p><strong><i class="bi bi-calendar-event me-1"></i>Έναρξη:</strong><br>
                        <?= formatDateGreek($mission['start_datetime']) ?><br>
                        <small class="text-muted"><?= formatDateTime($mission['start_datetime'], 'H:i') ?></small></p>
                        
                        <p><strong><i class="bi bi-calendar-check me-1"></i>Λήξη:</strong><br>
                        <?= formatDateGreek($mission['end_datetime']) ?><br>
                        <small class="text-muted"><?= formatDateTime($mission['end_datetime'], 'H:i') ?></small></p>
                        
                        <p><strong><i class="bi bi-person me-1"></i>Δημιουργός:</strong><br>
                        <?= h($mission['creator_name'] ?? '-') ?></p>
                    </div>
                </div>
                
                <!-- Mission Hours -->
                <?php
                $startDt = new DateTime($mission['start_datetime']);
                $endDt = new DateTime($mission['end_datetime']);
                $diff = $startDt->diff($endDt);
                $totalHours = ($diff->days * 24) + $diff->h + ($diff->i / 60);
                $totalHours = round($totalHours, 1);
                ?>
                <div class="alert alert-info alert-permanent mt-3 mb-0">
                    <h4 class="alert-heading mb-0 text-center">
                        <i class="bi bi-clock-fill me-2"></i>ΩΡΕΣ ΑΠΟΣΤΟΛΗΣ: <?= $totalHours ?> ΩΡΕΣ
                    </h4>
                </div>
                
                <?php if ($mission['requirements']): ?>
                    <hr>
                    <p><strong><i class="bi bi-list-check me-1"></i>Απαιτήσεις:</strong></p>
                    <p><?= nl2br(h($mission['requirements'])) ?></p>
                <?php endif; ?>
                
                <?php if ($mission['notes']): ?>
                    <hr>
                    <p><strong><i class="bi bi-sticky me-1"></i>Σημειώσεις:</strong></p>
                    <p><?= nl2br(h($mission['notes'])) ?></p>
                <?php endif; ?>
            </div>
        </div>
        
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
                                                <?php elseif (!$isPast && !$isFull && $mission['status'] === STATUS_OPEN): ?>
                                                    <form method="post" class="d-inline">
                                                        <?= csrfField() ?>
                                                        <input type="hidden" name="action" value="apply">
                                                        <input type="hidden" name="shift_id" value="<?= $shift['id'] ?>">
                                                        <button type="submit" class="btn btn-sm btn-primary">
                                                            <i class="bi bi-hand-index"></i> Αίτηση
                                                        </button>
                                                    </form>
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
                    <?php if ($mission['status'] === STATUS_DRAFT): ?>
                        <form method="post">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="publish">
                            <button type="submit" class="btn btn-success w-100">
                                <i class="bi bi-send me-1"></i>Δημοσίευση
                            </button>
                        </form>
                    <?php endif; ?>
                    
                    <?php if ($mission['status'] === STATUS_OPEN): ?>
                        <form method="post">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="close">
                            <button type="submit" class="btn btn-warning w-100">
                                <i class="bi bi-lock me-1"></i>Κλείσιμο Αιτήσεων
                            </button>
                        </form>
                        <a href="attendance.php?mission_id=<?= $mission['id'] ?>" class="btn btn-info w-100">
                            <i class="bi bi-clipboard-check me-1"></i>Διαχείριση Παρουσιών
                        </a>
                    <?php endif; ?>
                    
                    <?php if ($mission['status'] === STATUS_CLOSED): ?>
                        <a href="attendance.php?mission_id=<?= $mission['id'] ?>" class="btn btn-info w-100">
                            <i class="bi bi-clipboard-check me-1"></i>Διαχείριση Παρουσιών
                        </a>
                        <form method="post">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="complete">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-check-circle me-1"></i>Ολοκλήρωση
                            </button>
                        </form>
                    <?php endif; ?>
                    
                    <?php if (in_array($mission['status'], [STATUS_DRAFT, STATUS_OPEN])): ?>
                        <hr>
                        <button type="button" class="btn btn-outline-danger w-100" data-bs-toggle="modal" data-bs-target="#cancelModal">
                            <i class="bi bi-x-circle me-1"></i>Ακύρωση Αποστολής
                        </button>
                    <?php endif; ?>
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

<?php include __DIR__ . '/includes/footer.php'; ?>
