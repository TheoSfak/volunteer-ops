<?php
/**
 * VolunteerOps - Mission Dispatch Points Endpoint
 * War Room: admin sends a point or an area (polygon) to all teams or one team.
 * GET polls for active dispatches, POST creates or deletes one. AJAX only.
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();

header('Content-Type: application/json');

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

// ── GET: poll for active dispatch points visible to me ─────────────────────
if (!isPost()) {
    $dispatches = loadMissionDispatchesForUser($missionId, $userId, $canManageWarRoom, $isApprovedParticipant);
    echo json_encode(['ok' => true, 'dispatches' => $dispatches]);
    exit;
}

// ── POST: create, delete, or ack ────────────────────────────────────────────
if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string) $_POST['csrf_token'])) {
    echo json_encode(['ok' => false, 'error' => t('common.invalid_request')]);
    exit;
}

$action = post('action');

if ($action === 'receive') {
    if (!$isApprovedParticipant) {
        echo json_encode(['ok' => false, 'error' => t('dispatch.only_approved_can_receive')]);
        exit;
    }

    // Shared with mobile-order-ack.php, the button on the app's notification.
    $error = receiveMissionDispatch($mission, (int) post('id'), (int) $userId, $user['name']);
    if ($error !== null) {
        echo json_encode(['ok' => false, 'error' => $error]);
        exit;
    }

    $dispatches = loadMissionDispatchesForUser($missionId, $userId, $canManageWarRoom, $isApprovedParticipant);
    echo json_encode(['ok' => true, 'dispatches' => $dispatches]);
    exit;
}

// «Ξεκινάω» (depart), «Έφτασα» (ack — the action's historical name) and
// «Ολοκληρώθηκε» (complete). All three are TEAM steps: the first member to
// press moves the whole team on, and only that first press notifies anyone —
// before v3.325.0 arrival was per person, and every member of a four-person
// team pressing it meant four arrival alarms at the command post.
if (in_array($action, ['depart', 'ack', 'complete'], true)) {
    if (!$isApprovedParticipant) {
        echo json_encode(['ok' => false, 'error' => t('dispatch.only_approved_can_ack')]);
        exit;
    }

    // Shared with mobile-order-ack.php: «Έφτασα» pressed on the phone's
    // «Έφτασες;» notification.
    $step = ['depart' => 'depart', 'ack' => 'arrive', 'complete' => 'complete'][$action];
    // A note on completing (v3.364.0): for a point sent from the map's imported
    // points, but accepted for any dispatch.
    $note = $step === 'complete' ? mb_substr(trim((string) post('note')), 0, 500) : null;
    $error = advanceMissionDispatch($mission, (int) post('id'), (int) $userId, $user['name'], $step, null, $note);
    if ($error !== null) {
        echo json_encode(['ok' => false, 'error' => $error]);
        exit;
    }

    $dispatches = loadMissionDispatchesForUser($missionId, $userId, $canManageWarRoom, $isApprovedParticipant);
    echo json_encode(['ok' => true, 'dispatches' => $dispatches]);
    exit;
}

if (!$canManageWarRoom) {
    echo json_encode(['ok' => false, 'error' => t('dispatch.no_manage_permission')]);
    exit;
}

if ($action === 'create') {
    // The order itself - the checks, the row, the audit entry, the notifications - lives in
    // createMissionDispatch() (v3.451.0), so a scenario can send exactly the same one.
    $result = createMissionDispatch($mission, (int) $userId, (string) $user['name'], [
        'team_id'     => post('team_id'),
        'ring_index'  => post('ring_index'),
        'incident_id' => post('incident_id'),
        'type'        => post('type'),
        'label'       => post('label'),
        'geo'         => post('geo'),
        'travel_mode' => post('travel_mode'),
    ]);
    echo json_encode($result);
    exit;
}

// «Ξανάνοιγμα» (v3.443.0): command takes back an «Ολοκληρώθηκε» pressed by mistake or too soon. Past the
// $canManageWarRoom gate above, so command only.
if ($action === 'reopen') {
    $error = reopenMissionDispatch($mission, (int) post('id'), (string) post('scope'), (int) $userId);
    if ($error !== null) {
        echo json_encode(['ok' => false, 'error' => $error]);
        exit;
    }
    $dispatches = loadMissionDispatchesForUser($missionId, $userId, $canManageWarRoom, $isApprovedParticipant);
    echo json_encode(['ok' => true, 'dispatches' => $dispatches]);
    exit;
}

if ($action === 'delete') {
    $dispatchId = (int) post('id');
    $row = dbFetchOne("SELECT id FROM mission_dispatch_points WHERE id = ? AND mission_id = ?", [$dispatchId, $missionId]);
    if (!$row) {
        echo json_encode(['ok' => false, 'error' => t('common.not_found')]);
        exit;
    }
    // Before the DELETE: afterwards nothing says who it went to.
    $withdrawn = withdrawnDispatchLines($missionId, [$dispatchId], (int) $userId);
    // And so the activity timeline still says it was sent, and that it was taken back.
    logDispatchWithdrawal($dispatchId, $missionId, (int) $userId);
    dbExecute("DELETE FROM mission_dispatch_points WHERE id = ?", [$dispatchId]);
    logAudit('delete_mission_dispatch', 'mission_dispatch_points', $dispatchId, null, ['mission_id' => $missionId]);
    // Deleting it is command's answer to a «Δεν μπορώ» on it; the rows stay
    // for the record, closed.
    resolveOrderDeclinesReassigned('dispatch', $dispatchId, (int) $userId);
    // Whoever was still on their way is told it no longer stands.
    notifyOrdersWithdrawn($missionId, $mission['title'], $withdrawn, 'mission_dispatch_point');
    echo json_encode(['ok' => true]);
    exit;
}

// Part of the "reset ring assignments" button on the missing-person card
// (war-room.php) — bulk wipe, same null-record_id audit shape as
// mission-sector.php's own clear_all_areas/clear_ring_generated. Only ever
// touches dispatches with a real ring_index, so a hand-drawn dispatch is
// never at risk here regardless of how it was labeled.
if ($action === 'clear_ring_generated') {
    // Optional ring_index narrows the clear to ONE ring — see the same
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

    $ids = array_map('intval', array_column(dbFetchAll("SELECT id FROM mission_dispatch_points WHERE mission_id = ? AND ring_index$scope", $args), 'id'));
    $count = count($ids);
    $withdrawn = withdrawnDispatchLines($missionId, $ids, (int) $userId);
    dbExecute("DELETE FROM mission_dispatch_points WHERE mission_id = ? AND ring_index$scope", $args);
    logAudit('clear_ring_generated_mission_dispatch', 'mission_dispatch_points', null, null, ['mission_id' => $missionId, 'ring_index' => $ringIndex, 'count' => $count]);
    notifyOrdersWithdrawn($missionId, $mission['title'], $withdrawn, 'mission_dispatch_point');
    echo json_encode(['ok' => true, 'dispatches' => loadMissionDispatchesForUser($missionId, $userId, $canManageWarRoom, $isApprovedParticipant)]);
    exit;
}

echo json_encode(['ok' => false, 'error' => t('common.unknown_action')]);
