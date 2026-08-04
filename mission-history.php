<?php
/**
 * VolunteerOps - Mission Activity Feed Endpoint ("Δραστηριότητα")
 * War Room: unified timeline of everything timestamped in a mission —
 * dispatch (sent/received/arrived), all 4 order types (sent/acknowledged/
 * fulfilled), field-status changes (incl. SOS), GPS pings, and shortage
 * reports (submitted/seen/resolved). Each source is fetched separately and
 * normalized, then merged+sorted+capped in PHP (same technique this file
 * already used for dispatch-only, and mission-response-report.php reused).
 *
 * Visibility uses three different predicates depending on what team_id (or
 * membership) means for that source:
 *  - Dispatch family: team_id is the point's deliberate TARGET — NULL means
 *    "sent to all teams", so it's visible to everyone. ($dispatchScopeSql,
 *    unchanged from the original dispatch-only version of this file.)
 *  - Status/pings/shortage reports: team_id is the ACTOR's own team
 *    membership — NULL means "this person currently has no team", so it
 *    must be private to them + admin, NOT broadcast to every other team.
 *    Using the dispatch predicate here would leak a teamless volunteer's
 *    pings/status/reports to every other team. Predicate 2 never has a
 *    "team_id IS NULL" clause.
 *  - Route Order waypoints: a route may only involve a SUBSET of its
 *    nominal team (mission_route_members, see includes/migrations.php
 *    v109) — predicate 2 (current team membership) would leak an excluded
 *    teammate's sub-route detail and hide it from a loaned-in member, so
 *    this source uses predicate 3: viewer must be an assigned member of
 *    THIS SPECIFIC route.
 *
 * GET only, AJAX.
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();

header('Content-Type: application/json');

$userId = getCurrentUserId();

$missionId = (int) get('mission_id');

$mission = dbFetchOne(
    "SELECT id, title, status, show_in_ops, responsible_user_id FROM missions WHERE id = ? AND deleted_at IS NULL",
    [$missionId]
);
if (!$mission || $mission['status'] !== STATUS_OPEN || empty($mission['show_in_ops'])) {
    echo json_encode(['ok' => false, 'error' => t('common.mission_not_found_or_inactive')]);
    exit;
}

$canManageWarRoom = canManageActionRoom($mission['responsible_user_id'] ? (int)$mission['responsible_user_id'] : null, (int)$userId);
$isApprovedParticipant = (bool) dbFetchValue(
    "SELECT COUNT(*) FROM participation_requests pr
     JOIN shifts s ON s.id = pr.shift_id
     WHERE s.mission_id = ? AND pr.volunteer_id = ? AND pr.status = ?",
    [$missionId, $userId, PARTICIPATION_APPROVED]
);
if (!$canManageWarRoom && !$isApprovedParticipant) {
    echo json_encode(['ok' => false, 'error' => t('common.no_access_action_room')]);
    exit;
}

$isAdminParam = $canManageWarRoom ? 1 : 0;
$viewerTeamId = getUserTeamIdForMission($missionId, $userId);
$viewerLang = getUserLanguage($userId);

// Predicate 1 — dispatch family only (sent/received/arrived).
$dispatchScopeSql = "(d.team_id IS NULL OR ? = 1 OR d.team_id IN (SELECT team_id FROM mission_team_members WHERE user_id = ?))";

$events = [];

// ── dispatch sent ────────────────────────────────────────────────────────────
// Every source query below is capped at 200 rows (matching pingRows' own
// pre-existing LIMIT) — the final merged+sorted feed is itself sliced to the
// 200 most recent events across ALL sources combined (see usort+array_slice
// below), so no single source ever needed more than that many rows in the
// first place; this just stops fetching (and translating/formatting) far
// more than could ever survive the final cut on a mission that's been
// running long enough to accumulate thousands of rows in one table.
$sentRows = dbFetchAll(
    "SELECT d.id, d.type, d.label, d.created_at, d.team_id, mt.codename, mt.team_number, u.name AS actor_name
     FROM mission_dispatch_points d
     LEFT JOIN mission_teams mt ON mt.id = d.team_id
     JOIN users u ON u.id = d.created_by
     WHERE d.mission_id = ? AND $dispatchScopeSql
     ORDER BY d.created_at DESC LIMIT 200",
    [$missionId, $isAdminParam, $userId]
);
foreach ($sentRows as $row) {
    $teamLabel = $row['team_id'] ? teamLabel($row['codename'], $row['team_number']) : t('history.to_all_teams', [], $viewerLang);
    $kind = t($row['type'] === 'point' ? 'history.kind_point' : 'history.kind_area', [], $viewerLang);
    $labelSuffix = $row['label'] ? t('history.label_suffix_dash', ['label' => h($row['label'])], $viewerLang) : '';
    $events[] = [
        'icon' => '📍',
        'text' => t('history.dispatch_sent', ['actor' => h($row['actor_name']), 'kind' => $kind, 'team' => h($teamLabel), 'label_suffix' => $labelSuffix], $viewerLang),
        'time' => date('d/m H:i', strtotime($row['created_at'])),
        'ts'   => strtotime($row['created_at']),
    ];
}

// ── dispatch received ("Ελήφθη") ─────────────────────────────────────────────
$receivedRows = dbFetchAll(
    "SELECT rc.created_at, d.team_id, d.label, mt.codename, mt.team_number, u.name AS actor_name
     FROM mission_dispatch_receipts rc
     JOIN mission_dispatch_points d ON d.id = rc.dispatch_id
     LEFT JOIN mission_teams mt ON mt.id = d.team_id
     JOIN users u ON u.id = rc.user_id
     WHERE d.mission_id = ? AND $dispatchScopeSql
     ORDER BY rc.created_at DESC LIMIT 200",
    [$missionId, $isAdminParam, $userId]
);
foreach ($receivedRows as $row) {
    $teamLabel = $row['team_id'] ? teamLabel($row['codename'], $row['team_number']) : t('history.to_all_teams', [], $viewerLang);
    $labelSuffix = $row['label'] ? t('history.label_suffix_dash', ['label' => h($row['label'])], $viewerLang) : '';
    $events[] = [
        'icon' => '🚩',
        'text' => t('history.dispatch_received', ['actor' => h($row['actor_name']), 'team' => h($teamLabel), 'label_suffix' => $labelSuffix], $viewerLang),
        'time' => date('d/m H:i', strtotime($row['created_at'])),
        'ts'   => strtotime($row['created_at']),
    ];
}

// ── dispatch arrived ──────────────────────────────────────────────────────────
$arrivedRows = dbFetchAll(
    "SELECT a.created_at, a.team_id AS ack_team_id, amt.codename AS ack_codename, amt.team_number AS ack_team_number,
            au.name AS actor_name, d.label AS dispatch_label
     FROM mission_dispatch_acks a
     JOIN mission_dispatch_points d ON d.id = a.dispatch_id
     JOIN users au ON au.id = a.user_id
     LEFT JOIN mission_teams amt ON amt.id = a.team_id
     WHERE d.mission_id = ? AND $dispatchScopeSql
     ORDER BY a.created_at DESC LIMIT 200",
    [$missionId, $isAdminParam, $userId]
);
foreach ($arrivedRows as $row) {
    $teamLabel = $row['ack_team_id'] ? teamLabel($row['ack_codename'], $row['ack_team_number']) : null;
    $labelSuffix = $row['dispatch_label'] ? t('history.label_suffix_at', ['label' => h($row['dispatch_label'])], $viewerLang) : '';
    $events[] = [
        'icon' => '✅',
        'text' => $teamLabel
            ? t('history.dispatch_arrived_team', ['team' => h($teamLabel), 'label_suffix' => $labelSuffix, 'actor' => h($row['actor_name'])], $viewerLang)
            : t('history.dispatch_arrived_solo', ['actor' => h($row['actor_name']), 'label_suffix' => $labelSuffix], $viewerLang),
        'time' => date('d/m H:i', strtotime($row['created_at'])),
        'ts'   => strtotime($row['created_at']),
    ];
}

// ── search areas: created (no status/team, so nothing else to log) ─────────
// Same unscoped rationale as sectors below.
$areaCreatedRows = dbFetchAll(
    "SELECT a.label, a.created_at, cu.name AS actor_name
     FROM mission_search_areas a
     LEFT JOIN users cu ON cu.id = a.created_by
     WHERE a.mission_id = ?
     ORDER BY a.created_at DESC LIMIT 200",
    [$missionId]
);
foreach ($areaCreatedRows as $row) {
    $events[] = [
        'icon' => '🗺️',
        'text' => t('history.area_created', ['actor' => h($row['actor_name'] ?? '—'), 'label' => h($row['label'])], $viewerLang),
        'time' => date('d/m H:i', strtotime($row['created_at'])),
        'ts'   => strtotime($row['created_at']),
    ];
}

// ── search sectors: created / status changed ────────────────────────────────
// Deliberately UNSCOPED (no team predicate, not $dispatchScopeSql above) —
// every team needs to see sector coverage regardless of which team it's
// assigned to, or the whole point of tracking coverage is defeated. Matches
// the same unscoped precedent already set below for incidents/POI ("not
// team-private"), not the assignment-target scoping dispatch uses.
$sectorCreatedRows = dbFetchAll(
    "SELECT s.label, s.team_id, s.created_at, cu.name AS actor_name, mt.codename, mt.team_number
     FROM mission_search_sectors s
     LEFT JOIN users cu ON cu.id = s.created_by
     LEFT JOIN mission_teams mt ON mt.id = s.team_id
     WHERE s.mission_id = ?
     ORDER BY s.created_at DESC LIMIT 200",
    [$missionId]
);
foreach ($sectorCreatedRows as $row) {
    $teamLabel = $row['team_id'] ? teamLabel($row['codename'], $row['team_number']) : t('history.no_team', [], $viewerLang);
    $events[] = [
        'icon' => '🗺️',
        'text' => t('history.sector_created', ['actor' => h($row['actor_name'] ?? '—'), 'label' => h($row['label']), 'team' => h($teamLabel)], $viewerLang),
        'time' => date('d/m H:i', strtotime($row['created_at'])),
        'ts'   => strtotime($row['created_at']),
    ];
}

// ── restricted areas: created (no status/team, same as search areas above) ─
$restrictedAreaCreatedRows = dbFetchAll(
    "SELECT a.label, a.created_at, cu.name AS actor_name
     FROM mission_restricted_areas a
     LEFT JOIN users cu ON cu.id = a.created_by
     WHERE a.mission_id = ?
     ORDER BY a.created_at DESC LIMIT 200",
    [$missionId]
);
foreach ($restrictedAreaCreatedRows as $row) {
    $events[] = [
        'icon' => '⚠️',
        'text' => t('history.restricted_area_created', ['actor' => h($row['actor_name'] ?? '—'), 'label' => h($row['label'])], $viewerLang),
        'time' => date('d/m H:i', strtotime($row['created_at'])),
        'ts'   => strtotime($row['created_at']),
    ];
}

// ── restricted-area breaches: unscoped, same reasoning as sectors above —
// every team needs visibility into a safety incident regardless of which
// team it happened to.
$restrictedAreaBreachRows = dbFetchAll(
    "SELECT b.area_label, b.created_at, b.team_id, u.name AS actor_name, mt.codename, mt.team_number
     FROM mission_restricted_area_breaches b
     JOIN users u ON u.id = b.user_id
     LEFT JOIN mission_teams mt ON mt.id = b.team_id
     WHERE b.mission_id = ?
     ORDER BY b.created_at DESC LIMIT 200",
    [$missionId]
);
foreach ($restrictedAreaBreachRows as $row) {
    $teamLabel = $row['team_id'] ? teamLabel($row['codename'], $row['team_number']) : t('history.no_team', [], $viewerLang);
    $events[] = [
        'icon' => '🚨',
        'text' => t('history.restricted_area_breach', ['actor' => h($row['actor_name']), 'team' => h($teamLabel), 'label' => h($row['area_label'])], $viewerLang),
        'time' => date('d/m H:i', strtotime($row['created_at'])),
        'ts'   => strtotime($row['created_at']),
    ];
}

$sectorStatusRows = dbFetchAll(
    "SELECT l.to_status, l.created_at, s.label, u.name AS actor_name, mt.codename, mt.team_number
     FROM mission_sector_status_log l
     JOIN mission_search_sectors s ON s.id = l.sector_id
     LEFT JOIN users u ON u.id = l.user_id
     LEFT JOIN mission_teams mt ON mt.id = l.team_id
     WHERE s.mission_id = ?
     ORDER BY l.created_at DESC LIMIT 200",
    [$missionId]
);
foreach ($sectorStatusRows as $row) {
    $teamLabel = $row['codename'] ? teamLabel($row['codename'], $row['team_number']) : t('history.no_team', [], $viewerLang);
    $events[] = [
        'icon' => $row['to_status'] === 'completed' ? '✅' : ($row['to_status'] === 'needs_recheck' ? '⚠️' : '🗺️'),
        'text' => t('history.sector_status_changed', [
            'actor' => h($row['actor_name'] ?? '—'), 'label' => h($row['label']),
            'status' => h(sectorStatusLabel($row['to_status'], $viewerLang)), 'team' => h($teamLabel),
        ], $viewerLang),
        'time' => date('d/m H:i', strtotime($row['created_at'])),
        'ts'   => strtotime($row['created_at']),
    ];
}

// ── orders (location/photo/video/task): sent / acknowledged / fulfilled ───────
$orderTypeIcons = ['location' => '📍', 'photo' => '📷', 'video' => '🎥', 'task' => '📋', 'message' => '📢', 'return_to_base' => '🏁', 'route' => '🧭'];
$orderRows = dbFetchAll(
    "SELECT o.order_type, o.task_text, o.created_at AS sent_at, r.team_id, r.acknowledged_at, r.fulfilled_at,
            u.name AS actor_name, mt.codename, mt.team_number
     FROM mission_order_recipients r
     JOIN mission_orders o ON o.id = r.order_id
     JOIN users u ON u.id = r.user_id
     LEFT JOIN mission_teams mt ON mt.id = r.team_id
     WHERE o.mission_id = ? AND (? = 1 OR r.user_id = ? OR r.team_id = ?)
     ORDER BY o.created_at DESC LIMIT 200",
    [$missionId, $isAdminParam, $userId, $viewerTeamId]
);
foreach ($orderRows as $row) {
    $icon = $orderTypeIcons[$row['order_type']] ?? '📋';
    $teamLabel = $row['team_id'] ? teamLabel($row['codename'], $row['team_number']) : t('history.no_team', [], $viewerLang);
    $extra = '';
    if (in_array($row['order_type'], ['task', 'message', 'route'], true) && $row['task_text']) {
        $snippet = mb_strlen($row['task_text']) > 120 ? mb_substr($row['task_text'], 0, 117) . '…' : $row['task_text'];
        $extra = t('history.label_suffix_dash', ['label' => h($snippet)], $viewerLang);
    } elseif ($row['order_type'] === 'return_to_base') {
        // No task_text stored for this type — end_mission_broadcast sends a
        // fixed system phrase, not admin-typed free text (see war-room.php's
        // create action, $taskText stays null there), so it's re-resolved
        // here from the same key it was actually sent with, in the VIEWER's
        // own language like every other event in this feed (not the
        // original recipients' languages, which could differ per-person and
        // aren't what a single shared feed entry can show anyway). Mission
        // title is the one untrusted value in that template, escaped before
        // going in — t()'s own output is then used as-is, same single-escape
        // convention every other 'extra' in this file already follows.
        $extra = t('history.label_suffix_dash', ['label' => t('end_mission_broadcast.message', ['mission' => h($mission['title'])], $viewerLang)], $viewerLang);
    }
    $events[] = [
        'icon' => $icon,
        'text' => t('history.order_sent', ['actor' => h($row['actor_name']), 'team' => h($teamLabel), 'extra' => $extra], $viewerLang),
        'time' => date('d/m H:i', strtotime($row['sent_at'])),
        'ts'   => strtotime($row['sent_at']),
    ];
    if ($row['acknowledged_at']) {
        $events[] = [
            'icon' => '👍',
            'text' => t('history.order_acknowledged', ['actor' => h($row['actor_name']), 'team' => h($teamLabel), 'extra' => $extra], $viewerLang),
            'time' => date('d/m H:i', strtotime($row['acknowledged_at'])),
            'ts'   => strtotime($row['acknowledged_at']),
        ];
    }
    if ($row['fulfilled_at']) {
        $events[] = [
            'icon' => '✅',
            'text' => t('history.order_fulfilled', ['actor' => h($row['actor_name']), 'team' => h($teamLabel), 'extra' => $extra], $viewerLang),
            'time' => date('d/m H:i', strtotime($row['fulfilled_at'])),
            'ts'   => strtotime($row['fulfilled_at']),
        ];
    }
}

// ── Route Order waypoints: depart / arrive / complete / skip ──────────────────
// NOT predicate 2 (team_id) — a route may only involve a subset of its
// nominal team (mission_route_members, see includes/migrations.php v109), so
// gating this on the viewer's own current team would leak a sub-route's
// waypoint-level detail (GPS distance, skip reasons) to teammates who were
// deliberately excluded from it, while hiding it from a member loaned in from
// another team. Predicate 3: viewer must actually be an assigned member of
// THIS route.
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
     WHERE r.mission_id = ? AND (? = 1 OR EXISTS (SELECT 1 FROM mission_route_members rm WHERE rm.route_id = r.id AND rm.user_id = ?))
     ORDER BY GREATEST(
         COALESCE(p.departed_at, '1970-01-01'), COALESCE(p.arrived_at, '1970-01-01'),
         COALESCE(p.completed_at, '1970-01-01'), COALESCE(p.skipped_at, '1970-01-01')
     ) DESC LIMIT 200",
    [$missionId, $isAdminParam, $userId]
);
foreach ($routeProgressRows as $row) {
    $teamLabel = $row['team_id'] ? teamLabel($row['codename'], $row['team_number']) : t('history.no_team', [], $viewerLang);
    $pointLabel = $row['label'] !== null && $row['label'] !== '' ? $row['label'] : t('route.waypoint_fallback_label', ['seq' => $row['seq']], $viewerLang);
    if ($row['departed_at']) {
        $events[] = [
            'icon' => '🚶',
            'text' => t('history.route_departed', ['team' => h($teamLabel), 'label' => h($pointLabel), 'actor' => h($row['departed_by_name'] ?? '')], $viewerLang),
            'time' => date('d/m H:i', strtotime($row['departed_at'])),
            'ts'   => strtotime($row['departed_at']),
        ];
    }
    if ($row['arrived_at']) {
        $distanceSuffix = $row['arrived_distance_m'] !== null
            ? ' — ' . t('route.distance_from_point', ['m' => (int) $row['arrived_distance_m']], $viewerLang)
            : '';
        $events[] = [
            'icon' => '📍',
            'text' => t('history.route_arrived', ['team' => h($teamLabel), 'label' => h($pointLabel), 'distance_suffix' => $distanceSuffix, 'actor' => h($row['arrived_by_name'] ?? '')], $viewerLang),
            'time' => date('d/m H:i', strtotime($row['arrived_at'])),
            'ts'   => strtotime($row['arrived_at']),
        ];
    }
    if ($row['completed_at']) {
        $events[] = [
            'icon' => '✅',
            'text' => t('history.route_completed', ['team' => h($teamLabel), 'label' => h($pointLabel), 'actor' => h($row['completed_by_name'] ?? '')], $viewerLang),
            'time' => date('d/m H:i', strtotime($row['completed_at'])),
            'ts'   => strtotime($row['completed_at']),
        ];
    }
    if ($row['skipped_at']) {
        $reasonSuffix = $row['skip_reason'] ? t('history.label_suffix_dash', ['label' => h($row['skip_reason'])], $viewerLang) : '';
        $events[] = [
            'icon' => '⏭',
            'text' => t('history.route_skipped', ['team' => h($teamLabel), 'label' => h($pointLabel), 'reason_suffix' => $reasonSuffix, 'actor' => h($row['skipped_by_name'] ?? '')], $viewerLang),
            'time' => date('d/m H:i', strtotime($row['skipped_at'])),
            'ts'   => strtotime($row['skipped_at']),
        ];
    }
}

// ── field-status changes (on_way / on_site / needs_help) via audit_logs ───────
// table_name filter is load-bearing: audit_logs.record_id has no FK, so
// without it this join would match unrelated logAudit() call sites by
// coincidental numeric id.
$fieldStatusIcons = ['field_status_on_way' => '🚗', 'field_status_on_site' => '✅', 'needs_help' => '🆘'];
$fieldStatusText  = [
    'field_status_on_way' => t('history.status_on_way', [], $viewerLang),
    'field_status_on_site' => t('history.status_on_site', [], $viewerLang),
    'needs_help' => t('history.status_needs_help', [], $viewerLang),
];
$statusRows = dbFetchAll(
    "SELECT al.action, al.created_at, u.name AS actor_name, mtm.team_id AS actor_team_id
     FROM audit_logs al
     JOIN participation_requests pr ON pr.id = al.record_id
     JOIN shifts s ON s.id = pr.shift_id
     JOIN users u ON u.id = pr.volunteer_id
     LEFT JOIN mission_team_members mtm ON mtm.mission_id = s.mission_id AND mtm.user_id = pr.volunteer_id
     WHERE al.table_name = 'participation_requests'
       AND al.action IN ('field_status_on_way', 'field_status_on_site', 'needs_help')
       AND s.mission_id = ? AND (? = 1 OR pr.volunteer_id = ? OR mtm.team_id = ?)
     ORDER BY al.created_at DESC LIMIT 200",
    [$missionId, $isAdminParam, $userId, $viewerTeamId]
);
foreach ($statusRows as $row) {
    $events[] = [
        'icon' => $fieldStatusIcons[$row['action']] ?? '📶',
        'text' => t('history.field_status_change', ['actor' => h($row['actor_name']), 'status' => $fieldStatusText[$row['action']]], $viewerLang),
        'time' => date('d/m H:i', strtotime($row['created_at'])),
        'ts'   => strtotime($row['created_at']),
    ];
}

// ── GPS pings ("στίγματα") ─────────────────────────────────────────────────────
$pingRows = dbFetchAll(
    "SELECT vp.created_at, u.name AS actor_name, mtm.team_id AS actor_team_id
     FROM volunteer_pings vp
     JOIN shifts s ON s.id = vp.shift_id
     JOIN users u ON u.id = vp.user_id
     LEFT JOIN mission_team_members mtm ON mtm.mission_id = s.mission_id AND mtm.user_id = vp.user_id
     WHERE s.mission_id = ? AND vp.source = 'manual' AND (? = 1 OR vp.user_id = ? OR mtm.team_id = ?)
     ORDER BY vp.created_at DESC LIMIT 150",
    [$missionId, $isAdminParam, $userId, $viewerTeamId]
);
foreach ($pingRows as $row) {
    $events[] = [
        'icon' => '📡',
        'text' => t('history.gps_ping_sent', ['actor' => h($row['actor_name'])], $viewerLang),
        'time' => date('d/m H:i', strtotime($row['created_at'])),
        'ts'   => strtotime($row['created_at']),
    ];
}

// ── shortage reports: submitted / seen / resolved ──────────────────────────────
// Keyed off the report's own reporter_id/team_id for all three sub-events —
// never acknowledged_by/resolved_by (almost always the admin), which would
// silently break the reporter's own visibility into their report's outcome.
$shortageRows = dbFetchAll(
    "SELECT r.shortage_type, r.title, r.created_at, r.acknowledged_at, r.resolved_at, u.name AS actor_name
     FROM mission_shortage_reports r
     JOIN users u ON u.id = r.reporter_id
     WHERE r.mission_id = ? AND (? = 1 OR r.reporter_id = ? OR r.team_id = ?)
     ORDER BY r.created_at DESC LIMIT 200",
    [$missionId, $isAdminParam, $userId, $viewerTeamId]
);
foreach ($shortageRows as $row) {
    $label = shortageTypeLabel($row['shortage_type'], $viewerLang);
    $events[] = [
        'icon' => '⚠️',
        'text' => t('history.shortage_reported', ['actor' => h($row['actor_name']), 'type' => h($label), 'title' => h($row['title'])], $viewerLang),
        'time' => date('d/m H:i', strtotime($row['created_at'])),
        'ts'   => strtotime($row['created_at']),
    ];
    if ($row['acknowledged_at']) {
        $events[] = [
            'icon' => '👁️',
            'text' => t('history.shortage_seen', ['title' => h($row['title'])], $viewerLang),
            'time' => date('d/m H:i', strtotime($row['acknowledged_at'])),
            'ts'   => strtotime($row['acknowledged_at']),
        ];
    }
    if ($row['resolved_at']) {
        $events[] = [
            'icon' => '✅',
            'text' => t('history.shortage_resolved', ['title' => h($row['title'])], $viewerLang),
            'time' => date('d/m H:i', strtotime($row['resolved_at'])),
            'ts'   => strtotime($row['resolved_at']),
        ];
    }
}

// ── incidents: submitted / seen / resolved ──────────────────────────────────
// Visible to every approved participant, not scoped to the reporter's own
// team like shortage/orders above — mirrors loadUnresolvedIncidentsForMission()'s
// own "any approved participant sees it" policy for the live incidents panel,
// since a medical emergency isn't team-private the way a gear shortage is.
// Never includes patient_name/phone/notes (staff-only elsewhere, masked even
// on the live panel for non-command-staff) — same restraint
// loadMissionActivityEventsForReport() already applies for the PDF/Excel export.
$incidentRows = dbFetchAll(
    "SELECT i.incident_type, i.severity, i.created_at, i.acknowledged_at, i.resolved_at, u.name AS actor_name
     FROM mission_incidents i
     JOIN users u ON u.id = i.reporter_id
     WHERE i.mission_id = ?
     ORDER BY i.created_at DESC LIMIT 200",
    [$missionId]
);
foreach ($incidentRows as $row) {
    $label = incidentTypeLabel($row['incident_type'], $viewerLang) . ', ' . incidentSeverityLabel($row['severity'], $viewerLang);
    $events[] = [
        'icon' => '🚑',
        'text' => t('history.incident_reported', ['actor' => h($row['actor_name']), 'type' => h($label)], $viewerLang),
        'time' => date('d/m H:i', strtotime($row['created_at'])),
        'ts'   => strtotime($row['created_at']),
    ];
    if ($row['acknowledged_at']) {
        $events[] = [
            'icon' => '👁️',
            'text' => t('history.incident_seen', ['type' => h($label)], $viewerLang),
            'time' => date('d/m H:i', strtotime($row['acknowledged_at'])),
            'ts'   => strtotime($row['acknowledged_at']),
        ];
    }
    if ($row['resolved_at']) {
        $events[] = [
            'icon' => '✅',
            'text' => t('history.incident_resolved', ['type' => h($label)], $viewerLang),
            'time' => date('d/m H:i', strtotime($row['resolved_at'])),
            'ts'   => strtotime($row['resolved_at']),
        ];
    }
}

// ── points of interest: reported (per photo) / checked (per POI group) ─────
// Visible to every approved participant, same policy as incidents above — a
// physical clue found during a search is not team-private. One "reported"
// event per PHOTO, not per POI group: independent corroboration from
// several volunteers should each show up individually here (same reasoning
// as loadPointsOfInterestForMission()'s own "list every reporter, don't
// collapse to one" design), but "checked" fires once per POI, not once per
// photo merged into it.
$poiPhotoRows = dbFetchAll(
    "SELECT ph.poi_note, ph.created_at, u.name AS actor_name
     FROM mission_photos ph
     JOIN users u ON u.id = ph.user_id
     WHERE ph.mission_id = ? AND ph.poi_id IS NOT NULL
     ORDER BY ph.created_at DESC LIMIT 200",
    [$missionId]
);
foreach ($poiPhotoRows as $row) {
    // Reuses the same dash+quote suffix dispatch labels already use — same
    // visual shape, no new translation key needed for what's structurally
    // the same "optional short free text, tacked onto the base sentence".
    $noteSuffix = $row['poi_note'] ? t('history.label_suffix_dash', ['label' => h($row['poi_note'])], $viewerLang) : '';
    $events[] = [
        'icon' => '📍',
        'text' => t('history.poi_reported', ['actor' => h($row['actor_name'])], $viewerLang) . $noteSuffix,
        'time' => date('d/m H:i', strtotime($row['created_at'])),
        'ts'   => strtotime($row['created_at']),
    ];
}
$poiCheckedRows = dbFetchAll(
    "SELECT checked_at FROM mission_points_of_interest WHERE mission_id = ? AND checked_at IS NOT NULL ORDER BY checked_at DESC LIMIT 200",
    [$missionId]
);
foreach ($poiCheckedRows as $row) {
    $events[] = [
        'icon' => '✅',
        'text' => t('history.poi_checked', [], $viewerLang),
        'time' => date('d/m H:i', strtotime($row['checked_at'])),
        'ts'   => strtotime($row['checked_at']),
    ];
}

usort($events, fn($a, $b) => $b['ts'] <=> $a['ts']);
$events = array_slice($events, 0, 200);

echo json_encode([
    'ok'     => true,
    'events' => array_map(fn($e) => ['icon' => $e['icon'], 'text' => $e['text'], 'time' => $e['time']], $events),
]);
