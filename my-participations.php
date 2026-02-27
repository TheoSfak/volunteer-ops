<?php
/**
 * VolunteerOps - My Participations
 * Εθελοντής βλέπει τις αιτήσεις συμμετοχής του
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();

$pageTitle = 'Οι Αιτήσεις μου';
$user = getCurrentUser();

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
$pending  = array_filter($participations, fn($p) => $p['status'] === 'PENDING');
$approved = array_filter($participations, fn($p) => $p['status'] === 'APPROVED');
$rejected = array_filter($participations, fn($p) => $p['status'] === 'REJECTED');
$canceled = array_filter($participations, fn($p) => in_array($p['status'], ['CANCELED_BY_USER', 'CANCELED_BY_ADMIN']));

// Active NOW: approved shifts currently in progress
$now = time();
$activeNow = array_filter($approved, fn($p) =>
    strtotime($p['start_time']) <= $now && strtotime($p['end_time']) > $now
);

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">
        <i class="bi bi-list-check me-2"></i>Οι Αιτήσεις μου
    </h1>
    <div class="d-flex gap-2">
        <a href="volunteer-report.php?id=<?= getCurrentUserId() ?>" target="_blank" class="btn btn-outline-secondary">
            <i class="bi bi-file-earmark-text me-1"></i>Αναφορά Δραστηριότητας
        </a>
        <a href="missions.php" class="btn btn-primary">
            <i class="bi bi-search me-1"></i>Αναζήτηση Αποστολών
        </a>
    </div>
</div>

<?= showFlash() ?>

<?php if (!empty($activeNow)): ?>
<!-- ═══════════════════════════════════════════════════════════
     LIVE OPS PANEL — Βάρδιες Τώρα σε Εξέλιξη
═══════════════════════════════════════════════════════════ -->
<div id="liveOpsPanel" class="mb-4">
    <div class="alert alert-danger d-flex align-items-center gap-2 mb-3">
        <span class="spinner-grow spinner-grow-sm text-danger" role="status"></span>
        <strong>Είστε σε ενεργή βάρδια!</strong> Ενημερώστε την κατάστασή σας και τη θέση σας.
    </div>

    <?php foreach ($activeNow as $p): ?>
    <?php
        $fieldStatus = $p['field_status'] ?? null;
        $statusLabels = ['on_way' => '🚗 Σε Κίνηση', 'on_site' => '✅ Επί Τόπου', 'needs_help' => '🆘 Χρειάζεται Βοήθεια'];
        $statusColors = ['on_way' => 'warning', 'on_site' => 'success', 'needs_help' => 'danger'];
    ?>
    <div class="card border-danger mb-3">
        <div class="card-header bg-danger text-white d-flex justify-content-between align-items-center">
            <div>
                <i class="bi bi-broadcast-pin me-2"></i>
                <strong><?= h($p['mission_title']) ?></strong>
                <span class="ms-2 text-white-50 small"><?= date('H:i', strtotime($p['start_time'])) ?> – <?= date('H:i', strtotime($p['end_time'])) ?></span>
            </div>
            <?php if ($fieldStatus): ?>
                <span class="badge bg-light text-dark" id="statusBadge-<?= $p['id'] ?>"><?= $statusLabels[$fieldStatus] ?? '' ?></span>
            <?php else: ?>
                <span class="badge bg-light text-dark" id="statusBadge-<?= $p['id'] ?>">— Χωρίς κατάσταση</span>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <div class="row g-3">

                <!-- GPS Ping -->
                <div class="col-md-5">
                    <p class="small text-muted mb-2"><i class="bi bi-geo-alt-fill me-1 text-primary"></i><strong>Αναφορά Θέσης (GPS)</strong></p>
                    <button type="button"
                            class="btn btn-primary w-100 btn-ping"
                            data-pr-id="<?= $p['id'] ?>"
                            data-shift-id="<?= $p['shift_id'] ?>"
                            onclick="sendGpsPing(this)">
                        <i class="bi bi-send-fill me-1"></i>Αποστολή Θέσης
                    </button>
                    <div class="mt-1 small text-muted" id="pingStatus-<?= $p['id'] ?>"></div>
                </div>

                <!-- Field Status -->
                <div class="col-md-7">
                    <p class="small text-muted mb-2"><i class="bi bi-activity me-1 text-success"></i><strong>Κατάσταση Πεδίου</strong></p>
                    <div class="btn-group w-100" role="group" id="statusBtns-<?= $p['id'] ?>">
                        <button type="button"
                                class="btn btn-sm <?= $fieldStatus === 'on_way' ? 'btn-warning' : 'btn-outline-warning' ?>"
                                onclick="setStatus(this, <?= $p['id'] ?>, 'on_way')">
                            🚗 Σε Κίνηση
                        </button>
                        <button type="button"
                                class="btn btn-sm <?= $fieldStatus === 'on_site' ? 'btn-success' : 'btn-outline-success' ?>"
                                onclick="setStatus(this, <?= $p['id'] ?>, 'on_site')">
                            ✅ Επί Τόπου
                        </button>
                        <button type="button"
                                class="btn btn-sm <?= $fieldStatus === 'needs_help' ? 'btn-danger' : 'btn-outline-danger' ?>"
                                onclick="setStatus(this, <?= $p['id'] ?>, 'needs_help')">
                            🆘 Βοήθεια!
                        </button>
                    </div>
                </div>

            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

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
        <!-- Desktop/Tablet table view -->
        <div class="table-responsive d-none d-sm-block">
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
        <!-- Mobile card view -->
        <div class="d-sm-none mobile-cards-container p-2">
            <?php foreach ($pending as $p): ?>
                <div class="card mobile-card border-warning">
                    <div class="card-body">
                        <div class="mobile-card-header">
                            <strong><?= h($p['mission_title']) ?></strong>
                            <span class="badge bg-warning text-dark">Εκκρεμεί</span>
                        </div>
                        <?php if ($p['notes']): ?>
                            <div class="mobile-card-row">
                                <small class="text-muted"><i class="bi bi-quote me-1"></i><?= h($p['notes']) ?></small>
                            </div>
                        <?php endif; ?>
                        <div class="mobile-card-row">
                            <div class="mobile-card-label">Βάρδια</div>
                            <small><i class="bi bi-calendar me-1"></i><?= formatDateTime($p['start_time'], 'd/m/Y') ?> &nbsp;<?= formatDateTime($p['start_time'], 'H:i') ?> - <?= formatDateTime($p['end_time'], 'H:i') ?></small>
                        </div>
                        <div class="mobile-card-row">
                            <div class="mobile-card-label">Τοποθεσία</div>
                            <small><i class="bi bi-geo-alt me-1"></i><?= h($p['location']) ?></small>
                        </div>
                        <div class="mobile-card-row">
                            <div class="mobile-card-label">Ημ/νία Αίτησης</div>
                            <small><?= formatDateTime($p['created_at'], 'd/m/Y H:i') ?></small>
                        </div>
                        <div class="mobile-card-actions">
                            <form method="post" class="w-100" onsubmit="return confirm('Ακύρωση της αίτησης;')">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="cancel">
                                <input type="hidden" name="participation_id" value="<?= $p['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger w-100">
                                    <i class="bi bi-x-lg me-1"></i>Ακύρωση Αίτησης
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
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
        <!-- Desktop/Tablet table view -->
        <div class="table-responsive d-none d-sm-block">
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
        <!-- Mobile card view -->
        <div class="d-sm-none mobile-cards-container p-2">
            <?php foreach ($approved as $p): ?>
                <?php $isPast = strtotime($p['end_time']) < time(); ?>
                <div class="card mobile-card border-success <?= $isPast ? 'opacity-75' : '' ?>">
                    <div class="card-body">
                        <div class="mobile-card-header">
                            <strong><?= h($p['mission_title']) ?></strong>
                            <div>
                                <?php if ($p['attended']): ?>
                                    <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Παρευρέθηκα</span>
                                <?php elseif ($isPast): ?>
                                    <span class="badge bg-secondary">Ολοκληρώθηκε</span>
                                <?php else: ?>
                                    <span class="badge bg-info">Αναμένεται</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if ($p['notes']): ?>
                            <div class="mobile-card-row">
                                <small class="text-muted"><i class="bi bi-quote me-1"></i><?= h($p['notes']) ?></small>
                            </div>
                        <?php endif; ?>
                        <div class="mobile-card-row">
                            <div class="mobile-card-label">Βάρδια</div>
                            <small><i class="bi bi-calendar me-1"></i><?= formatDateTime($p['start_time'], 'd/m/Y') ?> &nbsp;<?= formatDateTime($p['start_time'], 'H:i') ?> - <?= formatDateTime($p['end_time'], 'H:i') ?></small>
                        </div>
                        <div class="mobile-card-row">
                            <div class="mobile-card-label">Τοποθεσία</div>
                            <small><i class="bi bi-geo-alt me-1"></i><?= h($p['location']) ?></small>
                        </div>
                        <div class="mobile-card-row">
                            <div class="mobile-card-label">Εγκρίθηκε</div>
                            <small><?= formatDateTime($p['decided_at'], 'd/m/Y H:i') ?>
                            <?php if ($p['decided_by_name']): ?>
                                <span class="text-muted">από <?= h($p['decided_by_name']) ?></span>
                            <?php endif; ?></small>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
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
        <!-- Desktop/Tablet table view -->
        <div class="table-responsive d-none d-sm-block">
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
        <!-- Mobile card view -->
        <div class="d-sm-none mobile-cards-container p-2">
            <?php foreach ($rejected as $p): ?>
                <div class="card mobile-card border-danger">
                    <div class="card-body">
                        <div class="mobile-card-header">
                            <strong><?= h($p['mission_title']) ?></strong>
                            <span class="badge bg-danger">Απορρίφθηκε</span>
                        </div>
                        <?php if ($p['notes']): ?>
                            <div class="mobile-card-row">
                                <small class="text-muted"><i class="bi bi-quote me-1"></i>Η αίτησή μου: <?= h($p['notes']) ?></small>
                            </div>
                        <?php endif; ?>
                        <div class="mobile-card-row">
                            <div class="mobile-card-label">Βάρδια</div>
                            <small><i class="bi bi-calendar me-1"></i><?= formatDateTime($p['start_time'], 'd/m/Y') ?> &nbsp;<?= formatDateTime($p['start_time'], 'H:i') ?> - <?= formatDateTime($p['end_time'], 'H:i') ?></small>
                        </div>
                        <div class="mobile-card-row">
                            <div class="mobile-card-label">Λόγος Απόρριψης</div>
                            <?php if ($p['rejection_reason']): ?>
                                <small class="text-danger"><i class="bi bi-info-circle me-1"></i><?= h($p['rejection_reason']) ?></small>
                            <?php else: ?>
                                <small class="text-muted">Δεν δόθηκε αιτιολογία</small>
                            <?php endif; ?>
                        </div>
                        <div class="mobile-card-row">
                            <div class="mobile-card-label">Ημ/νία Απόρριψης</div>
                            <small><?= formatDateTime($p['decided_at'], 'd/m/Y H:i') ?>
                            <?php if ($p['decided_by_name']): ?>
                                <span class="text-muted">από <?= h($p['decided_by_name']) ?></span>
                            <?php endif; ?></small>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
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
        <!-- Desktop/Tablet table view -->
        <div class="table-responsive d-none d-sm-block">
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
        <!-- Mobile card view -->
        <div class="d-sm-none mobile-cards-container p-2">
            <?php foreach ($canceled as $p): ?>
                <div class="card mobile-card border-secondary">
                    <div class="card-body">
                        <div class="mobile-card-header">
                            <strong><?= h($p['mission_title']) ?></strong>
                            <div>
                                <?php if ($p['status'] === 'CANCELED_BY_USER'): ?>
                                    <span class="badge bg-secondary">Από εσάς</span>
                                <?php else: ?>
                                    <span class="badge bg-dark">Από διαχειριστή</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="mobile-card-row">
                            <div class="mobile-card-label">Βάρδια</div>
                            <small><i class="bi bi-calendar me-1"></i><?= formatDateTime($p['start_time'], 'd/m/Y') ?> &nbsp;<?= formatDateTime($p['start_time'], 'H:i') ?> - <?= formatDateTime($p['end_time'], 'H:i') ?></small>
                        </div>
                        <div class="mobile-card-row">
                            <div class="mobile-card-label">Ημ/νία Ακύρωσης</div>
                            <small><?= formatDateTime($p['updated_at'], 'd/m/Y H:i') ?></small>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
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

<script>
let CSRF_TOKEN = '<?= csrfToken() ?>';

// ── GPS Ping ─────────────────────────────────────────────────────────────────
function sendGpsPing(btn) {
    if (!navigator.geolocation) {
        showPingStatus(btn.dataset.prId, 'Το GPS δεν υποστηρίζεται', 'danger');
        return;
    }
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Εντοπισμός...';

    navigator.geolocation.getCurrentPosition(
        (pos) => {
            const body = new URLSearchParams({
                csrf_token: CSRF_TOKEN,
                shift_id:   btn.dataset.shiftId,
                lat:        pos.coords.latitude,
                lng:        pos.coords.longitude,
            });
            fetch('ping-location.php', { method: 'POST', body })
                .then(r => r.json())
                .then(d => {
                    if (d.ok) {
                        showPingStatus(btn.dataset.prId, '✅ Θέση εστάλη (' + d.ts + ')', 'success');
                    } else {
                        showPingStatus(btn.dataset.prId, '❌ ' + d.error, 'danger');
                    }
                })
                .catch(() => showPingStatus(btn.dataset.prId, '❌ Σφάλμα αποστολής', 'danger'))
                .finally(() => {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-send-fill me-1"></i>Αποστολή Θέσης';
                });
        },
        (err) => {
            let msg = '❌ ';
            switch (err.code) {
                case err.PERMISSION_DENIED:
                    msg += 'Η πρόσβαση GPS απορρίφθηκε. Ελέγξτε τις ρυθμίσεις τοποθεσίας του browser σας.';
                    // Show help tooltip
                    showPingStatus(btn.dataset.prId, msg + '<br><small class="text-muted">Κλικ στο εικονίδιο 🔒 στη γραμμή διεύθυνσης → Τοποθεσία → Επιτρέπεται</small>', 'danger');
                    break;
                case err.POSITION_UNAVAILABLE:
                    msg += 'Η θέση δεν είναι διαθέσιμη. Ενεργοποιήστε το GPS.';
                    showPingStatus(btn.dataset.prId, msg, 'warning');
                    break;
                case err.TIMEOUT:
                    msg += 'Λήξη χρόνου εντοπισμού. Δοκιμάστε ξανά.';
                    showPingStatus(btn.dataset.prId, msg, 'warning');
                    break;
                default:
                    showPingStatus(btn.dataset.prId, '❌ Άγνωστο σφάλμα GPS', 'danger');
            }
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-send-fill me-1"></i>Αποστολή Θέσης';
        },
        { enableHighAccuracy: true, timeout: 10000 }
    );
}

function showPingStatus(prId, msg, type) {
    const el = document.getElementById('pingStatus-' + prId);
    if (el) el.innerHTML = '<span class="text-' + type + '">' + msg + '</span>';
}

// ── Field Status ─────────────────────────────────────────────────────────────
function setStatus(btn, prId, status) {
    const body = new URLSearchParams({
        csrf_token: CSRF_TOKEN,
        pr_id:   prId,
        status:  status,
    });
    // Optimistically disable all buttons in group
    const group = document.getElementById('statusBtns-' + prId);
    if (group) group.querySelectorAll('button').forEach(b => b.disabled = true);

    fetch('volunteer-status.php', { method: 'POST', body })
        .then(r => r.json())
        .then(d => {
            if (d.ok) {
                // Update badge
                const badge = document.getElementById('statusBadge-' + prId);
                if (badge) badge.textContent = d.label;

                // Re-style buttons
                const colorMap = { on_way: 'warning', on_site: 'success', needs_help: 'danger' };
                if (group) {
                    group.querySelectorAll('button').forEach(b => {
                        const s = b.getAttribute('onclick').match(/'([^']+)'\s*\)$/)?.[1];
                        if (s) {
                            const c = colorMap[s];
                            b.className = 'btn btn-sm ' + (s === d.status ? 'btn-' + c : 'btn-outline-' + c);
                        }
                        b.disabled = false;
                    });
                }

                if (status === 'needs_help') {
                    // Flash the panel red
                    const panel = btn.closest('.card');
                    if (panel) { panel.style.animation = 'pulse-red 0.5s 3'; }
                }
            }
        })
        .catch(() => { if (group) group.querySelectorAll('button').forEach(b => b.disabled = false); });
}
</script>
<style>
@keyframes pulse-red {
  0%, 100% { box-shadow: 0 0 0 0 rgba(220,53,69,0); }
  50%      { box-shadow: 0 0 0 10px rgba(220,53,69,0.4); }
}
</style>

<?php include __DIR__ . '/includes/footer.php'; ?>
