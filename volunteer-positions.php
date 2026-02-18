<?php
/**
 * VolunteerOps - Θέσεις/Ρόλοι Εθελοντών
 * Admin CRUD for volunteer organizational positions.
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();
requireRole([ROLE_SYSTEM_ADMIN, ROLE_DEPARTMENT_ADMIN]);

$pageTitle = 'Θέσεις Εθελοντών';

// =============================================
// Handle POST
// =============================================
if (isPost()) {
    verifyCsrf();
    $action = post('action');

    if ($action === 'add' || $action === 'edit') {
        $name = trim(post('name'));
        $color = post('color', 'secondary');
        $icon = trim(post('icon'));
        $description = trim(post('description'));
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $sortOrder = (int) post('sort_order', 0);

        if (empty($name)) {
            setFlash('error', 'Το όνομα είναι υποχρεωτικό.');
            redirect('volunteer-positions.php');
        }

        $validColors = ['primary', 'secondary', 'success', 'danger', 'warning', 'info', 'dark'];
        if (!in_array($color, $validColors)) $color = 'secondary';

        if ($action === 'add') {
            $maxSort = (int) dbFetchValue("SELECT COALESCE(MAX(sort_order), 0) FROM volunteer_positions");
            dbInsert(
                "INSERT INTO volunteer_positions (name, color, icon, description, is_active, sort_order) VALUES (?, ?, ?, ?, ?, ?)",
                [$name, $color, $icon ?: null, $description ?: null, $isActive, $sortOrder ?: $maxSort + 1]
            );
            logAudit('create', 'volunteer_positions', 0);
            setFlash('success', 'Η θέση "' . $name . '" δημιουργήθηκε.');
        } else {
            $id = (int) post('id');
            dbExecute(
                "UPDATE volunteer_positions SET name = ?, color = ?, icon = ?, description = ?, is_active = ?, sort_order = ? WHERE id = ?",
                [$name, $color, $icon ?: null, $description ?: null, $isActive, $sortOrder, $id]
            );
            logAudit('update', 'volunteer_positions', $id);
            setFlash('success', 'Η θέση ενημερώθηκε.');
        }
        redirect('volunteer-positions.php');
    }

    if ($action === 'delete') {
        $id = (int) post('id');
        $pos = dbFetchOne("SELECT name FROM volunteer_positions WHERE id = ?", [$id]);
        if ($pos) {
            // Unassign volunteers first
            dbExecute("UPDATE users SET position_id = NULL WHERE position_id = ?", [$id]);
            dbExecute("DELETE FROM volunteer_positions WHERE id = ?", [$id]);
            logAudit('delete', 'volunteer_positions', $id);
            setFlash('success', 'Η θέση "' . $pos['name'] . '" διαγράφηκε.');
        }
        redirect('volunteer-positions.php');
    }
}

// =============================================
// Fetch
// =============================================
$editId = get('edit') ? (int) get('edit') : null;

$positions = dbFetchAll("
    SELECT vp.*, COUNT(u.id) AS volunteer_count
    FROM volunteer_positions vp
    LEFT JOIN users u ON u.position_id = vp.id AND u.is_active = 1
    GROUP BY vp.id
    ORDER BY vp.sort_order ASC, vp.name ASC
");

$editPos = $editId ? dbFetchOne("SELECT * FROM volunteer_positions WHERE id = ?", [$editId]) : null;

$colorOptions = [
    'primary'   => 'Μπλε',
    'secondary' => 'Γκρι',
    'success'   => 'Πράσινο',
    'danger'    => 'Κόκκινο',
    'warning'   => 'Πορτοκαλί',
    'info'      => 'Γαλάζιο',
    'dark'      => 'Σκούρο',
];

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0"><i class="bi bi-person-badge me-2"></i><?= h($pageTitle) ?></h1>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addModal">
        <i class="bi bi-plus-circle me-1"></i>Νέα Θέση
    </button>
</div>

<?php displayFlash(); ?>

<div class="card shadow-sm">
    <div class="card-body p-0">
        <table class="table table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th style="width: 40px">#</th>
                    <th>Θέση / Ρόλος</th>
                    <th style="width: 100px" class="text-center">Χρώμα</th>
                    <th>Εικονίδιο</th>
                    <th style="width: 80px" class="text-center">Εθελοντές</th>
                    <th style="width: 90px" class="text-center">Κατάσταση</th>
                    <th style="width: 130px" class="text-center">Ενέργειες</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($positions)): ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">
                            <i class="bi bi-inbox fs-2 d-block mb-2"></i>
                            Δεν υπάρχουν θέσεις. Προσθέστε την πρώτη.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($positions as $i => $pos): ?>
                        <tr>
                            <td class="text-muted"><?= $pos['sort_order'] ?: $i + 1 ?></td>
                            <td>
                                <span class="badge bg-<?= h($pos['color']) ?> fs-6 me-2">
                                    <?php if ($pos['icon']): ?><i class="<?= h($pos['icon']) ?> me-1"></i><?php endif; ?>
                                    <?= h($pos['name']) ?>
                                </span>
                                <?php if ($pos['description']): ?>
                                    <small class="text-muted d-block mt-1"><?= h($pos['description']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-<?= h($pos['color']) ?> px-3"><?= h($colorOptions[$pos['color']] ?? $pos['color']) ?></span>
                            </td>
                            <td>
                                <?php if ($pos['icon']): ?>
                                    <i class="<?= h($pos['icon']) ?> fs-5"></i>
                                    <small class="text-muted ms-1"><?= h($pos['icon']) ?></small>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <span class="badge bg-secondary rounded-pill"><?= $pos['volunteer_count'] ?></span>
                            </td>
                            <td class="text-center">
                                <?php if ($pos['is_active']): ?>
                                    <span class="badge bg-success">Ενεργή</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Ανενεργή</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <a href="volunteer-positions.php?edit=<?= $pos['id'] ?>"
                                   class="btn btn-outline-primary btn-sm" title="Επεξεργασία">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <?php if ($pos['volunteer_count'] == 0): ?>
                                    <button class="btn btn-outline-danger btn-sm" title="Διαγραφή"
                                            data-bs-toggle="modal" data-bs-target="#deleteModal"
                                            data-id="<?= $pos['id'] ?>" data-name="<?= h($pos['name']) ?>">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                <?php else: ?>
                                    <button class="btn btn-outline-secondary btn-sm" disabled
                                            title="Δεν μπορεί να διαγραφεί — έχει <?= $pos['volunteer_count'] ?> εθελοντές">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add Modal -->
<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="add">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>Νέα Θέση</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <?php include __DIR__ . '/includes/position-form-fields.php'; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Ακύρωση</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Αποθήκευση</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if ($editPos): ?>
<!-- Edit Modal (auto-open) -->
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" value="<?= $editPos['id'] ?>">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Επεξεργασία Θέσης</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                            onclick="window.location='volunteer-positions.php'"></button>
                </div>
                <div class="modal-body">
                    <?php
                    // Inject values into the form fields partial
                    $pfName = $editPos['name'];
                    $pfColor = $editPos['color'];
                    $pfIcon = $editPos['icon'];
                    $pfDesc = $editPos['description'];
                    $pfActive = $editPos['is_active'];
                    $pfSort = $editPos['sort_order'];
                    include __DIR__ . '/includes/position-form-fields.php';
                    ?>
                </div>
                <div class="modal-footer">
                    <a href="volunteer-positions.php" class="btn btn-secondary">Ακύρωση</a>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Αποθήκευση</button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    new bootstrap.Modal(document.getElementById('editModal')).show();
    document.getElementById('editModal').addEventListener('hidden.bs.modal', function() {
        window.location = 'volunteer-positions.php';
    });
});
</script>
<?php endif; ?>

<!-- Delete Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" id="deleteId">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="bi bi-trash me-2"></i>Διαγραφή Θέσης</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    Είστε σίγουροι ότι θέλετε να διαγράψετε τη θέση <strong id="deleteName"></strong>;
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Ακύρωση</button>
                    <button type="submit" class="btn btn-danger"><i class="bi bi-trash me-1"></i>Διαγραφή</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.getElementById('deleteModal')?.addEventListener('show.bs.modal', function(e) {
    const btn = e.relatedTarget;
    document.getElementById('deleteId').value = btn.dataset.id;
    document.getElementById('deleteName').textContent = btn.dataset.name;
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
