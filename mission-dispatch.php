<?php
/**
 * VolunteerOps - Mission Dispatch Points Endpoint
 * War Room: admin sends a point or an area (polygon) to all teams or one team.
 * GET polls for active dispatches, POST creates or deletes one. AJAX only.
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();

header('Content-Type: application/json');

/**
 * Notify command staff (system/department admins, this mission's shift leaders,
 * and its responsible user) that a team reported arrival at a dispatch point/area.
 * Mirrors the admin/shift-leader recipient resolution in mission-chat.php's
 * notifyMissionTeamChat() for the non-admin-sender branch.
 */
function notifyDispatchArrival(int $missionId, string $missionTitle, ?int $responsibleUserId, array $dispatch, ?string $teamLabel, string $ackerName, int $ackerId): void {
    $warRoomUrl = rtrim(BASE_URL, '/') . '/war-room.php?id=' . $missionId;
    $kind = $dispatch['type'] === 'point' ? 'στο σημείο' : 'στην περιοχή';
    $labelPart = $dispatch['label'] ? ' «' . $dispatch['label'] . '»' : '';
    $who = $teamLabel ? 'Η ομάδα ' . $teamLabel : $ackerName;
    $message = $who . ' ανέφερε άφιξη ' . $kind . $labelPart . ' της αποστολής «' . $missionTitle . '».';

    $admins = dbFetchAll("SELECT id FROM users WHERE role IN (?, ?) AND is_active = 1", [ROLE_SYSTEM_ADMIN, ROLE_DEPARTMENT_ADMIN]);
    $leaders = dbFetchAll(
        "SELECT DISTINCT u.id FROM users u
         JOIN participation_requests pr ON pr.volunteer_id = u.id
         JOIN shifts s ON pr.shift_id = s.id
         WHERE s.mission_id = ? AND u.role = ? AND u.is_active = 1 AND pr.status = ?",
        [$missionId, ROLE_SHIFT_LEADER, PARTICIPATION_APPROVED]
    );
    $recipientIds = array_merge(
        array_map('intval', array_column($admins, 'id')),
        array_map('intval', array_column($leaders, 'id'))
    );
    if ($responsibleUserId) {
        $recipientIds[] = $responsibleUserId;
    }
    $recipientIds = array_values(array_unique(array_diff($recipientIds, [$ackerId])));

    foreach ($recipientIds as $recipientId) {
        sendNotification($recipientId, '✅ Αναφορά Άφιξης', $message, 'success', 'mission_dispatch_ack', [
            'url' => $warRoomUrl,
            'tag' => 'dispatch-ack-mission-' . $missionId,
            'bannerMission' => $missionId,
        ]);
    }
}

/**
 * Notify command staff that a team confirmed receipt ("Ελήφθη") of a dispatch
 * point/area — the earlier stage of notifyDispatchArrival() above. Also threads
 * bannerMission into pushData so this pops the scrolling banner + alert sound
 * for command staff (war-room.php's banner mechanism is generic: any
 * sendNotification() pushData with bannerMission => $missionId qualifies, no
 * per-feature banner code needed), same as arrival now gets above.
 */
function notifyDispatchReceive(int $missionId, string $missionTitle, ?int $responsibleUserId, array $dispatch, ?string $teamLabel, string $receiverName, int $receiverId): void {
    $warRoomUrl = rtrim(BASE_URL, '/') . '/war-room.php?id=' . $missionId;
    $kind = $dispatch['type'] === 'point' ? 'σημείου' : 'περιοχής';
    $labelPart = $dispatch['label'] ? ' «' . $dispatch['label'] . '»' : '';
    $who = $teamLabel ? 'Η ομάδα ' . $teamLabel : $receiverName;
    $message = $who . ' επιβεβαίωσε λήψη ' . $kind . $labelPart . ' της αποστολής «' . $missionTitle . '».';

    $recipientIds = getMissionCommandStaffIds($missionId, $responsibleUserId, $receiverId);
    foreach ($recipientIds as $recipientId) {
        sendNotification($recipientId, '📩 Επιβεβαίωση Λήψης', $message, 'info', 'mission_dispatch_receive', [
            'url' => $warRoomUrl,
            'tag' => 'dispatch-receive-mission-' . $missionId,
            'bannerMission' => $missionId,
        ]);
    }
}

$userId = getCurrentUserId();
$user = getCurrentUser();

$missionId = (int) (isPost() ? post('mission_id') : get('mission_id'));

$mission = dbFetchOne(
    "SELECT id, title, status, show_in_ops, responsible_user_id FROM missions WHERE id = ? AND deleted_at IS NULL",
    [$missionId]
);
if (!$mission || $mission['status'] !== STATUS_OPEN || empty($mission['show_in_ops'])) {
    echo json_encode(['ok' => false, 'error' => 'Η αποστολή δεν βρέθηκε ή δεν είναι ενεργή στο Επιχειρησιακό.']);
    exit;
}

$canManageWarRoom = hasPagePermission('missions_manage') || (int)$mission['responsible_user_id'] === (int)$userId;
$isApprovedParticipant = (bool) dbFetchValue(
    "SELECT COUNT(*) FROM participation_requests pr
     JOIN shifts s ON s.id = pr.shift_id
     WHERE s.mission_id = ? AND pr.volunteer_id = ? AND pr.status = ?",
    [$missionId, $userId, PARTICIPATION_APPROVED]
);
if (!$canManageWarRoom && !$isApprovedParticipant) {
    echo json_encode(['ok' => false, 'error' => 'Δεν έχετε πρόσβαση στο Action Room αυτής της αποστολής.']);
    exit;
}

// ── GET: poll for active dispatch points visible to me ─────────────────────
if (!isPost()) {
    $dispatches = loadMissionDispatchesForUser($missionId, $userId, $canManageWarRoom, $isApprovedParticipant);
    echo json_encode(['ok' => true, 'dispatches' => $dispatches]);
    exit;
}

// ── POST: create, delete, or ack ────────────────────────────────────────────
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
    echo json_encode(['ok' => false, 'error' => 'Μη έγκυρο αίτημα. Ανανεώστε τη σελίδα.']);
    exit;
}

$action = post('action');

if ($action === 'receive') {
    if (!$isApprovedParticipant) {
        echo json_encode(['ok' => false, 'error' => 'Μόνο εγκεκριμένοι εθελοντές μπορούν να επιβεβαιώσουν λήψη.']);
        exit;
    }

    $dispatchId = (int) post('id');
    $dispatch = dbFetchOne("SELECT id, team_id, label, type FROM mission_dispatch_points WHERE id = ? AND mission_id = ?", [$dispatchId, $missionId]);
    if (!$dispatch) {
        echo json_encode(['ok' => false, 'error' => 'Δεν βρέθηκε.']);
        exit;
    }

    $myTeamId = getUserTeamIdForMission($missionId, $userId);
    if ($dispatch['team_id'] && (int) $dispatch['team_id'] !== $myTeamId) {
        echo json_encode(['ok' => false, 'error' => 'Αυτή η εντολή δεν αφορά την ομάδα σας.']);
        exit;
    }

    $existing = dbFetchOne("SELECT id FROM mission_dispatch_receipts WHERE dispatch_id = ? AND user_id = ?", [$dispatchId, $userId]);
    if (!$existing) {
        dbInsert(
            "INSERT INTO mission_dispatch_receipts (dispatch_id, team_id, user_id, created_at) VALUES (?, ?, ?, NOW())",
            [$dispatchId, $myTeamId, $userId]
        );
        logAudit('team_received_dispatch', 'mission_dispatch_points', $dispatchId, null, [
            'mission_id' => $missionId, 'team_id' => $myTeamId, 'user_id' => $userId,
        ]);

        $teamLabel = null;
        if ($myTeamId) {
            $teamRow = dbFetchOne("SELECT codename, team_number FROM mission_teams WHERE id = ?", [$myTeamId]);
            if ($teamRow) {
                $teamLabel = $teamRow['codename'] . ' ' . $teamRow['team_number'];
            }
        }
        notifyDispatchReceive($missionId, $mission['title'], $mission['responsible_user_id'] ? (int) $mission['responsible_user_id'] : null, $dispatch, $teamLabel, $user['name'], $userId);
    }

    $dispatches = loadMissionDispatchesForUser($missionId, $userId, $canManageWarRoom, $isApprovedParticipant);
    echo json_encode(['ok' => true, 'dispatches' => $dispatches]);
    exit;
}

if ($action === 'ack') {
    if (!$isApprovedParticipant) {
        echo json_encode(['ok' => false, 'error' => 'Μόνο εγκεκριμένοι εθελοντές μπορούν να αναφέρουν άφιξη.']);
        exit;
    }

    $dispatchId = (int) post('id');
    $dispatch = dbFetchOne("SELECT id, team_id, label, type FROM mission_dispatch_points WHERE id = ? AND mission_id = ?", [$dispatchId, $missionId]);
    if (!$dispatch) {
        echo json_encode(['ok' => false, 'error' => 'Δεν βρέθηκε.']);
        exit;
    }

    $myTeamId = getUserTeamIdForMission($missionId, $userId);

    if ($dispatch['team_id'] && (int) $dispatch['team_id'] !== $myTeamId) {
        echo json_encode(['ok' => false, 'error' => 'Αυτή η εντολή δεν αφορά την ομάδα σας.']);
        exit;
    }

    $existing = dbFetchOne("SELECT id FROM mission_dispatch_acks WHERE dispatch_id = ? AND user_id = ?", [$dispatchId, $userId]);
    if (!$existing) {
        dbInsert(
            "INSERT INTO mission_dispatch_acks (dispatch_id, team_id, user_id, created_at) VALUES (?, ?, ?, NOW())",
            [$dispatchId, $myTeamId, $userId]
        );
        logAudit('team_arrived_dispatch', 'mission_dispatch_points', $dispatchId, null, [
            'mission_id' => $missionId, 'team_id' => $myTeamId, 'user_id' => $userId,
        ]);

        $teamLabel = null;
        if ($myTeamId) {
            $teamRow = dbFetchOne("SELECT codename, team_number FROM mission_teams WHERE id = ?", [$myTeamId]);
            if ($teamRow) {
                $teamLabel = $teamRow['codename'] . ' ' . $teamRow['team_number'];
            }
        }
        notifyDispatchArrival($missionId, $mission['title'], $mission['responsible_user_id'] ? (int) $mission['responsible_user_id'] : null, $dispatch, $teamLabel, $user['name'], $userId);
    }

    $dispatches = loadMissionDispatchesForUser($missionId, $userId, $canManageWarRoom, $isApprovedParticipant);
    echo json_encode(['ok' => true, 'dispatches' => $dispatches]);
    exit;
}

if (!$canManageWarRoom) {
    echo json_encode(['ok' => false, 'error' => 'Δεν έχετε δικαίωμα διαχείρισης σημείων.']);
    exit;
}

if ($action === 'create') {
    $teamIdRaw = post('team_id');
    $teamId = ($teamIdRaw !== '' && $teamIdRaw !== null) ? (int) $teamIdRaw : null;
    $team = null;
    if ($teamId) {
        $team = dbFetchOne("SELECT id FROM mission_teams WHERE id = ? AND mission_id = ?", [$teamId, $missionId]);
        if (!$team) {
            echo json_encode(['ok' => false, 'error' => 'Η ομάδα δεν βρέθηκε.']);
            exit;
        }
    }

    $type = post('type');
    $label = trim((string) post('label'));
    $label = $label !== '' ? mb_substr($label, 0, 255) : null;
    $rawGeo = json_decode((string) post('geo'), true);

    if ($type === 'point') {
        if (!is_array($rawGeo) || !isset($rawGeo['lat'], $rawGeo['lng'])) {
            echo json_encode(['ok' => false, 'error' => 'Μη έγκυρο σημείο.']);
            exit;
        }
        $geo = ['lat' => (float) $rawGeo['lat'], 'lng' => (float) $rawGeo['lng']];
    } elseif ($type === 'polygon') {
        if (!is_array($rawGeo) || count($rawGeo) < 3) {
            echo json_encode(['ok' => false, 'error' => 'Το πολύγωνο χρειάζεται τουλάχιστον 3 σημεία.']);
            exit;
        }
        $geo = array_map(fn($pt) => [(float) $pt[0], (float) $pt[1]], $rawGeo);
    } else {
        echo json_encode(['ok' => false, 'error' => 'Άγνωστος τύπος σημείου.']);
        exit;
    }

    $dispatchId = dbInsert(
        "INSERT INTO mission_dispatch_points (mission_id, team_id, type, geo, label, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())",
        [$missionId, $teamId, $type, json_encode($geo), $label, $userId]
    );
    logAudit('create_mission_dispatch', 'mission_dispatch_points', $dispatchId, null, ['mission_id' => $missionId, 'team_id' => $teamId, 'type' => $type]);

    // Recipients: every approved volunteer of the mission gets the scrolling banner, even
    // when the dispatch itself targets one team — only that team's map shows the actual
    // pin (loadMissionDispatchesForUser), but everyone should see that an order went out.
    // (Team members are always a subset of approved participants, so this covers them too.)
    $recipients = dbFetchAll(
        "SELECT DISTINCT pr.volunteer_id AS user_id FROM participation_requests pr
         JOIN shifts s ON s.id = pr.shift_id
         WHERE s.mission_id = ? AND pr.status = ?",
        [$missionId, PARTICIPATION_APPROVED]
    );

    $warRoomUrl = rtrim(BASE_URL, '/') . '/war-room.php?id=' . $missionId;
    $title = $type === 'point' ? '📍 Νέο σημείο στον χάρτη' : '🗺️ Νέα περιοχή στον χάρτη';
    $message = 'Ο/Η υπεύθυνος/η της αποστολής «' . $mission['title'] . '» έστειλε '
        . ($type === 'point' ? 'ένα σημείο' : 'μια περιοχή') . ' στον χάρτη.'
        . ($label ? ' (' . $label . ')' : '');

    foreach ($recipients as $recipient) {
        $recipientId = (int) $recipient['user_id'];
        if ($recipientId === (int) $userId) {
            continue;
        }
        sendNotification($recipientId, $title, $message, 'info', 'mission_dispatch_point', [
            'url' => $warRoomUrl,
            'tag' => 'dispatch-point-mission-' . $missionId,
            'bannerMission' => $missionId,
        ]);
    }

    echo json_encode(['ok' => true, 'id' => (int) $dispatchId]);
    exit;
}

if ($action === 'delete') {
    $dispatchId = (int) post('id');
    $row = dbFetchOne("SELECT id FROM mission_dispatch_points WHERE id = ? AND mission_id = ?", [$dispatchId, $missionId]);
    if (!$row) {
        echo json_encode(['ok' => false, 'error' => 'Δεν βρέθηκε.']);
        exit;
    }
    dbExecute("DELETE FROM mission_dispatch_points WHERE id = ?", [$dispatchId]);
    logAudit('delete_mission_dispatch', 'mission_dispatch_points', $dispatchId, null, ['mission_id' => $missionId]);
    echo json_encode(['ok' => true]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Άγνωστη ενέργεια.']);
