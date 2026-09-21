// Run with: node --test tests/js
//
// The codec negotiation behind the push-to-talk emergency voice channel.
//
// The ordering of AUDIO_RECORDER_MIME_CANDIDATES is not a preference, it is the
// feature working or not working: Safari is the only engine here that cannot
// fall back, because it will not decode Opus-in-WebM. A clip an Android
// volunteer records as WebM is therefore silent on the coordinator's iPad, and
// a Safari that can only record MP4 has no voice channel at all if MP4 is not
// offered first. Every engine can play MP4/AAC; not every one can play WebM.
//
// These are pinned here rather than left to a comment because the failure is
// silent in exactly the wrong direction — a WebM clip uploads, stores and
// serves perfectly, and only fails at the moment somebody in a command post
// presses play on an emergency call.

const test = require('node:test');
const assert = require('node:assert/strict');

const {
    AUDIO_RECORDER_MIME_CANDIDATES,
    audioExtensionForMimeType,
    pickVideoCompressionMimeType,
} = require('../../assets/js/war-room-utils.js');

test('MP4 is offered before WebM, because Safari cannot fall back', () => {
    const firstMp4 = AUDIO_RECORDER_MIME_CANDIDATES.findIndex(c => c.includes('mp4'));
    const firstWebm = AUDIO_RECORDER_MIME_CANDIDATES.findIndex(c => c.includes('webm'));

    assert.ok(firstMp4 >= 0, 'no mp4 candidate at all');
    assert.ok(firstWebm >= 0, 'no webm fallback at all');
    assert.ok(firstMp4 < firstWebm, 'a browser that can record MP4 must never be pushed onto WebM');
});

test('both mp4 spellings are offered, since engines disagree about which they answer to', () => {
    // A real, observed inconsistency: some engines report false for the
    // codec-qualified string and true for the bare one, others the reverse.
    const mp4s = AUDIO_RECORDER_MIME_CANDIDATES.filter(c => c.includes('mp4'));
    assert.ok(mp4s.length >= 2, 'one mp4 spelling is not enough');
    assert.ok(mp4s.some(c => c.includes('codecs=')), 'no codec-qualified mp4 spelling');
    assert.ok(mp4s.some(c => !c.includes('codecs=')), 'no bare mp4 spelling');
});

test('a Safari that only records MP4 still gets a working recorder', () => {
    const safariOnly = c => c === 'audio/mp4;codecs=mp4a.40.2' || c === 'audio/mp4';
    const picked = pickVideoCompressionMimeType(AUDIO_RECORDER_MIME_CANDIDATES, safariOnly);
    assert.ok(picked && picked.includes('mp4'));
});

test('a browser that records everything is still handed MP4', () => {
    const picked = pickVideoCompressionMimeType(AUDIO_RECORDER_MIME_CANDIDATES, () => true);
    assert.ok(picked.includes('mp4'), 'where both work, pick the one everybody can play');
});

test('a browser that records nothing gets null rather than a broken recorder', () => {
    // war-room.php falls back to `new MediaRecorder(stream)` with no options in
    // this case, which is strictly better than forcing an unsupported type.
    assert.equal(pickVideoCompressionMimeType(AUDIO_RECORDER_MIME_CANDIDATES, () => false), null);
});

test('the extension follows the negotiated type, never a guess', () => {
    // mission-voice.php checks extension AND sniffed MIME against each other
    // and rejects a mismatch, so a wrong extension here is a rejected upload.
    assert.equal(audioExtensionForMimeType('audio/mp4;codecs=mp4a.40.2'), 'm4a');
    assert.equal(audioExtensionForMimeType('audio/mp4'), 'm4a');
    assert.equal(audioExtensionForMimeType('audio/webm;codecs=opus'), 'webm');
    assert.equal(audioExtensionForMimeType('audio/ogg;codecs=opus'), 'ogg');
});

test('every candidate maps to an extension the server actually accepts', () => {
    // The server's allow-list is ['m4a','mp4','webm','ogg']; drifting apart
    // here means an upload that records fine and is refused on arrival.
    const serverAccepts = ['m4a', 'mp4', 'webm', 'ogg'];
    for (const candidate of AUDIO_RECORDER_MIME_CANDIDATES) {
        assert.ok(
            serverAccepts.includes(audioExtensionForMimeType(candidate)),
            `${candidate} yields an extension mission-voice.php would reject`
        );
    }
});

test('a junk or missing mimeType still yields something uploadable', () => {
    // MediaRecorder.mimeType is occasionally empty; war-room.php passes it
    // through regardless, so this must not produce an extension-less filename.
    assert.equal(audioExtensionForMimeType(''), 'webm');
    assert.equal(audioExtensionForMimeType(null), 'webm');
    assert.equal(audioExtensionForMimeType(undefined), 'webm');
});
