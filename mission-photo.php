<?php
/**
 * VolunteerOps - Mission Photo/Video Endpoint
 * War Room: any approved participant uploads a field photo or video (sender,
 * timestamp, and best-effort GPS are captured); admins or the sender can
 * delete one. POST only, AJAX/multipart.
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();

header('Content-Type: application/json');

/**
 * Notify command staff (system/department admins, this mission's shift leaders,
 * and its responsible user) that a field photo/video came in. Mirrors the
 * recipient resolution in mission-dispatch.php's notifyDispatchArrival().
 */
function notifyPhotoReceived(int $missionId, string $missionTitle, ?int $responsibleUserId, string $senderName, int $senderId, string $mediaType): void {
    $warRoomUrl = rtrim(BASE_URL, '/') . '/war-room.php?id=' . $missionId;

    $recipientIds = getMissionCommandStaffIds($missionId, $responsibleUserId, $senderId);

    $titleKey = $mediaType === 'video' ? 'photo.notify_title_video' : 'photo.notify_title_photo';
    $kindKey = $mediaType === 'video' ? 'photo.kind_video' : 'photo.kind_photo';
    $code = $mediaType === 'video' ? 'mission_video_received' : 'mission_photo_received';
    $langByUserId = getUserLanguages($recipientIds);
    foreach ($recipientIds as $recipientId) {
        $lang = $langByUserId[$recipientId] ?? DEFAULT_LANGUAGE;
        $message = t('photo.notify_message', ['name' => $senderName, 'kind' => t($kindKey, [], $lang), 'mission' => $missionTitle], $lang);
        sendNotification($recipientId, t($titleKey, [], $lang), $message, 'info', $code, [
            'url' => $warRoomUrl,
            'tag' => 'photo-received-mission-' . $missionId,
            'bannerMission' => $missionId,
        ]);
    }
}

$userId = getCurrentUserId();
$user = getCurrentUser();

$missionId = (int) post('mission_id');

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

if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string) $_POST['csrf_token'])) {
    echo json_encode(['ok' => false, 'error' => t('common.invalid_request')]);
    exit;
}

$action = post('action');

if ($action === 'upload') {
    if (!$isApprovedParticipant) {
        echo json_encode(['ok' => false, 'error' => t('photo.only_approved_can_send')]);
        exit;
    }
    if (empty($_FILES['media']['name']) || $_FILES['media']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['ok' => false, 'error' => t('photo.select_file')]);
        exit;
    }

    $file = $_FILES['media'];
    $photoExt   = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $photoMime  = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $videoExt   = ['mp4', 'webm', 'mov', 'm4v'];
    $videoMime  = ['video/mp4', 'video/webm', 'video/quicktime', 'video/x-m4v'];
    $origName = basename($file['name']);
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);

    if (in_array($ext, $photoExt, true) && in_array($mime, $photoMime, true)) {
        $mediaType = 'photo';
        $maxSize = UPLOAD_MAX_SIZE;
    } elseif (in_array($ext, $videoExt, true) && in_array($mime, $videoMime, true)) {
        $mediaType = 'video';
        $maxSize = VIDEO_MAX_SIZE;
    } else {
        echo json_encode(['ok' => false, 'error' => t('photo.invalid_type')]);
        exit;
    }
    if ($file['size'] > $maxSize) {
        echo json_encode(['ok' => false, 'error' => t('photo.file_too_large', ['size' => $maxSize / 1024 / 1024])]);
        exit;
    }

    $destDir = __DIR__ . '/uploads/mission-photos/';
    if (!is_dir($destDir)) {
        mkdir($destDir, 0755, true);
    }
    $storedName = 'mphoto_' . $missionId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $destDir . $storedName)) {
        echo json_encode(['ok' => false, 'error' => t('photo.save_failed')]);
        exit;
    }

    $latRaw = post('lat');
    $lngRaw = post('lng');
    $lat = ($latRaw !== '' && $latRaw !== null && is_numeric($latRaw)) ? (float) $latRaw : null;
    $lng = ($lngRaw !== '' && $lngRaw !== null && is_numeric($lngRaw)) ? (float) $lngRaw : null;

    $photoId = dbInsert(
        "INSERT INTO mission_photos (mission_id, user_id, media_type, stored_name, original_name, mime_type, file_size, lat, lng, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
        [$missionId, $userId, $mediaType, $storedName, $origName, $mime, (int) $file['size'], $lat, $lng]
    );
    logAudit('upload_mission_photo', 'mission_photos', $photoId, null, ['mission_id' => $missionId, 'media_type' => $mediaType]);

    // Auto-fulfill any outstanding War Room "send a photo/video" orders of this type for this user.
    dbExecute(
        "UPDATE mission_order_recipients r
         JOIN mission_orders o ON o.id = r.order_id
         SET r.fulfilled_at = NOW()
         WHERE r.user_id = ? AND o.mission_id = ? AND o.order_type = ? AND r.fulfilled_at IS NULL",
        [$userId, $missionId, $mediaType]
    );

    notifyPhotoReceived($missionId, $mission['title'], $mission['responsible_user_id'] ? (int) $mission['responsible_user_id'] : null, $user['name'], $userId, $mediaType);

    $teamLabel = null;
    $myTeamId = getUserTeamIdForMission($missionId, $userId);
    if ($myTeamId) {
        $teamRow = dbFetchOne("SELECT codename, team_number FROM mission_teams WHERE id = ?", [$myTeamId]);
        if ($teamRow) {
            $teamLabel = $teamRow['codename'] . ' ' . $teamRow['team_number'];
        }
    }

    echo json_encode(['ok' => true, 'media' => [
        'id'             => (int) $photoId,
        'media_type'     => $mediaType,
        'user_name'      => $user['name'],
        'is_external'    => (bool) $user['is_external'],
        'guest_org_name' => $user['guest_org_name'],
        'team_label'     => $teamLabel,
        'time'           => date('d/m H:i'),
        'lat'            => $lat,
        'lng'            => $lng,
        'can_delete'     => true,
    ]]);
    exit;
}

if ($action === 'delete') {
    $photoId = (int) post('id');
    $photo = dbFetchOne("SELECT id, user_id, stored_name FROM mission_photos WHERE id = ? AND mission_id = ?", [$photoId, $missionId]);
    if (!$photo) {
        echo json_encode(['ok' => false, 'error' => t('common.not_found')]);
        exit;
    }
    if (!$canManageWarRoom && (int) $photo['user_id'] !== $userId) {
        echo json_encode(['ok' => false, 'error' => t('photo.no_delete_permission')]);
        exit;
    }

    $filePath = __DIR__ . '/uploads/mission-photos/' . basename($photo['stored_name']);
    if (is_file($filePath)) {
        unlink($filePath);
    }
    dbExecute("DELETE FROM mission_photos WHERE id = ?", [$photoId]);
    logAudit('delete_mission_photo', 'mission_photos', $photoId, null, ['mission_id' => $missionId]);

    echo json_encode(['ok' => true]);
    exit;
}

echo json_encode(['ok' => false, 'error' => t('common.unknown_action')]);
