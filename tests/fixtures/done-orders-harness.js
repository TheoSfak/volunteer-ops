// Runs the finished-orders rules of the live map (assets/js/war-room-done-orders.js) that v3.443.0 added to: the
// field's quiet default and the note a team leaves on finishing. Prints what it works out as JSON, for
// tests/FinishedOrdersToolsTest.php. No browser, no map.
//
//   node tests/fixtures/done-orders-harness.js <repo root>
const fs = require('fs');
const vm = require('vm');

const root = process.argv[2];
const ctx = vm.createContext({
    t: (key) => key,
    escapeHtml: (s) => String(s),
    document: {getElementById: () => null, querySelectorAll: () => []},
    console,
});
for (const f of ['war-room-time-machine.js', 'war-room-done-orders.js']) {
    vm.runInContext(fs.readFileSync(root + '/assets/js/' + f, 'utf8'), ctx, {filename: f});
}
vm.runInContext('var dispatches = [], sectors = [], routes = [];', ctx);

const run = (code) => JSON.parse(vm.runInContext('JSON.stringify(' + code + ')', ctx));
const set = (code) => vm.runInContext(code, ctx);

// The clock is the time machine's: "now" is 1,000,000 s, so an order finished at 995,000 is old and one at 999,900 is fresh.
set('wrTimeSet(1000000)');
const OLD = 995000, FRESH = 999900;

const phases = () => ({
    dispatchOld: run('wrDonePhase(' + OLD + ', 40, "dispatch")'),
    dispatchFresh: run('wrDonePhase(' + FRESH + ', 40, "dispatch")'),
    dispatchLive: run('wrDonePhase(null, 40, "dispatch")'),
    sectorOld: run('wrDonePhase(' + OLD + ', 40, "sector")'),
    routeOld: run('wrDoneRoutePhase({status: "completed", completed_ts: ' + OLD + ', team_id: 40})'),
    routeFresh: run('wrDoneRoutePhase({status: "completed", completed_ts: ' + FRESH + ', team_id: 40})'),
    routeActive: run('wrDoneRoutePhase({status: "active", team_id: 40})'),
    sigDispatch: run('wrDonePhaseSig([{completed_ts: ' + OLD + ', team_id: 40}], "dispatch")'),
    sigSector: run('wrDonePhaseSig([{completed_ts: ' + OLD + ', team_id: 40}], "sector")'),
    sigRoutes: run('wrDoneRoutesSig([{status: "completed", completed_ts: ' + OLD + ', team_id: 40}])'),
});

const out = {};
out.command = phases();                                   // the default: wrDoneFieldQuiet = false

set('wrDoneFieldQuiet = true');
out.field = phases();                                     // the people in the field

set('wrDoneFieldShowAll = true');
out.fieldShowAll = phases();                              // ... who ticked «Εμφάνιση των ολοκληρωμένων»
set('wrDoneFieldShowAll = false');

set('wrDoneMode = "hidden"');
out.fieldModeHidden = {dispatchOld: run('wrDonePhase(' + OLD + ', 40, "dispatch")')};
set('wrDoneMode = "normal"');
out.fieldModeNormal = {dispatchOld: run('wrDonePhase(' + OLD + ', 40, "dispatch")')};
set('wrDoneMode = "faded"; wrDoneFieldQuiet = false');

// What the team wrote when it finished (only what the server let this viewer see is in the payload).
const note = (item) => run('wrDoneNote(' + JSON.stringify(item) + ')');
out.notes = {
    own: note({team_id: 40, progress: [{scope: 't40', completed: '09:11', note: 'Αποχώρησε περιπατητικός'}]}),
    otherTeamsNote: note({team_id: 40, progress: [{scope: 't39', completed: '09:11', note: 'not mine'}, {scope: 't40', completed: '09:12', note: null}]}),
    notFinishedYet: note({team_id: 40, progress: [{scope: 't40', completed: null, note: 'draft'}]}),
    broadcastLast: note({team_id: null, progress: [{scope: 't39', completed: '09:01', note: 'first'}, {scope: 't40', completed: '09:11', note: 'last'}]}),
    none: note({team_id: 40, progress: []}),
    noProgress: note({team_id: 40}),
};

// The list behind the counter carries the flag (the present: the time machine off).
set('wrTimeSet(null)');
set('dispatches = ' + JSON.stringify([
    {id: 1, team_id: 40, team_label: 'Bravo', label: 'a', completed_ts: OLD, completed_display: '09:11', progress: [{scope: 't40', completed: '09:11', note: 'x'}]},
    {id: 2, team_id: 40, team_label: 'Bravo', label: 'b', completed_ts: OLD, completed_display: '09:06', progress: [{scope: 't40', completed: '09:06', note: null}]},
]));
out.entries = run('wrDoneEntries().map(e => [e.id, e.note])');

console.log(JSON.stringify(out));
