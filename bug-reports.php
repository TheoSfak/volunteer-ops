<?php
/**
 * VolunteerOps - Bug Reports Management (Admin)
 * Διαχείριση αναφορών bug
 */

require_once __DIR__ . '/bootstrap.php';
requireRole([ROLE_SYSTEM_ADMIN]);

$pageTitle = 'Διαχείριση Bug Reports';

// Filters
$filterStatus = get('status');
$search = get('search');

$where = [];
$params = [];

if ($filterStatus && array_key_exists($filterStatus, BUG_REPORT_STATUS_LABELS)) {
    $where[] = "b.status = ?";
    $params[] = $filterStatus;
}
if ($search) {
    $where[] = "(b.description LIKE ? OR u.name LIKE ?)";
    $searchParam = '%' . dbEscape($search) . '%';
    $params[] = $searchParam;
    $params[] = $searchParam;
}

$whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

$total = dbFetchValue("SELECT COUNT(*) FROM bug_reports b JOIN users u ON b.user_id = u.id $whereClause", $params);
$pagination = paginate($total, (int) get('page', 1), 20);

$bugReports = dbFetchAll(
    "SELECT b.*, u.name as user_name, u.email as user_email
     FROM bug_reports b
     JOIN users u ON b.user_id = u.id
     $whereClause
     ORDER BY
        CASE b.status WHEN 'NEW' THEN 0 WHEN 'IN_REVIEW' THEN 1 WHEN 'RESOLVED' THEN 2 WHEN 'REJECTED' THEN 3 END,
        b.created_at DESC
     LIMIT ? OFFSET ?",
    array_merge($params, [$pagination['per_page'], $pagination['offset']])
);

$stats = dbFetchOne(
    "SELECT
        COUNT(*) as total,
        SUM(status = ?) as new_count,
        SUM(status = ?) as in_review_count,
        SUM(status = ?) as resolved_count,
        SUM(status = ?) as rejected_count
     FROM bug_reports",
    [BUG_REPORT_NEW, BUG_REPORT_IN_REVIEW, BUG_REPORT_RESOLVED, BUG_REPORT_REJECTED]
);

include __DIR__ . '/includes/header.php';
?>

<div class="container-fluid">
    <h2 class="mb-4"><i class="bi bi-bug me-2"></i>Διαχείριση Bug Reports</h2>

    <?= displayFlash() ?>

    <!-- Stats Cards -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card shadow-sm border-primary">
                <div class="card-body text-center py-3">
                    <h3 class="mb-0 text-primary"><?= $stats['new_count'] ?? 0 ?></h3>
                    <small class="text-muted">Νέα</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card shadow-sm border-warning">
                <div class="card-body text-center py-3">
                    <h3 class="mb-0 text-warning"><?= $stats['in_review_count'] ?? 0 ?></h3>
                    <small class="text-muted">Σε Εξέταση</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card shadow-sm border-success">
                <div class="card-body text-center py-3">
                    <h3 class="mb-0 text-success"><?= $stats['resolved_count'] ?? 0 ?></h3>
                    <small class="text-muted">Επιλύθηκαν</small>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card shadow-sm border-danger">
                <div class="card-body text-center py-3">
                    <h3 class="mb-0 text-danger"><?= $stats['rejected_count'] ?? 0 ?></h3>
                    <small class="text-muted">Απορρίφθηκαν</small>
                </div>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="card shadow-sm mb-4">
        <div class="card-body py-3">
            <form method="get" class="row g-2 align-items-end">
                <div class="col-md-5">
                    <label class="form-label small mb-1">Αναζήτηση</label>
                    <input type="text" name="search" class="form-control form-control-sm" value="<?= h($search) ?>" placeholder="Περιγραφή, όνομα...">
                </div>
                <div class="col-md-3">
                    <label class="form-label small mb-1">Κατάσταση</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="">Όλες</option>
                        <?php foreach (BUG_REPORT_STATUS_LABELS as $key => $label): ?>
                            <option value="<?= h($key) ?>" <?= $filterStatus === $key ? 'selected' : '' ?>><?= h($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4 d-flex gap-1">
                    <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-search me-1"></i>Φίλτρο</button>
                    <a href="bug-reports.php" class="btn btn-sm btn-outline-secondary">Καθαρισμός</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Bug Reports Table -->
    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Χρήστης</th>
                        <th>Περιγραφή</th>
                        <th>Σελίδα</th>
                        <th>Έκδοση</th>
                        <th>Κατάσταση</th>
                        <th>Ημ/νία</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($bugReports)): ?>
                        <tr>
                            <td colspan="8" class="text-center py-4 text-muted">Δεν βρέθηκαν αναφορές.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($bugReports as $b): ?>
                            <tr class="<?= $b['status'] === BUG_REPORT_NEW ? 'table-info bg-opacity-25' : '' ?>">
                                <td><?= $b['id'] ?></td>
                                <td>
                                    <strong><?= h($b['user_name']) ?></strong>
                                    <br><small class="text-muted"><?= h($b['user_email']) ?></small>
                                </td>
                                <td>
                                    <a href="bug-report-view.php?id=<?= $b['id'] ?>" class="text-decoration-none">
                                        <?= h(mb_strimwidth($b['description'], 0, 60, '…')) ?>
                                    </a>
                                    <?php if (!empty($b['screenshot'])): ?>
                                        <br><small class="text-muted"><i class="bi bi-image"></i> Έχει screenshot</small>
                                    <?php endif; ?>
                                </td>
                                <td><small class="text-muted"><?= h(mb_strimwidth($b['page_url'] ?? '—', 0, 30, '…')) ?></small></td>
                                <td><small class="text-muted"><?= $b['app_version'] ? 'v' . h($b['app_version']) : '—' ?></small></td>
                                <td>
                                    <span class="badge bg-<?= BUG_REPORT_STATUS_COLORS[$b['status']] ?? 'secondary' ?>">
                                        <?= h(BUG_REPORT_STATUS_LABELS[$b['status']] ?? $b['status']) ?>
                                    </span>
                                </td>
                                <td><small><?= formatDateTime($b['created_at']) ?></small></td>
                                <td>
                                    <a href="bug-report-view.php?id=<?= $b['id'] ?>" class="btn btn-sm btn-outline-primary" title="Προβολή / Απάντηση">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if ($total > $pagination['per_page']): ?>
            <div class="card-footer">
                <?= paginationLinks($pagination) ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
