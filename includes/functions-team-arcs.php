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
 * ON FOOT OR WITH A VEHICLE (v3.438.0). The person giving the order says which,
 * in the window that sends it (travel_mode on the order; NULL = the team's own
 * transport, which is how every older order reads). It picks the router's mode
 * and the icon; nothing about the team changes.
 *
 * THE FIGURES. Three, and they are not the same thing:
 *   · distance_m = the STRAIGHT LINE from the team's latest fix to the target.
 *     Free arithmetic that never fails. The arc itself and the thin dashed line
 *     under it are this;
 *   · route_m and minutes = the ROUTED path and time (teamRouteLeg(): Google
 *     walking or driving with the organisation's key, the free OSRM for a
 *     vehicle without one), or null / the straight line at an assumed speed when
 *     no router could say, and 'source' says which. Where the walking router
 *     finds no path (a ridge) there is no route_m, and the page shows the
 *     straight line as the distance and says that is what it is.
 *     For a dispatch POINT these are the very figures the pin's popup shows
 *     (computeDispatchEta(), one routing call per new fix, shared). For a sector
 *     or a route they are asked of the router when the team has moved AND at
 *     least TEAM_ARC_ETA_REFRESH_SECONDS have passed and, in between, scaled by
 *     the distance still to go — which is what makes them move continuously with
 *     the team without paying for a routing call on every 5-second poll of
 *     every tab, and without asking at all while the team stands still.
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
/** How often a sector's or route's time is asked of a router again (once the team has moved). In between it is scaled by distance. */
const TEAM_ARC_ETA_REFRESH_SECONDS = 45;
/** A trip shorter than this has no «how much is done»: the first fix already was the arrival. */
const TEAM_ARC_MIN_TRIP_METERS = 60;

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
        "SELECT id, team_id, type, geo, label, travel_mode, UNIX_TIMESTAMP(created_at) AS ts
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
                'travel_mode' => normalizeTravelMode($row['travel_mode']),
                'lat'     => $point['lat'], 'lng' => $point['lng'], 'ts' => (int) $row['ts'],
            ];
        }
    }

    // ── sectors on the way ───────────────────────────────────────────────
    // 'assigned' and 'en_route' only. Once the team is working the sector
    // (in_progress) it has arrived; a finished or not-yet-given one is no
    // order to be somewhere.
    foreach (dbFetchAll(
        "SELECT id, team_id, label, geo, travel_mode, UNIX_TIMESTAMP(COALESCE(status_updated_at, created_at)) AS ts
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
            'travel_mode' => normalizeTravelMode($row['travel_mode']),
            'lat'     => $point['lat'], 'lng' => $point['lng'], 'ts' => (int) $row['ts'],
        ];
    }

    // ── routes: the next waypoint not yet reached ────────────────────────
    $routes = dbFetchAll(
        "SELECT id, team_id, title, travel_mode, UNIX_TIMESTAMP(created_at) AS ts
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
                'travel_mode' => normalizeTravelMode($route['travel_mode']),
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
        "INSERT INTO team_arc_state (team_id, target_kind, target_id, first_distance_m, minutes, source, mode, eta_distance_m, eta_at, route_m, ping_ts)
         VALUES (?, ?, ?, ?, NULL, NULL, NULL, NULL, NULL, NULL, NULL)
         ON DUPLICATE KEY UPDATE target_kind = VALUES(target_kind), target_id = VALUES(target_id),
            first_distance_m = VALUES(first_distance_m), minutes = NULL, source = NULL, mode = NULL,
            eta_distance_m = NULL, eta_at = NULL, route_m = NULL, ping_ts = NULL",
        [$teamId, $target['kind'], (int) $target['id'], $distance]
    );
    return [
        'team_id' => $teamId, 'target_kind' => $target['kind'], 'target_id' => (int) $target['id'],
        'first_distance_m' => $distance, 'minutes' => null, 'source' => null, 'mode' => null,
        'eta_distance_m' => null, 'eta_at' => null, 'route_m' => null, 'ping_ts' => null,
    ];
}

/**
 * The routed time and distance to the target: ['minutes', 'source', 'mode',
 * 'route_m' (null when no router found a path)].
 *
 * Worked out by arithmetic alone while the remembered figure still stands (see
 * the file comment): the team has not moved since it was worked out, or it was
 * worked out less than TEAM_ARC_ETA_REFRESH_SECONDS ago and the team is not
 * farther than before. Otherwise one routing attempt (teamRouteLeg(), with its
 * own pause for a service that did not answer), falling back to the straight
 * line at an assumed speed. Whatever came out is remembered, a fallback too, so
 * a router that is down is retried at the refresh interval and not on every poll.
 */
function teamArcEstimate(array $state, int $teamId, string $mode, float $fromLat, float $fromLng, float $toLat, float $toLng, int $distance, int $pingTs): array {
    $remembered = (int) ($state['eta_distance_m'] ?? 0);
    $known = $state['minutes'] !== null && $state['mode'] === $mode && $remembered > 0;
    $sameFix = $known && (int) ($state['ping_ts'] ?? 0) === $pingTs;
    $young = $known && (time() - (int) $state['eta_at']) < TEAM_ARC_ETA_REFRESH_SECONDS;
    if ($known && ($sameFix || $young) && $distance <= $remembered * 1.5) {
        $ratio = $distance / $remembered;
        return [
            'minutes' => max(1, (int) round((int) $state['minutes'] * $ratio)),
            'source'  => (string) $state['source'],
            'mode'    => $mode,
            'route_m' => ($state['route_m'] ?? null) !== null ? (int) round((int) $state['route_m'] * $ratio) : null,
        ];
    }

    $leg = teamRouteLeg($mode, $fromLat, $fromLng, $toLat, $toLng);
    if ($leg !== null) {
        [$minutes, $source, $routeM] = [$leg['minutes'], $leg['source'], $leg['meters']];
    } else {
        $minutes = $mode === 'vehicle'
            ? straightLineEtaMinutes($fromLat, $fromLng, $toLat, $toLng)
            : straightLineWalkingEtaMinutes($fromLat, $fromLng, $toLat, $toLng);
        [$source, $routeM] = ['straight_line', null];
    }
    dbExecute(
        "UPDATE team_arc_state SET minutes = ?, source = ?, mode = ?, route_m = ?, eta_distance_m = ?, eta_at = ?, ping_ts = ? WHERE team_id = ?",
        [$minutes, $source, $mode, $routeM, max(1, $distance), time(), $pingTs, $teamId]
    );
    return ['minutes' => (int) $minutes, 'source' => (string) $source, 'mode' => $mode, 'route_m' => $routeM];
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
        // routeDistanceIsDetour(); not part of the bootstrap chain.
        require_once __DIR__ . '/route-distance.php';
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
            // How the order said to go; the team's own transport when it did not say.
            $mode = travelModeFor($target['travel_mode'] ?? null, $transport);
            $state = teamArcState($teamId, $target, $distance);

            $eta = null;
            if ($target['kind'] === 'dispatch' && $target['type'] === 'point') {
                // The pin's popup shows these same figures.
                $shared = computeDispatchEta((int) $target['id'], $teamId, $target['lat'], $target['lng'], $target['travel_mode'] ?? null);
                if ($shared) {
                    $eta = [
                        'minutes' => (int) $shared['minutes'], 'source' => (string) $shared['source'],
                        'mode' => (string) $shared['mode'], 'route_m' => $shared['route_m'] ?? null,
                    ];
                }
            }
            $eta ??= teamArcEstimate($state, $teamId, $mode, (float) $pos['lat'], (float) $pos['lng'], $target['lat'], $target['lng'], $distance, (int) $pos['ts']);

            $routeM = isset($eta['route_m']) ? (int) $eta['route_m'] : null;
            $first = (int) $state['first_distance_m'];
            $arcs[] = [
                'team_id'      => $teamId,
                'label'        => $pos['label'],
                'color'        => $pos['color'] ?: '#8a94a6',
                // The picture on the arc: on foot, or the team's own vehicle
                // (a car for a team that is on foot but was told to go by vehicle).
                'transport'    => $mode === 'foot' ? 'foot' : (teamTransportIsVehicle($transport) ? $transport : 'car'),
                'mode'         => $mode,
                'from'         => [
                    'lat'   => round((float) $pos['lat'], 6),
                    'lng'   => round((float) $pos['lng'], 6),
                    'ts'    => (int) $pos['ts'],
                    'stale' => (bool) $pos['is_stale'],
                ],
                'to'           => ['lat' => round($target['lat'], 6), 'lng' => round($target['lng'], 6)],
                'kind'         => $target['type'],
                'target_label' => $target['label'],
                // The straight line, always: the arc and the dashed line under it.
                'distance_m'   => $distance,
                // The routed path, when a router found one (null otherwise: the
                // page then shows the straight line as THE distance and says so).
                'route_m'      => $routeM,
                // The router went the long way round: past the same ratio the
                // assistant and the measuring tool call a detour.
                'detour'       => $routeM !== null && routeDistanceIsDetour((float) $distance, (float) $routeM),
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
