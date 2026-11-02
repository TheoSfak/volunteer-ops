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
function arNearestOnPolygon(me, geo) {
    const pts = geo.map(p => arLocal(me, {lat: Number(p[0]), lng: Number(p[1])}));
    let best = null;
    for (let i = 0; i < pts.length; i++) {
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

// ── The screen ───────────────────────────────────────────────────────────────

const AR_COL = {green: [74, 222, 128], cyan: [34, 211, 238], red: [248, 81, 73], orange: [255, 145, 60], amber: [251, 191, 36], white: [240, 246, 242], grey: [160, 170, 165]};
const AR_FONT = 'system-ui, -apple-system, "Segoe UI", Roboto, sans-serif';
const AR_MOVING_MPS = 1.5;        // faster than this and the screen dims: look where you are going
const AR_SESSION_MS = 10 * 60000; // camera + GPS + sensors heat a phone; ask before going on
const AR_SECTOR_OUT_M = 100;      // the same distance the server warns at (checkSectorExitWarnings)
const AR_MAX_ELEV_POINTS = 90;
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
            <div class="wr-ar-tiles">
                <div class="wr-ar-tile"><small>${escapeHtml(t('ar.tile_dist'))}</small><b data-ar-k="dist"></b></div>
                <div class="wr-ar-tile"><small>${escapeHtml(t('ar.tile_dz'))}</small><b data-ar-k="dz"></b></div>
                <div class="wr-ar-tile"><small>${escapeHtml(t('ar.tile_eta'))}</small><b data-ar-k="eta"></b></div>
                <div class="wr-ar-tile"><small>${escapeHtml(t('ar.tile_gps'))}</small><b data-ar-k="gps"></b></div>
            </div>
            <div class="wr-ar-title" data-ar-k="title"></div>
            <div class="wr-ar-hint" data-ar-k="hint"></div>
            <button type="button" class="wr-ar-go" data-ar="go" hidden></button>
            <div class="wr-ar-links">
                <button type="button" data-ar="compass"><i class="bi bi-compass me-1"></i>${escapeHtml(t('arrow.open_btn'))}</button>
                <button type="button" data-ar="decline" hidden>${escapeHtml(t('decline.btn'))}</button>
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
        pose: null, orientAt: 0, absolute: false, iosOffset: 0,
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
            arState.moving = typeof speed === 'number' && !isNaN(speed) && speed >= AR_MOVING_MPS;
            arLoadElevations(false);
        }, () => {}, {enableHighAccuracy: true, maximumAge: 0, timeout: 20000});
    }
    if (navigator.wakeLock && navigator.wakeLock.request) {
        navigator.wakeLock.request('screen')
            .then(lock => { if (arState) arState.wakeLock = lock; else lock.release().catch(() => {}); })
            .catch(() => {});
    }
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
    if (!arState || !kind) return;
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
    const basis = arSmoothBasis(arState.pose ? arState.pose.basis : null, arBasis(alpha, e.beta, e.gamma, screenAngle), 1 - Math.exp(-dt / 0.12));
    arState.pose = {basis: basis, screenAngle: screenAngle};
    arState.orientAt = now;
}
function arScreenAngle() {
    return (screen.orientation && typeof screen.orientation.angle === 'number') ? screen.orientation.angle : (Number(window.orientation) || 0);
}

function arMe() {
    const pos = arState.fix || (typeof latestAutoPosition !== 'undefined' ? latestAutoPosition : null);
    if (!pos || !pos.coords || Date.now() - pos.timestamp > 2 * 60000) return null;
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
            ctx.closePath(); ctx.stroke();
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
    const poseFresh = arState.pose && Date.now() - arState.orientAt < 3000;
    const world = el.querySelector('.wr-ar-world');
    const ctx = world.getContext('2d');
    ctx.setTransform(arState.dpr, 0, 0, arState.dpr, 0, 0);
    ctx.clearRect(0, 0, arState.cw, arState.ch);
    el.classList.toggle('wr-ar-dim', arState.moving);
    arUpdateStaticHud(el, m);
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
            if (!a.geo || a.geo.length < 3) return;
            const vecs = a.geo.map(p => arVec(me, p[0], p[1]));
            // A cliff is orange and stands taller, so an exposed ledge cannot be mistaken for a plain danger zone.
            const kind = arZoneKind(a);
            const zoneCol = kind === 'cliff' ? AR_COL.orange : AR_COL.red;
            arDrawWall(S, vecs, zoneCol, kind === 'cliff' ? 30 : 18, true, true);
            const near = arNearestOnPolygon(me, a.geo);
            S.radar.push({poly: a.geo, col: zoneCol});
            a.geo.forEach(p => { const v = arVec(me, p[0], p[1]); pushMark(arBearingElevation([v.x, v.y, 0]).az, zoneCol, 'sq'); });
            if (near && (!info.hazard || near.dist < info.hazard.dist)) {
                const v = arVec(me, near.lat, near.lng);
                info.hazard = {dist: near.dist, name: a.label, kind: kind, az: arBearingElevation([v.x, v.y, 0]).az, inside: pointInPolygon(me.lat, me.lng, a.geo)};
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
        arOfferCandidate,
    };
}
