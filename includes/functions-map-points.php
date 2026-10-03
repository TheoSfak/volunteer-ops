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
 * What the map draws. No raw timestamps, the page compares by JSON.
 */
function loadMissionMapPoints(int $missionId): array {
    $rows = dbFetchAll(
        "SELECT id, name, lat, lng, elevation_m, access, note
         FROM mission_map_points WHERE mission_id = ? ORDER BY name ASC, id ASC",
        [$missionId]
    );
    return array_map(static fn(array $r) => [
        'id'        => (int) $r['id'],
        'name'      => (string) $r['name'],
        'lat'       => (float) $r['lat'],
        'lng'       => (float) $r['lng'],
        'elevation' => $r['elevation_m'] !== null ? (int) $r['elevation_m'] : null,
        'access'    => $r['access'] ?: null,
        'note'      => $r['note'] !== null && $r['note'] !== '' ? (string) $r['note'] : null,
    ], $rows);
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
    return $r['c'] . '.' . $r['mx'] . '.' . preg_replace('/\D/', '', (string) $r['mu']);
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
