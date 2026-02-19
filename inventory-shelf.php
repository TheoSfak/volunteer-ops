<?php
/**
 * VolunteerOps - Υλικά Ραφιού (Shelf Materials)
 * Excel-style inline editing of consumable shelf items with expiry tracking.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/inventory-functions.php';
requireLogin();
requireInventoryTables();

if (!canManageInventory()) {
    setFlash('error', 'Δεν έχετε δικαίωμα πρόσβασης.');
    redirect('inventory.php');
}

$pageTitle = 'Υλικά Ραφιού';
$user = getCurrentUser();

// =============================================
// Ensure table exists (auto-create if missing)
// =============================================
try {
    dbFetchValue("SELECT 1 FROM inventory_shelf_items LIMIT 1");
} catch (\PDOException $e) {
    // Create table on-the-fly if not exists
    $pdo = db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS `inventory_shelf_items` (
        `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `name` VARCHAR(255) NOT NULL,
        `quantity` INT NOT NULL DEFAULT 1,
        `shelf` VARCHAR(100) NULL,
        `expiry_date` DATE NULL,
        `notes` TEXT NULL,
        `department_id` INT UNSIGNED NULL,
        `sort_order` INT DEFAULT 0,
        `created_by` INT UNSIGNED NULL,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (`department_id`) REFERENCES `departments`(`id`) ON DELETE SET NULL,
        FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
        INDEX `idx_expiry` (`expiry_date`),
        INDEX `idx_shelf` (`shelf`),
        INDEX `idx_department` (`department_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

// =============================================
// Handle POST actions
// =============================================
if (isPost()) {
    verifyCsrf();
    $action = post('action');
    
    switch ($action) {
        case 'add':
            $name = trim(post('name'));
            $quantity = (int) post('quantity', 1);
            $shelf = trim(post('shelf'));
            $expiryDate = post('expiry_date') ?: null;
            $notes = trim(post('notes'));
            $deptId = post('department_id') ?: null;
            
            if (empty($name)) {
                setFlash('error', 'Το όνομα είναι υποχρεωτικό.');
                redirect('inventory-shelf.php');
            }
            
            // Get max sort_order
            $maxSort = (int) dbFetchValue("SELECT COALESCE(MAX(sort_order), 0) FROM inventory_shelf_items");
            
            dbInsert(
                "INSERT INTO inventory_shelf_items (name, quantity, shelf, expiry_date, notes, department_id, sort_order, created_by) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                [$name, $quantity, $shelf ?: null, $expiryDate, $notes ?: null, $deptId, $maxSort + 1, getCurrentUserId()]
            );
            setFlash('success', 'Το υλικό προστέθηκε επιτυχώς.');
            redirect('inventory-shelf.php');
            break;
            
        case 'add_after':
            // Add new row after a specific item
            $afterId = (int) post('after_id');
            $afterSort = (int) dbFetchValue("SELECT sort_order FROM inventory_shelf_items WHERE id = ?", [$afterId]);
            
            // Shift all items after this one
            dbExecute("UPDATE inventory_shelf_items SET sort_order = sort_order + 2 WHERE sort_order > ?", [$afterSort]);
            
            dbInsert(
                "INSERT INTO inventory_shelf_items (name, quantity, shelf, sort_order, created_by) VALUES (?, 1, ?, ?, ?)",
                ['Νέο υλικό', null, $afterSort + 1, getCurrentUserId()]
            );
            $newId = dbFetchValue("SELECT LAST_INSERT_ID()");
            setFlash('success', 'Προστέθηκε νέα γραμμή. Επεξεργαστείτε τα στοιχεία.');
            redirect('inventory-shelf.php#row-' . $newId);
            break;
            
        case 'edit':
            $id = (int) post('id');
            $name = trim(post('name'));
            $quantity = (int) post('quantity', 1);
            $shelf = trim(post('shelf'));
            $expiryDate = post('expiry_date') ?: null;
            $notes = trim(post('notes'));
            $deptId = post('department_id') ?: null;
            
            if (empty($name)) {
                setFlash('error', 'Το όνομα είναι υποχρεωτικό.');
                redirect('inventory-shelf.php');
            }
            
            dbExecute(
                "UPDATE inventory_shelf_items SET name = ?, quantity = ?, shelf = ?, expiry_date = ?, notes = ?, department_id = ? WHERE id = ?",
                [$name, $quantity, $shelf ?: null, $expiryDate, $notes ?: null, $deptId, $id]
            );
            setFlash('success', 'Το υλικό ενημερώθηκε.');
            redirect('inventory-shelf.php');
            break;
            
        case 'delete':
            $id = (int) post('id');
            $item = dbFetchOne("SELECT name FROM inventory_shelf_items WHERE id = ?", [$id]);
            if ($item) {
                dbExecute("DELETE FROM inventory_shelf_items WHERE id = ?", [$id]);
                setFlash('success', 'Το υλικό "' . $item['name'] . '" διαγράφηκε.');
            }
            redirect('inventory-shelf.php');
            break;
    }
}

// =============================================
// Fetch data
// =============================================
$editId = get('edit') ? (int) get('edit') : null;

// Get all shelf items ordered
$items = dbFetchAll("
    SELECT si.*, d.name AS dept_name, u.name AS creator_name
    FROM inventory_shelf_items si
    LEFT JOIN departments d ON si.department_id = d.id
    LEFT JOIN users u ON si.created_by = u.id
    ORDER BY si.sort_order ASC, si.id ASC
");

// Get departments for dropdown
$departments = dbFetchAll("SELECT id, name FROM departments WHERE has_inventory = 1 ORDER BY name");

// Calculate expiry status for each item
$today = new DateTime();
foreach ($items as &$item) {
    $item['expiry_class'] = '';
    $item['expiry_label'] = '';
    if (!empty($item['expiry_date'])) {
        $expiry = new DateTime($item['expiry_date']);
        $diff = $today->diff($expiry);
        $totalDays = (int) $diff->format('%r%a'); // negative if expired
        
        if ($totalDays < 0) {
            $item['expiry_class'] = 'danger';
            $item['expiry_label'] = 'Έληξε';
        } elseif ($totalDays <= 90) {
            $item['expiry_class'] = 'warning';
            $months = round($totalDays / 30);
            $item['expiry_label'] = $months <= 1 ? 'Λιγότερο από 1 μήνα' : "{$months} μήνες";
        } elseif ($totalDays <= 180) {
            $item['expiry_class'] = 'success';
            $months = round($totalDays / 30);
            $item['expiry_label'] = "{$months} μήνες";
        } else {
            $item['expiry_class'] = 'success';
            $months = round($totalDays / 30);
            $item['expiry_label'] = "{$months} μήνες";
        }
    }
}
unset($item);

// Stats
$totalItems = count($items);
$expiredCount = count(array_filter($items, fn($i) => $i['expiry_class'] === 'danger'));
$warningCount = count(array_filter($items, fn($i) => $i['expiry_class'] === 'warning'));

include __DIR__ . '/includes/header.php';
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0"><i class="bi bi-grid-3x3 me-2"></i>Υλικά Ραφιού</h1>
        <div>
            <span class="badge bg-secondary me-2"><?= $totalItems ?> υλικά</span>
            <?php if ($expiredCount > 0): ?>
                <span class="badge bg-danger me-2"><?= $expiredCount ?> ληγμένα</span>
            <?php endif; ?>
            <?php if ($warningCount > 0): ?>
                <span class="badge bg-warning text-dark"><?= $warningCount ?> κοντά σε λήξη</span>
            <?php endif; ?>
        </div>
    </div>
    
    <?php displayFlash(); ?>
    
    <!-- Legend -->
    <div class="mb-3 d-flex gap-3 align-items-center small text-muted">
        <span><i class="bi bi-circle-fill text-success"></i> > 6 μήνες</span>
        <span><i class="bi bi-circle-fill text-warning"></i> 3-6 μήνες</span>
        <span><i class="bi bi-circle-fill text-danger"></i> Έληξε / < 3 μήνες</span>
    </div>
    
    <!-- Items Table -->
    <div class="card shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover table-bordered mb-0 align-middle" id="shelfTable">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 30px">#</th>
                            <th>Όνομα</th>
                            <th style="width: 90px" class="text-center">Ποσότητα</th>
                            <th style="width: 150px">Ράφι</th>
                            <th style="width: 160px">Ημερομηνία Λήξης</th>
                            <th style="width: 40px" class="text-center">Κατάσταση</th>
                            <th style="width: 130px" class="text-center">Ενέργειες</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($items)): ?>
                            <tr>
                                <td colspan="7" class="text-center text-muted py-4">
                                    <i class="bi bi-inbox fs-2 d-block mb-2"></i>
                                    Δεν υπάρχουν υλικά ραφιού. Προσθέστε το πρώτο παρακάτω.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php $row = 0; foreach ($items as $item): $row++; ?>
                                <?php if ($editId === (int)$item['id']): ?>
                                    <!-- EDIT ROW -->
                                    <tr class="table-info" id="row-<?= $item['id'] ?>">
                                        <td class="text-muted"><?= $row ?></td>
                                        <td colspan="6">
                                            <form method="post" class="row g-2 align-items-end">
                                                <?= csrfField() ?>
                                                <input type="hidden" name="action" value="edit">
                                                <input type="hidden" name="id" value="<?= $item['id'] ?>">
                                                <div class="col-md-3">
                                                    <label class="form-label small mb-0">Όνομα</label>
                                                    <input type="text" name="name" class="form-control form-control-sm" value="<?= h($item['name']) ?>" required>
                                                </div>
                                                <div class="col-md-1">
                                                    <label class="form-label small mb-0">Ποσότ.</label>
                                                    <input type="number" name="quantity" class="form-control form-control-sm" value="<?= $item['quantity'] ?>" min="0" required>
                                                </div>
                                                <div class="col-md-2">
                                                    <label class="form-label small mb-0">Ράφι</label>
                                                    <input type="text" name="shelf" class="form-control form-control-sm" value="<?= h($item['shelf'] ?? '') ?>">
                                                </div>
                                                <div class="col-md-2">
                                                    <label class="form-label small mb-0">Ημ. Λήξης</label>
                                                    <input type="date" name="expiry_date" class="form-control form-control-sm" value="<?= $item['expiry_date'] ?? '' ?>">
                                                </div>
                                                <div class="col-md-2">
                                                    <label class="form-label small mb-0">Σημειώσεις</label>
                                                    <input type="text" name="notes" class="form-control form-control-sm" value="<?= h($item['notes'] ?? '') ?>">
                                                </div>
                                                <input type="hidden" name="department_id" value="<?= $item['department_id'] ?? '' ?>">
                                                <div class="col-md-2">
                                                    <button type="submit" class="btn btn-success btn-sm"><i class="bi bi-check-lg"></i> Αποθήκευση</button>
                                                    <a href="inventory-shelf.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x"></i></a>
                                                </div>
                                            </form>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <!-- VIEW ROW -->
                                    <tr id="row-<?= $item['id'] ?>">
                                        <td class="text-muted small"><?= $row ?></td>
                                        <td>
                                            <strong><?= h($item['name']) ?></strong>
                                            <?php if (!empty($item['notes'])): ?>
                                                <small class="text-muted d-block"><?= h($item['notes']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-secondary rounded-pill"><?= $item['quantity'] ?></span>
                                        </td>
                                        <td>
                                            <?php if (!empty($item['shelf'])): ?>
                                                <i class="bi bi-bookshelf me-1"></i><?= h($item['shelf']) ?>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($item['expiry_date'])): ?>
                                                <?= formatDate($item['expiry_date']) ?>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <?php if (!empty($item['expiry_class'])): ?>
                                                <i class="bi bi-circle-fill text-<?= $item['expiry_class'] ?>" 
                                                   title="<?= h($item['expiry_label']) ?>"
                                                   data-bs-toggle="tooltip"></i>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <div class="btn-group btn-group-sm">
                                                <a href="inventory-shelf.php?edit=<?= $item['id'] ?>#row-<?= $item['id'] ?>" 
                                                   class="btn btn-outline-primary" title="Επεξεργασία">
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                                <button type="button" class="btn btn-outline-danger" 
                                                        data-bs-toggle="modal" data-bs-target="#deleteModal" 
                                                        data-id="<?= $item['id'] ?>" data-name="<?= h($item['name']) ?>">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <!-- Add New Item Form -->
    <div class="card shadow-sm mt-4">
        <div class="card-header bg-primary text-white">
            <i class="bi bi-plus-circle me-2"></i>Προσθήκη Νέου Υλικού
        </div>
        <div class="card-body">
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="add">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Όνομα <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" required placeholder="π.χ. Γάζες αποστειρωμένες">
                    </div>
                    <div class="col-md-1">
                        <label class="form-label">Ποσότητα</label>
                        <input type="number" name="quantity" class="form-control" value="1" min="0">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Ράφι</label>
                        <input type="text" name="shelf" class="form-control" placeholder="π.χ. Α1">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Ημ. Λήξης</label>
                        <input type="date" name="expiry_date" class="form-control">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Σημειώσεις</label>
                        <input type="text" name="notes" class="form-control" placeholder="Προαιρετικά">
                    </div>
                    <?php if (!empty($departments)): ?>
                    <div class="col-md-2">
                        <label class="form-label">Παράρτημα</label>
                        <select name="department_id" class="form-select">
                            <option value="">—</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?= $dept['id'] ?>"><?= h($dept['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="mt-3">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-plus-circle me-1"></i>Προσθήκη
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" id="deleteId">
                <div class="modal-header bg-danger text-white">
                    <h5 class="modal-title"><i class="bi bi-trash me-2"></i>Διαγραφή Υλικού</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Είστε σίγουροι ότι θέλετε να διαγράψετε το υλικό <strong id="deleteName"></strong>;</p>
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
// Delete modal - populate data
document.getElementById('deleteModal')?.addEventListener('show.bs.modal', function(event) {
    const btn = event.relatedTarget;
    document.getElementById('deleteId').value = btn.dataset.id;
    document.getElementById('deleteName').textContent = btn.dataset.name;
});

// Initialize tooltips
document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => new bootstrap.Tooltip(el));
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
