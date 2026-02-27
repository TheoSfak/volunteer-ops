<?php
/**
 * VolunteerOps - Citizen Certificate Types (Τύποι Πιστοποιητικών)
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();
requireRole([ROLE_SYSTEM_ADMIN, ROLE_DEPARTMENT_ADMIN]);

$pageTitle = 'Τύποι Πιστοποιητικών';

// Handle POST actions
if (isPost()) {
    verifyCsrf();
    $action = post('action');

    switch ($action) {
        case 'create':
        case 'update':
            $id = (int) post('type_id');
            $name = trim(post('name'));
            $description = trim(post('description')) ?: null;
            $isActive = post('is_active') ? 1 : 0;

            if (empty($name)) {
                setFlash('error', 'Το πεδίο Όνομα είναι υποχρεωτικό.');
                redirect('citizen-certificate-types.php');
            }

            if ($action === 'update' && $id > 0) {
                dbExecute(
                    "UPDATE citizen_certificate_types SET name=?, description=?, is_active=?, updated_at=NOW() WHERE id=?",
                    [$name, $description, $isActive, $id]
                );
                logAudit('update', 'citizen_certificate_types', $id);
                setFlash('success', 'Ο τύπος πιστοποιητικού ενημερώθηκε επιτυχώς.');
            } else {
                $newId = dbInsert(
                    "INSERT INTO citizen_certificate_types (name, description, is_active) VALUES (?, ?, ?)",
                    [$name, $description, $isActive]
                );
                logAudit('create', 'citizen_certificate_types', $newId);
                setFlash('success', 'Ο τύπος πιστοποιητικού δημιουργήθηκε επιτυχώς.');
            }
            redirect('citizen-certificate-types.php');
            break;

        case 'delete':
            $id = (int) post('type_id');
            if ($id > 0) {
                // Check if any certificates use this type
                $used = dbFetchValue("SELECT COUNT(*) FROM citizen_certificates WHERE certificate_type_id = ?", [$id]);
                if ($used > 0) {
                    setFlash('error', "Δεν μπορεί να διαγραφεί — χρησιμοποιείται σε {$used} πιστοποιητικά.");
                } else {
                    dbExecute("DELETE FROM citizen_certificate_types WHERE id = ?", [$id]);
                    logAudit('delete', 'citizen_certificate_types', $id);
                    setFlash('success', 'Ο τύπος πιστοποιητικού διαγράφηκε.');
                }
            }
            redirect('citizen-certificate-types.php');
            break;
    }
}

$types = dbFetchAll("SELECT cct.*, (SELECT COUNT(*) FROM citizen_certificates cc WHERE cc.certificate_type_id = cct.id) as usage_count FROM citizen_certificate_types cct ORDER BY cct.name");

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-tags"></i> Τύποι Πιστοποιητικών</h2>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#typeModal" onclick="resetForm()">
        <i class="bi bi-plus-lg"></i> Νέος Τύπος
    </button>
</div>

<div class="card">
    <div class="card-header">
        <i class="bi bi-list"></i> Σύνολο: <strong><?= count($types) ?></strong> τύποι
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-striped mb-0">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Όνομα</th>
                        <th>Περιγραφή</th>
                        <th class="text-center">Κατάσταση</th>
                        <th class="text-center">Χρήσεις</th>
                        <th class="text-center">Ενέργειες</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($types)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">Δεν υπάρχουν τύποι πιστοποιητικών.</td></tr>
                    <?php else: ?>
                    <?php foreach ($types as $i => $t): ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td><strong><?= h($t['name']) ?></strong></td>
                        <td><?= h($t['description'] ?? '-') ?></td>
                        <td class="text-center">
                            <?php if ($t['is_active']): ?>
                                <span class="badge bg-success">Ενεργός</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Ανενεργός</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center"><span class="badge bg-info"><?= $t['usage_count'] ?></span></td>
                        <td class="text-center">
                            <button class="btn btn-sm btn-outline-primary" onclick="editType(<?= h(json_encode($t)) ?>)" title="Επεξεργασία">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <?php if ($t['usage_count'] == 0): ?>
                            <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#deleteModal<?= $t['id'] ?>" title="Διαγραφή">
                                <i class="bi bi-trash"></i>
                            </button>
                            <div class="modal fade" id="deleteModal<?= $t['id'] ?>" tabindex="-1">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <div class="modal-header bg-danger text-white">
                                            <h5 class="modal-title">Διαγραφή Τύπου</h5>
                                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            Είστε σίγουροι ότι θέλετε να διαγράψετε τον τύπο
                                            <strong><?= h($t['name']) ?></strong>;
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Ακύρωση</button>
                                            <form method="post" class="d-inline">
                                                <?= csrfField() ?>
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="type_id" value="<?= $t['id'] ?>">
                                                <button type="submit" class="btn btn-danger">Διαγραφή</button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Create / Edit Modal -->
<div class="modal fade" id="typeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" id="typeForm">
                <?= csrfField() ?>
                <input type="hidden" name="action" id="formAction" value="create">
                <input type="hidden" name="type_id" id="formTypeId" value="0">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="modalTitle">Νέος Τύπος Πιστοποιητικού</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Όνομα <span class="text-danger">*</span></label>
                        <input type="text" name="name" id="type_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Περιγραφή</label>
                        <textarea name="description" id="type_description" class="form-control" rows="3"></textarea>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="is_active" id="type_is_active" value="1" checked>
                        <label class="form-check-label" for="type_is_active">Ενεργός</label>
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
function resetForm() {
    document.getElementById('formAction').value = 'create';
    document.getElementById('formTypeId').value = '0';
    document.getElementById('modalTitle').textContent = 'Νέος Τύπος Πιστοποιητικού';
    document.getElementById('typeForm').reset();
    document.getElementById('type_is_active').checked = true;
}

function editType(t) {
    document.getElementById('formAction').value = 'update';
    document.getElementById('formTypeId').value = t.id;
    document.getElementById('modalTitle').textContent = 'Επεξεργασία Τύπου';
    document.getElementById('type_name').value = t.name || '';
    document.getElementById('type_description').value = t.description || '';
    document.getElementById('type_is_active').checked = t.is_active == 1;

    var modal = new bootstrap.Modal(document.getElementById('typeModal'));
    modal.show();
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
