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
