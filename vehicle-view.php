<?php
/**
 * VolunteerOps - Vehicle Detail / Logbook
 * Shows one vehicle's info, current handover + maintenance status, cost
 * summary, and its combined logbook (handovers, refuels, maintenance).
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

if (isPost() && canManageFleet() && post('action') === 'add_refuel') {
    verifyCsrf();
    $liters = post('liters') !== '' ? (float) post('liters') : null;
    $cost = post('cost') !== '' ? (float) post('cost') : null;
    $odometer = post('odometer_value') !== '' ? (float) post('odometer_value') : null;
    $station = trim(post('station'));

    addRefuel($id, $liters, $cost, $odometer, $station, getCurrentUser()['id']);
    setFlash('success', 'Ο ανεφοδιασμός καταχωρήθηκε.');
    redirect('vehicle-view.php?id=' . $id);
}

if (isPost() && canManageFleet() && post('action') === 'issue_ping_token') {
    verifyCsrf();
    $rawToken = issueVehiclePingToken($id, trim(post('label')));
    // Shown once, in the flash message only - the hash stored in the DB can't
    // reproduce it, same one-time-reveal rule as every other token in this app.
    // displayFlash() HTML-escapes the message, so this stays plain text.
    setFlash('success', 'Νέο token καταγραφής θέσης: ' . $rawToken . ' — Αντιγράψτε το τώρα, δεν θα εμφανιστεί ξανά.');
    redirect('vehicle-view.php?id=' . $id);
}

if (isPost() && canManageFleet() && post('action') === 'revoke_ping_token') {
    verifyCsrf();
    revokeVehiclePingToken($id);
    setFlash('success', 'Το token καταγραφής θέσης ανακλήθηκε.');
    redirect('vehicle-view.php?id=' . $id);
}

$pageTitle = $vehicle['label'] . ' — Όχημα';
$vehicleTypes = getVehicleTypes();
$vehicleStatuses = getVehicleStatuses();
$checklistStatuses = getChecklistStatuses();
$maintenanceTypes = getMaintenanceTypes();
$odoUnit = $vehicle['odometer_unit'] === 'engine_hours' ? 'ώρες' : 'km';

$openHandover = getOpenHandover($id);
$openMaintenance = getOpenMaintenanceRecords($id);
$costSummary = getVehicleCostSummary($id);
$pingTokenInfo = getVehiclePingTokenInfo($id);
$latestPing = getLatestVehiclePing($id);
$route = getVehicleRoute($id, 24);

// Combined logbook: handovers, refuels and maintenance, merged by time.
$events = [];
foreach (getVehicleHandoverHistory($id) as $h) {
    $events[] = ['type' => 'handover', 'time' => $h['checkout_at'], 'data' => $h];
}
foreach (getVehicleRefuels($id) as $r) {
    $events[] = ['type' => 'refuel', 'time' => $r['created_at'], 'data' => $r];
}
foreach (getVehicleMaintenanceHistory($id) as $m) {
    $events[] = ['type' => 'maintenance', 'time' => $m['opened_at'], 'data' => $m];
}
usort($events, fn($a, $b) => strcmp($b['time'], $a['time']));

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
                    <?= $vehicle['odometer_value'] !== null ? number_format((float) $vehicle['odometer_value'], 1) . ' ' . $odoUnit : '-' ?>
                </p>
                <p class="mb-0"><strong>Κατάσταση:</strong>
                    <?php $statusBadge = ['available' => 'success', 'maintenance' => 'warning', 'out_of_service' => 'danger'][$vehicle['status']] ?? 'secondary'; ?>
                    <span class="badge bg-<?= $statusBadge ?>"><?= h($vehicleStatuses[$vehicle['status']] ?? $vehicle['status']) ?></span>
                </p>
                <?php if ($vehicle['notes']): ?>
                    <p class="mb-0 mt-2"><strong>Σημειώσεις:</strong><br><?= nl2br(h($vehicle['notes'])) ?></p>
                <?php endif; ?>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header"><h6 class="mb-0"><i class="bi bi-cash-coin me-2"></i>Κόστος</h6></div>
            <div class="card-body">
                <p class="mb-1">Καύσιμα: <strong><?= number_format($costSummary['fuel_cost'], 2) ?> €</strong>
                    <span class="text-muted">(<?= number_format($costSummary['fuel_liters'], 1) ?> L)</span></p>
                <p class="mb-1">Συντήρηση: <strong><?= number_format($costSummary['maintenance_cost'], 2) ?> €</strong></p>
                <p class="mb-0 border-top pt-2">Σύνολο: <strong><?= number_format($costSummary['total_cost'], 2) ?> €</strong></p>
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

        <?php if (!empty($openMaintenance)): ?>
        <div class="card mb-4 border-warning">
            <div class="card-header bg-warning"><h6 class="mb-0"><i class="bi bi-tools me-2"></i>Ανοιχτή Συντήρηση</h6></div>
            <div class="card-body">
                <?php foreach ($openMaintenance as $m): ?>
                    <p class="mb-1"><?= h($maintenanceTypes[$m['type']] ?? $m['type']) ?> — <?= formatDateTime($m['opened_at']) ?></p>
                <?php endforeach; ?>
                <a href="vehicle-maintenance.php" class="btn btn-sm btn-outline-warning w-100 mt-1">Διαχείριση στο Συντήρηση Οχημάτων</a>
            </div>
        </div>
        <?php endif; ?>

        <div class="card mb-4">
            <div class="card-header"><h6 class="mb-0"><i class="bi bi-fuel-pump me-2"></i>Νέος Ανεφοδιασμός</h6></div>
            <div class="card-body">
                <form method="post">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="add_refuel">
                    <div class="row">
                        <div class="col-6 mb-2">
                            <label class="form-label small mb-0">Λίτρα</label>
                            <input type="number" step="0.01" min="0" class="form-control form-control-sm" name="liters">
                        </div>
                        <div class="col-6 mb-2">
                            <label class="form-label small mb-0">Κόστος €</label>
                            <input type="number" step="0.01" min="0" class="form-control form-control-sm" name="cost">
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small mb-0">Οδόμετρο</label>
                        <input type="number" step="0.1" min="0" class="form-control form-control-sm" name="odometer_value"
                               value="<?= h($vehicle['odometer_value'] ?? '') ?>">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small mb-0">Πρατήριο</label>
                        <input type="text" class="form-control form-control-sm" name="station">
                    </div>
                    <button type="submit" class="btn btn-sm btn-primary w-100">Καταχώρηση</button>
                </form>
                <a href="vehicle-maintenance.php?vehicle_id=<?= $id ?>" class="btn btn-sm btn-outline-secondary w-100 mt-2">
                    <i class="bi bi-wrench-adjustable me-1"></i>Νέα Εργασία Συντήρησης
                </a>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header"><h6 class="mb-0"><i class="bi bi-broadcast me-2"></i>Καταγραφή Θέσης GPS</h6></div>
            <div class="card-body">
                <?php if ($pingTokenInfo): ?>
                    <p class="mb-1"><span class="badge bg-success">Ενεργό token</span>
                        <?= $pingTokenInfo['label'] ? h($pingTokenInfo['label']) : '' ?></p>
                    <p class="text-muted small mb-2">
                        Εκδόθηκε: <?= formatDateTime($pingTokenInfo['created_at']) ?><br>
                        Τελευταία χρήση: <?= $pingTokenInfo['last_used_at'] ? formatDateTime($pingTokenInfo['last_used_at']) : 'Ποτέ' ?>
                    </p>
                    <form method="post" onsubmit="return confirm('Ανάκληση token; Η πηγή που το χρησιμοποιεί θα σταματήσει να στέλνει θέση.');">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="revoke_ping_token">
                        <button type="submit" class="btn btn-sm btn-outline-danger w-100">Ανάκληση Token</button>
                    </form>
                <?php else: ?>
                    <p class="text-muted small">Χωρίς ενεργό token - δεν στέλνεται ακόμα θέση για αυτό το όχημα.</p>
                <?php endif; ?>
                <form method="post" class="mt-2" onsubmit="return confirm('<?= $pingTokenInfo ? 'Έκδοση νέου token; Το τρέχον θα ανακληθεί.' : 'Έκδοση token καταγραφής θέσης;' ?>');">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="issue_ping_token">
                    <input type="text" name="label" class="form-control form-control-sm mb-2" placeholder="Ετικέτα συσκευής (προαιρετικό)">
                    <button type="submit" class="btn btn-sm btn-primary w-100"><?= $pingTokenInfo ? 'Έκδοση Νέου Token' : 'Έκδοση Token' ?></button>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-8">
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-geo-alt me-2"></i>Διαδρομή 24ώρου</h5>
                <?php if ($latestPing): ?>
                    <span class="text-muted small">Τελευταία θέση: <?= formatDateTime($latestPing['created_at']) ?></span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if (empty($route)): ?>
                    <p class="text-muted text-center py-3 mb-0">
                        Δεν έχουν καταγραφεί θέσεις GPS τις τελευταίες 24 ώρες.
                        <?php if (canManageFleet()): ?>
                            Εκδώστε ένα token καταγραφής θέσης παρακάτω για να συνδέσετε μια πηγή GPS.
                        <?php endif; ?>
                    </p>
                <?php else: ?>
                    <div id="vehicleRouteMap" style="height: 350px; border-radius: 6px;"></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-journal-text me-2"></i>Ημερολόγιο Οχήματος</h5>
            </div>
            <div class="card-body p-0">
                <?php if (empty($events)): ?>
                    <p class="text-muted text-center py-4 mb-0">Δεν υπάρχουν καταχωρήσεις ακόμα.</p>
                <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($events as $event): ?>
                            <div class="list-group-item">
                                <?php if ($event['type'] === 'handover'):
                                    $h = $event['data'];
                                    $checks = getHandoverChecks($h['id']);
                                    $flaggedChecks = array_filter($checks, fn($c) => $c['status'] !== 'ok' && $c['stage'] === 'checkin');
                                ?>
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <strong><i class="bi bi-box-arrow-right me-1"></i>Παραλαβή</strong> — <?= formatDateTime($h['checkout_at']) ?>
                                            <?php if ($h['checkout_km'] !== null): ?> · <?= number_format((float) $h['checkout_km'], 1) ?> <?= $odoUnit ?><?php endif; ?>
                                            <?php if ($h['checkout_fuel_level'] !== null): ?> · καύσιμο <?= (int) $h['checkout_fuel_level'] ?>%<?php endif; ?>
                                        </div>
                                        <?php if (!$h['checkin_at']): ?><span class="badge bg-warning text-dark">Εκτός</span><?php endif; ?>
                                    </div>
                                    <?php if ($h['checkout_notes']): ?><div class="text-muted small"><?= h($h['checkout_notes']) ?></div><?php endif; ?>

                                    <?php if ($h['checkin_at']): ?>
                                        <div class="mt-2">
                                            <strong><i class="bi bi-box-arrow-in-left me-1"></i>Επιστροφή</strong> — <?= formatDateTime($h['checkin_at']) ?>
                                            <?php if ($h['checkin_km'] !== null): ?> · <?= number_format((float) $h['checkin_km'], 1) ?> <?= $odoUnit ?>
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

                                <?php elseif ($event['type'] === 'refuel'):
                                    $r = $event['data'];
                                ?>
                                    <div>
                                        <strong><i class="bi bi-fuel-pump me-1"></i>Ανεφοδιασμός</strong> — <?= formatDateTime($r['created_at']) ?>
                                        <?php if ($r['liters'] !== null): ?> · <?= number_format((float) $r['liters'], 1) ?> L<?php endif; ?>
                                        <?php if ($r['cost'] !== null): ?> · <?= number_format((float) $r['cost'], 2) ?> €<?php endif; ?>
                                        <?php if ($r['odometer_value'] !== null): ?> · <?= number_format((float) $r['odometer_value'], 1) ?> <?= $odoUnit ?><?php endif; ?>
                                        <?php if ($r['station']): ?> · <?= h($r['station']) ?><?php endif; ?>
                                    </div>

                                <?php else:
                                    $m = $event['data'];
                                ?>
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <strong><i class="bi bi-wrench-adjustable me-1"></i>Συντήρηση</strong>
                                            <span class="badge bg-secondary"><?= h($maintenanceTypes[$m['type']] ?? $m['type']) ?></span>
                                            — <?= formatDateTime($m['opened_at']) ?>
                                        </div>
                                        <span class="badge bg-<?= $m['status'] === 'open' ? 'warning text-dark' : 'success' ?>">
                                            <?= $m['status'] === 'open' ? 'Ανοιχτή' : 'Ολοκληρώθηκε' ?>
                                        </span>
                                    </div>
                                    <?php if ($m['description']): ?><div class="text-muted small"><?= nl2br(h($m['description'])) ?></div><?php endif; ?>
                                    <div class="small mt-1">
                                        <?php if ($m['workshop']): ?>Συνεργείο: <?= h($m['workshop']) ?> · <?php endif; ?>
                                        <?php if ($m['cost'] !== null): ?>Κόστος: <strong><?= number_format((float) $m['cost'], 2) ?> €</strong><?php endif; ?>
                                    </div>
                                    <?php if ($m['closed_at']): ?>
                                        <div class="text-muted small">Ολοκληρώθηκε: <?= formatDateTime($m['closed_at']) ?><?php if ($m['notes']): ?> — <?= h($m['notes']) ?><?php endif; ?></div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($route)): ?>
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha384-sHL9NAb7lN7rfvG5lfHpm643Xkcjzp4jFvuavGOndn6pjVqS6ny56CAt3nsEVT4H" crossorigin="anonymous">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha384-cxOPjt7s7Iz04uaHJceBmS+qpjv2JkIHNVcuOrM+YHwZOmJGBXI00mdUXEq65HTH" crossorigin="anonymous"></script>
<script>
(function () {
    var points = <?= json_encode(array_map(fn($p) => [(float) $p['lat'], (float) $p['lng']], $route)) ?>;
    var map = L.map('vehicleRouteMap', { zoomControl: true });
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap'
    }).addTo(map);

    var line = L.polyline(points, { color: '#0d6efd', weight: 4 }).addTo(map);
    L.circleMarker(points[0], { radius: 6, color: '#6c757d' }).addTo(map).bindPopup('Αρχή (24ωρο)');
    L.circleMarker(points[points.length - 1], { radius: 7, color: '#198754' }).addTo(map).bindPopup('Τρέχουσα θέση');
    map.fitBounds(line.getBounds(), { padding: [20, 20] });
})();
</script>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
