<?php
require_once __DIR__ . '/bootstrap.php';
requireLogin();
$subscription = dbFetchOne("SELECT * FROM volunteer_subscriptions WHERE id = ?", [(int)get('id')]);
if (!$subscription || ((int)getCurrentUserId() !== (int)$subscription['user_id'] && !hasPagePermission('subscriptions_manage'))) {
    http_response_code(403); exit('Δεν έχετε δικαίωμα πρόσβασης.');
}
$path = __DIR__ . '/uploads/subscription-receipts/' . basename((string)$subscription['receipt_stored_name']);
if (!$subscription['receipt_stored_name'] || !is_file($path)) { http_response_code(404); exit('Η απόδειξη δεν βρέθηκε.'); }
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';
$allowedMimes = ['application/pdf', 'image/jpeg', 'image/png'];
if (!in_array($mime, $allowedMimes, true)) { http_response_code(415); exit('Μη υποστηριζόμενος τύπος αρχείου.'); }
$downloadName = basename((string)($subscription['receipt_original_name'] ?: 'receipt'));
$asciiName = preg_replace('/[^A-Za-z0-9._-]/', '_', $downloadName) ?: 'receipt';
// A binary response must not contain any buffered whitespace/BOM from included PHP files.
if (ob_get_level() > 0) ob_clean();
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header("Content-Disposition: inline; filename=\"" . $asciiName . "\"; filename*=UTF-8''" . rawurlencode($downloadName));
readfile($path); exit;
