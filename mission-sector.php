<?php
/**
 * VolunteerOps - Search-Area Coverage Tracking Endpoint
 * War Room: admin draws polygon "sectors" on the shared map and assigns them
 * to a team; the team first acknowledges the assignment (`acknowledge`
 * action, separate from status — mirrors mission_route_progress's own split
 * of departed_at/arrived_at), then self-reports status through a
 * not_started/assigned/en_route/in_progress/completed/needs_recheck
 * lifecycle. Urban sectors can also carry a per-building/per-floor
 * checklist. GET polls for sectors visible to the caller, POST mutates.
 * AJAX only.
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();

header('Content-Type: application/json');

/**
 * Notify a team's approved participants that a sector was (re)assigned to
 * them. Team-scoped only, not mission-wide — mirrors mission-dispatch.php's
 * own create-notification fix (a team-targeted alert must not fire for every
 * other team on the mission).
 */
function notifySectorAssigned(int $missionId, string $missionTitle, int $teamId, string $label, int $excludeUserId): void {
    $recipients = dbFetchAll(
        "SELECT DISTINCT pr.volunteer_id AS user_id FROM participation_requests pr
         JOIN shifts s ON s.id = pr.shift_id
         WHERE s.mission_id = ? AND pr.status = ?
           AND pr.volunteer_id IN (SELECT user_id FROM mission_team_members WHERE team_id = ?)",
        [$missionId, PARTICIPATION_APPROVED, $teamId]
    );
    $recipientIds = array_values(array_diff(
        array_map(fn($r) => (int) $r['user_id'], $recipients),
        [$excludeUserId]
    ));
    if (empty($recipientIds)) {
        return;
    }

    $warRoomUrl = rtrim(BASE_URL, '/') . '/war-room.php?id=' . $missionId;
    $langByUserId = getUserLanguages($recipientIds);
    foreach ($recipientIds as $recipientId) {
        $lang = $langByUserId[$recipientId] ?? DEFAULT_LANGUAGE;
        $message = t('sector.assigned_notify_message', ['label' => $label], $lang);
        sendNotification($recipientId, t('sector.assigned_notify_title', ['mission' => $missionTitle], $lang), $message, 'info', 'mission_sector_assigned', [
            'url' => $warRoomUrl,
            'tag' => 'sector-assigned-mission-' . $missionId,
            'bannerMission' => $missionId,
        ]);
    }
}

/**
 * Notify "the other side" of a sector status change — command staff when a
 * field team self-reported, or the assigned team when command staff made
 * the change — so whoever DIDN'T cause it finds out. $newStatus is passed
 * explicitly rather than read off a $sector row that may already be stale
 * by the time this runs.
 */
function notifySectorStatusChanged(int $missionId, string $missionTitle, ?int $responsibleUserId, array $sector, string $newStatus, int $actorId, bool $actorIsAdmin): void {
    if ($actorIsAdmin) {
        if (!$sector['team_id']) {
            return;
        }
        $recipients = dbFetchAll(
            "SELECT DISTINCT pr.volunteer_id AS user_id FROM participation_requests pr
             JOIN shifts s ON s.id = pr.shift_id
             WHERE s.mission_id = ? AND pr.status = ?
               AND pr.volunteer_id IN (SELECT user_id FROM mission_team_members WHERE team_id = ?)",
            [$missionId, PARTICIPATION_APPROVED, $sector['team_id']]
        );
        $recipientIds = array_values(array_diff(array_map(fn($r) => (int) $r['user_id'], $recipients), [$actorId]));
    } else {
        $recipientIds = getMissionCommandStaffIds($missionId, $responsibleUserId, $actorId);
    }
    if (empty($recipientIds)) {
        return;
    }

    $warRoomUrl = rtrim(BASE_URL, '/') . '/war-room.php?id=' . $missionId;
    $langByUserId = getUserLanguages($recipientIds);
    foreach ($recipientIds as $recipientId) {
        $lang = $langByUserId[$recipientId] ?? DEFAULT_LANGUAGE;
        $message = t('sector.status_changed_notify_message', ['label' => $sector['label'], 'status' => sectorStatusLabel($newStatus, $lang)], $lang);
        sendNotification($recipientId, t('sector.status_changed_notify_title', ['mission' => $missionTitle], $lang), $message, 'info', 'mission_sector_status', [
            'url' => $warRoomUrl,
            'tag' => 'sector-status-mission-' . $missionId,
            'bannerMission' => $missionId,
        ]);
    }
}

/**
 * Every mutating action below ends by reloading and returning the current
 * state. Areas and sectors are always paired through this one function so
 * they can never drift apart at any individual call site — a lesson already
 * learned twice elsewhere in this codebase about independently-maintained
 * things going out of sync.
 */
function loadSectorPollPayload(int $missionId, int $userId, bool $canManageWarRoom, bool $isApprovedParticipant): array {
    return [
        'areas'   => loadMissionSearchAreasForUser($missionId, $canManageWarRoom),
        'sectors' => loadMissionSectorsForUser($missionId, $userId, $canManageWarRoom, $isApprovedParticipant),
    ];
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

$canManageWarRoom = canManageActionRoom($mission['responsible_user_id'] ? (int) $mission['responsible_user_id'] : null, (int) $userId);
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

// ── GET: poll for areas+sectors visible to me (universal — see loadMissionSearchAreasForUser/loadMissionSectorsForUser) ─
if (!isPost()) {
    echo json_encode(['ok' => true] + loadSectorPollPayload($missionId, $userId, $canManageWarRoom, $isApprovedParticipant));
    exit;
}

// ── POST: everything below is a mutation ────────────────────────────────────
if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string) $_POST['csrf_token'])) {
    echo json_encode(['ok' => false, 'error' => t('common.invalid_request')]);
    exit;
}

$action = post('action');
$isValidLatLng = fn($lat, $lng) => is_numeric($lat) && is_numeric($lng)
    && (float) $lat >= -90 && (float) $lat <= 90 && (float) $lng >= -180 && (float) $lng <= 180
    && !((float) $lat === 0.0 && (float) $lng === 0.0);

// ── Self-report actions: assigned-team members OR admin ─────────────────────

if ($action === 'status') {
    $sectorId = (int) post('id');
    $targetStatus = (string) post('status');
    $note = trim((string) post('note'));
    $note = $note !== '' ? mb_substr($note, 0, 500) : null;

    $sector = dbFetchOne("SELECT id, team_id, status, acknowledged_at, label FROM mission_search_sectors WHERE id = ? AND mission_id = ?", [$sectorId, $missionId]);
    if (!$sector) {
        echo json_encode(['ok' => false, 'error' => t('common.not_found')]);
        exit;
    }

    if (!$canManageWarRoom) {
        if (!$isApprovedParticipant) {
            echo json_encode(['ok' => false, 'error' => t('sector.no_manage_permission')]);
            exit;
        }
        // Opposite polarity from mission-dispatch.php's self-report guard —
        // there, team_id NULL means "everyone eligible" (all-teams
        // broadcast); here NULL means "nobody's assigned yet," so it must
        // be REJECTED for self-report, not let through.
        $myTeamId = getUserTeamIdForMission($missionId, $userId);
        if (!$sector['team_id'] || (int) $sector['team_id'] !== $myTeamId) {
            echo json_encode(['ok' => false, 'error' => t('sector.not_your_team')]);
            exit;
        }
        // Must explicitly acknowledge a fresh assignment (the `acknowledge`
        // action below) before advancing it any further — mirrors
        // can_acknowledge/can_self_report's own gating in
        // loadMissionSectorsForUser(); enforced here too since the UI
        // hiding the advance button is not itself a real guarantee.
        if ($sector['status'] === 'assigned' && !$sector['acknowledged_at']) {
            echo json_encode(['ok' => false, 'error' => t('sector.must_acknowledge_first')]);
            exit;
        }
        if ($targetStatus !== sectorSelfReportNextStatus($sector['status'])) {
            echo json_encode(['ok' => false, 'error' => t('sector.invalid_transition')]);
            exit;
        }
    } else {
        // Admin: any status except 'assigned' (that value only ever results
        // from the `assign` action attaching a team to a not_started
        // sector — a direct admin pick of 'assigned' with no team_id would
        // create an inconsistent "assigned but nobody's assigned" state).
        // No adjacency restriction otherwise — full override, including
        // backward moves.
        if (!in_array($targetStatus, ['not_started', 'en_route', 'in_progress', 'completed', 'needs_recheck'], true)) {
            echo json_encode(['ok' => false, 'error' => t('sector.invalid_transition')]);
            exit;
        }
    }

    // Optimistic concurrency: the WHERE also pins the status the client
    // believes it's changing FROM, so two people advancing the same sector
    // at once can't both succeed — the loser gets a graceful "already
    // changed" instead of a duplicate/inconsistent log row.
    $updated = dbExecute(
        "UPDATE mission_search_sectors SET status = ?, status_updated_at = NOW(), status_updated_by = ? WHERE id = ? AND status = ?",
        [$targetStatus, $userId, $sectorId, $sector['status']]
    );
    if ($updated === 0) {
        echo json_encode(['ok' => false, 'error' => t('sector.already_changed')]);
        exit;
    }

    dbInsert(
        "INSERT INTO mission_sector_status_log (sector_id, from_status, to_status, team_id, user_id, note, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())",
        [$sectorId, $sector['status'], $targetStatus, $sector['team_id'], $userId, $note]
    );
    logAudit('update_mission_sector_status', 'mission_search_sectors', $sectorId, null, [
        'mission_id' => $missionId, 'from' => $sector['status'], 'to' => $targetStatus,
    ]);

    notifySectorStatusChanged(
        $missionId, $mission['title'], $mission['responsible_user_id'] ? (int) $mission['responsible_user_id'] : null,
        $sector, $targetStatus, $userId, $canManageWarRoom
    );

    echo json_encode(['ok' => true] + loadSectorPollPayload($missionId, $userId, $canManageWarRoom, $isApprovedParticipant));
    exit;
}

if ($action === 'acknowledge') {
    $sectorId = (int) post('id');
    $sector = dbFetchOne("SELECT id, team_id, acknowledged_at FROM mission_search_sectors WHERE id = ? AND mission_id = ?", [$sectorId, $missionId]);
    if (!$sector) {
        echo json_encode(['ok' => false, 'error' => t('common.not_found')]);
        exit;
    }

    if (!$canManageWarRoom) {
        if (!$isApprovedParticipant) {
            echo json_encode(['ok' => false, 'error' => t('sector.no_manage_permission')]);
            exit;
        }
        $myTeamId = getUserTeamIdForMission($missionId, $userId);
        if (!$sector['team_id'] || (int) $sector['team_id'] !== $myTeamId) {
            echo json_encode(['ok' => false, 'error' => t('sector.not_your_team')]);
            exit;
        }
    }

    // Idempotent, same shape as mission-order.php's own acknowledge — a
    // retry (flaky connection, double-tap) must not overwrite who/when
    // first acknowledged.
    if (!$sector['acknowledged_at']) {
        dbExecute("UPDATE mission_search_sectors SET acknowledged_at = NOW(), acknowledged_by = ? WHERE id = ?", [$userId, $sectorId]);
        logAudit('acknowledge_mission_sector', 'mission_search_sectors', $sectorId, null, ['mission_id' => $missionId]);
    }

    echo json_encode(['ok' => true] + loadSectorPollPayload($missionId, $userId, $canManageWarRoom, $isApprovedParticipant));
    exit;
}

if ($action === 'check_floor' || $action === 'uncheck_floor') {
    $floorId = (int) post('id');
    $note = trim((string) post('note'));
    $note = $note !== '' ? mb_substr($note, 0, 500) : null;

    $floor = dbFetchOne(
        "SELECT f.id, b.sector_id, s.team_id, s.mission_id
         FROM mission_sector_building_floors f
         JOIN mission_sector_buildings b ON b.id = f.building_id
         JOIN mission_search_sectors s ON s.id = b.sector_id
         WHERE f.id = ?",
        [$floorId]
    );
    if (!$floor || (int) $floor['mission_id'] !== $missionId) {
        echo json_encode(['ok' => false, 'error' => t('common.not_found')]);
        exit;
    }

    if (!$canManageWarRoom) {
        if (!$isApprovedParticipant) {
            echo json_encode(['ok' => false, 'error' => t('sector.no_manage_permission')]);
            exit;
        }
        $myTeamId = getUserTeamIdForMission($missionId, $userId);
        if (!$floor['team_id'] || (int) $floor['team_id'] !== $myTeamId) {
            echo json_encode(['ok' => false, 'error' => t('sector.not_your_team')]);
            exit;
        }
    }

    if ($action === 'check_floor') {
        dbExecute("UPDATE mission_sector_building_floors SET checked_at = NOW(), checked_by = ?, note = ? WHERE id = ?", [$userId, $note, $floorId]);
    } else {
        dbExecute("UPDATE mission_sector_building_floors SET checked_at = NULL, checked_by = NULL, note = NULL WHERE id = ?", [$floorId]);
    }
    logAudit($action === 'check_floor' ? 'check_sector_floor' : 'uncheck_sector_floor', 'mission_sector_building_floors', $floorId, null, ['mission_id' => $missionId]);

    echo json_encode(['ok' => true] + loadSectorPollPayload($missionId, $userId, $canManageWarRoom, $isApprovedParticipant));
    exit;
}

// ── Everything below requires admin ──────────────────────────────────────────
if (!$canManageWarRoom) {
    echo json_encode(['ok' => false, 'error' => t('sector.no_manage_permission')]);
    exit;
}

if ($action === 'create_area') {
    $label = trim((string) post('label'));
    if ($label === '') {
        echo json_encode(['ok' => false, 'error' => t('sector.invalid_label')]);
        exit;
    }
    $label = mb_substr($label, 0, 255);

    $rawGeo = json_decode((string) post('geo'), true);
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

    // 0-3, matching LPB_RING_TABLE's own [25,50,75,95] index — set only by
    // the war-room.php ring shortcuts (openDivideRingIntoSectors(),
    // openAutoAssignForRing()), null for a hand-drawn area. Backs the
    // "reset ring assignments" bulk-clear (see clear_ring_generated below).
    $ringIndexRaw = post('ring_index');
    $ringIndex = ($ringIndexRaw !== '' && $ringIndexRaw !== null) ? (int) $ringIndexRaw : null;
    if ($ringIndex !== null && ($ringIndex < 0 || $ringIndex > 3)) {
        echo json_encode(['ok' => false, 'error' => t('common.invalid_request')]);
        exit;
    }

    $areaId = dbInsert(
        "INSERT INTO mission_search_areas (mission_id, label, geo, ring_index, created_by, created_at) VALUES (?, ?, ?, ?, ?, NOW())",
        [$missionId, $label, json_encode($geo), $ringIndex, $userId]
    );
    logAudit('create_mission_search_area', 'mission_search_areas', $areaId, null, ['mission_id' => $missionId, 'ring_index' => $ringIndex]);

    echo json_encode(['ok' => true, 'id' => (int) $areaId] + loadSectorPollPayload($missionId, $userId, $canManageWarRoom, $isApprovedParticipant));
    exit;
}

if ($action === 'create') {
    $label = trim((string) post('label'));
    if ($label === '') {
        echo json_encode(['ok' => false, 'error' => t('sector.invalid_label')]);
        exit;
    }
    $label = mb_substr($label, 0, 255);

    $areaId = (int) post('area_id');
    $area = dbFetchOne("SELECT id FROM mission_search_areas WHERE id = ? AND mission_id = ?", [$areaId, $missionId]);
    if (!$area) {
        echo json_encode(['ok' => false, 'error' => t('sector.area_not_found')]);
        exit;
    }

    $teamIdRaw = post('team_id');
    $teamId = ($teamIdRaw !== '' && $teamIdRaw !== null) ? (int) $teamIdRaw : null;
    if ($teamId) {
        $team = dbFetchOne("SELECT id FROM mission_teams WHERE id = ? AND mission_id = ?", [$teamId, $missionId]);
        if (!$team) {
            echo json_encode(['ok' => false, 'error' => t('common.team_not_found')]);
            exit;
        }
    }

    $rawGeo = json_decode((string) post('geo'), true);
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

    $status = $teamId ? 'assigned' : 'not_started';
    $sectorId = dbInsert(
        "INSERT INTO mission_search_sectors (mission_id, area_id, team_id, label, geo, status, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())",
        [$missionId, $areaId, $teamId, $label, json_encode($geo), $status, $userId]
    );
    if ($teamId) {
        dbExecute("UPDATE mission_search_sectors SET status_updated_at = NOW(), status_updated_by = ? WHERE id = ?", [$userId, $sectorId]);
        dbInsert(
            "INSERT INTO mission_sector_status_log (sector_id, from_status, to_status, team_id, user_id, created_at) VALUES (?, 'not_started', 'assigned', ?, ?, NOW())",
            [$sectorId, $teamId, $userId]
        );
    }
    logAudit('create_mission_sector', 'mission_search_sectors', $sectorId, null, ['mission_id' => $missionId, 'team_id' => $teamId]);

    // No notification if created unassigned — an admin laying out several
    // sectors before assigning any teams must not fire a push per sector.
    if ($teamId) {
        notifySectorAssigned($missionId, $mission['title'], $teamId, $label, $userId);
    }

    echo json_encode(['ok' => true, 'id' => (int) $sectorId] + loadSectorPollPayload($missionId, $userId, $canManageWarRoom, $isApprovedParticipant));
    exit;
}

if ($action === 'assign') {
    $sectorId = (int) post('id');
    $sector = dbFetchOne("SELECT id, team_id, status, label FROM mission_search_sectors WHERE id = ? AND mission_id = ?", [$sectorId, $missionId]);
    if (!$sector) {
        echo json_encode(['ok' => false, 'error' => t('common.not_found')]);
        exit;
    }

    $teamIdRaw = post('team_id');
    $newTeamId = ($teamIdRaw !== '' && $teamIdRaw !== null) ? (int) $teamIdRaw : null;
    if ($newTeamId) {
        $team = dbFetchOne("SELECT id FROM mission_teams WHERE id = ? AND mission_id = ?", [$newTeamId, $missionId]);
        if (!$team) {
            echo json_encode(['ok' => false, 'error' => t('common.team_not_found')]);
            exit;
        }
    }

    $oldTeamId = $sector['team_id'] ? (int) $sector['team_id'] : null;
    dbExecute("UPDATE mission_search_sectors SET team_id = ? WHERE id = ?", [$newTeamId, $sectorId]);
    logAudit('assign_mission_sector', 'mission_search_sectors', $sectorId, null, [
        'mission_id' => $missionId, 'old_team_id' => $oldTeamId, 'new_team_id' => $newTeamId,
    ]);

    // Attaching a team to a not_started sector auto-promotes it to assigned;
    // clearing the team on a sector still at assigned (nothing done yet)
    // reverts to not_started. Any further-along status (in_progress/
    // completed/needs_recheck) keeps its status untouched on reassignment —
    // a team hand-off shouldn't erase recorded progress.
    if ($newTeamId && $sector['status'] === 'not_started') {
        dbExecute("UPDATE mission_search_sectors SET status = 'assigned', status_updated_at = NOW(), status_updated_by = ? WHERE id = ?", [$userId, $sectorId]);
        dbInsert(
            "INSERT INTO mission_sector_status_log (sector_id, from_status, to_status, team_id, user_id, created_at) VALUES (?, 'not_started', 'assigned', ?, ?, NOW())",
            [$sectorId, $newTeamId, $userId]
        );
    } elseif (!$newTeamId && $sector['status'] === 'assigned') {
        dbExecute("UPDATE mission_search_sectors SET status = 'not_started', status_updated_at = NOW(), status_updated_by = ? WHERE id = ?", [$userId, $sectorId]);
        dbInsert(
            "INSERT INTO mission_sector_status_log (sector_id, from_status, to_status, team_id, user_id, created_at) VALUES (?, 'assigned', 'not_started', ?, ?, NOW())",
            [$sectorId, $oldTeamId, $userId]
        );
    }

    // A team hand-off while the sector is still sitting at 'assigned'
    // (whether it already was, or the branch above just promoted it from
    // not_started) means whoever's attached now hasn't confirmed anything
    // yet — an earlier team's acknowledgment must not carry over onto them.
    // Sectors already past 'assigned' (en_route+) keep acknowledged_at
    // untouched, same "don't erase recorded progress" spirit as status above.
    $resultingStatus = ($newTeamId && $sector['status'] === 'not_started') ? 'assigned' : $sector['status'];
    if ($newTeamId !== $oldTeamId && $resultingStatus === 'assigned') {
        dbExecute("UPDATE mission_search_sectors SET acknowledged_at = NULL, acknowledged_by = NULL WHERE id = ?", [$sectorId]);
    }

    if ($newTeamId && $newTeamId !== $oldTeamId) {
        notifySectorAssigned($missionId, $mission['title'], $newTeamId, $sector['label'], $userId);
    }

    echo json_encode(['ok' => true] + loadSectorPollPayload($missionId, $userId, $canManageWarRoom, $isApprovedParticipant));
    exit;
}

if ($action === 'delete') {
    $sectorId = (int) post('id');
    $row = dbFetchOne("SELECT id FROM mission_search_sectors WHERE id = ? AND mission_id = ?", [$sectorId, $missionId]);
    if (!$row) {
        echo json_encode(['ok' => false, 'error' => t('common.not_found')]);
        exit;
    }
    dbExecute("DELETE FROM mission_search_sectors WHERE id = ?", [$sectorId]);
    logAudit('delete_mission_sector', 'mission_search_sectors', $sectorId, null, ['mission_id' => $missionId]);
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'delete_area') {
    $areaId = (int) post('id');
    $row = dbFetchOne("SELECT id FROM mission_search_areas WHERE id = ? AND mission_id = ?", [$areaId, $missionId]);
    if (!$row) {
        echo json_encode(['ok' => false, 'error' => t('common.not_found')]);
        exit;
    }
    // Cascades to this area's sectors, and transitively their buildings/
    // floors — no payload reload (same convention as the plain sector
    // `delete` above); the client filters both local arrays by area_id.
    dbExecute("DELETE FROM mission_search_areas WHERE id = ?", [$areaId]);
    logAudit('delete_mission_search_area', 'mission_search_areas', $areaId, null, ['mission_id' => $missionId]);
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'clear_area_sectors') {
    $areaId = (int) post('id');
    $area = dbFetchOne("SELECT id FROM mission_search_areas WHERE id = ? AND mission_id = ?", [$areaId, $missionId]);
    if (!$area) {
        echo json_encode(['ok' => false, 'error' => t('common.not_found')]);
        exit;
    }
    // Cascades buildings/floors same as every other sector delete path here —
    // no payload reload (matches `delete`/`delete_area`'s own convention),
    // the client filters its local sectors array by area_id.
    $count = (int) dbFetchValue("SELECT COUNT(*) FROM mission_search_sectors WHERE area_id = ?", [$areaId]);
    dbExecute("DELETE FROM mission_search_sectors WHERE area_id = ?", [$areaId]);
    logAudit('clear_mission_search_area_sectors', 'mission_search_areas', $areaId, null, ['mission_id' => $missionId, 'count' => $count]);
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'clear_all_areas') {
    // Bulk wipe — no single record to point at, same null-record_id audit
    // shape as mission-annotation.php's own clear_all action.
    $areaCount = (int) dbFetchValue("SELECT COUNT(*) FROM mission_search_areas WHERE mission_id = ?", [$missionId]);
    $sectorCount = (int) dbFetchValue(
        "SELECT COUNT(*) FROM mission_search_sectors s JOIN mission_search_areas a ON a.id = s.area_id WHERE a.mission_id = ?",
        [$missionId]
    );
    dbExecute("DELETE FROM mission_search_areas WHERE mission_id = ?", [$missionId]);
    logAudit('clear_all_mission_search_areas', 'mission_search_areas', null, null, [
        'mission_id' => $missionId, 'area_count' => $areaCount, 'sector_count' => $sectorCount,
    ]);
    echo json_encode(['ok' => true]);
    exit;
}

// Part of the "reset ring assignments" button on the missing-person card
// (war-room.php) — exact mirror of clear_all_areas above, just scoped to
// ring-generated areas only. area_id is ON DELETE CASCADE, so deleting a
// ring-origin area already removes its sectors too.
if ($action === 'clear_ring_generated') {
    $areaCount = (int) dbFetchValue("SELECT COUNT(*) FROM mission_search_areas WHERE mission_id = ? AND ring_index IS NOT NULL", [$missionId]);
    $sectorCount = (int) dbFetchValue(
        "SELECT COUNT(*) FROM mission_search_sectors s JOIN mission_search_areas a ON a.id = s.area_id WHERE a.mission_id = ? AND a.ring_index IS NOT NULL",
        [$missionId]
    );
    dbExecute("DELETE FROM mission_search_areas WHERE mission_id = ? AND ring_index IS NOT NULL", [$missionId]);
    logAudit('clear_ring_generated_mission_search_areas', 'mission_search_areas', null, null, [
        'mission_id' => $missionId, 'area_count' => $areaCount, 'sector_count' => $sectorCount,
    ]);
    echo json_encode(['ok' => true] + loadSectorPollPayload($missionId, $userId, $canManageWarRoom, $isApprovedParticipant));
    exit;
}

if ($action === 'create_building') {
    $sectorId = (int) post('sector_id');
    $sector = dbFetchOne("SELECT id FROM mission_search_sectors WHERE id = ? AND mission_id = ?", [$sectorId, $missionId]);
    if (!$sector) {
        echo json_encode(['ok' => false, 'error' => t('common.not_found')]);
        exit;
    }

    $label = trim((string) post('label'));
    if ($label === '') {
        echo json_encode(['ok' => false, 'error' => t('sector.invalid_label')]);
        exit;
    }
    $label = mb_substr($label, 0, 255);

    $lat = post('lat');
    $lng = post('lng');
    if (!$isValidLatLng($lat, $lng)) {
        echo json_encode(['ok' => false, 'error' => t('dispatch.invalid_point')]);
        exit;
    }

    $floorCount = (int) post('floor_count');
    if ($floorCount < 1 || $floorCount > 50) {
        echo json_encode(['ok' => false, 'error' => t('common.invalid_request')]);
        exit;
    }

    $buildingId = dbInsert(
        "INSERT INTO mission_sector_buildings (sector_id, label, lat, lng, floor_count, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())",
        [$sectorId, $label, (float) $lat, (float) $lng, $floorCount, $userId]
    );
    // All floors default required=1 — admin narrows via update_building_floors.
    for ($i = 1; $i <= $floorCount; $i++) {
        dbInsert("INSERT INTO mission_sector_building_floors (building_id, floor_number, is_required) VALUES (?, ?, 1)", [$buildingId, $i]);
    }
    logAudit('create_sector_building', 'mission_sector_buildings', $buildingId, null, [
        'mission_id' => $missionId, 'sector_id' => $sectorId, 'floor_count' => $floorCount,
    ]);

    echo json_encode(['ok' => true, 'id' => (int) $buildingId] + loadSectorPollPayload($missionId, $userId, $canManageWarRoom, $isApprovedParticipant));
    exit;
}

if ($action === 'update_building_floors') {
    $buildingId = (int) post('building_id');
    $building = dbFetchOne(
        "SELECT b.id FROM mission_sector_buildings b
         JOIN mission_search_sectors s ON s.id = b.sector_id
         WHERE b.id = ? AND s.mission_id = ?",
        [$buildingId, $missionId]
    );
    if (!$building) {
        echo json_encode(['ok' => false, 'error' => t('common.not_found')]);
        exit;
    }

    $requiredRaw = json_decode((string) post('required_floor_numbers'), true);
    $requiredFloorNumbers = is_array($requiredRaw) ? array_map('intval', $requiredRaw) : [];

    dbExecute("UPDATE mission_sector_building_floors SET is_required = 0 WHERE building_id = ?", [$buildingId]);
    if (!empty($requiredFloorNumbers)) {
        $placeholders = implode(',', array_fill(0, count($requiredFloorNumbers), '?'));
        dbExecute(
            "UPDATE mission_sector_building_floors SET is_required = 1 WHERE building_id = ? AND floor_number IN ($placeholders)",
            array_merge([$buildingId], $requiredFloorNumbers)
        );
    }
    logAudit('update_sector_building_floors', 'mission_sector_buildings', $buildingId, null, [
        'mission_id' => $missionId, 'required_floor_numbers' => $requiredFloorNumbers,
    ]);

    echo json_encode(['ok' => true] + loadSectorPollPayload($missionId, $userId, $canManageWarRoom, $isApprovedParticipant));
    exit;
}

if ($action === 'delete_building') {
    $buildingId = (int) post('id');
    $building = dbFetchOne(
        "SELECT b.id FROM mission_sector_buildings b
         JOIN mission_search_sectors s ON s.id = b.sector_id
         WHERE b.id = ? AND s.mission_id = ?",
        [$buildingId, $missionId]
    );
    if (!$building) {
        echo json_encode(['ok' => false, 'error' => t('common.not_found')]);
        exit;
    }
    dbExecute("DELETE FROM mission_sector_buildings WHERE id = ?", [$buildingId]);
    logAudit('delete_sector_building', 'mission_sector_buildings', $buildingId, null, ['mission_id' => $missionId]);

    echo json_encode(['ok' => true] + loadSectorPollPayload($missionId, $userId, $canManageWarRoom, $isApprovedParticipant));
    exit;
}

echo json_encode(['ok' => false, 'error' => t('common.unknown_action')]);
