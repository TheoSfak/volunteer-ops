<?php
/**
 * VolunteerOps - Mission Chat Endpoint
 * War Room live chat: general room (team_id empty) or private team room.
 * GET polls for messages, POST sends or deletes one. AJAX only.
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();

header('Content-Type: application/json');

/**
 * Notify a team's chat about a new message: the team's own members
 * (minus the sender), plus — only when a regular member posted, not an
 * admin — the mission's admins/shift leaders, same recipient set as the
 * needs_help escalation in volunteer-status.php.
 */
function notifyMissionTeamChat(int $missionId, string $missionTitle, array $team, int $senderId, string $senderName, string $message, bool $senderIsAdmin, ?int $responsibleUserId = null): void {
    $teamId = (int) $team['id'];
    $teamLabel = $team['codename'] . ' ' . $team['team_number'];
    $warRoomUrl = rtrim(BASE_URL, '/') . '/war-room.php?id=' . $missionId;
    $preview = mb_strlen($message) > 120 ? mb_substr($message, 0, 117) . '…' : $message;

    $recipientIds = array_map('intval', array_column(
        dbFetchAll("SELECT user_id FROM mission_team_members WHERE team_id = ? AND user_id != ?", [$teamId, $senderId]),
        'user_id'
    ));

    if (!$senderIsAdmin) {
        $recipientIds = array_merge($recipientIds, getMissionCommandStaffIds($missionId, $responsibleUserId, $senderId));
    }

    $recipientIds = array_values(array_unique(array_diff($recipientIds, [$senderId])));
    foreach ($recipientIds as $recipientId) {
        $pushData = [
            'url' => $warRoomUrl,
            'tag' => 'mission-chat-team-' . $teamId,
        ];
        // Only an admin/shift-leader message to the team's private room gets the
        // loud scrolling banner + sound — a regular member posting (notifying
        // command staff, the other branch above) stays a quiet bell, same as before.
        if ($senderIsAdmin) {
            $pushData['bannerMission'] = $missionId;
        }
        sendNotification($recipientId, '💬 ' . $teamLabel, $senderName . ': ' . $preview, 'info', 'mission_team_chat', $pushData);
    }
}

$userId = getCurrentUserId();
$user = getCurrentUser();

$missionId = (int) (isPost() ? post('mission_id') : get('mission_id'));
$teamIdRaw = isPost() ? post('team_id') : get('team_id');
$teamId = ($teamIdRaw !== '' && $teamIdRaw !== null) ? (int) $teamIdRaw : 0;
if ($teamId <= 0) {
    $teamId = null;
}

$mission = dbFetchOne(
    "SELECT id, title, status, show_in_ops, responsible_user_id FROM missions WHERE id = ? AND deleted_at IS NULL",
    [$missionId]
);
if (!$mission || $mission['status'] !== STATUS_OPEN || empty($mission['show_in_ops'])) {
    echo json_encode(['ok' => false, 'error' => t('common.mission_not_found_or_inactive')]);
    exit;
}

$canManageWarRoom = canManageActionRoom($mission['responsible_user_id'] ? (int)$mission['responsible_user_id'] : null, (int)$userId);
$isApprovedParticipant = (bool) dbFetchValue(
    "SELECT COUNT(*) FROM participation_requests pr
     JOIN shifts s ON s.id = pr.shift_id
     WHERE s.mission_id = ? AND pr.volunteer_id = ? AND pr.status = ?",
    [$missionId, $userId, PARTICIPATION_APPROVED]
);
if (!$canManageWarRoom && !$isApprovedParticipant) {
    echo json_encode(['ok' => false, 'error' => t('common.no_access_action_room')]);
    exit;
}

$team = null;
if ($teamId) {
    $team = dbFetchOne("SELECT * FROM mission_teams WHERE id = ? AND mission_id = ?", [$teamId, $missionId]);
    if (!$team) {
        echo json_encode(['ok' => false, 'error' => t('common.team_not_found')]);
        exit;
    }
    $isTeamMember = (bool) dbFetchValue(
        "SELECT COUNT(*) FROM mission_team_members WHERE team_id = ? AND user_id = ?",
        [$teamId, $userId]
    );
    if (!$canManageWarRoom && !$isTeamMember) {
        echo json_encode(['ok' => false, 'error' => t('chat.no_room_access')]);
        exit;
    }
}

// ── GET: poll for messages ──────────────────────────────────────────────────
if (!isPost()) {
    $afterId = (int) get('after_id');
    $teamSql = $teamId ? 'c.team_id = ?' : 'c.team_id IS NULL';
    $params = [$missionId];
    if ($teamId) {
        $params[] = $teamId;
    }

    if ($afterId > 0) {
        $params[] = $afterId;
        $rows = dbFetchAll(
            "SELECT c.id, c.user_id, u.name, u.is_external, u.guest_org_name, c.message, c.created_at
             FROM mission_chat_messages c
             JOIN users u ON u.id = c.user_id
             WHERE c.mission_id = ? AND {$teamSql} AND c.id > ?
             ORDER BY c.id ASC",
            $params
        );
    } else {
        $rows = dbFetchAll(
            "SELECT c.id, c.user_id, u.name, u.is_external, u.guest_org_name, c.message, c.created_at
             FROM mission_chat_messages c
             JOIN users u ON u.id = c.user_id
             WHERE c.mission_id = ? AND {$teamSql}
             ORDER BY c.id DESC LIMIT 50",
            $params
        );
        $rows = array_reverse($rows);
    }

    $messages = array_map(fn($r) => [
        'id'            => (int) $r['id'],
        'user_id'       => (int) $r['user_id'],
        'name'          => $r['name'],
        'is_external'   => (bool) $r['is_external'],
        'guest_org_name'=> $r['guest_org_name'],
        'message'       => $r['message'],
        'time'          => date('H:i', strtotime($r['created_at'])),
        'mine'          => (int) $r['user_id'] === (int) $userId,
        'can_delete'    => (int) $r['user_id'] === (int) $userId || $canManageWarRoom,
    ], $rows);

    echo json_encode(['ok' => true, 'messages' => $messages]);
    exit;
}

// ── POST: send or delete ────────────────────────────────────────────────────
if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string) $_POST['csrf_token'])) {
    echo json_encode(['ok' => false, 'error' => t('common.invalid_request')]);
    exit;
}

$action = post('action');

if ($action === 'send') {
    $message = trim((string) post('message'));
    if ($message === '') {
        echo json_encode(['ok' => false, 'error' => t('chat.empty_message')]);
        exit;
    }
    if (mb_strlen($message) > 2000) {
        $message = mb_substr($message, 0, 2000);
    }

    $messageId = dbInsert(
        "INSERT INTO mission_chat_messages (mission_id, team_id, user_id, message, created_at) VALUES (?, ?, ?, ?, NOW())",
        [$missionId, $teamId, $userId, $message]
    );

    if ($teamId && $team) {
        notifyMissionTeamChat($missionId, $mission['title'], $team, $userId, $user['name'], $message, $canManageWarRoom, $mission['responsible_user_id'] ? (int) $mission['responsible_user_id'] : null);
    }

    echo json_encode(['ok' => true, 'message' => [
        'id'             => (int) $messageId,
        'user_id'        => (int) $userId,
        'name'           => $user['name'],
        'is_external'    => (bool) $user['is_external'],
        'guest_org_name' => $user['guest_org_name'],
        'message'        => $message,
        'time'           => date('H:i'),
        'mine'           => true,
        'can_delete'     => true,
    ]]);
    exit;
}

if ($action === 'delete') {
    $messageId = (int) post('message_id');
    $row = dbFetchOne(
        "SELECT id, user_id FROM mission_chat_messages WHERE id = ? AND mission_id = ?",
        [$messageId, $missionId]
    );
    if (!$row) {
        echo json_encode(['ok' => false, 'error' => t('chat.message_not_found')]);
        exit;
    }
    if ((int) $row['user_id'] !== (int) $userId && !$canManageWarRoom) {
        echo json_encode(['ok' => false, 'error' => t('chat.no_delete_permission')]);
        exit;
    }

    dbExecute("DELETE FROM mission_chat_messages WHERE id = ?", [$messageId]);
    logAudit('delete_mission_chat_message', 'mission_chat_messages', $messageId, null, ['mission_id' => $missionId, 'team_id' => $teamId]);

    echo json_encode(['ok' => true]);
    exit;
}

echo json_encode(['ok' => false, 'error' => t('common.unknown_action')]);
