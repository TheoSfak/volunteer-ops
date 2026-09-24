<?php
/**
 * VolunteerOps - Mass-casualty triage endpoint (Μαζικό Συμβάν / Διαλογή)
 *
 * POST only, AJAX. Two audiences:
 *
 *   Field (any approved participant of the mission, and command staff —
 *   the mission owner's decision: everybody on the operation can triage):
 *     assess       first triage or re-triage of one casualty
 *     set_card     attach a physical card number to a casualty made without one
 *     merge        "that card is the same person": fold the new record into it
 *     bulk_green   N walking wounded sent to the green area, untagged
 *     details      name / age / phone at the collection point
 *
 *   Command staff:
 *     mci          switch Μαζικό Συμβάν on or off
 *     point        place or clear the collection point / the green area
 *     status       to the collection point, or away in a vehicle
 *
 * assess, set_card and bulk_green are replayed by the Action Room's offline
 * queue, so each is idempotent on an id the phone made (see
 * recordTriageAssessment()) and each takes a reported_at that
 * resolveEventTimestamp() turns into the real field time.
 *
 * A casualty can only be recorded on a mission that has had a Μαζικό Συμβάν,
 * but switching it OFF does not close the door: an assessment made on a phone
 * while it was on and delivered after command switched it off (the offline
 * queue), or finished by a rescuer who was mid-questions at that moment, is
 * still accepted. That casualty exists either way, and refusing it because of
 * when the signal came back would be losing a person. The switch hides the
 * button; it does not reject the people already found.
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();

header('Content-Type: application/json');

$userId = (int) getCurrentUserId();

if (!isPost()) {
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string) $_POST['csrf_token'])) {
    echo json_encode(['ok' => false, 'error' => t('common.invalid_request')]);
    exit;
}

// Nothing below reads or writes the session, and a triage tap must never wait
// on another request from the same phone holding the session lock.
session_write_close();

$action    = post('action');
$missionId = (int) post('mission_id');

$mission = dbFetchOne(
    "SELECT id, title, responsible_user_id FROM missions
     WHERE id = ? AND status = ? AND show_in_ops = 1 AND deleted_at IS NULL",
    [$missionId, STATUS_OPEN]
);
if (!$mission) {
    echo json_encode(['ok' => false, 'error' => t('common.mission_not_found')]);
    exit;
}
$responsibleId = $mission['responsible_user_id'] ? (int) $mission['responsible_user_id'] : null;
$canManageWarRoom = canManageActionRoom($responsibleId, $userId);
$isApprovedParticipant = (bool) dbFetchValue(
    "SELECT COUNT(*) FROM participation_requests pr
     JOIN shifts s ON s.id = pr.shift_id
     WHERE s.mission_id = ? AND pr.volunteer_id = ? AND pr.status = ?",
    [$missionId, $userId, PARTICIPATION_APPROVED]
);

function triageJson(array $payload): void {
    echo json_encode($payload);
    exit;
}

function triageFail(string $key, array $extra = []): void {
    triageJson(array_merge(['ok' => false, 'error' => t($key), 'error_key' => $key], $extra));
}

/** The casualty as the board shows it, for the phone to adopt after a write. */
function triageVictimForResponse(int $missionId, int $victimId): ?array {
    $v = dbFetchOne(
        "SELECT id, victim_uuid, card_no, fallback_code, category, reason, status
         FROM mission_triage_victims WHERE id = ? AND mission_id = ?",
        [$victimId, $missionId]
    );
    if (!$v) {
        return null;
    }
    return [
        'id' => (int) $v['id'],
        'uuid' => $v['victim_uuid'],
        'code' => $v['card_no'] ?? $v['fallback_code'],
        'card_no' => $v['card_no'],
        'fallback_code' => $v['fallback_code'],
        'category' => $v['category'],
        'reason' => triageReasonLabel($v['reason']),
        'status' => $v['status'],
    ];
}

$fieldActions = ['assess', 'set_card', 'merge', 'bulk_green', 'details'];
$commandActions = ['mci', 'point', 'status'];

if (in_array($action, $fieldActions, true)) {
    if (!$isApprovedParticipant && !$canManageWarRoom) {
        triageFail('triage.err_perm');
    }
    // New casualties need a Μαζικό Συμβάν to belong to. Once there has been
    // one, they are accepted even after it is switched off — see the docblock.
    if (in_array($action, ['assess', 'bulk_green'], true) && !loadMissionMci($missionId)) {
        triageFail('triage.err_inactive');
    }
} elseif (in_array($action, $commandActions, true)) {
    if (!$canManageWarRoom) {
        triageFail('triage.err_manage');
    }
} else {
    triageJson(['ok' => false, 'error' => t('common.unknown_action')]);
}

// ── Field ───────────────────────────────────────────────────────────────────
if ($action === 'assess') {
    [$assessedAt, $reportedAt] = resolveEventTimestamp();
    $answers = json_decode((string) post('answers'), true);
    $result = recordTriageAssessment($missionId, $userId, [
        'victim_uuid'     => post('victim_uuid'),
        'assessment_uuid' => post('assessment_uuid'),
        'card_no'         => post('card_no'),
        'fallback_code'   => post('fallback_code'),
        'protocol'        => post('protocol'),
        'answers'         => is_array($answers) ? $answers : [],
        'category'        => post('category'),
        'age_group'       => post('age_group'),
        'lat'             => post('lat'),
        'lng'             => post('lng'),
        'accuracy'        => post('accuracy'),
        'assessed_at'     => $assessedAt,
        'reported_at'     => $reportedAt,
    ]);
    if (!$result['ok']) {
        triageFail($result['error']);
    }

    if (!$result['duplicate']) {
        logAudit('triage_assess', 'mission_triage_victims', $result['victim_id'], null, [
            'mission_id' => $missionId, 'category' => $result['category'], 'previous' => $result['previous_category'],
        ]);
        $victim = triageVictimForResponse($missionId, $result['victim_id']);
        $isFirst = $result['created'] && (int) dbFetchValue("SELECT COUNT(*) FROM mission_triage_victims WHERE mission_id = ?", [$missionId]) === 1;
        $becameSerious = in_array($result['category'], ['red', 'black'], true)
            && $result['previous_category'] !== $result['category'];
        if ($isFirst || $becameSerious) {
            $actorName = (string) dbFetchValue("SELECT name FROM users WHERE id = ?", [$userId]);
            notifyTriageCommand($missionId, $mission['title'], $responsibleId, $userId, $actorName, $victim['code'], $result['category'], $isFirst);
        }
    }
    triageJson([
        'ok' => true,
        'duplicate' => $result['duplicate'],
        'created' => $result['created'],
        'previous_category' => $result['previous_category'],
        'card_conflict' => $result['card_conflict'],
        'victim' => triageVictimForResponse($missionId, $result['victim_id']),
    ]);
}

if ($action === 'set_card') {
    $result = bindTriageCard($missionId, (string) post('victim_uuid'), post('card_no'));
    if (!$result['ok']) {
        triageFail($result['error'], array_intersect_key($result, ['owner' => 1, 'card_no' => 1]) + (
            isset($result['owner']) ? ['owner_label' => triageCategoryLabel($result['owner']['category'])] : []
        ));
    }
    triageJson(['ok' => true, 'victim' => triageVictimForResponse($missionId, $result['victim_id'])]);
}

if ($action === 'merge') {
    $result = mergeTriageVictimIntoCard($missionId, (string) post('victim_uuid'), post('card_no'));
    if (!$result['ok']) {
        triageFail($result['error']);
    }
    logAudit('triage_merge', 'mission_triage_victims', $result['victim_id'], null, ['mission_id' => $missionId]);
    triageJson(['ok' => true, 'victim' => triageVictimForResponse($missionId, $result['victim_id'])]);
}

if ($action === 'bulk_green') {
    [$eventAt] = resolveEventTimestamp();
    $lat = is_numeric(post('lat')) ? (float) post('lat') : null;
    $lng = is_numeric(post('lng')) ? (float) post('lng') : null;
    if ($lat === null || $lng === null) { $lat = null; $lng = null; }
    $result = recordTriageBulkGreen($missionId, $userId, (string) post('client_uuid'), (int) post('count'),
        $lat, $lng, parseAccuracyMeters(post('accuracy'), $lat), $eventAt);
    if (!$result['ok']) {
        triageFail($result['error']);
    }
    triageJson(['ok' => true, 'duplicate' => $result['duplicate']]);
}

if ($action === 'details') {
    $result = setTriageVictimDetails($missionId, (int) post('victim_id'), [
        'patient_name'  => post('patient_name'),
        'estimated_age' => post('estimated_age'),
        'gender'        => post('gender'),
        'phone'         => post('phone'),
        'notes'         => post('notes'),
    ]);
    if (!$result['ok']) {
        triageFail($result['error']);
    }
    logAudit('triage_details', 'mission_triage_victims', (int) post('victim_id'), null, ['mission_id' => $missionId]);
    triageJson(['ok' => true]);
}

// ── Command ─────────────────────────────────────────────────────────────────
if ($action === 'mci') {
    $active = post('active') === '1';
    $changed = setMissionMciActive($missionId, $active, $userId);
    if ($changed) {
        logAudit($active ? 'mci_activate' : 'mci_deactivate', 'missions', $missionId);
        if ($active) {
            notifyMciActivated($missionId, $mission['title'], $responsibleId, $userId);
        }
    }
    triageJson(['ok' => true, 'changed' => $changed, 'triage' => loadTriageStateForMission($missionId, true, $userId)]);
}

if ($action === 'point') {
    $kind = post('kind') === 'green' ? 'green' : 'ccp';
    $lat = is_numeric(post('lat')) ? (float) post('lat') : null;
    $lng = is_numeric(post('lng')) ? (float) post('lng') : null;
    if ($lat === null || $lng === null || abs($lat) > 90 || abs($lng) > 180) {
        $lat = null;
        $lng = null;
    }
    setMissionMciPoint($missionId, $kind, $lat, $lng, $userId);
    logAudit('mci_point', 'missions', $missionId, null, ['kind' => $kind, 'cleared' => $lat === null]);
    triageJson(['ok' => true, 'triage' => loadTriageStateForMission($missionId, true, $userId)]);
}

if ($action === 'status') {
    $result = setTriageVictimStatus($missionId, (int) post('victim_id'), (string) post('status'),
        post('vehicle'), post('destination'), $userId);
    if (!$result['ok']) {
        triageFail($result['error']);
    }
    logAudit('triage_status', 'mission_triage_victims', (int) post('victim_id'), null, ['mission_id' => $missionId, 'status' => post('status')]);
    triageJson(['ok' => true, 'triage' => loadTriageStateForMission($missionId, true, $userId)]);
}
