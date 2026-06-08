<?php
/**
 * VolunteerOps - Custom Roles Management
 * List, create, delete custom roles (System Admin only).
 */

require_once __DIR__ . '/bootstrap.php';
requireRole([ROLE_SYSTEM_ADMIN]);

$pageTitle = 'Διαχείριση Ρόλων';

// Handle delete
if (isPost()) {
    verifyCsrf();
    $action = post('action');
    $roleId = (int) post('role_id');

    if ($action === 'delete' && $roleId) {
        $role = dbFetchOne("SELECT * FROM custom_roles WHERE id = ?", [$roleId]);
        if (!$role) {
            setFlash('error', 'Ο ρόλος δεν βρέθηκε.');
        } else {
            // Unassign users first (FK is SET NULL on delete, but be explicit)
            dbExecute("UPDATE users SET custom_role_id = NULL WHERE custom_role_id = ?", [$roleId]);
            dbExecute("DELETE FROM custom_roles WHERE id = ?", [$roleId]);
            logAudit('delete_custom_role', 'custom_roles', $roleId);
            setFlash('success', 'Ο ρόλος «' . $role['name'] . '» διαγράφηκε.');
        }
        redirect('roles.php');
    }

    if ($action === 'set_default') {
        if ($roleId) {
            dbExecute("UPDATE custom_roles SET is_default = 0");
            dbExecute("UPDATE custom_roles SET is_default = 1 WHERE id = ?", [$roleId]);
        } else {
            dbExecute("UPDATE custom_roles SET is_default = 0");
        }
        setFlash('success', 'Ο προεπιλεγμένος ρόλος ενημερώθηκε.');
        redirect('roles.php');
    }
}

$roles = dbFetchAll(
    "SELECT cr.*,
            COUNT(DISTINCT crp.page_slug) AS perm_count,
            COUNT(DISTINCT u.id)          AS user_count
     FROM custom_roles cr
     LEFT JOIN custom_role_permissions crp ON crp.role_id = cr.id
     LEFT JOIN users u ON u.custom_role_id = cr.id AND u.is_active = 1 AND u.deleted_at IS NULL
     GROUP BY cr.id
     ORDER BY cr.name ASC"
);

$permissionMap = getPermissionMap();
$totalPerms    = count(getAllPermissionSlugs());

include __DIR__ . '/includes/header.php';
?>

<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h1 class="h3 mb-1"><i class="bi bi-shield-lock me-2"></i>Διαχείριση Ρόλων</h1>
            <p class="text-muted mb-0">Δημιουργήστε προσαρμοσμένους ρόλους με συγκεκριμένα δικαιώματα πρόσβασης.</p>
        </div>
        <a href="role-form.php" class="btn btn-primary">
            <i class="bi bi-plus-circle me-1"></i> Νέος Ρόλος
        </a>
    </div>

    <div class="card border-0 bg-light mb-4">
        <div class="card-body py-3">
            <h6 class="mb-2"><i class="bi bi-info-circle me-1 text-primary"></i>Ενσωματωμένοι Ρόλοι Συστήματος</h6>
            <div class="row g-2">
                <div class="col-auto">
                    <span class="badge bg-danger fs-6 px-3 py-2">
                        <i class="bi bi-shield-fill me-1"></i>Διαχειριστής Συστήματος
                    </span>
                </div>
                <div class="col-auto">
                    <span class="badge bg-secondary fs-6 px-3 py-2">
                        <i class="bi bi-person me-1"></i>Εθελοντής
                    </span>
                </div>
            </div>
            <small class="text-muted d-block mt-2">Οι ενσωματωμένοι ρόλοι δεν μπορούν να τροποποιηθούν. Οι προσαρμοσμένοι ρόλοι παρακάτω ισχύουν για χρήστες με βασικό ρόλο «Εθελοντής».</small>
        </div>
    </div>

    <?php if (empty($roles)): ?>
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center py-5">
                <i class="bi bi-shield-lock fs-1 text-muted d-block mb-3"></i>
                <h5 class="text-muted">Δεν υπάρχουν προσαρμοσμένοι ρόλοι</h5>
                <p class="text-muted">Δημιουργήστε έναν ρόλο για να ορίσετε συγκεκριμένα δικαιώματα πρόσβασης.</p>
                <a href="role-form.php" class="btn btn-primary mt-2">
                    <i class="bi bi-plus-circle me-1"></i> Νέος Ρόλος
                </a>
            </div>
        </div>
    <?php else: ?>
        <div class="row g-3">
            <?php foreach ($roles as $role): ?>
                <div class="col-md-6 col-xl-4">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <div class="d-flex align-items-start justify-content-between mb-3">
                                <div class="d-flex align-items-center gap-2">
                                    <span class="badge rounded-pill fs-6 px-3 py-2"
                                          style="background-color: <?= h($role['color']) ?>; color: #fff;">
                                        <?= h($role['name']) ?>
                                    </span>
                                    <?php if ($role['is_default']): ?>
                                        <span class="badge bg-warning text-dark">Προεπιλογή</span>
                                    <?php endif; ?>
                                </div>
                                <div class="dropdown">
                                    <button class="btn btn-sm btn-light" data-bs-toggle="dropdown">
                                        <i class="bi bi-three-dots-vertical"></i>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        <li>
                                            <a class="dropdown-item" href="role-form.php?id=<?= $role['id'] ?>">
                                                <i class="bi bi-pencil me-2"></i>Επεξεργασία
                                            </a>
                                        </li>
                                        <?php if (!$role['is_default']): ?>
                                        <li>
                                            <form method="post" class="d-inline">
                                                <?= csrfField() ?>
                                                <input type="hidden" name="action" value="set_default">
                                                <input type="hidden" name="role_id" value="<?= $role['id'] ?>">
                                                <button type="submit" class="dropdown-item">
                                                    <i class="bi bi-star me-2"></i>Ορισμός ως Προεπιλογή
                                                </button>
                                            </form>
                                        </li>
                                        <?php else: ?>
                                        <li>
                                            <form method="post" class="d-inline">
                                                <?= csrfField() ?>
                                                <input type="hidden" name="action" value="set_default">
                                                <input type="hidden" name="role_id" value="0">
                                                <button type="submit" class="dropdown-item text-warning">
                                                    <i class="bi bi-star-fill me-2"></i>Αφαίρεση Προεπιλογής
                                                </button>
                                            </form>
                                        </li>
                                        <?php endif; ?>
                                        <li><hr class="dropdown-divider"></li>
                                        <li>
                                            <button class="dropdown-item text-danger"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#deleteModal"
                                                    data-role-id="<?= $role['id'] ?>"
                                                    data-role-name="<?= h($role['name']) ?>"
                                                    data-user-count="<?= (int)$role['user_count'] ?>">
                                                <i class="bi bi-trash me-2"></i>Διαγραφή
                                            </button>
                                        </li>
                                    </ul>
                                </div>
                            </div>

                            <?php if ($role['description']): ?>
                                <p class="text-muted small mb-3"><?= h($role['description']) ?></p>
                            <?php endif; ?>

                            <div class="row g-3 mb-3">
                                <div class="col-6">
                                    <div class="text-center bg-light rounded p-2">
                                        <div class="fw-bold fs-5"><?= (int)$role['user_count'] ?></div>
                                        <div class="text-muted small">Χρήστες</div>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="text-center bg-light rounded p-2">
                                        <div class="fw-bold fs-5"><?= (int)$role['perm_count'] ?>/<?= $totalPerms ?></div>
                                        <div class="text-muted small">Δικαιώματα</div>
                                    </div>
                                </div>
                            </div>

                            <!-- Permission summary by section -->
                            <?php
                            $grantedSlugs = dbFetchAll(
                                "SELECT page_slug FROM custom_role_permissions WHERE role_id = ?",
                                [$role['id']]
                            );
                            $granted = array_column($grantedSlugs, 'page_slug');
                            ?>
                            <div class="d-flex flex-wrap gap-1">
                                <?php foreach ($permissionMap as $section => $perms): ?>
                                    <?php
                                    $sectionSlugs   = array_column($perms, 'slug');
                                    $sectionGranted = count(array_intersect($sectionSlugs, $granted));
                                    $sectionTotal   = count($sectionSlugs);
                                    $badgeClass     = $sectionGranted === 0 ? 'bg-light text-muted border'
                                                    : ($sectionGranted === $sectionTotal ? 'bg-success text-white' : 'bg-warning text-dark');
                                    ?>
                                    <span class="badge <?= $badgeClass ?>" title="<?= h($section) ?>: <?= $sectionGranted ?>/<?= $sectionTotal ?>">
                                        <?= h($section) ?> <?= $sectionGranted ?>/<?= $sectionTotal ?>
                                    </span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div class="card-footer bg-transparent border-0 pt-0">
                            <a href="role-form.php?id=<?= $role['id'] ?>" class="btn btn-sm btn-outline-primary w-100">
                                <i class="bi bi-pencil me-1"></i> Επεξεργασία Δικαιωμάτων
                            </a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Delete Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="post" class="modal-content">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="role_id" id="deleteRoleId">
            <div class="modal-header border-0">
                <h5 class="modal-title text-danger"><i class="bi bi-trash me-2"></i>Διαγραφή Ρόλου</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Είστε σίγουροι ότι θέλετε να διαγράψετε τον ρόλο <strong id="deleteRoleName"></strong>;</p>
                <div id="deleteUserWarning" class="alert alert-warning d-none">
                    <i class="bi bi-exclamation-triangle me-2"></i>
                    <strong id="deleteUserCount"></strong> χρήστες έχουν αυτόν τον ρόλο. Θα γίνουν απλοί εθελοντές χωρίς ειδικά δικαιώματα.
                </div>
                <p class="text-muted small">Αυτή η ενέργεια δεν μπορεί να αναιρεθεί.</p>
            </div>
            <div class="modal-footer border-0">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Ακύρωση</button>
                <button type="submit" class="btn btn-danger">Διαγραφή</button>
            </div>
        </form>
    </div>
</div>

<script>
document.getElementById('deleteModal').addEventListener('show.bs.modal', function(e) {
    const btn  = e.relatedTarget;
    const id   = btn.dataset.roleId;
    const name = btn.dataset.roleName;
    const cnt  = parseInt(btn.dataset.userCount, 10);

    document.getElementById('deleteRoleId').value   = id;
    document.getElementById('deleteRoleName').textContent = '«' + name + '»';

    const warn = document.getElementById('deleteUserWarning');
    if (cnt > 0) {
        document.getElementById('deleteUserCount').textContent = cnt;
        warn.classList.remove('d-none');
    } else {
        warn.classList.add('d-none');
    }
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
