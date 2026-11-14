<?php
/**
 * VolunteerOps - Vehicle Checklist Items
 * Admin page to manage the equipment checklist templates used at handover
 * (vehicle-handover.php). An item with no type applies to every vehicle type.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/fleet-functions.php';
requireLogin();

if (!canManageFleet()) {
    setFlash('error', 'Δεν έχετε δικαίωμα πρόσβασης.');
    redirect('dashboard.php');
}

$pageTitle = 'Checklist Οχημάτων';
$vehicleTypes = getVehicleTypes();

if (isPost()) {
    verifyCsrf();
    $action = post('action');

    if ($action === 'create' || $action === 'update') {
        $label = trim(post('label'));
        $type = post('type') ?: null;
        if ($type !== null && !array_key_exists($type, $vehicleTypes)) {
            $type = null;
        }
        $sortOrder = (int) post('sort_order', 0);

        if (empty($label)) {
            setFlash('error', 'Η περιγραφή είναι υποχρεωτική.');
        } elseif ($action === 'create') {
            $newId = dbInsert(
                "INSERT INTO vehicle_checklist_items (type, label, sort_order) VALUES (?, ?, ?)",
                [$type, $label, $sortOrder]
            );
            logAudit('vehicle_checklist_item_create', 'vehicle_checklist_items', $newId);
            setFlash('success', 'Το στοιχείο προστέθηκε: ' . $label);
        } else {
            $itemId = (int) post('item_id');
            dbExecute(
                "UPDATE vehicle_checklist_items SET type = ?, label = ?, sort_order = ? WHERE id = ?",
                [$type, $label, $sortOrder, $itemId]
            );
            logAudit('vehicle_checklist_item_update', 'vehicle_checklist_items', $itemId);
            setFlash('success', 'Το στοιχείο ενημερώθηκε.');
        }
        redirect('vehicle-checklist.php');
    }

    if ($action === 'toggle') {
        $itemId = (int) post('item_id');
        $isActive = (int) post('is_active');
        dbExecute("UPDATE vehicle_checklist_items SET is_active = ? WHERE id = ?", [$isActive, $itemId]);
        setFlash('success', $isActive ? 'Το στοιχείο ενεργοποιήθηκε.' : 'Το στοιχείο απενεργοποιήθηκε.');
        redirect('vehicle-checklist.php');
    }

    if ($action === 'delete') {
        $itemId = (int) post('item_id');
        dbExecute("DELETE FROM vehicle_checklist_items WHERE id = ?", [$itemId]);
        logAudit('vehicle_checklist_item_delete', 'vehicle_checklist_items', $itemId);
        setFlash('success', 'Το στοιχείο διαγράφηκε.');
        redirect('vehicle-checklist.php');
    }
}

$items = dbFetchAll("SELECT * FROM vehicle_checklist_items ORDER BY type IS NULL DESC, type, sort_order, label");

$editId = (int) get('edit', 0);
$editItem = null;
foreach ($items as $item) {
    if ($item['id'] == $editId) {
        $editItem = $item;
        break;
    }
}

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
    <h1 class="h3 mb-0">
        <i class="bi bi-list-check me-2"></i><?= h($pageTitle) ?>
    </h1>
    <a href="vehicles.php" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Οχήματα
    </a>
</div>

<?= showFlash() ?>

<div class="row">
    <div class="col-lg-8">
        <?php if (empty($items)): ?>
            <div class="card">
                <div class="card-body text-center py-5">
                    <i class="bi bi-list-check text-muted" style="font-size: 3rem;"></i>
                    <p class="text-muted mt-3">Δεν υπάρχουν στοιχεία checklist. Δημιουργήστε ένα.</p>
                </div>
            </div>
        <?php else: ?>
            <div class="card">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 admin-mobile-cards">
                            <thead>
                                <tr>
                                    <th>Στοιχείο</th>
                                    <th>Τύπος Οχήματος</th>
                                    <th>Κατάσταση</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($items as $item): ?>
                                    <tr class="<?= !$item['is_active'] ? 'table-secondary' : '' ?>">
                                        <td data-label="Στοιχείο"><strong><?= h($item['label']) ?></strong></td>
                                        <td data-label="Τύπος Οχήματος">
                                            <?= $item['type'] ? h($vehicleTypes[$item['type']] ?? $item['type']) : '<span class="badge bg-secondary">Όλα</span>' ?>
                                        </td>
                                        <td data-label="Κατάσταση">
                                            <?= $item['is_active'] ? '<span class="badge bg-success">Ενεργό</span>' : '<span class="badge bg-secondary">Ανενεργό</span>' ?>
                                        </td>
                                        <td data-label="Ενέργειες" class="text-end mobile-card-actions">
                                            <div class="btn-group btn-group-sm">
                                                <a href="?edit=<?= $item['id'] ?>" class="btn btn-outline-secondary" title="Επεξεργασία">
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                                <form method="post" class="d-inline">
                                                    <?= csrfField() ?>
                                                    <input type="hidden" name="action" value="toggle">
                                                    <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                                                    <input type="hidden" name="is_active" value="<?= $item['is_active'] ? 0 : 1 ?>">
                                                    <button type="submit" class="btn btn-outline-<?= $item['is_active'] ? 'warning' : 'success' ?>">
                                                        <i class="bi bi-<?= $item['is_active'] ? 'pause' : 'play' ?>"></i>
                                                    </button>
                                                </form>
                                                <form method="post" class="d-inline">
                                                    <?= csrfField() ?>
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                                                    <button type="submit" class="btn btn-outline-danger"
                                                            onclick="return confirm('Διαγραφή στοιχείου «<?= h($item['label']) ?>»;')">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-4">
        <div class="card">
            <div class="card-header bg-success text-white">
                <h5 class="mb-0">
                    <i class="bi bi-<?= $editItem ? 'pencil' : 'plus-lg' ?> me-1"></i>
                    <?= $editItem ? 'Επεξεργασία Στοιχείου' : 'Νέο Στοιχείο' ?>
                </h5>
            </div>
            <div class="card-body">
                <form method="post">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="<?= $editItem ? 'update' : 'create' ?>">
                    <?php if ($editItem): ?>
                        <input type="hidden" name="item_id" value="<?= $editItem['id'] ?>">
                    <?php endif; ?>

                    <div class="mb-3">
                        <label class="form-label">Περιγραφή *</label>
                        <input type="text" class="form-control" name="label" required
                               value="<?= h($editItem['label'] ?? '') ?>"
                               placeholder="π.χ. Πυροσβεστήρας, Σωσίβια">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Τύπος Οχήματος</label>
                        <select class="form-select" name="type">
                            <option value="">— Όλα τα οχήματα —</option>
                            <?php foreach ($vehicleTypes as $key => $label): ?>
                                <option value="<?= $key ?>" <?= ($editItem['type'] ?? '') === $key ? 'selected' : '' ?>>
                                    <?= h($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Σειρά Εμφάνισης</label>
                        <input type="number" class="form-control" name="sort_order"
                               value="<?= h($editItem['sort_order'] ?? 0) ?>">
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-success flex-fill">
                            <i class="bi bi-check-lg me-1"></i><?= $editItem ? 'Ενημέρωση' : 'Δημιουργία' ?>
                        </button>
                        <?php if ($editItem): ?>
                            <a href="vehicle-checklist.php" class="btn btn-outline-secondary">Ακύρωση</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<style>
@media (max-width: 767.98px) {
    .admin-mobile-cards thead { display: none; }
    .admin-mobile-cards, .admin-mobile-cards tbody, .admin-mobile-cards tr, .admin-mobile-cards td { display: block; width: 100%; }
    .admin-mobile-cards tr { margin: .75rem; width: calc(100% - 1.5rem); padding: .75rem; border: 1px solid var(--bs-border-color); border-radius: .75rem; background: var(--bs-body-bg); }
    .admin-mobile-cards td { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; padding: .45rem 0; border: 0; text-align: right !important; }
    .admin-mobile-cards td::before { content: attr(data-label); flex: 0 0 38%; color: var(--bs-secondary-color); font-weight: 600; text-align: left; }
    .admin-mobile-cards .mobile-card-actions { align-items: center; padding-top: .75rem; border-top: 1px solid var(--bs-border-color); }
}
</style>

<?php include __DIR__ . '/includes/footer.php'; ?>
