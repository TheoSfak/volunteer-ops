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
        renderRestrictedAreaLayer: (r) => calls.push(['renderRestrictedAreaLayer', r]),
        renderRestrictedAreasList: (r) => calls.push(['renderRestrictedAreasList', r]),
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

// ── General orders to people (v3.457.0) ─────────────────────────────────────────────────────────────────────────

const orderItem = (id, orderType, over = {}) => Object.assign({}, TEAM, {
    id, kind: 'order', type: 'order', order_type: orderType, label: 'Πηγαίνετε στο σημείο Α', geo: null,
}, over);
const field = (name, value) => ({target: {closest: (sel) => (sel === '[data-sc-o]' || sel === `[data-sc-o="${name}"]` ? {dataset: {scO: name}, value} : null)}});

test('an order to people shows its kind, its words and who it goes to, has no place on the map and no link to it', async () => {
    const {body, api, drawn} = await load(plan([
        orderItem(1, 'task'),
        orderItem(2, 'message', {audience: 'all', team_label: null, label: 'Μ'.repeat(90)}),
        orderItem(3, 'photo', {label: null}),
    ]));
    const html = body.innerHTML;
    assert.match(html, /scenario\.order_type_task/);
    assert.match(html, /scenario\.order_type_message/);
    assert.match(html, /scenario\.order_type_photo/);
    assert.match(html, /bi-clipboard-check-fill/);
    assert.match(html, /bi-chat-left-text-fill/);
    assert.match(html, /bi-camera-fill/);
    assert.match(html, /scenario\.target_all_people/, 'everybody on duty, not every team');
    assert.doesNotMatch(html, /common\.all_teams/);
    assert.doesNotMatch(html, /data-sc="goto"/, 'there is nowhere on the map to go');
    assert.match(html, /«Πηγαίνετε στο σημείο Α»/);
    assert.doesNotMatch(html, /Μ{80}/, 'long words are cut for the card');
    drawn.length = 0;
    api.drawGhostsInto({});
    assert.equal(drawn.length, 0, 'no ghost');
});

test('a message or an announcement is finished by being received, the others by being done, and the counts are people', async () => {
    const open = (type, extra = {}) => orderItem(1, type, Object.assign({sent: true, state: 'open', done_n: 1, of_n: 4}, extra));
    const text = async (item) => (await load(twoPhases({items: [item]}, {}))).body.innerHTML;

    assert.match(await text(open('message')), /scenario\.state_open_ack\{"done":1,"of":4\}/);
    assert.match(await text(open('speak')), /scenario\.state_open_ack/);
    assert.match(await text(open('task')), /scenario\.state_open_people\{"done":1,"of":4\}/);
    assert.match(await text(open('photo')), /scenario\.state_open_people/);
    assert.doesNotMatch(await text(open('task')), /state_open_broadcast/, 'not "teams"');
    assert.match(await text(open('message', {state: 'done'})), /scenario\.state_done_ack/);
    assert.match(await text(open('task', {state: 'done'})), /scenario\.state_done</);
});

test('an order under way cannot be withdrawn: the card says so and offers no button when only orders are open', async () => {
    const onlyOrders = await load(twoPhases({
        items: [orderItem(1, 'message', {sent: true, state: 'open', done_n: 0, of_n: 2})],
        progress: progress({open: 1, total: 1, withdrawable: 0, can_withdraw: false}),
    }, {}));
    assert.doesNotMatch(onlyOrders.body.innerHTML, /data-sc="withdraw"/);
    assert.match(onlyOrders.body.innerHTML, /data-sc-no-withdraw="1"/);
    assert.match(onlyOrders.body.innerHTML, /scenario\.order_no_withdraw/);

    // With a point as well, the button is there and its question counts only what it can take back.
    const mixed = await load(twoPhases({
        items: [orderItem(1, 'message', {sent: true, state: 'open', done_n: 0, of_n: 2}), sentItem(2, 'open')],
        progress: progress({open: 2, total: 2, withdrawable: 1, can_withdraw: true}),
    }, {}));
    assert.match(mixed.body.innerHTML, /data-sc="withdraw" data-phase="7"/);
    mixed.ctx.confirm = (t) => { mixed.calls.push(['confirm', t]); return false; };
    await clickOn(mixed.handlers, {sc: 'withdraw', phase: '7'});
    assert.match(mixed.calls.find((c) => c[0] === 'confirm')[1], /"n":1\}$/, 'one order can be taken back, not two');
});

test('the order form opens, keeps what is typed through a redraw, drops the words for a request, and sends what was filled in', async () => {
    const scenario = Object.assign(twoPhases({}, {}), {teams: [{id: 39, label: 'Alpha 1'}], slots: [{id: 3, label: 'Ομάδα Α', team_id: null, team_label: null}]});
    const {handlers, body, calls} = await load(scenario, {add_order: {ok: true, phase_id: 8}});
    assert.match(body.innerHTML, /data-sc="order-open"/);
    assert.doesNotMatch(body.innerHTML, /data-sc-orderform/);

    await clickOn(handlers, {sc: 'order-open'});
    assert.match(body.innerHTML, /data-sc-orderform="1"/);
    assert.match(body.innerHTML, /<textarea[^>]*data-sc-o="text"/, 'a task has words');
    assert.match(body.innerHTML, /<option value="all"[^>]*>\s*scenario\.target_all_people/);
    assert.match(body.innerHTML, /<option value="39"[^>]*>Alpha 1/);
    assert.match(body.innerHTML, /<option value="slot:3"[^>]*>Ομάδα Α/);
    assert.match(body.innerHTML, /<option value="8" selected>/, 'the first phase that has not gone');

    // Typed words survive anything that redraws the card (here: the choice of the audience).
    handlers.input({target: {closest: (sel) => (sel === '[data-sc-o="text"]' ? {value: 'Αναφορά στη βάση'} : null)}});
    await handlers.change(field('to', 'all'));
    await handlers.change(field('type', 'task'));
    assert.match(body.innerHTML, /Αναφορά στη βάση<\/textarea>/);
    assert.match(body.innerHTML, /<option value="all" selected>/);

    calls.length = 0;
    await clickOn(handlers, {sc: 'order-stage'});
    await settle();
    const post = calls.filter((c) => c[0] === 'fetch' && c[2]).pop();
    const sent = new URLSearchParams(post[2]);
    assert.equal(sent.get('action'), 'add_order');
    assert.equal(sent.get('phase_id'), '8');
    assert.equal(sent.get('order_type'), 'task');
    assert.equal(sent.get('text'), 'Αναφορά στη βάση');
    assert.equal(sent.get('team_id'), 'all');
    assert.match(body.innerHTML, /data-sc-order-note="1"/, 'it says where the order went');
    assert.match(body.innerHTML, /scenario\.order_staged\{"n":2\}/);
    assert.doesNotMatch(body.innerHTML, />Αναφορά στη βάση<\/textarea>/, 'the words are cleared for the next order');

    // A request has no words: the box goes away.
    await handlers.change(field('type', 'location'));
    assert.doesNotMatch(body.innerHTML, /data-sc-o="text"/);
    await clickOn(handlers, {sc: 'order-cancel'});
    assert.doesNotMatch(body.innerHTML, /data-sc-orderform/);
});

test('a refused order is shown to the person, keeps the form as it was, and asks for no phase it does not have', async () => {
    const refusal = {ok: false, error: 'Διάλεξε σε ποιους πηγαίνει η εντολή'};
    const {handlers, calls, body} = await load(plan([]), {add_order: refusal});
    await clickOn(handlers, {sc: 'order-open'});
    handlers.input({target: {closest: () => ({value: 'Κείμενο'})}});
    await clickOn(handlers, {sc: 'order-stage'});
    await settle();
    assert.deepEqual(calls.filter((c) => c[0] === 'alert').pop(), ['alert', refusal.error]);
    assert.match(body.innerHTML, /data-sc-orderform="1"/);
    assert.match(body.innerHTML, /Κείμενο<\/textarea>/, 'nothing typed is lost');
    const post = calls.filter((c) => c[0] === 'fetch' && c[2]).pop();
    assert.equal(new URLSearchParams(post[2]).get('phase_id'), '7', 'the phase the plan has');
});

test('typing in the order form is not torn down by the progress refresh', async () => {
    const {timers, doc, calls} = await load(twoPhases({items: [sentItem(1, 'open')]}, {}), {});
    doc.activeElement = {inCard: true, tagName: 'TEXTAREA'};
    calls.length = 0;
    timers[0]();
    await settle();
    assert.equal(calls.filter((c) => c[0] === 'fetch').length, 0, 'while someone types, the card does not even ask');
});

test('a new team slot can be made from the order form and is the one chosen afterwards', async () => {
    const scenario = Object.assign(twoPhases({}, {}), {teams: [], slots: [{id: 5, label: 'Ομάδα Α', team_id: null, team_label: null}]});
    const {handlers, body, ctx, calls} = await load(scenario, {add_slot: {ok: true, slot_id: 5}});
    await clickOn(handlers, {sc: 'order-open'});
    ctx.prompt = (question, suggestion) => { calls.push(['prompt', suggestion]); return 'Ομάδα Α'; };
    await handlers.change(field('to', 'slot:new'));
    await settle();
    await settle();
    const asked = calls.filter((c) => c[0] === 'fetch' && c[2]).pop();
    assert.equal(new URLSearchParams(asked[2]).get('action'), 'add_slot');
    assert.equal(new URLSearchParams(asked[2]).get('label'), 'Ομάδα Α');
    assert.match(body.innerHTML, /<option value="slot:5" selected>/, 'the slot just made is the audience');
});

test('declining the name of a new slot leaves the audience unchosen', async () => {
    const scenario = Object.assign(twoPhases({}, {}), {teams: [], slots: []});
    const {handlers, body, ctx} = await load(scenario, {});
    await clickOn(handlers, {sc: 'order-open'});
    ctx.prompt = () => null;
    await handlers.change(field('to', 'slot:new'));
    await settle();
    await settle();
    assert.doesNotMatch(body.innerHTML, /<option value="(all|slot:[0-9]+)" selected>/);
    assert.doesNotMatch(body.innerHTML, /<option value="slot:new" selected>/);
});

// ── Restricted areas (v3.458.0) ───────────────────────────────────────────────────────────────────────────────────
// A zone is for everybody: audience 'none', no team, never "finished" (it stands), and the real one is bright red where
// the staged one is a dull dashed ghost.
const zone = (id, over = {}) => Object.assign({
    id, kind: 'restricted', type: 'restricted', audience: 'none', team_id: null, team_label: null, team_missing: false,
    slot_id: null, slot_label: null, unmapped: false, team_empty: false, area_missing: false, sent: false, error: null,
    label: 'Ρέμα', restricted_kind: 'danger', shape: 'polygon', geo: [[35.0, 24.0], [35.1, 24.0], [35.1, 24.1]], state: null,
}, over);
const zonePhase = (items, played, prog) => ({
    phases: [{id: 7, n: 1, title: null, played_at: played ? '2026-11-07 10:00:00' : null, played_label: '10:00', played_by_name: 'Admin', items, progress: played ? prog : null}],
    slots: [], teams: [],
});
const standing = (over = {}) => Object.assign({total: 0, done: 0, open: 0, standing: 1, withdrawn: 0, failed: 0, complete: false, withdrawable: 1, can_withdraw: true}, over);

test('a staged zone is a dull dashed polygon, or a dashed line for an edge, with the phase number; a made one leaves no ghost', async () => {
    const {api, drawn} = await load(zonePhase([
        zone(1),
        zone(2, {shape: 'line', restricted_kind: 'cliff', geo: [[35.0, 24.0], [35.2, 24.2]]}),
        zone(3, {sent: true, state: 'standing'}),
        zone(4, {geo: [[35.0, 24.0], [35.1, 24.0]]}),
    ], false));
    drawn.length = 0;
    api.drawGhostsInto({name: 'layer'});

    const polygons = drawn.filter((d) => d.kind === 'polygon');
    const lines = drawn.filter((d) => d.kind === 'polyline');
    assert.equal(polygons.length, 1, 'only the zone with three points is a polygon; one already made is not drawn again');
    assert.equal(polygons[0].args[1].dashArray, '4 5');
    assert.equal(polygons[0].args[1].color, '#a05a5a', 'dull, not the bright red of the real zone');
    assert.equal(lines.length, 1);
    assert.deepEqual(lines[0].args[0], [[35.0, 24.0], [35.2, 24.2]]);
    assert.equal(lines[0].args[1].dashArray, '4 5');
    assert.equal(drawn.filter((d) => d.kind === 'marker').length, 2, 'one phase number each');
});

test('the card says what a zone is, that it is for everybody, and that it stands once made - never "team gone"', async () => {
    const staged = await load(zonePhase([zone(1), zone(2, {shape: 'line', restricted_kind: 'cliff'})], false));
    assert.match(staged.body.innerHTML, /scenario\.kind_restricted/);
    assert.match(staged.body.innerHTML, /bi-exclamation-triangle-fill/);
    assert.match(staged.body.innerHTML, /scenario\.target_everyone/);
    assert.match(staged.body.innerHTML, /restricted_area\.kind_danger · restricted_area\.shape_polygon/);
    assert.match(staged.body.innerHTML, /restricted_area\.kind_cliff · restricted_area\.shape_line/);
    assert.doesNotMatch(staged.body.innerHTML, /scenario\.team_gone/);
    assert.match(staged.body.innerHTML, /data-sc="goto"/, 'a staged zone has a place to go to');
    assert.match(staged.body.innerHTML, /data-sc="delete-item"/);

    const made = await load(zonePhase([zone(1, {sent: true, state: 'standing'})], true, standing()));
    assert.match(made.body.innerHTML, /scenario\.state_standing/);
    assert.doesNotMatch(made.body.innerHTML, /scenario\.state_open/);
    assert.doesNotMatch(made.body.innerHTML, /data-sc="delete-item"/, 'a made zone is taken away by withdrawing the phase');
    assert.doesNotMatch(made.body.innerHTML, /progress_badge/, 'a zone is not an order to finish: no 0/0 badge');
});

test('the restricted-area window stages a zone through add_restricted, with no team in it', async () => {
    const {api, calls} = await load(plan([]), {add_restricted: {ok: true, phase_id: 7}});
    const res = await api.stageRestricted('new', {label: 'Ρέμα', kind: 'cliff', shape: 'line', geo: '[[35,24],[35.1,24.1]]'});

    assert.equal(res.ok, true);
    const fields = new URLSearchParams(calls.filter((c) => c[0] === 'fetch' && c[2]).pop()[2]);
    assert.equal(fields.get('action'), 'add_restricted');
    assert.equal(fields.get('phase_id'), 'new');
    assert.equal(fields.get('label'), 'Ρέμα');
    assert.equal(fields.get('kind'), 'cliff');
    assert.equal(fields.get('shape'), 'line');
    assert.equal(fields.get('geo'), '[[35,24],[35.1,24.1]]');
    assert.equal(fields.get('mission_id'), '83');
    assert.equal(fields.has('team_id'), false);
});

test('play, retry and withdrawing put the zones on the map and in the list at once', async () => {
    const live = [{id: 17, label: 'Ρέμα'}];
    for (const [action, click, answer] of [
        ['play_phase', {sc: 'play', phase: '7'}, {ok: true, sent: 1, failed: 0, results: [], restricted_areas: live}],
        ['retry_item', {sc: 'retry', item: '1'}, {ok: true, item: {ok: true, kind: 'restricted'}, restricted_areas: live}],
        ['withdraw_phase', {sc: 'withdraw', phase: '7'}, {ok: true, withdrawn: 1, failed: 0, results: [], restricted_areas: live}],
    ]) {
        const scenario = action === 'play_phase' ? zonePhase([zone(1)], false) : zonePhase([zone(1, action === 'retry_item' ? {error: 'x'} : {sent: true, state: 'standing'})], true, standing());
        const {handlers, calls, ctx} = await load(scenario, {[action]: answer});
        calls.length = 0;
        await clickOn(handlers, click);
        await settle();
        assert.deepEqual(ctx.restrictedAreas, live, action);
        assert.ok(calls.some((c) => c[0] === 'renderRestrictedAreaLayer' && c[1] === live), action + ' redraws the map layer');
        assert.ok(calls.some((c) => c[0] === 'renderRestrictedAreasList' && c[1] === live), action + ' redraws the list');
    }
});

test('an answer without zones leaves the page\'s zones alone', async () => {
    const {handlers, calls, ctx} = await load(zonePhase([zone(1)], false), {play_phase: {ok: true, sent: 1, failed: 0, results: []}});
    ctx.restrictedAreas = [{id: 5}];
    calls.length = 0;
    await clickOn(handlers, {sc: 'play', phase: '7'});
    await settle();
    assert.deepEqual(ctx.restrictedAreas, [{id: 5}]);
    assert.equal(calls.filter((c) => c[0] === 'renderRestrictedAreaLayer').length, 0);
});

test('withdrawing says plainly that the phase\'s zones come off the map, and only when there are any', async () => {
    const ask = async (prog, items) => {
        const {handlers, calls, ctx} = await load(zonePhase(items, true, prog), {withdraw_phase: {ok: true, withdrawn: 1, failed: 0, results: []}});
        ctx.confirm = (text) => { calls.push(['confirm', text]); return true; };
        await clickOn(handlers, {sc: 'withdraw', phase: '7'});
        await settle();
        return calls.find((c) => c[0] === 'confirm')[1];
    };
    const withZone = await ask(standing(), [zone(1, {sent: true, state: 'standing'})]);
    assert.match(withZone, /scenario\.withdraw_confirm_zones/);
    assert.match(withZone, /"n":1\}/, 'the zone counts among what is taken back');
    const without = await ask({total: 2, done: 1, open: 1, standing: 0, withdrawn: 0, failed: 0, complete: false, withdrawable: 1, can_withdraw: true}, [sentItem(1, 'open')]);
    assert.doesNotMatch(without, /withdraw_confirm_zones/);
});

test('the zones warning comes before the question that closes the withdraw confirmation', async () => {
    const {handlers, calls, ctx} = await load(zonePhase([zone(1, {sent: true, state: 'standing'})], true, standing()), {withdraw_phase: {ok: true, withdrawn: 1, failed: 0, results: []}});
    const plain = ctx.t;
    ctx.t = (key, vars) => (key === 'scenario.withdraw_confirm' ? 'HEAD\n\nTHE QUESTION?' : plain(key, vars));
    ctx.confirm = (text) => { calls.push(['confirm', text]); return true; };
    await clickOn(handlers, {sc: 'withdraw', phase: '7'});
    await settle();
    assert.equal(calls.find((c) => c[0] === 'confirm')[1], 'HEAD\n\nscenario.withdraw_confirm_zones\n\nTHE QUESTION?');
});

test('the play confirmation says a zone is for everybody, never an empty «προς:»', async () => {
    const ask = async (items) => {
        const {handlers, calls, ctx} = await load(zonePhase(items, false), {play_phase: {ok: true, sent: 1, failed: 0, results: []}});
        ctx.confirm = (text) => { calls.push(['confirm', text]); return true; };
        await clickOn(handlers, {sc: 'play', phase: '7'});
        await settle();
        return calls.find((c) => c[0] === 'confirm')[1];
    };
    const only = await ask([zone(1)]);
    assert.match(only, /"orders":1,"targets":"scenario\.target_everyone"\}/);
    assert.doesNotMatch(only, /common\.all_teams|scenario\.target_all\b/, 'not "every team": a zone is not an order to teams');
    const mixed = await ask([zone(1), zone(3), Object.assign({}, TEAM, {id: 2, kind: 'dispatch', type: 'point', label: 'Σ', geo: {lat: 35, lng: 24}})]);
    assert.match(mixed, /"orders":3,"targets":"scenario\.target_everyone, Alpha 1"\}/, 'each name once');
});
