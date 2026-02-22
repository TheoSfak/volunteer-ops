<?php
/**
 * VolunteerOps - Inventory Booking (Checkout / Return)
 * Allows booking an item to a volunteer / returning it.
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

// Pre-select kit if passed via GET
$preselectedKitId = (int)get('kit_id', 0);
$preselectedKit   = null;
if ($preselectedKitId) {
    $preselectedKit = getInventoryKit($preselectedKitId);
    if (!$preselectedKit) {
        setFlash('error', 'Το σετ δεν βρέθηκε.');
        redirect('inventory-book.php');
    }
}

// Handle POST
if (isPost()) {
    verifyCsrf();
    $action = post('action');

    if ($action === 'book_kit') {
        $kitId     = (int)post('kit_id');
        $userId    = (int)post('user_id', getCurrentUserId());
        $data = [
            'mission_location'     => post('mission_location'),
            'notes'                => post('notes'),
            'expected_return_date' => post('expected_return_date') ?: null,
        ];

        if (!empty($data['expected_return_date'])) {
            $parsed = DateTime::createFromFormat('d/m/Y', $data['expected_return_date']);
            if ($parsed) $data['expected_return_date'] = $parsed->format('Y-m-d');
        }

        $result = bookInventoryKit($kitId, $userId, $data);

        if ($result['success']) {
            setFlash('success', $result['message']);
            redirect('inventory-book.php');
        } else {
            setFlash('error', $result['error']);
            redirect('inventory-book.php?kit_id=' . $kitId);
        }
    }

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

    if ($action === 'return_kit') {
        $kitId       = (int)post('kit_id');
        $returnNotes = post('return_notes');
        $userId      = getCurrentUserId();

        $result = returnInventoryKit($kitId, $userId, $returnNotes);

        if ($result['success']) {
            setFlash('success', $result['message']);
        } else {
            setFlash('error', $result['error']);
        }
        redirect('inventory-book.php');
    }

    if ($action === 'barcode_lookup') {
        $barcode = trim(post('barcode'));
        
        // Check if it's a kit first
        $kit = getInventoryKitByBarcode($barcode);
        if ($kit) {
            redirect('inventory-book.php?kit_id=' . $kit['id']);
        }
        
        // If not a kit, check if it's an item
        $item = getInventoryItemByBarcode($barcode);
        if ($item) {
            redirect('inventory-book.php?item_id=' . $item['id']);
        } else {
            setFlash('error', 'Δεν βρέθηκε υλικό ή σετ με barcode: ' . $barcode);
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
                <form method="post" class="d-flex gap-2" id="barcodeLookupForm">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="barcode_lookup">
                    <input type="text" class="form-control" name="barcode" id="barcodeInput"
                           placeholder="Σαρώστε ή πληκτρολογήστε barcode..." autofocus autocomplete="off">
                    <button type="submit" class="btn btn-primary" title="Αναζήτηση">
                        <i class="bi bi-search"></i>
                    </button>
                    <button type="button" class="btn btn-outline-primary" id="btnCamScan" title="Σάρωση με κάμερα κινητού">
                        <i class="bi bi-camera"></i>
                    </button>
                </form>

                <!-- Camera scanner area (hidden until activated) -->
                <div id="camScanArea" class="mt-3" style="display:none;">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <small class="text-primary fw-semibold">
                            <i class="bi bi-camera-video me-1"></i>Στρέψε την κάμερα στο QR code ή barcode
                        </small>
                        <button type="button" class="btn btn-sm btn-outline-danger" id="btnStopScan">
                            <i class="bi bi-x-circle me-1"></i>Κλείσιμο
                        </button>
                    </div>
                    <div id="qrReader" style="width:100%; max-width:420px; border-radius:8px; overflow:hidden;"></div>
                </div>
            </div>
        </div>

        <!-- Booking Form -->
        <div class="card mb-4">
            <div class="card-header bg-success text-white">
                <h5 class="mb-0"><i class="bi bi-box-arrow-right me-2"></i>Νέα Χρέωση / Επιστροφή</h5>
            </div>
            <div class="card-body">
                <?php if ($preselectedKit): ?>
                    <?php
                        $availableCount = 0;
                        $bookedCount = 0;
                        foreach ($preselectedKit['items'] as $item) {
                            if ($item['status'] === 'available' && $item['is_active'] == 1) $availableCount++;
                            if ($item['status'] === 'booked') $bookedCount++;
                        }
                    ?>
                    <div class="alert alert-info">
                        <h5 class="alert-heading"><i class="bi bi-briefcase me-2"></i>Σετ Εξοπλισμού: <?= h($preselectedKit['name']) ?></h5>
                        <p class="mb-0">Διαθέσιμα για χρέωση: <strong><?= $availableCount ?></strong> | Χρεωμένα: <strong><?= $bookedCount ?></strong></p>
                    </div>
                    
                    <h6 class="mb-3">Περιεχόμενα Σετ:</h6>
                    <ul class="list-group mb-4">
                        <?php foreach ($preselectedKit['items'] as $item): ?>
                            <?php 
                                $isAvail = $item['status'] === 'available' && $item['is_active'] == 1;
                                $badgeClass = $isAvail ? 'bg-success' : 'bg-danger';
                                $statusText = $isAvail ? 'Διαθέσιμο' : ($item['status'] === 'booked' ? 'Χρεωμένο' : 'Μη διαθέσιμο');
                            ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <div>
                                    <?= $item['category_icon'] ?? '📦' ?> <?= h($item['name']) ?>
                                    <br><small class="text-muted font-monospace"><?= h($item['barcode']) ?></small>
                                </div>
                                <span class="badge <?= $badgeClass ?> rounded-pill"><?= $statusText ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>

                    <?php if ($availableCount > 0): ?>
                    <form method="post" class="mb-4 border-bottom pb-4">
                        <h6 class="mb-3 text-success"><i class="bi bi-box-arrow-right me-1"></i>Χρέωση Διαθέσιμων Υλικών</h6>
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="book_kit">
                        <input type="hidden" name="kit_id" value="<?= $preselectedKit['id'] ?>">

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
                        <?php endif; ?>

                        <div class="mb-3">
                            <label class="form-label">Αποστολή / Τοποθεσία</label>
                            <input type="text" class="form-control" name="mission_location" placeholder="π.χ. Αποστολή Πάρνηθα">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Σημειώσεις</label>
                            <textarea class="form-control" name="notes" rows="2" placeholder="Προαιρετικές σημειώσεις..."></textarea>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Αναμενόμενη Επιστροφή</label>
                            <input type="text" class="form-control datepicker" name="expected_return_date" placeholder="ΗΗ/ΜΜ/ΕΕΕΕ">
                        </div>

                        <button type="submit" class="btn btn-success w-100">
                            <i class="bi bi-check-circle me-1"></i>Χρέωση Όλων των Διαθέσιμων Υλικών (<?= $availableCount ?>)
                        </button>
                    </form>
                    <?php endif; ?>

                    <?php if ($bookedCount > 0): ?>
                    <form method="post" onsubmit="return confirm('Είστε σίγουροι ότι θέλετε να επιστρέψετε τα χρεωμένα υλικά αυτού του σετ;')">
                        <h6 class="mb-3 text-warning"><i class="bi bi-box-arrow-in-left me-1"></i>Επιστροφή Χρεωμένων Υλικών</h6>
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="return_kit">
                        <input type="hidden" name="kit_id" value="<?= $preselectedKit['id'] ?>">
                        
                        <div class="mb-3">
                            <label class="form-label">Σημειώσεις Επιστροφής</label>
                            <input type="text" class="form-control" name="return_notes" placeholder="π.χ. Όλα σε καλή κατάσταση">
                        </div>

                        <button type="submit" class="btn btn-warning w-100">
                            <i class="bi bi-arrow-return-left me-1"></i>Επιστροφή Χρεωμένων Υλικών (<?= $bookedCount ?>)
                        </button>
                    </form>
                    <?php endif; ?>

                <?php elseif ($preselectedItem && $preselectedItem['status'] !== 'available'): ?>
                    <div class="alert alert-warning">
                        Το υλικό <strong><?= h($preselectedItem['name']) ?></strong> δεν είναι διαθέσιμο αυτή τη στιγμή (Κατάσταση: <?= h($preselectedItem['status']) ?>).
                    </div>
                    
                    <?php if ($preselectedItem['status'] === 'booked'): ?>
                        <?php
                            // Find active booking for this item
                            $activeBooking = dbFetchOne("
                                SELECT id, user_id 
                                FROM inventory_bookings 
                                WHERE item_id = ? AND status IN ('active', 'overdue')
                                ORDER BY created_at DESC LIMIT 1
                            ", [$preselectedItem['id']]);
                            
                            $canReturn = $activeBooking && (isAdmin() || $activeBooking['user_id'] == getCurrentUserId());
                        ?>
                        <?php if ($canReturn): ?>
                            <form method="post" onsubmit="return confirm('Επιβεβαίωση επιστροφής;')">
                                <h6 class="mb-3 text-warning"><i class="bi bi-box-arrow-in-left me-1"></i>Επιστροφή Υλικού</h6>
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="return">
                                <input type="hidden" name="booking_id" value="<?= $activeBooking['id'] ?>">
                                
                                <div class="mb-3">
                                    <label class="form-label">Σημειώσεις Επιστροφής</label>
                                    <input type="text" class="form-control" name="return_notes" placeholder="π.χ. Σε καλή κατάσταση">
                                </div>

                                <button type="submit" class="btn btn-warning w-100">
                                    <i class="bi bi-arrow-return-left me-1"></i>Επιστροφή Υλικού
                                </button>
                            </form>
                        <?php else: ?>
                            <div class="alert alert-danger mt-3">
                                Το υλικό είναι χρεωμένο σε άλλον εθελοντή. Δεν έχετε δικαίωμα επιστροφής.
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>

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

<!-- html5-qrcode: loaded after jQuery (which comes from footer.php) -->
<script src="https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
(function () {
    var html5QrCode = null;

    var btnStart = document.getElementById('btnCamScan');
    var btnStop  = document.getElementById('btnStopScan');
    var scanArea = document.getElementById('camScanArea');
    var input    = document.getElementById('barcodeInput');
    var form     = document.getElementById('barcodeLookupForm');

    function stopScanner(cb) {
        if (html5QrCode) {
            html5QrCode.stop().catch(function() {}).then(function() {
                html5QrCode = null;
                scanArea.style.display = 'none';
                btnStart.disabled = false;
                if (cb) cb();
            });
        } else {
            scanArea.style.display = 'none';
            btnStart.disabled = false;
            if (cb) cb();
        }
    }

    btnStart.addEventListener('click', function () {
        scanArea.style.display = 'block';
        btnStart.disabled = true;

        html5QrCode = new Html5Qrcode('qrReader');
        html5QrCode.start(
            { facingMode: 'environment' }, // back camera
            { fps: 10, qrbox: { width: 280, height: 160 } },
            function (decodedText) {
                // Strip URL prefix if the QR encoded a full URL (e.g. /inventory-view.php?barcode=XX)
                var barcode = decodedText;
                try {
                    var url = new URL(decodedText);
                    var b = url.searchParams.get('barcode');
                    if (b) barcode = b;
                } catch (e) { /* not a URL, use raw text */ }

                input.value = barcode;
                stopScanner(function () { form.submit(); });
            },
            function () { /* per-frame decode errors — ignore */ }
        ).catch(function (err) {
            alert('Δεν ήταν δυνατή η πρόσβαση στην κάμερα.\n' + err);
            stopScanner();
        });
    });

    btnStop.addEventListener('click', function () {
        stopScanner();
    });
}());
</script>
