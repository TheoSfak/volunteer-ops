<?php
/**
 * VolunteerOps - Mission Incident Report Admin Action Endpoint
 * War Room: any admin/responsible user can mark an incident report "Είδα"
 * (seen) then close it with an outcome — same two-step shape as
 * mission-shortage.php, just with a fixed outcome enum instead of free-text
 * resolved/not_resolved. POST only, AJAX. A volunteer's report from the field
 * form is handled inline in war-room.php's own POST handler
 * (action=report_incident), mirroring how report_shortage works there.
 * Command's «Νέο συμβάν εδώ» on the live map posts here instead (action
 * report, v3.344.0), because it needs the new incident's id back to send a
 * team to it without reloading the page. Both go through
 * reportMissionIncident().
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

$action = post('action');

if ($action === 'report') {
    $missionId = (int) post('mission_id');
    $mission = dbFetchOne(
        "SELECT id, title, status, show_in_ops, responsible_user_id FROM missions WHERE id = ? AND deleted_at IS NULL",
        [$missionId]
    );
    if (!$mission || $mission['status'] !== STATUS_OPEN || empty($mission['show_in_ops'])) {
        echo json_encode(['ok' => false, 'error' => t('common.mission_not_found_or_inactive')]);
        exit;
    }
    if (!canManageActionRoom($mission['responsible_user_id'] ? (int) $mission['responsible_user_id'] : null, (int) $userId)) {
        echo json_encode(['ok' => false, 'error' => t('incident.no_manage_permission')]);
        exit;
    }
    $result = reportMissionIncident($mission, getCurrentUser(), $_POST, true);
    unset($result['level']);
    echo json_encode($result);
    exit;
}

$incidentId = (int) post('incident_id');
$incident = loadIncidentForAction($incidentId);
if (!$incident) {
    echo json_encode(['ok' => false, 'error' => t('incident.report_not_found')]);
    exit;
}

$canManageWarRoom = canManageActionRoom($incident['responsible_user_id'] ? (int) $incident['responsible_user_id'] : null, (int) $userId);
if (!$canManageWarRoom) {
    echo json_encode(['ok' => false, 'error' => t('incident.no_manage_permission')]);
    exit;
}

if ($action === 'seen') {
    markIncidentSeen($incident, (int) $userId);
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'resolve') {
    if (!$incident['resolved_at']) {
        $outcome = post('outcome');
        $allowedOutcomes = array_keys(INCIDENT_OUTCOME_LABELS);
        if (!in_array($outcome, $allowedOutcomes, true)) {
            echo json_encode(['ok' => false, 'error' => t('incident.invalid_outcome')]);
            exit;
        }
        $outcomeLocation = $outcome === 'transported' ? mb_substr(trim((string) post('outcome_location')), 0, 255) : null;
        dbExecute(
            "UPDATE mission_incidents
             SET acknowledged_at = COALESCE(acknowledged_at, NOW()), acknowledged_by = COALESCE(acknowledged_by, ?),
                 resolved_at = NOW(), resolved_by = ?, outcome = ?, outcome_location = ?
             WHERE id = ?",
            [$userId, $userId, $outcome, $outcomeLocation ?: null, $incidentId]
        );
        logAudit('resolve_mission_incident', 'mission_incidents', $incidentId, null, ['mission_id' => $incident['mission_id'], 'outcome' => $outcome]);
        // The teams still on their way to it hear the outcome; the reporter's
        // team hears that it closed — once each, the first message winning
        // for anyone who is both.
        $told = notifyIncidentClosedToResponders(['outcome' => $outcome, 'outcome_location' => $outcomeLocation] + $incident, (int) $userId);
        notifyIncidentAffectedUsers($incident, 'incident.resolved_notify_title', 'incident.resolved_notify_message', 'mission_incident_resolved', $userId, $told);
    }
    echo json_encode(['ok' => true]);
    exit;
}

echo json_encode(['ok' => false, 'error' => t('common.unknown_action')]);
