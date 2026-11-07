/**
 * VolunteerOps - «Σενάριο» (v3.452.0): orders written down before an exercise and sent, phase by phase, by a
 * manual press of play.
 *
 * Three things on the page come from here, all for command staff only (the script is not even loaded for anyone
 * else, and mission-scenario.php refuses them if they ask):
 *   - the «Σενάριο» card in the sidebar: the phases, the orders staged in each, ▶ on a phase, and the way to
 *     take an order or a phase off again;
 *   - ghosts on the live map: a staged order is drawn as a grey dashed shape with its phase number, so the
 *     plan can be read against the ground. A ghost is not an order: nobody but command ever sees one, and it is
 *     gone from the map the moment its phase is played, because the real order is there instead;
 *   - the «Στο σενάριο ▾» button of the point/area window, which puts the order being drawn into a phase
 *     instead of sending it, and (v3.454.0) the same button of the sector-division window, which puts every
 *     piece of the division into a phase (stageSectors); a sector is a grey ghost polygon like an area, and the
 *     card names the search area it is cut from;
 *   - the route window's «Στο σενάριο ▾» (v3.455.0): the route drawn there goes into a phase instead of out to a team
 *     (stageRoute); a staged route is a grey dashed line through its waypoints with the phase number at its start;
 *   - the card's «Εντολή σε άτομα» (v3.457.0): a general order - a task, a spoken announcement, a message, or a request
 *     for a location, a photo or a video - goes into a phase from a small form in the card itself (the Action Room has
 *     no single window for them). It has no place on the map, so no ghost; it goes to a team, a slot, or everyone on
 *     duty when play is pressed, and the card counts people (✓2/5), not teams;
 *   - the restricted-area window's «Στο σενάριο ▾» (v3.458.0): the zone drawn there (a danger zone, an edge, a forbidden
 *     place; a polygon or a line) goes into a phase instead of being made now (stageRestricted). A zone is for everybody,
 *     so it has no team or slot; it is a dashed red-grey ghost, and once played the card reads it as «Σε ισχύ» (it is
 *     never "finished", so it does not count in the phase's «4/6 ✓»);
 *   - the search-area window's «Στο σενάριο ▾» (v3.459.0): the area drawn there goes into a phase instead of being made now
 *     (stageArea). An area has no team, slot or «όλες οι ομάδες» (it is a boundary, not an order to anybody); it is a grey
 *     ghost polygon, and once made the card reads it as «Σε ισχύ» and says that taking the phase back leaves it on the
 *     map. Its row on the card has «Τομείς», which opens the division window on it: the sectors cut there are written down
 *     against that area ('item:<id>') and play makes the area first;
 *   - the «Κτίριο» button on the row of a sector the scenario has still to make (v3.460.0): it arms the next click on the map,
 *     inside that sector, and the popup that opens there (name, floors, phase) puts the building into a phase (stageBuilding)
 *     instead of making it. A building has no team (the sector's team searches it); it is a small dashed square marker with the
 *     phase number, and once made the card reads it as «Σε ισχύ» and says that taking the phase back leaves it in the sector;
 *   - the team slots (v3.453.0): names for teams that may not exist yet, in the card, in the window's recipient
 *     list, and in the dialog that play opens to ask which team is which.
 *
 * Two places show the card (v3.462.0), and this one file serves both, through ONE renderer:
 *   - the Scenario PAGE (mission-scenario-view.php, its own tab, command only): the whole card - phases, orders, ▶,
 *     «Ποια ομάδα είναι ποια;», the waits, withdrawing, renaming, the «Εντολή σε άτομα» form. It has no map, so what
 *     needs one («Δείξε το στον χάρτη», «Τομείς», «Κτίριο») is a link to the Action Room, which opens focused on that
 *     order (?sc_do=...&sc_item=...; runMapAction below). The page marks itself with data-sc-mode="page" on #scenarioBody.
 *   - the Action Room: the card there is a slim summary line («Φάση 1 · 4/6 ✓ · Φάση 2 · περιμένουν 2 ομάδες») and a link
 *     to the page. Everything of the map stays here: the ghosts and the «Στο σενάριο ▾» menus of the order windows.
 * The two tabs share no messages: each asks mission-scenario.php again every REFRESH_MS, so a phase played on the page
 * is on the map within that time, and an order staged from a map window is on the page within it.
 *
 * The plan is never part of the 5-second poll or of WR_BOOT: it is fetched here, once when the page opens and
 * again whenever the tab comes back to the front or an action changes it (every answer of mission-scenario.php
 * carries the whole plan). A second coordinator's change shows up at the next of those.
 *
 * Loaded before the page's own script, so it only DECLARES things here and reaches for the page's globals (map,
 * dispatches, csrfToken, WR_MISSION_ID, t(), escapeHtml(), renderDispatches()) when it is called. The Scenario page
 * defines the same few globals itself (a small boot script printed by PHP), so nothing here knows which page it is on
 * except through data-sc-mode.
 */
(function () {
    const ENDPOINT = 'mission-scenario.php';
    // Where the links of the page lead to, and the tab they reuse: the Action Room of this mission names its own window (init, in
    // the Action Room), so a link of the page navigates THAT tab instead of opening a second Action Room.
    const ROOM_PAGE = 'war-room.php';
    function roomTarget() {
        return 'wrActionRoom' + WR_MISSION_ID;
    }

    let scenario = {phases: [], slots: [], teams: []};
    let ghostLayer = null;
    let initialised = false;
    let busy = false;
    let lastJson = '';
    // True on the Scenario page (the whole card), false in the Action Room (the summary line). Set once, by init().
    let pageMode = false;
    let deepLinkDone = false;
    // The card asks again now and then, so that what another tab changed shows up: always on the page, in the Action Room
    // whenever there is a plan to show ghosts of - only while the tab is in front and nobody is choosing in the card. It
    // redraws only when the answer differs, so a select being used is never torn down.
    const REFRESH_MS = 20000;
    const DEEP_LINK_DELAY_MS = 800;

    // The general orders a phase may carry (v3.457.0): orders to people rather than to a place. The three that carry
    // words are the first three; the others are a request for a location, a photo or a video.
    const ORDER_TYPES = ['task', 'speak', 'message', 'location', 'photo', 'video'];
    const ORDER_TEXT_TYPES = ['task', 'speak', 'message'];
    // The words for each kind, spelled out one by one so a missing string is found by name.
    const ORDER_LABEL_KEYS = {
        task: 'scenario.order_type_task', speak: 'scenario.order_type_speak', message: 'scenario.order_type_message',
        location: 'scenario.order_type_location', photo: 'scenario.order_type_photo', video: 'scenario.order_type_video'
    };
    const ORDER_ICONS = {
        task: 'bi-clipboard-check-fill', speak: 'bi-megaphone-fill', message: 'bi-chat-left-text-fill',
        location: 'bi-crosshair', photo: 'bi-camera-fill', video: 'bi-camera-reels-fill'
    };
    // What a restricted area is and how it is drawn (v3.458.0): the page's own words for them, spelled out one by one.
    const RESTRICTED_KIND_KEYS = {
        danger: 'restricted_area.kind_danger', cliff: 'restricted_area.kind_cliff', forbidden: 'restricted_area.kind_forbidden'
    };
    const RESTRICTED_SHAPE_KEYS = {polygon: 'restricted_area.shape_polygon', line: 'restricted_area.shape_line'};
    // What the «Εντολή σε άτομα» form holds while it is open. Kept here, not in the form, so a redraw of the card
    // (an action of another coordinator, the 20-second refresh) never loses what is being typed.
    let orderDraft = {open: false, type: 'task', text: '', to: '', phase: '', note: ''};

    function esc(value) {
        return escapeHtml(String(value == null ? '' : value));
    }

    // The Action Room opened on one order of the plan: what to do there (goto | divide-area | add-building) and which order.
    function roomHref(act, itemId) {
        return ROOM_PAGE + '?id=' + encodeURIComponent(WR_MISSION_ID) + '&sc_do=' + encodeURIComponent(act) + '&sc_item=' + encodeURIComponent(itemId);
    }

    function injectCss() {
        if (document.getElementById('wrScenarioCss')) return;
        const style = document.createElement('style');
        style.id = 'wrScenarioCss';
        style.textContent = [
            '.wr-sc-ghost-pin{width:26px;height:26px;border-radius:50%;border:2px dashed #6b7280;background:rgba(243,244,246,.88);color:#374151;font-weight:700;font-size:.8rem;display:flex;align-items:center;justify-content:center;box-shadow:0 1px 3px rgba(0,0,0,.35)}',
            '.wr-sc-ghost-pin.wr-sc-ghost-building{border-radius:6px}',
            '.wr-sc-phase{border:1px solid var(--bs-border-color,#dee2e6);border-radius:8px;padding:.4rem .55rem;margin-bottom:.55rem}',
            '.wr-sc-phase.wr-sc-played{background:rgba(25,135,84,.07);border-color:rgba(25,135,84,.35)}',
            '.wr-sc-phase.wr-sc-ready{border-color:rgba(25,135,84,.7);box-shadow:0 0 0 2px rgba(25,135,84,.18)}',
            '.wr-sc-item.wr-sc-withdrawn{opacity:.6}',
            '.wr-sc-items{list-style:none;margin:.35rem 0 0;padding:0}',
            '.wr-sc-item{font-size:.85rem;padding:.2rem 0;border-top:1px dashed var(--bs-border-color,#dee2e6)}',
            '.wr-sc-item:first-child{border-top:0}',
            '.wr-sc-item > span{min-width:0;overflow-wrap:anywhere}',
            '.wr-sc-item .badge{white-space:normal;text-align:left}',
            '.wr-sc-item > button{flex-shrink:0}',
            '.wr-sc-goto{text-decoration:none;color:inherit;cursor:pointer}',
            '.wr-sc-goto:hover{text-decoration:underline}',
            '.wr-sc-child{margin-left:14px;border-left:2px solid rgba(128,128,128,.35);padding-left:6px}',
            '.wr-sc-waits{margin-top:6px;padding:6px 8px;border:1px dashed rgba(178,106,0,.6);border-radius:6px}',
            '.wr-sc-wait + .wr-sc-wait{margin-top:6px;padding-top:6px;border-top:1px solid rgba(128,128,128,.25)}',
            // The summary line of the Action Room: one small chip per phase.
            '.wr-sc-chips{display:flex;flex-wrap:wrap;gap:4px}',
            '.wr-sc-chip{display:inline-block;padding:2px 8px;border-radius:999px;font-size:.78rem;font-weight:600;border:1px solid rgba(128,128,128,.4);background:rgba(128,128,128,.08)}',
            '.wr-sc-chip-ok{color:#146c43;border-color:rgba(25,135,84,.5);background:rgba(25,135,84,.1)}',
            '.wr-sc-chip-run{color:#0a58ca;border-color:rgba(13,110,253,.45);background:rgba(13,110,253,.08)}',
            '.wr-sc-chip-wait{color:#8a5300;border-color:rgba(178,106,0,.55);background:rgba(255,193,7,.14)}',
            '.wr-sc-chip-bad{color:#b02a37;border-color:rgba(220,53,69,.55);background:rgba(220,53,69,.1)}',
            '.wr-sc-chip-ready{color:#146c43;border-color:#198754;box-shadow:0 0 0 2px rgba(25,135,84,.18)}',
            // The page: a wide screen holds the phases side by side instead of one long column.
            '.wr-sc-page .wr-sc-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:.75rem;align-items:start;margin-bottom:.75rem}',
            '.wr-sc-page .wr-sc-grid .wr-sc-phase{margin-bottom:0}'
        ].join('\n');
        document.head.appendChild(style);
    }

    // ── Talking to the server ──────────────────────────────────────────────────────────────────────────────────
    function apply(fresh) {
        lastJson = JSON.stringify(fresh || null);
        scenario = (fresh && Array.isArray(fresh.phases)) ? fresh : {phases: []};
        if (!Array.isArray(scenario.slots)) scenario.slots = [];
        if (!Array.isArray(scenario.teams)) scenario.teams = [];
        renderCard();
        drawGhosts();
        // The point/area window keeps the slots in its recipient list (war-room.php).
        document.dispatchEvent(new CustomEvent('wr-scenario-updated'));
    }

    function load() {
        return fetch(ENDPOINT + '?mission_id=' + encodeURIComponent(WR_MISSION_ID))
            .then(r => r.json())
            .then(res => { if (res && res.ok) { apply(res.scenario); showReleased(res); consumeDeepLink(); } else { loadFailed(res); } return res; })
            .catch(() => { loadFailed(null); return null; });
    }

    // The page has nothing to draw until the plan has come: if it never does (the mission was closed, the session ended, the
    // connection is down) it says so instead of staying empty. After a first success the last good card stays.
    function loadFailed(res) {
        const body = document.getElementById('scenarioBody');
        if (!pageMode || !body || lastJson) return;
        body.innerHTML = `<div class="alert alert-warning mb-0" data-sc-load-error="1">${esc((res && res.error) || t('common.send_failed'))}</div>`;
    }

    // Every REFRESH_MS the card asks again, on the page and in the Action Room alike: another tab may have played a phase
    // or staged an order, a team may have finished an order, and each refresh is also the safety net that sends what a
    // missed completion left waiting for a team (v3.461.0). It stops while the tab is hidden, while an action is in
    // flight, and while somebody is choosing or typing in the card.
    function refreshProgress() {
        if (document.hidden || busy) return;
        const body = document.getElementById('scenarioBody');
        const active = document.activeElement;
        if (body && active && body.contains(active) && (active.tagName === 'SELECT' || active.tagName === 'INPUT' || active.tagName === 'TEXTAREA')) return;
        fetch(ENDPOINT + '?mission_id=' + encodeURIComponent(WR_MISSION_ID))
            .then(r => r.json())
            .then(res => { if (res && res.ok && !busy) { if (JSON.stringify(res.scenario) !== lastJson) apply(res.scenario); showReleased(res); } })
            .catch(() => null);
    }

    // Every answer of the endpoint, good or bad, carries the plan as it is now, so the card is never left
    // showing what another coordinator has already changed.
    function request(action, fields) {
        const body = new URLSearchParams(Object.assign(
            {csrf_token: csrfToken, mission_id: String(WR_MISSION_ID), action: action}, fields || {}
        ));
        return fetch(ENDPOINT, {method: 'POST', body: body})
            .then(r => r.json())
            .then(res => { if (res && res.scenario) apply(res.scenario); return res; });
    }

    function failed(res) {
        alert((res && res.error) || t('common.send_failed'));
    }

    // A refresh that sent what had been waiting for a team (v3.461.0) answers with the live lists, like a play, so the new orders are on
    // the map at once and not at the next poll.
    function showReleased(res) {
        if (!res || !res.released || !res.released.length) return;
        if (res.dispatches && typeof renderDispatches === 'function') renderDispatches(dispatches = res.dispatches);
        showNewSectors(res);
        showNewRoutes(res);
    }

    // A play or a retry that made sectors answers with the live list of sectors and areas, the pair the sector
    // endpoint answers with, so the new ones are on the map at once and not at the next poll.
    function showNewSectors(res) {
        if (res && Array.isArray(res.sectors) && typeof sectorRefreshAfter === 'function') sectorRefreshAfter(res.sectors, res.areas);
    }

    // A play or a retry that sent routes answers with the live list of routes, the one the route endpoint answers
    // with, so they are on the map and in the team's cards at once and not at the next poll.
    function showNewRoutes(res) {
        if (!res || !Array.isArray(res.routes) || typeof renderRouteLayer !== 'function') return;
        routes = res.routes;
        if (typeof renderMyRoutes === 'function') renderMyRoutes(routes);
        if (typeof renderRoutesAdmin === 'function') renderRoutesAdmin(routes);
        renderRouteLayer(routes);
    }

    // A play, a retry or a withdrawal that made or removed restricted areas answers with the live list of zones, the one the
    // restricted-area endpoint answers with, so the map and the list show the change at once (v3.458.0).
    function showNewRestricted(res) {
        if (!res || !Array.isArray(res.restricted_areas) || typeof renderRestrictedAreaLayer !== 'function') return;
        restrictedAreas = res.restricted_areas;
        renderRestrictedAreaLayer(restrictedAreas);
        if (typeof renderRestrictedAreasList === 'function') renderRestrictedAreasList(restrictedAreas);
    }

    // ── Words ──────────────────────────────────────────────────────────────────────────────────────────────────
    function phaseName(phase) {
        return t('scenario.phase_n', {n: phase.n});
    }
    function itemKind(item) {
        if (item.type === 'sector') return t('scenario.kind_sector');
        if (item.type === 'route') return t('scenario.kind_route');
        if (item.type === 'restricted') return t('scenario.kind_restricted');
        if (item.type === 'search_area') return t('scenario.kind_search_area');
        if (item.type === 'building') return t('scenario.kind_building');
        if (item.type === 'order') return t(ORDER_LABEL_KEYS[item.order_type] || 'scenario.order_add_btn');
        return item.type === 'point' ? t('scenario.kind_point') : t('scenario.kind_area');
    }
    function itemTarget(item) {
        // A restricted area is for everybody in the field; there is nobody to name (v3.458.0).
        if (item.audience === 'none') return t('scenario.target_everyone');
        // An order to people that goes to «όλοι» goes to everybody on duty, not to every team.
        if (item.audience === 'all') return item.type === 'order' ? t('scenario.target_all_people') : t('common.all_teams');
        if (item.audience === 'slot') return (item.slot_label || '') + (item.team_label ? ' → ' + item.team_label : '');
        return item.team_label || t('scenario.team_gone');
    }
    function itemTitle(item) {
        // The words of an order to people can be long: the card shows the beginning.
        const label = (item.type === 'order' && item.label && String(item.label).length > 60) ? String(item.label).slice(0, 57) + '…' : item.label;
        return label ? itemKind(item) + ' «' + label + '»' : itemKind(item);
    }

    // ── The card ───────────────────────────────────────────────────────────────────────────────────────────────
    // How an order that was sent stands now (v3.456.0), read from the live order by the server: finished, under way
    // (with how many teams have finished it when it went to every team), or taken back.
    function stateLine(item) {
        // A message or a spoken announcement is finished when everybody has acknowledged it; the other orders to
        // people when everybody has done what they asked (v3.457.0). Counts are people, not teams.
        const isOrder = item.type === 'order';
        const ackOnly = isOrder && (item.order_type === 'message' || item.order_type === 'speak');
        // Waiting for its team to finish the earlier phases, or its wait was cancelled, or it was split into one order per team (v3.461.0).
        if (item.state === 'waiting') {
            const w = item.wait;
            if (!w || !w.phase_n) {
                return `<div class="small" style="color:#b26a00"><i class="bi bi-hourglass-split me-1"></i>${esc(t('scenario.state_waiting_generic'))}</div>`;
            }
            const text = t(w.cant ? 'scenario.state_waiting_cant' : 'scenario.state_waiting', {team: w.team_label || item.team_label || '', phase: t('scenario.phase_n', {n: w.phase_n}), done: w.done, of: w.of});
            return w.cant
                ? `<div class="small text-danger"><i class="bi bi-exclamation-octagon-fill me-1"></i>${esc(text)}</div>`
                : `<div class="small" style="color:#b26a00"><i class="bi bi-hourglass-split me-1"></i>${esc(text)}</div>`;
        }
        if (item.state === 'cancelled') {
            return `<div class="small text-muted"><i class="bi bi-slash-circle me-1"></i>${esc(t('scenario.state_cancelled'))}</div>`;
        }
        if (item.state === 'expanded') {
            return `<div class="small text-muted"><i class="bi bi-diagram-2 me-1"></i>${esc(t('scenario.state_split'))}</div>`;
        }
        if (item.state === 'done') {
            return `<div class="small text-success"><i class="bi bi-check-circle-fill me-1"></i>${esc(t(ackOnly ? 'scenario.state_done_ack' : 'scenario.state_done'))}</div>`;
        }
        if (item.state === 'withdrawn') {
            return `<div class="small text-muted"><i class="bi bi-slash-circle me-1"></i>${esc(t('scenario.state_withdrawn'))}</div>`;
        }
        // A search area is never finished either, but it is not a danger: it stands, and taking the phase back leaves it on
        // the map (its sectors are what the phase takes back). The line says so in a calm colour (v3.459.0).
        if (item.state === 'standing' && item.type === 'search_area') {
            return `<div class="small text-primary"><i class="bi bi-bounding-box me-1"></i>${esc(t('scenario.state_standing'))} <span class="text-muted">· ${esc(t('scenario.area_stays_hint'))}</span></div>`;
        }
        // A building stands with its sector: taking the phase back leaves it there, so the line is calm too (v3.460.0).
        if (item.state === 'standing' && item.type === 'building') {
            return `<div class="small text-primary"><i class="bi bi-building me-1"></i>${esc(t('scenario.state_standing'))} <span class="text-muted">· ${esc(t('scenario.building_stays_hint'))}</span></div>`;
        }
        // A zone is never finished: it stands until somebody takes it away.
        if (item.state === 'standing') {
            return `<div class="small text-danger"><i class="bi bi-shield-fill-exclamation me-1"></i>${esc(t('scenario.state_standing'))}</div>`;
        }
        if (item.state === 'open') {
            const key = isOrder ? (ackOnly ? 'scenario.state_open_ack' : 'scenario.state_open_people') : 'scenario.state_open_broadcast';
            const text = item.of_n ? t(key, {done: item.done_n || 0, of: item.of_n}) : t('scenario.state_open');
            return `<div class="small text-primary"><i class="bi bi-hourglass-split me-1"></i>${esc(text)}</div>`;
        }
        return `<div class="small text-success"><i class="bi bi-check-circle-fill me-1"></i>${esc(t('scenario.item_sent'))}</div>`;
    }

    // The passive note «Η Φάση 1 ολοκληρώθηκε · έτοιμη η Φάση 2» (v3.456.0). It only says so: the phase is still sent by
    // pressing its own play, and nothing is ever sent for it. Per browser; on unless switched off.
    function hintEnabled() {
        try { return localStorage.getItem('wrScenarioHint') !== '0'; } catch (e) { return true; }
    }
    function setHintEnabled(on) {
        try { localStorage.setItem('wrScenarioHint', on ? '1' : '0'); } catch (e) { /* private window: stays on for this page only */ }
    }
    function readyHint(phase) {
        if (phase.played_at || !hintEnabled()) return '';
        const idx = scenario.phases.indexOf(phase);
        const prev = idx > 0 ? scenario.phases[idx - 1] : null;
        if (!prev || !prev.played_at || !prev.progress || !prev.progress.complete) return '';
        return `<div class="small fw-semibold text-success mt-1" data-sc-ready="1"><i class="bi bi-flag-fill me-1"></i>${esc(t('scenario.hint_ready', {prev: phaseName(prev), next: phaseName(phase)}))}</div>`;
    }

    function itemHtml(phase, item) {
        const icon = item.type === 'point' ? 'bi-geo-alt-fill'
            : (item.type === 'sector' ? 'bi-grid-3x3-gap-fill'
                : (item.type === 'route' ? 'bi-signpost-split-fill'
                    : (item.type === 'restricted' ? 'bi-exclamation-triangle-fill'
                        : (item.type === 'order' ? (ORDER_ICONS[item.order_type] || 'bi-chat-left-text-fill') : (item.type === 'building' ? 'bi-building-fill' : 'bi-bounding-box')))));
        const badgeClass = item.team_missing ? 'bg-danger'
            : (item.audience === 'all' ? 'bg-warning text-dark' : (item.audience === 'slot' ? (item.unmapped ? 'bg-warning text-dark' : 'bg-info text-dark') : 'bg-secondary'));
        const target = `<span class="badge ${badgeClass}">${esc(itemTarget(item))}</span>`;
        let status = '';
        if (item.sent) {
            status = stateLine(item);
            // An order that waited says how it went out: by itself when its team finished, or by «Στείλε τώρα» (v3.461.0).
            if (item.release_mode && item.released_label) {
                status += `<div class="small text-muted"><i class="bi bi-send-check me-1"></i>${esc(t(item.release_mode === 'auto' ? 'scenario.state_released_auto' : 'scenario.state_released_manual', {time: item.released_label}))}</div>`;
            }
        } else if (item.state === 'waiting' || item.state === 'cancelled') {
            status = stateLine(item);
        } else if (item.error) {
            status = `<div class="small text-danger"><i class="bi bi-exclamation-triangle-fill me-1"></i>${esc(item.error)}</div>`;
        } else if (item.team_missing) {
            status = `<div class="small text-danger"><i class="bi bi-exclamation-triangle-fill me-1"></i>${esc(t('scenario.team_gone_hint'))}</div>`;
        } else if (item.area_missing) {
            status = `<div class="small text-danger"><i class="bi bi-exclamation-triangle-fill me-1"></i>${esc(t('scenario.area_gone_hint'))}</div>`;
        } else if (item.sector_missing) {
            status = `<div class="small text-danger"><i class="bi bi-exclamation-triangle-fill me-1"></i>${esc(t('scenario.sector_gone_hint'))}</div>`;
        } else if (item.team_empty) {
            status = `<div class="small text-danger"><i class="bi bi-exclamation-triangle-fill me-1"></i>${esc(t('scenario.team_empty_hint'))}</div>`;
        } else if (item.unmapped) {
            status = `<div class="small text-muted"><i class="bi bi-hourglass-split me-1"></i>${esc(t('scenario.slot_unmapped_hint'))}</div>`;
        }
        const retry = (!item.sent && item.error && phase.played_at)
            ? `<button type="button" class="btn btn-outline-primary btn-sm py-0" data-sc="retry" data-item="${item.id}" title="${esc(t('scenario.retry_btn'))}"><i class="bi bi-arrow-repeat"></i></button>` : '';
        const del = !item.sent
            ? `<button type="button" class="btn btn-outline-danger btn-sm py-0" data-sc="delete-item" data-item="${item.id}" title="${esc(t('scenario.item_delete'))}"><i class="bi bi-x-lg"></i></button>` : '';
        // An order to people has no place on the map to show. The page has no map either: its link opens the Action Room on the
        // order (v3.462.0), the one place that can show it. data-sc-link tells the click handler to leave the link alone.
        const goto = (item.sent || item.type === 'order')
            ? `<span>${esc(itemTitle(item))}</span>`
            : `<a href="${esc(roomHref('goto', item.id))}" target="${roomTarget()}" class="wr-sc-goto" data-sc="goto" data-item="${item.id}" data-sc-link="1" title="${esc(t('scenario.show_on_map'))}">${esc(itemTitle(item))}</a>`;
        // A sector says which search area it is cut from (the area may be gone: then the line below says so). An area the
        // scenario itself still has to make says in which phase (v3.459.0).
        let where = '';
        if (item.type === 'sector' && item.area_label) {
            const inArea = item.area_staged
                ? t('scenario.sector_in_staged_area', {area: item.area_label, phase: t('scenario.phase_n', {n: item.area_phase_n || ''})})
                : t('scenario.sector_in_area', {area: item.area_label});
            where = ` <span class="text-muted small">${esc(inArea)}</span>`;
        }
        // A route says how many points it has, and whether the team is to return to where it started.
        if (item.type === 'route') {
            where = ` <span class="text-muted small">${esc(t('scenario.route_points', {n: item.point_count || 0}))}${item.return_to_start ? ' · ' + esc(t('scenario.route_return_hint')) : ''}</span>`;
        }
        // A restricted area says what it is («Γκρεμός · γραμμή») beside its name.
        if (item.type === 'restricted') {
            const kindText = RESTRICTED_KIND_KEYS[item.restricted_kind] ? t(RESTRICTED_KIND_KEYS[item.restricted_kind]) : '';
            const shapeText = RESTRICTED_SHAPE_KEYS[item.shape] ? t(RESTRICTED_SHAPE_KEYS[item.shape]) : '';
            where = ` <span class="text-muted small">${esc([kindText, shapeText].filter(Boolean).join(' · '))}</span>`;
        }
        // A building says how many floors it has and which sector it stands in (that sector may still have to be made: then
        // in which phase) (v3.460.0).
        if (item.type === 'building') {
            const floors = item.floor_count > 0 ? t('scenario.building_floors', {n: item.floor_count}) : t('scenario.building_ground_only');
            const inSector = item.sector_label
                ? (item.sector_staged
                    ? t('scenario.building_in_staged_sector', {sector: item.sector_label, phase: t('scenario.phase_n', {n: item.sector_phase_n || ''})})
                    : t('scenario.building_in_sector', {sector: item.sector_label}))
                : '';
            where = ` <span class="text-muted small">${esc([floors, inSector].filter(Boolean).join(' · '))}</span>`;
        }
        // A search area is a boundary and not an order to anybody: no «→ προς:» for it. Until it is made it has «Τομείς»,
        // which opens the division window on it (the sectors cut there go into the scenario, against this area). A building
        // goes to nobody either: the team of its sector searches it.
        const isArea = item.type === 'search_area';
        const recipient = (isArea || item.type === 'building') ? '' : ` <i class="bi bi-arrow-right-short"></i> ${target}`;
        // The button goes under the words, not beside them: the card is narrow, and a button in the row squeezes the name.
        const divide = (isArea && !item.sent)
            ? `<div class="mt-1"><a href="${esc(roomHref('divide-area', item.id))}" target="${roomTarget()}" class="btn btn-outline-primary btn-sm py-0" data-sc="divide-area" data-item="${item.id}" data-sc-link="1" title="${esc(t('scenario.area_divide_title'))}"><i class="bi bi-grid-3x3-gap me-1"></i>${esc(t('scenario.area_divide_btn'))}</a></div>` : '';
        // A sector the scenario has still to make can have buildings written down in it: «Κτίριο» arms the next click on the map
        // (v3.460.0). Also under the words, for the same reason.
        const addBuilding = (item.type === 'sector' && !item.sent && Array.isArray(item.geo) && item.geo.length >= 3)
            ? `<div class="mt-1"><a href="${esc(roomHref('add-building', item.id))}" target="${roomTarget()}" class="btn btn-outline-primary btn-sm py-0" data-sc="add-building" data-item="${item.id}" data-sc-link="1" title="${esc(t('scenario.building_add_title'))}"><i class="bi bi-building-add me-1"></i>${esc(t('scenario.building_add_btn'))}</a></div>` : '';
        return `<li class="wr-sc-item d-flex align-items-start gap-1${item.state === 'withdrawn' || item.state === 'cancelled' ? ' wr-sc-withdrawn' : ''}${item.parent_item_id ? ' wr-sc-child' : ''}" data-item="${item.id}">
            <i class="bi ${icon} text-secondary mt-1"></i>
            <span class="flex-grow-1">${goto}${where}${recipient}${status}${divide}${addBuilding}</span>
            ${retry}${del}
        </li>`;
    }

    // «Αναμονή ανά ομάδα» (v3.461.0): under a played phase, one row for each team whose orders are held back, or whose wait was cancelled,
    // with the two overrides command always has: send them now, or cancel the wait.
    function waitsHtml(phase) {
        const waits = Array.isArray(phase.waits) ? phase.waits : [];
        if (!waits.length) return '';
        const rows = waits.map(w => {
            let text;
            if (w.cancelled) {
                text = t('scenario.wait_row_cancelled', {team: w.team_label, n: w.n});
            } else if (!w.phase_n) {
                text = w.team_label + ' · ' + t('scenario.state_waiting_generic') + ' · ' + w.n;
            } else {
                text = t(w.cant ? 'scenario.wait_row_cant' : 'scenario.wait_row', {team: w.team_label, phase: t('scenario.phase_n', {n: w.phase_n}), done: w.done, of: w.of, n: w.n});
            }
            const cls = w.cant ? 'text-danger fw-semibold' : (w.cancelled ? 'text-muted' : '');
            const now = `<button type="button" class="btn btn-outline-success btn-sm py-0" data-sc="release-now" data-phase="${phase.id}" data-team="${w.team_id}" data-cancelled="${w.cancelled ? 1 : 0}" title="${esc(t('scenario.release_now_title'))}"><i class="bi bi-send me-1"></i>${esc(t('scenario.release_now_btn'))}</button>`;
            const cancel = w.cancelled ? '' : `<button type="button" class="btn btn-outline-secondary btn-sm py-0" data-sc="cancel-wait" data-phase="${phase.id}" data-team="${w.team_id}" title="${esc(t('scenario.cancel_wait_title'))}"><i class="bi bi-x-circle me-1"></i>${esc(t('scenario.cancel_wait_btn'))}</button>`;
            return `<div class="wr-sc-wait" data-wait-team="${w.team_id}"><div class="small ${cls}">${esc(text)}</div><div class="d-flex flex-wrap gap-1 mt-1">${now}${cancel}</div></div>`;
        }).join('');
        return `<div class="wr-sc-waits" data-sc-waits="1"><div class="small fw-semibold mb-1"><i class="bi bi-hourglass-split me-1"></i>${esc(t('scenario.waits_title'))}</div>${rows}</div>`;
    }

    function phaseHtml(phase) {
        const unsent = phase.items.filter(i => !i.sent).length;
        const played = !!phase.played_at;
        const play = played ? '' : `<button type="button" class="btn btn-success btn-sm w-100" data-sc="play" data-phase="${phase.id}" ${unsent ? '' : 'disabled'} title="${esc(t('scenario.play_title'))}"><i class="bi bi-play-fill me-1"></i>${esc(t('scenario.play_btn'))}</button>`;
        const playedLine = played
            ? `<div class="small text-success mt-1"><i class="bi bi-check-circle-fill me-1"></i>${esc(t('scenario.played_line', {time: phase.played_label || '', who: phase.played_by_name || ''}))}</div>` : '';
        const items = phase.items.length
            ? `<ul class="wr-sc-items">${phase.items.map(i => itemHtml(phase, i)).join('')}</ul>`
            : `<div class="small text-muted mt-1">${esc(t('scenario.phase_no_orders'))}</div>`;
        // «Φάση 1 · 4/6 ✓»: how many of the orders that still count are finished; «Ανακλήθηκε» when all were taken back.
        const pr = played ? phase.progress : null;
        let badge = '';
        if (pr && pr.total > 0) {
            badge = ` <span class="badge ${pr.complete ? 'bg-success' : 'bg-secondary'}" title="${esc(t('scenario.progress_title'))}">${esc(t('scenario.progress_badge', {done: pr.done, total: pr.total}))}</span>`;
        } else if (pr && pr.withdrawn > 0) {
            badge = ` <span class="badge bg-secondary">${esc(t('scenario.state_withdrawn'))}</span>`;
        }
        // An order to people that is still under way cannot be taken back: the card says so beside the button.
        const stuckOrders = played && phase.items.some(i => i.type === 'order' && i.state === 'open');
        const withdraw = ((pr && pr.can_withdraw)
            ? `<div class="mt-1"><button type="button" class="btn btn-outline-danger btn-sm w-100" data-sc="withdraw" data-phase="${phase.id}" title="${esc(t('scenario.withdraw_title'))}"><i class="bi bi-arrow-counterclockwise me-1"></i>${esc(t('scenario.withdraw_btn'))}</button></div>` : '')
            + (stuckOrders ? `<div class="small text-muted mt-1" data-sc-no-withdraw="1">${esc(t('scenario.order_no_withdraw'))}</div>` : '');
        const ready = readyHint(phase);
        return `<div class="wr-sc-phase ${played ? 'wr-sc-played' : ''}${ready ? ' wr-sc-ready' : ''}" data-phase="${phase.id}">
            <div class="d-flex align-items-center gap-1">
                <span class="flex-grow-1 text-break"><strong>${esc(phaseName(phase))}</strong>${badge}${phase.title ? ` <span class="text-muted small">· ${esc(phase.title)}</span>` : ''}</span>
                <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2" data-sc="rename" data-phase="${phase.id}" title="${esc(t('scenario.phase_rename'))}"><i class="bi bi-pencil"></i></button>
                <button type="button" class="btn btn-outline-danger btn-sm py-0 px-2" data-sc="delete-phase" data-phase="${phase.id}" title="${esc(t('scenario.phase_delete'))}"><i class="bi bi-trash"></i></button>
            </div>
            ${ready}${play ? `<div class="mt-1">${play}</div>` : ''}
            ${playedLine}${waitsHtml(phase)}${withdraw}${items}
        </div>`;
    }

    // «Θέσεις ομάδων»: the names the plan gives to teams that may not exist yet, each with the team it stands for.
    function slotsHtml() {
        const teamOptions = selected => scenario.teams.map(team =>
            `<option value="${team.id}"${String(team.id) === String(selected) ? ' selected' : ''}>${esc(team.label)}</option>`).join('');
        const rows = scenario.slots.map(slot => `<div class="mb-2" data-slot="${slot.id}">
            <div class="d-flex align-items-center gap-1">
                <span class="flex-grow-1 small fw-semibold text-break">${esc(slot.label)}</span>
                <button type="button" class="btn btn-outline-danger btn-sm py-0 px-2" data-sc="delete-slot" data-slot="${slot.id}" title="${esc(t('scenario.slot_delete'))}"><i class="bi bi-x-lg"></i></button>
            </div>
            <select class="form-select form-select-sm mt-1" data-sc-map="${slot.id}" aria-label="${esc(slot.label)}">
                <option value="">${esc(t('scenario.slot_no_team'))}</option>${teamOptions(slot.team_id)}
            </select>
        </div>`).join('');
        return `<div class="wr-sc-slots mt-3 pt-2 border-top">
            <div class="small fw-semibold">${esc(t('scenario.slots_title'))}</div>
            <div class="small text-muted mb-2">${esc(t('scenario.slots_hint'))}</div>
            ${rows}
            <button type="button" class="btn btn-outline-secondary btn-sm w-100" data-sc="add-slot"><i class="bi bi-plus-lg me-1"></i>${esc(t('scenario.slot_add_btn'))}</button>
        </div>`;
    }

    // ── «Εντολή σε άτομα» (v3.457.0) ───────────────────────────────────────────────────────────────────────────
    // The phase the form will use: the person's choice while it is still a phase that can take orders, else the first
    // phase that has not been played, else a new one.
    function orderPhase() {
        const open = scenario.phases.filter(p => !p.played_at);
        if (orderDraft.phase === 'new') return 'new';
        if (orderDraft.phase && open.some(p => String(p.id) === String(orderDraft.phase))) return String(orderDraft.phase);
        return open.length ? String(open[0].id) : 'new';
    }

    function orderFormHtml() {
        if (!orderDraft.open) {
            return `<button type="button" class="btn btn-outline-primary btn-sm w-100 mt-2" data-sc="order-open" title="${esc(t('scenario.order_add_title'))}"><i class="bi bi-chat-left-text me-1"></i>${esc(t('scenario.order_add_btn'))}</button>`;
        }
        const d = orderDraft;
        const typeOptions = ORDER_TYPES.map(k => `<option value="${k}"${d.type === k ? ' selected' : ''}>${esc(t(ORDER_LABEL_KEYS[k]))}</option>`).join('');
        const words = ORDER_TEXT_TYPES.includes(d.type)
            ? `<label class="form-label small fw-semibold mb-1 mt-2">${esc(t('scenario.order_text_label'))}</label>
               <textarea class="form-control form-control-sm" rows="3" maxlength="500" data-sc-o="text" placeholder="${esc(t('scenario.order_text_placeholder'))}">${esc(d.text)}</textarea>` : '';
        const teamOptions = scenario.teams.map(team => `<option value="${team.id}"${String(d.to) === String(team.id) ? ' selected' : ''}>${esc(team.label)}</option>`).join('');
        const slotOptions = scenario.slots.map(slot =>
            `<option value="slot:${slot.id}"${d.to === 'slot:' + slot.id ? ' selected' : ''}>${esc(slot.label)}${slot.team_label ? ' → ' + esc(slot.team_label) : ''}</option>`).join('')
            + `<option value="slot:new">${esc(t('scenario.slot_option_new'))}</option>`;
        const chosenPhase = orderPhase();
        const phaseOptions = scenario.phases.filter(p => !p.played_at).map(p =>
            `<option value="${p.id}"${String(p.id) === chosenPhase ? ' selected' : ''}>${esc(phaseName(p))}${p.title ? ' · ' + esc(p.title) : ''}</option>`).join('')
            + `<option value="new"${chosenPhase === 'new' ? ' selected' : ''}>${esc(t('scenario.new_phase_n', {n: scenario.phases.length + 1}))}</option>`;
        return `<div class="wr-sc-orderform mt-2 border rounded p-2" data-sc-orderform="1">
            <label class="form-label small fw-semibold mb-1">${esc(t('scenario.order_type_label'))}</label>
            <select class="form-select form-select-sm" data-sc-o="type">${typeOptions}</select>
            ${words}
            <label class="form-label small fw-semibold mb-1 mt-2">${esc(t('scenario.order_to_label'))}</label>
            <select class="form-select form-select-sm" data-sc-o="to">
                <option value="">${esc(t('scenario.order_to_pick'))}</option>
                <option value="all"${d.to === 'all' ? ' selected' : ''}>${esc(t('scenario.target_all_people'))}</option>
                ${teamOptions}
                <optgroup label="${esc(t('scenario.slot_option_group'))}">${slotOptions}</optgroup>
            </select>
            <label class="form-label small fw-semibold mb-1 mt-2">${esc(t('scenario.order_phase_label'))}</label>
            <select class="form-select form-select-sm" data-sc-o="phase">${phaseOptions}</select>
            <div class="small text-muted mt-2">${esc(t('scenario.order_hint'))}</div>
            ${d.note ? `<div class="small text-success mt-1" data-sc-order-note="1"><i class="bi bi-check-circle-fill me-1"></i>${esc(d.note)}</div>` : ''}
            <div class="d-flex gap-2 mt-2">
                <button type="button" class="btn btn-outline-secondary btn-sm flex-fill" data-sc="order-cancel">${esc(t('common.cancel'))}</button>
                <button type="button" class="btn btn-primary btn-sm flex-fill" data-sc="order-stage"><i class="bi bi-collection-play me-1"></i>${esc(t('scenario.order_stage_btn'))}</button>
            </div>
        </div>`;
    }

    // A field of the form changed: remember it. The kind decides whether there are words to type, so it redraws; a
    // new slot is asked for by name and, once made, is the one chosen.
    function onOrderField(el) {
        const field = el.dataset.scO;
        if (field !== 'type' && field !== 'to' && field !== 'phase' && field !== 'text') return;
        orderDraft[field] = el.value;
        orderDraft.note = '';
        if (field === 'type') {
            renderCard();
        } else if (field === 'to' && el.value === 'slot:new') {
            busy = true;
            newSlot().then(id => { orderDraft.to = id ? 'slot:' + id : ''; }).finally(() => { busy = false; renderCard(); });
        }
    }

    // The Action Room's card (v3.462.0): one chip per phase and nothing to press - «Φάση 1 · 4/6 ✓», «Φάση 2 · περιμένουν 2 ομάδες»,
    // «Φάση 3 · δεν στάλθηκε · εντολές: 5». The page behind the link beside it has everything else.
    const SUMMARY_MAX_CHIPS = 6;
    function phaseChip(phase, previous) {
        const parts = [phaseName(phase)];
        let tone = 'idle';
        if (phase.played_at) {
            const pr = phase.progress || {};
            if (pr.total > 0) parts.push(t('scenario.progress_badge', {done: pr.done, total: pr.total}));
            else if (pr.withdrawn > 0) parts.push(t('scenario.state_withdrawn'));
            const waits = (Array.isArray(phase.waits) ? phase.waits : []).filter(w => !w.cancelled);
            const blocked = waits.filter(w => w.cant).length;
            const waiting = waits.length - blocked;
            const failed = phase.items.filter(i => !i.sent && i.error).length;
            if (waiting > 0) parts.push(waiting === 1 ? t('scenario.summary_waiting_one') : t('scenario.summary_waiting_many', {n: waiting}));
            if (blocked > 0) parts.push(t('scenario.summary_blocked', {n: blocked}));
            if (failed > 0) parts.push(t('scenario.summary_failed', {n: failed}));
            tone = (blocked > 0 || failed > 0) ? 'bad' : (waiting > 0 ? 'wait' : (pr.complete ? 'ok' : 'run'));
        } else {
            parts.push(phase.items.length ? t('scenario.summary_unsent', {n: phase.items.length}) : t('scenario.phase_no_orders'));
            // The same passive note as the page's «έτοιμη η Φάση n+1»: the earlier phase is finished, so this one is next.
            if (phase.items.length && hintEnabled() && previous && previous.played_at && previous.progress && previous.progress.complete) {
                parts.push(t('scenario.summary_ready'));
                tone = 'ready';
            }
        }
        return `<span class="wr-sc-chip wr-sc-chip-${tone}" data-sc-chip="${phase.id}">${esc(parts.join(' · '))}</span>`;
    }

    function summaryHtml() {
        if (!scenario.phases.length) return `<div class="small text-muted" data-sc-summary="empty">${esc(t('scenario.summary_empty'))}</div>`;
        const shown = scenario.phases.slice(0, SUMMARY_MAX_CHIPS);
        const more = scenario.phases.length - shown.length;
        const chips = shown.map((phase, i) => phaseChip(phase, i > 0 ? scenario.phases[i - 1] : null)).join('');
        return `<div class="wr-sc-chips" data-sc-summary="chips">${chips}${more > 0 ? `<span class="wr-sc-chip">${esc(t('scenario.summary_more', {n: more}))}</span>` : ''}</div>`;
    }

    function renderCard() {
        const body = document.getElementById('scenarioBody');
        if (!body) return;
        if (!pageMode) {
            body.innerHTML = summaryHtml();
            return;
        }
        const add = `<button type="button" class="btn btn-outline-primary btn-sm w-100" data-sc="add-phase"><i class="bi bi-plus-lg me-1"></i>${esc(t('scenario.add_phase_btn'))}</button>`;
        const lead = scenario.phases.length
            ? `<div class="wr-sc-grid">${scenario.phases.map(phaseHtml).join('')}</div>`
            : `<p class="small text-muted mb-2">${esc(t('scenario.empty'))}</p>`;
        const hintToggle = scenario.phases.length > 1
            ? `<label class="d-flex align-items-start gap-1 small text-muted mt-2"><input type="checkbox" class="form-check-input mt-1" data-sc-hint="1"${hintEnabled() ? ' checked' : ''}><span>${esc(t('scenario.hint_toggle'))}</span></label>` : '';
        body.innerHTML = lead + add + orderFormHtml() + hintToggle + slotsHtml();
    }

    function findPhase(id) {
        return scenario.phases.find(p => String(p.id) === String(id)) || null;
    }
    function findItem(id) {
        for (const p of scenario.phases) {
            const hit = p.items.find(i => String(i.id) === String(id));
            if (hit) return {phase: p, item: hit};
        }
        return null;
    }

    function playConfirmText(phase) {
        const pending = phase.items.filter(i => !i.sent);
        const names = [];
        pending.forEach(i => {
            // A search area goes to nobody: it names no recipient (v3.459.0). Unless it is all the phase has, in which case the
            // line has to say something, and the area is on the map for everybody it concerns: «για όλους».
            // A building goes to nobody either: the team of its sector searches it (v3.460.0).
            if ((i.type === 'search_area' || i.type === 'building') && pending.some(o => o.type !== 'search_area' && o.type !== 'building')) return;
            // A restricted area is for everybody in the field, and the confirmation says so («προς: για όλους»).
            const label = i.audience === 'all' ? (i.type === 'order' ? t('scenario.target_all_people') : t('scenario.target_all')) : itemTarget(i);
            if (!names.includes(label)) names.push(label);
        });
        let text = t('scenario.play_confirm', {phase: phaseName(phase), orders: pending.length, targets: names.join(', ')});
        const missing = pending.filter(i => i.team_missing).length;
        if (missing) text += '\n\n' + t('scenario.play_confirm_missing', {n: missing});
        const noArea = pending.filter(i => i.area_missing).length;
        if (noArea) text += '\n\n' + t('scenario.play_confirm_area_missing', {n: noArea});
        const noSector = pending.filter(i => i.sector_missing).length;
        if (noSector) text += '\n\n' + t('scenario.play_confirm_sector_missing', {n: noSector});
        const noPeople = pending.filter(i => i.team_empty).length;
        if (noPeople) text += '\n\n' + t('scenario.play_confirm_team_empty', {n: noPeople});
        // Who gets their orders at once and who waits until they have finished the earlier phases (v3.461.0), right after the first paragraph.
        const pv = phase.release_preview;
        if (pv && Array.isArray(pv.wait) && pv.wait.length) {
            const lines = [];
            if (pv.now.length) lines.push(t('scenario.play_confirm_now', {teams: pv.now.map(x => x.team_label).join(', ')}));
            lines.push(t('scenario.play_confirm_wait', {teams: pv.wait.map(x => x.cant
                ? t('scenario.play_confirm_wait_cant', {team: x.team_label})
                : t('scenario.play_confirm_wait_team', {team: x.team_label, phase: t('scenario.phase_n', {n: x.phase_n}), done: x.done, of: x.of})).join(', ')}));
            const cut = text.indexOf('\n\n');
            text = cut < 0 ? text + '\n\n' + lines.join('\n') : text.slice(0, cut) + '\n\n' + lines.join('\n') + text.slice(cut);
        }
        return text;
    }

    // The slots the unsent orders of a phase go to that stand for no team yet: [{id, label}], each once.
    function unmappedSlotsOf(phase) {
        const out = [];
        phase.items.forEach(item => {
            if (!item.sent && item.audience === 'slot' && item.unmapped && !out.some(s => String(s.id) === String(item.slot_id))) {
                out.push({id: item.slot_id, label: item.slot_label || ''});
            }
        });
        return out;
    }

    // «Ποια ομάδα είναι ποια;»: resolves with {slotId: teamId} once every slot has a team, or null for «Ακύρωση».
    // Built from the page's own report dialog look, appended to <body>, outside everything the poll rebuilds.
    function askSlotMapping(phase, slots) {
        if (document.querySelector('.wr-report-dialog')) return Promise.resolve(null);
        return new Promise(resolve => {
            const teams = scenario.teams;
            const wrap = document.createElement('div');
            wrap.className = 'wr-report-dialog';
            wrap.setAttribute('role', 'dialog');
            wrap.setAttribute('aria-modal', 'true');
            const rows = slots.map(slot => `<div class="mb-2">
                <label class="form-label small fw-semibold mb-1">${esc(slot.label)}</label>
                <select class="form-select" data-map-slot="${slot.id}">
                    <option value="">${esc(t('scenario.slot_pick_team'))}</option>
                    ${teams.map(team => `<option value="${team.id}">${esc(team.label)}</option>`).join('')}
                </select>
            </div>`).join('');
            wrap.innerHTML = `<div class="wr-report-box">
                <div class="fw-semibold mb-1"><i class="bi bi-collection-play-fill text-primary me-1"></i>${esc(t('scenario.map_title'))}</div>
                <div class="small text-muted mb-3">${esc(teams.length ? t('scenario.map_hint', {phase: phaseName(phase)}) : t('scenario.map_no_teams'))}</div>
                ${teams.length ? rows : ''}
                <div class="d-flex gap-2 mt-3">
                    <button type="button" class="btn btn-outline-secondary flex-fill" data-map="cancel">${esc(t('common.cancel'))}</button>
                    ${teams.length ? `<button type="button" class="btn btn-success flex-fill" data-map="ok" disabled><i class="bi bi-play-fill me-1"></i>${esc(t('scenario.map_confirm_btn'))}</button>` : ''}
                </div>
            </div>`;
            const okBtn = wrap.querySelector('[data-map="ok"]');
            const selects = [...wrap.querySelectorAll('select[data-map-slot]')];
            const finish = value => {
                document.removeEventListener('keydown', onKey, true);
                wrap.remove();
                resolve(value);
            };
            const onKey = e => { if (e.key === 'Escape') { e.preventDefault(); finish(null); } };
            selects.forEach(sel => sel.addEventListener('change', () => { okBtn.disabled = selects.some(x => !x.value); }));
            wrap.querySelector('[data-map="cancel"]').addEventListener('click', () => finish(null));
            if (okBtn) okBtn.addEventListener('click', () => {
                if (selects.some(x => !x.value)) return;
                const mapping = {};
                selects.forEach(sel => { mapping[sel.dataset.mapSlot] = sel.value; });
                finish(mapping);
            });
            document.addEventListener('keydown', onKey, true);
            document.body.appendChild(wrap);
            if (selects[0]) selects[0].focus();
        });
    }

    // A new slot: asks for its name (the next free «Ομάδα Α», «Ομάδα Β»... is the suggestion) and resolves with
    // its id, or null when the person said no or the server refused (and has already been told why).
    function newSlot() {
        const letters = t('scenario.slot_letters');
        let suggestion = '';
        for (let i = 0; i < 200; i++) {
            suggestion = t('scenario.slot_default_label', {letter: i < letters.length ? letters.charAt(i) : String(i + 1)});
            if (!scenario.slots.some(s => String(s.label).toLowerCase() === suggestion.toLowerCase())) break;
        }
        const label = prompt(t('scenario.slot_new_prompt'), suggestion);
        if (label === null) return Promise.resolve(null);
        return request('add_slot', {label: label})
            .then(res => { if (res && res.ok) return res.slot_id; failed(res); return null; })
            .catch(() => { alert(t('common.send_failed')); return null; });
    }

    // The slots as options of any recipient list (the point/area window's): one group at the end, the slots
    // with the team each stands for, and «+ Νέα θέση ομάδας…». What is selected stays selected.
    function fillSlotOptions(select) {
        if (!select) return;
        let group = select.querySelector('optgroup[data-sc-slots]');
        if (!group) {
            group = document.createElement('optgroup');
            group.setAttribute('data-sc-slots', '1');
            select.appendChild(group);
        }
        group.label = t('scenario.slot_option_group');
        const keep = select.value;
        group.innerHTML = scenario.slots.map(slot =>
            `<option value="slot:${slot.id}">${esc(slot.label)}${slot.team_label ? ' → ' + esc(slot.team_label) : ''}</option>`
        ).join('') + `<option value="slot:new">${esc(t('scenario.slot_option_new'))}</option>`;
        if (keep && [...select.options].some(o => o.value === keep)) select.value = keep;
    }

    // The words of an order being typed are kept as they are typed, so nothing that redraws the card loses them.
    function onCardInput(e) {
        const field = e.target.closest('[data-sc-o="text"]');
        if (field) orderDraft.text = field.value;
    }

    function onCardChange(e) {
        const orderField = e.target.closest('[data-sc-o]');
        if (orderField) {
            onOrderField(orderField);
            return;
        }
        const hint = e.target.closest('input[data-sc-hint]');
        if (hint) {
            setHintEnabled(hint.checked);
            renderCard();
            return;
        }
        const sel = e.target.closest('select[data-sc-map]');
        if (!sel || busy) return;
        busy = true;
        sel.disabled = true;
        request('map_slot', {slot_id: sel.dataset.scMap, team_id: sel.value})
            .then(res => { if (!res || !res.ok) failed(res); })
            .catch(() => alert(t('common.send_failed')))
            .finally(() => { busy = false; sel.disabled = false; });
    }

    // What the Action Room does when the page sends it here (?sc_do=...&sc_item=...): the three things that need its map. Returns
    // false when the order is not there to act on any more (sent, taken off the plan, or never had an outline).
    function runMapAction(act, itemId) {
        const hit = findItem(itemId);
        if (!hit) return false;
        const item = hit.item;
        if (act === 'goto') {
            // «Δείξε το στον χάρτη»: the card of the map is brought into view and the map goes to the order.
            if (typeof map !== 'undefined' && map && item.geo) {
                if (typeof scrollToCard === 'function') scrollToCard('mapCard');
                if (item.type === 'point') map.setView([item.geo.lat, item.geo.lng], Math.max(map.getZoom(), 16));
                else if (Array.isArray(item.geo) && item.geo.length === 1) map.setView(item.geo[0], Math.max(map.getZoom(), 16));
                else map.fitBounds(L.latLngBounds(item.geo), {padding: [40, 40]});
            }
            return true;
        }
        if (act === 'divide-area') {
            // «Τομείς» on an area the scenario has still to make (v3.459.0): the division window opens on it. The sectors cut
            // there are written down against this area (item:<id>), not made. The page's own function, as for a live area.
            if (item.type !== 'search_area' || item.sent || !Array.isArray(item.geo) || item.geo.length < 3) return false;
            if (typeof openDivideSectorsForStagedArea !== 'function') return false;
            openDivideSectorsForStagedArea({item_id: item.id, label: item.label || '', geo: item.geo});
            return true;
        }
        if (act === 'add-building') {
            // «Κτίριο» on a sector the scenario has still to make (v3.460.0): the next click on the map, inside that sector, opens
            // the popup that writes the building down against this sector ('item:<id>'). The page's own function arms it.
            if (item.type !== 'sector' || item.sent || !Array.isArray(item.geo) || item.geo.length < 3) return false;
            if (typeof armStagedBuilding !== 'function') return false;
            armStagedBuilding({item_id: item.id, label: item.label || '', geo: item.geo, phase_id: hit.phase.id});
            return true;
        }
        return false;
    }

    // The Action Room, opened from a link of the Scenario page, does what the link asked once the plan is here, and takes the
    // asking out of its address so a reload does not do it again. Once per page load; the page itself never does this.
    function consumeDeepLink() {
        if (deepLinkDone || pageMode) return;
        deepLinkDone = true;
        let url;
        try { url = new URL(location.href); } catch (e) { return; }
        const act = url.searchParams.get('sc_do');
        const itemId = url.searchParams.get('sc_item');
        if (!act || !itemId) return;
        try {
            url.searchParams.delete('sc_do');
            url.searchParams.delete('sc_item');
            history.replaceState(null, '', url.pathname + url.search + url.hash);
        } catch (e) { /* the address stays: a reload would only ask again */ }
        // The Action Room frames the mission on the map as it opens, and that would take the map away from the order again: so the
        // action waits until the page has loaded and has had a moment to settle (the action happens in front of the person).
        const run = () => setTimeout(() => { if (!runMapAction(act, itemId)) alert(t('scenario.deeplink_gone')); }, DEEP_LINK_DELAY_MS);
        if (document.readyState === 'complete') run();
        else window.addEventListener('load', run, {once: true});
    }

    function onCardClick(e) {
        const el = e.target.closest('[data-sc]');
        if (!el) return;
        // A link to the Action Room (v3.462.0) is a link: the browser opens it, in the tab named for it.
        if (el.dataset.scLink === '1') return;
        e.preventDefault();
        if (busy) return;
        const act = el.dataset.sc;

        const run = (action, fields, after) => {
            busy = true;
            el.disabled = true;
            request(action, fields).then(res => {
                if (res && res.ok) { if (after) after(res); } else { failed(res); }
            }).catch(() => alert(t('common.send_failed'))).finally(() => { busy = false; el.disabled = false; });
        };

        if (act === 'add-phase') {
            run('add_phase', {});
        } else if (act === 'order-open') {
            orderDraft.open = true;
            orderDraft.note = '';
            renderCard();
        } else if (act === 'order-cancel') {
            orderDraft.open = false;
            orderDraft.note = '';
            renderCard();
        } else if (act === 'order-stage') {
            // The server checks all of it (the kind, the words, who it goes to) and says what is wrong in words.
            run('add_order', {phase_id: orderPhase(), order_type: orderDraft.type, text: orderDraft.text, team_id: orderDraft.to}, res => {
                orderDraft.text = '';
                orderDraft.phase = String(res.phase_id);
                orderDraft.note = t('scenario.order_staged', {n: phaseNumber(res.phase_id)});
                renderCard();
            });
        } else if (act === 'add-slot') {
            busy = true;
            newSlot().finally(() => { busy = false; });
        } else if (act === 'delete-slot') {
            const slot = scenario.slots.find(x => String(x.id) === String(el.dataset.slot));
            if (!slot) return;
            if (!confirm(t('scenario.slot_delete_confirm', {label: slot.label}))) return;
            run('delete_slot', {slot_id: el.dataset.slot});
        } else if (act === 'rename') {
            const phase = findPhase(el.dataset.phase);
            if (!phase) return;
            const title = prompt(t('scenario.phase_rename_prompt', {phase: phaseName(phase)}), phase.title || '');
            if (title === null) return;
            run('rename_phase', {phase_id: el.dataset.phase, title: title});
        } else if (act === 'delete-phase') {
            const phase = findPhase(el.dataset.phase);
            if (!phase) return;
            if (!confirm(t(phase.items.length ? 'scenario.phase_delete_confirm_orders' : 'scenario.phase_delete_confirm', {phase: phaseName(phase), n: phase.items.length}))) return;
            run('delete_phase', {phase_id: el.dataset.phase});
        } else if (act === 'delete-item') {
            run('delete_item', {item_id: el.dataset.item});
        } else if (act === 'play') {
            const phase = findPhase(el.dataset.phase);
            if (!phase) return;
            const afterPlay = res => {
                if (res.dispatches && typeof renderDispatches === 'function') renderDispatches(dispatches = res.dispatches);
                showNewSectors(res);
                showNewRoutes(res);
                showNewRestricted(res);
                if (res.failed > 0) {
                    const lines = (res.results || []).filter(r => !r.ok).map(r => '• ' + r.error);
                    alert(t('scenario.play_partial', {sent: res.sent, failed: res.failed}) + '\n\n' + lines.join('\n'));
                }
            };
            const lacking = unmappedSlotsOf(phase);
            if (!lacking.length) {
                if (!confirm(playConfirmText(phase))) return;
                run('play_phase', {phase_id: el.dataset.phase}, afterPlay);
                return;
            }
            // Some order goes to a slot that stands for no team yet: ask which team is which, and send nothing
            // until every one has an answer. The server refuses the phase too if it is ever asked without them.
            busy = true;
            askSlotMapping(phase, lacking).then(mapping => {
                busy = false;
                if (!mapping) return;
                const fields = {phase_id: el.dataset.phase};
                Object.keys(mapping).forEach(slotId => { fields['mapping[' + slotId + ']'] = mapping[slotId]; });
                run('play_phase', fields, afterPlay);
            });
        } else if (act === 'withdraw') {
            const phase = findPhase(el.dataset.phase);
            if (!phase || !phase.progress) return;
            let sure = t('scenario.withdraw_confirm', {phase: phaseName(phase), n: phase.progress.withdrawable != null ? phase.progress.withdrawable : phase.progress.open});
            // A restricted area that stands is taken off the map by it: say so plainly (v3.458.0), before the question
            // that closes the confirmation (its last paragraph).
            if (phase.progress.standing > 0) {
                const cut = sure.lastIndexOf('\n\n');
                const note = t('scenario.withdraw_confirm_zones');
                sure = cut < 0 ? sure + '\n\n' + note : sure.slice(0, cut) + '\n\n' + note + sure.slice(cut);
            }
            // Orders of this phase that are only waiting for their team are cancelled with it, and orders of LATER phases that wait for this one
            // are not held back by it any more once it is taken back: both are said before the question (v3.461.0).
            const extra = [];
            if (phase.progress.waiting > 0) extra.push(t('scenario.withdraw_confirm_waits', {n: phase.progress.waiting}));
            const later = scenario.phases.filter(p => p.n > phase.n).reduce((n, p) => n + (p.items || []).filter(i => i.state === 'waiting' && i.wait && i.wait.phase_n === phase.n).length, 0);
            if (later > 0) extra.push(t('scenario.withdraw_confirm_later', {n: later}));
            if (extra.length) {
                const cut = sure.lastIndexOf('\n\n');
                sure = cut < 0 ? sure + '\n\n' + extra.join('\n\n') : sure.slice(0, cut) + '\n\n' + extra.join('\n\n') + sure.slice(cut);
            }
            if (!confirm(sure)) return;
            run('withdraw_phase', {phase_id: el.dataset.phase}, res => {
                if (res.dispatches && typeof renderDispatches === 'function') renderDispatches(dispatches = res.dispatches);
                showNewSectors(res);
                showNewRoutes(res);
                showNewRestricted(res);
                if (res.failed > 0) {
                    const lines = (res.results || []).filter(r => !r.ok).map(r => '• ' + r.error);
                    alert(t('scenario.withdraw_partial', {withdrawn: res.withdrawn, failed: res.failed}) + '\n\n' + lines.join('\n'));
                }
            });
        } else if (act === 'release-now') {
            // «Στείλε τώρα» (v3.461.0): the team's orders go now, without waiting for it to finish. A team that has not finished is asked about;
            // one whose wait was cancelled is not (command already decided about it).
            const phase = findPhase(el.dataset.phase);
            const wait = phase && (phase.waits || []).find(w => String(w.team_id) === String(el.dataset.team) && (w.cancelled ? '1' : '0') === el.dataset.cancelled);
            if (!wait) return;
            if (!wait.cancelled && wait.phase_n && !confirm(t('scenario.release_now_confirm', {team: wait.team_label, phase: t('scenario.phase_n', {n: wait.phase_n}), done: wait.done, of: wait.of}))) return;
            run('release_now', {phase_id: el.dataset.phase, team_id: el.dataset.team}, res => {
                if (res.dispatches && typeof renderDispatches === 'function') renderDispatches(dispatches = res.dispatches);
                showNewSectors(res);
                showNewRoutes(res);
                showNewRestricted(res);
                if (res.failed > 0) {
                    const lines = (res.results || []).filter(r => !r.ok).map(r => '• ' + r.error);
                    alert(t('scenario.play_partial', {sent: res.sent, failed: res.failed}) + '\n\n' + lines.join('\n'));
                }
            });
        } else if (act === 'cancel-wait') {
            const phase = findPhase(el.dataset.phase);
            const wait = phase && (phase.waits || []).find(w => String(w.team_id) === String(el.dataset.team) && !w.cancelled);
            if (!wait) return;
            if (!confirm(t('scenario.cancel_wait_confirm', {team: wait.team_label}))) return;
            run('cancel_wait', {phase_id: el.dataset.phase, team_id: el.dataset.team});
        } else if (act === 'retry') {
            run('retry_item', {item_id: el.dataset.item}, res => {
                if (res.dispatches && typeof renderDispatches === 'function') renderDispatches(dispatches = res.dispatches);
                showNewSectors(res);
                showNewRoutes(res);
                showNewRestricted(res);
                if (res.item && !res.item.ok) alert(res.item.error || t('common.send_failed'));
            });
        }
    }

    // ── Ghosts ─────────────────────────────────────────────────────────────────────────────────────────────────
    function pinIcon(n) {
        return L.divIcon({className: '', html: `<div class="wr-sc-ghost-pin"><span>${esc(n)}</span></div>`, iconSize: [26, 26], iconAnchor: [13, 13]});
    }

    function buildingPinIcon(n) {
        return L.divIcon({className: '', html: `<div class="wr-sc-ghost-pin wr-sc-ghost-building"><span>${esc(n)}</span></div>`, iconSize: [26, 26], iconAnchor: [13, 13]});
    }

    // Every order not yet sent, as a dashed grey shape with its phase number, into any layer group - the live
    // map's own, and the point/area window's read-only copy of the live map.
    function drawGhostsInto(layer) {
        scenario.phases.forEach(phase => {
            phase.items.forEach(item => {
                if (item.sent || !item.geo) return;
                // A search area goes to nobody, so its tooltip has no «→ …» part (v3.459.0).
                const tip = ((item.type === 'search_area' || item.type === 'building') ? [phaseName(phase), itemTitle(item)] : [phaseName(phase), itemTitle(item), '→ ' + itemTarget(item)]).join(' · ') + ' — ' + t('scenario.ghost_not_sent');
                if (item.type === 'route') {
                    // A dashed line through the waypoints (back to the first one when the route is a loop), a dot at each
                    // waypoint, and the phase number where it starts.
                    if (!Array.isArray(item.geo) || !item.geo.length) return;
                    const style = {color: '#6b7280', weight: 3, dashArray: '7 6', fill: false};
                    if (item.geo.length >= 3 && item.closed_loop) L.polygon(item.geo, style).bindTooltip(tip, {sticky: true}).addTo(layer);
                    else if (item.geo.length >= 2) L.polyline(item.geo, style).bindTooltip(tip, {sticky: true}).addTo(layer);
                    item.geo.forEach(p => L.circleMarker(p, {radius: 3, color: '#6b7280', weight: 2, fillColor: '#f3f4f6', fillOpacity: 1, interactive: false}).addTo(layer));
                    L.marker(item.geo[0], {icon: pinIcon(phase.n), keyboard: false, zIndexOffset: -500}).bindTooltip(tip).addTo(layer);
                } else if (item.type === 'restricted') {
                    // A zone is drawn as the real one will be - a polygon, or an open line along an edge - but dashed and
                    // dull: red-grey, with the phase number where it starts. (The real one is bright red.)
                    if (!Array.isArray(item.geo) || !item.geo.length) return;
                    const zoneStyle = {color: '#a05a5a', weight: 3, dashArray: '4 5'};
                    if (item.shape === 'line') {
                        if (item.geo.length < 2) return;
                        L.polyline(item.geo, zoneStyle).bindTooltip(tip, {sticky: true}).addTo(layer);
                        L.marker(item.geo[0], {icon: pinIcon(phase.n), keyboard: false, zIndexOffset: -500}).addTo(layer);
                    } else if (item.geo.length >= 3) {
                        const zone = L.polygon(item.geo, Object.assign({fillColor: '#dc3545', fillOpacity: 0.06}, zoneStyle))
                            .bindTooltip(tip, {sticky: true}).addTo(layer);
                        L.marker(zone.getBounds().getCenter(), {icon: pinIcon(phase.n), keyboard: false, interactive: false}).addTo(layer);
                    }
                } else if (item.type === 'building') {
                    // One point: a small dashed square with the phase number where the building will be (a point is a circle).
                    if (!Array.isArray(item.geo) || !item.geo.length) return;
                    L.marker(item.geo[0], {icon: buildingPinIcon(phase.n), keyboard: false, zIndexOffset: -500}).bindTooltip(tip).addTo(layer);
                } else if (item.type === 'point') {
                    L.marker([item.geo.lat, item.geo.lng], {icon: pinIcon(phase.n), keyboard: false, zIndexOffset: -500})
                        .bindTooltip(tip).addTo(layer);
                } else if (Array.isArray(item.geo) && item.geo.length >= 3) {
                    const poly = L.polygon(item.geo, {color: '#6b7280', weight: 2, dashArray: '7 6', fillColor: '#9ca3af', fillOpacity: 0.1})
                        .bindTooltip(tip, {sticky: true}).addTo(layer);
                    L.marker(poly.getBounds().getCenter(), {icon: pinIcon(phase.n), keyboard: false, interactive: false}).addTo(layer);
                }
            });
        });
    }

    function drawGhosts() {
        if (!ghostLayer && typeof map !== 'undefined' && map) ghostLayer = L.layerGroup().addTo(map);
        if (!ghostLayer) return;
        ghostLayer.clearLayers();
        drawGhostsInto(ghostLayer);
    }

    // ── For the point/area window ─────────────────────────────────────────────────────────────────────────────
    function menuHtml() {
        const open = scenario.phases.filter(p => !p.played_at);
        let html = open.map(p =>
            `<li><a class="dropdown-item" href="#" data-sc-phase="${p.id}">${esc(phaseName(p))}${p.title ? ' · ' + esc(p.title) : ''} <span class="text-muted small">(${p.items.length})</span></a></li>`
        ).join('');
        if (open.length) html += '<li><hr class="dropdown-divider"></li>';
        html += `<li><a class="dropdown-item" href="#" data-sc-phase="new"><i class="bi bi-plus-lg me-1"></i>${esc(t('scenario.new_phase_n', {n: scenario.phases.length + 1}))}</a></li>`;
        return html;
    }

    // Puts an order into a phase ('new' = a phase made for it). Resolves with the endpoint's answer; a refusal
    // has already been shown to the person.
    function stage(phaseId, fields) {
        return request('add_item', Object.assign({phase_id: String(phaseId)}, fields))
            .then(res => { if (!res || !res.ok) failed(res); return res; })
            .catch(() => { alert(t('common.send_failed')); return {ok: false}; });
    }

    // The sector window's «Στο σενάριο»: every piece of one division of the search area areaId goes into the phase
    // together or not at all. sectors = [{team_id: '39' | 'slot:3', label, geo: [[lat, lng], ...]}, ...]. Resolves
    // with the endpoint's answer (phase_id, count on success); a refusal has already been shown to the person.
    function stageSectors(phaseId, areaId, sectors) {
        return request('add_sectors', {phase_id: String(phaseId), area_id: String(areaId), sectors: JSON.stringify(sectors)})
            .then(res => { if (!res || !res.ok) failed(res); return res; })
            .catch(() => { alert(t('common.send_failed')); return {ok: false}; });
    }

    // The route window's «Στο σενάριο»: the route drawn there goes into the phase instead of out to a team. fields =
    // {team_id: '39' | 'slot:3', title, waypoints: JSON text, is_closed_loop: '1'|'0', add_return_waypoint: '1'|'0',
    // member_ids: JSON text, travel_mode}. A route has no «όλες οι ομάδες»: the empty team of the window ("people
    // picked across teams") is refused by the server. Resolves with the endpoint's answer (phase_id on success); a
    // refusal has already been shown to the person.
    function stageRoute(phaseId, fields) {
        return request('add_route', Object.assign({phase_id: String(phaseId)}, fields))
            .then(res => { if (!res || !res.ok) failed(res); return res; })
            .catch(() => { alert(t('common.send_failed')); return {ok: false}; });
    }

    // The restricted-area window's «Στο σενάριο»: the zone drawn there goes into the phase instead of being made now. fields =
    // {label, kind: 'danger'|'cliff'|'forbidden', shape: 'polygon'|'line', geo: JSON text of [[lat, lng], ...]}. A zone has no
    // team or slot. Resolves with the endpoint's answer (phase_id on success); a refusal has already been shown to the person.
    function stageRestricted(phaseId, fields) {
        return request('add_restricted', Object.assign({phase_id: String(phaseId)}, fields))
            .then(res => { if (!res || !res.ok) failed(res); return res; })
            .catch(() => { alert(t('common.send_failed')); return {ok: false}; });
    }

    // The search-area window's «Στο σενάριο»: the area drawn there goes into the phase instead of being made now, so that
    // sectors can be written down against it (the «Τομείς» button of its row). fields = {label, geo: JSON text of
    // [[lat, lng], ...]}. An area has no team, slot or «όλες οι ομάδες». Resolves with the endpoint's answer (phase_id on
    // success); a refusal has already been shown to the person.
    function stageArea(phaseId, fields) {
        return request('add_area', Object.assign({phase_id: String(phaseId)}, fields))
            .then(res => { if (!res || !res.ok) failed(res); return res; })
            .catch(() => { alert(t('common.send_failed')); return {ok: false}; });
    }

    // The building popup's «Στο σενάριο» (v3.460.0): a building put on the map inside a sector goes into the phase instead of being made
    // now. fields = {sector_id: 'item:<id>' (a sector the scenario makes) | the id of a sector that exists, label, lat, lng,
    // floor_count}. A building has no team or slot. Resolves with the endpoint's answer (phase_id on success); a refusal (a point
    // outside the sector, a phase before the sector) has already been shown to the person.
    function stageBuilding(phaseId, fields) {
        return request('add_building', Object.assign({phase_id: String(phaseId)}, fields))
            .then(res => { if (!res || !res.ok) failed(res); return res; })
            .catch(() => { alert(t('common.send_failed')); return {ok: false}; });
    }

    function phaseNumber(phaseId) {
        const p = findPhase(phaseId);
        return p ? p.n : null;
    }

    function init() {
        if (initialised) return;
        initialised = true;
        injectCss();
        const body = document.getElementById('scenarioBody');
        pageMode = !!(body && body.dataset && body.dataset.scMode === 'page');
        // The Action Room names its window, once, so the page's links reach this very tab (a window that already has a name keeps it).
        if (!pageMode) {
            try { if (!window.name) window.name = roomTarget(); } catch (e) { /* a sandboxed frame: the links open a tab of their own */ }
        }
        if (body && pageMode) {
            body.addEventListener('click', onCardClick);
            body.addEventListener('change', onCardChange);
            body.addEventListener('input', onCardInput);
        }
        // The page waits for the plan before drawing (its placeholder says so): an empty card for a second would read as "no
        // scenario". The Action Room's summary is small enough to start from empty.
        if (!pageMode) renderCard();
        load();
        document.addEventListener('visibilitychange', () => { if (!document.hidden) load(); });
        setInterval(refreshProgress, REFRESH_MS);
    }

    window.wrScenario = {init: init, reload: load, menuHtml: menuHtml, stage: stage, stageSectors: stageSectors, stageRoute: stageRoute, stageRestricted: stageRestricted, stageArea: stageArea, stageBuilding: stageBuilding, phaseNumber: phaseNumber, drawGhostsInto: drawGhostsInto, phases: () => scenario.phases, newSlot: newSlot, fillSlotOptions: fillSlotOptions};
})();
