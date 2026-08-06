<?php
/**
 * VolunteerOps - Mission Order Acknowledgment Endpoint
 * War Room: a recipient of a location/photo/video request marks it "Ελήφθη"
 * (received). Fulfillment itself is never posted here — it's stamped
 * automatically by ping-location.php / mission-photo.php when the recipient
 * actually responds. POST only, AJAX.
 *
 * Exception: task orders (order_type='task') have no automatic fulfillment
 * signal — there's no real-world action to detect — so the `complete` action
 * below is the recipient manually self-reporting "done" for that one type only.
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

if ($action === 'acknowledge') {
    $orderId = (int) post('order_id');

    $recipient = dbFetchOne(
        "SELECT r.id, r.acknowledged_at, o.order_type
         FROM mission_order_recipients r
         JOIN mission_orders o ON o.id = r.order_id
         WHERE r.order_id = ? AND r.user_id = ?",
        [$orderId, $userId]
    );
    if (!$recipient) {
        echo json_encode(['ok' => false, 'error' => t('order.no_request_for_you')]);
        exit;
    }

    if (!$recipient['acknowledged_at']) {
        dbExecute("UPDATE mission_order_recipients SET acknowledged_at = NOW() WHERE id = ?", [$recipient['id']]);
        logAudit('acknowledge_mission_order', 'mission_order_recipients', $recipient['id'], null, ['order_id' => $orderId]);

        // Route/task/message orders each get a loud sound alert to command
        // staff the instant the recipient acknowledges (bannerMission tag
        // below is what makes showWarRoomBanner()'s existing
        // playWarRoomAlertSound() call fire — no new audio code needed).
        // Photo/video/location request acknowledges stay exactly as silent
        // as they've always been; this was asked for these three order
        // types specifically, not a blanket behavior change.
        if ($recipient['order_type'] === 'route') {
            $route = dbFetchOne(
                "SELECT r.id AS route_id, r.mission_id, r.team_id, m.title AS mission_title, m.responsible_user_id, mt.codename, mt.team_number
                 FROM mission_routes r
                 JOIN missions m ON m.id = r.mission_id
                 LEFT JOIN mission_teams mt ON mt.id = r.team_id
                 WHERE r.order_id = ?",
                [$orderId]
            );
            if ($route) {
                $teamLbl = $route['team_id']
                    ? teamLabel($route['codename'], $route['team_number'])
                    : routeMixedTeamLabel((int) $route['route_id']);
                notifyCommandStaffBanner(
                    (int) $route['mission_id'], $route['mission_title'], $route['responsible_user_id'] ? (int) $route['responsible_user_id'] : null, $userId,
                    'mission_route_acknowledged', 'route.notify_acknowledged_title', [],
                    'route.notify_acknowledged_message', ['team' => $teamLbl, 'mission' => $route['mission_title']]
                );
            }
        } elseif (in_array($recipient['order_type'], ['task', 'message'], true)) {
            $order = dbFetchOne(
                "SELECT o.mission_id, m.title AS mission_title, m.responsible_user_id
                 FROM mission_orders o
                 JOIN missions m ON m.id = o.mission_id
                 WHERE o.id = ?",
                [$orderId]
            );
            if ($order) {
                $recipientName = getCurrentUser()['name'] ?? '';
                if ($recipient['order_type'] === 'task') {
                    notifyCommandStaffBanner(
                        (int) $order['mission_id'], $order['mission_title'], $order['responsible_user_id'] ? (int) $order['responsible_user_id'] : null, $userId,
                        'mission_task_acknowledged', 'order.task.notify_acknowledged_title', [],
                        'order.task.notify_acknowledged_message', ['name' => $recipientName, 'mission' => $order['mission_title']]
                    );
                } else {
                    notifyCommandStaffBanner(
                        (int) $order['mission_id'], $order['mission_title'], $order['responsible_user_id'] ? (int) $order['responsible_user_id'] : null, $userId,
                        'global_message_acknowledged', 'global_message.notify_acknowledged_title', [],
                        'global_message.notify_acknowledged_message', ['name' => $recipientName, 'mission' => $order['mission_title']]
                    );
                }
            }
        } elseif ($recipient['order_type'] === 'charge_phone') {
            // Deliberately NOT notifyCommandStaffBanner() (that broadcasts to
            // the whole command-staff roster, the right call for route/task/
            // message above) — a battery alert is a one-to-one nudge, so only
            // the specific admin who sent it should hear it got seen.
            $order = dbFetchOne(
                "SELECT o.mission_id, o.created_by, m.title AS mission_title
                 FROM mission_orders o
                 JOIN missions m ON m.id = o.mission_id
                 WHERE o.id = ?",
                [$orderId]
            );
            if ($order && (int) $order['created_by'] !== $userId) {
                $creatorId = (int) $order['created_by'];
                $creatorLang = getUserLanguages([$creatorId])[$creatorId] ?? DEFAULT_LANGUAGE;
                sendNotification(
                    $creatorId,
                    t('order.charge_phone.notify_acknowledged_title', [], $creatorLang),
                    t('order.charge_phone.notify_acknowledged_message', ['name' => getCurrentUser()['name'] ?? '', 'mission' => $order['mission_title']], $creatorLang),
                    'success', 'mission_battery_alert_acknowledged',
                    [
                        'url' => rtrim(BASE_URL, '/') . '/war-room.php?id=' . $order['mission_id'],
                        'tag' => 'charge_phone-ack-' . $orderId,
                        'bannerMission' => (int) $order['mission_id'],
                    ]
                );
            }
        }
    }

    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'complete') {
    $orderId = (int) post('order_id');

    $recipient = dbFetchOne(
        "SELECT r.id, r.fulfilled_at, o.order_type
         FROM mission_order_recipients r
         JOIN mission_orders o ON o.id = r.order_id
         WHERE r.order_id = ? AND r.user_id = ?",
        [$orderId, $userId]
    );
    if (!$recipient) {
        echo json_encode(['ok' => false, 'error' => t('order.no_request_for_you')]);
        exit;
    }
    if ($recipient['order_type'] !== 'task') {
        echo json_encode(['ok' => false, 'error' => t('order.complete_not_supported')]);
        exit;
    }

    if (!$recipient['fulfilled_at']) {
        dbExecute(
            "UPDATE mission_order_recipients SET acknowledged_at = COALESCE(acknowledged_at, NOW()), fulfilled_at = NOW() WHERE id = ?",
            [$recipient['id']]
        );
        logAudit('complete_mission_order', 'mission_order_recipients', $recipient['id'], null, ['order_id' => $orderId]);
    }

    echo json_encode(['ok' => true]);
    exit;
}

echo json_encode(['ok' => false, 'error' => t('common.unknown_action')]);
