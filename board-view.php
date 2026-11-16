<?php
/**
 * VolunteerOps - Board View Link (PUBLIC - no login required, v3.499.0)
 *
 * The read-only, non-interactive, big-font «Οθόνη τοίχου» mirror of the live
 * map + team roster, for a second screen in the ops room or a liaising
 * authority. Created from the Action Room's «Σύνδεσμοι» tool.
 *
 * URL: /board-view.php?token=XXXX
 *
 * Same self-expiring token idiom as locate.php/briefing-view.php. Styled
 * after the «Πίνακας» console skin's dark wall-display look, but this is a
 * standalone minimal page, NOT the overlay skin itself: war-room.php's full
 * interactive stack is far too tightly coupled to a logged-in session
 * ($canManageWarRoom checks sprinkled throughout) to safely reuse for an
 * unauthenticated public page. Data is intentionally trimmed - see
 * includes/functions-board-links.php's own docblock for what is left out and
 * why (no vitals, no chat, no incident/SOS detail, no phone numbers).
 */
require_once __DIR__ . '/bootstrap.php';
// No requireLogin() - this page is intentionally public.

$token = trim(get('token', ''));
$link = findActiveMissionBoardLink($token);

// AJAX data poll - handled before any HTML output.
if ($link && get('ajax') === '1') {
    header('Content-Type: application/json');
    $missionId = (int) $link['mission_id'];
    $mission = dbFetchOne("SELECT title, start_datetime FROM missions WHERE id = ?", [$missionId]);
    $pins = loadBoardViewPins($missionId);
    $teams = loadBoardViewTeams($missionId, $pins);
    $gpsOn = count($pins);
    $gpsTotal = array_sum(array_column($teams, 'members_total'));
    echo json_encode([
        'ok'    => true,
        'title' => $mission['title'] ?? '',
        'pins'  => $pins,
        'teams' => $teams,
        'gps_on' => $gpsOn,
        'gps_total' => max($gpsOn, $gpsTotal),
        'sectors' => loadBoardViewSectors($missionId),
        'restricted_areas' => loadBoardViewRestrictedAreas($missionId),
        'incidents' => loadBoardViewIncidents($missionId),
        'poi' => loadBoardViewPoi($missionId),
        'server_time' => date('H:i:s'),
    ]);
    exit;
}

if ($link) {
    recordBoardLinkView((int) $link['id']);
    $mission = dbFetchOne("SELECT title FROM missions WHERE id = ?", [(int) $link['mission_id']]);
}
?><!DOCTYPE html>
<html lang="el">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0b1118">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $link ? 'Πίνακας · ' . h($mission['title'] ?? '') : 'Μη έγκυρος σύνδεσμος' ?></title>
<?php if ($link): ?>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha384-sHL9NAb7lN7rfvG5lfHpm643Xkcjzp4jFvuavGOndn6pjVqS6ny56CAt3nsEVT4H" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" integrity="sha384-XGjxtQfXaH2tnPFa9x+ruJTuLE3Aa6LhHSWRr1XeTyhezb4abCG4ccI5AkVDxqC+" crossorigin="anonymous">
<?php endif; ?>
    <style>
        :root { --bv-panel: #121a24; --bv-ink: #e7edf5; --bv-sub: #8fa3bb; --bv-border: #223047; }
        * { box-sizing: border-box; }
        html, body { margin: 0; height: 100%; background: #0b1118; color: var(--bv-ink); font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; overflow: hidden; }
        .bv-wrap { display: flex; flex-direction: column; height: 100vh; }
        .bv-head { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 14px 24px; background: var(--bv-panel); border-bottom: 1px solid var(--bv-border); flex-shrink: 0; }
        .bv-title { font-size: clamp(16px, 2vw, 26px); font-weight: 800; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .bv-stats { display: flex; gap: 20px; font-size: clamp(13px, 1.3vw, 18px); color: var(--bv-sub); font-weight: 700; flex-shrink: 0; }
        .bv-stats b { color: var(--bv-ink); }
        .bv-body { flex: 1; display: flex; min-height: 0; }
        #bvMap { flex: 1; background: #0b1118; }
        .bv-dock { width: clamp(220px, 20vw, 340px); background: var(--bv-panel); border-left: 1px solid var(--bv-border); overflow-y: auto; padding: 10px; flex-shrink: 0; }
        .bv-team { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border-radius: 12px; background: #0e151e; border: 1px solid var(--bv-border); margin-bottom: 8px; }
        .bv-team .dot { width: 14px; height: 14px; border-radius: 50%; flex-shrink: 0; }
        .bv-team .lbl { font-size: clamp(13px, 1.4vw, 17px); font-weight: 800; flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; }
        .bv-team .gps { font-size: clamp(11px, 1.1vw, 14px); font-weight: 700; color: var(--bv-sub); }
        .bv-team.tone-warn .gps { color: #f5b942; }
        .bv-team.tone-none .gps { color: #5a6b80; }
        .bv-pin-dot { width: 16px; height: 16px; border-radius: 50%; border: 2px solid #fff; box-shadow: 0 0 0 2px rgba(0,0,0,.4); }
        .bv-pin-dot.stale { opacity: .55; }
        .bv-mark { width: 22px; height: 22px; border-radius: 50%; border: 2px solid #fff; box-shadow: 0 0 0 2px rgba(0,0,0,.4); display: flex; align-items: center; justify-content: center; color: #fff; font-size: 12px; }
        .bv-legend { display: flex; gap: 14px; font-size: clamp(11px, 1.1vw, 13px); color: var(--bv-sub); flex-shrink: 0; }
        .bv-legend span { white-space: nowrap; }
        .bv-legend b { color: var(--bv-ink); }
        .bv-invalid { display: flex; align-items: center; justify-content: center; height: 100vh; text-align: center; padding: 24px; }
        .bv-invalid h1 { font-size: 1.3rem; }
        .bv-invalid p { color: var(--bv-sub); font-size: 14px; }
    </style>
</head>
<body>
<?php if (!$link): ?>
    <div class="bv-invalid">
        <div>
            <div style="font-size:2.5rem;color:#b91c1c;">&#10060;</div>
            <h1>Μη έγκυρος ή ληγμένος σύνδεσμος<br><span style="font-size:1rem;">Invalid or expired link</span></h1>
            <p>Αυτός ο σύνδεσμος δεν λειτουργεί πια.<br>This link no longer works.</p>
        </div>
    </div>
<?php else: ?>
    <div class="bv-wrap">
        <div class="bv-head">
            <div class="bv-title"><?= h($mission['title'] ?? '') ?></div>
            <div class="bv-legend" id="bvLegend"></div>
            <div class="bv-stats">
                <div>GPS <b id="bvGpsCount">0/0</b></div>
                <div id="bvClock">--:--:--</div>
            </div>
        </div>
        <div class="bv-body">
            <div id="bvMap"></div>
            <div class="bv-dock" id="bvDock"></div>
        </div>
    </div>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha384-cxOPjt7s7Iz04uaHJceBmS+qpjv2JkIHNVcuOrM+YHwZOmJGBXI00mdUXEq65HTH" crossorigin="anonymous"></script>
    <script>
    (function() {
        const token = <?= json_encode($token) ?>;
        const map = L.map('bvMap', { zoomControl: false, attributionControl: false }).setView([35.3, 24.5], 10);
        L.control.zoom({ position: 'bottomleft' }).addTo(map);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 }).addTo(map);

        let markers = {};
        let fitted = false;
        const sectorLayer = L.layerGroup().addTo(map);
        const restrictedLayer = L.layerGroup().addTo(map);
        let incidentMarkers = {};
        let poiMarkers = {};

        function teamToneColor(tone) {
            return tone === 'none' ? '#5a6b80' : (tone === 'warn' ? '#f5b942' : '#39d98a');
        }

        function renderTeams(teams) {
            const dock = document.getElementById('bvDock');
            dock.innerHTML = teams.map(function(t) {
                return '<div class="bv-team tone-' + t.tone + '">' +
                    '<div class="dot" style="background:' + t.color + '"></div>' +
                    '<div class="lbl">' + t.label + '</div>' +
                    '<div class="gps">' + t.gps_on + '/' + t.members_total + '</div>' +
                '</div>';
            }).join('');
        }

        function renderPins(pins) {
            const seen = {};
            pins.forEach(function(p) {
                const key = 'u' + p.user_id;
                seen[key] = true;
                const color = p.team_color || '#8fa3bb';
                if (!markers[key]) {
                    const icon = L.divIcon({ className: '', html: '<div class="bv-pin-dot' + (p.is_stale ? ' stale' : '') + '" style="background:' + color + '"></div>', iconSize: [16, 16] });
                    markers[key] = L.marker([p.lat, p.lng], { icon: icon }).addTo(map);
                } else {
                    markers[key].setLatLng([p.lat, p.lng]);
                }
                const label = (p.team_label ? p.team_label + ' · ' : '') + p.name;
                markers[key].bindPopup(label);
            });
            Object.keys(markers).forEach(function(key) {
                if (!seen[key]) { map.removeLayer(markers[key]); delete markers[key]; }
            });
            if (!fitted && pins.length) {
                fitted = true;
                map.fitBounds(pins.map(function(p) { return [p.lat, p.lng]; }), { padding: [40, 40], maxZoom: 15 });
            }
        }

        const SECTOR_COLORS = { not_started: '#5a6b80', assigned: '#f5b942', in_progress: '#39a0ff', completed: '#39d98a', needs_recheck: '#e8590c' };
        function renderSectors(sectors) {
            sectorLayer.clearLayers();
            sectors.forEach(function(s) {
                if (!s.geo || s.geo.length < 3) return;
                const color = s.team_color_bg || SECTOR_COLORS[s.status] || '#5a6b80';
                const poly = L.polygon(s.geo, { color: color, weight: 2, fillColor: color, fillOpacity: 0.12 });
                poly.bindPopup(s.team_label + ' · ' + s.status_label);
                sectorLayer.addLayer(poly);
            });
        }

        const RESTRICTED_COLORS = { danger: '#dc3545', cliff: '#e8590c', forbidden: '#adb5bd' };
        function renderRestrictedAreas(areas) {
            restrictedLayer.clearLayers();
            areas.forEach(function(a) {
                if (!a.geo || a.geo.length < 2) return;
                const color = RESTRICTED_COLORS[a.kind] || '#dc3545';
                const shape = a.shape === 'line'
                    ? L.polyline(a.geo, { color: color, weight: 4 })
                    : L.polygon(a.geo, { color: color, weight: 2, fillColor: color, fillOpacity: 0.25 });
                shape.bindPopup(a.label || a.kind);
                restrictedLayer.addLayer(shape);
            });
        }

        const SEVERITY_COLORS = { low: '#6c757d', medium: '#0dcaf0', high: '#f59e0b', critical: '#dc3545' };
        function renderIncidents(incidents) {
            const seen = {};
            incidents.forEach(function(i) {
                const key = 'i' + i.id;
                seen[key] = true;
                const color = SEVERITY_COLORS[i.severity] || '#6c757d';
                if (!incidentMarkers[key]) {
                    const icon = L.divIcon({ className: '', html: '<div class="bv-mark" style="background:' + color + '"><i class="bi bi-heart-pulse-fill"></i></div>', iconSize: [22, 22] });
                    incidentMarkers[key] = L.marker([i.lat, i.lng], { icon: icon, zIndexOffset: 400 }).addTo(map);
                } else {
                    incidentMarkers[key].setLatLng([i.lat, i.lng]);
                }
                incidentMarkers[key].bindPopup(i.type_label);
            });
            Object.keys(incidentMarkers).forEach(function(key) {
                if (!seen[key]) { map.removeLayer(incidentMarkers[key]); delete incidentMarkers[key]; }
            });
        }

        function renderPoi(pois) {
            const seen = {};
            pois.forEach(function(p) {
                const key = 'p' + p.id;
                seen[key] = true;
                const color = p.checked ? '#6c757d' : '#0d6efd';
                if (!poiMarkers[key]) {
                    const icon = L.divIcon({ className: '', html: '<div class="bv-mark" style="background:' + color + '"><i class="bi bi-search"></i></div>', iconSize: [22, 22] });
                    poiMarkers[key] = L.marker([p.lat, p.lng], { icon: icon, zIndexOffset: 300 }).addTo(map);
                } else {
                    poiMarkers[key].setLatLng([p.lat, p.lng]);
                }
            });
            Object.keys(poiMarkers).forEach(function(key) {
                if (!seen[key]) { map.removeLayer(poiMarkers[key]); delete poiMarkers[key]; }
            });
        }

        function renderLegend(data) {
            const parts = ['<span><b>' + data.sectors.length + '</b> τομείς</span>'];
            if (data.incidents.length) parts.push('<span style="color:#f59e0b"><b>' + data.incidents.length + '</b> αναφορές</span>');
            if (data.poi.length) parts.push('<span><b>' + data.poi.length + '</b> σημεία ενδιαφέροντος</span>');
            if (data.restricted_areas.length) parts.push('<span style="color:#dc3545"><b>' + data.restricted_areas.length + '</b> ζώνες κινδύνου</span>');
            document.getElementById('bvLegend').innerHTML = parts.join('');
        }

        function poll() {
            fetch('board-view.php?token=' + encodeURIComponent(token) + '&ajax=1')
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (!data.ok) { document.body.innerHTML = '<div class="bv-invalid"><div><h1>Ο σύνδεσμος έληξε / Link expired</h1></div></div>'; return; }
                    document.getElementById('bvGpsCount').textContent = data.gps_on + '/' + data.gps_total;
                    document.getElementById('bvClock').textContent = data.server_time;
                    renderTeams(data.teams);
                    renderPins(data.pins);
                    renderSectors(data.sectors);
                    renderRestrictedAreas(data.restricted_areas);
                    renderIncidents(data.incidents);
                    renderPoi(data.poi);
                    renderLegend(data);
                })
                .catch(function() {});
        }
        poll();
        setInterval(poll, 5000);
    })();
    </script>
<?php endif; ?>
</body>
</html>
