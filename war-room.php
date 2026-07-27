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

// MISSION_TEAM_COLORS/MISSION_TEAM_COLOR_TEXT and teamBadgeColors() moved to
// includes/functions.php (still same index basis as MISSION_TEAM_CODENAMES
// above) once loadMissionDispatchesForUser() there needed them too.

/**
 * Notify every team member (individually) about their team assignment.
 * $namesByUserId must map user_id => name for all ids in $memberIds/$leaderId.
 */
function notifyMissionTeamMembers(int $missionId, string $missionTitle, string $codename, ?int $teamNumber, array $memberIds, int $leaderId, array $namesByUserId): void {
    $teamLabel = teamLabel($codename, $teamNumber);
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

// A volunteer's own explicit choice (the toggle button below, which sets
// this cookie) always wins and is remembered from then on. Only on the
// very first visit, with no cookie yet, do we need a default — and that
// default should NOT be the desktop command view (map, media panel, full
// Teams/Participants lists) for someone opening this on their phone for
// the first time, mid-mission, needing the SOS button without first
// discovering and tapping a toggle they don't know exists. A simple
// User-Agent sniff is good enough for a default with a one-tap escape
// hatch already in place either way — a wrong guess here just costs one
// extra tap on the existing toggle, never a dead end.
$fieldMode = isset($_COOKIE['wr_field_mode'])
    ? $_COOKIE['wr_field_mode'] === '1'
    : (bool) preg_match('/Mobi|Android|iPhone|iPad|iPod/i', $_SERVER['HTTP_USER_AGENT'] ?? '');

if (isPost()) {
    verifyCsrf();
    if (post('action') === 'close_mission') {
        if (!$canManageWarRoom) {
            setFlash('error', t('wr.perm.close_mission'));
        } else {
            dbExecute("UPDATE missions SET status = ?, updated_at = NOW() WHERE id = ? AND status = ?", [STATUS_CLOSED, $missionId, STATUS_OPEN]);
            logAudit('close_from_war_room', 'missions', $missionId, null, ['old_status' => STATUS_OPEN]);
            notifyGuestsMissionDebriefEligible($missionId);
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
    } elseif (post('action') === 'end_mission_broadcast') {
        if (!$canManageWarRoom) {
            setFlash('error', t('wr.perm.end_mission_broadcast'));
            redirect('war-room.php?id=' . $missionId);
        }

        $recipients = dbFetchAll(
            "SELECT DISTINCT pr.volunteer_id FROM participation_requests pr
             JOIN shifts s ON s.id = pr.shift_id
             WHERE s.mission_id = ? AND pr.status = ?",
            [$missionId, PARTICIPATION_APPROVED]
        );
        $recipientIds = array_values(array_diff(
            array_map('intval', array_column($recipients, 'volunteer_id')),
            [(int) $user['id']]
        ));

        createMissionOrderAndNotify(
            $missionId, $mission['title'], 'return_to_base', $user['id'], $recipientIds,
            'end_mission_broadcast.title', ['mission' => $mission['title']], null,
            'end_mission_broadcast.message', ['mission' => $mission['title']],
            'end_mission_broadcast.message', ['mission' => $mission['title']],
            null, 'return_to_base'
        );
        logAudit('end_mission_broadcast', 'missions', $missionId);
        setFlash('success', t('end_mission_broadcast.sent_flash', ['count' => count($recipientIds)]));
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
            $customCodename = trim((string) post('custom_codename'));
            $isCustomName = $customCodename !== '';
            $codename = $isCustomName ? mb_substr($customCodename, 0, 20) : MISSION_TEAM_CODENAMES[$teamCount % count(MISSION_TEAM_CODENAMES)];
            $teamColor = MISSION_TEAM_COLORS[$teamCount % count(MISSION_TEAM_COLORS)];

            // A custom name never gets the random 2-digit suffix — that's
            // only there to disambiguate the auto NATO codenames once a
            // mission cycles past 26 teams and reuses one. An admin-chosen
            // name is assumed to already be distinct on its own.
            $teamNumber = null;
            $numberGenerationFailed = false;
            if (!$isCustomName) {
                for ($attempt = 0; $attempt < 50; $attempt++) {
                    $candidate = random_int(10, 99);
                    $exists = dbFetchValue(
                        "SELECT COUNT(*) FROM mission_teams WHERE mission_id = ? AND team_number = ?",
                        [$missionId, $candidate]
                    );
                    if (!$exists) { $teamNumber = $candidate; break; }
                }
                $numberGenerationFailed = $teamNumber === null;
            }

            if ($numberGenerationFailed) {
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
            notifyMissionTeamMembers($missionId, $mission['title'], $team['codename'], ($team['team_number'] !== null ? (int) $team['team_number'] : null), $memberIds, $leaderId, $namesByUserId);
            setFlash('success', t('team.update.success_flash', ['team' => teamLabel($team['codename'], $team['team_number'])]));
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

            $teamLabel = teamLabel($team['codename'], $team['team_number']);
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

// A participant's GPS ping (manual or auto) is flagged stale past 3x the
// passive auto-ping cadence (3 min) — enough headroom to not cry wolf over
// one missed tick's jitter, but still an honest signal once the gap is real
// (e.g. the tab got backgrounded/suspended, or geolocation permission was
// revoked). Shared by the full render and the ajax poll below so both agree.
//
// Computed via its own lightweight query (volunteer_id + last_ping_at only)
// rather than by reusing the full $participants query below — that query
// (with its name/phone/is_external/guest_org_name/shift-time joins) only
// exists for the Participants-list UI, which the ajax poll never renders,
// but every open tab hits this exact computation every 5s regardless.
$pingStaleThresholdSeconds = 540;
$pingIsStaleByVolunteerId = [];
foreach (dbFetchAll(
    "SELECT pr.volunteer_id,
            (SELECT MAX(vp.created_at) FROM volunteer_pings vp WHERE vp.user_id = pr.volunteer_id AND vp.shift_id = pr.shift_id) AS last_ping_at
     FROM participation_requests pr
     JOIN shifts s ON s.id = pr.shift_id
     WHERE s.mission_id = ? AND pr.status = ?",
    [$missionId, PARTICIPATION_APPROVED]
) as $pingRow) {
    $pingIsStaleByVolunteerId[(int)$pingRow['volunteer_id']] =
        $pingRow['last_ping_at'] !== null
        && strtotime($pingRow['last_ping_at']) < (time() - $pingStaleThresholdSeconds);
}

// Always returns each participant's LATEST ping regardless of age — a hard
// "last 2 hours" cutoff used to make someone silently vanish from the live
// map the moment their last ping aged past it, even though Team Trail (which
// has no such cutoff) still showed them. The map now shows every last-known
// position always, marking it 'is_stale' (reusing the same $pingStaleThresholdSeconds
// as the sidebar list) once it's past due, rather than hiding it outright.
$loadPins = function () use ($missionId, $hasFieldStatus, $pingStaleThresholdSeconds) {
    try {
        $field = $hasFieldStatus ? ', pr.field_status' : ', NULL AS field_status';
        $rawPins = dbFetchAll(
            "SELECT vp.user_id, vp.shift_id, vp.lat, vp.lng, vp.accuracy_meters, vp.created_at, u.name,
                    u.is_external, u.guest_org_name, mt.color AS team_color, mt.codename, mt.team_number{$field}
             FROM volunteer_pings vp
             JOIN shifts s ON s.id = vp.shift_id
             JOIN users u ON u.id = vp.user_id
             LEFT JOIN participation_requests pr ON pr.shift_id = vp.shift_id AND pr.volunteer_id = vp.user_id
             LEFT JOIN mission_team_members mtm ON mtm.user_id = vp.user_id AND mtm.mission_id = s.mission_id
             LEFT JOIN mission_teams mt ON mt.id = mtm.team_id
             WHERE s.mission_id = ?
               AND vp.id = (SELECT MAX(vp2.id) FROM volunteer_pings vp2 WHERE vp2.user_id = vp.user_id AND vp2.shift_id = vp.shift_id)
             ORDER BY vp.created_at DESC",
            [$missionId]
        );

        $pins = [];
        foreach ($rawPins as $pin) {
            $pingTs = strtotime($pin['created_at']);
            $isStale = $pingTs < (time() - $pingStaleThresholdSeconds);

            // "Moving" = the previous ping for this same person+shift is recent
            // enough to be meaningful (<=20 min gap) and far enough away to be
            // real movement rather than GPS/network-location noise. A fixed 30m
            // floor turned out to false-positive for a genuinely stationary
            // phone — enableHighAccuracy:false (deliberate, for battery) can
            // easily have 30-100m+ error on its own, so two noisy readings from
            // a standing-still phone could "move" 30m+ between them with no
            // real movement at all. Now uses each reading's own reported
            // accuracy_meters (Geolocation API's own uncertainty radius): the
            // required distance scales up to at least the combined uncertainty
            // of both fixes, with a 30m floor for when both are already tight,
            // and a more conservative fixed 75m when accuracy is missing
            // entirely (older data, or a browser that didn't report it) rather
            // than assuming the old, now-proven-too-loose 30m applies.
            $isMoving = false;
            $headingDeg = null;
            $prevPing = dbFetchOne(
                "SELECT lat, lng, accuracy_meters, created_at FROM volunteer_pings
                 WHERE user_id = ? AND shift_id = ? AND created_at < ?
                 ORDER BY created_at DESC LIMIT 1",
                [$pin['user_id'], $pin['shift_id'], $pin['created_at']]
            );
            if ($prevPing) {
                $secondsBetween = $pingTs - strtotime($prevPing['created_at']);
                if ($secondsBetween > 0 && $secondsBetween <= 1200) {
                    $distanceMeters = gpsDistanceMeters(
                        (float) $prevPing['lat'], (float) $prevPing['lng'],
                        (float) $pin['lat'], (float) $pin['lng']
                    );
                    $requiredMeters = ($prevPing['accuracy_meters'] !== null && $pin['accuracy_meters'] !== null)
                        ? max(30, (float) $prevPing['accuracy_meters'] + (float) $pin['accuracy_meters'])
                        : 75;
                    $isMoving = $distanceMeters >= $requiredMeters;
                    // Heading only means something once we've already decided
                    // this is real movement, not GPS jitter — a bearing
                    // computed between two noisy-but-stationary fixes would
                    // point in a meaningless, randomly-flipping direction.
                    if ($isMoving) {
                        $headingDeg = gpsBearingDegrees(
                            (float) $prevPing['lat'], (float) $prevPing['lng'],
                            (float) $pin['lat'], (float) $pin['lng']
                        );
                    }
                }
            }

            $pins[] = [
                'lat' => (float) $pin['lat'], 'lng' => (float) $pin['lng'], 'name' => $pin['name'],
                'status' => $pin['field_status'], 'team_color' => $pin['team_color'],
                'team_label' => $pin['codename'] ? teamLabel($pin['codename'], $pin['team_number']) : null,
                'is_external' => (bool) $pin['is_external'], 'guest_org_name' => $pin['guest_org_name'],
                'time' => date('H:i', $pingTs),
                'is_stale' => $isStale, 'is_moving' => $isMoving, 'heading_deg' => $headingDeg,
            ];
        }
        return $pins;
    } catch (Exception $e) {
        return [];
    }
};

// Nearby Teams (field-card column, both modes) + Team Distances (Teams
// panel, full view only). Same "define once, call from both the ajax
// branch and the full-page seed" shape as $loadPins above.
$loadTeamProximity = function () use ($missionId, $user) {
    $teamPositions = loadTeamPositionsForMission($missionId);

    $myPing = dbFetchOne(
        "SELECT vp.lat, vp.lng FROM volunteer_pings vp
         JOIN shifts s ON s.id = vp.shift_id
         WHERE s.mission_id = ? AND vp.user_id = ?
         ORDER BY vp.created_at DESC LIMIT 1",
        [$missionId, $user['id']]
    );
    $myTeamId = (int) dbFetchValue(
        "SELECT team_id FROM mission_team_members WHERE mission_id = ? AND user_id = ?",
        [$missionId, $user['id']]
    );

    $nearbyTeams = [];
    foreach ($teamPositions as $tp) {
        if ($myTeamId && $tp['team_id'] === $myTeamId) {
            continue; // your own team isn't "nearby", it's you
        }
        if ($myPing) {
            $tp['distance_m'] = gpsDistanceMeters((float) $myPing['lat'], (float) $myPing['lng'], $tp['lat'], $tp['lng']);
            $tp['bearing_deg'] = gpsBearingDegrees((float) $myPing['lat'], (float) $myPing['lng'], $tp['lat'], $tp['lng']);
        } else {
            // No ping of our own yet this mission — distance/direction are
            // undefined, not zero. The client shows a "send your own ping"
            // hint instead of a number for these.
            $tp['distance_m'] = null;
            $tp['bearing_deg'] = null;
        }
        $nearbyTeams[] = $tp;
    }
    usort($nearbyTeams, fn($a, $b) => ($a['distance_m'] ?? PHP_FLOAT_MAX) <=> ($b['distance_m'] ?? PHP_FLOAT_MAX));

    return ['nearbyTeams' => $nearbyTeams, 'teamDistances' => computeTeamDistanceMatrix($teamPositions)];
};

if (get('ajax') === '1') {
    header('Content-Type: application/json');

    // Releases the PHP session file lock immediately — nothing below this
    // point reads or writes $_SESSION (confirmed: $user and every flag used
    // here were already resolved above). Without this, PHP's default
    // session handler holds an exclusive lock on this session's file for the
    // whole request, and computeDispatchEta() below can make a real,
    // unbounded-by-us external HTTP call per point-dispatch (3s timeout
    // each, sequential) — a slow/unreachable OSRM would otherwise stall
    // every OTHER concurrent request from the same session (e.g. the same
    // browser tab tapping "Arrive" while this poll is still in flight)
    // behind this one, not just slow down this poll's own response.
    session_write_close();

    // Live-presence heartbeat: every open War Room tab hits this branch every
    // 15s, so it doubles as the "I'm still here" signal — no separate endpoint
    // or client timer needed.
    dbExecute(
        "INSERT INTO mission_presence (mission_id, user_id, last_seen_at) VALUES (?, ?, NOW())
         ON DUPLICATE KEY UPDATE last_seen_at = NOW()",
        [$missionId, $user['id']]
    );

    $pins = $loadPins();

    // Every new banner-worthy notification since the client's last checkpoint
    // is returned (not just the latest) so concurrent alerts each get their
    // own scrolling row client-side instead of the newest silently replacing
    // an older one that arrived in the same 5s poll window. Ascending order:
    // the client prepends each row in turn, so the last one processed (the
    // newest) ends up on top, same as if they'd arrived one at a time.
    $bannerAfterId = (int) get('banner_after');
    // banner_mission_id is a generated column (see migrations.php v108)
    // mirroring data's own 'bannerMission' JSON field — indexed, unlike the
    // JSON_EXTRACT expression this used to filter on directly, which forced a
    // full scan of every notification this user has ever received on every
    // single poll tick and every full page load.
    $bannerRows = dbFetchAll(
        "SELECT id, message, data FROM notifications WHERE user_id = ? AND id > ? AND banner_mission_id = ? ORDER BY id ASC",
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
        $banners[] = [
            'id' => (int) $bannerRow['id'],
            'message' => $bannerRow['message'],
            'orderId' => $orderId,
            'alarmStyle' => $bannerData['alarmStyle'] ?? null,
        ];
    }

    $dispatches = loadMissionDispatchesForUser($missionId, (int)$user['id'], $canManageWarRoom, $isApprovedParticipant);
    $photos = loadMissionPhotosForUser($missionId, (int)$user['id'], $canManageWarRoom);
    $myTasks = loadMyTaskOrdersForUser($missionId, (int)$user['id']);
    $routes = loadRoutesForUser($missionId, (int)$user['id'], $canManageWarRoom);
    $shortageReports = $canManageWarRoom ? loadUnresolvedShortageReportsForMission($missionId) : [];
    $sosAlerts = $canManageWarRoom ? loadOpenSosAlertsForMission($missionId) : [];
    $onlinePresence = loadOnlinePresenceUserIds($missionId);
    $annotations = loadMissionAnnotationsForMission($missionId);
    $teamProximity = $loadTeamProximity();

    echo json_encode([
        'pins' => $pins,
        'time' => date('H:i:s'),
        'banners' => $banners,
        'dispatches' => $dispatches,
        'media' => $photos,
        'myTasks' => $myTasks,
        'routes' => $routes,
        'shortageReports' => $shortageReports,
        'sosAlerts' => $sosAlerts,
        'onlinePresence' => $onlinePresence,
        'pingStaleness' => $pingIsStaleByVolunteerId,
        'annotations' => $annotations,
        'nearbyTeams' => $teamProximity['nearbyTeams'],
        'teamDistances' => $teamProximity['teamDistances'],
    ]);
    exit;
}

// Everything below here is reached only by the full (non-ajax) page render —
// $participants (with its name/phone/shift-time joins), $myAssignments, the
// first loadOnlinePresenceUserIds() call, and $shifts all exist purely for
// this render's own UI (Participants list, My Ping panel, Shift list) and
// were previously computed unconditionally above, before the ajax branch's
// own exit — meaning every 5s poll from every open tab paid for all of it
// and used none of it. loadOnlinePresenceUserIds() in particular was being
// called a second time, identically, further down for the ajax response.
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

$pins = $loadPins();

// Baseline for the live request banner: ignore anything sent before this page load,
// only pop the banner for admin-initiated alerts (location requests, dispatch points, ...)
// that arrive from now on. Any sendNotification() pushData with 'bannerMission' => $missionId
// qualifies, so future request types (photo/video/relocate) plug in without changes here.
$bannerSinceId = (int) dbFetchValue(
    "SELECT COALESCE(MAX(id), 0) FROM notifications WHERE user_id = ? AND banner_mission_id = ?",
    [$user['id'], $missionId]
);

$dispatches = loadMissionDispatchesForUser($missionId, (int)$user['id'], $canManageWarRoom, $isApprovedParticipant);
$photos = loadMissionPhotosForUser($missionId, (int)$user['id'], $canManageWarRoom);
$myTasks = loadMyTaskOrdersForUser($missionId, (int)$user['id']);
$routes = loadRoutesForUser($missionId, (int)$user['id'], $canManageWarRoom);
$shortageReports = $canManageWarRoom ? loadUnresolvedShortageReportsForMission($missionId) : [];
$sosAlerts = $canManageWarRoom ? loadOpenSosAlertsForMission($missionId) : [];
$annotations = loadMissionAnnotationsForMission($missionId);
$teamProximity = $loadTeamProximity();
$nearbyTeams = $teamProximity['nearbyTeams'];
$teamDistances = $teamProximity['teamDistances'];

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
$teamIdByUserId = [];
foreach ($teams as $team) {
    $label = teamLabel($team['codename'], $team['team_number']);
    foreach ($team['members'] as $member) {
        $teamLabelByUserId[$member['user_id']] = $label;
        $teamColorByUserId[$member['user_id']] = $team['color'];
        $teamIdByUserId[$member['user_id']] = $team['id'];
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

// ── Offline field snapshot: contacts ────────────────────────────────────────
// A phone call is the one channel that usually still works when data does
// not, so the offline fallback page (offline.html) shows tappable tel: links
// rather than only a "no connection" message. Scope mirrors what the viewer
// can already see on this page: a regular member gets their own team, command
// staff — who may genuinely need to reach anyone — get every participant.
// The mission's responsible user is always first, since they're who you call
// when something has gone wrong.
$offlineContacts = [];
$seenContactIds = [];
if (!empty($mission['responsible_user_id'])) {
    $responsible = dbFetchOne("SELECT id, name, phone FROM users WHERE id = ?", [(int) $mission['responsible_user_id']]);
    if ($responsible && !empty($responsible['phone']) && (int) $responsible['id'] !== (int) $user['id']) {
        $seenContactIds[(int) $responsible['id']] = true;
        $offlineContacts[] = [
            'name'  => $responsible['name'],
            'phone' => $responsible['phone'],
            'role'  => t('offline.contact_responsible'),
        ];
    }
}
foreach ($participants as $participant) {
    $vid = (int) $participant['volunteer_id'];
    if ($vid === (int) $user['id'] || isset($seenContactIds[$vid]) || empty($participant['phone'])) {
        continue;
    }
    if (!$canManageWarRoom && (!$myTeamId || ($teamIdByUserId[$vid] ?? null) !== $myTeamId)) {
        continue;
    }
    $seenContactIds[$vid] = true;
    $offlineContacts[] = [
        'name'  => $participant['name'],
        'phone' => $participant['phone'],
        'role'  => $teamLabelByUserId[$vid] ?? '',
    ];
}

$pageTitle = 'Action Room — ' . $mission['title'];
$currentPage = 'war-room';
include __DIR__ . '/includes/header.php';
?>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<style>
    /* Field-safety touch targets: the SOS/field-status buttons and the
       route depart/arrive/complete/photo/video buttons are what a volunteer
       actually taps, repeatedly, sometimes one-handed/under stress/with
       gloves — Bootstrap's plain .btn-sm (~30px tall) sits below the ~44px
       accessibility minimum. Applied everywhere (not just a mobile media
       query) since a bigger, easier-to-hit button is strictly better on
       desktop too, not just harmless there. */
    .wr-touch-btn { min-height: 44px; padding-top: .55rem; padding-bottom: .55rem; font-size: .95rem; }
    #warRoomMap { height: 520px; border-radius: 12px; }
    #mapCard.map-fullscreen-active { position: fixed; inset: 0; z-index: 1040; border-radius: 0; }
    #mapCard.map-fullscreen-active #warRoomMap { height: 100%; border-radius: 0; }
    #mapCard.map-fullscreen-active #warRoomBanner { position: absolute; left: 0; right: 0; bottom: 0; z-index: 600; border-top: 2px solid #dc2626; border-bottom: none; }
    /* Strips Leaflet's default white tooltip box/arrow so only our own colored
       pill (inline-styled per team in dispatchTeamLabelHtml()) shows through. */
    .dispatch-team-label { background: transparent !important; border: none !important; box-shadow: none !important; padding: 0 !important; }
    .dispatch-team-label::before { display: none !important; }
    .war-room-hero { background: linear-gradient(135deg, #172554, #b91c1c); color: #fff; border-radius: 14px; }
    .war-room-hero h1 { color: #fff; font-weight: 700; }
    .participant-row { border-left: 4px solid #e2e8f0; }
    .participant-row.needs-help { border-left-color: #dc2626; }
    .presence-dot { display: inline-block; width: 9px; height: 9px; border-radius: 50%; margin-right: 4px; }
    .presence-dot.presence-online { background: #28a745; }
    .presence-dot.presence-offline { background: #adb5bd; }
    #annotationToolbar button.active { background: #1f2937; color: #fff; border-color: #1f2937; }
    #mapCard.wr-draw-active #warRoomMap { cursor: crosshair; }
    #mapCard.wr-draw-active .leaflet-marker-pane,
    #mapCard.wr-draw-active .leaflet-overlay-pane { pointer-events: none; }
    .wr-anno-arrowhead { width: 0; height: 0; border-left: 8px solid transparent; border-right: 8px solid transparent; border-bottom: 16px solid; filter: drop-shadow(0 1px 2px #0008); }
    .wr-anno-text-label { display: inline-block; padding: 2px 8px; border-radius: 4px; color: #fff; font-weight: 600; font-size: .78rem; white-space: nowrap; box-shadow: 0 1px 3px #0006; }
    .war-room-banner { display: none; flex-direction: column; background: #000; border-bottom: 2px solid #dc2626; position: relative; z-index: 1900; max-height: 40vh; overflow-y: auto; }
    .war-room-banner-row { display: flex; align-items: center; gap: 10px; padding: 8px 12px; }
    .war-room-banner-row + .war-room-banner-row { border-top: 1px solid rgba(255,59,48,.35); }
    .war-room-banner-track { flex: 1; overflow: hidden; white-space: nowrap; position: relative; height: 1.6em; }
    .war-room-banner-track span { display: inline-block; position: absolute; white-space: nowrap; padding-left: 100%; color: #ff3b30; font-weight: 700; text-transform: uppercase; letter-spacing: .02em; animation: warRoomBannerScroll 14s linear infinite; }
    @keyframes warRoomBannerScroll { 0% { transform: translateX(0); } 100% { transform: translateX(-100%); } }
    .war-room-banner .bi-broadcast { color: #ff3b30; flex-shrink: 0; }
    .war-room-banner-close { background: transparent; border: none; color: #ff3b30; font-size: 1.3rem; line-height: 1; cursor: pointer; padding: 0 4px; flex-shrink: 0; }
    @media (min-width: 992px) {
        /* Set on the track itself, not the span inside it — both tracks size
           their height in `em` relative to their own font-size, so bumping
           the span alone would grow the text without growing its container,
           clipping it. Font-size set here is inherited by the span anyway.
           Value comes from Settings (war_room_banner_font_size), not hardcoded. */
        .war-room-banner-track, .sos-map-marquee-track { font-size: <?= (float) getSetting('war_room_banner_font_size', '1.35') ?>rem; }
    }
    @keyframes warRoomPulseRed { 0%, 100% { box-shadow: 0 0 0 0 rgba(220,53,69,0); } 50% { box-shadow: 0 0 0 10px rgba(220,53,69,0.4); } }
    #sosOverlay { position: fixed; inset: 0; pointer-events: none; z-index: 2000; display: none; }
    #sosOverlay.sos-active { display: block; animation: sosPulseCorners 1s ease-in-out infinite; }
    #sosOverlay.sos-calm { display: block; animation: none; box-shadow: inset 0 0 120px 40px rgba(220,38,38,.35); }
    /* Sits above #sosOverlay's own z-index (2000) so it's always clickable
       even while the corners are pulsing — #sosOverlay itself is
       pointer-events:none, so there's no overlap-blocking risk either way. */
    #sosMuteBtn {
        position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%);
        z-index: 2001; border: none; border-radius: 999px; padding: .6rem 1.4rem;
        font-weight: 700; font-size: .95rem; box-shadow: 0 4px 16px rgba(0,0,0,.4);
        cursor: pointer;
    }
    #sosMuteBtn.sos-mute-offer { background: #fff; color: #dc2626; }
    #sosMuteBtn.sos-mute-active { background: #dc2626; color: #fff; }
    /* End of Mission / Return to Base — a separate overlay from #sosOverlay
       (own element, own class) so it never interferes with real SOS alert
       state; reuses the same sosPulseCorners keyframe for the same visual
       urgency, but auto-clears on a timer instead of staying until acked. */
    #returnToBaseOverlay { position: fixed; inset: 0; pointer-events: none; z-index: 2000; display: none; }
    #returnToBaseOverlay.rtb-active { display: block; animation: sosPulseCorners 1s ease-in-out infinite; }
    @keyframes sosPulseCorners {
        0%, 100% { box-shadow: inset 0 0 60px 20px rgba(220,38,38,.25), inset 0 0 160px 60px rgba(220,38,38,.12); }
        50%      { box-shadow: inset 0 0 120px 50px rgba(220,38,38,.65), inset 0 0 260px 120px rgba(220,38,38,.35); }
    }
    .sos-map-marquee { position: absolute; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,.75); padding: 6px 10px; overflow: hidden; z-index: 500; }
    .sos-map-marquee-track { white-space: nowrap; position: relative; height: 1.4em; }
    .sos-map-marquee-track span { display: inline-block; position: absolute; white-space: nowrap; padding-left: 100%; color: #ff3b30; font-weight: 700; text-transform: uppercase; letter-spacing: .02em; animation: warRoomBannerScroll 14s linear infinite; }
    /* On narrow/mobile screens the scrolling text sweeps right through the
       Leaflet OSM attribution corner (bottom-right) since the marquee spans
       the full width flush against the map's bottom edge — lift it clear
       instead of overlapping. Desktop has enough width that this wasn't
       reported as an issue there, so scoped to mobile rather than changed
       globally. */
    @media (max-width: 991.98px) {
        .sos-map-marquee { bottom: 22px; }
    }
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
        <div class="d-flex gap-2 align-items-center flex-wrap justify-content-end">
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
            <button type="button" id="wakeLockToggle" class="btn btn-outline-light d-none"><i class="bi bi-sun me-1"></i><?= t('hero.btn_keep_awake') ?></button>
            <a href="ops-dashboard.php" class="btn btn-light"><i class="bi bi-arrow-left me-1"></i><?= t('hero.btn_back_ops') ?></a>
        </div>
    </div>
</div>

<?= showFlash() ?>

<div id="warRoomBanner" class="war-room-banner"></div>

<?php if ($canManageWarRoom): ?>
<div id="sosOverlay"></div>
<!-- Local-only siren mute — silences the audio on THIS device for 5 minutes
     without touching the alert itself (no ack/resolve), for the real case of
     "I'm in a meeting/on a call, I can see the SOS is still active on
     screen, I just need it quiet for a few minutes." The visual (pulsing
     corners + marquee) deliberately keeps running even while muted — only
     the sound is ever affected, and only on this one device/tab, not for
     any other command-staff member watching the same mission. -->
<button type="button" id="sosMuteBtn" class="d-none"></button>
<?php endif; ?>
<!-- Unlike #sosOverlay (command-staff-only, since SOS is a field->command
     incoming alert), this is command->field, so every approved participant
     needs the element regardless of $canManageWarRoom. -->
<div id="returnToBaseOverlay"></div>

<!-- Live-data staleness. The footer's generic offline bar only reacts to
     navigator.onLine, which stays true for the genuinely dangerous cases: a
     captive portal, a dropped VPN, Apache down, a phone holding a useless
     bar of signal. In all of those the 5s poll below was failing into a bare
     .catch(() => {}) while the map kept showing pins frozen minutes ago with
     nothing on screen saying so — on an ops console that is worse than an
     obvious error. -->
<div id="pollStaleBanner" class="alert alert-warning d-flex align-items-center gap-2 py-2 px-3 mb-3 d-none" role="status" aria-live="polite"></div>

<?php if (!$fieldMode): ?>
<div class="row g-4 mb-4">
    <div class="col-12 col-lg-8">
        <div class="card shadow-sm h-100" id="mapCard">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-map me-1"></i><?= t('map.title') ?></h5>
                <div class="d-flex align-items-center gap-2">
                    <small class="text-muted"><?= t('common.updated_label') ?> <span id="mapRefresh"><?= date('H:i:s') ?></span></small>
                    <?php if ($canManageWarRoom): ?>
                    <div class="btn-group btn-group-sm" role="group" id="annotationToolbar">
                        <button type="button" class="btn btn-outline-secondary" id="annoToolFreehand" data-tool="freehand" title="<?= t('annotation.tool_freehand') ?>"><i class="bi bi-pencil"></i></button>
                        <button type="button" class="btn btn-outline-secondary" id="annoToolArrow" data-tool="arrow" title="<?= t('annotation.tool_arrow') ?>"><i class="bi bi-arrow-up-right"></i></button>
                        <button type="button" class="btn btn-outline-secondary" id="annoToolText" data-tool="text" title="<?= t('annotation.tool_text') ?>"><i class="bi bi-fonts"></i></button>
                        <button type="button" class="btn btn-outline-secondary" id="annoToolErase" data-tool="erase" title="<?= t('annotation.tool_erase') ?>"><i class="bi bi-eraser"></i></button>
                    </div>
                    <?php endif; ?>
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
                            <option value="<?= $team['id'] ?>"><?= h(teamLabel($team['codename'], $team['team_number'])) ?></option>
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
                <!-- Replay scrubber — hidden until a trail with at least 2
                     distinct timestamps actually loads (a single-point or
                     empty trail has nothing to scrub through). Reuses the
                     exact same $canManageWarRoom-only visibility as the rest
                     of #trailFilterBar; no separate gate needed. -->
                <div class="row g-2 align-items-center mt-1 d-none" id="trailReplayBar">
                    <div class="col-auto">
                        <button type="button" class="btn btn-sm btn-outline-primary" id="trailPlayBtn" style="width:38px;"><i class="bi bi-play-fill"></i></button>
                    </div>
                    <div class="col">
                        <input type="range" class="form-range" id="trailScrubber" min="0" max="100" value="100">
                    </div>
                    <div class="col-auto">
                        <span class="small text-muted" id="trailScrubberTime" style="white-space:nowrap;"></span>
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

    <div class="col-12 col-lg-4">
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
    <div class="col-12 col-lg-8">
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
                            <span class="badge fs-6 me-2" style="background:<?= h($teamBg) ?>;color:<?= h($teamFg) ?>;"><?= h(teamLabel($team['codename'], $team['team_number'])) ?></span>
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
                            <form method="post" onsubmit="return confirm('<?= h(addslashes(t('teams.delete_confirm', ['team' => teamLabel($team['codename'], $team['team_number'])]))) ?>')">
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
            <!-- Pairwise team-to-team distances — a small addendum to the
                 roster above, not its own card (this column already stacks
                 Teams/Participants/Activity). JS-rendered and hidden via
                 d-none whenever fewer than 2 teams currently have a
                 position, since that's "nothing to compare yet", not an
                 empty/error state worth its own message. -->
            <div class="card-body border-top small d-none" id="teamDistancesSection">
                <div class="fw-semibold mb-1"><i class="bi bi-rulers me-1"></i><?= t('teams.distances_title') ?></div>
                <div id="teamDistancesList"></div>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header"><h5 class="mb-0"><i class="bi bi-people me-1"></i><?= t('participants.panel_title', ['count' => count($participants)]) ?></h5></div>
            <div class="list-group list-group-flush">
                <?php foreach ($participants as $participant): ?>
                <?php $status = $participant['field_status'] ?? ''; ?>
                <div class="list-group-item participant-row <?= $status === 'needs_help' ? 'needs-help' : '' ?> d-flex justify-content-between align-items-center gap-2 flex-wrap">
                    <div><span id="presence-<?= (int)$participant['volunteer_id'] ?>" class="presence-dot <?= in_array((int)$participant['volunteer_id'], $onlinePresenceIds, true) ? 'presence-online' : 'presence-offline' ?>" title="<?= in_array((int)$participant['volunteer_id'], $onlinePresenceIds, true) ? t('common.online') : t('common.offline') ?>"></span><strong><?= guestNameHtml($participant['name'], (bool)$participant['is_external'], $participant['guest_org_name']) ?></strong><?php if (isset($teamLabelByUserId[(int)$participant['volunteer_id']])): [$pBg, $pFg] = teamBadgeColors($teamColorByUserId[(int)$participant['volunteer_id']] ?? null); ?> <span class="badge" style="background:<?= h($pBg) ?>;color:<?= h($pFg) ?>;"><?= h($teamLabelByUserId[(int)$participant['volunteer_id']]) ?></span><?php endif; ?><br><small class="text-muted"><?= formatDateTime($participant['start_time']) ?> – <?= date('H:i', strtotime($participant['end_time'])) ?><?= $participant['last_ping_at'] ? t('participants.last_ping_label', ['time' => date('H:i', strtotime($participant['last_ping_at']))]) : t('participants.no_ping') ?><?php if ($participant['last_ping_at']): ?><span id="ping-stale-<?= (int)$participant['volunteer_id'] ?>" class="text-warning <?= $pingIsStaleByVolunteerId[(int)$participant['volunteer_id']] ? '' : 'd-none' ?>" title="<?= t('participants.stale_ping_title') ?>"><i class="bi bi-exclamation-triangle-fill"></i><?= t('participants.stale_ping_suffix') ?></span><?php endif; ?></small></div>
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
            <div class="col-12 col-md-6">
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

            <div class="col-12 col-md-6">
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

            <div class="col-12 col-md-6">
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

            <div class="col-12 col-md-6">
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
                <div class="d-flex align-items-center gap-2">
                    <a href="exports/export-mission-activity.php?mission_id=<?= $missionId ?>" class="btn btn-sm btn-outline-secondary" title="<?= t('activity.export_btn') ?>">
                        <i class="bi bi-file-earmark-excel me-1"></i><?= t('activity.export_btn') ?>
                    </a>
                    <small class="text-muted"><?= t('common.updated_label') ?> <span id="activityRefresh"></span></small>
                </div>
            </div>
            <div class="card-body">
                <div id="activityList" style="max-height:420px;overflow-y:auto;"><div class="text-muted small"><?= t('common.loading') ?></div></div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="<?= $fieldMode ? 'col-12 col-lg-6 mx-auto' : 'col-12 col-lg-4' ?>">
        <!-- Offline queue status. Sits above every field card rather than inside
             the Route Order one (where it used to live) because the queue now
             also carries field-status/SOS taps, which are reported from the
             card below and can happen on a mission with no route at all. -->
        <div id="offlineQueueBanner" class="alert alert-warning py-1 px-2 small mb-2 d-none"></div>
        <div id="offlineQueueFailures"></div>

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
                        <button type="button" class="btn btn-sm wr-touch-btn <?= $myFieldStatus === 'on_way' ? 'btn-warning' : 'btn-outline-warning' ?>" onclick="setFieldStatus(this, <?= $assignment['pr_id'] ?>, 'on_way')"><?= t('myping.btn_on_way') ?></button>
                        <button type="button" class="btn btn-sm wr-touch-btn <?= $myFieldStatus === 'on_site' ? 'btn-success' : 'btn-outline-success' ?>" onclick="setFieldStatus(this, <?= $assignment['pr_id'] ?>, 'on_site')"><?= t('myping.btn_on_site') ?></button>
                        <button type="button" class="btn btn-sm wr-touch-btn <?= $myFieldStatus === 'needs_help' ? 'btn-danger' : 'btn-outline-danger' ?>" onclick="setFieldStatus(this, <?= $assignment['pr_id'] ?>, 'needs_help')"><?= t('myping.btn_sos') ?></button>
                    </div>
                    <?php endforeach; ?>
                    <p class="small text-muted mb-0"><?= t('myping.auto_note') ?></p>
                <?php endif; ?>
            </div>
        </div>

        <!-- Field Mode has no map at all (see the !$fieldMode wrap further up),
             so this is the only place a field volunteer sees where other teams
             are — a plain distance+direction list instead of pins on a map.
             Unconditional card (like My Ping/Route/Tasks below it): shows in
             both Field Mode and full view, content degrades gracefully via
             renderNearbyTeams() when there's no data yet. -->
        <div class="card shadow-sm mb-4 border-primary">
            <div class="card-header bg-primary text-white"><h5 class="mb-0"><i class="bi bi-compass me-1"></i><?= t('nearby.panel_title') ?></h5></div>
            <div class="card-body">
                <div id="nearbyTeamsList"></div>
            </div>
        </div>

        <div class="card shadow-sm mb-4 border-primary">
            <div class="card-header bg-primary text-white"><h5 class="mb-0"><i class="bi bi-signpost-split-fill me-1"></i><?= t('route.my_panel_title') ?></h5></div>
            <div class="card-body">
                <div id="myRoutesList"><p class="text-muted mb-0"><?= t('route.my_empty') ?></p></div>
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

        <div class="card shadow-sm mb-4 border-danger">
            <div class="card-header bg-danger text-white"><h5 class="mb-0"><i class="bi bi-flag-fill me-1"></i><?= t('end_mission_broadcast.card_title') ?></h5></div>
            <div class="card-body">
                <p class="small text-muted"><?= t('end_mission_broadcast.note') ?></p>
                <form method="post" onsubmit="return confirm('<?= h(addslashes(t('end_mission_broadcast.confirm'))) ?>')">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="end_mission_broadcast">
                    <button type="submit" class="btn btn-danger btn-lg w-100 fw-bold">
                        <i class="bi bi-exclamation-triangle-fill me-1"></i><?= t('end_mission_broadcast.submit_btn', ['count' => count($participants)]) ?>
                    </button>
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
                    <option value="<?= $team['id'] ?>"><?= h(teamLabel($team['codename'], $team['team_number'])) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="btn btn-primary w-100 fw-semibold" data-bs-toggle="modal" data-bs-target="#dispatchMapModal">
                    <i class="bi bi-pin-map-fill me-1"></i><?= t('dispatch.send_btn') ?>
                </button>
            </div>
        </div>

        <?php if (!empty($teams)): ?>
        <div class="card shadow-sm mb-4 border-primary">
            <div class="card-header bg-primary bg-opacity-10"><h5 class="mb-0"><i class="bi bi-signpost-split-fill me-1"></i><?= t('route.card_title') ?></h5></div>
            <div class="card-body">
                <p class="small text-muted"><?= t('route.note') ?></p>
                <label class="form-label small fw-semibold"><?= t('route.recipients_label') ?></label>
                <select class="form-select mb-3" id="routeTeamSelect">
                    <?php foreach ($teams as $team): ?>
                    <option value="<?= $team['id'] ?>"><?= h(teamLabel($team['codename'], $team['team_number'])) ?></option>
                    <?php endforeach; ?>
                    <?php if (count($teams) >= 2): ?>
                    <option value=""><?= t('route.cross_team_option') ?></option>
                    <?php endif; ?>
                </select>
                <div class="mb-3">
                    <label class="form-label small fw-semibold mb-1"><?= t('route.members_label') ?></label>
                    <div id="routeMemberPicker" class="d-flex flex-wrap gap-2 small"></div>
                </div>
                <div class="form-check mb-3" id="routeReturnToStartWrap">
                    <input class="form-check-input" type="checkbox" id="routeReturnToStartCheck">
                    <label class="form-check-label small" for="routeReturnToStartCheck"><?= t('route.return_to_start_checkbox') ?></label>
                    <div class="text-muted" style="font-size:.75rem;"><?= t('route.return_to_start_hint') ?></div>
                </div>
                <button type="button" class="btn btn-primary w-100 fw-semibold" data-bs-toggle="modal" data-bs-target="#routeComposerModal">
                    <i class="bi bi-signpost-split-fill me-1"></i><?= t('route.send_btn') ?>
                </button>
            </div>
        </div>

        <div class="card shadow-sm mb-4 border-primary">
            <div class="card-header bg-primary bg-opacity-10"><h5 class="mb-0"><i class="bi bi-list-check me-1"></i><?= t('route.admin_panel_title') ?></h5></div>
            <div class="card-body">
                <div id="routesAdminList"><p class="text-muted mb-0"><?= t('route.admin_empty') ?></p></div>
            </div>
        </div>
        <?php endif; ?>

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
                    <label class="form-label small fw-semibold"><?= t('teams.custom_name_label') ?></label>
                    <input type="text" class="form-control mb-3" name="custom_codename" maxlength="20" placeholder="<?= t('teams.custom_name_placeholder') ?>">
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
                <h5 class="modal-title"><i class="bi bi-pencil me-1"></i><?= h(t('teams.edit_modal_title', ['team' => teamLabel($team['codename'], $team['team_number'])])) ?></h5>
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
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="bi bi-chat-dots me-1"></i><?= t('chat.panel_title') ?></h5>
        <div class="dropdown">
            <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown">
                <i class="bi bi-file-earmark-excel me-1"></i><?= t('chat.export_btn') ?>
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><a class="dropdown-item" href="exports/export-mission-chat.php?mission_id=<?= $missionId ?>&team_id="><?= t('chat.general_room') ?></a></li>
                <?php foreach ($chatTeams as $ct): ?>
                <li><a class="dropdown-item" href="exports/export-mission-chat.php?mission_id=<?= $missionId ?>&team_id=<?= $ct['id'] ?>"><?= h(teamLabel($ct['codename'], $ct['team_number'])) ?></a></li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <div class="card-body">
        <ul class="nav nav-pills mb-3 flex-wrap" id="chatRoomTabs">
            <li class="nav-item">
                <button type="button" class="nav-link active chat-room-tab" data-team-id=""><?= t('chat.general_room') ?></button>
            </li>
            <?php foreach ($chatTeams as $ct): ?>
            <li class="nav-item">
                <button type="button" class="nav-link chat-room-tab" data-team-id="<?= $ct['id'] ?>"><?= h(teamLabel($ct['codename'], $ct['team_number'])) ?></button>
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

<div class="modal fade" id="routeComposerModal" tabindex="-1">
    <div class="modal-dialog modal-fullscreen">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h5 class="modal-title"><i class="bi bi-signpost-split-fill me-1"></i><?= t('route.composer_title') ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0 d-flex flex-column">
                <div class="p-2 border-bottom d-flex flex-wrap gap-2 align-items-center bg-light">
                    <input type="text" id="routeTitleInput" class="form-control" style="max-width:240px;" maxlength="255" placeholder="<?= t('route.title_placeholder') ?>">
                    <input type="text" id="routeAddressInput" class="form-control" style="max-width:260px;" placeholder="<?= t('dispatch.address_placeholder') ?>">
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="routeAddressSearch"><i class="bi bi-search me-1"></i><?= t('dispatch.search_btn') ?></button>
                    <span class="text-muted small" id="routeAddressStatus"></span>
                    <div class="ms-auto d-flex gap-2">
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="routeClearBtn"><i class="bi bi-arrow-counterclockwise me-1"></i><?= t('dispatch.clear_btn') ?></button>
                        <button type="button" class="btn btn-success btn-sm" id="routeSendBtn" disabled><i class="bi bi-send-fill me-1"></i><?= t('route.send_btn') ?></button>
                    </div>
                </div>
                <div class="small text-muted px-2 py-1 bg-light border-bottom"><?= t('route.map_instructions') ?></div>
                <div class="d-flex flex-grow-1" style="min-height:0;">
                    <div id="routeMap" style="flex:1;min-height:0;"></div>
                    <div id="routeWaypointPanel" style="width:360px;min-width:280px;overflow-y:auto;border-left:1px solid #dee2e6;padding:.5rem;background:#fff;"></div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="routeWaypointEditModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h5 class="modal-title"><?= t('route.edit_waypoint_title') ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <label class="form-label small fw-semibold"><?= t('route.label_placeholder') ?></label>
                <input type="text" id="routeEditLabel" class="form-control mb-2" maxlength="255">
                <label class="form-label small fw-semibold"><?= t('route.instructions_placeholder') ?></label>
                <textarea id="routeEditInstructions" class="form-control mb-2" rows="3" maxlength="2000"></textarea>
                <label class="form-label small fw-semibold"><?= t('route.dwell_label') ?></label>
                <input type="number" id="routeEditDwell" class="form-control mb-2" min="0" max="600" style="max-width:120px;" placeholder="<?= t('route.dwell_unlimited_placeholder') ?>">
                <div class="d-flex gap-3">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="routeEditPhoto">
                        <label class="form-check-label small" for="routeEditPhoto"><?= t('route.photo_btn') ?></label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="routeEditVideo">
                        <label class="form-check-label small" for="routeEditVideo"><?= t('route.video_btn') ?></label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="routeEditNote">
                        <label class="form-check-label small" for="routeEditNote"><?= t('route.note_flag_label') ?></label>
                    </div>
                </div>
                <div class="small text-danger mt-2 d-none" id="routeEditError"></div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal"><?= t('common.cancel') ?></button>
                <button type="button" class="btn btn-primary btn-sm" id="routeEditSaveBtn"><?= t('common.save') ?></button>
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
let annotations = <?= json_encode($annotations) ?>;
let media = <?= json_encode($photos) ?>;
// Media re-renders every image tag from scratch (mission-photo-view.php is
// deliberately Cache-Control: no-store, since it's access-gated field media),
// so re-running renderMedia() on a poll tick where nothing actually changed
// means a real re-download of every photo/video, not just a visual flicker.
// Track what was last rendered and skip the call entirely when the fetched
// list is byte-for-byte the same.
let mediaSignature = JSON.stringify(media);
let myTasks = <?= json_encode($myTasks) ?>;
let routes = <?= json_encode($routes) ?>;
let nearbyTeams = <?= json_encode($nearbyTeams) ?>;
let teamDistances = <?= json_encode($teamDistances) ?>;
// Team rosters for the Route Order composer's member picker — lets an admin
// narrow a route to a subset of a team (e.g. 2 of 4) instead of always the
// whole team. See includes/migrations.php v109.
const missionTeamsForRoute = <?= json_encode(array_values(array_map(fn($t) => [
    'id' => $t['id'],
    'label' => teamLabel($t['codename'], $t['team_number']),
    'members' => array_map(fn($m) => ['id' => $m['user_id'], 'name' => $m['name']], $t['members']),
], $teams)), JSON_UNESCAPED_UNICODE) ?>;
// Every approved participant of the mission, with their current team label
// (or none) — feeds the composer's cross-team picker mode, where a route
// can be assembled from specific individuals across two or more different
// teams instead of always one nominal team. See includes/migrations.php v110.
const allApprovedForRoute = <?= json_encode(array_values(array_map(
    fn($p) => ['id' => $p['user_id'], 'name' => $p['name'], 'team_label' => $teamLabelByUserId[$p['user_id']] ?? null],
    $distinctApprovedById
)), JSON_UNESCAPED_UNICODE) ?>;
let shortageReports = <?= json_encode($shortageReports) ?>;
let sosAlerts = <?= json_encode($sosAlerts) ?>;

// Field Mode only, automatic — keeps the screen from sleeping so passive
// location capture keeps working while a volunteer's phone is out. The
// browser force-releases this lock the instant the tab is hidden and does
// NOT re-acquire it automatically, so it must be explicitly re-requested on
// every return to visible or it silently stays dead after the first
// backgrounding. Never blocks anything else on success/failure (unsupported
// browser, low battery mode, non-secure context all just no-op quietly),
// matching this file's existing defensive style for the Fullscreen API.
let wakeLockSentinel = null;
function requestWarRoomWakeLock() {
    if (!fieldMode || !('wakeLock' in navigator)) return;
    navigator.wakeLock.request('screen').then(sentinel => {
        wakeLockSentinel = sentinel;
        sentinel.addEventListener('release', () => { wakeLockSentinel = null; });
    }).catch(() => { wakeLockSentinel = null; });
}
if (fieldMode) {
    requestWarRoomWakeLock();
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') requestWarRoomWakeLock();
    });
}
let map = null, pinLayer = null, dispatchLayer = null, trailLayer = null, annotationLayer = null, annotationDrawLayer = null, routeLayer = null;
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
    // Battle-map annotations get their own pane (above the default marker/
    // overlay panes) so a draw-mode CSS rule can suspend pin/dispatch click
    // interactivity without touching this one — the eraser must keep working
    // while everything else is suspended. annotationLayer holds only the
    // persisted shapes (rebuilt from scratch by renderAnnotations() every
    // poll, like dispatchLayer); annotationDrawLayer holds only the
    // in-progress gesture preview (an active freehand stroke, a pending arrow
    // start point) so a poll tick landing mid-gesture can never wipe out what's
    // currently being drawn.
    map.createPane('annotationPane');
    map.getPane('annotationPane').style.zIndex = 610;
    annotationLayer = L.featureGroup().addTo(map);
    annotationDrawLayer = L.layerGroup().addTo(map);
    // FeatureGroup so popup events propagate the same way dispatchLayer's do
    // (not used for buttons today, but keeps the two "War Room order" layers
    // consistent in case a future popup action needs it).
    routeLayer = L.featureGroup().addTo(map);
}
const ANNOTATION_COLOR = '#1f2937';
// Battle-map annotation tool state — a plain toggle over the same live map
// instance (not a second map, unlike the dispatch-composition modal), since
// the whole point is sketching directly on what everyone's already looking
// at. Only one tool is ever active at a time; selecting the same one again
// deselects it and returns the map to normal pan/click behavior.
let activeTool = null; // null | 'freehand' | 'arrow' | 'text' | 'erase'
let freehandPoints = [], freehandPreviewLayer = null;
let arrowStart = null, arrowStartMarker = null;
function cancelActiveDrawing() {
    if (map) { map.dragging.enable(); map.doubleClickZoom.enable(); }
    if (annotationDrawLayer) annotationDrawLayer.clearLayers();
    freehandPoints = []; freehandPreviewLayer = null;
    arrowStart = null; arrowStartMarker = null;
}
function setActiveTool(tool) {
    cancelActiveDrawing();
    if (map) map.closePopup();
    activeTool = (activeTool === tool) ? null : tool;
    document.querySelectorAll('#annotationToolbar button').forEach(b => b.classList.toggle('active', b.dataset.tool === activeTool));
    const mapCardEl = document.getElementById('mapCard');
    if (mapCardEl) mapCardEl.classList.toggle('wr-draw-active', !!activeTool);
    if (map) {
        // Disabled proactively here (tool-selection time), not reactively
        // inside a mousedown handler — disabling mid-gesture would race
        // against Leaflet's own internal drag-handler already latching onto
        // the same event. Only freehand needs this: arrow/text are pure
        // clicks, and the dispatch-composition tool already proves Leaflet
        // cleanly separates click-from-pan without disabling dragging.
        if (activeTool === 'freehand') map.dragging.disable();
        if (activeTool) map.doubleClickZoom.disable();
    }
}
const annoToolbarEl = document.getElementById('annotationToolbar');
if (annoToolbarEl) {
    annoToolbarEl.querySelectorAll('button').forEach(btn => btn.addEventListener('click', () => setActiveTool(btn.dataset.tool)));
}
// Safety net: a mousedown with no matching mouseup (alt-tab mid-stroke, focus
// stolen mid-gesture) would otherwise leave map.dragging permanently disabled
// for the rest of the session, since nothing else would ever call
// cancelActiveDrawing() again.
window.addEventListener('blur', cancelActiveDrawing);
function bearing(latlng1, latlng2) {
    const lat1 = latlng1.lat * Math.PI / 180, lat2 = latlng2.lat * Math.PI / 180, dLng = (latlng2.lng - latlng1.lng) * Math.PI / 180;
    const y = Math.sin(dLng) * Math.cos(lat2);
    const x = Math.cos(lat1) * Math.sin(lat2) - Math.sin(lat1) * Math.cos(lat2) * Math.cos(dLng);
    return (Math.atan2(y, x) * 180 / Math.PI + 360) % 360;
}
function submitAnnotation(type, geo, label) {
    const data = new URLSearchParams({csrf_token: csrfToken, action: 'create', mission_id: <?= $missionId ?>, type, geo: JSON.stringify(geo)});
    if (label) data.append('label', label);
    fetch('mission-annotation.php', {method: 'POST', body: data}).then(r => r.json()).then(result => {
        if (result.ok) renderAnnotations(annotations = [...annotations, result.annotation]);
        else alert(result.error || t('common.send_failed'));
    }).catch(() => alert(t('common.send_failed')));
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
// Small colored pill (team's own badge color, or the dark "all teams" fallback
// teamBadgeColors() already returns for a null team) shown as a permanent —
// not hover/click-only — Leaflet tooltip, so which team a dispatch point/area
// belongs to is visible on the map at a glance.
function dispatchTeamLabelHtml(item) {
    return `<span style="background:${item.team_color_bg};color:${item.team_color_fg};padding:2px 8px;border-radius:10px;font-weight:700;font-size:.72rem;white-space:nowrap;box-shadow:0 1px 3px #0006;">${escapeHtml(item.team_label)}</span>`;
}
// Same whole-array-JSON signature technique as renderPins/mediaSignature —
// skips the rebuild (and the open-popup-preservation dance below, which
// itself isn't free) on a poll tick where literally nothing about any
// dispatch changed, including its live ETA.
let dispatchesRenderedSig = null;
function renderDispatches(items) {
    const sig = JSON.stringify(items);
    if (sig === dispatchesRenderedSig) return;
    dispatchesRenderedSig = sig;

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
            ? '<div class="small text-success mt-1">' + item.acks.map(a => `✅ ${a.team_label !== '—' ? escapeHtml(a.team_label) + ' — ' : ''}${guestNameHtml(a.user_name, a.is_external, a.guest_org_name)} (${a.time})`).join('<br>') + '</div>'
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
        // Live ETA — only ever present for a point sent to one specific team
        // (see computeDispatchEta()'s own scoping); null means either that
        // doesn't apply here, or the team hasn't sent a single GPS ping yet,
        // in which case this silently shows nothing rather than a fake "0".
        const etaHtml = item.eta
            ? `<div class="small mt-1">${escapeHtml(item.eta.minutes < 1 ? t('dispatch.eta_lt_1min') : t('dispatch.eta_minutes', {n: item.eta.minutes}))}` +
              `${item.eta.source === 'straight_line' ? ' ' + escapeHtml(t('dispatch.eta_straight_line_suffix')) : ''}` +
              `${item.eta.is_stale ? ' ' + escapeHtml(t('dispatch.eta_stale_suffix')) : ''}</div>`
            : '';
        const popupHtml = `<strong>${escapeHtml(item.team_label)}</strong>${item.label ? '<br>' + escapeHtml(item.label) : ''}` + etaHtml + acksHtml + receiveHtml + ackHtml + directionsHtml +
            (item.can_delete ? `<br><button type="button" class="btn btn-sm btn-outline-danger mt-1 dispatch-delete-btn" data-id="${item.id}">${t('common.delete')}</button>` : '');
        let layer = null;
        if (item.type === 'point') {
            const icon = L.divIcon({className:'', html:'<i class="bi bi-geo-alt-fill" style="font-size:28px;color:#7c3aed;filter:drop-shadow(0 1px 2px #0008);"></i>', iconSize:[28,28], iconAnchor:[14,26]});
            layer = L.marker([item.geo.lat, item.geo.lng], {icon}).addTo(dispatchLayer).bindPopup(popupHtml);
            layer.bindTooltip(dispatchTeamLabelHtml(item), {permanent:true, direction:'right', offset:[8,-8], className:'dispatch-team-label', interactive:false});
        } else if (item.type === 'polygon') {
            layer = L.polygon(item.geo, {color:'#7c3aed', fillOpacity:0.15}).addTo(dispatchLayer).bindPopup(popupHtml);
            // direction:'center' anchors the label at the polygon's own
            // centroid, which sits it right on top of the fill/border — use
            // 'top' with a small upward offset instead, same "off to the
            // side, not overlapping the shape" idea as the point marker's
            // own direction:'right' label just above.
            layer.bindTooltip(dispatchTeamLabelHtml(item), {permanent:true, direction:'top', offset:[0,-8], className:'dispatch-team-label', interactive:false});
        }
        if (layer) {
            layer.dispatchId = item.id;
            if (String(item.id) === String(openDispatchId)) reopenLayer = layer;
        }
    });
    if (reopenLayer) reopenLayer.openPopup();
}
// Battle-map annotations: rebuilt from scratch every poll, exactly like
// renderDispatches above — but never touches annotationDrawLayer, which
// holds only the in-progress gesture preview, so a poll tick landing
// mid-stroke can't rip out what's currently being drawn. Per-layer click
// listeners are only attached when the drawing toolbar exists in the DOM at
// all (command-staff sessions) — a regular viewer's annotations render but
// are otherwise inert, with zero click handling wired up at all.
function renderAnnotations(items) {
    annotationLayer.clearLayers();
    const canErase = !!document.getElementById('annotationToolbar');
    items.forEach(item => {
        let layer = null;
        if (item.type === 'freehand') {
            layer = L.polyline(item.geo, {color: ANNOTATION_COLOR, weight: 4, pane: 'annotationPane'}).addTo(annotationLayer);
        } else if (item.type === 'arrow') {
            const [p1, p2] = item.geo;
            L.polyline(item.geo, {color: ANNOTATION_COLOR, weight: 3, pane: 'annotationPane'}).addTo(annotationLayer);
            const brng = bearing(L.latLng(p1[0], p1[1]), L.latLng(p2[0], p2[1]));
            const headIcon = L.divIcon({className:'', html:`<div class="wr-anno-arrowhead" style="transform:rotate(${brng}deg);border-bottom-color:${ANNOTATION_COLOR}"></div>`, iconSize:[16,16], iconAnchor:[8,8]});
            layer = L.marker(p2, {icon: headIcon, pane: 'annotationPane'}).addTo(annotationLayer);
        } else if (item.type === 'text') {
            const icon = L.divIcon({className:'', html:`<span class="wr-anno-text-label" style="background:${ANNOTATION_COLOR}">${escapeHtml(item.label)}</span>`, iconAnchor:[0, 12]});
            layer = L.marker([item.geo.lat, item.geo.lng], {icon, pane: 'annotationPane'}).addTo(annotationLayer);
        }
        if (layer && canErase) {
            layer.on('click', () => {
                if (activeTool !== 'erase') return;
                if (!confirm(t('annotation.delete_confirm'))) return;
                const data = new URLSearchParams({csrf_token: csrfToken, action: 'delete', mission_id: <?= $missionId ?>, id: item.id});
                fetch('mission-annotation.php', {method:'POST', body:data}).then(r => r.json()).then(result => {
                    if (result.ok) renderAnnotations(annotations = annotations.filter(a => String(a.id) !== String(item.id)));
                });
            });
        }
    });
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
// Freehand: mousedown starts a stroke, mousemove samples points at a
// zoom-independent pixel-distance threshold (not a geographic one, so visual
// density stays constant whether zoomed in or out), mouseup finalizes and
// sends it. mouseup is bound on document (not just the map) so releasing
// outside the map container still ends the stroke. Touch handlers mirror the
// mouse ones exactly — command staff may well be on a tablet at a command
// post, and unlike arrow/text (single taps, which Leaflet's own 'click'
// event already normalizes across mouse and touch), a sustained drag gesture
// is the one interaction here genuinely likely to fight with a mobile
// browser's native touch handling if left mouse-only.
function touchToLatLng(touch) {
    return map.containerPointToLatLng(map.mouseEventToContainerPoint({clientX: touch.clientX, clientY: touch.clientY}));
}
function startFreehand(latlng, containerPoint) {
    freehandPoints = [[latlng.lat, latlng.lng]];
    freehandPreviewLayer = L.polyline(freehandPoints, {color: ANNOTATION_COLOR, weight: 4, pane: 'annotationPane'}).addTo(annotationDrawLayer);
    return containerPoint;
}
function appendFreehandPoint(latlng, lastPx, currentPx) {
    if (lastPx.distanceTo(currentPx) < 7) return lastPx;
    freehandPoints.push([latlng.lat, latlng.lng]);
    if (freehandPreviewLayer && freehandPoints.length < 500) freehandPreviewLayer.setLatLngs(freehandPoints);
    return currentPx;
}
function finishFreehand() {
    annotationDrawLayer.clearLayers();
    if (freehandPoints.length >= 2) submitAnnotation('freehand', freehandPoints, null);
    freehandPoints = [];
}
map.on('mousedown', e => {
    if (activeTool !== 'freehand') return;
    let lastPx = startFreehand(e.latlng, e.containerPoint);
    const onMove = ev => { lastPx = appendFreehandPoint(ev.latlng, lastPx, ev.containerPoint); };
    const onUp = () => { map.off('mousemove', onMove); document.removeEventListener('mouseup', onUp); finishFreehand(); };
    map.on('mousemove', onMove);
    document.addEventListener('mouseup', onUp);
});
document.getElementById('warRoomMap').addEventListener('touchstart', e => {
    if (activeTool !== 'freehand' || !e.touches.length) return;
    e.preventDefault();
    const latlng = touchToLatLng(e.touches[0]);
    let lastPx = startFreehand(latlng, map.latLngToContainerPoint(latlng));
    const onMove = ev => {
        if (!ev.touches.length) return;
        ev.preventDefault();
        const moveLatLng = touchToLatLng(ev.touches[0]);
        lastPx = appendFreehandPoint(moveLatLng, lastPx, map.latLngToContainerPoint(moveLatLng));
    };
    const onEnd = () => { document.getElementById('warRoomMap').removeEventListener('touchmove', onMove); document.removeEventListener('touchend', onEnd); finishFreehand(); };
    document.getElementById('warRoomMap').addEventListener('touchmove', onMove, {passive: false});
    document.addEventListener('touchend', onEnd);
}, {passive: false});

// Arrow: two clicks (start, then end) — matches the dispatch polygon tool's
// own "click commits a point, no mousemove rubber-band" interaction, rather
// than inventing a richer one. Text: one click opens a Leaflet popup with a
// plain input, reusing the map's own popup positioning rather than a native
// prompt() (unused anywhere in this app for real data entry) or a modal
// (which would visually disconnect the input from the labeled location).
map.on('click', e => {
    if (activeTool === 'arrow') {
        if (!arrowStart) {
            arrowStart = e.latlng;
            arrowStartMarker = L.circleMarker(e.latlng, {radius:6, color: ANNOTATION_COLOR, fillColor:'#fff', fillOpacity:1, weight:2, pane:'annotationPane'}).addTo(annotationDrawLayer);
        } else {
            const points = [[arrowStart.lat, arrowStart.lng], [e.latlng.lat, e.latlng.lng]];
            annotationDrawLayer.clearLayers();
            arrowStart = null;
            submitAnnotation('arrow', points, null);
        }
    } else if (activeTool === 'text') {
        const latlng = e.latlng;
        L.popup({closeOnClick: false})
            .setLatLng(latlng)
            .setContent(`<input type="text" maxlength="80" class="form-control form-control-sm mb-1" id="annoTextInput" placeholder="${t('annotation.text_placeholder')}">
                          <button type="button" class="btn btn-sm btn-primary w-100" id="annoTextSave">${t('common.save')}</button>`)
            .openOn(map);
        setTimeout(() => {
            const input = document.getElementById('annoTextInput');
            if (!input) return;
            input.focus();
            const save = () => {
                const text = input.value.trim();
                if (text) submitAnnotation('text', {lat: latlng.lat, lng: latlng.lng}, text);
                map.closePopup();
            };
            document.getElementById('annoTextSave')?.addEventListener('click', save);
            input.addEventListener('keydown', ev => { if (ev.key === 'Enter') { ev.preventDefault(); save(); } });
        }, 0);
    }
});
if (missionLocation.lat) L.marker([missionLocation.lat, missionLocation.lng]).addTo(map).bindPopup('<strong>' + t('map.mission_point_label') + '</strong><br><?= h(addslashes($mission['title'])) ?>');
}
let hasFitPins = false;
function pinStatusLabel(status) {
    return {needs_help: t('status.badge_needs_help'), on_site: t('status.badge_on_site'), on_way: t('status.badge_on_way')}[status] || '';
}
// Whole-array JSON.stringify as the change-detection signature — same
// technique this file already uses for media (mediaSignature) — rather than
// hand-picking which fields matter: on a list this small, the string
// compare is cheap, and there's no risk of a hand-picked field list quietly
// missing something that actually changed (a real concern here specifically,
// since a missed field could mean a genuine needs_help/position update
// silently fails to redraw).
let pinsRenderedSig = null;
// Builds one pin's marker (icon + popup), with no opinion about which
// layer/map it ends up on — shared by the live map's own renderPins() and
// the Route Order composer's read-only reference layer (renderRouteComposerPins()
// below), so the team-color/stale/moving styling stays identical everywhere
// a pin can appear instead of drifting between two copies.
function buildPinMarker(pin) {
    const statusColors = {needs_help:'#dc2626', on_site:'#198754', on_way:'#f59e0b'};
    // Team color takes priority (the whole point is spotting which team a
    // pin belongs to at a glance); volunteers with no team fall back to the
    // original status-based color. needs_help always gets a pulsing red
    // ring on top, team-colored or not, so that safety signal never
    // disappears just because someone's on a team.
    const color = pin.team_color || statusColors[pin.status] || '#2563eb';
    const ring = pin.status === 'needs_help'
        ? 'border:3px solid #dc2626;animation:warRoomPulseRed 1s infinite;'
        : 'border:2px solid white;';
    // Stale = past due for a fresh ping but still their last-known
    // position, so it stays on the map (never silently vanishes) just
    // dimmed instead. Moving gets a small running-person badge (not a
    // full icon swap, so the team-color dot itself still reads the same
    // at a glance) plus, when the server could compute one (see
    // $loadPins's gpsBearingDegrees() call — only once two real,
    // non-jitter fixes are available), a small arrow rotated to the
    // compass heading of travel.
    const opacity = pin.is_stale ? 'opacity:.45;' : '';
    const movingBadge = pin.is_moving
        ? '<span style="position:absolute;top:-4px;right:-4px;width:14px;height:14px;background:#0ea5e9;border:2px solid #fff;border-radius:50%;font-size:8px;line-height:11px;text-align:center;">🏃</span>'
        : '';
    // heading_deg is a compass bearing (0°=North, clockwise) — exactly
    // what CSS rotate() already expects, so no conversion is needed. The
    // arrow itself points up (North) at rotate(0), same convention every
    // map/compass UI uses.
    const headingArrow = (pin.is_moving && pin.heading_deg !== null && pin.heading_deg !== undefined)
        ? `<span style="position:absolute;bottom:-9px;left:50%;transform:translateX(-50%) rotate(${pin.heading_deg}deg);color:${color};text-shadow:0 0 2px #fff,0 0 2px #fff,0 0 3px #fff;font-size:15px;line-height:1;">▲</span>`
        : '';
    const icon = L.divIcon({className:'', html:`<span style="position:relative;display:block;width:16px;height:16px;background:${color};${ring}${opacity}border-radius:50%;box-shadow:0 1px 4px #0008">${movingBadge}${headingArrow}</span>`, iconSize:[16,16], iconAnchor:[8,8]});
    const statusLine = pinStatusLabel(pin.status);
    const extraLine = pin.is_stale ? `<br><span class="text-muted small">${t('map.pin_stale')}</span>`
        : (pin.is_moving ? `<br><span class="text-info small">${t('map.pin_moving')}</span>` : '');
    const teamLine = pin.team_label ? `<br>${escapeHtml(pin.team_label)}` : '';
    const navUrl = `https://www.google.com/maps/dir/?api=1&destination=${pin.lat},${pin.lng}&travelmode=driving`;
    const navLine = `<br><a href="${navUrl}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary mt-1">${t('map.navigate_btn')}</a>`;
    return L.marker([pin.lat, pin.lng], {icon}).bindPopup(`<strong>${guestNameHtml(pin.name, pin.is_external, pin.guest_org_name)}</strong>${teamLine}<br>${pin.time}${statusLine ? '<br>' + statusLine : ''}${extraLine}${navLine}`);
}

function renderPins(items) {
    const sig = JSON.stringify(items);
    if (sig === pinsRenderedSig) return;
    pinsRenderedSig = sig;

    pinLayer.clearLayers();
    items.forEach(pin => buildPinMarker(pin).addTo(pinLayer));
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

// Nearby Teams (field-card column, both modes — the only place a Field Mode
// volunteer sees any position data at all, since that mode has no map) and
// Team Distances (small addendum inside the Teams panel, full view only).
// No existing meters->km or bearing->compass-letter formatter anywhere in
// this file to reuse (route.distance_from_point only ever shows raw
// unrounded meters) — both written fresh here.
function formatDistanceMeters(m) {
    if (m === null || m === undefined) return '';
    return m < 1000 ? `${Math.round(m)} ${t('common.unit_m')}` : `${(m / 1000).toFixed(1)} ${t('common.unit_km')}`;
}
function bearingToCompassAbbr(deg) {
    if (deg === null || deg === undefined) return '';
    const keys = ['compass.n', 'compass.ne', 'compass.e', 'compass.se', 'compass.s', 'compass.sw', 'compass.w', 'compass.nw'];
    return t(keys[Math.round(deg / 45) % 8]);
}

let nearbyTeamsRenderedSig = null;
function renderNearbyTeams(items) {
    const sig = JSON.stringify(items);
    if (sig === nearbyTeamsRenderedSig) return;
    nearbyTeamsRenderedSig = sig;

    const list = document.getElementById('nearbyTeamsList');
    if (!list) return;
    if (!items.length) {
        list.innerHTML = `<p class="text-muted mb-0 small">${t('nearby.empty')}</p>`;
        return;
    }
    // team.label is admin-settable free text (custom team codename) — escaped
    // here the same way the live map's own pin popups already had to be
    // fixed to do (stored-XSS audit, v3.129.0). team.color is a validated
    // hex swatch, interpolated the same unescaped way renderPins() already
    // does for the identical field.
    list.innerHTML = items.map(team => {
        const swatch = `<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:${team.color || '#6c757d'};margin-right:6px;"></span>`;
        const dimmed = team.is_stale ? 'opacity:.55;' : '';
        const distanceLine = (team.distance_m !== null && team.distance_m !== undefined)
            ? `${formatDistanceMeters(team.distance_m)} · ${bearingToCompassAbbr(team.bearing_deg)}`
            : `<span class="text-muted">${t('nearby.no_own_ping')}</span>`;
        const staleNote = team.is_stale ? ` · <span class="text-warning small">${t('map.pin_stale')}</span>` : '';
        return `<div class="d-flex justify-content-between align-items-center py-1 border-bottom" style="${dimmed}">
            <div>${swatch}<strong>${escapeHtml(team.label)}</strong></div>
            <div class="text-end small">${distanceLine}${staleNote}<br><span class="text-muted">${team.time}</span></div>
        </div>`;
    }).join('');
}

let teamDistancesRenderedSig = null;
function renderTeamDistances(items) {
    const sig = JSON.stringify(items);
    if (sig === teamDistancesRenderedSig) return;
    teamDistancesRenderedSig = sig;

    const section = document.getElementById('teamDistancesSection');
    if (!section) return;
    // Hidden rather than an empty-state message when fewer than 2 teams
    // currently have a position — "nothing to compare yet" isn't an
    // error/loading state worth its own line, unlike Nearby Teams' empty
    // state (which a field volunteer might otherwise wonder is broken).
    section.classList.toggle('d-none', items.length === 0);
    if (!items.length) return;

    document.getElementById('teamDistancesList').innerHTML = items.map(pair => {
        const swatchA = `<span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:${pair.a_color || '#6c757d'};margin-right:4px;"></span>`;
        const swatchB = `<span style="display:inline-block;width:9px;height:9px;border-radius:50%;background:${pair.b_color || '#6c757d'};margin-right:4px;"></span>`;
        const staleNote = pair.is_stale ? ` <span class="text-warning" title="${t('map.pin_stale')}">⚠</span>` : '';
        return `<div class="d-flex justify-content-between align-items-center py-1">
            <span>${swatchA}${escapeHtml(pair.a_label)} ↔ ${swatchB}${escapeHtml(pair.b_label)}</span>
            <span class="text-muted">${formatDistanceMeters(pair.distance_m)}${staleNote}</span>
        </div>`;
    }).join('');
}

// "Πορεία Ομάδων" — historical GPS trail view, toggled in place of the live
// pinLayer on the same map (not a second map instance). Own fitBounds call,
// deliberately not sharing hasFitPins above (that flag is one-shot for the
// initial live view and reusing it here would break one view or the other).
let trailModeActive = false;
// Everything currently loaded (unfiltered) — kept so the scrubber can
// re-render at any chosen instant without re-fetching, and so switching
// filters (team/include-auto) via "Εμφάνιση" naturally replaces it.
let currentTrails = [];

// Shared by both the plain full-trail view and the replay scrubber: cutoffTs
// = Infinity reproduces the original "show everything" behavior exactly
// (every point renders, the true last point is the highlighted marker).
// A real cutoff instead filters each trail down to points at-or-before that
// instant first — the highlighted marker then naturally becomes "wherever
// this person was AT that point in the replay," which is exactly the
// animated current-position behavior a scrubber needs, for free, with no
// separate marker-position logic.
function renderTrailUpTo(trails, cutoffTs) {
    trailLayer.clearLayers();
    const bounds = [];
    trails.forEach(trail => {
        const color = trail.team_color || '#2563eb';
        const points = cutoffTs === Infinity ? trail.points : trail.points.filter(p => p.ts <= cutoffTs);
        if (!points.length) return;
        const latlngs = points.map(p => [p.lat, p.lng]);
        if (latlngs.length > 1) {
            L.polyline(latlngs, {color, weight: 3, opacity: 0.8}).addTo(trailLayer);
        }
        points.forEach((point, i) => {
            const isLast = i === points.length - 1;
            const isFirst = i === 0 && points.length > 1;
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
function renderTrail(trails) {
    renderTrailUpTo(trails, Infinity);
}

// ── Replay scrubber ──────────────────────────────────────────────────────
const TRAIL_REPLAY_STEPS = 60;
const TRAIL_REPLAY_TICK_MS = 400; // ~24s to play a trail of any real length
let trailReplayTimer = null;
let trailMinTs = null, trailMaxTs = null;

function setupTrailReplay(trails) {
    const bar = document.getElementById('trailReplayBar');
    const scrubber = document.getElementById('trailScrubber');
    stopTrailReplay();
    const allTs = trails.flatMap(t => t.points.map(p => p.ts)).filter(ts => ts !== null && ts !== undefined);
    trailMinTs = allTs.length ? Math.min(...allTs) : null;
    trailMaxTs = allTs.length ? Math.max(...allTs) : null;
    // A single instant (or no points at all) has nothing to scrub between —
    // the plain full-trail view above already shows the one thing there is
    // to see, so the bar stays hidden rather than offering a slider with
    // nowhere to go.
    if (trailMinTs === null || trailMinTs === trailMaxTs) {
        bar.classList.add('d-none');
        return;
    }
    bar.classList.remove('d-none');
    scrubber.min = trailMinTs;
    scrubber.max = trailMaxTs;
    scrubber.value = trailMaxTs;
    updateTrailScrubberLabel(trailMaxTs);
    setTrailPlayIcon(false);
}

function updateTrailScrubberLabel(ts) {
    const label = document.getElementById('trailScrubberTime');
    if (!label) return;
    label.textContent = new Date(ts * 1000).toLocaleString(jsLocale, {day:'2-digit', month:'2-digit', hour:'2-digit', minute:'2-digit'});
}

function setTrailPlayIcon(isPlaying) {
    const btn = document.getElementById('trailPlayBtn');
    if (btn) btn.innerHTML = isPlaying ? '<i class="bi bi-pause-fill"></i>' : '<i class="bi bi-play-fill"></i>';
}

function stopTrailReplay() {
    if (trailReplayTimer) { clearInterval(trailReplayTimer); trailReplayTimer = null; }
    setTrailPlayIcon(false);
}

document.getElementById('trailScrubber')?.addEventListener('input', function() {
    stopTrailReplay();
    const ts = Number(this.value);
    renderTrailUpTo(currentTrails, ts);
    updateTrailScrubberLabel(ts);
});

document.getElementById('trailPlayBtn')?.addEventListener('click', function() {
    if (trailReplayTimer) { stopTrailReplay(); return; }
    const scrubber = document.getElementById('trailScrubber');
    // Restart from the beginning if play is pressed at (or very near) the
    // end — matches how every ordinary media player's play/pause behaves,
    // rather than doing nothing because there's no time left to advance.
    if (Number(scrubber.value) >= trailMaxTs - 1) {
        scrubber.value = trailMinTs;
        renderTrailUpTo(currentTrails, trailMinTs);
        updateTrailScrubberLabel(trailMinTs);
    }
    setTrailPlayIcon(true);
    const stepMs = Math.max(1, Math.round((trailMaxTs - trailMinTs) / TRAIL_REPLAY_STEPS));
    trailReplayTimer = setInterval(() => {
        const next = Number(scrubber.value) + stepMs;
        if (next >= trailMaxTs) {
            scrubber.value = trailMaxTs;
            renderTrailUpTo(currentTrails, trailMaxTs);
            updateTrailScrubberLabel(trailMaxTs);
            stopTrailReplay();
            return;
        }
        scrubber.value = next;
        renderTrailUpTo(currentTrails, next);
        updateTrailScrubberLabel(next);
    }, TRAIL_REPLAY_TICK_MS);
});
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
        currentTrails = result.trails;
        renderTrail(currentTrails);
        setupTrailReplay(currentTrails);
    }).catch(() => alert(t('trail.load_failed')));
}
function exitTrailMode() {
    stopTrailReplay();
    document.getElementById('trailReplayBar')?.classList.add('d-none');
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

// ── Route Orders ("Εντολή Πορείας") ─────────────────────────────────────────
// One team-scoped multi-waypoint patrol. Field mode has no map/media panel at
// all (see the `!fieldMode`-gated block above), so this card is fully
// self-contained: its own directions links, its own photo/video capture
// (hits mission-photo.php directly with route_waypoint_id), its own GPS
// capture for "arrive". Every mutating call POSTs to mission-route.php and
// gets the full routes[] back, which is what's re-rendered — same
// "server is the source of truth, re-render from its response" pattern the
// rest of this page already uses (renderMyTasks, dispatch ack/receive, ...).

function postRouteAction(action, id, extra) {
    const data = new URLSearchParams(Object.assign({csrf_token: csrfToken, mission_id: '<?= $missionId ?>', action, id: String(id)}, extra || {}));
    return fetch('mission-route.php', {method: 'POST', body: data}).then(response => {
        if (!checkSessionAlive(response)) return null;
        return response.json();
    }).then(result => {
        if (result && result.ok && result.routes) {
            routes = result.routes;
            renderMyRoutes(routes);
            if (!fieldMode) { renderRoutesAdmin(routes); renderRouteLayer(routes); }
        }
        return result;
    }).catch(() => ({ok: false, error: t('common.network_error'), networkError: true}));
    // The .catch() above turns an unreachable-server rejection into a normal
    // resolved {ok:false} — every existing caller already handles that shape
    // (shows the error, re-enables its button) instead of the promise
    // silently rejecting with no handler anywhere, which is what used to
    // happen: a lost connection left the just-clicked button permanently
    // disabled with no feedback at all. postRouteActionQueueable() below is
    // the one caller that treats `networkError` specially instead of just
    // displaying it.
}

// ── Offline queue (field reports only) ──────────────────────────────────────
// A team member losing signal mid-mission must never just lose the tap: it's
// queued locally and replayed once connectivity returns, with the server
// recording the *actual* field time (reported_at) rather than whenever the
// network happened to come back — see resolveEventTimestamp() in
// includes/functions.php, which both replayed endpoints share.
//
// Two kinds ride this queue:
//   kind:'route'  → mission-route.php  (depart/arrive/complete)
//   kind:'status' → volunteer-status.php (on_way/on_site/needs_help, i.e. SOS)
//
// Desk-side admin actions (cancel/skip/edit_waypoint/create, SOS acknowledge/
// resolve) deliberately do NOT go through this — they're not the ones losing
// signal in the field, and their own networkError already surfaces a clear
// error for them same as before.
const OFFLINE_QUEUE_KEY = 'wr_offline_queue_<?= $missionId ?>';
const LEGACY_ROUTE_QUEUE_KEY = 'wr_route_queue_<?= $missionId ?>';

function loadQueue() {
    try {
        const parsed = JSON.parse(localStorage.getItem(OFFLINE_QUEUE_KEY) || '[]');
        return Array.isArray(parsed) ? parsed : [];
    } catch (e) { return []; }
}
function saveQueue(queue) {
    try { localStorage.setItem(OFFLINE_QUEUE_KEY, JSON.stringify(queue)); } catch (e) {}
}

// Before this release the queue was route-only, under its own key, with no
// `kind` field. A phone can still be carrying items from its last session —
// adopting them beats orphaning real field reports, so they're folded in
// (tagged as what they always were) and the old key is retired.
(function migrateLegacyRouteQueue() {
    let legacy;
    try { legacy = JSON.parse(localStorage.getItem(LEGACY_ROUTE_QUEUE_KEY) || '[]'); } catch (e) { legacy = []; }
    if (!Array.isArray(legacy) || !legacy.length) {
        try { localStorage.removeItem(LEGACY_ROUTE_QUEUE_KEY); } catch (e) {}
        return;
    }
    saveQueue(loadQueue().concat(legacy.map(item => Object.assign({kind: 'route'}, item))));
    try { localStorage.removeItem(LEGACY_ROUTE_QUEUE_KEY); } catch (e) {}
})();

function enqueueAction(item) {
    const queue = loadQueue();
    // Someone who needs help taps SOS again, and again, and again — that must
    // not become five identical queued alerts all replaying at once. An
    // already-queued identical status is left exactly as it is rather than
    // replaced, so the queued reported_at stays the moment help was FIRST
    // asked for, which is the time command staff needs to see. Different
    // statuses for the same person are never collapsed: on_way → needs_help
    // → on_site is a real sequence and replays in order.
    if (item.kind === 'status' &&
        queue.some(q => q.kind === 'status' && q.prId === item.prId && q.status === item.status)) {
        renderQueueStatus();
        return;
    }
    queue.push(item);
    saveQueue(queue);
    renderQueueStatus();
}

function renderQueueStatus() {
    const el = document.getElementById('offlineQueueBanner');
    if (!el) return;
    const queue = loadQueue();
    if (!queue.length) { el.classList.add('d-none'); el.innerHTML = ''; return; }
    el.classList.remove('d-none');
    // An SOS sitting in the queue is a different situation from a pending
    // arrival and gets said out loud, not folded into a generic count.
    const sosPending = queue.some(item => item.kind === 'status' && item.status === 'needs_help');
    el.className = 'alert py-1 px-2 small mb-2 ' + (sosPending ? 'alert-danger' : 'alert-warning');
    const pendingText = queue.length === 1 ? t('queue.pending_one') : t('queue.pending', {count: queue.length});
    el.innerHTML = `<i class="bi bi-wifi-off me-1"></i>${pendingText}` +
        (sosPending ? `<div class="fw-bold mt-1"><i class="bi bi-exclamation-triangle-fill me-1"></i>${t('queue.sos_pending')}</div>` : '');
}

// In-memory only (not localStorage) — a permanent failure should be seen and
// dismissed in the session it happened, not resurface as a stale warning
// after a later, unrelated reload.
let queueFailures = [];
function showQueueFailureNotice(error) {
    queueFailures.push(error || t('common.failed'));
    renderQueueFailures();
}
function renderQueueFailures() {
    const el = document.getElementById('offlineQueueFailures');
    if (!el) return;
    el.innerHTML = queueFailures.map((error, i) => `
        <div class="alert alert-danger py-1 px-2 small mb-1 d-flex justify-content-between align-items-center">
            <span><i class="bi bi-exclamation-triangle-fill me-1"></i>${t('queue.failed', {error: escapeHtml(error)})}</span>
            <button type="button" class="btn-close offline-queue-dismiss" style="font-size:.65rem;" data-i="${i}"></button>
        </div>
    `).join('');
    el.querySelectorAll('.offline-queue-dismiss').forEach(btn => btn.addEventListener('click', () => {
        queueFailures.splice(+btn.dataset.i, 1);
        renderQueueFailures();
    }));
}

// depart/arrive/complete's own wrapper: queues on a real networkError instead
// of surfacing it, everything else (success, needs_confirm, a real server
// error like "route already closed") behaves exactly like a plain
// postRouteAction call.
function postRouteActionQueueable(action, id, extra) {
    const payload = Object.assign({}, extra || {}, {reported_at: new Date().toISOString()});
    return postRouteAction(action, id, payload).then(result => {
        if (!result || result.networkError) {
            enqueueAction({kind: 'route', action, id: String(id), extra: payload});
            return {ok: true, queued: true};
        }
        return result;
    });
}

function sendQueuedItem(item) {
    if (item.kind === 'status') {
        return postFieldStatus(item.prId, item.status, item.extra || {}).then(result => {
            // markFieldStatusQueued() left the badge saying "not sent yet".
            // Nothing else on this page re-renders that badge, so replace it
            // here with what the server actually recorded — otherwise a
            // delivered status would keep claiming it was still pending.
            if (result && result.ok && result.label) {
                const badge = document.getElementById('statusBadge-' + item.prId);
                if (badge) badge.textContent = result.label;
            }
            return result;
        });
    }
    // Auto-confirm out-of-sequence on every replay, no popup: the volunteer
    // already expressed clear intent by tapping the button once, in the
    // field — a queued retry has no user present to re-ask, and asking them
    // to remember "was that out of sequence?" after the fact adds confusion,
    // not safety. If it's not actually out of sequence this is simply unused.
    return postRouteAction(item.action, item.id, Object.assign({}, item.extra, {confirm_out_of_sequence: '1'}));
}

// The queue lives in localStorage, which is shared by every tab on this
// origin — so `queueFlushInFlight` alone (a per-page variable) does not stop
// two open Action Room tabs from replaying the SAME queued item at the same
// moment. Seen for real in testing: one queued SOS produced two simultaneous
// POSTs and two audit rows, saved from becoming two alerts only by the
// server's duplicate guard. This lock is cross-tab. It carries a timestamp
// rather than a bare flag so a tab that dies mid-flush (phone kills the
// browser — the exact scenario this whole feature exists for) can't wedge the
// queue shut forever.
const QUEUE_LOCK_KEY = 'wr_queue_lock_<?= $missionId ?>';
const QUEUE_LOCK_TTL_MS = 30000;

function acquireQueueLock() {
    try {
        const held = parseInt(localStorage.getItem(QUEUE_LOCK_KEY) || '0', 10);
        if (held && Date.now() - held < QUEUE_LOCK_TTL_MS) return false;
        localStorage.setItem(QUEUE_LOCK_KEY, String(Date.now()));
        return true;
    } catch (e) {
        return true; // storage unavailable — better to send twice than never
    }
}
function releaseQueueLock() {
    try { localStorage.removeItem(QUEUE_LOCK_KEY); } catch (e) {}
}

let queueFlushInFlight = false;
function flushQueue() {
    if (queueFlushInFlight) return;
    const queue = loadQueue();
    if (!queue.length) return;
    if (!acquireQueueLock()) return; // another tab is already sending this
    queueFlushInFlight = true;
    const item = queue[0];
    let progressed = false;
    sendQueuedItem(item).then(result => {
        // Two retryable outcomes, both of which must LEAVE the item queued:
        //   networkError → still no signal
        //   null         → checkSessionAlive() saw a redirect to login, i.e.
        //                  the session expired while this device was offline
        // The second used to fall through to the drop below, which silently
        // destroyed genuine field reports (including an SOS) after exactly
        // the kind of long outage the queue exists for. It now survives until
        // the volunteer logs back in and the next flush carries it through.
        if (!result || result.networkError) return;
        progressed = true;
        // Either it succeeded, or it came back with a real, permanent server
        // error (e.g. the route was cancelled while this volunteer was
        // offline) — retrying won't fix the latter either, so either way this
        // item is done: drop it and move to the next queued one.
        saveQueue(loadQueue().slice(1));
        renderQueueStatus();
        if (!result.ok) {
            showQueueFailureNotice(result.error);
        }
    }).finally(() => {
        queueFlushInFlight = false;
        releaseQueueLock();
        // Only chain straight into the next item when this one actually
        // cleared. Without that guard a stuck head (offline, or an expired
        // session) would re-fire every 500ms forever instead of waiting for
        // the 15s timer / the next `online` event.
        if (progressed && loadQueue().length) setTimeout(flushQueue, 500);
    });
}
window.addEventListener('online', flushQueue);
setInterval(flushQueue, 15000);

// ── Offline field snapshot ──────────────────────────────────────────────────
// The queue above saves what the volunteer *sends*. This saves what they need
// to *read*: reload the page (or have the OS kill the tab, which it will over
// a multi-hour mission) with no signal and every .php request falls back to
// offline.html — previously a bare "no connection" screen, so the volunteer
// lost even the address of their next waypoint. offline.html now reads this
// snapshot and renders the route, the orders, and tappable phone numbers.
//
// localStorage, not the Cache API: offline.html is a static file served by the
// service worker, which cannot read a cached authenticated PHP response, but
// it CAN read same-origin localStorage from the page context.
const SNAPSHOT_KEY = 'wr_field_snapshot';
const OFFLINE_CONTACTS = <?= json_encode($offlineContacts, JSON_UNESCAPED_UNICODE) ?>;
const SNAPSHOT_MISSION = <?= json_encode(['id' => $missionId, 'title' => $mission['title'], 'location' => $mission['location'] ?? ''], JSON_UNESCAPED_UNICODE) ?>;
const SNAPSHOT_LANG = <?= json_encode($__viewerLang) ?>;

let lastSnapshotSignature = '';
function saveFieldSnapshot() {
    // Cancelled routes are noise on a screen someone is reading in the field,
    // and only my own team's routes belong on a phone at all — command staff
    // load every team's, and copying all of them into offline storage is both
    // clutter and needless exposure of other teams' movements. A viewer with
    // no team of their own (desk-side command staff) still gets the active
    // ones, since an empty snapshot would help nobody.
    //
    // NOTE: the client-side route shape has no `cancelled_at` — the server
    // collapses it into `status` ('active'|'completed'|'cancelled') and only
    // ships `cancelled_at_display`. Filtering on `cancelled_at` here silently
    // matched everything (undefined is falsy); caught by inspecting the real
    // payload in the browser rather than by reading the query.
    const activeRoutes = routes.filter(r => r.status !== 'cancelled');
    const myTeamRoutes = activeRoutes.filter(r => r.is_route_member);
    const snapRoutes = myTeamRoutes.length ? myTeamRoutes : activeRoutes;
    const signature = JSON.stringify([snapRoutes, myTasks]);
    if (signature === lastSnapshotSignature) return;
    lastSnapshotSignature = signature;
    try {
        localStorage.setItem(SNAPSHOT_KEY, JSON.stringify({
            savedAt: Date.now(),
            lang: SNAPSHOT_LANG,
            mission: SNAPSHOT_MISSION,
            routes: snapRoutes,
            tasks: myTasks,
            contacts: OFFLINE_CONTACTS
        }));
    } catch (e) {
        // Quota exceeded on a phone with a full origin: the snapshot is a
        // nice-to-have, the queue in the same storage is not. Drop the
        // snapshot rather than let a failed write bubble anywhere near the
        // field-report path.
        try { localStorage.removeItem(SNAPSHOT_KEY); } catch (e2) {}
    }
}

// Shared by depart/arrive/complete: on a needs_confirm response, ask once and
// replay the exact same call with confirm_out_of_sequence=1. The primary path
// never hits this — renderMyRoutes() only shows a plain action button on the
// current waypoint and a separate confirm-first "jump" button on later ones —
// this is defense-in-depth for stale client state (see mission-route.php).
// A `queued` result (offline queue above) is left untouched here — it's
// already ok:true, and the queue banner is the feedback for that case, not
// an alert.
function handleRouteActionResult(result, retry) {
    if (!result) return;
    if (!result.ok && result.needs_confirm) {
        if (confirm(result.error)) retry();
    } else if (!result.ok) {
        alert(result.error || t('common.failed'));
    }
}

function routeDepart(waypointId, confirmed) {
    postRouteActionQueueable('depart', waypointId, confirmed ? {confirm_out_of_sequence: '1'} : {})
        .then(result => handleRouteActionResult(result, () => routeDepart(waypointId, true)));
}

function routeArrive(waypointId, confirmed) {
    const post = (lat, lng, accuracy) => {
        const extra = {lat: lat ?? '', lng: lng ?? '', accuracy: accuracy ?? ''};
        if (confirmed) extra.confirm_out_of_sequence = '1';
        postRouteActionQueueable('arrive', waypointId, extra)
            .then(result => handleRouteActionResult(result, () => routeArrive(waypointId, true)));
    };
    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(pos => post(pos.coords.latitude, pos.coords.longitude, pos.coords.accuracy), () => post(null, null, null), {enableHighAccuracy: true, timeout: 10000});
    } else {
        post(null, null, null);
    }
}

// Checked against the already-polled `routes` array — no network round-trip,
// so this catches a missing deliverable even while fully offline. That
// matters specifically because "complete" is queueable: without this,
// tapping Complete with no signal would queue silently (looks like it
// worked) and only surface the missing-photo rejection once back online,
// possibly hours later and long past the point where retaking that photo is
// still practical. The server enforces the same rule independently (see
// mission-route.php's missingRouteDeliverables()) for the offline-queue
// replay path itself, where naturally no user is present to see this alert.
function findRouteWaypointById(waypointId) {
    for (const route of routes) {
        const wp = route.waypoints.find(w => String(w.id) === String(waypointId));
        if (wp) return wp;
    }
    return null;
}
function missingRouteDeliverablesClientSide(wp, noteValue) {
    const missing = [];
    if (wp.require_photo && !wp.photo) missing.push(t('route.deliverable_photo'));
    if (wp.require_video && !wp.video) missing.push(t('route.deliverable_video'));
    if (wp.require_note && !(noteValue || wp.note || '').trim()) missing.push(t('route.deliverable_note'));
    return missing;
}

function routeComplete(waypointId, confirmed) {
    const noteInput = document.querySelector(`.route-note-input[data-id="${waypointId}"]`);
    const noteValue = noteInput ? noteInput.value.trim() : '';
    const wp = findRouteWaypointById(waypointId);
    if (wp) {
        const missing = missingRouteDeliverablesClientSide(wp, noteValue);
        if (missing.length) {
            alert(t('route.missing_deliverables', {items: missing.join(', ')}));
            const group = document.querySelector(`.route-complete-btn[data-id="${waypointId}"]`);
            if (group) group.disabled = false;
            return;
        }
    }
    const extra = {};
    if (noteValue !== '') extra.note = noteValue;
    if (confirmed) extra.confirm_out_of_sequence = '1';
    postRouteActionQueueable('complete', waypointId, extra)
        .then(result => handleRouteActionResult(result, () => routeComplete(waypointId, true)));
}

function uploadWaypointMedia(waypointId, file, mediaType, statusEl) {
    if (statusEl) { statusEl.textContent = t('media.uploading'); statusEl.className = 'small text-muted'; }
    const send = (lat, lng) => {
        const data = new FormData();
        data.append('csrf_token', csrfToken);
        data.append('action', 'upload');
        data.append('mission_id', '<?= $missionId ?>');
        data.append('media', file);
        data.append('route_waypoint_id', String(waypointId));
        if (lat !== null) { data.append('lat', lat); data.append('lng', lng); }
        fetch('mission-photo.php', {method: 'POST', body: data}).then(r => r.json()).then(result => {
            if (result.ok) {
                for (const r of routes) {
                    const wp = r.waypoints.find(w => w.id === waypointId);
                    if (wp) {
                        const entry = {id: result.media.id, time: result.media.time};
                        if (result.media.media_type === 'video') wp.video = entry; else wp.photo = entry;
                        break;
                    }
                }
                renderMyRoutes(routes);
            } else {
                if (statusEl) { statusEl.textContent = result.error || t('common.send_failed'); statusEl.className = 'small text-danger'; }
            }
        }).catch(() => { if (statusEl) { statusEl.textContent = t('common.send_failed'); statusEl.className = 'small text-danger'; } });
    };
    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(pos => send(pos.coords.latitude, pos.coords.longitude), () => send(null, null), {enableHighAccuracy: true, timeout: 8000});
    } else {
        send(null, null);
    }
}

function triggerWaypointUpload(waypointId, mediaType, statusEl) {
    const input = document.createElement('input');
    input.type = 'file';
    input.accept = mediaType === 'video' ? 'video/*' : 'image/*';
    input.setAttribute('capture', 'environment');
    input.addEventListener('change', () => { if (input.files[0]) uploadWaypointMedia(waypointId, input.files[0], mediaType, statusEl); });
    input.click();
}

function routeWaypointDirectionsUrl(wp) {
    return `https://www.google.com/maps/dir/?api=1&destination=${wp.lat},${wp.lng}&travelmode=driving`;
}

function routeDwellCountdownHtml(wp) {
    if (!wp.arrived_at || wp.dwell_minutes === null) return '';
    const deadlineMs = Date.parse(wp.arrived_at) + wp.dwell_minutes * 60000;
    return `<div class="small mt-1"><i class="bi bi-hourglass-split me-1"></i><span class="route-countdown" data-deadline="${deadlineMs}" data-waypoint-id="${wp.id}"></span></div>`;
}

// A waypoint's dwell time is advisory (see mission-route.php's docblock — this
// never blocks or auto-completes anything), but the person standing there
// should still get a clear one-time heads-up the moment it elapses, the same
// alert sound every other War Room order already uses. Fires once per
// waypoint (tracked here, not server-side) since updateRouteCountdowns() below
// re-runs every second for as long as the countdown element stays on screen.
const routeOverdueAlerted = new Set();

function renderRouteWaypointCurrent(wp) {
    const label = wp.label ? escapeHtml(wp.label) : t('route.waypoint_fallback_label', {seq: wp.seq});
    let statusLine, actionHtml = '';
    if (!wp.departed_at) {
        statusLine = '';
        actionHtml = `<button type="button" class="btn btn-sm wr-touch-btn w-100 btn-primary route-depart-btn" data-id="${wp.id}"><i class="bi bi-play-fill me-1"></i>${t('route.depart_btn')}</button>`;
    } else if (!wp.arrived_at) {
        statusLine = `<div class="small text-warning mt-1"><i class="bi bi-car-front-fill me-1"></i>${t('route.enroute_since_prefix', {time: wp.departed_at_display})}</div>`;
        actionHtml = `<button type="button" class="btn btn-sm wr-touch-btn w-100 btn-success route-arrive-btn" data-id="${wp.id}"><i class="bi bi-geo-alt-fill me-1"></i>${t('route.arrive_btn')}</button>`;
    } else {
        const distanceHtml = routeDistanceBadgeHtml(wp);
        statusLine = `<div class="small text-success mt-1"><i class="bi bi-check-circle-fill me-1"></i>${t('route.onsite_since_prefix', {time: wp.arrived_at_display})}</div>`
            + (distanceHtml ? `<div class="small mt-1">${distanceHtml}</div>` : '')
            + routeDwellCountdownHtml(wp);
        const deliverables = [];
        if (wp.require_photo) {
            deliverables.push(wp.photo
                ? `<span class="badge bg-success"><i class="bi bi-camera-fill me-1"></i>${t('route.photo_btn')} ✓</span>`
                : `<button type="button" class="btn btn-sm wr-touch-btn btn-outline-primary route-media-btn" data-id="${wp.id}" data-media-type="photo"><i class="bi bi-camera-fill me-1"></i>${t('route.photo_btn')}</button>`);
        }
        if (wp.require_video) {
            deliverables.push(wp.video
                ? `<span class="badge bg-success"><i class="bi bi-camera-reels-fill me-1"></i>${t('route.video_btn')} ✓</span>`
                : `<button type="button" class="btn btn-sm wr-touch-btn btn-outline-primary route-media-btn" data-id="${wp.id}" data-media-type="video"><i class="bi bi-camera-reels-fill me-1"></i>${t('route.video_btn')}</button>`);
        }
        const deliverablesHtml = deliverables.length
            ? `<div class="d-flex flex-wrap gap-2 mt-2">${deliverables.join('')}</div><div class="small route-media-status mt-1"></div>`
            : '';
        const noteHtml = wp.require_note
            ? `<textarea class="form-control form-control-sm route-note-input mt-2" data-id="${wp.id}" rows="2" maxlength="2000" placeholder="${escapeHtml(t('route.note_placeholder'))}">${wp.note ? escapeHtml(wp.note) : ''}</textarea><div class="small text-muted">${t('route.note_hint')}</div>`
            : '';
        actionHtml = deliverablesHtml + noteHtml + `<button type="button" class="btn btn-sm wr-touch-btn btn-success w-100 mt-2 route-complete-btn" data-id="${wp.id}"><i class="bi bi-check-lg me-1"></i>${t('route.complete_btn')}</button>`;
    }
    return `<div class="border rounded p-2 mb-2 border-primary">
        <div class="d-flex justify-content-between align-items-start">
            <strong class="small">${wp.seq}. ${label}</strong>
            <a href="${routeWaypointDirectionsUrl(wp)}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-success py-0 px-1" title="${t('dispatch.directions_btn')}"><i class="bi bi-signpost-2-fill"></i></a>
        </div>
        ${wp.instructions ? `<div class="small mt-1">${escapeHtml(wp.instructions)}</div>` : ''}
        ${statusLine}
        ${actionHtml}
    </div>`;
}

// Advisory GPS-vs-pin distance, captured once at "arrive" and never used to
// block anything (see mission-route.php's docblock) — just surfaced so
// whoever's reviewing can see "arrival was reported 340m from the pin"
// instead of taking a self-report at face value with no corroboration.
function routeDistanceBadgeHtml(wp) {
    if (wp.arrived_distance_m === null || wp.arrived_distance_m === undefined) return '';
    const accSuffix = (wp.arrived_accuracy_m !== null && wp.arrived_accuracy_m !== undefined)
        ? ' ' + t('route.distance_accuracy_suffix', {accuracy: Math.round(wp.arrived_accuracy_m)})
        : '';
    return `<span class="text-muted"><i class="bi bi-rulers me-1"></i>${t('route.distance_from_point', {m: wp.arrived_distance_m})}${accSuffix}</span>`;
}

function renderRouteWaypointClosed(wp) {
    const label = wp.label ? escapeHtml(wp.label) : t('route.waypoint_fallback_label', {seq: wp.seq});
    if (wp.skipped_at) {
        return `<div class="border rounded p-2 mb-2 bg-light">
            <div class="small text-muted"><i class="bi bi-skip-forward-fill me-1"></i>${wp.seq}. ${label} — <span class="text-warning">${t('route.skipped_prefix')}</span>${wp.skip_reason ? ' (' + escapeHtml(wp.skip_reason) + ')' : ''}</div>
        </div>`;
    }
    const mediaHtml = (wp.photo || wp.video)
        ? `<div class="d-flex gap-2 mt-1">
            ${wp.photo ? `<img src="mission-photo-view.php?id=${wp.photo.id}" style="max-height:70px;border-radius:4px;">` : ''}
            ${wp.video ? `<video src="mission-photo-view.php?id=${wp.video.id}" style="max-height:70px;border-radius:4px;" muted></video>` : ''}
          </div>`
        : '';
    const distanceHtml = routeDistanceBadgeHtml(wp);
    return `<div class="border rounded p-2 mb-2 bg-light">
        <div class="small text-muted"><i class="bi bi-check-circle-fill text-success me-1"></i>${wp.seq}. ${label} — ${t('route.completed_at_prefix', {time: wp.completed_at_display})}</div>
        ${distanceHtml ? `<div class="small mt-1">${distanceHtml}</div>` : ''}
        ${wp.note ? `<div class="small fst-italic mt-1">"${escapeHtml(wp.note)}"</div>` : ''}
        ${mediaHtml}
    </div>`;
}

function renderRouteWaypointUpcoming(wp) {
    const label = wp.label ? escapeHtml(wp.label) : t('route.waypoint_fallback_label', {seq: wp.seq});
    return `<div class="border rounded p-2 mb-2" style="opacity:.65;">
        <div class="d-flex justify-content-between align-items-center">
            <span class="small"><i class="bi bi-lock-fill me-1"></i>${wp.seq}. ${label}</span>
            <div class="d-flex gap-1">
                <a href="${routeWaypointDirectionsUrl(wp)}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-success py-0 px-1" title="${t('dispatch.directions_btn')}"><i class="bi bi-signpost-2-fill"></i></a>
                <button type="button" class="btn btn-sm btn-outline-secondary py-0 route-jump-btn" data-id="${wp.id}">${t('route.jump_btn')}</button>
            </div>
        </div>
    </div>`;
}

// Same whole-array-JSON signature technique as renderPins/renderDispatches —
// this card also holds a live note textarea (route.note_hint) per open
// waypoint, so skipping the rebuild when the server-side route data hasn't
// actually changed protects an in-progress typed note from being wiped out
// by routine polling, same concern renderShortageReports' own signature
// check already exists to solve for its own note input.
let myRoutesRenderedSig = null;
function renderMyRoutes(allRoutes) {
    const sig = JSON.stringify(allRoutes);
    if (sig === myRoutesRenderedSig) return;
    myRoutesRenderedSig = sig;

    const list = document.getElementById('myRoutesList');
    const myRoutes = (allRoutes || []).filter(r => r.is_route_member);
    if (!myRoutes.length) {
        list.innerHTML = '<p class="text-muted mb-0">' + t('route.my_empty') + '</p>';
        return;
    }
    list.innerHTML = myRoutes.map(route => {
        const done = route.waypoints.filter(w => w.completed_at || w.skipped_at).length;
        const currentSeq = (route.waypoints.find(w => !w.completed_at && !w.skipped_at) || {}).seq;
        const statusBadge = route.status === 'cancelled'
            ? `<span class="badge bg-secondary">${t('route.status_cancelled')}</span>`
            : route.status === 'completed'
                ? `<span class="badge bg-success">${t('route.status_completed')}</span>`
                : `<span class="badge bg-primary">${done}/${route.waypoints.length}</span>`;
        const waypointsHtml = route.status === 'cancelled'
            ? `<div class="small text-muted">${t('route.cancelled_reason_prefix')}${route.cancel_reason ? ' — ' + escapeHtml(route.cancel_reason) : ''}</div>`
            : route.waypoints.map(wp => {
                if (wp.completed_at || wp.skipped_at) return renderRouteWaypointClosed(wp);
                if (wp.seq === currentSeq) return renderRouteWaypointCurrent(wp);
                return renderRouteWaypointUpcoming(wp);
            }).join('');
        return `<div class="mb-3">
            <div class="d-flex justify-content-between align-items-center mb-1">
                <strong class="small text-uppercase">${route.title ? escapeHtml(route.title) : t('route.default_title')}</strong>
                ${statusBadge}
            </div>
            ${waypointsHtml}
        </div>`;
    }).join('');

    list.querySelectorAll('.route-depart-btn').forEach(btn => btn.addEventListener('click', () => { btn.disabled = true; routeDepart(btn.dataset.id, false); }));
    list.querySelectorAll('.route-arrive-btn').forEach(btn => btn.addEventListener('click', () => { btn.disabled = true; routeArrive(btn.dataset.id, false); }));
    list.querySelectorAll('.route-complete-btn').forEach(btn => btn.addEventListener('click', () => { btn.disabled = true; routeComplete(btn.dataset.id, false); }));
    list.querySelectorAll('.route-jump-btn').forEach(btn => btn.addEventListener('click', () => {
        if (confirm(t('route.confirm_out_of_sequence'))) routeDepart(btn.dataset.id, true);
    }));
    list.querySelectorAll('.route-media-btn').forEach(btn => btn.addEventListener('click', () => {
        const statusEl = btn.closest('.d-flex').nextElementSibling;
        triggerWaypointUpload(btn.dataset.id, btn.dataset.mediaType, statusEl);
    }));
    updateRouteCountdowns();
}

function updateRouteCountdowns() {
    const now = Date.now();
    document.querySelectorAll('.route-countdown[data-deadline]').forEach(el => {
        const diffMs = parseInt(el.dataset.deadline, 10) - now;
        if (diffMs <= 0) {
            el.textContent = t('route.dwell_overdue');
            el.classList.add('text-danger');
            const waypointId = el.dataset.waypointId;
            if (waypointId && !routeOverdueAlerted.has(waypointId)) {
                routeOverdueAlerted.add(waypointId);
                playWarRoomAlertSound();
            }
        } else {
            const mins = Math.floor(diffMs / 60000);
            const secs = Math.floor((diffMs % 60000) / 1000);
            el.textContent = t('route.dwell_remaining', {time: mins + ':' + String(secs).padStart(2, '0')});
        }
    });
}
setInterval(updateRouteCountdowns, 1000);

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

function renderPingStaleness(staleness) {
    document.querySelectorAll('[id^="ping-stale-"]').forEach(el => {
        const uid = el.id.slice('ping-stale-'.length);
        el.classList.toggle('d-none', !staleness[uid]);
    });
}

let shortageReportsRenderedSig = null;
function renderShortageReports(items) {
    const list = document.getElementById('shortageReportsList');
    if (!list) return;
    // This whole card gets fed a fresh array every 5s poll tick regardless of
    // whether anything actually changed — rebuilding list.innerHTML every
    // single time destroys any note textarea an admin is mid-typing into
    // (value AND focus/cursor, since it's a brand new DOM node each time).
    // Skip the rebuild entirely when the set of reports + their seen-state
    // is identical to what's already on screen, so routine polling never
    // interrupts someone actively writing a note. A real change (new report,
    // someone else marks one seen/resolved) still re-renders normally.
    const sig = items.map(r => r.id + ':' + (r.acknowledged_at ? '1' : '0')).join(',');
    if (sig === shortageReportsRenderedSig) return;
    shortageReportsRenderedSig = sig;

    if (!items.length) {
        list.innerHTML = '<p class="text-muted mb-0">' + t('shortage.empty_list') + '</p>';
        return;
    }
    const sevColor = {low: 'secondary', medium: 'info', high: 'warning', critical: 'danger'};
    list.innerHTML = items.map(r => `
        <div class="border rounded p-2 mb-2">
            <div><span class="badge bg-${sevColor[r.severity] || 'secondary'}">${r.severity_label}</span> <strong>${r.type_label}</strong> — ${escapeHtml(r.title)}</div>
            <div class="small mt-1">${escapeHtml(r.description)}</div>
            <div class="text-muted" style="font-size:.75rem;">${guestNameHtml(r.reporter_name, r.is_external, r.guest_org_name)} (${escapeHtml(r.team_label)}) · ${r.created_at}${r.acknowledged_at ? t('shortage.seen_at_prefix', {time: r.acknowledged_at}) : ''}</div>
            ${r.acknowledged_at ? `<textarea class="form-control form-control-sm mt-1 shortage-note-input" data-report-id="${r.id}" rows="1" placeholder="${t('shortage.note_placeholder')}"></textarea>` : ''}
            <div class="mt-1 d-flex gap-1">${r.acknowledged_at
                ? `<button type="button" class="btn btn-sm btn-success flex-fill shortage-resolve-btn" data-report-id="${r.id}">${t('shortage.resolve_btn')}</button>
                   <button type="button" class="btn btn-sm btn-outline-danger flex-fill shortage-not-resolved-btn" data-report-id="${r.id}">${t('shortage.not_resolved_btn')}</button>`
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
    function submitShortageOutcome(btn, action) {
        btn.disabled = true;
        const noteInput = list.querySelector(`.shortage-note-input[data-report-id="${btn.dataset.reportId}"]`);
        const data = new URLSearchParams({csrf_token: csrfToken, action, report_id: btn.dataset.reportId, note: noteInput ? noteInput.value : ''});
        fetch('mission-shortage.php', {method: 'POST', body: data}).then(r => r.json()).then(result => {
            if (result.ok) {
                shortageReports = shortageReports.filter(x => String(x.id) !== btn.dataset.reportId);
                renderShortageReports(shortageReports);
            } else { btn.disabled = false; alert(result.error || t('common.failed')); }
        }).catch(() => { btn.disabled = false; });
    }
    list.querySelectorAll('.shortage-resolve-btn').forEach(btn => btn.addEventListener('click', () => submitShortageOutcome(btn, 'resolve')));
    list.querySelectorAll('.shortage-not-resolved-btn').forEach(btn => btn.addEventListener('click', () => submitShortageOutcome(btn, 'not_resolved')));
}

// Same whole-array-JSON signature technique as the other render*() functions.
let sosAlertsRenderedSig = null;
function renderSosAlerts(items) {
    const sig = JSON.stringify(items);
    if (sig === sosAlertsRenderedSig) return;
    sosAlertsRenderedSig = sig;

    const list = document.getElementById('sosAlertsList');
    if (!list) return;
    if (!items.length) {
        list.innerHTML = '<p class="text-muted mb-0">' + t('sos.empty') + '</p>';
        return;
    }
    // a.team_label arrives ALREADY escaped — loadOpenSosAlertsForMission()
    // (includes/functions.php) wraps it in h() server-side, unlike every
    // other team_label source in this file. Do not add escapeHtml() here:
    // confirmed live that doing so double-escapes to "&amp;lt;" instead of
    // neutralizing the payload, while still being fully inert either way —
    // this is a display-correctness note, not a security one.
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
    if (!fieldMode) { renderPins(pins); renderDispatches(dispatches); renderAnnotations(annotations); renderMedia(media); renderRouteLayer(routes); renderRoutesAdmin(routes); renderTeamDistances(teamDistances); }
    renderMyTasks(myTasks);
    renderMyRoutes(routes);
    renderShortageReports(shortageReports);
    renderSosAlerts(sosAlerts);
    renderNearbyTeams(nearbyTeams);
    if (!fieldMode) updateSosAlarmState(sosAlerts);
    // Anything still queued from a previous visit (closed the tab while
    // offline, reopened later) — the banner reflects it immediately, and a
    // flush is attempted right away rather than waiting for the first 15s tick.
    renderQueueStatus();
    flushQueue();
    saveFieldSnapshot();
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

// Local-only siren mute (this device/tab, this browser session — a page
// reload clears it): for "I'm in a meeting/on a call, I can see the SOS is
// still active on screen, I just need it quiet for a few minutes", not a
// substitute for acknowledging/resolving the real alert. Two deliberate
// safety limits so this can't become a way to accidentally go deaf to SOS
// entirely: (1) it's keyed to the SPECIFIC alert ids active when muted, so
// a NEW, different SOS arriving afterward is never muted by a stale earlier
// mute; (2) it always expires on its own (5 min) even if nobody remembers
// to unmute, so an alert that's still open resumes making noise as a
// reminder rather than staying silent indefinitely.
const SOS_MUTE_DURATION_MS = 5 * 60 * 1000;
let sosMutedAlertIds = new Set();
let sosMuteExpiresAt = 0;
function isSosMuteActive() {
    return sosMuteExpiresAt > Date.now();
}
function muteSosAlerts(alertIds) {
    sosMutedAlertIds = new Set(alertIds);
    sosMuteExpiresAt = Date.now() + SOS_MUTE_DURATION_MS;
    stopSosSiren();
    updateSosAlarmState(sosAlerts);
}
function unmuteSosAlerts() {
    sosMutedAlertIds = new Set();
    sosMuteExpiresAt = 0;
    updateSosAlarmState(sosAlerts);
}
document.getElementById('sosMuteBtn')?.addEventListener('click', () => {
    if (isSosMuteActive()) {
        unmuteSosAlerts();
    } else {
        muteSosAlerts(sosAlerts.filter(a => !a.acknowledged_at).map(a => a.id));
    }
});

// Drives the full-viewport red corner overlay + map marquee from the current
// sosAlerts list. Any unacknowledged alert = pulsing corners; all
// acknowledged-but-unresolved = calm static red; no open alerts = fully off.
// The VISUAL state is never affected by the local mute above — only the
// siren audio itself is; muting must never look like "no SOS is happening."
function updateSosAlarmState(items) {
    const overlay = document.getElementById('sosOverlay');
    if (!overlay) return;
    const unacked = items.filter(a => !a.acknowledged_at);
    const anyUnacked = unacked.length > 0;
    const muteStillValid = isSosMuteActive() && unacked.every(a => sosMutedAlertIds.has(a.id));
    if (!items.length) {
        overlay.classList.remove('sos-active', 'sos-calm');
        stopSosSiren();
        // A fresh SOS later must never inherit today's stale mute state.
        sosMutedAlertIds = new Set();
        sosMuteExpiresAt = 0;
    } else if (anyUnacked) {
        overlay.classList.add('sos-active');
        overlay.classList.remove('sos-calm');
        if (muteStillValid) { stopSosSiren(); } else { playSosSiren(); }
    } else {
        overlay.classList.remove('sos-active');
        overlay.classList.add('sos-calm');
        stopSosSiren();
    }
    const muteBtn = document.getElementById('sosMuteBtn');
    if (muteBtn) {
        if (!anyUnacked) {
            muteBtn.className = 'd-none';
        } else if (muteStillValid) {
            const minutesLeft = Math.max(1, Math.ceil((sosMuteExpiresAt - Date.now()) / 60000));
            muteBtn.className = 'sos-mute-active';
            muteBtn.innerHTML = `<i class="bi bi-volume-mute-fill me-1"></i>${escapeHtml(t('sos.muted_btn', {minutes: minutesLeft}))}`;
        } else {
            muteBtn.className = 'sos-mute-offer';
            muteBtn.innerHTML = `<i class="bi bi-volume-mute me-1"></i>${escapeHtml(t('sos.mute_btn'))}`;
        }
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

// End of Mission / Return to Base — reuses the SOS siren sound engine (via
// playSosSiren/stopSosSiren) and the SOS pulsing-red-corners keyframe, but on
// its own overlay element/timer so it never reads or clobbers real SOS state.
// Only stops the siren afterward if a genuine SOS isn't ALSO currently active.
let returnToBaseTimer = null;
function triggerReturnToBaseAlarm() {
    const overlay = document.getElementById('returnToBaseOverlay');
    if (!overlay) return;
    overlay.classList.add('rtb-active');
    playSosSiren();
    if (returnToBaseTimer) clearTimeout(returnToBaseTimer);
    returnToBaseTimer = setTimeout(() => {
        overlay.classList.remove('rtb-active');
        const sosOverlay = document.getElementById('sosOverlay');
        if (!sosOverlay || !sosOverlay.classList.contains('sos-active')) {
            stopSosSiren();
        }
    }, 12000);
}

function showWarRoomBanner(id, text, orderId, alarmStyle) {
    if (activeBannerRows.has(id)) return;
    playWarRoomAlertSound();
    if (alarmStyle === 'return_to_base') triggerReturnToBaseAlarm();

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

// Keep Phone Awake — Screen Wake Lock API. Available to everyone (not just
// command staff), since field volunteers with the map/status open are the
// main beneficiaries. Hidden entirely on browsers without the API instead of
// showing a button that would just fail silently on click.
(function() {
    const wakeBtn = document.getElementById('wakeLockToggle');
    if (!wakeBtn || !('wakeLock' in navigator)) return;
    wakeBtn.classList.remove('d-none');

    let wakeLock = null;
    let wantsAwake = false;

    function setWakeBtnState(active) {
        wakeBtn.classList.toggle('btn-warning', active);
        wakeBtn.classList.toggle('btn-outline-light', !active);
        wakeBtn.innerHTML = active
            ? '<i class="bi bi-sun-fill me-1"></i>' + t('hero.btn_awake_active')
            : '<i class="bi bi-sun me-1"></i>' + t('hero.btn_keep_awake');
    }

    async function acquireWakeLock() {
        try {
            wakeLock = await navigator.wakeLock.request('screen');
            setWakeBtnState(true);
            wakeLock.addEventListener('release', () => {
                wakeLock = null;
                setWakeBtnState(false);
            });
        } catch (err) {
            wantsAwake = false;
            setWakeBtnState(false);
        }
    }

    wakeBtn.addEventListener('click', async () => {
        if (wakeLock) {
            wantsAwake = false;
            await wakeLock.release();
        } else {
            wantsAwake = true;
            await acquireWakeLock();
        }
    });

    // A wake lock is automatically released whenever the tab is hidden
    // (backgrounded, screen locked) — re-acquire it once the tab is visible
    // again if the user still wants it on, so switching apps briefly doesn't
    // silently turn this back off.
    document.addEventListener('visibilitychange', () => {
        if (wantsAwake && !wakeLock && document.visibilityState === 'visible') {
            acquireWakeLock();
        }
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
    setInterval(() => { if (!document.hidden) loadActivity(); }, 15000);
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
                    <td>${escapeHtml(s.team_label)}</td>
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
                        <strong>${escapeHtml(d.team_label)}</strong> — ${escapeHtml(d.user_name)}
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
                        <strong>${escapeHtml(d.team_label)}</strong> — ${escapeHtml(d.reporter_name)}
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

// Safety net for a real incident: a redirect-to-login response (expired/
// invalidated session) used to fail completely silently here — fetch()
// treats "got redirected then received a 200 HTML login page" as a normal
// successful request, so a bare .catch(() => {}) never fires and nothing
// ever told the user their GPS pings had silently stopped being saved.
// response.redirected (or a non-JSON content-type, belt-and-braces) now
// surfaces a loud, persistent banner instead of failing quietly.
let sessionExpiredWarningShown = false;
function checkSessionAlive(response) {
    const contentType = response.headers.get('content-type') || '';
    if (response.redirected || !contentType.includes('json')) {
        if (!sessionExpiredWarningShown) {
            sessionExpiredWarningShown = true;
            const bar = document.createElement('div');
            bar.style.cssText = 'position:fixed;top:0;left:0;right:0;z-index:99999;background:#dc2626;color:#fff;padding:14px 16px;text-align:center;font-weight:700;box-shadow:0 2px 8px #0006;';
            bar.innerHTML = '<span>' + t('wr.session_expired_warning') + '</span>';
            const reloadBtn = document.createElement('button');
            reloadBtn.type = 'button';
            reloadBtn.className = 'btn btn-light btn-sm ms-3';
            reloadBtn.textContent = t('wr.reload_btn');
            reloadBtn.onclick = () => location.reload();
            bar.appendChild(reloadBtn);
            document.body.prepend(bar);
        }
        return false;
    }
    return true;
}

document.querySelectorAll('.send-ping').forEach(button => button.addEventListener('click', () => {
    const status = document.getElementById('pingStatus-' + button.dataset.prId);
    if (!navigator.geolocation) { status.textContent = t('myping.gps_unsupported'); return; }
    button.disabled = true; status.textContent = t('myping.locating');
    navigator.geolocation.getCurrentPosition(position => {
        const data = new URLSearchParams({csrf_token: csrfToken, shift_id: button.dataset.shiftId, lat: position.coords.latitude, lng: position.coords.longitude, accuracy: position.coords.accuracy || ''});
        fetch('ping-location.php', {method:'POST', body:data}).then(response => {
            if (!checkSessionAlive(response)) { status.textContent = t('myping.ping_send_failed'); status.className = 'small mb-2 text-danger'; return null; }
            return response.json();
        }).then(result => {
            if (!result) return;
            status.textContent = result.ok ? t('myping.ping_sent_prefix', {time: result.ts}) : result.error;
            status.className = 'small mb-2 ' + (result.ok ? 'text-success' : 'text-danger');
        }).catch(() => { status.textContent = t('myping.ping_send_failed'); status.className = 'small mb-2 text-danger'; }).finally(() => button.disabled = false);
    }, () => { status.textContent = t('myping.gps_denied'); status.className = 'small mb-2 text-danger'; button.disabled = false; }, {enableHighAccuracy:true, timeout:10000});
}));

// Passive background capture while this page stays open — silent (no status
// text, doesn't touch the manual button above), tagged source=auto so it's
// excluded from alerts/history/reports and hidden from the trail view unless
// the admin explicitly filters it in. Uses watchPosition() rather than a
// fresh getCurrentPosition() per tick so however often the OS delivers a fix
// doesn't change send frequency — a separate local-only timer below still
// decides when the ~3-minute cadence is actually due, preserving the exact
// send/DB-write volume every existing source='auto' consumer already assumes.
const AUTO_PING_CADENCE_MS = <?= (int) getSetting('war_room_auto_ping_seconds', '180') * 1000 ?>;
let latestAutoPosition = null;
let lastAutoPingSentAt = Date.now();

function sendAutoPing(position) {
    const buttons = document.querySelectorAll('.send-ping');
    if (!buttons.length) return;
    lastAutoPingSentAt = Date.now();
    buttons.forEach(button => {
        const data = new URLSearchParams({csrf_token: csrfToken, shift_id: button.dataset.shiftId, lat: position.coords.latitude, lng: position.coords.longitude, accuracy: position.coords.accuracy || '', source: 'auto'});
        fetch('ping-location.php', {method: 'POST', body: data}).then(checkSessionAlive).catch(() => {});
    });
}

// enableHighAccuracy is deliberately false here (unlike the manual button
// above) — a live ops-map pin doesn't need meter-level precision, and pairing
// continuous high-accuracy GPS with Field Mode's always-on screen (below)
// over a multi-hour mission is a real battery cost not worth paying twice.
// Delayed a few seconds so the location-permission prompt doesn't fire the
// instant the page renders, before anyone's read anything on it.
//
// getCurrentPosition() alongside watchPosition() (not just watchPosition
// alone): watchPosition's very first fix can legitimately take anywhere from
// seconds to several minutes on a real device depending on signal/GPS lock —
// confirmed live, cadence set to 60s but first auto-ping took 3-8 minutes
// across repeated tries, varying each time, consistent with first-fix
// latency rather than a timer bug. Firing an explicit getCurrentPosition()
// at the same time races both and seeds latestAutoPosition with whichever
// resolves first, so the interval below always has something to send once
// the configured cadence elapses instead of sitting blocked on
// !latestAutoPosition for however long that first fix happens to take.
setTimeout(() => {
    if (!navigator.geolocation || !document.querySelectorAll('.send-ping').length) return;
    navigator.geolocation.getCurrentPosition(
        position => { latestAutoPosition = position; },
        () => {},
        {enableHighAccuracy: false, maximumAge: 60000, timeout: 20000}
    );
    navigator.geolocation.watchPosition(
        position => { latestAutoPosition = position; },
        () => {},
        {enableHighAccuracy: false, maximumAge: 60000, timeout: 20000}
    );
}, 5000);

// Local-only check, no GPS/network call of its own — just decides whether the
// cadence window has elapsed and, if so, sends whatever watchPosition most
// recently handed us. Ticks far more often than the cadence itself (15s vs
// 3min) so send timing stays accurate without a one-shot GPS read per send.
setInterval(() => {
    if (!latestAutoPosition) return;
    if (Date.now() - lastAutoPingSentAt < AUTO_PING_CADENCE_MS) return;
    sendAutoPing(latestAutoPosition);
}, 15000);

// Catch-up: if the tab was backgrounded/suspended through a whole cadence
// window, don't wait for the next scheduled tick once it's visible again — a
// long-cached fix would show a stale location, so this takes one fresh read
// rather than reusing latestAutoPosition. Gates on the same lastAutoPingSentAt
// the tick above uses, so whichever fires first closes the window for the
// other with no separate bookkeeping and no double-send risk.
document.addEventListener('visibilitychange', () => {
    if (document.visibilityState !== 'visible') return;
    if (!navigator.geolocation || !document.querySelectorAll('.send-ping').length) return;
    if (Date.now() - lastAutoPingSentAt < AUTO_PING_CADENCE_MS) return;
    navigator.geolocation.getCurrentPosition(
        position => { latestAutoPosition = position; sendAutoPing(position); },
        () => {},
        {enableHighAccuracy: false, timeout: 10000}
    );
});

const FIELD_STATUS_LABEL_KEYS = {on_way: 'status.self_on_way', on_site: 'status.self_on_site', needs_help: 'status.self_sos'};

// Same contract as postRouteAction(): resolves to the server's JSON, to
// {networkError:true} when the server is unreachable, or to null when
// checkSessionAlive() spotted a redirect to login. The queue below relies on
// being able to tell those three apart.
function postFieldStatus(prId, status, extra) {
    const params = Object.assign({csrf_token: csrfToken, pr_id: prId, status: status}, extra || {});
    return fetch('volunteer-status.php', {method: 'POST', body: new URLSearchParams(params)}).then(response => {
        if (!checkSessionAlive(response)) return null;
        return response.json();
    }).catch(() => ({ok: false, error: t('common.network_error'), networkError: true}));
}

// Shows the truth while a status tap waits in the queue: NOT "you are now
// on site", but "this hasn't reached command yet". Overwritten by the real
// label as soon as the replay succeeds and the next poll re-renders.
function markFieldStatusQueued(prId, status) {
    const badge = document.getElementById('statusBadge-' + prId);
    if (!badge) return;
    const label = t(FIELD_STATUS_LABEL_KEYS[status] || 'status.badge_none');
    badge.innerHTML = `<span class="text-danger fw-semibold"><i class="bi bi-clock-history me-1"></i>${escapeHtml(t('status.queued_pending', {status: label}))}</span>`;
}

function setFieldStatus(btn, prId, status) {
    const group = document.getElementById('statusBtns-' + prId);
    if (group) group.querySelectorAll('button').forEach(b => b.disabled = true);

    const send = (lat, lng) => {
        const extra = {reported_at: new Date().toISOString()};
        if (lat !== null) { extra.lat = lat; extra.lng = lng; }
        postFieldStatus(prId, status, extra).then(result => {
            // No signal, or a session that expired mid-mission. This used to
            // do nothing at all beyond re-enabling the buttons — the SOS
            // button in particular looked like it had worked while nothing
            // had been sent and nothing was ever going to be. Queue it, say
            // so, and (for an SOS) say so loudly: the volunteer has to know
            // help has not actually been called yet.
            if (!result || result.networkError) {
                enqueueAction({kind: 'status', prId: String(prId), status: status, extra: extra});
                markFieldStatusQueued(prId, status);
                if (group) group.querySelectorAll('button').forEach(b => b.disabled = false);
                if (status === 'needs_help') alert(t('status.sos_queued_alert'));
                return;
            }
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

// ── Live-data staleness ─────────────────────────────────────────────────────
// Everything on this page is polled. When the poll stops succeeding, every
// number and pin on screen silently becomes a historical record that still
// looks live. Four consecutive misses (~20s) is past any normal jitter, so
// past that the page says out loud how old what you're looking at actually is.
let lastPollOkAt = Date.now();
const POLL_STALE_MS = 20000;

function renderPollStaleness() {
    const el = document.getElementById('pollStaleBanner');
    if (!el) return;
    const ageMs = Date.now() - lastPollOkAt;
    if (ageMs < POLL_STALE_MS) {
        el.classList.add('d-none');
        el.innerHTML = '';
        return;
    }
    const mins = Math.floor(ageMs / 60000);
    const age = mins >= 1 ? t('poll.stale_minutes', {n: mins}) : t('poll.stale_seconds', {n: Math.round(ageMs / 1000)});
    // Escalates once it's been long enough that the picture could have
    // changed materially — a 25-second gap is a hiccup, three minutes is not.
    const severe = ageMs > 180000;
    el.className = 'alert d-flex align-items-center gap-2 py-2 px-3 mb-3 ' + (severe ? 'alert-danger' : 'alert-warning');
    el.innerHTML = `<i class="bi bi-exclamation-triangle-fill"></i><div><strong>${escapeHtml(t('poll.stale_title', {age}))}</strong>`
        + `<div class="small">${escapeHtml(t('poll.stale_help'))}</div></div>`;
}
// Its own timer, not just the poll's: when the poll is failing its .then()
// never runs, so without this the "4 minutes ago" would freeze at whatever it
// said when the connection first died.
setInterval(renderPollStaleness, 5000);

// Named (not the previous inline arrow passed straight to setInterval) so
// the Page Visibility handling below can also call it directly, once, the
// moment this tab becomes visible again — rather than leaving the user
// looking at a map/route/SOS list that's been silently frozen for however
// long the tab was hidden until the next scheduled tick happens to land.
function pollWarRoomData() {
    fetch('war-room.php?id=<?= $missionId ?>&ajax=1&banner_after=' + bannerAfterId).then(response => {
        if (!checkSessionAlive(response)) return null;
        return response.json();
    }).then(data => {
        if (!data) return;
        lastPollOkAt = Date.now();
        renderPollStaleness();
        if (!fieldMode) {
            renderPins(pins = data.pins || []);
            if (data.dispatches) renderDispatches(dispatches = data.dispatches);
            if (data.annotations) renderAnnotations(annotations = data.annotations);
            // Keeps the route composer's reference layers live while it's
            // open, not just at the moment it was opened — both are no-ops
            // until the composer has been opened at least once this session
            // (the layers stay null until then).
            renderRouteComposerPins(pins);
            renderRouteComposerAnnotations(annotations);
            if (data.media) {
                const sig = JSON.stringify(data.media);
                if (sig !== mediaSignature) {
                    mediaSignature = sig;
                    renderMedia(media = data.media);
                }
            }
        }
        if (data.myTasks) renderMyTasks(myTasks = data.myTasks);
        if (data.routes) {
            routes = data.routes;
            renderMyRoutes(routes);
            if (!fieldMode) { renderRoutesAdmin(routes); renderRouteLayer(routes); }
        }
        if (data.shortageReports) renderShortageReports(shortageReports = data.shortageReports);
        if (data.sosAlerts) {
            renderSosAlerts(sosAlerts = data.sosAlerts);
            if (!fieldMode) updateSosAlarmState(sosAlerts);
        }
        if (data.onlinePresence) renderPresence(data.onlinePresence);
        if (data.pingStaleness) renderPingStaleness(data.pingStaleness);
        if (data.nearbyTeams) renderNearbyTeams(nearbyTeams = data.nearbyTeams);
        if (!fieldMode && data.teamDistances) renderTeamDistances(teamDistances = data.teamDistances);
        if (!fieldMode) document.getElementById('mapRefresh').textContent = data.time || '';
        if (data.banners && data.banners.length) {
            data.banners.forEach(b => {
                if (b.id > bannerAfterId) bannerAfterId = b.id;
                showWarRoomBanner(b.id, b.message, b.orderId, b.alarmStyle);
            });
        }
        // Keep the offline fallback's copy of the route/orders current. Cheap:
        // saveFieldSnapshot() compares a signature first and only writes when
        // something actually changed, so a quiet mission doesn't rewrite
        // localStorage every 5 seconds.
        saveFieldSnapshot();
    }).catch(() => { renderPollStaleness(); });
}
setInterval(() => { if (!document.hidden) pollWarRoomData(); }, 5000);

// A tab sitting in a background window/inactive phone screen still fires
// these 5s timers at full rate today — harmless for one open tab, but this
// app is routinely run with several tabs open at once (multiple admins,
// or one admin's laptop+phone both open), and every hidden one was polling
// exactly as often as the one actually being watched, for zero benefit.
// Skipping the fetch while hidden (checked above, and in pollRoom/
// loadActivity below) cuts that idle load; catching up immediately on
// return (rather than waiting up to 5s for the next tick) keeps "switch
// back to this tab" from ever showing stale data on the way back in.
document.addEventListener('visibilitychange', () => {
    if (document.hidden) return;
    pollWarRoomData();
    // Chat's own pollRoom() lives inside its IIFE further down (not in scope
    // here) and handles its own visibilitychange listener there instead.
    if (!fieldMode && typeof loadActivity === 'function') loadActivity();
});

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
    setInterval(() => { if (!document.hidden) pollRoom(); }, 5000);
    // Scoped here (not the outer visibilitychange listener further up) since
    // pollRoom only exists inside this closure.
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) pollRoom();
    });
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
            const tooltip = item.label ? escapeHtml(item.label) : escapeHtml(item.team_label);
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

// ── Route Order map layer (main live map) ───────────────────────────────────
function routeWaypointColor(wp, isCurrent) {
    if (wp.skipped_at) return '#dc3545';
    if (wp.completed_at) return '#198754';
    if (wp.arrived_at) return '#0d6efd';
    if (wp.departed_at) return '#f59e0b';
    return isCurrent ? '#6c757d' : '#adb5bd';
}

function renderRouteLayer(allRoutes) {
    if (!routeLayer) return;
    routeLayer.clearLayers();
    (allRoutes || []).filter(r => r.status !== 'cancelled').forEach(route => {
        if (!route.waypoints.length) return;
        const currentSeq = (route.waypoints.find(w => !w.completed_at && !w.skipped_at) || {}).seq;
        route.waypoints.forEach(wp => {
            const color = routeWaypointColor(wp, wp.seq === currentSeq);
            const icon = L.divIcon({
                className: '',
                html: `<div style="background:${color};color:#fff;width:24px;height:24px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:12px;border:2px solid #fff;box-shadow:0 1px 4px #0008;">${wp.seq}</div>`,
                iconSize: [24, 24], iconAnchor: [12, 12],
            });
            const statusText = wp.skipped_at ? t('route.skipped_prefix')
                : wp.completed_at ? t('route.completed_at_prefix', {time: wp.completed_at_display})
                : wp.arrived_at ? t('route.onsite_since_prefix', {time: wp.arrived_at_display})
                : wp.departed_at ? t('route.enroute_since_prefix', {time: wp.departed_at_display})
                : '';
            const label = wp.label ? escapeHtml(wp.label) : t('route.waypoint_fallback_label', {seq: wp.seq});
            const popupHtml = `<strong>${escapeHtml(route.team_label || '')} — ${wp.seq}. ${label}</strong>` +
                (wp.instructions ? `<br><span class="small">${escapeHtml(wp.instructions)}</span>` : '') +
                (statusText ? `<br><span class="small text-muted">${statusText}</span>` : '');
            L.marker([wp.lat, wp.lng], {icon}).addTo(routeLayer).bindPopup(popupHtml);
        });
        const coords = route.waypoints.map(w => [w.lat, w.lng]);
        if (route.is_closed_loop && coords.length >= 3) {
            // Matches what the admin saw while composing: L.polygon draws the
            // implicit last→first segment, fillOpacity:0 keeps it a closed
            // path rather than a shaded zone.
            L.polygon(coords, {color: route.team_color_bg || '#0d6efd', weight: 3, opacity: 0.7, dashArray: '6,6', fillOpacity: 0}).addTo(routeLayer);
        } else if (coords.length >= 2) {
            L.polyline(coords, {color: route.team_color_bg || '#0d6efd', weight: 3, opacity: 0.7, dashArray: '6,6'}).addTo(routeLayer);
        }
    });
}

// ── Route Order admin sidebar list (every team's routes, cancel/skip) ───────
// Which routes are expanded in the admin panel — module-level so the 5s poll's
// renderRoutesAdmin() re-render doesn't collapse a route the admin just opened
// (same class of "poll wipes UI state" bug fixed elsewhere in this file for the
// shortage-report textarea; sidestepped here at the root instead of a
// signature-skip guard, since collapse/expand state has to survive anyway).
let expandedRouteIds = new Set();

function routeAdminWaypointStatusHtml(wp) {
    const distanceHtml = wp.arrived_at ? routeDistanceBadgeHtml(wp) : '';
    const distanceSuffix = distanceHtml ? ` · ${distanceHtml}` : '';
    if (wp.skipped_at) return `<span class="text-warning">${t('route.skipped_prefix')}</span>`;
    if (wp.completed_at) return `<span class="text-success">${t('route.completed_at_prefix', {time: wp.completed_at_display})}</span>${distanceSuffix}`;
    if (wp.arrived_at) {
        // Passive visibility for command staff — no sound here (that's the
        // field volunteer's own card, see updateRouteCountdowns()), just
        // flagging that this team has been sitting past the planned time.
        const isOverdue = wp.dwell_minutes !== null && (Date.parse(wp.arrived_at) + wp.dwell_minutes * 60000) < Date.now();
        const onsiteHtml = `<span class="${isOverdue ? 'text-danger' : 'text-primary'}">${t('route.onsite_since_prefix', {time: wp.arrived_at_display})}${isOverdue ? ' ⏰' : ''}</span>`;
        return onsiteHtml + distanceSuffix;
    }
    if (wp.departed_at) return `<span class="text-warning">${t('route.enroute_since_prefix', {time: wp.departed_at_display})}</span>`;
    return `<span class="text-muted">${t('route.status_pending')}</span>`;
}

function renderRouteAdminWaypointsList(route) {
    const rows = route.waypoints.map(wp => {
        const label = wp.label ? escapeHtml(wp.label) : t('route.waypoint_fallback_label', {seq: wp.seq});
        const isOpen = route.status === 'active' && !wp.completed_at && !wp.skipped_at;
        const mediaHtml = (wp.photo || wp.video)
            ? `<div class="d-flex gap-2 mt-1">
                ${wp.photo ? `<img src="mission-photo-view.php?id=${wp.photo.id}" class="route-media-view" data-id="${wp.photo.id}" data-media-type="photo" style="max-height:50px;border-radius:4px;cursor:pointer;">` : ''}
                ${wp.video ? `<video src="mission-photo-view.php?id=${wp.video.id}" class="route-media-view" data-id="${wp.video.id}" data-media-type="video" style="max-height:50px;border-radius:4px;cursor:pointer;" muted></video>` : ''}
              </div>`
            : '';
        const noteHtml = wp.note ? `<div class="small fst-italic mt-1">"${escapeHtml(wp.note)}"</div>` : '';
        return `<div class="py-1 border-bottom">
            <div class="d-flex justify-content-between align-items-center">
                <div class="small">${wp.seq}. ${label}${wp.out_of_sequence ? ' ⚠️' : ''}<br>${routeAdminWaypointStatusHtml(wp)}</div>
                <div class="d-flex gap-1">
                    ${route.status === 'active' ? `<button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1 route-edit-btn" data-id="${wp.id}" title="${t('common.edit')}"><i class="bi bi-pencil"></i></button>` : ''}
                    ${isOpen ? `<button type="button" class="btn btn-sm btn-outline-warning py-0 px-1 route-skip-btn" data-id="${wp.id}" title="${t('route.skip_btn')}"><i class="bi bi-skip-forward-fill"></i></button>` : ''}
                </div>
            </div>
            ${noteHtml}
            ${mediaHtml}
        </div>`;
    }).join('');
    const footerHtml = route.status === 'active'
        ? `<div class="d-flex gap-1 mt-2 flex-wrap">
            <button type="button" class="btn btn-sm btn-outline-danger py-0 route-cancel-btn" data-id="${route.id}">${t('route.cancel_btn')}</button>
            <button type="button" class="btn btn-sm btn-outline-secondary py-0 route-zoom-btn" data-id="${route.id}"><i class="bi bi-geo-alt me-1"></i>${t('route.zoom_btn')}</button>
          </div>`
        : `<button type="button" class="btn btn-sm btn-outline-secondary py-0 mt-2 route-zoom-btn" data-id="${route.id}"><i class="bi bi-geo-alt me-1"></i>${t('route.zoom_btn')}</button>`;
    return rows + footerHtml;
}

// Same whole-array-JSON signature technique as the other render*() functions
// above — but this card also depends on expandedRouteIds (which route cards
// are expanded), a purely client-side Set that isn't part of allRoutes at
// all. That MUST be folded into the signature: the expand/collapse toggle
// handler calls this function again with the exact same allRoutes reference
// it was already given, so a signature built from allRoutes alone would
// match on every toggle and silently break expand/collapse entirely.
let routesAdminRenderedSig = null;
function renderRoutesAdmin(allRoutes) {
    const sig = JSON.stringify(allRoutes) + '|' + [...expandedRouteIds].sort().join(',');
    if (sig === routesAdminRenderedSig) return;
    routesAdminRenderedSig = sig;

    const list = document.getElementById('routesAdminList');
    if (!list) return;
    if (!allRoutes.length) {
        list.innerHTML = '<p class="text-muted mb-0">' + t('route.admin_empty') + '</p>';
        return;
    }
    list.innerHTML = allRoutes.map(route => {
        const done = route.waypoints.filter(w => w.completed_at || w.skipped_at).length;
        const statusBadge = route.status === 'cancelled'
            ? `<span class="badge bg-secondary">${t('route.status_cancelled')}</span>`
            : route.status === 'completed'
                ? `<span class="badge bg-success">${t('route.status_completed')}</span>`
                : `<span class="badge" style="background:${route.team_color_bg};color:${route.team_color_fg};">${done}/${route.waypoints.length}</span>`;
        const isExpanded = expandedRouteIds.has(route.id);
        // A route's members are only a proper subset of its nominal team
        // some of the time (e.g. 2 of 4 sent on a temporary task) — flag it
        // so command staff doesn't assume the whole team is out.
        const fullTeam = missionTeamsForRoute.find(t => t.id === route.team_id);
        const isSubset = fullTeam && route.members && route.members.length < fullTeam.members.length;
        const membersSuffix = isSubset
            ? ` <span class="small text-muted">(${route.members.map(m => escapeHtml(m.name)).join(', ')})</span>`
            : '';
        // No single nominal team (cross-team route, migration v110) — the
        // badge already shows the members' names (team_label falls back to
        // them server-side) in the neutral color teamBadgeColors(null)
        // picks; a people icon just makes "this isn't a real team" legible
        // at a glance alongside a real colored team badge.
        const teamBadgeIcon = route.team_id === null ? '<i class="bi bi-people-fill me-1"></i>' : '';
        return `<div class="border rounded mb-2">
            <div class="p-2 d-flex justify-content-between align-items-center route-admin-toggle" data-id="${route.id}" style="cursor:pointer;">
                <div>
                    <span class="badge me-1" style="background:${route.team_color_bg};color:${route.team_color_fg};">${teamBadgeIcon}${escapeHtml(route.team_label || '')}</span>
                    <strong class="small">${route.title ? escapeHtml(route.title) : t('route.default_title')}</strong>${membersSuffix}
                </div>
                <div class="d-flex align-items-center gap-2">
                    ${statusBadge}
                    <i class="bi bi-chevron-${isExpanded ? 'up' : 'down'}"></i>
                </div>
            </div>
            ${isExpanded ? `<div class="border-top p-2">${renderRouteAdminWaypointsList(route)}</div>` : ''}
        </div>`;
    }).join('');

    list.querySelectorAll('.route-admin-toggle').forEach(el => el.addEventListener('click', () => {
        const id = +el.dataset.id;
        if (expandedRouteIds.has(id)) expandedRouteIds.delete(id); else expandedRouteIds.add(id);
        renderRoutesAdmin(allRoutes);
    }));
    list.querySelectorAll('.route-cancel-btn').forEach(btn => btn.addEventListener('click', e => {
        e.stopPropagation();
        if (!confirm(t('route.cancel_confirm'))) return;
        btn.disabled = true;
        postRouteAction('cancel', btn.dataset.id, {}).then(result => { if (result && !result.ok) { alert(result.error || t('common.failed')); btn.disabled = false; } });
    }));
    list.querySelectorAll('.route-skip-btn').forEach(btn => btn.addEventListener('click', e => {
        e.stopPropagation();
        if (!confirm(t('route.skip_confirm'))) return;
        btn.disabled = true;
        postRouteAction('skip', btn.dataset.id, {}).then(result => { if (result && !result.ok) { alert(result.error || t('common.failed')); btn.disabled = false; } });
    }));
    list.querySelectorAll('.route-zoom-btn').forEach(btn => btn.addEventListener('click', e => {
        e.stopPropagation();
        const route = allRoutes.find(r => String(r.id) === btn.dataset.id);
        if (route && route.waypoints.length && map) {
            map.fitBounds(L.latLngBounds(route.waypoints.map(w => [w.lat, w.lng])), {padding: [40, 40]});
        }
    }));
    list.querySelectorAll('.route-edit-btn').forEach(btn => btn.addEventListener('click', e => {
        e.stopPropagation();
        const waypoint = allRoutes.flatMap(r => r.waypoints).find(w => String(w.id) === btn.dataset.id);
        if (waypoint) openWaypointEditModal(waypoint);
    }));
    list.querySelectorAll('.route-media-view').forEach(el => el.addEventListener('click', e => {
        e.stopPropagation();
        openMediaViewModal(el.dataset.id, el.dataset.mediaType);
    }));
}

// ── Edit an existing (already-sent) waypoint's label/instructions/dwell/
// deliverables. A separate modal (not inline in #routesAdminList) on purpose:
// that list's innerHTML gets fully rebuilt by renderRoutesAdmin() on every 5s
// poll tick, which would otherwise wipe out whatever the admin is mid-typing —
// living outside it sidesteps that instead of needing a signature-skip guard.
let routeEditWaypointId = null;

function openWaypointEditModal(waypoint) {
    routeEditWaypointId = waypoint.id;
    document.getElementById('routeEditLabel').value = waypoint.label || '';
    document.getElementById('routeEditInstructions').value = waypoint.instructions || '';
    document.getElementById('routeEditDwell').value = waypoint.dwell_minutes ?? '';
    document.getElementById('routeEditPhoto').checked = !!waypoint.require_photo;
    document.getElementById('routeEditVideo').checked = !!waypoint.require_video;
    document.getElementById('routeEditNote').checked = !!waypoint.require_note;
    document.getElementById('routeEditError').classList.add('d-none');
    bootstrap.Modal.getOrCreateInstance(document.getElementById('routeWaypointEditModal')).show();
}

(function() {
    const modalEl = document.getElementById('routeWaypointEditModal');
    if (!modalEl) return;
    const saveBtn = document.getElementById('routeEditSaveBtn');
    const errorEl = document.getElementById('routeEditError');

    saveBtn.addEventListener('click', () => {
        if (!routeEditWaypointId) return;
        const extra = {
            label: document.getElementById('routeEditLabel').value.trim(),
            instructions: document.getElementById('routeEditInstructions').value.trim(),
            dwell_minutes: document.getElementById('routeEditDwell').value,
            require_photo: document.getElementById('routeEditPhoto').checked ? '1' : '0',
            require_video: document.getElementById('routeEditVideo').checked ? '1' : '0',
            require_note: document.getElementById('routeEditNote').checked ? '1' : '0',
        };
        saveBtn.disabled = true;
        postRouteAction('edit_waypoint', routeEditWaypointId, extra).then(result => {
            saveBtn.disabled = false;
            if (result && result.ok) {
                bootstrap.Modal.getInstance(modalEl).hide();
            } else {
                errorEl.textContent = (result && result.error) || t('common.failed');
                errorEl.classList.remove('d-none');
            }
        });
    });
})();

// ── Route Order composer modal (admin: build a numbered, ordered patrol) ────
let routeWaypoints = [];
let routeClosed = false; // click near point 1 (see routeMap's click handler) sets this — same gesture as the dispatch polygon tool
let routeMap = null, routeMarkers = [], routeLine = null;
// Read-only reference layers — live pins + existing battle-map annotations,
// so an admin plotting a route can see where teams currently are and what's
// already been marked, without leaving the composer. Created once alongside
// routeMap itself (shown.bs.modal below); the composer never lets you draw
// a *new* annotation from here, only see the ones already on the live map.
let routeComposerPinLayer = null, routeComposerAnnotationLayer = null;
let routeComposerPinsRenderedSig = null;

function updateRouteSendState() {
    const sendBtn = document.getElementById('routeSendBtn');
    if (sendBtn) sendBtn.disabled = routeWaypoints.length < 1;
}

function renderRouteComposerMap() {
    if (!routeMap) return;
    routeMarkers.forEach(m => routeMap.removeLayer(m));
    routeMarkers = [];
    if (routeLine) { routeMap.removeLayer(routeLine); routeLine = null; }
    routeWaypoints.forEach((wp, i) => {
        const icon = L.divIcon({
            className: '',
            html: `<div style="background:#0d6efd;color:#fff;width:26px;height:26px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;border:2px solid #fff;box-shadow:0 1px 4px #0008;">${i + 1}</div>`,
            iconSize: [26, 26], iconAnchor: [13, 13],
        });
        const marker = L.marker([wp.lat, wp.lng], {icon, draggable: true}).addTo(routeMap);
        marker.on('dragend', ev => {
            const pos = ev.target.getLatLng();
            routeWaypoints[i].lat = pos.lat;
            routeWaypoints[i].lng = pos.lng;
        });
        routeMarkers.push(marker);
    });
    if (routeClosed && routeWaypoints.length >= 3) {
        // Closed loop: same technique as mission-dispatch.php's polygon preview
        // (L.polygon draws the implicit last→first segment for free), but
        // fillOpacity:0 so it reads as a closed path, not a shaded area/zone.
        routeLine = L.polygon(routeWaypoints.map(wp => [wp.lat, wp.lng]), {color: '#0d6efd', weight: 3, dashArray: '6,6', fillOpacity: 0}).addTo(routeMap);
    } else if (routeWaypoints.length >= 2) {
        routeLine = L.polyline(routeWaypoints.map(wp => [wp.lat, wp.lng]), {color: '#0d6efd', weight: 3, dashArray: '6,6'}).addTo(routeMap);
    }
}

function resetRouteComposer() {
    routeWaypoints = [];
    routeClosed = false;
    renderRouteComposerMap();
    renderWaypointPanel();
    updateRouteSendState();
}

// Same signature-skip technique renderPins() uses (v3.130 audit) — cheap
// insurance against rebuilding markers on every 5s poll tick when nothing
// about the underlying pins actually changed.
function renderRouteComposerPins(items) {
    if (!routeComposerPinLayer) return;
    const sig = JSON.stringify(items);
    if (sig === routeComposerPinsRenderedSig) return;
    routeComposerPinsRenderedSig = sig;
    routeComposerPinLayer.clearLayers();
    items.forEach(pin => buildPinMarker(pin).addTo(routeComposerPinLayer));
}

// Mirrors renderAnnotations()'s 3 shape types exactly (same ANNOTATION_COLOR,
// same divIcon markup/classes) but deliberately has no click-to-erase handler
// and no annotationPane — this view is read-only reference info, not a second
// place to manage annotations, and routeMap has no draw-mode/activeTool
// concept for a custom pane to protect against. No signature-guard, matching
// renderAnnotations() itself (which doesn't have one either).
function renderRouteComposerAnnotations(items) {
    if (!routeComposerAnnotationLayer) return;
    routeComposerAnnotationLayer.clearLayers();
    items.forEach(item => {
        if (item.type === 'freehand') {
            L.polyline(item.geo, {color: ANNOTATION_COLOR, weight: 4}).addTo(routeComposerAnnotationLayer);
        } else if (item.type === 'arrow') {
            const [p1, p2] = item.geo;
            L.polyline(item.geo, {color: ANNOTATION_COLOR, weight: 3}).addTo(routeComposerAnnotationLayer);
            const brng = bearing(L.latLng(p1[0], p1[1]), L.latLng(p2[0], p2[1]));
            const headIcon = L.divIcon({className:'', html:`<div class="wr-anno-arrowhead" style="transform:rotate(${brng}deg);border-bottom-color:${ANNOTATION_COLOR}"></div>`, iconSize:[16,16], iconAnchor:[8,8]});
            L.marker(p2, {icon: headIcon}).addTo(routeComposerAnnotationLayer);
        } else if (item.type === 'text') {
            const icon = L.divIcon({className:'', html:`<span class="wr-anno-text-label" style="background:${ANNOTATION_COLOR}">${escapeHtml(item.label)}</span>`, iconAnchor:[0, 12]});
            L.marker([item.geo.lat, item.geo.lng], {icon}).addTo(routeComposerAnnotationLayer);
        }
    });
}

function renderWaypointPanel() {
    const panel = document.getElementById('routeWaypointPanel');
    if (!panel) return;
    if (!routeWaypoints.length) {
        panel.innerHTML = `<p class="text-muted small">${t('route.panel_empty')}</p>`;
        return;
    }
    const closedNoticeHtml = routeClosed
        ? `<div class="alert alert-primary py-1 px-2 small mb-2"><i class="bi bi-arrow-repeat me-1"></i>${t('route.closed_loop_note')}</div>`
        : '';
    panel.innerHTML = closedNoticeHtml + routeWaypoints.map((wp, i) => `
        <div class="border rounded p-2 mb-2">
            <div class="d-flex justify-content-between align-items-center mb-1">
                <strong class="small">${t('route.waypoint_fallback_label', {seq: i + 1})}</strong>
                <div class="d-flex gap-1">
                    <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1 route-wp-up" data-i="${i}" ${i === 0 ? 'disabled' : ''} title="${t('route.move_up_title')}"><i class="bi bi-arrow-up"></i></button>
                    <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1 route-wp-down" data-i="${i}" ${i === routeWaypoints.length - 1 ? 'disabled' : ''} title="${t('route.move_down_title')}"><i class="bi bi-arrow-down"></i></button>
                    <button type="button" class="btn btn-sm btn-outline-danger py-0 px-1 route-wp-remove" data-i="${i}" title="${t('common.delete')}"><i class="bi bi-trash"></i></button>
                </div>
            </div>
            <input type="text" class="form-control form-control-sm mb-1 route-wp-label" data-i="${i}" maxlength="255" placeholder="${escapeHtml(t('route.label_placeholder'))}" value="${escapeHtml(wp.label)}">
            <textarea class="form-control form-control-sm mb-1 route-wp-instructions" data-i="${i}" rows="2" maxlength="2000" placeholder="${escapeHtml(t('route.instructions_placeholder'))}">${escapeHtml(wp.instructions)}</textarea>
            <div class="d-flex align-items-center gap-2 mb-1">
                <label class="small text-muted mb-0">${t('route.dwell_label')}</label>
                <input type="number" class="form-control form-control-sm route-wp-dwell" data-i="${i}" min="0" max="600" style="width:80px;" value="${wp.dwell_minutes ?? ''}" placeholder="${t('route.dwell_unlimited_placeholder')}">
            </div>
            <div class="d-flex gap-3 small">
                <div class="form-check">
                    <input class="form-check-input route-wp-flag" type="checkbox" data-i="${i}" data-flag="require_photo" id="wpPhoto${i}" ${wp.require_photo ? 'checked' : ''}>
                    <label class="form-check-label" for="wpPhoto${i}">${t('route.photo_btn')}</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input route-wp-flag" type="checkbox" data-i="${i}" data-flag="require_video" id="wpVideo${i}" ${wp.require_video ? 'checked' : ''}>
                    <label class="form-check-label" for="wpVideo${i}">${t('route.video_btn')}</label>
                </div>
                <div class="form-check">
                    <input class="form-check-input route-wp-flag" type="checkbox" data-i="${i}" data-flag="require_note" id="wpNote${i}" ${wp.require_note ? 'checked' : ''}>
                    <label class="form-check-label" for="wpNote${i}">${t('route.note_flag_label')}</label>
                </div>
            </div>
        </div>
    `).join('');

    panel.querySelectorAll('.route-wp-label').forEach(el => el.addEventListener('input', () => { routeWaypoints[+el.dataset.i].label = el.value; }));
    panel.querySelectorAll('.route-wp-instructions').forEach(el => el.addEventListener('input', () => { routeWaypoints[+el.dataset.i].instructions = el.value; }));
    panel.querySelectorAll('.route-wp-dwell').forEach(el => el.addEventListener('input', () => { routeWaypoints[+el.dataset.i].dwell_minutes = el.value === '' ? null : Math.max(0, parseInt(el.value, 10) || 0); }));
    panel.querySelectorAll('.route-wp-flag').forEach(el => el.addEventListener('change', () => { routeWaypoints[+el.dataset.i][el.dataset.flag] = el.checked; }));
    panel.querySelectorAll('.route-wp-remove').forEach(btn => btn.addEventListener('click', () => {
        routeWaypoints.splice(+btn.dataset.i, 1);
        // A closed loop needs at least 3 points to mean anything (mirrors the
        // dispatch polygon tool's own >=3 gate) — dropping below that reopens
        // it automatically rather than leaving a "closed" 2-point line.
        if (routeWaypoints.length < 3) routeClosed = false;
        renderWaypointPanel();
        renderRouteComposerMap();
        updateRouteSendState();
    }));
    // Up/down buttons rather than drag-and-drop: identical outcome (reorder
    // the sequence), but works the same on a touch screen as a mouse — no
    // HTML5 drag-and-drop polyfill needed for mobile, which native DnD
    // doesn't support at all without one.
    panel.querySelectorAll('.route-wp-up').forEach(btn => btn.addEventListener('click', () => {
        const i = +btn.dataset.i;
        if (i > 0) {
            [routeWaypoints[i - 1], routeWaypoints[i]] = [routeWaypoints[i], routeWaypoints[i - 1]];
            renderWaypointPanel();
            renderRouteComposerMap();
        }
    }));
    panel.querySelectorAll('.route-wp-down').forEach(btn => btn.addEventListener('click', () => {
        const i = +btn.dataset.i;
        if (i < routeWaypoints.length - 1) {
            [routeWaypoints[i], routeWaypoints[i + 1]] = [routeWaypoints[i + 1], routeWaypoints[i]];
            renderWaypointPanel();
            renderRouteComposerMap();
        }
    }));
}

(function() {
    const modalEl = document.getElementById('routeComposerModal');
    if (!modalEl) return;

    const titleInput = document.getElementById('routeTitleInput');
    const addressInput = document.getElementById('routeAddressInput');
    const addressSearchBtn = document.getElementById('routeAddressSearch');
    const addressStatus = document.getElementById('routeAddressStatus');
    const clearBtn = document.getElementById('routeClearBtn');
    const sendBtn = document.getElementById('routeSendBtn');
    const teamSelect = document.getElementById('routeTeamSelect');
    const memberPicker = document.getElementById('routeMemberPicker');

    // Which of the selected team's members this route actually applies to —
    // defaults to everyone (old whole-team behavior unless the admin
    // unchecks someone), see includes/migrations.php v109.
    function renderRouteMemberPicker() {
        // No single team position to capture in cross-team mode — hide the
        // return-to-start option entirely rather than show a checkbox that
        // would silently no-op server-side if checked (mission-route.php
        // only honors it when team_id is set).
        const returnWrap = document.getElementById('routeReturnToStartWrap');
        const returnCheck = document.getElementById('routeReturnToStartCheck');
        const isCrossTeam = teamSelect.value === '';
        returnWrap.classList.toggle('d-none', isCrossTeam);
        if (isCrossTeam) returnCheck.checked = false;

        // Empty value = cross-team mode (migration v110): list every
        // approved participant of the mission, grouped by their current
        // team, none pre-checked — unlike single-team mode there is no
        // sensible "whole team" default to start from, the admin must
        // deliberately assemble the group.
        if (teamSelect.value === '') {
            const byTeam = new Map();
            allApprovedForRoute.forEach(p => {
                const key = p.team_label || t('route.no_team_group');
                if (!byTeam.has(key)) byTeam.set(key, []);
                byTeam.get(key).push(p);
            });
            memberPicker.innerHTML = [...byTeam.entries()].map(([label, people]) => `
                <div class="w-100 small text-muted fw-semibold mt-1">${escapeHtml(label)}</div>
                ${people.map(p => `
                    <label class="form-check form-check-inline me-2 mb-1">
                        <input type="checkbox" class="form-check-input route-member-check" value="${p.id}">
                        <span class="form-check-label">${escapeHtml(p.name)}</span>
                    </label>
                `).join('')}
            `).join('') || `<span class="text-muted">${t('route.team_has_no_members')}</span>`;
            return;
        }
        const team = missionTeamsForRoute.find(t => String(t.id) === teamSelect.value);
        const members = team ? team.members : [];
        memberPicker.innerHTML = members.map(m => `
            <label class="form-check form-check-inline me-2 mb-1">
                <input type="checkbox" class="form-check-input route-member-check" value="${m.id}" checked>
                <span class="form-check-label">${escapeHtml(m.name)}</span>
            </label>
        `).join('') || `<span class="text-muted">${t('route.team_has_no_members')}</span>`;
    }
    renderRouteMemberPicker();
    teamSelect.addEventListener('change', renderRouteMemberPicker);

    // routeMap/routeWaypoints and the render/reset functions are module-level
    // (declared above renderWaypointPanel) rather than local to this IIFE,
    // since renderWaypointPanel's own per-waypoint "remove" button needs to
    // call back into the map redraw — one shared state, whichever caller
    // touches it first.
    modalEl.addEventListener('shown.bs.modal', () => {
        if (!routeMap) {
            const center = missionLocation.lat ? [missionLocation.lat, missionLocation.lng] : [37.97, 23.73];
            routeMap = L.map('routeMap').setView(center, missionLocation.lat ? 13 : 7);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {attribution: '© OpenStreetMap'}).addTo(routeMap);
            // Added before any waypoint markers exist, so once the admin
            // starts clicking points those numbered markers land on top of
            // (not under) the reference pins/annotations, matching Leaflet's
            // added-later-renders-on-top default — no explicit pane needed.
            routeComposerPinLayer = L.layerGroup().addTo(routeMap);
            routeComposerAnnotationLayer = L.featureGroup().addTo(routeMap);
            routeMap.on('click', e => {
                if (routeClosed) return;
                // Same "click near point 1 closes the shape" gesture as the
                // dispatch polygon tool (mission-dispatch.php's onMapClick) —
                // >=3 points placed, and the click lands within 16px (screen
                // space, so it stays easy to hit regardless of zoom level) of
                // point 1's current position.
                if (routeWaypoints.length >= 3) {
                    const firstPoint = routeMap.latLngToContainerPoint(L.latLng(routeWaypoints[0].lat, routeWaypoints[0].lng));
                    const clickPoint = routeMap.latLngToContainerPoint(e.latlng);
                    if (firstPoint.distanceTo(clickPoint) < 16) {
                        routeClosed = true;
                        renderRouteComposerMap();
                        renderWaypointPanel();
                        return;
                    }
                }
                routeWaypoints.push({lat: e.latlng.lat, lng: e.latlng.lng, label: '', instructions: '', dwell_minutes: 10, require_photo: false, require_video: false, require_note: false});
                renderRouteComposerMap();
                renderWaypointPanel();
                updateRouteSendState();
            });
        }
        // Refreshed on every open, not just the first — pins/annotations may
        // well have changed since the last time this admin opened the
        // composer, even though the underlying data itself has stayed live
        // (polled) the whole time in the background.
        renderRouteComposerPins(pins);
        renderRouteComposerAnnotations(annotations);
        setTimeout(() => routeMap.invalidateSize(), 100);
    });

    modalEl.addEventListener('hidden.bs.modal', () => {
        resetRouteComposer();
        titleInput.value = '';
        addressInput.value = '';
        addressStatus.textContent = '';
    });

    clearBtn.addEventListener('click', resetRouteComposer);

    addressSearchBtn.addEventListener('click', () => {
        const q = addressInput.value.trim();
        if (!q) return;
        addressStatus.textContent = t('dispatch.searching');
        fetch('geocode-address.php?q=' + encodeURIComponent(q)).then(response => response.json()).then(result => {
            if (result.ok) {
                routeMap.setView([result.lat, result.lng], 16);
                addressStatus.textContent = '✓ ' + (result.display_name || q);
            } else {
                addressStatus.textContent = result.error || t('dispatch.address_not_found');
            }
        }).catch(() => { addressStatus.textContent = t('dispatch.search_failed'); });
    });

    sendBtn.addEventListener('click', () => {
        if (!routeWaypoints.length) return;
        const payload = routeWaypoints.map(wp => ({
            lat: wp.lat, lng: wp.lng, label: wp.label, instructions: wp.instructions,
            dwell_minutes: wp.dwell_minutes, require_photo: wp.require_photo ? 1 : 0,
            require_video: wp.require_video ? 1 : 0, require_note: wp.require_note ? 1 : 0,
        }));
        const memberIds = [...memberPicker.querySelectorAll('.route-member-check:checked')].map(cb => cb.value);
        if (!memberIds.length) {
            alert(teamSelect.value === '' ? t('route.select_at_least_one_member') : t('route.team_has_no_members'));
            return;
        }
        const data = new URLSearchParams({
            csrf_token: csrfToken, action: 'create', mission_id: '<?= $missionId ?>',
            team_id: teamSelect.value, title: titleInput.value.trim(), waypoints: JSON.stringify(payload),
            is_closed_loop: routeClosed ? '1' : '0', member_ids: JSON.stringify(memberIds),
            add_return_waypoint: document.getElementById('routeReturnToStartCheck').checked ? '1' : '0',
        });
        sendBtn.disabled = true;
        fetch('mission-route.php', {method: 'POST', body: data}).then(r => r.json()).then(result => {
            if (result.ok) {
                bootstrap.Modal.getInstance(modalEl).hide();
                routes = result.routes;
                renderMyRoutes(routes);
                renderRoutesAdmin(routes);
                renderRouteLayer(routes);
            } else {
                alert(result.error || t('common.send_failed'));
            }
        }).catch(() => alert(t('common.send_failed'))).finally(() => { sendBtn.disabled = false; });
    });
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
