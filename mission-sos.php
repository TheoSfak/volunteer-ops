<?php
/**
 * VolunteerOps - Mission SOS Alert Admin Action Endpoint
 * War Room: command staff acknowledges ("Ελήφθη") then resolves ("Λύθηκε") an
 * SOS alert raised via the needs_help field-status button. Mirrors
 * mission-shortage.php's shape — a single ticket, no fixed recipient roster,
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

$action  = post('action');
$alertId = (int) post('alert_id');

$alert = dbFetchOne(
    "SELECT a.id, a.mission_id, a.pr_id, a.acknowledged_at, a.resolved_at, m.responsible_user_id
     FROM mission_sos_alerts a
     JOIN missions m ON m.id = a.mission_id
     WHERE a.id = ?",
    [$alertId]
);
if (!$alert) {
    echo json_encode(['ok' => false, 'error' => t('sos.alert_not_found')]);
    exit;
}

$canManageWarRoom = canManageActionRoom($alert['responsible_user_id'] ? (int) $alert['responsible_user_id'] : null, (int) $userId);
if (!$canManageWarRoom) {
    echo json_encode(['ok' => false, 'error' => t('sos.no_manage_permission')]);
    exit;
}

if ($action === 'acknowledge') {
    if (!$alert['acknowledged_at']) {
        dbExecute("UPDATE mission_sos_alerts SET acknowledged_at = NOW(), acknowledged_by = ? WHERE id = ?", [$userId, $alertId]);
        logAudit('acknowledge_sos_alert', 'mission_sos_alerts', $alertId, null, ['mission_id' => $alert['mission_id']]);
    }
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'resolve') {
    if (!$alert['resolved_at']) {
        db()->beginTransaction();
        try {
            dbExecute(
                "UPDATE mission_sos_alerts
                 SET acknowledged_at = COALESCE(acknowledged_at, NOW()), acknowledged_by = COALESCE(acknowledged_by, ?),
                     resolved_at = NOW(), resolved_by = ?
                 WHERE id = ?",
                [$userId, $userId, $alertId]
            );
            if ($alert['pr_id']) {
                // Guard on field_status='needs_help' so an independent status change
                // by the volunteer moments earlier isn't clobbered back to NULL.
                dbExecute(
                    "UPDATE participation_requests SET field_status = NULL, field_status_updated_at = NOW()
                     WHERE id = ? AND field_status = 'needs_help'",
                    [$alert['pr_id']]
                );
            }
            logAudit('resolve_sos_alert', 'mission_sos_alerts', $alertId, null, ['mission_id' => $alert['mission_id']]);
            db()->commit();
        } catch (Exception $e) {
            db()->rollBack();
            echo json_encode(['ok' => false, 'error' => t('sos.resolve_failed')]);
            exit;
        }
    }
    echo json_encode(['ok' => true]);
    exit;
}

echo json_encode(['ok' => false, 'error' => t('common.unknown_action')]);
