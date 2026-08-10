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
        'compass.n': 'Β', 'compass.ne': 'ΒΑ', 'compass.e': 'Α', 'compass.se': 'ΝΑ',
        'compass.s': 'Ν', 'compass.sw': 'ΝΔ', 'compass.w': 'Δ', 'compass.nw': 'ΒΔ',
        'route.deliverable_photo': 'φωτογραφία',
        'route.deliverable_video': 'βίντεο',
        'route.deliverable_note': 'σημείωση',
    };
    return strings[key] ?? key;
};

const {
    bearing,
    escapeHtml,
    parseCoordsInput,
    formatDistanceMeters,
    bearingToCompassAbbr,
    missingRouteDeliverablesClientSide,
    shouldSkipVideoCompression,
    pickVideoCompressionMimeType,
    videoExtensionForMimeType,
} = require('../../assets/js/war-room-utils.js');

test('bearing() points east from due-west movement', () => {
    const deg = bearing({ lat: 0, lng: 0 }, { lat: 0, lng: 1 });
    assert.ok(Math.abs(deg - 90) < 0.01, `expected ~90, got ${deg}`);
});

test('bearing() points north', () => {
    const deg = bearing({ lat: 0, lng: 0 }, { lat: 1, lng: 0 });
    assert.ok(Math.abs(deg - 0) < 0.01, `expected ~0, got ${deg}`);
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

test('videoExtensionForMimeType() defaults to webm for empty/missing input', () => {
    assert.equal(videoExtensionForMimeType(''), 'webm');
    assert.equal(videoExtensionForMimeType(null), 'webm');
});
