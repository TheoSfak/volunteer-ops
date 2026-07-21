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

if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
    echo json_encode(['ok' => false, 'error' => t('common.invalid_request')]);
    exit;
}

$action = post('action');
$reportId = (int) post('report_id');

$report = dbFetchOne(
    "SELECT r.id, r.mission_id, r.acknowledged_at, r.resolved_at, m.responsible_user_id
     FROM mission_shortage_reports r
     JOIN missions m ON m.id = r.mission_id
     WHERE r.id = ?",
    [$reportId]
);
if (!$report) {
    echo json_encode(['ok' => false, 'error' => t('shortage.report_not_found')]);
    exit;
}

$canManageWarRoom = hasPagePermission('missions_manage') || (int) $report['responsible_user_id'] === (int) $userId;
if (!$canManageWarRoom) {
    echo json_encode(['ok' => false, 'error' => t('shortage.no_manage_permission')]);
    exit;
}

if ($action === 'seen') {
    if (!$report['acknowledged_at']) {
        dbExecute("UPDATE mission_shortage_reports SET acknowledged_at = NOW(), acknowledged_by = ? WHERE id = ?", [$userId, $reportId]);
        logAudit('acknowledge_shortage_report', 'mission_shortage_reports', $reportId, null, ['mission_id' => $report['mission_id']]);
    }
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'resolve') {
    if (!$report['resolved_at']) {
        dbExecute(
            "UPDATE mission_shortage_reports
             SET acknowledged_at = COALESCE(acknowledged_at, NOW()), acknowledged_by = COALESCE(acknowledged_by, ?),
                 resolved_at = NOW(), resolved_by = ?
             WHERE id = ?",
            [$userId, $userId, $reportId]
        );
        logAudit('resolve_shortage_report', 'mission_shortage_reports', $reportId, null, ['mission_id' => $report['mission_id']]);
    }
    echo json_encode(['ok' => true]);
    exit;
}

echo json_encode(['ok' => false, 'error' => t('common.unknown_action')]);
