<?php
/**
 * VolunteerOps - Action Room geography export (GPX / KML / GeoJSON)
 *
 * The Action Room's whole operational picture - GPS trails, search areas and
 * their sectors, restricted zones, route orders, dispatch points, points of
 * interest and the missing person's last-seen fix - in the three formats
 * other services actually read: GPX (Garmin/handheld GPS), KML (Google
 * Earth), GeoJSON (QGIS / CalTopo / anything modern). Until this file
 * existed the only things that could leave the Action Room were a CSV of the
 * activity feed, a CSV of the chat, and a browser-printed PDF - none of
 * which another agency can put on a map.
 *
 * URL: /exports/export-mission-geo.php?mission_id=N&format=gpx|kml|geojson
 *
 * Command-staff only, unlike exports/export-mission-activity.php which also
 * lets an approved participant download the feed they can already read on
 * screen. Two reasons: this bundles EVERY volunteer's full historical GPS
 * trail, which on screen already sits behind the same $canManageWarRoom gate
 * (#trailFilterBar in war-room.php), and handing a mission's geography to an
 * outside agency is a command decision, not a field one.
 *
 * Deliberately carries no medical data. mission_incidents has lat/lng and
 * would map cleanly, but it also carries patient name/phone/age, and a
 * downloaded file has none of the masking (maskPatientName()) or the
 * staff-only gating the live panel applies to those. Operational geography
 * only - if incident locations are ever wanted here they need their own
 * explicit decision about what a file leaving the building may contain.
 */

require_once __DIR__ . '/../bootstrap.php';
requireLogin();

$missionId = (int) get('mission_id');
if (!$missionId) {
    setFlash('error', t('common.mission_not_found'));
    redirect('dashboard.php');
}

$format = strtolower(trim((string) get('format', 'gpx')));
if (!in_array($format, ['gpx', 'kml', 'geojson'], true)) {
    $format = 'gpx';
}

$user = getCurrentUser();
$mission = dbFetchOne("SELECT * FROM missions WHERE id = ? AND deleted_at IS NULL", [$missionId]);
if (!$mission) {
    setFlash('error', t('common.mission_not_found'));
    redirect('dashboard.php');
}

$canManageWarRoom = canManageActionRoom($mission['responsible_user_id'] ? (int) $mission['responsible_user_id'] : null, (int) $user['id']);
if (!$canManageWarRoom) {
    setFlash('error', t('wr.access_denied'));
    redirect('dashboard.php');
}

// ─── Palette ────────────────────────────────────────────────────────────────
// Per-kind fallback colours, used whenever a feature has no team colour of
// its own to inherit. Chosen to match what the live map already paints so a
// printed KML doesn't read as a different mission than the screen it came
// from.
const GEO_COLORS = [
    'trail'      => '#6c757d',
    'route'      => '#0d6efd',
    'area'       => '#0dcaf0',
    'sector'     => '#198754',
    'restricted' => '#dc3545',
    'poi'        => '#fd7e14',
    'dispatch'   => '#6f42c1',
    'subject'    => '#dc3545',
];

/**
 * Every exported feature is normalised into this one shape before any format
 * is rendered, so the three renderers below stay pure formatters and a new
 * dataset only ever has to be added in one place.
 *
 *   kind   - machine key, drives the folder/layer name and the fallback colour
 *   geom   - 'Point' | 'Line' | 'Polygon'
 *   coords - list of [lat, lng] pairs (a Point is a single-pair list). Rings
 *            are stored OPEN here, exactly as mission_search_areas.geo etc.
 *            store them; each renderer closes them itself, since GeoJSON and
 *            KML both require an explicit closing vertex and GPX does not.
 *   times  - parallel to coords, epoch seconds or null; only trails have it
 */
function geoFeature(string $kind, string $geom, array $coords, string $name, string $desc, ?string $color = null, ?int $time = null, array $props = [], array $times = []): array {
    return [
        'kind'   => $kind,
        'geom'   => $geom,
        'coords' => $coords,
        'name'   => $name,
        'desc'   => $desc,
        'color'  => $color ?: (GEO_COLORS[$kind] ?? '#0d6efd'),
        'time'   => $time,
        'props'  => $props,
        'times'  => $times,
    ];
}

/** Human label for a kind - the KML folder name and the GeoJSON `layer`. */
function geoKindLabel(string $kind): string {
    return t('geo.layer.' . $kind);
}

/** A stored ring is open; every closed-geometry format needs it shut. */
function geoClosedRing(array $coords): array {
    $n = count($coords);
    if ($n < 2) {
        return $coords;
    }
    $first = $coords[0];
    $last = $coords[$n - 1];
    if ((float) $first[0] !== (float) $last[0] || (float) $first[1] !== (float) $last[1]) {
        $coords[] = $first;
    }
    return $coords;
}

function geoXml(?string $s): string {
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/** Coordinates go out at 6 decimals (~11cm) - more is storage noise, not precision. */
function geoNum(float $n): string {
    return rtrim(rtrim(number_format($n, 6, '.', ''), '0'), '.') ?: '0';
}

/** GPX/KML timestamps are UTC; PHP here runs on Europe/Athens. */
function geoIsoTime(?int $epoch): ?string {
    return $epoch ? gmdate('Y-m-d\TH:i:s\Z', $epoch) : null;
}

/** KML wants aabbggrr, the reverse of the #rrggbb every other layer uses. */
function geoKmlColor(string $hex, string $alpha = 'ff'): string {
    $hex = ltrim($hex, '#');
    if (strlen($hex) !== 6 || !ctype_xdigit($hex)) {
        $hex = '0d6efd';
    }
    return $alpha . substr($hex, 4, 2) . substr($hex, 2, 2) . substr($hex, 0, 2);
}

/** Stable, XML-safe KML style id for one colour+shape pair. */
function geoKmlStyleId(string $color, string $shape): string {
    return 'vo_' . $shape . '_' . strtolower(ltrim($color, '#'));
}

/** KML coordinates are lng,lat[,alt] - the opposite order to everything stored here. */
function geoKmlCoords(array $coords): string {
    $parts = [];
    foreach ($coords as $c) {
        $parts[] = geoNum((float) $c[1]) . ',' . geoNum((float) $c[0]) . ',0';
    }
    return implode(' ', $parts);
}

// ─── Gather ─────────────────────────────────────────────────────────────────
$features = [];

// Volunteer GPS trails. Reuses the live map's own loader rather than a fresh
// query so an exported track can never disagree with the trail the command
// screen drew - and it is the one loader whose timestamps survive the trip
// ('ts' is real epoch seconds, added for the replay scrubber; every other
// loader in functions-warroom.php pre-formats to a yearless 'd/m H:i' that a
// GPX <time> element cannot use).
//
// Auto-captured pings are included ($includeAuto = true) even though the
// on-screen filter defaults them off. On screen the default is about
// readability - an auto trail is dense and hides the manual marks. A file
// handed to another agency is a coverage record, and the auto pings ARE the
// evidence of coverage.
$trails = loadMissionTrailForMission($missionId, 0, true);
foreach ($trails as $trail) {
    if (count($trail['points']) < 2) {
        continue; // a single fix is not a track; it is already a live pin
    }
    $coords = array_map(fn($p) => [$p['lat'], $p['lng']], $trail['points']);
    $times = array_map(fn($p) => $p['ts'] ?? null, $trail['points']);
    $features[] = geoFeature(
        'trail', 'Line', $coords,
        $trail['name'],
        t('geo.desc.trail', ['points' => count($coords)]),
        $trail['team_color'] ?: null,
        null,
        ['user_id' => $trail['user_id'], 'points' => count($coords)],
        $times
    );
}

// Search areas (the outer zone an admin draws) and the sectors they are cut
// into. Queried directly rather than through loadMissionSearchAreasForUser()
// / loadMissionSectorsForUser(): those two carry per-user permission flags,
// translated status labels, building/floor checklists and photo rollups that
// an export has no use for, and their timestamps are the yearless display
// format described above.
$areaRows = dbFetchAll(
    "SELECT id, label, geo, created_at FROM mission_search_areas WHERE mission_id = ? ORDER BY created_at",
    [$missionId]
);
foreach ($areaRows as $row) {
    $geo = json_decode($row['geo'], true);
    if (!is_array($geo) || count($geo) < 3) {
        continue;
    }
    $features[] = geoFeature(
        'area', 'Polygon', $geo,
        $row['label'],
        t('geo.desc.area', ['created' => formatDateTime($row['created_at'])]),
        null,
        strtotime($row['created_at']),
        ['area_id' => (int) $row['id']]
    );
}

$sectorRows = dbFetchAll(
    "SELECT s.id, s.area_id, s.label, s.geo, s.status, s.status_updated_at, s.created_at,
            mt.codename, mt.team_number, mt.color AS team_color
     FROM mission_search_sectors s
     LEFT JOIN mission_teams mt ON mt.id = s.team_id
     WHERE s.mission_id = ? ORDER BY s.created_at",
    [$missionId]
);
foreach ($sectorRows as $row) {
    $geo = json_decode($row['geo'], true);
    if (!is_array($geo) || count($geo) < 3) {
        continue;
    }
    $teamName = $row['codename'] !== null ? teamLabel($row['codename'], $row['team_number']) : t('sector.unassigned_option');
    $features[] = geoFeature(
        'sector', 'Polygon', $geo,
        $row['label'],
        t('geo.desc.sector', [
            'status' => sectorStatusLabel($row['status']),
            'team'   => $teamName,
        ]),
        $row['team_color'] ?: null,
        strtotime($row['created_at']),
        [
            'sector_id' => (int) $row['id'],
            'area_id'   => (int) $row['area_id'],
            'status'    => $row['status'],
            'team'      => $teamName,
        ]
    );
}

// Restricted / no-go zones.
$restrictedRows = dbFetchAll(
    "SELECT id, label, geo, created_at FROM mission_restricted_areas WHERE mission_id = ? ORDER BY created_at",
    [$missionId]
);
foreach ($restrictedRows as $row) {
    $geo = json_decode($row['geo'], true);
    if (!is_array($geo) || count($geo) < 3) {
        continue;
    }
    $features[] = geoFeature(
        'restricted', 'Polygon', $geo,
        $row['label'],
        t('geo.desc.restricted'),
        null,
        strtotime($row['created_at']),
        ['restricted_area_id' => (int) $row['id']]
    );
}

// Route orders. A GPX <rte> is exactly this - an ordered list of named
// waypoints a team is meant to walk - so routes are the one dataset that
// maps onto a native GPX type without any convention needed.
$routeRows = dbFetchAll(
    "SELECT r.id, r.title, r.is_closed_loop, r.created_at, r.completed_at, r.cancelled_at,
            mt.codename, mt.team_number, mt.color AS team_color
     FROM mission_routes r
     LEFT JOIN mission_teams mt ON mt.id = r.team_id
     WHERE r.mission_id = ? ORDER BY r.created_at",
    [$missionId]
);
if ($routeRows) {
    $routeIds = array_map(fn($r) => (int) $r['id'], $routeRows);
    $routePlaceholders = implode(',', array_fill(0, count($routeIds), '?'));
    $waypointRows = dbFetchAll(
        "SELECT route_id, seq, lat, lng, label FROM mission_route_waypoints
         WHERE route_id IN ($routePlaceholders) ORDER BY route_id, seq",
        $routeIds
    );
    $waypointsByRoute = [];
    foreach ($waypointRows as $w) {
        $waypointsByRoute[(int) $w['route_id']][] = $w;
    }
    foreach ($routeRows as $row) {
        $waypoints = $waypointsByRoute[(int) $row['id']] ?? [];
        if (count($waypoints) < 2) {
            continue;
        }
        $coords = array_map(fn($w) => [(float) $w['lat'], (float) $w['lng']], $waypoints);
        if ($row['is_closed_loop']) {
            $coords = geoClosedRing($coords);
        }
        $teamName = $row['codename'] !== null ? teamLabel($row['codename'], $row['team_number']) : t('sector.unassigned_option');
        $status = $row['cancelled_at'] ? t('geo.route_status.cancelled')
            : ($row['completed_at'] ? t('geo.route_status.completed') : t('geo.route_status.active'));
        $features[] = geoFeature(
            'route', 'Line', $coords,
            $row['title'] ?: t('geo.layer.route') . ' #' . (int) $row['id'],
            t('geo.desc.route', ['team' => $teamName, 'status' => $status, 'points' => count($coords)]),
            $row['team_color'] ?: null,
            strtotime($row['created_at']),
            [
                'route_id'  => (int) $row['id'],
                'team'      => $teamName,
                'status'    => $status,
                'waypoints' => array_map(fn($w) => ['seq' => (int) $w['seq'], 'label' => $w['label']], $waypoints),
            ]
        );
    }
}

// Dispatch points - a single pin OR a drawn area, in the same table, told
// apart by `type` (see mission-dispatch.php: a point stores {lat,lng}, a
// polygon stores the same [[lat,lng],...] ring every other shape here uses).
$dispatchRows = dbFetchAll(
    "SELECT d.id, d.type, d.geo, d.label, d.created_at,
            mt.codename, mt.team_number, mt.color AS team_color
     FROM mission_dispatch_points d
     LEFT JOIN mission_teams mt ON mt.id = d.team_id
     WHERE d.mission_id = ? ORDER BY d.created_at",
    [$missionId]
);
foreach ($dispatchRows as $row) {
    $geo = json_decode($row['geo'], true);
    if (!is_array($geo)) {
        continue;
    }
    $teamName = $row['codename'] !== null ? teamLabel($row['codename'], $row['team_number']) : t('common.all_teams');
    $name = $row['label'] ?: t('geo.layer.dispatch') . ' #' . (int) $row['id'];
    $desc = t('geo.desc.dispatch', ['team' => $teamName, 'created' => formatDateTime($row['created_at'])]);
    if ($row['type'] === 'point') {
        if (!isset($geo['lat'], $geo['lng'])) {
            continue;
        }
        $features[] = geoFeature(
            'dispatch', 'Point', [[(float) $geo['lat'], (float) $geo['lng']]],
            $name, $desc, $row['team_color'] ?: null, strtotime($row['created_at']),
            ['dispatch_id' => (int) $row['id'], 'team' => $teamName]
        );
    } elseif (count($geo) >= 3) {
        $features[] = geoFeature(
            'dispatch', 'Polygon', $geo,
            $name, $desc, $row['team_color'] ?: null, strtotime($row['created_at']),
            ['dispatch_id' => (int) $row['id'], 'team' => $teamName]
        );
    }
}

// Points of interest - the field clue log. Photos themselves are not
// exported (a KML with embedded imagery is a different, much heavier
// feature); the note text and who reported it carry the meaning.
$poiRows = dbFetchAll(
    "SELECT p.id, p.lat, p.lng, p.checked_at, p.created_at, cb.name AS checked_by_name
     FROM mission_points_of_interest p
     LEFT JOIN users cb ON cb.id = p.checked_by
     WHERE p.mission_id = ? ORDER BY p.created_at",
    [$missionId]
);
if ($poiRows) {
    $poiIds = array_map(fn($p) => (int) $p['id'], $poiRows);
    $poiPlaceholders = implode(',', array_fill(0, count($poiIds), '?'));
    $poiPhotoRows = dbFetchAll(
        "SELECT ph.poi_id, ph.poi_note, u.name AS reporter_name
         FROM mission_photos ph JOIN users u ON u.id = ph.user_id
         WHERE ph.poi_id IN ($poiPlaceholders) ORDER BY ph.created_at",
        $poiIds
    );
    $poiMetaById = [];
    foreach ($poiPhotoRows as $row) {
        $poiId = (int) $row['poi_id'];
        if (!isset($poiMetaById[$poiId])) {
            $poiMetaById[$poiId] = ['reporters' => [], 'notes' => []];
        }
        if (!in_array($row['reporter_name'], $poiMetaById[$poiId]['reporters'], true)) {
            $poiMetaById[$poiId]['reporters'][] = $row['reporter_name'];
        }
        if (!empty($row['poi_note']) && !in_array($row['poi_note'], $poiMetaById[$poiId]['notes'], true)) {
            $poiMetaById[$poiId]['notes'][] = $row['poi_note'];
        }
    }
    foreach ($poiRows as $row) {
        $poiId = (int) $row['id'];
        $meta = $poiMetaById[$poiId] ?? ['reporters' => [], 'notes' => []];
        $descParts = [t('geo.desc.poi', [
            'reporters' => $meta['reporters'] ? implode(', ', $meta['reporters']) : '—',
            'created'   => formatDateTime($row['created_at']),
        ])];
        if ($meta['notes']) {
            $descParts[] = implode(' | ', $meta['notes']);
        }
        $descParts[] = $row['checked_at']
            ? t('geo.desc.poi_checked', ['by' => $row['checked_by_name'] ?: '—', 'at' => formatDateTime($row['checked_at'])])
            : t('geo.desc.poi_unchecked');
        $features[] = geoFeature(
            'poi', 'Point', [[(float) $row['lat'], (float) $row['lng']]],
            t('geo.layer.poi') . ' #' . $poiId,
            implode("\n", $descParts),
            null,
            strtotime($row['created_at']),
            [
                'poi_id'    => $poiId,
                'checked'   => $row['checked_at'] !== null,
                'reporters' => $meta['reporters'],
                'notes'     => $meta['notes'],
            ]
        );
    }
}

// The missing person's last-seen fix - the single most important coordinate
// on a search handover, and the anchor every LPB ring is drawn from.
$missingPerson = loadMissingPersonForMission($missionId);
if ($missingPerson && $missingPerson['last_seen_lat'] !== null && $missingPerson['last_seen_lng'] !== null) {
    $features[] = geoFeature(
        'subject', 'Point',
        [[$missingPerson['last_seen_lat'], $missingPerson['last_seen_lng']]],
        t('geo.subject_name', ['name' => $missingPerson['full_name']]),
        t('geo.desc.subject', [
            'place' => $missingPerson['last_seen_label'] ?: '—',
            'at'    => $missingPerson['last_seen_at'] ?: '—',
        ]),
        null,
        null,
        [
            'full_name'    => $missingPerson['full_name'],
            'last_seen_at' => $missingPerson['last_seen_at'],
            'category'     => $missingPerson['subject_category'],
        ]
    );
}

// ─── Emit ───────────────────────────────────────────────────────────────────
if (ob_get_level()) {
    ob_end_clean();
}

$docTitle = t('geo.doc_title', ['mission' => $mission['title']]);
$docDesc = t('geo.doc_desc', [
    'mission'  => $mission['title'],
    'location' => $mission['location'] ?: '—',
    'exported' => formatDateTime(date('Y-m-d H:i:s')),
    'by'       => $user['name'],
]);

$dateStr = date('Y-m-d');
$asciiSlug = preg_replace('/[^A-Za-z0-9]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $mission['title']));
$asciiSlug = trim($asciiSlug, '_') ?: 'mission';
$fallbackName = 'geo_' . $asciiSlug . '_' . $dateStr . '.' . $format;
$utf8Name = t('geo.file_prefix') . '_' . $mission['title'] . '_' . $dateStr . '.' . $format;

$mimeByFormat = [
    'gpx'     => 'application/gpx+xml; charset=utf-8',
    'kml'     => 'application/vnd.google-earth.kml+xml; charset=utf-8',
    'geojson' => 'application/geo+json; charset=utf-8',
];
header('Content-Type: ' . $mimeByFormat[$format]);
header('Content-Disposition: attachment; filename="' . $fallbackName . '"; filename*=UTF-8\'\'' . rawurlencode($utf8Name));

if ($format === 'geojson') {
    $geoFeatures = [];
    foreach ($features as $f) {
        if ($f['geom'] === 'Point') {
            $type = 'Point';
            $coordinates = [(float) $f['coords'][0][1], (float) $f['coords'][0][0]];
        } elseif ($f['geom'] === 'Polygon') {
            $type = 'Polygon';
            $coordinates = [array_map(fn($c) => [(float) $c[1], (float) $c[0]], geoClosedRing($f['coords']))];
        } else {
            $type = 'LineString';
            $coordinates = array_map(fn($c) => [(float) $c[1], (float) $c[0]], $f['coords']);
        }
        // `stroke`/`fill` are the simplestyle-spec keys GitHub, geojson.io and
        // most GeoJSON viewers already understand - the only widely honoured
        // way to keep team colours in a format that has no styling of its own.
        $properties = array_merge([
            'layer'  => geoKindLabel($f['kind']),
            'kind'   => $f['kind'],
            'name'   => $f['name'],
            'description' => $f['desc'],
            'stroke' => $f['color'],
            'fill'   => $f['color'],
        ], $f['props']);
        if ($f['time']) {
            $properties['time'] = geoIsoTime($f['time']);
        }
        $geoFeatures[] = [
            'type'       => 'Feature',
            'geometry'   => ['type' => $type, 'coordinates' => $coordinates],
            'properties' => $properties,
        ];
    }
    echo json_encode([
        'type'     => 'FeatureCollection',
        'name'     => $docTitle,
        'metadata' => [
            'mission_id'  => $missionId,
            'title'       => $mission['title'],
            'location'    => $mission['location'],
            'exported_at' => geoIsoTime(time()),
            'exported_by' => $user['name'],
            'generator'   => 'VolunteerOps ' . APP_VERSION,
        ],
        'features' => $geoFeatures,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

if ($format === 'gpx') {
    // GPX 1.1 fixes the child order of <gpx> as metadata, then every <wpt>,
    // then every <rte>, then every <trk> - a file that interleaves them is
    // rejected outright by stricter parsers (and by Garmin BaseCamp), so the
    // features are grouped here rather than emitted in gather order.
    //
    // GPX has no polygon type at all. Areas, sectors, restricted zones and
    // drawn dispatch shapes therefore go out as closed <trk> segments (first
    // vertex repeated last), which is the same convention CalTopo and Garmin
    // use when they lower a polygon into GPX - it draws correctly as an
    // outline everywhere, it just stops being a filled shape.
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<gpx version="1.1" creator="VolunteerOps ' . geoXml(APP_VERSION) . '"' .
         ' xmlns="http://www.topografix.com/GPX/1/1"' .
         ' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"' .
         ' xsi:schemaLocation="http://www.topografix.com/GPX/1/1 http://www.topografix.com/GPX/1/1/gpx.xsd">' . "\n";
    echo "  <metadata>\n";
    echo '    <name>' . geoXml($docTitle) . "</name>\n";
    echo '    <desc>' . geoXml($docDesc) . "</desc>\n";
    echo '    <time>' . geoIsoTime(time()) . "</time>\n";
    echo "  </metadata>\n";

    foreach ($features as $f) {
        if ($f['geom'] !== 'Point') {
            continue;
        }
        [$lat, $lng] = $f['coords'][0];
        echo '  <wpt lat="' . geoNum((float) $lat) . '" lon="' . geoNum((float) $lng) . "\">\n";
        echo '    <name>' . geoXml($f['name']) . "</name>\n";
        echo '    <desc>' . geoXml($f['desc']) . "</desc>\n";
        echo '    <type>' . geoXml(geoKindLabel($f['kind'])) . "</type>\n";
        if ($f['time']) {
            echo '    <time>' . geoIsoTime($f['time']) . "</time>\n";
        }
        echo "  </wpt>\n";
    }

    foreach ($features as $f) {
        if ($f['kind'] !== 'route') {
            continue;
        }
        echo "  <rte>\n";
        echo '    <name>' . geoXml($f['name']) . "</name>\n";
        echo '    <desc>' . geoXml($f['desc']) . "</desc>\n";
        echo '    <type>' . geoXml(geoKindLabel($f['kind'])) . "</type>\n";
        $labels = [];
        foreach ($f['props']['waypoints'] ?? [] as $w) {
            $labels[(int) $w['seq']] = $w['label'];
        }
        $seq = 0;
        foreach ($f['coords'] as $coord) {
            $seq++;
            $label = $labels[$seq] ?? null;
            echo '    <rtept lat="' . geoNum((float) $coord[0]) . '" lon="' . geoNum((float) $coord[1]) . "\">\n";
            echo '      <name>' . geoXml($label !== null && $label !== '' ? $label : (string) $seq) . "</name>\n";
            echo "    </rtept>\n";
        }
        echo "  </rte>\n";
    }

    foreach ($features as $f) {
        if ($f['geom'] === 'Point' || $f['kind'] === 'route') {
            continue;
        }
        $coords = $f['geom'] === 'Polygon' ? geoClosedRing($f['coords']) : $f['coords'];
        echo "  <trk>\n";
        echo '    <name>' . geoXml($f['name']) . "</name>\n";
        echo '    <desc>' . geoXml($f['desc']) . "</desc>\n";
        echo '    <type>' . geoXml(geoKindLabel($f['kind'])) . "</type>\n";
        echo "    <trkseg>\n";
        foreach ($coords as $i => $coord) {
            $pointTime = $f['times'][$i] ?? null;
            echo '      <trkpt lat="' . geoNum((float) $coord[0]) . '" lon="' . geoNum((float) $coord[1]) . '">';
            if ($pointTime) {
                echo '<time>' . geoIsoTime((int) $pointTime) . '</time>';
            }
            echo "</trkpt>\n";
        }
        echo "    </trkseg>\n";
        echo "  </trk>\n";
    }

    echo "</gpx>\n";
    exit;
}

// KML. Google Earth is the format's real audience here, and it renders a
// <Document> as a browsable tree - so features are grouped into one <Folder>
// per kind, which is what makes a 12-team mission legible instead of a wall
// of overlapping shapes. Styles are emitted once per distinct colour+geometry
// pair and referenced by id, rather than inlined per placemark.
$styles = [];
foreach ($features as $f) {
    $styles[$f['color'] . '|' . ($f['geom'] === 'Point' ? 'pt' : 'ln')] = true;
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<kml xmlns="http://www.opengis.net/kml/2.2">' . "\n";
echo "<Document>\n";
echo '  <name>' . geoXml($docTitle) . "</name>\n";
echo '  <description>' . geoXml($docDesc) . "</description>\n";

foreach (array_keys($styles) as $styleKey) {
    [$color, $shape] = explode('|', $styleKey);
    echo '  <Style id="' . geoXml(geoKmlStyleId($color, $shape)) . "\">\n";
    if ($shape === 'pt') {
        echo "    <IconStyle>\n";
        echo '      <color>' . geoKmlColor($color) . "</color>\n";
        echo "      <scale>1.1</scale>\n";
        echo "    </IconStyle>\n";
    } else {
        echo "    <LineStyle>\n";
        echo '      <color>' . geoKmlColor($color) . "</color>\n";
        echo "      <width>3</width>\n";
        echo "    </LineStyle>\n";
        // 0x4d ~= 30% alpha: enough fill to read a sector's extent at a glance,
        // transparent enough that overlapping sectors stay individually visible.
        echo "    <PolyStyle>\n";
        echo '      <color>' . geoKmlColor($color, '4d') . "</color>\n";
        echo "    </PolyStyle>\n";
    }
    echo "  </Style>\n";
}

$byKind = [];
foreach ($features as $f) {
    $byKind[$f['kind']][] = $f;
}
foreach ($byKind as $kind => $kindFeatures) {
    echo "  <Folder>\n";
    echo '    <name>' . geoXml(geoKindLabel($kind)) . "</name>\n";
    foreach ($kindFeatures as $f) {
        $styleId = geoKmlStyleId($f['color'], $f['geom'] === 'Point' ? 'pt' : 'ln');
        // Child order is fixed by the KML 2.2 schema's AbstractFeatureGroup
        // sequence: name, description, AbstractTimePrimitive, styleUrl, and
        // only then the geometry. Google Earth itself is lenient about it,
        // but strict validators and several GIS importers are not - so
        // TimeStamp goes above styleUrl even though it reads oddly.
        echo "    <Placemark>\n";
        echo '      <name>' . geoXml($f['name']) . "</name>\n";
        echo '      <description>' . geoXml($f['desc']) . "</description>\n";
        if ($f['time']) {
            echo '      <TimeStamp><when>' . geoIsoTime($f['time']) . "</when></TimeStamp>\n";
        }
        echo '      <styleUrl>#' . geoXml($styleId) . "</styleUrl>\n";
        if ($f['geom'] === 'Point') {
            echo '      <Point><coordinates>' . geoKmlCoords([$f['coords'][0]]) . "</coordinates></Point>\n";
        } elseif ($f['geom'] === 'Polygon') {
            echo "      <Polygon>\n";
            echo "        <tessellate>1</tessellate>\n";
            echo "        <outerBoundaryIs><LinearRing><coordinates>" . geoKmlCoords(geoClosedRing($f['coords'])) . "</coordinates></LinearRing></outerBoundaryIs>\n";
            echo "      </Polygon>\n";
        } else {
            echo "      <LineString>\n";
            echo "        <tessellate>1</tessellate>\n";
            echo '        <coordinates>' . geoKmlCoords($f['coords']) . "</coordinates>\n";
            echo "      </LineString>\n";
        }
        echo "    </Placemark>\n";
    }
    echo "  </Folder>\n";
}

echo "</Document>\n";
echo "</kml>\n";
exit;
