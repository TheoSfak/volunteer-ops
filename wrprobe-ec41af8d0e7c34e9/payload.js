/**
 * War Room (Action Room) - the admin «Κονσόλα» view.
 *
 * One screen that does not scroll: the map is always in view, and around it the
 * four things a coordinator keeps asking - what needs me, what is each team
 * doing, what do I want to say or ask, what is coming in from the field.
 *
 * It is a VIEW over the page, not a second implementation. Every card is still
 * rendered by war-room.php and still owns its handlers; this file moves the
 * real nodes into the console's regions (never clones them), and builds four
 * small components from state the page already keeps - the poll's arrays,
 * assistantData (the «Βοηθός» findings), the team board's order model. The
 * order form posts through the same actions the old cards post, and opens the
 * same dispatch / route windows, so nothing the old layout could send is lost.
 *
 * Loaded (deferred) only when war-room.php renders the console view, i.e. for
 * command staff who chose it. The pure helpers at the top are also loaded by
 * `node --test` (tests/js/war-room-console.test.js).
 */

// ── pure helpers ─────────────────────────────────────────────────────────────

/**
 * «18/08 23:16» (day/month hour:minute, the form the poll's lists use) as a
 * number that sorts. The year is deliberately left out: none of those strings
 * carries one, and a feed only ever holds one mission's worth.
 */
function wrcStampKey(text) {
    const m = /^(\d{1,2})\/(\d{1,2})(?:\/\d{2,4})?\s+(\d{1,2}):(\d{2})/.exec(String(text || '').trim());
    if (!m) return 0;
    return ((Number(m[2]) * 100 + Number(m[1])) * 100 + Number(m[3])) * 100 + Number(m[4]);
}

const WRC_SEV_RANK = {critical: 0, high: 1, warn: 2, info: 3};
/** Findings that fold into one line once there are this many of them. */
const WRC_GROUPS = [
    {key: 'orders', kinds: ['order', 'dispatch', 'sector'], min: 2, label: 'console.orders_waiting', icon: 'bi-send-check', target: 'reportModal'},
    {key: 'silent', kinds: ['silent'], min: 3, label: 'console.silent_many', icon: 'bi-wifi-off', target: 'participantsCard'},
];

function wrcSevRank(sev) {
    return Object.prototype.hasOwnProperty.call(WRC_SEV_RANK, sev) ? WRC_SEV_RANK[sev] : 4;
}

/** Worst first, then newest first; stable for equals. */
function wrcOrderQueue(items) {
    return items
        .map((item, i) => ({item, i}))
        .sort((a, b) => (wrcSevRank(a.item.sev) - wrcSevRank(b.item.sev))
            || ((b.item.ts || 0) - (a.item.ts || 0))
            || (a.i - b.i))
        .map(x => x.item);
}

/**
 * Who is picked, grouped by team: [{teamId, label, color, count, size, whole,
 * ids}], and how many people in all. A team counts as «whole» when every member
 * is picked - which is what makes the difference between sending a point to a
 * team and sending it to two of its people.
 */
function wrcSummarizeSelection(teams, selected) {
    const chips = [];
    let total = 0;
    (teams || []).forEach(team => {
        const all = (team.members || []).map(m => Number(m.user_id));
        const picked = all.filter(id => selected.has(id));
        if (!picked.length) return;
        total += picked.length;
        chips.push({
            teamId: Number(team.id), label: team.label, color: team.color || null,
            count: picked.length, size: all.length, whole: picked.length === all.length, ids: picked,
        });
    });
    return {chips, total};
}

/**
 * How a point or an area can be sent to that selection. The dispatch window
 * knows teams (one, or all of them), not people, so people become a one-point
 * route - which the route window already hands to individuals.
 *   ask    nobody picked: the window's own team list decides
 *   team   exactly one whole team
 *   all    every team, whole
 *   many   several whole teams but not all: one at a time
 *   people part of a team, or people from several
 */
function wrcDispatchPlan(summary, teamCount) {
    if (!summary.total) return {mode: 'ask'};
    const whole = summary.chips.every(c => c.whole);
    if (whole && summary.chips.length === 1) return {mode: 'team', teamId: summary.chips[0].teamId};
    if (whole && summary.chips.length === teamCount && teamCount > 1) return {mode: 'all'};
    if (whole) return {mode: 'many'};
    return {mode: 'people'};
}

/** The seed the route window takes: one team (whole or part of it), or people from several. */
function wrcRouteSeedFor(summary) {
    if (!summary.total) return {};
    if (summary.chips.length === 1) {
        const c = summary.chips[0];
        return c.whole ? {teamId: c.teamId} : {teamId: c.teamId, memberIds: c.ids};
    }
    return {crossTeam: true, memberIds: summary.chips.flatMap(c => c.ids)};
}

const WRC_GREEK_LETTERS = ['Α', 'Β', 'Γ', 'Δ', 'Ε', 'Ζ', 'Η', 'Θ', 'Ι', 'Κ', 'Λ', 'Μ', 'Ν', 'Ξ', 'Ο', 'Π', 'Ρ', 'Σ', 'Τ', 'Υ', 'Φ', 'Χ', 'Ψ', 'Ω'];

/**
 * The area the sector tab works in: the one asked for while it still exists, otherwise the
 * newest (the one just drawn is the one wanted next), otherwise none.
 */
function wrcPickArea(areas, wantedId) {
    const list = Array.isArray(areas) ? areas : [];
    const wanted = list.find(a => String(a.id) === String(wantedId));
    if (wanted) return wanted;
    return list.reduce((best, a) => (!best || Number(a.id) > Number(best.id)) ? a : best, null);
}

/**
 * The name a freshly drawn sector starts with: «Τομέας Α», then «Τομέας Β»... the first
 * letter no existing sector already carries, and numbers once the alphabet is used up.
 * `labels` are the sectors' names as they are; `prefix` is the word for «sector».
 */
function wrcNextSectorLabel(labels, prefix) {
    const head = String(prefix).trim().toLowerCase() + ' ';
    const used = new Set();
    (labels || []).forEach(label => {
        const text = String(label || '').trim();
        if (text.toLowerCase().startsWith(head)) used.add(text.slice(head.length).trim().toUpperCase());
    });
    for (const letter of WRC_GREEK_LETTERS) if (!used.has(letter)) return prefix + ' ' + letter;
    let n = 1;
    while (used.has(String(n))) n++;
    return prefix + ' ' + n;
}

/** One short phrase for the «Προς» line: «Alpha 1», «Alpha 1 · 2 από 4», «3 άτομα». */
function wrcRecipientLabel(chip, ofWord) {
    return chip.whole ? chip.label : `${chip.label} · ${chip.count} ${ofWord} ${chip.size}`;
}

// ── the team avatars: a ring, a contact dot, a badge ─────────────────────────

/**
 * How far along an order is, 0..1 - the arc of the ring around a team's avatar.
 * The descriptor is what the page knows of the order, not the order itself:
 *   {type: 'dispatch', departed, arrived}   «Ξεκινάω» / «Έφτασα» were pressed
 *   {type: 'sector', status, acked}         the sector's status and whether it was received
 *   {type: 'route', done, total}            waypoints finished of all
 * (Finished orders never reach here: the team board leaves them out.)
 */
const WRC_SECTOR_STAGE = {en_route: 0.45, in_progress: 0.7, needs_recheck: 0.7};

function wrcOrderProgress(o) {
    if (!o) return 0;
    if (o.type === 'dispatch') return o.arrived ? 0.85 : (o.departed ? 0.5 : 0.1);
    if (o.type === 'sector') {
        if (o.status === 'assigned') return o.acked ? 0.25 : 0.1;
        return Object.prototype.hasOwnProperty.call(WRC_SECTOR_STAGE, o.status) ? WRC_SECTOR_STAGE[o.status] : 0;
    }
    if (o.type === 'route') {
        const total = Number(o.total) || 0;
        if (total <= 0) return 0.1;
        return Math.min(1, Math.max(0.1, (Number(o.done) || 0) / total));
    }
    return 0;
}

/** none: nobody on the team; ok: everyone reachable; warn: some silent; bad: all of them silent. */
function wrcContactTone(total, stale) {
    if (!(total > 0)) return 'none';
    if (!(stale > 0)) return 'ok';
    return stale >= total ? 'bad' : 'warn';
}

/**
 * Who a contact figure is about: only the people whose GPS is on. Somebody without the
 * GPS tick is never asked for a position, so "no position" is neither true nor false of
 * them, and they must not sit in the total either - "3 of 20" and "3 of 5" are different
 * statements when fifteen of the twenty were never being measured.
 * members -> {total, silent}; silentOf(userId) says whether one of them has gone quiet.
 */
function wrcContactOf(members, silentOf) {
    const measured = (members || []).filter(m => m.takes_part);
    return {total: measured.length, silent: measured.filter(m => silentOf(m.user_id)).length};
}

/**
 * The one thing worth a badge on a team's avatar, worst first:
 * {sos, needsHelp, blocked, orders} -> {kind, tone, text} or null.
 *   sos / help   someone on the team needs help now
 *   blocked      the team said «Δεν μπορώ», or stands at a locked route point
 *   more         it has more than one order running
 */
function wrcTeamBadge(f) {
    if (f.sos > 0) return {kind: 'sos', tone: 'bad', text: '!'};
    if (f.needsHelp > 0) return {kind: 'help', tone: 'bad', text: '!'};
    if (f.blocked > 0) return {kind: 'blocked', tone: 'warn', text: '!'};
    if (f.orders > 1) return {kind: 'more', tone: 'info', text: '+' + (f.orders - 1)};
    return null;
}

/**
 * «Δεν μπορώ» as the one line a team's row can hold: the reason (already
 * translated) and, after a dash, the note the volunteer wrote with it.
 */
function wrcDeclineLine(decline, reasonLabel) {
    if (!decline) return '';
    const note = String(decline.note || '').trim();
    return note ? `${reasonLabel} — «${note}»` : reasonLabel;
}

// ── what is new in the dock's tabs ───────────────────────────────────────────

/**
 * The keys of what has arrived that a tab has not shown yet. `seen` is a Set of the
 * keys the coordinator has looked at, or null the first time this mission is opened
 * in this browser: nothing is new on a first visit (the history is not news).
 */
function wrcUnreadKeys(keys, seen) {
    if (!seen) return [];
    return keys.filter(k => !seen.has(k));
}

/**
 * New chat messages per room, from the room sizes the poll sends ({general: {n}, '137':
 * {n}, ...}) and the sizes seen last ({room: n}, null on a first visit). `viewed` is the
 * room open on screen right now (or null): looking at it makes all its messages seen.
 * A room that shrank (a message was deleted) is not owed the difference later.
 * -> {seen: the sizes seen after this look, byRoom: {room: new}, total}
 */
function wrcChatUnread(rooms, seen, viewed) {
    const next = {};
    const byRoom = {};
    let total = 0;
    Object.keys(rooms || {}).forEach(key => {
        const n = Number(rooms[key] && rooms[key].n) || 0;
        const before = seen ? Math.min(Number(seen[key]) || 0, n) : n;
        next[key] = key === viewed ? n : before;
        const fresh = n - next[key];
        if (fresh > 0) { byRoom[key] = fresh; total += fresh; }
    });
    return {seen: next, byRoom, total};
}

/**
 * Which chat room the «unread messages» counter in the bar should open (v3.412.0): the
 * room already on screen if it has new messages, otherwise the first room, in the order
 * of the room tabs, that has some. null when nothing is unread (the button then only
 * brings the chat up).
 *   byRoom    {room: new}, from wrcChatUnread()
 *   roomKeys  the rooms in tab order
 */
function wrcChatTarget(byRoom, roomKeys, activeKey) {
    const has = key => (Number((byRoom || {})[key]) || 0) > 0;
    if (has(activeKey)) return activeKey;
    const first = (roomKeys || []).find(has);
    return first === undefined ? null : first;
}

/**
 * Orders sent a moment ago that nobody has acknowledged yet - the ones the assistant
 * does not raise until they have waited out its grace period, and which therefore
 * leave the queue saying «all under control» while 0 of 48 have answered.
 * `cards` are the acknowledgement panel's cards (the poll's `ackTracker`): each has
 * `people` ({ack_ts, declined}), `ts` (when it was sent, unix seconds), `finished`.
 * A card counts while somebody on it has not answered («Δεν μπορώ» is an answer) and
 * it is younger than the grace; past the grace it has its own row in the queue.
 * `skip` holds the keys of cards the coordinator closed in the panel. -> {orders,
 * acked, total}, where acked/total are people across those cards, as the panel counts.
 */
function wrcWaitingReceipts(cards, nowSec, graceSec, skip) {
    let orders = 0, acked = 0, total = 0;
    (cards || []).forEach(card => {
        if (!card || card.finished || card.gone) return;
        if (skip && skip.has(card.key)) return;
        const people = card.people || [];
        if (!people.length) return;
        if (people.every(p => p.ack_ts || p.declined)) return;
        if (nowSec - Number(card.ts) >= graceSec) return;
        orders++;
        total += people.length;
        acked += people.filter(p => p.ack_ts && !p.declined).length;
    });
    return {orders, acked, total};
}

// ── the phases: preparation, live, closing ───────────────────────────────────

const WRC_PHASES = ['prep', 'live', 'close'];

function wrcNormalizePhase(value) {
    return WRC_PHASES.includes(value) ? value : 'live';
}

/** Where the dock opens when a phase begins (and, were there any, which plan sections come open: none do). */
function wrcPhasePreset(phase) {
    switch (wrcNormalizePhase(phase)) {
        // Σχέδιο opens with every card folded in every phase: the checklists' own rows
        // open the card a finding is about, and the rest is the coordinator's to open.
        case 'prep': return {tab: 'plan', sections: [], composer: false};
        case 'close': return {tab: 'feed', sections: [], composer: false};
        default: return {tab: 'feed', sections: [], composer: true};
    }
}

/**
 * Two things the preparation checklist asks about the teams themselves (v3.410.0):
 * has everybody on the roster been put in a team, and does every team have at least
 * one person whose GPS is on. A team nobody carries a phone for cannot be tracked or
 * sent an order, however many people are in it.
 *   teams       [{label, members: [{user_id, takes_part}]}], as the poll sends them
 *   rosterIds   every person approved on the mission, in a team or not
 * -> {roster, unteamed, teamsNoGps, teamsNoGpsNames}
 */
function wrcTeamGaps(teams, rosterIds) {
    const list = teams || [];
    const roster = Array.from(new Set((rosterIds || []).map(Number)));
    const inTeam = new Set(list.flatMap(tm => (tm.members || []).map(m => Number(m.user_id))));
    const dark = list.filter(tm => !(tm.members || []).some(m => m.takes_part));
    return {
        roster: roster.length,
        unteamed: roster.filter(id => !inTeam.has(id)).length,
        teamsNoGps: dark.length,
        teamsNoGpsNames: dark.map(tm => tm.label),
    };
}

/**
 * The red «LIVE» counter in the bar (v3.411.0): how many streams are on air, and
 * whether the button should flash. It flashes for as long as a stream is on air that
 * command has not yet gone to look at; opening the live screen calms it to a slow
 * pulse, and the next stream to go on air flashes it again.
 *   streams  [{id, status}] as the Live card lists them: 'requested' = asked and not
 *            yet accepted, 'live' = transmitting, anything else is finished
 *   seenIds  ids of the streams command has already opened the live screen for
 * -> {onAir, waiting, fresh, flash}
 */
function wrcLiveSignal(streams, seenIds) {
    const list = streams || [];
    const seen = new Set((seenIds || []).map(String));
    const onAir = list.filter(s => s.status === 'live');
    const fresh = onAir.filter(s => !seen.has(String(s.id))).length;
    return {
        onAir: onAir.length,
        waiting: list.filter(s => s.status === 'requested').length,
        fresh,
        flash: fresh > 0,
    };
}

/**
 * «Είμαστε έτοιμοι;» - what has to be true before the mission goes live.
 * facts: {teams, people, noGps, gpsPeople, sectors, sectorsFree, stale, roster, unteamed, teamsNoGps, teamsNoGpsNames}
 *   gpsPeople  how many of `people` have their GPS on; `stale` is out of THEM, never out of everybody
 *   roster/unteamed/teamsNoGps/teamsNoGpsNames  see wrcTeamGaps()
 * -> rows {key, tone, n, of} in the order they should be fixed in; tone is
 * ok / warn / bad, and `ready` is true only when nothing is wrong.
 */
function wrcReadiness(f) {
    const rows = [
        {key: 'teams', tone: f.teams > 0 ? 'ok' : 'bad', n: f.teams, of: 0, card: 'teamsCard'},
        {key: 'unteamed', tone: f.unteamed > 0 ? 'warn' : 'ok', n: f.unteamed || 0, of: f.roster || 0, card: 'teamsCard'},
        {key: 'teamgps', tone: f.teamsNoGps > 0 ? 'bad' : 'ok', n: f.teamsNoGps || 0, of: f.teams, names: (f.teamsNoGpsNames || []).join(', '), card: 'participantsCard'},
        {key: 'gps', tone: f.noGps > 0 ? 'warn' : 'ok', n: f.noGps, of: f.people, card: 'participantsCard'},
        {key: 'sectors', tone: f.sectors > 0 ? 'ok' : 'warn', n: f.sectors, of: 0, card: 'sectorsCard'},
        {key: 'assigned', tone: f.sectorsFree > 0 ? 'warn' : 'ok', n: f.sectors - f.sectorsFree, of: f.sectors, card: 'sectorsListCard'},
        {key: 'contact', tone: f.stale > 0 ? 'warn' : 'ok', n: f.stale, of: f.gpsPeople, card: 'participantsCard'},
    ];
    // Sectors that are not there cannot be assigned, and with nobody on GPS there is
    // nobody whose contact could be measured (the GPS row above already says so): those
    // rows have nothing to say.
    // Nobody on the roster means nobody to put in a team, and with no teams the first
    // row already says so: neither of the two new rows has anything to add then.
    const shown = rows.filter(r => !(r.key === 'assigned' && f.sectors === 0) && !(r.key === 'contact' && !(f.gpsPeople > 0))
        && !(r.key === 'unteamed' && !(f.roster > 0)) && !(r.key === 'teamgps' && !(f.teams > 0)));
    return {rows: shown, ready: shown.every(r => r.tone === 'ok'), open: shown.filter(r => r.tone !== 'ok').length};
}

/**
 * «Τι μένει ανοιχτό» before closing: only the things that are open, worst first.
 * facts: {sos, incidents, shortages, orders, sectorsOpen, overdue}
 */
function wrcClosingItems(f) {
    const all = [
        {key: 'sos', n: f.sos, tone: 'bad'},
        {key: 'orders', n: f.orders, tone: 'warn'},
        {key: 'sectors', n: f.sectorsOpen, tone: 'warn'},
        {key: 'incidents', n: f.incidents, tone: 'warn'},
        {key: 'shortages', n: f.shortages, tone: 'warn'},
        {key: 'overdue', n: f.overdue, tone: 'warn'},
    ];
    return all.filter(r => r.n > 0);
}

if (typeof module !== 'undefined' && module.exports) {
    module.exports = {
        wrcStampKey, wrcSevRank, wrcOrderQueue, wrcSummarizeSelection,
        wrcDispatchPlan, wrcRouteSeedFor, wrcRecipientLabel, wrcNextSectorLabel, wrcPickArea,
        wrcOrderProgress, wrcContactTone, wrcContactOf, wrcTeamBadge, wrcDeclineLine, wrcWaitingReceipts,
        wrcUnreadKeys, wrcChatUnread, wrcChatTarget,
        wrcNormalizePhase, wrcPhasePreset, wrcReadiness, wrcTeamGaps, wrcLiveSignal, wrcClosingItems,
    };
}

// ── the view ─────────────────────────────────────────────────────────────────

(function () {
    'use strict';
    if (typeof document === 'undefined') return;
    const root = document.getElementById('wrConsole');
    if (!root || typeof t !== 'function') return;

    const MISSION_ID = Number(root.dataset.mission);
    const STORE = 'wr-console-' + MISSION_ID + '-';
    const $ = (sel, ctx) => (ctx || document).querySelector(sel);
    const $$ = (sel, ctx) => Array.from((ctx || document).querySelectorAll(sel));
    const esc = s => escapeHtml(String(s === null || s === undefined ? '' : s));
    const load = (key, fallback) => { try { const v = localStorage.getItem(STORE + key); return v === null ? fallback : v; } catch (e) { return fallback; } };
    const save = (key, value) => { try { localStorage.setItem(STORE + key, String(value)); } catch (e) { /* blocked storage: it just starts fresh */ } };

    // What the page already knows, read defensively: a name that is not defined
    // yet (or at all, on a mission without the feature) is an empty list.
    const page = {
        sos: () => (typeof sosAlerts !== 'undefined' && Array.isArray(sosAlerts)) ? sosAlerts : [],
        voice: () => (typeof voiceMessages !== 'undefined' && Array.isArray(voiceMessages)) ? voiceMessages : [],
        incidents: () => (typeof missionIncidents !== 'undefined' && Array.isArray(missionIncidents)) ? missionIncidents : [],
        shortages: () => (typeof shortageReports !== 'undefined' && Array.isArray(shortageReports)) ? shortageReports : [],
        pois: () => (typeof pointsOfInterest !== 'undefined' && Array.isArray(pointsOfInterest)) ? pointsOfInterest : [],
        media: () => (typeof media !== 'undefined' && Array.isArray(media)) ? media : [],
        sectors: () => (typeof sectors !== 'undefined' && Array.isArray(sectors)) ? sectors : [],
        areas: () => (typeof areas !== 'undefined' && Array.isArray(areas)) ? areas : [],
        dispatches: () => (typeof dispatches !== 'undefined' && Array.isArray(dispatches)) ? dispatches : [],
        routes: () => (typeof routes !== 'undefined' && Array.isArray(routes)) ? routes : [],
        assistant: () => (typeof assistantData !== 'undefined') ? assistantData : null,
    };

    const startPhase = wrcNormalizePhase(load('phase', 'live'));
    const state = {
        phase: startPhase,         // prep / live / close: how the console is composed
        tab: load('tab-' + startPhase, wrcPhasePreset(startPhase).tab),
        filter: 'all',
        open: new Set(),           // team ids whose member list is open
        ordersOpen: new Set(),     // team ids whose row shows all their orders, not only the first
        selected: new Set(),       // user ids the next order goes to
        verb: load('verb', 'point'),
        areaId: '',                // the area the sector tab is working in (see wrcPickArea)
        queueMore: false,
        teams: [],                 // as the poll sends them, with a label added
        ack: [],                   // the acknowledgement panel's cards, as the last poll sent them
        chatRooms: null,           // messages per chat room, from the last poll (null until the first one)
        stale: null,               // pingStaleness from the last poll
        live: {},                  // participantLive from the last poll
        online: [],
        // 'sections2': the first version of this list also took what the phase presets opened,
        // and Σχέδιο now starts closed - so an old list is not carried over.
        sections: new Set((load('sections2', '') || '').split(',').filter(Boolean)),
    };

    // ── building the regions ─────────────────────────────────────────────────
    const offlineBanner = $('#offlineQueueBanner', root);
    const offlineFailures = $('#offlineQueueFailures', root);

    root.innerHTML = `
        <div class="wrc-left">
            <section class="wrc-sec wrc-phase-panel" id="wrcPhasePanel"></section>
            <section class="wrc-sec wrc-queue" id="wrcQueue">
                <header class="wrc-sec-h"><i class="bi bi-bell-fill"></i><span class="wrc-h">${esc(t('console.queue_title'))}</span><span class="wrc-cnt" id="wrcQueueCnt">0</span></header>
                <div class="wrc-queue-body" id="wrcQueueBody"></div>
            </section>
            <section class="wrc-sec wrc-teams" id="wrcTeams">
                <header class="wrc-sec-h"><i class="bi bi-people-fill"></i><span class="wrc-h">${esc(t('console.teams_title'))}</span><span class="wrc-cnt" id="wrcTeamsCnt">0</span>
                    <button type="button" class="wrc-link ms-auto" id="wrcSelAll">${esc(t('console.select_all'))}</button>
                    ${typeof floorBaselineOpen === 'function' ? `<button type="button" class="wrc-link" id="wrcFloorBtn" title="${esc(t('floor.btn_title'))}" aria-label="${esc(t('floor.btn'))}"><i class="bi bi-building-up"></i></button>` : ''}
                    ${typeof meshGatewayOpen === 'function' && window.MeshGateway ? `<button type="button" class="wrc-link" id="wrcMeshBtn" data-mesh-btn title="${esc(t('mesh.btn_title'))}" aria-label="${esc(t('mesh.btn'))}"><i class="bi bi-broadcast-pin"></i></button>` : ''}</header>
                <div class="wrc-teams-body" id="wrcTeamsBody"></div>
            </section>
        </div>
        <div class="wrc-center">
            <div class="wrc-mapslot" id="wrcMapSlot"></div>
            <div class="wrc-composer" id="wrcComposer"></div>
        </div>
        <div class="wrc-dock">
            <div class="wrc-tabs" role="tablist" id="wrcTabs">
                <button type="button" role="tab" data-tab="feed">${esc(t('console.tab_feed'))}<span class="wrc-cnt new d-none" id="wrcFeedCnt"></span></button>
                <button type="button" role="tab" data-tab="chat">${esc(t('console.tab_chat'))}<span class="wrc-cnt new d-none" id="wrcChatCnt"></span></button>
                <button type="button" role="tab" data-tab="media">${esc(t('console.tab_media'))}<span class="wrc-cnt new d-none" id="wrcMediaCnt"></span></button>
                <button type="button" role="tab" data-tab="plan">${esc(t('console.tab_plan'))}</button>
            </div>
            <div class="wrc-pane" data-pane="feed" role="tabpanel">
                <div class="wrc-chips" id="wrcFeedChips"></div>
                <div class="wrc-feed" id="wrcFeed"></div>
            </div>
            <div class="wrc-pane" data-pane="chat" role="tabpanel"></div>
            <div class="wrc-pane" data-pane="media" role="tabpanel"></div>
            <div class="wrc-pane" data-pane="plan" role="tabpanel" id="wrcPlan"></div>
        </div>`;
    const left = $('.wrc-left', root);
    if (offlineBanner) left.insertBefore(offlineBanner, left.firstChild);
    if (offlineFailures) left.insertBefore(offlineFailures, offlineBanner ? offlineBanner.nextSibling : left.firstChild);

    // Which pane or plan section every card lives in. A card that is on the page
    // but named nowhere here still lands in the plan's last section: nothing the
    // old layout showed may vanish from this one.
    const PLAN_SECTIONS = [
        ['teams', 'console.plan_teams', ['teamsCard', 'participantsCard', 'shiftsCard']],
        ['areas', 'console.plan_areas', ['sectorsListCard', 'sectorsCard', 'restrictedAreasCard', 'missingPersonCard', 'weatherCard', 'poiListCard']],
        ['orders', 'console.plan_orders', ['teamRoutesAdminCard', 'dispatchCard', 'routeOrderCard', 'requestLocationCard', 'requestPhotoCard', 'requestVideoCard', 'requestLiveCard', 'requestTaskCard', 'requestSpeakCard', 'broadcastCard', 'broadcastPhotoCard']],
        ['field', 'console.plan_field', ['shortageListCard', 'incidentsListCard', 'activityCard', 'trailEventsCard', 'sosAlertsCard', 'voiceMessagesCard']],
        ['mission', 'console.plan_mission', ['triageCard', 'briefingCard', 'missionVisitorsCard', 'missionMgmtCard', 'endMissionCard']],
        ['other', 'console.plan_other', []],
    ];
    const PANE_CARDS = {chat: ['chatCard'], media: ['mediaCard', 'liveStreamsCard']};

    const planEl = $('#wrcPlan', root);
    planEl.innerHTML = PLAN_SECTIONS.map(([key, label]) => `
        <section class="wrc-acc${state.sections.has(key) ? ' open' : ''}" data-sec="${key}">
            <button type="button" class="wrc-acc-h" data-sec-toggle="${key}"><i class="bi bi-chevron-right"></i>${esc(t(label))}</button>
            <div class="wrc-acc-b"></div>
        </section>`).join('');

    function cardNode(id) { return document.querySelector('[data-card-id="' + id + '"]'); }
    function placeCard(id, target) { const el = cardNode(id); if (el && target) target.appendChild(el); return !!el; }

    placeCard('mapCard', $('#wrcMapSlot', root));
    Object.keys(PANE_CARDS).forEach(pane => PANE_CARDS[pane].forEach(id => placeCard(id, $('.wrc-pane[data-pane="' + pane + '"]', root))));
    const placed = new Set(['mapCard'].concat(...Object.values(PANE_CARDS)));
    PLAN_SECTIONS.forEach(([key, , ids]) => ids.forEach(id => {
        if (placeCard(id, $('.wrc-acc[data-sec="' + key + '"] .wrc-acc-b', root))) placed.add(id);
    }));
    // Anything else: not in a legacy row of its own any more either.
    $$('[data-card-id]').forEach(el => {
        if (root.contains(el)) return;
        $('.wrc-acc[data-sec="other"] .wrc-acc-b', root).appendChild(el);
    });
    $$('.wrc-acc', root).forEach(sec => { if (!$('.wrc-acc-b', sec).children.length) sec.remove(); });
    // The rows the cards used to sit in are empty now. Same sweep the zones do.
    $$('.wr-legacy-row').forEach(el => el.remove());

    // ── the hero: tooltips for the icon-only buttons, and the way to the site's menu ──
    $$('.war-room-hero .btn').forEach(btn => {
        if (btn.id === 'wrConsoleToggle' || btn.getAttribute('title')) return;
        const label = btn.textContent.replace(/\s+/g, ' ').trim();
        if (label) { btn.setAttribute('title', label); btn.setAttribute('aria-label', label); }
    });
    const hero = $('.war-room-hero');
    if (hero && typeof toggleSidebar === 'function') {
        const menuBtn = document.createElement('button');
        menuBtn.type = 'button';
        menuBtn.className = 'btn btn-outline-light wrc-menu-btn';
        menuBtn.title = t('console.menu');
        menuBtn.setAttribute('aria-label', t('console.menu'));
        menuBtn.innerHTML = '<i class="bi bi-list"></i>';
        menuBtn.addEventListener('click', toggleSidebar);
        hero.insertBefore(menuBtn, hero.firstChild);
    }

    // ── the counters: the whole mission in one glance (they sit in the phase bar below) ──
    const kpiBox = document.createElement('div');
    kpiBox.className = 'wrc-kpis';
    kpiBox.id = 'wrcKpis';

    // Streams command has already opened the live screen for (this page load only).
    const seenLive = new Set();
    /** The Live card's rows, read from the card itself: the poll keeps it current. */
    function liveStreamRows() {
        return $$('#liveStreamsBody .live-stream-item[data-stream-id]').map(el => ({id: el.dataset.streamId, status: el.dataset.status}));
    }
    function liveKpi() {
        // The card is on the page only where LiveKit is set up: elsewhere there is no button either.
        if (!cardNode('liveStreamsCard')) return [];
        const sig = wrcLiveSignal(liveStreamRows(), Array.from(seenLive));
        const waiting = sig.waiting ? t('console.kpi_live_wait', {n: sig.waiting}) : '';
        const title = sig.onAir
            ? t('console.kpi_live_on', {n: sig.onAir}) + (waiting ? ' · ' + waiting : '')
            : (waiting || t('console.kpi_live_none'));
        return [{k: 'live', label: 'LIVE', n: sig.onAir, tone: 'bad', card: 'liveStreamsCard', live: true, glyph: '<i class="wrc-rec" aria-hidden="true"></i>', flash: sig.flash, waiting: sig.waiting, title}];
    }
    // The chat's new messages as renderBadges() last counted them (it runs just before the counters).
    let lastChat = {total: 0, byRoom: {}};
    function chatKpi() {
        const n = lastChat.total;
        return [{k: 'chat', label: t('console.kpi_chat'), n, tone: 'warn', glyph: '<i class="bi bi-chat-dots-fill wrc-kpi-i" aria-hidden="true"></i>',
            title: n ? t('console.kpi_chat_on', {n}) : t('console.kpi_chat_none')}];
    }
    function kpiData() {
        const a = page.assistant();
        const rows = (a && a.pending) || [];
        const silent = rows.filter(r => r.kind === 'silent').reduce((n, r) => n + (Number(r.count) || 1), 0);
        const waitingEl = document.querySelector('[data-live-fragment="missionVisitors"] [data-visitor-pending]');
        return [
            {k: 'teams', label: t('console.kpi_teams'), n: state.teams.length},
            {k: 'sos', label: 'SOS', n: page.sos().length, tone: 'bad', go: 'queue'},
            ...liveKpi(),
            ...chatKpi(),
            {k: 'ack', label: t('console.kpi_ack'), n: (a && a.counts && a.counts.overdue) || 0, tone: 'warn', go: 'queue'},
            {k: 'silent', label: t('console.kpi_silent'), n: silent, tone: 'warn', go: 'queue'},
            {k: 'incident', label: t('console.kpi_incidents'), n: page.incidents().length, filter: 'incident'},
            {k: 'shortage', label: t('console.kpi_shortages'), n: page.shortages().length, filter: 'shortage'},
            {k: 'visitors', label: t('console.kpi_visitors'), n: waitingEl ? Number(waitingEl.dataset.visitorPending) || 0 : 0, tone: 'warn', card: 'missionVisitorsCard'},
        ];
    }
    let kpiSig = '';
    function renderKpis() {
        const data = kpiData();
        const sig = JSON.stringify(data.map(d => [d.k, d.n, d.flash ? 1 : 0, d.waiting || 0]));
        if (sig === kpiSig) return;
        kpiSig = sig;
        kpiBox.innerHTML = data.map(d => `<button type="button" class="wrc-kpi${d.n && d.tone ? ' wrc-kpi-' + d.tone : ''}${d.live ? ' wrc-kpi-live' + (d.n ? ' on' : '') + (d.flash ? ' flash' : '') : ''}" data-kpi="${esc(d.k)}"${d.go ? ` data-go="${esc(d.go)}"` : ''}${d.filter ? ` data-filter-to="${esc(d.filter)}"` : ''}${d.card ? ` data-card="${esc(d.card)}"` : ''}${d.title ? ` title="${esc(d.title)}" aria-label="${esc(d.label + ': ' + d.title)}"` : ''}>
            <span class="wrc-kpi-l">${d.glyph || ''}${esc(d.label)}</span><b class="num">${d.n}</b></button>`).join('');
    }
    /** The red LIVE button: whatever is on air now counts as looked at, and the live screen comes up. */
    function watchLive() {
        liveStreamRows().filter(r => r.status === 'live').forEach(r => seenLive.add(String(r.id)));
        kpiSig = '';
        renderKpis();
        // A tile attached while its pane was hidden may never have started: a browser that will
        // not play what it cannot see (WebKit) gave up asking long ago, so ask again.
        setTimeout(() => $$('video[data-live-video]').forEach(v => {
            if (!v.srcObject || !v.paused) return;
            const p = v.play();
            if (p && p.catch) p.catch(() => { /* the page's own retry loop reports it */ });
        }), 150);
    }
    /** Where a counter or a checklist row leads: a flash on a region, a feed filter, or a card. */
    function jump(ds) {
        if (ds.go) {
            const region = $(ds.go === 'teams' ? '#wrcTeams' : '#wrcQueue', root);
            region.classList.remove('wrc-flash'); void region.offsetWidth; region.classList.add('wrc-flash');
        } else if (ds.filterTo) {
            state.filter = ds.filterTo; feedSig = ''; $('#wrcFeedChips', root).dataset.sig = '';
            setTab('feed'); renderFeed();
        } else if (ds.card) {
            reveal(ds.card);
            if (typeof scrollToCard === 'function') scrollToCard(ds.card);
        }
    }
    kpiBox.addEventListener('click', e => {
        const b = e.target.closest('.wrc-kpi');
        if (!b) return;
        if (b.dataset.kpi === 'live') watchLive();
        if (b.dataset.kpi === 'chat') openUnreadChat();
        else jump(b.dataset);
    });

    // ── the phases: one console, composed for what the mission is doing ──────
    // Preparation puts the checklist and the plan in front; live is the cockpit;
    // closing lists what is still open. It only changes what is shown (and where
    // the dock opens): nothing about the mission itself, and nothing is removed.
    const PHASE_META = {
        prep: ['bi-clipboard-check', 'console.phase_prep', 'console.phase_prep_hint'],
        live: ['bi-broadcast', 'console.phase_live', 'console.phase_live_hint'],
        close: ['bi-flag-fill', 'console.phase_close', 'console.phase_close_hint'],
    };
    const bar = document.createElement('div');
    bar.className = 'wrc-bar';
    bar.id = 'wrcBar';
    bar.innerHTML = `<div class="wrc-phases" role="tablist" id="wrcPhases" aria-label="${esc(t('console.phase_aria'))}">${WRC_PHASES.map(p =>
        `<button type="button" role="tab" class="wrc-phase" data-phase="${p}"><i class="bi ${PHASE_META[p][0]}"></i><span>${esc(t(PHASE_META[p][1]))}</span>${p === 'close' ? '<span class="wrc-cnt bad d-none" id="wrcCloseCnt"></span>' : ''}</button>`).join('')}</div>
        <span class="wrc-bar-hint" id="wrcPhaseHint"></span>`;
    bar.appendChild(kpiBox);
    root.parentNode.insertBefore(bar, root);

    // ── light / dark ─────────────────────────────────────────────────────────
    // Bootstrap's colour mode on <html>, remembered per browser (not per mission: it
    // is how this coordinator likes to look at a screen). war-room.php applies a saved
    // choice before the first paint, so a dark page never flashes white.
    const THEME_KEY = 'wr-console-theme';
    const themeBtn = document.createElement('button');
    themeBtn.type = 'button';
    themeBtn.className = 'wrc-theme';
    themeBtn.id = 'wrcTheme';
    bar.appendChild(themeBtn);
    const currentTheme = () => document.documentElement.getAttribute('data-bs-theme') === 'dark' ? 'dark' : 'light';
    function paintThemeButton() {
        const dark = currentTheme() === 'dark';
        const label = t(dark ? 'console.theme_to_light' : 'console.theme_to_dark');
        themeBtn.innerHTML = `<i class="bi ${dark ? 'bi-sun-fill' : 'bi-moon-stars-fill'}"></i>`;
        themeBtn.title = label;
        themeBtn.setAttribute('aria-label', label);
        themeBtn.setAttribute('aria-pressed', dark ? 'true' : 'false');
    }
    themeBtn.addEventListener('click', () => {
        const next = currentTheme() === 'dark' ? 'light' : 'dark';
        if (next === 'dark') document.documentElement.setAttribute('data-bs-theme', 'dark');
        else document.documentElement.removeAttribute('data-bs-theme');
        try { localStorage.setItem(THEME_KEY, next); } catch (e) { /* blocked storage: it starts light next time */ }
        paintThemeButton();
    });
    paintThemeButton();

    const CHECK_TEXT = {
        teams: ['console.chk_teams_ok', 'console.chk_teams_bad'],
        unteamed: ['console.chk_unteamed_ok', 'console.chk_unteamed_warn'],
        teamgps: ['console.chk_teamgps_ok', 'console.chk_teamgps_bad'],
        gps: ['console.chk_gps_ok', 'console.chk_gps_warn'],
        sectors: ['console.chk_sectors_ok', 'console.chk_sectors_warn'],
        assigned: ['console.chk_assigned_ok', 'console.chk_assigned_warn'],
        contact: ['console.chk_contact_ok', 'console.chk_contact_warn'],
    };
    const CLOSE_TEXT = {
        sos: 'console.chk_close_sos', orders: 'console.chk_close_orders', sectors: 'console.chk_close_sectors',
        incidents: 'console.chk_close_incidents', shortages: 'console.chk_close_shortages', overdue: 'console.chk_close_overdue',
    };
    const CLOSE_GO = {
        sos: {go: 'queue'}, orders: {go: 'teams'}, sectors: {card: 'sectorsListCard'},
        incidents: {filterTo: 'incident'}, shortages: {filterTo: 'shortage'}, overdue: {go: 'queue'},
    };
    const CHECK_ICON = {ok: 'bi-check-circle-fill', warn: 'bi-exclamation-triangle-fill', bad: 'bi-x-circle-fill'};

    function readinessFacts() {
        const members = state.teams.flatMap(tm => tm.members || []);
        const secs = page.sectors();
        // Contact is judged among the people whose GPS is on, never among everybody.
        const contact = wrcContactOf(members, isStale);
        // Who is on the roster comes from the page (it reloads itself when the roster
        // changes); who is in a team and on GPS comes from the poll, so it stays live.
        const gaps = wrcTeamGaps(state.teams, (typeof ROSTER_PERSON_IDS !== 'undefined' && Array.isArray(ROSTER_PERSON_IDS)) ? ROSTER_PERSON_IDS : []);
        return Object.assign({}, gaps, {
            teams: state.teams.length, people: members.length,
            noGps: members.filter(m => !m.takes_part).length,
            gpsPeople: contact.total,
            sectors: secs.length, sectorsFree: secs.filter(sec => !sec.team_id).length,
            stale: contact.silent,
        });
    }
    function closingFacts() {
        const board = (typeof teamBoardItems === 'function') ? teamBoardItems : () => [];
        const a = page.assistant();
        // One order to every team is one order, not one per team; and a sector is
        // an order too, but it is counted once, as a sector.
        const orders = new Set();
        state.teams.forEach(tm => board({id: tm.id, label: tm.label, color: tm.color}).forEach(i => { if (i.kind !== 'sector') orders.add(i.ref); }));
        return {
            sos: page.sos().length,
            incidents: page.incidents().filter(r => !r.resolved_at).length,
            shortages: page.shortages().filter(r => !r.resolved_at).length,
            orders: orders.size,
            sectorsOpen: page.sectors().filter(sec => ['assigned', 'en_route', 'in_progress', 'needs_recheck'].includes(sec.status)).length,
            overdue: (a && a.counts && a.counts.overdue) || 0,
        };
    }

    function checkRow(tone, text, jumpTo) {
        const attrs = jumpTo.go ? ` data-go="${esc(jumpTo.go)}"` : jumpTo.filterTo ? ` data-filter-to="${esc(jumpTo.filterTo)}"` : jumpTo.card ? ` data-card="${esc(jumpTo.card)}"` : '';
        return `<button type="button" class="wrc-check wrc-check-${tone}"${attrs}><i class="bi ${CHECK_ICON[tone]}"></i><span>${esc(text)}</span></button>`;
    }

    let phaseSig = '';
    function renderPhase() {
        const closing = wrcClosingItems(closingFacts());
        const closeCnt = $('#wrcCloseCnt', bar);
        closeCnt.textContent = String(closing.length);
        closeCnt.classList.toggle('d-none', closing.length === 0);
        if (state.phase === 'live') { phaseSig = ''; return; }

        const panel = $('#wrcPhasePanel', root);
        let sig, html;
        if (state.phase === 'prep') {
            const r = wrcReadiness(readinessFacts());
            sig = JSON.stringify(['prep', r.rows.map(x => [x.key, x.tone, x.n, x.of, x.names || ''])]);
            if (sig === phaseSig) return;
            html = `<header class="wrc-sec-h"><i class="bi bi-clipboard-check"></i><span class="wrc-h">${esc(t('console.panel_prep'))}</span>
                    <span class="wrc-cnt ${r.ready ? 'ok' : 'bad'}">${r.rows.length - r.open}/${r.rows.length}</span></header>
                <div class="wrc-checks">${r.rows.map(x => checkRow(x.tone, t(CHECK_TEXT[x.key][x.tone === 'ok' ? 0 : 1], {n: x.n, of: x.of, names: x.names || ''}), {card: x.card})).join('')}</div>
                <div class="wrc-phase-cta"><button type="button" class="wrc-go" data-phase-go="live"><i class="bi bi-broadcast"></i>${esc(t('console.cta_live'))}</button></div>`;
        } else {
            sig = JSON.stringify(['close', closing.map(x => [x.key, x.n]), !!cardNode('endMissionCard')]);
            if (sig === phaseSig) return;
            html = `<header class="wrc-sec-h"><i class="bi bi-flag-fill"></i><span class="wrc-h">${esc(t('console.panel_close'))}</span>
                    <span class="wrc-cnt ${closing.length ? 'bad' : 'ok'}">${closing.length}</span></header>
                <div class="wrc-checks">${closing.length
                    ? closing.map(x => checkRow(x.tone, t(CLOSE_TEXT[x.key], {n: x.n}), CLOSE_GO[x.key])).join('')
                    : `<div class="wrc-calm"><i class="bi bi-check2-circle"></i>${esc(t('console.close_clear'))}</div>`}</div>
                ${cardNode('endMissionCard') ? `<div class="wrc-phase-cta"><button type="button" class="wrc-go" data-phase-go="end"><i class="bi bi-flag-fill"></i>${esc(t('console.cta_close'))}</button></div>` : ''}`;
        }
        phaseSig = sig;
        panel.innerHTML = html;
    }
    $('#wrcPhasePanel', root).addEventListener('click', e => {
        const go = e.target.closest('[data-phase-go]');
        if (go) {
            if (go.dataset.phaseGo === 'live') setPhase('live', true);
            else if (cardNode('endMissionCard')) { reveal('endMissionCard'); if (typeof scrollToCard === 'function') scrollToCard('endMissionCard'); }
            return;
        }
        const row = e.target.closest('.wrc-check');
        if (row) jump(row.dataset);
    });

    /** Opens a plan section; `remember` = false for a jump to a card, which should not change what a later visit starts with. */
    function openSection(key, remember) {
        const sec = $('.wrc-acc[data-sec="' + key + '"]', root);
        if (!sec || sec.classList.contains('open')) return;
        sec.classList.add('open');
        if (remember === false) return;
        state.sections.add(key);
        save('sections2', Array.from(state.sections).join(','));
    }
    /**
     * Switching phase by hand also sets the dock the way the phase wants it - the
     * tab last used in that phase, or its default - and opens the plan sections it
     * works with. Opening the page in a phase restores what was left, untouched.
     */
    function setPhase(phase, byHand) {
        phase = wrcNormalizePhase(phase);
        const changed = phase !== state.phase;
        state.phase = phase;
        save('phase', phase);
        root.dataset.phase = phase;
        $$('#wrcPhases .wrc-phase', bar).forEach(b => b.setAttribute('aria-selected', b.dataset.phase === phase ? 'true' : 'false'));
        $('#wrcPhaseHint', bar).textContent = t(PHASE_META[phase][2]);
        if (changed && byHand) {
            const preset = wrcPhasePreset(phase);
            setTab(load('tab-' + phase, preset.tab));
            preset.sections.forEach(key => openSection(key));
        }
        phaseSig = '';
        renderPhase();
        announce();
        // The order form comes and goes with the phase: the map takes the room.
        if (typeof map !== 'undefined' && map) setTimeout(() => map.invalidateSize(), 60);
    }
    bar.addEventListener('click', e => {
        const b = e.target.closest('.wrc-phase');
        if (b) setPhase(b.dataset.phase, true);
    });

    // ── fitting the screen ───────────────────────────────────────────────────
    // The ticker floats over the site's navbar (which is 78px tall), so a ticker
    // of one row needs no room of its own and a taller one needs only what pokes
    // out below the navbar. The page's own spacing pads the whole banner height,
    // which on a page that scrolls is harmless and on this one is 60px of nothing
    // - so the console takes over the top padding, and still never lets the
    // ticker cover it.
    function consolePad() {
        const cw = $('.content-wrapper');
        if (!cw || (typeof wrTickerPos !== 'undefined' && wrTickerPos !== 'top')) return;
        const banner = document.getElementById('warRoomBanner');
        const fullscreen = !!document.getElementById('mapCard')?.classList.contains('map-fullscreen-active');
        const bannerH = (banner && banner.style.display === 'flex' && !fullscreen) ? banner.offsetHeight : 0;
        const navH = ($('.top-navbar') || {}).offsetHeight || 0;
        cw.style.paddingTop = Math.max(8, bannerH - navH + 8) + 'px';
    }
    if (typeof syncTickerSpacing === 'function') {
        const originalSync = window.syncTickerSpacing;
        window.syncTickerSpacing = function () {
            const result = originalSync.apply(this, arguments);
            consolePad();
            fit();
            return result;
        };
    }
    function fit() {
        consolePad();
        // The acknowledgement panel floats at the top right, where it covered the
        // hero's counters: it starts under the hero instead.
        const above = bar || hero;
        if (above) document.documentElement.style.setProperty('--wr-acktracker-top', Math.round(above.getBoundingClientRect().bottom + 8) + 'px');
        const top = root.getBoundingClientRect().top + window.scrollY;
        const h = Math.max(520, window.innerHeight - (top - window.scrollY) - 10);
        root.style.height = h + 'px';
        if (typeof map !== 'undefined' && map) map.invalidateSize();
    }
    window.addEventListener('resize', fit);
    // «Πλήρης οθόνη» (the hero's focus mode) takes the site's top bar away by CSS,
    // which moves the top of the console. Nothing tells this script, so it watches
    // the class; and the native fullscreen resizes the window a moment later.
    if (window.MutationObserver) {
        let focusWas = document.body.classList.contains('war-room-focus');
        new MutationObserver(() => {
            const focusNow = document.body.classList.contains('war-room-focus');
            if (focusNow === focusWas) return;
            focusWas = focusNow;
            fit();
            setTimeout(fit, 250);
        }).observe(document.body, {attributes: true, attributeFilter: ['class']});
    }
    if (window.ResizeObserver) {
        const mapSlot = $('#wrcMapSlot', root);
        new ResizeObserver(() => { if (typeof map !== 'undefined' && map) map.invalidateSize(); }).observe(mapSlot);
    }

    // ── tabs and plan sections ───────────────────────────────────────────────
    function setTab(tab) {
        if (!$('.wrc-pane[data-pane="' + tab + '"]', root)) tab = 'feed';
        state.tab = tab;
        save('tab-' + state.phase, tab);
        $$('#wrcTabs button', root).forEach(b => b.setAttribute('aria-selected', b.dataset.tab === tab ? 'true' : 'false'));
        $$('.wrc-pane', root).forEach(p => p.classList.toggle('on', p.dataset.pane === tab));
        if (tab === 'chat') {
            // The chat card keeps its scroll position by itself; it only needs
            // to know it can be seen now.
            document.dispatchEvent(new CustomEvent('wr-console-tab', {detail: {tab}}));
        }
        // Going to a tab is looking at what is in it: its counter clears.
        renderBadges();
    }
    $('#wrcTabs', root).addEventListener('click', e => {
        const b = e.target.closest('button[data-tab]');
        if (b) setTab(b.dataset.tab);
    });
    planEl.addEventListener('click', e => {
        const b = e.target.closest('[data-sec-toggle]');
        if (!b) return;
        const sec = b.closest('.wrc-acc');
        sec.classList.toggle('open');
        if (sec.classList.contains('open')) state.sections.add(sec.dataset.sec); else state.sections.delete(sec.dataset.sec);
        save('sections2', Array.from(state.sections).join(','));
    });

    // ── the plan: every card starts folded ───────────────────────────────────
    // The cards under Σχέδιο are the page's own, each with a header that folds it. They
    // all start folded here - the coordinator opens the ones needed - and a card stays
    // open on later visits only if it was the coordinator who opened it. That choice is
    // kept in this browser under a key of the console's own: the page's own record of
    // folded cards (shared with the classic view) is deliberately not written by the
    // default, only by the clicks. The fold is done on the DOM, not through Bootstrap's
    // Collapse, so the default is not recorded as a choice.
    const OPEN_CARDS_KEY = 'wr-console-plan-open-cards';
    const openCards = (() => {
        try { const list = JSON.parse(localStorage.getItem(OPEN_CARDS_KEY) || '[]'); return new Set(Array.isArray(list) ? list.map(String) : []); } catch (e) { return new Set(); }
    })();
    function saveOpenCards() { try { localStorage.setItem(OPEN_CARDS_KEY, JSON.stringify(Array.from(openCards))); } catch (e) { /* blocked storage: they start folded next time */ } }
    /** The header that folds a card and the body it folds, or null for a card that does not fold. */
    function cardFold(card) {
        const header = card.querySelector('.wr-collapsible-header[aria-controls]');
        const target = header && document.getElementById(header.getAttribute('aria-controls'));
        return target ? {header, target} : null;
    }
    function setCardOpen(card, open) {
        const fold = cardFold(card);
        if (!fold) return;
        fold.target.classList.toggle('show', open);
        fold.header.classList.toggle('collapsed', !open);
        fold.header.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
    $$('.wrc-acc-b .card[data-card-id]', root).forEach(card => setCardOpen(card, openCards.has(card.dataset.cardId)));
    // What the coordinator opens or folds by hand (not a card's inner collapses).
    function noteCardToggle(e, open) {
        const card = e.target.closest('.card[data-card-id]');
        const fold = card && cardFold(card);
        if (!fold || fold.target !== e.target || !card.closest('.wrc-acc')) return;
        if (open) openCards.add(card.dataset.cardId); else openCards.delete(card.dataset.cardId);
        saveOpenCards();
    }
    planEl.addEventListener('shown.bs.collapse', e => noteCardToggle(e, true));
    planEl.addEventListener('hidden.bs.collapse', e => noteCardToggle(e, false));

    /** Show whatever holds this card, then let the caller scroll to it. */
    function reveal(cardId) {
        const el = cardNode(cardId);
        if (!el || !root.contains(el)) return;
        const pane = el.closest('.wrc-pane');
        if (pane && pane.dataset.pane !== state.tab) setTab(pane.dataset.pane);
        const sec = el.closest('.wrc-acc');
        if (sec) {
            // A jump is for now: neither the section nor the card is remembered as opened.
            openSection(sec.dataset.sec, false);
            // Someone sent here to see this card is not helped by its folded header.
            setCardOpen(el, true);
        }
    }

    // ── the queue: what needs the coordinator ────────────────────────────────
    function agoText(ts) {
        return (typeof assistantAgo === 'function') ? assistantAgo(ts) : '';
    }

    function queueItems() {
        const items = [];
        // SOS and voice come from their own lists, not from the assistant's rows:
        // those rows carry no id, and a row you cannot act on is only a headline.
        page.sos().forEach(a => items.push({
            k: 'sos', sev: a.acknowledged_at ? 'high' : 'critical', ts: Number.MAX_SAFE_INTEGER - (Number(a.id) || 0),
            icon: 'bi-exclamation-octagon-fill', id: a.id, lat: a.lat, lng: a.lng,
            title: '🆘 ' + (a.team_label || '') + ' — ' + esc(a.user_name),
            meta: esc(a.created_at || '') + (a.acknowledged_at ? ' · ' + esc(t('sos.ack_at_prefix', {time: a.acknowledged_at}).replace(/^[\s·,-]+/, '')) : ''),
            actions: [
                a.acknowledged_at
                    ? {cls: 'wrc-btn-ok', act: 'sos-resolve', label: t('shortage.resolve_btn')}
                    : {cls: 'wrc-btn-warn', act: 'sos-ack', label: t('banner.ack_btn')},
            ].concat(a.lat !== null && a.lat !== undefined
                ? [{cls: '', act: 'locate', label: t('sos.view_on_map')}, {cls: '', act: 'nearest', label: t('console.nearest')}] : []),
        }));
        page.voice().forEach(v => items.push({
            k: 'voice', sev: 'high', ts: Number.MAX_SAFE_INTEGER / 2 - (Number(v.id) || 0),
            icon: 'bi-mic-fill', id: v.id, lat: v.lat, lng: v.lng,
            title: '🔊 ' + (v.team_label || '') + ' — ' + esc(v.user_name),
            meta: esc(v.created_at || '') + (v.duration_s ? ' · ' + esc(t('voice.duration_s', {n: v.duration_s})) : ''),
            audio: 'mission-voice-play.php?id=' + encodeURIComponent(v.id),
            actions: [{cls: 'wrc-btn-warn', act: 'voice-ack', label: t('voice.ack_btn')}],
        }));
        const a = page.assistant();
        // Findings of one kind that pile up are one line, not one per finding: a
        // mission with twenty unanswered orders, or six people out of GPS reach,
        // is one problem to chase. A single one keeps its own line. The
        // acknowledgement panel and the roster list the individual ones.
        const rows = (a && a.pending) || [];
        const grouped = new Set();
        WRC_GROUPS.forEach(g => {
            const hits = rows.filter(row => g.kinds.includes(row.kind));
            const n = hits.reduce((sum, row) => sum + (Number(row.count) || 1), 0);
            if (n < g.min) return;
            hits.forEach(row => grouped.add(row));
            const oldest = Math.min(...hits.map(row => Number(row.ts) || Infinity));
            items.push({
                k: g.key, sev: hits.some(row => row.sev === 'high' || row.sev === 'critical') ? 'high' : 'warn',
                ts: oldest, icon: g.icon, target: g.target,
                title: esc(t(g.label, {n})),
                meta: '<span class="wrc-ago" data-ts="' + oldest + '">' + esc(agoText(oldest)) + '</span>',
                actions: [],
            });
        });
        rows.forEach(row => {
            // Already shown above, with buttons; or folded into a group line.
            if (row.kind === 'sos' || row.kind === 'voice' || grouped.has(row)) return;
            items.push({
                k: row.kind, sev: row.sev, ts: Number(row.ts) || 0, icon: row.icon, target: row.target,
                title: esc(row.title) + (row.count > 1 ? ` <span class="wrc-x">×${row.count}</span>` : ''),
                meta: (row.detail ? esc(row.detail) + ' · ' : '') + '<span class="wrc-ago" data-ts="' + Number(row.ts) + '">' + esc(agoText(Number(row.ts))) + '</span>',
                actions: [],
            });
        });
        return wrcOrderQueue(items);
    }

    /**
     * The quiet line under the queue: orders sent a moment ago that nobody has answered.
     * The assistant waits out a grace period before it raises one, so for those first
     * minutes the queue would say «all under control» while «0 από 48» have received
     * anything. The line is not a queue item (it does not count, and asks nothing of
     * the coordinator); it just tells the truth, and is replaced by the real row at
     * the end of the grace.
     */
    function waitingReceipts() {
        const a = page.assistant();
        const graceMin = (a && Number(a.grace_minutes)) || 10;
        const skip = (typeof ackDismissed !== 'undefined' && ackDismissed instanceof Set) ? ackDismissed : null;
        return {w: wrcWaitingReceipts(state.ack, Math.floor(Date.now() / 1000), graceMin * 60, skip), graceMin};
    }

    const QUEUE_SHOWN = 5;
    let queueSig = '';
    function renderQueue() {
        const items = queueItems();
        const {w: waiting, graceMin} = waitingReceipts();
        const waitHtml = waiting.orders
            ? `<div class="wrc-wait" title="${esc(t('console.waiting_hint', {min: graceMin}))}"><i class="bi bi-hourglass-split"></i><span>${esc(t('console.waiting_receipts', {n: waiting.orders, acked: waiting.acked, total: waiting.total}))}</span></div>`
            : '';
        // The phases that are not live drop an empty queue altogether.
        root.dataset.q = String(items.length);
        const body = $('#wrcQueueBody', root);
        const sosOpen = items.filter(i => i.sev === 'critical').length;
        const cnt = $('#wrcQueueCnt', root);
        cnt.textContent = String(items.length);
        cnt.classList.toggle('bad', sosOpen > 0);
        cnt.classList.toggle('ok', items.length === 0);
        $('#wrcQueue', root).classList.toggle('wrc-hot', sosOpen > 0);
        const shown = state.queueMore ? items : items.slice(0, QUEUE_SHOWN);
        const sig = JSON.stringify([shown.map(i => [i.k, i.id, i.title, i.meta, i.sev]), items.length, state.queueMore, waiting]);
        if (sig === queueSig) return;
        queueSig = sig;
        if (!items.length) {
            body.innerHTML = `<div class="wrc-calm"><i class="bi bi-check2-circle"></i>${esc(t('console.queue_empty'))}</div>` + waitHtml;
            return;
        }
        body.innerHTML = shown.map((i, n) => `
            <div class="wrc-q wrc-q-${esc(i.sev)}" data-q="${n}" data-k="${esc(i.k)}"${i.target ? ' data-target="' + esc(i.target) + '"' : ''}>
                <i class="bi ${esc(i.icon || 'bi-dot')} wrc-q-i"></i>
                <div class="wrc-q-main">
                    <div class="wrc-q-t">${i.title}</div>
                    <div class="wrc-q-m">${i.meta}</div>
                    ${i.audio ? `<audio class="wrc-audio" controls preload="none" src="${esc(i.audio)}"></audio>` : ''}
                    ${i.actions.length ? `<div class="wrc-q-a">${i.actions.map(b => `<button type="button" class="wrc-btn ${esc(b.cls)}" data-act="${esc(b.act)}" data-id="${esc(i.id)}">${esc(b.label)}</button>`).join('')}</div>` : ''}
                </div>
            </div>`).join('')
            + (items.length > QUEUE_SHOWN
                ? `<button type="button" class="wrc-link wrc-more" id="wrcQueueMore">${esc(state.queueMore ? t('console.show_less') : t('console.show_more', {n: items.length - QUEUE_SHOWN}))}</button>`
                : '')
            + waitHtml;
        body._items = shown;
    }

    $('#wrcQueueBody', root).addEventListener('click', e => {
        if (e.target.closest('#wrcQueueMore')) { state.queueMore = !state.queueMore; queueSig = ''; renderQueue(); return; }
        const btn = e.target.closest('button[data-act]');
        const row = e.target.closest('.wrc-q');
        if (btn) {
            const id = btn.dataset.id;
            // The cards' own buttons do the work (and the render that follows):
            // one code path for an acknowledgement, whichever screen it came from.
            if (btn.dataset.act === 'sos-ack') $('.sos-ack-btn[data-alert-id="' + id + '"]')?.click();
            else if (btn.dataset.act === 'sos-resolve') $('.sos-resolve-btn[data-alert-id="' + id + '"]')?.click();
            else if (btn.dataset.act === 'voice-ack') $('.voice-ack-btn[data-id="' + id + '"]')?.click();
            else if (btn.dataset.act === 'locate' || btn.dataset.act === 'nearest') {
                const item = (row && $('#wrcQueueBody', root)._items || [])[Number(row.dataset.q)];
                if (item && typeof map !== 'undefined' && map) {
                    const point = {lat: Number(item.lat), lng: Number(item.lng)};
                    map.setView([point.lat, point.lng], 17);
                    // The map's own «πιο κοντά» answer: which team is nearest, and how far.
                    if (btn.dataset.act === 'nearest' && typeof measureNearest === 'function') measureNearest(point);
                }
            }
            return;
        }
        if (e.target.closest('audio')) return;
        if (row && row.dataset.target && typeof assistantGoto === 'function') assistantGoto(row.dataset.target);
    });

    setInterval(() => {
        if (document.hidden) return;
        $$('.wrc-ago', root).forEach(el => { el.textContent = agoText(Number(el.dataset.ts)); });
    }, 30000);

    // ── the teams: what each is doing, and who an order goes to ──────────────
    function labelOf(team) { return teamLabel(team.codename, team.team_number); }

    function setTeams(list) {
        state.teams = (list || []).map(tm => Object.assign({}, tm, {id: Number(tm.id), label: labelOf(tm)}))
            .sort((a, b) => ((Number(a.team_number) || 0) - (Number(b.team_number) || 0)) || a.label.localeCompare(b.label, jsLocale));
        // A person who left the roster cannot stay picked.
        const known = new Set(state.teams.flatMap(tm => (tm.members || []).map(m => Number(m.user_id))));
        state.selected.forEach(id => { if (!known.has(id)) state.selected.delete(id); });
    }

    function isStale(uid) {
        if (state.stale && Object.prototype.hasOwnProperty.call(state.stale, String(uid))) return !!state.stale[String(uid)];
        const dom = document.getElementById('ping-stale-' + uid);
        return !!dom && !dom.classList.contains('d-none');
    }

    const MEMBER_STATUS = {needs_help: ['bad', 'status.badge_needs_help'], on_site: ['ok', 'status.badge_on_site'], on_way: ['warn', 'status.badge_on_way']};

    function memberHtml(m) {
        const uid = Number(m.user_id);
        const live = state.live[String(uid)] || {};
        const st = MEMBER_STATUS[live.field_status];
        const stale = isStale(uid);
        // The barometer floor (v3.426.0), on its own line under the name: beside the status
        // tag it left the name 60px wide in this narrow column.
        const floorChip = typeof floorChipHtml === 'function' ? floorChipHtml(live.floor, t, esc) : '';
        return `<label class="wrc-m${stale ? ' stale' : ''}">
            <input type="checkbox" data-uid="${uid}"${state.selected.has(uid) ? ' checked' : ''}>
            <span class="wrc-m-n">${guestNameHtml(m.name, m.is_external, m.home_team_name, teamBadgeColorsJs(m.home_team_color)[0], teamBadgeColorsJs(m.home_team_color)[1], m.guest_country_code)}${k9BadgeHtml(uid, true)}${captainBadgeHtml(uid, true)}</span>
            ${st ? `<span class="wrc-tag wrc-tag-${st[0]}">${esc(t(st[1]))}</span>` : ''}
            ${stale ? `<i class="bi bi-wifi-off wrc-stale" title="${esc(t('console.no_contact'))}"></i>` : ''}
            ${m.takes_part ? '' : `<i class="bi bi-geo-alt-slash wrc-mut" title="${esc(t('gps_participant.self_note'))}"></i>`}
            ${floorChip ? `<span class="wrc-m-br"></span>${floorChip}` : ''}
        </label>`;
    }

    /** What the page knows of one order, in the shape wrcOrderProgress takes. */
    function orderDescriptor(item, team) {
        const [type, rawId] = String(item.ref).split(':');
        const id = Number(rawId);
        if (type === 'dispatch') {
            const d = page.dispatches().find(x => Number(x.id) === id);
            const prog = d && (d.progress || []).find(p => p.label === team.label);
            return {type, departed: !!(prog && prog.departed), arrived: !!(prog && prog.arrived)};
        }
        if (type === 'sector') {
            const sec = page.sectors().find(x => Number(x.id) === id);
            return {type, status: sec ? sec.status : '', acked: !!(sec && sec.acknowledged_at)};
        }
        if (type === 'route') {
            const r = page.routes().find(x => Number(x.id) === id);
            const wps = (r && r.waypoints) || [];
            return {type, total: wps.length, done: wps.filter(w => w.completed_at || w.skipped_at).length};
        }
        return null;
    }

    /**
     * The «Δεν μπορώ» standing behind one of the team's orders, if there is one:
     * {reason, note, by, at}. Looked up where the team board looks (a dispatch's
     * declines are kept per team by its label; a sector's and a route's sit on it).
     */
    function declineOf(item, team) {
        const [type, rawId] = String(item.ref).split(':');
        const id = Number(rawId);
        if (type === 'dispatch') {
            const d = page.dispatches().find(x => Number(x.id) === id);
            return (d && (d.declines || []).find(x => x.team === team.label)) || null;
        }
        if (type === 'sector') {
            const sec = page.sectors().find(x => Number(x.id) === id);
            return (sec && sec.declined) || null;
        }
        if (type === 'route') {
            const r = page.routes().find(x => Number(x.id) === id);
            return (r && r.declined) || null;
        }
        return null;
    }

    /** The reason the team handed an order back, for the line under its order - or null. */
    function declineModel(team, items) {
        const hit = items.map(item => ({item, d: declineOf(item, team)})).find(x => x.d);
        if (!hit) return null;
        const reason = t('decline.reason.' + hit.d.reason);
        return {
            // The row shows the first order; if it is another one that was handed back, say which.
            order: hit.item === items[0] ? '' : (hit.item.detail || t('teamboard.kind.' + hit.item.kind)),
            line: wrcDeclineLine(hit.d, reason),
            who: [hit.d.by, hit.d.at].filter(Boolean).join(' · '),
        };
    }

    /** The ring, the contact dot and the badge of one team, from what is already on the page. */
    function avatarModel(team, items, members) {
        const ids = new Set(members.map(m => Number(m.user_id)));
        // Only the members whose GPS is on count, in the total as well as among the quiet:
        // a team of eight with one phone on GPS is one person to reach, not eight.
        const contact = wrcContactOf(members, isStale);
        const first = items[0];
        return {
            ring: first ? {f: Math.round(wrcOrderProgress(orderDescriptor(first, team)) * 100) / 100, tone: first.tone} : null,
            contact: {tone: wrcContactTone(contact.total, contact.silent), stale: contact.silent, total: contact.total},
            badge: wrcTeamBadge({
                sos: page.sos().filter(a => ids.has(Number(a.user_id))).length,
                needsHelp: members.filter(m => (state.live[String(m.user_id)] || {}).field_status === 'needs_help').length,
                blocked: items.filter(i => i.tone === 'bad').length,
                orders: items.length,
            }),
        };
    }

    const RING_R = 16;
    const RING_C = 2 * Math.PI * RING_R;
    const BADGE_TITLES = {sos: 'console.badge_sos', help: 'console.badge_help', blocked: 'console.badge_blocked'};
    function avatarHtml(team, vm) {
        const [bg, fg] = teamBadgeColorsJs(team.color);
        const mark = Number(team.team_number) > 0 ? String(Number(team.team_number)) : String(team.label || '?').trim().charAt(0).toUpperCase();
        const arc = vm.ring ? vm.ring.f * RING_C : 0;
        const contactTitle = vm.contact.tone === 'bad' ? t('console.contact_bad')
            : vm.contact.tone === 'warn' ? t('console.contact_warn', {n: vm.contact.stale, total: vm.contact.total})
            : vm.contact.tone === 'ok' ? t('console.contact_ok') : '';
        const badgeTitle = vm.badge ? (vm.badge.kind === 'more' ? t('console.badge_more', {n: vm.badge.text.slice(1)}) : t(BADGE_TITLES[vm.badge.kind])) : '';
        return `<label class="wrc-av" title="${esc(t('console.select_team', {team: team.label}))}">
            <input type="checkbox" class="wrc-team-sel" data-team-sel="${team.id}" aria-label="${esc(t('console.select_team', {team: team.label}))}">
            <svg class="wrc-av-ring" viewBox="0 0 38 38" aria-hidden="true">
                <circle class="wrc-av-track${vm.ring ? '' : ' idle'}" cx="19" cy="19" r="${RING_R}"/>
                ${vm.ring ? `<circle class="wrc-av-arc wrc-ring-${esc(vm.ring.tone)}" cx="19" cy="19" r="${RING_R}" stroke-dasharray="${arc.toFixed(1)} ${RING_C.toFixed(1)}" transform="rotate(-90 19 19)"/>` : ''}
            </svg>
            <span class="wrc-av-in" style="background:${esc(bg)};color:${esc(fg)}">${esc(mark)}</span>
            ${vm.contact.tone !== 'none' ? `<span class="wrc-av-dot wrc-dot-${vm.contact.tone}" title="${esc(contactTitle)}"></span>` : ''}
            ${vm.badge ? `<span class="wrc-av-badge wrc-badge-${esc(vm.badge.tone)}" title="${esc(badgeTitle)}">${esc(vm.badge.text)}</span>` : ''}
        </label>`;
    }

    let teamsSig = '';
    function renderTeams() {
        const body = $('#wrcTeamsBody', root);
        const board = (typeof teamBoardItems === 'function') ? teamBoardItems : () => [];
        const model = state.teams.map(team => {
            const items = board({id: team.id, label: team.label, color: team.color});
            const members = team.members || [];
            return {team, items, members, vm: avatarModel(team, items, members), decl: declineModel(team, items)};
        });
        $('#wrcTeamsCnt', root).textContent = String(model.length);
        const sig = JSON.stringify([model.map(m => [m.team.id, m.team.label, m.team.color, m.team.transport, m.vm, m.decl, m.items.map(i => [i.kind, i.detail, i.state, i.tone]),
            m.members.map(x => [x.user_id, x.name, isStale(x.user_id), (state.live[String(x.user_id)] || {}).field_status, (state.live[String(x.user_id)] || {}).floor || null])]),
            Array.from(state.open), Array.from(state.ordersOpen)]);
        if (sig === teamsSig) return;
        teamsSig = sig;
        if (!model.length) { body.innerHTML = `<div class="wrc-empty">${esc(t('teams.empty'))}</div>`; return; }
        body.innerHTML = model.map(({team, items, members, vm, decl}) => {
            const stale = wrcContactOf(members, isStale).silent;
            const open = state.open.has(team.id);
            const first = items[0];
            return `<div class="wrc-team${open ? ' open' : ''}" data-team="${team.id}">
                <div class="wrc-team-h">
                    ${avatarHtml(team, vm)}
                    <button type="button" class="wrc-team-name" data-team-open="${team.id}">
                        <b>${esc(team.label)}</b>
                        <span class="wrc-mut">${members.length}${typeof transportGlyphSvg === 'function' ? ' ' + transportGlyphSvg(team.transport, 14, 'currentColor').replace('style="display:block"', 'aria-hidden="true" style="vertical-align:-0.15em"') : ''}</span>
                        ${stale ? `<span class="wrc-tag wrc-tag-bad"><i class="bi bi-wifi-off"></i> ${stale}</span>` : ''}
                        <i class="bi bi-chevron-down wrc-chev"></i>
                    </button>
                </div>
                ${first
                    ? (state.ordersOpen.has(team.id) ? items : [first]).map(it => `<button type="button" class="wrc-order wrc-tone-${esc(it.tone)}" data-tb-ref="${esc(it.ref)}">
                            <span>${esc(t('teamboard.kind.' + it.kind))}${it.detail ? ' «' + esc(it.detail) + '»' : ''}</span>
                            <span class="wrc-order-s">${esc(it.state)}</span></button>`).join('')
                       + (decl ? `<div class="wrc-decline" title="${esc(decl.who)}"><i class="bi bi-x-octagon"></i><span>${decl.order ? '«' + esc(decl.order) + '» · ' : ''}${esc(decl.line)}</span></div>` : '')
                       + (items.length > 1 ? `<button type="button" class="wrc-link wrc-order-more" data-team-orders="${team.id}">${esc(state.ordersOpen.has(team.id) ? t('console.show_less') : t('teamboard.more', {n: items.length - 1}))}</button>` : '')
                    : `<div class="wrc-idle"><i class="bi bi-hourglass-split"></i> ${esc(t('teamboard.waiting'))}</div>`}
                ${open ? `<div class="wrc-members">${members.map(memberHtml).join('') || `<div class="wrc-empty">${esc(t('route.team_has_no_members'))}</div>`}</div>` : ''}
            </div>`;
        }).join('');
        syncSelection();
    }

    /**
     * The ticks follow state.selected in place, without redrawing the list: a
     * redraw on every click would drop keyboard focus and the scroll position.
     * (A checkbox cannot be half-ticked from markup either.)
     */
    function syncSelection() {
        $$('.wrc-team', root).forEach(el => {
            const team = state.teams.find(tm => tm.id === Number(el.dataset.team));
            const ids = team ? (team.members || []).map(m => Number(m.user_id)) : [];
            const picked = ids.filter(id => state.selected.has(id)).length;
            el.classList.toggle('picked', picked > 0);
            const box = $('.wrc-team-sel', el);
            if (box) { box.checked = picked > 0 && picked === ids.length; box.indeterminate = picked > 0 && picked < ids.length; }
            const av = $('.wrc-av', el);
            if (av) { av.classList.toggle('sel', picked > 0 && picked === ids.length); av.classList.toggle('part', picked > 0 && picked < ids.length); }
            $$('[data-uid]', el).forEach(b => { b.checked = state.selected.has(Number(b.dataset.uid)); });
        });
    }

    $('#wrcTeamsBody', root).addEventListener('click', e => {
        const order = e.target.closest('[data-tb-ref]');
        if (order && typeof teamBoardTarget === 'function' && typeof opGotoMap === 'function') {
            const target = teamBoardTarget(order.dataset.tbRef);
            if (target) opGotoMap(target);
            return;
        }
        const moreBtn = e.target.closest('[data-team-orders]');
        if (moreBtn) {
            const id = Number(moreBtn.dataset.teamOrders);
            if (state.ordersOpen.has(id)) state.ordersOpen.delete(id); else state.ordersOpen.add(id);
            teamsSig = '';
            renderTeams();
            return;
        }
        const openBtn = e.target.closest('[data-team-open]');
        if (openBtn) {
            const id = Number(openBtn.dataset.teamOpen);
            if (state.open.has(id)) state.open.delete(id); else state.open.add(id);
            teamsSig = '';
            renderTeams();
        }
    });
    $('#wrcTeamsBody', root).addEventListener('change', e => {
        const teamBox = e.target.closest('[data-team-sel]');
        const memberBox = e.target.closest('[data-uid]');
        if (teamBox) {
            const team = state.teams.find(tm => tm.id === Number(teamBox.dataset.teamSel));
            (team ? team.members : []).forEach(m => { if (teamBox.checked) state.selected.add(Number(m.user_id)); else state.selected.delete(Number(m.user_id)); });
        } else if (memberBox) {
            const uid = Number(memberBox.dataset.uid);
            if (memberBox.checked) state.selected.add(uid); else state.selected.delete(uid);
        } else return;
        selectionChanged();
    });
    $('#wrcSelAll', root).addEventListener('click', () => {
        const all = state.teams.flatMap(tm => (tm.members || []).map(m => Number(m.user_id)));
        const everyone = all.length && all.every(id => state.selected.has(id));
        state.selected.clear();
        if (!everyone) all.forEach(id => state.selected.add(id));
        selectionChanged();
    });

    // «Επίπεδο εδάφους» (v3.426.0): the window that sets the ground level the barometer floor is measured from.
    const floorBtn = $('#wrcFloorBtn', root);
    if (floorBtn) floorBtn.addEventListener('click', () => floorBaselineOpen());
    const meshBtn = $('#wrcMeshBtn', root);
    if (meshBtn) meshBtn.addEventListener('click', () => meshGatewayOpen());

    function selectionChanged() {
        syncSelection();
        renderComposerTo();
        announce();
    }

    /**
     * Tells a look that re-draws from the console's state (the «Πίνακας» companion,
     * assets/js/war-room-console-overlay.js) that something it shows may have changed.
     * One event per burst: a timer, because a tab in the background never fires a frame.
     */
    let announced = 0;
    function announce() {
        if (announced) return;
        announced = setTimeout(() => { announced = 0; document.dispatchEvent(new CustomEvent('wr-console-changed')); }, 0);
    }

    // ── the order form: who, what, where ─────────────────────────────────────
    const VERBS = [
        ['point', 'bi-geo-alt-fill', 'console.verb_point'],
        ['sector', 'bi-grid-3x3-gap-fill', 'console.verb_sector'],
        ['route', 'bi-signpost-split-fill', 'console.verb_route'],
        ['task', 'bi-clipboard-check', 'console.verb_task'],
        ['say', 'bi-megaphone-fill', 'console.verb_say'],
        ['ask', 'bi-camera-fill', 'console.verb_ask'],
    ];
    // A live stream can only be asked for where the server has one configured
    // (the same gate the old request card has).
    const ASKS = [
        ['request_location', 'console.ask_location'], ['request_photo', 'console.ask_photo'],
        ['request_video', 'console.ask_video'], ['request_live', 'console.ask_live'],
    ].filter(([action]) => action !== 'request_live' || root.dataset.live === '1');

    const cmp = $('#wrcComposer', root);
    cmp.innerHTML = `
        <div class="wrc-cmp-row"><span class="wrc-cmp-l">${esc(t('console.cmp_to'))}</span><div class="wrc-cmp-to" id="wrcCmpTo"></div></div>
        <div class="wrc-cmp-row"><span class="wrc-cmp-l">${esc(t('console.cmp_what'))}</span>
            <div class="wrc-verbs" id="wrcVerbs">${VERBS.map(([key, icon, label]) => `<button type="button" class="wrc-verb" data-verb="${key}"><i class="bi ${icon}"></i><span>${esc(t(label))}</span></button>`).join('')}</div></div>
        <div class="wrc-cmp-row wrc-cmp-body" id="wrcCmpBody"></div>
        <div class="wrc-toast" id="wrcToast" role="status" aria-live="polite" hidden></div>`;

    function summary() { return wrcSummarizeSelection(state.teams, state.selected); }

    function renderComposerTo() {
        const s = summary();
        const to = $('#wrcCmpTo', root);
        if (!s.total) {
            to.innerHTML = `<span class="wrc-mut">${esc(t('console.cmp_nobody'))}</span>`;
        } else {
            to.innerHTML = s.chips.map(c => {
                const [bg] = teamBadgeColorsJs(c.color);
                return `<span class="wrc-rcp"><span class="wrc-swatch" style="background:${esc(bg)}"></span>${esc(wrcRecipientLabel(c, t('console.of')))}</span>`;
            }).join('') + `<button type="button" class="wrc-link" id="wrcCmpClear">${esc(t('console.clear'))}</button>`;
        }
        renderComposerBody(true);
    }
    cmp.addEventListener('click', e => {
        if (e.target.closest('#wrcCmpClear')) { state.selected.clear(); selectionChanged(); return; }
        const verb = e.target.closest('[data-verb]');
        if (verb) { state.verb = verb.dataset.verb; save('verb', state.verb); renderComposerBody(false); announce(); }
    });

    function toast(text, kind) {
        const el = $('#wrcToast', root);
        el.textContent = text;
        el.className = 'wrc-toast wrc-toast-' + (kind || 'ok');
        el.hidden = false;
        clearTimeout(toast._t);
        toast._t = setTimeout(() => { el.hidden = true; }, 7000);
    }

    /** The old cards post these as ordinary forms; this posts the same fields through fetch. */
    function postOrder(action, fields, file) {
        const body = new FormData();
        body.append('csrf_token', csrfToken);
        body.append('console_ajax', '1');
        body.append('action', action);
        Object.keys(fields).forEach(k => {
            const v = fields[k];
            if (Array.isArray(v)) v.forEach(x => body.append(k + '[]', x)); else body.append(k, v);
        });
        if (file) body.append(file.name, file.file);
        return fetch('war-room.php?id=' + MISSION_ID, {method: 'POST', body, credentials: 'same-origin'}).then(r => r.json());
    }
    function sent(res, okText) {
        const flash = (res && res.flash) || {};
        const msg = flash.error || flash.warning || flash.success || (res && res.ok ? okText : t('common.failed'));
        toast(msg, res && res.ok ? 'ok' : 'bad');
        if (res && res.ok && typeof retryPollNow === 'function') retryPollNow();
        return !!(res && res.ok);
    }
    function failed(e) { toast(t('common.send_failed'), 'bad'); if (window.console) console.error(e); }

    /**
     * A sector goes to a team through mission-sector.php, as the page's own sectorAdminSetTeam()
     * does - same request, same redraw of the map - but says what happened. That function answers
     * only with an alert() on failure and nothing at all on success: the sector shows up as one
     * more order under a team that may already have several, so «Αποστολή» looked like it did
     * nothing.
     */
    function assignSector(sectorId, teamId, go) {
        const sec = page.sectors().find(s => String(s.id) === String(sectorId));
        const team = state.teams.find(tm => tm.id === Number(teamId));
        const body = new URLSearchParams({csrf_token: csrfToken, mission_id: MISSION_ID, action: 'assign', id: sectorId, team_id: teamId});
        // On foot or with a vehicle, as chosen next to «Αποστολή» (v3.438.0).
        const travel = $('#wrcSectorTravel', root);
        if (travel) body.append('travel_mode', travel.value);
        go.disabled = true;
        fetch('mission-sector.php', {method: 'POST', body, credentials: 'same-origin'}).then(r => r.json()).then(res => {
            if (res && res.ok) {
                if (typeof map !== 'undefined' && map) map.closePopup();
                if (typeof sectorRefreshAfter === 'function') sectorRefreshAfter(res.sectors, res.areas);
                // The team's row opens its orders, so the one that was just sent is in view.
                if (team) state.ordersOpen.add(team.id);
                teamsSig = '';
                state.sectorTravel = null;
                toast(t('console.sector_sent', {sector: sec ? sec.label : '', team: team ? team.label : ''}), 'ok');
                if (typeof retryPollNow === 'function') retryPollNow();
                refreshSoon();
            } else {
                toast((res && res.error) || t('common.send_failed'), 'bad');
            }
        }).catch(failed).finally(() => { go.disabled = false; });
    }

    function recipientFields() {
        const ids = Array.from(state.selected);
        return ids.length ? {request_scope: 'selected', volunteers: ids} : null;
    }

    let bodyDraft = {task: '', say: ''};

    /**
     * The poll redraws the composer every few seconds. A body whose markup has not changed is
     * left alone - a chosen option, a half-typed order, the box that has the cursor and a click
     * that is under way all survive it - and one that has changed keeps what was chosen and
     * where the cursor was, as far as it still exists.
     */
    let bodyDone = '';
    function putComposerBody(body, html) {
        const sig = state.verb + '\u0001' + html;
        if (sig === bodyDone) return;
        bodyDone = sig;
        const active = body.contains(document.activeElement) ? document.activeElement : null;
        const focused = active && active.id ? active.id : '';
        const chosen = {};
        body.querySelectorAll('select[id]').forEach(s => { chosen[s.id] = s.value; });
        body.innerHTML = html;
        Object.keys(chosen).forEach(id => {
            const s = body.querySelector('#' + id);
            // A select whose markup names its own choice (the area just drawn) keeps that one.
            if (s && !s.querySelector('option[selected]') && Array.from(s.options).some(o => o.value === chosen[id])) s.value = chosen[id];
        });
        const back = focused ? body.querySelector('#' + focused) : null;
        if (back) back.focus();
    }

    function renderComposerBody(keepInputs) {
        $$('#wrcVerbs .wrc-verb', root).forEach(b => b.classList.toggle('on', b.dataset.verb === state.verb));
        const body = $('#wrcCmpBody', root);
        let html = '';
        const s = summary();
        const plan = wrcDispatchPlan(s, state.teams.length);
        const need = s.total ? '' : `<span class="wrc-mut">${esc(t('console.cmp_pick_first'))}</span>`;
        // A text box must survive the re-render a changed selection causes.
        const keep = keepInputs ? {task: ($('#wrcTaskText', root) || {}).value, say: ($('#wrcSayText', root) || {}).value} : null;
        if (keep) { if (keep.task !== undefined) bodyDraft.task = keep.task; if (keep.say !== undefined) bodyDraft.say = keep.say; }

        if (state.verb === 'point') {
            const hint = plan.mode === 'many' ? t('console.hint_many')
                : plan.mode === 'people' ? t('console.hint_people_point') : '';
            html = `<button type="button" class="wrc-go" id="wrcGoPoint"${plan.mode === 'many' ? ' disabled' : ''}><i class="bi bi-pin-map-fill"></i>${esc(t('console.open_point_map'))}</button>
                <span class="wrc-mut">${esc(hint || t('console.point_help'))}</span>`;
        } else if (state.verb === 'route') {
            const canReturn = s.chips.length === 1;
            html = `<button type="button" class="wrc-go" id="wrcGoRoute"><i class="bi bi-signpost-split-fill"></i>${esc(t('console.open_route_map'))}</button>
                <label class="wrc-chk-l"><input type="checkbox" id="wrcRouteReturn"${canReturn ? '' : ' disabled'}> ${esc(t('console.route_return'))}</label>
                <span class="wrc-mut">${esc(s.total && !s.chips.every(c => c.whole) || s.chips.length > 1 ? t('console.hint_people_route') : t('console.route_help'))}</span>`;
        } else if (state.verb === 'sector') {
            // The way sectors are made: an AREA first (the ground), then sectors inside it - cut
            // into pieces, or drawn one by one - and a sector goes to a team. All of it from the
            // map; nothing has to be prepared under Σχέδιο.
            const allAreas = page.areas();
            const area = wrcPickArea(allAreas, state.areaId);
            state.areaId = area ? String(area.id) : '';
            const one = plan.mode === 'team';
            // On foot or with a vehicle (v3.438.0): the way the chosen team moves until somebody chooses.
            const pickedTeam = one ? state.teams.find(tm => tm.id === plan.teamId) : null;
            const sectorTravel = state.sectorTravel || (pickedTeam && pickedTeam.transport && pickedTeam.transport !== 'foot' ? 'vehicle' : 'foot');
            const newArea = `<button type="button" class="wrc-go wrc-go-alt" id="wrcGoNewArea"><i class="bi bi-bounding-box-circles"></i>${esc(t('console.area_new_btn'))}</button>`;
            if (!area) {
                html = `<div class="wrc-line">${newArea}<span class="wrc-mut">${esc(t('console.area_none'))}</span></div>`;
            } else {
                const inArea = page.sectors().filter(sec => String(sec.area_id) === String(area.id));
                const free = inArea.filter(sec => !sec.team_id || sec.status === 'not_started');
                const rest = inArea.filter(sec => !free.includes(sec));
                const opt = sec => `<option value="${esc(sec.id)}">${esc(sec.label)}${sec.team_id && sec.team_label ? ' — ' + esc(sec.team_label) : ''}</option>`;
                const areaOpt = a => `<option value="${esc(a.id)}"${String(a.id) === state.areaId ? ' selected' : ''}>${esc(t('console.area_option', {label: a.label, count: Number(a.sector_count) || 0}))}</option>`;
                // The page cuts an area up only while it has no sectors (to redo it, clear it first).
                const divide = inArea.length === 0 && !Number(area.sector_count)
                    ? `<button type="button" class="wrc-go wrc-go-alt" id="wrcGoDivide"><i class="bi bi-scissors"></i>${esc(t('console.area_divide_btn'))}</button>` : '';
                html = `<div class="wrc-line"><span class="wrc-sub">${esc(t('console.area_l'))}</span>
                        <select class="wrc-in" id="wrcAreaPick" aria-label="${esc(t('console.area_l'))}">${allAreas.map(areaOpt).join('')}</select>${divide}${newArea}</div>
                    <div class="wrc-line"><span class="wrc-sub">${esc(t('console.verb_sector'))}</span>
                        ${inArea.length
                            ? `<select class="wrc-in" id="wrcSectorPick" aria-label="${esc(t('console.verb_sector'))}">${free.map(opt).join('')}${rest.length ? '<option disabled>──────────</option>' + rest.map(opt).join('') : ''}</select>
                               <select class="wrc-in" id="wrcSectorTravel" aria-label="${esc(t('travel.label'))}" title="${esc(t('travel.label'))}">${['foot', 'vehicle'].map(m => `<option value="${m}"${m === sectorTravel ? ' selected' : ''}>${esc(t('travel.' + m))}</option>`).join('')}</select>
                               <button type="button" class="wrc-go" id="wrcGoSector"${one ? '' : ' disabled'} title="${esc(one ? '' : t('console.sector_needs_team'))}"><i class="bi bi-send-fill"></i>${esc(t('console.send'))}</button>`
                            : `<span class="wrc-mut">${esc(t('console.sector_none'))}</span>`}
                        <button type="button" class="wrc-go wrc-go-alt" id="wrcGoDrawSector"><i class="bi bi-bounding-box"></i>${esc(t('console.sector_draw_btn'))}</button></div>
                    <div class="wrc-mut wrc-line-h">${esc(one ? t('console.sector_help') : t('console.sector_draw_help'))}</div>`;
            }
        } else if (state.verb === 'task') {
            html = `<input type="text" class="wrc-in wrc-grow" id="wrcTaskText" maxlength="500" placeholder="${esc(t('console.task_placeholder'))}">
                <button type="button" class="wrc-go" id="wrcGoTask"><i class="bi bi-send-fill"></i>${esc(t('console.send'))}</button>${need}`;
        } else if (state.verb === 'say') {
            html = `<input type="text" class="wrc-in wrc-grow" id="wrcSayText" maxlength="500" placeholder="${esc(t('console.say_placeholder'))}">
                <select class="wrc-in" id="wrcSayHow" aria-label="${esc(t('console.say_how'))}">
                    <option value="all">${esc(t('console.say_all'))}</option>
                    <option value="speak"${s.total ? '' : ' disabled'}>${esc(t('console.say_speak'))}</option>
                </select>
                <label class="wrc-btn wrc-attach" title="${esc(t('console.attach_photo'))}"><i class="bi bi-image"></i> <span id="wrcSayPhotoName">${esc(t('console.attach_photo'))}</span>
                    <input type="file" id="wrcSayPhoto" accept="image/*" hidden></label>
                <button type="button" class="wrc-go" id="wrcGoSay"><i class="bi bi-send-fill"></i>${esc(t('console.send'))}</button>`;
        } else {
            html = `<select class="wrc-in" id="wrcAskKind" aria-label="${esc(t('console.verb_ask'))}">${ASKS.map(([a, l]) => `<option value="${a}">${esc(t(l))}</option>`).join('')}</select>
                <button type="button" class="wrc-go" id="wrcGoAsk"><i class="bi bi-send-fill"></i>${esc(t('console.send'))}</button>
                <span class="wrc-mut">${esc(s.total ? t('console.ask_help_sel') : t('console.ask_help_all'))}</span>`;
        }
        putComposerBody(body, html);
        const taskBox = $('#wrcTaskText', root), sayBox = $('#wrcSayText', root);
        if (taskBox && taskBox.value !== bodyDraft.task) taskBox.value = bodyDraft.task;
        if (sayBox && sayBox.value !== bodyDraft.say) sayBox.value = bodyDraft.say;
    }


    cmp.addEventListener('change', e => {
        if (e.target.id === 'wrcAreaPick') {
            state.areaId = e.target.value;
            renderComposerBody(true);
            return;
        }
        if (e.target.id === 'wrcSectorTravel') {
            state.sectorTravel = e.target.value;
            return;
        }
        if (e.target.id !== 'wrcSayPhoto') return;
        const f = (e.target.files || [])[0];
        $('#wrcSayPhotoName', root).textContent = f ? f.name : t('console.attach_photo');
    });

    cmp.addEventListener('click', e => {
        const go = e.target.closest('.wrc-go');
        if (!go) return;
        const s = summary();
        const plan = wrcDispatchPlan(s, state.teams.length);

        if (go.id === 'wrcGoPoint') {
            // Whole team(s) go through the dispatch window as before; part of a
            // team, or people from several, as a one-point route (see wrcDispatchPlan).
            if (plan.mode === 'people') {
                pendingRouteSeed = Object.assign({points: [], label: '', closed: false}, wrcRouteSeedFor(s));
                bootstrap.Modal.getOrCreateInstance(document.getElementById('routeComposerModal')).show();
            } else {
                pendingDispatchSeed = {points: [], label: '', closed: false};
                if (plan.mode === 'team') pendingDispatchSeed.teamId = plan.teamId;
                else if (plan.mode === 'all') pendingDispatchSeed.teamId = 'all';
                bootstrap.Modal.getOrCreateInstance(document.getElementById('dispatchMapModal')).show();
            }
        } else if (go.id === 'wrcGoRoute') {
            pendingRouteSeed = Object.assign({points: [], label: '', closed: false},
                wrcRouteSeedFor(s), {returnToStart: !!$('#wrcRouteReturn', root)?.checked});
            bootstrap.Modal.getOrCreateInstance(document.getElementById('routeComposerModal')).show();
        } else if (go.id === 'wrcGoNewArea') {
            // The ordinary search-area window; wr-area-created brings the console back to the new area.
            pendingAreaSeed = null;
            bootstrap.Modal.getOrCreateInstance(document.getElementById('searchAreaMapModal')).show();
        } else if (go.id === 'wrcGoDivide') {
            const area = page.areas().find(a => String(a.id) === state.areaId);
            if (area && typeof openDivideSectorsForArea === 'function') openDivideSectorsForArea(area.id);
        } else if (go.id === 'wrcGoDrawSector') {
            // The search-area window, as a «draw a sector inside this area» window (see pendingAreaSeed),
            // for the team when one whole team is chosen, otherwise just laid out.
            const area = page.areas().find(a => String(a.id) === state.areaId);
            if (!area) return;
            const team = plan.mode === 'team' ? state.teams.find(tm => tm.id === plan.teamId) : null;
            pendingAreaSeed = {
                areaId: area.id, areaLabel: area.label,
                teamId: team ? team.id : null, teamLabel: team ? team.label : '',
                label: wrcNextSectorLabel(page.sectors().map(s => s.label), t('console.verb_sector')),
            };
            bootstrap.Modal.getOrCreateInstance(document.getElementById('searchAreaMapModal')).show();
        } else if (go.id === 'wrcGoSector') {
            const id = $('#wrcSectorPick', root).value;
            if (id && plan.mode === 'team') assignSector(id, plan.teamId, go);
        } else if (go.id === 'wrcGoTask') {
            const text = $('#wrcTaskText', root).value.trim();
            const rec = recipientFields();
            if (!text) { toast(t('order.task.empty_warning'), 'bad'); return; }
            if (!rec) { toast(t('console.cmp_pick_first'), 'bad'); return; }
            go.disabled = true;
            postOrder('request_task', Object.assign({task_text: text}, rec)).then(res => {
                if (sent(res, t('order.task.sent_flash', {count: s.total}))) { bodyDraft.task = ''; $('#wrcTaskText', root).value = ''; }
            }).catch(failed).finally(() => { go.disabled = false; });
        } else if (go.id === 'wrcGoSay') {
            const text = $('#wrcSayText', root).value.trim();
            const how = $('#wrcSayHow', root).value;
            const photo = ($('#wrcSayPhoto', root).files || [])[0] || null;
            if (!text && !photo) { toast(t('order.speak.empty_warning'), 'bad'); return; }
            go.disabled = true;
            const done = res => {
                if (sent(res, t('console.say_sent'))) {
                    bodyDraft.say = ''; $('#wrcSayText', root).value = '';
                    $('#wrcSayPhoto', root).value = ''; $('#wrcSayPhotoName', root).textContent = t('console.attach_photo');
                }
            };
            const rec = recipientFields();
            // A photo only travels with the message to everyone, as on the old card.
            const call = how === 'speak' && rec
                ? postOrder('request_speak', Object.assign({speak_text: text}, rec))
                : postOrder('global_message', {global_message_text: text}, photo ? {name: 'global_message_photo', file: photo} : null);
            call.then(done).catch(failed).finally(() => { go.disabled = false; });
        } else if (go.id === 'wrcGoAsk') {
            const action = $('#wrcAskKind', root).value;
            const rec = recipientFields() || {request_scope: 'all'};
            go.disabled = true;
            postOrder(action, rec).then(res => sent(res, t('console.ask_sent'))).catch(failed).finally(() => { go.disabled = false; });
        }
    });

    // ── the feed: what is coming in from the field ───────────────────────────
    const FEED_FILTERS = [['all', 'console.f_all'], ['incident', 'console.f_incident'], ['shortage', 'console.f_shortage'], ['photo', 'console.f_photo'], ['poi', 'console.f_poi']];

    function feedItems() {
        const items = [];
        page.incidents().forEach(r => items.push({
            key: 'incident:' + r.id, kind: 'incident', ts: wrcStampKey(r.created_at), icon: 'bi-bandaid', tone: r.severity === 'critical' || r.severity === 'high' ? 'bad' : 'warn',
            tag: t('console.f_incident'), who: r.team_label, text: (r.type_label || '') + (r.severity_label ? ' · ' + r.severity_label : '') + (r.is_unknown_patient ? '' : (r.patient_name ? ' · ' + r.patient_name : '')),
            when: r.created_at, lat: r.lat, lng: r.lng, card: 'incidentsListCard',
            extra: !r.resolved_at && r.lat !== null && r.lat !== undefined && typeof openDispatchForIncident === 'function' ? {act: 'incident-send', id: r.id, label: t('incident.dispatch_btn')} : null,
        }));
        page.shortages().forEach(r => items.push({
            key: 'shortage:' + r.id, kind: 'shortage', ts: wrcStampKey(r.created_at), icon: 'bi-box-seam', tone: r.severity === 'critical' ? 'bad' : 'warn',
            tag: t('console.f_shortage'), who: r.team_label || r.reporter_name, text: r.title || '',
            when: r.created_at, lat: r.lat, lng: r.lng, card: 'shortageListCard',
        }));
        page.media().forEach(m => items.push({
            key: 'photo:' + m.id, kind: 'photo', ts: wrcStampKey(m.time), icon: m.media_type === 'video' ? 'bi-camera-video' : 'bi-camera', tone: 'info',
            tag: t(m.media_type === 'video' ? 'console.f_video' : 'console.f_photo_one'), who: m.team_label || m.user_name, text: m.poi_note || m.user_name || '',
            when: m.time, lat: m.lat, lng: m.lng, card: 'mediaCard', thumb: m.has_thumb && !m.purged ? m.id : null,
            thumbType: m.media_type, note: m.poi_note || '',
        }));
        page.pois().forEach(p => {
            // The first photo that has a thumbnail stands for the point; clicking it opens that photo.
            const shot = (p.photos || []).find(x => x.has_thumb) || null;
            items.push({
                key: 'poi:' + p.id, kind: 'poi', ts: wrcStampKey(p.created_at), icon: 'bi-flag', tone: p.checked_at ? 'ok' : 'info',
                tag: t('console.f_poi_one'), who: (p.reporter_names || []).join(', '), text: ((p.photos || [])[0] || {}).note || '',
                when: p.created_at, lat: p.lat, lng: p.lng, card: 'poiListCard',
                thumb: shot ? shot.id : null, thumbType: shot ? shot.media_type : null, note: shot ? (shot.note || '') : '',
            });
        });
        return items.sort((a, b) => b.ts - a.ts);
    }

    // ── what is new: the counters on the dock's tabs ─────────────────────────
    // Ροή, Συνομιλία and Πολυμέσα each count what arrived since the coordinator last looked
    // at that tab, and clear the moment they go there (or come back to a hidden window that
    // has it open). Remembered per mission in this browser, so what came in while the page
    // was closed still counts; a first visit counts nothing - the history is not news.
    // Chat is counted per room: looking at one room does not make the others seen, and a
    // small pill on each room tab says where the new messages are.
    function loadSeenKeys(name) {
        const raw = load(name, null);
        if (raw === null) return null;
        try { const list = JSON.parse(raw); return Array.isArray(list) ? new Set(list) : null; } catch (e) { return null; }
    }
    function loadSeenChat() {
        const raw = load('seen-chat', null);
        if (raw === null) return null;
        try { const o = JSON.parse(raw); return o && typeof o === 'object' && !Array.isArray(o) ? o : null; } catch (e) { return null; }
    }
    const seenKeys = {feed: loadSeenKeys('seen-feed'), media: loadSeenKeys('seen-media')};
    let seenChat = loadSeenChat();

    function unreadOf(name, keys) {
        const before = seenKeys[name];
        if (before === null) {
            seenKeys[name] = new Set(keys);
            save('seen-' + name, JSON.stringify(keys));
            return 0;
        }
        const viewing = state.tab === name && !document.hidden;
        // What is gone is forgotten, and what is on screen now is seen.
        const next = new Set(keys.filter(k => viewing || before.has(k)));
        if (next.size !== before.size || Array.from(next).some(k => !before.has(k))) {
            seenKeys[name] = next;
            save('seen-' + name, JSON.stringify(Array.from(next)));
        }
        return keys.length - next.size;
    }

    function roomKeyOf(tab) { return tab && tab.dataset.teamId ? String(tab.dataset.teamId) : 'general'; }
    function activeRoomKey() {
        return roomKeyOf($('.chat-room-tab.active', root));
    }
    /** The «messages» counter in the bar: bring the chat up on the room that has the new messages. */
    function openUnreadChat() {
        // Decided before the chat is shown: showing a room is what makes its messages seen.
        const tabs = $$('.chat-room-tab', root);
        const key = wrcChatTarget(lastChat.byRoom, tabs.map(roomKeyOf), activeRoomKey());
        setTab('chat');
        const tab = key === null ? null : tabs.find(tb => roomKeyOf(tb) === key);
        if (tab && !tab.classList.contains('active')) tab.click();
        renderBadges();
    }
    function chatUnread() {
        if (!state.chatRooms) return {total: 0, byRoom: {}};
        const viewed = state.tab === 'chat' && !document.hidden ? activeRoomKey() : null;
        const r = wrcChatUnread(state.chatRooms, seenChat, viewed);
        if (JSON.stringify(r.seen) !== JSON.stringify(seenChat)) {
            seenChat = r.seen;
            save('seen-chat', JSON.stringify(r.seen));
        }
        return r;
    }

    function setCount(id, n) {
        const el = $('#' + id, root);
        const text = n > 99 ? '99+' : (n ? String(n) : '');
        if (el.textContent !== text) el.textContent = text;
        el.classList.toggle('d-none', n === 0);
    }
    function renderBadges() {
        setCount('wrcFeedCnt', unreadOf('feed', feedItems().map(i => i.key)));
        setCount('wrcMediaCnt', unreadOf('media', page.media().map(m => 'media:' + m.id)));
        const chat = chatUnread();
        setCount('wrcChatCnt', chat.total);
        // The counter in the bar says the same as the dock's tab, and clears with it.
        const chatChanged = chat.total !== lastChat.total;
        lastChat = chat;
        if (chatChanged) renderKpis();
        $$('.chat-room-tab', root).forEach(tab => {
            const n = chat.byRoom[roomKeyOf(tab)] || 0;
            let pill = $('.wrc-room-new', tab);
            if (!n) { if (pill) pill.remove(); return; }
            if (!pill) { pill = document.createElement('span'); pill.className = 'wrc-room-new'; tab.appendChild(pill); }
            if (pill.textContent !== String(n)) pill.textContent = String(n);
        });
    }
    document.addEventListener('visibilitychange', () => { if (!document.hidden) renderBadges(); });
    // The room tabs are the chat card's own; its handler has switched the room by the time this runs.
    $('.wrc-pane[data-pane="chat"]', root).addEventListener('click', e => { if (e.target.closest('.chat-room-tab')) renderBadges(); });

    let feedSig = '';
    function renderFeed() {
        const all = feedItems();
        const items = state.filter === 'all' ? all : all.filter(i => i.kind === state.filter);
        const chips = $('#wrcFeedChips', root);
        const chipSig = state.filter + '|' + FEED_FILTERS.map(([k]) => all.filter(i => k === 'all' || i.kind === k).length).join(',');
        if (chips.dataset.sig !== chipSig) {
            chips.dataset.sig = chipSig;
            chips.innerHTML = FEED_FILTERS.map(([k, label]) => `<button type="button" class="wrc-chip${state.filter === k ? ' on' : ''}" data-filter="${k}">${esc(t(label))}<span class="wrc-chip-n">${all.filter(i => k === 'all' || i.kind === k).length}</span></button>`).join('');
        }
        const sig = JSON.stringify(items.map(i => [i.kind, i.ts, i.text, i.who, i.when, i.thumb]));
        if (sig === feedSig) return;
        feedSig = sig;
        const feed = $('#wrcFeed', root);
        feed._items = items;
        feed.innerHTML = items.length ? items.map((i, n) => `
            <div class="wrc-f wrc-tone-${esc(i.tone)}" data-f="${n}">
                ${i.thumb ? `<img class="wrc-f-thumb media-view-delegate" loading="lazy" alt="" src="mission-photo-view.php?id=${esc(i.thumb)}&thumb=1" data-id="${esc(i.thumb)}" data-media-type="${esc(i.thumbType || 'photo')}" data-note="${esc(i.note || '')}" data-meta="${esc([i.who, i.when].filter(Boolean).join(' \u00b7 '))}">` : `<i class="bi ${esc(i.icon)} wrc-f-i"></i>`}
                <div class="wrc-f-main">
                    <div class="wrc-f-h"><span class="wrc-tag">${esc(i.tag)}</span><span class="wrc-f-who">${esc(i.who || '')}</span><span class="wrc-f-t num">${esc(i.when || '')}</span></div>
                    <div class="wrc-f-x">${esc(i.text)}</div>
                    <div class="wrc-f-a">
                        ${i.lat !== null && i.lat !== undefined ? `<button type="button" class="wrc-link" data-fa="map">${esc(t('console.on_map'))}</button>` : ''}
                        ${i.extra ? `<button type="button" class="wrc-link" data-fa="${esc(i.extra.act)}" data-id="${esc(i.extra.id)}">${esc(i.extra.label)}</button>` : ''}
                        <button type="button" class="wrc-link" data-fa="open">${esc(t('console.open'))}</button>
                    </div>
                </div>
            </div>`).join('') : `<div class="wrc-empty">${esc(t('console.feed_empty'))}</div>`;
    }
    $('#wrcFeedChips', root).addEventListener('click', e => {
        const b = e.target.closest('[data-filter]');
        if (!b) return;
        state.filter = b.dataset.filter;
        $('#wrcFeedChips', root).dataset.sig = '';
        feedSig = '';
        renderFeed();
    });
    $('#wrcFeed', root).addEventListener('click', e => {
        const btn = e.target.closest('[data-fa]');
        const row = e.target.closest('.wrc-f');
        if (!btn || !row) return;
        const item = ($('#wrcFeed', root)._items || [])[Number(row.dataset.f)];
        if (!item) return;
        if (btn.dataset.fa === 'map' && typeof map !== 'undefined' && map) map.setView([Number(item.lat), Number(item.lng)], 17);
        else if (btn.dataset.fa === 'open') { reveal(item.card); if (typeof scrollToCard === 'function') scrollToCard(item.card); }
        else if (btn.dataset.fa === 'incident-send') {
            const inc = page.incidents().find(r => String(r.id) === String(btn.dataset.id));
            if (inc) openDispatchForIncident(inc);
        }
    });

    // ── the poll hands over what it just applied ─────────────────────────────
    let sectorSig = '';
    function refresh() {
        renderBadges();
        renderKpis();
        renderQueue();
        renderPhase();
        renderTeams();
        renderComposerTo();
        renderFeed();
        // The sector picker lists what the sectors are NOW.
        const sig = JSON.stringify([page.sectors().map(sec => [sec.id, sec.area_id, sec.team_id, sec.status, sec.label]), page.areas().map(a => [a.id, a.label, a.sector_count])]);
        if (sig !== sectorSig) {
            sectorSig = sig;
            if (state.verb === 'sector') renderComposerBody(true);
        }
        announce();
    }

    // The page's own renderers run the moment something is acknowledged, sent or
    // deleted; the poll comes up to five seconds later. Redraw with them, so what
    // the coordinator just did shows at once instead of after the next tick.
    let soon = 0;
    function refreshSoon() {
        if (soon) return;
        // A timer, not requestAnimationFrame: a tab in the background never fires one.
        soon = setTimeout(() => { soon = 0; refresh(); }, 20);
    }
    ['renderSosAlerts', 'renderVoiceMessages', 'renderAssistant', 'renderMissionIncidents', 'renderShortageReports',
     'renderPointsOfInterest', 'renderMedia', 'renderTeamBoard', 'renderTeamRosters'].forEach(name => {
        const original = window[name];
        if (typeof original !== 'function') return;
        window[name] = function () {
            const result = original.apply(this, arguments);
            refreshSoon();
            return result;
        };
    });

    /**
     * What the «Πίνακας» look shows beside the console's own regions: the teams as the team rows
     * read them (their first order, the progress ring, who can be reached), who is picked and the
     * phase. Plain copies - the look changes the console only through pickTeam() and clearSelection().
     */
    function view() {
        const board = (typeof teamBoardItems === 'function') ? teamBoardItems : () => [];
        return {
            phase: state.phase,
            verb: state.verb,
            gps: wrcContactOf(state.teams.flatMap(tm => tm.members || []), isStale),
            teams: state.teams.map(team => {
                const items = board({id: team.id, label: team.label, color: team.color});
                const members = team.members || [];
                const vm = avatarModel(team, items, members);
                const ids = members.map(m => Number(m.user_id));
                const picked = ids.filter(id => state.selected.has(id)).length;
                return {
                    id: team.id, label: team.label, color: team.color, number: Number(team.team_number) || 0,
                    members: ids, picked, all: ids.length,
                    orders: items.map(i => ({kind: i.kind, detail: i.detail, state: i.state, tone: i.tone})),
                    ring: vm.ring, contact: vm.contact, badge: vm.badge,
                };
            }),
        };
    }
    /** One team into the order's recipients: 'toggle' adds it or takes it out, 'only' makes it the only one. */
    function pickTeam(id, mode) {
        const team = state.teams.find(tm => tm.id === Number(id));
        if (!team) return;
        const ids = (team.members || []).map(m => Number(m.user_id));
        const whole = ids.length > 0 && ids.every(uid => state.selected.has(uid));
        if (mode === 'only') state.selected.clear();
        if (mode === 'only' || !whole) ids.forEach(uid => state.selected.add(uid)); else ids.forEach(uid => state.selected.delete(uid));
        selectionChanged();
    }
    function clearSelection() { state.selected.clear(); selectionChanged(); }

    window.wrConsole = {
        reveal,
        view,
        pickTeam,
        clearSelection,
        // The red LIVE button's «I have looked at what is on air» (stops the flashing), for a look that shows the picture itself.
        watchLive,
        onPoll(data) {
            if (data.teams) setTeams(data.teams);
            if (data.pingStaleness) state.stale = data.pingStaleness;
            if (data.participantLive) state.live = data.participantLive;
            if (data.onlinePresence) state.online = data.onlinePresence;
            if (Array.isArray(data.ackTracker)) state.ack = data.ackTracker;
            // An empty mission sends [] (a PHP list); a busy one an object of rooms.
            if (data.chatRooms !== undefined && data.chatRooms !== null) state.chatRooms = Array.isArray(data.chatRooms) ? {} : data.chatRooms;
            refresh();
        },
    };

    // The search-area window saved an area: it is the one the sector tab works in from now on.
    document.addEventListener('wr-area-created', e => {
        const made = e.detail || {};
        state.areaId = String(made.id);
        if (state.verb === 'sector') renderComposerBody(true);
        toast(t('console.area_made', {area: made.label || ''}), 'ok');
    });
    // The search-area window drew a sector inside an area (and sent it, when it had a team).
    document.addEventListener('wr-sector-sent', e => {
        const sent = e.detail || {};
        const team = sent.teamId ? state.teams.find(tm => tm.id === Number(sent.teamId)) : null;
        if (team) state.ordersOpen.add(team.id);
        teamsSig = '';
        toast(team
            ? t('console.sector_sent', {sector: sent.label || '', team: team.label})
            : t('console.sector_made', {sector: sent.label || '', area: sent.areaLabel || ''}), 'ok');
        if (typeof retryPollNow === 'function') retryPollNow();
        renderComposerBody(true);
        refreshSoon();
    });
    // A new team made while the page is open.
    document.addEventListener('wr-teams-updated', e => { if (e.detail && Array.isArray(e.detail.teams)) { setTeams(e.detail.teams); teamsSig = ''; refresh(); } });

    // ── go ───────────────────────────────────────────────────────────────────
    setTeams(typeof WR_TEAMS_AT_LOAD !== 'undefined' ? WR_TEAMS_AT_LOAD : []);
    if (typeof ackTrackerCards !== 'undefined' && Array.isArray(ackTrackerCards)) state.ack = ackTrackerCards;
    setTab(state.tab);
    setPhase(state.phase, false);
    refresh();
    fit();
    // The hero above it settles after fonts and badges load.
    setTimeout(fit, 400);
    setTimeout(fit, 1500);
    // A freshly built layout needs one more look at the map's size.
    setTimeout(() => { if (typeof map !== 'undefined' && map) map.invalidateSize(); }, 200);
})();

/**
 * War Room (Action Room) - pure/near-pure utility functions.
 * Extracted from war-room.php's inline <script> so they're unit-testable
 * (see tests/js/war-room-utils.test.js, run via `node --test`) without
 * pulling in the rest of that file's DOM/map/offline-queue state.
 *
 * Loaded as a plain <script src> (not a module) so these stay ordinary
 * globals, exactly as when they were defined inline - every other call site
 * in war-room.php's own inline script is unchanged.
 *
 * formatDistanceMeters(), bearingToCompassAbbr() and
 * missingRouteDeliverablesClientSide() call the page's t() translation
 * helper (defined in war-room.php itself, includes/i18n.php's JS-side
 * counterpart) as a global, same as before extraction - Node tests stub it.
 */

function bearing(latlng1, latlng2) {
    const lat1 = latlng1.lat * Math.PI / 180, lat2 = latlng2.lat * Math.PI / 180, dLng = (latlng2.lng - latlng1.lng) * Math.PI / 180;
    const y = Math.sin(dLng) * Math.cos(lat2);
    const x = Math.cos(lat1) * Math.sin(lat2) - Math.sin(lat1) * Math.cos(lat2) * Math.cos(dLng);
    return (Math.atan2(y, x) * 180 / Math.PI + 360) % 360;
}

// Inverse of bearing() above — the direct/forward geodesic problem (given a
// start point, a bearing, and a distance, where do you end up), not the
// inverse one (given two points, what's the bearing between them). Standard
// spherical trig, Earth radius 6371000m matching gpsDistanceMeters()
// (includes/functions-warroom.php) elsewhere in this app. Longitude is
// normalized to [-180, 180] since the raw formula can wrap past the
// antimeridian for a large distance/bearing combination.
function destinationPoint(latlng, bearingDeg, distanceMeters) {
    const R = 6371000;
    const δ = distanceMeters / R;
    const θ = bearingDeg * Math.PI / 180;
    const φ1 = latlng.lat * Math.PI / 180;
    const λ1 = latlng.lng * Math.PI / 180;

    const φ2 = Math.asin(Math.sin(φ1) * Math.cos(δ) + Math.cos(φ1) * Math.sin(δ) * Math.cos(θ));
    const λ2 = λ1 + Math.atan2(
        Math.sin(θ) * Math.sin(δ) * Math.cos(φ1),
        Math.cos(δ) - Math.sin(φ1) * Math.sin(φ2)
    );

    return {
        lat: φ2 * 180 / Math.PI,
        lng: (λ2 * 180 / Math.PI + 540) % 360 - 180,
    };
}

// Approximates a circle as an N-point polygon by sampling destinationPoint()
// evenly around 360° — used to seed a dispatch polygon or a route's
// waypoints from an LPB search ring's boundary (war-room.php), reusing
// mission-dispatch.php/mission-route.php's existing [[lat,lng],...] shape
// verbatim. numPoints is a required argument, not defaulted: how many points
// meaningfully trace a circle depends entirely on its radius (a small ring
// needs far fewer than a multi-km one) — see the callers in war-room.php for
// how numPoints is actually chosen.
function circleToPolygonPoints(center, radiusMeters, numPoints) {
    const points = [];
    for (let i = 0; i < numPoints; i++) {
        const pt = destinationPoint(center, (360 / numPoints) * i, radiusMeters);
        points.push([pt.lat, pt.lng]);
    }
    return points;
}

// Sector search — straight radial legs from a ring's inner boundary to its
// outer boundary, stepping to the next bearing and back, like spokes on a
// wheel. This is the actual SAR-doctrine pattern for searching a circular
// area radiating from a KNOWN datum point (IAMSAR/NASAR "sector search",
// the maritime "Victor Sierra" pattern: search unit travels straight out
// from center, turns, travels back in, turns again — recommended
// specifically when the target's position is known with reasonable
// confidence, which is exactly what an LPB percentage ring already is: a
// probability contour computed FROM a known last-seen point, not a vague
// search box). Two earlier attempts at this function got the shape wrong
// by not grounding it in that doctrine first: concentric arcs at fixed
// radius (confirmed live to read as scattered, disconnected numbers rather
// than a path) and, before that was even shipped, a from-scratch Cartesian
// zigzag that would have needed real line-vs-circle/wedge clipping to
// avoid re-walking a smaller inner ring's ground. Radial legs need none of
// that clipping — a leg is BY CONSTRUCTION already confined between
// innerRadiusMeters and outerRadiusMeters (it's defined as going from one
// to the other) and, for a wedge, already confined to
// [startBearingDeg, startBearingDeg+sweepDeg] (legs are only ever placed at
// bearings inside that range) — so both constraints hold for free, no
// intersection math required.
//
// Only 2 points per leg: a leg is a straight radial line, not a curve, so
// there's nothing to approximate — unlike the old arcs, which needed
// several points each just to look round. Legs alternate direction
// (in→out, then out→in) so consecutive legs meet at whichever radius they
// share, keeping the connecting "step" short instead of a diagonal cut
// across the whole band.
//
// startBearingDeg/sweepDeg default to a full circle. sweepDeg >= 360 uses
// EXCLUSIVE angular spacing (legCount legs spread sweepDeg/legCount apart)
// so the last leg doesn't land back on the same bearing as the first —
// circleToPolygonPoints()'s own convention, for the same reason. A
// narrower wedge instead spaces INCLUSIVE of both edges (legCount==1 skips
// the division) — weightedWedgePolygonPoints()'s convention — so a team's
// route actually reaches both edges of their assigned wedge, not stopping
// short of it.
function sectorSearchLegPoints(center, innerRadiusMeters, outerRadiusMeters, legCount, startBearingDeg = 0, sweepDeg = 360) {
    const points = [];
    const isFullCircle = sweepDeg >= 360;
    for (let leg = 0; leg < legCount; leg++) {
        const t = isFullCircle ? leg / legCount : (legCount === 1 ? 0 : leg / (legCount - 1));
        const bearingDeg = startBearingDeg + sweepDeg * t;
        const reverse = leg % 2 === 1;
        const nearRadius = reverse ? outerRadiusMeters : innerRadiusMeters;
        const farRadius = reverse ? innerRadiusMeters : outerRadiusMeters;
        const near = destinationPoint(center, bearingDeg, nearRadius);
        const far = destinationPoint(center, bearingDeg, farRadius);
        points.push([near.lat, near.lng], [far.lat, far.lng]);
    }
    return points;
}
// How many radial legs sectorSearchLegPoints() above should use, given how
// many degrees the sweep covers. Doctrine's own minimum for a full circle
// is 3 legs at 120° apart (the classic Victor Sierra pattern); this targets
// a finer 45° step (8 legs for a full circle) since these are walking
// teams, not aircraft, but keeps that same floor of 3 so even a narrow
// auto-assign wedge gets a real sector-search shape, not a single there-
// and-back line.
function sectorSearchLegCount(sweepDeg) {
    return Math.max(3, Math.round(sweepDeg / 45));
}

// One angular wedge of an LPB ring's full disc (center to this ring's own
// radius, matching what the manual divide-into-sectors tool's wedges
// cover) — center, then numPoints boundary points evenly spaced INCLUSIVE
// of both startBearingDeg and startBearingDeg+sweepDeg. Used directly as a
// finished mission_search_areas polygon by openAutoAssignForRing()
// (war-room.php), not fed into the interactive chord-cutting tool, so —
// unlike ringDiscPolygonPoints() above — it needs no duplicated seam point.
// That function's seam issue comes from circleToPolygonPoints() sampling
// EXCLUSIVE of the wrap-around point (bearing 360 is never reached); here
// sampling is inclusive of both endpoints by construction, so the last
// boundary point genuinely IS the wedge's far edge, and the polygon's
// implicit closing edge (last vertex -> center) is the wedge's own second
// radial edge, not a shortcut that discards area. Holds even at
// sweepDeg=360 (a single team gets the whole ring): the first and last
// boundary points land on the same physical bearing, naturally closing the
// disc the same way ringDiscPolygonPoints()'s explicit duplicate does.
function weightedWedgePolygonPoints(center, radiusMeters, startBearingDeg, sweepDeg, numPoints) {
    const boundary = [];
    for (let i = 0; i < numPoints; i++) {
        const bearingDeg = startBearingDeg + sweepDeg * i / (numPoints - 1);
        const pt = destinationPoint(center, bearingDeg, radiusMeters);
        boundary.push([pt.lat, pt.lng]);
    }
    return [[center.lat, center.lng], ...boundary];
}

// The BAND between two LPB rings, optionally narrowed to one angular wedge
// of it — what "ring N" actually means operationally for N > 0, since ring
// N's disc wholly contains every smaller ring's. weightedWedgePolygonPoints()
// above always spans center-to-radius, so using it for ring 2/3/4 hands a
// team ground already assigned to the rings inside it; worse, it made
// verified coverage measure a team's GPS against the whole disc while their
// route only ever swept the band, under-reporting real coverage and tripping
// the <60% "low coverage" warning on correctly-executed searches. Same
// "don't re-walk the smaller ring(s)" reasoning sectorSearchLegPoints()
// already applies to ROUTES, applied to the assigned AREA so the two finally
// describe the same ground.
//
// innerRadiusMeters <= 0 returns the plain pie slice (center + outer arc) —
// ring 0 genuinely has no hole, so it stays byte-identical to what
// weightedWedgePolygonPoints() produced.
//
// Otherwise: outer arc from startBearingDeg to startBearingDeg+sweepDeg,
// then the inner arc walked BACK along the same bearings, giving a 4-sided
// annular wedge whose two radial edges are the wedge's own sides (the
// implicit closing edge, last inner point -> first outer point, is the
// second one). Both arcs sample INCLUSIVE of both bearings, the same
// convention weightedWedgePolygonPoints() uses and for the same reason: a
// team's area has to actually reach both edges of their wedge.
//
// At sweepDeg >= 360 (one team gets the whole ring) this closes into a
// slit annulus: first and last outer points land on the same bearing, so
// the shape pinches to zero width at that seam and the two coincident
// radial edges there are traversed in opposite directions. That is safe for
// the ray-casting pointInPolygon() in functions-warroom.php that sector
// coverage runs — the zero-length edges contribute no crossings at all
// (latI === latJ fails its own (latI > lat) !== (latJ > lat) test), and the
// doubled seam is always crossed twice or not at all, so it cancels and
// never flips the inside/outside parity.
function annularWedgePolygonPoints(center, innerRadiusMeters, outerRadiusMeters, startBearingDeg, sweepDeg, numPoints) {
    const n = Math.max(2, numPoints);
    const bearingAt = i => startBearingDeg + sweepDeg * i / (n - 1);
    const outer = [];
    for (let i = 0; i < n; i++) {
        const pt = destinationPoint(center, bearingAt(i), outerRadiusMeters);
        outer.push([pt.lat, pt.lng]);
    }
    if (innerRadiusMeters <= 0) {
        return [[center.lat, center.lng], ...outer];
    }
    const inner = [];
    for (let i = n - 1; i >= 0; i--) {
        const pt = destinationPoint(center, bearingAt(i), innerRadiusMeters);
        inner.push([pt.lat, pt.lng]);
    }
    return [...outer, ...inner];
}

// Builds a mission_search_areas-shaped geo array (a flat [[lat,lng],...]
// polygon, same shape circleToPolygonPoints() already produces) tracing an
// LPB ring's full disc — center point, then numPoints boundary points from
// circleToPolygonPoints(), then the FIRST boundary point again as one extra
// trailing vertex. That trailing duplicate is required, not decorative: a
// polygon's last vertex implicitly closes back to its first one, so a plain
// [center, ...boundary] array closes from the LAST boundary point straight
// back to center — never from the last boundary point back to the FIRST
// one — which silently leaves one boundary arc's worth of area out of the
// shape entirely (not "uncut": genuinely absent, since a straight cut
// between two existing vertices can only ever split area a polygon already
// has, never add area it doesn't). Repeating the first boundary point gives
// that missing arc a real edge of its own (lastBoundaryPoint ->
// duplicatedFirstBoundaryPoint), so the shape becomes a complete disc and
// every boundary point becomes reachable as an independent center-to-vertex
// cut. Fed as-is into war-room.php's existing divideSectorsModal chord tool
// (built for hand-drawn mission_search_areas polygons, unmodified here), a
// center-to-boundary-vertex chord is exactly a radial spoke, so that tool
// produces true pie-slice sectors with zero changes of its own.
function ringDiscPolygonPoints(center, radiusMeters, numPoints) {
    const boundary = circleToPolygonPoints(center, radiusMeters, numPoints);
    return [[center.lat, center.lng], ...boundary, boundary[0]];
}

// ── Automatic sector grid ───────────────────────────────────────────────────
// Mirror of buildSectorGridCells() in includes/functions-warroom.php. That
// function's comment carries the reasoning behind every choice repeated here
// (meters only decide cols/rows, the cells themselves are cut out of the
// bounding box in degrees, a cell is kept when its CENTER is inside).
//
// This copy exists for the live preview in war-room.php's grid tool. The
// server recomputes the grid from the stored area polygon and never accepts
// a cell list from the client, so these two must produce the same cells or
// the preview's "Create 13 sectors" button is lying about what it is about
// to do. tests/fixtures/grid-cases.json is asserted by both sides for
// exactly that reason — the fixture, not discipline, is what holds them
// together.
//
// Deliberately does NOT reuse destinationPoint() above: that one is
// spherical (R=6371000) while the PHP side is flat local meters, and mixing
// the two models is precisely how two implementations of "the same" grid
// drift apart.
const GRID_SECTOR_SIZE_MIN_M = 50;
const GRID_SECTOR_SIZE_MAX_M = 2000;

// PHP's round() rounds halves away from zero; JS's Math.round() rounds them
// toward +Infinity, and the two languages' libm can differ in the last bit
// anyway. Matching PHP's direction here keeps them as close as they can get;
// the shared fixture compares coordinates with a 1e-6 tolerance (~11cm, well
// inside GPS error) and compares the counts exactly, since the counts are
// what the UI promises out loud.
function roundTo(n, decimals) {
    const f = Math.pow(10, decimals);
    return Math.sign(n) * Math.round(Math.abs(n) * f) / f;
}

// Ray casting, odd-number-of-crossings rule — the JS twin of
// pointInPolygon() in includes/functions-warroom.php, same ring of
// [lat, lng] pairs (not GeoJSON's [lng, lat]). Nothing on this side of the
// app had one before the grid preview needed it.
function pointInPolygon(lat, lng, geo) {
    let inside = false;
    for (let i = 0, j = geo.length - 1; i < geo.length; j = i++) {
        const latI = Number(geo[i][0]), lngI = Number(geo[i][1]);
        const latJ = Number(geo[j][0]), lngJ = Number(geo[j][1]);
        if (((latI > lat) !== (latJ > lat))
            && (lng < (lngJ - lngI) * (lat - latI) / (latJ - latI) + lngI)) {
            inside = !inside;
        }
    }
    return inside;
}

// One point a team can actually be sent to for a whole shape — the JS twin of
// polygonCentroid() in includes/functions-warroom.php, pinned to it by
// tests/fixtures/nav-point-cases.json so the two can never drift.
//
// A polygon has no single coordinate, but "Πλοήγηση" on a sector or a search
// area has to hand Google Maps exactly one. The plain area-weighted centroid
// is the obvious answer and it is wrong for a real search shape: the middle of
// a horseshoe is not in the horseshoe, and a sector traced around a gorge or a
// bay is exactly that. A team routed to such a point is sent to the wrong side
// of a ridge from every part of the ground they were given.
//
// So: take the centroid, and if it landed outside, cut the shape with the
// horizontal line through it and aim at the middle of the widest piece that is
// genuinely inside.
function polygonNavPoint(geo) {
    const ring = [];
    for (const p of (geo || [])) {
        if (!Array.isArray(p) || p.length < 2) continue;
        const lat = Number(p[0]), lng = Number(p[1]);
        if (!Number.isFinite(lat) || !Number.isFinite(lng)) continue;
        ring.push([lat, lng]);
    }
    const n = ring.length;
    if (n < 3) {
        // Two points is a line and one is a point: their middle is still a
        // perfectly good answer, and refusing would lose a real target.
        if (n === 2) return {lat: (ring[0][0] + ring[1][0]) / 2, lng: (ring[0][1] + ring[1][1]) / 2};
        if (n === 1) return {lat: ring[0][0], lng: ring[0][1]};
        return null;
    }

    let twiceArea = 0, latAcc = 0, lngAcc = 0;
    for (let i = 0, j = n - 1; i < n; j = i++) {
        const cross = ring[j][1] * ring[i][0] - ring[i][1] * ring[j][0];
        twiceArea += cross;
        latAcc += (ring[j][0] + ring[i][0]) * cross;
        lngAcc += (ring[j][1] + ring[i][1]) * cross;
    }

    let cLat, cLng;
    if (Math.abs(twiceArea) > 1e-12) {
        cLat = latAcc / (3 * twiceArea);
        cLng = lngAcc / (3 * twiceArea);
        if (pointInPolygon(cLat, cLng, ring)) return {lat: cLat, lng: cLng};
    } else {
        // Degenerate ring (every vertex collinear): the mean, which for a
        // line is its middle.
        let sLat = 0, sLng = 0;
        for (const p of ring) { sLat += p[0]; sLng += p[1]; }
        return {lat: sLat / n, lng: sLng / n};
    }

    const xs = [];
    for (let i = 0, j = n - 1; i < n; j = i++) {
        const latI = ring[i][0], latJ = ring[j][0];
        if ((latI > cLat) === (latJ > cLat)) continue;
        xs.push((ring[j][1] - ring[i][1]) * (cLat - latI) / (latJ - latI) + ring[i][1]);
    }
    xs.sort((a, b) => a - b);
    let bestMid = null, bestSpan = -1;
    // Crossings pair up: inside, outside, inside… so every other gap is solid.
    for (let i = 0; i + 1 < xs.length; i += 2) {
        const span = xs[i + 1] - xs[i];
        if (span > bestSpan) { bestSpan = span; bestMid = (xs[i] + xs[i + 1]) / 2; }
    }
    if (bestMid !== null) return {lat: cLat, lng: bestMid};

    // Nothing worked, which should not happen for a real ring. The first
    // vertex is on the polygon and is better than no target at all.
    return {lat: ring[0][0], lng: ring[0][1]};
}

// Metres per degree of latitude, and per degree of longitude at that
// latitude, on the WGS84 ellipsoid — the same reference frame the GPS in
// every phone reports against, so these are the numbers that make a figure
// on screen mean the ground under a rescuer.
//
// What they replace was a flat 111320 for latitude and 111320·cos φ for
// longitude. That is a sphere's number, and it is wrong in the same
// direction everywhere in Greece: at 35°N a degree of latitude is really
// 110.941 m, not 111.320, while a degree of longitude is 91.289 m, not
// 91.189. Checked against the closed-form area of a graticule quadrangle on
// WGS84 — exact, no approximation — that pushed every area here between
// 0.08% (Thrace) and 0.24% (Crete) too HIGH. Small beside GPS error, but a
// bias rather than noise: it never averages out, and it reported a 359.2
// στρέμματα sector as 360.0. With these it is 0.00001%.
//
// They are also what the grid measures its bounding box with, so a cell the
// tool calls 600 m really is 600 m of ground rather than 598.
const WGS84_A = 6378137.0;                  // semi-major axis, metres
const WGS84_E2 = 0.00669437999014;          // first eccentricity squared, f(2−f)
const DEG_TO_RAD = Math.PI / 180;

function metersPerDegreeLat(latDeg) {
    const sinLat = Math.sin(latDeg * DEG_TO_RAD);
    // Meridian radius of curvature M(φ), one degree of it.
    return DEG_TO_RAD * (WGS84_A * (1 - WGS84_E2)) / Math.pow(1 - WGS84_E2 * sinLat * sinLat, 1.5);
}

function metersPerDegreeLng(latDeg) {
    const sinLat = Math.sin(latDeg * DEG_TO_RAD);
    // Prime-vertical radius N(φ) times cos φ — the radius of the parallel.
    // cos is clamped exactly as it was before, so a polygon at the pole
    // cannot produce zero-width grid columns.
    const cosLat = Math.max(0.01, Math.cos(latDeg * DEG_TO_RAD));
    return DEG_TO_RAD * (WGS84_A / Math.sqrt(1 - WGS84_E2 * sinLat * sinLat)) * cosLat;
}

// Flat shoelace over a local equirectangular projection, using the WGS84
// metres-per-degree above taken at the bounding box's own centre latitude —
// deliberately the same projection gridCellsForPolygon() below measures its
// cells with, so the square metres and the "397 × 412 m" printed beside them
// can never disagree about the same rectangle.
//
// Accuracy against the exact ellipsoidal quadrangle area is better than
// 0.001% anywhere in Greece at mission scale, which is two or three orders
// of magnitude inside GPS error — see the accuracy tests, which measure it
// rather than assert a rounded sample. The one thing the flat projection
// still assumes is that an edge drawn between two vertices is straight on
// the map, which is exactly what the person drawing it meant.
// A point on a closed ring addressed by a "ring position": floor(r) is the
// edge index (the edge running from ring[i] to ring[i+1]) and frac(r) is how
// far along that edge, so r = 3 is vertex 3 itself and r = 3.5 is halfway to
// vertex 4. One representation covers both "clicked an existing corner" and
// "clicked partway along an edge" — a corner-only model can never split a
// triangle, since joining any two of its corners is an existing edge.
function pointAtRingPos(ring, r) {
    const n = ring.length;
    const i = ((Math.floor(r) % n) + n) % n;
    const t = r - Math.floor(r);
    const a = ring[i];
    if (t < 1e-9) return a.slice();
    const b = ring[(i + 1) % n];
    return [a[0] + (b[0] - a[0]) * t, a[1] + (b[1] - a[1]) * t];
}

// Cuts a closed ring into two along the straight line between two ring
// positions. Returns [] when either piece degenerates to fewer than three
// points. Both pieces walk their own arc IN RING ORDER and then close over
// the shared cut line, so the two of them tile the original exactly.
//
// That last property is the whole point, and it is what the previous
// inline version in war-room.php got wrong. It built the second piece by
// sweeping i = 0..n and keeping whatever fell outside the first arc, which
// emits (0, 1, …, r1-1, r2+1, …, n-1) whenever the second arc wraps past
// index 0 — in other words, for every cut whose first point was not on the
// ring's very first vertex. That is not a ring order at all: the polygon
// jumps from just before the first cut straight to just after the second and
// folds into a bowtie whose lobes cancel. Shipped in v3.154.3 and invisible
// until a sector's area was put on screen and two halves stopped adding up
// to the whole they came from. Hence the tiling assertions in this
// function's tests: a split that does not conserve area is not a split.
function splitRingAtCutPositions(ring, rA, rB) {
    if (!Array.isArray(ring) || ring.length < 3) return [];
    let r1 = rA, r2 = rB;
    if (r1 > r2) { const tmp = r1; r1 = r2; r2 = tmp; }
    if (r1 === r2) return [];

    const n = ring.length;
    const p1 = pointAtRingPos(ring, r1);
    const p2 = pointAtRingPos(ring, r2);

    // The near arc is a contiguous ascending run, so a plain sweep is
    // already in ring order.
    const polyA = [p1];
    for (let i = 0; i < n; i++) {
        if (i > r1 && i < r2) polyA.push(ring[i]);
    }
    polyA.push(p2);

    // The far arc is the one that wraps, so it is walked outward from the
    // second cut point and round, stopping at the first vertex that belongs
    // to the near arc instead. Same membership test as before; only the
    // order changes.
    const polyB = [p2];
    for (let step = 0; step < n; step++) {
        const i = (Math.floor(r2) + 1 + step) % n;
        if (!(i > r2 || i < r1)) break;
        polyB.push(ring[i]);
    }
    polyB.push(p1);

    if (polyA.length < 3 || polyB.length < 3) return [];
    return [polyA, polyB];
}

function polygonAreaSquareMeters(geo) {
    if (!Array.isArray(geo) || geo.length < 3) return 0;

    const lats = geo.map(pt => Number(pt[0]));
    const lngs = geo.map(pt => Number(pt[1]));
    const centerLat = (Math.min(...lats) + Math.max(...lats)) / 2;
    const mPerDegLat = metersPerDegreeLat(centerLat);
    const mPerDegLng = metersPerDegreeLng(centerLat);

    // Metres relative to the first vertex, not absolute ones. Subtracting the
    // origin up front keeps the cross products small; multiplying raw
    // ~4-million-metre coordinates together and leaning on the subtraction to
    // hand back a few thousand is exactly the shape of a cancellation bug.
    const x = lngs.map(lng => (lng - lngs[0]) * mPerDegLng);
    const y = lats.map(lat => (lat - lats[0]) * mPerDegLat);

    let twiceArea = 0;
    for (let i = 0, j = geo.length - 1; i < geo.length; j = i++) {
        twiceArea += x[j] * y[i] - x[i] * y[j];
    }
    // abs() — a ring drawn clockwise and the same ring drawn the other way
    // round are the same piece of ground. Nothing upstream enforces a winding
    // order: an area is whatever order the admin happened to click in.
    return Math.abs(twiceArea) / 2;
}

// The drawn shape's extent on the ground, as width x height in metres.
//
// Shown beside the area because an area alone cannot be sanity-checked. "500
// τ.μ." looks like a number; "22 × 23 μ" is something an admin can hold
// against a building they know, and a two-pixel over-draw at the zoom a
// composer usually opens at more than doubles a house-sized figure without
// looking any different on screen.
//
// A bounding box, not the polygon's own diameter: crude for an L-shape, and
// exactly right for the question being asked, which is "is this thing about
// fifteen metres across or about twenty-three".
function polygonBoundsSizeMeters(geo) {
    if (!Array.isArray(geo) || geo.length < 2) return null;
    const lats = geo.map(pt => Number(pt[0]));
    const lngs = geo.map(pt => Number(pt[1]));
    const minLat = Math.min(...lats), maxLat = Math.max(...lats);
    const minLng = Math.min(...lngs), maxLng = Math.max(...lngs);
    if (!isFinite(minLat) || !isFinite(minLng)) return null;
    const centerLat = (minLat + maxLat) / 2;
    return {
        w: (maxLng - minLng) * metersPerDegreeLng(centerLat),
        h: (maxLat - minLat) * metersPerDegreeLat(centerLat),
    };
}

// "22 × 23 μ", or "1,4 × 0,9 χλμ" once metres stop being readable. Rounded to
// whole metres below a kilometre: a tenth of a metre is below what anyone can
// click, and printing it would suggest a precision the gesture does not have.
function formatBoundsSize(size) {
    if (!size || !isFinite(size.w) || !isFinite(size.h) || size.w <= 0 || size.h <= 0) return '';
    const km = Math.max(size.w, size.h) >= 1000;
    const num = v => km ? formatAreaNumber(v / 1000, 1) : formatAreaNumber(Math.round(v), 0);
    return num(size.w) + ' × ' + num(size.h) + ' ' + (km ? t('common.unit_km') : t('common.unit_m'));
}

function gridCellsForPolygon(geo, sizeM) {
    const size = Math.max(GRID_SECTOR_SIZE_MIN_M, Math.min(GRID_SECTOR_SIZE_MAX_M, sizeM));

    const lats = geo.map(pt => Number(pt[0]));
    const lngs = geo.map(pt => Number(pt[1]));
    const minLat = Math.min(...lats), maxLat = Math.max(...lats);
    const minLng = Math.min(...lngs), maxLng = Math.max(...lngs);

    const centerLat = (minLat + maxLat) / 2;
    const mPerDegLat = metersPerDegreeLat(centerLat);
    const mPerDegLng = metersPerDegreeLng(centerLat);

    const bboxH = (maxLat - minLat) * mPerDegLat;
    const bboxW = (maxLng - minLng) * mPerDegLng;

    // The ratio is rounded before ceil() on both sides. It is the one place
    // where a last-bit difference between PHP's and JS's cos() would not stay
    // microscopic: on an area whose width divides exactly by the cell size,
    // 8.000000000000001 and 8.0 ceil to 9 and 8 — a whole extra column of
    // sectors on one side and not the other. 9 decimals of a ratio is half a
    // micron of ground; nothing real survives down there to be lost.
    const cols = Math.max(1, Math.ceil(roundTo(bboxW / size, 9)));
    const rows = Math.max(1, Math.ceil(roundTo(bboxH / size, 9)));

    const latStep = (maxLat - minLat) / rows;
    const lngStep = (maxLng - minLng) / cols;

    const cells = [];
    // Cells whose centre missed the area. The server ignores them; the
    // preview draws them dashed, so a coordinator can see the grid's full
    // extent and which corners of it are not being tasked. Produced by the
    // same single loop as the kept ones rather than a second pass, so there
    // is no way for the two sets to disagree about where a cell was.
    const dropped = [];
    for (let r = 0; r < rows; r++) {
        const latTop = maxLat - r * latStep;
        const latBottom = maxLat - (r + 1) * latStep;
        for (let c = 0; c < cols; c++) {
            const lngLeft = minLng + c * lngStep;
            const lngRight = minLng + (c + 1) * lngStep;
            const cell = [
                [roundTo(latTop, 6), roundTo(lngLeft, 6)],
                [roundTo(latTop, 6), roundTo(lngRight, 6)],
                [roundTo(latBottom, 6), roundTo(lngRight, 6)],
                [roundTo(latBottom, 6), roundTo(lngLeft, 6)],
            ];
            if (pointInPolygon((latTop + latBottom) / 2, (lngLeft + lngRight) / 2, geo)) {
                cells.push(cell);
            } else {
                dropped.push(cell);
            }
        }
    }

    return {
        cells,
        dropped,
        cols,
        rows,
        total: cols * rows,
        kept: cells.length,
        requested_m: size,
        actual_w_m: roundTo(bboxW / cols, 1),
        actual_h_m: roundTo(bboxH / rows, 1),
    };
}

function escapeHtml(str) {
    return String(str ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
}

// Shared by the dispatch and route composers' manual-coordinate-entry field —
// accepts whatever separator someone pastes a "lat, lng" pair with (comma,
// space, or both), since that's shared verbatim/read aloud from an outside
// source (a partner-org radio call, a WhatsApp message) rather than typed
// field-by-field. Same bounds + "reject exactly 0,0" rule mission-dispatch.php
// already enforces server-side for admin-drawn points (isValidLatLng) — kept
// here too so a bad paste is caught before a network round-trip, not after.
function parseCoordsInput(raw) {
    const parts = String(raw ?? '').trim().split(/[,\s]+/).map(Number);
    if (parts.length !== 2 || parts.some(n => !isFinite(n))) return null;
    const [lat, lng] = parts;
    if (lat < -90 || lat > 90 || lng < -180 || lng > 180) return null;
    if (lat === 0 && lng === 0) return null;
    return {lat, lng};
}

function formatDistanceMeters(m) {
    if (m === null || m === undefined) return '';
    return m < 1000 ? `${Math.round(m)} ${t('common.unit_m')}` : `${(m / 1000).toFixed(1)} ${t('common.unit_km')}`;
}

// Square metres up to a hectare, then the unit the language actually thinks
// in, then square kilometres. The middle band is the one that matters: a
// search sector is usually a few hundred thousand square metres, which is
// precisely the size nobody can picture written out in square metres. Greek
// says στρέμματα there and English says hectares, so the divisor lives in
// the language file beside the unit name — it is a property of the locale in
// the same way a decimal separator is, not a magic number in this file.
const AREA_TIER_MID_FROM_M2 = 10000;
const AREA_TIER_KM2_FROM_M2 = 1000000;

// Which of the three tiers a set of figures shown TOGETHER should all use:
// whichever one the smallest of them would have picked on its own.
//
// Areas printed side by side are there to be compared, and a sector reading
// "346 στρ." next to a total reading "35.60 τ.χλμ." makes the reader convert
// units before they can see that one is simply 103 of the other. So a group
// commits to one unit — and it has to be the finest one any member needs.
// The small figure is the one actually being decided (how big to make a
// sector); pushing it up into square kilometres renders it as 0.35 and
// destroys the very number the group exists to show, whereas pulling the
// large one down only makes it longer, which grouped thousands then fix.
// The unit an admin pinned in Settings → Γενικές, if this page declares one.
// war-room.php emits AREA_UNIT_PREFERENCE from the stored setting; every
// other context (and the tests) simply has no such global, and 'auto' — the
// shipped default — lets each group choose for itself as described above.
// Read through typeof rather than a bare reference so an undeclared global
// is not a ReferenceError.
function areaUnitPreference() {
    const pref = typeof AREA_UNIT_PREFERENCE !== 'undefined' ? AREA_UNIT_PREFERENCE : 'auto';
    return pref === 'mid' || pref === 'm2' ? pref : 'auto';
}

function areaTierForGroup(valuesM2) {
    const usable = (valuesM2 || []).filter(v => typeof v === 'number' && isFinite(v) && v > 0);
    // Nothing to show is nothing to show, whatever the admin picked.
    if (!usable.length) return null;

    const forced = areaUnitPreference();
    if (forced !== 'auto') return forced;

    const smallest = Math.min(...usable);
    if (smallest < AREA_TIER_MID_FROM_M2) return 'm2';
    if (smallest < AREA_TIER_KM2_FROM_M2) return 'mid';
    return 'km2';
}

// Grouped thousands and the language's own decimal mark. Holding a whole
// group to στρέμματα routinely produces five digits, and 35597 is not a
// number anyone reads at a glance during a callout — Greek writes it 35.597
// and writes a half 16,5, English does the reverse, which is why the locale
// tag sits in the language file beside the units rather than here. The
// formatters are cached because building an Intl.NumberFormat is the
// expensive part and sector popups are rebuilt for every sector on every
// five-second poll tick.
const AREA_NUMBER_FORMATTERS = {};
function formatAreaNumber(value, decimals) {
    const locale = t('common.number_locale') || 'en-GB';
    const key = locale + '/' + decimals;
    if (!AREA_NUMBER_FORMATTERS[key]) {
        AREA_NUMBER_FORMATTERS[key] = new Intl.NumberFormat(locale, {
            minimumFractionDigits: decimals,
            maximumFractionDigits: decimals,
        });
    }
    return AREA_NUMBER_FORMATTERS[key].format(value);
}

// `tier` forces the unit, for a figure being shown alongside others — pass
// areaTierForGroup([…]) over the whole set. Left out, the figure picks its
// own, which is right for a lone number in a popup or a badge.
function formatAreaSquareMeters(m2, tier) {
    if (m2 === null || m2 === undefined || !isFinite(m2) || m2 <= 0) return '';
    const unit = tier || areaTierForGroup([m2]);
    if (unit === 'm2') return `${formatAreaNumber(m2, 0)} ${t('common.unit_area_m2')}`;
    if (unit === 'mid') {
        const perMidUnit = Number(t('common.unit_area_mid_divisor')) || 1000;
        const mid = m2 / perMidUnit;
        // One decimal only while it buys something. At three digits the
        // tenth of a στρέμμα is noise on a number used to size a sweep.
        // Two below ten, because an admin who pinned στρέμματα in Settings
        // still gets shown small plots in them, and 0,05 στρ. has to stay
        // distinguishable from 0,1 rather than collapsing onto it.
        const decimals = mid < 10 ? 2 : (mid < 100 ? 1 : 0);
        return `${formatAreaNumber(mid, decimals)} ${t('common.unit_area_mid')}`;
    }
    const km2 = m2 / 1000000;
    return `${formatAreaNumber(km2, km2 < 100 ? 2 : 0)} ${t('common.unit_area_km2')}`;
}

function bearingToCompassAbbr(deg) {
    if (deg === null || deg === undefined) return '';
    const keys = ['compass.n', 'compass.ne', 'compass.e', 'compass.se', 'compass.s', 'compass.sw', 'compass.w', 'compass.nw'];
    return t(keys[Math.round(deg / 45) % 8]);
}

// Points evenly spaced BY DISTANCE along a line of [lat, lng], both ends
// always included — the places the live map's measuring tool asks the height
// of the ground at. Evenly by distance rather than by vertex, because a route
// is dense with vertices where a road winds and bare along a straight, and a
// climb added up at the vertices would weigh the bends and miss the hills.
// Roughly one sample per stepMetres, never more than maxSamples (the elevation
// service answers 100 at a time), never fewer than the two ends. A two-point
// line — the straight line from Α to Β — is interpolated.
function samplePolylineEvenly(points, maxSamples, stepMetres) {
    if (!Array.isArray(points) || points.length === 0) return [];
    if (points.length === 1) return [[points[0][0], points[0][1]]];
    const segMetres = (a, b) => {
        const midLat = (a[0] + b[0]) / 2;
        return Math.hypot((b[0] - a[0]) * metersPerDegreeLat(midLat), (b[1] - a[1]) * metersPerDegreeLng(midLat));
    };
    const cum = [0];
    for (let i = 1; i < points.length; i++) cum.push(cum[i - 1] + segMetres(points[i - 1], points[i]));
    const total = cum[cum.length - 1];
    const n = Math.max(2, Math.min(maxSamples, Math.ceil(total / stepMetres) + 1));
    const round5 = v => Math.round(v * 1e5) / 1e5;
    const out = [];
    let seg = 1;
    for (let k = 0; k < n; k++) {
        const d = total * k / (n - 1);
        while (seg < points.length - 1 && cum[seg] < d) seg++;
        const a = points[seg - 1], b = points[seg];
        const span = cum[seg] - cum[seg - 1];
        const f = span > 0 ? Math.min(1, Math.max(0, (d - cum[seg - 1]) / span)) : 0;
        out.push([round5(a[0] + (b[0] - a[0]) * f), round5(a[1] + (b[1] - a[1]) * f)]);
    }
    return out;
}

// Total metres climbed and descended over a height profile, plus its lowest
// and highest point. null for a profile too short to have a direction.
function elevationClimb(heights) {
    if (!Array.isArray(heights) || heights.length < 2) return null;
    let ascent = 0, descent = 0;
    for (let i = 1; i < heights.length; i++) {
        const d = heights[i] - heights[i - 1];
        if (d > 0) ascent += d; else descent -= d;
    }
    return {ascent, descent, min: Math.min(...heights), max: Math.max(...heights)};
}

// Naismith's rule, the mountain-rescue rule of thumb: 5 km/h on the level plus
// an hour for every 600 m climbed. It is what the routers' walking times leave
// out — they are the same whether a path runs along a valley or up its side.
// A rough figure for a fit walker on a path; off a path it is a floor, not an
// estimate, which is why the card says what it is.
function naismithMinutes(distanceMetres, ascentMetres) {
    return Math.round(distanceMetres / 1000 * 12 + Math.max(0, ascentMetres) / 10);
}

// Which way the top of the phone points, degrees clockwise from north, from a
// DeviceOrientationEvent — or null when the event cannot say. iOS gives it
// directly (webkitCompassHeading). Elsewhere only an ABSOLUTE event is a
// compass: its alpha is the turn from north, counter-clockwise, so the heading
// is 360 - alpha; a relative event measures from wherever the page happened to
// load. screenAngle (screen.orientation.angle) turns it for a phone held
// sideways. Used by the Action Room's arrow screen («Πυξίδα»).
function compassHeadingFromOrientation(e, screenAngle) {
    if (!e) return null;
    const turn = Number(screenAngle) || 0;
    if (typeof e.webkitCompassHeading === 'number' && !isNaN(e.webkitCompassHeading)) {
        return ((e.webkitCompassHeading + turn) % 360 + 360) % 360;
    }
    if (e.absolute === true && typeof e.alpha === 'number' && !isNaN(e.alpha)) {
        return ((360 - e.alpha + turn) % 360 + 360) % 360;
    }
    return null;
}

// A heading moved a fraction of the way towards the next reading, the short
// way round: raw compass readings jitter by several degrees, and 350° -> 10°
// is a 20° step, not a 340° one.
function smoothHeading(previous, next, factor) {
    if (previous === null || previous === undefined) return next;
    const diff = ((next - previous) % 360 + 540) % 360 - 180;
    return ((previous + diff * factor) % 360 + 360) % 360;
}

// The CSS rotation that shows `angle` next, continuing from `previous` rather
// than jumping back through 0: rotate(355deg) -> rotate(5deg) would spin the
// arrow the long way round; this returns 365.
function unwrapRotation(previous, angle) {
    if (previous === null || previous === undefined) return angle;
    const diff = ((angle - previous) % 360 + 540) % 360 - 180;
    return previous + diff;
}

// `queued` is what the phone is holding for this point and has not been able to
// send yet ({photo, video}, from VoMediaQueue.queuedMediaFor): a file kept on
// the phone counts as taken, otherwise the person who photographed the point
// in a dead zone could not press «Ολοκληρώθηκε». The queue sends the file
// first and the completion after it.
function missingRouteDeliverablesClientSide(wp, noteValue, queued) {
    const missing = [];
    const waiting = queued || {};
    if (wp.require_photo && !wp.photo && !waiting.photo) missing.push(t('route.deliverable_photo'));
    if (wp.require_video && !wp.video && !waiting.video) missing.push(t('route.deliverable_video'));
    if (wp.require_note && !(noteValue || wp.note || '').trim()) missing.push(t('route.deliverable_note'));
    return missing;
}

// Decides whether compressVideoForUpload() (war-room.php) should even
// attempt a re-encode. Two independent reasons to skip, either one is
// enough: the source is already small enough that re-encoding risks making
// it *bigger* for no real benefit, or it's long enough that a realtime-
// bound compression pass (roughly 1x duration) would make someone wait
// longer than just letting the original upload in the background would
// have taken. Unknown/malformed duration (NaN, 0, Infinity — some devices
// report this) is treated as "skip", not "assume short enough to compress".
function shouldSkipVideoCompression(sizeBytes, durationSeconds) {
    const SKIP_AT_OR_UNDER_BYTES = 4 * 1024 * 1024;
    if (sizeBytes <= SKIP_AT_OR_UNDER_BYTES) return true;
    // Deliberately does NOT pass sizeBytes through. The size fallback exists
    // for the rewrap, where the alternative is a file Viber will refuse and
    // the pass is therefore worth running. Upload compression is only ever an
    // optimisation, so an unknown duration stays a skip here: no reason to
    // put a phone in the field through a realtime re-encode it may not need.
    return videoTooLongToReencode(durationSeconds);
}

// The duration half of shouldSkipVideoCompression, split out because the
// container rewrap below needs the same ceiling without the size floor: a
// re-encode runs in realtime, so past a couple of minutes the operator is
// left staring at a progress bar for longer than the problem is worth.
//
// Unknown duration used to count as "too long" outright. That looked
// conservative and was actually self-defeating: a WebM produced by
// MediaRecorder routinely carries NO duration in its header at all —
// <video>.duration reads NaN or Infinity — and those are precisely the files
// the rewrap exists to convert. The guard was refusing the exact case it was
// written to serve, so a stuck WebM stayed WebM and Viber kept dropping it.
//
// Size stands in for duration when the header has none: a field clip small
// enough to sit under the cap cannot be a two-minute video at any bitrate a
// phone records at, so the realtime pass is still bounded. Size unknown too
// means genuinely no information, which does fall back to skipping.
function videoTooLongToReencode(durationSeconds, sizeBytes) {
    const MAX_SECONDS = 120;
    const UNKNOWN_DURATION_MAX_BYTES = 25 * 1024 * 1024;
    if (Number.isFinite(durationSeconds) && durationSeconds > 0) {
        return durationSeconds > MAX_SECONDS;
    }
    return !(Number.isFinite(sizeBytes) && sizeBytes > 0 && sizeBytes <= UNKNOWN_DURATION_MAX_BYTES);
}

// Picks the first MediaRecorder output mimeType this browser can actually
// produce, most- to least-preferred. isSupportedFn is injected (real
// callers pass MediaRecorder.isTypeSupported) since that API doesn't exist
// outside a browser, and this function otherwise has nothing browser-
// specific about it.
function pickVideoCompressionMimeType(candidates, isSupportedFn) {
    for (const candidate of candidates) {
        if (isSupportedFn(candidate)) return candidate;
    }
    return null;
}

// The container can legitimately change across compression (e.g. a .mov
// source re-encoded to webm output), so the upload filename's extension
// must come from the negotiated output mimeType, never copied from the
// original file's own extension.
function videoExtensionForMimeType(mimeType) {
    return mimeType && mimeType.indexOf('mp4') !== -1 ? 'mp4' : 'webm';
}

// Audio containers for the push-to-talk emergency channel, most- to least-
// preferred, and the ordering is the whole point rather than a preference.
//
// MP4/AAC leads because Safari is the browser that cannot fall back: it is the
// only engine here that will not decode Opus-in-WebM, so a clip recorded as
// WebM by an Android volunteer is silent on the coordinator's iPad, and a
// Safari that cannot RECORD anything else leaves the volunteer with no voice
// channel at all. Every browser in play can play back MP4/AAC; not every one
// can play WebM. Where both are recordable, choosing the one everybody can
// hear costs nothing.
//
// Both mp4 spellings are listed for the same reason the video list carries
// four: engines genuinely disagree about which string they answer true to,
// and one that can record MP4 must never be pushed onto WebM merely because
// the first spelling tried was not the one it recognises.
//
// ogg trails as a last resort for an older Firefox, which historically
// reported neither mp4 nor webm audio but would record ogg/opus.
const AUDIO_RECORDER_MIME_CANDIDATES = [
    'audio/mp4;codecs=mp4a.40.2',
    'audio/mp4',
    'audio/webm;codecs=opus',
    'audio/webm',
    'audio/ogg;codecs=opus',
];

// Capture settings for the push-to-talk channel. Every one of these is about
// making a rescuer's words understandable, not about fidelity:
//
//   channelCount 1 — voice is mono. A stereo clip spends half its bits
//   describing a second microphone that heard the same thing, so mono is
//   better quality at the same bitrate, not a compromise.
//
//   noiseSuppression / autoGainControl — the two that matter most outdoors.
//   Wind and a river are exactly what the first one removes, and the second is
//   the difference between someone shouting over a rotor and someone speaking
//   faintly from under a rock being equally audible at the command post.
//
//   sampleRate 48000 — matches what the AAC encoder wants anyway; asking for
//   less makes the browser resample rather than saving anything.
//
// These are plain values, deliberately NOT {exact: …}: a plain value is a hint
// the browser may ignore, while an exact one throws OverconstrainedError on a
// device that cannot meet it. A voice channel must never fail to open because
// a phone would only give stereo.
const VOICE_AUDIO_CONSTRAINTS = {
    channelCount: 1,
    sampleRate: 48000,
    echoCancellation: true,
    noiseSuppression: true,
    autoGainControl: true,
};

// 128 kbps, and the number matters less than what it is spent ON.
//
// Measured on Chrome here, the default for an audio-only recorder was already
// 128 kbps — but STEREO, so roughly 64 kbps per channel, and both channels
// carrying the same voice. Combined with channelCount 1 above, the same 128
// kbps now describes a single channel: double the bits per channel of what
// this produced before, for a file of the same size. That is the actual
// quality win here, not the bitrate figure.
//
// Set explicitly rather than left to the engine because the default is not
// specified anywhere and differs between them — a browser that picks 64 kbps
// stereo would be putting 32 kbps on the voice, which is where consonants
// smear, and consonants are what a place name or a call sign is made of.
//
// ~960KB for the full 60-second maximum, an eighth of what mission-voice.php
// accepts.
const VOICE_AUDIO_BITS_PER_SECOND = 128000;

function voiceRecorderOptions(mimeType) {
    const opts = {audioBitsPerSecond: VOICE_AUDIO_BITS_PER_SECOND};
    // An unsupported mimeType passed to MediaRecorder throws, so a null
    // negotiation result must yield options WITHOUT the key rather than one
    // holding null — the caller then gets the browser's own default container.
    if (mimeType) opts.mimeType = mimeType;
    return opts;
}

// Same rule as videoExtensionForMimeType(): the extension must be derived from
// the NEGOTIATED output type, never guessed, because mission-voice.php checks
// extension and sniffed MIME against each other and rejects a mismatch.
function audioExtensionForMimeType(mimeType) {
    const m = String(mimeType || '').toLowerCase();
    if (m.indexOf('mp4') !== -1 || m.indexOf('m4a') !== -1 || m.indexOf('aac') !== -1) return 'm4a';
    if (m.indexOf('ogg') !== -1) return 'ogg';
    return 'webm';
}

// MediaRecorder output containers, most- to least-preferred. MP4 leads for
// a reason that has nothing to do with file size: WebM is refused outright
// by several of the share targets field crews actually use. Viber takes
// MP4/MOV/3GP and silently drops a .webm handed to it by the OS share
// sheet — from the Action Room that looks exactly like a broken Share
// button rather than a container problem, which is how it was reported.
// Every mp4 spelling any engine accepts is listed because support is
// genuinely inconsistent between them: some report false for the
// codec-qualified string and true for the bare one, others the reverse,
// and a browser that can record MP4 must never be left on WebM just
// because the first spelling tried wasn't the one it recognises.
const MP4_RECORDER_MIME_CANDIDATES = [
    'video/mp4;codecs=avc1.42E01E,mp4a.40.2',
    'video/mp4;codecs=h264,aac',
    'video/mp4;codecs=avc1',
    'video/mp4',
];
const WEBM_RECORDER_MIME_CANDIDATES = [
    'video/webm;codecs=vp8,opus',
    'video/webm',
];

// mp4Only drops the WebM fallbacks entirely, so the caller gets null rather
// than a WebM recorder it didn't ask for — a WebM-to-WebM re-encode costs a
// full realtime pass and leaves the file exactly as unshareable as it
// started, which is strictly worse than not bothering.
function videoCompressionMimeCandidates(mp4Only) {
    return mp4Only
        ? MP4_RECORDER_MIME_CANDIDATES.slice()
        : MP4_RECORDER_MIME_CANDIDATES.concat(WEBM_RECORDER_MIME_CANDIDATES);
}

// True for a video whose container the share sheet can't be trusted with —
// see MP4_RECORDER_MIME_CANDIDATES. Filename is checked alongside the MIME
// type because a file picked off disk (rather than straight out of
// MediaRecorder) routinely arrives with an empty or generic type while
// still being a WebM.
function isUnshareableVideoContainer(mimeType, fileName) {
    return String(mimeType || '').toLowerCase().indexOf('webm') !== -1
        || /\.webm$/i.test(String(fileName || ''));
}

// Decides whether compressPhotoForUpload() (war-room.php) should even
// attempt a re-encode. Two independent reasons to skip: already small
// enough that re-encoding risks making it *bigger* for no benefit (same
// reasoning as shouldSkipVideoCompression's own size floor, just a lower
// number — photos start out much smaller than raw video), or a GIF, whose
// animation would silently break — a canvas draw only ever captures a
// single current frame, so "compressing" an animated GIF would ship a
// still image with no warning that the animation was lost.
function shouldSkipPhotoCompression(sizeBytes, mimeType) {
    const SKIP_AT_OR_UNDER_BYTES = 1.5 * 1024 * 1024;
    if (sizeBytes <= SKIP_AT_OR_UNDER_BYTES) return true;
    if (mimeType === 'image/gif') return true;
    return false;
}

/**
 * Picks the richest share payload the platform will actually accept, so the
 * object handed to navigator.share() is provably the same one canShare()
 * approved. Returns null when no file payload is shareable at all, which the
 * caller treats as "fall back to the link dropdown".
 *
 * The bug this exists to prevent: war-room.php used to test
 * canShare({files}) and then call share({files, text}). Chrome on Windows
 * answers true to the first and rejects the second — its bridge to the OS
 * share sheet does not take files and text together — so the guard passed
 * and the call threw. A photo silently degraded to the text-link dropdown
 * (Viber received a bare link, never the image) and a rewrapped video, whose
 * branch had no fallback, did nothing at all.
 *
 * The caption is the half worth dropping when the two disagree: the file is
 * the point of the share, the caption is a nicety the operator can retype.
 *
 * canShare is injected rather than read off navigator so this stays pure and
 * testable, same convention as the other extracted helpers here.
 */
function shareablePayload(file, caption, canShare) {
    const withCaption = { files: [file], text: caption };
    if (canShare(withCaption)) return withCaption;
    const filesOnly = { files: [file] };
    if (canShare(filesOnly)) return filesOnly;
    return null;
}

/**
 * True only on a pointer-driven computer: no native app bridge, not a phone
 * or tablet, and a real mouse or trackpad. That is exactly where the OS share
 * sheet cannot deliver a media file — Chrome on Windows accepts the file and
 * then hands the target a link or nothing, which is why the media Share
 * control is dropped there entirely in favour of the Download button.
 *
 * Written to fail SAFE: every uncertain case returns false, keeping the Share
 * button. Losing it on a field volunteer's phone is far worse than leaving a
 * dead button on someone's desktop, so nothing but a positive identification
 * of a mouse-driven computer counts.
 *
 * Signals are injected rather than read off navigator/window so this stays
 * pure and testable, same convention as the other helpers here.
 */
function isPointerOnlyComputer({ hasNativeBridge, uaDataMobile, userAgent, maxTouchPoints, hasFinePointer }) {
    // The installed Android app shares through Capacitor's native plugin,
    // which works fine — never touch it.
    if (hasNativeBridge) return false;
    // The browser's own answer, where it gives one, beats any sniffing.
    if (uaDataMobile === true) return false;
    if (/Android|iPhone|iPad|iPod|Mobile|Silk|Kindle/i.test(userAgent || '')) return false;
    // iPadOS 13+ reports itself as "Macintosh"; a Mac reporting more than one
    // touch point is really an iPad.
    if (/Macintosh/i.test(userAgent || '') && (maxTouchPoints || 0) > 1) return false;
    // A touchscreen Windows laptop still has a fine pointer and still has the
    // broken desktop share sheet, so touch alone does not disqualify — but
    // without a fine pointer this cannot be a computer.
    return hasFinePointer === true;
}

/**
 * How long a single spoken utterance is allowed to be, in characters.
 *
 * Roughly eleven seconds of Greek at the rate announcements use, which keeps
 * every piece well under the fifteen seconds at which Chrome stops speaking a
 * single utterance.
 */
const SPEECH_CHUNK_CHARS = 170;

/**
 * Break text into sentence-sized pieces to be queued as separate utterances.
 *
 * A long utterance gets cut three different ways and none of them announce
 * themselves: Chrome stops after about fifteen seconds, some Android engines
 * truncate anything long outright, and a remote voice that loses the network
 * mid-sentence takes the whole remainder with it. In every case the listener
 * simply hears a message that stops, and takes what they heard for the whole
 * of it — which in an operation means acting on half an instruction. Short
 * pieces queued in order survive all three: the engine's own queue does the
 * sequencing, and no single piece is long enough to be cut.
 *
 * Breaks at a sentence end first, then at a comma, then at a space. Never
 * inside a word, which is heard as a stutter rather than as a pause.
 *
 * Deliberately no lookbehind regex: Safari before 16.4 throws a SyntaxError
 * while PARSING one, which would take down the whole script it sits in rather
 * than just this function.
 */
function speechChunks(text) {
    // «;» is the Greek question mark — U+003B as typed and U+037E as some
    // tokenisers emit it — and «·» its semicolon. Both end a thought.
    const SENTENCE_ENDS = '.!;\u037E\u00b7';
    let rest = String(text == null ? '' : text).trim();
    const out = [];

    while (rest.length > SPEECH_CHUNK_CHARS) {
        const head = rest.slice(0, SPEECH_CHUNK_CHARS);
        let cut = -1;

        for (let i = head.length - 1; i > 0; i--) {
            if (SENTENCE_ENDS.indexOf(head[i]) !== -1 && /\s/.test(head[i + 1] || ' ')) {
                cut = i + 1;
                break;
            }
        }
        // A sentence end in the first third is not worth obeying: it leaves a
        // piece so short that the pause after it sounds like the end of the
        // message.
        if (cut < SPEECH_CHUNK_CHARS / 3) {
            const comma = head.lastIndexOf(',');
            cut = comma > SPEECH_CHUNK_CHARS / 3 ? comma + 1 : head.lastIndexOf(' ');
        }
        if (cut <= 0) cut = SPEECH_CHUNK_CHARS;

        const piece = rest.slice(0, cut).trim();
        if (piece) out.push(piece);
        rest = rest.slice(cut).trim();
    }

    if (rest) out.push(rest);
    return out;
}

/**
 * Whether the pause()/resume() nudge may be used on this device.
 *
 * That nudge exists to work around DESKTOP Chrome stopping a long utterance
 * after about fifteen seconds. On Chrome for Android pause() does not pause:
 * it stops, and resume() does not bring the voice back — so the very code that
 * keeps a desktop talking is what silences a phone, roughly ten seconds in.
 * That is the exact shape of the failure reported from the field: fine on a
 * laptop, cut short on a mobile.
 *
 * Nothing is lost by skipping it there, because announcements are already
 * broken into pieces short enough that no single one reaches the fifteen
 * seconds the nudge was protecting against.
 */
function speechKeepAliveIsSafe({ userAgent, uaDataMobile }) {
    // The browser's own answer first where it exists; it is not spoofable by
    // a desktop-mode toggle the way the string is.
    if (uaDataMobile === true) return false;
    if (uaDataMobile === false) return true;
    return !/Android|iPhone|iPad|iPod|Mobile|Silk|Kindle/i.test(userAgent || '');
}

/**
 * How long a piece is given to finish before the chain moves on without it.
 *
 * Some engines simply never deliver onend, and a chain waiting for one stops
 * the message dead halfway with nothing on screen to say why. Generous on
 * purpose — advancing early talks over the piece still playing, which is worse
 * than a pause — so this is roughly double the time the words actually take.
 */
function speechPieceTimeoutMs(piece) {
    return Math.round(String(piece || '').length * 140) + 4000;
}

/**
 * How long until the OpenStreetMap layer looks again by itself, when something of it is still to come (a tile that
 * was being fetched by another request, or one the server could not get): the next of the `retryAts` (times in ms
 * at which a tile may be asked for again) that has not run out yet, half a second after it, and ONE SECOND from now
 * when every one of them has. That last case is a pass that went on longer than the pauses it set (its chunks took
 * seconds each at a public map server): with no future time to wait for, nothing asked again until the map was
 * moved, and the chip said «loading» for as long as nobody touched it.
 */
function osmRetryDelayMs(retryAts, now) {
    const future = (retryAts || []).filter(at => at > now);
    return future.length ? Math.min(...future) - now + 500 : 1000;
}

if (typeof module !== 'undefined' && module.exports) {
    module.exports = {
        osmRetryDelayMs,
        shareablePayload,
        isPointerOnlyComputer,
        bearing,
        destinationPoint,
        circleToPolygonPoints,
        sectorSearchLegPoints,
        sectorSearchLegCount,
        ringDiscPolygonPoints,
        pointInPolygon,
        metersPerDegreeLat,
        metersPerDegreeLng,
        gridCellsForPolygon,
        polygonNavPoint,
        polygonAreaSquareMeters,
        polygonBoundsSizeMeters,
        formatBoundsSize,
        pointAtRingPos,
        splitRingAtCutPositions,
        weightedWedgePolygonPoints,
        annularWedgePolygonPoints,
        escapeHtml,
        parseCoordsInput,
        formatDistanceMeters,
        formatAreaSquareMeters,
        areaTierForGroup,
        areaUnitPreference,
        bearingToCompassAbbr,
        samplePolylineEvenly,
        elevationClimb,
        naismithMinutes,
        compassHeadingFromOrientation,
        smoothHeading,
        unwrapRotation,
        missingRouteDeliverablesClientSide,
        shouldSkipVideoCompression,
        videoTooLongToReencode,
        pickVideoCompressionMimeType,
        videoExtensionForMimeType,
        videoCompressionMimeCandidates,
        isUnshareableVideoContainer,
        MP4_RECORDER_MIME_CANDIDATES,
        WEBM_RECORDER_MIME_CANDIDATES,
        AUDIO_RECORDER_MIME_CANDIDATES,
        audioExtensionForMimeType,
        VOICE_AUDIO_CONSTRAINTS,
        VOICE_AUDIO_BITS_PER_SECOND,
        voiceRecorderOptions,
        shouldSkipPhotoCompression,
        speechChunks,
        SPEECH_CHUNK_CHARS,
        speechKeepAliveIsSafe,
        speechPieceTimeoutMs,
    };
}

/**
 * War Room (Action Room) - «AR Προβολή Πεδίου»: the camera view that puts an
 * order's target, the sector/area boundary, the other teams and the danger
 * zones on top of the real mountain.
 *
 * It is a fourth way to follow an order, beside the map, the 🚗/🚶 directions
 * and the «Πυξίδα» arrow, and it reads the very same model they do: every row
 * of «Οι Εντολές μου» carries a `target` ({kind: point|polygon|line, nav}) in
 * opEntries (war-room.php), so any order that has a place shows up here with
 * no setting of its own. The steps (Ελήφθη → Ξεκινάω → Έφτασα →
 * Ολοκληρώθηκε) go through opAct(), the one function the popup uses.
 *
 * Two halves. The first is pure geometry (phone orientation -> where a point
 * on the ground lands on the screen), unit-tested in tests/js/war-room-ar.test.js
 * (`node --test tests/js`). The second is the screen, which uses the page's own
 * globals (opEntries, opAct, t(), nearbyTeams, restrictedAreas, ...) at call
 * time, exactly like war-room-console.js does.
 *
 * No WebXR and no 3D library: an Android WebView has no WebXR, and a 2D canvas
 * over the camera picture is all a beacon and a few walls need. The camera
 * frames never leave the phone.
 */

// ── Pure geometry ────────────────────────────────────────────────────────────

const AR_EARTH_R = 6371000;
const AR_EYE_HEIGHT_M = 1.5;
// Vertical field of view of a typical phone back camera along the long side of
// the picture; the screen shows the picture "cover"-cropped, so this is the
// field of view along the screen's longer side.
const AR_FOV_LONG_SIDE_DEG = 64;

// East/north metres of `point` from `origin` (both {lat, lng}). Flat-earth is
// exact enough inside the few kilometres an order is ever away.
function arLocal(origin, point) {
    const rad = Math.PI / 180;
    const dLat = (point.lat - origin.lat) * rad;
    const dLng = (point.lng - origin.lng) * rad;
    return {
        x: dLng * Math.cos((origin.lat + point.lat) / 2 * rad) * AR_EARTH_R,
        y: dLat * AR_EARTH_R,
    };
}

// The way back: a spot `x` metres east and `y` north of `origin`.
function arFromLocal(origin, x, y) {
    const rad = Math.PI / 180;
    return {
        lat: origin.lat + (y / AR_EARTH_R) / rad,
        lng: origin.lng + (x / (AR_EARTH_R * Math.cos(origin.lat * rad))) / rad,
    };
}

// The phone's three axes in the earth frame (x east, y north, z up) from a
// DeviceOrientationEvent's alpha/beta/gamma (degrees): the W3C rotation
// Rz(alpha)·Rx(beta)·Ry(gamma). The back camera looks along -Z of the phone.
// screenAngle (screen.orientation.angle) is how far the screen is turned from
// portrait, so «right» and «up» are the ones the person looks at, not the ones
// printed on the phone. Returns {r, u, f}: screen-right, screen-up, view
// direction.
function arBasis(alpha, beta, gamma, screenAngle) {
    const rad = Math.PI / 180;
    const a = alpha * rad, b = beta * rad, g = gamma * rad;
    const ca = Math.cos(a), sa = Math.sin(a), cb = Math.cos(b), sb = Math.sin(b), cg = Math.cos(g), sg = Math.sin(g);
    const X = [ca * cg - sa * sg * sb, sa * cg + ca * sg * sb, -sg * cb];
    const Y = [-sa * cb, ca * cb, sb];
    const Z = [ca * sg + sa * sb * cg, sa * sg - ca * sb * cg, cb * cg];
    const s = (Number(screenAngle) || 0) * rad, cs = Math.cos(s), ss = Math.sin(s);
    return {
        r: [cs * X[0] - ss * Y[0], cs * X[1] - ss * Y[1], cs * X[2] - ss * Y[2]],
        u: [cs * Y[0] + ss * X[0], cs * Y[1] + ss * X[1], cs * Y[2] + ss * X[2]],
        f: [-Z[0], -Z[1], -Z[2]],
    };
}

// Degrees clockwise from north the camera faces, and how far above the horizon.
function arHeading(basis) {
    return ((Math.atan2(basis.f[0], basis.f[1]) * 180 / Math.PI) % 360 + 360) % 360;
}
function arPitch(basis) {
    return Math.asin(Math.max(-1, Math.min(1, basis.f[2]))) * 180 / Math.PI;
}

// Pixels of focal length for a canvas of cw x ch: the long side of the screen
// spans AR_FOV_LONG_SIDE_DEG.
function arFocalPx(cw, ch, fovDeg) {
    return Math.max(cw, ch) / (2 * Math.tan((fovDeg || AR_FOV_LONG_SIDE_DEG) / 2 * Math.PI / 180));
}

// Where a vector from the eye to something (east, north, up in metres) lands on
// a cw x ch screen, or null when it is behind the camera. `depth` is how far in
// front of the lens it is, for sorting far to near.
function arProject(v, basis, focal, cw, ch) {
    const depth = v[0] * basis.f[0] + v[1] * basis.f[1] + v[2] * basis.f[2];
    if (depth <= 0.5) return null;
    const xc = v[0] * basis.r[0] + v[1] * basis.r[1] + v[2] * basis.r[2];
    const yc = v[0] * basis.u[0] + v[1] * basis.u[1] + v[2] * basis.u[2];
    return {x: cw / 2 + focal * xc / depth, y: ch / 2 - focal * yc / depth, depth: depth};
}

// Compass bearing, flat distance and elevation angle (degrees) of a vector.
function arBearingElevation(v) {
    const d = Math.hypot(v[0], v[1]);
    return {
        az: ((Math.atan2(v[0], v[1]) * 180 / Math.PI) % 360 + 360) % 360,
        el: Math.atan2(v[2], Math.max(d, 0.001)) * 180 / Math.PI,
        d: d,
    };
}

// The signed turn (-180..180) from the way the camera faces to a bearing.
function arTurnTo(headingDeg, bearingDeg) {
    return ((bearingDeg - headingDeg) % 360 + 540) % 360 - 180;
}

// A polyline cut into pieces no longer than maxLen, as [a, b] pairs of
// {x, y, z}; `closed` joins the last point back to the first.
function arSubdivide(points, maxLen, closed) {
    const pieces = [];
    const n = points.length;
    const last = closed ? n : n - 1;
    for (let i = 0; i < last; i++) {
        const a = points[i], b = points[(i + 1) % n];
        const len = Math.hypot(b.x - a.x, b.y - a.y);
        const k = Math.max(1, Math.ceil(len / maxLen));
        for (let s = 0; s < k; s++) {
            const t0 = s / k, t1 = (s + 1) / k;
            pieces.push([
                {x: a.x + (b.x - a.x) * t0, y: a.y + (b.y - a.y) * t0, z: a.z + (b.z - a.z) * t0},
                {x: a.x + (b.x - a.x) * t1, y: a.y + (b.y - a.y) * t1, z: a.z + (b.z - a.z) * t1},
            ]);
        }
    }
    return pieces;
}

// The closest spot on a polygon's outline to `me`: {lat, lng, dist}. geo is
// [[lat, lng], ...] as the orders carry it.
// With open set it is an open line (a cliff edge): the last point is not joined to the first.
function arNearestOnPolygon(me, geo, open) {
    const pts = geo.map(p => arLocal(me, {lat: Number(p[0]), lng: Number(p[1])}));
    let best = null;
    for (let i = 0; i < (open ? pts.length - 1 : pts.length); i++) {
        const a = pts[i], b = pts[(i + 1) % pts.length];
        const vx = b.x - a.x, vy = b.y - a.y, l2 = vx * vx + vy * vy;
        const t = l2 ? Math.max(0, Math.min(1, (-a.x * vx - a.y * vy) / l2)) : 0;
        const x = a.x + t * vx, y = a.y + t * vy, dist = Math.hypot(x, y);
        if (!best || dist < best.dist) best = {x: x, y: y, dist: dist};
    }
    if (!best) return null;
    const p = arFromLocal(me, best.x, best.y);
    return {lat: p.lat, lng: p.lng, dist: best.dist};
}

// Which kind of orientation event this is, judged from the event alone:
// 'absolute' (alpha is measured from north), 'ios' (a relative alpha beside
// webkitCompassHeading) or null (relative to wherever the page loaded: no
// compass). It keeps no state on purpose. Android sends BOTH a
// deviceorientationabsolute and a relative deviceorientation stream; a rule that
// said "an absolute event has been seen, so accept the rest" let the relative
// stream through, and the beacon flipped between two headings every frame.
function arEventKind(e) {
    if (!e || typeof e.alpha !== 'number' || typeof e.beta !== 'number' || typeof e.gamma !== 'number') return null;
    if (e.absolute === true) return 'absolute';
    if (typeof e.webkitCompassHeading === 'number' && !isNaN(e.webkitCompassHeading)) return 'ios';
    return null;
}

// The next reading blended into the last one, as the three axes themselves, and
// made orthonormal again. Never blend the Euler angles instead: a phone held
// upright (beta near 90) is the singular pose of that convention, where alpha
// and gamma swap large values for a tiny physical movement, so averaging them
// one by one throws the camera sideways while the axes underneath barely move.
// k is the share of the new reading, 0..1.
function arSmoothBasis(prev, next, k) {
    if (!prev) return next;
    const mix = (a, b) => [a[0] * (1 - k) + b[0] * k, a[1] * (1 - k) + b[1] * k, a[2] * (1 - k) + b[2] * k];
    const norm = v => { const l = Math.hypot(v[0], v[1], v[2]); return l > 1e-9 ? [v[0] / l, v[1] / l, v[2] / l] : null; };
    const f = norm(mix(prev.f, next.f));
    const uRaw = mix(prev.u, next.u);
    if (!f) return next;
    const d = uRaw[0] * f[0] + uRaw[1] * f[1] + uRaw[2] * f[2];
    const u = norm([uRaw[0] - d * f[0], uRaw[1] - d * f[1], uRaw[2] - d * f[2]]);
    if (!u) return next;
    // The phone's axes are right, up and back (-f): right = forward x up.
    const r = [f[1] * u[2] - f[2] * u[1], f[2] * u[0] - f[0] * u[2], f[0] * u[1] - f[1] * u[0]];
    return {r: r, u: u, f: f};
}

// How hard to smooth a compass reading, as a time constant in seconds, by how far
// the new reading is from where the view is now. A phone held still wanders by a
// degree or two and must look still; a real turn leaves the view behind by more
// than that and is followed at once. The distance, not the change since the last
// event, decides it: a slow turn changes by a fraction of a degree per event.
function arSmoothingTau(offsetDeg) {
    if (offsetDeg < 1) return 0.6;
    if (offsetDeg < 2.5) return 0.3;
    if (offsetDeg < 6) return 0.15;
    return 0.06;
}

// The angle in degrees between two camera directions.
function arAngleBetween(a, b) {
    const d = a.f[0] * b.f[0] + a.f[1] * b.f[1] + a.f[2] * b.f[2];
    return Math.acos(Math.max(-1, Math.min(1, d))) * 180 / Math.PI;
}

// A new GPS fix blended into the position the screen uses. A receiver held
// still wanders by its own error, and close to the target a few metres is a big
// swing of the beam, so a fix inside its own error moves the position only a
// little; one outside it, or any fix while walking, is followed. fix is
// {lat, lng, acc}; speed is metres per second or null.
function arSmoothFix(prev, fix, speed) {
    if (!prev) return {lat: fix.lat, lng: fix.lng, acc: fix.acc};
    const l = arLocal(prev, fix), d = Math.hypot(l.x, l.y);
    const acc = Math.max(5, fix.acc || 20);
    const walking = typeof speed === 'number' && !isNaN(speed) && speed >= 0.7;
    const g = d > 2 * acc ? 0.8 : (walking ? 0.5 : (d > acc ? 0.3 : 0.12));
    return {lat: prev.lat + (fix.lat - prev.lat) * g, lng: prev.lng + (fix.lng - prev.lng) * g, acc: fix.acc};
}

// ── Is the compass any good? ──
// A browser cannot ask the phone how well its compass is calibrated (Android
// gives no accuracy at all), so three things stand in for it. iOS reports one
// (webkitCompassAccuracy). The magnetic field strength says whether something
// is bending the field: Earth's is 25-65 microtesla, and a car, a steel
// railing, a speaker or a power line pushes it outside that. And a phone held
// still whose compass still wanders is a compass that is not sure.

// microtesla -> 'good' | 'fair' | 'poor' | null (no reading).
function arFieldQuality(ut) {
    if (typeof ut !== 'number' || isNaN(ut)) return null;
    if (ut >= 25 && ut <= 65) return 'good';
    if (ut >= 20 && ut <= 75) return 'fair';
    return 'poor';
}

// Root mean square of a list of offsets, in degrees; null for none.
function arJitterRms(offsets) {
    if (!offsets || !offsets.length) return null;
    return Math.sqrt(offsets.reduce((sum, v) => sum + v * v, 0) / offsets.length);
}

// The worst of the signals that have a reading. jitterDeg is only meaningful
// for a phone that is held still (null otherwise); iosAccuracy is degrees, with
// a negative number meaning iOS has no heading at all. reasons names every
// signal that is not good, worst first.
function arCompassQuality(signals) {
    const order = {good: 0, fair: 1, poor: 2};
    const found = [];
    const field = arFieldQuality(signals.fieldUt);
    if (field && field !== 'good') found.push({level: field, reason: 'field'});
    if (typeof signals.jitterDeg === 'number') {
        const level = signals.jitterDeg <= 2.5 ? 'good' : (signals.jitterDeg <= 5 ? 'fair' : 'poor');
        if (level !== 'good') found.push({level: level, reason: 'jitter'});
    }
    if (typeof signals.iosAccuracy === 'number') {
        const a = signals.iosAccuracy;
        const level = a < 0 ? 'poor' : (a <= 15 ? 'good' : (a <= 30 ? 'fair' : 'poor'));
        if (level !== 'good') found.push({level: level, reason: 'ios'});
    }
    found.sort((a, b) => order[b.level] - order[a.level]);
    return {level: found.length ? found[0].level : 'good', reasons: found.map(f => f.reason)};
}

// ── The screen ───────────────────────────────────────────────────────────────

const AR_COL = {green: [74, 222, 128], cyan: [34, 211, 238], red: [248, 81, 73], orange: [255, 145, 60], amber: [251, 191, 36], white: [240, 246, 242], grey: [160, 170, 165]};
const AR_FONT = 'system-ui, -apple-system, "Segoe UI", Roboto, sans-serif';
const AR_MOVING_MPS = 1.5;        // faster than this and the screen dims: look where you are going
const AR_STILL_MPS = 0.8;         // and it stays dimmed until the speed has fallen below this
const AR_CALIB_SHOW_AFTER_MS = 5000;  // a poor compass must stay poor this long before the screen says so
const AR_CALIB_CLEAR_AFTER_MS = 4000; // and a good one this long before it takes it back
const AR_COMPASS_STALE_MS = 15000; // a phone held still sends nothing: a quiet compass is not a dead one
const AR_SESSION_MS = 10 * 60000; // camera + GPS + sensors heat a phone; ask before going on
const AR_SECTOR_OUT_M = 100;      // the same distance the server warns at (checkSectorExitWarnings)
const AR_MAX_ELEV_POINTS = 90;
const AR_LINE_BREACH_M = 10;      // within this of a restricted LINE is "at the edge" (RESTRICTED_LINE_BREACH_M in functions-warroom.php)
const AR_SAFETY_KEY = 'wrArSafetySeen';
let arState = null;

function arRgba(c, a) { return 'rgba(' + c[0] + ',' + c[1] + ',' + c[2] + ',' + a + ')'; }
function arClamp(v, a, b) { return Math.max(a, Math.min(b, v)); }
function arKey(lat, lng) { return Number(lat).toFixed(5) + ',' + Number(lng).toFixed(5); }

// Can this phone do it at all: a camera, orientation sensors, a touchscreen
// (a mouse-only computer has the APIs but no compass), and a secure page.
function arSupported() {
    return !!(window.isSecureContext && navigator.mediaDevices && navigator.mediaDevices.getUserMedia
        && 'DeviceOrientationEvent' in window && (navigator.maxTouchPoints || 0) > 0);
}

// Orders that have a place and are still open: what the pager steps through.
function arOrderKeys() {
    const keys = [];
    opEntries.forEach((m, key) => {
        if (m.target && m.target.nav && (m.outstanding || m.kind === 'arrive')) keys.push(key);
    });
    return keys;
}

// «danger» for a zone drawn before kinds existed, or one with a kind this screen does not know.
function arZoneKind(area) {
    return area && (area.kind === 'cliff' || area.kind === 'forbidden') ? area.kind : 'danger';
}

function arElement() {
    let el = document.getElementById('wrAr');
    if (el) return el;
    el = document.createElement('div');
    el.id = 'wrAr';
    el.className = 'wr-ar';
    el.hidden = true;
    el.setAttribute('role', 'dialog');
    el.setAttribute('aria-modal', 'true');
    el.dataset.look = t('ar.look_ahead');
    el.dataset.lower = t('ar.raise_phone');
    // Translated strings only; everything from an order is set as text below.
    el.innerHTML = `<video class="wr-ar-video" playsinline muted autoplay></video>
        <canvas class="wr-ar-world"></canvas>
        <div class="wr-ar-top">
            <div class="wr-ar-toprow">
                <button type="button" class="wr-ar-x" data-ar="close" aria-label="${escapeHtml(t('arrow.close'))}"><i class="bi bi-x-lg"></i></button>
                <div class="wr-ar-steps"></div>
                <div class="wr-ar-pager" hidden>
                    <button type="button" data-ar="prev" aria-label="${escapeHtml(t('popup.prev'))}"><i class="bi bi-chevron-left"></i></button>
                    <span class="wr-ar-pagerlabel"></span>
                    <button type="button" data-ar="next" aria-label="${escapeHtml(t('popup.next'))}"><i class="bi bi-chevron-right"></i></button>
                </div>
            </div>
            <div class="wr-ar-banner"></div>
            <canvas class="wr-ar-tape"></canvas>
        </div>
        <div class="wr-ar-warn" hidden></div>
        <div class="wr-ar-rail">
            <button type="button" class="wr-ar-chip" data-ar-layer="bound" aria-pressed="true">${escapeHtml(t('ar.layer_bound'))}</button>
            <button type="button" class="wr-ar-chip" data-ar-layer="team" aria-pressed="true">${escapeHtml(t('ar.layer_team'))}</button>
            <button type="button" class="wr-ar-chip" data-ar-layer="haz" aria-pressed="true">${escapeHtml(t('ar.layer_haz'))}</button>
        </div>
        <canvas class="wr-ar-radar"></canvas>
        <div class="wr-ar-bottom">
            <button type="button" class="wr-ar-calibpill" data-ar="calib" hidden></button>
            <div class="wr-ar-tiles">
                <div class="wr-ar-tile"><small>${escapeHtml(t('ar.tile_dist'))}</small><b data-ar-k="dist"></b></div>
                <div class="wr-ar-tile"><small>${escapeHtml(t('ar.tile_dz'))}</small><b data-ar-k="dz"></b></div>
                <div class="wr-ar-tile"><small>${escapeHtml(t('ar.tile_eta'))}</small><b data-ar-k="eta"></b></div>
                <button type="button" class="wr-ar-tile wr-ar-tilebtn" data-ar="debug"><small>${escapeHtml(t('ar.tile_gps'))}</small><b data-ar-k="gps"></b></button>
            </div>
            <div class="wr-ar-debug" data-ar-k="debug" hidden></div>
            <div class="wr-ar-title" data-ar-k="title"></div>
            <div class="wr-ar-hint" data-ar-k="hint"></div>
            <button type="button" class="wr-ar-go" data-ar="go" hidden></button>
            <div class="wr-ar-links">
                <button type="button" data-ar="compass"><i class="bi bi-compass me-1"></i>${escapeHtml(t('arrow.open_btn'))}</button>
                <button type="button" data-ar="calib">${escapeHtml(t('ar.calib_link'))}</button>
                <button type="button" data-ar="decline" hidden>${escapeHtml(t('decline.btn'))}</button>
            </div>
        </div>
        <div class="wr-ar-calib" hidden>
            <div class="wr-ar-calibbox">
                <div class="wr-ar-calibtitle">${escapeHtml(t('ar.calib_title'))}</div>
                <svg class="wr-ar-fig8" viewBox="0 0 200 100" aria-hidden="true"><path d="M100,50 C100,18 40,18 40,50 C40,82 100,82 100,50 C100,18 160,18 160,50 C160,82 100,82 100,50 Z"/><circle class="wr-ar-fig8dot" r="6"/></svg>
                <ol class="wr-ar-calibsteps">
                    <li>${escapeHtml(t('ar.calib_step_away'))}</li>
                    <li>${escapeHtml(t('ar.calib_step_wave'))}</li>
                    <li>${escapeHtml(t('ar.calib_step_hold'))}</li>
                </ol>
                <div class="wr-ar-calibstatus" aria-live="polite"></div>
                <button type="button" class="wr-ar-go" data-ar="calibdone">${escapeHtml(t('ar.calib_done'))}</button>
            </div>
        </div>
        <div class="wr-ar-msg" hidden><div class="wr-ar-msgbox"><div class="wr-ar-msgtext"></div><button type="button" class="wr-ar-go" data-ar="msgok"></button></div></div>`;
    document.body.appendChild(el);
    el.addEventListener('click', e => {
        const layer = e.target.closest('[data-ar-layer]');
        if (layer && arState) {
            arState.layers[layer.dataset.arLayer] = !arState.layers[layer.dataset.arLayer];
            layer.setAttribute('aria-pressed', arState.layers[layer.dataset.arLayer] ? 'true' : 'false');
            return;
        }
        const btn = e.target.closest('[data-ar]');
        if (!btn || !arState) return;
        switch (btn.dataset.ar) {
            case 'close': arClose(); break;
            case 'prev': arStep(-1); break;
            case 'next': arStep(1); break;
            case 'go': arGo(btn); break;
            case 'msgok': arMessageDone(); break;
            case 'debug': arState.debug = !arState.debug; break;
            case 'calib': arOpenCalib(); break;
            case 'calibdone': arCloseCalib(); break;
            case 'compass': { const m = opEntries.get(arState.key); arClose(); if (m) arrowOpen(m); break; }
            case 'decline': { const m = opEntries.get(arState.key); arClose(); if (m) opAct('decline', m, btn); break; }
        }
    });
    return el;
}

// With no order it opens the free view: the other teams and the danger zones around the person, nothing to steer to.
function arOpen(m) {
    if (m && !(m.target && m.target.nav)) return;
    arClose();
    const el = arElement();
    arState = {
        key: m ? m.key : null, layers: {bound: true, team: true, haz: true},
        pose: null, orientAt: 0, absolute: false, iosOffset: 0, sm: null, compass: {samples: [], fieldUt: null, fieldAt: 0, iosAcc: null, jitter: null, level: 'good', poorSince: 0, goodSince: 0, reasons: [], at: 0, shown: false, openedAt: 0}, mag: null, fastFixes: 0, evUsed: 0, evIgnored: 0, evRate: '', evAt: 0, debug: false,
        fix: null, watchId: null, stream: null, wakeLock: null, raf: 0, onOrient: null, onKey: null, onResize: null,
        elev: new Map(), meElev: null, meElevAt: null, elevBusy: false, elevKey: '',
        openedAt: Date.now(), elevAt: -1e9, message: null, moving: false, warnSig: '', cw: 0, ch: 0, dpr: 1,
    };
    el.hidden = false;
    document.body.classList.add('wr-ar-open');
    el.classList.remove('wr-ar-dim');
    el.querySelectorAll('[data-ar-layer]').forEach(b => b.setAttribute('aria-pressed', 'true'));
    arResize();
    arState.onResize = () => arResize();
    window.addEventListener('resize', arState.onResize);
    arState.onOrient = e => arOnOrientation(e);
    window.addEventListener('deviceorientationabsolute', arState.onOrient);
    window.addEventListener('deviceorientation', arState.onOrient);
    // iOS asks before handing out the sensors, and only inside a tap; this runs
    // from the popup button's click. Chrome has the function too and may answer
    // "denied" while still delivering events, so listening never waits for it
    // (same trap as arrowOpen()).
    if (typeof DeviceOrientationEvent !== 'undefined' && typeof DeviceOrientationEvent.requestPermission === 'function') {
        DeviceOrientationEvent.requestPermission().catch(() => {});
    }
    const video = el.querySelector('.wr-ar-video');
    navigator.mediaDevices.getUserMedia({video: {facingMode: {ideal: 'environment'}}, audio: false}).then(stream => {
        if (!arState) { stream.getTracks().forEach(tr => tr.stop()); return; }
        arState.stream = stream;
        video.srcObject = stream;
        video.play().catch(() => {});
    }).catch(() => { if (arState) arShowMessage(t('ar.no_camera'), t('popup.btn.got_it')); });
    if (navigator.geolocation) {
        arState.watchId = navigator.geolocation.watchPosition(pos => {
            if (!arState) return;
            arState.fix = pos;
            const speed = pos.coords.speed;
            arState.sm = arSmoothFix(arState.sm, {lat: pos.coords.latitude, lng: pos.coords.longitude, acc: pos.coords.accuracy}, speed);
            // A vague fix can report a speed it does not have; it takes two fast fixes in a row, from a fix good
            // enough to trust, to dim the screen, and a speed that has fallen back to bring it back.
            const fast = typeof speed === 'number' && !isNaN(speed) && speed >= AR_MOVING_MPS && (pos.coords.accuracy || 99) <= 30;
            arState.fastFixes = fast ? arState.fastFixes + 1 : 0;
            if (arState.fastFixes >= 2) arState.moving = true;
            else if (!(typeof speed === 'number' && speed >= AR_STILL_MPS)) arState.moving = false;
            arLoadElevations(false);
        }, () => {}, {enableHighAccuracy: true, maximumAge: 0, timeout: 20000});
    }
    if (navigator.wakeLock && navigator.wakeLock.request) {
        navigator.wakeLock.request('screen')
            .then(lock => { if (arState) arState.wakeLock = lock; else lock.release().catch(() => {}); })
            .catch(() => {});
    }
    arStartMagnetometer();
    arState.onKey = e => { if (e.key === 'Escape') arClose(); };
    document.addEventListener('keydown', arState.onKey);
    let seen = false;
    try { seen = !!localStorage.getItem(AR_SAFETY_KEY); } catch (e) { /* private window: ask every time */ }
    if (!seen) arShowMessage(t('ar.safety'), t('popup.btn.got_it'), () => { try { localStorage.setItem(AR_SAFETY_KEY, '1'); } catch (e) { /* ignore */ } });
    arState.raf = requestAnimationFrame(arFrame);
}

function arClose() {
    const el = document.getElementById('wrAr');
    if (el) {
        el.hidden = true;
        const video = el.querySelector('.wr-ar-video');
        if (video) video.srcObject = null;
    }
    document.body.classList.remove('wr-ar-open');
    if (!arState) return;
    cancelAnimationFrame(arState.raf);
    if (arState.onOrient) {
        window.removeEventListener('deviceorientationabsolute', arState.onOrient);
        window.removeEventListener('deviceorientation', arState.onOrient);
    }
    if (arState.onResize) window.removeEventListener('resize', arState.onResize);
    if (arState.onKey) document.removeEventListener('keydown', arState.onKey);
    if (arState.watchId !== null && navigator.geolocation) navigator.geolocation.clearWatch(arState.watchId);
    if (arState.stream) arState.stream.getTracks().forEach(tr => tr.stop());
    if (arState.wakeLock) arState.wakeLock.release().catch(() => {});
    if (arState.mag) { try { arState.mag.stop(); } catch (e) { /* already stopped */ } }
    arState = null;
}

function arStep(dir) {
    const keys = arOrderKeys();
    if (!keys.length) return;
    if (arState.key === null) {
        arState.key = dir > 0 ? keys[0] : keys[keys.length - 1]; // from the free view into an order
    } else {
        if (keys.length < 2) return;
        const i = Math.max(0, keys.indexOf(arState.key));
        arState.key = keys[(i + dir + keys.length) % keys.length];
    }
    arState.elevKey = '';
    arLoadElevations(true);
}

let arMessageThen = null;
function arShowMessage(text, okLabel, then) {
    const box = document.querySelector('#wrAr .wr-ar-msg');
    if (!box) return;
    box.querySelector('.wr-ar-msgtext').textContent = text;
    box.querySelector('.wr-ar-go').textContent = okLabel;
    box.hidden = false;
    arMessageThen = then || null;
    if (arState) arState.message = text;
}
function arMessageDone() {
    const box = document.querySelector('#wrAr .wr-ar-msg');
    if (box) box.hidden = true;
    const then = arMessageThen;
    arMessageThen = null;
    if (arState) { arState.message = null; if (arState.timedOut) { arState.timedOut = false; arState.openedAt = Date.now(); } }
    if (then) then();
}

function arResize() {
    const el = document.getElementById('wrAr');
    if (!el || !arState) return;
    arState.dpr = Math.min(2, window.devicePixelRatio || 1);
    arState.cw = el.clientWidth;
    arState.ch = el.clientHeight;
    const world = el.querySelector('.wr-ar-world');
    world.width = Math.round(arState.cw * arState.dpr);
    world.height = Math.round(arState.ch * arState.dpr);
    const tape = el.querySelector('.wr-ar-tape'), radar = el.querySelector('.wr-ar-radar');
    tape.width = Math.round(tape.clientWidth * arState.dpr); tape.height = Math.round(tape.clientHeight * arState.dpr);
    radar.width = Math.round(radar.clientWidth * arState.dpr); radar.height = Math.round(radar.clientHeight * arState.dpr);
}

// Phone orientation -> the three axes. Android gives an absolute event (alpha
// from north); iOS gives webkitCompassHeading next to a relative alpha, so the
// two are lined up while the phone stands upright. A relative event on its own
// is no compass, and neither is nothing: the screen then says so.
function arOnOrientation(e) {
    const kind = arEventKind(e);
    if (arState && !kind) arState.evIgnored++;
    if (!arState || !kind) return;
    arState.evUsed++;
    const screenAngle = arScreenAngle();
    let alpha = e.alpha;
    if (kind === 'ios') {
        const raw = arBasis(alpha, e.beta, e.gamma, screenAngle);
        if (e.beta > 55 && e.beta < 125) {
            const off = e.webkitCompassHeading - arHeading(raw);
            arState.iosOffset = smoothHeading(arState.iosOffset || 0, ((off % 360) + 360) % 360, 0.2);
        }
        alpha = e.alpha - arState.iosOffset;
    }
    arState.absolute = true;
    // Time-based, so the steadiness does not depend on how often the phone reports (about 120 ms to settle).
    const now = Date.now();
    const dt = arState.orientAt ? Math.min(0.25, Math.max(0.008, (now - arState.orientAt) / 1000)) : 1;
    const raw = arBasis(alpha, e.beta, e.gamma, screenAngle);
    const prevBasis = arState.pose ? arState.pose.basis : null;
    const tau = prevBasis ? arSmoothingTau(arAngleBetween(prevBasis, raw)) : 0.06;
    const basis = arSmoothBasis(prevBasis, raw, 1 - Math.exp(-dt / tau));
    arState.pose = {basis: basis, screenAngle: screenAngle};
    // How far each reading is from where the view settled: on a phone held still, that is the compass's own noise.
    if (prevBasis) arState.compass.samples.push({t: now, off: arAngleBetween(prevBasis, raw), f: basis.f});
    if (kind === 'ios' && typeof e.webkitCompassAccuracy === 'number') arState.compass.iosAcc = e.webkitCompassAccuracy;
    arState.orientAt = now;
}
// The magnetometer, on a browser that exposes it (Chrome on Android does, over
// https, without asking). Its field strength is the interference check; a phone
// or browser without it simply never reports one.
function arStartMagnetometer() {
    try {
        if (typeof Magnetometer === 'undefined') return;
        const sensor = new Magnetometer({frequency: 10});
        sensor.addEventListener('reading', () => {
            if (!arState) return;
            const ut = Math.hypot(sensor.x, sensor.y, sensor.z);
            const c = arState.compass;
            c.fieldUt = c.fieldUt === null ? ut : c.fieldUt + (ut - c.fieldUt) * 0.2;
            c.fieldAt = Date.now();
        });
        sensor.addEventListener('error', () => {});
        sensor.start();
        arState.mag = sensor;
    } catch (e) { /* no permission, or no such sensor: the other two signals still work */ }
}

// Called every frame, works four times a second. A poor compass has to stay
// poor for a few seconds before the screen says so, and a good one has to stay
// good before it takes it back, so a single bad second does not flash a warning.
function arUpdateCompass() {
    const c = arState.compass, now = Date.now();
    if (now - c.at < 250) return;
    c.at = now;
    while (c.samples.length && c.samples[0].t < now - 3000) c.samples.shift();
    c.jitter = null;
    const n = c.samples.length;
    if (n >= 20 && c.samples[n - 1].t - c.samples[0].t >= 1500 && arAngleBetween({f: c.samples[0].f}, {f: c.samples[n - 1].f}) < 3) {
        c.jitter = arJitterRms(c.samples.map(s => s.off));
    }
    if (c.fieldUt !== null && now - c.fieldAt > 5000) c.fieldUt = null; // the sensor went quiet
    const q = arCompassQuality({fieldUt: c.fieldUt, jitterDeg: c.jitter, iosAccuracy: c.iosAcc});
    c.level = q.level; c.reasons = q.reasons;
    if (q.level === 'poor') {
        c.goodSince = 0;
        if (!c.poorSince) c.poorSince = now;
        if (now - c.poorSince >= AR_CALIB_SHOW_AFTER_MS) c.shown = true;
    } else {
        c.poorSince = 0;
        if (!c.goodSince) c.goodSince = now;
        if (now - c.goodSince >= AR_CALIB_CLEAR_AFTER_MS) c.shown = false;
    }
}

function arCalibReasonText(c) {
    const first = c.reasons[0];
    if (first === 'field') return t('ar.calib_reason_field', {ut: Math.round(c.fieldUt === null ? 0 : c.fieldUt)});
    if (first === 'jitter') return t('ar.calib_reason_jitter', {deg: c.jitter === null ? '?' : c.jitter.toFixed(1)});
    if (first === 'ios') return t('ar.calib_reason_ios', {deg: c.iosAcc === null ? '?' : Math.round(c.iosAcc)});
    return t('ar.calib_pill');
}

function arOpenCalib() {
    const box = document.querySelector('#wrAr .wr-ar-calib');
    if (!box || !arState) return;
    arState.compass.openedAt = Date.now();
    box.hidden = false;
}
function arCloseCalib() {
    const box = document.querySelector('#wrAr .wr-ar-calib');
    if (box) box.hidden = true;
    if (arState) arState.compass.openedAt = 0;
}

// The pill under the compass strip, and the figure-8 dialog's live status line.
function arUpdateCompassHud(el) {
    const c = arState.compass;
    const pill = el.querySelector('.wr-ar-calibpill');
    pill.hidden = !c.shown;
    if (c.shown) {
        const text = '\u26A0 ' + arCalibReasonText(c) + ' \u00B7 ' + t('ar.calib_pill_tap');
        if (pill.textContent !== text) pill.textContent = text;
    }
    const box = el.querySelector('.wr-ar-calib');
    if (box.hidden) return;
    const status = box.querySelector('.wr-ar-calibstatus');
    const good = c.level === 'good' && c.goodSince && Date.now() - c.goodSince >= 2000;
    const text = good ? t('ar.calib_good') : (c.level === 'good' ? t('ar.calib_checking') : arCalibReasonText(c));
    if (status.textContent !== text) status.textContent = text;
    status.classList.toggle('is-good', !!good);
    // It is good again after the shake: the dialog has done its job.
    if (good && Date.now() - c.openedAt > 6000) arCloseCalib();
}

function arScreenAngle() {
    return (screen.orientation && typeof screen.orientation.angle === 'number') ? screen.orientation.angle : (Number(window.orientation) || 0);
}

function arMe() {
    const pos = arState.fix || (typeof latestAutoPosition !== 'undefined' ? latestAutoPosition : null);
    if (!pos || !pos.coords || Date.now() - pos.timestamp > 2 * 60000) return null;
    // The smoothed position once there is one (see arSmoothFix), else the raw fix.
    if (arState.fix && arState.sm) return {lat: arState.sm.lat, lng: arState.sm.lng, acc: arState.sm.acc};
    return {lat: pos.coords.latitude, lng: pos.coords.longitude, acc: pos.coords.accuracy};
}

// ── ground heights ──
// The height difference comes from terrain data for BOTH ends, never from the
// phone's GPS altitude (15-30 m off). One request on open and on a change of
// order, plus my own spot again after every 100 m; a miss just means flat.
function arCollectPoints(m, me) {
    const pts = [];
    const seen = new Set();
    const add = (lat, lng) => {
        if (pts.length >= AR_MAX_ELEV_POINTS) return;
        const k = arKey(lat, lng);
        if (seen.has(k) || arState.elev.has(k)) return;
        seen.add(k);
        pts.push([Number(Number(lat).toFixed(5)), Number(Number(lng).toFixed(5))]);
    };
    const decimate = (list, max) => { const step = Math.max(1, Math.ceil(list.length / max)); return list.filter((_, i) => i % step === 0); };
    if (me) add(me.lat, me.lng);
    if (m && m.target) {
        add(m.target.nav.lat, m.target.nav.lng);
        if (m.target.pts) decimate(m.target.pts, 24).forEach(p => add(p[0], p[1]));
    }
    (nearbyTeams || []).slice(0, 12).forEach(tm => add(tm.lat, tm.lng));
    (restrictedAreas || []).forEach(a => decimate(a.geo || [], 16).forEach(p => add(p[0], p[1])));
    return pts;
}
function arLoadElevations(force) {
    if (!arState || arState.elevBusy || typeof measureElevations !== 'function') return;
    const me = arMe();
    if (!me) return;
    const moved = arState.meElevAt ? arLocal(arState.meElevAt, me) : null;
    const meStale = !moved || Math.hypot(moved.x, moved.y) > 100;
    if (!force && !meStale && arState.elevKey === arState.key) return;
    const m = opEntries.get(arState.key);
    const pts = arCollectPoints(m, meStale ? me : null);
    arState.elevKey = arState.key;
    if (!pts.length) return;
    arState.elevBusy = true;
    measureElevations(pts).then(heights => {
        if (!arState) return;
        arState.elevBusy = false;
        if (!heights) return;
        pts.forEach((p, i) => { if (heights[i] !== null && heights[i] !== undefined) arState.elev.set(arKey(p[0], p[1]), Number(heights[i])); });
        const k = arKey(me.lat, me.lng);
        if (arState.elev.has(k)) { arState.meElev = arState.elev.get(k); arState.meElevAt = {lat: me.lat, lng: me.lng}; }
    });
}
// Height of a spot above where I stand, or 0 when the terrain lookup missed.
function arZ(lat, lng) {
    if (arState.meElev === null) return 0;
    const e = arState.elev.get(arKey(lat, lng));
    return e === undefined ? 0 : e - arState.meElev;
}
function arVec(me, lat, lng, extraZ) {
    const l = arLocal(me, {lat: Number(lat), lng: Number(lng)});
    return {x: l.x, y: l.y, z: arZ(lat, lng) + (extraZ || 0) - AR_EYE_HEIGHT_M};
}

// ── the order's next button ──
function arAction(m, near) {
    if (!m) return null;
    if (m.kind === 'arrive') return {op: 'arrived', label: t('popup.btn.arrived'), needNear: true, enabled: near};
    if (!m.outstanding) return null;
    if (!m.acked && m.kind !== 'info') return {op: 'ack', label: t('banner.ack_btn'), enabled: true};
    if (m.kind === 'dispatch') {
        if (m.canDepart) return {op: 'depart', label: t('dispatch.depart_btn'), enabled: true};
        if (m.canArrive) return {op: 'arrive', label: t('dispatch.arrival_btn'), needNear: true, enabled: near};
        if (m.canComplete) return {op: 'dcomplete', label: t('dispatch.complete_btn'), enabled: true};
    }
    if (m.kind === 'route' && m.nextWaypointOpen) return {op: 'route_arrive', label: t('popup.btn.arrived'), needNear: true, enabled: near};
    if (m.kind === 'sector' && m.advanceStatus) return {op: 'advance', label: m.advanceLabel, enabled: true};
    if (m.type === 'task') return {op: 'complete', label: t('mytasks.complete_btn'), enabled: true};
    return null;
}
function arGo(btn) {
    const m = opEntries.get(arState.key);
    const info = arState.scene;
    const act = arAction(m, info && info.near);
    if (!m || !act || !act.enabled) return;
    btn.disabled = true;
    if (act.op === 'route_arrive') { routeArrive(m.nextWaypointId, false); opToast(t('popup.arrived_toast')); }
    else { opAct(act.op, m, btn); if (act.op === 'arrive' || act.op === 'arrived') opToast(t('popup.arrived_toast')); }
    if (act.op === 'arrived' || act.op === 'route_arrive') arClose();
}

// ── drawing ──
function arLabelCard(ctx, cw, ch, cx, y, lines, col) {
    let w = 0;
    lines.forEach(l => { ctx.font = (l.b ? '700 13px ' : '500 12px ') + AR_FONT; w = Math.max(w, ctx.measureText(l.t).width); });
    w += 18;
    const h = lines.length * 16 + 10;
    const x = arClamp(cx - w / 2, 6, cw - 6 - w);
    y = arClamp(y, 190, ch - 250);
    ctx.beginPath();
    if (ctx.roundRect) ctx.roundRect(x, y, w, h, 9); else ctx.rect(x, y, w, h);
    ctx.fillStyle = 'rgba(8,12,10,.68)'; ctx.fill();
    ctx.strokeStyle = arRgba(col, .9); ctx.lineWidth = 1.2; ctx.stroke();
    ctx.textBaseline = 'middle'; ctx.textAlign = 'left';
    lines.forEach((l, i) => {
        ctx.font = (l.b ? '700 13px ' : '500 12px ') + AR_FONT;
        ctx.fillStyle = l.c ? arRgba(l.c, 1) : '#f2f6f3';
        ctx.fillText(l.t, x + 9, y + 13 + i * 16);
    });
}

// A tall pillar of light from the sky down to a spot on the ground.
function arDrawBeacon(S, p, opt) {
    const ctx = S.ctx;
    const w = arClamp(S.focal * (opt.big ? 3.6 : 1.6) / Math.max(5, p.depth), opt.big ? 5 : 3, opt.big ? 46 : 14);
    const top = opt.top !== undefined ? opt.top : -20;
    const gr = ctx.createLinearGradient(0, top, 0, p.y);
    gr.addColorStop(0, arRgba(opt.color, 0)); gr.addColorStop(.55, arRgba(opt.color, opt.big ? .34 : .28)); gr.addColorStop(1, arRgba(opt.color, opt.big ? .85 : .7));
    ctx.fillStyle = gr; ctx.fillRect(p.x - w / 2, top, w, p.y - top);
    ctx.fillStyle = arRgba(AR_COL.white, opt.big ? .75 : .5); ctx.fillRect(p.x - Math.max(1, w * .12), top, Math.max(2, w * .24), p.y - top);
    const rg = ctx.createRadialGradient(p.x, p.y, 1, p.x, p.y, w * 1.6 + 6);
    rg.addColorStop(0, arRgba(opt.color, .75)); rg.addColorStop(1, arRgba(opt.color, 0));
    ctx.fillStyle = rg; ctx.fillRect(p.x - w * 2 - 8, p.y - w * 2 - 8, w * 4 + 16, w * 4 + 16);
    if (opt.ring) {
        const rx = arClamp(S.focal * ARROW_ARRIVE_RADIUS_M / Math.max(5, p.depth), 8, 160);
        const pulse = 1 + .06 * Math.sin(S.now / 260);
        ctx.strokeStyle = arRgba(opt.color, .9); ctx.lineWidth = 2; ctx.beginPath(); ctx.ellipse(p.x, p.y, rx * pulse, rx * .2 * pulse, 0, 0, 7); ctx.stroke();
        ctx.strokeStyle = arRgba(opt.color, .3); ctx.lineWidth = 6; ctx.beginPath(); ctx.ellipse(p.x, p.y, rx * pulse, rx * .2 * pulse, 0, 0, 7); ctx.stroke();
    }
    if (opt.lines) arLabelCard(ctx, S.cw, S.ch, p.x, p.y - 100, opt.lines, opt.color);
    if (opt.tag) {
        const ty = Math.max(14, top > 0 ? top : p.y - 44);
        ctx.fillStyle = 'rgba(8,12,10,.7)'; ctx.beginPath(); ctx.arc(p.x, ty, 9, 0, 7); ctx.fill();
        ctx.strokeStyle = arRgba(opt.color, 1); ctx.lineWidth = 1.5; ctx.stroke();
        ctx.fillStyle = '#fff'; ctx.font = '700 11px ' + AR_FONT; ctx.textAlign = 'center'; ctx.textBaseline = 'middle'; ctx.fillText(opt.tag, p.x, ty + .5); ctx.textAlign = 'left';
    }
}

// A wall standing on a polygon's outline (closed) or a line (open): the ground
// itself is only guessed on a slope, but a wall reads from far away.
function arDrawWall(S, vecs, color, height, closed, fence) {
    const ctx = S.ctx;
    const out = [];
    arSubdivide(vecs, 16, closed).forEach(pc => {
        const lo0 = [pc[0].x, pc[0].y, pc[0].z], lo1 = [pc[1].x, pc[1].y, pc[1].z];
        const p0 = arProject(lo0, S.basis, S.focal, S.cw, S.ch), p1 = arProject(lo1, S.basis, S.focal, S.cw, S.ch);
        const t0 = arProject([lo0[0], lo0[1], lo0[2] + height], S.basis, S.focal, S.cw, S.ch);
        const t1 = arProject([lo1[0], lo1[1], lo1[2] + height], S.basis, S.focal, S.cw, S.ch);
        if (!p0 || !p1 || !t0 || !t1) return;
        out.push({d: (p0.depth + p1.depth) / 2, p0: p0, p1: p1, t0: t0, t1: t1});
    });
    out.sort((a, b) => b.d - a.d);
    out.forEach(q => {
        const al = arClamp(1.15 - q.d / 1500, .35, 1);
        ctx.beginPath(); ctx.moveTo(q.p0.x, q.p0.y); ctx.lineTo(q.p1.x, q.p1.y); ctx.lineTo(q.t1.x, q.t1.y); ctx.lineTo(q.t0.x, q.t0.y); ctx.closePath();
        ctx.fillStyle = arRgba(color, (fence ? .3 : .17) * al); ctx.fill();
        ctx.strokeStyle = arRgba(color, .95 * al); ctx.lineWidth = fence ? 2.4 : 2; ctx.beginPath(); ctx.moveTo(q.p0.x, q.p0.y); ctx.lineTo(q.p1.x, q.p1.y); ctx.stroke();
        ctx.strokeStyle = arRgba(color, .55 * al); ctx.lineWidth = fence ? 2.4 : 1.2; ctx.beginPath(); ctx.moveTo(q.t0.x, q.t0.y); ctx.lineTo(q.t1.x, q.t1.y); ctx.stroke();
        ctx.strokeStyle = arRgba(color, (fence ? .8 : .22) * al); ctx.lineWidth = fence ? 1.6 : 1;
        if (fence) { ctx.beginPath(); ctx.moveTo((q.p0.x + q.t0.x) / 2, (q.p0.y + q.t0.y) / 2); ctx.lineTo((q.p1.x + q.t1.x) / 2, (q.p1.y + q.t1.y) / 2); ctx.stroke(); }
        ctx.beginPath(); ctx.moveTo(q.p0.x, q.p0.y); ctx.lineTo(q.t0.x, q.t0.y); ctx.stroke();
    });
    return out;
}

function arDrawTeam(S, tm, me) {
    const ctx = S.ctx;
    const v = arVec(me, tm.lat, tm.lng, 2);
    const flat = arBearingElevation([v.x, v.y, v.z]);
    const col = tm.is_stale ? AR_COL.grey : AR_COL.cyan;
    const label = String(tm.label || '');
    const dist = formatDistanceMeters(flat.d);
    const p = arProject([v.x, v.y, v.z], S.basis, S.focal, S.cw, S.ch);
    if (!p || p.x < -20 || p.x > S.cw + 20) { S.edges.push({turn: arTurnTo(S.heading, flat.az), label: label + ' ' + dist, col: col}); return; }
    const g = arProject([v.x, v.y, v.z - 2], S.basis, S.focal, S.cw, S.ch);
    const s = arClamp(S.focal * 6.6 / Math.max(20, p.depth) / 10, 9, 17);
    if (g) { ctx.setLineDash([3, 4]); ctx.strokeStyle = arRgba(col, .6); ctx.lineWidth = 1.2; ctx.beginPath(); ctx.moveTo(p.x, p.y); ctx.lineTo(g.x, g.y); ctx.stroke(); ctx.setLineDash([]); }
    const gl = ctx.createRadialGradient(p.x, p.y, 1, p.x, p.y, s * 2.4); gl.addColorStop(0, arRgba(col, .5)); gl.addColorStop(1, arRgba(col, 0));
    ctx.fillStyle = gl; ctx.fillRect(p.x - s * 3, p.y - s * 3, s * 6, s * 6);
    ctx.beginPath(); ctx.moveTo(p.x, p.y - s); ctx.lineTo(p.x + s * .8, p.y); ctx.lineTo(p.x, p.y + s); ctx.lineTo(p.x - s * .8, p.y); ctx.closePath();
    ctx.fillStyle = arRgba(col, tm.is_stale ? .25 : .38); ctx.fill();
    ctx.strokeStyle = arRgba(col, 1); ctx.lineWidth = 2; ctx.setLineDash(tm.is_stale ? [3, 3] : []); ctx.stroke(); ctx.setLineDash([]);
    const lines = [{t: label, b: true}, {t: dist + (tm.is_stale && tm.time ? ' · ' + String(tm.time).slice(0, 5) : ''), c: col}];
    arLabelCard(ctx, S.cw, S.ch, p.x, p.y - s - 52, lines, col);
}

function arDrawEdges(S) {
    const ctx = S.ctx;
    const cnt = {left: 0, right: 0};
    S.edges.forEach(e => {
        const right = e.turn > 0, idx = right ? cnt.right++ : cnt.left++, y = S.ch * .42 + idx * 32;
        ctx.font = '600 11.5px ' + AR_FONT;
        const w = ctx.measureText(e.label).width + 30, x = right ? S.cw - 5 - w : 5;
        ctx.beginPath(); if (ctx.roundRect) ctx.roundRect(x, y, w, 24, 12); else ctx.rect(x, y, w, 24);
        ctx.fillStyle = 'rgba(8,12,10,.72)'; ctx.fill(); ctx.strokeStyle = arRgba(e.col, 1); ctx.lineWidth = 1.5; ctx.stroke();
        ctx.textBaseline = 'middle'; ctx.textAlign = 'left';
        ctx.fillStyle = arRgba(e.col, 1); ctx.fillText(right ? '›' : '‹', right ? x + w - 13 : x + 8, y + 11.5);
        ctx.fillStyle = '#f2f6f3'; ctx.fillText(e.label, right ? x + 9 : x + 20, y + 12);
    });
}

// Compass strip across the top: ticks, the cardinal letters, and a mark for
// everything the screen knows about, even what is out of the picture.
function arDrawTape(S) {
    const cv = document.querySelector('#wrAr .wr-ar-tape');
    const ctx = cv.getContext('2d'), w = cv.clientWidth, h = cv.clientHeight;
    ctx.setTransform(S.dpr, 0, 0, S.dpr, 0, 0); ctx.clearRect(0, 0, w, h);
    const sc = w / 100;
    ctx.fillStyle = 'rgba(8,12,10,.62)'; ctx.fillRect(0, 0, w, h);
    const names = {0: t('compass.n'), 45: t('compass.ne'), 90: t('compass.e'), 135: t('compass.se'), 180: t('compass.s'), 225: t('compass.sw'), 270: t('compass.w'), 315: t('compass.nw')};
    ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
    for (let d = Math.floor(S.heading - 52); d <= Math.ceil(S.heading + 52); d++) {
        if (d % 5) continue;
        const dd = ((d % 360) + 360) % 360, x = w / 2 + (d - S.heading) * sc, big = dd % 15 === 0;
        ctx.strokeStyle = 'rgba(255,255,255,' + (big ? .85 : .4) + ')'; ctx.lineWidth = 1; ctx.beginPath(); ctx.moveTo(x, h); ctx.lineTo(x, big ? h - 10 : h - 5); ctx.stroke();
        if (dd % 45 === 0) { ctx.fillStyle = '#fff'; ctx.font = '700 12px ' + AR_FONT; ctx.fillText(names[dd], x, 12); }
        else if (dd % 15 === 0) { ctx.fillStyle = 'rgba(255,255,255,.7)'; ctx.font = '500 10px ' + AR_FONT; ctx.fillText(String(dd), x, 12); }
    }
    S.marks.forEach(mk => {
        const turn = arTurnTo(S.heading, mk.az);
        if (Math.abs(turn) > 50) return;
        const x = w / 2 + turn * sc;
        ctx.fillStyle = arRgba(mk.col, 1); ctx.beginPath();
        if (mk.shape === 'tri') { ctx.moveTo(x - 6, h - 20); ctx.lineTo(x + 6, h - 20); ctx.lineTo(x, h - 9); }
        else if (mk.shape === 'dia') { ctx.moveTo(x, h - 20); ctx.lineTo(x + 5, h - 14); ctx.lineTo(x, h - 8); ctx.lineTo(x - 5, h - 14); }
        else { ctx.rect(x - 3.5, h - 18, 7, 7); }
        ctx.closePath(); ctx.fill();
    });
    ctx.fillStyle = '#fff'; ctx.beginPath(); ctx.moveTo(w / 2 - 5, 0); ctx.lineTo(w / 2 + 5, 0); ctx.lineTo(w / 2, 6); ctx.closePath(); ctx.fill();
}

function arDrawRadar(S, me) {
    const cv = document.querySelector('#wrAr .wr-ar-radar');
    const ctx = cv.getContext('2d'), w = cv.clientWidth, h = cv.clientHeight;
    ctx.setTransform(S.dpr, 0, 0, S.dpr, 0, 0); ctx.clearRect(0, 0, w, h);
    const cx = w / 2, cy = h / 2, r = Math.min(w, h) / 2 - 3, range = 800;
    ctx.fillStyle = 'rgba(8,12,10,.62)'; ctx.beginPath(); ctx.arc(cx, cy, r + 2, 0, 7); ctx.fill();
    ctx.strokeStyle = 'rgba(255,255,255,.3)'; ctx.lineWidth = 1; ctx.beginPath(); ctx.arc(cx, cy, r, 0, 7); ctx.stroke(); ctx.beginPath(); ctx.arc(cx, cy, r / 2, 0, 7); ctx.stroke();
    const half = 31;
    ctx.fillStyle = 'rgba(255,255,255,.12)'; ctx.beginPath(); ctx.moveTo(cx, cy); ctx.arc(cx, cy, r, (-90 - half) * Math.PI / 180, (-90 + half) * Math.PI / 180); ctx.closePath(); ctx.fill();
    const at = (lat, lng) => {
        const l = arLocal(me, {lat: Number(lat), lng: Number(lng)});
        const be = arBearingElevation([l.x, l.y, 0]);
        const a = (arTurnTo(S.heading, be.az) - 90) * Math.PI / 180, rr = Math.min(r - 2, be.d / range * r);
        return {x: cx + rr * Math.cos(a), y: cy + rr * Math.sin(a)};
    };
    S.radar.forEach(item => {
        if (item.poly) {
            ctx.strokeStyle = arRgba(item.col, .95); ctx.lineWidth = 1.5; ctx.beginPath();
            item.poly.forEach((p, i) => { const q = at(p[0], p[1]); if (i) ctx.lineTo(q.x, q.y); else ctx.moveTo(q.x, q.y); });
            if (!item.open) ctx.closePath();
            ctx.stroke();
        } else {
            const q = at(item.lat, item.lng);
            ctx.fillStyle = arRgba(item.col, 1); ctx.beginPath(); ctx.arc(q.x, q.y, item.size, 0, 7); ctx.fill();
        }
    });
    ctx.fillStyle = '#fff'; ctx.beginPath(); ctx.moveTo(cx, cy - 5); ctx.lineTo(cx + 4, cy + 4); ctx.lineTo(cx - 4, cy + 4); ctx.closePath(); ctx.fill();
}

// ── one frame ──
function arFrame(now) {
    if (!arState) return;
    arState.raf = requestAnimationFrame(arFrame);
    const el = document.getElementById('wrAr');
    const m = arState.key === null ? null : opEntries.get(arState.key);
    // Finished or withdrawn while open: the next open order, or the way out.
    if (arState.key !== null && (!m || !m.target || !m.target.nav)) {
        const keys = arOrderKeys();
        if (keys.length) { arState.key = keys[0]; arState.elevKey = ''; } else { arClose(); }
        return;
    }
    const me = arMe();
    // The ground heights, once a spot is known and again when it has moved far or the order changed.
    if (now - arState.elevAt > 2500) { arState.elevAt = now; arLoadElevations(false); }
    // Browsers send orientation events only when the reading changes, so a phone held still goes quiet: the last
    // pose stays good for a long while instead of the beam vanishing and coming back with the next wobble.
    const poseFresh = arState.pose && Date.now() - arState.orientAt < AR_COMPASS_STALE_MS;
    const world = el.querySelector('.wr-ar-world');
    const ctx = world.getContext('2d');
    ctx.setTransform(arState.dpr, 0, 0, arState.dpr, 0, 0);
    ctx.clearRect(0, 0, arState.cw, arState.ch);
    el.classList.toggle('wr-ar-dim', arState.moving);
    arUpdateStaticHud(el, m);
    arUpdateCompass();
    arUpdateCompassHud(el);
    if (Date.now() - arState.openedAt > AR_SESSION_MS && !arState.message) {
        arState.timedOut = true;
        arShowMessage(t('ar.timeout'), t('ar.continue'));
    }
    if (!me || !poseFresh) {
        arUpdateDynamicHud(el, m, null);
        const note = !arState.pose ? t('ar.no_compass') : t('ar.compass_lost');
        el.querySelector('.wr-ar-banner').textContent = me ? note : t('arrow.no_fix');
        return;
    }
    const basis = arState.pose.basis;
    const S = {
        ctx: ctx, cw: arState.cw, ch: arState.ch, dpr: arState.dpr, now: now, basis: basis,
        focal: arFocalPx(arState.cw, arState.ch), heading: arHeading(basis), pitch: arPitch(basis),
        edges: [], marks: [], radar: [],
    };
    const scene = arDrawWorld(S, m, me);
    arState.scene = scene;
    arDrawEdges(S);
    arDrawTape(S);
    arDrawRadar(S, me);
    arUpdateDynamicHud(el, m, S);
}

// The order's own things, then the layers around it. Returns what the HUD
// needs to say: distance, height difference, whether «Έφτασα» is in reach.
function arDrawWorld(S, m, me) {
    if (!m) {
        const free = {free: true, dist: 0, dz: 0, near: false, inside: false, out: false, hazard: null};
        arDrawLayers(S, me, free);
        return free;
    }
    const ctx = S.ctx, t0 = m.target;
    const nav = t0.nav;
    const navV = arVec(me, nav.lat, nav.lng);
    const flatNav = Math.hypot(navV.x, navV.y);
    const info = {dist: flatNav, dz: navV.z + AR_EYE_HEIGHT_M, near: flatNav <= ARROW_ARRIVE_RADIUS_M, inside: false, outM: 0, edge: null};
    const pushMark = (az, col, shape) => S.marks.push({az: az, col: col, shape: shape});
    const navAngles = arBearingElevation([navV.x, navV.y, navV.z]);
    const eta = naismithMinutes(flatNav, Math.max(0, info.dz));
    const targetLines = (title) => [{t: title, b: true}, {t: formatDistanceMeters(flatNav) + '  ·  Δz ' + arSignedMetres(info.dz), c: AR_COL.green}, {t: '~' + eta + '′ ' + t('ar.walk')}];
    const projAt = (lat, lng, z) => { const v = arVec(me, lat, lng, z); return arProject([v.x, v.y, v.z], S.basis, S.focal, S.cw, S.ch); };

    if (t0.kind === 'polygon') {
        const geo = t0.pts;
        info.inside = pointInPolygon(me.lat, me.lng, geo);
        const near = arNearestOnPolygon(me, geo);
        info.edge = near ? near.dist : null;
        info.dist = info.inside ? 0 : (near ? near.dist : flatNav);
        info.near = info.inside || flatNav <= ARROW_ARRIVE_RADIUS_M;
        // The same distance the server warns at: out of a sector in progress.
        const sector = m.kind === 'sector' ? (sectors || []).find(s => String(s.id) === String(m.id)) : null;
        info.out = !!(sector && sector.status === 'in_progress' && !info.inside && near && near.dist > AR_SECTOR_OUT_M);
        const col = info.out ? AR_COL.red : (info.inside ? AR_COL.green : AR_COL.amber);
        if (arState.layers.bound) {
            arDrawWall(S, geo.map(p => arVec(me, p[0], p[1])), col, 30, true, false);
            geo.forEach(p => { const v = arVec(me, p[0], p[1]); pushMark(arBearingElevation([v.x, v.y, 0]).az, col, 'sq'); });
        }
        S.radar.push({poly: geo, col: col});
        if (!info.inside && near) {
            const v = arVec(me, near.lat, near.lng);
            const p = arProject([v.x, v.y, v.z], S.basis, S.focal, S.cw, S.ch);
            const title = m.kind === 'sector' ? t('ar.sector') : t('ar.area');
            const lines = [{t: title, b: true}, {t: formatDistanceMeters(near.dist) + ' ' + t('ar.to_edge'), c: col}, {t: 'Δz ' + arSignedMetres(info.dz) + ' · ~' + eta + '′'}];
            if (p && p.x > -20 && p.x < S.cw + 20) arDrawBeacon(S, p, {color: AR_COL.green, big: true, ring: false, lines: lines});
            else S.edges.push({turn: arTurnTo(S.heading, arBearingElevation([v.x, v.y, 0]).az), label: title + ' ' + formatDistanceMeters(near.dist), col: AR_COL.green});
        } else if (info.inside) {
            const p = projAt(nav.lat, nav.lng, 0);
            const lines = [{t: t('ar.inside'), b: true, c: col}, {t: m.kind === 'sector' ? t('ar.searching') : t('ar.in_area')}];
            if (p && p.x > -20 && p.x < S.cw + 20) arDrawBeacon(S, p, {color: AR_COL.green, big: false, ring: false, top: Math.max(-20, p.y - 160), lines: lines});
        }
    } else if (t0.kind === 'line') {
        const pts = t0.pts;
        const nextIdx = Math.max(0, pts.findIndex(p => Math.abs(p[0] - nav.lat) < 1e-9 && Math.abs(p[1] - nav.lng) < 1e-9));
        // Dots along the ground from me to the next point.
        arSubdivide([{x: 0, y: 0, z: -AR_EYE_HEIGHT_M}, {x: navV.x, y: navV.y, z: navV.z}], 14, false).forEach(pc => {
            const p = arProject([pc[1].x, pc[1].y, pc[1].z], S.basis, S.focal, S.cw, S.ch);
            if (!p) return;
            ctx.fillStyle = arRgba(AR_COL.green, arClamp(1.1 - p.depth / 900, .25, .95)); ctx.beginPath(); ctx.arc(p.x, p.y, arClamp(S.focal * .45 / p.depth / 10, 1, 3.6), 0, 7); ctx.fill();
        });
        pts.forEach((pt, i) => {
            if (i <= nextIdx) return;
            const v = arVec(me, pt[0], pt[1]);
            const p = arProject([v.x, v.y, v.z], S.basis, S.focal, S.cw, S.ch);
            const label = t('ar.waypoint', {n: i + 1});
            if (p && p.x > -20 && p.x < S.cw + 20) {
                const top = arProject([v.x, v.y, v.z + 40], S.basis, S.focal, S.cw, S.ch);
                arDrawBeacon(S, p, {color: AR_COL.green, big: false, top: top ? top.y : -20, tag: String(i + 1)});
            } else {
                S.edges.push({turn: arTurnTo(S.heading, arBearingElevation([v.x, v.y, 0]).az), label: label, col: AR_COL.green});
            }
        });
        S.radar.push({poly: null, lat: nav.lat, lng: nav.lng, col: AR_COL.green, size: 4});
        const p = arProject([navV.x, navV.y, navV.z], S.basis, S.focal, S.cw, S.ch);
        const title = t('ar.waypoint_of', {n: nextIdx + 1, total: pts.length});
        if (p && p.x > -20 && p.x < S.cw + 20) arDrawBeacon(S, p, {color: AR_COL.green, big: true, ring: true, lines: targetLines(title)});
        else S.edges.push({turn: arTurnTo(S.heading, navAngles.az), label: title + ' · ' + formatDistanceMeters(flatNav), col: AR_COL.green});
    } else {
        const p = arProject([navV.x, navV.y, navV.z], S.basis, S.focal, S.cw, S.ch);
        const title = String(m.text || m.title).split('\n')[0];
        if (p && p.x > -20 && p.x < S.cw + 20) arDrawBeacon(S, p, {color: AR_COL.green, big: true, ring: true, lines: targetLines(title)});
        else S.edges.push({turn: arTurnTo(S.heading, navAngles.az), label: formatDistanceMeters(flatNav), col: AR_COL.green});
    }
    pushMark(navAngles.az, AR_COL.green, 'tri');
    if (t0.kind !== 'line') S.radar.push({poly: null, lat: nav.lat, lng: nav.lng, col: AR_COL.green, size: 4});

    arDrawLayers(S, me, info);
    return info;
}

// Danger zones and the other teams: the same around any order, and on their own with none open.
function arDrawLayers(S, me, info) {
    const ctx = S.ctx;
    const pushMark = (az, col, shape) => S.marks.push({az: az, col: col, shape: shape});
    // Danger zones: red fences, always on the order's side of the screen when near.
    info.hazard = null;
    if (arState.layers.haz) {
        (restrictedAreas || []).forEach(a => {
            // A zone is a closed area or an open line along an edge; a line has no inside.
            const line = a.shape === 'line';
            if (!a.geo || a.geo.length < (line ? 2 : 3)) return;
            const vecs = a.geo.map(p => arVec(me, p[0], p[1]));
            // A cliff is orange and stands taller, so an exposed ledge cannot be mistaken for a plain danger zone.
            const kind = arZoneKind(a);
            const zoneCol = kind === 'cliff' ? AR_COL.orange : AR_COL.red;
            arDrawWall(S, vecs, zoneCol, kind === 'cliff' ? 30 : 18, !line, true);
            const near = arNearestOnPolygon(me, a.geo, line);
            S.radar.push({poly: a.geo, col: zoneCol, open: line});
            a.geo.forEach(p => { const v = arVec(me, p[0], p[1]); pushMark(arBearingElevation([v.x, v.y, 0]).az, zoneCol, 'sq'); });
            if (near && (!info.hazard || near.dist < info.hazard.dist)) {
                const v = arVec(me, near.lat, near.lng);
                info.hazard = {dist: near.dist, name: a.label, kind: kind, az: arBearingElevation([v.x, v.y, 0]).az, inside: line ? near.dist <= AR_LINE_BREACH_M : pointInPolygon(me.lat, me.lng, a.geo)};
            }
        });
        if (info.hazard) {
            const hz = info.hazard;
            const p = arProject([Math.sin(hz.az * Math.PI / 180) * hz.dist, Math.cos(hz.az * Math.PI / 180) * hz.dist, 8 - AR_EYE_HEIGHT_M], S.basis, S.focal, S.cw, S.ch);
            if (p && p.x > 0 && p.x < S.cw && hz.dist < 400) arLabelCard(ctx, S.cw, S.ch, p.x, p.y - 56, [{t: '▲ ' + t('ar.kind_' + hz.kind), b: true, c: hz.kind === 'cliff' ? AR_COL.orange : AR_COL.red}, {t: hz.name}, {t: formatDistanceMeters(hz.dist)}], hz.kind === 'cliff' ? AR_COL.orange : AR_COL.red);
        }
    }
    if (arState.layers.team) {
        (nearbyTeams || []).forEach(tm => {
            if (tm.lat === null || tm.lat === undefined) return;
            arDrawTeam(S, tm, me);
            S.radar.push({poly: null, lat: tm.lat, lng: tm.lng, col: tm.is_stale ? AR_COL.grey : AR_COL.cyan, size: 3});
            const v = arVec(me, tm.lat, tm.lng);
            pushMark(arBearingElevation([v.x, v.y, 0]).az, tm.is_stale ? AR_COL.grey : AR_COL.cyan, 'dia');
        });
    }
}

// What the screen is working from, for when it misbehaves on a phone: tap the GPS tile.
function arUpdateDebug(el, S) {
    const box = el.querySelector('[data-ar-k="debug"]');
    box.hidden = !arState.debug;
    if (!arState.debug) return;
    const now = Date.now();
    if (now - arState.evAt >= 1000) {
        arState.evRate = arState.evUsed + ' ok / ' + arState.evIgnored + ' ignored per s';
        arState.evUsed = 0; arState.evIgnored = 0; arState.evAt = now;
    }
    const me = arMe();
    const raw = arState.fix ? arState.fix.coords : null;
    const wander = me && raw ? Math.round(Math.hypot(arLocal(me, {lat: raw.latitude, lng: raw.longitude}).x, arLocal(me, {lat: raw.latitude, lng: raw.longitude}).y)) : '-';
    const age = arState.orientAt ? ((now - arState.orientAt) / 1000).toFixed(1) : '-';
    const text = 'hdg ' + (S ? Math.round(S.heading) : '-') + '\u00B0 pitch ' + (S ? Math.round(S.pitch) : '-') + '\u00B0 | ' + arState.evRate + ' | last ' + age + 's | raw-vs-used ' + wander + ' m | compass ' + arState.compass.level + (arState.compass.fieldUt !== null ? ' ' + Math.round(arState.compass.fieldUt) + 'uT' : '') + (arState.compass.jitter !== null ? ' \u00B1' + arState.compass.jitter.toFixed(1) + '\u00B0' : '') + ' | moving ' + (arState.moving ? 'yes' : 'no') + (raw && typeof raw.speed === 'number' ? ' (' + raw.speed.toFixed(1) + ' m/s)' : '');
    if (box.textContent !== text) box.textContent = text;
}

function arSignedMetres(v) {
    return (v >= 0 ? '+' : '−') + Math.round(Math.abs(v)) + ' ' + t('common.unit_m');
}

// The parts of the HUD that only change with the order, not with the frame.
function arUpdateStaticHud(el, m) {
    const steps = el.querySelector('.wr-ar-steps');
    const sig = m ? m.key + '|' + (m.steps || []).join('/') + '|' + m.step : 'free';
    if (steps.dataset.sig !== sig) {
        steps.dataset.sig = sig;
        steps.replaceChildren();
        (m ? m.steps || [] : []).forEach((label, i) => {
            const s = document.createElement('div');
            s.className = 'wr-ar-step' + (i < m.step ? ' is-done' : (i === m.step ? ' is-cur' : ''));
            s.textContent = (i < m.step ? '✓ ' : '') + label;
            steps.appendChild(s);
        });
    }
    const keys = arOrderKeys();
    const pager = el.querySelector('.wr-ar-pager');
    pager.hidden = m ? keys.length < 2 : keys.length < 1;
    if (!m) pager.querySelector('.wr-ar-pagerlabel').textContent = t('ar.free_label');
    else if (keys.length > 1) pager.querySelector('.wr-ar-pagerlabel').textContent = t('popup.pager', {i: Math.max(0, keys.indexOf(arState.key)) + 1, n: keys.length});
    el.querySelector('[data-ar="decline"]').hidden = !(m && m.canDecline && m.outstanding);
    el.querySelector('[data-ar="compass"]').hidden = !m;
    el.querySelector('[data-ar-k="title"]').textContent = m ? (m.text ? String(m.text).split('\n')[0] : m.title) : t('ar.free_title');
}

function arUpdateDynamicHud(el, m, S) {
    const info = arState.scene;
    const set = (k, v) => { const e = el.querySelector('[data-ar-k="' + k + '"]'); if (e && e.textContent !== v) e.textContent = v; };
    const me = arMe();
    set('gps', me && typeof me.acc === 'number' ? '±' + Math.round(me.acc) + ' ' + t('common.unit_m') : '—');
    arUpdateDebug(el, S);
    const go = el.querySelector('[data-ar="go"]');
    if (!S || !info) {
        go.hidden = true;
        set('dist', '—'); set('dz', '—'); set('eta', '—'); set('hint', '');
        return;
    }
    if (!m) {
        go.hidden = true;
        set('dist', '\u2014'); set('dz', '\u2014'); set('eta', '\u2014'); set('hint', '');
        el.querySelector('.wr-ar-banner').textContent = arOrderKeys().length ? t('ar.free_banner_orders') : t('ar.free_banner');
        arUpdateWarning(el, S, info);
        return;
    }
    const polygon = m.target.kind === 'polygon';
    set('dist', polygon && info.inside ? t('ar.inside') : formatDistanceMeters(info.dist));
    set('dz', arState.meElev === null ? '—' : arSignedMetres(info.dz));
    set('eta', '~' + naismithMinutes(info.dist, Math.max(0, info.dz)) + '′');
    const act = arAction(m, info.near);
    go.hidden = !act;
    if (act) {
        if (go.textContent !== act.label) go.textContent = act.label;
        go.disabled = !act.enabled;
    }
    set('hint', act && act.needNear && !act.enabled ? (polygon ? t('ar.hint_inside') : t('ar.hint_near', {m: ARROW_ARRIVE_RADIUS_M})) : '');
    // What to do now, in a sentence.
    let banner;
    if (!act) banner = m.text ? String(m.text).split('\n')[0] : m.title;
    else if (act.op === 'ack') banner = t('ar.banner_ack');
    else if (act.op === 'depart') banner = t('ar.banner_depart');
    else if (act.needNear && info.near) banner = t('ar.banner_here');
    else if (act.needNear) banner = arGuideText(S, m, info);
    else banner = t('ar.banner_finish');
    el.querySelector('.wr-ar-banner').textContent = banner;
    arUpdateWarning(el, S, info);
}

// Danger and sector warnings above everything else, a buzz when one appears, and the lowered-phone cover.
function arUpdateWarning(el, S, info) {
    const warn = el.querySelector('.wr-ar-warn');
    let text = '';
    if (info.out) text = t('ar.sector_out');
    else if (info.hazard && arState.layers.haz) {
        // A cliff is warned about from further off: nobody stops in time at the edge.
        const warnFrom = info.hazard.kind === 'cliff' ? 220 : 170;
        if (info.hazard.inside) text = t('ar.inside_' + info.hazard.kind, {name: info.hazard.name});
        else if (info.hazard.dist < warnFrom && Math.abs(arTurnTo(S.heading, info.hazard.az)) < 45) text = t('ar.ahead_' + info.hazard.kind, {name: info.hazard.name, dist: formatDistanceMeters(info.hazard.dist)});
    }
    warn.hidden = !text;
    if (warn.textContent !== text) warn.textContent = text;
    // A buzz when a warning appears, not on every frame.
    if (text !== arState.warnSig) {
        arState.warnSig = text;
        if (text && navigator.vibrate) navigator.vibrate([180, 90, 180]);
    }
    // Phone lowered: the picture is the ground; say so instead of drawing.
    if (S.pitch < -60 && !arState.message) {
        el.classList.add('wr-ar-lowered');
    } else {
        el.classList.remove('wr-ar-lowered');
    }
}

function arGuideText(S, m, info) {
    const nav = m.target.nav, me = arMe();
    if (!me) return '';
    const l = arLocal(me, nav);
    const turn = arTurnTo(S.heading, arBearingElevation([l.x, l.y, 0]).az);
    const dist = formatDistanceMeters(info.dist);
    if (Math.abs(turn) > 25) return t(turn > 0 ? 'ar.turn_right' : 'ar.turn_left', {deg: Math.round(Math.abs(turn)), dist: dist});
    return t('ar.go_ahead', {dist: dist});
}

// ── «Δες τον με AR»: offered once per order, when its place comes within reach ──
const AR_OFFER_M = 150;
const AR_OFFER_OFF_KEY = 'wrArOfferOff';
const AR_OFFERED_KEY = 'wrArOffered';

// The first open order with a place that this person has received, is within
// radius metres of, and has not been offered yet. Pure: entries is opEntries.
function arOfferCandidate(me, entries, offered, radius) {
    for (const [key, m] of entries) {
        if (!m.outstanding || !m.acked || !m.target || !m.target.nav || offered.includes(key)) continue;
        const l = arLocal(me, m.target.nav);
        if (Math.hypot(l.x, l.y) <= radius) return m;
    }
    return null;
}
function arOfferOff() { try { return localStorage.getItem(AR_OFFER_OFF_KEY) === '1'; } catch (e) { return false; } }
function arOffered() { try { return JSON.parse(sessionStorage.getItem(AR_OFFERED_KEY) || '[]'); } catch (e) { return []; } }
function arMarkOffered(key) {
    const list = arOffered();
    if (!list.includes(key)) list.push(key);
    try { sessionStorage.setItem(AR_OFFERED_KEY, JSON.stringify(list)); } catch (e) { /* private window: may ask again after a reload */ }
}
let arOfferTimer = 0;
function arHideOffer() {
    clearTimeout(arOfferTimer);
    const el = document.getElementById('wrArOffer');
    if (el) el.hidden = true;
}
function arShowOffer(m) {
    let el = document.getElementById('wrArOffer');
    if (!el) {
        el = document.createElement('div');
        el.id = 'wrArOffer';
        el.className = 'wr-ar-offer';
        el.setAttribute('role', 'status');
        el.innerHTML = `<div class="wr-ar-offer-text"></div>
            <div class="wr-ar-offer-row">
                <button type="button" class="btn btn-sm btn-success" data-ar-offer="open"></button>
                <button type="button" class="btn btn-sm btn-link text-white" data-ar-offer="never"></button>
                <button type="button" class="wr-ar-offer-x" data-ar-offer="later" aria-label="${escapeHtml(t('arrow.close'))}"><i class="bi bi-x-lg"></i></button>
            </div>`;
        el.querySelector('[data-ar-offer="open"]').textContent = t('ar.offer_open');
        el.querySelector('[data-ar-offer="never"]').textContent = t('ar.offer_never');
        document.body.appendChild(el);
        el.addEventListener('click', e => {
            const btn = e.target.closest('[data-ar-offer]');
            if (!btn) return;
            const target = el._order;
            arHideOffer();
            if (btn.dataset.arOffer === 'never') { try { localStorage.setItem(AR_OFFER_OFF_KEY, '1'); } catch (err) { /* ignore */ } }
            if (btn.dataset.arOffer === 'open' && target && opEntries.get(target.key)) arOpen(opEntries.get(target.key));
        });
    }
    el._order = m;
    el.querySelector('.wr-ar-offer-text').textContent = t('ar.offer_text', {title: String(m.text || m.title).split('\n')[0]});
    el.hidden = false;
    clearTimeout(arOfferTimer);
    arOfferTimer = setTimeout(arHideOffer, 15000);
}
function arOfferTick() {
    try {
        if (arState || arOfferOff() || document.hidden || !arSupported()) return;
        if (document.body.classList.contains('wr-arrow-open')) return;
        const me = typeof opMyPosition === 'function' ? opMyPosition() : null;
        if (!me) return;
        const m = arOfferCandidate(me, opEntries, arOffered(), AR_OFFER_M);
        if (!m) return;
        arMarkOffered(m.key);
        arShowOffer(m);
    } catch (e) { /* the page's own state is not ready yet */ }
}

// The «Οι Εντολές μου» card's button: the first order with a place, or the free view.
function arWireCardButton() {
    const btn = document.getElementById('myTasksArBtn');
    if (!btn) return;
    if (arSupported()) btn.classList.remove('d-none');
    btn.addEventListener('click', () => {
        const keys = arOrderKeys();
        arOpen(keys.length ? opEntries.get(keys[0]) : null);
    });
}
if (typeof window !== 'undefined' && typeof document !== 'undefined') {
    setInterval(arOfferTick, 5000);
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', arWireCardButton);
    else arWireCardButton();
}

if (typeof module !== 'undefined' && module.exports) {
    module.exports = {
        AR_EYE_HEIGHT_M,
        arLocal,
        arFromLocal,
        arBasis,
        arHeading,
        arPitch,
        arFocalPx,
        arProject,
        arBearingElevation,
        arTurnTo,
        arSubdivide,
        arNearestOnPolygon,
        arEventKind,
        arSmoothBasis,
        arSmoothingTau,
        arAngleBetween,
        arSmoothFix,
        arFieldQuality,
        arJitterRms,
        arCompassQuality,
        arOfferCandidate,
    };
}

/*
 * War Room (Action Room) - the «Πίνακας» look of the admin «Κονσόλα»
 * (Settings → Εμφάνιση Κονσόλας = overlay; assets/css/war-room-console-overlay.css).
 *
 * The other looks are colours over the same three columns. This one is a LAYOUT: the map takes the
 * whole window and everything else floats over it as dark translucent squares and panels - a top
 * bar, a rail of the six kinds of order on the left, the drawing tools and then the map's tools
 * (map view, follow, zoom, ruler, heat, command post, map points) in one column at the right edge -
 * each folding to a single button - a segmented bar with the teams at the bottom, the queue and
 * the feed as floating cards.
 *
 * Nothing is copied. The controls that already exist are MOVED into those places (the map's
 * buttons, the zoom, the export menu, the hero's tools, the phase switch, the counters) and the
 * few new ones - the rail, the team bar, the selected-team card - press the console's own buttons
 * or ask it through window.wrConsole (view(), pickTeam(), clearSelection()). Below 1200 px the
 * controls go back where they were and the console is the three-column one again.
 *
 * The first half of the file is plain functions (tests/js/war-room-console-overlay.test.js).
 */

// ── plain functions ──────────────────────────────────────────────────────────

/**
 * Which teams the bottom bar shows: `cap` of `count`, starting at `start`, moved only as far as it
 * takes to keep the focused team (index, or -1) in view. -> {start, size, more}
 */
function wrcOvWindow(count, focusIndex, start, cap) {
    const size = Math.max(0, Math.min(cap, count));
    let first = Math.max(0, Math.min(start, count - size));
    if (focusIndex >= 0 && size > 0) {
        if (focusIndex < first) first = focusIndex;
        else if (focusIndex >= first + size) first = focusIndex - size + 1;
    }
    return {start: first, size, more: count > size};
}

/** The team before (-1) or after (+1) the focused one, going round; with none focused, the first or the last. */
function wrcOvStep(count, focusIndex, dir) {
    if (count <= 0) return -1;
    if (focusIndex < 0) return dir > 0 ? 0 : count - 1;
    return (focusIndex + dir + count) % count;
}

/** The mission clock: hours and minutes since the start, or before it (a minus sign) - {text, before}. */
function wrcOvElapsed(startTs, nowTs) {
    if (!startTs) return null;
    const diff = Math.floor(nowTs - startTs);
    const abs = Math.abs(diff);
    const pad = n => String(n).padStart(2, '0');
    return {text: (diff < 0 ? '−' : '') + pad(Math.floor(abs / 3600)) + ':' + pad(Math.floor((abs % 3600) / 60)), before: diff < 0};
}

/** The lowest battery level among these people's last positions, or null when none reported one. */
function wrcOvLowestBattery(memberIds, pins) {
    const ids = new Set((memberIds || []).map(Number));
    let low = null;
    (pins || []).forEach(p => {
        if (!ids.has(Number(p.user_id)) || p.battery_level === null || p.battery_level === undefined) return;
        const level = Number(p.battery_level);
        if (!isNaN(level) && (low === null || level < low)) low = level;
    });
    return low;
}

/** «28/31»: of the people whose GPS is on, how many are heard from. -> {text, tone} or null with nobody on GPS. */
function wrcOvGpsReadout(gps) {
    if (!gps || !(gps.total > 0)) return null;
    const heard = Math.max(0, gps.total - gps.silent);
    return {text: heard + '/' + gps.total, tone: heard === 0 ? 'bad' : (gps.silent > 0 ? 'warn' : 'ok')};
}

/**
 * What the selected-team card says about a team (the console's view() item): four rows and the stripe's colour.
 * A battery at or under `low` is a warning and at or under `critical` an alarm - the page's own thresholds
 * (Settings → low battery), so the card and the map's pin popups agree.
 */
function wrcOvTeamRows(team, pins, low, critical) {
    low = low === undefined ? 30 : low;
    critical = critical === undefined ? 15 : critical;
    const first = team.orders && team.orders[0];
    const battery = wrcOvLowestBattery(team.members, pins);
    const contact = team.contact || {tone: 'none', stale: 0, total: 0};
    const rows = {
        order: first ? {kind: first.kind, detail: first.detail || '', state: first.state, tone: first.tone, more: team.orders.length - 1} : null,
        progress: team.ring ? Math.round(team.ring.f * 100) : null,
        contact: contact.total > 0 ? {text: (contact.total - contact.stale) + '/' + contact.total, tone: contact.tone} : null,
        battery: battery === null ? null : {text: battery + '%', tone: battery <= critical ? 'bad' : (battery <= low ? 'warn' : 'ok')},
    };
    const tones = [rows.order && rows.order.tone, rows.contact && rows.contact.tone, rows.battery && rows.battery.tone];
    rows.tone = tones.includes('bad') ? 'bad' : (tones.includes('warn') ? 'warn' : 'ok');
    return rows;
}

/** The streams that are on air, newest first. Rows: {id, uid, status, name, left}. */
function wrcOvLiveStreams(rows) {
    return (rows || []).filter(r => r.status === 'live').sort((a, b) => b.id - a.id);
}

/**
 * Which stream the floating tile shows and whether it shows at all. `live` is wrcOvLiveStreams(), `current`
 * the stream shown last time and `dismissed` a Set of stream ids command has put away: a new stream is not
 * in it, so it brings the tile back by itself. -> {id, show}
 */
function wrcOvTilePick(live, current, dismissed) {
    if (!live.length) return {id: null, show: false};
    const open = live.filter(s => !dismissed.has(s.id));
    if (!open.length) return {id: (live.find(s => s.id === current) || live[0]).id, show: false};
    return {id: (open.find(s => s.id === current) || open[0]).id, show: true};
}

/** Seconds left of a stream as «mm:ss» (nothing while it is not known). */
function wrcOvLeft(seconds) {
    if (typeof seconds !== 'number' || isNaN(seconds)) return '';
    const left = Math.max(0, Math.floor(seconds));
    return String(Math.floor(left / 60)).padStart(2, '0') + ':' + String(left % 60).padStart(2, '0');
}

/**
 * A position as degrees, minutes and seconds with the compass letters: 35.2142, 24.7519 -> «35°12'51" Β, 24°45'07" Α».
 * `compass` is the four letters north, south, east, west in one string («ΒΝΑΔ», «NSEW»).
 */
function wrcOvDms(lat, lng, compass) {
    const letters = Array.from(compass || 'NSEW');
    const part = (value, positive, negative) => {
        const abs = Math.abs(value);
        let deg = Math.floor(abs);
        let min = Math.floor((abs - deg) * 60);
        let sec = Math.round(((abs - deg) * 60 - min) * 60);
        if (sec === 60) { sec = 0; min += 1; }
        if (min === 60) { min = 0; deg += 1; }
        return deg + '°' + String(min).padStart(2, '0') + "'" + String(sec).padStart(2, '0') + '" ' + (value < 0 ? negative : positive);
    };
    return part(lat, letters[0], letters[1]) + ', ' + part(lng, letters[2], letters[3]);
}

/** The cell a height is asked for and remembered by: about 100 m, which is what the terrain model resolves (90 m). */
function wrcOvCell(lat, lng) {
    return lat.toFixed(3) + ',' + lng.toFixed(3);
}

/**
 * A rolling allowance: at most `max` asks in the last `windowMs`. `stamps` are the times of the asks so far;
 * -> {ok, stamps} with `now` added when the ask is allowed. (The server's own limit for heights is shared with
 * the point menu and the measuring tool, so the pointer may only ever use a part of it.)
 */
function wrcOvBudget(stamps, now, max, windowMs) {
    const recent = (stamps || []).filter(ts => ts > now - windowMs);
    if (recent.length >= max) return {ok: false, stamps: recent};
    return {ok: true, stamps: recent.concat([now])};
}

/**
 * May the pointer ask the server for a height now? The allowance above, and two more rules: one ask at a time
 * (a service that does not answer would otherwise pile the asks up, and each one holds one of the host's few PHP
 * workers for seconds, which is what slowed every other request of the page, the map layer's included), and none
 * while a failed ask is being waited out. `ask` = {stamps, busy, pausedUntil}. -> {ok, stamps}.
 */
function wrcOvHeightGate(ask, now, max, windowMs) {
    const a = ask || {};
    if (a.busy || now < (a.pausedUntil || 0)) return {ok: false, stamps: (a.stamps || []).filter(ts => ts > now - windowMs)};
    return wrcOvBudget(a.stamps, now, max, windowMs);
}

/** When the pointer may ask again once an answer is in: at once after a height, after `pauseMs` after none (no service, the quota, a failure). */
function wrcOvHeightPausedUntil(now, gotHeight, pauseMs) {
    return gotHeight ? 0 : now + pauseMs;
}

/**
 * Where the tooltip goes: to the lower right of the pointer, or to the other side of it when that side has no
 * room. (x, y) the pointer, (w, h) the tooltip, (vw, vh) the window. -> {x, y} the tooltip's top left corner.
 */
function wrcOvTipAt(x, y, w, h, vw, vh, gap) {
    const g = gap === undefined ? 20 : gap;
    let left = x + g;
    let top = y + g;
    if (left + w > vw - 4) left = Math.max(4, x - g - w);
    if (top + h > vh - 4) top = Math.max(4, y - g - h);
    return {x: Math.round(left), y: Math.round(top)};
}

/**
 * Where a cell sits on the colour scale, 0 (blue) to 1 (red): its seconds on a logarithmic scale up to `scale`
 * (the answer's 95th percentile). Logarithmic because the difference between ten seconds and a minute matters
 * as much as the one between an hour and a night, and clamped so the few cells above the scale are simply red.
 */
function wrcOvHeatT(seconds, scale) {
    const top = Math.max(1, scale || 1);
    return Math.max(0, Math.min(1, Math.log1p(Math.max(0, seconds)) / Math.log1p(top)));
}

/** The colour at `t` on the scale blue - cyan - green - yellow - red, with the fainter end more transparent. -> {r, g, b, a} */
function wrcOvHeatColor(t) {
    const stops = [[0, [44, 70, 200]], [0.25, [44, 195, 214]], [0.5, [63, 208, 90]], [0.75, [242, 211, 58]], [1, [229, 50, 45]]];
    const x = Math.max(0, Math.min(1, t));
    let i = 1;
    while (i < stops.length - 1 && x > stops[i][0]) i++;
    const [t0, c0] = stops[i - 1];
    const [t1, c1] = stops[i];
    const f = t1 === t0 ? 0 : (x - t0) / (t1 - t0);
    const mix = k => Math.round(c0[k] + (c1[k] - c0[k]) * f);
    return {r: mix(0), g: mix(1), b: mix(2), a: Math.round((0.38 + 0.34 * x) * 100) / 100};
}

/** How many screen pixels a cell of `cellM` metres is at this latitude and zoom (Web Mercator, 256 px tiles). */
function wrcOvHeatCellPx(cellM, lat, zoom) {
    return cellM / (156543.03392 * Math.cos(lat * Math.PI / 180) / Math.pow(2, zoom));
}

/** The busiest cell as the legend's figure: whole minutes, at least one. */
function wrcOvHeatMinutes(seconds) {
    return Math.max(1, Math.ceil((seconds || 0) / 60));
}

/**
 * A name the way somebody types it to look for it: lower case, no accents (ά → α, ΐ → ι), the final sigma
 * as an ordinary one, one space between words. Greek only needs this - nobody has to type a Latin
 * transliteration.
 */
function wrcOvNorm(s) {
    return String(s === null || s === undefined ? '' : s).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '')
        .replace(/ς/g, 'σ').replace(/\s+/g, ' ').trim();
}

/**
 * The one button of the drawing column: folding it while it is open; the icon of the armed tool, lit, while it
 * is folded and a tool is on (so a pen that is armed behind a folded column is seen); the pen and square otherwise.
 * `toolIcon` is the armed tool's own icon class (bi-pencil, ...) or ''. -> {icon, on, label}
 */
function wrcOvDrawFace(open, toolIcon) {
    if (open) return {icon: 'bi-chevron-up', on: false, label: 'fold'};
    if (toolIcon) return {icon: toolIcon, on: true, label: 'armed'};
    return {icon: 'bi-pencil-square', on: false, label: 'open'};
}

/**
 * The one button of the map tools column: folding it while it is open; the map icon while it is folded, lit when
 * something behind the fold is on (the ruler waiting for its clicks, the heat layer) so it is not forgotten there.
 * -> {icon, on, label}
 */
function wrcOvMapFace(open, lit) {
    if (open) return {icon: 'bi-chevron-up', on: false, label: 'fold'};
    return lit ? {icon: 'bi-map', on: true, label: 'lit'} : {icon: 'bi-map', on: false, label: 'open'};
}

const WRC_OV_FIND_GROUPS = ['teams', 'people', 'places'];

/**
 * What the search shows for `query`: [{key, items, more}] for the groups that have a match, in the order
 * teams, people, places, `max` items of each (`more` = how many matched beyond them). `entries` are
 * {group, title, sub, ...}. Every word typed has to be somewhere in the title or the line under it, in any
 * order; the best come first - a title that starts with what was typed, then one with a word that does,
 * then one that has it inside, then a match only in the line under the title - and equals by name, with
 * numbers counted as numbers («Ομάδα 2» before «Ομάδα 10»).
 */
function wrcOvFind(entries, query, max) {
    const words = wrcOvNorm(query).split(' ').filter(Boolean);
    if (!words.length) return [];
    const first = words[0];
    const by = {teams: [], people: [], places: []};
    (entries || []).forEach(e => {
        if (!by[e.group]) return;
        const title = wrcOvNorm(e.title);
        // What else is searched besides the title: the line under it, unless the entry says otherwise (`find`).
        const hay = title + ' ' + wrcOvNorm(e.find !== undefined ? e.find : e.sub);
        if (!words.every(w => hay.includes(w))) return;
        let rank = 3;
        if (title.startsWith(first)) rank = 0;
        else if (title.split(' ').some(w => w.startsWith(first))) rank = 1;
        else if (title.includes(first)) rank = 2;
        by[e.group].push({e, rank, title});
    });
    return WRC_OV_FIND_GROUPS.filter(key => by[key].length).map(key => {
        const sorted = by[key].sort((a, b) => a.rank - b.rank || a.title.localeCompare(b.title, 'en', {numeric: true}));
        return {key, items: sorted.slice(0, max).map(x => x.e), more: Math.max(0, sorted.length - max)};
    });
}

if (typeof module !== 'undefined' && module.exports) {
    module.exports = {wrcOvWindow, wrcOvStep, wrcOvElapsed, wrcOvLowestBattery, wrcOvGpsReadout, wrcOvTeamRows, wrcOvLiveStreams, wrcOvTilePick, wrcOvLeft, wrcOvDms, wrcOvCell, wrcOvBudget, wrcOvHeightGate, wrcOvHeightPausedUntil, wrcOvTipAt, wrcOvHeatT, wrcOvHeatColor, wrcOvHeatCellPx, wrcOvHeatMinutes, wrcOvNorm, wrcOvFind, wrcOvDrawFace, wrcOvMapFace};
}

// ── the view ─────────────────────────────────────────────────────────────────

(function () {
    'use strict';
    if (typeof document === 'undefined') return;
    const html = document.documentElement;
    const root = document.getElementById('wrConsole');
    const api = window.wrConsole;
    if (html.getAttribute('data-wrc-skin') !== 'overlay' || !root || !api || typeof api.view !== 'function' || typeof t !== 'function') return;

    const $ = (sel, ctx) => (ctx || document).querySelector(sel);
    const $$ = (sel, ctx) => Array.from((ctx || document).querySelectorAll(sel));
    const esc = s => String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    const STORE = 'wr-console-' + Number(root.dataset.mission) + '-ov-';
    const load = (key, fallback) => { try { const v = localStorage.getItem(STORE + key); return v === null ? fallback : v; } catch (e) { return fallback; } };
    const save = (key, value) => { try { localStorage.setItem(STORE + key, String(value)); } catch (e) { /* blocked storage: it starts fresh */ } };
    const mk = (tag, cls, id) => { const el = document.createElement(tag); if (cls) el.className = cls; if (id) el.id = id; return el; };
    const pinsNow = () => (typeof pins !== 'undefined' && Array.isArray(pins)) ? pins : [];
    const mapNow = () => (typeof map !== 'undefined' && map) ? map : null;

    // ── the places ───────────────────────────────────────────────────────────
    const top = mk('div', 'wrc-ov-top', 'wrcOvTop');
    const rail = mk('div', 'wrc-ov-rail', 'wrcOvRail');
    // The right edge: one column holding the drawing tools (added further down) and the map's tools, in that order.
    // The map's tools are the stack (map view, follow, zoom, ruler, rings) and the pair (heat, command post, map points).
    const edge = mk('div', 'wrc-ov-edge', 'wrcOvEdge');
    const mapCol = mk('div', 'wrc-ov-mapg', 'wrcOvMapG');
    const mapBtn = mk('button', 'wrc-ov-sq wrc-ov-mapg-btn');
    const mapBody = mk('div', 'wrc-ov-mapg-body', 'wrcOvMapBody');
    const maptools = {open: true};
    const stack = mk('div', 'wrc-ov-stack', 'wrcOvStack');
    const pair = mk('div', 'wrc-ov-pair', 'wrcOvPair');
    const bottom = mk('div', 'wrc-ov-bottom', 'wrcOvBottom');
    const tools = mk('div', 'wrc-ov-tools', 'wrcOvTools');
    const op = mk('div', 'wrc-ov-op', 'wrcOvOp');
    const readout = mk('div', 'wrc-ov-read', 'wrcOvRead');
    const gear = mk('button', 'wrc-ov-sq wrc-ov-gear', 'wrcOvGear');
    gear.type = 'button';
    gear.innerHTML = '<i class="bi bi-gear-fill"></i>';
    gear.title = t('console.ov_tools');
    gear.setAttribute('aria-label', t('console.ov_tools'));
    gear.setAttribute('aria-expanded', 'false');
    mapBtn.type = 'button';
    mapBtn.setAttribute('aria-controls', 'wrcOvMapBody');
    mapBody.append(stack, pair);
    mapCol.append(mapBtn, mapBody);
    edge.appendChild(mapCol);
    root.append(top, rail, edge, bottom, tools);

    const VERBS = [
        ['point', 'bi-geo-alt-fill', t('console.verb_point')],
        ['sector', 'bi-grid-3x3-gap-fill', t('console.verb_sector')],
        ['route', 'bi-signpost-split-fill', t('console.verb_route')],
        ['task', 'bi-clipboard-check', t('console.verb_task')],
        ['say', 'bi-megaphone-fill', t('console.verb_say')],
        ['ask', 'bi-camera-fill', t('console.verb_ask')],
    ];
    rail.innerHTML = `<div class="wrc-ov-col wrc-ov-verbs" id="wrcOvVerbs">${VERBS.map(([key, icon, label]) =>
        `<button type="button" class="wrc-ov-sq" data-ov-verb="${key}" data-label="${esc(label)}" title="${esc(label)}" aria-label="${esc(label)}"><i class="bi ${icon}"></i></button>`).join('')}</div>
        <div class="wrc-ov-col wrc-ov-aux">
            <button type="button" class="wrc-ov-sq wrc-ov-live" data-ov="live" hidden><i class="bi bi-camera-video-fill"></i><span class="wrc-ov-nb"></span></button>
            <span class="wrc-ov-slot" id="wrcOvExport"></span>
            <button type="button" class="wrc-ov-sq" data-ov="teams" data-label="${esc(t('console.ov_teams_toggle'))}" title="${esc(t('console.ov_teams_toggle'))}" aria-label="${esc(t('console.ov_teams_toggle'))}"><i class="bi bi-people-fill"></i></button>
        </div>`;
    stack.innerHTML = '<div class="wrc-ov-col" data-g="nav"></div><div class="wrc-ov-col" data-g="extra"></div>';

    // The bottom bar and the readouts are repainted from the console's state; the tool panel is filled when engaged.
    const dock = $('.wrc-dock', root);
    const tabs = $('#wrcTabs', root);
    const composer = $('#wrcComposer', root);
    const sel = mk('div', 'wrc-ov-sel');
    sel.hidden = true;
    dock.appendChild(sel);
    const collapse = mk('button', 'wrc-ov-collapse');
    collapse.type = 'button';
    collapse.title = t('console.ov_dock_toggle');
    collapse.setAttribute('aria-label', t('console.ov_dock_toggle'));
    collapse.innerHTML = '<i class="bi bi-chevron-down"></i>';
    dock.insertBefore(collapse, dock.firstChild);

    const TAB_ICONS = {feed: 'bi-list-ul', chat: 'bi-chat-dots-fill', media: 'bi-camera-fill', plan: 'bi-grid-fill'};
    $$('button[data-tab]', tabs).forEach(b => {
        const icon = mk('i', 'bi ' + (TAB_ICONS[b.dataset.tab] || 'bi-dot') + ' wrc-ov-ti');
        const label = b.firstChild && b.firstChild.nodeType === 3 ? b.firstChild : null;
        if (label) {
            b.title = label.textContent.trim();
            const span = mk('span', 'wrc-ov-tl');
            span.textContent = label.textContent;
            b.replaceChild(span, label);
        }
        b.insertBefore(icon, b.firstChild);
    });

    const cmpHead = mk('div', 'wrc-ov-cmp-h');
    cmpHead.innerHTML = '<i class="bi"></i><span class="wrc-ov-cmp-t"></span><button type="button" class="wrc-ov-cmp-x" data-ov="cmp-close"><i class="bi bi-x-lg"></i></button>';
    $('.wrc-ov-cmp-x', cmpHead).title = t('console.ov_cmp_close');
    $('.wrc-ov-cmp-x', cmpHead).setAttribute('aria-label', t('console.ov_cmp_close'));
    composer.insertBefore(cmpHead, composer.firstChild);
    // The verbs sit on the rail now; their row in the form is kept (the rail presses its buttons) but not shown.
    const verbRow = $('#wrcVerbs', composer);
    if (verbRow && verbRow.parentNode) verbRow.parentNode.classList.add('wrc-ov-verbrow');

    // ── the live video tile (under the queue) ────────────────────────────────
    // Whoever is on air shows here, so command does not have to open the Media tab to see them. Each live stream
    // gets a <video> of its own, tagged data-live-tile (never data-live-video, which is the Live card's): the
    // page's live-video code attaches the stream's track to it through the SDK like to the card's, because a
    // track whose attached elements are all hidden is paused by adaptiveStream. The card keeps the stop button.
    const queueEl = $('#wrcQueue', root);
    const tile = mk('div', 'wrc-ov-tile');
    tile.hidden = true;
    tile.innerHTML = '<div class="wrc-ov-tile-stage"></div>'
        + `<div class="wrc-ov-tile-c wrc-ov-tile-tl"><b class="wrc-ov-tile-who"></b><span><i class="wrc-ov-rec"></i>${esc(t('console.ov_tile_live'))} <span class="wrc-ov-tile-left"></span></span></div>`
        + '<div class="wrc-ov-tile-c wrc-ov-tile-br"></div>'
        + `<div class="wrc-ov-tile-wait">${esc(t('console.ov_tile_wait'))}</div>`
        + `<div class="wrc-ov-tile-btns"><button type="button" data-ov-tile="full" title="${esc(t('console.ov_fullscreen'))}" aria-label="${esc(t('console.ov_fullscreen'))}"><i class="bi bi-arrows-fullscreen"></i></button>`
        + `<button type="button" data-ov-tile="close" title="${esc(t('console.ov_tile_close'))}" aria-label="${esc(t('console.ov_tile_close'))}"><i class="bi bi-x-lg"></i></button></div>`
        + '<div class="wrc-ov-tile-pick"></div>';
    if (queueEl && queueEl.parentNode) queueEl.parentNode.insertBefore(tile, queueEl.nextSibling);
    const stage = $('.wrc-ov-tile-stage', tile);
    const tileUi = {current: null, dismissed: new Set()};
    const tileVideos = new Map();   // user id -> its <video>
    let lastView = null;

    function liveRows() {
        return $$('#liveStreamsBody .live-stream-item[data-stream-id]').map(el => {
            const who = $('strong', el);
            const name = who ? (who.firstChild && who.firstChild.nodeType === 3 ? who.firstChild.textContent : who.textContent) : '';
            const left = $('.live-remaining[data-seconds-left]', el);
            return {id: Number(el.dataset.streamId), uid: String(el.dataset.userId), status: el.dataset.status, name: name.trim(), left: left ? parseInt(left.dataset.secondsLeft, 10) : NaN};
        });
    }
    function dropVideo(uid) {
        const v = tileVideos.get(uid);
        if (!v) return;
        tileVideos.delete(uid);
        if (typeof window.liveDetachTile === 'function') window.liveDetachTile(v);
        try { v.srcObject = null; } catch (e) { /* nothing attached */ }
        v.remove();
    }
    function teamOf(uid) {
        const tm = lastView && lastView.teams.find(x => x.members.includes(Number(uid)));
        return tm ? tm.label : '';
    }
    function syncTile() {
        if (!engaged) { Array.from(tileVideos.keys()).forEach(dropVideo); tile.hidden = true; return; }
        const live = wrcOvLiveStreams(liveRows());
        const pick = wrcOvTilePick(live, tileUi.current, tileUi.dismissed);
        tileUi.current = pick.id;
        const onAir = new Set(live.map(r => r.uid));
        Array.from(tileVideos.keys()).forEach(uid => { if (!onAir.has(uid)) dropVideo(uid); });
        // Reveal first, attach second: a browser that will not start a video it cannot draw (WebKit) is not asked to.
        tile.hidden = !pick.show;
        if (pick.show) {
            let fresh = false;
            live.forEach(r => {
                if (tileVideos.has(r.uid)) return;
                const v = mk('video', 'wrc-ov-tile-v');
                v.autoplay = true; v.muted = true; v.playsInline = true;
                v.setAttribute('playsinline', '');
                v.setAttribute('data-live-tile', r.uid);
                stage.appendChild(v);
                tileVideos.set(r.uid, v);
                fresh = true;
            });
            if (fresh && typeof window.liveReattachAll === 'function') window.liveReattachAll();
        }
        const shown = live.find(r => r.id === pick.id) || null;
        tileVideos.forEach((v, uid) => v.classList.toggle('on', !!shown && pick.show && shown.uid === uid));
        paintTile(live, shown, pick.show);
    }
    function paintTile(live, shown, visible) {
        if (!visible || !shown) { sigs.tilePick = ''; return; }
        const v = tileVideos.get(shown.uid);
        $('.wrc-ov-tile-who', tile).textContent = [teamOf(shown.uid), shown.name].filter(Boolean).join(' · ');
        $('.wrc-ov-tile-left', tile).textContent = wrcOvLeft(shown.left);
        $('.wrc-ov-tile-br', tile).textContent = v && v.videoWidth ? v.videoWidth + '×' + v.videoHeight : '';
        tile.classList.toggle('waiting', !(v && v.readyState >= 2 && v.videoWidth));
        tile.setAttribute('aria-label', t('console.ov_tile_aria', {name: shown.name}));
        put($('.wrc-ov-tile-pick', tile), 'tilePick', live.length > 1
            ? live.map((r, i) => `<button type="button" data-ov-tile-pick="${r.id}" class="${r.id === shown.id ? 'on' : ''}" title="${esc(t('console.ov_tile_pick', {name: r.name}))}" aria-label="${esc(t('console.ov_tile_pick', {name: r.name}))}">${i + 1}</button>`).join('')
            : '');
        // A video that was put away while hidden, or that the browser paused, is asked to play again.
        if (v && v.srcObject && v.paused) { const p = v.play(); if (p && p.catch) p.catch(() => { /* the page's own retry loop reports it */ }); }
    }
    let tileSoon = 0;
    function syncTileSoon() { if (!tileSoon) tileSoon = setTimeout(() => { tileSoon = 0; syncTile(); }, 50); }
    const liveBody = $('#liveStreamsBody');
    if (liveBody && window.MutationObserver) new MutationObserver(syncTileSoon).observe(liveBody, {childList: true, subtree: true, attributes: true, attributeFilter: ['data-status']});
    setInterval(syncTile, 1000);

    tile.addEventListener('click', e => {
        const pickBtn = e.target.closest('[data-ov-tile-pick]');
        if (pickBtn) { tileUi.current = Number(pickBtn.dataset.ovTilePick); syncTile(); return; }
        const act = e.target.closest('[data-ov-tile]');
        if (act && act.dataset.ovTile === 'close') {
            wrcOvLiveStreams(liveRows()).forEach(r => tileUi.dismissed.add(r.id));
            syncTile();
            return;
        }
        if (act && act.dataset.ovTile === 'full') {
            if (document.fullscreenElement) { document.exitFullscreen(); return; }
            const v = $('.wrc-ov-tile-v.on', tile);
            const fallback = () => { if (v && v.webkitEnterFullscreen) v.webkitEnterFullscreen(); };
            if (tile.requestFullscreen) tile.requestFullscreen().catch(fallback); else fallback();
            return;
        }
        // The picture itself: command is looking at what is on air.
        if (e.target.closest('.wrc-ov-tile-stage') && typeof api.watchLive === 'function') api.watchLive();
    });

    // ── the pointer: a ring and a tooltip with the position under it ─────────
    // The photo's white ring and black coordinates label. The position is the map's own answer for the pointer
    // (nothing is asked); the height of the ground comes a moment after the pointer rests, from the same server
    // door as the point menu and the measuring tool (measureElevations), remembered per ~100 m cell and kept to
    // a part of that door's allowance so it can never use up the heights those two need. It is asked for only
    // when the MOUSE rests (a map that re-centres under a pointer that stands still asks nothing), one ask at a
    // time, and not at all for a while after an ask that brought no height: every ask holds one of the host's
    // few PHP workers while the outside service answers, and a service that does not answer would otherwise fill
    // them all and slow every other request of the page, the map layer's included.
    const PTR_KEY = 'wr-console-ov-pointer';
    const PTR_REST_MS = 700;
    const PTR_BUDGET = 40;            // asks per ten minutes; the server grants 120 to all three users of the door
    const PTR_WINDOW_MS = 600000;
    const PTR_PAUSE_MS = 300000;      // after an ask that brought no height: none for five minutes (the server pauses for three)
    const ptrRing = mk('div', 'wrc-ov-ptr-ring');
    const ptrTip = mk('div', 'wrc-ov-ptr-tip');
    ptrRing.hidden = true;
    ptrTip.hidden = true;
    ptrTip.innerHTML = '<span class="wrc-ov-ptr-pos"></span><span class="wrc-ov-ptr-h"></span>';
    root.append(ptrRing, ptrTip);
    const ptrPos = $('.wrc-ov-ptr-pos', ptrTip);
    const ptrH = $('.wrc-ov-ptr-h', ptrTip);
    const ptr = {on: true, at: null, cell: '', heights: new Map(), stamps: [], busy: false, pausedUntil: 0, rest: 0, bound: false};
    try { ptr.on = localStorage.getItem(PTR_KEY) !== 'off'; } catch (e) { /* blocked storage: it starts on */ }
    // A touch screen has no pointer to follow: a tap would leave the label where it landed.
    const canHover = !window.matchMedia || window.matchMedia('(hover: hover) and (pointer: fine)').matches;
    const ptrBtn = mk('button', 'btn');
    ptrBtn.type = 'button';
    ptrBtn.innerHTML = `<i class="bi bi-cursor"></i>${esc(t('console.ov_coords_toggle'))}`;
    ptrBtn.title = t('console.ov_coords_toggle');
    function paintPtrBtn() {
        ptrBtn.classList.toggle('active', ptr.on);
        ptrBtn.setAttribute('aria-pressed', ptr.on ? 'true' : 'false');
    }
    paintPtrBtn();
    ptrBtn.addEventListener('click', () => {
        ptr.on = !ptr.on;
        try { localStorage.setItem(PTR_KEY, ptr.on ? 'on' : 'off'); } catch (e) { /* not remembered */ }
        paintPtrBtn();
        if (!ptr.on) hidePtr();
    });

    function hidePtr() {
        clearTimeout(ptr.rest);
        ptr.at = null;
        ptrRing.hidden = true;
        ptrTip.hidden = true;
    }
    function heightText(metres) {
        const loc = typeof jsLocale !== 'undefined' ? jsLocale : undefined;
        return ' · ~' + Math.round(metres).toLocaleString(loc) + ' ' + t('common.unit_m');
    }
    function paintPtr() {
        if (!ptr.at) return;
        const {latlng, x, y} = ptr.at;
        ptrPos.textContent = wrcOvDms(latlng.lat, latlng.lng, t('console.ov_compass'));
        const known = ptr.heights.get(ptr.cell);
        ptrH.textContent = typeof known === 'number' ? heightText(known) : '';
        ptrRing.style.transform = `translate(${Math.round(x - 14)}px, ${Math.round(y - 14)}px)`;
        ptrRing.hidden = false;
        ptrTip.hidden = false;
        const box = ptrTip.getBoundingClientRect();
        const at = wrcOvTipAt(x, y, box.width, box.height, window.innerWidth, window.innerHeight);
        ptrTip.style.transform = `translate(${at.x}px, ${at.y}px)`;
    }
    function askHeight(cell, latlng) {
        if (typeof measureElevations !== 'function' || ptr.heights.has(cell)) return;
        const grant = wrcOvHeightGate(ptr, Date.now(), PTR_BUDGET, PTR_WINDOW_MS);
        ptr.stamps = grant.stamps;
        if (!grant.ok) return;
        ptr.heights.set(cell, false);   // asked: not asked again for this cell, whatever the answer
        ptr.busy = true;
        measureElevations([[Number(latlng.lat.toFixed(5)), Number(latlng.lng.toFixed(5))]]).then(heights => {
            const got = !!heights && typeof heights[0] === 'number';
            ptr.busy = false;
            ptr.pausedUntil = wrcOvHeightPausedUntil(Date.now(), got, PTR_PAUSE_MS);
            if (!got) return;
            ptr.heights.set(cell, heights[0]);
            if (ptr.cell === cell) paintPtr();
        });
    }
    // `ask` is true only for the mouse's own movement: the map moving under a pointer that stands still repaints the label
    // (the position changed) but never asks for a height.
    function movePtr(latlng, x, y, ask) {
        if (!engaged || !ptr.on || !canHover) return;
        ptr.cell = wrcOvCell(latlng.lat, latlng.lng);
        ptr.at = {latlng, x, y};
        paintPtr();
        clearTimeout(ptr.rest);
        if (!ask) return;
        const cell = ptr.cell;
        ptr.rest = setTimeout(() => { if (ptr.cell === cell && ptr.at) askHeight(cell, ptr.at.latlng); }, PTR_REST_MS);
    }
    function bindPointer() {
        const m = mapNow();
        if (ptr.bound || !m) return;
        ptr.bound = true;
        m.on('mousemove', e => {
            const oe = e.originalEvent;
            // Over a popup or one of the map's own controls the label would be in the way of what is being read.
            if (!oe || (oe.target && oe.target.closest && oe.target.closest('.leaflet-popup, .leaflet-control'))) { hidePtr(); return; }
            movePtr(e.latlng, oe.clientX, oe.clientY, true);
        });
        m.on('mouseout', hidePtr);
        m.on('dragstart', hidePtr);
        // The map moving under a pointer that stands still (it follows the teams) changes what is under it.
        m.on('moveend', () => {
            if (!ptr.at) return;
            const rect = m.getContainer().getBoundingClientRect();
            const latlng = m.containerPointToLatLng([ptr.at.x - rect.left, ptr.at.y - rect.top]);
            movePtr(latlng, ptr.at.x, ptr.at.y, false);
        });
    }

    // ── the heat layer: where the teams have been, and for how long ──────────
    // Blue to red over the map, drawn on a canvas of its own (thousands of cells are too many SVG nodes), from
    // mission-coverage-heat.php: person-seconds per ~20 m cell over the verified fixes of the whole mission, scaled
    // to the busiest 5 %. Off until asked for (the button beside the command post and the map points, remembered
    // in the browser); while it is on the page asks every half minute and the server answers «unchanged» in one
    // line when no new fix has come. Never shown below 1200 px: the button is not there to turn it on.
    const HEAT_KEY = 'wr-console-ov-heat';
    const HEAT_EVERY_MS = 30000;
    const heat = {on: false, layer: null, sig: '', timer: 0, busy: false, data: null};
    try { heat.on = localStorage.getItem(HEAT_KEY) === 'on'; } catch (e) { /* blocked storage: it starts off */ }
    const heatBtn = mk('button', 'wrc-ov-sq wrc-ov-heat-btn');
    heatBtn.type = 'button';
    heatBtn.innerHTML = '<i class="bi bi-fire"></i>';
    heatBtn.title = t('console.ov_heat_toggle');
    heatBtn.setAttribute('aria-label', t('console.ov_heat_toggle'));
    const heatLegend = mk('div', 'wrc-ov-heat-legend');
    heatLegend.hidden = true;
    heatLegend.innerHTML = `<b class="wrc-ov-heat-t">${esc(t('console.ov_heat_title'))}</b><i class="wrc-ov-heat-bar"></i>`
        + `<span class="wrc-ov-heat-lo">${esc(t('console.ov_heat_low'))}</span><span class="wrc-ov-heat-hi"></span><em class="wrc-ov-heat-msg"></em>`;
    root.appendChild(heatLegend);
    function paintHeatBtn() {
        heatBtn.classList.toggle('on', heat.on);
        heatBtn.setAttribute('aria-pressed', heat.on ? 'true' : 'false');
    }
    paintHeatBtn();

    /** The layer is built the first time it is needed: Leaflet is there by then, and a page without a map never gets one. */
    function heatLayer() {
        const m = mapNow();
        if (heat.layer || !m || typeof L === 'undefined') return heat.layer;
        if (!m.getPane('ovHeatPane')) {
            m.createPane('ovHeatPane');
            // Over the map tiles (200), under the areas (340), sectors (350), pins and everything drawn on top of the ground.
            m.getPane('ovHeatPane').style.zIndex = 330;
            m.getPane('ovHeatPane').style.pointerEvents = 'none';
        }
        const Heat = L.Layer.extend({
            onAdd(map) {
                this._canvas = L.DomUtil.create('canvas', 'wrc-ov-heat-canvas');
                map.getPane('ovHeatPane').appendChild(this._canvas);
                map.on('moveend resize', this._redraw, this);
                map.on('zoomstart', this._hide, this);
                map.on('zoomend', this._redraw, this);
                this._redraw();
            },
            onRemove(map) {
                map.off('moveend resize', this._redraw, this);
                map.off('zoomstart', this._hide, this);
                map.off('zoomend', this._redraw, this);
                L.DomUtil.remove(this._canvas);
                this._canvas = null;
            },
            setData(data) { this.data = data; if (this._map) this._redraw(); },
            _hide() { if (this._canvas) this._canvas.style.visibility = 'hidden'; },
            _redraw() {
                const map = this._map;
                const canvas = this._canvas;
                if (!map || !canvas) return;
                const size = map.getSize();
                canvas.width = size.x;
                canvas.height = size.y;
                L.DomUtil.setPosition(canvas, map.containerPointToLayerPoint([0, 0]));
                canvas.style.visibility = '';
                const ctx = canvas.getContext('2d');
                ctx.clearRect(0, 0, size.x, size.y);
                const data = this.data;
                if (!data || !data.cells || !data.cells.length) return;
                const zoom = map.getZoom();
                const px = Math.max(3, wrcOvHeatCellPx(data.cell_m, data.cells[0][0], zoom));
                const half = px * 0.75;       // a little over a cell: neighbours run into each other, and the blur does the rest
                // The busiest cells come first in the answer: the faint ones are painted first, so the hot ones stay on top.
                for (let i = data.cells.length - 1; i >= 0; i--) {
                    const cell = data.cells[i];
                    const p = map.latLngToContainerPoint([cell[0], cell[1]]);
                    if (p.x < -px || p.y < -px || p.x > size.x + px || p.y > size.y + px) continue;
                    const c = wrcOvHeatColor(wrcOvHeatT(cell[2], data.p95));
                    ctx.fillStyle = `rgba(${c.r},${c.g},${c.b},${c.a})`;
                    ctx.fillRect(p.x - half, p.y - half, half * 2, half * 2);
                }
                canvas.style.filter = `blur(${Math.max(1.5, Math.min(14, px * 0.45)).toFixed(1)}px)`;
            },
        });
        heat.layer = new Heat();
        return heat.layer;
    }

    function paintHeatLegend(state) {
        const msg = $('.wrc-ov-heat-msg', heatLegend);
        const hi = $('.wrc-ov-heat-hi', heatLegend);
        heatLegend.hidden = !heat.on || !engaged;
        heatLegend.classList.toggle('empty', state === 'none' || state === 'failed');
        msg.textContent = state === 'failed' ? t('console.ov_heat_failed') : (state === 'none' ? t('console.ov_heat_none') : '');
        hi.textContent = state === 'ok' && heat.data ? t('console.ov_heat_high', {n: wrcOvHeatMinutes(heat.data.p95)}) : '';
        heatLegend.title = state === 'ok' && heat.data
            ? t('console.ov_heat_info', {fixes: heat.data.fixes, people: heat.data.people, cell: heat.data.cell_m}) : '';
    }

    function heatLoad() {
        if (!engaged || !heat.on || heat.busy) return;
        const layer = heatLayer();
        if (!layer) return;
        heat.busy = true;
        fetch('mission-coverage-heat.php?mission_id=' + encodeURIComponent(root.dataset.mission) + '&sig=' + encodeURIComponent(heat.sig), {credentials: 'same-origin'})
            .then(r => r.ok ? r.json() : null)
            .then(res => {
                if (!heat.on || !engaged) return;
                if (!res || !res.ok) { if (!heat.data) paintHeatLegend('failed'); return; }
                if (res.unchanged) return;
                heat.sig = res.sig;
                heat.data = res;
                layer.setData(res);
                paintHeatLegend(res.cells && res.cells.length ? 'ok' : 'none');
            })
            .catch(() => { if (!heat.data) paintHeatLegend('failed'); })
            .finally(() => { heat.busy = false; });
    }
    function heatStart() {
        const m = mapNow();
        const layer = heatLayer();
        if (!m || !layer) return;
        if (!m.hasLayer(layer)) layer.addTo(m);
        paintHeatLegend(heat.data ? (heat.data.cells.length ? 'ok' : 'none') : 'loading');
        heatLoad();
        clearInterval(heat.timer);
        heat.timer = setInterval(() => { if (!document.hidden) heatLoad(); }, HEAT_EVERY_MS);
    }
    function heatStop() {
        clearInterval(heat.timer);
        heat.timer = 0;
        const m = mapNow();
        if (heat.layer && m && m.hasLayer(heat.layer)) m.removeLayer(heat.layer);
        heatLegend.hidden = true;
    }
    heatBtn.addEventListener('click', () => {
        heat.on = !heat.on;
        try { localStorage.setItem(HEAT_KEY, heat.on ? 'on' : 'off'); } catch (e) { /* not remembered */ }
        paintHeatBtn();
        paintMapTools();
        if (heat.on) heatStart(); else heatStop();
    });

    // ── the ruler: the page's measuring tool, started from the map tools column ──
    // Two clicks on the map measure a distance, with everything the right-click menu's measuring has (the
    // card, the routes, the heights, Esc to cancel). The page owns the tool and says when it changes
    // (window.wrMeasure, the wr-measure-changed event); this is only its button, orange while it is on.
    const ruler = mk('button', 'btn wrc-ov-ruler');
    ruler.type = 'button';
    ruler.innerHTML = '<i class="bi bi-rulers"></i>';
    ruler.title = t('console.ov_ruler');
    ruler.setAttribute('aria-label', t('console.ov_ruler'));
    ruler.setAttribute('aria-pressed', 'false');
    // With the search rings' switch (added when engaged, after it): the map's own tools, in the map tools column under the drawing tools.
    $('[data-g="extra"]', stack).appendChild(ruler);
    const measureTool = () => window.wrMeasure || null;
    function paintRuler() {
        const m = measureTool();
        const on = !!(m && m.active());
        ruler.classList.toggle('active', on);
        ruler.setAttribute('aria-pressed', on ? 'true' : 'false');
        paintMapTools();
    }
    ruler.addEventListener('click', () => {
        const m = measureTool();
        if (!m) return;
        if (m.active()) m.clear(); else m.arm();
        paintRuler();
    });
    document.addEventListener('wr-measure-changed', () => setTimeout(paintRuler, 0));

    // ── the search: teams, people and places, from what the page already holds ─
    // The magnifier opens one input under the bar. Nothing is asked of the server: the teams come from the
    // console, the people from the positions on the map and the places from the lists the map is drawn
    // from. A result takes the map there through the page's own ways in (window.wrMapGo), so a shape opens
    // exactly as it does from «Τι υπάρχει εδώ;».
    const FIND_MAX = 8;
    const FIND_HEADS = {teams: t('console.ov_find_teams'), people: t('console.ov_find_people'), places: t('console.ov_find_places')};
    const FIND_ICONS = {team: 'bi-people-fill', person: 'bi-person-fill', area: 'bi-bounding-box', sector: 'bi-grid-3x3-gap-fill',
        restricted: 'bi-exclamation-triangle-fill', point: 'bi-geo-alt-fill', incident: 'bi-heart-pulse-fill', poi: 'bi-eye-fill'};
    const find = {open: false, q: '', idx: 0, flat: []};
    const findBtn = mk('button', 'btn wrc-ov-find-btn');
    findBtn.type = 'button';
    findBtn.innerHTML = '<i class="bi bi-search"></i>';
    findBtn.title = t('console.ov_find');
    findBtn.setAttribute('aria-label', t('console.ov_find'));
    findBtn.setAttribute('aria-haspopup', 'dialog');
    findBtn.setAttribute('aria-expanded', 'false');
    op.appendChild(findBtn);
    const findPn = mk('div', 'wrc-ov-find', 'wrcOvFind');
    findPn.hidden = true;
    findPn.setAttribute('role', 'dialog');
    findPn.setAttribute('aria-label', t('console.ov_find'));
    findPn.innerHTML = `<div class="wrc-ov-fin"><i class="bi bi-search"></i>`
        + `<input type="text" class="wrc-ov-fi" autocomplete="off" spellcheck="false" role="combobox" aria-expanded="true" aria-controls="wrcOvFindList" aria-autocomplete="list" placeholder="${esc(t('console.ov_find_ph'))}" aria-label="${esc(t('console.ov_find'))}">`
        + `<button type="button" class="wrc-ov-fx" data-find-close title="${esc(t('console.ov_find_close'))}" aria-label="${esc(t('console.ov_find_close'))}"><i class="bi bi-x-lg"></i></button></div>`
        + '<div class="wrc-ov-fl" id="wrcOvFindList" role="listbox"></div>';
    root.appendChild(findPn);
    const findIn = $('.wrc-ov-fi', findPn);
    const findList = $('.wrc-ov-fl', findPn);

    /** Everything that can be looked for, read off what the page holds right now (each list may not exist for this viewer). */
    function findEntries() {
        const out = [];
        const v = api.view();
        // The count under a team's name is not something to search for («α» is in «άτομα»).
        (v.teams || []).forEach(tm => out.push({group: 'teams', kind: 'team', id: tm.id, title: tm.label, sub: t('console.ov_find_members', {n: (tm.members || []).length}), find: '', members: tm.members || []}));
        const seen = new Set();
        pinsNow().forEach(p => {
            if (p.lat === null || p.lat === undefined || seen.has(Number(p.user_id))) return;
            seen.add(Number(p.user_id));
            out.push({group: 'people', kind: 'person', id: p.user_id, title: p.name, sub: p.team_label || ''});
        });
        const areaList = typeof areas !== 'undefined' && Array.isArray(areas) ? areas : [];
        areaList.forEach(a => out.push({group: 'places', kind: 'area', id: a.id, title: a.label, sub: t('map_pick.kind_area')}));
        (typeof sectors !== 'undefined' && Array.isArray(sectors) ? sectors : []).forEach(s => {
            const area = areaList.find(a => a.id === s.area_id);
            out.push({group: 'places', kind: 'sector', id: s.id, title: s.label, sub: t('map_pick.kind_sector') + (area ? ' · ' + area.label : '')});
        });
        (typeof restrictedAreas !== 'undefined' && Array.isArray(restrictedAreas) ? restrictedAreas : [])
            .forEach(r => out.push({group: 'places', kind: 'restricted', id: r.id, title: r.label, sub: t('map_pick.kind_restricted')}));
        (typeof mapPoints !== 'undefined' && Array.isArray(mapPoints) ? mapPoints : [])
            .forEach(p => out.push({group: 'places', kind: 'point', id: p.id, title: p.name, sub: t('console.ov_find_point')}));
        (typeof missionIncidents !== 'undefined' && Array.isArray(missionIncidents) ? missionIncidents : []).forEach(r => {
            if (r.lat === null || r.lat === undefined) return;
            out.push({group: 'places', kind: 'incident', id: r.id, title: typeof incidentShortText === 'function' ? incidentShortText(r) : t('console.ov_find_incident'),
                sub: [t('console.ov_find_incident'), r.is_unknown_patient ? '' : r.patient_name, r.team_label].filter(Boolean).join(' · ')});
        });
        (typeof pointsOfInterest !== 'undefined' && Array.isArray(pointsOfInterest) ? pointsOfInterest : []).forEach(p => {
            if (p.lat === null || p.lat === undefined) return;
            out.push({group: 'places', kind: 'poi', id: p.id, title: t('console.ov_find_poi'), sub: (p.reporter_names || []).join(', ')});
        });
        return out;
    }

    function paintFind() {
        const q = find.q.trim();
        const groups = wrcOvFind(findEntries(), q, FIND_MAX);
        find.flat = groups.reduce((all, g) => all.concat(g.items), []);
        if (find.idx >= find.flat.length) find.idx = 0;
        let out = '';
        if (!q) out = `<div class="wrc-ov-fm">${esc(t('console.ov_find_hint'))}</div>`;
        else if (!groups.length) out = `<div class="wrc-ov-fm">${esc(t('console.ov_find_none', {q}))}</div>`;
        let n = 0;
        groups.forEach(g => {
            out += `<div class="wrc-ov-fh">${esc(FIND_HEADS[g.key])}</div>`;
            g.items.forEach(e => {
                const i = n++;
                out += `<button type="button" class="wrc-ov-fr${i === find.idx ? ' on' : ''}" role="option" id="wrcOvFr${i}" aria-selected="${i === find.idx ? 'true' : 'false'}" data-find="${i}">`
                    + `<i class="bi ${FIND_ICONS[e.kind] || 'bi-dot'}"></i><span class="wrc-ov-ft"><b>${esc(e.title)}</b>${e.sub ? `<small>${esc(e.sub)}</small>` : ''}</span></button>`;
            });
            if (g.more) out += `<div class="wrc-ov-fmore">${esc(t('console.ov_find_more', {n: g.more}))}</div>`;
        });
        findList.innerHTML = out;
        findIn.setAttribute('aria-activedescendant', find.flat.length ? 'wrcOvFr' + find.idx : '');
    }
    /** The highlighted row moved: no repaint, so a click in progress is not lost under the pointer. */
    function markFind(scroll) {
        $$('.wrc-ov-fr', findList).forEach((b, i) => { b.classList.toggle('on', i === find.idx); b.setAttribute('aria-selected', i === find.idx ? 'true' : 'false'); });
        findIn.setAttribute('aria-activedescendant', find.flat.length ? 'wrcOvFr' + find.idx : '');
        const row = $('#wrcOvFr' + find.idx, findList);
        if (scroll && row && row.scrollIntoView) row.scrollIntoView({block: 'nearest'});
    }
    function placeFind() {
        const r = root.getBoundingClientRect();
        const b = findBtn.getBoundingClientRect();
        const w = Math.min(380, window.innerWidth - 24);
        findPn.style.width = w + 'px';
        findPn.style.left = Math.max(12, Math.min(b.left - r.left, window.innerWidth - w - 12)) + 'px';
        findPn.style.top = (top.getBoundingClientRect().bottom - r.top + 6) + 'px';
    }
    function openFind() {
        if (!engaged) return;
        find.open = true;
        find.q = '';
        find.idx = 0;
        findIn.value = '';
        findPn.hidden = false;
        findBtn.classList.add('active');
        findBtn.setAttribute('aria-expanded', 'true');
        placeFind();
        paintFind();
        setTimeout(() => findIn.focus(), 0);
    }
    function closeFind(refocus) {
        if (!find.open) return;
        find.open = false;
        findPn.hidden = true;
        findBtn.classList.remove('active');
        findBtn.setAttribute('aria-expanded', 'false');
        if (refocus) findBtn.focus();
    }
    /** Takes the map to a result. A team is picked as the team bar picks it, alone, and framed. */
    function goTo(e) {
        const door = window.wrMapGo;
        if (e.kind === 'team') {
            api.pickTeam(e.id, 'only');
            ui.focus = e.id;
            frame(new Set(e.members.map(Number)));
            update();
        } else if (door) {
            if (e.kind === 'person') door.person(e.id);
            else if (e.kind === 'point') door.point(e.id);
            else if (e.kind === 'incident') door.incident(e.id);
            else if (e.kind === 'poi') door.poi(e.id);
            else door.shape(e.kind, e.id);
        }
    }
    findBtn.addEventListener('click', e => { e.stopPropagation(); if (find.open) closeFind(); else openFind(); });
    findIn.addEventListener('input', () => { find.q = findIn.value; find.idx = 0; paintFind(); });
    findIn.addEventListener('keydown', e => {
        if (e.isComposing) return;
        if (e.key === 'Escape') {
            // Only the search closes: the page's own Esc (it cancels a measurement) must not also hear it.
            e.preventDefault();
            e.stopPropagation();
            closeFind(true);
        } else if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            if (find.flat.length) { find.idx = wrcOvStep(find.flat.length, find.idx, e.key === 'ArrowDown' ? 1 : -1); markFind(true); }
        } else if (e.key === 'Enter') {
            e.preventDefault();
            const hit = find.flat[find.idx];
            if (hit) { closeFind(); goTo(hit); }
        }
    });
    findList.addEventListener('click', e => {
        const row = e.target.closest('[data-find]');
        const hit = row && find.flat[Number(row.dataset.find)];
        if (hit) { closeFind(); goTo(hit); }
    });
    findList.addEventListener('mousemove', e => {
        const row = e.target.closest('[data-find]');
        if (row && Number(row.dataset.find) !== find.idx) { find.idx = Number(row.dataset.find); markFind(false); }
    });
    findPn.addEventListener('click', e => { if (e.target.closest('[data-find-close]')) closeFind(true); });
    document.addEventListener('mousedown', e => { if (find.open && !findPn.contains(e.target) && !findBtn.contains(e.target)) closeFind(); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && find.open) closeFind(true); });
    window.addEventListener('resize', () => { if (find.open) placeFind(); });

    // ── the drawing column: the pen, the arrow, the text, the eraser and clear-all, at the right edge ─────
    // The page's own toolbar (#annotationToolbar) is moved here whole - the annotation code keeps finding it by
    // its id, and the ruler's «one tool at a time» keeps working through its buttons - and the column folds to one
    // button: the tools when it is open, the icon of the armed tool (lit) when it is folded and a tool is on, so a
    // pen that is armed behind a folded column is seen. Folded by default, remembered per browser; a tool switched
    // on by another route opens it for as long as the page stays (not remembered: it is not a choice).
    const DRAW_KEY = 'wr-console-ov-draw';
    const drawCol = mk('div', 'wrc-ov-draw', 'wrcOvDraw');
    const drawBtn = mk('button', 'wrc-ov-sq wrc-ov-draw-btn');
    drawBtn.type = 'button';
    drawBtn.setAttribute('aria-controls', 'annotationToolbar');
    drawCol.hidden = true;
    drawCol.appendChild(drawBtn);
    edge.insertBefore(drawCol, mapCol);
    const draw = {open: false, observer: null};
    try { draw.open = localStorage.getItem(DRAW_KEY) === 'open'; } catch (e) { /* storage blocked: it starts folded */ }
    function armedTool() {
        const bar = $('#annotationToolbar');
        return bar ? $('button[data-tool].active', bar) : null;
    }
    function paintDraw() {
        const tool = armedTool();
        const glyph = tool ? $('i', tool) : null;
        const icon = glyph ? (Array.from(glyph.classList).find(c => c !== 'bi' && c.indexOf('bi-') === 0) || '') : '';
        const face = wrcOvDrawFace(draw.open, icon);
        drawCol.dataset.open = draw.open ? 'open' : 'closed';
        drawBtn.innerHTML = `<i class="bi ${face.icon}"></i>`;
        drawBtn.classList.toggle('on', face.on);
        const label = face.label === 'fold' ? t('console.ov_draw_fold')
            : (face.label === 'armed' ? t('console.ov_draw_armed', {tool: (tool.title || '').trim()}) : t('console.ov_draw'));
        drawBtn.title = label;
        drawBtn.setAttribute('aria-label', label);
        drawBtn.setAttribute('aria-expanded', draw.open ? 'true' : 'false');
    }
    function setDraw(open, remember) {
        draw.open = open;
        if (remember) { try { localStorage.setItem(DRAW_KEY, open ? 'open' : 'closed'); } catch (e) { /* not remembered */ } }
        paintDraw();
        fitEdge(open ? 'draw' : '');
    }
    function watchDraw() {
        const bar = $('#annotationToolbar');
        if (!bar || draw.observer || !window.MutationObserver) return;
        draw.observer = new MutationObserver(() => {
            const opened = !!armedTool() && !draw.open;
            if (opened) draw.open = true;
            paintDraw();
            if (opened) fitEdge('draw');
        });
        draw.observer.observe(bar, {attributes: true, attributeFilter: ['class'], subtree: true});
    }
    drawBtn.addEventListener('click', () => setDraw(!draw.open, true));
    // Esc folds the column from inside it (the focus on its button or a tool): anywhere else Esc keeps its other meanings.
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && engaged && draw.open && !e.defaultPrevented && drawCol.contains(document.activeElement)) { setDraw(false, true); drawBtn.focus(); }
    });

    // ── the map tools column: under the drawing tools, folding to one button the same way ─────────
    // The map view, follow, zoom, the ruler, the search rings, the heat layer, the command post and the map points are
    // the controls that already existed (the stack and the pair); only their place is new. The column is open by
    // default - they are used all the time - and remembered per browser. The button is the map icon when it is
    // folded, lit when the ruler is waiting for its clicks or the heat layer is on behind the fold.
    const MAPG_KEY = 'wr-console-ov-mapg';
    try { maptools.open = localStorage.getItem(MAPG_KEY) !== 'closed'; } catch (e) { /* storage blocked: it starts open */ }
    function paintMapTools() {
        const m = measureTool();
        const face = wrcOvMapFace(maptools.open, !!(m && m.active()) || heat.on);
        mapCol.dataset.open = maptools.open ? 'open' : 'closed';
        mapBtn.innerHTML = `<i class="bi ${face.icon}"></i>`;
        mapBtn.classList.toggle('on', face.on);
        const label = face.label === 'fold' ? t('console.ov_map_fold') : (face.label === 'lit' ? t('console.ov_map_lit') : t('console.ov_map'));
        mapBtn.title = label;
        mapBtn.setAttribute('aria-label', label);
        mapBtn.setAttribute('aria-expanded', maptools.open ? 'true' : 'false');
    }
    function setMapTools(open, remember) {
        maptools.open = open;
        if (remember) { try { localStorage.setItem(MAPG_KEY, open ? 'open' : 'closed'); } catch (e) { /* not remembered */ } }
        paintMapTools();
        fitEdge(open ? 'map' : '');
    }
    mapBtn.addEventListener('click', () => setMapTools(!maptools.open, true));

    // ── making the two columns fit the window's height ───────────────────────
    // Both open they are about 900 px tall, which a laptop does not have. In order: the buttons shrink (dense), then the
    // column that was not just opened folds - and with it folded the buttons get their full size again if they fit. Only
    // in the page - what was chosen stays what is remembered, so a taller window next time shows both again. Called
    // when either opens or closes, when the window is resized and whenever the column's own height changes (the
    // search rings' switch comes and goes with the mission).
    let fitting = false;
    function edgeFits() { return edge.offsetTop + edge.offsetHeight <= root.clientHeight - 10; }
    function fitEdge(keep) {
        if (!engaged || fitting || !edge.offsetHeight) return;
        fitting = true;
        try {
            // Full size if that fits, else dense; true when either does.
            const fits = () => {
                delete edge.dataset.dense;
                if (edgeFits()) return true;
                edge.dataset.dense = 'on';
                return edgeFits();
            };
            if (!fits()) {
                const folds = keep === 'map' ? ['draw'] : (keep === 'draw' ? ['map'] : ['draw', 'map']);
                for (const g of folds) {
                    if (g === 'draw' && draw.open) { draw.open = false; paintDraw(); } else if (g === 'map' && maptools.open) { maptools.open = false; paintMapTools(); } else continue;
                    if (fits()) break;
                }
            }
        } finally { fitting = false; }
    }
    window.addEventListener('resize', () => fitEdge(''));
    if (window.ResizeObserver) new ResizeObserver(() => fitEdge('')).observe(edge);

    // ── moving the real controls in, and back ────────────────────────────────
    const moved = [];
    let engaged = false;
    const undo = [];
    function take(node, target) {
        if (!node || !target || node.parentNode === target) return false;
        moved.push({node, parent: node.parentNode, next: node.nextSibling});
        target.appendChild(node);
        return true;
    }
    function giveBack() {
        while (moved.length) {
            const m = moved.pop();
            if (m.parent && m.parent.isConnected) m.parent.insertBefore(m.node, m.next && m.next.parentNode === m.parent ? m.next : null);
        }
        while (undo.length) undo.pop()();
    }
    /** Controls the map adds on its own time (the command post, the map points) are taken when they appear. */
    function adopt() {
        if (!engaged) return;
        take($('.wr-cp-control'), pair);
        take($('.wr-mp-control'), pair);
        take($('.leaflet-control-zoom'), $('[data-g="nav"]', stack));
    }

    function engage() {
        if (engaged) return;
        engaged = true;
        html.setAttribute('data-wrc-ov', 'on');
        const hero = $('.war-room-hero');
        const bar = $('#wrcBar');
        take(hero, top);
        take(bar, top);
        // The mission's name is long and the bar cuts it: the whole of it is the tooltip.
        const name = hero && $('h1', hero);
        if (name) { name.title = name.textContent.trim(); }
        take($('#notifBell') && $('#notifBell').closest('.dropdown'), op);
        take($('#userDropdown') && $('#userDropdown').closest('.dropdown'), op);
        top.appendChild(op);
        top.appendChild(readout);
        top.appendChild(gear);
        if (hero) {
            // The assistant and the mass-casualty button stay in the bar; everything else the hero carried is a tool.
            const row = $(':scope > .justify-content-end', hero);
            Array.from(row ? row.children : []).filter(el => el.id !== 'assistantBtn' && el.id !== 'mciHeroBtn').forEach(el => take(el, tools));
            const back = $('a[href="ops-dashboard.php"] .bi', hero);
            if (back) { const was = back.className; back.className = 'bi bi-house-fill'; undo.push(() => { back.className = was; }); }
        }
        take($('#mapFullscreenToggle'), tools);
        if (canHover) tools.appendChild(ptrBtn);
        bindPointer();
        // The heat layer's button goes first in the pair (the last group of the map tools column), so the command post and the map points keep their places.
        pair.insertBefore(heatBtn, pair.firstChild);
        if (heat.on) heatStart();
        // The acknowledgement panel floats over the map like the rest: inside the board, under its menus.
        take($('#ackTracker'), root);
        take($('#trailFilterBar'), root);

        const nav = $('[data-g="nav"]', stack);
        const layers = $('#mapSatelliteToggle');
        take(layers && layers.parentNode, nav);
        take($('#mapFollowBtn'), nav);
        // The drawing tools go to the right edge as a column of their own, folded to one button.
        if (take($('#annotationToolbar'), drawCol)) { watchDraw(); }
        drawCol.hidden = !$('#annotationToolbar');
        paintDraw();
        take($('#mapRingsToggle'), $('[data-g="extra"]', stack));
        // The export menu opens to the right of the rail, not off the left edge of the window.
        const exportMenu = $$('#mapCard .card-header .dropdown').find(d => d !== (layers && layers.parentNode));
        take(exportMenu, $('#wrcOvExport'));
        const menuEl = exportMenu && $('.dropdown-menu', exportMenu);
        if (menuEl && menuEl.classList.contains('dropdown-menu-end')) {
            menuEl.classList.remove('dropdown-menu-end');
            undo.push(() => menuEl.classList.add('dropdown-menu-end'));
        }
        adopt();
        applyChrome();
        update();
        paintMapTools();
        fitEdge('');
        window.dispatchEvent(new Event('resize'));
    }

    function disengage() {
        if (!engaged) return;
        engaged = false;
        html.removeAttribute('data-wrc-ov');
        hidePtr();
        heatStop();
        closeFind();
        // A ruler waiting for its clicks would leave the console's own map with a crosshair and nothing to say why.
        if (measureTool() && measureTool().armed()) measureTool().clear();
        syncTile();
        giveBack();
        top.contains(op) && op.remove();
        const m = mapNow();
        setTimeout(() => { window.dispatchEvent(new Event('resize')); if (m) m.invalidateSize(); }, 60);
    }

    // ── what the page puts at the top and the bottom of the window ──────────
    // The clock strip, and the scrolling banner when it is on the top (or the bottom) edge: the board
    // starts under them instead of being covered.
    function applyChrome() {
        const banner = document.getElementById('warRoomBanner');
        const shown = !!banner && banner.offsetHeight > 0;
        const atBottom = !!banner && banner.dataset.tickerPos === 'bottom';
        const clock = parseFloat(getComputedStyle(html).getPropertyValue('--app-clock-h')) || 0;
        // On <html>, not on the console: the acknowledgement panel outside it measures from the same line.
        html.style.setProperty('--wrc-ov-top', (clock + (shown && !atBottom ? banner.offsetHeight : 0)) + 'px');
        html.style.setProperty('--wrc-ov-bottom', (shown && atBottom ? banner.offsetHeight : 0) + 'px');
    }
    const banner = document.getElementById('warRoomBanner');
    if (banner) {
        if (window.ResizeObserver) new ResizeObserver(applyChrome).observe(banner);
        if (window.MutationObserver) new MutationObserver(applyChrome).observe(banner, {attributes: true, attributeFilter: ['style', 'data-ticker-pos']});
    }
    window.addEventListener('resize', applyChrome);

    // ── painting from the console's state ────────────────────────────────────
    const ui = {focus: null, start: 0, composer: false, tools: false};
    const phaseNow = () => root.dataset.phase || 'live';
    const teamsKey = () => 'teams-' + phaseNow();
    function teamsOpen() { return load(teamsKey(), phaseNow() === 'live' ? 'closed' : 'open') === 'open'; }
    function setFlags() {
        root.dataset.ovComposer = ui.composer && phaseNow() === 'live' ? 'open' : 'closed';
        root.dataset.ovTeams = teamsOpen() ? 'open' : 'closed';
        root.dataset.ovDock = load('dock', 'open') === 'open' ? 'open' : 'closed';
        root.dataset.ovTools = ui.tools ? 'open' : 'closed';
        gear.setAttribute('aria-expanded', ui.tools ? 'true' : 'false');
    }

    function focusOf(v) {
        const picked = v.teams.filter(tm => tm.picked > 0);
        if (!picked.length) return null;
        return picked.find(tm => tm.id === ui.focus) || picked[0];
    }

    let sigs = {};
    function put(el, key, markup) {
        if (sigs[key] === markup) return;
        sigs[key] = markup;
        el.innerHTML = markup;
    }

    function paintRail(v) {
        $$('[data-ov-verb]', rail).forEach(b => b.classList.toggle('on', ui.composer && b.dataset.ovVerb === v.verb));
        $('[data-ov="teams"]', rail).classList.toggle('on', teamsOpen());
        // The live camera: the red counter in the bar says what is on air, and this button is its twin.
        const kpi = $('.wrc-kpi[data-kpi="live"]');
        const live = $('[data-ov="live"]', rail);
        live.hidden = !kpi;
        if (kpi) {
            const n = Number(($('b', kpi) || {}).textContent) || 0;
            const nb = $('.wrc-ov-nb', live);
            nb.textContent = String(n);
            nb.hidden = n === 0;
            live.classList.toggle('flash', kpi.classList.contains('flash'));
            live.title = kpi.title || '';
            live.setAttribute('aria-label', kpi.title || '');
            live.dataset.label = kpi.title || '';
        }
    }

    function teamSeg(tm, focus) {
        const [bg, fg] = typeof teamBadgeColorsJs === 'function' ? teamBadgeColorsJs(tm.color) : [tm.color || '#8a97ad', '#0b1220'];
        const mark = tm.number > 0 ? String(tm.number) : String(tm.label || '?').trim().charAt(0).toUpperCase();
        const whole = tm.all > 0 && tm.picked === tm.all;
        const note = tm.badge ? `<span class="wrc-ov-flag wrc-ov-${esc(tm.badge.tone)}" title="${esc(tm.badge.text)}"></span>` : '';
        const title = t('console.select_team', {team: tm.label});
        return `<button type="button" class="wrc-ov-seg wrc-ov-team${tm.picked > 0 ? (whole ? ' on' : ' part') : ''}${focus && focus.id === tm.id ? ' focus' : ''}" data-ov-team="${tm.id}" style="--tc:${esc(bg)};--tf:${esc(fg)}" title="${esc(title)}" aria-label="${esc(title)}" aria-pressed="${tm.picked > 0 ? 'true' : 'false'}"><i>${esc(mark)}</i>${note}</button>`;
    }
    function paintBottom(v) {
        const cap = window.innerWidth >= 1440 ? 6 : 4;
        const focus = focusOf(v);
        const win = wrcOvWindow(v.teams.length, focus ? v.teams.findIndex(tm => tm.id === focus.id) : -1, ui.start, cap);
        ui.start = win.start;
        const seg = (act, icon, label) => `<button type="button" class="wrc-ov-seg" data-ov="${act}" title="${esc(label)}" aria-label="${esc(label)}"><i class="bi ${icon}"></i></button>`;
        const shown = v.teams.slice(win.start, win.start + win.size);
        put(bottom, 'bottom', seg('zoomteam', 'bi-zoom-in', t('console.ov_zoom_team')) + seg('fit', 'bi-bounding-box', t('console.ov_fit'))
            + (win.more ? seg('prev', 'bi-caret-left-fill', t('console.ov_prev')) : '')
            + shown.map(tm => teamSeg(tm, focus)).join('')
            + (win.more ? seg('next', 'bi-caret-right-fill', t('console.ov_next')) : '')
            + seg('clear', 'bi-x-lg', t('console.ov_clear_sel')) + seg('full', 'bi-fullscreen', t('console.ov_fullscreen')));
    }

    function paintSelected(v) {
        const focus = focusOf(v);
        if (!focus) { sel.hidden = true; sigs.sel = ''; return; }
        const picked = v.teams.filter(tm => tm.picked > 0).length;
        const rows = wrcOvTeamRows(focus, pinsNow(),
            typeof LOW_BATTERY_PCT !== 'undefined' ? LOW_BATTERY_PCT : undefined, typeof CRITICAL_BATTERY_PCT !== 'undefined' ? CRITICAL_BATTERY_PCT : undefined);
        const orderText = rows.order
            ? esc(t('teamboard.kind.' + rows.order.kind)) + (rows.order.more > 0 ? ` <small>+${rows.order.more}</small>` : '')
            : esc(t('teamboard.waiting'));
        const row = (label, value, tone) => `<div class="wrc-ov-r"><span>${esc(label)}</span><b${tone ? ` class="wrc-ov-${esc(tone)}"` : ''}>${value}</b></div>`;
        const head = picked > 1 ? t('console.ov_sel_many', {n: picked, team: focus.label}) : t('console.ov_sel_title', {team: focus.label});
        put(sel, 'sel', `<div class="wrc-ov-selh">${esc(head)}</div>
            <div class="wrc-ov-selg wrc-ov-stripe-${rows.tone}">
                ${row(t('console.ov_row_order'), orderText, rows.order ? rows.order.tone : '')}
                ${row(t('console.ov_row_progress'), rows.progress === null ? '—' : rows.progress + '%', '')}
                ${row(t('console.ov_row_contact'), rows.contact ? esc(rows.contact.text) : '—', rows.contact ? rows.contact.tone : '')}
                ${row(t('console.ov_row_battery'), rows.battery ? esc(rows.battery.text) : '—', rows.battery ? rows.battery.tone : '')}
            </div>`);
        sel.hidden = false;
    }

    function paintReadout(v) {
        const gps = wrcOvGpsReadout(v.gps);
        const start = Number(($('.war-room-hero') || {dataset: {}}).dataset.started) || 0;
        const el = wrcOvElapsed(start, Date.now() / 1000);
        put(readout, 'read',
            (el ? `<span class="wrc-ov-clock${el.before ? ' before' : ''}" title="${esc(t(el.before ? 'console.ov_clock_before' : 'console.ov_clock_title'))}">${esc(el.text)}</span>` : '')
            + (gps ? `<span class="wrc-ov-gps wrc-ov-${gps.tone}" title="${esc(t('console.ov_gps_title', {n: v.gps.total - v.gps.silent, total: v.gps.total}))}">${esc(gps.text)} <small>GPS</small></span>` : ''));
    }

    function paintComposerHead(v) {
        const verb = VERBS.find(x => x[0] === v.verb) || VERBS[0];
        $('i', cmpHead).className = 'bi ' + verb[1];
        $('.wrc-ov-cmp-t', cmpHead).textContent = verb[2];
    }

    function update() {
        if (!engaged) return;
        setFlags();
        const v = api.view();
        lastView = v;
        paintRail(v);
        paintBottom(v);
        paintSelected(v);
        paintReadout(v);
        paintComposerHead(v);
        syncTile();
    }
    let queued = 0;
    function schedule() { if (!queued) queued = setTimeout(() => { queued = 0; update(); }, 0); }
    document.addEventListener('wr-console-changed', schedule);
    setInterval(() => { if (engaged) { sigs.read = ''; update(); } }, 20000);

    // ── what the buttons do ──────────────────────────────────────────────────
    /** One look at where the teams are: `ids` (a Set of user ids) or everybody. */
    function frame(ids) {
        const m = mapNow();
        if (!m || typeof L === 'undefined') return;
        const list = pinsNow().filter(p => !ids || ids.has(Number(p.user_id)));
        if (!list.length) return;
        // A map that follows the pins would put itself back at the next position.
        if (ids && typeof setFollowPins === 'function' && typeof followPins !== 'undefined' && followPins) setFollowPins(false);
        m.invalidateSize();
        m.fitBounds(L.latLngBounds(list.map(p => [p.lat, p.lng])), {padding: [90, 90], maxZoom: 17, animate: false});
    }

    rail.addEventListener('click', e => {
        const verb = e.target.closest('[data-ov-verb]');
        if (verb) {
            const key = verb.dataset.ovVerb;
            const real = $('.wrc-verb[data-verb="' + key + '"]', root);
            const v = api.view();
            // The active one again puts the form away.
            if (ui.composer && v.verb === key) ui.composer = false;
            else { ui.composer = true; if (real) real.click(); }
            update();
            return;
        }
        const live = e.target.closest('[data-ov="live"]');
        if (live) {
            // With the tile put away, the camera brings it back; with the tile up (or nothing on air) it opens the Live card as before.
            if (tile.hidden && wrcOvLiveStreams(liveRows()).length) {
                tileUi.dismissed.clear();
                if (typeof api.watchLive === 'function') api.watchLive();
                syncTile();
                return;
            }
            const kpi = $('.wrc-kpi[data-kpi="live"]');
            if (kpi) kpi.click();
            return;
        }
        if (e.target.closest('[data-ov="teams"]')) {
            save(teamsKey(), teamsOpen() ? 'closed' : 'open');
            update();
        }
    });

    composer.addEventListener('click', e => {
        if (e.target.closest('[data-ov="cmp-close"]')) { ui.composer = false; update(); }
    });
    // A result that lands while the form is put away (a sector drawn in its own window) is still told.
    const toast = $('#wrcToast', composer);
    if (toast && window.MutationObserver) {
        new MutationObserver(() => { if (!toast.hidden && !ui.composer) { ui.composer = true; update(); } }).observe(toast, {attributes: true, attributeFilter: ['hidden']});
    }

    bottom.addEventListener('click', e => {
        const team = e.target.closest('[data-ov-team]');
        const v = api.view();
        if (team) {
            const id = Number(team.dataset.ovTeam);
            api.pickTeam(id, 'toggle');
            ui.focus = id;
            update();
            return;
        }
        const act = e.target.closest('[data-ov]');
        if (!act) return;
        const focus = focusOf(v);
        switch (act.dataset.ov) {
            case 'zoomteam': frame(focus ? new Set(focus.members.map(Number)) : null); break;
            case 'fit': frame(null); break;
            case 'prev':
            case 'next': {
                const at = wrcOvStep(v.teams.length, focus ? v.teams.findIndex(tm => tm.id === focus.id) : -1, act.dataset.ov === 'next' ? 1 : -1);
                if (at >= 0) { ui.focus = v.teams[at].id; api.pickTeam(ui.focus, 'only'); }
                break;
            }
            case 'clear': api.clearSelection(); ui.focus = null; break;
            case 'full': { const b = $('#warRoomFocusToggle'); if (b) b.click(); break; }
        }
        update();
    });

    // The map views menu sizes itself to the card of the map, and its button has left that card: it gets the room of the window.
    stack.addEventListener('show.bs.dropdown', e => {
        const menu = e.target && e.target.parentNode && $('.dropdown-menu', e.target.parentNode);
        if (menu && menu.classList.contains('wr-layers-2col')) menu.style.width = Math.max(260, Math.min(672, window.innerWidth - 480)) + 'px';
    });

    gear.addEventListener('click', e => { e.stopPropagation(); ui.tools = !ui.tools; setFlags(); });
    // A tool that was used has done its part: the panel goes, and the map it acts on is in view.
    tools.addEventListener('click', e => { if (e.target.closest('.btn')) { ui.tools = false; setFlags(); } });
    document.addEventListener('click', e => {
        if (ui.tools && !tools.contains(e.target) && !gear.contains(e.target)) { ui.tools = false; setFlags(); }
    });
    document.addEventListener('keydown', e => { if (e.key === 'Escape' && ui.tools) { ui.tools = false; setFlags(); } });

    collapse.addEventListener('click', () => { save('dock', load('dock', 'open') === 'open' ? 'closed' : 'open'); setFlags(); });
    // Picking a tab while the panel is folded opens it again.
    tabs.addEventListener('click', e => { if (e.target.closest('button[data-tab]') && load('dock', 'open') !== 'open') { save('dock', 'open'); setFlags(); } });

    // ── go ───────────────────────────────────────────────────────────────────
    const wide = window.matchMedia('(min-width: 1200px)');
    const follow = () => { wide.matches ? engage() : disengage(); };
    if (wide.addEventListener) wide.addEventListener('change', follow); else if (wide.addListener) wide.addListener(follow);
    // A window resized by hand, or by a tool that never fires the media query's own event, gets there too.
    window.addEventListener('resize', follow);
    // The map builds some of its controls after this script has run.
    const controls = $('#warRoomMap .leaflet-control-container');
    if (controls && window.MutationObserver) new MutationObserver(adopt).observe(controls, {childList: true, subtree: true});
    follow();
    setTimeout(adopt, 500);
    setTimeout(adopt, 2000);
})();
