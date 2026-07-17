<?php
/**
 * VolunteerOps - Inventory List
 * Main inventory page with search, filters, and pagination.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/inventory-functions.php';
requireLogin();
requireInventoryTables();
if (isTraineeRescuer()) {
    setFlash('error', 'Δεν έχετε πρόσβαση σε αυτή τη σελίδα.');
    redirect('dashboard.php');
}

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

<div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-3">
    <h1 class="h3 mb-0">
        <i class="bi bi-box-seam me-2"></i><?= h($pageTitle) ?>
    </h1>
    <div class="d-flex flex-wrap gap-2">
        <?php if (canManageInventory()): ?>
            <button type="button" class="btn btn-outline-primary" id="btnPrintSelected" style="display:none;" onclick="printSelected()" title="Εκτύπωση ετικετών για επιλεγμένα υλικά">
                <i class="bi bi-qr-code me-1"></i>Εκτύπωση επιλεγμένων (<span id="selectedCount">0</span>)
            </button>
        <?php endif; ?>
        <button type="button" class="btn btn-outline-secondary" onclick="printInventoryList()" title="Εκτύπωση λίστας υλικών">
            <i class="bi bi-printer me-1"></i>Εκτύπωση Λίστας
        </button>
        <?php if (canManageInventory()): ?>
            <a href="inventory-form.php" class="btn btn-primary">
                <i class="bi bi-plus-lg me-1"></i>Νέο Υλικό
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- Stats Cards -->
<div class="row g-2 mb-3 no-print">
    <div class="col-6 col-md-3 col-xl">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center py-2">
                <div class="fs-5 fw-bold text-dark"><?= $stats['total'] ?></div>
                <small class="text-muted" style="font-size:0.75rem;">Σύνολο</small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-xl">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center py-2">
                <div class="fs-5 fw-bold text-success"><?= $stats['available'] ?></div>
                <small class="text-muted" style="font-size:0.75rem;">Διαθέσιμα</small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-xl">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center py-2">
                <div class="fs-5 fw-bold text-primary"><?= $stats['booked'] ?></div>
                <small class="text-muted" style="font-size:0.75rem;">Χρεωμένα</small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-xl">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center py-2">
                <div class="fs-5 fw-bold text-warning"><?= $stats['maintenance'] ?></div>
                <small class="text-muted" style="font-size:0.75rem;">Συντήρηση</small>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-xl">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body text-center py-2">
                <div class="fs-5 fw-bold text-danger"><?= $stats['damaged'] ?></div>
                <small class="text-muted" style="font-size:0.75rem;">Χαλασμένα</small>
            </div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card mb-3 no-print">
    <div class="card-body py-2">
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
<div class="card mb-3 no-print">
    <div class="card-body py-2">
        <form method="post" class="d-flex align-items-center flex-wrap gap-3">
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
                <table class="table table-hover table-sm align-middle mb-0 admin-mobile-cards" style="font-size:0.85rem;">
                    <thead class="table-light">
                        <tr>
                            <?php if (canManageInventory()): ?>
                            <th style="width:36px;">
                                <input type="checkbox" class="form-check-input" id="chkAll" title="Επιλογή όλων">
                            </th>
                            <?php endif; ?>
                            <th>Barcode</th>
                            <th>Όνομα</th>
                            <th class="d-none d-xxl-table-cell">Εγγραφή</th>
                            <th class="d-none d-xxl-table-cell">Α.Μ</th>
                            <th>Κατηγορία</th>
                            <th>Τοποθεσία</th>
                            <?php if (isSystemAdmin()): ?>
                                <th>Τμήμα</th>
                            <?php endif; ?>
                            <th>Κατάσταση</th>
                            <th>Χρεωμένο σε</th>
                            <th class="text-end col-actions">Ενέργειες</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                            <tr>
                                <?php if (canManageInventory()): ?>
                                <td data-label="Επιλογή">
                                    <input type="checkbox" class="form-check-input item-chk" value="<?= $item['id'] ?>">
                                </td>
                                <?php endif; ?>
                                <td data-label="Barcode">
                                    <code class="text-primary"><?= h($item['barcode']) ?></code>
                                </td>
                                <td data-label="Όνομα">
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
                                <td data-label="Εγγραφή" class="d-none d-xxl-table-cell"><small><?= !empty($item['registration_date'] ?? null) ? formatDate($item['registration_date']) : '<span class="text-muted">-</span>' ?></small></td>
                                <td data-label="Α.Μ." class="d-none d-xxl-table-cell"><small><?= h($item['registration_number'] ?? '-') ?></small></td>
                                <td data-label="Κατηγορία">
                                    <?php if ($item['category_name']): ?>
                                        <span class="badge" style="background-color: <?= h($item['category_color']) ?>">
                                            <?= $item['category_icon'] ?> <?= h($item['category_name']) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Τοποθεσία"><?= h($item['location_name'] ?? '-') ?></td>
                                <?php if (isSystemAdmin()): ?>
                                    <td data-label="Τμήμα"><?= h($item['dept_name'] ?? 'Γενικό') ?></td>
                                <?php endif; ?>
                                <td data-label="Κατάσταση"><?= inventoryStatusBadge($item['status']) ?></td>
                                <td data-label="Χρεωμένο σε">
                                    <?php if ($item['booked_by_name']): ?>
                                        <?php
                                        $overdueInfo = calculateOverdueStatus(
                                            $item['booking_date'],
                                            $item['expected_return_date'] ?? null
                                        );
                                        ?>
                                        <small>
                                            <i class="bi bi-person<?= $overdueInfo['is_overdue'] ? '-fill text-danger' : '' ?>"></i>
                                            <span class="<?= $overdueInfo['is_overdue'] ? 'text-danger fw-bold' : '' ?>"
                                                  title="<?= formatDate($item['booking_date']) ?><?= !empty($item['expected_return_date']) ? ' · επ. ' . formatDate($item['expected_return_date']) : '' ?>" data-bs-toggle="tooltip">
                                                <?= h($item['booked_by_name']) ?>
                                            </span>
                                            <?php if ($overdueInfo['is_overdue']): ?>
                                                <span class="badge bg-danger" style="font-size:0.65em;">Εκπρόθ.</span>
                                            <?php endif; ?>
                                        </small>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Ενέργειες" class="text-end col-actions mobile-card-actions">
                                    <div class="btn-group btn-group-sm">
                                        <a href="inventory-view.php?id=<?= $item['id'] ?>" class="btn btn-outline-primary" title="Προβολή">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        <button type="button" class="btn btn-outline-secondary dropdown-toggle dropdown-toggle-split px-1" data-bs-toggle="dropdown" aria-expanded="false"></button>
                                        <ul class="dropdown-menu dropdown-menu-end">
                                            <?php if ($item['status'] === 'available'): ?>
                                                <li><a class="dropdown-item" href="inventory-book.php?item_id=<?= $item['id'] ?>"><i class="bi bi-box-arrow-right me-2"></i>Χρέωση</a></li>
                                            <?php endif; ?>
                                            <?php if (canManageInventory()): ?>
                                                <li><a class="dropdown-item" href="inventory-form.php?id=<?= $item['id'] ?>"><i class="bi bi-pencil me-2"></i>Επεξεργασία</a></li>
                                                <li><a class="dropdown-item" href="inventory-form.php?clone_id=<?= $item['id'] ?>"><i class="bi bi-copy me-2"></i>Κλωνοποίηση</a></li>
                                            <?php endif; ?>
                                        </ul>
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

<style>
.form-check-input {
    width: 1.4em !important;
    height: 1.4em !important;
    cursor: pointer;
}
@media (max-width: 767.98px) {
    .admin-mobile-cards thead { display: none; }
    .admin-mobile-cards, .admin-mobile-cards tbody, .admin-mobile-cards tr, .admin-mobile-cards td { display: block; width: 100%; }
    .admin-mobile-cards tr { margin-bottom: .85rem; padding: .75rem; border: 1px solid var(--bs-border-color); border-radius: .75rem; background: var(--bs-body-bg); }
    .admin-mobile-cards td:not(.d-none) { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; padding: .45rem 0; border: 0; text-align: right !important; overflow-wrap: anywhere; }
    .admin-mobile-cards td:not(.d-none)::before { content: attr(data-label); flex: 0 0 36%; color: var(--bs-secondary-color); font-weight: 600; text-align: left; }
    .admin-mobile-cards td[data-label="Όνομα"] { display: block; text-align: left !important; }
    .admin-mobile-cards td[data-label="Όνομα"]::before { display: block; margin-bottom: .25rem; }
    .admin-mobile-cards .mobile-card-actions { align-items: center; padding-top: .75rem !important; border-top: 1px solid var(--bs-border-color) !important; }
}
@media print {
    .no-print, .sidebar, .navbar, .card-footer, .btn-group,
    #btnPrintSelected, .form-check-input, #chkAll,
    [data-bs-toggle="modal"], .col-actions { display: none !important; }
    body { background: #fff !important; font-size: 11pt; }
    .card { border: none !important; box-shadow: none !important; }
    .card-body { padding: 0 !important; }
    .table { font-size: 9pt; }
    .table th, .table td { padding: 3px 5px !important; white-space: nowrap; }
    h1.h3 { font-size: 14pt !important; }
    .badge { border: 1px solid #999 !important; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
    @page { size: A4 landscape; margin: 10mm; }
    .print-header { display: block !important; }
    .print-footer { display: block !important; text-align: right; font-size: 9pt; color: #999; margin-top: 10px; }
}
.print-header { display: none; }
.print-footer { display: none; }
</style>
<div class="print-footer">Εκτυπώθηκε: <script>document.write(new Date().toLocaleDateString('el-GR') + ' ' + new Date().toLocaleTimeString('el-GR', {hour:'2-digit',minute:'2-digit'}))</script></div>

<?php include __DIR__ . '/includes/footer.php'; ?>

<script>
function printInventoryList() {
    // Collect current filter values from the filter form
    var params = new URLSearchParams();
    var search = document.querySelector('input[name="search"]');
    var status = document.querySelector('select[name="status"]');
    var cat    = document.querySelector('select[name="category_id"]');
    if (search && search.value) params.set('search',      search.value);
    if (status && status.value) params.set('status',      status.value);
    if (cat    && cat.value)    params.set('category_id', cat.value);
    var qs = params.toString();
    window.open('inventory-print.php' + (qs ? '?' + qs : ''), '_blank');
}
</script>

<?php if (canManageInventory()): ?>
<script>
(function () {
    var btnPrint   = document.getElementById('btnPrintSelected');
    var countSpan  = document.getElementById('selectedCount');
    var chkAll     = document.getElementById('chkAll');

    function getChecked() {
        return Array.from(document.querySelectorAll('.item-chk:checked')).map(cb => cb.value);
    }

    function updateBar() {
        var ids = getChecked();
        if (ids.length > 0) {
            btnPrint.style.display = '';
            countSpan.textContent  = ids.length;
        } else {
            btnPrint.style.display = 'none';
        }
        // update select-all state
        var all  = document.querySelectorAll('.item-chk');
        chkAll.indeterminate = ids.length > 0 && ids.length < all.length;
        chkAll.checked       = ids.length > 0 && ids.length === all.length;
    }

    // Individual checkboxes
    document.querySelectorAll('.item-chk').forEach(function (cb) {
        cb.addEventListener('change', updateBar);
    });

    // Select‑all
    chkAll.addEventListener('change', function () {
        document.querySelectorAll('.item-chk').forEach(function (cb) {
            cb.checked = chkAll.checked;
        });
        updateBar();
    });

    window.printSelected = function () {
        var ids = getChecked();
        if (ids.length === 0) return;
        window.open('inventory-label.php?ids=' + ids.join(','), '_blank');
    };
}());
</script>
<?php endif; ?>
