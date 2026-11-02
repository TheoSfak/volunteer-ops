// Run with: node --test tests/js/*.test.js
//
// The pure half of the «Πίνακας» look of the console (assets/js/war-room-console-overlay.js): which teams the
// bottom bar shows and how the previous / next buttons move through them, the mission clock, the GPS
// readout, what the selected-team card says and which live stream the floating video tile shows. The part that needs a page (moving the controls into the
// rails, the clicks) is exercised in the browser.

const test = require('node:test');
const assert = require('node:assert/strict');

const {wrcOvWindow, wrcOvStep, wrcOvElapsed, wrcOvLowestBattery, wrcOvGpsReadout, wrcOvTeamRows, wrcOvLiveStreams, wrcOvTilePick, wrcOvLeft} = require('../../assets/js/war-room-console-overlay.js');

test('the team window shows all the teams when they fit', () => {
    assert.deepEqual(wrcOvWindow(4, -1, 0, 6), {start: 0, size: 4, more: false});
    assert.deepEqual(wrcOvWindow(0, -1, 0, 6), {start: 0, size: 0, more: false});
});

test('the team window keeps the focused team in view and never runs past the list', () => {
    assert.deepEqual(wrcOvWindow(9, -1, 0, 4), {start: 0, size: 4, more: true});
    assert.deepEqual(wrcOvWindow(9, 6, 0, 4), {start: 3, size: 4, more: true}, 'the focus is after the window: it slides just far enough');
    assert.deepEqual(wrcOvWindow(9, 1, 5, 4), {start: 1, size: 4, more: true}, 'the focus is before the window');
    assert.deepEqual(wrcOvWindow(9, -1, 40, 4), {start: 5, size: 4, more: true}, 'a stale start is pulled back inside the list');
    assert.deepEqual(wrcOvWindow(9, 8, 5, 4), {start: 5, size: 4, more: true}, 'the last team is the last slot');
});

test('previous and next go round the list, and start from an end when nothing is focused', () => {
    assert.equal(wrcOvStep(4, 1, 1), 2);
    assert.equal(wrcOvStep(4, 3, 1), 0, 'after the last comes the first');
    assert.equal(wrcOvStep(4, 0, -1), 3, 'before the first comes the last');
    assert.equal(wrcOvStep(4, -1, 1), 0);
    assert.equal(wrcOvStep(4, -1, -1), 3);
    assert.equal(wrcOvStep(0, -1, 1), -1, 'no teams, no step');
});

test('the mission clock counts hours and minutes since the start, and before it with a minus sign', () => {
    const start = 1_700_000_000;
    assert.deepEqual(wrcOvElapsed(start, start + 2 * 3600 + 55 * 60 + 40), {text: '02:55', before: false});
    assert.deepEqual(wrcOvElapsed(start, start), {text: '00:00', before: false});
    assert.deepEqual(wrcOvElapsed(start, start - 70 * 60), {text: '−01:10', before: true});
    assert.deepEqual(wrcOvElapsed(start, start + 271 * 3600), {text: '271:00', before: false}, 'a long mission is not wrapped at a day');
    assert.equal(wrcOvElapsed(0, start), null, 'a mission with no start has no clock');
});

test('the lowest battery is read from the last positions of the team only', () => {
    const pins = [
        {user_id: 1, battery_level: 80}, {user_id: 2, battery_level: 34}, {user_id: 3, battery_level: 5},
        {user_id: 4, battery_level: null}, {user_id: 5},
    ];
    assert.equal(wrcOvLowestBattery([1, 2, 4], pins), 34, 'person 3 is on another team');
    assert.equal(wrcOvLowestBattery(['1', '2', '3'], pins), 5, 'ids may come as text');
    assert.equal(wrcOvLowestBattery([4, 5], pins), null, 'nobody reported a level');
    assert.equal(wrcOvLowestBattery([], pins), null);
    assert.equal(wrcOvLowestBattery([1], undefined), null);
});

test('the GPS readout counts only the people whose GPS is on, and says how the room is doing', () => {
    assert.deepEqual(wrcOvGpsReadout({total: 31, silent: 3}), {text: '28/31', tone: 'warn'});
    assert.deepEqual(wrcOvGpsReadout({total: 8, silent: 0}), {text: '8/8', tone: 'ok'});
    assert.deepEqual(wrcOvGpsReadout({total: 8, silent: 8}), {text: '0/8', tone: 'bad'});
    assert.equal(wrcOvGpsReadout({total: 0, silent: 0}), null, 'nobody on GPS: nothing to say');
    assert.equal(wrcOvGpsReadout(null), null);
});

test('the selected-team card: first order, progress, contact, battery and the colour of the stripe', () => {
    const team = {
        members: [1, 2],
        orders: [{kind: 'route', detail: 'Κορυφή 2', state: 'en route', tone: 'go'}, {kind: 'dispatch', detail: '', state: 'sent', tone: 'new'}],
        ring: {f: 0.623, tone: 'go'}, contact: {tone: 'ok', stale: 0, total: 2}, badge: null,
    };
    const rows = wrcOvTeamRows(team, [{user_id: 1, battery_level: 72}, {user_id: 2, battery_level: 90}]);
    assert.deepEqual(rows.order, {kind: 'route', detail: 'Κορυφή 2', state: 'en route', tone: 'go', more: 1});
    assert.equal(rows.progress, 62);
    assert.deepEqual(rows.contact, {text: '2/2', tone: 'ok'});
    assert.deepEqual(rows.battery, {text: '72%', tone: 'ok'});
    assert.equal(rows.tone, 'ok');
});

test('a team that cannot continue, is out of contact or is nearly flat colours the stripe, worst first', () => {
    const base = {members: [1], orders: [], ring: null, contact: {tone: 'none', stale: 0, total: 0}};
    assert.equal(wrcOvTeamRows(base, []).order, null, 'no order: the card says it waits');
    assert.equal(wrcOvTeamRows(base, []).progress, null);
    assert.equal(wrcOvTeamRows(base, []).contact, null);
    assert.equal(wrcOvTeamRows(base, []).tone, 'ok');

    const blocked = Object.assign({}, base, {orders: [{kind: 'sector', detail: '', state: 'declined', tone: 'bad'}]});
    assert.equal(wrcOvTeamRows(blocked, []).tone, 'bad');
    const quiet = Object.assign({}, base, {contact: {tone: 'warn', stale: 1, total: 4}});
    assert.deepEqual(wrcOvTeamRows(quiet, []).contact, {text: '3/4', tone: 'warn'});
    assert.equal(wrcOvTeamRows(quiet, []).tone, 'warn');
    assert.equal(wrcOvTeamRows(base, [{user_id: 1, battery_level: 12}]).battery.tone, 'bad');
    assert.equal(wrcOvTeamRows(base, [{user_id: 1, battery_level: 12}]).tone, 'bad');
    assert.equal(wrcOvTeamRows(base, [{user_id: 1, battery_level: 25}]).tone, 'warn');
});

test('the battery warning follows the thresholds the page is given', () => {
    const base = {members: [1], orders: [], ring: null, contact: {tone: 'none', stale: 0, total: 0}};
    const pins = [{user_id: 1, battery_level: 55}];
    assert.equal(wrcOvTeamRows(base, pins).battery.tone, 'ok', 'the default is 30');
    assert.equal(wrcOvTeamRows(base, pins, 60, 20).battery.tone, 'warn', 'Settings say a warning below 60');
    assert.equal(wrcOvTeamRows(base, [{user_id: 1, battery_level: 20}], 60, 20).battery.tone, 'bad');
});

test('only streams that are on air count, newest first', () => {
    const rows = [
        {id: 4, uid: '7', status: 'live'}, {id: 9, uid: '8', status: 'requested'}, {id: 6, uid: '9', status: 'live'}, {id: 2, uid: '3', status: 'ended'},
    ];
    assert.deepEqual(wrcOvLiveStreams(rows).map(r => r.id), [6, 4], 'a request nobody accepted yet and an ended stream are not shown');
    assert.deepEqual(wrcOvLiveStreams([]), []);
    assert.deepEqual(wrcOvLiveStreams(undefined), []);
});

test('the tile follows the newest stream and keeps the one command picked', () => {
    const live = [{id: 6}, {id: 4}];
    assert.deepEqual(wrcOvTilePick(live, null, new Set()), {id: 6, show: true}, 'nothing picked yet: the newest');
    assert.deepEqual(wrcOvTilePick(live, 4, new Set()), {id: 4, show: true}, 'the pick stays while that stream is on air');
    assert.deepEqual(wrcOvTilePick([{id: 6}], 4, new Set()), {id: 6, show: true}, 'the picked stream ended: the one that is left');
    assert.deepEqual(wrcOvTilePick([], 4, new Set()), {id: null, show: false}, 'nothing on air: no tile');
});

test('a tile put away stays away until a new stream comes', () => {
    const away = new Set([6, 4]);
    assert.deepEqual(wrcOvTilePick([{id: 6}, {id: 4}], 6, away), {id: 6, show: false}, 'both are put away');
    assert.deepEqual(wrcOvTilePick([{id: 11}, {id: 6}, {id: 4}], 6, away), {id: 11, show: true}, 'a stream that was not there when it was put away brings it back, and is the one shown');
    assert.deepEqual(wrcOvTilePick([{id: 6}], null, new Set([6])), {id: 6, show: false});
});

test('the time left is minutes and seconds, and nothing while it is not known', () => {
    assert.equal(wrcOvLeft(552), '09:12');
    assert.equal(wrcOvLeft(600), '10:00');
    assert.equal(wrcOvLeft(5), '00:05');
    assert.equal(wrcOvLeft(0), '00:00');
    assert.equal(wrcOvLeft(-3), '00:00', 'a clock that ran past the end does not show a minus');
    assert.equal(wrcOvLeft(NaN), '');
    assert.equal(wrcOvLeft(undefined), '');
});
