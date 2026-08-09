<?php
/**
 * NASA FIRMS (Fire Information for Resource Management System) integration —
 * near-real-time satellite wildfire hotspot detections for the Action Room
 * map overlay.
 *
 * Public entry point: getFireHotspotsForMission(array $mission): array
 *
 * Return values:
 *   ['status' => 'no_key']                 – no API key configured
 *   ['status' => 'api_error',
 *    'message'  => '...']                  – every source call failed
 *   ['status' => 'ok', ...]                – hotspots (possibly empty array)
 *
 * 'ok' keys:
 *   hotspots          – array of:
 *     lat, lng          – float
 *     confidence        – 'low' | 'nominal' | 'high' (VIIRS NRT convention)
 *     brightness        – float, Kelvin (bright_ti4)
 *     frp               – float|null, Fire Radiative Power in MW
 *     acq_date          – 'YYYY-MM-DD'
 *     acq_time          – 'HHMM', UTC
 *     satellite         – string, e.g. 'N', '1', '2' (NOAA-20/21 use 1/2)
 *     instrument        – string, e.g. 'VIIRS'
 *     daynight          – 'D' | 'N'
 *
 * Detections are satellite passes, not confirmed fires — every consumer of
 * this data must keep that caveat visible, never present it as a live fire
 * alert (see the Action Room fire-layer popup for the exact wording).
 */

// ─── Cache TTL (seconds) — much shorter than weather's 3h, matching how
// often a new satellite pass can actually change this data ─────────────────
define('FIRE_CACHE_TTL', 15 * 60);

// ─── Fixed all-Greece bounding box (west,south,east,north) — deliberately
// NOT scoped to the individual mission's own coordinates (that was v1's
// design; changed same-day after the user pointed out a mission-radius
// query misses fires anywhere else in the country, which is exactly the
// situational awareness this feature is for). Covers the Ionian islands
// through the Dodecanese and Crete through the Evros border, with margin.
define('FIRE_BBOX_GREECE', '19.0,34.5,29.7,41.8');

// Combining three VIIRS NRT sources (SNPP + both NOAA JPSS satellites) for
// better coverage than a single satellite — each is a separate API call,
// results are merged. MODIS deliberately left out: coarser 1km resolution,
// VIIRS's 375m is a better fit for spotting smaller fires near a mission.
define('FIRE_SOURCES', ['VIIRS_SNPP_NRT', 'VIIRS_NOAA20_NRT', 'VIIRS_NOAA21_NRT']);

// ─── Main entry point ────────────────────────────────────────────────────────

function getFireHotspotsForMission(array $mission): array
{
    $apiKey = getSetting('nasa_firms_api_key', '');
    if (empty($apiKey)) {
        return ['status' => 'no_key'];
    }

    // Check DB cache (FIRE_CACHE_TTL)
    $cached = dbFetchOne(
        "SELECT hotspots_json, fetched_at FROM fire_hotspot_cache WHERE mission_id = ?",
        [$mission['id']]
    );
    if ($cached) {
        $age = time() - strtotime($cached['fetched_at']);
        if ($age < FIRE_CACHE_TTL) {
            $data = json_decode($cached['hotspots_json'], true);
            if (is_array($data) && ($data['status'] ?? '') === 'ok') {
                return $data;
            }
        }
    }

    $bbox = FIRE_BBOX_GREECE;

    $hotspots   = [];
    $lastError  = '';
    $anySucceeded = false;

    foreach (FIRE_SOURCES as $source) {
        $errorMessage = '';
        $rows = _firmsFetchArea($apiKey, $source, $bbox, $errorMessage);
        if ($rows === null) {
            $lastError = $errorMessage;
            continue;
        }
        $anySucceeded = true;
        foreach ($rows as $row) {
            $hotspots[] = [
                'lat'         => (float)($row['latitude']  ?? 0),
                'lng'         => (float)($row['longitude'] ?? 0),
                'confidence'  => strtolower((string)($row['confidence'] ?? 'nominal')),
                'brightness'  => isset($row['bright_ti4']) ? round((float)$row['bright_ti4'], 1) : null,
                'frp'         => isset($row['frp']) && $row['frp'] !== '' ? round((float)$row['frp'], 1) : null,
                'acq_date'    => $row['acq_date'] ?? '',
                'acq_time'    => $row['acq_time'] ?? '',
                'satellite'   => $row['satellite']  ?? '',
                'instrument'  => $row['instrument'] ?? 'VIIRS',
                'daynight'    => $row['daynight']   ?? '',
            ];
        }
    }

    if (!$anySucceeded) {
        return ['status' => 'api_error', 'message' => $lastError ?: 'Αποτυχία ανάκτησης δεδομένων NASA FIRMS'];
    }

    $data = [
        'status'   => 'ok',
        'hotspots' => $hotspots,
    ];

    // Persist to cache (upsert) — same shape as includes/weather.php
    $json = json_encode($data);
    $exists = dbFetchValue(
        "SELECT COUNT(*) FROM fire_hotspot_cache WHERE mission_id = ?",
        [$mission['id']]
    );
    if ($exists) {
        dbExecute(
            "UPDATE fire_hotspot_cache SET hotspots_json = ?, fetched_at = NOW() WHERE mission_id = ?",
            [$json, $mission['id']]
        );
    } else {
        dbInsert(
            "INSERT INTO fire_hotspot_cache (mission_id, hotspots_json, fetched_at) VALUES (?, ?, NOW())",
            [$mission['id'], $json]
        );
    }

    return $data;
}

/**
 * Human-readable "Fire Xkm <direction> from <place>" location for one
 * hotspot popup — deliberately NOT precomputed for every hotspot on every
 * cache refresh (would mean dozens of sequential Nominatim calls against
 * their fair-use-policy free public instance every 15 minutes); called
 * on-demand, once, only when a marker's popup is actually opened, same
 * lazy-fetch shape as this app's dispatch/sector popup action buttons.
 *
 * Returns ['place' => string, 'distance_km' => float, 'direction' => 'n'|'ne'|
 * 'e'|'se'|'s'|'sw'|'w'|'nw'] or null if no place could be resolved.
 */
function reverseGeocodeFireLocation(float $lat, float $lng): ?array
{
    if (!function_exists('curl_init')) {
        return null;
    }

    // zoom=14 (settlement level) — low enough to reliably hit a genuinely
    // separate, named place with its own center point (so the distance below
    // isn't just ~0), high enough to usually land a real village/hamlet name
    // in nominative case rather than only the broader administrative-unit
    // polygon (see the field-priority comment below for why that matters).
    $url = sprintf(
        'https://nominatim.openstreetmap.org/reverse?format=json&lat=%s&lon=%s&zoom=14&accept-language=el',
        $lat,
        $lng
    );

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        // Same descriptive User-Agent as geocode-address.php's existing
        // Nominatim caller — required by their usage policy.
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; VolunteerOps/' . APP_VERSION . ')',
    ]);
    $response  = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError || empty($response)) {
        return null;
    }

    $data = json_decode($response, true);
    if (!is_array($data) || !isset($data['lat'], $data['lon'])) {
        return null;
    }

    $address = $data['address'] ?? [];
    // Prefer an actual named settlement (nominative case in Greek OSM
    // tagging, e.g. "Άνω Σουσάκι") over the broader administrative-unit
    // fields — Greek OSM tags those as "Δημοτική Ενότητα X" / "Κοινότητα X"
    // / "Δήμος X" ("Municipal Unit of X" / "Community of X" / "Municipality
    // of X"), which are grammatically genitive constructions, not a place
    // name on their own. Stripped as a fallback below when no simple
    // settlement name exists nearby (common in remote/forested areas).
    $placeName = $address['village'] ?? $address['town'] ?? $address['hamlet']
        ?? $address['suburb'] ?? null;
    if (!$placeName) {
        $adminRaw = $address['city_district'] ?? $address['city'] ?? $address['municipality'] ?? null;
        if (!$adminRaw) {
            return null;
        }
        $placeName = trim(str_replace(['Δημοτική Ενότητα', 'Κοινότητα', 'Δήμος'], '', $adminRaw));
    }
    // "Περιφερειακή Ενότητα" (Regional Unit) is the usual county-level tag;
    // Athens/Thessaloniki's own metro areas use "Μητροπολιτική Ενότητα"
    // (Metropolitan Unit) instead — both are the same "of <name>" shape.
    $regionRaw = $address['county'] ?? $address['state'] ?? '';
    $region = trim(str_replace(['Περιφερειακή Ενότητα', 'Μητροπολιτική Ενότητα'], '', $regionRaw));
    $place  = trim($placeName . ' ' . $region);

    $placeLat = (float) $data['lat'];
    $placeLng = (float) $data['lon'];

    return [
        'place'       => $place,
        'distance_km' => round(_haversineKm($placeLat, $placeLng, $lat, $lng), 1),
        'direction'   => _bearingToDirectionKey(_initialBearingDeg($placeLat, $placeLng, $lat, $lng)),
    ];
}

// ─── Internal helpers ────────────────────────────────────────────────────────

/**
 * Great-circle distance in km between two lat/lng points (haversine).
 */
function _haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $earthRadiusKm = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2
        + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    return $earthRadiusKm * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

/**
 * Initial compass bearing (0-360°, 0=North) travelling from point 1 to
 * point 2.
 */
function _initialBearingDeg(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $lat1r = deg2rad($lat1);
    $lat2r = deg2rad($lat2);
    $dLon  = deg2rad($lon2 - $lon1);
    $y = sin($dLon) * cos($lat2r);
    $x = cos($lat1r) * sin($lat2r) - sin($lat1r) * cos($lat2r) * cos($dLon);
    return fmod(rad2deg(atan2($y, $x)) + 360, 360);
}

/**
 * Maps a bearing in degrees to one of 8 direction-key suffixes, matching
 * the new fires.direction_* translation keys in includes/lang/war-room.php.
 */
function _bearingToDirectionKey(float $bearingDeg): string
{
    $keys = ['n', 'ne', 'e', 'se', 's', 'sw', 'w', 'nw'];
    $index = (int) round($bearingDeg / 45) % 8;
    return $keys[$index];
}

/**
 * Fetch and parse one FIRMS area/csv source for the given bounding box
 * (day_range=1 — freshest pass only). Returns an array of associative rows
 * keyed by the CSV's own header (dynamic — FIRMS' exact column set has
 * varied slightly between products), or null on failure with
 * $errorMessage set by reference.
 *
 * FIRMS returns HTTP 200 with a plain-text error body for a bad/expired
 * MAP_KEY (no 401 like OpenWeatherMap) — detected here by checking the
 * first line actually looks like the expected CSV header, not by status
 * code.
 */
function _firmsFetchArea(string $apiKey, string $source, string $bbox, ?string &$errorMessage = null): ?array
{
    $url = sprintf(
        'https://firms.modaps.eosdis.nasa.gov/api/area/csv/%s/%s/%s/1',
        urlencode($apiKey),
        urlencode($source),
        $bbox
    );

    $httpCode  = 0;
    $curlError = '';
    $res = _firmsCurlGet($url, $httpCode, $curlError);
    if ($res === null) {
        $errorMessage = $curlError ? 'Σφάλμα δικτύου: ' . $curlError : 'Αποτυχία σύνδεσης με NASA FIRMS (HTTP ' . $httpCode . ')';
        return null;
    }

    $lines = preg_split('/\r\n|\r|\n/', trim($res));
    if (empty($lines) || count($lines) < 1) {
        $errorMessage = 'Κενή απόκριση από NASA FIRMS';
        return null;
    }

    // Explicit $escape (PHP 8.4 deprecates the implicit default, and on this
    // host the deprecation notice prints straight into the response body,
    // corrupting the JSON output for every caller — settings.php's test
    // button and the live Action Room poll alike).
    $header = str_getcsv($lines[0], ',', '"', '\\');
    $header = array_map('strtolower', $header);
    if (!in_array('latitude', $header, true) || !in_array('longitude', $header, true)) {
        // Not a valid CSV header — almost always an invalid/expired MAP_KEY
        // or a malformed request, whose body is a short plain-text message.
        $errorMessage = 'Μη έγκυρο MAP_KEY ή σφάλμα NASA FIRMS: ' . mb_substr($lines[0], 0, 120);
        return null;
    }

    $rows = [];
    for ($i = 1; $i < count($lines); $i++) {
        if (trim($lines[$i]) === '') {
            continue;
        }
        $fields = str_getcsv($lines[$i], ',', '"', '\\');
        if (count($fields) !== count($header)) {
            continue;
        }
        $rows[] = array_combine($header, $fields);
    }

    return $rows;
}

/**
 * Perform a secure GET request to a FIRMS endpoint.
 * Returns the response body string or null on failure.
 * Sets $httpCode and $curlError by reference for diagnostics.
 */
function _firmsCurlGet(string $url, ?int &$httpCode = null, ?string &$curlError = null): ?string
{
    if (!function_exists('curl_init')) {
        $curlError = 'curl not available';
        return null;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT      => 'VolunteerOps/' . APP_VERSION,
        CURLOPT_FOLLOWLOCATION => false,
    ]);

    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $httpCode !== 200) {
        return null;
    }

    return $response;
}
