<?php
/**
 * VolunteerOps - OpenStreetMap prewarm (run daily with cron_daily.php)
 *
 * Fills the OpenStreetMap layer's cache (osm_feature_cache) around every open
 * mission that has a location, so the Action Room map is already full when
 * somebody opens it. The first look at a stretch of ground otherwise waits for
 * one Overpass query per row of tiles — seconds each on the public server, a
 * minute or more for a full screen — and that wait lands on a coordinator in the
 * middle of an operation. A tile is kept 30 days, so after the first run this
 * only refreshes what has aged out.
 *
 * Polite by construction: one query at a time, a pause between them, and at most
 * OSM_PREWARM_MAX_QUERIES per run (a mission not finished today is finished
 * tomorrow, nearest tiles first). Dirt roads are not warmed: few people tick
 * them and they are the heaviest query.
 *
 * What leaves this building: bounding boxes, nothing else (includes/osm.php).
 */

// CLI or manual admin trigger only
if (php_sapi_name() !== 'cli' && !defined('CRON_MANUAL_RUN')) {
    die('This script can only be run from command line.');
}

if (!defined('VOLUNTEEROPS')) {
    require_once __DIR__ . '/bootstrap.php';
}
require_once __DIR__ . '/includes/osm.php';

/** How far from a mission's point to warm, in degrees (0.12 is about 13 km). */
const OSM_PREWARM_RADIUS = 0.12;
/** Overpass queries one run may make, and the pause between them in seconds. */
const OSM_PREWARM_MAX_QUERIES = 20;
const OSM_PREWARM_PAUSE = 3;

if (getSetting('osm_layer_enabled', '1') !== '1') {
    echo "The OpenStreetMap layer is switched off.\n";
    return;
}
if (!function_exists('curl_init')) {
    echo "cURL is not available on this server: nothing to warm.\n";
    return;
}
@set_time_limit(0);

$missions = dbFetchAll(
    "SELECT id, latitude, longitude FROM missions
      WHERE status = ? AND deleted_at IS NULL AND latitude IS NOT NULL AND longitude IS NOT NULL
      ORDER BY id DESC",
    [STATUS_OPEN]
);
if (!$missions) {
    echo "No open mission with a location.\n";
    return;
}

// Every tile within the radius of any mission, each once, nearest to a mission
// first; then cut into rows of OSM_CHUNK_TILES, the size of one query.
$tiles = [];
foreach ($missions as $mission) {
    $lat = (float) $mission['latitude'];
    $lng = (float) $mission['longitude'];
    $r0 = (int) floor(($lat - OSM_PREWARM_RADIUS) * OSM_TILE_DIVISOR);
    $r1 = (int) floor(($lat + OSM_PREWARM_RADIUS) * OSM_TILE_DIVISOR);
    $c0 = (int) floor(($lng - OSM_PREWARM_RADIUS) * OSM_TILE_DIVISOR);
    $c1 = (int) floor(($lng + OSM_PREWARM_RADIUS) * OSM_TILE_DIVISOR);
    for ($r = $r0; $r <= $r1; $r++) {
        for ($c = $c0; $c <= $c1; $c++) {
            $key = $r . '_' . $c;
            $away = pow(($r + 0.5) / OSM_TILE_DIVISOR - $lat, 2) + pow(($c + 0.5) / OSM_TILE_DIVISOR - $lng, 2);
            if (!isset($tiles[$key]) || $away < $tiles[$key][5]) {
                $tiles[$key] = [$key, $r / OSM_TILE_DIVISOR, $c / OSM_TILE_DIVISOR, ($r + 1) / OSM_TILE_DIVISOR, ($c + 1) / OSM_TILE_DIVISOR, $away, $r, $c];
            }
        }
    }
}
uasort($tiles, fn($a, $b) => $a[5] <=> $b[5]);

$rows = [];
foreach ($tiles as $tile) $rows[$tile[6]][] = $tile;
$chunks = [];
foreach ($rows as $rowTiles) {
    usort($rowTiles, fn($a, $b) => $a[7] <=> $b[7]);
    foreach (array_chunk($rowTiles, OSM_CHUNK_TILES) as $chunk) $chunks[] = [min(array_column($chunk, 5)), $chunk];
}
usort($chunks, fn($a, $b) => $a[0] <=> $b[0]); // the chunk nearest a mission first

$queries = 0;
$filled = 0;
$failed = 0;
$already = 0;
foreach ($chunks as [, $chunk]) {
    if ($queries >= OSM_PREWARM_MAX_QUERIES) break;
    $budget = 1;
    $why = null;
    [, $states] = osmChunkBundle(array_map(fn($t) => array_slice($t, 0, 5), $chunk), 'core', $budget, $why);
    if ($budget === 1) { // nothing needed fetching: already cached
        $already += count($chunk);
        continue;
    }
    $queries++;
    foreach ($states as $state) {
        if ($state === 'fresh') $filled++;
        else $failed++;
    }
    if (in_array('failed', $states, true) || in_array('stale', $states, true)) {
        echo "  chunk {$chunk[0][0]}…: " . ($why ?? 'no answer') . "\n";
    }
    sleep(OSM_PREWARM_PAUSE);
}

echo sprintf(
    "OpenStreetMap prewarm: %d open mission(s), %d tiles in range; %d query/queries made, %d tile(s) filled, %d failed, %d already cached.\n",
    count($missions), count($tiles), $queries, $filled, $failed, $already
);
if ($queries >= OSM_PREWARM_MAX_QUERIES && $queries < count($chunks)) {
    echo "  More tiles remain; the next run continues.\n";
}
