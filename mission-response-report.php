<?php
/**
 * VolunteerOps - Mission Response Time Report
 * War Room: admin-only report of how long teams/volunteers took to
 * acknowledge ("Ελήφθη") and fulfill (arrived / sent the ping / sent the
 * photo-video) every order sent this mission. Merges two storage shapes —
 * the generic mission_orders/mission_order_recipients system (location,
 * photo, video) and dispatch's native tables (mission_dispatch_points +
 * mission_dispatch_receipts + mission_dispatch_acks) — into one normalized
 * detail list, same merge-in-PHP technique mission-history.php already uses.
 * GET only, AJAX.
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();

header('Content-Type: application/json');

$userId = getCurrentUserId();
$missionId = (int) get('mission_id');

$mission = dbFetchOne(
    "SELECT id, title, status, show_in_ops, responsible_user_id FROM missions WHERE id = ? AND deleted_at IS NULL",
    [$missionId]
);
if (!$mission || $mission['status'] !== STATUS_OPEN || empty($mission['show_in_ops'])) {
    echo json_encode(['ok' => false, 'error' => t('common.mission_not_found_or_inactive')]);
    exit;
}

$canManageWarRoom = hasPagePermission('missions_manage') || (int)$mission['responsible_user_id'] === (int)$userId;
if (!$canManageWarRoom) {
    echo json_encode(['ok' => false, 'error' => t('report.admin_only')]);
    exit;
}

// Core computation lives in includes/functions.php (computeMissionResponseReport()),
// shared with mission-report-print.php and mission-stats.php — it returns raw,
// unformatted timestamps, so this page applies its own compact date() format
// (the archival print export intentionally uses a longer format with the year).
$report = computeMissionResponseReport($missionId, getUserLanguage($userId));
$summary = $report['summary'];
$shortageSummary = $report['shortageSummary'];

$detail = array_map(function ($row) {
    $row['sent_at'] = date('d/m H:i', strtotime($row['sent_at']));
    $row['ack_at'] = $row['ack_at'] ? date('d/m H:i', strtotime($row['ack_at'])) : null;
    $row['fulfill_at'] = $row['fulfill_at'] ? date('d/m H:i', strtotime($row['fulfill_at'])) : null;
    return $row;
}, $report['detail']);

$shortageDetail = array_map(function ($row) {
    $row['sent_at'] = date('d/m H:i', strtotime($row['sent_at']));
    $row['seen_at'] = $row['seen_at'] ? date('d/m H:i', strtotime($row['seen_at'])) : null;
    $row['resolved_at'] = $row['resolved_at'] ? date('d/m H:i', strtotime($row['resolved_at'])) : null;
    return $row;
}, $report['shortageDetail']);

echo json_encode([
    'ok' => true, 'summary' => $summary, 'detail' => $detail,
    'shortageSummary' => $shortageSummary, 'shortageDetail' => $shortageDetail,
]);
