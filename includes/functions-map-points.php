<?php
/**
 * VolunteerOps - Map points on the Action Room map (v3.361.0).
 *
 * Reference points command loads for ONE mission from a list it already has
 * (caves, springs, huts, trailheads, vehicle gates, a landing zone...), as pins
 * on the live map. Everybody on the operation sees them and gets directions;
 * only command staff import, edit or delete them. Each point carries what a
 * crew needs on the spot: a name, its altitude, how to get there (on foot / by
 * vehicle / both) and a free note.
 *
 * Not a dispatch point: nobody is sent to it and it has no progress. Not the
 * photographed «Σημείο Ενδιαφέροντος» either: that is a clue found in the
 * field, this is knowledge loaded beforehand.
 *
 * The list is pasted as text, or read from a CSV file by the browser into the
 * same box, so parseMapPointText() is the only reader. It takes either
 *   - a header line (lat,lng,name,elevation,access,note in any order, English
 *     or Greek column names), or
 *   - positional lines: lat, lng, then optional name, altitude, access, note.
 * Comma, semicolon, tab or plain spaces separate the fields, a decimal comma
 * is understood, and a line that cannot be read is reported with its number
 * instead of sinking the whole import.
 */

/** One import is this many points at most; one mission holds this many in all. */
const MAP_POINT_MAX_IMPORT = 500;
const MAP_POINT_MAX_PER_MISSION = 2000;
/** The pasted text itself, in bytes: a 500-line list is ~50 KB. */
const MAP_POINT_MAX_TEXT_BYTES = 262144;
const MAP_POINT_ELEVATION_MIN = -500;
const MAP_POINT_ELEVATION_MAX = 9000;

/** Lower-case, no Greek accents and no final sigma: the form words are compared in. */
function mapPointNormalizeWord(string $s): string {
    $s = mb_strtolower(trim($s), 'UTF-8');
    return strtr($s, [
        'ά' => 'α', 'έ' => 'ε', 'ή' => 'η', 'ί' => 'ι', 'ϊ' => 'ι', 'ΐ' => 'ι',
        'ό' => 'ο', 'ύ' => 'υ', 'ϋ' => 'υ', 'ΰ' => 'υ', 'ώ' => 'ω', 'ς' => 'σ',
    ]);
}

/** 'foot' | 'vehicle' | 'both' for a word that says so, else null. */
function mapPointParseAccess(string $s): ?string {
    static $words = null;
    if ($words === null) {
        $words = [];
        $groups = [
            'foot'    => ['foot', 'walk', 'walking', 'on foot', 'πεζη', 'πεζοσ', 'πεζοπορια', 'ποδια', 'με τα ποδια', 'ποδι', 'με ποδια', 'μονο πεζη'],
            'vehicle' => ['vehicle', 'car', '4x4', 'drive', 'οχημα', 'με οχημα', 'αυτοκινητο', 'αμαξι', 'τζιπ', 'jeep', 'ανοδο με οχημα'],
            'both'    => ['both', 'και τα δυο', 'και τα 2', 'πεζη και οχημα', 'οχημα και πεζη', 'foot+vehicle', 'foot/vehicle'],
        ];
        foreach ($groups as $value => $list) {
            foreach ($list as $w) {
                $words[mapPointNormalizeWord($w)] = $value;
            }
        }
    }
    return $words[mapPointNormalizeWord($s)] ?? null;
}

/** A plain decimal number, with a point or a comma, and an optional degree sign. */
function mapPointParseNumber(string $s): ?float {
    $s = trim(str_replace('°', '', $s));
    if (preg_match('/^-?\d+([.,]\d+)?$/', $s) !== 1) {
        return null;
    }
    return (float) str_replace(',', '.', $s);
}

/**
 * Altitude in metres from «540», «540m», «540 μ.», or null when the text is not
 * one. Whether it is plausible is mapPointElevationInRange()'s business.
 */
function mapPointParseElevation(string $s): ?int {
    if (preg_match('/^(-?\d+(?:[.,]\d+)?)\s*(?:m|μ|μ\.|μέτρα|μετρα)?\.?$/iu', trim($s), $m) !== 1) {
        return null;
    }
    return (int) round((float) str_replace(',', '.', $m[1]));
}

function mapPointElevationInRange(int $m): bool {
    return $m >= MAP_POINT_ELEVATION_MIN && $m <= MAP_POINT_ELEVATION_MAX;
}

/**
 * The columns of a header line, ['lat' => 0, 'lng' => 1, ...], or null when the
 * line is not a header (it has no latitude and longitude column both).
 */
function mapPointHeaderColumns(array $fields): ?array {
    $names = [
        'lat'       => ['lat', 'latitude', 'πλατοσ', 'γεωγραφικο πλατοσ'],
        'lng'       => ['lng', 'lon', 'long', 'longitude', 'μηκοσ', 'γεωγραφικο μηκοσ'],
        'name'      => ['name', 'title', 'label', 'ονομα', 'τιτλοσ', 'ονομασια', 'σημειο'],
        'elevation' => ['elevation', 'ele', 'alt', 'altitude', 'υψομετρο', 'υψοσ'],
        'access'    => ['access', 'προσβαση', 'τροποσ προσβασησ'],
        'note'      => ['note', 'notes', 'description', 'desc', 'comment', 'σημειωση', 'σημειωσεισ', 'σχολιο', 'περιγραφη', 'οδηγιεσ'],
    ];
    $columns = [];
    foreach ($fields as $i => $f) {
        $w = mapPointNormalizeWord((string) $f);
        foreach ($names as $key => $list) {
            if (!isset($columns[$key]) && in_array($w, $list, true)) {
                $columns[$key] = $i;
            }
        }
    }
    return (isset($columns['lat'], $columns['lng'])) ? $columns : null;
}

/**
 * One line into its fields. Tab, then semicolon, then comma decide the
 * separator; when the first two fields still are not two numbers (a Greek
 * decimal comma split in the middle, or «35.33 24.21 Name» with spaces), the two
 * leading numbers are read off the line directly.
 */
function mapPointSplitLine(string $line): array {
    $delimiter = str_contains($line, "\t") ? "\t" : (str_contains($line, ';') ? ';' : ',');
    $fields = array_map('trim', str_getcsv($line, $delimiter, '"', ''));
    if (count($fields) >= 2 && mapPointParseNumber($fields[0]) !== null && mapPointParseNumber($fields[1]) !== null) {
        return $fields;
    }
    if (preg_match('/^(-?\d+(?:[.,]\d+)?)\s+(-?\d+(?:[.,]\d+)?)(?:[\s,;]+(.*))?$/u', $line, $m) === 1) {
        $rest = isset($m[3]) && trim($m[3]) !== '' ? array_map('trim', str_getcsv($m[3], ',', '"', '')) : [];
        return array_merge([$m[1], $m[2]], $rest);
    }
    return $fields;
}

/**
 * Read a pasted list.
 *
 * @return array{rows: array<int, array>, errors: array<int, array{line: int, text: string, reason: string}>}
 *   rows: ['line', 'lat', 'lng', 'name' (null if none), 'elevation' (null),
 *   'access' (null), 'note' (null)]. errors: reason is a translation key.
 */
function parseMapPointText(string $text): array {
    $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
    $rows = [];
    $errors = [];
    $columns = null;
    $first = true;

    foreach (preg_split('/\r\n|\r|\n/', (string) $text) as $i => $raw) {
        $lineNo = $i + 1;
        $line = trim($raw);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $fields = mapPointSplitLine($line);

        if ($first) {
            $first = false;
            $header = mapPointHeaderColumns($fields);
            if ($header !== null) {
                $columns = $header;
                continue;
            }
        }

        $error = static function (string $reason) use (&$errors, $lineNo, $line) {
            $errors[] = ['line' => $lineNo, 'text' => mb_substr($line, 0, 120), 'reason' => $reason];
        };

        $latIdx = $columns['lat'] ?? 0;
        $lngIdx = $columns['lng'] ?? 1;
        $lat = isset($fields[$latIdx]) ? mapPointParseNumber($fields[$latIdx]) : null;
        $lng = isset($fields[$lngIdx]) ? mapPointParseNumber($fields[$lngIdx]) : null;
        if ($lat === null || $lng === null) {
            $error('mp.err_line_coords');
            continue;
        }
        // Latitude cannot exceed 90: the two were written the other way round.
        if (abs($lat) > 90 && abs($lng) <= 90) {
            [$lat, $lng] = [$lng, $lat];
        }
        if (!commandPostValidLatLng($lat, $lng)) {
            $error('mp.err_line_range');
            continue;
        }

        $name = null;
        $elevation = null;
        $access = null;
        $noteParts = [];

        if ($columns !== null) {
            $get = fn(string $key) => isset($columns[$key], $fields[$columns[$key]]) ? trim($fields[$columns[$key]]) : '';
            $name = $get('name');
            if ($get('elevation') !== '') {
                $elevation = mapPointParseElevation($get('elevation'));
                if ($elevation === null || !mapPointElevationInRange($elevation)) {
                    $error('mp.err_line_elevation');
                    continue;
                }
            }
            $accessText = $get('access');
            if ($accessText !== '') {
                $access = mapPointParseAccess($accessText);
                if ($access === null) {
                    $noteParts[] = $accessText;
                }
            }
            if ($get('note') !== '') {
                $noteParts[] = $get('note');
            }
        } else {
            $rest = array_slice($fields, 2);
            // The first extra field is the name, unless it is a bare altitude:
            // «35.3,24.2,540» means no name, 540 m.
            if ($rest && $rest[0] !== '' && mapPointParseElevation($rest[0]) === null) {
                $name = array_shift($rest);
            } elseif ($rest && $rest[0] === '') {
                array_shift($rest);
            }
            foreach ($rest as $f) {
                if ($f === '') {
                    continue;
                }
                $asElevation = $elevation === null ? mapPointParseElevation($f) : null;
                if ($asElevation !== null) {
                    if (!mapPointElevationInRange($asElevation)) {
                        $error('mp.err_line_elevation');
                        continue 2;
                    }
                    $elevation = $asElevation;
                } elseif ($access === null && mapPointParseAccess($f) !== null) {
                    $access = mapPointParseAccess($f);
                } else {
                    $noteParts[] = $f;
                }
            }
        }

        $note = trim(implode(' · ', $noteParts));
        $rows[] = [
            'line'      => $lineNo,
            'lat'       => round($lat, 8),
            'lng'       => round($lng, 8),
            'name'      => $name !== null && trim($name) !== '' ? mb_substr(trim($name), 0, 120) : null,
            'elevation' => $elevation,
            'access'    => $access,
            'note'      => $note !== '' ? mb_substr($note, 0, 2000) : null,
        ];
    }

    return ['rows' => $rows, 'errors' => $errors];
}

/** Same point, same name: the key a re-pasted list is matched on. */
function mapPointDuplicateKey(string $name, float $lat, float $lng): string {
    return mb_strtolower(trim($name), 'UTF-8') . '|' . number_format($lat, 6, '.', '') . '|' . number_format($lng, 6, '.', '');
}

/**
 * Add parsed rows to a mission. A row that is already on the mission (same name
 * and place) is skipped, so pasting the same list twice changes nothing.
 *
 * @return array{added: int, duplicates: int, over_limit: bool}
 */
function importMissionMapPoints(int $missionId, array $rows, int $userId): array {
    $existing = dbFetchAll("SELECT name, lat, lng FROM mission_map_points WHERE mission_id = ?", [$missionId]);
    $have = [];
    foreach ($existing as $e) {
        $have[mapPointDuplicateKey((string) $e['name'], (float) $e['lat'], (float) $e['lng'])] = true;
    }
    $total = count($existing);
    $added = 0;
    $duplicates = 0;
    $overLimit = false;

    $pdo = db();
    $ownTransaction = !$pdo->inTransaction();
    if ($ownTransaction) {
        $pdo->beginTransaction();
    }
    try {
        foreach ($rows as $r) {
            $name = $r['name'] ?? null;
            if ($name === null || $name === '') {
                $name = t('mp.default_name', ['n' => $total + 1]);
            }
            $key = mapPointDuplicateKey($name, (float) $r['lat'], (float) $r['lng']);
            if (isset($have[$key])) {
                $duplicates++;
                continue;
            }
            if ($total >= MAP_POINT_MAX_PER_MISSION) {
                $overLimit = true;
                break;
            }
            dbInsert(
                "INSERT INTO mission_map_points (mission_id, name, lat, lng, elevation_m, access, note, created_by, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())",
                [$missionId, $name, $r['lat'], $r['lng'], $r['elevation'] ?? null, $r['access'] ?? null, $r['note'] ?? null, $userId]
            );
            $have[$key] = true;
            $total++;
            $added++;
        }
        if ($ownTransaction) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($ownTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return ['added' => $added, 'duplicates' => $duplicates, 'over_limit' => $overLimit];
}

/**
 * What the map draws, in the order the points were imported: the list as it was
 * pasted, which is also the order «Σύνδεση σημείων» joins them in. No raw
 * timestamps, the page compares by JSON.
 */
function loadMissionMapPoints(int $missionId, bool $withHistory = false): array {
    $rows = dbFetchAll(
        "SELECT id, name, lat, lng, elevation_m, access, note
         FROM mission_map_points WHERE mission_id = ? ORDER BY id ASC",
        [$missionId]
    );
    $visits = mapPointVisits($missionId);
    return array_map(static function (array $r) use ($visits, $withHistory) {
        $v = $visits[(int) $r['id']] ?? [];
        $status = 'free';
        foreach ($v as $visit) {
            if ($visit['state'] === 'active') {
                $status = 'active';
            } elseif ($status === 'free') {
                $status = 'done';
            }
        }
        $point = [
            'id'        => (int) $r['id'],
            'name'      => (string) $r['name'],
            'lat'       => (float) $r['lat'],
            'lng'       => (float) $r['lng'],
            'elevation' => $r['elevation_m'] !== null ? (int) $r['elevation_m'] : null,
            'access'    => $r['access'] ?: null,
            'note'      => $r['note'] !== null && $r['note'] !== '' ? (string) $r['note'] : null,
            // free (no team sent yet), active (a team is on it) or done (every
            // team sent has finished). Everybody sees this; the history below
            // is command's.
            'status'    => $status,
        ];
        if ($withHistory) {
            $point['visits'] = $v;
        }
        return $point;
    }, $rows);
}

/**
 * Every team that was sent to each point, oldest first, for command's view of a
 * point: [point_id => [visit, ...]]. A visit is the dispatch made from the
 * point plus its team's own steps, as the teams pressed them
 * (mission_dispatch_progress), with the note left on completing. Times are
 * 'H:i', or 'd/m H:i' when not today: a point can be revisited on another day.
 */
function mapPointVisits(int $missionId): array {
    if (!dbColumnExists('mission_dispatch_points', 'map_point_id')) {
        return [];
    }
    $rows = dbFetchAll(
        "SELECT d.id AS dispatch_id, d.map_point_id, d.team_id, d.created_at,
                mt.codename, mt.team_number,
                p.departed_at, p.arrived_at, p.completed_at, p.completed_note
         FROM mission_dispatch_points d
         LEFT JOIN mission_teams mt ON mt.id = d.team_id
         LEFT JOIN mission_dispatch_progress p ON p.dispatch_id = d.id AND p.scope_key = CONCAT('t', d.team_id)
         WHERE d.mission_id = ? AND d.map_point_id IS NOT NULL
         ORDER BY d.id",
        [$missionId]
    );
    $clock = static fn($ts) => $ts ? date(date('Y-m-d', strtotime($ts)) === date('Y-m-d') ? 'H:i' : 'd/m H:i', strtotime($ts)) : null;
    $out = [];
    foreach ($rows as $r) {
        $out[(int) $r['map_point_id']][] = [
            'dispatch_id' => (int) $r['dispatch_id'],
            'team_id'     => $r['team_id'] !== null ? (int) $r['team_id'] : null,
            'team_label'  => $r['codename'] !== null ? teamLabel($r['codename'], $r['team_number']) : '—',
            'state'       => $r['completed_at'] ? 'done' : 'active',
            'assigned'    => $clock($r['created_at']),
            'departed'    => $clock($r['departed_at']),
            'arrived'     => $clock($r['arrived_at']),
            'completed'   => $clock($r['completed_at']),
            'note'        => $r['completed_note'] !== null && $r['completed_note'] !== '' ? (string) $r['completed_note'] : null,
        ];
    }
    return $out;
}

/**
 * A short string that changes whenever the mission's points do. Rides every
 * poll in place of the points themselves (up to 2000 of them every five
 * seconds); a page whose copy differs asks mission-map-points.php for the list.
 */
function missionMapPointsVersion(int $missionId): string {
    $r = dbFetchOne(
        "SELECT COUNT(*) AS c, COALESCE(MAX(id), 0) AS mx, COALESCE(MAX(updated_at), '') AS mu
         FROM mission_map_points WHERE mission_id = ?",
        [$missionId]
    );
    $version = $r['c'] . '.' . $r['mx'] . '.' . preg_replace('/\D/', '', (string) $r['mu']);
    // A team being sent to a point, or pressing a step on it, changes what the
    // map shows (pin colour, history) without touching the point itself.
    if (dbColumnExists('mission_dispatch_points', 'map_point_id')) {
        $d = dbFetchOne(
            "SELECT COUNT(DISTINCT d.id) AS c, COALESCE(MAX(d.id), 0) AS mx,
                    COALESCE(MAX(p.departed_at), '') AS a, COALESCE(MAX(p.arrived_at), '') AS b, COALESCE(MAX(p.completed_at), '') AS f
             FROM mission_dispatch_points d
             LEFT JOIN mission_dispatch_progress p ON p.dispatch_id = d.id
             WHERE d.mission_id = ? AND d.map_point_id IS NOT NULL",
            [$missionId]
        );
        $version .= '/' . $d['c'] . '.' . $d['mx'] . '.' . preg_replace('/\D/', '', $d['a'] . $d['b'] . $d['f']);
    }
    // The shared road route (v3.365.0): drawn, redrawn or taken off.
    if (dbColumnExists('mission_map_point_routes', 'mission_id')) {
        $r = dbFetchOne("SELECT created_at, active FROM mission_map_point_routes WHERE mission_id = ?", [$missionId]);
        $version .= '/r' . ($r ? preg_replace('/\D/', '', (string) $r['created_at']) . 'a' . (int) $r['active'] : '0');
    }
    return $version;
}

/** Change one point's details. False when the point is not on this mission. */
function updateMissionMapPoint(int $missionId, int $id, string $name, ?int $elevation, ?string $access, ?string $note, int $userId): bool {
    $name = mb_substr(trim($name), 0, 120);
    if ($name === '') {
        $name = t('mp.default_name', ['n' => $id]);
    }
    if (!in_array($access, ['foot', 'vehicle', 'both'], true)) {
        $access = null;
    }
    if ($elevation !== null && !mapPointElevationInRange($elevation)) {
        $elevation = null;
    }
    $note = $note !== null ? mb_substr(trim($note), 0, 2000) : null;
    $affected = dbExecute(
        "UPDATE mission_map_points
         SET name = ?, elevation_m = ?, access = ?, note = ?, updated_by = ?, updated_at = NOW()
         WHERE id = ? AND mission_id = ?",
        [$name, $elevation, $access, $note !== '' ? $note : null, $userId, $id, $missionId]
    );
    // MySQL reports 0 for an update that changed nothing; the point still exists.
    return $affected > 0 || (bool) dbFetchValue("SELECT 1 FROM mission_map_points WHERE id = ? AND mission_id = ?", [$id, $missionId]);
}

function deleteMissionMapPoint(int $missionId, int $id): bool {
    return dbExecute("DELETE FROM mission_map_points WHERE id = ? AND mission_id = ?", [$id, $missionId]) > 0;
}

/** Remove every point of the mission; how many went. */
function clearMissionMapPoints(int $missionId): int {
    return (int) dbExecute("DELETE FROM mission_map_points WHERE mission_id = ?", [$missionId]);
}

/** One connection joins at most this many points (so one less legs). */
const MAP_POINT_LINK_MAX = 25;
/** Routed legs one person may ask for, over the window below (two full chains). */
const MAP_POINT_LINK_RATE_MAX = 60;
const MAP_POINT_LINK_RATE_WINDOW = 600;

/**
 * The points a connection is made from, in import order: all of them, or only
 * those that can be reached on foot ('foot': foot and both) or by vehicle
 * ('vehicle': vehicle and both). A point with no access stated is only in 'all'.
 */
function mapPointsForLinking(int $missionId, string $filter): array {
    $points = loadMissionMapPoints($missionId);
    if ($filter === 'foot' || $filter === 'vehicle') {
        $points = array_values(array_filter($points, fn($p) => $p['access'] === $filter || $p['access'] === 'both'));
    }
    return $points;
}

/**
 * Route each point to the next, by road or path (Google), not as the crow flies.
 *
 * $mode 'foot' asks Google for walking routes, 'vehicle' for driving ones. One
 * request per leg, run in parallel by routeDistanceRunJobs(); a leg Google
 * finds no way for comes back with no shape and is counted in `unrouted`
 * instead of being drawn as a straight line. $runner has routeDistanceRunJobs()'s
 * shape and is only there so a test can stand in for Google.
 *
 * @param array<int, array> $points loadMissionMapPoints() rows, at most MAP_POINT_LINK_MAX
 * @return array{legs: array<int, array>, meters: int, minutes: int, unrouted: int}
 */
function mapPointsLinkLegs(array $points, string $mode, string $apiKey, ?callable $runner = null): array {
    $points = array_values(array_slice($points, 0, MAP_POINT_LINK_MAX));
    $provider = ['name' => 'google', 'mode' => $mode === 'vehicle' ? 'driving' : 'walking', 'key' => $apiKey];
    $jobs = [];
    for ($i = 0; $i + 1 < count($points); $i++) {
        $jobs["leg:$i"] = [
            'provider' => $provider,
            'leg'      => [$points[$i]['lat'], $points[$i]['lng'], $points[$i + 1]['lat'], $points[$i + 1]['lng']],
            'geometry' => true,
        ];
    }
    $failed = [];
    $runner = $runner ?? 'routeDistanceRunJobs';
    $routed = $jobs ? $runner($jobs, $failed) : [];

    $legs = [];
    $meters = 0;
    $minutes = 0;
    $unrouted = 0;
    for ($i = 0; $i + 1 < count($points); $i++) {
        $r = $routed["leg:$i"] ?? null;
        $leg = ['from_id' => $points[$i]['id'], 'to_id' => $points[$i + 1]['id'], 'meters' => null, 'minutes' => null, 'points' => null];
        if ($r !== null && !empty($r['points'])) {
            $leg['meters'] = (int) $r['meters'];
            $leg['minutes'] = (int) $r['minutes'];
            $leg['points'] = routeDistanceSimplify($r['points']);
            $meters += $leg['meters'];
            $minutes += $leg['minutes'];
        } else {
            $unrouted++;
        }
        $legs[] = $leg;
    }
    return ['legs' => $legs, 'meters' => $meters, 'minutes' => $minutes, 'unrouted' => $unrouted];
}

/** One assignment sends at most this many points to a team. */
const MAP_POINT_ASSIGN_MAX = 50;

/**
 * Send imported points to a team: each becomes an ordinary dispatch point for
 * that team (label = the point's name, linked back by map_point_id), so the
 * team gets the same notice and popup as for any dispatch, presses the same
 * «Ξεκινάω / Έφτασα / Ολοκληρώθηκε», and command staff are alerted the same way.
 * The point stays available: another team can be sent later, each with its own
 * record. A point the same team already has open is skipped.
 *
 * One notification for the whole batch rather than one per point: forty points
 * must not be forty alarms on one phone.
 *
 * @param array $mission the open mission's row (id, title)
 * @param array<int, int> $pointIds in the order to send them
 * @return array{error?: string, assigned: int, skipped: int, dispatch_ids: array<int, int>}
 */
function assignMapPointsToTeam(array $mission, int $teamId, array $pointIds, int $userId, string $userName): array {
    $missionId = (int) $mission['id'];
    $team = dbFetchOne("SELECT id, codename, team_number FROM mission_teams WHERE id = ? AND mission_id = ?", [$teamId, $missionId]);
    if (!$team) {
        return ['error' => t('common.team_not_found'), 'assigned' => 0, 'skipped' => 0, 'dispatch_ids' => []];
    }
    $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', $pointIds)))), 0, MAP_POINT_ASSIGN_MAX);
    if (!$ids) {
        return ['error' => t('mp.err_assign_none'), 'assigned' => 0, 'skipped' => 0, 'dispatch_ids' => []];
    }
    $in = implode(',', array_fill(0, count($ids), '?'));
    $points = [];
    foreach (dbFetchAll("SELECT id, name, lat, lng FROM mission_map_points WHERE mission_id = ? AND id IN ($in)", array_merge([$missionId], $ids)) as $p) {
        $points[(int) $p['id']] = $p;
    }

    $dispatchIds = [];
    $labels = [];
    $skipped = 0;
    foreach ($ids as $id) {
        if (!isset($points[$id])) {
            continue;
        }
        $open = (int) dbFetchValue(
            "SELECT COUNT(*) FROM mission_dispatch_points d
             LEFT JOIN mission_dispatch_progress p ON p.dispatch_id = d.id AND p.scope_key = CONCAT('t', d.team_id)
             WHERE d.map_point_id = ? AND d.team_id = ? AND p.completed_at IS NULL",
            [$id, $teamId]
        );
        if ($open > 0) {
            $skipped++;
            continue;
        }
        $dispatchId = (int) dbInsert(
            "INSERT INTO mission_dispatch_points (mission_id, team_id, type, geo, label, map_point_id, created_by, created_at)
             VALUES (?, ?, 'point', ?, ?, ?, ?, NOW())",
            [$missionId, $teamId, json_encode(['lat' => (float) $points[$id]['lat'], 'lng' => (float) $points[$id]['lng']]), mb_substr((string) $points[$id]['name'], 0, 255), $id, $userId]
        );
        logAudit('create_mission_dispatch', 'mission_dispatch_points', $dispatchId, null, ['mission_id' => $missionId, 'team_id' => $teamId, 'type' => 'point', 'map_point_id' => $id]);
        $dispatchIds[] = $dispatchId;
        $labels[] = (string) $points[$id]['name'];
    }

    if ($dispatchIds) {
        notifyMapPointsAssigned($mission, $team, $dispatchIds, $labels, $userId, $userName);
    }
    return ['assigned' => count($dispatchIds), 'skipped' => $skipped, 'dispatch_ids' => $dispatchIds];
}

/**
 * Tell the team (and the admins watching) that points were assigned. Mirrors
 * mission-dispatch.php's own create notification: the team gets the banner and
 * sound and its popup opens on the first point; a system administrator who is
 * not on the team gets the same as a third-person line, without the banner.
 */
function notifyMapPointsAssigned(array $mission, array $team, array $dispatchIds, array $labels, int $actorId, string $actorName): void {
    $missionId = (int) $mission['id'];
    $teamId = (int) $team['id'];
    $teamLabel = teamLabel($team['codename'], $team['team_number']);
    $n = count($dispatchIds);
    $shown = array_slice($labels, 0, 3);
    $labelList = implode(', ', $shown) . ($n > count($shown) ? '…' : '');
    $warRoomUrl = rtrim(BASE_URL, '/') . '/war-room.php?id=' . $missionId;

    $recipientIds = actionRoomNotifyRecipientIds($missionId, $teamId, $actorId);
    $langs = getUserLanguages($recipientIds);
    foreach ($recipientIds as $recipientId) {
        $lang = $langs[$recipientId] ?? DEFAULT_LANGUAGE;
        $message = $n === 1
            ? t('dispatch.create_notify_message', ['mission' => $mission['title'], 'kind' => t('dispatch.a_point', [], $lang), 'label_suffix' => ' (' . $labels[0] . ')'], $lang)
            : t('mp.assign_notify_message', ['mission' => $mission['title'], 'n' => $n, 'labels' => $labelList], $lang);
        sendNotification($recipientId, t('dispatch.create_notify_title_point', [], $lang), $message, 'info', 'mission_dispatch_point', [
            'url' => $warRoomUrl,
            'tag' => 'dispatch-point-mission-' . $missionId,
            'bannerMission' => $missionId,
            'dispatchId' => (int) $dispatchIds[0],
        ]);
    }

    $bystanders = array_values(array_diff(getSystemAdminIds($actorId), $recipientIds));
    if ($bystanders) {
        $fyiLangs = getUserLanguages($bystanders);
        foreach ($bystanders as $adminId) {
            $lang = $fyiLangs[$adminId] ?? DEFAULT_LANGUAGE;
            $message = t('dispatch.create_admin_fyi', [
                'actor' => $actorName,
                'kind' => t('dispatch.a_point', [], $lang),
                'mission' => $mission['title'],
                'label_suffix' => ' (' . $labelList . ')',
                'target' => $teamLabel,
            ], $lang);
            sendNotification($adminId, t('dispatch.create_notify_title_point', [], $lang), $message, 'info', '', [
                'url' => $warRoomUrl,
                'tag' => 'dispatch-point-mission-' . $missionId,
            ]);
        }
    }
}

/**
 * Keep the route command drew as THE route of the mission, for everybody to see.
 * One per mission: drawing again replaces it. $result is mapPointsLinkLegs()'s
 * answer plus the mode, filter and counts the endpoint knows.
 */
function saveMapPointRoute(int $missionId, array $result, int $userId): void {
    dbExecute("DELETE FROM mission_map_point_routes WHERE mission_id = ?", [$missionId]);
    dbInsert(
        "INSERT INTO mission_map_point_routes
            (mission_id, mode, filter_kind, total_points, used_points, meters, minutes, unrouted, legs, created_by, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
        [
            $missionId, $result['mode'] === 'vehicle' ? 'vehicle' : 'foot', (string) $result['filter'],
            (int) $result['total'], (int) $result['used'], (int) $result['meters'], (int) $result['minutes'], (int) $result['unrouted'],
            json_encode($result['legs'], JSON_UNESCAPED_UNICODE), $userId,
        ]
    );
}

/**
 * Switch the saved route off or on for everybody, without drawing it again: the
 * route stays saved, so switching it back on costs nothing. True when it exists.
 */
function setMapPointRouteActive(int $missionId, bool $active): bool {
    dbExecute("UPDATE mission_map_point_routes SET active = ? WHERE mission_id = ?", [$active ? 1 : 0, $missionId]);
    return (bool) dbFetchValue("SELECT 1 FROM mission_map_point_routes WHERE mission_id = ?", [$missionId]);
}

function clearMapPointRoute(int $missionId): bool {
    return dbExecute("DELETE FROM mission_map_point_routes WHERE mission_id = ?", [$missionId]) > 0;
}

/**
 * The mission's shared route as the page draws it, or null when there is none.
 * A route whose points have since been deleted is not a true picture of the
 * ground any more, so it is dropped here rather than shown: coordinates of a
 * point never change, only its existence does.
 */
function loadMapPointRoute(int $missionId): ?array {
    $row = dbFetchOne(
        "SELECT r.*, u.name AS by_name FROM mission_map_point_routes r LEFT JOIN users u ON u.id = r.created_by WHERE r.mission_id = ?",
        [$missionId]
    );
    if (!$row) {
        return null;
    }
    $legs = json_decode((string) $row['legs'], true);
    if (!is_array($legs)) {
        clearMapPointRoute($missionId);
        return null;
    }
    $ids = [];
    foreach ($legs as $leg) {
        $ids[(int) $leg['from_id']] = true;
        $ids[(int) $leg['to_id']] = true;
    }
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $have = (int) dbFetchValue("SELECT COUNT(*) FROM mission_map_points WHERE mission_id = ? AND id IN ($in)", array_merge([$missionId], array_keys($ids)));
        if ($have !== count($ids)) {
            clearMapPointRoute($missionId);
            return null;
        }
    }
    return [
        'stamp'    => preg_replace('/\D/', '', (string) $row['created_at']),
        'mode'     => $row['mode'],
        'filter'   => $row['filter_kind'],
        'total'    => (int) $row['total_points'],
        'used'     => (int) $row['used_points'],
        'meters'   => (int) $row['meters'],
        'minutes'  => (int) $row['minutes'],
        'unrouted' => (int) $row['unrouted'],
        'active'   => (bool) $row['active'],
        'legs'     => $legs,
        'by'       => $row['by_name'],
    ];
}
