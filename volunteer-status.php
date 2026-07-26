<?php
/**
 * VolunteerOps - Volunteer Field Status Endpoint
 * Εθελοντής ενημερώνει κατάσταση: Σε Κίνηση / Επί Τόπου / Χρειάζεται Βοήθεια.
 * AJAX POST only.
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

$userId  = getCurrentUserId();
$prId    = (int) post('pr_id');
$status  = post('status');

// Optional GPS (only ever sent alongside 'needs_help' — the SOS button) — same
// nullable-if-not-numeric idiom as mission-photo.php's optional lat/lng capture.
$latRaw = post('lat');
$lngRaw = post('lng');
$lat = ($latRaw !== '' && $latRaw !== null && is_numeric($latRaw)) ? (float) $latRaw : null;
$lng = ($lngRaw !== '' && $lngRaw !== null && is_numeric($lngRaw)) ? (float) $lngRaw : null;

// The War Room offline queue replays this endpoint, so a status change (and
// especially an SOS) that only reaches the server once signal returns must be
// recorded at the time the volunteer actually tapped it, not on arrival. Same
// rules and same plausibility window as mission-route.php — see
// resolveEventTimestamp() in includes/functions.php.
[$eventTs, $reportedAtTs] = resolveEventTimestamp();

$allowedStatuses = ['on_way', 'on_site', 'needs_help'];
if (!in_array($status, $allowedStatuses, true)) {
    echo json_encode(['ok' => false, 'error' => t('status.invalid_status')]);
    exit;
}

// Verify the PR belongs to this user and is APPROVED
$pr = dbFetchOne(
    "SELECT pr.id, pr.shift_id, m.title as mission_title, m.id as mission_id, m.responsible_user_id
     FROM participation_requests pr
     JOIN shifts s ON pr.shift_id = s.id
     JOIN missions m ON s.mission_id = m.id
     WHERE pr.id = ? AND pr.volunteer_id = ? AND pr.status = '" . PARTICIPATION_APPROVED . "'
       AND m.status = '" . STATUS_OPEN . "' AND m.show_in_ops = 1 AND m.deleted_at IS NULL",
    [$prId, $userId]
);

if (!$pr) {
    echo json_encode(['ok' => false, 'error' => t('status.request_not_found')]);
    exit;
}

// Update field status
try {
    dbExecute(
        "UPDATE participation_requests SET field_status = ?, field_status_updated_at = ? WHERE id = ?",
        [$status, $eventTs, $prId]
    );
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => t('status.feature_unavailable_migration')]);
    exit;
}

// Audit trail for every field-status change (War Room Activity feed source).
// 'needs_help' keeps its pre-existing action string unchanged; on_way/on_site
// are new — previously only needs_help was ever logged here.
$auditActionByStatus = [
    'on_way' => 'field_status_on_way', 'on_site' => 'field_status_on_site', 'needs_help' => 'needs_help',
];
// A delayed offline replay is recorded at its true field time above, which
// would otherwise make it indistinguishable from a normal live tap — the raw
// client claim and the actual arrival time go in the audit notes so the
// delay stays visible after the fact. Nothing is added on the live path.
//
// 5 seconds, not a minute: on the live path the client stamps reported_at
// immediately before the fetch (after any GPS wait, not before it), so the
// gap there is sub-second. A minute-wide threshold quietly failed to mark
// real queued replays — confirmed live with an SOS that sat in the queue for
// 54s and logged as if it had been a normal tap.
$auditNotes = ($reportedAtTs !== $eventTs || strtotime($eventTs) < time() - 5)
    ? ['reported_at' => $reportedAtTs, 'received_at' => date('Y-m-d H:i:s'), 'offline_replay' => true]
    : null;
logAudit($auditActionByStatus[$status], 'participation_requests', $prId, $auditNotes);

// If needs_help → this IS the SOS button: open (or refresh) a War Room SOS
// alert ticket and alert command staff. A duplicate-open-alert guard stops
// repeated taps from spamming a fresh row + notification every time; if one
// is already open we just refresh its coordinates (the volunteer may be
// moving) and leave the existing ticket/notification alone.
if ($status === 'needs_help') {
    $missionId = (int) $pr['mission_id'];

    // Locking the participation_requests row (which always exists — $pr was
    // already fetched above) rather than the mission_sos_alerts row is the
    // point: two SOS taps for the same pr_id arriving within the same
    // instant (double-tap, or a live tap racing the offline queue's replay
    // of an earlier queued one) previously both ran the SELECT below, both
    // saw "no existing open alert" — a real check-then-insert race, since
    // there's no row to lock when none exists yet — and both inserted a
    // duplicate ticket with a duplicate siren/push to command staff.
    // Locking pr_id serializes any second concurrent request behind the
    // first one's commit, so it correctly sees the alert the first request
    // just created.
    db()->beginTransaction();
    try {
        dbFetchOne("SELECT id FROM participation_requests WHERE id = ? FOR UPDATE", [$prId]);

        $existingAlertId = dbFetchValue(
            "SELECT id FROM mission_sos_alerts WHERE pr_id = ? AND resolved_at IS NULL",
            [$prId]
        );

        if ($existingAlertId) {
            dbExecute("UPDATE mission_sos_alerts SET lat = ?, lng = ? WHERE id = ?", [$lat, $lng, (int) $existingAlertId]);
        } else {
            // created_at is the resolved field time, not NOW(): an SOS queued
            // offline and replayed 20 minutes later must show command staff when
            // the volunteer actually called for help. It still surfaces as a new
            // unacknowledged alert (siren + push fire below regardless), it just
            // sorts and reads by its true time.
            dbInsert(
                "INSERT INTO mission_sos_alerts (mission_id, user_id, pr_id, team_id, lat, lng, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)",
                [$missionId, $userId, $prId, getUserTeamIdForMission($missionId, $userId), $lat, $lng, $eventTs]
            );
        }
        db()->commit();
    } catch (Exception $e) {
        db()->rollBack();
        echo json_encode(['ok' => false, 'error' => t('common.failed')]);
        exit;
    }

    if (!$existingAlertId) {
        $currentUser = getCurrentUser();
        $teamId = getUserTeamIdForMission($missionId, $userId);
        $myTeam = $teamId ? dbFetchOne("SELECT codename, team_number FROM mission_teams WHERE id = ?", [$teamId]) : null;
        $teamLabel = $myTeam ? teamLabel($myTeam['codename'], $myTeam['team_number']) : t('status.no_team_label');

        $recipientIds = getMissionCommandStaffIds(
            $missionId,
            $pr['responsible_user_id'] ? (int) $pr['responsible_user_id'] : null,
            $userId
        );
        $warRoomUrl = rtrim(BASE_URL, '/') . '/war-room.php?id=' . $missionId;
        $langByUserId = getUserLanguages($recipientIds);
        foreach ($recipientIds as $recipientId) {
            $lang = $langByUserId[$recipientId] ?? DEFAULT_LANGUAGE;
            $notifyTitle = t('sos.notify_title', ['team' => mb_strtoupper($teamLabel, 'UTF-8')], $lang);
            $notifyMessage = t('sos.notify_message', ['name' => h($currentUser['name']), 'team' => h($teamLabel), 'mission' => $pr['mission_title']], $lang);
            // Mandatory (empty code) — same convention as orders/global-message/
            // high-severity shortage reports, so an SOS can never be silently muted.
            sendNotification($recipientId, $notifyTitle, $notifyMessage, 'danger', '', [
                'url'     => $warRoomUrl,
                'tag'     => 'sos-alert-mission-' . $missionId,
                'vibrate' => [300, 100, 300, 100, 500, 100, 500],
            ]);
        }
    }
}

$labels = [
    'on_way'      => t('status.self_on_way'),
    'on_site'     => t('status.self_on_site'),
    'needs_help'  => t('status.label_needs_help'),
];

echo json_encode(['ok' => true, 'label' => $labels[$status], 'status' => $status]);
