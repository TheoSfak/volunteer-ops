<?php
/**
 * VolunteerOps - Mission Route Order Endpoint ("Εντολή Πορείας")
 * War Room: an ordered, multi-waypoint patrol assigned to one team. Each
 * waypoint carries its own instructions, an advisory dwell time, and which
 * deliverables (photo/video/note) it expects. Rides on top of mission_orders
 * (order_type='route') purely to inherit push/banner/"Ελήφθη"/audit for free
 * — see includes/migrations.php v105 for the full rationale. GET polls for
 * the routes visible to the caller, POST creates/cancels a route or advances
 * a single waypoint (depart/arrive/complete/skip). AJAX only.
 *
 * Safety rules baked into every action below (agreed with the mission owner
 * before building this):
 *  - Dwell time is advisory, never blocking — there is no server-side lock
 *    tied to it anywhere in this file.
 *  - Sequence is enforced softly: acting on a later waypoint than the first
 *    still-open one requires confirm_out_of_sequence=1, never a hard block.
 *    The expected caller already resolves this client-side (it already has
 *    the full route in memory) — the check here is defense-in-depth, not
 *    the primary UX path.
 *  - A field action never fails because an earlier step was skipped by the
 *    user (e.g. "complete" before "arrive" was ever clicked) — timestamps
 *    get defensively backfilled instead of rejected.
 *  - Progress is TEAM state, not per-user: one mission_route_progress row
 *    per waypoint, first member to report an event advances the whole team.
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();

header('Content-Type: application/json');

/**
 * Notify command staff (system/department admins, this mission's shift leaders,
 * and its responsible user) about a route-level event. Mirrors the recipient
 * resolution mission-dispatch.php/mission-photo.php already use.
 */
function notifyRouteCommandStaff(int $missionId, string $missionTitle, ?int $responsibleUserId, int $actorId, string $code, string $titleKey, array $titleVars, string $messageKey, array $messageVars): void {
    $warRoomUrl = rtrim(BASE_URL, '/') . '/war-room.php?id=' . $missionId;
    $recipientIds = getMissionCommandStaffIds($missionId, $responsibleUserId, $actorId);
    $langByUserId = getUserLanguages($recipientIds);
    foreach ($recipientIds as $recipientId) {
        $lang = $langByUserId[$recipientId] ?? DEFAULT_LANGUAGE;
        sendNotification($recipientId, t($titleKey, $titleVars, $lang), t($messageKey, $messageVars, $lang), 'info', $code, [
            'url' => $warRoomUrl,
            'tag' => 'route-' . $code . '-mission-' . $missionId,
            'bannerMission' => $missionId,
        ]);
    }
}

/**
 * Notify every member actually assigned to a route about a route-level event
 * — used for "point skipped" and "route cancelled", the two admin-initiated
 * events the field team itself must be pushed (per the noise-reduction rule:
 * push only on arrival/skip/cancel/route-completion, everything else rides
 * the silent 5s poll). Keyed on route_id (mission_route_members), not
 * team_id — a route may only involve a subset of its nominal team.
 */
function notifyRouteTeam(int $missionId, int $routeId, int $excludeUserId, string $code, string $titleKey, array $titleVars, string $messageKey, array $messageVars): void {
    $warRoomUrl = rtrim(BASE_URL, '/') . '/war-room.php?id=' . $missionId;
    $memberIds = array_values(array_diff(
        array_map('intval', array_column(dbFetchAll("SELECT user_id FROM mission_route_members WHERE route_id = ?", [$routeId]), 'user_id')),
        [$excludeUserId]
    ));
    $langByUserId = getUserLanguages($memberIds);
    foreach ($memberIds as $memberId) {
        $lang = $langByUserId[$memberId] ?? DEFAULT_LANGUAGE;
        sendNotification($memberId, t($titleKey, $titleVars, $lang), t($messageKey, $messageVars, $lang), 'warning', $code, [
            'url' => $warRoomUrl,
            'tag' => 'route-' . $code . '-mission-' . $missionId,
            'bannerMission' => $missionId,
        ]);
    }
}

/**
 * A waypoint plus enough of its parent route/progress to authorize and act
 * on it in one query. Returns null if the waypoint doesn't belong to $missionId.
 * is_route_member reflects mission_route_members for $userId — the sole
 * authorization boundary for depart/arrive/complete/skip, independent of
 * current mission_team_members roster (see migration v109).
 */
function loadWaypointForAction(int $waypointId, int $missionId, int $userId): ?array {
    $row = dbFetchOne(
        "SELECT w.id, w.route_id, w.seq, w.lat, w.lng, w.label,
                w.require_photo, w.require_video, w.require_note,
                r.mission_id, r.team_id, r.completed_at AS route_completed_at, r.cancelled_at AS route_cancelled_at,
                p.departed_at, p.arrived_at, p.completed_at, p.skipped_at, p.out_of_sequence, p.note,
                EXISTS (SELECT 1 FROM mission_route_members rm WHERE rm.route_id = r.id AND rm.user_id = ?) AS is_route_member
         FROM mission_route_waypoints w
         JOIN mission_routes r ON r.id = w.route_id
         LEFT JOIN mission_route_progress p ON p.waypoint_id = w.id
         WHERE w.id = ? AND r.mission_id = ?",
        [$userId, $waypointId, $missionId]
    );
    return $row ?: null;
}

/**
 * Whichever of require_photo/require_video/require_note the admin set for
 * this waypoint but which "complete" would otherwise leave unfulfilled —
 * checked against what's already on record (an earlier upload, an earlier
 * saved note) UNION what this exact request is submitting, since the note
 * textarea and "Complete" are one and the same tap in the field UI.
 * Returns translated labels, empty if nothing is missing.
 */
function missingRouteDeliverables(array $wp, ?string $submittedNote): array {
    $missing = [];
    if ($wp['require_photo'] && !dbFetchValue(
        "SELECT 1 FROM mission_photos WHERE route_waypoint_id = ? AND media_type = 'photo' LIMIT 1", [$wp['id']]
    )) {
        $missing[] = t('route.deliverable_photo');
    }
    if ($wp['require_video'] && !dbFetchValue(
        "SELECT 1 FROM mission_photos WHERE route_waypoint_id = ? AND media_type = 'video' LIMIT 1", [$wp['id']]
    )) {
        $missing[] = t('route.deliverable_video');
    }
    if ($wp['require_note']) {
        $effectiveNote = $submittedNote !== null ? $submittedNote : ($wp['note'] ?? null);
        if ($effectiveNote === null || trim($effectiveNote) === '') {
            $missing[] = t('route.deliverable_note');
        }
    }
    return $missing;
}

/** The lowest seq in $routeId that isn't closed yet (completed or skipped), or null if none. */
function currentWaypointSeq(int $routeId): ?int {
    $seq = dbFetchValue(
        "SELECT w.seq FROM mission_route_waypoints w
         LEFT JOIN mission_route_progress p ON p.waypoint_id = w.id
         WHERE w.route_id = ? AND p.completed_at IS NULL AND p.skipped_at IS NULL
         ORDER BY w.seq ASC LIMIT 1",
        [$routeId]
    );
    return ($seq !== false && $seq !== null) ? (int) $seq : null;
}

// resolveEventTimestamp() — the offline-queue replay clock — now lives in
// includes/functions.php, since volunteer-status.php (field status / SOS)
// replays through the same queue and needs the identical rules. Behaviour
// here is unchanged.

/**
 * Whether every waypoint of $routeId is now closed (completed or skipped) —
 * if so, the route itself is done: stamp mission_routes.completed_at and
 * auto-fulfill the underlying mission_orders recipients (mirrors how a
 * 'task' order is fulfilled, except a route fulfills itself the moment its
 * last stop closes instead of waiting on a manual complete click).
 */
function maybeCompleteRoute(int $routeId, int $actorId): bool {
    $remaining = (int) dbFetchValue(
        "SELECT COUNT(*) FROM mission_route_waypoints w
         LEFT JOIN mission_route_progress p ON p.waypoint_id = w.id
         WHERE w.route_id = ? AND p.completed_at IS NULL AND p.skipped_at IS NULL",
        [$routeId]
    );
    if ($remaining > 0) {
        return false;
    }
    $route = dbFetchOne("SELECT id, mission_id, team_id, order_id, completed_at FROM mission_routes WHERE id = ?", [$routeId]);
    if (!$route || $route['completed_at']) {
        return false;
    }

    // AND completed_at IS NULL: this function is called from depart/arrive/
    // complete/skip, so two waypoints of the same route closing within the
    // same instant (or a live tap racing an offline-queue replay) can both
    // reach here having both seen $route['completed_at'] as still null. The
    // WHERE guard + rowCount() check ensures only one of them actually
    // stamps the route complete and fires the "route completed"
    // notification — without it, both would.
    $rows = dbExecute("UPDATE mission_routes SET completed_at = NOW() WHERE id = ? AND completed_at IS NULL", [$routeId]);
    if ($rows === 0) {
        return false;
    }
    if ($route['order_id']) {
        dbExecute("UPDATE mission_order_recipients SET fulfilled_at = NOW() WHERE order_id = ? AND fulfilled_at IS NULL", [$route['order_id']]);
    }

    $mission = dbFetchOne("SELECT title, responsible_user_id FROM missions WHERE id = ?", [$route['mission_id']]);
    $teamRow = $route['team_id'] ? dbFetchOne("SELECT codename, team_number FROM mission_teams WHERE id = ?", [$route['team_id']]) : null;
    $teamLbl = $teamRow ? teamLabel($teamRow['codename'], $teamRow['team_number']) : routeMixedTeamLabel($routeId);
    notifyRouteCommandStaff(
        (int) $route['mission_id'], $mission['title'] ?? '', $mission['responsible_user_id'] ? (int) $mission['responsible_user_id'] : null, $actorId,
        'mission_route_completed', 'route.notify_completed_title', [],
        'route.notify_completed_message', ['team' => $teamLbl, 'mission' => $mission['title'] ?? '']
    );
    logAudit('complete_mission_route', 'mission_routes', $routeId, null, ['mission_id' => $route['mission_id'], 'team_id' => $route['team_id']]);
    return true;
}

$userId = getCurrentUserId();
$user = getCurrentUser();

$missionId = (int) (isPost() ? post('mission_id') : get('mission_id'));

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

// ── GET: poll for routes visible to me ──────────────────────────────────
if (!isPost()) {
    echo json_encode(['ok' => true, 'routes' => loadRoutesForUser($missionId, $userId, $canManageWarRoom)]);
    exit;
}

// ── POST ─────────────────────────────────────────────────────────────────
if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string) $_POST['csrf_token'])) {
    echo json_encode(['ok' => false, 'error' => t('common.invalid_request')]);
    exit;
}

$action = post('action');

if ($action === 'create') {
    if (!$canManageWarRoom) {
        echo json_encode(['ok' => false, 'error' => t('dispatch.no_manage_permission')]);
        exit;
    }

    // team_id is optional (migration v110): a route can be assembled from
    // specific individuals across two or more different teams instead of
    // always belonging to one nominal team — see the cross-team branch below.
    $teamIdRaw = post('team_id');
    $teamId = ($teamIdRaw !== '' && $teamIdRaw !== null) ? (int) $teamIdRaw : null;
    $team = null;
    if ($teamId !== null) {
        $team = dbFetchOne("SELECT id, codename, team_number FROM mission_teams WHERE id = ? AND mission_id = ?", [$teamId, $missionId]);
        if (!$team) {
            echo json_encode(['ok' => false, 'error' => t('common.team_not_found')]);
            exit;
        }
    }

    $title = trim((string) post('title'));
    $title = $title !== '' ? mb_substr($title, 0, 255) : null;

    $waypointsRaw = json_decode((string) post('waypoints'), true);
    if (!is_array($waypointsRaw) || count($waypointsRaw) < 1) {
        echo json_encode(['ok' => false, 'error' => t('route.needs_one_waypoint')]);
        exit;
    }
    if (count($waypointsRaw) > 30) {
        echo json_encode(['ok' => false, 'error' => t('route.too_many_waypoints')]);
        exit;
    }

    $waypoints = [];
    foreach ($waypointsRaw as $wp) {
        if (!is_array($wp) || !isset($wp['lat'], $wp['lng']) || !is_numeric($wp['lat']) || !is_numeric($wp['lng'])) {
            echo json_encode(['ok' => false, 'error' => t('route.invalid_waypoint')]);
            exit;
        }
        // Same range check ping-location.php already applies to a live GPS
        // fix — a waypoint is admin-placed, not device-reported, but a
        // malformed/out-of-range value here would still corrupt the map and
        // every downstream distance/ETA calculation against it.
        $wpLat = (float) $wp['lat']; $wpLng = (float) $wp['lng'];
        if ($wpLat < -90 || $wpLat > 90 || $wpLng < -180 || $wpLng > 180 || ($wpLat == 0 && $wpLng == 0)) {
            echo json_encode(['ok' => false, 'error' => t('route.invalid_waypoint')]);
            exit;
        }
        $label = isset($wp['label']) ? trim((string) $wp['label']) : '';
        $instructions = isset($wp['instructions']) ? trim((string) $wp['instructions']) : '';
        $dwellRaw = $wp['dwell_minutes'] ?? null;
        $waypoints[] = [
            'lat'           => (float) $wp['lat'],
            'lng'           => (float) $wp['lng'],
            'label'         => $label !== '' ? mb_substr($label, 0, 255) : null,
            'instructions'  => $instructions !== '' ? mb_substr($instructions, 0, 2000) : null,
            'dwell_minutes' => ($dwellRaw !== null && $dwellRaw !== '' && is_numeric($dwellRaw)) ? max(0, (int) $dwellRaw) : null,
            'require_photo' => !empty($wp['require_photo']) ? 1 : 0,
            'require_video' => !empty($wp['require_video']) ? 1 : 0,
            'require_note'  => !empty($wp['require_note']) ? 1 : 0,
        ];
    }

    // Captures the team's own current position (their latest ping, same
    // "team is one unit" join shape computeDispatchEta() uses) and appends
    // it as one more real waypoint, so the patrol's last leg is a genuine
    // tracked depart/arrive/complete back to wherever the team actually
    // started — not just cosmetic. Single-team mode only: cross-team routes
    // have no one team to capture a position from, and the composer itself
    // hides this checkbox in that mode, so add_return_waypoint should never
    // even arrive truthy here — the $teamId check is still the real
    // authorization boundary, not just a UI nicety. Silently does nothing if
    // the team has no ping yet, same "advisory, never blocks route creation"
    // choice already made for is_closed_loop just below.
    if (post('add_return_waypoint') === '1' && $teamId !== null) {
        $startPing = dbFetchOne(
            "SELECT vp.lat, vp.lng
             FROM volunteer_pings vp
             JOIN mission_team_members mtm ON mtm.user_id = vp.user_id
             JOIN shifts s ON s.id = vp.shift_id AND s.mission_id = mtm.mission_id
             WHERE mtm.team_id = ?
             ORDER BY vp.created_at DESC LIMIT 1",
            [$teamId]
        );
        if ($startPing) {
            $waypoints[] = [
                'lat'           => (float) $startPing['lat'],
                'lng'           => (float) $startPing['lng'],
                'label'         => t('route.return_to_start_label'),
                'instructions'  => null,
                'dwell_minutes' => null,
                'require_photo' => 0,
                'require_video' => 0,
                'require_note'  => 0,
            ];
        }
    }

    $memberIdsRaw = json_decode((string) post('member_ids'), true);
    $submittedMemberIds = is_array($memberIdsRaw) ? array_map('intval', $memberIdsRaw) : [];

    if ($teamId !== null) {
        // Single-team mode. Optional subset of the team this specific route
        // actually applies to (e.g. only 2 of 4 members sent on a temporary
        // task) — see migration v109. Every submitted id is intersected
        // against the team's real roster: a forged member_ids array must
        // never be able to add a recipient/actor who isn't even on this
        // team. No subset submitted (or nothing survives the intersection)
        // falls back to the whole team — the composer's default, unchanged
        // old behavior.
        $teamRoster = array_map('intval', array_column(dbFetchAll("SELECT user_id FROM mission_team_members WHERE team_id = ?", [$teamId]), 'user_id'));
        if (empty($teamRoster)) {
            echo json_encode(['ok' => false, 'error' => t('route.team_has_no_members')]);
            exit;
        }
        $recipientIds = !empty($submittedMemberIds)
            ? array_values(array_intersect($teamRoster, $submittedMemberIds))
            : $teamRoster;
        if (empty($recipientIds)) {
            $recipientIds = $teamRoster;
        }
        $teamLbl = teamLabel($team['codename'], $team['team_number']);
    } else {
        // Cross-team mode (migration v110): no single team, so there's no
        // "whole roster" to fall back to — the admin must explicitly pick
        // who this route applies to. Validated against every approved
        // participant of the MISSION (not just one team), so a forged id
        // still can't add a recipient/actor who isn't even on this mission.
        $approvedIds = array_map('intval', array_column(dbFetchAll(
            "SELECT DISTINCT pr.volunteer_id FROM participation_requests pr
             JOIN shifts s ON s.id = pr.shift_id
             WHERE s.mission_id = ? AND pr.status = ?",
            [$missionId, PARTICIPATION_APPROVED]
        ), 'volunteer_id'));
        $recipientIds = array_values(array_intersect($approvedIds, $submittedMemberIds));
        if (empty($recipientIds)) {
            echo json_encode(['ok' => false, 'error' => t('route.select_at_least_one_member')]);
            exit;
        }
        // No team codename exists for a cross-team route — the members'
        // own names stand in for it wherever {team} is substituted into a
        // notification template (see routeMixedTeamLabel()'s docblock for
        // why this must stay untranslated/language-neutral).
        $memberNamePlaceholders = implode(',', array_fill(0, count($recipientIds), '?'));
        $teamLbl = implode(', ', array_column(dbFetchAll(
            "SELECT name FROM users WHERE id IN ($memberNamePlaceholders) ORDER BY name",
            $recipientIds
        ), 'name'));
    }

    // Purely a map-rendering hint (draw the connecting line back to point 1) —
    // never adds a waypoint the team has to visit, and geometrically meaningless
    // under 3 points, so silently ignore rather than reject a 1-2 point route
    // that happened to send it (e.g. a stale composer click before points were added).
    $isClosedLoop = (post('is_closed_loop') === '1' && count($waypoints) >= 3) ? 1 : 0;

    $routeId = dbInsert(
        "INSERT INTO mission_routes (mission_id, team_id, title, is_closed_loop, created_by, created_at) VALUES (?, ?, ?, ?, ?, NOW())",
        [$missionId, $teamId, $title, $isClosedLoop, $userId]
    );
    foreach ($recipientIds as $memberId) {
        dbInsert("INSERT INTO mission_route_members (route_id, user_id, added_at) VALUES (?, ?, NOW())", [$routeId, $memberId]);
    }

    // task_text on the underlying order is just a compact summary — the real
    // per-waypoint content lives in mission_route_waypoints and is what the
    // "Η Πορεία μου" card actually renders; this only feeds the generic
    // order history/report views that already know how to print task_text.
    $summary = $title
        ? t('route.order_summary_titled', ['title' => $title, 'count' => count($waypoints)])
        : t('route.order_summary_untitled', ['count' => count($waypoints)]);

    $orderId = createMissionOrderAndNotify(
        $missionId, $mission['title'], 'route', $userId, $recipientIds,
        'order.route.title', ['team' => $teamLbl],
        null, 'order.route.message', ['team' => $teamLbl, 'count' => count($waypoints)],
        'order.route.broadcast', ['team' => $teamLbl, 'mission' => $mission['title']],
        $summary
    );
    dbExecute("UPDATE mission_routes SET order_id = ? WHERE id = ?", [$orderId, $routeId]);

    $seq = 1;
    foreach ($waypoints as $wp) {
        $waypointId = dbInsert(
            "INSERT INTO mission_route_waypoints (route_id, seq, lat, lng, label, instructions, dwell_minutes, require_photo, require_video, require_note, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
            [$routeId, $seq, $wp['lat'], $wp['lng'], $wp['label'], $wp['instructions'], $wp['dwell_minutes'], $wp['require_photo'], $wp['require_video'], $wp['require_note']]
        );
        dbInsert("INSERT INTO mission_route_progress (waypoint_id, route_id, team_id) VALUES (?, ?, ?)", [$waypointId, $routeId, $teamId]);
        $seq++;
    }

    logAudit('create_mission_route', 'mission_routes', $routeId, null, ['mission_id' => $missionId, 'team_id' => $teamId, 'member_ids' => $recipientIds, 'waypoints' => count($waypoints)]);

    echo json_encode(['ok' => true, 'routes' => loadRoutesForUser($missionId, $userId, $canManageWarRoom)]);
    exit;
}

if ($action === 'cancel') {
    if (!$canManageWarRoom) {
        echo json_encode(['ok' => false, 'error' => t('dispatch.no_manage_permission')]);
        exit;
    }
    $routeId = (int) post('id');
    $route = dbFetchOne("SELECT id, team_id, order_id, completed_at, cancelled_at FROM mission_routes WHERE id = ? AND mission_id = ?", [$routeId, $missionId]);
    if (!$route) {
        echo json_encode(['ok' => false, 'error' => t('common.not_found')]);
        exit;
    }
    if ($route['completed_at'] || $route['cancelled_at']) {
        echo json_encode(['ok' => false, 'error' => t('route.already_closed')]);
        exit;
    }

    $reason = trim((string) post('reason'));
    $reason = $reason !== '' ? mb_substr($reason, 0, 255) : null;

    dbExecute("UPDATE mission_routes SET cancelled_at = NOW(), cancelled_by = ?, cancel_reason = ? WHERE id = ?", [$userId, $reason, $routeId]);
    // A cancelled route is the admin's own call to abort, not the team failing to
    // deliver — stamp fulfilled_at too so it stops counting as "outstanding" in the
    // generic mission_orders fulfillment-rate stats (mission-stats.php) it rides on.
    if ($route['order_id']) {
        dbExecute("UPDATE mission_order_recipients SET fulfilled_at = NOW() WHERE order_id = ? AND fulfilled_at IS NULL", [$route['order_id']]);
    }

    notifyRouteTeam($missionId, $routeId, $userId, 'mission_route_cancelled', 'route.notify_cancelled_title', [], 'route.notify_cancelled_message', ['mission' => $mission['title']]);
    logAudit('cancel_mission_route', 'mission_routes', $routeId, null, ['mission_id' => $missionId, 'reason' => $reason]);

    echo json_encode(['ok' => true, 'routes' => loadRoutesForUser($missionId, $userId, $canManageWarRoom)]);
    exit;
}

if ($action === 'depart' || $action === 'arrive' || $action === 'complete') {
    if (!$isApprovedParticipant) {
        echo json_encode(['ok' => false, 'error' => t('route.only_approved_can_report')]);
        exit;
    }
    $waypointId = (int) post('id');
    $wp = loadWaypointForAction($waypointId, $missionId, $userId);
    if (!$wp) {
        echo json_encode(['ok' => false, 'error' => t('common.not_found')]);
        exit;
    }
    if ($wp['route_cancelled_at'] || $wp['route_completed_at']) {
        echo json_encode(['ok' => false, 'error' => t('route.already_closed')]);
        exit;
    }
    // Field actions are proof-of-presence — only a person this specific route
    // was actually assigned to (mission_route_members, not just "any member of
    // the nominal team") can report on it, admin privileges don't substitute
    // (mirrors mission-dispatch.php's ack/receive: "not_your_team" applies
    // even to command staff unless they're also an assigned route member).
    if (!$wp['is_route_member']) {
        echo json_encode(['ok' => false, 'error' => t('dispatch.not_your_team')]);
        exit;
    }
    if ($wp['completed_at'] || $wp['skipped_at']) {
        echo json_encode(['ok' => false, 'error' => t('route.waypoint_already_closed')]);
        exit;
    }

    $currentSeq = currentWaypointSeq((int) $wp['route_id']);
    $alreadyFlagged = (bool) $wp['out_of_sequence'];
    $isOutOfSequence = $currentSeq !== null && (int) $wp['seq'] > $currentSeq;
    if ($isOutOfSequence && !$alreadyFlagged && post('confirm_out_of_sequence') !== '1') {
        echo json_encode(['ok' => false, 'needs_confirm' => true, 'error' => t('route.confirm_out_of_sequence')]);
        exit;
    }
    $outOfSequence = ($isOutOfSequence || $alreadyFlagged) ? 1 : 0;
    [$eventTs, $reportedAtTs] = resolveEventTimestamp();

    if ($action === 'depart') {
        // Moving on to a later stop implicitly closes out whichever earlier
        // stop(s) of this route are still open — in the field nobody clicks a
        // separate "done" button for the point they're leaving, they just move
        // (see includes/migrations.php v105's docblock). The explicit
        // "complete" action below still exists for the last stop of a route,
        // which has no next point to depart to. Uses the same resolved
        // $eventTs as the depart itself — these earlier stops' true close
        // time is "at or before" this action's, and NOW() would be wrong for
        // all of it on an offline-queued replay.
        dbExecute(
            "UPDATE mission_route_progress p
             JOIN mission_route_waypoints w ON w.id = p.waypoint_id
             SET p.completed_at = ?, p.completed_by = ?,
                 p.arrived_at = COALESCE(p.arrived_at, ?), p.arrived_by = COALESCE(p.arrived_by, ?),
                 p.departed_at = COALESCE(p.departed_at, ?), p.departed_by = COALESCE(p.departed_by, ?),
                 p.reported_at = ?
             WHERE w.route_id = ? AND w.seq < ? AND p.completed_at IS NULL AND p.skipped_at IS NULL",
            [$eventTs, $userId, $eventTs, $userId, $eventTs, $userId, $reportedAtTs, $wp['route_id'], $wp['seq']]
        );
        dbExecute(
            "UPDATE mission_route_progress SET departed_at = COALESCE(departed_at, ?), departed_by = COALESCE(departed_by, ?), out_of_sequence = ?, reported_at = ? WHERE waypoint_id = ?",
            [$eventTs, $userId, $outOfSequence, $reportedAtTs, $waypointId]
        );
        logAudit('depart_route_waypoint', 'mission_route_waypoints', $waypointId, null, ['mission_id' => $missionId, 'out_of_sequence' => (bool) $outOfSequence]);
        maybeCompleteRoute((int) $wp['route_id'], $userId);
    } elseif ($action === 'arrive') {
        if (!$wp['arrived_at']) {
            $latRaw = post('lat'); $lngRaw = post('lng'); $accRaw = post('accuracy');
            $lat = ($latRaw !== '' && is_numeric($latRaw)) ? (float) $latRaw : null;
            $lng = ($lngRaw !== '' && is_numeric($lngRaw)) ? (float) $lngRaw : null;
            // Out-of-range GPS (same bounds as ping-location.php) is treated
            // as "no fix", not a reason to fail the arrival itself — the
            // action this endpoint exists for is a real-world event that
            // must still record even if the browser handed back garbage
            // coordinates.
            if ($lat !== null && ($lat < -90 || $lat > 90)) { $lat = null; }
            if ($lng !== null && ($lng < -180 || $lng > 180)) { $lng = null; }
            if ($lat === 0.0 && $lng === 0.0) { $lat = null; $lng = null; }
            $acc = ($accRaw !== '' && is_numeric($accRaw)) ? (float) $accRaw : null;
            $distance = ($lat !== null && $lng !== null) ? (int) round(gpsDistanceMeters((float) $wp['lat'], (float) $wp['lng'], $lat, $lng)) : null;

            // AND arrived_at IS NULL closes a real race: the check above reads
            // a snapshot taken before this UPDATE runs, so two team members
            // tapping "arrive" within the same instant both pass it. Without
            // this guard the later UPDATE silently overwrites the earlier
            // one's GPS/who-arrived data and command staff gets a duplicate
            // arrival notification. rowCount() tells us which request (if
            // either) actually won the race — only that one logs/notifies.
            $rows = dbExecute(
                "UPDATE mission_route_progress
                 SET arrived_at = ?, arrived_by = ?, arrived_lat = ?, arrived_lng = ?, arrived_accuracy_m = ?, arrived_distance_m = ?,
                     departed_at = COALESCE(departed_at, ?), departed_by = COALESCE(departed_by, ?), out_of_sequence = ?, reported_at = ?
                 WHERE waypoint_id = ? AND arrived_at IS NULL",
                [$eventTs, $userId, $lat, $lng, $acc, $distance, $eventTs, $userId, $outOfSequence, $reportedAtTs, $waypointId]
            );

            if ($rows > 0) {
                logAudit('arrive_route_waypoint', 'mission_route_waypoints', $waypointId, null, ['mission_id' => $missionId, 'distance_m' => $distance]);

                $teamRow = $wp['team_id'] ? dbFetchOne("SELECT codename, team_number FROM mission_teams WHERE id = ?", [$wp['team_id']]) : null;
                $teamLbl = $teamRow ? teamLabel($teamRow['codename'], $teamRow['team_number']) : routeMixedTeamLabel((int) $wp['route_id']);
                $label = $wp['label'] !== null && $wp['label'] !== '' ? $wp['label'] : t('route.waypoint_fallback_label', ['seq' => $wp['seq']]);
                notifyRouteCommandStaff(
                    $missionId, $mission['title'], $mission['responsible_user_id'] ? (int) $mission['responsible_user_id'] : null, $userId,
                    'mission_route_arrival', 'route.notify_arrival_title', [],
                    'route.notify_arrival_message', ['team' => $teamLbl, 'label' => $label, 'mission' => $mission['title']]
                );
            }
        }
    } else { // complete
        $note = trim((string) post('note'));
        $note = $note !== '' ? mb_substr($note, 0, 2000) : null;

        // Hard block, not just a UI hint: an admin who ticked "photo
        // required" on this waypoint needs that to actually mean something.
        // Checked here rather than only client-side because the offline
        // queue (war-room.php) replays this exact same action later with no
        // user present to catch a client-side warning — the one place this
        // can actually be enforced is the request that tries to persist
        // completed_at.
        $missingDeliverables = missingRouteDeliverables($wp, $note);
        if (!empty($missingDeliverables)) {
            echo json_encode([
                'ok' => false,
                'error' => t('route.missing_deliverables', ['items' => implode(', ', $missingDeliverables)]),
                'missing_deliverables' => true,
            ]);
            exit;
        }

        // AND completed_at IS NULL — same race as "arrive" above: the
        // "already closed" check higher up reads a snapshot taken before
        // this UPDATE runs, so two near-simultaneous completes (e.g. a live
        // tap racing an offline-queue replay landing at the same moment)
        // could otherwise both pass it and both fire this UPDATE, both log,
        // and both trigger maybeCompleteRoute()'s "route completed"
        // notification. rowCount() tells us whether THIS request actually
        // won.
        $rows = dbExecute(
            "UPDATE mission_route_progress
             SET completed_at = ?, completed_by = ?, note = COALESCE(?, note),
                 arrived_at = COALESCE(arrived_at, ?), arrived_by = COALESCE(arrived_by, ?),
                 departed_at = COALESCE(departed_at, ?), departed_by = COALESCE(departed_by, ?),
                 out_of_sequence = ?, reported_at = ?
             WHERE waypoint_id = ? AND completed_at IS NULL",
            [$eventTs, $userId, $note, $eventTs, $userId, $eventTs, $userId, $outOfSequence, $reportedAtTs, $waypointId]
        );
        if ($rows > 0) {
            logAudit('complete_route_waypoint', 'mission_route_waypoints', $waypointId, null, ['mission_id' => $missionId]);
            maybeCompleteRoute((int) $wp['route_id'], $userId);
        }
    }

    echo json_encode(['ok' => true, 'routes' => loadRoutesForUser($missionId, $userId, $canManageWarRoom)]);
    exit;
}

if ($action === 'skip') {
    if (!$canManageWarRoom) {
        echo json_encode(['ok' => false, 'error' => t('dispatch.no_manage_permission')]);
        exit;
    }
    $waypointId = (int) post('id');
    $wp = loadWaypointForAction($waypointId, $missionId, $userId);
    if (!$wp) {
        echo json_encode(['ok' => false, 'error' => t('common.not_found')]);
        exit;
    }
    if ($wp['route_cancelled_at'] || $wp['route_completed_at']) {
        echo json_encode(['ok' => false, 'error' => t('route.already_closed')]);
        exit;
    }
    if ($wp['completed_at'] || $wp['skipped_at']) {
        echo json_encode(['ok' => false, 'error' => t('route.waypoint_already_closed')]);
        exit;
    }

    $reason = trim((string) post('reason'));
    $reason = $reason !== '' ? mb_substr($reason, 0, 255) : null;
    [$eventTs, $reportedAtTs] = resolveEventTimestamp();

    dbExecute("UPDATE mission_route_progress SET skipped_at = ?, skipped_by = ?, skip_reason = ?, reported_at = ? WHERE waypoint_id = ?", [$eventTs, $userId, $reason, $reportedAtTs, $waypointId]);
    logAudit('skip_route_waypoint', 'mission_route_waypoints', $waypointId, null, ['mission_id' => $missionId, 'reason' => $reason]);

    $label = $wp['label'] !== null && $wp['label'] !== '' ? $wp['label'] : t('route.waypoint_fallback_label', ['seq' => $wp['seq']]);
    notifyRouteTeam($missionId, (int) $wp['route_id'], $userId, 'mission_route_skipped', 'route.notify_skipped_title', [], 'route.notify_skipped_message', ['label' => $label, 'mission' => $mission['title']]);

    maybeCompleteRoute((int) $wp['route_id'], $userId);

    echo json_encode(['ok' => true, 'routes' => loadRoutesForUser($missionId, $userId, $canManageWarRoom)]);
    exit;
}

if ($action === 'edit_waypoint') {
    if (!$canManageWarRoom) {
        echo json_encode(['ok' => false, 'error' => t('dispatch.no_manage_permission')]);
        exit;
    }
    $waypointId = (int) post('id');
    $wp = loadWaypointForAction($waypointId, $missionId, $userId);
    if (!$wp) {
        echo json_encode(['ok' => false, 'error' => t('common.not_found')]);
        exit;
    }
    // Gated on the ROUTE's own state, not this waypoint's — editing the
    // label/instructions/dwell/deliverables of an already-completed or
    // -skipped stop is still useful (record-keeping, fixing a typo after the
    // fact) as long as the route itself is still active; it never touches
    // timestamps or who-did-what, only descriptive metadata.
    if ($wp['route_cancelled_at'] || $wp['route_completed_at']) {
        echo json_encode(['ok' => false, 'error' => t('route.already_closed')]);
        exit;
    }

    $label = trim((string) post('label'));
    $instructions = trim((string) post('instructions'));
    $dwellRaw = post('dwell_minutes');

    dbExecute(
        "UPDATE mission_route_waypoints
         SET label = ?, instructions = ?, dwell_minutes = ?, require_photo = ?, require_video = ?, require_note = ?
         WHERE id = ?",
        [
            $label !== '' ? mb_substr($label, 0, 255) : null,
            $instructions !== '' ? mb_substr($instructions, 0, 2000) : null,
            ($dwellRaw !== '' && $dwellRaw !== null && is_numeric($dwellRaw)) ? max(0, (int) $dwellRaw) : null,
            post('require_photo') === '1' ? 1 : 0,
            post('require_video') === '1' ? 1 : 0,
            post('require_note') === '1' ? 1 : 0,
            $waypointId,
        ]
    );
    logAudit('edit_route_waypoint', 'mission_route_waypoints', $waypointId, null, ['mission_id' => $missionId]);

    echo json_encode(['ok' => true, 'routes' => loadRoutesForUser($missionId, $userId, $canManageWarRoom)]);
    exit;
}

echo json_encode(['ok' => false, 'error' => t('common.unknown_action')]);
