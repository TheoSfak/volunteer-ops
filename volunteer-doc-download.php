<?php
/**
 * VolunteerOps - Volunteer Document Download
 * Serves volunteer documents securely to administrators and to their owner.
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();

$docId      = (int) get('id');
$volunteerId = (int) get('volunteer');

if (!$docId || !$volunteerId) {
    setFlash('error', 'Μη έγκυρο αίτημα.');
    redirect('profile.php');
}

$isOwner = getCurrentUserId() === $volunteerId;
if (!$isOwner && !hasPagePermission('volunteers_view')) {
    setFlash('error', 'Δεν έχετε δικαίωμα πρόσβασης σε αυτό το αρχείο.');
    redirect('dashboard.php');
}

$doc = dbFetchOne(
    "SELECT * FROM volunteer_documents WHERE id = ? AND user_id = ?",
    [$docId, $volunteerId]
);

if (!$doc) {
    setFlash('error', 'Το αρχείο δεν βρέθηκε.');
    redirect($isOwner ? 'profile.php#documents' : 'volunteer-view.php?id=' . $volunteerId . '#documents');
}

$filePath = __DIR__ . '/uploads/volunteer-docs/' . basename($doc['stored_name']);

if (!is_file($filePath) || !is_readable($filePath)) {
    setFlash('error', 'Το αρχείο δεν βρέθηκε στο σύστημα.');
    redirect($isOwner ? 'profile.php#documents' : 'volunteer-view.php?id=' . $volunteerId . '#documents');
}

// Trust the actual file contents rather than the MIME value stored in the DB.
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($filePath) ?: 'application/octet-stream';
$allowedMimes = [
    'application/pdf',
    'image/jpeg',
    'image/png',
    'image/gif',
    'image/webp',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
];
if (!in_array($mime, $allowedMimes, true)) {
    http_response_code(415);
    exit('Μη υποστηριζόμενος τύπος αρχείου.');
}

// Log the access
logAudit('download_document', 'volunteer_documents', $docId, $doc['label']);

// Serve a clean binary response. Even one buffered whitespace/BOM byte before a
// JPEG can make the browser display it as a broken image.
$downloadName = basename((string)($doc['original_name'] ?: $doc['stored_name']));
$asciiName = preg_replace('/[^A-Za-z0-9._-]/', '_', $downloadName) ?: 'document';
while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header("Content-Disposition: inline; filename=\"" . $asciiName . "\"; filename*=UTF-8''" . rawurlencode($downloadName));
header('Content-Length: ' . filesize($filePath));
header('Cache-Control: private, no-store, max-age=0');
readfile($filePath);
exit;
