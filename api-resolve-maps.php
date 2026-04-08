<?php
/**
 * AJAX endpoint — resolve a Google Maps short link and extract coordinates.
 * POST only, login required.
 * Returns JSON: { lat, lng } on success or { error } on failure.
 */
require_once __DIR__ . '/bootstrap.php';
requireLogin();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'Μη έγκυρη μέθοδος']);
    exit;
}

$url = trim($_POST['url'] ?? '');
if (empty($url)) {
    echo json_encode(['error' => 'Δεν δόθηκε σύνδεσμος']);
    exit;
}

// Basic sanity — only allow google maps domains
if (!preg_match('#^https?://(maps\.app\.goo\.gl|goo\.gl|www\.google\.com/maps|maps\.google\.com)#i', $url)) {
    echo json_encode(['error' => 'Μη αποδεκτός σύνδεσμος']);
    exit;
}

if (!function_exists('curl_init')) {
    echo json_encode(['error' => 'cURL δεν είναι διαθέσιμο']);
    exit;
}

// Follow redirects to get the full expanded URL
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 5,
    CURLOPT_TIMEOUT        => 8,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; VolunteerOps/' . APP_VERSION . ')',
    // We only need the final URL, not the body
    CURLOPT_NOBODY         => false,
    CURLOPT_HEADER         => false,
]);

curl_exec($ch);
$finalUrl  = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError || empty($finalUrl)) {
    echo json_encode(['error' => 'Αποτυχία επεξεργασίας συνδέσμου: ' . $curlError]);
    exit;
}

// Extract coordinates from the resolved URL — !3d<lat>!4d<lng> takes priority
$lat = null;
$lng = null;

// Priority 1: exact pin (!3d!4d)
if (preg_match('/!3d(-?\d+\.?\d*)!4d(-?\d+\.?\d*)/', $finalUrl, $m)) {
    $lat = $m[1];
    $lng = $m[2];

// Priority 2: ?q=lat,lng
} elseif (preg_match('/[?&]q=(-?\d+\.\d+),(-?\d+\.\d+)/', $finalUrl, $m)) {
    $lat = $m[1];
    $lng = $m[2];

// Priority 3: ll=lat,lng
} elseif (preg_match('/[?&]ll=(-?\d+\.\d+),(-?\d+\.\d+)/', $finalUrl, $m)) {
    $lat = $m[1];
    $lng = $m[2];

// Priority 4 (fallback): @lat,lng — map view center
} elseif (preg_match('/@(-?\d+\.\d+),(-?\d+\.\d+)/', $finalUrl, $m)) {
    $lat = $m[1];
    $lng = $m[2];
}

if ($lat === null || $lng === null) {
    echo json_encode(['error' => 'Δεν βρέθηκαν συντεταγμένα στον σύνδεσμο. Χρησιμοποιήστε τον κανονικό σύνδεσμο από Google Maps.']);
    exit;
}

echo json_encode(['lat' => $lat, 'lng' => $lng]);
