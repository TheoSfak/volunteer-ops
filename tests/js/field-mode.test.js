// Run with: node --test tests/js
//
// assets/js/field-mode.js: the offline field view. The page itself (Leaflet,
// GPS, the buttons) is checked in a browser; these are the pieces that decide
// what is written to the offline queue and what the radio door accepts.

const test = require('node:test');
const assert = require('node:assert/strict');

const {
    distanceM, bearingDeg, formatDistance, validLatLng,
    queueKey, enqueueInto, queuedFor, actionsFor, itemsForAction, statusItem,
    emptyFeed, applyFeedDelta, FEED_LIMITS, ageLabel,
} = require('../../assets/js/field-mode.js');

const NOW = Date.UTC(2026, 10, 2, 12, 0, 0);
const ISO = new Date(NOW).toISOString();

test('distance and bearing agree with known geography', () => {
    // Heraklion to Chania is roughly 120 km west-north-west.
    const heraklion = {lat: 35.3387, lng: 25.1442};
    const chania = {lat: 35.5138, lng: 24.0180};
    const km = distanceM(heraklion, chania) / 1000;
    assert.ok(km > 100 && km < 110, 'distance ' + km);
    const b = bearingDeg(heraklion, chania);
    assert.ok(b > 270 && b < 300, 'bearing ' + b);
    assert.equal(Math.round(bearingDeg({lat: 0, lng: 0}, {lat: 1, lng: 0})), 0);
    assert.equal(Math.round(bearingDeg({lat: 0, lng: 0}, {lat: 0, lng: 1})), 90);
    assert.equal(distanceM(heraklion, heraklion), 0);
});

test('distances read in metres under a kilometre and kilometres above it', () => {
    assert.equal(formatDistance(42), '40 m');
    assert.equal(formatDistance(1500), '1.50 km');
    assert.equal(formatDistance(25300), '25.3 km');
    assert.equal(formatDistance(NaN), '');
});

test('coordinates must be real numbers on the globe, and 0,0 means no fix', () => {
    assert.ok(validLatLng(35.2, 25.1));
    assert.ok(!validLatLng('35.2', 25.1));
    assert.ok(!validLatLng(91, 0));
    assert.ok(!validLatLng(0, 181));
    assert.ok(!validLatLng(0, 0));
    assert.ok(!validLatLng(NaN, 1));
});

test('an SOS is a needs_help status on the participation request, with the tap time and position', () => {
    assert.deepEqual(statusItem(77, 'needs_help', {lat: 35.2, lng: 25.1, acc: 12}, ISO), {
        kind: 'status', prId: '77', status: 'needs_help',
        extra: {reported_at: ISO, lat: 35.2, lng: 25.1, accuracy: 12},
    });
    assert.deepEqual(statusItem(77, 'on_site', null, ISO).extra, {reported_at: ISO});
    assert.equal(statusItem(null, 'needs_help', null, ISO), null);
});

test('a repeated identical status is not queued twice, so the first SOS time stands', () => {
    const first = statusItem(77, 'needs_help', null, ISO);
    const again = statusItem(77, 'needs_help', null, new Date(NOW + 60000).toISOString());
    let r = enqueueInto([], first);
    assert.ok(r.added);
    r = enqueueInto(r.queue, again);
    assert.ok(!r.added);
    assert.equal(r.queue.length, 1);
    assert.equal(r.queue[0].extra.reported_at, ISO);
    // on_way then needs_help is a real sequence and both stay.
    r = enqueueInto(r.queue, statusItem(77, 'on_way', null, ISO));
    assert.equal(r.queue.length, 2);
});

test('queue keys tell the kinds apart and ignore the ones they do not know', () => {
    assert.notEqual(queueKey({kind: 'order', action: 'acknowledge', orderId: '5'}), queueKey({kind: 'order', action: 'complete', orderId: '5'}));
    assert.equal(queueKey({kind: 'triage', params: {}}), null);
    // Triage items from the live page are never collapsed.
    const triage = {kind: 'triage', params: {a: 1}};
    assert.equal(enqueueInto([triage], triage).queue.length, 2);
});

const task = {key: 'order:9', kind: 'order', id: 9, type: 'task', acked: false, steps: 2, step: 0, canDecline: true};
const route = {key: 'route:3', kind: 'route', id: 3, orderId: 41, type: 'route', acked: false, steps: 3, step: 0,
    canDecline: true, nextWaypointId: 12, nextWaypointOpen: true};

test('a task order offers Received first, then Completed once the receipt is queued', () => {
    assert.deepEqual(actionsFor(task, []).actions, ['ack', 'decline']);
    const afterAck = itemsForAction(task, 'ack', {nowIso: ISO});
    assert.deepEqual(afterAck, [{kind: 'order', action: 'acknowledge', orderId: '9'}]);
    assert.deepEqual(actionsFor(task, afterAck).actions, ['complete', 'decline']);
    const afterComplete = afterAck.concat(itemsForAction(task, 'complete', {nowIso: ISO}));
    assert.deepEqual(actionsFor(task, afterComplete).actions, ['decline']);
    assert.ok(actionsFor(task, afterComplete).pending.complete);
});

test('an order that completes on receipt, or needs the camera, offers no Completed button', () => {
    const message = {kind: 'order', id: 4, type: 'message', acked: true, steps: 1, canDecline: false};
    assert.deepEqual(actionsFor(message, []).actions, []);
    const photo = {kind: 'order', id: 5, type: 'photo', acked: true, steps: 2, canDecline: true};
    assert.deepEqual(actionsFor(photo, []).actions, ['decline']);
});

test('a route is received through its order id and arrived at through its waypoint', () => {
    assert.deepEqual(actionsFor(route, []).actions, ['ack', 'arrive', 'decline']);
    assert.deepEqual(itemsForAction(route, 'ack', {nowIso: ISO}), [{kind: 'order', action: 'acknowledge', orderId: '41'}]);
    const arrive = itemsForAction(route, 'arrive', {nowIso: ISO, fix: {lat: 35.2, lng: 25.1, acc: 8}});
    assert.deepEqual(arrive, [{kind: 'route', action: 'arrive', id: '12', extra: {lat: 35.2, lng: 25.1, accuracy: 8, reported_at: ISO}}]);
    // No fix: the empty strings postRouteActionQueueable() would send.
    assert.deepEqual(itemsForAction(route, 'arrive', {nowIso: ISO}).pop().extra, {lat: '', lng: '', accuracy: '', reported_at: ISO});
    assert.ok(!actionsFor(route, arrive).actions.includes('arrive'));
    const closed = Object.assign({}, route, {nextWaypointOpen: false});
    assert.ok(!actionsFor(closed, []).actions.includes('arrive'));
});

test('"cannot do it" needs a known reason, and a note for "other"', () => {
    assert.deepEqual(itemsForAction(task, 'decline', {reason: 'unsafe', note: ''}),
        [{kind: 'decline', target: 'order', id: '9', reason: 'unsafe', note: ''}]);
    assert.deepEqual(itemsForAction(route, 'decline', {reason: 'busy', note: ' x '}),
        [{kind: 'decline', target: 'route', id: '3', reason: 'busy', note: 'x'}]);
    assert.deepEqual(itemsForAction(task, 'decline', {reason: 'other', note: '  '}), []);
    assert.equal(itemsForAction(task, 'decline', {reason: 'other', note: 'no torch'}).length, 1);
    assert.deepEqual(itemsForAction(task, 'decline', {reason: 'because', note: ''}), []);
    assert.equal(itemsForAction(task, 'decline', {reason: 'other', note: 'x'.repeat(500)})[0].note.length, 300);
    // Once handed back, the card offers nothing more.
    const declined = itemsForAction(task, 'decline', {reason: 'busy', note: ''});
    assert.deepEqual(actionsFor(task, declined).actions, []);
});

test('queuedFor reads only what belongs to this order', () => {
    const q = [{kind: 'order', action: 'acknowledge', orderId: '41'}];
    assert.ok(queuedFor(route, q).ack);
    assert.ok(!queuedFor(task, q).ack);
});

// ── window.voFieldFeed.apply(delta) ─────────────────────────────────────────

test('a delta carries positions, the command post, an order, an ack and a line of text', () => {
    const r = applyFeedDelta(emptyFeed(), {
        v: 1, at: NOW,
        positions: [{id: 'A2', name: 'Maria', lat: 35.2, lng: 25.1, acc: 9}],
        command: {lat: 35.21, lng: 25.11, note: 'base'},
        orders: [{code: 'GO-7', text: 'Move to the stream', lat: 35.22, lng: 25.12}],
        text: 'Back at 14:00',
    }, NOW);
    assert.deepEqual(r.rejected, []);
    assert.deepEqual(r.applied, {positions: 1, orders: 1, acks: 0, messages: 1, command: true});
    assert.equal(r.feed.positions.A2.name, 'Maria');
    assert.equal(r.feed.positions.A2.ts, NOW);
    assert.deepEqual(r.feed.command, {lat: 35.21, lng: 25.11, note: 'base', at: NOW});
    assert.equal(r.feed.orders[0].confirmed, false);

    const acked = applyFeedDelta(r.feed, {acks: ['GO-7']}, NOW + 1000);
    assert.equal(acked.feed.orders[0].confirmed, true);
    assert.equal(acked.applied.acks, 1);
});

test('a delta never changes the feed it was given', () => {
    const feed = emptyFeed();
    const frozen = JSON.stringify(feed);
    applyFeedDelta(feed, {positions: [{id: 'A', lat: 35, lng: 25}]}, NOW);
    assert.equal(JSON.stringify(feed), frozen);
});

test('a late frame cannot move a dot back, a new frame can', () => {
    let f = applyFeedDelta(emptyFeed(), {positions: [{id: 'A', lat: 35.2, lng: 25.1, ts: NOW}]}, NOW).feed;
    f = applyFeedDelta(f, {positions: [{id: 'A', lat: 35.9, lng: 25.9, ts: NOW - 5000}]}, NOW).feed;
    assert.equal(f.positions.A.lat, 35.2);
    f = applyFeedDelta(f, {positions: [{id: 'A', lat: 35.3, lng: 25.2, ts: NOW + 5000}]}, NOW).feed;
    assert.equal(f.positions.A.lat, 35.3);
});

test('what does not pass is reported and never stored', () => {
    const r = applyFeedDelta(emptyFeed(), {
        positions: [{id: 'A', lat: 'x', lng: 25}, {lat: 35, lng: 25}, {id: 'B', lat: 95, lng: 25}],
        command: {lat: 0, lng: 0},
        orders: [{text: 'no code'}, {code: 'C1', lat: 35.2}],
        acks: ['NOPE'],
        text: '   ',
    }, NOW);
    assert.deepEqual(r.applied, {positions: 0, orders: 0, acks: 0, messages: 0, command: false});
    assert.deepEqual(r.feed, emptyFeed());
    assert.deepEqual(r.rejected, ['positions[0]', 'positions[1]', 'positions[2]', 'command', 'orders[0]', 'orders[1].target', 'acks[0]', 'text']);
});

test('junk deltas and unknown versions are refused whole', () => {
    ['x', 5, null, undefined, [1, 2]].forEach(bad => {
        const r = applyFeedDelta(emptyFeed(), bad, NOW);
        assert.deepEqual(r.rejected, ['delta']);
    });
    assert.deepEqual(applyFeedDelta(emptyFeed(), {v: 2, text: 'hi'}, NOW).rejected, ['version']);
    assert.equal(applyFeedDelta(emptyFeed(), {v: 2, text: 'hi'}, NOW).feed.messages.length, 0);
});

test('text, names and codes are clipped, and control characters become spaces', () => {
    const r = applyFeedDelta(emptyFeed(), {
        positions: [{id: 'A'.repeat(40), name: 'N'.repeat(100), lat: 35, lng: 25}],
        text: 'a\nb' + 'x'.repeat(500),
    }, NOW);
    const id = Object.keys(r.feed.positions)[0];
    assert.equal(id.length, FEED_LIMITS.code);
    assert.equal(r.feed.positions[id].name.length, FEED_LIMITS.name);
    assert.equal(r.feed.messages[0].text.length, FEED_LIMITS.text);
    assert.ok(r.feed.messages[0].text.startsWith('a b'));
});

test('the feed keeps a bounded number of orders and messages', () => {
    let f = emptyFeed();
    for (let i = 0; i < 40; i++) {
        f = applyFeedDelta(f, {at: NOW + i, text: 'm' + i, orders: [{code: 'O' + i}]}, NOW).feed;
    }
    assert.equal(f.messages.length, FEED_LIMITS.messages);
    assert.equal(f.orders.length, FEED_LIMITS.orders);
    assert.equal(f.messages[f.messages.length - 1].text, 'm39');
});

test('the same frame heard twice is one message, and a re-sent order keeps its confirmation', () => {
    let f = applyFeedDelta(emptyFeed(), {at: NOW, text: 'hello', orders: [{code: 'G1', text: 'a'}]}, NOW).feed;
    f = applyFeedDelta(f, {acks: ['G1']}, NOW).feed;
    const again = applyFeedDelta(f, {at: NOW, text: 'hello', orders: [{code: 'G1', text: 'a changed'}]}, NOW);
    assert.equal(again.feed.messages.length, 1);
    assert.equal(again.applied.messages, 0);
    assert.equal(again.feed.orders.length, 1);
    assert.equal(again.feed.orders[0].text, 'a changed');
    assert.equal(again.feed.orders[0].confirmed, true);
});

test('a position is aged in minutes then hours, in the pages own words', () => {
    const T = {justNow: 'now', minutesAgo: '{n} min', hoursAgo: '{n} h'};
    assert.equal(ageLabel(NOW - 20000, NOW, T), 'now');
    assert.equal(ageLabel(NOW - 5 * 60000, NOW, T), '5 min');
    assert.equal(ageLabel(NOW - 150 * 60000, NOW, T), '3 h');
    assert.equal(ageLabel(null, NOW, T), '');
});
