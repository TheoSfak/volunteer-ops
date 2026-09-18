<?php
/**
 * VolunteerOps — where each team was actually told to go.
 *
 * The Action Room can send a team to a place in three different shapes, each
 * stored in its own table, and none of them is a column on mission_orders:
 *
 *   a dispatch POINT or AREA  (mission_dispatch_points)
 *   a search SECTOR           (mission_search_sectors)
 *   a ROUTE of waypoints      (mission_routes + mission_route_waypoints)
 *
 * This resolves all three to one comparable thing — a single pair of
 * coordinates with a human label — so that "how far is this person from where
 * I sent them" has one answer however the coordinator drew it.
 *
 * The rules for reducing a shape to a point are deliberate and not
 * interchangeable:
 *   POINT   the point itself.
 *   AREA    the middle of the polygon, guaranteed to be inside it — see
 *           polygonCentroid(), which does not simply take the centroid.
 *   SECTOR  the same.
 *   ROUTE   WAYPOINT 1, not the nearest one and not the centre. A route is an
 *           ordered instruction; until a team reaches its first waypoint the
 *           distance that matters is the distance to the start of the job.
 *
 * Assignment runs through the TEAM, not the order: dispatch points, sectors
 * and routes all carry team_id, and mission_orders carries no geometry at all.
 * A row with team_id NULL is addressed to the whole operation and therefore
 * applies to everyone who has no team target of their own.
 */

if (!defined('VOLUNTEEROPS')) {
    die('Direct access not permitted');
}

/**
 * Turn a stored geo blob into one point.
 *
 * Handles both shapes this app stores without having to be told which: an
 * object with lat/lng is a point, a list of pairs is a ring. Returns null for
 * anything else, because a target nobody can locate is worse than no target —
 * it would be reported as a distance.
 */
function missionTargetPointFromGeo($geo): ?array {
    $data = is_string($geo) ? json_decode($geo, true) : $geo;
    if (!is_array($data)) return null;

    if (isset($data['lat'], $data['lng']) && is_numeric($data['lat']) && is_numeric($data['lng'])) {
        return ['lat' => (float) $data['lat'], 'lng' => (float) $data['lng']];
    }
    return polygonCentroid($data);
}

/**
 * Every team's current target, newest first.
 *
 * Returns [teamId => target], with key 0 holding the mission-wide one. A
 * target is:
 *   ['kind' => 'point'|'area'|'sector'|'route', 'label' => string,
 *    'lat' => float, 'lng' => float, 'ts' => int, 'detail' => ?string]
 *
 * NEWEST WINS when a team has several. A team can hold a sector and a route at
 * once, and the coordinator's question — "where did I send them" — means the
 * last thing they were sent, not an arbitrary one. The kind travels with the
 * target so the answer can say which it was rather than implying there was
 * only ever one.
 */
function missionAssignedTargets(int $missionId): array {
    $candidates = [];

    // ── dispatch points and areas ────────────────────────────────────────
    $rows = dbFetchAll(
        "SELECT id, team_id, type, geo, label, UNIX_TIMESTAMP(created_at) AS ts
         FROM mission_dispatch_points
         WHERE mission_id = ?",
        [$missionId]
    );
    foreach ($rows as $row) {
        $point = missionTargetPointFromGeo($row['geo']);
        if ($point === null) continue;
        $isArea = ($row['type'] ?? '') !== 'point';
        $candidates[] = [
            'team_id' => $row['team_id'] === null ? 0 : (int) $row['team_id'],
            'kind'    => $isArea ? 'area' : 'point',
            'label'   => trim((string) ($row['label'] ?? '')) !== ''
                ? (string) $row['label']
                : ($isArea ? 'Περιοχή αποστολής' : 'Σημείο αποστολής'),
            'lat'     => $point['lat'],
            'lng'     => $point['lng'],
            'ts'      => (int) $row['ts'],
            'detail'  => $isArea ? 'στο μέσο της περιοχής' : null,
        ];
    }

    // ── search sectors ───────────────────────────────────────────────────
    //
    // A completed sector is not somewhere anybody is still being sent, and
    // reporting a distance to it would put a finished job back on the board.
    //
    // team_id IS NOT NULL is load-bearing and unlike the dispatch points
    // above. A point drawn with no team IS addressed to everyone — that is
    // what "send to all" means. A sector with no team is simply ground that
    // has been drawn and not yet given to anybody. Counting those as
    // everyone's assignment meant a mission with seven unassigned sectors
    // collapsed them to whichever one happened to sort first and told every
    // teamless volunteer that was where they had been sent. It was not.
    $rows = dbFetchAll(
        "SELECT id, team_id, label, geo, status, UNIX_TIMESTAMP(created_at) AS ts
         FROM mission_search_sectors
         WHERE mission_id = ? AND status <> 'completed' AND team_id IS NOT NULL",
        [$missionId]
    );
    foreach ($rows as $row) {
        $point = missionTargetPointFromGeo($row['geo']);
        if ($point === null) continue;
        // Real sector labels are already stored as «Τομέας Ε», so prefixing
        // unconditionally produces «Τομέας Τομέας Ε» — which is what shipped
        // in the first cut and reads as a bug to anyone who sees it.
        $label = trim((string) $row['label']);
        $candidates[] = [
            'team_id' => $row['team_id'] === null ? 0 : (int) $row['team_id'],
            'kind'    => 'sector',
            'label'   => mb_stripos($label, 'τομέας') === 0 ? $label : ('Τομέας ' . $label),
            'lat'     => $point['lat'],
            'lng'     => $point['lng'],
            'ts'      => (int) $row['ts'],
            'detail'  => 'στο μέσο του τομέα',
        ];
    }

    // ── routes: the FIRST waypoint ───────────────────────────────────────
    $rows = dbFetchAll(
        "SELECT r.id, r.team_id, r.title, UNIX_TIMESTAMP(r.created_at) AS ts,
                w.lat, w.lng, w.label AS wp_label,
                (SELECT COUNT(*) FROM mission_route_waypoints c WHERE c.route_id = r.id) AS waypoints
         FROM mission_routes r
         JOIN mission_route_waypoints w ON w.route_id = r.id AND w.seq = (
              SELECT MIN(seq) FROM mission_route_waypoints m WHERE m.route_id = r.id
         )
         WHERE r.mission_id = ? AND r.completed_at IS NULL AND r.cancelled_at IS NULL",
        [$missionId]
    );
    foreach ($rows as $row) {
        $candidates[] = [
            'team_id' => $row['team_id'] === null ? 0 : (int) $row['team_id'],
            'kind'    => 'route',
            'label'   => trim((string) ($row['title'] ?? '')) !== ''
                ? (string) $row['title']
                : 'Πορεία',
            'lat'     => (float) $row['lat'],
            'lng'     => (float) $row['lng'],
            'ts'      => (int) $row['ts'],
            'detail'  => 'σημείο 1 από ' . (int) $row['waypoints']
                . (trim((string) ($row['wp_label'] ?? '')) !== '' ? ' — ' . $row['wp_label'] : ''),
        ];
    }

    // ALL of them per team, newest first — not just the newest one.
    //
    // A team legitimately holds a sector AND a rendezvous point at the same
    // time; both are places it was sent. Keeping only the newest threw one
    // away silently, and since dispatch points were read first, a tie on the
    // second-granularity timestamp handed it to the point as well. Reported
    // from the field as "it works for a point and not for a sector": the
    // coordinator had assigned a sector, and the assistant went on measuring
    // to a rendezvous point sent minutes earlier.
    $targets = [];
    foreach ($candidates as $c) {
        $team = $c['team_id'];
        unset($c['team_id']);
        $targets[$team][] = $c;
    }
    foreach ($targets as &$list) {
        usort($list, fn($a, $b) => $b['ts'] <=> $a['ts']);
    }
    unset($list);

    return $targets;
}

/** How many of a team's targets are reported. Past this it is a list, not an order. */
const MISSION_TARGET_CAP = 3;

/**
 * Every place one person was sent, newest first.
 *
 * Their team's own assignments, then anything addressed to the whole mission.
 * Team-first rather than strictly newest, because an instruction given to your
 * team is more yours than one broadcast to everybody — but the broadcast one is
 * still reported, which is the part that used to be missing.
 *
 * Returns an empty array when nothing was assigned, which is the normal state
 * early in an operation and must read as "nothing yet", never as a distance of
 * zero.
 */
function missionTargetsForTeam(array $targets, ?int $teamId): array {
    $own    = ($teamId !== null && isset($targets[$teamId])) ? $targets[$teamId] : [];
    $shared = $targets[0] ?? [];
    return array_slice(array_merge($own, $shared), 0, MISSION_TARGET_CAP);
}
