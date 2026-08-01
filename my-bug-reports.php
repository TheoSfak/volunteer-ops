<?php
/**
 * VolunteerOps - My Bug Reports
 * Τα bug reports του εθελοντή
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();

$pageTitle = 'Τα Bug μου';
$userId = getCurrentUserId();

$bugReports = dbFetchAll(
    "SELECT b.*, responder.name as responded_by_name
     FROM bug_reports b
     LEFT JOIN users responder ON b.responded_by = responder.id
     WHERE b.user_id = ?
     ORDER BY b.created_at DESC",
    [$userId]
);

include __DIR__ . '/includes/header.php';
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2><i class="bi bi-bug me-2"></i>Τα Bug μου</h2>
        <a href="bug-report.php" class="btn btn-primary">
            <i class="bi bi-plus-lg me-1"></i>Αποστολή Bug
        </a>
    </div>

    <?= displayFlash() ?>

    <?php if (empty($bugReports)): ?>
        <div class="card shadow-sm">
            <div class="card-body text-center py-5">
                <i class="bi bi-bug text-muted" style="font-size: 3rem;"></i>
                <p class="text-muted mt-3 mb-0">Δεν έχετε αναφέρει κανένα πρόβλημα.</p>
            </div>
        </div>
    <?php else: ?>
        <div class="card shadow-sm">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Περιγραφή</th>
                            <th>Έκδοση</th>
                            <th>Κατάσταση</th>
                            <th>Ημ/νία</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($bugReports as $b): ?>
                            <tr>
                                <td><?= $b['id'] ?></td>
                                <td>
                                    <?= h(mb_strimwidth($b['description'], 0, 80, '…')) ?>
                                    <?php if (!empty($b['admin_response'])): ?>
                                        <br><small class="text-success"><i class="bi bi-reply"></i> Υπάρχει απάντηση</small>
                                    <?php endif; ?>
                                </td>
                                <td><small class="text-muted"><?= $b['app_version'] ? 'v' . h($b['app_version']) : '—' ?></small></td>
                                <td>
                                    <span class="badge bg-<?= BUG_REPORT_STATUS_COLORS[$b['status']] ?? 'secondary' ?>">
                                        <?= h(BUG_REPORT_STATUS_LABELS[$b['status']] ?? $b['status']) ?>
                                    </span>
                                </td>
                                <td><small><?= formatDateTime($b['created_at']) ?></small></td>
                                <td>
                                    <a href="bug-report-view.php?id=<?= $b['id'] ?>" class="btn btn-sm btn-outline-primary" title="Προβολή">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
