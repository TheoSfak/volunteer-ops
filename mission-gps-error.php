<?php
/**
 * VolunteerOps - Action Room GPS failure report
 *
 * The volunteer's own device telling the command post WHY it has stopped
 * producing positions. The browser has always known this — the Geolocation
 * API hands back a PositionError carrying a code — and war-room.php threw it
 * away into `() => {}` on every passive-capture callback. A coordinator
 * watching a pin go quiet could not tell "they have stopped moving" from
 * "their location permission is off and no position will ever arrive", and
 * those call for completely different decisions.
 *
 * Reports about the SENDER only — there is no target user id, by design.
 * Anybody who could report a failure on somebody else's behalf could paint a
 * red warning across a roster they do not run.
 *
 * Nothing here is cleared by this endpoint: recordVolunteerPing() drops the
 * flag the moment a fix does arrive, so a recovery reported by a different
 * client (the native service, a reopened tab) still clears it.
 *
 * POST only, AJAX, session + CSRF like ping-location.php beside it.
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();

header('Content-Type: application/json');

$userId = getCurrentUserId();

if (!isPost()) {
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string) $_POST['csrf_token'])) {
    echo json_encode(['ok' => false, 'error' => t('common.invalid_request')]);
    exit;
}

$missionId = (int) post('mission_id');
// Straight off the PositionError. Not validated against the known set here —
// volunteerGpsErrorFromCode() maps anything unrecognised to 'unknown', which
// is the honest answer and still more use to a coordinator than silence.
$code = (int) post('code');

$mission = dbFetchOne(
    "SELECT id, status, show_in_ops FROM missions WHERE id = ? AND deleted_at IS NULL",
    [$missionId]
);
if (!$mission || $mission['status'] !== STATUS_OPEN || empty($mission['show_in_ops'])) {
    echo json_encode(['ok' => false, 'error' => t('common.mission_not_found_or_inactive')]);
    exit;
}

// Same gate the ping write path applies, for the same reason: somebody who is
// not taking part in this Action Room is not being tracked, so they have no
// GPS failure worth recording — and a warning on their roster line would
// contradict the blank row the page deliberately renders for them.
//
// One phone, one stream — the same rule recordVolunteerPing() applies to
// positions. Inside the Android app the page runs its own capture beside the
// native background service; while the service is delivering fixes, the page's
// failures describe a receiver nobody is listening to. Recording them made the
// roster flash an error every cadence that the next native fix then cleared,
// on a volunteer whose position was arriving perfectly well.
if (volunteerHasRecentNativeFix($missionId, (int) $userId)) {
    echo json_encode(['ok' => true, 'skipped' => 'native_active']);
    exit;
}

// A `reason` by name is what the Android app's page-side check sends for the
// things no PositionError can express (location switched off, battery saver
// cutting GPS, approximate-only permission). Only the client-reportable set is
// accepted by name; anything else falls back to the numeric code.
$reason = (string) post('reason');
$recorded = in_array($reason, VOLUNTEER_GPS_CLIENT_REASONS, true)
    ? recordVolunteerGpsErrorReason($missionId, (int) $userId, $reason)
    : recordVolunteerGpsError($missionId, (int) $userId, $code);

echo json_encode(['ok' => $recorded]);
