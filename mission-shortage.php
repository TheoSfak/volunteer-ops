<?php
/**
 * VolunteerOps - Mission Shortage Report Admin Action Endpoint
 * War Room: any admin/responsible user can mark a shortage report "Είδα"
 * (seen) then "Λύθηκε" (resolved) — a single ticket, no fixed recipient
 * roster (unlike mission-order.php's per-recipient acknowledge/complete),
 * so authorization here is an explicit role check, not row ownership.
 * POST only, AJAX.
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
$reportId = (int) post('report_id');

$report = dbFetchOne(
    "SELECT r.id, r.mission_id, r.reporter_id, r.team_id, r.title, r.acknowledged_at, r.resolved_at, r.not_resolved_at,
            m.title AS mission_title, m.responsible_user_id, mt.codename, mt.team_number
     FROM mission_shortage_reports r
     JOIN missions m ON m.id = r.mission_id
     LEFT JOIN mission_teams mt ON mt.id = r.team_id
     WHERE r.id = ?",
    [$reportId]
);
if (!$report) {
    echo json_encode(['ok' => false, 'error' => t('shortage.report_not_found')]);
    exit;
}

$canManageWarRoom = canManageActionRoom($report['responsible_user_id'] ? (int) $report['responsible_user_id'] : null, (int) $userId);
if (!$canManageWarRoom) {
    echo json_encode(['ok' => false, 'error' => t('shortage.no_manage_permission')]);
    exit;
}

// Notify whoever the report actually concerns when the admin acts on it —
// the reporter's team if they had one at submit time, or just the reporter
// themselves if they didn't (there's no wider "team" to loop in). Excludes
// the acting admin in case they happen to be a member of that same team.
function notifyShortageAffectedUsers(array $report, string $titleKey, string $messageKey, string $notifCode, int $actingUserId, string $note = ''): void {
    if ($report['team_id']) {
        $recipientIds = array_map('intval', array_column(
            dbFetchAll("SELECT user_id FROM mission_team_members WHERE team_id = ?", [(int) $report['team_id']]),
            'user_id'
        ));
    } else {
        $recipientIds = [(int) $report['reporter_id']];
    }
    $recipientIds = array_values(array_unique(array_diff($recipientIds, [$actingUserId])));
    if (!$recipientIds) {
        return;
    }

    $teamLabel = $report['team_id'] ? ($report['codename'] . ' ' . $report['team_number']) : t('history.no_team_capitalized');
    $warRoomUrl = rtrim(BASE_URL, '/') . '/war-room.php?id=' . $report['mission_id'];
    $langs = getUserLanguages($recipientIds);
    foreach ($recipientIds as $recipientId) {
        $lang = $langs[$recipientId] ?? DEFAULT_LANGUAGE;
        $notifTitle = t($titleKey, ['mission' => $report['mission_title']], $lang);
        $notifMessage = t($messageKey, ['title' => $report['title'], 'team' => $teamLabel], $lang);
        // Free-form admin note is never translated — shown exactly as typed,
        // same "free text stays as typed" rule as order/task broadcasts.
        if ($note !== '') {
            $notifMessage .= ' ' . t('shortage.note_suffix', ['note' => $note], $lang);
        }
        sendNotification($recipientId, $notifTitle, $notifMessage, 'success', $notifCode, [
            'url' => $warRoomUrl,
            'tag' => $notifCode . '-' . $report['id'],
            'bannerMission' => $report['mission_id'],
        ]);
    }
}

if ($action === 'seen') {
    if (!$report['acknowledged_at']) {
        dbExecute("UPDATE mission_shortage_reports SET acknowledged_at = NOW(), acknowledged_by = ? WHERE id = ?", [$userId, $reportId]);
        logAudit('acknowledge_shortage_report', 'mission_shortage_reports', $reportId, null, ['mission_id' => $report['mission_id']]);
        notifyShortageAffectedUsers($report, 'shortage.seen_notify_title', 'shortage.seen_notify_message', 'mission_shortage_seen', $userId);
    }
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'resolve') {
    if (!$report['resolved_at'] && !$report['not_resolved_at']) {
        $note = trim((string) post('note'));
        dbExecute(
            "UPDATE mission_shortage_reports
             SET acknowledged_at = COALESCE(acknowledged_at, NOW()), acknowledged_by = COALESCE(acknowledged_by, ?),
                 resolved_at = NOW(), resolved_by = ?, outcome_note = ?
             WHERE id = ?",
            [$userId, $userId, $note ?: null, $reportId]
        );
        logAudit('resolve_shortage_report', 'mission_shortage_reports', $reportId, null, ['mission_id' => $report['mission_id']]);
        notifyShortageAffectedUsers(
            $report, 'shortage.resolved_notify_title', 'shortage.resolved_notify_message',
            'mission_shortage_resolved', $userId, $note
        );
    }
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'not_resolved') {
    if (!$report['resolved_at'] && !$report['not_resolved_at']) {
        $note = trim((string) post('note'));
        dbExecute(
            "UPDATE mission_shortage_reports
             SET acknowledged_at = COALESCE(acknowledged_at, NOW()), acknowledged_by = COALESCE(acknowledged_by, ?),
                 not_resolved_at = NOW(), not_resolved_by = ?, outcome_note = ?
             WHERE id = ?",
            [$userId, $userId, $note ?: null, $reportId]
        );
        logAudit('not_resolve_shortage_report', 'mission_shortage_reports', $reportId, null, ['mission_id' => $report['mission_id']]);
        notifyShortageAffectedUsers(
            $report, 'shortage.not_resolved_notify_title', 'shortage.not_resolved_notify_message',
            'mission_shortage_not_resolved', $userId, $note
        );
    }
    echo json_encode(['ok' => true]);
    exit;
}

echo json_encode(['ok' => false, 'error' => t('common.unknown_action')]);
