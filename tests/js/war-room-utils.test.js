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
        'common.unit_area_m2': 'τ.μ.',
        'common.unit_area_mid': 'στρ.',
        'common.unit_area_mid_divisor': '1000',
        'common.unit_area_km2': 'τ.χλμ.',
        'common.number_locale': 'el-GR',
        'compass.n': 'Β', 'compass.ne': 'ΒΑ', 'compass.e': 'Α', 'compass.se': 'ΝΑ',
        'compass.s': 'Ν', 'compass.sw': 'ΝΔ', 'compass.w': 'Δ', 'compass.nw': 'ΒΔ',
        'route.deliverable_photo': 'φωτογραφία',
        'route.deliverable_video': 'βίντεο',
        'route.deliverable_note': 'σημείωση',
    };
    return strings[key] ?? key;
};

const {
    shareablePayload,
    isPointerOnlyComputer,
    bearing,
    destinationPoint,
    circleToPolygonPoints,
    sectorSearchLegPoints,
    sectorSearchLegCount,
    ringDiscPolygonPoints,
    weightedWedgePolygonPoints,
    annularWedgePolygonPoints,
    escapeHtml,
    parseCoordsInput,
    polygonAreaSquareMeters,
    metersPerDegreeLat,
    metersPerDegreeLng,
    gridCellsForPolygon,
    pointAtRingPos,
    splitRingAtCutPositions,
    formatAreaSquareMeters,
    areaTierForGroup,
    formatDistanceMeters,
    bearingToCompassAbbr,
    missingRouteDeliverablesClientSide,
    shouldSkipVideoCompression,
    videoTooLongToReencode,
    pickVideoCompressionMimeType,
    videoExtensionForMimeType,
    videoCompressionMimeCandidates,
    isUnshareableVideoContainer,
    MP4_RECORDER_MIME_CANDIDATES,
    shouldSkipPhotoCompression,
    speechChunks,
    SPEECH_CHUNK_CHARS,
    speechKeepAliveIsSafe,
    speechPieceTimeoutMs,
    polygonBoundsSizeMeters,
    formatBoundsSize,
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

// Ring 0 has no ring inside it, so its "band" is the whole disc and the
// shape must stay exactly what weightedWedgePolygonPoints() produced —
// otherwise this change would silently alter the one ring that was never
// wrong.
test('annularWedgePolygonPoints() with innerRadius 0 is the same pie slice as weightedWedgePolygonPoints()', () => {
    const center = { lat: 35.0, lng: 24.0 };
    assert.deepEqual(
        annularWedgePolygonPoints(center, 0, 1000, 45, 90, 6),
        weightedWedgePolygonPoints(center, 1000, 45, 90, 6)
    );
});

test('annularWedgePolygonPoints() with an inner radius returns 2*numPoints vertices and no center point', () => {
    const center = { lat: 35.0, lng: 24.0 };
    const points = annularWedgePolygonPoints(center, 500, 1000, 45, 90, 6);
    assert.equal(points.length, 12);
    assert.ok(
        !points.some(([lat, lng]) => lat === center.lat && lng === center.lng),
        'an annular wedge must not include the center vertex — that is the whole point of it'
    );
});

// The actual regression this shape exists to fix. The old full-disc wedge
// handed a team ground from the datum outward, so verified coverage graded
// their GPS against the inner rings' ground too. A band must exclude its own
// hole — checked with the same ray-casting rule pointInPolygon() uses
// server-side (functions-warroom.php), since that is what sector coverage
// actually runs.
test('annularWedgePolygonPoints() excludes the datum and the inner ring, but contains the band', () => {
    const center = { lat: 0, lng: 0 };
    const pointInPolygon = (lat, lng, geo) => {
        let inside = false;
        for (let i = 0, j = geo.length - 1; i < geo.length; j = i++) {
            const [latI, lngI] = geo[i], [latJ, lngJ] = geo[j];
            if (((latI > lat) !== (latJ > lat))
                && (lng < (lngJ - lngI) * (lat - latI) / (latJ - latI) + lngI)) {
                inside = !inside;
            }
        }
        return inside;
    };
    // Full 360 band, the single-eligible-team case that closes into a slit
    // annulus — the hardest one for the parity rule to get right.
    const band = annularWedgePolygonPoints(center, 3000, 6000, 0, 360, 24);
    const northAt = m => [m / 111320, 0.0001];
    assert.equal(pointInPolygon(0, 0, band), false, 'the datum itself must be outside the band');
    assert.equal(pointInPolygon(...northAt(1500), band), false, 'inside the inner ring must be outside the band');
    assert.equal(pointInPolygon(...northAt(4500), band), true, 'mid-band must be inside');
    assert.equal(pointInPolygon(...northAt(7500), band), false, 'beyond the outer radius must be outside');
});

test('annularWedgePolygonPoints() band area is the annulus area, scaled by sweepDeg', () => {
    const center = { lat: 0, lng: 0 };
    const shoelaceArea = poly => Math.abs(poly.reduce((sum, [x1, y1], i) => {
        const [x2, y2] = poly[(i + 1) % poly.length];
        return sum + (x1 * y2 - x2 * y1);
    }, 0)) / 2;
    const fullBand = shoelaceArea(annularWedgePolygonPoints(center, 500, 1000, 0, 360, 64));
    const fullDisc = shoelaceArea(weightedWedgePolygonPoints(center, 1000, 0, 360, 64));
    const innerDisc = shoelaceArea(weightedWedgePolygonPoints(center, 500, 0, 360, 64));
    assert.ok(
        Math.abs(fullBand - (fullDisc - innerDisc)) / (fullDisc - innerDisc) < 0.02,
        `expected the band to enclose outer-minus-inner area, got ${fullBand} vs ${fullDisc - innerDisc}`
    );
    const quarterBand = shoelaceArea(annularWedgePolygonPoints(center, 500, 1000, 0, 90, 64));
    const ratio = quarterBand / fullBand;
    assert.ok(Math.abs(ratio - 0.25) < 0.02, `expected a 90° band wedge to be ~1/4 of the full band, got ${ratio}`);
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

test('videoTooLongToReencode() allows a clip exactly at the 120s ceiling', () => {
    assert.equal(videoTooLongToReencode(120), false);
});

// A MediaRecorder WebM routinely has no duration in its header, so
// <video>.duration reads NaN or Infinity. Treating that as "too long" meant
// the rewrap refused the exact files it exists to convert, and the WebM went
// to Viber unchanged. Size stands in for duration in that case.
test('videoTooLongToReencode() converts a header-less clip when its size is sane', () => {
    assert.equal(videoTooLongToReencode(NaN, 1810), false);
    assert.equal(videoTooLongToReencode(Infinity, 3 * 1024 * 1024), false);
    assert.equal(videoTooLongToReencode(0, 25 * 1024 * 1024), false);
});

test('videoTooLongToReencode() still refuses a header-less clip that is too big to bound', () => {
    assert.equal(videoTooLongToReencode(NaN, 25 * 1024 * 1024 + 1), true);
    // No duration and no size is genuinely no information — stay conservative.
    assert.equal(videoTooLongToReencode(NaN, NaN), true);
    assert.equal(videoTooLongToReencode(NaN, 0), true);
    assert.equal(videoTooLongToReencode(NaN, undefined), true);
});

test('videoTooLongToReencode() rejects past the ceiling and on unusable durations', () => {
    assert.equal(videoTooLongToReencode(121), true);
    // A real duration always wins over size — a 3-minute clip is refused no
    // matter how small the file is.
    assert.equal(videoTooLongToReencode(180, 1024), true);
});

test('videoCompressionMimeCandidates() prefers every mp4 spelling before any webm', () => {
    const all = videoCompressionMimeCandidates(false);
    const firstWebm = all.findIndex(mt => mt.indexOf('webm') !== -1);
    const lastMp4 = all.map(mt => mt.indexOf('mp4') !== -1).lastIndexOf(true);
    assert.ok(lastMp4 < firstWebm);
    assert.equal(all.length, MP4_RECORDER_MIME_CANDIDATES.length + 2);
});

test('videoCompressionMimeCandidates(true) offers no webm fallback at all', () => {
    const mp4Only = videoCompressionMimeCandidates(true);
    assert.deepEqual(mp4Only, MP4_RECORDER_MIME_CANDIDATES);
    assert.equal(mp4Only.some(mt => mt.indexOf('webm') !== -1), false);
});

test('videoCompressionMimeCandidates() hands back a copy, not the shared constant', () => {
    videoCompressionMimeCandidates(true).push('video/bogus');
    assert.equal(MP4_RECORDER_MIME_CANDIDATES.includes('video/bogus'), false);
});

test('isUnshareableVideoContainer() flags webm by mimeType or by filename', () => {
    assert.equal(isUnshareableVideoContainer('video/webm;codecs=vp8,opus', 'field-52.webm'), true);
    assert.equal(isUnshareableVideoContainer('', 'field-52.webm'), true);
    assert.equal(isUnshareableVideoContainer('application/octet-stream', 'CLIP.WEBM'), true);
    assert.equal(isUnshareableVideoContainer('video/webm', ''), true);
});

test('isUnshareableVideoContainer() leaves mp4/mov alone', () => {
    assert.equal(isUnshareableVideoContainer('video/mp4', 'field-52.mp4'), false);
    assert.equal(isUnshareableVideoContainer('video/quicktime', 'clip.mov'), false);
    assert.equal(isUnshareableVideoContainer(null, null), false);
});

test('isUnshareableVideoContainer() does not flag a non-webm name merely containing webm', () => {
    assert.equal(isUnshareableVideoContainer('video/mp4', 'webm-export.mp4'), false);
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

// The media Share control is dropped on mouse-driven computers, where the OS
// share sheet will not deliver the file. These guard the far more damaging
// direction: never strip it from a phone in the field.
const PC = {hasNativeBridge: false, uaDataMobile: false, userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120', maxTouchPoints: 0, hasFinePointer: true};

test('isPointerOnlyComputer() identifies a plain Windows desktop', () => {
    assert.strictEqual(isPointerOnlyComputer(PC), true);
});

test('isPointerOnlyComputer() identifies a touchscreen Windows laptop, which has the same broken share sheet', () => {
    assert.strictEqual(isPointerOnlyComputer({...PC, maxTouchPoints: 10}), true);
});

test('isPointerOnlyComputer() never strips the button inside the native Android app', () => {
    assert.strictEqual(isPointerOnlyComputer({...PC, hasNativeBridge: true}), false);
});

test('isPointerOnlyComputer() never strips the button on a phone or tablet', () => {
    const phones = [
        'Mozilla/5.0 (Linux; Android 14; Pixel 8) Chrome/120 Mobile',
        'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Version/17.0 Mobile Safari',
        'Mozilla/5.0 (iPad; CPU OS 17_0 like Mac OS X) Version/17.0 Mobile Safari',
    ];
    for (const ua of phones) {
        assert.strictEqual(isPointerOnlyComputer({...PC, userAgent: ua, maxTouchPoints: 5, hasFinePointer: false}), false, ua);
    }
    // The browser's own mobile flag wins even when nothing else looks mobile.
    assert.strictEqual(isPointerOnlyComputer({...PC, uaDataMobile: true}), false);
    // iPadOS 13+ claims to be a Macintosh; >1 touch point gives it away.
    assert.strictEqual(isPointerOnlyComputer({...PC, userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Safari', maxTouchPoints: 5}), false);
});

test('isPointerOnlyComputer() fails safe and keeps the button when the pointer is unknown', () => {
    assert.strictEqual(isPointerOnlyComputer({...PC, hasFinePointer: false}), false);
    assert.strictEqual(isPointerOnlyComputer({...PC, hasFinePointer: undefined}), false);
});

test('isPointerOnlyComputer() still treats a real Mac desktop as a computer', () => {
    assert.strictEqual(isPointerOnlyComputer({...PC, userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Safari', maxTouchPoints: 0}), true);
});

// Regression guard for the Chrome-on-Windows desktop share bug: the payload
// handed to navigator.share() must always be one canShare() already approved.
test('shareablePayload() drops the caption when the platform rejects files+text', () => {
    // Exactly Chrome on Windows: files alone are fine, files+text are not.
    const canShare = d => !!(d && d.files) && !('text' in d);
    const file = {name: 'field-1.jpg'};
    const payload = shareablePayload(file, 'caption', canShare);
    assert.deepStrictEqual(Object.keys(payload).sort(), ['files']);
    assert.strictEqual(payload.files[0], file);
    assert.ok(canShare(payload), 'the returned payload must itself pass canShare');
});

test('shareablePayload() keeps the caption where the platform accepts both', () => {
    const canShare = d => !!(d && d.files);
    const payload = shareablePayload({name: 'f.mp4'}, 'caption', canShare);
    assert.deepStrictEqual(Object.keys(payload).sort(), ['files', 'text']);
    assert.strictEqual(payload.text, 'caption');
});

test('shareablePayload() returns null when no file payload is shareable at all', () => {
    assert.strictEqual(shareablePayload({name: 'f.mp4'}, 'caption', () => false), null);
});

test('shouldSkipPhotoCompression() always skips gif, even when large, to protect animation', () => {
    assert.equal(shouldSkipPhotoCompression(5 * 1024 * 1024, 'image/gif'), true);
});

// ── polygonAreaSquareMeters / formatAreaSquareMeters ────────────────────────
// The square metres shown while drawing a search area, while dividing one,
// and in the area/sector popups on the live map.

// Builds a rectangle of genuinely widthM × heightM of WGS84 ground, using
// the same metres-per-degree the measurement does, so a disagreement here is
// a real bug in the shoelace rather than a difference of opinion about the
// shape of the earth. (It used the flat 111320 until the projection was
// corrected — which made the shape not actually 400 m, and the assertion
// passed only because the measurement carried the identical error.)
function rectangleAt(lat, lng, widthM, heightM) {
    const dLat = heightM / metersPerDegreeLat(lat);
    const dLng = widthM / metersPerDegreeLng(lat);
    return [
        [lat, lng],
        [lat, lng + dLng],
        [lat + dLat, lng + dLng],
        [lat + dLat, lng],
    ];
}

test('polygonAreaSquareMeters() measures a 400 x 400 m square', () => {
    const m2 = polygonAreaSquareMeters(rectangleAt(35.33, 24.85, 400, 400));
    // 0.1% tolerance: the projection is taken at the ring's centre latitude
    // while the ring itself spans a few metres of latitude either side.
    assert.ok(Math.abs(m2 - 160000) < 160, `expected ~160000, got ${m2}`);
});

test('polygonAreaSquareMeters() ignores winding direction', () => {
    const ring = rectangleAt(35.33, 24.85, 250, 300);
    const reversed = ring.slice().reverse();
    assert.ok(Math.abs(polygonAreaSquareMeters(ring) - polygonAreaSquareMeters(reversed)) < 1e-6);
});

test('polygonAreaSquareMeters() returns 0 for anything that encloses nothing', () => {
    assert.equal(polygonAreaSquareMeters([]), 0);
    assert.equal(polygonAreaSquareMeters([[35, 24], [35.1, 24.1]]), 0);
    assert.equal(polygonAreaSquareMeters(null), 0);
});

test('polygonAreaSquareMeters() handles a concave ring', () => {
    // An L shape: a 400 x 400 square with its top-right 200 x 200 quarter bitten
    // out, so 160000 - 40000. Concave rings are the normal case for a hand-drawn
    // area, and a shoelace that only worked on convex ones would pass every
    // rectangle test above and still be wrong in the field.
    const lat = 35.33, lng = 24.85;
    const d = m => m / metersPerDegreeLat(lat);
    const e = m => m / metersPerDegreeLng(lat);
    const ring = [
        [lat, lng],
        [lat, lng + e(400)],
        [lat + d(200), lng + e(400)],
        [lat + d(200), lng + e(200)],
        [lat + d(400), lng + e(200)],
        [lat + d(400), lng],
    ];
    assert.ok(Math.abs(polygonAreaSquareMeters(ring) - 120000) < 200);
});

test('formatAreaSquareMeters() stays in square metres below a hectare', () => {
    assert.equal(formatAreaSquareMeters(4800), '4.800 τ.μ.');
    assert.equal(formatAreaSquareMeters(9999.4), '9.999 τ.μ.');
});

test('formatAreaSquareMeters() switches to the middle unit at a hectare', () => {
    // 1.000 m² per στρέμμα in Greek (10.000 per hectare in English) — the
    // divisor comes from the language file, not from the formatter.
    assert.equal(formatAreaSquareMeters(10000), '10,0 στρ.');
    assert.equal(formatAreaSquareMeters(16500), '16,5 στρ.');
});

test('formatAreaSquareMeters() drops the decimal once it stops buying anything', () => {
    // A 400 m grid cell — the single most common sector size in the app.
    assert.equal(formatAreaSquareMeters(160000), '160 στρ.');
});

test('formatAreaSquareMeters() switches to square kilometres at a million', () => {
    assert.equal(formatAreaSquareMeters(1000000), '1,00 τ.χλμ.');
    assert.equal(formatAreaSquareMeters(12500000), '12,50 τ.χλμ.');
    assert.equal(formatAreaSquareMeters(250000000), '250 τ.χλμ.');
});

test('formatAreaSquareMeters() returns empty string for nothing to show', () => {
    assert.equal(formatAreaSquareMeters(0), '');
    assert.equal(formatAreaSquareMeters(null), '');
    assert.equal(formatAreaSquareMeters(undefined), '');
    assert.equal(formatAreaSquareMeters(NaN), '');
});

// ── splitRingAtCutPositions ────────────────────────────────────────────────
// Cutting one sector into two in the Action Room. The governing property is
// conservation: the two halves must tile the sector they came from. The
// version this replaced conserved area only when the first cut point landed
// on the ring's very first vertex, and produced a self-intersecting bowtie
// for every other cut — which is what these tests exist to keep out.

// A regular polygon, so every vertex is an equally plausible cut point and no
// single arrangement can accidentally satisfy the tiling check.
// Signed-area shoelace straight on [lat, lng] degrees — no projection, so two
// pieces that tile a whole sum to it to full double precision.
function planarArea(ring) {
    let a = 0;
    for (let i = 0, j = ring.length - 1; i < ring.length; j = i++) {
        a += ring[j][1] * ring[i][0] - ring[i][1] * ring[j][0];
    }
    return Math.abs(a) / 2;
}

function regularRing(n, lat, lng, radiusM) {
    return Array.from({length: n}, (_, i) => {
        const a = (2 * Math.PI * i) / n;
        return [
            lat + (radiusM * Math.cos(a)) / metersPerDegreeLat(lat),
            lng + (radiusM * Math.sin(a)) / metersPerDegreeLng(lat),
        ];
    });
}

test('splitRingAtCutPositions() conserves area from EVERY pair of cut points', () => {
    const ring = regularRing(12, 35.33, 24.85, 1200);
    // Measured with a raw planar shoelace on the degrees themselves, NOT with
    // polygonAreaSquareMeters(): that one picks its projection from each
    // polygon's own centre latitude, so two halves legitimately disagree with
    // their parent in the sixth decimal place and would force a tolerance loose
    // enough to let a real defect through. Tiling is planar geometry and holds
    // exactly, so it is asserted exactly.
    const whole = planarArea(ring);
    let checked = 0;
    // Whole-number positions are cuts on a corner, the .5s are cuts partway
    // along an edge; both are reachable by clicking in the split composer.
    for (let a = 0; a < 12; a += 0.5) {
        for (let b = a + 1; b < 12; b += 0.5) {
            const halves = splitRingAtCutPositions(ring, a, b);
            if (!halves.length) continue;
            const sum = planarArea(halves[0]) + planarArea(halves[1]);
            assert.ok(
                Math.abs(sum - whole) / whole < 1e-9,
                `cut ${a}-${b} lost area: ${sum} vs ${whole} (self-intersecting half)`
            );
            checked++;
        }
    }
    assert.ok(checked > 200, `expected many cut pairs, checked ${checked}`);
});

test('splitRingAtCutPositions() walks the wrapping arc in ring order', () => {
    // The exact shape of the old bug: cut points 2 and 4 of a hexagon leave
    // the far arc as 5, 0, 1 — which the old sweep emitted as 0, 1, 5.
    const ring = regularRing(6, 35.33, 24.85, 800);
    const [, far] = splitRingAtCutPositions(ring, 2, 4);
    // [p2(=v4), v5, v0, v1, p1(=v2)]
    assert.deepEqual(far.slice(1, 4), [ring[5], ring[0], ring[1]]);
});

test('splitRingAtCutPositions() puts each cut point in both halves', () => {
    const ring = regularRing(8, 35.33, 24.85, 600);
    const [near, far] = splitRingAtCutPositions(ring, 1.5, 5.25);
    const p1 = pointAtRingPos(ring, 1.5), p2 = pointAtRingPos(ring, 5.25);
    assert.deepEqual(near[0], p1);
    assert.deepEqual(near[near.length - 1], p2);
    assert.deepEqual(far[0], p2);
    assert.deepEqual(far[far.length - 1], p1);
});

test('splitRingAtCutPositions() refuses a cut that makes no second piece', () => {
    const ring = regularRing(4, 35.33, 24.85, 500);
    // Two points on the SAME edge: the far side would be a sliver of two
    // points, which is not a polygon.
    assert.deepEqual(splitRingAtCutPositions(ring, 1.2, 1.8), []);
    assert.deepEqual(splitRingAtCutPositions(ring, 2, 2), []);
    assert.deepEqual(splitRingAtCutPositions([[35, 24], [35.1, 24.1]], 0, 1), []);
});

test('splitRingAtCutPositions() takes its two cut points in either order', () => {
    const ring = regularRing(7, 35.33, 24.85, 900);
    assert.deepEqual(splitRingAtCutPositions(ring, 5.5, 1.25), splitRingAtCutPositions(ring, 1.25, 5.5));
});

test('pointAtRingPos() returns the vertex itself at a whole position', () => {
    const ring = regularRing(5, 35.33, 24.85, 400);
    assert.deepEqual(pointAtRingPos(ring, 3), ring[3]);
    // Halfway along the edge from vertex 0 to vertex 1.
    const half = pointAtRingPos(ring, 0.5);
    assert.ok(Math.abs(half[0] - (ring[0][0] + ring[1][0]) / 2) < 1e-12);
    assert.ok(Math.abs(half[1] - (ring[0][1] + ring[1][1]) / 2) < 1e-12);
});

// ── areaTierForGroup + forced units ───────────────────────────────────────
// Figures shown side by side (a sector beside the total it is part of, an
// area beside the pieces it was cut into) must share one unit, or the reader
// converts before they can compare. The unit is the finest any member needs.

test('areaTierForGroup() takes its unit from the smallest member', () => {
    // A 400 m sector (160.000 m²) inside a 35 km² total: the total alone would
    // say square kilometres, but that would render the sector as 0,16.
    assert.equal(areaTierForGroup([160000, 35600000]), 'mid');
    assert.equal(areaTierForGroup([4800, 35600000]), 'm2');
    assert.equal(areaTierForGroup([2000000, 35600000]), 'km2');
});

test('areaTierForGroup() ignores members that are not real areas', () => {
    // A grid with nothing inside the polygon contributes a 0 total, and an
    // empty group has no unit to impose on anyone.
    assert.equal(areaTierForGroup([160000, 0]), 'mid');
    assert.equal(areaTierForGroup([0, NaN, null, undefined]), null);
    assert.equal(areaTierForGroup([]), null);
    assert.equal(areaTierForGroup(undefined), null);
});

test('formatAreaSquareMeters() honours a forced unit instead of its own', () => {
    const tier = areaTierForGroup([345600, 35596800]);   // one 600x576 cell, 103 of them
    assert.equal(tier, 'mid');
    assert.equal(formatAreaSquareMeters(345600, tier), '346 στρ.');
    assert.equal(formatAreaSquareMeters(35596800, tier), '35.597 στρ.');
    // Without the group it splits across two units, which is the thing being
    // fixed: 346 στρ. next to 35,60 τ.χλμ. hides that one is 103 of the other.
    assert.equal(formatAreaSquareMeters(35596800), '35,60 τ.χλμ.');
});

test('formatAreaSquareMeters() still picks its own unit for a lone figure', () => {
    // Map popups and the drawing badge show one number and nothing to compare
    // it against, so they stay free to choose.
    assert.equal(formatAreaSquareMeters(345600, null), '346 στρ.');
    assert.equal(formatAreaSquareMeters(345600, undefined), '346 στρ.');
});

test('formatAreaSquareMeters() groups thousands the way the language does', () => {
    // Greek: full stop for thousands, comma for the decimal. 35597 unseparated
    // is not a number anyone reads at a glance mid-callout.
    assert.equal(formatAreaSquareMeters(35596800, 'mid'), '35.597 στρ.');
    assert.equal(formatAreaSquareMeters(1234567890, 'm2'), '1.234.567.890 τ.μ.');
    assert.equal(formatAreaSquareMeters(16500, 'mid'), '16,5 στρ.');
});

// ── Projection accuracy against WGS84 ground truth ─────────────────────────
// These exist because the figures this app prints are read as ground: a
// coordinator sizes a sweep off them and a rescuer walks it. Asserting a
// rounded sample ("160000-ish") cannot catch a systematic bias, because the
// sample is generated by the very projection under test — which is exactly
// how a 0.24% overstatement lived in this file unnoticed. So the reference
// here is independent and exact.
//
// Reference: the closed-form area of a graticule quadrangle (two parallels,
// two meridians) on the WGS84 ellipsoid. From dA = M(φ)·N(φ)·cos φ dφ dλ,
//   ∫ cos φ/(1−e²sin²φ)² dφ  =  s/(2(1−e²s²)) + (1/4e)·ln((1+es)/(1−es)),  s = sin φ
// It involves no projection and no approximation, and grid cells are exactly
// this shape.
const WGS84_A_REF = 6378137.0;
const WGS84_E_REF = Math.sqrt(0.00669437999014);
function exactQuadrangleArea(lat1, lat2, lng1, lng2) {
    const term = latDeg => {
        const s = Math.sin((latDeg * Math.PI) / 180);
        return s / (2 * (1 - WGS84_E_REF * WGS84_E_REF * s * s))
            + Math.log((1 + WGS84_E_REF * s) / (1 - WGS84_E_REF * s)) / (4 * WGS84_E_REF);
    };
    return WGS84_A_REF * WGS84_A_REF * (1 - WGS84_E_REF * WGS84_E_REF)
        * Math.abs(((lng2 - lng1) * Math.PI) / 180)
        * Math.abs(term(lat2) - term(lat1));
}

test('polygonAreaSquareMeters() matches the exact WGS84 area across Greece', () => {
    // Crete to Thrace, a garden plot to a whole search area. The old flat
    // projection failed this at every single point, 0.08% to 0.24% high.
    let worst = 0, worstAt = null;
    for (const lat of [34.8, 35.3, 36.4, 37.9, 39.2, 40.6, 41.7]) {
        for (const metres of [30, 100, 400, 600, 2000, 10000]) {
            const dLat = metres / metersPerDegreeLat(lat);
            const dLng = metres / metersPerDegreeLng(lat);
            const exact = exactQuadrangleArea(lat, lat + dLat, 24, 24 + dLng);
            const got = polygonAreaSquareMeters([
                [lat, 24], [lat, 24 + dLng], [lat + dLat, 24 + dLng], [lat + dLat, 24],
            ]);
            const errPct = Math.abs((got - exact) / exact) * 100;
            if (errPct > worst) { worst = errPct; worstAt = `${lat}° / ${metres}m`; }
        }
    }
    // 0.001% of a 400 m sector is 1.6 m² — six orders of magnitude inside the
    // GPS fix the polygon was drawn from.
    assert.ok(worst < 0.001, `worst error ${worst.toFixed(5)}% at ${worstAt}`);
});

test('polygonAreaSquareMeters() has no systematic bias in one direction', () => {
    // The failure that was actually shipped was not size, it was SIGN: every
    // area came out high, so it never averaged away over a mission. Signed
    // errors must straddle zero, not sit on one side of it.
    const signed = [];
    for (const lat of [34.8, 36.0, 37.5, 39.0, 40.5, 41.7]) {
        const dLat = 800 / metersPerDegreeLat(lat);
        const dLng = 800 / metersPerDegreeLng(lat);
        const exact = exactQuadrangleArea(lat, lat + dLat, 24, 24 + dLng);
        const got = polygonAreaSquareMeters([
            [lat, 24], [lat, 24 + dLng], [lat + dLat, 24 + dLng], [lat + dLat, 24],
        ]);
        signed.push(((got - exact) / exact) * 100);
    }
    const mean = signed.reduce((a, b) => a + b, 0) / signed.length;
    assert.ok(Math.abs(mean) < 0.0005, `mean signed error ${mean.toFixed(6)}% — a bias, not noise`);
});

// Exact area of one emitted grid cell, which is a graticule quadrangle.
function exactCellArea(cell) {
    const lats = cell.map(pt => pt[0]);
    const lngs = cell.map(pt => pt[1]);
    return exactQuadrangleArea(Math.min(...lats), Math.max(...lats), Math.min(...lngs), Math.max(...lngs));
}

test('the size a grid reports is the true mean of the cells it emits', () => {
    // "Κάθε τομέας ≈ 346 στρ. · σύνολο 35.604 στρ." is arithmetic the reader
    // does in their head: the per-sector figure times the count. Both sides of
    // it are checked here against exact ellipsoidal cell areas, so neither the
    // size nor the total can drift from the ground.
    const area = [[35.24, 24.75], [35.24, 24.79], [35.22, 24.79], [35.22, 24.75]];
    const grid = gridCellsForPolygon(area, 500);
    const all = grid.cells.concat(grid.dropped);
    const exactMean = all.reduce((sum, cell) => sum + exactCellArea(cell), 0) / all.length;
    const claimed = grid.actual_w_m * grid.actual_h_m;
    const errPct = Math.abs((claimed - exactMean) / exactMean) * 100;
    // actual_w_m and actual_h_m are each reported to 0.1 m, so that rounding
    // alone moves their product by up to this much. Allowing it explicitly,
    // rather than loosening the bound to a round number, keeps the projection
    // itself pinned to 0.002% — the flat 111320 missed that by a hundredfold.
    const roundingPct = (0.05 / grid.actual_w_m + 0.05 / grid.actual_h_m) * 100;
    assert.ok(errPct < roundingPct + 0.002, `claims ${claimed.toFixed(0)} m² per cell, true mean ${exactMean.toFixed(0)} m² (${errPct.toFixed(4)}%, rounding allows ${roundingPct.toFixed(4)}%)`);

    // And the tasked total, which is that figure times the kept count.
    const exactTasked = grid.cells.reduce((sum, cell) => sum + exactCellArea(cell), 0);
    const claimedTasked = claimed * grid.kept;
    const totalErr = Math.abs((claimedTasked - exactTasked) / exactTasked) * 100;
    assert.ok(totalErr < roundingPct + 0.002, `tasked total off by ${totalErr.toFixed(4)}% (rounding allows ${roundingPct.toFixed(4)}%)`);
});

test('cells within one grid vary in ground size, and the spread stays small', () => {
    // Worth pinning rather than assuming: a grid is cut in DEGREES, so its
    // northern cells are narrower on the ground than its southern ones, and
    // one reported size can only ever be the mean. That is what the "≈" in
    // the preview is doing. Over a search area this spread is a fraction of a
    // percent; if a future change ever widens it to something a coordinator
    // would notice, the per-sector figure needs to stop being a single number.
    const tall = [[35.60, 24.75], [35.60, 24.95], [35.10, 24.95], [35.10, 24.75]];  // ~55 km tall
    const grid = gridCellsForPolygon(tall, 900);
    const areas = grid.cells.concat(grid.dropped).map(exactCellArea);
    const min = Math.min(...areas), max = Math.max(...areas);
    const spreadPct = ((max - min) / min) * 100;
    assert.ok(spreadPct < 1.5, `cells vary by ${spreadPct.toFixed(3)}% across the grid`);
    assert.ok(spreadPct > 0, 'a lat/lng grid cannot have perfectly uniform cells');
});

test('metres per degree match published WGS84 values', () => {
    // Spot values anyone can check against a geodesy table, so a future edit
    // to the constants cannot quietly drift.
    assert.ok(Math.abs(metersPerDegreeLat(0) - 110574.3) < 1, metersPerDegreeLat(0));
    assert.ok(Math.abs(metersPerDegreeLat(45) - 111132.0) < 1, metersPerDegreeLat(45));
    assert.ok(Math.abs(metersPerDegreeLat(90) - 111694.0) < 1, metersPerDegreeLat(90));
    assert.ok(Math.abs(metersPerDegreeLng(0) - 111319.5) < 1, metersPerDegreeLng(0));
    assert.ok(Math.abs(metersPerDegreeLng(45) - 78846.8) < 1, metersPerDegreeLng(45));
    // 35°N is Crete, where this app is actually used.
    assert.ok(Math.abs(metersPerDegreeLat(35) - 110940.6) < 1, metersPerDegreeLat(35));
    assert.ok(Math.abs(metersPerDegreeLng(35) - 91288.2) < 1, metersPerDegreeLng(35));
});

// ── The drawn shape's extent ──────────────────────────────────────────────
//
// These exist because of a real report: a house the owner knows to be 230 τ.μ.
// came back as 500. The area arithmetic was exact — checked against the
// spherical-excess formula on shapes from 40 τ.μ. to 160.000 στρ. — and the
// gap was drawing precision. At zoom 16 that house is EIGHT PIXELS across, so
// a two-pixel slip per edge reports 527 τ.μ. and looks no different.
//
// An area cannot be sanity-checked on its own. An extent can.

test('the extent of a drawn shape is its real size on the ground', () => {
    const lat0 = 35.3387, lng0 = 25.1442;
    const mLat = metersPerDegreeLat(lat0), mLng = metersPerDegreeLng(lat0);
    const rect = (w, h) => [
        [lat0, lng0], [lat0, lng0 + w / mLng],
        [lat0 + h / mLat, lng0 + w / mLng], [lat0 + h / mLat, lng0],
    ];

    const house = polygonBoundsSizeMeters(rect(15.17, 15.17));
    assert.ok(Math.abs(house.w - 15.17) < 0.1, house.w);
    assert.ok(Math.abs(house.h - 15.17) < 0.1, house.h);

    // Not square: width and height must not be interchangeable, or the
    // readout would hide the shape being twice as long as it should be.
    const oblong = polygonBoundsSizeMeters(rect(11.5, 20));
    assert.ok(Math.abs(oblong.w - 11.5) < 0.1, oblong.w);
    assert.ok(Math.abs(oblong.h - 20) < 0.1, oblong.h);
});

test('an over-drawn house reads as visibly the wrong size', () => {
    const lat0 = 35.3387, lng0 = 25.1442;
    const mLat = metersPerDegreeLat(lat0), mLng = metersPerDegreeLng(lat0);
    const square = s => [
        [lat0, lng0], [lat0, lng0 + s / mLng],
        [lat0 + s / mLat, lng0 + s / mLng], [lat0 + s / mLat, lng0],
    ];

    // 230 τ.μ. and 500 τ.μ. are 15 m and 22 m per side. The areas look like
    // two numbers; the extents look like two different buildings.
    assert.equal(formatBoundsSize(polygonBoundsSizeMeters(square(Math.sqrt(230)))), '15 × 15 μ.');
    assert.equal(formatBoundsSize(polygonBoundsSizeMeters(square(Math.sqrt(500)))), '22 × 22 μ.');
});

test('the extent switches to kilometres only when metres stop being readable', () => {
    assert.equal(formatBoundsSize({w: 900, h: 400}), '900 × 400 μ.');
    assert.equal(formatBoundsSize({w: 1400, h: 900}), '1,4 × 0,9 χλμ.');
});

test('a shape with no extent says nothing rather than zero', () => {
    // A single vertex, or none, is not a shape — and "0 × 0 μ" beside a blank
    // area would read as a measurement.
    assert.equal(polygonBoundsSizeMeters(null), null);
    assert.equal(polygonBoundsSizeMeters([[35, 25]]), null);
    assert.equal(formatBoundsSize(null), '');
    assert.equal(formatBoundsSize({w: 0, h: 0}), '');
});

// ── Spoken announcements, cut into pieces an engine will not truncate ───────

test('speechChunks keeps a short message in one piece', () => {
    const short = 'Η ΑΛΦΑ σιωπά σαράντα επτά λεπτά. Ζήτησέ της επικοινωνία τώρα.';
    assert.deepEqual(speechChunks(short), [short]);
});

test('speechChunks breaks a long message at sentence ends', () => {
    // Every piece must be short enough that no engine cuts it, and each must
    // end where a thought ends — a piece ending mid-clause is heard as the
    // message stopping.
    const long = 'Η ομάδα ΑΛΦΑ δεν έχει στείλει στίγμα σαράντα επτά λεπτά και είναι η μόνη '
               + 'ομάδα που βρίσκεται στον βόρειο τομέα αυτή τη στιγμή. Η ΒΗΤΑ βρίσκεται '
               + 'ενάμισι χιλιόμετρο νότια και μπορεί να την καλύψει χωρίς να αφήσει το δικό '
               + 'της έδαφος ακάλυπτο. Ζήτησε επικοινωνία από την ΑΛΦΑ πριν μετακινήσεις '
               + 'οποιαδήποτε άλλη ομάδα στο βουνό.';
    const pieces = speechChunks(long);

    assert.ok(pieces.length > 1, 'a 320-character message must not go out as one utterance');
    for (const p of pieces) {
        assert.ok(p.length <= SPEECH_CHUNK_CHARS, `piece too long: ${p.length}`);
    }
    // Nothing added, nothing lost: the pieces rejoin into the original.
    assert.equal(pieces.join(' ').replace(/\s+/g, ' '), long.replace(/\s+/g, ' '));
    assert.ok(/[.!;\u037E\u00b7]$/.test(pieces[0]), 'the first piece must end on a sentence');
});

test('speechChunks never cuts inside a word', () => {
    // A cut mid-word is heard as a stutter, which reads as a fault rather than
    // as a pause.
    const noPunctuation = 'ομάδα '.repeat(80).trim();
    const pieces = speechChunks(noPunctuation);

    assert.ok(pieces.length > 1);
    for (const p of pieces) {
        assert.ok(p.length <= SPEECH_CHUNK_CHARS);
        assert.equal(p, p.trim());
        assert.ok(!/^\S*[^ο]/.test(p.split(' ')[0]) || p.split(' ')[0] === 'ομάδα',
            `piece starts mid-word: ${p.split(' ')[0]}`);
    }
    assert.equal(pieces.join(' '), noPunctuation);
});

test('speechChunks falls back to a comma when no sentence ends in range', () => {
    const oneLongSentence = 'Η ομάδα ΑΛΦΑ κινείται βόρεια, η ΒΗΤΑ παραμένει στη βάση, '
        + 'η ΓΑΜΑ ανεβαίνει προς το καταφύγιο, η ΔΕΛΤΑ περιμένει εντολή, '
        + 'και η ΕΨΙΛΟΝ δεν έχει ακόμη ξεκινήσει από το σημείο συγκέντρωσης.';
    const pieces = speechChunks(oneLongSentence);

    assert.ok(pieces.length > 1);
    assert.ok(pieces[0].endsWith(','), `expected a comma break, got: ${pieces[0].slice(-20)}`);
});

test('speechChunks handles the Greek question mark in both code points', () => {
    const ascii = 'Πού είναι η ΑΛΦΑ; '.repeat(12).trim();
    const greek = 'Πού είναι η ΑΛΦΑ\u037E '.repeat(12).trim();
    for (const text of [ascii, greek]) {
        const pieces = speechChunks(text);
        assert.ok(pieces.length > 1);
        assert.ok(/[;\u037E]$/.test(pieces[0]), `did not break on the question mark: ${pieces[0].slice(-10)}`);
    }
});

test('speechChunks returns nothing for nothing', () => {
    assert.deepEqual(speechChunks(''), []);
    assert.deepEqual(speechChunks('   '), []);
    assert.deepEqual(speechChunks(null), []);
    assert.deepEqual(speechChunks(undefined), []);
});

test('the pause/resume nudge is refused on phones and allowed on desktops', () => {
    // On Chrome for Android pause() does not pause, it stops, and resume()
    // does not bring the voice back — so there the workaround IS the fault,
    // silencing a message about ten seconds in. Reported from the field as
    // "fine on the laptop, cut short on the phone".
    const android = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 '
                  + '(KHTML, like Gecko) Chrome/126.0.0.0 Mobile Safari/537.36';
    const iphone  = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 '
                  + '(KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';
    const windows = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
                  + '(KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';

    assert.equal(speechKeepAliveIsSafe({userAgent: android}), false);
    assert.equal(speechKeepAliveIsSafe({userAgent: iphone}), false);
    assert.equal(speechKeepAliveIsSafe({userAgent: windows}), true);

    // The browser's own answer wins over the string, which a desktop-mode
    // toggle rewrites to look like a laptop.
    assert.equal(speechKeepAliveIsSafe({userAgent: windows, uaDataMobile: true}), false);
    assert.equal(speechKeepAliveIsSafe({userAgent: android, uaDataMobile: false}), true);

    // Nothing known at all is treated as a desktop: that is where the nudge
    // was needed, and where it is harmless.
    assert.equal(speechKeepAliveIsSafe({}), true);
});

test('the watchdog waits longer than the words could possibly take', () => {
    // It exists for an engine that never delivers onend. Firing early would
    // talk over a piece still being spoken, which is worse than the silence it
    // is trying to prevent — so it is roughly double the real duration.
    // Measured on the real engine: 284 Greek characters take about 19 seconds.
    const realMsPerChar = 19000 / 284;
    for (const chars of [20, 90, 170]) {
        const allowed = speechPieceTimeoutMs('x'.repeat(chars));
        assert.ok(allowed > chars * realMsPerChar * 1.5,
            `${chars} chars: watchdog ${allowed}ms is too close to the real ${Math.round(chars * realMsPerChar)}ms`);
    }
    // Even an empty string gets a floor rather than firing immediately.
    assert.ok(speechPieceTimeoutMs('') >= 4000);
    assert.ok(speechPieceTimeoutMs(null) >= 4000);
});
