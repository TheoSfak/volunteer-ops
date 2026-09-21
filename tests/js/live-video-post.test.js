// Run with: node --test tests/js
//
// The live-video client's two silent-failure guards, pinned.
//
// Both exist because of the same field report: an Android volunteer tapped to
// go on air and got "…is not valid JSON" on screen. The camera was never the
// problem — mission-live.php had answered with something that was not JSON
// (an HTML login page after the session expired, a 503 when the database ran
// out of connections, or a PHP warning printed ahead of the JSON) and a bare
// r.json() turned that into a raw browser exception pasted into the card.
//
// Neither guard can be tested from the page: one only fires when the server
// misbehaves, the other only when the session is already dead. So the real
// code is lifted out of war-room.php and run here against those answers.
// Nothing is reimplemented — if the source changes, this runs the change.

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const SRC = fs.readFileSync(path.join(__dirname, '../../war-room.php'), 'utf8');

// Functions in that block are indented four spaces and close on a line that is
// exactly "    }". If either anchor ever stops matching, say so plainly rather
// than failing with something cryptic ten lines later.
function lift(name) {
    const start = SRC.indexOf('    function ' + name + '(');
    assert.ok(start >= 0, 'war-room.php no longer defines ' + name + '() — update this test\'s anchor');
    const end = SRC.indexOf('\n    }', start);
    assert.ok(end > start, 'could not find the end of ' + name + '() in war-room.php');
    return SRC.slice(start, end + 6);
}

function liftRegion(fromMarker, toMarker) {
    const from = SRC.indexOf(fromMarker);
    const to = SRC.indexOf(toMarker);
    assert.ok(from >= 0, 'war-room.php no longer contains ' + JSON.stringify(fromMarker));
    assert.ok(to > from, 'war-room.php no longer contains ' + JSON.stringify(toMarker) + ' after it');
    return SRC.slice(from, to);
}

// ── post(): every answer shape the server can produce ──────────────────────

function buildPost(answer) {
    const seen = {banners: [], log: []};
    const sandbox = {
        csrfToken: 'x', MISSION_ID: 1, URLSearchParams,
        fetch: () => Promise.resolve({
            status: answer.status || 200,
            redirected: !!answer.redirected,
            url: answer.url || 'https://example/mission-live.php',
            headers: {get: () => answer.ctype || 'text/html; charset=UTF-8'},
            text: () => Promise.resolve(answer.body || '')
        }),
        // The page-wide red/amber bar. Returning false is what it does for
        // every answer it classifies.
        checkSessionAlive: r => { seen.banners.push(r.status === 503 ? 'busy' : 'session'); return false; },
        dbg: m => seen.log.push(m)
    };
    const code = lift('liveError') + '\n' + lift('post');
    const post = new Function(...Object.keys(sandbox), code + '; return post;')(...Object.values(sandbox));
    return {post, seen};
}

async function kindOf(answer) {
    const {post, seen} = buildPost(answer);
    try {
        const value = await post('accept');
        return {kind: null, value, seen};
    } catch (e) {
        return {kind: e.liveKind || ('UNTYPED:' + e.name), seen};
    }
}

test('a real JSON answer is returned untouched', async () => {
    const r = await kindOf({status: 200, ctype: 'application/json', body: '{"ok":true,"streamId":7}'});
    assert.equal(r.kind, null);
    assert.deepEqual(r.value, {ok: true, streamId: 7});
    assert.deepEqual(r.seen.banners, [], 'a good answer must not raise any banner');
});

test('an expired session is a session error, not a camera error', async () => {
    const r = await kindOf({status: 200, redirected: true, url: 'https://example/login.php',
                            ctype: 'text/html', body: '<!DOCTYPE html><html>login</html>'});
    assert.equal(r.kind, 'session');
    assert.deepEqual(r.seen.banners, ['session'], 'the page-wide session bar must appear');
});

test('a 503 is the database, not the session', async () => {
    const r = await kindOf({status: 503, ctype: 'text/html', body: '<html>503</html>'});
    assert.equal(r.kind, 'busy');
    assert.deepEqual(r.seen.banners, ['busy']);
});

test('a PHP warning printed ahead of the JSON is caught, and logged verbatim', async () => {
    // display_errors is on in production, so this is not hypothetical.
    const r = await kindOf({status: 200, ctype: 'application/json',
                            body: '<br /><b>Warning</b>: Undefined array key "x" in /y.php on line 3<br />{"ok":true}'});
    assert.equal(r.kind, 'server');
    // The warning text IS the diagnosis; losing it is what r.json() did.
    assert.ok(r.seen.log.some(l => l.includes('Undefined array key')),
        'the body that failed to parse must reach the log');
});

test('a 500 HTML page does not claim the session expired', async () => {
    const r = await kindOf({status: 500, ctype: 'text/html', body: '<html>500</html>'});
    assert.equal(r.kind, 'server');
    // Telling someone hitting a 500 to log in again is advice that cannot work.
    assert.deepEqual(r.seen.banners, []);
});

// ── The diagnostic buffer and its report ───────────────────────────────────

function buildReporter() {
    const state = {posted: [], store: {}, next: null};
    const sandbox = {
        LIVE_DEBUG: false, csrfToken: 'x', URLSearchParams,
        document: {querySelector: () => null, getElementById: () => null,
                   createElement: () => ({style: {}, classList: {add() {}}}), body: {appendChild() {}}},
        localStorage: {
            getItem: k => (k in state.store ? state.store[k] : null),
            setItem: (k, v) => { state.store[k] = String(v); },
            removeItem: k => { delete state.store[k]; }
        },
        fetch: (url, opt) => {
            state.posted.push(Object.fromEntries(new URLSearchParams(opt.body)));
            return Promise.resolve(state.next);
        }
    };
    const code = liftRegion('    const dbgBuf = [];', '    setTimeout(liveReportFlush, 8000);');
    const api = new Function(...Object.keys(sandbox),
        code + '; return {dbg, dbgBuf, liveReport, liveReportFlush};')(...Object.values(sandbox));
    api.stash = () => sandbox.localStorage.getItem('vopsLiveReport');
    api.answer = a => { state.next = Promise.resolve(a); };
    api.posted = () => state.posted;
    return api;
}

const ANSWER_OK = {ok: true, redirected: false, headers: {get: () => 'application/json'}};
const ANSWER_LOGIN = {ok: true, redirected: true, headers: {get: () => 'text/html'}};
const settle = () => new Promise(r => setImmediate(r));

function writeIdentity(api) {
    // Exactly the lines war-room.php writes at load, in order.
    api.dbg('UA Mozilla/5.0 (Linux; Android 11; SM-A125F) Chrome/120');
    api.dbg('secure=true sdk=loaded 2.22.3 gUM=true');
    api.dbg('standalone=false nativeApp=false');
    api.dbg('can send: VP8, H264');
}

test('trimming the buffer never eats the device identity', () => {
    const api = buildReporter();
    writeIdentity(api);
    for (let i = 0; i < 400; i++) api.dbg('noise ' + i);

    assert.equal(api.dbgBuf.length, 140, 'the buffer must still be capped');
    // The whole point: a report that cannot say which phone it came from is
    // the report this feature has already been defeated by twice.
    assert.match(api.dbgBuf[0], /SM-A125F/);
    assert.match(api.dbgBuf[3], /can send/);
    assert.match(api.dbgBuf[139], /noise 399/, 'and the tail must be the most recent lines');
});

test('a report sent on a dead session is kept, then flushed on the next load', async () => {
    const api = buildReporter();
    writeIdentity(api);

    // The session is gone: the POST follows the redirect to the login page and
    // comes back 200 HTML, which is not a network error and never used to fire
    // .catch(). The likeliest failure was the one guaranteed to be unreadable.
    api.answer(ANSWER_LOGIN);
    api.liveReport('start-failed: session');
    await settle();

    const stashed = JSON.parse(api.stash());
    assert.equal(stashed.reason, 'start-failed: session');
    assert.match(stashed.detail, /SM-A125F/, 'the stash must carry the whole buffer');

    // Next Action Room load, signed in again.
    api.answer(ANSWER_OK);
    api.liveReportFlush();
    await settle();

    const sent = api.posted()[api.posted().length - 1];
    assert.ok(sent.event.startsWith('late: '), 'a recovered report must not look like a fresh one');
    assert.match(sent.detail, /\(failed at \d{4}-\d\d-\d\d \d\d:\d\d\)/, 'stamped with when it actually happened');
    assert.equal(api.stash(), null, 'and cleared once it lands');
});

test('a report that lands leaves nothing behind', async () => {
    const api = buildReporter();
    api.answer(ANSWER_OK);
    api.liveReport('zero-frames-16s');
    await settle();
    assert.equal(api.posted().length, 1);
    assert.equal(api.stash(), null);
});
