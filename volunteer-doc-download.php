<?php
/**
 * VolunteerOps - Volunteer Document Download
 * Serves volunteer documents securely (admins only)
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();
requireRole([ROLE_SYSTEM_ADMIN, ROLE_DEPARTMENT_ADMIN, ROLE_SHIFT_LEADER]);

$docId      = (int) get('id');
$volunteerId = (int) get('volunteer');

if (!$docId || !$volunteerId) {
    setFlash('error', 'Μη έγκυρο αίτημα.');
    redirect('volunteers.php');
}

$doc = dbFetchOne(
    "SELECT * FROM volunteer_documents WHERE id = ? AND user_id = ?",
    [$docId, $volunteerId]
);

if (!$doc) {
    setFlash('error', 'Το αρχείο δεν βρέθηκε.');
    redirect('volunteer-view.php?id=' . $volunteerId);
}

$filePath = __DIR__ . '/uploads/volunteer-docs/' . $doc['stored_name'];

if (!file_exists($filePath)) {
    setFlash('error', 'Το αρχείο δεν βρέθηκε στο σύστημα.');
    redirect('volunteer-view.php?id=' . $volunteerId);
}

// Log the access
logAudit('download_document', 'volunteer_documents', $docId, $doc['label']);

// Serve the file
header('Content-Type: ' . $doc['mime_type']);
header('Content-Disposition: inline; filename="' . addslashes($doc['original_name']) . '"');
header('Content-Length: ' . filesize($filePath));
header('Cache-Control: private, max-age=3600');
readfile($filePath);
exit;
