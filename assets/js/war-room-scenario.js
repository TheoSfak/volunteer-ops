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
 *   - the team slots (v3.453.0): names for teams that may not exist yet, in the card, in the window's recipient
 *     list, and in the dialog that play opens to ask which team is which.
 *
 * The plan is never part of the 5-second poll or of WR_BOOT: it is fetched here, once when the page opens and
 * again whenever the tab comes back to the front or an action changes it (every answer of mission-scenario.php
 * carries the whole plan). A second coordinator's change shows up at the next of those.
 *
 * Loaded before the page's own script, so it only DECLARES things here and reaches for the page's globals (map,
 * dispatches, csrfToken, WR_MISSION_ID, t(), escapeHtml(), renderDispatches()) when it is called.
 */
(function () {
    const ENDPOINT = 'mission-scenario.php';

    let scenario = {phases: [], slots: [], teams: []};
    let ghostLayer = null;
    let initialised = false;
    let busy = false;
    let lastJson = '';
    // While an order of a played phase is still under way its progress moves on its own (a team presses «Ολοκληρώθηκε»),
    // so the card asks again now and then - only while the tab is in front, nobody is choosing in the card and there is
    // something to wait for. It redraws only when the answer differs, so a select being used is never torn down.
    const REFRESH_MS = 20000;

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
    // What the «Εντολή σε άτομα» form holds while it is open. Kept here, not in the form, so a redraw of the card
    // (an action of another coordinator, the 20-second refresh) never loses what is being typed.
    let orderDraft = {open: false, type: 'task', text: '', to: '', phase: '', note: ''};

    function esc(value) {
        return escapeHtml(String(value == null ? '' : value));
    }

    function injectCss() {
        if (document.getElementById('wrScenarioCss')) return;
        const style = document.createElement('style');
        style.id = 'wrScenarioCss';
        style.textContent = [
            '.wr-sc-ghost-pin{width:26px;height:26px;border-radius:50%;border:2px dashed #6b7280;background:rgba(243,244,246,.88);color:#374151;font-weight:700;font-size:.8rem;display:flex;align-items:center;justify-content:center;box-shadow:0 1px 3px rgba(0,0,0,.35)}',
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
            '.wr-sc-goto:hover{text-decoration:underline}'
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
            .then(res => { if (res && res.ok) apply(res.scenario); return res; })
            .catch(() => null);
    }

    function underWay() {
        return scenario.phases.some(p => p.played_at && p.progress && p.progress.open > 0);
    }
    function refreshProgress() {
        if (document.hidden || busy || !underWay()) return;
        const body = document.getElementById('scenarioBody');
        const active = document.activeElement;
        if (body && active && body.contains(active) && (active.tagName === 'SELECT' || active.tagName === 'INPUT' || active.tagName === 'TEXTAREA')) return;
        fetch(ENDPOINT + '?mission_id=' + encodeURIComponent(WR_MISSION_ID))
            .then(r => r.json())
            .then(res => { if (res && res.ok && !busy && JSON.stringify(res.scenario) !== lastJson) apply(res.scenario); })
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

    // ── Words ──────────────────────────────────────────────────────────────────────────────────────────────────
    function phaseName(phase) {
        return t('scenario.phase_n', {n: phase.n});
    }
    function itemKind(item) {
        if (item.type === 'sector') return t('scenario.kind_sector');
        if (item.type === 'route') return t('scenario.kind_route');
        if (item.type === 'order') return t(ORDER_LABEL_KEYS[item.order_type] || 'scenario.order_add_btn');
        return item.type === 'point' ? t('scenario.kind_point') : t('scenario.kind_area');
    }
    function itemTarget(item) {
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
        if (item.state === 'done') {
            return `<div class="small text-success"><i class="bi bi-check-circle-fill me-1"></i>${esc(t(ackOnly ? 'scenario.state_done_ack' : 'scenario.state_done'))}</div>`;
        }
        if (item.state === 'withdrawn') {
            return `<div class="small text-muted"><i class="bi bi-slash-circle me-1"></i>${esc(t('scenario.state_withdrawn'))}</div>`;
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
                    : (item.type === 'order' ? (ORDER_ICONS[item.order_type] || 'bi-chat-left-text-fill') : 'bi-bounding-box')));
        const badgeClass = item.team_missing ? 'bg-danger'
            : (item.audience === 'all' ? 'bg-warning text-dark' : (item.audience === 'slot' ? (item.unmapped ? 'bg-warning text-dark' : 'bg-info text-dark') : 'bg-secondary'));
        const target = `<span class="badge ${badgeClass}">${esc(itemTarget(item))}</span>`;
        let status = '';
        if (item.sent) {
            status = stateLine(item);
        } else if (item.error) {
            status = `<div class="small text-danger"><i class="bi bi-exclamation-triangle-fill me-1"></i>${esc(item.error)}</div>`;
        } else if (item.team_missing) {
            status = `<div class="small text-danger"><i class="bi bi-exclamation-triangle-fill me-1"></i>${esc(t('scenario.team_gone_hint'))}</div>`;
        } else if (item.area_missing) {
            status = `<div class="small text-danger"><i class="bi bi-exclamation-triangle-fill me-1"></i>${esc(t('scenario.area_gone_hint'))}</div>`;
        } else if (item.team_empty) {
            status = `<div class="small text-danger"><i class="bi bi-exclamation-triangle-fill me-1"></i>${esc(t('scenario.team_empty_hint'))}</div>`;
        } else if (item.unmapped) {
            status = `<div class="small text-muted"><i class="bi bi-hourglass-split me-1"></i>${esc(t('scenario.slot_unmapped_hint'))}</div>`;
        }
        const retry = (!item.sent && item.error && phase.played_at)
            ? `<button type="button" class="btn btn-outline-primary btn-sm py-0" data-sc="retry" data-item="${item.id}" title="${esc(t('scenario.retry_btn'))}"><i class="bi bi-arrow-repeat"></i></button>` : '';
        const del = !item.sent
            ? `<button type="button" class="btn btn-outline-danger btn-sm py-0" data-sc="delete-item" data-item="${item.id}" title="${esc(t('scenario.item_delete'))}"><i class="bi bi-x-lg"></i></button>` : '';
        // An order to people has no place on the map to show.
        const goto = (item.sent || item.type === 'order')
            ? `<span>${esc(itemTitle(item))}</span>`
            : `<a href="#" class="wr-sc-goto" data-sc="goto" data-item="${item.id}" title="${esc(t('scenario.show_on_map'))}">${esc(itemTitle(item))}</a>`;
        // A sector says which search area it is cut from (the area may be gone: then the line below says so).
        let where = (item.type === 'sector' && item.area_label)
            ? ` <span class="text-muted small">${esc(t('scenario.sector_in_area', {area: item.area_label}))}</span>` : '';
        // A route says how many points it has, and whether the team is to return to where it started.
        if (item.type === 'route') {
            where = ` <span class="text-muted small">${esc(t('scenario.route_points', {n: item.point_count || 0}))}${item.return_to_start ? ' · ' + esc(t('scenario.route_return_hint')) : ''}</span>`;
        }
        return `<li class="wr-sc-item d-flex align-items-start gap-1${item.state === 'withdrawn' ? ' wr-sc-withdrawn' : ''}" data-item="${item.id}">
            <i class="bi ${icon} text-secondary mt-1"></i>
            <span class="flex-grow-1">${goto}${where} <i class="bi bi-arrow-right-short"></i> ${target}${status}</span>
            ${retry}${del}
        </li>`;
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
            ${playedLine}${withdraw}${items}
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

    function renderCard() {
        const body = document.getElementById('scenarioBody');
        if (!body) return;
        const add = `<button type="button" class="btn btn-outline-primary btn-sm w-100" data-sc="add-phase"><i class="bi bi-plus-lg me-1"></i>${esc(t('scenario.add_phase_btn'))}</button>`;
        const lead = scenario.phases.length ? scenario.phases.map(phaseHtml).join('') : `<p class="small text-muted mb-2">${esc(t('scenario.empty'))}</p>`;
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
            const label = i.audience === 'all' ? (i.type === 'order' ? t('scenario.target_all_people') : t('scenario.target_all')) : itemTarget(i);
            if (!names.includes(label)) names.push(label);
        });
        let text = t('scenario.play_confirm', {phase: phaseName(phase), orders: pending.length, targets: names.join(', ')});
        const missing = pending.filter(i => i.team_missing).length;
        if (missing) text += '\n\n' + t('scenario.play_confirm_missing', {n: missing});
        const noArea = pending.filter(i => i.area_missing).length;
        if (noArea) text += '\n\n' + t('scenario.play_confirm_area_missing', {n: noArea});
        const noPeople = pending.filter(i => i.team_empty).length;
        if (noPeople) text += '\n\n' + t('scenario.play_confirm_team_empty', {n: noPeople});
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

    function onCardClick(e) {
        const el = e.target.closest('[data-sc]');
        if (!el) return;
        e.preventDefault();
        if (busy) return;
        const act = el.dataset.sc;

        if (act === 'goto') {
            const hit = findItem(el.dataset.item);
            if (hit && typeof map !== 'undefined' && map && hit.item.geo) {
                if (typeof scrollToCard === 'function') scrollToCard('mapCard');
                if (hit.item.type === 'point') map.setView([hit.item.geo.lat, hit.item.geo.lng], Math.max(map.getZoom(), 16));
                else if (Array.isArray(hit.item.geo) && hit.item.geo.length === 1) map.setView(hit.item.geo[0], Math.max(map.getZoom(), 16));
                else map.fitBounds(L.latLngBounds(hit.item.geo), {padding: [40, 40]});
            }
            return;
        }

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
            if (!confirm(t('scenario.withdraw_confirm', {phase: phaseName(phase), n: phase.progress.withdrawable != null ? phase.progress.withdrawable : phase.progress.open}))) return;
            run('withdraw_phase', {phase_id: el.dataset.phase}, res => {
                if (res.dispatches && typeof renderDispatches === 'function') renderDispatches(dispatches = res.dispatches);
                showNewSectors(res);
                showNewRoutes(res);
                if (res.failed > 0) {
                    const lines = (res.results || []).filter(r => !r.ok).map(r => '• ' + r.error);
                    alert(t('scenario.withdraw_partial', {withdrawn: res.withdrawn, failed: res.failed}) + '\n\n' + lines.join('\n'));
                }
            });
        } else if (act === 'retry') {
            run('retry_item', {item_id: el.dataset.item}, res => {
                if (res.dispatches && typeof renderDispatches === 'function') renderDispatches(dispatches = res.dispatches);
                showNewSectors(res);
                showNewRoutes(res);
                if (res.item && !res.item.ok) alert(res.item.error || t('common.send_failed'));
            });
        }
    }

    // ── Ghosts ─────────────────────────────────────────────────────────────────────────────────────────────────
    function pinIcon(n) {
        return L.divIcon({className: '', html: `<div class="wr-sc-ghost-pin"><span>${esc(n)}</span></div>`, iconSize: [26, 26], iconAnchor: [13, 13]});
    }

    // Every order not yet sent, as a dashed grey shape with its phase number, into any layer group - the live
    // map's own, and the point/area window's read-only copy of the live map.
    function drawGhostsInto(layer) {
        scenario.phases.forEach(phase => {
            phase.items.forEach(item => {
                if (item.sent || !item.geo) return;
                const tip = [phaseName(phase), itemTitle(item), '→ ' + itemTarget(item)].join(' · ') + ' — ' + t('scenario.ghost_not_sent');
                if (item.type === 'route') {
                    // A dashed line through the waypoints (back to the first one when the route is a loop), a dot at each
                    // waypoint, and the phase number where it starts.
                    if (!Array.isArray(item.geo) || !item.geo.length) return;
                    const style = {color: '#6b7280', weight: 3, dashArray: '7 6', fill: false};
                    if (item.geo.length >= 3 && item.closed_loop) L.polygon(item.geo, style).bindTooltip(tip, {sticky: true}).addTo(layer);
                    else if (item.geo.length >= 2) L.polyline(item.geo, style).bindTooltip(tip, {sticky: true}).addTo(layer);
                    item.geo.forEach(p => L.circleMarker(p, {radius: 3, color: '#6b7280', weight: 2, fillColor: '#f3f4f6', fillOpacity: 1, interactive: false}).addTo(layer));
                    L.marker(item.geo[0], {icon: pinIcon(phase.n), keyboard: false, zIndexOffset: -500}).bindTooltip(tip).addTo(layer);
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

    function phaseNumber(phaseId) {
        const p = findPhase(phaseId);
        return p ? p.n : null;
    }

    function init() {
        if (initialised) return;
        initialised = true;
        injectCss();
        const body = document.getElementById('scenarioBody');
        if (body) {
            body.addEventListener('click', onCardClick);
            body.addEventListener('change', onCardChange);
            body.addEventListener('input', onCardInput);
        }
        renderCard();
        load();
        document.addEventListener('visibilitychange', () => { if (!document.hidden) load(); });
        setInterval(refreshProgress, REFRESH_MS);
    }

    window.wrScenario = {init: init, reload: load, menuHtml: menuHtml, stage: stage, stageSectors: stageSectors, stageRoute: stageRoute, phaseNumber: phaseNumber, drawGhostsInto: drawGhostsInto, phases: () => scenario.phases, newSlot: newSlot, fillSlotOptions: fillSlotOptions};
})();
