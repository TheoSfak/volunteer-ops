/**
 * The rhythm of the Action Room's polls (v3.450.0).
 *
 * The page asks the server for the mission every 5 seconds (and the open chat room every 5, the activity feed every
 * 15), whether or not anything is happening. On a quiet mission every one of those answers is "nothing changed" - and
 * still costs the phone 2-4 KB on the air and the server a full set of queries. With a cap set in Settings > Action
 * Room the page slows down while nothing changes, and goes back to 5 seconds the moment anything does:
 *
 *   nothing has changed for   30 s   -> every 10 s
 *                             90 s   -> every 15 s
 *                            180 s   -> every 30 s        (never slower than the cap)
 *
 * "Changed" is what the page already knows: a poll whose payload differs from the last one, a failed poll, a new chat
 * message, a touch or key press, the screen coming back. This file only decides how often; the polls themselves
 * (pollWarRoomData, pollRoom, loadActivity) are untouched and still run from their own 5 s / 15 s timers, which now
 * ask wrCreatePollRhythm().due() first. With the cap off (0) due() is always true and staleMs() is the 20 s the page
 * always had: nothing changes at all.
 *
 * The "lost contact" banner waits for 4 missed polls; that count scales with the rhythm (staleMs), and a failed poll
 * puts the rhythm back to fast, so a real outage still shows within the old 20 s of the first miss.
 */

const WR_POLL_BASE_MS = 5000;
// [nothing has changed for at least (ms), then ask every (ms)], slowest first
const WR_POLL_LADDER = [[180000, 30000], [90000, 15000], [30000, 10000]];

/** How often to ask after quietMs of nothing changing, under a cap of capMs (0 = off = always the base). */
function wrPollIntervalMs(quietMs, capMs) {
    if (!(capMs > WR_POLL_BASE_MS)) return WR_POLL_BASE_MS;
    let every = WR_POLL_BASE_MS;
    for (const [quiet, interval] of WR_POLL_LADDER) {
        if (quietMs >= quiet) { every = interval; break; }
    }
    return Math.min(every, capMs);
}

/** How old the last good poll may be before the page says it has lost contact: 4 misses at the current rhythm, never under 20 s. */
function wrPollStaleMs(intervalMs) {
    return Math.max(20000, 3 * intervalMs + 5000);
}

/**
 * The state of the rhythm for one page. capMs 0 (or not above the base) = off. now is a clock (tests pass their own).
 *   bump()            something happened: back to the base rhythm
 *   intervalMs()      how often to ask right now
 *   staleMs()         the staleness limit that goes with it
 *   due(stream, base) whether the named stream ('main', 'chat', 'activity') should run on this tick of its own timer
 *                     (baseMs is that timer's period); true when it has waited out the current interval, less a
 *                     little slack for timer jitter - or when the clock went backwards, so a changed device clock
 *                     can never stall a poll.
 */
function wrCreatePollRhythm(capMs, now) {
    const clock = typeof now === 'function' ? now : Date.now;
    const cap = capMs > WR_POLL_BASE_MS ? capMs : 0;
    let lastActivityAt = clock();
    const lastRun = {};
    function intervalMs() {
        return cap === 0 ? WR_POLL_BASE_MS : wrPollIntervalMs(clock() - lastActivityAt, cap);
    }
    return {
        cap: cap,
        bump: function () { lastActivityAt = clock(); },
        intervalMs: intervalMs,
        staleMs: function () { return wrPollStaleMs(intervalMs()); },
        due: function (stream, baseMs) {
            if (cap === 0) return true;
            const at = clock();
            const every = Math.max(baseMs, intervalMs());
            const before = lastRun[stream];
            if (before === undefined || at < before || at - before >= every - 600) {
                lastRun[stream] = at;
                return true;
            }
            return false;
        },
    };
}

/** A touch, a click or a key press is somebody using the page: the rhythm goes back to the base. */
function wrWatchPollActivity(rhythm, doc) {
    ['pointerdown', 'keydown', 'touchstart'].forEach(function (name) {
        doc.addEventListener(name, function () { rhythm.bump(); }, {capture: true, passive: true});
    });
}

if (typeof module !== 'undefined' && module.exports) {
    module.exports = {WR_POLL_BASE_MS, WR_POLL_LADDER, wrPollIntervalMs, wrPollStaleMs, wrCreatePollRhythm, wrWatchPollActivity};
}
