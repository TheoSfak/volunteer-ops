<?php
/**
 * VolunteerOps - Inventory Kits List
 * Λίστα με τα Σετ Εξοπλισμού
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/inventory-functions.php';
requireLogin();
requireInventoryTables();
if (isTraineeRescuer()) {
    setFlash('error', 'Δεν έχετε πρόσβαση σε αυτή τη σελίδα.');
    redirect('dashboard.php');
}

$pageTitle = 'Σετ Εξοπλισμού';

// Handle Delete
if (isPost() && post('action') === 'delete') {
    verifyCsrf();
    if (!isAdmin()) {
        setFlash('error', 'Δεν έχετε δικαίωμα διαγραφής σετ.');
        redirect('inventory-kits.php');
    }
    
    $id = (int)post('id');
    $res = deleteInventoryKit($id);
    if ($res['success']) {
        setFlash('success', 'Το σετ διαγράφηκε επιτυχώς.');
    } else {
        setFlash('error', $res['error']);
    }
    redirect('inventory-kits.php');
}

// Fetch all kits with item counts
$kits = dbFetchAll("
    SELECT k.*, d.name as department_name, u.name as creator_name,
           (SELECT COUNT(*) FROM inventory_kit_items WHERE kit_id = k.id) as item_count
    FROM inventory_kits k
    LEFT JOIN departments d ON k.department_id = d.id
    LEFT JOIN users u ON k.created_by = u.id
    ORDER BY k.name
");

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
    <h1 class="h3 mb-0">
        <i class="bi bi-briefcase me-2"></i><?= h($pageTitle) ?>
    </h1>
    <?php if (isAdmin()): ?>
    <a href="inventory-kit-form.php" class="btn btn-primary">
        <i class="bi bi-plus-lg me-1"></i>Νέο Σετ
    </a>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 admin-mobile-cards">
                <thead class="table-light">
                    <tr>
                        <th>Όνομα Σετ</th>
                        <th>Barcode</th>
                        <th>Τμήμα</th>
                        <th>Πλήθος Υλικών</th>
                        <th>Δημιουργός</th>
                        <th class="text-end">Ενέργειες</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($kits)): ?>
                    <tr>
                        <td colspan="6" class="text-center py-4 text-muted">
                            Δεν βρέθηκαν σετ εξοπλισμού.
                        </td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($kits as $k): ?>
                        <tr>
                            <td data-label="Όνομα Σετ">
                                <strong><?= h($k['name']) ?></strong>
                                <?php if ($k['description']): ?>
                                    <br><small class="text-muted"><?= h($k['description']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td data-label="Barcode"><span class="badge bg-secondary font-monospace"><?= h($k['barcode']) ?></span></td>
                            <td data-label="Τμήμα"><?= h($k['department_name'] ?: 'Όλα τα Τμήματα') ?></td>
                            <td data-label="Πλήθος Υλικών">
                                <span class="badge bg-info text-dark"><?= $k['item_count'] ?> υλικά</span>
                            </td>
                            <td data-label="Δημιουργός"><small class="text-muted"><?= h($k['creator_name']) ?></small></td>
                            <td data-label="Ενέργειες" class="text-end mobile-card-actions">
                                <a href="inventory-book.php?kit_id=<?= $k['id'] ?>" class="btn btn-sm btn-success" title="Χρέωση Σετ">
                                    <i class="bi bi-upc-scan"></i>
                                </a>
                                <?php if (isAdmin()): ?>
                                <a href="inventory-label.php?kit_id=<?= $k['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Εκτύπωση Ετικέτας" target="_blank">
                                    <i class="bi bi-printer"></i>
                                </a>
                                <a href="inventory-kit-form.php?id=<?= $k['id'] ?>" class="btn btn-sm btn-outline-primary" title="Επεξεργασία">
                                    <i class="bi bi-pencil"></i>
                                </a>
                                <button type="button" class="btn btn-sm btn-outline-danger" 
                                        data-bs-toggle="modal" data-bs-target="#deleteModal-<?= $k['id'] ?>" title="Διαγραφή">
                                    <i class="bi bi-trash"></i>
                                </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        
                        <!-- Delete Modal -->
                        <?php if (isAdmin()): ?>
                        <div class="modal fade" id="deleteModal-<?= $k['id'] ?>" tabindex="-1">
                            <div class="modal-dialog">
                                <div class="modal-content">
                                    <div class="modal-header bg-danger text-white">
                                        <h5 class="modal-title"><i class="bi bi-exclamation-triangle-fill me-2"></i>Διαγραφή Σετ</h5>
                                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                        Είστε σίγουροι ότι θέλετε να διαγράψετε το σετ <strong><?= h($k['name']) ?></strong>;
                                        <br><br>
                                        <small class="text-muted">Τα υλικά που περιέχει <strong>δεν</strong> θα διαγραφούν από την αποθήκη.</small>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Ακύρωση</button>
                                        <form method="post" class="d-inline">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= $k['id'] ?>">
                                            <button type="submit" class="btn btn-danger">Διαγραφή</button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<style>
@media (max-width: 767.98px) {
    .admin-mobile-cards thead { display: none; }
    .admin-mobile-cards, .admin-mobile-cards tbody, .admin-mobile-cards tr, .admin-mobile-cards td { display: block; width: 100%; }
    .admin-mobile-cards tr:not(:has(td[colspan])) { margin: .75rem; width: calc(100% - 1.5rem); padding: .75rem; border: 1px solid var(--bs-border-color); border-radius: .75rem; background: var(--bs-body-bg); }
    .admin-mobile-cards tr:not(:has(td[colspan])) td { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; padding: .45rem 0; border: 0; text-align: right !important; }
    .admin-mobile-cards tr:not(:has(td[colspan])) td::before { content: attr(data-label); flex: 0 0 38%; color: var(--bs-secondary-color); font-weight: 600; text-align: left; }
    .admin-mobile-cards .mobile-card-actions { align-items: center; flex-wrap: wrap; padding-top: .75rem !important; border-top: 1px solid var(--bs-border-color) !important; }
}
</style>

<?php include __DIR__ . '/includes/footer.php'; ?>
