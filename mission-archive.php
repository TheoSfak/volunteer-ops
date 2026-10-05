<?php
/**
 * VolunteerOps - Mission archive («Action Room (αρχείο)»)
 *
 * What a CLOSED / COMPLETED mission looked like, after the Action Room itself
 * has refused to open it: the map with every team's GPS trail and a replay
 * scrubber, the geometry that was drawn on it (dispatched points and areas,
 * sectors, routes, incidents, imported map points) and the mission's
 * timestamped timeline.
 *
 * READ ONLY, and deliberately NOT war-room.php with a flag: that page is a
 * 28,000-line live console with dozens of timers, a GPS tick, an SOS button and
 * hundreds of write controls, and a flag would have to hide every one of them.
 * This page has no form, no POST handler and no write endpoint at all, so there
 * is nothing to forget. Every live endpoint it could have called already
 * refuses a mission that is not open.
 *
 * Command staff only (canManageActionRoom) — the same gate as the GPS trail it
 * draws (mission-track.php) and the GPS-quality page. The trail and timeline
 * come from mission-track.php, the one endpoint the live Action Room's trail
 * view and this page share.
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();

$user      = getCurrentUser();
$userId    = (int) $user['id'];
$missionId = (int) get('id');

$mission = dbFetchOne("SELECT * FROM missions WHERE id = ? AND deleted_at IS NULL", [$missionId]);
if (!$mission) {
    setFlash('error', t('common.mission_not_found_or_inactive'));
    redirect('dashboard.php');
}
if (!canManageActionRoom($mission['responsible_user_id'] ? (int) $mission['responsible_user_id'] : null, $userId)) {
    setFlash('error', t('archive.command_only'));
    redirect('mission-view.php?id=' . $missionId);
}
if ($mission['status'] === STATUS_OPEN && !empty($mission['show_in_ops'])) {
    redirect('war-room.php?id=' . $missionId);
}
if (!in_array($mission['status'], [STATUS_CLOSED, STATUS_COMPLETED], true) || empty($mission['show_in_ops'])) {
    setFlash('warning', t('wr.mission_not_active'));
    redirect('mission-view.php?id=' . $missionId);
}

// ── Geometry, as it stood when the mission ended ────────────────────────────
// Plain queries rather than the live loaders: those compute ETAs (an external
// routing call per dispatched point) and visibility per viewer, neither of
// which means anything for a finished mission and command sees everything.
$decodeGeo = static function ($raw) {
    $geo = json_decode((string) $raw, true);
    return is_array($geo) ? $geo : null;
};
$teamColor = static fn($c) => (is_string($c) && preg_match('/^#[0-9a-fA-F]{6}$/', $c)) ? $c : null;

$teams = [];
foreach (dbFetchAll("SELECT id, codename, team_number FROM mission_teams WHERE mission_id = ? ORDER BY created_at, id", [$missionId]) as $row) {
    $teams[] = ['id' => (int) $row['id'], 'label' => teamLabel($row['codename'], $row['team_number'])];
}

$dispatches = [];
foreach (dbFetchAll(
    "SELECT d.type, d.geo, d.label, mt.codename, mt.team_number, mt.color
     FROM mission_dispatch_points d
     LEFT JOIN mission_teams mt ON mt.id = d.team_id
     WHERE d.mission_id = ? ORDER BY d.created_at",
    [$missionId]
) as $row) {
    $geo = $decodeGeo($row['geo']);
    if ($geo === null) continue;
    $dispatches[] = [
        'type'  => $row['type'],
        'geo'   => $geo,
        'label' => (string) $row['label'],
        'team'  => $row['codename'] !== null ? teamLabel($row['codename'], $row['team_number']) : null,
        'color' => $teamColor($row['color']),
    ];
}

$areas = [];
foreach (dbFetchAll("SELECT label, geo FROM mission_search_areas WHERE mission_id = ? ORDER BY created_at", [$missionId]) as $row) {
    $geo = $decodeGeo($row['geo']);
    if ($geo !== null) $areas[] = ['label' => (string) $row['label'], 'geo' => $geo];
}

$sectors = [];
foreach (dbFetchAll(
    "SELECT s.label, s.geo, s.status, mt.color
     FROM mission_search_sectors s
     LEFT JOIN mission_teams mt ON mt.id = s.team_id
     WHERE s.mission_id = ? ORDER BY s.created_at, s.id",
    [$missionId]
) as $row) {
    $geo = $decodeGeo($row['geo']);
    if ($geo !== null) $sectors[] = ['label' => (string) $row['label'], 'geo' => $geo, 'color' => $teamColor($row['color'])];
}

$routes = [];
$routeRows = dbFetchAll(
    "SELECT r.id, r.title, r.is_closed_loop, mt.color
     FROM mission_routes r LEFT JOIN mission_teams mt ON mt.id = r.team_id
     WHERE r.mission_id = ? ORDER BY r.created_at",
    [$missionId]
);
if ($routeRows) {
    $routeIds = array_map(fn($r) => (int) $r['id'], $routeRows);
    $ph = implode(',', array_fill(0, count($routeIds), '?'));
    $waypointsByRoute = [];
    foreach (dbFetchAll(
        "SELECT route_id, seq, lat, lng, label FROM mission_route_waypoints WHERE route_id IN ($ph) ORDER BY route_id, seq",
        $routeIds
    ) as $w) {
        $waypointsByRoute[(int) $w['route_id']][] = ['lat' => (float) $w['lat'], 'lng' => (float) $w['lng'], 'label' => (string) $w['label']];
    }
    foreach ($routeRows as $row) {
        $wps = $waypointsByRoute[(int) $row['id']] ?? [];
        if ($wps) {
            $routes[] = ['title' => (string) $row['title'], 'closed' => (bool) $row['is_closed_loop'], 'color' => $teamColor($row['color']), 'waypoints' => $wps];
        }
    }
}

$incidents = [];
foreach (dbFetchAll(
    "SELECT incident_type, severity, lat, lng, created_at FROM mission_incidents
     WHERE mission_id = ? AND lat IS NOT NULL AND lng IS NOT NULL ORDER BY created_at",
    [$missionId]
) as $row) {
    // Type, severity and time only: no patient name, phone or notes on a map.
    $incidents[] = [
        'lat' => (float) $row['lat'], 'lng' => (float) $row['lng'],
        'severity' => $row['severity'],
        'text' => t('archive.incident_popup', [
            'type'     => incidentTypeLabel($row['incident_type']),
            'severity' => incidentSeverityLabel($row['severity']),
            'time'     => date('d/m H:i', strtotime($row['created_at'])),
        ]),
    ];
}

$mapPoints = array_map(
    fn($p) => ['name' => $p['name'], 'lat' => $p['lat'], 'lng' => $p['lng'], 'status' => $p['status']],
    loadMissionMapPoints($missionId)
);

// Incidents and shortage reports in full. Unmasked: this page is command staff
// only (gate above), the one audience allowed the real patient name, phone and
// the staff-only notes.
require_once __DIR__ . '/includes/mission-review-render.php';
$reviewHtml = renderMissionReviewCards(
    loadMissionReviewData($missionId, true, $user['language'] ?? DEFAULT_LANGUAGE),
    $user['language'] ?? DEFAULT_LANGUAGE
);

$archive = [
    'missionId' => $missionId,
    'dispatches' => $dispatches, 'areas' => $areas, 'sectors' => $sectors,
    'routes' => $routes, 'incidents' => $incidents, 'mapPoints' => $mapPoints,
];
$jsKeys = [
    'archive.loading', 'archive.load_failed', 'archive.no_trails', 'archive.timeline_empty', 'archive.speed',
    'archive.auto_suffix', 'archive.route_popup', 'archive.waypoint_popup',
    'archive.view_street', 'archive.view_topo', 'archive.view_satellite',
];
$jsStrings = [];
foreach ($jsKeys as $key) $jsStrings[$key] = t($key);
$jsLocale = ($user['language'] ?? DEFAULT_LANGUAGE) === 'en' ? 'en-GB' : 'el-GR';

$pageTitle = t('archive.page_title', ['title' => $mission['title']]);
$clockMission = $mission; // the clock strip names its prefecture (includes/clock-place.php)
include __DIR__ . '/includes/header.php';
?>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha384-sHL9NAb7lN7rfvG5lfHpm643Xkcjzp4jFvuavGOndn6pjVqS6ny56CAt3nsEVT4H" crossorigin="anonymous">
<style>
    .ar-hero { background: linear-gradient(135deg, #334155, #172554); color: #fff; border-radius: 16px; padding: 18px 24px; }
    .ar-hero h1 { color: #fff; font-weight: 700; font-size: 1.5rem; margin: 0; }
    .ar-card { background: #fff; border-radius: 14px; box-shadow: 0 2px 10px rgba(0,0,0,.06); padding: 14px 16px; margin-bottom: 16px; }
    .ar-card h2 { font-size: 1.05rem; font-weight: 700; margin-bottom: 10px; }
    #archiveMap { height: 68vh; min-height: 380px; border-radius: 10px; }
    #archiveEventLog { max-height: 68vh; overflow-y: auto; }
    .ar-pin { width: 14px; height: 14px; border-radius: 50%; border: 2px solid #fff; box-shadow: 0 1px 4px #0009; }
</style>

<div class="container-fluid px-0">
    <div class="ar-hero mb-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <h1><i class="bi bi-archive me-2"></i><?= h(t('archive.heading')) ?></h1>
            <div class="small opacity-75"><?= h($mission['title']) ?></div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="mission-report-print.php?mission_id=<?= $missionId ?>" target="_blank" rel="noopener" class="btn btn-outline-light btn-sm"><i class="bi bi-file-earmark-pdf me-1"></i><?= h(t('archive.link_report')) ?></a>
            <a href="mission-stats.php?id=<?= $missionId ?>" class="btn btn-outline-light btn-sm"><i class="bi bi-bar-chart me-1"></i><?= h(t('archive.link_stats')) ?></a>
            <a href="mission-debrief.php?id=<?= $missionId ?>" class="btn btn-outline-light btn-sm"><i class="bi bi-clipboard-check me-1"></i><?= h(t('archive.link_debrief')) ?></a>
            <a href="mission-gps-quality.php?id=<?= $missionId ?>" target="_blank" rel="noopener" class="btn btn-outline-light btn-sm"><i class="bi bi-crosshair me-1"></i><?= h(t('archive.link_gps_quality')) ?></a>
            <a href="mission-view.php?id=<?= $missionId ?>" class="btn btn-outline-light btn-sm"><i class="bi bi-arrow-left me-1"></i><?= h(t('archive.link_mission')) ?></a>
        </div>
    </div>

    <div class="alert alert-secondary d-flex align-items-center gap-2 py-2" role="status">
        <i class="bi bi-eye"></i><span><?= h(t('archive.banner')) ?></span>
    </div>

    <div class="row g-3">
        <div class="col-lg-8">
            <div class="ar-card">
                <div class="row g-2 align-items-center mb-2">
                    <div class="col-auto">
                        <select id="archiveTeam" class="form-select form-select-sm" aria-label="<?= h(t('archive.filter_team')) ?>">
                            <option value="0"><?= h(t('archive.filter_all_teams')) ?></option>
                            <?php foreach ($teams as $team): ?>
                                <option value="<?= $team['id'] ?>"><?= h($team['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-auto form-check ms-2">
                        <input class="form-check-input" type="checkbox" id="archiveAuto" checked>
                        <label class="form-check-label small" for="archiveAuto"><?= h(t('archive.filter_include_auto')) ?></label>
                    </div>
                    <div class="col-auto">
                        <button type="button" id="archiveShow" class="btn btn-primary btn-sm"><?= h(t('archive.btn_show')) ?></button>
                    </div>
                    <div class="col-auto small text-muted" id="archiveStatus"></div>
                </div>
                <div class="d-flex flex-wrap gap-3 small mb-2" aria-label="<?= h(t('archive.layers')) ?>">
                    <label class="form-check-label"><input type="checkbox" class="form-check-input me-1" data-layer="dispatch" checked><?= h(t('archive.layer_dispatch')) ?></label>
                    <label class="form-check-label"><input type="checkbox" class="form-check-input me-1" data-layer="sectors" checked><?= h(t('archive.layer_sectors')) ?></label>
                    <label class="form-check-label"><input type="checkbox" class="form-check-input me-1" data-layer="routes" checked><?= h(t('archive.layer_routes')) ?></label>
                    <label class="form-check-label"><input type="checkbox" class="form-check-input me-1" data-layer="incidents" checked><?= h(t('archive.layer_incidents')) ?></label>
                    <label class="form-check-label"><input type="checkbox" class="form-check-input me-1" data-layer="points" checked><?= h(t('archive.layer_points')) ?></label>
                </div>
                <div id="archiveMap"></div>
                <div class="d-none align-items-center gap-2 mt-2" id="archiveReplayBar">
                    <button type="button" id="archivePlay" class="btn btn-outline-primary btn-sm"><i class="bi bi-play-fill"></i></button>
                    <input type="range" id="archiveScrubber" class="form-range flex-grow-1" step="1">
                    <span class="small text-muted text-nowrap" id="archiveScrubberTime"></span>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="ar-card">
                <h2><i class="bi bi-clock-history me-1"></i><?= h(t('archive.timeline')) ?></h2>
                <div id="archiveEventLog"></div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <?php /* built by renderMissionReviewCards(); every value inside is escaped there */ ?>
        <div class="col-lg-6"><?= $reviewHtml['incidents'] ?></div>
        <div class="col-lg-6"><?= $reviewHtml['shortages'] ?></div>
    </div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha384-cxOPjt7s7Iz04uaHJceBmS+qpjv2JkIHNVcuOrM+YHwZOmJGBXI00mdUXEq65HTH" crossorigin="anonymous"></script>
<script>
(function () {
    const ARCHIVE = <?= json_encode($archive, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const STR = <?= json_encode($jsStrings, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const LOCALE = <?= json_encode($jsLocale) ?>;
    const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const fmtTime = ts => new Date(ts * 1000).toLocaleString(LOCALE, {day:'2-digit', month:'2-digit', hour:'2-digit', minute:'2-digit'});

    const map = L.map('archiveMap', {maxZoom: 21});
    const base = {
        [STR['archive.view_street']]: L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {attribution: '© OpenStreetMap', maxNativeZoom: 19, maxZoom: 21}),
        [STR['archive.view_topo']]: L.tileLayer('https://{s}.tile.opentopomap.org/{z}/{x}/{y}.png', {attribution: '© OpenTopoMap (CC-BY-SA)', maxNativeZoom: 17, maxZoom: 21}),
        [STR['archive.view_satellite']]: L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {attribution: 'Tiles © Esri', maxNativeZoom: 19, maxZoom: 21}),
    };
    base[STR['archive.view_street']].addTo(map);
    L.control.layers(base, null, {position: 'topright'}).addTo(map);
    map.setView([35.34, 25.14], 10);

    // ── Static geometry, one group per toggle ────────────────────────────────
    const groups = {dispatch: L.layerGroup(), sectors: L.layerGroup(), routes: L.layerGroup(), incidents: L.layerGroup(), points: L.layerGroup()};
    const staticBounds = [];
    const extend = (lat, lng) => staticBounds.push([lat, lng]);

    ARCHIVE.dispatches.forEach(d => {
        const tip = esc(d.label || '') + (d.team ? ' — ' + esc(d.team) : '');
        const color = d.color || '#7c3aed';
        if (d.type === 'point' && d.geo.lat !== undefined) {
            L.circleMarker([d.geo.lat, d.geo.lng], {radius: 8, color: '#fff', weight: 2, fillColor: color, fillOpacity: 0.9}).bindTooltip(tip).addTo(groups.dispatch);
            extend(d.geo.lat, d.geo.lng);
        } else if (Array.isArray(d.geo) && d.geo.length) {
            L.polygon(d.geo, {color, weight: 2, opacity: 0.6, dashArray: '6,4', fillOpacity: 0.06}).bindTooltip(tip).addTo(groups.dispatch);
            d.geo.forEach(p => extend(p[0], p[1]));
        }
    });
    ARCHIVE.areas.forEach(a => {
        if (!Array.isArray(a.geo) || !a.geo.length) return;
        L.polygon(a.geo, {color: '#495057', weight: 2, opacity: 0.5, dashArray: '8,5', fillOpacity: 0.04}).bindTooltip(esc(a.label)).addTo(groups.sectors);
        a.geo.forEach(p => extend(p[0], p[1]));
    });
    ARCHIVE.sectors.forEach(s => {
        if (!Array.isArray(s.geo) || !s.geo.length) return;
        L.polygon(s.geo, {color: s.color || '#0d9488', weight: 1.5, opacity: 0.6, fillOpacity: 0.1}).bindTooltip(esc(s.label)).addTo(groups.sectors);
        s.geo.forEach(p => extend(p[0], p[1]));
    });
    ARCHIVE.routes.forEach(r => {
        const color = r.color || '#2563eb';
        const pts = r.waypoints.map(w => [w.lat, w.lng]);
        if (r.closed && pts.length > 2) pts.push(pts[0]);
        if (pts.length > 1) L.polyline(pts, {color, weight: 3, opacity: 0.55, dashArray: '2,8'}).bindTooltip(esc(STR['archive.route_popup'].replace('{title}', r.title))).addTo(groups.routes);
        r.waypoints.forEach((w, i) => {
            L.circleMarker([w.lat, w.lng], {radius: 5, color: '#fff', weight: 2, fillColor: color, fillOpacity: 1})
                .bindTooltip(esc(STR['archive.waypoint_popup'].replace('{n}', i + 1).replace('{label}', w.label))).addTo(groups.routes);
            extend(w.lat, w.lng);
        });
    });
    const sevColor = {low: '#16a34a', medium: '#f59e0b', high: '#ea580c', critical: '#dc2626'};
    ARCHIVE.incidents.forEach(i => {
        L.circleMarker([i.lat, i.lng], {radius: 9, color: '#fff', weight: 2, fillColor: sevColor[i.severity] || '#dc2626', fillOpacity: 1}).bindPopup(esc(i.text)).addTo(groups.incidents);
        extend(i.lat, i.lng);
    });
    const pointColor = {free: '#6c757d', active: '#f59e0b', done: '#198754'};
    ARCHIVE.mapPoints.forEach(p => {
        L.circleMarker([p.lat, p.lng], {radius: 5, color: '#fff', weight: 1.5, fillColor: pointColor[p.status] || '#6c757d', fillOpacity: 1}).bindTooltip(esc(p.name)).addTo(groups.points);
        extend(p.lat, p.lng);
    });
    Object.values(groups).forEach(g => g.addTo(map));
    document.querySelectorAll('[data-layer]').forEach(box => box.addEventListener('change', () => {
        const g = groups[box.dataset.layer];
        if (box.checked) g.addTo(map); else map.removeLayer(g);
    }));
    if (staticBounds.length) map.fitBounds(L.latLngBounds(staticBounds), {padding: [40, 40]});

    // ── Trails + replay (mission-track.php, the same endpoint the live view uses) ──
    const trailLayer = L.layerGroup().addTo(map);
    let trails = [], events = [], minTs = null, maxTs = null, timer = null;
    const statusEl = document.getElementById('archiveStatus');
    const bar = document.getElementById('archiveReplayBar');
    const scrubber = document.getElementById('archiveScrubber');
    const playBtn = document.getElementById('archivePlay');
    const timeLabel = document.getElementById('archiveScrubberTime');

    function renderEvents(cutoff) {
        const log = document.getElementById('archiveEventLog');
        const list = cutoff === Infinity ? events : events.filter(e => e.ts <= cutoff);
        if (!list.length) { log.innerHTML = '<p class="text-muted small mb-0">' + esc(STR['archive.timeline_empty']) + '</p>'; return; }
        // e.text is already HTML-escaped server-side (loadMissionActivityEventsForReport).
        log.innerHTML = list.slice().sort((a, b) => b.ts - a.ts).map(e =>
            '<div class="small py-1 border-bottom d-flex justify-content-between gap-2"><span>' + e.icon + ' ' + e.text + '</span><span class="text-muted text-nowrap">' + fmtTime(e.ts) + '</span></div>'
        ).join('');
    }
    function renderTrails(cutoff, fit) {
        trailLayer.clearLayers();
        const bounds = [];
        trails.forEach(trail => {
            const color = trail.team_color || '#2563eb';
            const pts = cutoff === Infinity ? trail.points : trail.points.filter(p => p.ts <= cutoff);
            if (!pts.length) return;
            if (pts.length > 1) L.polyline(pts.map(p => [p.lat, p.lng]), {color, weight: 3, opacity: 0.8}).addTo(trailLayer);
            pts.forEach((p, i) => {
                const last = i === pts.length - 1;
                const prev = pts[i - 1];
                const kmh = (prev && p.ts > prev.ts) ? L.latLng(prev.lat, prev.lng).distanceTo(L.latLng(p.lat, p.lng)) / (p.ts - prev.ts) * 3.6 : null;
                const popup = '<strong>' + esc(trail.name) + '</strong><br>' + esc(p.time) + (p.source === 'auto' ? esc(STR['archive.auto_suffix']) : '')
                    + '<br>' + esc(STR['archive.speed']) + ': ' + (kmh === null ? '—' : kmh.toFixed(1) + ' km/h');
                const m = last
                    ? L.circleMarker([p.lat, p.lng], {radius: 9, color: '#fff', weight: 3, fillColor: color, fillOpacity: 1}).bindTooltip(esc(trail.name), {permanent: true, direction: 'top', offset: [0, -8]})
                    : L.circleMarker([p.lat, p.lng], {radius: 4, color: '#fff', weight: 1.5, fillColor: color, fillOpacity: 1});
                m.bindPopup(popup).addTo(trailLayer);
                bounds.push([p.lat, p.lng]);
            });
        });
        if (fit && bounds.length) { map.invalidateSize(); map.fitBounds(L.latLngBounds(bounds.concat(staticBounds)), {padding: [40, 40]}); }
        renderEvents(cutoff);
    }
    function setLabel(ts) { timeLabel.textContent = fmtTime(ts); }
    function stopReplay() { if (timer) { clearInterval(timer); timer = null; } playBtn.innerHTML = '<i class="bi bi-play-fill"></i>'; }
    function setupReplay() {
        stopReplay();
        const all = trails.flatMap(t => t.points.map(p => p.ts)).concat(events.map(e => e.ts)).filter(v => v != null);
        minTs = all.length ? Math.min(...all) : null;
        maxTs = all.length ? Math.max(...all) : null;
        if (minTs === null || minTs === maxTs) { bar.classList.add('d-none'); bar.classList.remove('d-flex'); return; }
        bar.classList.remove('d-none'); bar.classList.add('d-flex');
        scrubber.min = minTs; scrubber.max = maxTs; scrubber.value = maxTs; setLabel(maxTs);
    }
    scrubber.addEventListener('input', () => { stopReplay(); const ts = Number(scrubber.value); renderTrails(ts, false); setLabel(ts); });
    playBtn.addEventListener('click', () => {
        if (timer) { stopReplay(); return; }
        if (Number(scrubber.value) >= maxTs - 1) { scrubber.value = minTs; renderTrails(minTs, false); setLabel(minTs); }
        playBtn.innerHTML = '<i class="bi bi-pause-fill"></i>';
        const step = Math.max(1, Math.round((maxTs - minTs) / 60));
        timer = setInterval(() => {
            const next = Number(scrubber.value) + step;
            if (next >= maxTs) { scrubber.value = maxTs; renderTrails(maxTs, false); setLabel(maxTs); stopReplay(); return; }
            scrubber.value = next; renderTrails(next, false); setLabel(next);
        }, 400);
    });

    function loadTrails() {
        const params = new URLSearchParams({
            mission_id: ARCHIVE.missionId,
            team_id: document.getElementById('archiveTeam').value || '0',
            include_auto: document.getElementById('archiveAuto').checked ? '1' : '0',
        });
        statusEl.textContent = STR['archive.loading'];
        fetch('mission-track.php?' + params).then(r => r.json()).then(result => {
            if (!result.ok) { statusEl.textContent = result.error || STR['archive.load_failed']; return; }
            trails = result.trails || [];
            events = result.events || [];
            statusEl.textContent = trails.length ? '' : STR['archive.no_trails'];
            renderTrails(Infinity, true);
            setupReplay();
        }).catch(() => { statusEl.textContent = STR['archive.load_failed']; });
    }
    document.getElementById('archiveShow').addEventListener('click', loadTrails);
    loadTrails();
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
