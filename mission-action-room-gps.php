<?php
/**
 * VolunteerOps - Action Room GPS Participation Endpoint
 * The switch beside each name on the Action Room's participants card: ticks
 * or unticks one volunteer as an Action Room participant (position tracked,
 * reachable by orders, counted in the operational report). The team forms in
 * war-room.php set the same flag for a whole team at once; this is the
 * mid-operation one — a phone dies and the crew hands it to somebody else,
 * and that must be one tap rather than an edit of the team.
 *
 * Reaches people the team forms cannot: an approved volunteer on no team at
 * all, and every volunteer on a mission where no teams were ever created.
 * POST only, AJAX, admin-only.
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

$missionId    = (int) post('mission_id');
$targetUserId = (int) post('user_id');
// The client posts the state it WANTS, never "flip it". Two taps racing from
// a phone on a bad connection then agree on an answer instead of cancelling
// each other out, and a retry of a request whose reply was lost is harmless.
$takesPart    = post('takes_part') === '1';

$mission = dbFetchOne(
    "SELECT id, title, status, show_in_ops, responsible_user_id FROM missions WHERE id = ? AND deleted_at IS NULL",
    [$missionId]
);
if (!$mission || $mission['status'] !== STATUS_OPEN || empty($mission['show_in_ops'])) {
    echo json_encode(['ok' => false, 'error' => t('common.mission_not_found_or_inactive')]);
    exit;
}

if (!canManageActionRoom($mission['responsible_user_id'] ? (int) $mission['responsible_user_id'] : null, (int) $userId)) {
    echo json_encode(['ok' => false, 'error' => t('gps_participant.no_manage_permission')]);
    exit;
}

// Only somebody approved on this mission can be ticked. Same re-check every
// other admin action here does: the switch only renders for people already on
// the roster, but that gate lives in a page this endpoint does not run.
$target = dbFetchOne(
    "SELECT u.id, u.name
     FROM participation_requests pr
     JOIN shifts s ON s.id = pr.shift_id
     JOIN users u ON u.id = pr.volunteer_id
     WHERE s.mission_id = ? AND pr.volunteer_id = ? AND pr.status = ?
     LIMIT 1",
    [$missionId, $targetUserId, PARTICIPATION_APPROVED]
);
if (!$target) {
    echo json_encode(['ok' => false, 'error' => t('gps_participant.recipient_not_found')]);
    exit;
}

$changed = setActionRoomParticipation($missionId, $targetUserId, $takesPart, (int) $userId);
if ($changed) {
    logAudit(
        $takesPart ? 'action_room_gps_on' : 'action_room_gps_off',
        'missions',
        $missionId,
        null,
        ['user_id' => $targetUserId, 'name' => $target['name']]
    );
}

echo json_encode([
    'ok'         => true,
    'takes_part' => $takesPart,
    'name'       => $target['name'],
    // How many people are on duty right now with the tick — the participants
    // card uses it to raise (or clear) its "nobody is carrying this operation"
    // warning without a second round trip.
    'active_participant_count' => (int) dbFetchValue(
        "SELECT COUNT(DISTINCT pr.volunteer_id)
         FROM participation_requests pr
         JOIN shifts s ON s.id = pr.shift_id
         JOIN mission_action_room_participants arp
              ON arp.mission_id = s.mission_id AND arp.user_id = pr.volunteer_id
         WHERE s.mission_id = ? AND pr.status = ?
           AND s.start_time <= NOW() AND s.end_time > NOW()",
        [$missionId, PARTICIPATION_APPROVED]
    ),
]);
