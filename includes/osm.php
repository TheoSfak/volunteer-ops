<?php
/**
 * VolunteerOps — the OpenStreetMap layer on the Action Room map.
 *
 * Paths, caves, huts, springs, chapels, cliffs, peaks and the like, for the
 * people who have to guess where someone who is lost has gone: a lost hiker
 * follows a path and goes downhill, a child makes for shelter, a person in
 * crisis goes to water or a drop. The page never talks to Overpass itself
 * (the Content-Security-Policy would stop it, and the public servers ask to be
 * called sparingly): mission-osm.php asks here, and what Overpass answers is
 * kept in osm_feature_cache.
 *
 * The map is cut into tiles of 0.05° (about 5 km by 4.5 km in Crete). One
 * Overpass query per tile and per group of features, kept 30 days — a hillside
 * does not change between two searches — and served stale, however old, when
 * Overpass is down: an old map of the hillside is better than none at night.
 *
 * WHAT LEAVES THIS BUILDING: a bounding box and nothing else, the same
 * exposure as the routers in route-distance.php. Overpass etiquette is kept:
 * a User-Agent that says who is asking, no parallel calls, a retry pause after
 * a failure, and a second public server only when the first one fails.
 *
 * Data © OpenStreetMap contributors, ODbL.
 */

if (!defined('VOLUNTEEROPS')) {
    die('Direct access not permitted');
}

/** Tile edge in degrees. 0.05 = 1/20, so a tile is floor(coordinate * 20). */
const OSM_TILE_DIVISOR = 20;

/** The most tiles one request may cover. A phone screen at zoom 13 is about 4. */
const OSM_MAX_TILES = 12;

/** The groups a page may ask for. Each is one Overpass query per tile. */
const OSM_GROUPS = ['points', 'paths', 'tracks', 'cliffs'];

/** How long an answer is trusted, and how soon a failed refresh is retried. */
const OSM_CACHE_TTL = 2592000;   // 30 days
const OSM_RETRY_AFTER = 120;     // 2 minutes: Overpass itself asks for a pause of ~30 s after a 429/504

/**
 * What one Overpass query fetches. Points, paths and cliffs travel together —
 * one query per tile instead of three, which matters on a public server that
 * answers 429 to anyone who asks too often — and are cached as separate rows,
 * so the page can still ask for any of them. Dirt roads are many times the
 * data and ticked by few, so they are a query of their own.
 */
const OSM_BUNDLES = [
    'core'   => ['points', 'paths', 'cliffs'],
    'tracks' => ['tracks'],
];

/** Overpass' own server-side limit for one query, and ours for the call. */
const OSM_QUERY_TIMEOUT = 35;
const OSM_CURL_TIMEOUT = 40;
/** A server that does not even answer the connection is skipped quickly. */
const OSM_CONNECT_TIMEOUT = 6;
/** The pause before the one retry after a 429 or 504, in seconds. */
const OSM_BUSY_PAUSE = 5;

/** Most features one response carries; a guard, not a tuned figure. */
const OSM_MAX_ITEMS = 6000;

/**
 * Public Overpass servers, tried in order; a second one goes here when there is
 * one worth trusting. Probed 2026-10-01 from Heraklion: overpass-api.de is the
 * only global one that answers. overpass.kumi.systems and
 * overpass.private.coffee accepted nothing (a full timeout each), and
 * overpass.osm.ch answers but holds Switzerland only.
 */
const OSM_OVERPASS_URLS = [
    'https://overpass-api.de/api/interpreter',
];

/**
 * The tiles a bounding box covers, as [key, south, west, north, east] each, or
 * null when it is not a sane box or would take more than OSM_MAX_TILES.
 */
function osmTileKeys($south, $west, $north, $east): ?array {
    foreach ([$south, $west, $north, $east] as $v) {
        if (!is_numeric($v)) return null;
    }
    [$south, $west, $north, $east] = [(float) $south, (float) $west, (float) $north, (float) $east];
    if ($south >= $north || $west >= $east) return null;
    if ($south < -90 || $north > 90 || $west < -180 || $east > 180) return null;

    $r0 = (int) floor($south * OSM_TILE_DIVISOR);
    $r1 = (int) floor($north * OSM_TILE_DIVISOR);
    $c0 = (int) floor($west * OSM_TILE_DIVISOR);
    $c1 = (int) floor($east * OSM_TILE_DIVISOR);
    if (($r1 - $r0 + 1) * ($c1 - $c0 + 1) > OSM_MAX_TILES) return null;

    $tiles = [];
    for ($r = $r0; $r <= $r1; $r++) {
        for ($c = $c0; $c <= $c1; $c++) {
            $tiles[] = [
                $r . '_' . $c,
                $r / OSM_TILE_DIVISOR,
                $c / OSM_TILE_DIVISOR,
                ($r + 1) / OSM_TILE_DIVISOR,
                ($c + 1) / OSM_TILE_DIVISOR,
            ];
        }
    }
    return $tiles;
}

/** The bundle a group is fetched in, or null for a group that does not exist. */
function osmBundleOf(string $group): ?string {
    foreach (OSM_BUNDLES as $bundle => $groups) {
        if (in_array($group, $groups, true)) return $bundle;
    }
    return null;
}

/**
 * The Overpass QL for one group: [selection, output] — the selection as a union
 * or single statement for a bounding box, the output statement that reads it
 * back (a centre for the things drawn as a point, the full shape for lines).
 */
function osmGroupStatements(string $group, string $bb): ?array {
    switch ($group) {
        case 'points':
            return [
                '('
                . 'nwr["natural"~"^(cave_entrance|spring|peak|saddle)$"]' . $bb . ';'
                . 'nwr["amenity"~"^(drinking_water|shelter)$"]' . $bb . ';'
                . 'nwr["man_made"="water_well"]' . $bb . ';'
                . 'nwr["tourism"~"^(alpine_hut|wilderness_hut)$"]' . $bb . ';'
                . 'nwr["building"="chapel"]' . $bb . ';'
                . 'nwr["emergency"~"^(phone|defibrillator|water_tank|assembly_point)$"]' . $bb . ';'
                . 'nwr["aeroway"="helipad"]' . $bb . ';'
                . 'nwr["highway"="trailhead"]' . $bb . ';'
                . 'nwr["information"~"^(guidepost|board)$"]' . $bb . ';'
                . ')',
                'out center tags;',
            ];
        case 'paths':
            return ['way["highway"="path"]' . $bb, 'out geom tags;'];
        case 'tracks':
            return ['way["highway"="track"]' . $bb, 'out geom tags;'];
        case 'cliffs':
            return ['way["natural"="cliff"]' . $bb, 'out geom tags;'];
    }
    return null;
}

/** The Overpass QL for one group of features in one tile. */
function osmGroupQuery(string $group, float $south, float $west, float $north, float $east): ?string {
    $parts = osmGroupStatements($group, sprintf('(%.5F,%.5F,%.5F,%.5F)', $south, $west, $north, $east));
    return $parts === null ? null : '[out:json][timeout:' . OSM_QUERY_TIMEOUT . '];' . $parts[0] . ';' . $parts[1];
}

/** One query for every group of a bundle in one tile. */
function osmBundleQuery(string $bundle, float $south, float $west, float $north, float $east): ?string {
    if (!isset(OSM_BUNDLES[$bundle])) return null;
    $bb = sprintf('(%.5F,%.5F,%.5F,%.5F)', $south, $west, $north, $east);
    $query = '[out:json][timeout:' . OSM_QUERY_TIMEOUT . '];';
    $outputs = '';
    foreach (OSM_BUNDLES[$bundle] as $i => $group) {
        [$select, $output] = osmGroupStatements($group, $bb);
        $query .= $select . '->.g' . $i . ';';
        $outputs .= '.g' . $i . ' ' . $output;
    }
    return $query . $outputs;
}

/**
 * What a point feature is, from its tags: [category, extra] or null for a
 * feature the page has no use for. The category picks the icon; the extra is a
 * short word shown in the popup (whether a spring is drinkable, the kind of
 * emergency point, the kind of shelter).
 */
function osmClassifyPoint(array $tags): ?array {
    $natural = $tags['natural'] ?? '';
    $amenity = $tags['amenity'] ?? '';
    $emergency = $tags['emergency'] ?? '';

    if ($natural === 'cave_entrance') return ['cave', ''];
    if (in_array($tags['tourism'] ?? '', ['alpine_hut', 'wilderness_hut'], true)) return ['hut', (string) $tags['tourism']];
    if ($amenity === 'shelter') return ['shelter', (string) ($tags['shelter_type'] ?? '')];
    if ($natural === 'spring') return ['spring', (string) ($tags['drinking_water'] ?? '')];
    if ($amenity === 'drinking_water') return ['water', ''];
    if (($tags['man_made'] ?? '') === 'water_well') return ['well', (string) ($tags['drinking_water'] ?? '')];
    if ($emergency === 'water_tank') return ['tank', ''];
    if (in_array($emergency, ['phone', 'defibrillator', 'assembly_point'], true)) return ['emergency', $emergency];
    if (($tags['building'] ?? '') === 'chapel') return ['chapel', ''];
    if ($natural === 'peak') return ['peak', ''];
    if ($natural === 'saddle') return ['saddle', ''];
    if (($tags['aeroway'] ?? '') === 'helipad') return ['helipad', ''];
    if (($tags['highway'] ?? '') === 'trailhead') return ['trailhead', ''];
    if (in_array($tags['information'] ?? '', ['guidepost', 'board'], true)) return ['guidepost', (string) $tags['information']];
    return null;
}

/** A name for the popup: the local one, else the English one, kept short. */
function osmName(array $tags): string {
    $name = trim((string) ($tags['name'] ?? ($tags['name:el'] ?? ($tags['name:en'] ?? ''))));
    return $name === '' ? '' : mb_substr($name, 0, 80);
}

/** Elevation in whole metres from an `ele` tag such as "1450" or "1450 m", else null. */
function osmElevation(array $tags): ?int {
    if (!isset($tags['ele']) || !preg_match('/^\s*(-?\d+(?:\.\d+)?)/', (string) $tags['ele'], $m)) return null;
    return (int) round((float) $m[1]);
}

/**
 * A line's points as [lat, lng] to 5 decimals (about a metre), with the ones
 * closer than about 4 m to the last kept point dropped. Always keeps the last.
 */
function osmThin(array $geometry): array {
    $out = [];
    $lastLat = null;
    $lastLng = null;
    $n = count($geometry);
    foreach ($geometry as $i => $p) {
        if (!is_array($p) || !isset($p['lat'], $p['lon']) || !is_numeric($p['lat']) || !is_numeric($p['lon'])) continue;
        $lat = round((float) $p['lat'], 5);
        $lng = round((float) $p['lon'], 5);
        $isLast = ($i === $n - 1);
        if ($lastLat === null || $isLast || abs($lat - $lastLat) + abs($lng - $lastLng) > 0.00004) {
            $out[] = [$lat, $lng];
            $lastLat = $lat;
            $lastLng = $lng;
        }
    }
    return $out;
}

/**
 * Overpass' answer for one group, turned into the compact list the page draws —
 * or null when it is not an answer worth keeping: not JSON, or Overpass says it
 * ran out of time or memory (it still answers 200, with part of the data and a
 * "remark"). Keeping a part as if it were the whole would show a hillside with
 * holes in it for a month.
 *
 * Points:  {t,id,c,lat,lng[,n][,e][,x]}      Lines: {t,id,c,p:[[lat,lng]…][,n][,s][,v]}
 * t = n|w|r (node, way, relation), c = category, n = name, e = elevation (m),
 * x = a short extra, s = sac_scale, v = trail_visibility.
 */
function osmParse(?string $body, string $group): ?array {
    $all = osmParseAll($body);
    return $all === null ? null : ($all[$group] ?? []);
}

/**
 * The same, for an answer that may hold several groups (a bundle): every
 * element goes to the group its tags say — highway=path to paths, highway=track
 * to tracks, natural=cliff to cliffs, everything else is a point — and the
 * result is [group => items] for every group, empty ones included. Null on an
 * answer not worth keeping.
 */
function osmParseAll(?string $body): ?array {
    if ($body === null || $body === '') return null;
    $data = json_decode($body, true);
    if (!is_array($data) || !isset($data['elements']) || !is_array($data['elements'])) return null;
    if (isset($data['remark']) && stripos((string) $data['remark'], 'runtime error') !== false) return null;

    $groupOfLine = ['path' => 'paths', 'track' => 'tracks', 'cliff' => 'cliffs'];
    $out = ['points' => [], 'paths' => [], 'tracks' => [], 'cliffs' => []];

    foreach ($data['elements'] as $el) {
        if (!is_array($el) || empty($el['tags']) || !is_array($el['tags']) || !isset($el['id'], $el['type'])) continue;
        $tags = $el['tags'];
        $type = ['node' => 'n', 'way' => 'w', 'relation' => 'r'][$el['type']] ?? null;
        if ($type === null) continue;
        $name = osmName($tags);

        $highway = $tags['highway'] ?? '';
        $lineCategory = ($highway === 'path' || $highway === 'track') ? $highway : null;
        if ($lineCategory === null && ($tags['natural'] ?? '') === 'cliff') $lineCategory = 'cliff';

        if ($lineCategory !== null) {
            $points = osmThin((array) ($el['geometry'] ?? []));
            if (count($points) < 2) continue;
            $item = ['t' => $type, 'id' => (int) $el['id'], 'c' => $lineCategory, 'p' => $points];
            if ($name !== '') $item['n'] = $name;
            if ($lineCategory === 'path') {
                if (!empty($tags['sac_scale'])) $item['s'] = (string) $tags['sac_scale'];
                if (!empty($tags['trail_visibility'])) $item['v'] = (string) $tags['trail_visibility'];
            }
            $out[$groupOfLine[$lineCategory]][] = $item;
            continue;
        }

        $class = osmClassifyPoint($tags);
        if ($class === null) continue;
        $lat = $el['lat'] ?? ($el['center']['lat'] ?? null);
        $lng = $el['lon'] ?? ($el['center']['lon'] ?? null);
        if (!is_numeric($lat) || !is_numeric($lng)) continue;
        $item = ['t' => $type, 'id' => (int) $el['id'], 'c' => $class[0], 'lat' => round((float) $lat, 5), 'lng' => round((float) $lng, 5)];
        if ($name !== '') $item['n'] = $name;
        $ele = osmElevation($tags);
        if ($ele !== null) $item['e'] = $ele;
        if ($class[1] !== '') $item['x'] = mb_substr($class[1], 0, 40);
        $out['points'][] = $item;
    }
    return $out;
}

/**
 * Asks Overpass for a bundle of groups in one tile, trying each public server in
 * turn. [group => items] for every group of the bundle, or null when none of the
 * servers gave a usable answer.
 */
function osmFetchBundle(string $bundle, float $south, float $west, float $north, float $east): ?array {
    $query = osmBundleQuery($bundle, $south, $west, $north, $east);
    if ($query === null || !function_exists('curl_init')) return null;

    foreach (OSM_OVERPASS_URLS as $url) {
        // "Too many requests" and "gateway timeout" are Overpass saying it is
        // busy for a moment: it asks to be left alone briefly and then tried once more.
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => http_build_query(['data' => $query]),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => OSM_CONNECT_TIMEOUT,
                CURLOPT_TIMEOUT        => OSM_CURL_TIMEOUT,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_USERAGENT      => 'VolunteerOps/' . APP_VERSION . ' (search and rescue volunteer coordination)',
            ]);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($code === 200) {
                $all = osmParseAll($body === false ? null : $body);
                if ($all !== null) return array_intersect_key($all, array_flip(OSM_BUNDLES[$bundle]));
                error_log('[osm] unusable answer from ' . $url . ' for ' . $bundle);
                break;
            }
            error_log('[osm] ' . $url . ' HTTP ' . $code . ' for ' . $bundle);
            if ($attempt === 0 && ($code === 429 || $code === 504)) {
                sleep(OSM_BUSY_PAUSE);
                continue;
            }
            break;
        }
    }
    return null;
}

/**
 * What the cache holds for a tile and group: [items, ageSeconds], or null.
 */
function osmCacheRead(string $tileKey, string $group): ?array {
    $row = dbFetchOne(
        "SELECT payload, TIMESTAMPDIFF(SECOND, fetched_at, NOW()) AS age
           FROM osm_feature_cache WHERE tile_key = ? AND layer_group = ?",
        [$tileKey, $group]
    );
    if (!$row) return null;
    $items = json_decode((string) $row['payload'], true);
    return [is_array($items) ? $items : [], (int) $row['age']];
}

function osmCacheWrite(string $tileKey, string $group, array $items): void {
    dbExecute(
        "INSERT INTO osm_feature_cache (tile_key, layer_group, payload, element_count, fetched_at)
         VALUES (?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE payload = VALUES(payload), element_count = VALUES(element_count), fetched_at = NOW()",
        [$tileKey, $group, json_encode($items, JSON_UNESCAPED_UNICODE), count($items)]
    );
    // Rows nobody has looked at for a quarter of a year are not worth keeping.
    if (mt_rand(1, 50) === 1) {
        dbExecute("DELETE FROM osm_feature_cache WHERE fetched_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");
    }
}

/**
 * A refresh failed: do not ask again for OSM_RETRY_AFTER seconds. With an old
 * row this ages it to "due in two minutes"; with none, an empty row is written
 * the same way, so the next look retries instead of every look hammering a
 * server that is down.
 */
function osmCacheRetryLater(string $tileKey, string $group): void {
    $age = OSM_CACHE_TTL - OSM_RETRY_AFTER;
    dbExecute(
        "INSERT INTO osm_feature_cache (tile_key, layer_group, payload, element_count, fetched_at)
         VALUES (?, ?, '[]', 0, DATE_SUB(NOW(), INTERVAL $age SECOND))
         ON DUPLICATE KEY UPDATE fetched_at = DATE_SUB(NOW(), INTERVAL $age SECOND)",
        [$tileKey, $group]
    );
}

/**
 * The features of one bundle in one tile.
 *
 * Returns [byGroup, state]: byGroup is [group => items] for every group of the
 * bundle, and state is 'fresh' (from the cache, or just fetched), 'stale' (an
 * old answer; the refresh failed or was held back), 'pending' (nothing known yet
 * and no fetch allowed now — the page should ask again) or 'failed' (nothing
 * known and Overpass did not answer). $budget is how many Overpass calls this
 * request may still make; it is spent here.
 */
function osmTileBundle(string $tileKey, string $bundle, float $s, float $w, float $n, float $e, int &$budget): array {
    $groups = OSM_BUNDLES[$bundle];
    $held = [];
    $allFresh = true;
    $anyRow = false;
    foreach ($groups as $group) {
        $row = osmCacheRead($tileKey, $group);
        $held[$group] = $row !== null ? $row[0] : [];
        if ($row === null || $row[1] >= OSM_CACHE_TTL) $allFresh = false;
        if ($row !== null) $anyRow = true;
    }
    if ($allFresh) return [$held, 'fresh'];

    $notNow = [$held, $anyRow ? 'stale' : 'pending'];
    if ($budget <= 0) return $notNow;

    // One caller at a time per tile, so ten volunteers opening the same
    // hillside do not send Overpass ten identical queries.
    $lock = 'osm_' . $tileKey . '_' . $bundle;
    $got = (int) dbFetchValue("SELECT GET_LOCK(?, 0)", [$lock]) === 1;
    if (!$got) return $notNow;
    try {
        $budget--;
        $fetched = osmFetchBundle($bundle, $s, $w, $n, $e);
        if ($fetched === null) {
            foreach ($groups as $group) osmCacheRetryLater($tileKey, $group);
            return [$held, $anyRow ? 'stale' : 'failed'];
        }
        foreach ($groups as $group) osmCacheWrite($tileKey, $group, $fetched[$group] ?? []);
        return [$fetched, 'fresh'];
    } finally {
        dbFetchValue("SELECT RELEASE_LOCK(?)", [$lock]);
    }
}
