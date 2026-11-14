/**
 * The «Ομάδες» page (mission-teams-view.php, v3.463.0): the teams of a mission on a page of their own.
 *
 * What command does here:
 *   - the automatic split: one number, how many people a team has. The server works the split out (whoever is left
 *     over goes into the last team, people of one home team stay together) and this page shows it BEFORE it is made;
 *   - a team by hand, out of the people ticked in «Χωρίς ομάδα»;
 *   - «Επεξεργασία» of a team: its name, its leader and how it moves, in one save (the star beside a name is the
 *     quick way to the same change of leader), and deleting it;
 *   - moving one person to another team, with the select beside the name or by dragging the name onto the team;
 *   - GPS: a switch beside every name, three buttons under every team and three for the whole mission. It is off for
 *     everybody until it is switched on here (or in the Action Room).
 *
 * The page holds no rule of its own. Every press is one POST to mission-teams.php, and every answer - a refusal too -
 * carries the teams as they are on the server, which is what is drawn. A second command post that has the page open
 * follows within the 20-second refresh.
 *
 * It reaches for four things the page defines before loading it: WR_MISSION_ID, csrfToken, t() and
 * MT_TRANSPORT_ICONS (the inner markup of the three transport icons), and for escapeHtml() of war-room-utils.js.
 * The same text for every mission: nothing of a mission is written into this file.
 */
(function () {
    'use strict';

    const REFRESH_MS = 20000;
    const TRANSPORTS = ['foot', 'motorbike', 'car'];

    let root = null;
    let state = {teams: [], people: []};
    let loaded = false;
    let busy = false;
    let plan = null;              // the split being shown before it is made: {size, redo, teams: [{member_ids, leader_id}]}
    let autoSize = '5';
    let autoRedo = false;
    let editingTeamId = null;     // the team whose «Επεξεργασία» form is open
    let newTeamName = '';
    const picked = new Set();     // people ticked in «Χωρίς ομάδα» for a team made by hand
    let flashTimer = null;
    let flashHtml = '';

    const esc = (value) => escapeHtml(String(value ?? ''));

    // ── what the state says ─────────────────────────────────────────────────────────────────────────────────

    function peopleById() {
        const map = new Map();
        for (const person of state.people) map.set(person.id, person);
        return map;
    }

    function unassigned() {
        return state.people.filter((person) => person.team_id === null);
    }

    /** Why a person cannot leave their team, or '' when they can: the server refuses the same two cases. */
    function moveLock(person, team) {
        if (!team) return '';
        if (team.leader_id === person.id) return t('teamsp.move_locked_leader');
        if (team.member_ids.length <= 1) return t('teamsp.move_locked_only');
        return '';
    }

    // ── talking to the server ───────────────────────────────────────────────────────────────────────────────

    function flash(message, kind) {
        flashHtml = message ? `<div class="alert alert-${kind} py-2 mb-3" role="status">${esc(message)}</div>` : '';
        clearTimeout(flashTimer);
        if (message) {
            flashTimer = setTimeout(() => {
                flashHtml = '';
                const box = root && root.querySelector('[data-mt-flash]');
                if (box) box.innerHTML = '';
            }, 7000);
        }
    }

    async function call(action, params = {}) {
        if (busy) return null;
        busy = true;
        root.classList.add('mt-busy');
        const body = new URLSearchParams({csrf_token: csrfToken, mission_id: String(WR_MISSION_ID), action});
        for (const [key, value] of Object.entries(params)) {
            if (Array.isArray(value)) {
                for (const item of value) body.append(key + '[]', String(item));
            } else {
                body.append(key, String(value));
            }
        }
        let data = null;
        try {
            const response = await fetch('mission-teams.php', {method: 'POST', body, credentials: 'same-origin'});
            data = await response.json();
        } catch (error) {
            data = {ok: false, error: t('common.send_failed')};
        }
        busy = false;
        root.classList.remove('mt-busy');
        if (data.state) state = data.state;
        if (!data.ok) flash(data.error || t('common.send_failed'), 'danger');
        else if (data.message) flash(data.message, 'success');
        else flash('', '');
        return data;
    }

    async function refresh(quiet) {
        try {
            const response = await fetch('mission-teams.php?mission_id=' + encodeURIComponent(WR_MISSION_ID), {credentials: 'same-origin'});
            const data = await response.json();
            if (!data.ok) {
                if (!quiet) flash(data.error || t('common.send_failed'), 'danger');
                return false;
            }
            const changed = JSON.stringify(data.state) !== JSON.stringify(state);
            state = data.state;
            loaded = true;
            return changed;
        } catch (error) {
            if (!quiet) flash(t('common.send_failed'), 'danger');
            return false;
        }
    }

    /** The refresh never redraws under somebody's hands: not while they type, choose from a select, or read a split. */
    function userIsBusy() {
        if (busy || plan !== null || editingTeamId !== null || picked.size > 0) return true;
        const active = document.activeElement;
        return !!(active && root.contains(active) && /^(INPUT|SELECT|TEXTAREA)$/.test(active.tagName) && active.type !== 'checkbox');
    }

    // ── drawing ─────────────────────────────────────────────────────────────────────────────────────────────

    function transportIcon(transport, size) {
        const inner = MT_TRANSPORT_ICONS[transport] || MT_TRANSPORT_ICONS.foot;
        return `<svg xmlns="http://www.w3.org/2000/svg" width="${size}" height="${size}" viewBox="0 0 24 24" fill="none" stroke="currentColor"`
            + ` stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-0.2em">${inner}</svg>`;
    }

    function homeBadge(person) {
        if (!person.home_colors) {
            return person.external ? `<span class="team-name-badge" style="background:#6c757d;color:#fff">${esc(t('guest.org_unknown'))}</span>` : '';
        }
        return `<span class="team-name-badge" style="background:${esc(person.home_colors[0])};color:${esc(person.home_colors[1])}" title="${esc(person.home)}">${esc(person.home)}</span>`;
    }

    function nameHtml(person) {
        const captain = person.captain
            ? ` <span class="captain-badge" title="${esc(t('captain.label'))}"><i class="bi bi-star-fill"></i></span>`
            : '';
        return `<span class="mt-name">${esc(person.name)}</span> ${homeBadge(person)}${captain}`;
    }

    function gpsSwitch(person) {
        return `<label class="form-check form-switch mb-0 mt-gps" title="${esc(t('gps_participant.tick_title'))}">`
            + `<input class="form-check-input" type="checkbox" role="switch" data-mt-change="gps" data-user="${person.id}"${person.gps ? ' checked' : ''}>`
            + `<span class="small ${person.gps ? 'text-primary fw-semibold' : 'text-muted'}"><i class="bi bi-geo-alt-fill"></i> ${esc(t('gps_participant.label'))}</span></label>`;
    }

    function moveSelect(person, team) {
        const lock = moveLock(person, team);
        const options = state.teams
            .filter((other) => !team || other.id !== team.id)
            .map((other) => `<option value="${other.id}">${esc(other.label)}</option>`);
        if (team) options.push(`<option value="0">${esc(t('teamsp.move_none'))}</option>`);
        if (!options.length) return '';
        return `<select class="form-select form-select-sm mt-move" data-mt-change="move" data-user="${person.id}"`
            + `${lock ? ' disabled' : ''} title="${esc(lock || t('teamsp.move_title'))}" aria-label="${esc(t('teamsp.move_title'))}">`
            + `<option value="">${esc(team ? t('teamsp.move_placeholder') : t('teamsp.add_to_team'))}</option>${options.join('')}</select>`;
    }

    function memberRow(person, team) {
        const isLeader = team && team.leader_id === person.id;
        const lock = moveLock(person, team);
        const star = team
            ? `<button type="button" class="mt-star${isLeader ? ' is-leader' : ''}" data-mt-act="leader" data-team="${team.id}" data-user="${person.id}"`
              + ` title="${esc(isLeader ? t('teamsp.leader_is_title') : t('teamsp.leader_set_title'))}" aria-pressed="${isLeader ? 'true' : 'false'}">`
              + `<i class="bi ${isLeader ? 'bi-star-fill' : 'bi-star'}"></i></button>`
            : `<input class="form-check-input mt-pick" type="checkbox" data-mt-change="pick" data-user="${person.id}"${picked.has(person.id) ? ' checked' : ''}`
              + ` aria-label="${esc(person.name)}">`;
        return `<div class="mt-row${person.gps ? ' has-gps' : ''}" data-user="${person.id}"${lock ? '' : ' draggable="true"'}>`
            + `${star}<div class="mt-who">${nameHtml(person)}</div>${moveSelect(person, team)}${gpsSwitch(person)}</div>`;
    }

    function teamCard(team, people) {
        // The leader heads the list; everybody else stays in name order (the sort is stable).
        const members = team.member_ids.map((id) => people.get(id)).filter(Boolean)
            .sort((a, b) => Number(b.id === team.leader_id) - Number(a.id === team.leader_id));
        const gpsOn = members.filter((person) => person.gps).length;
        const leader = members.find((person) => person.id === team.leader_id) || null;
        // «Επεξεργασία»: the leader - any member of the team - on a row of its own, then the name and how the team
        // moves side by side; saved together. The card is half the page wide, so the grid goes by the card, not the screen.
        const editForm = () => `<form class="w-100" data-mt-form="edit" data-team="${team.id}">`
            + `<div class="row g-2">`
            + `<div class="col-12"><label class="form-label small fw-semibold mb-1" for="mtEditLeader${team.id}"><i class="bi bi-star-fill text-warning me-1"></i>${esc(t('teamsp.edit_leader_label'))}</label>`
            + `<select class="form-select form-select-sm" id="mtEditLeader${team.id}" name="leader_id" data-mt-autofocus="1">`
            + (leader ? '' : `<option value="">${esc(t('teamsp.edit_leader_choose'))}</option>`)
            + members.map((person) => `<option value="${person.id}"${person.id === team.leader_id ? ' selected' : ''}>${esc(person.name)}</option>`).join('')
            + `</select></div>`
            + `<div class="col-7"><label class="form-label small fw-semibold mb-1" for="mtEditName${team.id}">${esc(t('teamsp.edit_name_label'))}</label>`
            + `<input type="text" class="form-control form-control-sm" id="mtEditName${team.id}" name="name" maxlength="20" value="${esc(team.codename)}" placeholder="${esc(t('teamsp.rename_placeholder'))}"></div>`
            + `<div class="col-5"><label class="form-label small fw-semibold mb-1" for="mtEditTransport${team.id}">${esc(t('teamsp.edit_transport_label'))}</label>`
            + `<select class="form-select form-select-sm" id="mtEditTransport${team.id}" name="transport">`
            + TRANSPORTS.map((tr) => `<option value="${tr}"${tr === team.transport ? ' selected' : ''}>${esc(t('teams.transport.' + tr))}</option>`).join('')
            + `</select></div>`
            + `</div><div class="d-flex gap-2 mt-2">`
            + `<button type="submit" class="btn btn-sm btn-primary"><i class="bi bi-check2 me-1"></i>${esc(t('common.save'))}</button>`
            + `<button type="button" class="btn btn-sm btn-outline-secondary" data-mt-act="edit-cancel">${esc(t('common.cancel'))}</button>`
            + `</div></form>`;
        const head = editingTeamId === team.id
            ? editForm()
            : `<span class="badge fs-6" style="background:${esc(team.colors[0])};color:${esc(team.colors[1])}">${transportIcon(team.transport, 16)} ${esc(team.label)}</span>`
              + `<span class="mt-leader-line"><i class="bi bi-star-fill text-warning me-1"></i>${esc(leader ? t('teamsp.leader_line', {name: leader.name}) : t('teamsp.leader_none'))}</span>`
              + `<span class="small text-muted">${esc(t('teamsp.team_members_count', {n: members.length}))} · ${esc(t('teamsp.gps_count', {on: gpsOn, total: members.length}))}</span>`
              + `<div class="ms-auto d-flex gap-1 align-items-center">`
              + `<button type="button" class="btn btn-sm btn-outline-secondary" data-mt-act="edit" data-team="${team.id}" title="${esc(t('teamsp.edit_title'))}"><i class="bi bi-pencil me-1"></i>${esc(t('teamsp.edit_btn'))}</button>`
              + `<button type="button" class="btn btn-sm btn-outline-danger" data-mt-act="delete" data-team="${team.id}" title="${esc(t('teams.delete_btn_title'))}"><i class="bi bi-x-lg"></i></button>`
              + `</div>${equipmentHtml(team)}`;
        return `<div class="col-12 col-xl-6"><div class="card shadow-sm mt-team h-100" data-mt-drop="${team.id}" style="border-top:4px solid ${esc(team.colors[0])}">`
            + `<div class="card-header d-flex align-items-center flex-wrap gap-2">${head}</div>`
            + `<div class="card-body p-2">${members.map((person) => memberRow(person, team)).join('')}</div>`
            + `<div class="card-footer d-flex align-items-center flex-wrap gap-2 small">`
            + `<span class="text-muted"><i class="bi bi-geo-alt-fill"></i> ${esc(t('teamsp.gps_team'))}</span>`
            + `<div class="btn-group btn-group-sm">`
            + `<button type="button" class="btn btn-outline-primary" data-mt-act="gps-team" data-mode="all" data-team="${team.id}">${esc(t('teamsp.gps_team_all'))}</button>`
            + `<button type="button" class="btn btn-outline-primary" data-mt-act="gps-team" data-mode="leader" data-team="${team.id}">${esc(t('teamsp.gps_team_leader'))}</button>`
            + `<button type="button" class="btn btn-outline-secondary" data-mt-act="gps-team" data-mode="none" data-team="${team.id}">${esc(t('teamsp.gps_team_none'))}</button>`
            + `</div></div></div></div>`;
    }

    /** The equipment line of a team's card: its current chips, plus a checklist that saves on every change. */
    function equipmentHtml(team) {
        if (!MT_EQUIPMENT_TYPES.length) return '';
        const activeIds = (team.equipment || []).map(Number);
        const chips = MT_EQUIPMENT_TYPES.filter((et) => activeIds.includes(et.id))
            .map((et) => `<span class="badge bg-info-subtle text-info-emphasis border me-1 mb-1">${esc(et.icon)} ${esc(et.name)}</span>`).join('');
        const items = MT_EQUIPMENT_TYPES.map((et) => {
            const id = `mtEq${team.id}_${et.id}`;
            return `<li class="form-check mb-1 px-2"><input class="form-check-input" type="checkbox" id="${id}" data-mt-change="equipment" data-team="${team.id}" data-type="${et.id}"${activeIds.includes(et.id) ? ' checked' : ''}>`
                + `<label class="form-check-label small" for="${id}">${esc(et.icon)} ${esc(et.name)}</label></li>`;
        }).join('');
        return `<div class="w-100 small mt-1 d-flex align-items-center gap-1 flex-wrap"><span>${chips}</span>`
            + `<div class="dropdown"><button type="button" class="btn btn-sm btn-link p-0" data-bs-toggle="dropdown" title="${esc(t('teams.equipment.edit_title'))}">`
            + `<i class="bi bi-plus-circle"></i> ${esc(t('teams.equipment.edit_btn'))}</button>`
            + `<ul class="dropdown-menu p-1" style="min-width:220px;">${items}</ul></div></div>`;
    }

    function statsHtml() {
        const total = state.people.length;
        const free = unassigned().length;
        const gpsOn = state.people.filter((person) => person.gps).length;
        const chip = (icon, text, strong) => `<span class="mt-chip${strong ? ' mt-chip-strong' : ''}"><i class="bi ${icon} me-1"></i>${esc(text)}</span>`;
        return `<div class="d-flex flex-wrap gap-2 mb-3">`
            + chip('bi-people-fill', t('teamsp.stat_people', {n: total}))
            + chip('bi-diagram-3', t('teamsp.stat_teams', {n: state.teams.length}))
            + chip('bi-person-check', t('teamsp.stat_assigned', {n: total - free}))
            + chip('bi-person-dash', t('teamsp.stat_unassigned', {n: free}), free > 0)
            + chip('bi-geo-alt-fill', t('teamsp.stat_gps', {on: gpsOn, total}), gpsOn === 0 && total > 0)
            + `</div>`;
    }

    function planHtml(people) {
        if (!plan) return '';
        if (!plan.teams.length) {
            return `<div class="alert alert-warning py-2 mt-3 mb-0">${esc(t('teamsp.auto_nobody'))}</div>`;
        }
        const sizes = plan.teams.map((team) => team.member_ids.length).join(' · ');
        const cards = plan.teams.map((team, index) => {
            const names = team.member_ids.map((id) => people.get(id)).filter(Boolean).map((person) =>
                `<div class="mt-plan-row">${person.id === team.leader_id ? '<i class="bi bi-star-fill text-warning me-1"></i>' : '<i class="bi bi-dot me-1"></i>'}${nameHtml(person)}</div>`
            ).join('');
            return `<div class="col-12 col-md-6 col-xl-4"><div class="border rounded p-2 h-100 bg-body">`
                + `<div class="fw-semibold mb-1">${esc(t('teamsp.auto_plan_team', {n: index + 1}))} <span class="text-muted fw-normal">(${team.member_ids.length})</span></div>${names}</div></div>`;
        }).join('');
        const createLabel = plan.teams.length === 1 ? t('teamsp.auto_create_btn_one') : t('teamsp.auto_create_btn', {n: plan.teams.length});
        return `<div class="mt-plan mt-3">`
            + `<div class="fw-semibold mb-2"><i class="bi bi-eye me-1"></i>${esc(t('teamsp.auto_plan_summary', {teams: plan.teams.length, sizes}))}</div>`
            + `<div class="row g-2">${cards}</div>`
            + `<div class="small text-muted mt-2"><i class="bi bi-geo-alt me-1"></i>${esc(t('teamsp.auto_gps_note'))}</div>`
            + `<div class="d-flex gap-2 mt-2">`
            + `<button type="button" class="btn btn-success" data-mt-act="auto-create"><i class="bi bi-check2-circle me-1"></i>${esc(createLabel)}</button>`
            + `<button type="button" class="btn btn-outline-secondary" data-mt-act="auto-cancel">${esc(t('common.cancel'))}</button>`
            + `</div></div>`;
    }

    function autoCardHtml(people) {
        const free = unassigned().length;
        const hasTeams = state.teams.length > 0;
        const scope = autoRedo && hasTeams
            ? t('teamsp.auto_scope_all', {n: state.people.length})
            : t('teamsp.auto_scope_unassigned', {n: free});
        const redo = hasTeams
            ? `<label class="form-check mb-0"><input class="form-check-input" type="checkbox" data-mt-change="auto-redo"${autoRedo ? ' checked' : ''}>`
              + `<span class="form-check-label">${esc(t('teamsp.auto_redo', {n: state.teams.length}))}</span></label>`
            : '';
        return `<div class="card shadow-sm border-primary mb-3"><div class="card-header bg-primary bg-opacity-10"><h5 class="mb-0"><i class="bi bi-magic me-1"></i>${esc(t('teamsp.auto_title'))}</h5></div>`
            + `<div class="card-body">`
            + `<div class="small text-muted mb-2">${esc(t('teamsp.auto_hint'))}</div>`
            + `<form class="d-flex align-items-end flex-wrap gap-3" data-mt-form="auto">`
            + `<div><label class="form-label small fw-semibold mb-1" for="mtAutoSize">${esc(t('teamsp.auto_size_label'))}</label>`
            + `<input type="number" class="form-control" id="mtAutoSize" name="size" min="1" max="50" step="1" inputmode="numeric" style="width:7rem" value="${esc(autoSize)}" data-mt-change="auto-size"></div>`
            + `<button type="submit" class="btn btn-primary"><i class="bi bi-eye me-1"></i>${esc(t('teamsp.auto_preview_btn'))}</button>`
            + `<div class="small"><div>${esc(scope)}</div>${redo}</div>`
            + `</form>${planHtml(people)}</div></div>`;
    }

    function gpsCardHtml() {
        return `<div class="card shadow-sm mb-3"><div class="card-header"><h5 class="mb-0"><i class="bi bi-geo-alt-fill me-1"></i>${esc(t('teamsp.gps_title'))}</h5></div>`
            + `<div class="card-body">`
            + `<div class="small text-muted mb-2">${esc(t('teamsp.gps_note'))}</div>`
            + `<div class="d-flex flex-wrap gap-2">`
            + `<button type="button" class="btn btn-sm btn-outline-primary" data-mt-act="gps-all" data-mode="leaders"${state.teams.length ? '' : ' disabled'}><i class="bi bi-star-fill me-1"></i>${esc(t('teamsp.gps_all_leaders'))}</button>`
            + `<button type="button" class="btn btn-sm btn-outline-primary" data-mt-act="gps-all" data-mode="all"><i class="bi bi-geo-alt-fill me-1"></i>${esc(t('teamsp.gps_all_on'))}</button>`
            + `<button type="button" class="btn btn-sm btn-outline-secondary" data-mt-act="gps-all" data-mode="none"><i class="bi bi-geo-alt me-1"></i>${esc(t('teamsp.gps_all_off'))}</button>`
            + `</div></div></div>`;
    }

    function unassignedCardHtml() {
        const free = unassigned();
        for (const id of [...picked]) {
            if (!free.some((person) => person.id === id)) picked.delete(id);
        }
        const rows = free.length
            ? free.map((person) => memberRow(person, null)).join('')
            : `<div class="text-muted small p-2">${esc(state.people.length ? t('teamsp.unassigned_empty') : t('teamsp.no_people'))}</div>`;
        const manual = free.length
            ? `<div class="card-footer"><div class="small text-muted mb-2">${esc(t('teamsp.new_hint'))}</div>`
              + `<form class="d-flex flex-wrap gap-2" data-mt-form="new-team">`
              + `<input type="text" class="form-control form-control-sm" style="max-width:16rem" name="name" maxlength="20" value="${esc(newTeamName)}" placeholder="${esc(t('teamsp.new_name_placeholder'))}" data-mt-change="new-name">`
              + `<button type="submit" class="btn btn-sm btn-primary"${picked.size ? '' : ' disabled'}><i class="bi bi-plus-lg me-1"></i>${esc(t('teamsp.new_create_btn', {n: picked.size}))}</button>`
              + `</form></div>`
            : '';
        return `<div class="card shadow-sm mt-team mt-unassigned mb-3" data-mt-drop="0">`
            + `<div class="card-header d-flex align-items-center gap-2"><h5 class="mb-0"><i class="bi bi-person-dash me-1"></i>${esc(t('teamsp.unassigned_title'))}</h5>`
            + `<span class="badge bg-secondary">${free.length}</span></div>`
            + `<div class="card-body p-2">${rows}</div>${manual}</div>`;
    }

    function render() {
        if (!loaded) return;
        const people = peopleById();
        if (editingTeamId !== null && !state.teams.some((team) => team.id === editingTeamId)) editingTeamId = null;
        const teams = state.teams.length
            ? `<div class="row g-3 mb-3">${state.teams.map((team) => teamCard(team, people)).join('')}</div>`
            : `<div class="alert alert-light border mb-3">${esc(t('teams.empty'))}</div>`;
        const dragHint = state.teams.length > 0
            ? `<div class="small text-muted mb-2"><i class="bi bi-arrows-move me-1"></i>${esc(t('teamsp.drag_hint'))}</div>`
            : '';
        root.innerHTML = `<div data-mt-flash="1">${flashHtml}</div>`
            + statsHtml()
            + `<div class="row g-3"><div class="col-12 col-lg-7">${autoCardHtml(people)}</div><div class="col-12 col-lg-5">${gpsCardHtml()}</div></div>`
            + `<h5 class="mt-2 mb-2"><i class="bi bi-diagram-3 me-1"></i>${esc(t('teamsp.teams_title'))}</h5>`
            + dragHint + teams + unassignedCardHtml();
        const focus = root.querySelector('[data-mt-autofocus]');
        if (focus) {
            focus.focus();
            if (typeof focus.select === 'function') focus.select();
        }
    }

    // ── what a press does ───────────────────────────────────────────────────────────────────────────────────

    function teamById(teamId) {
        return state.teams.find((team) => team.id === teamId) || null;
    }

    async function setGps(onIds, offIds) {
        await call('set_gps', {on_ids: onIds, off_ids: offIds});
        render();
    }

    /**
     * The whole new set of equipment a team carries, posted at once. A different endpoint from call() above
     * (mission-team-equipment.php, not mission-teams.php) - it is shared with the War Room's Teams card, where a
     * team's own member may use it too, not only command staff. Merges the server's answer straight into state
     * rather than re-fetching the whole page, same idiom as setGps() above re-rendering off call()'s own answer.
     */
    async function setEquipment(teamId, ids) {
        if (busy) return;
        busy = true;
        root.classList.add('mt-busy');
        const body = new URLSearchParams({csrf_token: csrfToken, mission_id: String(WR_MISSION_ID), team_id: String(teamId)});
        for (const id of ids) body.append('equipment_type_ids[]', String(id));
        let data;
        try {
            const response = await fetch('mission-team-equipment.php', {method: 'POST', body, credentials: 'same-origin'});
            data = await response.json();
        } catch (error) {
            data = {ok: false, error: t('common.send_failed')};
        }
        busy = false;
        root.classList.remove('mt-busy');
        if (!data.ok) {
            flash(data.error || t('common.send_failed'), 'danger');
        } else {
            const team = teamById(teamId);
            if (team) team.equipment = data.equipment || [];
        }
        render();
    }

    async function onClick(event) {
        const button = event.target.closest('[data-mt-act]');
        if (!button || !root.contains(button) || busy) return;
        const act = button.dataset.mtAct;
        const team = button.dataset.team ? teamById(Number(button.dataset.team)) : null;

        if (act === 'leader') {
            if (!team || team.leader_id === Number(button.dataset.user)) return;
            await call('set_leader', {team_id: team.id, user_id: button.dataset.user});
        } else if (act === 'edit') {
            editingTeamId = team ? team.id : null;
        } else if (act === 'edit-cancel') {
            editingTeamId = null;
        } else if (act === 'delete') {
            if (!team || !confirm(t('teams.delete_confirm', {team: team.label}))) return;
            await call('delete_team', {team_id: team.id});
        } else if (act === 'gps-team') {
            if (!team) return;
            const mode = button.dataset.mode;
            const on = mode === 'all' ? team.member_ids : (mode === 'leader' && team.leader_id ? [team.leader_id] : []);
            await setGps(on, team.member_ids.filter((id) => !on.includes(id)));
            return;
        } else if (act === 'gps-all') {
            const mode = button.dataset.mode;
            const everybody = state.people.map((person) => person.id);
            const leaders = state.teams.map((item) => item.leader_id).filter(Boolean);
            const on = mode === 'all' ? everybody : (mode === 'leaders' ? leaders : []);
            const question = mode === 'all' ? t('teamsp.gps_all_on_confirm', {n: everybody.length})
                : (mode === 'leaders' ? t('teamsp.gps_all_leaders_confirm', {n: leaders.length}) : t('teamsp.gps_all_off_confirm'));
            if (!confirm(question)) return;
            await setGps(on, everybody.filter((id) => !on.includes(id)));
            return;
        } else if (act === 'auto-cancel') {
            plan = null;
        } else if (act === 'auto-create') {
            if (!plan) return;
            if (plan.redo && state.teams.length && !confirm(t('teamsp.auto_redo_confirm', {n: state.teams.length}))) return;
            const data = await call('auto_create', {size: plan.size, redo: plan.redo ? '1' : '0'});
            if (data && data.ok) {
                plan = null;
                autoRedo = false;
            }
        }
        render();
    }

    async function onChange(event) {
        const input = event.target.closest('[data-mt-change]');
        if (!input || !root.contains(input)) return;
        const kind = input.dataset.mtChange;
        const userId = Number(input.dataset.user);

        if (kind === 'gps') {
            await setGps(input.checked ? [userId] : [], input.checked ? [] : [userId]);
        } else if (kind === 'move') {
            if (input.value === '') return;
            await call('move_member', {user_id: userId, to_team_id: input.value});
            render();
        } else if (kind === 'pick') {
            if (input.checked) picked.add(userId); else picked.delete(userId);
            render();
        } else if (kind === 'auto-redo') {
            autoRedo = input.checked;
            plan = null;
            render();
        } else if (kind === 'equipment') {
            const team = teamById(Number(input.dataset.team));
            if (!team) return;
            const typeId = Number(input.dataset.type);
            const ids = new Set(team.equipment || []);
            if (input.checked) ids.add(typeId); else ids.delete(typeId);
            await setEquipment(team.id, Array.from(ids));
        }
    }

    /** What is typed is kept across a redraw; a new number makes the split shown stale, so it is taken away. */
    function onInput(event) {
        const input = event.target.closest('[data-mt-change]');
        if (!input || !root.contains(input)) return;
        if (input.dataset.mtChange === 'auto-size') {
            autoSize = input.value;
            if (plan) {
                plan = null;
                const box = root.querySelector('.mt-plan');
                if (box) box.remove();
            }
        } else if (input.dataset.mtChange === 'new-name') {
            newTeamName = input.value;
        }
    }

    async function onSubmit(event) {
        const form = event.target.closest('[data-mt-form]');
        if (!form || !root.contains(form)) return;
        event.preventDefault();
        if (busy) return;
        const kind = form.dataset.mtForm;

        if (kind === 'auto') {
            const size = parseInt(autoSize, 10);
            const data = await call('auto_preview', {size: Number.isFinite(size) ? size : 0, redo: autoRedo ? '1' : '0'});
            plan = data && data.ok ? {size, redo: autoRedo, teams: data.plan || []} : null;
        } else if (kind === 'edit') {
            // One save for the three: the server checks them together, so a refused name does not change the leader.
            const data = await call('update_team', {
                team_id: form.dataset.team,
                name: form.elements.name.value,
                leader_id: form.elements.leader_id.value || '0',
                transport: form.elements.transport.value,
            });
            if (data && data.ok) editingTeamId = null;
        } else if (kind === 'new-team') {
            const members = unassigned().filter((person) => picked.has(person.id));
            if (!members.length) {
                flash(t('teamsp.new_select_first'), 'warning');
            } else {
                const leader = members.find((person) => person.captain) || members[0];
                const data = await call('create_team', {member_ids: members.map((person) => person.id), leader_id: leader.id, name: newTeamName, transport: 'foot'});
                if (data && data.ok) {
                    picked.clear();
                    newTeamName = '';
                }
            }
        }
        render();
    }

    // ── dragging a name onto a team ─────────────────────────────────────────────────────────────────────────

    function onDragStart(event) {
        const row = event.target.closest('.mt-row[draggable="true"]');
        if (!row || !event.dataTransfer) return;
        event.dataTransfer.setData('text/plain', row.dataset.user);
        event.dataTransfer.effectAllowed = 'move';
        root.classList.add('mt-dragging');
    }

    function onDragOver(event) {
        const target = event.target.closest('[data-mt-drop]');
        if (!target || !root.classList.contains('mt-dragging')) return;
        event.preventDefault();
        for (const other of root.querySelectorAll('.mt-drop-over')) {
            if (other !== target) other.classList.remove('mt-drop-over');
        }
        target.classList.add('mt-drop-over');
    }

    function clearDrag() {
        root.classList.remove('mt-dragging');
        for (const other of root.querySelectorAll('.mt-drop-over')) other.classList.remove('mt-drop-over');
    }

    async function onDrop(event) {
        const target = event.target.closest('[data-mt-drop]');
        const userId = Number(event.dataTransfer ? event.dataTransfer.getData('text/plain') : 0);
        clearDrag();
        if (!target || !userId) return;
        event.preventDefault();
        const toTeamId = Number(target.dataset.mtDrop);
        const person = state.people.find((item) => item.id === userId);
        if (!person || (person.team_id || 0) === toTeamId) return;
        await call('move_member', {user_id: userId, to_team_id: toTeamId});
        render();
    }

    // ── start ───────────────────────────────────────────────────────────────────────────────────────────────

    async function init() {
        root = document.getElementById('missionTeamsBody');
        if (!root) return;
        root.addEventListener('click', onClick);
        root.addEventListener('change', onChange);
        root.addEventListener('input', onInput);
        root.addEventListener('submit', onSubmit);
        root.addEventListener('dragstart', onDragStart);
        root.addEventListener('dragover', onDragOver);
        root.addEventListener('drop', onDrop);
        root.addEventListener('dragend', clearDrag);

        await refresh(false);
        if (!loaded) {
            root.innerHTML = `<div data-mt-flash="1">${flashHtml}</div>`;
            return;
        }
        render();
        setInterval(async () => {
            if (document.hidden || userIsBusy()) return;
            if (await refresh(true) && !userIsBusy()) render();
        }, REFRESH_MS);
    }

    window.missionTeams = {init};
})();
