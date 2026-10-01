<?php
/**
 * VolunteerOps — all of Crete's OpenStreetMap points, downloaded once.
 *
 * The OpenStreetMap layer asks Overpass for a tile only when somebody looks at
 * it, and the public server takes seconds for each, minutes for a screen. This
 * fills osm_feature_cache for the whole island ahead of time, on purpose and
 * from the Settings page (osm-bulk.php), and refreshes it the same way whenever
 * an administrator wants the newly mapped points: the cache then answers every
 * view at once and Overpass is not in the picture.
 *
 * The island is OSM_BULK_LAND (its land tiles) in the same 0.05° tiles the layer uses, and
 * the tiles into rows of OSM_CHUNK_TILES — one Overpass query each, about 130 in
 * all, one at a time (the server asks for no more), so under an hour, once.
 * Points, paths and cliffs only: dirt roads are the heaviest data by far and
 * stay on demand.
 *
 * A run is a "job": osmBulkStart() stamps its beginning, and a chunk is done
 * when all its tiles hold rows fetched since. Nothing is deleted on the way: the
 * old rows keep answering until a new one replaces them, and a chunk Overpass
 * did not answer keeps its old data and is simply asked for again.
 *
 * What leaves this building: bounding boxes, nothing else (includes/osm.php).
 */

if (!defined('VOLUNTEEROPS')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/osm.php';

/**
 * The tiles of Crete and its islets that touch land, as row => [[first column,
 * last column], …] with row = floor(lat * 20) and column = floor(lng * 20).
 *
 * Crete is a thin island in a wide box, so only 458 of the 1,200 tiles of its
 * bounding box (lat 34.78–35.72, lng 23.45–26.40) are land, and a query for sea
 * is a query wasted on a slow public server. Made once, on 2026-10-01, from
 * OSM's coastline — way["natural"="coastline"] in that box (1,765 ways, 77,796
 * segments): a tile counts if a coastline segment crosses it, or if its centre
 * is inside the coast by even-odd ray casting. Gavdos, Chrysi, Dia and the other
 * islets are in it. Ground outside the mask still loads on demand, as ever.
 */
const OSM_BULK_LAND = [
    696 => [[480, 482]],
    697 => [[480, 482], [513, 514]],
    698 => [[479, 480], [494, 504], [522, 523]],
    699 => [[494, 511], [515, 516], [521, 522]],
    700 => [[491, 492], [494, 524]],
    701 => [[491, 525]],
    702 => [[489, 525]],
    703 => [[480, 514], [516, 526]],
    704 => [[471, 515], [519, 526]],
    705 => [[470, 515], [524, 527]],
    706 => [[470, 515], [523, 523], [525, 526]],
    707 => [[470, 500], [502, 503], [523, 523]],
    708 => [[470, 485], [492, 500], [503, 505]],
    709 => [[471, 485], [503, 504]],
    710 => [[471, 484]],
    711 => [[469, 469], [471, 472], [474, 475], [481, 483], [511, 511]],
    712 => [[471, 472], [474, 475], [482, 482], [511, 511]],
    713 => [[474, 475]],
];

/** Settings keys: when the current job began, and when one last finished. */
const OSM_BULK_STARTED = 'osm_bulk_started_at';
const OSM_BULK_COMPLETED = 'osm_bulk_completed_at';

/**
 * The rows of tiles of the area, as chunks: [['index' => n, 'tiles' => [[tileKey,
 * south, west, north, east], …]], …], in order from the south-west.
 */
function osmBulkChunks(): array {
    static $chunks = null;
    if ($chunks !== null) return $chunks;
    $chunks = [];
    foreach (OSM_BULK_LAND as $r => $ranges) {
        foreach ($ranges as [$c0, $c1]) {
            // A run of land tiles in one row, cut into chunks of OSM_CHUNK_TILES.
            $run = [];
            for ($c = $c0; $c <= $c1; $c++) {
                $run[] = [$r . '_' . $c, $r / OSM_TILE_DIVISOR, $c / OSM_TILE_DIVISOR, ($r + 1) / OSM_TILE_DIVISOR, ($c + 1) / OSM_TILE_DIVISOR];
            }
            foreach (array_chunk($run, OSM_CHUNK_TILES) as $tiles) {
                $chunks[] = ['index' => count($chunks), 'tiles' => $tiles];
            }
        }
    }
    return $chunks;
}

/** The database's own clock in seconds, so ages and the job's start agree. */
function osmBulkNow(): int {
    return (int) dbFetchValue("SELECT UNIX_TIMESTAMP()");
}

function osmBulkGet(string $key): ?int {
    $value = dbFetchValue("SELECT setting_value FROM settings WHERE setting_key = ?", [$key]);
    return ($value === false || $value === null || $value === '') ? null : (int) $value;
}

function osmBulkSet(string $key, int $value): void {
    dbExecute(
        "INSERT INTO settings (setting_key, setting_value, created_at, updated_at) VALUES (?, ?, NOW(), NOW())
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()",
        [$key, (string) $value]
    );
}

/** [tileKey => [group => age in seconds]] for every cached row, without reading a payload. */
function osmBulkAges(): array {
    $ages = [];
    $rows = dbFetchAll("SELECT tile_key, layer_group, TIMESTAMPDIFF(SECOND, fetched_at, NOW()) AS age FROM osm_feature_cache");
    foreach ($rows as $row) $ages[$row['tile_key']][$row['layer_group']] = (int) $row['age'];
    return $ages;
}

/**
 * Whether a chunk still has to be fetched for a job begun $maxAge seconds ago:
 * any of its tiles lacks a row of the core bundle, or holds one older than that.
 * (The "try again later" row of a failed fetch is aged on purpose, so a chunk
 * Overpass did not answer counts as still to do.)
 */
function osmBulkChunkNeeds(array $chunk, array $ages, int $maxAge): bool {
    foreach ($chunk['tiles'] as $tile) {
        foreach (OSM_BUNDLES['core'] as $group) {
            $age = $ages[$tile[0]][$group] ?? null;
            if ($age === null || $age > $maxAge) return true;
        }
    }
    return false;
}

/** The first chunk at or after $after that still has to be fetched, or null. */
function osmBulkNextIndex(array $chunks, array $ages, int $maxAge, int $after): ?int {
    foreach ($chunks as $chunk) {
        if ($chunk['index'] >= $after && osmBulkChunkNeeds($chunk, $ages, $maxAge)) return $chunk['index'];
    }
    return null;
}

/** How many chunks still have to be fetched. */
function osmBulkRemaining(array $chunks, array $ages, int $maxAge): int {
    $n = 0;
    foreach ($chunks as $chunk) {
        if (osmBulkChunkNeeds($chunk, $ages, $maxAge)) $n++;
    }
    return $n;
}

/**
 * Tiles of the area that hold a real answer now (a row of every core group, none
 * of them the retry marker of a failed fetch), whatever job put it there.
 */
function osmBulkStoredTiles(array $chunks, array $ages, array $counts = []): int {
    $stored = 0;
    foreach ($chunks as $chunk) {
        foreach ($chunk['tiles'] as $tile) {
            $real = true;
            $present = true;
            foreach (OSM_BUNDLES['core'] as $group) {
                $age = $ages[$tile[0]][$group] ?? null;
                if ($age === null) { $present = false; $real = false; break; }
                if ($age >= OSM_CACHE_TTL - OSM_RETRY_AFTER) $real = false;
            }
            // A failed refresh ages the rows of a tile into the retry window but leaves
            // what they held: a tile with features in it is still stored.
            if ($present && !$real && ($counts[$tile[0]] ?? 0) > 0) $real = true;
            if ($real) $stored++;
        }
    }
    return $stored;
}

/** [tileKey => features held in all the tile's rows], for telling a stored tile from an empty marker. */
function osmBulkCounts(): array {
    $counts = [];
    foreach (dbFetchAll("SELECT tile_key, SUM(element_count) AS n FROM osm_feature_cache GROUP BY tile_key") as $row) {
        $counts[$row['tile_key']] = (int) $row['n'];
    }
    return $counts;
}

/** What the Settings card shows. */
function osmBulkStatus(): array {
    $chunks = osmBulkChunks();
    $ages = osmBulkAges();
    $started = osmBulkGet(OSM_BULK_STARTED);
    $now = osmBulkNow();
    $tiles = 0;
    foreach ($chunks as $chunk) $tiles += count($chunk['tiles']);
    return [
        'total_chunks'   => count($chunks),
        'total_tiles'    => $tiles,
        'stored_tiles'   => osmBulkStoredTiles($chunks, $ages, osmBulkCounts()),
        'job_started'    => $started,
        'job_remaining'  => $started !== null ? osmBulkRemaining($chunks, $ages, max(0, $now - $started)) : null,
        'last_completed' => osmBulkGet(OSM_BULK_COMPLETED),
    ];
}

/** Begins a job: from now on a chunk is done when it holds rows newer than this. */
function osmBulkStart(): array {
    osmBulkSet(OSM_BULK_STARTED, osmBulkNow());
    return osmBulkStatus();
}

/**
 * Fetches the next chunk that still needs it, at or after chunk $after: one
 * Overpass query. 'failed' is set when Overpass did not answer for it; the page
 * carries on with the next one and can come back for the failed ones at the end.
 * 'finished' means nothing is left at or after $after, and 'remaining' how many
 * chunks overall still need fetching (failed ones before $after included).
 */
function osmBulkStep(int $after): array {
    $started = osmBulkGet(OSM_BULK_STARTED);
    if ($started === null) return ['ok' => false, 'error' => 'No job has been started.'];

    $chunks = osmBulkChunks();
    $ages = osmBulkAges();
    $now = osmBulkNow();
    $maxAge = max(0, $now - $started);
    $remaining = osmBulkRemaining($chunks, $ages, $maxAge);
    $next = osmBulkNextIndex($chunks, $ages, $maxAge, max(0, $after));

    if ($next === null) {
        if ($remaining === 0) osmBulkSet(OSM_BULK_COMPLETED, $now);
        return ['ok' => true, 'finished' => true, 'remaining' => $remaining, 'total' => count($chunks)];
    }

    $budget = 1;
    $why = null;
    [, $states] = osmChunkBundle($chunks[$next]['tiles'], 'core', $budget, $why, $maxAge);
    $failed = count(array_filter($states, fn($s) => $s !== 'fresh')) > 0;
    return [
        'ok'        => true,
        'finished'  => false,
        'index'     => $next,
        'next'      => $next + 1,
        'failed'    => $failed,
        'why'       => $failed ? $why : null,
        'remaining' => $failed ? $remaining : $remaining - 1,
        'total'     => count($chunks),
    ];
}
