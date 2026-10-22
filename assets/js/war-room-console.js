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
 * «Είμαστε έτοιμοι;» - what has to be true before the mission goes live.
 * facts: {teams, people, noGps, gpsPeople, sectors, sectorsFree, stale}
 *   gpsPeople  how many of `people` have their GPS on; `stale` is out of THEM, never out of everybody
 * -> rows {key, tone, n, of} in the order they should be fixed in; tone is
 * ok / warn / bad, and `ready` is true only when nothing is wrong.
 */
function wrcReadiness(f) {
    const rows = [
        {key: 'teams', tone: f.teams > 0 ? 'ok' : 'bad', n: f.teams, of: 0, card: 'teamsCard'},
        {key: 'gps', tone: f.noGps > 0 ? 'warn' : 'ok', n: f.noGps, of: f.people, card: 'participantsCard'},
        {key: 'sectors', tone: f.sectors > 0 ? 'ok' : 'warn', n: f.sectors, of: 0, card: 'sectorsCard'},
        {key: 'assigned', tone: f.sectorsFree > 0 ? 'warn' : 'ok', n: f.sectors - f.sectorsFree, of: f.sectors, card: 'sectorsListCard'},
        {key: 'contact', tone: f.stale > 0 ? 'warn' : 'ok', n: f.stale, of: f.gpsPeople, card: 'participantsCard'},
    ];
    // Sectors that are not there cannot be assigned, and with nobody on GPS there is
    // nobody whose contact could be measured (the GPS row above already says so): those
    // rows have nothing to say.
    const shown = rows.filter(r => !(r.key === 'assigned' && f.sectors === 0) && !(r.key === 'contact' && !(f.gpsPeople > 0)));
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
        wrcUnreadKeys, wrcChatUnread,
        wrcNormalizePhase, wrcPhasePreset, wrcReadiness, wrcClosingItems,
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
                    <button type="button" class="wrc-link ms-auto" id="wrcSelAll">${esc(t('console.select_all'))}</button></header>
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

    function kpiData() {
        const a = page.assistant();
        const rows = (a && a.pending) || [];
        const silent = rows.filter(r => r.kind === 'silent').reduce((n, r) => n + (Number(r.count) || 1), 0);
        const waitingEl = document.querySelector('[data-live-fragment="missionVisitors"] [data-visitor-pending]');
        return [
            {k: 'teams', label: t('console.kpi_teams'), n: state.teams.length},
            {k: 'sos', label: 'SOS', n: page.sos().length, tone: 'bad', go: 'queue'},
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
        const sig = JSON.stringify(data.map(d => [d.k, d.n]));
        if (sig === kpiSig) return;
        kpiSig = sig;
        kpiBox.innerHTML = data.map(d => `<button type="button" class="wrc-kpi${d.n && d.tone ? ' wrc-kpi-' + d.tone : ''}" data-kpi="${esc(d.k)}"${d.go ? ` data-go="${esc(d.go)}"` : ''}${d.filter ? ` data-filter-to="${esc(d.filter)}"` : ''}${d.card ? ` data-card="${esc(d.card)}"` : ''}>
            <span class="wrc-kpi-l">${esc(d.label)}</span><b class="num">${d.n}</b></button>`).join('');
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
        if (b) jump(b.dataset);
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
        return {
            teams: state.teams.length, people: members.length,
            noGps: members.filter(m => !m.takes_part).length,
            gpsPeople: contact.total,
            sectors: secs.length, sectorsFree: secs.filter(sec => !sec.team_id).length,
            stale: contact.silent,
        };
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
            sig = JSON.stringify(['prep', r.rows.map(x => [x.key, x.tone, x.n, x.of])]);
            if (sig === phaseSig) return;
            html = `<header class="wrc-sec-h"><i class="bi bi-clipboard-check"></i><span class="wrc-h">${esc(t('console.panel_prep'))}</span>
                    <span class="wrc-cnt ${r.ready ? 'ok' : 'bad'}">${r.rows.length - r.open}/${r.rows.length}</span></header>
                <div class="wrc-checks">${r.rows.map(x => checkRow(x.tone, t(CHECK_TEXT[x.key][x.tone === 'ok' ? 0 : 1], {n: x.n, of: x.of}), {card: x.card})).join('')}</div>
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
        return `<label class="wrc-m${stale ? ' stale' : ''}">
            <input type="checkbox" data-uid="${uid}"${state.selected.has(uid) ? ' checked' : ''}>
            <span class="wrc-m-n">${guestNameHtml(m.name, m.is_external, m.home_team_name, teamBadgeColorsJs(m.home_team_color)[0], teamBadgeColorsJs(m.home_team_color)[1], m.guest_country_code)}${k9BadgeHtml(uid, true)}${captainBadgeHtml(uid, true)}</span>
            ${st ? `<span class="wrc-tag wrc-tag-${st[0]}">${esc(t(st[1]))}</span>` : ''}
            ${stale ? `<i class="bi bi-wifi-off wrc-stale" title="${esc(t('console.no_contact'))}"></i>` : ''}
            ${m.takes_part ? '' : `<i class="bi bi-geo-alt-slash wrc-mut" title="${esc(t('gps_participant.self_note'))}"></i>`}
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
            m.members.map(x => [x.user_id, x.name, isStale(x.user_id), (state.live[String(x.user_id)] || {}).field_status])]),
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

    function selectionChanged() {
        syncSelection();
        renderComposerTo();
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
        if (verb) { state.verb = verb.dataset.verb; save('verb', state.verb); renderComposerBody(false); }
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
        go.disabled = true;
        fetch('mission-sector.php', {method: 'POST', body, credentials: 'same-origin'}).then(r => r.json()).then(res => {
            if (res && res.ok) {
                if (typeof map !== 'undefined' && map) map.closePopup();
                if (typeof sectorRefreshAfter === 'function') sectorRefreshAfter(res.sectors, res.areas);
                // The team's row opens its orders, so the one that was just sent is in view.
                if (team) state.ordersOpen.add(team.id);
                teamsSig = '';
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

    function activeRoomKey() {
        const tab = $('.chat-room-tab.active', root);
        return tab && tab.dataset.teamId ? String(tab.dataset.teamId) : 'general';
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
        $$('.chat-room-tab', root).forEach(tab => {
            const n = chat.byRoom[tab.dataset.teamId ? String(tab.dataset.teamId) : 'general'] || 0;
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

    window.wrConsole = {
        reveal,
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
