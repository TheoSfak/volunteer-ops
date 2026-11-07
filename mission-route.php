<?php
/**
 * VolunteerOps - Mission Route Order Endpoint ("Εντολή Πορείας")
 * War Room: an ordered, multi-waypoint patrol assigned to one team. Each
 * waypoint carries its own instructions, an advisory dwell time, and which
 * deliverables (photo/video/note) it expects. Rides on top of mission_orders
 * (order_type='route') purely to inherit push/banner/"Ελήφθη"/audit for free
 * — see includes/migrations.php v105 for the full rationale. GET polls for
 * the routes visible to the caller, POST creates/cancels a route or advances
 * a single waypoint (depart/arrive/complete/skip). AJAX only. Since v3.350.0
 * also «Δεν μπορώ» at a point whose photo/video/note cannot be sent (cant)
 * and command's answer to it, «Ξεκλείδωμα» (unlock).
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

// notifyCommandStaffBanner() (formerly notifyRouteCommandStaff()) now lives in
// includes/functions-warroom.php — it gained real callers beyond this page (mission-
// order.php's acknowledge action, for the Route/Task/Message "Ελήφθη" loud
// banner) and needed to be shared and order-type-agnostic, not page-local.

// notifyRouteTeam(), missingRouteDeliverables() and maybeCompleteRoute() moved
// to includes/functions-warroom.php in v3.350.0, beside the route point's
// «Δεν μπορώ» / «Ξεκλείδωμα» that share them (and so the tests can reach them).

// resolveEventTimestamp() — the offline-queue replay clock — now lives in
// includes/functions-warroom.php, since volunteer-status.php (field status / SOS)
// replays through the same queue and needs the identical rules. Behaviour
// here is unchanged.

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

    // The route itself - the checks, the rows, the order behind it, the people told - lives in createMissionRoute()
    // (v3.455.0), so a scenario can send exactly the same one.
    $result = createMissionRoute($mission, (int) $userId, [
        'team_id'             => post('team_id'),
        'ring_index'          => post('ring_index'),
        'title'               => post('title'),
        'waypoints'           => post('waypoints'),
        'add_return_waypoint' => post('add_return_waypoint'),
        'member_ids'          => post('member_ids'),
        'is_closed_loop'      => post('is_closed_loop'),
        'travel_mode'         => post('travel_mode'),
    ]);
    if (!$result['ok']) {
        echo json_encode($result);
        exit;
    }
    echo json_encode(['ok' => true, 'routes' => loadRoutesForUser($missionId, $userId, $canManageWarRoom)]);
    exit;
}

// «Ξανάνοιγμα διαδρομής» (v3.443.0): command takes back the completion of a route that closed too soon or by
// mistake; its last point is open again and the team is told.
if ($action === 'reopen') {
    if (!$canManageWarRoom) {
        echo json_encode(['ok' => false, 'error' => t('dispatch.no_manage_permission')]);
        exit;
    }
    $error = reopenMissionRoute($mission, (int) post('id'), (int) $userId);
    if ($error !== null) {
        echo json_encode(['ok' => false, 'error' => $error]);
        exit;
    }
    echo json_encode(['ok' => true, 'routes' => loadRoutesForUser($missionId, $userId, $canManageWarRoom)]);
    exit;
}

if ($action === 'cancel') {
    if (!$canManageWarRoom) {
        echo json_encode(['ok' => false, 'error' => t('dispatch.no_manage_permission')]);
        exit;
    }
    // The steps of cancelling live in cancelMissionRoute() (v3.456.0), so a scenario can take a phase back
    // through exactly the same ones.
    $error = cancelMissionRoute($mission, (int) $userId, (int) post('id'), (string) post('reason'));
    if ($error !== null) {
        echo json_encode(['ok' => false, 'error' => $error]);
        exit;
    }

    echo json_encode(['ok' => true, 'routes' => loadRoutesForUser($missionId, $userId, $canManageWarRoom)]);
    exit;
}

// Part of the "reset ring assignments" button on the missing-person card
// (war-room.php). Cancels rather than deletes — this file has no delete
// precedent for a route anywhere (only the single-route cancel() above),
// and a hard delete would orphan mission_orders/mission_order_recipients
// rows a route's creation produces; cancelling reaches the same "gone from
// the live map" result loadRoutesForUser()/renderRoutesReference() already
// give a cancelled route, via the exact same per-route steps cancel() above
// takes, just looped. Only ever touches routes with a real ring_index still
// open (not already cancelled/completed), so a hand-drawn or already-closed
// route is never at risk here.
if ($action === 'clear_ring_generated') {
    if (!$canManageWarRoom) {
        echo json_encode(['ok' => false, 'error' => t('dispatch.no_manage_permission')]);
        exit;
    }
    // Optional ring_index narrows the cancel to ONE ring — see the same
    // parameter on mission-sector.php's clear_ring_generated for why.
    $ringIndexRaw = post('ring_index');
    $ringIndex = ($ringIndexRaw !== '' && $ringIndexRaw !== null) ? (int) $ringIndexRaw : null;
    if ($ringIndex !== null && ($ringIndex < 0 || $ringIndex > 3)) {
        echo json_encode(['ok' => false, 'error' => t('common.invalid_request')]);
        exit;
    }
    // Two fixed literals chosen by a validated int, never interpolated input.
    $scope = $ringIndex !== null ? ' = ?' : ' IS NOT NULL';
    $args = $ringIndex !== null ? [$missionId, $ringIndex] : [$missionId];

    $rows = dbFetchAll(
        "SELECT id, order_id FROM mission_routes WHERE mission_id = ? AND ring_index$scope AND cancelled_at IS NULL AND completed_at IS NULL",
        $args
    );
    $reason = t('missing_person.ring_reset_cancel_reason');
    foreach ($rows as $route) {
        dbExecute("UPDATE mission_routes SET cancelled_at = NOW(), cancelled_by = ?, cancel_reason = ? WHERE id = ?", [$userId, $reason, $route['id']]);
        if ($route['order_id']) {
            dbExecute("UPDATE mission_order_recipients SET fulfilled_at = NOW() WHERE order_id = ? AND fulfilled_at IS NULL", [$route['order_id']]);
        }
        notifyRouteTeam($missionId, (int) $route['id'], $userId, 'mission_route_cancelled', 'route.notify_cancelled_title', [], 'route.notify_cancelled_message', ['mission' => $mission['title']]);
    }
    logAudit('clear_ring_generated_mission_routes', 'mission_routes', null, null, ['mission_id' => $missionId, 'ring_index' => $ringIndex, 'count' => count($rows)]);
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

    // Leaving a point behind closes it (see "depart" below), and a point whose
    // photo, video or note is missing is not the team's to close: «Δεν μπορώ»
    // there, then command's «Ξεκλείδωμα» or «Παράλειψη» (v3.350.0). Asked
    // before the out-of-sequence confirmation, so nobody confirms a jump only
    // to be refused it.
    if ($action === 'depart') {
        $blocker = routeJumpBlocker((int) $wp['route_id'], (int) $wp['seq']);
        if ($blocker) {
            echo json_encode([
                'ok' => false,
                'jump_blocked' => true,
                'error' => t('route.jump_blocked', [
                    'point' => routeWaypointRef($blocker['waypoint']),
                    'items' => implode(', ', array_map(fn($key) => t($key), $blocker['missing'])),
                ]),
            ]);
            exit;
        }
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

    // Team label + waypoint label, resolved lazily and at most once per
    // request: all three of depart/arrive/complete now report to command
    // staff, but each only on the branch that actually won its race, so
    // resolving eagerly would run two extra queries on every no-op retry.
    $resolvedNames = null;
    $waypointNames = function () use (&$resolvedNames, $wp) {
        if ($resolvedNames === null) {
            $teamRow = $wp['team_id'] ? dbFetchOne("SELECT codename, team_number FROM mission_teams WHERE id = ?", [$wp['team_id']]) : null;
            $resolvedNames = [
                $teamRow ? teamLabel($teamRow['codename'], $teamRow['team_number']) : routeMixedTeamLabel((int) $wp['route_id']),
                ($wp['label'] !== null && $wp['label'] !== '') ? $wp['label'] : t('route.waypoint_fallback_label', ['seq' => $wp['seq']]),
            ];
        }
        return $resolvedNames;
    };

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

        // "Ξεκίνησε" is the moment a team commits to moving toward a point —
        // operationally the most decision-relevant event on a route, and the
        // one command staff was never told about: only "arrive" below fired
        // a banner, so the whole travel window was invisible from the map
        // side. Guarded on $wp['departed_at'] being empty rather than on a
        // rowCount: the UPDATE above uses COALESCE(departed_at, ...), so it
        // reports 0 changed rows on a genuine first depart whose other
        // columns happen to already match, and would swallow the alert.
        if (!$wp['departed_at']) {
            [$teamLbl, $label] = $waypointNames();
            notifyCommandStaffBanner(
                $missionId, $mission['title'], $mission['responsible_user_id'] ? (int) $mission['responsible_user_id'] : null, $userId,
                'mission_route_departure', 'route.notify_departure_title', [],
                'route.notify_departure_message', ['team' => $teamLbl, 'label' => $label, 'mission' => $mission['title']]
            );
        }
        maybeCompleteRoute((int) $wp['route_id'], $userId);
    } elseif ($action === 'arrive') {
        if (!$wp['arrived_at']) {
            $latRaw = post('lat'); $lngRaw = post('lng'); $accRaw = post('accuracy');
            $lat = ($latRaw !== '' && is_numeric($latRaw)) ? (float) $latRaw : null;
            $lng = ($lngRaw !== '' && is_numeric($lngRaw)) ? (float) $lngRaw : null;
            $acc = ($accRaw !== '' && is_numeric($accRaw)) ? (float) $accRaw : null;
            // Shared with mobile-order-ack.php: «Έφτασα» pressed on the
            // phone's «Έφτασες;» notification.
            recordRouteWaypointArrival($mission, $wp, (int) $userId, $lat, $lng, $acc, $eventTs, $reportedAtTs, $outOfSequence);
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

            // Closing out a single point, as distinct from maybeCompleteRoute()
            // below announcing the whole route finished — a 12-stop patrol
            // used to be completely silent to command staff for its first 11
            // stops. Inside the $rows > 0 guard, so an offline-queue replay
            // landing on an already-completed waypoint stays silent.
            [$teamLbl, $label] = $waypointNames();
            notifyCommandStaffBanner(
                $missionId, $mission['title'], $mission['responsible_user_id'] ? (int) $mission['responsible_user_id'] : null, $userId,
                'mission_route_waypoint_completed', 'route.notify_waypoint_completed_title', [],
                'route.notify_waypoint_completed_message', ['team' => $teamLbl, 'label' => $label, 'mission' => $mission['title']]
            );
            maybeCompleteRoute((int) $wp['route_id'], $userId);
        }
    }

    // A group that said «Δεν μπορώ» and is now walking the route after all.
    resolveOrderDeclineOnProgress('route', (int) $wp['route_id'], 'all', (int) $userId);

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

// «Δεν μπορώ» at a route point (v3.350.0): the team cannot send the photo,
// video or note the point asks for, and asks command to let them go on. Any
// route member, like depart/arrive/complete — admin rights do not stand in.
if ($action === 'cant') {
    if (!$isApprovedParticipant) {
        echo json_encode(['ok' => false, 'error' => t('route.only_approved_can_report')]);
        exit;
    }
    $wp = loadWaypointForAction((int) post('id'), $missionId, $userId);
    if (!$wp) {
        echo json_encode(['ok' => false, 'error' => t('common.not_found')]);
        exit;
    }
    $error = reportRouteWaypointCant($mission, $wp, (int) $userId, $user['name'] ?? '', (string) post('reason'), (string) post('note'));
    if ($error !== null) {
        echo json_encode(['ok' => false, 'error' => $error]);
        exit;
    }
    echo json_encode(['ok' => true, 'routes' => loadRoutesForUser($missionId, $userId, $canManageWarRoom)]);
    exit;
}

// «Ξεκλείδωμα» (v3.350.0): command lets the team on past the point. It closes
// as completed without what was missing, marked unlocked, and the team is
// told to go on to the next point.
if ($action === 'unlock') {
    if (!$canManageWarRoom) {
        echo json_encode(['ok' => false, 'error' => t('dispatch.no_manage_permission')]);
        exit;
    }
    $wp = loadWaypointForAction((int) post('id'), $missionId, $userId);
    if (!$wp) {
        echo json_encode(['ok' => false, 'error' => t('common.not_found')]);
        exit;
    }
    $error = unlockRouteWaypoint($mission, $wp, (int) $userId);
    if ($error !== null) {
        echo json_encode(['ok' => false, 'error' => $error]);
        exit;
    }
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
