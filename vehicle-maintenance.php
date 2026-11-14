<?php
/**
 * VolunteerOps - Vehicle Maintenance Log
 * Opens and closes maintenance tickets with cost. Opening one moves the
 * vehicle to status='maintenance'; closing the last open one for a vehicle
 * returns it to 'available' - see includes/fleet-functions.php.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/fleet-functions.php';
requireLogin();

if (!canManageFleet()) {
    setFlash('error', 'Δεν έχετε δικαίωμα πρόσβασης.');
    redirect('dashboard.php');
}

$pageTitle = 'Συντήρηση Οχημάτων';
$maintenanceTypes = getMaintenanceTypes();

if (isPost()) {
    verifyCsrf();
    $action = post('action');

    if ($action === 'open') {
        $vehicleId = (int) post('vehicle_id');
        $vehicle = dbFetchOne("SELECT * FROM vehicles WHERE id = ?", [$vehicleId]);
        if (!$vehicle) {
            setFlash('error', 'Το όχημα δεν βρέθηκε.');
            redirect('vehicle-maintenance.php');
        }
        $type = post('type', 'repair');
        $description = trim(post('description'));
        $cost = post('cost') !== '' ? (float) post('cost') : null;
        $workshop = trim(post('workshop'));
        $odometer = post('odometer_value') !== '' ? (float) post('odometer_value') : null;

        $result = openMaintenance($vehicleId, $type, $description, $cost, $workshop, $odometer, getCurrentUser()['id']);
        logAudit('vehicle_maintenance_open', 'vehicle_maintenance', $result['maintenance_id']);
        setFlash('success', 'Η εργασία συντήρησης καταχωρήθηκε για το «' . $vehicle['label'] . '».');
        redirect('vehicle-view.php?id=' . $vehicleId);
    }

    if ($action === 'close') {
        $maintenanceId = (int) post('maintenance_id');
        $notes = trim(post('notes'));
        $cost = post('cost') !== '' ? (float) post('cost') : null;
        $record = dbFetchOne("SELECT vehicle_id FROM vehicle_maintenance WHERE id = ?", [$maintenanceId]);

        $result = closeMaintenance($maintenanceId, getCurrentUser()['id'], $notes, $cost);
        if ($result['success']) {
            logAudit('vehicle_maintenance_close', 'vehicle_maintenance', $maintenanceId);
            setFlash('success', 'Η εργασία συντήρησης ολοκληρώθηκε.');
        } else {
            setFlash('error', $result['error']);
        }
        redirect($record ? 'vehicle-view.php?id=' . $record['vehicle_id'] : 'vehicle-maintenance.php');
    }
}

$openRecords = getAllOpenMaintenanceRecords();
$vehicles = getAllActiveVehicles();

// Preselected vehicle, from vehicle-view.php's "new maintenance" link
$preselectedVehicleId = (int) get('vehicle_id', 0);

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
    <h1 class="h3 mb-0">
        <i class="bi bi-wrench-adjustable me-2"></i><?= h($pageTitle) ?>
    </h1>
    <a href="vehicles.php" class="btn btn-outline-secondary">
        <i class="bi bi-arrow-left me-1"></i>Οχήματα
    </a>
</div>

<?= showFlash() ?>

<div class="row">
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header bg-warning">
                <h5 class="mb-0"><i class="bi bi-tools me-2"></i>Ανοιχτές Εργασίες (<?= count($openRecords) ?>)</h5>
            </div>
            <div class="card-body p-0">
                <?php if (empty($openRecords)): ?>
                    <p class="text-muted text-center py-4 mb-0">Δεν υπάρχουν ανοιχτές εργασίες συντήρησης.</p>
                <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($openRecords as $rec): ?>
                            <div class="list-group-item">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <div>
                                        <strong><?= h($rec['vehicle_label']) ?></strong>
                                        <span class="badge bg-secondary ms-1"><?= h($maintenanceTypes[$rec['type']] ?? $rec['type']) ?></span>
                                        <br><small class="text-muted"><?= h($rec['plate_number']) ?> — από <?= formatDateTime($rec['opened_at']) ?></small>
                                    </div>
                                </div>
                                <?php if ($rec['description']): ?><p class="small mb-2"><?= nl2br(h($rec['description'])) ?></p><?php endif; ?>
                                <form method="post" class="d-flex gap-2 flex-wrap align-items-end">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="close">
                                    <input type="hidden" name="maintenance_id" value="<?= $rec['id'] ?>">
                                    <div>
                                        <label class="form-label small mb-0">Κόστος €</label>
                                        <input type="number" step="0.01" min="0" class="form-control form-control-sm" name="cost"
                                               value="<?= h($rec['cost'] ?? '') ?>" style="width:100px">
                                    </div>
                                    <div class="flex-fill">
                                        <label class="form-label small mb-0">Σημείωση ολοκλήρωσης</label>
                                        <input type="text" class="form-control form-control-sm" name="notes">
                                    </div>
                                    <button type="submit" class="btn btn-sm btn-success">
                                        <i class="bi bi-check-lg me-1"></i>Ολοκλήρωση
                                    </button>
                                </form>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card">
            <div class="card-header bg-success text-white">
                <h5 class="mb-0"><i class="bi bi-plus-lg me-2"></i>Νέα Εργασία Συντήρησης</h5>
            </div>
            <div class="card-body">
                <form method="post">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="open">

                    <div class="mb-3">
                        <label class="form-label">Όχημα *</label>
                        <select class="form-select" name="vehicle_id" required>
                            <option value="">— Επιλέξτε όχημα —</option>
                            <?php foreach ($vehicles as $veh): ?>
                                <option value="<?= $veh['id'] ?>" <?= $preselectedVehicleId == $veh['id'] ? 'selected' : '' ?>>
                                    <?= h($veh['label']) ?> (<?= h($veh['plate_number']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Τύπος</label>
                        <select class="form-select" name="type">
                            <?php foreach ($maintenanceTypes as $key => $label): ?>
                                <option value="<?= $key ?>"><?= h($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Περιγραφή</label>
                        <textarea class="form-control" name="description" rows="2" placeholder="π.χ. Αλλαγή λαδιών, φθορά φαναριού..."></textarea>
                    </div>

                    <div class="row">
                        <div class="col-6 mb-3">
                            <label class="form-label">Συνεργείο</label>
                            <input type="text" class="form-control" name="workshop">
                        </div>
                        <div class="col-6 mb-3">
                            <label class="form-label">Κόστος € (αν γνωστό)</label>
                            <input type="number" step="0.01" min="0" class="form-control" name="cost">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Οδόμετρο</label>
                        <input type="number" step="0.1" min="0" class="form-control" name="odometer_value">
                    </div>

                    <button type="submit" class="btn btn-success w-100">
                        <i class="bi bi-wrench-adjustable me-1"></i>Καταχώρηση
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
