/**
 * Offline field view (v3.422.0) - the page a volunteer gets when war-room.php
 * cannot load at all: Android killed the WebView in a pocket and the app was
 * reopened in a dead zone, a tunnel, a page refresh with no signal.
 *
 * offline.html loads this next to Leaflet (assets/leaflet/, precached by sw.js)
 * and calls VoFieldMode.init() with the snapshot war-room.php left behind in
 * localStorage['wr_field_snapshot'] (saveFieldSnapshot()). It draws:
 *   - the map, from tiles the service worker kept (sw.js isTileRequest()),
 *   - a blue dot and accuracy circle from the phone's own GPS - GPS needs no
 *     network, which is the whole point,
 *   - my team, the command post, my sectors, the clues and imported points,
 *   - the distance and direction to the order being followed,
 *   - the buttons: SOS, «Έφτασα», «Ελήφθη», «Ολοκληρώθηκε», «Δεν μπορώ».
 *
 * It SENDS NOTHING. Every button appends to the same queue the live page uses,
 * localStorage['wr_offline_queue_<missionId>'], in the shapes enqueueAction()
 * and sendQueuedItem() in war-room.php already replay; when the network is
 * back offline.html reloads into the real page and flushQueue() delivers it,
 * stamped with the moment of the tap (reported_at), same as always.
 *
 * window.voFieldFeed.apply(delta) is the single door for data that arrives by
 * some other road than the network - the planned LoRa/Meshtastic gateway. Only
 * the shape and the merge rules live here, no radio code. See applyFeedDelta().
 *
 * The pure helpers are exported for Node (tests/js/field-mode.test.js).
 */
(function (root) {
    'use strict';

    // ── Geometry ────────────────────────────────────────────────────────────
    // Same maths as bearing() in war-room-utils.js and Leaflet's distanceTo(),
    // restated here because the offline page loads neither of those files.
    const EARTH_RADIUS_M = 6371000;
    const rad = deg => deg * Math.PI / 180;

    function distanceM(a, b) {
        const dLat = rad(b.lat - a.lat), dLng = rad(b.lng - a.lng);
        const h = Math.sin(dLat / 2) * Math.sin(dLat / 2)
            + Math.cos(rad(a.lat)) * Math.cos(rad(b.lat)) * Math.sin(dLng / 2) * Math.sin(dLng / 2);
        return 2 * EARTH_RADIUS_M * Math.asin(Math.min(1, Math.sqrt(h)));
    }

    /** Initial bearing from a to b, degrees clockwise from north, 0..360. */
    function bearingDeg(a, b) {
        const lat1 = rad(a.lat), lat2 = rad(b.lat), dLng = rad(b.lng - a.lng);
        const y = Math.sin(dLng) * Math.cos(lat2);
        const x = Math.cos(lat1) * Math.sin(lat2) - Math.sin(lat1) * Math.cos(lat2) * Math.cos(dLng);
        return (Math.atan2(y, x) * 180 / Math.PI + 360) % 360;
    }

    function formatDistance(metres) {
        if (!(metres >= 0)) return '';
        if (metres < 1000) return Math.round(metres / 5) * 5 + ' m';
        return (metres / 1000).toFixed(metres < 10000 ? 2 : 1) + ' km';
    }

    function validLatLng(lat, lng) {
        return typeof lat === 'number' && typeof lng === 'number'
            && isFinite(lat) && isFinite(lng)
            && lat >= -90 && lat <= 90 && lng >= -180 && lng <= 180
            && !(lat === 0 && lng === 0);
    }

    // ── The offline queue ───────────────────────────────────────────────────
    // What makes two queued items "the same tap". Mirrors enqueueAction() in
    // war-room.php, which collapses a repeated identical status (a person in
    // trouble taps SOS again and again; the queued reported_at must stay the
    // moment help was FIRST asked for). The other kinds are collapsed here too,
    // because on this page a button stays visible until the reload.
    function queueKey(item) {
        switch (item.kind) {
            case 'status':  return ['status', item.prId, item.status].join('|');
            case 'order':   return ['order', item.action, item.orderId].join('|');
            case 'decline': return ['decline', item.target, item.id].join('|');
            case 'route':   return ['route', item.action, item.id].join('|');
            default:        return null;
        }
    }

    /** Returns {queue, added}; an identical item already waiting is left as it is. */
    function enqueueInto(queue, item) {
        const key = queueKey(item);
        if (key !== null && queue.some(q => queueKey(q) === key)) return {queue: queue, added: false};
        return {queue: queue.concat([item]), added: true};
    }

    // ── What each order offers ──────────────────────────────────────────────
    // `order` is a row of snapshot.orders (snapshotFieldOrders() in
    // war-room.php); `queue` is what is already waiting. An answer that is
    // queued counts as given - «Ολοκληρώθηκε» appears the moment «Ελήφθη» is
    // queued, and neither button comes back - so the page never invites a
    // second tap on something it is already holding.
    function queuedFor(order, queue) {
        const orderId = String(order.kind === 'route' ? order.orderId : order.id);
        const has = fn => queue.some(fn);
        return {
            ack: has(q => q.kind === 'order' && q.action === 'acknowledge' && q.orderId === orderId),
            complete: has(q => q.kind === 'order' && q.action === 'complete' && q.orderId === orderId),
            arrive: has(q => q.kind === 'route' && q.action === 'arrive' && q.id === String(order.nextWaypointId)),
            decline: has(q => q.kind === 'decline' && q.target === order.kind && q.id === String(order.id)),
        };
    }

    function actionsFor(order, queue) {
        const pending = queuedFor(order, queue);
        const out = [];
        if (pending.decline) return {actions: out, pending: pending};
        const acked = order.acked || pending.ack;
        if (order.kind === 'order') {
            if (!acked) out.push('ack');
            else if (order.type === 'task' && order.steps === 2 && !pending.complete) out.push('complete');
        } else if (order.kind === 'route') {
            if (!acked) out.push('ack');
            if (order.nextWaypointOpen && order.nextWaypointId && !pending.arrive) out.push('arrive');
        }
        if (order.canDecline) out.push('decline');
        return {actions: out, pending: pending};
    }

    const DECLINE_REASONS = ['unsafe', 'no_access', 'busy', 'injury', 'other'];

    /**
     * The queue item(s) a button writes, in the exact shapes sendQueuedItem()
     * replays. `fix` is {lat, lng, acc} or null; `nowIso` the moment of the tap.
     */
    function itemsForAction(order, action, ctx) {
        const fix = ctx.fix || null;
        const reportedAt = ctx.nowIso;
        if (action === 'ack' || action === 'complete') {
            const orderId = String(order.kind === 'route' ? order.orderId : order.id);
            return [{kind: 'order', action: action === 'ack' ? 'acknowledge' : 'complete', orderId: orderId}];
        }
        if (action === 'arrive') {
            return [{
                kind: 'route', action: 'arrive', id: String(order.nextWaypointId),
                extra: {
                    lat: fix ? fix.lat : '', lng: fix ? fix.lng : '',
                    accuracy: fix && fix.acc !== null && fix.acc !== undefined ? fix.acc : '',
                    reported_at: reportedAt,
                },
            }];
        }
        if (action === 'decline') {
            if (DECLINE_REASONS.indexOf(ctx.reason) === -1) return [];
            const note = String(ctx.note || '').trim().slice(0, 300);
            if (ctx.reason === 'other' && !note) return [];
            return [{kind: 'decline', target: order.kind, id: String(order.id), reason: ctx.reason, note: note}];
        }
        return [];
    }

    /** SOS / «Έφτασα»: a status on my own participation request. */
    function statusItem(prId, status, fix, nowIso) {
        if (!prId) return null;
        const extra = {reported_at: nowIso};
        if (fix) {
            extra.lat = fix.lat;
            extra.lng = fix.lng;
            if (fix.acc !== null && fix.acc !== undefined) extra.accuracy = fix.acc;
        }
        return {kind: 'status', prId: String(prId), status: status, extra: extra};
    }

    // ── The radio door ──────────────────────────────────────────────────────
    // window.voFieldFeed.apply(delta) - what a gateway calls with whatever
    // arrived over the air. Small on purpose: a LoRa frame carries a few
    // hundred bytes, so a delta is positions, an order as a code plus a target,
    // acknowledgements and one short line of text.
    //
    //   delta = {
    //     v: 1,
    //     at: <ms since epoch the sender stamped it; default: arrival time>,
    //     positions: [{id, name?, lat, lng, ts?, acc?}],  // teammates, by id
    //     command: {lat, lng, note?},                      // the command post
    //     orders: [{code, text?, lat?, lng?}],             // an order = code + target
    //     acks: [code, ...],                               // command confirms: got your answer to this order
    //     text: 'short message'
    //   }
    //
    // Everything is validated and clipped here; whatever does not pass is
    // reported back in `rejected` and never stored. Returns
    // {feed, applied: {positions, orders, acks, messages, command}, rejected}.
    // `feed` is the model the page draws and persists:
    //   {positions: {id: {...}}, command, orders: [{code, text, lat, lng, at, confirmed}], messages: [{at, text}]}
    const FEED_LIMITS = {positions: 50, orders: 20, messages: 30, text: 200, name: 40, code: 16, note: 120};

    function emptyFeed() {
        return {positions: {}, command: null, orders: [], messages: []};
    }

    function clipText(value, max) {
        return String(value === null || value === undefined ? '' : value).replace(/[\u0000-\u001f]/g, ' ').trim().slice(0, max);
    }

    function applyFeedDelta(feedIn, delta, nowMs) {
        const feed = {
            positions: Object.assign({}, (feedIn && feedIn.positions) || {}),
            command: (feedIn && feedIn.command) || null,
            orders: ((feedIn && feedIn.orders) || []).slice(),
            messages: ((feedIn && feedIn.messages) || []).slice(),
        };
        const applied = {positions: 0, orders: 0, acks: 0, messages: 0, command: false};
        const rejected = [];
        if (!delta || typeof delta !== 'object' || Array.isArray(delta)) {
            return {feed: feed, applied: applied, rejected: ['delta']};
        }
        if (delta.v !== undefined && delta.v !== 1) {
            return {feed: feed, applied: applied, rejected: ['version']};
        }
        const at = typeof delta.at === 'number' && isFinite(delta.at) ? delta.at : nowMs;

        (Array.isArray(delta.positions) ? delta.positions.slice(0, FEED_LIMITS.positions) : []).forEach((p, i) => {
            const id = p && clipText(p.id, FEED_LIMITS.code);
            if (!id || !validLatLng(p.lat, p.lng)) { rejected.push('positions[' + i + ']'); return; }
            const ts = typeof p.ts === 'number' && isFinite(p.ts) ? p.ts : at;
            // A late frame must not move a dot back to where somebody was.
            if (feed.positions[id] && feed.positions[id].ts > ts) return;
            feed.positions[id] = {
                id: id, name: clipText(p.name, FEED_LIMITS.name), lat: p.lat, lng: p.lng, ts: ts,
                acc: typeof p.acc === 'number' && isFinite(p.acc) ? p.acc : null,
            };
            applied.positions++;
        });

        if (delta.command !== undefined && delta.command !== null) {
            if (validLatLng(delta.command.lat, delta.command.lng)) {
                feed.command = {
                    lat: delta.command.lat, lng: delta.command.lng,
                    note: clipText(delta.command.note, FEED_LIMITS.note), at: at,
                };
                applied.command = true;
            } else {
                rejected.push('command');
            }
        }

        (Array.isArray(delta.orders) ? delta.orders.slice(0, FEED_LIMITS.orders) : []).forEach((o, i) => {
            const code = o && clipText(o.code, FEED_LIMITS.code);
            if (!code) { rejected.push('orders[' + i + ']'); return; }
            const hasTarget = o.lat !== undefined || o.lng !== undefined;
            if (hasTarget && !validLatLng(o.lat, o.lng)) { rejected.push('orders[' + i + '].target'); return; }
            const previous = feed.orders.find(x => x.code === code);
            const order = {
                code: code, text: clipText(o.text, FEED_LIMITS.text),
                lat: hasTarget ? o.lat : null, lng: hasTarget ? o.lng : null,
                at: at, confirmed: previous ? previous.confirmed : false,
            };
            feed.orders = feed.orders.filter(x => x.code !== code).concat([order]).slice(-FEED_LIMITS.orders);
            applied.orders++;
        });

        (Array.isArray(delta.acks) ? delta.acks.slice(0, FEED_LIMITS.orders) : []).forEach((code, i) => {
            const clean = clipText(code, FEED_LIMITS.code);
            const order = clean && feed.orders.find(x => x.code === clean);
            if (!order) { rejected.push('acks[' + i + ']'); return; }
            order.confirmed = true;
            applied.acks++;
        });

        if (delta.text !== undefined && delta.text !== null) {
            const text = clipText(delta.text, FEED_LIMITS.text);
            if (!text) {
                rejected.push('text');
            } else if (!feed.messages.some(m => m.at === at && m.text === text)) {
                feed.messages = feed.messages.concat([{at: at, text: text}]).slice(-FEED_LIMITS.messages);
                applied.messages++;
            }
        }
        return {feed: feed, applied: applied, rejected: rejected};
    }

    function ageLabel(ts, nowMs, T) {
        if (!ts) return '';
        const min = Math.max(0, Math.round((nowMs - ts) / 60000));
        if (min < 1) return T.justNow;
        return min < 60 ? T.minutesAgo.replace('{n}', min) : T.hoursAgo.replace('{n}', Math.round(min / 60));
    }

    const pure = {
        distanceM, bearingDeg, formatDistance, validLatLng,
        queueKey, enqueueInto, queuedFor, actionsFor, itemsForAction, statusItem, DECLINE_REASONS,
        emptyFeed, applyFeedDelta, FEED_LIMITS, ageLabel,
    };
    if (typeof module !== 'undefined' && module.exports) module.exports = pure;
    if (typeof document === 'undefined') return;

    // ── The page ────────────────────────────────────────────────────────────
    const FIX_FRESH_MS = 120000;
    const SOS_CONFIRM_MS = 4000;
    const BASE_KEYS = ['street', 'topo', 'satellite', 'hybrid'];

    function esc(s) {
        return String(s === null || s === undefined ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function init(opts) {
        const snapshot = opts.snapshot;
        const T = opts.strings;
        const host = opts.host;
        const missionId = snapshot.mission.id;
        const QUEUE_KEY = 'wr_offline_queue_' + missionId;
        const FEED_KEY = 'wr_field_feed_' + missionId;
        const me = snapshot.me || {};
        const orders = snapshot.orders || [];
        const L = root.L || null;

        const t = (key, vars) => {
            let s = T[key] || key;
            for (const k in (vars || {})) s = s.split('{' + k + '}').join(vars[k]);
            return s;
        };

        const state = {
            fix: null, heading: null, headingAt: 0, geoError: null,
            followKey: null, sosArmedAt: 0, declineOpen: null, feed: emptyFeed(),
            userMovedMap: false, centredOnce: false,
        };
        try {
            const stored = JSON.parse(localStorage.getItem(FEED_KEY) || 'null');
            if (stored && typeof stored === 'object') state.feed = Object.assign(emptyFeed(), stored);
        } catch (e) {}

        const loadQueue = () => {
            try {
                const parsed = JSON.parse(localStorage.getItem(QUEUE_KEY) || '[]');
                return Array.isArray(parsed) ? parsed : [];
            } catch (e) { return []; }
        };
        function enqueue(items) {
            let queue = loadQueue();
            items.forEach(item => { queue = enqueueInto(queue, item).queue; });
            try { localStorage.setItem(QUEUE_KEY, JSON.stringify(queue)); return true; } catch (e) { return false; }
        }

        // ── Markup ──────────────────────────────────────────────────────────
        host.innerHTML = `
            <div class="fm-queue" id="fmQueue" hidden></div>
            <div class="fm-bar" id="fmBar"></div>
            <div class="fm-mapwrap">
                <div id="fmMap" class="fm-map"></div>
                <div class="fm-nav" id="fmNav" hidden></div>
                <div class="fm-geo" id="fmGeo" hidden></div>
            </div>
            <div class="fm-layers" id="fmLayers"></div>
            <div id="fmOrders"></div>
            <div id="fmFeed"></div>`;
        const $ = id => document.getElementById(id);

        // ── Map ─────────────────────────────────────────────────────────────
        let map = null, baseKey = 'street', baseLayers = {}, dynamicGroup = null, meMarker = null, meCircle = null, followLine = null;

        function makeBaseLayers() {
            const esriPath = 'https://server.arcgisonline.com/ArcGIS/rest/services/';
            const maxZoom = 21;
            // Same URLs and options as addMapBaseLayers() in war-room.php, so a
            // tile the live map showed is the very tile the service worker holds.
            const sat = root.voEsriImagery
                ? root.voEsriImagery({attribution: 'Tiles © Esri', maxNativeZoom: 19, maxZoom: maxZoom})
                : L.tileLayer(esriPath + 'World_Imagery/MapServer/tile/{z}/{y}/{x}', {attribution: 'Tiles © Esri', maxNativeZoom: 19, maxZoom: maxZoom, crossOrigin: true});
            return {
                street: L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {attribution: '© OpenStreetMap', maxNativeZoom: 19, maxZoom: maxZoom, crossOrigin: true}),
                topo: L.tileLayer('https://{s}.tile.opentopomap.org/{z}/{x}/{y}.png', {attribution: '© OpenTopoMap (CC-BY-SA)', maxNativeZoom: 17, maxZoom: maxZoom, crossOrigin: true}),
                satellite: sat,
                hybrid: L.layerGroup([
                    sat,
                    L.tileLayer(esriPath + 'Reference/World_Transportation/MapServer/tile/{z}/{y}/{x}', {maxNativeZoom: 19, maxZoom: maxZoom, zIndex: 2, crossOrigin: true}),
                    L.tileLayer(esriPath + 'Reference/World_Boundaries_and_Places/MapServer/tile/{z}/{y}/{x}', {attribution: 'Labels © Esri', maxNativeZoom: 19, maxZoom: maxZoom, zIndex: 3, crossOrigin: true}),
                ]),
            };
        }

        const dot = (css, html) => L.divIcon({className: '', html: '<div class="fm-dot ' + css + '">' + html + '</div>', iconSize: [28, 28], iconAnchor: [14, 14]});
        const initials = name => String(name || '?').trim().split(/\s+/).map(w => w.charAt(0)).join('').slice(0, 2).toUpperCase();

        function startView() {
            const v = snapshot.view;
            if (v && validLatLng(v.lat, v.lng)) return {c: [v.lat, v.lng], z: Math.min(Math.max(v.zoom || 15, 3), 19)};
            if (snapshot.mission.lat) return {c: [snapshot.mission.lat, snapshot.mission.lng], z: 15};
            if (snapshot.command) return {c: [snapshot.command.lat, snapshot.command.lng], z: 15};
            return {c: [35.2, 25.0], z: 9};
        }

        function drawStatic() {
            const shapeStyle = {color: '#facc15', weight: 2, fillOpacity: .08};
            (snapshot.areas || []).forEach(a => L.polygon(a.geo, {color: '#ffffff', weight: 2, dashArray: '6 6', fill: false}).bindTooltip(esc(a.label)).addTo(map));
            (snapshot.sectors || []).forEach(s => L.polygon(s.geo, shapeStyle).bindTooltip(esc(s.label) + (s.statusLabel ? ' — ' + esc(s.statusLabel) : '')).addTo(map));
            (snapshot.pois || []).forEach(p => L.marker([p.lat, p.lng], {icon: dot(p.checked ? 'fm-poi-done' : 'fm-poi', '🔍')}).bindTooltip(esc(t('poi')) + (p.at ? ' · ' + esc(p.at) : '')).addTo(map));
            (snapshot.points || []).forEach(p => L.marker([p.lat, p.lng], {icon: dot('fm-point', '📍')}).bindTooltip(esc(p.name || '')).addTo(map));
            if (snapshot.command) {
                L.marker([snapshot.command.lat, snapshot.command.lng], {icon: dot('fm-cp', '⚑'), zIndexOffset: 3000})
                    .bindTooltip(esc(t('commandPost')) + (snapshot.command.note ? ' — ' + esc(snapshot.command.note) : '')).addTo(map);
            }
        }

        // The things that move or change with a tap: teammates, radio data,
        // the order being followed, the line to it. Rebuilt wholesale - there
        // are a handful of them and rebuilding is simpler than diffing.
        function drawDynamic() {
            if (!map) return;
            dynamicGroup.clearLayers();
            const now = Date.now();
            (snapshot.members || []).forEach(m => {
                L.marker([m.lat, m.lng], {icon: dot('fm-mate', esc(initials(m.name)))})
                    .bindTooltip(esc(m.name) + (m.ts ? ' · ' + esc(ageLabel(m.ts, now, T)) : '')).addTo(dynamicGroup);
            });
            Object.keys(state.feed.positions).forEach(id => {
                const p = state.feed.positions[id];
                L.marker([p.lat, p.lng], {icon: dot('fm-mate fm-radio', esc(initials(p.name || p.id)))})
                    .bindTooltip('📻 ' + esc(p.name || p.id) + ' · ' + esc(ageLabel(p.ts, now, T))).addTo(dynamicGroup);
            });
            if (state.feed.command) {
                L.marker([state.feed.command.lat, state.feed.command.lng], {icon: dot('fm-cp fm-radio', '⚑'), zIndexOffset: 3000})
                    .bindTooltip('📻 ' + esc(t('commandPost')) + ' ' + esc(ageLabel(state.feed.command.at, now, T))).addTo(dynamicGroup);
            }
            targets().forEach(tg => {
                L.marker([tg.lat, tg.lng], {icon: dot(tg.key === state.followKey ? 'fm-target fm-target-on' : 'fm-target', '🎯')})
                    .bindTooltip(esc(tg.text)).addTo(dynamicGroup);
            });
            const tg = followed();
            if (state.fix && tg) {
                followLine = L.polyline([[state.fix.lat, state.fix.lng], [tg.lat, tg.lng]], {color: '#38bdf8', weight: 3, dashArray: '4 8'}).addTo(dynamicGroup);
            }
        }

        function initMap() {
            if (!L) { $('fmMap').innerHTML = '<div class="fm-nomap">' + esc(t('noMapLib')) + '</div>'; return; }
            const v = startView();
            map = L.map('fmMap', {zoomControl: true, attributionControl: true}).setView(v.c, v.z);
            baseLayers = makeBaseLayers();
            try { baseKey = localStorage.getItem('wr_map_base_layer'); } catch (e) {}
            if (BASE_KEYS.indexOf(baseKey) === -1) baseKey = 'street';
            baseLayers[baseKey].addTo(map);
            dynamicGroup = L.layerGroup().addTo(map);
            drawStatic();
            // Someone who has panned the map has taken the wheel: the first GPS
            // fix may only recentre it while they have not.
            map.on('dragstart zoomstart', () => { if (state.centredOnce) state.userMovedMap = true; });
            $('fmLayers').innerHTML = BASE_KEYS.map(k => '<button type="button" class="fm-layer" data-layer="' + k + '">' + esc(t('layer_' + k)) + '</button>').join('')
                + '<button type="button" class="fm-layer" data-fm="locate">📍 ' + esc(t('locate')) + '</button>';
            paintLayerButtons();
        }

        function paintLayerButtons() {
            document.querySelectorAll('#fmLayers [data-layer]').forEach(b => b.classList.toggle('on', b.dataset.layer === baseKey));
        }

        // ── Orders being followed ───────────────────────────────────────────
        function targets() {
            const out = [];
            orders.forEach(o => { if (o.nav && validLatLng(o.nav.lat, o.nav.lng)) out.push({key: o.key, text: o.text || o.title, lat: o.nav.lat, lng: o.nav.lng}); });
            (state.feed.orders || []).forEach(o => { if (o.lat !== null && o.lng !== null) out.push({key: 'feed:' + o.code, text: o.text || o.code, lat: o.lat, lng: o.lng}); });
            return out;
        }
        function followed() {
            const all = targets();
            return all.find(x => x.key === state.followKey) || all[0] || null;
        }

        function renderNav() {
            const el = $('fmNav');
            const tg = followed();
            if (!tg) { el.hidden = true; return; }
            el.hidden = false;
            if (!state.fix || Date.now() - state.fix.ts > FIX_FRESH_MS) {
                el.innerHTML = '<div class="fm-nav-text"><b>' + esc(tg.text) + '</b><br>' + esc(state.geoError ? t('geoDenied') : t('noFix')) + '</div>';
                return;
            }
            const from = {lat: state.fix.lat, lng: state.fix.lng};
            const metres = distanceM(from, tg);
            const brg = bearingDeg(from, tg);
            // With a heading the arrow is relative to where the phone points;
            // without one it is a bearing and says so (north-up).
            const haveHeading = state.heading !== null && Date.now() - state.headingAt < 3000;
            const rot = haveHeading ? (brg - state.heading + 360) % 360 : brg;
            el.innerHTML = '<svg class="fm-arrow" viewBox="0 0 100 100" style="transform:rotate(' + rot.toFixed(0) + 'deg)"><path d="M50 4 L80 86 L50 68 L20 86 Z"/></svg>'
                + '<div class="fm-nav-text"><b>' + esc(tg.text) + '</b><br>'
                + '<span class="fm-nav-dist">' + esc(formatDistance(metres)) + '</span> · ' + Math.round(brg) + '°'
                + (haveHeading ? '' : ' <span class="fm-dim">' + esc(t('northUp')) + '</span>') + '</div>';
        }

        // ── GPS ─────────────────────────────────────────────────────────────
        function onFix(pos) {
            const c = pos.coords;
            state.fix = {lat: c.latitude, lng: c.longitude, acc: typeof c.accuracy === 'number' ? Math.round(c.accuracy) : null, ts: Date.now()};
            state.geoError = null;
            $('fmGeo').hidden = true;
            if (typeof c.heading === 'number' && !isNaN(c.heading) && (c.speed || 0) >= 0.7 && Date.now() - state.headingAt > 3000) {
                state.heading = c.heading;
                state.headingAt = Date.now();
            }
            if (map) {
                const ll = [state.fix.lat, state.fix.lng];
                if (!meMarker) {
                    meMarker = L.marker(ll, {icon: L.divIcon({className: '', html: '<div class="fm-me"></div>', iconSize: [22, 22], iconAnchor: [11, 11]}), zIndexOffset: 5000}).addTo(map);
                    meCircle = L.circle(ll, {radius: state.fix.acc || 30, color: '#2563eb', weight: 1, fillOpacity: .12}).addTo(map);
                } else {
                    meMarker.setLatLng(ll);
                    meCircle.setLatLng(ll).setRadius(state.fix.acc || 30);
                }
                if (!state.userMovedMap) {
                    if (!state.centredOnce) map.setView(ll, Math.max(map.getZoom(), 16));
                    else map.panTo(ll, {animate: true});
                    state.centredOnce = true;
                }
            }
            drawDynamic();
            renderNav();
        }
        function onGeoError(err) {
            state.geoError = err && err.code === 1 ? 'denied' : 'unavailable';
            const el = $('fmGeo');
            el.textContent = t(state.geoError === 'denied' ? 'geoDenied' : 'geoUnavailable');
            el.hidden = false;
            renderNav();
        }
        function startGps() {
            if (!navigator.geolocation) { onGeoError({code: 2}); return; }
            navigator.geolocation.watchPosition(onFix, onGeoError, {enableHighAccuracy: true, maximumAge: 5000, timeout: 30000});
        }
        function startCompass() {
            const onOrient = e => {
                let h = null;
                if (typeof e.webkitCompassHeading === 'number') h = e.webkitCompassHeading;
                else if (e.absolute && typeof e.alpha === 'number') h = (360 - e.alpha) % 360;
                if (h === null) return;
                state.heading = h;
                state.headingAt = Date.now();
            };
            window.addEventListener('deviceorientationabsolute', onOrient);
            window.addEventListener('deviceorientation', onOrient);
        }

        // The position to put on a queued report: the live fix if it is recent,
        // else one quick attempt, else none - an SOS without coordinates still
        // beats no SOS, same rule as setFieldStatus() in war-room.php.
        function fixForReport() {
            if (state.fix && Date.now() - state.fix.ts < FIX_FRESH_MS) return Promise.resolve(state.fix);
            return new Promise(resolve => {
                if (!navigator.geolocation) { resolve(null); return; }
                navigator.geolocation.getCurrentPosition(
                    pos => resolve({lat: pos.coords.latitude, lng: pos.coords.longitude, acc: Math.round(pos.coords.accuracy), ts: Date.now()}),
                    () => resolve(null),
                    {enableHighAccuracy: true, maximumAge: 60000, timeout: 4000}
                );
            });
        }

        // ── Buttons ─────────────────────────────────────────────────────────
        function renderBar() {
            const queue = loadQueue();
            const sosQueued = queue.some(q => q.kind === 'status' && q.status === 'needs_help');
            const arrivedQueued = queue.some(q => q.kind === 'status' && q.status === 'on_site');
            if (!me.prId) {
                $('fmBar').innerHTML = '<div class="fm-note">' + esc(t('noParticipation')) + '</div>';
                return;
            }
            const armed = Date.now() - state.sosArmedAt < SOS_CONFIRM_MS;
            $('fmBar').innerHTML =
                '<button type="button" class="fm-sos' + (sosQueued ? ' queued' : armed ? ' armed' : '') + '" data-fm="sos"' + (sosQueued ? ' disabled' : '') + '>'
                + esc(sosQueued ? t('sosQueued') : armed ? t('sosAgain') : t('sos')) + '</button>'
                + '<button type="button" class="fm-arrived' + (arrivedQueued ? ' queued' : '') + '" data-fm="arrived"' + (arrivedQueued ? ' disabled' : '') + '>'
                + esc(arrivedQueued ? t('arrivedQueued') : t('arrived')) + '</button>';
        }

        function renderQueue() {
            const queue = loadQueue();
            const el = $('fmQueue');
            if (!queue.length) { el.hidden = true; el.innerHTML = ''; return; }
            el.hidden = false;
            const sos = queue.some(i => i.kind === 'status' && i.status === 'needs_help');
            el.innerHTML = esc(queue.length === 1 ? t('queued_one') : t('queued', {n: queue.length}))
                + (sos ? '<div style="margin-top:.35rem;">' + esc(t('queuedSos')) + '</div>' : '');
        }

        function orderCard(o, queue) {
            const {actions, pending} = actionsFor(o, queue);
            const btn = (act, cls, label) => '<button type="button" class="fm-act ' + cls + '" data-fm="act" data-act="' + act + '" data-key="' + esc(o.key) + '">' + esc(label) + '</button>';
            let html = '<div class="fm-order' + (o.key === (followed() || {}).key ? ' follow' : '') + '">';
            html += '<div class="fm-order-title">' + esc(o.title || '') + '</div>';
            if (o.text) html += '<div class="fm-order-text">' + esc(o.text) + '</div>';
            if (o.meta) html += '<div class="fm-dim">' + esc(o.meta) + '</div>';
            const waiting = [];
            if (pending.ack) waiting.push(t('pendingAck'));
            if (pending.complete) waiting.push(t('pendingComplete'));
            if (pending.arrive) waiting.push(t('pendingArrive'));
            if (pending.decline) waiting.push(t('pendingDecline'));
            if (waiting.length) html += '<div class="fm-pending">⏳ ' + esc(waiting.join(' · ')) + '</div>';
            html += '<div class="fm-order-btns">';
            if (o.nav) html += btn('follow', 'ghost', '🎯 ' + t('follow'));
            if (actions.indexOf('ack') !== -1) html += btn('ack', 'warn', t('ack'));
            if (actions.indexOf('arrive') !== -1) html += btn('arrive', 'ok', t('arrivedHere'));
            if (actions.indexOf('complete') !== -1) html += btn('complete', 'ok', t('complete'));
            if (actions.indexOf('decline') !== -1) html += btn('decline-open', 'danger', t('cantDo'));
            html += '</div>';
            if (state.declineOpen === o.key) {
                html += '<div class="fm-decline">' + DECLINE_REASONS.map(r => '<button type="button" class="fm-reason" data-fm="reason" data-reason="' + r + '" data-key="' + esc(o.key) + '">' + esc(t('reason_' + r)) + '</button>').join('')
                    + '<input type="text" class="fm-note-input" id="fmNote" maxlength="300" placeholder="' + esc(t('reasonNote')) + '"></div>';
            }
            return html + '</div>';
        }

        function renderOrders() {
            const queue = loadQueue();
            let html = '';
            if (orders.length) {
                html += '<div class="snap-section-title">' + esc(t('orders')) + '</div>' + orders.map(o => orderCard(o, queue)).join('');
            }
            $('fmOrders').innerHTML = html;
        }

        function renderFeed() {
            const f = state.feed;
            const now = Date.now();
            let html = '';
            if ((f.orders || []).length || (f.messages || []).length) {
                html += '<div class="snap-section-title">📻 ' + esc(t('radio')) + '</div>';
                (f.orders || []).forEach(o => {
                    html += '<div class="fm-order fm-radio-card"><div class="fm-order-title">📻 ' + esc(o.code) + (o.confirmed ? ' ✔' : '') + '</div>'
                        + (o.text ? '<div class="fm-order-text">' + esc(o.text) + '</div>' : '')
                        + '<div class="fm-dim">' + esc(ageLabel(o.at, now, T)) + '</div>'
                        + (o.lat !== null ? '<div class="fm-order-btns"><button type="button" class="fm-act ghost" data-fm="act" data-act="follow" data-key="feed:' + esc(o.code) + '">🎯 ' + esc(t('follow')) + '</button></div>' : '')
                        + '</div>';
                });
                (f.messages || []).slice().reverse().forEach(m => {
                    html += '<div class="fm-order fm-radio-card"><div class="fm-order-text">' + esc(m.text) + '</div><div class="fm-dim">' + esc(ageLabel(m.at, now, T)) + '</div></div>';
                });
            }
            $('fmFeed').innerHTML = html;
        }

        function renderAll() {
            renderQueue();
            renderBar();
            renderOrders();
            renderFeed();
            drawDynamic();
            renderNav();
        }

        function toast(text) {
            const el = $('fmGeo');
            el.textContent = text;
            el.hidden = false;
            setTimeout(() => { if (!state.geoError) el.hidden = true; }, 3500);
        }

        // ── Taps ────────────────────────────────────────────────────────────
        function findOrder(key) { return orders.find(o => o.key === key); }

        host.addEventListener('click', e => {
            const layer = e.target.closest('[data-layer]');
            if (layer && map) {
                map.removeLayer(baseLayers[baseKey]);
                baseKey = layer.dataset.layer;
                map.addLayer(baseLayers[baseKey]);
                try { localStorage.setItem('wr_map_base_layer', baseKey); } catch (err) {}
                paintLayerButtons();
                return;
            }
            const el = e.target.closest('[data-fm]');
            if (!el || el.disabled) return;
            const what = el.dataset.fm;
            if (what === 'locate') {
                state.userMovedMap = false;
                if (state.fix && map) map.setView([state.fix.lat, state.fix.lng], Math.max(map.getZoom(), 16));
                return;
            }
            if (what === 'sos') {
                if (Date.now() - state.sosArmedAt > SOS_CONFIRM_MS) {
                    state.sosArmedAt = Date.now();
                    renderBar();
                    setTimeout(renderBar, SOS_CONFIRM_MS + 50);
                    return;
                }
                state.sosArmedAt = 0;
                const nowIso = new Date().toISOString();
                el.disabled = true;
                fixForReport().then(fix => {
                    const item = statusItem(me.prId, 'needs_help', fix, nowIso);
                    if (item) enqueue([item]);
                    renderAll();
                });
                return;
            }
            if (what === 'arrived') {
                const nowIso = new Date().toISOString();
                el.disabled = true;
                fixForReport().then(fix => {
                    const item = statusItem(me.prId, 'on_site', fix, nowIso);
                    if (item) enqueue([item]);
                    renderAll();
                });
                return;
            }
            if (what === 'act') {
                const act = el.dataset.act;
                if (act === 'follow') { state.followKey = el.dataset.key; renderAll(); return; }
                if (act === 'decline-open') { state.declineOpen = state.declineOpen === el.dataset.key ? null : el.dataset.key; renderOrders(); return; }
                const order = findOrder(el.dataset.key);
                if (!order) return;
                const nowIso = new Date().toISOString();
                el.disabled = true;
                // Only arriving carries a position; «Ελήφθη» must not wait on a GPS attempt.
                (act === 'arrive' ? fixForReport() : Promise.resolve(null)).then(fix => {
                    enqueue(itemsForAction(order, act, {fix: fix, nowIso: nowIso}));
                    renderAll();
                });
                return;
            }
            if (what === 'reason') {
                const order = findOrder(el.dataset.key);
                if (!order) return;
                const noteEl = $('fmNote');
                const items = itemsForAction(order, 'decline', {reason: el.dataset.reason, note: noteEl ? noteEl.value : ''});
                if (!items.length) { toast(t('reasonNeedsNote')); if (noteEl) noteEl.focus(); return; }
                enqueue(items);
                state.declineOpen = null;
                renderAll();
            }
        });

        // ── The radio door ──────────────────────────────────────────────────
        function persistFeed() {
            try { localStorage.setItem(FEED_KEY, JSON.stringify(state.feed)); } catch (e) {}
        }
        const feedApi = {
            apply: function (delta) {
                const result = applyFeedDelta(state.feed, delta, Date.now());
                state.feed = result.feed;
                persistFeed();
                renderFeed();
                drawDynamic();
                renderNav();
                return {applied: result.applied, rejected: result.rejected};
            },
            // For the gateway and for support: what the page currently holds.
            snapshot: () => JSON.parse(JSON.stringify(state.feed)),
        };
        root.voFieldFeed = feedApi;
        (pendingFeed.splice(0)).forEach(d => feedApi.apply(d));

        initMap();
        renderAll();
        startGps();
        startCompass();
        setInterval(() => { renderNav(); }, 1000);
        // Another tab on this origin (the real page, finishing a flush) changes the queue under us.
        window.addEventListener('storage', e => { if (e.key === QUEUE_KEY) renderAll(); });
        return feedApi;
    }

    // A delta handed over before init() has run (a gateway script that loaded
    // first) waits here instead of being lost.
    const pendingFeed = [];
    root.voFieldFeed = root.voFieldFeed || {apply: function (delta) { pendingFeed.push(delta); return {applied: null, rejected: [], pending: true}; }};
    root.VoFieldMode = {init: init};
})(typeof window !== 'undefined' ? window : globalThis);
