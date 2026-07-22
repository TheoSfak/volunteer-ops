<?php
/**
 * VolunteerOps - War Room
 * Mission-specific live operational view for approved participants and managers.
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();

$missionId = (int)get('id');
if (!$missionId) {
    setFlash('error', t('common.mission_not_found'));
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
    setFlash('error', t('common.mission_not_found'));
    redirect('dashboard.php');
}

if (!defined('MISSION_TEAM_CODENAMES')) {
    define('MISSION_TEAM_CODENAMES', ['Alpha','Bravo','Charlie','Delta','Echo','Foxtrot','Golf','Hotel','India',
        'Juliett','Kilo','Lima','Mike','November','Oscar','Papa','Quebec','Romeo',
        'Sierra','Tango','Uniform','Victor','Whiskey','X-ray','Yankee','Zulu']);
}

// Team color palette, same index basis as MISSION_TEAM_CODENAMES above (team N
// gets codename[N % 26] and color[N % 8] — colors cycle every 8 teams since a
// colorblind-safe categorical palette only stays distinguishable up to ~8
// slots; ordering was picked (and validated with the dataviz skill's
// scripts/validate_palette.js) to lead with red for Alpha / green for Bravo
// as requested, then fill the rest by worst-adjacent-pair CVD separation.
if (!defined('MISSION_TEAM_COLORS')) {
    define('MISSION_TEAM_COLORS', ['#e34948','#008300','#4a3aa7','#eda100','#2a78d6','#e87ba4','#1baf7a','#eb6834']);
}
// Readable text color per swatch (WCAG contrast checked); anything not listed
// defaults to black in teamBadgeColors() below.
if (!defined('MISSION_TEAM_COLOR_TEXT')) {
    define('MISSION_TEAM_COLOR_TEXT', ['#008300' => '#fff', '#4a3aa7' => '#fff']);
}
/** Returns [background, text] hex pair for a team badge; null color falls back to the old bg-dark look. */
function teamBadgeColors(?string $color): array {
    if (!$color) return ['#212529', '#fff'];
    return [$color, MISSION_TEAM_COLOR_TEXT[$color] ?? '#000'];
}

/**
 * Notify every team member (individually) about their team assignment.
 * $namesByUserId must map user_id => name for all ids in $memberIds/$leaderId.
 */
function notifyMissionTeamMembers(int $missionId, string $missionTitle, string $codename, int $teamNumber, array $memberIds, int $leaderId, array $namesByUserId): void {
    $teamLabel = $codename . ' ' . $teamNumber;
    $warRoomUrl = rtrim(BASE_URL, '/') . '/war-room.php?id=' . $missionId;
    $leaderName = $namesByUserId[$leaderId] ?? '';
    $langByUserId = getUserLanguages($memberIds);
    foreach ($memberIds as $memberId) {
        $lang = $langByUserId[$memberId] ?? DEFAULT_LANGUAGE;
        $teammateNames = array_filter(array_map(
            fn($id) => $namesByUserId[$id] ?? '',
            array_values(array_diff($memberIds, [$memberId]))
        ));
        $message = t('team.notify.assigned', ['mission' => $missionTitle, 'team' => $teamLabel], $lang);
        if (!empty($teammateNames)) {
            $message .= t('team.notify.mates', ['names' => implode(', ', $teammateNames)], $lang);
        }
        $message .= $memberId === $leaderId
            ? t('team.notify.leader_self', [], $lang)
            : t('team.notify.leader_other', ['leader' => $leaderName], $lang);
        sendNotification($memberId, t('team.notify.title', ['team' => $teamLabel], $lang), $message, 'info', '', [
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
function createMissionOrderAndNotify(
    int $missionId, string $missionTitle, string $orderType, int $createdBy, array $recipientIds,
    string $titleKey, array $titleVars, ?string $rawMessage, string $messageKey, array $messageVars,
    string $broadcastKey, array $broadcastVars, ?string $taskText = null
): int {
    $orderId = dbInsert(
        "INSERT INTO mission_orders (mission_id, order_type, task_text, created_by, created_at) VALUES (?, ?, ?, ?, NOW())",
        [$missionId, $orderType, $taskText, $createdBy]
    );

    $warRoomUrl = rtrim(BASE_URL, '/') . '/war-room.php?id=' . $missionId;
    $recipientLangs = getUserLanguages($recipientIds);
    foreach ($recipientIds as $recipientId) {
        $lang = $recipientLangs[$recipientId] ?? DEFAULT_LANGUAGE;
        $teamId = getUserTeamIdForMission($missionId, $recipientId);
        dbInsert(
            "INSERT INTO mission_order_recipients (order_id, user_id, team_id) VALUES (?, ?, ?)",
            [$orderId, $recipientId, $teamId]
        );
        // Free-form task/broadcast text ($rawMessage) is never translated — it's
        // exactly what the admin typed, per the "free text stays as typed" rule.
        $message = $rawMessage ?? t($messageKey, $messageVars, $lang);
        sendNotification($recipientId, t($titleKey, $titleVars, $lang), $message, 'warning', '', [
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
    $bystanderLangs = getUserLanguages($bystanderIds);
    foreach ($bystanderIds as $bystanderId) {
        $lang = $bystanderLangs[$bystanderId] ?? DEFAULT_LANGUAGE;
        sendNotification($bystanderId, t($titleKey, $titleVars, $lang), t($broadcastKey, $broadcastVars, $lang), 'info', '', [
            'url' => $warRoomUrl,
            'tag' => $orderType . '-request-mission-' . $missionId,
            'bannerMission' => $missionId,
        ]);
    }

    return (int) $orderId;
}

/**
 * War Room: resolve which active-shift volunteers a location/photo/video/task
 * request targets — either every currently-active participant, or just the
 * ones the admin checked. Shared by the 4 near-identical request_* handlers
 * below (each used to run this exact query + intersection independently).
 */
function resolveRequestedActiveRecipients(int $missionId): array {
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
    return post('request_scope') === 'all'
        ? $activeIds
        : array_values(array_intersect($activeIds, array_map('intval', (array)($_POST['volunteers'] ?? []))));
}

$canManageWarRoom = canManageActionRoom($mission['responsible_user_id'] ? (int)$mission['responsible_user_id'] : null, (int)$user['id']);
$isApprovedParticipant = (bool)dbFetchValue(
    "SELECT COUNT(*) FROM participation_requests pr
     JOIN shifts s ON s.id = pr.shift_id
     WHERE s.mission_id = ? AND pr.volunteer_id = ? AND pr.status = ?",
    [$missionId, $user['id'], PARTICIPATION_APPROVED]
);
if (!$canManageWarRoom && !$isApprovedParticipant) {
    setFlash('error', t('wr.access_denied'));
    redirect('dashboard.php');
}
if ($mission['status'] !== STATUS_OPEN || empty($mission['show_in_ops'])) {
    setFlash('warning', t('wr.mission_not_active'));
    redirect('mission-view.php?id=' . $missionId);
}

$fieldMode = ($_COOKIE['wr_field_mode'] ?? '') === '1';

if (isPost()) {
    verifyCsrf();
    if (post('action') === 'close_mission') {
        if (!$canManageWarRoom) {
            setFlash('error', t('wr.perm.close_mission'));
        } else {
            dbExecute("UPDATE missions SET status = ?, updated_at = NOW() WHERE id = ? AND status = ?", [STATUS_CLOSED, $missionId, STATUS_OPEN]);
            logAudit('close_from_war_room', 'missions', $missionId, null, ['old_status' => STATUS_OPEN]);
            setFlash('success', t('wr.mission_closed_success'));
            redirect('ops-dashboard.php');
        }
    } elseif (post('action') === 'request_location') {
        if (!$canManageWarRoom) {
            setFlash('error', t('wr.perm.request_location'));
            redirect('war-room.php?id=' . $missionId);
        }

        $requestedIds = resolveRequestedActiveRecipients($missionId);

        if (empty($requestedIds)) {
            setFlash('warning', t('common.select_active_volunteer'));
        } else {
            createMissionOrderAndNotify(
                $missionId, $mission['title'], 'location', $user['id'], $requestedIds,
                'order.location.title', [], null, 'order.location.message', ['mission' => $mission['title']],
                'order.location.broadcast', ['mission' => $mission['title']]
            );
            logAudit('request_mission_location', 'missions', $missionId, null, ['recipient_ids' => $requestedIds]);
            setFlash('success', t('order.location.sent_flash', ['count' => count($requestedIds)]));
        }
        redirect('war-room.php?id=' . $missionId);
    } elseif (post('action') === 'request_photo') {
        if (!$canManageWarRoom) {
            setFlash('error', t('wr.perm.request_photo'));
            redirect('war-room.php?id=' . $missionId);
        }

        $requestedIds = resolveRequestedActiveRecipients($missionId);

        if (empty($requestedIds)) {
            setFlash('warning', t('common.select_active_volunteer'));
        } else {
            createMissionOrderAndNotify(
                $missionId, $mission['title'], 'photo', $user['id'], $requestedIds,
                'order.photo.title', [], null, 'order.photo.message', ['mission' => $mission['title']],
                'order.photo.broadcast', ['mission' => $mission['title']]
            );
            logAudit('request_mission_photo', 'missions', $missionId, null, ['recipient_ids' => $requestedIds]);
            setFlash('success', t('order.photo.sent_flash', ['count' => count($requestedIds)]));
        }
        redirect('war-room.php?id=' . $missionId);
    } elseif (post('action') === 'request_video') {
        if (!$canManageWarRoom) {
            setFlash('error', t('wr.perm.request_video'));
            redirect('war-room.php?id=' . $missionId);
        }

        $requestedIds = resolveRequestedActiveRecipients($missionId);

        if (empty($requestedIds)) {
            setFlash('warning', t('common.select_active_volunteer'));
        } else {
            createMissionOrderAndNotify(
                $missionId, $mission['title'], 'video', $user['id'], $requestedIds,
                'order.video.title', [], null, 'order.video.message', ['mission' => $mission['title']],
                'order.video.broadcast', ['mission' => $mission['title']]
            );
            logAudit('request_mission_video', 'missions', $missionId, null, ['recipient_ids' => $requestedIds]);
            setFlash('success', t('order.video.sent_flash', ['count' => count($requestedIds)]));
        }
        redirect('war-room.php?id=' . $missionId);
    } elseif (post('action') === 'request_task') {
        if (!$canManageWarRoom) {
            setFlash('error', t('wr.perm.request_task'));
            redirect('war-room.php?id=' . $missionId);
        }

        $taskText = trim((string) post('task_text'));
        $taskText = mb_substr($taskText, 0, 500);

        $requestedIds = resolveRequestedActiveRecipients($missionId);

        if ($taskText === '') {
            setFlash('warning', t('order.task.empty_warning'));
        } elseif (empty($requestedIds)) {
            setFlash('warning', t('common.select_active_volunteer'));
        } else {
            createMissionOrderAndNotify(
                $missionId, $mission['title'], 'task', $user['id'], $requestedIds,
                'order.task.title', ['mission' => $mission['title']], $taskText, '', [],
                'order.task.broadcast', ['mission' => $mission['title'], 'text' => $taskText],
                $taskText
            );
            logAudit('request_mission_task', 'missions', $missionId, null, ['recipient_ids' => $requestedIds, 'task_text' => $taskText]);
            setFlash('success', t('order.task.sent_flash', ['count' => count($requestedIds)]));
        }
        redirect('war-room.php?id=' . $missionId);
    } elseif (post('action') === 'global_message') {
        if (!$canManageWarRoom) {
            setFlash('error', t('wr.perm.global_message'));
            redirect('war-room.php?id=' . $missionId);
        }

        $broadcastText = trim((string) post('global_message_text'));
        $broadcastText = mb_substr($broadcastText, 0, 500);

        if ($broadcastText === '') {
            setFlash('warning', t('global_message.empty_warning'));
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
                'global_message.title', ['mission' => $mission['title']], $broadcastText, '', [],
                'global_message.broadcast', ['mission' => $mission['title']],
                $broadcastText
            );
            logAudit('global_message_war_room', 'missions', $missionId, null, ['message' => $broadcastText]);
            setFlash('success', t('global_message.sent_flash', ['count' => count($recipientIds)]));
        }
        redirect('war-room.php?id=' . $missionId);
    } elseif (post('action') === 'create_team') {
        if (!$canManageWarRoom) {
            setFlash('error', t('wr.perm.create_team'));
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
            setFlash('warning', t('team.create.select_member_warning'));
        } elseif (!in_array($leaderId, $memberIds, true)) {
            setFlash('warning', t('team.leader_must_be_member'));
        } else {
            $teamCount = (int) dbFetchValue("SELECT COUNT(*) FROM mission_teams WHERE mission_id = ?", [$missionId]);
            $codename = MISSION_TEAM_CODENAMES[$teamCount % count(MISSION_TEAM_CODENAMES)];
            $teamColor = MISSION_TEAM_COLORS[$teamCount % count(MISSION_TEAM_COLORS)];

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
                setFlash('error', t('team.create.number_failed'));
            } else {
                $teamId = dbInsert(
                    "INSERT INTO mission_teams (mission_id, codename, team_number, color, leader_id, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())",
                    [$missionId, $codename, $teamNumber, $teamColor, $leaderId, $user['id']]
                );
                foreach ($memberIds as $memberId) {
                    dbInsert(
                        "INSERT INTO mission_team_members (team_id, mission_id, user_id, added_at) VALUES (?, ?, ?, NOW())",
                        [$teamId, $missionId, $memberId]
                    );
                }
                logAudit('create_mission_team', 'mission_teams', $teamId, null, ['mission_id' => $missionId, 'member_ids' => $memberIds, 'leader_id' => $leaderId]);
                notifyMissionTeamMembers($missionId, $mission['title'], $codename, $teamNumber, $memberIds, $leaderId, $namesByUserId);
                setFlash('success', t('team.create.success_flash', ['team' => $codename . ' ' . $teamNumber]));
            }
        }
        redirect('war-room.php?id=' . $missionId);
    } elseif (post('action') === 'update_team') {
        if (!$canManageWarRoom) {
            setFlash('error', t('wr.perm.update_team'));
            redirect('war-room.php?id=' . $missionId);
        }

        $teamId = (int) post('team_id');
        $team = dbFetchOne("SELECT * FROM mission_teams WHERE id = ? AND mission_id = ?", [$teamId, $missionId]);
        if (!$team) {
            setFlash('error', t('common.team_not_found'));
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
            setFlash('warning', t('team.update.select_member_warning'));
        } elseif (!in_array($leaderId, $memberIds, true)) {
            setFlash('warning', t('team.leader_must_be_member'));
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
            setFlash('success', t('team.update.success_flash', ['team' => $team['codename'] . ' ' . $team['team_number']]));
        }
        redirect('war-room.php?id=' . $missionId);
    } elseif (post('action') === 'delete_team') {
        if (!$canManageWarRoom) {
            setFlash('error', t('wr.perm.delete_team'));
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
            $formerMemberLangs = getUserLanguages(array_column($formerMembers, 'user_id'));
            foreach ($formerMembers as $member) {
                $lang = $formerMemberLangs[(int)$member['user_id']] ?? DEFAULT_LANGUAGE;
                sendNotification(
                    (int)$member['user_id'],
                    t('team.delete.notify_title', [], $lang),
                    t('team.delete.notify_message', ['team' => $teamLabel, 'mission' => $mission['title']], $lang),
                    'warning', '', ['url' => $warRoomUrl]
                );
            }
            setFlash('success', t('team.delete.success_flash', ['team' => $teamLabel]));
        } else {
            setFlash('error', t('common.team_not_found'));
        }
        redirect('war-room.php?id=' . $missionId);
    } elseif (post('action') === 'report_shortage') {
        if (!$isApprovedParticipant) {
            setFlash('error', t('wr.perm.report_shortage'));
            redirect('war-room.php?id=' . $missionId);
        }

        $allowedTypes = ['people', 'equipment', 'medical', 'vehicle', 'other'];
        $allowedSeverities = ['low', 'medium', 'high', 'critical'];
        $shortageType = post('shortage_type');
        $severity = post('severity');
        $title = mb_substr(trim((string) post('title')), 0, 255);
        $description = mb_substr(trim((string) post('description')), 0, 2000);

        if (!in_array($shortageType, $allowedTypes, true) || !in_array($severity, $allowedSeverities, true)) {
            setFlash('error', t('shortage.invalid_fields'));
        } elseif ($title === '' || $description === '') {
            setFlash('warning', t('shortage.missing_fields'));
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
            $isLoud = in_array($severity, ['high', 'critical'], true);
            $shortageRecipientLangs = getUserLanguages($recipientIds);
            foreach ($recipientIds as $recipientId) {
                $lang = $shortageRecipientLangs[$recipientId] ?? DEFAULT_LANGUAGE;
                $notifTitle = t('shortage.notify_title', ['mission' => $mission['title']], $lang);
                $notifMessage = t('shortage.notify_message', [
                    'name' => h($user['name']),
                    'type' => shortageTypeLabel($shortageType, $lang),
                    'severity' => shortageSeverityLabel($severity, $lang),
                    'title' => $title,
                ], $lang);
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
            setFlash('success', t('shortage.submitted_flash'));
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
            u.name, u.phone, u.is_external, u.guest_org_name, s.id AS shift_id, s.start_time, s.end_time,
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
            "SELECT vp.user_id, vp.shift_id, vp.lat, vp.lng, vp.created_at, u.name, mt.color AS team_color{$field}
             FROM volunteer_pings vp
             JOIN shifts s ON s.id = vp.shift_id
             JOIN users u ON u.id = vp.user_id
             LEFT JOIN participation_requests pr ON pr.shift_id = vp.shift_id AND pr.volunteer_id = vp.user_id
             LEFT JOIN mission_team_members mtm ON mtm.user_id = vp.user_id AND mtm.mission_id = s.mission_id
             LEFT JOIN mission_teams mt ON mt.id = mtm.team_id
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
        'status' => $pin['field_status'], 'team_color' => $pin['team_color'], 'time' => date('H:i', strtotime($pin['created_at']))
    ], $loadPins());

    // Every new banner-worthy notification since the client's last checkpoint
    // is returned (not just the latest) so concurrent alerts each get their
    // own scrolling row client-side instead of the newest silently replacing
    // an older one that arrived in the same 5s poll window. Ascending order:
    // the client prepends each row in turn, so the last one processed (the
    // newest) ends up on top, same as if they'd arrived one at a time.
    $bannerAfterId = (int) get('banner_after');
    $bannerRows = dbFetchAll(
        "SELECT id, message, data FROM notifications WHERE user_id = ? AND id > ? AND JSON_EXTRACT(data, '$.bannerMission') = ? ORDER BY id ASC",
        [$user['id'], $bannerAfterId, $missionId]
    );
    $banners = [];
    foreach ($bannerRows as $bannerRow) {
        $bannerData = json_decode((string) $bannerRow['data'], true);
        $rawOrderId = $bannerData['orderId'] ?? null;
        $orderId = null;
        if ($rawOrderId) {
            $acked = (bool) dbFetchValue(
                "SELECT acknowledged_at FROM mission_order_recipients WHERE order_id = ? AND user_id = ?",
                [$rawOrderId, $user['id']]
            );
            if (!$acked) {
                $orderId = (int) $rawOrderId;
            }
        }
        $banners[] = ['id' => (int) $bannerRow['id'], 'message' => $bannerRow['message'], 'orderId' => $orderId];
    }

    $dispatches = loadMissionDispatchesForUser($missionId, (int)$user['id'], $canManageWarRoom, $isApprovedParticipant);
    $photos = loadMissionPhotosForUser($missionId, (int)$user['id'], $canManageWarRoom);
    $myTasks = loadMyTaskOrdersForUser($missionId, (int)$user['id']);
    $shortageReports = $canManageWarRoom ? loadUnresolvedShortageReportsForMission($missionId) : [];
    $sosAlerts = $canManageWarRoom ? loadOpenSosAlertsForMission($missionId) : [];
    $onlinePresence = loadOnlinePresenceUserIds($missionId);

    echo json_encode([
        'pins' => $pins,
        'time' => date('H:i:s'),
        'banners' => $banners,
        'dispatches' => $dispatches,
        'media' => $photos,
        'myTasks' => $myTasks,
        'shortageReports' => $shortageReports,
        'sosAlerts' => $sosAlerts,
        'onlinePresence' => $onlinePresence,
    ]);
    exit;
}

$pins = array_map(fn($pin) => [
    'lat' => (float)$pin['lat'], 'lng' => (float)$pin['lng'], 'name' => $pin['name'],
    'status' => $pin['field_status'], 'team_color' => $pin['team_color'], 'time' => date('H:i', strtotime($pin['created_at']))
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
$sosAlerts = $canManageWarRoom ? loadOpenSosAlertsForMission($missionId) : [];

$firstShift = $shifts[0]['start_time'] ?? $mission['start_datetime'];
$lastShift = !empty($shifts) ? end($shifts)['end_time'] : $mission['end_datetime'];
$now = time();
$timeState = strtotime($firstShift) > $now ? 'upcoming' : (strtotime($lastShift) < $now ? 'overdue' : 'active');
$activeParticipants = array_values(array_filter($participants, fn($participant) =>
    strtotime($participant['start_time']) <= $now && strtotime($participant['end_time']) > $now
));

// ── Mission teams ─────────────────────────────────────────────────────────
$teamRows = dbFetchAll(
    "SELECT mt.id, mt.codename, mt.team_number, mt.color, mt.leader_id, l.name AS leader_name,
            l.is_external AS leader_is_external, l.guest_org_name AS leader_guest_org_name,
            mtm.user_id, u.name AS member_name, u.is_external AS member_is_external, u.guest_org_name AS member_guest_org_name
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
            'color' => $row['color'],
            'leader_id' => $row['leader_id'] !== null ? (int)$row['leader_id'] : null,
            'leader_name' => $row['leader_name'],
            'leader_is_external' => (bool) $row['leader_is_external'],
            'leader_guest_org_name' => $row['leader_guest_org_name'],
            'members' => [],
        ];
    }
    if ($row['user_id'] !== null) {
        $teams[$tid]['members'][] = [
            'user_id' => (int)$row['user_id'], 'name' => $row['member_name'],
            'is_external' => (bool) $row['member_is_external'], 'guest_org_name' => $row['member_guest_org_name'],
        ];
    }
}

$teamLabelByUserId = [];
$teamColorByUserId = [];
foreach ($teams as $team) {
    $label = $team['codename'] . ' ' . $team['team_number'];
    foreach ($team['members'] as $member) {
        $teamLabelByUserId[$member['user_id']] = $label;
        $teamColorByUserId[$member['user_id']] = $team['color'];
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

$pageTitle = 'Action Room — ' . $mission['title'];
$currentPage = 'war-room';
include __DIR__ . '/includes/header.php';
?>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<style>
    #warRoomMap { height: 520px; border-radius: 12px; }
    #mapCard.map-fullscreen-active { position: fixed; inset: 0; z-index: 1040; border-radius: 0; }
    #mapCard.map-fullscreen-active #warRoomMap { height: 100%; border-radius: 0; }
    #mapCard.map-fullscreen-active #warRoomBanner { position: absolute; left: 0; right: 0; bottom: 0; z-index: 600; border-top: 2px solid #dc2626; border-bottom: none; }
    .war-room-hero { background: linear-gradient(135deg, #172554, #b91c1c); color: #fff; border-radius: 14px; }
    .war-room-hero h1 { color: #fff; font-weight: 700; }
    .participant-row { border-left: 4px solid #e2e8f0; }
    .participant-row.needs-help { border-left-color: #dc2626; }
    .presence-dot { display: inline-block; width: 9px; height: 9px; border-radius: 50%; margin-right: 4px; }
    .presence-dot.presence-online { background: #28a745; }
    .presence-dot.presence-offline { background: #adb5bd; }
    .war-room-banner { display: none; flex-direction: column; background: #000; border-bottom: 2px solid #dc2626; position: relative; z-index: 1900; max-height: 40vh; overflow-y: auto; }
    .war-room-banner-row { display: flex; align-items: center; gap: 10px; padding: 8px 12px; }
    .war-room-banner-row + .war-room-banner-row { border-top: 1px solid rgba(255,59,48,.35); }
    .war-room-banner-track { flex: 1; overflow: hidden; white-space: nowrap; position: relative; height: 1.6em; }
    .war-room-banner-track span { display: inline-block; position: absolute; white-space: nowrap; padding-left: 100%; color: #ff3b30; font-weight: 700; text-transform: uppercase; letter-spacing: .02em; animation: warRoomBannerScroll 14s linear infinite; }
    @keyframes warRoomBannerScroll { 0% { transform: translateX(0); } 100% { transform: translateX(-100%); } }
    .war-room-banner .bi-broadcast { color: #ff3b30; flex-shrink: 0; }
    .war-room-banner-close { background: transparent; border: none; color: #ff3b30; font-size: 1.3rem; line-height: 1; cursor: pointer; padding: 0 4px; flex-shrink: 0; }
    @keyframes warRoomPulseRed { 0%, 100% { box-shadow: 0 0 0 0 rgba(220,53,69,0); } 50% { box-shadow: 0 0 0 10px rgba(220,53,69,0.4); } }
    #sosOverlay { position: fixed; inset: 0; pointer-events: none; z-index: 2000; display: none; }
    #sosOverlay.sos-active { display: block; animation: sosPulseCorners 1s ease-in-out infinite; }
    #sosOverlay.sos-calm { display: block; animation: none; box-shadow: inset 0 0 120px 40px rgba(220,38,38,.35); }
    @keyframes sosPulseCorners {
        0%, 100% { box-shadow: inset 0 0 60px 20px rgba(220,38,38,.25), inset 0 0 160px 60px rgba(220,38,38,.12); }
        50%      { box-shadow: inset 0 0 120px 50px rgba(220,38,38,.65), inset 0 0 260px 120px rgba(220,38,38,.35); }
    }
    .sos-map-marquee { position: absolute; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,.75); padding: 6px 10px; overflow: hidden; z-index: 500; }
    .sos-map-marquee-track { white-space: nowrap; position: relative; height: 1.4em; }
    .sos-map-marquee-track span { display: inline-block; position: absolute; white-space: nowrap; padding-left: 100%; color: #ff3b30; font-weight: 700; text-transform: uppercase; letter-spacing: .02em; animation: warRoomBannerScroll 14s linear infinite; }
    /* Focus mode: reclaim the app's own left sidebar for more War Room room. */
    body.war-room-focus .sidebar,
    body.war-room-focus .sidebar-overlay,
    body.war-room-focus .sidebar-toggle { display: none; }
    body.war-room-focus .main-content { margin-left: 0; }
    #mediaList { display: grid; grid-template-columns: 1fr 1fr; gap: .5rem; align-content: start; }
</style>

<div class="war-room-hero p-4 mb-4 shadow-sm">
    <div class="d-flex flex-wrap justify-content-between gap-3 align-items-start">
        <div>
            <div class="text-uppercase small fw-semibold opacity-75 mb-1"><i class="bi bi-broadcast-pin me-1"></i><?= t('hero.eyebrow') ?></div>
            <h1 class="h3 mb-2"><?= h($mission['title']) ?></h1>
            <div class="small opacity-75"><i class="bi bi-geo-alt me-1"></i><?= h($mission['location']) ?> · <?= formatDateTime($firstShift) ?> <?= t('hero.until') ?> <?= formatDateTime($lastShift) ?></div>
        </div>
        <div class="d-flex gap-2 align-items-center">
            <span class="badge fs-6 <?= $timeState === 'active' ? 'bg-success' : ($timeState === 'upcoming' ? 'bg-info text-dark' : 'bg-warning text-dark') ?>">
                <?= $timeState === 'active' ? t('hero.status_active') : ($timeState === 'upcoming' ? t('hero.status_upcoming') : t('hero.status_overdue')) ?>
            </span>
            <?php if ($canManageWarRoom && !$fieldMode): ?>
            <button type="button" class="btn btn-outline-light" data-bs-toggle="modal" data-bs-target="#reportModal"><i class="bi bi-stopwatch me-1"></i><?= t('hero.btn_response_report') ?></button>
            <button type="button" class="btn btn-outline-light" onclick="window.open('mission-report-print.php?mission_id=<?= $missionId ?>', '_blank')"><i class="bi bi-printer me-1"></i><?= t('hero.btn_pdf_report') ?></button>
            <button type="button" id="trailModeToggle" class="btn btn-outline-light"><i class="bi bi-clock-history me-1"></i><?= t('hero.btn_team_trail') ?></button>
            <?php endif; ?>
            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="toggle_field_mode">
                <button type="submit" class="btn btn-outline-light">
                    <i class="bi bi-<?= $fieldMode ? 'grid-3x3-gap' : 'geo-alt' ?> me-1"></i><?= $fieldMode ? t('hero.btn_full_view') : t('hero.btn_field_mode') ?>
                </button>
            </form>
            <button type="button" id="warRoomFocusToggle" class="btn btn-outline-light"><i class="bi bi-arrows-fullscreen me-1"></i><?= t('hero.btn_fullscreen') ?></button>
            <a href="ops-dashboard.php" class="btn btn-light"><i class="bi bi-arrow-left me-1"></i><?= t('hero.btn_back_ops') ?></a>
        </div>
    </div>
</div>

<?= showFlash() ?>

<div id="warRoomBanner" class="war-room-banner"></div>

<?php if ($canManageWarRoom): ?>
<div id="sosOverlay"></div>
<?php endif; ?>

<?php if (!$fieldMode): ?>
<div class="row g-4 mb-4">
    <div class="col-lg-8">
        <div class="card shadow-sm h-100" id="mapCard">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-map me-1"></i><?= t('map.title') ?></h5>
                <div class="d-flex align-items-center gap-2">
                    <small class="text-muted"><?= t('common.updated_label') ?> <span id="mapRefresh"><?= date('H:i:s') ?></span></small>
                    <button type="button" id="mapFullscreenToggle" class="btn btn-sm btn-outline-secondary" title="<?= t('map.btn_fullscreen') ?>">
                        <i class="bi bi-arrows-fullscreen"></i>
                    </button>
                </div>
            </div>
            <?php if ($canManageWarRoom): ?>
            <div class="card-header bg-light border-top d-none" id="trailFilterBar">
                <div class="row g-2 align-items-end">
                    <div class="col-6 col-md-3">
                        <label class="form-label small fw-semibold mb-1"><?= t('trail.team_label') ?></label>
                        <select class="form-select form-select-sm" id="trailTeamSelect">
                            <option value=""><?= t('common.all_teams') ?></option>
                            <?php foreach ($teams as $team): ?>
                            <option value="<?= $team['id'] ?>"><?= h($team['codename'] . ' ' . $team['team_number']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-6 col-md-5">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="trailIncludeAdmin" checked>
                            <label class="form-check-label small" for="trailIncludeAdmin"><?= t('trail.include_admin_points') ?></label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="trailIncludeAuto">
                            <label class="form-check-label small" for="trailIncludeAuto"><?= t('trail.include_auto') ?></label>
                        </div>
                    </div>
                    <div class="col-12 col-md-4">
                        <button type="button" class="btn btn-sm btn-primary w-100" id="trailApplyBtn"><i class="bi bi-funnel-fill me-1"></i><?= t('trail.apply_btn') ?></button>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            <div class="card-body p-0" style="position:relative;">
                <div id="warRoomMap"></div>
                <?php if ($canManageWarRoom): ?>
                <div id="sosMapMarquee" class="sos-map-marquee d-none">
                    <div class="sos-map-marquee-track"><span id="sosMapMarqueeText"></span></div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card shadow-sm h-100">
            <div class="card-header"><h5 class="mb-0"><i class="bi bi-camera-fill me-1"></i><?= t('media.panel_title') ?></h5></div>
            <div class="card-body d-flex flex-column" style="height:520px;">
                <?php if ($isApprovedParticipant): ?>
                <div class="d-flex gap-2 mb-2">
                    <label class="btn btn-primary w-100 mb-0">
                        <i class="bi bi-camera-fill me-1"></i><?= t('media.photo_btn') ?>
                        <input type="file" id="photoCaptureInput" accept="image/*" capture="environment" class="d-none">
                    </label>
                    <label class="btn btn-outline-primary w-100 mb-0">
                        <i class="bi bi-images me-1"></i><?= t('media.gallery_btn') ?>
                        <input type="file" id="photoGalleryInput" accept="image/*" class="d-none">
                    </label>
                </div>
                <div class="d-flex gap-2 mb-2">
                    <label class="btn btn-primary w-100 mb-0">
                        <i class="bi bi-camera-reels-fill me-1"></i><?= t('media.video_btn') ?>
                        <input type="file" id="videoCaptureInput" accept="video/*" capture="environment" class="d-none">
                    </label>
                    <label class="btn btn-outline-primary w-100 mb-0">
                        <i class="bi bi-images me-1"></i><?= t('media.gallery_btn') ?>
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
                <h5 class="mb-0"><i class="bi bi-diagram-3 me-1"></i><?= t('teams.panel_title') ?></h5>
                <?php if ($canManageWarRoom && !empty($unassignedApproved)): ?>
                <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#createTeamModal">
                    <i class="bi bi-plus-lg me-1"></i><?= t('teams.new_btn') ?>
                </button>
                <?php endif; ?>
            </div>
            <div class="list-group list-group-flush">
                <?php foreach ($teams as $team): ?>
                <?php [$teamBg, $teamFg] = teamBadgeColors($team['color']); ?>
                <div class="list-group-item">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                        <div>
                            <span class="badge fs-6 me-2" style="background:<?= h($teamBg) ?>;color:<?= h($teamFg) ?>;"><?= h($team['codename'] . ' ' . $team['team_number']) ?></span>
                            <?php if ($team['leader_name']): ?>
                            <span class="small text-muted"><i class="bi bi-star-fill text-warning me-1"></i><?= guestNameHtml($team['leader_name'], $team['leader_is_external'], $team['leader_guest_org_name']) ?></span>
                            <?php endif; ?>
                            <div class="small mt-2">
                                <?php foreach ($team['members'] as $member): ?>
                                <span class="badge bg-light text-dark border me-1 mb-1"><?= guestNameHtml($member['name'], $member['is_external'], $member['guest_org_name']) ?><?= $member['user_id'] === $team['leader_id'] ? ' ⭐' : '' ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php if ($canManageWarRoom): ?>
                        <div class="d-flex gap-1">
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#editTeamModal-<?= $team['id'] ?>" title="<?= t('common.edit') ?>"><i class="bi bi-pencil"></i></button>
                            <form method="post" onsubmit="return confirm('<?= h(addslashes(t('teams.delete_confirm', ['team' => $team['codename'] . ' ' . $team['team_number']]))) ?>')">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="delete_team">
                                <input type="hidden" name="team_id" value="<?= $team['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="<?= t('teams.delete_btn_title') ?>"><i class="bi bi-x-lg"></i></button>
                            </form>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php if (empty($teams)): ?>
                <div class="list-group-item text-muted"><?= t('teams.empty') ?></div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header"><h5 class="mb-0"><i class="bi bi-people me-1"></i><?= t('participants.panel_title', ['count' => count($participants)]) ?></h5></div>
            <div class="list-group list-group-flush">
                <?php foreach ($participants as $participant): ?>
                <?php $status = $participant['field_status'] ?? ''; ?>
                <div class="list-group-item participant-row <?= $status === 'needs_help' ? 'needs-help' : '' ?> d-flex justify-content-between align-items-center gap-2 flex-wrap">
                    <div><span id="presence-<?= (int)$participant['volunteer_id'] ?>" class="presence-dot <?= in_array((int)$participant['volunteer_id'], $onlinePresenceIds, true) ? 'presence-online' : 'presence-offline' ?>" title="<?= in_array((int)$participant['volunteer_id'], $onlinePresenceIds, true) ? t('common.online') : t('common.offline') ?>"></span><strong><?= guestNameHtml($participant['name'], (bool)$participant['is_external'], $participant['guest_org_name']) ?></strong><?php if (isset($teamLabelByUserId[(int)$participant['volunteer_id']])): [$pBg, $pFg] = teamBadgeColors($teamColorByUserId[(int)$participant['volunteer_id']] ?? null); ?> <span class="badge" style="background:<?= h($pBg) ?>;color:<?= h($pFg) ?>;"><?= h($teamLabelByUserId[(int)$participant['volunteer_id']]) ?></span><?php endif; ?><br><small class="text-muted"><?= formatDateTime($participant['start_time']) ?> – <?= date('H:i', strtotime($participant['end_time'])) ?><?= $participant['last_ping_at'] ? t('participants.last_ping_label', ['time' => date('H:i', strtotime($participant['last_ping_at']))]) : t('participants.no_ping') ?></small></div>
                    <span class="badge <?= $status === 'needs_help' ? 'bg-danger' : ($status === 'on_site' ? 'bg-success' : ($status === 'on_way' ? 'bg-warning text-dark' : 'bg-secondary')) ?>">
                        <?= $status === 'needs_help' ? t('status.badge_needs_help') : ($status === 'on_site' ? t('status.badge_on_site') : ($status === 'on_way' ? t('status.badge_on_way') : t('status.badge_none'))) ?>
                    </span>
                </div>
                <?php endforeach; ?>
                <?php if (empty($participants)): ?><div class="list-group-item text-muted"><?= t('participants.empty') ?></div><?php endif; ?>
            </div>
        </div>

        <?php if ($canManageWarRoom): ?>
        <div class="row g-4 mt-0">
            <div class="col-md-6">
                <div class="card shadow-sm h-100 border-warning">
                    <div class="card-header bg-warning bg-opacity-25"><h5 class="mb-0"><i class="bi bi-bell-fill me-1"></i><?= t('request.location.card_title') ?></h5></div>
                    <div class="card-body">
                        <?php if (empty($activeParticipants)): ?>
                            <p class="text-muted mb-0"><?= t('common.no_active_now') ?></p>
                        <?php else: ?>
                            <p class="small text-muted"><?= t('common.push_vibrate_note') ?></p>
                            <form method="post">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="request_location">
                                <button type="submit" name="request_scope" value="all" class="btn btn-warning w-100 fw-semibold mb-3">
                                    <i class="bi bi-broadcast me-1"></i><?= t('common.request_all_active', ['count' => count($activeParticipants)]) ?>
                                </button>
                                <div class="small fw-semibold mb-2"><?= t('common.or_select_volunteers') ?></div>
                                <div class="border rounded p-2 mb-3" style="max-height:190px;overflow:auto;">
                                    <?php foreach ($activeParticipants as $participant): ?>
                                    <label class="form-check d-flex align-items-center justify-content-between gap-2 py-1">
                                        <span><input class="form-check-input me-2" type="checkbox" name="volunteers[]" value="<?= $participant['volunteer_id'] ?>"><?= h($participant['name']) ?></span>
                                        <small class="text-muted"><?= $participant['last_ping_at'] ? date('H:i', strtotime($participant['last_ping_at'])) : t('common.no_ping_short') ?></small>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                                <button type="submit" name="request_scope" value="selected" class="btn btn-outline-warning w-100 fw-semibold">
                                    <i class="bi bi-person-check me-1"></i><?= t('common.request_selected') ?>
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="card shadow-sm h-100 border-warning">
                    <div class="card-header bg-warning bg-opacity-25"><h5 class="mb-0"><i class="bi bi-camera-fill me-1"></i><?= t('request.photo.card_title') ?></h5></div>
                    <div class="card-body">
                        <?php if (empty($activeParticipants)): ?>
                            <p class="text-muted mb-0"><?= t('common.no_active_now') ?></p>
                        <?php else: ?>
                            <p class="small text-muted"><?= t('common.push_vibrate_note') ?></p>
                            <form method="post">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="request_photo">
                                <button type="submit" name="request_scope" value="all" class="btn btn-warning w-100 fw-semibold mb-3">
                                    <i class="bi bi-broadcast me-1"></i><?= t('common.request_all_active', ['count' => count($activeParticipants)]) ?>
                                </button>
                                <div class="small fw-semibold mb-2"><?= t('common.or_select_volunteers') ?></div>
                                <div class="border rounded p-2 mb-3" style="max-height:190px;overflow:auto;">
                                    <?php foreach ($activeParticipants as $participant): ?>
                                    <label class="form-check d-flex align-items-center justify-content-between gap-2 py-1">
                                        <span><input class="form-check-input me-2" type="checkbox" name="volunteers[]" value="<?= $participant['volunteer_id'] ?>"><?= h($participant['name']) ?></span>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                                <button type="submit" name="request_scope" value="selected" class="btn btn-outline-warning w-100 fw-semibold">
                                    <i class="bi bi-person-check me-1"></i><?= t('common.request_selected') ?>
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="card shadow-sm h-100 border-warning">
                    <div class="card-header bg-warning bg-opacity-25"><h5 class="mb-0"><i class="bi bi-camera-reels-fill me-1"></i><?= t('request.video.card_title') ?></h5></div>
                    <div class="card-body">
                        <?php if (empty($activeParticipants)): ?>
                            <p class="text-muted mb-0"><?= t('common.no_active_now') ?></p>
                        <?php else: ?>
                            <p class="small text-muted"><?= t('common.push_vibrate_note') ?></p>
                            <form method="post">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="request_video">
                                <button type="submit" name="request_scope" value="all" class="btn btn-warning w-100 fw-semibold mb-3">
                                    <i class="bi bi-broadcast me-1"></i><?= t('common.request_all_active', ['count' => count($activeParticipants)]) ?>
                                </button>
                                <div class="small fw-semibold mb-2"><?= t('common.or_select_volunteers') ?></div>
                                <div class="border rounded p-2 mb-3" style="max-height:190px;overflow:auto;">
                                    <?php foreach ($activeParticipants as $participant): ?>
                                    <label class="form-check d-flex align-items-center justify-content-between gap-2 py-1">
                                        <span><input class="form-check-input me-2" type="checkbox" name="volunteers[]" value="<?= $participant['volunteer_id'] ?>"><?= h($participant['name']) ?></span>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                                <button type="submit" name="request_scope" value="selected" class="btn btn-outline-warning w-100 fw-semibold">
                                    <i class="bi bi-person-check me-1"></i><?= t('common.request_selected') ?>
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="card shadow-sm h-100 border-warning">
                    <div class="card-header bg-warning bg-opacity-25"><h5 class="mb-0"><i class="bi bi-clipboard-check-fill me-1"></i><?= t('request.task.card_title') ?></h5></div>
                    <div class="card-body">
                        <?php if (empty($activeParticipants)): ?>
                            <p class="text-muted mb-0"><?= t('common.no_active_now') ?></p>
                        <?php else: ?>
                            <p class="small text-muted"><?= t('request.task.note') ?></p>
                            <form method="post">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="request_task">
                                <textarea name="task_text" class="form-control mb-2" rows="3" maxlength="500" placeholder="<?= t('request.task.placeholder') ?>" required></textarea>
                                <button type="submit" name="request_scope" value="all" class="btn btn-warning w-100 fw-semibold mb-3">
                                    <i class="bi bi-broadcast me-1"></i><?= t('common.request_all_active', ['count' => count($activeParticipants)]) ?>
                                </button>
                                <div class="small fw-semibold mb-2"><?= t('common.or_select_volunteers') ?></div>
                                <div class="border rounded p-2 mb-3" style="max-height:190px;overflow:auto;">
                                    <?php foreach ($activeParticipants as $participant): ?>
                                    <label class="form-check d-flex align-items-center justify-content-between gap-2 py-1">
                                        <span><input class="form-check-input me-2" type="checkbox" name="volunteers[]" value="<?= $participant['volunteer_id'] ?>"><?= h($participant['name']) ?></span>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                                <button type="submit" name="request_scope" value="selected" class="btn btn-outline-warning w-100 fw-semibold">
                                    <i class="bi bi-person-check me-1"></i><?= t('common.request_selected') ?>
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
                <h5 class="mb-0"><i class="bi bi-activity me-1"></i><?= t('activity.panel_title') ?></h5>
                <small class="text-muted"><?= t('common.updated_label') ?> <span id="activityRefresh"></span></small>
            </div>
            <div class="card-body">
                <div id="activityList" style="max-height:420px;overflow-y:auto;"><div class="text-muted small"><?= t('common.loading') ?></div></div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="<?= $fieldMode ? 'col-lg-6 mx-auto' : 'col-lg-4' ?>">
        <div class="card shadow-sm mb-4 border-primary">
            <div class="card-header bg-primary text-white"><h5 class="mb-0"><i class="bi bi-geo-alt-fill me-1"></i><?= t('myping.panel_title') ?></h5></div>
            <div class="card-body">
                <?php if (empty($myAssignments)): ?>
                    <p class="text-muted mb-0"><?= t('myping.no_shift') ?></p>
                <?php else: ?>
                    <p class="small text-muted"><?= t('myping.select_shift_note') ?></p>
                    <?php foreach ($myAssignments as $assignment): ?>
                    <button type="button" class="btn btn-primary w-100 mb-2 send-ping" data-shift-id="<?= $assignment['shift_id'] ?>" data-pr-id="<?= $assignment['pr_id'] ?>">
                        <i class="bi bi-send-fill me-1"></i><?= t('myping.send_btn', ['time' => date('H:i', strtotime($assignment['start_time']))]) ?>
                    </button>
                    <div class="small mb-2" id="pingStatus-<?= $assignment['pr_id'] ?>"></div>
                    <?php $myFieldStatus = $assignment['field_status'] ?? null; ?>
                    <div class="small mb-1" id="statusBadge-<?= $assignment['pr_id'] ?>">
                        <?= $myFieldStatus ? h(['on_way' => t('status.self_on_way'), 'on_site' => t('status.self_on_site'), 'needs_help' => t('status.self_sos')][$myFieldStatus] ?? '') : t('status.self_none') ?>
                    </div>
                    <div class="btn-group w-100 mb-3" role="group" id="statusBtns-<?= $assignment['pr_id'] ?>">
                        <button type="button" class="btn btn-sm <?= $myFieldStatus === 'on_way' ? 'btn-warning' : 'btn-outline-warning' ?>" onclick="setFieldStatus(this, <?= $assignment['pr_id'] ?>, 'on_way')"><?= t('myping.btn_on_way') ?></button>
                        <button type="button" class="btn btn-sm <?= $myFieldStatus === 'on_site' ? 'btn-success' : 'btn-outline-success' ?>" onclick="setFieldStatus(this, <?= $assignment['pr_id'] ?>, 'on_site')"><?= t('myping.btn_on_site') ?></button>
                        <button type="button" class="btn btn-sm <?= $myFieldStatus === 'needs_help' ? 'btn-danger' : 'btn-outline-danger' ?>" onclick="setFieldStatus(this, <?= $assignment['pr_id'] ?>, 'needs_help')"><?= t('myping.btn_sos') ?></button>
                    </div>
                    <?php endforeach; ?>
                    <p class="small text-muted mb-0"><?= t('myping.auto_note') ?></p>
                <?php endif; ?>
            </div>
        </div>

        <div class="card shadow-sm mb-4 border-primary">
            <div class="card-header bg-primary text-white"><h5 class="mb-0"><i class="bi bi-clipboard-check me-1"></i><?= t('mytasks.panel_title') ?></h5></div>
            <div class="card-body">
                <div id="myTasksList"></div>
            </div>
        </div>

        <?php if ($isApprovedParticipant): ?>
        <div class="card shadow-sm mb-4 border-warning">
            <div class="card-header bg-warning bg-opacity-25"><h5 class="mb-0"><i class="bi bi-exclamation-triangle-fill me-1"></i><?= t('shortage.card_title') ?></h5></div>
            <div class="card-body">
                <form method="post">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="report_shortage">
                    <label class="form-label small fw-semibold"><?= t('shortage.type_label') ?></label>
                    <select name="shortage_type" class="form-select mb-2" required>
                        <?php foreach (SHORTAGE_TYPE_LABELS as $val => $label): ?>
                        <option value="<?= h($val) ?>"><?= h(shortageTypeLabel($val)) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label class="form-label small fw-semibold"><?= t('shortage.severity_label') ?></label>
                    <select name="severity" class="form-select mb-2" required>
                        <?php foreach (SHORTAGE_SEVERITY_LABELS as $val => $label): ?>
                        <option value="<?= h($val) ?>" <?= $val === 'medium' ? 'selected' : '' ?>><?= h(shortageSeverityLabel($val)) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" name="title" class="form-control mb-2" maxlength="255" placeholder="<?= t('shortage.title_placeholder') ?>" required>
                    <textarea name="description" class="form-control mb-2" rows="3" maxlength="2000" placeholder="<?= t('shortage.desc_placeholder') ?>" required></textarea>
                    <button type="submit" class="btn btn-warning w-100 fw-semibold"><i class="bi bi-send-fill me-1"></i><?= t('shortage.submit_btn') ?></button>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!$fieldMode): ?>
        <div class="card shadow-sm mb-4">
            <div class="card-header"><h5 class="mb-0"><i class="bi bi-calendar-range me-1"></i><?= t('shifts.panel_title') ?></h5></div>
            <div class="list-group list-group-flush">
                <?php foreach ($shifts as $shift): ?>
                <div class="list-group-item"><strong><?= formatDateTime($shift['start_time']) ?></strong><br><small class="text-muted"><?= t('hero.until') ?> <?= date('H:i', strtotime($shift['end_time'])) ?> · <?= $shift['approved_count'] ?>/<?= $shift['max_volunteers'] ?> <?= t('shifts.approved_count_suffix') ?></small></div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($canManageWarRoom && !$fieldMode): ?>
        <div class="card shadow-sm mb-4 border-danger">
            <div class="card-header bg-danger text-white"><h5 class="mb-0"><i class="bi bi-sos me-1"></i><?= t('sos.panel_title') ?></h5></div>
            <div class="card-body">
                <div id="sosAlertsList"><p class="text-muted mb-0"><?= t('sos.empty') ?></p></div>
            </div>
        </div>

        <div class="card shadow-sm mb-4 border-danger">
            <div class="card-header bg-danger bg-opacity-10"><h5 class="mb-0"><i class="bi bi-exclamation-triangle-fill me-1 text-danger"></i><?= t('shortage.list_panel_title') ?></h5></div>
            <div class="card-body">
                <div id="shortageReportsList"></div>
            </div>
        </div>

        <div class="card shadow-sm mb-4 border-danger">
            <div class="card-header bg-danger bg-opacity-10"><h5 class="mb-0"><i class="bi bi-megaphone-fill me-1 text-danger"></i><?= t('global_message.card_title') ?></h5></div>
            <div class="card-body">
                <p class="small text-muted"><?= t('global_message.note') ?></p>
                <form method="post">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="global_message">
                    <textarea name="global_message_text" class="form-control mb-2" rows="3" maxlength="500" placeholder="<?= t('global_message.placeholder') ?>" required></textarea>
                    <button type="submit" class="btn btn-danger w-100 fw-semibold"><i class="bi bi-send-fill me-1"></i><?= t('global_message.submit_btn', ['count' => count($participants)]) ?></button>
                </form>
            </div>
        </div>

        <div class="card shadow-sm mb-4 border-primary">
            <div class="card-header bg-primary bg-opacity-10"><h5 class="mb-0"><i class="bi bi-geo-fill me-1"></i><?= t('dispatch.card_title') ?></h5></div>
            <div class="card-body">
                <p class="small text-muted"><?= t('dispatch.note') ?></p>
                <label class="form-label small fw-semibold"><?= t('dispatch.recipients_label') ?></label>
                <select class="form-select mb-3" id="dispatchTeamSelect">
                    <option value=""><?= t('common.all_teams') ?></option>
                    <?php foreach ($teams as $team): ?>
                    <option value="<?= $team['id'] ?>"><?= h($team['codename'] . ' ' . $team['team_number']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="btn btn-primary w-100 fw-semibold" data-bs-toggle="modal" data-bs-target="#dispatchMapModal">
                    <i class="bi bi-pin-map-fill me-1"></i><?= t('dispatch.send_btn') ?>
                </button>
            </div>
        </div>

        <div class="card border-danger shadow-sm">
            <div class="card-body"><h6><i class="bi bi-shield-exclamation text-danger me-1"></i><?= t('admin.mission_mgmt_title') ?></h6>
                <p class="small text-muted"><?= t('admin.close_note') ?></p>
                <form method="post" onsubmit="return confirm('<?= h(addslashes(t('admin.close_confirm'))) ?>')">
                    <?= csrfField() ?><input type="hidden" name="action" value="close_mission">
                    <button class="btn btn-danger w-100"><i class="bi bi-x-octagon-fill me-1"></i><?= t('admin.close_btn') ?></button>
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
                <h5 class="modal-title"><i class="bi bi-plus-lg me-1"></i><?= t('teams.new_btn') ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post" class="team-form" data-leader-select="#createTeamLeader">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="create_team">
                <div class="modal-body">
                    <p class="small text-muted"><?= t('teams.create_modal.select_note') ?></p>
                    <div class="border rounded p-2 mb-3" style="max-height:220px;overflow:auto;">
                        <?php foreach ($unassignedApproved as $person): ?>
                        <label class="form-check d-flex align-items-center gap-2 py-1">
                            <input class="form-check-input team-member-check" type="checkbox" name="member_ids[]" value="<?= $person['user_id'] ?>" data-name="<?= h($person['name']) ?>">
                            <span><?= h($person['name']) ?></span>
                        </label>
                        <?php endforeach; ?>
                        <?php if (empty($unassignedApproved)): ?>
                        <div class="text-muted small"><?= t('teams.create_modal.no_available') ?></div>
                        <?php endif; ?>
                    </div>
                    <label class="form-label small fw-semibold"><?= t('teams.leader_label') ?></label>
                    <select class="form-select team-leader-select" name="leader_id" id="createTeamLeader" required>
                        <option value=""><?= t('teams.select_members_first') ?></option>
                    </select>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= t('common.cancel') ?></button>
                    <button type="submit" class="btn btn-primary"><?= t('common.create') ?></button>
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
                <h5 class="modal-title"><i class="bi bi-pencil me-1"></i><?= h(t('teams.edit_modal_title', ['team' => $team['codename'] . ' ' . $team['team_number']])) ?></h5>
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
                    <label class="form-label small fw-semibold"><?= t('teams.leader_label') ?></label>
                    <select class="form-select team-leader-select" name="leader_id" id="editTeamLeader-<?= $team['id'] ?>" required data-current="<?= $team['leader_id'] ?>"></select>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= t('common.cancel') ?></button>
                    <button type="submit" class="btn btn-primary"><?= t('common.save') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<div class="card shadow-sm mb-4">
    <div class="card-header"><h5 class="mb-0"><i class="bi bi-chat-dots me-1"></i><?= t('chat.panel_title') ?></h5></div>
    <div class="card-body">
        <ul class="nav nav-pills mb-3 flex-wrap" id="chatRoomTabs">
            <li class="nav-item">
                <button type="button" class="nav-link active chat-room-tab" data-team-id=""><?= t('chat.general_room') ?></button>
            </li>
            <?php foreach ($chatTeams as $ct): ?>
            <li class="nav-item">
                <button type="button" class="nav-link chat-room-tab" data-team-id="<?= $ct['id'] ?>"><?= h($ct['codename'] . ' ' . $ct['team_number']) ?></button>
            </li>
            <?php endforeach; ?>
        </ul>
        <div id="chatMessages" class="border rounded p-3 mb-3" style="height:320px;overflow-y:auto;background:#f8f9fa;"></div>
        <form id="chatSendForm" class="d-flex gap-2">
            <textarea id="chatInput" class="form-control" rows="1" maxlength="2000" placeholder="<?= t('chat.placeholder') ?>" required></textarea>
            <button type="submit" class="btn btn-primary"><i class="bi bi-send-fill"></i></button>
        </form>
    </div>
</div>

<?php if ($canManageWarRoom): ?>
<div class="modal fade" id="reportModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-stopwatch me-1"></i><?= t('report.modal_title') ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <h6 class="text-muted small text-uppercase fw-semibold mb-2"><?= t('report.by_team') ?></h6>
                <div class="table-responsive mb-4">
                    <table class="table table-sm table-bordered align-middle">
                        <thead class="table-light">
                            <tr>
                                <th><?= t('trail.team_label') ?></th>
                                <th class="text-end"><?= t('report.col_orders') ?></th>
                                <th class="text-end"><?= t('banner.ack_btn') ?></th>
                                <th class="text-end"><?= t('report.col_completed') ?></th>
                                <th class="text-end"><?= t('report.col_avg_ack') ?></th>
                                <th class="text-end"><?= t('report.col_avg_complete') ?></th>
                            </tr>
                        </thead>
                        <tbody id="reportSummaryBody">
                            <tr><td colspan="6" class="text-muted small"><?= t('common.loading') ?></td></tr>
                        </tbody>
                    </table>
                </div>
                <h6 class="text-muted small text-uppercase fw-semibold mb-2"><?= t('report.details') ?></h6>
                <div id="reportDetailList" class="list-group list-group-flush"></div>

                <h6 class="text-muted small text-uppercase fw-semibold mb-2 mt-4"><?= t('report.shortage_by_severity') ?></h6>
                <div class="table-responsive mb-4">
                    <table class="table table-sm table-bordered align-middle">
                        <thead class="table-light">
                            <tr>
                                <th><?= t('shortage.severity_label') ?></th>
                                <th class="text-end"><?= t('report.col_reports') ?></th>
                                <th class="text-end"><?= t('report.col_seen') ?></th>
                                <th class="text-end"><?= t('report.col_resolved') ?></th>
                                <th class="text-end"><?= t('report.col_avg_seen') ?></th>
                                <th class="text-end"><?= t('report.col_avg_resolve') ?></th>
                            </tr>
                        </thead>
                        <tbody id="shortageReportSummaryBody">
                            <tr><td colspan="6" class="text-muted small"><?= t('common.loading') ?></td></tr>
                        </tbody>
                    </table>
                </div>
                <h6 class="text-muted small text-uppercase fw-semibold mb-2"><?= t('report.shortage_details') ?></h6>
                <div id="shortageReportDetailList" class="list-group list-group-flush"></div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="dispatchMapModal" tabindex="-1">
    <div class="modal-dialog modal-fullscreen">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h5 class="modal-title"><i class="bi bi-pin-map-fill me-1"></i><?= t('dispatch.card_title') ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0 d-flex flex-column">
                <div class="p-2 border-bottom d-flex flex-wrap gap-2 align-items-center bg-light">
                    <input type="text" id="dispatchAddressInput" class="form-control" style="max-width:320px;" placeholder="<?= t('dispatch.address_placeholder') ?>">
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="dispatchAddressSearch"><i class="bi bi-search me-1"></i><?= t('dispatch.search_btn') ?></button>
                    <span class="text-muted small" id="dispatchAddressStatus"></span>
                    <input type="text" id="dispatchNoteInput" class="form-control" style="max-width:260px;" maxlength="200" placeholder="<?= t('dispatch.note_placeholder') ?>">
                    <div class="ms-auto d-flex gap-2">
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="dispatchClearBtn"><i class="bi bi-arrow-counterclockwise me-1"></i><?= t('dispatch.clear_btn') ?></button>
                        <button type="button" class="btn btn-success btn-sm" id="dispatchSendBtn" disabled><i class="bi bi-send-fill me-1"></i><?= t('dispatch.send_short_btn') ?></button>
                    </div>
                </div>
                <div class="small text-muted px-2 py-1 bg-light border-bottom">
                    <?= t('dispatch.map_instructions') ?>
                </div>
                <div id="dispatchMap" style="flex:1;min-height:0;"></div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="modal fade" id="mediaViewModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content bg-dark">
            <div class="modal-header border-0 py-2">
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="<?= t('common.close') ?>"></button>
            </div>
            <div class="modal-body p-0 text-center" id="mediaViewModalBody"></div>
        </div>
    </div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
const csrfToken = '<?= csrfToken() ?>';
<?php $__wrStrings = loadLangStrings('war-room'); $__viewerLang = $user['language'] ?? DEFAULT_LANGUAGE; ?>
const WR_STRINGS = <?= json_encode($__wrStrings[$__viewerLang] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const WR_STRINGS_FALLBACK = <?= json_encode($__wrStrings[DEFAULT_LANGUAGE] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
function t(key, vars = {}) {
    let text = WR_STRINGS[key] ?? WR_STRINGS_FALLBACK[key] ?? key;
    for (const [k, v] of Object.entries(vars)) text = text.replaceAll('{' + k + '}', String(v));
    return text;
}
const jsLocale = <?= json_encode($__viewerLang === 'en' ? 'en-US' : 'el-GR') ?>;
const fieldMode = <?= $fieldMode ? 'true' : 'false' ?>;
const missionLocation = <?= json_encode(['lat' => $mission['latitude'] ? (float)$mission['latitude'] : null, 'lng' => $mission['longitude'] ? (float)$mission['longitude'] : null, 'title' => $mission['title']]) ?>;
let pins = <?= json_encode($pins) ?>;
let dispatches = <?= json_encode($dispatches) ?>;
let media = <?= json_encode($photos) ?>;
// Media re-renders every image tag from scratch (mission-photo-view.php is
// deliberately Cache-Control: no-store, since it's access-gated field media),
// so re-running renderMedia() on a poll tick where nothing actually changed
// means a real re-download of every photo/video, not just a visual flicker.
// Track what was last rendered and skip the call entirely when the fetched
// list is byte-for-byte the same.
let mediaSignature = JSON.stringify(media);
let myTasks = <?= json_encode($myTasks) ?>;
let shortageReports = <?= json_encode($shortageReports) ?>;
let sosAlerts = <?= json_encode($sosAlerts) ?>;
let map = null, pinLayer = null, dispatchLayer = null, trailLayer = null;
if (!fieldMode) {
    map = L.map('warRoomMap').setView(missionLocation.lat ? [missionLocation.lat, missionLocation.lng] : [37.97, 23.73], missionLocation.lat ? 13 : 7);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {attribution: '© OpenStreetMap'}).addTo(map);
    pinLayer = L.layerGroup().addTo(map);
    // FeatureGroup (not plain LayerGroup) is required here: only FeatureGroup
    // propagates child-layer events like 'popupopen' up to the group's own
    // listeners, which is how dispatchLayer.on('popupopen', ...) below wires up
    // the Ελήφθη/Άφιξη/Διαγραφή buttons inside each dispatch's popup.
    dispatchLayer = L.featureGroup().addTo(map);
    // Not attached to the map yet — only shown while trail mode is active
    // (enterTrailMode()/exitTrailMode() below), swapped in place of pinLayer.
    trailLayer = L.layerGroup();
}
function escapeHtml(str) {
    return String(str ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
}
// Mirrors guestNameHtml() in includes/functions.php for names that render from
// a JS poll (chat, media, dispatch, SOS, shortage) rather than server-side PHP.
function guestNameHtml(name, isExternal, orgName) {
    if (!isExternal) return escapeHtml(name);
    const org = (orgName && orgName.trim() !== '') ? orgName : t('guest.org_unknown');
    return `${escapeHtml(name)}<sup class="guest-org-badge" title="${escapeHtml(t('guest.org_tooltip', {org}))}">${escapeHtml(org)}</sup>`;
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
            ? '<div class="small text-success mt-1">' + item.acks.map(a => `✅ ${a.team_label !== '—' ? a.team_label + ' — ' : ''}${guestNameHtml(a.user_name, a.is_external, a.guest_org_name)} (${a.time})`).join('<br>') + '</div>'
            : '';
        const receiveHtml = item.can_receive
            ? `<br><button type="button" class="btn btn-sm btn-warning mt-1 dispatch-receive-btn" data-id="${item.id}"><i class="bi bi-flag me-1"></i>${t('banner.ack_btn')}</button>`
            : (item.my_receipt ? `<div class="small text-muted mt-1">${t('dispatch.received_at_prefix', {time: item.my_receipt})}</div>` : '');
        const ackHtml = item.can_ack
            ? `<br><button type="button" class="btn btn-sm btn-success mt-1 dispatch-ack-btn" data-id="${item.id}"><i class="bi bi-check-lg me-1"></i>${t('dispatch.arrival_btn')}</button>`
            : (item.my_ack ? `<div class="small text-success mt-1">${t('dispatch.arrived_at_prefix', {time: item.my_ack})}</div>` : '');
        // Google Maps opened with no "origin" resolves directions from the
        // device's own current location — simpler and more reliable than us
        // grabbing navigator.geolocation ourselves (works even if this page
        // was never granted location permission). A polygon has no single
        // point, so route to its centroid instead.
        let destLat, destLng;
        if (item.type === 'point') {
            destLat = item.geo.lat;
            destLng = item.geo.lng;
        } else {
            const sum = item.geo.reduce((acc, pt) => [acc[0] + pt[0], acc[1] + pt[1]], [0, 0]);
            destLat = sum[0] / item.geo.length;
            destLng = sum[1] / item.geo.length;
        }
        const directionsUrl = `https://www.google.com/maps/dir/?api=1&destination=${destLat},${destLng}&travelmode=driving`;
        const directionsHtml = `<br><a href="${directionsUrl}" target="_blank" rel="noopener" class="btn btn-sm btn-success mt-1"><i class="bi bi-signpost-2-fill me-1"></i>${t('dispatch.directions_btn')}</a>`;
        const popupHtml = `<strong>${item.team_label}</strong>${item.label ? '<br>' + escapeHtml(item.label) : ''}` + acksHtml + receiveHtml + ackHtml + directionsHtml +
            (item.can_delete ? `<br><button type="button" class="btn btn-sm btn-outline-danger mt-1 dispatch-delete-btn" data-id="${item.id}">${t('common.delete')}</button>` : '');
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
            if (!confirm(t('dispatch.delete_confirm'))) return;
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
                else { alert(result.error || t('common.send_failed')); ackBtn.disabled = false; }
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
                else { alert(result.error || t('common.send_failed')); receiveBtn.disabled = false; }
            });
        });
    }
});
if (missionLocation.lat) L.marker([missionLocation.lat, missionLocation.lng]).addTo(map).bindPopup('<strong>' + t('map.mission_point_label') + '</strong><br><?= h(addslashes($mission['title'])) ?>');
}
let hasFitPins = false;
function pinStatusLabel(status) {
    return {needs_help: t('status.badge_needs_help'), on_site: t('status.badge_on_site'), on_way: t('status.badge_on_way')}[status] || '';
}
function renderPins(items) {
    pinLayer.clearLayers();
    const statusColors = {needs_help:'#dc2626', on_site:'#198754', on_way:'#f59e0b'};
    items.forEach(pin => {
        // Team color takes priority (the whole point is spotting which team a
        // pin belongs to at a glance); volunteers with no team fall back to the
        // original status-based color. needs_help always gets a pulsing red
        // ring on top, team-colored or not, so that safety signal never
        // disappears just because someone's on a team.
        const color = pin.team_color || statusColors[pin.status] || '#2563eb';
        const ring = pin.status === 'needs_help'
            ? 'border:3px solid #dc2626;animation:warRoomPulseRed 1s infinite;'
            : 'border:2px solid white;';
        const icon = L.divIcon({className:'', html:`<span style="display:block;width:16px;height:16px;background:${color};${ring}border-radius:50%;box-shadow:0 1px 4px #0008"></span>`, iconSize:[16,16], iconAnchor:[8,8]});
        const statusLine = pinStatusLabel(pin.status);
        L.marker([pin.lat, pin.lng], {icon}).addTo(pinLayer).bindPopup(`<strong>${escapeHtml(pin.name)}</strong><br>${pin.time}${statusLine ? '<br>' + statusLine : ''}`);
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

// "Πορεία Ομάδων" — historical GPS trail view, toggled in place of the live
// pinLayer on the same map (not a second map instance). Own fitBounds call,
// deliberately not sharing hasFitPins above (that flag is one-shot for the
// initial live view and reusing it here would break one view or the other).
let trailModeActive = false;
function renderTrail(trails) {
    trailLayer.clearLayers();
    const bounds = [];
    trails.forEach(trail => {
        const color = trail.team_color || '#2563eb';
        const latlngs = trail.points.map(p => [p.lat, p.lng]);
        if (latlngs.length > 1) {
            L.polyline(latlngs, {color, weight: 3, opacity: 0.8}).addTo(trailLayer);
        }
        trail.points.forEach((point, i) => {
            const isLast = i === trail.points.length - 1;
            const isFirst = i === 0 && trail.points.length > 1;
            let marker;
            if (isLast) {
                const icon = L.divIcon({className:'', html:`<span style="display:block;width:16px;height:16px;background:${color};border:2px solid white;border-radius:50%;box-shadow:0 1px 4px #0008"></span>`, iconSize:[16,16], iconAnchor:[8,8]});
                marker = L.marker([point.lat, point.lng], {icon}).addTo(trailLayer);
            } else {
                marker = L.circleMarker([point.lat, point.lng], {radius: isFirst ? 7 : 5, color:'#fff', weight: isFirst ? 3 : 2, fillColor: color, fillOpacity: 1}).addTo(trailLayer);
            }
            const sourceLabel = point.source === 'auto' ? t('trail.auto_suffix') : '';
            marker.bindTooltip(`<strong>${escapeHtml(trail.name)}</strong><br>${point.time}${sourceLabel}`);
            bounds.push([point.lat, point.lng]);
        });
    });
    if (bounds.length) {
        map.invalidateSize();
        map.fitBounds(L.latLngBounds(bounds), {padding: [40, 40]});
    }
}
function enterTrailMode() {
    const teamId = document.getElementById('trailTeamSelect').value || '0';
    const includeAuto = document.getElementById('trailIncludeAuto').checked ? '1' : '0';
    const includeAdmin = document.getElementById('trailIncludeAdmin').checked;
    const params = new URLSearchParams({mission_id: <?= $missionId ?>, team_id: teamId, include_auto: includeAuto});
    fetch('mission-track.php?' + params).then(r => r.json()).then(result => {
        if (!result.ok) { alert(result.error || t('trail.load_failed')); return; }
        if (!trailModeActive) {
            map.removeLayer(pinLayer);
            trailLayer.addTo(map);
            trailModeActive = true;
        }
        if (includeAdmin) { if (!map.hasLayer(dispatchLayer)) dispatchLayer.addTo(map); }
        else if (map.hasLayer(dispatchLayer)) { map.removeLayer(dispatchLayer); }
        renderTrail(result.trails);
    }).catch(() => alert(t('trail.load_failed')));
}
function exitTrailMode() {
    trailModeActive = false;
    trailLayer.clearLayers();
    if (map.hasLayer(trailLayer)) map.removeLayer(trailLayer);
    if (!map.hasLayer(pinLayer)) pinLayer.addTo(map);
    if (!map.hasLayer(dispatchLayer)) dispatchLayer.addTo(map);
}
const trailModeToggleBtn = document.getElementById('trailModeToggle');
if (trailModeToggleBtn) {
    const trailFilterBar = document.getElementById('trailFilterBar');
    trailModeToggleBtn.addEventListener('click', () => {
        if (trailModeActive) {
            exitTrailMode();
            trailFilterBar.classList.add('d-none');
            trailModeToggleBtn.innerHTML = '<i class="bi bi-clock-history me-1"></i>' + t('hero.btn_team_trail');
        } else {
            trailFilterBar.classList.remove('d-none');
            trailModeToggleBtn.innerHTML = '<i class="bi bi-x-lg me-1"></i>' + t('trail.exit_btn');
            enterTrailMode();
        }
    });
    document.getElementById('trailApplyBtn').addEventListener('click', enterTrailMode);
}

function renderMedia(items) {
    const list = document.getElementById('mediaList');
    if (!items.length) {
        list.innerHTML = '<div class="text-muted small" style="grid-column:1/-1;">' + t('media.empty') + '</div>';
        return;
    }
    list.innerHTML = items.map(m => {
        const icon = m.media_type === 'video' ? '🎥 ' : '📷 ';
        // Team is the headline (bigger/bold) when the sender has one — the
        // individual's name drops to a small muted line underneath, rather
        // than being the only identity shown. Teamless senders keep the old
        // single-line look, just their name, since there's no team to lead with.
        const whoBlock = m.team_label
            ? `<div style="font-size:.85rem;font-weight:700;line-height:1.2;">${icon}${escapeHtml(m.team_label)}</div><div class="text-muted" style="font-size:.7rem;">${guestNameHtml(m.user_name, m.is_external, m.guest_org_name)}</div>`
            : `<div class="fw-bold" style="font-size:.8rem;">${icon}${guestNameHtml(m.user_name, m.is_external, m.guest_org_name)}</div>`;
        // Two-column grid (#mediaList below) leaves each card roughly half as
        // wide as before, so the footer stacks name-block over a
        // time+buttons row instead of the old side-by-side split, which
        // would squeeze/overflow at this width.
        return `
        <div class="card">
            ${m.media_type === 'video'
                ? `<video src="mission-photo-view.php?id=${m.id}" class="card-img-top media-view-trigger" data-id="${m.id}" data-media-type="video" style="height:90px;object-fit:cover;background:#000;cursor:pointer;" preload="metadata"></video>`
                : `<img src="mission-photo-view.php?id=${m.id}" class="card-img-top media-view-trigger" data-id="${m.id}" data-media-type="photo" style="height:90px;object-fit:cover;cursor:pointer;">`}
            <div class="card-body p-2">
                ${whoBlock}
                <div class="d-flex justify-content-between align-items-center mt-1">
                    <span class="text-muted" style="font-size:.7rem;">${m.time}</span>
                    <div class="d-flex gap-1">
                        ${m.lat !== null ? `<button type="button" class="btn btn-sm btn-outline-secondary media-locate-btn p-1" data-lat="${m.lat}" data-lng="${m.lng}" title="${t('media.locate_title')}"><i class="bi bi-geo-alt-fill" style="font-size:.7rem;"></i></button>` : ''}
                        ${m.can_delete ? `<button type="button" class="btn btn-sm btn-outline-danger media-delete-btn p-1" data-id="${m.id}" title="${t('common.delete')}"><i class="bi bi-trash" style="font-size:.7rem;"></i></button>` : ''}
                    </div>
                </div>
            </div>
        </div>
    `;
    }).join('');
    list.querySelectorAll('.media-view-trigger').forEach(el => el.addEventListener('click', () => {
        openMediaViewModal(el.dataset.id, el.dataset.mediaType);
    }));
    list.querySelectorAll('.media-locate-btn').forEach(btn => btn.addEventListener('click', () => {
        map.setView([parseFloat(btn.dataset.lat), parseFloat(btn.dataset.lng)], 16);
    }));
    list.querySelectorAll('.media-delete-btn').forEach(btn => btn.addEventListener('click', () => {
        if (!confirm(t('media.delete_confirm'))) return;
        const data = new URLSearchParams({csrf_token: csrfToken, action: 'delete', mission_id: <?= $missionId ?>, id: btn.dataset.id});
        fetch('mission-photo.php', {method:'POST', body:data}).then(r => r.json()).then(result => {
            if (result.ok) { renderMedia(media = media.filter(m => String(m.id) !== btn.dataset.id)); mediaSignature = JSON.stringify(media); }
            else alert(result.error || t('common.delete_failed'));
        });
    }));
}

// Media click opens a lightbox modal instead of a new tab — the modal body
// is emptied on close so a playing video actually stops (removing the
// element from the DOM halts playback) rather than silently continuing in
// the background.
function openMediaViewModal(id, mediaType) {
    const body = document.getElementById('mediaViewModalBody');
    body.innerHTML = mediaType === 'video'
        ? `<video src="mission-photo-view.php?id=${id}" controls autoplay style="max-width:100%;max-height:80vh;"></video>`
        : `<img src="mission-photo-view.php?id=${id}" style="max-width:100%;max-height:80vh;">`;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('mediaViewModal')).show();
}
document.getElementById('mediaViewModal').addEventListener('hidden.bs.modal', () => {
    document.getElementById('mediaViewModalBody').innerHTML = '';
});

function renderMyTasks(items) {
    const list = document.getElementById('myTasksList');
    if (!items.length) {
        list.innerHTML = '<p class="text-muted mb-0">' + t('mytasks.empty') + '</p>';
        return;
    }
    list.innerHTML = items.map(task => {
        let actionHtml;
        if (task.fulfilled_at) {
            actionHtml = `<span class="badge bg-success">${t('mytasks.completed_at_prefix', {time: task.fulfilled_at})}</span>`;
        } else if (task.acknowledged_at) {
            actionHtml = `<button type="button" class="btn btn-sm btn-success w-100 my-task-complete-btn" data-order-id="${task.order_id}">${t('mytasks.complete_btn')}</button>`;
        } else {
            actionHtml = `<button type="button" class="btn btn-sm btn-warning w-100 my-task-ack-btn" data-order-id="${task.order_id}">${t('banner.ack_btn')}</button>`;
        }
        return `<div class="border rounded p-2 mb-2">
            <div class="small">${escapeHtml(task.task_text)}</div>
            <div class="text-muted" style="font-size:.75rem;">${t('mytasks.sent_prefix', {time: task.sent_at})}</div>
            <div class="mt-1">${actionHtml}</div>
        </div>`;
    }).join('');
    list.querySelectorAll('.my-task-ack-btn').forEach(btn => btn.addEventListener('click', () => {
        btn.disabled = true;
        const data = new URLSearchParams({csrf_token: csrfToken, action: 'acknowledge', order_id: btn.dataset.orderId});
        fetch('mission-order.php', {method: 'POST', body: data}).then(r => r.json()).then(result => {
            if (result.ok) {
                const item = myTasks.find(task => String(task.order_id) === btn.dataset.orderId);
                if (item) item.acknowledged_at = item.acknowledged_at || t('common.now');
                renderMyTasks(myTasks);
            } else { btn.disabled = false; alert(result.error || t('common.failed')); }
        }).catch(() => { btn.disabled = false; });
    }));
    list.querySelectorAll('.my-task-complete-btn').forEach(btn => btn.addEventListener('click', () => {
        btn.disabled = true;
        const data = new URLSearchParams({csrf_token: csrfToken, action: 'complete', order_id: btn.dataset.orderId});
        fetch('mission-order.php', {method: 'POST', body: data}).then(r => r.json()).then(result => {
            if (result.ok) {
                const item = myTasks.find(task => String(task.order_id) === btn.dataset.orderId);
                if (item) { item.fulfilled_at = t('common.now'); item.acknowledged_at = item.acknowledged_at || t('common.now'); }
                renderMyTasks(myTasks);
            } else { btn.disabled = false; alert(result.error || t('common.failed')); }
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
        el.title = isOnline ? t('common.online') : t('common.offline');
    });
}

function renderShortageReports(items) {
    const list = document.getElementById('shortageReportsList');
    if (!list) return;
    if (!items.length) {
        list.innerHTML = '<p class="text-muted mb-0">' + t('shortage.empty_list') + '</p>';
        return;
    }
    const sevColor = {low: 'secondary', medium: 'info', high: 'warning', critical: 'danger'};
    list.innerHTML = items.map(r => `
        <div class="border rounded p-2 mb-2">
            <div><span class="badge bg-${sevColor[r.severity] || 'secondary'}">${r.severity_label}</span> <strong>${r.type_label}</strong> — ${escapeHtml(r.title)}</div>
            <div class="small mt-1">${escapeHtml(r.description)}</div>
            <div class="text-muted" style="font-size:.75rem;">${guestNameHtml(r.reporter_name, r.is_external, r.guest_org_name)} (${r.team_label}) · ${r.created_at}${r.acknowledged_at ? t('shortage.seen_at_prefix', {time: r.acknowledged_at}) : ''}</div>
            <div class="mt-1">${r.acknowledged_at
                ? `<button type="button" class="btn btn-sm btn-success w-100 shortage-resolve-btn" data-report-id="${r.id}">${t('shortage.resolve_btn')}</button>`
                : `<button type="button" class="btn btn-sm btn-warning w-100 shortage-seen-btn" data-report-id="${r.id}">${t('shortage.seen_btn')}</button>`}</div>
        </div>
    `).join('');
    list.querySelectorAll('.shortage-seen-btn').forEach(btn => btn.addEventListener('click', () => {
        btn.disabled = true;
        const data = new URLSearchParams({csrf_token: csrfToken, action: 'seen', report_id: btn.dataset.reportId});
        fetch('mission-shortage.php', {method: 'POST', body: data}).then(r => r.json()).then(result => {
            if (result.ok) {
                const item = shortageReports.find(x => String(x.id) === btn.dataset.reportId);
                if (item) item.acknowledged_at = item.acknowledged_at || t('common.now');
                renderShortageReports(shortageReports);
            } else { btn.disabled = false; alert(result.error || t('common.failed')); }
        }).catch(() => { btn.disabled = false; });
    }));
    list.querySelectorAll('.shortage-resolve-btn').forEach(btn => btn.addEventListener('click', () => {
        btn.disabled = true;
        const data = new URLSearchParams({csrf_token: csrfToken, action: 'resolve', report_id: btn.dataset.reportId});
        fetch('mission-shortage.php', {method: 'POST', body: data}).then(r => r.json()).then(result => {
            if (result.ok) {
                shortageReports = shortageReports.filter(x => String(x.id) !== btn.dataset.reportId);
                renderShortageReports(shortageReports);
            } else { btn.disabled = false; alert(result.error || t('common.failed')); }
        }).catch(() => { btn.disabled = false; });
    }));
}

function renderSosAlerts(items) {
    const list = document.getElementById('sosAlertsList');
    if (!list) return;
    if (!items.length) {
        list.innerHTML = '<p class="text-muted mb-0">' + t('sos.empty') + '</p>';
        return;
    }
    list.innerHTML = items.map(a => `
        <div class="border border-danger rounded p-2 mb-2">
            <div><strong>🆘 ${a.team_label}</strong> — ${guestNameHtml(a.user_name, a.is_external, a.guest_org_name)}</div>
            <div class="text-muted" style="font-size:.75rem;">${a.created_at}${a.lat !== null ? ` · <a href="#" class="sos-locate-link" data-lat="${a.lat}" data-lng="${a.lng}">${t('sos.view_on_map')}</a>` : t('sos.no_gps')}${a.acknowledged_at ? t('sos.ack_at_prefix', {time: a.acknowledged_at}) : ''}</div>
            <div class="mt-1">${a.acknowledged_at
                ? `<button type="button" class="btn btn-sm btn-success w-100 sos-resolve-btn" data-alert-id="${a.id}">${t('shortage.resolve_btn')}</button>`
                : `<button type="button" class="btn btn-sm btn-warning w-100 sos-ack-btn" data-alert-id="${a.id}">${t('banner.ack_btn')}</button>`}</div>
        </div>
    `).join('');
    list.querySelectorAll('.sos-locate-link').forEach(link => link.addEventListener('click', (e) => {
        e.preventDefault();
        if (!fieldMode && map) { map.setView([parseFloat(link.dataset.lat), parseFloat(link.dataset.lng)], 16); }
    }));
    list.querySelectorAll('.sos-ack-btn').forEach(btn => btn.addEventListener('click', () => {
        btn.disabled = true;
        const data = new URLSearchParams({csrf_token: csrfToken, action: 'acknowledge', alert_id: btn.dataset.alertId});
        fetch('mission-sos.php', {method: 'POST', body: data}).then(r => r.json()).then(result => {
            if (result.ok) {
                const item = sosAlerts.find(x => String(x.id) === btn.dataset.alertId);
                if (item) item.acknowledged_at = item.acknowledged_at || t('common.now');
                renderSosAlerts(sosAlerts);
                if (!fieldMode) updateSosAlarmState(sosAlerts);
            } else { btn.disabled = false; alert(result.error || t('common.failed')); }
        }).catch(() => { btn.disabled = false; });
    }));
    list.querySelectorAll('.sos-resolve-btn').forEach(btn => btn.addEventListener('click', () => {
        btn.disabled = true;
        const data = new URLSearchParams({csrf_token: csrfToken, action: 'resolve', alert_id: btn.dataset.alertId});
        fetch('mission-sos.php', {method: 'POST', body: data}).then(r => r.json()).then(result => {
            if (result.ok) {
                sosAlerts = sosAlerts.filter(x => String(x.id) !== btn.dataset.alertId);
                renderSosAlerts(sosAlerts);
                if (!fieldMode) updateSosAlarmState(sosAlerts);
            } else { btn.disabled = false; alert(result.error || t('common.failed')); }
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
        status.textContent = t('media.uploading');
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
                    status.textContent = '✓ ' + sentLabel + t('media.sent_suffix');
                    status.className = 'small mb-2 text-success';
                    renderMedia(media = [result.media, ...media]);
                    mediaSignature = JSON.stringify(media);
                } else {
                    status.textContent = result.error || t('common.send_failed');
                    status.className = 'small mb-2 text-danger';
                }
                input.value = '';
            }).catch(() => { status.textContent = t('common.send_failed'); status.className = 'small mb-2 text-danger'; input.value = ''; });
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
wireMediaInput('photoCaptureInput', t('media.photo_label'));
wireMediaInput('photoGalleryInput', t('media.photo_label'));
wireMediaInput('videoCaptureInput', t('media.video_label'));
wireMediaInput('videoGalleryInput', t('media.video_label'));

setTimeout(() => {
    if (!fieldMode) { renderPins(pins); renderDispatches(dispatches); renderMedia(media); }
    renderMyTasks(myTasks);
    renderShortageReports(shortageReports);
    renderSosAlerts(sosAlerts);
    if (!fieldMode) updateSosAlarmState(sosAlerts);
}, 200);

let bannerAfterId = <?= $bannerSinceId ?>;
// notification id -> {el, timer} for every currently-showing banner row —
// concurrent alerts each get their own row/timer instead of one message
// overwriting another that's still scrolling.
const activeBannerRows = new Map();

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

// Continuous SOS siren — distinct from the one-shot triple-beep above, reuses
// the same shared warRoomAudioCtx. Guarded by sosSirenOsc so repeated calls
// across successive poll ticks don't stack additional oscillators.
let sosSirenOsc = null;
let sosSirenGain = null;
let sosSirenTimer = null;
function playSosSiren() {
    unlockWarRoomAudio();
    if (sosSirenOsc || !warRoomAudioCtx || warRoomAudioCtx.state !== 'running') return;
    const ctx = warRoomAudioCtx;
    sosSirenOsc = ctx.createOscillator();
    sosSirenGain = ctx.createGain();
    sosSirenOsc.type = 'sine';
    sosSirenGain.gain.value = 0.35;
    sosSirenOsc.connect(sosSirenGain).connect(ctx.destination);
    sosSirenOsc.frequency.setValueAtTime(500, ctx.currentTime);
    sosSirenOsc.start();
    const sweep = () => {
        if (!sosSirenOsc) return;
        const now = ctx.currentTime;
        sosSirenOsc.frequency.cancelScheduledValues(now);
        sosSirenOsc.frequency.setValueAtTime(500, now);
        sosSirenOsc.frequency.linearRampToValueAtTime(1000, now + 0.6);
        sosSirenOsc.frequency.linearRampToValueAtTime(500, now + 1.2);
        sosSirenTimer = setTimeout(sweep, 1200);
    };
    sweep();
}
function stopSosSiren() {
    if (sosSirenTimer) { clearTimeout(sosSirenTimer); sosSirenTimer = null; }
    if (sosSirenOsc) {
        try { sosSirenOsc.stop(); } catch (e) {}
        sosSirenOsc.disconnect();
        sosSirenOsc = null;
    }
    if (sosSirenGain) { sosSirenGain.disconnect(); sosSirenGain = null; }
}

// Drives the full-viewport red corner overlay + map marquee from the current
// sosAlerts list. Any unacknowledged alert = siren + pulsing corners; all
// acknowledged-but-unresolved = calm static red; no open alerts = fully off.
function updateSosAlarmState(items) {
    const overlay = document.getElementById('sosOverlay');
    if (!overlay) return;
    const anyUnacked = items.some(a => !a.acknowledged_at);
    if (!items.length) {
        overlay.classList.remove('sos-active', 'sos-calm');
        stopSosSiren();
    } else if (anyUnacked) {
        overlay.classList.add('sos-active');
        overlay.classList.remove('sos-calm');
        playSosSiren();
    } else {
        overlay.classList.remove('sos-active');
        overlay.classList.add('sos-calm');
        stopSosSiren();
    }
    const marquee = document.getElementById('sosMapMarquee');
    if (marquee) {
        if (items.length) {
            document.getElementById('sosMapMarqueeText').textContent = items.map(a =>
                t('sos.marquee_text', {team: a.team_label.toUpperCase(), name: a.user_name})
            ).join('     •••     ');
            marquee.classList.remove('d-none');
        } else {
            marquee.classList.add('d-none');
            document.getElementById('sosMapMarqueeText').textContent = '';
        }
    }
}

function showWarRoomBanner(id, text, orderId) {
    if (activeBannerRows.has(id)) return;
    playWarRoomAlertSound();

    const row = document.createElement('div');
    row.className = 'war-room-banner-row';
    row.innerHTML = `
        <i class="bi bi-broadcast"></i>
        <div class="war-room-banner-track"><span></span></div>
        <button type="button" class="btn btn-sm btn-light fw-semibold${orderId ? '' : ' d-none'}" style="flex-shrink:0;">${t('banner.ack_btn')}</button>
        <button type="button" class="war-room-banner-close" aria-label="${t('common.close')}">&times;</button>
    `;
    row.querySelector('span').textContent = text;

    if (orderId) {
        const ackBtn = row.querySelector('.btn-light');
        ackBtn.onclick = () => {
            ackBtn.disabled = true;
            const data = new URLSearchParams({csrf_token: csrfToken, action: 'acknowledge', order_id: orderId});
            fetch('mission-order.php', {method: 'POST', body: data}).then(r => r.json()).then(result => {
                if (result.ok) { ackBtn.textContent = t('banner.acked_label'); }
                else { ackBtn.disabled = false; alert(result.error || t('common.failed')); }
            }).catch(() => { ackBtn.disabled = false; });
        };
    }
    row.querySelector('.war-room-banner-close').addEventListener('click', () => hideWarRoomBannerRow(id));

    // New rows go to the top of the stack, oldest sinks toward the bottom.
    const container = document.getElementById('warRoomBanner');
    container.prepend(row);
    container.style.display = 'flex';

    const timer = setTimeout(() => hideWarRoomBannerRow(id), 60000);
    activeBannerRows.set(id, {el: row, timer});
}
function hideWarRoomBannerRow(id) {
    const entry = activeBannerRows.get(id);
    if (!entry) return;
    clearTimeout(entry.timer);
    entry.el.remove();
    activeBannerRows.delete(id);
    if (activeBannerRows.size === 0) {
        document.getElementById('warRoomBanner').style.display = 'none';
    }
}

// Focus mode: hide the app's own left sidebar and expand War Room to the
// full window width, plus request real browser fullscreen for a kiosk-style
// big-screen view. The two are tied to one button/state so a native Esc-key
// fullscreen exit also brings the sidebar back, instead of leaving it stuck
// hidden with no visible way to undo it.
(function() {
    const focusBtn = document.getElementById('warRoomFocusToggle');
    if (!focusBtn) return;
    function setFocusMode(active) {
        document.body.classList.toggle('war-room-focus', active);
        focusBtn.innerHTML = active
            ? '<i class="bi bi-fullscreen-exit me-1"></i>' + t('hero.btn_exit_fullscreen')
            : '<i class="bi bi-arrows-fullscreen me-1"></i>' + t('hero.btn_fullscreen');
    }
    focusBtn.addEventListener('click', () => {
        const entering = !document.body.classList.contains('war-room-focus');
        setFocusMode(entering);
        if (entering) {
            if (document.documentElement.requestFullscreen) {
                document.documentElement.requestFullscreen().catch(() => {});
            }
        } else if (document.fullscreenElement) {
            document.exitFullscreen().catch(() => {});
        }
    });
    document.addEventListener('fullscreenchange', () => {
        if (!document.fullscreenElement) setFocusMode(false);
    });
})();

// Map-only fullscreen: separate from Focus Mode above (that hides the whole
// app's sidebar; this just expands the live-map card itself). Driven by our
// own class rather than the :fullscreen CSS pseudo-class so the "fill the
// screen" effect works even when a real fullscreen grant isn't available,
// with the real Fullscreen API layered on top on a best-effort basis.
(function() {
    const mapFsBtn = document.getElementById('mapFullscreenToggle');
    const mapCardEl = document.getElementById('mapCard');
    if (!mapFsBtn || !mapCardEl) return;
    // The alert banner (orders/dispatch/global messages) lives at the top of
    // the whole page normally. While the map is fullscreen that's off-screen
    // from what's actually visible, so we physically relocate the same node
    // (not a clone — its close/ack button listeners and running scroll
    // animation keep working untouched) into the map card, bottom-anchored
    // like the existing SOS marquee. bannerHome remembers exactly where it
    // came from so exiting puts it back in precisely the right spot.
    const bannerEl = document.getElementById('warRoomBanner');
    const mapBodyEl = mapCardEl.querySelector('.card-body');
    const bannerHome = bannerEl ? {parent: bannerEl.parentNode, next: bannerEl.nextSibling} : null;
    function setMapFullscreen(active) {
        mapCardEl.classList.toggle('map-fullscreen-active', active);
        mapFsBtn.innerHTML = active ? '<i class="bi bi-fullscreen-exit"></i>' : '<i class="bi bi-arrows-fullscreen"></i>';
        mapFsBtn.title = active ? t('map.btn_exit_fullscreen') : t('map.btn_fullscreen');
        if (bannerEl && mapBodyEl && bannerHome) {
            if (active) {
                mapBodyEl.appendChild(bannerEl);
            } else {
                bannerHome.parent.insertBefore(bannerEl, bannerHome.next);
            }
        }
        setTimeout(() => { if (map) map.invalidateSize(); }, 150);
    }
    mapFsBtn.addEventListener('click', () => {
        const entering = !mapCardEl.classList.contains('map-fullscreen-active');
        setMapFullscreen(entering);
        if (entering) {
            if (mapCardEl.requestFullscreen) mapCardEl.requestFullscreen().catch(() => {});
        } else if (document.fullscreenElement === mapCardEl) {
            document.exitFullscreen().catch(() => {});
        }
    });
    document.addEventListener('fullscreenchange', () => {
        if (document.fullscreenElement !== mapCardEl) setMapFullscreen(false);
    });
})();

function loadActivity() {
    fetch('mission-history.php?mission_id=<?= $missionId ?>').then(r => r.json()).then(data => {
        const list = document.getElementById('activityList');
        if (!data.ok || !data.events.length) {
            list.innerHTML = '<div class="text-muted small">' + t('activity.empty') + '</div>';
            return;
        }
        list.innerHTML = data.events.map(e => `
            <div class="d-flex justify-content-between align-items-start gap-3 border-bottom py-2">
                <div><span class="me-1">${e.icon}</span>${e.text}</div>
                <small class="text-muted text-nowrap">${e.time}</small>
            </div>
        `).join('');
        document.getElementById('activityRefresh').textContent = new Date().toLocaleTimeString(jsLocale, {hour: '2-digit', minute: '2-digit'});
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
        summaryBody.innerHTML = '<tr><td colspan="6" class="text-muted small">' + t('common.loading') + '</td></tr>';
        detailList.innerHTML = '';
        shortageSummaryBody.innerHTML = '<tr><td colspan="6" class="text-muted small">' + t('common.loading') + '</td></tr>';
        shortageDetailList.innerHTML = '';
        fetch('mission-response-report.php?mission_id=<?= $missionId ?>').then(r => r.json()).then(data => {
            if (!data.ok) { summaryBody.innerHTML = `<tr><td colspan="6" class="text-danger small">${data.error}</td></tr>`; return; }

            summaryBody.innerHTML = data.summary.length ? data.summary.map(s => `
                <tr>
                    <td>${s.team_label}</td>
                    <td class="text-end">${s.order_count}</td>
                    <td class="text-end">${s.ack_rate}%</td>
                    <td class="text-end">${s.fulfill_rate}%</td>
                    <td class="text-end">${s.avg_ack_minutes !== null ? s.avg_ack_minutes + t('report.minutes_suffix') : '—'}</td>
                    <td class="text-end">${s.avg_fulfill_minutes !== null ? s.avg_fulfill_minutes + t('report.minutes_suffix') : '—'}</td>
                </tr>
            `).join('') : '<tr><td colspan="6" class="text-muted small">' + t('report.no_orders_yet') + '</td></tr>';

            detailList.innerHTML = data.detail.length ? data.detail.map(d => `
                <div class="list-group-item d-flex justify-content-between align-items-start gap-3">
                    <div>
                        <span class="me-1">${d.type_label}</span>
                        <strong>${d.team_label}</strong> — ${escapeHtml(d.user_name)}
                        ${d.label ? ' («' + escapeHtml(d.label) + '»)' : ''}
                        <div class="small text-muted">
                            ${t('mytasks.sent_prefix', {time: d.sent_at})}
                            ${d.ack_at ? t('report.detail_ack_prefix') + d.ack_at + ' (' + d.ack_minutes + t('report.minutes_suffix') + ')' : t('report.detail_ack_prefix') + '—'}
                            ${d.fulfill_at ? t('report.detail_complete_prefix') + d.fulfill_at + ' (' + d.fulfill_minutes + t('report.minutes_suffix') + ')' : t('report.detail_complete_prefix') + '—'}
                        </div>
                    </div>
                </div>
            `).join('') : '<div class="text-muted small">' + t('report.no_details') + '</div>';

            shortageSummaryBody.innerHTML = data.shortageSummary.length ? data.shortageSummary.map(s => `
                <tr>
                    <td><span class="badge bg-${({low:'secondary',medium:'info',high:'warning',critical:'danger'})[s.severity] || 'secondary'}">${s.severity_label}</span></td>
                    <td class="text-end">${s.report_count}</td>
                    <td class="text-end">${s.seen_rate}%</td>
                    <td class="text-end">${s.resolved_rate}%</td>
                    <td class="text-end">${s.avg_seen_minutes !== null ? s.avg_seen_minutes + t('report.minutes_suffix') : '—'}</td>
                    <td class="text-end">${s.avg_resolved_minutes !== null ? s.avg_resolved_minutes + t('report.minutes_suffix') : '—'}</td>
                </tr>
            `).join('') : '<tr><td colspan="6" class="text-muted small">' + t('report.no_shortage_yet') + '</td></tr>';

            shortageDetailList.innerHTML = data.shortageDetail.length ? data.shortageDetail.map(d => `
                <div class="list-group-item d-flex justify-content-between align-items-start gap-3">
                    <div>
                        <span class="badge bg-${({low:'secondary',medium:'info',high:'warning',critical:'danger'})[d.severity] || 'secondary'} me-1">${d.severity_label}</span>
                        <strong>${d.team_label}</strong> — ${escapeHtml(d.reporter_name)}
                        («${escapeHtml(d.title)}»)
                        <div class="small text-muted">
                            ${t('mytasks.sent_prefix', {time: d.sent_at})}
                            ${d.seen_at ? t('report.detail_seen_prefix') + d.seen_at + ' (' + d.seen_minutes + t('report.minutes_suffix') + ')' : t('report.detail_seen_prefix') + '—'}
                            ${d.resolved_at ? t('report.detail_resolved_prefix') + d.resolved_at + ' (' + d.resolved_minutes + t('report.minutes_suffix') + ')' : t('report.detail_resolved_prefix') + '—'}
                        </div>
                    </div>
                </div>
            `).join('') : '<div class="text-muted small">' + t('report.no_details') + '</div>';
        }).catch(() => {
            summaryBody.innerHTML = '<tr><td colspan="6" class="text-danger small">' + t('common.load_failed') + '</td></tr>';
        });
    });
}

document.querySelectorAll('.send-ping').forEach(button => button.addEventListener('click', () => {
    const status = document.getElementById('pingStatus-' + button.dataset.prId);
    if (!navigator.geolocation) { status.textContent = t('myping.gps_unsupported'); return; }
    button.disabled = true; status.textContent = t('myping.locating');
    navigator.geolocation.getCurrentPosition(position => {
        const data = new URLSearchParams({csrf_token: csrfToken, shift_id: button.dataset.shiftId, lat: position.coords.latitude, lng: position.coords.longitude});
        fetch('ping-location.php', {method:'POST', body:data}).then(response => response.json()).then(result => {
            status.textContent = result.ok ? t('myping.ping_sent_prefix', {time: result.ts}) : result.error;
            status.className = 'small mb-2 ' + (result.ok ? 'text-success' : 'text-danger');
        }).catch(() => { status.textContent = t('myping.ping_send_failed'); status.className = 'small mb-2 text-danger'; }).finally(() => button.disabled = false);
    }, () => { status.textContent = t('myping.gps_denied'); status.className = 'small mb-2 text-danger'; button.disabled = false; }, {enableHighAccuracy:true, timeout:10000});
}));

// Passive background capture while this page stays open — silent (no status
// text, doesn't touch the manual button above), tagged source=auto so it's
// excluded from alerts/history/reports and hidden from the trail view unless
// the admin explicitly filters it in. One getCurrentPosition() call per tick
// reused for every active shift assignment, not one read per button.
setInterval(() => {
    const buttons = document.querySelectorAll('.send-ping');
    if (!buttons.length || !navigator.geolocation) return;
    navigator.geolocation.getCurrentPosition(position => {
        buttons.forEach(button => {
            const data = new URLSearchParams({csrf_token: csrfToken, shift_id: button.dataset.shiftId, lat: position.coords.latitude, lng: position.coords.longitude, source: 'auto'});
            fetch('ping-location.php', {method: 'POST', body: data});
        });
    }, () => {}, {enableHighAccuracy: true, timeout: 10000});
}, 180000);

function setFieldStatus(btn, prId, status) {
    const group = document.getElementById('statusBtns-' + prId);
    if (group) group.querySelectorAll('button').forEach(b => b.disabled = true);

    const send = (lat, lng) => {
        const params = {csrf_token: csrfToken, pr_id: prId, status: status};
        if (lat !== null) { params.lat = lat; params.lng = lng; }
        fetch('volunteer-status.php', {method: 'POST', body: new URLSearchParams(params)}).then(r => r.json()).then(result => {
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
                alert(result.error || t('myping.status_update_failed'));
                if (group) group.querySelectorAll('button').forEach(b => b.disabled = false);
            }
        }).catch(() => { if (group) group.querySelectorAll('button').forEach(b => b.disabled = false); });
    };

    // SOS specifically tries to attach GPS, but never blocks on it — an alert
    // without coordinates beats no alert at all if geolocation fails/denies.
    if (status === 'needs_help' && navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(
            pos => send(pos.coords.latitude, pos.coords.longitude),
            () => send(null, null),
            {enableHighAccuracy: true, timeout: 5000}
        );
    } else {
        send(null, null);
    }
}

setInterval(() => fetch('war-room.php?id=<?= $missionId ?>&ajax=1&banner_after=' + bannerAfterId).then(response => response.json()).then(data => {
    if (!fieldMode) {
        renderPins(pins = data.pins || []);
        if (data.dispatches) renderDispatches(dispatches = data.dispatches);
        if (data.media) {
            const sig = JSON.stringify(data.media);
            if (sig !== mediaSignature) {
                mediaSignature = sig;
                renderMedia(media = data.media);
            }
        }
    }
    if (data.myTasks) renderMyTasks(myTasks = data.myTasks);
    if (data.shortageReports) renderShortageReports(shortageReports = data.shortageReports);
    if (data.sosAlerts) {
        renderSosAlerts(sosAlerts = data.sosAlerts);
        if (!fieldMode) updateSosAlarmState(sosAlerts);
    }
    if (data.onlinePresence) renderPresence(data.onlinePresence);
    if (!fieldMode) document.getElementById('mapRefresh').textContent = data.time || '';
    if (data.banners && data.banners.length) {
        data.banners.forEach(b => {
            if (b.id > bannerAfterId) bannerAfterId = b.id;
            showWarRoomBanner(b.id, b.message, b.orderId);
        });
    }
}).catch(() => {}), 5000);

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
            leaderSelect.innerHTML = '<option value="">' + t('teams.select_members_first') + '</option>';
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
        metaText.innerHTML = guestNameHtml(msg.name, msg.is_external, msg.guest_org_name) + ' · ' + escapeHtml(msg.time);
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
                if (!data.ok) { chatMessagesEl.textContent = data.error || t('chat.load_error'); return; }
                data.messages.forEach(renderMessage);
                if (data.messages.length) lastIdByRoom[teamId] = data.messages[data.messages.length - 1].id;
                chatMessagesEl.scrollTop = chatMessagesEl.scrollHeight;
            })
            .catch(() => { chatMessagesEl.textContent = t('chat.load_error'); });
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
                alert(result.error || t('common.send_failed'));
            }
        }).catch(() => alert(t('common.send_failed')));
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
    let refLayer = null;
    let drawPoints = [];
    let vertexMarkers = [];
    let shapeLayer = null;
    let isClosed = false;
    let lastAddressLabel = '';

    // Dimmed, read-only copy of what the live map currently shows (volunteer
    // pings + existing dispatch points/areas) so the admin isn't drawing a
    // new dispatch blind — reads the same `pins`/`dispatches` globals the
    // live map itself is rendered from, refreshed on every modal open.
    // Tooltip-only (no popups/buttons): this map's click handler is for
    // placing new draw points, not for managing existing ones.
    function renderDispatchContext() {
        if (!refLayer) return;
        refLayer.clearLayers();
        const statusColors = {needs_help:'#dc2626', on_site:'#198754', on_way:'#f59e0b'};
        pins.forEach(pin => {
            const color = pin.team_color || statusColors[pin.status] || '#2563eb';
            L.circleMarker([pin.lat, pin.lng], {radius:6, weight:2, color:'#fff', fillColor:color, fillOpacity:0.55, opacity:0.6})
                .addTo(refLayer)
                .bindTooltip(escapeHtml(pin.name));
        });
        dispatches.forEach(item => {
            const tooltip = item.label ? escapeHtml(item.label) : item.team_label;
            if (item.type === 'point') {
                const icon = L.divIcon({className:'', html:'<i class="bi bi-geo-alt-fill" style="font-size:22px;color:#7c3aed;opacity:0.55;filter:drop-shadow(0 1px 2px #0008);"></i>', iconSize:[22,22], iconAnchor:[11,20]});
                L.marker([item.geo.lat, item.geo.lng], {icon}).addTo(refLayer).bindTooltip(tooltip);
            } else if (item.type === 'polygon') {
                L.polygon(item.geo, {color:'#7c3aed', weight:2, opacity:0.5, fillOpacity:0.1}).addTo(refLayer).bindTooltip(tooltip);
            }
        });
    }

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
            refLayer = L.layerGroup().addTo(dispatchMap);
            dispatchMap.on('click', onMapClick);
        }
        renderDispatchContext();
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
        addressStatus.textContent = t('dispatch.searching');
        fetch('geocode-address.php?q=' + encodeURIComponent(q)).then(response => response.json()).then(result => {
            if (result.ok) {
                dispatchMap.setView([result.lat, result.lng], 16);
                lastAddressLabel = result.display_name || q;
                addressStatus.textContent = '✓ ' + lastAddressLabel;
            } else {
                addressStatus.textContent = result.error || t('dispatch.address_not_found');
            }
        }).catch(() => { addressStatus.textContent = t('dispatch.search_failed'); });
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
                alert(result.error || t('common.send_failed'));
                sendBtn.disabled = false;
            }
        }).catch(() => { alert(t('common.send_failed')); sendBtn.disabled = false; });
    });
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
