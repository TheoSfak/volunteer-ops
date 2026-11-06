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
    // Looking at the past (the time machine of «Πορεία Ομάδων», assets/js/war-room-time-machine.js): the moment
    // looked at is "now", so an order finished by then fades by the same rules as it did on the live map.
    if (typeof wrTimeNow === 'function' && wrTimeNow() !== null) return wrTimeNow();
    return Date.now() / 1000 + (typeof WR_CLOCK_OFFSET === 'number' ? WR_CLOCK_OFFSET : 0);
}

// Is the team of this order out of the picture because command is looking at one other team?
// (assets/js/war-room-team-coverage.js: «Εστίαση σε ομάδα»). Out of the picture is «hidden», the very
// state «Κρυφά» uses, so a focus leaves an open popup alone, redraws only when it changed, and so on.
function wrDoneFocusHides(teamId) {
    return typeof wrFocusExcludes === 'function' && wrFocusExcludes(teamId);
}

// The people in the field see what is running, not the record (v3.443.0): they have «Οι Εντολές μου» for their own
// orders, so a finished point, area or route leaves their map once its ten bright minutes are over. A sector is
// the exception - every team seeing what is already covered, so nobody searches it twice, is the whole point of
// a sector - so it stays as its outline for everyone. The chip's list has a tick to show them all anyway.
const WR_DONE_FIELD_KEY = 'wr_done_field_all_';   // + the mission id, in sessionStorage
let wrDoneFieldQuiet = false;     // set by wrDoneInit for whoever cannot choose the mode (everyone but command)
let wrDoneFieldShowAll = false;
function wrDoneFieldHides(kind) {
    return wrDoneFieldQuiet && !wrDoneFieldShowAll && kind !== 'sector';
}

// Which phase a dispatch, a sector or a route is in, from the time its team finished it. $kind is 'dispatch',
// 'sector' or 'route'.
function wrDonePhase(ts, teamId, kind) {
    if (wrDoneFocusHides(teamId)) return 'hidden';
    if (!ts || wrDoneMode === 'normal') return 'live';
    if (wrDoneMode === 'hidden') return 'hidden';
    if (wrDoneNowSec() - ts <= WR_DONE_FRESH_SECONDS) return 'fresh';
    return wrDoneFieldHides(kind) ? 'hidden' : 'archived';
}

// Routes: an active one is live; a finished one is only on the map from
// «Ξεθωριασμένα» on (it never was in «Όπως πριν»); a cancelled one never.
function wrDoneRoutePhase(route) {
    if (wrDoneFocusHides(route.team_id)) return 'hidden';
    if (route.status === 'active') return 'live';
    if (route.status !== 'completed' || wrDoneMode === 'normal') return 'skip';
    if (wrDoneMode === 'hidden') return 'hidden';
    return wrDonePhase(route.completed_ts || 1, undefined, 'route');
}

// One character per item, for the render functions' "nothing changed" signatures.
function wrDonePhaseSig(items, kind) {
    return wrDoneMode + (wrDoneFieldHides(kind) ? 'q' : '') + ':' + (items || []).map(i => wrDonePhase(i.completed_ts, i.team_id, kind)[0]).join('');
}
function wrDoneRoutesSig(items) {
    return wrDoneMode + (wrDoneFieldHides('route') ? 'q' : '') + ':' + (items || []).map(r => wrDoneRoutePhase(r)[0]).join('');
}
// The lists as they are drawn: the present, or (time machine) as they stood at the moment looked at.
function wrDoneView(kind) {
    if (typeof wrTimeNow !== 'function') return kind === 'dispatch' ? dispatches : kind === 'sector' ? sectors : routes;
    return kind === 'dispatch' ? wrTimeDispatches(dispatches) : kind === 'sector' ? wrTimeSectors(sectors) : wrTimeRoutes(routes);
}
function wrDoneAllSig() {
    return wrDonePhaseSig(wrDoneView('dispatch'), 'dispatch') + '|' + wrDonePhaseSig(wrDoneView('sector'), 'sector') + '|' + wrDoneRoutesSig(wrDoneView('route'));
}

// ── Drawing ─────────────────────────────────────────────────────────────────
function wrDoneDotIcon(withNote) {
    return withNote
        ? L.divIcon({className: 'wr-done-dot wr-done-note', html: '<i class="bi bi-chat-left-text-fill"></i>', iconSize: [22, 22], iconAnchor: [11, 11]})
        : L.divIcon({className: 'wr-done-dot', html: '<i class="bi bi-check-lg"></i>', iconSize: [18, 18], iconAnchor: [9, 9]});
}
// What the team wrote when it finished this order (v3.443.0). The server sends it only to command and to that team
// itself, so what is here is what this viewer may read. An order with one is NOT folded into the quiet tick: it
// carries information («Αποχώρησε περιπατητικός»), so it stays brighter, with the words on hover.
function wrDoneNote(item) {
    const lines = (item.progress || []).filter(p => p.completed && p.note && (item.team_id ? p.scope === 't' + item.team_id : true));
    return lines.length ? lines[lines.length - 1].note : null;
}
function wrDoneNoteTip(note) {
    return '<br><i class="bi bi-chat-left-text-fill"></i> ' + escapeHtml(t('map.done_note_tip', {note: note.length > 140 ? note.slice(0, 139) + '…' : note}));
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
    const note = wrDoneNote(item);
    const tip = escapeHtml(item.team_label) + ' · ✓ ' + escapeHtml(item.completed_display || '') + (item.label ? ' · ' + escapeHtml(item.label) : '')
        + (note ? wrDoneNoteTip(note) : '');
    let layer = null;
    if (item.type === 'point') {
        layer = L.marker([item.geo.lat, item.geo.lng], {icon: wrDoneDotIcon(!!note), zIndexOffset: note ? -50 : -200}).addTo(dispatchLayer).bindPopup(popupHtml);
        layer.bindTooltip(tip, {direction: 'right', offset: [10, 0]});
    } else if (item.type === 'polygon') {
        layer = L.polygon(item.geo, note
            ? {color: '#d97706', weight: 2, opacity: 0.85, dashArray: '4,4', fillOpacity: 0.06}
            : {color: '#7c3aed', weight: 1.5, opacity: 0.55, dashArray: '4,4', fillOpacity: 0.03}).addTo(dispatchLayer).bindPopup(popupHtml);
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
        + (route.completed_at_display ? '<br><span class="small text-muted">✓ ' + escapeHtml(route.completed_at_display) + '</span>' : '')
        + (typeof routeReopenBtnHtml === 'function' ? routeReopenBtnHtml(route) : '');
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
    (wrDoneView('dispatch') || []).forEach(d => {
        if (!d.completed_ts || d.map_point_id || wrDoneFocusHides(d.team_id)) return;
        out.push({kind: 'dispatch', id: d.id, ts: d.completed_ts, team: d.team_label || '', label: d.label || '', time: d.completed_display || '', note: !!wrDoneNote(d)});
    });
    (wrDoneView('sector') || []).forEach(s => {
        if (!s.completed_ts || wrDoneFocusHides(s.team_id)) return;
        out.push({kind: 'sector', id: s.id, ts: s.completed_ts, team: s.team_label || '', label: s.label || '', time: s.status_updated_at || ''});
    });
    (wrDoneView('route') || []).forEach(r => {
        if (r.status !== 'completed' || wrDoneFocusHides(r.team_id)) return;
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
    // A row of the list is a wish to see that order: if the field's quiet default has taken it off the map, it comes back.
    if (wrDoneFieldHides(kind)) wrDoneFieldSet(true);
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

// Hovering a row of the list lights that order up on the map (v3.443.0): an amber outline drawn from the same
// lists the layers are drawn from (so it is right in the time machine too), on a layer of its own that is
// never part of the order layers, so no redraw of them can leave it behind.
let wrDoneHoverLayer = null;
function wrDoneHoverOff() {
    if (wrDoneHoverLayer) { wrDoneHoverLayer.remove(); wrDoneHoverLayer = null; }
}
function wrDoneHoverOn(kind, id) {
    wrDoneHoverOff();
    if (typeof map === 'undefined' || !map) return;
    const find = list => (list || []).find(x => String(x.id) === String(id));
    const style = {color: '#f59e0b', weight: 4, opacity: 0.95, interactive: false};
    const shapes = [];
    if (kind === 'dispatch') {
        const d = find(wrDoneView('dispatch'));
        if (!d) return;
        shapes.push(d.type === 'point'
            ? L.circleMarker([d.geo.lat, d.geo.lng], Object.assign({radius: 16, fill: false}, style))
            : L.polygon(d.geo, Object.assign({fill: false}, style)));
    } else if (kind === 'sector') {
        const s = find(wrDoneView('sector'));
        if (!s) return;
        shapes.push(L.polygon(s.geo, Object.assign({fill: false}, style)));
    } else {
        const r = find(wrDoneView('route'));
        if (!r || !r.waypoints.length) return;
        const coords = r.waypoints.map(w => [w.lat, w.lng]);
        if (coords.length > 1) shapes.push(L.polyline(coords, style));
        coords.forEach(c => shapes.push(L.circleMarker(c, Object.assign({radius: 9, fill: false}, style))));
    }
    wrDoneHoverLayer = L.layerGroup(shapes).addTo(map);
}

// The field's tick «Εμφάνιση των ολοκληρωμένων εντολών στον χάρτη» (v3.443.0). A choice for this tab only.
function wrDoneFieldSet(on) {
    if (wrDoneFieldShowAll === !!on) return;
    wrDoneFieldShowAll = !!on;
    try { sessionStorage.setItem(WR_DONE_FIELD_KEY + wrDoneMissionId, on ? '1' : '0'); } catch (e) {}
    wrDoneChipSig = null;
    wrDoneRerender();
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
                        wrDoneHoverOff();
                        wrDoneChipSig = null;
                        wrDoneChipRefresh();
                    }
                });
                // A row lit on the map while the pointer (or the keyboard) is on it.
                const rowOf = ev => ev.target.closest ? ev.target.closest('[data-done-kind]') : null;
                const over = ev => { const row = rowOf(ev); if (row) wrDoneHoverOn(row.dataset.doneKind, row.dataset.doneId); };
                const out = ev => { const row = rowOf(ev); if (row && !(ev.relatedTarget && row.contains(ev.relatedTarget))) wrDoneHoverOff(); };
                div.addEventListener('mouseover', over);
                div.addEventListener('mouseout', out);
                div.addEventListener('focusin', over);
                div.addEventListener('focusout', out);
                div.addEventListener('change', ev => {
                    if (ev.target.matches && ev.target.matches('[data-done-field]')) wrDoneFieldSet(ev.target.checked);
                });
                return div;
            },
        });
        wrDoneChip = new Chip().addTo(map);
    }
    const sig = entries.map(e => e.kind + e.id + e.time + (e.note ? 'n' : '')).join(',') + '|' + wrDoneListOpen + '|' + wrDoneFieldQuiet + wrDoneFieldShowAll;
    if (sig === wrDoneChipSig) return;
    wrDoneChipSig = sig;
    wrDoneHoverOff();   // the rows below are about to be replaced; a pointer on one of them would not say it left
    // For the people in the field the finished points, areas and routes are off the map (wrDoneFieldHides): a tick says
    // so on the chip, and the list opens with the tick that brings them back.
    const fieldQuiet = wrDoneFieldQuiet && entries.some(e => e.kind !== 'sector');
    const fieldToggle = wrDoneFieldQuiet
        ? `<label class="wr-done-field"><input type="checkbox" data-done-field ${wrDoneFieldShowAll ? 'checked' : ''}> <span>${escapeHtml(t('map.done_field_toggle'))}</span></label>`
        : '';
    const rows = wrDoneListOpen ? '<div class="wr-done-list">' + fieldToggle + entries.map(e =>
        `<button type="button" class="wr-done-row" data-done-kind="${e.kind}" data-done-id="${e.id}">`
        + `<i class="bi ${WR_DONE_KIND_ICON[e.kind]}"></i>`
        + `<span class="t">${escapeHtml([e.team, e.label].filter(Boolean).join(' · '))}</span>`
        + (e.note ? '<i class="bi bi-chat-left-text-fill wr-done-note-ico"></i>' : '')
        + `<span class="m">${escapeHtml(wrDoneTimeLabel(e))}</span></button>`).join('') + '</div>' : '';
    wrDoneChip.getContainer().innerHTML = rows
        + `<button type="button" class="wr-done-btn" data-done-toggle aria-expanded="${wrDoneListOpen ? 'true' : 'false'}" title="${escapeHtml(t('map.done_list_title'))}">`
        + `<i class="bi bi-check-circle"></i><span>${escapeHtml(t('map.done_chip', {n: entries.length}))}</span>`
        + (fieldQuiet && !wrDoneFieldShowAll ? `<i class="bi bi-eye-slash" title="${escapeHtml(t('map.done_field_note'))}"></i>` : '')
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
    // Whoever cannot choose the mode - everyone but command - gets the field's quiet default (see wrDoneFieldHides).
    wrDoneFieldQuiet = !canChoose;
    try {
        const stored = sessionStorage.getItem(WR_DONE_MODE_KEY + missionId);
        if (canChoose && WR_DONE_MODES.includes(stored)) wrDoneMode = stored;
        if (wrDoneFieldQuiet) wrDoneFieldShowAll = sessionStorage.getItem(WR_DONE_FIELD_KEY + missionId) === '1';
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
    // Folded: the menu is already as tall as a laptop's window, and its state is on the fold's own line.
    const li = document.createElement('li');
    li.innerHTML = '<hr class="dropdown-divider"><details class="wr-menu-sec"><summary class="dropdown-header wr-menu-sum"><span class="wr-sum-t"></span><span class="wr-sum-s wr-done-sum"></span></summary>'
        + '<div class="px-3 pb-1"><div class="btn-group btn-group-sm w-100 wr-done-modes" role="group"></div><div class="small text-muted mt-1 wr-done-hint"></div></div></details>';
    li.querySelector('.wr-sum-t').textContent = t('map.done_header');
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
    // At the end: that is the menu's second column, which is the shorter one. In the first, under the shapes it
    // goes with, it made the menu taller than a laptop's window.
    menu.appendChild(li);
}

// The menu's buttons and the line of the fold, from the choice.
function wrDoneUi() {
    document.querySelectorAll('[data-done-mode]').forEach(b => b.classList.toggle('active', b.dataset.doneMode === wrDoneMode));
    document.querySelectorAll('.wr-done-sum').forEach(e => { e.textContent = '· ' + t('map.done_mode_' + wrDoneMode); });
}

// «14:59», or «03/11 14:59» for something finished more than half a day ago: the
// server writes the time of a pin as «H:i» and of a sector or a route as «d/m H:i».
function wrDoneTimeLabel(e) {
    const hm = String(e.time || '').slice(-5);
    if (!e.ts || wrDoneNowSec() - e.ts < 12 * 3600) return hm;
    const d = new Date(e.ts * 1000);
    return String(d.getDate()).padStart(2, '0') + '/' + String(d.getMonth() + 1).padStart(2, '0') + ' ' + hm;
}
