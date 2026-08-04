<?php
/**
 * VolunteerOps - Restricted Area Endpoint
 * War Room: admin draws hazard/danger-zone polygons on the shared map, above
 * every other layer. A volunteer's GPS ping landing inside one (checked in
 * recordVolunteerPing(), includes/functions-warroom.php) opens a breach
 * record and fires the dual-sided (volunteer + command staff) full-screen
 * alarm; here is just the admin CRUD for the zones themselves plus the
 * ack/resolve bookkeeping on breaches, mirroring mission-sos.php's shape.
 * GET polls for state visible to the caller, POST mutates. AJAX only.
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();

header('Content-Type: application/json');

$userId = getCurrentUserId();

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

// ── GET: poll for zones (admin only, map rendering) + breaches (personalized) ─
if (!isPost()) {
    echo json_encode([
        'ok'       => true,
        'areas'    => $canManageWarRoom ? loadMissionRestrictedAreasForUser($missionId) : [],
        'breaches' => loadOpenRestrictedAreaBreachesForUser($missionId, $userId, $canManageWarRoom),
    ]);
    exit;
}

// ── POST: everything below is a mutation, all admin-only ────────────────────
if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string) $_POST['csrf_token'])) {
    echo json_encode(['ok' => false, 'error' => t('common.invalid_request')]);
    exit;
}

if (!$canManageWarRoom) {
    echo json_encode(['ok' => false, 'error' => t('sector.no_manage_permission')]);
    exit;
}

$action = post('action');
$isValidLatLng = fn($lat, $lng) => is_numeric($lat) && is_numeric($lng)
    && (float) $lat >= -90 && (float) $lat <= 90 && (float) $lng >= -180 && (float) $lng <= 180
    && !((float) $lat === 0.0 && (float) $lng === 0.0);

if ($action === 'create') {
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

    $areaId = dbInsert(
        "INSERT INTO mission_restricted_areas (mission_id, label, geo, created_by, created_at) VALUES (?, ?, ?, ?, NOW())",
        [$missionId, $label, json_encode($geo), $userId]
    );
    logAudit('create_mission_restricted_area', 'mission_restricted_areas', $areaId, null, ['mission_id' => $missionId]);

    echo json_encode(['ok' => true, 'id' => (int) $areaId, 'areas' => loadMissionRestrictedAreasForUser($missionId)]);
    exit;
}

if ($action === 'delete') {
    $areaId = (int) post('id');
    $area = dbFetchOne("SELECT id FROM mission_restricted_areas WHERE id = ? AND mission_id = ?", [$areaId, $missionId]);
    if (!$area) {
        echo json_encode(['ok' => false, 'error' => t('common.not_found')]);
        exit;
    }

    // Force-resolve any currently-open breaches for this area before deleting
    // it — auto-resolve, not auto-erase. restricted_area_id is ON DELETE SET
    // NULL (not CASCADE), so the breach rows themselves survive as an audit
    // trail (area_label was snapshotted at breach-creation time), but without
    // this step a deleted zone would leave an orphaned OPEN alarm on
    // someone's screen with nothing left that could ever auto-clear it.
    db()->beginTransaction();
    try {
        dbExecute(
            "UPDATE mission_restricted_area_breaches SET resolved_at = NOW(), resolved_by = ? WHERE restricted_area_id = ? AND resolved_at IS NULL",
            [$userId, $areaId]
        );
        dbExecute("DELETE FROM mission_restricted_areas WHERE id = ?", [$areaId]);
        db()->commit();
    } catch (Exception $e) {
        db()->rollBack();
        echo json_encode(['ok' => false, 'error' => t('common.failed')]);
        exit;
    }
    logAudit('delete_mission_restricted_area', 'mission_restricted_areas', $areaId, null, ['mission_id' => $missionId]);

    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'acknowledge') {
    $breachId = (int) post('id');
    $breach = dbFetchOne("SELECT id, mission_id, acknowledged_at FROM mission_restricted_area_breaches WHERE id = ?", [$breachId]);
    if (!$breach || (int) $breach['mission_id'] !== $missionId) {
        echo json_encode(['ok' => false, 'error' => t('common.not_found')]);
        exit;
    }
    if (!$breach['acknowledged_at']) {
        dbExecute("UPDATE mission_restricted_area_breaches SET acknowledged_at = NOW(), acknowledged_by = ? WHERE id = ?", [$userId, $breachId]);
        logAudit('acknowledge_restricted_area_breach', 'mission_restricted_area_breaches', $breachId, null, ['mission_id' => $missionId]);
    }
    echo json_encode(['ok' => true, 'breaches' => loadOpenRestrictedAreaBreachesForUser($missionId, $userId, true)]);
    exit;
}

if ($action === 'resolve') {
    $breachId = (int) post('id');
    $breach = dbFetchOne("SELECT id, mission_id, resolved_at FROM mission_restricted_area_breaches WHERE id = ?", [$breachId]);
    if (!$breach || (int) $breach['mission_id'] !== $missionId) {
        echo json_encode(['ok' => false, 'error' => t('common.not_found')]);
        exit;
    }
    if (!$breach['resolved_at']) {
        dbExecute(
            "UPDATE mission_restricted_area_breaches
             SET acknowledged_at = COALESCE(acknowledged_at, NOW()), acknowledged_by = COALESCE(acknowledged_by, ?),
                 resolved_at = NOW(), resolved_by = ?
             WHERE id = ?",
            [$userId, $userId, $breachId]
        );
        logAudit('resolve_restricted_area_breach', 'mission_restricted_area_breaches', $breachId, null, ['mission_id' => $missionId]);
    }
    echo json_encode(['ok' => true, 'breaches' => loadOpenRestrictedAreaBreachesForUser($missionId, $userId, true)]);
    exit;
}

echo json_encode(['ok' => false, 'error' => t('common.unknown_action')]);
