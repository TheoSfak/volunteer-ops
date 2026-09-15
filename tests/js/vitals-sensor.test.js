/**
 * Unit tests for the pure half of assets/js/vitals-sensor.js — the Bluetooth
 * Heart Rate Measurement parser and the windowing that turns a 1Hz stream into
 * the rows the server stores.
 *
 * Worth testing precisely because the parser is the one place in this feature
 * where a silent misread produces a plausible-looking wrong number: a 16-bit
 * frame read as 8-bit gives a real-looking heart rate that is simply not the
 * one the strap measured, and nothing downstream could ever tell.
 *
 * Run: node --test tests/js/vitals-sensor.test.js
 */

const test = require('node:test');
const assert = require('node:assert');

const {
    parseHeartRateMeasurement,
    hexToDataView,
    vitalsWindowIndex,
    summariseWindow,
    HR_SERVICE_UUID,
    HR_MEASUREMENT_UUID,
} = require('../../assets/js/vitals-sensor.js');

const view = (...bytes) => new DataView(Uint8Array.from(bytes).buffer);

test('parses an 8-bit heart rate with no optional fields', () => {
    // flags 0x00 = uint8 value, no contact support, no energy, no RR
    const r = parseHeartRateMeasurement(view(0x00, 72));
    assert.strictEqual(r.bpm, 72);
    assert.strictEqual(r.contactSupported, false);
    assert.deepStrictEqual(r.rr, []);
});

test('parses a 16-bit heart rate little-endian', () => {
    // flags 0x01 = uint16. 0x00A4 = 164, sent low byte first.
    const r = parseHeartRateMeasurement(view(0x01, 0xa4, 0x00));
    assert.strictEqual(r.bpm, 164);
});

test('reports sensor contact states distinctly', () => {
    // bits 1-2: 0b10 = supported, not detected; 0b11 = supported and detected
    assert.deepStrictEqual(
        (({ contactSupported, contactDetected }) => ({ contactSupported, contactDetected }))(parseHeartRateMeasurement(view(0x04, 70))),
        { contactSupported: true, contactDetected: false }
    );
    assert.deepStrictEqual(
        (({ contactSupported, contactDetected }) => ({ contactSupported, contactDetected }))(parseHeartRateMeasurement(view(0x06, 70))),
        { contactSupported: true, contactDetected: true }
    );
});

test('skips the energy-expended field before reading RR intervals', () => {
    // flags 0x18 = energy expended present (bit3) + RR present (bit4), 8-bit HR.
    // Without the skip, the 2-byte energy value would be read as the first RR
    // interval and every interval after it would be off by two bytes.
    const r = parseHeartRateMeasurement(view(0x18, 60, 0xe8, 0x03, 0x00, 0x04));
    assert.strictEqual(r.bpm, 60);
    // 0x0400 = 1024 units = exactly 1000 ms
    assert.deepStrictEqual(r.rr, [1000]);
});

test('returns null for a truncated frame rather than a zero reading', () => {
    assert.strictEqual(parseHeartRateMeasurement(view(0x00)), null);
    assert.strictEqual(parseHeartRateMeasurement(view(0x01, 0xa4)), null, '16-bit frame missing its high byte');
    assert.strictEqual(parseHeartRateMeasurement(null), null);
});

test('hexToDataView decodes what the native bridge sends', () => {
    const v = hexToDataView('00 48');
    assert.strictEqual(v.byteLength, 2);
    assert.strictEqual(parseHeartRateMeasurement(v).bpm, 0x48);
    assert.strictEqual(hexToDataView('').byteLength, 0);
});

test('window index groups a second-by-second stream into sampling windows', () => {
    const base = 1_700_000_000_000;
    assert.strictEqual(vitalsWindowIndex(base, 5), vitalsWindowIndex(base + 4_999, 5));
    assert.notStrictEqual(vitalsWindowIndex(base, 5), vitalsWindowIndex(base + 5_000, 5));
});

test('window summary keeps the extremes alongside the average', () => {
    // The whole reason min/max travel with the average: this window reads as a
    // calm 140 unless the 170 survives.
    assert.deepStrictEqual(summariseWindow([130, 140, 170, 120]), { bpm: 140, min: 120, max: 170 });
    assert.deepStrictEqual(summariseWindow([80]), { bpm: 80, min: 80, max: 80 });
    assert.strictEqual(summariseWindow([]), null);
    assert.strictEqual(summariseWindow(null), null);
});

test('uses the standard assigned UUIDs, not a vendor-specific one', () => {
    // If these ever drift, the app stops seeing every strap on the market.
    assert.strictEqual(HR_SERVICE_UUID, '0000180d-0000-1000-8000-00805f9b34fb');
    assert.strictEqual(HR_MEASUREMENT_UUID, '00002a37-0000-1000-8000-00805f9b34fb');
});
