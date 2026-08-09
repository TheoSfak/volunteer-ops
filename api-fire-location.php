<?php
/**
 * AJAX endpoint — resolve one fire hotspot's lat/lng into a human-readable
 * "X km <direction> from <place>" label via reverse geocoding. GET only,
 * login required, called on-demand when a fire marker's popup opens (see
 * war-room.php's fireLayer.on('popupopen', ...)) — never precomputed for
 * every hotspot, to stay well within Nominatim's free-tier fair-use policy.
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/wildfire.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');

$lat = (float) get('lat');
$lng = (float) get('lng');

if ($lat === 0.0 && $lng === 0.0) {
    echo json_encode(['ok' => false]);
    exit;
}

$result = reverseGeocodeFireLocation($lat, $lng);
if ($result === null) {
    echo json_encode(['ok' => false]);
    exit;
}

echo json_encode(array_merge(['ok' => true], $result));
