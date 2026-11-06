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
    const ctx = vm.createContext({
        window: win,
        document: {
            getElementById: (id) => (id === 'scenarioBody' ? body : null),
            createElement: () => ({}),
            head: {appendChild() {}},
            addEventListener() {},
            dispatchEvent() {},
            hidden: false,
        },
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
    return {api: win.wrScenario, drawn, body, calls, ctx, handlers};
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
