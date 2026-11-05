/**
 * VolunteerOps - destination arcs of the live map (v3.437.0).
 *
 * Every team that has been sent somewhere and has not got there yet is drawn
 * as an arch from where it is now to where it was sent, in its own colour,
 * fading in from the team's side. At the top of the arch a dial says how long
 * it will take (ETA) and a pill under it the metres still to go: the ROUTED
 * path, on foot or by vehicle as the order was given (v3.438.0), with a thin
 * dashed straight line under the arch carrying the straight-line distance.
 * Where no router found a path the pill says the straight line and is dashed.
 * What the arc says is worked out by includes/functions-team-arcs.php; this
 * only draws it.
 *
 * Loaded before the page's own script, so it only DECLARES things here and
 * reaches for the page's globals (map, t(), transportGlyphSvg(), teamArcs)
 * when it is called.
 *
 * THE SHAPE. A quadratic curve whose control point sits at twice the arch's
 * height above the middle of the chord, computed in Web Mercator metres: the
 * arch then keeps its proportions at every zoom without being redrawn, and
 * "above" is north, so the dial at the top always reads the right way up.
 * The one thing that does change with the zoom is how much room the dial has:
 * a chord shorter than TEAM_ARC_COMPACT_PX leaves no room for a 78 px dial on
 * its own arc, so the label becomes a one-line pill.
 */

let teamArcLayer = null, teamArcLabelLayer = null, teamArcSuspended = false;

const TEAM_ARC_SAMPLES = 40;
const TEAM_ARC_SEGMENTS = 8;       // the fade-in is this many pieces of rising opacity
const TEAM_ARC_COMPACT_PX = 170;   // a shorter chord (on screen) gets the one-line label: a 78 px dial would sit on the team's own pin and its name
const TEAM_ARC_RING_LENGTH = 194.78; // the dial's ring: 2 * PI * 31

function teamArcsInit() {
    if (teamArcLayer || typeof map === 'undefined' || !map) return;
    // Lines above the sectors and dispatch shapes (overlay pane, 400), under the
    // team pins (marker pane, 600); the labels above the pins and the annotations
    // (610) but below the restricted areas (700), which must stay on top of all.
    map.createPane('teamArcPane').style.zIndex = 420;
    map.createPane('teamArcLabelPane').style.zIndex = 620;
    teamArcLayer = L.layerGroup().addTo(map);
    teamArcLabelLayer = L.layerGroup().addTo(map);
    // The label depends on how much room the zoom gives it.
    map.on('zoomend', teamArcsRedraw);
    // The «στίγμα πριν N λεπτά» chip counts up on its own between polls.
    setInterval(() => {
        if (typeof teamArcs !== 'undefined' && (teamArcs || []).some(a => a.from && a.from.stale)) teamArcsRedraw();
    }, 30000);
}

function teamArcsRedraw() {
    if (typeof teamArcs !== 'undefined') renderTeamArcs(teamArcs);
}

// The trail replay shows where people WERE; an arc to where they are going now
// does not belong on it.
function teamArcsSuspend(suspended) {
    teamArcSuspended = !!suspended;
    teamArcsRedraw();
}

function teamArcSafeColor(color) {
    return /^#[0-9a-f]{6}$/i.test(color || '') ? color : '#8a94a6';
}

/** The arch between two points as a list of lat/lngs, plus its top. Null for two identical points. */
function teamArcGeometry(from, to) {
    const crs = L.CRS.EPSG3857;
    const a = crs.project(L.latLng(from.lat, from.lng));
    const b = crs.project(L.latLng(to.lat, to.lng));
    const dx = b.x - a.x, dy = b.y - a.y;
    const len = Math.hypot(dx, dy);
    if (!(len > 0)) return null;
    // The perpendicular that points north; for a chord running due north or
    // south there is no "up", so the arch bulges east.
    let nx = -dy / len, ny = dx / len;
    if (ny < -1e-9 || (Math.abs(ny) <= 1e-9 && nx < 0)) { nx = -nx; ny = -ny; }
    const h = 0.3 * len;
    const mx = (a.x + b.x) / 2, my = (a.y + b.y) / 2;
    const cx = mx + nx * 2 * h, cy = my + ny * 2 * h;
    const points = [];
    for (let i = 0; i <= TEAM_ARC_SAMPLES; i++) {
        const s = i / TEAM_ARC_SAMPLES, u = 1 - s;
        points.push(crs.unproject(L.point(
            u * u * a.x + 2 * u * s * cx + s * s * b.x,
            u * u * a.y + 2 * u * s * cy + s * s * b.y
        )));
    }
    return {points, apex: crs.unproject(L.point(mx + nx * h, my + ny * h))};
}

/** 1.250 μ. / 2,4 χλμ - a figure that jitters with the GPS is rounded so it does not look more exact than it is. */
function teamArcFormatDistance(metres) {
    if (metres >= 2000) {
        return t('arc.km', {n: (metres / 1000).toFixed(1).replace('.', t('arc.decimal_sep'))});
    }
    const step = metres < 200 ? 5 : 10;
    const rounded = Math.max(step, Math.round(metres / step) * step);
    return t('arc.metres', {n: String(rounded).replace(/\B(?=(\d{3})+(?!\d))/g, t('arc.thousands_sep'))});
}

function teamArcEtaParts(minutes) {
    if (minutes >= 100) {
        return {n: '~' + (minutes / 60).toFixed(1).replace('.', t('arc.decimal_sep')), unit: t('arc.hours')};
    }
    return {n: '~' + Math.max(1, Math.round(minutes)), unit: t('arc.minutes')};
}

function teamArcAgeText(ts) {
    const minutes = Math.max(1, Math.round((Date.now() / 1000 - ts) / 60));
    return minutes < 90
        ? t('arc.stale_min', {n: minutes})
        : t('arc.stale_hours', {n: Math.round(minutes / 60)});
}

/**
 * The metres the pill says: the ROUTED path when a router found one, the
 * straight line when none did (a ridge with no mapped path, no key, a service
 * that is down). The straight line is then the only distance there is, and the
 * pill is dashed and the chip says so, rather than passing it off as a route.
 */
function teamArcRouted(arc) {
    return arc.route_m !== null && arc.route_m !== undefined;
}

function teamArcLabelHtml(arc, color, compact) {
    const eta = arc.eta || null;
    const stale = !!(arc.from && arc.from.stale);
    const routed = teamArcRouted(arc);
    const estimate = !routed || (!!eta && eta.source === 'straight_line');
    const dist = teamArcFormatDistance(routed ? arc.route_m : arc.distance_m);
    const parts = eta ? teamArcEtaParts(eta.minutes) : null;
    const icon = transportGlyphSvg(arc.transport, 15, '#fff');
    const etaWords = parts ? parts.n.replace('~', '') + ' ' + parts.unit : '-';
    const aria = escapeHtml(routed
        ? t('arc.aria_straight', {team: arc.label || '', eta: etaWords, dist, straight: teamArcFormatDistance(arc.distance_m)})
        : t('arc.aria', {team: arc.label || '', eta: etaWords, dist}));
    const notes = [];
    if (stale) notes.push(teamArcAgeText(arc.from.ts));
    if (arc.detour) notes.push(t('arc.detour'));
    if (estimate) notes.push(t('arc.straight'));
    const chips = notes.map(n => `<span class="wr-arc-chip">${escapeHtml(n)}</span>`).join('');

    if (compact) {
        return `<div class="wr-arc-pill${stale ? ' is-stale' : ''}${estimate ? ' is-est' : ''}" style="--arc:${color}" role="img" aria-label="${aria}">`
            + icon
            + (parts ? `<b>${escapeHtml(parts.n)}</b><span>${escapeHtml(parts.unit)}</span><i></i>` : '')
            + `<b>${escapeHtml(dist)}</b></div>`;
    }
    const covered = arc.covered_pct === null || arc.covered_pct === undefined ? 0 : Math.max(0, Math.min(100, arc.covered_pct));
    const ring = (covered / 100 * TEAM_ARC_RING_LENGTH).toFixed(1);
    const dial = parts
        ? `<div class="wr-arc-dial${stale ? ' is-stale' : ''}">`
            + `<svg width="78" height="78" viewBox="0 0 78 78" aria-hidden="true"><circle cx="39" cy="39" r="37" class="bg"/>`
            + `<circle cx="39" cy="39" r="31" class="track"/>`
            + `<circle cx="39" cy="39" r="31" class="prog" stroke-dasharray="${ring} ${TEAM_ARC_RING_LENGTH}" transform="rotate(-90 39 39)"/></svg>`
            + `<span class="txt"><span class="a">${escapeHtml(t('arc.eta'))}</span><b>${escapeHtml(parts.n)}</b><span class="u">${escapeHtml(parts.unit)}</span></span></div>`
        : '';
    return `<div class="wr-arc-box" style="--arc:${color}" role="img" aria-label="${aria}">${dial}`
        + `<div class="wr-arc-under"><div class="wr-arc-dist${stale ? ' is-stale' : ''}${estimate ? ' is-est' : ''}">${icon}<b>${escapeHtml(dist)}</b></div>${chips}</div></div>`;
}

function drawTeamArc(arc) {
    if (!arc || !arc.from || !arc.to) return;
    const geo = teamArcGeometry(arc.from, arc.to);
    if (!geo) return;
    const color = teamArcSafeColor(arc.color);
    const stale = !!arc.from.stale;
    const base = {pane: 'teamArcPane', interactive: false};

    // A dark casing under everything, so the arch reads on satellite imagery as well as on a plain map.
    L.polyline(geo.points, {...base, color: '#0b1007', opacity: stale ? 0.4 : 0.5, weight: 10, lineCap: 'round', lineJoin: 'round', dashArray: stale ? '14 9' : null}).addTo(teamArcLayer);
    if (stale) {
        // An old fix: dashed and faint, so it does not claim to be where the team is now.
        L.polyline(geo.points, {...base, color, opacity: 0.6, weight: 5, lineCap: 'round', dashArray: '14 9'}).addTo(teamArcLayer);
    } else {
        const per = Math.floor(TEAM_ARC_SAMPLES / TEAM_ARC_SEGMENTS);
        for (let i = 0; i < TEAM_ARC_SEGMENTS; i++) {
            L.polyline(geo.points.slice(i * per, (i + 1) * per + 1), {
                ...base, color, weight: 6, lineCap: 'butt', lineJoin: 'round',
                opacity: 0.15 + 0.85 * (i / (TEAM_ARC_SEGMENTS - 1)),
            }).addTo(teamArcLayer);
        }
        // Ticks along it, like a ruler: the arch is longer than it looks.
        L.polyline(geo.points, {...base, color: '#ffffff', opacity: 0.7, weight: 12, lineCap: 'butt', dashArray: '1.6 30'}).addTo(teamArcLayer);
    }

    // A sector or an area has no pin to land on: mark where the arch ends.
    if (arc.kind === 'sector' || arc.kind === 'area') {
        L.circleMarker([arc.to.lat, arc.to.lng], {...base, radius: 8, color, weight: 3, fillColor: '#0b1007', fillOpacity: 0.6}).addTo(teamArcLayer);
        L.circleMarker([arc.to.lat, arc.to.lng], {...base, radius: 2.5, color, weight: 0, fillColor: color, fillOpacity: 1}).addTo(teamArcLayer);
    }

    const chordPx = map.latLngToContainerPoint([arc.from.lat, arc.from.lng]).distanceTo(map.latLngToContainerPoint([arc.to.lat, arc.to.lng]));
    const compact = chordPx < TEAM_ARC_COMPACT_PX;
    L.marker(geo.apex, {
        pane: 'teamArcLabelPane', interactive: false, keyboard: false,
        icon: L.divIcon({className: 'wr-arc-label', iconSize: [0, 0], html: teamArcLabelHtml(arc, color, compact)}),
    }).addTo(teamArcLabelLayer);

    // The straight line under the arch, with its own length written on it: how
    // far the way really is next to how far the team is as the crow flies. Only
    // where the pill says the ROUTED distance (otherwise the two would be the
    // same number twice), and not on an arc too short to hold a label.
    if (teamArcRouted(arc) && !compact) {
        const line = [[arc.from.lat, arc.from.lng], [arc.to.lat, arc.to.lng]];
        L.polyline(line, {...base, color: '#0b1007', opacity: 0.4, weight: 4, lineCap: 'round'}).addTo(teamArcLayer);
        L.polyline(line, {...base, color: '#ffffff', opacity: stale ? 0.45 : 0.8, weight: 2, lineCap: 'round', dashArray: '2 7'}).addTo(teamArcLayer);
        L.marker([(arc.from.lat + arc.to.lat) / 2, (arc.from.lng + arc.to.lng) / 2], {
            pane: 'teamArcLabelPane', interactive: false, keyboard: false,
            icon: L.divIcon({
                className: 'wr-arc-label', iconSize: [0, 0],
                html: `<div class="wr-arc-straight">${escapeHtml(t('arc.straight_line', {dist: teamArcFormatDistance(arc.distance_m)}))}</div>`,
            }),
        }).addTo(teamArcLabelLayer);
    }
}

function renderTeamArcs(arcs) {
    if (!teamArcLayer) return;
    teamArcLayer.clearLayers();
    teamArcLabelLayer.clearLayers();
    if (teamArcSuspended) return;
    (arcs || []).forEach(arc => {
        // One arc failing to draw must not take the others, or the poll, with it.
        try { drawTeamArc(arc); } catch (e) { console.error('team arc', e); }
    });
}
