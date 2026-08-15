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
        sectorSearchLegPoints,
        sectorSearchLegCount,
        ringDiscPolygonPoints,
        weightedWedgePolygonPoints,
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
