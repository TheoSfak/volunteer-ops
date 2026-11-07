// Run with: node --test tests/js
//
// assets/js/war-room-scenario.js (the «Σενάριο» card, its ghosts on the map and the way play hands the orders it
// has just sent back to the page), loaded into a vm context with only the page globals it reaches for faked. The
// PHP side is tests/ScenarioTest.php; this is the half only a script can show: what a staged ROUTE looks like on the
// map and in the card, and that pressing play puts the routes it sent on the page at once (v3.455.0).

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const SOURCE = fs.readFileSync(path.join(__dirname, '../../assets/js/war-room-scenario.js'), 'utf8');

/** A Leaflet that only remembers what it was asked to draw. */
function fakeLeaflet(drawn) {
    const shape = (kind) => (...args) => {
        const entry = {kind, args};
        drawn.push(entry);
        const chain = {bindTooltip: () => chain, addTo: (layer) => { entry.layer = layer; return chain; }, getBounds: () => ({getCenter: () => [0, 0]})};
        return chain;
    };
    return {
        divIcon: (opts) => ({html: opts.html}),
        marker: shape('marker'),
        polyline: shape('polyline'),
        polygon: shape('polygon'),
        circleMarker: shape('circleMarker'),
        layerGroup: () => ({addTo: () => ({clearLayers() {}})}),
        latLngBounds: (pts) => pts,
    };
}

/**
 * Loads the script with a plan the endpoint "answers" with. Returns {api, drawn, body, calls, ctx}: the script's
 * window.wrScenario, what it drew, the card's fake element, the requests it made and the context (for the page
 * globals it assigns).
 */
async function load(scenario, answers = {}) {
    const drawn = [];
    const calls = [];
    const handlers = {};
    const body = {innerHTML: '', addEventListener: (type, fn) => { handlers[type] = fn; }};
    const win = {};
    const timers = [];
    const storage = {};
    const doc = {
        getElementById: (id) => (id === 'scenarioBody' ? body : null),
        createElement: () => ({}),
        head: {appendChild() {}},
        addEventListener() {},
        dispatchEvent() {},
        hidden: false,
        activeElement: null,
    };
    body.contains = (el) => !!el && el.inCard === true;
    const ctx = vm.createContext({
        window: win,
        document: doc,
        setInterval: (fn) => { timers.push(fn); return timers.length; },
        localStorage: {getItem: (k) => (k in storage ? storage[k] : null), setItem: (k, v) => { storage[k] = String(v); }},
        CustomEvent: class { constructor(type) { this.type = type; } },
        URLSearchParams,
        L: fakeLeaflet(drawn),
        csrfToken: 'tok',
        WR_MISSION_ID: 83,
        t: (key, vars) => key + (vars ? JSON.stringify(vars) : ''),
        escapeHtml: (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;'),
        confirm: () => true,
        alert: (m) => calls.push(['alert', m]),
        prompt: () => null,
        renderDispatches: () => calls.push(['renderDispatches']),
        renderMyRoutes: (r) => calls.push(['renderMyRoutes', r]),
        renderRoutesAdmin: (r) => calls.push(['renderRoutesAdmin', r]),
        renderRouteLayer: (r) => calls.push(['renderRouteLayer', r]),
        fetch: async (url, opts) => {
            calls.push(['fetch', String(url), opts && opts.body ? String(opts.body) : null]);
            const action = opts && opts.body ? new URLSearchParams(String(opts.body)).get('action') : 'get';
            const payload = answers[action] || {ok: true};
            return {json: async () => Object.assign({scenario}, payload)};
        },
    });
    vm.runInContext(SOURCE, ctx);
    win.wrScenario.init();
    await new Promise((resolve) => setImmediate(resolve));
    return {api: win.wrScenario, drawn, body, calls, ctx, handlers, timers, storage, doc};
}

const TEAM = {audience: 'team', team_id: 39, team_label: 'Alpha 1', team_missing: false, slot_id: null, slot_label: null, unmapped: false, team_empty: false, area_missing: false, sent: false, error: null};
const route = (id, over = {}) => Object.assign({}, TEAM, {
    id, kind: 'route', type: 'route', label: 'Περίπολος', point_count: 3, closed_loop: false, return_to_start: false,
    geo: [[35.0, 24.0], [35.1, 24.1], [35.2, 24.2]],
}, over);
const plan = (items, played = false) => ({
    phases: [{id: 7, n: 2, title: null, played_at: played ? '2026-11-07 10:00:00' : null, played_label: null, played_by_name: null, items}],
    slots: [], teams: [],
});

test('a staged route is a dashed line through its waypoints with a dot at each and the phase number where it starts', async () => {
    const {api, drawn} = await load(plan([route(1)]));
    drawn.length = 0;
    const layer = {name: 'layer'};
    api.drawGhostsInto(layer);

    const lines = drawn.filter((d) => d.kind === 'polyline');
    assert.equal(lines.length, 1);
    assert.deepEqual(lines[0].args[0], [[35.0, 24.0], [35.1, 24.1], [35.2, 24.2]]);
    assert.equal(lines[0].args[1].dashArray, '7 6', 'dashed, like every ghost');
    assert.equal(lines[0].layer, layer);
    assert.equal(drawn.filter((d) => d.kind === 'circleMarker').length, 3);
    const pins = drawn.filter((d) => d.kind === 'marker');
    assert.equal(pins.length, 1);
    assert.deepEqual(pins[0].args[0], [35.0, 24.0], 'at the first waypoint');
    assert.match(pins[0].args[1].icon.html, /<span>2<\/span>/, 'the phase number');
    assert.equal(drawn.filter((d) => d.kind === 'polygon').length, 0, 'a route is not drawn as an area');
});

test('a route that is a loop is drawn closed, and one with a single point is only a dot and a number', async () => {
    const {api, drawn} = await load(plan([route(1, {closed_loop: true}), route(2, {geo: [[35.5, 24.5]], point_count: 1})]));
    drawn.length = 0;
    api.drawGhostsInto({});

    const polygons = drawn.filter((d) => d.kind === 'polygon');
    assert.equal(polygons.length, 1);
    assert.equal(polygons[0].args[1].fill, false, 'a loop is an outline, not an area');
    assert.equal(drawn.filter((d) => d.kind === 'polyline').length, 0, 'the single point has no line, the loop is a polygon');
    assert.equal(drawn.filter((d) => d.kind === 'circleMarker').length, 4);
    assert.equal(drawn.filter((d) => d.kind === 'marker').length, 2);
});

test('a route that has been sent, or has no waypoints, leaves no ghost', async () => {
    const {api, drawn} = await load(plan([route(1, {sent: true}), route(2, {geo: null}), route(3, {geo: []})]));
    drawn.length = 0;
    api.drawGhostsInto({});
    assert.equal(drawn.length, 0);
});

test('the card says what a route is, how many points it has, and what will stop it', async () => {
    const {body} = await load(plan([
        route(1, {return_to_start: true}),
        route(2, {team_empty: true, label: 'Χωρίς άτομα'}),
        route(3, {audience: 'slot', slot_id: 4, slot_label: 'Ομάδα Α', team_label: null, unmapped: true}),
    ]));

    const html = body.innerHTML;
    assert.match(html, /bi-signpost-split-fill/, 'its own icon');
    assert.match(html, /scenario\.kind_route/);
    assert.match(html, /scenario\.route_points\{"n":3\}/);
    assert.match(html, /scenario\.route_return_hint/, 'a route that comes back to the start says so');
    assert.match(html, /scenario\.team_empty_hint/, 'a team with nobody in the Action Room is told in red beforehand');
    assert.match(html, /scenario\.slot_unmapped_hint/);
    assert.match(html, /data-sc="goto" data-item="1"/);
});

test('the route window stages a route through add_route, with its fields as the window posted them', async () => {
    const {api, calls} = await load(plan([]), {add_route: {ok: true, phase_id: 7}});
    const res = await api.stageRoute('new', {team_id: 'slot:3', title: 'Π', waypoints: '[{"lat":35,"lng":24}]', is_closed_loop: '0', add_return_waypoint: '1', member_ids: '[]', travel_mode: 'foot'});

    assert.equal(res.ok, true);
    const post = calls.filter((c) => c[0] === 'fetch' && c[2]).pop();
    const fields = new URLSearchParams(post[2]);
    assert.equal(fields.get('action'), 'add_route');
    assert.equal(fields.get('phase_id'), 'new');
    assert.equal(fields.get('mission_id'), '83');
    assert.equal(fields.get('team_id'), 'slot:3');
    assert.equal(fields.get('add_return_waypoint'), '1');
    assert.equal(fields.get('csrf_token'), 'tok');
});

test('play and retry put the routes they sent on the page at once', async () => {
    const live = [{id: 91, team_id: 39}];
    const {handlers, calls, ctx} = await load(plan([route(1)]), {
        play_phase: {ok: true, sent: 1, failed: 0, results: [], routes: live},
        retry_item: {ok: true, item: {ok: true, kind: 'route'}, routes: live},
    });
    const click = (data) => handlers.click({target: {closest: () => ({dataset: data, disabled: false})}, preventDefault() {}});

    calls.length = 0;
    await click({sc: 'play', phase: '7'});
    await new Promise((resolve) => setImmediate(resolve));
    assert.deepEqual(ctx.routes, live, 'the page\'s own list of routes is replaced');
    assert.ok(calls.some((c) => c[0] === 'renderRouteLayer' && c[1] === live), 'and drawn');
    assert.ok(calls.some((c) => c[0] === 'renderMyRoutes'));
    assert.ok(calls.some((c) => c[0] === 'renderRoutesAdmin'));

    // A play that sent no route hands back none, and the page's list is left alone.
    ctx.routes = 'untouched';
    calls.length = 0;
    const second = await load(plan([route(1)]), {play_phase: {ok: true, sent: 1, failed: 0, results: []}});
    second.ctx.routes = 'untouched';
    await second.handlers.click({target: {closest: () => ({dataset: {sc: 'play', phase: '7'}, disabled: false})}, preventDefault() {}});
    await new Promise((resolve) => setImmediate(resolve));
    assert.equal(second.ctx.routes, 'untouched');
});

// ── Progress, the note about the next phase, and taking a phase back (v3.456.0) ──────────────────────────────────

const progress = (over = {}) => Object.assign({total: 6, done: 4, open: 2, withdrawn: 0, failed: 0, complete: false, can_withdraw: true}, over);
const sentItem = (id, state, over = {}) => Object.assign({}, TEAM, {id, kind: 'dispatch', type: 'point', label: 'Σημείο ' + id, geo: {lat: 35, lng: 24}, sent: true, state}, over);
const twoPhases = (first, second) => ({
    phases: [
        Object.assign({id: 7, n: 1, title: null, played_at: '2026-11-07 10:00:00', played_label: '10:00', played_by_name: 'Admin', items: [], progress: progress()}, first),
        Object.assign({id: 8, n: 2, title: null, played_at: null, played_label: null, played_by_name: null, items: [], progress: null}, second),
    ],
    slots: [], teams: [],
});
const clickOn = (handlers, data) => handlers.click({target: {closest: () => ({dataset: data, disabled: false})}, preventDefault() {}});
const settle = () => new Promise((resolve) => setImmediate(resolve));

test('a played phase shows how many of its orders are finished, and each order says where it stands', async () => {
    const {body} = await load(twoPhases({items: [
        sentItem(1, 'done'), sentItem(2, 'open'), sentItem(3, 'withdrawn'),
        sentItem(4, 'open', {audience: 'all', team_label: null, done_n: 2, of_n: 3}),
    ]}, {}));

    const html = body.innerHTML;
    assert.match(html, /scenario\.progress_badge\{"done":4,"total":6\}/, 'the badge next to the phase');
    assert.match(html, /bg-secondary" title="scenario\.progress_title/, 'grey while something is still open');
    assert.match(html, /scenario\.state_done/);
    assert.match(html, /scenario\.state_open</);
    assert.match(html, /scenario\.state_withdrawn/);
    assert.match(html, /scenario\.state_open_broadcast\{"done":2,"of":3\}/, 'an order to every team says how many teams have finished');
    assert.match(html, /wr-sc-withdrawn/, 'a withdrawn order is dimmed');
});

test('the badge turns green when every order that still counts is finished, and says withdrawn when all were taken back', async () => {
    const done = await load(twoPhases({progress: progress({done: 6, open: 0, complete: true, can_withdraw: false})}, {}));
    assert.match(done.body.innerHTML, /bg-success" title="scenario\.progress_title/);
    assert.doesNotMatch(done.body.innerHTML, /data-sc="withdraw"/, 'nothing left to take back, no button');

    const gone = await load(twoPhases({progress: progress({total: 0, done: 0, open: 0, withdrawn: 6, can_withdraw: false})}, {}));
    assert.doesNotMatch(gone.body.innerHTML, /progress_badge/);
    assert.match(gone.body.innerHTML, /badge bg-secondary">scenario\.state_withdrawn/);
});

test('«Ανάκληση φάσης» is offered while something is under way, asks first, and drops the withdrawn orders from the page', async () => {
    const live = {dispatches: [{id: 1}], routes: [{id: 5}], sectors: [{id: 9}], areas: [{id: 2}]};
    const res = Object.assign({ok: true, withdrawn: 2, failed: 0, results: []}, live);
    const {handlers, calls, ctx, body} = await load(twoPhases({items: [sentItem(1, 'open')]}, {}), {withdraw_phase: res});
    assert.match(body.innerHTML, /data-sc="withdraw" data-phase="7"/);

    // Said no: nothing is asked of the server.
    ctx.confirm = () => false;
    calls.length = 0;
    await clickOn(handlers, {sc: 'withdraw', phase: '7'});
    assert.equal(calls.filter((c) => c[0] === 'fetch').length, 0);

    ctx.confirm = (text) => { calls.push(['confirm', text]); return true; };
    ctx.sectorRefreshAfter = (sectors, areas) => calls.push(['sectors', sectors, areas]);
    await clickOn(handlers, {sc: 'withdraw', phase: '7'});
    await settle();
    const asked = calls.find((c) => c[0] === 'confirm')[1];
    assert.match(asked, /^scenario\.withdraw_confirm/);
    assert.match(asked, /"n":2\}$/, 'the number of orders that will be taken back');
    const post = calls.filter((c) => c[0] === 'fetch' && c[2]).pop();
    assert.equal(new URLSearchParams(post[2]).get('action'), 'withdraw_phase');
    assert.equal(new URLSearchParams(post[2]).get('phase_id'), '7');
    assert.deepEqual(ctx.dispatches, live.dispatches);
    assert.deepEqual(ctx.routes, live.routes);
    assert.ok(calls.some((c) => c[0] === 'renderRouteLayer'));
    assert.ok(calls.some((c) => c[0] === 'sectors' && c[1] === live.sectors && c[2] === live.areas));
});

test('a withdrawal that did not take everything back says which orders stayed', async () => {
    const res = {ok: true, withdrawn: 1, failed: 1, results: [{ok: true}, {ok: false, error: 'Η εντολή δεν ανακλήθηκε.'}]};
    const {handlers, calls} = await load(twoPhases({items: [sentItem(1, 'open')]}, {}), {withdraw_phase: res});
    await clickOn(handlers, {sc: 'withdraw', phase: '7'});
    await settle();
    const alerted = calls.find((c) => c[0] === 'alert');
    assert.ok(alerted);
    assert.match(alerted[1], /withdraw_partial/);
    assert.match(alerted[1], /Η εντολή δεν ανακλήθηκε\./);
});

test('the note about the next phase appears only after a finished phase, only on a phase not yet played, and can be switched off', async () => {
    const finished = {progress: progress({done: 6, open: 0, complete: true, can_withdraw: false})};
    const on = await load(twoPhases(finished, {}));
    assert.match(on.body.innerHTML, /data-sc-ready="1"/);
    assert.match(on.body.innerHTML, /scenario\.hint_ready/);
    assert.match(on.body.innerHTML, /wr-sc-ready/);
    assert.match(on.body.innerHTML, /data-sc="play" data-phase="8"/, 'the note adds no button: the phase is still sent by its own play');

    const notYet = await load(twoPhases({}, {}));
    assert.doesNotMatch(notYet.body.innerHTML, /data-sc-ready/, 'the first phase is still under way');

    const played = await load(twoPhases(finished, {played_at: '2026-11-07 11:00:00', progress: progress({done: 1, total: 1, open: 0, complete: true, can_withdraw: false})}));
    assert.doesNotMatch(played.body.innerHTML, /data-sc-ready/, 'a phase that has gone needs no note');

    const off = await load(twoPhases(finished, {}));
    off.storage.wrScenarioHint = '0';
    await off.api.reload();
    assert.doesNotMatch(off.body.innerHTML, /data-sc-ready/, 'switched off for this browser');
});

test('switching the note off is remembered and sends nothing', async () => {
    const finished = {progress: progress({done: 6, open: 0, complete: true, can_withdraw: false})};
    const {handlers, storage, calls, body} = await load(twoPhases(finished, {}));
    assert.match(body.innerHTML, /data-sc-hint="1" checked/);
    calls.length = 0;
    handlers.change({target: {closest: (sel) => (sel === 'input[data-sc-hint]' ? {checked: false} : null)}});
    assert.equal(storage.wrScenarioHint, '0');
    assert.doesNotMatch(body.innerHTML, /data-sc-ready/);
    assert.equal(calls.filter((c) => c[0] === 'fetch').length, 0, 'a note is not an order');
});

test('the card asks again for progress only while something is under way, the tab is in front and nobody is choosing in it', async () => {
    const {timers, calls, doc} = await load(twoPhases({items: [sentItem(1, 'open')]}, {}));
    assert.equal(timers.length, 1);
    const gets = (c) => c.filter((x) => x[0] === 'fetch' && !x[2]).length;
    const base = gets(calls);

    timers[0]();
    await settle();
    assert.equal(gets(calls), base + 1, 'something is under way: it asks');

    doc.hidden = true;
    timers[0]();
    assert.equal(gets(calls), base + 1, 'not while the tab is hidden');
    doc.hidden = false;

    doc.activeElement = {tagName: 'SELECT', inCard: true};
    timers[0]();
    assert.equal(gets(calls), base + 1, 'not while a select of the card is in use');
    doc.activeElement = null;

    // Nothing under way: finished and withdrawn phases are not worth asking about.
    const idle = await load(twoPhases({progress: progress({done: 6, open: 0, complete: true, can_withdraw: false})}, {}));
    const idleBase = gets(idle.calls);
    idle.timers[0]();
    await settle();
    assert.equal(gets(idle.calls), idleBase);
});

test('an answer that is the same as the card already shows does not redraw it', async () => {
    const {timers, body, ctx} = await load(twoPhases({items: [sentItem(1, 'open')]}, {}));
    let redraws = 0;
    let html = body.innerHTML;
    Object.defineProperty(body, 'innerHTML', {get: () => html, set: (v) => { redraws++; html = v; }});
    timers[0]();
    await settle();
    assert.equal(redraws, 0, 'a select or a typed name in the card survives the refresh');
    void ctx;
});
