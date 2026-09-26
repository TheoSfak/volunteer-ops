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
    $error = advanceMissionDispatch($mission, (int) post('id'), (int) $userId, $user['name'], $step);
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
    $teamIdRaw = post('team_id');
    $teamId = ($teamIdRaw !== '' && $teamIdRaw !== null) ? (int) $teamIdRaw : null;
    $team = null;
    if ($teamId) {
        $team = dbFetchOne("SELECT id, codename, team_number FROM mission_teams WHERE id = ? AND mission_id = ?", [$teamId, $missionId]);
        if (!$team) {
            echo json_encode(['ok' => false, 'error' => t('common.team_not_found')]);
            exit;
        }
    }
    $teamLabel = $team ? teamLabel($team['codename'], $team['team_number']) : null;

    // 0-3, matching LPB_RING_TABLE's own [25,50,75,95] index — set only by
    // the war-room.php ring shortcuts, null for a hand-drawn dispatch. Backs
    // the "reset ring assignments" bulk-clear (see the clear_ring_generated
    // action further down).
    $ringIndexRaw = post('ring_index');
    $ringIndex = ($ringIndexRaw !== '' && $ringIndexRaw !== null) ? (int) $ringIndexRaw : null;
    if ($ringIndex !== null && ($ringIndex < 0 || $ringIndex > 3)) {
        echo json_encode(['ok' => false, 'error' => t('common.invalid_request')]);
        exit;
    }

    $type = post('type');
    $label = trim((string) post('label'));
    $label = $label !== '' ? mb_substr($label, 0, 255) : null;
    $rawGeo = json_decode((string) post('geo'), true);

    // Same lat/lng range ping-location.php already enforces for a live GPS
    // fix — this is admin-drawn, not device-reported, but a malformed value
    // would still put a marker/zone somewhere nonsensical on the map and
    // corrupt any distance/ETA calculation against it.
    $isValidLatLng = fn($lat, $lng) => is_numeric($lat) && is_numeric($lng)
        && (float) $lat >= -90 && (float) $lat <= 90 && (float) $lng >= -180 && (float) $lng <= 180
        && !((float) $lat === 0.0 && (float) $lng === 0.0);

    if ($type === 'point') {
        if (!is_array($rawGeo) || !isset($rawGeo['lat'], $rawGeo['lng']) || !$isValidLatLng($rawGeo['lat'], $rawGeo['lng'])) {
            echo json_encode(['ok' => false, 'error' => t('dispatch.invalid_point')]);
            exit;
        }
        $geo = ['lat' => (float) $rawGeo['lat'], 'lng' => (float) $rawGeo['lng']];
    } elseif ($type === 'polygon') {
        if (!is_array($rawGeo) || count($rawGeo) < 3) {
            echo json_encode(['ok' => false, 'error' => t('dispatch.polygon_needs_3_points')]);
            exit;
        }
        foreach ($rawGeo as $pt) {
            if (!is_array($pt) || !isset($pt[0], $pt[1]) || !$isValidLatLng($pt[0], $pt[1])) {
                echo json_encode(['ok' => false, 'error' => t('dispatch.invalid_point')]);
                exit;
            }
        }
        $geo = array_map(fn($pt) => [(float) $pt[0], (float) $pt[1]], $rawGeo);
    } else {
        echo json_encode(['ok' => false, 'error' => t('dispatch.unknown_point_type')]);
        exit;
    }

    $dispatchId = dbInsert(
        "INSERT INTO mission_dispatch_points (mission_id, team_id, type, geo, label, ring_index, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())",
        [$missionId, $teamId, $type, json_encode($geo), $label, $ringIndex, $userId]
    );
    logAudit('create_mission_dispatch', 'mission_dispatch_points', $dispatchId, null, ['mission_id' => $missionId, 'team_id' => $teamId, 'type' => $type, 'ring_index' => $ringIndex]);

    // Recipients: a team-targeted dispatch only alerts (banner + sound) that
    // team — matching who can actually see the pin/area on their map
    // (loadMissionDispatchesForUser). Previously every approved participant
    // got the alert regardless of target, on the theory that "everyone
    // should know an order went out" even without seeing where — reversed
    // per explicit request: a team getting dispatched shouldn't sound an
    // alarm for every other team on the mission. A dispatch with no team
    // (sent to "all teams") still notifies everyone, since that's genuinely
    // for the whole mission.
    $recipientIds = actionRoomNotifyRecipientIds($missionId, $teamId ?: null, (int) $userId);

    $warRoomUrl = rtrim(BASE_URL, '/') . '/war-room.php?id=' . $missionId;
    $titleKey = $type === 'point' ? 'dispatch.create_notify_title_point' : 'dispatch.create_notify_title_area';
    $kindKey = $type === 'point' ? 'dispatch.a_point' : 'dispatch.an_area';
    $labelSuffix = $label ? ' (' . $label . ')' : '';

    $langByUserId = getUserLanguages($recipientIds);
    foreach ($recipientIds as $recipientId) {
        $lang = $langByUserId[$recipientId] ?? DEFAULT_LANGUAGE;
        $message = t('dispatch.create_notify_message', ['mission' => $mission['title'], 'kind' => t($kindKey, [], $lang), 'label_suffix' => $labelSuffix], $lang);
        sendNotification($recipientId, t($titleKey, [], $lang), $message, 'info', 'mission_dispatch_point', [
            'url' => $warRoomUrl,
            'tag' => 'dispatch-point-mission-' . $missionId,
            'bannerMission' => $missionId,
            // Lets the Action Room open this as the order popup and jump
            // straight to the point on the map (see war-room.php's banners).
            'dispatchId' => (int) $dispatchId,
        ]);
    }

    // Every System Administrator not already a real recipient above (not on
    // the targeted team, or not even an approved participant of this mission
    // at all) still gets the identical banner + sound alert, worded as a
    // third-person FYI naming who the real target was — admins watching any
    // Action Room see/hear everything in it, same rule now applied to
    // createMissionOrderAndNotify() for the other admin-to-user order types.
    $adminBystanderIds = array_values(array_diff(getSystemAdminIds((int) $userId), $recipientIds));
    if ($adminBystanderIds) {
        $fyiLangs = getUserLanguages($adminBystanderIds);
        foreach ($adminBystanderIds as $adminId) {
            $lang = $fyiLangs[$adminId] ?? DEFAULT_LANGUAGE;
            $fyiMessage = t('dispatch.create_admin_fyi', [
                'actor' => $user['name'],
                'kind' => t($kindKey, [], $lang),
                'mission' => $mission['title'],
                'label_suffix' => $labelSuffix,
                'target' => $teamLabel ?: t('common.all_teams', [], $lang),
            ], $lang);
            // No 'bannerMission': same rule as createMissionOrderAndNotify()'s
            // own admin FYI. This dispatch already produces an acknowledgement
            // card on every command-staff screen, listing the team it went to
            // with a box per person; a marquee saying the same thing less well
            // is the second announcement of one event. The targeted team's own
            // notification, just above, keeps its banner.
            sendNotification($adminId, t($titleKey, [], $lang), $fyiMessage, 'info', '', [
                'url' => $warRoomUrl,
                'tag' => 'dispatch-point-mission-' . $missionId,
            ]);
        }
    }

    echo json_encode(['ok' => true, 'id' => (int) $dispatchId]);
    exit;
}

if ($action === 'delete') {
    $dispatchId = (int) post('id');
    $row = dbFetchOne("SELECT id FROM mission_dispatch_points WHERE id = ? AND mission_id = ?", [$dispatchId, $missionId]);
    if (!$row) {
        echo json_encode(['ok' => false, 'error' => t('common.not_found')]);
        exit;
    }
    dbExecute("DELETE FROM mission_dispatch_points WHERE id = ?", [$dispatchId]);
    logAudit('delete_mission_dispatch', 'mission_dispatch_points', $dispatchId, null, ['mission_id' => $missionId]);
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

    $count = (int) dbFetchValue("SELECT COUNT(*) FROM mission_dispatch_points WHERE mission_id = ? AND ring_index$scope", $args);
    dbExecute("DELETE FROM mission_dispatch_points WHERE mission_id = ? AND ring_index$scope", $args);
    logAudit('clear_ring_generated_mission_dispatch', 'mission_dispatch_points', null, null, ['mission_id' => $missionId, 'ring_index' => $ringIndex, 'count' => $count]);
    echo json_encode(['ok' => true, 'dispatches' => loadMissionDispatchesForUser($missionId, $userId, $canManageWarRoom, $isApprovedParticipant)]);
    exit;
}

echo json_encode(['ok' => false, 'error' => t('common.unknown_action')]);
