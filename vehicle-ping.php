<?php
/**
 * VolunteerOps - Vehicle GPS Ping Endpoint (bearer-token auth)
 * Fleet management, M4 backend: records one GPS fix for a vehicle. Deliberately
 * generic about who calls it - the ingestion method (a driver's phone while the
 * vehicle is checked out, vs. a dedicated LoRa/SIM tracker riding in the vehicle)
 * is undecided (see includes/fleet-functions.php), so auth is a per-vehicle
 * bearer token (vehicle_ping_tokens, issued from vehicle-view.php) rather than
 * a user session - a hardware tracker has no user to log in as. Same bearer
 * shape as mobile-ping-location.php, but keyed on vehicle_id, never user_id.
 * AJAX POST only.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/fleet-functions.php';

header('Content-Type: application/json');

if (!isPost()) {
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if (!preg_match('/^Bearer\s+([A-Za-z0-9]+)$/', trim($authHeader), $matches)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Missing or malformed bearer token']);
    exit;
}

$vehicleId = authenticateVehiclePingToken($matches[1]);
if ($vehicleId === null) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Invalid or revoked token']);
    exit;
}

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (stripos($contentType, 'application/json') !== false) {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) {
        $body = [];
    }
} else {
    $body = $_POST;
}

$lat = isset($body['lat']) && is_numeric($body['lat']) ? (float) $body['lat'] : null;
$lng = isset($body['lng']) && is_numeric($body['lng']) ? (float) $body['lng'] : null;
if ($lat === null || $lng === null || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
    echo json_encode(['ok' => false, 'error' => 'Invalid coordinates']);
    exit;
}

$accuracy = isset($body['accuracy']) && is_numeric($body['accuracy']) ? min((float) $body['accuracy'], 5000) : null;
$speed = isset($body['speed']) && is_numeric($body['speed']) ? (float) $body['speed'] : null;
$heading = (isset($body['heading']) && is_numeric($body['heading']) && (float) $body['heading'] >= 0 && (float) $body['heading'] < 360)
    ? (float) $body['heading']
    : null;
$source = isset($body['source']) && is_string($body['source']) ? $body['source'] : null;

$pingId = addVehiclePing($vehicleId, $lat, $lng, $accuracy, $speed, $heading, $source);
echo json_encode(['ok' => true, 'ping_id' => $pingId]);
