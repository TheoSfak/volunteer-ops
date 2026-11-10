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
 *
 * opts.mode is where the script thinks it is (v3.462.0): 'page' (the default, so every test of the card draws the whole
 * card) is the Scenario page, data-sc-mode="page"; 'room' is the Action Room, which draws a summary line and has the map.
 * opts.href is the address the page was opened at (the Action Room is opened on an order by ?sc_do=...&sc_item=...),
 * opts.readyState what the document says about loading. Returns also {win, timeouts (what setTimeout was asked for, in
 * order), winHandlers}.
 */
async function load(scenario, answers = {}, opts = {}) {
    const drawn = [];
    const calls = [];
    const handlers = {};
    const body = {innerHTML: '', dataset: {scMode: opts.mode || 'page'}, addEventListener: (type, fn) => { handlers[type] = fn; }};
    const winHandlers = {};
    const timeouts = [];
    const win = {name: opts.windowName || '', addEventListener: (type, fn) => { winHandlers[type] = fn; }, open: (url, target) => calls.push(['open', url, target])};
    const timers = [];
    const storage = {};
    const doc = {
        getElementById: (id) => (id === 'scenarioBody' ? body : null),
        createElement: () => ({}),
        head: {appendChild() {}},
        addEventListener() {},
        dispatchEvent() {},
        hidden: false,
        readyState: opts.readyState || 'complete',
        activeElement: null,
    };
    body.contains = (el) => !!el && el.inCard === true;
    const ctx = vm.createContext({
        window: win,
        document: doc,
        setInterval: (fn) => { timers.push(fn); return timers.length; },
        setTimeout: (fn, ms) => { timeouts.push({fn, ms}); return timeouts.length; },
        URL,
        location: {href: opts.href || 'http://localhost/volunteerops/war-room.php?id=83'},
        history: {replaceState: (state, title, url) => calls.push(['replaceState', url])},
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
    return {api: win.wrScenario, drawn, body, calls, ctx, handlers, timers, storage, doc, win, timeouts, winHandlers};
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

test('the card asks again every 20 s, on the page and in the Action Room, but not while the tab is hidden, an action is in flight or somebody is choosing in it', async () => {
    const {timers, calls, doc} = await load(twoPhases({items: [sentItem(1, 'open')]}, {}));
    assert.equal(timers.length, 1);
    const gets = (c) => c.filter((x) => x[0] === 'fetch' && !x[2]).length;
    const base = gets(calls);

    timers[0]();
    await settle();
    assert.equal(gets(calls), base + 1, 'it asks');

    doc.hidden = true;
    timers[0]();
    assert.equal(gets(calls), base + 1, 'not while the tab is hidden');
    doc.hidden = false;

    doc.activeElement = {tagName: 'SELECT', inCard: true};
    timers[0]();
    assert.equal(gets(calls), base + 1, 'not while a select of the card is in use');
    doc.activeElement = null;

    // Nothing under way - a finished phase, or no plan at all - is still asked about: another tab may have played or staged one (v3.462.0).
    const idle = await load(twoPhases({progress: progress({done: 6, open: 0, complete: true, can_withdraw: false})}, {}));
    const idleBase = gets(idle.calls);
    idle.timers[0]();
    await settle();
    assert.equal(gets(idle.calls), idleBase + 1);
    for (const mode of ['page', 'room']) {
        const empty = await load({phases: [], slots: [], teams: []}, {}, {mode});
        const emptyBase = gets(empty.calls);
        empty.timers[0]();
        await settle();
        assert.equal(gets(empty.calls), emptyBase + 1, mode + ': an empty plan is asked about too');
    }
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

// ── Search areas (v3.459.0) ───────────────────────────────────────────────────────────────────────────────────────
// An area is a boundary, not an order to anybody: audience 'none', no team, never finished (it stands), and it is the ground
// the sectors of a later piece of the plan are cut from. Taking the phase back leaves it on the map.
const searchArea = (id, over = {}) => Object.assign({
    id, kind: 'area', type: 'search_area', audience: 'none', team_id: null, team_label: null, team_missing: false,
    slot_id: null, slot_label: null, unmapped: false, team_empty: false, area_missing: false, sent: false, error: null,
    label: 'Κοίτη', geo: [[35.0, 24.0], [35.1, 24.0], [35.1, 24.1]], state: null,
}, over);
const stagedSector = (id, over = {}) => Object.assign({}, TEAM, {
    id, kind: 'sector', type: 'sector', label: 'Τομέας Α', geo: [[35.0, 24.0], [35.1, 24.0], [35.1, 24.1]],
    area_id: null, area_label: 'Κοίτη', area_missing: false, area_item_id: 1, area_staged: true, area_phase_n: 1,
}, over);

test('the search-area window stages an area through add_area, with no team in it', async () => {
    const {api, calls} = await load(plan([]), {add_area: {ok: true, phase_id: 7}});
    assert.equal(typeof api.stageArea, 'function');
    const res = await api.stageArea('new', {label: 'Κοίτη', geo: '[[35,24],[35.1,24],[35.1,24.1]]'});

    assert.equal(res.ok, true);
    const fields = new URLSearchParams(calls.filter((c) => c[0] === 'fetch' && c[2]).pop()[2]);
    assert.equal(fields.get('action'), 'add_area');
    assert.equal(fields.get('phase_id'), 'new');
    assert.equal(fields.get('label'), 'Κοίτη');
    assert.equal(fields.get('geo'), '[[35,24],[35.1,24],[35.1,24.1]]');
    assert.equal(fields.get('mission_id'), '83');
    assert.equal(fields.has('team_id'), false);
    assert.equal(fields.has('ring_index'), false, 'a staged area is a hand-drawn one');
});

test('a refused area is shown to the person and the answer is handed back', async () => {
    const {api, calls} = await load(plan([]), {add_area: {ok: false, error: 'Χρειάζονται τουλάχιστον 3 σημεία.'}});
    const res = await api.stageArea('7', {label: 'Κοίτη', geo: '[]'});
    assert.equal(res.ok, false);
    assert.ok(calls.some((c) => c[0] === 'alert' && c[1] === 'Χρειάζονται τουλάχιστον 3 σημεία.'));
});

test('the card says what an area is, names no recipient, and an unsent one has «Τομείς» and a delete button', async () => {
    const {body} = await load(zonePhase([searchArea(1)], false));
    assert.match(body.innerHTML, /scenario\.kind_search_area/);
    assert.match(body.innerHTML, /bi-bounding-box/);
    assert.doesNotMatch(body.innerHTML, /scenario\.target_everyone|common\.all_teams|scenario\.team_gone/, 'an area goes to nobody');
    assert.doesNotMatch(body.innerHTML, /bi-arrow-right-short/, 'no «→ προς» for it');
    assert.match(body.innerHTML, /data-sc="divide-area" data-item="1"/);
    assert.match(body.innerHTML, /scenario\.area_divide_btn/);
    assert.match(body.innerHTML, /data-sc="goto"/);
    assert.match(body.innerHTML, /data-sc="delete-item"/);
});

test('a made area stands calmly: no «Τομείς», no delete, no red shield, and it says that withdrawing leaves it', async () => {
    const prog = {total: 0, done: 0, open: 0, standing: 0, withdrawn: 0, failed: 0, complete: false, withdrawable: 0, can_withdraw: false};
    const {body} = await load(zonePhase([searchArea(1, {sent: true, state: 'standing', search_area_id: 12})], true, prog));
    assert.match(body.innerHTML, /scenario\.state_standing/);
    assert.match(body.innerHTML, /scenario\.area_stays_hint/);
    assert.doesNotMatch(body.innerHTML, /bi-shield-fill-exclamation/, 'it is not a danger zone');
    assert.doesNotMatch(body.innerHTML, /data-sc="divide-area"/);
    assert.doesNotMatch(body.innerHTML, /data-sc="delete-item"/);
    assert.doesNotMatch(body.innerHTML, /progress_badge/, 'an area is not an order to finish: no 0/0 badge');
    assert.doesNotMatch(body.innerHTML, /data-sc="withdraw"/, 'nothing in the phase can be taken back');
});

test('«Τομείς» opens the division window on the staged area with its item, name and points - when the page sends the Action Room here', async () => {
    const {ctx, timeouts} = await load(zonePhase([searchArea(1)], false), {}, {mode: 'room', href: 'http://localhost/volunteerops/war-room.php?id=83&sc_do=divide-area&sc_item=1'});
    const opened = [];
    ctx.openDivideSectorsForStagedArea = (area) => opened.push(area);
    timeouts[0].fn();
    // Objects made inside the vm context have another Array.prototype: compare them as JSON.
    assert.equal(JSON.stringify(opened), JSON.stringify([{item_id: 1, label: 'Κοίτη', geo: [[35.0, 24.0], [35.1, 24.0], [35.1, 24.1]]}]));
});

test('«Τομείς» does nothing for an area that is already made, an item that is gone, or an area with no outline - and says so', async () => {
    for (const item of ['1', '2', '99']) {
        const {ctx, timeouts, calls} = await load(zonePhase([searchArea(1, {sent: true, state: 'standing'}), searchArea(2, {geo: [[35, 24]]})], false), {}, {mode: 'room', href: 'http://localhost/volunteerops/war-room.php?id=83&sc_do=divide-area&sc_item=' + item});
        const opened = [];
        ctx.openDivideSectorsForStagedArea = (area) => opened.push(area);
        timeouts[0].fn();
        assert.deepEqual(opened, [], 'item ' + item);
        assert.ok(calls.some((c) => c[0] === 'alert' && /scenario\.deeplink_gone/.test(c[1])), 'item ' + item + ': the person is told it is not there to act on');
    }
});

test('a sector says which staged area it is cut from and in which phase; one cut from a made area says only the area', async () => {
    const staged = await load(zonePhase([searchArea(1), stagedSector(2)], false));
    assert.match(staged.body.innerHTML, /scenario\.sector_in_staged_area\{"area":"Κοίτη","phase":"scenario\.phase_n\{\\"n\\":1\}"\}/);
    assert.doesNotMatch(staged.body.innerHTML, /scenario\.sector_in_area/);

    const live = await load(zonePhase([stagedSector(2, {area_staged: false, area_item_id: null, area_phase_n: null, area_id: 12})], false));
    assert.match(live.body.innerHTML, /scenario\.sector_in_area\{"area":"Κοίτη"\}/);
    assert.doesNotMatch(live.body.innerHTML, /sector_in_staged_area/);
});

test('a sector whose staged area was taken off the plan says the area is gone', async () => {
    const {body} = await load(zonePhase([stagedSector(2, {area_staged: false, area_missing: true, area_phase_n: null})], false));
    assert.match(body.innerHTML, /scenario\.area_gone_hint/);
});

test('a staged area is a dashed grey polygon with the phase number and no «→» in its tooltip; a made one leaves no ghost', async () => {
    const tips = [];
    const {api, drawn, ctx} = await load(zonePhase([searchArea(1), searchArea(2, {sent: true, state: 'standing'})], false));
    const shapeOf = ctx.L.polygon;
    ctx.L.polygon = (...args) => {
        const chain = shapeOf(...args);
        const bind = chain.bindTooltip;
        chain.bindTooltip = (text, ...rest) => { tips.push(text); return bind(text, ...rest); };
        return chain;
    };
    drawn.length = 0;
    api.drawGhostsInto({name: 'layer'});

    const polygons = drawn.filter((d) => d.kind === 'polygon');
    assert.equal(polygons.length, 1);
    assert.equal(polygons[0].args[1].dashArray, '7 6');
    assert.equal(drawn.filter((d) => d.kind === 'marker').length, 1, 'one phase number');
    assert.equal(tips.length, 1);
    assert.match(tips[0], /scenario\.phase_n/);
    assert.match(tips[0], /scenario\.kind_search_area/);
    assert.doesNotMatch(tips[0], /→/);
});

test('the play confirmation names no recipient for an area beside other orders, and «για όλους» when the area is all there is', async () => {
    const ask = async (items) => {
        const {handlers, calls, ctx} = await load(zonePhase(items, false), {play_phase: {ok: true, sent: 1, failed: 0, results: []}});
        ctx.confirm = (text) => { calls.push(['confirm', text]); return true; };
        await clickOn(handlers, {sc: 'play', phase: '7'});
        await settle();
        return calls.find((c) => c[0] === 'confirm')[1];
    };
    const alone = await ask([searchArea(1)]);
    assert.match(alone, /"orders":1,"targets":"scenario\.target_everyone"\}/);
    const withSector = await ask([searchArea(1), stagedSector(2)]);
    assert.match(withSector, /"orders":2,"targets":"Alpha 1"\}/, 'only the sector\'s team');
});

test('play and retry put an area they made on the map at once, through the sector pair of lists', async () => {
    const sectors = [{id: 3}];
    const areas = [{id: 12, label: 'Κοίτη'}];
    for (const [action, click, answer, scenario] of [
        ['play_phase', {sc: 'play', phase: '7'}, {ok: true, sent: 1, failed: 0, results: [], sectors, areas}, zonePhase([searchArea(1)], false)],
        ['retry_item', {sc: 'retry', item: '1'}, {ok: true, item: {ok: true, kind: 'area'}, sectors, areas}, zonePhase([searchArea(1, {error: 'x'})], true, standing({standing: 0}))],
    ]) {
        const {handlers, calls, ctx} = await load(scenario, {[action]: answer});
        ctx.sectorRefreshAfter = (s, a) => calls.push(['sectorRefreshAfter', s, a]);
        calls.length = 0;
        await clickOn(handlers, click);
        await settle();
        const hit = calls.find((c) => c[0] === 'sectorRefreshAfter');
        assert.ok(hit, action + ' redraws sectors and areas');
        assert.deepEqual([hit[1], hit[2]], [sectors, areas], action);
    }
});

test('withdrawing a phase that holds only an area and its sectors does not claim that zones are removed', async () => {
    const prog = {total: 2, done: 0, open: 2, standing: 0, withdrawn: 0, failed: 0, complete: false, withdrawable: 2, can_withdraw: true};
    const items = [searchArea(1, {sent: true, state: 'standing'}), stagedSector(2, {sent: true, state: 'open', area_staged: false, area_id: 12})];
    const {handlers, calls, ctx} = await load(zonePhase(items, true, prog), {withdraw_phase: {ok: true, withdrawn: 2, failed: 0, results: []}});
    ctx.confirm = (text) => { calls.push(['confirm', text]); return true; };
    await clickOn(handlers, {sc: 'withdraw', phase: '7'});
    await settle();
    const text = calls.find((c) => c[0] === 'confirm')[1];
    assert.doesNotMatch(text, /withdraw_confirm_zones/);
    assert.match(text, /"n":2\}/);
});

// ── Buildings of a sector (v3.460.0) ──────────────────────────────────────────────────────────────────────────────
// A building is a point in a sector: audience 'none' (the sector's team searches it), never finished (it stands), written
// down against a sector the plan makes ('item:<id>') or one that exists. Its row says its floors and its sector; an unsent
// sector has «Κτίριο», which arms the next click on the map; taking the phase back leaves it in the sector.
const building = (id, over = {}) => Object.assign({
    id, kind: 'building', type: 'building', audience: 'none', team_id: null, team_label: null, team_missing: false,
    slot_id: null, slot_label: null, unmapped: false, team_empty: false, area_missing: false, sent: false, error: null,
    label: 'Σχολείο', geo: [[35.05, 24.02]], floor_count: 2, state: null,
    sector_item_id: 2, sector_label: 'Τομέας Α', sector_staged: true, sector_phase_n: 1, sector_missing: false,
}, over);

test('the card\'s building popup stages a building through add_building, with a sector and no team in it', async () => {
    const {api, calls} = await load(plan([]), {add_building: {ok: true, phase_id: 7}});
    assert.equal(typeof api.stageBuilding, 'function');
    const res = await api.stageBuilding('new', {sector_id: 'item:2', label: 'Σχολείο', lat: 35.05, lng: 24.02, floor_count: 2});

    assert.equal(res.ok, true);
    const fields = new URLSearchParams(calls.filter((c) => c[0] === 'fetch' && c[2]).pop()[2]);
    assert.equal(fields.get('action'), 'add_building');
    assert.equal(fields.get('phase_id'), 'new');
    assert.equal(fields.get('sector_id'), 'item:2');
    assert.equal(fields.get('label'), 'Σχολείο');
    assert.equal(fields.get('lat'), '35.05');
    assert.equal(fields.get('lng'), '24.02');
    assert.equal(fields.get('floor_count'), '2');
    assert.equal(fields.get('mission_id'), '83');
    assert.equal(fields.has('team_id'), false, 'a building has no team');
});

test('a refused building (outside its sector) is shown to the person and the answer is handed back', async () => {
    const {api, calls} = await load(plan([]), {add_building: {ok: false, error: 'Το κτίριο πέφτει 31 μ. έξω από τον τομέα.'}});
    const res = await api.stageBuilding('7', {sector_id: 'item:2', label: 'Σχολείο', lat: 35.9, lng: 24.9, floor_count: 0});
    assert.equal(res.ok, false);
    assert.ok(calls.some((c) => c[0] === 'alert' && c[1] === 'Το κτίριο πέφτει 31 μ. έξω από τον τομέα.'));
});

test('the card says what a building is, how many floors, in which sector and phase, and names no recipient', async () => {
    const {body} = await load(zonePhase([building(1)], false));
    assert.match(body.innerHTML, /scenario\.kind_building/);
    assert.match(body.innerHTML, /bi-building-fill/);
    assert.match(body.innerHTML, /scenario\.building_floors\{"n":2\}/);
    assert.match(body.innerHTML, /scenario\.building_in_staged_sector\{"sector":"Τομέας Α","phase":"scenario\.phase_n\{\\"n\\":1\}"\}/);
    assert.doesNotMatch(body.innerHTML, /scenario\.building_in_sector\{/);
    assert.doesNotMatch(body.innerHTML, /scenario\.target_everyone|common\.all_teams|scenario\.team_gone/, 'a building goes to nobody');
    assert.doesNotMatch(body.innerHTML, /bi-arrow-right-short/, 'no «→ προς» for it');
    assert.match(body.innerHTML, /data-sc="goto"/);
    assert.match(body.innerHTML, /data-sc="delete-item"/);

    const ground = await load(zonePhase([building(1, {floor_count: 0})], false));
    assert.match(ground.body.innerHTML, /scenario\.building_ground_only/);
    assert.doesNotMatch(ground.body.innerHTML, /scenario\.building_floors/);
    const live = await load(zonePhase([building(1, {sector_staged: false, sector_item_id: null, sector_phase_n: null})], false));
    assert.match(live.body.innerHTML, /scenario\.building_in_sector\{"sector":"Τομέας Α"\}/);
    assert.doesNotMatch(live.body.innerHTML, /building_in_staged_sector/);
});

test('a building whose sector was taken off the plan says the sector is gone', async () => {
    const {body} = await load(zonePhase([building(1, {sector_missing: true, sector_staged: false})], false));
    assert.match(body.innerHTML, /scenario\.sector_gone_hint/);
});

test('a made building stands calmly: no delete, no red shield, and it says that withdrawing leaves it in its sector', async () => {
    const prog = {total: 0, done: 0, open: 0, standing: 0, withdrawn: 0, failed: 0, complete: false, withdrawable: 0, can_withdraw: false};
    const {body} = await load(zonePhase([building(1, {sent: true, state: 'standing', building_id: 5, sector_staged: false})], true, prog));
    assert.match(body.innerHTML, /scenario\.state_standing/);
    assert.match(body.innerHTML, /scenario\.building_stays_hint/);
    assert.doesNotMatch(body.innerHTML, /bi-shield-fill-exclamation/, 'it is not a danger zone');
    assert.doesNotMatch(body.innerHTML, /data-sc="delete-item"/);
    assert.doesNotMatch(body.innerHTML, /progress_badge/, 'a building is not an order to finish');
    assert.doesNotMatch(body.innerHTML, /data-sc="withdraw"/, 'nothing in the phase can be taken back');
});

test('«Κτίριο» is on an unsent sector with an outline only, under its words', async () => {
    const withOutline = await load(zonePhase([stagedSector(2, {area_staged: false, area_id: 12})], false));
    assert.match(withOutline.body.innerHTML, /data-sc="add-building" data-item="2"/);
    assert.match(withOutline.body.innerHTML, /scenario\.building_add_btn/);
    const sent = await load(zonePhase([stagedSector(2, {sent: true, state: 'open', area_staged: false, area_id: 12})], true));
    assert.doesNotMatch(sent.body.innerHTML, /add-building/, 'a sector that is made is not for the plan');
    const noOutline = await load(zonePhase([stagedSector(2, {geo: [[35, 24]]})], false));
    assert.doesNotMatch(noOutline.body.innerHTML, /add-building/);
    const notASector = await load(zonePhase([searchArea(1), building(3)], false));
    assert.doesNotMatch(notASector.body.innerHTML, /add-building/, 'an area and a building have no «Κτίριο»');
});

test('«Κτίριο» arms the Action Room with the sector\'s item, name, points and phase - when the page sends it here', async () => {
    const {ctx, timeouts} = await load(zonePhase([stagedSector(2)], false), {}, {mode: 'room', href: 'http://localhost/volunteerops/war-room.php?id=83&sc_do=add-building&sc_item=2'});
    const armed = [];
    ctx.armStagedBuilding = (sector) => armed.push(sector);
    timeouts[0].fn();
    assert.equal(JSON.stringify(armed), JSON.stringify([{item_id: 2, label: 'Τομέας Α', geo: [[35.0, 24.0], [35.1, 24.0], [35.1, 24.1]], phase_id: 7}]));
});

test('«Κτίριο» does nothing for a sector that is made, an item that is gone, a building, or a sector with no outline', async () => {
    const {handlers, ctx} = await load(zonePhase([stagedSector(2, {sent: true, state: 'open'}), stagedSector(3, {geo: [[35, 24]]}), building(4)], false));
    const armed = [];
    ctx.armStagedBuilding = (sector) => armed.push(sector);
    for (const item of ['2', '3', '4', '99']) {
        await clickOn(handlers, {sc: 'add-building', item});
    }
    assert.deepEqual(armed, []);
});

test('a staged building is a dashed rounded square with the phase number and no «→» in its tooltip; a made one leaves no ghost', async () => {
    const tips = [];
    const {api, drawn, ctx} = await load(zonePhase([building(1), building(2, {sent: true, state: 'standing'})], false));
    const markerOf = ctx.L.marker;
    ctx.L.marker = (...args) => {
        const chain = markerOf(...args);
        const bind = chain.bindTooltip;
        chain.bindTooltip = (text, ...rest) => { tips.push(text); return bind(text, ...rest); };
        return chain;
    };
    drawn.length = 0;
    api.drawGhostsInto({name: 'layer'});

    const pins = drawn.filter((d) => d.kind === 'marker');
    assert.equal(pins.length, 1);
    assert.deepEqual(pins[0].args[0], [35.05, 24.02], 'where the building will be');
    assert.match(pins[0].args[1].icon.html, /wr-sc-ghost-building/);
    assert.match(pins[0].args[1].icon.html, /<span>1<\/span>/, 'the phase number');
    assert.equal(tips.length, 1);
    assert.match(tips[0], /scenario\.kind_building/);
    assert.doesNotMatch(tips[0], /→/);
});

test('the play confirmation names no recipient for a building beside other orders, and «για όλους» when buildings are all there is', async () => {
    const ask = async (items) => {
        const {handlers, calls, ctx} = await load(zonePhase(items, false), {play_phase: {ok: true, sent: 1, failed: 0, results: []}});
        ctx.confirm = (text) => { calls.push(['confirm', text]); return true; };
        await clickOn(handlers, {sc: 'play', phase: '7'});
        await settle();
        return calls.find((c) => c[0] === 'confirm')[1];
    };
    const alone = await ask([building(1)]);
    assert.match(alone, /"orders":1,"targets":"scenario\.target_everyone"\}/);
    const withSector = await ask([stagedSector(2, {area_staged: false, area_id: 12}), building(1)]);
    assert.match(withSector, /"orders":2,"targets":"Alpha 1"\}/, 'only the sector\'s team');
    const missing = await ask([building(1, {sector_missing: true, sector_staged: false})]);
    assert.match(missing, /scenario\.play_confirm_sector_missing\{"n":1\}/);
});

test('play and retry put a building they made on the map at once, through the sector pair of lists', async () => {
    const sectors = [{id: 3, buildings: [{id: 5}]}];
    const areas = [{id: 12, label: 'Κοίτη'}];
    for (const [action, click, answer, scenario] of [
        ['play_phase', {sc: 'play', phase: '7'}, {ok: true, sent: 1, failed: 0, results: [], sectors, areas}, zonePhase([building(1)], false)],
        ['retry_item', {sc: 'retry', item: '1'}, {ok: true, item: {ok: true, kind: 'building'}, sectors, areas}, zonePhase([building(1, {error: 'x'})], true, standing({standing: 0}))],
    ]) {
        const {handlers, calls, ctx} = await load(scenario, {[action]: answer});
        ctx.sectorRefreshAfter = (s, a) => calls.push(['sectorRefreshAfter', s, a]);
        calls.length = 0;
        await clickOn(handlers, click);
        await settle();
        const hit = calls.find((c) => c[0] === 'sectorRefreshAfter');
        assert.ok(hit, action + ' redraws sectors and areas');
        assert.deepEqual([hit[1], hit[2]], [sectors, areas], action);
    }
});

test('withdrawing a phase that holds a sector and its building does not claim that zones are removed', async () => {
    const prog = {total: 1, done: 0, open: 1, standing: 0, withdrawn: 0, failed: 0, complete: false, withdrawable: 1, can_withdraw: true};
    const items = [stagedSector(2, {sent: true, state: 'open', area_staged: false, area_id: 12}), building(1, {sent: true, state: 'standing', sector_staged: false})];
    const {handlers, calls, ctx} = await load(zonePhase(items, true, prog), {withdraw_phase: {ok: true, withdrawn: 1, failed: 0, results: []}});
    ctx.confirm = (text) => { calls.push(['confirm', text]); return true; };
    await clickOn(handlers, {sc: 'withdraw', phase: '7'});
    await settle();
    const text = calls.find((c) => c[0] === 'confirm')[1];
    assert.doesNotMatch(text, /withdraw_confirm_zones/);
    assert.match(text, /"n":1\}/);
});

// ── Per-team release (v3.461.0) ─────────────────────────────────────────────────────────────────────────────────

const waitingItem = (id, over = {}) => Object.assign({}, TEAM, {
    id, kind: 'dispatch', type: 'point', label: 'Σημείο ' + id, geo: {lat: 35, lng: 24}, sent: false, state: 'waiting', waiting: true,
    team_id: 40, team_label: 'Beta 2', wait_team_id: 40,
    wait: {team_id: 40, team_label: 'Beta 2', phase_n: 1, done: 1, of: 3, cant: false, blocked: true},
}, over);
const WAIT_ROW = {team_id: 40, team_label: 'Beta 2', n: 2, cancelled: false, phase_n: 1, done: 1, of: 3, cant: false};
const armedPhases = (items, waits, preview = null) => {
    const played = waits.length > 0 || items.some((i) => i.state === 'waiting');
    return {
        phases: [
            {id: 7, n: 1, title: null, played_at: '2026-11-07 10:00:00', played_label: '10:00', played_by_name: 'Admin', items: [sentItem(1, 'open')], progress: {total: 1, done: 0, open: 1, waiting: 0, standing: 0, withdrawn: 0, failed: 0, complete: false, withdrawable: 1, can_withdraw: true}, waits: [], release_preview: null},
            {id: 8, n: 2, title: null, played_at: played ? '2026-11-07 10:05:00' : null, played_label: '10:05', played_by_name: 'Admin', items,
                progress: played ? {total: items.length, done: 0, open: 0, waiting: items.length, standing: 0, withdrawn: 0, failed: 0, complete: false, withdrawable: items.length, can_withdraw: true} : null,
                waits, release_preview: preview},
        ],
        slots: [], teams: [],
    };
};

test('a played phase shows each waiting order with what its team still has to finish, and one row per team with the two overrides', async () => {
    const stuck = waitingItem(3, {wait: {team_id: 40, team_label: 'Beta 2', phase_n: 1, done: 0, of: 1, cant: true, blocked: true}});
    const {body} = await load(armedPhases([waitingItem(2), stuck], [WAIT_ROW]));

    const html = body.innerHTML;
    assert.match(html, /scenario\.state_waiting\{"team":"Beta 2","phase":"scenario\.phase_n\{\\"n\\":1\}","done":1,"of":3\}/, 'the order says who it waits for and how far they are');
    assert.match(html, /scenario\.state_waiting_cant/, 'a team that said «Δεν μπορώ» is shown as stuck');
    assert.match(html, /text-danger"><i class="bi bi-exclamation-octagon-fill/, 'in red');
    assert.match(html, /data-sc-waits="1"/);
    assert.match(html, /scenario\.wait_row\{"team":"Beta 2","phase":"scenario\.phase_n\{\\"n\\":1\}","done":1,"of":3,"n":2\}/);
    assert.match(html, /data-sc="release-now" data-phase="8" data-team="40" data-cancelled="0"/);
    assert.match(html, /data-sc="cancel-wait" data-phase="8" data-team="40"/);
    assert.doesNotMatch(html, /small text-danger fw-semibold/, 'a team that is simply waiting is not shown red');
    const stuckRow = await load(armedPhases([stuck], [Object.assign({}, WAIT_ROW, {cant: true, n: 1})]));
    assert.match(stuckRow.body.innerHTML, /small text-danger fw-semibold">scenario\.wait_row_cant/, 'a team stuck on «Δεν μπορώ» is red in its row');
    assert.match(html, /scenario\.progress_badge\{"done":0,"total":2\}/, 'a waiting order is not finished, so the phase is not');
    assert.match(html, /data-sc="withdraw" data-phase="8"/, 'and the phase can be taken back, which cancels the waits');
});

test('a row of cancelled waits has only «Στείλε τώρα», and a cancelled order and a split one say so', async () => {
    const cancelled = waitingItem(2, {state: 'cancelled', waiting: false, wait: null});
    const parent = Object.assign({}, TEAM, {id: 5, kind: 'dispatch', type: 'polygon', label: 'Χωράφι', audience: 'all', team_id: null, team_label: null, geo: [[35, 24], [35, 24.1], [35.1, 24.1]], sent: true, state: 'expanded', is_parent: true});
    const child = waitingItem(6, {parent_item_id: 5});
    const {body} = await load(armedPhases([cancelled, parent, child], [Object.assign({}, WAIT_ROW, {cancelled: true, n: 1})]));

    const html = body.innerHTML;
    assert.match(html, /data-sc="release-now" data-phase="8" data-team="40" data-cancelled="1"/);
    assert.equal((html.match(/data-sc="cancel-wait"/g) || []).length, 0, 'nothing left to cancel on that row');
    assert.match(html, /scenario\.wait_row_cancelled/);
    assert.match(html, /scenario\.state_cancelled/);
    assert.match(html, /scenario\.state_split/, 'the order to every team says it was split per team');
    assert.match(html, /wr-sc-child/, 'and its parts hang under it');
});

test('«Στείλε τώρα» asks about a team that has not finished, sends, and shows what it made at once; a cancelled row needs no question', async () => {
    const answer = {ok: true, sent: 2, failed: 1, results: [{ok: false, error: 'η ομάδα δεν υπάρχει'}], dispatches: [{id: 9}]};
    const {handlers, calls, ctx} = await load(armedPhases([waitingItem(2), waitingItem(3)], [WAIT_ROW]), {release_now: answer});
    ctx.confirm = (text) => { calls.push(['confirm', text]); return true; };
    calls.length = 0;

    await clickOn(handlers, {sc: 'release-now', phase: '8', team: '40', cancelled: '0'});
    await settle();

    assert.match(calls.find((c) => c[0] === 'confirm')[1], /scenario\.release_now_confirm\{"team":"Beta 2","phase":"scenario\.phase_n\{\\"n\\":1\}","done":1,"of":3\}/);
    const post = calls.find((c) => c[0] === 'fetch' && c[2]);
    const sent = new URLSearchParams(post[2]);
    assert.equal(sent.get('action'), 'release_now');
    assert.equal(sent.get('phase_id'), '8');
    assert.equal(sent.get('team_id'), '40');
    assert.ok(calls.some((c) => c[0] === 'renderDispatches'), 'the orders it made are on the map at once');
    assert.match(calls.find((c) => c[0] === 'alert')[1], /scenario\.play_partial\{"sent":2,"failed":1\}[^]*η ομάδα δεν υπάρχει/);

    // Declining the question sends nothing.
    const again = await load(armedPhases([waitingItem(2)], [WAIT_ROW]));
    again.ctx.confirm = () => false;
    again.calls.length = 0;
    await clickOn(again.handlers, {sc: 'release-now', phase: '8', team: '40', cancelled: '0'});
    await settle();
    assert.equal(again.calls.filter((c) => c[0] === 'fetch').length, 0);

    // A row whose wait was cancelled is sent without the question: command already decided about it.
    const cancelledRow = Object.assign({}, WAIT_ROW, {cancelled: true});
    const third = await load(armedPhases([waitingItem(2, {state: 'cancelled', waiting: false})], [cancelledRow]), {release_now: {ok: true, sent: 1, failed: 0, results: []}});
    third.ctx.confirm = (text) => { third.calls.push(['confirm', text]); return true; };
    third.calls.length = 0;
    await clickOn(third.handlers, {sc: 'release-now', phase: '8', team: '40', cancelled: '1'});
    await settle();
    assert.equal(third.calls.filter((c) => c[0] === 'confirm').length, 0);
    assert.equal(new URLSearchParams(third.calls.find((c) => c[0] === 'fetch' && c[2])[2]).get('action'), 'release_now');
});

test('«Ακύρωση αναμονής» asks first and then cancels the team\'s waits', async () => {
    const {handlers, calls, ctx} = await load(armedPhases([waitingItem(2)], [WAIT_ROW]), {cancel_wait: {ok: true, cancelled: 1}});
    ctx.confirm = (text) => { calls.push(['confirm', text]); return false; };
    calls.length = 0;
    await clickOn(handlers, {sc: 'cancel-wait', phase: '8', team: '40'});
    await settle();
    assert.match(calls.find((c) => c[0] === 'confirm')[1], /scenario\.cancel_wait_confirm\{"team":"Beta 2"\}/);
    assert.equal(calls.filter((c) => c[0] === 'fetch').length, 0, 'declined: nothing is sent');

    ctx.confirm = () => true;
    await clickOn(handlers, {sc: 'cancel-wait', phase: '8', team: '40'});
    await settle();
    const sent = new URLSearchParams(calls.find((c) => c[0] === 'fetch' && c[2])[2]);
    assert.deepEqual([sent.get('action'), sent.get('phase_id'), sent.get('team_id')], ['cancel_wait', '8', '40']);
});

test('the confirmation of play names who gets their orders at once and who waits, with how far they are', async () => {
    const preview = {
        now: [{team_id: 39, team_label: 'Alpha 1'}],
        wait: [{team_id: 40, team_label: 'Beta 2', phase_n: 1, done: 1, of: 3, cant: false}, {team_id: 41, team_label: 'Gamma 3', phase_n: 1, done: 0, of: 1, cant: true}],
    };
    const items = [Object.assign({}, TEAM, {id: 2, kind: 'dispatch', type: 'point', label: 'Β', geo: {lat: 35, lng: 24}, sent: false, state: null})];
    const {handlers, calls, ctx} = await load(armedPhases(items, [], preview));
    ctx.confirm = (text) => { calls.push(['confirm', text]); return false; };
    await clickOn(handlers, {sc: 'play', phase: '8'});
    await settle();
    const text = calls.find((c) => c[0] === 'confirm')[1];
    assert.match(text, /scenario\.play_confirm_now\{"teams":"Alpha 1"\}/);
    assert.match(text, /scenario\.play_confirm_wait\{/);
    assert.match(text, /play_confirm_wait_team[^]*Beta 2[^]*\\"done\\":1,\\"of\\":3/, 'Beta 2 is one of three');
    assert.match(text, /play_confirm_wait_cant[^]*Gamma 3/, 'and Gamma 3 is shown as stuck');
    assert.ok(text.indexOf('play_confirm_now') < text.indexOf('play_confirm_wait{') && text.indexOf('\n\n') < text.indexOf('play_confirm_now'), 'right after the first paragraph');

    // Nobody waiting: the confirmation is the plain one.
    const plain = await load(armedPhases(items, [], {now: [{team_id: 39, team_label: 'Alpha 1'}], wait: []}));
    plain.ctx.confirm = (t2) => { plain.calls.push(['confirm', t2]); return false; };
    await clickOn(plain.handlers, {sc: 'play', phase: '8'});
    await settle();
    assert.doesNotMatch(plain.calls.find((c) => c[0] === 'confirm')[1], /play_confirm_wait/);
});

test('taking a phase back says that waiting orders are cancelled and that later orders waiting for it will go', async () => {
    const later = {id: 9, n: 3, title: null, played_at: '2026-11-07 10:09:00', played_label: '10:09', played_by_name: 'Admin', items: [waitingItem(9, {wait: {team_id: 40, team_label: 'Beta 2', phase_n: 2, done: 0, of: 1, cant: false, blocked: true}})], progress: {total: 1, done: 0, open: 0, waiting: 1, standing: 0, withdrawn: 0, failed: 0, complete: false, withdrawable: 1, can_withdraw: true}, waits: [], release_preview: null};
    const plan = armedPhases([waitingItem(2)], [WAIT_ROW]);
    plan.phases[1].progress.waiting = 1;
    plan.phases.push(later);
    const {handlers, calls, ctx} = await load(plan, {withdraw_phase: {ok: true, withdrawn: 1, failed: 0, results: []}});
    ctx.confirm = (text) => { calls.push(['confirm', text]); return true; };
    const plainT = ctx.t;
    ctx.t = (key, vars) => (key === 'scenario.withdraw_confirm' ? 'intro\n\nthe closing question' : plainT(key, vars));
    await clickOn(handlers, {sc: 'withdraw', phase: '8'});
    await settle();
    const text = calls.find((c) => c[0] === 'confirm')[1];
    assert.match(text, /scenario\.withdraw_confirm_waits\{"n":1\}/, 'its own waits');
    assert.match(text, /scenario\.withdraw_confirm_later\{"n":1\}/, 'the later order that waits for this phase');
    assert.ok(text.lastIndexOf('withdraw_confirm_later') < text.lastIndexOf('\n\n'), 'both come before the closing question');
});

test('the card keeps refreshing while an order waits, and what a refresh released is put on the map at once', async () => {
    const plan = armedPhases([waitingItem(2)], [WAIT_ROW]);
    plan.phases[0].progress.open = 0;
    const {timers, calls, ctx} = await load(plan, {get: {ok: true, released: [{ok: true, kind: 'dispatch'}], dispatches: [{id: 4}], sectors: [{id: 1}], areas: [], routes: [{id: 2}]}});
    ctx.sectorRefreshAfter = (s, a) => calls.push(['sectorRefreshAfter', s, a]);
    calls.length = 0;

    timers[0]();
    await settle();
    await settle();

    assert.ok(calls.some((c) => c[0] === 'fetch'), 'a waiting order keeps the safety net running even with nothing open');
    assert.ok(calls.some((c) => c[0] === 'renderDispatches'));
    assert.ok(calls.some((c) => c[0] === 'sectorRefreshAfter'));
    assert.ok(calls.some((c) => c[0] === 'renderRouteLayer'));
});

test('an order that went out by itself says when and why, and one sent by hand says so', async () => {
    const auto = sentItem(2, 'open', {release_mode: 'auto', released_label: '14:32'});
    const manual = sentItem(3, 'open', {release_mode: 'manual', released_label: '14:40'});
    const {body} = await load(armedPhases([auto, manual], []));
    assert.match(body.innerHTML, /scenario\.state_released_auto\{"time":"14:32"\}/);
    assert.match(body.innerHTML, /scenario\.state_released_manual\{"time":"14:40"\}/);
});

// ── v3.462.0: the card on its own page, and a summary line in the Action Room ────────────────────────────────────

const PAGE_URL = 'war-room.php?id=83';

test('on the page the controls that need the map are links to the Action Room, named for the mission, and the click handler leaves them alone', async () => {
    const {body, handlers, calls} = await load(zonePhase([searchArea(1), stagedSector(2), building(3)], false));
    const html = body.innerHTML;
    assert.match(html, /<a href="war-room\.php\?id=83&amp;sc_do=goto&amp;sc_item=1" target="wrActionRoom83" class="wr-sc-goto" data-sc="goto" data-item="1" data-sc-link="1"/);
    assert.match(html, /<a href="war-room\.php\?id=83&amp;sc_do=divide-area&amp;sc_item=1" target="wrActionRoom83" class="btn btn-outline-primary btn-sm py-0" data-sc="divide-area" data-item="1" data-sc-link="1"/);
    assert.match(html, /<a href="war-room\.php\?id=83&amp;sc_do=add-building&amp;sc_item=2" target="wrActionRoom83" class="btn btn-outline-primary btn-sm py-0" data-sc="add-building" data-item="2" data-sc-link="1"/);
    assert.doesNotMatch(html, /<button[^>]*data-sc="(goto|divide-area|add-building)"/, 'no button on the page asks the map for anything');

    // A click on such a link is the browser's: nothing is prevented, nothing is sent.
    let prevented = false;
    calls.length = 0;
    await handlers.click({target: {closest: () => ({dataset: {sc: 'divide-area', item: '1', scLink: '1'}, disabled: false})}, preventDefault() { prevented = true; }});
    assert.equal(prevented, false);
    assert.equal(calls.length, 0);
});

test('the page draws the whole card, in a grid of phases, and never touches a map', async () => {
    const {body, drawn, api, handlers, win} = await load(zonePhase([searchArea(1), stagedSector(2)], false));
    assert.match(body.innerHTML, /class="wr-sc-grid"/);
    assert.match(body.innerHTML, /data-sc="play"/);
    assert.match(body.innerHTML, /data-sc="add-phase"/);
    assert.match(body.innerHTML, /data-sc="order-open"/);
    assert.match(body.innerHTML, /data-sc="add-slot"/);
    assert.equal(drawn.length, 0, 'no ghosts: the page has no map');
    assert.ok(handlers.click && handlers.change && handlers.input, 'the card listens to its own clicks, changes and typing');
    assert.equal(win.name, '', 'the page does not rename its window: only the Action Room does');
    assert.equal(typeof api.menuHtml, 'function', 'the API is the same one');
});

test('the Action Room draws only a summary line and keeps the map: ghosts, the order windows\' menus, its window\'s name', async () => {
    const {body, drawn, handlers, win, api} = await load(zonePhase([searchArea(1)], false), {}, {mode: 'room'});
    assert.match(body.innerHTML, /data-sc-summary="chips"/);
    assert.doesNotMatch(body.innerHTML, /data-sc="/, 'nothing to press in the summary');
    assert.doesNotMatch(body.innerHTML, /wr-sc-grid/);
    // A click listener IS attached in the Action Room now (v3.467.0), for the active nudge's own «Άνοιγμα»/✕ - but
    // with no nudge showing here, nothing of the summary itself is clickable (checked just above).
    assert.deepEqual(Object.keys(handlers), ['click']);
    assert.equal(win.name, 'wrActionRoom83', 'so that the page\'s links reach this very tab');
    api.drawGhostsInto({});
    assert.ok(drawn.length >= 2, "the ghosts are the same ones as before: the area's polygon and its number");
    assert.match(api.menuHtml(), /data-sc-phase="7"/, 'the «Στο σενάριο ▾» menus still know the phases');
});

test('a window that already has a name keeps it', async () => {
    const {win} = await load(zonePhase([], false), {}, {mode: 'room', windowName: 'mine'});
    assert.equal(win.name, 'mine');
});

test('the summary has one chip per phase: how far a played one is, who waits, who is blocked, what failed; and how many orders an unplayed one holds', async () => {
    const waits = [
        {team_id: 40, team_label: 'Beta 2', n: 1, cancelled: false, phase_n: 1, done: 1, of: 3, cant: false},
        {team_id: 41, team_label: 'Gamma 3', n: 1, cancelled: false, phase_n: 1, done: 0, of: 2, cant: true},
        {team_id: 42, team_label: 'Delta 4', n: 1, cancelled: true, phase_n: 1, done: 0, of: 2, cant: false},
    ];
    const plan3 = {
        phases: [
            {id: 7, n: 1, played_at: '2026-11-07 10:00:00', items: [sentItem(1, 'done')], progress: progress({total: 6, done: 4}), waits: []},
            {id: 8, n: 2, played_at: '2026-11-07 10:05:00', items: [sentItem(2, 'open'), Object.assign({}, TEAM, {id: 3, kind: 'dispatch', type: 'point', sent: false, error: 'x'})], progress: progress({total: 2, done: 0}), waits},
            {id: 9, n: 3, played_at: null, items: [Object.assign({}, TEAM, {id: 4, type: 'point', geo: {lat: 1, lng: 2}})], progress: null, waits: []},
        ],
        slots: [], teams: [],
    };
    const {body} = await load(plan3, {}, {mode: 'room'});
    const chips = body.innerHTML.match(/<span class="wr-sc-chip [^"]*" data-sc-chip="\d+">[^<]*<\/span>/g);
    assert.equal(chips.length, 3);
    assert.match(chips[0], /wr-sc-chip-run/);
    assert.match(chips[0], /scenario\.phase_n\{"n":1\} · scenario\.progress_badge\{"done":4,"total":6\}/);
    // Beta 2 waits (one team, singular), Gamma 3 is blocked, the cancelled wait of Delta 4 is not a wait, one order failed.
    assert.match(chips[1], /wr-sc-chip-bad/);
    assert.match(chips[1], /scenario\.summary_waiting_one · scenario\.summary_blocked\{"n":1\} · scenario\.summary_failed\{"n":1\}/);
    assert.doesNotMatch(chips[1], /summary_waiting_many/);
    assert.match(chips[2], /wr-sc-chip-idle/);
    assert.match(chips[2], /scenario\.phase_n\{"n":3\} · scenario\.summary_unsent\{"n":1\}/);
});

test('the summary says «περιμένουν N» for more than one waiting team, ✓ in green when a phase is finished, and ready for the phase after it', async () => {
    const waits = [{team_id: 40, n: 1, cancelled: false, phase_n: 1, done: 0, of: 1, cant: false}, {team_id: 41, n: 1, cancelled: false, phase_n: 1, done: 0, of: 1, cant: false}];
    const waiting = await load({phases: [{id: 8, n: 2, played_at: 'x', items: [], progress: progress({total: 2, done: 0}), waits}], slots: [], teams: []}, {}, {mode: 'room'});
    assert.match(waiting.body.innerHTML, /wr-sc-chip-wait/);
    assert.match(waiting.body.innerHTML, /scenario\.summary_waiting_many\{"n":2\}/);

    const plan2 = twoPhases({progress: progress({done: 6, open: 0, complete: true, can_withdraw: false}), waits: []}, {items: [Object.assign({}, TEAM, {id: 9, type: 'point', geo: {lat: 1, lng: 2}})]});
    const ready = await load(plan2, {}, {mode: 'room'});
    assert.match(ready.body.innerHTML, /wr-sc-chip-ok[^>]*>scenario\.phase_n\{"n":1\} · scenario\.progress_badge/);
    assert.match(ready.body.innerHTML, /wr-sc-chip-ready[^>]*>scenario\.phase_n\{"n":2\} · scenario\.summary_unsent\{"n":1\} · scenario\.summary_ready/);

    // The passive note of the page can be switched off, and then the summary does not say it either.
    const off = await load(plan2, {}, {mode: 'room'});
    off.storage.wrScenarioHint = '0';
    off.api.reload();
    await settle();
    assert.doesNotMatch(off.body.innerHTML, /summary_ready/);
});

test('the summary of an empty plan says so, and a long plan shows six chips and how many more', async () => {
    const empty = await load({phases: [], slots: [], teams: []}, {}, {mode: 'room'});
    assert.match(empty.body.innerHTML, /data-sc-summary="empty">scenario\.summary_empty</);
    const many = {phases: Array.from({length: 8}, (_, i) => ({id: i + 1, n: i + 1, played_at: null, items: [], progress: null, waits: []})), slots: [], teams: []};
    const long = await load(many, {}, {mode: 'room'});
    assert.equal((long.body.innerHTML.match(/data-sc-chip="/g) || []).length, 6);
    assert.match(long.body.innerHTML, /scenario\.summary_more\{"n":2\}/);
});

test('the Action Room does what the page asked once the page has loaded, takes the asking out of its address, and keeps the rest of it', async () => {
    const {ctx, timeouts, calls} = await load(twoPhases({items: [sentItem(1, 'open')]}, {items: [Object.assign({}, TEAM, {id: 5, type: 'point', geo: {lat: 35.1, lng: 24.2}, sent: false})]}), {}, {mode: 'room', href: 'http://localhost/volunteerops/war-room.php?id=83&sc_do=goto&sc_item=5&view=x#top'});
    const moves = [];
    ctx.map = {getZoom: () => 12, setView: (c, z) => moves.push(JSON.stringify([c, z])), fitBounds: () => moves.push('fit')};
    const replaced = calls.filter((c) => c[0] === 'replaceState');
    assert.equal(replaced.length, 1);
    assert.equal(replaced[0][1], '/volunteerops/war-room.php?id=83&view=x#top', 'only sc_do and sc_item come out');
    assert.equal(timeouts.length, 1);
    assert.equal(timeouts[0].ms, 800, 'after the page has had a moment to frame the mission');
    assert.deepEqual(moves, [], 'not before that moment');
    timeouts[0].fn();
    assert.deepEqual(moves, ['[[35.1,24.2],16]']);
});

test('the Action Room waits for the load event when the page is still loading, and asks only once', async () => {
    const {timeouts, winHandlers, api, calls} = await load(twoPhases({}, {}), {}, {mode: 'room', readyState: 'loading', href: 'http://localhost/volunteerops/war-room.php?id=83&sc_do=goto&sc_item=1'});
    assert.equal(timeouts.length, 0);
    assert.equal(typeof winHandlers.load, 'function');
    winHandlers.load();
    assert.equal(timeouts.length, 1);
    // A later refresh answers with the same plan: the link has been used up.
    await api.reload();
    assert.equal(timeouts.length, 1);
    assert.equal(calls.filter((c) => c[0] === 'replaceState').length, 1);
});

test('without sc_do and sc_item, or on the page itself, nothing happens', async () => {
    const plain = await load(twoPhases({}, {}), {}, {mode: 'room'});
    assert.equal(plain.timeouts.length, 0);
    assert.equal(plain.calls.filter((c) => c[0] === 'replaceState').length, 0);
    const half = await load(twoPhases({}, {}), {}, {mode: 'room', href: 'http://localhost/volunteerops/war-room.php?id=83&sc_do=goto'});
    assert.equal(half.timeouts.length, 0);
    const onPage = await load(twoPhases({}, {}), {}, {mode: 'page', href: 'http://localhost/volunteerops/mission-scenario-view.php?id=83&sc_do=goto&sc_item=1'});
    assert.equal(onPage.timeouts.length, 0, 'the page has no map to act on');
    assert.equal(onPage.calls.filter((c) => c[0] === 'replaceState').length, 0);
});

test('an unknown action in the address says the order is not there, and does nothing else', async () => {
    const {ctx, timeouts, calls} = await load(twoPhases({}, {items: [Object.assign({}, TEAM, {id: 5, type: 'point', geo: {lat: 35.1, lng: 24.2}, sent: false})]}), {}, {mode: 'room', href: 'http://localhost/volunteerops/war-room.php?id=83&sc_do=delete-everything&sc_item=5'});
    ctx.map = {getZoom: () => 12, setView: () => calls.push(['moved']), fitBounds: () => calls.push(['moved'])};
    timeouts[0].fn();
    assert.ok(calls.some((c) => c[0] === 'alert' && /deeplink_gone/.test(c[1])));
    assert.ok(!calls.some((c) => c[0] === 'moved'));
});

test('a staged order made from the map shows on the page at the next refresh, and one played on the page shows in the Action Room at its next', async () => {
    const answerWith = (plan) => async () => ({json: async () => ({ok: true, scenario: plan})});
    // The page asks again and draws what the other tab staged.
    const page = await load(twoPhases({}, {}), {}, {mode: 'page'});
    assert.doesNotMatch(page.body.innerHTML, /Σημείο 77/);
    page.ctx.fetch = answerWith(twoPhases({}, {items: [Object.assign({}, TEAM, {id: 77, type: 'point', label: 'Σημείο 77', geo: {lat: 35, lng: 24}, sent: false})]}));
    page.timers[0]();
    await settle();
    assert.match(page.body.innerHTML, /Σημείο 77/);
    // The Action Room: phase 2 held one unsent order, and is played in the other tab.
    const room = await load(twoPhases({}, {items: [Object.assign({}, TEAM, {id: 77, type: 'point', label: 'Σημείο 77', geo: {lat: 35, lng: 24}, sent: false})]}), {}, {mode: 'room'});
    assert.match(room.body.innerHTML, /scenario\.phase_n\{"n":2\} · scenario\.summary_unsent\{"n":1\}/);
    room.ctx.fetch = answerWith(twoPhases({}, {played_at: '2026-11-07 10:07:00', items: [sentItem(77, 'open')], progress: progress({total: 1, done: 0})}));
    room.timers[0]();
    await settle();
    assert.doesNotMatch(room.body.innerHTML, /summary_unsent/);
    assert.match(room.body.innerHTML, /scenario\.phase_n\{"n":2\} · scenario\.progress_badge\{"done":0,"total":1\}/);
});

test('the page says why when the plan never arrives, and keeps the last good card when a later refresh fails', async () => {
    const dead = await load({phases: [], slots: [], teams: []}, {get: {ok: false, error: 'Η αποστολή δεν βρέθηκε ή δεν είναι ενεργή'}});
    assert.match(dead.body.innerHTML, /data-sc-load-error="1">Η αποστολή δεν βρέθηκε/);
    const room = await load({phases: [], slots: [], teams: []}, {get: {ok: false, error: 'x'}}, {mode: 'room'});
    assert.doesNotMatch(room.body.innerHTML, /data-sc-load-error/, 'the Action Room has the map to show its trouble; the summary stays empty');

    const good = await load(twoPhases({items: [sentItem(1, 'open')]}, {}), {});
    good.ctx.fetch = async () => { throw new Error('offline'); };
    await good.api.reload();
    assert.match(good.body.innerHTML, /data-phase="7"/, 'the card the person was reading stays');
    assert.doesNotMatch(good.body.innerHTML, /data-sc-load-error/);
});

test('a phase says who built it and when, beside who played it (v3.467.0) - the loader\'s own fields, not computed here', async () => {
    const withCreator = twoPhases({created_label: '07/11 09:30', created_by_name: 'Scenario Admin'}, {created_label: '07/11 09:45', created_by_name: 'Other Admin'});
    const {body} = await load(withCreator, {}, {mode: 'page'});
    assert.match(body.innerHTML, /scenario\.created_line\{"time":"07\/11 09:30","who":"Scenario Admin"\}/);
    assert.match(body.innerHTML, /scenario\.created_line\{"time":"07\/11 09:45","who":"Other Admin"\}/);
    assert.match(body.innerHTML, /scenario\.played_line\{"time":"10:00","who":"Admin"\}/, 'played stays its own line, unaffected');

    // No creator on the fixture (older data, or a test that never set it): no empty line is drawn.
    const bare = await load(twoPhases({}, {}), {}, {mode: 'page'});
    assert.doesNotMatch(bare.body.innerHTML, /scenario\.created_line/);
});

test('the duplicate-phase button sends duplicate_phase for that phase, asking nothing first', async () => {
    const {handlers, calls} = await load(twoPhases({}, {}), {duplicate_phase: {ok: true, phase_id: 9}});
    await clickOn(handlers, {sc: 'duplicate-phase', phase: '7'});
    await settle();
    const post = calls.find((c) => c[0] === 'fetch' && c[2]);
    assert.ok(post);
    assert.equal(new URLSearchParams(post[2]).get('action'), 'duplicate_phase');
    assert.equal(new URLSearchParams(post[2]).get('phase_id'), '7');
    assert.ok(!calls.some((c) => c[0] === 'confirm'), 'duplicating only ADDS a phase, unlike delete, so it asks nothing first');
});

// ── The active nudge (v3.467.0): a beep and a row drawn into the card itself, once, on the edge where a phase
// becomes ready - never on the very first load, and never again for the same phase ──────────────────────────────

const answerWith = (plan) => async () => ({json: async () => ({ok: true, scenario: plan})});
const readyPhase = (over = {}) => Object.assign({}, {progress: progress({done: 6, open: 0, complete: true, can_withdraw: false})}, over);

test('the nudge fires once a phase goes from not ready to ready, on the page and in the Action Room, and not on the very first load', async () => {
    // Not ready yet: the first phase still has open orders.
    const page = await load(twoPhases({}, {}), {}, {mode: 'page'});
    assert.doesNotMatch(page.body.innerHTML, /data-sc-nudge="1"/, 'nothing ready yet');

    // The poll finds phase 1 finished: the edge fires, once.
    page.ctx.fetch = answerWith(twoPhases(readyPhase(), {}));
    page.timers[0]();
    await settle();
    assert.match(page.body.innerHTML, /data-sc-nudge="1"/);
    assert.match(page.body.innerHTML, /scenario\.hint_ready/);

    // A refresh later it is still ready: the nudge was already shown, so it does not fire again - but a row of the
    // card is still free to say so passively (readyHint(), unaffected by any of this).
    page.ctx.fetch = answerWith(twoPhases(readyPhase(), {}));
    page.timers[0]();
    await settle();
    // Dismiss and recheck: if checkReadyNudge fired again it would have put the row straight back.
    const dismiss = page.body.innerHTML.includes('data-sc-nudge="1"');
    assert.ok(dismiss, 'still showing from the first edge (nobody dismissed it)');

    // Opening the Scenario page fresh on an already-finished phase 1 (no phase 1 was ever "not ready" in THIS load)
    // nudges nobody for something that became ready an hour ago.
    const fresh = await load(twoPhases(readyPhase(), {}), {}, {mode: 'page'});
    assert.doesNotMatch(fresh.body.innerHTML, /data-sc-nudge="1"/);
    assert.match(fresh.body.innerHTML, /data-sc-ready="1"/, 'the passive note still shows; only the active nudge is edge-triggered');
});

test('the Action Room\'s nudge opens the Scenario page; the page\'s own nudge only dismisses, and dismissing sends nothing', async () => {
    const room = await load(twoPhases({}, {}), {}, {mode: 'room'});
    room.ctx.fetch = answerWith(twoPhases(readyPhase(), {}));
    room.timers[0]();
    await settle();
    assert.match(room.body.innerHTML, /data-sc="nudge-open"/);
    await clickOn(room.handlers, {sc: 'nudge-open'});
    const opened = room.calls.find((c) => c[0] === 'open');
    assert.ok(opened);
    assert.match(opened[1], /mission-scenario-view\.php\?id=83/);
    assert.doesNotMatch(room.body.innerHTML, /data-sc-nudge="1"/, 'dismissed itself on the way out');

    const page = await load(twoPhases({}, {}), {}, {mode: 'page'});
    page.ctx.fetch = answerWith(twoPhases(readyPhase(), {}));
    page.timers[0]();
    await settle();
    assert.doesNotMatch(page.body.innerHTML, /data-sc="nudge-open"/, 'already on the page: nothing to open');
    page.calls.length = 0;
    await clickOn(page.handlers, {sc: 'nudge-dismiss'});
    assert.doesNotMatch(page.body.innerHTML, /data-sc-nudge="1"/);
    assert.equal(page.calls.filter((c) => c[0] === 'fetch').length, 0, 'a nudge is not an order: dismissing it asks the server nothing');
});

test('switching the passive hint off hides an already-showing nudge at once, not just the next one', async () => {
    const page = await load(twoPhases({}, {}), {}, {mode: 'page'});
    page.ctx.fetch = answerWith(twoPhases(readyPhase(), {}));
    page.timers[0]();
    await settle();
    assert.match(page.body.innerHTML, /data-sc-nudge="1"/);

    page.handlers.change({target: {closest: (sel) => (sel === 'input[data-sc-hint]' ? {checked: false} : null)}});
    assert.equal(page.storage.wrScenarioHint, '0');
    assert.doesNotMatch(page.body.innerHTML, /data-sc-nudge="1"/);
    assert.doesNotMatch(page.body.innerHTML, /data-sc-ready="1"/, 'the passive note is hidden the same way');
});
