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

// The BAND between two LPB rings, optionally narrowed to one angular wedge
// of it — what "ring N" actually means operationally for N > 0, since ring
// N's disc wholly contains every smaller ring's. weightedWedgePolygonPoints()
// above always spans center-to-radius, so using it for ring 2/3/4 hands a
// team ground already assigned to the rings inside it; worse, it made
// verified coverage measure a team's GPS against the whole disc while their
// route only ever swept the band, under-reporting real coverage and tripping
// the <60% "low coverage" warning on correctly-executed searches. Same
// "don't re-walk the smaller ring(s)" reasoning sectorSearchLegPoints()
// already applies to ROUTES, applied to the assigned AREA so the two finally
// describe the same ground.
//
// innerRadiusMeters <= 0 returns the plain pie slice (center + outer arc) —
// ring 0 genuinely has no hole, so it stays byte-identical to what
// weightedWedgePolygonPoints() produced.
//
// Otherwise: outer arc from startBearingDeg to startBearingDeg+sweepDeg,
// then the inner arc walked BACK along the same bearings, giving a 4-sided
// annular wedge whose two radial edges are the wedge's own sides (the
// implicit closing edge, last inner point -> first outer point, is the
// second one). Both arcs sample INCLUSIVE of both bearings, the same
// convention weightedWedgePolygonPoints() uses and for the same reason: a
// team's area has to actually reach both edges of their wedge.
//
// At sweepDeg >= 360 (one team gets the whole ring) this closes into a
// slit annulus: first and last outer points land on the same bearing, so
// the shape pinches to zero width at that seam and the two coincident
// radial edges there are traversed in opposite directions. That is safe for
// the ray-casting pointInPolygon() in functions-warroom.php that sector
// coverage runs — the zero-length edges contribute no crossings at all
// (latI === latJ fails its own (latI > lat) !== (latJ > lat) test), and the
// doubled seam is always crossed twice or not at all, so it cancels and
// never flips the inside/outside parity.
function annularWedgePolygonPoints(center, innerRadiusMeters, outerRadiusMeters, startBearingDeg, sweepDeg, numPoints) {
    const n = Math.max(2, numPoints);
    const bearingAt = i => startBearingDeg + sweepDeg * i / (n - 1);
    const outer = [];
    for (let i = 0; i < n; i++) {
        const pt = destinationPoint(center, bearingAt(i), outerRadiusMeters);
        outer.push([pt.lat, pt.lng]);
    }
    if (innerRadiusMeters <= 0) {
        return [[center.lat, center.lng], ...outer];
    }
    const inner = [];
    for (let i = n - 1; i >= 0; i--) {
        const pt = destinationPoint(center, bearingAt(i), innerRadiusMeters);
        inner.push([pt.lat, pt.lng]);
    }
    return [...outer, ...inner];
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

// ── Automatic sector grid ───────────────────────────────────────────────────
// Mirror of buildSectorGridCells() in includes/functions-warroom.php. That
// function's comment carries the reasoning behind every choice repeated here
// (meters only decide cols/rows, the cells themselves are cut out of the
// bounding box in degrees, a cell is kept when its CENTER is inside).
//
// This copy exists for the live preview in war-room.php's grid tool. The
// server recomputes the grid from the stored area polygon and never accepts
// a cell list from the client, so these two must produce the same cells or
// the preview's "Create 13 sectors" button is lying about what it is about
// to do. tests/fixtures/grid-cases.json is asserted by both sides for
// exactly that reason — the fixture, not discipline, is what holds them
// together.
//
// Deliberately does NOT reuse destinationPoint() above: that one is
// spherical (R=6371000) while the PHP side is flat local meters, and mixing
// the two models is precisely how two implementations of "the same" grid
// drift apart.
const GRID_SECTOR_SIZE_MIN_M = 50;
const GRID_SECTOR_SIZE_MAX_M = 2000;

// PHP's round() rounds halves away from zero; JS's Math.round() rounds them
// toward +Infinity, and the two languages' libm can differ in the last bit
// anyway. Matching PHP's direction here keeps them as close as they can get;
// the shared fixture compares coordinates with a 1e-6 tolerance (~11cm, well
// inside GPS error) and compares the counts exactly, since the counts are
// what the UI promises out loud.
function roundTo(n, decimals) {
    const f = Math.pow(10, decimals);
    return Math.sign(n) * Math.round(Math.abs(n) * f) / f;
}

// Ray casting, odd-number-of-crossings rule — the JS twin of
// pointInPolygon() in includes/functions-warroom.php, same ring of
// [lat, lng] pairs (not GeoJSON's [lng, lat]). Nothing on this side of the
// app had one before the grid preview needed it.
function pointInPolygon(lat, lng, geo) {
    let inside = false;
    for (let i = 0, j = geo.length - 1; i < geo.length; j = i++) {
        const latI = Number(geo[i][0]), lngI = Number(geo[i][1]);
        const latJ = Number(geo[j][0]), lngJ = Number(geo[j][1]);
        if (((latI > lat) !== (latJ > lat))
            && (lng < (lngJ - lngI) * (lat - latI) / (latJ - latI) + lngI)) {
            inside = !inside;
        }
    }
    return inside;
}

// Metres per degree of latitude, and per degree of longitude at that
// latitude, on the WGS84 ellipsoid — the same reference frame the GPS in
// every phone reports against, so these are the numbers that make a figure
// on screen mean the ground under a rescuer.
//
// What they replace was a flat 111320 for latitude and 111320·cos φ for
// longitude. That is a sphere's number, and it is wrong in the same
// direction everywhere in Greece: at 35°N a degree of latitude is really
// 110.941 m, not 111.320, while a degree of longitude is 91.289 m, not
// 91.189. Checked against the closed-form area of a graticule quadrangle on
// WGS84 — exact, no approximation — that pushed every area here between
// 0.08% (Thrace) and 0.24% (Crete) too HIGH. Small beside GPS error, but a
// bias rather than noise: it never averages out, and it reported a 359.2
// στρέμματα sector as 360.0. With these it is 0.00001%.
//
// They are also what the grid measures its bounding box with, so a cell the
// tool calls 600 m really is 600 m of ground rather than 598.
const WGS84_A = 6378137.0;                  // semi-major axis, metres
const WGS84_E2 = 0.00669437999014;          // first eccentricity squared, f(2−f)
const DEG_TO_RAD = Math.PI / 180;

function metersPerDegreeLat(latDeg) {
    const sinLat = Math.sin(latDeg * DEG_TO_RAD);
    // Meridian radius of curvature M(φ), one degree of it.
    return DEG_TO_RAD * (WGS84_A * (1 - WGS84_E2)) / Math.pow(1 - WGS84_E2 * sinLat * sinLat, 1.5);
}

function metersPerDegreeLng(latDeg) {
    const sinLat = Math.sin(latDeg * DEG_TO_RAD);
    // Prime-vertical radius N(φ) times cos φ — the radius of the parallel.
    // cos is clamped exactly as it was before, so a polygon at the pole
    // cannot produce zero-width grid columns.
    const cosLat = Math.max(0.01, Math.cos(latDeg * DEG_TO_RAD));
    return DEG_TO_RAD * (WGS84_A / Math.sqrt(1 - WGS84_E2 * sinLat * sinLat)) * cosLat;
}

// Flat shoelace over a local equirectangular projection, using the WGS84
// metres-per-degree above taken at the bounding box's own centre latitude —
// deliberately the same projection gridCellsForPolygon() below measures its
// cells with, so the square metres and the "397 × 412 m" printed beside them
// can never disagree about the same rectangle.
//
// Accuracy against the exact ellipsoidal quadrangle area is better than
// 0.001% anywhere in Greece at mission scale, which is two or three orders
// of magnitude inside GPS error — see the accuracy tests, which measure it
// rather than assert a rounded sample. The one thing the flat projection
// still assumes is that an edge drawn between two vertices is straight on
// the map, which is exactly what the person drawing it meant.
// A point on a closed ring addressed by a "ring position": floor(r) is the
// edge index (the edge running from ring[i] to ring[i+1]) and frac(r) is how
// far along that edge, so r = 3 is vertex 3 itself and r = 3.5 is halfway to
// vertex 4. One representation covers both "clicked an existing corner" and
// "clicked partway along an edge" — a corner-only model can never split a
// triangle, since joining any two of its corners is an existing edge.
function pointAtRingPos(ring, r) {
    const n = ring.length;
    const i = ((Math.floor(r) % n) + n) % n;
    const t = r - Math.floor(r);
    const a = ring[i];
    if (t < 1e-9) return a.slice();
    const b = ring[(i + 1) % n];
    return [a[0] + (b[0] - a[0]) * t, a[1] + (b[1] - a[1]) * t];
}

// Cuts a closed ring into two along the straight line between two ring
// positions. Returns [] when either piece degenerates to fewer than three
// points. Both pieces walk their own arc IN RING ORDER and then close over
// the shared cut line, so the two of them tile the original exactly.
//
// That last property is the whole point, and it is what the previous
// inline version in war-room.php got wrong. It built the second piece by
// sweeping i = 0..n and keeping whatever fell outside the first arc, which
// emits (0, 1, …, r1-1, r2+1, …, n-1) whenever the second arc wraps past
// index 0 — in other words, for every cut whose first point was not on the
// ring's very first vertex. That is not a ring order at all: the polygon
// jumps from just before the first cut straight to just after the second and
// folds into a bowtie whose lobes cancel. Shipped in v3.154.3 and invisible
// until a sector's area was put on screen and two halves stopped adding up
// to the whole they came from. Hence the tiling assertions in this
// function's tests: a split that does not conserve area is not a split.
function splitRingAtCutPositions(ring, rA, rB) {
    if (!Array.isArray(ring) || ring.length < 3) return [];
    let r1 = rA, r2 = rB;
    if (r1 > r2) { const tmp = r1; r1 = r2; r2 = tmp; }
    if (r1 === r2) return [];

    const n = ring.length;
    const p1 = pointAtRingPos(ring, r1);
    const p2 = pointAtRingPos(ring, r2);

    // The near arc is a contiguous ascending run, so a plain sweep is
    // already in ring order.
    const polyA = [p1];
    for (let i = 0; i < n; i++) {
        if (i > r1 && i < r2) polyA.push(ring[i]);
    }
    polyA.push(p2);

    // The far arc is the one that wraps, so it is walked outward from the
    // second cut point and round, stopping at the first vertex that belongs
    // to the near arc instead. Same membership test as before; only the
    // order changes.
    const polyB = [p2];
    for (let step = 0; step < n; step++) {
        const i = (Math.floor(r2) + 1 + step) % n;
        if (!(i > r2 || i < r1)) break;
        polyB.push(ring[i]);
    }
    polyB.push(p1);

    if (polyA.length < 3 || polyB.length < 3) return [];
    return [polyA, polyB];
}

function polygonAreaSquareMeters(geo) {
    if (!Array.isArray(geo) || geo.length < 3) return 0;

    const lats = geo.map(pt => Number(pt[0]));
    const lngs = geo.map(pt => Number(pt[1]));
    const centerLat = (Math.min(...lats) + Math.max(...lats)) / 2;
    const mPerDegLat = metersPerDegreeLat(centerLat);
    const mPerDegLng = metersPerDegreeLng(centerLat);

    // Metres relative to the first vertex, not absolute ones. Subtracting the
    // origin up front keeps the cross products small; multiplying raw
    // ~4-million-metre coordinates together and leaning on the subtraction to
    // hand back a few thousand is exactly the shape of a cancellation bug.
    const x = lngs.map(lng => (lng - lngs[0]) * mPerDegLng);
    const y = lats.map(lat => (lat - lats[0]) * mPerDegLat);

    let twiceArea = 0;
    for (let i = 0, j = geo.length - 1; i < geo.length; j = i++) {
        twiceArea += x[j] * y[i] - x[i] * y[j];
    }
    // abs() — a ring drawn clockwise and the same ring drawn the other way
    // round are the same piece of ground. Nothing upstream enforces a winding
    // order: an area is whatever order the admin happened to click in.
    return Math.abs(twiceArea) / 2;
}

function gridCellsForPolygon(geo, sizeM) {
    const size = Math.max(GRID_SECTOR_SIZE_MIN_M, Math.min(GRID_SECTOR_SIZE_MAX_M, sizeM));

    const lats = geo.map(pt => Number(pt[0]));
    const lngs = geo.map(pt => Number(pt[1]));
    const minLat = Math.min(...lats), maxLat = Math.max(...lats);
    const minLng = Math.min(...lngs), maxLng = Math.max(...lngs);

    const centerLat = (minLat + maxLat) / 2;
    const mPerDegLat = metersPerDegreeLat(centerLat);
    const mPerDegLng = metersPerDegreeLng(centerLat);

    const bboxH = (maxLat - minLat) * mPerDegLat;
    const bboxW = (maxLng - minLng) * mPerDegLng;

    // The ratio is rounded before ceil() on both sides. It is the one place
    // where a last-bit difference between PHP's and JS's cos() would not stay
    // microscopic: on an area whose width divides exactly by the cell size,
    // 8.000000000000001 and 8.0 ceil to 9 and 8 — a whole extra column of
    // sectors on one side and not the other. 9 decimals of a ratio is half a
    // micron of ground; nothing real survives down there to be lost.
    const cols = Math.max(1, Math.ceil(roundTo(bboxW / size, 9)));
    const rows = Math.max(1, Math.ceil(roundTo(bboxH / size, 9)));

    const latStep = (maxLat - minLat) / rows;
    const lngStep = (maxLng - minLng) / cols;

    const cells = [];
    // Cells whose centre missed the area. The server ignores them; the
    // preview draws them dashed, so a coordinator can see the grid's full
    // extent and which corners of it are not being tasked. Produced by the
    // same single loop as the kept ones rather than a second pass, so there
    // is no way for the two sets to disagree about where a cell was.
    const dropped = [];
    for (let r = 0; r < rows; r++) {
        const latTop = maxLat - r * latStep;
        const latBottom = maxLat - (r + 1) * latStep;
        for (let c = 0; c < cols; c++) {
            const lngLeft = minLng + c * lngStep;
            const lngRight = minLng + (c + 1) * lngStep;
            const cell = [
                [roundTo(latTop, 6), roundTo(lngLeft, 6)],
                [roundTo(latTop, 6), roundTo(lngRight, 6)],
                [roundTo(latBottom, 6), roundTo(lngRight, 6)],
                [roundTo(latBottom, 6), roundTo(lngLeft, 6)],
            ];
            if (pointInPolygon((latTop + latBottom) / 2, (lngLeft + lngRight) / 2, geo)) {
                cells.push(cell);
            } else {
                dropped.push(cell);
            }
        }
    }

    return {
        cells,
        dropped,
        cols,
        rows,
        total: cols * rows,
        kept: cells.length,
        requested_m: size,
        actual_w_m: roundTo(bboxW / cols, 1),
        actual_h_m: roundTo(bboxH / rows, 1),
    };
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

// Square metres up to a hectare, then the unit the language actually thinks
// in, then square kilometres. The middle band is the one that matters: a
// search sector is usually a few hundred thousand square metres, which is
// precisely the size nobody can picture written out in square metres. Greek
// says στρέμματα there and English says hectares, so the divisor lives in
// the language file beside the unit name — it is a property of the locale in
// the same way a decimal separator is, not a magic number in this file.
const AREA_TIER_MID_FROM_M2 = 10000;
const AREA_TIER_KM2_FROM_M2 = 1000000;

// Which of the three tiers a set of figures shown TOGETHER should all use:
// whichever one the smallest of them would have picked on its own.
//
// Areas printed side by side are there to be compared, and a sector reading
// "346 στρ." next to a total reading "35.60 τ.χλμ." makes the reader convert
// units before they can see that one is simply 103 of the other. So a group
// commits to one unit — and it has to be the finest one any member needs.
// The small figure is the one actually being decided (how big to make a
// sector); pushing it up into square kilometres renders it as 0.35 and
// destroys the very number the group exists to show, whereas pulling the
// large one down only makes it longer, which grouped thousands then fix.
// The unit an admin pinned in Settings → Γενικές, if this page declares one.
// war-room.php emits AREA_UNIT_PREFERENCE from the stored setting; every
// other context (and the tests) simply has no such global, and 'auto' — the
// shipped default — lets each group choose for itself as described above.
// Read through typeof rather than a bare reference so an undeclared global
// is not a ReferenceError.
function areaUnitPreference() {
    const pref = typeof AREA_UNIT_PREFERENCE !== 'undefined' ? AREA_UNIT_PREFERENCE : 'auto';
    return pref === 'mid' || pref === 'm2' ? pref : 'auto';
}

function areaTierForGroup(valuesM2) {
    const usable = (valuesM2 || []).filter(v => typeof v === 'number' && isFinite(v) && v > 0);
    // Nothing to show is nothing to show, whatever the admin picked.
    if (!usable.length) return null;

    const forced = areaUnitPreference();
    if (forced !== 'auto') return forced;

    const smallest = Math.min(...usable);
    if (smallest < AREA_TIER_MID_FROM_M2) return 'm2';
    if (smallest < AREA_TIER_KM2_FROM_M2) return 'mid';
    return 'km2';
}

// Grouped thousands and the language's own decimal mark. Holding a whole
// group to στρέμματα routinely produces five digits, and 35597 is not a
// number anyone reads at a glance during a callout — Greek writes it 35.597
// and writes a half 16,5, English does the reverse, which is why the locale
// tag sits in the language file beside the units rather than here. The
// formatters are cached because building an Intl.NumberFormat is the
// expensive part and sector popups are rebuilt for every sector on every
// five-second poll tick.
const AREA_NUMBER_FORMATTERS = {};
function formatAreaNumber(value, decimals) {
    const locale = t('common.number_locale') || 'en-GB';
    const key = locale + '/' + decimals;
    if (!AREA_NUMBER_FORMATTERS[key]) {
        AREA_NUMBER_FORMATTERS[key] = new Intl.NumberFormat(locale, {
            minimumFractionDigits: decimals,
            maximumFractionDigits: decimals,
        });
    }
    return AREA_NUMBER_FORMATTERS[key].format(value);
}

// `tier` forces the unit, for a figure being shown alongside others — pass
// areaTierForGroup([…]) over the whole set. Left out, the figure picks its
// own, which is right for a lone number in a popup or a badge.
function formatAreaSquareMeters(m2, tier) {
    if (m2 === null || m2 === undefined || !isFinite(m2) || m2 <= 0) return '';
    const unit = tier || areaTierForGroup([m2]);
    if (unit === 'm2') return `${formatAreaNumber(m2, 0)} ${t('common.unit_area_m2')}`;
    if (unit === 'mid') {
        const perMidUnit = Number(t('common.unit_area_mid_divisor')) || 1000;
        const mid = m2 / perMidUnit;
        // One decimal only while it buys something. At three digits the
        // tenth of a στρέμμα is noise on a number used to size a sweep.
        // Two below ten, because an admin who pinned στρέμματα in Settings
        // still gets shown small plots in them, and 0,05 στρ. has to stay
        // distinguishable from 0,1 rather than collapsing onto it.
        const decimals = mid < 10 ? 2 : (mid < 100 ? 1 : 0);
        return `${formatAreaNumber(mid, decimals)} ${t('common.unit_area_mid')}`;
    }
    const km2 = m2 / 1000000;
    return `${formatAreaNumber(km2, km2 < 100 ? 2 : 0)} ${t('common.unit_area_km2')}`;
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
    if (sizeBytes <= SKIP_AT_OR_UNDER_BYTES) return true;
    // Deliberately does NOT pass sizeBytes through. The size fallback exists
    // for the rewrap, where the alternative is a file Viber will refuse and
    // the pass is therefore worth running. Upload compression is only ever an
    // optimisation, so an unknown duration stays a skip here: no reason to
    // put a phone in the field through a realtime re-encode it may not need.
    return videoTooLongToReencode(durationSeconds);
}

// The duration half of shouldSkipVideoCompression, split out because the
// container rewrap below needs the same ceiling without the size floor: a
// re-encode runs in realtime, so past a couple of minutes the operator is
// left staring at a progress bar for longer than the problem is worth.
//
// Unknown duration used to count as "too long" outright. That looked
// conservative and was actually self-defeating: a WebM produced by
// MediaRecorder routinely carries NO duration in its header at all —
// <video>.duration reads NaN or Infinity — and those are precisely the files
// the rewrap exists to convert. The guard was refusing the exact case it was
// written to serve, so a stuck WebM stayed WebM and Viber kept dropping it.
//
// Size stands in for duration when the header has none: a field clip small
// enough to sit under the cap cannot be a two-minute video at any bitrate a
// phone records at, so the realtime pass is still bounded. Size unknown too
// means genuinely no information, which does fall back to skipping.
function videoTooLongToReencode(durationSeconds, sizeBytes) {
    const MAX_SECONDS = 120;
    const UNKNOWN_DURATION_MAX_BYTES = 25 * 1024 * 1024;
    if (Number.isFinite(durationSeconds) && durationSeconds > 0) {
        return durationSeconds > MAX_SECONDS;
    }
    return !(Number.isFinite(sizeBytes) && sizeBytes > 0 && sizeBytes <= UNKNOWN_DURATION_MAX_BYTES);
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

// MediaRecorder output containers, most- to least-preferred. MP4 leads for
// a reason that has nothing to do with file size: WebM is refused outright
// by several of the share targets field crews actually use. Viber takes
// MP4/MOV/3GP and silently drops a .webm handed to it by the OS share
// sheet — from the Action Room that looks exactly like a broken Share
// button rather than a container problem, which is how it was reported.
// Every mp4 spelling any engine accepts is listed because support is
// genuinely inconsistent between them: some report false for the
// codec-qualified string and true for the bare one, others the reverse,
// and a browser that can record MP4 must never be left on WebM just
// because the first spelling tried wasn't the one it recognises.
const MP4_RECORDER_MIME_CANDIDATES = [
    'video/mp4;codecs=avc1.42E01E,mp4a.40.2',
    'video/mp4;codecs=h264,aac',
    'video/mp4;codecs=avc1',
    'video/mp4',
];
const WEBM_RECORDER_MIME_CANDIDATES = [
    'video/webm;codecs=vp8,opus',
    'video/webm',
];

// mp4Only drops the WebM fallbacks entirely, so the caller gets null rather
// than a WebM recorder it didn't ask for — a WebM-to-WebM re-encode costs a
// full realtime pass and leaves the file exactly as unshareable as it
// started, which is strictly worse than not bothering.
function videoCompressionMimeCandidates(mp4Only) {
    return mp4Only
        ? MP4_RECORDER_MIME_CANDIDATES.slice()
        : MP4_RECORDER_MIME_CANDIDATES.concat(WEBM_RECORDER_MIME_CANDIDATES);
}

// True for a video whose container the share sheet can't be trusted with —
// see MP4_RECORDER_MIME_CANDIDATES. Filename is checked alongside the MIME
// type because a file picked off disk (rather than straight out of
// MediaRecorder) routinely arrives with an empty or generic type while
// still being a WebM.
function isUnshareableVideoContainer(mimeType, fileName) {
    return String(mimeType || '').toLowerCase().indexOf('webm') !== -1
        || /\.webm$/i.test(String(fileName || ''));
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

/**
 * Picks the richest share payload the platform will actually accept, so the
 * object handed to navigator.share() is provably the same one canShare()
 * approved. Returns null when no file payload is shareable at all, which the
 * caller treats as "fall back to the link dropdown".
 *
 * The bug this exists to prevent: war-room.php used to test
 * canShare({files}) and then call share({files, text}). Chrome on Windows
 * answers true to the first and rejects the second — its bridge to the OS
 * share sheet does not take files and text together — so the guard passed
 * and the call threw. A photo silently degraded to the text-link dropdown
 * (Viber received a bare link, never the image) and a rewrapped video, whose
 * branch had no fallback, did nothing at all.
 *
 * The caption is the half worth dropping when the two disagree: the file is
 * the point of the share, the caption is a nicety the operator can retype.
 *
 * canShare is injected rather than read off navigator so this stays pure and
 * testable, same convention as the other extracted helpers here.
 */
function shareablePayload(file, caption, canShare) {
    const withCaption = { files: [file], text: caption };
    if (canShare(withCaption)) return withCaption;
    const filesOnly = { files: [file] };
    if (canShare(filesOnly)) return filesOnly;
    return null;
}

/**
 * True only on a pointer-driven computer: no native app bridge, not a phone
 * or tablet, and a real mouse or trackpad. That is exactly where the OS share
 * sheet cannot deliver a media file — Chrome on Windows accepts the file and
 * then hands the target a link or nothing, which is why the media Share
 * control is dropped there entirely in favour of the Download button.
 *
 * Written to fail SAFE: every uncertain case returns false, keeping the Share
 * button. Losing it on a field volunteer's phone is far worse than leaving a
 * dead button on someone's desktop, so nothing but a positive identification
 * of a mouse-driven computer counts.
 *
 * Signals are injected rather than read off navigator/window so this stays
 * pure and testable, same convention as the other helpers here.
 */
function isPointerOnlyComputer({ hasNativeBridge, uaDataMobile, userAgent, maxTouchPoints, hasFinePointer }) {
    // The installed Android app shares through Capacitor's native plugin,
    // which works fine — never touch it.
    if (hasNativeBridge) return false;
    // The browser's own answer, where it gives one, beats any sniffing.
    if (uaDataMobile === true) return false;
    if (/Android|iPhone|iPad|iPod|Mobile|Silk|Kindle/i.test(userAgent || '')) return false;
    // iPadOS 13+ reports itself as "Macintosh"; a Mac reporting more than one
    // touch point is really an iPad.
    if (/Macintosh/i.test(userAgent || '') && (maxTouchPoints || 0) > 1) return false;
    // A touchscreen Windows laptop still has a fine pointer and still has the
    // broken desktop share sheet, so touch alone does not disqualify — but
    // without a fine pointer this cannot be a computer.
    return hasFinePointer === true;
}

if (typeof module !== 'undefined' && module.exports) {
    module.exports = {
        shareablePayload,
        isPointerOnlyComputer,
        bearing,
        destinationPoint,
        circleToPolygonPoints,
        sectorSearchLegPoints,
        sectorSearchLegCount,
        ringDiscPolygonPoints,
        pointInPolygon,
        metersPerDegreeLat,
        metersPerDegreeLng,
        gridCellsForPolygon,
        polygonAreaSquareMeters,
        pointAtRingPos,
        splitRingAtCutPositions,
        weightedWedgePolygonPoints,
        annularWedgePolygonPoints,
        escapeHtml,
        parseCoordsInput,
        formatDistanceMeters,
        formatAreaSquareMeters,
        areaTierForGroup,
        areaUnitPreference,
        bearingToCompassAbbr,
        missingRouteDeliverablesClientSide,
        shouldSkipVideoCompression,
        videoTooLongToReencode,
        pickVideoCompressionMimeType,
        videoExtensionForMimeType,
        videoCompressionMimeCandidates,
        isUnshareableVideoContainer,
        MP4_RECORDER_MIME_CANDIDATES,
        WEBM_RECORDER_MIME_CANDIDATES,
        shouldSkipPhotoCompression,
    };
}
