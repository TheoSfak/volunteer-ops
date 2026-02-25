<?php
/**
 * VolunteerOps - Missions List
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();

$user = getCurrentUser();

// Filters
$status = get('status', STATUS_OPEN);
$department = get('department');
$missionType = (int)get('mission_type', 0);
$search = get('search');
$page = max(1, (int)get('page', 1));
$perPage = 20;

// Dynamic page title
$statusTitles = [
    STATUS_OPEN => 'Ενεργές Αποστολές',
    STATUS_CLOSED => 'Κλειστές Αποστολές',
    STATUS_COMPLETED => 'Ολοκληρωμένες Αποστολές',
    STATUS_DRAFT => 'Πρόχειρες Αποστολές',
    STATUS_CANCELED => 'Ακυρωμένες Αποστολές'
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

if ($missionType) {
    $where[] = 'm.mission_type_id = ?';
    $params[] = $missionType;
}

// Τ.Ε.Π. αποστολές: ορατές μόνο σε admins, δόκιμους και υπεύθυνο αποστολής
if (!canSeeTep()) {
    $where[] = '(m.mission_type_id != ? OR m.responsible_user_id = ?)';
    $params[] = getTepMissionTypeId();
    $params[] = $user['id'];
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
            mt.name as type_name, mt.color as type_color, mt.icon as type_icon,
            (SELECT COUNT(*) FROM shifts WHERE mission_id = m.id) as shift_count,
            (SELECT COUNT(*) FROM shifts s 
             JOIN participation_requests pr ON pr.shift_id = s.id 
             WHERE s.mission_id = m.id AND pr.status = '" . PARTICIPATION_APPROVED . "') as volunteer_count
     FROM missions m
     LEFT JOIN departments d ON m.department_id = d.id
     LEFT JOIN users u ON m.created_by = u.id
     LEFT JOIN mission_types mt ON m.mission_type_id = mt.id
     WHERE $whereClause
     ORDER BY m.start_datetime DESC
     LIMIT {$pagination['per_page']} OFFSET {$pagination['offset']}",
    $params
);

// Get departments for filter
$departments = dbFetchAll("SELECT id, name FROM departments WHERE is_active = 1 ORDER BY name");

// Get mission types for filter (TEP hidden from regular volunteers)
$tepMissionTypeId = getTepMissionTypeId();
if (canSeeTep()) {
    $missionTypesFilter = dbFetchAll("SELECT id, name FROM mission_types WHERE is_active = 1 ORDER BY sort_order");
} else {
    $missionTypesFilter = dbFetchAll("SELECT id, name FROM mission_types WHERE is_active = 1 AND id != ? ORDER BY sort_order", [$tepMissionTypeId]);
}

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">
        <i class="bi bi-flag me-2"></i>Αποστολές
    </h1>
    <?php if (isAdmin()): ?>
        <div class="d-flex gap-2">
            <a href="exports/export-missions.php?status=<?= h($status) ?>&department_id=<?= h($department) ?>&search=<?= h($search) ?>&mission_type=<?= h($missionType) ?>" 
               class="btn btn-outline-success">
                <i class="bi bi-download me-1"></i>Εξαγωγή CSV
            </a>
            <a href="exports/export-missions.php" 
               class="btn btn-outline-secondary" title="Εξαγωγή όλων των αποστολών">
                <i class="bi bi-download me-1"></i>Εξαγωγή Όλων
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
            <div class="col-md-2">
                <select class="form-select" name="mission_type">
                    <option value="">Όλοι οι τύποι</option>
                    <?php foreach ($missionTypesFilter as $mtf): ?>
                        <option value="<?= $mtf['id'] ?>" <?= $missionType == $mtf['id'] ? 'selected' : '' ?>>
                            <?= h($mtf['name']) ?>
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
                            <th>Τύπος</th>
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
                                <td>
                                    <?php if (!empty($mission['type_name'])): ?>
                                        <span class="badge bg-<?= h($mission['type_color'] ?? 'secondary') ?>">
                                            <i class="bi <?= h($mission['type_icon'] ?? 'bi-flag') ?>"></i>
                                            <?= h($mission['type_name']) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= h($mission['department_name'] ?? '-') ?></td>
                                <td>
                                    <?= formatDate($mission['start_datetime']) ?>
                                    <br>
                                    <small class="text-muted"><?= formatDateTime($mission['start_datetime'], 'H:i') ?></small>
                                </td>
                                <td><span class="badge bg-secondary"><?= $mission['shift_count'] ?></span></td>
                                <td><span class="badge bg-info"><?= $mission['volunteer_count'] ?></span></td>
                                <td>
                                    <?= statusBadge($mission['status']) ?>
                                    <?php
                                    // Show overdue badge for OPEN/CLOSED missions past their end date
                                    if (in_array($mission['status'], [STATUS_OPEN, STATUS_CLOSED]) && strtotime($mission['end_datetime']) < time()):
                                        $elapsed = time() - strtotime($mission['end_datetime']);
                                        $days = floor($elapsed / 86400);
                                        $hours = floor(($elapsed % 86400) / 3600);
                                        $elapsedText = '';
                                        if ($days > 0) $elapsedText .= $days . ' μέρ' . ($days == 1 ? 'α' : 'ες');
                                        if ($hours > 0) $elapsedText .= ($days > 0 ? ' και ' : '') . $hours . ' ώρ' . ($hours == 1 ? 'α' : 'ες');
                                        if (!$elapsedText) $elapsedText = 'Μόλις τώρα';
                                    ?>
                                        <br>
                                        <span class="badge bg-danger mt-1" title="Έληξε πριν <?= h($elapsedText) ?>" data-bs-toggle="tooltip">
                                            <i class="bi bi-clock-history me-1"></i>ΕΛΗΞΕ
                                        </span>
                                    <?php endif; ?>
                                </td>
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
