<?php
/**
 * VolunteerOps - Attendance Management for Mission
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();
requireRole([ROLE_SYSTEM_ADMIN, ROLE_DEPARTMENT_ADMIN, ROLE_SHIFT_LEADER]);

$missionId = get('mission_id');
if (!$missionId) {
    setFlash('error', 'Δεν καθορίστηκε αποστολή.');
    redirect('missions.php');
}

$mission = dbFetchOne(
    "SELECT m.*, d.name as department_name
     FROM missions m
     LEFT JOIN departments d ON m.department_id = d.id
     WHERE m.id = ? AND m.deleted_at IS NULL",
    [$missionId]
);

if (!$mission) {
    setFlash('error', 'Η αποστολή δεν βρέθηκε.');
    redirect('missions.php');
}

$pageTitle = 'Διαχείριση Παρουσιών - ' . $mission['title'];
$user = getCurrentUser();

// Get all shifts with approved participants
$shifts = dbFetchAll(
    "SELECT s.*, 
            (SELECT COUNT(*) FROM participation_requests WHERE shift_id = s.id AND status = '" . PARTICIPATION_APPROVED . "') as approved_count,
            (SELECT COUNT(*) FROM participation_requests WHERE shift_id = s.id AND status = '" . PARTICIPATION_APPROVED . "' AND attended = 1) as attended_count
     FROM shifts s
     WHERE s.mission_id = ?
     ORDER BY s.start_time ASC",
    [$missionId]
);

// Get all approved participants grouped by shift
$participants = dbFetchAll(
    "SELECT pr.*, u.name, u.email, u.phone, s.start_time, s.end_time, s.id as shift_id
     FROM participation_requests pr
     JOIN users u ON pr.volunteer_id = u.id
     JOIN shifts s ON pr.shift_id = s.id
     WHERE s.mission_id = ? AND pr.status = '" . PARTICIPATION_APPROVED . "'
     ORDER BY s.start_time ASC, u.name ASC",
    [$missionId]
);

// Handle attendance update
if (isPost()) {
    verifyCsrf();
    $action = post('action');
    
    if ($action === 'update_attendance') {
        $participationId = post('participation_id');
        $attended = post('attended') ? 1 : 0;
        $actualHours = post('actual_hours');
        $actualStartTime = post('actual_start_time') ?: null;
        $actualEndTime = post('actual_end_time') ?: null;
        $adminNotes = post('admin_notes');
        
        dbExecute(
            "UPDATE participation_requests SET 
                attended = ?, 
                actual_hours = ?, 
                actual_start_time = ?, 
                actual_end_time = ?,
                admin_notes = ?,
                attendance_confirmed_at = NOW(),
                attendance_confirmed_by = ?,
                updated_at = NOW()
             WHERE id = ?",
            [$attended, $actualHours, $actualStartTime, $actualEndTime, $adminNotes, $user['id'], $participationId]
        );
        
        logAudit('update_attendance', 'participation_requests', $participationId);
        setFlash('success', 'Η παρουσία ενημερώθηκε.');
        redirect('attendance.php?mission_id=' . $missionId);
    }
    
    if ($action === 'bulk_attendance') {
        $shiftId = post('shift_id');
        $attendedIds = post('attended', []);
        
        // Get all approved for this shift
        $approvedIds = dbFetchAll(
            "SELECT id FROM participation_requests WHERE shift_id = ? AND status = '" . PARTICIPATION_APPROVED . "'",
            [$shiftId]
        );
        
        foreach ($approvedIds as $row) {
            $attended = in_array($row['id'], $attendedIds) ? 1 : 0;
            dbExecute(
                "UPDATE participation_requests SET attended = ?, attendance_confirmed_at = NOW(), attendance_confirmed_by = ? WHERE id = ?",
                [$attended, $user['id'], $row['id']]
            );
        }
        
        logAudit('bulk_attendance', 'shifts', $shiftId);
        setFlash('success', 'Οι παρουσίες αποθηκεύτηκαν.');
        redirect('attendance.php?mission_id=' . $missionId);
    }
    
    if ($action === 'award_points') {
        $shiftId = post('shift_id');
        
        // Get attended participants who haven't been awarded points yet
        $toAward = dbFetchAll(
            "SELECT pr.*, s.start_time, s.end_time
             FROM participation_requests pr
             JOIN shifts s ON pr.shift_id = s.id
             WHERE pr.shift_id = ? AND pr.status = '" . PARTICIPATION_APPROVED . "' AND pr.attended = 1 AND pr.points_awarded = 0",
            [$shiftId]
        );
        
        $pointsAwarded = 0;
        foreach ($toAward as $pr) {
            // Calculate hours
            $hours = $pr['actual_hours'];
            if (!$hours) {
                $start = new DateTime($pr['start_time']);
                $end = new DateTime($pr['end_time']);
                $hours = ($end->getTimestamp() - $start->getTimestamp()) / 3600;
            }
            
            // Base points
            $points = round($hours * POINTS_PER_HOUR);
            
            // Award points
            dbInsert(
                "INSERT INTO volunteer_points (user_id, points, reason, description, pointable_type, pointable_id, created_at) 
                 VALUES (?, ?, ?, ?, ?, ?, NOW())",
                [$pr['volunteer_id'], $points, 'SHIFT_COMPLETED', 'Βάρδια: ' . $mission['title'], 'App\\Models\\Shift', $shiftId]
            );
            
            // Update user total points
            dbExecute(
                "UPDATE users SET total_points = total_points + ?, monthly_points = monthly_points + ? WHERE id = ?",
                [$points, $points, $pr['volunteer_id']]
            );
            
            // Mark as awarded
            dbExecute("UPDATE participation_requests SET points_awarded = 1 WHERE id = ?", [$pr['id']]);
            
            $pointsAwarded++;
        }
        
        if ($pointsAwarded > 0) {
            setFlash('success', "Απονεμήθηκαν πόντοι σε $pointsAwarded εθελοντές.");
        } else {
            setFlash('info', 'Δεν υπάρχουν εθελοντές για απονομή πόντων.');
        }
        redirect('attendance.php?mission_id=' . $missionId);
    }
}

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-0">
            <i class="bi bi-clipboard-check me-2"></i>Διαχείριση Παρουσιών
        </h1>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="missions.php">Αποστολές</a></li>
                <li class="breadcrumb-item"><a href="mission-view.php?id=<?= $missionId ?>"><?= h($mission['title']) ?></a></li>
                <li class="breadcrumb-item active">Παρουσίες</li>
            </ol>
        </nav>
    </div>
    <a href="mission-view.php?id=<?= $missionId ?>" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Πίσω
    </a>
</div>

<?= showFlash() ?>

<!-- Mission Summary -->
<div class="card mb-4">
    <div class="card-body">
        <div class="row align-items-center">
            <div class="col-md-6">
                <h4 class="mb-1"><?= h($mission['title']) ?></h4>
                <p class="text-muted mb-0">
                    <i class="bi bi-building me-1"></i><?= h($mission['department_name'] ?? '-') ?>
                    &nbsp;|&nbsp;
                    <i class="bi bi-geo-alt me-1"></i><?= h($mission['location']) ?>
                </p>
            </div>
            <div class="col-md-6 text-md-end">
                <?= statusBadge($mission['status']) ?>
                <span class="ms-2 text-muted">
                    <?= formatDateTime($mission['start_datetime']) ?> - <?= formatDateTime($mission['end_datetime']) ?>
                </span>
            </div>
        </div>
    </div>
</div>

<!-- Shifts Accordion -->
<?php if (empty($shifts)): ?>
    <div class="alert alert-info">
        <i class="bi bi-info-circle me-2"></i>Δεν υπάρχουν βάρδιες σε αυτή την αποστολή.
    </div>
<?php else: ?>
    <div class="accordion" id="shiftsAccordion">
        <?php foreach ($shifts as $index => $shift): ?>
            <?php 
            $shiftParticipants = array_filter($participants, fn($p) => $p['shift_id'] == $shift['id']);
            $shiftStart = new DateTime($shift['start_time']);
            $shiftEnd = new DateTime($shift['end_time']);
            $shiftHours = round(($shiftEnd->getTimestamp() - $shiftStart->getTimestamp()) / 3600, 1);
            $isPast = $shiftEnd < new DateTime();
            ?>
            <div class="accordion-item">
                <h2 class="accordion-header">
                    <button class="accordion-button <?= $index > 0 ? 'collapsed' : '' ?>" type="button" 
                            data-bs-toggle="collapse" data-bs-target="#shift<?= $shift['id'] ?>">
                        <div class="d-flex justify-content-between align-items-center w-100 me-3">
                            <div>
                                <strong><?= formatDateGreek($shift['start_time']) ?></strong>
                                <span class="text-muted ms-2">
                                    <?= date('H:i', strtotime($shift['start_time'])) ?> - <?= date('H:i', strtotime($shift['end_time'])) ?>
                                </span>
                                <span class="badge bg-secondary ms-2"><?= $shiftHours ?> ώρες</span>
                            </div>
                            <div>
                                <span class="badge bg-primary"><?= $shift['approved_count'] ?> εγκεκριμένοι</span>
                                <span class="badge bg-success"><?= $shift['attended_count'] ?> παρόντες</span>
                            </div>
                        </div>
                    </button>
                </h2>
                <div id="shift<?= $shift['id'] ?>" class="accordion-collapse collapse <?= $index === 0 ? 'show' : '' ?>">
                    <div class="accordion-body">
                        <?php if (empty($shiftParticipants)): ?>
                            <p class="text-muted text-center py-3">Δεν υπάρχουν εγκεκριμένες συμμετοχές.</p>
                        <?php else: ?>
                            <!-- Bulk Actions -->
                            <?php if ($isPast): ?>
                            <div class="d-flex justify-content-between mb-3">
                                <form method="post" class="d-inline">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="award_points">
                                    <input type="hidden" name="shift_id" value="<?= $shift['id'] ?>">
                                    <button type="submit" class="btn btn-warning btn-sm" 
                                            onclick="return confirm('Απονομή πόντων σε όλους τους παρόντες;')">
                                        <i class="bi bi-star me-1"></i>Απονομή Πόντων
                                    </button>
                                </form>
                            </div>
                            <?php endif; ?>
                            
                            <!-- Participants Table -->
                            <form method="post">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="bulk_attendance">
                                <input type="hidden" name="shift_id" value="<?= $shift['id'] ?>">
                                
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th style="width: 50px;">
                                                <input type="checkbox" class="form-check-input" 
                                                       onchange="toggleAll(this, <?= $shift['id'] ?>)">
                                            </th>
                                            <th>Εθελοντής</th>
                                            <th>Επικοινωνία</th>
                                            <th>Παρουσία</th>
                                            <th>Πόντοι</th>
                                            <th>Ενέργειες</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($shiftParticipants as $p): ?>
                                        <tr>
                                            <td>
                                                <input type="checkbox" class="form-check-input shift-<?= $shift['id'] ?>" 
                                                       name="attended[]" value="<?= $p['id'] ?>" 
                                                       <?= $p['attended'] ? 'checked' : '' ?>>
                                            </td>
                                            <td>
                                                <strong><?= h($p['name']) ?></strong>
                                            </td>
                                            <td>
                                                <small>
                                                    <i class="bi bi-envelope me-1"></i><?= h($p['email']) ?><br>
                                                    <i class="bi bi-phone me-1"></i><?php if ($p['phone']): ?><a href="tel:<?= h($p['phone']) ?>"><?= h($p['phone']) ?></a><?php else: ?>-<?php endif; ?>
                                                </small>
                                            </td>
                                            <td>
                                                <?php if ($p['attended']): ?>
                                                    <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Παρών</span>
                                                    <?php if ($p['actual_hours']): ?>
                                                        <br><small class="text-muted"><?= $p['actual_hours'] ?> ώρες</small>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">Εκκρεμεί</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($p['points_awarded']): ?>
                                                    <span class="badge bg-warning text-dark"><i class="bi bi-star-fill me-1"></i>Απονεμήθηκαν</span>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <button type="button" class="btn btn-sm btn-outline-primary" 
                                                        data-bs-toggle="modal" data-bs-target="#editModal"
                                                        onclick="editAttendance(<?= htmlspecialchars(json_encode($p)) ?>, <?= $shiftHours ?>)">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                                
                                <?php if ($isPast): ?>
                                <div class="text-end">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-save me-1"></i>Αποθήκευση Παρουσιών
                                    </button>
                                </div>
                                <?php endif; ?>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Edit Attendance Modal -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="update_attendance">
                <input type="hidden" name="participation_id" id="editParticipationId">
                
                <div class="modal-header">
                    <h5 class="modal-title">Επεξεργασία Παρουσίας</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-3"><strong id="editVolunteerName"></strong></p>
                    
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="attended" id="editAttended" value="1">
                        <label class="form-check-label" for="editAttended">Παρών/Παρούσα</label>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4">
                            <label class="form-label">Πραγματικές Ώρες</label>
                            <input type="number" class="form-control" name="actual_hours" id="editActualHours" 
                                   step="0.5" min="0" max="24">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Ώρα Άφιξης</label>
                            <input type="time" class="form-control" name="actual_start_time" id="editStartTime">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Ώρα Αποχώρησης</label>
                            <input type="time" class="form-control" name="actual_end_time" id="editEndTime">
                        </div>
                    </div>
                    
                    <div class="mt-3">
                        <label class="form-label">Σημειώσεις Διαχειριστή</label>
                        <textarea class="form-control" name="admin_notes" id="editNotes" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Ακύρωση</button>
                    <button type="submit" class="btn btn-primary">Αποθήκευση</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function toggleAll(checkbox, shiftId) {
    document.querySelectorAll('.shift-' + shiftId).forEach(cb => {
        cb.checked = checkbox.checked;
    });
}

function editAttendance(p, defaultHours) {
    document.getElementById('editParticipationId').value = p.id;
    document.getElementById('editVolunteerName').textContent = p.name;
    document.getElementById('editAttended').checked = p.attended == 1;
    document.getElementById('editActualHours').value = p.actual_hours || defaultHours;
    document.getElementById('editStartTime').value = p.actual_start_time || '';
    document.getElementById('editEndTime').value = p.actual_end_time || '';
    document.getElementById('editNotes').value = p.admin_notes || '';
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
