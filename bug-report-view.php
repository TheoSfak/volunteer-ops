<?php
/**
 * VolunteerOps - Bug Report View
 * Προβολή bug report (εθελοντής & admin)
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();

$id = (int) get('id');
if (!$id) {
    setFlash('error', 'Μη έγκυρο bug report.');
    redirect('my-bug-reports.php');
}

$bugReport = dbFetchOne(
    "SELECT b.*,
            u.name as user_name, u.email as user_email,
            responder.name as responded_by_name
     FROM bug_reports b
     JOIN users u ON b.user_id = u.id
     LEFT JOIN users responder ON b.responded_by = responder.id
     WHERE b.id = ?",
    [$id]
);

if (!$bugReport) {
    setFlash('error', 'Δεν βρέθηκε το bug report.');
    redirect('my-bug-reports.php');
}

$isOwner = ($bugReport['user_id'] == getCurrentUserId());
$isAdmin = isSystemAdmin();
if (!$isOwner && !$isAdmin) {
    setFlash('error', 'Δεν έχετε πρόσβαση σε αυτό το bug report.');
    redirect('my-bug-reports.php');
}

$pageTitle = 'Bug Report #' . $id;

if (isPost() && $isAdmin) {
    verifyCsrf();
    $action = post('action');

    switch ($action) {
        case 'respond':
            $response = trim(post('admin_response'));
            $newStatus = post('new_status', $bugReport['status']);

            if (empty($response)) {
                setFlash('error', 'Η απάντηση είναι υποχρεωτική.');
                redirect('bug-report-view.php?id=' . $id);
            }

            if (!array_key_exists($newStatus, BUG_REPORT_STATUS_LABELS)) {
                $newStatus = BUG_REPORT_IN_REVIEW;
            }

            dbExecute(
                "UPDATE bug_reports SET admin_response = ?, status = ?, responded_by = ?, responded_at = NOW() WHERE id = ?",
                [$response, $newStatus, getCurrentUserId(), $id]
            );

            logAudit('respond_bug_report', 'bug_reports', $id);

            sendNotification(
                $bugReport['user_id'],
                'Απάντηση στο Bug Report #' . $id,
                'Υπάρχει απάντηση στην αναφορά σας: ' . mb_strimwidth($bugReport['description'], 0, 60, '…'),
                'bug_report'
            );

            setFlash('success', 'Η απάντηση αποθηκεύτηκε.');
            redirect('bug-report-view.php?id=' . $id);
            break;

        case 'change_status':
            $newStatus = post('new_status');
            if (array_key_exists($newStatus, BUG_REPORT_STATUS_LABELS)) {
                dbExecute("UPDATE bug_reports SET status = ? WHERE id = ?", [$newStatus, $id]);
                logAudit('change_bug_report_status', 'bug_reports', $id);
                setFlash('success', 'Η κατάσταση ενημερώθηκε.');
            }
            redirect('bug-report-view.php?id=' . $id);
            break;

        case 'delete':
            if (!empty($bugReport['screenshot'])) {
                $screenshotPath = __DIR__ . '/uploads/bug_reports/' . $bugReport['screenshot'];
                if (file_exists($screenshotPath)) {
                    unlink($screenshotPath);
                }
            }
            dbExecute("DELETE FROM bug_reports WHERE id = ?", [$id]);
            logAudit('delete_bug_report', 'bug_reports', $id);
            setFlash('success', 'Το bug report #' . $id . ' διαγράφηκε επιτυχώς.');
            redirect('bug-reports.php');
            break;
    }
}

$backUrl = $isAdmin ? 'bug-reports.php' : 'my-bug-reports.php';

include __DIR__ . '/includes/header.php';
?>

<div class="container-fluid">
    <div class="row justify-content-center">
        <div class="col-lg-9 col-xl-8">

            <div class="d-flex align-items-center mb-4">
                <a href="<?= h($backUrl) ?>" class="btn btn-outline-secondary me-3">
                    <i class="bi bi-arrow-left"></i>
                </a>
                <h2 class="mb-0">
                    <i class="bi bi-bug me-2"></i>Bug Report #<?= $id ?>
                    <span class="badge bg-<?= BUG_REPORT_STATUS_COLORS[$bugReport['status']] ?? 'secondary' ?> ms-2">
                        <?= h(BUG_REPORT_STATUS_LABELS[$bugReport['status']] ?? $bugReport['status']) ?>
                    </span>
                </h2>
            </div>

            <?= displayFlash() ?>

            <!-- Bug Report Details -->
            <div class="card shadow-sm mb-4">
                <div class="card-header bg-light">
                    <h5 class="mb-0"><i class="bi bi-info-circle me-2"></i>Στοιχεία Αναφοράς</h5>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <small class="text-muted d-block">Υποβολή από</small>
                            <strong><?= h($bugReport['user_name']) ?></strong>
                            <small class="text-muted">(<?= h($bugReport['user_email']) ?>)</small>
                        </div>
                        <div class="col-md-6">
                            <small class="text-muted d-block">Ημερομηνία</small>
                            <strong><?= formatDateTime($bugReport['created_at']) ?></strong>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <small class="text-muted d-block">Σελίδα</small>
                            <strong><?= h($bugReport['page_url'] ?: '—') ?></strong>
                        </div>
                        <div class="col-md-6">
                            <small class="text-muted d-block">Έκδοση εφαρμογής</small>
                            <strong><?= $bugReport['app_version'] ? 'v' . h($bugReport['app_version']) : '—' ?></strong>
                        </div>
                    </div>

                    <hr>

                    <small class="text-muted d-block mb-1">Περιγραφή</small>
                    <div class="bg-light rounded p-3" style="white-space: pre-wrap;"><?= h($bugReport['description']) ?></div>

                    <?php if (!empty($bugReport['screenshot']) && file_exists(__DIR__ . '/uploads/bug_reports/' . $bugReport['screenshot'])): ?>
                        <hr>
                        <small class="text-muted d-block mb-2">Στιγμιότυπο οθόνης</small>
                        <a href="uploads/bug_reports/<?= h($bugReport['screenshot']) ?>" target="_blank" rel="noopener">
                            <img src="uploads/bug_reports/<?= h($bugReport['screenshot']) ?>" class="img-fluid rounded border" style="max-height: 400px;" alt="">
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Admin Response (if exists) -->
            <?php if (!empty($bugReport['admin_response'])): ?>
                <div class="card shadow-sm mb-4 border-success">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0"><i class="bi bi-reply me-2"></i>Απάντηση Προγραμματιστή</h5>
                    </div>
                    <div class="card-body">
                        <div class="d-flex justify-content-between mb-2">
                            <small class="text-muted">
                                Από: <strong><?= h($bugReport['responded_by_name'] ?? 'Διαχειριστής') ?></strong>
                            </small>
                            <small class="text-muted"><?= $bugReport['responded_at'] ? formatDateTime($bugReport['responded_at']) : '' ?></small>
                        </div>
                        <div class="bg-light rounded p-3" style="white-space: pre-wrap;"><?= h($bugReport['admin_response']) ?></div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Admin Actions -->
            <?php if ($isAdmin): ?>
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-warning bg-opacity-25">
                        <h5 class="mb-0"><i class="bi bi-pencil-square me-2"></i>Ενέργειες Διαχειριστή</h5>
                    </div>
                    <div class="card-body">
                        <!-- Change Status -->
                        <div class="d-flex align-items-center mb-4">
                            <form method="post" class="d-flex align-items-center gap-2">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="change_status">
                                <label class="fw-semibold me-2 text-nowrap">Αλλαγή Κατάστασης:</label>
                                <select name="new_status" class="form-select form-select-sm" style="width:auto;">
                                    <?php foreach (BUG_REPORT_STATUS_LABELS as $key => $label): ?>
                                        <option value="<?= h($key) ?>" <?= $bugReport['status'] === $key ? 'selected' : '' ?>><?= h($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="btn btn-sm btn-outline-primary">Ενημέρωση</button>
                            </form>
                        </div>

                        <hr>

                        <!-- Respond -->
                        <form method="post">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="respond">

                            <div class="mb-3">
                                <label for="admin_response" class="form-label fw-semibold">
                                    <?= empty($bugReport['admin_response']) ? 'Απάντηση' : 'Ενημέρωση Απάντησης' ?>
                                </label>
                                <textarea name="admin_response" id="admin_response" class="form-control" rows="5" required
                                    placeholder="π.χ. Διορθώθηκε στην έκδοση 3.152.1..."><?= h($bugReport['admin_response'] ?? '') ?></textarea>
                            </div>

                            <div class="row align-items-end">
                                <div class="col-md-4 mb-3">
                                    <label for="respond_status" class="form-label">Νέα Κατάσταση</label>
                                    <select name="new_status" id="respond_status" class="form-select">
                                        <?php foreach (BUG_REPORT_STATUS_LABELS as $key => $label): ?>
                                            <option value="<?= h($key) ?>" <?= $bugReport['status'] === $key ? 'selected' : '' ?>><?= h($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-8 mb-3">
                                    <button type="submit" class="btn btn-success">
                                        <i class="bi bi-send me-1"></i>Αποθήκευση Απάντησης
                                    </button>
                                </div>
                            </div>
                        </form>

                        <hr>

                        <!-- Delete -->
                        <button type="button" class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#deleteBugReportModal">
                            <i class="bi bi-trash me-1"></i>Διαγραφή Bug Report
                        </button>
                    </div>
                </div>

                <!-- Delete Confirmation Modal -->
                <div class="modal fade" id="deleteBugReportModal" tabindex="-1">
                    <div class="modal-dialog">
                        <div class="modal-content">
                            <form method="post">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="delete">
                                <div class="modal-header bg-danger text-white">
                                    <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-2"></i>Διαγραφή Bug Report</h5>
                                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                </div>
                                <div class="modal-body">
                                    <p>Είστε σίγουρος/η ότι θέλετε να διαγράψετε το bug report <strong>#<?= $id ?></strong>;</p>
                                    <p class="text-muted mb-0"><strong>Από:</strong> <?= h($bugReport['user_name']) ?></p>
                                    <div class="alert alert-warning mt-3 mb-0">
                                        <i class="bi bi-exclamation-triangle me-1"></i>Η ενέργεια αυτή είναι μη αναστρέψιμη.
                                    </div>
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

        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
