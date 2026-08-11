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

// Coarse outward Archimedean spiral from center to maxRadiusMeters, used to
// seed a route with an "interior sweep" pattern (as opposed to
// circleToPolygonPoints()'s boundary-only trace) — mirrors the standard SAR
// expanding-circle technique of starting at the point last seen and working
// outward. numPoints/numLoops are required, not defaulted: this is always
// called with fixed constants sized to fit mission-route.php's 30-waypoint
// cap (see war-room.php's caller) rather than scaled by radius the way
// circleToPolygonPoints() is — a spiral is inherently an illustrative
// search-pattern aid, not a precision boundary trace, so the same coarse
// point count reads fine at any ring radius. t=0 lands exactly on center
// (radius 0, so destinationPoint()'s bearing term is moot); t=1 lands
// exactly maxRadiusMeters out, after numLoops full turns.
function spiralSweepPoints(center, maxRadiusMeters, numPoints, numLoops) {
    const points = [];
    const totalAngle = numLoops * 360;
    for (let i = 0; i < numPoints; i++) {
        const t = numPoints === 1 ? 0 : i / (numPoints - 1);
        const pt = destinationPoint(center, (totalAngle * t) % 360, maxRadiusMeters * t);
        points.push([pt.lat, pt.lng]);
    }
    return points;
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

function bearingToCompassAbbr(deg) {
    if (deg === null || deg === undefined) return '';
    const keys = ['compass.n', 'compass.ne', 'compass.e', 'compass.se', 'compass.s', 'compass.sw', 'compass.w', 'compass.nw'];
    return t(keys[Math.round(deg / 45) % 8]);
}

function missingRouteDeliverablesClientSide(wp, noteValue) {
    const missing = [];
    if (wp.require_photo && !wp.photo) missing.push(t('route.deliverable_photo'));
    if (wp.require_video && !wp.video) missing.push(t('route.deliverable_video'));
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
    const SKIP_OVER_SECONDS = 120;
    if (sizeBytes <= SKIP_AT_OR_UNDER_BYTES) return true;
    if (!Number.isFinite(durationSeconds) || durationSeconds <= 0) return true;
    if (durationSeconds > SKIP_OVER_SECONDS) return true;
    return false;
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

if (typeof module !== 'undefined' && module.exports) {
    module.exports = {
        bearing,
        destinationPoint,
        circleToPolygonPoints,
        spiralSweepPoints,
        escapeHtml,
        parseCoordsInput,
        formatDistanceMeters,
        bearingToCompassAbbr,
        missingRouteDeliverablesClientSide,
        shouldSkipVideoCompression,
        pickVideoCompressionMimeType,
        videoExtensionForMimeType,
        shouldSkipPhotoCompression,
    };
}
