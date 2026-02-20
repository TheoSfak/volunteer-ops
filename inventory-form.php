<?php
/**
 * VolunteerOps - Inventory Item Create/Edit Form
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/inventory-functions.php';
requireLogin();
requireInventoryTables();
if (isTraineeRescuer()) {
    setFlash('error', 'Δεν έχετε πρόσβαση σε αυτή τη σελίδα.');
    redirect('dashboard.php');
}

// Only admins can create/edit inventory items
if (!canManageInventory()) {
    setFlash('error', 'Δεν έχετε πρόσβαση σε αυτή τη σελίδα.');
    redirect('inventory.php');
}

$id     = get('id');
$isEdit = !empty($id);
$pageTitle = $isEdit ? '?pe?e??as?a ??????' : '??? ?????';

$user = getCurrentUser();

// Fetch item for editing
$item = null;
if ($isEdit) {
    $item = getInventoryItem($id);
    if (!$item) {
        setFlash('error', 'Δεν έχετε πρόσβαση σε αυτή τη σελίδα.');
        redirect('inventory.php');
    }
    // Department admin can only edit own department's items
    if ($user['role'] === ROLE_DEPARTMENT_ADMIN && $item['department_id'] && $item['department_id'] != $user['department_id']) {
        setFlash('error', 'Δεν έχετε πρόσβαση σε αυτή τη σελίδα.');
        redirect('inventory.php');
    }
}

// Data for dropdowns
$categories  = getInventoryCategories();
$locations   = getInventoryLocations();
$departments = getInventoryDepartments();

$errors = [];

// Handle POST
if (isPost()) {
    verifyCsrf();

    $action = post('action');

    // Delete action
    if ($action === 'delete' && $isEdit) {
        // Soft delete
        dbExecute("UPDATE inventory_items SET is_active = 0 WHERE id = ?", [$id]);
        logAudit('inventory_delete', 'inventory_items', $id);
        setFlash('success', '?? ????? d?a???f??e ep?t????.');
        redirect('inventory.php');
    }

    // Save action (create/edit)
    $data = [
        'barcode'         => trim(post('barcode')),
        'name'            => trim(post('name')),
        'description'     => post('description'),
        'category_id'     => post('category_id') ?: null,
        'department_id'   => post('department_id') ?: null,
        'location_id'     => post('location_id') ?: null,
        'location_notes'  => post('location_notes'),
        'status'          => post('status', 'available'),
        'condition_notes' => post('condition_notes'),
        'quantity'        => max(1, (int)post('quantity', 1)),
    ];

    // Validation
    if (empty($data['name'])) {
        $errors[] = '?? ???�a e??a? ?p???e?t???.';
    }

    // Barcode validation
    $barcodeError = validateBarcode($data['barcode'], $isEdit ? $id : null);
    if ($barcodeError) {
        $errors[] = $barcodeError;
    }

    // Department admin auto-assigns their department
    if ($user['role'] === ROLE_DEPARTMENT_ADMIN && $user['department_id']) {
        $data['department_id'] = $user['department_id'];
    }

    if (empty($errors)) {
        try {
            if ($isEdit) {
                dbExecute("
                    UPDATE inventory_items SET
                        barcode = ?, name = ?, description = ?, category_id = ?,
                        department_id = ?, location_id = ?, location_notes = ?,
                        status = ?, condition_notes = ?, quantity = ?, updated_at = NOW()
                    WHERE id = ?
                ", [
                    $data['barcode'], $data['name'], $data['description'], $data['category_id'],
                    $data['department_id'], $data['location_id'], $data['location_notes'],
                    $data['status'], $data['condition_notes'], $data['quantity'], $id
                ]);

                logAudit('inventory_update', 'inventory_items', $id);
                setFlash('success', '?? ????? e??�e?????e ep?t????.');
            } else {
                $newId = dbInsert("
                    INSERT INTO inventory_items 
                        (barcode, name, description, category_id, department_id, location_id,
                         location_notes, status, condition_notes, quantity, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ", [
                    $data['barcode'], $data['name'], $data['description'], $data['category_id'],
                    $data['department_id'], $data['location_id'], $data['location_notes'],
                    $data['status'], $data['condition_notes'], $data['quantity'], $user['id']
                ]);

                logAudit('inventory_create', 'inventory_items', $newId);
                setFlash('success', '?? ????? d?�?????????e ep?t????.');
                redirect('inventory-view.php?id=' . $newId);
            }

            redirect('inventory.php');
        } catch (Exception $e) {
            $errors[] = 'Sf??�a ap????e?s??: ' . $e->getMessage();
        }
    }
}

// Generate barcode suggestion for new items
$suggestedBarcode = '';
if (!$isEdit) {
    $suggestedBarcode = generateInventoryBarcode();
}

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">
        <i class="bi bi-box-seam me-2"></i><?= h($pageTitle) ?>
    </h1>
    <a href="<?= $isEdit ? 'inventory-view.php?id=' . $id : 'inventory.php' ?>" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>??s?
    </a>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <ul class="mb-0">
            <?php foreach ($errors as $error): ?>
                <li><?= h($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="post" action="">
    <?= csrfField() ?>

    <div class="row">
        <!-- Left Column: Main Info -->
        <div class="col-lg-8">
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">?as???? ?????f???e?</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label for="barcode" class="form-label">Barcode *</label>
                            <div class="input-group">
                                <input type="text" class="form-control" id="barcode" name="barcode"
                                       value="<?= h($item['barcode'] ?? post('barcode', $suggestedBarcode)) ?>" 
                                       required pattern="[A-Za-z0-9\-_]{3,50}">
                                <?php if (!$isEdit): ?>
                                    <button type="button" class="btn btn-outline-secondary" id="btnGenerateBarcode" title="??�??????a barcode">
                                        <i class="bi bi-arrow-clockwise"></i>
                                    </button>
                                <?php endif; ?>
                            </div>
                            <small class="form-text text-muted">3-50 ?a?a?t??e?: ???��ata, a???�??, -, _</small>
                        </div>
                        <div class="col-md-8 mb-3">
                            <label for="name" class="form-label">???�a *</label>
                            <input type="text" class="form-control" id="name" name="name"
                                   value="<?= h($item['name'] ?? post('name')) ?>" required>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="description" class="form-label">?e????af?</label>
                        <textarea class="form-control" id="description" name="description" rows="3"
                        ><?= h($item['description'] ?? post('description')) ?></textarea>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="category_id" class="form-label">?at?????a</label>
                            <select class="form-select" id="category_id" name="category_id">
                                <option value="">-- ?p????te --</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?= $cat['id'] ?>" <?= ($item['category_id'] ?? post('category_id')) == $cat['id'] ? 'selected' : '' ?>>
                                        <?= $cat['icon'] ?> <?= h($cat['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="quantity" class="form-label">??s?t?ta</label>
                            <input type="number" class="form-control" id="quantity" name="quantity"
                                   value="<?= h($item['quantity'] ?? post('quantity', 1)) ?>" min="1">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="status" class="form-label">?at?stas?</label>
                            <select class="form-select" id="status" name="status">
                                <?php foreach (INVENTORY_STATUS_LABELS as $key => $label): ?>
                                    <option value="<?= $key ?>" <?= ($item['status'] ?? post('status', 'available')) === $key ? 'selected' : '' ?>>
                                        <?= h($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">??p??es?a</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="location_id" class="form-label">?p????? / ??p??es?a</label>
                            <select class="form-select" id="location_id" name="location_id">
                                <option value="">-- ?p????te --</option>
                                <?php foreach ($locations as $loc): ?>
                                    <option value="<?= $loc['id'] ?>" <?= ($item['location_id'] ?? post('location_id')) == $loc['id'] ? 'selected' : '' ?>>
                                        <?= h($loc['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="location_notes" class="form-label">?ept?�??e?e? T?s??</label>
                            <input type="text" class="form-control" id="location_notes" name="location_notes"
                                   value="<?= h($item['location_notes'] ?? post('location_notes')) ?>"
                                   placeholder="p.?. ??f? ?1, 2?? ???f??">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Column: Meta & Actions -->
        <div class="col-lg-4">
            <?php if (isSystemAdmin()): ?>
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-building me-1"></i>?p?????</h5>
                </div>
                <div class="card-body">
                    <select class="form-select" name="department_id" required>
                        <option value="">� ?p????te ap????? �</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?= $dept['id'] ?>" <?= ($item['department_id'] ?? post('department_id')) == $dept['id'] ? 'selected' : '' ?>>
                                <?= h($dept['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="form-text text-muted">Se p??a ap????? a???e? a?t? t? ?????.</small>
                </div>
            </div>
            <?php endif; ?>

            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">?at?stas? ??????</h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label for="condition_notes" class="form-label">S?�e??se?? ?at?stas??</label>
                        <textarea class="form-control" id="condition_notes" name="condition_notes" rows="3"
                        ><?= h($item['condition_notes'] ?? post('condition_notes')) ?></textarea>
                    </div>
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-body d-grid gap-2">
                    <button type="submit" class="btn btn-primary btn-lg">
                        <i class="bi bi-check-lg me-1"></i>
                        <?= $isEdit ? '?p????e?s? ???a???' : '??�??????a ??????' ?>
                    </button>
                    <a href="<?= $isEdit ? 'inventory-view.php?id=' . $id : 'inventory.php' ?>" class="btn btn-outline-secondary">
                        ?????s?
                    </a>
                </div>
            </div>

            <?php if ($isEdit): ?>
            <div class="card border-danger">
                <div class="card-header bg-danger text-white">
                    <h5 class="mb-0">?p????d??? ????</h5>
                </div>
                <div class="card-body">
                    <p class="text-muted small">? d?a??af? ?????? e??a? �? a?ast????�?.</p>
                    <button type="button" class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#deleteModal">
                        <i class="bi bi-trash me-1"></i>??a??af? ??????
                    </button>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</form>

<?php if ($isEdit): ?>
<!-- Delete Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title">?p?�e�a??s? ??a??af??</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>??ste s??????? ?t? ???ete ?a d?a????ete t? ????? <strong><?= h($item['name']) ?></strong> (<?= h($item['barcode']) ?>);</p>
                    <?php
                    $activeBookings = dbFetchValue("SELECT COUNT(*) FROM inventory_bookings WHERE item_id = ? AND status = 'active'", [$id]);
                    if ($activeBookings > 0):
                    ?>
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle me-1"></i>
                            ??t? t? ????? ??e? <?= $activeBookings ?> e?e???/?? ????s?/e??!
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">?????s?</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-trash me-1"></i>??a??af?
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Auto-generate barcode button
    const btnGenerate = document.getElementById('btnGenerateBarcode');
    if (btnGenerate) {
        btnGenerate.addEventListener('click', function() {
            // Simple client-side generation (server will validate)
            const prefix = 'INV';
            const random = Math.floor(Math.random() * 999999).toString().padStart(6, '0');
            document.getElementById('barcode').value = prefix + random;
        });
    }
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
