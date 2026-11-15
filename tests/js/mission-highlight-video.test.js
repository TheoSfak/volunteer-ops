// Run with: node --test tests/js
//
// assets/js/mission-highlight-video.js: the client-side renderer for the
// mission highlight video feature. Only the pure, canvas-free pieces are
// checked here (tile math, timing budget, Ken Burns transform, date
// formatting) - the actual canvas/MediaRecorder painting needs a real
// browser and was verified by the M3 spike instead.

const test = require('node:test');
const assert = require('node:assert/strict');

const {
    latLngToTile, computeTiming, kenBurnsTransform, formatMissionDate,
    PIN_ZOOM, MIN_TOTAL_MS, MAX_TOTAL_MS, MIN_ITEM_MS,
} = require('../../assets/js/mission-highlight-video.js');

test('latLngToTile matches the standard slippy-map formula at zoom 0 (whole world, one tile)', () => {
    const t = latLngToTile(0, 0, 0);
    assert.equal(t.z, 0);
    assert.equal(t.x, 0);
    assert.equal(t.y, 0);
    // (0,0) is exactly the centre of the single z0 tile.
    assert.ok(Math.abs(t.fx - 128) < 0.01);
    assert.ok(Math.abs(t.fy - 128) < 0.01);
});

test('latLngToTile: a known reference point (Heraklion) lands in a sane tile range at a normal zoom', () => {
    const t = latLngToTile(35.3387, 25.1442, 14);
    assert.equal(t.z, 14);
    // At z14 there are 2^14 = 16384 tiles per axis.
    assert.ok(t.x > 0 && t.x < 16384);
    assert.ok(t.y > 0 && t.y < 16384);
    assert.ok(t.fx >= 0 && t.fx < 256);
    assert.ok(t.fy >= 0 && t.fy < 256);
});

test('latLngToTile: moving east increases the tile x at a fixed zoom', () => {
    const west = latLngToTile(35.0, 24.0, 12);
    const east = latLngToTile(35.0, 26.0, 12);
    assert.ok(east.x >= west.x);
});

test('computeTiming: total duration stays within the 20-40s band while the per-item minimum allows it', () => {
    for (const n of [1, 2, 5, 10]) {
        const timing = computeTiming(n);
        assert.ok(timing.totalMs >= MIN_TOTAL_MS, `n=${n}: ${timing.totalMs} >= ${MIN_TOTAL_MS}`);
        assert.ok(timing.totalMs <= MAX_TOTAL_MS + 1000, `n=${n}: ${timing.totalMs} <= ${MAX_TOTAL_MS}`); // rounding slack
        assert.equal(timing.tooMany, false);
    }
});

test('computeTiming: past the point where MIN_ITEM_MS no longer fits in 40s, the total grows instead of truncating items (and tooMany is flagged, for the UI warning)', () => {
    const timing = computeTiming(50);
    assert.ok(timing.totalMs > MAX_TOTAL_MS, 'it must not silently cut every item short to stay under the cap');
    assert.equal(timing.perItemMs, MIN_ITEM_MS);
    assert.equal(timing.tooMany, true);
});

test('computeTiming: per-item time never drops below the minimum, however many items', () => {
    const timing = computeTiming(50);
    assert.ok(timing.perItemMs >= MIN_ITEM_MS - 0.01);
});

test('computeTiming: a single item still gets a full, sane slideshow slice', () => {
    const timing = computeTiming(1);
    assert.ok(timing.perItemMs > MIN_ITEM_MS);
});

test('computeTiming: more items means a shorter (or equal) per-item slice', () => {
    const few = computeTiming(2);
    const many = computeTiming(10);
    assert.ok(many.perItemMs <= few.perItemMs);
});

test('kenBurnsTransform: starts at native scale with no pan, grows over the clip', () => {
    const start = kenBurnsTransform(0, 1);
    assert.equal(start.scale, 1);
    assert.equal(start.panX, 0);
    const end = kenBurnsTransform(1, 1);
    assert.ok(end.scale > start.scale);
});

test('kenBurnsTransform: direction flips the pan sign, not the zoom', () => {
    const left = kenBurnsTransform(1, -1);
    const right = kenBurnsTransform(1, 1);
    assert.equal(left.scale, right.scale);
    assert.ok(left.panX < 0);
    assert.ok(right.panX > 0);
});

test('formatMissionDate: formats a MySQL datetime as dd/mm/yyyy hh:mm', () => {
    assert.equal(formatMissionDate('2026-03-05 09:30:00'), '05/03/2026 09:30');
});

test('formatMissionDate: empty/invalid input is the empty string, never "Invalid Date"', () => {
    assert.equal(formatMissionDate(null), '');
    assert.equal(formatMissionDate(''), '');
    assert.equal(formatMissionDate('not-a-date'), '');
});

test('PIN_ZOOM stays within Esri\'s documented worst-case coverage depth', () => {
    // esri-imagery.js: real imagery can be as shallow as z17 in some places.
    // Staying at 15 keeps this feature one level inside that everywhere.
    assert.ok(PIN_ZOOM < 17);
    assert.ok(PIN_ZOOM > 0);
});
