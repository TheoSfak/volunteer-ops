<?php
/**
 * VolunteerOps - The heat layer of the «Πίνακας» console look (v3.431.0): where the
 * teams have actually been, and for how long.
 *
 * Verified Coverage (computeMissionSectorCoverage()) answers a yes / no question
 * per sector - was this ground seen? - and draws only the gaps. This answers a
 * different one, for the whole mission at once: how much of the people's time was
 * spent where. A staging point where a team waited an hour is red; a ridge crossed
 * once at walking pace is blue; ground nobody reached is not drawn at all.
 *
 * What counts is the time, not the number of fixes: a phone that pings every 5 s
 * and one that pings every 30 s describe the same walk. So each stretch between
 * two consecutive fixes of the SAME person is worth its own seconds, and those
 * seconds are spread along the line between the two fixes (a walker passing a cell
 * leaves a few, someone standing in it leaves all of them) into cells of about
 * 20 m. Three rules keep the picture honest, and they are the ones Verified
 * Coverage already lives by:
 *
 *  - only a fix that is believed counts: one vaguer than the cell itself is
 *    evidence of nothing at that scale (COVERAGE_HEAT_MAX_ACCURACY_M);
 *  - a silence longer than COVERAGE_HEAT_MAX_GAP_S is not a stretch walked: the
 *    seconds of lost signal are not credited to a line nobody was seen on;
 *  - a stretch faster than any vehicle (COVERAGE_HEAT_MAX_SPEED_MPS) is a jump of
 *    the position, not a walk, and is not credited either.
 * A fix that belongs to no stretch (the first one of a person after a silence, a
 * lone one) is worth COVERAGE_HEAT_POINT_S, so a person who was only heard once
 * is still on the map, faintly.
 *
 * The cells are anchored to the earth, not to the data: the same place is the same
 * cell whatever else has been walked since, so the picture grows instead of
 * shifting between two requests. When there are too many of them for a map to
 * draw smoothly the cell size doubles (20, 40, 80, 160 m) until they fit.
 *
 * Nothing here is stored: it is computed from volunteer_pings for whoever asks
 * (command staff, mission-coverage-heat.php), and a signature of the pings lets
 * the page ask «anything new?» without the work being done again.
 */

/** The smallest cell, in metres: what the GPS of a phone in a hand can tell apart. */
const COVERAGE_HEAT_CELL_M = 20;
/** The most cells one answer carries; past it the cells grow (a map draws this many without effort). */
const COVERAGE_HEAT_MAX_CELLS = 6000;
/** The vaguest fix believed. Looser than the cell would put the walker in the wrong one as often as the right one. */
const COVERAGE_HEAT_MAX_ACCURACY_M = 30;
/** The longest silence between two fixes that is still a stretch walked (the mountain profile's, the strictest ground the map is used on). */
const COVERAGE_HEAT_MAX_GAP_S = 300;
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

/**
 * Seconds per cell, before rounding: [cellKey => seconds], the key being "i:j" of the cell's indices.
 *
 * @param array<int, list<array{lat: float, lng: float, ts: int}>> $byUser each person's believed fixes, oldest first
 * @return array<string, float>
 */
function coverageHeatAccumulate(array $byUser, float $cellM, float $refLat): array
{
    [$dLat, $dLng] = coverageHeatCellDegrees($cellM, $refLat);
    $acc = [];
    $add = function (float $lat, float $lng, float $seconds) use (&$acc, $dLat, $dLng): void {
        $key = ((int) floor($lat / $dLat)) . ':' . ((int) floor($lng / $dLng));
        $acc[$key] = ($acc[$key] ?? 0.0) + $seconds;
    };

    foreach ($byUser as $fixes) {
        $n = count($fixes);
        $inStretch = array_fill(0, $n, false);
        for ($i = 1; $i < $n; $i++) {
            $a = $fixes[$i - 1];
            $b = $fixes[$i];
            $dt = $b['ts'] - $a['ts'];
            if ($dt <= 0 || $dt > COVERAGE_HEAT_MAX_GAP_S) {
                continue;
            }
            $d = gpsDistanceMeters($a['lat'], $a['lng'], $b['lat'], $b['lng']);
            if ($d / $dt > COVERAGE_HEAT_MAX_SPEED_MPS) {
                continue;
            }
            $inStretch[$i - 1] = true;
            $inStretch[$i] = true;
            // The stretch's seconds, spread evenly over points no more than half a cell apart (both ends included).
            $samples = (int) min(COVERAGE_HEAT_MAX_SAMPLES, max(1, ceil($d / ($cellM / 2))));
            $each = $dt / ($samples + 1);
            for ($k = 0; $k <= $samples; $k++) {
                $f = $k / $samples;
                $add($a['lat'] + ($b['lat'] - $a['lat']) * $f, $a['lng'] + ($b['lng'] - $a['lng']) * $f, $each);
            }
        }
        foreach ($fixes as $i => $fix) {
            if (!$inStretch[$i]) {
                $add($fix['lat'], $fix['lng'], COVERAGE_HEAT_POINT_S);
            }
        }
    }
    return $acc;
}

/**
 * The heat of a mission from its pings: the cells with their seconds, the cell size they ended up at and what the
 * colours should be scaled against.
 *
 * @param list<array{user_id: int|string, lat: float|string, lng: float|string, accuracy_meters?: float|string|null, created_at: string}> $pings
 *        in any order (they are sorted here), as volunteer_pings rows
 * @return array{cells: list<array{0: float, 1: float, 2: int}>, cell_m: int, max: int, p95: int, people: int, fixes: int}
 */
function coverageHeatCells(array $pings, int $cellM = COVERAGE_HEAT_CELL_M, int $maxCells = COVERAGE_HEAT_MAX_CELLS): array
{
    $byUser = [];
    $fixes = 0;
    $latSum = 0.0;
    foreach ($pings as $row) {
        if (isset($row['accuracy_meters']) && (float) $row['accuracy_meters'] > COVERAGE_HEAT_MAX_ACCURACY_M) {
            continue;
        }
        $ts = strtotime((string) $row['created_at']);
        if ($ts === false) {
            continue;
        }
        $byUser[(int) $row['user_id']][] = ['lat' => (float) $row['lat'], 'lng' => (float) $row['lng'], 'ts' => $ts];
        $fixes++;
        $latSum += (float) $row['lat'];
    }
    $empty = ['cells' => [], 'cell_m' => $cellM, 'max' => 0, 'p95' => 0, 'people' => count($byUser), 'fixes' => $fixes];
    if ($fixes === 0) {
        return $empty;
    }
    foreach ($byUser as &$list) {
        usort($list, fn($x, $y) => $x['ts'] <=> $y['ts']);
    }
    unset($list);

    $refLat = $latSum / $fixes;
    $acc = coverageHeatAccumulate($byUser, (float) $cellM, $refLat);
    while (count($acc) > $maxCells && $cellM < COVERAGE_HEAT_CELL_MAX_M) {
        $cellM *= 2;
        $acc = coverageHeatAccumulate($byUser, (float) $cellM, $refLat);
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
        'cells'  => $cells,
        'cell_m' => $cellM,
        'max'    => $count ? $values[$count - 1] : 0,
        // The colours are scaled to the 95th percentile, so one cell a team sat in all night does not turn the rest blue.
        'p95'    => $count ? $values[(int) max(0, ceil($count * 0.95) - 1)] : 0,
        'people' => count($byUser),
        'fixes'  => $fixes,
    ];
}

/** What the pings of a mission look like now, cheaply: the count and the newest id of the ones the heat would use. */
function coverageHeatSignature(int $missionId): string
{
    $row = dbFetchOne(
        "SELECT COUNT(*) AS c, COALESCE(MAX(vp.id), 0) AS m
         FROM volunteer_pings vp
         JOIN shifts s ON s.id = vp.shift_id
         WHERE s.mission_id = ? AND (vp.accuracy_meters IS NULL OR vp.accuracy_meters <= ?)",
        [$missionId, COVERAGE_HEAT_MAX_ACCURACY_M]
    );
    return ((int) ($row['c'] ?? 0)) . '-' . ((int) ($row['m'] ?? 0));
}

/**
 * The heat of one mission, from its pings (the positions stored are the estimate the server kept, not the raw fix:
 * see recordVolunteerPing()). `sig` is coverageHeatSignature() of the pings it was made from.
 *
 * @return array{cells: list<array{0: float, 1: float, 2: int}>, cell_m: int, max: int, p95: int, people: int, fixes: int, sig: string}
 */
function computeMissionCoverageHeat(int $missionId): array
{
    $sig = coverageHeatSignature($missionId);
    $pings = dbFetchAll(
        "SELECT vp.user_id, vp.lat, vp.lng, vp.accuracy_meters, vp.created_at
         FROM volunteer_pings vp
         JOIN shifts s ON s.id = vp.shift_id
         WHERE s.mission_id = ? AND (vp.accuracy_meters IS NULL OR vp.accuracy_meters <= ?)
         ORDER BY vp.user_id, vp.created_at, vp.id",
        [$missionId, COVERAGE_HEAT_MAX_ACCURACY_M]
    );
    return coverageHeatCells($pings) + ['sig' => $sig];
}
