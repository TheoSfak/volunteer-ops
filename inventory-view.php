<?php
/**
 * VolunteerOps - Inventory Item View
 * Displays item details, booking history, notes, and quick actions.
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();

$id      = get('id');
$barcode = get('barcode');

// Find item by ID or barcode
if ($barcode) {
    $item = getInventoryItemByBarcode($barcode);
    if ($item) {
        redirect('inventory-view.php?id=' . $item['id']);
    }
} else {
    $item = getInventoryItem($id);
}

if (!$item) {
    setFlash('error', 'Το υλικό δεν βρέθηκε.');
    redirect('inventory.php');
}

// Check access
if (!checkInventoryAccess($item['id'])) {
    setFlash('error', 'Δεν έχετε πρόσβαση σε αυτό το υλικό.');
    redirect('inventory.php');
}

$pageTitle = $item['name'] . ' — Προβολή Υλικού';

// Handle status change (admin only)
if (isPost() && canManageInventory()) {
    verifyCsrf();
    $action = post('action');

    if ($action === 'change_status') {
        $newStatus = post('new_status');
        $validStatuses = ['available', 'maintenance', 'damaged'];
        if (in_array($newStatus, $validStatuses) && $item['status'] !== 'booked') {
            dbExecute("UPDATE inventory_items SET status = ? WHERE id = ?", [$newStatus, $id]);
            logAudit('inventory_status_change', 'inventory_items', $id);
            setFlash('success', 'Η κατάσταση ενημερώθηκε.');
            redirect('inventory-view.php?id=' . $id);
        } else {
            setFlash('error', 'Δεν μπορείτε να αλλάξετε κατάσταση χρεωμένου υλικού.');
            redirect('inventory-view.php?id=' . $id);
        }
    }

    if ($action === 'quick_return') {
        $bookingId   = (int)post('booking_id');
        $returnNotes = post('return_notes');
        $result = returnInventoryItem($bookingId, $returnNotes);
        if ($result['success']) {
            setFlash('success', 'Η επιστροφή καταγράφηκε επιτυχώς. Διάρκεια: ' . round($result['hours'], 1) . ' ώρες.');
        } else {
            setFlash('error', $result['error']);
        }
        redirect('inventory-view.php?id=' . $id);
    }

    if ($action === 'add_note') {
        $content  = trim(post('note_content'));
        $noteType = post('note_type', 'general');
        $priority = post('note_priority', 'medium');
        
        if (!empty($content)) {
            $user = getCurrentUser();
            dbInsert("
                INSERT INTO inventory_notes 
                    (item_id, item_name, note_type, content, priority, created_by_user_id, created_by_name)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ", [$id, $item['name'], $noteType, $content, $priority, $user['id'], $user['name']]);
            logAudit('inventory_note_add', 'inventory_notes', $id);
            setFlash('success', 'Η σημείωση προστέθηκε.');
        }
        redirect('inventory-view.php?id=' . $id);
    }
}

// Fetch booking history
$bookings = getItemBookings($id, 20);

// Fetch active booking (if booked)
$activeBooking = null;
if ($item['status'] === 'booked') {
    $activeBooking = dbFetchOne("
        SELECT b.*, u.name AS user_name, u.phone AS user_phone
        FROM inventory_bookings b
        JOIN users u ON b.user_id = u.id
        WHERE b.item_id = ? AND b.status IN ('active', 'overdue')
        ORDER BY b.created_at DESC
        LIMIT 1
    ", [$id]);
}

// Fetch notes
$notes = dbFetchAll("
    SELECT n.*, u.name AS author_name
    FROM inventory_notes n
    LEFT JOIN users u ON n.created_by_user_id = u.id
    WHERE n.item_id = ?
    ORDER BY n.created_at DESC
    LIMIT 10
", [$id]);

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">
        <i class="bi bi-box-seam me-2"></i><?= h($item['name']) ?>
    </h1>
    <div class="d-flex gap-2">
        <?php if ($item['status'] === 'available'): ?>
            <a href="inventory-book.php?item_id=<?= $item['id'] ?>" class="btn btn-success">
                <i class="bi bi-box-arrow-right me-1"></i>Χρέωση
            </a>
        <?php endif; ?>
        <?php if (canManageInventory()): ?>
            <a href="inventory-form.php?id=<?= $item['id'] ?>" class="btn btn-outline-secondary">
                <i class="bi bi-pencil me-1"></i>Επεξεργασία
            </a>
        <?php endif; ?>
        <a href="inventory.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i>Πίσω
        </a>
    </div>
</div>

<div class="row">
    <!-- Left: Item Details -->
    <div class="col-lg-8">
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">Πληροφορίες Υλικού</h5>
                <?= inventoryStatusBadge($item['status']) ?>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <strong class="text-muted d-block mb-1">Barcode</strong>
                        <code class="fs-5 text-primary"><?= h($item['barcode']) ?></code>
                    </div>
                    <div class="col-md-4">
                        <strong class="text-muted d-block mb-1">Κατηγορία</strong>
                        <?php if ($item['category_name']): ?>
                            <span class="badge" style="background-color: <?= h($item['category_color']) ?>">
                                <?= $item['category_icon'] ?> <?= h($item['category_name']) ?>
                            </span>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-4">
                        <strong class="text-muted d-block mb-1">Ποσότητα</strong>
                        <?= (int)$item['quantity'] ?>
                    </div>
                    <div class="col-md-4">
                        <strong class="text-muted d-block mb-1">Τοποθεσία</strong>
                        <?= h($item['location_name'] ?? '—') ?>
                        <?php if ($item['location_notes']): ?>
                            <br><small class="text-muted"><?= h($item['location_notes']) ?></small>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-4">
                        <strong class="text-muted d-block mb-1">Τμήμα</strong>
                        <?= h($item['dept_name'] ?? 'Γενικό') ?>
                    </div>
                    <div class="col-md-4">
                        <strong class="text-muted d-block mb-1">Δημιουργήθηκε</strong>
                        <?= formatDateTime($item['created_at']) ?>
                        <?php if ($item['creator_name']): ?>
                            <br><small class="text-muted">από <?= h($item['creator_name']) ?></small>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($item['description'])): ?>
                        <div class="col-12">
                            <strong class="text-muted d-block mb-1">Περιγραφή</strong>
                            <?= nl2br(h($item['description'])) ?>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($item['condition_notes'])): ?>
                        <div class="col-12">
                            <strong class="text-muted d-block mb-1">Κατάσταση Υλικού</strong>
                            <?= nl2br(h($item['condition_notes'])) ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Active Booking -->
        <?php if ($activeBooking): ?>
        <div class="card mb-4 border-primary">
            <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-person-fill me-2"></i>Ενεργή Χρέωση</h5>
                <?php
                $overdueInfo = calculateOverdueStatus(
                    $activeBooking['created_at'],
                    $activeBooking['expected_return_date']
                );
                ?>
                <span class="badge bg-<?= $overdueInfo['status_class'] ?>">
                    <?= h($overdueInfo['status_label']) ?>
                </span>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <strong class="text-muted d-block mb-1">Εθελοντής</strong>
                        <?= h($activeBooking['volunteer_name']) ?>
                        <?php if ($activeBooking['user_phone']): ?>
                            <br><small><i class="bi bi-telephone"></i> <?= h($activeBooking['user_phone']) ?></small>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-4">
                        <strong class="text-muted d-block mb-1">Ημ/νία Χρέωσης</strong>
                        <?= formatDateTime($activeBooking['created_at']) ?>
                    </div>
                    <div class="col-md-4">
                        <strong class="text-muted d-block mb-1">Αναμ. Επιστροφή</strong>
                        <?= $activeBooking['expected_return_date'] ? formatDate($activeBooking['expected_return_date']) : '—' ?>
                    </div>
                    <?php if ($activeBooking['mission_location']): ?>
                        <div class="col-md-6">
                            <strong class="text-muted d-block mb-1">Τοποθεσία Αποστολής</strong>
                            <?= h($activeBooking['mission_location']) ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($activeBooking['notes']): ?>
                        <div class="col-md-6">
                            <strong class="text-muted d-block mb-1">Σημειώσεις</strong>
                            <?= h($activeBooking['notes']) ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Quick Return Form -->
                <?php if (canManageInventory() || $activeBooking['user_id'] == getCurrentUserId()): ?>
                <hr>
                <form method="post" class="mt-3">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="quick_return">
                    <input type="hidden" name="booking_id" value="<?= $activeBooking['id'] ?>">
                    <div class="row g-2 align-items-end">
                        <div class="col-md-8">
                            <label class="form-label">Σημειώσεις Επιστροφής</label>
                            <input type="text" class="form-control" name="return_notes" placeholder="π.χ. Σε καλή κατάσταση">
                        </div>
                        <div class="col-md-4">
                            <button type="submit" class="btn btn-warning w-100" 
                                    onclick="return confirm('Επιβεβαίωση επιστροφής υλικού;')">
                                <i class="bi bi-box-arrow-in-left me-1"></i>Επιστροφή
                            </button>
                        </div>
                    </div>
                </form>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Booking History -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i>Ιστορικό Χρεώσεων</h5>
            </div>
            <div class="card-body p-0">
                <?php if (empty($bookings)): ?>
                    <p class="text-muted text-center py-4">Δεν υπάρχουν χρεώσεις.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Εθελοντής</th>
                                    <th>Ημ/νία</th>
                                    <th>Τοποθεσία</th>
                                    <th>Κατάσταση</th>
                                    <th>Επιστροφή</th>
                                    <th>Ώρες</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($bookings as $booking): ?>
                                    <tr>
                                        <td><?= h($booking['volunteer_name'] ?? $booking['user_name']) ?></td>
                                        <td><small><?= formatDateTime($booking['created_at']) ?></small></td>
                                        <td><small><?= h($booking['mission_location'] ?? '—') ?></small></td>
                                        <td><?= bookingStatusBadge($booking['status']) ?></td>
                                        <td>
                                            <?php if ($booking['return_date']): ?>
                                                <small><?= formatDateTime($booking['return_date']) ?></small>
                                            <?php else: ?>
                                                —
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?= $booking['actual_hours'] ? round($booking['actual_hours'], 1) . 'ω' : '—' ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Right Column: Actions & Notes -->
    <div class="col-lg-4">
        <!-- Status Change (Admin) -->
        <?php if (canManageInventory() && $item['status'] !== 'booked'): ?>
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0">Αλλαγή Κατάστασης</h5>
            </div>
            <div class="card-body">
                <form method="post">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="change_status">
                    <div class="d-grid gap-2">
                        <?php foreach (['available' => 'success', 'maintenance' => 'warning', 'damaged' => 'danger'] as $st => $color): ?>
                            <?php if ($item['status'] !== $st): ?>
                                <button type="submit" name="new_status" value="<?= $st ?>" 
                                        class="btn btn-outline-<?= $color ?> btn-sm">
                                    <?= h(INVENTORY_STATUS_LABELS[$st]) ?>
                                </button>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <!-- Notes -->
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-sticky me-2"></i>Σημειώσεις</h5>
                <span class="badge bg-secondary"><?= count($notes) ?></span>
            </div>
            <div class="card-body">
                <!-- Add Note Form -->
                <form method="post" class="mb-3">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="add_note">
                    <div class="mb-2">
                        <textarea class="form-control form-control-sm" name="note_content" rows="2" 
                                  placeholder="Γράψτε σημείωση..." required></textarea>
                    </div>
                    <div class="d-flex gap-2 mb-2">
                        <select class="form-select form-select-sm" name="note_type" style="max-width: 130px;">
                            <?php foreach (NOTE_TYPE_LABELS as $key => $label): ?>
                                <option value="<?= $key ?>"><?= h($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select class="form-select form-select-sm" name="note_priority" style="max-width: 120px;">
                            <?php foreach (NOTE_PRIORITY_LABELS as $key => $label): ?>
                                <option value="<?= $key ?>" <?= $key === 'medium' ? 'selected' : '' ?>><?= h($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-sm btn-primary w-100">
                        <i class="bi bi-plus me-1"></i>Προσθήκη
                    </button>
                </form>

                <!-- Notes List -->
                <?php if (empty($notes)): ?>
                    <p class="text-muted small text-center">Δεν υπάρχουν σημειώσεις.</p>
                <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($notes as $note): ?>
                            <div class="list-group-item px-0">
                                <div class="d-flex justify-content-between align-items-start mb-1">
                                    <small class="text-muted"><?= h($note['author_name'] ?? 'Σύστημα') ?></small>
                                    <?= notePriorityBadge($note['priority']) ?>
                                </div>
                                <p class="mb-1 small"><?= nl2br(h($note['content'])) ?></p>
                                <small class="text-muted">
                                    <?= h(NOTE_TYPE_LABELS[$note['note_type']] ?? $note['note_type']) ?> · 
                                    <?= formatDateTime($note['created_at']) ?>
                                </small>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
