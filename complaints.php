<?php
/**
 * VolunteerOps - Complaints Management (Admin)
 * Διαχείριση παραπόνων
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();
requireRole([ROLE_SYSTEM_ADMIN, ROLE_DEPARTMENT_ADMIN]);

$pageTitle = 'Διαχείριση Παραπόνων';

// Filters
$filterStatus = get('status');
$filterCategory = get('category');
$filterPriority = get('priority');
$search = get('search');

// Build query
$where = [];
$params = [];

if ($filterStatus && array_key_exists($filterStatus, COMPLAINT_STATUS_LABELS)) {
    $where[] = "c.status = ?";
    $params[] = $filterStatus;
}
if ($filterCategory && array_key_exists($filterCategory, COMPLAINT_CATEGORY_LABELS)) {
    $where[] = "c.category = ?";
    $params[] = $filterCategory;
}
if ($filterPriority && array_key_exists($filterPriority, COMPLAINT_PRIORITY_LABELS)) {
    $where[] = "c.priority = ?";
    $params[] = $filterPriority;
}
if ($search) {
    $where[] = "(c.subject LIKE ? OR c.body LIKE ? OR u.name LIKE ?)";
    $searchParam = '%' . $search . '%';
    $params[] = $searchParam;
    $params[] = $searchParam;
    $params[] = $searchParam;
}

$whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// Count for pagination
$total = dbFetchValue("SELECT COUNT(*) FROM complaints c JOIN users u ON c.user_id = u.id $whereClause", $params);
$pagination = paginate($total, (int) get('page', 1), 20);

// Fetch complaints
$complaints = dbFetchAll(
    "SELECT c.*, 
            u.name as user_name, u.email as user_email,
            m.title as mission_title,
            responder.name as responded_by_name
     FROM complaints c
     JOIN users u ON c.user_id = u.id
     LEFT JOIN missions m ON c.mission_id = m.id
     LEFT JOIN users responder ON c.responded_by = responder.id
     $whereClause
     ORDER BY 
        CASE c.status WHEN 'NEW' THEN 0 WHEN 'IN_REVIEW' THEN 1 WHEN 'RESOLVED' THEN 2 WHEN 'REJECTED' THEN 3 END,
        CASE c.priority WHEN 'HIGH' THEN 0 WHEN 'MEDIUM' THEN 1 WHEN 'LOW' THEN 2 END,
        c.created_at DESC
     LIMIT ? OFFSET ?",
    array_merge($params, [$pagination['per_page'], $pagination['offset']])
);

// Stats
$stats = dbFetchOne(
    "SELECT 
        COUNT(*) as total,
        SUM(status = 'NEW') as new_count,
        SUM(status = 'IN_REVIEW') as in_review_count,
        SUM(status = 'RESOLVED') as resolved_count,
        SUM(status = 'REJECTED') as rejected_count
     FROM complaints"
);

include __DIR__ . '/includes/header.php';
?>

<div class="container-fluid">
    <h2 class="mb-4"><i class="bi bi-chat-left-dots me-2"></i>Διαχείριση Παραπόνων</h2>
    
    <?= showFlash() ?>
    
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
                <div class="col-md-3">
                    <label class="form-label small mb-1">Αναζήτηση</label>
                    <input type="text" name="search" class="form-control form-control-sm" value="<?= h($search) ?>" placeholder="Θέμα, κείμενο, όνομα...">
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1">Κατάσταση</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="">Όλες</option>
                        <?php foreach (COMPLAINT_STATUS_LABELS as $key => $label): ?>
                            <option value="<?= h($key) ?>" <?= $filterStatus === $key ? 'selected' : '' ?>><?= h($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1">Κατηγορία</label>
                    <select name="category" class="form-select form-select-sm">
                        <option value="">Όλες</option>
                        <?php foreach (COMPLAINT_CATEGORY_LABELS as $key => $label): ?>
                            <option value="<?= h($key) ?>" <?= $filterCategory === $key ? 'selected' : '' ?>><?= h($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small mb-1">Προτεραιότητα</label>
                    <select name="priority" class="form-select form-select-sm">
                        <option value="">Όλες</option>
                        <?php foreach (COMPLAINT_PRIORITY_LABELS as $key => $label): ?>
                            <option value="<?= h($key) ?>" <?= $filterPriority === $key ? 'selected' : '' ?>><?= h($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 d-flex gap-1">
                    <button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-search me-1"></i>Φίλτρο</button>
                    <a href="complaints.php" class="btn btn-sm btn-outline-secondary">Καθαρισμός</a>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Complaints Table -->
    <div class="card shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Εθελοντής</th>
                        <th>Θέμα</th>
                        <th>Κατηγορία</th>
                        <th>Προτεραιότητα</th>
                        <th>Αποστολή</th>
                        <th>Κατάσταση</th>
                        <th>Ημ/νία</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($complaints)): ?>
                        <tr>
                            <td colspan="9" class="text-center py-4 text-muted">Δεν βρέθηκαν παράπονα.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($complaints as $c): ?>
                            <tr class="<?= $c['status'] === COMPLAINT_NEW ? 'table-info bg-opacity-25' : '' ?>">
                                <td><?= $c['id'] ?></td>
                                <td>
                                    <strong><?= h($c['user_name']) ?></strong>
                                    <br><small class="text-muted"><?= h($c['user_email']) ?></small>
                                </td>
                                <td>
                                    <a href="complaint-view.php?id=<?= $c['id'] ?>" class="text-decoration-none">
                                        <strong><?= h($c['subject']) ?></strong>
                                    </a>
                                    <?php if (!empty($c['admin_response'])): ?>
                                        <br><small class="text-success"><i class="bi bi-reply"></i> Απαντήθηκε</small>
                                    <?php endif; ?>
                                </td>
                                <td><small><?= h(COMPLAINT_CATEGORY_LABELS[$c['category']] ?? $c['category']) ?></small></td>
                                <td>
                                    <span class="badge bg-<?= COMPLAINT_PRIORITY_COLORS[$c['priority']] ?? 'secondary' ?>">
                                        <?= h(COMPLAINT_PRIORITY_LABELS[$c['priority']] ?? $c['priority']) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($c['mission_title']): ?>
                                        <small><?= h($c['mission_title']) ?></small>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-<?= COMPLAINT_STATUS_COLORS[$c['status']] ?? 'secondary' ?>">
                                        <?= h(COMPLAINT_STATUS_LABELS[$c['status']] ?? $c['status']) ?>
                                    </span>
                                </td>
                                <td><small><?= formatDateTime($c['created_at']) ?></small></td>
                                <td>
                                    <a href="complaint-view.php?id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-primary" title="Προβολή / Απάντηση">
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
