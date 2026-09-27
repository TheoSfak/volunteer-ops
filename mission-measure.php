<?php
/**
 * VolunteerOps - Action Room «Απόσταση από εδώ / Έως εδώ»
 *
 * The live map's measuring tool: two points in, the route between them on
 * foot and by car out, each with its shape for drawing. POST only, AJAX.
 *
 * Open to everyone who can open the Action Room itself, not just command: a
 * volunteer standing at a trailhead has exactly the same question. The straight
 * line never comes from here — the page draws it the instant the second point
 * is chosen, so a slow or absent router costs nothing but the routed figures.
 *
 * WHAT LEAVES THIS BUILDING is the same as for every other routed distance
 * (includes/route-distance.php): two coordinate pairs and nothing else. And
 * nothing is kept: a measurement is one person's question, not part of the
 * operation's record, so it is neither stored nor audited.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/route-distance.php';
requireLogin();

header('Content-Type: application/json');

/**
 * Measurements per person per mission, and the window they are counted over.
 *
 * Every one of them is two calls billed to the organisation's own Google
 * account. Thirty in ten minutes is well past anyone measuring by hand and
 * well short of a stuck script or a finger resting on a phone screen running
 * up an invoice.
 */
const MEASURE_RATE_MAX = 30;
const MEASURE_RATE_WINDOW = 600;

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

$coords = [];
foreach (['from_lat' => 90, 'from_lng' => 180, 'to_lat' => 90, 'to_lng' => 180] as $field => $limit) {
    $raw = post($field);
    if (!is_numeric($raw) || abs((float) $raw) > $limit) {
        echo json_encode(['ok' => false, 'error' => t('common.invalid_request')]);
        exit;
    }
    $coords[$field] = (float) $raw;
}

// Counted in the session, like the assistant's own limit, and BEFORE the
// session is released below, because the counter lives in it.
$rateKey = 'measure_calls_' . $missionId;
$now = time();
$calls = array_values(array_filter(
    (array) ($_SESSION[$rateKey] ?? []),
    fn($ts) => is_int($ts) && $ts > $now - MEASURE_RATE_WINDOW
));
if (count($calls) >= MEASURE_RATE_MAX) {
    $wait = max(1, MEASURE_RATE_WINDOW - ($now - $calls[0]));
    echo json_encode(['ok' => false, 'error' => t('measure.rate_limited', ['n' => (int) ceil($wait / 60)])]);
    exit;
}
$calls[] = $now;
$_SESSION[$rateKey] = $calls;

// The routers can take seconds to answer. PHP's session file stays locked for
// the whole request otherwise, and every other request from this browser —
// the 5s poll included — would queue behind one measurement.
session_write_close();

$straight = gpsDistanceMeters($coords['from_lat'], $coords['from_lng'], $coords['to_lat'], $coords['to_lng']);

if (!routeDistanceAvailable()) {
    echo json_encode(['ok' => true, 'straight_m' => (int) round($straight), 'walking' => null, 'driving' => null, 'walk_tried' => false, 'routing' => false]);
    exit;
}

$routes = routeDistanceMeasure($coords['from_lat'], $coords['from_lng'], $coords['to_lat'], $coords['to_lng']);

$shape = function (?array $route) use ($straight): ?array {
    if ($route === null) return null;
    return [
        'meters'  => $route['meters'],
        'minutes' => $route['minutes'],
        'source'  => $route['source'],
        'points'  => routeDistanceSimplify($route['points'] ?? []),
        'detour'  => routeDistanceIsDetour($straight, (float) $route['meters']),
    ];
};

echo json_encode([
    'ok'          => true,
    'straight_m'  => (int) round($straight),
    'walking'     => $shape($routes['walking']),
    'driving'     => $shape($routes['driving']),
    'walk_tried'  => $routes['walk_tried'],
    'walk_failed' => $routes['walk_failed'],
    'routing'     => true,
]);
