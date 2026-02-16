<?php
/**
 * VolunteerOps - Mission Types Management
 * Διαχείριση τύπων αποστολών
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();
requireRole([ROLE_SYSTEM_ADMIN]);

$pageTitle = 'Τύποι Αποστολών';

// Handle actions
if (isPost()) {
    verifyCsrf();
    $action = post('action');
    
    switch ($action) {
        case 'create':
        case 'update':
            $id = (int)post('id');
            $name = trim(post('name'));
            $description = trim(post('description'));
            $color = post('color', 'primary');
            $icon = trim(post('icon', 'bi-flag'));
            $sortOrder = (int)post('sort_order', 0);
            $isActive = isset($_POST['is_active']) ? 1 : 0;
            
            if (empty($name)) {
                setFlash('error', 'Το όνομα είναι υποχρεωτικό.');
            } else {
                if ($id) {
                    dbExecute(
                        "UPDATE mission_types SET name = ?, description = ?, color = ?, icon = ?, sort_order = ?, is_active = ?, updated_at = NOW() WHERE id = ?",
                        [$name, $description, $color, $icon, $sortOrder, $isActive, $id]
                    );
                    logAudit('update', 'mission_types', $id);
                    setFlash('success', 'Ο τύπος αποστολής ενημερώθηκε.');
                } else {
                    $newId = dbInsert(
                        "INSERT INTO mission_types (name, description, color, icon, sort_order, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())",
                        [$name, $description, $color, $icon, $sortOrder, $isActive]
                    );
                    logAudit('create', 'mission_types', $newId);
                    setFlash('success', 'Ο τύπος αποστολής δημιουργήθηκε.');
                }
            }
            break;
            
        case 'delete':
            $id = (int)post('id');
            // Check if used by any mission
            $usageCount = dbFetchValue("SELECT COUNT(*) FROM missions WHERE mission_type_id = ?", [$id]);
            if ($usageCount > 0) {
                setFlash('error', "Ο τύπος χρησιμοποιείται σε $usageCount αποστολές. Απενεργοποιήστε τον αντί να τον διαγράψετε.");
            } else {
                dbExecute("DELETE FROM mission_types WHERE id = ?", [$id]);
                logAudit('delete', 'mission_types', $id);
                setFlash('success', 'Ο τύπος αποστολής διαγράφηκε.');
            }
            break;
    }
    
    redirect('mission-types.php');
}

// Fetch all mission types with usage count
$missionTypes = dbFetchAll(
    "SELECT mt.*, 
            (SELECT COUNT(*) FROM missions m WHERE m.mission_type_id = mt.id AND m.deleted_at IS NULL) as missions_count
     FROM mission_types mt
     ORDER BY mt.sort_order, mt.name"
);

// Available Bootstrap colors for dropdown
$colorOptions = [
    'primary' => 'Μπλε',
    'success' => 'Πράσινο',
    'danger' => 'Κόκκινο',
    'warning' => 'Κίτρινο',
    'info' => 'Γαλάζιο',
    'secondary' => 'Γκρι',
    'dark' => 'Σκούρο',
];

// Available icons
$iconOptions = [
    'bi-people' => 'Άνθρωποι',
    'bi-heart-pulse' => 'Υγεία',
    'bi-mortarboard' => 'Εκπαίδευση',
    'bi-life-preserver' => 'Διάσωση',
    'bi-flag' => 'Σημαία',
    'bi-house' => 'Σπίτι',
    'bi-tree' => 'Δέντρο',
    'bi-tsunami' => 'Φυσική καταστροφή',
    'bi-fire' => 'Φωτιά',
    'bi-shield-check' => 'Ασφάλεια',
    'bi-truck' => 'Μεταφορά',
    'bi-basket' => 'Τρόφιμα',
    'bi-clipboard2-pulse' => 'Ιατρική',
    'bi-tools' => 'Εργαλεία',
    'bi-megaphone' => 'Ενημέρωση',
    'bi-globe' => 'Περιβάλλον',
];

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">
        <i class="bi bi-tags me-2"></i>Τύποι Αποστολών
    </h1>
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#typeModal" 
            onclick="resetForm()">
        <i class="bi bi-plus-lg me-1"></i>Νέος Τύπος
    </button>
</div>

<?= showFlash() ?>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th width="50">Σειρά</th>
                    <th>Εικονίδιο</th>
                    <th>Όνομα</th>
                    <th>Περιγραφή</th>
                    <th>Χρώμα</th>
                    <th class="text-center">Αποστολές</th>
                    <th>Κατάσταση</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($missionTypes)): ?>
                    <tr>
                        <td colspan="8" class="text-center py-4 text-muted">
                            Δεν υπάρχουν τύποι αποστολών
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($missionTypes as $mt): ?>
                        <tr class="<?= !$mt['is_active'] ? 'table-secondary' : '' ?>">
                            <td class="text-center"><?= $mt['sort_order'] ?></td>
                            <td>
                                <i class="bi <?= h($mt['icon'] ?: 'bi-flag') ?> fs-5 text-<?= h($mt['color']) ?>"></i>
                            </td>
                            <td><strong><?= h($mt['name']) ?></strong></td>
                            <td><small class="text-muted"><?= h($mt['description'] ?: '-') ?></small></td>
                            <td>
                                <span class="badge bg-<?= h($mt['color']) ?>"><?= h($mt['color']) ?></span>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-secondary"><?= $mt['missions_count'] ?></span>
                            </td>
                            <td>
                                <?php if ($mt['is_active']): ?>
                                    <span class="badge bg-success">Ενεργό</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Ανενεργό</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <button type="button" class="btn btn-sm btn-outline-primary" 
                                        onclick="editType(<?= htmlspecialchars(json_encode($mt), ENT_QUOTES) ?>)">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <?php if ($mt['missions_count'] == 0): ?>
                                    <form method="post" class="d-inline" onsubmit="return confirm('Διαγραφή τύπου αποστολής;')">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= $mt['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Create/Edit Modal -->
<div class="modal fade" id="typeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" id="typeForm">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="create" id="formAction">
                <input type="hidden" name="id" value="" id="formId">
                
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Νέος Τύπος Αποστολής</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Όνομα *</label>
                        <input type="text" class="form-control" name="name" id="formName" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Περιγραφή</label>
                        <input type="text" class="form-control" name="description" id="formDescription">
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Χρώμα</label>
                            <select class="form-select" name="color" id="formColor">
                                <?php foreach ($colorOptions as $val => $label): ?>
                                    <option value="<?= $val ?>"><?= h($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Εικονίδιο</label>
                            <select class="form-select" name="icon" id="formIcon">
                                <?php foreach ($iconOptions as $val => $label): ?>
                                    <option value="<?= $val ?>"><?= h($label) ?> (<?= $val ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Σειρά εμφάνισης</label>
                            <input type="number" class="form-control" name="sort_order" id="formSortOrder" value="0" min="0">
                        </div>
                        <div class="col-md-6 mb-3 d-flex align-items-end">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_active" id="formIsActive" checked>
                                <label class="form-check-label" for="formIsActive">Ενεργός</label>
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Προεπισκόπηση</label>
                        <div id="preview" class="p-3 bg-light rounded text-center">
                            <i class="bi bi-flag fs-3 text-primary" id="previewIcon"></i>
                            <br>
                            <span class="badge bg-primary mt-2" id="previewBadge">Νέος Τύπος</span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Ακύρωση</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-1"></i>Αποθήκευση
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function resetForm() {
    document.getElementById('formAction').value = 'create';
    document.getElementById('formId').value = '';
    document.getElementById('formName').value = '';
    document.getElementById('formDescription').value = '';
    document.getElementById('formColor').value = 'primary';
    document.getElementById('formIcon').value = 'bi-flag';
    document.getElementById('formSortOrder').value = '0';
    document.getElementById('formIsActive').checked = true;
    document.getElementById('modalTitle').textContent = 'Νέος Τύπος Αποστολής';
    updatePreview();
}

function editType(mt) {
    document.getElementById('formAction').value = 'update';
    document.getElementById('formId').value = mt.id;
    document.getElementById('formName').value = mt.name;
    document.getElementById('formDescription').value = mt.description || '';
    document.getElementById('formColor').value = mt.color;
    document.getElementById('formIcon').value = mt.icon || 'bi-flag';
    document.getElementById('formSortOrder').value = mt.sort_order;
    document.getElementById('formIsActive').checked = mt.is_active == 1;
    document.getElementById('modalTitle').textContent = 'Επεξεργασία: ' + mt.name;
    updatePreview();
    new bootstrap.Modal(document.getElementById('typeModal')).show();
}

function updatePreview() {
    const color = document.getElementById('formColor').value;
    const icon = document.getElementById('formIcon').value;
    const name = document.getElementById('formName').value || 'Νέος Τύπος';
    
    const previewIcon = document.getElementById('previewIcon');
    previewIcon.className = 'bi ' + icon + ' fs-3 text-' + color;
    
    const previewBadge = document.getElementById('previewBadge');
    previewBadge.className = 'badge bg-' + color + ' mt-2';
    previewBadge.textContent = name;
}

// Live preview
document.getElementById('formColor')?.addEventListener('change', updatePreview);
document.getElementById('formIcon')?.addEventListener('change', updatePreview);
document.getElementById('formName')?.addEventListener('input', updatePreview);
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
