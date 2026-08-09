<?php
/**
 * AJAX endpoint — test the stored NASA FIRMS MAP_KEY.
 * POST only, admin access required.
 * Returns JSON: { ok: bool, message: string }
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/wildfire.php';
requireRole([ROLE_SYSTEM_ADMIN]);

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'message' => 'Μη έγκυρη μέθοδος']);
    exit;
}

if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string) $_POST['csrf_token'])) {
    echo json_encode(['ok' => false, 'message' => 'Μη έγκυρο αίτημα']);
    exit;
}

$apiKey = trim(getSetting('nasa_firms_api_key', ''));
if (empty($apiKey)) {
    echo json_encode(['ok' => false, 'message' => 'Δεν έχει οριστεί MAP_KEY']);
    exit;
}

// Cheapest possible real call: tiny 1x1 degree box around Heraklion,
// day_range=1, a single VIIRS source — just enough to prove the key works,
// same reasoning as api-weather-test.php's geocoding-only test call.
$errorMessage = '';
$rows = _firmsFetchArea($apiKey, 'VIIRS_SNPP_NRT', '25.1,34.9,25.6,35.4', $errorMessage);

if ($rows === null) {
    $detail = $errorMessage ? ' (' . $errorMessage . ')' : '';
    echo json_encode([
        'ok'      => false,
        'message' => 'Το MAP_KEY δεν λειτούργησε' . $detail,
    ]);
    exit;
}

echo json_encode(['ok' => true, 'message' => 'Το MAP_KEY λειτουργεί κανονικά ✓']);
