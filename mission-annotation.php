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

$action = post('action');

if ($action === 'create') {
    $type = post('type');
    $rawGeo = json_decode((string) post('geo'), true);
    $label = trim((string) post('label'));
    $label = $label !== '' ? mb_substr($label, 0, 255) : null;

    if ($type === 'freehand') {
        if (!is_array($rawGeo) || count($rawGeo) < 2) {
            echo json_encode(['ok' => false, 'error' => t('annotation.freehand_needs_2_points')]);
            exit;
        }
        $geo = array_map(fn($pt) => [(float) $pt[0], (float) $pt[1]], $rawGeo);
        $label = null;
    } elseif ($type === 'arrow') {
        if (!is_array($rawGeo) || count($rawGeo) !== 2) {
            echo json_encode(['ok' => false, 'error' => t('annotation.arrow_needs_2_points')]);
            exit;
        }
        $geo = array_map(fn($pt) => [(float) $pt[0], (float) $pt[1]], $rawGeo);
        $label = null;
    } elseif ($type === 'text') {
        if (!is_array($rawGeo) || !isset($rawGeo['lat'], $rawGeo['lng'])) {
            echo json_encode(['ok' => false, 'error' => t('annotation.invalid_point')]);
            exit;
        }
        if ($label === null) {
            echo json_encode(['ok' => false, 'error' => t('annotation.text_required')]);
            exit;
        }
        $geo = ['lat' => (float) $rawGeo['lat'], 'lng' => (float) $rawGeo['lng']];
    } else {
        echo json_encode(['ok' => false, 'error' => t('annotation.unknown_type')]);
        exit;
    }

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

echo json_encode(['ok' => false, 'error' => t('common.unknown_action')]);
