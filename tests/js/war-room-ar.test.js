// Run with: node --test tests/js
// The pure half of assets/js/war-room-ar.js: phone orientation -> where a
// point on the ground lands on the screen. The screen half needs a browser.

const test = require('node:test');
const assert = require('node:assert/strict');

const {
    arLocal, arFromLocal, arBasis, arHeading, arPitch, arFocalPx, arProject,
    arBearingElevation, arTurnTo, arSubdivide, arNearestOnPolygon, arOfferCandidate,
} = require('../../assets/js/war-room-ar.js');

const near = (a, b, eps = 1e-6) => assert.ok(Math.abs(a - b) <= eps, `${a} is not within ${eps} of ${b}`);

// A phone held upright (beta 90) with the camera facing the way alpha says.
test('upright phone: alpha 0 faces north, alpha 90 faces west, alpha 270 faces east', () => {
    near(arHeading(arBasis(0, 90, 0, 0)), 0);
    near(arHeading(arBasis(90, 90, 0, 0)), 270);
    near(arHeading(arBasis(270, 90, 0, 0)), 90);
    near(arHeading(arBasis(180, 90, 0, 0)), 180);
});

test('upright phone looks at the horizon; tipped back it looks up, tipped forward it looks down', () => {
    near(arPitch(arBasis(0, 90, 0, 0)), 0);
    near(arPitch(arBasis(0, 120, 0, 0)), 30);
    near(arPitch(arBasis(0, 60, 0, 0)), -30);
    near(arPitch(arBasis(0, 0, 0, 0)), -90, 1e-6); // flat on a table: camera at the floor
});

test('screen-right is east when facing north, and up is up', () => {
    const b = arBasis(0, 90, 0, 0);
    near(b.r[0], 1); near(b.r[1], 0); near(b.r[2], 0);
    near(b.u[2], 1);
});

test('a target straight ahead lands in the middle of the screen', () => {
    const b = arBasis(0, 90, 0, 0);
    const p = arProject([0, 100, 0], b, 500, 400, 800);
    near(p.x, 200); near(p.y, 400); near(p.depth, 100);
});

test('a target to the right of north appears right of centre by focal * tan(angle)', () => {
    const b = arBasis(0, 90, 0, 0);
    const d = 200, az = 30 * Math.PI / 180;
    const p = arProject([d * Math.sin(az), d * Math.cos(az), 0], b, 500, 400, 800);
    near(p.x, 200 + 500 * Math.tan(az), 1e-6);
    near(p.y, 400, 1e-6);
});

test('a target above the horizon appears above the middle of the screen', () => {
    const b = arBasis(0, 90, 0, 0);
    const p = arProject([0, 100, 20], b, 500, 400, 800);
    assert.ok(p.y < 400);
    near(p.y, 400 - 500 * 0.2, 1e-6);
});

test('something behind the phone is not drawn', () => {
    const b = arBasis(0, 90, 0, 0);
    assert.equal(arProject([0, -50, 0], b, 500, 400, 800), null);
});

test('turning the phone moves the target the opposite way across the screen', () => {
    // Target due north. Facing 20 degrees to its right (east of north), it sits to the left.
    const facingEast20 = arBasis(360 - 20, 90, 0, 0);
    near(arHeading(facingEast20), 20);
    const p = arProject([0, 100, 0], facingEast20, 500, 400, 800);
    assert.ok(p.x < 200);
    near(p.x, 200 - 500 * Math.tan(20 * Math.PI / 180), 1e-6);
});

test('a phone turned to landscape keeps looking the same way and its «up» follows the screen', () => {
    const portrait = arBasis(0, 90, 0, 0);
    // Rotated a quarter turn counter-clockwise: the phone's right edge is now on top.
    const landscape = arBasis(0, 90, 0, 90);
    near(arHeading(landscape), arHeading(portrait));
    near(landscape.u[0], portrait.r[0]);
    // A target to the east is on the screen's top half in this pose.
    const east = arProject([100, 100, 0], landscape, 500, 800, 400);
    assert.ok(east.y < 200);
});

test('upright, gamma turns the phone about its vertical axis, so the camera yaws', () => {
    // gamma is the tilt about the phone's long axis; held upright that is a turn left or right.
    const b = arBasis(0, 90, 20, 0);
    near(arHeading(b), 340);
    near(arPitch(b), 0);
});

test('arLocal and arFromLocal are inverses, and a degree of latitude is about 111 km', () => {
    const o = {lat: 35.2, lng: 24.9};
    const q = {lat: 35.2 + 0.001, lng: 24.9 + 0.002};
    const l = arLocal(o, q);
    near(l.y, 111.19, 0.5);
    assert.ok(l.x > 0);
    const back = arFromLocal(o, l.x, l.y);
    near(back.lat, q.lat, 1e-7); near(back.lng, q.lng, 1e-6);
});

test('arBearingElevation: east is 90, up 45 degrees at equal distance', () => {
    const e = arBearingElevation([100, 0, 100]);
    near(e.az, 90); near(e.el, 45); near(e.d, 100);
});

test('arTurnTo takes the short way round', () => {
    assert.equal(arTurnTo(350, 10), 20);
    assert.equal(arTurnTo(10, 350), -20);
    assert.equal(arTurnTo(90, 90), 0);
});

test('arSubdivide cuts edges into pieces no longer than the limit and closes the loop', () => {
    const sq = [{x: 0, y: 0, z: 0}, {x: 100, y: 0, z: 10}, {x: 100, y: 100, z: 10}, {x: 0, y: 100, z: 0}];
    const open = arSubdivide(sq, 30, false);
    const closed = arSubdivide(sq, 30, true);
    assert.equal(open.length, 4 + 4 + 4);   // three edges of 100 m, four pieces each
    assert.equal(closed.length, 16);
    open.forEach(([a, b]) => assert.ok(Math.hypot(b.x - a.x, b.y - a.y) <= 30 + 1e-9));
    near(open[3][1].z, 10); // the first edge climbs 10 m and the pieces carry the height
});

test('arNearestOnPolygon: from outside it is the closest outline point; from inside the closest wall', () => {
    const me = {lat: 35.2, lng: 24.9};
    // A square roughly 100 m a side, its near edge 50 m north of me.
    const d = (m) => m / 111190;
    const dl = (m) => m / (111190 * Math.cos(35.2 * Math.PI / 180)); // a degree of longitude is shorter
    const geo = [
        [35.2 + d(50), 24.9 - dl(50)], [35.2 + d(50), 24.9 + dl(50)],
        [35.2 + d(150), 24.9 + dl(50)], [35.2 + d(150), 24.9 - dl(50)],
    ];
    const out = arNearestOnPolygon(me, geo);
    near(out.dist, 50, 1);
    near(out.lat, 35.2 + d(50), 1e-5);
    const inside = arNearestOnPolygon({lat: 35.2 + d(100), lng: 24.9}, geo);
    near(inside.dist, 50, 1);
});

test('focal length makes the long side of the screen span the field of view', () => {
    const f = arFocalPx(400, 800, 64);
    near(2 * Math.atan(400 / f) * 180 / Math.PI, 64, 1e-6);
    assert.equal(arFocalPx(800, 400, 64), f); // landscape: same camera, same long side
});

// ── the one-time «Δες τον με AR» offer ──
const offerMe = {lat: 35.2, lng: 24.9};
const at = (metres) => ({lat: 35.2 + metres / 111190, lng: 24.9});
const order = (over) => Object.assign({outstanding: true, acked: true, target: {kind: 'point', nav: at(120)}}, over);

test('an offer goes to the first received open order within reach', () => {
    const entries = new Map([['dispatch:1', order({})], ['sector:2', order({target: {kind: 'point', nav: at(60)}})]]);
    assert.equal(arOfferCandidate(offerMe, entries, [], 150), entries.get('dispatch:1'));
});

test('nothing is offered for an order that is too far, not yet received, finished, without a place, or already offered', () => {
    const far = order({target: {kind: 'point', nav: at(400)}});
    const fresh = order({acked: false});
    const done = order({outstanding: false});
    const noPlace = order({target: null});
    const again = order({});
    const entries = new Map([['a', far], ['b', fresh], ['c', done], ['d', noPlace], ['e', again]]);
    assert.equal(arOfferCandidate(offerMe, entries, ['e'], 150), null);
});

test('the offer is for an order not yet offered, so an order already offered gives way to the next', () => {
    const entries = new Map([['one', order({})], ['two', order({})]]);
    assert.equal(arOfferCandidate(offerMe, entries, ['one'], 150), entries.get('two'));
});
