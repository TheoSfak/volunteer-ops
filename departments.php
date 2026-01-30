<?php
/**
 * VolunteerOps - Departments Management
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();
requireRole([ROLE_SYSTEM_ADMIN]);

$pageTitle = 'Τμήματα';

// Get departments with stats
$departments = dbFetchAll(
    "SELECT d.*,
            (SELECT COUNT(*) FROM users u WHERE u.department_id = d.id) as users_count,
            (SELECT COUNT(*) FROM missions m WHERE m.department_id = d.id AND m.deleted_at IS NULL) as missions_count
     FROM departments d
     ORDER BY d.name"
);

// Handle actions
if (isPost()) {
    verifyCsrf();
    $action = post('action');
    
    switch ($action) {
        case 'create':
        case 'update':
            $id = post('id');
            $name = post('name');
            $description = post('description');
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            
            if (empty($name)) {
                setFlash('error', 'Το όνομα είναι υποχρεωτικό.');
            } else {
                if ($id) {
                    dbExecute(
                        "UPDATE departments SET name = ?, description = ?, is_active = ?, updated_at = NOW() WHERE id = ?",
                        [$name, $description, $isActive, $id]
                    );
                    logAudit('update', 'departments', $id);
                    setFlash('success', 'Το τμήμα ενημερώθηκε.');
                } else {
                    $newId = dbInsert(
                        "INSERT INTO departments (name, description, is_active, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())",
                        [$name, $description, $isActive]
                    );
                    logAudit('create', 'departments', $newId);
                    setFlash('success', 'Το τμήμα δημιουργήθηκε.');
                }
            }
            break;
            
        case 'delete':
            $id = post('id');
            $dept = dbFetchOne("SELECT * FROM departments WHERE id = ?", [$id]);
            
            if ($dept) {
                // Check if has users or missions
                $usersCount = dbFetchValue("SELECT COUNT(*) FROM users WHERE department_id = ?", [$id]);
                $missionsCount = dbFetchValue("SELECT COUNT(*) FROM missions WHERE department_id = ? AND deleted_at IS NULL", [$id]);
                
                if ($usersCount > 0 || $missionsCount > 0) {
                    setFlash('error', 'Δεν μπορείτε να διαγράψετε τμήμα με χρήστες ή αποστολές.');
                } else {
                    dbExecute("DELETE FROM departments WHERE id = ?", [$id]);
                    logAudit('delete', 'departments', $id);
                    setFlash('success', 'Το τμήμα διαγράφηκε.');
                }
            }
            break;
    }
    
    redirect('departments.php');
}

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">
        <i class="bi bi-building me-2"></i>Τμήματα
    </h1>
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#deptModal">
        <i class="bi bi-plus-lg me-1"></i>Νέο Τμήμα
    </button>
</div>

<?= showFlash() ?>

<?php if (empty($departments)): ?>
    <div class="alert alert-info">
        <i class="bi bi-info-circle me-2"></i>Δεν υπάρχουν τμήματα.
    </div>
<?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover align-middle">
            <thead>
                <tr>
                    <th>Κωδικός</th>
                    <th>Όνομα</th>
                    <th>Περιγραφή</th>
                    <th class="text-center">Χρήστες</th>
                    <th class="text-center">Αποστολές</th>
                    <th>Κατάσταση</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($departments as $d): ?>
                    <tr class="<?= !$d['is_active'] ? 'table-secondary' : '' ?>">
                        <td><code><?= h($d['code'] ?: '-') ?></code></td>
                        <td><strong><?= h($d['name']) ?></strong></td>
                        <td><?= h($d['description'] ?: '-') ?></td>
                        <td class="text-center">
                            <span class="badge bg-secondary"><?= $d['users_count'] ?></span>
                        </td>
                        <td class="text-center">
                            <span class="badge bg-primary"><?= $d['missions_count'] ?></span>
                        </td>
                        <td>
                            <?php if ($d['is_active']): ?>
                                <span class="badge bg-success">Ενεργό</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Ανενεργό</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <button type="button" class="btn btn-sm btn-outline-primary" 
                                    onclick="editDept(<?= htmlspecialchars(json_encode($d)) ?>)">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <?php if ($d['users_count'] == 0 && $d['missions_count'] == 0): ?>
                                <form method="post" class="d-inline" onsubmit="return confirm('Διαγραφή τμήματος;')">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $d['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<!-- Department Modal -->
<div class="modal fade" id="deptModal">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" id="deptForm">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="create" id="deptAction">
                <input type="hidden" name="id" id="deptId">
                
                <div class="modal-header">
                    <h5 class="modal-title" id="deptModalTitle">Νέο Τμήμα</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Όνομα *</label>
                        <input type="text" class="form-control" name="name" id="deptName" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Περιγραφή</label>
                        <textarea class="form-control" name="description" id="deptDesc" rows="2"></textarea>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="is_active" id="deptActive" checked>
                        <label class="form-check-label" for="deptActive">Ενεργό</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Ακύρωση</button>
                    <button type="submit" class="btn btn-primary">Αποθήκευση</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editDept(dept) {
    document.getElementById('deptAction').value = 'update';
    document.getElementById('deptId').value = dept.id;
    document.getElementById('deptName').value = dept.name;
    document.getElementById('deptDesc').value = dept.description || '';
    document.getElementById('deptActive').checked = dept.is_active == 1;
    document.getElementById('deptModalTitle').textContent = 'Επεξεργασία Τμήματος';
    
    new bootstrap.Modal(document.getElementById('deptModal')).show();
}

// Reset modal on close
document.getElementById('deptModal').addEventListener('hidden.bs.modal', function() {
    document.getElementById('deptAction').value = 'create';
    document.getElementById('deptId').value = '';
    document.getElementById('deptForm').reset();
    document.getElementById('deptModalTitle').textContent = 'Νέο Τμήμα';
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
