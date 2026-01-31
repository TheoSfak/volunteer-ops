<?php
/**
 * VolunteerOps - Volunteers Management
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();
requireRole([ROLE_SYSTEM_ADMIN, ROLE_DEPARTMENT_ADMIN]);

$pageTitle = 'Εθελοντές';
$user = getCurrentUser();

// Filters
$search = get('search', '');
$role = get('role', '');
$departmentId = get('department_id', '');
$status = get('status', '');
$page = max(1, (int) get('page', 1));
$perPage = 20;

// Build query
$where = ['1=1'];
$params = [];

if ($search) {
    $where[] = "(u.name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($role) {
    $where[] = "u.role = ?";
    $params[] = $role;
}

if ($departmentId) {
    $where[] = "u.department_id = ?";
    $params[] = $departmentId;
}

if ($status === 'active') {
    $where[] = "u.is_active = 1";
} elseif ($status === 'inactive') {
    $where[] = "u.is_active = 0";
}

// Dept admins see only their department
if ($user['role'] === ROLE_DEPARTMENT_ADMIN) {
    $where[] = "u.department_id = ?";
    $params[] = $user['department_id'];
}

$whereClause = implode(' AND ', $where);

// Count total
$total = dbFetchValue("SELECT COUNT(*) FROM users u WHERE $whereClause", $params);
$pagination = paginate($total, $page, $perPage);

// Get volunteers (optimized with JOINs)
$volunteers = dbFetchAll(
    "SELECT u.*, d.name as department_name,
            COALESCE(pr_stats.shifts_count, 0) as shifts_count,
            COALESCE(pr_stats.total_hours, 0) as total_hours
     FROM users u
     LEFT JOIN departments d ON u.department_id = d.id
     LEFT JOIN (
         SELECT volunteer_id, 
                COUNT(*) as shifts_count,
                COALESCE(SUM(actual_hours), 0) as total_hours
         FROM participation_requests 
         WHERE status = 'APPROVED' OR attended = 1
         GROUP BY volunteer_id
     ) pr_stats ON u.id = pr_stats.volunteer_id
     WHERE $whereClause
     ORDER BY u.name ASC
     LIMIT {$pagination['offset']}, {$pagination['per_page']}",
    $params
);

// Get departments for filter
$departments = dbFetchAll("SELECT id, name FROM departments ORDER BY name");

// Handle actions
if (isPost()) {
    verifyCsrf();
    $action = post('action');
    $userId = post('user_id');
    
    switch ($action) {
        case 'toggle_active':
            $targetUser = dbFetchOne("SELECT * FROM users WHERE id = ?", [$userId]);
            if ($targetUser) {
                // Can't deactivate self or higher roles
                if ($targetUser['id'] == $user['id']) {
                    setFlash('error', 'Δεν μπορείτε να απενεργοποιήσετε τον εαυτό σας.');
                } elseif ($targetUser['role'] === ROLE_SYSTEM_ADMIN && $user['role'] !== ROLE_SYSTEM_ADMIN) {
                    setFlash('error', 'Δεν έχετε δικαίωμα σε αυτή την ενέργεια.');
                } else {
                    $newStatus = $targetUser['is_active'] ? 0 : 1;
                    dbExecute("UPDATE users SET is_active = ?, updated_at = NOW() WHERE id = ?", [$newStatus, $userId]);
                    logAudit($newStatus ? 'activate_user' : 'deactivate_user', 'users', $userId);
                    setFlash('success', $newStatus ? 'Ο χρήστης ενεργοποιήθηκε.' : 'Ο χρήστης απενεργοποιήθηκε.');
                }
            }
            break;
            
        case 'change_role':
            $newRole = post('new_role');
            $targetUser = dbFetchOne("SELECT * FROM users WHERE id = ?", [$userId]);
            
            if ($targetUser && in_array($newRole, [ROLE_VOLUNTEER, ROLE_SHIFT_LEADER, ROLE_DEPARTMENT_ADMIN, ROLE_SYSTEM_ADMIN])) {
                // Only system admin can create other system admins
                if ($newRole === ROLE_SYSTEM_ADMIN && $user['role'] !== ROLE_SYSTEM_ADMIN) {
                    setFlash('error', 'Μόνο διαχειριστές συστήματος μπορούν να δημιουργήσουν άλλους.');
                } elseif ($targetUser['id'] == $user['id']) {
                    setFlash('error', 'Δεν μπορείτε να αλλάξετε τον δικό σας ρόλο.');
                } else {
                    dbExecute("UPDATE users SET role = ?, updated_at = NOW() WHERE id = ?", [$newRole, $userId]);
                    logAudit('change_role', 'users', $userId, "New role: $newRole");
                    setFlash('success', 'Ο ρόλος άλλαξε.');
                }
            }
            break;
            
        case 'change_department':
            $newDeptId = post('new_department_id');
            dbExecute("UPDATE users SET department_id = ?, updated_at = NOW() WHERE id = ?", [$newDeptId ?: null, $userId]);
            logAudit('change_department', 'users', $userId);
            setFlash('success', 'Το τμήμα άλλαξε.');
            break;
    }
    
    redirect('volunteers.php?' . http_build_query($_GET));
}

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">
        <i class="bi bi-people me-2"></i>Εθελοντές
    </h1>
    <div class="d-flex gap-2">
        <a href="exports/import-volunteers.php" class="btn btn-outline-primary">
            <i class="bi bi-upload me-1"></i>Εισαγωγή CSV
        </a>
        <a href="exports/export-volunteers.php?role=<?= h($role) ?>&department_id=<?= h($departmentId) ?>" 
           class="btn btn-outline-success">
            <i class="bi bi-download me-1"></i>Εξαγωγή CSV
        </a>
        <a href="volunteer-form.php" class="btn btn-primary">
            <i class="bi bi-plus-lg me-1"></i>Νέος Εθελοντής
        </a>
    </div>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body">
        <form method="get" class="row g-3">
            <div class="col-md-4">
                <label class="form-label">Αναζήτηση</label>
                <input type="text" class="form-control" name="search" value="<?= h($search) ?>" placeholder="Όνομα, email, τηλέφωνο...">
            </div>
            <div class="col-md-2">
                <label class="form-label">Ρόλος</label>
                <select name="role" class="form-select">
                    <option value="">Όλοι</option>
                    <?php foreach (ROLE_LABELS as $r => $label): ?>
                        <option value="<?= $r ?>" <?= $role === $r ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if ($user['role'] === ROLE_SYSTEM_ADMIN): ?>
            <div class="col-md-2">
                <label class="form-label">Τμήμα</label>
                <select name="department_id" class="form-select">
                    <option value="">Όλα</option>
                    <?php foreach ($departments as $d): ?>
                        <option value="<?= $d['id'] ?>" <?= $departmentId == $d['id'] ? 'selected' : '' ?>>
                            <?= h($d['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="col-md-2">
                <label class="form-label">Κατάσταση</label>
                <select name="status" class="form-select">
                    <option value="">Όλοι</option>
                    <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Ενεργοί</option>
                    <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Ανενεργοί</option>
                </select>
            </div>
            <div class="col-md-2 d-flex align-items-end">
                <button type="submit" class="btn btn-outline-primary w-100">
                    <i class="bi bi-search me-1"></i>Αναζήτηση
                </button>
            </div>
        </form>
    </div>
</div>

<?= showFlash() ?>

<?php if (empty($volunteers)): ?>
    <div class="alert alert-info">
        <i class="bi bi-info-circle me-2"></i>Δεν βρέθηκαν εθελοντές.
    </div>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th>Εθελοντής</th>
                    <th>Ρόλος</th>
                    <th>Τμήμα</th>
                    <th class="text-center">Βάρδιες</th>
                    <th class="text-center">Πόντοι</th>
                    <th>Κατάσταση</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($volunteers as $v): ?>
                    <tr class="<?= !$v['is_active'] ? 'table-secondary' : '' ?>">
                        <td>
                            <a href="volunteer-view.php?id=<?= $v['id'] ?>" class="text-decoration-none">
                                <strong><?= h($v['name']) ?></strong>
                            </a>
                            <br><small class="text-muted"><?= h($v['email']) ?></small>
                            <?php if ($v['phone']): ?>
                                <br><small class="text-muted"><i class="bi bi-telephone"></i> <?= h($v['phone']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?= roleBadge($v['role']) ?></td>
                        <td><?= h($v['department_name'] ?? '-') ?></td>
                        <td class="text-center">
                            <?= $v['shifts_count'] ?>
                            <?php if ($v['total_hours'] > 0): ?>
                                <br><small class="text-muted"><?= number_format($v['total_hours'], 1) ?> ώρες</small>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <span class="badge bg-warning text-dark"><?= number_format($v['total_points']) ?></span>
                        </td>
                        <td>
                            <?php if ($v['is_active']): ?>
                                <span class="badge bg-success">Ενεργός</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Ανενεργός</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="dropdown">
                                <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                    <i class="bi bi-three-dots"></i>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li>
                                        <a class="dropdown-item" href="volunteer-view.php?id=<?= $v['id'] ?>">
                                            <i class="bi bi-eye me-1"></i>Προβολή
                                        </a>
                                    </li>
                                    <li>
                                        <a class="dropdown-item" href="volunteer-form.php?id=<?= $v['id'] ?>">
                                            <i class="bi bi-pencil me-1"></i>Επεξεργασία
                                        </a>
                                    </li>
                                    <li><hr class="dropdown-divider"></li>
                                    <?php if ($v['id'] != $user['id']): ?>
                                        <li>
                                            <a class="dropdown-item" href="#" data-bs-toggle="modal" data-bs-target="#roleModal<?= $v['id'] ?>">
                                                <i class="bi bi-person-gear me-1"></i>Αλλαγή Ρόλου
                                            </a>
                                        </li>
                                        <li>
                                            <form method="post" class="d-inline">
                                                <?= csrfField() ?>
                                                <input type="hidden" name="action" value="toggle_active">
                                                <input type="hidden" name="user_id" value="<?= $v['id'] ?>">
                                                <button type="submit" class="dropdown-item">
                                                    <?php if ($v['is_active']): ?>
                                                        <i class="bi bi-x-circle me-1"></i>Απενεργοποίηση
                                                    <?php else: ?>
                                                        <i class="bi bi-check-circle me-1"></i>Ενεργοποίηση
                                                    <?php endif; ?>
                                                </button>
                                            </form>
                                        </li>
                                    <?php endif; ?>
                                </ul>
                            </div>
                            
                            <!-- Role Change Modal -->
                            <div class="modal fade" id="roleModal<?= $v['id'] ?>">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <form method="post">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="change_role">
                                            <input type="hidden" name="user_id" value="<?= $v['id'] ?>">
                                            <div class="modal-header">
                                                <h5 class="modal-title">Αλλαγή Ρόλου</h5>
                                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body">
                                                <p>Αλλαγή ρόλου για <strong><?= h($v['name']) ?></strong></p>
                                                <select class="form-select" name="new_role" required>
                                                    <?php foreach (ROLE_LABELS as $r => $label): ?>
                                                        <?php if ($r !== ROLE_SYSTEM_ADMIN || $user['role'] === ROLE_SYSTEM_ADMIN): ?>
                                                            <option value="<?= $r ?>" <?= $v['role'] === $r ? 'selected' : '' ?>>
                                                                <?= $label ?>
                                                            </option>
                                                        <?php endif; ?>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Ακύρωση</button>
                                                <button type="submit" class="btn btn-primary">Αποθήκευση</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <?= paginationLinks($pagination) ?>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
