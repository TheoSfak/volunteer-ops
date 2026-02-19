<?php
/**
 * VolunteerOps - Inventory Warehouses Management
 * Manage warehouse departments (multi-tenancy for inventory).
 * Each warehouse is a department with has_inventory=1.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/inventory-functions.php';
requireLogin();
requireInventoryTables();
requireRole([ROLE_SYSTEM_ADMIN]);

$pageTitle = 'Αποθήκες';

// Handle POST
if (isPost()) {
    verifyCsrf();
    $action = post('action');

    if ($action === 'create') {
        $name  = trim(post('name'));
        $notes = trim(post('notes', ''));
        $barcodePrefix = strtoupper(trim(post('barcode_prefix', 'INV')));

        if (empty($name)) {
            setFlash('error', 'Το όνομα είναι υποχρεωτικό.');
            redirect('inventory-warehouses.php');
        }

        // Check duplicate name
        $exists = dbFetchOne("SELECT id FROM departments WHERE name = ?", [$name]);
        if ($exists) {
            setFlash('error', 'Υπάρχει ήδη τμήμα με αυτό το όνομα.');
            redirect('inventory-warehouses.php');
        }

        $settings = json_encode([
            'barcode_prefix' => $barcodePrefix,
            'notes'          => $notes,
        ], JSON_UNESCAPED_UNICODE);

        $id = dbInsert(
            "INSERT INTO departments (name, is_active, has_inventory, inventory_settings) VALUES (?, 1, 1, ?)",
            [$name, $settings]
        );

        logAudit('warehouse_create', 'departments', $id);
        setFlash('success', 'Η αποθήκη δημιουργήθηκε: ' . $name);
        redirect('inventory-warehouses.php');
    }

    if ($action === 'update') {
        $id    = (int)post('id');
        $name  = trim(post('name'));
        $notes = trim(post('notes', ''));
        $barcodePrefix = strtoupper(trim(post('barcode_prefix', 'INV')));

        if (empty($name)) {
            setFlash('error', 'Το όνομα είναι υποχρεωτικό.');
            redirect('inventory-warehouses.php');
        }

        // Check duplicate name
        $exists = dbFetchOne("SELECT id FROM departments WHERE name = ? AND id != ?", [$name, $id]);
        if ($exists) {
            setFlash('error', 'Υπάρχει ήδη τμήμα με αυτό το όνομα.');
            redirect('inventory-warehouses.php');
        }

        $settings = json_encode([
            'barcode_prefix' => $barcodePrefix,
            'notes'          => $notes,
        ], JSON_UNESCAPED_UNICODE);

        dbExecute(
            "UPDATE departments SET name = ?, inventory_settings = ? WHERE id = ?",
            [$name, $settings, $id]
        );

        logAudit('warehouse_update', 'departments', $id);
        setFlash('success', 'Η αποθήκη ενημερώθηκε.');
        redirect('inventory-warehouses.php');
    }

    if ($action === 'toggle_active') {
        $id = (int)post('id');
        $dept = dbFetchOne("SELECT is_active FROM departments WHERE id = ? AND has_inventory = 1", [$id]);
        if ($dept) {
            $newActive = $dept['is_active'] ? 0 : 1;
            dbExecute("UPDATE departments SET is_active = ? WHERE id = ?", [$newActive, $id]);
            logAudit('warehouse_toggle', 'departments', $id);
            setFlash('success', $newActive ? 'Η αποθήκη ενεργοποιήθηκε.' : 'Η αποθήκη απενεργοποιήθηκε.');
        }
        redirect('inventory-warehouses.php');
    }

    if ($action === 'assign_items') {
        $warehouseId = (int)post('warehouse_id');
        $scope       = post('scope', 'unassigned'); // 'unassigned' or 'all'

        // Verify warehouse exists
        $wh = dbFetchOne("SELECT id, name FROM departments WHERE id = ? AND has_inventory = 1", [$warehouseId]);
        if (!$wh) {
            setFlash('error', 'Η αποθήκη δεν βρέθηκε.');
            redirect('inventory-warehouses.php');
        }

        if ($scope === 'all') {
            $affected = dbExecute("UPDATE inventory_items SET department_id = ?", [$warehouseId]);
        } else {
            $affected = dbExecute("UPDATE inventory_items SET department_id = ? WHERE department_id IS NULL", [$warehouseId]);
        }

        logAudit('warehouse_assign_items', 'departments', $warehouseId);
        setFlash('success', "Ανατέθηκαν {$affected} υλικά στην αποθήκη: " . $wh['name']);
        redirect('inventory-warehouses.php');
    }
}

// Get all warehouse departments
$warehouses = dbFetchAll("
    SELECT d.*, 
        (SELECT COUNT(*) FROM inventory_items WHERE department_id = d.id AND is_active = 1) AS item_count,
        (SELECT COUNT(*) FROM inventory_items WHERE department_id = d.id AND status = 'booked') AS booked_count,
        (SELECT COUNT(*) FROM inventory_locations WHERE department_id = d.id) AS location_count,
        (SELECT COUNT(*) FROM users WHERE warehouse_id = d.id AND is_active = 1) AS user_count
    FROM departments d 
    WHERE d.has_inventory = 1
    ORDER BY d.name
");

// Count unassigned items
$unassignedCount = (int)dbFetchValue("SELECT COUNT(*) FROM inventory_items WHERE department_id IS NULL AND is_active = 1");

// Editing?
$editId   = (int)get('edit', 0);
$editItem = null;
if ($editId) {
    $editItem = dbFetchOne("SELECT * FROM departments WHERE id = ? AND has_inventory = 1", [$editId]);
}

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">
        <i class="bi bi-building me-2"></i><?= h($pageTitle) ?>
    </h1>
    <a href="inventory.php" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Υλικά
    </a>
</div>

<?php if ($unassignedCount > 0): ?>
<div class="alert alert-warning d-flex align-items-center gap-3">
    <i class="bi bi-exclamation-triangle-fill fs-4"></i>
    <div class="flex-grow-1">
        <strong><?= $unassignedCount ?> υλικά</strong> δεν έχουν ανατεθεί σε αποθήκη.
    </div>
    <?php if (!empty($warehouses)): ?>
    <form method="post" class="d-flex gap-2">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="assign_items">
        <input type="hidden" name="scope" value="unassigned">
        <select class="form-select form-select-sm" name="warehouse_id" style="max-width: 200px;" required>
            <option value="">Επιλέξτε αποθήκη</option>
            <?php foreach ($warehouses as $wh): ?>
                <option value="<?= $wh['id'] ?>"><?= h($wh['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-sm btn-warning" onclick="return confirm('Ανάθεση <?= $unassignedCount ?> υλικών στην επιλεγμένη αποθήκη;')">
            <i class="bi bi-arrow-right me-1"></i>Ανάθεση
        </button>
    </form>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="row">
    <!-- Left: Warehouse list -->
    <div class="col-lg-8">
        <?php if (empty($warehouses)): ?>
            <div class="card">
                <div class="card-body text-center py-5">
                    <i class="bi bi-building text-muted" style="font-size: 3rem;"></i>
                    <p class="text-muted mt-3">Δεν υπάρχουν αποθήκες. Δημιουργήστε μία.</p>
                </div>
            </div>
        <?php else: ?>
            <div class="row g-3">
                <?php foreach ($warehouses as $wh): ?>
                    <?php
                    $settings = !empty($wh['inventory_settings']) ? json_decode($wh['inventory_settings'], true) : [];
                    $prefix   = $settings['barcode_prefix'] ?? 'INV';
                    $notes    = $settings['notes'] ?? '';
                    ?>
                    <div class="col-md-6">
                        <div class="card h-100 <?= !$wh['is_active'] ? 'bg-light border-secondary' : 'border-primary' ?> border-2">
                            <div class="card-header d-flex justify-content-between align-items-center <?= $wh['is_active'] ? 'bg-primary text-white' : 'bg-secondary text-white' ?>">
                                <h5 class="mb-0">
                                    <i class="bi bi-building me-1"></i><?= h($wh['name']) ?>
                                </h5>
                                <?php if (!$wh['is_active']): ?>
                                    <span class="badge bg-dark">Ανενεργή</span>
                                <?php endif; ?>
                            </div>
                            <div class="card-body">
                                <div class="row g-2 mb-3">
                                    <div class="col-6">
                                        <div class="text-center p-2 bg-light rounded">
                                            <div class="fs-4 fw-bold text-primary"><?= $wh['item_count'] ?></div>
                                            <small class="text-muted">Υλικά</small>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="text-center p-2 bg-light rounded">
                                            <div class="fs-4 fw-bold text-warning"><?= $wh['booked_count'] ?></div>
                                            <small class="text-muted">Χρεωμένα</small>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="text-center p-2 bg-light rounded">
                                            <div class="fs-4 fw-bold text-info"><?= $wh['location_count'] ?></div>
                                            <small class="text-muted">Τοποθεσίες</small>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="text-center p-2 bg-light rounded">
                                            <div class="fs-4 fw-bold text-success"><?= $wh['user_count'] ?></div>
                                            <small class="text-muted">Μέλη</small>
                                        </div>
                                    </div>
                                </div>

                                <div class="mb-2">
                                    <small class="text-muted">Barcode Prefix:</small>
                                    <code class="ms-1"><?= h($prefix) ?></code>
                                </div>
                                <?php if ($notes): ?>
                                    <div class="mb-2">
                                        <small class="text-muted"><?= h($notes) ?></small>
                                    </div>
                                <?php endif; ?>

                                <div class="d-flex gap-2 mt-3">
                                    <a href="inventory.php?dept=<?= $wh['id'] ?>" class="btn btn-sm btn-outline-primary flex-fill">
                                        <i class="bi bi-box-seam me-1"></i>Υλικά
                                    </a>
                                    <a href="?edit=<?= $wh['id'] ?>" class="btn btn-sm btn-outline-secondary">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <form method="post" class="d-inline">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="toggle_active">
                                        <input type="hidden" name="id" value="<?= $wh['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-<?= $wh['is_active'] ? 'warning' : 'success' ?>"
                                                onclick="return confirm('<?= $wh['is_active'] ? 'Απενεργοποίηση' : 'Ενεργοποίηση' ?> αποθήκης;')">
                                            <i class="bi bi-<?= $wh['is_active'] ? 'pause' : 'play' ?>"></i>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Right: Create / Edit Form -->
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header bg-success text-white">
                <h5 class="mb-0">
                    <i class="bi bi-<?= $editItem ? 'pencil' : 'plus-lg' ?> me-1"></i>
                    <?= $editItem ? 'Επεξεργασία Αποθήκης' : 'Νέα Αποθήκη' ?>
                </h5>
            </div>
            <div class="card-body">
                <form method="post">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="<?= $editItem ? 'update' : 'create' ?>">
                    <?php if ($editItem): ?>
                        <input type="hidden" name="id" value="<?= $editItem['id'] ?>">
                    <?php endif; ?>

                    <?php
                    $editSettings = [];
                    if ($editItem && !empty($editItem['inventory_settings'])) {
                        $editSettings = json_decode($editItem['inventory_settings'], true) ?: [];
                    }
                    ?>

                    <div class="mb-3">
                        <label class="form-label">Όνομα Αποθήκης *</label>
                        <input type="text" class="form-control" name="name" required
                               value="<?= h($editItem['name'] ?? '') ?>"
                               placeholder="π.χ. Αποθήκη Ηρακλείου">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Barcode Prefix</label>
                        <input type="text" class="form-control" name="barcode_prefix" maxlength="10"
                               value="<?= h($editSettings['barcode_prefix'] ?? 'INV') ?>"
                               placeholder="π.χ. HER">
                        <small class="text-muted">Πρόθεμα για αυτόματη δημιουργία barcode</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Σημειώσεις</label>
                        <textarea class="form-control" name="notes" rows="2"
                                  placeholder="Διεύθυνση, τηλέφωνο, κ.λπ."><?= h($editSettings['notes'] ?? '') ?></textarea>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-success flex-fill">
                            <i class="bi bi-check-lg me-1"></i><?= $editItem ? 'Ενημέρωση' : 'Δημιουργία' ?>
                        </button>
                        <?php if ($editItem): ?>
                            <a href="inventory-warehouses.php" class="btn btn-outline-secondary">Ακύρωση</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
