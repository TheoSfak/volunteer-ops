// Runs the time machine of «Πορεία Ομάδων» (assets/js/war-room-time-machine.js, v3.441.0) against a small
// invented mission and prints what it works out, as JSON. Driven by tests/TimeMachineTest.php; no browser, no map.
//
//   node tests/fixtures/time-machine-harness.js <repo root>
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

// Times are small invented unix seconds, so a case reads at a glance.
vm.runInContext(`
var dispatches = [
  // A point sent to team 40 at 100: left 110, arrived 120, finished 130.
  {id: 1, type: 'point', team_id: 40, sent_ts: 100, completed_ts: 130, completed_display: '00:02', map_point_id: null,
   eta: {minutes: 5}, acks: [{user_name: 'x'}], my_receipt: '00:01', can_delete: true, can_complete: true, my_completed: '00:02',
   declines: [{ts: 112, by: 'a'}, {ts: 140, by: 'b'}],
   progress: [{scope: 't40', label: 'Bravo', departed: '00:00', arrived: '00:01', completed: '00:02', departed_ts: 110, arrived_ts: 120, completed_ts: 130, note: 'done here'}]},
  // The same kind of order sent to EVERY team: one team finishing it must not finish it.
  {id: 2, type: 'point', team_id: null, sent_ts: 100, completed_ts: null, completed_display: null, map_point_id: null,
   progress: [{scope: 't40', label: 'Bravo', departed: null, arrived: null, completed: '00:02', departed_ts: null, arrived_ts: null, completed_ts: 130, note: null}]},
  // A team that only left (never arrived, never finished).
  {id: 3, type: 'polygon', team_id: 41, sent_ts: 200, completed_ts: null, map_point_id: null,
   progress: [{scope: 't41', label: 'Charlie', departed: '00:03', arrived: null, completed: null, departed_ts: 210, arrived_ts: null, completed_ts: null, note: null}]},
];
var sectors = [
  // Made at 10, assigned at 10 (a row in the log), in progress at 30, finished at 60, sent back at 90, finished again at 120.
  {id: 7, team_id: 40, status: 'completed', created_ts: 10, status_updated_at: '00:09', completed_ts: 120, can_manage: true, can_self_report: true,
   status_log: [
     {from_status: 'not_started', to_status: 'assigned', ts: 10, time: '00:00'},
     {from_status: 'assigned', to_status: 'in_progress', ts: 30, time: '00:01'},
     {from_status: 'in_progress', to_status: 'completed', ts: 60, time: '00:02'},
     {from_status: 'completed', to_status: 'needs_recheck', ts: 90, time: '00:03'},
     {from_status: 'needs_recheck', to_status: 'completed', ts: 120, time: '00:04'},
   ]},
  // A sector whose log starts with a change at 50: before it, it was in the status that change left.
  {id: 8, team_id: null, status: 'in_progress', created_ts: 5, status_updated_at: '00:05', completed_ts: null,
   status_log: [{from_status: 'assigned', to_status: 'in_progress', ts: 50, time: '00:05'}]},
  // Never changed at all: it is in the status it has.
  {id: 9, team_id: null, status: 'not_started', created_ts: 5, status_updated_at: null, completed_ts: null, status_log: []},
];
var routes = [
  {id: 21, team_id: 40, status: 'completed', created_ts: 10, completed_ts: 200, cancelled_ts: null, completed_at_display: '00:10', can_manage: true},
  {id: 22, team_id: 40, status: 'cancelled', created_ts: 10, completed_ts: null, cancelled_ts: 60, can_manage: true},
  {id: 23, team_id: 41, status: 'active', created_ts: 300, completed_ts: null, cancelled_ts: null},
];
`, ctx);

const run = (code) => JSON.parse(vm.runInContext('JSON.stringify(' + code + ')', ctx));
const at = (T) => vm.runInContext('wrTimeSet(' + T + ')', ctx);
const pick = (items, f) => items.map(f);

const out = {};

// The present: the very lists, untouched.
out.presentIsSameList = vm.runInContext('wrTimeDispatches(dispatches) === dispatches && wrTimeSectors(sectors) === sectors && wrTimeRoutes(routes) === routes', ctx);

at(50);
out.t50 = {
    dispatches: run('wrTimeDispatches(dispatches).map(d => d.id)'),
    sectors: run('wrTimeSectors(sectors).map(s => [s.id, s.status])'),
    routes: run('wrTimeRoutes(routes).map(r => [r.id, r.status])'),
};

at(105);
out.t105 = run('wrTimeDispatches(dispatches).filter(d => d.id === 1)[0]');

at(125);
out.t125 = run('wrTimeDispatches(dispatches).filter(d => d.id === 1)[0]');

at(135);
out.t135 = {
    one: run('wrTimeDispatches(dispatches).filter(d => d.id === 1)[0]'),
    broadcast: run('wrTimeDispatches(dispatches).filter(d => d.id === 2)[0]'),
    phaseOfOne: run('wrDonePhase(130, 40)'),
    nowIsTheMoment: run('wrDoneNowSec()'),
};
at(130 + 601);
out.phaseLater = run('wrDonePhase(130, 40)');

for (const T of [20, 40, 70, 100, 130]) {
    at(T);
    out['sector' + T] = run('wrTimeSectors(sectors).filter(s => s.id === 7)[0]');
}
at(40);
out.sectorsBeforeFirstChange = run('wrTimeSectors(sectors).map(s => [s.id, s.status])');
at(70);
out.sectors70 = run('wrTimeSectors(sectors).map(s => [s.id, s.status, s.completed_ts])');

at(50);
out.routes50 = run('wrTimeRoutes(routes).map(r => [r.id, r.status])');
at(70);
out.routes70 = run('wrTimeRoutes(routes).map(r => [r.id, r.status])');
at(250);
out.routes250 = run('wrTimeRoutes(routes).map(r => [r.id, r.status, r.completed_ts])');

// The lists the page holds are never touched by looking at a moment.
out.liveUntouched = run('[dispatches[0].can_delete, dispatches[0].completed_ts, sectors[0].status, routes[0].status]');

// Back to the present.
at(null);
out.afterNull = vm.runInContext('wrTimeNow() === null && wrTimeDispatches(dispatches) === dispatches', ctx);
out.nowIsWallClock = Math.abs(run('wrDoneNowSec()') - Date.now() / 1000) < 5;

console.log(JSON.stringify(out));
