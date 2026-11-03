// Run with: node --test tests/js/*.test.js
//
// The pure half of the «Πίνακας» look of the console (assets/js/war-room-console-overlay.js): which teams the
// bottom bar shows and how the previous / next buttons move through them, the mission clock, the GPS
// readout, what the selected-team card says, which live stream the floating video tile shows and the pointer's
// label (the position as degrees, minutes and seconds, the height cell, the allowance and where the label goes)
// the heat layer's colour scale and cell size on the screen, and what the search finds (a name as it is typed,
// the groups, the order). The part that needs a page (moving the controls into the
// rails, the clicks) is exercised in the browser.

const test = require('node:test');
const assert = require('node:assert/strict');

const {wrcOvWindow, wrcOvStep, wrcOvElapsed, wrcOvLowestBattery, wrcOvGpsReadout, wrcOvTeamRows, wrcOvLiveStreams, wrcOvTilePick, wrcOvLeft, wrcOvDms, wrcOvCell, wrcOvBudget, wrcOvTipAt, wrcOvHeatT, wrcOvHeatColor, wrcOvHeatCellPx, wrcOvHeatMinutes, wrcOvNorm, wrcOvFind, wrcOvDrawFace} = require('../../assets/js/war-room-console-overlay.js');

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

test('a position is degrees, minutes and seconds with the compass letters', () => {
    assert.equal(wrcOvDms(35.2142, 24.7519, 'ΒΝΑΔ'), `35°12'51" Β, 24°45'07" Α`);
    assert.equal(wrcOvDms(35.2142, 24.7519, 'NSEW'), `35°12'51" N, 24°45'07" E`);
    assert.equal(wrcOvDms(-33.8688, -70.6693, 'NSEW'), `33°52'08" S, 70°40'09" W`, 'south and west: the letter, not a minus sign');
    assert.equal(wrcOvDms(-33.8688, 151.2093, 'ΒΝΑΔ'), `33°52'08" Ν, 151°12'33" Α`);
    assert.equal(wrcOvDms(0, 0), `0°00'00" N, 0°00'00" E`, 'no letters given: north and east');
});

test('seconds that round up to sixty carry into the minutes and the degrees', () => {
    assert.equal(wrcOvDms(35.99999, 24.29999, 'NSEW'), `36°00'00" N, 24°18'00" E`);
    assert.equal(wrcOvDms(35.19999, 25.0, 'NSEW'), `35°12'00" N, 25°00'00" E`);
});

test('a height is asked for per cell of about a hundred metres', () => {
    assert.equal(wrcOvCell(35.21424, 24.75191), '35.214,24.752');
    assert.equal(wrcOvCell(35.21449, 24.75151), '35.214,24.752', 'a few metres away is the same cell');
    assert.notEqual(wrcOvCell(35.2142, 24.7519), wrcOvCell(35.2152, 24.7519), 'a hundred metres north is another');
});

test('the pointer may ask for heights only a few times in ten minutes', () => {
    const win = 600000;
    let stamps = [];
    for (let i = 0; i < 3; i++) {
        const g = wrcOvBudget(stamps, 1000 + i, 3, win);
        assert.equal(g.ok, true);
        stamps = g.stamps;
    }
    const over = wrcOvBudget(stamps, 2000, 3, win);
    assert.equal(over.ok, false, 'the fourth ask is refused');
    assert.equal(over.stamps.length, 3, 'and a refused ask is not counted');
    const later = wrcOvBudget(stamps, 1000 + win, 3, win);
    assert.equal(later.ok, true, 'ten minutes after the first ask, that one has left the window');
    assert.equal(later.stamps.length, 3, 'the two that are left and the new one');
    assert.deepEqual(wrcOvBudget(undefined, 5, 1, win), {ok: true, stamps: [5]});
});

test('the label goes to the lower right of the pointer, and to the other side where that has no room', () => {
    assert.deepEqual(wrcOvTipAt(100, 100, 200, 30, 1440, 900), {x: 120, y: 120}, 'room on both sides');
    assert.deepEqual(wrcOvTipAt(1300, 100, 200, 30, 1440, 900), {x: 1080, y: 120}, 'no room to the right: to the left of the pointer');
    assert.deepEqual(wrcOvTipAt(100, 880, 200, 30, 1440, 900), {x: 120, y: 830}, 'no room below: above the pointer');
    assert.deepEqual(wrcOvTipAt(1300, 880, 200, 30, 1440, 900), {x: 1080, y: 830}, 'the corner: both');
    assert.deepEqual(wrcOvTipAt(10, 10, 2000, 30, 1440, 900), {x: 4, y: 30}, 'a label wider than the window is kept on screen at its left edge');
});

test('the heat scale runs from nothing to the 95th percentile and stays there', () => {
    assert.equal(wrcOvHeatT(0, 600), 0);
    assert.equal(wrcOvHeatT(600, 600), 1);
    assert.equal(wrcOvHeatT(5000, 600), 1, 'a cell above the scale is simply the reddest');
    const low = wrcOvHeatT(10, 600);
    const mid = wrcOvHeatT(60, 600);
    assert.ok(low > 0 && low < mid && mid < 1, 'more time is hotter');
    assert.ok(wrcOvHeatT(60, 600) - wrcOvHeatT(10, 600) > 0.1, 'ten seconds against a minute can be told apart (a logarithmic scale)');
    assert.equal(wrcOvHeatT(30, 0), 1, 'no scale given: anything is the top of it');
    assert.equal(wrcOvHeatT(-5, 600), 0);
});

test('the heat colours go blue, cyan, green, yellow, red, and the faint end is more transparent', () => {
    assert.deepEqual(wrcOvHeatColor(0), {r: 44, g: 70, b: 200, a: 0.38});
    assert.deepEqual(wrcOvHeatColor(0.25), {r: 44, g: 195, b: 214, a: 0.47});
    assert.deepEqual(wrcOvHeatColor(0.5), {r: 63, g: 208, b: 90, a: 0.55});
    assert.deepEqual(wrcOvHeatColor(0.75), {r: 242, g: 211, b: 58, a: 0.64});
    assert.deepEqual(wrcOvHeatColor(1), {r: 229, g: 50, b: 45, a: 0.72});
    const between = wrcOvHeatColor(0.375);
    assert.ok(between.r > 44 && between.r < 63 && between.g > 195 && between.g < 208, 'between cyan and green it is a mix of the two');
    assert.deepEqual(wrcOvHeatColor(-1), wrcOvHeatColor(0), 'out of range is clamped');
    assert.deepEqual(wrcOvHeatColor(7), wrcOvHeatColor(1));
});

test('a cell is as many pixels as its metres take at that zoom and latitude', () => {
    // At zoom 17 on the equator a pixel is 1.194 m; at 35 N it is 0.978 m.
    assert.ok(Math.abs(wrcOvHeatCellPx(20, 0, 17) - 16.75) < 0.1);
    assert.ok(Math.abs(wrcOvHeatCellPx(20, 35.2, 17) - 20.45) < 0.1);
    assert.ok(Math.abs(wrcOvHeatCellPx(20, 35.2, 18) - 2 * wrcOvHeatCellPx(20, 35.2, 17)) < 1e-9, 'one zoom level more doubles it');
    assert.ok(wrcOvHeatCellPx(160, 35.2, 12) < wrcOvHeatCellPx(160, 35.2, 14), 'zoomed out, the cell is small');
});

test('the busiest cell is stated in whole minutes, at least one', () => {
    assert.equal(wrcOvHeatMinutes(600), 10);
    assert.equal(wrcOvHeatMinutes(601), 11);
    assert.equal(wrcOvHeatMinutes(5), 1);
    assert.equal(wrcOvHeatMinutes(0), 1);
    assert.equal(wrcOvHeatMinutes(undefined), 1);
});

// ── the search ───────────────────────────────────────────────────────────────

const SEARCH = [
    {group: 'teams', kind: 'team', id: 2, title: 'Bravo 2', sub: '8 άτομα'},
    {group: 'teams', kind: 'team', id: 10, title: 'Ομάδα 10', sub: '4 άτομα'},
    {group: 'teams', kind: 'team', id: 9, title: 'Ομάδα 2', sub: '5 άτομα'},
    {group: 'people', kind: 'person', id: 7, title: 'Αναστασία Πετράκη', sub: 'Alpha 1'},
    {group: 'people', kind: 'person', id: 8, title: 'Μαρία Πετράκη', sub: 'Bravo 2'},
    {group: 'people', kind: 'person', id: 9, title: 'Πέτρος Αναστασίου', sub: 'Alpha 1'},
    {group: 'places', kind: 'area', id: 1, title: 'Περιοχή αναζήτησης — Γιούχτας', sub: 'Περιοχή έρευνας'},
    {group: 'places', kind: 'sector', id: 4, title: 'Τομέας Α', sub: 'Τομέας · Περιοχή αναζήτησης — Γιούχτας'},
    {group: 'places', kind: 'point', id: 3, title: 'Πηγή Αγίου Νικολάου', sub: 'Σημείο χάρτη'},
];

test('a name is typed without accents or capitals and still finds itself', () => {
    assert.equal(wrcOvNorm('Αναστασία'), 'αναστασια');
    assert.equal(wrcOvNorm('ΑΝΑΣΤΑΣΙΑ'), 'αναστασια');
    assert.equal(wrcOvNorm('ΐ ΰ ή ώ'), 'ι υ η ω', 'the dialytika and the tonos both go');
    assert.equal(wrcOvNorm('ΟΔΟΣ'), wrcOvNorm('οδος'), 'the final sigma is a sigma');
    assert.equal(wrcOvNorm('οδός'), wrcOvNorm('ΟΔΟΣ'));
    assert.equal(wrcOvNorm('  Γιώργος   Κωνσταντίνου '), 'γιωργοσ κωνσταντινου', 'one space between words, none at the ends (and every final sigma is σ)');
    assert.equal(wrcOvNorm(null), '');
    assert.equal(wrcOvNorm(undefined), '');
    assert.equal(wrcOvNorm(12), '12');
});

test('nothing typed finds nothing, and a miss is an empty list', () => {
    assert.deepEqual(wrcOvFind(SEARCH, '', 8), []);
    assert.deepEqual(wrcOvFind(SEARCH, '   ', 8), []);
    assert.deepEqual(wrcOvFind(SEARCH, 'ζζζζ', 8), []);
    assert.deepEqual(wrcOvFind([], 'alpha', 8), []);
    assert.deepEqual(wrcOvFind(null, 'alpha', 8), []);
});

test('the result is grouped teams, people, places - and only the groups that have a match', () => {
    const all = wrcOvFind(SEARCH, 'α', 8);
    assert.deepEqual(all.map(g => g.key), ['teams', 'people', 'places']);
    const placesOnly = wrcOvFind(SEARCH, 'γιουχτ', 8);
    assert.deepEqual(placesOnly.map(g => g.key), ['places']);
    assert.deepEqual(wrcOvFind(SEARCH, 'bravo', 8).map(g => g.key), ['teams', 'people'], 'the team, and the person whose line under the name says Bravo 2');
});

test('every word typed has to be there, in any order, in the title or the line under it', () => {
    assert.deepEqual(wrcOvFind(SEARCH, 'πετρακη αναστασια', 8)[0].items.map(e => e.id), [7]);
    assert.deepEqual(wrcOvFind(SEARCH, 'αναστασια πετρακη', 8)[0].items.map(e => e.id), [7]);
    assert.deepEqual(wrcOvFind(SEARCH, 'αναστασια bravo', 8), [], 'one word that is nowhere is no match');
    assert.deepEqual(wrcOvFind(SEARCH, 'ΑΛΦΑ', 8), [], 'Latin and Greek are not the same letters here: no transliteration');
    // The sector is found through the line under its name, the area it lies in.
    assert.deepEqual(wrcOvFind(SEARCH, 'τομεας γιουχτας', 8)[0].items.map(e => e.id), [4]);
});

test('a title that starts with what was typed comes first, a match only in the line under the title last', () => {
    const rows = wrcOvFind(SEARCH, 'αναστασ', 8).find(g => g.key === 'people').items.map(e => e.id);
    assert.deepEqual(rows, [7, 9], 'Αναστασία… starts with it, Πέτρος Αναστασίου only has it in a later word');
    const places = wrcOvFind(SEARCH, 'γιουχτας', 8)[0].items.map(e => e.id);
    assert.deepEqual(places, [1, 4], 'the area has it in its name; the sector only in the line under its name');
    const word = wrcOvFind(SEARCH, 'νικολαου', 8)[0].items.map(e => e.id);
    assert.deepEqual(word, [3], 'a word in the middle of a title is a match');
});

test('equal matches come in name order with numbers counted as numbers', () => {
    const teams = wrcOvFind(SEARCH, 'ομαδα', 8)[0].items.map(e => e.title);
    assert.deepEqual(teams, ['Ομάδα 2', 'Ομάδα 10'], '2 before 10, not after it');
});

test('only as many of a group as asked for are shown, and how many were left out is said', () => {
    const many = [];
    for (let i = 1; i <= 12; i++) many.push({group: 'people', kind: 'person', id: i, title: 'Εθελοντής ' + i, sub: ''});
    const [group] = wrcOvFind(many, 'εθελοντης', 8);
    assert.equal(group.items.length, 8);
    assert.equal(group.more, 4);
    assert.deepEqual(group.items.map(e => e.id), [1, 2, 3, 4, 5, 6, 7, 8], 'the first eight by name, numbers as numbers');
    assert.equal(wrcOvFind(many, 'εθελοντης', 20)[0].more, 0);
});

test('an entry of a group the search does not know is ignored, and the entries are not changed', () => {
    const before = JSON.stringify(SEARCH);
    const odd = SEARCH.concat([{group: 'elsewhere', kind: 'x', id: 99, title: 'Αναστασία', sub: ''}]);
    assert.equal(wrcOvFind(odd, 'αναστασια', 8).flatMap(g => g.items).some(e => e.id === 99), false);
    wrcOvFind(SEARCH, 'α', 8);
    assert.equal(JSON.stringify(SEARCH), before);
});

test('an entry can keep its line under the name out of the search, so a count is not matched by a letter', () => {
    const teams = [
        {group: 'teams', kind: 'team', id: 1, title: 'Alpha 1', sub: '8 άτομα', find: ''},
        {group: 'teams', kind: 'team', id: 2, title: 'Ομάδα Άλφα', sub: '5 άτομα', find: ''},
    ];
    assert.deepEqual(wrcOvFind(teams, 'α', 8)[0].items.map(e => e.id), [2], 'only the name that has the letter, not «άτομα»');
    assert.deepEqual(wrcOvFind(teams, 'ατομα', 8), [], 'the count itself is not searchable');
    const searchable = [{group: 'people', kind: 'person', id: 3, title: 'Μαρία', sub: 'Alpha 1'}];
    assert.equal(wrcOvFind(searchable, 'alpha', 8).length, 1, 'without it the line under the name is searched, as before');
});

// ── the drawing column's one button ──────────────────────────────────────────

test("the drawing column's button folds the column while it is open, whatever tool is armed", () => {
    assert.deepEqual(wrcOvDrawFace(true, ''), {icon: 'bi-chevron-up', on: false, label: 'fold'});
    assert.deepEqual(wrcOvDrawFace(true, 'bi-pencil'), {icon: 'bi-chevron-up', on: false, label: 'fold'}, 'the tools are in view, and the lit one is lit there');
});

test("a folded column with a tool on shows that tool's own icon, lit, so an armed pen is never out of sight", () => {
    assert.deepEqual(wrcOvDrawFace(false, 'bi-pencil'), {icon: 'bi-pencil', on: true, label: 'armed'});
    assert.deepEqual(wrcOvDrawFace(false, 'bi-arrow-up-right'), {icon: 'bi-arrow-up-right', on: true, label: 'armed'});
    assert.deepEqual(wrcOvDrawFace(false, 'bi-eraser'), {icon: 'bi-eraser', on: true, label: 'armed'});
});

test('a folded column with no tool on is the pen and square, not lit', () => {
    assert.deepEqual(wrcOvDrawFace(false, ''), {icon: 'bi-pencil-square', on: false, label: 'open'});
    assert.deepEqual(wrcOvDrawFace(false, undefined), {icon: 'bi-pencil-square', on: false, label: 'open'});
    assert.deepEqual(wrcOvDrawFace(false, null), {icon: 'bi-pencil-square', on: false, label: 'open'});
});
