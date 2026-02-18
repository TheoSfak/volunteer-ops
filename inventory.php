<?php
/**
 * VolunteerOps - Inventory List
 * Main inventory page with search, filters, and pagination.
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();

$user = getCurrentUser();

// Handle department filter change (via GET or POST)
if (isPost() && post('action') === 'set_department_filter') {
    verifyCsrf();
    $_SESSION['inventory_department_filter'] = post('dept_filter', 'all');
    redirect('inventory.php');
}
// Support ?dept=ID from warehouse page links
if (get('dept')) {
    $_SESSION['inventory_department_filter'] = get('dept');
    redirect('inventory.php');
}

// Filters
$status     = get('status');
$categoryId = (int)get('category_id', 0);
$search     = get('search');
$page       = max(1, (int)get('page', 1));
$perPage    = 20;

$pageTitle = 'Υλικά & Εξοπλισμός';

// Build filters array
$filters = [];
if ($status)     $filters['status']      = $status;
if ($categoryId) $filters['category_id'] = $categoryId;
if ($search)     $filters['search']      = $search;

// Count & paginate
$total      = countInventoryItems($filters);
$pagination = paginate($total, $page, $perPage);

// Fetch items
$items = getInventoryItems($filters, $pagination['per_page'], $pagination['offset']);

// Data for filters
$categories  = getInventoryCategories();
$departments = getInventoryDepartments();
$stats       = getInventoryStats();

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">
        <i class="bi bi-box-seam me-2"></i><?= h($pageTitle) ?>
    </h1>
    <div class="d-flex gap-2">
        <?php if (canManageInventory()): ?>
            <a href="inventory-form.php" class="btn btn-primary">
                <i class="bi bi-plus-lg me-1"></i>Νέο Υλικό
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- Stats Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3 col-xl">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center py-3">
                <div class="fs-2 fw-bold text-dark"><?= $stats['total'] ?></div>
                <small class="text-muted">Σύνολο</small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-xl">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center py-3">
                <div class="fs-2 fw-bold text-success"><?= $stats['available'] ?></div>
                <small class="text-muted">Διαθέσιμα</small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-xl">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center py-3">
                <div class="fs-2 fw-bold text-primary"><?= $stats['booked'] ?></div>
                <small class="text-muted">Χρεωμένα</small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-xl">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center py-3">
                <div class="fs-2 fw-bold text-warning"><?= $stats['maintenance'] ?></div>
                <small class="text-muted">Συντήρηση</small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-xl">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center py-3">
                <div class="fs-2 fw-bold text-danger"><?= $stats['damaged'] ?></div>
                <small class="text-muted">Χαλασμένα</small>
            </div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body">
        <form method="get" class="row g-3">
            <div class="col-md-3">
                <input type="text" class="form-control" name="search" placeholder="Αναζήτηση (barcode, όνομα)..." 
                       value="<?= h($search) ?>">
            </div>
            <div class="col-md-2">
                <select class="form-select" name="status">
                    <option value="">Όλες οι καταστάσεις</option>
                    <?php foreach (INVENTORY_STATUS_LABELS as $key => $label): ?>
                        <option value="<?= $key ?>" <?= $status === $key ? 'selected' : '' ?>>
                            <?= h($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <select class="form-select" name="category_id">
                    <option value="">Όλες οι κατηγορίες</option>
                    <?php foreach ($categories as $cat): ?>
                        <option value="<?= $cat['id'] ?>" <?= $categoryId == $cat['id'] ? 'selected' : '' ?>>
                            <?= $cat['icon'] ?> <?= h($cat['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-outline-primary w-100">
                    <i class="bi bi-search me-1"></i>Φίλτρο
                </button>
            </div>
            <div class="col-md-2">
                <a href="inventory.php" class="btn btn-outline-secondary w-100">Καθαρισμός</a>
            </div>
        </form>
    </div>
</div>

<?php if (isAdmin() && count($departments) > 0): ?>
<!-- Warehouse filter -->
<div class="card mb-4">
    <div class="card-body py-2">
        <form method="post" class="d-flex align-items-center gap-3">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="set_department_filter">
            <label class="form-label mb-0 fw-bold"><i class="bi bi-building me-1"></i>Αποθήκη:</label>
            <select class="form-select form-select-sm" name="dept_filter" style="max-width: 250px;" onchange="this.form.submit()">
                <option value="all" <?= getCurrentInventoryDepartment() === 'all' ? 'selected' : '' ?>>Όλες οι αποθήκες</option>
                <?php foreach ($departments as $dept): ?>
                    <option value="<?= $dept['id'] ?>" <?= getCurrentInventoryDepartment() == $dept['id'] ? 'selected' : '' ?>>
                        <?= h($dept['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <?php if (isSystemAdmin()): ?>
                <a href="inventory-warehouses.php" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-gear me-1"></i>Διαχείριση
                </a>
            <?php endif; ?>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- Results -->
<div class="card">
    <div class="card-body p-0">
        <?php if (empty($items)): ?>
            <div class="text-center py-5">
                <i class="bi bi-box-seam text-muted" style="font-size: 3rem;"></i>
                <p class="text-muted mt-3">Δεν βρέθηκαν υλικά.</p>
                <?php if (canManageInventory()): ?>
                    <a href="inventory-form.php" class="btn btn-primary">
                        <i class="bi bi-plus-lg me-1"></i>Προσθήκη Υλικού
                    </a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Barcode</th>
                            <th>Όνομα</th>
                            <th>Κατηγορία</th>
                            <th>Τοποθεσία</th>
                            <?php if (isSystemAdmin()): ?>
                                <th>Τμήμα</th>
                            <?php endif; ?>
                            <th>Κατάσταση</th>
                            <th>Χρεωμένο σε</th>
                            <th class="text-end">Ενέργειες</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                            <tr>
                                <td>
                                    <code class="text-primary"><?= h($item['barcode']) ?></code>
                                </td>
                                <td>
                                    <a href="inventory-view.php?id=<?= $item['id'] ?>" class="text-decoration-none fw-medium">
                                        <?= h($item['name']) ?>
                                    </a>
                                    <?php if (!empty($item['open_notes_count']) && $item['open_notes_count'] > 0): ?>
                                        <a href="inventory-view.php?id=<?= $item['id'] ?>#notes" class="text-decoration-none ms-1"
                                           title="<?= $item['open_notes_count'] ?> ανοιχτό/ά σχόλιο/α" data-bs-toggle="tooltip">
                                            <span class="badge bg-warning text-dark">
                                                <i class="bi bi-exclamation-triangle-fill me-1"></i>ΣΧΟΛΙΟ!
                                            </span>
                                        </a>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($item['category_name']): ?>
                                        <span class="badge" style="background-color: <?= h($item['category_color']) ?>">
                                            <?= $item['category_icon'] ?> <?= h($item['category_name']) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= h($item['location_name'] ?? '-') ?></td>
                                <?php if (isSystemAdmin()): ?>
                                    <td><?= h($item['dept_name'] ?? 'Γενικό') ?></td>
                                <?php endif; ?>
                                <td><?= inventoryStatusBadge($item['status']) ?></td>
                                <td>
                                    <?php if ($item['booked_by_name']): ?>
                                        <?php
                                        $overdueInfo = calculateOverdueStatus(
                                            $item['booking_date'],
                                            $item['expected_return_date'] ?? null
                                        );
                                        ?>
                                        <small>
                                            <i class="bi bi-person<?= $overdueInfo['is_overdue'] ? '-fill' : '' ?>"></i>
                                            <span class="<?= $overdueInfo['is_overdue'] ? 'text-danger fw-bold' : '' ?>">
                                                <?= h($item['booked_by_name']) ?>
                                            </span>
                                            <br>
                                            <span class="text-muted"><?= formatDate($item['booking_date']) ?></span>
                                            <span class="badge bg-<?= $overdueInfo['status_class'] ?> ms-1" style="font-size: 0.65em;">
                                                <?= h($overdueInfo['status_label']) ?>
                                            </span>
                                        </small>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <div class="btn-group btn-group-sm">
                                        <a href="inventory-view.php?id=<?= $item['id'] ?>" class="btn btn-outline-primary" title="Προβολή">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        <?php if ($item['status'] === 'available'): ?>
                                            <a href="inventory-book.php?item_id=<?= $item['id'] ?>" class="btn btn-outline-success" title="Χρέωση">
                                                <i class="bi bi-box-arrow-right"></i>
                                            </a>
                                        <?php endif; ?>
                                        <?php if (canManageInventory()): ?>
                                            <a href="inventory-form.php?id=<?= $item['id'] ?>" class="btn btn-outline-secondary" title="Επεξεργασία">
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($pagination['total_pages'] > 1): ?>
        <div class="card-footer">
            <?= paginationLinks($pagination, '?status=' . urlencode($status ?? '') . '&category_id=' . urlencode($categoryId) . '&search=' . urlencode($search ?? '') . '&') ?>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
