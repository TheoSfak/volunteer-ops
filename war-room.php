<?php
/**
 * VolunteerOps - War Room
 * Mission-specific live operational view for approved participants and managers.
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();

$missionId = (int)get('id');
if (!$missionId) {
    setFlash('error', 'Η αποστολή δεν βρέθηκε.');
    redirect('dashboard.php');
}

$user = getCurrentUser();
$mission = dbFetchOne(
    "SELECT m.*, d.name AS department_name, mt.name AS mission_type_name,
            r.name AS responsible_name
     FROM missions m
     LEFT JOIN departments d ON d.id = m.department_id
     LEFT JOIN mission_types mt ON mt.id = m.mission_type_id
     LEFT JOIN users r ON r.id = m.responsible_user_id
     WHERE m.id = ? AND m.deleted_at IS NULL",
    [$missionId]
);
if (!$mission) {
    setFlash('error', 'Η αποστολή δεν βρέθηκε.');
    redirect('dashboard.php');
}

$canManageWarRoom = hasPagePermission('missions_manage') || (int)$mission['responsible_user_id'] === (int)$user['id'];
$isApprovedParticipant = (bool)dbFetchValue(
    "SELECT COUNT(*) FROM participation_requests pr
     JOIN shifts s ON s.id = pr.shift_id
     WHERE s.mission_id = ? AND pr.volunteer_id = ? AND pr.status = ?",
    [$missionId, $user['id'], PARTICIPATION_APPROVED]
);
if (!$canManageWarRoom && !$isApprovedParticipant) {
    setFlash('error', 'Έχετε πρόσβαση στο War Room μόνο για αποστολές στις οποίες είστε εγκεκριμένος/η.');
    redirect('dashboard.php');
}
if ($mission['status'] !== STATUS_OPEN || empty($mission['show_in_ops'])) {
    setFlash('warning', 'Η αποστολή δεν είναι ενεργή στο Επιχειρησιακό.');
    redirect('mission-view.php?id=' . $missionId);
}

if (isPost()) {
    verifyCsrf();
    if (post('action') === 'close_mission') {
        if (!$canManageWarRoom) {
            setFlash('error', 'Δεν έχετε δικαίωμα να κλείσετε αυτή την αποστολή.');
        } else {
            dbExecute("UPDATE missions SET status = ?, updated_at = NOW() WHERE id = ? AND status = ?", [STATUS_CLOSED, $missionId, STATUS_OPEN]);
            logAudit('close_from_war_room', 'missions', $missionId, null, ['old_status' => STATUS_OPEN]);
            setFlash('success', 'Η αποστολή έκλεισε και αφαιρέθηκε από το Επιχειρησιακό.');
            redirect('ops-dashboard.php');
        }
    }
}

$hasFieldStatus = (bool)dbFetchValue(
    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'participation_requests' AND COLUMN_NAME = 'field_status'"
);
$fieldStatusColumns = $hasFieldStatus ? ', pr.field_status, pr.field_status_updated_at' : ', NULL AS field_status, NULL AS field_status_updated_at';
$participants = dbFetchAll(
    "SELECT pr.id AS pr_id, pr.volunteer_id, pr.attended{$fieldStatusColumns},
            u.name, u.phone, s.id AS shift_id, s.start_time, s.end_time
     FROM participation_requests pr
     JOIN users u ON u.id = pr.volunteer_id
     JOIN shifts s ON s.id = pr.shift_id
     WHERE s.mission_id = ? AND pr.status = ?
     ORDER BY s.start_time, u.name",
    [$missionId, PARTICIPATION_APPROVED]
);
$myAssignments = array_values(array_filter($participants, fn($participant) => (int)$participant['volunteer_id'] === (int)$user['id']));

$shifts = dbFetchAll(
    "SELECT s.*, COUNT(CASE WHEN pr.status = '" . PARTICIPATION_APPROVED . "' THEN 1 END) AS approved_count,
            COUNT(CASE WHEN pr.status = '" . PARTICIPATION_PENDING . "' THEN 1 END) AS pending_count
     FROM shifts s
     LEFT JOIN participation_requests pr ON pr.shift_id = s.id
     WHERE s.mission_id = ?
     GROUP BY s.id
     ORDER BY s.start_time",
    [$missionId]
);

$loadPins = function () use ($missionId, $hasFieldStatus) {
    try {
        $field = $hasFieldStatus ? ', pr.field_status' : ', NULL AS field_status';
        return dbFetchAll(
            "SELECT vp.user_id, vp.shift_id, vp.lat, vp.lng, vp.created_at, u.name{$field}
             FROM volunteer_pings vp
             JOIN shifts s ON s.id = vp.shift_id
             JOIN users u ON u.id = vp.user_id
             LEFT JOIN participation_requests pr ON pr.shift_id = vp.shift_id AND pr.volunteer_id = vp.user_id
             WHERE s.mission_id = ?
               AND vp.created_at >= DATE_SUB(NOW(), INTERVAL 2 HOUR)
               AND vp.id = (SELECT MAX(vp2.id) FROM volunteer_pings vp2 WHERE vp2.user_id = vp.user_id AND vp2.shift_id = vp.shift_id)
             ORDER BY vp.created_at DESC",
            [$missionId]
        );
    } catch (Exception $e) {
        return [];
    }
};

if (get('ajax') === '1') {
    header('Content-Type: application/json');
    $pins = array_map(fn($pin) => [
        'lat' => (float)$pin['lat'], 'lng' => (float)$pin['lng'], 'name' => $pin['name'],
        'status' => $pin['field_status'], 'time' => date('H:i', strtotime($pin['created_at']))
    ], $loadPins());
    echo json_encode(['pins' => $pins, 'time' => date('H:i:s')]);
    exit;
}

$pins = array_map(fn($pin) => [
    'lat' => (float)$pin['lat'], 'lng' => (float)$pin['lng'], 'name' => $pin['name'],
    'status' => $pin['field_status'], 'time' => date('H:i', strtotime($pin['created_at']))
], $loadPins());

$firstShift = $shifts[0]['start_time'] ?? $mission['start_datetime'];
$lastShift = !empty($shifts) ? end($shifts)['end_time'] : $mission['end_datetime'];
$now = time();
$timeState = strtotime($firstShift) > $now ? 'upcoming' : (strtotime($lastShift) < $now ? 'overdue' : 'active');
$pageTitle = 'War Room — ' . $mission['title'];
$currentPage = 'war-room';
include __DIR__ . '/includes/header.php';
?>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<style>
    #warRoomMap { height: 520px; border-radius: 12px; }
    .war-room-hero { background: linear-gradient(135deg, #172554, #b91c1c); color: #fff; border-radius: 14px; }
    .participant-row { border-left: 4px solid #e2e8f0; }
    .participant-row.needs-help { border-left-color: #dc2626; }
</style>

<div class="war-room-hero p-4 mb-4 shadow-sm">
    <div class="d-flex flex-wrap justify-content-between gap-3 align-items-start">
        <div>
            <div class="text-uppercase small fw-semibold opacity-75 mb-1"><i class="bi bi-broadcast-pin me-1"></i>War Room · Επιχειρησιακό Κέντρο Αποστολής</div>
            <h1 class="h3 mb-2"><?= h($mission['title']) ?></h1>
            <div class="small opacity-75"><i class="bi bi-geo-alt me-1"></i><?= h($mission['location']) ?> · <?= formatDateTime($firstShift) ?> έως <?= formatDateTime($lastShift) ?></div>
        </div>
        <div class="d-flex gap-2 align-items-center">
            <span class="badge fs-6 <?= $timeState === 'active' ? 'bg-success' : ($timeState === 'upcoming' ? 'bg-info text-dark' : 'bg-warning text-dark') ?>">
                <?= $timeState === 'active' ? 'ΣΕ ΕΞΕΛΙΞΗ' : ($timeState === 'upcoming' ? 'ΠΡΟΣΕΧΩΣ' : 'ΕΚΚΡΕΜΕΙ ΚΛΕΙΣΙΜΟ') ?>
            </span>
            <a href="ops-dashboard.php" class="btn btn-light"><i class="bi bi-arrow-left me-1"></i>Επιχειρησιακό</a>
        </div>
    </div>
</div>

<?= showFlash() ?>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="card shadow-sm mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-map me-1"></i>Ζωντανός χάρτης αποστολής</h5>
                <small class="text-muted">Ενημέρωση: <span id="mapRefresh"><?= date('H:i:s') ?></span></small>
            </div>
            <div class="card-body p-0"><div id="warRoomMap"></div></div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header"><h5 class="mb-0"><i class="bi bi-people me-1"></i>Εγκεκριμένοι εθελοντές (<?= count($participants) ?>)</h5></div>
            <div class="list-group list-group-flush">
                <?php foreach ($participants as $participant): ?>
                <?php $status = $participant['field_status'] ?? ''; ?>
                <div class="list-group-item participant-row <?= $status === 'needs_help' ? 'needs-help' : '' ?> d-flex justify-content-between align-items-center gap-2 flex-wrap">
                    <div><strong><?= h($participant['name']) ?></strong><br><small class="text-muted"><?= formatDateTime($participant['start_time']) ?> – <?= date('H:i', strtotime($participant['end_time'])) ?></small></div>
                    <span class="badge <?= $status === 'needs_help' ? 'bg-danger' : ($status === 'on_site' ? 'bg-success' : ($status === 'on_way' ? 'bg-warning text-dark' : 'bg-secondary')) ?>">
                        <?= $status === 'needs_help' ? 'Χρειάζεται βοήθεια' : ($status === 'on_site' ? 'Επί τόπου' : ($status === 'on_way' ? 'Σε κίνηση' : 'Χωρίς κατάσταση')) ?>
                    </span>
                </div>
                <?php endforeach; ?>
                <?php if (empty($participants)): ?><div class="list-group-item text-muted">Δεν υπάρχουν εγκεκριμένοι εθελοντές.</div><?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card shadow-sm mb-4 border-primary">
            <div class="card-header bg-primary text-white"><h5 class="mb-0"><i class="bi bi-geo-alt-fill me-1"></i>Το στίγμα μου</h5></div>
            <div class="card-body">
                <?php if (empty($myAssignments)): ?>
                    <p class="text-muted mb-0">Δεν έχετε εγκεκριμένη βάρδια σε αυτή την αποστολή.</p>
                <?php else: ?>
                    <p class="small text-muted">Επιλέξτε τη βάρδια για την οποία βρίσκεστε στο πεδίο.</p>
                    <?php foreach ($myAssignments as $assignment): ?>
                    <button type="button" class="btn btn-primary w-100 mb-2 send-ping" data-shift-id="<?= $assignment['shift_id'] ?>" data-pr-id="<?= $assignment['pr_id'] ?>">
                        <i class="bi bi-send-fill me-1"></i>Αποστολή στίγματος · <?= date('H:i', strtotime($assignment['start_time'])) ?>
                    </button>
                    <div class="small mb-2" id="pingStatus-<?= $assignment['pr_id'] ?>"></div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="card shadow-sm mb-4">
            <div class="card-header"><h5 class="mb-0"><i class="bi bi-calendar-range me-1"></i>Βάρδιες</h5></div>
            <div class="list-group list-group-flush">
                <?php foreach ($shifts as $shift): ?>
                <div class="list-group-item"><strong><?= formatDateTime($shift['start_time']) ?></strong><br><small class="text-muted">έως <?= date('H:i', strtotime($shift['end_time'])) ?> · <?= $shift['approved_count'] ?>/<?= $shift['max_volunteers'] ?> εγκεκριμένοι</small></div>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if ($canManageWarRoom): ?>
        <div class="card border-danger shadow-sm">
            <div class="card-body"><h6><i class="bi bi-shield-exclamation text-danger me-1"></i>Διαχείριση αποστολής</h6>
                <p class="small text-muted">Το κλείσιμο αφαιρεί την αποστολή από το Επιχειρησιακό και σταματά τη λήψη νέων στιγμάτων.</p>
                <form method="post" onsubmit="return confirm('Είστε σίγουρος/η ότι θέλετε να κλείσετε την αποστολή;')">
                    <?= csrfField() ?><input type="hidden" name="action" value="close_mission">
                    <button class="btn btn-danger w-100"><i class="bi bi-x-octagon-fill me-1"></i>Κλείσιμο αποστολής</button>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
const csrfToken = '<?= csrfToken() ?>';
const missionLocation = <?= json_encode(['lat' => $mission['latitude'] ? (float)$mission['latitude'] : null, 'lng' => $mission['longitude'] ? (float)$mission['longitude'] : null, 'title' => $mission['title']]) ?>;
let pins = <?= json_encode($pins) ?>;
const map = L.map('warRoomMap').setView(missionLocation.lat ? [missionLocation.lat, missionLocation.lng] : [37.97, 23.73], missionLocation.lat ? 13 : 7);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {attribution: '© OpenStreetMap'}).addTo(map);
const pinLayer = L.layerGroup().addTo(map);
if (missionLocation.lat) L.marker([missionLocation.lat, missionLocation.lng]).addTo(map).bindPopup('<strong>Σημείο αποστολής</strong><br><?= h(addslashes($mission['title'])) ?>');
function renderPins(items) {
    pinLayer.clearLayers();
    const colors = {needs_help:'#dc2626', on_site:'#198754', on_way:'#f59e0b'};
    items.forEach(pin => {
        const color = colors[pin.status] || '#2563eb';
        const icon = L.divIcon({className:'', html:`<span style="display:block;width:16px;height:16px;background:${color};border:2px solid white;border-radius:50%;box-shadow:0 1px 4px #0008"></span>`, iconSize:[16,16], iconAnchor:[8,8]});
        L.marker([pin.lat, pin.lng], {icon}).addTo(pinLayer).bindPopup(`<strong>${pin.name}</strong><br>${pin.time}`);
    });
}
renderPins(pins);
document.querySelectorAll('.send-ping').forEach(button => button.addEventListener('click', () => {
    const status = document.getElementById('pingStatus-' + button.dataset.prId);
    if (!navigator.geolocation) { status.textContent = 'Το GPS δεν υποστηρίζεται από τη συσκευή.'; return; }
    button.disabled = true; status.textContent = 'Εντοπισμός θέσης…';
    navigator.geolocation.getCurrentPosition(position => {
        const data = new URLSearchParams({csrf_token: csrfToken, shift_id: button.dataset.shiftId, lat: position.coords.latitude, lng: position.coords.longitude});
        fetch('ping-location.php', {method:'POST', body:data}).then(response => response.json()).then(result => {
            status.textContent = result.ok ? '✓ Το στίγμα εστάλη στις ' + result.ts : result.error;
            status.className = 'small mb-2 ' + (result.ok ? 'text-success' : 'text-danger');
        }).catch(() => { status.textContent = 'Αποτυχία αποστολής στίγματος.'; status.className = 'small mb-2 text-danger'; }).finally(() => button.disabled = false);
    }, () => { status.textContent = 'Δεν δόθηκε άδεια πρόσβασης στο GPS.'; status.className = 'small mb-2 text-danger'; button.disabled = false; }, {enableHighAccuracy:true, timeout:10000});
}));
setInterval(() => fetch('war-room.php?id=<?= $missionId ?>&ajax=1').then(response => response.json()).then(data => { renderPins(data.pins || []); document.getElementById('mapRefresh').textContent = data.time || ''; }).catch(() => {}), 15000);
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
