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

const ELEVATION_TIMEOUT = 5;

/** The service's own limit per request. */
const ELEVATION_MAX_POINTS = 100;

/**
 * Heights in metres for a list of [lat, lng], in the same order — or null when
 * the service could not be asked or did not answer sensibly. All or nothing: a
 * climb added up over a profile with holes in it would be a wrong number
 * stated with confidence.
 */
function elevationLookup(array $points): ?array {
    $points = array_slice(array_values($points), 0, ELEVATION_MAX_POINTS);
    if (!$points || !function_exists('curl_init')) return null;

    $url = 'https://api.open-meteo.com/v1/elevation?' . http_build_query([
        'latitude'  => implode(',', array_map(fn($p) => sprintf('%.5f', (float) $p[0]), $points)),
        'longitude' => implode(',', array_map(fn($p) => sprintf('%.5f', (float) $p[1]), $points)),
    ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => ELEVATION_TIMEOUT,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; VolunteerOps/' . APP_VERSION . ')',
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200) {
        if ($code !== 0) error_log('[elevation] open-meteo HTTP ' . $code);
        return null;
    }
    return elevationParse($body === false ? null : $body, count($points));
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
