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
    $kind = $mediaType === 'video' ? 'βίντεο' : 'φωτογραφία';
    $message = $senderName . ' έστειλε ' . $kind . ' από το πεδίο για την αποστολή «' . $missionTitle . '».';

    $admins = dbFetchAll("SELECT id FROM users WHERE role IN (?, ?) AND is_active = 1", [ROLE_SYSTEM_ADMIN, ROLE_DEPARTMENT_ADMIN]);
    $leaders = dbFetchAll(
        "SELECT DISTINCT u.id FROM users u
         JOIN participation_requests pr ON pr.volunteer_id = u.id
         JOIN shifts s ON pr.shift_id = s.id
         WHERE s.mission_id = ? AND u.role = ? AND u.is_active = 1 AND pr.status = ?",
        [$missionId, ROLE_SHIFT_LEADER, PARTICIPATION_APPROVED]
    );
    $recipientIds = array_merge(
        array_map('intval', array_column($admins, 'id')),
        array_map('intval', array_column($leaders, 'id'))
    );
    if ($responsibleUserId) {
        $recipientIds[] = $responsibleUserId;
    }
    $recipientIds = array_values(array_unique(array_diff($recipientIds, [$senderId])));

    $title = $mediaType === 'video' ? '🎥 Νέο Βίντεο' : '📷 Νέα Φωτογραφία';
    $code = $mediaType === 'video' ? 'mission_video_received' : 'mission_photo_received';
    foreach ($recipientIds as $recipientId) {
        sendNotification($recipientId, $title, $message, 'info', $code, [
            'url' => $warRoomUrl,
            'tag' => 'photo-received-mission-' . $missionId,
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
    echo json_encode(['ok' => false, 'error' => 'Η αποστολή δεν βρέθηκε ή δεν είναι ενεργή στο Επιχειρησιακό.']);
    exit;
}

$canManageWarRoom = hasPagePermission('missions_manage') || (int)$mission['responsible_user_id'] === (int)$userId;
$isApprovedParticipant = (bool) dbFetchValue(
    "SELECT COUNT(*) FROM participation_requests pr
     JOIN shifts s ON s.id = pr.shift_id
     WHERE s.mission_id = ? AND pr.volunteer_id = ? AND pr.status = ?",
    [$missionId, $userId, PARTICIPATION_APPROVED]
);
if (!$canManageWarRoom && !$isApprovedParticipant) {
    echo json_encode(['ok' => false, 'error' => 'Δεν έχετε πρόσβαση στο War Room αυτής της αποστολής.']);
    exit;
}

if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
    echo json_encode(['ok' => false, 'error' => 'Μη έγκυρο αίτημα. Ανανεώστε τη σελίδα.']);
    exit;
}

$action = post('action');

if ($action === 'upload') {
    if (!$isApprovedParticipant) {
        echo json_encode(['ok' => false, 'error' => 'Μόνο εγκεκριμένοι εθελοντές μπορούν να στείλουν φωτογραφία ή βίντεο.']);
        exit;
    }
    if (empty($_FILES['media']['name']) || $_FILES['media']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['ok' => false, 'error' => 'Επιλέξτε ένα αρχείο.']);
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
        echo json_encode(['ok' => false, 'error' => 'Επιτρέπονται μόνο αρχεία εικόνας (JPG, PNG, GIF, WebP) ή βίντεο (MP4, WebM, MOV).']);
        exit;
    }
    if ($file['size'] > $maxSize) {
        echo json_encode(['ok' => false, 'error' => 'Το αρχείο υπερβαίνει το μέγιστο επιτρεπτό μέγεθος (' . ($maxSize / 1024 / 1024) . 'MB).']);
        exit;
    }

    $destDir = __DIR__ . '/uploads/mission-photos/';
    if (!is_dir($destDir)) {
        mkdir($destDir, 0755, true);
    }
    $storedName = 'mphoto_' . $missionId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $destDir . $storedName)) {
        echo json_encode(['ok' => false, 'error' => 'Αποτυχία αποθήκευσης του αρχείου.']);
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

    notifyPhotoReceived($missionId, $mission['title'], $mission['responsible_user_id'] ? (int) $mission['responsible_user_id'] : null, $user['name'], $userId, $mediaType);

    echo json_encode(['ok' => true, 'media' => [
        'id'         => (int) $photoId,
        'media_type' => $mediaType,
        'user_name'  => $user['name'],
        'time'       => date('d/m H:i'),
        'lat'        => $lat,
        'lng'        => $lng,
        'can_delete' => true,
    ]]);
    exit;
}

if ($action === 'delete') {
    $photoId = (int) post('id');
    $photo = dbFetchOne("SELECT id, user_id, stored_name FROM mission_photos WHERE id = ? AND mission_id = ?", [$photoId, $missionId]);
    if (!$photo) {
        echo json_encode(['ok' => false, 'error' => 'Δεν βρέθηκε.']);
        exit;
    }
    if (!$canManageWarRoom && (int) $photo['user_id'] !== $userId) {
        echo json_encode(['ok' => false, 'error' => 'Δεν έχετε δικαίωμα διαγραφής αυτής της φωτογραφίας.']);
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

echo json_encode(['ok' => false, 'error' => 'Άγνωστη ενέργεια.']);
