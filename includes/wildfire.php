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
 *   fallback_location – bool, true when the mission had no coordinates and
 *                        this used the Heraklion default instead — same
 *                        caveat shape as includes/weather.php
 *
 * Detections are satellite passes, not confirmed fires — every consumer of
 * this data must keep that caveat visible, never present it as a live fire
 * alert (see the Action Room fire-layer popup for the exact wording).
 */

// ─── Cache TTL (seconds) — much shorter than weather's 3h, matching how
// often a new satellite pass can actually change this data ─────────────────
define('FIRE_CACHE_TTL', 15 * 60);

// ─── Bounding box half-width in degrees around the mission's coordinates —
// ~0.5° is roughly 100km square, a static constant for v1 ──────────────────
define('FIRE_BBOX_DEGREES', 0.5);

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

    // Resolve coordinates — same fallback idiom as includes/weather.php:
    // mission's own lat/lng, else Heraklion, flagged so the UI can say so.
    $lat = isset($mission['latitude'])  && $mission['latitude']  !== null ? (float)$mission['latitude']  : null;
    $lon = isset($mission['longitude']) && $mission['longitude'] !== null ? (float)$mission['longitude'] : null;

    $fallbackLocation = false;
    if ($lat === null || $lon === null) {
        $lat = 35.3387;
        $lon = 25.1442;
        $fallbackLocation = true;
    }

    $bbox = sprintf(
        '%.4f,%.4f,%.4f,%.4f',
        $lon - FIRE_BBOX_DEGREES,
        $lat - FIRE_BBOX_DEGREES,
        $lon + FIRE_BBOX_DEGREES,
        $lat + FIRE_BBOX_DEGREES
    );

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
    if ($fallbackLocation) {
        $data['fallback_location'] = true;
    }

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

// ─── Internal helpers ────────────────────────────────────────────────────────

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
