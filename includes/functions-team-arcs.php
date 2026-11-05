<?php
/**
 * VolunteerOps — destination arcs (v3.437.0).
 *
 * On the live map every team that has been sent somewhere and has not got
 * there yet gets an arch from where it is NOW to where it was sent, in the
 * team's own colour, with a dial at the top of the arch: the time it will
 * take (ETA) and the metres still to go. This file works out what that arch
 * says; the drawing is assets/js/war-room-team-arcs.js.
 *
 * WHERE A TEAM IS BEING SENT. Three shapes, each in its own table, and a team
 * can hold several at once — it gets ONE arc, to the newest it has not reached:
 *   · a dispatch POINT or AREA   (mission_dispatch_points)   → the point; an area → the middle of the polygon
 *   · a SECTOR                    (mission_search_sectors)    → the middle of the polygon, while assigned / en route
 *   · a ROUTE                     (mission_routes)            → the NEXT waypoint it has not reached
 * The middle of a polygon is polygonCentroid(), never a plain centroid (a
 * sector round a gorge is concave; see mission-targets.php). A route differs
 * from the AI digest's rule (waypoint 1): the arc follows the team along the
 * route, so it points at the next point still to do.
 *
 * WHEN THE ARC GOES. The moment the team has arrived (dispatch: «Έφτασα» or
 * «Ολοκληρώθηκε»; sector: it is in progress; route point: arrived, completed,
 * skipped or unlocked), has said «Δεν μπορώ», or is within a few metres of the
 * target. Nothing here keeps an arc alive after that.
 *
 * WHO SEES IT. Command sees every team's arc. A participant sees only their
 * own team's: where another team has been sent is not theirs to know, the same
 * rule loadMissionDispatchesForUser() applies to the orders themselves.
 *
 * THE FIGURES.
 *   · metres = straight line from the team's latest fix, free arithmetic that
 *     never fails (the routed distance would need a service call per poll);
 *   · minutes = the routed time when there is one (OSRM by road for a team in a
 *     vehicle, Google on foot when the organisation stored a key), the straight
 *     line at an assumed speed otherwise, and the arc says which ('source').
 *     For a dispatch POINT it is the very figure the pin's popup shows
 *     (computeDispatchEta(), one routing call per new fix, shared). For a sector
 *     or a route it is asked of the router at most once per
 *     TEAM_ARC_ETA_REFRESH_SECONDS and, in between, scaled by the distance
 *     still to go — which is what makes it move continuously with the team
 *     without hitting a free public router on every 5-second poll of every tab.
 *
 * NOTHING IN THE PAYLOAD MAY SLIDE WITH THE CLOCK. The poll's response is
 * hashed so a quiet mission costs no bandwidth; an "age in seconds" here would
 * change it every tick. The fix carries its absolute time and the page works
 * out the age itself.
 */

if (!defined('VOLUNTEEROPS')) {
    die('Direct access not permitted');
}

/** Closer than this the team is AT the target: GPS noise, not a journey, and the arc would be a blob. */
const TEAM_ARC_AT_TARGET_METERS = 20;
/** How often a sector's or route's time is asked of a router again. In between it is scaled by distance. */
const TEAM_ARC_ETA_REFRESH_SECONDS = 45;
/** A trip shorter than this has no «how much is done»: the first fix already was the arrival. */
const TEAM_ARC_MIN_TRIP_METERS = 60;
/** After a road-router failure, every request answers with the straight line for this long (see externalBreakerTrip). */
const TEAM_ARC_ROAD_BREAKER_KEY = 'team_arc_road_eta_paused_until';
const TEAM_ARC_ROAD_BREAKER_SECONDS = 180;

/**
 * The one place each team is being sent now.
 *
 * Returns [teamId => ['kind' => 'dispatch'|'sector'|'route', 'id' => int,
 * 'type' => 'point'|'area'|'sector'|'route', 'label' => string, 'lat' => float,
 * 'lng' => float, 'ts' => int]], newest first wins. A target addressed to every
 * team (team_id NULL) has no team to draw from and is not here.
 */
function teamArcActiveTargets(int $missionId): array {
    require_once __DIR__ . '/mission-targets.php';
    $declines = loadActiveOrderDeclines($missionId);
    $candidates = [];

    // ── dispatch points and areas ────────────────────────────────────────
    $rows = dbFetchAll(
        "SELECT id, team_id, type, geo, label, UNIX_TIMESTAMP(created_at) AS ts
         FROM mission_dispatch_points
         WHERE mission_id = ? AND team_id IS NOT NULL",
        [$missionId]
    );
    if ($rows) {
        $ids = array_map('intval', array_column($rows, 'id'));
        $progress = loadDispatchProgress($ids);
        // Arrivals from before the progress table (v3.325.0) live only in the
        // per-person acknowledgements; any one from the team still means arrived.
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $acked = [];
        foreach (dbFetchAll(
            "SELECT DISTINCT dispatch_id, team_id FROM mission_dispatch_acks
             WHERE dispatch_id IN ($placeholders) AND team_id IS NOT NULL",
            $ids
        ) as $ack) {
            $acked[(int) $ack['dispatch_id']][(int) $ack['team_id']] = true;
        }
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $teamId = (int) $row['team_id'];
            $scope = 't' . $teamId;
            $p = $progress[$id][$scope] ?? null;
            if (($p && ($p['arrived'] || $p['completed'])) || !empty($acked[$id][$teamId]) || isset($declines['dispatch:' . $id][$scope])) {
                continue;
            }
            $point = missionTargetPointFromGeo($row['geo']);
            if ($point === null) {
                continue;
            }
            $candidates[] = [
                'team_id' => $teamId, 'kind' => 'dispatch', 'id' => $id,
                'type'    => $row['type'] === 'point' ? 'point' : 'area',
                'label'   => trim((string) $row['label']),
                'lat'     => $point['lat'], 'lng' => $point['lng'], 'ts' => (int) $row['ts'],
            ];
        }
    }

    // ── sectors on the way ───────────────────────────────────────────────
    // 'assigned' and 'en_route' only. Once the team is working the sector
    // (in_progress) it has arrived; a finished or not-yet-given one is no
    // order to be somewhere.
    foreach (dbFetchAll(
        "SELECT id, team_id, label, geo, UNIX_TIMESTAMP(COALESCE(status_updated_at, created_at)) AS ts
         FROM mission_search_sectors
         WHERE mission_id = ? AND team_id IS NOT NULL AND status IN ('assigned', 'en_route')",
        [$missionId]
    ) as $row) {
        $id = (int) $row['id'];
        $teamId = (int) $row['team_id'];
        if (isset($declines['sector:' . $id]['t' . $teamId])) {
            continue;
        }
        $point = missionTargetPointFromGeo($row['geo']);
        if ($point === null) {
            continue;
        }
        $candidates[] = [
            'team_id' => $teamId, 'kind' => 'sector', 'id' => $id, 'type' => 'sector',
            'label'   => trim((string) $row['label']),
            'lat'     => $point['lat'], 'lng' => $point['lng'], 'ts' => (int) $row['ts'],
        ];
    }

    // ── routes: the next waypoint not yet reached ────────────────────────
    $routes = dbFetchAll(
        "SELECT id, team_id, title, UNIX_TIMESTAMP(created_at) AS ts
         FROM mission_routes
         WHERE mission_id = ? AND team_id IS NOT NULL AND completed_at IS NULL AND cancelled_at IS NULL",
        [$missionId]
    );
    if ($routes) {
        $routeIds = array_map('intval', array_column($routes, 'id'));
        $placeholders = implode(',', array_fill(0, count($routeIds), '?'));
        $nextByRoute = [];
        foreach (dbFetchAll(
            "SELECT w.route_id, w.lat, w.lng, w.label
             FROM mission_route_waypoints w
             LEFT JOIN mission_route_progress p ON p.waypoint_id = w.id
             WHERE w.route_id IN ($placeholders)
               AND p.arrived_at IS NULL AND p.completed_at IS NULL AND p.skipped_at IS NULL AND p.unlocked_at IS NULL
             ORDER BY w.route_id, w.seq",
            $routeIds
        ) as $wp) {
            $nextByRoute[(int) $wp['route_id']] ??= $wp;
        }
        foreach ($routes as $route) {
            $id = (int) $route['id'];
            $teamId = (int) $route['team_id'];
            $wp = $nextByRoute[$id] ?? null;
            if ($wp === null || isset($declines['route:' . $id]['t' . $teamId])) {
                continue;
            }
            $label = trim((string) ($wp['label'] ?? ''));
            $candidates[] = [
                'team_id' => $teamId, 'kind' => 'route', 'id' => $id, 'type' => 'route',
                'label'   => $label !== '' ? $label : trim((string) ($route['title'] ?? '')),
                'lat'     => (float) $wp['lat'], 'lng' => (float) $wp['lng'], 'ts' => (int) $route['ts'],
            ];
        }
    }

    usort($candidates, fn($a, $b) => $b['ts'] <=> $a['ts']);
    $byTeam = [];
    foreach ($candidates as $c) {
        $team = $c['team_id'];
        unset($c['team_id']);
        $byTeam[$team] ??= $c;
    }
    return $byTeam;
}

/**
 * What this team's arc remembers between polls: the target it is for, how far
 * away the target was when the arc first appeared, and the last time worked
 * out. A new target replaces the row, so «how much is done» starts again.
 */
function teamArcState(int $teamId, array $target, int $distance): array {
    $row = dbFetchOne("SELECT * FROM team_arc_state WHERE team_id = ?", [$teamId]);
    if ($row && $row['target_kind'] === $target['kind'] && (int) $row['target_id'] === (int) $target['id']) {
        return $row;
    }
    dbExecute(
        "INSERT INTO team_arc_state (team_id, target_kind, target_id, first_distance_m, minutes, source, mode, eta_distance_m, eta_at)
         VALUES (?, ?, ?, ?, NULL, NULL, NULL, NULL, NULL)
         ON DUPLICATE KEY UPDATE target_kind = VALUES(target_kind), target_id = VALUES(target_id),
            first_distance_m = VALUES(first_distance_m), minutes = NULL, source = NULL, mode = NULL,
            eta_distance_m = NULL, eta_at = NULL",
        [$teamId, $target['kind'], (int) $target['id'], $distance]
    );
    return [
        'team_id' => $teamId, 'target_kind' => $target['kind'], 'target_id' => (int) $target['id'],
        'first_distance_m' => $distance, 'minutes' => null, 'source' => null, 'mode' => null,
        'eta_distance_m' => null, 'eta_at' => null,
    ];
}

/**
 * The time to the target, as [minutes, source, mode] keyed array.
 *
 * Pure arithmetic when the remembered figure is still fresh (see the file
 * comment); otherwise one routing attempt, falling back to the straight line.
 * A road router that failed is left alone for TEAM_ARC_ROAD_BREAKER_SECONDS
 * for the whole site. The walking router (Google, only with a stored key)
 * answers "no path" with a plain empty result on a ridge, which is not an
 * outage, so it is never put on that pause.
 */
function teamArcEstimate(array $state, int $teamId, string $mode, float $fromLat, float $fromLng, float $toLat, float $toLng, int $distance): array {
    $remembered = (int) ($state['eta_distance_m'] ?? 0);
    $fresh = $state['minutes'] !== null
        && $state['mode'] === $mode
        && $remembered > 0
        && (time() - (int) $state['eta_at']) < TEAM_ARC_ETA_REFRESH_SECONDS
        && $distance <= $remembered * 1.5;
    if ($fresh) {
        return [
            'minutes' => max(1, (int) round((int) $state['minutes'] * $distance / $remembered)),
            'source'  => (string) $state['source'],
            'mode'    => $mode,
        ];
    }

    if ($mode === 'vehicle') {
        $routed = null;
        if (externalBreakerPausedUntil(TEAM_ARC_ROAD_BREAKER_KEY) === 0) {
            $routed = fetchOsrmEtaMinutes($fromLat, $fromLng, $toLat, $toLng);
            if ($routed === null) {
                externalBreakerTrip(TEAM_ARC_ROAD_BREAKER_KEY, TEAM_ARC_ROAD_BREAKER_SECONDS);
            } else {
                externalBreakerClear(TEAM_ARC_ROAD_BREAKER_KEY);
            }
        }
        [$minutes, $source] = $routed ?? [straightLineEtaMinutes($fromLat, $fromLng, $toLat, $toLng), 'straight_line'];
    } else {
        $routed = fetchWalkingEtaMinutes($fromLat, $fromLng, $toLat, $toLng);
        [$minutes, $source] = $routed ?? [straightLineWalkingEtaMinutes($fromLat, $fromLng, $toLat, $toLng), 'straight_line'];
    }
    dbExecute(
        "UPDATE team_arc_state SET minutes = ?, source = ?, mode = ?, eta_distance_m = ?, eta_at = ? WHERE team_id = ?",
        [$minutes, $source, $mode, max(1, $distance), time(), $teamId]
    );
    return ['minutes' => (int) $minutes, 'source' => (string) $source, 'mode' => $mode];
}

/**
 * The arcs for one viewer. $teamPositions is loadTeamPositionsForMission()'s
 * answer, passed in because the poll has already asked for it once.
 *
 * Fails to an empty list: this rides the poll every open tab hits, and a
 * missing table (a deploy that has not run migration 201 yet) or a routing
 * hiccup must not be able to take the rest of the Action Room down with it.
 */
function teamArcsForMission(int $missionId, array $teamPositions, bool $canManageWarRoom, ?int $myTeamId): array {
    if (!$teamPositions || (!$canManageWarRoom && $myTeamId === null)) {
        return [];
    }
    try {
        $targets = teamArcActiveTargets($missionId);
        if (!$targets) {
            return [];
        }
        $transports = [];
        foreach (dbFetchAll("SELECT id, transport FROM mission_teams WHERE mission_id = ?", [$missionId]) as $team) {
            $transports[(int) $team['id']] = normalizeTeamTransport($team['transport']);
        }

        $arcs = [];
        foreach ($teamPositions as $pos) {
            $teamId = (int) $pos['team_id'];
            if (!$canManageWarRoom && $teamId !== $myTeamId) {
                continue;
            }
            $target = $targets[$teamId] ?? null;
            if ($target === null) {
                continue;
            }
            $distance = (int) round(gpsDistanceMeters((float) $pos['lat'], (float) $pos['lng'], $target['lat'], $target['lng']));
            if ($distance < TEAM_ARC_AT_TARGET_METERS) {
                continue;
            }

            $transport = $transports[$teamId] ?? 'foot';
            $mode = teamTransportIsVehicle($transport) ? 'vehicle' : 'foot';
            $state = teamArcState($teamId, $target, $distance);

            $eta = null;
            if ($target['kind'] === 'dispatch' && $target['type'] === 'point') {
                // The pin's popup shows this same figure.
                $shared = computeDispatchEta((int) $target['id'], $teamId, $target['lat'], $target['lng']);
                if ($shared) {
                    $eta = ['minutes' => (int) $shared['minutes'], 'source' => (string) $shared['source'], 'mode' => (string) $shared['mode']];
                }
            }
            $eta ??= teamArcEstimate($state, $teamId, $mode, (float) $pos['lat'], (float) $pos['lng'], $target['lat'], $target['lng'], $distance);

            $first = (int) $state['first_distance_m'];
            $arcs[] = [
                'team_id'      => $teamId,
                'label'        => $pos['label'],
                'color'        => $pos['color'] ?: '#8a94a6',
                'transport'    => $transport,
                'from'         => [
                    'lat'   => round((float) $pos['lat'], 6),
                    'lng'   => round((float) $pos['lng'], 6),
                    'ts'    => (int) $pos['ts'],
                    'stale' => (bool) $pos['is_stale'],
                ],
                'to'           => ['lat' => round($target['lat'], 6), 'lng' => round($target['lng'], 6)],
                'kind'         => $target['type'],
                'target_label' => $target['label'],
                'distance_m'   => $distance,
                // How much of the way is done: null on a trip too short to say.
                'covered_pct'  => $first >= TEAM_ARC_MIN_TRIP_METERS
                    ? max(0, min(100, (int) round((1 - $distance / $first) * 100)))
                    : null,
                'eta'          => $eta,
            ];
        }
        usort($arcs, fn($a, $b) => $a['team_id'] <=> $b['team_id']);
        return $arcs;
    } catch (Throwable $e) {
        error_log('teamArcsForMission() failed (mission ' . $missionId . '): ' . $e->getMessage());
        return [];
    }
}
