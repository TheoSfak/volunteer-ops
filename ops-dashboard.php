<?php
/**
 * VolunteerOps - Επιχειρησιακό Dashboard
 * Live operations view: active missions, shift staff counters, map, alerts.
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();
requireRole([ROLE_SYSTEM_ADMIN, ROLE_DEPARTMENT_ADMIN, ROLE_SHIFT_LEADER]);

$pageTitle = 'Επιχειρησιακό Dashboard';
$currentUser = getCurrentUser();

// ── Handle quick attendance POST ─────────────────────────────────────────────
if (isPost()) {
    verifyCsrf();
    $action = post('action');

    if ($action === 'mark_attended') {
        $prId = (int) post('pr_id');
        $shiftId = (int) post('shift_id');
        $pr = dbFetchOne(
            "SELECT pr.*, s.start_time, s.end_time FROM participation_requests pr
             JOIN shifts s ON pr.shift_id = s.id
             WHERE pr.id = ? AND pr.status = ?",
            [$prId, PARTICIPATION_APPROVED]
        );
        if ($pr) {
            $actualHours = round(
                (strtotime($pr['end_time']) - strtotime($pr['start_time'])) / 3600, 2
            );
            dbExecute(
                "UPDATE participation_requests SET attended = 1, actual_hours = ?, updated_at = NOW() WHERE id = ?",
                [$actualHours, $prId]
            );
            logAudit('mark_attended', 'participation_requests', $prId);
        }
    }

    redirect('ops-dashboard.php');
}

// ── Core data query ───────────────────────────────────────────────────────────
$missionRows = dbFetchAll(
    "SELECT m.id as mission_id, m.title, m.is_urgent, m.status as mission_status,
            m.start_datetime, m.end_datetime, m.location, m.location_details,
            m.latitude, m.longitude, m.type as mission_type,
            d.name as department_name,
            s.id as shift_id, s.start_time, s.end_time,
            s.max_volunteers, s.min_volunteers, s.notes as shift_notes,
            COALESCE(SUM(pr.status = '" . PARTICIPATION_APPROVED . "'), 0)     as approved,
            COALESCE(SUM(pr.status = '" . PARTICIPATION_PENDING . "'), 0)      as pending,
            COALESCE(SUM(pr.status = '" . PARTICIPATION_APPROVED . "' AND pr.attended = 1), 0) as attended
     FROM missions m
     JOIN shifts s ON s.mission_id = m.id
     LEFT JOIN departments d ON m.department_id = d.id
     LEFT JOIN participation_requests pr ON pr.shift_id = s.id
     WHERE m.status = '" . STATUS_OPEN . "' AND m.deleted_at IS NULL
     GROUP BY s.id
     ORDER BY m.is_urgent DESC, m.start_datetime ASC, s.start_time ASC",
    []
);

// ── Group rows by mission ─────────────────────────────────────────────────────
$missions = [];
foreach ($missionRows as $row) {
    $mid = $row['mission_id'];
    if (!isset($missions[$mid])) {
        $missions[$mid] = [
            'id'              => $mid,
            'title'           => $row['title'],
            'is_urgent'       => $row['is_urgent'],
            'start_datetime'  => $row['start_datetime'],
            'end_datetime'    => $row['end_datetime'],
            'location'        => $row['location'],
            'location_details'=> $row['location_details'],
            'latitude'        => $row['latitude'],
            'longitude'       => $row['longitude'],
            'mission_type'    => $row['mission_type'],
            'department_name' => $row['department_name'],
            'shifts'          => [],
        ];
    }
    $missions[$mid]['shifts'][] = [
        'shift_id'    => $row['shift_id'],
        'start_time'  => $row['start_time'],
        'end_time'    => $row['end_time'],
        'max_volunteers' => (int)$row['max_volunteers'],
        'min_volunteers' => (int)$row['min_volunteers'],
        'shift_notes' => $row['shift_notes'],
        'approved'    => (int)$row['approved'],
        'pending'     => (int)$row['pending'],
        'attended'    => (int)$row['attended'],
    ];
}

// ── Ajax endpoint: return JSON for live refresh ───────────────────────────────
if (get('ajax') === '1') {
    header('Content-Type: application/json');
    $payload = [];
    foreach ($missions as $m) {
        foreach ($m['shifts'] as $s) {
            $payload['shift_' . $s['shift_id']] = [
                'approved' => $s['approved'],
                'pending'  => $s['pending'],
                'attended' => $s['attended'],
                'max'      => $s['max_volunteers'],
                'min'      => $s['min_volunteers'],
            ];
        }
    }
    echo json_encode(['ts' => date('H:i:s'), 'shifts' => $payload]);
    exit;
}

// ── Compute alerts (understaffed shifts starting in < 2h) ────────────────────
$alerts = [];
$now = time();
foreach ($missions as $m) {
    foreach ($m['shifts'] as $s) {
        $secsToStart = strtotime($s['start_time']) - $now;
        $isUnderstaffed = $s['approved'] < $s['min_volunteers'];
        $startingSoon   = $secsToStart > 0 && $secsToStart < 7200; // < 2 hours
        $alreadyActive  = $secsToStart <= 0 && strtotime($s['end_time']) > $now;
        if ($isUnderstaffed && ($startingSoon || $alreadyActive)) {
            $alerts[] = [
                'mission_title' => $m['title'],
                'shift_id'      => $s['shift_id'],
                'start_time'    => $s['start_time'],
                'approved'      => $s['approved'],
                'min'           => $s['min_volunteers'],
            ];
        }
    }
}

// ── Map pins (missions with coordinates) ─────────────────────────────────────
$mapPins = [];
foreach ($missions as $m) {
    if ($m['latitude'] && $m['longitude']) {
        // Determine pin color based on worst shift
        $worstColor = 'green';
        foreach ($m['shifts'] as $s) {
            $pct = $s['max_volunteers'] > 0 ? $s['approved'] / $s['max_volunteers'] : 0;
            if ($s['approved'] < $s['min_volunteers']) { $worstColor = 'red'; break; }
            if ($pct < 0.5) { $worstColor = 'orange'; }
        }
        $mapPins[] = [
            'lat'   => (float)$m['latitude'],
            'lng'   => (float)$m['longitude'],
            'title' => $m['title'],
            'url'   => 'mission-view.php?id=' . $m['id'],
            'color' => $worstColor,
            'urgent'=> (bool)$m['is_urgent'],
        ];
    }
}

// ── Approved volunteers per shift for quick list ──────────────────────────────
$shiftIds = array_merge(...array_map(fn($m) => array_column($m['shifts'], 'shift_id'), array_values($missions)));
$approvedVolunteers = [];
if (!empty($shiftIds)) {
    $placeholders = implode(',', array_fill(0, count($shiftIds), '?'));
    $rows = dbFetchAll(
        "SELECT pr.id as pr_id, pr.shift_id, pr.attended, u.name, u.phone
         FROM participation_requests pr
         JOIN users u ON pr.volunteer_id = u.id
         WHERE pr.shift_id IN ($placeholders) AND pr.status = '" . PARTICIPATION_APPROVED . "'
         ORDER BY u.name",
        $shiftIds
    );
    foreach ($rows as $r) {
        $approvedVolunteers[$r['shift_id']][] = $r;
    }
}

include __DIR__ . '/includes/header.php';
?>

<!-- Auto-refresh styles -->
<style>
.ops-mission-card  { border-left: 4px solid #0d6efd; }
.ops-mission-card.urgent { border-left-color: #dc3545; }
.shift-row         { background: #f8f9fa; border-radius: 6px; padding: 10px 14px; margin-bottom: 8px; }
.shift-row.active-shift  { background: #fff3cd; }
.shift-row.ended-shift   { opacity: .6; }
.vol-chip          { display:inline-block; background:#e9ecef; border-radius:20px;
                     padding:2px 10px; font-size:.78rem; margin:2px; }
.vol-chip.attended { background:#d1e7dd; }
#mapPanel          { height: 400px; border-radius: 8px; overflow: hidden; }
.refresh-badge     { font-size:.75rem; }
.countdown         { font-size:.8rem; font-weight:600; }
#alertBanner .alert { margin-bottom: 6px; }
</style>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1 class="h3 mb-0">
        <i class="bi bi-broadcast text-danger me-2"></i>Επιχειρησιακό Dashboard
    </h1>
    <div class="d-flex align-items-center gap-3">
        <span class="text-muted refresh-badge">
            Ενημέρωση: <span id="lastRefresh"><?= date('H:i:s') ?></span>
            <span id="refreshSpinner" class="spinner-border spinner-border-sm ms-1 d-none" role="status"></span>
        </span>
        <a href="mission-form.php" class="btn btn-sm btn-primary">
            <i class="bi bi-plus-lg me-1"></i>Νέα Αποστολή
        </a>
    </div>
</div>

<!-- ── Alert banner ── -->
<div id="alertBanner">
<?php if (!empty($alerts)): ?>
    <?php foreach ($alerts as $al): ?>
    <div class="alert alert-danger py-2 d-flex align-items-center gap-2">
        <i class="bi bi-exclamation-triangle-fill fs-5"></i>
        <div>
            <strong><?= h($al['mission_title']) ?></strong> —
            Βάρδια <?= formatDateTime($al['start_time']) ?>:
            μόνο <strong><?= $al['approved'] ?>/<?= $al['min'] ?></strong> εθελοντές εγκεκριμένοι!
            <a href="shift-view.php?id=<?= $al['shift_id'] ?>" class="alert-link ms-2">Διαχείριση →</a>
        </div>
    </div>
    <?php endforeach; ?>
<?php endif; ?>
</div>

<?php if (empty($missions)): ?>
    <div class="alert alert-info">
        <i class="bi bi-info-circle me-2"></i>Δεν υπάρχουν ανοιχτές αποστολές αυτή τη στιγμή.
    </div>
<?php else: ?>

<div class="row g-4">
    <!-- ── Left: mission cards ── -->
    <div class="col-lg-<?= !empty($mapPins) ? '7' : '12' ?>">

        <?php foreach ($missions as $m): ?>
        <?php
            $isUrgent = (bool)$m['is_urgent'];
            $totalApproved = array_sum(array_column($m['shifts'], 'approved'));
            $totalMax      = array_sum(array_column($m['shifts'], 'max_volunteers'));
        ?>
        <div class="card mb-4 ops-mission-card <?= $isUrgent ? 'urgent' : '' ?>">
            <div class="card-header d-flex align-items-center justify-content-between">
                <div class="d-flex align-items-center gap-2">
                    <?php if ($isUrgent): ?>
                        <span class="badge bg-danger"><i class="bi bi-lightning-fill"></i> ΕΠΕΙΓΟΝ</span>
                    <?php endif; ?>
                    <h5 class="mb-0"><?= h($m['title']) ?></h5>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <?php if ($m['department_name']): ?>
                        <span class="badge bg-secondary"><?= h($m['department_name']) ?></span>
                    <?php endif; ?>
                    <a href="mission-view.php?id=<?= $m['id'] ?>" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-arrow-right"></i>
                    </a>
                </div>
            </div>
            <div class="card-body">
                <!-- Mission meta -->
                <div class="d-flex flex-wrap gap-3 mb-3 text-muted small">
                    <?php if ($m['location']): ?>
                        <span><i class="bi bi-geo-alt me-1"></i><?= h($m['location']) ?></span>
                    <?php endif; ?>
                    <span><i class="bi bi-calendar me-1"></i><?= formatDateTime($m['start_datetime']) ?> – <?= formatDateTime($m['end_datetime']) ?></span>
                    <span><i class="bi bi-people me-1"></i><?= $totalApproved ?> / <?= $totalMax ?> εθελοντές</span>
                </div>

                <!-- Shifts -->
                <?php foreach ($m['shifts'] as $s): ?>
                <?php
                    $startTs  = strtotime($s['start_time']);
                    $endTs    = strtotime($s['end_time']);
                    $nowTs    = time();
                    $isActive = $startTs <= $nowTs && $endTs > $nowTs;
                    $isEnded  = $endTs <= $nowTs;
                    $pct      = $s['max_volunteers'] > 0 ? round(($s['approved'] / $s['max_volunteers']) * 100) : 0;
                    $barColor = $s['approved'] < $s['min_volunteers'] ? 'danger' : ($pct < 60 ? 'warning' : 'success');
                    $shiftVols = $approvedVolunteers[$s['shift_id']] ?? [];
                ?>
                <div class="shift-row <?= $isActive ? 'active-shift' : ($isEnded ? 'ended-shift' : '') ?>"
                     id="shift-<?= $s['shift_id'] ?>">
                    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                        <div>
                            <?php if ($isActive): ?>
                                <span class="badge bg-success me-1"><i class="bi bi-circle-fill"></i> ΣΕ ΕΞΕΛΙΞΗ</span>
                            <?php elseif ($isEnded): ?>
                                <span class="badge bg-secondary me-1">ΟΛΟΚΛΗΡΩΘΗΚΕ</span>
                            <?php else: ?>
                                <span class="badge bg-info text-dark me-1">ΠΡΟΣΕΧΩΣ</span>
                            <?php endif; ?>
                            <strong><?= formatDateTime($s['start_time']) ?></strong>
                            <span class="text-muted"> – <?= date('H:i', $endTs) ?></span>
                            <span class="countdown text-muted ms-2"
                                  data-start="<?= $startTs ?>" data-end="<?= $endTs ?>"></span>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge bg-success" id="appr-<?= $s['shift_id'] ?>"><?= $s['approved'] ?> Εγκ.</span>
                            <span class="badge bg-warning text-dark" id="pend-<?= $s['shift_id'] ?>"><?= $s['pending'] ?> Εκκρ.</span>
                            <span class="badge bg-primary" id="att-<?= $s['shift_id'] ?>"><?= $s['attended'] ?> Παρόντες</span>
                            <a href="shift-view.php?id=<?= $s['shift_id'] ?>" class="btn btn-xs btn-outline-secondary btn-sm">
                                <i class="bi bi-pencil"></i>
                            </a>
                        </div>
                    </div>

                    <!-- Progress bar -->
                    <div class="progress mt-2" style="height:6px;" title="<?= $s['approved'] ?>/<?= $s['max_volunteers'] ?> εγκεκριμένοι">
                        <div class="progress-bar bg-<?= $barColor ?>"
                             id="bar-<?= $s['shift_id'] ?>"
                             style="width:<?= min($pct,100) ?>%"></div>
                    </div>
                    <div class="d-flex justify-content-between mt-1">
                        <small class="text-muted"><?= $s['approved'] ?>/<?= $s['max_volunteers'] ?> (min: <?= $s['min_volunteers'] ?>)</small>
                        <small class="text-muted"><?= $pct ?>%</small>
                    </div>

                    <!-- Volunteer chips + quick attendance -->
                    <?php if (!empty($shiftVols)): ?>
                    <div class="mt-2">
                        <?php foreach ($shiftVols as $v): ?>
                        <span class="vol-chip <?= $v['attended'] ? 'attended' : '' ?>">
                            <?php if ($v['attended']): ?>
                                <i class="bi bi-check-circle-fill text-success me-1"></i>
                            <?php endif; ?>
                            <?= h($v['name']) ?>
                            <?php if (!$v['attended'] && $isActive): ?>
                                <form method="post" class="d-inline ms-1"
                                      onsubmit="return confirm('Σήμανση παρουσίας για <?= h(addslashes($v['name'])) ?>;')">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="mark_attended">
                                    <input type="hidden" name="pr_id" value="<?= $v['pr_id'] ?>">
                                    <input type="hidden" name="shift_id" value="<?= $s['shift_id'] ?>">
                                    <button type="submit" class="btn btn-link btn-sm p-0 text-success"
                                            title="Σήμανση παρουσίας">✓</button>
                                </form>
                            <?php endif; ?>
                        </span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- ── Right: map ── -->
    <?php if (!empty($mapPins)): ?>
    <div class="col-lg-5">
        <div class="card sticky-top" style="top:80px;">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-map me-1"></i>Χάρτης Αποστολών</h5>
            </div>
            <div class="card-body p-0">
                <div id="mapPanel"></div>
            </div>
            <div class="card-footer small text-muted">
                <span class="me-3"><i class="bi bi-circle-fill text-success"></i> Επαρκής στελέχωση</span>
                <span class="me-3"><i class="bi bi-circle-fill text-warning"></i> Μερική στελέχωση</span>
                <span><i class="bi bi-circle-fill text-danger"></i> Υποστελεχωμένη</span>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php endif; ?>

<!-- Leaflet CSS -->
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
// ── Map ───────────────────────────────────────────────────────────────────────
<?php if (!empty($mapPins)): ?>
(function() {
    const pins = <?= json_encode(array_values($mapPins)) ?>;
    const map  = L.map('mapPanel').setView([pins[0].lat, pins[0].lng], 10);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap'
    }).addTo(map);

    const icons = {
        green:  L.divIcon({className:'', html:'<div style="background:#198754;width:16px;height:16px;border-radius:50%;border:2px solid #fff;box-shadow:0 1px 4px #0004"></div>'}),
        orange: L.divIcon({className:'', html:'<div style="background:#fd7e14;width:16px;height:16px;border-radius:50%;border:2px solid #fff;box-shadow:0 1px 4px #0004"></div>'}),
        red:    L.divIcon({className:'', html:'<div style="background:#dc3545;width:18px;height:18px;border-radius:50%;border:2px solid #fff;box-shadow:0 1px 4px #0004"></div>'}),
    };

    pins.forEach(p => {
        const icon = p.urgent ? icons.red : (icons[p.color] || icons.green);
        L.marker([p.lat, p.lng], {icon})
         .addTo(map)
         .bindPopup(`<strong>${p.title}</strong><br><a href="${p.url}">Προβολή Αποστολής →</a>`);
    });

    if (pins.length > 1) {
        const bounds = L.latLngBounds(pins.map(p => [p.lat, p.lng]));
        map.fitBounds(bounds, {padding:[30,30]});
    }
})();
<?php endif; ?>

// ── Countdown timers ──────────────────────────────────────────────────────────
function updateCountdowns() {
    document.querySelectorAll('.countdown[data-start]').forEach(el => {
        const start = parseInt(el.dataset.start) * 1000;
        const end   = parseInt(el.dataset.end)   * 1000;
        const now   = Date.now();
        if (now < start) {
            const diff = Math.floor((start - now) / 1000);
            const h = Math.floor(diff / 3600), m = Math.floor((diff % 3600) / 60), s = diff % 60;
            el.textContent = `(σε ${h}ω ${m}λ)`;
            el.className = 'countdown text-muted ms-2';
        } else if (now <= end) {
            const diff = Math.floor((end - now) / 1000);
            const h = Math.floor(diff / 3600), m = Math.floor((diff % 3600) / 60);
            el.textContent = `(λήγει σε ${h}ω ${m}λ)`;
            el.className = 'countdown text-success ms-2';
        } else {
            el.textContent = '(ολοκληρώθηκε)';
            el.className = 'countdown text-muted ms-2';
        }
    });
}
updateCountdowns();
setInterval(updateCountdowns, 30000);

// ── Live refresh (every 30s) ──────────────────────────────────────────────────
function liveRefresh() {
    const spinner = document.getElementById('refreshSpinner');
    const tsEl    = document.getElementById('lastRefresh');
    if (spinner) spinner.classList.remove('d-none');

    fetch('ops-dashboard.php?ajax=1')
        .then(r => r.json())
        .then(data => {
            tsEl.textContent = data.ts;
            Object.entries(data.shifts).forEach(([key, s]) => {
                const id = key.replace('shift_', '');
                const pct = s.max > 0 ? Math.min(Math.round(s.approved / s.max * 100), 100) : 0;
                const color = s.approved < s.min ? 'danger' : (pct < 60 ? 'warning' : 'success');

                const appr = document.getElementById('appr-' + id);
                const pend = document.getElementById('pend-' + id);
                const att  = document.getElementById('att-'  + id);
                const bar  = document.getElementById('bar-'  + id);

                if (appr) appr.textContent = s.approved + ' Εγκ.';
                if (pend) pend.textContent = s.pending  + ' Εκκρ.';
                if (att)  att.textContent  = s.attended + ' Παρόντες';
                if (bar) {
                    bar.style.width = pct + '%';
                    bar.className = 'progress-bar bg-' + color;
                }
            });
        })
        .catch(() => {})
        .finally(() => { if (spinner) spinner.classList.add('d-none'); });
}
setInterval(liveRefresh, 30000);
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
