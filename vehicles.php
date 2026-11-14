<?php
/**
 * VolunteerOps - Fleet (Vehicles) Management
 * Admin page to manage the vehicle fleet: cars, motorbikes, vans, boats...
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/fleet-functions.php';
requireLogin();

if (!canViewFleet()) {
    setFlash('error', 'Δεν έχετε δικαίωμα πρόσβασης.');
    redirect('dashboard.php');
}

$canManage = canManageFleet();
$pageTitle = 'Στόλος Οχημάτων';

$vehicleTypes    = getVehicleTypes();
$vehicleStatuses = getVehicleStatuses();

$departments = dbFetchAll("SELECT id, name FROM departments WHERE is_active = 1 ORDER BY name");

// Handle POST actions
if (isPost()) {
    if (!$canManage) {
        setFlash('error', 'Δεν έχετε δικαίωμα διαχείρισης του στόλου.');
        redirect('vehicles.php');
    }
    verifyCsrf();
    $action = post('action');

    if ($action === 'create' || $action === 'update') {
        $plateNumber   = trim(post('plate_number'));
        $label         = trim(post('label'));
        $type          = post('type', 'car');
        $departmentId  = post('department_id') ?: null;
        $odometerValue = post('odometer_value') !== '' ? (float) post('odometer_value') : null;
        $odometerUnit  = post('odometer_unit', 'km');
        $fuelType      = trim(post('fuel_type'));
        $seats         = post('seats') ? (int) post('seats') : null;
        $status        = post('status', 'available');
        $notes         = trim(post('notes'));

        if (!array_key_exists($type, $vehicleTypes)) {
            $type = 'car';
        }
        if (!array_key_exists($status, $vehicleStatuses)) {
            $status = 'available';
        }
        if (!in_array($odometerUnit, ['km', 'engine_hours'], true)) {
            $odometerUnit = 'km';
        }

        if (empty($plateNumber) || empty($label)) {
            setFlash('error', 'Η πινακίδα και η ονομασία είναι υποχρεωτικά.');
        } else {
            try {
                if ($action === 'create') {
                    $newId = dbInsert(
                        "INSERT INTO vehicles
                         (plate_number, label, type, department_id, odometer_value, odometer_unit, fuel_type, seats, status, notes, created_by)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                        [$plateNumber, $label, $type, $departmentId, $odometerValue, $odometerUnit, $fuelType, $seats, $status, $notes, getCurrentUser()['id']]
                    );
                    logAudit('vehicle_create', 'vehicles', $newId);
                    setFlash('success', 'Το όχημα δημιουργήθηκε: ' . $label);
                } else {
                    $vehicleId = (int) post('vehicle_id');
                    dbExecute(
                        "UPDATE vehicles
                         SET plate_number = ?, label = ?, type = ?, department_id = ?, odometer_value = ?, odometer_unit = ?,
                             fuel_type = ?, seats = ?, status = ?, notes = ?
                         WHERE id = ?",
                        [$plateNumber, $label, $type, $departmentId, $odometerValue, $odometerUnit, $fuelType, $seats, $status, $notes, $vehicleId]
                    );
                    logAudit('vehicle_update', 'vehicles', $vehicleId);
                    setFlash('success', 'Το όχημα ενημερώθηκε.');
                }
            } catch (Exception $e) {
                $isDuplicate = str_contains($e->getMessage(), 'unique_plate_number');
                setFlash('error', $isDuplicate
                    ? 'Υπάρχει ήδη όχημα με αυτή την πινακίδα.'
                    : 'Σφάλμα αποθήκευσης οχήματος.');
            }
        }
        redirect('vehicles.php');
    }

    if ($action === 'toggle') {
        $vehicleId = (int) post('vehicle_id');
        $isActive  = (int) post('is_active');
        dbExecute("UPDATE vehicles SET is_active = ? WHERE id = ?", [$isActive, $vehicleId]);
        logAudit('vehicle_toggle', 'vehicles', $vehicleId);
        setFlash('success', $isActive ? 'Το όχημα ενεργοποιήθηκε.' : 'Το όχημα απενεργοποιήθηκε.');
        redirect('vehicles.php');
    }

    if ($action === 'delete') {
        $vehicleId = (int) post('vehicle_id');
        $assignmentCount = (int) dbFetchValue("SELECT COUNT(*) FROM shift_vehicles WHERE vehicle_id = ?", [$vehicleId]);
        if ($assignmentCount > 0) {
            setFlash('error', 'Δεν μπορεί να διαγραφεί το όχημα γιατί έχει ' . $assignmentCount . ' αναθέσεις σε βάρδιες. Απενεργοποιήστε το όχημα αντί να το διαγράψετε.');
        } else {
            $veh = dbFetchOne("SELECT label FROM vehicles WHERE id = ?", [$vehicleId]);
            dbExecute("DELETE FROM vehicles WHERE id = ?", [$vehicleId]);
            logAudit('vehicle_delete', 'vehicles', $vehicleId);
            setFlash('success', 'Το όχημα «' . ($veh['label'] ?? '') . '» διαγράφηκε.');
        }
        redirect('vehicles.php');
    }
}

// Fetch all vehicles (including inactive for admin)
$vehicles = dbFetchAll("
    SELECT v.*, d.name AS department_name,
           (SELECT COUNT(*) FROM shift_vehicles sv WHERE sv.vehicle_id = v.id) AS assignment_count
    FROM vehicles v
    LEFT JOIN departments d ON v.department_id = d.id
    ORDER BY v.label
");

// Editing mode
$editId = (int) get('edit', 0);
$editVehicle = null;
if ($editId) {
    foreach ($vehicles as $veh) {
        if ($veh['id'] == $editId) {
            $editVehicle = $veh;
            break;
        }
    }
}

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
    <h1 class="h3 mb-0">
        <i class="bi bi-truck me-2"></i><?= h($pageTitle) ?>
    </h1>
</div>

<?= showFlash() ?>

<div class="row">
    <!-- Left: Vehicles Table -->
    <div class="<?= $canManage ? 'col-lg-8' : 'col-lg-12' ?>">
        <?php if (empty($vehicles)): ?>
            <div class="card">
                <div class="card-body text-center py-5">
                    <i class="bi bi-truck text-muted" style="font-size: 3rem;"></i>
                    <p class="text-muted mt-3">Δεν υπάρχουν οχήματα.<?= $canManage ? ' Δημιουργήστε ένα.' : '' ?></p>
                </div>
            </div>
        <?php else: ?>
            <div class="card">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 admin-mobile-cards">
                            <thead>
                                <tr>
                                    <th>Όχημα</th>
                                    <th>Τύπος</th>
                                    <th>Παράρτημα</th>
                                    <th>Οδόμετρο</th>
                                    <th>Κατάσταση</th>
                                    <?php if ($canManage): ?><th></th><?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($vehicles as $veh): ?>
                                    <tr class="<?= !$veh['is_active'] ? 'table-secondary' : '' ?>">
                                        <td data-label="Όχημα">
                                            <strong><?= h($veh['label']) ?></strong>
                                            <br><small class="text-muted"><?= h($veh['plate_number']) ?></small>
                                        </td>
                                        <td data-label="Τύπος">
                                            <span class="badge bg-secondary">
                                                <i class="bi <?= getVehicleTypeIcon($veh['type']) ?> me-1"></i><?= h($vehicleTypes[$veh['type']] ?? $veh['type']) ?>
                                            </span>
                                        </td>
                                        <td data-label="Παράρτημα">
                                            <?= $veh['department_name'] ? h($veh['department_name']) : '<span class="text-muted">-</span>' ?>
                                        </td>
                                        <td data-label="Οδόμετρο">
                                            <?php if ($veh['odometer_value'] !== null): ?>
                                                <?= number_format((float) $veh['odometer_value'], 1) ?> <?= $veh['odometer_unit'] === 'engine_hours' ? 'ώρες' : 'km' ?>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="Κατάσταση">
                                            <?php
                                            $statusBadge = ['available' => 'success', 'maintenance' => 'warning', 'out_of_service' => 'danger'][$veh['status']] ?? 'secondary';
                                            ?>
                                            <span class="badge bg-<?= $statusBadge ?>"><?= h($vehicleStatuses[$veh['status']] ?? $veh['status']) ?></span>
                                            <?php if (!$veh['is_active']): ?>
                                                <span class="badge bg-secondary">Ανενεργό</span>
                                            <?php endif; ?>
                                        </td>
                                        <?php if ($canManage): ?>
                                        <td data-label="Ενέργειες" class="text-end mobile-card-actions">
                                            <div class="btn-group btn-group-sm">
                                                <a href="vehicle-view.php?id=<?= $veh['id'] ?>" class="btn btn-outline-secondary" title="Λεπτομέρειες">
                                                    <i class="bi bi-journal-text"></i>
                                                </a>
                                                <a href="?edit=<?= $veh['id'] ?>" class="btn btn-outline-secondary" title="Επεξεργασία">
                                                    <i class="bi bi-pencil"></i>
                                                </a>
                                                <form method="post" class="d-inline">
                                                    <?= csrfField() ?>
                                                    <input type="hidden" name="action" value="toggle">
                                                    <input type="hidden" name="vehicle_id" value="<?= $veh['id'] ?>">
                                                    <input type="hidden" name="is_active" value="<?= $veh['is_active'] ? 0 : 1 ?>">
                                                    <button type="submit" class="btn btn-outline-<?= $veh['is_active'] ? 'warning' : 'success' ?>"
                                                            title="<?= $veh['is_active'] ? 'Απενεργοποίηση' : 'Ενεργοποίηση' ?>"
                                                            onclick="return confirm('<?= $veh['is_active'] ? 'Απενεργοποίηση' : 'Ενεργοποίηση' ?> οχήματος;')">
                                                        <i class="bi bi-<?= $veh['is_active'] ? 'pause' : 'play' ?>"></i>
                                                    </button>
                                                </form>
                                                <form method="post" class="d-inline">
                                                    <?= csrfField() ?>
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="vehicle_id" value="<?= $veh['id'] ?>">
                                                    <button type="submit" class="btn btn-outline-danger"
                                                            title="Διαγραφή"
                                                            onclick="return confirm('Διαγραφή οχήματος «<?= h($veh['label']) ?>»;\n<?= $veh['assignment_count'] > 0 ? 'Έχει ' . $veh['assignment_count'] . ' αναθέσεις σε βάρδιες!' : 'Δεν έχει αναθέσεις.' ?>')">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($canManage): ?>
    <!-- Right: Create / Edit Form -->
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header bg-success text-white">
                <h5 class="mb-0">
                    <i class="bi bi-<?= $editVehicle ? 'pencil' : 'plus-lg' ?> me-1"></i>
                    <?= $editVehicle ? 'Επεξεργασία Οχήματος' : 'Νέο Όχημα' ?>
                </h5>
            </div>
            <div class="card-body">
                <form method="post">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="<?= $editVehicle ? 'update' : 'create' ?>">
                    <?php if ($editVehicle): ?>
                        <input type="hidden" name="vehicle_id" value="<?= $editVehicle['id'] ?>">
                    <?php endif; ?>

                    <div class="mb-3">
                        <label class="form-label">Πινακίδα *</label>
                        <input type="text" class="form-control" name="plate_number" required
                               value="<?= h($editVehicle['plate_number'] ?? '') ?>"
                               placeholder="π.χ. ΗΡΑ-1234">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Ονομασία *</label>
                        <input type="text" class="form-control" name="label" required
                               value="<?= h($editVehicle['label'] ?? '') ?>"
                               placeholder="π.χ. Όχημα 1 - Ford Ranger">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Τύπος</label>
                        <select class="form-select" name="type">
                            <?php foreach ($vehicleTypes as $key => $label): ?>
                                <option value="<?= $key ?>" <?= ($editVehicle['type'] ?? 'car') === $key ? 'selected' : '' ?>>
                                    <?= h($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Παράρτημα</label>
                        <select class="form-select" name="department_id">
                            <option value="">— Κανένα —</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?= $dept['id'] ?>" <?= ($editVehicle['department_id'] ?? '') == $dept['id'] ? 'selected' : '' ?>>
                                    <?= h($dept['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="row">
                        <div class="col-7 mb-3">
                            <label class="form-label">Οδόμετρο</label>
                            <input type="number" step="0.1" min="0" class="form-control" name="odometer_value"
                                   value="<?= h($editVehicle['odometer_value'] ?? '') ?>">
                        </div>
                        <div class="col-5 mb-3">
                            <label class="form-label">Μονάδα</label>
                            <select class="form-select" name="odometer_unit">
                                <option value="km" <?= ($editVehicle['odometer_unit'] ?? 'km') === 'km' ? 'selected' : '' ?>>km</option>
                                <option value="engine_hours" <?= ($editVehicle['odometer_unit'] ?? '') === 'engine_hours' ? 'selected' : '' ?>>ώρες κινητήρα</option>
                            </select>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-7 mb-3">
                            <label class="form-label">Καύσιμο</label>
                            <input type="text" class="form-control" name="fuel_type"
                                   value="<?= h($editVehicle['fuel_type'] ?? '') ?>"
                                   placeholder="π.χ. πετρέλαιο">
                        </div>
                        <div class="col-5 mb-3">
                            <label class="form-label">Θέσεις</label>
                            <input type="number" min="0" class="form-control" name="seats"
                                   value="<?= h($editVehicle['seats'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Κατάσταση</label>
                        <select class="form-select" name="status">
                            <?php foreach ($vehicleStatuses as $key => $label): ?>
                                <option value="<?= $key ?>" <?= ($editVehicle['status'] ?? 'available') === $key ? 'selected' : '' ?>>
                                    <?= h($label) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Σε συντήρηση/εκτός λειτουργίας: δεν μπορεί να ανατεθεί νέα βάρδια.</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Σημειώσεις</label>
                        <textarea class="form-control" name="notes" rows="2"><?= h($editVehicle['notes'] ?? '') ?></textarea>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-success flex-fill">
                            <i class="bi bi-check-lg me-1"></i><?= $editVehicle ? 'Ενημέρωση' : 'Δημιουργία' ?>
                        </button>
                        <?php if ($editVehicle): ?>
                            <a href="vehicles.php" class="btn btn-outline-secondary">Ακύρωση</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<style>
@media (max-width: 767.98px) {
    .admin-mobile-cards thead { display: none; }
    .admin-mobile-cards, .admin-mobile-cards tbody, .admin-mobile-cards tr, .admin-mobile-cards td { display: block; width: 100%; }
    .admin-mobile-cards tr { margin: .75rem; width: calc(100% - 1.5rem); padding: .75rem; border: 1px solid var(--bs-border-color); border-radius: .75rem; background: var(--bs-body-bg); }
    .admin-mobile-cards td { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; padding: .45rem 0; border: 0; text-align: right !important; }
    .admin-mobile-cards td::before { content: attr(data-label); flex: 0 0 38%; color: var(--bs-secondary-color); font-weight: 600; text-align: left; }
    .admin-mobile-cards .mobile-card-actions { align-items: center; padding-top: .75rem; border-top: 1px solid var(--bs-border-color); }
}
</style>

<?php include __DIR__ . '/includes/footer.php'; ?>
