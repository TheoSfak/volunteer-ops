<?php
/**
 * VolunteerOps - Action Room «Απόσταση από εδώ / Έως εδώ»
 *
 * The live map's measuring tool. POST only, AJAX. Three actions:
 *
 *   route     - two points in, the route between them on foot and by car out,
 *               each with its shape for drawing.
 *   nearest   - «Ποια ομάδα είναι πιο κοντά εδώ»: up to three starting points
 *               (the page picks the nearest teams from the positions it is
 *               already showing) to one destination, all routed at once.
 *   elevation - the height of the ground at up to 100 points, for a point's
 *               altitude and for the climb along a measured line.
 *
 * Open to everyone who can open the Action Room itself, not just command: a
 * volunteer standing at a trailhead has exactly the same question. The straight
 * line never comes from here — the page draws it the instant the second point
 * is chosen, so a slow or absent router costs nothing but the routed figures.
 *
 * WHAT LEAVES THIS BUILDING is the same as for every other routed distance
 * (includes/route-distance.php): coordinate pairs and nothing else. And nothing
 * is kept: a measurement is one person's question, not part of the operation's
 * record, so it is neither stored nor audited.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/route-distance.php';
require_once __DIR__ . '/includes/elevation.php';
requireLogin();

header('Content-Type: application/json');

/**
 * Routed legs per person per mission, and the window they are counted over.
 *
 * Every leg is two calls billed to the organisation's own Google account; a
 * «πιο κοντινή ομάδα» question is up to three legs. Thirty in ten minutes is
 * well past anyone measuring by hand and well short of a stuck script or a
 * finger resting on a phone screen running up an invoice.
 */
const MEASURE_RATE_MAX = 30;
const MEASURE_RATE_WINDOW = 600;

/** How many teams «Ποια ομάδα είναι πιο κοντά» routes. */
const MEASURE_NEAREST_MAX = 3;

/**
 * Height lookups, counted apart from routing: the service is free, but the
 * point menu asks it every time it opens, and it must not be possible to lean
 * on it from here without limit either.
 */
const ELEVATION_RATE_MAX = 120;

$userId = (int) getCurrentUserId();

if (!isPost()) {
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string) $_POST['csrf_token'])) {
    echo json_encode(['ok' => false, 'error' => t('common.invalid_request')]);
    exit;
}

$missionId = (int) post('mission_id');
$mission = dbFetchOne(
    "SELECT id, status, show_in_ops, responsible_user_id FROM missions WHERE id = ? AND deleted_at IS NULL",
    [$missionId]
);
if (!$mission || $mission['status'] !== STATUS_OPEN || empty($mission['show_in_ops'])) {
    echo json_encode(['ok' => false, 'error' => t('common.mission_not_found_or_inactive')]);
    exit;
}

// The same gate war-room.php itself applies: whoever can open this mission's
// Action Room can measure on its map, and nobody else.
$canManageWarRoom = canManageActionRoom($mission['responsible_user_id'] ? (int) $mission['responsible_user_id'] : null, $userId);
$isApprovedParticipant = (bool) dbFetchValue(
    "SELECT COUNT(*) FROM participation_requests pr
     JOIN shifts s ON s.id = pr.shift_id
     WHERE s.mission_id = ? AND pr.volunteer_id = ? AND pr.status = ?",
    [$missionId, $userId, PARTICIPATION_APPROVED]
);
if (!$canManageWarRoom && !$isApprovedParticipant) {
    echo json_encode(['ok' => false, 'error' => t('wr.access_denied')]);
    exit;
}

$fail = function (string $error): void {
    echo json_encode(['ok' => false, 'error' => $error]);
    exit;
};

/** A point from two POST fields, or null when either is not a coordinate. */
$pointFromFields = function (string $latField, string $lngField): ?array {
    $lat = post($latField);
    $lng = post($lngField);
    if (!is_numeric($lat) || !is_numeric($lng) || abs((float) $lat) > 90 || abs((float) $lng) > 180) return null;
    return [(float) $lat, (float) $lng];
};

/** A list of points from one JSON field, or null unless every entry is one. */
$pointsFromJson = function (string $field, int $max): ?array {
    $list = json_decode((string) post($field), true);
    if (!is_array($list) || !$list || count($list) > $max) return null;
    $points = [];
    foreach ($list as $p) {
        if (!is_array($p) || count($p) !== 2 || !is_numeric($p[0] ?? null) || !is_numeric($p[1] ?? null)) return null;
        if (abs((float) $p[0]) > 90 || abs((float) $p[1]) > 180) return null;
        $points[] = [(float) $p[0], (float) $p[1]];
    }
    return $points;
};

/**
 * Counted in the session, like the assistant's own limit, and BEFORE the
 * session is released below, because the counter lives in it. Returns the
 * wait in seconds when over, null when the $cost calls were granted.
 */
$takeQuota = function (string $key, int $max, int $cost): ?int {
    $now = time();
    $calls = array_values(array_filter(
        (array) ($_SESSION[$key] ?? []),
        fn($ts) => is_int($ts) && $ts > $now - MEASURE_RATE_WINDOW
    ));
    if (count($calls) + $cost > $max) {
        return max(1, MEASURE_RATE_WINDOW - ($now - ($calls[0] ?? $now)));
    }
    $_SESSION[$key] = array_merge($calls, array_fill(0, $cost, $now));
    return null;
};

$action = (string) (post('action') ?: 'route');

if ($action === 'elevation') {
    $points = $pointsFromJson('points', ELEVATION_MAX_POINTS);
    if ($points === null) $fail(t('common.invalid_request'));
    if ($takeQuota('elevation_calls_' . $missionId, ELEVATION_RATE_MAX, 1) !== null) {
        // Heights are an extra on the card, never the answer to the question
        // asked, so running out of them says nothing: the card simply goes
        // without, rather than showing an error beside a perfectly good route.
        echo json_encode(['ok' => true, 'elevations' => null]);
        exit;
    }
    session_write_close();
    echo json_encode(['ok' => true, 'elevations' => elevationLookup($points)]);
    exit;
}

if ($action !== 'route' && $action !== 'nearest') $fail(t('common.invalid_request'));

$to = $pointFromFields('to_lat', 'to_lng');
$origins = $action === 'route'
    ? (($from = $pointFromFields('from_lat', 'from_lng')) !== null ? [$from] : null)
    : $pointsFromJson('from', MEASURE_NEAREST_MAX);
if ($to === null || $origins === null) $fail(t('common.invalid_request'));

$wait = $takeQuota('measure_calls_' . $missionId, MEASURE_RATE_MAX, count($origins));
if ($wait !== null) $fail(t('measure.rate_limited', ['n' => (int) ceil($wait / 60)]));

// The routers can take seconds to answer. PHP's session file stays locked for
// the whole request otherwise, and every other request from this browser —
// the 5s poll included — would queue behind one measurement.
session_write_close();

$straights = array_map(fn($o) => gpsDistanceMeters($o[0], $o[1], $to[0], $to[1]), $origins);
$routing = routeDistanceAvailable();
$measured = $routing ? routeDistanceMeasureMany($origins, $to[0], $to[1]) : [];

$shape = function (?array $route, float $straight): ?array {
    if ($route === null) return null;
    return [
        'meters'  => $route['meters'],
        'minutes' => $route['minutes'],
        'source'  => $route['source'],
        'points'  => routeDistanceSimplify($route['points'] ?? []),
        'detour'  => routeDistanceIsDetour($straight, (float) $route['meters']),
    ];
};

$results = [];
foreach ($origins as $i => $_) {
    $m = $measured[$i] ?? ['walking' => null, 'driving' => null, 'walk_tried' => false, 'walk_failed' => false];
    $results[] = [
        'straight_m'  => (int) round($straights[$i]),
        'walking'     => $shape($m['walking'], $straights[$i]),
        'driving'     => $shape($m['driving'], $straights[$i]),
        'walk_tried'  => $m['walk_tried'],
        'walk_failed' => $m['walk_failed'],
    ];
}

if ($action === 'nearest') {
    echo json_encode(['ok' => true, 'routing' => $routing, 'results' => $results]);
    exit;
}

echo json_encode(['ok' => true, 'routing' => $routing] + $results[0]);
