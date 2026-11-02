// Run with: node --test tests/js
//
// assets/js/mesh-gateway.js: the bookkeeping of the LoRa gateway page. The
// serial port and the window are checked on a real cable and in a browser;
// these are the rules that decide what is forwarded twice, what waits and
// what is shown.

const test = require('node:test');
const assert = require('node:assert/strict');

const {SeenSet, SendQueue, ageText, signalText} = require('../../assets/js/mesh-gateway.js');

test('a packet repeated by another node is taken once', () => {
    const seen = new SeenSet();
    assert.equal(seen.add(0x570dee8c, 7), true);
    assert.equal(seen.add(0x570dee8c, 7), false);
    // The same packet id from another node is another packet.
    assert.equal(seen.add(0x1234abcd, 7), true);
    // 32-bit numbers do not turn negative on the way.
    assert.equal(seen.add(0xfffffffe, 0xffffffff), true);
    assert.equal(seen.add(0xfffffffe, 0xffffffff), false);
});

test('the remembered set is bounded and forgets the oldest first', () => {
    const seen = new SeenSet(3);
    [1, 2, 3, 4].forEach(id => seen.add(9, id));
    assert.equal(seen.set.size, 3);
    assert.equal(seen.add(9, 1), true, 'the oldest was forgotten');
    assert.equal(seen.add(9, 4), false, 'the newest is still known');
});

test('the queue hands out batches in order and takes a failed one back at the front', () => {
    const q = new SendQueue(10);
    [1, 2, 3, 4, 5].forEach(n => q.push({n}));
    const first = q.take(2);
    assert.deepEqual(first.map(r => r.n), [1, 2]);
    q.push({n: 6});
    q.giveBack(first);
    assert.deepEqual(q.take(10).map(r => r.n), [1, 2, 3, 4, 5, 6]);
});

test('a page that cannot reach the server drops the oldest, not the newest', () => {
    const q = new SendQueue(3);
    [1, 2, 3, 4, 5].forEach(n => q.push({n}));
    assert.deepEqual(q.take(10).map(r => r.n), [3, 4, 5]);
    const back = new SendQueue(3);
    [10, 11].forEach(n => back.push({n}));
    back.giveBack([{n: 1}, {n: 2}, {n: 3}]);
    assert.equal(back.length, 3);
});

test('ages and signal read as one short line', () => {
    assert.equal(ageText(12), '12 s');
    assert.equal(ageText(59.6), '60 s');
    assert.equal(ageText(240), '4 min');
    assert.equal(ageText(7200), '2 h');
    assert.equal(ageText(null), '');
    assert.equal(signalText({snr: 6.5, rssi: -97, hops: 1}), 'SNR 6.5 dB · -97 dBm · 1 hop');
    assert.equal(signalText({snr: null, rssi: -97, hops: null}), '-97 dBm');
    assert.equal(signalText({}), '');
    assert.equal(signalText({snr: 0, rssi: 0, hops: 0}), 'SNR 0 dB · 0 dBm · 0 hop');
});
