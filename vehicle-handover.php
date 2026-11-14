<?php
/**
 * VolunteerOps - Vehicle Handover (Checkout / Check-in)
 * Takes a vehicle out (odometer, fuel level, equipment checklist) and
 * checks it back in the same way. A checkin tick other than "ok" moves the
 * vehicle into maintenance - see includes/fleet-functions.php closeHandover().
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/fleet-functions.php';
requireLogin();

if (!canManageFleet()) {
    setFlash('error', 'Δεν έχετε δικαίωμα πρόσβασης.');
    redirect('dashboard.php');
}

$pageTitle = 'Παραλαβή / Επιστροφή Οχήματος';
$checklistStatuses = getChecklistStatuses();

function readPostedChecks(array $itemIds): array {
    $posted = post('checks', []) ?: [];
    $checks = [];
    foreach ($itemIds as $itemId) {
        $checks[$itemId] = [
            'status' => $posted[$itemId]['status'] ?? 'ok',
            'note'   => $posted[$itemId]['note'] ?? '',
        ];
    }
    return $checks;
}

if (isPost()) {
    verifyCsrf();
    $action = post('action');
    $vehicleId = (int) post('vehicle_id');
    $km = post('km') !== '' ? (float) post('km') : null;
    $fuelLevel = post('fuel_level') !== '' ? max(0, min(100, (int) post('fuel_level'))) : null;
    $notes = trim(post('notes'));

    $vehicle = dbFetchOne("SELECT * FROM vehicles WHERE id = ?", [$vehicleId]);
    if (!$vehicle) {
        setFlash('error', 'Το όχημα δεν βρέθηκε.');
        redirect('vehicle-handover.php');
    }
    $itemIds = array_column(getChecklistItemsForType($vehicle['type']), 'id');
    $checks = readPostedChecks($itemIds);

    if ($action === 'checkout') {
        $shiftId = (int) post('shift_id') ?: null;
        $result = openHandover($vehicleId, $shiftId, getCurrentUser()['id'], $km, $fuelLevel, $notes, $checks);
        if ($result['success']) {
            logAudit('vehicle_handover_checkout', 'vehicle_handovers', $result['handover_id']);
            setFlash('success', 'Η παραλαβή του οχήματος «' . $vehicle['label'] . '» καταγράφηκε.');
        } else {
            setFlash('error', $result['error']);
        }
        redirect('vehicle-view.php?id=' . $vehicleId);
    }

    if ($action === 'checkin') {
        $result = closeHandover($vehicleId, getCurrentUser()['id'], $km, $fuelLevel, $notes, $checks);
        if ($result['success']) {
            logAudit('vehicle_handover_checkin', 'vehicles', $vehicleId);
            setFlash('success', $result['flagged']
                ? 'Η επιστροφή καταγράφηκε. Το όχημα μπήκε σε συντήρηση λόγω φθοράς/έλλειψης στο checklist.'
                : 'Η επιστροφή του οχήματος «' . $vehicle['label'] . '» καταγράφηκε.');
        } else {
            setFlash('error', $result['error']);
        }
        redirect('vehicle-view.php?id=' . $vehicleId);
    }
}

// Vehicles available to check out (active, not already out)
$outVehicleIds = array_column(getOpenHandovers(), 'vehicle_id');
$checkoutCandidates = array_filter(getAllActiveVehicles(), fn($v) => !in_array($v['id'], $outVehicleIds, true));

$openHandovers = getOpenHandovers();

// Preselected vehicle (either to check out, or to check in if it's already open)
$selectedVehicleId = (int) get('vehicle_id', 0);
$selectedVehicle = $selectedVehicleId ? dbFetchOne("SELECT * FROM vehicles WHERE id = ?", [$selectedVehicleId]) : null;
$selectedOpenHandover = $selectedVehicle ? getOpenHandover($selectedVehicle['id']) : null;
$selectedChecklistItems = $selectedVehicle ? getChecklistItemsForType($selectedVehicle['type']) : [];
$selectedExistingChecks = $selectedOpenHandover ? getHandoverChecks($selectedOpenHandover['id']) : [];

// Upcoming/current shifts, for the optional link on checkout
$shiftsForPicker = dbFetchAll(
    "SELECT s.id, s.start_time, m.title AS mission_title
     FROM shifts s JOIN missions m ON m.id = s.mission_id
     WHERE s.end_time >= NOW() AND m.deleted_at IS NULL
     ORDER BY s.start_time LIMIT 100"
);

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
    <h1 class="h3 mb-0">
        <i class="bi bi-key me-2"></i><?= h($pageTitle) ?>
    </h1>
    <a href="vehicles.php" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Οχήματα
    </a>
</div>

<?= showFlash() ?>

<div class="row">
    <div class="col-lg-7">
        <?php if ($selectedVehicle && $selectedOpenHandover): ?>
            <!-- Check-in form for the selected (currently out) vehicle -->
            <div class="card mb-4">
                <div class="card-header bg-warning">
                    <h5 class="mb-0"><i class="bi bi-box-arrow-in-left me-2"></i>Επιστροφή: <?= h($selectedVehicle['label']) ?></h5>
                </div>
                <div class="card-body">
                    <form method="post">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="checkin">
                        <input type="hidden" name="vehicle_id" value="<?= $selectedVehicle['id'] ?>">

                        <div class="row">
                            <div class="col-7 mb-3">
                                <label class="form-label">Οδόμετρο κατά την επιστροφή</label>
                                <input type="number" step="0.1" min="<?= h($selectedOpenHandover['checkout_km'] ?? 0) ?>"
                                       class="form-control" name="km"
                                       placeholder="<?= $selectedVehicle['odometer_unit'] === 'engine_hours' ? 'ώρες' : 'km' ?>">
                            </div>
                            <div class="col-5 mb-3">
                                <label class="form-label">Καύσιμο %</label>
                                <input type="number" min="0" max="100" class="form-control" name="fuel_level">
                            </div>
                        </div>

                        <?php if (!empty($selectedChecklistItems)): ?>
                        <div class="mb-3">
                            <label class="form-label">Checklist Εξοπλισμού</label>
                            <table class="table table-sm">
                                <thead>
                                    <tr><th>Στοιχείο</th><th>Κατά την παραλαβή</th><th>Τώρα</th><th>Σημείωση</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($selectedChecklistItems as $ci):
                                        $outCheck = $selectedExistingChecks[$ci['id'] . ':checkout'] ?? null;
                                    ?>
                                        <tr>
                                            <td><?= h($ci['label']) ?></td>
                                            <td>
                                                <?php if ($outCheck): ?>
                                                    <span class="badge bg-<?= $outCheck['status'] === 'ok' ? 'success' : 'warning' ?>">
                                                        <?= h($checklistStatuses[$outCheck['status']] ?? $outCheck['status']) ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <select class="form-select form-select-sm" name="checks[<?= $ci['id'] ?>][status]">
                                                    <?php foreach ($checklistStatuses as $sk => $sl): ?>
                                                        <option value="<?= $sk ?>" <?= $sk === 'ok' ? 'selected' : '' ?>><?= h($sl) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </td>
                                            <td>
                                                <input type="text" class="form-control form-control-sm" name="checks[<?= $ci['id'] ?>][note]">
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <small class="text-muted">Μια φθορά ή έλλειψη εδώ βάζει αυτόματα το όχημα σε συντήρηση.</small>
                        </div>
                        <?php endif; ?>

                        <div class="mb-3">
                            <label class="form-label">Σημειώσεις Επιστροφής</label>
                            <textarea class="form-control" name="notes" rows="2"></textarea>
                        </div>

                        <button type="submit" class="btn btn-warning w-100">
                            <i class="bi bi-arrow-return-left me-1"></i>Επιστροφή Οχήματος
                        </button>
                    </form>
                </div>
            </div>
        <?php elseif ($selectedVehicle): ?>
            <!-- Checkout form for the selected vehicle -->
            <div class="card mb-4">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0"><i class="bi bi-box-arrow-right me-2"></i>Παραλαβή: <?= h($selectedVehicle['label']) ?></h5>
                </div>
                <div class="card-body">
                    <?php if ($selectedVehicle['status'] !== 'available'): ?>
                        <div class="alert alert-warning">Το όχημα δεν είναι διαθέσιμο αυτή τη στιγμή (<?= h(getVehicleStatuses()[$selectedVehicle['status']] ?? $selectedVehicle['status']) ?>).</div>
                    <?php endif; ?>
                    <form method="post">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="checkout">
                        <input type="hidden" name="vehicle_id" value="<?= $selectedVehicle['id'] ?>">

                        <div class="row">
                            <div class="col-7 mb-3">
                                <label class="form-label">Οδόμετρο κατά την παραλαβή</label>
                                <input type="number" step="0.1" min="0" class="form-control" name="km"
                                       value="<?= h($selectedVehicle['odometer_value'] ?? '') ?>"
                                       placeholder="<?= $selectedVehicle['odometer_unit'] === 'engine_hours' ? 'ώρες' : 'km' ?>">
                            </div>
                            <div class="col-5 mb-3">
                                <label class="form-label">Καύσιμο %</label>
                                <input type="number" min="0" max="100" class="form-control" name="fuel_level">
                            </div>
                        </div>

                        <?php if (!empty($shiftsForPicker)): ?>
                        <div class="mb-3">
                            <label class="form-label">Βάρδια (προαιρετικό)</label>
                            <select class="form-select" name="shift_id">
                                <option value="">— Καμία —</option>
                                <?php foreach ($shiftsForPicker as $sh): ?>
                                    <option value="<?= $sh['id'] ?>">
                                        <?= h($sh['mission_title']) ?> — <?= formatDateTime($sh['start_time']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($selectedChecklistItems)): ?>
                        <div class="mb-3">
                            <label class="form-label">Checklist Εξοπλισμού</label>
                            <table class="table table-sm">
                                <thead><tr><th>Στοιχείο</th><th>Κατάσταση</th><th>Σημείωση</th></tr></thead>
                                <tbody>
                                    <?php foreach ($selectedChecklistItems as $ci): ?>
                                        <tr>
                                            <td><?= h($ci['label']) ?></td>
                                            <td>
                                                <select class="form-select form-select-sm" name="checks[<?= $ci['id'] ?>][status]">
                                                    <?php foreach ($checklistStatuses as $sk => $sl): ?>
                                                        <option value="<?= $sk ?>" <?= $sk === 'ok' ? 'selected' : '' ?>><?= h($sl) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </td>
                                            <td>
                                                <input type="text" class="form-control form-control-sm" name="checks[<?= $ci['id'] ?>][note]">
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php endif; ?>

                        <div class="mb-3">
                            <label class="form-label">Σημειώσεις Παραλαβής</label>
                            <textarea class="form-control" name="notes" rows="2"></textarea>
                        </div>

                        <button type="submit" class="btn btn-success w-100">
                            <i class="bi bi-box-arrow-right me-1"></i>Παραλαβή Οχήματος
                        </button>
                    </form>
                </div>
            </div>
        <?php else: ?>
            <!-- No vehicle selected: pick one to check out -->
            <div class="card mb-4">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0"><i class="bi bi-box-arrow-right me-2"></i>Παραλαβή Οχήματος</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($checkoutCandidates)): ?>
                        <p class="text-muted mb-0">Δεν υπάρχουν διαθέσιμα οχήματα προς παραλαβή.</p>
                    <?php else: ?>
                        <form method="get" class="d-flex gap-2">
                            <select class="form-select" name="vehicle_id" required>
                                <option value="">— Επιλέξτε όχημα —</option>
                                <?php foreach ($checkoutCandidates as $veh): ?>
                                    <option value="<?= $veh['id'] ?>"><?= h($veh['label']) ?> (<?= h($veh['plate_number'] ?? '') ?>)</option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="btn btn-success flex-shrink-0">Συνέχεια</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-5">
        <div class="card">
            <div class="card-header bg-warning">
                <h5 class="mb-0"><i class="bi bi-box-arrow-in-left me-2"></i>Οχήματα Εκτός (<?= count($openHandovers) ?>)</h5>
            </div>
            <div class="card-body p-0">
                <?php if (empty($openHandovers)): ?>
                    <p class="text-muted text-center py-4">Δεν υπάρχουν οχήματα εκτός αυτή τη στιγμή.</p>
                <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($openHandovers as $oh): ?>
                            <a href="?vehicle_id=<?= $oh['vehicle_id'] ?>" class="list-group-item list-group-item-action">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <strong><i class="bi <?= getVehicleTypeIcon($oh['vehicle_type']) ?> me-1"></i><?= h($oh['vehicle_label']) ?></strong>
                                        <br><small class="text-muted">
                                            <?= h($oh['plate_number']) ?> — από <?= formatDateTime($oh['checkout_at']) ?>
                                        </small>
                                    </div>
                                    <span class="badge bg-warning text-dark">Εκτός</span>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
