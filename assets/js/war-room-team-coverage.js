/**
 * VolunteerOps - «Κάλυψη ομάδων» and «Εστίαση σε ομάδα» of the live map (v3.440.0).
 *
 * Two things for command, both in the layers menu (every skin has it):
 *
 *  - «Πού πέρασαν οι ομάδες (GPS)»: the ground each team has really had in sight, one colour per team, from
 *    mission-coverage-heat.php?by=team. The same idea of «seen» as Verified Coverage (🛰️) and the heat layer of the
 *    «Πίνακας» look - the rules of the ground a cell lies on - but kept apart per team. It is what the faded outline of
 *    a finished sector (assets/js/war-room-done-orders.js) is for: the outline says what was ASSIGNED and finished, this
 *    says where the GPS says the team really was, so a sector marked done over ground nobody walked shows. Off until
 *    asked for and remembered in the browser; while it is on the page asks every half minute and the server answers
 *    «unchanged» in one line when no new fix has come.
 *
 *  - «Εστίαση σε ομάδα»: one team's orders only. Every order, sector, route and destination arc of the OTHER teams
 *    leaves the map (finished or not), and so does their coverage; whatever belongs to no single team (an order sent
 *    to every team, a sector nobody has) stays, since it is the ground the team works in. The focus is never silent:
 *    the amber chip of hidden layers says «Μόνο η ομάδα …», and its «Εμφάνιση όλων» ends it. It is the same
 *    «hidden» the finished orders use (wrDonePhase), so everything that already leaves an open popup alone, redraws
 *    only when something changed and keeps its counter honest does so for the focus too.
 *
 * Only command has either (the GPS trails it is made of are command's), so only command ever carries a focus back
 * from the session: a volunteer's tab must not start with a state it cannot undo.
 *
 * Loaded before the page's own script, so it only DECLARES things here and reaches for the page's globals (map, teams,
 * t(), the render functions) when it is called.
 */

const WR_COV_KEY = 'wr_team_coverage';        // localStorage: 'on' / 'off', for this browser
const WR_FOCUS_KEY = 'wr_team_focus_';        // + the mission id, in sessionStorage
const WR_COV_EVERY_MS = 30000;
const WR_COV_ALPHA = 0.4;

let wrCovOn = false, wrCovLayer = null, wrCovData = null, wrCovSig = '', wrCovTimer = 0, wrCovBusy = false, wrCovState = 'off';
let wrCovMissionId = 0;
let wrFocusTeam = null;   // the id of the one team the map is showing, as a string; null = every team

// Is this order's team out of the picture? Nothing that belongs to no one team ever is.
function wrFocusExcludes(teamId) {
    return wrFocusTeam !== null && teamId !== null && teamId !== undefined && String(teamId) !== String(wrFocusTeam);
}
function wrFocusLabel() {
    const team = (typeof teams !== 'undefined' ? teams : []).find(x => String(x.id) === String(wrFocusTeam));
    return team ? team.label : '';
}

function wrCovRgba(hex, alpha) {
    const m = /^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec(String(hex || ''));
    return m ? `rgba(${parseInt(m[1], 16)},${parseInt(m[2], 16)},${parseInt(m[3], 16)},${alpha})` : String(hex || '#212529');
}

// ── The layer ───────────────────────────────────────────────────────────────
// Thousands of cells are too many SVG nodes: one canvas, in its own pane over the tiles and under the areas (340),
// the sectors (350) and everything drawn on top of the ground, so a faded outline shows the cells through it.
function wrCovEnsureLayer() {
    if (wrCovLayer || typeof map === 'undefined' || !map || typeof L === 'undefined') return wrCovLayer;
    if (!map.getPane('teamCoveragePane')) {
        map.createPane('teamCoveragePane');
        map.getPane('teamCoveragePane').style.zIndex = 335;
        map.getPane('teamCoveragePane').style.pointerEvents = 'none';
    }
    const Cover = L.Layer.extend({
        onAdd(m) {
            this._canvas = L.DomUtil.create('canvas', 'wr-cov-canvas');
            m.getPane('teamCoveragePane').appendChild(this._canvas);
            m.on('moveend resize', this._redraw, this);
            m.on('zoomstart', this._hide, this);
            m.on('zoomend', this._redraw, this);
            this._redraw();
        },
        onRemove(m) {
            m.off('moveend resize', this._redraw, this);
            m.off('zoomstart', this._hide, this);
            m.off('zoomend', this._redraw, this);
            L.DomUtil.remove(this._canvas);
            this._canvas = null;
        },
        refresh() { if (this._map) this._redraw(); },
        _hide() { if (this._canvas) this._canvas.style.visibility = 'hidden'; },
        _redraw() {
            const m = this._map;
            const canvas = this._canvas;
            if (!m || !canvas) return;
            const size = m.getSize();
            canvas.width = size.x;
            canvas.height = size.y;
            L.DomUtil.setPosition(canvas, m.containerPointToLayerPoint([0, 0]));
            canvas.style.visibility = '';
            const ctx = canvas.getContext('2d');
            ctx.clearRect(0, 0, size.x, size.y);
            const data = wrCovData;
            if (!data || !data.teams) return;
            const zoom = m.getZoom();
            data.teams.forEach(team => {
                if (wrFocusTeam !== null && String(team.id) !== String(wrFocusTeam)) return;
                if (!team.cells || !team.cells.length) return;
                // A cell is a square of cell_m metres wherever it is; Web Mercator's metres per pixel at its latitude.
                const mpp = 156543.03392 * Math.cos(team.cells[0][0] * Math.PI / 180) / Math.pow(2, zoom);
                const px = Math.max(2, team.cell_m / mpp);
                const half = px / 2 + 0.4;   // a hair over: neighbours meet without a seam
                ctx.fillStyle = wrCovRgba(team.color, WR_COV_ALPHA);
                team.cells.forEach(cell => {
                    const p = m.latLngToContainerPoint([cell[0], cell[1]]);
                    if (p.x < -px || p.y < -px || p.x > size.x + px || p.y > size.y + px) return;
                    ctx.fillRect(p.x - half, p.y - half, half * 2, half * 2);
                });
            });
        },
    });
    wrCovLayer = new Cover();
    return wrCovLayer;
}

function wrCovSetState(state) {
    wrCovState = state;
    wrCovUi();
}

function wrCovLoad() {
    if (!wrCovOn || wrCovBusy) return;
    const layer = wrCovEnsureLayer();
    if (!layer) return;
    wrCovBusy = true;
    fetch('mission-coverage-heat.php?mission_id=' + encodeURIComponent(wrCovMissionId) + '&by=team&sig=' + encodeURIComponent(wrCovSig), {credentials: 'same-origin'})
        .then(r => r.ok ? r.json() : null)
        .then(res => {
            if (!wrCovOn) return;
            if (!res || !res.ok) { if (!wrCovData) wrCovSetState('failed'); return; }
            if (res.unchanged) return;
            wrCovSig = res.sig;
            wrCovData = res;
            layer.refresh();
            wrCovSetState((res.teams || []).some(tm => tm.cells && tm.cells.length) ? 'ok' : 'none');
        })
        .catch(() => { if (!wrCovData) wrCovSetState('failed'); })
        .finally(() => { wrCovBusy = false; });
}

function wrCovStart() {
    const layer = wrCovEnsureLayer();
    if (!layer) return;
    if (!map.hasLayer(layer)) layer.addTo(map);
    wrCovSetState(wrCovData ? ((wrCovData.teams || []).some(tm => tm.cells && tm.cells.length) ? 'ok' : 'none') : 'loading');
    wrCovLoad();
    clearInterval(wrCovTimer);
    wrCovTimer = setInterval(() => { if (!document.hidden) wrCovLoad(); }, WR_COV_EVERY_MS);
}

function wrCovStop() {
    clearInterval(wrCovTimer);
    wrCovTimer = 0;
    if (wrCovLayer && typeof map !== 'undefined' && map && map.hasLayer(wrCovLayer)) map.removeLayer(wrCovLayer);
    wrCovSetState('off');
}

function wrCovSet(on) {
    wrCovOn = !!on;
    try { localStorage.setItem(WR_COV_KEY, wrCovOn ? 'on' : 'off'); } catch (e) {}
    if (wrCovOn) wrCovStart(); else wrCovStop();
}

// ── The focus ───────────────────────────────────────────────────────────────
function wrFocusSet(teamId) {
    const next = (teamId === null || teamId === undefined || teamId === '') ? null : String(teamId);
    if (next === wrFocusTeam) return;
    wrFocusTeam = next;
    try {
        if (next === null) sessionStorage.removeItem(WR_FOCUS_KEY + wrCovMissionId);
        else sessionStorage.setItem(WR_FOCUS_KEY + wrCovMissionId, next);
    } catch (e) {}
    if (wrCovLayer) wrCovLayer.refresh();
    if (typeof renderTeamArcs === 'function' && typeof teamArcs !== 'undefined') renderTeamArcs(teamArcs);
    wrCovUi();
    // The orders, sectors and routes, the counter, and the amber chip that says the focus is on.
    wrDoneRerender();
}

// ── The menu ────────────────────────────────────────────────────────────────
function wrCovUi() {
    document.querySelectorAll('input[data-team-coverage]').forEach(i => { i.checked = wrCovOn; });
    document.querySelectorAll('[data-focus-team]').forEach(b => {
        const id = b.dataset.focusTeam === '' ? null : b.dataset.focusTeam;
        const on = id === wrFocusTeam;
        b.classList.toggle('active', on);
        b.setAttribute('aria-pressed', on ? 'true' : 'false');
        if (id !== null) {
            b.style.background = on ? b.dataset.bg : 'transparent';
            b.style.color = on ? b.dataset.fg : '';
        }
    });
    // The line of the fold says what is on, so a closed section is never a hidden state.
    const sum = [wrCovOn ? t('map.cov_sum_gps') : '', wrFocusTeam !== null ? wrFocusLabel() : ''].filter(Boolean).join(' · ');
    document.querySelectorAll('.wr-cov-sum').forEach(e => { e.textContent = sum ? '· ' + sum : ''; });
    const msg = document.querySelector('.wr-cov-msg');
    if (msg) {
        msg.textContent = !wrCovOn ? '' : (wrCovState === 'failed' ? t('map.cov_failed') : (wrCovState === 'none' ? t('map.cov_none') : ''));
    }
}

function wrCovAddMenu() {
    const btn = document.getElementById('mapSatelliteToggle');
    const menu = btn?.parentNode.querySelector('.dropdown-menu');
    if (!menu) return;
    const li = document.createElement('li');
    // Folded, like the finished-orders choice above it: the menu is already as tall as a laptop's window.
    // No divider of its own: the finished-orders fold above it already has one, and the two read as one block.
    li.innerHTML = '<details class="wr-menu-sec"><summary class="dropdown-header wr-menu-sum"><span class="wr-sum-t"></span><span class="wr-sum-s wr-cov-sum"></span></summary>'
        + '<label class="dropdown-item wr-overlay-item"><input type="checkbox" class="form-check-input" data-team-coverage><i class="bi bi-person-walking"></i><span></span></label>'
        + '<div class="px-3 pb-1"><div class="small text-muted mb-1 wr-cov-focus-label"></div><div class="d-flex flex-wrap gap-1 wr-focus-chips"></div>'
        + '<div class="small text-muted mt-1 wr-cov-hint"></div><div class="small text-danger mt-1 wr-cov-msg"></div></div></details>';
    li.querySelector('.wr-sum-t').textContent = t('map.cov_header');
    li.querySelector('label span').textContent = t('map.cov_toggle');
    li.querySelector('.wr-cov-focus-label').textContent = t('map.cov_focus_label');
    li.querySelector('.wr-cov-hint').textContent = t('map.cov_hint');
    li.querySelector('input').addEventListener('change', e => wrCovSet(e.target.checked));

    const chips = li.querySelector('.wr-focus-chips');
    const mk = (id, label, bg, fg) => {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'btn btn-sm wr-focus-chip' + (id === '' ? ' btn-outline-secondary' : '');
        b.dataset.focusTeam = id;
        if (id !== '') {
            b.dataset.bg = bg;
            b.dataset.fg = fg;
            b.style.border = '2px solid ' + bg;
        }
        b.textContent = label;
        b.addEventListener('click', () => wrFocusSet(id === '' ? null : id));
        chips.appendChild(b);
    };
    mk('', t('map.cov_focus_all'), '', '');
    (typeof teams !== 'undefined' ? teams : []).forEach(tm => mk(String(tm.id), tm.label, tm.color_bg || '#212529', tm.color_fg || '#fff'));

    // Right under the finished-orders choice it goes with, else at the end.
    const after = menu.querySelector('.wr-done-modes')?.closest('li');
    if (after) after.after(li); else menu.appendChild(li);
}

// Called once by the page, after wrDoneInit() and before its first render of the layers. Only command has any of this.
function wrCovInit(missionId, canChoose) {
    wrCovMissionId = missionId;
    if (!canChoose || typeof map === 'undefined' || !map) return;
    try {
        const stored = sessionStorage.getItem(WR_FOCUS_KEY + missionId);
        if (stored !== null && (typeof teams !== 'undefined' ? teams : []).some(x => String(x.id) === stored)) wrFocusTeam = stored;
        wrCovOn = localStorage.getItem(WR_COV_KEY) === 'on';
    } catch (e) {}
    wrCovAddMenu();
    if (wrCovOn) wrCovStart();
    wrCovUi();
    // The focus came back from the session after wrDoneInit() had taken stock of the layers.
    wrDoneAppliedSig = wrDoneAllSig();
}
