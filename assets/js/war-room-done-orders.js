/**
 * VolunteerOps - finished orders on the live map (v3.439.0).
 *
 * An order that is done stops being something to act on and becomes a record
 * of what was covered. By the time its team finished it (completed_ts in the
 * payload) it is in one of three phases:
 *
 *   live      still going, or never finished: drawn as ever
 *   fresh     finished less than WR_DONE_FRESH_SECONDS ago: drawn as ever, plus a
 *             green ring round a pin and a tick after a sector's name, so command
 *             sees it end
 *   archived  older: a pin is a small tick dot with no label, a sector only its
 *             outline in the colour of the team that did it (no fill, no name),
 *             a route a thin faint line. The name is one hover away and the full
 *             popup one click away, exactly as before.
 *
 * The layers menu has a three-way choice: «Όπως πριν» (no phases; a finished
 * route stays off the map, as it always was), «Ξεθωριασμένα» (the default) and
 * «Κρυφά» (every finished order off the map, announced by the same amber chip
 * as any other hidden layer). A counter chip at the bottom left lists what has
 * been finished, one click to fly to it.
 *
 * Nothing here changes a record, and nothing that still asks for something
 * fades: completed_ts is only ever set by a team's own completion (an order
 * sent to every team has none, a team that said «Δεν μπορώ» has none, a sector
 * sent back for a recheck is no longer «completed»).
 *
 * The minutes are counted here, in the browser, against the server's clock as
 * it was when the page loaded (WR_CLOCK_OFFSET), because the 5-second poll is
 * hashed and nothing in it may slide with the time of the request.
 *
 * Loaded before the page's own script, so it only DECLARES things here and
 * reaches for the page's globals (map, dispatches, sectors, routes, the three
 * layer groups, t(), escapeHtml(), the render functions) when it is called.
 */

const WR_DONE_FRESH_SECONDS = 600;
const WR_DONE_MODES = ['normal', 'faded', 'hidden'];
const WR_DONE_MODE_KEY = 'wr_done_mode_';   // + the mission id, in sessionStorage
const WR_DONE_OUTLINE_FALLBACK = '#1cc88a'; // a finished sector with no team keeps the "completed" green

let wrDoneMode = 'faded';
let wrDoneMissionId = 0;
let wrDoneAppliedSig = null;
let wrDoneChip = null, wrDoneListOpen = false, wrDoneChipSig = null, wrDoneHiddenCount = -1;

function wrDoneNowSec() {
    return Date.now() / 1000 + (typeof WR_CLOCK_OFFSET === 'number' ? WR_CLOCK_OFFSET : 0);
}

// Which phase a dispatch or a sector is in, from the time its team finished it.
function wrDonePhase(ts) {
    if (!ts || wrDoneMode === 'normal') return 'live';
    if (wrDoneMode === 'hidden') return 'hidden';
    return wrDoneNowSec() - ts > WR_DONE_FRESH_SECONDS ? 'archived' : 'fresh';
}

// Routes: an active one is live; a finished one is only on the map from
// «Ξεθωριασμένα» on (it never was in «Όπως πριν»); a cancelled one never.
function wrDoneRoutePhase(route) {
    if (route.status === 'active') return 'live';
    if (route.status !== 'completed' || wrDoneMode === 'normal') return 'skip';
    if (wrDoneMode === 'hidden') return 'hidden';
    return wrDonePhase(route.completed_ts || 1);
}

// One character per item, for the render functions' "nothing changed" signatures.
function wrDonePhaseSig(items) {
    return wrDoneMode + ':' + (items || []).map(i => wrDonePhase(i.completed_ts)[0]).join('');
}
function wrDoneRoutesSig(items) {
    return wrDoneMode + ':' + (items || []).map(r => wrDoneRoutePhase(r)[0]).join('');
}
function wrDoneAllSig() {
    return wrDonePhaseSig(dispatches) + '|' + wrDonePhaseSig(sectors) + '|' + wrDoneRoutesSig(routes);
}

// ── Drawing ─────────────────────────────────────────────────────────────────
function wrDoneDotIcon() {
    return L.divIcon({className: 'wr-done-dot', html: '<i class="bi bi-check-lg"></i>', iconSize: [18, 18], iconAnchor: [9, 9]});
}
// Centred on the pin's head, 15 px above the point it marks.
function wrDoneRingIcon() {
    return L.divIcon({className: 'wr-done-ring', html: '', iconSize: [44, 44], iconAnchor: [22, 37]});
}
function wrDoneAddRing(item) {
    L.marker([item.geo.lat, item.geo.lng], {icon: wrDoneRingIcon(), interactive: false, keyboard: false, zIndexOffset: -100}).addTo(dispatchLayer);
}

// An archived dispatch: the same popup as ever, none of the permanent label.
function wrDoneDrawDispatch(item, popupHtml) {
    const tip = escapeHtml(item.team_label) + ' · ✓ ' + escapeHtml(item.completed_display || '') + (item.label ? ' · ' + escapeHtml(item.label) : '');
    let layer = null;
    if (item.type === 'point') {
        layer = L.marker([item.geo.lat, item.geo.lng], {icon: wrDoneDotIcon(), zIndexOffset: -200}).addTo(dispatchLayer).bindPopup(popupHtml);
        layer.bindTooltip(tip, {direction: 'right', offset: [10, 0]});
    } else if (item.type === 'polygon') {
        layer = L.polygon(item.geo, {color: '#7c3aed', weight: 1.5, opacity: 0.55, dashArray: '4,4', fillOpacity: 0.03}).addTo(dispatchLayer).bindPopup(popupHtml);
        markMapPickable(layer, {kind: 'dispatch', id: item.id, color: '#7c3aed',
            title: item.team_label + (item.label ? ' — ' + item.label : ''), sub: t('map_pick.kind_dispatch')});
        layer.bindTooltip(tip, {sticky: true});
    }
    return layer;
}

// The outline of an archived sector, in its team's own colour.
function wrDoneSectorOutline(item, statusColor) {
    return item.team_id && item.team_color_bg ? item.team_color_bg : (statusColor || WR_DONE_OUTLINE_FALLBACK);
}
function wrDoneSectorTip(item) {
    return escapeHtml(item.label) + (item.team_label ? ' · ' + escapeHtml(item.team_label) : '') + (item.status_updated_at ? ' · ✓ ' + escapeHtml(item.status_updated_at) : '');
}

// An archived route: a thin faint line and small dots, no numbers.
function wrDoneDrawRoute(route) {
    const color = route.team_color_bg || '#0d6efd';
    const head = '<strong>' + escapeHtml(route.team_label || '') + (route.title ? ' — ' + escapeHtml(route.title) : '') + '</strong>'
        + (route.completed_at_display ? '<br><span class="small text-muted">✓ ' + escapeHtml(route.completed_at_display) + '</span>' : '');
    const coords = route.waypoints.map(w => [w.lat, w.lng]);
    if (route.is_closed_loop && coords.length >= 3) {
        L.polygon(coords, {color, weight: 2, opacity: 0.35, fillOpacity: 0, interactive: false}).addTo(routeLayer);
    } else if (coords.length >= 2) {
        L.polyline(coords, {color, weight: 2, opacity: 0.35, interactive: false}).addTo(routeLayer);
    }
    route.waypoints.forEach(wp => {
        L.circleMarker([wp.lat, wp.lng], {radius: 4, color: '#198754', weight: 1, opacity: 0.6, fillColor: '#198754', fillOpacity: 0.6})
            .addTo(routeLayer).bindPopup(head)
            .bindTooltip(escapeHtml(route.team_label || '') + ' · ' + wp.seq + (wp.label ? '. ' + escapeHtml(wp.label) : ''), {direction: 'top'});
    });
}

// ── The list behind the counter ─────────────────────────────────────────────
function wrDoneEntries() {
    const out = [];
    (dispatches || []).forEach(d => {
        if (!d.completed_ts || d.map_point_id) return;
        out.push({kind: 'dispatch', id: d.id, ts: d.completed_ts, team: d.team_label || '', label: d.label || '', time: d.completed_display || ''});
    });
    (sectors || []).forEach(s => {
        if (!s.completed_ts) return;
        out.push({kind: 'sector', id: s.id, ts: s.completed_ts, team: s.team_label || '', label: s.label || '', time: s.status_updated_at || ''});
    });
    (routes || []).forEach(r => {
        if (r.status !== 'completed') return;
        out.push({kind: 'route', id: r.id, ts: r.completed_ts || 0, team: r.team_label || '', label: r.title || '', time: r.completed_at_display || ''});
    });
    return out.sort((a, b) => b.ts - a.ts);
}

const WR_DONE_KIND_ICON = {dispatch: 'bi-geo-alt-fill', sector: 'bi-grid-3x3-gap-fill', route: 'bi-signpost-split-fill'};
const WR_DONE_KIND_OVERLAY = {dispatch: 'dispatch', sector: 'sectors', route: 'routes'};

function wrDoneFindLayer(kind, id) {
    let found = null;
    const group = kind === 'dispatch' ? dispatchLayer : kind === 'sector' ? sectorLayer : null;
    if (!group) return null;
    group.eachLayer(l => { if (String(kind === 'dispatch' ? l.dispatchId : l.sectorId) === String(id)) found = l; });
    return found;
}

// Fly to a finished order and open what it says. The layers are rebuilt by the
// poll, so the one to open is looked up again when the flight ends.
function wrDoneFocus(kind, id) {
    if (typeof mapOverlayEnsureShown === 'function') mapOverlayEnsureShown(WR_DONE_KIND_OVERLAY[kind]);
    let bounds = null;
    if (kind === 'dispatch') {
        const d = (dispatches || []).find(x => String(x.id) === String(id));
        if (d) bounds = d.type === 'point' ? L.latLngBounds([[d.geo.lat, d.geo.lng]]) : L.latLngBounds(d.geo);
    } else if (kind === 'sector') {
        const s = (sectors || []).find(x => String(x.id) === String(id));
        if (s) bounds = L.latLngBounds(s.geo);
    } else {
        const r = (routes || []).find(x => String(x.id) === String(id));
        if (r && r.waypoints.length) bounds = L.latLngBounds(r.waypoints.map(w => [w.lat, w.lng]));
    }
    if (!bounds || !bounds.isValid()) return;
    map.flyToBounds(bounds, {maxZoom: 17, padding: [60, 60], duration: 0.6});
    map.once('moveend', () => {
        const layer = wrDoneFindLayer(kind, id);
        if (layer && layer._map) layer.openPopup();
    });
}

function wrDoneChipRefresh() {
    if (typeof map === 'undefined' || !map) return;
    const entries = wrDoneEntries();
    // «Κρυφά»: the amber chip of the layers menu says how many are hidden.
    if (wrDoneMode === 'hidden') {
        if (entries.length !== wrDoneHiddenCount) {
            wrDoneHiddenCount = entries.length;
            if (typeof refreshMapOverlayUi === 'function') refreshMapOverlayUi();
        }
    } else {
        wrDoneHiddenCount = -1;
    }
    if (wrDoneMode !== 'faded' || !entries.length) {
        if (wrDoneChip) { wrDoneChip.remove(); wrDoneChip = null; wrDoneListOpen = false; wrDoneChipSig = null; }
        return;
    }
    if (!wrDoneChip) {
        const Chip = L.Control.extend({
            options: {position: 'bottomleft'},
            onAdd: function () {
                const div = L.DomUtil.create('div', 'leaflet-control wr-done-chip');
                L.DomEvent.disableClickPropagation(div);
                L.DomEvent.disableScrollPropagation(div);
                div.addEventListener('click', ev => {
                    const row = ev.target.closest('[data-done-kind]');
                    if (row) { wrDoneFocus(row.dataset.doneKind, row.dataset.doneId); return; }
                    if (ev.target.closest('[data-done-toggle]')) {
                        wrDoneListOpen = !wrDoneListOpen;
                        wrDoneChipSig = null;
                        wrDoneChipRefresh();
                    }
                });
                return div;
            },
        });
        wrDoneChip = new Chip().addTo(map);
    }
    const sig = entries.map(e => e.kind + e.id + e.time).join(',') + '|' + wrDoneListOpen;
    if (sig === wrDoneChipSig) return;
    wrDoneChipSig = sig;
    const rows = wrDoneListOpen ? '<div class="wr-done-list">' + entries.map(e =>
        `<button type="button" class="wr-done-row" data-done-kind="${e.kind}" data-done-id="${e.id}">`
        + `<i class="bi ${WR_DONE_KIND_ICON[e.kind]}"></i>`
        + `<span class="t">${escapeHtml([e.team, e.label].filter(Boolean).join(' · '))}</span>`
        + `<span class="m">${escapeHtml(wrDoneTimeLabel(e))}</span></button>`).join('') + '</div>' : '';
    wrDoneChip.getContainer().innerHTML = rows
        + `<button type="button" class="wr-done-btn" data-done-toggle aria-expanded="${wrDoneListOpen ? 'true' : 'false'}" title="${escapeHtml(t('map.done_list_title'))}">`
        + `<i class="bi bi-check-circle"></i><span>${escapeHtml(t('map.done_chip', {n: entries.length}))}</span>`
        + `<i class="bi bi-chevron-${wrDoneListOpen ? 'down' : 'up'} ms-auto"></i></button>`;
}

// ── The choice, and the clock ───────────────────────────────────────────────
function wrDoneRerender() {
    wrDoneAppliedSig = wrDoneAllSig();
    renderDispatches(dispatches);
    renderSectorLayer(sectors);
    renderRouteLayer(routes);
    wrDoneChipRefresh();
    if (typeof refreshMapOverlayUi === 'function') refreshMapOverlayUi();
}

function wrDoneSetMode(mode) {
    if (!WR_DONE_MODES.includes(mode) || mode === wrDoneMode) return;
    wrDoneMode = mode;
    try { sessionStorage.setItem(WR_DONE_MODE_KEY + wrDoneMissionId, mode); } catch (e) {}
    wrDoneRerender();
}

// A finished order crosses into the next phase with no poll to tell the page,
// so a slow timer looks for it. It redraws only when some phase really changed.
function wrDoneTick() {
    if (typeof map === 'undefined' || !map) return;
    if (wrDoneAllSig() !== wrDoneAppliedSig) wrDoneRerender();
}

// Called once by the page, just before its first render of the layers (the
// page's own memo variables do not exist any earlier). Only command can choose
// «Κρυφά», so only command ever carries it back from the session: a
// volunteer's tab must not start with a state it cannot undo.
function wrDoneInit(missionId, canChoose) {
    wrDoneMissionId = missionId;
    try {
        const stored = sessionStorage.getItem(WR_DONE_MODE_KEY + missionId);
        if (canChoose && WR_DONE_MODES.includes(stored)) wrDoneMode = stored;
    } catch (e) {}
    if (typeof map === 'undefined' || !map) return;
    if (canChoose) wrDoneAddMenu();
    wrDoneAppliedSig = wrDoneAllSig();
    if (typeof refreshMapOverlayUi === 'function') refreshMapOverlayUi();
    setInterval(wrDoneTick, 20000);
}

function wrDoneAddMenu() {
    const btn = document.getElementById('mapSatelliteToggle');
    const menu = btn?.parentNode.querySelector('.dropdown-menu');
    if (!menu) return;
    const li = document.createElement('li');
    li.innerHTML = '<hr class="dropdown-divider"><h6 class="dropdown-header"></h6>'
        + '<div class="px-3 pb-1"><div class="btn-group btn-group-sm w-100 wr-done-modes" role="group"></div><div class="small text-muted mt-1 wr-done-hint"></div></div>';
    li.querySelector('h6').textContent = t('map.done_header');
    li.querySelector('.wr-done-hint').textContent = t('map.done_hint', {min: WR_DONE_FRESH_SECONDS / 60});
    const group = li.querySelector('.wr-done-modes');
    WR_DONE_MODES.forEach(mode => {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'btn btn-outline-primary';
        b.dataset.doneMode = mode;
        b.textContent = t('map.done_mode_' + mode);
        b.addEventListener('click', () => wrDoneSetMode(mode));
        group.appendChild(b);
    });
    // Right under the switches for the shapes it is a sibling of, not at the very end of the menu.
    const after = [...menu.querySelectorAll('[data-pin-team-labels], [data-overlay]')].pop()?.closest('li');
    if (after) after.after(li); else menu.appendChild(li);
}

// «14:59», or «03/11 14:59» for something finished more than half a day ago: the
// server writes the time of a pin as «H:i» and of a sector or a route as «d/m H:i».
function wrDoneTimeLabel(e) {
    const hm = String(e.time || '').slice(-5);
    if (!e.ts || wrDoneNowSec() - e.ts < 12 * 3600) return hm;
    const d = new Date(e.ts * 1000);
    return String(d.getDate()).padStart(2, '0') + '/' + String(d.getMonth() + 1).padStart(2, '0') + ' ' + hm;
}
