<?php
/**
 * VolunteerOps - The heat layer of the «Πίνακας» console look (v3.431.0, ground rules v3.432.0): where the
 * teams have actually been, and for how long.
 *
 * Verified Coverage (computeMissionSectorCoverage()) answers a yes / no question per sector - was this ground
 * seen? - and draws only the gaps. This answers the next one, for the whole mission at once: for how long was
 * each spot in sight of a searcher. A staging point where a team waited an hour is red; a ridge crossed once at
 * walking pace is blue; ground nobody reached is not drawn at all.
 *
 * ONE DEFINITION OF «SEEN». It is not invented here: it is Verified Coverage's, applied to every cell of the
 * map instead of the cells of a sector. Each cell is judged by the rules of its own ground (rural, mountain,
 * urban: COVERAGE_TERRAIN_PROFILES) - the ground a sector has through its area (coverageEffectiveTerrain()),
 * and for ground outside every sector the area's own choice, else open country, which is how every cell was
 * measured before the ground could be chosen:
 *
 *  - radius_m: a searcher has a cell in sight while they are within this distance of it (25 m in the open,
 *    15 on a mountain, 10 in a town). A cell therefore has heat exactly where Verified Coverage would call it
 *    covered, and the heat is the time that held for;
 *  - max_accuracy_m: the vaguest fix believed on that ground (100 / 50 / 30 m). A fix is only evidence of
 *    ground within its own uncertainty, so a town does not believe what an open field does;
 *  - max_gap_s: the longest silence between two believed fixes of one person that is still a walked stretch
 *    (30 min / 5 min / 2 min). A longer one is lost signal, and its seconds are nobody's;
 *  - COVERAGE_BUILDING_EXCLUSION_M: the ground within 15 m of a registered building is left out, as it is from
 *    both of the sector's counts (the building is checked floor by floor, the 🏢 figure).
 * What Verified Coverage does not have, and this does: a stretch faster than COVERAGE_HEAT_MAX_SPEED_MPS is a
 * jump of the position, not a walk, and is not credited (Verified Coverage would draw a corridor through it).
 *
 * What counts is the time, not the number of fixes: a phone that pings every 5 s and one that pings every 30 s
 * describe the same walk. So each stretch between two consecutive fixes of the SAME person is worth its own
 * seconds, spread along the line between the two fixes, and every cell the person had in sight while at a point
 * of the line is credited those seconds (someone standing still gives all of theirs to every cell around them).
 * A fix that belongs to no stretch (a person heard once, the first fix after a silence) is worth
 * COVERAGE_HEAT_POINT_S, so they are on the map, faintly.
 *
 * The cells are anchored to the earth, not to the data: the same place is the same cell whatever else has been
 * walked since, so the picture grows instead of shifting between two requests. When there are too many of them
 * for a map to draw smoothly the cell size doubles (20, 40, 80, 160 m) until they fit.
 *
 * Nothing here is stored: it is computed from volunteer_pings for whoever asks (command staff,
 * mission-coverage-heat.php), and a signature of the pings AND of the ground they are judged on (a change of an
 * area's terrain moves the picture) lets the page ask «anything new?» without the work being done again.
 */

/** The smallest cell, in metres: what the GPS of a phone in a hand can tell apart. */
const COVERAGE_HEAT_CELL_M = 20;
/** The most cells one answer carries; past it the cells grow (a map draws this many without effort). */
const COVERAGE_HEAT_MAX_CELLS = 6000;
/** Faster than this between two fixes is a jump of the position, not somebody moving (m/s, a car on a good road is 30). */
const COVERAGE_HEAT_MAX_SPEED_MPS = 50;
/** What a fix with no stretch around it is worth, in seconds. */
const COVERAGE_HEAT_POINT_S = 5;
/** The most points one stretch is spread over, so a long fast leg cannot make a request slow. */
const COVERAGE_HEAT_MAX_SAMPLES = 400;
/** The coarsest cell the grid grows to. */
const COVERAGE_HEAT_CELL_MAX_M = 160;

/**
 * One cell's size in degrees of latitude and of longitude. The longitude figure uses a reference latitude
 * rounded to a tenth of a degree, so the grid does not move when a few more fixes shift the average.
 *
 * @return array{0: float, 1: float}
 */
function coverageHeatCellDegrees(float $cellM, float $refLat): array
{
    $mLat = 111320.0;
    $mLng = max(1.0, 111320.0 * cos(deg2rad(round($refLat, 1))));
    return [$cellM / $mLat, $cellM / $mLng];
}

/** The loosest accuracy any of these grounds believes: what the pings are asked for (each ground then tightens it). */
function coverageHeatLoosestAccuracy(array $terrains = ['rural', 'mountain', 'urban']): float
{
    $limits = array_map(fn($t) => COVERAGE_TERRAIN_PROFILES[$t]['max_accuracy_m'], array_filter($terrains, fn($t) => isset(COVERAGE_TERRAIN_PROFILES[$t])));
    return (float) ($limits ? max($limits) : COVERAGE_TERRAIN_PROFILES['rural']['max_accuracy_m']);
}

/**
 * Seconds per cell, before rounding, for ONE ground: [cellKey => seconds], the key being "i:j" of the cell's
 * indices. Only cells that are of this ground and not beside a building are credited, with this ground's rules
 * for which fixes are believed, how long a silence may be and how far a searcher sees.
 *
 * @param array<int, list<array{lat: float, lng: float, ts: int, acc: ?float}>> $byUser each person's fixes, oldest first
 * @param callable(int, int, string): bool $cellOk is cell (i, j) of this ground and outside the buildings' ground?
 * @return array<string, float>
 */
function coverageHeatAccumulate(array $byUser, float $cellM, float $refLat, array $profile, string $terrain, callable $cellOk): array
{
    [$dLat, $dLng] = coverageHeatCellDegrees($cellM, $refLat);
    $mLat = 111320.0;
    $mLng = $cellM / $dLng;
    $radius = (float) $profile['radius_m'];
    $span = (int) ceil($radius / $cellM);
    $acc = [];

    // Seconds are first gathered per metre-square, because a person standing still (or a base where thirty people
    // stand) leaves thousands of credits at what is, to a GPS, the same place; spreading each of them over the cells
    // in sight would cost seconds of work for nothing. The square's centre stands for its points: a shift of at most
    // 0.7 m, which no radius of 10 m or more can tell.
    $qLat = $dLat / $cellM;
    $qLng = $dLng / $cellM;
    $bucket = [];
    $credit = function (float $lat, float $lng, float $seconds) use (&$bucket, $qLat, $qLng): void {
        $bi = (int) floor($lat / $qLat);
        $bj = (int) floor($lng / $qLng);
        $key = $bi . ':' . $bj;
        $bucket[$key] = isset($bucket[$key]) ? [$bucket[$key][0], $bucket[$key][1], $bucket[$key][2] + $seconds] : [($bi + 0.5) * $qLat, ($bj + 0.5) * $qLng, $seconds];
    };

    // The cell the point is in always gets the seconds (so none are lost when the cells are larger than the
    // radius); so does every cell whose centre is within the radius of the point.
    $spread = function (float $lat, float $lng, float $seconds) use (&$acc, $dLat, $dLng, $mLat, $mLng, $radius, $span, $cellOk, $terrain): void {
        $i0 = (int) floor($lat / $dLat);
        $j0 = (int) floor($lng / $dLng);
        $r2 = $radius * $radius;
        for ($di = -$span; $di <= $span; $di++) {
            $i = $i0 + $di;
            $dy = (($i + 0.5) * $dLat - $lat) * $mLat;
            $dy2 = $dy * $dy;
            if ($di !== 0 && $dy2 > $r2) {
                continue;
            }
            for ($dj = -$span; $dj <= $span; $dj++) {
                $j = $j0 + $dj;
                if ($di !== 0 || $dj !== 0) {
                    $dx = (($j + 0.5) * $dLng - $lng) * $mLng;
                    if ($dx * $dx + $dy2 > $r2) {
                        continue;
                    }
                }
                if (!$cellOk($i, $j, $terrain)) {
                    continue;
                }
                $key = $i . ':' . $j;
                $acc[$key] = ($acc[$key] ?? 0.0) + $seconds;
            }
        }
    };

    foreach ($byUser as $all) {
        // A fix vaguer than this ground believes is skipped before anything else: it neither counts nor ends a
        // stretch, so the gap rule sees the silence it really was (as coverageTracksFromPings() does).
        $fixes = array_values(array_filter($all, fn($f) => $f['acc'] === null || $f['acc'] <= $profile['max_accuracy_m']));
        $n = count($fixes);
        $inStretch = array_fill(0, $n, false);
        for ($i = 1; $i < $n; $i++) {
            $a = $fixes[$i - 1];
            $b = $fixes[$i];
            $dt = $b['ts'] - $a['ts'];
            if ($dt <= 0 || $dt > $profile['max_gap_s']) {
                continue;
            }
            $d = gpsDistanceMeters($a['lat'], $a['lng'], $b['lat'], $b['lng']);
            if ($d / $dt > COVERAGE_HEAT_MAX_SPEED_MPS) {
                continue;
            }
            $inStretch[$i - 1] = true;
            $inStretch[$i] = true;
            if ($d < 1.0) {
                // Standing still: all of the stretch's time is at the one place.
                $credit(($a['lat'] + $b['lat']) / 2, ($a['lng'] + $b['lng']) / 2, (float) $dt);
                continue;
            }
            // The stretch's seconds, spread evenly over points no more than half a cell apart (both ends included).
            $samples = (int) min(COVERAGE_HEAT_MAX_SAMPLES, max(1, ceil($d / ($cellM / 2))));
            $each = $dt / ($samples + 1);
            for ($k = 0; $k <= $samples; $k++) {
                $f = $k / $samples;
                $credit($a['lat'] + ($b['lat'] - $a['lat']) * $f, $a['lng'] + ($b['lng'] - $a['lng']) * $f, $each);
            }
        }
        foreach ($fixes as $i => $fix) {
            if (!$inStretch[$i]) {
                $credit($fix['lat'], $fix['lng'], (float) COVERAGE_HEAT_POINT_S);
            }
        }
    }
    foreach ($bucket as [$lat, $lng, $seconds]) {
        $spread($lat, $lng, $seconds);
    }
    return $acc;
}

/**
 * The heat of a mission from its pings: the cells with their seconds, the cell size they ended up at and what the
 * colours should be scaled against.
 *
 * `$ground` says what each cell is judged as (all optional; without it the whole map is open country, which is
 * how Verified Coverage measures ground nobody chose a kind for):
 *   'terrains'  the grounds that exist on this map, from COVERAGE_TERRAIN_PROFILES (default ['rural'])
 *   'at'        callable(lat, lng): string - the ground of a point (default: always 'rural')
 *   'buildings' list of [lat, lng] of registered buildings, whose surroundings are left out
 *
 * @param list<array{user_id: int|string, lat: float|string, lng: float|string, accuracy_meters?: float|string|null, created_at: string}> $pings
 *        in any order (they are sorted here), as volunteer_pings rows
 * @param array{terrains?: list<string>, at?: callable, buildings?: list<array{0: float, 1: float}>} $ground
 * @return array{cells: list<array{0: float, 1: float, 2: int}>, cell_m: int, max: int, p95: int, people: int, fixes: int, terrains: list<string>}
 */
function coverageHeatCells(array $pings, array $ground = [], int $cellM = COVERAGE_HEAT_CELL_M, int $maxCells = COVERAGE_HEAT_MAX_CELLS): array
{
    $terrains = array_values(array_filter($ground['terrains'] ?? ['rural'], fn($t) => isset(COVERAGE_TERRAIN_PROFILES[$t])));
    if (!$terrains) {
        $terrains = ['rural'];
    }
    $at = $ground['at'] ?? null;
    $buildings = $ground['buildings'] ?? [];
    $limit = coverageHeatLoosestAccuracy($terrains);

    $byUser = [];
    $fixes = 0;
    $latSum = 0.0;
    foreach ($pings as $row) {
        $acc = isset($row['accuracy_meters']) ? (float) $row['accuracy_meters'] : null;
        if ($acc !== null && $acc > $limit) {
            continue;
        }
        $ts = strtotime((string) $row['created_at']);
        if ($ts === false) {
            continue;
        }
        $byUser[(int) $row['user_id']][] = ['lat' => (float) $row['lat'], 'lng' => (float) $row['lng'], 'ts' => $ts, 'acc' => $acc];
        $fixes++;
        $latSum += (float) $row['lat'];
    }
    $empty = ['cells' => [], 'cell_m' => $cellM, 'max' => 0, 'p95' => 0, 'people' => count($byUser), 'fixes' => $fixes, 'terrains' => $terrains];
    if ($fixes === 0) {
        return $empty;
    }
    foreach ($byUser as &$list) {
        usort($list, fn($x, $y) => $x['ts'] <=> $y['ts']);
    }
    unset($list);
    $refLat = $latSum / $fixes;

    $run = function (float $size) use ($byUser, $refLat, $terrains, $at, $buildings): array {
        [$dLat, $dLng] = coverageHeatCellDegrees($size, $refLat);
        // What a cell is, worked out once per cell and shared by the grounds: its own ground, or "beside a building".
        $info = [];
        $exclusionLat = COVERAGE_BUILDING_EXCLUSION_M / 111320.0;
        $cellOk = function (int $i, int $j, string $terrain) use (&$info, $dLat, $dLng, $at, $buildings, $exclusionLat): bool {
            $key = $i . ':' . $j;
            if (!isset($info[$key])) {
                $cLat = ($i + 0.5) * $dLat;
                $cLng = ($j + 0.5) * $dLng;
                $info[$key] = '';
                $beside = false;
                foreach ($buildings as $b) {
                    if (abs($cLat - $b[0]) <= $exclusionLat && gpsDistanceMeters($cLat, $cLng, $b[0], $b[1]) <= COVERAGE_BUILDING_EXCLUSION_M) {
                        $beside = true;
                        break;
                    }
                }
                if (!$beside) {
                    $info[$key] = $at ? (string) $at($cLat, $cLng) : 'rural';
                }
            }
            return $info[$key] === $terrain;
        };
        $acc = [];
        foreach ($terrains as $terrain) {
            // The cells of different grounds are different cells, so the passes never touch the same key.
            $acc += coverageHeatAccumulate($byUser, $size, $refLat, COVERAGE_TERRAIN_PROFILES[$terrain], $terrain, $cellOk);
        }
        return $acc;
    };

    $acc = $run((float) $cellM);
    while (count($acc) > $maxCells && $cellM < COVERAGE_HEAT_CELL_MAX_M) {
        $cellM *= 2;
        $acc = $run((float) $cellM);
    }

    // Busiest first (ties by cell, so the same pings always give the same answer); past the cap the faintest go.
    uksort($acc, fn($ka, $kb) => $acc[$kb] <=> $acc[$ka] ?: strcmp((string) $ka, (string) $kb));
    $acc = array_slice($acc, 0, $maxCells, true);

    [$dLat, $dLng] = coverageHeatCellDegrees((float) $cellM, $refLat);
    $cells = [];
    $values = [];
    foreach ($acc as $key => $seconds) {
        [$i, $j] = array_map('intval', explode(':', (string) $key));
        $value = max(1, (int) round($seconds));
        $cells[] = [round(($i + 0.5) * $dLat, 6), round(($j + 0.5) * $dLng, 6), $value];
        $values[] = $value;
    }
    sort($values);
    $count = count($values);
    return [
        'cells'    => $cells,
        'cell_m'   => $cellM,
        'max'      => $count ? $values[$count - 1] : 0,
        // The colours are scaled to the 95th percentile, so one cell a team sat in all night does not turn the rest blue.
        'p95'      => $count ? $values[(int) max(0, ceil($count * 0.95) - 1)] : 0,
        'people'   => count($byUser),
        'fixes'    => $fixes,
        'terrains' => $terrains,
    ];
}

/**
 * The ground of one point: the sector it lies in (its area's terrain, or urban when the sector carries registered
 * buildings and the area chose nothing, else open country - coverageEffectiveTerrain()); outside every sector the
 * area it lies in, when that area chose a ground; else open country. Where sectors overlap the stricter ground
 * wins (the one that believes the fewest fixes), so ground is never judged more generously than a sector would.
 *
 * @param list<array{bbox: array{0: float, 1: float, 2: float, 3: float}, geo: array, terrain: string}> $sectors
 * @param list<array{bbox: array{0: float, 1: float, 2: float, 3: float}, geo: array, terrain: string}> $areas only areas with a ground of their own
 */
function coverageHeatTerrainAt(float $lat, float $lng, array $sectors, array $areas): string
{
    foreach ([$sectors, $areas] as $shapes) {
        $best = null;
        foreach ($shapes as $shape) {
            [$minLat, $maxLat, $minLng, $maxLng] = $shape['bbox'];
            if ($lat < $minLat || $lat > $maxLat || $lng < $minLng || $lng > $maxLng || !pointInPolygon($lat, $lng, $shape['geo'])) {
                continue;
            }
            if ($best === null || COVERAGE_TERRAIN_PROFILES[$shape['terrain']]['max_accuracy_m'] < COVERAGE_TERRAIN_PROFILES[$best]['max_accuracy_m']) {
                $best = $shape['terrain'];
            }
        }
        if ($best !== null) {
            return $best;
        }
    }
    return 'rural';
}

/** A polygon column ([[lat, lng], ...], at least three corners) as a shape with its bounding box, or null. */
function coverageHeatShape($geoJson, string $terrain): ?array
{
    $geo = is_array($geoJson) ? $geoJson : json_decode((string) $geoJson, true);
    if (!is_array($geo) || count($geo) < 3) {
        return null;
    }
    $lats = array_map(fn($p) => (float) $p[0], $geo);
    $lngs = array_map(fn($p) => (float) $p[1], $geo);
    return ['bbox' => [min($lats), max($lats), min($lngs), max($lngs)], 'geo' => $geo, 'terrain' => $terrain];
}

/**
 * What the cells of one mission are judged as: the grounds that exist on its map, a function from a point to its
 * ground, and the registered buildings. Read from the same tables Verified Coverage reads.
 *
 * @return array{terrains: list<string>, at: callable, buildings: list<array{0: float, 1: float}>}
 */
function coverageHeatGround(int $missionId): array
{
    $buildingsBySector = [];
    $buildings = [];
    foreach (dbFetchAll(
        "SELECT b.sector_id, b.lat, b.lng FROM mission_sector_buildings b JOIN mission_search_sectors s ON s.id = b.sector_id WHERE s.mission_id = ?",
        [$missionId]
    ) as $b) {
        $buildingsBySector[(int) $b['sector_id']] = true;
        $buildings[] = [(float) $b['lat'], (float) $b['lng']];
    }

    $sectors = [];
    foreach (dbFetchAll(
        "SELECT s.id, s.geo, a.terrain FROM mission_search_sectors s JOIN mission_search_areas a ON a.id = s.area_id WHERE s.mission_id = ?",
        [$missionId]
    ) as $row) {
        $terrain = coverageEffectiveTerrain($row['terrain'], !empty($buildingsBySector[(int) $row['id']]));
        $shape = coverageHeatShape($row['geo'], $terrain);
        if ($shape) {
            $sectors[] = $shape;
        }
    }
    $areas = [];
    foreach (dbFetchAll("SELECT geo, terrain FROM mission_search_areas WHERE mission_id = ? AND terrain IS NOT NULL", [$missionId]) as $row) {
        $shape = coverageHeatShape($row['geo'], (string) $row['terrain']);
        if ($shape && isset(COVERAGE_TERRAIN_PROFILES[$shape['terrain']])) {
            $areas[] = $shape;
        }
    }

    // Open country always exists: it is what lies outside every shape. The others only when something is on them.
    $terrains = array_values(array_unique(array_merge(['rural'], array_column($sectors, 'terrain'), array_column($areas, 'terrain'))));
    return [
        'terrains'  => $terrains,
        'at'        => fn(float $lat, float $lng): string => coverageHeatTerrainAt($lat, $lng, $sectors, $areas),
        'buildings' => $buildings,
    ];
}

/**
 * What the mission looks like now, cheaply: the believed pings (count and newest id) and the ground they are judged
 * on (the sectors, the areas with their terrain, the buildings), so that neither a new fix nor a change of an
 * area's ground leaves the page with an old picture.
 */
function coverageHeatSignature(int $missionId): string
{
    $pings = dbFetchOne(
        "SELECT COUNT(*) AS c, COALESCE(MAX(vp.id), 0) AS m
         FROM volunteer_pings vp
         JOIN shifts s ON s.id = vp.shift_id
         WHERE s.mission_id = ? AND (vp.accuracy_meters IS NULL OR vp.accuracy_meters <= ?)",
        [$missionId, coverageHeatLoosestAccuracy()]
    );
    $sectors = dbFetchOne(
        "SELECT COUNT(*) AS c, COALESCE(SUM(CRC32(CONCAT(id, ':', area_id, ':', geo))), 0) AS h FROM mission_search_sectors WHERE mission_id = ?",
        [$missionId]
    );
    $areas = dbFetchOne(
        "SELECT COUNT(*) AS c, COALESCE(SUM(CRC32(CONCAT(id, ':', COALESCE(terrain, ''), ':', geo))), 0) AS h FROM mission_search_areas WHERE mission_id = ?",
        [$missionId]
    );
    $buildings = dbFetchOne(
        "SELECT COUNT(*) AS c, COALESCE(MAX(b.id), 0) AS m FROM mission_sector_buildings b JOIN mission_search_sectors s ON s.id = b.sector_id WHERE s.mission_id = ?",
        [$missionId]
    );
    $ground = implode(':', [$sectors['c'] ?? 0, $sectors['h'] ?? 0, $areas['c'] ?? 0, $areas['h'] ?? 0, $buildings['c'] ?? 0, $buildings['m'] ?? 0]);
    return ((int) ($pings['c'] ?? 0)) . '-' . ((int) ($pings['m'] ?? 0)) . '-' . dechex(crc32($ground));
}

/**
 * The heat of one mission, from its pings (the positions stored are the estimate the server kept, not the raw fix:
 * see recordVolunteerPing()) judged on the ground of its map. `sig` is coverageHeatSignature() of what it was made from.
 *
 * @return array{cells: list<array{0: float, 1: float, 2: int}>, cell_m: int, max: int, p95: int, people: int, fixes: int, terrains: list<string>, sig: string}
 */
function computeMissionCoverageHeat(int $missionId): array
{
    $sig = coverageHeatSignature($missionId);
    $ground = coverageHeatGround($missionId);
    $pings = dbFetchAll(
        "SELECT vp.user_id, vp.lat, vp.lng, vp.accuracy_meters, vp.created_at
         FROM volunteer_pings vp
         JOIN shifts s ON s.id = vp.shift_id
         WHERE s.mission_id = ? AND (vp.accuracy_meters IS NULL OR vp.accuracy_meters <= ?)
         ORDER BY vp.user_id, vp.created_at, vp.id",
        [$missionId, coverageHeatLoosestAccuracy($ground['terrains'])]
    );
    return coverageHeatCells($pings, $ground) + ['sig' => $sig];
}
