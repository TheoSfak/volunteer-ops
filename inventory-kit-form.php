<?php
/**
 * VolunteerOps - Inventory Kit Form
 * Φόρμα δημιουργίας/επεξεργασίας Σετ Εξοπλισμού
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/inventory-functions.php';
requireLogin();
requireInventoryTables();
if (!isAdmin()) {
    setFlash('error', 'Δεν έχετε πρόσβαση σε αυτή τη σελίδα.');
    redirect('inventory-kits.php');
}

$id = (int)get('id', 0);
$isEdit = $id > 0;
$kit = null;
$selectedItemIds = [];

if ($isEdit) {
    $kit = getInventoryKit($id);
    if (!$kit) {
        setFlash('error', 'Το σετ δεν βρέθηκε.');
        redirect('inventory-kits.php');
    }
    $selectedItemIds = array_column($kit['items'], 'id');
}

$pageTitle = $isEdit ? 'Επεξεργασία Σετ: ' . $kit['name'] : 'Νέο Σετ Εξοπλισμού';

// Handle POST
if (isPost()) {
    verifyCsrf();
    
    $data = [
        'barcode'       => trim(post('barcode')),
        'name'          => trim(post('name')),
        'description'   => trim(post('description')),
        'department_id' => post('department_id') ? (int)post('department_id') : null,
    ];
    
    $itemIds = post('item_ids', []);
    if (!is_array($itemIds)) $itemIds = [];
    $itemIds = array_map('intval', $itemIds);
    
    // Validation
    $errors = [];
    if (empty($data['barcode'])) $errors[] = 'Το Barcode είναι υποχρεωτικό.';
    if (empty($data['name'])) $errors[] = 'Το Όνομα είναι υποχρεωτικό.';
    if (empty($itemIds)) $errors[] = 'Πρέπει να επιλέξετε τουλάχιστον ένα υλικό για το σετ.';
    
    // Check barcode uniqueness
    $existing = getInventoryKitByBarcode($data['barcode']);
    if ($existing && (!$isEdit || $existing['id'] != $id)) {
        $errors[] = 'Το Barcode χρησιμοποιείται ήδη σε άλλο σετ.';
    }
    
    // Check if barcode is used by a single item
    $existingItem = getInventoryItemByBarcode($data['barcode']);
    if ($existingItem) {
        $errors[] = 'Το Barcode χρησιμοποιείται ήδη από το μεμονωμένο υλικό: ' . $existingItem['name'];
    }
    
    if (empty($errors)) {
        if ($isEdit) {
            $res = updateInventoryKit($id, $data, $itemIds);
            $msg = 'Το σετ ενημερώθηκε επιτυχώς.';
        } else {
            $res = createInventoryKit($data, $itemIds);
            $msg = 'Το σετ δημιουργήθηκε επιτυχώς.';
        }
        
        if ($res['success']) {
            setFlash('success', $msg);
            redirect('inventory-kits.php');
        } else {
            $errors[] = $res['error'];
        }
    }
    
    if (!empty($errors)) {
        setFlash('error', implode('<br>', $errors));
        // Restore form state
        $kit = $data;
        $selectedItemIds = $itemIds;
    }
}

// Get available items for selection
$items = dbFetchAll("
    SELECT i.id, i.barcode, i.name, c.icon as category_icon, d.name as department_name
    FROM inventory_items i
    LEFT JOIN inventory_categories c ON i.category_id = c.id
    LEFT JOIN departments d ON i.department_id = d.id
    WHERE i.is_active = 1
    ORDER BY i.name
");

// Get departments
$departments = getInventoryDepartments();

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">
        <i class="bi bi-briefcase me-2"></i><?= h($pageTitle) ?>
    </h1>
    <a href="inventory-kits.php" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Πίσω
    </a>
</div>

<div class="row">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-body">
                <form method="post">
                    <?= csrfField() ?>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Όνομα Σετ <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control" required
                                   value="<?= h($kit['name'] ?? '') ?>" placeholder="π.χ. Σακίδιο Πρώτων Βοηθειών #1">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Barcode / QR <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="bi bi-upc-scan"></i></span>
                                <input type="text" name="barcode" class="form-control font-monospace" required
                                       value="<?= h($kit['barcode'] ?? '') ?>" placeholder="Σαρώστε ή πληκτρολογήστε">
                            </div>
                            <div class="form-text">Πρέπει να είναι μοναδικό σε όλο το σύστημα.</div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Περιγραφή</label>
                        <textarea name="description" class="form-control" rows="2"
                                  placeholder="Προαιρετική περιγραφή του σετ..."><?= h($kit['description'] ?? '') ?></textarea>
                    </div>
                    
                    <div class="mb-4">
                        <label class="form-label">Τμήμα</label>
                        <select name="department_id" class="form-select">
                            <option value="">-- Όλα τα Τμήματα (Κοινόχρηστο) --</option>
                            <?php foreach ($departments as $d): ?>
                                <option value="<?= $d['id'] ?>" <?= ($kit['department_id'] ?? '') == $d['id'] ? 'selected' : '' ?>>
                                    <?= h($d['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Αν επιλέξετε τμήμα, μόνο τα μέλη του θα μπορούν να το χρεωθούν.</div>
                    </div>
                    
                    <hr>
                    
                    <h5 class="mb-3"><i class="bi bi-box-seam me-2"></i>Περιεχόμενα Σετ <span class="text-danger">*</span></h5>
                    <p class="text-muted small mb-3">Επιλέξτε τα υλικά που περιλαμβάνονται μόνιμα σε αυτό το σετ. Όταν χρεώνεται το σετ, θα χρεώνονται αυτόματα όλα τα επιλεγμένα υλικά.</p>
                    
                    <div class="mb-3">
                        <input type="text" id="itemSearch" class="form-control form-control-sm mb-2" placeholder="Αναζήτηση υλικών...">
                        <div class="border rounded p-2" style="max-height: 400px; overflow-y: auto;">
                            <?php foreach ($items as $item): ?>
                            <div class="form-check item-row mb-2 pb-2 border-bottom">
                                <input class="form-check-input" type="checkbox" name="item_ids[]" 
                                       value="<?= $item['id'] ?>" id="item_<?= $item['id'] ?>"
                                       <?= in_array($item['id'], $selectedItemIds) ? 'checked' : '' ?>>
                                <label class="form-check-label w-100" for="item_<?= $item['id'] ?>">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <?= $item['category_icon'] ?? '📦' ?> 
                                            <strong><?= h($item['name']) ?></strong>
                                            <br>
                                            <small class="text-muted font-monospace"><?= h($item['barcode']) ?></small>
                                        </div>
                                        <?php if ($item['department_name']): ?>
                                            <span class="badge bg-secondary" style="font-size: 0.7rem;"><?= h($item['department_name']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <div class="d-flex justify-content-between mt-4">
                        <a href="inventory-kits.php" class="btn btn-secondary">Ακύρωση</a>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-save me-1"></i>Αποθήκευση Σετ
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <div class="col-lg-4">
        <div class="card bg-light">
            <div class="card-body">
                <h6><i class="bi bi-info-circle me-2"></i>Πληροφορίες</h6>
                <p class="small text-muted">
                    Τα <strong>Σετ Εξοπλισμού</strong> (Kits) σας επιτρέπουν να ομαδοποιήσετε πολλά υλικά κάτω από ένα κοινό Barcode.
                </p>
                <p class="small text-muted">
                    Για παράδειγμα, ένα "Σακίδιο Α' Βοηθειών" μπορεί να περιέχει ένα πιεσόμετρο, ένα οξύμετρο και έναν ασύρματο.
                </p>
                <p class="small text-muted mb-0">
                    Όταν σκανάρετε το barcode του σετ στη σελίδα Χρέωσης, το σύστημα θα χρεώσει αυτόματα όλα τα περιεχόμενα υλικά στον εθελοντή με μία κίνηση.
                </p>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('itemSearch').addEventListener('input', function(e) {
    const term = e.target.value.toLowerCase();
    document.querySelectorAll('.item-row').forEach(row => {
        const text = row.textContent.toLowerCase();
        row.style.display = text.includes(term) ? '' : 'none';
    });
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>