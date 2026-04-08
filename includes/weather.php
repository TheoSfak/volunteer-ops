<?php
/**
 * Weather forecast integration using OpenWeatherMap free 5-day / 3-hour API.
 *
 * Public entry point: getWeatherForMission(array $mission): ?array
 *
 * Return values:
 *   null                                  – no API key configured, or mission is >1 hr in the past
 *   ['status' => 'too_far',
 *    'available_from' => 'dd/mm/yyyy']    – mission start is beyond the free 5-day window
 *   ['status' => 'no_location']           – no usable location info on the mission
 *   ['status' => 'api_error',
 *    'message'  => '...']                 – network / API failure
 *   ['status' => 'ok', ...]               – valid forecast (see keys below)
 *
 * 'ok' keys:
 *   temp, feels_like  – float, °C
 *   description       – string (Greek, first-letter capitalised)
 *   icon              – OWM icon code, e.g. '10d'
 *   wind_speed        – float, m/s
 *   wind_deg          – int, degrees
 *   humidity          – int, %
 *   condition_id      – int, OWM condition code
 *   forecast_dt       – int, Unix timestamp of the chosen forecast slot
 *   warnings          – string[], Greek warning messages
 *   severity          – 'none' | 'warning' | 'danger'
 */

// ─── Cache TTL (seconds) ─────────────────────────────────────────────────────
define('WEATHER_CACHE_TTL', 3 * 3600);  // 3 hours

// ─── Main entry point ────────────────────────────────────────────────────────

/**
 * Returns weather data for the mission, or null if not applicable.
 */
function getWeatherForMission(array $mission): ?array
{
    $apiKey = getSetting('openweathermap_api_key', '');
    if (empty($apiKey)) {
        return null;
    }

    $startTs = strtotime($mission['start_datetime']);

    // Do not show weather for missions that ended more than 1 hour ago
    if ($startTs < time() - 3600) {
        return null;
    }

    // OWM free tier only provides 5-day (120-hour) forecasts
    if ($startTs > time() + (5 * 24 * 3600)) {
        $availableTs = $startTs - (5 * 24 * 3600);
        return [
            'status'         => 'too_far',
            'available_from' => date('d/m/Y', $availableTs),
        ];
    }

    // Check DB cache (WEATHER_CACHE_TTL)
    $cached = dbFetchOne(
        "SELECT weather_json, fetched_at FROM weather_cache WHERE mission_id = ?",
        [$mission['id']]
    );
    if ($cached) {
        $age = time() - strtotime($cached['fetched_at']);
        if ($age < WEATHER_CACHE_TTL) {
            $data = json_decode($cached['weather_json'], true);
            if (is_array($data) && ($data['status'] ?? '') === 'ok') {
                return $data;
            }
        }
    }

    // Resolve coordinates
    $lat = isset($mission['latitude'])  && $mission['latitude']  !== null ? (float)$mission['latitude']  : null;
    $lon = isset($mission['longitude']) && $mission['longitude'] !== null ? (float)$mission['longitude'] : null;

    if (($lat === null || $lon === null) && !empty($mission['location'])) {
        // Append country hint for better geocoding accuracy with Greek location names
        $locationQuery = trim($mission['location']) . ', Ελλάδα';
        [$lat, $lon] = _owmGeocode($locationQuery, $apiKey);
    }

    $fallbackLocation = false;
    if ($lat === null || $lon === null) {
        // Fall back to Heraklion, Crete when no coordinates are available
        $lat = 35.3387;
        $lon = 25.1442;
        $fallbackLocation = true;
    }

    // Fetch forecast
    $data = _owmFetchForecast($lat, $lon, $startTs, $apiKey);
    if ($data === null) {
        return ['status' => 'api_error', 'message' => 'Αποτυχία ανάκτησης δεδομένων καιρού'];
    }

    // Add warnings + severity
    $parsed  = _owmParseWarnings($data);
    $data    = array_merge($data, $parsed);
    $data['status'] = 'ok';
    if ($fallbackLocation) {
        $data['fallback_location'] = true;
    }

    // Persist to cache (upsert)
    $json = json_encode($data);
    $exists = dbFetchValue(
        "SELECT COUNT(*) FROM weather_cache WHERE mission_id = ?",
        [$mission['id']]
    );
    if ($exists) {
        dbExecute(
            "UPDATE weather_cache SET weather_json = ?, fetched_at = NOW() WHERE mission_id = ?",
            [$json, $mission['id']]
        );
    } else {
        dbInsert(
            "INSERT INTO weather_cache (mission_id, weather_json, fetched_at) VALUES (?, ?, NOW())",
            [$mission['id'], $json]
        );
    }

    return $data;
}

// ─── Internal helpers ────────────────────────────────────────────────────────

/**
 * Geocode a text location via OWM geo/1.0/direct.
 * Returns [lat, lon] or [null, null] on failure.
 */
function _owmGeocode(string $location, string $apiKey): array
{
    $url = 'https://api.openweathermap.org/geo/1.0/direct?q='
         . urlencode($location)
         . '&limit=1&appid='
         . urlencode($apiKey);

    $res = _owmCurlGet($url);
    if ($res === null) {
        return [null, null];
    }

    $results = json_decode($res, true);
    if (empty($results) || !isset($results[0]['lat'], $results[0]['lon'])) {
        return [null, null];
    }

    return [(float)$results[0]['lat'], (float)$results[0]['lon']];
}

/**
 * Fetch the 3-hour forecast slot closest to $targetTs.
 * Returns a normalised array or null on failure.
 */
function _owmFetchForecast(float $lat, float $lon, int $targetTs, string $apiKey): ?array
{
    $url = sprintf(
        'https://api.openweathermap.org/data/2.5/forecast?lat=%s&lon=%s&units=metric&lang=el&appid=%s',
        $lat,
        $lon,
        urlencode($apiKey)
    );

    $res = _owmCurlGet($url);
    if ($res === null) {
        return null;
    }

    $body = json_decode($res, true);
    if (empty($body['list'])) {
        return null;
    }

    // Pick the slot closest to the mission start time
    $best     = null;
    $bestDiff = PHP_INT_MAX;
    foreach ($body['list'] as $slot) {
        $diff = abs($slot['dt'] - $targetTs);
        if ($diff < $bestDiff) {
            $bestDiff = $diff;
            $best     = $slot;
        }
    }

    if ($best === null) {
        return null;
    }

    $conditionId = (int)($best['weather'][0]['id'] ?? 800);
    $desc        = $best['weather'][0]['description'] ?? '';

    // Capitalise first letter (mb-safe)
    if ($desc !== '') {
        $desc = mb_strtoupper(mb_substr($desc, 0, 1)) . mb_substr($desc, 1);
    }

    return [
        'temp'         => round((float)($best['main']['temp']       ?? 0), 1),
        'feels_like'   => round((float)($best['main']['feels_like'] ?? 0), 1),
        'description'  => $desc,
        'icon'         => $best['weather'][0]['icon'] ?? '01d',
        'wind_speed'   => round((float)($best['wind']['speed'] ?? 0), 1),
        'wind_deg'     => (int)($best['wind']['deg'] ?? 0),
        'humidity'     => (int)($best['main']['humidity'] ?? 0),
        'condition_id' => $conditionId,
        'forecast_dt'  => (int)$best['dt'],
    ];
}

/**
 * Derive Greek warning messages and severity from a forecast data array.
 * Returns ['warnings' => string[], 'severity' => 'none'|'warning'|'danger'].
 */
function _owmParseWarnings(array $w): array
{
    $warnings = [];
    $severity = 'none';

    $id   = $w['condition_id'] ?? 800;
    $wind = $w['wind_speed']   ?? 0;
    $temp = $w['temp']         ?? 20;

    // Thunderstorm (2xx) → danger
    if ($id >= 200 && $id < 300) {
        $warnings[] = 'Καταιγίδα αναμένεται κατά τη διάρκεια της αποστολής';
        $severity   = 'danger';

    // Drizzle (3xx) → warning
    } elseif ($id >= 300 && $id < 400) {
        $warnings[] = 'Ψιλόβροχο αναμένεται κατά τη διάρκεια της αποστολής';
        $severity   = _owmMaxSeverity($severity, 'warning');

    // Rain (5xx)
    } elseif ($id >= 500 && $id < 600) {
        if ($id >= 502) {
            $warnings[] = 'Ισχυρή βροχόπτωση αναμένεται κατά τη διάρκεια της αποστολής';
            $severity   = 'danger';
        } else {
            $warnings[] = 'Βροχόπτωση αναμένεται κατά τη διάρκεια της αποστολής';
            $severity   = _owmMaxSeverity($severity, 'warning');
        }

    // Snow (6xx) → warning
    } elseif ($id >= 600 && $id < 700) {
        $warnings[] = 'Χιονόπτωση αναμένεται κατά τη διάρκεια της αποστολής';
        $severity   = _owmMaxSeverity($severity, 'warning');

    // Tornado (781) → danger
    } elseif ($id === 781) {
        $warnings[] = 'ΤΥΦΩΝΑΣ — Εξαιρετικά επικίνδυνες καιρικές συνθήκες';
        $severity   = 'danger';

    // Other atmosphere (7xx: fog, haze, sand, ash …) → warning
    } elseif ($id >= 700 && $id < 800) {
        $warnings[] = 'Μειωμένη ορατότητα αναμένεται κατά τη διάρκεια της αποστολής';
        $severity   = _owmMaxSeverity($severity, 'warning');
    }

    // Wind thresholds
    if ($wind > 17) {
        $warnings[] = 'Ισχυροί άνεμοι (' . $wind . ' m/s) — Απαιτείται ιδιαίτερη προσοχή';
        $severity   = 'danger';
    } elseif ($wind > 10) {
        $warnings[] = 'Δυνατοί άνεμοι (' . $wind . ' m/s)';
        $severity   = _owmMaxSeverity($severity, 'warning');
    }

    // Temperature extremes
    if ($temp > 35) {
        $warnings[] = 'Υψηλή θερμοκρασία (' . $temp . '°C) — Κίνδυνος θερμοπληξίας';
        $severity   = _owmMaxSeverity($severity, 'warning');
    } elseif ($temp < 0) {
        $warnings[] = 'Παγετός (' . $temp . '°C) — Κίνδυνος ολισθηρών επιφανειών';
        $severity   = _owmMaxSeverity($severity, 'warning');
    }

    return ['warnings' => $warnings, 'severity' => $severity];
}

/**
 * Perform a secure GET request to an OWM endpoint.
 * Returns the response body string or null on failure.
 */
function _owmCurlGet(string $url): ?string
{
    if (!function_exists('curl_init')) {
        return null;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT      => 'VolunteerOps/' . APP_VERSION,
        CURLOPT_FOLLOWLOCATION => false,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $httpCode !== 200) {
        return null;
    }

    return $response;
}

/**
 * Returns the more severe of two severity strings.
 */
function _owmMaxSeverity(string $a, string $b): string
{
    static $rank = ['none' => 0, 'warning' => 1, 'danger' => 2];
    return ($rank[$a] ?? 0) >= ($rank[$b] ?? 0) ? $a : $b;
}
