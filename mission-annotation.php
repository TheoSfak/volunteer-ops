<?php
/**
 * VolunteerOps - Action Room Map Annotations Endpoint
 * Command staff sketch freehand lines, arrows, or text labels directly on the
 * shared live map; every approved participant can see them, only command
 * staff can create or delete. POST only, AJAX.
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();

header('Content-Type: application/json');

$userId = getCurrentUserId();
$user = getCurrentUser();

if (!isPost()) {
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$missionId = (int) post('mission_id');

$mission = dbFetchOne(
    "SELECT id, status, show_in_ops, responsible_user_id FROM missions WHERE id = ? AND deleted_at IS NULL",
    [$missionId]
);
if (!$mission || $mission['status'] !== STATUS_OPEN || empty($mission['show_in_ops'])) {
    echo json_encode(['ok' => false, 'error' => t('common.mission_not_found_or_inactive')]);
    exit;
}

$canManageWarRoom = canManageActionRoom($mission['responsible_user_id'] ? (int) $mission['responsible_user_id'] : null, (int) $userId);
if (!$canManageWarRoom) {
    echo json_encode(['ok' => false, 'error' => t('annotation.no_manage_permission')]);
    exit;
}

if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string) $_POST['csrf_token'])) {
    echo json_encode(['ok' => false, 'error' => t('common.invalid_request')]);
    exit;
}

// Same lat/lng range ping-location.php already enforces for a live GPS fix —
// admin-drawn here, not device-reported, but a malformed value would still
// put a sketch somewhere nonsensical on the shared map. Shared by 'create'
// (a brand-new shape) and 'move' (an existing one dragged to a new spot) so
// the two can never drift apart on what counts as a valid geo.
function wrAnnotationGeoFromRaw($type, $rawGeo) {
    $isValidLatLng = fn($lat, $lng) => is_numeric($lat) && is_numeric($lng)
        && (float) $lat >= -90 && (float) $lat <= 90 && (float) $lng >= -180 && (float) $lng <= 180
        && !((float) $lat === 0.0 && (float) $lng === 0.0);

    if ($type === 'freehand') {
        if (!is_array($rawGeo) || count($rawGeo) < 2) return null;
        foreach ($rawGeo as $pt) {
            if (!is_array($pt) || !isset($pt[0], $pt[1]) || !$isValidLatLng($pt[0], $pt[1])) return null;
        }
        return array_map(fn($pt) => [(float) $pt[0], (float) $pt[1]], $rawGeo);
    }
    if ($type === 'arrow') {
        if (!is_array($rawGeo) || count($rawGeo) !== 2) return null;
        foreach ($rawGeo as $pt) {
            if (!is_array($pt) || !isset($pt[0], $pt[1]) || !$isValidLatLng($pt[0], $pt[1])) return null;
        }
        return array_map(fn($pt) => [(float) $pt[0], (float) $pt[1]], $rawGeo);
    }
    if ($type === 'text') {
        if (!is_array($rawGeo) || !isset($rawGeo['lat'], $rawGeo['lng']) || !$isValidLatLng($rawGeo['lat'], $rawGeo['lng'])) return null;
        return ['lat' => (float) $rawGeo['lat'], 'lng' => (float) $rawGeo['lng']];
    }
    return null;
}

$action = post('action');

if ($action === 'create') {
    $type = post('type');
    $rawGeo = json_decode((string) post('geo'), true);
    $label = trim((string) post('label'));
    $label = $label !== '' ? mb_substr($label, 0, 255) : null;

    if (!in_array($type, ['freehand', 'arrow', 'text'], true)) {
        echo json_encode(['ok' => false, 'error' => t('annotation.unknown_type')]);
        exit;
    }

    $geo = wrAnnotationGeoFromRaw($type, $rawGeo);
    if ($geo === null) {
        $error = $type === 'freehand' ? t('annotation.freehand_needs_2_points')
            : ($type === 'arrow' ? t('annotation.arrow_needs_2_points') : t('annotation.invalid_point'));
        echo json_encode(['ok' => false, 'error' => $error]);
        exit;
    }
    if ($type === 'text' && $label === null) {
        echo json_encode(['ok' => false, 'error' => t('annotation.text_required')]);
        exit;
    }
    if ($type !== 'text') $label = null;

    $annotationId = dbInsert(
        "INSERT INTO mission_annotations (mission_id, type, geo, label, created_by, created_at) VALUES (?, ?, ?, ?, ?, NOW())",
        [$missionId, $type, json_encode($geo), $label, $userId]
    );
    logAudit('create_mission_annotation', 'mission_annotations', $annotationId, null, ['mission_id' => $missionId, 'type' => $type]);

    echo json_encode(['ok' => true, 'annotation' => [
        'id'              => (int) $annotationId,
        'type'            => $type,
        'geo'             => $geo,
        'label'           => $label,
        'created_by_name' => $user['name'],
        'time'            => date('H:i'),
    ]]);
    exit;
}

if ($action === 'delete') {
    $annotationId = (int) post('id');
    $row = dbFetchOne("SELECT id FROM mission_annotations WHERE id = ? AND mission_id = ?", [$annotationId, $missionId]);
    if (!$row) {
        echo json_encode(['ok' => false, 'error' => t('common.not_found')]);
        exit;
    }
    dbExecute("DELETE FROM mission_annotations WHERE id = ?", [$annotationId]);
    logAudit('delete_mission_annotation', 'mission_annotations', $annotationId, null, ['mission_id' => $missionId]);
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'move') {
    $annotationId = (int) post('id');
    $row = dbFetchOne("SELECT id, type FROM mission_annotations WHERE id = ? AND mission_id = ?", [$annotationId, $missionId]);
    if (!$row) {
        echo json_encode(['ok' => false, 'error' => t('common.not_found')]);
        exit;
    }

    $rawGeo = json_decode((string) post('geo'), true);
    $geo = wrAnnotationGeoFromRaw($row['type'], $rawGeo);
    if ($geo === null) {
        echo json_encode(['ok' => false, 'error' => t('annotation.invalid_point')]);
        exit;
    }

    dbExecute("UPDATE mission_annotations SET geo = ? WHERE id = ?", [json_encode($geo), $annotationId]);
    logAudit('move_mission_annotation', 'mission_annotations', $annotationId, null, ['mission_id' => $missionId]);
    echo json_encode(['ok' => true, 'annotation' => ['id' => $annotationId, 'geo' => $geo]]);
    exit;
}

if ($action === 'clear_all') {
    $count = (int) dbFetchValue("SELECT COUNT(*) FROM mission_annotations WHERE mission_id = ?", [$missionId]);
    if ($count > 0) {
        dbExecute("DELETE FROM mission_annotations WHERE mission_id = ?", [$missionId]);
        // No single record_id for a bulk wipe — same null-id shape audit.php
        // already uses for 'clear_audit_log'.
        logAudit('clear_all_mission_annotations', 'mission_annotations', null, null, ['mission_id' => $missionId, 'count' => $count]);
    }
    echo json_encode(['ok' => true, 'deleted' => $count]);
    exit;
}

echo json_encode(['ok' => false, 'error' => t('common.unknown_action')]);
