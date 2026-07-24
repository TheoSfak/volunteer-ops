<?php
/**
 * VolunteerOps - Volunteer GPS Ping Endpoint
 * Εθελοντής στέλνει θέση GPS κατά τη διάρκεια βάρδιας.
 * AJAX POST only.
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();

header('Content-Type: application/json');

/**
 * Notify command staff that a volunteer sent their GPS location — mirrors
 * mission-dispatch.php's notifyDispatchReceive()/notifyDispatchArrival()
 * shape (own notification code, bannerMission for the loud scrolling
 * banner + sound, getMissionCommandStaffIds() for recipients). Fires on
 * every ping regardless of whether it was requested via a War Room order —
 * request fulfillment is already tracked separately
 * (mission_order_recipients.fulfilled_at) for the response-time report;
 * this is the live "someone just sent their location" alert.
 */
function notifyVolunteerGpsPing(int $missionId, string $missionTitle, ?int $responsibleUserId, string $senderName, int $senderId): void {
    $warRoomUrl = rtrim(BASE_URL, '/') . '/war-room.php?id=' . $missionId;
    $recipientIds = getMissionCommandStaffIds($missionId, $responsibleUserId, $senderId);
    $langByUserId = getUserLanguages($recipientIds);
    foreach ($recipientIds as $recipientId) {
        $lang = $langByUserId[$recipientId] ?? DEFAULT_LANGUAGE;
        sendNotification(
            $recipientId,
            t('ping.notify_title', [], $lang),
            t('ping.notify_message', ['name' => $senderName, 'mission' => $missionTitle], $lang),
            'info', 'mission_gps_ping', [
                'url' => $warRoomUrl,
                'tag' => 'gps-ping-mission-' . $missionId,
                'bannerMission' => $missionId,
            ]
        );
    }
}

if (!isPost()) {
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

// AJAX-safe CSRF check (verifyCsrf() redirects on failure which breaks fetch)
if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string) $_POST['csrf_token'])) {
    echo json_encode(['ok' => false, 'error' => t('common.invalid_request')]);
    exit;
}

$userId   = getCurrentUserId();
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

// Validate coordinates
if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || ($lat == 0 && $lng == 0)) {
    echo json_encode(['ok' => false, 'error' => t('ping.invalid_coordinates')]);
    exit;
}

// Verify user has an APPROVED participation for this shift
$pr = dbFetchOne(
    "SELECT pr.id, s.mission_id, m.title AS mission_title, m.responsible_user_id FROM participation_requests pr
     JOIN shifts s ON pr.shift_id = s.id
     JOIN missions m ON s.mission_id = m.id
     WHERE pr.shift_id = ? AND pr.volunteer_id = ? AND pr.status = '" . PARTICIPATION_APPROVED . "'
       AND m.status = '" . STATUS_OPEN . "' AND m.show_in_ops = 1 AND m.deleted_at IS NULL",
    [$shiftId, $userId]
);

if (!$pr) {
    echo json_encode(['ok' => false, 'error' => t('ping.mission_not_open_or_not_approved')]);
    exit;
}

// Insert ping
try {
    dbInsert(
        "INSERT INTO volunteer_pings (user_id, shift_id, lat, lng, accuracy_meters, source, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())",
        [$userId, $shiftId, $lat, $lng, $accuracy, $source]
    );
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => t('ping.gps_unavailable_migration')]);
    exit;
}

// Auto-captured pings (passive, every few minutes while Action Room is open)
// stay quiet — only a manual tap should trigger the loud command-staff alert.
if ($source !== 'auto') {
    notifyVolunteerGpsPing(
        (int) $pr['mission_id'],
        $pr['mission_title'],
        $pr['responsible_user_id'] ? (int) $pr['responsible_user_id'] : null,
        $user['name'],
        $userId
    );
}

// Auto-fulfill any outstanding War Room "send your location" orders for this user.
try {
    dbExecute(
        "UPDATE mission_order_recipients r
         JOIN mission_orders o ON o.id = r.order_id
         SET r.fulfilled_at = NOW()
         WHERE r.user_id = ? AND o.mission_id = ? AND o.order_type = 'location' AND r.fulfilled_at IS NULL",
        [$userId, $pr['mission_id']]
    );
} catch (Exception $e) {
    // Non-critical — the ping itself already succeeded.
}

echo json_encode(['ok' => true, 'ts' => date('H:i:s')]);
