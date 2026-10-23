// Run with: node --test tests/js
//
// trailDisplayModel() / trailFindStops() (assets/js/trail-display.js): what the
// «Πορεία Ομάδων» map draws from a person's stored fixes. Two behaviours are
// pinned - a run of fixes standing still folds into ONE stop with a duration,
// and a silence in the recording is cut out of the solid line and returned as
// a gap to draw dashed - along with the cases where doing either would lie.

const test = require('node:test');
const assert = require('node:assert/strict');

const {
    trailFindStops, trailDisplayModel, trailDurationParts, trailRecordsContinuously,
    trailEffectiveGapSeconds, trailStopBadgeHtml,
    TRAIL_STOP_MIN_SECONDS, TRAIL_STOP_MIN_POINTS,
} = require('../../assets/js/trail-display.js');

const LAT0 = 35.33, LNG0 = 25.13;
const M_LAT = 111320, M_LNG = 111320 * Math.cos(LAT0 * Math.PI / 180);

/** A fix at [north, east] metres from a fixed origin, t seconds after the start. */
function fix(t, north, east, extra) {
    return Object.assign({
        lat: LAT0 + north / M_LAT, lng: LNG0 + east / M_LNG, ts: 1000000 + t, acc: 5,
        source: 'auto', time: 'd/m H:i', hr_zone: null,
    }, extra || {});
}

/** n fixes standing at [north, east], every `step` seconds from t0, jittering a couple of metres. */
function standing(n, t0, north, east, step = 20) {
    return Array.from({length: n}, (_, i) => fix(t0 + i * step, north + ((i * 7) % 5) - 2, east + ((i * 3) % 5) - 2));
}

/** n fixes walking north at `speed` m/s, every `step` seconds from t0, starting at `north0`. */
function walking(n, t0, north0, speed = 1.3, step = 20) {
    return Array.from({length: n}, (_, i) => fix(t0 + i * step, north0 + i * speed * step, 0));
}

const GAP = 60; // what mission-track.php sends with a 20 s cadence

test('twenty minutes standing is one stop with the right duration and centre', () => {
    const pts = standing(61, 0, 100, 50); // 61 fixes, 20 s apart = 20 minutes
    const stops = trailFindStops(pts, {gapSeconds: GAP});
    assert.equal(stops.length, 1);
    assert.equal(stops[0].from, 0);
    assert.equal(stops[0].to, 60);
    assert.equal(stops[0].count, 61);
    assert.equal(stops[0].endTs - stops[0].startTs, 1200);
    // The centre is where they stood, within the couple of metres of jitter.
    const north = (stops[0].lat - LAT0) * M_LAT, east = (stops[0].lng - LNG0) * M_LNG;
    assert.ok(Math.abs(north - 100) < 3 && Math.abs(east - 50) < 3, `centre ${north}, ${east}`);
});

test('the whole run collapses to one vertex and one item', () => {
    const m = trailDisplayModel(standing(61, 0, 0, 0), {gapSeconds: GAP});
    assert.equal(m.items.length, 1);
    assert.equal(m.items[0].type, 'stop');
    assert.equal(m.solid.length, 0, 'one vertex has no line to draw');
    assert.equal(m.lastIndex, 60);
});

test('walking never makes a stop, however long', () => {
    assert.equal(trailFindStops(walking(120, 0, 0), {gapSeconds: GAP}).length, 0);
    // Even a slow search pace (0.4 m/s, ~8 m per fix) leaves the radius in a minute or two.
    assert.equal(trailFindStops(walking(120, 0, 0, 0.4), {gapSeconds: GAP}).length, 0);
});

test('a walk, a stop, a walk: the stop sits in the middle and the line goes through its centre', () => {
    const a = walking(20, 0, 0);                      // t 0..380, ends ~494 m north
    const endNorth = 19 * 1.3 * 20;
    const b = standing(30, 400, endNorth + 10, 0);    // t 400..980: ~10 minutes standing
    const c = walking(20, 1000, endNorth + 30);       // walks on
    const m = trailDisplayModel([...a, ...b, ...c], {gapSeconds: GAP});
    const stopItems = m.items.filter(i => i.type === 'stop');
    assert.equal(stopItems.length, 1);
    const stop = stopItems[0].stop;
    assert.ok(stop.from >= 18 && stop.from <= 21, `stop starts at fix ${stop.from}`);
    assert.ok(stop.to >= 48 && stop.to <= 51, `stop ends at fix ${stop.to}`);
    assert.equal(m.solid.length, 1, 'no gap, so one continuous line');
    // 20 walking fixes (the last may join the stop) + the stop vertex + 20 walking fixes.
    assert.ok(m.solid[0].length >= 38 && m.solid[0].length <= 42, `vertices ${m.solid[0].length}`);
});

test('under three minutes, or under three fixes, is not a stop', () => {
    assert.equal(trailFindStops(standing(8, 0, 0, 0), {gapSeconds: GAP}).length, 0, '8 fixes = 140 s');
    // Two fixes five minutes apart: long enough, but two samples are not a stretch of time.
    const two = [fix(0, 0, 0), fix(300, 1, 1)];
    assert.equal(trailFindStops(two, {}).length, 0);
    assert.equal(TRAIL_STOP_MIN_POINTS, 3);
    assert.equal(TRAIL_STOP_MIN_SECONDS, 180);
});

test('a stop does not span a silence: the unseen time is not counted as standing', () => {
    // Ten minutes standing, an eight-minute silence at the same spot, ten more minutes.
    const pts = [...standing(31, 0, 0, 0), ...standing(31, 600 + 480, 0, 0)];
    const stops = trailFindStops(pts, {gapSeconds: GAP});
    assert.equal(stops.length, 2);
    assert.equal(stops[0].to, 30);
    assert.equal(stops[1].from, 31);
});

const alarm = p => p.hr_zone === 'critical' || p.hr_zone === 'low';

test('a heart-rate alarm fix stays a dot of its own inside the stop, and does not split it', () => {
    const pts = standing(61, 0, 0, 0);
    pts[30].hr_zone = 'critical';
    const m = trailDisplayModel(pts, {gapSeconds: GAP, keep: alarm});
    const stops = m.items.filter(i => i.type === 'stop');
    assert.equal(stops.length, 1, 'one stop, not one on each side');
    assert.equal(stops[0].stop.count, 60, 'the alarm fix is not folded in');
    assert.deepEqual(stops[0].stop.kept, [30]);
    assert.equal(stops[0].endTs - stops[0].startTs, 1200, 'the stop still lasted twenty minutes');
    const lone = m.items.filter(i => i.type === 'point');
    assert.equal(lone.length, 1);
    assert.equal(lone[0].index, 30);
    assert.equal(lone[0].inStop, true);
    assert.equal(m.gaps.length, 0, 'the alarm fix lies inside the stop and is not a step along the path');
    assert.equal(m.solid.length, 0);
});

test('an alarm fix at the very end of a run belongs after the stop, and a run of alarms is no stop at all', () => {
    const pts = standing(40, 0, 0, 0);
    pts[39].hr_zone = 'low';
    const m = trailDisplayModel(pts, {gapSeconds: GAP, keep: alarm});
    const stop = m.items.find(i => i.type === 'stop').stop;
    assert.equal(stop.to, 38, 'the stop ends on a folded fix');
    const last = m.items[m.items.length - 1];
    assert.equal(last.type, 'point');
    assert.equal(last.index, 39);
    assert.equal(last.inStop, undefined, 'it follows the stop on the path');
    // Somebody whose every fix is an alarm has nothing folded: it is all marks.
    const all = standing(40, 0, 0, 0).map(p => Object.assign(p, {hr_zone: 'critical'}));
    assert.equal(trailDisplayModel(all, {gapSeconds: GAP, keep: alarm}).items.filter(i => i.type === 'stop').length, 0);
});

test('an alarm fix far from the stop is a real excursion and ends it', () => {
    const pts = standing(61, 0, 0, 0);
    Object.assign(pts[30], fix(0, 300, 0, {hr_zone: 'critical'}), {ts: pts[30].ts});
    const stops = trailFindStops(pts, {gapSeconds: GAP, keep: alarm});
    assert.equal(stops.length, 2, 'standing, a 300 m excursion, standing again');
});

test('no false gap appears after a stop that contains an alarm fix', () => {
    const before = walking(5, 0, 0);
    const stand = standing(61, 100, 300, 0);
    stand[10].hr_zone = 'critical';
    const after = walking(5, 100 + 61 * 20, 320);
    const m = trailDisplayModel([...before, ...stand, ...after], {gapSeconds: GAP, keep: alarm});
    assert.equal(m.gaps.length, 0);
    assert.equal(m.solid.length, 1);
});

test('collapseStops:false draws every fix, as before', () => {
    const pts = standing(61, 0, 0, 0);
    const m = trailDisplayModel(pts, {gapSeconds: GAP, collapseStops: false});
    assert.equal(m.items.length, 61);
    assert.ok(m.items.every(i => i.type === 'point'));
    assert.equal(m.solid.length, 1);
    assert.equal(m.solid[0].length, 61);
});

// ── Gaps ────────────────────────────────────────────────────────────────────

test('a silence longer than the limit is cut out of the line and returned as a gap', () => {
    // Walk, 10 minutes with no fix, walk on from further along.
    const a = walking(10, 0, 0);
    const b = walking(10, 180 + 600, 600);
    const m = trailDisplayModel([...a, ...b], {gapSeconds: GAP});
    assert.equal(m.solid.length, 2);
    assert.equal(m.solid[0].length, 10);
    assert.equal(m.solid[1].length, 10);
    assert.equal(m.gaps.length, 1);
    assert.equal(m.gaps[0].seconds, 600, 'last fix at t=180, next at t=780');
    assert.deepEqual(m.gaps[0].from, m.solid[0][9]);
    assert.deepEqual(m.gaps[0].to, m.solid[1][0]);
    assert.equal(m.gaps[0].fromPoint, a[9]);
    assert.equal(m.gaps[0].toPoint, b[0]);
});

test('the gap length is the silence between the two fixes', () => {
    const m = trailDisplayModel([fix(0, 0, 0), fix(20, 20, 0), fix(500, 400, 0), fix(520, 420, 0)], {gapSeconds: GAP});
    assert.equal(m.gaps.length, 1);
    assert.equal(m.gaps[0].seconds, 480);
});

test('exactly the limit is still continuous; one second more is a gap', () => {
    const at = d => trailDisplayModel([fix(0, 0, 0), fix(d, 50, 0), fix(d + 20, 70, 0)], {gapSeconds: GAP, collapseStops: false});
    assert.equal(at(60).gaps.length, 0);
    assert.equal(at(61).gaps.length, 1);
});

test('no limit given (or 0) never cuts the line', () => {
    const pts = [fix(0, 0, 0), fix(20, 20, 0), fix(5000, 400, 0)];
    assert.equal(trailDisplayModel(pts, {}).gaps.length, 0);
    assert.equal(trailDisplayModel(pts, {gapSeconds: 0}).gaps.length, 0);
    assert.equal(trailDisplayModel(pts, {gapSeconds: 0}).solid.length, 1);
});

test('a trail of manual taps has no gaps: minutes between taps is how it is made', () => {
    const taps = [0, 400, 1500, 4000].map((t, i) => fix(t, i * 80, 0, {source: 'manual'}));
    assert.equal(trailRecordsContinuously(taps), false);
    const m = trailDisplayModel(taps, {gapSeconds: GAP});
    assert.equal(m.gaps.length, 0);
    assert.equal(m.solid.length, 1);
    assert.equal(m.solid[0].length, 4);
});

test('a manual tap in the middle of an automatic trail does not hide a gap around it', () => {
    const pts = [fix(0, 0, 0), fix(20, 20, 0), fix(40, 40, 0, {source: 'manual'}), fix(900, 700, 0), fix(920, 720, 0)];
    assert.equal(trailRecordsContinuously(pts), true);
    assert.equal(trailDisplayModel(pts, {gapSeconds: GAP, collapseStops: false}).gaps.length, 1);
});

test('a gap before and after a stop: the dashed line starts and ends at the stop centre', () => {
    const before = walking(5, 0, 0);
    const stand = standing(31, 100 + 600, 300, 0);       // 10 min silence, then 10 min standing
    const after = walking(5, 100 + 600 + 600 + 600, 500);
    const m = trailDisplayModel([...before, ...stand, ...after], {gapSeconds: GAP});
    assert.equal(m.items.filter(i => i.type === 'stop').length, 1);
    assert.equal(m.gaps.length, 2);
    const stop = m.items.find(i => i.type === 'stop');
    assert.deepEqual(m.gaps[0].to, [stop.lat, stop.lng]);
    assert.deepEqual(m.gaps[1].from, [stop.lat, stop.lng]);
});

// ── The limit adapts to the trail's own rhythm ──────────────────────────────

test('a trail recorded slower than the setting is not dashed from end to end', () => {
    // Fixes every 3 minutes against a 90 s limit: every leg is "longer than the limit".
    const pts = walking(30, 0, 0, 1.3, 180);
    assert.equal(trailDisplayModel(pts, {gapSeconds: 90, collapseStops: false}).gaps.length, 29, 'the raw limit would cut every leg');
    const effective = trailEffectiveGapSeconds(pts, 90);
    assert.equal(effective, 540, 'three times the 180 s rhythm');
    assert.equal(trailDisplayModel(pts, {gapSeconds: effective, collapseStops: false}).gaps.length, 0);
});

test('a real silence in a slow trail is still a gap', () => {
    const a = walking(15, 0, 0, 1.3, 180);
    const b = walking(15, 15 * 180 + 1800, 5000, 1.3, 180); // half an hour of nothing
    const pts = [...a, ...b];
    const m = trailDisplayModel(pts, {gapSeconds: trailEffectiveGapSeconds(pts, 90), collapseStops: false});
    assert.equal(m.gaps.length, 1);
    assert.ok(m.gaps[0].seconds >= 1800);
});

test('the limit never goes below the configured one, and a short trail keeps it', () => {
    assert.equal(trailEffectiveGapSeconds(walking(40, 0, 0, 1.3, 10), 90), 90, '3 x 10 s is under the limit');
    assert.equal(trailEffectiveGapSeconds(walking(4, 0, 0, 1.3, 600), 90), 90, 'three intervals say nothing about a rhythm');
    assert.equal(trailEffectiveGapSeconds(walking(40, 0, 0, 1.3, 600), 0), 0, 'no limit configured: never cut');
});

test('the stop badge carries the label and escapes what it is given', () => {
    const html = trailStopBadgeHtml('#2563eb', '12 λ.', false);
    assert.ok(html.includes('12 λ.') && html.includes('#2563eb') && html.includes('translate(-50%,-50%)'));
    assert.ok(trailStopBadgeHtml('#2563eb', '3 λ.', true).includes('translate(-50%,6px)'), 'a stop still going hangs under the pin');
    const evil = trailStopBadgeHtml('red"><script>', '<b>x</b>', false);
    assert.ok(!evil.includes('<script>') && !evil.includes('<b>'));
});

test('an empty or one-fix trail is harmless', () => {
    const none = trailDisplayModel([], {gapSeconds: GAP});
    assert.deepEqual([none.items, none.solid, none.gaps, none.lastIndex], [[], [], [], -1]);
    const one = trailDisplayModel([fix(0, 0, 0)], {gapSeconds: GAP});
    assert.equal(one.items.length, 1);
    assert.equal(one.solid.length, 0);
    assert.equal(one.lastIndex, 0);
});

test('durations come out as whole hours and minutes, never under a minute', () => {
    assert.deepEqual(trailDurationParts(200), {h: 0, m: 3});
    assert.deepEqual(trailDurationParts(1200), {h: 0, m: 20});
    assert.deepEqual(trailDurationParts(3 * 3600 + 1500), {h: 3, m: 25});
    assert.deepEqual(trailDurationParts(10), {h: 0, m: 1});
});
