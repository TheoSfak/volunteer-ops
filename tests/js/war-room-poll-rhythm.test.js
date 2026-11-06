// Run with: node --test tests/js
//
// assets/js/war-room-poll-rhythm.js (v3.450.0): how often the Action Room asks the server while nothing changes.
// Pinned: with the cap off nothing at all differs from the 5 s the page always had (every tick is due, the staleness
// limit is the old 20 s); the ladder (30 s of quiet -> 10 s, 90 s -> 15 s, 180 s -> 30 s) is never slower than the
// cap and never faster than the base; anything that happens puts it back at once; the staleness limit follows the
// rhythm; a clock that goes backwards cannot stall a stream; the three streams keep their own pace.

const test = require('node:test');
const assert = require('node:assert/strict');

const {
    WR_POLL_BASE_MS, wrPollIntervalMs, wrPollStaleMs, wrCreatePollRhythm, wrWatchPollActivity,
} = require('../../assets/js/war-room-poll-rhythm.js');

const S = 1000;

test('the base is the 5 seconds the page always had', () => {
    assert.equal(WR_POLL_BASE_MS, 5000);
});

test('with the cap off, or not above the base, the interval is always the base', () => {
    for (const cap of [0, -1, NaN, undefined, null, 5000, 4000]) {
        for (const quiet of [0, 29 * S, 30 * S, 10 * 60 * S, 1e9]) {
            assert.equal(wrPollIntervalMs(quiet, cap), 5000, 'cap ' + cap + ', quiet ' + quiet);
        }
    }
});

test('the ladder: 5 s for the first 30 s of quiet, then 10, 15 and 30', () => {
    const cap = 30 * S;
    assert.equal(wrPollIntervalMs(0, cap), 5000);
    assert.equal(wrPollIntervalMs(29999, cap), 5000);
    assert.equal(wrPollIntervalMs(30 * S, cap), 10000);
    assert.equal(wrPollIntervalMs(89999, cap), 10000);
    assert.equal(wrPollIntervalMs(90 * S, cap), 15000);
    assert.equal(wrPollIntervalMs(179999, cap), 15000);
    assert.equal(wrPollIntervalMs(180 * S, cap), 30000);
    assert.equal(wrPollIntervalMs(24 * 3600 * S, cap), 30000);
});

test('the cap is a ceiling: a cap of 10 s or 15 s is never exceeded', () => {
    for (const quiet of [0, 30 * S, 90 * S, 180 * S, 1e9]) {
        assert.ok(wrPollIntervalMs(quiet, 10 * S) <= 10000);
        assert.ok(wrPollIntervalMs(quiet, 15 * S) <= 15000);
    }
    assert.equal(wrPollIntervalMs(1e9, 10 * S), 10000);
    assert.equal(wrPollIntervalMs(1e9, 15 * S), 15000);
    assert.equal(wrPollIntervalMs(60 * S, 15 * S), 10000);
});

test('a negative quiet time (a clock that moved back) is the base, the fast side', () => {
    assert.equal(wrPollIntervalMs(-5000, 30 * S), 5000);
});

test('the staleness limit is today\'s 20 s at the base and follows the rhythm up', () => {
    assert.equal(wrPollStaleMs(5000), 20000);
    assert.equal(wrPollStaleMs(10000), 35000);
    assert.equal(wrPollStaleMs(15000), 50000);
    assert.equal(wrPollStaleMs(30000), 95000);
    assert.equal(wrPollStaleMs(1000), 20000, 'never under the 20 s');
});

function clockAt(start) {
    const c = {t: start, now: () => c.t, advance(ms) { c.t += ms; }};
    return c;
}

test('with the cap off every stream is due on every tick and the limit is 20 s', () => {
    const c = clockAt(1000000);
    const r = wrCreatePollRhythm(0, c.now);
    assert.equal(r.cap, 0);
    for (let i = 0; i < 100; i++) {
        c.advance(5000);
        assert.equal(r.due('main', 5000), true);
        assert.equal(r.due('chat', 5000), true);
        assert.equal(r.due('activity', 15000), true);
    }
    assert.equal(r.intervalMs(), 5000);
    assert.equal(r.staleMs(), 20000);
});

test('a cap at or below the base is the same as off', () => {
    const c = clockAt(0);
    const r = wrCreatePollRhythm(5000, c.now);
    assert.equal(r.cap, 0);
    c.advance(10 * 60 * S);
    assert.equal(r.intervalMs(), 5000);
    assert.equal(r.due('main', 5000), true);
});

test('on a quiet page the main stream slows down along the ladder', () => {
    const c = clockAt(0);
    const r = wrCreatePollRhythm(30 * S, c.now);
    const runs = [];
    for (let tick = 0; tick < 12 * 60; tick++) {           // 60 minutes of 5 s ticks
        if (r.due('main', 5000)) runs.push(c.t);
        c.advance(5000);
    }
    // 0..25 s: every tick (6 runs: 0,5,10,15,20,25), then every 10 s up to 90 s, every 15 s up to 180 s, then every 30 s
    const gaps = runs.slice(1).map((t, i) => (t - runs[i]) / 1000);
    assert.deepEqual(gaps.slice(0, 5), [5, 5, 5, 5, 5]);
    assert.ok(gaps.includes(10));
    assert.ok(gaps.includes(15));
    assert.equal(gaps[gaps.length - 1], 30);
    assert.ok(gaps.every(g => g >= 5 && g <= 30));
    // an hour of quiet: 120 polls at the 30 s steady state plus the climb up to it, against 720 at 5 s (-82%)
    assert.ok(runs.length >= 120 && runs.length <= 140, 'polls in an hour: ' + runs.length);
});

test('anything that happens puts the rhythm back to the base at once', () => {
    const c = clockAt(0);
    const r = wrCreatePollRhythm(30 * S, c.now);
    c.advance(10 * 60 * S);
    assert.equal(r.intervalMs(), 30000);
    assert.equal(r.staleMs(), 95000);
    r.bump();
    assert.equal(r.intervalMs(), 5000);
    assert.equal(r.staleMs(), 20000);
    c.advance(29 * S);
    assert.equal(r.intervalMs(), 5000);
    c.advance(1 * S);
    assert.equal(r.intervalMs(), 10000);
});

test('the streams keep their own pace: the feed never runs faster than its own 15 s', () => {
    const c = clockAt(0);
    const r = wrCreatePollRhythm(30 * S, c.now);
    const feed = [];
    for (let tick = 0; tick < 12 * 20; tick++) {            // 20 minutes of 5 s ticks
        if (r.due('activity', 15000)) feed.push(c.t);
        c.advance(5000);
    }
    const gaps = feed.slice(1).map((t, i) => (t - feed[i]) / 1000);
    assert.ok(gaps.every(g => g >= 15), 'gaps ' + gaps.join(','));
    assert.equal(gaps[gaps.length - 1], 30);
    // and a stream asking at the base while the rhythm is fast runs on its own timer
    const r2 = wrCreatePollRhythm(30 * S, clockAt(0).now);
    assert.equal(r2.due('chat', 5000), true);
});

test('a stream is judged by its own last run, not by the others\'', () => {
    const c = clockAt(0);
    const r = wrCreatePollRhythm(30 * S, c.now);
    c.advance(10 * 60 * S);                                  // quiet: 30 s rhythm
    assert.equal(r.due('main', 5000), true);                 // first time: runs
    assert.equal(r.due('chat', 5000), true);                 // its own first time
    c.advance(5000);
    assert.equal(r.due('main', 5000), false);
    assert.equal(r.due('chat', 5000), false);
    c.advance(25000);
    assert.equal(r.due('main', 5000), true);
    assert.equal(r.due('chat', 5000), true);
});

test('timer jitter does not skip a poll: a tick a little early still runs', () => {
    const c = clockAt(0);
    const r = wrCreatePollRhythm(30 * S, c.now);
    c.advance(10 * 60 * S);
    assert.equal(r.due('main', 5000), true);
    c.advance(29500);                                        // 30 s rhythm, tick 0.5 s early
    assert.equal(r.due('main', 5000), true);
    c.advance(29300);                                        // 0.7 s early is too early
    assert.equal(r.due('main', 5000), false);
});

test('a clock that goes backwards cannot stall a stream', () => {
    const c = clockAt(5000000);
    const r = wrCreatePollRhythm(30 * S, c.now);
    c.advance(10 * 60 * S);
    assert.equal(r.due('main', 5000), true);
    c.t -= 3 * 3600 * S;                                     // the device clock is set back three hours
    assert.equal(r.due('main', 5000), true);
    assert.equal(r.intervalMs(), 5000, 'and the page is back on the fast rhythm');
});

test('a touch, a click or a key press is activity; scrolling alone is not', () => {
    const handlers = {};
    const doc = {addEventListener(name, fn, opts) { (handlers[name] = handlers[name] || []).push({fn, opts}); }};
    const c = clockAt(0);
    const r = wrCreatePollRhythm(30 * S, c.now);
    wrWatchPollActivity(r, doc);
    assert.deepEqual(Object.keys(handlers).sort(), ['keydown', 'pointerdown', 'touchstart']);
    for (const name of Object.keys(handlers)) {
        assert.deepEqual(handlers[name][0].opts, {capture: true, passive: true}, name + ' must not block scrolling');
        c.advance(10 * 60 * S);
        assert.equal(r.intervalMs(), 30000);
        handlers[name][0].fn();
        assert.equal(r.intervalMs(), 5000, name);
    }
});
