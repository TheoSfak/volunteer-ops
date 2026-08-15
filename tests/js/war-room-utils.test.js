// Run with: node --test tests/js
// No npm dependency - Node's built-in test runner (stable since Node 18/20).

const test = require('node:test');
const assert = require('node:assert/strict');

// war-room-utils.js's formatDistanceMeters/bearingToCompassAbbr/
// missingRouteDeliverablesClientSide call the page-wide t() translation
// helper as a global (see includes/i18n.php + war-room.php's own t()) -
// stubbed here with the real war-room lang keys so assertions mean something.
global.t = function (key) {
    const strings = {
        'common.unit_m': 'μ.',
        'common.unit_km': 'χλμ.',
        'compass.n': 'Β', 'compass.ne': 'ΒΑ', 'compass.e': 'Α', 'compass.se': 'ΝΑ',
        'compass.s': 'Ν', 'compass.sw': 'ΝΔ', 'compass.w': 'Δ', 'compass.nw': 'ΒΔ',
        'route.deliverable_photo': 'φωτογραφία',
        'route.deliverable_video': 'βίντεο',
        'route.deliverable_note': 'σημείωση',
    };
    return strings[key] ?? key;
};

const {
    bearing,
    destinationPoint,
    circleToPolygonPoints,
    sectorSearchLegPoints,
    sectorSearchLegCount,
    ringDiscPolygonPoints,
    weightedWedgePolygonPoints,
    escapeHtml,
    parseCoordsInput,
    formatDistanceMeters,
    bearingToCompassAbbr,
    missingRouteDeliverablesClientSide,
    shouldSkipVideoCompression,
    pickVideoCompressionMimeType,
    videoExtensionForMimeType,
    shouldSkipPhotoCompression,
} = require('../../assets/js/war-room-utils.js');

// Local-only Haversine, not exported by war-room-utils.js — this file has no
// distance function to import (bearing() only computes direction), so this
// is purely a test-side check that destinationPoint()/circleToPolygonPoints()
// actually land where they claim to.
function haversineMeters(a, b) {
    const R = 6371000;
    const dLat = (b.lat - a.lat) * Math.PI / 180;
    const dLng = (b.lng - a.lng) * Math.PI / 180;
    const lat1 = a.lat * Math.PI / 180, lat2 = b.lat * Math.PI / 180;
    const h = Math.sin(dLat / 2) ** 2 + Math.cos(lat1) * Math.cos(lat2) * Math.sin(dLng / 2) ** 2;
    return 2 * R * Math.asin(Math.sqrt(h));
}

test('bearing() points east from due-west movement', () => {
    const deg = bearing({ lat: 0, lng: 0 }, { lat: 0, lng: 1 });
    assert.ok(Math.abs(deg - 90) < 0.01, `expected ~90, got ${deg}`);
});

test('bearing() points north', () => {
    const deg = bearing({ lat: 0, lng: 0 }, { lat: 1, lng: 0 });
    assert.ok(Math.abs(deg - 0) < 0.01, `expected ~0, got ${deg}`);
});

test('destinationPoint() bearing 0 moves due north (lng unchanged)', () => {
    const start = { lat: 35.0, lng: 24.0 };
    const end = destinationPoint(start, 0, 1000);
    assert.ok(end.lat > start.lat, `expected lat to increase, got ${end.lat}`);
    assert.ok(Math.abs(end.lng - start.lng) < 1e-9, `expected lng unchanged, got ${end.lng}`);
});

test('destinationPoint() bearing 90 moves due east (lat ~unchanged)', () => {
    const start = { lat: 35.0, lng: 24.0 };
    const end = destinationPoint(start, 90, 1000);
    assert.ok(end.lng > start.lng, `expected lng to increase, got ${end.lng}`);
    assert.ok(Math.abs(end.lat - start.lat) < 0.001, `expected lat ~unchanged, got ${end.lat}`);
});

test('destinationPoint() lands ~distanceMeters away, per independent Haversine check', () => {
    const start = { lat: 35.0, lng: 24.0 };
    for (const bearingDeg of [0, 45, 90, 180, 270]) {
        const end = destinationPoint(start, bearingDeg, 2000);
        const dist = haversineMeters(start, end);
        assert.ok(Math.abs(dist - 2000) < 1, `bearing ${bearingDeg}: expected ~2000m, got ${dist}`);
    }
});

test('circleToPolygonPoints() returns numPoints points, each ~radiusMeters from center', () => {
    const center = { lat: 35.0, lng: 24.0 };
    const radius = 800;
    const points = circleToPolygonPoints(center, radius, 12);
    assert.equal(points.length, 12);
    for (const [lat, lng] of points) {
        const dist = haversineMeters(center, { lat, lng });
        assert.ok(Math.abs(dist - radius) < 1, `expected ~${radius}m, got ${dist}`);
    }
});

test('circleToPolygonPoints() spaces points evenly around the circle', () => {
    const center = { lat: 35.0, lng: 24.0 };
    const radius = 500;
    const points = circleToPolygonPoints(center, radius, 4);
    // 4 points 90° apart on a circle: adjacent points (p0-p1) are a chord of
    // 2r·sin(45°) ≈ 707m apart; the opposite point (p0-p2) is the full
    // diameter, 2r = 1000m. Checking both distinguishes "evenly spaced
    // around the circle" from points bunched up or duplicated.
    const [p0, p1, p2] = points.map(([lat, lng]) => ({ lat, lng }));
    const dAdjacent = haversineMeters(p0, p1);
    const dOpposite = haversineMeters(p0, p2);
    assert.ok(Math.abs(dAdjacent - radius * Math.SQRT2) < 1, `expected ~${radius * Math.SQRT2}m between adjacent points, got ${dAdjacent}`);
    assert.ok(Math.abs(dOpposite - radius * 2) < 1, `expected ~${radius * 2}m between opposite points, got ${dOpposite}`);
});

test('sectorSearchLegPoints() returns legCount*2 points (one leg = near point + far point)', () => {
    const center = { lat: 35.0, lng: 24.0 };
    const points = sectorSearchLegPoints(center, 500, 2000, 6);
    assert.equal(points.length, 12);
});

// The bug this (and the concentric-arc version before it) replaced: a
// center-to-edge spiral always starts at radius 0 regardless of which ring
// it's sweeping, so for ring 2/3/4 it re-walked ground already assigned to
// the smaller ring(s) inside it. Every point staying >= innerRadiusMeters
// out is the regression test for that fix.
test('sectorSearchLegPoints() never places a point closer to center than innerRadiusMeters', () => {
    const center = { lat: 35.0, lng: 24.0 };
    const innerRadius = 800;
    const points = sectorSearchLegPoints(center, innerRadius, 3500, 6);
    for (const [lat, lng] of points) {
        const dist = haversineMeters(center, { lat, lng });
        assert.ok(dist >= innerRadius - 1, `expected >= ${innerRadius}m from center, got ${dist}`);
    }
});

// Unlike the old concentric arcs (only the outermost lane touched the outer
// boundary exactly), every leg here is a straight line FROM the inner
// radius TO the outer radius, so every single point should land on one or
// the other, exactly — no in-between arc samples to approximate.
test('sectorSearchLegPoints() every point lands exactly on innerRadiusMeters or outerRadiusMeters', () => {
    const center = { lat: 35.0, lng: 24.0 };
    const innerRadius = 800, outerRadius = 3500;
    const points = sectorSearchLegPoints(center, innerRadius, outerRadius, 6);
    for (const [lat, lng] of points) {
        const dist = haversineMeters(center, { lat, lng });
        const onInner = Math.abs(dist - innerRadius) < 1;
        const onOuter = Math.abs(dist - outerRadius) < 1;
        assert.ok(onInner || onOuter, `expected ${dist}m to be ~${innerRadius}m or ~${outerRadius}m from center`);
    }
});

// The bug a first attempt at this function actually shipped with: using
// legCount/(legCount-1) spacing (inclusive of both ends) for a FULL circle
// makes the last leg land back on the exact same bearing as the first
// (t=1 → sweepDeg=360, same physical direction as t=0) — e.g. 4 "legs"
// collapsing to 3 distinct bearings, a triangle instead of a square. Full-
// circle spacing must be EXCLUSIVE (sweepDeg/legCount) so legCount distinct
// bearings actually result.
test('sectorSearchLegPoints() full circle produces legCount distinct bearings, not legCount-1', () => {
    const center = { lat: 35.0, lng: 24.0 };
    const legCount = 4;
    const points = sectorSearchLegPoints(center, 500, 2000, legCount);
    const legBearings = [];
    for (let i = 0; i < legCount; i++) {
        legBearings.push(Math.round(bearing(center, { lat: points[i * 2][0], lng: points[i * 2][1] })));
    }
    const distinctBearings = new Set(legBearings);
    assert.equal(distinctBearings.size, legCount, `expected ${legCount} distinct bearings, got ${[...distinctBearings]}`);
});

test('ringDiscPolygonPoints() returns numPoints+2 points: center, boundary, then a repeat of the first boundary point', () => {
    const center = { lat: 35.0, lng: 24.0 };
    const points = ringDiscPolygonPoints(center, 500, 8);
    assert.equal(points.length, 10);
    assert.deepEqual(points[0], [center.lat, center.lng]);
    assert.deepEqual(points[9], points[1]); // the seam duplicate
});

// Regression test for a real geometry bug caught before shipping: a plain
// [center, ...boundary] array (no duplicated seam point) closes from the
// LAST boundary point straight back to center, never back to the FIRST
// boundary point - silently excluding one boundary arc's worth of area from
// the shape, with no way to recover it afterward (a straight cut between
// two existing vertices can only ever split area a polygon already has).
// Checked via shoelace area rather than eyeballing coordinates, since this
// is exactly the kind of bug that looks fine in a screenshot (a circle of
// vertex markers still renders) but leaves one slice permanently uncuttable.
test('ringDiscPolygonPoints() traces the full disc, not (numPoints-1)/numPoints of it', () => {
    const center = { lat: 0, lng: 0 };
    const points = ringDiscPolygonPoints(center, 1000, 8);
    const shoelaceArea = poly => Math.abs(poly.reduce((sum, [x1, y1], i) => {
        const [x2, y2] = poly[(i + 1) % poly.length];
        return sum + (x1 * y2 - x2 * y1);
    }, 0)) / 2;
    const fullDiscArea = shoelaceArea(points);
    const missingSeamArea = shoelaceArea(points.slice(0, -1)); // the old, buggy shape
    assert.ok(fullDiscArea > missingSeamArea * 1.05, `expected the seam-duplicated polygon (${fullDiscArea}) to enclose meaningfully more area than the un-duplicated one (${missingSeamArea})`);
});

test('sectorSearchLegPoints() with no bearing args matches explicit (0, 360) exactly', () => {
    // Regression check: startBearingDeg/sweepDeg default to a full circle so
    // openInteriorSweepForRing()'s 4-argument call keeps sweeping the whole
    // ring unchanged.
    const center = { lat: 35.0, lng: 24.0 };
    const withDefaults = sectorSearchLegPoints(center, 500, 2000, 6);
    const withExplicitFullCircle = sectorSearchLegPoints(center, 500, 2000, 6, 0, 360);
    assert.deepEqual(withDefaults, withExplicitFullCircle);
});

test('sectorSearchLegPoints() confines every point to [startBearingDeg, startBearingDeg+sweepDeg] when given a wedge', () => {
    const center = { lat: 35.0, lng: 24.0 };
    const startBearingDeg = 90, sweepDeg = 90;
    const points = sectorSearchLegPoints(center, 500, 2000, 6, startBearingDeg, sweepDeg);
    for (const [lat, lng] of points) {
        const b = bearing(center, { lat, lng });
        assert.ok(b >= startBearingDeg - 0.01 && b <= startBearingDeg + sweepDeg + 0.01, `expected bearing ${b} within [${startBearingDeg}, ${startBearingDeg + sweepDeg}]`);
    }
});

// A wedge (sweepDeg < 360) uses INCLUSIVE spacing instead — unlike the full
// circle, there's no wrap-around duplicate to avoid, and a team's route
// should actually reach both edges of their assigned wedge.
test('sectorSearchLegPoints() wedge spacing reaches both edge bearings exactly', () => {
    const center = { lat: 35.0, lng: 24.0 };
    const startBearingDeg = 90, sweepDeg = 90, legCount = 4;
    const points = sectorSearchLegPoints(center, 500, 2000, legCount, startBearingDeg, sweepDeg);
    const firstLegBearing = bearing(center, { lat: points[0][0], lng: points[0][1] });
    const lastLegBearing = bearing(center, { lat: points[points.length - 2][0], lng: points[points.length - 2][1] });
    assert.ok(Math.abs(firstLegBearing - startBearingDeg) < 0.01, `expected first leg at ${startBearingDeg}, got ${firstLegBearing}`);
    assert.ok(Math.abs(lastLegBearing - (startBearingDeg + sweepDeg)) < 0.01, `expected last leg at ${startBearingDeg + sweepDeg}, got ${lastLegBearing}`);
});

test('sectorSearchLegCount() targets 45° steps for a full circle (8 legs), well above doctrine\'s 3-leg minimum', () => {
    assert.equal(sectorSearchLegCount(360), 8);
});

test('sectorSearchLegCount() floors at 3 (doctrine\'s own minimum) even for a narrow wedge', () => {
    assert.equal(sectorSearchLegCount(90), 3);
    assert.equal(sectorSearchLegCount(10), 3);
});

test('weightedWedgePolygonPoints() returns numPoints+1 points: center, then the boundary arc', () => {
    const center = { lat: 35.0, lng: 24.0 };
    const points = weightedWedgePolygonPoints(center, 1000, 45, 90, 6);
    assert.equal(points.length, 7);
    assert.deepEqual(points[0], [center.lat, center.lng]);
});

// A wedge's boundary sampling is INCLUSIVE of both endpoints (unlike
// circleToPolygonPoints()'s exclusive-of-the-wrap-around sampling, which is
// what forced ringDiscPolygonPoints() to duplicate a seam point above) - so
// a standalone wedge needs no such trick. Checked the same way as that
// regression test: shoelace area, not eyeballed coordinates, since this is
// exactly the kind of bug a screenshot could miss.
test('weightedWedgePolygonPoints() area is proportional to sweepDeg, no seam gap', () => {
    const center = { lat: 0, lng: 0 };
    const radius = 1000, numPoints = 16;
    const shoelaceArea = poly => Math.abs(poly.reduce((sum, [x1, y1], i) => {
        const [x2, y2] = poly[(i + 1) % poly.length];
        return sum + (x1 * y2 - x2 * y1);
    }, 0)) / 2;
    const fullDiscArea = shoelaceArea(weightedWedgePolygonPoints(center, radius, 0, 360, numPoints));
    const quarterWedgeArea = shoelaceArea(weightedWedgePolygonPoints(center, radius, 0, 90, numPoints));
    const ratio = quarterWedgeArea / fullDiscArea;
    assert.ok(Math.abs(ratio - 0.25) < 0.02, `expected a 90° wedge to enclose ~1/4 of the full disc's area, got ratio ${ratio}`);
});

test('escapeHtml() escapes all five special characters', () => {
    assert.equal(escapeHtml(`<a href="x">'&'</a>`), '&lt;a href=&quot;x&quot;&gt;&#39;&amp;&#39;&lt;/a&gt;');
});

test('escapeHtml() treats null/undefined as empty string', () => {
    assert.equal(escapeHtml(null), '');
    assert.equal(escapeHtml(undefined), '');
});

test('parseCoordsInput() accepts a comma-space pair', () => {
    assert.deepEqual(parseCoordsInput('35.3387, 25.1442'), { lat: 35.3387, lng: 25.1442 });
});

test('parseCoordsInput() accepts a bare-space pair', () => {
    assert.deepEqual(parseCoordsInput('35.3387 25.1442'), { lat: 35.3387, lng: 25.1442 });
});

test('parseCoordsInput() rejects out-of-range latitude', () => {
    assert.equal(parseCoordsInput('91, 25'), null);
});

test('parseCoordsInput() rejects out-of-range longitude', () => {
    assert.equal(parseCoordsInput('35, 181'), null);
});

test('parseCoordsInput() rejects exactly 0,0', () => {
    assert.equal(parseCoordsInput('0,0'), null);
});

test('parseCoordsInput() rejects non-numeric garbage', () => {
    assert.equal(parseCoordsInput('not coordinates'), null);
});

test('parseCoordsInput() rejects a single number', () => {
    assert.equal(parseCoordsInput('35.3387'), null);
});

test('formatDistanceMeters() shows meters under 1000', () => {
    assert.equal(formatDistanceMeters(432), '432 μ.');
});

test('formatDistanceMeters() shows km at and above 1000', () => {
    assert.equal(formatDistanceMeters(1500), '1.5 χλμ.');
});

test('formatDistanceMeters() returns empty string for null/undefined', () => {
    assert.equal(formatDistanceMeters(null), '');
    assert.equal(formatDistanceMeters(undefined), '');
});

test('bearingToCompassAbbr() maps 0 degrees to North', () => {
    assert.equal(bearingToCompassAbbr(0), 'Β');
});

test('bearingToCompassAbbr() maps 90 degrees to East', () => {
    assert.equal(bearingToCompassAbbr(90), 'Α');
});

test('bearingToCompassAbbr() wraps 360 back to North', () => {
    assert.equal(bearingToCompassAbbr(360), 'Β');
});

test('missingRouteDeliverablesClientSide() flags nothing when nothing is required', () => {
    const wp = { require_photo: false, require_video: false, require_note: false };
    assert.deepEqual(missingRouteDeliverablesClientSide(wp, ''), []);
});

test('missingRouteDeliverablesClientSide() flags a missing required photo', () => {
    const wp = { require_photo: true, photo: null, require_video: false, require_note: false };
    assert.deepEqual(missingRouteDeliverablesClientSide(wp, ''), ['φωτογραφία']);
});

test('missingRouteDeliverablesClientSide() accepts a note typed in the field even if wp.note is empty', () => {
    const wp = { require_photo: false, require_video: false, require_note: true, note: '' };
    assert.deepEqual(missingRouteDeliverablesClientSide(wp, 'typed just now'), []);
});

test('missingRouteDeliverablesClientSide() rejects a whitespace-only note', () => {
    const wp = { require_photo: false, require_video: false, require_note: true, note: '' };
    assert.deepEqual(missingRouteDeliverablesClientSide(wp, '   '), ['σημείωση']);
});

test('missingRouteDeliverablesClientSide() can flag all three at once', () => {
    const wp = { require_photo: true, photo: null, require_video: true, video: null, require_note: true, note: '' };
    assert.deepEqual(missingRouteDeliverablesClientSide(wp, ''), ['φωτογραφία', 'βίντεο', 'σημείωση']);
});

test('shouldSkipVideoCompression() skips a file at or under the 4MB floor', () => {
    assert.equal(shouldSkipVideoCompression(4 * 1024 * 1024, 6), true);
    assert.equal(shouldSkipVideoCompression(1024, 6), true);
});

test('shouldSkipVideoCompression() attempts compression for a large-enough, short-enough video', () => {
    assert.equal(shouldSkipVideoCompression(20 * 1024 * 1024, 6), false);
});

test('shouldSkipVideoCompression() attempts compression exactly at the 120s ceiling', () => {
    assert.equal(shouldSkipVideoCompression(20 * 1024 * 1024, 120), false);
});

test('shouldSkipVideoCompression() skips once duration exceeds the 120s ceiling', () => {
    assert.equal(shouldSkipVideoCompression(20 * 1024 * 1024, 121), true);
});

test('shouldSkipVideoCompression() skips unknown/malformed duration rather than assuming it is short', () => {
    assert.equal(shouldSkipVideoCompression(20 * 1024 * 1024, NaN), true);
    assert.equal(shouldSkipVideoCompression(20 * 1024 * 1024, 0), true);
    assert.equal(shouldSkipVideoCompression(20 * 1024 * 1024, Infinity), true);
});

test('pickVideoCompressionMimeType() returns the first supported candidate in priority order', () => {
    const candidates = ['video/mp4', 'video/webm;codecs=vp8,opus', 'video/webm'];
    const isSupported = mt => mt !== 'video/mp4';
    assert.equal(pickVideoCompressionMimeType(candidates, isSupported), 'video/webm;codecs=vp8,opus');
});

test('pickVideoCompressionMimeType() returns null when nothing is supported', () => {
    assert.equal(pickVideoCompressionMimeType(['video/mp4', 'video/webm'], () => false), null);
});

test('videoExtensionForMimeType() maps an mp4 mimeType (with codecs) to mp4', () => {
    assert.equal(videoExtensionForMimeType('video/mp4;codecs=h264,aac'), 'mp4');
});

test('videoExtensionForMimeType() maps a webm mimeType to webm', () => {
    assert.equal(videoExtensionForMimeType('video/webm;codecs=vp8,opus'), 'webm');
});

test('videoExtensionForMimeType() defaults to webm for empty/missing input', () => {
    assert.equal(videoExtensionForMimeType(''), 'webm');
    assert.equal(videoExtensionForMimeType(null), 'webm');
});

test('shouldSkipPhotoCompression() skips a file at or under the 1.5MB floor', () => {
    assert.equal(shouldSkipPhotoCompression(1.5 * 1024 * 1024, 'image/jpeg'), true);
    assert.equal(shouldSkipPhotoCompression(1024, 'image/jpeg'), true);
});

test('shouldSkipPhotoCompression() attempts compression for a large-enough jpeg', () => {
    assert.equal(shouldSkipPhotoCompression(5 * 1024 * 1024, 'image/jpeg'), false);
});

test('shouldSkipPhotoCompression() attempts compression for a large-enough png/webp', () => {
    assert.equal(shouldSkipPhotoCompression(5 * 1024 * 1024, 'image/png'), false);
    assert.equal(shouldSkipPhotoCompression(5 * 1024 * 1024, 'image/webp'), false);
});

test('shouldSkipPhotoCompression() always skips gif, even when large, to protect animation', () => {
    assert.equal(shouldSkipPhotoCompression(5 * 1024 * 1024, 'image/gif'), true);
});
