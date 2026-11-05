/*
 * War Room (Action Room) - the «Πίνακας» look of the admin «Κονσόλα»
 * (Settings → Εμφάνιση Κονσόλας = overlay; assets/css/war-room-console-overlay.css).
 *
 * The other looks are colours over the same three columns. This one is a LAYOUT: the map takes the
 * whole window and everything else floats over it as dark translucent squares and panels - a top
 * bar, a rail of the six kinds of order on the left, the drawing tools and then the map's tools
 * (map view, follow, zoom, ruler, heat, command post, map points) in one column at the right edge -
 * each folding to a single button - a segmented bar with the teams at the bottom, the queue and
 * the feed as floating cards.
 *
 * Nothing is copied. The controls that already exist are MOVED into those places (the map's
 * buttons, the zoom, the export menu, the hero's tools, the phase switch, the counters) and the
 * few new ones - the rail, the team bar, the selected-team card - press the console's own buttons
 * or ask it through window.wrConsole (view(), pickTeam(), clearSelection()). Below 1200 px the
 * controls go back where they were and the console is the three-column one again.
 *
 * The first half of the file is plain functions (tests/js/war-room-console-overlay.test.js).
 */

// ── plain functions ──────────────────────────────────────────────────────────

/**
 * Which teams the bottom bar shows: `cap` of `count`, starting at `start`, moved only as far as it
 * takes to keep the focused team (index, or -1) in view. -> {start, size, more}
 */
function wrcOvWindow(count, focusIndex, start, cap) {
    const size = Math.max(0, Math.min(cap, count));
    let first = Math.max(0, Math.min(start, count - size));
    if (focusIndex >= 0 && size > 0) {
        if (focusIndex < first) first = focusIndex;
        else if (focusIndex >= first + size) first = focusIndex - size + 1;
    }
    return {start: first, size, more: count > size};
}

/** The team before (-1) or after (+1) the focused one, going round; with none focused, the first or the last. */
function wrcOvStep(count, focusIndex, dir) {
    if (count <= 0) return -1;
    if (focusIndex < 0) return dir > 0 ? 0 : count - 1;
    return (focusIndex + dir + count) % count;
}

/** The mission clock: hours and minutes since the start, or before it (a minus sign) - {text, before}. */
function wrcOvElapsed(startTs, nowTs) {
    if (!startTs) return null;
    const diff = Math.floor(nowTs - startTs);
    const abs = Math.abs(diff);
    const pad = n => String(n).padStart(2, '0');
    return {text: (diff < 0 ? '−' : '') + pad(Math.floor(abs / 3600)) + ':' + pad(Math.floor((abs % 3600) / 60)), before: diff < 0};
}

/** The lowest battery level among these people's last positions, or null when none reported one. */
function wrcOvLowestBattery(memberIds, pins) {
    const ids = new Set((memberIds || []).map(Number));
    let low = null;
    (pins || []).forEach(p => {
        if (!ids.has(Number(p.user_id)) || p.battery_level === null || p.battery_level === undefined) return;
        const level = Number(p.battery_level);
        if (!isNaN(level) && (low === null || level < low)) low = level;
    });
    return low;
}

/** «28/31»: of the people whose GPS is on, how many are heard from. -> {text, tone} or null with nobody on GPS. */
function wrcOvGpsReadout(gps) {
    if (!gps || !(gps.total > 0)) return null;
    const heard = Math.max(0, gps.total - gps.silent);
    return {text: heard + '/' + gps.total, tone: heard === 0 ? 'bad' : (gps.silent > 0 ? 'warn' : 'ok')};
}

/**
 * What the selected-team card says about a team (the console's view() item): four rows and the stripe's colour.
 * A battery at or under `low` is a warning and at or under `critical` an alarm - the page's own thresholds
 * (Settings → low battery), so the card and the map's pin popups agree.
 */
function wrcOvTeamRows(team, pins, low, critical) {
    low = low === undefined ? 30 : low;
    critical = critical === undefined ? 15 : critical;
    const first = team.orders && team.orders[0];
    const battery = wrcOvLowestBattery(team.members, pins);
    const contact = team.contact || {tone: 'none', stale: 0, total: 0};
    const rows = {
        order: first ? {kind: first.kind, detail: first.detail || '', state: first.state, tone: first.tone, more: team.orders.length - 1} : null,
        progress: team.ring ? Math.round(team.ring.f * 100) : null,
        contact: contact.total > 0 ? {text: (contact.total - contact.stale) + '/' + contact.total, tone: contact.tone} : null,
        battery: battery === null ? null : {text: battery + '%', tone: battery <= critical ? 'bad' : (battery <= low ? 'warn' : 'ok')},
    };
    const tones = [rows.order && rows.order.tone, rows.contact && rows.contact.tone, rows.battery && rows.battery.tone];
    rows.tone = tones.includes('bad') ? 'bad' : (tones.includes('warn') ? 'warn' : 'ok');
    return rows;
}

/** The streams that are on air, newest first. Rows: {id, uid, status, name, left}. */
function wrcOvLiveStreams(rows) {
    return (rows || []).filter(r => r.status === 'live').sort((a, b) => b.id - a.id);
}

/**
 * Which stream the floating tile shows and whether it shows at all. `live` is wrcOvLiveStreams(), `current`
 * the stream shown last time and `dismissed` a Set of stream ids command has put away: a new stream is not
 * in it, so it brings the tile back by itself. -> {id, show}
 */
function wrcOvTilePick(live, current, dismissed) {
    if (!live.length) return {id: null, show: false};
    const open = live.filter(s => !dismissed.has(s.id));
    if (!open.length) return {id: (live.find(s => s.id === current) || live[0]).id, show: false};
    return {id: (open.find(s => s.id === current) || open[0]).id, show: true};
}

/** Seconds left of a stream as «mm:ss» (nothing while it is not known). */
function wrcOvLeft(seconds) {
    if (typeof seconds !== 'number' || isNaN(seconds)) return '';
    const left = Math.max(0, Math.floor(seconds));
    return String(Math.floor(left / 60)).padStart(2, '0') + ':' + String(left % 60).padStart(2, '0');
}

/**
 * A position as degrees, minutes and seconds with the compass letters: 35.2142, 24.7519 -> «35°12'51" Β, 24°45'07" Α».
 * `compass` is the four letters north, south, east, west in one string («ΒΝΑΔ», «NSEW»).
 */
function wrcOvDms(lat, lng, compass) {
    const letters = Array.from(compass || 'NSEW');
    const part = (value, positive, negative) => {
        const abs = Math.abs(value);
        let deg = Math.floor(abs);
        let min = Math.floor((abs - deg) * 60);
        let sec = Math.round(((abs - deg) * 60 - min) * 60);
        if (sec === 60) { sec = 0; min += 1; }
        if (min === 60) { min = 0; deg += 1; }
        return deg + '°' + String(min).padStart(2, '0') + "'" + String(sec).padStart(2, '0') + '" ' + (value < 0 ? negative : positive);
    };
    return part(lat, letters[0], letters[1]) + ', ' + part(lng, letters[2], letters[3]);
}

/** The cell a height is asked for and remembered by: about 100 m, which is what the terrain model resolves (90 m). */
function wrcOvCell(lat, lng) {
    return lat.toFixed(3) + ',' + lng.toFixed(3);
}

/**
 * A rolling allowance: at most `max` asks in the last `windowMs`. `stamps` are the times of the asks so far;
 * -> {ok, stamps} with `now` added when the ask is allowed. (The server's own limit for heights is shared with
 * the point menu and the measuring tool, so the pointer may only ever use a part of it.)
 */
function wrcOvBudget(stamps, now, max, windowMs) {
    const recent = (stamps || []).filter(ts => ts > now - windowMs);
    if (recent.length >= max) return {ok: false, stamps: recent};
    return {ok: true, stamps: recent.concat([now])};
}

/**
 * May the pointer ask the server for a height now? The allowance above, and two more rules: one ask at a time
 * (a service that does not answer would otherwise pile the asks up, and each one holds one of the host's few PHP
 * workers for seconds, which is what slowed every other request of the page, the map layer's included), and none
 * while a failed ask is being waited out. `ask` = {stamps, busy, pausedUntil}. -> {ok, stamps}.
 */
function wrcOvHeightGate(ask, now, max, windowMs) {
    const a = ask || {};
    if (a.busy || now < (a.pausedUntil || 0)) return {ok: false, stamps: (a.stamps || []).filter(ts => ts > now - windowMs)};
    return wrcOvBudget(a.stamps, now, max, windowMs);
}

/** When the pointer may ask again once an answer is in: at once after a height, after `pauseMs` after none (no service, the quota, a failure). */
function wrcOvHeightPausedUntil(now, gotHeight, pauseMs) {
    return gotHeight ? 0 : now + pauseMs;
}

/**
 * Where the tooltip goes: to the lower right of the pointer, or to the other side of it when that side has no
 * room. (x, y) the pointer, (w, h) the tooltip, (vw, vh) the window. -> {x, y} the tooltip's top left corner.
 */
function wrcOvTipAt(x, y, w, h, vw, vh, gap) {
    const g = gap === undefined ? 20 : gap;
    let left = x + g;
    let top = y + g;
    if (left + w > vw - 4) left = Math.max(4, x - g - w);
    if (top + h > vh - 4) top = Math.max(4, y - g - h);
    return {x: Math.round(left), y: Math.round(top)};
}

/**
 * Where a cell sits on the colour scale, 0 (blue) to 1 (red): its seconds on a logarithmic scale up to `scale`
 * (the answer's 95th percentile). Logarithmic because the difference between ten seconds and a minute matters
 * as much as the one between an hour and a night, and clamped so the few cells above the scale are simply red.
 */
function wrcOvHeatT(seconds, scale) {
    const top = Math.max(1, scale || 1);
    return Math.max(0, Math.min(1, Math.log1p(Math.max(0, seconds)) / Math.log1p(top)));
}

/** The colour at `t` on the scale blue - cyan - green - yellow - red, with the fainter end more transparent. -> {r, g, b, a} */
function wrcOvHeatColor(t) {
    const stops = [[0, [44, 70, 200]], [0.25, [44, 195, 214]], [0.5, [63, 208, 90]], [0.75, [242, 211, 58]], [1, [229, 50, 45]]];
    const x = Math.max(0, Math.min(1, t));
    let i = 1;
    while (i < stops.length - 1 && x > stops[i][0]) i++;
    const [t0, c0] = stops[i - 1];
    const [t1, c1] = stops[i];
    const f = t1 === t0 ? 0 : (x - t0) / (t1 - t0);
    const mix = k => Math.round(c0[k] + (c1[k] - c0[k]) * f);
    return {r: mix(0), g: mix(1), b: mix(2), a: Math.round((0.38 + 0.34 * x) * 100) / 100};
}

/** How many screen pixels a cell of `cellM` metres is at this latitude and zoom (Web Mercator, 256 px tiles). */
function wrcOvHeatCellPx(cellM, lat, zoom) {
    return cellM / (156543.03392 * Math.cos(lat * Math.PI / 180) / Math.pow(2, zoom));
}

/** The busiest cell as the legend's figure: whole minutes, at least one. */
function wrcOvHeatMinutes(seconds) {
    return Math.max(1, Math.ceil((seconds || 0) / 60));
}

/**
 * A name the way somebody types it to look for it: lower case, no accents (ά → α, ΐ → ι), the final sigma
 * as an ordinary one, one space between words. Greek only needs this - nobody has to type a Latin
 * transliteration.
 */
function wrcOvNorm(s) {
    return String(s === null || s === undefined ? '' : s).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '')
        .replace(/ς/g, 'σ').replace(/\s+/g, ' ').trim();
}

/**
 * The one button of the drawing column: folding it while it is open; the icon of the armed tool, lit, while it
 * is folded and a tool is on (so a pen that is armed behind a folded column is seen); the pen and square otherwise.
 * `toolIcon` is the armed tool's own icon class (bi-pencil, ...) or ''. -> {icon, on, label}
 */
function wrcOvDrawFace(open, toolIcon) {
    if (open) return {icon: 'bi-chevron-up', on: false, label: 'fold'};
    if (toolIcon) return {icon: toolIcon, on: true, label: 'armed'};
    return {icon: 'bi-pencil-square', on: false, label: 'open'};
}

/**
 * The one button of the map tools column: folding it while it is open; the map icon while it is folded, lit when
 * something behind the fold is on (the ruler waiting for its clicks, the heat layer) so it is not forgotten there.
 * -> {icon, on, label}
 */
function wrcOvMapFace(open, lit) {
    if (open) return {icon: 'bi-chevron-up', on: false, label: 'fold'};
    return lit ? {icon: 'bi-map', on: true, label: 'lit'} : {icon: 'bi-map', on: false, label: 'open'};
}

const WRC_OV_FIND_GROUPS = ['teams', 'people', 'places'];

/**
 * What the search shows for `query`: [{key, items, more}] for the groups that have a match, in the order
 * teams, people, places, `max` items of each (`more` = how many matched beyond them). `entries` are
 * {group, title, sub, ...}. Every word typed has to be somewhere in the title or the line under it, in any
 * order; the best come first - a title that starts with what was typed, then one with a word that does,
 * then one that has it inside, then a match only in the line under the title - and equals by name, with
 * numbers counted as numbers («Ομάδα 2» before «Ομάδα 10»).
 */
function wrcOvFind(entries, query, max) {
    const words = wrcOvNorm(query).split(' ').filter(Boolean);
    if (!words.length) return [];
    const first = words[0];
    const by = {teams: [], people: [], places: []};
    (entries || []).forEach(e => {
        if (!by[e.group]) return;
        const title = wrcOvNorm(e.title);
        // What else is searched besides the title: the line under it, unless the entry says otherwise (`find`).
        const hay = title + ' ' + wrcOvNorm(e.find !== undefined ? e.find : e.sub);
        if (!words.every(w => hay.includes(w))) return;
        let rank = 3;
        if (title.startsWith(first)) rank = 0;
        else if (title.split(' ').some(w => w.startsWith(first))) rank = 1;
        else if (title.includes(first)) rank = 2;
        by[e.group].push({e, rank, title});
    });
    return WRC_OV_FIND_GROUPS.filter(key => by[key].length).map(key => {
        const sorted = by[key].sort((a, b) => a.rank - b.rank || a.title.localeCompare(b.title, 'en', {numeric: true}));
        return {key, items: sorted.slice(0, max).map(x => x.e), more: Math.max(0, sorted.length - max)};
    });
}

if (typeof module !== 'undefined' && module.exports) {
    module.exports = {wrcOvWindow, wrcOvStep, wrcOvElapsed, wrcOvLowestBattery, wrcOvGpsReadout, wrcOvTeamRows, wrcOvLiveStreams, wrcOvTilePick, wrcOvLeft, wrcOvDms, wrcOvCell, wrcOvBudget, wrcOvHeightGate, wrcOvHeightPausedUntil, wrcOvTipAt, wrcOvHeatT, wrcOvHeatColor, wrcOvHeatCellPx, wrcOvHeatMinutes, wrcOvNorm, wrcOvFind, wrcOvDrawFace, wrcOvMapFace};
}

// ── the view ─────────────────────────────────────────────────────────────────

(function () {
    'use strict';
    if (typeof document === 'undefined') return;
    const html = document.documentElement;
    const root = document.getElementById('wrConsole');
    const api = window.wrConsole;
    if (html.getAttribute('data-wrc-skin') !== 'overlay' || !root || !api || typeof api.view !== 'function' || typeof t !== 'function') return;

    const $ = (sel, ctx) => (ctx || document).querySelector(sel);
    const $$ = (sel, ctx) => Array.from((ctx || document).querySelectorAll(sel));
    const esc = s => String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const STORE = 'wr-console-' + Number(root.dataset.mission) + '-ov-';
    const load = (key, fallback) => { try { const v = localStorage.getItem(STORE + key); return v === null ? fallback : v; } catch (e) { return fallback; } };
    const save = (key, value) => { try { localStorage.setItem(STORE + key, String(value)); } catch (e) { /* blocked storage: it starts fresh */ } };
    const mk = (tag, cls, id) => { const el = document.createElement(tag); if (cls) el.className = cls; if (id) el.id = id; return el; };
    const pinsNow = () => (typeof pins !== 'undefined' && Array.isArray(pins)) ? pins : [];
    const mapNow = () => (typeof map !== 'undefined' && map) ? map : null;

    // ── the places ───────────────────────────────────────────────────────────
    const top = mk('div', 'wrc-ov-top', 'wrcOvTop');
    const rail = mk('div', 'wrc-ov-rail', 'wrcOvRail');
    // The right edge: one column holding the drawing tools (added further down) and the map's tools, in that order.
    // The map's tools are the stack (map view, follow, zoom, ruler, rings) and the pair (heat, command post, map points).
    const edge = mk('div', 'wrc-ov-edge', 'wrcOvEdge');
    const mapCol = mk('div', 'wrc-ov-mapg', 'wrcOvMapG');
    const mapBtn = mk('button', 'wrc-ov-sq wrc-ov-mapg-btn');
    const mapBody = mk('div', 'wrc-ov-mapg-body', 'wrcOvMapBody');
    const maptools = {open: true};
    const stack = mk('div', 'wrc-ov-stack', 'wrcOvStack');
    const pair = mk('div', 'wrc-ov-pair', 'wrcOvPair');
    const bottom = mk('div', 'wrc-ov-bottom', 'wrcOvBottom');
    const tools = mk('div', 'wrc-ov-tools', 'wrcOvTools');
    const op = mk('div', 'wrc-ov-op', 'wrcOvOp');
    const readout = mk('div', 'wrc-ov-read', 'wrcOvRead');
    const gear = mk('button', 'wrc-ov-sq wrc-ov-gear', 'wrcOvGear');
    gear.type = 'button';
    gear.innerHTML = '<i class="bi bi-gear-fill"></i>';
    gear.title = t('console.ov_tools');
    gear.setAttribute('aria-label', t('console.ov_tools'));
    gear.setAttribute('aria-expanded', 'false');
    mapBtn.type = 'button';
    mapBtn.setAttribute('aria-controls', 'wrcOvMapBody');
    mapBody.append(stack, pair);
    mapCol.append(mapBtn, mapBody);
    edge.appendChild(mapCol);
    root.append(top, rail, edge, bottom, tools);

    const VERBS = [
        ['point', 'bi-geo-alt-fill', t('console.verb_point')],
        ['sector', 'bi-grid-3x3-gap-fill', t('console.verb_sector')],
        ['route', 'bi-signpost-split-fill', t('console.verb_route')],
        ['task', 'bi-clipboard-check', t('console.verb_task')],
        ['say', 'bi-megaphone-fill', t('console.verb_say')],
        ['ask', 'bi-camera-fill', t('console.verb_ask')],
    ];
    rail.innerHTML = `<div class="wrc-ov-col wrc-ov-verbs" id="wrcOvVerbs">${VERBS.map(([key, icon, label]) =>
        `<button type="button" class="wrc-ov-sq" data-ov-verb="${key}" data-label="${esc(label)}" title="${esc(label)}" aria-label="${esc(label)}"><i class="bi ${icon}"></i></button>`).join('')}</div>
        <div class="wrc-ov-col wrc-ov-aux">
            <button type="button" class="wrc-ov-sq wrc-ov-live" data-ov="live" hidden><i class="bi bi-camera-video-fill"></i><span class="wrc-ov-nb"></span></button>
            <span class="wrc-ov-slot" id="wrcOvExport"></span>
            <button type="button" class="wrc-ov-sq" data-ov="teams" data-label="${esc(t('console.ov_teams_toggle'))}" title="${esc(t('console.ov_teams_toggle'))}" aria-label="${esc(t('console.ov_teams_toggle'))}"><i class="bi bi-people-fill"></i></button>
        </div>`;
    stack.innerHTML = '<div class="wrc-ov-col" data-g="nav"></div><div class="wrc-ov-col" data-g="extra"></div>';

    // The bottom bar and the readouts are repainted from the console's state; the tool panel is filled when engaged.
    const dock = $('.wrc-dock', root);
    const tabs = $('#wrcTabs', root);
    const composer = $('#wrcComposer', root);
    const sel = mk('div', 'wrc-ov-sel');
    sel.hidden = true;
    dock.appendChild(sel);
    const collapse = mk('button', 'wrc-ov-collapse');
    collapse.type = 'button';
    collapse.title = t('console.ov_dock_toggle');
    collapse.setAttribute('aria-label', t('console.ov_dock_toggle'));
    collapse.innerHTML = '<i class="bi bi-chevron-down"></i>';
    dock.insertBefore(collapse, dock.firstChild);

    const TAB_ICONS = {feed: 'bi-list-ul', chat: 'bi-chat-dots-fill', media: 'bi-camera-fill', plan: 'bi-grid-fill'};
    $$('button[data-tab]', tabs).forEach(b => {
        const icon = mk('i', 'bi ' + (TAB_ICONS[b.dataset.tab] || 'bi-dot') + ' wrc-ov-ti');
        const label = b.firstChild && b.firstChild.nodeType === 3 ? b.firstChild : null;
        if (label) {
            b.title = label.textContent.trim();
            const span = mk('span', 'wrc-ov-tl');
            span.textContent = label.textContent;
            b.replaceChild(span, label);
        }
        b.insertBefore(icon, b.firstChild);
    });

    const cmpHead = mk('div', 'wrc-ov-cmp-h');
    cmpHead.innerHTML = '<i class="bi"></i><span class="wrc-ov-cmp-t"></span><button type="button" class="wrc-ov-cmp-x" data-ov="cmp-close"><i class="bi bi-x-lg"></i></button>';
    $('.wrc-ov-cmp-x', cmpHead).title = t('console.ov_cmp_close');
    $('.wrc-ov-cmp-x', cmpHead).setAttribute('aria-label', t('console.ov_cmp_close'));
    composer.insertBefore(cmpHead, composer.firstChild);
    // The verbs sit on the rail now; their row in the form is kept (the rail presses its buttons) but not shown.
    const verbRow = $('#wrcVerbs', composer);
    if (verbRow && verbRow.parentNode) verbRow.parentNode.classList.add('wrc-ov-verbrow');

    // ── the live video tile (under the queue) ────────────────────────────────
    // Whoever is on air shows here, so command does not have to open the Media tab to see them. Each live stream
    // gets a <video> of its own, tagged data-live-tile (never data-live-video, which is the Live card's): the
    // page's live-video code attaches the stream's track to it through the SDK like to the card's, because a
    // track whose attached elements are all hidden is paused by adaptiveStream. The card keeps the stop button.
    const queueEl = $('#wrcQueue', root);
    const tile = mk('div', 'wrc-ov-tile');
    tile.hidden = true;
    tile.innerHTML = '<div class="wrc-ov-tile-stage"></div>'
        + `<div class="wrc-ov-tile-c wrc-ov-tile-tl"><b class="wrc-ov-tile-who"></b><span><i class="wrc-ov-rec"></i>${esc(t('console.ov_tile_live'))} <span class="wrc-ov-tile-left"></span></span></div>`
        + '<div class="wrc-ov-tile-c wrc-ov-tile-br"></div>'
        + `<div class="wrc-ov-tile-wait">${esc(t('console.ov_tile_wait'))}</div>`
        + `<div class="wrc-ov-tile-btns"><button type="button" data-ov-tile="full" title="${esc(t('console.ov_fullscreen'))}" aria-label="${esc(t('console.ov_fullscreen'))}"><i class="bi bi-arrows-fullscreen"></i></button>`
        + `<button type="button" data-ov-tile="close" title="${esc(t('console.ov_tile_close'))}" aria-label="${esc(t('console.ov_tile_close'))}"><i class="bi bi-x-lg"></i></button></div>`
        + '<div class="wrc-ov-tile-pick"></div>';
    if (queueEl && queueEl.parentNode) queueEl.parentNode.insertBefore(tile, queueEl.nextSibling);
    const stage = $('.wrc-ov-tile-stage', tile);
    const tileUi = {current: null, dismissed: new Set()};
    const tileVideos = new Map();   // user id -> its <video>
    let lastView = null;

    function liveRows() {
        return $$('#liveStreamsBody .live-stream-item[data-stream-id]').map(el => {
            const who = $('strong', el);
            const name = who ? (who.firstChild && who.firstChild.nodeType === 3 ? who.firstChild.textContent : who.textContent) : '';
            const left = $('.live-remaining[data-seconds-left]', el);
            return {id: Number(el.dataset.streamId), uid: String(el.dataset.userId), status: el.dataset.status, name: name.trim(), left: left ? parseInt(left.dataset.secondsLeft, 10) : NaN};
        });
    }
    function dropVideo(uid) {
        const v = tileVideos.get(uid);
        if (!v) return;
        tileVideos.delete(uid);
        if (typeof window.liveDetachTile === 'function') window.liveDetachTile(v);
        try { v.srcObject = null; } catch (e) { /* nothing attached */ }
        v.remove();
    }
    function teamOf(uid) {
        const tm = lastView && lastView.teams.find(x => x.members.includes(Number(uid)));
        return tm ? tm.label : '';
    }
    function syncTile() {
        if (!engaged) { Array.from(tileVideos.keys()).forEach(dropVideo); tile.hidden = true; return; }
        const live = wrcOvLiveStreams(liveRows());
        const pick = wrcOvTilePick(live, tileUi.current, tileUi.dismissed);
        tileUi.current = pick.id;
        const onAir = new Set(live.map(r => r.uid));
        Array.from(tileVideos.keys()).forEach(uid => { if (!onAir.has(uid)) dropVideo(uid); });
        // Reveal first, attach second: a browser that will not start a video it cannot draw (WebKit) is not asked to.
        tile.hidden = !pick.show;
        if (pick.show) {
            let fresh = false;
            live.forEach(r => {
                if (tileVideos.has(r.uid)) return;
                const v = mk('video', 'wrc-ov-tile-v');
                v.autoplay = true; v.muted = true; v.playsInline = true;
                v.setAttribute('playsinline', '');
                v.setAttribute('data-live-tile', r.uid);
                stage.appendChild(v);
                tileVideos.set(r.uid, v);
                fresh = true;
            });
            if (fresh && typeof window.liveReattachAll === 'function') window.liveReattachAll();
        }
        const shown = live.find(r => r.id === pick.id) || null;
        tileVideos.forEach((v, uid) => v.classList.toggle('on', !!shown && pick.show && shown.uid === uid));
        paintTile(live, shown, pick.show);
    }
    function paintTile(live, shown, visible) {
        if (!visible || !shown) { sigs.tilePick = ''; return; }
        const v = tileVideos.get(shown.uid);
        $('.wrc-ov-tile-who', tile).textContent = [teamOf(shown.uid), shown.name].filter(Boolean).join(' · ');
        $('.wrc-ov-tile-left', tile).textContent = wrcOvLeft(shown.left);
        $('.wrc-ov-tile-br', tile).textContent = v && v.videoWidth ? v.videoWidth + '×' + v.videoHeight : '';
        tile.classList.toggle('waiting', !(v && v.readyState >= 2 && v.videoWidth));
        tile.setAttribute('aria-label', t('console.ov_tile_aria', {name: shown.name}));
        put($('.wrc-ov-tile-pick', tile), 'tilePick', live.length > 1
            ? live.map((r, i) => `<button type="button" data-ov-tile-pick="${r.id}" class="${r.id === shown.id ? 'on' : ''}" title="${esc(t('console.ov_tile_pick', {name: r.name}))}" aria-label="${esc(t('console.ov_tile_pick', {name: r.name}))}">${i + 1}</button>`).join('')
            : '');
        // A video that was put away while hidden, or that the browser paused, is asked to play again.
        if (v && v.srcObject && v.paused) { const p = v.play(); if (p && p.catch) p.catch(() => { /* the page's own retry loop reports it */ }); }
    }
    let tileSoon = 0;
    function syncTileSoon() { if (!tileSoon) tileSoon = setTimeout(() => { tileSoon = 0; syncTile(); }, 50); }
    const liveBody = $('#liveStreamsBody');
    if (liveBody && window.MutationObserver) new MutationObserver(syncTileSoon).observe(liveBody, {childList: true, subtree: true, attributes: true, attributeFilter: ['data-status']});
    setInterval(syncTile, 1000);

    tile.addEventListener('click', e => {
        const pickBtn = e.target.closest('[data-ov-tile-pick]');
        if (pickBtn) { tileUi.current = Number(pickBtn.dataset.ovTilePick); syncTile(); return; }
        const act = e.target.closest('[data-ov-tile]');
        if (act && act.dataset.ovTile === 'close') {
            wrcOvLiveStreams(liveRows()).forEach(r => tileUi.dismissed.add(r.id));
            syncTile();
            return;
        }
        if (act && act.dataset.ovTile === 'full') {
            if (document.fullscreenElement) { document.exitFullscreen(); return; }
            const v = $('.wrc-ov-tile-v.on', tile);
            const fallback = () => { if (v && v.webkitEnterFullscreen) v.webkitEnterFullscreen(); };
            if (tile.requestFullscreen) tile.requestFullscreen().catch(fallback); else fallback();
            return;
        }
        // The picture itself: command is looking at what is on air.
        if (e.target.closest('.wrc-ov-tile-stage') && typeof api.watchLive === 'function') api.watchLive();
    });

    // ── the pointer: a ring and a tooltip with the position under it ─────────
    // The photo's white ring and black coordinates label. The position is the map's own answer for the pointer
    // (nothing is asked); the height of the ground comes a moment after the pointer rests, from the same server
    // door as the point menu and the measuring tool (measureElevations), remembered per ~100 m cell and kept to
    // a part of that door's allowance so it can never use up the heights those two need. It is asked for only
    // when the MOUSE rests (a map that re-centres under a pointer that stands still asks nothing), one ask at a
    // time, and not at all for a while after an ask that brought no height: every ask holds one of the host's
    // few PHP workers while the outside service answers, and a service that does not answer would otherwise fill
    // them all and slow every other request of the page, the map layer's included.
    const PTR_KEY = 'wr-console-ov-pointer';
    const PTR_REST_MS = 700;
    const PTR_BUDGET = 40;            // asks per ten minutes; the server grants 120 to all three users of the door
    const PTR_WINDOW_MS = 600000;
    const PTR_PAUSE_MS = 300000;      // after an ask that brought no height: none for five minutes (the server pauses for three)
    const ptrRing = mk('div', 'wrc-ov-ptr-ring');
    const ptrTip = mk('div', 'wrc-ov-ptr-tip');
    ptrRing.hidden = true;
    ptrTip.hidden = true;
    ptrTip.innerHTML = '<span class="wrc-ov-ptr-pos"></span><span class="wrc-ov-ptr-h"></span>';
    root.append(ptrRing, ptrTip);
    const ptrPos = $('.wrc-ov-ptr-pos', ptrTip);
    const ptrH = $('.wrc-ov-ptr-h', ptrTip);
    const ptr = {on: true, at: null, cell: '', heights: new Map(), stamps: [], busy: false, pausedUntil: 0, rest: 0, bound: false};
    try { ptr.on = localStorage.getItem(PTR_KEY) !== 'off'; } catch (e) { /* blocked storage: it starts on */ }
    // A touch screen has no pointer to follow: a tap would leave the label where it landed.
    const canHover = !window.matchMedia || window.matchMedia('(hover: hover) and (pointer: fine)').matches;
    const ptrBtn = mk('button', 'btn');
    ptrBtn.type = 'button';
    ptrBtn.innerHTML = `<i class="bi bi-cursor"></i>${esc(t('console.ov_coords_toggle'))}`;
    ptrBtn.title = t('console.ov_coords_toggle');
    function paintPtrBtn() {
        ptrBtn.classList.toggle('active', ptr.on);
        ptrBtn.setAttribute('aria-pressed', ptr.on ? 'true' : 'false');
    }
    paintPtrBtn();
    ptrBtn.addEventListener('click', () => {
        ptr.on = !ptr.on;
        try { localStorage.setItem(PTR_KEY, ptr.on ? 'on' : 'off'); } catch (e) { /* not remembered */ }
        paintPtrBtn();
        if (!ptr.on) hidePtr();
    });

    function hidePtr() {
        clearTimeout(ptr.rest);
        ptr.at = null;
        ptrRing.hidden = true;
        ptrTip.hidden = true;
    }
    function heightText(metres) {
        const loc = typeof jsLocale !== 'undefined' ? jsLocale : undefined;
        return ' · ~' + Math.round(metres).toLocaleString(loc) + ' ' + t('common.unit_m');
    }
    function paintPtr() {
        if (!ptr.at) return;
        const {latlng, x, y} = ptr.at;
        ptrPos.textContent = wrcOvDms(latlng.lat, latlng.lng, t('console.ov_compass'));
        const known = ptr.heights.get(ptr.cell);
        ptrH.textContent = typeof known === 'number' ? heightText(known) : '';
        ptrRing.style.transform = `translate(${Math.round(x - 14)}px, ${Math.round(y - 14)}px)`;
        ptrRing.hidden = false;
        ptrTip.hidden = false;
        const box = ptrTip.getBoundingClientRect();
        const at = wrcOvTipAt(x, y, box.width, box.height, window.innerWidth, window.innerHeight);
        ptrTip.style.transform = `translate(${at.x}px, ${at.y}px)`;
    }
    function askHeight(cell, latlng) {
        if (typeof measureElevations !== 'function' || ptr.heights.has(cell)) return;
        const grant = wrcOvHeightGate(ptr, Date.now(), PTR_BUDGET, PTR_WINDOW_MS);
        ptr.stamps = grant.stamps;
        if (!grant.ok) return;
        ptr.heights.set(cell, false);   // asked: not asked again for this cell, whatever the answer
        ptr.busy = true;
        measureElevations([[Number(latlng.lat.toFixed(5)), Number(latlng.lng.toFixed(5))]]).then(heights => {
            const got = !!heights && typeof heights[0] === 'number';
            ptr.busy = false;
            ptr.pausedUntil = wrcOvHeightPausedUntil(Date.now(), got, PTR_PAUSE_MS);
            if (!got) return;
            ptr.heights.set(cell, heights[0]);
            if (ptr.cell === cell) paintPtr();
        });
    }
    // `ask` is true only for the mouse's own movement: the map moving under a pointer that stands still repaints the label
    // (the position changed) but never asks for a height.
    function movePtr(latlng, x, y, ask) {
        if (!engaged || !ptr.on || !canHover) return;
        ptr.cell = wrcOvCell(latlng.lat, latlng.lng);
        ptr.at = {latlng, x, y};
        paintPtr();
        clearTimeout(ptr.rest);
        if (!ask) return;
        const cell = ptr.cell;
        ptr.rest = setTimeout(() => { if (ptr.cell === cell && ptr.at) askHeight(cell, ptr.at.latlng); }, PTR_REST_MS);
    }
    function bindPointer() {
        const m = mapNow();
        if (ptr.bound || !m) return;
        ptr.bound = true;
        m.on('mousemove', e => {
            const oe = e.originalEvent;
            // Over a popup or one of the map's own controls the label would be in the way of what is being read.
            if (!oe || (oe.target && oe.target.closest && oe.target.closest('.leaflet-popup, .leaflet-control'))) { hidePtr(); return; }
            movePtr(e.latlng, oe.clientX, oe.clientY, true);
        });
        m.on('mouseout', hidePtr);
        m.on('dragstart', hidePtr);
        // The map moving under a pointer that stands still (it follows the teams) changes what is under it.
        m.on('moveend', () => {
            if (!ptr.at) return;
            const rect = m.getContainer().getBoundingClientRect();
            const latlng = m.containerPointToLatLng([ptr.at.x - rect.left, ptr.at.y - rect.top]);
            movePtr(latlng, ptr.at.x, ptr.at.y, false);
        });
    }

    // ── the heat layer: where the teams have been, and for how long ──────────
    // Blue to red over the map, drawn on a canvas of its own (thousands of cells are too many SVG nodes), from
    // mission-coverage-heat.php: person-seconds per ~20 m cell over the verified fixes of the whole mission, scaled
    // to the busiest 5 %. Off until asked for (the button beside the command post and the map points, remembered
    // in the browser); while it is on the page asks every half minute and the server answers «unchanged» in one
    // line when no new fix has come. Never shown below 1200 px: the button is not there to turn it on.
    const HEAT_KEY = 'wr-console-ov-heat';
    const HEAT_EVERY_MS = 30000;
    const heat = {on: false, layer: null, sig: '', timer: 0, busy: false, data: null};
    try { heat.on = localStorage.getItem(HEAT_KEY) === 'on'; } catch (e) { /* blocked storage: it starts off */ }
    const heatBtn = mk('button', 'wrc-ov-sq wrc-ov-heat-btn');
    heatBtn.type = 'button';
    heatBtn.innerHTML = '<i class="bi bi-fire"></i>';
    heatBtn.title = t('console.ov_heat_toggle');
    heatBtn.setAttribute('aria-label', t('console.ov_heat_toggle'));
    const heatLegend = mk('div', 'wrc-ov-heat-legend');
    heatLegend.hidden = true;
    heatLegend.innerHTML = `<b class="wrc-ov-heat-t">${esc(t('console.ov_heat_title'))}</b><i class="wrc-ov-heat-bar"></i>`
        + `<span class="wrc-ov-heat-lo">${esc(t('console.ov_heat_low'))}</span><span class="wrc-ov-heat-hi"></span><em class="wrc-ov-heat-msg"></em>`;
    root.appendChild(heatLegend);
    function paintHeatBtn() {
        heatBtn.classList.toggle('on', heat.on);
        heatBtn.setAttribute('aria-pressed', heat.on ? 'true' : 'false');
    }
    paintHeatBtn();

    /** The layer is built the first time it is needed: Leaflet is there by then, and a page without a map never gets one. */
    function heatLayer() {
        const m = mapNow();
        if (heat.layer || !m || typeof L === 'undefined') return heat.layer;
        if (!m.getPane('ovHeatPane')) {
            m.createPane('ovHeatPane');
            // Over the map tiles (200), under the areas (340), sectors (350), pins and everything drawn on top of the ground.
            m.getPane('ovHeatPane').style.zIndex = 330;
            m.getPane('ovHeatPane').style.pointerEvents = 'none';
        }
        const Heat = L.Layer.extend({
            onAdd(map) {
                this._canvas = L.DomUtil.create('canvas', 'wrc-ov-heat-canvas');
                map.getPane('ovHeatPane').appendChild(this._canvas);
                map.on('moveend resize', this._redraw, this);
                map.on('zoomstart', this._hide, this);
                map.on('zoomend', this._redraw, this);
                this._redraw();
            },
            onRemove(map) {
                map.off('moveend resize', this._redraw, this);
                map.off('zoomstart', this._hide, this);
                map.off('zoomend', this._redraw, this);
                L.DomUtil.remove(this._canvas);
                this._canvas = null;
            },
            setData(data) { this.data = data; if (this._map) this._redraw(); },
            _hide() { if (this._canvas) this._canvas.style.visibility = 'hidden'; },
            _redraw() {
                const map = this._map;
                const canvas = this._canvas;
                if (!map || !canvas) return;
                const size = map.getSize();
                canvas.width = size.x;
                canvas.height = size.y;
                L.DomUtil.setPosition(canvas, map.containerPointToLayerPoint([0, 0]));
                canvas.style.visibility = '';
                const ctx = canvas.getContext('2d');
                ctx.clearRect(0, 0, size.x, size.y);
                const data = this.data;
                if (!data || !data.cells || !data.cells.length) return;
                const zoom = map.getZoom();
                const px = Math.max(3, wrcOvHeatCellPx(data.cell_m, data.cells[0][0], zoom));
                const half = px * 0.75;       // a little over a cell: neighbours run into each other, and the blur does the rest
                // The busiest cells come first in the answer: the faint ones are painted first, so the hot ones stay on top.
                for (let i = data.cells.length - 1; i >= 0; i--) {
                    const cell = data.cells[i];
                    const p = map.latLngToContainerPoint([cell[0], cell[1]]);
                    if (p.x < -px || p.y < -px || p.x > size.x + px || p.y > size.y + px) continue;
                    const c = wrcOvHeatColor(wrcOvHeatT(cell[2], data.p95));
                    ctx.fillStyle = `rgba(${c.r},${c.g},${c.b},${c.a})`;
                    ctx.fillRect(p.x - half, p.y - half, half * 2, half * 2);
                }
                canvas.style.filter = `blur(${Math.max(1.5, Math.min(14, px * 0.45)).toFixed(1)}px)`;
            },
        });
        heat.layer = new Heat();
        return heat.layer;
    }

    function paintHeatLegend(state) {
        const msg = $('.wrc-ov-heat-msg', heatLegend);
        const hi = $('.wrc-ov-heat-hi', heatLegend);
        heatLegend.hidden = !heat.on || !engaged;
        heatLegend.classList.toggle('empty', state === 'none' || state === 'failed');
        msg.textContent = state === 'failed' ? t('console.ov_heat_failed') : (state === 'none' ? t('console.ov_heat_none') : '');
        hi.textContent = state === 'ok' && heat.data ? t('console.ov_heat_high', {n: wrcOvHeatMinutes(heat.data.p95)}) : '';
        heatLegend.title = state === 'ok' && heat.data
            ? t('console.ov_heat_info', {fixes: heat.data.fixes, people: heat.data.people, cell: heat.data.cell_m}) : '';
    }

    function heatLoad() {
        if (!engaged || !heat.on || heat.busy) return;
        const layer = heatLayer();
        if (!layer) return;
        heat.busy = true;
        fetch('mission-coverage-heat.php?mission_id=' + encodeURIComponent(root.dataset.mission) + '&sig=' + encodeURIComponent(heat.sig), {credentials: 'same-origin'})
            .then(r => r.ok ? r.json() : null)
            .then(res => {
                if (!heat.on || !engaged) return;
                if (!res || !res.ok) { if (!heat.data) paintHeatLegend('failed'); return; }
                if (res.unchanged) return;
                heat.sig = res.sig;
                heat.data = res;
                layer.setData(res);
                paintHeatLegend(res.cells && res.cells.length ? 'ok' : 'none');
            })
            .catch(() => { if (!heat.data) paintHeatLegend('failed'); })
            .finally(() => { heat.busy = false; });
    }
    function heatStart() {
        const m = mapNow();
        const layer = heatLayer();
        if (!m || !layer) return;
        if (!m.hasLayer(layer)) layer.addTo(m);
        paintHeatLegend(heat.data ? (heat.data.cells.length ? 'ok' : 'none') : 'loading');
        heatLoad();
        clearInterval(heat.timer);
        heat.timer = setInterval(() => { if (!document.hidden) heatLoad(); }, HEAT_EVERY_MS);
    }
    function heatStop() {
        clearInterval(heat.timer);
        heat.timer = 0;
        const m = mapNow();
        if (heat.layer && m && m.hasLayer(heat.layer)) m.removeLayer(heat.layer);
        heatLegend.hidden = true;
    }
    heatBtn.addEventListener('click', () => {
        heat.on = !heat.on;
        try { localStorage.setItem(HEAT_KEY, heat.on ? 'on' : 'off'); } catch (e) { /* not remembered */ }
        paintHeatBtn();
        paintMapTools();
        if (heat.on) heatStart(); else heatStop();
    });

    // ── the ruler: the page's measuring tool, started from the map tools column ──
    // Two clicks on the map measure a distance, with everything the right-click menu's measuring has (the
    // card, the routes, the heights, Esc to cancel). The page owns the tool and says when it changes
    // (window.wrMeasure, the wr-measure-changed event); this is only its button, orange while it is on.
    const ruler = mk('button', 'btn wrc-ov-ruler');
    ruler.type = 'button';
    ruler.innerHTML = '<i class="bi bi-rulers"></i>';
    ruler.title = t('console.ov_ruler');
    ruler.setAttribute('aria-label', t('console.ov_ruler'));
    ruler.setAttribute('aria-pressed', 'false');
    // With the search rings' switch (added when engaged, after it): the map's own tools, in the map tools column under the drawing tools.
    $('[data-g="extra"]', stack).appendChild(ruler);
    const measureTool = () => window.wrMeasure || null;
    function paintRuler() {
        const m = measureTool();
        const on = !!(m && m.active());
        ruler.classList.toggle('active', on);
        ruler.setAttribute('aria-pressed', on ? 'true' : 'false');
        paintMapTools();
    }
    ruler.addEventListener('click', () => {
        const m = measureTool();
        if (!m) return;
        if (m.active()) m.clear(); else m.arm();
        paintRuler();
    });
    document.addEventListener('wr-measure-changed', () => setTimeout(paintRuler, 0));

    // ── the search: teams, people and places, from what the page already holds ─
    // The magnifier opens one input under the bar. Nothing is asked of the server: the teams come from the
    // console, the people from the positions on the map and the places from the lists the map is drawn
    // from. A result takes the map there through the page's own ways in (window.wrMapGo), so a shape opens
    // exactly as it does from «Τι υπάρχει εδώ;».
    const FIND_MAX = 8;
    const FIND_HEADS = {teams: t('console.ov_find_teams'), people: t('console.ov_find_people'), places: t('console.ov_find_places')};
    const FIND_ICONS = {team: 'bi-people-fill', person: 'bi-person-fill', area: 'bi-bounding-box', sector: 'bi-grid-3x3-gap-fill',
        restricted: 'bi-exclamation-triangle-fill', point: 'bi-geo-alt-fill', incident: 'bi-heart-pulse-fill', poi: 'bi-eye-fill'};
    const find = {open: false, q: '', idx: 0, flat: []};
    const findBtn = mk('button', 'btn wrc-ov-find-btn');
    findBtn.type = 'button';
    findBtn.innerHTML = '<i class="bi bi-search"></i>';
    findBtn.title = t('console.ov_find');
    findBtn.setAttribute('aria-label', t('console.ov_find'));
    findBtn.setAttribute('aria-haspopup', 'dialog');
    findBtn.setAttribute('aria-expanded', 'false');
    op.appendChild(findBtn);
    const findPn = mk('div', 'wrc-ov-find', 'wrcOvFind');
    findPn.hidden = true;
    findPn.setAttribute('role', 'dialog');
    findPn.setAttribute('aria-label', t('console.ov_find'));
    findPn.innerHTML = `<div class="wrc-ov-fin"><i class="bi bi-search"></i>`
        + `<input type="text" class="wrc-ov-fi" autocomplete="off" spellcheck="false" role="combobox" aria-expanded="true" aria-controls="wrcOvFindList" aria-autocomplete="list" placeholder="${esc(t('console.ov_find_ph'))}" aria-label="${esc(t('console.ov_find'))}">`
        + `<button type="button" class="wrc-ov-fx" data-find-close title="${esc(t('console.ov_find_close'))}" aria-label="${esc(t('console.ov_find_close'))}"><i class="bi bi-x-lg"></i></button></div>`
        + '<div class="wrc-ov-fl" id="wrcOvFindList" role="listbox"></div>';
    root.appendChild(findPn);
    const findIn = $('.wrc-ov-fi', findPn);
    const findList = $('.wrc-ov-fl', findPn);

    /** Everything that can be looked for, read off what the page holds right now (each list may not exist for this viewer). */
    function findEntries() {
        const out = [];
        const v = api.view();
        // The count under a team's name is not something to search for («α» is in «άτομα»).
        (v.teams || []).forEach(tm => out.push({group: 'teams', kind: 'team', id: tm.id, title: tm.label, sub: t('console.ov_find_members', {n: (tm.members || []).length}), find: '', members: tm.members || []}));
        const seen = new Set();
        pinsNow().forEach(p => {
            if (p.lat === null || p.lat === undefined || seen.has(Number(p.user_id))) return;
            seen.add(Number(p.user_id));
            out.push({group: 'people', kind: 'person', id: p.user_id, title: p.name, sub: p.team_label || ''});
        });
        const areaList = typeof areas !== 'undefined' && Array.isArray(areas) ? areas : [];
        areaList.forEach(a => out.push({group: 'places', kind: 'area', id: a.id, title: a.label, sub: t('map_pick.kind_area')}));
        (typeof sectors !== 'undefined' && Array.isArray(sectors) ? sectors : []).forEach(s => {
            const area = areaList.find(a => a.id === s.area_id);
            out.push({group: 'places', kind: 'sector', id: s.id, title: s.label, sub: t('map_pick.kind_sector') + (area ? ' · ' + area.label : '')});
        });
        (typeof restrictedAreas !== 'undefined' && Array.isArray(restrictedAreas) ? restrictedAreas : [])
            .forEach(r => out.push({group: 'places', kind: 'restricted', id: r.id, title: r.label, sub: t('map_pick.kind_restricted')}));
        (typeof mapPoints !== 'undefined' && Array.isArray(mapPoints) ? mapPoints : [])
            .forEach(p => out.push({group: 'places', kind: 'point', id: p.id, title: p.name, sub: t('console.ov_find_point')}));
        (typeof missionIncidents !== 'undefined' && Array.isArray(missionIncidents) ? missionIncidents : []).forEach(r => {
            if (r.lat === null || r.lat === undefined) return;
            out.push({group: 'places', kind: 'incident', id: r.id, title: typeof incidentShortText === 'function' ? incidentShortText(r) : t('console.ov_find_incident'),
                sub: [t('console.ov_find_incident'), r.is_unknown_patient ? '' : r.patient_name, r.team_label].filter(Boolean).join(' · ')});
        });
        (typeof pointsOfInterest !== 'undefined' && Array.isArray(pointsOfInterest) ? pointsOfInterest : []).forEach(p => {
            if (p.lat === null || p.lat === undefined) return;
            out.push({group: 'places', kind: 'poi', id: p.id, title: t('console.ov_find_poi'), sub: (p.reporter_names || []).join(', ')});
        });
        return out;
    }

    function paintFind() {
        const q = find.q.trim();
        const groups = wrcOvFind(findEntries(), q, FIND_MAX);
        find.flat = groups.reduce((all, g) => all.concat(g.items), []);
        if (find.idx >= find.flat.length) find.idx = 0;
        let out = '';
        if (!q) out = `<div class="wrc-ov-fm">${esc(t('console.ov_find_hint'))}</div>`;
        else if (!groups.length) out = `<div class="wrc-ov-fm">${esc(t('console.ov_find_none', {q}))}</div>`;
        let n = 0;
        groups.forEach(g => {
            out += `<div class="wrc-ov-fh">${esc(FIND_HEADS[g.key])}</div>`;
            g.items.forEach(e => {
                const i = n++;
                out += `<button type="button" class="wrc-ov-fr${i === find.idx ? ' on' : ''}" role="option" id="wrcOvFr${i}" aria-selected="${i === find.idx ? 'true' : 'false'}" data-find="${i}">`
                    + `<i class="bi ${FIND_ICONS[e.kind] || 'bi-dot'}"></i><span class="wrc-ov-ft"><b>${esc(e.title)}</b>${e.sub ? `<small>${esc(e.sub)}</small>` : ''}</span></button>`;
            });
            if (g.more) out += `<div class="wrc-ov-fmore">${esc(t('console.ov_find_more', {n: g.more}))}</div>`;
        });
        findList.innerHTML = out;
        findIn.setAttribute('aria-activedescendant', find.flat.length ? 'wrcOvFr' + find.idx : '');
    }
    /** The highlighted row moved: no repaint, so a click in progress is not lost under the pointer. */
    function markFind(scroll) {
        $$('.wrc-ov-fr', findList).forEach((b, i) => { b.classList.toggle('on', i === find.idx); b.setAttribute('aria-selected', i === find.idx ? 'true' : 'false'); });
        findIn.setAttribute('aria-activedescendant', find.flat.length ? 'wrcOvFr' + find.idx : '');
        const row = $('#wrcOvFr' + find.idx, findList);
        if (scroll && row && row.scrollIntoView) row.scrollIntoView({block: 'nearest'});
    }
    function placeFind() {
        const r = root.getBoundingClientRect();
        const b = findBtn.getBoundingClientRect();
        const w = Math.min(380, window.innerWidth - 24);
        findPn.style.width = w + 'px';
        findPn.style.left = Math.max(12, Math.min(b.left - r.left, window.innerWidth - w - 12)) + 'px';
        findPn.style.top = (top.getBoundingClientRect().bottom - r.top + 6) + 'px';
    }
    function openFind() {
        if (!engaged) return;
        find.open = true;
        find.q = '';
        find.idx = 0;
        findIn.value = '';
        findPn.hidden = false;
        findBtn.classList.add('active');
        findBtn.setAttribute('aria-expanded', 'true');
        placeFind();
        paintFind();
        setTimeout(() => findIn.focus(), 0);
    }
    function closeFind(refocus) {
        if (!find.open) return;
        find.open = false;
        findPn.hidden = true;
        findBtn.classList.remove('active');
        findBtn.setAttribute('aria-expanded', 'false');
        if (refocus) findBtn.focus();
    }
    /** Takes the map to a result. A team is picked as the team bar picks it, alone, and framed. */
    function goTo(e) {
        const door = window.wrMapGo;
        if (e.kind === 'team') {
            api.pickTeam(e.id, 'only');
            ui.focus = e.id;
            frame(new Set(e.members.map(Number)));
            update();
        } else if (door) {
            if (e.kind === 'person') door.person(e.id);
            else if (e.kind === 'point') door.point(e.id);
            else if (e.kind === 'incident') door.incident(e.id);
            else if (e.kind === 'poi') door.poi(e.id);
            else door.shape(e.kind, e.id);
        }
    }
    findBtn.addEventListener('click', e => { e.stopPropagation(); if (find.open) closeFind(); else openFind(); });
    findIn.addEventListener('input', () => { find.q = findIn.value; find.idx = 0; paintFind(); });
    findIn.addEventListener('keydown', e => {
        if (e.isComposing) return;
        if (e.key === 'Escape') {
            // Only the search closes: the page's own Esc (it cancels a measurement) must not also hear it.
            e.preventDefault();
            e.stopPropagation();
            closeFind(true);
        } else if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            if (find.flat.length) { find.idx = wrcOvStep(find.flat.length, find.idx, e.key === 'ArrowDown' ? 1 : -1); markFind(true); }
        } else if (e.key === 'Enter') {
            e.preventDefault();
            const hit = find.flat[find.idx];
            if (hit) { closeFind(); goTo(hit); }
        }
    });
    findList.addEventListener('click', e => {
        const row = e.target.closest('[data-find]');
        const hit = row && find.flat[Number(row.dataset.find)];
        if (hit) { closeFind(); goTo(hit); }
    });
    findList.addEventListener('mousemove', e => {
        const row = e.target.closest('[data-find]');
        if (row && Number(row.dataset.find) !== find.idx) { find.idx = Number(row.dataset.find); markFind(false); }
    });
    findPn.addEventListener('click', e => { if (e.target.closest('[data-find-close]')) closeFind(true); });
    document.addEventListener('mousedown', e => { if (find.open && !findPn.contains(e.target) && !findBtn.contains(e.target)) closeFind(); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && find.open) closeFind(true); });
    window.addEventListener('resize', () => { if (find.open) placeFind(); });

    // ── the drawing column: the pen, the arrow, the text, the eraser and clear-all, at the right edge ─────
    // The page's own toolbar (#annotationToolbar) is moved here whole - the annotation code keeps finding it by
    // its id, and the ruler's «one tool at a time» keeps working through its buttons - and the column folds to one
    // button: the tools when it is open, the icon of the armed tool (lit) when it is folded and a tool is on, so a
    // pen that is armed behind a folded column is seen. Folded by default, remembered per browser; a tool switched
    // on by another route opens it for as long as the page stays (not remembered: it is not a choice).
    const DRAW_KEY = 'wr-console-ov-draw';
    const drawCol = mk('div', 'wrc-ov-draw', 'wrcOvDraw');
    const drawBtn = mk('button', 'wrc-ov-sq wrc-ov-draw-btn');
    drawBtn.type = 'button';
    drawBtn.setAttribute('aria-controls', 'annotationToolbar');
    drawCol.hidden = true;
    drawCol.appendChild(drawBtn);
    edge.insertBefore(drawCol, mapCol);
    const draw = {open: false, observer: null};
    try { draw.open = localStorage.getItem(DRAW_KEY) === 'open'; } catch (e) { /* storage blocked: it starts folded */ }
    function armedTool() {
        const bar = $('#annotationToolbar');
        return bar ? $('button[data-tool].active', bar) : null;
    }
    function paintDraw() {
        const tool = armedTool();
        const glyph = tool ? $('i', tool) : null;
        const icon = glyph ? (Array.from(glyph.classList).find(c => c !== 'bi' && c.indexOf('bi-') === 0) || '') : '';
        const face = wrcOvDrawFace(draw.open, icon);
        drawCol.dataset.open = draw.open ? 'open' : 'closed';
        drawBtn.innerHTML = `<i class="bi ${face.icon}"></i>`;
        drawBtn.classList.toggle('on', face.on);
        const label = face.label === 'fold' ? t('console.ov_draw_fold')
            : (face.label === 'armed' ? t('console.ov_draw_armed', {tool: (tool.title || '').trim()}) : t('console.ov_draw'));
        drawBtn.title = label;
        drawBtn.setAttribute('aria-label', label);
        drawBtn.setAttribute('aria-expanded', draw.open ? 'true' : 'false');
    }
    function setDraw(open, remember) {
        draw.open = open;
        if (remember) { try { localStorage.setItem(DRAW_KEY, open ? 'open' : 'closed'); } catch (e) { /* not remembered */ } }
        paintDraw();
        fitEdge(open ? 'draw' : '');
    }
    function watchDraw() {
        const bar = $('#annotationToolbar');
        if (!bar || draw.observer || !window.MutationObserver) return;
        draw.observer = new MutationObserver(() => {
            const opened = !!armedTool() && !draw.open;
            if (opened) draw.open = true;
            paintDraw();
            if (opened) fitEdge('draw');
        });
        draw.observer.observe(bar, {attributes: true, attributeFilter: ['class'], subtree: true});
    }
    drawBtn.addEventListener('click', () => setDraw(!draw.open, true));
    // Esc folds the column from inside it (the focus on its button or a tool): anywhere else Esc keeps its other meanings.
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && engaged && draw.open && !e.defaultPrevented && drawCol.contains(document.activeElement)) { setDraw(false, true); drawBtn.focus(); }
    });

    // ── the map tools column: under the drawing tools, folding to one button the same way ─────────
    // The map view, follow, zoom, the ruler, the search rings, the heat layer, the command post and the map points are
    // the controls that already existed (the stack and the pair); only their place is new. The column is open by
    // default - they are used all the time - and remembered per browser. The button is the map icon when it is
    // folded, lit when the ruler is waiting for its clicks or the heat layer is on behind the fold.
    const MAPG_KEY = 'wr-console-ov-mapg';
    try { maptools.open = localStorage.getItem(MAPG_KEY) !== 'closed'; } catch (e) { /* storage blocked: it starts open */ }
    function paintMapTools() {
        const m = measureTool();
        const face = wrcOvMapFace(maptools.open, !!(m && m.active()) || heat.on);
        mapCol.dataset.open = maptools.open ? 'open' : 'closed';
        mapBtn.innerHTML = `<i class="bi ${face.icon}"></i>`;
        mapBtn.classList.toggle('on', face.on);
        const label = face.label === 'fold' ? t('console.ov_map_fold') : (face.label === 'lit' ? t('console.ov_map_lit') : t('console.ov_map'));
        mapBtn.title = label;
        mapBtn.setAttribute('aria-label', label);
        mapBtn.setAttribute('aria-expanded', maptools.open ? 'true' : 'false');
    }
    function setMapTools(open, remember) {
        maptools.open = open;
        if (remember) { try { localStorage.setItem(MAPG_KEY, open ? 'open' : 'closed'); } catch (e) { /* not remembered */ } }
        paintMapTools();
        fitEdge(open ? 'map' : '');
    }
    mapBtn.addEventListener('click', () => setMapTools(!maptools.open, true));

    // ── making the two columns fit the window's height ───────────────────────
    // Both open they are about 900 px tall, which a laptop does not have. In order: the buttons shrink (dense), then the
    // column that was not just opened folds - and with it folded the buttons get their full size again if they fit. Only
    // in the page - what was chosen stays what is remembered, so a taller window next time shows both again. Called
    // when either opens or closes, when the window is resized and whenever the column's own height changes (the
    // search rings' switch comes and goes with the mission).
    let fitting = false;
    function edgeFits() { return edge.offsetTop + edge.offsetHeight <= root.clientHeight - 10; }
    function fitEdge(keep) {
        if (!engaged || fitting || !edge.offsetHeight) return;
        fitting = true;
        try {
            // Full size if that fits, else dense; true when either does.
            const fits = () => {
                delete edge.dataset.dense;
                if (edgeFits()) return true;
                edge.dataset.dense = 'on';
                return edgeFits();
            };
            if (!fits()) {
                const folds = keep === 'map' ? ['draw'] : (keep === 'draw' ? ['map'] : ['draw', 'map']);
                for (const g of folds) {
                    if (g === 'draw' && draw.open) { draw.open = false; paintDraw(); } else if (g === 'map' && maptools.open) { maptools.open = false; paintMapTools(); } else continue;
                    if (fits()) break;
                }
            }
        } finally { fitting = false; }
    }
    window.addEventListener('resize', () => fitEdge(''));
    if (window.ResizeObserver) new ResizeObserver(() => fitEdge('')).observe(edge);

    // ── moving the real controls in, and back ────────────────────────────────
    const moved = [];
    let engaged = false;
    const undo = [];
    function take(node, target) {
        if (!node || !target || node.parentNode === target) return false;
        moved.push({node, parent: node.parentNode, next: node.nextSibling});
        target.appendChild(node);
        return true;
    }
    function giveBack() {
        while (moved.length) {
            const m = moved.pop();
            if (m.parent && m.parent.isConnected) m.parent.insertBefore(m.node, m.next && m.next.parentNode === m.parent ? m.next : null);
        }
        while (undo.length) undo.pop()();
    }
    /** Controls the map adds on its own time (the command post, the map points) are taken when they appear. */
    function adopt() {
        if (!engaged) return;
        take($('.wr-cp-control'), pair);
        take($('.wr-mp-control'), pair);
        take($('.leaflet-control-zoom'), $('[data-g="nav"]', stack));
    }

    function engage() {
        if (engaged) return;
        engaged = true;
        html.setAttribute('data-wrc-ov', 'on');
        const hero = $('.war-room-hero');
        const bar = $('#wrcBar');
        take(hero, top);
        take(bar, top);
        // The mission's name is long and the bar cuts it: the whole of it is the tooltip.
        const name = hero && $('h1', hero);
        if (name) { name.title = name.textContent.trim(); }
        take($('#notifBell') && $('#notifBell').closest('.dropdown'), op);
        take($('#userDropdown') && $('#userDropdown').closest('.dropdown'), op);
        top.appendChild(op);
        top.appendChild(readout);
        top.appendChild(gear);
        if (hero) {
            // The assistant and the mass-casualty button stay in the bar; everything else the hero carried is a tool.
            const row = $(':scope > .justify-content-end', hero);
            Array.from(row ? row.children : []).filter(el => el.id !== 'assistantBtn' && el.id !== 'mciHeroBtn').forEach(el => take(el, tools));
            const back = $('a[href="ops-dashboard.php"] .bi', hero);
            if (back) { const was = back.className; back.className = 'bi bi-house-fill'; undo.push(() => { back.className = was; }); }
        }
        take($('#mapFullscreenToggle'), tools);
        if (canHover) tools.appendChild(ptrBtn);
        bindPointer();
        // The heat layer's button goes first in the pair (the last group of the map tools column), so the command post and the map points keep their places.
        pair.insertBefore(heatBtn, pair.firstChild);
        if (heat.on) heatStart();
        // The acknowledgement panel floats over the map like the rest: inside the board, under its menus.
        take($('#ackTracker'), root);
        take($('#trailFilterBar'), root);

        const nav = $('[data-g="nav"]', stack);
        const layers = $('#mapSatelliteToggle');
        take(layers && layers.parentNode, nav);
        take($('#mapFollowBtn'), nav);
        // The drawing tools go to the right edge as a column of their own, folded to one button.
        if (take($('#annotationToolbar'), drawCol)) { watchDraw(); }
        drawCol.hidden = !$('#annotationToolbar');
        paintDraw();
        take($('#mapRingsToggle'), $('[data-g="extra"]', stack));
        // The export menu opens to the right of the rail, not off the left edge of the window.
        const exportMenu = $$('#mapCard .card-header .dropdown').find(d => d !== (layers && layers.parentNode));
        take(exportMenu, $('#wrcOvExport'));
        const menuEl = exportMenu && $('.dropdown-menu', exportMenu);
        if (menuEl && menuEl.classList.contains('dropdown-menu-end')) {
            menuEl.classList.remove('dropdown-menu-end');
            undo.push(() => menuEl.classList.add('dropdown-menu-end'));
        }
        adopt();
        applyChrome();
        update();
        paintMapTools();
        fitEdge('');
        window.dispatchEvent(new Event('resize'));
    }

    function disengage() {
        if (!engaged) return;
        engaged = false;
        html.removeAttribute('data-wrc-ov');
        hidePtr();
        heatStop();
        closeFind();
        // A ruler waiting for its clicks would leave the console's own map with a crosshair and nothing to say why.
        if (measureTool() && measureTool().armed()) measureTool().clear();
        syncTile();
        giveBack();
        top.contains(op) && op.remove();
        const m = mapNow();
        setTimeout(() => { window.dispatchEvent(new Event('resize')); if (m) m.invalidateSize(); }, 60);
    }

    // ── what the page puts at the top and the bottom of the window ──────────
    // The clock strip, and the scrolling banner when it is on the top (or the bottom) edge: the board
    // starts under them instead of being covered.
    function applyChrome() {
        const banner = document.getElementById('warRoomBanner');
        const shown = !!banner && banner.offsetHeight > 0;
        const atBottom = !!banner && banner.dataset.tickerPos === 'bottom';
        const clock = parseFloat(getComputedStyle(html).getPropertyValue('--app-clock-h')) || 0;
        // On <html>, not on the console: the acknowledgement panel outside it measures from the same line.
        html.style.setProperty('--wrc-ov-top', (clock + (shown && !atBottom ? banner.offsetHeight : 0)) + 'px');
        html.style.setProperty('--wrc-ov-bottom', (shown && atBottom ? banner.offsetHeight : 0) + 'px');
    }
    const banner = document.getElementById('warRoomBanner');
    if (banner) {
        if (window.ResizeObserver) new ResizeObserver(applyChrome).observe(banner);
        if (window.MutationObserver) new MutationObserver(applyChrome).observe(banner, {attributes: true, attributeFilter: ['style', 'data-ticker-pos']});
    }
    window.addEventListener('resize', applyChrome);

    // ── painting from the console's state ────────────────────────────────────
    const ui = {focus: null, start: 0, composer: false, tools: false};
    const phaseNow = () => root.dataset.phase || 'live';
    const teamsKey = () => 'teams-' + phaseNow();
    function teamsOpen() { return load(teamsKey(), phaseNow() === 'live' ? 'closed' : 'open') === 'open'; }
    function setFlags() {
        root.dataset.ovComposer = ui.composer && phaseNow() === 'live' ? 'open' : 'closed';
        root.dataset.ovTeams = teamsOpen() ? 'open' : 'closed';
        root.dataset.ovDock = load('dock', 'open') === 'open' ? 'open' : 'closed';
        root.dataset.ovTools = ui.tools ? 'open' : 'closed';
        gear.setAttribute('aria-expanded', ui.tools ? 'true' : 'false');
    }

    function focusOf(v) {
        const picked = v.teams.filter(tm => tm.picked > 0);
        if (!picked.length) return null;
        return picked.find(tm => tm.id === ui.focus) || picked[0];
    }

    let sigs = {};
    function put(el, key, markup) {
        if (sigs[key] === markup) return;
        sigs[key] = markup;
        el.innerHTML = markup;
    }

    function paintRail(v) {
        $$('[data-ov-verb]', rail).forEach(b => b.classList.toggle('on', ui.composer && b.dataset.ovVerb === v.verb));
        $('[data-ov="teams"]', rail).classList.toggle('on', teamsOpen());
        // The live camera: the red counter in the bar says what is on air, and this button is its twin.
        const kpi = $('.wrc-kpi[data-kpi="live"]');
        const live = $('[data-ov="live"]', rail);
        live.hidden = !kpi;
        if (kpi) {
            const n = Number(($('b', kpi) || {}).textContent) || 0;
            const nb = $('.wrc-ov-nb', live);
            nb.textContent = String(n);
            nb.hidden = n === 0;
            live.classList.toggle('flash', kpi.classList.contains('flash'));
            live.title = kpi.title || '';
            live.setAttribute('aria-label', kpi.title || '');
            live.dataset.label = kpi.title || '';
        }
    }

    function teamSeg(tm, focus) {
        const [bg, fg] = typeof teamBadgeColorsJs === 'function' ? teamBadgeColorsJs(tm.color) : [tm.color || '#8a97ad', '#0b1220'];
        const mark = tm.number > 0 ? String(tm.number) : String(tm.label || '?').trim().charAt(0).toUpperCase();
        const whole = tm.all > 0 && tm.picked === tm.all;
        const note = tm.badge ? `<span class="wrc-ov-flag wrc-ov-${esc(tm.badge.tone)}" title="${esc(tm.badge.text)}"></span>` : '';
        const title = t('console.select_team', {team: tm.label});
        return `<button type="button" class="wrc-ov-seg wrc-ov-team${tm.picked > 0 ? (whole ? ' on' : ' part') : ''}${focus && focus.id === tm.id ? ' focus' : ''}" data-ov-team="${tm.id}" style="--tc:${esc(bg)};--tf:${esc(fg)}" title="${esc(title)}" aria-label="${esc(title)}" aria-pressed="${tm.picked > 0 ? 'true' : 'false'}"><i>${esc(mark)}</i>${note}</button>`;
    }
    function paintBottom(v) {
        const cap = window.innerWidth >= 1440 ? 6 : 4;
        const focus = focusOf(v);
        const win = wrcOvWindow(v.teams.length, focus ? v.teams.findIndex(tm => tm.id === focus.id) : -1, ui.start, cap);
        ui.start = win.start;
        const seg = (act, icon, label) => `<button type="button" class="wrc-ov-seg" data-ov="${act}" title="${esc(label)}" aria-label="${esc(label)}"><i class="bi ${icon}"></i></button>`;
        const shown = v.teams.slice(win.start, win.start + win.size);
        put(bottom, 'bottom', seg('zoomteam', 'bi-zoom-in', t('console.ov_zoom_team')) + seg('fit', 'bi-bounding-box', t('console.ov_fit'))
            + (win.more ? seg('prev', 'bi-caret-left-fill', t('console.ov_prev')) : '')
            + shown.map(tm => teamSeg(tm, focus)).join('')
            + (win.more ? seg('next', 'bi-caret-right-fill', t('console.ov_next')) : '')
            + seg('clear', 'bi-x-lg', t('console.ov_clear_sel')) + seg('full', 'bi-fullscreen', t('console.ov_fullscreen')));
    }

    function paintSelected(v) {
        const focus = focusOf(v);
        if (!focus) { sel.hidden = true; sigs.sel = ''; return; }
        const picked = v.teams.filter(tm => tm.picked > 0).length;
        const rows = wrcOvTeamRows(focus, pinsNow(),
            typeof LOW_BATTERY_PCT !== 'undefined' ? LOW_BATTERY_PCT : undefined, typeof CRITICAL_BATTERY_PCT !== 'undefined' ? CRITICAL_BATTERY_PCT : undefined);
        const orderText = rows.order
            ? esc(t('teamboard.kind.' + rows.order.kind)) + (rows.order.more > 0 ? ` <small>+${rows.order.more}</small>` : '')
            : esc(t('teamboard.waiting'));
        const row = (label, value, tone) => `<div class="wrc-ov-r"><span>${esc(label)}</span><b${tone ? ` class="wrc-ov-${esc(tone)}"` : ''}>${value}</b></div>`;
        const head = picked > 1 ? t('console.ov_sel_many', {n: picked, team: focus.label}) : t('console.ov_sel_title', {team: focus.label});
        put(sel, 'sel', `<div class="wrc-ov-selh">${esc(head)}</div>
            <div class="wrc-ov-selg wrc-ov-stripe-${rows.tone}">
                ${row(t('console.ov_row_order'), orderText, rows.order ? rows.order.tone : '')}
                ${row(t('console.ov_row_progress'), rows.progress === null ? '—' : rows.progress + '%', '')}
                ${row(t('console.ov_row_contact'), rows.contact ? esc(rows.contact.text) : '—', rows.contact ? rows.contact.tone : '')}
                ${row(t('console.ov_row_battery'), rows.battery ? esc(rows.battery.text) : '—', rows.battery ? rows.battery.tone : '')}
            </div>`);
        sel.hidden = false;
    }

    function paintReadout(v) {
        const gps = wrcOvGpsReadout(v.gps);
        const start = Number(($('.war-room-hero') || {dataset: {}}).dataset.started) || 0;
        const el = wrcOvElapsed(start, Date.now() / 1000);
        put(readout, 'read',
            (el ? `<span class="wrc-ov-clock${el.before ? ' before' : ''}" title="${esc(t(el.before ? 'console.ov_clock_before' : 'console.ov_clock_title'))}">${esc(el.text)}</span>` : '')
            + (gps ? `<span class="wrc-ov-gps wrc-ov-${gps.tone}" title="${esc(t('console.ov_gps_title', {n: v.gps.total - v.gps.silent, total: v.gps.total}))}">${esc(gps.text)} <small>GPS</small></span>` : ''));
    }

    function paintComposerHead(v) {
        const verb = VERBS.find(x => x[0] === v.verb) || VERBS[0];
        $('i', cmpHead).className = 'bi ' + verb[1];
        $('.wrc-ov-cmp-t', cmpHead).textContent = verb[2];
    }

    function update() {
        if (!engaged) return;
        setFlags();
        const v = api.view();
        lastView = v;
        paintRail(v);
        paintBottom(v);
        paintSelected(v);
        paintReadout(v);
        paintComposerHead(v);
        syncTile();
    }
    let queued = 0;
    function schedule() { if (!queued) queued = setTimeout(() => { queued = 0; update(); }, 0); }
    document.addEventListener('wr-console-changed', schedule);
    setInterval(() => { if (engaged) { sigs.read = ''; update(); } }, 20000);

    // ── what the buttons do ──────────────────────────────────────────────────
    /** One look at where the teams are: `ids` (a Set of user ids) or everybody. */
    function frame(ids) {
        const m = mapNow();
        if (!m || typeof L === 'undefined') return;
        const list = pinsNow().filter(p => !ids || ids.has(Number(p.user_id)));
        if (!list.length) return;
        // A map that follows the pins would put itself back at the next position.
        if (ids && typeof setFollowPins === 'function' && typeof followPins !== 'undefined' && followPins) setFollowPins(false);
        m.invalidateSize();
        m.fitBounds(L.latLngBounds(list.map(p => [p.lat, p.lng])), {padding: [90, 90], maxZoom: 17, animate: false});
    }

    rail.addEventListener('click', e => {
        const verb = e.target.closest('[data-ov-verb]');
        if (verb) {
            const key = verb.dataset.ovVerb;
            const real = $('.wrc-verb[data-verb="' + key + '"]', root);
            const v = api.view();
            // The active one again puts the form away.
            if (ui.composer && v.verb === key) ui.composer = false;
            else { ui.composer = true; if (real) real.click(); }
            update();
            return;
        }
        const live = e.target.closest('[data-ov="live"]');
        if (live) {
            // With the tile put away, the camera brings it back; with the tile up (or nothing on air) it opens the Live card as before.
            if (tile.hidden && wrcOvLiveStreams(liveRows()).length) {
                tileUi.dismissed.clear();
                if (typeof api.watchLive === 'function') api.watchLive();
                syncTile();
                return;
            }
            const kpi = $('.wrc-kpi[data-kpi="live"]');
            if (kpi) kpi.click();
            return;
        }
        if (e.target.closest('[data-ov="teams"]')) {
            save(teamsKey(), teamsOpen() ? 'closed' : 'open');
            update();
        }
    });

    composer.addEventListener('click', e => {
        if (e.target.closest('[data-ov="cmp-close"]')) { ui.composer = false; update(); }
    });
    // A result that lands while the form is put away (a sector drawn in its own window) is still told.
    const toast = $('#wrcToast', composer);
    if (toast && window.MutationObserver) {
        new MutationObserver(() => { if (!toast.hidden && !ui.composer) { ui.composer = true; update(); } }).observe(toast, {attributes: true, attributeFilter: ['hidden']});
    }

    bottom.addEventListener('click', e => {
        const team = e.target.closest('[data-ov-team]');
        const v = api.view();
        if (team) {
            const id = Number(team.dataset.ovTeam);
            api.pickTeam(id, 'toggle');
            ui.focus = id;
            update();
            return;
        }
        const act = e.target.closest('[data-ov]');
        if (!act) return;
        const focus = focusOf(v);
        switch (act.dataset.ov) {
            case 'zoomteam': frame(focus ? new Set(focus.members.map(Number)) : null); break;
            case 'fit': frame(null); break;
            case 'prev':
            case 'next': {
                const at = wrcOvStep(v.teams.length, focus ? v.teams.findIndex(tm => tm.id === focus.id) : -1, act.dataset.ov === 'next' ? 1 : -1);
                if (at >= 0) { ui.focus = v.teams[at].id; api.pickTeam(ui.focus, 'only'); }
                break;
            }
            case 'clear': api.clearSelection(); ui.focus = null; break;
            case 'full': { const b = $('#warRoomFocusToggle'); if (b) b.click(); break; }
        }
        update();
    });

    // The map views menu sizes itself to the card of the map, and its button has left that card: it gets the room of the window.
    stack.addEventListener('show.bs.dropdown', e => {
        const menu = e.target && e.target.parentNode && $('.dropdown-menu', e.target.parentNode);
        if (menu && menu.classList.contains('wr-layers-2col')) menu.style.width = Math.max(260, Math.min(672, window.innerWidth - 480)) + 'px';
    });

    gear.addEventListener('click', e => { e.stopPropagation(); ui.tools = !ui.tools; setFlags(); });
    // A tool that was used has done its part: the panel goes, and the map it acts on is in view.
    tools.addEventListener('click', e => { if (e.target.closest('.btn')) { ui.tools = false; setFlags(); } });
    document.addEventListener('click', e => {
        if (ui.tools && !tools.contains(e.target) && !gear.contains(e.target)) { ui.tools = false; setFlags(); }
    });
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && ui.tools) { ui.tools = false; setFlags(); } });

    collapse.addEventListener('click', () => { save('dock', load('dock', 'open') === 'open' ? 'closed' : 'open'); setFlags(); });
    // Picking a tab while the panel is folded opens it again.
    tabs.addEventListener('click', e => { if (e.target.closest('button[data-tab]') && load('dock', 'open') !== 'open') { save('dock', 'open'); setFlags(); } });

    // ── go ───────────────────────────────────────────────────────────────────
    const wide = window.matchMedia('(min-width: 1200px)');
    const follow = () => { wide.matches ? engage() : disengage(); };
    if (wide.addEventListener) wide.addEventListener('change', follow); else if (wide.addListener) wide.addListener(follow);
    // A window resized by hand, or by a tool that never fires the media query's own event, gets there too.
    window.addEventListener('resize', follow);
    // The map builds some of its controls after this script has run.
    const controls = $('#warRoomMap .leaflet-control-container');
    if (controls && window.MutationObserver) new MutationObserver(adopt).observe(controls, {childList: true, subtree: true});
    follow();
    setTimeout(adopt, 500);
    setTimeout(adopt, 2000);
})();
