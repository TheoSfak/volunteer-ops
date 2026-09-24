<?php
/**
 * VolunteerOps - Volunteer GPS Ping Endpoint
 * Εθελοντής στέλνει θέση GPS κατά τη διάρκεια βάρδιας.
 * AJAX POST only. Session + CSRF auth — for the bearer-token-authed twin used
 * by the native Android app's background-location plugin, see
 * mobile-ping-location.php. Both share their core logic via
 * recordVolunteerPing() in includes/functions-warroom.php.
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();

header('Content-Type: application/json');

if (!isPost()) {
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

// AJAX-safe CSRF check (verifyCsrf() redirects on failure which breaks fetch)
if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string) $_POST['csrf_token'])) {
    echo json_encode(['ok' => false, 'error' => t('common.invalid_request')]);
    exit;
}

$user     = getCurrentUser();
$shiftId  = (int) post('shift_id');
$lat      = (float) post('lat');
$lng      = (float) post('lng');
$source   = post('source') === 'auto' ? 'auto' : 'manual';
// Geolocation API accuracy radius in meters — optional (older clients/cached
// pages won't send it), used to keep "is moving" detection honest about how
// precise this particular fix actually was, rather than a fixed distance
// guessing at it. Sanity-capped so a garbage/huge value can't silently
// suppress movement detection entirely.
$rawAccuracy = post('accuracy');
$accuracy = ($rawAccuracy !== null && $rawAccuracy !== '' && is_numeric($rawAccuracy))
    ? min((float) $rawAccuracy, 5000)
    : null;

// Phone battery percentage (0-100 integer) — optional, read via the Battery
// Status API (navigator.getBattery()) where the browser supports it. Unlike
// accuracy's open-ended radius above, 0-100 is a hard bound: an out-of-range
// value can only be garbage, so it's dropped to null rather than clamped —
// clamping could disguise a bug as a false "critical battery" reading on
// what is a safety-relevant signal.
$rawBattery = post('battery_level');
$batteryLevel = ($rawBattery !== null && $rawBattery !== '' && is_numeric($rawBattery) && (int) $rawBattery >= 0 && (int) $rawBattery <= 100)
    ? (int) $rawBattery
    : null;

// How old the fix already was when the page sent it (Date.now() minus the
// fix's own timestamp, both on the phone's clock, so a wrong clock cancels
// out). Absent from pages cached before v3.320.0, which then keep the old
// arrival-time stamping.
$fixAgeMs = parseFixAgeMs(post('fix_age_ms'));
// The Geolocation API's coords.speed (Doppler, m/s) when the phone gives one.
$speedMps = parseSpeedMps(post('speed'));

// 'browser': this endpoint is only ever reached from the live war-room.php
// tab, whose watcher the phone suspends the moment the screen locks. The
// native service posts to mobile-ping-location.php instead.
echo json_encode(recordVolunteerPing($user, $shiftId, $lat, $lng, $accuracy, $batteryLevel, $source, 'browser', $fixAgeMs, false, $speedMps));
