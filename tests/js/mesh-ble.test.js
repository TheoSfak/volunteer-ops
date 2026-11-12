// Run with: node --test tests/js
//
// assets/js/mesh-ble.js: the pure bits of the M1 BLE test harness - turning a
// decoded FromRadio message into a log line, the bounded log, and the hex <->
// bytes conversions the Capacitor plugin's bridge needs. The actual Bluetooth
// stack (native and Web Bluetooth) is checked by hand against real hardware.

const test = require('node:test');
const assert = require('node:assert/strict');

const MP = require('../../assets/js/mesh-protocol.js');
const {logLineFor, pushLog, hexToBytes, bytesToHex} = require('../../assets/js/mesh-ble.js');

test('logLineFor describes each message type', () => {
    assert.equal(logLineFor(null), 'empty read (queue drained)');
    assert.equal(logLineFor({type: 'myInfo', nodeNum: 0x570dee8c}), 'myInfo: !570dee8c');
    assert.equal(
        logLineFor({type: 'nodeInfo', node: {num: 0x570dee8c, longName: 'Meshtastic ee8', shortName: 'ee8c'}}),
        'nodeInfo: Meshtastic ee8 !570dee8c'
    );
    assert.equal(
        logLineFor({type: 'nodeInfo', node: {num: 0x570dee8c, longName: '', shortName: ''}}),
        'nodeInfo: (no name) !570dee8c'
    );
    assert.equal(logLineFor({type: 'configComplete', id: 1}), 'configComplete');
    assert.equal(logLineFor({type: 'bad'}), 'bad frame (could not decode)');
    assert.equal(logLineFor({type: 'other'}), 'other (ignored: settings, etc.)');
});

test('logLineFor includes the position when a packet carries one', () => {
    const withPos = {type: 'packet', packet: {from: 0x1234abcd, position: {lat: 35.33, lng: 25.14}}};
    assert.equal(logLineFor(withPos), 'packet from !1234abcd pos 35.33000,25.14000');
    const noFix = {type: 'packet', packet: {from: 0x1234abcd, position: {lat: null, lng: null}}};
    assert.equal(logLineFor(noFix), 'packet from !1234abcd');
    const notPosition = {type: 'packet', packet: {from: 0x1234abcd, position: null}};
    assert.equal(logLineFor(notPosition), 'packet from !1234abcd');
});

test('pushLog keeps only the most recent 50 lines, oldest first dropped', () => {
    let log = [];
    for (let i = 0; i < 55; i++) log = pushLog(log, 'line ' + i);
    assert.equal(log.length, 50);
    assert.equal(log[0], 'line 5');
    assert.equal(log[log.length - 1], 'line 54');
});

test('pushLog does not mutate the array it was given', () => {
    const original = ['a'];
    const next = pushLog(original, 'b');
    assert.deepEqual(original, ['a']);
    assert.deepEqual(next, ['a', 'b']);
});

test('hexToBytes and bytesToHex round-trip, and ignore separators on the way in', () => {
    const bytes = Uint8Array.of(0x94, 0xc3, 0x00, 0x07);
    assert.equal(bytesToHex(bytes), '94c30007');
    assert.deepEqual(hexToBytes('94c30007'), bytes);
    assert.deepEqual(hexToBytes('94 C3 00 07'), bytes);
});

test('a want_config written for BLE is the bare ToRadio bytes, no 0x94 0xC3 frame', () => {
    // mesh-gateway.js wraps the same call in frame() for the serial cable;
    // over BLE the GATT write already is the one message (see mesh-protocol.js).
    const serial = MP.encodeWantConfig(42);
    const ble = MP.encodeVarint(3, 42);
    assert.ok(serial.length > ble.length, 'the serial version carries the extra 4-byte header');
    assert.deepEqual(serial.slice(4), ble, 'once the header is stripped, the payload is identical');
});
