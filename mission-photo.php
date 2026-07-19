<?php
/**
 * VolunteerOps - Mission Photo Endpoint
 * War Room: any approved participant uploads a field photo (sender, timestamp,
 * and best-effort GPS are captured); admins or the sender can delete one.
 * POST only, AJAX/multipart.
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();

header('Content-Type: application/json');

/**
 * Notify command staff (system/department admins, this mission's shift leaders,
 * and its responsible user) that a field photo came in. Mirrors the recipient
 * resolution in mission-dispatch.php's notifyDispatchArrival().
 */
function notifyPhotoReceived(int $missionId, string $missionTitle, ?int $responsibleUserId, string $senderName, int $senderId): void {
    $warRoomUrl = rtrim(BASE_URL, '/') . '/war-room.php?id=' . $missionId;
    $message = $senderName . ' έστειλε φωτογραφία από το πεδίο για την αποστολή «' . $missionTitle . '».';

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

    foreach ($recipientIds as $recipientId) {
        sendNotification($recipientId, '📷 Νέα Φωτογραφία', $message, 'info', 'mission_photo_received', [
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
        echo json_encode(['ok' => false, 'error' => 'Μόνο εγκεκριμένοι εθελοντές μπορούν να στείλουν φωτογραφία.']);
        exit;
    }
    if (empty($_FILES['photo']['name']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['ok' => false, 'error' => 'Επιλέξτε μια φωτογραφία.']);
        exit;
    }

    $file = $_FILES['photo'];
    $allowedExt  = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $allowedMime = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $origName = basename($file['name']);
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);

    if (!in_array($ext, $allowedExt, true) || !in_array($mime, $allowedMime, true)) {
        echo json_encode(['ok' => false, 'error' => 'Επιτρέπονται μόνο αρχεία εικόνας (JPG, PNG, GIF, WebP).']);
        exit;
    }
    if ($file['size'] > UPLOAD_MAX_SIZE) {
        echo json_encode(['ok' => false, 'error' => 'Το αρχείο υπερβαίνει το μέγιστο επιτρεπτό μέγεθος.']);
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
        "INSERT INTO mission_photos (mission_id, user_id, stored_name, original_name, mime_type, file_size, lat, lng, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())",
        [$missionId, $userId, $storedName, $origName, $mime, (int) $file['size'], $lat, $lng]
    );
    logAudit('upload_mission_photo', 'mission_photos', $photoId, null, ['mission_id' => $missionId]);

    notifyPhotoReceived($missionId, $mission['title'], $mission['responsible_user_id'] ? (int) $mission['responsible_user_id'] : null, $user['name'], $userId);

    echo json_encode(['ok' => true, 'photo' => [
        'id'         => (int) $photoId,
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
