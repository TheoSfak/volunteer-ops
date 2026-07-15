<?php
require_once __DIR__ . '/bootstrap.php';
requireLogin();
$subscription = dbFetchOne("SELECT * FROM volunteer_subscriptions WHERE id = ?", [(int)get('id')]);
if (!$subscription || (getCurrentUserId() !== (int)$subscription['user_id'] && !hasPagePermission('subscriptions_manage'))) {
    http_response_code(403); exit('Δεν έχετε δικαίωμα πρόσβασης.');
}
$path = __DIR__ . '/uploads/subscription-receipts/' . basename((string)$subscription['receipt_stored_name']);
if (!$subscription['receipt_stored_name'] || !is_file($path)) { http_response_code(404); exit('Η απόδειξη δεν βρέθηκε.'); }
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';
header('Content-Type: ' . $mime); header('Content-Length: ' . filesize($path)); header('Content-Disposition: inline; filename="' . rawurlencode($subscription['receipt_original_name'] ?: 'receipt') . '"');
readfile($path); exit;
