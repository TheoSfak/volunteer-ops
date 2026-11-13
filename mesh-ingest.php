<?php
/**
 * VolunteerOps - The LoRa gateway's server side (v3.427.0).
 *
 * POST only, AJAX, command staff only. The Action Room page that holds a
 * Meshtastic node on its USB cable (assets/js/mesh-gateway.js) sends what the
 * radio heard here; includes/functions-mesh.php explains what becomes of it.
 *
 *   ingest   packets = JSON list of position records (node, id, lat, lng, prec,
 *            age_s, speed, snr, rssi, hops). Answers the counts.
 *   nodes    the nodes bound to people of the mission, the nodes heard lately
 *            that nobody carries yet, and the people a node can be bound to
 *   bind     node (number) + user_id, optional label: this node is that person's
 *   unbind   node
 *   pending_orders  node: that person's orders on this mission not yet acknowledged
 *   order_ack       node + order_id: their "Ελήφθη" arrived by radio, not the app
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

// Nothing below writes the session, and this page posts every few seconds for
// hours: it must not queue behind the same browser's poll holding the lock.
session_write_close();

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
if (!canManageActionRoom($mission['responsible_user_id'] ? (int) $mission['responsible_user_id'] : null, $userId)) {
    echo json_encode(['ok' => false, 'error' => t('mesh.err_manage')]);
    exit;
}

$action = post('action');

if ($action === 'ingest') {
    $records = json_decode((string) ($_POST['packets'] ?? ''), true);
    if (!is_array($records)) {
        echo json_encode(['ok' => false, 'error' => t('common.invalid_request')]);
        exit;
    }
    echo json_encode(['ok' => true] + meshIngest($missionId, $records), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'nodes') {
    echo json_encode([
        'ok'     => true,
        'bound'  => meshBoundNodes($missionId),
        'heard'  => meshHeardUnbound(),
        'people' => meshCandidates($missionId),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'bind') {
    $node = (int) post('node');
    $personId = (int) post('user_id');
    // Only somebody who takes part in this mission can be given a node.
    if (!in_array($personId, actionRoomParticipantIds($missionId), true)) {
        echo json_encode(['ok' => false, 'error' => t('mesh.err_person')], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!meshBindNode($node, $personId, post('label') !== '' ? (string) post('label') : null, $userId)) {
        echo json_encode(['ok' => false, 'error' => t('mesh.err_node')], JSON_UNESCAPED_UNICODE);
        exit;
    }
    logAudit('mesh_node_bind', 'missions', $missionId, null, ['node' => $node, 'user_id' => $personId]);
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'unbind') {
    $node = (int) post('node');
    // Only a node carried by somebody of this mission: one command post must
    // not be able to unbind another mission's radios.
    $owner = meshBoundUsers([$node])[$node] ?? null;
    if ($owner === null || !in_array($owner, actionRoomParticipantIds($missionId), true)) {
        echo json_encode(['ok' => true, 'removed' => 0]);
        exit;
    }
    $removed = meshUnbindNode($node);
    if ($removed > 0) {
        logAudit('mesh_node_unbind', 'missions', $missionId, null, ['node' => $node]);
    }
    echo json_encode(['ok' => true, 'removed' => $removed]);
    exit;
}

// M4: node -> owner, shared by both actions below - only a node bound to
// somebody of THIS mission, same rule as 'unbind'.
$meshOwnerOf = function (int $node) use ($missionId): ?int {
    $owner = meshBoundUsers([$node])[$node] ?? null;
    return ($owner !== null && in_array($owner, actionRoomParticipantIds($missionId), true)) ? $owner : null;
};

if ($action === 'pending_orders') {
    $owner = $meshOwnerOf((int) post('node'));
    if ($owner === null) {
        echo json_encode(['ok' => false, 'error' => t('mesh.err_person')], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(['ok' => true, 'orders' => meshPendingOrders($missionId, $owner)], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'order_ack') {
    $owner = $meshOwnerOf((int) post('node'));
    if ($owner === null) {
        echo json_encode(['ok' => false, 'error' => t('mesh.err_person')], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $orderId = (int) post('order_id');
    // The order must be this mission's own - a radio ack must not be able to
    // touch a recipient row belonging to some other mission this person is on.
    $belongs = dbFetchValue("SELECT COUNT(*) FROM mission_orders WHERE id = ? AND mission_id = ?", [$orderId, $missionId]);
    if (!$belongs) {
        echo json_encode(['ok' => false, 'error' => t('mesh.err_order')], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $ownerName = (string) (dbFetchValue("SELECT name FROM users WHERE id = ?", [$owner]) ?? '');
    $error = receiveMissionOrder($orderId, $owner, $ownerName, MESH_ACK_VIA);
    echo json_encode($error === null ? ['ok' => true] : ['ok' => false, 'error' => $error], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Unknown action']);
