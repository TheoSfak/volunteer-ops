<?php
/**
 * VolunteerOps - Participations Management
 * Διαχείριση αιτήσεων συμμετοχής σε βάρδιες
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();

$pageTitle = 'Συμμετοχές';

$currentUser = getCurrentUser();
$isAdmin = isAdmin();
$isShiftLeader = hasRole(ROLE_SHIFT_LEADER);

// Filters
$filterStatus = get('status', '');
$filterMission = (int)get('mission', 0);
$search = get('search', '');
$page = max(1, (int)get('page', 1));
$perPage = 20;

// Build query
$where = [];
$params = [];

// Non-admins see only their own participations
if (!$isAdmin && !$isShiftLeader) {
    $where[] = "pr.volunteer_id = ?";
    $params[] = $currentUser['id'];
}

if ($filterStatus) {
    $where[] = "pr.status = ?";
    $params[] = $filterStatus;
}

if ($filterMission) {
    $where[] = "s.mission_id = ?";
    $params[] = $filterMission;
}

if ($search) {
    $where[] = "(m.title LIKE ? OR u.name LIKE ?)";
    $searchTerm = "%$search%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

$whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

try {
    // Count total
    $countSql = "SELECT COUNT(*) 
                 FROM participation_requests pr
                 JOIN shifts s ON pr.shift_id = s.id
                 JOIN missions m ON s.mission_id = m.id
                 JOIN users u ON pr.volunteer_id = u.id
                 $whereClause";
    $total = dbFetchValue($countSql, $params);
} catch (Exception $e) {
    error_log('Participations count error: ' . $e->getMessage());
    $total = 0;
}

$pagination = paginate($total, $page, $perPage);

try {
    // Fetch participations - using correct field names from database
    $sql = "SELECT pr.*, 
                   DATE(s.start_time) as shift_date, 
                   TIME(s.start_time) as shift_start_time, 
                   TIME(s.end_time) as shift_end_time,
                   m.id as mission_id, m.title as mission_title,
                   u.name as volunteer_name, u.email as volunteer_email,
                   decided.name as decided_by_name
            FROM participation_requests pr
            JOIN shifts s ON pr.shift_id = s.id
            JOIN missions m ON s.mission_id = m.id
            JOIN users u ON pr.volunteer_id = u.id
            LEFT JOIN users decided ON pr.decided_by = decided.id
            $whereClause
            ORDER BY pr.created_at DESC
            LIMIT {$pagination['offset']}, {$pagination['per_page']}";

    $participations = dbFetchAll($sql, $params);
} catch (Exception $e) {
    error_log('Participations fetch error: ' . $e->getMessage());
    $participations = [];
}

// Get missions for filter dropdown
$missions = dbFetchAll("SELECT id, title FROM missions WHERE deleted_at IS NULL ORDER BY title");

// Handle actions (admin only)
if (isPost() && ($isAdmin || $isShiftLeader)) {
    verifyCsrf();
    
    $action = post('action');
    
    // Bulk actions
    if ($action === 'bulk_action') {
        $bulkAction = post('bulk_action_type');
        $selectedIds = post('selected_ids', []);
        
        if (!empty($selectedIds) && in_array($bulkAction, ['approve', 'reject'])) {
            $successCount = 0;
            $rejectionReason = $bulkAction === 'reject' ? post('bulk_rejection_reason', '') : null;
            $newStatus = $bulkAction === 'approve' ? 'APPROVED' : 'REJECTED';
            
            foreach ($selectedIds as $participationId) {
                $participation = dbFetchOne("SELECT * FROM participation_requests WHERE id = ? AND status = 'PENDING'", [(int)$participationId]);
                
                if ($participation) {
                    dbExecute(
                        "UPDATE participation_requests 
                         SET status = ?, rejection_reason = ?, decided_by = ?, decided_at = NOW(), updated_at = NOW() 
                         WHERE id = ?",
                        [$newStatus, $rejectionReason, $currentUser['id'], $participationId]
                    );
                    
                    // Send notification
                    $notificationType = $bulkAction === 'approve' ? 'participation_approved' : 'participation_rejected';
                    if (isNotificationEnabled($notificationType)) {
                        $message = $bulkAction === 'approve' 
                            ? 'Η αίτησή σας για συμμετοχή εγκρίθηκε!'
                            : 'Η αίτησή σας για συμμετοχή απορρίφθηκε.';
                        if ($rejectionReason) {
                            $message .= ' Αιτιολογία: ' . $rejectionReason;
                        }
                        sendNotification($participation['volunteer_id'], 'Ενημέρωση Συμμετοχής', $message);
                    }
                    
                    logAudit($bulkAction, 'participation_requests', $participationId, $participation['status'] . ' -> ' . $newStatus);
                    $successCount++;
                }
            }
            
            $actionLabel = $bulkAction === 'approve' ? 'εγκρίθηκαν' : 'απορρίφθηκαν';
            setFlash('success', "$successCount συμμετοχές $actionLabel επιτυχώς.");
            redirect('participations.php?' . $_SERVER['QUERY_STRING']);
        } else {
            setFlash('error', 'Δεν επιλέχθηκαν συμμετοχές.');
            redirect('participations.php?' . $_SERVER['QUERY_STRING']);
        }
    }
    
    // Single action
    $participationId = (int)post('participation_id');
    
    if ($participationId && in_array($action, ['approve', 'reject'])) {
        $participation = dbFetchOne("SELECT * FROM participation_requests WHERE id = ?", [$participationId]);
        
        if ($participation) {
            $newStatus = $action === 'approve' ? 'APPROVED' : 'REJECTED';
            $rejectionReason = $action === 'reject' ? post('rejection_reason', '') : null;
            
            dbExecute(
                "UPDATE participation_requests 
                 SET status = ?, rejection_reason = ?, decided_by = ?, decided_at = NOW(), updated_at = NOW() 
                 WHERE id = ?",
                [$newStatus, $rejectionReason, $currentUser['id'], $participationId]
            );
            
            // Send notification
            $notificationType = $action === 'approve' ? 'participation_approved' : 'participation_rejected';
            if (isNotificationEnabled($notificationType)) {
                $message = $action === 'approve' 
                    ? 'Η αίτησή σας για συμμετοχή εγκρίθηκε!'
                    : 'Η αίτησή σας για συμμετοχή απορρίφθηκε.';
                if ($rejectionReason) {
                    $message .= ' Αιτιολογία: ' . $rejectionReason;
                }
                sendNotification($participation['volunteer_id'], 'Ενημέρωση Συμμετοχής', $message);
            }
            
            logAudit($action, 'participation_requests', $participationId, $participation['status'] . ' -> ' . $newStatus);
            
            $actionLabel = $action === 'approve' ? 'εγκρίθηκε' : 'απορρίφθηκε';
            setFlash('success', "Η συμμετοχή $actionLabel επιτυχώς.");
            redirect('participations.php?' . $_SERVER['QUERY_STRING']);
        }
    }
}

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">
        <i class="bi bi-people me-2"></i>Συμμετοχές
    </h1>
    <?php if ($isAdmin || $isShiftLeader): ?>
        <?php
        // Build export URL with current filters
        $exportParams = array_filter([
            'status' => $filterStatus,
            'mission' => $filterMission
        ]);
        $exportUrl = 'exports/export-participations.php?' . http_build_query($exportParams);
        ?>
        <a href="<?= $exportUrl ?>" class="btn btn-outline-success">
            <i class="bi bi-download me-1"></i>Εξαγωγή CSV
        </a>
    <?php endif; ?>
</div>

<?= showFlash() ?>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body">
        <form method="get" class="row g-3">
            <div class="col-md-3">
                <label class="form-label">Αναζήτηση</label>
                <input type="text" class="form-control" name="search" value="<?= h($search) ?>" 
                       placeholder="Αποστολή, εθελοντής...">
            </div>
            <div class="col-md-3">
                <label class="form-label">Κατάσταση</label>
                <select class="form-select" name="status">
                    <option value="">Όλες</option>
                    <?php foreach (PARTICIPATION_LABELS as $key => $label): ?>
                        <option value="<?= $key ?>" <?= $filterStatus === $key ? 'selected' : '' ?>>
                            <?= h($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Αποστολή</label>
                <select class="form-select" name="mission">
                    <option value="">Όλες</option>
                    <?php foreach ($missions as $mission): ?>
                        <option value="<?= $mission['id'] ?>" <?= $filterMission == $mission['id'] ? 'selected' : '' ?>>
                            <?= h($mission['title']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 d-flex align-items-end gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-search me-1"></i>Αναζήτηση
                </button>
                <a href="participations.php" class="btn btn-outline-secondary">
                    <i class="bi bi-x-lg"></i>
                </a>
            </div>
        </form>
    </div>
</div>

<!-- Results -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>Βρέθηκαν <?= $total ?> συμμετοχές</span>
        <?php if (($isAdmin || $isShiftLeader) && $total > 0): ?>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-sm btn-success" onclick="showBulkApproveModal()">
                    <i class="bi bi-check-all me-1"></i>Μαζική Έγκριση
                </button>
                <button type="button" class="btn btn-sm btn-danger" onclick="showBulkRejectModal()">
                    <i class="bi bi-x-circle me-1"></i>Μαζική Απόρριψη
                </button>
            </div>
        <?php endif; ?>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <?php if ($isAdmin || $isShiftLeader): ?>
                        <th width="40">
                            <input type="checkbox" id="selectAll" class="form-check-input">
                        </th>
                    <?php endif; ?>
                    <?php if ($isAdmin || $isShiftLeader): ?>
                        <th>Εθελοντής</th>
                    <?php endif; ?>
                    <th>Αποστολή</th>
                    <th>Βάρδια</th>
                    <th>Κατάσταση</th>
                    <th>Ημ/νία Αίτησης</th>
                    <th>Απόφαση</th>
                    <th class="text-end">Ενέργειες</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($participations)): ?>
                    <tr>
                        <td colspan="<?= ($isAdmin || $isShiftLeader) ? 8 : 6 ?>" class="text-center py-4 text-muted">
                            Δεν βρέθηκαν συμμετοχές
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($participations as $p): ?>
                        <tr>
                            <?php if ($isAdmin || $isShiftLeader): ?>
                                <td>
                                    <?php if ($p['status'] === 'PENDING'): ?>
                                        <input type="checkbox" class="form-check-input participation-checkbox" 
                                               value="<?= $p['id'] ?>" data-volunteer="<?= h($p['volunteer_name']) ?>">
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                            <?php if ($isAdmin || $isShiftLeader): ?>
                                <td>
                                    <a href="volunteer-view.php?id=<?= $p['volunteer_id'] ?>">
                                        <?= h($p['volunteer_name']) ?>
                                    </a>
                                    <br><small class="text-muted"><?= h($p['volunteer_email']) ?></small>
                                </td>
                            <?php endif; ?>
                            <td>
                                <a href="mission-view.php?id=<?= $p['mission_id'] ?>">
                                    <?= h($p['mission_title']) ?>
                                </a>
                            </td>
                            <td>
                                <a href="shift-view.php?id=<?= $p['shift_id'] ?>">
                                    <?= formatDate($p['shift_date']) ?>
                                    <br><small class="text-muted"><?= substr($p['shift_start_time'], 0, 5) ?> - <?= substr($p['shift_end_time'], 0, 5) ?></small>
                                </a>
                            </td>
                            <td><?= statusBadge($p['status'], 'participation') ?></td>
                            <td><?= formatDateTime($p['created_at']) ?></td>
                            <td>
                                <?php if ($p['decided_at']): ?>
                                    <?= h($p['decided_by_name']) ?>
                                    <br><small class="text-muted"><?= formatDateTime($p['decided_at']) ?></small>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <?php if ($p['status'] === 'PENDING' && ($isAdmin || $isShiftLeader)): ?>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Έγκριση συμμετοχής;')">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="approve">
                                        <input type="hidden" name="participation_id" value="<?= $p['id'] ?>">
                                        <button type="submit" class="btn btn-success btn-sm" title="Έγκριση">
                                            <i class="bi bi-check-lg"></i>
                                        </button>
                                    </form>
                                    <button type="button" class="btn btn-danger btn-sm" title="Απόρριψη"
                                            data-bs-toggle="modal" data-bs-target="#rejectModal<?= $p['id'] ?>">
                                        <i class="bi bi-x-lg"></i>
                                    </button>
                                    
                                    <!-- Reject Modal -->
                                    <div class="modal fade" id="rejectModal<?= $p['id'] ?>" tabindex="-1">
                                        <div class="modal-dialog">
                                            <div class="modal-content">
                                                <form method="post">
                                                    <?= csrfField() ?>
                                                    <input type="hidden" name="action" value="reject">
                                                    <input type="hidden" name="participation_id" value="<?= $p['id'] ?>">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">Απόρριψη Συμμετοχής</h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <div class="modal-body">
                                                        <p>Απόρριψη συμμετοχής για: <strong><?= h($p['volunteer_name']) ?></strong></p>
                                                        <div class="mb-3">
                                                            <label class="form-label">Αιτιολογία (προαιρετικά)</label>
                                                            <textarea class="form-control" name="rejection_reason" rows="3"></textarea>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Ακύρωση</button>
                                                        <button type="submit" class="btn btn-danger">Απόρριψη</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                <?php elseif ($p['status'] === 'PENDING' && $p['volunteer_id'] == $currentUser['id']): ?>
                                    <form method="post" action="mission-view.php?id=<?= $p['mission_id'] ?>" 
                                          onsubmit="return confirm('Ακύρωση της αίτησης συμμετοχής;')">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="cancel_participation">
                                        <input type="hidden" name="shift_id" value="<?= $p['shift_id'] ?>">
                                        <button type="submit" class="btn btn-outline-danger btn-sm" title="Ακύρωση">
                                            <i class="bi bi-x-lg"></i> Ακύρωση
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <a href="shift-view.php?id=<?= $p['shift_id'] ?>" class="btn btn-outline-secondary btn-sm">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <?php if ($pagination['total_pages'] > 1): ?>
        <div class="card-footer">
            <?= paginationLinks($pagination, 'participations.php?' . http_build_query(array_filter([
                'status' => $filterStatus,
                'mission' => $filterMission,
                'search' => $search
            ])) . '&') ?>
        </div>
    <?php endif; ?>
</div>

<!-- Bulk Approve Modal -->
<div class="modal fade" id="bulkApproveModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" id="bulkApproveForm">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="bulk_action">
                <input type="hidden" name="bulk_action_type" value="approve">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="bi bi-check-all me-2"></i>Μαζική Έγκριση Συμμετοχών</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Θα εγκριθούν <strong id="approveCount">0</strong> συμμετοχές:</p>
                    <ul id="approveList" class="list-unstyled mb-0" style="max-height: 300px; overflow-y: auto;"></ul>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Ακύρωση</button>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-check-lg me-1"></i>Έγκριση Όλων
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Bulk Reject Modal -->
<div class="modal fade" id="bulkRejectModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" id="bulkRejectForm">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="bulk_action">
                <input type="hidden" name="bulk_action_type" value="reject">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="bi bi-x-circle me-2"></i>Μαζική Απόρριψη Συμμετοχών</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Θα απορριφθούν <strong id="rejectCount">0</strong> συμμετοχές:</p>
                    <ul id="rejectList" class="list-unstyled mb-3" style="max-height: 200px; overflow-y: auto;"></ul>
                    <div class="mb-3">
                        <label class="form-label">Αιτιολογία (προαιρετικά)</label>
                        <textarea class="form-control" name="bulk_rejection_reason" rows="3" 
                                  placeholder="Αιτιολογία που θα σταλεί σε όλους..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Ακύρωση</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-x-lg me-1"></i>Απόρριψη Όλων
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Select all checkbox
document.getElementById('selectAll')?.addEventListener('change', function() {
    document.querySelectorAll('.participation-checkbox').forEach(cb => {
        cb.checked = this.checked;
    });
});

// Show bulk approve modal
function showBulkApproveModal() {
    const selected = getSelectedParticipations();
    if (selected.length === 0) {
        alert('Παρακαλώ επιλέξτε τουλάχιστον μία συμμετοχή');
        return;
    }
    
    document.getElementById('approveCount').textContent = selected.length;
    const list = document.getElementById('approveList');
    list.innerHTML = selected.map(p => `<li><i class="bi bi-person text-success me-2"></i>${p.volunteer}</li>`).join('');
    
    // Add hidden inputs
    const form = document.getElementById('bulkApproveForm');
    form.querySelectorAll('input[name="selected_ids[]"]').forEach(el => el.remove());
    selected.forEach(p => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'selected_ids[]';
        input.value = p.id;
        form.appendChild(input);
    });
    
    new bootstrap.Modal(document.getElementById('bulkApproveModal')).show();
}

// Show bulk reject modal
function showBulkRejectModal() {
    const selected = getSelectedParticipations();
    if (selected.length === 0) {
        alert('Παρακαλώ επιλέξτε τουλάχιστον μία συμμετοχή');
        return;
    }
    
    document.getElementById('rejectCount').textContent = selected.length;
    const list = document.getElementById('rejectList');
    list.innerHTML = selected.map(p => `<li><i class="bi bi-person text-danger me-2"></i>${p.volunteer}</li>`).join('');
    
    // Add hidden inputs
    const form = document.getElementById('bulkRejectForm');
    form.querySelectorAll('input[name="selected_ids[]"]').forEach(el => el.remove());
    selected.forEach(p => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'selected_ids[]';
        input.value = p.id;
        form.appendChild(input);
    });
    
    new bootstrap.Modal(document.getElementById('bulkRejectModal')).show();
}

// Get selected participations
function getSelectedParticipations() {
    const selected = [];
    document.querySelectorAll('.participation-checkbox:checked').forEach(cb => {
        selected.push({
            id: cb.value,
            volunteer: cb.dataset.volunteer
        });
    });
    return selected;
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
