<?php
/**
 * VolunteerOps - Mission Dispatch Points Endpoint
 * War Room: admin sends a point or an area (polygon) to all teams or one team.
 * GET polls for active dispatches, POST creates or deletes one. AJAX only.
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();

header('Content-Type: application/json');

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
    echo json_encode(['ok' => false, 'error' => 'Δεν έχετε πρόσβαση στο War Room αυτής της αποστολής.']);
    exit;
}

// ── GET: poll for active dispatch points visible to me ─────────────────────
if (!isPost()) {
    $rows = dbFetchAll(
        "SELECT d.id, d.team_id, d.type, d.geo, d.label, mt.codename, mt.team_number
         FROM mission_dispatch_points d
         LEFT JOIN mission_teams mt ON mt.id = d.team_id
         WHERE d.mission_id = ?
           AND (d.team_id IS NULL OR ? = 1 OR d.team_id IN (
                SELECT team_id FROM mission_team_members WHERE user_id = ?
           ))
         ORDER BY d.created_at",
        [$missionId, $canManageWarRoom ? 1 : 0, $userId]
    );

    $dispatches = array_map(fn($row) => [
        'id'         => (int) $row['id'],
        'type'       => $row['type'],
        'geo'        => json_decode($row['geo'], true),
        'label'      => $row['label'],
        'team_label' => $row['team_id'] ? ($row['codename'] . ' ' . $row['team_number']) : 'Όλες οι ομάδες',
        'can_delete' => $canManageWarRoom,
    ], $rows);

    echo json_encode(['ok' => true, 'dispatches' => $dispatches]);
    exit;
}

// ── POST: create or delete ──────────────────────────────────────────────────
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
    echo json_encode(['ok' => false, 'error' => 'Μη έγκυρο αίτημα. Ανανεώστε τη σελίδα.']);
    exit;
}

if (!$canManageWarRoom) {
    echo json_encode(['ok' => false, 'error' => 'Δεν έχετε δικαίωμα διαχείρισης σημείων.']);
    exit;
}

$action = post('action');

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

    // Recipients: members of the specific team, or every approved volunteer of the mission.
    if ($teamId) {
        $recipients = dbFetchAll("SELECT user_id FROM mission_team_members WHERE team_id = ?", [$teamId]);
    } else {
        $recipients = dbFetchAll(
            "SELECT DISTINCT pr.volunteer_id AS user_id FROM participation_requests pr
             JOIN shifts s ON s.id = pr.shift_id
             WHERE s.mission_id = ? AND pr.status = ?",
            [$missionId, PARTICIPATION_APPROVED]
        );
    }

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
