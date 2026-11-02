// Run with: node --test tests/js
// The pure half of assets/js/war-room-ar.js: phone orientation -> where a
// point on the ground lands on the screen. The screen half needs a browser.

const test = require('node:test');
const assert = require('node:assert/strict');

const {
    arLocal, arFromLocal, arBasis, arHeading, arPitch, arFocalPx, arProject,
    arBearingElevation, arTurnTo, arSubdivide, arNearestOnPolygon, arOfferCandidate, arEventKind, arSmoothBasis, arSmoothingTau, arAngleBetween, arSmoothFix,
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

// ── steadiness: the beacon jumping left and right on a real phone ──
test('an event is a compass only when it says so, whatever came before it', () => {
    const base = {alpha: 10, beta: 90, gamma: 0};
    assert.equal(arEventKind({...base, absolute: true}), 'absolute');
    // Android sends a relative deviceorientation stream beside the absolute one; it must never be used.
    assert.equal(arEventKind({...base, absolute: false}), null);
    assert.equal(arEventKind({...base}), null);
    assert.equal(arEventKind({...base, absolute: false, webkitCompassHeading: 123}), 'ios');
    assert.equal(arEventKind({alpha: null, beta: 90, gamma: 0, absolute: true}), null);
    assert.equal(arEventKind(null), null);
});

test('smoothing the axes: all the way is the new reading, none of the way is the old one', () => {
    const north = arBasis(0, 90, 0, 0), east = arBasis(270, 90, 0, 0);
    const all = arSmoothBasis(north, east, 1), none = arSmoothBasis(north, east, 0);
    near(arHeading(all), 90); near(arHeading(none), 0);
    assert.equal(arSmoothBasis(null, east, 0.3), east);
});

test('halfway between north and east is north-east, and the axes stay unit length and square', () => {
    const b = arSmoothBasis(arBasis(0, 90, 0, 0), arBasis(270, 90, 0, 0), 0.5);
    near(arHeading(b), 45, 1e-6);
    const dot = (a, c) => a[0] * c[0] + a[1] * c[1] + a[2] * c[2];
    near(dot(b.f, b.f), 1); near(dot(b.u, b.u), 1); near(dot(b.r, b.r), 1);
    near(dot(b.f, b.u), 0); near(dot(b.f, b.r), 0); near(dot(b.u, b.r), 0);
    assert.ok(b.r[0] > 0.7, 'right is still east-ish'); // north-east view: right of it is south-east
});

test('an upright phone reports the same pose with very different alpha and gamma; smoothing must not move it', () => {
    // At beta 90 only alpha + gamma matters: these two readings are one physical pose.
    const a = arBasis(350, 90, 40, 0), b = arBasis(10, 90, 20, 0);
    near(arHeading(a), arHeading(b), 1e-9);
    // Averaging the Euler angles (alpha 180, gamma 30) would face the other way; the axes do not.
    const mid = arSmoothBasis(a, b, 0.5);
    near(arHeading(mid), arHeading(b), 1e-9);
    near(arPitch(mid), 0, 1e-9);
});

test('a steady phone with a little compass noise stays within the noise, not a few dozen degrees', () => {
    let basis = null;
    const heads = [];
    for (let i = 0; i < 120; i++) {
        const noise = (i % 2 ? 1 : -1) * 3; // plus or minus 3 degrees, every reading
        basis = arSmoothBasis(basis, arBasis(360 - (40 + noise), 90, 0, 0), 1 - Math.exp(-0.016 / 0.12));
        if (i > 30) heads.push(arHeading(basis));
    }
    const spread = Math.max(...heads) - Math.min(...heads);
    assert.ok(spread < 1.2, 'spread was ' + spread);
});

// ── a phone held still ──
test('a still phone is smoothed hard and a real turn is followed at once', () => {
    assert.ok(arSmoothingTau(0.4) > arSmoothingTau(2) && arSmoothingTau(2) > arSmoothingTau(4) && arSmoothingTau(4) > arSmoothingTau(20));
    assert.ok(arSmoothingTau(20) <= 0.08);
});

test('the angle between two camera directions', () => {
    near(arAngleBetween(arBasis(0, 90, 0, 0), arBasis(270, 90, 0, 0)), 90, 1e-9);
    near(arAngleBetween(arBasis(0, 90, 0, 0), arBasis(0, 90, 0, 0)), 0, 1e-6);
});

function run(headings, dtSec) {
    // The screen's own loop: adaptive smoothing of a reading per step.
    let basis = null;
    const out = [];
    for (const h of headings) {
        const raw = arBasis(360 - h, 90, 0, 0);
        const tau = basis ? arSmoothingTau(arAngleBetween(basis, raw)) : 0.06;
        basis = arSmoothBasis(basis, raw, 1 - Math.exp(-dtSec / tau));
        out.push(arHeading(basis));
    }
    return out;
}

test('adaptive smoothing: 3 degrees of noise on a still phone settles to well under a degree', () => {
    const readings = Array.from({length: 240}, (_, i) => 40 + (i % 3 === 0 ? 3 : (i % 3 === 1 ? -3 : 0.5)));
    const out = run(readings, 0.016).slice(60);
    assert.ok(Math.max(...out) - Math.min(...out) < 0.9, 'spread ' + (Math.max(...out) - Math.min(...out)));
});

test('adaptive smoothing: a steady 40 degrees per second turn is followed within a few degrees', () => {
    const readings = Array.from({length: 120}, (_, i) => 40 + i * 0.016 * 40);
    const out = run(readings, 0.016);
    const lag = readings[119] - out[119];
    assert.ok(lag >= 0 && lag < 7, 'lag ' + lag);
});

test('adaptive smoothing: a sudden 30 degree turn is caught up within a quarter of a second', () => {
    const readings = [...Array.from({length: 40}, () => 40), ...Array.from({length: 40}, () => 70)];
    const out = run(readings, 0.016);
    assert.ok(Math.abs(out[40 + 15] - 70) < 3, 'after 15 steps: ' + out[55]);
});

const fixAt = (northM, eastM, acc) => ({lat: 35.2 + northM / 111190, lng: 24.9 + eastM / (111190 * Math.cos(35.2 * Math.PI / 180)), acc});

test('a receiver wandering inside its own error barely moves the position', () => {
    let pos = arSmoothFix(null, fixAt(0, 0, 12), 0);
    const wander = [[6, 3], [-5, 7], [4, -6], [-7, -4], [5, 5], [-6, 2]];
    let worst = 0;
    for (const [n, e] of wander) {
        pos = arSmoothFix(pos, fixAt(n, e, 12), 0);
        const l = arLocal({lat: 35.2, lng: 24.9}, pos);
        worst = Math.max(worst, Math.hypot(l.x, l.y));
    }
    assert.ok(worst < 3.5, 'moved ' + worst + ' m on fixes that wandered up to 8 m');
});

test('a real move is followed: a fix far outside the error, and fixes while walking', () => {
    let pos = arSmoothFix(null, fixAt(0, 0, 8), 0);
    pos = arSmoothFix(pos, fixAt(60, 0, 8), 0);
    near(arLocal({lat: 35.2, lng: 24.9}, pos).y, 48, 1);
    let walk = arSmoothFix(null, fixAt(0, 0, 8), 1.4);
    for (let i = 1; i <= 8; i++) walk = arSmoothFix(walk, fixAt(i * 1.4, 0, 8), 1.4);
    assert.ok(arLocal({lat: 35.2, lng: 24.9}, walk).y > 8.5, 'kept up while walking');
});
