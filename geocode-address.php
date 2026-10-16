<?php
/**
 * VolunteerOps - Address Geocoding Endpoint
 * Resolves a free-text address to coordinates via Nominatim (OpenStreetMap).
 * GET only, login required, read-only lookup.
 *
 * Three modes, all backwards compatible with the single-result callers in the
 * Action Room (they send only q= and read lat/lng/display_name):
 *   ?q=<text>             best single match (unchanged)
 *   ?q=<text>&limit=5     up to 5 matches in `results` (mission-form.php map picker);
 *                         the first match is still mirrored in lat/lng/display_name
 *   ?lat=<n>&lng=<n>      reverse lookup: the address of a point (map picker fills
 *                         an empty «Τοποθεσία» from it)
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');

if (!function_exists('curl_init')) {
    echo json_encode(['ok' => false, 'error' => t('geocode.curl_unavailable')]);
    exit;
}

/** One GET against Nominatim; decoded JSON, or null with $error set. */
function nominatimGet(string $url, ?string &$error): ?array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; VolunteerOps/' . APP_VERSION . ')',
    ]);
    $response = curl_exec($ch);
    $error    = curl_error($ch);
    curl_close($ch);

    if ($error || empty($response)) {
        return null;
    }
    $decoded = json_decode($response, true);
    return is_array($decoded) ? $decoded : null;
}

// ---- Reverse lookup: coordinates -> address --------------------------------
if (isset($_GET['lat'], $_GET['lng'])) {
    $lat = (float) get('lat');
    $lng = (float) get('lng');
    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
        echo json_encode(['ok' => false, 'error' => t('geocode.address_not_found')]);
        exit;
    }

    $url = 'https://nominatim.openstreetmap.org/reverse?format=jsonv2&zoom=18&addressdetails=1&accept-language=el,en'
         . '&lat=' . rawurlencode((string) $lat) . '&lon=' . rawurlencode((string) $lng);
    $curlError = null;
    $result = nominatimGet($url, $curlError);
    if ($curlError) {
        echo json_encode(['ok' => false, 'error' => t('geocode.search_failed_prefix', ['error' => $curlError])]);
        exit;
    }
    if (!$result || empty($result['display_name'])) {
        echo json_encode(['ok' => false, 'error' => t('geocode.address_not_found')]);
        exit;
    }

    // "Road 12, Village" reads better in a one-line field than Nominatim's
    // full comma chain down to the country and postcode.
    $a = $result['address'] ?? [];
    $street = trim(($a['road'] ?? $a['pedestrian'] ?? $a['path'] ?? '') . ' ' . ($a['house_number'] ?? ''));
    $place  = $a['neighbourhood'] ?? $a['suburb'] ?? $a['village'] ?? $a['town'] ?? $a['city'] ?? $a['municipality'] ?? '';
    $short  = trim(implode(', ', array_filter([$street, $place])));

    echo json_encode([
        'ok'           => true,
        'display_name' => $result['display_name'],
        'short_name'   => $short !== '' ? $short : $result['display_name'],
    ]);
    exit;
}

// ---- Forward lookup: text -> coordinates -----------------------------------
$query = trim((string) get('q'));
if ($query === '') {
    echo json_encode(['ok' => false, 'error' => t('geocode.no_address')]);
    exit;
}

$limit = max(1, min(5, (int) get('limit')));

$url = 'https://nominatim.openstreetmap.org/search?format=json&limit=' . $limit . '&countrycodes=gr&q=' . urlencode($query);
if ($limit > 1) {
    // Only the list mode asks for Greek names and the area's bounding box
    // (so the picker can frame a whole village rather than zoom to one point).
    $url .= '&accept-language=el,en';
}

$curlError = null;
$results = nominatimGet($url, $curlError);
if ($curlError) {
    echo json_encode(['ok' => false, 'error' => t('geocode.search_failed_prefix', ['error' => $curlError])]);
    exit;
}
if (empty($results) || !isset($results[0]['lat'], $results[0]['lon'])) {
    echo json_encode(['ok' => false, 'error' => t('geocode.address_not_found')]);
    exit;
}

$out = [
    'ok'           => true,
    'lat'          => (float) $results[0]['lat'],
    'lng'          => (float) $results[0]['lon'],
    'display_name' => $results[0]['display_name'] ?? $query,
];

if ($limit > 1) {
    $out['results'] = [];
    foreach ($results as $r) {
        if (!isset($r['lat'], $r['lon'])) {
            continue;
        }
        $item = [
            'lat'          => (float) $r['lat'],
            'lng'          => (float) $r['lon'],
            'display_name' => $r['display_name'] ?? $query,
        ];
        // Nominatim order: south, north, west, east
        if (isset($r['boundingbox']) && count($r['boundingbox']) === 4) {
            $item['bbox'] = array_map('floatval', $r['boundingbox']);
        }
        $out['results'][] = $item;
    }
}

echo json_encode($out);
