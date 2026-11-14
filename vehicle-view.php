<?php
/**
 * VolunteerOps - Vehicle Detail / Logbook
 * Shows one vehicle's info, current handover status, and its logbook
 * (handovers for now; refuels and maintenance join the same logbook in M3).
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/fleet-functions.php';
requireLogin();

if (!canViewFleet()) {
    setFlash('error', 'Δεν έχετε δικαίωμα πρόσβασης.');
    redirect('dashboard.php');
}

$id = (int) get('id');
$vehicle = dbFetchOne("SELECT v.*, d.name AS department_name FROM vehicles v LEFT JOIN departments d ON v.department_id = d.id WHERE v.id = ?", [$id]);
if (!$vehicle) {
    setFlash('error', 'Το όχημα δεν βρέθηκε.');
    redirect('vehicles.php');
}

$pageTitle = $vehicle['label'] . ' — Όχημα';
$vehicleTypes = getVehicleTypes();
$vehicleStatuses = getVehicleStatuses();
$checklistStatuses = getChecklistStatuses();

$openHandover = getOpenHandover($id);
$history = getVehicleHandoverHistory($id);

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-4">
    <h1 class="h3 mb-0">
        <i class="bi <?= getVehicleTypeIcon($vehicle['type']) ?> me-2"></i><?= h($vehicle['label']) ?>
    </h1>
    <div class="d-flex gap-2">
        <?php if (canManageFleet()): ?>
            <a href="vehicles.php?edit=<?= $id ?>" class="btn btn-outline-secondary"><i class="bi bi-pencil me-1"></i>Επεξεργασία</a>
        <?php endif; ?>
        <a href="vehicles.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Οχήματα</a>
    </div>
</div>

<?= showFlash() ?>

<div class="row">
    <div class="col-lg-4">
        <div class="card mb-4">
            <div class="card-body">
                <p><strong>Πινακίδα:</strong> <?= h($vehicle['plate_number']) ?></p>
                <p><strong>Τύπος:</strong> <?= h($vehicleTypes[$vehicle['type']] ?? $vehicle['type']) ?></p>
                <p><strong>Παράρτημα:</strong> <?= $vehicle['department_name'] ? h($vehicle['department_name']) : '-' ?></p>
                <p><strong>Οδόμετρο:</strong>
                    <?= $vehicle['odometer_value'] !== null ? number_format((float) $vehicle['odometer_value'], 1) . ' ' . ($vehicle['odometer_unit'] === 'engine_hours' ? 'ώρες' : 'km') : '-' ?>
                </p>
                <p><strong>Κατάσταση:</strong>
                    <?php $statusBadge = ['available' => 'success', 'maintenance' => 'warning', 'out_of_service' => 'danger'][$vehicle['status']] ?? 'secondary'; ?>
                    <span class="badge bg-<?= $statusBadge ?>"><?= h($vehicleStatuses[$vehicle['status']] ?? $vehicle['status']) ?></span>
                </p>
                <?php if ($vehicle['notes']): ?>
                    <p class="mb-0"><strong>Σημειώσεις:</strong><br><?= nl2br(h($vehicle['notes'])) ?></p>
                <?php endif; ?>
            </div>
        </div>

        <?php if (canManageFleet()): ?>
        <div class="card mb-4">
            <div class="card-body">
                <?php if ($openHandover): ?>
                    <p class="mb-2"><span class="badge bg-warning text-dark">Εκτός</span> από <?= formatDateTime($openHandover['checkout_at']) ?></p>
                    <a href="vehicle-handover.php?vehicle_id=<?= $id ?>" class="btn btn-warning w-100">
                        <i class="bi bi-box-arrow-in-left me-1"></i>Επιστροφή Οχήματος
                    </a>
                <?php else: ?>
                    <p class="mb-2"><span class="badge bg-success">Διαθέσιμο στη βάση</span></p>
                    <a href="vehicle-handover.php?vehicle_id=<?= $id ?>" class="btn btn-success w-100">
                        <i class="bi bi-box-arrow-right me-1"></i>Παραλαβή Οχήματος
                    </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-journal-text me-2"></i>Ημερολόγιο Οχήματος</h5>
            </div>
            <div class="card-body p-0">
                <?php if (empty($history)): ?>
                    <p class="text-muted text-center py-4 mb-0">Δεν υπάρχουν καταχωρήσεις ακόμα.</p>
                <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($history as $h):
                            $checks = getHandoverChecks($h['id']);
                            $flaggedChecks = array_filter($checks, fn($c) => $c['status'] !== 'ok' && $c['stage'] === 'checkin');
                        ?>
                            <div class="list-group-item">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <strong><i class="bi bi-box-arrow-right me-1"></i>Παραλαβή</strong> — <?= formatDateTime($h['checkout_at']) ?>
                                        <?php if ($h['checkout_km'] !== null): ?> · <?= number_format((float) $h['checkout_km'], 1) ?> <?= $vehicle['odometer_unit'] === 'engine_hours' ? 'ώρες' : 'km' ?><?php endif; ?>
                                        <?php if ($h['checkout_fuel_level'] !== null): ?> · καύσιμο <?= (int) $h['checkout_fuel_level'] ?>%<?php endif; ?>
                                    </div>
                                    <?php if (!$h['checkin_at']): ?>
                                        <span class="badge bg-warning text-dark">Εκτός</span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($h['checkout_notes']): ?><div class="text-muted small"><?= h($h['checkout_notes']) ?></div><?php endif; ?>

                                <?php if ($h['checkin_at']): ?>
                                    <div class="mt-2">
                                        <strong><i class="bi bi-box-arrow-in-left me-1"></i>Επιστροφή</strong> — <?= formatDateTime($h['checkin_at']) ?>
                                        <?php if ($h['checkin_km'] !== null): ?> · <?= number_format((float) $h['checkin_km'], 1) ?> <?= $vehicle['odometer_unit'] === 'engine_hours' ? 'ώρες' : 'km' ?>
                                            <?php if ($h['checkout_km'] !== null): ?>
                                                <span class="text-muted">(+<?= number_format((float) $h['checkin_km'] - (float) $h['checkout_km'], 1) ?>)</span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        <?php if ($h['checkin_fuel_level'] !== null): ?> · καύσιμο <?= (int) $h['checkin_fuel_level'] ?>%<?php endif; ?>
                                    </div>
                                    <?php if ($h['checkin_notes']): ?><div class="text-muted small"><?= h($h['checkin_notes']) ?></div><?php endif; ?>
                                <?php endif; ?>

                                <?php if (!empty($flaggedChecks)): ?>
                                    <div class="mt-2">
                                        <?php foreach ($flaggedChecks as $fc): ?>
                                            <span class="badge bg-danger me-1">
                                                <?= h($fc['item_label']) ?>: <?= h($checklistStatuses[$fc['status']] ?? $fc['status']) ?>
                                            </span>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
