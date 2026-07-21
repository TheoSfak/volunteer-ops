<?php
/**
 * VolunteerOps - Address Geocoding Endpoint
 * Resolves a free-text address to coordinates via Nominatim (OpenStreetMap).
 * GET only, login required, read-only lookup.
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');

$query = trim((string) get('q'));
if ($query === '') {
    echo json_encode(['ok' => false, 'error' => t('geocode.no_address')]);
    exit;
}

if (!function_exists('curl_init')) {
    echo json_encode(['ok' => false, 'error' => t('geocode.curl_unavailable')]);
    exit;
}

$url = 'https://nominatim.openstreetmap.org/search?format=json&limit=1&countrycodes=gr&q=' . urlencode($query);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 8,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; VolunteerOps/' . APP_VERSION . ')',
]);
$response  = curl_exec($ch);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError || empty($response)) {
    echo json_encode(['ok' => false, 'error' => t('geocode.search_failed_prefix', ['error' => $curlError])]);
    exit;
}

$results = json_decode($response, true);
if (empty($results) || !isset($results[0]['lat'], $results[0]['lon'])) {
    echo json_encode(['ok' => false, 'error' => t('geocode.address_not_found')]);
    exit;
}

echo json_encode([
    'ok'           => true,
    'lat'          => (float) $results[0]['lat'],
    'lng'          => (float) $results[0]['lon'],
    'display_name' => $results[0]['display_name'] ?? $query,
]);
