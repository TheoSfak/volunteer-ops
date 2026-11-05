<?php
/**
 * VolunteerOps — how high the ground is.
 *
 * For the live map's measuring tool: the height of a point, and the heights
 * along a line so the page can add up the climb. On a mountain the climb is
 * what decides how long a walk takes, and it is the one thing no router's
 * walking time accounts for.
 *
 * Open-Meteo's elevation service: free, keyless, and built on the Copernicus
 * 90 m terrain model. Probed before building (2026-09-27) against known
 * heights — Ζαρός 349 m (village ~340), Κνωσός 99 (~110), Νίδα 1344 (~1400),
 * the Ψηλορείτης summit 2396 (2456; a 90 m cell averages a peak down) — so
 * the page states heights as approximate. Up to 100 points per request.
 *
 * WHAT LEAVES THIS BUILDING: coordinates, nothing else — the same exposure as
 * the routers in route-distance.php.
 */

if (!defined('VOLUNTEEROPS')) {
    die('Direct access not permitted');
}

/**
 * What one lookup may cost while somebody waits for it. A height is an extra on
 * a card or a label, never the answer to what was asked, and the lookup holds a
 * PHP worker and a database connection for as long as the service takes.
 */
const ELEVATION_TIMEOUT = 3;
const ELEVATION_CONNECT_TIMEOUT = 2;

/**
 * After a failed lookup every request on the site answers "no height" without
 * asking for this long, so a service that does not answer costs one timeout in
 * three minutes, not one for each time somebody moves a pointer.
 */
const ELEVATION_BREAKER_SECONDS = 180;
const ELEVATION_BREAKER_KEY = 'elevation_down_until';

const ELEVATION_URL = 'https://api.open-meteo.com/v1/elevation';

/** The service's own limit per request. */
const ELEVATION_MAX_POINTS = 100;

/**
 * The service to ask. Normally ELEVATION_URL; the tests can replace it for the
 * rest of the request by passing an address, and put the default back with ''.
 */
function elevationEndpoint(?string $override = null): string {
    static $replacement = null;
    if ($override !== null) $replacement = $override !== '' ? $override : null;
    return $replacement ?? ELEVATION_URL;
}

/**
 * Heights in metres for a list of [lat, lng], in the same order — or null when
 * the service could not be asked or did not answer sensibly. All or nothing: a
 * climb added up over a profile with holes in it would be a wrong number
 * stated with confidence.
 */
function elevationLookup(array $points): ?array {
    $points = array_slice(array_values($points), 0, ELEVATION_MAX_POINTS);
    if (!$points || !function_exists('curl_init')) return null;
    // It did not answer a moment ago: nobody waits for it again until the pause is over.
    if (externalBreakerPausedUntil(ELEVATION_BREAKER_KEY) > 0) return null;

    $url = elevationEndpoint() . '?' . http_build_query([
        'latitude'  => implode(',', array_map(fn($p) => sprintf('%.5f', (float) $p[0]), $points)),
        'longitude' => implode(',', array_map(fn($p) => sprintf('%.5f', (float) $p[1]), $points)),
    ]);
    $startedAt = microtime(true);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => ELEVATION_CONNECT_TIMEOUT,
        CURLOPT_TIMEOUT        => ELEVATION_TIMEOUT,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; VolunteerOps/' . APP_VERSION . ')',
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    externalCallLog('elevation', (string) parse_url($url, PHP_URL_HOST), $code, $startedAt, ELEVATION_TIMEOUT, $curlError);

    $heights = $code === 200 ? elevationParse($body === false ? null : $body, count($points)) : null;
    if ($heights !== null) {
        externalBreakerClear(ELEVATION_BREAKER_KEY);
    } elseif ($code === 0 || $code === 429 || $code >= 500 || $code === 200) {
        // No answer, "too many requests", a server error or an answer that is not heights: the service is in
        // trouble. A 4xx of any other kind is about this request, not about the service, and pauses nothing.
        externalBreakerTrip(ELEVATION_BREAKER_KEY, ELEVATION_BREAKER_SECONDS);
    }
    return $heights;
}

/**
 * The service's answer, checked: exactly one number per point asked about.
 * Anything else is refused rather than half used.
 */
function elevationParse(?string $body, int $expected): ?array {
    if ($body === null || $body === '') return null;
    $data = json_decode($body, true);
    $list = is_array($data) ? ($data['elevation'] ?? null) : null;
    if (!is_array($list) || count($list) !== $expected) return null;
    $out = [];
    foreach ($list as $value) {
        if (!is_numeric($value)) return null;
        $out[] = (int) round((float) $value);
    }
    return $out;
}
