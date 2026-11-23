<?php
/**
 * VolunteerOps — Οικονομικά: read a receipt photo with the AI (v3.510.0). AJAX only; stores NOTHING.
 * In: POST csrf_token, image (data URL, already downsized in the browser). Out: JSON for the document form.
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/finance-functions.php';
require_once __DIR__ . '/includes/finance-scan.php';
require_once __DIR__ . '/includes/ai-vision.php';

requireLogin();
if (!hasPagePermission('finance_manage')) jsonResponse(['ok' => false, 'error' => 'Δεν έχετε δικαίωμα πρόσβασης.'], 403);
if (!isPost()) jsonResponse(['ok' => false, 'error' => 'Μη έγκυρο αίτημα.'], 405);
if (!hash_equals($_SESSION['csrf_token'] ?? '', (string) ($_POST['csrf_token'] ?? ''))) jsonResponse(['ok' => false, 'error' => 'Μη έγκυρο αίτημα. Ανανεώστε τη σελίδα.'], 400);
// A 30-90 second AI call must not hold the session lock and freeze the user's other tabs.
session_write_close();

$image = (string) ($_POST['image'] ?? '');
if (!financeScanImageOk($image)) jsonResponse(['ok' => false, 'error' => 'Μη έγκυρη εικόνα.'], 400);
if (!aiVisionAvailable()) jsonResponse(['ok' => false, 'error' => 'Δεν υπάρχει ρυθμισμένος πάροχος AI που διαβάζει εικόνες (Gemini, Groq ή Grok).'], 409);

$categories = financeCategories(null);
$result = aiVisionChat(financeScanMessages($image, array_column($categories, 'name')),
    ['json' => true, 'temperature' => 0.1, 'max_tokens' => 3000, 'timeout' => 90]);
if (empty($result['ok']) || !is_array($result['json'] ?? null)) {
    jsonResponse(['ok' => false, 'error' => $result['error'] ?: 'Το AI δεν επέστρεψε αναγνώσιμα στοιχεία.'], 502);
}
$data = financeValidateScan($result['json'], $categories, date('Y-m-d'), (int) financeSetting('finance_default_vat_rate', '24'));
jsonResponse(['ok' => true, 'data' => $data, 'provider_label' => $result['provider_label'] ?: $result['provider']]);
