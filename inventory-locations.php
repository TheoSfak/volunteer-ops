<?php
/**
 * VolunteerOps - Inventory Locations Management
 * Admin page to manage inventory locations (physical positions within warehouses).
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/inventory-functions.php';
requireLogin();
requireInventoryTables();

if (!canManageInventory()) {
    setFlash('error', 'Δεν έχετε δικαίωμα πρόσβασης.');
    redirect('inventory.php');
}

$pageTitle = 'Τοποθεσίες Υλικών';

// Get warehouses for dropdown
$warehouses = dbFetchAll("SELECT id, name FROM departments WHERE has_inventory = 1 AND is_active = 1 ORDER BY name");

// Location types
$locationTypes = [
    'warehouse' => 'Αποθήκη',
    'vehicle'   => 'Όχημα',
    'room'      => 'Δωμάτιο',
    'other'     => 'Άλλο',
];

// Handle POST actions
if (isPost()) {
    verifyCsrf();
    $action = post('action');

    if ($action === 'create') {
        $name         = trim(post('name'));
        $locationType = post('location_type', 'warehouse');
        $departmentId = post('department_id') ?: null;
        $address      = trim(post('address'));
        $capacity     = post('capacity') ? (int)post('capacity') : null;
        $notes        = trim(post('notes'));

        if (empty($name)) {
            setFlash('error', 'Το όνομα τοποθεσίας είναι υποχρεωτικό.');
        } else {
            try {
                dbInsert("
                    INSERT INTO inventory_locations (name, location_type, department_id, address, capacity, notes)
                    VALUES (?, ?, ?, ?, ?, ?)
                ", [$name, $locationType, $departmentId, $address, $capacity, $notes]);
                logAudit('inventory_location_create', 'inventory_locations', 0);
                setFlash('success', 'Η τοποθεσία δημιουργήθηκε: ' . $name);
            } catch (Exception $e) {
                setFlash('error', 'Σφάλμα δημιουργίας τοποθεσίας.');
            }
        }
        redirect('inventory-locations.php');
    }

    if ($action === 'update') {
        $locId        = (int)post('location_id');
        $name         = trim(post('name'));
        $locationType = post('location_type', 'warehouse');
        $departmentId = post('department_id') ?: null;
        $address      = trim(post('address'));
        $capacity     = post('capacity') ? (int)post('capacity') : null;
        $notes        = trim(post('notes'));

        if (empty($name)) {
            setFlash('error', 'Το όνομα τοποθεσίας είναι υποχρεωτικό.');
        } else {
            try {
                dbExecute("
                    UPDATE inventory_locations 
                    SET name = ?, location_type = ?, department_id = ?, address = ?, capacity = ?, notes = ?
                    WHERE id = ?
                ", [$name, $locationType, $departmentId, $address, $capacity, $notes, $locId]);
                logAudit('inventory_location_update', 'inventory_locations', $locId);
                setFlash('success', 'Η τοποθεσία ενημερώθηκε.');
            } catch (Exception $e) {
                setFlash('error', 'Σφάλμα ενημέρωσης τοποθεσίας.');
            }
        }
        redirect('inventory-locations.php');
    }

    if ($action === 'toggle') {
        $locId    = (int)post('location_id');
        $isActive = (int)post('is_active');
        dbExecute("UPDATE inventory_locations SET is_active = ? WHERE id = ?", [$isActive, $locId]);
        logAudit('inventory_location_toggle', 'inventory_locations', $locId);
        setFlash('success', $isActive ? 'Η τοποθεσία ενεργοποιήθηκε.' : 'Η τοποθεσία απενεργοποιήθηκε.');
        redirect('inventory-locations.php');
    }
}

// Fetch all locations (including inactive for admin)
$locations = dbFetchAll("
    SELECT l.*, d.name AS warehouse_name,
           (SELECT COUNT(*) FROM inventory_items i WHERE i.location_id = l.id AND i.is_active = 1) AS item_count
    FROM inventory_locations l
    LEFT JOIN departments d ON l.department_id = d.id
    ORDER BY l.name
");

// Editing mode
$editId  = (int)get('edit', 0);
$editLoc = null;
if ($editId) {
    foreach ($locations as $loc) {
        if ($loc['id'] == $editId) {
            $editLoc = $loc;
            break;
        }
    }
}

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">
        <i class="bi bi-geo-alt me-2"></i><?= h($pageTitle) ?>
    </h1>
    <a href="inventory.php" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Υλικά
    </a>
</div>

<?= showFlash() ?>

<div class="row">
    <!-- Left: Locations Table -->
    <div class="col-lg-8">
        <?php if (empty($locations)): ?>
            <div class="card">
                <div class="card-body text-center py-5">
                    <i class="bi bi-geo-alt text-muted" style="font-size: 3rem;"></i>
                    <p class="text-muted mt-3">Δεν υπάρχουν τοποθεσίες. Δημιουργήστε μία.</p>
                </div>
            </div>
        <?php else: ?>
            <div class="card">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Τοποθεσία</th>
                                    <th>Τύπος</th>
                                    <th>Αποθήκη</th>
                                    <th class="text-center">Υλικά</th>
                                    <th>Κατάσταση</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($locations as $loc): ?>
                                    <tr class="<?= !$loc['is_active'] ? 'table-secondary' : '' ?>">
                                        <td>
                                            <strong><?= h($loc['name']) ?></strong>
                                            <?php if ($loc['address']): ?>
                                                <br><small class="text-muted"><i class="bi bi-pin-map me-1"></i><?= h($loc['address']) ?></small>
                                            <?php endif; ?>
                                            <?php if ($loc['notes']): ?>
                                                <br><small class="text-muted"><?= h($loc['notes']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php
                                            $typeIcons = ['warehouse' => 'bi-building', 'vehicle' => 'bi-truck', 'room' => 'bi-door-open', 'other' => 'bi-pin-map'];
                                            $typeIcon = $typeIcons[$loc['location_type']] ?? 'bi-pin-map';
                                            ?>
                                            <span class="badge bg-secondary">
                                                <i class="bi <?= $typeIcon ?> me-1"></i><?= h($locationTypes[$loc['location_type']] ?? $loc['location_type']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($loc['warehouse_name']): ?>
                                                <span class="badge bg-info"><?= h($loc['warehouse_name']) ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-primary"><?= $loc['item_count'] ?></span>
                                        </td>
                                        <td>
                                            <?php if ($loc['is_active']): ?>
                                                <span class="badge bg-success">Ενεργή</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">Ανενεργή</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <div class="btn-group btn-group-sm">
                                                <a href="?edit=<?= $loc['id'] ?>" class="btn btn-outline-secondary" title="Επεξεργασία">
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                                <form method="post" class="d-inline">
                                                    <?= csrfField() ?>
                                                    <input type="hidden" name="action" value="toggle">
                                                    <input type="hidden" name="location_id" value="<?= $loc['id'] ?>">
                                                    <input type="hidden" name="is_active" value="<?= $loc['is_active'] ? 0 : 1 ?>">
                                                    <button type="submit" class="btn btn-outline-<?= $loc['is_active'] ? 'warning' : 'success' ?>"
                                                            title="<?= $loc['is_active'] ? 'Απενεργοποίηση' : 'Ενεργοποίηση' ?>"
                                                            onclick="return confirm('<?= $loc['is_active'] ? 'Απενεργοποίηση' : 'Ενεργοποίηση' ?> τοποθεσίας;')">
                                                        <i class="bi bi-<?= $loc['is_active'] ? 'pause' : 'play' ?>"></i>
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

    <!-- Right: Create / Edit Form -->
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header bg-success text-white">
                <h5 class="mb-0">
                    <i class="bi bi-<?= $editLoc ? 'pencil' : 'plus-lg' ?> me-1"></i>
                    <?= $editLoc ? 'Επεξεργασία Τοποθεσίας' : 'Νέα Τοποθεσία' ?>
                </h5>
            </div>
            <div class="card-body">
                <form method="post">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="<?= $editLoc ? 'update' : 'create' ?>">
                    <?php if ($editLoc): ?>
                        <input type="hidden" name="location_id" value="<?= $editLoc['id'] ?>">
                    <?php endif; ?>

                    <div class="mb-3">
                        <label class="form-label">Όνομα Τοποθεσίας *</label>
                        <input type="text" class="form-control" name="name" required
                               value="<?= h($editLoc['name'] ?? '') ?>"
                               placeholder="π.χ. Κεντρική Αποθήκη, Όχημα ΑΒΓ-1234">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Τύπος</label>
                        <select class="form-select" name="location_type">
                            <?php foreach ($locationTypes as $key => $label): ?>
                                <option value="<?= $key ?>" <?= ($editLoc['location_type'] ?? 'warehouse') === $key ? 'selected' : '' ?>>
                                    <?= h($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Αποθήκη</label>
                        <select class="form-select" name="department_id">
                            <option value="">— Καμία —</option>
                            <?php foreach ($warehouses as $wh): ?>
                                <option value="<?= $wh['id'] ?>" <?= ($editLoc['department_id'] ?? '') == $wh['id'] ? 'selected' : '' ?>>
                                    <?= h($wh['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Σε ποια αποθήκη ανήκει η τοποθεσία.</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Διεύθυνση</label>
                        <input type="text" class="form-control" name="address"
                               value="<?= h($editLoc['address'] ?? '') ?>"
                               placeholder="π.χ. Λεωφ. Κνωσού 120, Ηράκλειο">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Χωρητικότητα</label>
                        <input type="number" class="form-control" name="capacity" min="0"
                               value="<?= h($editLoc['capacity'] ?? '') ?>"
                               placeholder="Μέγιστος αριθμός υλικών">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Σημειώσεις</label>
                        <textarea class="form-control" name="notes" rows="2"
                                  placeholder="Π.χ. 2ος όροφος, ράφι Α1"><?= h($editLoc['notes'] ?? '') ?></textarea>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-success flex-fill">
                            <i class="bi bi-check-lg me-1"></i><?= $editLoc ? 'Ενημέρωση' : 'Δημιουργία' ?>
                        </button>
                        <?php if ($editLoc): ?>
                            <a href="inventory-locations.php" class="btn btn-outline-secondary">Ακύρωση</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
