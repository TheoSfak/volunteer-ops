/**
 * VolunteerOps - the time machine of «Πορεία Ομάδων» (v3.441.0).
 *
 * The replay scrubber of «Πορεία Ομάδων» already walks the teams' GPS trails back to any moment of the
 * mission. This makes the ORDERS follow it: while the scrubber is back in the past, the points, areas,
 * sectors and routes on the map are drawn as they stood at that moment - not yet given ones are not
 * there, a sector is in the status it had, an order its team had not finished yet is a live one, and
 * one finished by then fades by the very same rules as on the live map (fresh for ten minutes, then a
 * quiet record of what was covered).
 *
 * Nothing is fetched. Every order already carries the moments of its own history in the page's payload
 * (sent_ts, the unix times of each team's Ξεκινάω / Έφτασα / Ολοκληρώθηκε, a sector's created_ts and the
 * ts of each row of its status log, a route's created_ts / completed_ts / cancelled_ts), so the view of
 * a moment is worked out here from the lists the page holds. It is a READ-ONLY picture: every button
 * that would act on an order (receive, depart, complete, delete, change status ...) is off in it.
 *
 * It hooks the three render functions at their top: each hands its list through wrTimeDispatches() /
 * wrTimeSectors() / wrTimeRoutes(), which return the list itself while the time is the present, so with
 * the machine off the render functions behave exactly as before. And wrDoneNowSec() (the clock that
 * ages a finished order) answers with the moment looked at, so the fade follows the scrubber too.
 *
 * What it does NOT know: an order withdrawn since (its row is deleted, only a label is logged), who a
 * sector belonged to at that moment (it keeps its team of today), the check marks inside a sector's
 * buildings, and where the teams had been (the GPS cells of «Κάλυψη ομάδων» stay as they are now - the
 * trails themselves are drawn up to the moment).
 *
 * Loaded before the page's own script, so it only DECLARES things here and reaches for the page's
 * globals (dispatches, sectors, routes, map, the trail scrubber, t()) when it is called.
 */

// null = the present; otherwise the unix second being looked at.
let wrTimeT = null;
let wrTimeChip = null;

// Same colours the server gives a status (SECTOR_STATUS_COLORS in config.php).
const WR_TIME_STATUS_COLORS = {
    not_started: 'secondary', assigned: 'info', en_route: 'primary', in_progress: 'warning',
    completed: 'success', needs_recheck: 'danger',
};

function wrTimeNow() { return wrTimeT; }
function wrTimeActive() { return wrTimeT !== null; }

// ── An order as it stood at moment T ────────────────────────────────────────
// Each returns null for something that did not exist yet, otherwise a copy (the live list is never touched).

function wrTimeDispatch(d, T) {
    if (d.sent_ts && d.sent_ts > T) return null;
    // What each team had done by T; a line with nothing done yet is not a line.
    const progress = (d.progress || []).map(p => {
        const dep = !!p.departed_ts && p.departed_ts <= T;
        const arr = !!p.arrived_ts && p.arrived_ts <= T;
        const com = !!p.completed_ts && p.completed_ts <= T;
        return Object.assign({}, p, {
            departed: dep ? p.departed : null, departed_ts: dep ? p.departed_ts : null,
            arrived: arr ? p.arrived : null, arrived_ts: arr ? p.arrived_ts : null,
            completed: com ? p.completed : null, completed_ts: com ? p.completed_ts : null,
            note: com ? p.note : null,
        });
    }).filter(p => p.departed || p.arrived || p.completed);
    // The finish that counts is the one of the team this order was sent to. An order sent to every team
    // has none, as on the live map.
    const own = d.team_id !== null && d.team_id !== undefined ? progress.find(p => p.scope === 't' + d.team_id) : null;
    return Object.assign({}, d, {
        progress,
        completed_ts: own && own.completed ? own.completed_ts : null,
        completed_display: own && own.completed ? own.completed : null,
        declines: (d.declines || []).filter(dc => !dc.ts || dc.ts <= T),
        // A picture of the past: nothing here acts on the order.
        eta: null, acks: [], my_receipt: null, my_departed: null, my_ack: null, my_completed: null, my_declined: null,
        can_delete: false, can_receive: false, can_depart: false, can_ack: false, can_complete: false, can_decline: false,
    });
}

function wrTimeSector(s, T) {
    if (s.created_ts && s.created_ts > T) return null;
    const log = s.status_log || [];
    const upto = log.filter(l => l.ts && l.ts <= T);
    const last = upto.length ? upto[upto.length - 1] : null;
    // Before its first change a sector was in the status that change left (the log keeps both ends).
    const status = last ? last.to_status : ((log.length && log[0].from_status) || (log.length ? 'not_started' : s.status));
    return Object.assign({}, s, {
        status,
        status_label: t('sector.status.' + status),
        status_color: WR_TIME_STATUS_COLORS[status] || 'secondary',
        status_updated_at: last ? last.time : null,
        completed_ts: status === 'completed' && last ? last.ts : null,
        status_log: upto,
        declined: null, next_status: null, next_status_label: null,
        can_manage: false, can_acknowledge: false, can_self_report: false, can_decline: false,
    });
}

function wrTimeRoute(r, T) {
    if (r.created_ts && r.created_ts > T) return null;
    let status = 'active';
    if (r.completed_ts && r.completed_ts <= T) status = 'completed';
    else if (r.status === 'cancelled' && r.cancelled_ts && r.cancelled_ts <= T) status = 'cancelled';
    return Object.assign({}, r, {
        status,
        completed_ts: status === 'completed' ? r.completed_ts : null,
        completed_at_display: status === 'completed' ? r.completed_at_display : null,
        declined: null, can_manage: false, can_decline: false, is_route_member: false,
    });
}

function wrTimeMap(list, fn) {
    if (wrTimeT === null || !Array.isArray(list)) return list;
    const T = wrTimeT;
    return list.map(x => fn(x, T)).filter(Boolean);
}
function wrTimeDispatches(list) { return wrTimeMap(list, wrTimeDispatch); }
function wrTimeSectors(list) { return wrTimeMap(list, wrTimeSector); }
function wrTimeRoutes(list) { return wrTimeMap(list, wrTimeRoute); }

// ── Looking at a moment ─────────────────────────────────────────────────────
function wrTimeLabel(ts) {
    return new Date(ts * 1000).toLocaleString(typeof jsLocale !== 'undefined' ? jsLocale : undefined,
        {day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit'});
}

// What the scrubber tells us after every render of the trail. A cutoff of Infinity, or the very end of the
// scrubber, is the present; so is a trail view with «Οι εντολές όπως ήταν» unticked.
function wrTimeFollow(cutoffTs) {
    const on = document.getElementById('trailOrdersAsOf')?.checked !== false;
    const atEnd = cutoffTs === Infinity || (typeof trailMaxTs === 'number' && trailMaxTs !== null && cutoffTs >= trailMaxTs);
    wrTimeSet(on && !atEnd ? cutoffTs : null);
}

function wrTimeSet(ts) {
    const next = (ts === null || ts === undefined || !isFinite(ts)) ? null : Math.floor(ts);
    if (next === wrTimeT) return;
    wrTimeT = next;
    // The same re-render the choice of «Ολοκληρωμένα» makes: the three layers, the counter, the amber chip.
    if (typeof wrDoneRerender === 'function' && typeof map !== 'undefined' && map) wrDoneRerender();
    wrTimeSummaryRefresh();
}

// «Τώρα»: the scrubber goes back to its end, which is the present picture.
function wrTimeBackToNow() {
    const scrubber = document.getElementById('trailScrubber');
    if (!scrubber || typeof trailMaxTs !== 'number') { wrTimeSet(null); return; }
    if (typeof stopTrailReplay === 'function') stopTrailReplay();
    scrubber.value = trailMaxTs;
    renderTrailUpTo(currentTrails, trailMaxTs);
    updateTrailScrubberLabel(trailMaxTs);
}

// One line under the scrubber saying what the orders were at that moment, and a chip ON the map, so the
// map never shows a past picture silently (the bar is out of sight in the map's full-screen view).
function wrTimeSummaryRefresh() {
    const line = document.getElementById('trailOrdersSummary');
    if (line) {
        if (wrTimeT === null) {
            line.textContent = '';
            line.classList.add('d-none');
        } else {
            const time = wrTimeLabel(wrTimeT);
            const ds = wrTimeDispatches((dispatches || []).filter(d => !d.map_point_id));
            const ss = wrTimeSectors(sectors || []);
            const rs = wrTimeRoutes(routes || []).filter(r => r.status !== 'cancelled');
            const n = {
                d: ds.length, dc: ds.filter(d => d.completed_ts).length,
                s: ss.length, sc: ss.filter(s => s.status === 'completed').length,
                r: rs.length, rc: rs.filter(r => r.status === 'completed').length,
            };
            line.textContent = (n.d + n.s + n.r === 0) ? t('timemachine.summary_none', {time}) : t('timemachine.summary', Object.assign({time}, n));
            line.classList.remove('d-none');
        }
    }
    wrTimeChipRefresh();
}

function wrTimeChipRefresh() {
    if (typeof map === 'undefined' || !map) return;
    if (wrTimeT === null) {
        if (wrTimeChip) { wrTimeChip.remove(); wrTimeChip = null; }
        return;
    }
    if (!wrTimeChip) {
        const Chip = L.Control.extend({
            options: {position: 'bottomleft'},
            onAdd: function () {
                const div = L.DomUtil.create('div', 'leaflet-control wr-hidden-chip wr-time-chip');
                L.DomEvent.disableClickPropagation(div);
                div.addEventListener('click', ev => { if (ev.target.closest('[data-time-now]')) wrTimeBackToNow(); });
                return div;
            },
        });
        wrTimeChip = new Chip().addTo(map);
    }
    wrTimeChip.getContainer().innerHTML = '<i class="bi bi-clock-history me-1"></i>'
        + escapeHtml(t('timemachine.chip', {time: wrTimeLabel(wrTimeT)}))
        + ' <button type="button" data-time-now title="' + escapeHtml(t('timemachine.chip_now_title')) + '">' + escapeHtml(t('timemachine.chip_now')) + '</button>';
}
