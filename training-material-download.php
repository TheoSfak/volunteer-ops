<?php
require_once __DIR__ . '/bootstrap.php';
requireLogin();

$id = (int) get('id');
if (empty($id)) {
    setFlash('error', 'Μη έγκυρο ID υλικού.');
    redirect('training.php');
}

// Fetch material
$material = dbFetchOne("SELECT * FROM training_materials WHERE id = ?", [$id]);

if (!$material) {
    setFlash('error', 'Το υλικό δεν βρέθηκε.');
    redirect('training.php');
}

// Build file path
$filePath = TRAINING_UPLOAD_PATH . $material['file_path'];

if (!file_exists($filePath)) {
    setFlash('error', 'Το αρχείο δεν βρέθηκε στον διακομιστή.');
    redirect('training.php');
}

// Track material view
trackMaterialView(getCurrentUserId(), $material['category_id'], $id);

// Sanitize filename for header injection prevention
$safeFilename = preg_replace('/[^\w\s\-.]/', '', $material['title']);
$safeFilename = preg_replace('/\s+/', '_', trim($safeFilename));

// Validate file type (whitelist)
$allowedTypes = [
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-powerpoint',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'image/jpeg',
    'image/png',
    'video/mp4'
];
$contentType = in_array($material['file_type'], $allowedTypes) ? $material['file_type'] : 'application/octet-stream';

// Serve file
header('Content-Type: ' . $contentType);
header('Content-Disposition: inline; filename="' . $safeFilename . '.pdf"');
header('Content-Length: ' . $material['file_size']);
header('Cache-Control: public, max-age=3600');

readfile($filePath);
exit;
