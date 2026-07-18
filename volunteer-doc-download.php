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

if (!file_exists($filePath)) {
    setFlash('error', 'Το αρχείο δεν βρέθηκε στο σύστημα.');
    redirect($isOwner ? 'profile.php#documents' : 'volunteer-view.php?id=' . $volunteerId . '#documents');
}

// Log the access
logAudit('download_document', 'volunteer_documents', $docId, $doc['label']);

// Serve the file
$safeName = preg_replace('/[^\w\s\-.]/', '', $doc['original_name']);
header('Content-Type: ' . $doc['mime_type']);
header('Content-Disposition: inline; filename="' . $safeName . '"');
header('Content-Length: ' . filesize($filePath));
header('Cache-Control: private, max-age=3600');
readfile($filePath);
exit;
