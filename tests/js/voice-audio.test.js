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
    VOICE_AUDIO_CONSTRAINTS,
    VOICE_AUDIO_BITS_PER_SECOND,
    voiceRecorderOptions,
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

// ── Capture quality ─────────────────────────────────────────────────────────

test('capture is mono, because stereo spends half its bits on a second mic', () => {
    // Voice is mono. A stereo clip at the same bitrate is WORSE, not better.
    assert.equal(VOICE_AUDIO_CONSTRAINTS.channelCount, 1);
});

test('the two processors that matter outdoors are on', () => {
    // Wind and running water are what noiseSuppression removes; autoGainControl
    // is what makes a shout over a rotor and a whisper from under a rock
    // equally audible at the command post.
    assert.equal(VOICE_AUDIO_CONSTRAINTS.noiseSuppression, true);
    assert.equal(VOICE_AUDIO_CONSTRAINTS.autoGainControl, true);
});

test('no constraint is exact, so a phone that cannot meet one still records', () => {
    // A plain value is a hint the browser may ignore; {exact: …} throws
    // OverconstrainedError. A voice channel must never fail to OPEN because a
    // device would only give stereo — that trades quality for silence.
    for (const [key, value] of Object.entries(VOICE_AUDIO_CONSTRAINTS)) {
        assert.ok(
            value === null || typeof value !== 'object',
            `${key} is an object, which risks OverconstrainedError`
        );
    }
});

test('the bitrate is at least what the engine default was, never below it', () => {
    // Measured: Chrome's own default for an audio-only recorder was 128 kbps
    // STEREO, i.e. ~64 kbps carrying the voice. Paired with channelCount 1 the
    // same figure now describes one channel, which is the real improvement.
    // Setting anything lower here would quietly make clips WORSE than before
    // this feature tried to improve them — the trap this test exists to catch.
    assert.ok(VOICE_AUDIO_BITS_PER_SECOND >= 128000, 'below the engine default is a regression, not a tuning');
    assert.ok(VOICE_AUDIO_BITS_PER_SECOND <= 192000, 'past the point more bits buy intelligibility for speech');
});

test('the bitrate buys a mono channel, not a duplicated stereo one', () => {
    // The pairing is the point: 128 kbps across two channels of the same voice
    // is half the bitrate per channel of 128 kbps across one.
    assert.equal(VOICE_AUDIO_CONSTRAINTS.channelCount, 1);
    assert.equal(voiceRecorderOptions('audio/mp4').audioBitsPerSecond, VOICE_AUDIO_BITS_PER_SECOND);
});

test('a full-length clip still fits well inside what the server accepts', () => {
    // VOICE_MAX_MS is 60s in war-room.php; mission-voice.php caps uploads at 8MB.
    const maxBytes = (VOICE_AUDIO_BITS_PER_SECOND / 8) * 60;
    assert.ok(maxBytes < 8 * 1024 * 1024, 'a 60s clip could be refused on arrival');
});

test('recorder options carry the bitrate and the negotiated container', () => {
    const opts = voiceRecorderOptions('audio/mp4;codecs=mp4a.40.2');
    assert.equal(opts.mimeType, 'audio/mp4;codecs=mp4a.40.2');
    assert.equal(opts.audioBitsPerSecond, VOICE_AUDIO_BITS_PER_SECOND);
});

test('nothing negotiated means NO mimeType key, not a null one', () => {
    // new MediaRecorder(stream, {mimeType: null}) throws. Omitting the key
    // lets the browser fall back to its own default container.
    const opts = voiceRecorderOptions(null);
    assert.ok(!('mimeType' in opts), 'a null mimeType would throw at construction');
    assert.equal(opts.audioBitsPerSecond, VOICE_AUDIO_BITS_PER_SECOND);
});
