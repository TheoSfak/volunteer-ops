// Run with: node --test tests/js
//
// assets/js/media-queue.js: the offline queue for photos and videos. The
// IndexedDB half is checked in a browser (Node has no IndexedDB); these are the
// decisions around it - what is sent, what is retried, what goes first.

const test = require('node:test');
const assert = require('node:assert/strict');

const {
    isIosDevice, newMediaId, mediaFileName, mediaKind, makeSpec, buildFormData,
    classifyFailure, queuedMediaFor, countQueuedMedia, nextQueueIndex,
} = require('../../assets/js/media-queue.js');

const entries = fd => Array.from(fd.entries()).reduce((o, [k, v]) => { o[k] = v; return o; }, {});

test('an iPhone and an iPad are told apart from a Mac and from Android', () => {
    assert.equal(isIosDevice({userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X)', platform: 'iPhone', maxTouchPoints: 5}), true);
    assert.equal(isIosDevice({userAgent: 'Mozilla/5.0 (iPad; CPU OS 16_0 like Mac OS X)', platform: 'iPad', maxTouchPoints: 5}), true);
    // iPadOS 13+ reports a Mac; only its touch screen gives it away.
    assert.equal(isIosDevice({userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)', platform: 'MacIntel', maxTouchPoints: 5}), true);
    assert.equal(isIosDevice({userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)', platform: 'MacIntel', maxTouchPoints: 0}), false);
    assert.equal(isIosDevice({userAgent: 'Mozilla/5.0 (Linux; Android 14; Pixel 8) Chrome/120', platform: 'Linux armv8l', maxTouchPoints: 5}), false);
    assert.equal(isIosDevice({userAgent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)', platform: 'Win32', maxTouchPoints: 0}), false);
    assert.equal(isIosDevice(null), false);
});

test('a media id is something the server accepts as a client_uuid', () => {
    const accepts = /^[A-Za-z0-9-]{8,64}$/;
    assert.match(newMediaId(), accepts);
    // Without randomUUID() (an insecure context) the fallback is still valid and still unique.
    const bare = {getRandomValues: bytes => { for (let i = 0; i < bytes.length; i++) bytes[i] = (i * 37 + 11) & 255; return bytes; }};
    assert.match(newMediaId(bare), accepts);
    assert.match(newMediaId({}), accepts);
    assert.notEqual(newMediaId({}), newMediaId({}));
});

test('a file with no usable name gets one from its type, a good name is kept', () => {
    assert.equal(mediaFileName({name: 'IMG_0042.MOV', type: 'video/quicktime'}), 'IMG_0042.MOV');
    assert.equal(mediaFileName({name: '', type: 'video/mp4'}), 'field-video.mp4');
    assert.equal(mediaFileName({name: 'blob', type: 'video/webm;codecs=vp9'}), 'field-video.webm');
    assert.equal(mediaFileName({name: 'image', type: 'image/png'}), 'field-photo.png');
    assert.equal(mediaFileName({type: ''}), 'field-photo.jpg');
    assert.equal(mediaKind({type: 'video/mp4'}), 'video');
    assert.equal(mediaKind({type: 'image/jpeg'}), 'photo');
});

test('the form of a first attempt carries the guard but not the capture time', () => {
    const file = new Blob(['x'.repeat(10)], {type: 'image/jpeg'});
    const spec = makeSpec({missionId: 77, file, fix: {lat: 35.31, lng: 25.12, acc: 8}, capturedAt: '2026-11-02T10:00:00.000Z'});
    const form = entries(buildFormData(spec, 'tok', false));
    assert.equal(form.csrf_token, 'tok');
    assert.equal(form.action, 'upload');
    assert.equal(form.mission_id, '77');
    assert.equal(form.client_uuid, spec.id);
    assert.equal(form.media.name, 'field-photo.jpg');
    assert.equal(form.lat, '35.31');
    assert.equal(form.lng, '25.12');
    assert.equal(form.accuracy, '8');
    assert.equal('reported_at' in form, false);
    assert.equal('queued' in form, false);
    assert.equal('is_poi' in form, false);
    assert.equal('route_waypoint_id' in form, false);
});

test('a replay carries the moment it was taken, and the same guard as the first attempt', () => {
    const file = new Blob(['v'], {type: 'video/mp4'});
    const thumb = new Blob(['t'], {type: 'image/jpeg'});
    const spec = makeSpec({missionId: 5, file, thumb, fix: null, waypointId: '31', capturedAt: '2026-11-02T10:00:00.000Z'});
    const first = entries(buildFormData(spec, 'tok', false));
    const replay = entries(buildFormData(spec, 'tok', true));
    assert.equal(replay.client_uuid, first.client_uuid);
    assert.equal(replay.reported_at, '2026-11-02T10:00:00.000Z');
    assert.equal(replay.queued, '1');
    assert.equal(replay.route_waypoint_id, '31');
    assert.equal(replay.thumb.name, 'thumb.jpg');
    // No fix: no lat/lng at all, never an empty or zero one (the server drops 0,0 anyway).
    assert.equal('lat' in replay, false);
    assert.equal('accuracy' in replay, false);
});

test('a fix without an accuracy sends the position only', () => {
    const spec = makeSpec({missionId: 1, file: new Blob(['x'], {type: 'image/jpeg'}), fix: {lat: 35, lng: 25, acc: null}});
    const form = entries(buildFormData(spec, 't', false));
    assert.equal(form.lat, '35');
    assert.equal('accuracy' in form, false);
});

test('a point of interest sends its flag and its note, even an empty one', () => {
    const spec = makeSpec({missionId: 1, file: new Blob(['x'], {type: 'image/jpeg'}), fix: {lat: 35, lng: 25, acc: 5}, isPoi: true, note: ''});
    const form = entries(buildFormData(spec, 't', true));
    assert.equal(form.is_poi, '1');
    assert.equal(form.note, '');
});

test('a failed upload is sorted into what to do about it', () => {
    assert.equal(classifyFailure({wrConnection: true, wrWrite: true}), 'network');
    // Login expired: the XHR followed the redirect and got the login page.
    assert.equal(classifyFailure({wrRedirected: true, wrStatus: 200}), 'retry');
    assert.equal(classifyFailure({wrStatus: 503}), 'retry');
    assert.equal(classifyFailure({wrStatus: 502}), 'retry');
    assert.equal(classifyFailure({wrStatus: 504}), 'retry');
    // Over the server's own size limit: sending it again changes nothing.
    assert.equal(classifyFailure({wrStatus: 413}), 'reject');
    assert.equal(classifyFailure({wrStatus: 500}), 'count');
    assert.equal(classifyFailure(new SyntaxError('Unexpected token <')), 'count');
    assert.equal(classifyFailure(null), 'count');
});

test('what is waiting for a route point is read from the queue', () => {
    const queue = [
        {kind: 'status', prId: 3, status: 'needs_help'},
        {kind: 'media', mediaId: 'a', mediaType: 'photo', waypointId: 31},
        {kind: 'media', mediaId: 'b', mediaType: 'video', waypointId: 32},
        {kind: 'media', mediaId: 'c', mediaType: 'photo', waypointId: null},
    ];
    assert.deepEqual(queuedMediaFor(queue, 31), {photo: true, video: false, any: true});
    assert.deepEqual(queuedMediaFor(queue, '32'), {photo: false, video: true, any: true});
    assert.deepEqual(queuedMediaFor(queue, 99), {photo: false, video: false, any: false});
    assert.equal(countQueuedMedia(queue), 3);
    assert.equal(countQueuedMedia([]), 0);
});

test('an SOS never waits behind a file, and files go in their own order', () => {
    const queue = [
        {kind: 'media', mediaId: 'a', mediaType: 'video', waypointId: null},
        {kind: 'media', mediaId: 'b', mediaType: 'photo', waypointId: null},
        {kind: 'status', prId: 3, status: 'needs_help'},
    ];
    assert.equal(nextQueueIndex(queue, 'fast'), 2);
    assert.equal(nextQueueIndex(queue, 'media'), 0);
});

test('a queue with no files behaves exactly as before: first item first', () => {
    const queue = [
        {kind: 'route', action: 'arrive', id: '9'},
        {kind: 'status', prId: 3, status: 'on_site'},
    ];
    assert.equal(nextQueueIndex(queue, 'fast'), 0);
    assert.equal(nextQueueIndex(queue, 'media'), -1);
    assert.equal(nextQueueIndex([], 'fast'), -1);
    assert.equal(nextQueueIndex([], 'media'), -1);
});

test('completing a point waits for its own photo, and holds the route behind it', () => {
    const queue = [
        {kind: 'media', mediaId: 'a', mediaType: 'photo', waypointId: 31},
        {kind: 'route', action: 'complete', id: '31'},
        {kind: 'route', action: 'depart', id: '32'},
        {kind: 'status', prId: 3, status: 'on_site'},
    ];
    // The point's completion and the next departure both wait; the status does not.
    assert.equal(nextQueueIndex(queue, 'fast'), 3);
    assert.equal(nextQueueIndex(queue, 'media'), 0);
    // Once the photo has gone, the route is released in order.
    assert.equal(nextQueueIndex(queue.slice(1), 'fast'), 0);
});

test('a point is not held by a file that belongs to a different point', () => {
    const queue = [
        {kind: 'media', mediaId: 'a', mediaType: 'photo', waypointId: 40},
        {kind: 'route', action: 'complete', id: '31'},
    ];
    assert.equal(nextQueueIndex(queue, 'fast'), 1);
});
