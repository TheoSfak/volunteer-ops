<?php
/**
 * VolunteerOps - War Room (Action Room) Helper Functions
 * Split out of includes/functions.php: mission dispatch/routes/trails/photos,
 * command-staff notifications, GPS/ETA math, incident & SOS handling, and the
 * Action Room scoring/narrative-generation used by reports.php's Action Room
 * tab. Loaded by bootstrap.php right after includes/functions.php, since a
 * few of these (loadBroadcastPhotosForMission, computeDispatchEta,
 * gpsDistanceMeters, createMissionOrderAndNotify) are also called from
 * includes/migrations.php.
 */

if (!defined('VOLUNTEEROPS')) {
    die('Direct access not permitted');
}

/**
 * War Room: the caller's team for a mission, or null if unassigned. Used to
 * snapshot team_id at order/receipt/ack creation time (mission_dispatch_acks,
 * mission_dispatch_receipts, mission_order_recipients) so later team
 * reassignment doesn't retroactively change historical reports.
 */
function getUserTeamIdForMission(int $missionId, int $userId): ?int {
    $teamId = dbFetchValue(
        "SELECT team_id FROM mission_team_members WHERE mission_id = ? AND user_id = ? LIMIT 1",
        [$missionId, $userId]
    );
    return $teamId ? (int) $teamId : null;
}

/**
 * External/guest accounts (users.is_external) are locked to Action Room for
 * only the mission(s) an admin has approved them on — this is that scope,
 * derived from the same participation_requests rows normal volunteers use
 * (an admin approves them via mission-view.php's "manual_add_volunteer",
 * no separate binding needed). Most-recently-started mission first.
 */
function getExternalGuestMissionIds(int $userId): array {
    $rows = dbFetchAll(
        "SELECT DISTINCT s.mission_id, MAX(s.start_time) as last_start
         FROM participation_requests pr
         JOIN shifts s ON pr.shift_id = s.id
         JOIN missions m ON m.id = s.mission_id AND m.deleted_at IS NULL
         WHERE pr.volunteer_id = ? AND pr.status = ?
         GROUP BY s.mission_id
         ORDER BY last_start DESC",
        [$userId, PARTICIPATION_APPROVED]
    );
    return array_map('intval', array_column($rows, 'mission_id'));
}

// Team color palette, same index basis as war-room.php's own MISSION_TEAM_CODENAMES
// (team N gets codename[N % 26] and color[N % 8] — colors cycle every 8 teams
// since a colorblind-safe categorical palette only stays distinguishable up to
// ~8 slots; ordering was picked, and validated with the dataviz skill's
// scripts/validate_palette.js, to lead with red for Alpha / green for Bravo as
// requested, then fill the rest by worst-adjacent-pair CVD separation).
// Moved here from war-room.php once loadMissionDispatchesForUser() below also
// needed the same [bg, fg] pair, for the dispatch map's permanent team-label
// pills — same palette team badges/pins/trail lines already use.
if (!defined('MISSION_TEAM_COLORS')) {
    define('MISSION_TEAM_COLORS', ['#e34948','#008300','#4a3aa7','#eda100','#2a78d6','#e87ba4','#1baf7a','#eb6834']);
}
if (!defined('MISSION_TEAM_COLOR_TEXT')) {
    define('MISSION_TEAM_COLOR_TEXT', ['#008300' => '#fff', '#4a3aa7' => '#fff']);
}
/** Returns [background, text] hex pair for a team badge; null color falls back to the old bg-dark look. */
function teamBadgeColors(?string $color): array {
    if (!$color) return ['#212529', '#fff'];
    return [$color, MISSION_TEAM_COLOR_TEXT[$color] ?? '#000'];
}

/**
 * <img> for a real flag graphic — deliberately never an emoji and never a
 * bare "GR"/"GB" text code: Windows/Chrome renders flag emoji as literal
 * two-letter text instead of a picture, which is the whole reason this
 * exists. Self-hosted SVGs under assets/flags/{code}.svg (sourced from the
 * MIT-licensed lipis/flag-icons project), not a CDN. Returns '' if there's
 * no code to show.
 */
function flagHtml(?string $countryCode): string {
    if (!$countryCode || !preg_match('/^[A-Za-z]{2}$/', $countryCode)) {
        return '';
    }
    $cc = strtolower($countryCode);
    return '<img class="flag-icon" src="' . BASE_URL . '/assets/flags/' . $cc . '.svg" alt="">';
}

/**
 * War Room: load dispatch points/areas visible to $userId, each augmented with
 * its receipt (mission_dispatch_receipts, "Ελήφθη") and arrival (mission_dispatch_acks,
 * "Άφιξη") acknowledgements — shared by war-room.php (live map, twice) and
 * mission-dispatch.php (AJAX poll) so all three stay in sync.
 */
function loadMissionDispatchesForUser(int $missionId, int $userId, bool $canManageWarRoom, bool $isApprovedParticipant): array {
    $rows = dbFetchAll(
        "SELECT d.id, d.team_id, d.type, d.geo, d.label, d.ring_index, mt.codename, mt.team_number, mt.color
         FROM mission_dispatch_points d
         LEFT JOIN mission_teams mt ON mt.id = d.team_id
         WHERE d.mission_id = ?
           AND (d.team_id IS NULL OR ? = 1 OR d.team_id IN (
                SELECT team_id FROM mission_team_members WHERE user_id = ?
           ))
         ORDER BY d.created_at",
        [$missionId, $canManageWarRoom ? 1 : 0, $userId]
    );
    if (empty($rows)) {
        return [];
    }

    $dispatchIds = array_map('intval', array_column($rows, 'id'));
    $placeholders = implode(',', array_fill(0, count($dispatchIds), '?'));
    $ackRows = dbFetchAll(
        "SELECT a.dispatch_id, a.team_id, a.user_id, a.created_at, u.name AS user_name,
                u.is_external, u.guest_org_name, u.guest_country_code,
                COALESCE(vt.name, mvt.label) AS home_team_name, COALESCE(vt.color, mvt.color) AS home_team_color,
                mt.codename, mt.team_number
         FROM mission_dispatch_acks a
         JOIN users u ON u.id = a.user_id
         LEFT JOIN volunteer_teams vt ON vt.id = u.volunteer_team_id
         LEFT JOIN mission_visitor_tags mvt ON mvt.id = u.mission_visitor_tag_id
         LEFT JOIN mission_teams mt ON mt.id = a.team_id
         WHERE a.dispatch_id IN ($placeholders)
         ORDER BY a.created_at",
        $dispatchIds
    );
    $acksByDispatch = [];
    foreach ($ackRows as $ack) {
        [$homeBg, $homeFg] = teamBadgeColors($ack['home_team_color']);
        $acksByDispatch[(int) $ack['dispatch_id']][] = [
            'team_label'         => $ack['team_id'] ? teamLabel($ack['codename'], $ack['team_number']) : null,
            'user_name'          => $ack['user_name'],
            'is_external'        => (bool) $ack['is_external'],
            'guest_org_name'     => $ack['guest_org_name'],
            'home_team_name'     => $ack['home_team_name'],
            'home_team_color_bg' => $homeBg,
            'home_team_color_fg' => $homeFg,
            'guest_country_code' => $ack['guest_country_code'],
            'user_id'            => (int) $ack['user_id'],
            'time'               => date('H:i', strtotime($ack['created_at'])),
        ];
    }

    $receiptRows = dbFetchAll(
        "SELECT r.dispatch_id, r.user_id, r.created_at
         FROM mission_dispatch_receipts r
         WHERE r.dispatch_id IN ($placeholders)",
        $dispatchIds
    );
    $receiptsByDispatch = [];
    foreach ($receiptRows as $receipt) {
        $receiptsByDispatch[(int) $receipt['dispatch_id']][(int) $receipt['user_id']] = date('H:i', strtotime($receipt['created_at']));
    }

    $myTeamId = getUserTeamIdForMission($missionId, $userId);

    return array_map(function ($row) use ($canManageWarRoom, $isApprovedParticipant, $userId, $myTeamId, $acksByDispatch, $receiptsByDispatch) {
        $dispatchId = (int) $row['id'];
        $teamId = $row['team_id'] ? (int) $row['team_id'] : null;
        $acks = $acksByDispatch[$dispatchId] ?? [];
        $eligible = $teamId === null || $teamId === $myTeamId;

        $myAck = null;
        foreach ($acks as $ack) {
            if ($ack['user_id'] === $userId) {
                $myAck = $ack['time'];
                break;
            }
        }
        $myReceipt = $receiptsByDispatch[$dispatchId][$userId] ?? null;
        [$teamColorBg, $teamColorFg] = teamBadgeColors($teamId ? $row['color'] : null);
        $geo = json_decode($row['geo'], true);

        // ETA only makes sense for a single point sent to one specific team —
        // a polygon zone has no one destination, and a broadcast to "all
        // teams" (team_id null) has no one team's position to measure from.
        $eta = ($row['type'] === 'point' && $teamId !== null)
            ? computeDispatchEta($dispatchId, $teamId, (float) $geo['lat'], (float) $geo['lng'])
            : null;

        return [
            'id'          => $dispatchId,
            'type'        => $row['type'],
            'geo'         => $geo,
            'eta'         => $eta,
            'label'       => $row['label'],
            'ring_index'  => $row['ring_index'] !== null ? (int) $row['ring_index'] : null,
            'team_label'  => $teamId ? teamLabel($row['codename'], $row['team_number']) : t('common.all_teams'),
            'team_color_bg' => $teamColorBg,
            'team_color_fg' => $teamColorFg,
            'can_delete'  => $canManageWarRoom,
            'acks'        => array_map(fn($a) => [
                'team_label' => $a['team_label'] ?? '—', 'user_name' => $a['user_name'],
                'is_external' => $a['is_external'], 'guest_org_name' => $a['guest_org_name'],
                'home_team_name' => $a['home_team_name'], 'home_team_color_bg' => $a['home_team_color_bg'], 'home_team_color_fg' => $a['home_team_color_fg'],
                'guest_country_code' => $a['guest_country_code'], 'time' => $a['time'],
            ], $acks),
            'my_ack'      => $myAck,
            'can_ack'     => $isApprovedParticipant && !$myAck && $eligible,
            'my_receipt'  => $myReceipt,
            'can_receive' => $isApprovedParticipant && !$myReceipt && $eligible,
        ];
    }, $rows);
}

/**
 * War Room search-area coverage tracking — the outer polygon an admin draws
 * first, representing a whole search zone, which then gets divided into
 * sectors (see loadMissionSectorsForUser() below). Deliberately UNIVERSAL
 * visibility, same rationale as sectors: everyone needs to see the whole
 * coverage picture. No status of its own — sector_count/completed_count is
 * always computed fresh from its sectors here, never stored on the area row,
 * so it can never go stale.
 */
function loadMissionSearchAreasForUser(int $missionId, bool $canManageWarRoom): array {
    $rows = dbFetchAll(
        "SELECT a.id, a.label, a.geo, a.ring_index, a.created_at, cu.name AS created_by_name
         FROM mission_search_areas a
         LEFT JOIN users cu ON cu.id = a.created_by
         WHERE a.mission_id = ?
         ORDER BY a.created_at",
        [$missionId]
    );
    if (empty($rows)) {
        return [];
    }

    $areaIds = array_map(fn($r) => (int) $r['id'], $rows);
    $placeholders = implode(',', array_fill(0, count($areaIds), '?'));

    // Rollup, batched — no N+1, same discipline as the buildings/floors
    // batching in loadMissionSectorsForUser() below.
    $rollupRows = dbFetchAll(
        "SELECT area_id, COUNT(*) AS total, SUM(status = 'completed') AS completed_count
         FROM mission_search_sectors WHERE area_id IN ($placeholders) GROUP BY area_id",
        $areaIds
    );
    $rollupByArea = [];
    foreach ($rollupRows as $r) {
        $rollupByArea[(int) $r['area_id']] = ['total' => (int) $r['total'], 'completed_count' => (int) $r['completed_count']];
    }

    return array_map(function ($row) use ($canManageWarRoom, $rollupByArea) {
        $areaId = (int) $row['id'];
        $rollup = $rollupByArea[$areaId] ?? ['total' => 0, 'completed_count' => 0];
        return [
            'id'              => $areaId,
            'label'           => $row['label'],
            'geo'             => json_decode($row['geo'], true),
            'ring_index'      => $row['ring_index'] !== null ? (int) $row['ring_index'] : null,
            'sector_count'    => $rollup['total'],
            'completed_count' => $rollup['completed_count'],
            'can_manage'      => $canManageWarRoom,
            'created_at'      => date('d/m H:i', strtotime($row['created_at'])),
            'created_by_name' => $row['created_by_name'],
        ];
    }, $rows);
}

/**
 * War Room search-sector coverage tracking — polygon sub-divisions of a
 * search area (see loadMissionSearchAreasForUser() above), optionally
 * assigned to a team, tracked through a
 * not_started/assigned/en_route/in_progress/completed/needs_recheck
 * lifecycle (self-report additionally gated on acknowledged_at while still
 * 'assigned' — see $needsAcknowledgeFirst below), with an optional
 * per-building/per-floor checklist for urban sectors.
 *
 * Deliberately UNIVERSAL visibility (no team_id filter at all), unlike
 * loadMissionDispatchesForUser() above — the whole point of this feature is
 * that every team can see what's already covered so nobody re-searches it.
 * Team-scoping only applies to the self-report action in mission-sector.php,
 * never to what gets returned here.
 */
function loadMissionSectorsForUser(int $missionId, int $userId, bool $canManageWarRoom, bool $isApprovedParticipant): array {
    $rows = dbFetchAll(
        "SELECT s.id, s.area_id, s.team_id, s.label, s.geo, s.status, s.status_updated_at,
                su.name AS status_updated_by_name, s.acknowledged_at, au.name AS acknowledged_by_name,
                s.created_at, cu.name AS created_by_name,
                mt.codename, mt.team_number, mt.color
         FROM mission_search_sectors s
         LEFT JOIN mission_teams mt ON mt.id = s.team_id
         LEFT JOIN users su ON su.id = s.status_updated_by
         LEFT JOIN users au ON au.id = s.acknowledged_by
         LEFT JOIN users cu ON cu.id = s.created_by
         WHERE s.mission_id = ?
         -- id breaks the tie: a generated grid inserts every one of its
         -- sectors in a single statement, so they all share one created_at
         -- and MySQL is free to return Α36 first. Insert order is label
         -- order, so this is what makes the list read Α1, Α2, Α3.
         ORDER BY s.created_at, s.id",
        [$missionId]
    );
    if (empty($rows)) {
        return [];
    }

    $sectorIds = array_map(fn($r) => (int) $r['id'], $rows);
    $placeholders = implode(',', array_fill(0, count($sectorIds), '?'));

    // Buildings + floors, batched via IN(...) — no per-sector/per-building
    // loop queries, matching this session's own running discipline about
    // N+1s on the 15s poll path (see $loadPins' LEAD() rewrite).
    $buildingRows = dbFetchAll(
        "SELECT id, sector_id, label, lat, lng, floor_count FROM mission_sector_buildings
         WHERE sector_id IN ($placeholders) ORDER BY id",
        $sectorIds
    );
    $buildingsBySector = [];
    $buildingIds = [];
    foreach ($buildingRows as $b) {
        $buildingsBySector[(int) $b['sector_id']][] = $b;
        $buildingIds[] = (int) $b['id'];
    }

    $floorsByBuilding = [];
    if (!empty($buildingIds)) {
        $bPlaceholders = implode(',', array_fill(0, count($buildingIds), '?'));
        $floorRows = dbFetchAll(
            "SELECT f.id, f.building_id, f.floor_number, f.is_required, f.checked_at, f.note,
                    cb.name AS checked_by_name
             FROM mission_sector_building_floors f
             LEFT JOIN users cb ON cb.id = f.checked_by
             WHERE f.building_id IN ($bPlaceholders)
             ORDER BY f.building_id, f.floor_number",
            $buildingIds
        );
        foreach ($floorRows as $f) {
            $floorsByBuilding[(int) $f['building_id']][] = [
                'id'              => (int) $f['id'],
                'floor_number'    => (int) $f['floor_number'],
                'is_required'     => (bool) $f['is_required'],
                'checked_at'      => $f['checked_at'] ? date('d/m H:i', strtotime($f['checked_at'])) : null,
                'checked_by_name' => $f['checked_by_name'],
                'note'            => $f['note'],
            ];
        }
    }

    // Status change log, also batched.
    $logRows = dbFetchAll(
        "SELECT l.sector_id, l.from_status, l.to_status, l.note, l.created_at, u.name AS user_name,
                mt.codename, mt.team_number
         FROM mission_sector_status_log l
         LEFT JOIN users u ON u.id = l.user_id
         LEFT JOIN mission_teams mt ON mt.id = l.team_id
         WHERE l.sector_id IN ($placeholders)
         ORDER BY l.sector_id, l.created_at",
        $sectorIds
    );
    $logBySector = [];
    foreach ($logRows as $l) {
        $logBySector[(int) $l['sector_id']][] = [
            'from_status' => $l['from_status'],
            'to_status'   => $l['to_status'],
            'note'        => $l['note'],
            'user_name'   => $l['user_name'],
            'team_label'  => $l['codename'] ? teamLabel($l['codename'], $l['team_number']) : null,
            'time'        => date('d/m H:i', strtotime($l['created_at'])),
        ];
    }

    $myTeamId = getUserTeamIdForMission($missionId, $userId);

    return array_map(function ($row) use ($canManageWarRoom, $isApprovedParticipant, $myTeamId, $buildingsBySector, $floorsByBuilding, $logBySector) {
        $sectorId = (int) $row['id'];
        $teamId = $row['team_id'] ? (int) $row['team_id'] : null;
        $isMyTeam = $teamId !== null && $teamId === $myTeamId;
        [$teamColorBg, $teamColorFg] = teamBadgeColors($teamId ? $row['color'] : null);

        $buildings = array_map(function ($b) use ($floorsByBuilding) {
            $floors = $floorsByBuilding[(int) $b['id']] ?? [];
            $requiredFloors = array_values(array_filter($floors, fn($f) => $f['is_required']));
            // Vacuously "done" if this building has no required floors at
            // all (e.g. shown on the map for awareness only) — nothing
            // needed from it, so it must never block the sector's own
            // "everything's checked" rollup below.
            $allRequiredChecked = empty($requiredFloors)
                || array_reduce($requiredFloors, fn($carry, $f) => $carry && $f['checked_at'] !== null, true);
            return [
                'id'                    => (int) $b['id'],
                'label'                 => $b['label'],
                'lat'                   => (float) $b['lat'],
                'lng'                   => (float) $b['lng'],
                'floor_count'           => (int) $b['floor_count'],
                'floors'                => $floors,
                'all_required_checked'  => $allRequiredChecked,
            ];
        }, $buildingsBySector[$sectorId] ?? []);

        // Only meaningful when the sector actually has buildings — an
        // empty-buildings sector (no urban checklist at all) stays false
        // here, but the client only ever reads this alongside buildings.length
        // > 0, so it never wrongly reads as "not yet done."
        $allBuildingsComplete = !empty($buildings)
            && array_reduce($buildings, fn($carry, $b) => $carry && $b['all_required_checked'], true);

        $nextStatus = sectorSelfReportNextStatus($row['status']);
        // A freshly-assigned, not-yet-acknowledged sector must be acknowledged
        // before the team can advance it further — same two-step shape as
        // Route Orders' separate ack-then-depart (mission_order_recipients
        // .acknowledged_at gating mission_route_progress.departed_at).
        $needsAcknowledgeFirst = $row['status'] === 'assigned' && !$row['acknowledged_at'];

        return [
            'id'                     => $sectorId,
            'area_id'                => (int) $row['area_id'],
            'label'                  => $row['label'],
            'geo'                    => json_decode($row['geo'], true),
            'status'                 => $row['status'],
            'status_label'           => sectorStatusLabel($row['status']),
            'status_color'           => SECTOR_STATUS_COLORS[$row['status']] ?? 'secondary',
            'status_updated_at'      => $row['status_updated_at'] ? date('d/m H:i', strtotime($row['status_updated_at'])) : null,
            'status_updated_by_name' => $row['status_updated_by_name'],
            'acknowledged_at'        => $row['acknowledged_at'] ? date('d/m H:i', strtotime($row['acknowledged_at'])) : null,
            'acknowledged_by_name'   => $row['acknowledged_by_name'],
            'team_id'                => $teamId,
            'team_label'             => $teamId ? teamLabel($row['codename'], $row['team_number']) : t('sector.unassigned_option'),
            'team_color_bg'          => $teamColorBg,
            'team_color_fg'          => $teamColorFg,
            'is_my_team'             => $isMyTeam,
            'can_manage'             => $canManageWarRoom,
            'can_acknowledge'        => $isApprovedParticipant && $isMyTeam && $needsAcknowledgeFirst,
            'can_self_report'        => $isApprovedParticipant && $isMyTeam && $nextStatus !== null && !$needsAcknowledgeFirst,
            'next_status'            => $nextStatus,
            'next_status_label'      => $nextStatus ? sectorStatusLabel($nextStatus) : null,
            'buildings'              => $buildings,
            'all_buildings_complete' => $allBuildingsComplete,
            'status_log'             => $logBySector[$sectorId] ?? [],
            'created_at'             => date('d/m H:i', strtotime($row['created_at'])),
            'created_by_name'        => $row['created_by_name'],
        ];
    }, $rows);
}

/**
 * Self-report status progression for search sectors — shared by
 * loadMissionSectorsForUser() above (to compute next_status/can_self_report)
 * and mission-sector.php's `status` action (to validate what a non-admin
 * submitted), so the button shown and the action actually allowed can never
 * drift apart. Admin overrides are NOT restricted to this adjacency — this
 * function only governs the self-report path.
 */
function sectorSelfReportNextStatus(string $currentStatus): ?string {
    return [
        'assigned'      => 'en_route',
        'en_route'      => 'in_progress',
        'in_progress'   => 'completed',
        'completed'     => 'needs_recheck',
        'needs_recheck' => 'in_progress',
    ][$currentStatus] ?? null;
}

/**
 * War Room "Εντολή Πορείας": load routes visible to $userId — every route of
 * the mission for command staff (the live admin panel needs the full
 * picture, including closed ones), or just their own team's route(s) for an
 * ordinary participant. Shared by war-room.php (initial render) and
 * mission-route.php (AJAX poll + the return value of every mutating action),
 * same "one loader, every caller" pattern as loadMissionDispatchesForUser()
 * above. See includes/migrations.php v105 for the underlying table shapes.
 */
function loadRoutesForUser(int $missionId, int $userId, bool $canManageWarRoom): array {
    $myTeamId = getUserTeamIdForMission($missionId, $userId);

    $params = [$missionId];
    $teamFilter = '';
    if (!$canManageWarRoom) {
        // Visibility (and, in mission-route.php, authorization to act) is
        // purely mission_route_members — a route may only involve a subset
        // of its nominal team (see migration v109), so filtering on
        // r.team_id alone would show/hide the wrong set of routes for a
        // subset assignment. No $myTeamId-based early return above either:
        // a teamless participant could in principle still be an
        // individually assigned route member.
        $teamFilter = ' AND EXISTS (SELECT 1 FROM mission_route_members rm WHERE rm.route_id = r.id AND rm.user_id = ?)';
        $params[] = $userId;
    }

    $routes = dbFetchAll(
        "SELECT r.id, r.team_id, r.order_id, r.title, r.is_closed_loop, r.ring_index, r.created_at, r.completed_at, r.cancelled_at, r.cancel_reason,
                mt.codename, mt.team_number, mt.color, cu.name AS created_by_name
         FROM mission_routes r
         LEFT JOIN mission_teams mt ON mt.id = r.team_id
         JOIN users cu ON cu.id = r.created_by
         WHERE r.mission_id = ?$teamFilter
         ORDER BY r.created_at DESC",
        $params
    );
    if (empty($routes)) {
        return [];
    }

    $routeIds = array_map(fn($r) => (int) $r['id'], $routes);
    $placeholders = implode(',', array_fill(0, count($routeIds), '?'));

    $waypointRows = dbFetchAll(
        "SELECT w.id, w.route_id, w.seq, w.lat, w.lng, w.label, w.instructions, w.dwell_minutes,
                w.require_photo, w.require_video, w.require_note,
                p.departed_at, du.name AS departed_by_name,
                p.arrived_at, au.name AS arrived_by_name, p.arrived_distance_m, p.arrived_accuracy_m,
                p.completed_at, cu2.name AS completed_by_name,
                p.skipped_at, su.name AS skipped_by_name, p.skip_reason, p.note, p.out_of_sequence
         FROM mission_route_waypoints w
         LEFT JOIN mission_route_progress p ON p.waypoint_id = w.id
         LEFT JOIN users du ON du.id = p.departed_by
         LEFT JOIN users au ON au.id = p.arrived_by
         LEFT JOIN users cu2 ON cu2.id = p.completed_by
         LEFT JOIN users su ON su.id = p.skipped_by
         WHERE w.route_id IN ($placeholders)
         ORDER BY w.route_id, w.seq",
        $routeIds
    );

    // One photo thumbnail AND one video thumbnail per waypoint, tracked in
    // separate slots — a waypoint can require both at once (independent
    // checkboxes in the composer), so collapsing them into a single "latest
    // upload" slot would make whichever came first disappear from the card
    // the moment the second one was sent. ORDER BY created_at ASC so the
    // foreach's natural overwrite deterministically keeps the latest of each
    // type (last-wins is only meaningful if the scan order is fixed).
    //
    // Bound against WAYPOINT ids, not $routeIds/$placeholders (route ids) —
    // route_waypoint_id is a waypoint id column, so binding route ids here
    // silently returned the wrong rows (or none) for every waypoint whose id
    // didn't happen to also be a valid route id for this mission. Confirmed
    // live: a route with 5 waypoints only ever showed a photo for whichever
    // one's id coincided with a route id (here, seq-1's id 8 == a route id
    // 8), every other waypoint's already-uploaded photo silently never
    // rendered. Present since this query was introduced (v3.124.0).
    $photosByWaypoint = [];
    $videosByWaypoint = [];
    $waypointIds = array_map(fn($w) => (int) $w['id'], $waypointRows);
    if (!empty($waypointIds)) {
        $wpPlaceholders = implode(',', array_fill(0, count($waypointIds), '?'));
        foreach (dbFetchAll(
            "SELECT route_waypoint_id, id, media_type, thumb_stored_name, created_at FROM mission_photos WHERE route_waypoint_id IN ($wpPlaceholders) ORDER BY created_at ASC",
            $waypointIds
        ) as $ph) {
            $entry = ['id' => (int) $ph['id'], 'has_thumb' => $ph['thumb_stored_name'] !== null, 'time' => date('d/m H:i', strtotime($ph['created_at']))];
            if ($ph['media_type'] === 'video') {
                $videosByWaypoint[(int) $ph['route_waypoint_id']] = $entry;
            } else {
                $photosByWaypoint[(int) $ph['route_waypoint_id']] = $entry;
            }
        }
    }

    // Route members: who this specific route actually applies to (a subset
    // of the nominal team, or the whole team — see migration v109). Drives
    // both is_route_member (actionability, replacing the old is_my_team
    // gate) and members (so command staff can see "this route only involves
    // Γ, Δ" instead of assuming the whole team is out).
    $membersByRoute = [];
    foreach (dbFetchAll(
        "SELECT rm.route_id, rm.user_id, u.name FROM mission_route_members rm
         JOIN users u ON u.id = rm.user_id
         WHERE rm.route_id IN ($placeholders)
         ORDER BY u.name",
        $routeIds
    ) as $rm) {
        $membersByRoute[(int) $rm['route_id']][] = ['id' => (int) $rm['user_id'], 'name' => $rm['name']];
    }

    // Viewer's own acknowledgment of each route's underlying order — gates
    // the waypoint UI behind an explicit "Ελήφθη" press client-side (see
    // mission-order.php's acknowledge action, which is what actually stamps
    // this). Keyed by order_id, not route_id: that's the real column
    // mission_order_recipients is keyed on, and this is the viewer's own
    // status, not a shared/team-wide one.
    $ackByOrderId = [];
    $orderIds = array_values(array_filter(array_map(fn($r) => $r['order_id'] ? (int) $r['order_id'] : null, $routes)));
    if (!empty($orderIds)) {
        $orderPlaceholders = implode(',', array_fill(0, count($orderIds), '?'));
        foreach (dbFetchAll(
            "SELECT order_id, acknowledged_at FROM mission_order_recipients WHERE order_id IN ($orderPlaceholders) AND user_id = ?",
            [...$orderIds, $userId]
        ) as $row) {
            $ackByOrderId[(int) $row['order_id']] = (bool) $row['acknowledged_at'];
        }
    }

    $waypointsByRoute = [];
    foreach ($waypointRows as $w) {
        $waypointId = (int) $w['id'];
        $waypointsByRoute[(int) $w['route_id']][] = [
            'id'                    => $waypointId,
            'seq'                   => (int) $w['seq'],
            'lat'                   => (float) $w['lat'],
            'lng'                   => (float) $w['lng'],
            'label'                 => $w['label'],
            'instructions'          => $w['instructions'],
            'dwell_minutes'         => $w['dwell_minutes'] !== null ? (int) $w['dwell_minutes'] : null,
            'require_photo'         => (bool) $w['require_photo'],
            'require_video'         => (bool) $w['require_video'],
            'require_note'          => (bool) $w['require_note'],
            // *_at is ISO 8601 (parses reliably with JS `new Date()` cross-browser,
            // unlike MySQL's raw "Y-m-d H:i:s") for the live dwell-time countdown;
            // *_at_display is the pre-formatted H:i string every other loader here
            // already returns, for plain display.
            'departed_at'           => $w['departed_at'] ? date('c', strtotime($w['departed_at'])) : null,
            'departed_at_display'   => $w['departed_at'] ? date('H:i', strtotime($w['departed_at'])) : null,
            'departed_by_name'      => $w['departed_by_name'],
            'arrived_at'            => $w['arrived_at'] ? date('c', strtotime($w['arrived_at'])) : null,
            'arrived_at_display'    => $w['arrived_at'] ? date('H:i', strtotime($w['arrived_at'])) : null,
            'arrived_by_name'       => $w['arrived_by_name'],
            'arrived_distance_m'    => $w['arrived_distance_m'] !== null ? (int) $w['arrived_distance_m'] : null,
            'arrived_accuracy_m'    => $w['arrived_accuracy_m'] !== null ? (float) $w['arrived_accuracy_m'] : null,
            'completed_at'          => $w['completed_at'] ? date('c', strtotime($w['completed_at'])) : null,
            'completed_at_display'  => $w['completed_at'] ? date('H:i', strtotime($w['completed_at'])) : null,
            'completed_by_name'     => $w['completed_by_name'],
            'skipped_at_display'    => $w['skipped_at'] ? date('H:i', strtotime($w['skipped_at'])) : null,
            'skipped_by_name'       => $w['skipped_by_name'],
            'skip_reason'           => $w['skip_reason'],
            'note'                  => $w['note'],
            'out_of_sequence'       => (bool) $w['out_of_sequence'],
            'photo'                 => $photosByWaypoint[$waypointId] ?? null,
            'video'                 => $videosByWaypoint[$waypointId] ?? null,
        ];
    }

    return array_map(function ($r) use ($waypointsByRoute, $membersByRoute, $ackByOrderId, $canManageWarRoom, $myTeamId, $userId) {
        $routeId = (int) $r['id'];
        $teamId = $r['team_id'] ? (int) $r['team_id'] : null;
        $orderId = $r['order_id'] ? (int) $r['order_id'] : null;
        [$teamColorBg, $teamColorFg] = teamBadgeColors($teamId ? $r['color'] : null);
        $members = $membersByRoute[$routeId] ?? [];
        return [
            'id'                    => $routeId,
            'team_id'               => $teamId,
            'order_id'              => $orderId,
            // Whether THIS viewer has acknowledged the route's order — a
            // route created before this feature shipped (order_id somehow
            // null, shouldn't happen post-migration but defensive regardless)
            // reads as already-acknowledged so the gate never gets stuck
            // with no way through.
            'my_acknowledged_at'    => $orderId ? ($ackByOrderId[$orderId] ?? false) : true,
            // No single nominal team (cross-team route, migration v110) —
            // the member names ($members, already loaded above) stand in
            // for a team label instead of a generic "mixed" placeholder.
            'team_label'            => $teamId ? teamLabel($r['codename'], $r['team_number']) : implode(', ', array_column($members, 'name')),
            'team_color_bg'         => $teamColorBg,
            'team_color_fg'         => $teamColorFg,
            'title'                 => $r['title'],
            'is_closed_loop'        => (bool) $r['is_closed_loop'],
            'ring_index'            => $r['ring_index'] !== null ? (int) $r['ring_index'] : null,
            'status'                => $r['cancelled_at'] ? 'cancelled' : ($r['completed_at'] ? 'completed' : 'active'),
            'created_at_display'    => date('d/m H:i', strtotime($r['created_at'])),
            'created_by_name'       => $r['created_by_name'],
            'completed_at_display'  => $r['completed_at'] ? date('d/m H:i', strtotime($r['completed_at'])) : null,
            'cancelled_at_display'  => $r['cancelled_at'] ? date('d/m H:i', strtotime($r['cancelled_at'])) : null,
            'cancel_reason'         => $r['cancel_reason'],
            'can_manage'            => $canManageWarRoom,
            // Kept for team-badge context, but no longer read for
            // actionability anywhere (see is_route_member below).
            'is_my_team'            => $teamId !== null && $teamId === $myTeamId,
            // The real authorization/visibility signal for "is this route
            // mine to act on" — mission_route_members, not team membership.
            'is_route_member'       => in_array($userId, array_column($members, 'id'), true),
            'members'               => $members,
            'waypoints'             => $waypointsByRoute[$routeId] ?? [],
        ];
    }, $routes);
}

/**
 * War Room "Πορεία Ομάδων": full historical GPS trail per volunteer for a
 * mission (not just the latest ping like the live map's $loadPins), grouped
 * by user_id (not user+shift — a volunteer has at most one team per mission
 * per uniq_mission_user, so merging pings across their shift assignments
 * into one continuous trail is correct). $teamId = 0 means all teams; the
 * mtm.mission_id = s.mission_id join condition makes a team_id from another
 * mission safely match nothing, no separate ownership check needed.
 * Auto-captured pings (source='auto') are excluded unless $includeAuto is
 * true — admin-only opt-in filter, off by default everywhere else.
 */
function loadMissionTrailForMission(int $missionId, int $teamId, bool $includeAuto): array {
    // Capped PER USER (3000 most recent points each — ~6 days of continuous
    // 180s auto-pinging for a single volunteer, far past any realistic
    // single mission's length), not by one shared LIMIT across every row —
    // a flat LIMIT here, ordered by user_id first, meant that once total
    // pings for the mission exceeded the cap, whichever volunteers happened
    // to have the numerically highest user_id lost part or all of their
    // trail while everyone else kept theirs in full, for no reason
    // connected to anything about their actual participation.
    $rows = dbFetchAll(
        "SELECT user_id, lat, lng, created_at, source, name, team_id, team_color FROM (
            SELECT vp.user_id, vp.lat, vp.lng, vp.created_at, vp.source,
                    u.name, mtm.team_id, mt.color AS team_color,
                    ROW_NUMBER() OVER (PARTITION BY vp.user_id ORDER BY vp.created_at DESC) AS rn
             FROM volunteer_pings vp
             JOIN shifts s ON s.id = vp.shift_id
             JOIN users u ON u.id = vp.user_id
             LEFT JOIN mission_team_members mtm ON mtm.mission_id = s.mission_id AND mtm.user_id = vp.user_id
             LEFT JOIN mission_teams mt ON mt.id = mtm.team_id
             WHERE s.mission_id = ?
               AND (? = 0 OR mtm.team_id = ?)
               AND (vp.source = 'manual' OR ? = 1)
         ) ranked
         WHERE rn <= 3000
         ORDER BY user_id, created_at",
        [$missionId, $teamId, $teamId, $includeAuto ? 1 : 0]
    );

    // Heart rate for the same window, one value per user per minute, so each
    // trail point can carry what the volunteer's pulse was doing where they
    // were standing. Loaded once for the whole mission rather than per point —
    // see loadVitalsBucketedForMission() for why it is bucketed. Empty array
    // when the feature is off, which makes every lookup below a miss and every
    // point vitals-free, with no extra branch needed here.
    $vitalsByMinute = loadVitalsBucketedForMission($missionId);

    $trailsByUser = [];
    foreach ($rows as $row) {
        $userId = (int) $row['user_id'];
        if (!isset($trailsByUser[$userId])) {
            $trailsByUser[$userId] = [
                'user_id'    => $userId,
                'name'       => $row['name'],
                'team_color' => $row['team_color'],
                'points'     => [],
            ];
        }
        // Flat safety ceiling per trail, not a real pagination control — this
        // codebase has no window-function usage anywhere, so trimming here in
        // PHP (keep the most recent points) matches its existing style.
        if (count($trailsByUser[$userId]['points']) >= 1000) {
            array_shift($trailsByUser[$userId]['points']);
        }
        // The ping's own minute first, then the two either side. A ping and a
        // heart-rate sample are produced by two independent timers that never
        // line up, so a ping landing at 10:03:59 routinely has its nearest
        // reading in the 10:04 bucket — insisting on an exact bucket match
        // would drop roughly one point in three for no reason a viewer could
        // understand. One minute of tolerance is well inside the resolution
        // this overlay claims.
        $pingTs = strtotime($row['created_at']);
        $vitals = $vitalsByMinute[$userId][vitalsBucketKey($pingTs)]
            ?? $vitalsByMinute[$userId][vitalsBucketKey($pingTs - 60)]
            ?? $vitalsByMinute[$userId][vitalsBucketKey($pingTs + 60)]
            ?? null;

        $trailsByUser[$userId]['points'][] = [
            'lat'    => (float) $row['lat'],
            'lng'    => (float) $row['lng'],
            'bpm'    => $vitals ? $vitals['bpm'] : null,
            'hr_zone' => $vitals ? $vitals['zone'] : null,
            // 'd/m H:i' (not the live dot's bare 'H:i') — a trail is often
            // reviewed on a different day than it was recorded. Formatted
            // server-side since PHP/MySQL are both synced to Europe/Athens;
            // sending raw created_at for client-side Date parsing would use
            // the viewer's own browser timezone instead.
            'time'   => date('d/m H:i', strtotime($row['created_at'])),
            'source' => $row['source'],
            // Unix epoch seconds, alongside (not instead of) the pre-formatted
            // 'time' above — added for the replay scrubber's timeline math
            // (comparing/sorting points, positioning a slider), where a plain
            // integer sidesteps the exact browser-timezone concern 'time'
            // itself already exists to avoid; every consumer of 'time' is
            // untouched.
            'ts'     => strtotime($row['created_at']),
        ];
    }

    return array_values($trailsByUser);
}

/**
 * War Room: load field photos/videos for a mission, newest first. Visibility is
 * "everyone with War Room access sees everything" (unlike dispatches, which
 * are team-scoped) — so this is a flat query, no per-user filtering.
 */
function loadMissionPhotosForUser(int $missionId, int $currentUserId, bool $canManageWarRoom, int $limit = 30): array {
    $rows = dbFetchAll(
        "SELECT p.id, p.user_id, p.media_type, p.thumb_stored_name, p.lat, p.lng, p.created_at, p.poi_id, p.poi_note,
                u.name AS user_name, u.is_external, u.guest_org_name, u.guest_country_code,
                COALESCE(vt.name, mvt.label) AS home_team_name, COALESCE(vt.color, mvt.color) AS home_team_color,
                mt.codename, mt.team_number
         FROM mission_photos p
         JOIN users u ON u.id = p.user_id
         LEFT JOIN volunteer_teams vt ON vt.id = u.volunteer_team_id
         LEFT JOIN mission_visitor_tags mvt ON mvt.id = u.mission_visitor_tag_id
         LEFT JOIN mission_team_members mtm ON mtm.user_id = p.user_id AND mtm.mission_id = p.mission_id
         LEFT JOIN mission_teams mt ON mt.id = mtm.team_id
         WHERE p.mission_id = ? AND p.order_id IS NULL
         ORDER BY p.created_at DESC
         LIMIT ?",
        [$missionId, $limit]
    );

    return array_map(function ($row) use ($canManageWarRoom, $currentUserId) {
        [$homeBg, $homeFg] = teamBadgeColors($row['home_team_color']);
        return [
            'id'                 => (int) $row['id'],
            'media_type'         => $row['media_type'],
            'has_thumb'          => $row['thumb_stored_name'] !== null,
            'user_name'          => $row['user_name'],
            'user_id'            => (int) $row['user_id'],
            'is_external'        => (bool) $row['is_external'],
            'guest_org_name'     => $row['guest_org_name'],
            'home_team_name'     => $row['home_team_name'],
            'home_team_color_bg' => $homeBg,
            'home_team_color_fg' => $homeFg,
            'guest_country_code' => $row['guest_country_code'],
            'team_label'         => $row['codename'] ? teamLabel($row['codename'], $row['team_number']) : null,
            'time'               => date('d/m H:i', strtotime($row['created_at'])),
            'lat'                => $row['lat'] !== null ? (float) $row['lat'] : null,
            'lng'                => $row['lng'] !== null ? (float) $row['lng'] : null,
            'can_delete'         => $canManageWarRoom || (int) $row['user_id'] === $currentUserId,
            'is_poi'             => $row['poi_id'] !== null,
            'poi_note'           => $row['poi_note'],
        ];
    }, $rows);
}

/**
 * Action Room: the small JPEG behind every photo drawn as a tile — the media
 * gallery, the POI and route strips, the broadcast card, and mission-stats'
 * own gallery and map popups.
 *
 * Those tiles are between 50 and 120 pixels, and every one of them used to
 * download the full stored image: 1920px on its long edge, because that is
 * what compressPhotoForUpload() produces. A 30-item gallery was ~12MB of
 * photograph to draw 30 stamps, re-decoded by the browser on every render.
 *
 * Generated on first request rather than at upload time, deliberately:
 *   - it backfills every photo already on disk, with no migration to run, and
 *   - the work lands on a coordinator's browser rather than on the volunteer's
 *     phone mid-upload, on whatever signal the field has.
 * The cost is paid once per photo, by whoever views it first.
 *
 * Returns the absolute path of a usable thumbnail, or null. Null is not an
 * error — it means "serve the original instead", and every failure path
 * returns it: no GD, an unreadable or corrupt file, an image large enough to
 * be a decompression bomb, a full disk. A thumbnail is an optimisation, and it
 * must never be able to make a photo unviewable.
 */
function ensureMissionPhotoThumbnail(array $photo): ?string {
    $dir = __DIR__ . '/../uploads/mission-photos/';
    $staleName = (string) ($photo['thumb_stored_name'] ?? '');

    if ($staleName !== '') {
        $existing = $dir . basename($staleName);
        if (is_file($existing) && is_readable($existing)) {
            return $existing;
        }
    }

    // A video's thumbnail is the poster frame its uploader captured
    // client-side (see mission-photo.php's 'upload' action). There is no frame
    // to extract server-side without ffmpeg, so a video that arrived without
    // one simply has no thumbnail, exactly as before.
    if (($photo['media_type'] ?? '') !== 'photo' || !function_exists('imagecreatefromstring')) {
        return null;
    }

    $sourcePath = $dir . basename((string) ($photo['stored_name'] ?? ''));
    if (!is_file($sourcePath) || !is_readable($sourcePath)) {
        return null;
    }

    // Dimensions read before decoding, not after: imagecreatefromstring()
    // allocates 4 bytes per pixel, so a 50MP image is 200MB of memory and
    // would take the whole request down with it. Nothing that large is a field
    // photo — the client caps its uploads at 1920px on the long edge.
    $info = @getimagesize($sourcePath);
    if ($info === false || $info[0] < 1 || $info[1] < 1 || ($info[0] * $info[1]) > 50000000) {
        return null;
    }

    $source = @imagecreatefromstring((string) file_get_contents($sourcePath));
    if (!$source instanceof GdImage) {
        return null;
    }

    $scale = min(1, MISSION_PHOTO_THUMB_LONG_EDGE / max($info[0], $info[1]));
    $width = max(1, (int) round($info[0] * $scale));
    $height = max(1, (int) round($info[1] * $scale));

    $thumb = imagecreatetruecolor($width, $height);
    // Flattened onto white first: the output is JPEG, which has no alpha
    // channel, so a transparent PNG source would otherwise come out black.
    imagefilledrectangle($thumb, 0, 0, $width, $height, imagecolorallocate($thumb, 255, 255, 255));
    imagecopyresampled($thumb, $source, 0, 0, 0, 0, $width, $height, $info[0], $info[1]);
    imagedestroy($source);

    // A browser honours a JPEG's EXIF orientation tag when it renders it
    // (image-orientation: from-image has been the CSS default for years); GD
    // does not. Without this, a photo from a phone that records orientation
    // instead of rotating pixels shows upright everywhere in the app and
    // sideways in its own thumbnail. Applied to the thumbnail rather than the
    // source because it is 16x smaller; rotation only swaps the two sides, so
    // the result still fits inside the same long-edge box.
    $orientation = 1;
    if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $exif = @exif_read_data($sourcePath);
        if (!empty($exif['Orientation'])) {
            $orientation = (int) $exif['Orientation'];
        }
    }
    if (in_array($orientation, [2, 5, 7], true)) {
        imageflip($thumb, IMG_FLIP_HORIZONTAL);
    } elseif ($orientation === 4) {
        imageflip($thumb, IMG_FLIP_VERTICAL);
    }
    // imagerotate() turns counter-clockwise, the EXIF table is clockwise.
    $angle = [3 => 180, 5 => 90, 6 => -90, 7 => -90, 8 => 90][$orientation] ?? 0;
    if ($angle !== 0) {
        $rotated = imagerotate($thumb, $angle, 0);
        if ($rotated instanceof GdImage) {
            imagedestroy($thumb);
            $thumb = $rotated;
        }
    }

    $thumbName = 'mphoto_' . (int) ($photo['mission_id'] ?? 0) . '_' . time()
        . '_' . bin2hex(random_bytes(4)) . '_thumb.jpg';
    $tempPath = $dir . $thumbName . '.tmp';
    $written = @imagejpeg($thumb, $tempPath, MISSION_PHOTO_THUMB_QUALITY);
    imagedestroy($thumb);
    if (!$written) {
        @unlink($tempPath);
        return null;
    }
    // Renamed into place rather than written there, so a second request for
    // the same photo can never read a half-written JPEG. The name is unique,
    // so the rename never overwrites anything.
    if (!@rename($tempPath, $dir . $thumbName)) {
        @unlink($tempPath);
        return null;
    }

    // The row already named a thumbnail, it was just missing from disk (a
    // restore that skipped uploads/, a manual cleanup). Replace it outright —
    // the compare-and-set below would refuse, and the photo would then
    // regenerate a thumbnail it could never record, on every single view.
    if ($staleName !== '') {
        dbExecute(
            "UPDATE mission_photos SET thumb_stored_name = ? WHERE id = ?",
            [$thumbName, (int) $photo['id']]
        );
        return $dir . $thumbName;
    }

    // Compare-and-set, because two coordinators opening the Action Room in the
    // same second both generate one. The loser deletes its own file and uses
    // the winner's, so the row and the disk never disagree and no orphan is
    // left behind.
    $claimed = dbExecute(
        "UPDATE mission_photos SET thumb_stored_name = ? WHERE id = ? AND thumb_stored_name IS NULL",
        [$thumbName, (int) $photo['id']]
    );
    if ($claimed === 0) {
        @unlink($dir . $thumbName);
        $winner = (string) dbFetchValue(
            "SELECT thumb_stored_name FROM mission_photos WHERE id = ?",
            [(int) $photo['id']]
        );
        $winnerPath = $winner !== '' ? $dir . basename($winner) : '';
        return ($winnerPath !== '' && is_file($winnerPath)) ? $winnerPath : null;
    }

    return $dir . $thumbName;
}

/**
 * War Room: load reference photos attached to a Καθολικό Μήνυμα (global
 * broadcast message) — the coordinator-to-field direction, opposite of
 * loadMissionPhotosForUser()'s field-to-coordinator gallery, and always
 * shown in its own card so the two are never confused for one another.
 * Visible to every command-staff/approved-participant viewer alike (no
 * per-viewer filtering, same reasoning as the broadcast itself going to
 * everyone); can_delete is simply $canManageWarRoom since only command
 * staff can ever create one of these in the first place.
 */
/**
 * Live streams for a mission that are not finished yet — both 'requested'
 * (asked, not yet accepted) and 'live' (publishing). Command staff need to see
 * the pending ones too, otherwise a request that is never accepted looks
 * identical to a request that was never sent.
 *
 * seconds_left is computed in SQL against started_at so the server, not the
 * browser clock, decides when MISSION_LIVE_MAX_SECONDS has run out.
 */
function loadActiveLiveStreamsForMission(int $missionId): array {
    return dbFetchAll(
        "SELECT ls.id, ls.user_id, ls.status, ls.started_at,
                u.name, u.is_external, u.guest_country_code,
                vt.name AS home_team_name, vt.color AS home_team_color,
                GREATEST(0, ? - TIMESTAMPDIFF(SECOND, ls.started_at, NOW())) AS seconds_left
         FROM mission_live_streams ls
         JOIN users u ON u.id = ls.user_id
         LEFT JOIN volunteer_teams vt ON vt.id = u.volunteer_team_id
         WHERE ls.mission_id = ? AND ls.status <> 'ended'
         ORDER BY ls.id ASC",
        [MISSION_LIVE_MAX_SECONDS, $missionId]
    );
}

/**
 * The caller's own live stream row for this mission, if one is open. Drives
 * the volunteer-facing card: a pending request they can accept, or a session
 * already running (after a page reload, say).
 */
function loadMyLiveStreamForUser(int $missionId, int $userId): ?array {
    $row = dbFetchOne(
        "SELECT id, status, started_at,
                GREATEST(0, ? - TIMESTAMPDIFF(SECOND, started_at, NOW())) AS seconds_left
         FROM mission_live_streams
         WHERE mission_id = ? AND user_id = ? AND status <> 'ended'
         ORDER BY id DESC LIMIT 1",
        [MISSION_LIVE_MAX_SECONDS, $missionId, $userId]
    );
    return $row ?: null;
}

/**
 * Close live streams that have outrun their limits. Called from the Action
 * Room's own request cycle rather than from cron: this must hold even on an
 * install where nobody ever set cron up, and the only moment it actually
 * matters is while someone is looking at the board.
 *
 * The cap is enforced HERE, not by the browser countdown — that one is
 * decoration. A frozen tab, a tampered clock or a closed laptop must not be
 * able to extend a live camera pointed at a person.
 */
function expireStaleLiveStreams(int $missionId): void {
    $expired = dbFetchAll(
        "SELECT id, user_id FROM mission_live_streams
         WHERE mission_id = ? AND status = 'live' AND started_at IS NOT NULL
           AND TIMESTAMPDIFF(SECOND, started_at, NOW()) >= ?",
        [$missionId, MISSION_LIVE_MAX_SECONDS]
    );
    foreach ($expired as $row) {
        livekitRemoveParticipant($missionId, livekitIdentity((int) $row['user_id'], 'publisher'));
        dbExecute(
            "UPDATE mission_live_streams SET status = 'ended', ended_at = NOW(), end_reason = 'timeout'
             WHERE id = ? AND status <> 'ended'",
            [$row['id']]
        );
    }

    // Asked but never answered. Nothing to disconnect — no session was ever
    // created — so this is a plain database close.
    dbExecute(
        "UPDATE mission_live_streams SET status = 'ended', ended_at = NOW(), end_reason = 'timeout'
         WHERE mission_id = ? AND status = 'requested'
           AND created_at < DATE_SUB(NOW(), INTERVAL ? SECOND)",
        [$missionId, MISSION_LIVE_REQUEST_EXPIRY_SECONDS]
    );
}

/**
 * End every open stream for a mission — used when the mission itself closes.
 * A mission that is over must not leave a camera running.
 */
function endAllLiveStreamsForMission(int $missionId, ?int $byUserId = null): int {
    $open = dbFetchAll(
        "SELECT id, user_id, status FROM mission_live_streams WHERE mission_id = ? AND status <> 'ended'",
        [$missionId]
    );
    foreach ($open as $row) {
        if ($row['status'] === 'live') {
            livekitRemoveParticipant($missionId, livekitIdentity((int) $row['user_id'], 'publisher'));
        }
    }
    if ($open) {
        dbExecute(
            "UPDATE mission_live_streams SET status = 'ended', ended_at = NOW(),
                    end_reason = 'mission_closed', ended_by = ?
             WHERE mission_id = ? AND status <> 'ended'",
            [$byUserId, $missionId]
        );
    }
    return count($open);
}

function loadBroadcastPhotosForMission(int $missionId, bool $canManageWarRoom, int $limit = 15): array {
    $rows = dbFetchAll(
        "SELECT p.id, p.created_at, u.name AS user_name, o.task_text AS caption
         FROM mission_photos p
         JOIN mission_orders o ON o.id = p.order_id
         JOIN users u ON u.id = p.user_id
         WHERE p.mission_id = ? AND p.order_id IS NOT NULL
         ORDER BY p.created_at DESC
         LIMIT ?",
        [$missionId, $limit]
    );

    return array_map(fn($row) => [
        'id'         => (int) $row['id'],
        'user_name'  => $row['user_name'],
        'caption'    => $row['caption'],
        'time'       => date('d/m H:i', strtotime($row['created_at'])),
        'can_delete' => $canManageWarRoom,
    ], $rows);
}

/**
 * Action Room: load every shared battle-map annotation (freehand/arrow/text)
 * for a mission. Unlike dispatch points, there's no per-team targeting or
 * per-viewer ack state — every approved participant sees every annotation,
 * and only command staff can create/delete (enforced entirely by whether the
 * drawing toolbar renders at all, not by a per-row flag here).
 */
function loadMissionAnnotationsForMission(int $missionId): array {
    $rows = dbFetchAll(
        "SELECT a.id, a.type, a.geo, a.label, u.name AS created_by_name, a.created_at
         FROM mission_annotations a
         JOIN users u ON u.id = a.created_by
         WHERE a.mission_id = ?
         ORDER BY a.created_at",
        [$missionId]
    );
    return array_map(fn($row) => [
        'id'              => (int) $row['id'],
        'type'            => $row['type'],
        'geo'             => json_decode($row['geo'], true),
        'label'           => $row['label'],
        'created_by_name' => $row['created_by_name'],
        'time'            => date('H:i', strtotime($row['created_at'])),
    ], $rows);
}

/**
 * War Room: load $userId's own personal orders for a mission — the "Οι
 * Εντολές μου" self-service checklist. Covers every order_type that targets
 * one specific recipient and is meaningful to track as "still owed": task,
 * the field-request types (location/photo/video/live), and charge_phone.
 *
 * charge_phone and speak have no fulfilment event of their own — nothing in
 * the app can observe a phone being plugged in, or a person hearing a
 * sentence — so for those two types acknowledging IS completing, and
 * war-room.php's renderer branches on that. Treating them like the rest
 * would leave a row nothing could ever clear.
 *
 * A voice announcement belongs in this card even though it is broadcast-ish,
 * unlike 'message' below, for one reason: it is the only order whose content
 * can be missed entirely and leave no trace. Sound plays once, into whatever
 * the volunteer's surroundings happen to be, and a browser that has not yet
 * seen a tap may refuse to play it at all. The row is where they read it and
 * press play again.
 *
 * 'task' is the only type ever manually completed by the recipient
 * (mission-order.php action=complete) — location/photo/video instead
 * auto-fulfill themselves the moment the real action happens
 * (ping-location.php / mission-photo.php), so the renderer must branch on
 * order_type to know whether a "Ολοκληρώθηκε" button makes sense at all.
 * 'label' is computed here (not client-side) so every consumer — the live
 * war-room.php renderer AND offline.html's cached-snapshot view, which has
 * no access to war-room.php's own t() dictionary — can just display it
 * without each needing its own order_type-to-text mapping.
 *
 * Shared by war-room.php (full render + ajax poll), like
 * loadMissionPhotosForUser above.
 */
function loadMyTaskOrdersForUser(int $missionId, int $userId): array {
    // 'message' and 'return_to_base' are broadcasts: they announce something
    // rather than ask this person for anything. They were once excluded here
    // for that reason, because a row that could never be cleared would hold
    // the volunteer's orders badge on for the rest of the mission — but the
    // exclusion turned out to be the more serious bug of the two. Their ONLY
    // acknowledgement path was the live scrolling banner, and that banner
    // replays nothing: war-room.php starts each page load at MAX(notification
    // id), so a broadcast sent while the volunteer's tab was closed could
    // never be acknowledged by them at all, and the coordinator's «Τι μου
    // ξέφυγε» panel showed it unanswered forever.
    //
    // They are listed now, and the badge problem is solved where it actually
    // belongs: war-room.php treats them as "acknowledgement IS completion"
    // (the same ackCompletes rule 'charge_phone' and 'speak' already use), so
    // the row clears the moment the volunteer presses «Ελήφθη».
    //
    // Anything NEW that asks the volunteer to act belongs in this list —
    // 'live' was added late and missed it, so a request arrived by push with
    // no trace in the volunteer's orders card.
    //
    // 'route' belongs in that card too but not in this query: it lives in
    // mission_routes with its own waypoint UI, so war-room.php folds it in
    // client-side as a summary row — same for assigned sectors, and for
    // dispatch points/areas, which are not mission_orders rows at all.
    $rows = dbFetchAll(
        "SELECT o.id AS order_id, o.order_type, o.task_text, o.created_at, r.acknowledged_at, r.fulfilled_at
         FROM mission_order_recipients r
         JOIN mission_orders o ON o.id = r.order_id
         WHERE o.mission_id = ? AND r.user_id = ? AND o.order_type IN ('task', 'speak', 'location', 'photo', 'video', 'live', 'charge_phone', 'message', 'return_to_base')
         ORDER BY o.created_at DESC",
        [$missionId, $userId]
    );

    return array_map(function ($row) {
        // 'speak' and 'message' join 'task' here: all three store what the
        // coordinator actually typed, and for an announcement the text IS the
        // order — a row reading "Voice Announcement" would hide the one thing
        // the volunteer needs to re-read when the words went past them.
        // war-room.php escapes all three.
        //
        // The fallback is not defensive padding: a photo-only broadcast is a
        // real and supported case (global_message accepts a photo with no
        // text), and it stores NULL in task_text. Without this it would render
        // as an empty row with an acknowledge button and nothing above it.
        $freeText = in_array($row['order_type'], ['task', 'speak', 'message'], true)
            ? trim((string) $row['task_text'])
            : '';
        return [
            'order_id'        => (int) $row['order_id'],
            'order_type'      => $row['order_type'],
            'task_text'       => $row['task_text'],
            'label'           => $freeText !== '' ? $freeText : t('order.' . $row['order_type'] . '.title'),
            'sent_at'         => date('d/m H:i', strtotime($row['created_at'])),
            'acknowledged_at' => $row['acknowledged_at'] ? date('d/m H:i', strtotime($row['acknowledged_at'])) : null,
            'fulfilled_at'    => $row['fulfilled_at'] ? date('d/m H:i', strtotime($row['fulfilled_at'])) : null,
        ];
    }, $rows);
}

/**
 * War Room: whether $userId has admin/manager-level control of an Action Room
 * (close the mission, broadcast, manage teams, issue orders, view reports, ...).
 * External/guest accounts (users.is_external) are hard-excluded here regardless
 * of hasPagePermission()/responsible_user_id — bootstrap.php's allow-list only
 * restricts *which pages* a guest can reach, this is what stops them gaining
 * admin *powers* once on an allowed one. Concretely: mission-form.php's
 * "Υπεύθυνος Αποστολής" dropdown has no reason to exclude guests by itself
 * (fixed separately, defense-in-depth), so without this check here, picking a
 * partner org's lead as a mission's responsible_user_id would silently hand
 * that guest full Action Room admin powers for that mission.
 */
function canManageActionRoom(?int $responsibleUserId, int $userId): bool {
    if (isExternalGuest()) return false;
    return hasPagePermission('missions_manage') || ($responsibleUserId !== null && $responsibleUserId === $userId);
}

/**
 * Same authorization as canManageActionRoom(), but for a mission-independent
 * check (e.g. saving a cross-mission preference like the war room's card
 * layout) — canManageActionRoom(null, $userId) is NOT equivalent, since it
 * silently drops the responsible_user_id path and would 403 a shift leader
 * who manages their own mission's Action Room but holds no sitewide
 * missions_manage permission. This checks that path across every mission.
 */
function canManageAnyActionRoom(int $userId): bool {
    if (isExternalGuest()) return false;
    if (hasPagePermission('missions_manage')) return true;
    return (bool) dbFetchOne(
        "SELECT 1 FROM missions WHERE responsible_user_id = ? AND deleted_at IS NULL LIMIT 1",
        [$userId]
    );
}

/**
 * War Room: appends an always-visible small badge showing this person's
 * home team (users.volunteer_team_id — "Επίδρασις" for regular members, a
 * partner org for guests; NOT the same as a mission_teams squad) plus their
 * country's flag, at the upper-right of their name (superscript-style, same
 * position the old guest-only org badge always used). $teamColor/$countryCode
 * are optional trailing params so call sites not yet upgraded keep compiling
 * and rendering exactly as before — a byte-identical bare h($name) for
 * regular users, today's plain org-name <sup> for guests.
 *
 * Once upgraded ($teamColor passed), the team name is always shown as
 * visible text next to the flag — for every user, not just guests, per the
 * mission owner's explicit correction after reviewing the shipped feature.
 * Used everywhere a person's name is shown app-wide, including the guest's
 * own profile hero (not just Action Room). Mirrored client-side by the
 * same-named JS function in war-room.php for names that render from a JS
 * poll (chat, media, dispatch, SOS, shortage).
 */
function guestNameHtml(string $name, bool $isExternal, ?string $teamName, ?string $teamColor = null, ?string $countryCode = null): string {
    if ($teamColor === null) {
        // Legacy path — call site not yet upgraded to the team badge/flag.
        if (!$isExternal) {
            return h($name);
        }
        $org = ($teamName !== null && trim($teamName) !== '') ? $teamName : t('guest.org_unknown');
        return h($name) . '<sup class="guest-org-badge" title="' . h(t('guest.org_tooltip', ['org' => $org])) . '">' . h($org) . '</sup>';
    }

    [$bg, $fg] = teamBadgeColors($teamColor);
    // Regular members are always Επίδρασις, a Greek org — fixed, not stored
    // per-row (users.guest_country_code is guest-only data and stays NULL
    // for everyone else), so the flag is hardcoded here rather than left
    // blank for the common case.
    $flag = flagHtml($isExternal ? $countryCode : 'GR');
    $team = ($teamName !== null && trim($teamName) !== '') ? $teamName : t('guest.org_unknown');
    $badge = '<span class="team-name-badge" style="background:' . h($bg) . ';color:' . h($fg) . '" title="' . h($team) . '">' . $flag . h($team) . '</span>';
    return h($name) . ' ' . $badge;
}

/**
 * Every K9 handler in the org, keyed by user id — request-scoped static cache
 * behind a single query.
 *
 * Deliberately NOT threaded through the ~11 SELECTs that already feed
 * guestNameHtml() (participants, teams, chat, dispatch, SOS, shortage,
 * incidents, media, breaches...). Handlers are a handful of people org-wide,
 * so one small query per request is cheaper than three extra columns on every
 * one of those joins — and it keeps k9BadgeHtml() callable from any render
 * site that has a user id in scope, which all of them already do. That's what
 * makes "show it everywhere" a one-line change per call site instead of a
 * signature change rippling through every payload array.
 *
 * Wrapped in a try/catch because bootstrap.php renders names on pages that
 * load before/independently of a successful migration run: if v140 is stuck
 * in its failure cooldown, this degrades to "nobody is a handler" instead of
 * fataling every page in the app that prints a person's name.
 */
function k9Handlers(): array {
    static $handlers = null;
    if ($handlers === null) {
        $handlers = [];
        try {
            $rows = dbFetchAll(
                "SELECT id, dog_name, dog_training FROM users WHERE is_dog_handler = 1 AND deleted_at IS NULL"
            );
            foreach ($rows as $row) {
                $handlers[(int) $row['id']] = [
                    'dog_name'     => $row['dog_name'],
                    'dog_training' => $row['dog_training'],
                ];
            }
        } catch (Throwable $e) {
            $handlers = [];
        }
    }
    return $handlers;
}

/**
 * The 🐕 badge that rides next to a K9 handler's name everywhere a name is
 * rendered — volunteer/guest lists, Action Room (participants, team rosters,
 * map pins, chat, dispatch, SOS), shifts, briefings, the navbar. Returns ''
 * for everyone else, so call sites can append it unconditionally.
 *
 * Emoji rather than a Bootstrap Icon because bi has no dog glyph, and unlike
 * the flag emoji this codebase had to replace with self-hosted SVGs, 🐕
 * renders correctly on Windows/Chrome (war-room already leans on ⭐/🆘/✅).
 *
 * $compact drops the dog's name and keeps only the glyph + tooltip, for the
 * places where the name line is already carrying a team badge and a flag
 * (map marker labels, chat meta lines, team-member chips).
 *
 * $lang pins the tooltip's language instead of following the viewer's, for
 * the deliberately Greek-only archival pages (mission-report-print.php,
 * mission-stats.php) whose output must stay byte-stable no matter who
 * generates it — same reason computeMissionResponseReport() takes one.
 */
function k9BadgeHtml(?int $userId, bool $compact = false, ?string $lang = null): string {
    if (!$userId) return '';
    $handler = k9Handlers()[$userId] ?? null;
    if ($handler === null) return '';

    $dogName  = trim((string) ($handler['dog_name'] ?? ''));
    $training = trim((string) ($handler['dog_training'] ?? ''));
    $label    = $dogName !== '' ? $dogName : t('k9.dog_unnamed', [], $lang);
    $tooltip  = $training !== ''
        ? t('k9.badge_tooltip', ['dog' => $label, 'training' => $training], $lang)
        : t('k9.badge_tooltip_no_training', ['dog' => $label], $lang);

    return '<span class="k9-badge" title="' . h($tooltip) . '">🐕'
        . ($compact ? '' : ' ' . h($label)) . '</span>';
}

/**
 * Every home-team captain in the org, keyed by user id — same request-scoped
 * static-cache-behind-one-query shape as k9Handlers(), for the same reason:
 * captains are a handful of people, so one small query per request beats
 * threading an is_team_captain column through every render site's own query.
 * Includes the captain's home team name (volunteer_teams or, for a walk-up
 * Mission Visitor, mission_visitor_tags) so captainBadgeHtml()'s tooltip can
 * name it without any call site passing it in.
 */
function teamCaptains(): array {
    static $captains = null;
    if ($captains === null) {
        $captains = [];
        try {
            $rows = dbFetchAll(
                "SELECT u.id, COALESCE(vt.name, mvt.label) AS team_name
                 FROM users u
                 LEFT JOIN volunteer_teams vt ON vt.id = u.volunteer_team_id
                 LEFT JOIN mission_visitor_tags mvt ON mvt.id = u.mission_visitor_tag_id
                 WHERE u.is_team_captain = 1 AND u.deleted_at IS NULL"
            );
            foreach ($rows as $row) {
                $captains[(int) $row['id']] = [
                    'team_name' => $row['team_name'],
                ];
            }
        } catch (Throwable $e) {
            $captains = [];
        }
    }
    return $captains;
}

/**
 * The ⭐ badge that rides next to a home-team captain's name everywhere a
 * name is rendered — mirrors k9BadgeHtml() exactly (same signature, same
 * compact/lang behaviour) so it can be appended at every existing
 * k9BadgeHtml() call site with the same arguments. Returns '' for everyone
 * else, so call sites can append it unconditionally.
 *
 * Deliberately unrelated to the ⭐ already used for mission_teams.leader_id
 * (a per-mission Action Room squad leader, picked fresh for every mission —
 * see war-room.php's Ομάδες Αποστολής card). This one is a standing,
 * admin-set property of the person's home team (volunteer_teams/
 * mission_visitor_tags), set on volunteer-form.php — a person can be both,
 * neither, or just one, and both badges may legitimately appear together.
 * Styled as a filled bi-star-fill pill (not the bare ⭐ glyph the mission-team
 * leader line uses) precisely so the two are never visually interchangeable.
 */
function captainBadgeHtml(?int $userId, bool $compact = false, ?string $lang = null): string {
    if (!$userId) return '';
    $captain = teamCaptains()[$userId] ?? null;
    if ($captain === null) return '';

    $team    = trim((string) ($captain['team_name'] ?? ''));
    $tooltip = $team !== ''
        ? t('captain.badge_tooltip', ['team' => $team], $lang)
        : t('captain.badge_tooltip_no_team', [], $lang);

    return '<span class="captain-badge" title="' . h($tooltip) . '"><i class="bi bi-star-fill"></i>'
        . ($compact ? '' : ' ' . h(t('captain.label', [], $lang))) . '</span>';
}

/**
 * Standalone, larger home-team flag+label badge — bigger/bolder sibling of
 * guestNameHtml()'s inline badge, for a spot (e.g. war-room.php's Ομάδες
 * Αποστολής leader line) that wants more visual weight. Deliberately still
 * placed right next to the specific person's name it describes, not floated
 * to a row/card corner: a mission_teams squad can mix people from different
 * home teams (Επίδρασις + a partner org), so a corner tag reads as "this
 * whole team belongs to X" — wrong when the team is mixed. Same flag/fallback
 * rules as guestNameHtml(), intentionally duplicated rather than shared so
 * guestNameHtml()'s many other call sites (chat, dispatch, SOS, profile) are
 * untouched. Pairs with the .team-name-badge-corner CSS in header.php.
 */
function homeTeamCornerBadgeHtml(?string $teamName, ?string $teamColor, bool $isExternal, ?string $countryCode): string {
    if ($teamColor === null) return '';
    [$bg, $fg] = teamBadgeColors($teamColor);
    $flag = flagHtml($isExternal ? $countryCode : 'GR');
    $team = ($teamName !== null && trim($teamName) !== '') ? $teamName : t('guest.org_unknown');
    return '<span class="team-name-badge-corner" style="background:' . h($bg) . ';color:' . h($fg) . '" title="' . h($team) . '">' . $flag . h($team) . '</span>';
}

/**
 * War Room: every mission team with its leader and full member roster, keyed
 * by team id (not a plain 0-indexed array — war-room.php's lazy
 * briefing-token backfill on the full-page render still needs the id as the
 * array key). Shared by that full-page render and the live ajax=1 poll, so a
 * team's membership or leader changing reaches every other open War Room tab
 * within one poll cycle instead of needing a manual reload.
 */
function loadMissionTeamsForMission(int $missionId): array {
    $teamRows = dbFetchAll(
        "SELECT mt.id, mt.codename, mt.team_number, mt.color, mt.briefing_token, mt.leader_id, l.name AS leader_name,
                l.is_external AS leader_is_external, l.guest_org_name AS leader_guest_org_name, l.guest_country_code AS leader_guest_country_code,
                COALESCE(lht.name, lmvt.label) AS leader_home_team_name, COALESCE(lht.color, lmvt.color) AS leader_home_team_color,
                mtm.user_id, u.name AS member_name, u.is_external AS member_is_external, u.guest_org_name AS member_guest_org_name, u.guest_country_code AS member_guest_country_code,
                COALESCE(mht.name, mmvt.label) AS member_home_team_name, COALESCE(mht.color, mmvt.color) AS member_home_team_color
         FROM mission_teams mt
         LEFT JOIN users l ON l.id = mt.leader_id
         LEFT JOIN volunteer_teams lht ON lht.id = l.volunteer_team_id
         LEFT JOIN mission_visitor_tags lmvt ON lmvt.id = l.mission_visitor_tag_id
         LEFT JOIN mission_team_members mtm ON mtm.team_id = mt.id
         LEFT JOIN users u ON u.id = mtm.user_id
         LEFT JOIN volunteer_teams mht ON mht.id = u.volunteer_team_id
         LEFT JOIN mission_visitor_tags mmvt ON mmvt.id = u.mission_visitor_tag_id
         WHERE mt.mission_id = ?
         ORDER BY mt.created_at, u.name",
        [$missionId]
    );
    $teams = [];
    foreach ($teamRows as $row) {
        $tid = (int) $row['id'];
        if (!isset($teams[$tid])) {
            $teams[$tid] = [
                'id' => $tid,
                'codename' => $row['codename'],
                'team_number' => $row['team_number'],
                'color' => $row['color'],
                'briefing_token' => $row['briefing_token'],
                'leader_id' => $row['leader_id'] !== null ? (int) $row['leader_id'] : null,
                'leader_name' => $row['leader_name'],
                'leader_is_external' => (bool) $row['leader_is_external'],
                'leader_guest_org_name' => $row['leader_guest_org_name'],
                'leader_guest_country_code' => $row['leader_guest_country_code'],
                'leader_home_team_name' => $row['leader_home_team_name'],
                'leader_home_team_color' => $row['leader_home_team_color'],
                'members' => [],
            ];
        }
        if ($row['user_id'] !== null) {
            $teams[$tid]['members'][] = [
                'user_id' => (int) $row['user_id'], 'name' => $row['member_name'],
                'is_external' => (bool) $row['member_is_external'], 'guest_org_name' => $row['member_guest_org_name'],
                'guest_country_code' => $row['member_guest_country_code'],
                'home_team_name' => $row['member_home_team_name'], 'home_team_color' => $row['member_home_team_color'],
            ];
        }
    }
    return $teams;
}

/**
 * War Room: which OTHER team of this mission each volunteer currently belongs
 * to, keyed by user id. Exists so a volunteer can be moved between teams in a
 * single save: both team forms need to show "this person is on ΒΡΑΒΟ 2" next
 * to the checkbox, and both handlers need to know who to unassign.
 *
 * Before this, neither form would even list someone already on another team,
 * so a move was two separate saves — remove from A, then add to B — and
 * between them the volunteer had no team room at all (see syncRoomTabs() in
 * war-room.php, which correctly takes their team chat away the moment the
 * first save lands).
 */
function loadOtherTeamAssignments(int $missionId, int $excludeTeamId = 0): array {
    $rows = dbFetchAll(
        "SELECT mtm.user_id, mt.id AS team_id, mt.codename, mt.team_number, mt.color, mt.leader_id,
                (SELECT COUNT(*) FROM mission_team_members x WHERE x.team_id = mt.id) AS member_count
         FROM mission_team_members mtm
         JOIN mission_teams mt ON mt.id = mtm.team_id
         WHERE mtm.mission_id = ? AND mt.id != ?",
        [$missionId, $excludeTeamId]
    );
    $byUserId = [];
    foreach ($rows as $row) {
        $byUserId[(int) $row['user_id']] = [
            'team_id'      => (int) $row['team_id'],
            'label'        => teamLabel($row['codename'], $row['team_number']),
            'color'        => $row['color'],
            'leader_id'    => $row['leader_id'] !== null ? (int) $row['leader_id'] : null,
            'member_count' => (int) $row['member_count'],
        ];
    }
    return $byUserId;
}

/**
 * War Room: of the volunteers about to be pulled onto another team, which ones
 * cannot be taken without breaking the team they would leave — returns those
 * teams' labels (deduplicated), empty when the move is safe.
 *
 * Blocked in two cases, which are really one: the app's own invariant is that
 * a team's leader is always one of its members (both create_team and
 * update_team enforce it), so taking a leader would leave a team pointing at
 * someone who is no longer on it. A one-member team is the same case — that
 * member is necessarily its leader — and taking them would also silently
 * leave an empty team behind.
 *
 * Deliberately a refusal rather than an auto-promotion: who leads a team mid
 * operation is a command decision, and quietly picking the next name on the
 * roster is exactly the kind of guess that gets found out at the worst moment.
 */
function teamsBlockedFromMemberMove(array $userIds, array $otherAssignments): array {
    $labels = [];
    foreach ($userIds as $userId) {
        $current = $otherAssignments[(int) $userId] ?? null;
        if (!$current) continue;
        if ($current['leader_id'] === (int) $userId || $current['member_count'] <= 1) {
            $labels[$current['label']] = $current['label'];
        }
    }
    return array_values($labels);
}

/**
 * War Room: command-staff recipient set for admin-facing alerts (shortage
 * reports, and reusable for similar future cases) — system/dept admins +
 * this mission's shift leaders + the mission's responsible_user_id, MINUS
 * anyone in that set who could not actually open the mission's command view
 * (see filterActionRoomManagers() below for why the two differ). Mirrors
 * the resolution already duplicated in mission-dispatch.php/mission-photo.php/
 * mission-chat.php/volunteer-status.php; centralized here for new code only,
 * not retrofitted into those four.
 */
function getMissionCommandStaffIds(int $missionId, ?int $responsibleUserId, int $excludeUserId = 0): array {
    $admins = dbFetchAll("SELECT id FROM users WHERE role IN (?, ?) AND is_active = 1", [ROLE_SYSTEM_ADMIN, ROLE_DEPARTMENT_ADMIN]);
    $leaders = dbFetchAll(
        "SELECT DISTINCT u.id FROM users u
         JOIN participation_requests pr ON pr.volunteer_id = u.id
         JOIN shifts s ON pr.shift_id = s.id
         WHERE s.mission_id = ? AND u.role = ? AND u.is_active = 1 AND pr.status = ?",
        [$missionId, ROLE_SHIFT_LEADER, PARTICIPATION_APPROVED]
    );
    $ids = array_merge(array_map('intval', array_column($admins, 'id')), array_map('intval', array_column($leaders, 'id')));
    if ($responsibleUserId) {
        $ids[] = (int) $responsibleUserId;
    }
    $ids = array_values(array_unique(array_diff($ids, [$excludeUserId])));
    return filterActionRoomManagers($ids, $responsibleUserId);
}

/**
 * Narrows a set of user ids to those who could actually open this mission's
 * command view — the per-user, set-based twin of canManageActionRoom(), which
 * can only ever answer for the logged-in session.
 *
 * Exists because getMissionCommandStaffIds() above selects by ACCOUNT ROLE
 * (SYSTEM_ADMIN / DEPARTMENT_ADMIN / SHIFT_LEADER) while every visibility gate
 * in the Action Room asks canManageActionRoom(), which is a PERMISSION check
 * (missions_manage) plus the mission's own responsible user. Those two sets
 * are not the same, and where they diverged the app notified people about
 * things it then refused to show them: an "Αρχηγός Βάρδιας" whose role has no
 * missions_manage was sent every team's chat, dispatch acks, photo uploads,
 * sector events, needs_help escalations, shortage reports and SOS alerts, and
 * could open none of them — tapping through landed on a page with no such
 * panel, or bounced to the dashboard outright. Reported as "I get messages
 * from a team channel I cannot find".
 *
 * Nobody gains access here; people simply stop being sent alerts that were
 * dead on arrival. If a deployment WANTS its shift leaders to be command
 * staff, the supported way is to grant their role missions_manage — which
 * gives them the screens to act on the alert as well as the alert.
 */
function filterActionRoomManagers(array $userIds, ?int $responsibleUserId): array {
    $userIds = array_values(array_unique(array_map('intval', $userIds)));
    if (empty($userIds)) return [];

    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    try {
        $rows = dbFetchAll(
            "SELECT u.id FROM users u
             WHERE u.id IN ($placeholders)
               AND (u.is_external IS NULL OR u.is_external = 0)
               AND (u.role = ?
                    OR EXISTS (SELECT 1 FROM custom_role_permissions p
                               WHERE p.role_id = u.custom_role_id AND p.page_slug = 'missions_manage'))",
            array_merge($userIds, [ROLE_SYSTEM_ADMIN])
        );
    } catch (Throwable $e) {
        // Same defensive stance hasPagePermission() takes around this table.
        // Fails OPEN on purpose: these are SOS/shortage/needs_help paths, and
        // an alert that reaches someone who cannot act on it is a far smaller
        // failure than an alert that reaches nobody at all.
        error_log('filterActionRoomManagers() permission lookup failed: ' . $e->getMessage());
        return $userIds;
    }

    $allowed = array_map('intval', array_column($rows, 'id'));
    // Second half of canManageActionRoom(): the mission's responsible user
    // manages its Action Room whatever their sitewide permissions say.
    if ($responsibleUserId && in_array((int) $responsibleUserId, $userIds, true)) {
        $allowed[] = (int) $responsibleUserId;
    }
    return array_values(array_unique($allowed));
}

/**
 * Notify command staff (system/department admins, this mission's shift leaders,
 * and its responsible user) about a notable event, with the scrolling-banner
 * treatment (bannerMission => the sound/visual alert on war-room.php). Mirrors
 * the recipient resolution mission-dispatch.php/mission-photo.php already use.
 * Originally page-local to mission-route.php (depart/arrive/complete/skip/
 * cancel all call it) under the name notifyRouteCommandStaff(); renamed once
 * mission-order.php's acknowledge action needed the exact same shape for
 * *two more* order types (task, global message) beyond the route case it was
 * first built for — kept the generic tag/'route-' prefix would have been
 * actively misleading on a non-route notification.
 */
function notifyCommandStaffBanner(int $missionId, string $missionTitle, ?int $responsibleUserId, int $actorId, string $code, string $titleKey, array $titleVars, string $messageKey, array $messageVars): void {
    $warRoomUrl = rtrim(BASE_URL, '/') . '/war-room.php?id=' . $missionId;
    $recipientIds = getMissionCommandStaffIds($missionId, $responsibleUserId, $actorId);
    $langByUserId = getUserLanguages($recipientIds);
    foreach ($recipientIds as $recipientId) {
        $lang = $langByUserId[$recipientId] ?? DEFAULT_LANGUAGE;
        sendNotification($recipientId, t($titleKey, $titleVars, $lang), t($messageKey, $messageVars, $lang), 'info', $code, [
            'url' => $warRoomUrl,
            'tag' => $code . '-mission-' . $missionId,
            'bannerMission' => $missionId,
        ]);
    }
}

/**
 * Notify command staff that a volunteer sent their GPS location — mirrors
 * mission-dispatch.php's notifyDispatchReceive()/notifyDispatchArrival()
 * shape (own notification code, bannerMission for the loud scrolling
 * banner + sound, getMissionCommandStaffIds() for recipients). Fires on
 * every ping regardless of whether it was requested via a War Room order —
 * request fulfillment is already tracked separately
 * (mission_order_recipients.fulfilled_at) for the response-time report;
 * this is the live "someone just sent their location" alert. Moved here
 * from ping-location.php (originally page-local) so mobile-ping-location.php
 * — the bearer-token-authed twin used by the native Android app — can call
 * it too via recordVolunteerPing() below.
 */
function notifyVolunteerGpsPing(int $missionId, string $missionTitle, ?int $responsibleUserId, string $senderName, int $senderId): void {
    $warRoomUrl = rtrim(BASE_URL, '/') . '/war-room.php?id=' . $missionId;
    $recipientIds = getMissionCommandStaffIds($missionId, $responsibleUserId, $senderId);
    $langByUserId = getUserLanguages($recipientIds);
    foreach ($recipientIds as $recipientId) {
        $lang = $langByUserId[$recipientId] ?? DEFAULT_LANGUAGE;
        sendNotification(
            $recipientId,
            t('ping.notify_title', [], $lang),
            t('ping.notify_message', ['name' => $senderName, 'mission' => $missionTitle], $lang),
            'info', 'mission_gps_ping', [
                'url' => $warRoomUrl,
                'tag' => 'gps-ping-mission-' . $missionId,
                'bannerMission' => $missionId,
            ]
        );
    }
}

/**
 * Ping staleness threshold in seconds — 3x the configured auto-ping cadence
 * (war_room_auto_ping_seconds, default 180s), generous enough to not cry
 * wolf over one missed tick's jitter (the original rationale for 3x) while
 * still scaling down when an admin configures a faster cadence than the
 * default. Single source of truth for every "is this GPS ping still fresh"
 * check in the app — was previously 4 independent hardcoded `540` (9min)
 * literals across war-room.php and this file, each explicitly commented
 * "kept manually in sync, update this too if it ever changes." Real bug
 * found live: an admin lowered the cadence to 30s for tighter tracking, but
 * every staleness check kept using the stale 540s/9min default anyway — a
 * volunteer's presence dot and map pin could read "online"/fresh for up to
 * 9 minutes of real silence, the opposite of what configuring a faster
 * cadence was for.
 */
function warRoomPingStaleThresholdSeconds(): int {
    return 3 * (int) getSetting('war_room_auto_ping_seconds', '180');
}

/**
 * War Room fatigue flag: minutes each currently-on-duty volunteer has been
 * continuously in the field on this mission — a CHAIN of back-to-back
 * APPROVED shifts (gap <= $toleranceMinutes between one shift's end_time and
 * the next's start_time doesn't break the chain; a real gap does).
 *
 * "Currently on duty" gives a shift's end_time the SAME gap tolerance as any
 * other link in the chain — a shift that ended a few minutes ago with no
 * follow-on shift approved yet still counts as on-duty for up to
 * $toleranceMinutes past its end_time. Without this, the flag would vanish
 * the instant a shift's end_time passes if nobody has approved the next
 * shift yet, which is exactly the scenario this feature exists to catch
 * (nobody replaced them). Past that grace window with still no new shift,
 * the volunteer drops out of this array entirely.
 *
 * Returns [volunteerId => continuousMinutes] — ONLY for volunteers currently
 * on duty per the rule above. Doubles as the "who's currently deployed on
 * this mission" set for api-suggest-replacement.php's exclusion filter.
 */
function computeContinuousFieldMinutesByVolunteerId(int $missionId, int $toleranceMinutes = 30): array {
    $rows = dbFetchAll(
        "SELECT pr.volunteer_id, s.start_time, s.end_time
         FROM participation_requests pr
         JOIN shifts s ON s.id = pr.shift_id
         WHERE s.mission_id = ? AND pr.status = ?
         ORDER BY pr.volunteer_id, s.start_time",
        [$missionId, PARTICIPATION_APPROVED]
    );

    $shiftsByVolunteer = [];
    foreach ($rows as $row) {
        $shiftsByVolunteer[(int) $row['volunteer_id']][] = [
            'start' => strtotime($row['start_time']),
            'end'   => strtotime($row['end_time']),
        ];
    }

    $toleranceSeconds = $toleranceMinutes * 60;
    $now = time();
    $result = [];

    foreach ($shiftsByVolunteer as $volunteerId => $shifts) {
        // Already ORDER BY s.start_time from the query — cheap
        // belt-and-braces re-sort (each volunteer has at most a handful of
        // rows on one mission), not trusted-away in case grouping order
        // ever changes.
        usort($shifts, fn($a, $b) => $a['start'] <=> $b['start']);

        // Last shift that has already started. Shifts are sorted ascending,
        // so the last one found is the most recent start <= now.
        $chainEndIndex = null;
        foreach ($shifts as $i => $shift) {
            if ($shift['start'] <= $now) {
                $chainEndIndex = $i;
            } else {
                break;
            }
        }
        if ($chainEndIndex === null) {
            continue; // no shift has started yet
        }
        $chainEnd = $shifts[$chainEndIndex];
        if ($now - $chainEnd['end'] > $toleranceSeconds) {
            continue; // last-started shift ended too long ago — not on duty
        }

        // Walk backward, extending the chain while each earlier shift's
        // end_time is within tolerance of the next one's start_time.
        $chainStart = $chainEnd;
        for ($i = $chainEndIndex; $i > 0; $i--) {
            $current = $shifts[$i];
            $prev = $shifts[$i - 1];
            if ($current['start'] - $prev['end'] > $toleranceSeconds) {
                break; // real gap — chain stops here
            }
            $chainStart = $prev;
        }

        $result[$volunteerId] = (int) floor(($now - $chainStart['start']) / 60);
    }

    return $result;
}

/**
 * Core GPS-ping write path (ownership check + volunteer_pings insert +
 * command-staff notify + order auto-fulfillment) shared by ping-location.php
 * (session + CSRF auth, called from the live war-room.php tab) and
 * mobile-ping-location.php (bearer-token auth, called by the native Android
 * app's background-location plugin — which runs detached from any WebView
 * and so can never hold a live session or CSRF token). Keeping this in one
 * place means the two auth paths can't silently drift apart, the way this
 * codebase's separate report/live-tab event aggregators once did.
 * $user must be a full users row (id, name, language) — callers resolve it
 * their own way (session vs. token lookup) before calling this.
 */
function recordVolunteerPing(array $user, int $shiftId, float $lat, float $lng, ?float $accuracy, ?int $batteryLevel, string $source): array {
    $userId = (int) $user['id'];
    $lang = $user['language'] ?? DEFAULT_LANGUAGE;

    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || ($lat == 0 && $lng == 0)) {
        return ['ok' => false, 'error' => t('ping.invalid_coordinates', [], $lang)];
    }

    // Verify user has an APPROVED participation for this shift
    $pr = dbFetchOne(
        "SELECT pr.id, s.mission_id, m.title AS mission_title, m.responsible_user_id FROM participation_requests pr
         JOIN shifts s ON pr.shift_id = s.id
         JOIN missions m ON s.mission_id = m.id
         WHERE pr.shift_id = ? AND pr.volunteer_id = ? AND pr.status = ?
           AND m.status = ? AND m.show_in_ops = 1 AND m.deleted_at IS NULL",
        [$shiftId, $userId, PARTICIPATION_APPROVED, STATUS_OPEN]
    );

    if (!$pr) {
        return ['ok' => false, 'error' => t('ping.mission_not_open_or_not_approved', [], $lang)];
    }

    try {
        dbInsert(
            "INSERT INTO volunteer_pings (user_id, shift_id, lat, lng, accuracy_meters, battery_level, source, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())",
            [$userId, $shiftId, $lat, $lng, $accuracy, $batteryLevel, $source]
        );
    } catch (Exception $e) {
        return ['ok' => false, 'error' => t('ping.gps_unavailable_migration', [], $lang)];
    }

    // Geofence check against any admin-drawn restricted areas — best-effort,
    // same "non-critical" treatment as the order-auto-fulfill block below: a
    // hiccup here (malformed geo on some row, a transient lock timeout) must
    // never fail the ping itself, which already succeeded above.
    try {
        checkRestrictedAreaBreach(
            (int) $pr['mission_id'], $userId, getUserTeamIdForMission((int) $pr['mission_id'], $userId),
            (int) $pr['id'], $lat, $lng, $source, $accuracy
        );
    } catch (Exception $e) {
        // Non-critical — the ping itself already succeeded.
    }

    // Auto-captured pings (passive, every few minutes while Action Room is open,
    // or from the native app's background plugin) stay quiet — only a manual
    // tap should trigger the loud command-staff alert.
    if ($source !== 'auto') {
        notifyVolunteerGpsPing(
            (int) $pr['mission_id'],
            $pr['mission_title'],
            $pr['responsible_user_id'] ? (int) $pr['responsible_user_id'] : null,
            $user['name'],
            $userId
        );
    }

    // Auto-fulfill any outstanding War Room "send your location" orders for this user.
    try {
        dbExecute(
            "UPDATE mission_order_recipients r
             JOIN mission_orders o ON o.id = r.order_id
             SET r.fulfilled_at = NOW()
             WHERE r.user_id = ? AND o.mission_id = ? AND o.order_type = 'location' AND r.fulfilled_at IS NULL",
            [$userId, $pr['mission_id']]
        );
    } catch (Exception $e) {
        // Non-critical — the ping itself already succeeded.
    }

    return ['ok' => true, 'ts' => date('H:i:s')];
}

/**
 * Geofence check for admin-drawn restricted (hazard/danger) areas, called
 * from recordVolunteerPing() on every ping from every source (web manual,
 * web auto, native background). For each active restricted area on this
 * mission: newly inside with no open breach -> open one; newly outside an
 * open breach -> close it, but only on a ping trustworthy enough to believe
 * (see below). Entry is deliberately eager (a false-positive alarm is
 * cheap) while exit is deliberately conservative (a false-negative means
 * someone stays in a danger zone with a silenced alarm) — asymmetric on
 * purpose, not a missed case.
 *
 * Race-safety mirrors volunteer-status.php's SOS-alert creation exactly:
 * lock the participation_requests row (guaranteed to already exist) before
 * the check-then-act on the breaches table, since there's nothing to lock
 * when the breach row doesn't exist yet. Without this, two near-simultaneous
 * pings for the same user (e.g. a browser tab's auto-ping racing the native
 * app's own background ping) could both observe "no open breach" and both
 * insert.
 */
function checkRestrictedAreaBreach(int $missionId, int $userId, ?int $teamId, int $prId, float $lat, float $lng, string $source, ?float $accuracy): void {
    $newBreaches = [];

    db()->beginTransaction();
    try {
        dbFetchOne("SELECT id FROM participation_requests WHERE id = ? FOR UPDATE", [$prId]);

        $areas = dbFetchAll("SELECT id, label, geo FROM mission_restricted_areas WHERE mission_id = ?", [$missionId]);
        foreach ($areas as $area) {
            $geo = json_decode((string) $area['geo'], true);
            if (!is_array($geo) || count($geo) < 3) {
                continue;
            }
            $inside = pointInPolygon($lat, $lng, $geo);
            $openId = dbFetchValue(
                "SELECT id FROM mission_restricted_area_breaches
                 WHERE restricted_area_id = ? AND user_id = ? AND exited_at IS NULL AND resolved_at IS NULL",
                [$area['id'], $userId]
            );

            if ($inside && !$openId) {
                $breachId = dbInsert(
                    "INSERT INTO mission_restricted_area_breaches (mission_id, restricted_area_id, area_label, user_id, team_id, lat, lng, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, NOW())",
                    [$missionId, $area['id'], $area['label'], $userId, $teamId, $lat, $lng]
                );
                $newBreaches[] = ['id' => (int) $breachId, 'area_label' => $area['label']];
            } elseif (!$inside && $openId) {
                // Auto-pings default to enableHighAccuracy:false (war-room.php's
                // sendAutoPing/watchPosition setup) and are already excluded from
                // other alerting for the same low-trust reason (the source!=='auto'
                // check just above in recordVolunteerPing) — a single noisy,
                // low-accuracy auto-ping landing just outside the boundary must not
                // be enough on its own to silence the siren.
                $trustworthy = $source !== 'auto' || ($accuracy !== null && $accuracy <= 100);
                if ($trustworthy) {
                    dbExecute("UPDATE mission_restricted_area_breaches SET exited_at = NOW() WHERE id = ?", [$openId]);
                }
            }
        }
        db()->commit();
    } catch (Exception $e) {
        db()->rollBack();
        throw $e;
    }

    // Notify after commit, never inside the transaction — matches every other
    // notify-after-write call in this codebase.
    foreach ($newBreaches as $breach) {
        notifyRestrictedAreaBreach($missionId, $userId, $teamId, $breach['area_label']);
    }
}

/**
 * Alerts BOTH sides of a restricted-area breach — deliberately two separate
 * sendNotification() calls, not one. getMissionCommandStaffIds() excludes
 * whoever is passed as its own $excludeUserId (that's how notifyVolunteerGpsPing
 * avoids notifying someone about their own ping), so it only ever reaches the
 * *other* party; the breaching volunteer themselves needs their own explicit
 * call, which no existing helper does today.
 */
function notifyRestrictedAreaBreach(int $missionId, int $userId, ?int $teamId, string $areaLabel): void {
    $mission = dbFetchOne("SELECT title, responsible_user_id FROM missions WHERE id = ?", [$missionId]);
    if (!$mission) {
        return;
    }
    $user = dbFetchOne("SELECT name FROM users WHERE id = ?", [$userId]);
    $team = $teamId ? dbFetchOne("SELECT codename, team_number FROM mission_teams WHERE id = ?", [$teamId]) : null;
    $teamLabelStr = $team ? teamLabel($team['codename'], $team['team_number']) : t('history.no_team_capitalized');

    $warRoomUrl = rtrim(BASE_URL, '/') . '/war-room.php?id=' . $missionId;
    $pushData = [
        'url' => $warRoomUrl,
        'tag' => 'restricted-area-mission-' . $missionId,
        'bannerMission' => $missionId,
    ];

    // The volunteer themselves.
    sendNotification(
        $userId,
        t('restricted_area.notify_title_self'),
        t('restricted_area.notify_message_self', ['area' => $areaLabel]),
        'danger', 'mission_restricted_area_breach', $pushData
    );

    // Command staff (excludes the volunteer above — same helper every other
    // admin-facing War Room alert uses).
    $responsibleUserId = $mission['responsible_user_id'] ? (int) $mission['responsible_user_id'] : null;
    $recipientIds = getMissionCommandStaffIds($missionId, $responsibleUserId, $userId);
    $langByUserId = getUserLanguages($recipientIds);
    foreach ($recipientIds as $recipientId) {
        $lang = $langByUserId[$recipientId] ?? DEFAULT_LANGUAGE;
        sendNotification(
            $recipientId,
            t('restricted_area.notify_title_admin', ['mission' => $mission['title']], $lang),
            t('restricted_area.notify_message_admin', ['team' => $teamLabelStr, 'name' => $user['name'] ?? '', 'area' => $areaLabel], $lang),
            'danger', 'mission_restricted_area_breach', $pushData
        );
    }
}

/**
 * War Room: every active System Administrator, minus $excludeUserId. This is
 * the "sees/hears absolutely everything happening in every Action Room" tier
 * — deliberately narrower than getMissionCommandStaffIds() (department admins
 * + this mission's shift leaders, used for routine "FYI" pings elsewhere) and
 * deliberately mission-independent: a system admin is looped in on a
 * mission's admin-to-user alerts even without being that mission's own
 * responsible_user_id or an approved participant on it, since they may just
 * be watching the Action Room without being on its roster.
 */
function getSystemAdminIds(int $excludeUserId = 0): array {
    $rows = dbFetchAll("SELECT id FROM users WHERE role = ? AND is_active = 1", [ROLE_SYSTEM_ADMIN]);
    return array_values(array_diff(array_map('intval', array_column($rows, 'id')), [$excludeUserId]));
}

/**
 * War Room: capped, human-readable name list for an admin FYI message
 * ("Maria, Giorgos, Nikos +3 ακόμη") — who a targeted order/dispatch actually
 * went to, shown to a system admin who wasn't one of them.
 */
function formatAdminFyiRecipientList(array $names, string $lang): string {
    $names = array_values(array_filter($names, fn($n) => $n !== null && $n !== ''));
    if (empty($names)) return '';
    if (count($names) <= 3) return implode(', ', $names);
    return implode(', ', array_slice($names, 0, 3)) . ' ' . t('common.and_n_more', ['n' => count($names) - 3], $lang);
}

/**
 * War Room: persist a trackable order (mission_orders + one mission_order_recipients
 * row per recipient, snapshotting each recipient's team) then notify them, threading
 * orderId into the pushData so the alert banner can offer an "Ελήφθη" button. Shared
 * by request_location/request_photo/request_video/task (war-room.php), the Route
 * Order composer (mission-route.php), and the battery-charge alert
 * (mission-battery-alert.php) — the only difference between them is order_type +
 * notification copy. Lives here (not war-room.php, where it was first written)
 * specifically so a standalone endpoint like mission-route.php — which only
 * requires bootstrap.php, never war-room.php itself — can call it too.
 *
 * $recipientIds get the real banner + alert sound + orderId (the "Ελήφθη"
 * button) — same "a targeted order shouldn't sound an alarm for people it
 * wasn't sent to" rule already applied to team-targeted dispatch
 * (mission-dispatch.php's create action, v3.153.18). An earlier version also
 * broadcast a quieter FYI banner to every other *approved participant*;
 * removed per explicit request (v3.165.1), no replacement — that part stays
 * gone. But v3.165.1 went one step too far and also silenced every *other
 * System Administrator* watching a different browser tab/session — every
 * such admin now still gets the identical banner+sound alert too, worded as
 * a third-person FYI naming the real recipient(s) instead of the 2nd-person
 * "you" text meant for them, and with no orderId (a bystander admin has no
 * mission_order_recipients row, so no "Ελήφθη" button — mission-order.php's
 * acknowledge action would just reject it as "not your request" anyway).
 */
function createMissionOrderAndNotify(
    int $missionId, string $missionTitle, string $orderType, int $createdBy, array $recipientIds,
    string $titleKey, array $titleVars, ?string $rawMessage, string $messageKey, array $messageVars,
    ?string $taskText = null, ?string $alarmStyle = null
): int {
    $orderId = dbInsert(
        "INSERT INTO mission_orders (mission_id, order_type, task_text, created_by, created_at) VALUES (?, ?, ?, ?, NOW())",
        [$missionId, $orderType, $taskText, $createdBy]
    );

    $warRoomUrl = rtrim(BASE_URL, '/') . '/war-room.php?id=' . $missionId;
    $recipientLangs = getUserLanguages($recipientIds);
    foreach ($recipientIds as $recipientId) {
        $lang = $recipientLangs[$recipientId] ?? DEFAULT_LANGUAGE;
        $teamId = getUserTeamIdForMission($missionId, $recipientId);
        dbInsert(
            "INSERT INTO mission_order_recipients (order_id, user_id, team_id) VALUES (?, ?, ?)",
            [$orderId, $recipientId, $teamId]
        );
        // Free-form task/broadcast text ($rawMessage) is never translated — it's
        // exactly what the admin typed, per the "free text stays as typed" rule.
        $message = $rawMessage ?? t($messageKey, $messageVars, $lang);
        $pushData = [
            'url' => $warRoomUrl,
            'tag' => $orderType . '-request-mission-' . $missionId,
            'vibrate' => [300, 100, 300, 100, 500],
            'bannerMission' => $missionId,
            'orderId' => (int) $orderId,
        ];
        if ($alarmStyle) {
            $pushData['alarmStyle'] = $alarmStyle;
        }
        sendNotification($recipientId, t($titleKey, $titleVars, $lang), $message, 'warning', '', $pushData);
    }

    static $adminFyiKeys = [
        'location'       => 'order.location.admin_fyi',
        'photo'          => 'order.photo.admin_fyi',
        'video'          => 'order.video.admin_fyi',
        'task'           => 'order.task.admin_fyi',
        'message'        => 'global_message.admin_fyi',
        'return_to_base' => 'end_mission_broadcast.admin_fyi',
        'route'          => 'order.route.admin_fyi',
        'charge_phone'   => 'order.charge_phone.admin_fyi',
        'live'           => 'order.live.admin_fyi',
        'speak'          => 'order.speak.admin_fyi',
    ];
    $fyiKey = $adminFyiKeys[$orderType] ?? null;
    $adminBystanderIds = $fyiKey ? array_values(array_diff(getSystemAdminIds($createdBy), $recipientIds)) : [];
    // An empty $recipientIds is a real case, not a defensive hypothetical: the
    // Action Room's global broadcast sends to the mission's approved volunteers
    // minus the sender, so it comes out empty on a mission with no approved
    // volunteers yet, or where the sender is the only one. That crashed the
    // whole request — the name lookup below built "id IN ()", which is a SQL
    // syntax error — and it crashed precisely BECAUSE the list was empty, since
    // array_diff() then returns every admin and lands us in here.
    //
    // Skipping is also the right answer on its own terms: this FYI exists to
    // tell the other admins who was just messaged, and there is no one to name.
    // The order itself is still created and still audited; only this courtesy
    // notification is dropped.
    if ($adminBystanderIds && $recipientIds) {
        $actorName = (string) dbFetchValue("SELECT name FROM users WHERE id = ?", [$createdBy]);
        $recipientNamePlaceholders = implode(',', array_fill(0, count($recipientIds), '?'));
        $recipientNames = array_column(
            dbFetchAll("SELECT name FROM users WHERE id IN ($recipientNamePlaceholders)", $recipientIds),
            'name'
        );
        $fyiLangs = getUserLanguages($adminBystanderIds);
        foreach ($adminBystanderIds as $adminId) {
            $lang = $fyiLangs[$adminId] ?? DEFAULT_LANGUAGE;
            $fyiMessage = t($fyiKey, [
                'actor'      => $actorName,
                'recipients' => formatAdminFyiRecipientList($recipientNames, $lang),
                'mission'    => $missionTitle,
                'text'       => $rawMessage ?? '',
            ], $lang);
            $fyiPushData = [
                'url' => $warRoomUrl,
                'tag' => $orderType . '-request-mission-' . $missionId,
                'bannerMission' => $missionId,
            ];
            if ($alarmStyle) {
                $fyiPushData['alarmStyle'] = $alarmStyle;
            }
            sendNotification($adminId, t($titleKey, $titleVars, $lang), $fyiMessage, 'info', '', $fyiPushData);
        }
    }

    return (int) $orderId;
}

/**
 * Guest Mission Debrief: one-time invite to every guest approved-participant
 * of $missionId who doesn't already have a mission_guest_debriefs row for it.
 * Call this from every place a mission's status first enters {CLOSED,
 * COMPLETED} (see call sites in mission-view.php/war-room.php/
 * ops-dashboard.php/dashboard.php) — the NOT EXISTS guard means calling it
 * again for an already-notified/already-submitted guest is always a no-op,
 * so callers don't need to track "have I already invited this guest" state
 * themselves (this also absorbs the case where an admin manually reopens a
 * CLOSED mission back to OPEN via mission-form.php and re-closes it later).
 */
function notifyGuestsMissionDebriefEligible(int $missionId): void {
    $mission = dbFetchOne("SELECT title FROM missions WHERE id = ?", [$missionId]);
    if (!$mission) return;

    $guestIds = array_column(dbFetchAll(
        "SELECT DISTINCT u.id
         FROM participation_requests pr
         JOIN shifts s ON s.id = pr.shift_id
         JOIN users u ON u.id = pr.volunteer_id
         WHERE s.mission_id = ? AND pr.status = ? AND u.is_external = 1
           AND NOT EXISTS (
               SELECT 1 FROM mission_guest_debriefs mgd
               WHERE mgd.mission_id = ? AND mgd.user_id = u.id
           )",
        [$missionId, PARTICIPATION_APPROVED, $missionId]
    ), 'id');
    if (empty($guestIds)) return;

    $url = rtrim(BASE_URL, '/') . '/mission-guest-debrief.php?mission_id=' . $missionId;
    $langByUserId = getUserLanguages($guestIds);
    foreach ($guestIds as $guestId) {
        $lang = $langByUserId[$guestId] ?? DEFAULT_LANGUAGE;
        sendNotification(
            (int) $guestId,
            t('notif.guest_debrief_invite_title', [], $lang),
            t('notif.guest_debrief_invite_message', ['mission' => $mission['title']], $lang),
            'info',
            'mission_guest_debrief_invite',
            ['url' => $url]
        );
    }
}

/**
 * Guest Mission Debrief: quiet FYI to command staff when a guest submits
 * their own feedback. Mirrors notifyPhotoReceived()'s (mission-photo.php)
 * recipient-resolution shape.
 */
function notifyCommandStaffGuestDebriefSubmitted(int $missionId, ?int $responsibleUserId, int $guestId, string $guestName, string $missionTitle): void {
    $recipientIds = getMissionCommandStaffIds($missionId, $responsibleUserId, $guestId);
    if (empty($recipientIds)) return;

    $url = rtrim(BASE_URL, '/') . '/mission-debrief.php?id=' . $missionId;
    $langByUserId = getUserLanguages($recipientIds);
    foreach ($recipientIds as $recipientId) {
        $lang = $langByUserId[$recipientId] ?? DEFAULT_LANGUAGE;
        sendNotification(
            $recipientId,
            t('notif.guest_debrief_submitted_title', [], $lang),
            t('notif.guest_debrief_submitted_message', ['name' => $guestName, 'mission' => $missionTitle], $lang),
            'info',
            'mission_guest_debrief_submitted',
            ['url' => $url]
        );
    }
}

/**
 * War Room: unresolved shortage reports for the admin "Αναφορές Έλλειψης" card.
 * Caller MUST gate this behind $canManageWarRoom before calling — titles,
 * descriptions and reporter identity are sensitive, this function has no
 * built-in permission check of its own.
 */
function loadUnresolvedShortageReportsForMission(int $missionId): array {
    $rows = dbFetchAll(
        "SELECT r.id, r.shortage_type, r.severity, r.title, r.description, r.created_at, r.acknowledged_at,
                r.team_id, r.reporter_id, u.name AS reporter_name, u.is_external, u.guest_org_name, u.guest_country_code,
                COALESCE(vt.name, mvt.label) AS home_team_name, COALESCE(vt.color, mvt.color) AS home_team_color,
                mt.codename, mt.team_number
         FROM mission_shortage_reports r
         JOIN users u ON u.id = r.reporter_id
         LEFT JOIN volunteer_teams vt ON vt.id = u.volunteer_team_id
         LEFT JOIN mission_visitor_tags mvt ON mvt.id = u.mission_visitor_tag_id
         LEFT JOIN mission_teams mt ON mt.id = r.team_id
         WHERE r.mission_id = ? AND r.resolved_at IS NULL AND r.not_resolved_at IS NULL
         ORDER BY FIELD(r.severity, 'critical', 'high', 'medium', 'low'), r.created_at ASC",
        [$missionId]
    );

    return array_map(function ($row) {
        [$homeBg, $homeFg] = teamBadgeColors($row['home_team_color']);
        return [
            'id'                 => (int) $row['id'],
            'type_label'         => shortageTypeLabel($row['shortage_type']),
            'severity'           => $row['severity'],
            'severity_label'     => shortageSeverityLabel($row['severity']),
            'title'              => $row['title'],
            'description'        => $row['description'],
            'reporter_name'      => $row['reporter_name'],
            'user_id'            => (int) $row['reporter_id'],
            'is_external'        => (bool) $row['is_external'],
            'guest_org_name'     => $row['guest_org_name'],
            'home_team_name'     => $row['home_team_name'],
            'home_team_color_bg' => $homeBg,
            'home_team_color_fg' => $homeFg,
            'guest_country_code' => $row['guest_country_code'],
            'team_label'         => $row['team_id'] ? teamLabel($row['codename'], $row['team_number']) : t('history.no_team_capitalized'),
            'created_at'         => date('d/m H:i', strtotime($row['created_at'])),
            'acknowledged_at'    => $row['acknowledged_at'] ? date('d/m H:i', strtotime($row['acknowledged_at'])) : null,
        ];
    }, $rows);
}

/**
 * Privacy redaction agreed with the mission owner for patient PII wherever it
 * isn't reporting/resolving command staff looking (live panel for regular
 * participants, PDF report, mission-stats). Name keeps the surname in full and
 * initials the given name(s); phone keeps only the last 4 digits. Deliberately
 * simple/pure (no DB, no unknown-patient check) — callers decide when to call
 * these vs. show the raw value.
 */
function maskPatientName(string $name): string {
    $name = trim($name);
    if ($name === '') return '';
    $parts = preg_split('/\s+/', $name);
    $surname = array_pop($parts);
    if (empty($parts)) {
        return mb_substr($surname, 0, 1) . '.';
    }
    $initials = implode(' ', array_map(fn($p) => mb_substr($p, 0, 1) . '.', $parts));
    return $initials . ' ' . $surname;
}

function maskPatientPhone(string $phone): string {
    $digits = preg_replace('/\D+/', '', $phone);
    $len = mb_strlen($digits);
    if ($len <= 4) return str_repeat('*', $len);
    $visible = mb_substr($digits, -4);
    $maskedCount = $len - 4;
    $groups = [];
    for ($i = 0; $i < $maskedCount; $i += 3) {
        $groups[] = str_repeat('*', min(3, $maskedCount - $i));
    }
    return implode(' ', $groups) . ' ' . $visible;
}

/**
 * War Room: open (unresolved) incidents for the mission, shaped for both
 * audiences from one query — $unmasked=true (command staff) gets the real
 * patient_name/phone/notes, $unmasked=false (any other approved participant)
 * gets maskPatientName()/maskPatientPhone() and notes stripped entirely (never
 * shown outside command staff, per the mission owner's privacy decision).
 * Mirrors loadUnresolvedShortageReportsForMission()'s shape/ordering.
 */
function loadUnresolvedIncidentsForMission(int $missionId, bool $unmasked): array {
    $rows = dbFetchAll(
        "SELECT i.id, i.incident_type, i.severity, i.is_unknown_patient, i.patient_name,
                i.estimated_age, i.gender, i.phone, i.notes, i.team_id, i.lat, i.lng,
                i.created_at, i.acknowledged_at,
                u.name AS reporter_name, u.is_external, u.guest_org_name, u.guest_country_code,
                COALESCE(vt.name, mvt.label) AS home_team_name, COALESCE(vt.color, mvt.color) AS home_team_color,
                mt.codename, mt.team_number
         FROM mission_incidents i
         JOIN users u ON u.id = i.reporter_id
         LEFT JOIN volunteer_teams vt ON vt.id = u.volunteer_team_id
         LEFT JOIN mission_visitor_tags mvt ON mvt.id = u.mission_visitor_tag_id
         LEFT JOIN mission_teams mt ON mt.id = i.team_id
         WHERE i.mission_id = ? AND i.resolved_at IS NULL
         ORDER BY FIELD(i.severity, 'critical', 'high', 'medium', 'low'), i.created_at ASC",
        [$missionId]
    );

    return array_map(function ($row) use ($unmasked) {
        $isUnknown = (bool) $row['is_unknown_patient'];
        [$homeBg, $homeFg] = teamBadgeColors($row['home_team_color']);
        return [
            'id'                 => (int) $row['id'],
            'type_label'         => incidentTypeLabel($row['incident_type']),
            'severity'           => $row['severity'],
            'severity_label'     => incidentSeverityLabel($row['severity']),
            'is_unknown_patient' => $isUnknown,
            'patient_name'       => $isUnknown ? null : ($unmasked ? $row['patient_name'] : maskPatientName($row['patient_name'])),
            'estimated_age'      => $row['estimated_age'],
            'gender_label'       => $row['gender'] ? incidentGenderLabel($row['gender']) : null,
            'phone'              => $row['phone'] ? ($unmasked ? $row['phone'] : maskPatientPhone($row['phone'])) : null,
            'notes'              => $unmasked ? $row['notes'] : null,
            'reporter_name'      => $row['reporter_name'],
            'is_external'        => (bool) $row['is_external'],
            'guest_org_name'     => $row['guest_org_name'],
            'home_team_name'     => $row['home_team_name'],
            'home_team_color_bg' => $homeBg,
            'home_team_color_fg' => $homeFg,
            'guest_country_code' => $row['guest_country_code'],
            'team_label'         => $row['team_id'] ? teamLabel($row['codename'], $row['team_number']) : t('history.no_team_capitalized'),
            'lat'                => $row['lat'] !== null ? (float) $row['lat'] : null,
            'lng'                => $row['lng'] !== null ? (float) $row['lng'] : null,
            'created_at'         => date('d/m H:i', strtotime($row['created_at'])),
            'acknowledged_at'    => $row['acknowledged_at'] ? date('d/m H:i', strtotime($row['acknowledged_at'])) : null,
        ];
    }, $rows);
}

/**
 * War Room: Points of Interest for the mission — a physical clue
 * photographed while searching (mission-photo.php's upload action creates/
 * joins these), each with every photo merged into it (multiple nearby
 * reports become one pin, see that file's proximity-match comment). Unlike
 * incidents/shortages this has no masking — visible in full to every
 * approved participant, no separate admin-only shape, per the mission
 * owner's explicit answer that a POI should be as visible as an incident
 * (other teams converging on/avoiding the area is the whole point, and
 * there's no patient-privacy-equivalent concern here to mask in the first
 * place). Caller still gates the call itself behind canManageWarRoom ||
 * isApprovedParticipant, same as every other Action Room data loader.
 */
function loadPointsOfInterestForMission(int $missionId): array {
    $pois = dbFetchAll(
        "SELECT p.id, p.lat, p.lng, p.checked_at, cb.name AS checked_by_name, p.created_at
         FROM mission_points_of_interest p
         LEFT JOIN users cb ON cb.id = p.checked_by
         WHERE p.mission_id = ?
         ORDER BY (p.checked_at IS NULL) DESC, p.created_at DESC",
        [$missionId]
    );
    if (!$pois) {
        return [];
    }

    $poiIds = array_map('intval', array_column($pois, 'id'));
    $placeholders = implode(',', array_fill(0, count($poiIds), '?'));
    $photoRows = dbFetchAll(
        "SELECT ph.id, ph.poi_id, ph.media_type, ph.thumb_stored_name, ph.user_id, ph.poi_note, ph.created_at,
                u.name AS reporter_name, u.is_external, u.guest_org_name, u.guest_country_code,
                COALESCE(vt.name, mvt.label) AS home_team_name, COALESCE(vt.color, mvt.color) AS home_team_color
         FROM mission_photos ph
         JOIN users u ON u.id = ph.user_id
         LEFT JOIN volunteer_teams vt ON vt.id = u.volunteer_team_id
         LEFT JOIN mission_visitor_tags mvt ON mvt.id = u.mission_visitor_tag_id
         WHERE ph.poi_id IN ($placeholders)
         ORDER BY ph.created_at ASC",
        $poiIds
    );
    $photosByPoi = [];
    foreach ($photoRows as $row) {
        [$homeBg, $homeFg] = teamBadgeColors($row['home_team_color']);
        $photosByPoi[(int) $row['poi_id']][] = [
            'id'                 => (int) $row['id'],
            'media_type'         => $row['media_type'],
            'has_thumb'          => $row['thumb_stored_name'] !== null,
            'reporter_name'      => $row['reporter_name'],
            'is_external'        => (bool) $row['is_external'],
            'guest_org_name'     => $row['guest_org_name'],
            'home_team_name'     => $row['home_team_name'],
            'home_team_color_bg' => $homeBg,
            'home_team_color_fg' => $homeFg,
            'guest_country_code' => $row['guest_country_code'],
            'note'               => $row['poi_note'],
            'time'               => date('d/m H:i', strtotime($row['created_at'])),
        ];
    }

    return array_map(function ($p) use ($photosByPoi) {
        $photos = $photosByPoi[(int) $p['id']] ?? [];
        // Distinct reporters in first-seen order — a merged POI's whole
        // point is showing it was independently corroborated by more than
        // one person, not a photo-by-photo dump (per the mission owner's
        // explicit ask: "mention it was given by 2 volunteers").
        $reporterNames = [];
        foreach ($photos as $photo) {
            if (!in_array($photo['reporter_name'], $reporterNames, true)) {
                $reporterNames[] = $photo['reporter_name'];
            }
        }
        return [
            'id'              => (int) $p['id'],
            'lat'             => (float) $p['lat'],
            'lng'             => (float) $p['lng'],
            'checked_at'      => $p['checked_at'] ? date('d/m H:i', strtotime($p['checked_at'])) : null,
            'checked_by_name' => $p['checked_by_name'],
            'created_at'      => date('d/m H:i', strtotime($p['created_at'])),
            'reporter_names'  => $reporterNames,
            'photos'          => $photos,
        ];
    }, $pois);
}

/**
 * Resolves mission_types.id for "Αναζήτηση Αγνοουμένου" (Missing Person
 * Search) by name instead of a hardcoded id. Migration v128 originally
 * pinned this to a shared constant (id 7), assuming every deployment's
 * mission_types table would assign it the same id — broke on any database
 * that already had a different custom type sitting at id 7 (real incident:
 * epidrasis.iloveweb.gr's admin-created "Τ.Ε.Π." type, 61 real missions —
 * see v128's own comment, includes/migrations.php, for the full story).
 * Migration v134 creates the row wherever it's still missing, without
 * pinning an id, so each database can have it at whatever id it lands on —
 * this function is what makes that safe to do. Cached per-request (same
 * static-cache shape as getSetting(), functions-core.php) since both
 * callers (war-room.php, mission-view.php) check this on every page load.
 * Returns null if the type doesn't exist yet on this database (hasn't
 * reached v134 yet) — every caller compares the result with a strict ===
 * against an int, so null just means "no mission currently matches", not a
 * crash.
 */
function missingPersonMissionTypeId(): ?int {
    static $cached = false; // false = not looked up yet; null = looked up, doesn't exist
    if ($cached === false) {
        $row = dbFetchOne("SELECT id FROM mission_types WHERE name = ?", ['Αναζήτηση Αγνοουμένου']);
        $cached = $row ? (int) $row['id'] : null;
    }
    return $cached;
}

/**
 * The single missing-person profile for this mission (mission_missing_persons
 * is one row per mission_id, UNIQUE-constrained), or null if staff haven't
 * filled one in yet. Called only for missions of the "Αναζήτηση Αγνοουμένου"
 * type — see missingPersonMissionTypeId() above.
 */
function loadMissingPersonForMission(int $missionId): ?array {
    $p = dbFetchOne(
        "SELECT id, full_name, age, description, clothing_description, vehicle, photo,
                last_seen_label, last_seen_lat, last_seen_lng, last_seen_at, subject_category,
                disappearance_circumstances, likely_direction, witness_accounts, updated_at
         FROM mission_missing_persons WHERE mission_id = ?",
        [$missionId]
    );
    if (!$p) {
        return null;
    }
    return [
        'id'                    => (int) $p['id'],
        'full_name'             => $p['full_name'],
        'age'                   => $p['age'] !== null ? (int) $p['age'] : null,
        'description'           => $p['description'],
        'clothing_description'  => $p['clothing_description'],
        'vehicle'               => $p['vehicle'],
        'photo'                 => $p['photo'],
        // Drives the "LPB search rings" map layer (includes/lpb-rings.php) —
        // null until staff pick a category on the edit form.
        'subject_category'      => $p['subject_category'],
        'last_seen_label'       => $p['last_seen_label'],
        'last_seen_lat'         => $p['last_seen_lat'] !== null ? (float) $p['last_seen_lat'] : null,
        'last_seen_lng'         => $p['last_seen_lng'] !== null ? (float) $p['last_seen_lng'] : null,
        // Human display format (d/m/Y H:i) isn't safely round-trippable back
        // into an <input type="datetime-local"> (strtotime() on an ambiguous
        // d/m/Y string risks misparsing as m/d/Y) — _raw carries the
        // unambiguous MySQL value straight through for the edit form only;
        // every display use (this file's initial render and the poll's live
        // JS update) uses the formatted key instead.
        'last_seen_at'          => $p['last_seen_at'] ? date('d/m/Y H:i', strtotime($p['last_seen_at'])) : null,
        'last_seen_at_raw'      => $p['last_seen_at'] ? date('Y-m-d\TH:i', strtotime($p['last_seen_at'])) : null,
        'disappearance_circumstances' => $p['disappearance_circumstances'],
        'likely_direction'      => $p['likely_direction'],
        'witness_accounts'      => $p['witness_accounts'],
        'updated_at'            => date('d/m H:i', strtotime($p['updated_at'])),
    ];
}

/**
 * mission-report-print.php: every incident from the mission (resolved or not
 * — unlike loadUnresolvedIncidentsForMission() above, a closed-mission PDF
 * must show the full history), always masked (PDF is print/export, never
 * command-staff-only screen) and never including notes (staff-only, per the
 * mission owner's privacy decision — see mission_incidents migration).
 * Deliberately its own query rather than reusing computeMissionResponseReport()
 * — incidents are NOT wired into that function's scoring pipeline (see the
 * mission_incidents migration's docblock for why).
 */
function loadIncidentDetailForMissionReport(int $missionId): array {
    $rows = dbFetchAll(
        "SELECT i.incident_type, i.severity, i.is_unknown_patient, i.patient_name, i.phone,
                i.estimated_age, i.gender, i.outcome, i.outcome_location, i.team_id,
                i.created_at, i.acknowledged_at, i.resolved_at,
                u.name AS reporter_name, mt.codename, mt.team_number
         FROM mission_incidents i
         JOIN users u ON u.id = i.reporter_id
         LEFT JOIN mission_teams mt ON mt.id = i.team_id
         WHERE i.mission_id = ?
         ORDER BY FIELD(i.severity, 'critical', 'high', 'medium', 'low'), i.created_at ASC",
        [$missionId]
    );

    return array_map(function ($row) {
        $isUnknown = (bool) $row['is_unknown_patient'];
        return [
            'severity'         => $row['severity'],
            'severity_label'   => incidentSeverityLabel($row['severity']),
            'type_label'       => incidentTypeLabel($row['incident_type']),
            'who'              => $isUnknown ? t('incident.unknown_patient_label') : maskPatientName((string) $row['patient_name']),
            'phone'            => $row['phone'] ? maskPatientPhone($row['phone']) : null,
            'estimated_age'    => $row['estimated_age'],
            'gender_label'     => $row['gender'] ? incidentGenderLabel($row['gender']) : null,
            'outcome_label'    => $row['outcome'] ? incidentOutcomeLabel($row['outcome']) : null,
            'outcome_location' => $row['outcome_location'],
            'reporter_name'    => $row['reporter_name'],
            'team_label'       => $row['team_id'] ? teamLabel($row['codename'], $row['team_number']) : t('history.no_team_capitalized'),
            'created_at'       => $row['created_at'],
            'acknowledged_at'  => $row['acknowledged_at'],
            'resolved_at'      => $row['resolved_at'],
        ];
    }, $rows);
}

/**
 * War Room: open (unresolved) SOS alerts for the command-staff alarm overlay +
 * "Ειδοποιήσεις SOS" card. Caller MUST gate this behind $canManageWarRoom before
 * calling — reporter identity and live location are sensitive, this function has
 * no built-in permission check of its own. Mirrors loadUnresolvedShortageReportsForMission,
 * except user_name/team_label are escaped here (not left raw) since this feeds an
 * auto-triggered, no-click-required surface rather than a manually-opened list.
 */
function loadOpenSosAlertsForMission(int $missionId): array {
    $rows = dbFetchAll(
        "SELECT a.id, a.pr_id, a.lat, a.lng, a.created_at, a.acknowledged_at,
                a.team_id, a.user_id, u.name AS user_name, u.is_external, u.guest_org_name, u.guest_country_code,
                COALESCE(vt.name, mvt.label) AS home_team_name, COALESCE(vt.color, mvt.color) AS home_team_color,
                mt.codename, mt.team_number
         FROM mission_sos_alerts a
         JOIN users u ON u.id = a.user_id
         LEFT JOIN volunteer_teams vt ON vt.id = u.volunteer_team_id
         LEFT JOIN mission_visitor_tags mvt ON mvt.id = u.mission_visitor_tag_id
         LEFT JOIN mission_teams mt ON mt.id = a.team_id
         WHERE a.mission_id = ? AND a.resolved_at IS NULL
         ORDER BY a.created_at ASC",
        [$missionId]
    );

    // user_name is deliberately NOT pre-escaped here (unlike before) — it now
    // rides along with is_external/guest_org_name for the JS-side
    // guestNameHtml() helper to wrap and escape together, same as the
    // dispatch-acks/media loaders. team_label keeps its original
    // pre-escaped-server-side treatment, unchanged.
    return array_map(function ($row) {
        [$homeBg, $homeFg] = teamBadgeColors($row['home_team_color']);
        return [
            'id'                 => (int) $row['id'],
            'user_name'          => $row['user_name'],
            'user_id'            => (int) $row['user_id'],
            'is_external'        => (bool) $row['is_external'],
            'guest_org_name'     => $row['guest_org_name'],
            'home_team_name'     => $row['home_team_name'],
            'home_team_color_bg' => $homeBg,
            'home_team_color_fg' => $homeFg,
            'guest_country_code' => $row['guest_country_code'],
            'team_label'         => h($row['team_id'] ? teamLabel($row['codename'], $row['team_number']) : t('history.no_team_capitalized')),
            'lat'                => $row['lat'] !== null ? (float) $row['lat'] : null,
            'lng'                => $row['lng'] !== null ? (float) $row['lng'] : null,
            'created_at'         => date('d/m H:i', strtotime($row['created_at'])),
            'acknowledged_at'    => $row['acknowledged_at'] ? date('d/m H:i', strtotime($row['acknowledged_at'])) : null,
        ];
    }, $rows);
}

/**
 * War Room: admin-drawn restricted (hazard/danger) area polygons for this
 * mission's map layer. No per-item personalization needed (unlike areas/
 * sectors) — the caller decides whether to call this at all, same external
 * gate loadOpenSosAlertsForMission() already uses ($canManageWarRoom ? load() : []),
 * since field mode has no map to draw these on regardless of role.
 */
function loadMissionRestrictedAreasForUser(int $missionId): array {
    $rows = dbFetchAll(
        "SELECT id, label, geo, created_at FROM mission_restricted_areas WHERE mission_id = ? ORDER BY created_at ASC",
        [$missionId]
    );
    return array_map(fn($row) => [
        'id'         => (int) $row['id'],
        'label'      => $row['label'],
        'geo'        => json_decode($row['geo'], true),
        'created_at' => date('d/m H:i', strtotime($row['created_at'])),
    ], $rows);
}

/**
 * War Room: open (unresolved) restricted-area breaches. Unlike
 * loadMissionRestrictedAreasForUser() above, this one personalizes AT LOAD
 * TIME rather than via an external all-or-nothing gate — admin gets every
 * open breach mission-wide (drives the admin's own full-screen alarm +
 * breach-list card), a regular approved participant gets only their OWN
 * breaches (drives just their own full-screen alarm, and only their own —
 * they must never see, or be alarmed by, a different team's breach). This
 * is what lets one shared client-side alarm function correctly serve both
 * audiences: the payload is already scoped correctly per caller by the time
 * it reaches the client. Row shape mirrors loadOpenSosAlertsForMission()
 * above (guest-aware user_name/home-team fields for the same client-side
 * guestNameHtml() treatment).
 */
function loadOpenRestrictedAreaBreachesForUser(int $missionId, int $userId, bool $canManageWarRoom): array {
    $sql = "SELECT b.id, b.area_label, b.lat, b.lng, b.exited_at, b.acknowledged_at, b.created_at,
                   b.user_id, b.team_id, u.name AS user_name, u.is_external, u.guest_org_name, u.guest_country_code,
                   COALESCE(vt.name, mvt.label) AS home_team_name, COALESCE(vt.color, mvt.color) AS home_team_color,
                   mt.codename, mt.team_number
            FROM mission_restricted_area_breaches b
            JOIN users u ON u.id = b.user_id
            LEFT JOIN volunteer_teams vt ON vt.id = u.volunteer_team_id
            LEFT JOIN mission_visitor_tags mvt ON mvt.id = u.mission_visitor_tag_id
            LEFT JOIN mission_teams mt ON mt.id = b.team_id
            WHERE b.mission_id = ? AND b.resolved_at IS NULL";
    $params = [$missionId];
    if (!$canManageWarRoom) {
        $sql .= " AND b.user_id = ?";
        $params[] = $userId;
    }
    $sql .= " ORDER BY b.created_at ASC";

    $rows = dbFetchAll($sql, $params);
    return array_map(function ($row) use ($userId) {
        [$homeBg, $homeFg] = teamBadgeColors($row['home_team_color']);
        return [
            'id'                 => (int) $row['id'],
            'area_label'         => $row['area_label'],
            'user_name'          => $row['user_name'],
            'user_id'            => (int) $row['user_id'],
            'is_external'        => (bool) $row['is_external'],
            'guest_org_name'     => $row['guest_org_name'],
            'home_team_name'     => $row['home_team_name'],
            'home_team_color_bg' => $homeBg,
            'home_team_color_fg' => $homeFg,
            'guest_country_code' => $row['guest_country_code'],
            'team_label'         => h($row['team_id'] ? teamLabel($row['codename'], $row['team_number']) : t('history.no_team_capitalized')),
            'lat'                => (float) $row['lat'],
            'lng'                => (float) $row['lng'],
            'exited_at'          => $row['exited_at'] ? date('d/m H:i', strtotime($row['exited_at'])) : null,
            'acknowledged_at'    => $row['acknowledged_at'] ? date('d/m H:i', strtotime($row['acknowledged_at'])) : null,
            'created_at'         => date('d/m H:i', strtotime($row['created_at'])),
            'is_mine'            => (int) $row['user_id'] === $userId,
        ];
    }, $rows);
}

/**
 * War Room: same shape as loadOpenRestrictedAreaBreachesForUser() above, but
 * WITHOUT the resolved_at filter — feeds the admin-facing breach LIST widget,
 * not the alarm state machine. Kept as a fully separate function rather than
 * a parameter on the existing one: the alarm machine's "no items at all"
 * branch depends on genuinely seeing an empty array once nothing is open,
 * and mixing resolved rows into that same array would break it. A deleted/
 * resolved zone's breach must not simply vanish from the one place admin
 * most naturally looks for it — "auto-resolve not auto-erase" already held
 * at the data layer, this is what makes it hold in the UI too. Capped at 50:
 * breaches are a rare event compared to e.g. GPS pings, this is headroom,
 * not an expected real limit.
 */
function loadRestrictedAreaBreachHistoryForUser(int $missionId, int $userId, bool $canManageWarRoom): array {
    $sql = "SELECT b.id, b.area_label, b.lat, b.lng, b.exited_at, b.acknowledged_at, b.resolved_at, b.created_at,
                   b.user_id, b.team_id, u.name AS user_name, u.is_external, u.guest_org_name, u.guest_country_code,
                   COALESCE(vt.name, mvt.label) AS home_team_name, COALESCE(vt.color, mvt.color) AS home_team_color,
                   mt.codename, mt.team_number, ru.name AS resolved_by_name
            FROM mission_restricted_area_breaches b
            JOIN users u ON u.id = b.user_id
            LEFT JOIN volunteer_teams vt ON vt.id = u.volunteer_team_id
            LEFT JOIN mission_visitor_tags mvt ON mvt.id = u.mission_visitor_tag_id
            LEFT JOIN mission_teams mt ON mt.id = b.team_id
            LEFT JOIN users ru ON ru.id = b.resolved_by
            WHERE b.mission_id = ?";
    $params = [$missionId];
    if (!$canManageWarRoom) {
        $sql .= " AND b.user_id = ?";
        $params[] = $userId;
    }
    $sql .= " ORDER BY b.created_at DESC LIMIT 50";

    $rows = dbFetchAll($sql, $params);
    return array_map(function ($row) use ($userId) {
        [$homeBg, $homeFg] = teamBadgeColors($row['home_team_color']);
        return [
            'id'                 => (int) $row['id'],
            'area_label'         => $row['area_label'],
            'user_name'          => $row['user_name'],
            'user_id'            => (int) $row['user_id'],
            'is_external'        => (bool) $row['is_external'],
            'guest_org_name'     => $row['guest_org_name'],
            'home_team_name'     => $row['home_team_name'],
            'home_team_color_bg' => $homeBg,
            'home_team_color_fg' => $homeFg,
            'guest_country_code' => $row['guest_country_code'],
            'team_label'         => h($row['team_id'] ? teamLabel($row['codename'], $row['team_number']) : t('history.no_team_capitalized')),
            'lat'                => (float) $row['lat'],
            'lng'                => (float) $row['lng'],
            'exited_at'          => $row['exited_at'] ? date('d/m H:i', strtotime($row['exited_at'])) : null,
            'acknowledged_at'    => $row['acknowledged_at'] ? date('d/m H:i', strtotime($row['acknowledged_at'])) : null,
            'resolved_at'        => $row['resolved_at'] ? date('d/m H:i', strtotime($row['resolved_at'])) : null,
            'resolved_by_name'   => $row['resolved_by_name'],
            'created_at'         => date('d/m H:i', strtotime($row['created_at'])),
            'is_mine'            => (int) $row['user_id'] === $userId,
        ];
    }, $rows);
}

/**
 * War Room: user_ids currently "present" on this mission's War Room — last
 * touched the 15s ajax poll within the last 2x its interval. Shared by
 * war-room.php's full-page render (initial dot state) and its own ajax
 * branch (per-poll dot state) so both compute "online" identically.
 */
function loadOnlinePresenceUserIds(int $missionId): array {
    $rows = dbFetchAll(
        "SELECT user_id FROM mission_presence WHERE mission_id = ? AND last_seen_at > DATE_SUB(NOW(), INTERVAL 30 SECOND)",
        [$missionId]
    );
    return array_map('intval', array_column($rows, 'user_id'));
}

function reportMinutesBetween(?string $from, ?string $to): ?float {
    if (!$from || !$to) {
        return null;
    }
    return round((strtotime($to) - strtotime($from)) / 60, 1);
}

/**
 * Mission team display label. team_number is nullable — a team given a
 * custom name (instead of the auto NATO codename) never gets a number
 * appended, per explicit request; the auto-assigned NATO codenames still
 * always pair with their random 2-digit number as before.
 */
function teamLabel(?string $codename, $teamNumber): string {
    if ($codename === null || $codename === '') {
        return '';
    }
    return ($teamNumber !== null && $teamNumber !== '') ? ($codename . ' ' . $teamNumber) : $codename;
}

/**
 * Display label for a Route Order with no single nominal team (team_id
 * NULL — a cross-team route assembled from specific individuals across 2+
 * teams rather than one team's roster, see migration v110). Falls back to
 * a comma-joined list of the route's actual members' names instead of a
 * generic "mixed team" string, since names are proper nouns and need no
 * per-recipient translation — unlike a translated placeholder would, in
 * the several call sites that reuse this value as a {team} substitution
 * across a notification sent to recipients in different languages.
 */
function routeMixedTeamLabel(int $routeId): string {
    $names = array_column(dbFetchAll(
        "SELECT u.name FROM mission_route_members rm JOIN users u ON u.id = rm.user_id WHERE rm.route_id = ? ORDER BY u.name",
        [$routeId]
    ), 'name');
    return implode(', ', $names);
}

/**
 * Live ETA for a team travelling to a point-type dispatch (a polygon zone
 * has no single destination, so this is never called for those). "The
 * team's position" is whichever member's GPS ping is most recent — same
 * one-team-is-one-unit convention Route Order already uses for
 * depart/arrive/complete, rather than tracking N separate per-member ETAs.
 *
 * Routed via OSRM's public router — same free, no-API-key OSM-ecosystem
 * service this app already talks to for geocoding (geocode-address.php's
 * Nominatim call), same cURL shape. Falls back to straight-line distance at
 * an assumed average speed if OSRM is unreachable or the response is
 * unusable — advisory only, exactly like every other GPS-derived number
 * this app already shows (dwell time, arrival distance): never blocks
 * anything, just may be a rougher estimate, and says so.
 *
 * Cached in dispatch_eta_cache, keyed to the ping it was computed from —
 * this poll's caller (loadMissionDispatchesForUser(), hit every 5s by every
 * open War Room tab) only triggers a fresh OSRM call once the team's
 * underlying ping actually changes (roughly the ~3min auto-ping cadence),
 * not on every poll tick.
 *
 * Returns null if this team has sent no GPS ping at all yet.
 */
function computeDispatchEta(int $dispatchId, int $teamId, float $destLat, float $destLng): ?array {
    $ping = dbFetchOne(
        "SELECT vp.lat, vp.lng, vp.created_at
         FROM volunteer_pings vp
         JOIN mission_team_members mtm ON mtm.user_id = vp.user_id
         JOIN shifts s ON s.id = vp.shift_id AND s.mission_id = mtm.mission_id
         WHERE mtm.team_id = ?
         ORDER BY vp.created_at DESC LIMIT 1",
        [$teamId]
    );
    if (!$ping) {
        return null;
    }

    $pingLat = (float) $ping['lat'];
    $pingLng = (float) $ping['lng'];
    $pingCreatedAt = $ping['created_at'];
    $pingAgeSeconds = time() - strtotime($pingCreatedAt);
    $isStale = $pingAgeSeconds > warRoomPingStaleThresholdSeconds();

    $cached = dbFetchOne(
        "SELECT minutes, source, ping_created_at FROM dispatch_eta_cache WHERE dispatch_id = ?",
        [$dispatchId]
    );
    if ($cached && $cached['ping_created_at'] === $pingCreatedAt) {
        return [
            'minutes' => (int) $cached['minutes'], 'source' => $cached['source'],
            'ping_age_seconds' => $pingAgeSeconds, 'is_stale' => $isStale,
        ];
    }

    $osrm = fetchOsrmEtaMinutes($pingLat, $pingLng, $destLat, $destLng);
    [$minutes, $source] = $osrm ?? [straightLineEtaMinutes($pingLat, $pingLng, $destLat, $destLng), 'straight_line'];

    dbExecute(
        "INSERT INTO dispatch_eta_cache (dispatch_id, minutes, source, ping_lat, ping_lng, ping_created_at)
         VALUES (?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE minutes = VALUES(minutes), source = VALUES(source),
             ping_lat = VALUES(ping_lat), ping_lng = VALUES(ping_lng), ping_created_at = VALUES(ping_created_at)",
        [$dispatchId, $minutes, $source, $pingLat, $pingLng, $pingCreatedAt]
    );

    return ['minutes' => $minutes, 'source' => $source, 'ping_age_seconds' => $pingAgeSeconds, 'is_stale' => $isStale];
}

/**
 * OSRM's public demo router — free, no API key, but explicitly a
 * lightweight demo service with no uptime guarantee, so this is a single
 * short-timeout attempt with no retry: a slow/down OSRM must not stall the
 * shared 5s War Room poll every open tab hits. 3s, not the 8s
 * geocode-address.php uses for its Nominatim call — that one is a one-off
 * lookup a user explicitly triggered and is actively waiting on; this one
 * runs unattended inside a background poll loop and needs to fail fast.
 * Returns null (triggering the straight-line fallback above) on any
 * failure — unreachable, timeout, non-OK response, or an unexpected shape.
 */
function fetchOsrmEtaMinutes(float $lat1, float $lng1, float $lat2, float $lng2): ?array {
    if (!function_exists('curl_init')) {
        return null;
    }
    $url = sprintf(
        'https://router.project-osrm.org/route/v1/driving/%s,%s;%s,%s?overview=false',
        sprintf('%.6f', $lng1), sprintf('%.6f', $lat1), sprintf('%.6f', $lng2), sprintf('%.6f', $lat2)
    );
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 3,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; VolunteerOps/' . APP_VERSION . ')',
    ]);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    if ($error || empty($response)) {
        return null;
    }
    $data = json_decode($response, true);
    if (($data['code'] ?? '') !== 'Ok' || !isset($data['routes'][0]['duration'])) {
        return null;
    }
    return [(int) round($data['routes'][0]['duration'] / 60), 'osrm'];
}

/**
 * Straight-line ETA fallback for when OSRM is unreachable — deliberately
 * rough (no road network, no terrain), so the caller always tags this as
 * 'straight_line' and the UI discloses it as an estimate rather than
 * presenting it with the same confidence as a real routed ETA. 30 km/h is a
 * generic mixed rural/urban driving average, not tuned to this app's actual
 * terrain (Cretan backroads, search areas partly on foot) — a placeholder
 * until real usage shows it needs adjusting.
 */
function straightLineEtaMinutes(float $lat1, float $lng1, float $lat2, float $lng2): int {
    $meters = gpsDistanceMeters($lat1, $lng1, $lat2, $lng2);
    $metersPerMinute = (30 * 1000) / 60;
    return max(1, (int) round($meters / $metersPerMinute));
}

/**
 * Great-circle distance between two GPS points, in meters (Haversine).
 * Used to flag a volunteer_pings row as "in motion" vs. stationary GPS
 * jitter — see $loadPins in war-room.php.
 */
function gpsDistanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $earthRadiusMeters = 6371000;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $earthRadiusMeters * $c;
}

/**
 * Compass bearing (0-360°, 0 = North, clockwise) from point 1 to point 2 —
 * the direction of travel for the live map's moving-pin arrow. Standard
 * great-circle initial-bearing formula, paired with gpsDistanceMeters()
 * above (same two-point GPS shape, $loadPins in war-room.php calls both on
 * the same $prevPing → $pin pair).
 */
function gpsBearingDegrees(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $lat1Rad = deg2rad($lat1);
    $lat2Rad = deg2rad($lat2);
    $dLngRad = deg2rad($lng2 - $lng1);
    $y = sin($dLngRad) * cos($lat2Rad);
    $x = cos($lat1Rad) * sin($lat2Rad) - sin($lat1Rad) * cos($lat2Rad) * cos($dLngRad);
    $bearing = rad2deg(atan2($y, $x));
    return fmod($bearing + 360, 360);
}

/**
 * One position sample per this many seconds when measuring how far somebody
 * walked.
 *
 * Summing every ping would read ~360 rows per person per half hour — 14.000
 * rows on a forty-person mission — to answer a question that does not need
 * that resolution. Six points give a path length accurate enough to tell
 * "searching a slope" from "sitting in the vehicle", at one sixtieth of the
 * cost.
 */
const VITALS_MOVE_SAMPLE_SECONDS = 300;

/**
 * How far each person has moved recently, keyed by user id.
 *
 * Returns both numbers because they answer different questions and disagree
 * in the case that matters. A team sweeping a slope walks two kilometres and
 * ends up ninety metres from where it started: the path says they are
 * working, the straight line says they are not. Reporting only the straight
 * line would call a working team stuck, and only the path would miss a
 * volunteer pacing beside a vehicle.
 *
 * One query. The inner GROUP BY rides idx_pings_shift_time (shift_id,
 * created_at) for the range and returns at most seven rows per person, which
 * the primary-key join then resolves to coordinates.
 *
 * $sinceTs is ABSOLUTE rather than "minutes ago" because one of its two
 * callers is the Action Room's 5-second poll, whose payload is md5-hashed to
 * avoid re-sending 51KB to every open tab. A window that slides with NOW()
 * changes the answer on every tick, the hash never matches, and the whole
 * payload goes out twelve times a minute to everybody. The poll passes a
 * quantised boundary; the assistant, answering one question on demand, passes
 * whatever it likes.
 */
function volunteerMovementByUser(array $shiftBinds, int $sinceTs): array {
    if (!$shiftBinds) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($shiftBinds), '?'));
    try {
        $rows = dbFetchAll(
            "SELECT s.user_id, p.lat, p.lng, UNIX_TIMESTAMP(p.created_at) AS ts
             FROM (SELECT user_id,
                          FLOOR(UNIX_TIMESTAMP(created_at) / ?) AS bucket,
                          MIN(id) AS pid
                     FROM volunteer_pings
                    WHERE shift_id IN ({$placeholders})
                      AND created_at >= FROM_UNIXTIME(?)
                    GROUP BY user_id, bucket) s
             JOIN volunteer_pings p ON p.id = s.pid
             ORDER BY s.user_id, s.bucket",
            array_merge([VITALS_MOVE_SAMPLE_SECONDS], $shiftBinds, [$sinceTs])
        );
    } catch (Exception $e) {
        error_log('[movement] query failed: ' . $e->getMessage());
        return [];
    }

    $byUser = [];
    foreach ($rows as $row) {
        $byUser[(int) $row['user_id']][] = [
            'lat' => (float) $row['lat'],
            'lng' => (float) $row['lng'],
            'ts'  => (int) $row['ts'],
        ];
    }

    $out = [];
    foreach ($byUser as $userId => $points) {
        // A single sample says nothing about movement — not "did not move",
        // which is what a zero here would be read as.
        if (count($points) < 2) {
            continue;
        }
        $path = 0.0;
        for ($i = 1; $i < count($points); $i++) {
            $path += gpsDistanceMeters(
                $points[$i - 1]['lat'], $points[$i - 1]['lng'],
                $points[$i]['lat'], $points[$i]['lng']
            );
        }
        $first = $points[0];
        $last  = $points[count($points) - 1];
        $out[$userId] = [
            'path'     => (int) round($path),
            'straight' => (int) round(gpsDistanceMeters($first['lat'], $first['lng'], $last['lat'], $last['lng'])),
            'minutes'  => (int) max(1, round(($last['ts'] - $first['ts']) / 60)),
        ];
    }
    return $out;
}

/**
 * Standard ray-casting point-in-polygon test (odd-number-of-crossings rule).
 * $geo is a ring of [lat, lng] pairs, same shape as every other polygon
 * stored in this app (mission_search_areas.geo etc.) — not GeoJSON's
 * [lng, lat] order. Flat-Cartesian on lat/lng, same accuracy tradeoff as the
 * point-to-segment projection in war-room.php's sector-split tool: not
 * geodesically exact, but well within tolerance at mission scale. Used by
 * checkRestrictedAreaBreach() below; no existing implementation elsewhere
 * in this codebase.
 */
function pointInPolygon(float $lat, float $lng, array $geo): bool {
    $inside = false;
    $n = count($geo);
    for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
        $latI = (float) $geo[$i][0];
        $lngI = (float) $geo[$i][1];
        $latJ = (float) $geo[$j][0];
        $lngJ = (float) $geo[$j][1];
        $crosses = (($latI > $lat) !== ($latJ > $lat))
            && ($lng < ($lngJ - $lngI) * ($lat - $latI) / ($latJ - $latI) + $lngI);
        if ($crosses) {
            $inside = !$inside;
        }
    }
    return $inside;
}

/**
 * A point in the middle of a polygon — and guaranteed to be INSIDE it.
 *
 * The obvious answer is the area-weighted centroid, and for the rectangles and
 * pie slices the sector tool draws it is the right one. But a sector traced
 * around the shape of a gorge is concave, and the centroid of a concave shape
 * can sit outside it: the middle of a horseshoe is not in the horseshoe. Sent
 * a team as "the middle of your sector", such a point is on the wrong side of
 * a ridge from every part of the ground they were asked to search.
 *
 * So the centroid is tested, and when it falls outside, the midpoint of the
 * widest span of polygon along the centroid's own latitude is used instead —
 * cheap, always inside, and still recognisably "the middle" on a map.
 *
 * $geo is a ring of [lat, lng] pairs, the same shape pointInPolygon() takes.
 * Returns ['lat' => float, 'lng' => float] or null for anything that is not a
 * polygon.
 */
function polygonCentroid(array $geo): ?array {
    $ring = [];
    foreach ($geo as $p) {
        if (!is_array($p) || !isset($p[0], $p[1]) || !is_numeric($p[0]) || !is_numeric($p[1])) continue;
        $ring[] = [(float) $p[0], (float) $p[1]];
    }
    $n = count($ring);
    if ($n < 3) {
        // Two points is a line and one is a point: their middle is still a
        // perfectly good answer, and refusing would lose a real target.
        if ($n === 2) return ['lat' => ($ring[0][0] + $ring[1][0]) / 2, 'lng' => ($ring[0][1] + $ring[1][1]) / 2];
        if ($n === 1) return ['lat' => $ring[0][0], 'lng' => $ring[0][1]];
        return null;
    }

    // Standard area-weighted centroid, flat-Cartesian on lat/lng — the same
    // tradeoff pointInPolygon() already accepts at mission scale.
    $twiceArea = 0.0;
    $lat = 0.0;
    $lng = 0.0;
    for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
        $cross = $ring[$j][1] * $ring[$i][0] - $ring[$i][1] * $ring[$j][0];
        $twiceArea += $cross;
        $lat += ($ring[$j][0] + $ring[$i][0]) * $cross;
        $lng += ($ring[$j][1] + $ring[$i][1]) * $cross;
    }

    if (abs($twiceArea) > 1e-12) {
        $cLat = $lat / (3 * $twiceArea);
        $cLng = $lng / (3 * $twiceArea);
        if (pointInPolygon($cLat, $cLng, $ring)) {
            return ['lat' => $cLat, 'lng' => $cLng];
        }
    } else {
        // Degenerate ring (every vertex collinear): fall through to the mean,
        // which for a line is its middle.
        $cLat = array_sum(array_column($ring, 0)) / $n;
        $cLng = array_sum(array_column($ring, 1)) / $n;
        return ['lat' => $cLat, 'lng' => $cLng];
    }

    // Concave, and the centroid escaped. Cut the polygon with the horizontal
    // line through it and take the middle of the widest piece that is actually
    // polygon.
    $xs = [];
    for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
        $latI = $ring[$i][0];
        $latJ = $ring[$j][0];
        if (($latI > $cLat) === ($latJ > $cLat)) continue;
        $xs[] = ($ring[$j][1] - $ring[$i][1]) * ($cLat - $latI) / ($latJ - $latI) + $ring[$i][1];
    }
    sort($xs);
    $bestMid = null;
    $bestSpan = -1.0;
    // Crossings pair up: inside, outside, inside… so every other gap is solid.
    for ($i = 0; $i + 1 < count($xs); $i += 2) {
        $span = $xs[$i + 1] - $xs[$i];
        if ($span > $bestSpan) {
            $bestSpan = $span;
            $bestMid = ($xs[$i] + $xs[$i + 1]) / 2;
        }
    }
    if ($bestMid !== null) {
        return ['lat' => $cLat, 'lng' => $bestMid];
    }

    // Nothing worked, which should not happen for a real ring. The first
    // vertex is on the polygon and is better than no target at all.
    return ['lat' => $ring[0][0], 'lng' => $ring[0][1]];
}

/**
 * Distance in meters from a point to the nearest edge of a polygon (0 if
 * the point is already inside — see pointInPolygon() above). Projects to a
 * local flat X/Y in meters centered on the query point itself, then takes
 * the minimum standard point-to-segment distance over every edge — same
 * flat-Cartesian-at-mission-scale tradeoff pointInPolygon()'s own doc
 * comment already accepts. Used for the Field Mode restricted-area
 * proximity card (no map there to just look at the polygon directly).
 */
/**
 * How far outside its sector a building may stand and still be filed there.
 *
 * A building ON the boundary, or dropped from a GPS fix taken beside it, is
 * legitimately a few metres out; refusing that would block somebody recording
 * a real building mid-operation. Past this it is not a rounding matter — it is
 * a building on the wrong side of a line, and the team clearing that sector
 * will never walk past it.
 */
const SECTOR_BUILDING_TOLERANCE_METRES = 30.0;

function pointToPolygonDistanceMeters(float $lat, float $lng, array $geo): float {
    if (pointInPolygon($lat, $lng, $geo)) {
        return 0.0;
    }

    $latDegPerMeter = 1 / 111320;
    $lngDegPerMeter = 1 / (111320 * max(0.01, cos(deg2rad($lat))));
    $toLocalMeters = fn($pLat, $pLng) => [
        ($pLng - $lng) / $lngDegPerMeter,
        ($pLat - $lat) / $latDegPerMeter,
    ];

    $minDist = INF;
    $n = count($geo);
    for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
        [$x1, $y1] = $toLocalMeters($geo[$j][0], $geo[$j][1]);
        [$x2, $y2] = $toLocalMeters($geo[$i][0], $geo[$i][1]);
        $dx = $x2 - $x1;
        $dy = $y2 - $y1;
        $lenSq = $dx * $dx + $dy * $dy;
        $t = $lenSq > 0 ? max(0.0, min(1.0, (-$x1 * $dx - $y1 * $dy) / $lenSq)) : 0.0;
        $projX = $x1 + $t * $dx;
        $projY = $y1 + $t * $dy;
        $minDist = min($minDist, sqrt($projX * $projX + $projY * $projY));
    }
    return $minDist;
}

/**
 * Distance in meters from a point to a line segment A-B — same local-flat-
 * meters-centered-on-the-query-point projection and point-to-segment math as
 * the inner loop of pointToPolygonDistanceMeters() above, just for one
 * standalone segment instead of walking a polygon's edges. Used by
 * computeMissionSectorCoverage() below to credit the ground actually walked
 * *between* two consecutive GPS pings, not just the ping points themselves.
 */
function pointToSegmentDistanceMeters(float $lat, float $lng, float $latA, float $lngA, float $latB, float $lngB): float {
    $latDegPerMeter = 1 / 111320;
    $lngDegPerMeter = 1 / (111320 * max(0.01, cos(deg2rad($lat))));
    $ax = ($lngA - $lng) / $lngDegPerMeter;
    $ay = ($latA - $lat) / $latDegPerMeter;
    $bx = ($lngB - $lng) / $lngDegPerMeter;
    $by = ($latB - $lat) / $latDegPerMeter;
    $dx = $bx - $ax;
    $dy = $by - $ay;
    $lenSq = $dx * $dx + $dy * $dy;
    $t = $lenSq > 0 ? max(0.0, min(1.0, (-$ax * $dx - $ay * $dy) / $lenSq)) : 0.0;
    $projX = $ax + $t * $dx;
    $projY = $ay + $t * $dy;
    return sqrt($projX * $projX + $projY * $projY);
}

/**
 * The two operator-tunable limits of the automatic sector grid, read from
 * Settings and clamped to the hard ceilings in config.php.
 *
 * Functions rather than inline getSetting() calls because each is read in two
 * places that must never disagree — the endpoint that enforces the limit and
 * the page that renders it into the tool the coordinator sees — and a clamp
 * copy-pasted into the second of those is a clamp that will eventually be
 * edited in only one.
 *
 * MAX_GRID_CELLS is a ceiling nobody can raise past, not the number an
 * operation gets: every sector of a mission ships in full on every 5-second
 * poll to every open Action Room tab, at a measured 739 bytes each, so the
 * 120 default is already ~87KB a tick. GRID_SECTOR_SIZE_MAX_M plays the same
 * role for the size slider, whose lower end stays at the 150m the tool itself
 * offers. The floors here (10 cells, 200m) exist so a mistyped 0 cannot leave
 * the tool unable to produce anything at all.
 */
function gridMaxCells(): int {
    return max(10, min(MAX_GRID_CELLS, (int) getSetting('war_room_grid_max_cells', '120') ?: 120));
}

/**
 * Which unit the Action Room prints areas in: 'auto' (each group of figures
 * shown together picks the finest unit any of them needs), 'mid' (στρέμματα
 * in Greek, hectares in English — the language file decides, see
 * common.unit_area_mid_divisor) or 'm2' (square metres, always).
 *
 * Read here and rendered into war-room.php as AREA_UNIT_PREFERENCE, which
 * areaTierForGroup() in assets/js/war-room-utils.js consults. Anything not
 * recognised falls back to 'auto' rather than erroring, the same way the
 * ticker-position setting does.
 */
function areaUnitSetting(): string {
    $value = (string) getSetting('war_room_area_unit', 'auto');
    return in_array($value, ['auto', 'mid', 'm2'], true) ? $value : 'auto';
}
function gridMaxSectorSizeM(): int {
    return max(200, min(GRID_SECTOR_SIZE_MAX_M, (int) getSetting('war_room_grid_max_size_m', '900') ?: 900));
}

/**
 * Divides a search-area polygon into a grid of equal cells, keeping only the
 * cells whose CENTER falls inside the polygon. The pure geometry half of
 * mission-sector.php's generate_grid action — no database, no permission, no
 * request state, so it is testable on its own (tests/SectorGridTest.php).
 *
 * Mirrored in JS as gridCellsForPolygon() (assets/js/war-room-utils.js) for
 * the live preview in war-room.php's grid tool. The two MUST agree cell for
 * cell: the preview's button promises "Create 13 sectors" and the server is
 * what actually creates them, so a disagreement is a lie told to a
 * coordinator mid-callout. Both are pinned to the same fixture file,
 * tests/fixtures/grid-cases.json, asserted by PHPUnit and by node --test —
 * that shared fixture, not discipline, is what keeps them from drifting.
 *
 * $sizeM is a REQUEST, not a result. An area 3637m wide cannot hold whole
 * 500m cells, so cols = ceil(3637/500) = 8 and the cells come out 455m wide
 * — always smaller than asked, never larger, so a whole number of them
 * covers the area. Callers are expected to show actual_w_m/actual_h_m back
 * to the admin before anything is created: cell size drives sweep time and
 * crew allocation, so a 30% deviation nobody was shown is a planning error,
 * not a rounding detail.
 *
 * Cells are cut out of the bounding box in DEGREES, as an even cols x rows
 * split, rather than by stepping meters and converting back each time. That
 * keeps neighbouring cells exactly edge to edge with no accumulated rounding
 * gap, and keeps the PHP and JS results identical instead of merely close.
 * The real danger in "a grid in degrees" — a degree of longitude in Crete
 * being ~91km against ~111km for latitude, so an equal-degree step yields
 * "squares" a fifth longer than they are wide — is handled where it actually
 * bites: cols and rows are each derived from the bounding box measured in
 * METERS, so the cells come out square-ish on the ground. Same flat local
 * projection (111320 m/deg, times cos(lat) for longitude) as every other
 * own center latitude. The conversion itself is WGS84 (metersPerDegreeLat()
 * / metersPerDegreeLng() above), not the flat 111320 this used to carry for
 * both, which reported cells 0.34% taller and 0.11% narrower than the ground
 * they cover — a bias, not noise, and always in the same direction.
 *
 * $geo is a ring of [lat, lng] pairs — mission_search_areas.geo's own shape,
 * not GeoJSON's [lng, lat], same as pointInPolygon() above. Cells come back
 * in reading order (row 0 northernmost, column 0 westernmost), each a
 * 4-point ring [NW, NE, SE, SW] rounded to 6 decimals: ~11cm, far past GPS
 * precision, and it stops the 5-second poll from carrying fifteen
 * meaningless digits per corner for every cell of every grid.
 *
 * A degenerate area (all points identical, or a sliver with no width) yields
 * a 1x1 grid whose single cell has no inside to be centered in, so 'kept'
 * comes back 0. That is a real outcome, not an error to raise here — the
 * caller decides what to tell the admin (see generate_grid's grid.no_cells).
 */
/**
 * Metres per degree of latitude, and per degree of longitude at that
 * latitude, on the WGS84 ellipsoid. PHP twin of metersPerDegreeLat() /
 * metersPerDegreeLng() in assets/js/war-room-utils.js — see that file for
 * why the flat 111320 they replaced was a systematic 0.08%-0.24% overstatement
 * everywhere in Greece, worst in Crete.
 *
 * Used by buildSectorGridCells() below, which must agree with its JS twin
 * cell for cell (tests/fixtures/grid-cases.json), so the two have to be
 * changed together or CI fails.
 */
define('WGS84_A', 6378137.0);
define('WGS84_E2', 0.00669437999014);

function metersPerDegreeLat(float $latDeg): float {
    $sinLat = sin(deg2rad($latDeg));
    return deg2rad(1.0) * (WGS84_A * (1 - WGS84_E2)) / pow(1 - WGS84_E2 * $sinLat * $sinLat, 1.5);
}

function metersPerDegreeLng(float $latDeg): float {
    $sinLat = sin(deg2rad($latDeg));
    // cos clamped exactly as before, so a polygon at the pole cannot make
    // zero-width grid columns.
    $cosLat = max(0.01, cos(deg2rad($latDeg)));
    return deg2rad(1.0) * (WGS84_A / sqrt(1 - WGS84_E2 * $sinLat * $sinLat)) * $cosLat;
}

function buildSectorGridCells(array $geo, int $sizeM): array {
    $sizeM = max(GRID_SECTOR_SIZE_MIN_M, min(GRID_SECTOR_SIZE_MAX_M, $sizeM));

    $lats = array_map(fn($pt) => (float) $pt[0], $geo);
    $lngs = array_map(fn($pt) => (float) $pt[1], $geo);
    $minLat = min($lats);
    $maxLat = max($lats);
    $minLng = min($lngs);
    $maxLng = max($lngs);

    $centerLat = ($minLat + $maxLat) / 2;
    $mPerDegLat = metersPerDegreeLat($centerLat);
    $mPerDegLng = metersPerDegreeLng($centerLat);

    $bboxH = ($maxLat - $minLat) * $mPerDegLat;
    $bboxW = ($maxLng - $minLng) * $mPerDegLng;

    // The ratio is rounded before ceil() on both sides. It is the one place
    // where a last-bit difference between PHP's and JS's cos() would not stay
    // microscopic: on an area whose width divides exactly by the cell size,
    // 8.000000000000001 and 8.0 ceil to 9 and 8 — a whole extra column of
    // sectors on one side and not the other. 9 decimals of a ratio is half a
    // micron of ground; nothing real survives down there to be lost.
    $cols = max(1, (int) ceil(round($bboxW / $sizeM, 9)));
    $rows = max(1, (int) ceil(round($bboxH / $sizeM, 9)));

    $latStep = ($maxLat - $minLat) / $rows;
    $lngStep = ($maxLng - $minLng) / $cols;

    $cells = [];
    $dropped = [];
    for ($r = 0; $r < $rows; $r++) {
        $latTop = $maxLat - $r * $latStep;
        $latBottom = $maxLat - ($r + 1) * $latStep;
        for ($c = 0; $c < $cols; $c++) {
            $lngLeft = $minLng + $c * $lngStep;
            $lngRight = $minLng + ($c + 1) * $lngStep;
            // The center is the whole keep-or-drop rule: a cell more than
            // half outside the search area is not worth sending a crew to,
            // and one more than half inside is. Cheap, and it never leaves a
            // hole in the middle of the area the way an "entirely inside"
            // test would along every edge.
            $cell = [
                [round($latTop, 6), round($lngLeft, 6)],
                [round($latTop, 6), round($lngRight, 6)],
                [round($latBottom, 6), round($lngRight, 6)],
                [round($latBottom, 6), round($lngLeft, 6)],
            ];
            if (pointInPolygon(($latTop + $latBottom) / 2, ($lngLeft + $lngRight) / 2, $geo)) {
                $cells[] = $cell;
            } else {
                $dropped[] = $cell;
            }
        }
    }

    return [
        'cells'       => $cells,
        'dropped'     => $dropped,
        'cols'        => $cols,
        'rows'        => $rows,
        'total'       => $cols * $rows,
        'kept'        => count($cells),
        'requested_m' => $sizeM,
        'actual_w_m'  => round($bboxW / $cols, 1),
        'actual_h_m'  => round($bboxH / $rows, 1),
    ];
}

/**
 * Spatial index over the pings and walked segments a coverage sweep has to
 * test cells against, bucketed at sweep-radius resolution.
 *
 * Both coverage functions below grid-sample an area and ask, per cell, "did
 * anyone walk within $sweepRadiusMeters of here". They used to answer that by
 * scanning every ping and then every segment, breaking on the first hit — so a
 * COVERED cell was cheap but an UNCOVERED one paid for the full list twice,
 * and on a large, thinly-walked area almost every cell is uncovered. Measured
 * on the demo mission's 7km sector with only 180 pings: the grid itself and
 * pointInPolygon() together cost 0,13s, the covered-check 25s.
 *
 * Shared by both rather than copied into each, so the two can never drift into
 * disagreeing about what "covered" means — the same reasoning the activity-feed
 * scope fragments further up this file are shared for.
 */
function coverageSpatialIndex(array $points, array $segments, float $bucketLat, float $bucketLng): array {
    $key = fn($la, $ln) => ((int) floor($la / $bucketLat)) . ':' . ((int) floor($ln / $bucketLng));

    $pointBuckets = [];
    foreach ($points as $p) {
        $pointBuckets[$key($p['lat'], $p['lng'])][] = $p;
    }

    // A segment is registered in every bucket it passes through, not just the
    // two holding its endpoints — a walked leg crossing a cell must be visible
    // to that cell even when both of its ends are far away. Sampled at half a
    // bucket so none in between is stepped over.
    $segmentBuckets = [];
    foreach ($segments as $s) {
        $spanLat = $s['lat2'] - $s['lat1'];
        $spanLng = $s['lng2'] - $s['lng1'];
        $samples = (int) max(1, ceil(2 * max(abs($spanLat) / $bucketLat, abs($spanLng) / $bucketLng)));
        $seenKeys = [];
        for ($k = 0; $k <= $samples; $k++) {
            $bucket = $key($s['lat1'] + $spanLat * $k / $samples, $s['lng1'] + $spanLng * $k / $samples);
            if (!isset($seenKeys[$bucket])) {
                $seenKeys[$bucket] = true;
                $segmentBuckets[$bucket][] = $s;
            }
        }
    }

    return ['points' => $pointBuckets, 'segments' => $segmentBuckets];
}

/**
 * Was this cell walked? Answers from coverageSpatialIndex() above, looking only
 * at the buckets that could possibly hold something in range.
 *
 * The neighbourhood is +/-2 buckets, not +/-1: one bucket covers the sweep
 * radius itself, the second absorbs the half-bucket sampling error in that
 * function's segment rasterisation. Anything genuinely within reach of this
 * cell is therefore guaranteed to sit in one of the buckets examined here.
 */
function coverageCellIsCovered(float $cellLat, float $cellLng, array $index, float $bucketLat, float $bucketLng, float $sweepRadiusMeters): bool {
    $ci = (int) floor($cellLat / $bucketLat);
    $cj = (int) floor($cellLng / $bucketLng);

    for ($di = -2; $di <= 2; $di++) {
        for ($dj = -2; $dj <= 2; $dj++) {
            $bucket = ($ci + $di) . ':' . ($cj + $dj);
            foreach ($index['points'][$bucket] ?? [] as $p) {
                if (gpsDistanceMeters($cellLat, $cellLng, $p['lat'], $p['lng']) <= $sweepRadiusMeters) {
                    return true;
                }
            }
            foreach ($index['segments'][$bucket] ?? [] as $s) {
                if (pointToSegmentDistanceMeters($cellLat, $cellLng, $s['lat1'], $s['lng1'], $s['lat2'], $s['lng2']) <= $sweepRadiusMeters) {
                    return true;
                }
            }
        }
    }

    return false;
}

/**
 * Verified Coverage — ground-truth swept-area estimate for search sectors,
 * entirely independent of the self-reported status a team leader taps.
 * Grid-samples each sector polygon at $gridStepMeters resolution: a cell
 * counts toward the denominator when its center is inside the polygon
 * (pointInPolygon() above), and counts as covered when it's within
 * $sweepRadiusMeters of either a trusted ping (gpsDistanceMeters()) or a
 * "walked segment" between two of the same person's consecutive trusted
 * pings (pointToSegmentDistanceMeters() above — see below). This is a
 * deliberate approximation (no polygon clipping/union math) rather than
 * exact geometry — nothing in this codebase uses a GIS library or DB
 * spatial functions (the latter has already bitten this repo once via
 * MariaDB-vs-MySQL syntax gaps, see schema.sql history), and approximate is
 * plenty for a decision-support signal.
 *
 * Walked segments exist because a team is several people but usually one
 * phone actively pinging, and that phone may only ping every 20-30s while
 * its carrier keeps moving — crediting only the exact ping points
 * under-counted the ground genuinely swept between them. Consecutive pings
 * from the SAME user_id within $maxWalkGapSeconds become a segment; a wider
 * gap (lost signal, a different sector entirely) is deliberately left as
 * two disconnected points instead of a straight line through ground nobody
 * actually walked — mission owner's explicit call on that cutoff.
 *
 * Ping attribution is purely spatial + mission-wide, NOT filtered by which
 * team is currently assigned to the sector: mission_search_sectors.team_id
 * has no time-versioned history (a mid-mission reassignment doesn't always
 * write a mission_sector_status_log row — see the `assign` action), so
 * reconstructing "was the *assigned* team here" would have real gaps. "Was a
 * GPS-tracked person physically here" is both simpler and more honest.
 *
 * $sweepRadiusMeters is a starting default, not a tuned value — real
 * visibility differs hugely between open field and dense forest. Left as a
 * local constant rather than an admin-facing setting until real usage shows
 * the default is wrong.
 */
function computeMissionSectorCoverage(int $missionId): array {
    // $gridStepMeters is derived per sector below, not fixed. It used to be a
    // flat 10m on the stated assumption that "hand-drawn sectors are naturally
    // small" — which does not hold. The demo mission's own search sector is
    // 7km x 7km, which at 10m resolution is 491.118 grid cells, each of them
    // running pointInPolygon() and, when not covered, a linear scan of every
    // ping and every walked segment. That endpoint did not merely get slow: it
    // ran into PHP's max_execution_time and returned a 500, on 181 pings — so
    // this was never a ping-volume problem, and no amount of query tuning
    // would have touched it. When it did finish, it was 112 seconds and a
    // 16MB response of gap cells.
    $sweepRadiusMeters = 25;
    $trustedAccuracyMeters = 100; // same "trustworthy" cutoff checkRestrictedAreaBreach() already uses
    $maxWalkGapSeconds = 1800; // 30 min — see doc comment above

    $sectors = dbFetchAll("SELECT id, geo FROM mission_search_sectors WHERE mission_id = ?", [$missionId]);
    if (empty($sectors)) {
        return [];
    }

    $pingRows = dbFetchAll(
        "SELECT vp.user_id, vp.lat, vp.lng, vp.created_at FROM volunteer_pings vp
         JOIN shifts s ON s.id = vp.shift_id
         WHERE s.mission_id = ? AND (vp.accuracy_meters IS NULL OR vp.accuracy_meters <= ?)
         ORDER BY vp.user_id, vp.created_at",
        [$missionId, $trustedAccuracyMeters]
    );

    $points = [];
    $segments = [];
    $prevByUser = [];
    foreach ($pingRows as $row) {
        $uid = (int) $row['user_id'];
        $lat = (float) $row['lat'];
        $lng = (float) $row['lng'];
        $points[] = ['lat' => $lat, 'lng' => $lng];
        if (isset($prevByUser[$uid])) {
            $gapSeconds = strtotime($row['created_at']) - strtotime($prevByUser[$uid]['created_at']);
            if ($gapSeconds >= 0 && $gapSeconds <= $maxWalkGapSeconds) {
                $segments[] = [
                    'lat1' => $prevByUser[$uid]['lat'], 'lng1' => $prevByUser[$uid]['lng'],
                    'lat2' => $lat, 'lng2' => $lng,
                ];
            }
        }
        $prevByUser[$uid] = ['lat' => $lat, 'lng' => $lng, 'created_at' => $row['created_at']];
    }

    $result = [];
    foreach ($sectors as $sector) {
        $geo = json_decode($sector['geo'], true);
        if (!is_array($geo) || count($geo) < 3) {
            continue;
        }

        $lats = array_column($geo, 0);
        $lngs = array_column($geo, 1);
        $minLat = min($lats);
        $maxLat = max($lats);
        $minLng = min($lngs);
        $maxLng = max($lngs);

        // meters -> degrees, latitude-adjusted for longitude — same
        // flat-Cartesian tradeoff pointInPolygon()'s own doc comment
        // already accepts as fine at mission scale.
        $midLat = ($minLat + $maxLat) / 2;
        $latDegPerMeter = 1 / 111320;
        $lngDegPerMeter = 1 / (111320 * max(0.01, cos(deg2rad($midLat))));

        $bufferLat = $sweepRadiusMeters * $latDegPerMeter;
        $bufferLng = $sweepRadiusMeters * $lngDegPerMeter;
        $bboxMinLat = $minLat - $bufferLat;
        $bboxMaxLat = $maxLat + $bufferLat;
        $bboxMinLng = $minLng - $bufferLng;
        $bboxMaxLng = $maxLng + $bufferLng;

        // Cheap numeric pre-filter before any Haversine/segment calls below
        // — a mission-wide ping pool can be a few thousand rows, most of
        // them nowhere near this one sector.
        $inBbox = fn($lat, $lng) =>
            $lat >= $bboxMinLat && $lat <= $bboxMaxLat && $lng >= $bboxMinLng && $lng <= $bboxMaxLng;

        $nearbyPoints = array_values(array_filter($points, fn($p) => $inBbox($p['lat'], $p['lng'])));
        // A segment counts as nearby if either endpoint does — cheap and
        // consistent with the point pre-filter above; a segment that clips
        // through the bbox with both endpoints just outside it is a real
        // miss, but rare enough (needs a walked leg longer than roughly
        // 2x the sweep radius landing exactly either side) not to be worth
        // a heavier segment-bbox-intersection test here.
        $nearbySegments = array_values(array_filter($segments, fn($s) =>
            $inBbox($s['lat1'], $s['lng1']) || $inBbox($s['lat2'], $s['lng2'])
        ));

        // Bound the cell count instead of the sector size: at most 150 steps
        // along each axis, so any sector costs at most ~22.500 cells however
        // large an admin drew it. Small sectors keep the full 10m resolution;
        // only ones too big to grid finely get a coarser cell. This mirrors
        // exactly what computeMissionRingCoverage() below already does
        // ($maxRadius / 150) — rings were hardened against this and sectors
        // were left on the "naturally small" assumption.
        $sectorHeightMeters = ($maxLat - $minLat) / $latDegPerMeter;
        $sectorWidthMeters  = ($maxLng - $minLng) / $lngDegPerMeter;
        $gridStepMeters = max(10, (int) round(max($sectorHeightMeters, $sectorWidthMeters) / 150));

        $stepLat = $gridStepMeters * $latDegPerMeter;
        $stepLng = $gridStepMeters * $lngDegPerMeter;

        $totalCells = 0;
        $coveredCells = 0;
        $gapCells = [];

        $bucketLat = $sweepRadiusMeters * $latDegPerMeter;
        $bucketLng = $sweepRadiusMeters * $lngDegPerMeter;
        $coverageIndex = coverageSpatialIndex($nearbyPoints, $nearbySegments, $bucketLat, $bucketLng);

        // Always grid-sample, even with zero nearby pings — a completely
        // unwalked sector must still resolve to a real 0%, not be skipped.
        for ($lat = $minLat; $lat <= $maxLat; $lat += $stepLat) {
            for ($lng = $minLng; $lng <= $maxLng; $lng += $stepLng) {
                $cellLat = $lat + $stepLat / 2;
                $cellLng = $lng + $stepLng / 2;
                if (!pointInPolygon($cellLat, $cellLng, $geo)) {
                    continue;
                }
                $totalCells++;

                $covered = coverageCellIsCovered($cellLat, $cellLng, $coverageIndex, $bucketLat, $bucketLng, $sweepRadiusMeters);
                if ($covered) {
                    $coveredCells++;
                } else {
                    $gapCells[] = [
                        round($lat, 7), round($lng, 7),
                        round($lat + $stepLat, 7), round($lng + $stepLng, 7),
                    ];
                }
            }
        }

        $result[(int) $sector['id']] = [
            'percent'   => $totalCells > 0 ? (int) round($coveredCells / $totalCells * 100) : 0,
            'gap_cells' => $gapCells,
        ];
    }

    return $result;
}

/**
 * Same "Verified Coverage" idea as computeMissionSectorCoverage() above, but
 * for the 4 concentric LPB search rings (includes/lpb-rings.php) instead of
 * hand-drawn sector polygons. Unlike sectors, rings have no DB row of their
 * own — they're derived purely from mission_missing_persons + LPB_RING_TABLE,
 * the same way war-room.php's renderSearchRingsLayer() computes them
 * client-side — so this function re-derives the same center+radii itself and
 * keys its result by ring index (0-3, matching the existing pct=[25,50,75,95]
 * ordering already used client-side) rather than a database id.
 *
 * Deliberately simpler than the sector version in two ways:
 *  - Single grid pass over the LARGEST ring's bounding box, bucketing each
 *    cell's covered/not-covered status into every ring whose radius contains
 *    it (rings are concentric — ring i's disc is a strict subset of ring
 *    i+1's), rather than 4 independent passes. Cuts the dominant cost
 *    (the per-cell nearby-ping scan) roughly 4x.
 *  - Flat-Cartesian distance instead of gpsDistanceMeters()'s Haversine for
 *    the ring-membership test itself (not the covered-check, which still
 *    uses the real Haversine/segment helpers) — cheaper, and the same
 *    approximation pointToSegmentDistanceMeters() already makes at this
 *    scale.
 *  - No gap_cells: wasn't asked for, and a multi-km-radius disc's gap set
 *    would be "almost the whole grid" — a real payload/SVG-node risk sector
 *    coverage never faces since hand-drawn sectors are naturally small.
 *
 * RING_COVERAGE_MAX_RADIUS_METERS (includes/lpb-rings.php) skips computing a
 * ring entirely once its radius exceeds that cap — grid cell count is
 * already bounded regardless of radius (see $gridStepMeters below), but the
 * covered-check cost scales with how many volunteer_pings fall inside the
 * ring's bbox, and a ring's bbox — unlike a hand-drawn sector's — has zero
 * admin discretion capping how large it gets.
 */
function computeMissionRingCoverage(int $missionId): array {
    $person = loadMissingPersonForMission($missionId);
    if (!$person || $person['last_seen_lat'] === null || $person['last_seen_lng'] === null) {
        return [];
    }
    $radii = LPB_RING_TABLE[$person['subject_category'] ?? ''] ?? null;
    if (!$radii) {
        return [];
    }

    $centerLat = $person['last_seen_lat'];
    $centerLng = $person['last_seen_lng'];
    $maxRadius = max($radii);
    if ($maxRadius > RING_COVERAGE_MAX_RADIUS_METERS) {
        // Still compute the rings that DO fit under the cap, if any —
        // e.g. a category whose 25%/50% rings are small but 95% is huge
        // shouldn't lose its inner badges just because the outer one is
        // too expensive to grid-sample.
        $maxRadius = RING_COVERAGE_MAX_RADIUS_METERS;
    }

    $sweepRadiusMeters = 25;
    $trustedAccuracyMeters = 100;
    $maxWalkGapSeconds = 1800;
    $gridStepMeters = max(10, (int) round($maxRadius / 150));

    $pingRows = dbFetchAll(
        "SELECT vp.user_id, vp.lat, vp.lng, vp.created_at FROM volunteer_pings vp
         JOIN shifts s ON s.id = vp.shift_id
         WHERE s.mission_id = ? AND (vp.accuracy_meters IS NULL OR vp.accuracy_meters <= ?)
         ORDER BY vp.user_id, vp.created_at",
        [$missionId, $trustedAccuracyMeters]
    );

    $points = [];
    $segments = [];
    $prevByUser = [];
    foreach ($pingRows as $row) {
        $uid = (int) $row['user_id'];
        $lat = (float) $row['lat'];
        $lng = (float) $row['lng'];
        $points[] = ['lat' => $lat, 'lng' => $lng];
        if (isset($prevByUser[$uid])) {
            $gapSeconds = strtotime($row['created_at']) - strtotime($prevByUser[$uid]['created_at']);
            if ($gapSeconds >= 0 && $gapSeconds <= $maxWalkGapSeconds) {
                $segments[] = [
                    'lat1' => $prevByUser[$uid]['lat'], 'lng1' => $prevByUser[$uid]['lng'],
                    'lat2' => $lat, 'lng2' => $lng,
                ];
            }
        }
        $prevByUser[$uid] = ['lat' => $lat, 'lng' => $lng, 'created_at' => $row['created_at']];
    }

    $latDegPerMeter = 1 / 111320;
    $lngDegPerMeter = 1 / (111320 * max(0.01, cos(deg2rad($centerLat))));

    $bufferLat = $sweepRadiusMeters * $latDegPerMeter;
    $bufferLng = $sweepRadiusMeters * $lngDegPerMeter;
    $radiusLat = $maxRadius * $latDegPerMeter;
    $radiusLng = $maxRadius * $lngDegPerMeter;
    $bboxMinLat = $centerLat - $radiusLat - $bufferLat;
    $bboxMaxLat = $centerLat + $radiusLat + $bufferLat;
    $bboxMinLng = $centerLng - $radiusLng - $bufferLng;
    $bboxMaxLng = $centerLng + $radiusLng + $bufferLng;

    $inBbox = fn($lat, $lng) =>
        $lat >= $bboxMinLat && $lat <= $bboxMaxLat && $lng >= $bboxMinLng && $lng <= $bboxMaxLng;

    $nearbyPoints = array_values(array_filter($points, fn($p) => $inBbox($p['lat'], $p['lng'])));
    $nearbySegments = array_values(array_filter($segments, fn($s) =>
        $inBbox($s['lat1'], $s['lng1']) || $inBbox($s['lat2'], $s['lng2'])
    ));

    $stepLat = $gridStepMeters * $latDegPerMeter;
    $stepLng = $gridStepMeters * $lngDegPerMeter;

    // Same spatial index the sector sweep uses — this loop had the identical
    // full-list-scan-per-uncovered-cell cost, and with four concentric rings
    // gridded in one pass it was the slower of the two: 20,5s of the coverage
    // endpoint's response on the demo mission, against 0,38s for the sectors.
    $bucketLat = $sweepRadiusMeters * $latDegPerMeter;
    $bucketLng = $sweepRadiusMeters * $lngDegPerMeter;
    $coverageIndex = coverageSpatialIndex($nearbyPoints, $nearbySegments, $bucketLat, $bucketLng);

    $totalByRing = [0, 0, 0, 0];
    $coveredByRing = [0, 0, 0, 0];

    for ($lat = $centerLat - $radiusLat; $lat <= $centerLat + $radiusLat; $lat += $stepLat) {
        for ($lng = $centerLng - $radiusLng; $lng <= $centerLng + $radiusLng; $lng += $stepLng) {
            $cellLat = $lat + $stepLat / 2;
            $cellLng = $lng + $stepLng / 2;

            // Flat-Cartesian distance-from-center in meters — cheap
            // membership test, evaluated before any Haversine/segment call.
            $dx = ($cellLng - $centerLng) / $lngDegPerMeter;
            $dy = ($cellLat - $centerLat) / $latDegPerMeter;
            $distMeters = sqrt($dx * $dx + $dy * $dy);
            if ($distMeters > $maxRadius) {
                continue;
            }

            $covered = coverageCellIsCovered($cellLat, $cellLng, $coverageIndex, $bucketLat, $bucketLng, $sweepRadiusMeters);

            // Bucket this one cell's already-computed status into every
            // ring whose radius contains it — rings are concentric, so a
            // cell inside the 25% ring is also inside 50%/75%/95%.
            for ($i = 0; $i < 4; $i++) {
                if ($radii[$i] > RING_COVERAGE_MAX_RADIUS_METERS || $distMeters > $radii[$i]) {
                    continue;
                }
                $totalByRing[$i]++;
                if ($covered) {
                    $coveredByRing[$i]++;
                }
            }
        }
    }

    $result = [];
    for ($i = 0; $i < 4; $i++) {
        if ($radii[$i] > RING_COVERAGE_MAX_RADIUS_METERS) {
            continue; // no percent for a ring too large to have been sampled
        }
        $result[$i] = [
            'percent' => $totalByRing[$i] > 0 ? (int) round($coveredByRing[$i] / $totalByRing[$i] * 100) : 0,
        ];
    }
    return $result;
}

/**
 * War Room: each team's current position, taken as the most recent ping
 * among its current members — same "team is one unit" convention and same
 * per-team join shape as computeDispatchEta() above, just without that
 * function's OSRM/cache machinery (this is plain Haversine, cheap enough to
 * run fresh every poll tick for the handful of teams a mission typically
 * has). Teams with no ping yet are silently omitted, not returned with a
 * null position — callers (Nearby Teams, Team Distances) both treat
 * "not in this array" as the correct empty state.
 */
function loadTeamPositionsForMission(int $missionId, array $continuousFieldMinutesByVolunteerId = []): array {
    $teams = dbFetchAll(
        "SELECT id, codename, team_number, color FROM mission_teams WHERE mission_id = ? ORDER BY codename",
        [$missionId]
    );

    // Every team's most recent ping in one query, not one query per team.
    // The per-team version read and sorted every ping belonging to that
    // team's members to pick a single row, so it cost O(pings) and ran once
    // per team on every 5s Action Room poll: 34ms x 6 teams = 204ms on a
    // mission with 100.000 pings.
    //
    // The derived table is the same loose-index-scan shape $loadPins uses in
    // war-room.php (one index entry per volunteer, not one per ping), and the
    // mission's shift ids are again passed as an explicit IN list because a
    // join or subquery there loses that index. The global newest ping of a
    // team is necessarily the latest ping of whoever made it, so ranking each
    // member's latest ping picks the identical row the old ORDER BY ... LIMIT 1
    // did — newest first, first row per team wins.
    $shiftIds = array_column(
        dbFetchAll("SELECT id FROM shifts WHERE mission_id = ?", [$missionId]),
        'id'
    ) ?: [0];
    $shiftPlaceholders = implode(',', array_fill(0, count($shiftIds), '?'));
    $pingByTeamId = [];
    foreach (dbFetchAll(
        "SELECT mtm.team_id, vp.user_id, vp.lat, vp.lng, vp.created_at, vp.battery_level
         FROM (SELECT user_id, shift_id, MAX(id) AS max_id
                 FROM volunteer_pings
                WHERE shift_id IN ({$shiftPlaceholders})
                GROUP BY user_id, shift_id) l
         JOIN volunteer_pings vp ON vp.id = l.max_id
         JOIN mission_team_members mtm ON mtm.user_id = vp.user_id AND mtm.mission_id = ?
         ORDER BY vp.created_at DESC",
        array_merge($shiftIds, [$missionId])
    ) as $pingRow) {
        $rowTeamId = (int) $pingRow['team_id'];
        if (!isset($pingByTeamId[$rowTeamId])) {
            $pingByTeamId[$rowTeamId] = $pingRow;
        }
    }

    $positions = [];
    foreach ($teams as $team) {
        $ping = $pingByTeamId[(int) $team['id']] ?? null;
        if (!$ping) {
            continue;
        }
        $positions[] = [
            'team_id' => (int) $team['id'],
            'label' => teamLabel($team['codename'], $team['team_number']),
            'color' => $team['color'],
            'lat' => (float) $ping['lat'],
            'lng' => (float) $ping['lng'],
            // Includes the date — same reasoning as the live pin popup's own
            // 'time' field (war-room.php's $loadPins): a team's last-known
            // ping can be from a different day than "now".
            'time' => date('H:i d/m/Y', strtotime($ping['created_at'])),
            'is_stale' => (time() - strtotime($ping['created_at'])) > warRoomPingStaleThresholdSeconds(),
            // Battery of whoever on the team most recently reported in — a
            // reasonable proxy for "is this team's tracking about to go dark".
            'battery_level' => $ping['battery_level'] !== null ? (int) $ping['battery_level'] : null,
            // Fatigue of whoever's ping this position is based on — same
            // "reasonable proxy, not a guarantee" tradeoff already accepted
            // for battery_level just above.
            'continuous_field_minutes' => $continuousFieldMinutesByVolunteerId[(int) $ping['user_id']] ?? null,
        ];
    }
    return $positions;
}

/**
 * War Room: every pairwise distance between teams that currently have a
 * position (i<j, not the full N² — a mission with 4 teams has 6 pairs, not
 * 16). Sorted nearest-first since the closest pair is usually the most
 * operationally relevant ("are these two close enough to support each
 * other"). Feeds the Team Distances section next to the Teams panel.
 */
function computeTeamDistanceMatrix(array $teamPositions): array {
    $matrix = [];
    $count = count($teamPositions);
    for ($i = 0; $i < $count; $i++) {
        for ($j = $i + 1; $j < $count; $j++) {
            $a = $teamPositions[$i];
            $b = $teamPositions[$j];
            $aBat = $a['battery_level'] ?? null;
            $bBat = $b['battery_level'] ?? null;
            $aFatigue = $a['continuous_field_minutes'] ?? null;
            $bFatigue = $b['continuous_field_minutes'] ?? null;
            $matrix[] = [
                'a_label' => $a['label'], 'a_color' => $a['color'],
                'b_label' => $b['label'], 'b_color' => $b['color'],
                'distance_m' => gpsDistanceMeters($a['lat'], $a['lng'], $b['lat'], $b['lng']),
                'is_stale' => $a['is_stale'] || $b['is_stale'],
                // Worse (lower) of the two sides wins — mirrors the is_stale
                // OR-rollup just above.
                'battery_level' => ($aBat !== null && $bBat !== null) ? min($aBat, $bBat) : ($aBat ?? $bBat),
                // Worse (HIGHER) of the two sides wins — opposite of
                // battery_level above, since for fatigue more minutes is worse.
                'continuous_field_minutes' => ($aFatigue !== null && $bFatigue !== null) ? max($aFatigue, $bFatigue) : ($aFatigue ?? $bFatigue),
            ];
        }
    }
    usort($matrix, fn($x, $y) => $x['distance_m'] <=> $y['distance_m']);
    return $matrix;
}

/**
 * War Room: response-time computation shared by the live report modal
 * (mission-response-report.php) and the archival print export
 * (mission-report-print.php) — merges the generic mission_orders/
 * mission_order_recipients system with dispatch's native ack/receipt
 * tables into one normalized detail list, same merge-in-PHP technique
 * mission-history.php uses. Returns raw, unformatted datetime strings
 * (plus already-computed *_minutes deltas) — callers apply their own
 * date() format, since the live report and the archival export intentionally
 * format timestamps differently (compact vs. with year).
 */
function computeMissionResponseReport(int $missionId, ?string $lang = null): array {
    // $lang defaults to null (-> DEFAULT_LANGUAGE, i.e. today's Greek text) so the
    // two out-of-scope callers (mission-stats.php, mission-report-print.php) are
    // completely unaffected; only mission-response-report.php passes the viewer's
    // real language. See includes/i18n.php's note on SHORTAGE_TYPE_LABELS for why
    // this function must not change shape for those two callers.
    $lang = $lang ?? DEFAULT_LANGUAGE;
    $typeMeta = [
        'location' => t('report.type_location', [], $lang),
        'photo'    => t('report.type_photo', [], $lang),
        'video'    => t('report.type_video', [], $lang),
        'task'     => t('report.type_task', [], $lang),
        'message'  => t('report.type_message', [], $lang),
        'dispatch' => t('report.type_dispatch', [], $lang),
        'return_to_base' => t('report.type_return_to_base', [], $lang),
        'route'    => t('report.type_route', [], $lang),
        'charge_phone' => t('report.type_charge_phone', [], $lang),
        'speak'    => t('report.type_speak', [], $lang),
    ];

    $teamLabels = [];
    foreach (dbFetchAll("SELECT id, codename, team_number FROM mission_teams WHERE mission_id = ?", [$missionId]) as $t) {
        $teamLabels[(int) $t['id']] = teamLabel($t['codename'], $t['team_number']);
    }

    $detail = [];

    // ── location/photo/video/task/message orders ────────────────────────────────
    $orderRows = dbFetchAll(
        "SELECT o.order_type, o.task_text, o.created_at AS sent_at, r.team_id, r.user_id, u.name AS user_name,
                r.acknowledged_at, r.fulfilled_at
         FROM mission_order_recipients r
         JOIN mission_orders o ON o.id = r.order_id
         JOIN users u ON u.id = r.user_id
         WHERE o.mission_id = ?
         ORDER BY o.created_at DESC",
        [$missionId]
    );
    foreach ($orderRows as $row) {
        $teamId = $row['team_id'] ? (int) $row['team_id'] : null;
        $detail[] = [
            'type_label'  => $typeMeta[$row['order_type']] ?? $row['order_type'],
            'order_type'  => $row['order_type'],
            'team_id'     => $teamId,
            'team_label'  => $teamId ? ($teamLabels[$teamId] ?? '—') : t('history.no_team_capitalized', [], $lang),
            'user_id'     => (int) $row['user_id'],
            'user_name'   => $row['user_name'],
            'label'       => in_array($row['order_type'], ['task', 'speak', 'message', 'route', 'charge_phone'], true) ? $row['task_text'] : null,
            'sent_at'     => $row['sent_at'],
            'ack_at'      => $row['acknowledged_at'],
            'fulfill_at'  => $row['fulfilled_at'],
        ];
    }

    // ── dispatch orders ──────────────────────────────────────────────────────────
    $dispatchRows = dbFetchAll(
        "SELECT id, label, created_at AS sent_at, team_id FROM mission_dispatch_points WHERE mission_id = ?",
        [$missionId]
    );
    if (!empty($dispatchRows)) {
        $dispatchById = [];
        foreach ($dispatchRows as $d) {
            $dispatchById[(int) $d['id']] = $d;
        }
        $dispatchIds = array_keys($dispatchById);
        $placeholders = implode(',', array_fill(0, count($dispatchIds), '?'));

        $byDispatchUser = [];
        foreach (dbFetchAll(
            "SELECT r.dispatch_id, r.user_id, r.team_id, r.created_at, u.name AS user_name
             FROM mission_dispatch_receipts r JOIN users u ON u.id = r.user_id
             WHERE r.dispatch_id IN ($placeholders)",
            $dispatchIds
        ) as $r) {
            $key = $r['dispatch_id'] . ':' . $r['user_id'];
            $byDispatchUser[$key] = [
                'dispatch_id' => (int) $r['dispatch_id'],
                'user_id'     => (int) $r['user_id'],
                'user_name'   => $r['user_name'],
                'team_id'     => $r['team_id'] ? (int) $r['team_id'] : null,
                'ack_at'      => $r['created_at'],
                'fulfill_at'  => null,
            ];
        }
        foreach (dbFetchAll(
            "SELECT a.dispatch_id, a.user_id, a.team_id, a.created_at, u.name AS user_name
             FROM mission_dispatch_acks a JOIN users u ON u.id = a.user_id
             WHERE a.dispatch_id IN ($placeholders)",
            $dispatchIds
        ) as $a) {
            $key = $a['dispatch_id'] . ':' . $a['user_id'];
            if (!isset($byDispatchUser[$key])) {
                $byDispatchUser[$key] = [
                    'dispatch_id' => (int) $a['dispatch_id'],
                    'user_id'     => (int) $a['user_id'],
                    'user_name'   => $a['user_name'],
                    'team_id'     => $a['team_id'] ? (int) $a['team_id'] : null,
                    'ack_at'      => null,
                    'fulfill_at'  => null,
                ];
            }
            $byDispatchUser[$key]['fulfill_at'] = $a['created_at'];
            if (!$byDispatchUser[$key]['team_id'] && $a['team_id']) {
                $byDispatchUser[$key]['team_id'] = (int) $a['team_id'];
            }
        }

        foreach ($byDispatchUser as $entry) {
            $d = $dispatchById[$entry['dispatch_id']];
            $detail[] = [
                'type_label' => $typeMeta['dispatch'],
                'order_type' => 'dispatch',
                'team_id'    => $entry['team_id'],
                'team_label' => $entry['team_id'] ? ($teamLabels[$entry['team_id']] ?? '—') : t('history.no_team_capitalized', [], $lang),
                'user_id'    => $entry['user_id'],
                'user_name'  => $entry['user_name'],
                'label'      => $d['label'],
                'sent_at'    => $d['sent_at'],
                'ack_at'     => $entry['ack_at'],
                'fulfill_at' => $entry['fulfill_at'],
            ];
        }
    }

    // ── minute deltas + sort ─────────────────────────────────────────────────────
    foreach ($detail as &$row) {
        $row['ack_minutes'] = reportMinutesBetween($row['sent_at'], $row['ack_at']);
        $row['fulfill_minutes'] = reportMinutesBetween($row['sent_at'], $row['fulfill_at']);
    }
    unset($row);
    usort($detail, fn($a, $b) => strtotime($b['sent_at']) <=> strtotime($a['sent_at']));

    // ── per-team summary, computed from the same $detail rows ──────────────────
    $byTeam = [];
    foreach ($detail as $row) {
        $label = $row['team_label'];
        if (!isset($byTeam[$label])) {
            $byTeam[$label] = ['count' => 0, 'ack_count' => 0, 'fulfill_count' => 0, 'ack_sum' => 0.0, 'fulfill_sum' => 0.0];
        }
        $byTeam[$label]['count']++;
        if ($row['ack_minutes'] !== null) {
            $byTeam[$label]['ack_count']++;
            $byTeam[$label]['ack_sum'] += $row['ack_minutes'];
        }
        if ($row['fulfill_minutes'] !== null) {
            $byTeam[$label]['fulfill_count']++;
            $byTeam[$label]['fulfill_sum'] += $row['fulfill_minutes'];
        }
    }
    $summary = [];
    foreach ($byTeam as $label => $s) {
        $summary[] = [
            'team_label'          => $label,
            'order_count'         => $s['count'],
            'ack_rate'            => $s['count'] ? round($s['ack_count'] / $s['count'] * 100) : 0,
            'fulfill_rate'        => $s['count'] ? round($s['fulfill_count'] / $s['count'] * 100) : 0,
            'avg_ack_minutes'     => $s['ack_count'] ? round($s['ack_sum'] / $s['ack_count'], 1) : null,
            'avg_fulfill_minutes' => $s['fulfill_count'] ? round($s['fulfill_sum'] / $s['fulfill_count'], 1) : null,
        ];
    }
    usort($summary, fn($a, $b) => $b['order_count'] <=> $a['order_count']);

    // ── shortage reports (inverse direction: admin responding to a team's report) ──
    $shortageRows = dbFetchAll(
        "SELECT r.shortage_type, r.severity, r.title, r.created_at AS sent_at, r.team_id, r.reporter_id, u.name AS user_name,
                r.acknowledged_at, r.resolved_at
         FROM mission_shortage_reports r
         JOIN users u ON u.id = r.reporter_id
         WHERE r.mission_id = ?
         ORDER BY r.created_at DESC",
        [$missionId]
    );
    $shortageDetail = [];
    foreach ($shortageRows as $row) {
        $teamId = $row['team_id'] ? (int) $row['team_id'] : null;
        $shortageDetail[] = [
            'type_label'     => shortageTypeLabel($row['shortage_type'], $lang),
            'severity'       => $row['severity'],
            'severity_label' => shortageSeverityLabel($row['severity'], $lang),
            'team_id'        => $teamId,
            'team_label'     => $teamId ? ($teamLabels[$teamId] ?? '—') : t('history.no_team_capitalized', [], $lang),
            'reporter_id'    => (int) $row['reporter_id'],
            'reporter_name'  => $row['user_name'],
            'title'          => $row['title'],
            'sent_at'        => $row['sent_at'],
            'seen_at'        => $row['acknowledged_at'],
            'resolved_at'    => $row['resolved_at'],
        ];
    }
    foreach ($shortageDetail as &$row) {
        $row['seen_minutes'] = reportMinutesBetween($row['sent_at'], $row['seen_at']);
        $row['resolved_minutes'] = reportMinutesBetween($row['sent_at'], $row['resolved_at']);
    }
    unset($row);
    usort($shortageDetail, fn($a, $b) => strtotime($b['sent_at']) <=> strtotime($a['sent_at']));

    $bySeverity = [];
    foreach ($shortageDetail as $row) {
        $sev = $row['severity'];
        if (!isset($bySeverity[$sev])) {
            $bySeverity[$sev] = ['label' => $row['severity_label'], 'count' => 0, 'seen_count' => 0, 'resolved_count' => 0, 'seen_sum' => 0.0, 'resolved_sum' => 0.0];
        }
        $bySeverity[$sev]['count']++;
        if ($row['seen_minutes'] !== null) {
            $bySeverity[$sev]['seen_count']++;
            $bySeverity[$sev]['seen_sum'] += $row['seen_minutes'];
        }
        if ($row['resolved_minutes'] !== null) {
            $bySeverity[$sev]['resolved_count']++;
            $bySeverity[$sev]['resolved_sum'] += $row['resolved_minutes'];
        }
    }
    $severityRank = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
    $shortageSummary = [];
    foreach ($bySeverity as $sev => $s) {
        $shortageSummary[] = [
            'severity'             => $sev,
            'severity_label'       => $s['label'],
            'report_count'         => $s['count'],
            'seen_rate'            => $s['count'] ? round($s['seen_count'] / $s['count'] * 100) : 0,
            'resolved_rate'        => $s['count'] ? round($s['resolved_count'] / $s['count'] * 100) : 0,
            'avg_seen_minutes'     => $s['seen_count'] ? round($s['seen_sum'] / $s['seen_count'], 1) : null,
            'avg_resolved_minutes' => $s['resolved_count'] ? round($s['resolved_sum'] / $s['resolved_count'], 1) : null,
        ];
    }
    usort($shortageSummary, fn($a, $b) => ($severityRank[$a['severity']] ?? 9) <=> ($severityRank[$b['severity']] ?? 9));

    return [
        'summary' => $summary,
        'detail' => $detail,
        'shortageSummary' => $shortageSummary,
        'shortageDetail' => $shortageDetail,
    ];
}

/**
 * War Room: maps a 0-100 score to its display tier. Shared by both the
 * mission-wide score and every per-team leaderboard score computed in
 * computeMissionScore() below, and by both of that function's Greek-only
 * callers (mission-stats.php, mission-report-print.php) — kept here rather
 * than duplicated so the two pages can never drift on the band cutoffs.
 * Returns [tier, label]; tier is one of 'good'/'warning'/'critical', matching
 * mission-stats.php's pre-existing .mstats-chip.good/.warning/.critical CSS.
 */
function missionScoreTierMeta(float $score): array {
    if ($score >= 85) return ['good', 'Άριστη Επίδοση'];
    if ($score >= 65) return ['warning', 'Καλή Επίδοση'];
    return ['critical', 'Χρειάζεται Βελτίωση'];
}

/**
 * War Room: raw speed-bucket stats for a list of minute-deltas — splits into
 * "normal" (<= $thresholdMinutes) and "forgotten" (> $thresholdMinutes,
 * excluded from the average, kept only as a count), with NO score/decay
 * logic at all. Shared by missionScoreForgottenAwareSpeed() below (which
 * layers a decay curve + per-occurrence penalty on top, for the 0-100 score
 * pillars/leaderboard) and by every *display-only* forgotten-aware average
 * (order-type breakdown, per-team summary, fulfillment-time hero metric —
 * see computeMissionOrderTypeBreakdown()/computeMissionTeamSpeedBreakdown())
 * that needs avg_minutes + forgotten_count but must never see a meaningless
 * 0-100 "score" for what is just a raw minutes figure.
 */
function missionSpeedBucketStats(array $minutesList, int $thresholdMinutes): array {
    $present = array_values(array_filter($minutesList, fn($m) => $m !== null));
    $normal = array_values(array_filter($present, fn($m) => $m <= $thresholdMinutes));
    $forgottenCount = count($present) - count($normal);
    return [
        'avg_minutes'     => count($normal) ? round(array_sum($normal) / count($normal), 1) : null,
        'forgotten_count' => $forgottenCount,
        'normal_count'    => count($normal),
        'total_count'     => count($present),
    ];
}

/**
 * War Room: linear-interpolated percentile of an already-sorted numeric list.
 * Small-sample friendly (the team leaderboard routinely works with 3-10
 * orders per team), and interpolating rather than nearest-rank keeps p90 from
 * snapping onto the single worst value the moment a list has fewer than ten
 * entries — which would make every small team look equally erratic.
 */
function missionPercentile(array $sortedValues, float $p): ?float {
    $n = count($sortedValues);
    if ($n === 0) return null;
    if ($n === 1) return (float) $sortedValues[0];
    $pos = ($n - 1) * $p;
    $lo = (int) floor($pos);
    $hi = (int) ceil($pos);
    if ($lo === $hi) return (float) $sortedValues[$lo];
    return $sortedValues[$lo] + ($sortedValues[$hi] - $sortedValues[$lo]) * ($pos - $lo);
}

/**
 * War Room: how CONSISTENT a team's acknowledgment rhythm was, as a 0-100
 * score independent of how fast it was on average. The speed pillar can only
 * see the mean, and a mean hides the difference that matters most to a
 * commander: ack times of 1, 1, 1, 60 and 15, 16, 16, 16 average the same,
 * but the first team is unpredictable and the second is dependable.
 *
 * Measured as the dispersion ratio p90/median rather than a standard
 * deviation: minute-deltas are strongly right-skewed (a long tail of slow
 * responses, a hard floor at zero), so an SD is dominated by the tail and a
 * coefficient of variation inherits that. The ratio is also scale-free — a
 * team that answers in 2/2/8 minutes is exactly as erratic as one answering
 * in 20/20/80, which is the intended reading.
 *
 * Forgotten entries are excluded (the speed pillar already charges for them
 * directly and they would swamp the ratio), and nulls with them. Needs at
 * least $minSamples real observations — below that a spread figure is noise,
 * so the metric reports itself unavailable rather than guessing.
 */
function missionTeamConsistency(array $minutesList, int $thresholdMinutes, int $minSamples = 3): array {
    $vals = array_values(array_filter($minutesList, fn($m) => $m !== null && $m <= $thresholdMinutes));
    if (count($vals) < $minSamples) {
        return ['available' => false, 'score' => null, 'median' => null, 'p90' => null, 'spread' => null, 'sample' => count($vals)];
    }
    sort($vals);
    $median = missionPercentile($vals, 0.5);
    $p90 = missionPercentile($vals, 0.9);
    // Floor the denominator at 1 minute: a team answering everything inside
    // 60 seconds has a median of 0, which would divide by zero and, worse,
    // brand the fastest possible team as infinitely inconsistent.
    $spread = $p90 / max(1.0, (float) $median);
    // spread 1.0 (p90 == median, perfectly even) => 100. Half-life of 3 on
    // the excess: spread 4 => 50, spread 7 => 25. Asymptotic, never negative,
    // same shape as the speed decay so the two read on one mental scale.
    $score = 100 * (0.5 ** (max(0.0, $spread - 1.0) / 3.0));
    return ['available' => true, 'score' => $score, 'median' => round((float) $median, 1), 'p90' => round((float) $p90, 1), 'spread' => round($spread, 2), 'sample' => count($vals)];
}

/**
 * War Room: did a team hold its pace, or did it fade? Splits the team's own
 * orders at the midpoint of its own activity window and compares the average
 * acknowledgment time of each half. Nothing else in the report can see this:
 * every other figure is a single number for the whole mission, so a team that
 * started sharp and drifted after hour four reads identically to one that was
 * mediocre throughout — and those are completely different debrief
 * conversations.
 *
 * Split by sent_at order, not by wall-clock halves of the mission: a team
 * that only received orders in the last two hours should still be judged on
 * its own first-half-vs-second-half, not compared against an empty window.
 *
 * Needs >=2 answered orders on BOTH sides to say anything, and uses a +-25%
 * dead zone — deliberately wider than missionMinutesTrend()'s +-10%, because
 * half of one mission is a far smaller and noisier sample than a historical
 * baseline built from every past mission of the same type.
 */
function missionTeamTrajectory(array $timeline, int $thresholdMinutes): array {
    $unavailable = ['available' => false, 'direction' => null, 'first_avg' => null, 'second_avg' => null, 'change_pct' => null];
    $rows = array_values(array_filter($timeline, fn($r) => $r['sent_at'] !== null));
    if (count($rows) < 4) return $unavailable;
    usort($rows, fn($a, $b) => strtotime($a['sent_at']) <=> strtotime($b['sent_at']));
    $half = intdiv(count($rows), 2);
    $avg = function (array $slice) use ($thresholdMinutes): ?float {
        $vals = array_values(array_filter(array_column($slice, 'ack_minutes'), fn($m) => $m !== null && $m <= $thresholdMinutes));
        return count($vals) >= 2 ? array_sum($vals) / count($vals) : null;
    };
    $firstAvg = $avg(array_slice($rows, 0, $half));
    $secondAvg = $avg(array_slice($rows, $half));
    if ($firstAvg === null || $secondAvg === null || $firstAvg <= 0) return $unavailable;
    $changePct = round((($secondAvg - $firstAvg) / $firstAvg) * 100);
    if ($changePct >= 25) $direction = 'worse';
    elseif ($changePct <= -25) $direction = 'better';
    else $direction = 'steady';
    return ['available' => true, 'direction' => $direction, 'first_avg' => round($firstAvg, 1), 'second_avg' => round($secondAvg, 1), 'change_pct' => (int) $changePct];
}

/**
 * War Room: turns a raw list of minute-deltas (order-ack, shortage-seen,
 * shortage-resolved — anything "how long until X happened") into a 0-100
 * speed score that a single forgotten outlier can't destroy. Built on
 * missionSpeedBucketStats() above for the normal/forgotten split, then
 * applies a smooth exponential half-life decay: score = 100 * 0.5^(avg /
 * $halfLifeMinutes). This replaced an earlier linear "100 - avg*decayFactor"
 * formula that hit an exact 0 floor at a fixed number of minutes well before
 * $thresholdMinutes, collapsing a wide "kinda slow but not forgotten" band
 * to an identical 0 and losing all ordering within it — confirmed on a real
 * production PDF where a 1000+-minute outlier had already shown how badly a
 * plain mean distorts this same data; the decay curve had the same class of
 * problem one level down. The exponential is asymptotic — never hits
 * exactly 0, always monotonically decreasing — so relative ordering is
 * preserved across the whole 0-$thresholdMinutes range. On top of the decay,
 * anything past $thresholdMinutes still docks the score directly as a
 * per-occurrence penalty (the same shape already used for
 * unresolved-critical shortages elsewhere in computeMissionScore()) — so a
 * forgotten item still costs real points, it just can't single-handedly
 * zero out an otherwise-fast team.
 *
 * $countUnanswered (opt-in, off by default so no existing caller shifts)
 * closes a real hole: missionSpeedBucketStats() drops every null, and a null
 * here means "never acknowledged at all". Without this flag a team with ten
 * orders, one acknowledged in 2 minutes and nine never touched, scored ~94 on
 * this pillar — i.e. never answering ranked BETTER than answering after five
 * hours, which costs a real -15. Switched on, the decayed base is multiplied
 * by the answered-rate.
 *
 * Deliberately a RATE multiplier rather than another per-occurrence penalty
 * like the forgotten one, even though per-occurrence is this file's usual
 * idiom: a flat cost per unanswered order inverts the ranking by volume — a
 * team with 20 orders and 3 unanswered (85% answered, good) would lose 3x the
 * points of a team with 3 orders and 1 unanswered (67% answered, worse). The
 * multiplier is volume-neutral and has the useful property that a team which
 * answered everything gets rate = 1.0, i.e. a score byte-identical to what
 * this function returned before the flag existed.
 */
function missionScoreForgottenAwareSpeed(array $minutesList, float $halfLifeMinutes, int $thresholdMinutes, int $penaltyPerForgotten, bool $countUnanswered = false): array {
    $bucket = missionSpeedBucketStats($minutesList, $thresholdMinutes);
    $unansweredCount = $countUnanswered ? count($minutesList) - $bucket['total_count'] : 0;
    // Nothing answered AND nothing to penalise — genuinely no data. When
    // $countUnanswered is on and the list is all-nulls, that's NOT "no data":
    // it's the worst possible outcome (orders sent, none ever acknowledged),
    // so it must stay available and score 0 rather than silently drop out and
    // let the pillar renormalise the failure away.
    if ($bucket['total_count'] === 0 && $unansweredCount === 0) {
        return ['available' => false, 'score' => null, 'avg_minutes' => null, 'forgotten_count' => 0, 'unanswered_count' => 0, 'answered_rate' => null];
    }
    $avgNormal = $bucket['avg_minutes'];
    // No normal-speed data at all (every single response was forgotten) —
    // start from a neutral 100 rather than assuming the worst on top of the
    // forgotten penalty below, which already does the real punishing.
    // min(100, ...) guards a theoretical negative $avgNormal (clock-skew/bad
    // data — reportMinutesBetween() doesn't clamp negative deltas) from
    // pushing 0.5^negative above 100; no lower clamp is needed since the
    // exponential is already bounded in (0,100] for any non-negative input.
    $base = $avgNormal !== null ? min(100, 100 * (0.5 ** ($avgNormal / $halfLifeMinutes))) : 100.0;
    // answered_rate is 1.0 for every caller that leaves $countUnanswered off,
    // so their arithmetic is untouched.
    $considered = $bucket['total_count'] + $unansweredCount;
    $answeredRate = $considered > 0 ? $bucket['total_count'] / $considered : 1.0;
    $score = max(0, $base * $answeredRate - $bucket['forgotten_count'] * $penaltyPerForgotten);
    return [
        'available'        => true,
        'score'            => $score,
        'avg_minutes'      => $avgNormal,
        'forgotten_count'  => $bucket['forgotten_count'],
        'unanswered_count' => $unansweredCount,
        'answered_rate'    => $countUnanswered ? $answeredRate : null,
    ];
}

/**
 * War Room: per-team fieldwork signals — everything the Action Room already
 * records about what a team physically did, which until now no part of the
 * report read. Keyed by team_id; teams with no fieldwork at all simply do not
 * appear. One grouped query per concern, never per team: this file has a
 * documented history of connection exhaustion under load, and the mission
 * report is exactly the kind of page that gets opened by several people at
 * once right after a mission ends.
 *
 * The split between what is SCORED and what is only DESCRIBED is deliberate
 * and must not be quietly widened:
 *
 *  - Scored (see the 'discipline' pillar in computeMissionScore()): route
 *    waypoint closure, out-of-sequence visits, assigned-sector coverage, and
 *    restricted-area breaches. All four measure whether a team did what it was
 *    told, the way it was told — none of them depends on how far the team had
 *    to travel, which is the same fairness line generateTeamComparisonNarrative()
 *    already draws around dispatch arrival times.
 *
 *  - Never scored, only described: SOS alerts and casualty incidents. Scoring
 *    either one would reward a team for NOT raising them, which is a safety
 *    hazard, not a scoring imperfection — a team must never have a numeric
 *    reason to sit on a mayday. Field media and Points of Interest are also
 *    left unscored for a plainer reason: a team that found nothing may have
 *    been given an empty sector, and counting finds would score the ground
 *    rather than the crew.
 */
function computeMissionTeamFieldwork(int $missionId): array {
    $teams = [];
    $seed = function (int $tid) use (&$teams) {
        if (!isset($teams[$tid])) {
            $teams[$tid] = [
                'waypoints_total' => 0, 'waypoints_completed' => 0, 'waypoints_skipped' => 0,
                'waypoints_skipped_with_reason' => 0, 'waypoints_untouched' => 0,
                'out_of_sequence' => 0, 'skip_reason_example' => null,
                'sectors_assigned' => 0, 'sectors_completed' => 0, 'sectors_in_progress' => 0,
                'sectors_needs_recheck' => 0, 'sectors_not_started' => 0,
                'breaches' => 0, 'breach_area' => null,
                'sos_alerts' => 0, 'incidents' => 0, 'poi_photos' => 0, 'field_media' => 0,
            ];
        }
    };

    // ── route waypoints. A cancelled route is excluded outright: command
    //    staff called it off, so its unvisited stops are not the team's doing.
    //    One progress row already exists per waypoint (created with the
    //    route), so COUNT(*) here is "stops this team was given", not "stops
    //    it touched". ───────────────────────────────────────────────────────
    $routeRows = dbFetchAll(
        "SELECT p.team_id,
                COUNT(*) AS total,
                SUM(CASE WHEN p.completed_at IS NOT NULL THEN 1 ELSE 0 END) AS completed,
                SUM(CASE WHEN p.skipped_at IS NOT NULL THEN 1 ELSE 0 END) AS skipped,
                SUM(CASE WHEN p.skipped_at IS NOT NULL AND p.skip_reason IS NOT NULL AND p.skip_reason <> '' THEN 1 ELSE 0 END) AS skipped_with_reason,
                SUM(CASE WHEN p.out_of_sequence = 1 THEN 1 ELSE 0 END) AS out_of_sequence
         FROM mission_route_progress p
         JOIN mission_routes r ON r.id = p.route_id
         WHERE r.mission_id = ? AND r.cancelled_at IS NULL AND p.team_id IS NOT NULL
         GROUP BY p.team_id",
        [$missionId]
    );
    foreach ($routeRows as $row) {
        $tid = (int) $row['team_id'];
        $seed($tid);
        $teams[$tid]['waypoints_total'] = (int) $row['total'];
        $teams[$tid]['waypoints_completed'] = (int) $row['completed'];
        $teams[$tid]['waypoints_skipped'] = (int) $row['skipped'];
        $teams[$tid]['waypoints_skipped_with_reason'] = (int) $row['skipped_with_reason'];
        $teams[$tid]['out_of_sequence'] = (int) $row['out_of_sequence'];
        $teams[$tid]['waypoints_untouched'] = max(0, (int) $row['total'] - (int) $row['completed'] - (int) $row['skipped']);
    }

    // one representative skip reason per team, for the narrative to quote
    foreach (dbFetchAll(
        "SELECT p.team_id, p.skip_reason, w.label, w.seq
         FROM mission_route_progress p
         JOIN mission_routes r ON r.id = p.route_id
         JOIN mission_route_waypoints w ON w.id = p.waypoint_id
         WHERE r.mission_id = ? AND r.cancelled_at IS NULL AND p.team_id IS NOT NULL
           AND p.skipped_at IS NOT NULL AND p.skip_reason IS NOT NULL AND p.skip_reason <> ''
         ORDER BY p.skipped_at",
        [$missionId]
    ) as $row) {
        $tid = (int) $row['team_id'];
        $seed($tid);
        if ($teams[$tid]['skip_reason_example'] === null) {
            $teams[$tid]['skip_reason_example'] = [
                'reason' => $row['skip_reason'],
                'waypoint' => ($row['label'] !== null && $row['label'] !== '') ? $row['label'] : ('#' . $row['seq']),
            ];
        }
    }

    // ── assigned search sectors ─────────────────────────────────────────
    foreach (dbFetchAll(
        "SELECT team_id, status, COUNT(*) AS cnt
         FROM mission_search_sectors
         WHERE mission_id = ? AND team_id IS NOT NULL
         GROUP BY team_id, status",
        [$missionId]
    ) as $row) {
        $tid = (int) $row['team_id'];
        $seed($tid);
        $cnt = (int) $row['cnt'];
        $teams[$tid]['sectors_assigned'] += $cnt;
        switch ($row['status']) {
            case 'completed':      $teams[$tid]['sectors_completed'] += $cnt; break;
            case 'needs_recheck':  $teams[$tid]['sectors_needs_recheck'] += $cnt; break;
            case 'in_progress':
            case 'en_route':       $teams[$tid]['sectors_in_progress'] += $cnt; break;
            default:               $teams[$tid]['sectors_not_started'] += $cnt; break;
        }
    }

    // ── restricted-area breaches ────────────────────────────────────────
    foreach (dbFetchAll(
        "SELECT team_id, COUNT(*) AS cnt, MIN(area_label) AS area
         FROM mission_restricted_area_breaches
         WHERE mission_id = ? AND team_id IS NOT NULL
         GROUP BY team_id",
        [$missionId]
    ) as $row) {
        $tid = (int) $row['team_id'];
        $seed($tid);
        $teams[$tid]['breaches'] = (int) $row['cnt'];
        $teams[$tid]['breach_area'] = $row['area'];
    }

    // ── described-only signals ──────────────────────────────────────────
    foreach ([
        ['mission_sos_alerts', 'sos_alerts'],
        ['mission_incidents', 'incidents'],
    ] as [$table, $key]) {
        foreach (dbFetchAll("SELECT team_id, COUNT(*) AS cnt FROM $table WHERE mission_id = ? AND team_id IS NOT NULL GROUP BY team_id", [$missionId]) as $row) {
            $tid = (int) $row['team_id'];
            $seed($tid);
            $teams[$tid][$key] = (int) $row['cnt'];
        }
    }

    // mission_photos carries no team_id — a photo belongs to whoever uploaded
    // it, and mission_team_members is unique on (mission_id, user_id), so the
    // uploader maps to exactly one team with no fan-out risk.
    foreach (dbFetchAll(
        "SELECT tm.team_id,
                COUNT(*) AS media,
                SUM(CASE WHEN ph.poi_id IS NOT NULL THEN 1 ELSE 0 END) AS poi_media
         FROM mission_photos ph
         JOIN mission_team_members tm ON tm.user_id = ph.user_id AND tm.mission_id = ph.mission_id
         WHERE ph.mission_id = ?
         GROUP BY tm.team_id",
        [$missionId]
    ) as $row) {
        $tid = (int) $row['team_id'];
        $seed($tid);
        $teams[$tid]['field_media'] = (int) $row['media'];
        $teams[$tid]['poi_photos'] = (int) $row['poi_media'];
    }

    return $teams;
}

/**
 * War Room: turns one team's computeMissionTeamFieldwork() row into the
 * 0-100 'discipline' pillar. Split out so the formula sits next to its own
 * explanation rather than inside computeMissionScore()'s already long body.
 *
 * Route stops and search sectors are averaged together weighted by their own
 * counts, not 50/50: a team with twenty waypoints and one sector should be
 * judged almost entirely on the waypoints, and a 50/50 blend would let a
 * single untouched sector cancel out a flawless twenty-stop route.
 *
 * A stop the team explicitly SKIPPED with a reason earns half credit, while
 * one it simply never touched earns none. That gap is the whole point: both
 * leave the ground uncovered, but one of them told command about it at the
 * time and the other left the map quietly lying. Scoring them identically
 * would remove any reason to file the skip.
 */
function missionTeamDisciplineScore(array $fw): array {
    $parts = [];

    $wpTotal = $fw['waypoints_total'];
    if ($wpTotal > 0) {
        $credit = $fw['waypoints_completed'] + 0.5 * $fw['waypoints_skipped_with_reason'];
        $parts[] = ['score' => min(100.0, $credit / $wpTotal * 100), 'weight' => $wpTotal];
    }

    $secTotal = $fw['sectors_assigned'];
    if ($secTotal > 0) {
        // in_progress/en_route earn half: the team engaged with the sector but
        // never closed it out, which is materially different from not starting.
        $credit = $fw['sectors_completed'] + 0.5 * $fw['sectors_in_progress'];
        $parts[] = ['score' => min(100.0, $credit / $secTotal * 100), 'weight' => $secTotal];
    }

    if (empty($parts)) {
        // Breaches alone do not make a discipline score. A team with no route
        // and no sector assignment has no body of work to be disciplined
        // about, and grading it purely on a penalty would manufacture a very
        // low score out of a single GPS event. The breach is still described
        // in the narrative.
        return ['available' => false, 'score' => null];
    }

    $weightSum = array_sum(array_column($parts, 'weight'));
    $base = array_sum(array_map(fn($p) => $p['score'] * $p['weight'], $parts)) / $weightSum;
    // Same per-occurrence scale the rest of this file uses (15 for a serious
    // failure); an out-of-sequence visit is a smaller thing than walking into
    // a restricted area, and the app asks for confirmation before allowing it
    // rather than blocking it, so it is a flag and not an offence.
    $score = max(0.0, $base - $fw['out_of_sequence'] * 5 - $fw['breaches'] * 15);
    return ['available' => true, 'score' => $score];
}

/**
 * War Room: post-mission performance score — an overall 0-100 grade plus a
 * per-team leaderboard, for mission-stats.php's score-validation section and
 * mission-report-print.php's read-only display. Reuses
 * computeMissionResponseReport()'s $detail/$shortageDetail rather than
 * re-querying orders/shortages a third time; pass an already-fetched $report
 * to avoid a duplicate call when the caller has one (both current callers do).
 *
 * Every pillar is independently gated on having real data — an unavailable
 * pillar drops out and the rest renormalize, the same "show — not a fake 0"
 * idea mission-stats.php already applies to its volunteer-hours tile via
 * $attendanceReady. $overall is null (not 0 or 100) when none of the
 * response/completion/staffing/debrief pillars have data — otherwise a
 * completely inactive mission would vacuously score 100 from the shortage
 * pillar's neutral "nothing went wrong" default alone. Callers must treat a
 * null overall as "insufficient data to score", not persist it.
 *
 * Two fairness passes happen before any pillar is computed, both prompted by
 * a real production PDF where a single forgotten order distorted everything
 * downstream of it:
 *  1. Rows whose actor is no longer an approved participant in this mission
 *     (their participation was later canceled/removed — "left by mistake")
 *     are dropped from every score computation entirely, via $approvedIds.
 *     This ONLY affects scoring, never the archival detail — $report itself
 *     is untouched (PHP arrays are copy-on-write), so mission-stats.php /
 *     mission-report-print.php's detail tables and activity feed keep
 *     showing literally everything that happened, unfiltered.
 *  2. Any surviving response/seen/resolved time past
 *     MISSION_SCORE_FORGOTTEN_MINUTES is excluded from the relevant speed
 *     *average* (so one 1000-minute gap can't flatten nine 2-minute ones)
 *     but still docks the score directly as a per-occurrence penalty, via
 *     missionScoreForgottenAwareSpeed() above — see that function's own
 *     comment for why a mean alone isn't enough here.
 */
function computeMissionScore(int $missionId, ?array $report = null): array {
    $report = $report ?? computeMissionResponseReport($missionId);
    $detail = $report['detail'];
    $shortageDetail = $report['shortageDetail'];

    $debrief = dbFetchOne("SELECT rating, objectives_met FROM mission_debriefs WHERE mission_id = ?", [$missionId]);
    $teams = dbFetchAll("SELECT id, codename, team_number, color FROM mission_teams WHERE mission_id = ? ORDER BY team_number", [$missionId]);
    $approvedIds = array_map('intval', array_column(dbFetchAll(
        "SELECT DISTINCT pr.volunteer_id FROM participation_requests pr
         JOIN shifts s ON s.id = pr.shift_id
         WHERE s.mission_id = ? AND pr.status = ?",
        [$missionId, PARTICIPATION_APPROVED]
    ), 'volunteer_id'));
    $approvedCount = count($approvedIds);
    $capacity = (int) dbFetchValue("SELECT COALESCE(SUM(max_volunteers), 0) FROM shifts WHERE mission_id = ?", [$missionId]);

    $forgottenThresholdMinutes = MISSION_SCORE_FORGOTTEN_MINUTES; // 4 hours — past this, treat as "forgotten"/off-shift, not merely slow
    $forgottenPenalty = 15; // same magnitude as the unresolved-critical-shortage penalty below, one consistent scale
    // Half-life (minutes) for the exponential speed-decay curve below — the
    // resolution half-life is deliberately larger (decays gentler) than the
    // response/seen one, matching this function's existing documented intent
    // that fixing something inherently takes longer than merely noticing it.
    $responseHalfLifeMinutes = 24;
    $resolutionHalfLifeMinutes = 66;

    $scoredDetail = array_values(array_filter($detail, fn($d) => in_array($d['user_id'], $approvedIds, true)));
    $scoredShortage = array_values(array_filter($shortageDetail, fn($d) => in_array($d['reporter_id'], $approvedIds, true)));

    // Named incidents for the narrative to cite directly ("η εντολή X προς Y
    // έμεινε αναπάντητη Z ώρες") rather than only ever speaking in aggregates.
    $forgottenOrders = [];
    foreach ($scoredDetail as $row) {
        if ($row['ack_minutes'] !== null && $row['ack_minutes'] > $forgottenThresholdMinutes) {
            $forgottenOrders[] = ['label' => $row['type_label'], 'order_label' => $row['label'], 'user_name' => $row['user_name'], 'team_label' => $row['team_label'], 'minutes' => $row['ack_minutes']];
        }
    }
    usort($forgottenOrders, fn($a, $b) => $b['minutes'] <=> $a['minutes']);
    $forgottenShortages = [];
    foreach ($scoredShortage as $row) {
        if ($row['seen_minutes'] !== null && $row['seen_minutes'] > $forgottenThresholdMinutes) {
            $forgottenShortages[] = ['title' => $row['title'], 'reporter_name' => $row['reporter_name'], 'team_label' => $row['team_label'], 'minutes' => $row['seen_minutes']];
        }
    }
    usort($forgottenShortages, fn($a, $b) => $b['minutes'] <=> $a['minutes']);

    // ── mission-wide pillars ─────────────────────────────────────────────────
    $totalOrders = count($scoredDetail);
    $pillars = [];

    $ackMinutesForScoring = array_column($scoredDetail, 'ack_minutes');
    // true = count never-acknowledged orders against the score (see
    // missionScoreForgottenAwareSpeed()'s docblock). $ackMinutesForScoring
    // already carries the nulls, they were simply being discarded.
    $responseCalc = missionScoreForgottenAwareSpeed($ackMinutesForScoring, $responseHalfLifeMinutes, $forgottenThresholdMinutes, $forgottenPenalty, true);
    if ($responseCalc['available']) {
        $pillars['response'] = ['label' => 'Ταχύτητα Απόκρισης', 'weight' => 25, 'available' => true, 'score' => $responseCalc['score'], 'raw' => ['avg_minutes' => $responseCalc['avg_minutes'], 'forgotten_count' => $responseCalc['forgotten_count']]];
    } else {
        $pillars['response'] = ['label' => 'Ταχύτητα Απόκρισης', 'weight' => 25, 'available' => false, 'score' => null, 'raw' => []];
    }

    // ── response-time distribution (mission-report-print.php) — same
    //    $scoredDetail population the response pillar itself scores, so the
    //    histogram can never visually disagree with that pillar/hero-tile
    //    number. Bucket boundaries match the existing normal/forgotten
    //    cutoff exactly: 60-240 is inclusive of 240 ("normal"), forgotten is
    //    strictly >240. Never-acknowledged orders (null) are excluded
    //    entirely — "no response at all" is a different story than
    //    "responded slowly", and missionSpeedBucketStats() excludes them too.
    $histogramBuckets = [
        ['key' => 'lt5',       'label' => '<5',               'count' => 0],
        ['key' => '5to15',     'label' => '5-15',              'count' => 0],
        ['key' => '15to60',    'label' => '15-60',             'count' => 0],
        ['key' => '60to240',   'label' => '60-240',            'count' => 0],
        ['key' => 'forgotten', 'label' => 'Ξεχασμένες (>240)', 'count' => 0],
    ];
    $totalAcknowledged = 0;
    foreach ($ackMinutesForScoring as $m) {
        if ($m === null) continue;
        $totalAcknowledged++;
        if ($m < 5) $histogramBuckets[0]['count']++;
        elseif ($m < 15) $histogramBuckets[1]['count']++;
        elseif ($m < 60) $histogramBuckets[2]['count']++;
        elseif ($m <= $forgottenThresholdMinutes) $histogramBuckets[3]['count']++;
        else $histogramBuckets[4]['count']++;
    }
    $responseHistogram = [
        'available'          => $totalAcknowledged > 0,
        'buckets'            => $histogramBuckets,
        'total_acknowledged' => $totalAcknowledged,
    ];

    $fulfilledRows = array_filter($scoredDetail, fn($d) => $d['fulfill_minutes'] !== null);
    $fulfilled = count($fulfilledRows);
    if ($totalOrders > 0) {
        $pillars['completion'] = ['label' => 'Ολοκλήρωση Εντολών', 'weight' => 20, 'available' => true, 'score' => $fulfilled / $totalOrders * 100, 'raw' => ['fulfilled' => $fulfilled, 'total' => $totalOrders]];
    } else {
        $pillars['completion'] = ['label' => 'Ολοκλήρωση Εντολών', 'weight' => 20, 'available' => false, 'score' => null, 'raw' => []];
    }
    // Forgotten-aware, matching the ack-minutes hero tile right next to this
    // one — previously a plain mean with no outlier protection or footnote.
    $fulfillSpeedStats = missionSpeedBucketStats(array_column($scoredDetail, 'fulfill_minutes'), $forgottenThresholdMinutes);
    $avgFulfillMinutes = $fulfillSpeedStats['avg_minutes'];

    $totalShortage = count($scoredShortage);
    $resolved = count(array_filter($scoredShortage, fn($d) => $d['resolved_at'] !== null));
    $unresolvedCritical = count(array_filter($scoredShortage, fn($d) => $d['resolved_at'] === null && $d['severity'] === 'critical'));
    if ($totalShortage > 0) {
        $shortageScore = max(0, min(100, ($resolved / $totalShortage * 100) - $unresolvedCritical * 15));
    } else {
        $shortageScore = 100.0; // nothing reported broken — neutral, not penalized
    }
    $pillars['shortage'] = ['label' => 'Διαχείριση Ελλείψεων', 'weight' => 20, 'available' => true, 'score' => $shortageScore, 'raw' => ['resolved' => $resolved, 'total' => $totalShortage, 'unresolved_critical' => $unresolvedCritical]];

    if ($capacity > 0) {
        $pillars['staffing'] = ['label' => 'Στελέχωση / Κάλυψη', 'weight' => 15, 'available' => true, 'score' => max(0, min(100, $approvedCount / $capacity * 100)), 'raw' => ['approved' => $approvedCount, 'capacity' => $capacity]];
    } else {
        $pillars['staffing'] = ['label' => 'Στελέχωση / Κάλυψη', 'weight' => 15, 'available' => false, 'score' => null, 'raw' => []];
    }

    if ($debrief) {
        $ratingScore = ((int) $debrief['rating']) / 5 * 100;
        $objectivesScore = ['YES' => 100, 'PARTIAL' => 55, 'NO' => 15][$debrief['objectives_met']] ?? 55;
        $pillars['debrief'] = ['label' => 'Απολογισμός Debrief', 'weight' => 20, 'available' => true, 'score' => $ratingScore * 0.6 + $objectivesScore * 0.4, 'raw' => ['rating' => (int) $debrief['rating'], 'objectives' => $debrief['objectives_met']]];
    } else {
        $pillars['debrief'] = ['label' => 'Απολογισμός Debrief', 'weight' => 20, 'available' => false, 'score' => null, 'raw' => []];
    }

    // ── shared headline timing metrics (hero tiles on both pages) + command/
    //    admin responsiveness — how fast the command staff SAW (acknowledged)
    //    and RESOLVED teams' shortage reports. Deliberately a separate
    //    evaluation from the 'shortage' pillar above (which grades the
    //    *outcome* — resolved or not, penalized for unresolved criticals) —
    //    this one grades *speed*, and is null (not defaulted to neutral) when
    //    there are zero shortage reports, since there's nothing to judge the
    //    command staff's reaction time on. Same forgotten-aware treatment as
    //    the response pillar — a shortage seen 16 hours late shouldn't erase
    //    every other same-day acknowledgment from the average. ────────────
    // Asymmetric on purpose. SEEN counts never-seen reports against the score
    // (same hole as the response pillar: a report command staff never even
    // opened must not outrank one they opened late). RESOLVED does NOT — this
    // score is documented right above as grading *speed*, with the separate
    // 'shortage' pillar grading the *outcome*; folding never-resolved in here
    // would quietly turn half of the command score into a second outcome
    // measure and double-count the same failure on two different reports.
    $seenCalc = missionScoreForgottenAwareSpeed(array_column($scoredShortage, 'seen_minutes'), $responseHalfLifeMinutes, $forgottenThresholdMinutes, $forgottenPenalty, true);
    $resolvedCalc = missionScoreForgottenAwareSpeed(array_column($scoredShortage, 'resolved_minutes'), $resolutionHalfLifeMinutes, $forgottenThresholdMinutes, $forgottenPenalty);
    $seenCount = count(array_filter($scoredShortage, fn($d) => $d['seen_minutes'] !== null));

    $commandParts = [];
    if ($seenCalc['available']) $commandParts[] = ['score' => $seenCalc['score'], 'weight' => 50];
    if ($resolvedCalc['available']) $commandParts[] = ['score' => $resolvedCalc['score'], 'weight' => 50];
    if ($totalShortage > 0 && !empty($commandParts)) {
        $cWeightSum = array_sum(array_column($commandParts, 'weight'));
        $cWeightedScore = array_sum(array_map(fn($p) => $p['score'] * $p['weight'], $commandParts));
        $commandScore = round($cWeightedScore / $cWeightSum, 2);
        $commandTier = missionScoreTierMeta($commandScore);
        $commandAvailable = true;
    } else {
        $commandScore = null;
        $commandTier = null;
        $commandAvailable = false;
    }
    $command = [
        'available'          => $commandAvailable,
        'score'              => $commandScore,
        'tier'               => $commandTier,
        'avg_seen'           => $seenCalc['avg_minutes'],
        'avg_resolved'       => $resolvedCalc['avg_minutes'],
        'seen_rate'          => $totalShortage ? round($seenCount / $totalShortage * 100) : null,
        'resolved_rate'      => $totalShortage ? round($resolved / $totalShortage * 100) : null,
        'total_reports'      => $totalShortage,
        'forgotten_incidents' => $forgottenShortages,
    ];
    // Captured now, before the per-team loop below reuses $avgAck as its own
    // local (per-team) variable name — these are the mission-wide values.
    $metrics = [
        'avg_ack'           => $responseCalc['avg_minutes'],
        'avg_fulfill'       => $avgFulfillMinutes,
        'avg_seen'          => $seenCalc['avg_minutes'],
        'avg_resolved'      => $resolvedCalc['avg_minutes'],
        'forgotten_orders'  => $responseCalc['forgotten_count'],
        'forgotten_fulfill' => $fulfillSpeedStats['forgotten_count'],
        'forgotten_seen'    => $seenCalc['forgotten_count'],
        'forgotten_resolved' => $resolvedCalc['forgotten_count'],
    ];

    // ── historical baseline — same mission_type_id, everything except this
    //    mission, same forgotten-minutes cutoff applied to every side of the
    //    comparison so a past fluke can't distort the baseline either.
    //    Cheap set-based aggregation (not a per-mission computeMissionScore()
    //    call in a loop) since only a handful of numbers are needed. Gated on
    //    a small minimum sample so 1-2 historical data points don't produce a
    //    noisy "50% faster than history" claim. avg_fulfill/avg_seen/
    //    avg_resolved back the hero-tile trend arrows (mission-report-print.php)
    //    only — no narrative sentence reads them, unlike avg_ack/completion_rate
    //    which generateMissionObserverNarrative() already cites. ─────────────
    $missionTypeId = (int) dbFetchValue("SELECT mission_type_id FROM missions WHERE id = ?", [$missionId]);
    $historical = [
        'avg_ack' => null, 'avg_ack_sample' => 0,
        'completion_rate' => null, 'completion_sample' => 0,
        'avg_fulfill' => null, 'avg_fulfill_sample' => 0,
        'avg_seen' => null, 'avg_seen_sample' => 0,
        'avg_resolved' => null, 'avg_resolved_sample' => 0,
    ];
    if ($missionTypeId) {
        $histAck = dbFetchOne(
            "SELECT AVG(TIMESTAMPDIFF(MINUTE, o.created_at, r.acknowledged_at)) AS avg_ack, COUNT(*) AS n
             FROM mission_order_recipients r
             JOIN mission_orders o ON o.id = r.order_id
             JOIN missions m ON m.id = o.mission_id
             WHERE m.mission_type_id = ? AND m.id != ? AND m.deleted_at IS NULL
               AND r.acknowledged_at IS NOT NULL
               AND TIMESTAMPDIFF(MINUTE, o.created_at, r.acknowledged_at) <= ?",
            [$missionTypeId, $missionId, $forgottenThresholdMinutes]
        );
        $histCompletion = dbFetchOne(
            "SELECT COUNT(*) AS total, SUM(CASE WHEN r.fulfilled_at IS NOT NULL THEN 1 ELSE 0 END) AS fulfilled
             FROM mission_order_recipients r
             JOIN mission_orders o ON o.id = r.order_id
             JOIN missions m ON m.id = o.mission_id
             WHERE m.mission_type_id = ? AND m.id != ? AND m.deleted_at IS NULL",
            [$missionTypeId, $missionId]
        );
        $histFulfill = dbFetchOne(
            "SELECT AVG(TIMESTAMPDIFF(MINUTE, o.created_at, r.fulfilled_at)) AS avg_fulfill, COUNT(*) AS n
             FROM mission_order_recipients r
             JOIN mission_orders o ON o.id = r.order_id
             JOIN missions m ON m.id = o.mission_id
             WHERE m.mission_type_id = ? AND m.id != ? AND m.deleted_at IS NULL
               AND r.fulfilled_at IS NOT NULL
               AND TIMESTAMPDIFF(MINUTE, o.created_at, r.fulfilled_at) <= ?",
            [$missionTypeId, $missionId, $forgottenThresholdMinutes]
        );
        $histSeen = dbFetchOne(
            "SELECT AVG(TIMESTAMPDIFF(MINUTE, r.created_at, r.acknowledged_at)) AS avg_seen, COUNT(*) AS n
             FROM mission_shortage_reports r
             JOIN missions m ON m.id = r.mission_id
             WHERE m.mission_type_id = ? AND m.id != ? AND m.deleted_at IS NULL
               AND r.acknowledged_at IS NOT NULL
               AND TIMESTAMPDIFF(MINUTE, r.created_at, r.acknowledged_at) <= ?",
            [$missionTypeId, $missionId, $forgottenThresholdMinutes]
        );
        $histResolved = dbFetchOne(
            "SELECT AVG(TIMESTAMPDIFF(MINUTE, r.created_at, r.resolved_at)) AS avg_resolved, COUNT(*) AS n
             FROM mission_shortage_reports r
             JOIN missions m ON m.id = r.mission_id
             WHERE m.mission_type_id = ? AND m.id != ? AND m.deleted_at IS NULL
               AND r.resolved_at IS NOT NULL
               AND TIMESTAMPDIFF(MINUTE, r.created_at, r.resolved_at) <= ?",
            [$missionTypeId, $missionId, $forgottenThresholdMinutes]
        );

        $histAckN = $histAck ? (int) $histAck['n'] : 0;
        $histTotal = $histCompletion ? (int) $histCompletion['total'] : 0;
        $histFulfillN = $histFulfill ? (int) $histFulfill['n'] : 0;
        $histSeenN = $histSeen ? (int) $histSeen['n'] : 0;
        $histResolvedN = $histResolved ? (int) $histResolved['n'] : 0;

        $historical = [
            'avg_ack'            => ($histAckN >= 3 && $histAck['avg_ack'] !== null) ? round((float) $histAck['avg_ack'], 1) : null,
            'avg_ack_sample'     => $histAckN,
            'completion_rate'    => ($histTotal >= 3) ? round(((int) $histCompletion['fulfilled']) / $histTotal * 100) : null,
            'completion_sample'  => $histTotal,
            'avg_fulfill'        => ($histFulfillN >= 3 && $histFulfill['avg_fulfill'] !== null) ? round((float) $histFulfill['avg_fulfill'], 1) : null,
            'avg_fulfill_sample' => $histFulfillN,
            'avg_seen'           => ($histSeenN >= 3 && $histSeen['avg_seen'] !== null) ? round((float) $histSeen['avg_seen'], 1) : null,
            'avg_seen_sample'    => $histSeenN,
            'avg_resolved'       => ($histResolvedN >= 3 && $histResolved['avg_resolved'] !== null) ? round((float) $histResolved['avg_resolved'], 1) : null,
            'avg_resolved_sample' => $histResolvedN,
        ];
    }

    $hasSubstantiveData = $pillars['response']['available'] || $pillars['completion']['available']
        || $pillars['staffing']['available'] || $pillars['debrief']['available'];
    $weightSum = 0;
    $weightedScore = 0.0;
    foreach ($pillars as $p) {
        if ($p['available']) {
            $weightSum += $p['weight'];
            $weightedScore += $p['weight'] * $p['score'];
        }
    }
    $overall = ($hasSubstantiveData && $weightSum > 0) ? round($weightedScore / $weightSum, 2) : null;
    $overallTier = $overall !== null ? missionScoreTierMeta($overall) : null;

    // ── per-team leaderboard, grouped by team_id (not the display-string
    //    team_label) so it can't be confused by two teams that happen to
    //    render identically — see computeMissionResponseReport()'s $detail
    //    (already carries team_id) and $shortageDetail (carries it as of the
    //    field added just above this function). Built from the same
    //    approved-only $scoredDetail/$scoredShortage as the mission-wide
    //    pillars above, not the raw $detail/$shortageDetail. ──────────────
    $byTeamOrders = [];
    foreach ($scoredDetail as $row) {
        $tid = $row['team_id'];
        if ($tid === null) continue;
        if (!isset($byTeamOrders[$tid])) {
            $byTeamOrders[$tid] = ['count' => 0, 'ack_minutes' => [], 'fulfill_count' => 0, 'timeline' => [], 'worst' => null, 'unanswered_example' => null];
        }
        $byTeamOrders[$tid]['count']++;
        // Nulls are pushed too, unlike before — the speed helper is now told
        // to count them as "never acknowledged" rather than silently dropping
        // them, so it needs to actually see them.
        $byTeamOrders[$tid]['ack_minutes'][] = $row['ack_minutes'];
        if ($row['fulfill_minutes'] !== null) {
            $byTeamOrders[$tid]['fulfill_count']++;
        }
        // Kept for the narrative only (trajectory + named worst-order
        // callout); no pillar arithmetic reads either of these.
        $byTeamOrders[$tid]['timeline'][] = ['sent_at' => $row['sent_at'], 'ack_minutes' => $row['ack_minutes']];
        $worst = $byTeamOrders[$tid]['worst'];
        if ($row['ack_minutes'] !== null && ($worst === null || $row['ack_minutes'] > $worst['minutes'])) {
            $byTeamOrders[$tid]['worst'] = ['label' => $row['type_label'], 'order_label' => $row['label'], 'user_name' => $row['user_name'], 'minutes' => $row['ack_minutes']];
        }
        if ($row['ack_minutes'] === null && $byTeamOrders[$tid]['unanswered_example'] === null) {
            $byTeamOrders[$tid]['unanswered_example'] = ['label' => $row['type_label'], 'order_label' => $row['label'], 'user_name' => $row['user_name']];
        }
    }
    $byTeamShortage = [];
    foreach ($scoredShortage as $row) {
        $tid = $row['team_id'];
        if ($tid === null) continue;
        if (!isset($byTeamShortage[$tid])) {
            $byTeamShortage[$tid] = ['count' => 0, 'resolved' => 0, 'unresolvedCritical' => 0];
        }
        $byTeamShortage[$tid]['count']++;
        if ($row['resolved_at'] !== null) {
            $byTeamShortage[$tid]['resolved']++;
        } elseif ($row['severity'] === 'critical') {
            $byTeamShortage[$tid]['unresolvedCritical']++;
        }
    }

    // One batch of grouped queries for every team's fieldwork, fetched before
    // the loop rather than inside it — see computeMissionTeamFieldwork()'s own
    // note on why this page must not issue per-team queries.
    $fieldwork = computeMissionTeamFieldwork($missionId);
    $emptyFieldwork = [
        'waypoints_total' => 0, 'waypoints_completed' => 0, 'waypoints_skipped' => 0,
        'waypoints_skipped_with_reason' => 0, 'waypoints_untouched' => 0,
        'out_of_sequence' => 0, 'skip_reason_example' => null,
        'sectors_assigned' => 0, 'sectors_completed' => 0, 'sectors_in_progress' => 0,
        'sectors_needs_recheck' => 0, 'sectors_not_started' => 0,
        'breaches' => 0, 'breach_area' => null,
        'sos_alerts' => 0, 'incidents' => 0, 'poi_photos' => 0, 'field_media' => 0,
    ];

    $teamScores = [];
    foreach ($teams as $team) {
        $tid = (int) $team['id'];
        $orders = $byTeamOrders[$tid] ?? null;
        $shortages = $byTeamShortage[$tid] ?? null;
        $fw = $fieldwork[$tid] ?? $emptyFieldwork;
        $fwDiscipline = missionTeamDisciplineScore($fw);
        // Fieldwork alone is now enough to put a team on the leaderboard. A
        // team can be given a route to walk and no radio orders at all, and
        // before this it vanished from the report entirely.
        if ($orders === null && $shortages === null && !$fwDiscipline['available']) {
            continue; // nothing to score this team on — excluded from the leaderboard entirely
        }

        // raw fields deliberately mirror only the distance-independent
        // dimensions (order-ACKNOWLEDGMENT speed — a device/UI action, not
        // travel — completion rate, and shortage handling) so anything built
        // from them (the team-comparison narrative below) can never compare
        // teams on dispatch-arrival time, which depends on how far each
        // team's point was and isn't a fair performance signal.
        $teamPillars = [];
        $teamResponseCalc = $orders ? missionScoreForgottenAwareSpeed($orders['ack_minutes'], $responseHalfLifeMinutes, $forgottenThresholdMinutes, $forgottenPenalty, true) : ['available' => false, 'score' => null, 'avg_minutes' => null, 'forgotten_count' => 0, 'unanswered_count' => 0, 'answered_rate' => null];
        if ($teamResponseCalc['available']) {
            $teamPillars['response'] = ['weight' => 45, 'available' => true, 'score' => $teamResponseCalc['score'], 'raw' => ['avg_minutes' => $teamResponseCalc['avg_minutes'], 'forgotten_count' => $teamResponseCalc['forgotten_count']]];
        } else {
            $teamPillars['response'] = ['weight' => 45, 'available' => false, 'score' => null, 'raw' => []];
        }
        if ($orders && $orders['count'] > 0) {
            $teamPillars['completion'] = ['weight' => 35, 'available' => true, 'score' => $orders['fulfill_count'] / $orders['count'] * 100, 'raw' => ['fulfilled' => $orders['fulfill_count'], 'total' => $orders['count']]];
        } else {
            $teamPillars['completion'] = ['weight' => 35, 'available' => false, 'score' => null, 'raw' => []];
        }
        if ($shortages && $shortages['count'] > 0) {
            $rate = $shortages['resolved'] / $shortages['count'] * 100;
            $teamPillars['shortage'] = ['weight' => 20, 'available' => true, 'score' => max(0, min(100, $rate - $shortages['unresolvedCritical'] * 15)), 'raw' => ['resolved' => $shortages['resolved'], 'total' => $shortages['count']]];
        } else {
            // Unavailable, NOT a neutral 100. The mission-wide pillar can
            // afford "nothing reported = nothing went wrong" because it is one
            // score standing alone; here the teams are ranked against each
            // other, and a free 100 on a fifth of the weight systematically
            // floated every team that reported nothing above every team that
            // reported real problems and fixed them. With it unavailable the
            // remaining weights renormalise (45/35 of 80), so a team is only
            // ever ranked on dimensions it actually has data for — the same
            // rule the mission-wide pillars have always followed. 'raw' keeps
            // its shape so existing total-based filters still read cleanly.
            $teamPillars['shortage'] = ['weight' => 20, 'available' => false, 'score' => null, 'raw' => ['resolved' => 0, 'total' => 0]];
        }
        // Always-available third dimension. Replaces 'shortage' as the radar
        // chart's third axis in mission-report-print.php: shortage data is
        // absent for most teams in most missions, so keying a comparison
        // chart on it drew almost nobody, while consistency is derived from
        // the very orders that qualified the team for the leaderboard.
        // Weight 0 — it deliberately does NOT move the team's score (that
        // would double-count the same acknowledgment times the response
        // pillar already grades); it exists to be described, ranked and
        // plotted.
        // Weight 25 alongside response 45 / completion 35 / shortage 20. It is
        // unavailable whenever a mission used neither Route Orders nor search
        // sectors, which is most of them — so for those missions the weights
        // renormalise back to exactly what they were before this pillar
        // existed, and no historical score shifts. The mission-wide score is
        // deliberately NOT given a matching pillar: it feeds the stored
        // computed_score and the same-type historical baseline, and re-weighting
        // it again would make those incomparable across this release.
        $teamPillars['discipline'] = ['weight' => 25, 'available' => $fwDiscipline['available'], 'score' => $fwDiscipline['score'], 'raw' => $fw];

        $teamConsistency = missionTeamConsistency($orders['ack_minutes'] ?? [], $forgottenThresholdMinutes);
        $teamPillars['consistency'] = ['weight' => 0, 'available' => $teamConsistency['available'], 'score' => $teamConsistency['score'], 'raw' => ['median' => $teamConsistency['median'], 'p90' => $teamConsistency['p90'], 'spread' => $teamConsistency['spread'], 'sample' => $teamConsistency['sample']]];

        $tWeightSum = 0;
        $tWeightedScore = 0.0;
        foreach ($teamPillars as $p) {
            if ($p['available']) {
                $tWeightSum += $p['weight'];
                $tWeightedScore += $p['weight'] * $p['score'];
            }
        }
        if ($tWeightSum === 0) continue;
        $tScore = round($tWeightedScore / $tWeightSum, 2);

        // Scores computed over different pillar sets are not comparable, and
        // the renormalise-on-unavailable rule means a team measured on one
        // narrow thing can post a perfect number. A team that walked four
        // waypoints and was never given a radio order scored 100.00 and
        // outranked a team that scored 96.73 across four pillars and nineteen
        // observations — which is not a finer-grained judgement, it is a
        // category error.
        //
        // So a single-pillar team is shown with its score but left out of the
        // ranking, exactly as computeMissionScore() already leaves $overall
        // null when no substantive pillar has data, and as
        // mission-report-print.php already refuses to draw a 1-2 axis radar
        // ("degenerate"). Its score is still real — it just is not a placing.
        // 'consistency' is excluded from the count: it is a weight-0
        // descriptive pillar and contributes nothing to $tScore, so counting
        // it would let a team qualify on evidence its score never saw.
        $scoringPillars = array_filter($teamPillars, fn($p, $k) => $p['available'] && $k !== 'consistency', ARRAY_FILTER_USE_BOTH);
        $isRanked = count($scoringPillars) >= 2;

        $teamScores[] = [
            'team_id'     => $tid,
            'codename'    => $team['codename'],
            'team_number' => $team['team_number'],
            'color'       => $team['color'] ?: '#898781',
            'score'       => $tScore,
            'tier'        => missionScoreTierMeta($tScore),
            'ranked'      => $isRanked,
            'pillar_count' => count($scoringPillars),
            'order_count' => $orders['count'] ?? 0,
            'pillars'     => $teamPillars,
            // ── narrative-only enrichment below. None of it feeds $tScore;
            //    it exists so generateTeamComparisonNarrative() can say WHY a
            //    team ranked where it did instead of only restating the rank.
            'shortage_count'   => $shortages['count'] ?? 0,
            'unanswered_count' => $teamResponseCalc['unanswered_count'] ?? 0,
            'answered_rate'    => $teamResponseCalc['answered_rate'],
            'forgotten_count'  => $teamResponseCalc['forgotten_count'] ?? 0,
            'consistency'      => $teamConsistency,
            'trajectory'       => missionTeamTrajectory($orders['timeline'] ?? [], $forgottenThresholdMinutes),
            'worst_order'      => $orders['worst'] ?? null,
            'unanswered_example' => $orders['unanswered_example'] ?? null,
            'fieldwork'        => $fw,
        ];
    }
    // Ranked teams first and numbered; unranked ones keep their score, sort
    // among themselves, and carry rank null so every renderer has to decide
    // what to show rather than silently printing a placing it did not earn.
    usort($teamScores, fn($a, $b) =>
        ($b['ranked'] <=> $a['ranked'])
        ?: ($b['score'] <=> $a['score'])
        ?: ($b['order_count'] <=> $a['order_count']));
    $place = 0;
    foreach ($teamScores as &$t) {
        $t['rank'] = $t['ranked'] ? ++$place : null;
    }
    unset($t);

    return [
        'overall'            => $overall,
        'tier'               => $overallTier,
        'pillars'            => $pillars,
        'teams'              => $teamScores,
        'metrics'            => $metrics,
        'command'            => $command,
        'forgotten_orders'   => $forgottenOrders,
        'historical'         => $historical,
        'response_histogram' => $responseHistogram,
    ];
}

/**
 * War Room: per-order-type breakdown (pie + bar chart + table) shared by
 * mission-stats.php and mission-report-print.php — previously two
 * byte-identical inline loops over $report['detail'], now one function so
 * the two pages can't drift. Forgotten-aware (missionSpeedBucketStats()) on
 * both the ack and fulfill side, unlike the plain mean it replaces. Operates
 * on the raw, unfiltered $detail rows computeMissionResponseReport() already
 * returns (canceled-participant rows included, same as the rest of that
 * archival dataset) — this changes only the averaging formula, never which
 * rows are counted.
 */
function computeMissionOrderTypeBreakdown(array $detail, int $thresholdMinutes): array {
    $byType = [];
    foreach ($detail as $row) {
        $t = $row['order_type'];
        if (!isset($byType[$t])) {
            $byType[$t] = ['label' => $row['type_label'], 'count' => 0, 'ack_minutes' => [], 'fulfill_minutes' => []];
        }
        $byType[$t]['count']++;
        if ($row['ack_minutes'] !== null) $byType[$t]['ack_minutes'][] = $row['ack_minutes'];
        if ($row['fulfill_minutes'] !== null) $byType[$t]['fulfill_minutes'][] = $row['fulfill_minutes'];
    }
    $result = [];
    foreach ($byType as $type => $s) {
        $ackStats = missionSpeedBucketStats($s['ack_minutes'], $thresholdMinutes);
        $fulfillStats = missionSpeedBucketStats($s['fulfill_minutes'], $thresholdMinutes);
        $result[] = [
            'order_type'              => $type,
            'label'                   => $s['label'],
            'count'                   => $s['count'],
            'avg_ack_minutes'         => $ackStats['avg_minutes'],
            'forgotten_ack_count'     => $ackStats['forgotten_count'],
            'avg_fulfill_minutes'     => $fulfillStats['avg_minutes'],
            'forgotten_fulfill_count' => $fulfillStats['forgotten_count'],
        ];
    }
    return $result;
}

/**
 * War Room: per-team order-response rollup (bar charts + print table) for
 * mission-stats.php/mission-report-print.php — same shape as
 * computeMissionResponseReport()'s own $summary, but with forgotten-aware
 * avg_ack_minutes/avg_fulfill_minutes instead of a plain mean. Deliberately
 * NOT folded into computeMissionResponseReport() itself: that function's
 * $summary is also read verbatim by mission-response-report.php's live
 * (STATUS_OPEN-gated) JSON endpoint, rendered directly inside war-room.php's
 * own "Αναφορά Χρόνων Απόκρισης" modal — mutating $summary's averaging
 * formula in place would silently change that live in-mission surface's
 * numbers too, with no forgotten-count context ever shown there, which is
 * out of scope here. Operates on the same raw, unfiltered $detail rows
 * $summary already uses (canceled-participant rows included) — only the
 * averaging formula differs, never which rows are counted.
 */
function computeMissionTeamSpeedBreakdown(array $detail, int $thresholdMinutes): array {
    $byTeam = [];
    foreach ($detail as $row) {
        $label = $row['team_label'];
        if (!isset($byTeam[$label])) {
            $byTeam[$label] = ['count' => 0, 'ack_count' => 0, 'fulfill_count' => 0, 'ack_minutes' => [], 'fulfill_minutes' => []];
        }
        $byTeam[$label]['count']++;
        if ($row['ack_minutes'] !== null) { $byTeam[$label]['ack_count']++; $byTeam[$label]['ack_minutes'][] = $row['ack_minutes']; }
        if ($row['fulfill_minutes'] !== null) { $byTeam[$label]['fulfill_count']++; $byTeam[$label]['fulfill_minutes'][] = $row['fulfill_minutes']; }
    }
    $result = [];
    foreach ($byTeam as $label => $s) {
        $ackStats = missionSpeedBucketStats($s['ack_minutes'], $thresholdMinutes);
        $fulfillStats = missionSpeedBucketStats($s['fulfill_minutes'], $thresholdMinutes);
        $result[] = [
            'team_label'              => $label,
            'order_count'             => $s['count'],
            'ack_rate'                => $s['count'] ? round($s['ack_count'] / $s['count'] * 100) : 0,
            'fulfill_rate'            => $s['count'] ? round($s['fulfill_count'] / $s['count'] * 100) : 0,
            'avg_ack_minutes'         => $ackStats['avg_minutes'],
            'forgotten_ack_count'     => $ackStats['forgotten_count'],
            'avg_fulfill_minutes'     => $fulfillStats['avg_minutes'],
            'forgotten_fulfill_count' => $fulfillStats['forgotten_count'],
        ];
    }
    usort($result, fn($a, $b) => $b['order_count'] <=> $a['order_count']);
    return $result;
}

/**
 * War Room: classifies a current-vs-historical minutes comparison into
 * better/worse/neutral at the same ±10% dead zone
 * generateMissionObserverNarrative()'s avg_ack-vs-historical sentence uses
 * (see its own dead-zone block below), so the hero-tile trend arrows
 * (mission-report-print.php) and that narrative sentence can never disagree
 * on the cutoff. Lower minutes is always "better" here (every caller is a
 * response/resolution-speed metric). Returns null when either side of the
 * comparison is missing.
 */
function missionMinutesTrend(?float $current, ?float $historical): ?array {
    if ($current === null || $historical === null || $historical <= 0) {
        return null;
    }
    $diffPct = round((($current - $historical) / $historical) * 100);
    if ($diffPct <= -10) return ['direction' => 'better', 'pct' => (int) abs($diffPct)];
    if ($diffPct >= 10) return ['direction' => 'worse', 'pct' => (int) $diffPct];
    return ['direction' => 'neutral', 'pct' => (int) $diffPct];
}

/**
 * War Room: per-pillar improvement suggestions, keyed the same as
 * computeMissionScore()'s $pillars. Extracted out of
 * generateMissionObserverNarrative() so generateWarRoomComparisonNarrative()
 * (reports.php's cross-mission trends tab) can cite the same advice text
 * instead of a second, independently-drifting copy.
 */
function missionScoreRecommendations(): array {
    return [
        'response'   => 'Εξετάστε συντομότερες υπενθυμίσεις (push notification) προς τις ομάδες όταν μια εντολή μένει αναπάντητη για μεγάλο διάστημα.',
        'completion' => 'Εξετάστε αν οι εντολές ήταν σαφείς και εφικτές εντός του διαθέσιμου χρόνου κάθε ομάδας.',
        'shortage'   => 'Εξετάστε ταχύτερη πρώτη ανταπόκριση στις αναφορές έλλειψης, ιδίως τις κρίσιμες.',
        'staffing'   => 'Εξετάστε αύξηση του αριθμού διαθέσιμων εθελοντών ή καλύτερη προ-δρομολόγηση βαρδιών στην επόμενη αποστολή.',
        'debrief'    => 'Εξετάστε πιο αναλυτική τεκμηρίωση των στόχων πριν την έναρξη της επόμενης αποστολής.',
    ];
}

/**
 * War Room: composes the "expert observer" paragraph for the score section —
 * one paragraph of Greek prose grounded in the actual pillar numbers (not
 * generic boilerplate), naming the strongest/weakest measured area and, for
 * a low score, explicitly recommending what to improve. Pure function of
 * computeMissionScore()'s own output — no new queries, always regenerated
 * live rather than stored, same "computed, not cached" philosophy as every
 * other number on this report.
 */
function missionScorePillarPhrase(string $key, array $pillar, bool $positive): string {
    $raw = $pillar['raw'] ?? [];
    switch ($key) {
        case 'response':
            $m = $raw['avg_minutes'] ?? null;
            if ($m === null) return '';
            return $positive
                ? "Ο μέσος χρόνος απόκρισης των ομάδων στις εντολές ήταν {$m} λεπτά, χρόνος που υποδηλώνει υψηλή ετοιμότητα και καλή ροή επικοινωνίας."
                : "Ο μέσος χρόνος απόκρισης των ομάδων στις εντολές έφτασε τα {$m} λεπτά, χρόνος αυξημένος για επιχειρησιακό περιβάλλον που απαιτεί άμεση αντίδραση.";
        case 'completion':
            $f = $raw['fulfilled'] ?? 0;
            $t = $raw['total'] ?? 0;
            if ($t === 0) return '';
            $rate = round($f / $t * 100);
            return $positive
                ? "Ολοκληρώθηκαν {$f} από τις {$t} εντολές ({$rate}%), δείχνοντας συνέπεια στην εκτέλεση του έργου που ανατέθηκε."
                : "Ολοκληρώθηκαν μόλις {$f} από τις {$t} εντολές ({$rate}%), ποσοστό που αφήνει σημαντικό αριθμό εντολών ημιτελείς ή αναπάντητες.";
        case 'shortage':
            $r = $raw['resolved'] ?? 0;
            $t = $raw['total'] ?? 0;
            if ($t === 0) return 'Δεν αναφέρθηκαν ελλείψεις κατά τη διάρκεια της αποστολής.';
            $rate = round($r / $t * 100);
            $uc = $raw['unresolved_critical'] ?? 0;
            $extra = '';
            if ($uc > 0) {
                $extra = ' Ιδιαίτερη ανησυχία προκαλεί το γεγονός ότι ' . ($uc === 1 ? 'μία κρίσιμη αναφορά παρέμεινε ανεπίλυτη' : "{$uc} κρίσιμες αναφορές παρέμειναν ανεπίλυτες") . '.';
            }
            return $positive
                ? "Λύθηκαν {$r} από τις {$t} αναφορές έλλειψης ({$rate}%), απόδειξη αποτελεσματικής διαχείρισης προβλημάτων στο πεδίο."
                : "Λύθηκαν μόνο {$r} από τις {$t} αναφορές έλλειψης ({$rate}%).{$extra}";
        case 'staffing':
            $a = $raw['approved'] ?? 0;
            $c = $raw['capacity'] ?? 0;
            if ($c === 0) return '';
            $rate = round($a / $c * 100);
            return $positive
                ? "Η κάλυψη βαρδιών ήταν επαρκής, με {$a} από {$c} διαθέσιμες θέσεις εθελοντών καλυμμένες ({$rate}%)."
                : "Η κάλυψη βαρδιών ήταν ανεπαρκής, με μόλις {$a} από {$c} διαθέσιμες θέσεις εθελοντών καλυμμένες ({$rate}%), γεγονός που περιόρισε τους διαθέσιμους πόρους στο πεδίο.";
        case 'debrief':
            $rt = $raw['rating'] ?? null;
            if ($rt === null) return '';
            $objText = ['YES' => 'πλήρως επιτεύχθηκαν', 'PARTIAL' => 'επιτεύχθηκαν εν μέρει', 'NO' => 'δεν επιτεύχθηκαν'][$raw['objectives'] ?? ''] ?? 'επιτεύχθηκαν εν μέρει';
            return $positive
                ? "Ο υπεύθυνος αποστολής βαθμολόγησε την άσκηση με {$rt}/5 στο debrief, αναφέροντας ότι οι στόχοι {$objText}."
                : "Ο υπεύθυνος αποστολής βαθμολόγησε την άσκηση με {$rt}/5 στο debrief, σημειώνοντας ότι οι στόχοι {$objText} — αυτοαξιολόγηση που επιβεβαιώνει τα περιθώρια βελτίωσης.";
    }
    return '';
}

function generateMissionObserverNarrative(array $score, string $missionTitle): string {
    if ($score['overall'] === null) {
        return 'Δεν υπάρχουν επαρκή δεδομένα (εντολές, βάρδιες ή αναφορά debrief) ώστε ο παρατηρητής να διατυπώσει τεκμηριωμένη αξιολόγηση για αυτή την αποστολή.';
    }
    $tier = $score['tier'][0];
    $overallFmt = number_format($score['overall'], 1);
    $title = $missionTitle !== '' ? " «{$missionTitle}»" : '';

    $openers = [
        'good'     => "Η άσκηση{$title} ολοκληρώθηκε με άριστη συνολική επίδοση ({$overallFmt}/100), αντανακλώντας αποτελεσματικό συντονισμό μεταξύ ομάδων και διοίκησης.",
        'warning'  => "Η άσκηση{$title} ολοκληρώθηκε με ικανοποιητική συνολική επίδοση ({$overallFmt}/100), με σαφή όμως περιθώρια βελτίωσης σε επιμέρους τομείς.",
        'critical' => "Η συνολική επίδοση της άσκησης{$title} ({$overallFmt}/100) υστερεί αισθητά από τον επιθυμητό στόχο και καταδεικνύει σοβαρά περιθώρια βελτίωσης.",
    ];
    $sentences = [$openers[$tier]];

    // Positive/negative phrasing is picked at a stricter 75-point bar than
    // the 65/85 tier-color bands — a "warning"-tier pillar (65-84) still
    // reads as "needs improvement" in prose, since a narrative benefits from
    // being a bit more discerning than a 3-color badge.
    $available = array_filter($score['pillars'], fn($p) => $p['available']);
    $weakest = null;
    if (count($available) >= 2) {
        uasort($available, fn($a, $b) => $a['score'] <=> $b['score']);
        $weakestKey = array_key_first($available);
        $strongestKey = array_key_last($available);
        $weakest = $available[$weakestKey];

        $strongPhrase = missionScorePillarPhrase($strongestKey, $available[$strongestKey], $available[$strongestKey]['score'] >= 75);
        if ($strongPhrase !== '') $sentences[] = $strongPhrase;
        if ($weakestKey !== $strongestKey) {
            $weakPhrase = missionScorePillarPhrase($weakestKey, $weakest, $weakest['score'] >= 75);
            if ($weakPhrase !== '') $sentences[] = $weakPhrase;
        }
    } elseif (count($available) === 1) {
        $onlyKey = array_key_first($available);
        $weakest = $available[$onlyKey];
        $onlyPhrase = missionScorePillarPhrase($onlyKey, $weakest, $weakest['score'] >= 75);
        if ($onlyPhrase !== '') $sentences[] = $onlyPhrase;
    }

    if ($score['historical']['avg_ack'] !== null && $score['metrics']['avg_ack'] !== null) {
        $hist = $score['historical']['avg_ack'];
        $diffPct = $hist > 0 ? round((($score['metrics']['avg_ack'] - $hist) / $hist) * 100) : 0;
        if ($diffPct <= -10) {
            $sentences[] = 'Ο μέσος χρόνος απόκρισης ήταν ' . abs($diffPct) . "% ταχύτερος από τον ιστορικό μέσο όρο ({$hist} λεπ.) για αποστολές ίδιου τύπου.";
        } elseif ($diffPct >= 10) {
            $sentences[] = 'Ο μέσος χρόνος απόκρισης ήταν ' . $diffPct . "% πιο αργός από τον ιστορικό μέσο όρο ({$hist} λεπ.) για αποστολές ίδιου τύπου.";
        } else {
            $sentences[] = "Ο μέσος χρόνος απόκρισης ήταν σε γενικές γραμμές στα ίδια επίπεδα με τον ιστορικό μέσο όρο ({$hist} λεπ.) για αποστολές ίδιου τύπου.";
        }
    }

    if (!empty($score['forgotten_orders'])) {
        $worst = $score['forgotten_orders'][0];
        $hours = round($worst['minutes'] / 60, 1);
        $extra = count($score['forgotten_orders']) > 1 ? ', εκ των οποίων δεν ήταν η μοναδική τέτοια περίπτωση.' : '.';
        $worstName = trim((string) ($worst['order_label'] ?? '')) !== '' ? $worst['order_label'] : $worst['label'];
        $sentences[] = "Ιδιαίτερη προσοχή χρειάζεται η εντολή «{$worstName}» προς {$worst['user_name']} ({$worst['team_label']}), η οποία έμεινε χωρίς επιβεβαίωση παραλαβής για περίπου {$hours} ώρες" . $extra;
    }

    $recommendations = missionScoreRecommendations();
    if ($tier === 'critical') {
        $weakKeys = [];
        foreach ($available as $key => $p) {
            if ($p['score'] < 65) $weakKeys[] = $key;
        }
        $weakKeys = array_slice($weakKeys, 0, 2);
        if (!empty($weakKeys)) {
            $weakLabels = array_map(fn($k) => $available[$k]['label'], $weakKeys);
            $sentences[] = 'Προτεραιότητα για μελλοντικές ασκήσεις θα πρέπει να αποτελέσει η βελτίωση σε: ' . implode(' και ', $weakLabels) . '.';
            foreach ($weakKeys as $k) {
                if (isset($recommendations[$k])) $sentences[] = $recommendations[$k];
            }
        }
    } elseif ($tier === 'warning' && $weakest !== null) {
        $sentences[] = "Ο τομέας «{$weakest['label']}» παραμένει ο πιο αδύναμος κρίκος και αξίζει ιδιαίτερη προσοχή στην επόμενη άσκηση.";
        $weakestKeyFinal = array_search($weakest, $available, true);
        if ($weakestKeyFinal !== false && isset($recommendations[$weakestKeyFinal])) $sentences[] = $recommendations[$weakestKeyFinal];
    } elseif ($tier === 'good') {
        if ($weakest !== null && $weakest['score'] < 85) {
            $sentences[] = "Παρά τη συνολικά άριστη εικόνα, ο τομέας «{$weakest['label']}» υπολείπεται ελαφρώς των υπολοίπων και θα μπορούσε να βελτιωθεί περαιτέρω.";
        } else {
            $sentences[] = 'Δεν εντοπίζονται αδυναμίες που να απαιτούν άμεση παρέμβαση.';
        }
    }

    $unavailable = array_filter($score['pillars'], fn($p) => !$p['available']);
    if (!empty($unavailable)) {
        $names = array_map(fn($p) => $p['label'], $unavailable);
        $sentences[] = 'Σημειώνεται ότι η αξιολόγηση δεν περιλαμβάνει: ' . implode(', ', $names) . ', λόγω έλλειψης σχετικών δεδομένων.';
    }

    return implode(' ', $sentences);
}

/**
 * War Room: the shorter companion paragraph for the new, deliberately
 * separate "command responsiveness" evaluation ($score['command']) — how
 * fast command staff saw and resolved teams' shortage reports, as judged on
 * its own rather than folded into the team-facing overall score.
 */
function generateCommandNarrative(array $command): string {
    if (!$command['available']) {
        return 'Δεν έχουν υποβληθεί αναφορές έλλειψης κατά τη διάρκεια της αποστολής, συνεπώς δεν υπάρχουν επαρκή δεδομένα για αξιολόγηση του χρόνου ανταπόκρισης της διοίκησης.';
    }
    $seenTxt = $command['avg_seen'] !== null ? number_format($command['avg_seen'], 1) . ' λεπτά' : '—';
    $resolvedTxt = $command['avg_resolved'] !== null ? number_format($command['avg_resolved'], 1) . ' λεπτά' : '—';
    $tier = $command['tier'][0];

    $base = "Η διοίκηση ανταποκρίθηκε στις {$command['total_reports']} αναφορές έλλειψης των ομάδων σε μέσο χρόνο παρατήρησης {$seenTxt} και προχώρησε σε επίλυση εντός {$resolvedTxt} κατά μέσο όρο.";
    $verdicts = [
        'good'     => 'Η ταχύτητα αυτή αντανακλά αποτελεσματική επιχειρησιακή εποπτεία και άμεση διαθεσιμότητα της διοίκησης.',
        'warning'  => 'Ο χρόνος αυτός είναι αποδεκτός, ωστόσο ταχύτερη πρώτη αντίδραση στις αναφορές θα ενίσχυε την επιχειρησιακή εικόνα.',
        'critical' => 'Ο χρόνος αυτός κρίνεται αυξημένος και ενδέχεται να έχει επιβαρύνει τη διαχείριση προβλημάτων στο πεδίο — συνιστάται στενότερη παρακολούθηση του καναλιού αναφορών σε επόμενες ασκήσεις.',
    ];
    $sentences = [$base, $verdicts[$tier]];

    if (!empty($command['forgotten_incidents'])) {
        $worst = $command['forgotten_incidents'][0];
        $hours = round($worst['minutes'] / 60, 1);
        $sentences[] = "Ιδιαίτερα αξιοσημείωτη είναι η αναφορά «{$worst['title']}» από {$worst['reporter_name']} ({$worst['team_label']}), η οποία παρέμεινε χωρίς παρατήρηση για περίπου {$hours} ώρες.";
    }

    return implode(' ', $sentences);
}

/**
 * War Room: the team-vs-team comparison paragraph appended after the main
 * observer narrative.
 *
 * Structure is a salience-ranked finding pool, not a fixed sentence order.
 * Every candidate observation is generated with a 0-100 salience and only the
 * six strongest are printed, so a mission where the teams differed mainly in
 * consistency reads differently from one where they differed mainly in speed
 * — the previous version emitted the same four slots in the same order every
 * time, which made every report read alike and buried the one thing that
 * actually mattered. Salience is computed from effect size rather than raw
 * difference wherever the two diverge (speed uses the ratio, not the minute
 * gap: 2-vs-8 minutes is a fourfold gap in reflexes while 40-vs-46 is noise,
 * yet both differ by six minutes).
 *
 * The opening (leaderboard spread) and the closing sample caveat sit outside
 * the ranking on purpose: the first is the topic sentence and the last
 * qualifies everything before it, so neither should have to compete for a
 * slot.
 *
 * Fieldwork findings (route stops, assigned sectors, restricted-area
 * breaches) join the same pool and compete on salience like everything else.
 * Two of them never compete on equal terms by design: a restricted-area breach
 * enters high because it is a safety matter rather than a performance one, and
 * an SOS or casualty incident enters high because it EXPLAINS a team's other
 * numbers — a crew that ran a mayday will look slow on every speed metric in
 * this report, and a debrief that does not know that is an unfair one. Neither
 * SOS alerts nor incidents touch any pillar; see computeMissionTeamFieldwork().
 *
 * $alreadyCited is the caller's $score['forgotten_orders']. Both callers print
 * generateMissionObserverNarrative() immediately above this paragraph, and
 * that function names forgotten_orders[0] by name — so without this the same
 * order gets called out twice, one sentence apart, which reads as two separate
 * incidents. Passing it in lets this paragraph spend the slot on something the
 * reader has not already been told.
 *
 * Deliberately compares teams ONLY on dimensions that
 * are fair regardless of geography — order-ACKNOWLEDGMENT speed (a
 * device/UI action: tapping "Ελήφθη" doesn't require traveling anywhere),
 * completion RATE (measures follow-through, not raw travel time), and
 * shortage-report resolution. It never compares raw dispatch arrival/travel
 * time between teams, since a team sent to a point 1 hour away isn't
 * performing worse than one sent 10 minutes away — that's geography, not
 * diligence. (Dispatch arrival times stay visible in the existing
 * tables/charts as neutral per-team facts, just never used for a
 * comparative claim here.) Takes $score['teams'] (already ranked, each with
 * its own $pillars sub-array carrying the same 'raw' shape as the
 * mission-wide pillars).
 */
function generateTeamComparisonNarrative(array $teams, array $alreadyCited = []): string {
    if (count($teams) < 2) {
        return '';
    }
    $label = fn($t) => teamLabel($t['codename'], $t['team_number']);
    $fmt = fn($n) => rtrim(rtrim(number_format((float) $n, 1, '.', ''), '0'), '.');

    // Every candidate observation lands here as ['salience' => 0-100, 'text']
    // and only the strongest few survive — see this function's docblock for
    // why the order is earned rather than fixed.
    $findings = [];
    // FILLER_SALIENCE sits below MIN_REAL_SALIENCE so a “the teams were much
    // the same” sentence can never displace an observation that actually
    // distinguishes two teams, however small that distinction is. Without the
    // floor a marginal-but-real speed gap scored 5.6 and lost its slot to a
    // fixed-value filler scored 12.
    $FILLER_SALIENCE = 5.0;
    $MIN_REAL_SALIENCE = 10.0;
    $findings = [];
    $add = function (float $salience, string $text) use (&$findings, $MIN_REAL_SALIENCE) {
        if ($text !== '') $findings[] = ['salience' => max($MIN_REAL_SALIENCE, $salience), 'text' => $text];
    };
    $addFiller = function (string $text) use (&$findings, $FILLER_SALIENCE) {
        $findings[] = ['salience' => $FILLER_SALIENCE, 'text' => $text];
    };

    // ── opening: the spread of the leaderboard itself ────────────────────
    //    Overall scores computed over different pillar sets are not comparable
    //    (see computeMissionScore()'s ranking gate), so this sentence and the
    //    contenders clause below use ranked teams only. Every dimension-
    //    specific finding further down is unaffected: those compare two teams
    //    on one measure that both of them actually have.
    //    A team array without the key at all is treated as ranked: the flag is
    //    set by computeMissionScore(), and a future caller that assembles
    //    teams some other way should get the normal paragraph rather than
    //    silently collapse to the "cannot be ranked" one.
    $isRankedTeam = fn($t) => !array_key_exists('ranked', $t) || !empty($t['ranked']);
    $rankedTeams = array_values(array_filter($teams, $isRankedTeam));
    $unranked = array_values(array_filter($teams, fn($t) => !$isRankedTeam($t)));
    if (count($rankedTeams) < 2) {
        // Nothing may be said about relative standing. Report what each team
        // was measured on instead of inventing a comparison.
        if (empty($unranked)) return '';
        $bits = array_map(fn($t) => $label($t) . ' (' . number_format($t['score'], 1) . ')', $teams);
        return 'Δεν προκύπτει ασφαλής κατάταξη μεταξύ των ομάδων: η κάθε μία αξιολογήθηκε σε διαφορετική βάση δεδομένων και οι βαθμοί τους δεν είναι μεταξύ τους συγκρίσιμοι — ' . implode(', ', $bits) . '.';
    }
    $byScore = $rankedTeams;
    usort($byScore, fn($a, $b) => $b['score'] <=> $a['score']);
    $top = $byScore[0];
    $bottom = $byScore[count($byScore) - 1];
    $topLabel = $label($top);
    $bottomLabel = $label($bottom);
    $gap = round($top['score'] - $bottom['score'], 1);
    if ($gap < 0.05) {
        // A literal dead heat otherwise printed "διαφορά μόλις 0 μονάδων",
        // which is a strange way to say the teams tied — and it happens often
        // enough to matter (every team scoring 0, or every team scoring 100).
        $opening = 'Όλες οι ομάδες κατέληξαν σε πρακτικά ταυτόσημη βαθμολογία (' . number_format($top['score'], 1) . '/100), οπότε η κατάταξη από μόνη της δεν ξεχωρίζει καμία.';
    } elseif ($gap < 5) {
        $opening = "Η συνολική βαθμολογία των ομάδων ήταν ιδιαίτερα ομοιογενής, με διαφορά μόλις {$gap} μονάδων μεταξύ {$topLabel} και {$bottomLabel}.";
    } elseif ($gap < 20) {
        $opening = "Υπήρξε μέτρια απόκλιση {$gap} μονάδων μεταξύ της κορυφαίας ομάδας ({$topLabel}) και της {$bottomLabel}.";
    } else {
        $opening = "Η απόσταση βαθμολογίας μεταξύ της κορυφαίας ομάδας ({$topLabel}, " . number_format($top['score'], 1) . ") και της {$bottomLabel} (" . number_format($bottom['score'], 1) . ") έφτασε τις {$gap} μονάδες, υποδεικνύοντας σημαντική ανομοιογένεια στην απόδοση μεταξύ των ομάδων.";
    }

    // ── who else was in contention — stops a 5-team mission from being
    //    described entirely through its best and its worst team ───────────
    if (count($byScore) >= 3 && $gap >= 0.05) {
        $contenders = [];
        foreach (array_slice($byScore, 1, count($byScore) - 2) as $t) {
            if ($top['score'] - $t['score'] <= 5) $contenders[] = $label($t);
        }
        if (!empty($contenders)) {
            $add(45, 'Στα ίδια επίπεδα με την κορυφαία κινήθηκαν και ' . implode(', ', $contenders) . (count($contenders) === 1 ? ' (εντός 5 μονάδων).' : ' (εντός 5 μονάδων η κάθε μία).'));
        }
    }

    // ── orders left with no acknowledgment at all ────────────────────────
    $unanswered = array_values(array_filter($teams, fn($t) => ($t['unanswered_count'] ?? 0) > 0));
    if (!empty($unanswered)) {
        usort($unanswered, fn($a, $b) => $b['unanswered_count'] <=> $a['unanswered_count']);
        $w = $unanswered[0];
        $n = $w['unanswered_count'];
        $total = $w['order_count'];
        $sentence = 'Η ομάδα ' . $label($w) . ' άφησε ' . ($n === 1 ? 'μία' : $n) . " από τις {$total} εντολές της χωρίς καμία απάντηση"
            . ($n === 1 ? '' : ' (' . round($n / max(1, $total) * 100) . '% του συνόλου της)')
            . ' — κενό βαρύτερο από μια απλή καθυστέρηση, καθώς η διοίκηση δεν έλαβε ποτέ επιβεβαίωση παραλαβής.';
        if (!empty($w['unanswered_example'])) {
            $ex = $w['unanswered_example'];
            // An order can carry neither task_text nor a resolvable type label
            // (seen on real data). Naming it then reads as "η εντολή  προς X",
            // so the clause drops the identifier rather than the callout.
            $what = trim(!empty($ex['order_label']) ? '«' . $ex['order_label'] . '»' : (string) ($ex['label'] ?? ''));
            $sentence .= $what !== ''
                ? " Χαρακτηριστική περίπτωση η εντολή {$what} προς {$ex['user_name']}."
                : " Μεταξύ αυτών και εντολή προς {$ex['user_name']}.";
        }
        if (count($unanswered) > 1) {
            $sentence .= ' Αναπάντητες εντολές κατέγραψαν συνολικά ' . count($unanswered) . ' ομάδες.';
        }
        $add(min(100.0, 55 + $n * 12), $sentence);
    }

    // ── response speed (order acknowledgment) ───────────────────────────
    $withResponse = array_values(array_filter($teams, fn($t) => $t['pillars']['response']['available'] && $t['pillars']['response']['raw']['avg_minutes'] !== null));
    if (count($withResponse) >= 2) {
        usort($withResponse, fn($a, $b) => $a['pillars']['response']['raw']['avg_minutes'] <=> $b['pillars']['response']['raw']['avg_minutes']);
        $fastest = $withResponse[0];
        $slowest = $withResponse[count($withResponse) - 1];
        $fMin = (float) $fastest['pillars']['response']['raw']['avg_minutes'];
        $sMin = (float) $slowest['pillars']['response']['raw']['avg_minutes'];
        if ($label($fastest) === $label($slowest) || abs($fMin - $sMin) < 0.5) {
            $addFiller('Ως προς την ταχύτητα αποδοχής εντολών, οι ομάδες παρουσίασαν παρόμοια απόδοση.');
        } else {
            // Ratio, not absolute difference: 2 vs 8 minutes is a fourfold gap
            // in operational reflexes while 40 vs 46 is noise, yet the two
            // differ by the same handful of minutes.
            $ratio = $sMin / max(0.5, $fMin);
            $extra = $ratio >= 2 ? ' — διαφορά ' . $fmt($ratio) . ' φορές.' : '.';
            $add(min(100.0, ($ratio - 1) * 45), 'Ως προς την ταχύτητα αποδοχής εντολών, η ομάδα ' . $label($fastest) . ' ξεχώρισε με μέσο χρόνο ' . $fmt($fMin) . ' λεπτών, έναντι ' . $fmt($sMin) . ' λεπτών της ' . $label($slowest) . $extra);
        }
    }

    // ── consistency: the thing an average structurally cannot show ───────
    $withConsistency = array_values(array_filter($teams, fn($t) => !empty($t['consistency']['available'])));
    if (count($withConsistency) >= 2) {
        usort($withConsistency, fn($a, $b) => $a['consistency']['spread'] <=> $b['consistency']['spread']);
        $steady = $withConsistency[0];
        $erratic = $withConsistency[count($withConsistency) - 1];
        if ($erratic['consistency']['spread'] >= 2.5 && $erratic['consistency']['spread'] > $steady['consistency']['spread'] * 1.5) {
            $add(min(100.0, ($erratic['consistency']['spread'] - 1) * 22),
                'Πέρα από τον μέσο όρο, η ομάδα ' . $label($steady) . ' κράτησε σταθερό ρυθμό (διάμεσος ' . $fmt($steady['consistency']['median']) . ' λεπτά, με το 90% των αποκρίσεών της εντός ' . $fmt($steady['consistency']['p90']) . ' λεπτών), ενώ η ομάδα ' . $label($erratic) . ' κινήθηκε ανομοιόμορφα (διάμεσος ' . $fmt($erratic['consistency']['median']) . ' λεπτά, αλλά αποκρίσεις που έφταναν τα ' . $fmt($erratic['consistency']['p90']) . ' λεπτά) — στοιχείο που ο μέσος όρος από μόνος του δεν αποκαλύπτει.');
        }
    }

    // ── did the pace hold, or did the team fade? ─────────────────────────
    $moved = array_values(array_filter($teams, fn($t) => !empty($t['trajectory']['available']) && $t['trajectory']['direction'] !== 'steady'));
    if (!empty($moved)) {
        usort($moved, fn($a, $b) => abs($b['trajectory']['change_pct']) <=> abs($a['trajectory']['change_pct']));
        $m = $moved[0];
        $tr = $m['trajectory'];
        $pct = abs($tr['change_pct']);
        // Past ~3x the eye stops reading a percentage as a quantity — “1829%
        // πιο αργά” is technically right and operationally meaningless, where
        // “19 φορές πιο αργά” lands immediately.
        $slower = $tr['direction'] === 'worse'
            ? ($tr['second_avg'] / max(0.1, (float) $tr['first_avg']))
            : ($tr['first_avg'] / max(0.1, (float) $tr['second_avg']));
        $magnitude = $pct >= 200 ? $fmt(round($slower, 1)) . ' φορές' : $pct . '%';
        $add(min(100.0, $pct * 0.8), $tr['direction'] === 'worse'
            ? 'Η ομάδα ' . $label($m) . ' έχασε ρυθμό κατά τη διάρκεια της αποστολής: από ' . $fmt($tr['first_avg']) . ' λεπτά μέσο χρόνο απόκρισης στο πρώτο μισό των εντολών της, πήγε στα ' . $fmt($tr['second_avg']) . " λεπτά στο δεύτερο ({$magnitude} πιο αργά) — ένδειξη κόπωσης ή χαλάρωσης της επικοινωνίας προς το τέλος."
            : 'Η ομάδα ' . $label($m) . ' βελτιώθηκε καθώς προχωρούσε η αποστολή, από ' . $fmt($tr['first_avg']) . ' σε ' . $fmt($tr['second_avg']) . " λεπτά μέσο χρόνο απόκρισης ({$magnitude} ταχύτερα στο δεύτερο μισό των εντολών της).");
    }

    // ── completion rate ─────────────────────────────────────────────────
    $withCompletion = array_values(array_filter($teams, fn($t) => $t['pillars']['completion']['available']));
    if (count($withCompletion) >= 2) {
        $rates = array_map(function ($t) use ($label) {
            $raw = $t['pillars']['completion']['raw'];
            return ['label' => $label($t), 'rate' => $raw['total'] ? round($raw['fulfilled'] / $raw['total'] * 100) : 0, 'raw' => $raw];
        }, $withCompletion);
        usort($rates, fn($a, $b) => $b['rate'] <=> $a['rate']);
        $best = $rates[0];
        $worst = $rates[count($rates) - 1];
        if ($best['label'] === $worst['label'] || abs($best['rate'] - $worst['rate']) < 10) {
            $addFiller('Στο ποσοστό ολοκλήρωσης εντολών, οι ομάδες κινήθηκαν σε παρόμοια επίπεδα.');
        } else {
            $add(min(100.0, (float) ($best['rate'] - $worst['rate'])), "Στο ποσοστό ολοκλήρωσης εντολών, η {$best['label']} πέτυχε {$best['rate']}% ({$best['raw']['fulfilled']}/{$best['raw']['total']}), έναντι {$worst['rate']}% ({$worst['raw']['fulfilled']}/{$worst['raw']['total']}) της {$worst['label']}.");
        }
    }

    // ── shortage handling — only teams that actually reported >=1, so a team
    //    with zero reports is never compared against a team that genuinely
    //    resolved real reports ─────────────────────────────────────────────
    $withShortage = array_values(array_filter($teams, fn($t) => $t['pillars']['shortage']['raw']['total'] > 0));
    if (count($withShortage) >= 2) {
        $rates = array_map(function ($t) use ($label) {
            $raw = $t['pillars']['shortage']['raw'];
            return ['label' => $label($t), 'rate' => round($raw['resolved'] / $raw['total'] * 100), 'raw' => $raw];
        }, $withShortage);
        usort($rates, fn($a, $b) => $b['rate'] <=> $a['rate']);
        $best = $rates[0];
        $worst = $rates[count($rates) - 1];
        if ($best['label'] !== $worst['label'] && $best['rate'] !== $worst['rate']) {
            $add(min(100.0, (float) ($best['rate'] - $worst['rate'])), "Στη διαχείριση αναφορών έλλειψης, η {$best['label']} έλυσε {$best['raw']['resolved']}/{$best['raw']['total']} αναφορές, ενώ η {$worst['label']} μόλις {$worst['raw']['resolved']}/{$worst['raw']['total']}.");
        }
    }

    // ── a forgotten-but-eventually-answered order, named ─────────────────
    $lateTeams = array_values(array_filter($teams, fn($t) => ($t['forgotten_count'] ?? 0) > 0 && !empty($t['worst_order'])));
    // Drop any team whose worst order is the one the mission-wide paragraph
    // has already named (it names only forgotten_orders[0]); matching on
    // recipient + minutes is enough to identify a single order here.
    $cited = $alreadyCited[0] ?? null;
    if ($cited !== null) {
        $lateTeams = array_values(array_filter($lateTeams, fn($t) =>
            !($t['worst_order']['user_name'] === $cited['user_name']
              && abs($t['worst_order']['minutes'] - $cited['minutes']) < 0.5)));
    }
    if (!empty($lateTeams)) {
        usort($lateTeams, fn($a, $b) => $b['worst_order']['minutes'] <=> $a['worst_order']['minutes']);
        $lt = $lateTeams[0];
        $wo = $lt['worst_order'];
        $hours = round($wo['minutes'] / 60, 1);
        $what = trim(!empty($wo['order_label']) ? '«' . $wo['order_label'] . '»' : (string) ($wo['label'] ?? ''));
        $add(min(100.0, 40 + $hours * 4), $what !== ''
            ? 'Στην ομάδα ' . $label($lt) . " η εντολή {$what} προς {$wo['user_name']} χρειάστηκε περίπου {$hours} ώρες για να επιβεβαιωθεί, τη μεγαλύτερη καθυστέρηση της αποστολής."
            : 'Στην ομάδα ' . $label($lt) . " μία εντολή προς {$wo['user_name']} χρειάστηκε περίπου {$hours} ώρες για να επιβεβαιωθεί, τη μεγαλύτερη καθυστέρηση της αποστολής.");
    }

    // ── fieldwork: what the team physically did ─────────────────────────
    $fwOf = fn($t) => $t['fieldwork'] ?? null;

    // Walking into a restricted area outranks almost everything else here.
    $breached = array_values(array_filter($teams, fn($t) => ($fwOf($t)['breaches'] ?? 0) > 0));
    if (!empty($breached)) {
        usort($breached, fn($a, $b) => $b['fieldwork']['breaches'] <=> $a['fieldwork']['breaches']);
        $b = $breached[0];
        $n = $b['fieldwork']['breaches'];
        $area = trim((string) ($b['fieldwork']['breach_area'] ?? ''));
        $where = $area !== '' ? " («{$area}»)" : '';
        $add(min(100.0, 60 + $n * 12), 'Η ομάδα ' . $label($b) . ($n === 1 ? " κατέγραψε είσοδο σε απαγορευμένη ζώνη{$where}." : " κατέγραψε {$n} εισόδους σε απαγορευμένη ζώνη{$where}.")
            . ' Ανεξάρτητα από τη βαθμολογία, αυτό αφορά την ασφάλεια και αξίζει να συζητηθεί στο debrief.');
    }

    // Route stops: untouched is the real failure, a reported skip is not.
    $withRoute = array_values(array_filter($teams, fn($t) => ($fwOf($t)['waypoints_total'] ?? 0) > 0));
    if (count($withRoute) >= 2) {
        $rates = array_map(function ($t) use ($label) {
            $f = $t['fieldwork'];
            return ['label' => $label($t), 'rate' => round($f['waypoints_completed'] / $f['waypoints_total'] * 100), 'f' => $f];
        }, $withRoute);
        usort($rates, fn($a, $b) => $b['rate'] <=> $a['rate']);
        $best = $rates[0];
        $worst = $rates[count($rates) - 1];
        if ($best['label'] !== $worst['label'] && $best['rate'] - $worst['rate'] >= 15) {
            $add(min(100.0, (float) ($best['rate'] - $worst['rate'])),
                "Στα σημεία πορείας, η {$best['label']} έκλεισε {$best['f']['waypoints_completed']}/{$best['f']['waypoints_total']} ({$best['rate']}%), έναντι {$worst['f']['waypoints_completed']}/{$worst['f']['waypoints_total']} ({$worst['rate']}%) της {$worst['label']}.");
        }
    }

    $untouched = array_values(array_filter($teams, fn($t) => ($fwOf($t)['waypoints_untouched'] ?? 0) > 0));
    if (!empty($untouched)) {
        usort($untouched, fn($a, $b) => $b['fieldwork']['waypoints_untouched'] <=> $a['fieldwork']['waypoints_untouched']);
        $u = $untouched[0];
        $f = $u['fieldwork'];
        $n = $f['waypoints_untouched'];
        $add(min(100.0, 45 + $n * 10), 'Η ομάδα ' . $label($u) . ' άφησε ' . ($n === 1 ? 'ένα σημείο' : "{$n} σημεία") . " της πορείας της χωρίς καμία ενέργεια — ούτε ολοκλήρωση ούτε δήλωση παράλειψης, οπότε ο χάρτης έδειχνε εκκρεμότητα που στην πραγματικότητα δεν παρακολουθούσε κανείς.");
    }

    // A skip WITH a reason is good practice being surfaced, not a complaint.
    $skipped = array_values(array_filter($teams, fn($t) => ($fwOf($t)['waypoints_skipped_with_reason'] ?? 0) > 0 && !empty($fwOf($t)['skip_reason_example'])));
    if (!empty($skipped)) {
        usort($skipped, fn($a, $b) => $b['fieldwork']['waypoints_skipped_with_reason'] <=> $a['fieldwork']['waypoints_skipped_with_reason']);
        $sk = $skipped[0];
        $f = $sk['fieldwork'];
        $ex = $f['skip_reason_example'];
        $n = $f['waypoints_skipped_with_reason'];
        $add(min(100.0, 25 + $n * 8), 'Η ομάδα ' . $label($sk) . ' δήλωσε ρητά ' . ($n === 1 ? 'μία παράλειψη σημείου' : "{$n} παραλείψεις σημείων") . " με αιτιολόγηση (π.χ. «{$ex['waypoint']}»: {$ex['reason']}) — πρακτική που κρατά την εικόνα του χάρτη ειλικρινή και προτιμότερη από ένα σημείο που μένει σιωπηλά ανοιχτό.");
    }

    $outOfSeq = array_values(array_filter($teams, fn($t) => ($fwOf($t)['out_of_sequence'] ?? 0) > 0));
    if (!empty($outOfSeq)) {
        usort($outOfSeq, fn($a, $b) => $b['fieldwork']['out_of_sequence'] <=> $a['fieldwork']['out_of_sequence']);
        $o = $outOfSeq[0];
        $n = $o['fieldwork']['out_of_sequence'];
        $add(min(100.0, 20 + $n * 10), 'Η ομάδα ' . $label($o) . ' προσπέλασε ' . ($n === 1 ? 'ένα σημείο' : "{$n} σημεία") . ' εκτός της σειράς της πορείας. Δεν είναι από μόνο του σφάλμα — το σύστημα το επιτρέπει μετά από επιβεβαίωση — αλλά αξίζει να επιβεβαιωθεί ότι ήταν συνειδητή επιλογή και όχι σύγχυση για το πού βρισκόταν η ομάδα.');
    }

    // Assigned search sectors.
    $withSectors = array_values(array_filter($teams, fn($t) => ($fwOf($t)['sectors_assigned'] ?? 0) > 0));
    if (count($withSectors) >= 2) {
        $rates = array_map(function ($t) use ($label) {
            $f = $t['fieldwork'];
            return ['label' => $label($t), 'rate' => round($f['sectors_completed'] / $f['sectors_assigned'] * 100), 'f' => $f];
        }, $withSectors);
        usort($rates, fn($a, $b) => $b['rate'] <=> $a['rate']);
        $best = $rates[0];
        $worst = $rates[count($rates) - 1];
        if ($best['label'] !== $worst['label'] && $best['rate'] !== $worst['rate']) {
            $add(min(100.0, (float) ($best['rate'] - $worst['rate'])),
                "Στην κάλυψη ανατεθειμένων τομέων, η {$best['label']} ολοκλήρωσε {$best['f']['sectors_completed']}/{$best['f']['sectors_assigned']}, ενώ η {$worst['label']} {$worst['f']['sectors_completed']}/{$worst['f']['sectors_assigned']}. Η δυσκολία ενός τομέα δεν είναι σταθερή, οπότε το νούμερο διαβάζεται μαζί με το μέγεθος και το έδαφος που ανατέθηκε στην κάθε ομάδα.");
        }
    }
    $recheck = array_values(array_filter($teams, fn($t) => ($fwOf($t)['sectors_needs_recheck'] ?? 0) > 0));
    if (!empty($recheck)) {
        $names = array_map($label, $recheck);
        $totalRecheck = array_sum(array_map(fn($t) => $t['fieldwork']['sectors_needs_recheck'], $recheck));
        $add(min(100.0, 35 + $totalRecheck * 10), ($totalRecheck === 1 ? 'Ένας τομέας σημάνθηκε' : "{$totalRecheck} τομείς σημάνθηκαν") . ' για επανέλεγχο (' . implode(', ', $names) . '), εκκρεμότητα που πρέπει να μεταφερθεί στην επόμενη βάρδια αντί να κλείσει μαζί με την αποστολή.');
    }

    // ── context that EXPLAINS the numbers above rather than scoring them ──
    //    A team that ran a mayday or worked a casualty will look slow on every
    //    speed metric in this report, and saying so is the difference between
    //    a fair debrief and an unfair one. Never phrased as a fault, and
    //    deliberately absent from every pillar — see computeMissionTeamFieldwork().
    $disrupted = array_values(array_filter($teams, fn($t) => (($fwOf($t)['sos_alerts'] ?? 0) + ($fwOf($t)['incidents'] ?? 0)) > 0));
    if (!empty($disrupted)) {
        usort($disrupted, fn($a, $b) =>
            ($b['fieldwork']['sos_alerts'] * 2 + $b['fieldwork']['incidents'])
            <=> ($a['fieldwork']['sos_alerts'] * 2 + $a['fieldwork']['incidents']));
        $d = $disrupted[0];
        $f = $d['fieldwork'];
        $bits = [];
        if ($f['sos_alerts'] > 0) $bits[] = $f['sos_alerts'] === 1 ? 'σήμανε συναγερμό SOS' : "σήμανε {$f['sos_alerts']} συναγερμούς SOS";
        if ($f['incidents'] > 0) $bits[] = $f['incidents'] === 1 ? 'διαχειρίστηκε ένα περιστατικό τραυματισμού' : "διαχειρίστηκε {$f['incidents']} περιστατικά τραυματισμού";
        $add(min(100.0, 50 + $f['sos_alerts'] * 20 + $f['incidents'] * 10),
            'Η ομάδα ' . $label($d) . ' ' . implode(' και ', $bits) . ' κατά τη διάρκεια της αποστολής. Αυτό δεν επηρεάζει τη βαθμολογία της, αλλά εξηγεί σε μεγάλο βαθμό τυχόν καθυστερήσεις της στους υπόλοιπους δείκτες και πρέπει να ληφθεί υπόψη πριν συγκριθεί με τις άλλες ομάδες.');
    }

    $contributors = array_values(array_filter($teams, fn($t) => ($fwOf($t)['poi_photos'] ?? 0) > 0));
    if (!empty($contributors)) {
        usort($contributors, fn($a, $b) => $b['fieldwork']['poi_photos'] <=> $a['fieldwork']['poi_photos']);
        $c = $contributors[0];
        $n = $c['fieldwork']['poi_photos'];
        $add(22, 'Η ομάδα ' . $label($c) . ' κατέγραψε ' . ($n === 1 ? 'ένα ευρήμα' : "{$n} ευρήματα") . ' με φωτογραφική τεκμηρίωση στο πεδίο. Ο αριθμός ευρημάτων δεν βαθμολογείται — εξαρτάται από το τι υπήρχε στον τομέα της κάθε ομάδας, όχι από το πόσο καλά έψαξε.');
    }

    // ── workload distribution: a command-side finding, stated as such ────
    $counts = array_map(fn($t) => $t['order_count'], $teams);
    $maxOrders = max($counts);
    $minOrders = min($counts);
    if ($minOrders > 0 && $maxOrders / $minOrders >= 2.5) {
        $busiest = $teams[array_search($maxOrders, $counts, true)];
        $quietest = $teams[array_search($minOrders, $counts, true)];
        $add(min(100.0, ($maxOrders / $minOrders - 1) * 20), 'Ο φόρτος μοιράστηκε άνισα: η ομάδα ' . $label($busiest) . " δέχτηκε {$maxOrders} εντολές, ενώ η ομάδα " . $label($quietest) . " μόλις {$minOrders}. Αυτό αφορά τον σχεδιασμό της διοίκησης περισσότερο από την απόδοση των ομάδων, και καθιστά τη μεταξύ τους σύγκριση λιγότερο ασφαλή.");
    }

    usort($findings, fn($a, $b) => $b['salience'] <=> $a['salience']);
    // Six was the cap when the pool held at most eight candidates. Fieldwork
    // roughly doubled it, and a mission that used routes, sectors and radio
    // orders genuinely has more to report than one that used orders alone —
    // so the cap follows the pool instead of silently discarding half of it.
    // Still bounded: this is one paragraph in a printed report, not a log.
    $cap = count($findings) > 9 ? 9 : 6;
    $sentences = array_merge([$opening], array_column(array_slice($findings, 0, $cap), 'text'));

    if (!empty($unranked)) {
        $bits = array_map(function ($t) use ($label) {
            $had = [];
            $f = $t['fieldwork'] ?? null;
            if (($f['waypoints_total'] ?? 0) > 0) $had[] = $f['waypoints_total'] . ' σημεία πορείας';
            if (($f['sectors_assigned'] ?? 0) > 0) $had[] = $f['sectors_assigned'] . ' τομείς';
            if ($t['order_count'] > 0) $had[] = $t['order_count'] . ' εντολές';
            if (($t['shortage_count'] ?? 0) > 0) $had[] = $t['shortage_count'] . ' αναφορές έλλειψης';
            return $label($t) . (empty($had) ? '' : ' (' . implode(', ', $had) . ', βαθμός ' . number_format($t['score'], 1) . ')');
        }, $unranked);
        $one = count($unranked) === 1;
        $sentences[] = ($one ? 'Η ομάδα ' : 'Οι ομάδες ') . implode(', ', $bits)
            . ($one ? ' δεν κατατάσσεται: μετρήθηκε σε έναν μόνο τομέα, οπότε ο βαθμός της δεν είναι συγκρίσιμος με των υπολοίπων.'
                        : ' δεν κατατάσσονται: μετρήθηκαν σε έναν μόνο τομέα, οπότε οι βαθμοί τους δεν είναι συγκρίσιμοι με των υπολοίπων.');
    }

    // ── closing caveats, deliberately never ranked: they qualify
    //    everything above rather than competing with it ──────────────────
    // A team judged on fieldwork instead of radio traffic is not a thin
    // sample — it was measured on a different axis, and saying "evaluated on
    // very few orders" about a team that walked a full route is simply wrong.
    $thin = array_values(array_filter($teams, fn($t) =>
        $t['order_count'] < 3
        && ($t['shortage_count'] ?? 0) === 0
        && empty($t['pillars']['discipline']['available'])));
    if (!empty($thin)) {
        $names = array_map($label, $thin);
        $sentences[] = (count($thin) === 1 ? 'Η ομάδα ' . $names[0] . ' αξιολογήθηκε' : implode(', ', $names) . ' αξιολογήθηκαν')
            . ' σε ελάχιστες εντολές, οπότε η θέση ' . (count($thin) === 1 ? 'της' : 'τους') . ' στην κατάταξη είναι ενδεικτική και όχι ασφαλής σύγκριση.';
    }

    return implode(' ', $sentences);
}
/**
 * War Room: cross-mission pillar aggregates for reports.php's "Action Room"
 * trends tab, one row per mission_type_id (including 0/"no type", and every
 * type with zero missions in range, so callers never have to guess which
 * types exist). Same five pillars/weights/tiering as computeMissionScore(),
 * but computed with pooled SQL across every mission in [$startDate,$endDate]
 * instead of one mission at a time — calling computeMissionScore() in a loop
 * over a date range would be N+1 and, worse, would be *wrong* for the
 * response pillar: its exponential-decay formula is nonlinear
 * (decay(pooled avg) != avg(per-mission decayed scores)), so it specifically
 * needs the raw per-order minute list, not a re-averaged score. The other
 * four pillars ARE safe to pool directly: completion/shortage are simple
 * ratios over pooled counts, staffing is pooled capacity vs pooled distinct
 * (mission,volunteer) pairs, and debrief is safe to pool because its formula
 * (0.6*rating + 0.4*objectives) is linear, so AVG(pooled) == AVG(per-mission
 * scores) exactly.
 *
 * Response-pillar scope: mission_orders only, not the synthetic dispatch-
 * point-derived rows computeMissionResponseReport() also merges in for a
 * single mission — replicating that exact PHP-side merge in pooled SQL risks
 * silently disagreeing with it at the edges. Fast-follow if ever needed, not v1.
 *
 * $departmentId=null means "every department" (reports.php's own
 * $departmentId is '' for "all" — callers pass null, not '').
 */
function computeWarRoomTypeAggregates(string $startDate, string $endDate, ?int $departmentId = null): array {
    $where = "m.deleted_at IS NULL AND m.start_datetime >= ? AND m.start_datetime < ? + INTERVAL 1 DAY";
    $params = [$startDate, $endDate];
    if ($departmentId) {
        $where .= " AND m.department_id = ?";
        $params[] = $departmentId;
    }

    $forgottenThresholdMinutes = MISSION_SCORE_FORGOTTEN_MINUTES;
    $forgottenPenalty = 15;
    $responseHalfLifeMinutes = 24;

    // Seed every mission type (including inactive ones — a deactivated type
    // can still have historical missions inside the selected range) plus a
    // synthetic id-0 bucket for missions with no type set at all.
    $types = dbFetchAll("SELECT id, name, color, icon FROM mission_types ORDER BY sort_order, id");
    $result = [0 => ['id' => 0, 'name' => 'Χωρίς τύπο', 'color' => 'secondary', 'icon' => 'bi-question-circle', 'mission_count' => 0, 'pillars' => [], 'overall' => null, 'tier' => null]];
    foreach ($types as $t) {
        $result[(int) $t['id']] = ['id' => (int) $t['id'], 'name' => $t['name'], 'color' => $t['color'], 'icon' => $t['icon'], 'mission_count' => 0, 'pillars' => [], 'overall' => null, 'tier' => null];
    }
    $emptyPillar = fn(string $label, int $weight) => ['label' => $label, 'weight' => $weight, 'available' => false, 'score' => null, 'raw' => []];
    foreach ($result as &$r) {
        $r['pillars'] = [
            'response'   => $emptyPillar('Ταχύτητα Απόκρισης', 25),
            'completion' => $emptyPillar('Ολοκλήρωση Εντολών', 20),
            'shortage'   => $emptyPillar('Διαχείριση Ελλείψεων', 20),
            'staffing'   => $emptyPillar('Στελέχωση / Κάλυψη', 15),
            'debrief'    => $emptyPillar('Απολογισμός Debrief', 20),
        ];
    }
    unset($r);

    // ── mission counts ──────────────────────────────────────────────────
    $counts = dbFetchAll("SELECT COALESCE(m.mission_type_id,0) AS tid, COUNT(*) AS cnt FROM missions m WHERE $where GROUP BY tid", $params);
    foreach ($counts as $row) {
        $tid = (int) $row['tid'];
        if (isset($result[$tid])) $result[$tid]['mission_count'] = (int) $row['cnt'];
    }

    // ── staffing: pooled capacity vs pooled distinct (mission,volunteer)
    //    pairs — a flat COUNT(DISTINCT volunteer_id) would collapse one
    //    volunteer who worked 3 missions down to 1, deflating the fill rate.
    //    (mission_id,volunteer_id) pairs are inherently disjoint across
    //    missions, so the multi-column DISTINCT is exactly the pooled sum of
    //    each mission's own distinct-approved-volunteer count. ─────────────
    $capacityRows = dbFetchAll(
        "SELECT COALESCE(m.mission_type_id,0) AS tid, COALESCE(SUM(s.max_volunteers),0) AS capacity
         FROM shifts s JOIN missions m ON m.id = s.mission_id WHERE $where GROUP BY tid",
        $params
    );
    $capacityByType = [];
    foreach ($capacityRows as $row) $capacityByType[(int) $row['tid']] = (int) $row['capacity'];

    $approvedRows = dbFetchAll(
        "SELECT COALESCE(m.mission_type_id,0) AS tid, COUNT(DISTINCT m.id, pr.volunteer_id) AS approved
         FROM participation_requests pr JOIN shifts s ON s.id = pr.shift_id JOIN missions m ON m.id = s.mission_id
         WHERE pr.status = ? AND $where GROUP BY tid",
        array_merge([PARTICIPATION_APPROVED], $params)
    );
    $approvedByType = [];
    foreach ($approvedRows as $row) $approvedByType[(int) $row['tid']] = (int) $row['approved'];

    foreach ($result as $tid => &$r) {
        $capacity = $capacityByType[$tid] ?? 0;
        if ($capacity > 0) {
            $approved = $approvedByType[$tid] ?? 0;
            $r['pillars']['staffing'] = ['label' => 'Στελέχωση / Κάλυψη', 'weight' => 15, 'available' => true, 'score' => max(0, min(100, $approved / $capacity * 100)), 'raw' => ['approved' => $approved, 'capacity' => $capacity]];
        }
    }
    unset($r);

    // ── completion: pooled fulfilled/total over mission_order_recipients ──
    $completionRows = dbFetchAll(
        "SELECT COALESCE(m.mission_type_id,0) AS tid, COUNT(*) AS total,
                SUM(CASE WHEN r.fulfilled_at IS NOT NULL THEN 1 ELSE 0 END) AS fulfilled
         FROM mission_order_recipients r JOIN mission_orders o ON o.id = r.order_id JOIN missions m ON m.id = o.mission_id
         WHERE $where GROUP BY tid",
        $params
    );
    foreach ($completionRows as $row) {
        $tid = (int) $row['tid'];
        if (!isset($result[$tid])) continue;
        $total = (int) $row['total'];
        $fulfilled = (int) $row['fulfilled'];
        if ($total > 0) {
            $result[$tid]['pillars']['completion'] = ['label' => 'Ολοκλήρωση Εντολών', 'weight' => 20, 'available' => true, 'score' => $fulfilled / $total * 100, 'raw' => ['fulfilled' => $fulfilled, 'total' => $total]];
        }
    }

    // ── shortage: pooled resolved/total/unresolved-critical — same formula
    //    as computeMissionScore() (0 reports = neutral 100, not penalized) ──
    $shortageRows = dbFetchAll(
        "SELECT COALESCE(m.mission_type_id,0) AS tid, COUNT(*) AS total,
                SUM(CASE WHEN r.resolved_at IS NOT NULL THEN 1 ELSE 0 END) AS resolved,
                SUM(CASE WHEN r.resolved_at IS NULL AND r.severity = 'critical' THEN 1 ELSE 0 END) AS unresolved_critical
         FROM mission_shortage_reports r JOIN missions m ON m.id = r.mission_id
         WHERE $where GROUP BY tid",
        $params
    );
    foreach ($shortageRows as $row) {
        $tid = (int) $row['tid'];
        if (!isset($result[$tid])) continue;
        $total = (int) $row['total'];
        $resolved = (int) $row['resolved'];
        $unresolvedCritical = (int) $row['unresolved_critical'];
        $score = $total > 0 ? max(0, min(100, ($resolved / $total * 100) - $unresolvedCritical * 15)) : 100.0;
        $result[$tid]['pillars']['shortage'] = ['label' => 'Διαχείριση Ελλείψεων', 'weight' => 20, 'available' => true, 'score' => $score, 'raw' => ['resolved' => $resolved, 'total' => $total, 'unresolved_critical' => $unresolvedCritical]];
    }

    // ── debrief: pooled avg rating + objectives_met distribution — safe to
    //    pool since the per-mission formula is linear (see docblock) ───────
    $debriefRows = dbFetchAll(
        "SELECT COALESCE(m.mission_type_id,0) AS tid, COUNT(*) AS n, AVG(md.rating) AS avg_rating,
                SUM(CASE WHEN md.objectives_met = 'YES' THEN 1 ELSE 0 END) AS yes_cnt,
                SUM(CASE WHEN md.objectives_met = 'PARTIAL' THEN 1 ELSE 0 END) AS partial_cnt,
                SUM(CASE WHEN md.objectives_met = 'NO' THEN 1 ELSE 0 END) AS no_cnt
         FROM mission_debriefs md JOIN missions m ON m.id = md.mission_id
         WHERE $where GROUP BY tid",
        $params
    );
    foreach ($debriefRows as $row) {
        $tid = (int) $row['tid'];
        if (!isset($result[$tid])) continue;
        $n = (int) $row['n'];
        if ($n === 0) continue;
        $ratingScore = ((float) $row['avg_rating']) / 5 * 100;
        $objScore = (((int) $row['yes_cnt']) * 100 + ((int) $row['partial_cnt']) * 55 + ((int) $row['no_cnt']) * 15) / $n;
        $result[$tid]['pillars']['debrief'] = ['label' => 'Απολογισμός Debrief', 'weight' => 20, 'available' => true, 'score' => $ratingScore * 0.6 + $objScore * 0.4, 'raw' => ['avg_rating' => round((float) $row['avg_rating'], 2), 'n' => $n, 'yes' => (int) $row['yes_cnt'], 'partial' => (int) $row['partial_cnt'], 'no' => (int) $row['no_cnt']]];
    }

    // ── response: raw per-order minute deltas, bucketed by type in PHP so
    //    the nonlinear decay formula runs on the real distribution rather
    //    than a pre-averaged number (see docblock above) ────────────────────
    $responseRows = dbFetchAll(
        "SELECT COALESCE(m.mission_type_id,0) AS tid, TIMESTAMPDIFF(MINUTE, o.created_at, r.acknowledged_at) AS mins
         FROM mission_order_recipients r JOIN mission_orders o ON o.id = r.order_id JOIN missions m ON m.id = o.mission_id
         WHERE r.acknowledged_at IS NOT NULL AND $where",
        $params
    );
    $minutesByType = [];
    foreach ($responseRows as $row) {
        $minutesByType[(int) $row['tid']][] = (float) $row['mins'];
    }
    // Never-acknowledged recipients are filtered out by the query above (it
    // needs acknowledged_at to compute a delta at all), so they are counted
    // separately and padded back in as nulls — missionScoreForgottenAwareSpeed()
    // reads a null as "never answered" and charges for it. Without this the
    // trends tab would keep scoring a type that never acknowledged anything as
    // if those orders had not been sent, and would then disagree with the
    // single-mission score on the very same missions.
    $recipientRows = dbFetchAll(
        "SELECT COALESCE(m.mission_type_id,0) AS tid, COUNT(*) AS total,
                SUM(CASE WHEN r.acknowledged_at IS NULL THEN 1 ELSE 0 END) AS unanswered
         FROM mission_order_recipients r JOIN mission_orders o ON o.id = r.order_id JOIN missions m ON m.id = o.mission_id
         WHERE $where GROUP BY tid",
        $params
    );
    foreach ($recipientRows as $row) {
        $tid = (int) $row['tid'];
        $unanswered = (int) $row['unanswered'];
        if (!isset($result[$tid]) || $unanswered === 0) continue;
        for ($i = 0; $i < $unanswered; $i++) $minutesByType[$tid][] = null;
    }
    foreach ($minutesByType as $tid => $minutesList) {
        if (!isset($result[$tid])) continue;
        $speed = missionScoreForgottenAwareSpeed($minutesList, $responseHalfLifeMinutes, $forgottenThresholdMinutes, $forgottenPenalty, true);
        if ($speed['available']) {
            $result[$tid]['pillars']['response'] = ['label' => 'Ταχύτητα Απόκρισης', 'weight' => 25, 'available' => true, 'score' => $speed['score'], 'raw' => ['avg_minutes' => $speed['avg_minutes'], 'forgotten_count' => $speed['forgotten_count'], 'unanswered_count' => $speed['unanswered_count']]];
        }
    }

    // ── combine into overall/tier, same weights + "shortage alone can't
    //    vacuously score a type" gate as computeMissionScore() ─────────────
    foreach ($result as &$r) {
        $weightSum = 0;
        $weighted = 0.0;
        $hasSubstantive = false;
        foreach ($r['pillars'] as $key => $p) {
            if ($p['available']) {
                $weightSum += $p['weight'];
                $weighted += $p['weight'] * $p['score'];
                if ($key !== 'shortage') $hasSubstantive = true;
            }
        }
        $r['overall'] = ($hasSubstantive && $weightSum > 0) ? round($weighted / $weightSum, 2) : null;
        $r['tier'] = $r['overall'] !== null ? missionScoreTierMeta($r['overall']) : null;
    }
    unset($r);

    return $result;
}

/**
 * War Room: sibling of missionScorePillarPhrase() for
 * generateWarRoomComparisonNarrative() — same key/$positive contract and
 * "always return '' when unavailable" rule, but period-appropriate wording.
 * Not a reuse of missionScorePillarPhrase() itself: its shortage/debrief
 * case bodies are single-mission-specific ("κατά τη διάρκεια της αποστολής",
 * "ο υπεύθυνος αποστολής... την άσκηση"), wording that doesn't read
 * correctly when pooling many missions/commanders across a mission type.
 */
function warRoomTypePillarPhrase(string $key, array $pillar, bool $positive): string {
    $raw = $pillar['raw'] ?? [];
    switch ($key) {
        case 'response':
            $m = $raw['avg_minutes'] ?? null;
            if ($m === null) return '';
            return $positive
                ? "Ο μέσος χρόνος απόκρισης στις εντολές ήταν {$m} λεπτά, χρόνος που υποδηλώνει καλή ετοιμότητα των ομάδων."
                : "Ο μέσος χρόνος απόκρισης στις εντολές έφτασε τα {$m} λεπτά, χρόνος αυξημένος για επιχειρησιακό περιβάλλον.";
        case 'completion':
            $f = $raw['fulfilled'] ?? 0;
            $t = $raw['total'] ?? 0;
            if ($t === 0) return '';
            $rate = round($f / $t * 100);
            return $positive
                ? "Ολοκληρώθηκαν {$f} από τις {$t} εντολές ({$rate}%) στις αποστολές αυτού του τύπου."
                : "Ολοκληρώθηκαν {$f} από τις {$t} εντολές ({$rate}%) στις αποστολές αυτού του τύπου, ποσοστό που αφήνει περιθώριο βελτίωσης.";
        case 'shortage':
            $r = $raw['resolved'] ?? 0;
            $t = $raw['total'] ?? 0;
            if ($t === 0) return '';
            $rate = round($r / $t * 100);
            $uc = $raw['unresolved_critical'] ?? 0;
            $extra = $uc > 0 ? (' ' . ($uc === 1 ? 'Μία κρίσιμη αναφορά παρέμεινε ανεπίλυτη.' : "{$uc} κρίσιμες αναφορές παρέμειναν ανεπίλυτες.")) : '';
            return $positive
                ? "Λύθηκαν {$r} από τις {$t} αναφορές έλλειψης ({$rate}%)."
                : "Λύθηκαν {$r} από τις {$t} αναφορές έλλειψης ({$rate}%).{$extra}";
        case 'staffing':
            $a = $raw['approved'] ?? 0;
            $c = $raw['capacity'] ?? 0;
            if ($c === 0) return '';
            $rate = round($a / $c * 100);
            return $positive
                ? "Η κάλυψη βαρδιών ήταν επαρκής, με {$rate}% των διαθέσιμων θέσεων εθελοντών καλυμμένες."
                : "Η κάλυψη βαρδιών ήταν ανεπαρκής, με μόλις {$rate}% των διαθέσιμων θέσεων εθελοντών καλυμμένες.";
        case 'debrief':
            $n = $raw['n'] ?? 0;
            $avgRating = $raw['avg_rating'] ?? null;
            if ($n === 0 || $avgRating === null) return '';
            $yesPct = round((($raw['yes'] ?? 0) / $n) * 100);
            return $positive
                ? "Ο μέσος όρος βαθμολογίας debrief ήταν {$avgRating}/5, με τους στόχους να θεωρούνται πλήρως επιτευχθέντες στο {$yesPct}% των αποστολών."
                : "Ο μέσος όρος βαθμολογίας debrief ήταν {$avgRating}/5, με τους στόχους να θεωρούνται πλήρως επιτευχθέντες μόλις στο {$yesPct}% των αποστολών.";
    }
    return '';
}

/**
 * War Room: cross-mission trends narrative for reports.php's "Action Room"
 * tab — compares $current against $previous (each the direct return value
 * of computeWarRoomTypeAggregates(), one call per period). All comparison/
 * delta logic lives here, not in the aggregation function, mirroring how
 * computeMissionScore() itself has no notion of "compare to something else"
 * (that's this narrative's job, same separation as the single-mission side).
 *
 * Deliberately never names a specific mission or volunteer (unlike
 * generateMissionObserverNarrative()/generateCommandNarrative(), which both
 * do named-incident callouts) — comparisons stay at mission-type level only,
 * per explicit requirement. Tone is advisory throughout: concerns are always
 * phrased as suggestions via missionScoreRecommendations(), never verdicts.
 */
function generateWarRoomComparisonNarrative(array $current, array $previous, string $startDate, string $endDate, string $prevStartDate, string $prevEndDate): string {
    $totalCurrent = array_sum(array_column($current, 'mission_count'));
    if ($totalCurrent === 0) {
        return 'Δεν καταγράφηκαν αποστολές με δεδομένα Επιχειρησιακού (Action Room) στην επιλεγμένη περίοδο, συνεπώς δεν υπάρχουν επαρκή στοιχεία για σύγκριση.';
    }

    $startFmt = formatDate($startDate);
    $endFmt = formatDate($endDate);
    $prevStartFmt = formatDate($prevStartDate);
    $prevEndFmt = formatDate($prevEndDate);
    $totalPrevious = array_sum(array_column($previous, 'mission_count'));
    $typesUsed = count(array_filter($current, fn($t) => $t['mission_count'] > 0));

    $sentences = [];
    $sentences[] = "Κατά την περίοδο {$startFmt} έως {$endFmt} καταγράφηκαν {$totalCurrent} αποστολές σε {$typesUsed} "
        . ($typesUsed === 1 ? 'τύπο αποστολών' : 'τύπους αποστολών')
        . ", έναντι {$totalPrevious} αποστολών κατά την αμέσως προηγούμενη περίοδο ({$prevStartFmt}–{$prevEndFmt}).";

    // ── standout / concern this period — only among types with enough
    //    missions to mean something (>=2, same "don't crown a winner off one
    //    mission" instinct as generateTeamComparisonNarrative()'s own gate) ─
    $scored = array_values(array_filter($current, fn($t) => $t['overall'] !== null && $t['mission_count'] >= 2));
    if (count($scored) >= 2) {
        usort($scored, fn($a, $b) => $b['overall'] <=> $a['overall']);
        $top = $scored[0];
        $bottom = $scored[count($scored) - 1];
        $gap = round($top['overall'] - $bottom['overall'], 1);
        // Same gap-bucketing vocabulary as generateTeamComparisonNarrative()
        // (<5 = similar, applied here to types instead of teams) — a small
        // gap states homogeneity instead of forcing a false winner/loser.
        if ($gap < 5) {
            $sentences[] = 'Η επίδοση ήταν αρκετά ομοιογενής μεταξύ των διαφορετικών τύπων αποστολών αυτή την περίοδο.';
        } else {
            $topFmt = number_format($top['overall'], 1);
            $bottomFmt = number_format($bottom['overall'], 1);
            $sentences[] = "Ο τύπος «{$top['name']}» ξεχώρισε με βαθμολογία {$topFmt}/100, έναντι {$bottomFmt}/100 του τύπου «{$bottom['name']}».";

            $topAvailable = array_filter($top['pillars'], fn($p) => $p['available']);
            if (!empty($topAvailable)) {
                uasort($topAvailable, fn($a, $b) => $a['score'] <=> $b['score']);
                $strongestKey = array_key_last($topAvailable);
                $phrase = warRoomTypePillarPhrase($strongestKey, $topAvailable[$strongestKey], true);
                if ($phrase !== '') $sentences[] = $phrase;
            }

            $bottomAvailable = array_filter($bottom['pillars'], fn($p) => $p['available']);
            if (!empty($bottomAvailable)) {
                uasort($bottomAvailable, fn($a, $b) => $a['score'] <=> $b['score']);
                $weakestKey = array_key_first($bottomAvailable);
                $phrase = warRoomTypePillarPhrase($weakestKey, $bottomAvailable[$weakestKey], false);
                if ($phrase !== '') $sentences[] = $phrase;
                $recommendations = missionScoreRecommendations();
                if (isset($recommendations[$weakestKey])) $sentences[] = $recommendations[$weakestKey];
            }
        }
    }

    // ── most-improved/declined vs. the previous period — only among types
    //    with >=2 missions in BOTH periods, only if the delta clears the same
    //    5-point dead zone used just above ──────────────────────────────────
    $deltas = [];
    foreach ($current as $tid => $c) {
        if ($c['overall'] === null || $c['mission_count'] < 2) continue;
        $p = $previous[$tid] ?? null;
        if (!$p || $p['overall'] === null || $p['mission_count'] < 2) continue;
        $deltas[] = ['name' => $c['name'], 'delta' => $c['overall'] - $p['overall'], 'current' => $c['overall'], 'previous' => $p['overall']];
    }
    if (!empty($deltas)) {
        usort($deltas, fn($a, $b) => abs($b['delta']) <=> abs($a['delta']));
        $biggest = $deltas[0];
        if (abs($biggest['delta']) >= 5) {
            $deltaFmt = number_format(abs($biggest['delta']), 1);
            $curFmt = number_format($biggest['current'], 1);
            $prevFmt = number_format($biggest['previous'], 1);
            $sentences[] = $biggest['delta'] > 0
                ? "Ο τύπος «{$biggest['name']}» παρουσίασε τη μεγαλύτερη βελτίωση σε σχέση με την προηγούμενη περίοδο, από {$prevFmt} σε {$curFmt} (+{$deltaFmt} μονάδες)."
                : "Ο τύπος «{$biggest['name']}» παρουσίασε την πιο αισθητή πτώση σε σχέση με την προηγούμενη περίοδο, από {$prevFmt} σε {$curFmt} (-{$deltaFmt} μονάδες).";
        } else {
            $sentences[] = 'Η επίδοση παρέμεινε σε γενικές γραμμές σταθερή σε σχέση με την προηγούμενη περίοδο.';
        }
    }

    // ── closing disclaimer for any type with zero missions this period ────
    $zeroTypes = array_values(array_filter($current, fn($t) => $t['mission_count'] === 0));
    if (!empty($zeroTypes)) {
        $names = array_map(fn($t) => $t['name'], $zeroTypes);
        $sentences[] = 'Δεν καταγράφηκαν αποστολές αυτή την περίοδο για: ' . implode(', ', $names) . '.';
    }

    return implode(' ', $sentences);
}

/**
 * The three per-viewer visibility predicates the Activity feed is built on,
 * as SQL fragments shared by both of its consumers: mission-history.php (the
 * live feed) and loadMissionActivityEventsForReport() below (the archival
 * one, on the path where it is asked to scope). They live here, rather than
 * inline in mission-history.php where they were first written, for a single
 * reason: the two surfaces must never disagree about who may see an event,
 * and one copy of each rule is the only way to guarantee that. They were two
 * copies once — the export shipped with none of them at all, and a volunteer
 * could download ~80 events they could not see on screen.
 *
 * Each fragment takes its binds positionally, in the order named on the
 * function. The leading `? = 1` in all three is the command-staff escape
 * hatch: bind 1 to see everything, 0 to be scoped like a field volunteer.
 *
 * mission-history.php's own docblock explains WHY there are three different
 * rules here rather than one — that reasoning is not repeated.
 */

// Predicate 1 — dispatch family (sent/received/arrived). $alias is the
// mission_dispatch_points alias. Binds: [$isAdminParam, $viewerId].
function missionActivityDispatchScopeSql(string $alias = 'd'): string {
    return "($alias.team_id IS NULL OR ? = 1 OR $alias.team_id IN (SELECT team_id FROM mission_team_members WHERE user_id = ?))";
}

// Predicate 2 — sources where team_id is the ACTOR's own membership (orders,
// field status, SOS, pings, shortage reports). Note the deliberate absence of
// any "IS NULL" branch, unlike predicate 1: NULL here means "this person has
// no team", which stays private to them plus command staff.
// Binds: [$isAdminParam, $viewerId, $viewerTeamId].
function missionActivityActorScopeSql(string $actorColumn, string $teamColumn): string {
    return "(? = 1 OR $actorColumn = ? OR $teamColumn = ?)";
}

// Predicate 3 — Route Order waypoints: membership of THIS specific route,
// not of the route's nominal team. $alias is the mission_routes alias.
// Binds: [$isAdminParam, $viewerId].
function missionActivityRouteScopeSql(string $alias = 'r'): string {
    return "(? = 1 OR EXISTS (SELECT 1 FROM mission_route_members rm WHERE rm.route_id = $alias.id AND rm.user_id = ?))";
}

/**
 * War Room: activity-feed events for the archival print/stats surfaces —
 * same sources as mission-history.php's live Activity feed, but uncapped (no
 * LIMIT 150 on pings, no 200-event slice) and Greek-only. Returns events
 * sorted newest-first with a Unix `ts` — callers format their own display
 * string.
 *
 * TWO independent gates, because this helper's callers are NOT equally
 * privileged and a single "is staff" flag was not enough to express that:
 *
 * $includeStaffOnly gates ONE source: hand-typed notes the author marked
 * staff-only (mission_activity_notes.staff_only), a flat per-row filter.
 *
 * $scopeToViewerId gates every source where an event belongs to a particular
 * team or person. Null (the default) means no per-viewer scoping at all —
 * the full archive, for a caller that has already established the viewer is
 * command staff. A user id means "show only what this volunteer can see in
 * the live feed", applying the three shared predicates above with their
 * escape hatch bound to 0 (a scoping caller is by construction not staff).
 * Both default to the safe value, so a future caller that forgets either
 * argument leaks nothing.
 *
 * mission-report-print.php, mission-stats.php and mission-track.php are all
 * genuinely command-staff-only and pass (true, null). Only
 * exports/export-mission-activity.php admits an approved participant as
 * well, and it is the one caller that passes a viewer id.
 */
function loadMissionActivityEventsForReport(int $missionId, bool $includeStaffOnly = false, ?int $scopeToViewerId = null): array {
    $events = [];

    // Each pair below is either an empty string + no binds (the unscoped
    // archive) or " AND <predicate>" + its binds, appended to that source's
    // own WHERE clause. Building them once up here keeps every query below
    // reading as the plain SQL it was before scoping existed.
    $scoped        = $scopeToViewerId !== null;
    $viewerTeamId  = $scoped ? getUserTeamIdForMission($missionId, $scopeToViewerId) : null;

    $dispatchScope = $scoped ? ' AND ' . missionActivityDispatchScopeSql() : '';
    $dispatchBinds = $scoped ? [0, $scopeToViewerId] : [];

    $orderScope    = $scoped ? ' AND ' . missionActivityActorScopeSql('r.user_id', 'r.team_id') : '';
    $orderBinds    = $scoped ? [0, $scopeToViewerId, $viewerTeamId] : [];

    $routeScope    = $scoped ? ' AND ' . missionActivityRouteScopeSql() : '';
    $routeBinds    = $scoped ? [0, $scopeToViewerId] : [];

    $statusScope   = $scoped ? ' AND ' . missionActivityActorScopeSql('pr.volunteer_id', 'mtm.team_id') : '';
    $statusBinds   = $scoped ? [0, $scopeToViewerId, $viewerTeamId] : [];

    $sosScope      = $scoped ? ' AND ' . missionActivityActorScopeSql('a.user_id', 'a.team_id') : '';
    $sosBinds      = $scoped ? [0, $scopeToViewerId, $viewerTeamId] : [];

    $pingScope     = $scoped ? ' AND ' . missionActivityActorScopeSql('vp.user_id', 'mtm.team_id') : '';
    $pingBinds     = $scoped ? [0, $scopeToViewerId, $viewerTeamId] : [];

    $shortageScope = $scoped ? ' AND ' . missionActivityActorScopeSql('r.reporter_id', 'r.team_id') : '';
    $shortageBinds = $scoped ? [0, $scopeToViewerId, $viewerTeamId] : [];

    $sentRows = dbFetchAll(
        "SELECT d.type, d.label, d.created_at, d.team_id, mt.codename, mt.team_number, u.name AS actor_name
         FROM mission_dispatch_points d
         LEFT JOIN mission_teams mt ON mt.id = d.team_id
         JOIN users u ON u.id = d.created_by
         WHERE d.mission_id = ?" . $dispatchScope,
        array_merge([$missionId], $dispatchBinds)
    );
    foreach ($sentRows as $row) {
        $teamLabel = $row['team_id'] ? teamLabel($row['codename'], $row['team_number']) : 'όλες τις ομάδες';
        $kind = $row['type'] === 'point' ? 'σημείο' : 'περιοχή';
        $events[] = [
            'icon' => '📍',
            'text' => h($row['actor_name']) . ' έστειλε ' . $kind . ' στη ' . h($teamLabel)
                . ($row['label'] ? ' — «' . h($row['label']) . '»' : ''),
            'ts'   => strtotime($row['created_at']),
        ];
    }

    $receivedRows = dbFetchAll(
        "SELECT rc.created_at, d.team_id, d.label, mt.codename, mt.team_number, u.name AS actor_name
         FROM mission_dispatch_receipts rc
         JOIN mission_dispatch_points d ON d.id = rc.dispatch_id
         LEFT JOIN mission_teams mt ON mt.id = d.team_id
         JOIN users u ON u.id = rc.user_id
         WHERE d.mission_id = ?" . $dispatchScope,
        array_merge([$missionId], $dispatchBinds)
    );
    foreach ($receivedRows as $row) {
        $teamLabel = $row['team_id'] ? teamLabel($row['codename'], $row['team_number']) : 'όλες τις ομάδες';
        $events[] = [
            'icon' => '🚩',
            'text' => h($row['actor_name']) . ' έλαβε εντολή προς ' . h($teamLabel)
                . ($row['label'] ? ' — «' . h($row['label']) . '»' : ''),
            'ts'   => strtotime($row['created_at']),
        ];
    }

    $arrivedRows = dbFetchAll(
        "SELECT a.created_at, a.team_id AS ack_team_id, amt.codename AS ack_codename, amt.team_number AS ack_team_number,
                au.name AS actor_name, d.label AS dispatch_label
         FROM mission_dispatch_acks a
         JOIN mission_dispatch_points d ON d.id = a.dispatch_id
         JOIN users au ON au.id = a.user_id
         LEFT JOIN mission_teams amt ON amt.id = a.team_id
         WHERE d.mission_id = ?" . $dispatchScope,
        array_merge([$missionId], $dispatchBinds)
    );
    foreach ($arrivedRows as $row) {
        $teamLabel = $row['ack_team_id'] ? teamLabel($row['ack_codename'], $row['ack_team_number']) : null;
        $events[] = [
            'icon' => '✅',
            'text' => ($teamLabel ? 'Η ομάδα ' . h($teamLabel) : h($row['actor_name'])) . ' ανέφερε άφιξη'
                . ($row['dispatch_label'] ? ' στο «' . h($row['dispatch_label']) . '»' : '')
                . ($teamLabel ? ' (' . h($row['actor_name']) . ')' : ''),
            'ts'   => strtotime($row['created_at']),
        ];
    }

    // Search areas — no status/team, so nothing to log beyond creation.
    $areaCreatedRows = dbFetchAll(
        "SELECT a.label, a.created_at, cu.name AS actor_name
         FROM mission_search_areas a
         LEFT JOIN users cu ON cu.id = a.created_by
         WHERE a.mission_id = ?",
        [$missionId]
    );
    foreach ($areaCreatedRows as $row) {
        $events[] = [
            'icon' => '🗺️',
            'text' => h($row['actor_name'] ?? '—') . ' δημιούργησε την περιοχή έρευνας «' . h($row['label']) . '»',
            'ts'   => strtotime($row['created_at']),
        ];
    }

    // Search sectors — unscoped (no team filter), same reasoning as
    // mission-history.php's own copy: every team needs to see coverage
    // regardless of who it's assigned to.
    $sectorCreatedRows = dbFetchAll(
        "SELECT s.label, s.team_id, s.created_at, cu.name AS actor_name, mt.codename, mt.team_number
         FROM mission_search_sectors s
         LEFT JOIN users cu ON cu.id = s.created_by
         LEFT JOIN mission_teams mt ON mt.id = s.team_id
         WHERE s.mission_id = ?",
        [$missionId]
    );
    foreach ($sectorCreatedRows as $row) {
        $teamLabel = $row['team_id'] ? teamLabel($row['codename'], $row['team_number']) : 'χωρίς ομάδα';
        $events[] = [
            'icon' => '🗺️',
            'text' => h($row['actor_name'] ?? '—') . ' δημιούργησε τον τομέα έρευνας «' . h($row['label']) . '» (' . h($teamLabel) . ')',
            'ts'   => strtotime($row['created_at']),
        ];
    }

    // Restricted (hazard/danger) areas — creation, same "nothing else to log"
    // reasoning as search areas above.
    $restrictedAreaCreatedRows = dbFetchAll(
        "SELECT a.label, a.created_at, cu.name AS actor_name
         FROM mission_restricted_areas a
         LEFT JOIN users cu ON cu.id = a.created_by
         WHERE a.mission_id = ?",
        [$missionId]
    );
    foreach ($restrictedAreaCreatedRows as $row) {
        $events[] = [
            'icon' => '⚠️',
            'text' => h($row['actor_name'] ?? '—') . ' δημιούργησε την απαγορευμένη περιοχή «' . h($row['label']) . '»',
            'ts'   => strtotime($row['created_at']),
        ];
    }

    // Restricted-area breaches — one event per entry (exit/resolve aren't
    // separately logged here, same granularity as SOS alerts below, which
    // also only report the initial created_at, not the ack/resolve times).
    $restrictedAreaBreachRows = dbFetchAll(
        "SELECT b.area_label, b.created_at, b.team_id, u.name AS actor_name, mt.codename, mt.team_number
         FROM mission_restricted_area_breaches b
         JOIN users u ON u.id = b.user_id
         LEFT JOIN mission_teams mt ON mt.id = b.team_id
         WHERE b.mission_id = ?",
        [$missionId]
    );
    foreach ($restrictedAreaBreachRows as $row) {
        $teamLabel = $row['team_id'] ? teamLabel($row['codename'], $row['team_number']) : 'χωρίς ομάδα';
        $events[] = [
            'icon' => '🚨',
            'text' => h($row['actor_name']) . ' (' . h($teamLabel) . ') μπήκε στην απαγορευμένη περιοχή «' . h($row['area_label']) . '»',
            'ts'   => strtotime($row['created_at']),
        ];
    }

    $sectorStatusRows = dbFetchAll(
        "SELECT l.to_status, l.created_at, s.label, u.name AS actor_name, mt.codename, mt.team_number
         FROM mission_sector_status_log l
         JOIN mission_search_sectors s ON s.id = l.sector_id
         LEFT JOIN users u ON u.id = l.user_id
         LEFT JOIN mission_teams mt ON mt.id = l.team_id
         WHERE s.mission_id = ?",
        [$missionId]
    );
    foreach ($sectorStatusRows as $row) {
        $teamLabel = $row['codename'] ? teamLabel($row['codename'], $row['team_number']) : 'χωρίς ομάδα';
        $events[] = [
            'icon' => $row['to_status'] === 'completed' ? '✅' : ($row['to_status'] === 'needs_recheck' ? '⚠️' : '🗺️'),
            // Forced 'el', not the viewer's own profile language — this whole
            // function is hardcoded-Greek by its own 100%-consistent existing
            // convention (a PDF/Excel export, not a per-viewer live surface),
            // and sectorStatusLabel() would otherwise resolve in whichever
            // language the CALLER happens to be viewing in, breaking that
            // invariant for this one string alone.
            'text' => h($row['actor_name'] ?? '—') . ' άλλαξε τον τομέα «' . h($row['label']) . '» σε «' . h(sectorStatusLabel($row['to_status'], 'el')) . '» (' . h($teamLabel) . ')',
            'ts'   => strtotime($row['created_at']),
        ];
    }

    $orderTypeIcons = ['location' => '📍', 'photo' => '📷', 'video' => '🎥', 'task' => '📋', 'message' => '📢', 'return_to_base' => '🏁', 'route' => '🧭', 'charge_phone' => '🔋', 'speak' => '🔊'];
    $orderRows = dbFetchAll(
        "SELECT o.order_type, o.task_text, o.created_at AS sent_at, r.team_id, r.acknowledged_at, r.fulfilled_at,
                u.name AS actor_name, mt.codename, mt.team_number
         FROM mission_order_recipients r
         JOIN mission_orders o ON o.id = r.order_id
         JOIN users u ON u.id = r.user_id
         LEFT JOIN mission_teams mt ON mt.id = r.team_id
         WHERE o.mission_id = ?" . $orderScope,
        array_merge([$missionId], $orderBinds)
    );
    foreach ($orderRows as $row) {
        $icon = $orderTypeIcons[$row['order_type']] ?? '📋';
        $teamLabel = $row['team_id'] ? teamLabel($row['codename'], $row['team_number']) : 'χωρίς ομάδα';
        $extra = '';
        if (in_array($row['order_type'], ['task', 'speak', 'message', 'route', 'charge_phone'], true) && $row['task_text']) {
            $snippet = mb_strlen($row['task_text']) > 120 ? mb_substr($row['task_text'], 0, 117) . '…' : $row['task_text'];
            $extra = ' — «' . h($snippet) . '»';
        } elseif ($row['order_type'] === 'return_to_base') {
            // No task_text stored for this type (see mission-history.php's own
            // fix for the same gap) — the sent message is a fixed system
            // phrase, not admin-typed free text, so it's spelled out directly
            // here rather than re-derived from a translation key this
            // hardcoded-Greek report file doesn't otherwise use.
            $extra = ' — «Η αποστολή ολοκληρώθηκε — επιστρέψτε άμεσα στη βάση.»';
        }
        $events[] = ['icon' => $icon, 'text' => 'Εντολή προς ' . h($row['actor_name']) . ' (' . h($teamLabel) . ')' . $extra, 'ts' => strtotime($row['sent_at'])];
        if ($row['acknowledged_at']) {
            $events[] = ['icon' => '👍', 'text' => h($row['actor_name']) . ' έλαβε εντολή (' . h($teamLabel) . ')' . $extra, 'ts' => strtotime($row['acknowledged_at'])];
        }
        if ($row['fulfilled_at']) {
            $events[] = ['icon' => '✅', 'text' => h($row['actor_name']) . ' ολοκλήρωσε εντολή (' . h($teamLabel) . ')' . $extra, 'ts' => strtotime($row['fulfilled_at'])];
        }
    }

    // Per-waypoint Route Order events — the $orderRows block above only covers
    // the order's own lifecycle (sent/acknowledged/route-fulfilled-as-a-whole);
    // this is the actual stop-by-stop journey (depart/arrive/complete/skip)
    // within it, one row of mission_route_progress per waypoint.
    $routeProgressRows = dbFetchAll(
        "SELECT p.departed_at, p.arrived_at, p.completed_at, p.skipped_at, p.skip_reason, p.arrived_distance_m,
                w.seq, w.label, r.team_id, mt.codename, mt.team_number,
                du.name AS departed_by_name, au.name AS arrived_by_name, cu.name AS completed_by_name, su.name AS skipped_by_name
         FROM mission_route_progress p
         JOIN mission_route_waypoints w ON w.id = p.waypoint_id
         JOIN mission_routes r ON r.id = p.route_id
         LEFT JOIN mission_teams mt ON mt.id = r.team_id
         LEFT JOIN users du ON du.id = p.departed_by
         LEFT JOIN users au ON au.id = p.arrived_by
         LEFT JOIN users cu ON cu.id = p.completed_by
         LEFT JOIN users su ON su.id = p.skipped_by
         WHERE r.mission_id = ?" . $routeScope,
        array_merge([$missionId], $routeBinds)
    );
    foreach ($routeProgressRows as $row) {
        $teamLabel = $row['team_id'] ? teamLabel($row['codename'], $row['team_number']) : 'χωρίς ομάδα';
        $pointLabel = $row['label'] !== null && $row['label'] !== '' ? $row['label'] : ('Σημείο ' . $row['seq']);
        if ($row['departed_at']) {
            $events[] = [
                'icon' => '🚶',
                'text' => 'Η ομάδα ' . h($teamLabel) . ' ξεκίνησε για «' . h($pointLabel) . '»' . ($row['departed_by_name'] ? ' (' . h($row['departed_by_name']) . ')' : ''),
                'ts'   => strtotime($row['departed_at']),
            ];
        }
        if ($row['arrived_at']) {
            $distanceSuffix = $row['arrived_distance_m'] !== null ? ' — ~' . (int) $row['arrived_distance_m'] . 'μ. από το σημείο' : '';
            $events[] = [
                'icon' => '📍',
                'text' => 'Η ομάδα ' . h($teamLabel) . ' έφτασε στο «' . h($pointLabel) . '»' . ($row['arrived_by_name'] ? ' (' . h($row['arrived_by_name']) . ')' : '') . $distanceSuffix,
                'ts'   => strtotime($row['arrived_at']),
            ];
        }
        if ($row['completed_at']) {
            $events[] = [
                'icon' => '✅',
                'text' => 'Η ομάδα ' . h($teamLabel) . ' ολοκλήρωσε το «' . h($pointLabel) . '»' . ($row['completed_by_name'] ? ' (' . h($row['completed_by_name']) . ')' : ''),
                'ts'   => strtotime($row['completed_at']),
            ];
        }
        if ($row['skipped_at']) {
            $events[] = [
                'icon' => '⏭',
                'text' => 'Παραλείφθηκε το «' . h($pointLabel) . '» της ομάδας ' . h($teamLabel) . ($row['skip_reason'] ? ' — ' . h($row['skip_reason']) : '') . ($row['skipped_by_name'] ? ' (' . h($row['skipped_by_name']) . ')' : ''),
                'ts'   => strtotime($row['skipped_at']),
            ];
        }
    }

    $fieldStatusIcons = ['field_status_on_way' => '🚗', 'field_status_on_site' => '✅', 'needs_help' => '🆘'];
    $fieldStatusText  = ['field_status_on_way' => 'σε κίνηση', 'field_status_on_site' => 'στο σημείο', 'needs_help' => 'χρειάζεται βοήθεια (SOS)'];
    // The mission_team_members join exists only to give predicate 2 its
    // mtm.team_id — nothing is selected from it, and it cannot multiply rows
    // (UNIQUE KEY uniq_mission_user on mission_id+user_id), so it is left in
    // place unconditionally rather than spliced in only on the scoped path.
    // Same shape as mission-history.php's copy of this query.
    $statusRows = dbFetchAll(
        "SELECT al.action, al.created_at, u.name AS actor_name
         FROM audit_logs al
         JOIN participation_requests pr ON pr.id = al.record_id
         JOIN shifts s ON s.id = pr.shift_id
         JOIN users u ON u.id = pr.volunteer_id
         LEFT JOIN mission_team_members mtm ON mtm.mission_id = s.mission_id AND mtm.user_id = pr.volunteer_id
         WHERE al.table_name = 'participation_requests'
           AND al.action IN ('field_status_on_way', 'field_status_on_site', 'needs_help')
           AND s.mission_id = ?" . $statusScope . "
         ORDER BY al.created_at DESC",
        array_merge([$missionId], $statusBinds)
    );
    foreach ($statusRows as $row) {
        $events[] = ['icon' => $fieldStatusIcons[$row['action']] ?? '📶', 'text' => h($row['actor_name']) . ' → ' . $fieldStatusText[$row['action']], 'ts' => strtotime($row['created_at'])];
    }

    // SOS lifecycle continued: the trigger itself is already the 'needs_help'
    // event just above (same created_at, same source action in
    // volunteer-status.php) — only acknowledge/resolve add new information,
    // previously entirely absent from this timeline even though
    // mission-sos.php tracks both.
    $sosEventRows = dbFetchAll(
        "SELECT a.acknowledged_at, a.resolved_at, u.name AS actor_name
         FROM mission_sos_alerts a
         JOIN users u ON u.id = a.user_id
         WHERE a.mission_id = ?" . $sosScope,
        array_merge([$missionId], $sosBinds)
    );
    foreach ($sosEventRows as $row) {
        if ($row['acknowledged_at']) {
            $events[] = ['icon' => '👁️', 'text' => 'Ελήφθη το SOS — ' . h($row['actor_name']), 'ts' => strtotime($row['acknowledged_at'])];
        }
        if ($row['resolved_at']) {
            $events[] = ['icon' => '✅', 'text' => 'Λύθηκε το SOS — ' . h($row['actor_name']), 'ts' => strtotime($row['resolved_at'])];
        }
    }

    // mission_team_members joined for predicate 2 only, same as $statusRows
    // above — see the note there.
    $pingRows = dbFetchAll(
        "SELECT vp.created_at, u.name AS actor_name
         FROM volunteer_pings vp
         JOIN shifts s ON s.id = vp.shift_id
         JOIN users u ON u.id = vp.user_id
         LEFT JOIN mission_team_members mtm ON mtm.mission_id = s.mission_id AND mtm.user_id = vp.user_id
         WHERE s.mission_id = ? AND vp.source = 'manual'" . $pingScope . "
         ORDER BY vp.created_at DESC",
        array_merge([$missionId], $pingBinds)
    );
    foreach ($pingRows as $row) {
        $events[] = ['icon' => '📡', 'text' => h($row['actor_name']) . ' έστειλε στίγμα GPS', 'ts' => strtotime($row['created_at'])];
    }

    $shortageEventRows = dbFetchAll(
        "SELECT r.shortage_type, r.title, r.created_at, r.acknowledged_at, r.resolved_at, r.not_resolved_at, r.outcome_note, u.name AS actor_name
         FROM mission_shortage_reports r
         JOIN users u ON u.id = r.reporter_id
         WHERE r.mission_id = ?" . $shortageScope,
        array_merge([$missionId], $shortageBinds)
    );
    foreach ($shortageEventRows as $row) {
        $label = SHORTAGE_TYPE_LABELS[$row['shortage_type']] ?? $row['shortage_type'];
        $noteSuffix = $row['outcome_note'] ? ' — «' . h($row['outcome_note']) . '»' : '';
        $events[] = ['icon' => '⚠️', 'text' => h($row['actor_name']) . ' ανέφερε έλλειψη (' . h($label) . ') — «' . h($row['title']) . '»', 'ts' => strtotime($row['created_at'])];
        if ($row['acknowledged_at']) {
            $events[] = ['icon' => '👁️', 'text' => 'Η αναφορά «' . h($row['title']) . '» ελέγχθηκε', 'ts' => strtotime($row['acknowledged_at'])];
        }
        if ($row['resolved_at']) {
            $events[] = ['icon' => '✅', 'text' => 'Η αναφορά «' . h($row['title']) . '» λύθηκε' . $noteSuffix, 'ts' => strtotime($row['resolved_at'])];
        }
        if ($row['not_resolved_at']) {
            $events[] = ['icon' => '🚫', 'text' => 'Η αναφορά «' . h($row['title']) . '» δεν λύθηκε' . $noteSuffix, 'ts' => strtotime($row['not_resolved_at'])];
        }
    }

    // Patient name/phone are deliberately never mentioned here, even though the
    // live activity card itself is command-staff-only (see the $canManageWarRoom
    // gate around it in war-room.php) — these events also feed this same page's
    // PDF export and the Excel activity export, neither of which carries that
    // same access boundary. Only type/severity/outcome, same restraint as the
    // dedicated incident PDF section.
    $incidentEventRows = dbFetchAll(
        "SELECT i.incident_type, i.severity, i.created_at, i.acknowledged_at, i.resolved_at, i.outcome, i.outcome_location, u.name AS actor_name
         FROM mission_incidents i
         JOIN users u ON u.id = i.reporter_id
         WHERE i.mission_id = ?",
        [$missionId]
    );
    foreach ($incidentEventRows as $row) {
        $label = incidentTypeLabel($row['incident_type']) . ', ' . incidentSeverityLabel($row['severity']);
        $events[] = ['icon' => '🚑', 'text' => h($row['actor_name']) . ' ανέφερε περιστατικό (' . h($label) . ')', 'ts' => strtotime($row['created_at'])];
        if ($row['acknowledged_at']) {
            $events[] = ['icon' => '👁️', 'text' => 'Η αναφορά περιστατικού (' . h($label) . ') ελέγχθηκε', 'ts' => strtotime($row['acknowledged_at'])];
        }
        if ($row['resolved_at']) {
            $outcomeSuffix = $row['outcome'] ? ' — ' . h(incidentOutcomeLabel($row['outcome'])) . ($row['outcome_location'] ? ' («' . h($row['outcome_location']) . '»)' : '') : '';
            $events[] = ['icon' => '✅', 'text' => 'Η αναφορά περιστατικού (' . h($label) . ') έκλεισε' . $outcomeSuffix, 'ts' => strtotime($row['resolved_at'])];
        }
    }

    // Points of interest: one "reported" event per photo (independent
    // corroboration from several volunteers each shows up individually,
    // same as the live Δραστηριότητα tab's own choice for this — see
    // mission-history.php), "checked" once per POI group.
    $poiPhotoEventRows = dbFetchAll(
        "SELECT ph.poi_note, ph.created_at, u.name AS actor_name
         FROM mission_photos ph
         JOIN users u ON u.id = ph.user_id
         WHERE ph.mission_id = ? AND ph.poi_id IS NOT NULL",
        [$missionId]
    );
    foreach ($poiPhotoEventRows as $row) {
        $noteSuffix = $row['poi_note'] ? ' — «' . h($row['poi_note']) . '»' : '';
        $events[] = ['icon' => '📍', 'text' => h($row['actor_name']) . ' ανέφερε Σημείο Ενδιαφέροντος' . $noteSuffix, 'ts' => strtotime($row['created_at'])];
    }

    // Regular field photos/videos — every mission_photos row NOT already
    // covered by the POI block above (poi_id IS NULL): a plain "send a
    // photo/video" order fulfillment, or a Route Order waypoint's own
    // photo/video deliverable (mission-route.php's require_photo/require_video).
    // Previously entirely absent from this timeline — a route waypoint's
    // proof-of-arrival photo left no trace here even though the waypoint's
    // own arrive/complete events above did.
    $fieldMediaRows = dbFetchAll(
        "SELECT ph.media_type, ph.created_at, ph.route_waypoint_id, w.seq AS waypoint_seq, w.label AS waypoint_label,
                r.team_id, mt.codename, mt.team_number, u.name AS actor_name
         FROM mission_photos ph
         JOIN users u ON u.id = ph.user_id
         LEFT JOIN mission_route_waypoints w ON w.id = ph.route_waypoint_id
         LEFT JOIN mission_routes r ON r.id = w.route_id
         LEFT JOIN mission_teams mt ON mt.id = r.team_id
         WHERE ph.mission_id = ? AND ph.poi_id IS NULL",
        [$missionId]
    );
    foreach ($fieldMediaRows as $row) {
        $icon = $row['media_type'] === 'video' ? '🎥' : '📷';
        $kind = $row['media_type'] === 'video' ? 'βίντεο' : 'φωτογραφία';
        if ($row['route_waypoint_id']) {
            $pointLabel = $row['waypoint_label'] !== null && $row['waypoint_label'] !== '' ? $row['waypoint_label'] : ('Σημείο ' . $row['waypoint_seq']);
            $teamLabel = $row['team_id'] ? teamLabel($row['codename'], $row['team_number']) : 'χωρίς ομάδα';
            $events[] = [
                'icon' => $icon,
                'text' => h($row['actor_name']) . ' έστειλε ' . $kind . ' από «' . h($pointLabel) . '» (' . h($teamLabel) . ')',
                'ts'   => strtotime($row['created_at']),
            ];
        } else {
            $events[] = [
                'icon' => $icon,
                'text' => h($row['actor_name']) . ' έστειλε ' . $kind,
                'ts'   => strtotime($row['created_at']),
            ];
        }
    }

    $poiCheckedEventRows = dbFetchAll(
        "SELECT checked_at FROM mission_points_of_interest WHERE mission_id = ? AND checked_at IS NOT NULL",
        [$missionId]
    );
    foreach ($poiCheckedEventRows as $row) {
        $events[] = ['icon' => '✅', 'text' => 'Ελέγχθηκε Σημείο Ενδιαφέροντος', 'ts' => strtotime($row['checked_at'])];
    }

    // Hand-typed command-staff entries. Greek literals like every other
    // source in this function (unlike mission-history.php, which translates
    // per viewer) — this helper feeds the printed report and the CSV
    // archive, both of which are Greek documents. The note body itself is
    // free text and is never translated anywhere.
    $noteSql = "SELECT n.note, n.staff_only, n.created_at, u.name AS author_name
                FROM mission_activity_notes n
                LEFT JOIN users u ON u.id = n.author_id
                WHERE n.mission_id = ?";
    if (!$includeStaffOnly) {
        $noteSql .= " AND n.staff_only = 0";
    }
    $noteRows = dbFetchAll($noteSql, [$missionId]);
    foreach ($noteRows as $row) {
        $events[] = [
            'icon' => $row['staff_only'] ? '🔒' : '📝',
            'text' => h($row['author_name'] ?? 'Άγνωστος') . ' κατέγραψε: «' . h($row['note']) . '»',
            'ts'   => strtotime($row['created_at']),
        ];
    }

    usort($events, fn($a, $b) => $b['ts'] <=> $a['ts']);
    return $events;
}

/**
 * Resolves the timestamp a queued-offline field action should actually be
 * recorded at. The client optionally sends `reported_at` (its own clock,
 * captured the moment the volunteer tapped the button) — this is what makes
 * the War Room offline queue work: an "arrive", or an SOS, that only
 * physically reaches this server 20 minutes later (once signal comes back)
 * still records the real field time instead of whenever the network happened
 * to recover.
 *
 * Returns [$eventTimestamp, $reportedAtTimestamp] as MySQL DATETIME strings:
 * $eventTimestamp is what the real column (departed_at/arrived_at/
 * completed_at/skipped_at, field_status_updated_at, sos created_at) gets set
 * to; $reportedAtTimestamp is the client's raw claim, kept for the audit
 * trail even on the rare path below where it wasn't trusted for the real
 * column.
 *
 * Only accepted within a plausible field-offline window (not in the future,
 * not implausibly old) — this is a self-reported client clock, not
 * authenticated, so an unbounded value is never trusted outright; outside
 * that window it silently falls back to server NOW() rather than rejecting
 * the whole action (a wrong client clock must never block a field report,
 * least of all an SOS).
 *
 * Shared by mission-route.php and volunteer-status.php: both are replayed by
 * the same client-side queue, so they must agree on these rules exactly.
 */
function resolveEventTimestamp(): array {
    $now = date('Y-m-d H:i:s');
    $reportedAtRaw = post('reported_at');
    if ($reportedAtRaw === '' || $reportedAtRaw === null) {
        return [$now, $now];
    }
    $ts = strtotime($reportedAtRaw);
    if ($ts === false) {
        return [$now, $now];
    }
    $reportedAtTimestamp = date('Y-m-d H:i:s', $ts);
    $nowTs = time();
    if ($ts <= $nowTs && $ts >= $nowTs - 86400) {
        return [$reportedAtTimestamp, $reportedAtTimestamp];
    }
    return [$now, $reportedAtTimestamp];
}