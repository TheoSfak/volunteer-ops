<?php
/** VolunteerOps — Οικονομικά: stream a document's stored file (v3.510.0). */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/finance-functions.php';
requireLogin();
if (!hasPagePermission('finance_manage')) { http_response_code(403); exit('Δεν έχετε δικαίωμα πρόσβασης.'); }

$doc = dbFetchOne("SELECT file_original_name, file_stored_name FROM finance_documents WHERE id = ?", [(int) get('id')]);
ensurePrivateUploadDir(financeUploadDir());
$path = financeUploadDir() . basename((string) ($doc['file_stored_name'] ?? ''));
if (!$doc || empty($doc['file_stored_name']) || !is_file($path)) { http_response_code(404); exit('Το αρχείο δεν βρέθηκε.'); }
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';
if (!in_array($mime, ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'], true)) { http_response_code(415); exit('Μη υποστηριζόμενος τύπος αρχείου.'); }
$name = basename((string) ($doc['file_original_name'] ?: 'document'));
$ascii = preg_replace('/[^A-Za-z0-9._-]/', '_', $name) ?: 'document';
if (ob_get_level() > 0) ob_clean();
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
// Images only: a sandbox CSP stops Chrome's built-in viewer from showing a PDF inline,
// and browsers' PDF viewers never run the PDF's script in our origin anyway.
if ($mime !== 'application/pdf') header('Content-Security-Policy: sandbox');
header("Content-Disposition: inline; filename=\"" . $ascii . "\"; filename*=UTF-8''" . rawurlencode($name));
readfile($path);
exit;
