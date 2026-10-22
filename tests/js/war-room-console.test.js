// Run with: node --test tests/js
//
// The pure half of the admin console (assets/js/war-room-console.js): how the
// queue is ordered, how a selection of people becomes a recipient summary, and
// what a point or a route can be sent to. The part that needs a page (the
// regions, the poll, the buttons) is exercised in the browser.

const test = require('node:test');
const assert = require('node:assert/strict');

const {
    wrcStampKey, wrcSevRank, wrcOrderQueue, wrcSummarizeSelection,
    wrcDispatchPlan, wrcRouteSeedFor, wrcRecipientLabel,
    wrcOrderProgress, wrcContactTone, wrcTeamBadge, wrcDeclineLine, wrcWaitingReceipts, wrcNextSectorLabel, wrcPickArea,
    wrcUnreadKeys, wrcChatUnread,
    wrcNormalizePhase, wrcPhasePreset, wrcReadiness, wrcClosingItems,
} = require('../../assets/js/war-room-console.js');

const teams = [
    {id: 1, label: 'Alpha 1', color: '#e34948', members: [{user_id: 11}, {user_id: 12}, {user_id: 13}, {user_id: 14}]},
    {id: 2, label: 'Bravo 2', color: '#008300', members: [{user_id: 21}, {user_id: 22}]},
    {id: 3, label: 'Charlie 3', color: null, members: [{user_id: 31}]},
];

test('wrcStampKey sorts the poll\'s «dd/mm HH:MM» strings by when they happened', () => {
    assert.ok(wrcStampKey('19/08 09:39') > wrcStampKey('19/08 00:36'));
    assert.ok(wrcStampKey('19/08 00:01') > wrcStampKey('18/08 23:59'));
    // a year, when one is there, does not change the order within it
    assert.ok(wrcStampKey('02/03/2026 10:00') > wrcStampKey('01/03/2026 23:00'));
    assert.equal(wrcStampKey(''), 0);
    assert.equal(wrcStampKey(null), 0);
    assert.equal(wrcStampKey('εχθές'), 0);
});

test('wrcOrderQueue puts the worst first and, within a severity, the newest', () => {
    const ordered = wrcOrderQueue([
        {id: 'a', sev: 'warn', ts: 50},
        {id: 'b', sev: 'critical', ts: 1},
        {id: 'c', sev: 'high', ts: 10},
        {id: 'd', sev: 'high', ts: 20},
        {id: 'e', sev: 'info', ts: 99},
        {id: 'f', sev: 'something-new', ts: 5},
    ]);
    assert.deepEqual(ordered.map(i => i.id), ['b', 'd', 'c', 'a', 'e', 'f']);
});

test('wrcOrderQueue is stable for equals and does not touch its input', () => {
    const input = [{id: 1, sev: 'high', ts: 5}, {id: 2, sev: 'high', ts: 5}, {id: 3, sev: 'high', ts: 5}];
    const copy = input.slice();
    assert.deepEqual(wrcOrderQueue(input).map(i => i.id), [1, 2, 3]);
    assert.deepEqual(input, copy);
});

test('wrcSevRank knows the four tiers and ranks the rest last', () => {
    assert.deepEqual(['critical', 'high', 'warn', 'info', 'x'].map(wrcSevRank), [0, 1, 2, 3, 4]);
});

test('wrcSummarizeSelection tells a whole team from part of one', () => {
    const s = wrcSummarizeSelection(teams, new Set([11, 12, 21, 22, 31]));
    assert.equal(s.total, 5);
    assert.deepEqual(s.chips.map(c => [c.teamId, c.count, c.size, c.whole]), [[1, 2, 4, false], [2, 2, 2, true], [3, 1, 1, true]]);
    assert.deepEqual(s.chips[0].ids, [11, 12]);
});

test('wrcSummarizeSelection ignores people who are not on any team and an empty pick', () => {
    assert.deepEqual(wrcSummarizeSelection(teams, new Set()), {chips: [], total: 0});
    assert.equal(wrcSummarizeSelection(teams, new Set([999])).total, 0);
});

test('wrcDispatchPlan: a point goes to a team, to all of them, or - for people - as a route', () => {
    const plan = ids => wrcDispatchPlan(wrcSummarizeSelection(teams, new Set(ids)), teams.length);
    assert.deepEqual(plan([]), {mode: 'ask'});
    assert.deepEqual(plan([21, 22]), {mode: 'team', teamId: 2});
    assert.deepEqual(plan([11, 12, 13, 14, 21, 22, 31]), {mode: 'all'});
    assert.deepEqual(plan([11, 12, 13, 14, 21, 22]), {mode: 'many'});
    // two of Alpha's four: the dispatch window cannot do that
    assert.deepEqual(plan([11, 12]), {mode: 'people'});
    // one person across two teams
    assert.deepEqual(plan([11, 21]), {mode: 'people'});
});

test('wrcDispatchPlan never calls two teams «all» when there is only one team', () => {
    const one = [teams[0]];
    const s = wrcSummarizeSelection(one, new Set([11, 12, 13, 14]));
    assert.deepEqual(wrcDispatchPlan(s, 1), {mode: 'team', teamId: 1});
});

test('wrcRouteSeedFor: the tool-carrying case, two of four people of one team', () => {
    const seed = wrcRouteSeedFor(wrcSummarizeSelection(teams, new Set([11, 14])));
    assert.deepEqual(seed, {teamId: 1, memberIds: [11, 14]});
});

test('wrcRouteSeedFor: a whole team needs no member list; several teams are cross-team', () => {
    assert.deepEqual(wrcRouteSeedFor(wrcSummarizeSelection(teams, new Set([21, 22]))), {teamId: 2});
    assert.deepEqual(wrcRouteSeedFor(wrcSummarizeSelection(teams, new Set([12, 21]))), {crossTeam: true, memberIds: [12, 21]});
    assert.deepEqual(wrcRouteSeedFor(wrcSummarizeSelection(teams, new Set())), {});
});

test('wrcRecipientLabel names a whole team plainly and part of one with its size', () => {
    const [part, whole] = wrcSummarizeSelection(teams, new Set([11, 12, 21, 22])).chips;
    assert.equal(wrcRecipientLabel(part, 'από'), 'Alpha 1 · 2 από 4');
    assert.equal(wrcRecipientLabel(whole, 'από'), 'Bravo 2');
});

// ── the team avatars ─────────────────────────────────────────────────────────

test('wrcOrderProgress: a dispatch moves from sent, to under way, to arrived', () => {
    const p = o => wrcOrderProgress(Object.assign({type: 'dispatch'}, o));
    assert.ok(p({}) > 0, 'a sent order still shows a sliver of ring');
    assert.ok(p({departed: true}) > p({}));
    assert.ok(p({departed: true, arrived: true}) > p({departed: true}));
    // arriving without ever pressing «Ξεκινάω» still counts as arrived
    assert.equal(p({arrived: true}), p({departed: true, arrived: true}));
});

test('wrcOrderProgress: a sector goes sent, received, under way, working; a lost one shows nothing', () => {
    const s = (status, acked) => wrcOrderProgress({type: 'sector', status, acked});
    assert.ok(s('assigned', true) > s('assigned', false));
    assert.ok(s('en_route') > s('assigned', true));
    assert.ok(s('in_progress') > s('en_route'));
    assert.equal(s('needs_recheck'), s('in_progress'));
    assert.equal(s('not_started'), 0);
    assert.equal(s('something-new'), 0);
});

test('wrcOrderProgress: a route is the share of its points done, never an empty ring', () => {
    const r = (done, total) => wrcOrderProgress({type: 'route', done, total});
    assert.equal(r(2, 4), 0.5);
    assert.equal(r(0, 4), 0.1);
    assert.equal(r(4, 4), 1);
    assert.equal(r(0, 0), 0.1);
    assert.equal(r(9, 4), 1, 'more done than points never overshoots the ring');
});

test('wrcOrderProgress: nothing, or something it does not know, is an empty ring', () => {
    assert.equal(wrcOrderProgress(null), 0);
    assert.equal(wrcOrderProgress({type: 'mystery'}), 0);
});

test('wrcContactTone: nobody, everybody, some, none', () => {
    assert.equal(wrcContactTone(0, 0), 'none');
    assert.equal(wrcContactTone(4, 0), 'ok');
    assert.equal(wrcContactTone(4, 1), 'warn');
    assert.equal(wrcContactTone(4, 4), 'bad');
    assert.equal(wrcContactTone(1, 1), 'bad');
});

test('wrcTeamBadge shows the worst thing only, and nothing for a quiet team', () => {
    assert.equal(wrcTeamBadge({sos: 0, needsHelp: 0, blocked: 0, orders: 0}), null);
    assert.equal(wrcTeamBadge({sos: 0, needsHelp: 0, blocked: 0, orders: 1}), null);
    assert.deepEqual(wrcTeamBadge({sos: 0, needsHelp: 0, blocked: 0, orders: 3}), {kind: 'more', tone: 'info', text: '+2'});
    assert.deepEqual(wrcTeamBadge({sos: 0, needsHelp: 0, blocked: 1, orders: 3}), {kind: 'blocked', tone: 'warn', text: '!'});
    assert.equal(wrcTeamBadge({sos: 0, needsHelp: 2, blocked: 1, orders: 3}).kind, 'help');
    assert.equal(wrcTeamBadge({sos: 1, needsHelp: 2, blocked: 1, orders: 3}).kind, 'sos');
});

// ── the phases ───────────────────────────────────────────────────────────────

test('wrcNormalizePhase falls back to live for anything it does not know', () => {
    assert.deepEqual(['prep', 'live', 'close'].map(wrcNormalizePhase), ['prep', 'live', 'close']);
    assert.equal(wrcNormalizePhase('archive'), 'live');
    assert.equal(wrcNormalizePhase(null), 'live');
    assert.equal(wrcNormalizePhase(undefined), 'live');
});

test('wrcPhasePreset: preparation goes to the plan, live to the feed with the order form, closing to the feed', () => {
    assert.deepEqual(wrcPhasePreset('prep'), {tab: 'plan', sections: [], composer: false});
    assert.deepEqual(wrcPhasePreset('live'), {tab: 'feed', sections: [], composer: true});
    assert.deepEqual(wrcPhasePreset('close'), {tab: 'feed', sections: [], composer: false});
    assert.deepEqual(wrcPhasePreset('x'), wrcPhasePreset('live'));
});

test('wrcPhasePreset never opens a plan section by itself: Σχέδιο starts folded in every phase', () => {
    ['prep', 'live', 'close', 'anything'].forEach(phase => assert.deepEqual(wrcPhasePreset(phase).sections, []));
});

const readyFacts = {teams: 3, people: 12, noGps: 0, sectors: 4, sectorsFree: 0, stale: 0};

test('wrcReadiness: a mission with everything in place is ready', () => {
    const r = wrcReadiness(readyFacts);
    assert.equal(r.ready, true);
    assert.equal(r.open, 0);
    assert.deepEqual(r.rows.map(x => x.key), ['teams', 'gps', 'sectors', 'assigned', 'contact']);
    assert.ok(r.rows.every(x => x.tone === 'ok'));
});

test('wrcReadiness: no teams is bad, the rest are warnings, and each says what it is about', () => {
    const r = wrcReadiness({teams: 0, people: 0, noGps: 0, sectors: 0, sectorsFree: 0, stale: 0});
    assert.equal(r.ready, false);
    assert.equal(r.rows.find(x => x.key === 'teams').tone, 'bad');
    assert.equal(r.rows.find(x => x.key === 'sectors').tone, 'warn');
    // with no sectors there is nothing to assign: that row is left out, not failed
    assert.equal(r.rows.find(x => x.key === 'assigned'), undefined);
    assert.equal(r.open, 2);
});

test('wrcReadiness: unassigned sectors, silent people and people outside GPS each count', () => {
    const r = wrcReadiness({teams: 2, people: 10, noGps: 3, sectors: 5, sectorsFree: 2, stale: 4});
    assert.deepEqual(r.rows.map(x => [x.key, x.tone]), [['teams', 'ok'], ['gps', 'warn'], ['sectors', 'ok'], ['assigned', 'warn'], ['contact', 'warn']]);
    assert.equal(r.open, 3);
    const assigned = r.rows.find(x => x.key === 'assigned');
    assert.deepEqual([assigned.n, assigned.of], [3, 5], 'three of five sectors have a team');
    assert.deepEqual([r.rows.find(x => x.key === 'gps').n, r.rows.find(x => x.key === 'gps').of], [3, 10]);
});

test('wrcReadiness names the card each row leads to', () => {
    wrcReadiness(readyFacts).rows.forEach(x => assert.match(x.card, /Card$/));
});

test('wrcClosingItems lists only what is open, worst first', () => {
    assert.deepEqual(wrcClosingItems({sos: 0, incidents: 0, shortages: 0, orders: 0, sectorsOpen: 0, overdue: 0}), []);
    const items = wrcClosingItems({sos: 1, incidents: 2, shortages: 0, orders: 3, sectorsOpen: 4, overdue: 1});
    assert.deepEqual(items.map(x => [x.key, x.n, x.tone]), [
        ['sos', 1, 'bad'], ['orders', 3, 'warn'], ['sectors', 4, 'warn'], ['incidents', 2, 'warn'], ['overdue', 1, 'warn'],
    ]);
});

// ── «Δεν μπορώ» on a team's row ──────────────────────────────────────────────

test('wrcDeclineLine is the reason, and the volunteer\'s note after a dash', () => {
    assert.equal(wrcDeclineLine({reason: 'unsafe', note: ''}, 'Μη ασφαλές'), 'Μη ασφαλές');
    assert.equal(wrcDeclineLine({reason: 'other', note: 'Χρειαζόμαστε σχοινιά'}, 'Άλλο'), 'Άλλο — «Χρειαζόμαστε σχοινιά»');
    // a note of spaces is no note; a missing one neither
    assert.equal(wrcDeclineLine({reason: 'busy', note: '   '}, 'Απασχολημένοι'), 'Απασχολημένοι');
    assert.equal(wrcDeclineLine({reason: 'busy'}, 'Απασχολημένοι'), 'Απασχολημένοι');
});

test('wrcDeclineLine says nothing when nothing was declined', () => {
    assert.equal(wrcDeclineLine(null, 'Άλλο'), '');
    assert.equal(wrcDeclineLine(undefined, 'Άλλο'), '');
});

// ── orders waiting for their first «Ελήφθη» ──────────────────────────────────

const NOW = 1_800_000_000;
const person = (extra) => Object.assign({ack_ts: null, declined: false}, extra);
const card = (key, ageSec, people, extra) => Object.assign({key, ts: NOW - ageSec, finished: false, people}, extra);
const GRACE = 600;

test('wrcWaitingReceipts counts the orders nobody has answered yet, and the people on them', () => {
    const cards = [
        card('order:1', 120, Array.from({length: 8}, () => person())),
        card('order:2', 60, Array.from({length: 32}, () => person())),
        card('dispatch:3', 30, Array.from({length: 8}, () => person())),
    ];
    assert.deepEqual(wrcWaitingReceipts(cards, NOW, GRACE), {orders: 3, acked: 0, total: 48});
});

test('wrcWaitingReceipts: those who have answered count towards «από», not towards waiting', () => {
    const cards = [card('order:1', 100, [person({ack_ts: NOW - 20}), person(), person({ack_ts: NOW - 10}), person()])];
    assert.deepEqual(wrcWaitingReceipts(cards, NOW, GRACE), {orders: 1, acked: 2, total: 4});
});

test('wrcWaitingReceipts leaves out what has its own row: an order past the grace', () => {
    const cards = [card('old', GRACE, [person()]), card('older', GRACE + 500, [person()]), card('new', GRACE - 1, [person()])];
    assert.deepEqual(wrcWaitingReceipts(cards, NOW, GRACE), {orders: 1, acked: 0, total: 1});
});

test('wrcWaitingReceipts: an order everyone has answered is not waiting, and «Δεν μπορώ» is an answer', () => {
    const answered = card('a', 60, [person({ack_ts: NOW - 5}), person({ack_ts: NOW - 4})]);
    const declined = card('b', 60, [person({ack_ts: NOW - 5}), person({declined: true})]);
    assert.deepEqual(wrcWaitingReceipts([answered, declined], NOW, GRACE), {orders: 0, acked: 0, total: 0});
});

test('wrcWaitingReceipts skips finished and vanished cards, and the ones the coordinator closed', () => {
    const cards = [
        card('fin', 60, [person()], {finished: true}),
        card('gone', 60, [person()], {gone: {why: 'deleted'}}),
        card('closed', 60, [person()]),
        card('empty', 60, []),
        card('kept', 60, [person()]),
    ];
    assert.deepEqual(wrcWaitingReceipts(cards, NOW, GRACE, new Set(['closed'])), {orders: 1, acked: 0, total: 1});
});

test('wrcWaitingReceipts copes with no cards at all', () => {
    assert.deepEqual(wrcWaitingReceipts(null, NOW, GRACE), {orders: 0, acked: 0, total: 0});
    assert.deepEqual(wrcWaitingReceipts([], NOW, GRACE), {orders: 0, acked: 0, total: 0});
});

// ── what is new in the dock's tabs ───────────────────────────────────────────

test('wrcUnreadKeys: what has not been seen is new, and nothing is new on a first visit', () => {
    assert.deepEqual(wrcUnreadKeys(['a', 'b', 'c'], new Set(['a'])), ['b', 'c']);
    assert.deepEqual(wrcUnreadKeys(['a', 'b'], new Set(['a', 'b'])), []);
    assert.deepEqual(wrcUnreadKeys(['a', 'b'], null), []);
    assert.deepEqual(wrcUnreadKeys([], new Set(['a'])), []);
});

test('wrcChatUnread: a first visit takes the rooms as they are and counts nothing', () => {
    const r = wrcChatUnread({general: {n: 4}, 137: {n: 2}}, null, null);
    assert.equal(r.total, 0);
    assert.deepEqual(r.byRoom, {});
    assert.deepEqual(r.seen, {general: 4, 137: 2});
});

test('wrcChatUnread counts new messages per room and in all', () => {
    const r = wrcChatUnread({general: {n: 6}, 137: {n: 2}, 138: {n: 3}}, {general: 4, 137: 2, 138: 0}, null);
    assert.equal(r.total, 5);
    assert.deepEqual(r.byRoom, {general: 2, 138: 3});
});

test('wrcChatUnread: looking at one room clears that room and only that room', () => {
    const rooms = {general: {n: 6}, 137: {n: 4}};
    const seen = {general: 4, 137: 2};
    const r = wrcChatUnread(rooms, seen, '137');
    assert.deepEqual(r.byRoom, {general: 2});
    assert.equal(r.total, 2);
    assert.deepEqual(r.seen, {general: 4, 137: 4});
});

test('wrcChatUnread: a room that appears later is new in full, and a deleted message is not owed back', () => {
    // Bravo had no room when the sizes were last seen
    assert.deepEqual(wrcChatUnread({138: {n: 2}}, {}, null).byRoom, {138: 2});
    // general shrank from 5 to 3 (two deleted); one new message after that is one new message
    const shrunk = wrcChatUnread({general: {n: 3}}, {general: 5}, null);
    assert.equal(shrunk.total, 0);
    assert.deepEqual(shrunk.seen, {general: 3});
    assert.equal(wrcChatUnread({general: {n: 4}}, shrunk.seen, null).total, 1);
});

test('wrcChatUnread copes with no rooms at all', () => {
    assert.deepEqual(wrcChatUnread({}, {general: 2}, null), {seen: {}, byRoom: {}, total: 0});
    assert.deepEqual(wrcChatUnread(null, null, null), {seen: {}, byRoom: {}, total: 0});
});

// ── a sector drawn from the console ──────────────────────────────────────────

test('wrcNextSectorLabel starts at Α and takes the first letter nobody has', () => {
    assert.equal(wrcNextSectorLabel([], 'Τομέας'), 'Τομέας Α');
    assert.equal(wrcNextSectorLabel(['Τομέας Α'], 'Τομέας'), 'Τομέας Β');
    assert.equal(wrcNextSectorLabel(['Τομέας Α', 'Τομέας Γ'], 'Τομέας'), 'Τομέας Β', 'a gap is filled before the alphabet goes on');
});

test('wrcNextSectorLabel ignores other names, other cases and the automatic grid names Α1, Α2', () => {
    assert.equal(wrcNextSectorLabel(['Τομέας Α1', 'Τομέας Α2', 'Βάση', 'Περιοχή Α'], 'Τομέας'), 'Τομέας Α');
    assert.equal(wrcNextSectorLabel(['τομέας α'], 'Τομέας'), 'Τομέας Β');
    assert.equal(wrcNextSectorLabel([null, undefined, ''], 'Τομέας'), 'Τομέας Α');
});

test('wrcNextSectorLabel goes on to numbers when the alphabet is used up', () => {
    const letters = ['Α', 'Β', 'Γ', 'Δ', 'Ε', 'Ζ', 'Η', 'Θ', 'Ι', 'Κ', 'Λ', 'Μ', 'Ν', 'Ξ', 'Ο', 'Π', 'Ρ', 'Σ', 'Τ', 'Υ', 'Φ', 'Χ', 'Ψ', 'Ω'].map(l => 'Τομέας ' + l);
    assert.equal(wrcNextSectorLabel(letters, 'Τομέας'), 'Τομέας 1');
    assert.equal(wrcNextSectorLabel(letters.concat(['Τομέας 1', 'Τομέας 2']), 'Τομέας'), 'Τομέας 3');
});

test('wrcPickArea keeps the asked-for area while it exists and otherwise takes the newest', () => {
    const areas = [{id: 4, label: 'Α'}, {id: 9, label: 'Β'}, {id: 7, label: 'Γ'}];
    assert.equal(wrcPickArea(areas, '4').id, 4, 'the one asked for, whatever the id type');
    assert.equal(wrcPickArea(areas, 7).id, 7);
    assert.equal(wrcPickArea(areas, '').id, 9, 'nothing asked for: the newest');
    assert.equal(wrcPickArea(areas, '55').id, 9, 'the asked-for area was deleted: the newest');
    assert.equal(wrcPickArea([], '4'), null);
    assert.equal(wrcPickArea(undefined, '4'), null);
});
