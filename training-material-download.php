<?php
require_once __DIR__ . '/bootstrap.php';
requireLogin();

$id = get('id');
if (empty($id)) {
    die('Invalid material ID');
}

// Fetch material
$material = dbFetchOne("SELECT * FROM training_materials WHERE id = ?", [$id]);

if (!$material) {
    die('Material not found');
}

// Build file path
$filePath = TRAINING_UPLOAD_PATH . $material['file_path'];

if (!file_exists($filePath)) {
    die('File not found on server');
}

// Track material view
trackMaterialView(getCurrentUserId(), $material['category_id'], $id);

// Serve file
header('Content-Type: ' . $material['file_type']);
header('Content-Disposition: inline; filename="' . $material['title'] . '.pdf"');
header('Content-Length: ' . $material['file_size']);
header('Cache-Control: public, max-age=3600');

readfile($filePath);
exit;
