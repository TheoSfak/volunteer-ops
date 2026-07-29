<?php
/**
 * VolunteerOps - Mission Incident Report Admin Action Endpoint
 * War Room: any admin/responsible user can mark an incident report "Είδα"
 * (seen) then close it with an outcome — same two-step shape as
 * mission-shortage.php, just with a fixed outcome enum instead of free-text
 * resolved/not_resolved. POST only, AJAX. Reporting a new incident happens
 * inline in war-room.php's own POST handler (action=report_incident),
 * mirroring how report_shortage works there — this file only ever
 * acknowledges/resolves an existing row.
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
$incidentId = (int) post('incident_id');

$incident = dbFetchOne(
    "SELECT i.id, i.mission_id, i.reporter_id, i.team_id, i.incident_type, i.acknowledged_at, i.resolved_at,
            m.title AS mission_title, m.responsible_user_id, mt.codename, mt.team_number
     FROM mission_incidents i
     JOIN missions m ON m.id = i.mission_id
     LEFT JOIN mission_teams mt ON mt.id = i.team_id
     WHERE i.id = ?",
    [$incidentId]
);
if (!$incident) {
    echo json_encode(['ok' => false, 'error' => t('incident.report_not_found')]);
    exit;
}

$canManageWarRoom = canManageActionRoom($incident['responsible_user_id'] ? (int) $incident['responsible_user_id'] : null, (int) $userId);
if (!$canManageWarRoom) {
    echo json_encode(['ok' => false, 'error' => t('incident.no_manage_permission')]);
    exit;
}

// Notify whoever the report actually concerns when the admin acts on it — same
// recipient resolution as mission-shortage.php's notifyShortageAffectedUsers().
function notifyIncidentAffectedUsers(array $incident, string $titleKey, string $messageKey, string $notifCode, int $actingUserId): void {
    if ($incident['team_id']) {
        $recipientIds = array_map('intval', array_column(
            dbFetchAll("SELECT user_id FROM mission_team_members WHERE team_id = ?", [(int) $incident['team_id']]),
            'user_id'
        ));
    } else {
        $recipientIds = [(int) $incident['reporter_id']];
    }
    $recipientIds = array_values(array_unique(array_diff($recipientIds, [$actingUserId])));
    if (!$recipientIds) {
        return;
    }

    $teamLabel = $incident['team_id'] ? teamLabel($incident['codename'], $incident['team_number']) : t('history.no_team_capitalized');
    $warRoomUrl = rtrim(BASE_URL, '/') . '/war-room.php?id=' . $incident['mission_id'];
    $langs = getUserLanguages($recipientIds);
    foreach ($recipientIds as $recipientId) {
        $lang = $langs[$recipientId] ?? DEFAULT_LANGUAGE;
        $notifTitle = t($titleKey, ['mission' => $incident['mission_title']], $lang);
        $notifMessage = t($messageKey, ['type' => incidentTypeLabel($incident['incident_type'], $lang), 'team' => $teamLabel], $lang);
        sendNotification($recipientId, $notifTitle, $notifMessage, 'success', $notifCode, [
            'url' => $warRoomUrl,
            'tag' => $notifCode . '-' . $incident['id'],
            'bannerMission' => $incident['mission_id'],
        ]);
    }
}

if ($action === 'seen') {
    if (!$incident['acknowledged_at']) {
        dbExecute("UPDATE mission_incidents SET acknowledged_at = NOW(), acknowledged_by = ? WHERE id = ?", [$userId, $incidentId]);
        logAudit('acknowledge_mission_incident', 'mission_incidents', $incidentId, null, ['mission_id' => $incident['mission_id']]);
        notifyIncidentAffectedUsers($incident, 'incident.seen_notify_title', 'incident.seen_notify_message', 'mission_incident_seen', $userId);
    }
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
        notifyIncidentAffectedUsers($incident, 'incident.resolved_notify_title', 'incident.resolved_notify_message', 'mission_incident_resolved', $userId);
    }
    echo json_encode(['ok' => true]);
    exit;
}

echo json_encode(['ok' => false, 'error' => t('common.unknown_action')]);
