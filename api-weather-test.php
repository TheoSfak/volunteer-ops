<?php
/**
 * AJAX endpoint — test the stored OpenWeatherMap API key.
 * POST only, admin access required.
 * Returns JSON: { ok: bool, message: string }
 */
require_once __DIR__ . '/bootstrap.php';
requireRole([ROLE_SYSTEM_ADMIN]);

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'message' => 'Μη έγκυρη μέθοδος']);
    exit;
}

$apiKey = trim(getSetting('openweathermap_api_key', ''));
if (empty($apiKey)) {
    echo json_encode(['ok' => false, 'message' => 'Δεν έχει οριστεί API key']);
    exit;
}

// Test call: geocode Heraklion (cheap, fast, no quota impact vs. full forecast)
$url = 'https://api.openweathermap.org/geo/1.0/direct?q=Heraklion,GR&limit=1&appid=' . urlencode($apiKey);

if (!function_exists('curl_init')) {
    echo json_encode(['ok' => false, 'message' => 'Η επέκταση cURL δεν είναι διαθέσιμη στον server']);
    exit;
}

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 8,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_USERAGENT      => 'VolunteerOps/' . APP_VERSION,
    CURLOPT_FOLLOWLOCATION => false,
]);
$response  = curl_exec($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($response === false || $curlError) {
    echo json_encode(['ok' => false, 'message' => 'Σφάλμα δικτύου: ' . $curlError]);
    exit;
}

if ($httpCode === 401) {
    echo json_encode(['ok' => false, 'message' => 'Μη έγκυρο API key (HTTP 401) — ελέγξτε ότι το key είναι σωστό και ενεργό']);
    exit;
}

if ($httpCode === 429) {
    echo json_encode(['ok' => false, 'message' => 'Υπέρβαση ορίου κλήσεων OpenWeatherMap (HTTP 429)']);
    exit;
}

if ($httpCode !== 200) {
    echo json_encode(['ok' => false, 'message' => 'Σφάλμα OpenWeatherMap API (HTTP ' . $httpCode . ')']);
    exit;
}

$data = json_decode($response, true);
if (!is_array($data)) {
    echo json_encode(['ok' => false, 'message' => 'Μη αναγνώσιμη απόκριση από OpenWeatherMap']);
    exit;
}

echo json_encode(['ok' => true, 'message' => 'Το API key λειτουργεί κανονικά ✓']);
