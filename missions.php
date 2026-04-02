<?php
/**
 * VolunteerOps - Missions List
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();

$user = getCurrentUser();

// Handle duplicate action
if (isPost() && post('action') === 'duplicate' && isAdmin()) {
    verifyCsrf();
    $srcId = (int)post('mission_id');
    $src = dbFetchOne(
        "SELECT * FROM missions WHERE id = ? AND deleted_at IS NULL",
        [$srcId]
    );
    if ($src) {
        $newId = dbInsert(
            "INSERT INTO missions
             (title, description, mission_type_id, department_id, location, location_details,
              latitude, longitude, start_datetime, end_datetime, requirements, notes,
              is_urgent, status, responsible_user_id, created_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
            [
                'Αντίγραφο: ' . $src['title'],
                $src['description'],
                $src['mission_type_id'],
                $src['department_id'],
                $src['location'],
                $src['location_details'],
                $src['latitude'],
                $src['longitude'],
                $src['start_datetime'],
                $src['end_datetime'],
                $src['requirements'],
                $src['notes'],
                $src['is_urgent'],
                STATUS_DRAFT,
                $src['responsible_user_id'],
                $user['id'],
            ]
        );
        logAudit('duplicate', 'missions', $newId, null, ['source_id' => $srcId]);
        setFlash('success', 'Η αποστολή αντιγράφηκε. Μπορείτε να την επεξεργαστείτε.');
        redirect('mission-form.php?id=' . $newId);
    }
    setFlash('error', 'Δεν βρέθηκε η αποστολή.');
    redirect('missions.php');
}

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
    $searchTerm = '%' . dbEscape($search) . '%';
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
            m.recurrence_id,
            COUNT(DISTINCT sh.id) as shift_count,
            COUNT(DISTINCT CASE WHEN pr.status = '" . PARTICIPATION_APPROVED . "' THEN pr.id END) as volunteer_count,
            (SELECT COALESCE(SUM(s2.max_volunteers), 0) FROM shifts s2 WHERE s2.mission_id = m.id) as max_volunteers
     FROM missions m
     LEFT JOIN departments d ON m.department_id = d.id
     LEFT JOIN users u ON m.created_by = u.id
     LEFT JOIN mission_types mt ON m.mission_type_id = mt.id
     LEFT JOIN shifts sh ON sh.mission_id = m.id
     LEFT JOIN participation_requests pr ON pr.shift_id = sh.id
     WHERE $whereClause
     GROUP BY m.id
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

<style>
.vol-pill {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 7px 16px;
    border-radius: 50px;
    font-weight: 700;
    font-size: 0.9rem;
    color: #fff;
    white-space: nowrap;
    transition: all .3s ease;
    text-shadow: 0 1px 3px rgba(0,0,0,.35);
    width: 100%;
}
.vol-pill .vol-num {
    font-size: 1rem;
    letter-spacing: 0.5px;
}
.vol-pill .bi {
    font-size: 0.95rem;
}
.vol-pill-danger {
    background: linear-gradient(90deg, #dc3545 var(--fill), #6c2029 var(--fill));
    box-shadow: 0 2px 8px rgba(220,53,69,.3);
}
.vol-pill-warning {
    background: linear-gradient(90deg, #fd7e14 var(--fill), #7a3d0a var(--fill));
    box-shadow: 0 2px 8px rgba(253,126,20,.3);
}
.vol-pill-success {
    background: linear-gradient(90deg, #198754 var(--fill), #0a4a2e var(--fill));
    box-shadow: 0 3px 12px rgba(25,135,84,.35);
    animation: pill-glow 1.5s ease-in-out infinite;
}
@keyframes pill-glow {
    0%, 100% { box-shadow: 0 2px 8px rgba(25,135,84,.3); }
    50% { box-shadow: 0 4px 20px rgba(32,201,151,.6); }
}
.vol-label {
    font-size: 0.78rem;
    font-weight: 600;
    margin-top: 4px;
    display: flex;
    align-items: center;
    gap: 4px;
}
</style>

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
            <!-- Desktop/Tablet table view (hidden on portrait phones) -->
            <div class="table-responsive d-none d-sm-block">
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
                                    <?php if ($mission['recurrence_id']): ?>
                                        <span class="badge bg-info text-dark ms-1" title="Αποστολή σε επαναλαμβανόμενη σειρά">
                                            <i class="bi bi-arrow-repeat me-1"></i>Σειρά
                                        </span>
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
                                <td style="min-width: 140px;">
                                    <?php if ($mission['status'] === STATUS_OPEN && $mission['max_volunteers'] > 0):
                                        $vPct = min(100, round(($mission['volunteer_count'] / $mission['max_volunteers']) * 100));
                                        $vColor = $vPct >= 100 ? 'success' : ($vPct >= 50 ? 'warning' : 'danger');
                                    ?>
                                        <div class="text-center">
                                            <span class="vol-pill vol-pill-<?= $vColor ?>" style="--fill: <?= $vPct ?>%;">
                                                <span class="vol-num"><?= $mission['volunteer_count'] ?>/<?= $mission['max_volunteers'] ?></span>
                                                <i class="bi bi-people-fill"></i>
                                            </span>
                                            <div class="vol-label justify-content-center text-<?= $vColor ?>">
                                                <?php if ($vPct >= 100): ?>
                                                    <i class="bi bi-check-circle-fill"></i> Πλήρης!
                                                <?php elseif ($vPct == 0): ?>
                                                    <i class="bi bi-exclamation-triangle-fill"></i> Χρειάζονται εθελοντές
                                                <?php else: ?>
                                                    <i class="bi bi-hourglass-split"></i> Απομένουν <?= $mission['max_volunteers'] - $mission['volunteer_count'] ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <?= statusBadge($mission['status']) ?>
                                    <?php endif; ?>
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
                                        <form method="post" class="d-inline">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="duplicate">
                                            <input type="hidden" name="mission_id" value="<?= $mission['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-info" title="Αντιγραφή">
                                                <i class="bi bi-copy"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Mobile card view (visible only on portrait phones <576px) -->
            <div class="d-sm-none mobile-cards-container p-2">
                <?php foreach ($missions as $mission): ?>
                    <div class="card mobile-card">
                        <div class="card-body">
                            <div class="mobile-card-header">
                                <div>
                                    <a href="mission-view.php?id=<?= $mission['id'] ?>" class="text-decoration-none">
                                        <strong><?= h($mission['title']) ?></strong>
                                    </a>
                                    <?php if ($mission['is_urgent']): ?>
                                        <span class="badge bg-danger ms-1">Επείγον</span>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <?= statusBadge($mission['status']) ?>
                                    <?php
                                    if (in_array($mission['status'], [STATUS_OPEN, STATUS_CLOSED]) && strtotime($mission['end_datetime']) < time()):
                                        $elapsed = time() - strtotime($mission['end_datetime']);
                                        $days = floor($elapsed / 86400);
                                        $hours = floor(($elapsed % 86400) / 3600);
                                        $elapsedText = '';
                                        if ($days > 0) $elapsedText .= $days . ' μέρ' . ($days == 1 ? 'α' : 'ες');
                                        if ($hours > 0) $elapsedText .= ($days > 0 ? ' και ' : '') . $hours . ' ώρ' . ($hours == 1 ? 'α' : 'ες');
                                        if (!$elapsedText) $elapsedText = 'Μόλις τώρα';
                                    ?>
                                        <span class="badge bg-danger" title="Έληξε πριν <?= h($elapsedText) ?>">
                                            <i class="bi bi-clock-history me-1"></i>ΕΛΗΞΕ
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="mobile-card-meta">
                                <?php if (!empty($mission['type_name'])): ?>
                                    <span class="badge bg-<?= h($mission['type_color'] ?? 'secondary') ?>">
                                        <i class="bi <?= h($mission['type_icon'] ?? 'bi-flag') ?>"></i>
                                        <?= h($mission['type_name']) ?>
                                    </span>
                                <?php endif; ?>
                                <?php if (!empty($mission['department_name'])): ?>
                                    <span class="badge bg-light text-dark border"><?= h($mission['department_name']) ?></span>
                                <?php endif; ?>
                            </div>
                            
                            <div class="mobile-card-row">
                                <small class="text-muted">
                                    <i class="bi bi-geo-alt me-1"></i><?= h($mission['location']) ?>
                                </small>
                            </div>
                            <div class="mobile-card-row">
                                <small>
                                    <i class="bi bi-calendar me-1 text-muted"></i><?= formatDate($mission['start_datetime']) ?>
                                    <span class="text-muted ms-1"><?= formatDateTime($mission['start_datetime'], 'H:i') ?></span>
                                </small>
                            </div>
                            <?php if ($mission['status'] === STATUS_OPEN && $mission['max_volunteers'] > 0):
                                $mPct = min(100, round(($mission['volunteer_count'] / $mission['max_volunteers']) * 100));
                                $mColor = $mPct >= 100 ? 'success' : ($mPct >= 50 ? 'warning' : 'danger');
                            ?>
                            <div class="mobile-card-row">
                                <span class="vol-pill vol-pill-<?= $mColor ?>" style="--fill: <?= $mPct ?>%;">
                                    <span class="vol-num"><?= $mission['volunteer_count'] ?>/<?= $mission['max_volunteers'] ?></span>
                                    <i class="bi bi-people-fill"></i>
                                </span>
                                <span class="vol-label d-inline-flex ms-2 text-<?= $mColor ?>">
                                    <?php if ($mPct >= 100): ?>
                                        <i class="bi bi-check-circle-fill"></i> Πλήρης!
                                    <?php elseif ($mPct == 0): ?>
                                        <i class="bi bi-exclamation-triangle-fill"></i> Κενό
                                    <?php else: ?>
                                        <i class="bi bi-hourglass-split"></i> -<?= $mission['max_volunteers'] - $mission['volunteer_count'] ?>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <div class="mobile-card-row">
                                <small>
                                    <span class="badge bg-secondary"><?= $mission['shift_count'] ?></span>
                                    <span class="text-muted ms-1">βάρδιες</span>
                                </small>
                            </div>
                            <?php else: ?>
                            <div class="mobile-card-row">
                                <small>
                                    <span class="badge bg-secondary"><?= $mission['shift_count'] ?></span>
                                    <span class="text-muted ms-1">βάρδιες</span>
                                    <span class="badge bg-info ms-2"><?= $mission['volunteer_count'] ?></span>
                                    <span class="text-muted ms-1">εθελοντές</span>
                                </small>
                            </div>
                            <?php endif; ?>
                            
                            <div class="mobile-card-actions">
                                <a href="mission-view.php?id=<?= $mission['id'] ?>" class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-eye me-1"></i>Προβολή
                                </a>
                                <?php if (isAdmin()): ?>
                                    <a href="mission-form.php?id=<?= $mission['id'] ?>" class="btn btn-sm btn-outline-secondary">
                                        <i class="bi bi-pencil me-1"></i>Επεξεργασία
                                    </a>
                                    <form method="post" class="d-inline">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="duplicate">
                                        <input type="hidden" name="mission_id" value="<?= $mission['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-info">
                                            <i class="bi bi-copy me-1"></i>Αντιγραφή
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
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
