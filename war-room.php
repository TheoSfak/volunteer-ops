<?php
/**
 * VolunteerOps - War Room
 * Mission-specific live operational view for approved participants and managers.
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();

$missionId = (int)get('id');
if (!$missionId) {
    setFlash('error', 'Η αποστολή δεν βρέθηκε.');
    redirect('dashboard.php');
}

$user = getCurrentUser();
$mission = dbFetchOne(
    "SELECT m.*, d.name AS department_name, mt.name AS mission_type_name,
            r.name AS responsible_name
     FROM missions m
     LEFT JOIN departments d ON d.id = m.department_id
     LEFT JOIN mission_types mt ON mt.id = m.mission_type_id
     LEFT JOIN users r ON r.id = m.responsible_user_id
     WHERE m.id = ? AND m.deleted_at IS NULL",
    [$missionId]
);
if (!$mission) {
    setFlash('error', 'Η αποστολή δεν βρέθηκε.');
    redirect('dashboard.php');
}

if (!defined('MISSION_TEAM_CODENAMES')) {
    define('MISSION_TEAM_CODENAMES', ['Alpha','Bravo','Charlie','Delta','Echo','Foxtrot','Golf','Hotel','India',
        'Juliett','Kilo','Lima','Mike','November','Oscar','Papa','Quebec','Romeo',
        'Sierra','Tango','Uniform','Victor','Whiskey','X-ray','Yankee','Zulu']);
}

/**
 * Notify every team member (individually) about their team assignment.
 * $namesByUserId must map user_id => name for all ids in $memberIds/$leaderId.
 */
function notifyMissionTeamMembers(int $missionId, string $missionTitle, string $codename, int $teamNumber, array $memberIds, int $leaderId, array $namesByUserId): void {
    $teamLabel = $codename . ' ' . $teamNumber;
    $warRoomUrl = rtrim(BASE_URL, '/') . '/war-room.php?id=' . $missionId;
    $leaderName = $namesByUserId[$leaderId] ?? '';
    foreach ($memberIds as $memberId) {
        $teammateNames = array_filter(array_map(
            fn($id) => $namesByUserId[$id] ?? '',
            array_values(array_diff($memberIds, [$memberId]))
        ));
        $message = 'Αποστολή «' . $missionTitle . '» — μπήκατε στην ομάδα «' . $teamLabel . '».';
        if (!empty($teammateNames)) {
            $message .= ' Μαζί σας: ' . implode(', ', $teammateNames) . '.';
        }
        $message .= $memberId === $leaderId
            ? ' Είστε ο υπεύθυνος της ομάδας.'
            : ' Υπεύθυνος: ' . $leaderName . '.';
        sendNotification($memberId, '🔷 Ομάδα ' . $teamLabel, $message, 'info', '', [
            'url' => $warRoomUrl,
            'tag' => 'mission-team-' . $missionId,
        ]);
    }
}

/**
 * War Room: persist a trackable order (mission_orders + one mission_order_recipients
 * row per recipient, snapshotting each recipient's team) then notify them, threading
 * orderId into the pushData so the alert banner can offer an "Ελήφθη" button. Shared
 * by request_location/request_photo/request_video — the only difference between them
 * is order_type + notification copy.
 */
function createMissionOrderAndNotify(int $missionId, string $missionTitle, string $orderType, int $createdBy, array $recipientIds, string $title, string $message, string $broadcastMessage, ?string $taskText = null): int {
    $orderId = dbInsert(
        "INSERT INTO mission_orders (mission_id, order_type, task_text, created_by, created_at) VALUES (?, ?, ?, ?, NOW())",
        [$missionId, $orderType, $taskText, $createdBy]
    );

    $warRoomUrl = rtrim(BASE_URL, '/') . '/war-room.php?id=' . $missionId;
    foreach ($recipientIds as $recipientId) {
        $teamId = getUserTeamIdForMission($missionId, $recipientId);
        dbInsert(
            "INSERT INTO mission_order_recipients (order_id, user_id, team_id) VALUES (?, ?, ?)",
            [$orderId, $recipientId, $teamId]
        );
        sendNotification($recipientId, $title, $message, 'warning', '', [
            'url' => $warRoomUrl,
            'tag' => $orderType . '-request-mission-' . $missionId,
            'vibrate' => [300, 100, 300, 100, 500],
            'bannerMission' => $missionId,
            'orderId' => (int) $orderId,
        ]);
    }

    // Every order also scrolls as a banner for every other approved participant of the
    // mission, not just whoever it was actually addressed to, so the whole mission stays
    // aware of what's being asked. No orderId here — no order row, no "Ελήφθη" button —
    // this is informational only, unlike the real recipients notified above.
    $allApproved = dbFetchAll(
        "SELECT DISTINCT pr.volunteer_id FROM participation_requests pr
         JOIN shifts s ON s.id = pr.shift_id
         WHERE s.mission_id = ? AND pr.status = ?",
        [$missionId, PARTICIPATION_APPROVED]
    );
    $bystanderIds = array_diff(
        array_map('intval', array_column($allApproved, 'volunteer_id')),
        $recipientIds,
        [$createdBy]
    );
    foreach ($bystanderIds as $bystanderId) {
        sendNotification($bystanderId, $title, $broadcastMessage, 'info', '', [
            'url' => $warRoomUrl,
            'tag' => $orderType . '-request-mission-' . $missionId,
            'bannerMission' => $missionId,
        ]);
    }

    return (int) $orderId;
}

$canManageWarRoom = hasPagePermission('missions_manage') || (int)$mission['responsible_user_id'] === (int)$user['id'];
$isApprovedParticipant = (bool)dbFetchValue(
    "SELECT COUNT(*) FROM participation_requests pr
     JOIN shifts s ON s.id = pr.shift_id
     WHERE s.mission_id = ? AND pr.volunteer_id = ? AND pr.status = ?",
    [$missionId, $user['id'], PARTICIPATION_APPROVED]
);
if (!$canManageWarRoom && !$isApprovedParticipant) {
    setFlash('error', 'Έχετε πρόσβαση στο War Room μόνο για αποστολές στις οποίες είστε εγκεκριμένος/η.');
    redirect('dashboard.php');
}
if ($mission['status'] !== STATUS_OPEN || empty($mission['show_in_ops'])) {
    setFlash('warning', 'Η αποστολή δεν είναι ενεργή στο Επιχειρησιακό.');
    redirect('mission-view.php?id=' . $missionId);
}

$fieldMode = ($_COOKIE['wr_field_mode'] ?? '') === '1';

if (isPost()) {
    verifyCsrf();
    if (post('action') === 'close_mission') {
        if (!$canManageWarRoom) {
            setFlash('error', 'Δεν έχετε δικαίωμα να κλείσετε αυτή την αποστολή.');
        } else {
            dbExecute("UPDATE missions SET status = ?, updated_at = NOW() WHERE id = ? AND status = ?", [STATUS_CLOSED, $missionId, STATUS_OPEN]);
            logAudit('close_from_war_room', 'missions', $missionId, null, ['old_status' => STATUS_OPEN]);
            setFlash('success', 'Η αποστολή έκλεισε και αφαιρέθηκε από το Επιχειρησιακό.');
            redirect('ops-dashboard.php');
        }
    } elseif (post('action') === 'request_location') {
        if (!$canManageWarRoom) {
            setFlash('error', 'Δεν έχετε δικαίωμα να ζητήσετε στίγματα.');
            redirect('war-room.php?id=' . $missionId);
        }

        $activeRecipients = dbFetchAll(
            "SELECT DISTINCT pr.volunteer_id, u.name
             FROM participation_requests pr
             JOIN shifts s ON s.id = pr.shift_id
             JOIN users u ON u.id = pr.volunteer_id
             WHERE s.mission_id = ? AND pr.status = ?
               AND s.start_time <= NOW() AND s.end_time > NOW()",
            [$missionId, PARTICIPATION_APPROVED]
        );
        $activeIds = array_map('intval', array_column($activeRecipients, 'volunteer_id'));
        $requestedIds = post('request_scope') === 'all'
            ? $activeIds
            : array_values(array_intersect($activeIds, array_map('intval', (array)($_POST['volunteers'] ?? []))));

        if (empty($requestedIds)) {
            setFlash('warning', 'Επιλέξτε τουλάχιστον έναν εθελοντή με ενεργή βάρδια.');
        } else {
            createMissionOrderAndNotify(
                $missionId, $mission['title'], 'location', $user['id'], $requestedIds,
                '📍 Ζητείται στίγμα GPS',
                'Ο/Η υπεύθυνος/η της αποστολής «' . $mission['title'] . '» ζητά να στείλετε το τρέχον στίγμα σας.',
                'Ζητήθηκε στίγμα GPS από εθελοντές της αποστολής «' . $mission['title'] . '».'
            );
            logAudit('request_mission_location', 'missions', $missionId, null, ['recipient_ids' => $requestedIds]);
            setFlash('success', 'Στάλθηκε αίτημα στίγματος σε ' . count($requestedIds) . ' ενεργούς εθελοντές.');
        }
        redirect('war-room.php?id=' . $missionId);
    } elseif (post('action') === 'request_photo') {
        if (!$canManageWarRoom) {
            setFlash('error', 'Δεν έχετε δικαίωμα να ζητήσετε φωτογραφία.');
            redirect('war-room.php?id=' . $missionId);
        }

        $activeRecipients = dbFetchAll(
            "SELECT DISTINCT pr.volunteer_id, u.name
             FROM participation_requests pr
             JOIN shifts s ON s.id = pr.shift_id
             JOIN users u ON u.id = pr.volunteer_id
             WHERE s.mission_id = ? AND pr.status = ?
               AND s.start_time <= NOW() AND s.end_time > NOW()",
            [$missionId, PARTICIPATION_APPROVED]
        );
        $activeIds = array_map('intval', array_column($activeRecipients, 'volunteer_id'));
        $requestedIds = post('request_scope') === 'all'
            ? $activeIds
            : array_values(array_intersect($activeIds, array_map('intval', (array)($_POST['volunteers'] ?? []))));

        if (empty($requestedIds)) {
            setFlash('warning', 'Επιλέξτε τουλάχιστον έναν εθελοντή με ενεργή βάρδια.');
        } else {
            createMissionOrderAndNotify(
                $missionId, $mission['title'], 'photo', $user['id'], $requestedIds,
                '📷 Ζητείται φωτογραφία',
                'Ο/Η υπεύθυνος/η της αποστολής «' . $mission['title'] . '» ζητά να στείλετε φωτογραφία από το πεδίο.',
                'Ζητήθηκε φωτογραφία πεδίου από εθελοντές της αποστολής «' . $mission['title'] . '».'
            );
            logAudit('request_mission_photo', 'missions', $missionId, null, ['recipient_ids' => $requestedIds]);
            setFlash('success', 'Στάλθηκε αίτημα φωτογραφίας σε ' . count($requestedIds) . ' ενεργούς εθελοντές.');
        }
        redirect('war-room.php?id=' . $missionId);
    } elseif (post('action') === 'request_video') {
        if (!$canManageWarRoom) {
            setFlash('error', 'Δεν έχετε δικαίωμα να ζητήσετε βίντεο.');
            redirect('war-room.php?id=' . $missionId);
        }

        $activeRecipients = dbFetchAll(
            "SELECT DISTINCT pr.volunteer_id, u.name
             FROM participation_requests pr
             JOIN shifts s ON s.id = pr.shift_id
             JOIN users u ON u.id = pr.volunteer_id
             WHERE s.mission_id = ? AND pr.status = ?
               AND s.start_time <= NOW() AND s.end_time > NOW()",
            [$missionId, PARTICIPATION_APPROVED]
        );
        $activeIds = array_map('intval', array_column($activeRecipients, 'volunteer_id'));
        $requestedIds = post('request_scope') === 'all'
            ? $activeIds
            : array_values(array_intersect($activeIds, array_map('intval', (array)($_POST['volunteers'] ?? []))));

        if (empty($requestedIds)) {
            setFlash('warning', 'Επιλέξτε τουλάχιστον έναν εθελοντή με ενεργή βάρδια.');
        } else {
            createMissionOrderAndNotify(
                $missionId, $mission['title'], 'video', $user['id'], $requestedIds,
                '🎥 Ζητείται βίντεο',
                'Ο/Η υπεύθυνος/η της αποστολής «' . $mission['title'] . '» ζητά να στείλετε βίντεο από το πεδίο.',
                'Ζητήθηκε βίντεο πεδίου από εθελοντές της αποστολής «' . $mission['title'] . '».'
            );
            logAudit('request_mission_video', 'missions', $missionId, null, ['recipient_ids' => $requestedIds]);
            setFlash('success', 'Στάλθηκε αίτημα βίντεο σε ' . count($requestedIds) . ' ενεργούς εθελοντές.');
        }
        redirect('war-room.php?id=' . $missionId);
    } elseif (post('action') === 'request_task') {
        if (!$canManageWarRoom) {
            setFlash('error', 'Δεν έχετε δικαίωμα να δώσετε εντολή.');
            redirect('war-room.php?id=' . $missionId);
        }

        $taskText = trim((string) post('task_text'));
        $taskText = mb_substr($taskText, 0, 500);

        $activeRecipients = dbFetchAll(
            "SELECT DISTINCT pr.volunteer_id, u.name
             FROM participation_requests pr
             JOIN shifts s ON s.id = pr.shift_id
             JOIN users u ON u.id = pr.volunteer_id
             WHERE s.mission_id = ? AND pr.status = ?
               AND s.start_time <= NOW() AND s.end_time > NOW()",
            [$missionId, PARTICIPATION_APPROVED]
        );
        $activeIds = array_map('intval', array_column($activeRecipients, 'volunteer_id'));
        $requestedIds = post('request_scope') === 'all'
            ? $activeIds
            : array_values(array_intersect($activeIds, array_map('intval', (array)($_POST['volunteers'] ?? []))));

        if ($taskText === '') {
            setFlash('warning', 'Γράψτε το κείμενο της εντολής πριν την αποστολή.');
        } elseif (empty($requestedIds)) {
            setFlash('warning', 'Επιλέξτε τουλάχιστον έναν εθελοντή με ενεργή βάρδια.');
        } else {
            createMissionOrderAndNotify(
                $missionId, $mission['title'], 'task', $user['id'], $requestedIds,
                '📋 Νέα Εντολή — ' . $mission['title'],
                $taskText,
                'Δόθηκε εντολή σε εθελοντές της αποστολής «' . $mission['title'] . '»: «' . $taskText . '».',
                $taskText
            );
            logAudit('request_mission_task', 'missions', $missionId, null, ['recipient_ids' => $requestedIds, 'task_text' => $taskText]);
            setFlash('success', 'Στάλθηκε εντολή σε ' . count($requestedIds) . ' ενεργούς εθελοντές.');
        }
        redirect('war-room.php?id=' . $missionId);
    } elseif (post('action') === 'global_message') {
        if (!$canManageWarRoom) {
            setFlash('error', 'Δεν έχετε δικαίωμα να στείλετε καθολικό μήνυμα.');
            redirect('war-room.php?id=' . $missionId);
        }

        $broadcastText = trim((string) post('global_message_text'));
        $broadcastText = mb_substr($broadcastText, 0, 500);

        if ($broadcastText === '') {
            setFlash('warning', 'Γράψτε ένα μήνυμα πριν την αποστολή.');
        } else {
            $recipients = dbFetchAll(
                "SELECT DISTINCT pr.volunteer_id FROM participation_requests pr
                 JOIN shifts s ON s.id = pr.shift_id
                 WHERE s.mission_id = ? AND pr.status = ?",
                [$missionId, PARTICIPATION_APPROVED]
            );
            // createMissionOrderAndNotify() itself never excludes the creator from
            // the real-recipient loop (none of its other 4 callers needed to) — the
            // exclusion has to happen here, same as the old hand-rolled loop did.
            $recipientIds = array_values(array_diff(
                array_map('intval', array_column($recipients, 'volunteer_id')),
                [(int) $user['id']]
            ));

            createMissionOrderAndNotify(
                $missionId, $mission['title'], 'message', $user['id'], $recipientIds,
                '📢 Καθολικό μήνυμα — ' . $mission['title'],
                $broadcastText,
                'Στάλθηκε καθολικό μήνυμα στην αποστολή «' . $mission['title'] . '».',
                $broadcastText
            );
            logAudit('global_message_war_room', 'missions', $missionId, null, ['message' => $broadcastText]);
            setFlash('success', 'Το καθολικό μήνυμα εστάλη σε ' . count($recipientIds) . ' εθελοντές.');
        }
        redirect('war-room.php?id=' . $missionId);
    } elseif (post('action') === 'create_team') {
        if (!$canManageWarRoom) {
            setFlash('error', 'Δεν έχετε δικαίωμα να δημιουργήσετε ομάδες.');
            redirect('war-room.php?id=' . $missionId);
        }

        $approvedVolunteers = dbFetchAll(
            "SELECT DISTINCT pr.volunteer_id, u.name
             FROM participation_requests pr
             JOIN shifts s ON s.id = pr.shift_id
             JOIN users u ON u.id = pr.volunteer_id
             WHERE s.mission_id = ? AND pr.status = ?",
            [$missionId, PARTICIPATION_APPROVED]
        );
        $namesByUserId = array_column($approvedVolunteers, 'name', 'volunteer_id');
        $approvedIds = array_map('intval', array_column($approvedVolunteers, 'volunteer_id'));
        $assignedIds = array_map('intval', array_column(
            dbFetchAll("SELECT user_id FROM mission_team_members WHERE mission_id = ?", [$missionId]),
            'user_id'
        ));
        $eligibleIds = array_diff($approvedIds, $assignedIds);

        $memberIds = array_values(array_unique(array_intersect(
            array_map('intval', (array)($_POST['member_ids'] ?? [])),
            $eligibleIds
        )));
        $leaderId = (int) post('leader_id');

        if (empty($memberIds)) {
            setFlash('warning', 'Επιλέξτε τουλάχιστον έναν διαθέσιμο εθελοντή για την ομάδα.');
        } elseif (!in_array($leaderId, $memberIds, true)) {
            setFlash('warning', 'Ο υπεύθυνος πρέπει να είναι μέλος της ομάδας.');
        } else {
            $teamCount = (int) dbFetchValue("SELECT COUNT(*) FROM mission_teams WHERE mission_id = ?", [$missionId]);
            $codename = MISSION_TEAM_CODENAMES[$teamCount % count(MISSION_TEAM_CODENAMES)];

            $teamNumber = null;
            for ($attempt = 0; $attempt < 50; $attempt++) {
                $candidate = random_int(10, 99);
                $exists = dbFetchValue(
                    "SELECT COUNT(*) FROM mission_teams WHERE mission_id = ? AND team_number = ?",
                    [$missionId, $candidate]
                );
                if (!$exists) { $teamNumber = $candidate; break; }
            }

            if ($teamNumber === null) {
                setFlash('error', 'Δεν ήταν δυνατή η δημιουργία μοναδικού αριθμού ομάδας. Δοκιμάστε ξανά.');
            } else {
                $teamId = dbInsert(
                    "INSERT INTO mission_teams (mission_id, codename, team_number, leader_id, created_by, created_at) VALUES (?, ?, ?, ?, ?, NOW())",
                    [$missionId, $codename, $teamNumber, $leaderId, $user['id']]
                );
                foreach ($memberIds as $memberId) {
                    dbInsert(
                        "INSERT INTO mission_team_members (team_id, mission_id, user_id, added_at) VALUES (?, ?, ?, NOW())",
                        [$teamId, $missionId, $memberId]
                    );
                }
                logAudit('create_mission_team', 'mission_teams', $teamId, null, ['mission_id' => $missionId, 'member_ids' => $memberIds, 'leader_id' => $leaderId]);
                notifyMissionTeamMembers($missionId, $mission['title'], $codename, $teamNumber, $memberIds, $leaderId, $namesByUserId);
                setFlash('success', 'Δημιουργήθηκε η ομάδα ' . $codename . ' ' . $teamNumber . '.');
            }
        }
        redirect('war-room.php?id=' . $missionId);
    } elseif (post('action') === 'update_team') {
        if (!$canManageWarRoom) {
            setFlash('error', 'Δεν έχετε δικαίωμα να επεξεργαστείτε ομάδες.');
            redirect('war-room.php?id=' . $missionId);
        }

        $teamId = (int) post('team_id');
        $team = dbFetchOne("SELECT * FROM mission_teams WHERE id = ? AND mission_id = ?", [$teamId, $missionId]);
        if (!$team) {
            setFlash('error', 'Η ομάδα δεν βρέθηκε.');
            redirect('war-room.php?id=' . $missionId);
        }

        $approvedVolunteers = dbFetchAll(
            "SELECT DISTINCT pr.volunteer_id, u.name
             FROM participation_requests pr
             JOIN shifts s ON s.id = pr.shift_id
             JOIN users u ON u.id = pr.volunteer_id
             WHERE s.mission_id = ? AND pr.status = ?",
            [$missionId, PARTICIPATION_APPROVED]
        );
        $namesByUserId = array_column($approvedVolunteers, 'name', 'volunteer_id');
        $approvedIds = array_map('intval', array_column($approvedVolunteers, 'volunteer_id'));
        $assignedElsewhereIds = array_map('intval', array_column(
            dbFetchAll("SELECT user_id FROM mission_team_members WHERE mission_id = ? AND team_id != ?", [$missionId, $teamId]),
            'user_id'
        ));
        $eligibleIds = array_diff($approvedIds, $assignedElsewhereIds);

        $memberIds = array_values(array_unique(array_intersect(
            array_map('intval', (array)($_POST['member_ids'] ?? [])),
            $eligibleIds
        )));
        $leaderId = (int) post('leader_id');

        if (empty($memberIds)) {
            setFlash('warning', 'Επιλέξτε τουλάχιστον έναν εθελοντή για την ομάδα.');
        } elseif (!in_array($leaderId, $memberIds, true)) {
            setFlash('warning', 'Ο υπεύθυνος πρέπει να είναι μέλος της ομάδας.');
        } else {
            $oldMemberIds = array_map('intval', array_column(
                dbFetchAll("SELECT user_id FROM mission_team_members WHERE team_id = ?", [$teamId]),
                'user_id'
            ));
            dbExecute("DELETE FROM mission_team_members WHERE team_id = ?", [$teamId]);
            foreach ($memberIds as $memberId) {
                dbInsert(
                    "INSERT INTO mission_team_members (team_id, mission_id, user_id, added_at) VALUES (?, ?, ?, NOW())",
                    [$teamId, $missionId, $memberId]
                );
            }
            dbExecute("UPDATE mission_teams SET leader_id = ?, updated_at = NOW() WHERE id = ?", [$leaderId, $teamId]);
            logAudit('update_mission_team', 'mission_teams', $teamId, ['member_ids' => $oldMemberIds], ['member_ids' => $memberIds, 'leader_id' => $leaderId]);
            notifyMissionTeamMembers($missionId, $mission['title'], $team['codename'], (int)$team['team_number'], $memberIds, $leaderId, $namesByUserId);
            setFlash('success', 'Η ομάδα ' . $team['codename'] . ' ' . $team['team_number'] . ' ενημερώθηκε.');
        }
        redirect('war-room.php?id=' . $missionId);
    } elseif (post('action') === 'delete_team') {
        if (!$canManageWarRoom) {
            setFlash('error', 'Δεν έχετε δικαίωμα να διαλύσετε ομάδες.');
            redirect('war-room.php?id=' . $missionId);
        }

        $teamId = (int) post('team_id');
        $team = dbFetchOne("SELECT * FROM mission_teams WHERE id = ? AND mission_id = ?", [$teamId, $missionId]);
        if ($team) {
            $formerMembers = dbFetchAll(
                "SELECT mtm.user_id, u.name FROM mission_team_members mtm JOIN users u ON u.id = mtm.user_id WHERE mtm.team_id = ?",
                [$teamId]
            );
            dbExecute("DELETE FROM mission_teams WHERE id = ?", [$teamId]);
            logAudit('delete_mission_team', 'mission_teams', $teamId, ['mission_id' => $missionId], null);

            $teamLabel = $team['codename'] . ' ' . $team['team_number'];
            $warRoomUrl = rtrim(BASE_URL, '/') . '/war-room.php?id=' . $missionId;
            foreach ($formerMembers as $member) {
                sendNotification(
                    (int)$member['user_id'],
                    '🔷 Διάλυση ομάδας',
                    'Η ομάδα «' . $teamLabel . '» της αποστολής «' . $mission['title'] . '» διαλύθηκε.',
                    'warning', '', ['url' => $warRoomUrl]
                );
            }
            setFlash('success', 'Η ομάδα ' . $teamLabel . ' διαλύθηκε.');
        } else {
            setFlash('error', 'Η ομάδα δεν βρέθηκε.');
        }
        redirect('war-room.php?id=' . $missionId);
    } elseif (post('action') === 'report_shortage') {
        if (!$isApprovedParticipant) {
            setFlash('error', 'Μόνο εγκεκριμένοι εθελοντές μπορούν να υποβάλουν αναφορά έλλειψης.');
            redirect('war-room.php?id=' . $missionId);
        }

        $allowedTypes = ['people', 'equipment', 'medical', 'vehicle', 'other'];
        $allowedSeverities = ['low', 'medium', 'high', 'critical'];
        $shortageType = post('shortage_type');
        $severity = post('severity');
        $title = mb_substr(trim((string) post('title')), 0, 255);
        $description = mb_substr(trim((string) post('description')), 0, 2000);

        if (!in_array($shortageType, $allowedTypes, true) || !in_array($severity, $allowedSeverities, true)) {
            setFlash('error', 'Μη έγκυρα στοιχεία αναφοράς.');
        } elseif ($title === '' || $description === '') {
            setFlash('warning', 'Συμπληρώστε τίτλο και περιγραφή πριν την υποβολή.');
        } else {
            $teamId = getUserTeamIdForMission($missionId, $user['id']);
            $reportId = dbInsert(
                "INSERT INTO mission_shortage_reports (mission_id, reporter_id, team_id, shortage_type, severity, title, description, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW())",
                [$missionId, $user['id'], $teamId, $shortageType, $severity, $title, $description]
            );
            logAudit('report_mission_shortage', 'mission_shortage_reports', $reportId, null, ['mission_id' => $missionId, 'severity' => $severity]);

            $recipientIds = getMissionCommandStaffIds($missionId, $mission['responsible_user_id'] ? (int) $mission['responsible_user_id'] : null, (int) $user['id']);
            $warRoomUrl = rtrim(BASE_URL, '/') . '/war-room.php?id=' . $missionId;
            $severityLabel = SHORTAGE_SEVERITY_LABELS[$severity] ?? $severity;
            $typeLabel = SHORTAGE_TYPE_LABELS[$shortageType] ?? $shortageType;
            $notifTitle = '⚠️ Αναφορά Έλλειψης — ' . $mission['title'];
            $notifMessage = h($user['name']) . ' ανέφερε έλλειψη (' . $typeLabel . ', σοβαρότητα: ' . $severityLabel . '): «' . $title . '».';
            $isLoud = in_array($severity, ['high', 'critical'], true);
            foreach ($recipientIds as $recipientId) {
                $pushData = ['url' => $warRoomUrl, 'tag' => 'shortage-report-mission-' . $missionId];
                if ($isLoud) {
                    $pushData['bannerMission'] = $missionId;
                    $pushData['vibrate'] = [300, 100, 300, 100, 500];
                }
                // High/critical is mandatory (empty code, same as orders/global-message/
                // needs_help) so it can never be silently muted by an admin's own
                // preference — low/medium uses the configurable code instead.
                sendNotification($recipientId, $notifTitle, $notifMessage, $isLoud ? 'danger' : 'info', $isLoud ? '' : 'mission_shortage_report', $pushData);
            }
            setFlash('success', 'Η αναφορά έλλειψης υποβλήθηκε.');
        }
        redirect('war-room.php?id=' . $missionId);
    } elseif (post('action') === 'toggle_field_mode') {
        $newFieldMode = $fieldMode ? '0' : '1';
        setcookie('wr_field_mode', $newFieldMode, [
            'expires' => time() + 31536000, 'path' => '/',
            'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            'httponly' => true, 'samesite' => 'Lax',
        ]);
        redirect('war-room.php?id=' . $missionId);
    }
}

$hasFieldStatus = (bool)dbFetchValue(
    "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'participation_requests' AND COLUMN_NAME = 'field_status'"
);
$fieldStatusColumns = $hasFieldStatus ? ', pr.field_status, pr.field_status_updated_at' : ', NULL AS field_status, NULL AS field_status_updated_at';
$participants = dbFetchAll(
    "SELECT pr.id AS pr_id, pr.volunteer_id, pr.attended{$fieldStatusColumns},
            u.name, u.phone, s.id AS shift_id, s.start_time, s.end_time,
            (SELECT MAX(vp.created_at) FROM volunteer_pings vp WHERE vp.user_id = pr.volunteer_id AND vp.shift_id = pr.shift_id) AS last_ping_at
     FROM participation_requests pr
     JOIN users u ON u.id = pr.volunteer_id
     JOIN shifts s ON s.id = pr.shift_id
     WHERE s.mission_id = ? AND pr.status = ?
     ORDER BY s.start_time, u.name",
    [$missionId, PARTICIPATION_APPROVED]
);
$myAssignments = array_values(array_filter($participants, fn($participant) => (int)$participant['volunteer_id'] === (int)$user['id']));
$onlinePresenceIds = loadOnlinePresenceUserIds($missionId);

$shifts = dbFetchAll(
    "SELECT s.*, COUNT(CASE WHEN pr.status = '" . PARTICIPATION_APPROVED . "' THEN 1 END) AS approved_count,
            COUNT(CASE WHEN pr.status = '" . PARTICIPATION_PENDING . "' THEN 1 END) AS pending_count
     FROM shifts s
     LEFT JOIN participation_requests pr ON pr.shift_id = s.id
     WHERE s.mission_id = ?
     GROUP BY s.id
     ORDER BY s.start_time",
    [$missionId]
);

$loadPins = function () use ($missionId, $hasFieldStatus) {
    try {
        $field = $hasFieldStatus ? ', pr.field_status' : ', NULL AS field_status';
        return dbFetchAll(
            "SELECT vp.user_id, vp.shift_id, vp.lat, vp.lng, vp.created_at, u.name{$field}
             FROM volunteer_pings vp
             JOIN shifts s ON s.id = vp.shift_id
             JOIN users u ON u.id = vp.user_id
             LEFT JOIN participation_requests pr ON pr.shift_id = vp.shift_id AND pr.volunteer_id = vp.user_id
             WHERE s.mission_id = ?
               AND vp.created_at >= DATE_SUB(NOW(), INTERVAL 2 HOUR)
               AND vp.id = (SELECT MAX(vp2.id) FROM volunteer_pings vp2 WHERE vp2.user_id = vp.user_id AND vp2.shift_id = vp.shift_id)
             ORDER BY vp.created_at DESC",
            [$missionId]
        );
    } catch (Exception $e) {
        return [];
    }
};

if (get('ajax') === '1') {
    header('Content-Type: application/json');

    // Live-presence heartbeat: every open War Room tab hits this branch every
    // 15s, so it doubles as the "I'm still here" signal — no separate endpoint
    // or client timer needed.
    dbExecute(
        "INSERT INTO mission_presence (mission_id, user_id, last_seen_at) VALUES (?, ?, NOW())
         ON DUPLICATE KEY UPDATE last_seen_at = NOW()",
        [$missionId, $user['id']]
    );

    $pins = array_map(fn($pin) => [
        'lat' => (float)$pin['lat'], 'lng' => (float)$pin['lng'], 'name' => $pin['name'],
        'status' => $pin['field_status'], 'time' => date('H:i', strtotime($pin['created_at']))
    ], $loadPins());

    $bannerAfterId = (int) get('banner_after');
    $bannerRow = dbFetchOne(
        "SELECT id, message, data FROM notifications WHERE user_id = ? AND id > ? AND JSON_EXTRACT(data, '$.bannerMission') = ? ORDER BY id DESC LIMIT 1",
        [$user['id'], $bannerAfterId, $missionId]
    );
    $bannerOrderId = null;
    if ($bannerRow) {
        $bannerData = json_decode((string) $bannerRow['data'], true);
        $rawOrderId = $bannerData['orderId'] ?? null;
        if ($rawOrderId) {
            $acked = (bool) dbFetchValue(
                "SELECT acknowledged_at FROM mission_order_recipients WHERE order_id = ? AND user_id = ?",
                [$rawOrderId, $user['id']]
            );
            if (!$acked) {
                $bannerOrderId = (int) $rawOrderId;
            }
        }
    }

    $dispatches = loadMissionDispatchesForUser($missionId, (int)$user['id'], $canManageWarRoom, $isApprovedParticipant);
    $photos = loadMissionPhotosForUser($missionId, (int)$user['id'], $canManageWarRoom);
    $myTasks = loadMyTaskOrdersForUser($missionId, (int)$user['id']);
    $shortageReports = $canManageWarRoom ? loadUnresolvedShortageReportsForMission($missionId) : [];
    $onlinePresence = loadOnlinePresenceUserIds($missionId);

    echo json_encode([
        'pins' => $pins,
        'time' => date('H:i:s'),
        'banner' => $bannerRow ? ['id' => (int)$bannerRow['id'], 'message' => $bannerRow['message'], 'orderId' => $bannerOrderId] : null,
        'dispatches' => $dispatches,
        'media' => $photos,
        'myTasks' => $myTasks,
        'shortageReports' => $shortageReports,
        'onlinePresence' => $onlinePresence,
    ]);
    exit;
}

$pins = array_map(fn($pin) => [
    'lat' => (float)$pin['lat'], 'lng' => (float)$pin['lng'], 'name' => $pin['name'],
    'status' => $pin['field_status'], 'time' => date('H:i', strtotime($pin['created_at']))
], $loadPins());

// Baseline for the live request banner: ignore anything sent before this page load,
// only pop the banner for admin-initiated alerts (location requests, dispatch points, ...)
// that arrive from now on. Any sendNotification() pushData with 'bannerMission' => $missionId
// qualifies, so future request types (photo/video/relocate) plug in without changes here.
$bannerSinceId = (int) dbFetchValue(
    "SELECT COALESCE(MAX(id), 0) FROM notifications WHERE user_id = ? AND JSON_EXTRACT(data, '$.bannerMission') = ?",
    [$user['id'], $missionId]
);

$dispatches = loadMissionDispatchesForUser($missionId, (int)$user['id'], $canManageWarRoom, $isApprovedParticipant);
$photos = loadMissionPhotosForUser($missionId, (int)$user['id'], $canManageWarRoom);
$myTasks = loadMyTaskOrdersForUser($missionId, (int)$user['id']);
$shortageReports = $canManageWarRoom ? loadUnresolvedShortageReportsForMission($missionId) : [];

$firstShift = $shifts[0]['start_time'] ?? $mission['start_datetime'];
$lastShift = !empty($shifts) ? end($shifts)['end_time'] : $mission['end_datetime'];
$now = time();
$timeState = strtotime($firstShift) > $now ? 'upcoming' : (strtotime($lastShift) < $now ? 'overdue' : 'active');
$activeParticipants = array_values(array_filter($participants, fn($participant) =>
    strtotime($participant['start_time']) <= $now && strtotime($participant['end_time']) > $now
));

// ── Mission teams ─────────────────────────────────────────────────────────
$teamRows = dbFetchAll(
    "SELECT mt.id, mt.codename, mt.team_number, mt.leader_id, l.name AS leader_name,
            mtm.user_id, u.name AS member_name
     FROM mission_teams mt
     LEFT JOIN users l ON l.id = mt.leader_id
     LEFT JOIN mission_team_members mtm ON mtm.team_id = mt.id
     LEFT JOIN users u ON u.id = mtm.user_id
     WHERE mt.mission_id = ?
     ORDER BY mt.created_at, u.name",
    [$missionId]
);
$teams = [];
foreach ($teamRows as $row) {
    $tid = (int)$row['id'];
    if (!isset($teams[$tid])) {
        $teams[$tid] = [
            'id' => $tid,
            'codename' => $row['codename'],
            'team_number' => $row['team_number'],
            'leader_id' => $row['leader_id'] !== null ? (int)$row['leader_id'] : null,
            'leader_name' => $row['leader_name'],
            'members' => [],
        ];
    }
    if ($row['user_id'] !== null) {
        $teams[$tid]['members'][] = ['user_id' => (int)$row['user_id'], 'name' => $row['member_name']];
    }
}

$teamLabelByUserId = [];
foreach ($teams as $team) {
    $label = $team['codename'] . ' ' . $team['team_number'];
    foreach ($team['members'] as $member) {
        $teamLabelByUserId[$member['user_id']] = $label;
    }
}

$distinctApprovedById = [];
foreach ($participants as $participant) {
    $vid = (int)$participant['volunteer_id'];
    if (!isset($distinctApprovedById[$vid])) {
        $distinctApprovedById[$vid] = ['user_id' => $vid, 'name' => $participant['name']];
    }
}
$unassignedApproved = array_values(array_filter(
    $distinctApprovedById,
    fn($p) => !isset($teamLabelByUserId[$p['user_id']])
));

$myTeamId = null;
foreach ($teams as $team) {
    foreach ($team['members'] as $member) {
        if ($member['user_id'] === (int)$user['id']) {
            $myTeamId = $team['id'];
            break 2;
        }
    }
}
$chatTeams = $canManageWarRoom
    ? array_values($teams)
    : array_values(array_filter($teams, fn($t) => $t['id'] === $myTeamId));

$pageTitle = 'War Room — ' . $mission['title'];
$currentPage = 'war-room';
include __DIR__ . '/includes/header.php';
?>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<style>
    #warRoomMap { height: 520px; border-radius: 12px; }
    .war-room-hero { background: linear-gradient(135deg, #172554, #b91c1c); color: #fff; border-radius: 14px; }
    .war-room-hero h1 { color: #fff; font-weight: 700; }
    .participant-row { border-left: 4px solid #e2e8f0; }
    .participant-row.needs-help { border-left-color: #dc2626; }
    .presence-dot { display: inline-block; width: 9px; height: 9px; border-radius: 50%; margin-right: 4px; }
    .presence-dot.presence-online { background: #28a745; }
    .presence-dot.presence-offline { background: #adb5bd; }
    .war-room-banner { display: none; align-items: center; gap: 10px; background: #000; border-bottom: 2px solid #dc2626; padding: 8px 12px; }
    .war-room-banner-track { flex: 1; overflow: hidden; white-space: nowrap; position: relative; height: 1.6em; }
    .war-room-banner-track span { display: inline-block; position: absolute; white-space: nowrap; padding-left: 100%; color: #ff3b30; font-weight: 700; text-transform: uppercase; letter-spacing: .02em; animation: warRoomBannerScroll 14s linear infinite; }
    @keyframes warRoomBannerScroll { 0% { transform: translateX(0); } 100% { transform: translateX(-100%); } }
    .war-room-banner .bi-broadcast { color: #ff3b30; }
    .war-room-banner-close { background: transparent; border: none; color: #ff3b30; font-size: 1.3rem; line-height: 1; cursor: pointer; padding: 0 4px; flex-shrink: 0; }
    @keyframes warRoomPulseRed { 0%, 100% { box-shadow: 0 0 0 0 rgba(220,53,69,0); } 50% { box-shadow: 0 0 0 10px rgba(220,53,69,0.4); } }
</style>

<div class="war-room-hero p-4 mb-4 shadow-sm">
    <div class="d-flex flex-wrap justify-content-between gap-3 align-items-start">
        <div>
            <div class="text-uppercase small fw-semibold opacity-75 mb-1"><i class="bi bi-broadcast-pin me-1"></i>War Room · Επιχειρησιακό Κέντρο Αποστολής</div>
            <h1 class="h3 mb-2"><?= h($mission['title']) ?></h1>
            <div class="small opacity-75"><i class="bi bi-geo-alt me-1"></i><?= h($mission['location']) ?> · <?= formatDateTime($firstShift) ?> έως <?= formatDateTime($lastShift) ?></div>
        </div>
        <div class="d-flex gap-2 align-items-center">
            <span class="badge fs-6 <?= $timeState === 'active' ? 'bg-success' : ($timeState === 'upcoming' ? 'bg-info text-dark' : 'bg-warning text-dark') ?>">
                <?= $timeState === 'active' ? 'ΣΕ ΕΞΕΛΙΞΗ' : ($timeState === 'upcoming' ? 'ΠΡΟΣΕΧΩΣ' : 'ΕΚΚΡΕΜΕΙ ΚΛΕΙΣΙΜΟ') ?>
            </span>
            <?php if ($canManageWarRoom && !$fieldMode): ?>
            <button type="button" class="btn btn-outline-light" data-bs-toggle="modal" data-bs-target="#reportModal"><i class="bi bi-stopwatch me-1"></i>Αναφορά Χρόνων</button>
            <button type="button" class="btn btn-outline-light" onclick="window.open('mission-report-print.php?mission_id=<?= $missionId ?>', '_blank')"><i class="bi bi-printer me-1"></i>Αναφορά PDF</button>
            <?php endif; ?>
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="toggle_field_mode">
                <button type="submit" class="btn btn-outline-light">
                    <i class="bi bi-<?= $fieldMode ? 'grid-3x3-gap' : 'geo-alt' ?> me-1"></i><?= $fieldMode ? 'Πλήρης Προβολή' : 'Λειτουργία Πεδίου' ?>
                </button>
            </form>
            <a href="ops-dashboard.php" class="btn btn-light"><i class="bi bi-arrow-left me-1"></i>Επιχειρησιακό</a>
        </div>
    </div>
</div>

<?= showFlash() ?>

<div id="warRoomBanner" class="war-room-banner">
    <i class="bi bi-broadcast"></i>
    <div class="war-room-banner-track"><span id="warRoomBannerText"></span></div>
    <button type="button" id="warRoomBannerAckBtn" class="btn btn-sm btn-light fw-semibold d-none" style="flex-shrink:0;">Ελήφθη</button>
    <button type="button" id="warRoomBannerClose" class="war-room-banner-close" aria-label="Κλείσιμο">&times;</button>
</div>

<?php if (!$fieldMode): ?>
<div class="row g-4 mb-4">
    <div class="col-lg-8">
        <div class="card shadow-sm h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-map me-1"></i>Ζωντανός χάρτης αποστολής</h5>
                <small class="text-muted">Ενημέρωση: <span id="mapRefresh"><?= date('H:i:s') ?></span></small>
            </div>
            <div class="card-body p-0">
                <div id="warRoomMap"></div>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card shadow-sm h-100">
            <div class="card-header"><h5 class="mb-0"><i class="bi bi-camera-fill me-1"></i>Φωτογραφίες &amp; Βίντεο Πεδίου</h5></div>
            <div class="card-body d-flex flex-column" style="height:520px;">
                <?php if ($isApprovedParticipant): ?>
                <div class="d-flex gap-2 mb-2">
                    <label class="btn btn-primary w-100 mb-0">
                        <i class="bi bi-camera-fill me-1"></i>Φωτογραφία
                        <input type="file" id="photoCaptureInput" accept="image/*" capture="environment" class="d-none">
                    </label>
                    <label class="btn btn-outline-primary w-100 mb-0">
                        <i class="bi bi-images me-1"></i>Συλλογή
                        <input type="file" id="photoGalleryInput" accept="image/*" class="d-none">
                    </label>
                </div>
                <div class="d-flex gap-2 mb-2">
                    <label class="btn btn-primary w-100 mb-0">
                        <i class="bi bi-camera-reels-fill me-1"></i>Βίντεο
                        <input type="file" id="videoCaptureInput" accept="video/*" capture="environment" class="d-none">
                    </label>
                    <label class="btn btn-outline-primary w-100 mb-0">
                        <i class="bi bi-images me-1"></i>Συλλογή
                        <input type="file" id="videoGalleryInput" accept="video/*" class="d-none">
                    </label>
                </div>
                <div class="small mb-2" id="mediaUploadStatus"></div>
                <?php endif; ?>
                <div id="mediaList" class="flex-grow-1 overflow-auto"></div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="row g-4">
    <?php if (!$fieldMode): ?>
    <div class="col-lg-8">
        <div class="card shadow-sm mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-diagram-3 me-1"></i>Ομάδες Αποστολής</h5>
                <?php if ($canManageWarRoom && !empty($unassignedApproved)): ?>
                <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#createTeamModal">
                    <i class="bi bi-plus-lg me-1"></i>Νέα Ομάδα
                </button>
                <?php endif; ?>
            </div>
            <div class="list-group list-group-flush">
                <?php foreach ($teams as $team): ?>
                <div class="list-group-item">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                        <div>
                            <span class="badge bg-dark fs-6 me-2"><?= h($team['codename'] . ' ' . $team['team_number']) ?></span>
                            <?php if ($team['leader_name']): ?>
                            <span class="small text-muted"><i class="bi bi-star-fill text-warning me-1"></i><?= h($team['leader_name']) ?></span>
                            <?php endif; ?>
                            <div class="small mt-2">
                                <?php foreach ($team['members'] as $member): ?>
                                <span class="badge bg-light text-dark border me-1 mb-1"><?= h($member['name']) ?><?= $member['user_id'] === $team['leader_id'] ? ' ⭐' : '' ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php if ($canManageWarRoom): ?>
                        <div class="d-flex gap-1">
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editTeamModal-<?= $team['id'] ?>" title="Επεξεργασία"><i class="bi bi-pencil"></i></button>
                            <form method="post" onsubmit="return confirm('Διάλυση ομάδας <?= h(addslashes($team['codename'] . ' ' . $team['team_number'])) ?>;')">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="delete_team">
                                <input type="hidden" name="team_id" value="<?= $team['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Διάλυση"><i class="bi bi-x-lg"></i></button>
                            </form>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php if (empty($teams)): ?>
                <div class="list-group-item text-muted">Δεν έχουν δημιουργηθεί ομάδες ακόμη.</div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header"><h5 class="mb-0"><i class="bi bi-people me-1"></i>Εγκεκριμένοι εθελοντές (<?= count($participants) ?>)</h5></div>
            <div class="list-group list-group-flush">
                <?php foreach ($participants as $participant): ?>
                <?php $status = $participant['field_status'] ?? ''; ?>
                <div class="list-group-item participant-row <?= $status === 'needs_help' ? 'needs-help' : '' ?> d-flex justify-content-between align-items-center gap-2 flex-wrap">
                    <div><span id="presence-<?= (int)$participant['volunteer_id'] ?>" class="presence-dot <?= in_array((int)$participant['volunteer_id'], $onlinePresenceIds, true) ? 'presence-online' : 'presence-offline' ?>" title="<?= in_array((int)$participant['volunteer_id'], $onlinePresenceIds, true) ? 'Online' : 'Offline' ?>"></span><strong><?= h($participant['name']) ?></strong><?php if (isset($teamLabelByUserId[(int)$participant['volunteer_id']])): ?> <span class="badge bg-dark"><?= h($teamLabelByUserId[(int)$participant['volunteer_id']]) ?></span><?php endif; ?><br><small class="text-muted"><?= formatDateTime($participant['start_time']) ?> – <?= date('H:i', strtotime($participant['end_time'])) ?><?= $participant['last_ping_at'] ? ' · Τελευταίο στίγμα: ' . date('H:i', strtotime($participant['last_ping_at'])) : ' · Δεν υπάρχει στίγμα' ?></small></div>
                    <span class="badge <?= $status === 'needs_help' ? 'bg-danger' : ($status === 'on_site' ? 'bg-success' : ($status === 'on_way' ? 'bg-warning text-dark' : 'bg-secondary')) ?>">
                        <?= $status === 'needs_help' ? 'Χρειάζεται βοήθεια' : ($status === 'on_site' ? 'Επί τόπου' : ($status === 'on_way' ? 'Σε κίνηση' : 'Χωρίς κατάσταση')) ?>
                    </span>
                </div>
                <?php endforeach; ?>
                <?php if (empty($participants)): ?><div class="list-group-item text-muted">Δεν υπάρχουν εγκεκριμένοι εθελοντές.</div><?php endif; ?>
            </div>
        </div>

        <?php if ($canManageWarRoom): ?>
        <div class="row g-4 mt-0">
            <div class="col-md-6">
                <div class="card shadow-sm h-100 border-warning">
                    <div class="card-header bg-warning bg-opacity-25"><h5 class="mb-0"><i class="bi bi-bell-fill me-1"></i>Ζήτηση στίγματος</h5></div>
                    <div class="card-body">
                        <?php if (empty($activeParticipants)): ?>
                            <p class="text-muted mb-0">Δεν υπάρχουν εθελοντές με βάρδια σε εξέλιξη αυτή τη στιγμή.</p>
                        <?php else: ?>
                            <p class="small text-muted">Στέλνει άμεση ειδοποίηση push με έντονη δόνηση. Ο ήχος εξαρτάται από τις ρυθμίσεις της συσκευής του εθελοντή.</p>
                            <form method="post">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="request_location">
                                <button type="submit" name="request_scope" value="all" class="btn btn-warning w-100 fw-semibold mb-3">
                                    <i class="bi bi-broadcast me-1"></i>Ζήτηση από όλους τους ενεργούς (<?= count($activeParticipants) ?>)
                                </button>
                                <div class="small fw-semibold mb-2">Ή επιλέξτε εθελοντές:</div>
                                <div class="border rounded p-2 mb-3" style="max-height:190px;overflow:auto;">
                                    <?php foreach ($activeParticipants as $participant): ?>
                                    <label class="form-check d-flex align-items-center justify-content-between gap-2 py-1">
                                        <span><input class="form-check-input me-2" type="checkbox" name="volunteers[]" value="<?= $participant['volunteer_id'] ?>"><?= h($participant['name']) ?></span>
                                        <small class="text-muted"><?= $participant['last_ping_at'] ? date('H:i', strtotime($participant['last_ping_at'])) : 'χωρίς στίγμα' ?></small>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                                <button type="submit" name="request_scope" value="selected" class="btn btn-outline-warning w-100 fw-semibold">
                                    <i class="bi bi-person-check me-1"></i>Ζήτηση από επιλεγμένους
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="card shadow-sm h-100 border-warning">
                    <div class="card-header bg-warning bg-opacity-25"><h5 class="mb-0"><i class="bi bi-camera-fill me-1"></i>Ζήτηση φωτογραφίας</h5></div>
                    <div class="card-body">
                        <?php if (empty($activeParticipants)): ?>
                            <p class="text-muted mb-0">Δεν υπάρχουν εθελοντές με βάρδια σε εξέλιξη αυτή τη στιγμή.</p>
                        <?php else: ?>
                            <p class="small text-muted">Στέλνει άμεση ειδοποίηση push με έντονη δόνηση. Ο ήχος εξαρτάται από τις ρυθμίσεις της συσκευής του εθελοντή.</p>
                            <form method="post">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="request_photo">
                                <button type="submit" name="request_scope" value="all" class="btn btn-warning w-100 fw-semibold mb-3">
                                    <i class="bi bi-broadcast me-1"></i>Ζήτηση από όλους τους ενεργούς (<?= count($activeParticipants) ?>)
                                </button>
                                <div class="small fw-semibold mb-2">Ή επιλέξτε εθελοντές:</div>
                                <div class="border rounded p-2 mb-3" style="max-height:190px;overflow:auto;">
                                    <?php foreach ($activeParticipants as $participant): ?>
                                    <label class="form-check d-flex align-items-center justify-content-between gap-2 py-1">
                                        <span><input class="form-check-input me-2" type="checkbox" name="volunteers[]" value="<?= $participant['volunteer_id'] ?>"><?= h($participant['name']) ?></span>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                                <button type="submit" name="request_scope" value="selected" class="btn btn-outline-warning w-100 fw-semibold">
                                    <i class="bi bi-person-check me-1"></i>Ζήτηση από επιλεγμένους
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="card shadow-sm h-100 border-warning">
                    <div class="card-header bg-warning bg-opacity-25"><h5 class="mb-0"><i class="bi bi-camera-reels-fill me-1"></i>Ζήτηση βίντεο</h5></div>
                    <div class="card-body">
                        <?php if (empty($activeParticipants)): ?>
                            <p class="text-muted mb-0">Δεν υπάρχουν εθελοντές με βάρδια σε εξέλιξη αυτή τη στιγμή.</p>
                        <?php else: ?>
                            <p class="small text-muted">Στέλνει άμεση ειδοποίηση push με έντονη δόνηση. Ο ήχος εξαρτάται από τις ρυθμίσεις της συσκευής του εθελοντή.</p>
                            <form method="post">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="request_video">
                                <button type="submit" name="request_scope" value="all" class="btn btn-warning w-100 fw-semibold mb-3">
                                    <i class="bi bi-broadcast me-1"></i>Ζήτηση από όλους τους ενεργούς (<?= count($activeParticipants) ?>)
                                </button>
                                <div class="small fw-semibold mb-2">Ή επιλέξτε εθελοντές:</div>
                                <div class="border rounded p-2 mb-3" style="max-height:190px;overflow:auto;">
                                    <?php foreach ($activeParticipants as $participant): ?>
                                    <label class="form-check d-flex align-items-center justify-content-between gap-2 py-1">
                                        <span><input class="form-check-input me-2" type="checkbox" name="volunteers[]" value="<?= $participant['volunteer_id'] ?>"><?= h($participant['name']) ?></span>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                                <button type="submit" name="request_scope" value="selected" class="btn btn-outline-warning w-100 fw-semibold">
                                    <i class="bi bi-person-check me-1"></i>Ζήτηση από επιλεγμένους
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="card shadow-sm h-100 border-warning">
                    <div class="card-header bg-warning bg-opacity-25"><h5 class="mb-0"><i class="bi bi-clipboard-check-fill me-1"></i>Γενική Εντολή</h5></div>
                    <div class="card-body">
                        <?php if (empty($activeParticipants)): ?>
                            <p class="text-muted mb-0">Δεν υπάρχουν εθελοντές με βάρδια σε εξέλιξη αυτή τη στιγμή.</p>
                        <?php else: ?>
                            <p class="small text-muted">Στέλνει άμεση ειδοποίηση push με έντονη δόνηση και ελεύθερο κείμενο εντολής. Ο εθελοντής επιβεβαιώνει χειροκίνητα την ολοκλήρωση.</p>
                            <form method="post">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="request_task">
                                <textarea name="task_text" class="form-control mb-2" rows="3" maxlength="500" placeholder="Περιγράψτε την εντολή…" required></textarea>
                                <button type="submit" name="request_scope" value="all" class="btn btn-warning w-100 fw-semibold mb-3">
                                    <i class="bi bi-broadcast me-1"></i>Ζήτηση από όλους τους ενεργούς (<?= count($activeParticipants) ?>)
                                </button>
                                <div class="small fw-semibold mb-2">Ή επιλέξτε εθελοντές:</div>
                                <div class="border rounded p-2 mb-3" style="max-height:190px;overflow:auto;">
                                    <?php foreach ($activeParticipants as $participant): ?>
                                    <label class="form-check d-flex align-items-center justify-content-between gap-2 py-1">
                                        <span><input class="form-check-input me-2" type="checkbox" name="volunteers[]" value="<?= $participant['volunteer_id'] ?>"><?= h($participant['name']) ?></span>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                                <button type="submit" name="request_scope" value="selected" class="btn btn-outline-warning w-100 fw-semibold">
                                    <i class="bi bi-person-check me-1"></i>Ζήτηση από επιλεγμένους
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="card shadow-sm mt-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-activity me-1"></i>Δραστηριότητα</h5>
                <small class="text-muted">Ενημέρωση: <span id="activityRefresh"></span></small>
            </div>
            <div class="card-body">
                <div id="activityList" style="max-height:420px;overflow-y:auto;"><div class="text-muted small">Φόρτωση…</div></div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="<?= $fieldMode ? 'col-lg-6 mx-auto' : 'col-lg-4' ?>">
        <div class="card shadow-sm mb-4 border-primary">
            <div class="card-header bg-primary text-white"><h5 class="mb-0"><i class="bi bi-geo-alt-fill me-1"></i>Το στίγμα μου</h5></div>
            <div class="card-body">
                <?php if (empty($myAssignments)): ?>
                    <p class="text-muted mb-0">Δεν έχετε εγκεκριμένη βάρδια σε αυτή την αποστολή.</p>
                <?php else: ?>
                    <p class="small text-muted">Επιλέξτε τη βάρδια για την οποία βρίσκεστε στο πεδίο.</p>
                    <?php foreach ($myAssignments as $assignment): ?>
                    <button type="button" class="btn btn-primary w-100 mb-2 send-ping" data-shift-id="<?= $assignment['shift_id'] ?>" data-pr-id="<?= $assignment['pr_id'] ?>">
                        <i class="bi bi-send-fill me-1"></i>Αποστολή στίγματος · <?= date('H:i', strtotime($assignment['start_time'])) ?>
                    </button>
                    <div class="small mb-2" id="pingStatus-<?= $assignment['pr_id'] ?>"></div>
                    <?php $myFieldStatus = $assignment['field_status'] ?? null; ?>
                    <div class="small mb-1" id="statusBadge-<?= $assignment['pr_id'] ?>">
                        <?= $myFieldStatus ? h(['on_way' => '🚗 Σε Κίνηση', 'on_site' => '✅ Επί Τόπου', 'needs_help' => '🆘 SOS'][$myFieldStatus] ?? '') : '— Χωρίς κατάσταση' ?>
                    </div>
                    <div class="btn-group w-100 mb-3" role="group" id="statusBtns-<?= $assignment['pr_id'] ?>">
                        <button type="button" class="btn btn-sm <?= $myFieldStatus === 'on_way' ? 'btn-warning' : 'btn-outline-warning' ?>" onclick="setFieldStatus(this, <?= $assignment['pr_id'] ?>, 'on_way')">🚗 Κίνηση</button>
                        <button type="button" class="btn btn-sm <?= $myFieldStatus === 'on_site' ? 'btn-success' : 'btn-outline-success' ?>" onclick="setFieldStatus(this, <?= $assignment['pr_id'] ?>, 'on_site')">✅ Θέση μου</button>
                        <button type="button" class="btn btn-sm <?= $myFieldStatus === 'needs_help' ? 'btn-danger' : 'btn-outline-danger' ?>" onclick="setFieldStatus(this, <?= $assignment['pr_id'] ?>, 'needs_help')">🆘 SOS</button>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="card shadow-sm mb-4 border-primary">
            <div class="card-header bg-primary text-white"><h5 class="mb-0"><i class="bi bi-clipboard-check me-1"></i>Οι Εντολές μου</h5></div>
            <div class="card-body">
                <div id="myTasksList"></div>
            </div>
        </div>

        <?php if ($isApprovedParticipant): ?>
        <div class="card shadow-sm mb-4 border-warning">
            <div class="card-header bg-warning bg-opacity-25"><h5 class="mb-0"><i class="bi bi-exclamation-triangle-fill me-1"></i>Αναφορά Έλλειψης</h5></div>
            <div class="card-body">
                <form method="post">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="report_shortage">
                    <label class="form-label small fw-semibold">Είδος</label>
                    <select name="shortage_type" class="form-select mb-2" required>
                        <?php foreach (SHORTAGE_TYPE_LABELS as $val => $label): ?>
                        <option value="<?= h($val) ?>"><?= h($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label class="form-label small fw-semibold">Σοβαρότητα</label>
                    <select name="severity" class="form-select mb-2" required>
                        <?php foreach (SHORTAGE_SEVERITY_LABELS as $val => $label): ?>
                        <option value="<?= h($val) ?>" <?= $val === 'medium' ? 'selected' : '' ?>><?= h($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" name="title" class="form-control mb-2" maxlength="255" placeholder="Σύντομος τίτλος…" required>
                    <textarea name="description" class="form-control mb-2" rows="3" maxlength="2000" placeholder="Περιγραφή της έλλειψης…" required></textarea>
                    <button type="submit" class="btn btn-warning w-100 fw-semibold"><i class="bi bi-send-fill me-1"></i>Υποβολή Αναφοράς</button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!$fieldMode): ?>
        <div class="card shadow-sm mb-4">
            <div class="card-header"><h5 class="mb-0"><i class="bi bi-calendar-range me-1"></i>Βάρδιες</h5></div>
            <div class="list-group list-group-flush">
                <?php foreach ($shifts as $shift): ?>
                <div class="list-group-item"><strong><?= formatDateTime($shift['start_time']) ?></strong><br><small class="text-muted">έως <?= date('H:i', strtotime($shift['end_time'])) ?> · <?= $shift['approved_count'] ?>/<?= $shift['max_volunteers'] ?> εγκεκριμένοι</small></div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($canManageWarRoom && !$fieldMode): ?>
        <div class="card shadow-sm mb-4 border-danger">
            <div class="card-header bg-danger bg-opacity-10"><h5 class="mb-0"><i class="bi bi-exclamation-triangle-fill me-1 text-danger"></i>Αναφορές Έλλειψης</h5></div>
            <div class="card-body">
                <div id="shortageReportsList"></div>
            </div>
        </div>

        <div class="card shadow-sm mb-4 border-danger">
            <div class="card-header bg-danger bg-opacity-10"><h5 class="mb-0"><i class="bi bi-megaphone-fill me-1 text-danger"></i>Καθολικό Μήνυμα</h5></div>
            <div class="card-body">
                <p class="small text-muted">Εμφανίζεται ως κυλιόμενο μήνυμα (60 δευτ.) σε όσους έχουν ανοιχτό το War Room και στέλνεται ως ειδοποίηση σε όλους τους εγκεκριμένους εθελοντές της αποστολής.</p>
                <form method="post">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="global_message">
                    <textarea name="global_message_text" class="form-control mb-2" rows="3" maxlength="500" placeholder="Γράψτε το μήνυμα προς όλους τους εθελοντές…" required></textarea>
                    <button type="submit" class="btn btn-danger w-100 fw-semibold"><i class="bi bi-send-fill me-1"></i>Αποστολή σε όλους (<?= count($participants) ?>)</button>
                </form>
            </div>
        </div>

        <div class="card shadow-sm mb-4 border-primary">
            <div class="card-header bg-primary bg-opacity-10"><h5 class="mb-0"><i class="bi bi-geo-fill me-1"></i>Αποστολή Στίγματος/Περιοχής</h5></div>
            <div class="card-body">
                <p class="small text-muted">Στείλτε ένα σημείο ή μια περιοχή στον χάρτη — θα εμφανιστεί μόνιμα στον χάρτη των παραληπτών.</p>
                <label class="form-label small fw-semibold">Παραλήπτες</label>
                <select class="form-select mb-3" id="dispatchTeamSelect">
                    <option value="">Όλες οι ομάδες</option>
                    <?php foreach ($teams as $team): ?>
                    <option value="<?= $team['id'] ?>"><?= h($team['codename'] . ' ' . $team['team_number']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="btn btn-primary w-100 fw-semibold" data-bs-toggle="modal" data-bs-target="#dispatchMapModal">
                    <i class="bi bi-pin-map-fill me-1"></i>Αποστολή Στίγματος
                </button>
            </div>
        </div>

        <div class="card border-danger shadow-sm">
            <div class="card-body"><h6><i class="bi bi-shield-exclamation text-danger me-1"></i>Διαχείριση αποστολής</h6>
                <p class="small text-muted">Το κλείσιμο αφαιρεί την αποστολή από το Επιχειρησιακό και σταματά τη λήψη νέων στιγμάτων.</p>
                <form method="post" onsubmit="return confirm('Είστε σίγουρος/η ότι θέλετε να κλείσετε την αποστολή;')">
                    <?= csrfField() ?><input type="hidden" name="action" value="close_mission">
                    <button class="btn btn-danger w-100"><i class="bi bi-x-octagon-fill me-1"></i>Κλείσιμο αποστολής</button>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($canManageWarRoom): ?>
<div class="modal fade" id="createTeamModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-plus-lg me-1"></i>Νέα Ομάδα</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post" class="team-form" data-leader-select="#createTeamLeader">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="create_team">
                <div class="modal-body">
                    <p class="small text-muted">Επιλέξτε μέλη από τους διαθέσιμους (χωρίς ομάδα) εθελοντές.</p>
                    <div class="border rounded p-2 mb-3" style="max-height:220px;overflow:auto;">
                        <?php foreach ($unassignedApproved as $person): ?>
                        <label class="form-check d-flex align-items-center gap-2 py-1">
                            <input class="form-check-input team-member-check" type="checkbox" name="member_ids[]" value="<?= $person['user_id'] ?>" data-name="<?= h($person['name']) ?>">
                            <span><?= h($person['name']) ?></span>
                        </label>
                        <?php endforeach; ?>
                        <?php if (empty($unassignedApproved)): ?>
                        <div class="text-muted small">Δεν υπάρχουν διαθέσιμοι εθελοντές.</div>
                        <?php endif; ?>
                    </div>
                    <label class="form-label small fw-semibold">Υπεύθυνος ομάδας</label>
                    <select class="form-select team-leader-select" name="leader_id" id="createTeamLeader" required>
                        <option value="">Επιλέξτε μέλη πρώτα…</option>
                    </select>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Ακύρωση</button>
                    <button type="submit" class="btn btn-primary">Δημιουργία</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php foreach ($teams as $team): ?>
<?php
    $memberIdsInTeam = array_column($team['members'], 'user_id');
    $editablePool = array_merge(
        $team['members'],
        array_values(array_filter($unassignedApproved, fn($p) => !in_array($p['user_id'], $memberIdsInTeam, true)))
    );
?>
<div class="modal fade" id="editTeamModal-<?= $team['id'] ?>" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-pencil me-1"></i>Επεξεργασία <?= h($team['codename'] . ' ' . $team['team_number']) ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post" class="team-form" data-leader-select="#editTeamLeader-<?= $team['id'] ?>">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="update_team">
                <input type="hidden" name="team_id" value="<?= $team['id'] ?>">
                <div class="modal-body">
                    <div class="border rounded p-2 mb-3" style="max-height:220px;overflow:auto;">
                        <?php foreach ($editablePool as $person): ?>
                        <label class="form-check d-flex align-items-center gap-2 py-1">
                            <input class="form-check-input team-member-check" type="checkbox" name="member_ids[]"
                                   value="<?= $person['user_id'] ?>" data-name="<?= h($person['name']) ?>"
                                   <?= in_array($person['user_id'], $memberIdsInTeam, true) ? 'checked' : '' ?>>
                            <span><?= h($person['name']) ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <label class="form-label small fw-semibold">Υπεύθυνος ομάδας</label>
                    <select class="form-select team-leader-select" name="leader_id" id="editTeamLeader-<?= $team['id'] ?>" required data-current="<?= $team['leader_id'] ?>"></select>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Ακύρωση</button>
                    <button type="submit" class="btn btn-primary">Αποθήκευση</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<div class="card shadow-sm mb-4">
    <div class="card-header"><h5 class="mb-0"><i class="bi bi-chat-dots me-1"></i>Συνομιλία</h5></div>
    <div class="card-body">
        <ul class="nav nav-pills mb-3 flex-wrap" id="chatRoomTabs">
            <li class="nav-item">
                <button type="button" class="nav-link active chat-room-tab" data-team-id="">Γενικό</button>
            </li>
            <?php foreach ($chatTeams as $ct): ?>
            <li class="nav-item">
                <button type="button" class="nav-link chat-room-tab" data-team-id="<?= $ct['id'] ?>"><?= h($ct['codename'] . ' ' . $ct['team_number']) ?></button>
            </li>
            <?php endforeach; ?>
        </ul>
        <div id="chatMessages" class="border rounded p-3 mb-3" style="height:320px;overflow-y:auto;background:#f8f9fa;"></div>
        <form id="chatSendForm" class="d-flex gap-2">
            <textarea id="chatInput" class="form-control" rows="1" maxlength="2000" placeholder="Γράψτε μήνυμα…" required></textarea>
            <button type="submit" class="btn btn-primary"><i class="bi bi-send-fill"></i></button>
        </form>
    </div>
</div>

<?php if ($canManageWarRoom): ?>
<div class="modal fade" id="reportModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-stopwatch me-1"></i>Αναφορά Χρόνων Απόκρισης</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <h6 class="text-muted small text-uppercase fw-semibold mb-2">Ανά ομάδα</h6>
                <div class="table-responsive mb-4">
                    <table class="table table-sm table-bordered align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Ομάδα</th>
                                <th class="text-end">Εντολές</th>
                                <th class="text-end">Ελήφθη</th>
                                <th class="text-end">Ολοκληρώθηκε</th>
                                <th class="text-end">Μέσος χρόνος αποδοχής</th>
                                <th class="text-end">Μέσος χρόνος ολοκλήρωσης</th>
                            </tr>
                        </thead>
                        <tbody id="reportSummaryBody">
                            <tr><td colspan="6" class="text-muted small">Φόρτωση…</td></tr>
                        </tbody>
                    </table>
                </div>
                <h6 class="text-muted small text-uppercase fw-semibold mb-2">Λεπτομέρειες</h6>
                <div id="reportDetailList" class="list-group list-group-flush"></div>

                <h6 class="text-muted small text-uppercase fw-semibold mb-2 mt-4">Αναφορές Έλλειψης — Ανά Σοβαρότητα</h6>
                <div class="table-responsive mb-4">
                    <table class="table table-sm table-bordered align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Σοβαρότητα</th>
                                <th class="text-end">Αναφορές</th>
                                <th class="text-end">Είδε</th>
                                <th class="text-end">Λύθηκε</th>
                                <th class="text-end">Μέσος χρόνος (Είδα)</th>
                                <th class="text-end">Μέσος χρόνος επίλυσης</th>
                            </tr>
                        </thead>
                        <tbody id="shortageReportSummaryBody">
                            <tr><td colspan="6" class="text-muted small">Φόρτωση…</td></tr>
                        </tbody>
                    </table>
                </div>
                <h6 class="text-muted small text-uppercase fw-semibold mb-2">Λεπτομέρειες Αναφορών Έλλειψης</h6>
                <div id="shortageReportDetailList" class="list-group list-group-flush"></div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="dispatchMapModal" tabindex="-1">
    <div class="modal-dialog modal-fullscreen">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h5 class="modal-title"><i class="bi bi-pin-map-fill me-1"></i>Αποστολή Στίγματος/Περιοχής</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0 d-flex flex-column">
                <div class="p-2 border-bottom d-flex flex-wrap gap-2 align-items-center bg-light">
                    <input type="text" id="dispatchAddressInput" class="form-control" style="max-width:320px;" placeholder="Διεύθυνση (προαιρετικό)…">
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="dispatchAddressSearch"><i class="bi bi-search me-1"></i>Αναζήτηση</button>
                    <span class="text-muted small" id="dispatchAddressStatus"></span>
                    <input type="text" id="dispatchNoteInput" class="form-control" style="max-width:260px;" maxlength="200" placeholder="Σύντομη σημείωση (προαιρετικό)…">
                    <div class="ms-auto d-flex gap-2">
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="dispatchClearBtn"><i class="bi bi-arrow-counterclockwise me-1"></i>Καθαρισμός</button>
                        <button type="button" class="btn btn-success btn-sm" id="dispatchSendBtn" disabled><i class="bi bi-send-fill me-1"></i>Αποστολή</button>
                    </div>
                </div>
                <div class="small text-muted px-2 py-1 bg-light border-bottom">
                    Κάντε click στον χάρτη για να τοποθετήσετε ένα σημείο. Για περιοχή, κάντε click σε πολλά σημεία και μετά ξανά στο 1ο σημείο για να κλείσει το σχήμα.
                </div>
                <div id="dispatchMap" style="flex:1;min-height:0;"></div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
const csrfToken = '<?= csrfToken() ?>';
const fieldMode = <?= $fieldMode ? 'true' : 'false' ?>;
const missionLocation = <?= json_encode(['lat' => $mission['latitude'] ? (float)$mission['latitude'] : null, 'lng' => $mission['longitude'] ? (float)$mission['longitude'] : null, 'title' => $mission['title']]) ?>;
let pins = <?= json_encode($pins) ?>;
let dispatches = <?= json_encode($dispatches) ?>;
let media = <?= json_encode($photos) ?>;
let myTasks = <?= json_encode($myTasks) ?>;
let shortageReports = <?= json_encode($shortageReports) ?>;
let map = null, pinLayer = null, dispatchLayer = null;
if (!fieldMode) {
    map = L.map('warRoomMap').setView(missionLocation.lat ? [missionLocation.lat, missionLocation.lng] : [37.97, 23.73], missionLocation.lat ? 13 : 7);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {attribution: '© OpenStreetMap'}).addTo(map);
    pinLayer = L.layerGroup().addTo(map);
    // FeatureGroup (not plain LayerGroup) is required here: only FeatureGroup
    // propagates child-layer events like 'popupopen' up to the group's own
    // listeners, which is how dispatchLayer.on('popupopen', ...) below wires up
    // the Ελήφθη/Άφιξη/Διαγραφή buttons inside each dispatch's popup.
    dispatchLayer = L.featureGroup().addTo(map);
}
function renderDispatches(items) {
    // A live poll can re-run this while an admin has a dispatch popup open
    // (very plausible for an area — there's more to read before deciding to
    // click "Διαγραφή" than for a simple point). clearLayers() below destroys
    // that popup and its buttons out from under them with no visible error.
    // Remember which dispatch was open and reopen the freshly-rendered
    // version of it afterward, so its buttons stay live and its popupopen
    // handler re-wires correctly instead of clicking into a dead popup.
    let openDispatchId = null;
    dispatchLayer.eachLayer(layer => {
        if (layer.dispatchId !== undefined && layer.isPopupOpen && layer.isPopupOpen()) {
            openDispatchId = layer.dispatchId;
        }
    });

    dispatchLayer.clearLayers();
    let reopenLayer = null;
    items.forEach(item => {
        const acksHtml = item.acks.length
            ? '<div class="small text-success mt-1">' + item.acks.map(a => `✅ ${a.team_label !== '—' ? a.team_label + ' — ' : ''}${a.user_name} (${a.time})`).join('<br>') + '</div>'
            : '';
        const receiveHtml = item.can_receive
            ? `<br><button type="button" class="btn btn-sm btn-warning mt-1 dispatch-receive-btn" data-id="${item.id}"><i class="bi bi-flag me-1"></i>Ελήφθη</button>`
            : (item.my_receipt ? `<div class="small text-muted mt-1">✓ Ελήφθη στις ${item.my_receipt}</div>` : '');
        const ackHtml = item.can_ack
            ? `<br><button type="button" class="btn btn-sm btn-success mt-1 dispatch-ack-btn" data-id="${item.id}"><i class="bi bi-check-lg me-1"></i>Άφιξη</button>`
            : (item.my_ack ? `<div class="small text-success mt-1">✓ Αφίξατε στις ${item.my_ack}</div>` : '');
        const popupHtml = `<strong>${item.team_label}</strong>${item.label ? '<br>' + item.label : ''}` + acksHtml + receiveHtml + ackHtml +
            (item.can_delete ? `<br><button type="button" class="btn btn-sm btn-outline-danger mt-1 dispatch-delete-btn" data-id="${item.id}">Διαγραφή</button>` : '');
        let layer = null;
        if (item.type === 'point') {
            const icon = L.divIcon({className:'', html:'<i class="bi bi-geo-alt-fill" style="font-size:28px;color:#7c3aed;filter:drop-shadow(0 1px 2px #0008);"></i>', iconSize:[28,28], iconAnchor:[14,26]});
            layer = L.marker([item.geo.lat, item.geo.lng], {icon}).addTo(dispatchLayer).bindPopup(popupHtml);
        } else if (item.type === 'polygon') {
            layer = L.polygon(item.geo, {color:'#7c3aed', fillOpacity:0.15}).addTo(dispatchLayer).bindPopup(popupHtml);
        }
        if (layer) {
            layer.dispatchId = item.id;
            if (String(item.id) === String(openDispatchId)) reopenLayer = layer;
        }
    });
    if (reopenLayer) reopenLayer.openPopup();
}
if (!fieldMode) {
dispatchLayer.on('popupopen', event => {
    const popupEl = event.popup.getElement();
    const delBtn = popupEl.querySelector('.dispatch-delete-btn');
    if (delBtn) {
        delBtn.addEventListener('click', () => {
            if (!confirm('Διαγραφή αυτού του σημείου/περιοχής;')) return;
            const data = new URLSearchParams({csrf_token: csrfToken, action: 'delete', mission_id: <?= $missionId ?>, id: delBtn.dataset.id});
            fetch('mission-dispatch.php', {method:'POST', body:data}).then(r => r.json()).then(result => {
                if (result.ok) { map.closePopup(); renderDispatches(dispatches = dispatches.filter(d => String(d.id) !== delBtn.dataset.id)); }
            });
        });
    }
    const ackBtn = popupEl.querySelector('.dispatch-ack-btn');
    if (ackBtn) {
        ackBtn.addEventListener('click', () => {
            ackBtn.disabled = true;
            const data = new URLSearchParams({csrf_token: csrfToken, action: 'ack', mission_id: <?= $missionId ?>, id: ackBtn.dataset.id});
            fetch('mission-dispatch.php', {method:'POST', body:data}).then(r => r.json()).then(result => {
                if (result.ok) { map.closePopup(); if (result.dispatches) renderDispatches(dispatches = result.dispatches); }
                else { alert(result.error || 'Αποτυχία αποστολής.'); ackBtn.disabled = false; }
            });
        });
    }
    const receiveBtn = popupEl.querySelector('.dispatch-receive-btn');
    if (receiveBtn) {
        receiveBtn.addEventListener('click', () => {
            receiveBtn.disabled = true;
            const data = new URLSearchParams({csrf_token: csrfToken, action: 'receive', mission_id: <?= $missionId ?>, id: receiveBtn.dataset.id});
            fetch('mission-dispatch.php', {method:'POST', body:data}).then(r => r.json()).then(result => {
                if (result.ok) { map.closePopup(); if (result.dispatches) renderDispatches(dispatches = result.dispatches); }
                else { alert(result.error || 'Αποτυχία αποστολής.'); receiveBtn.disabled = false; }
            });
        });
    }
});
if (missionLocation.lat) L.marker([missionLocation.lat, missionLocation.lng]).addTo(map).bindPopup('<strong>Σημείο αποστολής</strong><br><?= h(addslashes($mission['title'])) ?>');
}
let hasFitPins = false;
function renderPins(items) {
    pinLayer.clearLayers();
    const colors = {needs_help:'#dc2626', on_site:'#198754', on_way:'#f59e0b'};
    items.forEach(pin => {
        const color = colors[pin.status] || '#2563eb';
        const icon = L.divIcon({className:'', html:`<span style="display:block;width:16px;height:16px;background:${color};border:2px solid white;border-radius:50%;box-shadow:0 1px 4px #0008"></span>`, iconSize:[16,16], iconAnchor:[8,8]});
        L.marker([pin.lat, pin.lng], {icon}).addTo(pinLayer).bindPopup(`<strong>${pin.name}</strong><br>${pin.time}`);
    });
    if (!hasFitPins && items.length) {
        hasFitPins = true;
        map.invalidateSize();
        const coords = items.map(pin => [pin.lat, pin.lng]);
        if (missionLocation.lat) coords.push([missionLocation.lat, missionLocation.lng]);
        if (coords.length > 1) {
            map.fitBounds(L.latLngBounds(coords), {padding: [40, 40]});
        } else {
            map.setView(coords[0], 15);
        }
    }
}

function renderMedia(items) {
    const list = document.getElementById('mediaList');
    if (!items.length) {
        list.innerHTML = '<div class="text-muted small">Δεν έχουν σταλεί φωτογραφίες ή βίντεο ακόμη.</div>';
        return;
    }
    list.innerHTML = items.map(m => `
        <div class="card mb-2">
            ${m.media_type === 'video'
                ? `<video src="mission-photo-view.php?id=${m.id}" class="card-img-top" style="height:160px;object-fit:cover;background:#000;" controls preload="metadata"></video>`
                : `<img src="mission-photo-view.php?id=${m.id}" class="card-img-top" style="height:160px;object-fit:cover;cursor:pointer;" onclick="window.open('mission-photo-view.php?id=${m.id}', '_blank')">`}
            <div class="card-body p-2 d-flex justify-content-between align-items-center">
                <div class="small">
                    <strong>${m.media_type === 'video' ? '🎥 ' : '📷 '}${m.user_name}</strong><br>
                    <span class="text-muted">${m.time}</span>
                </div>
                <div class="d-flex gap-1">
                    ${m.lat !== null ? `<button type="button" class="btn btn-sm btn-outline-secondary media-locate-btn" data-lat="${m.lat}" data-lng="${m.lng}" title="Εμφάνιση στον χάρτη"><i class="bi bi-geo-alt-fill"></i></button>` : ''}
                    ${m.can_delete ? `<button type="button" class="btn btn-sm btn-outline-danger media-delete-btn" data-id="${m.id}" title="Διαγραφή"><i class="bi bi-trash"></i></button>` : ''}
                </div>
            </div>
        </div>
    `).join('');
    list.querySelectorAll('.media-locate-btn').forEach(btn => btn.addEventListener('click', () => {
        map.setView([parseFloat(btn.dataset.lat), parseFloat(btn.dataset.lng)], 16);
    }));
    list.querySelectorAll('.media-delete-btn').forEach(btn => btn.addEventListener('click', () => {
        if (!confirm('Διαγραφή αυτού του αρχείου;')) return;
        const data = new URLSearchParams({csrf_token: csrfToken, action: 'delete', mission_id: <?= $missionId ?>, id: btn.dataset.id});
        fetch('mission-photo.php', {method:'POST', body:data}).then(r => r.json()).then(result => {
            if (result.ok) renderMedia(media = media.filter(m => String(m.id) !== btn.dataset.id));
            else alert(result.error || 'Αποτυχία διαγραφής.');
        });
    }));
}

function renderMyTasks(items) {
    const list = document.getElementById('myTasksList');
    if (!items.length) {
        list.innerHTML = '<p class="text-muted mb-0">Δεν έχετε ανατεθειμένες εντολές σε αυτή την αποστολή.</p>';
        return;
    }
    list.innerHTML = items.map(t => {
        let actionHtml;
        if (t.fulfilled_at) {
            actionHtml = `<span class="badge bg-success">✓ Ολοκληρώθηκε στις ${t.fulfilled_at}</span>`;
        } else if (t.acknowledged_at) {
            actionHtml = `<button type="button" class="btn btn-sm btn-success w-100 my-task-complete-btn" data-order-id="${t.order_id}">Ολοκληρώθηκε</button>`;
        } else {
            actionHtml = `<button type="button" class="btn btn-sm btn-warning w-100 my-task-ack-btn" data-order-id="${t.order_id}">Ελήφθη</button>`;
        }
        return `<div class="border rounded p-2 mb-2">
            <div class="small">${t.task_text}</div>
            <div class="text-muted" style="font-size:.75rem;">Στάλθηκε ${t.sent_at}</div>
            <div class="mt-1">${actionHtml}</div>
        </div>`;
    }).join('');
    list.querySelectorAll('.my-task-ack-btn').forEach(btn => btn.addEventListener('click', () => {
        btn.disabled = true;
        const data = new URLSearchParams({csrf_token: csrfToken, action: 'acknowledge', order_id: btn.dataset.orderId});
        fetch('mission-order.php', {method: 'POST', body: data}).then(r => r.json()).then(result => {
            if (result.ok) {
                const item = myTasks.find(t => String(t.order_id) === btn.dataset.orderId);
                if (item) item.acknowledged_at = item.acknowledged_at || 'τώρα';
                renderMyTasks(myTasks);
            } else { btn.disabled = false; alert(result.error || 'Αποτυχία.'); }
        }).catch(() => { btn.disabled = false; });
    }));
    list.querySelectorAll('.my-task-complete-btn').forEach(btn => btn.addEventListener('click', () => {
        btn.disabled = true;
        const data = new URLSearchParams({csrf_token: csrfToken, action: 'complete', order_id: btn.dataset.orderId});
        fetch('mission-order.php', {method: 'POST', body: data}).then(r => r.json()).then(result => {
            if (result.ok) {
                const item = myTasks.find(t => String(t.order_id) === btn.dataset.orderId);
                if (item) { item.fulfilled_at = 'τώρα'; item.acknowledged_at = item.acknowledged_at || 'τώρα'; }
                renderMyTasks(myTasks);
            } else { btn.disabled = false; alert(result.error || 'Αποτυχία.'); }
        }).catch(() => { btn.disabled = false; });
    }));
}

function renderPresence(onlineIds) {
    const onlineSet = new Set((onlineIds || []).map(String));
    document.querySelectorAll('[id^="presence-"]').forEach(el => {
        const uid = el.id.slice('presence-'.length);
        const isOnline = onlineSet.has(uid);
        el.classList.toggle('presence-online', isOnline);
        el.classList.toggle('presence-offline', !isOnline);
        el.title = isOnline ? 'Online' : 'Offline';
    });
}

function renderShortageReports(items) {
    const list = document.getElementById('shortageReportsList');
    if (!list) return;
    if (!items.length) {
        list.innerHTML = '<p class="text-muted mb-0">Δεν υπάρχουν ανοιχτές αναφορές έλλειψης.</p>';
        return;
    }
    const sevColor = {low: 'secondary', medium: 'info', high: 'warning', critical: 'danger'};
    list.innerHTML = items.map(r => `
        <div class="border rounded p-2 mb-2">
            <div><span class="badge bg-${sevColor[r.severity] || 'secondary'}">${r.severity_label}</span> <strong>${r.type_label}</strong> — ${r.title}</div>
            <div class="small mt-1">${r.description}</div>
            <div class="text-muted" style="font-size:.75rem;">${r.reporter_name} (${r.team_label}) · ${r.created_at}${r.acknowledged_at ? ' · Είδατε: ' + r.acknowledged_at : ''}</div>
            <div class="mt-1">${r.acknowledged_at
                ? `<button type="button" class="btn btn-sm btn-success w-100 shortage-resolve-btn" data-report-id="${r.id}">Λύθηκε</button>`
                : `<button type="button" class="btn btn-sm btn-warning w-100 shortage-seen-btn" data-report-id="${r.id}">Είδα</button>`}</div>
        </div>
    `).join('');
    list.querySelectorAll('.shortage-seen-btn').forEach(btn => btn.addEventListener('click', () => {
        btn.disabled = true;
        const data = new URLSearchParams({csrf_token: csrfToken, action: 'seen', report_id: btn.dataset.reportId});
        fetch('mission-shortage.php', {method: 'POST', body: data}).then(r => r.json()).then(result => {
            if (result.ok) {
                const item = shortageReports.find(x => String(x.id) === btn.dataset.reportId);
                if (item) item.acknowledged_at = item.acknowledged_at || 'τώρα';
                renderShortageReports(shortageReports);
            } else { btn.disabled = false; alert(result.error || 'Αποτυχία.'); }
        }).catch(() => { btn.disabled = false; });
    }));
    list.querySelectorAll('.shortage-resolve-btn').forEach(btn => btn.addEventListener('click', () => {
        btn.disabled = true;
        const data = new URLSearchParams({csrf_token: csrfToken, action: 'resolve', report_id: btn.dataset.reportId});
        fetch('mission-shortage.php', {method: 'POST', body: data}).then(r => r.json()).then(result => {
            if (result.ok) {
                shortageReports = shortageReports.filter(x => String(x.id) !== btn.dataset.reportId);
                renderShortageReports(shortageReports);
            } else { btn.disabled = false; alert(result.error || 'Αποτυχία.'); }
        }).catch(() => { btn.disabled = false; });
    }));
}

function wireMediaInput(inputId, sentLabel) {
    const input = document.getElementById(inputId);
    if (!input) return;
    input.addEventListener('change', () => {
        const file = input.files[0];
        if (!file) return;
        const status = document.getElementById('mediaUploadStatus');
        status.textContent = 'Αποστολή…';
        status.className = 'small mb-2';

        const send = (lat, lng) => {
            const data = new FormData();
            data.append('csrf_token', csrfToken);
            data.append('action', 'upload');
            data.append('mission_id', '<?= $missionId ?>');
            data.append('media', file);
            if (lat !== null) { data.append('lat', lat); data.append('lng', lng); }
            fetch('mission-photo.php', {method:'POST', body:data}).then(r => r.json()).then(result => {
                if (result.ok) {
                    status.textContent = '✓ ' + sentLabel + ' εστάλη.';
                    status.className = 'small mb-2 text-success';
                    renderMedia(media = [result.media, ...media]);
                } else {
                    status.textContent = result.error || 'Αποτυχία αποστολής.';
                    status.className = 'small mb-2 text-danger';
                }
                input.value = '';
            }).catch(() => { status.textContent = 'Αποτυχία αποστολής.'; status.className = 'small mb-2 text-danger'; input.value = ''; });
        };

        if (navigator.geolocation) {
            navigator.geolocation.getCurrentPosition(
                position => send(position.coords.latitude, position.coords.longitude),
                () => send(null, null),
                {enableHighAccuracy: true, timeout: 8000}
            );
        } else {
            send(null, null);
        }
    });
}
wireMediaInput('photoCaptureInput', 'Η φωτογραφία');
wireMediaInput('photoGalleryInput', 'Η φωτογραφία');
wireMediaInput('videoCaptureInput', 'Το βίντεο');
wireMediaInput('videoGalleryInput', 'Το βίντεο');

setTimeout(() => {
    if (!fieldMode) { renderPins(pins); renderDispatches(dispatches); renderMedia(media); }
    renderMyTasks(myTasks);
    renderShortageReports(shortageReports);
}, 200);

let bannerAfterId = <?= $bannerSinceId ?>;
let bannerHideTimer = null;

// Loud alert sound for incoming War Room banners (orders, dispatches, global messages).
// Browsers block audio until the page has seen a user gesture, so we lazily create/resume
// the AudioContext on the first click/tap/keydown anywhere on the page.
let warRoomAudioCtx = null;
function unlockWarRoomAudio() {
    if (!warRoomAudioCtx) {
        const Ctx = window.AudioContext || window.webkitAudioContext;
        if (!Ctx) return;
        warRoomAudioCtx = new Ctx();
    }
    if (warRoomAudioCtx.state === 'suspended') warRoomAudioCtx.resume().catch(() => {});
}
['click', 'touchstart', 'keydown'].forEach(evt => document.addEventListener(evt, unlockWarRoomAudio, {once: true}));

function playWarRoomAlertSound() {
    unlockWarRoomAudio();
    if (!warRoomAudioCtx || warRoomAudioCtx.state !== 'running') return;
    const ctx = warRoomAudioCtx;
    const now = ctx.currentTime;
    [0, 0.32, 0.64].forEach((offset, i) => {
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.type = 'square';
        osc.frequency.value = i % 2 === 0 ? 988 : 740;
        gain.gain.setValueAtTime(0.0001, now + offset);
        gain.gain.exponentialRampToValueAtTime(1, now + offset + 0.02);
        gain.gain.exponentialRampToValueAtTime(0.0001, now + offset + 0.28);
        osc.connect(gain).connect(ctx.destination);
        osc.start(now + offset);
        osc.stop(now + offset + 0.3);
    });
}

function showWarRoomBanner(text, orderId) {
    playWarRoomAlertSound();
    const el = document.getElementById('warRoomBanner');
    document.getElementById('warRoomBannerText').textContent = text;
    el.style.display = 'flex';
    if (bannerHideTimer) clearTimeout(bannerHideTimer);
    bannerHideTimer = setTimeout(hideWarRoomBanner, 60000);

    const ackBtn = document.getElementById('warRoomBannerAckBtn');
    if (orderId) {
        ackBtn.classList.remove('d-none');
        ackBtn.disabled = false;
        ackBtn.textContent = 'Ελήφθη';
        ackBtn.onclick = () => {
            ackBtn.disabled = true;
            const data = new URLSearchParams({csrf_token: csrfToken, action: 'acknowledge', order_id: orderId});
            fetch('mission-order.php', {method: 'POST', body: data}).then(r => r.json()).then(result => {
                if (result.ok) { ackBtn.textContent = '✓ Ελήφθη'; }
                else { ackBtn.disabled = false; alert(result.error || 'Αποτυχία.'); }
            }).catch(() => { ackBtn.disabled = false; });
        };
    } else {
        ackBtn.classList.add('d-none');
        ackBtn.onclick = null;
    }
}
function hideWarRoomBanner() {
    document.getElementById('warRoomBanner').style.display = 'none';
    if (bannerHideTimer) { clearTimeout(bannerHideTimer); bannerHideTimer = null; }
}
document.getElementById('warRoomBannerClose').addEventListener('click', hideWarRoomBanner);

function loadActivity() {
    fetch('mission-history.php?mission_id=<?= $missionId ?>').then(r => r.json()).then(data => {
        const list = document.getElementById('activityList');
        if (!data.ok || !data.events.length) {
            list.innerHTML = '<div class="text-muted small">Δεν υπάρχουν καταγεγραμμένα γεγονότα ακόμη.</div>';
            return;
        }
        list.innerHTML = data.events.map(e => `
            <div class="d-flex justify-content-between align-items-start gap-3 border-bottom py-2">
                <div><span class="me-1">${e.icon}</span>${e.text}</div>
                <small class="text-muted text-nowrap">${e.time}</small>
            </div>
        `).join('');
        document.getElementById('activityRefresh').textContent = new Date().toLocaleTimeString('el-GR', {hour: '2-digit', minute: '2-digit'});
    }).catch(() => {});
}
if (!fieldMode) {
    loadActivity();
    setInterval(loadActivity, 15000);
}

const reportModalEl = document.getElementById('reportModal');
if (reportModalEl) {
    reportModalEl.addEventListener('show.bs.modal', () => {
        const summaryBody = document.getElementById('reportSummaryBody');
        const detailList = document.getElementById('reportDetailList');
        const shortageSummaryBody = document.getElementById('shortageReportSummaryBody');
        const shortageDetailList = document.getElementById('shortageReportDetailList');
        summaryBody.innerHTML = '<tr><td colspan="6" class="text-muted small">Φόρτωση…</td></tr>';
        detailList.innerHTML = '';
        shortageSummaryBody.innerHTML = '<tr><td colspan="6" class="text-muted small">Φόρτωση…</td></tr>';
        shortageDetailList.innerHTML = '';
        fetch('mission-response-report.php?mission_id=<?= $missionId ?>').then(r => r.json()).then(data => {
            if (!data.ok) { summaryBody.innerHTML = `<tr><td colspan="6" class="text-danger small">${data.error}</td></tr>`; return; }

            summaryBody.innerHTML = data.summary.length ? data.summary.map(s => `
                <tr>
                    <td>${s.team_label}</td>
                    <td class="text-end">${s.order_count}</td>
                    <td class="text-end">${s.ack_rate}%</td>
                    <td class="text-end">${s.fulfill_rate}%</td>
                    <td class="text-end">${s.avg_ack_minutes !== null ? s.avg_ack_minutes + ' λεπ.' : '—'}</td>
                    <td class="text-end">${s.avg_fulfill_minutes !== null ? s.avg_fulfill_minutes + ' λεπ.' : '—'}</td>
                </tr>
            `).join('') : '<tr><td colspan="6" class="text-muted small">Δεν έχουν σταλεί εντολές ακόμη.</td></tr>';

            detailList.innerHTML = data.detail.length ? data.detail.map(d => `
                <div class="list-group-item d-flex justify-content-between align-items-start gap-3">
                    <div>
                        <span class="me-1">${d.type_label}</span>
                        <strong>${d.team_label}</strong> — ${d.user_name}
                        ${d.label ? ' («' + d.label + '»)' : ''}
                        <div class="small text-muted">
                            Στάλθηκε ${d.sent_at}
                            · Ελήφθη ${d.ack_at ? d.ack_at + ' (' + d.ack_minutes + ' λεπ.)' : '—'}
                            · Ολοκληρώθηκε ${d.fulfill_at ? d.fulfill_at + ' (' + d.fulfill_minutes + ' λεπ.)' : '—'}
                        </div>
                    </div>
                </div>
            `).join('') : '<div class="text-muted small">Δεν υπάρχουν λεπτομέρειες.</div>';

            shortageSummaryBody.innerHTML = data.shortageSummary.length ? data.shortageSummary.map(s => `
                <tr>
                    <td><span class="badge bg-${({low:'secondary',medium:'info',high:'warning',critical:'danger'})[s.severity] || 'secondary'}">${s.severity_label}</span></td>
                    <td class="text-end">${s.report_count}</td>
                    <td class="text-end">${s.seen_rate}%</td>
                    <td class="text-end">${s.resolved_rate}%</td>
                    <td class="text-end">${s.avg_seen_minutes !== null ? s.avg_seen_minutes + ' λεπ.' : '—'}</td>
                    <td class="text-end">${s.avg_resolved_minutes !== null ? s.avg_resolved_minutes + ' λεπ.' : '—'}</td>
                </tr>
            `).join('') : '<tr><td colspan="6" class="text-muted small">Δεν έχουν υποβληθεί αναφορές έλλειψης ακόμη.</td></tr>';

            shortageDetailList.innerHTML = data.shortageDetail.length ? data.shortageDetail.map(d => `
                <div class="list-group-item d-flex justify-content-between align-items-start gap-3">
                    <div>
                        <span class="badge bg-${({low:'secondary',medium:'info',high:'warning',critical:'danger'})[d.severity] || 'secondary'} me-1">${d.severity_label}</span>
                        <strong>${d.team_label}</strong> — ${d.reporter_name}
                        («${d.title}»)
                        <div class="small text-muted">
                            Στάλθηκε ${d.sent_at}
                            · Είδε ${d.seen_at ? d.seen_at + ' (' + d.seen_minutes + ' λεπ.)' : '—'}
                            · Λύθηκε ${d.resolved_at ? d.resolved_at + ' (' + d.resolved_minutes + ' λεπ.)' : '—'}
                        </div>
                    </div>
                </div>
            `).join('') : '<div class="text-muted small">Δεν υπάρχουν λεπτομέρειες.</div>';
        }).catch(() => {
            summaryBody.innerHTML = '<tr><td colspan="6" class="text-danger small">Αποτυχία φόρτωσης.</td></tr>';
        });
    });
}

document.querySelectorAll('.send-ping').forEach(button => button.addEventListener('click', () => {
    const status = document.getElementById('pingStatus-' + button.dataset.prId);
    if (!navigator.geolocation) { status.textContent = 'Το GPS δεν υποστηρίζεται από τη συσκευή.'; return; }
    button.disabled = true; status.textContent = 'Εντοπισμός θέσης…';
    navigator.geolocation.getCurrentPosition(position => {
        const data = new URLSearchParams({csrf_token: csrfToken, shift_id: button.dataset.shiftId, lat: position.coords.latitude, lng: position.coords.longitude});
        fetch('ping-location.php', {method:'POST', body:data}).then(response => response.json()).then(result => {
            status.textContent = result.ok ? '✓ Το στίγμα εστάλη στις ' + result.ts : result.error;
            status.className = 'small mb-2 ' + (result.ok ? 'text-success' : 'text-danger');
        }).catch(() => { status.textContent = 'Αποτυχία αποστολής στίγματος.'; status.className = 'small mb-2 text-danger'; }).finally(() => button.disabled = false);
    }, () => { status.textContent = 'Δεν δόθηκε άδεια πρόσβασης στο GPS.'; status.className = 'small mb-2 text-danger'; button.disabled = false; }, {enableHighAccuracy:true, timeout:10000});
}));

function setFieldStatus(btn, prId, status) {
    const group = document.getElementById('statusBtns-' + prId);
    if (group) group.querySelectorAll('button').forEach(b => b.disabled = true);
    const data = new URLSearchParams({csrf_token: csrfToken, pr_id: prId, status: status});
    fetch('volunteer-status.php', {method: 'POST', body: data}).then(r => r.json()).then(result => {
        if (result.ok) {
            const badge = document.getElementById('statusBadge-' + prId);
            if (badge) badge.textContent = result.label;
            const colorMap = {on_way: 'warning', on_site: 'success', needs_help: 'danger'};
            if (group) {
                group.querySelectorAll('button').forEach(b => {
                    const s = b.getAttribute('onclick').match(/'([^']+)'\)$/)?.[1];
                    if (s) { b.className = 'btn btn-sm ' + (s === result.status ? 'btn-' + colorMap[s] : 'btn-outline-' + colorMap[s]); }
                    b.disabled = false;
                });
            }
            if (status === 'needs_help') {
                const panel = btn.closest('.card');
                if (panel) panel.style.animation = 'warRoomPulseRed 0.5s 3';
            }
        } else {
            alert(result.error || 'Αποτυχία ενημέρωσης κατάστασης.');
            if (group) group.querySelectorAll('button').forEach(b => b.disabled = false);
        }
    }).catch(() => { if (group) group.querySelectorAll('button').forEach(b => b.disabled = false); });
}

setInterval(() => fetch('war-room.php?id=<?= $missionId ?>&ajax=1&banner_after=' + bannerAfterId).then(response => response.json()).then(data => {
    if (!fieldMode) {
        renderPins(data.pins || []);
        if (data.dispatches) renderDispatches(dispatches = data.dispatches);
        if (data.media) renderMedia(media = data.media);
    }
    if (data.myTasks) renderMyTasks(myTasks = data.myTasks);
    if (data.shortageReports) renderShortageReports(shortageReports = data.shortageReports);
    if (data.onlinePresence) renderPresence(data.onlinePresence);
    if (!fieldMode) document.getElementById('mapRefresh').textContent = data.time || '';
    if (data.banner && data.banner.id > bannerAfterId) {
        bannerAfterId = data.banner.id;
        showWarRoomBanner(data.banner.message, data.banner.orderId);
    }
}).catch(() => {}), 15000);

document.querySelectorAll('.team-form').forEach(form => {
    const leaderSelect = form.querySelector(form.dataset.leaderSelect);
    if (!leaderSelect) return;
    const checkboxes = form.querySelectorAll('.team-member-check');
    const currentLeaderId = leaderSelect.dataset.current || '';
    function refreshLeaderOptions() {
        const checked = Array.from(checkboxes).filter(cb => cb.checked);
        const previousValue = leaderSelect.value || currentLeaderId;
        leaderSelect.innerHTML = '';
        if (checked.length === 0) {
            leaderSelect.innerHTML = '<option value="">Επιλέξτε μέλη πρώτα…</option>';
            return;
        }
        checked.forEach(cb => {
            const opt = document.createElement('option');
            opt.value = cb.value;
            opt.textContent = cb.dataset.name;
            if (cb.value === String(previousValue)) opt.selected = true;
            leaderSelect.appendChild(opt);
        });
    }
    checkboxes.forEach(cb => cb.addEventListener('change', refreshLeaderOptions));
    refreshLeaderOptions();
});

(function() {
    const chatMessagesEl = document.getElementById('chatMessages');
    const chatInput = document.getElementById('chatInput');
    const chatForm = document.getElementById('chatSendForm');
    if (!chatMessagesEl || !chatForm) return;

    const missionId = <?= $missionId ?>;
    let activeTeamId = '';
    let lastIdByRoom = {};

    function renderMessage(msg) {
        const wrap = document.createElement('div');
        wrap.className = 'mb-2 d-flex ' + (msg.mine ? 'justify-content-end' : 'justify-content-start');
        const bubble = document.createElement('div');
        bubble.className = 'p-2 rounded' + (msg.mine ? ' bg-primary text-white' : ' bg-white border');
        bubble.style.maxWidth = '75%';
        const meta = document.createElement('div');
        meta.className = 'small d-flex align-items-center gap-1 ' + (msg.mine ? 'text-white-50' : 'text-muted');
        const metaText = document.createElement('span');
        metaText.textContent = msg.name + ' · ' + msg.time;
        meta.appendChild(metaText);
        if (msg.can_delete) {
            const del = document.createElement('button');
            del.type = 'button';
            del.className = 'btn btn-sm btn-link p-0 ' + (msg.mine ? 'text-white-50' : 'text-danger');
            del.style.fontSize = '.8rem';
            del.innerHTML = '<i class="bi bi-trash"></i>';
            del.addEventListener('click', () => deleteMessage(msg.id, wrap));
            meta.appendChild(del);
        }
        const body = document.createElement('div');
        body.textContent = msg.message;
        body.style.whiteSpace = 'pre-wrap';
        bubble.appendChild(meta);
        bubble.appendChild(body);
        wrap.appendChild(bubble);
        chatMessagesEl.appendChild(wrap);
    }

    function loadRoom(teamId) {
        activeTeamId = teamId;
        lastIdByRoom[teamId] = 0;
        chatMessagesEl.innerHTML = '';
        fetch(`mission-chat.php?mission_id=${missionId}&team_id=${teamId}&after_id=0`)
            .then(response => response.json())
            .then(data => {
                if (!data.ok) { chatMessagesEl.textContent = data.error || 'Σφάλμα φόρτωσης.'; return; }
                data.messages.forEach(renderMessage);
                if (data.messages.length) lastIdByRoom[teamId] = data.messages[data.messages.length - 1].id;
                chatMessagesEl.scrollTop = chatMessagesEl.scrollHeight;
            })
            .catch(() => { chatMessagesEl.textContent = 'Σφάλμα φόρτωσης.'; });
    }

    function pollRoom() {
        const teamId = activeTeamId;
        const afterId = lastIdByRoom[teamId] || 0;
        fetch(`mission-chat.php?mission_id=${missionId}&team_id=${teamId}&after_id=${afterId}`)
            .then(response => response.json())
            .then(data => {
                if (!data.ok || teamId !== activeTeamId || !data.messages.length) return;
                const nearBottom = chatMessagesEl.scrollHeight - chatMessagesEl.scrollTop - chatMessagesEl.clientHeight < 60;
                data.messages.forEach(renderMessage);
                lastIdByRoom[teamId] = data.messages[data.messages.length - 1].id;
                if (nearBottom) chatMessagesEl.scrollTop = chatMessagesEl.scrollHeight;
            })
            .catch(() => {});
    }

    function deleteMessage(id, el) {
        const data = new URLSearchParams({csrf_token: csrfToken, action: 'delete', mission_id: missionId, message_id: id});
        fetch('mission-chat.php', {method: 'POST', body: data}).then(response => response.json()).then(result => {
            if (result.ok) el.remove();
        });
    }

    document.querySelectorAll('.chat-room-tab').forEach(tab => tab.addEventListener('click', () => {
        document.querySelectorAll('.chat-room-tab').forEach(t => t.classList.remove('active'));
        tab.classList.add('active');
        loadRoom(tab.dataset.teamId);
    }));

    chatForm.addEventListener('submit', event => {
        event.preventDefault();
        const text = chatInput.value.trim();
        if (!text) return;
        const data = new URLSearchParams({csrf_token: csrfToken, action: 'send', mission_id: missionId, team_id: activeTeamId, message: text});
        fetch('mission-chat.php', {method: 'POST', body: data}).then(response => response.json()).then(result => {
            if (result.ok) {
                renderMessage(result.message);
                lastIdByRoom[activeTeamId] = result.message.id;
                chatMessagesEl.scrollTop = chatMessagesEl.scrollHeight;
                chatInput.value = '';
            } else {
                alert(result.error || 'Αποτυχία αποστολής.');
            }
        }).catch(() => alert('Αποτυχία αποστολής.'));
    });

    loadRoom('');
    setInterval(pollRoom, 5000);
})();

(function() {
    const modalEl = document.getElementById('dispatchMapModal');
    if (!modalEl) return;

    const teamSelect = document.getElementById('dispatchTeamSelect');
    const addressInput = document.getElementById('dispatchAddressInput');
    const addressSearchBtn = document.getElementById('dispatchAddressSearch');
    const addressStatus = document.getElementById('dispatchAddressStatus');
    const noteInput = document.getElementById('dispatchNoteInput');
    const clearBtn = document.getElementById('dispatchClearBtn');
    const sendBtn = document.getElementById('dispatchSendBtn');

    let dispatchMap = null;
    let drawPoints = [];
    let vertexMarkers = [];
    let shapeLayer = null;
    let isClosed = false;
    let lastAddressLabel = '';

    function resetDrawing() {
        drawPoints = [];
        isClosed = false;
        vertexMarkers.forEach(m => dispatchMap.removeLayer(m));
        vertexMarkers = [];
        if (shapeLayer) { dispatchMap.removeLayer(shapeLayer); shapeLayer = null; }
        sendBtn.disabled = true;
    }

    function updateShapePreview() {
        if (shapeLayer) { dispatchMap.removeLayer(shapeLayer); shapeLayer = null; }
        if (drawPoints.length < 2) return;
        shapeLayer = isClosed
            ? L.polygon(drawPoints, {color:'#7c3aed', fillOpacity:0.15}).addTo(dispatchMap)
            : L.polyline(drawPoints, {color:'#7c3aed'}).addTo(dispatchMap);
    }

    function updateSendState() {
        sendBtn.disabled = !(drawPoints.length === 1 || (isClosed && drawPoints.length >= 3));
    }

    function onMapClick(e) {
        if (isClosed) return;
        if (drawPoints.length >= 3) {
            const firstPoint = dispatchMap.latLngToContainerPoint(L.latLng(drawPoints[0]));
            const clickPoint = dispatchMap.latLngToContainerPoint(e.latlng);
            if (firstPoint.distanceTo(clickPoint) < 16) {
                isClosed = true;
                updateShapePreview();
                updateSendState();
                return;
            }
        }
        drawPoints.push([e.latlng.lat, e.latlng.lng]);
        vertexMarkers.push(L.circleMarker(e.latlng, {radius:7, color:'#7c3aed', fillColor:'#fff', fillOpacity:1, weight:2}).addTo(dispatchMap));
        updateShapePreview();
        updateSendState();
    }

    modalEl.addEventListener('shown.bs.modal', () => {
        if (!dispatchMap) {
            const center = missionLocation.lat ? [missionLocation.lat, missionLocation.lng] : [37.97, 23.73];
            dispatchMap = L.map('dispatchMap').setView(center, missionLocation.lat ? 13 : 7);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {attribution: '© OpenStreetMap'}).addTo(dispatchMap);
            dispatchMap.on('click', onMapClick);
        }
        setTimeout(() => dispatchMap.invalidateSize(), 100);
    });

    modalEl.addEventListener('hidden.bs.modal', () => {
        resetDrawing();
        addressInput.value = '';
        addressStatus.textContent = '';
        lastAddressLabel = '';
        noteInput.value = '';
    });

    clearBtn.addEventListener('click', resetDrawing);

    addressSearchBtn.addEventListener('click', () => {
        const q = addressInput.value.trim();
        if (!q) return;
        addressStatus.textContent = 'Αναζήτηση…';
        fetch('geocode-address.php?q=' + encodeURIComponent(q)).then(response => response.json()).then(result => {
            if (result.ok) {
                dispatchMap.setView([result.lat, result.lng], 16);
                lastAddressLabel = result.display_name || q;
                addressStatus.textContent = '✓ ' + lastAddressLabel;
            } else {
                addressStatus.textContent = result.error || 'Δεν βρέθηκε.';
            }
        }).catch(() => { addressStatus.textContent = 'Αποτυχία αναζήτησης.'; });
    });

    sendBtn.addEventListener('click', () => {
        const type = isClosed ? 'polygon' : 'point';
        const geo = type === 'point' ? {lat: drawPoints[0][0], lng: drawPoints[0][1]} : drawPoints;
        const noteText = noteInput.value.trim();
        const combinedLabel = noteText && lastAddressLabel ? (noteText + ' — ' + lastAddressLabel) : (noteText || lastAddressLabel);
        const data = new URLSearchParams({
            csrf_token: csrfToken, action: 'create', mission_id: <?= $missionId ?>,
            team_id: teamSelect.value, type: type, geo: JSON.stringify(geo), label: combinedLabel,
        });
        sendBtn.disabled = true;
        fetch('mission-dispatch.php', {method:'POST', body:data}).then(response => response.json()).then(result => {
            if (result.ok) {
                bootstrap.Modal.getInstance(modalEl).hide();
                fetch('war-room.php?id=<?= $missionId ?>&ajax=1&banner_after=' + bannerAfterId)
                    .then(response => response.json())
                    .then(d => { if (d.dispatches) renderDispatches(dispatches = d.dispatches); });
            } else {
                alert(result.error || 'Αποτυχία αποστολής.');
                sendBtn.disabled = false;
            }
        }).catch(() => { alert('Αποτυχία αποστολής.'); sendBtn.disabled = false; });
    });
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
