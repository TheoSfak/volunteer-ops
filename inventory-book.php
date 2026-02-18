<?php
/**
 * VolunteerOps - Inventory Booking (Checkout / Return)
 * Allows booking an item to a volunteer / returning it.
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();

$user = getCurrentUser();

$pageTitle = 'Χρέωση Υλικού';

// Pre-select item if passed via GET
$preselectedItemId = (int)get('item_id', 0);
$preselectedItem   = null;
if ($preselectedItemId) {
    $preselectedItem = getInventoryItem($preselectedItemId);
    if ($preselectedItem && !checkInventoryAccess($preselectedItemId)) {
        setFlash('error', 'Δεν έχετε πρόσβαση σε αυτό το υλικό.');
        redirect('inventory.php');
    }
}

// Handle POST
if (isPost()) {
    verifyCsrf();
    $action = post('action');

    if ($action === 'book') {
        $itemId    = (int)post('item_id');
        $userId    = (int)post('user_id', getCurrentUserId());
        $data = [
            'mission_location'     => post('mission_location'),
            'notes'                => post('notes'),
            'expected_return_date' => post('expected_return_date') ?: null,
        ];

        // Convert date format if provided
        if (!empty($data['expected_return_date'])) {
            $parsed = DateTime::createFromFormat('d/m/Y', $data['expected_return_date']);
            if ($parsed) {
                $data['expected_return_date'] = $parsed->format('Y-m-d');
            }
        }

        $result = createInventoryBooking($itemId, $userId, $data);

        if ($result['success']) {
            setFlash('success', 'Η χρέωση καταγράφηκε επιτυχώς.');
            redirect('inventory-view.php?id=' . $itemId);
        } else {
            setFlash('error', $result['error']);
            redirect('inventory-book.php?item_id=' . $itemId);
        }
    }

    if ($action === 'return') {
        $bookingId   = (int)post('booking_id');
        $returnNotes = post('return_notes');

        $result = returnInventoryItem($bookingId, $returnNotes);

        if ($result['success']) {
            setFlash('success', 'Η επιστροφή καταγράφηκε. Διάρκεια: ' . round($result['hours'], 1) . ' ώρες.');
        } else {
            setFlash('error', $result['error']);
        }
        redirect('inventory-book.php');
    }

    if ($action === 'barcode_lookup') {
        $barcode = trim(post('barcode'));
        $item = getInventoryItemByBarcode($barcode);
        if ($item) {
            redirect('inventory-book.php?item_id=' . $item['id']);
        } else {
            setFlash('error', 'Δεν βρέθηκε υλικό με barcode: ' . $barcode);
            redirect('inventory-book.php');
        }
    }
}

// Get available items for dropdown
$availableItems = dbFetchAll("
    SELECT i.id, i.barcode, i.name, c.icon AS category_icon
    FROM inventory_items i
    LEFT JOIN inventory_categories c ON i.category_id = c.id
    WHERE i.is_active = 1 AND i.status = 'available'
    ORDER BY i.name
");

// Get active bookings for return section
$activeBookings = [];
if (isAdmin()) {
    // Admins see all active bookings
    $activeBookings = dbFetchAll("
        SELECT b.*, i.name AS item_name, i.barcode, u.name AS user_name
        FROM inventory_bookings b
        JOIN inventory_items i ON b.item_id = i.id
        JOIN users u ON b.user_id = u.id
        WHERE b.status IN ('active', 'overdue')
        ORDER BY b.created_at DESC
        LIMIT 50
    ");
} else {
    // Volunteers see only their own
    $activeBookings = getUserActiveBookings(getCurrentUserId());
}

// Get volunteers list for admin booking
$volunteers = [];
if (isAdmin()) {
    $volunteers = dbFetchAll("SELECT id, name, email FROM users WHERE is_active = 1 ORDER BY name");
}

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">
        <i class="bi bi-upc-scan me-2"></i><?= h($pageTitle) ?>
    </h1>
    <a href="inventory.php" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Πίσω
    </a>
</div>

<div class="row">
    <!-- Left: Booking Form -->
    <div class="col-lg-7">
        <!-- Barcode Lookup -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-upc me-2"></i>Αναζήτηση με Barcode</h5>
            </div>
            <div class="card-body">
                <form method="post" class="d-flex gap-2">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="barcode_lookup">
                    <input type="text" class="form-control" name="barcode" placeholder="Σαρώστε ή πληκτρολογήστε barcode..." autofocus>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-search"></i>
                    </button>
                </form>
            </div>
        </div>

        <!-- Booking Form -->
        <div class="card mb-4">
            <div class="card-header bg-success text-white">
                <h5 class="mb-0"><i class="bi bi-box-arrow-right me-2"></i>Νέα Χρέωση</h5>
            </div>
            <div class="card-body">
                <?php if ($preselectedItem && $preselectedItem['status'] !== 'available'): ?>
                    <div class="alert alert-warning">
                        Το υλικό <strong><?= h($preselectedItem['name']) ?></strong> δεν είναι διαθέσιμο αυτή τη στιγμή.
                    </div>
                <?php else: ?>
                    <form method="post">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="book">

                        <div class="mb-3">
                            <label class="form-label">Υλικό *</label>
                            <select class="form-select" name="item_id" required>
                                <option value="">-- Επιλέξτε υλικό --</option>
                                <?php foreach ($availableItems as $ai): ?>
                                    <option value="<?= $ai['id'] ?>" <?= $preselectedItemId == $ai['id'] ? 'selected' : '' ?>>
                                        [<?= h($ai['barcode']) ?>] <?= $ai['category_icon'] ?? '' ?> <?= h($ai['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <?php if (isAdmin() && !empty($volunteers)): ?>
                        <div class="mb-3">
                            <label class="form-label">Εθελοντής *</label>
                            <select class="form-select" name="user_id" required>
                                <option value="">-- Επιλέξτε εθελοντή --</option>
                                <?php foreach ($volunteers as $vol): ?>
                                    <option value="<?= $vol['id'] ?>" <?= $vol['id'] == getCurrentUserId() ? 'selected' : '' ?>>
                                        <?= h($vol['name']) ?> (<?= h($vol['email']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php else: ?>
                            <input type="hidden" name="user_id" value="<?= getCurrentUserId() ?>">
                            <div class="mb-3">
                                <label class="form-label">Εθελοντής</label>
                                <input type="text" class="form-control" value="<?= h($user['name']) ?>" disabled>
                            </div>
                        <?php endif; ?>

                        <div class="mb-3">
                            <label class="form-label">Αποστολή *</label>
                            <input type="text" class="form-control" name="mission_location" 
                                   placeholder="π.χ. Αποστολή Ηράκλεια 2026" required>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Σημειώσεις</label>
                            <textarea class="form-control" name="notes" rows="2" placeholder="Προαιρετικές σημειώσεις..."></textarea>
                        </div>

                        <button type="submit" class="btn btn-success btn-lg w-100">
                            <i class="bi bi-box-arrow-right me-1"></i>Χρέωση Υλικού
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Right: Active Bookings / Returns -->
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header bg-warning">
                <h5 class="mb-0"><i class="bi bi-box-arrow-in-left me-2"></i>Ενεργές Χρεώσεις (<?= count($activeBookings) ?>)</h5>
            </div>
            <div class="card-body p-0">
                <?php if (empty($activeBookings)): ?>
                    <p class="text-muted text-center py-4">Δεν υπάρχουν ενεργές χρεώσεις.</p>
                <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($activeBookings as $ab): ?>
                            <?php
                            $overdueInfo = calculateOverdueStatus(
                                $ab['created_at'],
                                $ab['expected_return_date'] ?? null
                            );
                            ?>
                            <div class="list-group-item">
                                <div class="d-flex justify-content-between align-items-start mb-1">
                                    <div>
                                        <strong><?= h($ab['item_name'] ?? $ab['name'] ?? '') ?></strong>
                                        <br>
                                        <small class="text-muted">
                                            <code><?= h($ab['barcode']) ?></code> — 
                                            <?= h($ab['volunteer_name'] ?? $ab['user_name'] ?? '') ?>
                                        </small>
                                    </div>
                                    <span class="badge bg-<?= $overdueInfo['status_class'] ?>">
                                        <?= h($overdueInfo['status_label']) ?>
                                    </span>
                                </div>
                                <small class="text-muted d-block mb-2">
                                    Χρέωση: <?= formatDateTime($ab['created_at']) ?>
                                    <?php if ($ab['mission_location']): ?>
                                        <br><i class="bi bi-geo-alt"></i> <?= h($ab['mission_location']) ?>
                                    <?php endif; ?>
                                </small>
                                <!-- Return Button -->
                                <form method="post" class="d-flex gap-2" onsubmit="return confirm('Επιβεβαίωση επιστροφής;')">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="return">
                                    <input type="hidden" name="booking_id" value="<?= $ab['id'] ?>">
                                    <input type="text" class="form-control form-control-sm" name="return_notes" 
                                           placeholder="Σημείωση επιστροφής...">
                                    <button type="submit" class="btn btn-sm btn-outline-success flex-shrink-0">
                                        <i class="bi bi-box-arrow-in-left"></i> Επιστροφή
                                    </button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
