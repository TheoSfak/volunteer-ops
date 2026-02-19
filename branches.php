<?php
/**
 * VolunteerOps - Παραρτήματα (Regional Branches)
 * Manage city-based departments that serve dual purpose:
 *   - Τμήμα πόλης: assigned to volunteers (users.warehouse_id)
 *   - Αποθήκη:     assigned to inventory items (inventory_items.department_id)
 * 
 * These are departments with has_inventory = 1.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/inventory-functions.php';
requireLogin();
requireInventoryTables();
requireRole([ROLE_SYSTEM_ADMIN]);

$pageTitle = 'Παραρτήματα';

// Handle POST actions
if (isPost()) {
    verifyCsrf();
    $action = post('action');

    // --- CREATE ---
    if ($action === 'create') {
        $name          = trim(post('name'));
        $description   = trim(post('description', ''));
        $barcodePrefix = strtoupper(trim(post('barcode_prefix', 'INV')));
        $address       = trim(post('address', ''));
        $phone         = trim(post('phone', ''));
        $notes         = trim(post('notes', ''));

        if (empty($name)) {
            setFlash('error', 'Το όνομα είναι υποχρεωτικό.');
            redirect('branches.php');
        }

        $exists = dbFetchOne("SELECT id FROM departments WHERE name = ?", [$name]);
        if ($exists) {
            setFlash('error', 'Υπάρχει ήδη τμήμα/παράρτημα με αυτό το όνομα.');
            redirect('branches.php');
        }

        $settings = json_encode([
            'barcode_prefix' => $barcodePrefix,
            'address'        => $address,
            'phone'          => $phone,
            'notes'          => $notes,
        ], JSON_UNESCAPED_UNICODE);

        $id = dbInsert(
            "INSERT INTO departments (name, description, is_active, has_inventory, inventory_settings, created_at, updated_at)
             VALUES (?, ?, 1, 1, ?, NOW(), NOW())",
            [$name, $description, $settings]
        );

        logAudit('branch_create', 'departments', $id);
        setFlash('success', 'Το παράρτημα δημιουργήθηκε: ' . $name);
        redirect('branches.php');
    }

    // --- UPDATE ---
    if ($action === 'update') {
        $id            = (int)post('id');
        $name          = trim(post('name'));
        $description   = trim(post('description', ''));
        $barcodePrefix = strtoupper(trim(post('barcode_prefix', 'INV')));
        $address       = trim(post('address', ''));
        $phone         = trim(post('phone', ''));
        $notes         = trim(post('notes', ''));

        if (empty($name)) {
            setFlash('error', 'Το όνομα είναι υποχρεωτικό.');
            redirect('branches.php');
        }

        $exists = dbFetchOne("SELECT id FROM departments WHERE name = ? AND id != ?", [$name, $id]);
        if ($exists) {
            setFlash('error', 'Υπάρχει ήδη τμήμα/παράρτημα με αυτό το όνομα.');
            redirect('branches.php');
        }

        $settings = json_encode([
            'barcode_prefix' => $barcodePrefix,
            'address'        => $address,
            'phone'          => $phone,
            'notes'          => $notes,
        ], JSON_UNESCAPED_UNICODE);

        dbExecute(
            "UPDATE departments SET name = ?, description = ?, inventory_settings = ?, updated_at = NOW() WHERE id = ?",
            [$name, $description, $settings, $id]
        );

        logAudit('branch_update', 'departments', $id);
        setFlash('success', 'Το παράρτημα ενημερώθηκε.');
        redirect('branches.php');
    }

    // --- TOGGLE ACTIVE ---
    if ($action === 'toggle_active') {
        $id = (int)post('id');
        $dept = dbFetchOne("SELECT is_active FROM departments WHERE id = ? AND has_inventory = 1", [$id]);
        if ($dept) {
            $newActive = $dept['is_active'] ? 0 : 1;
            dbExecute("UPDATE departments SET is_active = ?, updated_at = NOW() WHERE id = ?", [$newActive, $id]);
            logAudit('branch_toggle', 'departments', $id);
            setFlash('success', $newActive ? 'Το παράρτημα ενεργοποιήθηκε.' : 'Το παράρτημα απενεργοποιήθηκε.');
        }
        redirect('branches.php');
    }

    // --- DELETE ---
    if ($action === 'delete') {
        $id = (int)post('id');
        $dept = dbFetchOne("SELECT * FROM departments WHERE id = ? AND has_inventory = 1", [$id]);

        if (!$dept) {
            setFlash('error', 'Δεν βρέθηκε το παράρτημα.');
            redirect('branches.php');
        }

        // Check references
        $volCount    = (int)dbFetchValue("SELECT COUNT(*) FROM users WHERE warehouse_id = ?", [$id]);
        $itemCount   = (int)dbFetchValue("SELECT COUNT(*) FROM inventory_items WHERE department_id = ?", [$id]);
        $locCount    = (int)dbFetchValue("SELECT COUNT(*) FROM inventory_locations WHERE department_id = ?", [$id]);

        if ($volCount > 0 || $itemCount > 0) {
            setFlash('error', 'Δεν μπορείτε να διαγράψετε παράρτημα με εθελοντές ή υλικά. Μεταφέρετε πρώτα.');
            redirect('branches.php');
        }

        // Remove locations first
        if ($locCount > 0) {
            dbExecute("DELETE FROM inventory_locations WHERE department_id = ?", [$id]);
        }
        dbExecute("DELETE FROM departments WHERE id = ?", [$id]);
        logAudit('branch_delete', 'departments', $id);
        setFlash('success', 'Το παράρτημα διαγράφηκε.');
        redirect('branches.php');
    }
}

// Fetch branches (departments with has_inventory=1)
$branches = dbFetchAll("
    SELECT d.*,
        (SELECT COUNT(*) FROM users u WHERE u.warehouse_id = d.id AND u.is_active = 1) AS volunteer_count,
        (SELECT COUNT(*) FROM users u WHERE u.warehouse_id = d.id) AS total_volunteer_count,
        (SELECT COUNT(*) FROM inventory_items i WHERE i.department_id = d.id AND i.is_active = 1) AS item_count,
        (SELECT COUNT(*) FROM inventory_items i WHERE i.department_id = d.id AND i.status = 'booked') AS booked_count,
        (SELECT COUNT(*) FROM inventory_locations l WHERE l.department_id = d.id) AS location_count,
        (SELECT COUNT(*) FROM inventory_bookings b 
            JOIN inventory_items i ON b.item_id = i.id 
            WHERE i.department_id = d.id AND b.status = 'active') AS active_bookings_count
    FROM departments d
    WHERE d.has_inventory = 1
    ORDER BY d.name
");

// Regular departments (no has_inventory) – for reference/info
$regularDepartments = dbFetchAll("
    SELECT d.*, 
        (SELECT COUNT(*) FROM users u WHERE u.department_id = d.id) AS users_count
    FROM departments d 
    WHERE (d.has_inventory = 0 OR d.has_inventory IS NULL)
    ORDER BY d.name
");

// Editing?
$editId   = (int)get('edit', 0);
$editItem = null;
$editSettings = [];
if ($editId) {
    $editItem = dbFetchOne("SELECT * FROM departments WHERE id = ? AND has_inventory = 1", [$editId]);
    if ($editItem && !empty($editItem['inventory_settings'])) {
        $editSettings = json_decode($editItem['inventory_settings'], true) ?: [];
    }
}

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-1">
            <i class="bi bi-geo-alt-fill me-2"></i><?= h($pageTitle) ?>
        </h1>
        <p class="text-muted mb-0">Τμήματα πόλεων — η κάθε πόλη αντιστοιχεί σε τμήμα εθελοντών <strong>και</strong> αποθήκη υλικών.</p>
    </div>
</div>

<?= showFlash() ?>

<!-- Info box explaining the dual concept -->
<div class="alert alert-info d-flex align-items-start gap-3 mb-4">
    <i class="bi bi-info-circle-fill fs-4 mt-1"></i>
    <div>
        <strong>Πώς λειτουργεί:</strong> Κάθε παράρτημα (π.χ. «Ηράκλειο», «Χερσόνησος») αντιστοιχεί ταυτόχρονα σε:
        <ul class="mb-0 mt-1">
            <li><i class="bi bi-people text-primary me-1"></i><strong>Τμήμα εθελοντών</strong> — επιλέγεται στην <em>Αποθήκη/Πόλη</em> κατά την επεξεργασία εθελοντή</li>
            <li><i class="bi bi-box-seam text-success me-1"></i><strong>Αποθήκη υλικών</strong> — επιλέγεται ως αποθήκη στα υλικά αποθέματος</li>
        </ul>
    </div>
</div>

<div class="row g-4">
    <!-- Left: Branch list -->
    <div class="col-lg-8">
        <?php if (empty($branches)): ?>
            <div class="card">
                <div class="card-body text-center py-5">
                    <i class="bi bi-geo-alt text-muted" style="font-size: 3rem;"></i>
                    <p class="text-muted mt-3 mb-0">Δεν υπάρχουν παραρτήματα. Δημιουργήστε το πρώτο.</p>
                </div>
            </div>
        <?php else: ?>
            <div class="row g-3">
                <?php foreach ($branches as $b): ?>
                    <?php
                    $brSettings = !empty($b['inventory_settings']) ? json_decode($b['inventory_settings'], true) : [];
                    $prefix     = $brSettings['barcode_prefix'] ?? 'INV';
                    $brAddress  = $brSettings['address'] ?? '';
                    $brPhone    = $brSettings['phone'] ?? '';
                    $brNotes    = $brSettings['notes'] ?? '';
                    ?>
                    <div class="col-md-6">
                        <div class="card h-100 <?= !$b['is_active'] ? 'bg-light border-secondary' : 'border-0 shadow-sm' ?>">
                            <div class="card-header d-flex justify-content-between align-items-center <?= $b['is_active'] ? 'bg-primary text-white' : 'bg-secondary text-white' ?>">
                                <h5 class="mb-0">
                                    <i class="bi bi-geo-alt-fill me-1"></i><?= h($b['name']) ?>
                                </h5>
                                <div class="d-flex gap-1">
                                    <?php if (!$b['is_active']): ?>
                                        <span class="badge bg-dark">Ανενεργό</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="card-body">
                                <?php if ($b['description']): ?>
                                    <p class="text-muted small mb-3"><?= h($b['description']) ?></p>
                                <?php endif; ?>

                                <!-- Dual role stats -->
                                <div class="row g-2 mb-3">
                                    <div class="col-12">
                                        <small class="text-uppercase fw-bold text-muted">
                                            <i class="bi bi-people me-1"></i>Τμήμα Εθελοντών
                                        </small>
                                    </div>
                                    <div class="col-6">
                                        <div class="text-center p-2 bg-light rounded">
                                            <div class="fs-5 fw-bold text-primary"><?= $b['volunteer_count'] ?></div>
                                            <small class="text-muted">Ενεργοί</small>
                                        </div>
                                    </div>
                                    <div class="col-6">
                                        <div class="text-center p-2 bg-light rounded">
                                            <div class="fs-5 fw-bold text-secondary"><?= $b['total_volunteer_count'] ?></div>
                                            <small class="text-muted">Σύνολο</small>
                                        </div>
                                    </div>
                                </div>

                                <div class="row g-2 mb-3">
                                    <div class="col-12">
                                        <small class="text-uppercase fw-bold text-muted">
                                            <i class="bi bi-box-seam me-1"></i>Αποθήκη Υλικών
                                        </small>
                                    </div>
                                    <div class="col-4">
                                        <div class="text-center p-2 bg-light rounded">
                                            <div class="fs-5 fw-bold text-success"><?= $b['item_count'] ?></div>
                                            <small class="text-muted">Υλικά</small>
                                        </div>
                                    </div>
                                    <div class="col-4">
                                        <div class="text-center p-2 bg-light rounded">
                                            <div class="fs-5 fw-bold text-warning"><?= $b['booked_count'] ?></div>
                                            <small class="text-muted">Χρεωμένα</small>
                                        </div>
                                    </div>
                                    <div class="col-4">
                                        <div class="text-center p-2 bg-light rounded">
                                            <div class="fs-5 fw-bold text-info"><?= $b['location_count'] ?></div>
                                            <small class="text-muted">Τοποθεσίες</small>
                                        </div>
                                    </div>
                                </div>

                                <!-- Extra info -->
                                <div class="small">
                                    <div class="mb-1">
                                        <i class="bi bi-upc text-muted me-1"></i>Barcode Prefix: <code><?= h($prefix) ?></code>
                                    </div>
                                    <?php if ($brAddress): ?>
                                        <div class="mb-1">
                                            <i class="bi bi-pin-map text-muted me-1"></i><?= h($brAddress) ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($brPhone): ?>
                                        <div class="mb-1">
                                            <i class="bi bi-telephone text-muted me-1"></i><?= h($brPhone) ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if ($brNotes): ?>
                                        <div class="mb-1">
                                            <i class="bi bi-chat-left-text text-muted me-1"></i><?= h($brNotes) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <!-- Action buttons -->
                                <div class="d-flex gap-2 mt-3 pt-2 border-top">
                                    <a href="volunteers.php?warehouse=<?= $b['id'] ?>" class="btn btn-sm btn-outline-primary flex-fill" title="Εθελοντές">
                                        <i class="bi bi-people me-1"></i>Εθελοντές
                                    </a>
                                    <a href="inventory.php?dept=<?= $b['id'] ?>" class="btn btn-sm btn-outline-success flex-fill" title="Υλικά">
                                        <i class="bi bi-box-seam me-1"></i>Υλικά
                                    </a>
                                    <a href="?edit=<?= $b['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Επεξεργασία">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <form method="post" class="d-inline">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="toggle_active">
                                        <input type="hidden" name="id" value="<?= $b['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-<?= $b['is_active'] ? 'warning' : 'success' ?>"
                                                title="<?= $b['is_active'] ? 'Απενεργοποίηση' : 'Ενεργοποίηση' ?>"
                                                onclick="return confirm('<?= $b['is_active'] ? 'Απενεργοποίηση' : 'Ενεργοποίηση' ?> παραρτήματος;')">
                                            <i class="bi bi-<?= $b['is_active'] ? 'pause' : 'play' ?>"></i>
                                        </button>
                                    </form>
                                    <?php if ($b['volunteer_count'] == 0 && $b['item_count'] == 0): ?>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Διαγραφή παραρτήματος «<?= h($b['name']) ?>»;')">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= $b['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Διαγραφή">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Regular departments reference -->
        <?php if (!empty($regularDepartments)): ?>
        <div class="card mt-4">
            <div class="card-header bg-light">
                <h6 class="mb-0">
                    <i class="bi bi-building me-1"></i>Λειτουργικά Τμήματα
                    <small class="text-muted ms-2">(ρόλοι, δεν αφορούν αποθήκη)</small>
                </h6>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Τμήμα</th>
                            <th class="text-center">Χρήστες</th>
                            <th>Περιγραφή</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($regularDepartments as $rd): ?>
                        <tr>
                            <td><i class="bi bi-building text-muted me-1"></i><?= h($rd['name']) ?></td>
                            <td class="text-center"><span class="badge bg-secondary"><?= $rd['users_count'] ?></span></td>
                            <td class="text-muted small"><?= h($rd['description'] ?: '-') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-footer bg-light text-muted small">
                <i class="bi bi-info-circle me-1"></i>Τα λειτουργικά τμήματα (π.χ. Διασωστών, Υγειονομική) αντιστοιχίζονται στο πεδίο <em>Τμήμα</em> του εθελοντή.
                Τα παραρτήματα αντιστοιχίζονται στο πεδίο <em>Αποθήκη/Πόλη</em>.
                <a href="departments.php" class="ms-2">Διαχείριση τμημάτων →</a>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Right: Create/Edit form -->
    <div class="col-lg-4">
        <div class="card sticky-top" style="top: 80px;">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">
                    <i class="bi bi-<?= $editItem ? 'pencil' : 'plus-lg' ?> me-1"></i>
                    <?= $editItem ? 'Επεξεργασία Παραρτήματος' : 'Νέο Παράρτημα' ?>
                </h5>
            </div>
            <div class="card-body">
                <form method="post">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="<?= $editItem ? 'update' : 'create' ?>">
                    <?php if ($editItem): ?>
                        <input type="hidden" name="id" value="<?= $editItem['id'] ?>">
                    <?php endif; ?>

                    <div class="mb-3">
                        <label class="form-label fw-bold">Όνομα Παραρτήματος *</label>
                        <input type="text" class="form-control" name="name" required
                               value="<?= h($editItem['name'] ?? '') ?>"
                               placeholder="π.χ. Ηράκλειο">
                        <small class="text-muted">Αυτό εμφανίζεται ως τμήμα στους εθελοντές και ως αποθήκη στα υλικά</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Περιγραφή</label>
                        <textarea class="form-control" name="description" rows="2"
                                  placeholder="Σύντομη περιγραφή παραρτήματος"><?= h($editItem['description'] ?? '') ?></textarea>
                    </div>

                    <hr class="my-3">
                    <small class="text-uppercase fw-bold text-muted d-block mb-2">
                        <i class="bi bi-box-seam me-1"></i>Ρυθμίσεις Αποθήκης
                    </small>

                    <div class="mb-3">
                        <label class="form-label">Barcode Prefix</label>
                        <input type="text" class="form-control" name="barcode_prefix" maxlength="10"
                               value="<?= h($editSettings['barcode_prefix'] ?? 'INV') ?>"
                               placeholder="π.χ. HER">
                        <small class="text-muted">Πρόθεμα για αυτόματη δημιουργία barcode υλικών</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Διεύθυνση</label>
                        <input type="text" class="form-control" name="address"
                               value="<?= h($editSettings['address'] ?? '') ?>"
                               placeholder="π.χ. Λ. Κνωσού 210, Ηράκλειο">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Τηλέφωνο</label>
                        <input type="text" class="form-control" name="phone"
                               value="<?= h($editSettings['phone'] ?? '') ?>"
                               placeholder="π.χ. 2810 xxxxxx">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Σημειώσεις</label>
                        <textarea class="form-control" name="notes" rows="2"
                                  placeholder="Σχόλια, ωράριο λειτουργίας, κ.λπ."><?= h($editSettings['notes'] ?? '') ?></textarea>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary flex-fill">
                            <i class="bi bi-check-lg me-1"></i><?= $editItem ? 'Ενημέρωση' : 'Δημιουργία' ?>
                        </button>
                        <?php if ($editItem): ?>
                            <a href="branches.php" class="btn btn-outline-secondary">Ακύρωση</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <!-- Quick links -->
        <div class="card mt-3">
            <div class="card-body">
                <h6 class="card-title"><i class="bi bi-link-45deg me-1"></i>Σχετικές Σελίδες</h6>
                <div class="list-group list-group-flush">
                    <a href="volunteers.php" class="list-group-item list-group-item-action d-flex align-items-center">
                        <i class="bi bi-people text-primary me-2"></i>Εθελοντές
                        <small class="text-muted ms-auto">Αντιστοίχιση πόλης</small>
                    </a>
                    <a href="inventory.php" class="list-group-item list-group-item-action d-flex align-items-center">
                        <i class="bi bi-box-seam text-success me-2"></i>Υλικά
                        <small class="text-muted ms-auto">Ανά αποθήκη</small>
                    </a>
                    <a href="inventory-locations.php" class="list-group-item list-group-item-action d-flex align-items-center">
                        <i class="bi bi-geo-alt text-info me-2"></i>Τοποθεσίες
                        <small class="text-muted ms-auto">Ανά αποθήκη</small>
                    </a>
                    <a href="departments.php" class="list-group-item list-group-item-action d-flex align-items-center">
                        <i class="bi bi-building text-secondary me-2"></i>Λειτουργικά Τμήματα
                        <small class="text-muted ms-auto">Ρόλοι</small>
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
