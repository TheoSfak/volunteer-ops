// Run with: node --test tests/js
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const F = require('../../assets/js/training-case-find.js');

const north = (lat, metres) => lat + metres / 111194.9;

test('the constants are the agreed ones and match the PHP side', () => {
    assert.equal(F.FOUND_M, 10);
    assert.equal(F.CLOSE_M, 50);
    assert.equal(F.ACCURACY_CREDIT_M, 15);
    const php = fs.readFileSync(path.join(__dirname, '../../includes/functions-training-case-runs.php'), 'utf8');
    assert.match(php, /const TRAINING_CASE_FOUND_M = 10;/);
    assert.match(php, /const TRAINING_CASE_CLOSE_M = 50;/);
    assert.match(php, /const TRAINING_CASE_ACCURACY_CREDIT_M = 15;/);
});

test('distanceM is metres on the sphere', () => {
    const d = F.distanceM({lat: 35.3, lng: 25.1}, {lat: north(35.3, 100), lng: 25.1});
    assert.ok(Math.abs(d - 100) < 0.01, String(d));
    assert.equal(F.distanceM({lat: 35.3, lng: 25.1}, {lat: 35.3, lng: 25.1}), 0);
});

test('classify at the boundaries, like trainingCaseProximity()', () => {
    assert.equal(F.classify(10, null), 'found');
    assert.equal(F.classify(10.1, null), 'close');
    assert.equal(F.classify(50, null), 'close');
    assert.equal(F.classify(50.1, null), 'far');
});

test('classify credits GPS error, capped, and ignores a negative or missing one', () => {
    assert.equal(F.classify(14, 10), 'found');
    assert.equal(F.classify(25, 15), 'found');
    assert.equal(F.classify(25.1, 100), 'close');
    assert.equal(F.classify(9, -5), 'found');
    assert.equal(F.classify(64.9, 15), 'close');
    assert.equal(F.classify(65.1, 15), 'far');
    assert.equal(F.classify(30, undefined), 'close');
});

test('watchList keeps only the cases worth watching', () => {
    const spot = {lat: 35.3, lng: 25.1};
    const list = F.watchList([
        {id: 1, case: {placement_id: 11, can_play: true, spot, found: false, state: 'new', briefing: 'a'}},
        {id: 2, case: {placement_id: 12, can_play: true, spot, found: true, state: 'new'}},
        {id: 3, case: {placement_id: 13, can_play: true, spot, found: false, state: 'done'}},
        {id: 4, case: {placement_id: 14, can_play: false, spot, found: false, state: 'new'}},
        {id: 5, case: {placement_id: 15, can_play: true, spot: null, found: false, state: 'new'}},
        {id: 6, case: null},
        {id: 7},
        null,
    ]);
    assert.deepEqual(list, [{dispatch_id: 1, placement_id: 11, spot, briefing: 'a'}]);
    assert.deepEqual(F.watchList(null), []);
});

test('the tracker says close once and found once, and found never reverts', () => {
    const calls = [];
    const tracker = F.createTracker({
        onClose: (card, d) => calls.push(['close', card.placement_id, Math.round(d)]),
        onFound: (card, fix, d) => calls.push(['found', card.placement_id, Math.round(d)]),
    });
    const card = {placement_id: 11, spot: {lat: 35.3, lng: 25.1}};
    const at = metres => ({lat: north(35.3, metres), lng: 25.1, acc: 5});
    tracker.check([card], at(200));
    assert.deepEqual(calls, []);
    tracker.check([card], at(45));
    tracker.check([card], at(40));
    assert.deepEqual(calls, [['close', 11, 45]]);
    tracker.check([card], at(8));
    tracker.check([card], at(30));
    tracker.check([card], at(6));
    assert.deepEqual(calls, [['close', 11, 45], ['found', 11, 8]]);
});

test('a find from afar goes straight to found without a separate close', () => {
    const calls = [];
    const tracker = F.createTracker({onClose: () => calls.push('close'), onFound: () => calls.push('found')});
    tracker.check([{placement_id: 1, spot: {lat: 35.3, lng: 25.1}}], {lat: 35.3, lng: 25.1, acc: 5});
    assert.deepEqual(calls, ['found']);
});

test('the tracker ignores malformed input and keeps each placement apart', () => {
    const calls = [];
    const tracker = F.createTracker({onClose: c => calls.push(['close', c.placement_id]), onFound: c => calls.push(['found', c.placement_id])});
    tracker.check(null, {lat: 1, lng: 1});
    tracker.check([{placement_id: 1}, null, {placement_id: 2, spot: {lat: 'x', lng: 1}}], {lat: 1, lng: 1});
    tracker.check([{placement_id: 3, spot: {lat: 35.3, lng: 25.1}}], {lat: NaN, lng: 25.1});
    assert.deepEqual(calls, []);
    tracker.check([{placement_id: 3, spot: {lat: 35.3, lng: 25.1}}, {placement_id: 4, spot: {lat: north(35.3, 1000), lng: 25.1}}], {lat: 35.3, lng: 25.1, acc: 5});
    assert.deepEqual(calls, [['found', 3]]);
});

test('forget(placementId) re-arms the found and close latches of that placement only', () => {
    const calls = [];
    const tracker = F.createTracker({onClose: c => calls.push(['close', c.placement_id]), onFound: c => calls.push(['found', c.placement_id])});
    const spot = {lat: 35.3, lng: 25.1};
    const cards = [{placement_id: 1, spot}, {placement_id: 2, spot}];
    const here = {lat: 35.3, lng: 25.1, acc: 5};
    tracker.check(cards, here);
    assert.deepEqual(calls, [['found', 1], ['found', 2]]);
    tracker.check(cards, here);
    assert.equal(calls.length, 2, 'latched');
    tracker.forget(1);
    tracker.check(cards, here);
    assert.deepEqual(calls.slice(2), [['found', 1]], 'only placement 1 is armed again');
    // the close latch too
    const near = {lat: north(35.3, 40), lng: 25.1, acc: 5};
    const t2 = F.createTracker({onClose: c => calls.push(['close2', c.placement_id])});
    t2.check([{placement_id: 7, spot}], near);
    t2.check([{placement_id: 7, spot}], near);
    t2.forget(7);
    t2.check([{placement_id: 7, spot}], near);
    assert.equal(calls.filter(c => c[0] === 'close2').length, 2);
    tracker.forget(999); // unknown ids are harmless
});

test('a handler that throws cannot stop the other cards, and a failed onFound is retried on the next fix', () => {
    const errors = [];
    const realError = console.error;
    console.error = (...a) => errors.push(a);
    try {
        const calls = [];
        let failFirst = true;
        const tracker = F.createTracker({
            onFound: c => {
                if (c.placement_id === 1 && failFirst) { failFirst = false; throw new Error('storage full'); }
                calls.push(['found', c.placement_id]);
            },
            onClose: c => { if (c.placement_id === 3) throw new Error('boom'); calls.push(['close', c.placement_id]); },
        });
        const spot = {lat: 35.3, lng: 25.1};
        const here = {lat: 35.3, lng: 25.1, acc: 5};
        tracker.check([{placement_id: 1, spot}, {placement_id: 2, spot}], here);
        assert.deepEqual(calls, [['found', 2]], 'card 2 was still processed');
        tracker.check([{placement_id: 1, spot}, {placement_id: 2, spot}], here);
        assert.deepEqual(calls, [['found', 2], ['found', 1]], 'card 1 retried, card 2 not repeated');
        const near = {lat: north(35.3, 40), lng: 25.1, acc: 5};
        tracker.check([{placement_id: 3, spot}, {placement_id: 4, spot}], near);
        assert.deepEqual(calls.slice(2), [['close', 4]], 'a throwing onClose does not stop the next card');
        assert.ok(errors.length >= 2, 'the throws were logged');
    } finally {
        console.error = realError;
    }
});

// ---- v3.507.0: what the AR screen may show --------------------------------------------------------

const SPOT = {lat: 35.3, lng: 25.1};
const card = (over) => Object.assign({placement_id: 7, can_play: true, available: false, found: false, hidden: false, state: 'new', spot: SPOT, briefing: ''}, over || {});
const fixAt = (metres, acc) => ({lat: north(SPOT.lat, metres), lng: SPOT.lng, acc: acc === undefined ? 5 : acc});

test('victimView: nothing for no card, a card I cannot play, a finished run or a card without a spot', () => {
    assert.deepEqual(F.victimView(null, fixAt(5)), {mode: 'none'});
    assert.deepEqual(F.victimView(card({can_play: false}), fixAt(5)), {mode: 'none'});
    assert.deepEqual(F.victimView(card({state: 'done'}), fixAt(5)), {mode: 'none'});
    assert.deepEqual(F.victimView(card({spot: null}), fixAt(5)), {mode: 'none'});
    assert.deepEqual(F.victimView(card({spot: {lat: 'x', lng: 25.1}}), fixAt(5)), {mode: 'none'});
});

test('victimView: a victim whose place is shown (a point order) is a marker whatever the distance, and says whether it is found', () => {
    assert.deepEqual(F.victimView(card(), fixAt(400)), {mode: 'marker', found: false});
    assert.deepEqual(F.victimView(card(), null), {mode: 'marker', found: false}, 'no GPS fix yet: still drawn at the order\'s place');
    assert.deepEqual(F.victimView(card({found: true}), fixAt(400)), {mode: 'marker', found: true});
    assert.deepEqual(F.victimView(card({available: true}), fixAt(400)), {mode: 'marker', found: true});
});

test('victimView: a hidden victim is "close" from 50 m (with the accuracy credit) and never carries a spot or a distance', () => {
    const hidden = (over) => card(Object.assign({hidden: true}, over || {}));
    assert.deepEqual(F.victimView(hidden(), fixAt(200)), {mode: 'none'});
    assert.deepEqual(F.victimView(hidden(), fixAt(60)), {mode: 'none'});
    assert.deepEqual(F.victimView(hidden(), fixAt(49)), {mode: 'close'});
    assert.deepEqual(F.victimView(hidden(), fixAt(30)), {mode: 'close'});
    assert.deepEqual(F.victimView(hidden(), fixAt(8)), {mode: 'close'}, 'within 10 m and not yet found: still only "close"');
    assert.deepEqual(F.victimView(hidden(), fixAt(60, 15)), {mode: 'close'}, '60 m with the full 15 m credit is 45 m effective: close');
    assert.deepEqual(F.victimView(hidden(), fixAt(66, 15)), {mode: 'none'}, '66 m less the 15 m credit is 51 m: still far');
    assert.deepEqual(F.victimView(hidden(), fixAt(66, 100)), {mode: 'none'}, 'the credit is capped at 15 m, whatever the reported accuracy');
    assert.deepEqual(F.victimView(hidden(), fixAt(120, 100)), {mode: 'none'}, 'a vague fix cannot make a far victim close');
    assert.deepEqual(F.victimView(hidden(), null), {mode: 'none'});
    assert.deepEqual(F.victimView(hidden({found: true}), fixAt(8)), {mode: 'none'}, 'found: nothing left to hunt for');
    const close = F.victimView(hidden(), fixAt(30));
    assert.deepEqual(Object.keys(close), ['mode'], 'no spot, no distance, no direction');
    assert.deepEqual(Object.keys(F.victimView(hidden(), fixAt(300))), ['mode']);
});

test('canOpen: the case may be opened from the AR when it is playable, open to my team (or found on this phone) and not finished', () => {
    assert.equal(F.canOpen(card({available: true}), false), true);
    assert.equal(F.canOpen(card(), true), true, 'a find this phone has reported before the poll caught up');
    assert.equal(F.canOpen(card(), false), false);
    assert.equal(F.canOpen(card({available: true, can_play: false}), false), false);
    assert.equal(F.canOpen(card({available: true, state: 'done'}), false), false);
    assert.equal(F.canOpen(null, true), false);
    assert.equal(F.canOpen(card({state: 'running', available: true}), false), true, 'a run in progress can be resumed');
});
