<?php
/**
 * VolunteerOps - Inactive Volunteers
 * Shows volunteers with is_active = 0 and not soft-deleted
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();
requireRole([ROLE_SYSTEM_ADMIN, ROLE_DEPARTMENT_ADMIN]);

$pageTitle = 'Ανενεργοί Εθελοντές';
$user = getCurrentUser();

// Filters
$search      = get('search', '');
$role        = get('role', '');
$departmentId = get('department_id', '');
$page        = max(1, (int) get('page', 1));
$perPage     = 20;

// Build query — show only is_active = 0 AND not soft-deleted
$where  = ['u.is_active = 0', 'u.deleted_at IS NULL'];
$params = [];

if ($search) {
    $where[]  = "(u.name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($role) {
    $where[]  = "u.role = ?";
    $params[] = $role;
}
if ($departmentId) {
    $where[]  = "u.department_id = ?";
    $params[] = $departmentId;
}

// Dept admins see only their department
if ($user['role'] === ROLE_DEPARTMENT_ADMIN) {
    $where[]  = "u.department_id = ?";
    $params[] = $user['department_id'];
}

$whereClause = implode(' AND ', $where);

$total      = dbFetchValue("SELECT COUNT(*) FROM users u WHERE $whereClause", $params);
$pagination = paginate($total, $page, $perPage);

$volunteers = dbFetchAll(
    "SELECT u.*, d.name AS department_name, wh.name AS warehouse_name,
            COALESCE(pr_stats.shifts_count, 0) AS shifts_count,
            COALESCE(pr_stats.total_hours, 0)  AS total_hours
     FROM users u
     LEFT JOIN departments d  ON u.department_id = d.id
     LEFT JOIN departments wh ON u.warehouse_id  = wh.id
     LEFT JOIN (
         SELECT volunteer_id,
                COUNT(*) AS shifts_count,
                COALESCE(SUM(actual_hours), 0) AS total_hours
         FROM participation_requests
         WHERE status = '" . PARTICIPATION_APPROVED . "' OR attended = 1
         GROUP BY volunteer_id
     ) pr_stats ON u.id = pr_stats.volunteer_id
     WHERE $whereClause
     ORDER BY u.name ASC
     LIMIT {$pagination['offset']}, {$pagination['per_page']}",
    $params
);

$departments = dbFetchAll("SELECT id, name FROM departments ORDER BY name");

// Handle POST actions
if (isPost()) {
    verifyCsrf();
    $action = post('action');
    $userId = post('user_id');

    switch ($action) {
        case 'reactivate':
            $targetUser = dbFetchOne("SELECT * FROM users WHERE id = ? AND is_active = 0 AND deleted_at IS NULL", [$userId]);
            if ($targetUser) {
                dbExecute("UPDATE users SET is_active = 1, updated_at = NOW() WHERE id = ?", [$userId]);
                logAudit('reactivate_user', 'users', $userId);
                setFlash('success', 'Ο χρήστης ' . h($targetUser['name']) . ' επανενεργοποιήθηκε.');
            } else {
                setFlash('error', 'Ο χρήστης δεν βρέθηκε.');
            }
            break;

        case 'delete_user':
            if ($user['role'] !== ROLE_SYSTEM_ADMIN) {
                setFlash('error', 'Δεν έχετε δικαίωμα σε αυτή την ενέργεια.');
                break;
            }
            $targetUser = dbFetchOne("SELECT * FROM users WHERE id = ? AND deleted_at IS NULL", [$userId]);
            if ($targetUser) {
                if ($targetUser['id'] == $user['id']) {
                    setFlash('error', 'Δεν μπορείτε να διαγράψετε τον εαυτό σας.');
                } elseif ($targetUser['role'] === ROLE_SYSTEM_ADMIN) {
                    setFlash('error', 'Δεν μπορείτε να διαγράψετε διαχειριστή συστήματος.');
                } else {
                    dbExecute(
                        "UPDATE users SET deleted_at = NOW(), deleted_by = ?, is_active = 0, updated_at = NOW() WHERE id = ?",
                        [$user['id'], $userId]
                    );
                    logAudit('soft_delete_user', 'users', $userId);
                    setFlash('success', 'Ο χρήστης διαγράφηκε. Τα δεδομένα του διατηρούνται.');
                }
            } else {
                setFlash('error', 'Ο χρήστης δεν βρέθηκε.');
            }
            break;
    }

    redirect('inactive-volunteers.php?' . http_build_query(array_filter([
        'search'        => $search,
        'role'          => $role,
        'department_id' => $departmentId,
        'page'          => $page > 1 ? $page : null,
    ])));
}

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">
        <i class="bi bi-person-x me-2 text-secondary"></i>Ανενεργοί Εθελοντές
    </h1>
    <a href="volunteers.php" class="btn btn-outline-primary">
        <i class="bi bi-people me-1"></i>Ενεργοί Εθελοντές
    </a>
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
            <div class="col-md-3">
                <label class="form-label">Σώμα</label>
                <select name="department_id" class="form-select">
                    <option value="">Όλα</option>
                    <?php foreach ($departments as $d): ?>
                        <option value="<?= $d['id'] ?>" <?= $departmentId == $d['id'] ? 'selected' : '' ?>><?= h($d['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
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
        <i class="bi bi-info-circle me-2"></i>Δεν υπάρχουν ανενεργοί εθελοντές.
    </div>
<?php else: ?>
    <p class="text-muted">Σύνολο: <strong><?= $total ?></strong> ανενεργοί εθελοντές</p>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th>Εθελοντής</th>
                    <th>Ρόλος</th>
                    <th>Σώμα</th>
                    <th class="text-center">Βάρδιες</th>
                    <th class="text-center">Πόντοι</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($volunteers as $v): ?>
                    <tr class="table-secondary">
                        <td>
                            <a href="volunteer-view.php?id=<?= $v['id'] ?>" class="text-decoration-none text-dark">
                                <strong><?= h($v['name']) ?></strong>
                                <?= volunteerTypeBadge($v['volunteer_type'] ?? VTYPE_VOLUNTEER) ?>
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
                                    <li>
                                        <form method="post" class="d-inline">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="reactivate">
                                            <input type="hidden" name="user_id" value="<?= $v['id'] ?>">
                                            <button type="submit" class="dropdown-item text-success">
                                                <i class="bi bi-check-circle me-1"></i>Επανενεργοποίηση
                                            </button>
                                        </form>
                                    </li>
                                    <?php if (isSystemAdmin() && $v['role'] !== ROLE_SYSTEM_ADMIN): ?>
                                    <li><hr class="dropdown-divider"></li>
                                    <li>
                                        <a class="dropdown-item text-danger" href="#" data-bs-toggle="modal" data-bs-target="#deleteModal<?= $v['id'] ?>">
                                            <i class="bi bi-trash me-1"></i>Διαγραφή
                                        </a>
                                    </li>
                                    <?php endif; ?>
                                </ul>
                            </div>

                            <?php if (isSystemAdmin() && $v['role'] !== ROLE_SYSTEM_ADMIN): ?>
                            <!-- Soft Delete Confirmation Modal -->
                            <div class="modal fade" id="deleteModal<?= $v['id'] ?>">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <form method="post">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="delete_user">
                                            <input type="hidden" name="user_id" value="<?= $v['id'] ?>">
                                            <div class="modal-header bg-danger text-white">
                                                <h5 class="modal-title"><i class="bi bi-exclamation-triangle me-2"></i>Διαγραφή Εθελοντή</h5>
                                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                            </div>
                                            <div class="modal-body">
                                                <div class="alert alert-warning mb-3">
                                                    <i class="bi bi-shield-check me-1"></i>
                                                    <strong>Τα δεδομένα διατηρούνται!</strong><br>
                                                    Ο χρήστης κρύβεται από όλες τις σελίδες αλλά το ιστορικό του μένει ανέπαφο.
                                                </div>
                                                <p>Πρόκειται να διαγράψετε τον/την <strong><?= h($v['name']) ?></strong>:</p>
                                                <ul>
                                                    <li>Συμμετοχές σε βάρδιες: <strong><?= $v['shifts_count'] ?></strong></li>
                                                    <li>Συνολικοί πόντοι: <strong><?= number_format($v['total_points']) ?></strong></li>
                                                </ul>
                                                <p class="text-muted mb-0"><small><i class="bi bi-info-circle me-1"></i>Αυτή η ενέργεια μπορεί να αναιρεθεί μόνο απευθείας από τη βάση δεδομένων.</small></p>
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
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?= paginationLinks($pagination) ?>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
