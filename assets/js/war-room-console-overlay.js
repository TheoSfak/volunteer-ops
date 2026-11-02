/*
 * War Room (Action Room) - the «Πίνακας» look of the admin «Κονσόλα»
 * (Settings → Εμφάνιση Κονσόλας = overlay; assets/css/war-room-console-overlay.css).
 *
 * The other looks are colours over the same three columns. This one is a LAYOUT: the map takes the
 * whole window and everything else floats over it as dark translucent squares and panels - a top
 * bar, a rail of the six kinds of order on the left, the map's tools in a stack on the right, a
 * segmented bar with the teams at the bottom, the queue and the feed as floating cards.
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

if (typeof module !== 'undefined' && module.exports) {
    module.exports = {wrcOvWindow, wrcOvStep, wrcOvElapsed, wrcOvLowestBattery, wrcOvGpsReadout, wrcOvTeamRows, wrcOvLiveStreams, wrcOvTilePick, wrcOvLeft};
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
    root.append(top, rail, stack, pair, bottom, tools);

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
    stack.innerHTML = '<div class="wrc-ov-col" data-g="nav"></div><div class="wrc-ov-col" data-g="draw"></div><div class="wrc-ov-col" data-g="extra"></div>';

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
        // The acknowledgement panel floats over the map like the rest: inside the board, under its menus.
        take($('#ackTracker'), root);
        take($('#trailFilterBar'), root);

        const nav = $('[data-g="nav"]', stack);
        const layers = $('#mapSatelliteToggle');
        take(layers && layers.parentNode, nav);
        take($('#mapFollowBtn'), nav);
        take($('#annotationToolbar'), $('[data-g="draw"]', stack));
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
        window.dispatchEvent(new Event('resize'));
    }

    function disengage() {
        if (!engaged) return;
        engaged = false;
        html.removeAttribute('data-wrc-ov');
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
