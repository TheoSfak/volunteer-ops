<?php
/**
 * VolunteerOps - Missions List
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();

$user = getCurrentUser();

// Filters
$status = get('status', 'OPEN');
$department = get('department');
$search = get('search');
$page = max(1, (int)get('page', 1));
$perPage = 20;

// Dynamic page title
$statusTitles = [
    'OPEN' => 'Ενεργές Αποστολές',
    'CLOSED' => 'Κλειστές Αποστολές',
    'COMPLETED' => 'Ολοκληρωμένες Αποστολές',
    'DRAFT' => 'Πρόχειρες Αποστολές',
    'CANCELED' => 'Ακυρωμένες Αποστολές'
];
$pageTitle = $statusTitles[$status] ?? 'Αποστολές';

// Build query
$where = ['m.deleted_at IS NULL'];
$params = [];

if ($status) {
    $where[] = 'm.status = ?';
    $params[] = $status;
}

if ($department) {
    $where[] = 'm.department_id = ?';
    $params[] = $department;
}

if ($search) {
    $where[] = '(m.title LIKE ? OR m.description LIKE ? OR m.location LIKE ?)';
    $searchTerm = "%$search%";
    $params = array_merge($params, [$searchTerm, $searchTerm, $searchTerm]);
}

// Department admin can only see their department
if ($user['role'] === ROLE_DEPARTMENT_ADMIN && $user['department_id']) {
    $where[] = 'm.department_id = ?';
    $params[] = $user['department_id'];
}

$whereClause = implode(' AND ', $where);

// Count total
$total = dbFetchValue("SELECT COUNT(*) FROM missions m WHERE $whereClause", $params);
$pagination = paginate($total, $page, $perPage);

// Fetch missions
$missions = dbFetchAll(
    "SELECT m.*, d.name as department_name, u.name as creator_name,
            (SELECT COUNT(*) FROM shifts WHERE mission_id = m.id) as shift_count,
            (SELECT COUNT(*) FROM shifts s 
             JOIN participation_requests pr ON pr.shift_id = s.id 
             WHERE s.mission_id = m.id AND pr.status = 'APPROVED') as volunteer_count
     FROM missions m
     LEFT JOIN departments d ON m.department_id = d.id
     LEFT JOIN users u ON m.created_by = u.id
     WHERE $whereClause
     ORDER BY m.start_datetime DESC
     LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}",
    $params
);

// Get departments for filter
$departments = dbFetchAll("SELECT id, name FROM departments WHERE is_active = 1 ORDER BY name");

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">
        <i class="bi bi-flag me-2"></i>Αποστολές
    </h1>
    <?php if (isAdmin()): ?>
        <div class="d-flex gap-2">
            <a href="exports/export-missions.php?status=<?= h($status) ?>&department_id=<?= h($department) ?>" 
               class="btn btn-outline-success">
                <i class="bi bi-download me-1"></i>Εξαγωγή CSV
            </a>
            <a href="mission-form.php" class="btn btn-primary">
                <i class="bi bi-plus-lg me-1"></i>Νέα Αποστολή
            </a>
        </div>
    <?php endif; ?>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body">
        <form method="get" class="row g-3">
            <div class="col-md-3">
                <input type="text" class="form-control" name="search" placeholder="Αναζήτηση..." 
                       value="<?= h($search) ?>">
            </div>
            <div class="col-md-2">
                <select class="form-select" name="status">
                    <option value="">Όλες οι καταστάσεις</option>
                    <?php foreach ($GLOBALS['STATUS_LABELS'] as $key => $label): ?>
                        <option value="<?= $key ?>" <?= $status === $key ? 'selected' : '' ?>>
                            <?= h($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <select class="form-select" name="department">
                    <option value="">Όλα τα τμήματα</option>
                    <?php foreach ($departments as $dept): ?>
                        <option value="<?= $dept['id'] ?>" <?= $department == $dept['id'] ? 'selected' : '' ?>>
                            <?= h($dept['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-outline-primary w-100">
                    <i class="bi bi-search me-1"></i>Φίλτρο
                </button>
            </div>
            <div class="col-md-2">
                <a href="missions.php" class="btn btn-outline-secondary w-100">Καθαρισμός</a>
            </div>
        </form>
    </div>
</div>

<!-- Results -->
<div class="card">
    <div class="card-body p-0">
        <?php if (empty($missions)): ?>
            <p class="text-muted text-center py-5">Δεν βρέθηκαν αποστολές.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Τίτλος</th>
                            <th>Τμήμα</th>
                            <th>Ημερομηνία</th>
                            <th>Βάρδιες</th>
                            <th>Εθελοντές</th>
                            <th>Κατάσταση</th>
                            <th class="text-end">Ενέργειες</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($missions as $mission): ?>
                            <tr>
                                <td>
                                    <a href="mission-view.php?id=<?= $mission['id'] ?>" class="text-decoration-none">
                                        <strong><?= h($mission['title']) ?></strong>
                                    </a>
                                    <?php if ($mission['is_urgent']): ?>
                                        <span class="badge bg-danger ms-1">Επείγον</span>
                                    <?php endif; ?>
                                    <br>
                                    <small class="text-muted">
                                        <i class="bi bi-geo-alt"></i> <?= h($mission['location']) ?>
                                    </small>
                                </td>
                                <td><?= h($mission['department_name'] ?? '-') ?></td>
                                <td>
                                    <?= formatDate($mission['start_datetime']) ?>
                                    <br>
                                    <small class="text-muted"><?= formatDateTime($mission['start_datetime'], 'H:i') ?></small>
                                </td>
                                <td><span class="badge bg-secondary"><?= $mission['shift_count'] ?></span></td>
                                <td><span class="badge bg-info"><?= $mission['volunteer_count'] ?></span></td>
                                <td><?= statusBadge($mission['status']) ?></td>
                                <td class="text-end">
                                    <a href="mission-view.php?id=<?= $mission['id'] ?>" class="btn btn-sm btn-outline-primary" title="Προβολή">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <?php if (isAdmin()): ?>
                                        <a href="mission-form.php?id=<?= $mission['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Επεξεργασία">
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
    
    <?php if ($pagination['total_pages'] > 1): ?>
        <div class="card-footer">
            <?= paginationLinks($pagination, '?status=' . urlencode($status) . '&department=' . urlencode($department) . '&search=' . urlencode($search) . '&') ?>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
