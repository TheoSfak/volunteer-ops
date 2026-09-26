<?php
/**
 * VolunteerOps - «Ελήφθη» from the Android app's notification (bearer-token auth)
 *
 * The order notification the app raises with the screen off (AlertPoller, fed
 * by mobile-alerts.php) carries an «Ελήφθη» button, so a volunteer can confirm
 * an order without unlocking the phone and opening the Action Room. The button
 * posts here. Bearer-token authed exactly like mobile-ping-location.php — a
 * notification button has no session and no CSRF token.
 *
 * Nothing is recorded here directly: receiveMissionOrder(),
 * receiveMissionDispatch() and receiveMissionSector() are the functions the
 * page's own «Ελήφθη» calls, so command sees the same tick on the
 * acknowledgement panel, and gets the same notification, whichever way it was
 * pressed. What this file adds are the checks the page endpoints make before
 * calling them — an open mission, an approved participant — since the phone
 * does not come through those endpoints.
 *
 * AJAX POST only: kind (order|dispatch|sector — «Ελήφθη»; dispatch_arrive|
 * waypoint_arrive — «Έφτασα» on an arrival question, v3.333.0), id.
 */

require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json');

if (!isPost()) {
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if (!preg_match('/^Bearer\s+([A-Za-z0-9]+)$/', trim($authHeader), $matches)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Missing or malformed bearer token']);
    exit;
}
$tokenHash = hash('sha256', $matches[1]);

$tokenRow = dbFetchOne(
    "SELECT user_id FROM mobile_api_tokens WHERE token_hash = ? AND revoked_at IS NULL",
    [$tokenHash]
);
if (!$tokenRow) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Invalid or revoked token']);
    exit;
}

$user = dbFetchOne("SELECT id, name FROM users WHERE id = ? AND deleted_at IS NULL AND is_active = 1", [(int) $tokenRow['user_id']]);
if (!$user) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Account not found or inactive']);
    exit;
}
$userId = (int) $user['id'];

// Named in the audit row, which cannot take the actor from a session here.
const MOBILE_ACK_VIA = 'app_notification';

/**
 * «Έφτασα» at a route point, pressed on the «Έφτασες;» notification. The same
 * gates as mission-route.php's arrive (a member of this route, the route still
 * running, the point not closed). The position reported is the fix that asked
 * the question, kept in mission_arrival_prompts — the phone sends none.
 * Answering a question about a point is its own confirmation, so an
 * out-of-order point is recorded as such rather than refused.
 */
function arriveFromPrompt(array $mission, int $waypointId, int $userId): ?string {
    $wp = loadWaypointForAction($waypointId, (int) $mission['id'], $userId);
    if (!$wp) {
        return t('common.not_found');
    }
    if ($wp['route_cancelled_at'] || $wp['route_completed_at']) {
        return t('route.already_closed');
    }
    if (!$wp['is_route_member']) {
        return t('dispatch.not_your_team');
    }
    if ($wp['completed_at'] || $wp['skipped_at']) {
        return t('route.waypoint_already_closed');
    }
    if ($wp['arrived_at']) {
        return null;
    }
    $asked = dbFetchOne(
        "SELECT lat, lng, accuracy_m FROM mission_arrival_prompts WHERE target_kind = 'waypoint' AND target_id = ? AND user_id = ?",
        [$waypointId, $userId]
    );
    $currentSeq = currentWaypointSeq((int) $wp['route_id']);
    $outOfSequence = (($currentSeq !== null && (int) $wp['seq'] > $currentSeq) || $wp['out_of_sequence']) ? 1 : 0;
    $now = date('Y-m-d H:i:s');
    recordRouteWaypointArrival(
        $mission, $wp, $userId,
        $asked ? (float) $asked['lat'] : null, $asked ? (float) $asked['lng'] : null,
        ($asked && $asked['accuracy_m'] !== null) ? (float) $asked['accuracy_m'] : null,
        $now, $now, $outOfSequence, MOBILE_ACK_VIA
    );
    return null;
}

$kind = (string) post('kind');
$id = (int) post('id');

if ($kind === 'order') {
    // mission-order.php checks nothing beyond "is this order addressed to
    // you", and neither does this.
    $error = receiveMissionOrder($id, $userId, (string) $user['name'], MOBILE_ACK_VIA);
} elseif (in_array($kind, ['dispatch', 'sector', 'dispatch_arrive', 'waypoint_arrive'], true)) {
    $missionSql = [
        'dispatch' => "SELECT mission_id FROM mission_dispatch_points WHERE id = ?",
        'dispatch_arrive' => "SELECT mission_id FROM mission_dispatch_points WHERE id = ?",
        'sector' => "SELECT mission_id FROM mission_search_sectors WHERE id = ?",
        'waypoint_arrive' => "SELECT r.mission_id FROM mission_route_waypoints w JOIN mission_routes r ON r.id = w.route_id WHERE w.id = ?",
    ][$kind];
    $missionId = (int) dbFetchValue($missionSql, [$id]);
    $mission = $missionId ? dbFetchOne(
        "SELECT id, title, status, show_in_ops, responsible_user_id FROM missions WHERE id = ? AND deleted_at IS NULL",
        [$missionId]
    ) : null;
    $isApprovedParticipant = $mission && (bool) dbFetchValue(
        "SELECT COUNT(*) FROM participation_requests pr
         JOIN shifts s ON s.id = pr.shift_id
         WHERE s.mission_id = ? AND pr.volunteer_id = ? AND pr.status = ?",
        [$missionId, $userId, PARTICIPATION_APPROVED]
    );
    if (!$mission || $mission['status'] !== STATUS_OPEN || empty($mission['show_in_ops'])) {
        $error = t('common.mission_not_found_or_inactive');
    } elseif (!$isApprovedParticipant) {
        $error = t('common.no_access_action_room');
    } elseif ($kind === 'dispatch') {
        $error = receiveMissionDispatch($mission, $id, $userId, (string) $user['name'], MOBILE_ACK_VIA);
    } elseif ($kind === 'dispatch_arrive') {
        // «Έφτασα» on the «Έφτασες;» notification (checkArrivalPrompts()).
        $error = advanceMissionDispatch($mission, $id, $userId, (string) $user['name'], 'arrive', MOBILE_ACK_VIA);
    } elseif ($kind === 'waypoint_arrive') {
        $error = arriveFromPrompt($mission, $id, $userId);
    } else {
        // Never as command staff standing in for a team: whether this person
        // may do that is a session permission (canManageActionRoom()), and a
        // notification button is for the order that was sent to them.
        $error = receiveMissionSector($mission, $id, $userId, (string) $user['name'], false, true, MOBILE_ACK_VIA);
    }
} else {
    $error = t('common.unknown_action');
}

echo json_encode($error === null ? ['ok' => true] : ['ok' => false, 'error' => $error], JSON_UNESCAPED_UNICODE);
