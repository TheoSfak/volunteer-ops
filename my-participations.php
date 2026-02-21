<?php
/**
 * VolunteerOps - My Participations
 * Εθελοντής βλέπει τις αιτήσεις συμμετοχής του
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();

$pageTitle = 'Οι Αιτήσεις μου';
$user = getCurrentUser();

// Admins redirect to shifts management
if (isAdmin()) {
    redirect('shifts.php');
}

// Get all participations for current user
$participations = dbFetchAll(
    "SELECT pr.*, 
            s.start_time, s.end_time, s.max_volunteers,
            m.title as mission_title, m.location, m.status as mission_status,
            d.name as department_name,
            decider.name as decided_by_name
     FROM participation_requests pr
     JOIN shifts s ON pr.shift_id = s.id
     JOIN missions m ON s.mission_id = m.id
     LEFT JOIN departments d ON m.department_id = d.id
     LEFT JOIN users decider ON pr.decided_by = decider.id
     WHERE pr.volunteer_id = ?
     ORDER BY s.start_time DESC",
    [$user['id']]
);

// Handle cancel action
if (isPost()) {
    verifyCsrf();
    $action = post('action');
    
    if ($action === 'cancel') {
        $prId = post('participation_id');
        
        // Verify ownership and status
        $pr = dbFetchOne(
            "SELECT * FROM participation_requests WHERE id = ? AND volunteer_id = ? AND status = '" . PARTICIPATION_PENDING . "'",
            [$prId, $user['id']]
        );
        
        if ($pr) {
            dbExecute(
                "UPDATE participation_requests SET status = 'CANCELED_BY_USER', updated_at = NOW() WHERE id = ?",
                [$prId]
            );
            logAudit('cancel_participation', 'participation_requests', $prId);
            setFlash('success', 'Η αίτηση ακυρώθηκε.');
        } else {
            setFlash('error', 'Δεν μπορείτε να ακυρώσετε αυτή την αίτηση.');
        }
        redirect('my-participations.php');
    }
}

// Group by status
$pending = array_filter($participations, fn($p) => $p['status'] === 'PENDING');
$approved = array_filter($participations, fn($p) => $p['status'] === 'APPROVED');
$rejected = array_filter($participations, fn($p) => $p['status'] === 'REJECTED');
$canceled = array_filter($participations, fn($p) => in_array($p['status'], ['CANCELED_BY_USER', 'CANCELED_BY_ADMIN']));

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">
        <i class="bi bi-list-check me-2"></i>Οι Αιτήσεις μου
    </h1>
    <a href="missions.php" class="btn btn-primary">
        <i class="bi bi-search me-1"></i>Αναζήτηση Αποστολών
    </a>
</div>

<?= showFlash() ?>

<!-- Stats Cards -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card bg-warning text-dark">
            <div class="card-body text-center">
                <h3><?= count($pending) ?></h3>
                <small>Εκκρεμείς</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-success text-white">
            <div class="card-body text-center">
                <h3><?= count($approved) ?></h3>
                <small>Εγκεκριμένες</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-danger text-white">
            <div class="card-body text-center">
                <h3><?= count($rejected) ?></h3>
                <small>Απορριφθείσες</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card bg-secondary text-white">
            <div class="card-body text-center">
                <h3><?= count($canceled) ?></h3>
                <small>Ακυρωμένες</small>
            </div>
        </div>
    </div>
</div>

<!-- Pending Participations -->
<?php if (!empty($pending)): ?>
<div class="card mb-4 border-warning">
    <div class="card-header bg-warning text-dark">
        <h5 class="mb-0"><i class="bi bi-hourglass-split me-1"></i>Εκκρεμείς Αιτήσεις (<?= count($pending) ?>)</h5>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Αποστολή</th>
                        <th>Βάρδια</th>
                        <th>Τοποθεσία</th>
                        <th>Ημ/νία Αίτησης</th>
                        <th>Ενέργειες</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pending as $p): ?>
                        <tr>
                            <td>
                                <strong><?= h($p['mission_title']) ?></strong>
                                <?php if ($p['notes']): ?>
                                    <br><small class="text-muted"><i class="bi bi-quote me-1"></i><?= h($p['notes']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= formatDateTime($p['start_time'], 'd/m/Y') ?><br>
                                <small><?= formatDateTime($p['start_time'], 'H:i') ?> - <?= formatDateTime($p['end_time'], 'H:i') ?></small>
                            </td>
                            <td><?= h($p['location']) ?></td>
                            <td><?= formatDateTime($p['created_at'], 'd/m/Y H:i') ?></td>
                            <td>
                                <form method="post" class="d-inline" onsubmit="return confirm('Ακύρωση της αίτησης;')">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="cancel">
                                    <input type="hidden" name="participation_id" value="<?= $p['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">
                                        <i class="bi bi-x-lg"></i> Ακύρωση
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Approved Participations -->
<?php if (!empty($approved)): ?>
<div class="card mb-4 border-success">
    <div class="card-header bg-success text-white">
        <h5 class="mb-0"><i class="bi bi-check-circle me-1"></i>Εγκεκριμένες Συμμετοχές (<?= count($approved) ?>)</h5>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Αποστολή</th>
                        <th>Βάρδια</th>
                        <th>Τοποθεσία</th>
                        <th>Κατάσταση</th>
                        <th>Εγκρίθηκε</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($approved as $p): ?>
                        <?php 
                        $isPast = strtotime($p['end_time']) < time();
                        ?>
                        <tr class="<?= $isPast ? 'table-light' : '' ?>">
                            <td>
                                <strong><?= h($p['mission_title']) ?></strong>
                                <?php if ($p['notes']): ?>
                                    <br><small class="text-muted"><i class="bi bi-quote me-1"></i><?= h($p['notes']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= formatDateTime($p['start_time'], 'd/m/Y') ?><br>
                                <small><?= formatDateTime($p['start_time'], 'H:i') ?> - <?= formatDateTime($p['end_time'], 'H:i') ?></small>
                            </td>
                            <td><?= h($p['location']) ?></td>
                            <td>
                                <?php if ($p['attended']): ?>
                                    <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Παρευρέθηκα</span>
                                <?php elseif ($isPast): ?>
                                    <span class="badge bg-secondary">Ολοκληρώθηκε</span>
                                <?php else: ?>
                                    <span class="badge bg-info">Αναμένεται</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= formatDateTime($p['decided_at'], 'd/m/Y H:i') ?>
                                <?php if ($p['decided_by_name']): ?>
                                    <br><small class="text-muted">από <?= h($p['decided_by_name']) ?></small>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Rejected Participations -->
<?php if (!empty($rejected)): ?>
<div class="card mb-4 border-danger">
    <div class="card-header bg-danger text-white">
        <h5 class="mb-0"><i class="bi bi-x-circle me-1"></i>Απορριφθείσες Αιτήσεις (<?= count($rejected) ?>)</h5>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Αποστολή</th>
                        <th>Βάρδια</th>
                        <th>Λόγος Απόρριψης</th>
                        <th>Ημ/νία Απόρριψης</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rejected as $p): ?>
                        <tr>
                            <td>
                                <strong><?= h($p['mission_title']) ?></strong>
                                <?php if ($p['notes']): ?>
                                    <br><small class="text-muted"><i class="bi bi-quote me-1"></i>Η αίτησή μου: <?= h($p['notes']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= formatDateTime($p['start_time'], 'd/m/Y') ?><br>
                                <small><?= formatDateTime($p['start_time'], 'H:i') ?> - <?= formatDateTime($p['end_time'], 'H:i') ?></small>
                            </td>
                            <td>
                                <?php if ($p['rejection_reason']): ?>
                                    <span class="text-danger">
                                        <i class="bi bi-info-circle me-1"></i><?= h($p['rejection_reason']) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted">Δεν δόθηκε αιτιολογία</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= formatDateTime($p['decided_at'], 'd/m/Y H:i') ?>
                                <?php if ($p['decided_by_name']): ?>
                                    <br><small class="text-muted">από <?= h($p['decided_by_name']) ?></small>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Canceled Participations -->
<?php if (!empty($canceled)): ?>
<div class="card mb-4">
    <div class="card-header bg-secondary text-white">
        <h5 class="mb-0"><i class="bi bi-dash-circle me-1"></i>Ακυρωμένες Αιτήσεις (<?= count($canceled) ?>)</h5>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Αποστολή</th>
                        <th>Βάρδια</th>
                        <th>Κατάσταση</th>
                        <th>Ημ/νία</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($canceled as $p): ?>
                        <tr>
                            <td><strong><?= h($p['mission_title']) ?></strong></td>
                            <td>
                                <?= formatDateTime($p['start_time'], 'd/m/Y') ?><br>
                                <small><?= formatDateTime($p['start_time'], 'H:i') ?> - <?= formatDateTime($p['end_time'], 'H:i') ?></small>
                            </td>
                            <td>
                                <?php if ($p['status'] === 'CANCELED_BY_USER'): ?>
                                    <span class="badge bg-secondary">Ακυρώθηκε από εσάς</span>
                                <?php else: ?>
                                    <span class="badge bg-dark">Ακυρώθηκε από διαχειριστή</span>
                                <?php endif; ?>
                            </td>
                            <td><?= formatDateTime($p['updated_at'], 'd/m/Y H:i') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Empty State -->
<?php if (empty($participations)): ?>
<div class="card">
    <div class="card-body text-center py-5">
        <i class="bi bi-inbox text-muted" style="font-size: 4rem;"></i>
        <h4 class="mt-3">Δεν έχετε υποβάλει αιτήσεις</h4>
        <p class="text-muted">Αναζητήστε αποστολές για να υποβάλετε την πρώτη σας αίτηση συμμετοχής.</p>
        <a href="missions.php" class="btn btn-primary">
            <i class="bi bi-search me-1"></i>Αναζήτηση Αποστολών
        </a>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
