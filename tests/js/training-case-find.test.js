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
