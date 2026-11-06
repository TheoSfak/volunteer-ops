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
 *     instead of sending it.
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

    let scenario = {phases: []};
    let ghostLayer = null;
    let initialised = false;
    let busy = false;

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
        scenario = (fresh && Array.isArray(fresh.phases)) ? fresh : {phases: []};
        renderCard();
        drawGhosts();
    }

    function load() {
        return fetch(ENDPOINT + '?mission_id=' + encodeURIComponent(WR_MISSION_ID))
            .then(r => r.json())
            .then(res => { if (res && res.ok) apply(res.scenario); return res; })
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

    // ── Words ──────────────────────────────────────────────────────────────────────────────────────────────────
    function phaseName(phase) {
        return t('scenario.phase_n', {n: phase.n});
    }
    function itemKind(item) {
        return item.type === 'point' ? t('scenario.kind_point') : t('scenario.kind_area');
    }
    function itemTarget(item) {
        if (item.audience === 'all') return t('common.all_teams');
        return item.team_label || t('scenario.team_gone');
    }
    function itemTitle(item) {
        return item.label ? itemKind(item) + ' «' + item.label + '»' : itemKind(item);
    }

    // ── The card ───────────────────────────────────────────────────────────────────────────────────────────────
    function itemHtml(phase, item) {
        const icon = item.type === 'point' ? 'bi-geo-alt-fill' : 'bi-bounding-box';
        const target = `<span class="badge ${item.team_missing ? 'bg-danger' : (item.audience === 'all' ? 'bg-warning text-dark' : 'bg-secondary')}">${esc(itemTarget(item))}</span>`;
        let status = '';
        if (item.sent) {
            status = `<div class="small text-success"><i class="bi bi-check-circle-fill me-1"></i>${esc(t('scenario.item_sent'))}</div>`;
        } else if (item.error) {
            status = `<div class="small text-danger"><i class="bi bi-exclamation-triangle-fill me-1"></i>${esc(item.error)}</div>`;
        } else if (item.team_missing) {
            status = `<div class="small text-danger"><i class="bi bi-exclamation-triangle-fill me-1"></i>${esc(t('scenario.team_gone_hint'))}</div>`;
        }
        const retry = (!item.sent && item.error && phase.played_at)
            ? `<button type="button" class="btn btn-outline-primary btn-sm py-0" data-sc="retry" data-item="${item.id}" title="${esc(t('scenario.retry_btn'))}"><i class="bi bi-arrow-repeat"></i></button>` : '';
        const del = !item.sent
            ? `<button type="button" class="btn btn-outline-danger btn-sm py-0" data-sc="delete-item" data-item="${item.id}" title="${esc(t('scenario.item_delete'))}"><i class="bi bi-x-lg"></i></button>` : '';
        const goto = item.sent
            ? `<span>${esc(itemTitle(item))}</span>`
            : `<a href="#" class="wr-sc-goto" data-sc="goto" data-item="${item.id}" title="${esc(t('scenario.show_on_map'))}">${esc(itemTitle(item))}</a>`;
        return `<li class="wr-sc-item d-flex align-items-start gap-1" data-item="${item.id}">
            <i class="bi ${icon} text-secondary mt-1"></i>
            <span class="flex-grow-1">${goto} <i class="bi bi-arrow-right-short"></i> ${target}${status}</span>
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
        return `<div class="wr-sc-phase ${played ? 'wr-sc-played' : ''}" data-phase="${phase.id}">
            <div class="d-flex align-items-center gap-1">
                <span class="flex-grow-1 text-break"><strong>${esc(phaseName(phase))}</strong>${phase.title ? ` <span class="text-muted small">· ${esc(phase.title)}</span>` : ''}</span>
                <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2" data-sc="rename" data-phase="${phase.id}" title="${esc(t('scenario.phase_rename'))}"><i class="bi bi-pencil"></i></button>
                <button type="button" class="btn btn-outline-danger btn-sm py-0 px-2" data-sc="delete-phase" data-phase="${phase.id}" title="${esc(t('scenario.phase_delete'))}"><i class="bi bi-trash"></i></button>
            </div>
            ${play ? `<div class="mt-1">${play}</div>` : ''}
            ${playedLine}${items}
        </div>`;
    }

    function renderCard() {
        const body = document.getElementById('scenarioBody');
        if (!body) return;
        const add = `<button type="button" class="btn btn-outline-primary btn-sm w-100" data-sc="add-phase"><i class="bi bi-plus-lg me-1"></i>${esc(t('scenario.add_phase_btn'))}</button>`;
        if (!scenario.phases.length) {
            body.innerHTML = `<p class="small text-muted mb-2">${esc(t('scenario.empty'))}</p>${add}`;
            return;
        }
        body.innerHTML = scenario.phases.map(phaseHtml).join('') + add;
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
            const label = i.audience === 'all' ? t('scenario.target_all') : itemTarget(i);
            if (!names.includes(label)) names.push(label);
        });
        let text = t('scenario.play_confirm', {phase: phaseName(phase), orders: pending.length, targets: names.join(', ')});
        const missing = pending.filter(i => i.team_missing).length;
        if (missing) text += '\n\n' + t('scenario.play_confirm_missing', {n: missing});
        return text;
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
            if (!confirm(playConfirmText(phase))) return;
            run('play_phase', {phase_id: el.dataset.phase}, res => {
                if (res.dispatches && typeof renderDispatches === 'function') renderDispatches(dispatches = res.dispatches);
                if (res.failed > 0) {
                    const lines = (res.results || []).filter(r => !r.ok).map(r => '• ' + r.error);
                    alert(t('scenario.play_partial', {sent: res.sent, failed: res.failed}) + '\n\n' + lines.join('\n'));
                }
            });
        } else if (act === 'retry') {
            run('retry_item', {item_id: el.dataset.item}, res => {
                if (res.dispatches && typeof renderDispatches === 'function') renderDispatches(dispatches = res.dispatches);
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
                if (item.type === 'point') {
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

    function phaseNumber(phaseId) {
        const p = findPhase(phaseId);
        return p ? p.n : null;
    }

    function init() {
        if (initialised) return;
        initialised = true;
        injectCss();
        const body = document.getElementById('scenarioBody');
        if (body) body.addEventListener('click', onCardClick);
        renderCard();
        load();
        document.addEventListener('visibilitychange', () => { if (!document.hidden) load(); });
    }

    window.wrScenario = {init: init, reload: load, menuHtml: menuHtml, stage: stage, phaseNumber: phaseNumber, drawGhostsInto: drawGhostsInto, phases: () => scenario.phases};
})();
