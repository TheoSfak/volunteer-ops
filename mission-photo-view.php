<?php
/**
 * VolunteerOps - Mission Photo Viewer
 * Streams a single War Room field photo inline, gated to users who have
 * War Room access to that photo's mission. Mirrors volunteer-doc-download.php's
 * secure-serve pattern.
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();

$userId = getCurrentUserId();
$photoId = (int) get('id');

$photo = dbFetchOne("SELECT * FROM mission_photos WHERE id = ?", [$photoId]);
if (!$photo) {
    http_response_code(404);
    exit('Δεν βρέθηκε.');
}

$mission = dbFetchOne(
    "SELECT id, status, show_in_ops, responsible_user_id FROM missions WHERE id = ? AND deleted_at IS NULL",
    [$photo['mission_id']]
);
if (!$mission || $mission['status'] !== STATUS_OPEN || empty($mission['show_in_ops'])) {
    http_response_code(404);
    exit('Δεν βρέθηκε.');
}

$canManageWarRoom = hasPagePermission('missions_manage') || (int)$mission['responsible_user_id'] === (int)$userId;
$isApprovedParticipant = (bool) dbFetchValue(
    "SELECT COUNT(*) FROM participation_requests pr
     JOIN shifts s ON s.id = pr.shift_id
     WHERE s.mission_id = ? AND pr.volunteer_id = ? AND pr.status = ?",
    [$mission['id'], $userId, PARTICIPATION_APPROVED]
);
if (!$canManageWarRoom && !$isApprovedParticipant) {
    http_response_code(403);
    exit('Δεν έχετε πρόσβαση.');
}

$filePath = __DIR__ . '/uploads/mission-photos/' . basename($photo['stored_name']);
if (!is_file($filePath) || !is_readable($filePath)) {
    http_response_code(404);
    exit('Το αρχείο δεν βρέθηκε στο σύστημα.');
}

// Trust the actual file contents rather than the MIME value stored in the DB.
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($filePath) ?: 'application/octet-stream';
$allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
if (!in_array($mime, $allowedMimes, true)) {
    http_response_code(415);
    exit('Μη υποστηριζόμενος τύπος αρχείου.');
}

while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', basename((string)($photo['original_name'] ?: $photo['stored_name']))) . '"');
header('Content-Length: ' . filesize($filePath));
header('Cache-Control: private, no-store, max-age=0');
readfile($filePath);
exit;
