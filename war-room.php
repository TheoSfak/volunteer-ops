<?php
/**
 * VolunteerOps - War Room
 * Mission-specific live operational view for approved participants and managers.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/weather.php';
require_once __DIR__ . '/includes/wildfire.php';
require_once __DIR__ . '/includes/lpb-rings.php';
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
// includes/functions-warroom.php (still same index basis as MISSION_TEAM_CODENAMES
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
$isMissingPersonMission = ((int)($mission['mission_type_id'] ?? 0) === MISSION_TYPE_MISSING_PERSON_SEARCH);
if (!$canManageWarRoom && !$isApprovedParticipant) {
    setFlash('error', t('wr.access_denied'));
    redirect('dashboard.php');
}
if ($mission['status'] !== STATUS_OPEN || empty($mission['show_in_ops'])) {
    setFlash('warning', t('wr.mission_not_active'));
    redirect('mission-view.php?id=' . $missionId);
}

// A volunteer's own explicit choice (the toggle button below, which sets
// this cookie) always wins and is remembered from then on. With no cookie
// yet, the default is the normal full view on every device, mobile
// included — a deliberate reversal of this file's earlier User-Agent-sniffed
// default (mission owner's explicit call): Field Mode stays available as an
// opt-in, one tap away, but no longer decides silently for a first-time
// mobile visitor.
$fieldMode = isset($_COOKIE['wr_field_mode']) && $_COOKIE['wr_field_mode'] === '1';

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
                'order.location.title', [], null, 'order.location.message', ['mission' => $mission['title']]
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
                'order.photo.title', [], null, 'order.photo.message', ['mission' => $mission['title']]
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
                'order.video.title', [], null, 'order.video.message', ['mission' => $mission['title']]
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

        // Optional reference photo (e.g. a missing person's photo relayed to
        // the coordination center) — broadcast alongside the text to every
        // approved participant. Validated fail-fast, same style as
        // mission-photo.php's own upload action, before anything is written.
        $photoUpload = null;
        if (!empty($_FILES['global_message_photo']['name']) && $_FILES['global_message_photo']['error'] !== UPLOAD_ERR_NO_FILE) {
            $file = $_FILES['global_message_photo'];
            if ($file['error'] !== UPLOAD_ERR_OK) {
                setFlash('error', t('photo.select_file'));
                redirect('war-room.php?id=' . $missionId);
            }
            $photoExt  = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            $photoMime = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $origName = basename($file['name']);
            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($file['tmp_name']);
            if (!in_array($ext, $photoExt, true) || !in_array($mime, $photoMime, true)) {
                setFlash('error', t('photo.invalid_type'));
                redirect('war-room.php?id=' . $missionId);
            }
            if ($file['size'] > UPLOAD_MAX_SIZE) {
                setFlash('error', t('photo.file_too_large', ['size' => UPLOAD_MAX_SIZE / 1024 / 1024]));
                redirect('war-room.php?id=' . $missionId);
            }

            $destDir = __DIR__ . '/uploads/mission-photos/';
            if (!is_dir($destDir)) {
                mkdir($destDir, 0755, true);
            }
            $storedName = 'orderphoto_' . $missionId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            if (!move_uploaded_file($file['tmp_name'], $destDir . $storedName)) {
                setFlash('error', t('photo.save_failed'));
                redirect('war-room.php?id=' . $missionId);
            }
            $photoUpload = ['stored_name' => $storedName, 'original_name' => $origName, 'mime_type' => $mime, 'file_size' => (int) $file['size']];
        }

        if ($broadcastText === '' && $photoUpload === null) {
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

            $orderId = createMissionOrderAndNotify(
                $missionId, $mission['title'], 'message', $user['id'], $recipientIds,
                'global_message.title', ['mission' => $mission['title']], $broadcastText !== '' ? $broadcastText : t('global_message.photo_only_text'), '', [],
                $broadcastText !== '' ? $broadcastText : null
            );

            if ($photoUpload !== null) {
                dbInsert(
                    "INSERT INTO mission_photos (mission_id, user_id, media_type, stored_name, original_name, mime_type, file_size, order_id, created_at)
                     VALUES (?, ?, 'photo', ?, ?, ?, ?, ?, NOW())",
                    [$missionId, $user['id'], $photoUpload['stored_name'], $photoUpload['original_name'], $photoUpload['mime_type'], $photoUpload['file_size'], $orderId]
                );
            }

            logAudit('global_message_war_room', 'missions', $missionId, null, ['message' => $broadcastText, 'has_photo' => $photoUpload !== null]);
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
    } elseif (post('action') === 'save_briefing_info') {
        if (!$canManageWarRoom) {
            setFlash('error', t('briefing.perm.save'));
            redirect('war-room.php?id=' . $missionId);
        }
        if (empty($mission['is_special_mission'])) {
            setFlash('error', t('briefing.not_special_mission'));
            redirect('war-room.php?id=' . $missionId);
        }
        $rvPointLabel = mb_substr(trim((string) post('rv_point_label')), 0, 255);
        $radioChannel = mb_substr(trim((string) post('radio_channel')), 0, 100);
        dbExecute(
            "UPDATE missions SET rv_point_label = ?, radio_channel = ?, updated_at = NOW() WHERE id = ?",
            [$rvPointLabel !== '' ? $rvPointLabel : null, $radioChannel !== '' ? $radioChannel : null, $missionId]
        );
        logAudit('save_briefing_info', 'missions', $missionId,
            ['rv_point_label' => $mission['rv_point_label'], 'radio_channel' => $mission['radio_channel']],
            ['rv_point_label' => $rvPointLabel, 'radio_channel' => $radioChannel]);
        setFlash('success', t('briefing.save_success'));
        redirect('war-room.php?id=' . $missionId);
    } elseif (post('action') === 'save_missing_person_info') {
        if (!$canManageWarRoom) {
            setFlash('error', t('missing_person.perm.save'));
            redirect('war-room.php?id=' . $missionId);
        }
        if (!$isMissingPersonMission) {
            setFlash('error', t('missing_person.not_missing_person_mission'));
            redirect('war-room.php?id=' . $missionId);
        }
        $fullName = mb_substr(trim((string) post('full_name')), 0, 255);
        if ($fullName === '') {
            setFlash('error', t('missing_person.name_required'));
            redirect('war-room.php?id=' . $missionId);
        }

        $ageRaw = post('age');
        $age = ($ageRaw !== '' && $ageRaw !== null && is_numeric($ageRaw)) ? max(0, min(150, (int) $ageRaw)) : null;

        $description = trim((string) post('description'));
        $description = $description !== '' ? mb_substr($description, 0, 5000) : null;
        $clothingDescription = trim((string) post('clothing_description'));
        $clothingDescription = $clothingDescription !== '' ? mb_substr($clothingDescription, 0, 2000) : null;
        $vehicle = trim((string) post('vehicle'));
        $vehicle = $vehicle !== '' ? mb_substr($vehicle, 0, 255) : null;
        $lastSeenLabel = trim((string) post('last_seen_label'));
        $lastSeenLabel = $lastSeenLabel !== '' ? mb_substr($lastSeenLabel, 0, 255) : null;

        $lastSeenAtRaw = trim((string) post('last_seen_at'));
        $lastSeenAt = null;
        if ($lastSeenAtRaw !== '') {
            $ts = strtotime($lastSeenAtRaw);
            if ($ts !== false) {
                $lastSeenAt = date('Y-m-d H:i:s', $ts);
            }
        }

        // Same validation/clamping as mission-photo.php's GPS handling.
        $latRaw = post('last_seen_lat');
        $lngRaw = post('last_seen_lng');
        $lat = ($latRaw !== '' && $latRaw !== null && is_numeric($latRaw)) ? (float) $latRaw : null;
        $lng = ($lngRaw !== '' && $lngRaw !== null && is_numeric($lngRaw)) ? (float) $lngRaw : null;
        if ($lat !== null && ($lat < -90 || $lat > 90)) { $lat = null; }
        if ($lng !== null && ($lng < -180 || $lng > 180)) { $lng = null; }
        if ($lat === 0.0 && $lng === 0.0) { $lat = null; $lng = null; }
        if ($lat === null || $lng === null) { $lat = null; $lng = null; }

        $disappearanceCircumstances = trim((string) post('disappearance_circumstances'));
        $disappearanceCircumstances = $disappearanceCircumstances !== '' ? mb_substr($disappearanceCircumstances, 0, 3000) : null;
        $likelyDirection = trim((string) post('likely_direction'));
        $likelyDirection = $likelyDirection !== '' ? mb_substr($likelyDirection, 0, 255) : null;
        $witnessAccounts = trim((string) post('witness_accounts'));
        $witnessAccounts = $witnessAccounts !== '' ? mb_substr($witnessAccounts, 0, 5000) : null;

        // Drives the "LPB search rings" map layer — must be one of
        // LPB_RING_TABLE's keys (includes/lpb-rings.php) or null, never
        // free text, since it's used as a direct lookup key client-side.
        $subjectCategoryRaw = trim((string) post('subject_category'));
        $subjectCategory = in_array($subjectCategoryRaw, array_keys(LPB_RING_TABLE), true) ? $subjectCategoryRaw : null;

        $existing = dbFetchOne("SELECT id, photo FROM mission_missing_persons WHERE mission_id = ?", [$missionId]);

        // Photo is optional on every save — only touched (validated, stored,
        // old file unlinked) when a new one was actually chosen. $newPhoto
        // stays null otherwise, so the upsert's COALESCE below keeps
        // whatever was already on file.
        $newPhoto = null;
        if (!empty($_FILES['photo']['name']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['photo'];
            $photoExt = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            $photoMime = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $ext = strtolower(pathinfo(basename($file['name']), PATHINFO_EXTENSION));
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($file['tmp_name']);

            if (!in_array($ext, $photoExt, true) || !in_array($mime, $photoMime, true)) {
                setFlash('error', t('photo.invalid_type'));
                redirect('war-room.php?id=' . $missionId);
            }
            if ($file['size'] > UPLOAD_MAX_SIZE) {
                setFlash('error', t('photo.file_too_large', ['size' => UPLOAD_MAX_SIZE / 1024 / 1024]));
                redirect('war-room.php?id=' . $missionId);
            }

            $destDir = __DIR__ . '/uploads/missing-persons/';
            if (!is_dir($destDir)) {
                mkdir($destDir, 0755, true);
            }
            $storedName = 'mperson_' . $missionId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            if (!move_uploaded_file($file['tmp_name'], $destDir . $storedName)) {
                setFlash('error', t('photo.save_failed'));
                redirect('war-room.php?id=' . $missionId);
            }
            $newPhoto = $storedName;

            if ($existing && !empty($existing['photo'])) {
                $oldPath = $destDir . basename($existing['photo']);
                if (is_file($oldPath)) {
                    unlink($oldPath);
                }
            }
        }

        dbExecute(
            "INSERT INTO mission_missing_persons
                (mission_id, full_name, age, description, clothing_description, vehicle, photo,
                 last_seen_label, last_seen_lat, last_seen_lng, last_seen_at, subject_category,
                 disappearance_circumstances, likely_direction, witness_accounts, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE
                full_name = VALUES(full_name), age = VALUES(age), description = VALUES(description),
                clothing_description = VALUES(clothing_description), vehicle = VALUES(vehicle),
                photo = COALESCE(VALUES(photo), photo),
                last_seen_label = VALUES(last_seen_label), last_seen_lat = VALUES(last_seen_lat),
                last_seen_lng = VALUES(last_seen_lng), last_seen_at = VALUES(last_seen_at),
                subject_category = VALUES(subject_category),
                disappearance_circumstances = VALUES(disappearance_circumstances),
                likely_direction = VALUES(likely_direction), witness_accounts = VALUES(witness_accounts),
                updated_at = NOW()",
            [$missionId, $fullName, $age, $description, $clothingDescription, $vehicle, $newPhoto,
             $lastSeenLabel, $lat, $lng, $lastSeenAt, $subjectCategory,
             $disappearanceCircumstances, $likelyDirection, $witnessAccounts]
        );
        logAudit('save_missing_person_info', 'mission_missing_persons', $existing['id'] ?? null, null, ['mission_id' => $missionId, 'full_name' => $fullName]);
        setFlash('success', t('missing_person.save_success'));
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
    } elseif (post('action') === 'report_incident') {
        if (!$isApprovedParticipant) {
            setFlash('error', t('wr.perm.report_incident'));
            redirect('war-room.php?id=' . $missionId);
        }

        $allowedTypes = array_keys(INCIDENT_TYPE_LABELS);
        $allowedSeverities = ['low', 'medium', 'high', 'critical'];
        $incidentType = post('incident_type');
        $severity = post('severity');
        $isUnknownPatient = post('is_unknown_patient') === '1';
        $patientName = $isUnknownPatient ? '' : mb_substr(trim((string) post('patient_name')), 0, 255);
        $estimatedAge = mb_substr(trim((string) post('estimated_age')), 0, 50);
        $gender = post('gender');
        $allowedGenders = array_keys(INCIDENT_GENDER_LABELS);
        $phone = $isUnknownPatient ? '' : mb_substr(trim((string) post('phone')), 0, 30);
        $notes = mb_substr(trim((string) post('notes')), 0, 2000);
        $lat = post('lat') !== '' ? (float) post('lat') : null;
        $lng = post('lng') !== '' ? (float) post('lng') : null;

        if (!in_array($incidentType, $allowedTypes, true) || !in_array($severity, $allowedSeverities, true)) {
            setFlash('error', t('incident.invalid_fields'));
        } elseif (!$isUnknownPatient && $patientName === '') {
            setFlash('warning', t('incident.missing_fields'));
        } elseif ($gender !== '' && !in_array($gender, $allowedGenders, true)) {
            setFlash('error', t('incident.invalid_fields'));
        } else {
            $teamId = getUserTeamIdForMission($missionId, $user['id']);
            $incidentId = dbInsert(
                "INSERT INTO mission_incidents
                    (mission_id, reporter_id, team_id, lat, lng, incident_type, severity,
                     is_unknown_patient, patient_name, estimated_age, gender, phone, notes, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
                [
                    $missionId, $user['id'], $teamId, $lat, $lng, $incidentType, $severity,
                    $isUnknownPatient ? 1 : 0, $patientName ?: null, $estimatedAge ?: null,
                    $gender ?: null, $phone ?: null, $notes ?: null,
                ]
            );
            logAudit('report_mission_incident', 'mission_incidents', $incidentId, null, ['mission_id' => $missionId, 'severity' => $severity]);

            $recipientIds = getMissionCommandStaffIds($missionId, $mission['responsible_user_id'] ? (int) $mission['responsible_user_id'] : null, (int) $user['id']);
            $warRoomUrl = rtrim(BASE_URL, '/') . '/war-room.php?id=' . $missionId;
            $incidentRecipientLangs = getUserLanguages($recipientIds);
            foreach ($recipientIds as $recipientId) {
                $lang = $incidentRecipientLangs[$recipientId] ?? DEFAULT_LANGUAGE;
                $notifTitle = t('incident.notify_title', ['mission' => $mission['title']], $lang);
                $notifMessage = t('incident.notify_message', [
                    'name' => h($user['name']),
                    'type' => incidentTypeLabel($incidentType, $lang),
                    'severity' => incidentSeverityLabel($severity, $lang),
                ], $lang);
                $pushData = ['url' => $warRoomUrl, 'tag' => 'incident-report-mission-' . $missionId, 'bannerMission' => $missionId, 'vibrate' => [300, 100, 300, 100, 500]];
                // Always mandatory (empty code, same as orders/SOS/needs_help) — a
                // person needing help can never be silently muted by an admin's
                // own notification preference, unlike shortage's low/medium tier.
                sendNotification($recipientId, $notifTitle, $notifMessage, 'danger', '', $pushData);
            }
            setFlash('success', t('incident.submitted_flash'));
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
// configured auto-ping cadence (warRoomPingStaleThresholdSeconds() —
// includes/functions-warroom.php, also used by computeDispatchEta() and
// loadTeamPositionsForMission() so every staleness check in the app scales
// together with war_room_auto_ping_seconds) — enough headroom to not cry
// wolf over one missed tick's jitter, but still an honest signal once the
// gap is real (e.g. the tab got backgrounded/suspended, or geolocation
// permission was revoked). Shared by the full render and the ajax poll
// below so both agree.
//
// Computed via its own lightweight query (volunteer_id + last_ping_at only)
// rather than by reusing the full $participants query below — that query
// (with its name/phone/is_external/guest_org_name/shift-time joins) only
// exists for the Participants-list UI, which the ajax poll never renders,
// but every open tab hits this exact computation every 5s regardless.
$pingStaleThresholdSeconds = warRoomPingStaleThresholdSeconds();
$pingIsStaleByVolunteerId = [];
// Also doubles as the ajax poll's live-refresh source for the Participants
// list's ping-time text and status badge — both were previously frozen at
// page load (the $participants query below only ever runs on a full,
// non-ajax render), a real gap surfaced by the first real-device test of
// native background tracking: GPS kept flowing but this card never showed
// it without a manual reload. Reuses this exact query/scope rather than
// adding a second one.
$participantLiveByVolunteerId = [];
// War Room fatigue flag: minutes each currently-on-duty volunteer has been
// continuously in the field on this mission. Computed once here (same shape
// as $pingIsStaleByVolunteerId above) and merged into $participantLiveByVolunteerId
// below so it rides the existing ajax-poll + full-render plumbing rather than
// needing its own JSON key.
$continuousFieldMinutesByVolunteerId = computeContinuousFieldMinutesByVolunteerId($missionId);
$warRoomMaxShiftMinutes = (int) getSetting('war_room_max_shift_minutes', '480');
$warRoomCriticalShiftMinutes = (int) round($warRoomMaxShiftMinutes * 1.5);
foreach (dbFetchAll(
    "SELECT pr.volunteer_id" . ($hasFieldStatus ? ', pr.field_status' : ', NULL AS field_status') . ",
            (SELECT MAX(vp.created_at) FROM volunteer_pings vp WHERE vp.user_id = pr.volunteer_id AND vp.shift_id = pr.shift_id) AS last_ping_at
     FROM participation_requests pr
     JOIN shifts s ON s.id = pr.shift_id
     WHERE s.mission_id = ? AND pr.status = ?",
    [$missionId, PARTICIPATION_APPROVED]
) as $pingRow) {
    $volunteerId = (int)$pingRow['volunteer_id'];
    // Only set a staleness entry when a ping actually exists. A missing
    // key (never a false one) is how renderPresence()'s hasFreshPing
    // check tells "no ping ever" apart from "pinged recently" — both
    // used to collapse to the same `false`, which the client read as
    // "not stale" i.e. fresh, marking every never-pinged volunteer
    // online within one 5s poll tick.
    if ($pingRow['last_ping_at'] !== null) {
        $pingIsStaleByVolunteerId[$volunteerId] = strtotime($pingRow['last_ping_at']) < (time() - $pingStaleThresholdSeconds);
    }
    $participantLiveByVolunteerId[$volunteerId] = [
        'last_ping_time' => $pingRow['last_ping_at'] ? formatDateTime($pingRow['last_ping_at'], 'H:i d/m/Y') : null,
        'field_status' => $pingRow['field_status'],
        'continuous_field_minutes' => $continuousFieldMinutesByVolunteerId[$volunteerId] ?? null,
    ];
}

// Always returns each participant's LATEST ping regardless of age — a hard
// "last 2 hours" cutoff used to make someone silently vanish from the live
// map the moment their last ping aged past it, even though Team Trail (which
// has no such cutoff) still showed them. The map now shows every last-known
// position always, marking it 'is_stale' (reusing the same $pingStaleThresholdSeconds
// as the sidebar list) once it's past due, rather than hiding it outright.
$loadPins = function () use ($missionId, $hasFieldStatus, $pingStaleThresholdSeconds, $continuousFieldMinutesByVolunteerId) {
    try {
        $field = $hasFieldStatus ? ', pr.field_status' : ', NULL AS field_status';
        // Was: 1 query for the latest ping per volunteer, PLUS one extra
        // query per volunteer inside the loop below to find their previous
        // ping (needed only for the moving/heading calc) — a real N+1 that
        // scaled with active-volunteer count on every single poll tick.
        // LEAD() pulls that previous ping's lat/lng/accuracy/time into the
        // SAME row in one query. Windowed/ordered by vp.id (not created_at)
        // to match exactly what the old MAX(id) subquery picked as
        // "latest" — id is the real tiebreak of record here, not the
        // timestamp, so this selects and pairs the identical rows the old
        // two-query version did.
        $rawPins = dbFetchAll(
            "SELECT * FROM (
                SELECT vp.user_id, vp.shift_id, vp.lat, vp.lng, vp.accuracy_meters, vp.battery_level, vp.created_at, u.name,
                        u.is_external, u.guest_org_name, u.guest_country_code,
                        ht.name AS home_team_name, ht.color AS home_team_color,
                        mt.color AS team_color, mt.codename, mt.team_number{$field},
                        ROW_NUMBER() OVER (PARTITION BY vp.user_id, vp.shift_id ORDER BY vp.id DESC) AS rn,
                        LEAD(vp.lat) OVER (PARTITION BY vp.user_id, vp.shift_id ORDER BY vp.id DESC) AS prev_lat,
                        LEAD(vp.lng) OVER (PARTITION BY vp.user_id, vp.shift_id ORDER BY vp.id DESC) AS prev_lng,
                        LEAD(vp.accuracy_meters) OVER (PARTITION BY vp.user_id, vp.shift_id ORDER BY vp.id DESC) AS prev_accuracy_meters,
                        LEAD(vp.created_at) OVER (PARTITION BY vp.user_id, vp.shift_id ORDER BY vp.id DESC) AS prev_created_at
                 FROM volunteer_pings vp
                 JOIN shifts s ON s.id = vp.shift_id
                 JOIN users u ON u.id = vp.user_id
                 LEFT JOIN volunteer_teams ht ON ht.id = u.volunteer_team_id
                 LEFT JOIN participation_requests pr ON pr.shift_id = vp.shift_id AND pr.volunteer_id = vp.user_id
                 LEFT JOIN mission_team_members mtm ON mtm.user_id = vp.user_id AND mtm.mission_id = s.mission_id
                 LEFT JOIN mission_teams mt ON mt.id = mtm.team_id
                 WHERE s.mission_id = ?
             ) ranked
             WHERE rn = 1
             ORDER BY created_at DESC",
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
            if ($pin['prev_created_at'] !== null) {
                $secondsBetween = $pingTs - strtotime($pin['prev_created_at']);
                if ($secondsBetween > 0 && $secondsBetween <= 1200) {
                    $distanceMeters = gpsDistanceMeters(
                        (float) $pin['prev_lat'], (float) $pin['prev_lng'],
                        (float) $pin['lat'], (float) $pin['lng']
                    );
                    $requiredMeters = ($pin['prev_accuracy_meters'] !== null && $pin['accuracy_meters'] !== null)
                        ? max(30, (float) $pin['prev_accuracy_meters'] + (float) $pin['accuracy_meters'])
                        : 75;
                    $isMoving = $distanceMeters >= $requiredMeters;
                    // Heading only means something once we've already decided
                    // this is real movement, not GPS jitter — a bearing
                    // computed between two noisy-but-stationary fixes would
                    // point in a meaningless, randomly-flipping direction.
                    if ($isMoving) {
                        $headingDeg = gpsBearingDegrees(
                            (float) $pin['prev_lat'], (float) $pin['prev_lng'],
                            (float) $pin['lat'], (float) $pin['lng']
                        );
                    }
                }
            }

            [$homeBg, $homeFg] = teamBadgeColors($pin['home_team_color']);
            $pins[] = [
                'user_id' => (int) $pin['user_id'],
                'lat' => (float) $pin['lat'], 'lng' => (float) $pin['lng'], 'name' => $pin['name'],
                'status' => $pin['field_status'], 'team_color' => $pin['team_color'],
                'team_label' => $pin['codename'] ? teamLabel($pin['codename'], $pin['team_number']) : null,
                'is_external' => (bool) $pin['is_external'], 'guest_org_name' => $pin['guest_org_name'],
                'home_team_name' => $pin['home_team_name'], 'home_team_color_bg' => $homeBg, 'home_team_color_fg' => $homeFg,
                'guest_country_code' => $pin['guest_country_code'],
                // Includes the date, not just H:i — same reasoning already
                // applied to the GPS trail's own 'time' field (see
                // loadMissionTrailForMission()): a shift, and therefore its
                // last ping, can be from a completely different day (or, for
                // an old/reopened mission, a different year) than "now".
                'time' => date('H:i d/m/Y', $pingTs),
                'is_stale' => $isStale, 'is_moving' => $isMoving, 'heading_deg' => $headingDeg,
                'battery_level' => $pin['battery_level'] !== null ? (int) $pin['battery_level'] : null,
                'continuous_field_minutes' => $continuousFieldMinutesByVolunteerId[(int) $pin['user_id']] ?? null,
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
$loadTeamProximity = function () use ($missionId, $user, $continuousFieldMinutesByVolunteerId) {
    $teamPositions = loadTeamPositionsForMission($missionId, $continuousFieldMinutesByVolunteerId);

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

// Field Mode has no map at all (see the !$fieldMode wrap further down), so
// this is the only place a field volunteer gets any restricted-area
// awareness — a plain distance list instead of seeing the red zone on a
// map, same reasoning as $loadTeamProximity/nearbyTeamsCard just above.
// Kept as its own closure rather than folded into $loadTeamProximity
// (different subject, would muddy that name) — the small extra "my own
// latest ping" query this duplicates is cheap and both indexes it needs
// already exist for $loadTeamProximity's identical lookup.
$loadRestrictedAreaProximity = function () use ($missionId, $user, &$restrictedAreas) {
    if (empty($restrictedAreas)) {
        return [];
    }
    $myPing = dbFetchOne(
        "SELECT vp.lat, vp.lng FROM volunteer_pings vp
         JOIN shifts s ON s.id = vp.shift_id
         WHERE s.mission_id = ? AND vp.user_id = ?
         ORDER BY vp.created_at DESC LIMIT 1",
        [$missionId, $user['id']]
    );
    if (!$myPing) {
        return [];
    }
    $result = [];
    foreach ($restrictedAreas as $area) {
        $result[] = [
            'id'         => $area['id'],
            'label'      => $area['label'],
            'distance_m' => pointToPolygonDistanceMeters((float) $myPing['lat'], (float) $myPing['lng'], $area['geo']),
        ];
    }
    usort($result, fn($a, $b) => $a['distance_m'] <=> $b['distance_m']);
    return $result;
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
    $broadcastPhotos = loadBroadcastPhotosForMission($missionId, $canManageWarRoom);
    $myTasks = loadMyTaskOrdersForUser($missionId, (int)$user['id']);
    $routes = loadRoutesForUser($missionId, (int)$user['id'], $canManageWarRoom);
    $shortageReports = $canManageWarRoom ? loadUnresolvedShortageReportsForMission($missionId) : [];
    $incidents = ($canManageWarRoom || $isApprovedParticipant) ? loadUnresolvedIncidentsForMission($missionId, $canManageWarRoom) : [];
    $sosAlerts = $canManageWarRoom ? loadOpenSosAlertsForMission($missionId) : [];
    $pointsOfInterest = ($canManageWarRoom || $isApprovedParticipant) ? loadPointsOfInterestForMission($missionId) : [];
    $missingPerson = ($isMissingPersonMission && ($canManageWarRoom || $isApprovedParticipant)) ? loadMissingPersonForMission($missionId) : null;
    // Both gated behind their own Settings toggle (default off) — see the
    // Action Room weather/exposure artifact for why these are two separate
    // flags rather than one. getWeatherForMission() has its own 3h DB cache,
    // so calling it every 5s poll costs one indexed SELECT, not an API hit.
    $weatherCompassOn = getSetting('weather_map_compass_enabled', '0') === '1';
    $exposureUrgencyOn = $isMissingPersonMission && getSetting('exposure_urgency_enabled', '0') === '1';
    // "LPB search rings" — same off-by-default posture, but no weather
    // dependency at all (pure static lookup keyed by subject_category), so
    // it doesn't join the $weather fetch below.
    $searchRingsOn = $isMissingPersonMission && getSetting('search_rings_enabled', '0') === '1';
    // Wrapped: getWeatherForMission() hits weather_cache via a plain SELECT
    // with no table-existence check of its own, so an environment where that
    // table wasn't provisioned (e.g. a migration that only ever shipped as a
    // standalone sql/migrations/*.sql file, never picked up by a deploy path
    // that doesn't run update.php's migration runner) would otherwise throw
    // an uncaught PDOException here and take down this entire ajax poll —
    // every other live card, not just the weather one. Degrade to "no
    // weather data" instead; this toggle must never be able to break the
    // rest of the Action Room.
    try {
        $weather = ($weatherCompassOn || $exposureUrgencyOn) ? getWeatherForMission($mission) : null;
    } catch (Throwable $e) {
        error_log('getWeatherForMission() failed (ajax poll, mission ' . $missionId . '): ' . $e->getMessage());
        $weather = null;
    }
    // Formatted here (not in weather.php) so the label travels as-is through
    // json_encode to every poll tick — mission-view.php's own date() call
    // stays untouched since this is an added key, not a changed one.
    if ($weather && ($weather['status'] ?? '') === 'ok') {
        $weather['forecast_dt_label'] = date('d/m H:i', $weather['forecast_dt']);
    }
    $exposureUrgency = ($exposureUrgencyOn && $missingPerson && $weather) ? computeExposureUrgency($missingPerson, $weather) : null;
    // Per-mission flag (not a global Settings toggle like the weather compass
    // above) — flipped live by admins from inside the Action Room itself via
    // mission-fires.php, so every viewer picks up the change on their next
    // poll tick. Same defensive try/catch reasoning as getWeatherForMission()
    // just above: a NASA FIRMS/DB hiccup here must degrade to "no fire data",
    // never take down the rest of this poll.
    $firesOverlayOn = !empty($mission['fires_overlay_enabled']);
    try {
        $fireHotspots = $firesOverlayOn ? getFireHotspotsForMission($mission) : null;
    } catch (Throwable $e) {
        error_log('getFireHotspotsForMission() failed (ajax poll, mission ' . $missionId . '): ' . $e->getMessage());
        $fireHotspots = null;
    }
    $onlinePresence = loadOnlinePresenceUserIds($missionId);
    $annotations = loadMissionAnnotationsForMission($missionId);
    $areas = loadMissionSearchAreasForUser($missionId, $canManageWarRoom);
    $sectors = loadMissionSectorsForUser($missionId, (int)$user['id'], $canManageWarRoom, $isApprovedParticipant);
    // Only the id/leader/roster fields the live Teams card actually
    // re-renders are needed here (see renderTeamRosters() further down) —
    // array_values() so this comes through as a JSON array, not an object
    // keyed by team id like the full-page render's own $teams stays as.
    $teams = array_values(loadMissionTeamsForMission($missionId));
    // Hazard zones are mission-wide, not team/area-scoped (no team_id on
    // mission_restricted_areas at all) — unlike shortage reports/SOS above,
    // there's no reason to hide a danger zone's boundary from the very
    // people who need to physically avoid it. Same (canManageWarRoom ||
    // isApprovedParticipant) gate this file already uses for incidents/POIs.
    $restrictedAreas = ($canManageWarRoom || $isApprovedParticipant) ? loadMissionRestrictedAreasForUser($missionId) : [];
    $restrictedAreaBreaches = loadOpenRestrictedAreaBreachesForUser($missionId, (int)$user['id'], $canManageWarRoom);
    // Full history (open + resolved) for the breach LIST widget — deliberately
    // a separate load from $restrictedAreaBreaches above, which must stay
    // open-only since it drives the alarm state machine's "nothing open at
    // all" branch.
    $restrictedAreaBreachHistory = loadRestrictedAreaBreachHistoryForUser($missionId, (int)$user['id'], $canManageWarRoom);
    $teamProximity = $loadTeamProximity();
    $restrictedAreaProximity = $loadRestrictedAreaProximity();

    echo json_encode([
        'pins' => $pins,
        'time' => date('H:i:s'),
        'banners' => $banners,
        'dispatches' => $dispatches,
        'media' => $photos,
        'broadcastPhotos' => $broadcastPhotos,
        'myTasks' => $myTasks,
        'routes' => $routes,
        'shortageReports' => $shortageReports,
        'incidents' => $incidents,
        'sosAlerts' => $sosAlerts,
        'pointsOfInterest' => $pointsOfInterest,
        'missingPerson' => $missingPerson,
        'weather' => $weather,
        'exposureUrgency' => $exposureUrgency,
        'firesOverlayOn' => $firesOverlayOn,
        'fireHotspots' => $fireHotspots,
        'onlinePresence' => $onlinePresence,
        'pingStaleness' => $pingIsStaleByVolunteerId,
        'participantLive' => $participantLiveByVolunteerId,
        'annotations' => $annotations,
        'areas' => $areas,
        'sectors' => $sectors,
        'teams' => $teams,
        'restrictedAreas' => $restrictedAreas,
        'restrictedAreaBreaches' => $restrictedAreaBreaches,
        'restrictedAreaBreachHistory' => $restrictedAreaBreachHistory,
        'restrictedAreaProximity' => $restrictedAreaProximity,
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
            u.name, u.phone, u.is_external, u.guest_org_name, u.guest_country_code,
            ht.name AS home_team_name, ht.color AS home_team_color,
            s.id AS shift_id, s.start_time, s.end_time,
            (SELECT MAX(vp.created_at) FROM volunteer_pings vp WHERE vp.user_id = pr.volunteer_id AND vp.shift_id = pr.shift_id) AS last_ping_at
     FROM participation_requests pr
     JOIN users u ON u.id = pr.volunteer_id
     LEFT JOIN volunteer_teams ht ON ht.id = u.volunteer_team_id
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
$broadcastPhotos = loadBroadcastPhotosForMission($missionId, $canManageWarRoom);
$myTasks = loadMyTaskOrdersForUser($missionId, (int)$user['id']);
$routes = loadRoutesForUser($missionId, (int)$user['id'], $canManageWarRoom);
$shortageReports = $canManageWarRoom ? loadUnresolvedShortageReportsForMission($missionId) : [];
$incidents = ($canManageWarRoom || $isApprovedParticipant) ? loadUnresolvedIncidentsForMission($missionId, $canManageWarRoom) : [];
$sosAlerts = $canManageWarRoom ? loadOpenSosAlertsForMission($missionId) : [];
$pointsOfInterest = ($canManageWarRoom || $isApprovedParticipant) ? loadPointsOfInterestForMission($missionId) : [];
$missingPerson = ($isMissingPersonMission && ($canManageWarRoom || $isApprovedParticipant)) ? loadMissingPersonForMission($missionId) : null;
// See the ajax branch's own copy of this block above for why these two are
// separately gated settings rather than one flag, and why the call is
// try/caught (a missing weather_cache table must not fail the whole page).
$weatherCompassOn = getSetting('weather_map_compass_enabled', '0') === '1';
$exposureUrgencyOn = $isMissingPersonMission && getSetting('exposure_urgency_enabled', '0') === '1';
// See the ajax branch's own copy of this block above.
$searchRingsOn = $isMissingPersonMission && getSetting('search_rings_enabled', '0') === '1';
try {
    $weather = ($weatherCompassOn || $exposureUrgencyOn) ? getWeatherForMission($mission) : null;
} catch (Throwable $e) {
    error_log('getWeatherForMission() failed (full page load, mission ' . $missionId . '): ' . $e->getMessage());
    $weather = null;
}
if ($weather && ($weather['status'] ?? '') === 'ok') {
    $weather['forecast_dt_label'] = date('d/m H:i', $weather['forecast_dt']);
}
$exposureUrgency = ($exposureUrgencyOn && $missingPerson && $weather) ? computeExposureUrgency($missingPerson, $weather) : null;
// See the ajax branch's own copy of this block above — per-mission flag,
// not a global setting, flipped live from within the Action Room itself.
$firesOverlayOn = !empty($mission['fires_overlay_enabled']);
try {
    $fireHotspots = $firesOverlayOn ? getFireHotspotsForMission($mission) : null;
} catch (Throwable $e) {
    error_log('getFireHotspotsForMission() failed (full page load, mission ' . $missionId . '): ' . $e->getMessage());
    $fireHotspots = null;
}
$annotations = loadMissionAnnotationsForMission($missionId);
$areas = loadMissionSearchAreasForUser($missionId, $canManageWarRoom);
$sectors = loadMissionSectorsForUser($missionId, (int)$user['id'], $canManageWarRoom, $isApprovedParticipant);
// Hazard zones are mission-wide, not team/area-scoped (no team_id on
// mission_restricted_areas at all) — unlike shortage reports/SOS above,
// there's no reason to hide a danger zone's boundary from the very
// people who need to physically avoid it. Same (canManageWarRoom ||
// isApprovedParticipant) gate this file already uses for incidents/POIs.
$restrictedAreas = ($canManageWarRoom || $isApprovedParticipant) ? loadMissionRestrictedAreasForUser($missionId) : [];
$restrictedAreaBreaches = loadOpenRestrictedAreaBreachesForUser($missionId, (int)$user['id'], $canManageWarRoom);
// See the ajax branch's own copy of this line above for why it's separate.
$restrictedAreaBreachHistory = loadRestrictedAreaBreachHistoryForUser($missionId, (int)$user['id'], $canManageWarRoom);
$teamProximity = $loadTeamProximity();
$nearbyTeams = $teamProximity['nearbyTeams'];
$teamDistances = $teamProximity['teamDistances'];
$restrictedAreaProximity = $loadRestrictedAreaProximity();

$firstShift = $shifts[0]['start_time'] ?? $mission['start_datetime'];
$lastShift = !empty($shifts) ? end($shifts)['end_time'] : $mission['end_datetime'];
$now = time();
$timeState = strtotime($firstShift) > $now ? 'upcoming' : (strtotime($lastShift) < $now ? 'overdue' : 'active');
$activeParticipants = array_values(array_filter($participants, fn($participant) =>
    strtotime($participant['start_time']) <= $now && strtotime($participant['end_time']) > $now
));

// ── Mission teams ─────────────────────────────────────────────────────────
$teams = loadMissionTeamsForMission($missionId);

// Lazy briefing-link generation: a team created before its mission was
// flagged special (or before this feature existed) simply has a NULL
// briefing_token — backfilled here on the admin's next War Room view rather
// than needing a separate "generate" action. Mirrors shift-view.php's own
// lazy QR-token generation idiom.
if ($canManageWarRoom && !empty($mission['is_special_mission'])) {
    foreach ($teams as $tid => &$teamRef) {
        if (empty($teamRef['briefing_token'])) {
            $newBriefingToken = bin2hex(random_bytes(32));
            dbExecute("UPDATE mission_teams SET briefing_token = ? WHERE id = ?", [$newBriefingToken, $tid]);
            $teamRef['briefing_token'] = $newBriefingToken;
        }
    }
    unset($teamRef);
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

require_once __DIR__ . '/includes/war-room-layout.php';
$warRoomLayout = ($canManageWarRoom && !$fieldMode)
    ? getWarRoomLayoutForUser((int)$user['id'], $isApprovedParticipant, !empty($teams), !empty($mission['is_special_mission']), $isMissingPersonMission, $weatherCompassOn)
    : null;

$pageTitle = 'Action Room — ' . $mission['title'];
$currentPage = 'war-room';
include __DIR__ . '/includes/header.php';
?>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" integrity="sha384-sHL9NAb7lN7rfvG5lfHpm643Xkcjzp4jFvuavGOndn6pjVqS6ny56CAt3nsEVT4H" crossorigin="anonymous">
<style>
    /* Field-safety touch targets: the SOS/field-status buttons and the
       route depart/arrive/complete/photo/video buttons are what a volunteer
       actually taps, repeatedly, sometimes one-handed/under stress/with
       gloves — Bootstrap's plain .btn-sm (~30px tall) sits below the ~44px
       accessibility minimum. Applied everywhere (not just a mobile media
       query) since a bigger, easier-to-hit button is strictly better on
       desktop too, not just harmless there. */
    .wr-touch-btn { min-height: 44px; padding-top: .55rem; padding-bottom: .55rem; font-size: .95rem; }
    /* Mobile-only collapse: card-body stays a Bootstrap .collapse (hidden
       until the header is clicked) below the lg breakpoint, but d-lg-block
       forces it permanently visible from lg up regardless of collapse
       state — desktop never needed the tap-to-expand, only phones do. */
    .wr-collapsible-header { cursor: pointer; }
    .wr-collapsible-chevron { transition: transform .2s; }
    .wr-collapsible-header:not(.collapsed) .wr-collapsible-chevron { transform: rotate(180deg); }
    /* Drag-and-drop card layout (admin desktop view only) — a flat flex stack
       inside each existing col-lg-8/col-lg-4 column, so mobile reflow (which
       only ever saw one column at a time anyway) is unaffected. */
    .wr-zone { display: flex; flex-direction: column; gap: 1rem; }
    .wr-zone > .card { margin: 0; }
    .wr-zone.wr-unlocked > .card > .card-header,
    .wr-zone.wr-unlocked > .card [data-card-drag-handle] { cursor: grab; }
    .wr-sortable-ghost { opacity: .4; }
    .wr-sortable-drag { box-shadow: 0 8px 24px rgba(0,0,0,.25); }
    /* Show/hide cards (admin desktop view only). A dedicated class rather
       than reusing d-none — several cards toggle their OWN d-none
       independently (e.g. trailEventsCard on trail-mode state), and reusing
       it here would let that unrelated logic silently override this
       preference. !important wins over any inline/utility display too. */
    .wr-card-hidden { display: none !important; }
    #warRoomMap { height: 520px; border-radius: 12px; }
    #mapCard.map-fullscreen-active { position: fixed; inset: 0; z-index: 1040; border-radius: 0; }
    #mapCard.map-fullscreen-active #warRoomMap { height: 100%; border-radius: 0; }
    #mapCard.map-fullscreen-active #warRoomBanner { position: absolute; left: 0; right: 0; bottom: 0; z-index: 600; border-top: 2px solid #dc2626; border-bottom: none; }
    /* Strips Leaflet's default white tooltip box/arrow so only our own colored
       pill (inline-styled per team in dispatchTeamLabelHtml()) shows through. */
    .dispatch-team-label { background: transparent !important; border: none !important; box-shadow: none !important; padding: 0 !important; }
    .dispatch-team-label::before { display: none !important; }
    /* Search-area/sector/restricted-area polygon name labels — same
       box/arrow-stripping as dispatch-team-label above, but its own class
       rather than reusing that one: these are plain text (no inline-styled
       pill of their own to fall back on), so they need their OWN color/
       weight here, and dispatch-team-label's name means "styled for
       dispatch team pills" — piggybacking its color onto that class would
       silently recolor dispatch labels too the day anyone adds one there.
       Red (matches the search-area polygon outline — #dc3545 is already its
       border color) + bold: plain grey text was reported hard to read
       against the map. font-weight 900 (not 700) and 14px (not Leaflet's
       12px default) per direct follow-up feedback that even red+bold-700
       still read too small/light at a glance. */
    .wr-polygon-label { background: transparent !important; border: none !important; box-shadow: none !important; padding: 0 !important; color: #dc3545; font-weight: 900; font-size: 14px; }
    .wr-polygon-label::before { display: none !important; }
    /* Restricted areas are already solid red (border + hatch fill, see
       renderRestrictedAreaLayer) — red text on that background is exactly
       the low-contrast problem wr-polygon-label above was created to fix,
       just recreated in a different way. Orange instead (#fd7e14, this
       project's existing "orange" — already the default team color, see
       schema.sql's volunteer_teams seed row) reuses everything else
       (stripped box/arrow, bold, size) from wr-polygon-label via a second
       class on the same tooltip rather than a full separate copy. Must be
       declared after wr-polygon-label above — same specificity, so source
       order decides which color wins. */
    .wr-polygon-label-restricted { color: #fd7e14; }
    .war-room-hero { background: linear-gradient(135deg, #172554, #b91c1c); color: #fff; border-radius: 14px; }
    .war-room-hero h1 { color: #fff; font-weight: 700; }
    /* The action row can hold up to ~10 buttons for an admin (report, trail,
       coverage, field mode, fullscreen, keep-awake, layout lock, manage
       cards, back) — shrinking them (vs. Bootstrap's default btn size) fits
       noticeably more per row before flex-wrap kicks in, without hiding any
       of them behind a menu. Scoped to this hero only, not a global .btn
       override. */
    .war-room-hero .btn { padding: .3rem .65rem; font-size: .8125rem; }
    .participant-row { border-left: 4px solid #e2e8f0; }
    .participant-row.needs-help { border-left-color: #dc2626; }
    .presence-dot { display: inline-block; width: 9px; height: 9px; border-radius: 50%; margin-right: 4px; }
    .presence-dot.presence-online { background: #28a745; }
    .presence-dot.presence-offline { background: #adb5bd; }
    /* 8+ approved volunteers: split the roster into 2 columns so it fills
       height-wise (8 -> 4+4, 9 -> 5+4, ...) instead of one long scroll.
       CSS grid with an explicit row count (set inline from PHP, since it
       depends on the actual participant count) fills column 1 top-to-
       bottom first, then column 2 — deterministic, unlike column-count's
       height-balanced auto-split, which real testing showed silently
       flips to e.g. 4+5 once row heights vary (guest badges, team
       badges, stale-ping warnings all change a row's height). Phones
       stay single-column below sm — the card is already narrow there
       and a name + timestamp doesn't fit two-up. */
    @media (min-width: 576px) {
        /* .list-group is display:flex (Bootstrap 5's default) — grid
           overrides that here. Confirmed via computed-style inspection
           that flex silently no-ops both column-count AND grid-auto-flow
           unless display is forced off flex first. */
        .wr-participants-cols { display: grid; grid-auto-flow: column; grid-template-columns: 1fr 1fr; column-gap: 20px; }
    }
    #annotationToolbar button.active { background: #1f2937; color: #fff; border-color: #1f2937; }
    #mapCard.wr-draw-active #warRoomMap { cursor: crosshair; }
    #mapCard.wr-draw-active .leaflet-marker-pane,
    #mapCard.wr-draw-active .leaflet-overlay-pane,
    /* sectorPane isn't covered by the two rules above (it's a separate custom
       pane, not markerPane/overlayPane) — without this, a sector polygon's
       popup keeps intercepting clicks underneath an in-progress freehand/
       arrow annotation stroke (or an Add-Building placement click) drawn on
       top of it. NOTE: Leaflet's createPane() strips the literal substring
       "Pane" out of the name when building the class (its own built-in
       'tilePane' becomes .leaflet-tile-pane, not .leaflet-tilePane-pane), so
       our pane named 'sectorPane' renders as .leaflet-sector-pane, not
       .leaflet-sectorPane-pane. Same applies to 'areaPane' below (renders as
       .leaflet-area-pane) — get the class right the first time.
       A SECOND, separate gotcha found live-testing this: pointer-events:none
       on the PANE alone is not enough. Leaflet's own leaflet.css gives every
       vector path a `.leaflet-interactive { pointer-events: ... }` rule
       directly on the <path> element itself — a more specific selector than
       an ancestor's pointer-events, so CSS cascade lets it win over the
       pane's none regardless of ancestry. Must target .leaflet-interactive
       inside these panes explicitly too, with a selector specific enough
       (id + 2 classes + descendant class) to actually beat it. */
    #mapCard.wr-draw-active .leaflet-sector-pane,
    #mapCard.wr-draw-active .leaflet-area-pane,
    #mapCard.wr-draw-active .leaflet-sector-pane .leaflet-interactive,
    #mapCard.wr-draw-active .leaflet-area-pane .leaflet-interactive { pointer-events: none; }
    .wr-anno-arrowhead { width: 0; height: 0; border-left: 8px solid transparent; border-right: 8px solid transparent; border-bottom: 16px solid; filter: drop-shadow(0 1px 2px #0008); }
    .wr-anno-text-label { display: inline-block; padding: 2px 8px; border-radius: 4px; color: #fff; font-weight: 600; font-size: .78rem; white-space: nowrap; box-shadow: 0 1px 3px #0006; }
    .wr-weather-ctl { background: #fff; padding: .5rem .6rem .45rem; }
    .wr-weather-ctl-label { font-size: .6rem; letter-spacing: .05em; text-transform: uppercase; color: #6c757d; text-align: center; margin-bottom: .25rem; }
    .wr-weather-ctl-reading { display: flex; align-items: baseline; justify-content: center; gap: .25rem; margin-top: .25rem; }
    .wr-weather-ctl-reading .v { font-weight: 700; font-size: .9rem; }
    .wr-weather-ctl-reading .u { font-size: .65rem; color: #6c757d; }
    .wr-weather-ctl-sev { margin-top: .3rem; text-align: center; font-size: .62rem; font-weight: 600; padding: .12rem .35rem; border-radius: 4px; }
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
    /* Unacknowledged = maximum drama: full dark-red scrim + rotating beacon +
       scrolling "who's in danger" text, same full-takeover idea as the
       end-of-mission overlay but red and (deliberately) not on a timer — it
       stays until someone acts. Still pointer-events:none like every overlay
       here, so it never blocks command staff from clicking straight through
       to the map or the real Acknowledge/Resolve buttons in the SOS list
       panel underneath. Once acknowledged (sos-calm), it intentionally steps
       back down to the original lightweight corner-glow-only look instead of
       staying full-screen — the "drop everything" moment is over, and staff
       need the map clear to actually coordinate the response. */
    #sosOverlay.sos-active { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 18px; background: rgba(20,2,2,.93); animation: sosPulseCorners 1s ease-in-out infinite; }
    #sosOverlay.sos-calm { display: block; animation: none; box-shadow: inset 0 0 120px 40px rgba(220,38,38,.35); }
    .sos-beacon, .sos-overlay-marquee { display: none; }
    #sosOverlay.sos-active .sos-beacon, #sosOverlay.sos-active .sos-overlay-marquee,
    #restrictedAreaOverlay.ra-active .sos-beacon, #restrictedAreaOverlay.ra-active .sos-overlay-marquee { display: block; }
    /* A real siren graphic (🚨, the standard "revolving light" emoji — a
       single long-established codepoint, unlike the flag emoji sequences
       that are known to render as literal text on Windows/Chrome, see
       volunteer-team badges) instead of a hand-drawn CSS shape, still with
       zero external asset file: same zero-download approach already used
       for the siren sound (Web Audio API, synthesized, no audio file).
       Two independent animations layered on it, deliberately on different
       cycle lengths (0.5s vs 1.1s) so they read as two distinct effects —
       a rotating sweep behind it AND the siren itself strobing — rather
       than one animation that just looks like the other. The sweep spins
       behind the icon (its own layer, ::before) instead of spinning the
       emoji itself, since a spinning "revolving light" glyph reads as a
       tumbling icon rather than a beam sweeping around a fixed light. */
    .sos-beacon { position: relative; width: 130px; height: 130px; border-radius: 50%; background: radial-gradient(circle, #4a0000 0%, #1a0000 72%); box-shadow: 0 0 70px 25px rgba(220,38,38,.55); overflow: hidden; flex-shrink: 0; display: flex; align-items: center; justify-content: center; }
    .sos-beacon::before { content: ''; position: absolute; inset: 0; background: conic-gradient(from 0deg, rgba(255,59,48,0) 0deg, rgba(255,59,48,0) 290deg, rgba(255,140,130,.95) 328deg, rgba(255,59,48,0) 360deg); animation: sosBeaconSpin 1.1s linear infinite; }
    @keyframes sosBeaconSpin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
    .sos-beacon-icon { position: relative; font-size: 70px; line-height: 1; animation: sosBeaconFlash .5s ease-in-out infinite; }
    @keyframes sosBeaconFlash { 0%, 100% { filter: drop-shadow(0 0 14px rgba(255,59,48,.9)) brightness(1); transform: scale(1); } 50% { filter: drop-shadow(0 0 28px rgba(255,110,100,1)) brightness(1.5); transform: scale(1.08); } }
    .sos-overlay-marquee { width: 100%; white-space: nowrap; overflow: hidden; position: relative; height: 1.3em; font-size: clamp(1.2rem, 5.5vw, 2.4rem); }
    .sos-overlay-marquee span { display: inline-block; position: absolute; white-space: nowrap; padding-left: 100%; color: #ff6b60; font-weight: 800; text-transform: uppercase; letter-spacing: .02em; text-shadow: 0 0 20px rgba(255,59,48,.85); animation: warRoomBannerScroll 14s linear infinite; }
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
    /* Sits above #sosOverlay the same way #sosMuteBtn does (own fixed
       position, own z-index) — only shown while sos-active, toggled in
       updateSosAlarmState() alongside the mute button. */
    #sosOverlayCloseBtn {
        position: fixed; top: 16px; right: 16px; z-index: 2001;
        width: 44px; height: 44px; border-radius: 50%; border: 2px solid rgba(255,255,255,.5);
        background: rgba(255,255,255,.12); color: #fff; font-size: 1.6rem; line-height: 1;
        cursor: pointer;
    }
    #sosOverlayCloseBtn:hover { background: rgba(255,255,255,.25); }
    /* End of Mission / Return to Base — a separate overlay from #sosOverlay
       (own element, own class) so it never interferes with real SOS alert
       state. Deliberately GREEN with a full dark scrim (not SOS's red corner
       pulse) — this is an all-clear signal, not an emergency, and should
       read as visually distinct from SOS at a glance, with the message
       scrolling front-and-center instead of tucked in a corner/banner.
       Auto-clears on a timer instead of staying until acked. */
    #returnToBaseOverlay { position: fixed; inset: 0; pointer-events: none; z-index: 2000; display: none; }
    #returnToBaseOverlay.rtb-active { display: flex; align-items: center; justify-content: center; background: rgba(2,20,10,.93); animation: rtbPulseGreen 1s ease-in-out infinite; }
    /* Restricted-area breach — a THIRD independent full-screen overlay (own
       element, own class, never touches sosAlerts/sosOverlay or
       returnToBaseOverlay's own state), rendered for EVERY approved
       participant unconditionally (unlike #sosOverlay, which is command-only
       — see the HTML below), since the primary audience here is the specific
       field volunteer who walked into the zone, not just command staff.
       Reuses #sosOverlay's exact beacon/marquee visual components and
       sosPulseCorners animation (extended above) rather than duplicating
       them — the user explicitly asked for the same intensity as SOS.
       ra-active/ra-calm mirror sos-active/sos-calm's two-tier idea, but
       driven by exited_at/resolved_at, not acknowledged_at (see
       updateRestrictedAreaAlarmState() — calms the instant the volunteer's
       own next trustworthy ping shows them outside the zone, independent of
       whether admin ever acknowledges). */
    #restrictedAreaOverlay { position: fixed; inset: 0; pointer-events: none; z-index: 2000; display: none; }
    #restrictedAreaOverlay.ra-active { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 18px; background: rgba(20,2,2,.93); animation: sosPulseCorners 1s ease-in-out infinite; }
    #restrictedAreaOverlay.ra-calm { display: block; animation: none; box-shadow: inset 0 0 120px 40px rgba(220,38,38,.35); }
    #raMuteBtn {
        position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%);
        z-index: 2001; border: none; border-radius: 999px; padding: .6rem 1.4rem;
        font-weight: 700; font-size: .95rem; box-shadow: 0 4px 16px rgba(0,0,0,.4);
        cursor: pointer;
    }
    #raMuteBtn.sos-mute-offer { background: #fff; color: #dc2626; }
    #raMuteBtn.sos-mute-active { background: #dc2626; color: #fff; }
    #restrictedAreaOverlayCloseBtn {
        position: fixed; top: 16px; right: 16px; z-index: 2001;
        width: 44px; height: 44px; border-radius: 50%; border: 2px solid rgba(255,255,255,.5);
        background: rgba(255,255,255,.12); color: #fff; font-size: 1.6rem; line-height: 1;
        cursor: pointer;
    }
    #restrictedAreaOverlayCloseBtn:hover { background: rgba(255,255,255,.25); }
    @keyframes sosPulseCorners {
        0%, 100% { box-shadow: inset 0 0 60px 20px rgba(220,38,38,.25), inset 0 0 160px 60px rgba(220,38,38,.12); }
        50%      { box-shadow: inset 0 0 120px 50px rgba(220,38,38,.65), inset 0 0 260px 120px rgba(220,38,38,.35); }
    }
    @keyframes rtbPulseGreen {
        0%, 100% { box-shadow: inset 0 0 60px 20px rgba(34,197,94,.3), inset 0 0 160px 60px rgba(34,197,94,.15); }
        50%      { box-shadow: inset 0 0 120px 50px rgba(34,197,94,.7), inset 0 0 260px 120px rgba(34,197,94,.4); }
    }
    .rtb-marquee-track { width: 100%; white-space: nowrap; overflow: hidden; position: relative; height: 1.3em; font-size: clamp(1.8rem, 9vw, 3.5rem); }
    .rtb-marquee-track span { display: inline-block; position: absolute; white-space: nowrap; padding-left: 100%; color: #4ade80; font-weight: 800; text-transform: uppercase; letter-spacing: .03em; text-shadow: 0 0 24px rgba(74,222,128,.85); animation: warRoomBannerScroll 14s linear infinite; }
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
    /* grid-auto-rows defaults to `auto`, which does NOT reliably mean
       "size each row to its tallest item" once #mediaList sits inside a
       fixed-height overflow:auto ancestor with dynamically-inserted (JS,
       not server-rendered) children — Chrome under-measured row height
       (matching the item's now-overridden automatic-minimum-size of 0
       rather than its real min-content size), so row N+1 started
       *before* row N's cards actually ended, painting over their bottom
       (name/GPS/delete row) instead of the panel overflowing to scroll
       past it. Forcing the row-sizing function itself to min-content
       (not just the item's min-height below) is what actually fixes the
       row positions — confirmed live: card top-to-top spacing matched
       real card height only after adding this, not with the item-level
       fix alone. */
    #mediaList { display: grid; grid-template-columns: 1fr 1fr; grid-auto-rows: min-content; gap: .5rem; align-content: start; }
    /* .card's own overflow:hidden (clips card-img-top's square corners to the
       card's rounded ones) gives every grid item here an automatic minimum
       size of 0 per the CSS Grid spec — so once #mediaList's fixed-height
       overflow:auto ancestor runs out of room, rows silently shrink below
       their content's real height (clipping the name/GPS/delete row) instead
       of overflowing and letting the scrollbar do its job. min-height
       overrides that computed 0 back to the content's natural size, which is
       what actually makes #mediaList grow past its container and scroll. */
    #mediaList .card { min-height: min-content; }
</style>

<div class="war-room-hero p-4 mb-4 shadow-sm">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
        <div>
            <div class="text-uppercase small fw-semibold opacity-75 mb-1"><i class="bi bi-broadcast-pin me-1"></i><?= t('hero.eyebrow') ?></div>
            <h1 class="h3 mb-2"><?= h($mission['title']) ?></h1>
            <div class="small opacity-75"><i class="bi bi-geo-alt me-1"></i><?= h($mission['location']) ?> · <?= formatDateTime($firstShift) ?> <?= t('hero.until') ?> <?= formatDateTime($lastShift) ?></div>
        </div>
        <!-- Status + back-to-ops live in their own top-right group, apart
             from the tool-buttons row below — neither is an Action Room
             tool, so mixing them into that row just crowded it. -->
        <div class="d-flex gap-2 align-items-center flex-shrink-0">
            <span class="badge fs-6 <?= $timeState === 'active' ? 'bg-success' : ($timeState === 'upcoming' ? 'bg-info text-dark' : 'bg-warning text-dark') ?>">
                <?= $timeState === 'active' ? t('hero.status_active') : ($timeState === 'upcoming' ? t('hero.status_upcoming') : t('hero.status_overdue')) ?>
            </span>
            <a href="ops-dashboard.php" class="btn btn-light"><i class="bi bi-arrow-left me-1"></i><?= t('hero.btn_back_ops') ?></a>
        </div>
    </div>
    <div class="d-flex gap-1 align-items-center flex-wrap justify-content-end">
        <?php if ($canManageWarRoom && !$fieldMode): ?>
        <button type="button" class="btn btn-outline-light" data-bs-toggle="modal" data-bs-target="#reportModal"><i class="bi bi-stopwatch me-1"></i><?= t('hero.btn_response_report') ?></button>
        <button type="button" id="trailModeToggle" class="btn btn-outline-light"><i class="bi bi-clock-history me-1"></i><?= t('hero.btn_team_trail') ?></button>
        <button type="button" id="coverageModeToggle" class="btn btn-outline-light"><i class="bi bi-broadcast me-1"></i><?= t('hero.btn_verified_coverage') ?></button>
        <!-- Unlike its neighbors above (Team Trail/Verified Coverage, both
             local-only per-browser view toggles), this one is a per-mission
             DB flag — its active/inactive class is re-applied from server
             state on every poll tick (updateFiresToggleBtn()), not just
             flipped locally on click, so it stays correct if a second admin
             toggles it from another session. -->
        <button type="button" id="firesOverlayToggle" class="btn btn-outline-light<?= $firesOverlayOn ? ' active' : '' ?>">🔥 <?= t('hero.btn_fires_overlay') ?></button>
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
        <?php if ($canManageWarRoom && !$fieldMode): ?>
        <button type="button" id="wrLayoutLockToggle" class="btn btn-outline-light"></button>
        <button type="button" class="btn btn-outline-light" data-bs-toggle="modal" data-bs-target="#cardVisibilityModal" title="<?= t('hero.btn_manage_cards') ?>" aria-label="<?= t('hero.btn_manage_cards') ?>">
            <i class="bi bi-gear-fill"></i>
        </button>
        <?php endif; ?>
    </div>
</div>

<?= showFlash() ?>

<div id="warRoomBanner" class="war-room-banner"></div>

<?php if ($canManageWarRoom): ?>
<div id="sosOverlay">
    <div class="sos-beacon"><div class="sos-beacon-icon">🚨</div></div>
    <div class="sos-overlay-marquee"><span id="sosOverlayMarqueeText"></span></div>
</div>
<!-- Local-only siren mute — silences the audio on THIS device for 5 minutes
     without touching the alert itself (no ack/resolve), for the real case of
     "I'm in a meeting/on a call, I can see the SOS is still active on
     screen, I just need it quiet for a few minutes." The visual (pulsing
     corners + marquee) deliberately keeps running even while muted — only
     the sound is ever affected, and only on this one device/tab, not for
     any other command-staff member watching the same mission. -->
<button type="button" id="sosMuteBtn" class="d-none"></button>
<!-- Full-screen SOS takeover (sos-active) is a near-opaque dark scrim over
     the whole viewport — pointer-events:none technically lets clicks reach
     the map/SOS-panel underneath, but staff can't SEE them to click blind.
     This button is the one way back to a usable screen without acknowledging
     anything: it's a purely local/visual dismiss (see sosDismissedAlertIds),
     not a call to mission-sos.php, so the alert stays genuinely open — the
     siren, the SOS list panel, and the small map marquee are all untouched. -->
<button type="button" id="sosOverlayCloseBtn" class="d-none" aria-label="<?= t('sos.close_overlay_btn') ?>" title="<?= t('sos.close_overlay_btn') ?>">&times;</button>
<?php endif; ?>
<!-- Unlike #sosOverlay (command-staff-only, since SOS is a field->command
     incoming alert), this is command->field, so every approved participant
     needs the element regardless of $canManageWarRoom. -->
<div id="returnToBaseOverlay">
    <div class="rtb-marquee-track"><span id="returnToBaseMarqueeText"></span></div>
</div>

<!-- Restricted-area breach — same reasoning as #returnToBaseOverlay just
     above: rendered unconditionally regardless of $canManageWarRoom, since
     the field volunteer who actually walked into the zone is this feature's
     primary audience, not just command staff (who also get it, driven by
     the SAME element/JS but with mission-wide breach data instead of just
     their own — see loadOpenRestrictedAreaBreachesForUser()'s personalization
     and updateRestrictedAreaAlarmState() below). -->
<div id="restrictedAreaOverlay">
    <div class="sos-beacon"><div class="sos-beacon-icon">🚨</div></div>
    <div class="sos-overlay-marquee"><span id="restrictedAreaOverlayMarqueeText"></span></div>
</div>
<button type="button" id="raMuteBtn" class="d-none"></button>
<button type="button" id="restrictedAreaOverlayCloseBtn" class="d-none" aria-label="<?= t('sos.close_overlay_btn') ?>" title="<?= t('sos.close_overlay_btn') ?>">&times;</button>

<!-- Live-data staleness. The footer's generic offline bar only reacts to
     navigator.onLine, which stays true for the genuinely dangerous cases: a
     captive portal, a dropped VPN, Apache down, a phone holding a useless
     bar of signal. In all of those the 5s poll below was failing into a bare
     .catch(() => {}) while the map kept showing pins frozen minutes ago with
     nothing on screen saying so — on an ops console that is worse than an
     obvious error. -->
<div id="pollStaleBanner" class="alert alert-warning d-flex align-items-center gap-2 py-2 px-3 mb-3 d-none" role="status" aria-live="polite"></div>

<?php if ($canManageWarRoom && !$fieldMode): ?>
<!-- Drag-and-drop card layout (admin desktop view only). Starts empty —
     every card below still renders in its normal PHP-conditioned spot; JS
     physically relocates each [data-card-id] node into these two zones
     (never clones), then removes the now-empty .wr-legacy-row containers
     left behind. Any other view ($fieldMode, or a non-admin participant)
     never renders this block, so nothing below is ever touched for them. -->
<div class="row g-4 mb-4">
    <div class="col-12 col-lg-8">
        <div id="wrZoneMain" class="wr-zone"></div>
    </div>
    <div class="col-12 col-lg-4">
        <div id="offlineQueueBanner" class="alert alert-warning py-1 px-2 small mb-2 d-none"></div>
        <div id="offlineQueueFailures"></div>
        <div id="wrZoneSidebar" class="wr-zone"></div>
    </div>
</div>
<?php endif; ?>

<?php if (!$fieldMode): ?>
<div class="row g-4 mb-4 wr-legacy-row">
    <div class="col-12 col-lg-8">
        <div class="card shadow-sm h-100" id="mapCard" data-card-id="mapCard">
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
                        <button type="button" class="btn btn-outline-danger" id="annoToolClearAll" title="<?= t('annotation.tool_clear_all') ?>"><i class="bi bi-trash3"></i></button>
                    </div>
                    <?php endif; ?>
                    <button type="button" id="mapSatelliteToggle" class="btn btn-sm btn-outline-secondary" title="<?= t('map.btn_satellite_view') ?>">
                        <i class="bi bi-globe-americas"></i>
                    </button>
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
        <?php if ($canManageWarRoom): ?>
        <!-- Replay's event-timeline companion to the map above: same
             trailModeActive show/hide lifecycle, filtered to the identical
             cutoffTs on every single render the map itself gets (folded into
             renderTrailUpTo() itself rather than threaded through as a
             parallel call at each of its call sites — a separate call would
             mean a future new call site could update the map and forget the
             log, exactly the class of gap this project's own past audits
             have hit before). -->
        <div class="card shadow-sm mt-3 d-none" id="trailEventsCard" data-card-id="trailEventsCard">
            <div class="card-header"><h6 class="mb-0"><i class="bi bi-list-ul me-1"></i><?= t('trail.events_title') ?></h6></div>
            <div class="card-body p-2" id="trailEventLog" style="max-height:300px;overflow-y:auto;"></div>
        </div>
        <?php endif; ?>
    </div>

    <div class="col-12 col-lg-4">
        <!-- Offline queue status. Sits above every field card rather than inside
             the Route Order one (where it used to live) because the queue now
             also carries field-status/SOS taps, which are reported from the
             card below and can happen on a mission with no route at all.
             Rendered here except for the admin-desktop drag/zone view, which
             already rendered these same two ids once, above, next to
             #wrZoneSidebar — never both, since duplicate ids would break
             every getElementById() lookup that targets them. -->
        <?php if (!($canManageWarRoom && !$fieldMode)): ?>
        <div id="offlineQueueBanner" class="alert alert-warning py-1 px-2 small mb-2 d-none"></div>
        <div id="offlineQueueFailures"></div>
        <?php endif; ?>

        <div class="card shadow-sm mb-4 border-primary" data-card-id="myLocationCard">
            <div class="card-header bg-primary text-white"><h5 class="mb-0"><i class="bi bi-geo-alt-fill me-1"></i><?= t('myping.panel_title') ?></h5></div>
            <div class="card-body">
                <?php if (empty($myAssignments)): ?>
                    <p class="text-muted mb-0"><?= t('myping.no_shift') ?></p>
                <?php else: ?>
                    <?php foreach ($myAssignments as $assignment): ?>
                    <button type="button" class="btn btn-primary w-100 mb-2 send-ping" data-shift-id="<?= $assignment['shift_id'] ?>" data-pr-id="<?= $assignment['pr_id'] ?>">
                        <i class="bi bi-send-fill me-1"></i><?= t('myping.send_btn') ?>
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
                    <?php $autoPingSeconds = (int) getSetting('war_room_auto_ping_seconds', '180'); ?>
                    <p class="small text-muted mb-0"><?= $autoPingSeconds >= 60
                        ? t('myping.auto_note_minutes', ['n' => (int) round($autoPingSeconds / 60)])
                        : t('myping.auto_note_seconds', ['n' => $autoPingSeconds]) ?></p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($isApprovedParticipant): ?>
<div class="row g-4 mb-4 wr-legacy-row">
    <div class="col-12 col-md-6">
        <div class="card shadow-sm h-100 border-warning" data-card-id="shortageFormCard">
            <div class="card-header bg-warning bg-opacity-25 wr-collapsible-header" data-bs-toggle="collapse" data-bs-target="#shortageFormCollapse" role="button" aria-expanded="false" aria-controls="shortageFormCollapse">
                <h5 class="mb-0 d-flex justify-content-between align-items-center"><span><i class="bi bi-exclamation-triangle-fill me-1"></i><?= t('shortage.card_title') ?></span><i class="bi bi-chevron-down d-lg-none wr-collapsible-chevron"></i></h5>
            </div>
            <div class="card-body collapse d-lg-block" id="shortageFormCollapse">
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
    </div>

    <div class="col-12 col-md-6">
        <div class="card shadow-sm h-100 border-danger" data-card-id="incidentFormCard">
            <div class="card-header bg-danger bg-opacity-10 wr-collapsible-header" data-bs-toggle="collapse" data-bs-target="#incidentFormCollapse" role="button" aria-expanded="false" aria-controls="incidentFormCollapse">
                <h5 class="mb-0 d-flex justify-content-between align-items-center"><span><i class="bi bi-heart-pulse-fill me-1 text-danger"></i><?= t('incident.card_title') ?></span><i class="bi bi-chevron-down d-lg-none wr-collapsible-chevron"></i></h5>
            </div>
            <div class="card-body collapse d-lg-block" id="incidentFormCollapse">
                <form method="post" id="incidentReportForm">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="report_incident">
                    <input type="hidden" name="lat" id="incidentLat" value="">
                    <input type="hidden" name="lng" id="incidentLng" value="">
                    <label class="form-label small fw-semibold"><?= t('incident.type_label') ?></label>
                    <select name="incident_type" class="form-select mb-2" required>
                        <?php foreach (INCIDENT_TYPE_LABELS as $val => $label): ?>
                        <option value="<?= h($val) ?>"><?= h(incidentTypeLabel($val)) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label class="form-label small fw-semibold"><?= t('incident.severity_label') ?></label>
                    <select name="severity" class="form-select mb-2" required>
                        <?php foreach (SHORTAGE_SEVERITY_LABELS as $val => $label): ?>
                        <option value="<?= h($val) ?>" <?= $val === 'medium' ? 'selected' : '' ?>><?= h(incidentSeverityLabel($val)) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-check mb-2">
                        <input type="checkbox" class="form-check-input" name="is_unknown_patient" value="1" id="incidentUnknownPatient">
                        <label class="form-check-label small" for="incidentUnknownPatient"><?= t('incident.unknown_patient_label') ?></label>
                    </div>
                    <div id="incidentPatientFields">
                        <input type="text" name="patient_name" class="form-control mb-2" maxlength="255" placeholder="<?= t('incident.patient_name_placeholder') ?>">
                        <input type="tel" name="phone" class="form-control mb-2" maxlength="30" placeholder="<?= t('incident.phone_placeholder') ?>">
                    </div>
                    <input type="text" name="estimated_age" class="form-control mb-2" maxlength="50" placeholder="<?= t('incident.age_placeholder') ?>">
                    <select name="gender" class="form-select mb-2">
                        <option value=""><?= t('incident.gender_placeholder') ?></option>
                        <?php foreach (INCIDENT_GENDER_LABELS as $val => $label): ?>
                        <option value="<?= h($val) ?>"><?= h(incidentGenderLabel($val)) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <textarea name="notes" class="form-control mb-2" rows="2" maxlength="2000" placeholder="<?= t('incident.notes_placeholder') ?>"></textarea>
                    <div class="small text-muted mb-2"><?= t('incident.staff_only_hint') ?></div>
                    <button type="submit" class="btn btn-danger w-100 fw-semibold"><i class="bi bi-send-fill me-1"></i><?= t('incident.submit_btn') ?></button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (($canManageWarRoom || $isApprovedParticipant) && !$fieldMode): ?>
<?php
// Shortage is admin-only, so whenever it's actually present there are
// guaranteed to be 3 cards in this row (it + incidents + POI, both of the
// latter always shown to everyone) — a 3-way split. Without it there are
// only 2 (incidents + POI). Incidents/POI share this same formula since
// both are always shown together, unlike shortage.
$actionRoomListColClass = $canManageWarRoom ? 'col-12 col-md-4' : 'col-12 col-md-6';
?>
<div class="row g-4 mb-4 wr-legacy-row">
    <?php if ($canManageWarRoom): ?>
    <div class="<?= $actionRoomListColClass ?>">
        <div class="card shadow-sm h-100 border-danger" data-card-id="shortageListCard">
            <div class="card-header bg-danger bg-opacity-10 wr-collapsible-header" data-bs-toggle="collapse" data-bs-target="#shortageListCollapse" role="button" aria-expanded="false" aria-controls="shortageListCollapse">
                <h5 class="mb-0 d-flex justify-content-between align-items-center"><span><i class="bi bi-exclamation-triangle-fill me-1 text-danger"></i><?= t('shortage.list_panel_title') ?></span><i class="bi bi-chevron-down wr-collapsible-chevron"></i></h5>
            </div>
            <div class="card-body collapse" id="shortageListCollapse">
                <div id="shortageReportsList"></div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <div class="<?= $actionRoomListColClass ?>">
        <div class="card shadow-sm h-100 border-danger" data-card-id="incidentsListCard">
            <div class="card-header bg-danger bg-opacity-10 wr-collapsible-header" data-bs-toggle="collapse" data-bs-target="#incidentsListCollapse" role="button" aria-expanded="false" aria-controls="incidentsListCollapse">
                <h5 class="mb-0 d-flex justify-content-between align-items-center"><span><i class="bi bi-heart-pulse-fill me-1 text-danger"></i><?= t('incident.list_panel_title') ?></span><i class="bi bi-chevron-down wr-collapsible-chevron"></i></h5>
            </div>
            <div class="card-body collapse" id="incidentsListCollapse">
                <div id="incidentsList"></div>
            </div>
        </div>
    </div>
    <div class="<?= $actionRoomListColClass ?>">
        <div class="card shadow-sm h-100 border-primary" data-card-id="poiListCard">
            <div class="card-header bg-primary bg-opacity-10 wr-collapsible-header" data-bs-toggle="collapse" data-bs-target="#poiListCollapse" role="button" aria-expanded="false" aria-controls="poiListCollapse">
                <h5 class="mb-0 d-flex justify-content-between align-items-center"><span><i class="bi bi-search me-1 text-primary"></i><?= t('poi.list_panel_title') ?></span><i class="bi bi-chevron-down d-lg-none wr-collapsible-chevron"></i></h5>
            </div>
            <div class="card-body collapse d-lg-block" id="poiListCollapse">
                <div id="poiList"></div>
            </div>
        </div>
    </div>
</div>

<!-- Own full-width row rather than a 4th column squeezed into the row
     above — sectors carry more visual content per row (nested buildings)
     than shortage/incident/POI entries do, so the extra width earns its
     keep here. -->
<div class="row g-4 mb-4 wr-legacy-row">
    <div class="col-12">
        <div class="card shadow-sm border-primary" data-card-id="sectorsListCard">
            <div class="card-header bg-primary bg-opacity-10 wr-collapsible-header" data-bs-toggle="collapse" data-bs-target="#sectorsListCollapse" role="button" aria-expanded="false" aria-controls="sectorsListCollapse">
                <h5 class="mb-0 d-flex justify-content-between align-items-center"><span><i class="bi bi-grid-3x3-gap-fill me-1 text-primary"></i><?= t('sector.list_panel_title') ?></span><i class="bi bi-chevron-down wr-collapsible-chevron"></i></h5>
            </div>
            <div class="card-body collapse" id="sectorsListCollapse">
                <div id="sectorsList"></div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="row g-4">
    <?php if (!$fieldMode): ?>
    <div class="col-12 col-lg-8 wr-legacy-row">
        <div class="card shadow-sm mb-4" data-card-id="teamsCard">
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
                <div class="list-group-item" data-team-id="<?= $team['id'] ?>">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                        <div class="wr-team-roster">
                            <span class="badge fs-6 me-2" style="background:<?= h($teamBg) ?>;color:<?= h($teamFg) ?>;"><?= h(teamLabel($team['codename'], $team['team_number'])) ?></span>
                            <?php if ($team['leader_name']): ?>
                            <span class="small text-muted"><i class="bi bi-star-fill text-warning me-1"></i><?= h($team['leader_name']) ?></span>
                            <?= homeTeamCornerBadgeHtml($team['leader_home_team_name'], $team['leader_home_team_color'], $team['leader_is_external'], $team['leader_guest_country_code']) ?>
                            <?php endif; ?>
                            <div class="small mt-2">
                                <?php foreach ($team['members'] as $member): ?>
                                <span class="badge bg-light text-dark border me-1 mb-1"><?= guestNameHtml($member['name'], $member['is_external'], $member['home_team_name'], $member['home_team_color'], $member['guest_country_code']) ?><?= $member['user_id'] === $team['leader_id'] ? ' ⭐' : '' ?></span>
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

        <div class="card shadow-sm" data-card-id="participantsCard">
            <div class="card-header wr-collapsible-header" data-bs-toggle="collapse" data-bs-target="#participantsCollapse" role="button" aria-expanded="false" aria-controls="participantsCollapse">
                <h5 class="mb-0 d-flex justify-content-between align-items-center"><span><i class="bi bi-people me-1"></i><?= t('participants.panel_title', ['count' => count($participants)]) ?></span><i class="bi bi-chevron-down wr-collapsible-chevron"></i></h5>
            </div>
            <?php $participantSplitRows = count($participants) >= 8 ? (int)ceil(count($participants) / 2) : 0; ?>
            <div class="list-group list-group-flush collapse<?= $participantSplitRows ? ' wr-participants-cols' : '' ?>" id="participantsCollapse"<?= $participantSplitRows ? ' style="grid-template-rows: repeat(' . $participantSplitRows . ', auto);"' : '' ?>>
                <?php foreach ($participants as $participant): ?>
                <?php
                $status = $participant['field_status'] ?? '';
                $fatigueMinutes = $continuousFieldMinutesByVolunteerId[(int)$participant['volunteer_id']] ?? null;
                $isFatigued = $fatigueMinutes !== null && $fatigueMinutes > $warRoomMaxShiftMinutes;
                $isCriticalFatigue = $isFatigued && $fatigueMinutes >= $warRoomCriticalShiftMinutes;
                $fatigueH = $fatigueMinutes !== null ? intdiv($fatigueMinutes, 60) : 0;
                $fatigueM = $fatigueMinutes !== null ? $fatigueMinutes % 60 : 0;
                ?>
                <div class="list-group-item participant-row <?= $status === 'needs_help' ? 'needs-help' : '' ?> d-flex justify-content-between align-items-center gap-2 flex-wrap" id="participant-row-<?= (int)$participant['volunteer_id'] ?>">
                    <div><span id="presence-<?= (int)$participant['volunteer_id'] ?>" class="presence-dot <?= (in_array((int)$participant['volunteer_id'], $onlinePresenceIds, true) || (!empty($participant['last_ping_at']) && !$pingIsStaleByVolunteerId[(int)$participant['volunteer_id']])) ? 'presence-online' : 'presence-offline' ?>" title="<?= (in_array((int)$participant['volunteer_id'], $onlinePresenceIds, true) || (!empty($participant['last_ping_at']) && !$pingIsStaleByVolunteerId[(int)$participant['volunteer_id']])) ? t('common.online') : t('common.offline') ?>"></span><strong><?= guestNameHtml($participant['name'], (bool)$participant['is_external'], $participant['home_team_name'], $participant['home_team_color'], $participant['guest_country_code']) ?></strong><?php if (isset($teamLabelByUserId[(int)$participant['volunteer_id']])): [$pBg, $pFg] = teamBadgeColors($teamColorByUserId[(int)$participant['volunteer_id']] ?? null); ?> <span class="badge" style="background:<?= h($pBg) ?>;color:<?= h($pFg) ?>;"><?= h($teamLabelByUserId[(int)$participant['volunteer_id']]) ?></span><?php endif; ?><br><small class="text-muted"><?= formatDateTime($participant['start_time']) ?> – <?= date('H:i', strtotime($participant['end_time'])) ?><span id="ping-time-<?= (int)$participant['volunteer_id'] ?>"><?= $participant['last_ping_at'] ? t('participants.last_ping_label', ['time' => formatDateTime($participant['last_ping_at'], 'H:i d/m/Y')]) : t('participants.no_ping') ?></span><span id="ping-stale-<?= (int)$participant['volunteer_id'] ?>" class="text-warning <?= (!empty($participant['last_ping_at']) && $pingIsStaleByVolunteerId[(int)$participant['volunteer_id']]) ? '' : 'd-none' ?>" title="<?= t('participants.stale_ping_title') ?>"><i class="bi bi-exclamation-triangle-fill"></i><?= t('participants.stale_ping_suffix') ?></span> <span id="fatigue-badge-<?= (int)$participant['volunteer_id'] ?>" class="<?= $isCriticalFatigue ? 'text-danger' : 'text-warning' ?> <?= $isFatigued ? '' : 'd-none' ?>" title="<?= t('fatigue.tooltip') ?>"><i class="bi bi-clock-history"></i> <?= t('fatigue.badge_label', ['h' => $fatigueH, 'm' => $fatigueM]) ?></span></small></div>
                    <span class="badge <?= $status === 'needs_help' ? 'bg-danger' : ($status === 'on_site' ? 'bg-success' : ($status === 'on_way' ? 'bg-warning text-dark' : 'bg-secondary')) ?>" id="status-badge-<?= (int)$participant['volunteer_id'] ?>">
                        <?= $status === 'needs_help' ? t('status.badge_needs_help') : ($status === 'on_site' ? t('status.badge_on_site') : ($status === 'on_way' ? t('status.badge_on_way') : t('status.badge_none'))) ?>
                    </span>
                    <?php if ($canManageWarRoom): ?>
                    <button type="button" class="btn btn-sm btn-outline-danger suggest-replacement-btn <?= $isFatigued ? '' : 'd-none' ?>" id="suggest-replacement-btn-<?= (int)$participant['volunteer_id'] ?>" data-volunteer-id="<?= (int)$participant['volunteer_id'] ?>" data-volunteer-name="<?= h($participant['name']) ?>" title="<?= t('fatigue.suggest_replacement_btn') ?>">
                        <i class="bi bi-arrow-left-right"></i>
                    </button>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
                <?php if (empty($participants)): ?><div class="list-group-item text-muted"><?= t('participants.empty') ?></div><?php endif; ?>
            </div>
        </div>

        <?php if ($canManageWarRoom): ?>
        <div class="row g-4 mt-0">
            <div class="col-12 col-md-6">
                <div class="card shadow-sm h-100 border-warning" data-card-id="requestLocationCard">
                    <div class="card-header bg-warning bg-opacity-25 wr-collapsible-header" data-bs-toggle="collapse" data-bs-target="#requestLocationCollapse" role="button" aria-expanded="false" aria-controls="requestLocationCollapse">
                        <h5 class="mb-0 d-flex justify-content-between align-items-center"><span><i class="bi bi-bell-fill me-1"></i><?= t('request.location.card_title') ?></span><i class="bi bi-chevron-down wr-collapsible-chevron"></i></h5>
                    </div>
                    <div class="card-body collapse" id="requestLocationCollapse">
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
                                        <small class="text-muted"><?= $participant['last_ping_at'] ? formatDateTime($participant['last_ping_at'], 'H:i d/m/Y') : t('common.no_ping_short') ?></small>
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
                <div class="card shadow-sm h-100 border-warning" data-card-id="requestPhotoCard">
                    <div class="card-header bg-warning bg-opacity-25 wr-collapsible-header" data-bs-toggle="collapse" data-bs-target="#requestPhotoCollapse" role="button" aria-expanded="false" aria-controls="requestPhotoCollapse">
                        <h5 class="mb-0 d-flex justify-content-between align-items-center"><span><i class="bi bi-camera-fill me-1"></i><?= t('request.photo.card_title') ?></span><i class="bi bi-chevron-down wr-collapsible-chevron"></i></h5>
                    </div>
                    <div class="card-body collapse" id="requestPhotoCollapse">
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
                <div class="card shadow-sm h-100 border-warning" data-card-id="requestVideoCard">
                    <div class="card-header bg-warning bg-opacity-25 wr-collapsible-header" data-bs-toggle="collapse" data-bs-target="#requestVideoCollapse" role="button" aria-expanded="false" aria-controls="requestVideoCollapse">
                        <h5 class="mb-0 d-flex justify-content-between align-items-center"><span><i class="bi bi-camera-reels-fill me-1"></i><?= t('request.video.card_title') ?></span><i class="bi bi-chevron-down wr-collapsible-chevron"></i></h5>
                    </div>
                    <div class="card-body collapse" id="requestVideoCollapse">
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
                <div class="card shadow-sm h-100 border-warning" data-card-id="requestTaskCard">
                    <div class="card-header bg-warning bg-opacity-25 wr-collapsible-header" data-bs-toggle="collapse" data-bs-target="#requestTaskCollapse" role="button" aria-expanded="false" aria-controls="requestTaskCollapse">
                        <h5 class="mb-0 d-flex justify-content-between align-items-center"><span><i class="bi bi-clipboard-check-fill me-1"></i><?= t('request.task.card_title') ?></span><i class="bi bi-chevron-down wr-collapsible-chevron"></i></h5>
                    </div>
                    <div class="card-body collapse" id="requestTaskCollapse">
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

        <div class="card shadow-sm mt-4" data-card-id="activityCard">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0 wr-collapsible-header" data-bs-toggle="collapse" data-bs-target="#activityCollapse" role="button" aria-expanded="false" aria-controls="activityCollapse"><i class="bi bi-activity me-1"></i><?= t('activity.panel_title') ?><i class="bi bi-chevron-down wr-collapsible-chevron ms-1"></i></h5>
                <div class="d-flex align-items-center gap-2">
                    <a href="exports/export-mission-activity.php?mission_id=<?= $missionId ?>" class="btn btn-sm btn-outline-secondary" title="<?= t('activity.export_btn') ?>">
                        <i class="bi bi-file-earmark-excel me-1"></i><?= t('activity.export_btn') ?>
                    </a>
                    <small class="text-muted"><?= t('common.updated_label') ?> <span id="activityRefresh"></span></small>
                </div>
            </div>
            <div class="card-body collapse" id="activityCollapse">
                <div id="activityList" style="max-height:420px;overflow-y:auto;"><div class="text-muted small"><?= t('common.loading') ?></div></div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="<?= $fieldMode ? 'col-12 col-lg-6 mx-auto' : 'col-12 col-lg-4' ?>">
        <div class="card shadow-sm h-100" data-card-id="mediaCard">
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
                <!-- Camera capture only, no gallery variant — a Point of
                     Interest is "I found this right here, right now"; an
                     old photo picked from the gallery would attach today's
                     GPS to a find from god-knows-when/where, which defeats
                     the entire point of the feature. -->
                <div class="mb-2">
                    <input type="text" id="poiNoteInput" class="form-control form-control-sm mb-1" maxlength="500" placeholder="<?= t('poi.note_placeholder') ?>">
                    <label class="btn btn-outline-danger w-100 mb-0">
                        <i class="bi bi-search me-1"></i><?= t('poi.capture_btn') ?>
                        <input type="file" id="poiCaptureInput" accept="image/*" capture="environment" class="d-none">
                    </label>
                    <button type="button" id="poiSendBtn" class="btn btn-danger w-100 mt-1 d-none" disabled></button>
                </div>
                <div class="small mb-2" id="mediaUploadStatus"></div>
                <?php endif; ?>
                <div id="mediaList" class="flex-grow-1 overflow-auto"></div>
            </div>
        </div>

        <!-- Weather (+ exposure-urgency, missing-person missions only): any
             mission type, gated behind its own weather_map_compass_enabled
             Settings toggle (default off) — independent of the exposure
             toggle, which only affects whether exposureUrgency is non-null.
             Placed before the missing-person card so general conditions
             read before person-specific ones. Starts hidden via inline
             style, not a PHP if, when there's no forecast yet on first
             paint (no API key, cache cold) — JS shows/hides it exactly like
             the poll loop keeps it in sync afterwards. -->
        <?php if ($weatherCompassOn): ?>
        <div class="card shadow-sm mb-4" data-card-id="weatherCard" id="weatherCardWrap" style="<?= $weather ? '' : 'display:none;' ?>">
            <div class="card-header d-flex justify-content-between align-items-center" id="weatherCardHeader">
                <h5 class="mb-0"><i class="bi bi-cloud-sun me-1"></i><?= t('weather.card_title') ?></h5>
            </div>
            <div class="card-body" id="weatherCardDisplay"></div>
        </div>
        <?php endif; ?>

        <!-- Missing-person profile: structured, pinned card for the
             "Αναζήτηση Αγνοουμένου" mission type — distinct from the
             free-text briefingCard. Unconditional visibility (like My
             Location/broadcastPhotoCard right below it): shows in both
             Field Mode and full view to every approved participant, not just
             admins, since every field volunteer needs to know who they're
             looking for. Only $canManageWarRoom gets the edit trigger/modal. -->
        <?php if ($isMissingPersonMission): ?>
        <div class="card shadow-sm mb-4 border-danger" data-card-id="missingPersonCard">
            <div class="card-header bg-danger text-white d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center gap-2">
                    <h5 class="mb-0"><i class="bi bi-person-bounding-box me-1"></i><?= t('missing_person.card_title') ?></h5>
                    <a href="missing-person-guide.php?mission_id=<?= $missionId ?>" target="_blank" class="text-white" title="<?= h(t('guide.link_label')) ?>">
                        <i class="bi bi-question-circle"></i>
                    </a>
                </div>
                <?php if ($canManageWarRoom): ?>
                <button type="button" class="btn btn-sm btn-light" data-bs-toggle="modal" data-bs-target="#missingPersonEditModal">
                    <i class="bi bi-pencil"></i> <?= t('missing_person.edit_btn') ?>
                </button>
                <?php endif; ?>
            </div>
            <div class="card-body" id="missingPersonDisplay"></div>
        </div>

        <?php if ($canManageWarRoom): ?>
        <div class="modal fade" id="missingPersonEditModal" tabindex="-1">
            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                <div class="modal-content">
                    <!-- display:contents so this <form> wrapper doesn't interpose in
                         .modal-content's flex layout — modal-dialog-scrollable relies
                         on .modal-header/.modal-body/.modal-footer being direct flex
                         children of .modal-content (that's what makes .modal-body the
                         one part that scrolls, keeping the footer/save button fixed
                         in view); a <form> wrapping them here would otherwise become
                         that flex child instead, and it isn't itself a flex container,
                         so .modal-body's flex:1 1 auto has nothing to size against —
                         it renders at full natural height and everything past the
                         dialog's height limit just gets clipped by modal-content's
                         overflow:hidden, save button included. The form's own submit
                         behavior is unaffected; display:contents only removes its box
                         from the layout/paint tree, not its DOM/event semantics. -->
                    <form method="post" enctype="multipart/form-data" id="missingPersonForm" style="display:contents;">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="save_missing_person_info">
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="bi bi-person-bounding-box me-1"></i><?= t('missing_person.card_title') ?></h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label small fw-semibold"><?= t('missing_person.full_name_label') ?> *</label>
                                <input type="text" class="form-control" name="full_name" maxlength="255" required value="<?= h($missingPerson['full_name'] ?? '') ?>">
                            </div>
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label class="form-label small fw-semibold"><?= t('missing_person.age_label') ?></label>
                                    <input type="number" class="form-control" name="age" min="0" max="150" value="<?= h((string)($missingPerson['age'] ?? '')) ?>">
                                </div>
                                <div class="col-md-8 mb-3">
                                    <label class="form-label small fw-semibold"><?= t('missing_person.photo_label') ?></label>
                                    <input type="file" class="form-control" name="photo" accept="image/jpeg,image/png,image/gif,image/webp">
                                    <?php if (!empty($missingPerson['photo'])): ?>
                                    <div class="form-text"><?= t('missing_person.photo_replace_note') ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label small fw-semibold"><?= t('missing_person.subject_category_label') ?></label>
                                <select class="form-select" name="subject_category">
                                    <option value=""><?= t('missing_person.subject_category_placeholder') ?></option>
                                    <?php foreach (LPB_RING_TABLE as $key => $radii): ?>
                                    <option value="<?= h($key) ?>" <?= ($missingPerson['subject_category'] ?? '') === $key ? 'selected' : '' ?>><?= h(lpbCategoryLabel($key)) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text"><?= t('missing_person.subject_category_help') ?></div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label small fw-semibold"><?= t('missing_person.description_label') ?></label>
                                <textarea class="form-control" name="description" rows="2" maxlength="5000"><?= h($missingPerson['description'] ?? '') ?></textarea>
                            </div>
                            <div class="mb-3">
                                <label class="form-label small fw-semibold"><?= t('missing_person.clothing_label') ?></label>
                                <textarea class="form-control" name="clothing_description" rows="2" maxlength="2000"><?= h($missingPerson['clothing_description'] ?? '') ?></textarea>
                            </div>
                            <div class="mb-3">
                                <label class="form-label small fw-semibold"><?= t('missing_person.vehicle_label') ?></label>
                                <input type="text" class="form-control" name="vehicle" maxlength="255" value="<?= h($missingPerson['vehicle'] ?? '') ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label small fw-semibold"><?= t('missing_person.last_seen_place_label') ?></label>
                                <input type="text" class="form-control" name="last_seen_label" maxlength="255" value="<?= h($missingPerson['last_seen_label'] ?? '') ?>">
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label small fw-semibold"><?= t('missing_person.last_seen_at_label') ?></label>
                                    <input type="datetime-local" class="form-control" name="last_seen_at" value="<?= h($missingPerson['last_seen_at_raw'] ?? '') ?>">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label small fw-semibold"><?= t('missing_person.last_seen_location_label') ?></label>
                                    <?php if (!$fieldMode): ?>
                                    <div>
                                        <button type="button" class="btn btn-outline-primary btn-sm" id="missingPersonPickOnMapBtn"><i class="bi bi-crosshair"></i> <?= t('missing_person.pick_on_map_btn') ?></button>
                                    </div>
                                    <div class="small text-muted mt-1" id="missingPersonLocationPreview"></div>
                                    <?php else: ?>
                                    <div class="small text-muted"><?= t('missing_person.location_needs_full_view') ?></div>
                                    <?php endif; ?>
                                    <input type="hidden" name="last_seen_lat" id="missingPersonLat" value="<?= h((string)($missingPerson['last_seen_lat'] ?? '')) ?>">
                                    <input type="hidden" name="last_seen_lng" id="missingPersonLng" value="<?= h((string)($missingPerson['last_seen_lng'] ?? '')) ?>">
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label small fw-semibold"><?= t('missing_person.circumstances_label') ?></label>
                                <textarea class="form-control" name="disappearance_circumstances" rows="2" maxlength="3000"><?= h($missingPerson['disappearance_circumstances'] ?? '') ?></textarea>
                            </div>
                            <div class="mb-3">
                                <label class="form-label small fw-semibold"><?= t('missing_person.likely_direction_label') ?></label>
                                <input type="text" class="form-control" name="likely_direction" maxlength="255" value="<?= h($missingPerson['likely_direction'] ?? '') ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label small fw-semibold"><?= t('missing_person.witness_accounts_label') ?></label>
                                <textarea class="form-control" name="witness_accounts" rows="3" maxlength="5000"><?= h($missingPerson['witness_accounts'] ?? '') ?></textarea>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= t('common.cancel') ?></button>
                            <button type="submit" class="btn btn-primary"><?= t('common.save') ?></button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Dedicated map picker for the last-known-location field above —
             mirrors dispatchMapModal's own pattern (fullscreen, own lazily-
             initialized Leaflet map, satellite toggle, renderFullMapReference
             context layer) instead of reusing/hijacking the page's main map,
             so picking a point doesn't require finding+scrolling to the main
             map card first. Field-mode-gated like every other map-drawing
             feature (the trigger button above already is too; this also
             skips shipping an unusable map container in that mode's DOM). -->
        <?php if (!$fieldMode): ?>
        <div class="modal fade" id="missingPersonPickMapModal" tabindex="-1">
            <div class="modal-dialog modal-fullscreen">
                <div class="modal-content">
                    <div class="modal-header py-2">
                        <h5 class="modal-title"><i class="bi bi-geo-alt-fill me-1"></i><?= t('missing_person.last_seen_location_label') ?></h5>
                        <button type="button" class="btn btn-sm btn-outline-secondary me-2" id="missingPersonPickSatelliteToggle" title="<?= t('map.btn_satellite_view') ?>"><i class="bi bi-globe-americas"></i></button>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body p-0 d-flex flex-column">
                        <div class="small text-muted px-2 py-1 bg-light border-bottom">
                            <?= t('missing_person.pick_map_instructions') ?>
                        </div>
                        <div id="missingPersonPickMap" style="flex:1;min-height:0;"></div>
                        <div class="p-2 border-top d-flex gap-2 justify-content-end bg-light">
                            <button type="button" class="btn btn-outline-secondary btn-sm" id="missingPersonPickUseCenterBtn"><?= t('missing_person.use_map_center_btn') ?></button>
                            <button type="button" class="btn btn-success btn-sm" id="missingPersonPickConfirmBtn" disabled><i class="bi bi-check-lg me-1"></i><?= t('missing_person.pick_confirm_btn') ?></button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>
        <?php endif; ?>

        <!-- Broadcast reference photo (e.g. a missing person's photo relayed
             to the coordination center) — read-only here, sent via the
             Καθολικό Μήνυμα composer further down (command staff only).
             Unconditional card (like My Location/Nearby/Route/Tasks): shows
             in both Field Mode and full view, since field volunteers out
             searching are exactly who most need to see it. Deliberately its
             own card, never merged into "Φωτογραφίες Πεδίου" (mediaCard) —
             that gallery is field-to-coordinator, this is the opposite
             direction, coordinator-to-field. -->
        <div class="card shadow-sm mb-4 border-danger" data-card-id="broadcastPhotoCard">
            <div class="card-header bg-danger bg-opacity-10"><h5 class="mb-0"><i class="bi bi-image-fill me-1 text-danger"></i><?= t('broadcast_photo.card_title') ?></h5></div>
            <div class="card-body">
                <div id="broadcastPhotoList"><p class="text-muted mb-0 small"><?= t('broadcast_photo.empty') ?></p></div>
            </div>
        </div>

        <!-- Field Mode has no map at all (see the !$fieldMode wrap further up),
             so this is the only place a field volunteer sees where other teams
             are — a plain distance+direction list instead of pins on a map.
             Unconditional card (like My Ping/Route/Tasks below it): shows in
             both Field Mode and full view, content degrades gracefully via
             renderNearbyTeams() when there's no data yet. -->
        <div class="card shadow-sm mb-4 border-primary" data-card-id="nearbyTeamsCard">
            <div class="card-header bg-primary text-white"><h5 class="mb-0"><i class="bi bi-compass me-1"></i><?= t('nearby.panel_title') ?></h5></div>
            <div class="card-body">
                <div id="nearbyTeamsList"></div>
            </div>
        </div>

        <!-- Field Mode's only restricted-area awareness (see the !$fieldMode
             map wrap further up) — a plain distance list mirroring
             nearbyTeamsCard just above, instead of seeing the red zone
             directly on a map that doesn't exist here. Unconditional, same
             as nearbyTeamsCard: shows in both Field Mode and full view. -->
        <div class="card shadow-sm mb-4 border-danger" data-card-id="restrictedAreaProximityCard">
            <div class="card-header bg-danger bg-opacity-10"><h5 class="mb-0"><i class="bi bi-exclamation-triangle-fill me-1"></i><?= t('restricted_area.proximity_card_title') ?></h5></div>
            <div class="card-body">
                <div id="restrictedAreaProximityList"></div>
            </div>
        </div>

        <div class="card shadow-sm mb-4 border-primary" data-card-id="myRouteCard">
            <div class="card-header bg-primary text-white"><h5 class="mb-0"><i class="bi bi-signpost-split-fill me-1"></i><?= t('route.my_panel_title') ?></h5></div>
            <div class="card-body">
                <div id="myRoutesList"><p class="text-muted mb-0"><?= t('route.my_empty') ?></p></div>
            </div>
        </div>

        <div class="card shadow-sm mb-4 border-primary" data-card-id="myTasksCard">
            <div class="card-header bg-primary text-white"><h5 class="mb-0"><i class="bi bi-clipboard-check me-1"></i><?= t('mytasks.panel_title') ?></h5></div>
            <div class="card-body">
                <div id="myTasksList"></div>
            </div>
        </div>

        <!-- Unconditional, same as myTasksCard above: shows in both Field
             Mode and full view, since this is the primary surface for a
             volunteer physically walking a sector's buildings — no map
             involved, just the nested checklist. -->
        <div class="card shadow-sm mb-4 border-primary" data-card-id="mySectorsCard">
            <div class="card-header bg-primary text-white"><h5 class="mb-0"><i class="bi bi-grid-3x3-gap-fill me-1"></i><?= t('sector.my_panel_title') ?></h5></div>
            <div class="card-body">
                <div id="mySectorsList"></div>
            </div>
        </div>

        <?php if (!$fieldMode): ?>
        <div class="card shadow-sm mb-4" data-card-id="shiftsCard">
            <div class="card-header"><h5 class="mb-0"><i class="bi bi-calendar-range me-1"></i><?= t('shifts.panel_title') ?></h5></div>
            <div class="list-group list-group-flush">
                <?php foreach ($shifts as $shift): ?>
                <div class="list-group-item"><strong><?= formatDateTime($shift['start_time']) ?></strong><br><small class="text-muted"><?= t('hero.until') ?> <?= date('H:i', strtotime($shift['end_time'])) ?> · <?= $shift['approved_count'] ?>/<?= $shift['max_volunteers'] ?> <?= t('shifts.approved_count_suffix') ?></small></div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($canManageWarRoom && !$fieldMode): ?>
        <div class="card shadow-sm mb-4 border-danger" data-card-id="sosAlertsCard">
            <div class="card-header bg-danger text-white"><h5 class="mb-0"><i class="bi bi-sos me-1"></i><?= t('sos.panel_title') ?></h5></div>
            <div class="card-body">
                <div id="sosAlertsList"><p class="text-muted mb-0"><?= t('sos.empty') ?></p></div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($canManageWarRoom && !$fieldMode): ?>
        <div class="card shadow-sm mb-4 border-danger" data-card-id="broadcastCard">
            <div class="card-header bg-danger bg-opacity-10"><h5 class="mb-0"><i class="bi bi-megaphone-fill me-1 text-danger"></i><?= t('global_message.card_title') ?></h5></div>
            <div class="card-body">
                <p class="small text-muted"><?= t('global_message.note') ?></p>
                <form method="post" enctype="multipart/form-data">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="global_message">
                    <textarea name="global_message_text" class="form-control mb-2" rows="3" maxlength="500" placeholder="<?= t('global_message.placeholder') ?>"></textarea>
                    <div class="mb-2">
                        <label class="form-label small mb-1"><?= t('global_message.photo_label') ?></label>
                        <input type="file" name="global_message_photo" accept="image/*" class="form-control form-control-sm">
                    </div>
                    <button type="submit" class="btn btn-danger w-100 fw-semibold"><i class="bi bi-send-fill me-1"></i><?= t('global_message.submit_btn', ['count' => count($participants)]) ?></button>
                </form>
            </div>
        </div>

        <div class="card shadow-sm mb-4 border-danger" data-card-id="endMissionCard">
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

        <div class="card shadow-sm mb-4 border-primary" data-card-id="dispatchCard">
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

        <div class="card shadow-sm mb-4 border-primary" data-card-id="sectorsCard">
            <div class="card-header bg-primary bg-opacity-10"><h5 class="mb-0"><i class="bi bi-grid-3x3-gap-fill me-1"></i><?= t('sector.card_title') ?></h5></div>
            <div class="card-body">
                <p class="small text-muted"><?= t('sector.note') ?></p>
                <button type="button" class="btn btn-outline-secondary w-100 fw-semibold mb-2" data-bs-toggle="modal" data-bs-target="#searchAreaMapModal">
                    <i class="bi bi-bounding-box me-1"></i><?= t('sector.area_card_new_btn') ?>
                </button>
                <button type="button" class="btn btn-primary w-100 fw-semibold mb-2" id="sectorsCardDivideBtn">
                    <i class="bi bi-scissors me-1"></i><?= t('sector.divide_btn') ?>
                </button>
                <button type="button" class="btn btn-outline-danger w-100 btn-sm" id="sectorsCardClearAllBtn">
                    <i class="bi bi-trash3 me-1"></i><?= t('sector.clear_all_btn') ?>
                </button>
            </div>
        </div>

        <div class="card shadow-sm mb-4 border-danger" data-card-id="restrictedAreasCard">
            <div class="card-header bg-danger bg-opacity-10"><h5 class="mb-0"><i class="bi bi-exclamation-triangle-fill me-1"></i><?= t('restricted_area.card_title') ?></h5></div>
            <div class="card-body">
                <p class="small text-muted"><?= t('restricted_area.note') ?></p>
                <button type="button" class="btn btn-danger w-100 fw-semibold mb-3" data-bs-toggle="modal" data-bs-target="#restrictedAreaMapModal">
                    <i class="bi bi-plus-lg me-1"></i><?= t('restricted_area.new_btn') ?>
                </button>
                <div id="restrictedAreasList" class="mb-3"></div>
                <hr>
                <h6 class="small fw-semibold text-uppercase text-muted"><?= t('restricted_area.breaches_title') ?></h6>
                <div id="restrictedAreaBreachesList"></div>
            </div>
        </div>

        <?php if (!empty($teams)): ?>
        <div class="card shadow-sm mb-4 border-primary" data-card-id="routeOrderCard">
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

        <div class="card shadow-sm mb-4 border-primary" data-card-id="teamRoutesAdminCard">
            <div class="card-header bg-primary bg-opacity-10"><h5 class="mb-0"><i class="bi bi-list-check me-1"></i><?= t('route.admin_panel_title') ?></h5></div>
            <div class="card-body">
                <div id="routesAdminList"><p class="text-muted mb-0"><?= t('route.admin_empty') ?></p></div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($canManageWarRoom && !empty($mission['is_special_mission'])): ?>
        <div class="card shadow-sm mb-4" data-card-id="briefingCard">
            <div class="card-header"><h5 class="mb-0"><i class="bi bi-signpost-2 me-1"></i><?= t('briefing.card_title') ?></h5></div>
            <div class="card-body">
                <p class="small text-muted"><?= t('briefing.intro') ?></p>
                <form method="post" class="mb-3">
                    <?= csrfField() ?><input type="hidden" name="action" value="save_briefing_info">
                    <div class="mb-2">
                        <label class="form-label small fw-semibold"><?= t('briefing.rv_point_label') ?></label>
                        <input type="text" class="form-control form-control-sm" name="rv_point_label" maxlength="255" placeholder="<?= t('briefing.rv_point_placeholder') ?>" value="<?= h($mission['rv_point_label'] ?? '') ?>">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold"><?= t('briefing.radio_channel_label') ?></label>
                        <input type="text" class="form-control form-control-sm" name="radio_channel" maxlength="100" placeholder="<?= t('briefing.radio_channel_placeholder') ?>" value="<?= h($mission['radio_channel'] ?? '') ?>">
                    </div>
                    <button type="submit" class="btn btn-sm btn-primary w-100"><?= t('common.save') ?></button>
                </form>
                <div class="fw-semibold small mb-2"><?= t('briefing.links_title') ?></div>
                <?php if (empty($teams)): ?>
                <p class="small text-muted mb-0"><?= t('briefing.no_teams_yet') ?></p>
                <?php else: ?>
                <?php foreach ($teams as $team): [$briefBg, $briefFg] = teamBadgeColors($team['color']); $briefUrl = rtrim(BASE_URL, '/') . '/briefing-view.php?token=' . urlencode($team['briefing_token'] ?? ''); ?>
                <div class="d-flex align-items-center gap-2 mb-2">
                    <span class="badge" style="background:<?= h($briefBg) ?>;color:<?= h($briefFg) ?>;white-space:nowrap;"><?= h(teamLabel($team['codename'], $team['team_number'])) ?></span>
                    <input type="text" class="form-control form-control-sm wr-briefing-link" readonly value="<?= h($briefUrl) ?>" onclick="this.select()">
                    <button type="button" class="btn btn-sm btn-outline-secondary wr-briefing-copy-btn" data-copy-text="<?= h($briefUrl) ?>" title="<?= t('briefing.copy_btn') ?>"><i class="bi bi-clipboard"></i></button>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php
        // Closing is the only door out of the Action Room: every "Είδα"/"Λύθηκε"
        // button for incidents/shortages/SOS lives exclusively behind war-room.php,
        // which hard-redirects away the instant the mission is no longer OPEN (see
        // the status check near the top of this file) — with no exception for
        // admins and no other page that can act on those rows afterward. Closing
        // with any of these still open silently stops them from ever being
        // resolvable through the UI again, so this warns with the real counts
        // before the generic confirm, rather than staying silent about it.
        $openItemsCount = count($incidents) + count($shortageReports) + count($sosAlerts);
        $closeConfirmMsg = t('admin.close_confirm');
        if ($openItemsCount > 0) {
            $closeConfirmMsg = t('admin.close_open_items_warning', [
                'incidents' => count($incidents), 'shortages' => count($shortageReports), 'sos' => count($sosAlerts),
            ]) . ' ' . $closeConfirmMsg;
        }
        ?>
        <div class="card border-danger shadow-sm" data-card-id="missionMgmtCard">
            <div class="card-body"><h6 data-card-drag-handle><i class="bi bi-shield-exclamation text-danger me-1"></i><?= t('admin.mission_mgmt_title') ?></h6>
                <p class="small text-muted"><?= t('admin.close_note') ?></p>
                <?php if ($openItemsCount > 0): ?>
                <div class="alert alert-warning small py-2 px-2 mb-2"><i class="bi bi-exclamation-triangle-fill me-1"></i><?= t('admin.close_open_items_warning', [
                    'incidents' => count($incidents), 'shortages' => count($shortageReports), 'sos' => count($sosAlerts),
                ]) ?></div>
                <?php endif; ?>
                <form method="post" onsubmit="return confirm('<?= h(addslashes($closeConfirmMsg)) ?>')">
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

<div class="card shadow-sm mb-4" data-card-id="chatCard">
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

<!-- Show/hide cards (admin desktop view only, see the wrCardVisibilityBtn
     gear button in the hero header above). #cardVisibilityList is populated
     entirely by JS — see the card-layout IIFE further down — from the same
     cardLabels/hiddenCards data the drag-layout code already builds, in the
     admin's current main+sidebar order rather than a fixed default order. -->
<div class="modal fade" id="cardVisibilityModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-gear-fill me-1"></i><?= t('card_visibility.modal_title') ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row row-cols-1 row-cols-md-2 g-2" id="cardVisibilityList"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" id="cardVisibilityShowAllBtn"><?= t('card_visibility.show_all_btn') ?></button>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= t('common.close') ?></button>
            </div>
        </div>
    </div>
</div>

<?php if ($canManageWarRoom): ?>
<div class="modal fade" id="suggestReplacementModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-arrow-left-right me-1"></i><?= t('fatigue.suggest_replacement_modal_title') ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="suggestReplacementList"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?= t('common.close') ?></button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="modal fade" id="dispatchMapModal" tabindex="-1">
    <div class="modal-dialog modal-fullscreen">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h5 class="modal-title"><i class="bi bi-pin-map-fill me-1"></i><?= t('dispatch.card_title') ?></h5>
                <button type="button" class="btn btn-sm btn-outline-secondary me-2" id="dispatchSatelliteToggle" title="<?= t('map.btn_satellite_view') ?>"><i class="bi bi-globe-americas"></i></button>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0 d-flex flex-column">
                <div class="p-2 border-bottom d-flex flex-wrap gap-2 align-items-center bg-light">
                    <input type="text" id="dispatchAddressInput" class="form-control" style="max-width:320px;" placeholder="<?= t('dispatch.address_placeholder') ?>">
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="dispatchAddressSearch"><i class="bi bi-search me-1"></i><?= t('dispatch.search_btn') ?></button>
                    <span class="text-muted small" id="dispatchAddressStatus"></span>
                    <div class="input-group input-group-sm" style="max-width:230px;">
                        <input type="text" id="dispatchCoordsInput" class="form-control" placeholder="<?= t('dispatch.coords_placeholder') ?>" title="<?= t('dispatch.coords_add_title') ?>">
                        <button type="button" class="btn btn-outline-secondary" id="dispatchCoordsAddBtn" title="<?= t('dispatch.coords_add_title') ?>"><i class="bi bi-plus-lg"></i></button>
                    </div>
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

<div class="modal fade" id="divideSectorsModal" tabindex="-1">
    <div class="modal-dialog modal-fullscreen">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h5 class="modal-title"><i class="bi bi-scissors me-1"></i><span id="divideSectorsAreaLabel"></span></h5>
                <button type="button" class="btn btn-sm btn-outline-secondary me-2" id="divideSectorsSatelliteToggle" title="<?= t('map.btn_satellite_view') ?>"><i class="bi bi-globe-americas"></i></button>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0 d-flex flex-column flex-md-row">
                <div class="p-2 border-bottom border-md-bottom-0 border-md-end d-flex flex-column gap-2" style="width:100%;max-width:320px;">
                    <div class="small text-muted" id="divideSectorsHint"><?= t('sector.hub_hint') ?></div>
                    <div id="divideSectorsWedgeList" class="flex-grow-1" style="overflow-y:auto;min-height:0;"></div>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="divideSectorsClearBtn"><i class="bi bi-arrow-counterclockwise me-1"></i><?= t('dispatch.clear_btn') ?></button>
                        <button type="button" class="btn btn-success btn-sm flex-grow-1" id="divideSectorsSaveBtn" disabled><i class="bi bi-send-fill me-1"></i><?= t('sector.divide_save_btn') ?></button>
                    </div>
                </div>
                <div id="divideSectorsMap" style="flex:1;min-height:300px;"></div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="splitSectorModal" tabindex="-1">
    <div class="modal-dialog modal-fullscreen">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h5 class="modal-title"><i class="bi bi-scissors me-1"></i><span id="splitSectorLabel"></span></h5>
                <button type="button" class="btn btn-sm btn-outline-secondary me-2" id="splitSectorSatelliteToggle" title="<?= t('map.btn_satellite_view') ?>"><i class="bi bi-globe-americas"></i></button>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0 d-flex flex-column flex-md-row">
                <div class="p-2 border-bottom border-md-bottom-0 border-md-end d-flex flex-column gap-2" style="width:100%;max-width:320px;">
                    <div class="small text-muted" id="splitSectorHint"><?= t('sector.split_hint') ?></div>
                    <div id="splitSectorPreview" style="display:none;">
                        <div class="input-group input-group-sm mb-2">
                            <span class="input-group-text justify-content-center text-white" id="splitSectorSwatch1" style="min-width:34px;">Α</span>
                            <input type="text" class="form-control" id="splitSectorLabelInput1" maxlength="255">
                        </div>
                        <div class="input-group input-group-sm mb-2">
                            <span class="input-group-text justify-content-center text-white" id="splitSectorSwatch2" style="min-width:34px;">Β</span>
                            <input type="text" class="form-control" id="splitSectorLabelInput2" maxlength="255">
                        </div>
                    </div>
                    <div class="flex-grow-1"></div>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="splitSectorClearBtn"><i class="bi bi-arrow-counterclockwise me-1"></i><?= t('dispatch.clear_btn') ?></button>
                        <button type="button" class="btn btn-success btn-sm flex-grow-1" id="splitSectorSaveBtn" disabled><i class="bi bi-send-fill me-1"></i><?= t('sector.split_save_btn') ?></button>
                    </div>
                </div>
                <div id="splitSectorMap" style="flex:1;min-height:300px;"></div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="searchAreaMapModal" tabindex="-1">
    <div class="modal-dialog modal-fullscreen">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h5 class="modal-title"><i class="bi bi-bounding-box me-1"></i><?= t('sector.area_card_new_btn') ?></h5>
                <button type="button" class="btn btn-sm btn-outline-secondary me-2" id="areaComposerSatelliteToggle" title="<?= t('map.btn_satellite_view') ?>"><i class="bi bi-globe-americas"></i></button>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0 d-flex flex-column">
                <div class="p-2 border-bottom d-flex flex-wrap gap-2 align-items-center bg-light">
                    <input type="text" id="areaAddressInput" class="form-control" style="max-width:320px;" placeholder="<?= t('dispatch.address_placeholder') ?>">
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="areaAddressSearch"><i class="bi bi-search me-1"></i><?= t('dispatch.search_btn') ?></button>
                    <span class="text-muted small" id="areaAddressStatus"></span>
                    <div class="input-group input-group-sm" style="max-width:230px;">
                        <input type="text" id="areaCoordsInput" class="form-control" placeholder="<?= t('dispatch.coords_placeholder') ?>" title="<?= t('dispatch.coords_add_title') ?>">
                        <button type="button" class="btn btn-outline-secondary" id="areaCoordsAddBtn" title="<?= t('dispatch.coords_add_title') ?>"><i class="bi bi-plus-lg"></i></button>
                    </div>
                    <input type="text" id="areaLabelInput" class="form-control" style="max-width:220px;" maxlength="255" placeholder="<?= t('sector.area_label_placeholder') ?>">
                    <div class="ms-auto d-flex gap-2">
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="areaClearBtn"><i class="bi bi-arrow-counterclockwise me-1"></i><?= t('dispatch.clear_btn') ?></button>
                        <button type="button" class="btn btn-success btn-sm" id="areaSendBtn" disabled><i class="bi bi-send-fill me-1"></i><?= t('sector.save_btn') ?></button>
                    </div>
                </div>
                <div class="small text-muted px-2 py-1 bg-light border-bottom">
                    <?= t('sector.area_map_instructions') ?>
                </div>
                <div id="areaComposerMap" style="flex:1;min-height:0;"></div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="restrictedAreaMapModal" tabindex="-1">
    <div class="modal-dialog modal-fullscreen">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h5 class="modal-title"><i class="bi bi-exclamation-triangle-fill me-1 text-danger"></i><?= t('restricted_area.new_btn') ?></h5>
                <button type="button" class="btn btn-sm btn-outline-secondary me-2" id="restrictedAreaSatelliteToggle" title="<?= t('map.btn_satellite_view') ?>"><i class="bi bi-globe-americas"></i></button>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0 d-flex flex-column">
                <div class="p-2 border-bottom d-flex flex-wrap gap-2 align-items-center bg-light">
                    <input type="text" id="restrictedAreaAddressInput" class="form-control" style="max-width:320px;" placeholder="<?= t('dispatch.address_placeholder') ?>">
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="restrictedAreaAddressSearch"><i class="bi bi-search me-1"></i><?= t('dispatch.search_btn') ?></button>
                    <span class="text-muted small" id="restrictedAreaAddressStatus"></span>
                    <div class="input-group input-group-sm" style="max-width:230px;">
                        <input type="text" id="restrictedAreaCoordsInput" class="form-control" placeholder="<?= t('dispatch.coords_placeholder') ?>" title="<?= t('dispatch.coords_add_title') ?>">
                        <button type="button" class="btn btn-outline-secondary" id="restrictedAreaCoordsAddBtn" title="<?= t('dispatch.coords_add_title') ?>"><i class="bi bi-plus-lg"></i></button>
                    </div>
                    <input type="text" id="restrictedAreaLabelInput" class="form-control" style="max-width:220px;" maxlength="255" placeholder="<?= t('restricted_area.label_placeholder') ?>">
                    <div class="ms-auto d-flex gap-2">
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="restrictedAreaClearBtn"><i class="bi bi-arrow-counterclockwise me-1"></i><?= t('dispatch.clear_btn') ?></button>
                        <button type="button" class="btn btn-danger btn-sm" id="restrictedAreaSendBtn" disabled><i class="bi bi-send-fill me-1"></i><?= t('sector.save_btn') ?></button>
                    </div>
                </div>
                <div class="small text-muted px-2 py-1 bg-light border-bottom">
                    <?= t('restricted_area.map_instructions') ?>
                </div>
                <div id="restrictedAreaComposerMap" style="flex:1;min-height:0;"></div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="routeComposerModal" tabindex="-1">
    <div class="modal-dialog modal-fullscreen">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h5 class="modal-title"><i class="bi bi-signpost-split-fill me-1"></i><?= t('route.composer_title') ?></h5>
                <button type="button" class="btn btn-sm btn-outline-secondary me-2" id="routeSatelliteToggle" title="<?= t('map.btn_satellite_view') ?>"><i class="bi bi-globe-americas"></i></button>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0 d-flex flex-column">
                <div class="p-2 border-bottom d-flex flex-wrap gap-2 align-items-center bg-light">
                    <input type="text" id="routeTitleInput" class="form-control" style="max-width:240px;" maxlength="255" placeholder="<?= t('route.title_placeholder') ?>">
                    <input type="text" id="routeAddressInput" class="form-control" style="max-width:260px;" placeholder="<?= t('dispatch.address_placeholder') ?>">
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="routeAddressSearch"><i class="bi bi-search me-1"></i><?= t('dispatch.search_btn') ?></button>
                    <span class="text-muted small" id="routeAddressStatus"></span>
                    <div class="input-group input-group-sm" style="max-width:230px;">
                        <input type="text" id="routeCoordsInput" class="form-control" placeholder="<?= t('dispatch.coords_placeholder') ?>" title="<?= t('dispatch.coords_add_title') ?>">
                        <button type="button" class="btn btn-outline-secondary" id="routeCoordsAddBtn" title="<?= t('dispatch.coords_add_title') ?>"><i class="bi bi-plus-lg"></i></button>
                    </div>
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

<!-- Shared compress/upload progress popup — one global instance, used by
     every place a photo or video gets sent (main media buttons, a Route
     Order waypoint's own capture button, Point of Interest capture), so the
     user always sees the same real-percentage feedback regardless of which
     card triggered the send. Left dismissible (default Bootstrap backdrop/
     Esc behavior, no backdrop:static) deliberately: dismissing it early
     doesn't cancel the underlying compression/upload, it just hides the
     progress display, so there's never a way for this to trap the user
     behind an un-closeable dialog if some edge case left it open longer
     than expected. -->
<div class="modal fade" id="mediaProgressModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered" style="max-width:300px;">
        <div class="modal-content">
            <div class="modal-body text-center py-4">
                <div class="mb-2 fw-bold" id="mediaProgressLabel"></div>
                <div class="progress" style="height:20px;">
                    <div class="progress-bar progress-bar-striped progress-bar-animated" id="mediaProgressBar" role="progressbar" style="width:0%" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">0%</div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" integrity="sha384-cxOPjt7s7Iz04uaHJceBmS+qpjv2JkIHNVcuOrM+YHwZOmJGBXI00mdUXEq65HTH" crossorigin="anonymous"></script>
<script src="<?= rtrim(BASE_URL, '/') ?>/assets/js/war-room-utils.js?v=<?= APP_VERSION ?>"></script>
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
let areas = <?= json_encode($areas) ?>;
// Set by openDivideSectorsForArea() (declared further down, inside the
// !fieldMode block, where the area popup's "Divide into Sectors" button
// lives) and read by the divideSectorsModal IIFE (declared later in the
// file, as its own separate top-level scope). MUST live at this true
// top-level script scope, not inside any block — a `let` declared inside a
// `{}` block is invisible to a sibling scope outside that block (unlike a
// `function` declaration, which at least gets its name hoisted in sloppy
// mode; see the field-mode hoisting note elsewhere in this file for that
// related but distinct pitfall). Declaring this class of state inside the
// !fieldMode block has already thrown "is not defined" once this session —
// caught via a live shown.bs.modal test, not visible from php -l.
let pendingDivideAreaId = null;
// Same cross-scope requirement as pendingDivideAreaId just above — set by
// openSplitSectorModal() (inside the !fieldMode block) and read by the
// splitSectorModal IIFE (a separate top-level scope further down the file).
let pendingSplitSectorId = null;
// Same cross-scope requirement again — set by openDispatchForRing()/
// openRouteForRing() (near openDivideSectorsForArea(), inside the !fieldMode
// block) and consumed once, one-shot, inside dispatchMapModal's/
// routeComposerModal's own shown.bs.modal handlers (separate top-level IIFE
// scopes further down the file). Shape: null, or {points: [[lat,lng],...],
// label: string}.
let pendingDispatchSeed = null;
let pendingRouteSeed = null;
// Team assignment moved from the (now-removed) sector composer's own select
// to a per-sector control in its popup/list row instead (see sectorAdminSetTeam
// below) — needs the team roster available client-side for that <select>'s
// options, same id+label shape mission-history.php/etc. already compute
// server-side via teamLabel().
let teams = <?= json_encode(array_values(array_map(fn($t) => ['id' => $t['id'], 'label' => teamLabel($t['codename'], $t['team_number'])], $teams))) ?>;
let sectors = <?= json_encode($sectors) ?>;
// Verified Coverage — populated only once an admin toggles the layer on
// (mission-sector-coverage.php), keyed by sector id: {percent, gap_cells}.
// Kept separate from the `sectors` array itself rather than merged into it,
// since it's fetched on a completely different cadence (on-demand, not the
// 5s poll) and must survive across normal sector re-renders.
let coverageModeActive = false;
let sectorCoverageById = {};
// Same idea as sectorCoverageById, but for the 4 LPB search rings — keyed by
// ring index (0-3, matching pct=[25,50,75,95] elsewhere), not a DB id, since
// rings have no row of their own. See computeMissionRingCoverage().
let ringCoverageById = {};
let media = <?= json_encode($photos) ?>;
let broadcastPhotos = <?= json_encode($broadcastPhotos) ?>;
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
let restrictedAreaProximity = <?= json_encode($restrictedAreaProximity) ?>;
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
let missionIncidents = <?= json_encode($incidents) ?>;
// Regular participants see the same masked incidentsList as command staff (unlike
// shortageReportsList, which never renders for them at all) — this flag is what
// hides the seen/resolve action buttons for everyone except command staff, since
// the server already strips patient name/phone/notes from their copy of the data.
const canManageIncidents = <?= json_encode($canManageWarRoom) ?>;
let sosAlerts = <?= json_encode($sosAlerts) ?>;
let pointsOfInterest = <?= json_encode($pointsOfInterest) ?>;
let missingPerson = <?= json_encode($missingPerson) ?>;
// weather can be non-null purely because exposureUrgencyOn fetched it for the
// exposure card, even while the compass toggle itself is off — so the
// compass control needs its own explicit flag, not just `if (weather)`.
const weatherCompassEnabled = <?= json_encode($weatherCompassOn) ?>;
// LPB_RING_TABLE is the server-side PHP constant (includes/lpb-rings.php)
// reused as-is, so the ring radii JS draws can never drift from the ones the
// subject_category <select> was built from.
const searchRingsEnabled = <?= json_encode($searchRingsOn) ?>;
const LPB_RING_TABLE = <?= json_encode(LPB_RING_TABLE) ?>;
let weather = <?= json_encode($weather) ?>;
let exposureUrgency = <?= json_encode($exposureUrgency) ?>;
// Unlike weatherCompassEnabled (a page-load-only global setting),
// firesOverlayOn is per-mission and can change live from another admin's
// click, so it's a `let` re-assigned every poll tick — not a `const`.
let firesOverlayOn = <?= json_encode($firesOverlayOn) ?>;
let fireHotspots = <?= json_encode($fireHotspots) ?>;
let restrictedAreas = <?= json_encode($restrictedAreas) ?>;
let restrictedAreaBreaches = <?= json_encode($restrictedAreaBreaches) ?>;
let restrictedAreaBreachHistory = <?= json_encode($restrictedAreaBreachHistory) ?>;

// Drag-and-drop card layout (admin desktop view only — #wrZoneMain/#wrZoneSidebar
// only exist in the DOM when canManageWarRoom && !fieldMode, so their absence
// here already means "not this view", nothing further to check). Runs before
// the map initializes below, so a saved layout that puts the map card in a
// different zone is already in its final position before Leaflet ever
// measures it — see the map.invalidateSize() calls further down for the two
// cases (initial load, live drag) where a corrective resize is still needed.
let savedLayout = <?= json_encode($warRoomLayout) ?>;
// id -> already-localized short label, for the show/hide checklist modal.
let cardLabels = <?= json_encode(warRoomCardLabels(), JSON_UNESCAPED_UNICODE) ?>;
(function() {
    const zoneMain = document.getElementById('wrZoneMain');
    const zoneSidebar = document.getElementById('wrZoneSidebar');
    if (!zoneMain || !zoneSidebar) return;

    const mapCardEl = document.querySelector('[data-card-id="mapCard"]');

    function placeCards(order) {
        [['main', zoneMain], ['sidebar', zoneSidebar]].forEach(([zoneName, zoneEl]) => {
            ((order && order[zoneName]) || []).forEach(id => {
                const el = document.querySelector(`[data-card-id="${id}"]`);
                if (el) zoneEl.appendChild(el); // real node, never a clone — same idiom as the banner relocation below
            });
        });
        // Defensive only: PHP already reconciles savedLayout against whatever
        // actually renders this request, so this should never find anything —
        // but if it ever does, the card still ends up visible (in main)
        // rather than silently disappearing from the page.
        document.querySelectorAll('[data-card-id]').forEach(el => {
            if (!zoneMain.contains(el) && !zoneSidebar.contains(el)) zoneMain.appendChild(el);
        });
    }

    const mapZoneBefore = mapCardEl ? mapCardEl.closest('.wr-zone') : null;
    placeCards(savedLayout);
    document.querySelectorAll('.wr-legacy-row').forEach(el => el.remove());
    const mapZoneAfter = mapCardEl ? mapCardEl.closest('.wr-zone') : null;
    if (mapCardEl && mapZoneBefore !== mapZoneAfter) {
        setTimeout(() => { if (map) map.invalidateSize(); }, 150);
    }

    // Show/hide cards. Orthogonal to placeCards() above — a hidden card
    // still occupies its normal zone/position, it's just display:none, so
    // un-hiding it later doesn't need to re-place anything.
    const hiddenCards = new Set(savedLayout.hidden || []);
    function setCardHidden(id, hide) {
        const el = document.querySelector(`[data-card-id="${id}"]`);
        if (el) el.classList.toggle('wr-card-hidden', hide);
        // A map sized while display:none renders with missing/misaligned
        // tiles — same corrective invalidateSize() used everywhere else in
        // this file a hidden/resized map card can become visible again.
        if (id === 'mapCard' && !hide) {
            setTimeout(() => { if (map) map.invalidateSize(); }, 150);
        }
    }
    hiddenCards.forEach(id => setCardHidden(id, true));

    function renderCardVisibilityList() {
        const list = document.getElementById('cardVisibilityList');
        if (!list) return;
        list.innerHTML = '';
        const orderedIds = Array.from(zoneMain.children)
            .concat(Array.from(zoneSidebar.children))
            .map(el => el.getAttribute('data-card-id'))
            .filter(Boolean);
        orderedIds.forEach(id => {
            const col = document.createElement('div');
            col.className = 'col';
            const checkId = 'cardVis_' + id;
            col.innerHTML = `<div class="form-check">
                <input class="form-check-input" type="checkbox" id="${checkId}" ${hiddenCards.has(id) ? '' : 'checked'}>
                <label class="form-check-label" for="${checkId}">${escapeHtml(cardLabels[id] || id)}</label>
            </div>`;
            col.querySelector('input').addEventListener('change', (e) => {
                const hide = !e.target.checked;
                if (hide) hiddenCards.add(id); else hiddenCards.delete(id);
                setCardHidden(id, hide);
                scheduleSaveLayout();
            });
            list.appendChild(col);
        });
    }
    const cardVisibilityModalEl = document.getElementById('cardVisibilityModal');
    if (cardVisibilityModalEl) {
        cardVisibilityModalEl.addEventListener('show.bs.modal', renderCardVisibilityList);
    }
    const showAllBtn = document.getElementById('cardVisibilityShowAllBtn');
    if (showAllBtn) {
        showAllBtn.addEventListener('click', () => {
            Array.from(hiddenCards).forEach(id => setCardHidden(id, false));
            hiddenCards.clear();
            renderCardVisibilityList();
            scheduleSaveLayout();
        });
    }

    let sortableMain = null, sortableSidebar = null;
    if (typeof Sortable !== 'undefined') {
        const zoneOpts = {
            group: 'wrCards',
            handle: '.card-header, [data-card-drag-handle]',
            animation: 200,
            ghostClass: 'wr-sortable-ghost',
            dragClass: 'wr-sortable-drag',
            delay: 150,
            delayOnTouchOnly: true,
            touchStartThreshold: 5,
            disabled: true,
            onEnd: function(evt) {
                if (evt.item.getAttribute('data-card-id') === 'mapCard') {
                    setTimeout(() => { if (map) map.invalidateSize(); }, 150);
                }
                scheduleSaveLayout();
            },
        };
        sortableMain = new Sortable(zoneMain, zoneOpts);
        sortableSidebar = new Sortable(zoneSidebar, zoneOpts);
    }

    let saveTimer = null;
    function scheduleSaveLayout() {
        clearTimeout(saveTimer);
        saveTimer = setTimeout(saveLayoutNow, 600);
    }
    function saveLayoutNow() {
        const layout = {
            main: Array.from(zoneMain.children).map(el => el.getAttribute('data-card-id')).filter(Boolean),
            sidebar: Array.from(zoneSidebar.children).map(el => el.getAttribute('data-card-id')).filter(Boolean),
            hidden: Array.from(hiddenCards),
        };
        const data = new URLSearchParams({csrf_token: csrfToken, layout_json: JSON.stringify(layout)});
        fetch('api-war-room-layout.php', {method: 'POST', body: data}).then(r => r.json()).then(result => {
            if (!result.ok) console.error('War room layout save failed:', result.error);
        }).catch(() => {});
    }

    // Always starts locked, every page load — this is a live incident-command
    // screen, so the risk of an accidental drag mid-incident outweighs the
    // one-click cost of unlocking each session. Not persisted on purpose.
    const lockBtn = document.getElementById('wrLayoutLockToggle');
    let layoutLocked = true;
    function applyLockState() {
        zoneMain.classList.toggle('wr-unlocked', !layoutLocked);
        zoneSidebar.classList.toggle('wr-unlocked', !layoutLocked);
        if (sortableMain) sortableMain.option('disabled', layoutLocked);
        if (sortableSidebar) sortableSidebar.option('disabled', layoutLocked);
        if (lockBtn) {
            lockBtn.classList.toggle('btn-outline-light', layoutLocked);
            lockBtn.classList.toggle('btn-warning', !layoutLocked);
            lockBtn.innerHTML = layoutLocked
                ? '<i class="bi bi-lock-fill me-1"></i>' + t('hero.btn_unlock_layout')
                : '<i class="bi bi-unlock-fill me-1"></i>' + t('hero.btn_lock_layout');
        }
    }
    if (lockBtn) {
        lockBtn.addEventListener('click', () => { layoutLocked = !layoutLocked; applyLockState(); });
    }
    applyLockState();
})();

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
// Street/satellite base layers, shared by the live map and all 6 composer
// maps (dispatch/search-area/divide-into-sectors/split-sector/restricted-
// area/route) — one definition so every map agrees on tile URLs and toggle
// behavior instead of each composer hand-rolling its own copy. Satellite is
// Esri World Imagery — free, no API key, no usage cap at this traffic scale
// (Google/Bing/Mapbox satellite tiles all require a paid key, ruled out on
// this project's standing "must be free" constraint). Both tile layers are
// created upfront so toggling is instant add/remove, never a re-fetch; only
// one is ever attached to a given map at a time. The preference
// (wr_map_base_layer) is intentionally ONE shared localStorage key across
// every map, not per-map/per-mission — whichever base a volunteer/admin
// prefers, they want it everywhere they look or draw, not re-toggled per
// composer. toggleBtnId is optional — the live map and every composer pass
// their own button id; icon/title show the action a click would take
// (globe while on street = click for satellite; map while on satellite =
// click to go back), matching mapFullscreenToggle's own convention.
function addMapBaseLayers(targetMap, toggleBtnId) {
    const street = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {attribution: '© OpenStreetMap'});
    const satellite = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {attribution: 'Tiles © Esri'});
    (localStorage.getItem('wr_map_base_layer') === 'satellite' ? satellite : street).addTo(targetMap);
    const btn = toggleBtnId ? document.getElementById(toggleBtnId) : null;
    if (btn) {
        const refreshBtn = () => {
            const active = targetMap.hasLayer(satellite);
            btn.innerHTML = active ? '<i class="bi bi-map"></i>' : '<i class="bi bi-globe-americas"></i>';
            btn.title = active ? t('map.btn_street_view') : t('map.btn_satellite_view');
        };
        refreshBtn();
        btn.addEventListener('click', () => {
            const goingSatellite = !targetMap.hasLayer(satellite);
            targetMap.removeLayer(goingSatellite ? street : satellite);
            targetMap.addLayer(goingSatellite ? satellite : street);
            try { localStorage.setItem('wr_map_base_layer', goingSatellite ? 'satellite' : 'street'); } catch (e) {}
            refreshBtn();
        });
    }
    return {street, satellite};
}
let map = null, pinLayer = null, dispatchLayer = null, trailLayer = null, annotationLayer = null, annotationDrawLayer = null, routeLayer = null, incidentLayer = null, poiLayer = null, areaLayer = null, sectorLayer = null, sectorBuildingLayer = null, restrictedAreaLayer = null, coverageLayer = null, missingPersonLayer = null, fireLayer = null, searchRingsLayer = null;
if (!fieldMode) {
    map = L.map('warRoomMap').setView(missionLocation.lat ? [missionLocation.lat, missionLocation.lng] : [37.97, 23.73], missionLocation.lat ? 13 : 7);
    addMapBaseLayers(map, 'mapSatelliteToggle');
    // Search-area boundaries get their own pane BELOW search-sector polygons
    // (tilePane 200 < areaPane 340 < sectorPane 350 < overlayPane 400 <
    // markerPane 600) — an area is the large outer container a sector lives
    // inside, so it must render underneath the sector's own fill, never on
    // top of it.
    map.createPane('areaPane');
    map.getPane('areaPane').style.zIndex = 340;
    areaLayer = L.featureGroup().addTo(map);
    // Search-sector polygons get their own pane BELOW the default marker/
    // overlay panes (tilePane 200 < sectorPane 350 < overlayPane 400 <
    // markerPane 600) — sectors are large background coverage fills and
    // must never sit visually on top of dispatch polygons or live pins the
    // way annotationPane (610, above everything) deliberately does for
    // hand-drawn sketches. Buildings are separate point markers and stay in
    // the default marker pane (sectorBuildingLayer, created below) rather
    // than this pane, since a small precise point would visually bury
    // under the sector's own fill if placed at this same low z-index.
    map.createPane('sectorPane');
    map.getPane('sectorPane').style.zIndex = 350;
    sectorLayer = L.featureGroup().addTo(map);
    sectorBuildingLayer = L.featureGroup().addTo(map);
    // Verified Coverage gap-cell overlay gets its own pane ABOVE sectorPane
    // (so translucent gap tint paints over the sector's own status-color
    // fill) but BELOW the default overlayPane/markerPane (tilePane 200 <
    // areaPane 340 < sectorPane 350 < coveragePane 360 < overlayPane 400 <
    // markerPane 600) — live pins must stay visible on top of it. Not
    // attached to the map here — same never-attached-until-toggled pattern
    // as trailLayer just below, only shown while coverage mode is active.
    map.createPane('coveragePane');
    map.getPane('coveragePane').style.zIndex = 360;
    coverageLayer = L.featureGroup();
    // FeatureGroup, not plain LayerGroup — required for popupopen to
    // propagate from a child marker up to the group's own listener (see the
    // matching dispatchLayer note two lines below), needed by the new
    // pin-charge-alert-btn wiring.
    pinLayer = L.featureGroup().addTo(map);
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
    incidentLayer = L.featureGroup().addTo(map);
    poiLayer = L.featureGroup().addTo(map);
    missingPersonLayer = L.featureGroup().addTo(map);
    // No custom pane — L.circle defaults to the standard overlayPane (z=400),
    // which already sits exactly where these rings should stack: above
    // coveragePane/sectorPane/areaPane, below markerPane/annotationPane/
    // restrictedAreaPane. Same as dispatchLayer/routeLayer/trailLayer, none
    // of which use a custom pane either.
    searchRingsLayer = L.featureGroup().addTo(map);
    fireLayer = L.featureGroup().addTo(map);
    // Restricted (hazard/danger) areas render ABOVE literally everything else
    // on the map, including annotationPane (610, itself already above every
    // default Leaflet pane) — the user's own explicit ask. 700 leaves headroom
    // above annotationPane without needing to renumber anything else.
    map.createPane('restrictedAreaPane');
    map.getPane('restrictedAreaPane').style.zIndex = 700;
    restrictedAreaLayer = L.featureGroup().addTo(map);
    // Diagonal-hatch fill pattern, injected once as a standalone SVG appended
    // to document.body — deliberately NOT reaching into Leaflet's internals
    // (map.getPanes()/map._renderer._container). A custom pane with no
    // explicitly-created renderer gets its own separate, lazily-created SVG
    // root distinct from the default overlayPane's (areaPane/sectorPane
    // already do this invisibly), so a <defs> placed inside one pane's tree
    // wouldn't be reachable from a polygon drawn in a different pane anyway.
    // fill="url(#id)" resolves document-wide regardless of which SVG subtree
    // hosts the referencing element, so a standalone def sidesteps the whole
    // question and survives restrictedAreaLayer.clearLayers() on every
    // re-render (never part of the cleared layer group to begin with).
    if (!document.getElementById('restrictedHatchDefs')) {
        const hatchSvg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        hatchSvg.setAttribute('id', 'restrictedHatchDefs');
        hatchSvg.style.cssText = 'position:absolute;width:0;height:0';
        hatchSvg.innerHTML = '<defs><pattern id="restrictedHatch" patternUnits="userSpaceOnUse" width="10" height="10" patternTransform="rotate(45)">'
            + '<line x1="0" y1="0" x2="0" y2="10" stroke="#dc3545" stroke-width="4" stroke-dasharray="4,3"/>'
            + '</pattern></defs>';
        document.body.appendChild(hatchSvg);
    }
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
// Search-sector "Add Building" — a one-shot click-to-place mode set by the
// sector popup's own button (not part of the annotationToolbar/activeTool
// state machine above, since it needs a sectorId carried through to the
// next click, not a persistent toggleable tool). Consumed and cleared by
// the map click handler below.
let addingBuildingToSectorId = null;
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
    // [data-tool] excludes annoToolClearAll below — it performs an immediate
    // action, not a persistent tool selection, and has no data-tool value of
    // its own (setActiveTool(undefined) would have wrongly matched the same
    // "active" style toggle every other tool-less state already uses).
    annoToolbarEl.querySelectorAll('button[data-tool]').forEach(btn => btn.addEventListener('click', () => setActiveTool(btn.dataset.tool)));
}
document.getElementById('annoToolClearAll')?.addEventListener('click', () => {
    if (!annotations.length) return;
    if (!confirm(t('annotation.clear_all_confirm'))) return;
    const data = new URLSearchParams({csrf_token: csrfToken, action: 'clear_all', mission_id: '<?= $missionId ?>'});
    fetch('mission-annotation.php', {method: 'POST', body: data}).then(r => r.json()).then(result => {
        if (result.ok) renderAnnotations(annotations = []);
        else alert(result.error || t('common.failed'));
    });
});
// Safety net: a mousedown with no matching mouseup (alt-tab mid-stroke, focus
// stolen mid-gesture) would otherwise leave map.dragging permanently disabled
// for the rest of the session, since nothing else would ever call
// cancelActiveDrawing() again.
window.addEventListener('blur', cancelActiveDrawing);
function submitAnnotation(type, geo, label) {
    const data = new URLSearchParams({csrf_token: csrfToken, action: 'create', mission_id: <?= $missionId ?>, type, geo: JSON.stringify(geo)});
    if (label) data.append('label', label);
    fetch('mission-annotation.php', {method: 'POST', body: data}).then(r => r.json()).then(result => {
        if (result.ok) renderAnnotations(annotations = [...annotations, result.annotation]);
        else alert(result.error || t('common.send_failed'));
    }).catch(() => alert(t('common.send_failed')));
}
// Mirrors guestNameHtml() in includes/functions-warroom.php for names that render from
// a JS poll (chat, media, dispatch, SOS, shortage) rather than server-side PHP.
// teamColorBg/teamColorFg are pre-resolved server-side via PHP's teamBadgeColors()
// and ride along in the poll JSON rather than being reimplemented here — same
// division of labor dispatchTeamLabelHtml() below already relies on.
function guestNameHtml(name, isExternal, teamName, teamColorBg, teamColorFg, countryCode) {
    if (!teamColorBg) {
        // Legacy path — poll payload not yet upgraded to the team badge/flag.
        if (!isExternal) return escapeHtml(name);
        const org = (teamName && teamName.trim() !== '') ? teamName : t('guest.org_unknown');
        return `${escapeHtml(name)}<sup class="guest-org-badge" title="${escapeHtml(t('guest.org_tooltip', {org}))}">${escapeHtml(org)}</sup>`;
    }
    // Regular members are always Επίδρασις, a Greek org — fixed, hardcoded
    // here rather than left blank (users.guest_country_code is guest-only
    // data and never populated for anyone else).
    const flag = flagHtml(isExternal ? countryCode : 'GR');
    const team = (teamName && teamName.trim() !== '') ? teamName : t('guest.org_unknown');
    return `${escapeHtml(name)} <span class="team-name-badge" style="background:${teamColorBg};color:${teamColorFg}" title="${escapeHtml(team)}">${flag}${escapeHtml(team)}</span>`;
}
// Mirrors flagHtml() in includes/functions-warroom.php — real self-hosted SVG,
// never emoji (Windows/Chrome renders flag emoji as literal "GR"/"GB" text).
const FLAG_ASSET_BASE = '<?= rtrim(BASE_URL, "/") ?>/assets/flags/';
function flagHtml(countryCode) {
    if (!countryCode || !/^[A-Za-z]{2}$/.test(countryCode)) return '';
    return `<img class="flag-icon" src="${FLAG_ASSET_BASE}${countryCode.toLowerCase()}.svg" alt="">`;
}
// Mirrors teamBadgeColors()/teamLabel() in includes/functions-warroom.php —
// needed client-side so the Teams card's roster can be live-refreshed from
// the poll (renderTeamRosters() below) without a full page reload.
const TEAM_COLOR_TEXT = {'#008300': '#fff', '#4a3aa7': '#fff'};
function teamBadgeColorsJs(color) {
    if (!color) return ['#212529', '#fff'];
    return [color, TEAM_COLOR_TEXT[color] || '#000'];
}
function teamLabel(codename, teamNumber) {
    if (!codename) return '';
    return (teamNumber !== null && teamNumber !== undefined && teamNumber !== '') ? (codename + ' ' + teamNumber) : codename;
}
// Mirrors homeTeamCornerBadgeHtml() in includes/functions-warroom.php —
// intentionally duplicated rather than reusing guestNameHtml()'s inline
// badge, same reasoning as the PHP version's own doc comment.
function homeTeamCornerBadgeHtml(teamName, teamColor, isExternal, countryCode) {
    if (teamColor === null || teamColor === undefined) return '';
    const [bg, fg] = teamBadgeColorsJs(teamColor);
    const flag = flagHtml(isExternal ? countryCode : 'GR');
    const team = (teamName && String(teamName).trim() !== '') ? teamName : t('guest.org_unknown');
    return `<span class="team-name-badge-corner" style="background:${bg};color:${fg}" title="${escapeHtml(team)}">${flag}${escapeHtml(team)}</span>`;
}
// Rebuilds one team's roster HTML (badge, leader line, member badges) —
// exactly what the PHP template's own #teamsCard loop renders for
// .wr-team-roster, kept in sync by hand since this is client-only data.
function teamRosterHtml(team) {
    const [teamBg, teamFg] = teamBadgeColorsJs(team.color);
    let html = `<span class="badge fs-6 me-2" style="background:${teamBg};color:${teamFg};">${escapeHtml(teamLabel(team.codename, team.team_number))}</span>`;
    if (team.leader_name) {
        html += `<span class="small text-muted"><i class="bi bi-star-fill text-warning me-1"></i>${escapeHtml(team.leader_name)}</span>`;
        html += homeTeamCornerBadgeHtml(team.leader_home_team_name, team.leader_home_team_color, team.leader_is_external, team.leader_guest_country_code);
    }
    html += '<div class="small mt-2">' + team.members.map(m => {
        const [mBg, mFg] = teamBadgeColorsJs(m.home_team_color);
        return `<span class="badge bg-light text-dark border me-1 mb-1">${guestNameHtml(m.name, m.is_external, m.home_team_name, mBg, mFg, m.guest_country_code)}${m.user_id === team.leader_id ? ' ⭐' : ''}</span>`;
    }).join('') + '</div>';
    return html;
}
// Live-refreshes each EXISTING team's roster from the poll, so another
// admin's edit (add/remove a member, change leader) shows up here within
// one poll cycle instead of needing a manual reload. Deliberately doesn't
// add a row for a brand-new team created by someone else — its Edit button
// has no matching #editTeamModal-{id} in this tab's DOM to open, and
// building one here would duplicate the PHP template's own eligible-member
// pool logic. A disbanded team's row IS removed live though — that's just
// deleting a DOM node, no modal involved.
function renderTeamRosters(items) {
    const byId = new Map(items.map(team => [String(team.id), team]));
    document.querySelectorAll('[data-card-id="teamsCard"] [data-team-id]').forEach(row => {
        const team = byId.get(row.dataset.teamId);
        if (!team) { row.remove(); return; }
        const rosterEl = row.querySelector('.wr-team-roster');
        if (rosterEl) rosterEl.innerHTML = teamRosterHtml(team);
    });
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
            ? '<div class="small text-success mt-1">' + item.acks.map(a => `✅ ${a.team_label !== '—' ? escapeHtml(a.team_label) + ' — ' : ''}${guestNameHtml(a.user_name, a.is_external, a.home_team_name, a.home_team_color_bg, a.home_team_color_fg, a.guest_country_code)} (${a.time})`).join('<br>') + '</div>'
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

// Search-sector coverage tracking. Bootstrap-class-name -> hex lookup so the
// same SECTOR_STATUS_COLORS badge classes used server-side also paint the
// map polygons/building pins with a real color, same technique mission-
// stats.php already uses for its own status-driven charts.
// NOTE: sectorRefreshAfter/sectorSelfAdvance/sectorFloorToggle/
// sectorActionLabel/sectorFloorChecklistHtml/renderMySectors are NOT here —
// they live below, outside this `if (!fieldMode)` block. A `function` DEFINED
// inside a block is only actually ASSIGNED if that block runs (legacy
// sloppy-mode "Annex B" hoisting rules) — in field mode this block never
// runs at all, so anything renderMySectors' own field-mode card needs at
// call time must be declared where field mode still reaches it. Confirmed
// live: this exact mistake first shipped as "renderMySectors is not a
// function" the moment field mode was tested for real, not caught by
// php -l (a JS runtime issue, not a PHP one) or by any of the full-view
// testing done first.
const SECTOR_STATUS_HEX = <?= json_encode(array_map(fn($c) => MISSION_TYPE_COLOR_HEX[$c] ?? '#6c757d', SECTOR_STATUS_COLORS)) ?>;

// Shared by both the map popup and the sidebar list row so the two never
// drift apart. Deliberately NOT another red/yellow/green badge — that scale
// is already spoken for by SECTOR_STATUS_HEX (status) and the building-pin
// grey/green/red convention (floor checklist), so this uses a plain
// neutral badge + a satellite icon instead, with the ⚠️ doing the actual
// "pay attention" work only for the one case that matters: a sector called
// done that the GPS record doesn't back up.
function sectorCoverageBadgeHtml(item) {
    const cov = sectorCoverageById[item.id];
    if (!cov) return '';
    const groundBadge = `<span class="badge bg-light text-dark border" title="${escapeHtml(t('coverage.badge_tooltip'))}">🛰️ ${cov.percent}%</span>`;
    // Sectors with buildings show BOTH numbers side by side — ground-sweep %
    // (same GPS signal as any other sector) plus a buildings % (checked
    // buildings / total). The ⚠️ stays tied to buildings only, never to the
    // ground %: when the actual assignment is "check these N buildings," a
    // volunteer doing exactly that produces a GPS track clustered tightly
    // around a few points, not spread across the whole polygon, so a ground-%
    // threshold would read as a false-alarm-low warning no matter how
    // thoroughly the buildings were actually checked.
    if (item.buildings && item.buildings.length > 0) {
        const doneBuildings = item.buildings.filter(b => b.all_required_checked).length;
        const buildingsPercent = Math.round(doneBuildings / item.buildings.length * 100);
        const buildingsBadge = `<span class="badge bg-light text-dark border" title="${escapeHtml(t('coverage.buildings_tooltip'))}">🏢 ${buildingsPercent}%</span>`;
        const warn = (item.status === 'completed' && buildingsPercent < 100)
            ? ` <span title="${escapeHtml(t('coverage.buildings_incomplete_warning'))}">⚠️</span>` : '';
        return ` ${groundBadge} ${buildingsBadge}${warn}`;
    }
    const warn = (item.status === 'completed' && cov.percent < 60)
        ? ` <span title="${escapeHtml(t('coverage.low_coverage_warning'))}">⚠️</span>` : '';
    return ` ${groundBadge}${warn}`;
}
// Same badge idiom as sectorCoverageBadgeHtml(), reusing the same generic
// coverage.badge_tooltip key — no ring-specific translation needed. Returns
// '' when coverage mode is off or this particular ring has no percent (e.g.
// its radius exceeded RING_COVERAGE_MAX_RADIUS_METERS server-side).
function ringCoverageBadgeHtml(ringIndex) {
    const cov = ringCoverageById[ringIndex];
    if (!coverageModeActive || !cov) return '';
    return ` <span class="badge bg-light text-dark border" title="${escapeHtml(t('coverage.badge_tooltip'))}">🛰️ ${cov.percent}%</span>`;
}

function sectorAdminSetStatus(id, status, selectEl) {
    if (selectEl) selectEl.disabled = true;
    const data = new URLSearchParams({csrf_token: csrfToken, mission_id: <?= $missionId ?>, action: 'status', id, status});
    fetch('mission-sector.php', {method:'POST', body:data}).then(r => r.json()).then(result => {
        if (result.ok) { if (map) map.closePopup(); sectorRefreshAfter(result.sectors, result.areas); }
        else { alert(result.error || t('common.send_failed')); if (selectEl) selectEl.disabled = false; }
    });
}
// Only client-side entry point to the `assign` action — sectors created by
// the divide tool start unassigned on purpose (team choice is per-wedge,
// made here rather than duplicated into that tool), so this is the sole way
// to ever put a team on a sector post-creation.
function sectorAdminSetTeam(id, teamId, selectEl) {
    if (selectEl) selectEl.disabled = true;
    const data = new URLSearchParams({csrf_token: csrfToken, mission_id: <?= $missionId ?>, action: 'assign', id, team_id: teamId});
    fetch('mission-sector.php', {method:'POST', body:data}).then(r => r.json()).then(result => {
        if (result.ok) { if (map) map.closePopup(); sectorRefreshAfter(result.sectors, result.areas); }
        else { alert(result.error || t('common.send_failed')); if (selectEl) selectEl.disabled = false; }
    });
}
function sectorDelete(id) {
    if (!confirm(t('sector.delete_confirm'))) return;
    const data = new URLSearchParams({csrf_token: csrfToken, mission_id: <?= $missionId ?>, action: 'delete', id});
    fetch('mission-sector.php', {method:'POST', body:data}).then(r => r.json()).then(result => {
        if (result.ok) { if (map) map.closePopup(); sectors = sectors.filter(s => String(s.id) !== String(id)); sectorRefreshAfter(); }
    });
}
function sectorDeleteBuilding(id) {
    if (!confirm(t('sector.delete_confirm'))) return;
    const data = new URLSearchParams({csrf_token: csrfToken, mission_id: <?= $missionId ?>, action: 'delete_building', id});
    fetch('mission-sector.php', {method:'POST', body:data}).then(r => r.json()).then(result => {
        if (result.ok) { if (map) map.closePopup(); sectorRefreshAfter(result.sectors, result.areas); }
    });
}

// Search-area boundaries — the outer polygon a sector lives inside. No
// status/self-report machinery of its own (see loadMissionSearchAreasForUser
// in functions-warroom.php): just a label, a rollup computed server-side from
// its sectors, and admin actions (divide into sectors OR clear them, delete
// the area itself). pendingDivideAreaId is read by the divideSectorsModal
// IIFE (a separate top-level scope later in the file) — MUST stay at true
// top-level script scope, not inside this block, for the same reason
// pendingSectorAreaId used to have to (see the note further down where the
// file-wide `let areas/sectors` globals are declared): a `let` inside this
// `if (!fieldMode) {}` block is invisible to a sibling scope outside it.
function openDivideSectorsForArea(areaId) {
    pendingDivideAreaId = areaId;
    const modalEl = document.getElementById('divideSectorsModal');
    if (modalEl) bootstrap.Modal.getOrCreateInstance(modalEl).show();
}
// Split an already-created sector into two — only ever called for a sector
// that's still not_started with no buildings (see the caller's own gate);
// this function itself doesn't re-check that, it's purely "open the tool".
function openSplitSectorModal(sectorId) {
    pendingSplitSectorId = sectorId;
    const modalEl = document.getElementById('splitSectorModal');
    if (modalEl) bootstrap.Modal.getOrCreateInstance(modalEl).show();
}
// How many boundary points to sample a ring's circumference into — a fixed
// count breaks down badly across the radius range (12,000m radius is ~75km
// around, 12 points would be ~6.3km apart and wouldn't remotely trace a
// circle). Targets one point roughly every 280m instead, clamped to
// [8, 30] — 30 is mission-route.php's real hard server-side waypoint cap
// (dispatch's polygon path has no such cap, but using the same clamp keeps
// the two visually/operationally consistent).
function ringPolygonPointCount(radiusMeters) {
    return Math.min(30, Math.max(8, Math.round(2 * Math.PI * radiusMeters / 280)));
}
// "Send a team to this ring" — seeds the dispatch composer with a polygon
// tracing the ring's boundary (so the team is assigned the whole disc area),
// then opens it exactly like clicking the normal "New Dispatch" button would.
// Deliberately does NOT pre-pick a team: only the tedious geometry is
// automated, the admin still chooses who and confirms send.
function openDispatchForRing(ringIndex) {
    if (!missingPerson || !missingPerson.subject_category) return;
    const radii = LPB_RING_TABLE[missingPerson.subject_category];
    if (!radii) return;
    const radius = radii[ringIndex];
    const center = {lat: missingPerson.last_seen_lat, lng: missingPerson.last_seen_lng};
    const points = circleToPolygonPoints(center, radius, ringPolygonPointCount(radius));
    const pct = [25, 50, 75, 95][ringIndex];
    pendingDispatchSeed = {points, label: t('missing_person.ring_generated_label', {pct})};
    map.closePopup();
    const modalEl = document.getElementById('dispatchMapModal');
    if (modalEl) bootstrap.Modal.getOrCreateInstance(modalEl).show();
}
// "Sweep this ring's perimeter" — seeds the route composer with waypoints
// sampled around the ring's circumference (a boundary-sweep search pattern),
// then opens it like the normal "New Route" flow. Same "geometry only, admin
// still picks the team/members" scope as openDispatchForRing() above.
function openRouteForRing(ringIndex) {
    if (!missingPerson || !missingPerson.subject_category) return;
    // No team-existence check here on purpose: the route composer itself now
    // tolerates opening with zero teams on the mission (lets the map/
    // waypoint-planning parts work regardless) and only shows a clear
    // "no teams yet" message at actual send time, when a team genuinely
    // becomes required — see the composer's own setup IIFE for the full
    // explanation. Keeping this a single, composer-owned check rather than
    // duplicating it at every entry point that can open this modal.
    const radii = LPB_RING_TABLE[missingPerson.subject_category];
    if (!radii) return;
    const radius = radii[ringIndex];
    const center = {lat: missingPerson.last_seen_lat, lng: missingPerson.last_seen_lng};
    const points = circleToPolygonPoints(center, radius, ringPolygonPointCount(radius));
    const pct = [25, 50, 75, 95][ringIndex];
    pendingRouteSeed = {points, label: t('missing_person.ring_generated_label', {pct})};
    map.closePopup();
    const modalEl = document.getElementById('routeComposerModal');
    if (modalEl) bootstrap.Modal.getOrCreateInstance(modalEl).show();
}
function areaDelete(id) {
    const area = areas.find(a => String(a.id) === String(id));
    if (!confirm(t('sector.area_delete_confirm', {label: area ? area.label : '', count: area ? area.sector_count : 0}))) return;
    const data = new URLSearchParams({csrf_token: csrfToken, mission_id: <?= $missionId ?>, action: 'delete_area', id});
    fetch('mission-sector.php', {method:'POST', body:data}).then(r => r.json()).then(result => {
        if (result.ok) {
            if (map) map.closePopup();
            areas = areas.filter(a => String(a.id) !== String(id));
            sectors = sectors.filter(s => String(s.area_id) !== String(id));
            sectorRefreshAfter();
        }
    });
}
function clearAreaSectors(id) {
    const area = areas.find(a => String(a.id) === String(id));
    if (!confirm(t('sector.clear_area_sectors_confirm', {count: area ? area.sector_count : 0}))) return;
    const data = new URLSearchParams({csrf_token: csrfToken, mission_id: <?= $missionId ?>, action: 'clear_area_sectors', id});
    fetch('mission-sector.php', {method:'POST', body:data}).then(r => r.json()).then(result => {
        if (result.ok) {
            if (map) map.closePopup();
            sectors = sectors.filter(s => String(s.area_id) !== String(id));
            // Locally mirror what the server's own rollup would now say —
            // without this, the area's stale sector_count would keep its
            // popup showing "Clear Sectors" instead of switching back to
            // "Divide into Sectors" until the next poll tick.
            const areaObj = areas.find(a => String(a.id) === String(id));
            if (areaObj) { areaObj.sector_count = 0; areaObj.completed_count = 0; }
            sectorRefreshAfter();
        }
    });
}
// Sidebar shortcut — only unambiguous when exactly one area still has zero
// sectors; with several, sending the admin to that specific area's own map
// popup (which has no such ambiguity) beats building a whole second picker
// UI for what's a secondary entry point to begin with.
document.getElementById('sectorsCardDivideBtn')?.addEventListener('click', () => {
    const eligible = areas.filter(a => a.sector_count === 0);
    if (!eligible.length) {
        alert(t('sector.no_undivided_areas'));
    } else if (eligible.length === 1) {
        openDivideSectorsForArea(eligible[0].id);
    } else {
        alert(t('sector.pick_area_on_map'));
    }
});
document.getElementById('sectorsCardClearAllBtn')?.addEventListener('click', () => {
    const sectorTotal = areas.reduce((sum, a) => sum + a.sector_count, 0);
    if (!confirm(t('sector.clear_all_confirm', {areas: areas.length, sectors: sectorTotal}))) return;
    const data = new URLSearchParams({csrf_token: csrfToken, mission_id: <?= $missionId ?>, action: 'clear_all_areas'});
    fetch('mission-sector.php', {method: 'POST', body: data}).then(r => r.json()).then(result => {
        if (result.ok) {
            if (map) map.closePopup();
            areas = [];
            sectors = [];
            sectorRefreshAfter();
        } else {
            alert(result.error || t('common.send_failed'));
        }
    });
});
// Search areas get their permanent label OUTSIDE the polygon (just past its
// own northernmost corner) instead of centered like every other polygon
// label on this map — once an area is divided, a sector's own centered
// label can land right on the area's centroid too, and the two used to
// overlap into unreadable text. Sectors keep direction:'center' — a sector
// never has another label competing for its own centroid, only the area
// does. Anchored to the polygon's own northernmost vertex (a real corner of
// the shape) with a small FIXED nudge, not scaled by the polygon's size —
// a first version scaled the offset with the area's own height and drifted
// far enough from a realistically-sized area to start overlapping a
// different, unrelated area drawn further north on the same map.
function areaLabelAnchor(geo) {
    let north = geo[0];
    for (const pt of geo) {
        if (pt[0] > north[0]) north = pt;
    }
    return [north[0] + 0.00025, north[1]];
}

let areasRenderedSig = null;
function renderAreaLayer(items) {
    if (!areaLayer) return;

    // Same reopen-across-rerender dance as renderSectorLayer below.
    let openAreaId = null;
    areaLayer.eachLayer(layer => { if (layer.areaId !== undefined && layer.isPopupOpen && layer.isPopupOpen()) openAreaId = layer.areaId; });
    areaLayer.clearLayers();
    let reopenAreaLayer = null;

    items.forEach(item => {
        const rollup = `<div class="small mt-1">${t('sector.area_rollup', {completed: item.completed_count, total: item.sector_count})}</div>`;
        // Divide vs Clear are mutually exclusive by design — re-dividing an
        // area that already has sectors would fan a second, overlapping set
        // from scratch, so the supported path is clear-then-redivide, not
        // both actions available at once.
        const divideOrClearBtn = item.sector_count > 0
            ? `<button type="button" class="btn btn-sm btn-outline-warning mt-1 area-clear-sectors-btn" data-id="${item.id}">${t('sector.clear_area_sectors_btn')}</button>`
            : `<button type="button" class="btn btn-sm btn-outline-primary mt-1 area-divide-btn" data-id="${item.id}">${t('sector.divide_btn')}</button>`;
        const manageHtml = item.can_manage ? `
            <div class="mt-2">
                ${divideOrClearBtn}
                <button type="button" class="btn btn-sm btn-outline-danger mt-1 area-delete-btn" data-id="${item.id}">${t('common.delete')}</button>
            </div>` : '';
        const popupHtml = `<strong>${escapeHtml(item.label)}</strong>${rollup}${manageHtml}`;

        const layer = L.polygon(item.geo, {pane: 'areaPane', color: '#dc3545', weight: 4, dashArray: '10,6', fillColor: '#dc3545', fillOpacity: 0.06}).addTo(areaLayer).bindPopup(popupHtml);
        L.marker(areaLabelAnchor(item.geo), {icon: L.divIcon({className: '', iconSize: [0, 0]}), interactive: false})
            .bindTooltip(escapeHtml(item.label), {permanent: true, direction: 'center', className: 'wr-polygon-label', interactive: false})
            .addTo(areaLayer);
        layer.areaId = item.id;
        if (String(item.id) === String(openAreaId)) reopenAreaLayer = layer;
    });

    if (reopenAreaLayer) reopenAreaLayer.openPopup();
}
areaLayer?.on('popupopen', event => {
    const popupEl = event.popup.getElement();
    const divideBtn = popupEl.querySelector('.area-divide-btn');
    if (divideBtn) divideBtn.addEventListener('click', () => { map.closePopup(); openDivideSectorsForArea(parseInt(divideBtn.dataset.id, 10)); });
    const clearSectorsBtn = popupEl.querySelector('.area-clear-sectors-btn');
    if (clearSectorsBtn) clearSectorsBtn.addEventListener('click', () => clearAreaSectors(clearSectorsBtn.dataset.id));
    const delBtn = popupEl.querySelector('.area-delete-btn');
    if (delBtn) delBtn.addEventListener('click', () => areaDelete(delBtn.dataset.id));
});

// Restricted (hazard/danger) areas — solid red border + red diagonal-hatch
// fill (restrictedHatch pattern, defined once at map init above), rendered
// in restrictedAreaPane (z-index 700) so they sit above every other layer on
// the map, admin-drawn/managed only (field mode has no map to show these on;
// the volunteer-facing side of this feature is purely the full-screen alarm,
// wired separately below).
function renderRestrictedAreaLayer(items) {
    if (!restrictedAreaLayer) return;

    let openId = null;
    restrictedAreaLayer.eachLayer(layer => { if (layer.restrictedAreaId !== undefined && layer.isPopupOpen && layer.isPopupOpen()) openId = layer.restrictedAreaId; });
    restrictedAreaLayer.clearLayers();
    let reopenLayer = null;

    items.forEach(item => {
        // canManageIncidents is really just canManageWarRoom under an
        // incident-specific name (see its own declaration) — reused here too.
        // Server already rejects a non-admin's delete POST either way
        // (mission-restricted-area.php is admin-only for every mutation),
        // but showing a button that always errors on click is bad UX on its
        // own — sectors/buildings already hide their own manage controls the
        // same way (item.can_manage), this just brings restricted areas in
        // line with that, closing the gap the visibility fix above exposed
        // (non-admins never saw this popup at all before that fix).
        const popupHtml = `<strong>${escapeHtml(item.label)}</strong>` +
            (canManageIncidents ? `
            <div class="mt-2">
                <button type="button" class="btn btn-sm btn-outline-danger restricted-area-delete-btn" data-id="${item.id}">${t('common.delete')}</button>
            </div>` : '');
        const layer = L.polygon(item.geo, {pane: 'restrictedAreaPane', color: '#dc3545', weight: 3, fillColor: 'url(#restrictedHatch)', fillOpacity: 0.55}).addTo(restrictedAreaLayer).bindPopup(popupHtml);
        layer.bindTooltip(escapeHtml(item.label), {permanent: true, direction: 'center', className: 'wr-polygon-label wr-polygon-label-restricted', interactive: false});
        layer.restrictedAreaId = item.id;
        if (String(item.id) === String(openId)) reopenLayer = layer;
    });

    if (reopenLayer) reopenLayer.openPopup();
}
restrictedAreaLayer?.on('popupopen', event => {
    const popupEl = event.popup.getElement();
    const delBtn = popupEl.querySelector('.restricted-area-delete-btn');
    if (delBtn) delBtn.addEventListener('click', () => restrictedAreaDelete(delBtn.dataset.id));
});
function restrictedAreaDelete(id) {
    const area = restrictedAreas.find(a => String(a.id) === String(id));
    if (!confirm(t('restricted_area.delete_confirm', {label: area ? area.label : ''}))) return;
    const data = new URLSearchParams({csrf_token: csrfToken, mission_id: <?= $missionId ?>, action: 'delete', id});
    fetch('mission-restricted-area.php', {method:'POST', body:data}).then(r => r.json()).then(result => {
        if (result.ok) {
            if (map) map.closePopup();
            restrictedAreas = restrictedAreas.filter(a => String(a.id) !== String(id));
            renderRestrictedAreaLayer(restrictedAreas);
            renderRestrictedAreasList(restrictedAreas);
        }
    });
}

// Dimmed, read-only, tooltip-only reference layers — every composer map that
// opens as a popup over the live map (dispatch, search-area, divide-into-
// sectors, split-sector, restricted-area, route) must show everything
// already on the live map, not just its own specific entity type, so an
// admin drawing something new is never doing it blind. One small function
// per entity type (each takes the composer's own ref layer, not a fixed
// one) plus renderFullMapReference() below as the one-call convenience most
// composers actually want. Top-level, not nested in any one composer's
// IIFE, since every composer needs to call these on its own layer.
function renderPinsReference(layer) {
    if (!layer) return;
    const statusColors = {needs_help:'#dc2626', on_site:'#198754', on_way:'#f59e0b'};
    pins.forEach(pin => {
        const color = pin.team_color || statusColors[pin.status] || '#2563eb';
        L.circleMarker([pin.lat, pin.lng], {radius:6, weight:2, color:'#fff', fillColor:color, fillOpacity:0.55, opacity:0.6})
            .addTo(layer).bindTooltip(escapeHtml(pin.name));
    });
}
function renderDispatchesReference(layer) {
    if (!layer) return;
    dispatches.forEach(item => {
        const tooltip = item.label ? escapeHtml(item.label) : escapeHtml(item.team_label);
        if (item.type === 'point') {
            const icon = L.divIcon({className:'', html:'<i class="bi bi-geo-alt-fill" style="font-size:22px;color:#7c3aed;opacity:0.55;filter:drop-shadow(0 1px 2px #0008);"></i>', iconSize:[22,22], iconAnchor:[11,20]});
            L.marker([item.geo.lat, item.geo.lng], {icon}).addTo(layer).bindTooltip(tooltip);
        } else if (item.type === 'polygon' && item.geo && item.geo.length) {
            L.polygon(item.geo, {color:'#7c3aed', weight:2, opacity:0.5, dashArray:'6,4', fillOpacity:0.05}).addTo(layer).bindTooltip(tooltip);
        }
    });
}
function renderSearchAreasReference(layer) {
    if (!layer) return;
    areas.forEach(item => {
        L.polygon(item.geo, {color:'#495057', weight:2, opacity:0.5, dashArray:'8,5', fillOpacity:0.05}).addTo(layer).bindTooltip(escapeHtml(item.label));
    });
}
function renderSectorsReference(layer) {
    if (!layer) return;
    sectors.forEach(item => {
        const color = SECTOR_STATUS_HEX[item.status] || '#6c757d';
        L.polygon(item.geo, {color, weight:1.5, opacity:0.5, fillOpacity:0.1}).addTo(layer).bindTooltip(escapeHtml(item.label));
    });
}
function renderRestrictedAreasReference(layer) {
    if (!layer) return;
    restrictedAreas.forEach(item => {
        L.polygon(item.geo, {color:'#dc3545', weight:2, opacity:0.6, dashArray:'4,3', fillOpacity:0.08}).addTo(layer).bindTooltip(escapeHtml(item.label));
    });
}
function renderRoutesReference(layer) {
    if (!layer) return;
    (routes || []).filter(r => r.status === 'active' && r.waypoints && r.waypoints.length).forEach(route => {
        const coords = route.waypoints.map(w => [w.lat, w.lng]);
        const style = {color: route.team_color_bg || '#0d6efd', weight:2, opacity:0.5, dashArray:'6,6'};
        const line = (route.is_closed_loop && coords.length >= 3)
            ? L.polygon(coords, Object.assign({fillOpacity:0}, style))
            : coords.length >= 2 ? L.polyline(coords, style) : null;
        if (line) line.addTo(layer).bindTooltip(escapeHtml(route.team_label || ''));
    });
}
// Mirrors renderAnnotations()'s 3 shape types (same ANNOTATION_COLOR) but
// dimmed and with no click-to-erase handler — read-only reference, same
// relationship renderRouteComposerAnnotations() already has to the live
// annotation layer, just parameterized to any composer's own ref layer
// instead of always routeComposerAnnotationLayer.
function renderAnnotationsReference(layer) {
    if (!layer) return;
    annotations.forEach(item => {
        if (item.type === 'freehand') {
            L.polyline(item.geo, {color: ANNOTATION_COLOR, weight:3, opacity:0.55}).addTo(layer);
        } else if (item.type === 'arrow') {
            const [p1, p2] = item.geo;
            L.polyline(item.geo, {color: ANNOTATION_COLOR, weight:2, opacity:0.55}).addTo(layer);
            const brng = bearing(L.latLng(p1[0], p1[1]), L.latLng(p2[0], p2[1]));
            const headIcon = L.divIcon({className:'', html:`<div class="wr-anno-arrowhead" style="transform:rotate(${brng}deg);border-bottom-color:${ANNOTATION_COLOR};opacity:.55"></div>`, iconSize:[16,16], iconAnchor:[8,8]});
            L.marker(p2, {icon: headIcon}).addTo(layer);
        } else if (item.type === 'text') {
            const icon = L.divIcon({className:'', html:`<span class="wr-anno-text-label" style="background:${ANNOTATION_COLOR};opacity:.55">${escapeHtml(item.label)}</span>`, iconAnchor:[0, 12]});
            L.marker([item.geo.lat, item.geo.lng], {icon}).addTo(layer);
        }
    });
}
// Single dimmed reference pin for the mission's missing person (if the
// mission is that type and a last-known location has been set) — every
// composer (dispatch, sector split, new search area, restricted area,
// route) needs this as context, e.g. "don't route the team away from where
// they were last seen", without duplicating the lookup in each one.
function renderMissingPersonReference(layer) {
    if (!layer) return;
    if (!missingPerson || missingPerson.last_seen_lat === null || missingPerson.last_seen_lat === undefined) return;
    const icon = L.divIcon({className:'', html:'<i class="bi bi-person-fill" style="font-size:22px;color:#212529;opacity:0.6;filter:drop-shadow(0 1px 2px #0008);"></i>', iconSize:[22,22], iconAnchor:[11,20]});
    L.marker([missingPerson.last_seen_lat, missingPerson.last_seen_lng], {icon}).addTo(layer).bindTooltip(escapeHtml(missingPerson.full_name));
}
// Same LPB_RING_TABLE-driven rings as renderSearchRingsLayer() on the live
// map, but dimmed/tooltip-only like every other reference layer above, and
// gated by searchRingsEnabled — the first reference layer that needs its own
// settings check, since none of the others here are settings-gated.
function renderSearchRingsReference(layer) {
    if (!layer) return;
    if (!searchRingsEnabled) return;
    if (!missingPerson || missingPerson.last_seen_lat === null || missingPerson.last_seen_lat === undefined) return;
    const radii = LPB_RING_TABLE[missingPerson.subject_category];
    if (!radii) return;
    const center = [missingPerson.last_seen_lat, missingPerson.last_seen_lng];
    const pct = [25, 50, 75, 95];
    for (let i = 3; i >= 0; i--) {
        const km = (radii[i] / 1000).toLocaleString(jsLocale, {minimumFractionDigits: 1, maximumFractionDigits: 1});
        L.circle(center, {radius: radii[i], color:'#7c3aed', weight:1, opacity:0.35, fillColor:'#7c3aed', fillOpacity:0.03})
            .addTo(layer).bindTooltip(t('missing_person.ring_tooltip', {pct: pct[i], km}));
    }
}
// The one-call version most composers actually want: everything, in back-
// to-front paint order (pins first so later shapes don't visually bury the
// small dots, but pins still show — bindTooltip works regardless of order).
function renderFullMapReference(layer) {
    if (!layer) return;
    renderSearchAreasReference(layer);
    renderSectorsReference(layer);
    renderRestrictedAreasReference(layer);
    renderRoutesReference(layer);
    renderAnnotationsReference(layer);
    renderDispatchesReference(layer);
    renderPinsReference(layer);
    renderMissingPersonReference(layer);
    renderSearchRingsReference(layer);
}

// restrictedAreasCard's flat list of drawn zones — the map popup (above) is
// the primary way to manage one, this is just a non-map-dependent secondary
// surface, same relationship sectorsListCard has to renderSectorLayer's
// popups.
function renderRestrictedAreasList(items) {
    const list = document.getElementById('restrictedAreasList');
    if (!list) return;
    if (!items.length) {
        list.innerHTML = '<p class="text-muted mb-0 small">' + t('restricted_area.empty_list') + '</p>';
        return;
    }
    list.innerHTML = items.map(a => `
        <div class="d-flex justify-content-between align-items-center border rounded p-2 mb-1">
            <strong class="small">${escapeHtml(a.label)}</strong>
            <button type="button" class="btn btn-sm btn-outline-danger restricted-area-list-delete-btn" data-id="${a.id}">${t('common.delete')}</button>
        </div>
    `).join('');
    list.querySelectorAll('.restricted-area-list-delete-btn').forEach(btn => btn.addEventListener('click', () => restrictedAreaDelete(btn.dataset.id)));
}

// Breach list — structural clone of renderSosAlerts (same border-danger card
// shape, same guestNameHtml() treatment, same acknowledge-then-resolve two-
// step button), pointed at mission-restricted-area.php's own actions instead
// of mission-sos.php's. Unlike SOS, resolving here doesn't require the
// breach to already be acknowledged (mirrors mission-restricted-area.php's
// own resolve action, which COALESCEs acknowledged_at if it was skipped) —
// an admin force-clearing a stuck alarm shouldn't need two clicks.
// items is the full history (open + resolved), not the alarm-driving
// open-only array — a resolved row (whether resolved manually or auto-
// resolved by deleting its zone) stays visible here, muted and without
// action buttons, instead of silently vanishing the moment it's no longer
// "active." "Auto-resolve not auto-erase" holding in the UI, not just the DB.
function renderRestrictedAreaBreachesList(items) {
    const list = document.getElementById('restrictedAreaBreachesList');
    if (!list) return;
    if (!items.length) {
        list.innerHTML = '<p class="text-muted mb-0 small">' + t('restricted_area.breaches_empty') + '</p>';
        return;
    }
    list.innerHTML = items.map(b => {
        const resolved = !!b.resolved_at;
        const statusOrActions = resolved
            ? `<div class="small fst-italic text-muted">${t('restricted_area.resolved_at_prefix', {time: b.resolved_at})}${b.resolved_by_name ? ` (${escapeHtml(b.resolved_by_name)})` : ''}</div>`
            : `<div class="mt-1 d-flex gap-1">
                ${!b.acknowledged_at ? `<button type="button" class="btn btn-sm btn-warning w-100 restricted-area-ack-btn" data-breach-id="${b.id}">${t('banner.ack_btn')}</button>` : ''}
                <button type="button" class="btn btn-sm btn-success w-100 restricted-area-resolve-btn" data-breach-id="${b.id}">${t('shortage.resolve_btn')}</button>
            </div>`;
        return `
        <div class="border ${resolved ? 'border-secondary' : 'border-danger'} rounded p-2 mb-2${resolved ? ' opacity-75' : ''}">
            <div><strong>${resolved ? '✅' : '⚠️'} ${b.team_label}</strong> — ${guestNameHtml(b.user_name, b.is_external, b.home_team_name, b.home_team_color_bg, b.home_team_color_fg, b.guest_country_code)}</div>
            <div class="small">${escapeHtml(b.area_label)}</div>
            <div class="text-muted" style="font-size:.75rem;">${b.created_at}${b.exited_at ? t('restricted_area.exited_at_prefix', {time: b.exited_at}) : t('restricted_area.still_inside')}${b.acknowledged_at ? t('sos.ack_at_prefix', {time: b.acknowledged_at}) : ''}</div>
            ${statusOrActions}
        </div>`;
    }).join('');
    list.querySelectorAll('.restricted-area-ack-btn').forEach(btn => btn.addEventListener('click', () => {
        btn.disabled = true;
        const data = new URLSearchParams({csrf_token: csrfToken, mission_id: <?= $missionId ?>, action: 'acknowledge', id: btn.dataset.breachId});
        fetch('mission-restricted-area.php', {method: 'POST', body: data}).then(r => r.json()).then(result => {
            if (result.ok) {
                // Acknowledging doesn't touch resolved_at/exited_at, so the
                // alarm's own open/anyOpen concept is unaffected — only the
                // list needs a refresh here, matching this action's original
                // (pre-history) behavior.
                restrictedAreaBreachHistory = result.breaches;
                renderRestrictedAreaBreachesList(restrictedAreaBreachHistory);
            } else { btn.disabled = false; alert(result.error || t('common.failed')); }
        }).catch(() => { btn.disabled = false; });
    }));
    list.querySelectorAll('.restricted-area-resolve-btn').forEach(btn => btn.addEventListener('click', () => {
        btn.disabled = true;
        const data = new URLSearchParams({csrf_token: csrfToken, mission_id: <?= $missionId ?>, action: 'resolve', id: btn.dataset.breachId});
        fetch('mission-restricted-area.php', {method: 'POST', body: data}).then(r => r.json()).then(result => {
            if (result.ok) {
                restrictedAreaBreachHistory = result.breaches;
                renderRestrictedAreaBreachesList(restrictedAreaBreachHistory);
                // Resolving DOES remove it from what the alarm considers
                // open — re-derive that subset from the fresh history rather
                // than issuing a second request for it.
                restrictedAreaBreaches = restrictedAreaBreachHistory.filter(b => !b.resolved_at);
                updateRestrictedAreaAlarmState(restrictedAreaBreaches);
            } else { btn.disabled = false; alert(result.error || t('common.failed')); }
        }).catch(() => { btn.disabled = false; });
    }));
}

let sectorsRenderedSig = null;
function addSectorBuildingMarker(b, item) {
    const hasRequired = b.floors.some(f => f.is_required);
    const bColor = !hasRequired ? '#6c757d' : (b.all_required_checked ? '#198754' : '#dc3545');
    const icon = L.divIcon({
        className: '',
        html: `<div style="background:${bColor};color:#fff;width:22px;height:22px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;border:2px solid #fff;box-shadow:0 1px 4px #0008;"><i class="bi bi-building"></i></div>`,
        iconSize: [22, 22], iconAnchor: [11, 11],
    });
    const canActOnBuildings = item.can_self_report || item.can_manage;
    const delBuildingBtn = item.can_manage ? `<button type="button" class="btn btn-sm btn-outline-danger mt-1 sector-building-delete-btn" data-id="${b.id}">${t('common.delete')}</button>` : '';
    // Same navUrl pattern + map.navigate_btn label as a volunteer's own GPS
    // pin popup (renderPinMarker above) — no origin means Google Maps routes
    // from the device's current location, so this works without ever asking
    // this page for geolocation permission.
    const navUrl = `https://www.google.com/maps/dir/?api=1&destination=${b.lat},${b.lng}&travelmode=driving`;
    const navBtn = `<br><a href="${navUrl}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary mt-1">${t('map.navigate_btn')}</a>`;
    const bPopupHtml = `<strong>${escapeHtml(b.label)}</strong>${sectorFloorChecklistHtml(b, canActOnBuildings)}${navBtn}${delBuildingBtn}`;
    const bLayer = L.marker([b.lat, b.lng], {icon}).addTo(sectorBuildingLayer).bindPopup(bPopupHtml);
    bLayer.buildingId = b.id;
}

function renderSectorLayer(items) {
    if (!sectorLayer || !sectorBuildingLayer) return;

    // A sector/building whose popup is open right now is left completely
    // untouched below — not removed, not rebuilt, not reopened — instead of
    // the old "clear everything, rebuild everything, then reopen whichever
    // one was open" dance shared with renderDispatches() above. That old
    // dance kept the popup LOOKING open across every poll tick, but it was
    // still fresh DOM underneath: the team/status <select>s and the note
    // <input> inside it got silently replaced with server-state defaults
    // every ~5s, discarding a pick or a half-typed note the admin hadn't
    // submitted yet. Reported live: assigning a team kept getting reset
    // mid-interaction. Skipping the rebuild for just the open one fixes it
    // while everything else on the map still updates normally. A sector and
    // one of its own buildings can be open independently of each other
    // (separate layer groups, separate click targets), so both are tracked
    // and preserved on their own.
    let openSectorLayer = null, openSectorId = null;
    sectorLayer.eachLayer(layer => {
        if (layer.sectorId !== undefined && layer.isPopupOpen && layer.isPopupOpen()) {
            openSectorLayer = layer;
            openSectorId = layer.sectorId;
        }
    });
    let openBuildingLayer = null, openBuildingId = null;
    sectorBuildingLayer.eachLayer(layer => {
        if (layer.buildingId !== undefined && layer.isPopupOpen && layer.isPopupOpen()) {
            openBuildingLayer = layer;
            openBuildingId = layer.buildingId;
        }
    });
    // The "leave it untouched" protection above only makes sense while the
    // open sector/building still exists — a split deletes the original
    // sector (cascading its buildings) the moment the two halves are
    // created, and nothing else ever re-checks a preserved layer against
    // fresh data. Without this, an open-popup sector/building that gets
    // deleted out from under itself leaves its polygon (and permanent
    // label tooltip) orphaned on the map forever, since it's simultaneously
    // never rebuilt (it's "the open one") and never removed (it's not "the
    // open one" from the removal pass's perspective either, once it no
    // longer matches anything real).
    if (openSectorId !== null && !items.some(item => String(item.id) === String(openSectorId))) {
        openSectorLayer = null;
        openSectorId = null;
    }
    if (openBuildingId !== null && !items.some(item => item.buildings.some(b => String(b.id) === String(openBuildingId)))) {
        openBuildingLayer = null;
        openBuildingId = null;
    }

    sectorLayer.eachLayer(layer => { if (layer !== openSectorLayer) sectorLayer.removeLayer(layer); });
    sectorBuildingLayer.eachLayer(layer => { if (layer !== openBuildingLayer) sectorBuildingLayer.removeLayer(layer); });

    items.forEach(item => {
        if (String(item.id) !== String(openSectorId)) {
            const color = SECTOR_STATUS_HEX[item.status] || '#6c757d';
            const buildingsSummary = item.buildings.length
                ? `<div class="small mt-1">🏢 ${item.buildings.filter(b => b.all_required_checked).length}/${item.buildings.length}</div>`
                : '';
            const completePrompt = (item.buildings.length && item.all_buildings_complete && item.can_self_report)
                ? `<div class="small text-success fw-semibold mt-1">${t('sector.all_floors_checked_prompt')}</div>` : '';
            const ackBtn = item.can_acknowledge
                ? `<br><button type="button" class="btn btn-sm btn-warning w-100 mt-1 sector-ack-btn" data-id="${item.id}">${t('banner.ack_btn')}</button>`
                : '';
            const selfReportBtn = item.can_self_report
                ? `<br><div class="mt-1 sector-advance-group">
                    <input type="text" class="form-control form-control-sm mb-1 sector-advance-note" placeholder="${t('sector.note_placeholder')}" maxlength="500">
                    <button type="button" class="btn btn-sm btn-primary w-100 sector-advance-btn" data-id="${item.id}" data-status="${item.next_status}">${sectorActionLabel(item.status)}</button>
                </div>`
                : '';
            // Admin can never pick 'assigned' directly (mission-sector.php
            // rejects it — that value only ever results from assigning a team
            // to a not_started sector), so it's deliberately not an option here.
            const manageHtml = item.can_manage ? `
                <div class="mt-2">
                    <select class="form-select form-select-sm sector-team-select mb-1" data-id="${item.id}">
                        <option value="">${escapeHtml(t('sector.unassigned_option'))}</option>
                        ${teams.map(tm => `<option value="${tm.id}" ${String(tm.id) === String(item.team_id) ? 'selected' : ''}>${escapeHtml(tm.label)}</option>`).join('')}
                    </select>
                    <select class="form-select form-select-sm sector-status-select" data-id="${item.id}">
                        ${['not_started','en_route','in_progress','completed','needs_recheck'].map(s => `<option value="${s}" ${s === item.status ? 'selected' : ''}>${escapeHtml(t('sector.status.' + s))}</option>`).join('')}
                    </select>
                    <button type="button" class="btn btn-sm btn-outline-primary mt-1 sector-add-building-btn" data-id="${item.id}"><i class="bi bi-building-add me-1"></i>${t('sector.add_building_btn')}</button>
                    ${item.status === 'not_started' && !item.buildings.length ? `<button type="button" class="btn btn-sm btn-outline-secondary mt-1 sector-split-btn" data-id="${item.id}"><i class="bi bi-scissors me-1"></i>${t('sector.split_btn')}</button>` : ''}
                    <button type="button" class="btn btn-sm btn-outline-danger mt-1 sector-delete-btn" data-id="${item.id}">${t('common.delete')}</button>
                </div>` : '';
            const popupHtml = `<strong>${escapeHtml(item.label)}</strong><br>` +
                `<span class="badge bg-${item.status_color}">${escapeHtml(item.status_label)}</span> ${escapeHtml(item.team_label)}${sectorCoverageBadgeHtml(item)}` +
                buildingsSummary + completePrompt + ackBtn + selfReportBtn + manageHtml;

            const layer = L.polygon(item.geo, {pane: 'sectorPane', color, fillColor: color, fillOpacity: 0.35, weight: 2}).addTo(sectorLayer).bindPopup(popupHtml);
            // Same sectorCoverageBadgeHtml() as the popup above (so the
            // buildings-suppress-% rule and the ⚠️ low-coverage warning stay
            // identical in both places) — but only added here, on-map, while
            // Verified Coverage mode is active, so scanning the map answers
            // "which sectors still need walking" without opening every popup.
            const tooltipCoverage = coverageModeActive ? sectorCoverageBadgeHtml(item) : '';
            layer.bindTooltip(escapeHtml(item.label) + tooltipCoverage, {permanent: true, direction: 'center', className: 'wr-polygon-label', interactive: false});
            layer.sectorId = item.id;
            // Verified Coverage gap-cell detail is only ever drawn for whichever
            // sector's popup is currently open (never all sectors at once — see
            // drawCoverageGapCells) so map SVG node count stays bounded
            // regardless of mission size.
            layer.on('popupopen', () => drawCoverageGapCells(item.id));
            layer.on('popupclose', () => coverageLayer?.clearLayers());
        }

        item.buildings.forEach(b => {
            if (String(b.id) === String(openBuildingId)) return;
            addSectorBuildingMarker(b, item);
        });
    });
}
sectorLayer?.on('popupopen', event => {
    const popupEl = event.popup.getElement();
    const ackBtn = popupEl.querySelector('.sector-ack-btn');
    if (ackBtn) ackBtn.addEventListener('click', () => sectorAcknowledge(ackBtn.dataset.id, ackBtn));
    const advBtn = popupEl.querySelector('.sector-advance-btn');
    if (advBtn) advBtn.addEventListener('click', () => sectorSelfAdvance(advBtn.dataset.id, advBtn.dataset.status, advBtn));
    const teamSelect = popupEl.querySelector('.sector-team-select');
    if (teamSelect) teamSelect.addEventListener('change', () => sectorAdminSetTeam(teamSelect.dataset.id, teamSelect.value, teamSelect));
    const statusSelect = popupEl.querySelector('.sector-status-select');
    if (statusSelect) statusSelect.addEventListener('change', () => sectorAdminSetStatus(statusSelect.dataset.id, statusSelect.value, statusSelect));
    const delBtn = popupEl.querySelector('.sector-delete-btn');
    if (delBtn) delBtn.addEventListener('click', () => sectorDelete(delBtn.dataset.id));
    const addBuildingBtn = popupEl.querySelector('.sector-add-building-btn');
    if (addBuildingBtn) addBuildingBtn.addEventListener('click', () => {
        // One-shot: the NEXT click anywhere on the live map places a
        // building for this sector (see the map.on('click', ...) handler
        // below) — mirrors the annotation toolbar's own click-to-place
        // tools (arrow/text) rather than a native prompt(), which this
        // file's own convention deliberately avoids for real data entry.
        addingBuildingToSectorId = parseInt(addBuildingBtn.dataset.id, 10);
        map.closePopup();
        document.getElementById('mapCard')?.classList.add('wr-draw-active');
    });
    const splitBtn = popupEl.querySelector('.sector-split-btn');
    if (splitBtn) splitBtn.addEventListener('click', () => { map.closePopup(); openSplitSectorModal(parseInt(splitBtn.dataset.id, 10)); });
});
sectorBuildingLayer?.on('popupopen', event => {
    const popupEl = event.popup.getElement();
    popupEl.querySelectorAll('.sector-floor-btn').forEach(btn => btn.addEventListener('click', () => sectorFloorToggle(btn.dataset.id, btn.dataset.action, btn)));
    const delBtn = popupEl.querySelector('.sector-building-delete-btn');
    if (delBtn) delBtn.addEventListener('click', () => sectorDeleteBuilding(delBtn.dataset.id));
});

// Admin-facing full list (sectorsListCard) — an at-a-glance overview with
// the same actions as the map popups (clicking a row locates+opens it on
// the map too), for command staff who'd rather scan a list than click
// through polygons one at a time.
let sectorsListRenderedSig = null;
function sectorListRowHtml(item) {
    const buildingsSummary = item.buildings.length
        ? `<div class="small">🏢 ${item.buildings.filter(b => b.all_required_checked).length}/${item.buildings.length}</div>` : '';
    const ackBtn = item.can_acknowledge
        ? `<button type="button" class="btn btn-sm btn-warning w-100 mt-1 sector-ack-btn" data-id="${item.id}">${t('banner.ack_btn')}</button>`
        : '';
    const advanceBtn = item.can_self_report
        ? `<div class="mt-1 sector-advance-group">
            <input type="text" class="form-control form-control-sm mb-1 sector-advance-note" placeholder="${t('sector.note_placeholder')}" maxlength="500">
            <button type="button" class="btn btn-sm btn-primary w-100 sector-advance-btn" data-id="${item.id}" data-status="${item.next_status}">${sectorActionLabel(item.status)}</button>
        </div>`
        : '';
    const manageHtml = item.can_manage ? `
        <select class="form-select form-select-sm mt-1 sector-team-select" data-id="${item.id}">
            <option value="">${escapeHtml(t('sector.unassigned_option'))}</option>
            ${teams.map(tm => `<option value="${tm.id}" ${String(tm.id) === String(item.team_id) ? 'selected' : ''}>${escapeHtml(tm.label)}</option>`).join('')}
        </select>
        <select class="form-select form-select-sm mt-1 sector-status-select" data-id="${item.id}">
            ${['not_started','en_route','in_progress','completed','needs_recheck'].map(s => `<option value="${s}" ${s === item.status ? 'selected' : ''}>${escapeHtml(t('sector.status.' + s))}</option>`).join('')}
        </select>
        ${item.status === 'not_started' && !item.buildings.length ? `<button type="button" class="btn btn-sm btn-outline-secondary mt-1 sector-split-btn" data-id="${item.id}"><i class="bi bi-scissors me-1"></i>${t('sector.split_btn')}</button>` : ''}
        <button type="button" class="btn btn-sm btn-outline-danger mt-1 sector-delete-btn" data-id="${item.id}">${t('common.delete')}</button>` : '';
    return `<div class="border rounded p-2 mb-2 sector-list-row" data-id="${item.id}" style="cursor:pointer;">
        <div class="d-flex justify-content-between align-items-start">
            <strong>${escapeHtml(item.label)}</strong>
            <span class="badge bg-${item.status_color}">${escapeHtml(item.status_label)}</span>${sectorCoverageBadgeHtml(item)}
        </div>
        <div class="small text-muted">${escapeHtml(item.team_label)}</div>
        ${buildingsSummary}${ackBtn}${advanceBtn}${manageHtml}
    </div>`;
}
// Grouped by area (every sector belongs to exactly one) rather than one flat
// list — an admin overseeing several search areas at once needs to scan by
// area first, sector second. References the `areas` global directly rather
// than taking it as a second parameter, matching how this function already
// reads the `sectors` global directly below instead of just `items`.
function renderSectorsList(items) {
    // sectorCoverageById is deliberately part of this signature, not just
    // items/areas — otherwise this memoization can never detect "coverage
    // data just arrived" (sectors/areas themselves don't change when
    // coverage is fetched), so a concurrent 5s poll tick's own
    // coverage-unaware call can win the race and permanently mask the
    // badge-bearing render behind a matching-but-stale sig.
    const sig = JSON.stringify(items) + '|' + JSON.stringify(areas) + '|' + JSON.stringify(sectorCoverageById);
    if (sig === sectorsListRenderedSig) return;
    sectorsListRenderedSig = sig;

    const list = document.getElementById('sectorsList');
    if (!list) return;
    if (!areas.length) {
        list.innerHTML = '<p class="text-muted mb-0">' + t('sector.area_empty_list') + '</p>';
        return;
    }
    list.innerHTML = areas.map(area => {
        const areaSectors = items.filter(s => s.area_id === area.id);
        const rollup = t('sector.area_rollup', {completed: area.completed_count, total: area.sector_count});
        const delBtn = area.can_manage ? `<button type="button" class="btn btn-sm btn-outline-danger area-delete-btn" data-id="${area.id}">${t('common.delete')}</button>` : '';
        const rows = areaSectors.length ? areaSectors.map(sectorListRowHtml).join('') : `<p class="text-muted small">${t('sector.empty_list')}</p>`;
        return `<div class="mb-3">
            <div class="d-flex justify-content-between align-items-center bg-light rounded px-2 py-1 mb-2 area-list-header" data-id="${area.id}" style="cursor:pointer;">
                <div><i class="bi bi-bounding-box me-1"></i><strong>${escapeHtml(area.label)}</strong> <span class="small text-muted ms-1">${rollup}</span></div>
                ${delBtn}
            </div>
            <div class="ps-2">${rows}</div>
        </div>`;
    }).join('');

    list.querySelectorAll('.sector-list-row').forEach(row => row.addEventListener('click', e => {
        if (e.target.closest('button, select, input')) return;
        if (fieldMode || !map) return;
        const item = sectors.find(s => String(s.id) === row.dataset.id);
        if (item && item.geo && item.geo.length) {
            map.fitBounds(L.latLngBounds(item.geo), {padding: [40, 40], maxZoom: 17});
            sectorLayer.eachLayer(l => { if (String(l.sectorId) === row.dataset.id) l.openPopup(); });
        }
    }));
    list.querySelectorAll('.sector-ack-btn').forEach(btn => btn.addEventListener('click', e => { e.stopPropagation(); sectorAcknowledge(btn.dataset.id, btn); }));
    list.querySelectorAll('.sector-advance-btn').forEach(btn => btn.addEventListener('click', e => { e.stopPropagation(); sectorSelfAdvance(btn.dataset.id, btn.dataset.status, btn); }));
    list.querySelectorAll('.sector-team-select').forEach(sel => sel.addEventListener('change', () => sectorAdminSetTeam(sel.dataset.id, sel.value, sel)));
    list.querySelectorAll('.sector-status-select').forEach(sel => sel.addEventListener('change', () => sectorAdminSetStatus(sel.dataset.id, sel.value, sel)));
    list.querySelectorAll('.sector-split-btn').forEach(btn => btn.addEventListener('click', e => { e.stopPropagation(); openSplitSectorModal(parseInt(btn.dataset.id, 10)); }));
    list.querySelectorAll('.sector-delete-btn').forEach(btn => btn.addEventListener('click', e => { e.stopPropagation(); sectorDelete(btn.dataset.id); }));

    list.querySelectorAll('.area-list-header').forEach(header => header.addEventListener('click', e => {
        if (e.target.closest('button')) return;
        if (fieldMode || !map) return;
        const area = areas.find(a => String(a.id) === header.dataset.id);
        if (area && area.geo && area.geo.length) {
            map.fitBounds(L.latLngBounds(area.geo), {padding: [40, 40], maxZoom: 16});
            areaLayer.eachLayer(l => { if (String(l.areaId) === header.dataset.id) l.openPopup(); });
        }
    }));
    list.querySelectorAll('.area-delete-btn').forEach(btn => btn.addEventListener('click', e => { e.stopPropagation(); areaDelete(btn.dataset.id); }));
}

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
    } else if (addingBuildingToSectorId !== null) {
        // Same click-opens-a-popup-with-an-inline-form technique as the
        // 'text' annotation tool just above, not a native prompt() — one-
        // shot (not a persistent toggleable tool), so the state is cleared
        // as soon as this click is consumed, regardless of whether the
        // form is actually submitted afterward.
        const sectorId = addingBuildingToSectorId;
        const latlng = e.latlng;
        addingBuildingToSectorId = null;
        document.getElementById('mapCard')?.classList.remove('wr-draw-active');
        L.popup({closeOnClick: false})
            .setLatLng(latlng)
            .setContent(`<input type="text" maxlength="255" class="form-control form-control-sm mb-1" id="sectorBuildingLabelInput" placeholder="${t('sector.building_label_placeholder')}">
                          <input type="number" min="1" max="50" class="form-control form-control-sm mb-1" id="sectorBuildingFloorsInput" placeholder="${t('sector.floor_count_label')}">
                          <button type="button" class="btn btn-sm btn-primary w-100" id="sectorBuildingSave">${t('sector.add_building_btn')}</button>`)
            .openOn(map);
        setTimeout(() => {
            const input = document.getElementById('sectorBuildingLabelInput');
            if (!input) return;
            input.focus();
            const save = () => {
                const label = input.value.trim();
                const floorCount = parseInt(document.getElementById('sectorBuildingFloorsInput').value, 10);
                if (!label || !floorCount || floorCount < 1 || floorCount > 50) return;
                const data = new URLSearchParams({csrf_token: csrfToken, mission_id: <?= $missionId ?>, action: 'create_building', sector_id: sectorId, label, lat: latlng.lat, lng: latlng.lng, floor_count: floorCount});
                fetch('mission-sector.php', {method:'POST', body:data}).then(r => r.json()).then(result => {
                    map.closePopup();
                    if (result.ok) sectorRefreshAfter(result.sectors, result.areas);
                    else alert(result.error || t('common.send_failed'));
                });
            };
            document.getElementById('sectorBuildingSave')?.addEventListener('click', save);
        }, 0);
    }
});
if (missionLocation.lat) L.marker([missionLocation.lat, missionLocation.lng]).addTo(map).bindPopup('<strong>' + t('map.mission_point_label') + '</strong><br><?= h(addslashes($mission['title'])) ?>');

function updateMissingPersonLocationPreview() {
    // Guards every lookup, not just the first — none of these ids exist in
    // the DOM at all for a viewer who isn't $canManageWarRoom (the edit
    // modal is admin-only), and this runs unconditionally at map-init time
    // regardless of role.
    const preview = document.getElementById('missingPersonLocationPreview');
    const latEl = document.getElementById('missingPersonLat');
    const lngEl = document.getElementById('missingPersonLng');
    if (!preview || !latEl || !lngEl) return;
    const lat = latEl.value, lng = lngEl.value;
    preview.textContent = (lat && lng) ? `${parseFloat(lat).toFixed(5)}, ${parseFloat(lng).toFixed(5)}` : '';
}
// Dedicated map-picker modal for the last-known-location field — mirrors
// dispatchMapModal's lazy-init-on-first-open pattern (map creation is
// expensive, and the container has no real size until the modal has
// actually faded in, hence the invalidateSize() below). A single marker,
// not a shape/polygon like dispatch's — every click just replaces it.
(function() {
    const pickModalEl = document.getElementById('missingPersonPickMapModal');
    if (!pickModalEl) return;
    let pickMap = null, pickMarker = null, pickRefLayer = null;
    const confirmBtn = document.getElementById('missingPersonPickConfirmBtn');

    function setPickMarker(lat, lng) {
        if (pickMarker) pickMap.removeLayer(pickMarker);
        pickMarker = L.marker([lat, lng]).addTo(pickMap);
        confirmBtn.disabled = false;
    }

    pickModalEl.addEventListener('shown.bs.modal', () => {
        if (!pickMap) {
            const center = missionLocation.lat ? [missionLocation.lat, missionLocation.lng] : [37.97, 23.73];
            pickMap = L.map('missingPersonPickMap').setView(center, missionLocation.lat ? 13 : 7);
            addMapBaseLayers(pickMap, 'missingPersonPickSatelliteToggle');
            pickRefLayer = L.layerGroup().addTo(pickMap);
            pickMap.on('click', e => setPickMarker(e.latlng.lat, e.latlng.lng));
        }
        pickRefLayer.clearLayers();
        renderFullMapReference(pickRefLayer);
        // Re-seed from the form's current hidden values every open (not just
        // once at map-creation) — editing an existing point should show
        // where it currently is, not wherever it was left from a previous
        // pick-then-cancel in the same page visit.
        if (pickMarker) { pickMap.removeLayer(pickMarker); pickMarker = null; }
        confirmBtn.disabled = true;
        const curLat = document.getElementById('missingPersonLat').value;
        const curLng = document.getElementById('missingPersonLng').value;
        if (curLat && curLng) {
            setPickMarker(parseFloat(curLat), parseFloat(curLng));
            pickMap.setView([parseFloat(curLat), parseFloat(curLng)], 15);
        }
        setTimeout(() => pickMap.invalidateSize(), 100);
    });

    document.getElementById('missingPersonPickUseCenterBtn').addEventListener('click', () => {
        const c = pickMap.getCenter();
        setPickMarker(c.lat, c.lng);
    });

    confirmBtn.addEventListener('click', () => {
        if (!pickMarker) return;
        const ll = pickMarker.getLatLng();
        document.getElementById('missingPersonLat').value = ll.lat;
        document.getElementById('missingPersonLng').value = ll.lng;
        updateMissingPersonLocationPreview();
        bootstrap.Modal.getInstance(pickModalEl)?.hide();
        new bootstrap.Modal(document.getElementById('missingPersonEditModal')).show();
    });
})();
document.getElementById('missingPersonPickOnMapBtn')?.addEventListener('click', () => {
    bootstrap.Modal.getInstance(document.getElementById('missingPersonEditModal'))?.hide();
    bootstrap.Modal.getOrCreateInstance(document.getElementById('missingPersonPickMapModal')).show();
});
updateMissingPersonLocationPreview();
}

// Search-sector functions that MUST work in field mode (no map involved,
// deliberately declared outside the `if (!fieldMode)` block above — see the
// note next to SECTOR_STATUS_HEX for why a block-scoped `function` here
// would silently never be assigned in field mode). renderSectorLayer/
// renderSectorsList/sectorAdminSetStatus/sectorDelete/sectorDeleteBuilding
// stay inside that block on purpose — they're genuinely map/full-view-only.
function sectorRefreshAfter(newSectors, newAreas) {
    if (newSectors) sectors = newSectors;
    if (newAreas) areas = newAreas;
    if (!fieldMode) { renderSectorLayer(sectors); renderSectorsList(sectors); renderAreaLayer(areas); }
    renderMySectors(sectors);
}
// Separate from sectorSelfAdvance below — acknowledging a fresh assignment
// is its own explicit step, gated server-side (can_acknowledge / the
// `acknowledge` action) before the advance button even becomes available,
// same two-step shape as Route Orders' own ack-then-depart.
function sectorAcknowledge(id, btnEl) {
    if (btnEl) btnEl.disabled = true;
    const data = new URLSearchParams({csrf_token: csrfToken, mission_id: <?= $missionId ?>, action: 'acknowledge', id});
    fetch('mission-sector.php', {method:'POST', body:data}).then(r => r.json()).then(result => {
        if (result.ok) { if (map) map.closePopup(); sectorRefreshAfter(result.sectors, result.areas); }
        else { alert(result.error || t('common.send_failed')); if (btnEl) btnEl.disabled = false; }
    });
}
function sectorSelfAdvance(id, status, btnEl) {
    if (btnEl) btnEl.disabled = true;
    // Optional note, entered in the sibling input right next to the button —
    // deliberately not a separate popup/step, so the fast "just tap to
    // advance" case stays exactly one click; the note is purely additive.
    const noteInput = btnEl ? btnEl.closest('.sector-advance-group')?.querySelector('.sector-advance-note') : null;
    const note = noteInput ? noteInput.value.trim() : '';
    const data = new URLSearchParams({csrf_token: csrfToken, mission_id: <?= $missionId ?>, action: 'status', id, status});
    if (note) data.set('note', note);
    fetch('mission-sector.php', {method:'POST', body:data}).then(r => r.json()).then(result => {
        if (result.ok) { if (map) map.closePopup(); sectorRefreshAfter(result.sectors, result.areas); }
        else { alert(result.error || t('common.send_failed')); if (btnEl) btnEl.disabled = false; }
    });
}
function sectorFloorToggle(floorId, action, btnEl) {
    if (btnEl) btnEl.disabled = true;
    const data = new URLSearchParams({csrf_token: csrfToken, mission_id: <?= $missionId ?>, action, id: floorId});
    fetch('mission-sector.php', {method:'POST', body:data}).then(r => r.json()).then(result => {
        if (result.ok) { if (map) map.closePopup(); sectorRefreshAfter(result.sectors, result.areas); }
        else { alert(result.error || t('common.send_failed')); if (btnEl) btnEl.disabled = false; }
    });
}
// Keyed by the sector's CURRENT status (the action that would move it
// forward), not the target — 'assigned' and 'needs_recheck' both advance
// TO 'in_progress', so keying by target would collide between "start" and
// "resume" and show the wrong label for one of them.
function sectorActionLabel(currentStatus) {
    return {
        'assigned': t('sector.action.depart'),
        'en_route': t('sector.action.arrived'),
        'in_progress': t('sector.action.complete'),
        'completed': t('sector.action.flag_recheck'),
        'needs_recheck': t('sector.action.resume'),
    }[currentStatus] || '';
}
function sectorFloorChecklistHtml(building, canAct) {
    const requiredFloors = building.floors.filter(f => f.is_required);
    if (!requiredFloors.length) {
        return `<div class="small text-muted">${t('sector.floor_required_hint')}</div>`;
    }
    return requiredFloors.map(f => {
        const isChecked = f.checked_at !== null;
        const btn = canAct
            ? `<button type="button" class="btn btn-sm ${isChecked ? 'btn-outline-secondary' : 'btn-success'} sector-floor-btn" data-id="${f.id}" data-action="${isChecked ? 'uncheck_floor' : 'check_floor'}">${isChecked ? t('sector.floor_uncheck_btn') : t('sector.floor_check_btn')}</button>`
            : (isChecked ? '✅' : '');
        const meta = isChecked ? `<span class="text-muted small ms-1">(${escapeHtml(f.checked_by_name || '')}, ${f.checked_at})</span>` : '';
        const note = f.note ? `<div class="small fst-italic">"${escapeHtml(f.note)}"</div>` : '';
        return `<div class="d-flex justify-content-between align-items-center py-1">
            <span class="small">${escapeHtml(t('sector.floor_label', {n: f.floor_number}))}${meta}</span>${btn}
        </div>${note}`;
    }).join('');
}
// Field-mode-safe (no map): every sector assigned to the viewer's own team,
// with the full nested building/floor checklist inline — this is the
// primary real-world surface for a volunteer physically walking building to
// building, not a fallback for when the map isn't available.
function renderMySectors(items) {
    const list = document.getElementById('mySectorsList');
    if (!list) return;
    const mine = items.filter(s => s.is_my_team);
    if (!mine.length) {
        list.innerHTML = '<p class="text-muted mb-0 small">' + t('sector.my_empty') + '</p>';
        return;
    }
    list.innerHTML = mine.map(item => {
        const ackBtn = item.can_acknowledge
            ? `<button type="button" class="btn btn-sm btn-warning w-100 mt-1 sector-ack-btn" data-id="${item.id}">${t('banner.ack_btn')}</button>`
            : '';
        const advanceBtn = item.can_self_report
            ? `<div class="mt-1 sector-advance-group">
                <input type="text" class="form-control form-control-sm mb-1 sector-advance-note" placeholder="${t('sector.note_placeholder')}" maxlength="500">
                <button type="button" class="btn btn-sm btn-primary w-100 sector-advance-btn" data-id="${item.id}" data-status="${item.next_status}">${sectorActionLabel(item.status)}</button>
            </div>`
            : '';
        const completePrompt = (item.buildings.length && item.all_buildings_complete && item.can_self_report)
            ? `<div class="small text-success fw-semibold mt-1">${t('sector.all_floors_checked_prompt')}</div>` : '';
        const buildingsHtml = item.buildings.map(b => `
            <div class="border-top pt-1 mt-1">
                <div class="small fw-semibold">${escapeHtml(b.label)}${b.all_required_checked ? ' ✅' : ''}</div>
                ${sectorFloorChecklistHtml(b, item.can_self_report)}
            </div>`).join('');
        // Area label only, not full grouping (unlike sectorsListCard) — a
        // volunteer only ever sees their own team's handful of assigned
        // sectors here, not enough of them to need a grouped view.
        const area = areas.find(a => a.id === item.area_id);
        const areaTag = area ? `<div class="small text-muted">${escapeHtml(area.label)}</div>` : '';
        return `<div class="border rounded p-2 mb-2${item.status === 'needs_recheck' ? ' border-danger' : ''}">
            ${areaTag}
            <div class="d-flex justify-content-between align-items-center">
                <strong>${escapeHtml(item.label)}</strong>
                <span class="badge bg-${item.status_color}">${escapeHtml(item.status_label)}</span>
            </div>
            ${buildingsHtml}${completePrompt}${ackBtn}${advanceBtn}
        </div>`;
    }).join('');

    list.querySelectorAll('.sector-ack-btn').forEach(btn => btn.addEventListener('click', () => sectorAcknowledge(btn.dataset.id, btn)));
    list.querySelectorAll('.sector-advance-btn').forEach(btn => btn.addEventListener('click', () => sectorSelfAdvance(btn.dataset.id, btn.dataset.status, btn)));
    list.querySelectorAll('.sector-floor-btn').forEach(btn => btn.addEventListener('click', () => sectorFloorToggle(btn.dataset.id, btn.dataset.action, btn)));
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
// Configurable low-battery warning threshold (Settings → war_room_low_battery_pct).
// Critical tier is always half the warning tier, not a second setting.
const LOW_BATTERY_PCT = <?= (int) getSetting('war_room_low_battery_pct', '60') ?>;
const CRITICAL_BATTERY_PCT = Math.floor(LOW_BATTERY_PCT / 2);
// Configurable max-continuous-shift warning threshold (Settings →
// war_room_max_shift_minutes). Critical tier is 1.5x the warning tier, not a
// second setting — mirrors CRITICAL_BATTERY_PCT above, but INVERTED: higher
// minutes is worse (battery's critical tier is lower pct = worse).
const WR_MAX_SHIFT_MINUTES = <?= (int) getSetting('war_room_max_shift_minutes', '480') ?>;
const WR_CRITICAL_SHIFT_MINUTES = Math.round(WR_MAX_SHIFT_MINUTES * 1.5);
function fatigueHm(minutes) { return {h: Math.floor(minutes / 60), m: minutes % 60}; }
// Three fixed tiers for the pin popup's battery badge (below) — deliberately
// independent of the configurable LOW_BATTERY_PCT/CRITICAL_BATTERY_PCT above,
// which still gate the separate low-battery-only badges in Nearby Teams /
// Team Distances and the charge-alert button; this one is always shown.
function batteryTier(pct) {
    if (pct >= 65) return {cls: 'text-success', key: 'map.pin_battery_charged'};
    if (pct >= 35) return {cls: 'text-warning', key: 'map.pin_battery_moderate'};
    return {cls: 'text-danger', key: 'map.pin_battery_low'};
}
// Deliberately separate from LOW_BATTERY_PCT above, fixed (not a Settings
// field) — LOW_BATTERY_PCT gates the passive "getting low" badge, this
// gates the active charge-alert button (below/right of the Navigate
// button): a stricter, "actually worth bothering them about it" bar.
// Sourced from the real PHP constant (config.php) — mission-battery-alert.php
// re-checks the exact same value server-side, never a second hardcoded copy.
const CHARGE_ALERT_THRESHOLD_PCT = <?= CHARGE_ALERT_THRESHOLD_PCT ?>;
// First client-side gate needed for admin-only UI built entirely in JS —
// every other admin branch is server-rendered PHP conditioned directly on
// $canManageWarRoom around static HTML, but buildPinMarker()'s popup is
// pure JS. The mission-battery-alert.php endpoint independently re-checks
// canManageActionRoom() regardless — this only controls whether the button
// renders, never trusted as the real gate.
const CAN_MANAGE_WAR_ROOM = <?= json_encode($canManageWarRoom) ?>;

function buildPinMarker(pin, interactive = true) {
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
    // Always rendered when a reading exists (percentage should always be
    // visible per ops request) — unlike extraLine/fatigueLine, which stay
    // hidden unless actually triggered. The charge-alert button (below,
    // next to Navigate) is fully independent of this badge — it has its
    // own, stricter threshold and renders regardless of this badge's tier.
    const batteryPinTier = (pin.battery_level !== null && pin.battery_level !== undefined) ? batteryTier(pin.battery_level) : null;
    const batteryLine = batteryPinTier
        ? `<br><span class="${batteryPinTier.cls} small">🔋 ${t(batteryPinTier.key, {pct: pin.battery_level})}</span>`
        : '';
    // Fatigue: same "only rendered when actually over" idiom as batteryLine above.
    const fatigueLine = (pin.continuous_field_minutes !== null && pin.continuous_field_minutes !== undefined && pin.continuous_field_minutes > WR_MAX_SHIFT_MINUTES)
        ? `<br><span class="${pin.continuous_field_minutes >= WR_CRITICAL_SHIFT_MINUTES ? 'text-danger' : 'text-warning'} small">⏱ ${t('fatigue.pin_line', fatigueHm(pin.continuous_field_minutes))}</span>`
        : '';
    const teamLine = pin.team_label ? `<br>${escapeHtml(pin.team_label)}` : '';
    const navUrl = `https://www.google.com/maps/dir/?api=1&destination=${pin.lat},${pin.lng}&travelmode=driving`;
    // Always rendered for an admin regardless of battery level, so there's
    // something to notice/hover even on a healthy pin — deliberately NOT
    // the native disabled attribute, which would swallow the click
    // entirely; the click handler still needs to fire on an "inactive"
    // click so it can explain via a popup why nothing happened, per what
    // was actually asked for rather than a silently-inert button.
    // interactive=false still fully suppresses it on the read-only Route
    // Order composer map (no popupopen listener there to handle a click).
    const chargeAlertActive = pin.battery_level !== null && pin.battery_level !== undefined && pin.battery_level < CHARGE_ALERT_THRESHOLD_PCT;
    const chargeAlertTitle = chargeAlertActive ? '' : ` title="${t('map.charge_alert_inactive_hint', {pct: CHARGE_ALERT_THRESHOLD_PCT})}"`;
    const chargeAlertBtn = (CAN_MANAGE_WAR_ROOM && interactive)
        ? ` <button type="button" class="btn btn-sm ${chargeAlertActive ? 'btn-outline-warning' : 'btn-outline-secondary'} pin-charge-alert-btn" style="${chargeAlertActive ? '' : 'opacity:.55;'}" data-user-id="${pin.user_id}" data-active="${chargeAlertActive}"${chargeAlertTitle}>${t('map.charge_alert_btn')}</button>`
        : '';
    const navLine = `<br><a href="${navUrl}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary mt-1">${t('map.navigate_btn')}</a>${chargeAlertBtn}`;
    // zIndexOffset keeps a live position dot on top of any other pin type
    // (POI, dispatch, incident) that happens to land on the exact same spot
    // — Leaflet's default z-index is purely latitude-based, so two markers
    // at the same coordinate tie and fall back to DOM order, which is fragile
    // (depends on which render*() happened to run last that poll tick). A
    // volunteer's own live position should never be the one that silently
    // disappears underneath another marker.
    return L.marker([pin.lat, pin.lng], {icon, zIndexOffset: 1000}).bindPopup(`<strong>${guestNameHtml(pin.name, pin.is_external, pin.home_team_name, pin.home_team_color_bg, pin.home_team_color_fg, pin.guest_country_code)}</strong>${teamLine}<br>${pin.time}${statusLine ? '<br>' + statusLine : ''}${extraLine}${batteryLine}${fatigueLine}${navLine}`);
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

// Wires the pin-charge-alert-btn built into buildPinMarker() next to the
// Navigate button (admin-only, always rendered regardless of the battery
// badge). Mirrors dispatchLayer.on('popupopen', ...) below exactly — same
// delegated-listener-on-the-group approach, since a marker's popup only
// exists in the DOM while genuinely open. Field Mode has no map at all,
// same guard every other pin/layer listener here already uses.
if (!fieldMode) {
pinLayer.on('popupopen', event => {
    const popupEl = event.popup.getElement();
    const chargeBtn = popupEl.querySelector('.pin-charge-alert-btn');
    if (chargeBtn) {
        chargeBtn.addEventListener('click', () => {
            // Deliberately not a native disabled attribute (see
            // buildPinMarker()) — clicking an "inactive" button (battery
            // still >= CHARGE_ALERT_THRESHOLD_PCT, or no battery data at
            // all) explains why via a popup instead of doing nothing, so
            // it doesn't read as broken.
            if (chargeBtn.dataset.active !== 'true') {
                alert(t('map.charge_alert_inactive_hint', {pct: CHARGE_ALERT_THRESHOLD_PCT}));
                return;
            }
            chargeBtn.disabled = true;
            const data = new URLSearchParams({csrf_token: csrfToken, action: 'send', mission_id: <?= $missionId ?>, user_id: chargeBtn.dataset.userId});
            fetch('mission-battery-alert.php', {method: 'POST', body: data}).then(r => r.json()).then(result => {
                if (result.ok) { map.closePopup(); }
                else { alert(result.error || t('common.send_failed')); chargeBtn.disabled = false; }
            }).catch(() => { chargeBtn.disabled = false; });
        });
    }
});
}

// Nearby Teams (field-card column, both modes — the only place a Field Mode
// volunteer sees any position data at all, since that mode has no map) and
// Team Distances (small addendum inside the Teams panel, full view only).
// No existing meters->km or bearing->compass-letter formatter anywhere in
// this file to reuse (route.distance_from_point only ever shows raw
// unrounded meters) — both written fresh here.
// The 100m threshold below is a rough "getting close, pay attention" cue,
// not the actual safety boundary — the real trigger is entering the zone
// at all (distance 0), which fires the existing full-screen alarm
// regardless of Field Mode. This card is purely proactive/informational.
const RESTRICTED_AREA_PROXIMITY_WARN_METERS = 100;
let restrictedAreaProximityRenderedSig = null;
function renderRestrictedAreaProximity(items) {
    const sig = JSON.stringify(items);
    if (sig === restrictedAreaProximityRenderedSig) return;
    restrictedAreaProximityRenderedSig = sig;

    const list = document.getElementById('restrictedAreaProximityList');
    if (!list) return;
    if (!items.length) {
        list.innerHTML = `<p class="text-muted mb-0 small">${t('restricted_area.proximity_empty')}</p>`;
        return;
    }
    list.innerHTML = items.map(area => {
        const near = area.distance_m <= RESTRICTED_AREA_PROXIMITY_WARN_METERS;
        const line = area.distance_m <= 0
            ? `<span class="text-danger fw-bold">${t('restricted_area.proximity_inside')}</span>`
            : `<span class="${near ? 'text-danger fw-semibold' : 'text-muted'}">${formatDistanceMeters(area.distance_m)}</span>`;
        return `<div class="d-flex justify-content-between align-items-center py-1 border-bottom">
            <div><i class="bi bi-exclamation-triangle-fill text-danger me-1"></i>${escapeHtml(area.label)}</div>
            <div class="text-end small">${line}</div>
        </div>`;
    }).join('');
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
        const batteryNote = (team.battery_level !== null && team.battery_level !== undefined && team.battery_level <= LOW_BATTERY_PCT)
            ? ` · <span class="${team.battery_level <= CRITICAL_BATTERY_PCT ? 'text-danger' : 'text-warning'} small">🔋 ${t('map.pin_low_battery', {pct: team.battery_level})}</span>`
            : '';
        const fatigueNote = (team.continuous_field_minutes !== null && team.continuous_field_minutes !== undefined && team.continuous_field_minutes > WR_MAX_SHIFT_MINUTES)
            ? ` · <span class="${team.continuous_field_minutes >= WR_CRITICAL_SHIFT_MINUTES ? 'text-danger' : 'text-warning'} small">⏱ ${t('fatigue.pin_line', fatigueHm(team.continuous_field_minutes))}</span>`
            : '';
        return `<div class="d-flex justify-content-between align-items-center py-1 border-bottom" style="${dimmed}">
            <div>${swatch}<strong>${escapeHtml(team.label)}</strong></div>
            <div class="text-end small">${distanceLine}${staleNote}${batteryNote}${fatigueNote}<br><span class="text-muted">${team.time}</span></div>
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
        const batteryNote = (pair.battery_level !== null && pair.battery_level !== undefined && pair.battery_level <= LOW_BATTERY_PCT)
            ? ` <span class="${pair.battery_level <= CRITICAL_BATTERY_PCT ? 'text-danger' : 'text-warning'}" title="${t('map.pin_low_battery', {pct: pair.battery_level})}">🔋</span>`
            : '';
        const fatigueNote = (pair.continuous_field_minutes !== null && pair.continuous_field_minutes !== undefined && pair.continuous_field_minutes > WR_MAX_SHIFT_MINUTES)
            ? ` <span class="${pair.continuous_field_minutes >= WR_CRITICAL_SHIFT_MINUTES ? 'text-danger' : 'text-warning'}" title="${t('fatigue.pin_line', fatigueHm(pair.continuous_field_minutes))}">⏱</span>`
            : '';
        return `<div class="d-flex justify-content-between align-items-center py-1">
            <span>${swatchA}${escapeHtml(pair.a_label)} ↔ ${swatchB}${escapeHtml(pair.b_label)}</span>
            <span class="text-muted">${formatDistanceMeters(pair.distance_m)}${staleNote}${batteryNote}${fatigueNote}</span>
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
// Mission-wide event timeline paired with the trails above — always the
// full mission regardless of the team filter (see mission-track.php).
let currentTrailEvents = [];

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
    // Folded in here rather than called separately at every renderTrailUpTo()
    // call site — every one of them needs the event log updated to the exact
    // same instant as the map, with no exceptions, so making that a property
    // of this function itself instead of a convention callers must remember
    // is the only way a future new call site can't accidentally skip it.
    renderTrailEventsUpTo(cutoffTs);
}
function renderTrail(trails) {
    renderTrailUpTo(trails, Infinity);
}

// Text is already server-escaped by loadMissionActivityEventsForReport()
// (same trust boundary mission-report-print.php/mission-stats.php already
// rely on for this exact array) — safe to drop into innerHTML as-is.
function renderTrailEventsUpTo(cutoffTs) {
    const log = document.getElementById('trailEventLog');
    if (!log) return;
    const events = cutoffTs === Infinity ? currentTrailEvents : currentTrailEvents.filter(e => e.ts <= cutoffTs);
    if (!events.length) {
        log.innerHTML = '<p class="text-muted small mb-0">' + t('trail.events_empty') + '</p>';
        return;
    }
    // Newest first, same convention as the Δραστηριότητα tab's own event list.
    log.innerHTML = events.slice().sort((a, b) => b.ts - a.ts).map(e => `
        <div class="small py-1 border-bottom d-flex justify-content-between gap-2">
            <span>${e.icon} ${e.text}</span>
            <span class="text-muted text-nowrap">${new Date(e.ts * 1000).toLocaleString(jsLocale, {day:'2-digit', month:'2-digit', hour:'2-digit', minute:'2-digit'})}</span>
        </div>
    `).join('');
}

// ── Replay scrubber ──────────────────────────────────────────────────────
const TRAIL_REPLAY_STEPS = 60;
const TRAIL_REPLAY_TICK_MS = 400; // ~24s to play a trail of any real length
let trailReplayTimer = null;
let trailMinTs = null, trailMaxTs = null;

function setupTrailReplay(trails, events) {
    const bar = document.getElementById('trailReplayBar');
    const scrubber = document.getElementById('trailScrubber');
    stopTrailReplay();
    // Range spans BOTH GPS pings and mission events — an order sent or an
    // incident reported before anyone's first ping (or after their last one)
    // still needs to be reachable by scrubbing, not clipped to whatever
    // window the GPS trail alone happens to cover.
    const allTs = trails.flatMap(t => t.points.map(p => p.ts))
        .concat((events || []).map(e => e.ts))
        .filter(ts => ts !== null && ts !== undefined);
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
        currentTrailEvents = result.events || [];
        document.getElementById('trailEventsCard')?.classList.remove('d-none');
        renderTrail(currentTrails);
        setupTrailReplay(currentTrails, currentTrailEvents);
    }).catch(() => alert(t('trail.load_failed')));
}
function exitTrailMode() {
    stopTrailReplay();
    document.getElementById('trailReplayBar')?.classList.add('d-none');
    document.getElementById('trailEventsCard')?.classList.add('d-none');
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

// Verified Coverage — same on-demand-fetch-and-toggle idiom as Team Trail
// just above (no L.control.layers/checkbox system exists anywhere in this
// app; every toggleable layer here is a plain button flipping a boolean +
// map.hasLayer()/addTo()/removeLayer()). Unlike Team Trail this doesn't
// swap out pinLayer — it's an additive overlay, so it only ever adds/removes
// coverageLayer itself.
function drawCoverageGapCells(sectorId) {
    if (!coverageLayer) return;
    coverageLayer.clearLayers();
    if (!coverageModeActive) return;
    const cov = sectorCoverageById[sectorId];
    if (!cov || !cov.gap_cells) return;
    // interactive:false is required, not cosmetic — Split Sector's wedge-
    // preview already proved a filled overlay steals clicks from whatever's
    // underneath it (the sector polygon's own popup trigger) otherwise.
    cov.gap_cells.forEach(cell => {
        L.rectangle([[cell[0], cell[1]], [cell[2], cell[3]]], {
            pane: 'coveragePane', stroke: false, fillColor: '#000', fillOpacity: 0.35, interactive: false,
        }).addTo(coverageLayer);
    });
}
function enterCoverageMode() {
    fetch('mission-sector-coverage.php?mission_id=<?= $missionId ?>').then(r => r.json()).then(result => {
        if (!result.ok) { alert(result.error || t('coverage.load_failed')); return; }
        sectorCoverageById = result.coverage || {};
        ringCoverageById = result.rings || {};
        coverageModeActive = true;
        if (!map.hasLayer(coverageLayer)) coverageLayer.addTo(map);
        renderSectorLayer(sectors);
        renderSectorsList(sectors);
        renderSearchRingsLayer(missingPerson);
    }).catch(() => alert(t('coverage.load_failed')));
}
function exitCoverageMode() {
    coverageModeActive = false;
    sectorCoverageById = {};
    ringCoverageById = {};
    coverageLayer.clearLayers();
    if (map.hasLayer(coverageLayer)) map.removeLayer(coverageLayer);
    renderSectorLayer(sectors);
    renderSectorsList(sectors);
    renderSearchRingsLayer(missingPerson);
}
const coverageModeToggleBtn = document.getElementById('coverageModeToggle');
if (coverageModeToggleBtn) {
    coverageModeToggleBtn.addEventListener('click', () => {
        if (coverageModeActive) {
            exitCoverageMode();
            coverageModeToggleBtn.innerHTML = '<i class="bi bi-broadcast me-1"></i>' + t('hero.btn_verified_coverage');
        } else {
            coverageModeToggleBtn.innerHTML = '<i class="bi bi-x-lg me-1"></i>' + t('coverage.exit_btn');
            enterCoverageMode();
        }
    });
}

// Reflects server-driven firesOverlayOn on the toolbar button — called both
// right after this admin's own click and every poll tick (in case a second
// admin flipped it from another session), never assumed from local state
// alone.
function updateFiresToggleBtn() {
    const btn = document.getElementById('firesOverlayToggle');
    if (!btn) return;
    btn.classList.toggle('active', !!firesOverlayOn);
}
const firesOverlayToggleBtn = document.getElementById('firesOverlayToggle');
if (firesOverlayToggleBtn) {
    firesOverlayToggleBtn.addEventListener('click', () => {
        const nextEnabled = !firesOverlayOn;
        firesOverlayToggleBtn.disabled = true;
        const data = new URLSearchParams({csrf_token: csrfToken, mission_id: <?= $missionId ?>, enabled: nextEnabled ? '1' : '0'});
        fetch('mission-fires.php', {method: 'POST', body: data}).then(r => r.json()).then(result => {
            if (result.ok) {
                firesOverlayOn = result.enabled;
                updateFiresToggleBtn();
                // Turning off clears immediately for instant feedback; turning
                // on waits for the next poll tick to actually populate
                // fireHotspots (this endpoint is write-only, see mission-fires.php).
                if (!firesOverlayOn) { fireHotspots = null; if (!fieldMode) renderFireLayer(null); }
            } else {
                alert(result.error || t('fires.no_api_key'));
            }
        }).catch(() => {
            // A raw PHP error/non-JSON response (the class of bug this app
            // hit twice already this same day) must still tell the admin
            // SOMETHING, not fail invisibly like it did before this line
            // existed.
            alert(t('fires.toggle_failed'));
        }).finally(() => { firesOverlayToggleBtn.disabled = false; });
    });
}

// One Share button, not five. Prefers the OS's own native share sheet
// (navigator.share with an actual file attached) — that sends the real
// photo/video bytes, not a link, so the recipient doesn't need an account
// on this app at all, and lets the user pick WhatsApp/Telegram/Viber/
// Messenger/anything else installed from one native picker, matching what
// the user asked for exactly ("a share icon, then a popup to choose where").
// Only falls back to a manual Bootstrap dropdown of app-specific LINK
// shares (same login caveat as before) on browsers with no file-share
// support — mainly desktop; every mobile browser that matters here
// supports it.
function buildMediaShareButtonsHtml(m) {
    const url = location.origin + '/mission-photo-view.php?id=' + m.id;
    const caption = (m.media_type === 'video' ? '🎥' : '📷') + ' ' + t('media.share_caption');
    const shareText = caption + ' ' + url;
    return `
        <div class="d-flex gap-1 mt-1 flex-wrap">
            <a class="btn btn-sm btn-outline-secondary p-1" href="mission-photo-view.php?id=${m.id}" download title="${t('media.download_title')}"><i class="bi bi-download" style="font-size:.7rem;"></i></a>
            <div class="dropdown">
                <button type="button" class="btn btn-sm btn-outline-primary p-1 media-share-btn" data-id="${m.id}" data-media-type="${m.media_type}" data-url="${escapeHtml(url)}" data-caption="${escapeHtml(caption)}" data-share-text="${escapeHtml(shareText)}" title="${t('media.share_title')}"><i class="bi bi-share-fill" style="font-size:.7rem;"></i></button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><a class="dropdown-item" target="_blank" rel="noopener" href="https://wa.me/?text=${encodeURIComponent(shareText)}"><i class="bi bi-whatsapp me-2"></i>WhatsApp</a></li>
                    <li><a class="dropdown-item" target="_blank" rel="noopener" href="https://t.me/share/url?url=${encodeURIComponent(url)}&text=${encodeURIComponent(caption)}"><i class="bi bi-telegram me-2"></i>Telegram</a></li>
                    <li><a class="dropdown-item" href="viber://forward?text=${encodeURIComponent(shareText)}"><i class="bi bi-chat-dots-fill me-2"></i>Viber</a></li>
                    <li><a class="dropdown-item" href="fb-messenger://share/?link=${encodeURIComponent(url)}"><i class="bi bi-messenger me-2"></i>Messenger</a></li>
                </ul>
            </div>
        </div>`;
}

// Fetches the actual media bytes and hands them to a real OS share sheet —
// see buildMediaShareButtonsHtml's comment for why this is preferred over
// the link-based dropdown. Two different native paths, not one: Android
// WebView (unlike an actual mobile browser) implements NO Web Share API at
// all — not even text/url, let alone files, a WebView-specific gap tracked
// upstream as Chromium issue 765923 — so navigator.canShare is always
// undefined inside the installed Action Room app, and this was silently
// falling back to the link dropdown on every single share, not just flaky
// ones. Capacitor.Plugins.Share (native picker via Filesystem+Share, same
// raw-bridge convention as BackgroundGeolocation above — no ES imports,
// see that block's comment) covers the app; navigator.share covers
// everywhere it's actually implemented (real mobile browsers). Falls back
// to opening that same button's Bootstrap dropdown (the manual per-platform
// LINK list) only when neither path is available, or the user's own device
// has no share targets at all for that file type — genuinely not an error,
// so no alert() for that case.
async function fetchMediaBlob(id, mediaType) {
    const res = await fetch(`mission-photo-view.php?id=${id}`);
    const blob = await res.blob();
    const ext = {'image/jpeg':'jpg','image/png':'png','image/webp':'webp','image/gif':'gif','video/mp4':'mp4','video/webm':'webm','video/quicktime':'mov'}[blob.type] || (mediaType === 'video' ? 'mp4' : 'jpg');
    return { blob, ext };
}

// The @capacitor/filesystem NPM package's writeFile() accepts a raw Blob
// for `data` because that package's own JS wrapper base64-encodes it before
// crossing the bridge. Capacitor.Plugins.Filesystem (the raw bridge this
// file uses, no ES imports — see BackgroundGeolocation's comment above) skips
// that wrapper entirely, so a Blob reaches native as an unserializable
// object and writeFile rejects with "input parameters aren't valid" —
// confirmed live via mobile-debug-log.php against a real device. Encoding it
// to base64 here ourselves is the fix.
function blobToBase64(blob) {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onloadend = () => resolve(String(reader.result).split(',')[1] || '');
        reader.onerror = () => reject(reader.error || new Error('blobToBase64 failed'));
        reader.readAsDataURL(blob);
    });
}

async function shareMediaItem(btn) {
    const { id, mediaType, url, caption } = btn.dataset;
    try {
        if (window.Capacitor && window.Capacitor.isNativePlatform && window.Capacitor.isNativePlatform()) {
            const { Filesystem, Share } = window.Capacitor.Plugins || {};
            if (Filesystem && Share) {
                const { blob, ext } = await fetchMediaBlob(id, mediaType);
                const base64Data = await blobToBase64(blob);
                const written = await Filesystem.writeFile({ path: `field-${id}.${ext}`, data: base64Data, directory: 'CACHE' });
                await Share.share({ files: [written.uri], text: caption });
                return;
            }
            bgDebugLog('share_plugin_missing', Object.keys(window.Capacitor.Plugins || {}).join(','));
        } else if (navigator.canShare) {
            const { blob, ext } = await fetchMediaBlob(id, mediaType);
            const file = new File([blob], `field-${id}.${ext}`, {type: blob.type});
            if (navigator.canShare({files: [file]})) {
                await navigator.share({files: [file], text: caption});
                return;
            }
        }
    } catch (e) {
        // Web cancel throws AbortError; the native Capacitor Share sheet
        // instead rejects with a plain Error whose message is literally
        // "Share canceled" — both mean the user closed the picker on
        // purpose, not a real failure, so neither should fall through to
        // popping the dropdown open right after they dismissed one already.
        if (e.name === 'AbortError' || /cancel/i.test(e.message || '')) return;
        bgDebugLog('share_failed', e.message || String(e));
        // Any other failure (fetch, blob, unsupported) falls through to the dropdown below.
    }
    // strategy:'fixed' is required here — the media card this button lives
    // in has its own overflow:hidden (clips card-img-top's corners, see
    // #mediaList's own CSS comment above), which silently clips Popper's
    // default absolutely-positioned menu to invisible. Fixed positioning is
    // relative to the viewport, not any clipped ancestor, so the menu
    // actually renders on top of everything instead of opening "successfully"
    // but nowhere visible.
    bootstrap.Dropdown.getOrCreateInstance(btn, {popperConfig: {strategy: 'fixed'}}).toggle();
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
            ? `<div style="font-size:.85rem;font-weight:700;line-height:1.2;">${icon}${escapeHtml(m.team_label)}</div><div class="text-muted" style="font-size:.7rem;">${guestNameHtml(m.user_name, m.is_external, m.home_team_name, m.home_team_color_bg, m.home_team_color_fg, m.guest_country_code)}</div>`
            : `<div class="fw-bold" style="font-size:.8rem;">${icon}${guestNameHtml(m.user_name, m.is_external, m.home_team_name, m.home_team_color_bg, m.home_team_color_fg, m.guest_country_code)}</div>`;
        // Two-column grid (#mediaList below) leaves each card roughly half as
        // wide as before, so the footer stacks name-block over a
        // time+buttons row instead of the old side-by-side split, which
        // would squeeze/overflow at this width.
        return `
        <div class="card position-relative">
            ${m.is_poi ? `<span class="badge bg-danger position-absolute top-0 end-0 m-1" style="z-index:1;" title="${t('poi.popup_title')}"><i class="bi bi-search"></i></span>` : ''}
            ${m.media_type === 'video'
                ? `<video src="mission-photo-view.php?id=${m.id}" class="card-img-top media-view-trigger" data-id="${m.id}" data-media-type="video" style="height:90px;object-fit:cover;background:#000;cursor:pointer;" preload="metadata"${m.has_thumb ? ` poster="mission-photo-view.php?id=${m.id}&thumb=1"` : ''}></video>`
                : `<img src="mission-photo-view.php?id=${m.id}" class="card-img-top media-view-trigger" data-id="${m.id}" data-media-type="photo" style="height:90px;object-fit:cover;cursor:pointer;">`}
            <div class="card-body p-2">
                ${whoBlock}
                ${m.poi_note ? `<div class="small fst-italic mt-1">"${escapeHtml(m.poi_note)}"</div>` : ''}
                <div class="d-flex justify-content-between align-items-center mt-1">
                    <span class="text-muted" style="font-size:.7rem;">${m.time}</span>
                    <div class="d-flex gap-1">
                        ${m.lat !== null ? `<button type="button" class="btn btn-sm btn-outline-secondary media-locate-btn p-1" data-lat="${m.lat}" data-lng="${m.lng}" title="${t('media.locate_title')}"><i class="bi bi-geo-alt-fill" style="font-size:.7rem;"></i></button>` : ''}
                        ${m.can_delete ? `<button type="button" class="btn btn-sm btn-outline-danger media-delete-btn p-1" data-id="${m.id}" title="${t('common.delete')}"><i class="bi bi-trash" style="font-size:.7rem;"></i></button>` : ''}
                    </div>
                </div>
                ${buildMediaShareButtonsHtml(m)}
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
    list.querySelectorAll('.media-share-btn').forEach(btn => btn.addEventListener('click', () => shareMediaItem(btn)));
}

// Reference photos attached to a Καθολικό Μήνυμα — coordinator-to-field
// direction, rendered in its own #broadcastPhotoList card, never merged into
// #mediaList above. Reuses the same secure view endpoint + lightbox modal
// and the same mission-photo.php delete action (it doesn't care whether a
// row has order_id set, only whether the viewer may delete it).
function renderBroadcastPhotos(items) {
    const list = document.getElementById('broadcastPhotoList');
    if (!items.length) {
        list.innerHTML = '<p class="text-muted mb-0 small">' + t('broadcast_photo.empty') + '</p>';
        return;
    }
    list.innerHTML = items.map(p => `
        <div class="d-flex gap-2 mb-2 pb-2 border-bottom">
            <img src="mission-photo-view.php?id=${p.id}" class="broadcast-photo-thumb media-view-trigger" data-id="${p.id}" data-media-type="photo" style="width:64px;height:64px;object-fit:cover;border-radius:.25rem;cursor:pointer;flex-shrink:0;">
            <div class="flex-grow-1" style="min-width:0;">
                ${p.caption ? `<div class="small">${escapeHtml(p.caption)}</div>` : ''}
                <div class="text-muted" style="font-size:.7rem;">${escapeHtml(p.user_name)} · ${p.time}</div>
            </div>
            ${p.can_delete ? `<button type="button" class="btn btn-sm btn-outline-danger broadcast-photo-delete-btn p-1 align-self-start" data-id="${p.id}" title="${t('common.delete')}"><i class="bi bi-trash" style="font-size:.7rem;"></i></button>` : ''}
        </div>
    `).join('');
    list.querySelectorAll('.broadcast-photo-thumb').forEach(el => el.addEventListener('click', () => {
        openMediaViewModal(el.dataset.id, el.dataset.mediaType);
    }));
    list.querySelectorAll('.broadcast-photo-delete-btn').forEach(btn => btn.addEventListener('click', () => {
        if (!confirm(t('media.delete_confirm'))) return;
        const data = new URLSearchParams({csrf_token: csrfToken, action: 'delete', mission_id: <?= $missionId ?>, id: btn.dataset.id});
        fetch('mission-photo.php', {method:'POST', body:data}).then(r => r.json()).then(result => {
            if (result.ok) { renderBroadcastPhotos(broadcastPhotos = broadcastPhotos.filter(p => String(p.id) !== btn.dataset.id)); }
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
// includes/functions-warroom.php, which both replayed endpoints share.
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

// Shared by the card's ack button and the map popup's — mutates the
// already-loaded `routes` array in place (no need to wait for the next
// poll) and refreshes both surfaces that read it, so acknowledging from
// either place immediately reveals depart/arrive/complete in the other too.
function routeAcknowledge(routeId, orderId, btn) {
    btn.disabled = true;
    const data = new URLSearchParams({csrf_token: csrfToken, action: 'acknowledge', order_id: orderId});
    fetch('mission-order.php', {method: 'POST', body: data}).then(r => r.json()).then(result => {
        if (result.ok) {
            const route = routes.find(r => String(r.id) === String(routeId));
            if (route) route.my_acknowledged_at = true;
            renderMyRoutes(routes);
            if (!fieldMode) renderRouteLayer(routes);
        } else { btn.disabled = false; alert(result.error || t('common.failed')); }
    }).catch(() => { btn.disabled = false; });
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
// scopeEl narrows the note-input/button lookup to the container this call
// came from (the card's #myRoutesList, or a specific waypoint popup's own
// element) — needed now that the same waypoint's "current" block can be
// on-screen twice at once (card + map popup); an unscoped document-wide
// query would silently grab whichever instance happens to sit first in the
// DOM regardless of which one the volunteer actually typed a note into.
function routeComplete(waypointId, confirmed, scopeEl) {
    const scope = scopeEl || document;
    const noteInput = scope.querySelector(`.route-note-input[data-id="${waypointId}"]`);
    const noteValue = noteInput ? noteInput.value.trim() : '';
    const wp = findRouteWaypointById(waypointId);
    if (wp) {
        const missing = missingRouteDeliverablesClientSide(wp, noteValue);
        if (missing.length) {
            alert(t('route.missing_deliverables', {items: missing.join(', ')}));
            const group = scope.querySelector(`.route-complete-btn[data-id="${waypointId}"]`);
            if (group) group.disabled = false;
            return;
        }
    }
    const extra = {};
    if (noteValue !== '') extra.note = noteValue;
    if (confirmed) extra.confirm_out_of_sequence = '1';
    postRouteActionQueueable('complete', waypointId, extra)
        .then(result => handleRouteActionResult(result, () => routeComplete(waypointId, true, scopeEl)));
}

function uploadWaypointMedia(waypointId, file, mediaType, statusEl) {
    const isVideo = mediaType === 'video';
    if (statusEl) { statusEl.textContent = isVideo ? t('media.compressing') : t('media.uploading'); statusEl.className = 'small text-muted'; }
    showMediaProgressModal(isVideo ? t('media.compressing') : t('media.uploading'));

    // Same shared compressVideoForUpload()/compressPhotoForUpload() as
    // wireMediaInput's own upload flow, composed the same way (compression
    // + geolocation concurrent, one CPU-bound, one a GPS wait) — this is
    // the *other* independent upload path (a Route Order waypoint's own
    // self-contained capture button, used because field mode has no map/
    // media panel), and without this it would keep the exact same
    // slow-upload problem for a required deliverable photo/video.
    const compressPromise = isVideo
        ? compressVideoForUpload(file, pct => setMediaProgress(pct))
        : compressPhotoForUpload(file);
    const geoPromise = new Promise(resolve => {
        if (!navigator.geolocation) { resolve([null, null]); return; }
        navigator.geolocation.getCurrentPosition(
            pos => resolve([pos.coords.latitude, pos.coords.longitude]),
            () => resolve([null, null]),
            {enableHighAccuracy: true, timeout: 8000}
        );
    });

    compressPromise.then(finalFile => {
        if (statusEl) statusEl.textContent = t('media.uploading');
        setMediaProgress(0, t('media.uploading'));
        geoPromise.then(([lat, lng]) => {
            const data = new FormData();
            data.append('csrf_token', csrfToken);
            data.append('action', 'upload');
            data.append('mission_id', '<?= $missionId ?>');
            data.append('media', finalFile);
            data.append('route_waypoint_id', String(waypointId));
            if (lat !== null) { data.append('lat', lat); data.append('lng', lng); }
            postFormDataWithProgress('mission-photo.php', data, pct => setMediaProgress(pct)).then(result => {
                hideMediaProgressModal();
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
                    if (!fieldMode) renderRouteLayer(routes);
                } else {
                    if (statusEl) { statusEl.textContent = result.error || t('common.send_failed'); statusEl.className = 'small text-danger'; }
                }
            }).catch(() => { hideMediaProgressModal(); if (statusEl) { statusEl.textContent = t('common.send_failed'); statusEl.className = 'small text-danger'; } });
        });
    });
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
            ${wp.video ? `<video src="mission-photo-view.php?id=${wp.video.id}" style="max-height:70px;border-radius:4px;" muted${wp.video.has_thumb ? ` poster="mission-photo-view.php?id=${wp.video.id}&thumb=1"` : ''}></video>` : ''}
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
        // Gated: the whole waypoint list (including "Ξεκίνησε" for stop 1)
        // stays hidden behind an explicit "Ελήφθη" until this viewer
        // acknowledges the route's order — same gate renderMyTasks() already
        // uses for plain tasks, applied here so the admin gets a real-time
        // sound the moment the team confirms they got it (mission-order.php's
        // acknowledge action, route-order_type branch).
        const waypointsHtml = (route.status === 'active' && !route.my_acknowledged_at)
            ? `<button type="button" class="btn btn-sm wr-touch-btn btn-warning w-100 route-ack-btn" data-id="${route.id}" data-order-id="${route.order_id}"><i class="bi bi-check2 me-1"></i>${t('banner.ack_btn')}</button>`
            : route.status === 'cancelled'
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

    list.querySelectorAll('.route-ack-btn').forEach(btn => btn.addEventListener('click', () => routeAcknowledge(btn.dataset.id, btn.dataset.orderId, btn)));
    list.querySelectorAll('.route-depart-btn').forEach(btn => btn.addEventListener('click', () => { btn.disabled = true; routeDepart(btn.dataset.id, false); }));
    list.querySelectorAll('.route-arrive-btn').forEach(btn => btn.addEventListener('click', () => { btn.disabled = true; routeArrive(btn.dataset.id, false); }));
    list.querySelectorAll('.route-complete-btn').forEach(btn => btn.addEventListener('click', () => { btn.disabled = true; routeComplete(btn.dataset.id, false, list); }));
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

function renderPresence(onlineIds, staleness) {
    const onlineSet = new Set((onlineIds || []).map(String));
    const staleMap = staleness || {};
    document.querySelectorAll('[id^="presence-"]').forEach(el => {
        const uid = el.id.slice('presence-'.length);
        // "Online" means either the browser tab itself is open (heartbeat)
        // OR GPS is still reporting fresh — including via the native
        // background path with the screen off/app backgrounded. That
        // second case is exactly the scenario background tracking exists
        // for, so it must never read as offline just because the tab isn't
        // focused (was previously heartbeat-only, real gap found on the
        // first real-device background-tracking test).
        const hasFreshPing = Object.prototype.hasOwnProperty.call(staleMap, uid) && !staleMap[uid];
        const isOnline = onlineSet.has(uid) || hasFreshPing;
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

const PARTICIPANT_STATUS_BADGE_META = {
    needs_help: {cls: 'bg-danger', label: () => t('status.badge_needs_help')},
    on_site: {cls: 'bg-success', label: () => t('status.badge_on_site')},
    on_way: {cls: 'bg-warning text-dark', label: () => t('status.badge_on_way')},
};
// Keeps the Participants list's ping-time text and status badge live —
// previously frozen at page load (only the presence dot and stale-warning
// icon above were poll-patched), so a status/ping update after the initial
// render stayed invisible until a manual reload.
function renderParticipantLiveData(data) {
    Object.keys(data || {}).forEach(uid => {
        const info = data[uid];
        const timeEl = document.getElementById('ping-time-' + uid);
        if (timeEl) timeEl.textContent = info.last_ping_time ? t('participants.last_ping_label', {time: info.last_ping_time}) : t('participants.no_ping');

        const badgeEl = document.getElementById('status-badge-' + uid);
        if (badgeEl) {
            const meta = PARTICIPANT_STATUS_BADGE_META[info.field_status];
            badgeEl.className = 'badge ' + (meta ? meta.cls : 'bg-secondary');
            badgeEl.textContent = meta ? meta.label() : t('status.badge_none');
        }
        const rowEl = document.getElementById('participant-row-' + uid);
        if (rowEl) rowEl.classList.toggle('needs-help', info.field_status === 'needs_help');

        // Fatigue flag — recomputed every poll tick (unlike battery_level,
        // continuous_field_minutes keeps growing while someone stays out).
        // The button is always in the DOM (see roster row markup), only its
        // d-none class is toggled here, so someone who crosses the threshold
        // mid-poll (not just at page load) still gets a working button.
        const fatigueEl = document.getElementById('fatigue-badge-' + uid);
        const suggestBtn = document.getElementById('suggest-replacement-btn-' + uid);
        const minutes = info.continuous_field_minutes;
        const isFatigued = minutes !== null && minutes !== undefined && minutes > WR_MAX_SHIFT_MINUTES;
        if (fatigueEl) {
            fatigueEl.classList.toggle('d-none', !isFatigued);
            if (isFatigued) {
                const isCritical = minutes >= WR_CRITICAL_SHIFT_MINUTES;
                fatigueEl.classList.toggle('text-danger', isCritical);
                fatigueEl.classList.toggle('text-warning', !isCritical);
                fatigueEl.innerHTML = `<i class="bi bi-clock-history"></i> ${t('fatigue.badge_label', fatigueHm(minutes))}`;
            }
        }
        if (suggestBtn) suggestBtn.classList.toggle('d-none', !isFatigued);
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
            <div class="text-muted" style="font-size:.75rem;">${guestNameHtml(r.reporter_name, r.is_external, r.home_team_name, r.home_team_color_bg, r.home_team_color_fg, r.guest_country_code)} (${escapeHtml(r.team_label)}) · ${r.created_at}${r.acknowledged_at ? t('shortage.seen_at_prefix', {time: r.acknowledged_at}) : ''}</div>
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

const INCIDENT_OUTCOME_OPTIONS = [
    ['stayed_on_site', 'incident.outcome.stayed_on_site'],
    ['transported', 'incident.outcome.transported'],
    ['declined', 'incident.outcome.declined'],
    ['deceased', 'incident.outcome.deceased'],
];

// Same whole-array-JSON signature technique as renderShortageReports() above —
// also folds in canManageIncidents so a card never gets stuck mid-render if
// that ever changed within a session (it can't today, but costs nothing).
let missionIncidentsRenderedSig = null;
function renderMissionIncidents(items) {
    const list = document.getElementById('incidentsList');
    if (!list) return;
    const sig = canManageIncidents + '|' + items.map(r => r.id + ':' + (r.acknowledged_at ? '1' : '0')).join(',');
    if (sig === missionIncidentsRenderedSig) return;
    missionIncidentsRenderedSig = sig;

    if (!items.length) {
        list.innerHTML = '<p class="text-muted mb-0">' + t('incident.empty_list') + '</p>';
        return;
    }
    const sevColor = {low: 'secondary', medium: 'info', high: 'warning', critical: 'danger'};
    list.innerHTML = items.map(r => {
        const who = r.is_unknown_patient ? t('incident.unknown_patient_label') : (r.patient_name || '—');
        const details = [r.estimated_age, r.gender_label, r.phone].filter(Boolean).join(' · ');
        const outcomeOptions = INCIDENT_OUTCOME_OPTIONS.map(([val, key]) => `<option value="${val}">${t(key)}</option>`).join('');
        return `
        <div class="border rounded p-2 mb-2">
            <div><span class="badge bg-${sevColor[r.severity] || 'secondary'}">${r.severity_label}</span> <strong>${r.type_label}</strong> — ${escapeHtml(who)}</div>
            ${details ? `<div class="small mt-1">${escapeHtml(details)}</div>` : ''}
            ${r.notes ? `<div class="small fst-italic mt-1">"${escapeHtml(r.notes)}"</div>` : ''}
            <div class="text-muted" style="font-size:.75rem;">${guestNameHtml(r.reporter_name, r.is_external, r.home_team_name, r.home_team_color_bg, r.home_team_color_fg, r.guest_country_code)} (${escapeHtml(r.team_label)}) · ${r.created_at}${r.acknowledged_at ? t('shortage.seen_at_prefix', {time: r.acknowledged_at}) : ''}</div>
            ${canManageIncidents ? `<div class="mt-1 d-flex gap-1">${r.acknowledged_at
                ? `<select class="form-select form-select-sm incident-outcome-select" data-incident-id="${r.id}"><option value="">${t('incident.outcome_label')}…</option>${outcomeOptions}</select>
                   <input type="text" class="form-control form-control-sm incident-outcome-location-input d-none" data-incident-id="${r.id}" maxlength="255" placeholder="${t('incident.outcome_location_placeholder')}">
                   <button type="button" class="btn btn-sm btn-success flex-fill incident-resolve-btn" data-incident-id="${r.id}">${t('shortage.resolve_btn')}</button>`
                : `<button type="button" class="btn btn-sm btn-warning w-100 incident-seen-btn" data-incident-id="${r.id}">${t('shortage.seen_btn')}</button>`}</div>` : ''}
        </div>
    `;
    }).join('');
    if (!canManageIncidents) return;

    list.querySelectorAll('.incident-outcome-select').forEach(sel => sel.addEventListener('change', () => {
        const locInput = list.querySelector(`.incident-outcome-location-input[data-incident-id="${sel.dataset.incidentId}"]`);
        if (locInput) locInput.classList.toggle('d-none', sel.value !== 'transported');
    }));
    list.querySelectorAll('.incident-seen-btn').forEach(btn => btn.addEventListener('click', () => {
        btn.disabled = true;
        const data = new URLSearchParams({csrf_token: csrfToken, action: 'seen', incident_id: btn.dataset.incidentId});
        fetch('mission-incident.php', {method: 'POST', body: data}).then(r => r.json()).then(result => {
            if (result.ok) {
                const item = missionIncidents.find(x => String(x.id) === btn.dataset.incidentId);
                if (item) item.acknowledged_at = item.acknowledged_at || t('common.now');
                renderMissionIncidents(missionIncidents);
            } else { btn.disabled = false; alert(result.error || t('common.failed')); }
        }).catch(() => { btn.disabled = false; });
    }));
    list.querySelectorAll('.incident-resolve-btn').forEach(btn => btn.addEventListener('click', () => {
        const sel = list.querySelector(`.incident-outcome-select[data-incident-id="${btn.dataset.incidentId}"]`);
        const outcome = sel ? sel.value : '';
        if (!outcome) { alert(t('incident.pick_outcome_first')); return; }
        const locInput = list.querySelector(`.incident-outcome-location-input[data-incident-id="${btn.dataset.incidentId}"]`);
        btn.disabled = true;
        const data = new URLSearchParams({csrf_token: csrfToken, action: 'resolve', incident_id: btn.dataset.incidentId, outcome, outcome_location: locInput ? locInput.value : ''});
        fetch('mission-incident.php', {method: 'POST', body: data}).then(r => r.json()).then(result => {
            if (result.ok) {
                missionIncidents = missionIncidents.filter(x => String(x.id) !== btn.dataset.incidentId);
                renderMissionIncidents(missionIncidents);
            } else { btn.disabled = false; alert(result.error || t('common.failed')); }
        }).catch(() => { btn.disabled = false; });
    }));
}

// Live map pin per incident — view-only (no seen/resolve controls in the popup,
// same split as route waypoints: actions live in the sidebar panel, the map is
// for "where"). Skips any incident reported without a GPS fix (geolocation
// denied/unavailable) rather than guessing a location for it.
function renderIncidentLayer(items) {
    if (!incidentLayer) return;
    incidentLayer.clearLayers();
    const sevColor = {low: '#6c757d', medium: '#0dcaf0', high: '#f59e0b', critical: '#dc3545'};
    (items || []).filter(r => r.lat !== null && r.lng !== null).forEach(r => {
        const icon = L.divIcon({
            className: '',
            html: `<div style="background:${sevColor[r.severity] || '#6c757d'};color:#fff;width:26px;height:26px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:14px;border:2px solid #fff;box-shadow:0 1px 4px #0008;"><i class="bi bi-heart-pulse-fill"></i></div>`,
            iconSize: [26, 26], iconAnchor: [13, 13],
        });
        const who = r.is_unknown_patient ? t('incident.unknown_patient_label') : (r.patient_name || '—');
        const details = [r.estimated_age, r.gender_label, r.phone].filter(Boolean).join(' · ');
        const popupHtml = `<strong>${r.severity_label} — ${r.type_label}</strong><br>${escapeHtml(who)}` +
            (details ? `<br><span class="small">${escapeHtml(details)}</span>` : '') +
            `<br><span class="small text-muted">${escapeHtml(r.team_label)} · ${r.created_at}</span>`;
        L.marker([r.lat, r.lng], {icon}).addTo(incidentLayer).bindPopup(popupHtml);
    });
}

let poiRenderedSig = null;
function renderPointsOfInterest(items) {
    const list = document.getElementById('poiList');
    if (!list) return;
    const sig = JSON.stringify(items);
    if (sig === poiRenderedSig) return;
    poiRenderedSig = sig;

    if (!items.length) {
        list.innerHTML = '<p class="text-muted mb-0">' + t('poi.empty_list') + '</p>';
        return;
    }
    // canManageIncidents is really just canManageWarRoom under an
    // incident-specific name (see its own declaration above) — reused here
    // rather than adding a second identical constant.
    list.innerHTML = items.map(p => {
        const reportedBy = p.reporter_names.length > 1
            ? t('poi.reported_by_multiple', {count: String(p.reporter_names.length), names: p.reporter_names.map(escapeHtml).join(', ')})
            : t('poi.reported_by_one', {name: escapeHtml(p.reporter_names[0] || '—')});
        const thumbs = p.photos.map(photo => photo.media_type === 'video'
            ? `<video src="mission-photo-view.php?id=${photo.id}" class="media-view-trigger" data-id="${photo.id}" data-media-type="video" style="width:56px;height:56px;object-fit:cover;border-radius:6px;cursor:pointer;background:#000;" title="${escapeHtml(photo.reporter_name)} · ${photo.time}" preload="metadata"${photo.has_thumb ? ` poster="mission-photo-view.php?id=${photo.id}&thumb=1"` : ''}></video>`
            : `<img src="mission-photo-view.php?id=${photo.id}" class="media-view-trigger" data-id="${photo.id}" data-media-type="photo" style="width:56px;height:56px;object-fit:cover;border-radius:6px;cursor:pointer;" title="${escapeHtml(photo.reporter_name)} · ${photo.time}">`
        ).join('');
        // One line per note, attributed — a merged POI can have a note from
        // each reporter (e.g. one says "found a shoe", another adds "there's
        // a bag nearby too"), not just one for the whole group.
        const notesHtml = p.photos.filter(photo => photo.note).map(photo =>
            `<div class="small fst-italic mt-1">"${escapeHtml(photo.note)}" — ${escapeHtml(photo.reporter_name)}</div>`
        ).join('');
        return `
        <div class="border rounded p-2 mb-2${p.checked_at ? '' : ' border-primary'}">
            <div class="d-flex flex-wrap gap-1 mb-1">${thumbs}</div>
            ${notesHtml}
            <div class="small mt-1">${reportedBy}</div>
            <div class="text-muted" style="font-size:.75rem;">${p.created_at}${p.checked_at ? t('poi.checked_at_prefix', {time: p.checked_at, name: escapeHtml(p.checked_by_name || '')}) : ''}</div>
            ${canManageIncidents && !p.checked_at ? `<button type="button" class="btn btn-sm btn-primary w-100 mt-1 poi-check-btn" data-poi-id="${p.id}">${t('poi.check_btn')}</button>` : ''}
        </div>
    `;
    }).join('');

    if (!canManageIncidents) return;
    list.querySelectorAll('.poi-check-btn').forEach(btn => btn.addEventListener('click', () => {
        btn.disabled = true;
        const data = new URLSearchParams({csrf_token: csrfToken, action: 'check_poi', mission_id: '<?= $missionId ?>', poi_id: btn.dataset.poiId});
        fetch('mission-photo.php', {method: 'POST', body: data}).then(r => r.json()).then(result => {
            if (result.ok) {
                const item = pointsOfInterest.find(x => String(x.id) === btn.dataset.poiId);
                if (item) item.checked_at = item.checked_at || t('common.now');
                poiRenderedSig = null;
                renderPointsOfInterest(pointsOfInterest);
            } else { btn.disabled = false; alert(result.error || t('common.failed')); }
        }).catch(() => { btn.disabled = false; });
    }));
}

function renderPoiLayer(items) {
    if (!poiLayer) return;
    poiLayer.clearLayers();
    (items || []).forEach(p => {
        const color = p.checked_at ? '#6c757d' : '#0d6efd';
        // A centered circle anchored on its own coordinate used to sit
        // exactly on top of (and, being bigger, fully hide) a position pin
        // reported at the same spot — extremely common here, since a POI
        // photo is normally taken from right where the reporter is
        // standing. Anchored like a real map pin instead (tip at the true
        // coordinate, body floating above it) so the badge no longer covers
        // whatever else is at that exact point; see buildPinMarker's
        // zIndexOffset for the other half of this fix.
        const icon = L.divIcon({
            className: '',
            html: `<div style="position:relative;width:26px;height:34px;">
                <div style="width:26px;height:26px;background:${color};color:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:14px;border:2px solid #fff;box-shadow:0 1px 4px #0008;"><i class="bi bi-search"></i></div>
                <div style="position:absolute;left:50%;top:24px;transform:translateX(-50%);width:0;height:0;border-left:7px solid transparent;border-right:7px solid transparent;border-top:10px solid ${color};"></div>
            </div>`,
            iconSize: [26, 34], iconAnchor: [13, 34],
        });
        const reportedBy = p.reporter_names.map(escapeHtml).join(', ');
        const notesHtml = (p.photos || []).filter(photo => photo.note)
            .map(photo => `<br><span class="small fst-italic">"${escapeHtml(photo.note)}"</span>`)
            .join('');
        const popupHtml = `<strong>${t('poi.popup_title')}</strong><br>${reportedBy}${notesHtml}<br><span class="small text-muted">${p.created_at}</span>` +
            (p.checked_at ? `<br><span class="small text-success">${t('poi.checked_at_prefix', {time: p.checked_at, name: escapeHtml(p.checked_by_name || '')})}</span>` : '');
        L.marker([p.lat, p.lng], {icon}).addTo(poiLayer).bindPopup(popupHtml);
    });
}

// Reuses the existing #mediaViewModal lightbox — openMediaViewModal() above
// builds its <img> src from a mission_photos id, but this feature's photo is
// a plain filename on mission_missing_persons instead (no mission_photos row
// at all), so this sets the same target elements directly rather than
// forcing that id-based helper to support a second URL shape.
function openMissingPersonPhoto(photo) {
    const body = document.getElementById('mediaViewModalBody');
    body.innerHTML = `<img src="uploads/missing-persons/${encodeURIComponent(photo)}" style="max-width:100%;max-height:80vh;">`;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('mediaViewModal')).show();
}

// Dark/bi-person-fill marker, deliberately distinct from POI's blue
// bi-search above, for the single last-known-location pin of the mission's
// missing person (if staff have set one). Same divIcon anchoring technique
// (tip at the true coordinate) as renderPoiLayer.
function renderMissingPersonMarker(item) {
    if (!missingPersonLayer) return;
    missingPersonLayer.clearLayers();
    if (!item || item.last_seen_lat === null || item.last_seen_lat === undefined
        || item.last_seen_lng === null || item.last_seen_lng === undefined) return;
    const icon = L.divIcon({
        className: '',
        html: `<div style="position:relative;width:26px;height:34px;">
            <div style="width:26px;height:26px;background:#212529;color:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:14px;border:2px solid #fff;box-shadow:0 1px 4px #0008;"><i class="bi bi-person-fill"></i></div>
            <div style="position:absolute;left:50%;top:24px;transform:translateX(-50%);width:0;height:0;border-left:7px solid transparent;border-right:7px solid transparent;border-top:10px solid #212529;"></div>
        </div>`,
        iconSize: [26, 34], iconAnchor: [13, 34],
    });
    // Clicking the marker opens this popup (default Leaflet behavior for a
    // bound popup) — the photo thumbnail inside it is the actual "open the
    // photo" affordance the marker icon itself can't carry on its own.
    const photoHtml = item.photo
        ? `<img src="uploads/missing-persons/${encodeURIComponent(item.photo)}" alt="" style="width:100%;max-width:200px;border-radius:4px;cursor:pointer;display:block;margin-bottom:4px;" onclick='openMissingPersonPhoto(${JSON.stringify(item.photo)})'>`
        : '';
    const popupHtml = `${photoHtml}<strong>${escapeHtml(item.full_name)}</strong>` +
        (item.last_seen_label ? `<br>${escapeHtml(item.last_seen_label)}` : '') +
        (item.last_seen_at ? `<br><span class="small text-muted">${t('missing_person.last_seen_at_prefix', {time: item.last_seen_at})}</span>` : '');
    L.marker([item.last_seen_lat, item.last_seen_lng], {icon}).addTo(missingPersonLayer).bindPopup(popupHtml);
}

// "LPB search rings" — 4 statistical concentric circles around the missing
// person's last-seen point, sized by LPB_RING_TABLE[item.subject_category].
// Purely a planning aid (see includes/lpb-rings.php's header comment for the
// caveats) — gated behind searchRingsEnabled (Settings, default off).
function renderSearchRingsLayer(item) {
    if (!searchRingsLayer) return;

    // Preserve whichever ring's popup is currently open — same rationale as
    // renderSectorLayer's guard above: now that each ring carries a popup
    // with real action buttons (send dispatch / sweep route), an
    // unconditional clearLayers()+rebuild every ~5s poll would silently
    // close it on the admin mid-click. Rings have no stable DB id to key by
    // (unlike sectors) — mission_missing_persons is UNIQUE-per-mission, at
    // most one row — so identity is: this ring's index (0-3) AND the exact
    // (subject_category, last_seen_lat, last_seen_lng) tuple that produced
    // its current geometry still matching the incoming item. An unrelated
    // profile edit (description, clothing, etc.) while a ring popup is open
    // correctly leaves it alone, since those fields don't affect ring
    // geometry/labels.
    let openRingLayer = null, openRingIndex = null;
    searchRingsLayer.eachLayer(layer => {
        if (layer.ringIndex !== undefined && layer.isPopupOpen && layer.isPopupOpen()) {
            openRingLayer = layer;
            openRingIndex = layer.ringIndex;
        }
    });
    const stillValid = searchRingsEnabled && item && item.last_seen_lat !== null && item.last_seen_lat !== undefined
        && item.last_seen_lng !== null && item.last_seen_lng !== undefined && LPB_RING_TABLE[item.subject_category];
    const sameSubject = openRingLayer && stillValid
        && openRingLayer.ringSubjectCategory === item.subject_category
        && openRingLayer.ringLat === item.last_seen_lat
        && openRingLayer.ringLng === item.last_seen_lng;
    if (!sameSubject) { openRingLayer = null; openRingIndex = null; }

    searchRingsLayer.eachLayer(layer => { if (layer !== openRingLayer) searchRingsLayer.removeLayer(layer); });
    if (!stillValid) return;

    const radii = LPB_RING_TABLE[item.subject_category];
    const center = [item.last_seen_lat, item.last_seen_lng];
    const pct = [25, 50, 75, 95];
    const fillOpacity = [0.12, 0.08, 0.05, 0.03];
    const strokeOpacity = [0.9, 0.7, 0.5, 0.35];
    const layersByIndex = {};
    if (openRingLayer) layersByIndex[openRingIndex] = openRingLayer;

    // Largest first so smaller rings paint on top — hovering/clicking inside
    // the 25% ring must resolve to the 25% ring's own tooltip/popup, not the
    // 95% one underneath it (same overlapping-shape gotcha as
    // drawCoverageGapCells()).
    for (let i = 3; i >= 0; i--) {
        if (i === openRingIndex) continue; // preserved above, left untouched
        const km = (radii[i] / 1000).toLocaleString(jsLocale, {minimumFractionDigits: 1, maximumFractionDigits: 1});
        const label = t('missing_person.ring_tooltip', {pct: pct[i], km}) + ringCoverageBadgeHtml(i);
        const actionButtons = CAN_MANAGE_WAR_ROOM
            ? `<div class="mt-2 d-flex gap-1">
                <button type="button" class="btn btn-sm btn-outline-primary ring-dispatch-btn" data-ring-index="${i}">${t('missing_person.ring_dispatch_btn')}</button>
                <button type="button" class="btn btn-sm btn-outline-primary ring-route-btn" data-ring-index="${i}">${t('missing_person.ring_route_btn')}</button>
            </div>`
            : '';
        const circle = L.circle(center, {
            radius: radii[i],
            color: '#7c3aed',
            weight: i === 0 ? 2 : 1.5,
            opacity: strokeOpacity[i],
            fillColor: '#7c3aed',
            fillOpacity: fillOpacity[i],
        }).addTo(searchRingsLayer);
        circle.ringIndex = i;
        circle.ringSubjectCategory = item.subject_category;
        circle.ringLat = item.last_seen_lat;
        circle.ringLng = item.last_seen_lng;
        circle.bindPopup(`${label}${actionButtons}`);
        if (i === 3) {
            // Outermost ring only: an always-visible caption (not just a
            // hover tooltip) so the feature reads as self-explanatory the
            // first time anyone opens this map.
            circle.bindTooltip(`${label} — ${t('missing_person.ring_caption')}`, {permanent: true, direction: 'top', className: 'search-rings-caption'});
        } else {
            circle.bindTooltip(label, {sticky: true});
        }
        layersByIndex[i] = circle;
    }

    // Re-impose largest-at-bottom/smallest-on-top paint order. The preserved
    // layer (if any) kept its OLD DOM position — required, so its open popup
    // doesn't get torn down — which means a freshly-created larger ring could
    // otherwise land on top of it in the SVG, breaking click targeting until
    // that popup next closes.
    for (let i = 3; i >= 0; i--) { layersByIndex[i]?.bringToFront(); }
}
// Delegated the same way sectorLayer's own popupopen listener is — buttons
// are inert HTML until a popup actually opens, then this wires them up by
// querying the open popup's own DOM node. openDispatchForRing()/
// openRouteForRing() are defined further down, near openDivideSectorsForArea().
searchRingsLayer?.on('popupopen', event => {
    const popupEl = event.popup.getElement();
    const dispatchBtn = popupEl.querySelector('.ring-dispatch-btn');
    if (dispatchBtn) dispatchBtn.addEventListener('click', () => { map.closePopup(); openDispatchForRing(parseInt(dispatchBtn.dataset.ringIndex, 10)); });
    const routeBtn = popupEl.querySelector('.ring-route-btn');
    if (routeBtn) routeBtn.addEventListener('click', () => { map.closePopup(); openRouteForRing(parseInt(routeBtn.dataset.ringIndex, 10)); });
});

// Updates only the read-only #missingPersonDisplay block — never touches the
// edit modal's form inputs (those are pre-filled once, server-side, from the
// page-load value), so a poll landing mid-edit can never clobber an admin's
// unsaved changes. Mirrors renderBroadcastPhotos' empty-container-in-PHP,
// JS-renders-everything pattern.
// deg -> localized 8-point compass abbreviation, meteorological convention
// (the direction the wind is blowing FROM, same as OpenWeatherMap's wind_deg).
function compassLabel(deg) {
    const keys = ['compass.n', 'compass.ne', 'compass.e', 'compass.se', 'compass.s', 'compass.sw', 'compass.w', 'compass.nw'];
    return t(keys[Math.round(((deg % 360) + 360) % 360 / 45) % 8]);
}

// h (float hours, possibly negative) -> "Xω Yλ" / "Xh Ym" via t(), rounded
// to the minute. Caller decides sign/prefix framing (remaining vs overdue).
function formatHoursMinutes(h) {
    const abs = Math.abs(h);
    let hh = Math.floor(abs);
    let mm = Math.round((abs - hh) * 60);
    if (mm === 60) { mm = 0; hh += 1; }
    return t('exposure.hm_format', {h: hh, m: mm});
}

// Deliberately NOT called "survival clock" anywhere in UI copy (that's the
// SAR-literature term used only in internal docs/commit messages) — this is
// framed as an operational action-margin estimate, never a survival/death
// prediction. See exposure_urgency_enabled's own settings.php caveat text.
function renderExposureBlock(eu) {
    if (!eu) return '';
    const hasCountdown = eu.remaining_hours !== null && eu.remaining_hours !== undefined;
    let numText, labelText, ink, ringColor;
    if (!hasCountdown) {
        numText = '—';
        labelText = t('exposure.monitoring_only');
        ink = '#495057'; ringColor = '#adb5bd';
    } else if (eu.remaining_hours > 1) {
        numText = formatHoursMinutes(eu.remaining_hours);
        labelText = t('exposure.margin_label');
        ink = '#664d03'; ringColor = '#ffc107';
    } else if (eu.remaining_hours > 0) {
        numText = formatHoursMinutes(eu.remaining_hours);
        labelText = t('exposure.margin_label');
        ink = '#842029'; ringColor = '#dc3545';
    } else {
        numText = formatHoursMinutes(eu.remaining_hours) + ' ' + t('exposure.overdue_suffix');
        labelText = t('exposure.overdue_label');
        ink = '#7a1220'; ringColor = '#7a1220';
    }
    const fraction = (hasCountdown && eu.baseline_hours) ? Math.max(0, Math.min(1, eu.remaining_hours / eu.baseline_hours)) : 0;
    const circumference = 2 * Math.PI * 32;
    const offset = (circumference * (1 - fraction)).toFixed(2);
    return `
        <div class="mt-3 pt-3 border-top">
            <div class="text-muted small mb-2">${t('exposure.section_title')}</div>
            <div class="d-flex align-items-center gap-3">
                <svg width="68" height="68" viewBox="0 0 76 76" aria-hidden="true">
                    <circle cx="38" cy="38" r="32" fill="none" stroke="#e9ecef" stroke-width="7"/>
                    ${hasCountdown ? `<circle cx="38" cy="38" r="32" fill="none" stroke="${ringColor}" stroke-width="7" stroke-linecap="round" stroke-dasharray="${circumference.toFixed(2)}" stroke-dashoffset="${offset}" transform="rotate(-90 38 38)"/>` : ''}
                </svg>
                <div style="min-width:0;">
                    <div class="fw-bold" style="font-size:1.3rem;color:${ink};">${escapeHtml(numText)}</div>
                    <div class="text-muted" style="font-size:.72rem;max-width:18ch;">${escapeHtml(labelText)}</div>
                </div>
            </div>
            <div class="text-muted fst-italic mt-2" style="font-size:.7rem;line-height:1.4;">${t('exposure.note')}</div>
        </div>`;
}

function renderWeatherCard(w, eu) {
    const wrap = document.getElementById('weatherCardWrap');
    const el = document.getElementById('weatherCardDisplay');
    const header = document.getElementById('weatherCardHeader');
    if (!wrap || !el) return;
    if (!w || w.status !== 'ok') { wrap.style.display = 'none'; return; }
    wrap.style.display = '';
    if (header) {
        header.className = 'card-header d-flex justify-content-between align-items-center'
            + (w.severity === 'danger' ? ' bg-danger bg-opacity-10' : (w.severity === 'warning' ? ' bg-warning bg-opacity-10' : ''));
    }

    const warningsHtml = (w.warnings && w.warnings.length)
        ? `<div class="alert ${w.severity === 'danger' ? 'alert-danger' : 'alert-warning'} py-2 px-3 mt-2 mb-0 small">`
            + w.warnings.map(msg => `<div><i class="bi bi-exclamation-triangle-fill me-1"></i>${escapeHtml(msg)}</div>`).join('')
            + `</div>`
        : '';
    const fallbackHtml = w.fallback_location
        ? `<div class="text-muted mt-1" style="font-size:.7rem;"><i class="bi bi-info-circle me-1"></i>${t('weather.fallback_location_note')}</div>`
        : '';

    el.innerHTML = `
        <div class="d-flex align-items-center gap-2">
            <img src="https://openweathermap.org/img/wn/${encodeURIComponent(w.icon)}@2x.png" width="46" height="46" alt="">
            <div>
                <div class="fw-bold" style="font-size:1.2rem;">${w.temp}&deg;C</div>
                <div class="text-muted small">${escapeHtml(w.description)}</div>
            </div>
        </div>
        <div class="d-flex gap-3 mt-2 small">
            <span><i class="bi bi-thermometer-half me-1"></i>${w.feels_like}&deg;C</span>
            <span><i class="bi bi-wind me-1"></i>${w.wind_speed} m/s</span>
            <span><i class="bi bi-droplet-half me-1"></i>${w.humidity}%</span>
        </div>
        ${warningsHtml}
        ${fallbackHtml}
        <div class="text-muted mt-2" style="font-size:.72rem;">${t('weather.forecast_for_prefix', {time: w.forecast_dt_label || ''})} &middot; ${t('weather.source_owm')}</div>
        ${renderExposureBlock(eu)}
    `;
}

// Custom Leaflet control (bottom-left, alongside no other control today —
// see the artifact's own placement note) showing wind direction/speed.
// Created once, then just has its inner HTML swapped on every update — same
// "stable DOM, refresh content" approach as the popups elsewhere in this
// file, cheaper than destroying/re-adding the Leaflet control each poll.
let weatherControlInstance = null;
let weatherControlEl = null;
function ensureWeatherControl() {
    if (weatherControlInstance || !map) return;
    const WeatherControl = L.Control.extend({
        options: {position: 'bottomleft'},
        onAdd: function () {
            const div = L.DomUtil.create('div', 'leaflet-control leaflet-bar wr-weather-ctl');
            L.DomEvent.disableClickPropagation(div);
            weatherControlEl = div;
            return div;
        },
    });
    weatherControlInstance = new WeatherControl();
    weatherControlInstance.addTo(map);
}

function renderWeatherControl(w) {
    if (!weatherCompassEnabled) return;
    if (!w || w.status !== 'ok') {
        if (weatherControlInstance) {
            map.removeControl(weatherControlInstance);
            weatherControlInstance = null;
            weatherControlEl = null;
        }
        return;
    }
    ensureWeatherControl();
    if (!weatherControlEl) return;

    const sevBg = w.severity === 'danger' ? '#f8d7da' : (w.severity === 'warning' ? '#fff3cd' : '#f1f3f5');
    const sevInk = w.severity === 'danger' ? '#842029' : (w.severity === 'warning' ? '#664d03' : '#495057');
    const sevLabel = w.severity === 'danger' ? t('weather.wind_danger') : (w.severity === 'warning' ? t('weather.wind_warning') : t('weather.wind_calm'));
    const dirLabel = compassLabel(w.wind_deg);
    // Caveat from the design artifact: when the mission has no coordinates,
    // weather.php silently falls back to Heraklion — the direction shown
    // here would then have nothing to do with the actual mission location,
    // so that MUST be visible on the control itself, not just in the card.
    const fallbackTitle = w.fallback_location ? ' — ' + t('weather.fallback_location_note') : '';

    weatherControlEl.innerHTML = `
        <div class="wr-weather-ctl-inner" title="${escapeHtml(t('weather.wind_from_prefix', {dir: dirLabel}) + fallbackTitle)}">
            <div class="wr-weather-ctl-label">${w.fallback_location ? '⚠ ' : ''}${t('weather.wind_label')}</div>
            <svg width="52" height="52" viewBox="0 0 72 72" aria-hidden="true">
                <circle cx="36" cy="36" r="30" fill="none" stroke="#ced4da" stroke-width="1.5"/>
                <text x="36" y="12" text-anchor="middle" font-size="9" fill="#6c757d">${t('compass.n')}</text>
                <text x="63" y="39.5" text-anchor="middle" font-size="9" fill="#6c757d">${t('compass.e')}</text>
                <text x="36" y="66" text-anchor="middle" font-size="9" fill="#6c757d">${t('compass.s')}</text>
                <text x="9" y="39.5" text-anchor="middle" font-size="9" fill="#6c757d">${t('compass.w')}</text>
                <g transform="rotate(${w.wind_deg} 36 36)">
                    <line x1="36" y1="36" x2="36" y2="14" stroke="${sevInk}" stroke-width="3" stroke-linecap="round"/>
                    <path d="M36,10 L31,20 L41,20 Z" fill="${sevInk}"/>
                </g>
                <circle cx="36" cy="36" r="3.5" fill="#495057"/>
            </svg>
            <div class="wr-weather-ctl-reading"><span class="v">${w.wind_speed}</span><span class="u">m/s</span></div>
            <div class="wr-weather-ctl-sev" style="background:${sevBg};color:${sevInk};">${escapeHtml(dirLabel)} &middot; ${escapeHtml(sevLabel)}</div>
        </div>`;
}

// NASA FIRMS satellite hotspot markers — cleared/rebuilt every poll tick,
// same idiom as renderPoiLayer above. Driven entirely by server state
// (firesOverlayOn/fireHotspots come from the ajax=1 poll, per-mission, not
// a local toggle), so this has no on/off logic of its own: an empty/null
// hotspots value from the server already means "layer should be empty".
function renderFireLayer(fireData) {
    if (!fireLayer) return;
    fireLayer.clearLayers();
    // fireData is the full ['status' => ..., 'hotspots' => [...]] shape from
    // getFireHotspotsForMission() — same wrapper convention as `weather`, not
    // a bare array — so a non-'ok' status (no_key/api_error, or firesOverlayOn
    // itself false → fireHotspots null) just clears the layer, same as
    // renderWeatherControl's own status check.
    if (!fireData || fireData.status !== 'ok') return;
    const confidenceColor = {high: '#d62828', nominal: '#f77f00', low: '#ffb703'};
    (fireData.hotspots || []).forEach(h => {
        const color = confidenceColor[h.confidence] || confidenceColor.nominal;
        // Same teardrop divIcon shape as renderPoiLayer, a flame emoji
        // instead of a Bootstrap icon glyph (user's explicit ask — matches
        // the rest of this app's existing emoji-as-marker-glyph convention,
        // e.g. the 📷/🎥 media icons) — colored ring still encodes confidence.
        const icon = L.divIcon({
            className: '',
            html: `<div style="position:relative;width:26px;height:34px;">
                <div style="width:26px;height:26px;background:${color};border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:15px;line-height:1;border:2px solid #fff;box-shadow:0 1px 4px #0008;">🔥</div>
                <div style="position:absolute;left:50%;top:24px;transform:translateX(-50%);width:0;height:0;border-left:7px solid transparent;border-right:7px solid transparent;border-top:10px solid ${color};"></div>
            </div>`,
            iconSize: [26, 34], iconAnchor: [13, 34],
        });
        // Confidence shown as NASA's own raw term (Low/Nominal/High), not a
        // Greek translation — matches the exact wording the user pointed to
        // on NASA's own FIRMS map, deliberately not localized.
        const confidenceRaw = (h.confidence || 'nominal');
        const confidenceLabel = confidenceRaw.charAt(0).toUpperCase() + confidenceRaw.slice(1);
        const frpHtml = (h.frp !== null && h.frp !== undefined) ? `<br>${t('fires.popup_frp_label')}: ${h.frp} MW` : '';
        // FIRMS reports brightness in Kelvin; converted to °C for display —
        // European audience, Kelvin isn't the intuitive unit here.
        const brightnessHtml = (h.brightness !== null && h.brightness !== undefined) ? `<br>${t('fires.popup_brightness_label')}: ${(h.brightness - 273.15).toFixed(1)}°C` : '';
        // acq_date 'YYYY-MM-DD' + acq_time 'HHMM' (UTC) -> 'DD/MM/YYYY HH:MM',
        // matching the exact format the user pointed to on NASA's own site.
        const dateParts = (h.acq_date || '').split('-');
        const dateLabel = dateParts.length === 3 ? `${dateParts[2]}/${dateParts[1]}/${dateParts[0]}` : (h.acq_date || '');
        const timeRaw = (h.acq_time || '').padStart(4, '0');
        const timeLabel = timeRaw.length === 4 ? `${timeRaw.slice(0, 2)}:${timeRaw.slice(2)}` : timeRaw;
        // Location line is filled in lazily on popupopen (see fireLayer.on
        // below) — a reverse-geocode call per marker up front would mean
        // dozens of sequential Nominatim requests on every 15-min cache
        // refresh, well past their free-tier fair-use policy.
        const popupHtml = `<div class="fire-location-line small fw-semibold mb-1" data-lat="${h.lat}" data-lng="${h.lng}">${t('fires.location_loading')}</div>` +
            `${t('fires.popup_coords_label')}: ${h.lat.toFixed(4)}, ${h.lng.toFixed(4)}<br>` +
            `${t('fires.popup_detected_label')}: ${dateLabel} ${timeLabel} UTC<br>` +
            `${t('fires.popup_confidence_label')}: ${escapeHtml(confidenceLabel)}<br>` +
            `${t('fires.popup_source_label')}: NASA ${escapeHtml(h.instrument || 'VIIRS')}` +
            brightnessHtml + frpHtml +
            `<br><span class="small fst-italic text-muted">${t('fires.caveat')}</span>`;
        L.marker([h.lat, h.lng], {icon}).addTo(fireLayer).bindPopup(popupHtml);
    });
}
// Lazy reverse-geocode: fills in the "Fire Xkm <direction> from <place>"
// line only when a marker's popup is actually opened, same delegated-
// listener-on-the-group idiom as dispatchLayer.on('popupopen', ...) above.
fireLayer?.on('popupopen', event => {
    const popupEl = event.popup.getElement();
    const line = popupEl.querySelector('.fire-location-line');
    if (!line) return;
    const lat = line.dataset.lat, lng = line.dataset.lng;
    fetch(`api-fire-location.php?lat=${encodeURIComponent(lat)}&lng=${encodeURIComponent(lng)}`)
        .then(r => r.json())
        .then(result => {
            if (result.ok) {
                const distanceLabel = Number(result.distance_km).toLocaleString(jsLocale, {minimumFractionDigits: 1, maximumFractionDigits: 1});
                line.textContent = t('fires.location_line', {
                    distance: distanceLabel,
                    direction: t('fires.direction_' + result.direction),
                    place: result.place,
                });
            } else {
                line.textContent = t('fires.location_unavailable');
            }
        })
        .catch(() => { line.textContent = t('fires.location_unavailable'); });
});

function renderMissingPersonCard(item) {
    const el = document.getElementById('missingPersonDisplay');
    if (!el) return;
    if (!item) {
        el.innerHTML = `<p class="text-muted mb-0 small">${t('missing_person.no_profile_yet')}</p>`;
        return;
    }
    const photoHtml = item.photo
        ? `<img src="uploads/missing-persons/${encodeURIComponent(item.photo)}" alt="" class="rounded" style="width:100px;height:100px;object-fit:cover;flex-shrink:0;cursor:pointer;" onclick='openMissingPersonPhoto(${JSON.stringify(item.photo)})'>`
        : '';
    const ageHtml = (item.age !== null && item.age !== undefined)
        ? ` <span class="text-muted fs-6">(${item.age} ${t('missing_person.years_short')})</span>` : '';
    const descHtml = item.description ? `<div class="small mt-1">${escapeHtml(item.description).replace(/\n/g, '<br>')}</div>` : '';
    const clothingHtml = item.clothing_description
        ? `<div class="small mt-1"><strong>${t('missing_person.clothing_label')}:</strong> ${escapeHtml(item.clothing_description).replace(/\n/g, '<br>')}</div>` : '';
    const vehicleHtml = item.vehicle
        ? `<div class="small mt-1"><strong>${t('missing_person.vehicle_label')}:</strong> ${escapeHtml(item.vehicle)}</div>` : '';
    const lastSeenHtml = (item.last_seen_label || item.last_seen_at)
        ? `<div class="small mt-1 text-danger"><i class="bi bi-geo-alt-fill"></i> ${escapeHtml(item.last_seen_label || '')}${item.last_seen_at ? ' — ' + item.last_seen_at : ''}</div>`
        : '';
    const circumstancesHtml = item.disappearance_circumstances
        ? `<div class="small mt-1"><strong>${t('missing_person.circumstances_label')}:</strong> ${escapeHtml(item.disappearance_circumstances).replace(/\n/g, '<br>')}</div>` : '';
    const directionHtml = item.likely_direction
        ? `<div class="small mt-1"><strong>${t('missing_person.likely_direction_label')}:</strong> ${escapeHtml(item.likely_direction)}</div>` : '';
    const witnessHtml = item.witness_accounts
        ? `<div class="small mt-1"><strong>${t('missing_person.witness_accounts_label')}:</strong> ${escapeHtml(item.witness_accounts).replace(/\n/g, '<br>')}</div>` : '';
    el.innerHTML = `<div class="d-flex gap-3">${photoHtml}<div class="flex-grow-1" style="min-width:0;">
        <div class="fs-5 fw-bold">${escapeHtml(item.full_name)}${ageHtml}</div>
        ${descHtml}${clothingHtml}${vehicleHtml}${lastSeenHtml}${circumstancesHtml}${directionHtml}${witnessHtml}
        <div class="small text-muted mt-2">${t('missing_person.updated_at_prefix', {time: item.updated_at})}</div>
    </div></div>`;
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
    // (includes/functions-warroom.php) wraps it in h() server-side, unlike every
    // other team_label source in this file. Do not add escapeHtml() here:
    // confirmed live that doing so double-escapes to "&amp;lt;" instead of
    // neutralizing the payload, while still being fully inert either way —
    // this is a display-correctness note, not a security one.
    list.innerHTML = items.map(a => `
        <div class="border border-danger rounded p-2 mb-2">
            <div><strong>🆘 ${a.team_label}</strong> — ${guestNameHtml(a.user_name, a.is_external, a.home_team_name, a.home_team_color_bg, a.home_team_color_fg, a.guest_country_code)}</div>
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

// #mediaProgressModal helpers — one shared popup used by every place a
// photo/video gets sent (see the modal's own HTML comment for why it's a
// single global instance rather than one per card).
function showMediaProgressModal(labelText) {
    setMediaProgress(0, labelText);
    bootstrap.Modal.getOrCreateInstance(document.getElementById('mediaProgressModal')).show();
}
function setMediaProgress(percent, labelText) {
    const bar = document.getElementById('mediaProgressBar');
    bar.style.width = percent + '%';
    bar.textContent = percent + '%';
    bar.setAttribute('aria-valuenow', percent);
    if (labelText !== undefined) document.getElementById('mediaProgressLabel').textContent = labelText;
}
function hideMediaProgressModal() {
    const instance = bootstrap.Modal.getInstance(document.getElementById('mediaProgressModal'));
    if (instance) instance.hide();
}

// fetch() has no reliable cross-browser API for upload progress (only
// download/response progress, via a ReadableStream response body) — actual
// bytes-sent progress during the POST itself needs XMLHttpRequest's
// upload.onprogress, the well-established way to do this. Mirrors
// fetch(url,{method:'POST',body:formData}).then(r=>r.json())'s own
// resolve/reject shape so it drops into the existing .then()/.catch()
// chains at each upload call site unchanged.
function postFormDataWithProgress(url, formData, onProgress) {
    return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhr.open('POST', url);
        if (onProgress) {
            xhr.upload.addEventListener('progress', e => {
                if (e.lengthComputable) onProgress(Math.round(e.loaded / e.total * 100));
            });
        }
        xhr.addEventListener('load', () => {
            try {
                resolve(JSON.parse(xhr.responseText));
            } catch (e) {
                reject(e);
            }
        });
        xhr.addEventListener('error', () => reject(new Error('network error')));
        xhr.send(formData);
    });
}

// Best-effort client-side poster frame for a video upload, grabbed via a
// hidden <video>+<canvas> from the same local file about to be uploaded.
// Needed because mobile browsers/WebViews (unlike desktop) frequently don't
// auto-paint a <video> element's first frame under preload="metadata" alone
// with no poster set, leaving the preview as a bare broken-play-button
// placeholder with no visible frame at all. Resolves null (never rejects)
// on any failure/timeout, so a thumbnail miss never blocks or fails the
// actual video upload — this only ever improves on the old behavior, never
// makes it worse.
function captureVideoThumbnail(file) {
    return new Promise(resolve => {
        const url = URL.createObjectURL(file);
        const videoEl = document.createElement('video');
        videoEl.muted = true;
        videoEl.playsInline = true;
        videoEl.preload = 'metadata';
        videoEl.src = url;

        let settled = false;
        const finish = blob => {
            if (settled) return;
            settled = true;
            URL.revokeObjectURL(url);
            resolve(blob);
        };
        const timeoutId = setTimeout(() => finish(null), 5000);

        videoEl.addEventListener('error', () => finish(null));
        videoEl.addEventListener('loadedmetadata', () => {
            // A hair past frame 0 — some encoders leave the very first frame
            // black/unreadable, a fraction of a second in is more reliable.
            try {
                videoEl.currentTime = Math.min(0.3, (videoEl.duration || 1) / 2);
            } catch (e) {
                finish(null);
            }
        });
        videoEl.addEventListener('seeked', () => {
            clearTimeout(timeoutId);
            try {
                const canvas = document.createElement('canvas');
                canvas.width = videoEl.videoWidth;
                canvas.height = videoEl.videoHeight;
                if (!canvas.width || !canvas.height) { finish(null); return; }
                canvas.getContext('2d').drawImage(videoEl, 0, 0, canvas.width, canvas.height);
                canvas.toBlob(blob => finish(blob), 'image/jpeg', 0.7);
            } catch (e) {
                finish(null); // tainted canvas or decode failure
            }
        });
    });
}

// Best-effort client-side downscale+re-encode of a photo to a smaller JPEG
// before upload — same motivation as compressVideoForUpload just below
// (the phone's raw camera photo, often several MB at full sensor
// resolution, going out over the same slow field connections), but much
// simpler: a single decode+draw+encode, not a realtime capture loop, so
// none of that function's watchdog/frame-count-safeguard machinery is
// needed here — there's no sustained playback to stall or get throttled.
// Always resolves with a File — either a genuinely smaller re-encode, or
// the original file completely unchanged — and never rejects, same
// contract as compressVideoForUpload/captureVideoThumbnail. Always
// outputs JPEG regardless of input format (png/webp/non-animated gif all
// normalize to it) — fine for real-world camera/gallery photos, which are
// essentially never transparency-dependent graphics in this app's actual
// use (field documentation photos, not graphic design assets).
function compressPhotoForUpload(file) {
    return new Promise(resolve => {
        const finishOriginal = () => resolve(file);

        if (typeof createImageBitmap === 'undefined'
            || shouldSkipPhotoCompression(file.size, file.type)) {
            finishOriginal();
            return;
        }

        createImageBitmap(file).then(bitmap => {
            // Idempotent close — several paths below (early-return, normal
            // completion, catch) can each want to release the bitmap, and
            // ImageBitmap.close() throws if called twice.
            let bitmapClosed = false;
            const closeBitmap = () => { if (!bitmapClosed) { bitmapClosed = true; try { bitmap.close(); } catch (e) {} } };

            try {
                // Cap the long edge, aspect-preserved, never upscale —
                // same reasoning as compressVideoForUpload's own resolution
                // cap: plenty of detail for field review, a fraction of a
                // full-sensor original's pixel count.
                const TARGET_LONG_EDGE = 1920;
                const scale = Math.min(1, TARGET_LONG_EDGE / Math.max(bitmap.width, bitmap.height));
                const canvas = document.createElement('canvas');
                canvas.width = Math.round(bitmap.width * scale);
                canvas.height = Math.round(bitmap.height * scale);
                if (!canvas.width || !canvas.height) { closeBitmap(); finishOriginal(); return; }
                canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);
                closeBitmap();
                canvas.toBlob(blob => {
                    // Never make it worse — an edge-case input that doesn't
                    // actually shrink falls back to the original untouched.
                    if (!blob || !blob.size || blob.size >= file.size) { finishOriginal(); return; }
                    // canvas.toBlob()'s output is a bare Blob with no
                    // .name, same as MediaRecorder's — FormData would send
                    // filename "blob" with no extension otherwise, which
                    // mission-photo.php's pathinfo()-based check rejects.
                    resolve(new File([blob], 'field-photo.jpg', {type: 'image/jpeg'}));
                }, 'image/jpeg', 0.85);
            } catch (e) {
                closeBitmap();
                finishOriginal();
            }
        }).catch(finishOriginal);
    });
}

// Best-effort client-side re-encode of a video to a much smaller file
// before upload. There's no way to constrain a native camera app's own
// recording quality from a plain <input capture> file, and no ffmpeg
// available server-side on this shared host — so this is the only place
// compression can happen at all: client-side, before the network request
// the original slow-upload complaint was actually about. Always resolves
// with a File — either a genuinely smaller re-encode, or the original file
// completely unchanged — and never rejects, same contract as
// captureVideoThumbnail: a failed/unsupported/not-worth-it compression
// must never block or fail the actual upload. Shared by both upload call
// sites (wireMediaInput below, and uploadWaypointMedia's own route-order
// deliverable flow) so neither one is left with the slow-upload problem.
// Optional onProgress(percent) is called from real playback position
// (currentTime/duration) while actively re-encoding — never called at all
// when compression is skipped, since there's no meaningful "compressing"
// phase to report in that case.
function compressVideoForUpload(file, onProgress) {
    return new Promise(resolve => {
        let settled = false;
        let watchdogId = null;
        const finish = result => {
            if (settled) return;
            settled = true;
            clearTimeout(watchdogId);
            resolve(result);
        };
        const finishOriginal = () => finish(file);

        if (typeof MediaRecorder === 'undefined'
            || !HTMLCanvasElement.prototype.captureStream
            || !HTMLVideoElement.prototype.captureStream) {
            finishOriginal();
            return;
        }

        const url = URL.createObjectURL(file);
        const videoEl = document.createElement('video');
        // Sustained real-time playback (unlike captureVideoThumbnail's
        // instant single seek) needs the element actually attached to the
        // document to reliably advance/complete in every engine — confirmed
        // live: fully DOM-detached, playback silently never reached 'ended'
        // even though .play() itself resolved; attached (off-screen, never
        // visible) it played through and fired 'ended' normally. Removed
        // again in cleanup either way.
        videoEl.style.cssText = 'position:fixed;top:-9999px;width:1px;height:1px;';
        document.body.appendChild(videoEl);
        const cleanupUrl = () => { URL.revokeObjectURL(url); videoEl.remove(); };
        videoEl.muted = true;
        videoEl.playsInline = true;
        videoEl.preload = 'auto';
        videoEl.src = url;

        videoEl.addEventListener('error', () => { cleanupUrl(); finishOriginal(); });

        videoEl.addEventListener('loadedmetadata', () => {
            if (shouldSkipVideoCompression(file.size, videoEl.duration)) {
                cleanupUrl();
                finishOriginal();
                return;
            }

            try {
                const mimeType = pickVideoCompressionMimeType(
                    ['video/mp4;codecs=h264,aac', 'video/webm;codecs=vp8,opus', 'video/webm'],
                    candidate => MediaRecorder.isTypeSupported(candidate)
                );
                if (!mimeType) { cleanupUrl(); finishOriginal(); return; }

                // A several-second realtime capture is exposed to the phone
                // locking, a call coming in, or the tab backgrounding — none
                // of which the instant thumbnail-grab above ever had to
                // worry about. Bound the worst case rather than risk a hung
                // upload.
                watchdogId = setTimeout(() => { cleanupUrl(); finishOriginal(); }, videoEl.duration * 3000 + 10000);

                // Cap the long edge, aspect-preserved — never upscale, and
                // never force a fixed landscape frame (phone video shot in
                // portrait, very common, must stay portrait).
                const TARGET_LONG_EDGE = 720;
                const scale = Math.min(1, TARGET_LONG_EDGE / Math.max(videoEl.videoWidth, videoEl.videoHeight));
                const canvas = document.createElement('canvas');
                canvas.width = Math.round(videoEl.videoWidth * scale);
                canvas.height = Math.round(videoEl.videoHeight * scale);
                if (!canvas.width || !canvas.height) { cleanupUrl(); finishOriginal(); return; }
                const ctx = canvas.getContext('2d');

                // videoEl stays muted (so .play() isn't blocked by autoplay
                // policy, and nothing plays out the speaker) — captureStream()
                // taps the decoded media pipeline, not the speaker output, so
                // the audio track pulled here still carries real audio.
                const canvasStream = canvas.captureStream();
                const audioTracks = videoEl.captureStream().getAudioTracks();
                const combinedStream = new MediaStream([...canvasStream.getVideoTracks(), ...audioTracks]);
                const recorder = new MediaRecorder(combinedStream, {
                    mimeType,
                    videoBitsPerSecond: 2000000,
                    audioBitsPerSecond: 64000,
                });

                const chunks = [];
                let frameCount = 0;
                recorder.addEventListener('dataavailable', e => {
                    if (e.data && e.data.size > 0) chunks.push(e.data);
                });
                recorder.addEventListener('error', () => { cleanupUrl(); finishOriginal(); });
                recorder.addEventListener('stop', () => {
                    cleanupUrl();
                    if (settled) return;
                    // requestVideoFrameCallback/requestAnimationFrame can be
                    // throttled to near-zero by the browser (backgrounded
                    // tab, screen off, some OS power-saving modes) without
                    // pausing playback or the recorder itself — confirmed
                    // live: a recording can finish with the right size and
                    // duration while having drawn only its first frame,
                    // producing a frozen/broken result that otherwise looks
                    // completely successful. A healthy real-device capture
                    // clears this floor by a wide margin, so this only ever
                    // catches the genuinely-broken case.
                    const MIN_FRAMES = Math.max(2, Math.floor(videoEl.duration * 2));
                    if (frameCount < MIN_FRAMES) { finishOriginal(); return; }
                    const blob = new Blob(chunks, {type: mimeType});
                    // Never make it worse — an edge-case input that doesn't
                    // actually shrink falls back to the original untouched.
                    if (!blob.size || blob.size >= file.size) { finishOriginal(); return; }
                    const ext = videoExtensionForMimeType(mimeType);
                    finish(new File([blob], 'field-video.' + ext, {type: mimeType}));
                });

                // Draw-driven capture (canvas.captureStream() with no fps
                // argument) rather than a fixed-timer sample, driven by
                // requestVideoFrameCallback (fires once per real decoded
                // frame) where available so an unchanged frame isn't
                // redundantly redrawn at display refresh rate for nothing.
                let drawing = true;
                const drawFrame = () => {
                    if (!drawing) return;
                    try {
                        ctx.drawImage(videoEl, 0, 0, canvas.width, canvas.height);
                        frameCount++;
                    } catch (e) { /* a single missed frame isn't fatal, keep going */ }
                    if (videoEl.requestVideoFrameCallback) {
                        videoEl.requestVideoFrameCallback(drawFrame);
                    } else {
                        requestAnimationFrame(drawFrame);
                    }
                };

                videoEl.addEventListener('ended', () => {
                    drawing = false;
                    if (recorder.state !== 'inactive') recorder.stop();
                });

                if (onProgress) {
                    videoEl.addEventListener('timeupdate', () => {
                        onProgress(Math.min(100, Math.round(videoEl.currentTime / videoEl.duration * 100)));
                    });
                }

                // Start recording only once playback has genuinely begun,
                // so the first captured frame is real — and play at normal
                // speed (never touch playbackRate: capture is wall-clock-
                // timestamped, not source-timestamped, so a faster playback
                // rate produces a fast-motion/pitch-shifted *output*, not a
                // faster compression).
                videoEl.play().then(() => {
                    recorder.start();
                    drawFrame();
                }).catch(() => { cleanupUrl(); finishOriginal(); });
            } catch (e) {
                cleanupUrl();
                finishOriginal();
            }
        });
    });
}

function wireMediaInput(inputId, sentLabel) {
    const input = document.getElementById(inputId);
    if (!input) return;
    input.addEventListener('change', () => {
        const file = input.files[0];
        if (!file) return;
        const status = document.getElementById('mediaUploadStatus');
        const isVideo = file.type.startsWith('video/');
        status.textContent = isVideo ? t('media.compressing') : t('media.uploading');
        status.className = 'small mb-2';
        showMediaProgressModal(isVideo ? t('media.compressing') : t('media.uploading'));

        // Compression and geolocation run concurrently — one's CPU-bound,
        // the other's a GPS/network wait, they don't contend for the same
        // resource. Thumbnail capture runs AFTER compression settles,
        // against whichever file will actually be uploaded — deliberately
        // not run concurrently against the original video file too: two
        // concurrent video decodes on a weak Android SoC is untested
        // territory, and compression already dominates the wall-clock cost
        // here regardless. Photo compression is a single decode+draw+
        // encode, not a realtime loop, so it has no such contention concern.
        const compressPromise = isVideo
            ? compressVideoForUpload(file, pct => setMediaProgress(pct))
            : compressPhotoForUpload(file);
        const geoPromise = new Promise(resolve => {
            if (!navigator.geolocation) { resolve([null, null]); return; }
            navigator.geolocation.getCurrentPosition(
                position => resolve([position.coords.latitude, position.coords.longitude]),
                () => resolve([null, null]),
                {enableHighAccuracy: true, timeout: 8000}
            );
        });

        compressPromise.then(finalFile => {
            status.textContent = t('media.uploading');
            setMediaProgress(0, t('media.uploading'));
            const thumbPromise = finalFile.type.startsWith('video/') ? captureVideoThumbnail(finalFile) : Promise.resolve(null);
            Promise.all([geoPromise, thumbPromise]).then(([[lat, lng], thumbBlob]) => {
                const data = new FormData();
                data.append('csrf_token', csrfToken);
                data.append('action', 'upload');
                data.append('mission_id', '<?= $missionId ?>');
                data.append('media', finalFile);
                if (thumbBlob) data.append('thumb', thumbBlob, 'thumb.jpg');
                if (lat !== null) { data.append('lat', lat); data.append('lng', lng); }
                postFormDataWithProgress('mission-photo.php', data, pct => setMediaProgress(pct)).then(result => {
                    hideMediaProgressModal();
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
                }).catch(() => { hideMediaProgressModal(); status.textContent = t('common.send_failed'); status.className = 'small mb-2 text-danger'; input.value = ''; });
            });
        });
    });
}
wireMediaInput('photoCaptureInput', t('media.photo_label'));
wireMediaInput('photoGalleryInput', t('media.photo_label'));
wireMediaInput('videoCaptureInput', t('media.video_label'));
wireMediaInput('videoGalleryInput', t('media.video_label'));

// Point of Interest capture — deliberately its own wiring rather than a
// wireMediaInput() variant: GPS is mandatory here (the whole upload is
// aborted on denial/timeout, never silently sent without a location the way
// a regular field photo would be), and a successful send needs to refresh
// the POI list/map pin immediately rather than wait for the next 5s poll.
//
// Two-step stage-then-send, NOT upload-on-select: the first version fired
// the whole upload straight from the file input's change event, reading
// whatever was in the note field at that exact instant. That silently
// required "type the note, THEN tap capture" — anyone who tapped capture
// first (the obvious first move) got an empty note baked in with no way to
// add one afterward, since the note field was left with nothing listening
// to it. Now: choosing a photo only stages it (and starts the GPS fix in
// the background) — nothing is sent until the note field's current value is
// read at the moment the Send button is actually pressed, so the note can
// be written before OR after taking the photo.
(function wirePoiInput() {
    const input = document.getElementById('poiCaptureInput');
    const noteInput = document.getElementById('poiNoteInput');
    const sendBtn = document.getElementById('poiSendBtn');
    if (!input || !sendBtn) return;
    const status = document.getElementById('mediaUploadStatus');
    let stagedFile = null;
    let stagedCoords = null;

    function resetStage() {
        stagedFile = null;
        stagedCoords = null;
        sendBtn.classList.add('d-none');
        sendBtn.disabled = false;
        input.value = '';
    }

    input.addEventListener('change', () => {
        const file = input.files[0];
        if (!file) return;
        stagedFile = file;
        stagedCoords = null;
        status.textContent = '';
        status.className = 'small mb-2';
        sendBtn.classList.remove('d-none');
        sendBtn.disabled = true;
        sendBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>' + t('poi.locating');

        if (!navigator.geolocation) {
            status.textContent = t('poi.gps_required');
            status.className = 'small mb-2 text-danger';
            resetStage();
            return;
        }
        navigator.geolocation.getCurrentPosition(
            position => {
                stagedCoords = {lat: position.coords.latitude, lng: position.coords.longitude};
                sendBtn.disabled = false;
                sendBtn.innerHTML = '<i class="bi bi-send-fill me-1"></i>' + t('poi.send_btn');
            },
            () => {
                status.textContent = t('poi.gps_required');
                status.className = 'small mb-2 text-danger';
                resetStage();
            },
            {enableHighAccuracy: true, timeout: 8000}
        );
    });

    sendBtn.addEventListener('click', () => {
        if (!stagedFile || !stagedCoords) return;
        const note = noteInput ? noteInput.value.trim() : '';
        sendBtn.disabled = true;
        status.textContent = t('media.uploading');
        status.className = 'small mb-2';
        showMediaProgressModal(t('media.uploading'));
        // POI capture is photo-only (input's own accept="image/*"), so this
        // is always compressPhotoForUpload — no isVideo branch needed here.
        compressPhotoForUpload(stagedFile).then(finalFile => {
            const data = new FormData();
            data.append('csrf_token', csrfToken);
            data.append('action', 'upload');
            data.append('mission_id', '<?= $missionId ?>');
            data.append('media', finalFile);
            data.append('is_poi', '1');
            data.append('note', note);
            data.append('lat', stagedCoords.lat);
            data.append('lng', stagedCoords.lng);
            postFormDataWithProgress('mission-photo.php', data, pct => setMediaProgress(pct)).then(result => {
                hideMediaProgressModal();
                if (result.ok) {
                    status.textContent = '✓ ' + t('poi.sent_confirmation');
                    status.className = 'small mb-2 text-success';
                    renderMedia(media = [result.media, ...media]);
                    mediaSignature = JSON.stringify(media);
                    if (noteInput) noteInput.value = '';
                    pollWarRoomData();
                } else {
                    status.textContent = result.error || t('common.send_failed');
                    status.className = 'small mb-2 text-danger';
                }
                resetStage();
            }).catch(() => {
                hideMediaProgressModal();
                status.textContent = t('common.send_failed');
                status.className = 'small mb-2 text-danger';
                resetStage();
            });
        });
    });
})();

(function wireIncidentReportForm() {
    const form = document.getElementById('incidentReportForm');
    if (!form) return;
    // Best-effort, non-blocking — same "denied/unavailable never blocks submission"
    // rule as every other geolocation call in this file. Captured once up front
    // rather than on submit so a slow/denied GPS fix never delays sending the report.
    navigator.geolocation.getCurrentPosition(pos => {
        document.getElementById('incidentLat').value = pos.coords.latitude;
        document.getElementById('incidentLng').value = pos.coords.longitude;
    }, () => {}, {enableHighAccuracy: true, timeout: 10000});

    const unknownCheckbox = document.getElementById('incidentUnknownPatient');
    const patientFields = document.getElementById('incidentPatientFields');
    unknownCheckbox.addEventListener('change', () => {
        patientFields.classList.toggle('d-none', unknownCheckbox.checked);
        patientFields.querySelectorAll('input').forEach(input => { input.disabled = unknownCheckbox.checked; });
    });
})();

setTimeout(() => {
    if (!fieldMode) { renderPins(pins); renderDispatches(dispatches); renderAnnotations(annotations); renderMedia(media); renderRouteLayer(routes); renderRoutesAdmin(routes); renderTeamDistances(teamDistances); renderIncidentLayer(missionIncidents); renderPoiLayer(pointsOfInterest); renderAreaLayer(areas); renderSectorLayer(sectors); renderSectorsList(sectors); renderRestrictedAreaLayer(restrictedAreas); renderRestrictedAreasList(restrictedAreas); renderRestrictedAreaBreachesList(restrictedAreaBreachHistory); renderMissingPersonMarker(missingPerson); renderSearchRingsLayer(missingPerson); renderWeatherControl(weather); renderFireLayer(fireHotspots); }
    renderMyTasks(myTasks);
    renderMySectors(sectors);
    renderMyRoutes(routes);
    renderShortageReports(shortageReports);
    renderMissionIncidents(missionIncidents);
    renderPointsOfInterest(pointsOfInterest);
    renderSosAlerts(sosAlerts);
    renderNearbyTeams(nearbyTeams);
    renderRestrictedAreaProximity(restrictedAreaProximity);
    renderBroadcastPhotos(broadcastPhotos);
    renderMissingPersonCard(missingPerson);
    renderWeatherCard(weather, exposureUrgency);
    if (!fieldMode) updateSosAlarmState(sosAlerts);
    // Ungated (unlike updateSosAlarmState just above) — same reasoning as the
    // poll-path wiring in pollWarRoomData(): this alarm's primary audience is
    // the field volunteer's own device, not just command staff.
    updateRestrictedAreaAlarmState(restrictedAreaBreaches);
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

// Three independent alarm sources (SOS panic button, restricted-area breach,
// return-to-base) all share the one siren engine above. Each used to decide
// play-vs-stop unilaterally from only its own state, so on the admin screen
// a poll tick running "no SOS alerts -> stop" immediately followed by "RA
// breach still open -> play" nulled and recreated the oscillator every 5s —
// audible as the siren restarting instead of sounding continuous. Fix: each
// source exposes a pure "do I want the siren right now" check, and
// reconcileSharedSiren() is the ONLY place that actually calls
// playSosSiren()/stopSosSiren() — it plays if ANY source wants it, stops
// only once NONE do. Every call site below that used to call those two
// functions directly now calls this instead. (raWantsSirenNow is defined
// further down with the rest of the RA state — plain function declarations
// are hoisted, so the forward reference here is safe.)
function sosWantsSirenNow() {
    const unacked = sosAlerts.filter(a => !a.acknowledged_at);
    if (!unacked.length) return false;
    return !(isSosMuteActive() && unacked.every(a => sosMutedAlertIds.has(a.id)));
}
function rtbWantsSirenNow() {
    return !!document.getElementById('returnToBaseOverlay')?.classList.contains('rtb-active');
}
function reconcileSharedSiren() {
    if (sosWantsSirenNow() || raWantsSirenNow() || rtbWantsSirenNow()) playSosSiren(); else stopSosSiren();
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
    updateSosAlarmState(sosAlerts); // reconcileSharedSiren() inside will stop it (unless RA/RTB still want it)
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

// Local-only dismiss of the full-screen SOS takeover — NOT an acknowledge.
// The takeover's dark scrim covers the whole viewport, so even though it's
// pointer-events:none (clicks technically reach the map/SOS panel
// underneath), staff can't SEE those controls to click them. This is the
// way back to a usable screen without pretending anyone has actually
// responded: the siren, the SOS list panel's real Acknowledge/Resolve
// buttons, and the small map marquee are all untouched — only the
// full-screen visual steps down to the calm corner-glow look. Same
// "keyed to the specific alert ids, so a NEW SOS is never silently
// suppressed by a stale dismiss" safety property as the mute button above,
// but no time expiry — unlike the siren sound, there's no reason a
// deliberately-dismissed full-screen takeover should reappear on its own
// while staff are actively using the map to respond.
let sosDismissedAlertIds = new Set();
function dismissSosOverlay() {
    sosDismissedAlertIds = new Set(sosAlerts.filter(a => !a.acknowledged_at).map(a => a.id));
    updateSosAlarmState(sosAlerts);
}
document.getElementById('sosOverlayCloseBtn')?.addEventListener('click', dismissSosOverlay);

// Drives the full-viewport red corner overlay + map marquee from the current
// sosAlerts list. Any unacknowledged, non-dismissed alert = full-screen
// takeover; everything else (acknowledged, or dismissed-but-still-open) =
// calm static red; no open alerts = fully off. The VISUAL state is never
// affected by the local mute above — only the siren audio itself is; muting
// must never look like "no SOS is happening." Siren decision itself is
// delegated entirely to reconcileSharedSiren() (see above), not decided here.
function updateSosAlarmState(items) {
    const overlay = document.getElementById('sosOverlay');
    if (!overlay) return;
    const unacked = items.filter(a => !a.acknowledged_at);
    const anyUnacked = unacked.length > 0;
    const muteStillValid = isSosMuteActive() && unacked.every(a => sosMutedAlertIds.has(a.id));
    const dismissStillValid = anyUnacked && unacked.every(a => sosDismissedAlertIds.has(a.id));
    if (!items.length) {
        overlay.classList.remove('sos-active', 'sos-calm');
        // A fresh SOS later must never inherit today's stale mute/dismiss state.
        sosMutedAlertIds = new Set();
        sosMuteExpiresAt = 0;
        sosDismissedAlertIds = new Set();
    } else if (anyUnacked && !dismissStillValid) {
        overlay.classList.add('sos-active');
        overlay.classList.remove('sos-calm');
    } else if (anyUnacked) {
        // Dismissed, not acknowledged — still genuinely open, so the siren
        // keeps obeying mute exactly as it would in the full-screen state;
        // only the takeover visual itself steps back.
        overlay.classList.remove('sos-active');
        overlay.classList.add('sos-calm');
    } else {
        overlay.classList.remove('sos-active');
        overlay.classList.add('sos-calm');
    }
    reconcileSharedSiren();
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
    const closeBtn = document.getElementById('sosOverlayCloseBtn');
    if (closeBtn) closeBtn.classList.toggle('d-none', !(anyUnacked && !dismissStillValid));
    // Shared by the map-bottom marquee and the full-screen overlay's own
    // centered one (the latter only ever visible while sos-active) — one
    // "who's in danger" string computed once, not duplicated per surface.
    const marqueeText = items.length
        ? items.map(a => t('sos.marquee_text', {team: a.team_label.toUpperCase(), name: a.user_name})).join('     •••     ')
        : '';
    const marquee = document.getElementById('sosMapMarquee');
    if (marquee) {
        if (items.length) {
            document.getElementById('sosMapMarqueeText').textContent = marqueeText;
            marquee.classList.remove('d-none');
        } else {
            marquee.classList.add('d-none');
            document.getElementById('sosMapMarqueeText').textContent = '';
        }
    }
    const overlayMarqueeText = document.getElementById('sosOverlayMarqueeText');
    if (overlayMarqueeText) overlayMarqueeText.textContent = marqueeText;
}

// Restricted-area breach alarm — structural clone of the SOS mute/dismiss/
// alarm-state trio directly above (same local-only, non-destructive, keyed-
// to-specific-ids escape hatches), but gated on exited_at/resolved_at
// instead of acknowledged_at: this is a physical-safety alarm about a
// CURRENT condition (is the volunteer still inside the zone right now?),
// not a ticket queue, so it calms itself the instant the volunteer's own
// next trustworthy ping shows them outside — independent of whether admin
// ever acknowledges. resolved_at (admin's manual force-clear) is the only
// thing that fully removes an item from `items` in the first place (see
// loadOpenRestrictedAreaBreachesForUser's WHERE resolved_at IS NULL).
//
// Unlike SOS, the siren does NOT sound continuously for the whole time a
// breach stays open — a still-open, unmuted breach gets one siren BURST the
// first time it's seen, then not again until RA_SIREN_REMINDER_INTERVAL_MS
// has passed since its last burst. Tracked per breach id, which is safe
// because one continuous "still inside" episode is always exactly one
// stable row/id (confirmed against checkRestrictedAreaBreach() in
// functions-warroom.php: a new row is only INSERTed when there's no
// existing open one for that area+user — every ping in between just
// re-finds the same row, and leaving/re-entering later gets a genuinely new
// id, correctly restarting the cadence). Each burst lasts
// RA_SIREN_BURST_DURATION_MS, tracked via raSirenBurstUntil and checked by
// raWantsSirenNow() below rather than its own timer — 5s poll-tick
// granularity is plenty precise at a 15-minute scale. Mute is INDEFINITE
// here (raMuteExpiresAt = Infinity), unlike SOS's 5-minute quick-mute —
// "even if the user stays in that area" was the explicit ask, and an admin
// who deliberately silences one ongoing breach shouldn't have it resume
// nagging on its own; it's still keyed to specific breach ids exactly like
// SOS's mute, so a genuinely new/different breach is never silently caught
// by a stale mute.
const RA_SIREN_REMINDER_INTERVAL_MS = 15 * 60 * 1000;
const RA_SIREN_BURST_DURATION_MS = 15 * 1000;
let raSirenLastPlayedAt = new Map(); // breach id -> ms epoch of its last siren burst
let raSirenBurstUntil = 0;           // ms epoch; RA wants the siren playing until this time
function raWantsSirenNow() {
    return Date.now() < raSirenBurstUntil;
}
let raMutedBreachIds = new Set();
let raMuteExpiresAt = 0;
function isRaMuteActive() {
    return raMuteExpiresAt > Date.now();
}
function muteRaAlerts(breachIds) {
    raMutedBreachIds = new Set(breachIds);
    raMuteExpiresAt = Infinity;
    raSirenBurstUntil = 0; // cut a currently-sounding burst short immediately
    updateRestrictedAreaAlarmState(restrictedAreaBreaches);
}
function unmuteRaAlerts() {
    raMutedBreachIds = new Set();
    raMuteExpiresAt = 0;
    updateRestrictedAreaAlarmState(restrictedAreaBreaches);
}
document.getElementById('raMuteBtn')?.addEventListener('click', () => {
    if (isRaMuteActive()) {
        unmuteRaAlerts();
    } else {
        muteRaAlerts(restrictedAreaBreaches.filter(b => !b.exited_at).map(b => b.id));
    }
});

let raDismissedBreachIds = new Set();
function dismissRestrictedAreaOverlay() {
    raDismissedBreachIds = new Set(restrictedAreaBreaches.filter(b => !b.exited_at).map(b => b.id));
    updateRestrictedAreaAlarmState(restrictedAreaBreaches);
}
document.getElementById('restrictedAreaOverlayCloseBtn')?.addEventListener('click', dismissRestrictedAreaOverlay);

function updateRestrictedAreaAlarmState(items) {
    const overlay = document.getElementById('restrictedAreaOverlay');
    if (!overlay) return;
    const open = items.filter(b => !b.exited_at);
    const anyOpen = open.length > 0;
    const muteStillValid = isRaMuteActive() && open.every(b => raMutedBreachIds.has(b.id));
    const dismissStillValid = anyOpen && open.every(b => raDismissedBreachIds.has(b.id));

    // Schedule a siren burst for any open, unmuted breach that's due (never
    // played yet, or last played >= RA_SIREN_REMINDER_INTERVAL_MS ago) —
    // this is the ONLY place bursts get scheduled, run on every poll tick
    // but only actually fires roughly once per 15 minutes per breach.
    if (!muteStillValid) {
        const now = Date.now();
        open.forEach(b => {
            const last = raSirenLastPlayedAt.get(b.id) || 0;
            if (now - last >= RA_SIREN_REMINDER_INTERVAL_MS) {
                raSirenLastPlayedAt.set(b.id, now);
                raSirenBurstUntil = Math.max(raSirenBurstUntil, now + RA_SIREN_BURST_DURATION_MS);
            }
        });
    }

    if (!items.length) {
        overlay.classList.remove('ra-active', 'ra-calm');
        raMutedBreachIds = new Set();
        raMuteExpiresAt = 0;
        raDismissedBreachIds = new Set();
        raSirenLastPlayedAt = new Map();
        raSirenBurstUntil = 0;
    } else if (anyOpen && !dismissStillValid) {
        overlay.classList.add('ra-active');
        overlay.classList.remove('ra-calm');
    } else if (anyOpen) {
        overlay.classList.remove('ra-active');
        overlay.classList.add('ra-calm');
    } else {
        overlay.classList.remove('ra-active');
        overlay.classList.add('ra-calm');
        raSirenBurstUntil = 0; // nothing open anywhere — don't let a stale burst window linger
    }
    reconcileSharedSiren();

    const muteBtn = document.getElementById('raMuteBtn');
    if (muteBtn) {
        if (!anyOpen) {
            muteBtn.className = 'd-none';
        } else if (muteStillValid) {
            muteBtn.className = 'sos-mute-active';
            muteBtn.innerHTML = `<i class="bi bi-volume-mute-fill me-1"></i>${escapeHtml(t('restricted_area.muted_btn'))}`;
        } else {
            muteBtn.className = 'sos-mute-offer';
            muteBtn.innerHTML = `<i class="bi bi-volume-mute me-1"></i>${escapeHtml(t('restricted_area.mute_btn'))}`;
        }
    }
    const closeBtn = document.getElementById('restrictedAreaOverlayCloseBtn');
    if (closeBtn) closeBtn.classList.toggle('d-none', !(anyOpen && !dismissStillValid));
    const marqueeText = items.length
        ? items.map(b => t('restricted_area.marquee_text', {team: b.team_label.toUpperCase(), name: b.user_name, area: b.area_label})).join('     •••     ')
        : '';
    const overlayMarqueeText = document.getElementById('restrictedAreaOverlayMarqueeText');
    if (overlayMarqueeText) overlayMarqueeText.textContent = marqueeText;
}

// End of Mission / Return to Base — reuses the SOS siren sound engine (via
// reconcileSharedSiren(), which is siren-source-agnostic) but its own green
// full-screen overlay (not the SOS red corner pulse) and its own overlay
// element/timer, so it never reads or clobbers real SOS/RA state — it just
// declares "I want the siren while rtb-active" via rtbWantsSirenNow() above,
// and lets the shared arbitrator decide whether that's enough to stop it.
let returnToBaseTimer = null;
function triggerReturnToBaseAlarm(text) {
    const overlay = document.getElementById('returnToBaseOverlay');
    if (!overlay) return;
    const marqueeText = document.getElementById('returnToBaseMarqueeText');
    if (marqueeText) marqueeText.textContent = text || '';
    overlay.classList.add('rtb-active');
    reconcileSharedSiren();
    if (returnToBaseTimer) clearTimeout(returnToBaseTimer);
    returnToBaseTimer = setTimeout(() => {
        overlay.classList.remove('rtb-active');
        reconcileSharedSiren();
    }, 12000);
}

function showWarRoomBanner(id, text, orderId, alarmStyle) {
    if (activeBannerRows.has(id)) return;
    playWarRoomAlertSound();
    if (alarmStyle === 'return_to_base') triggerReturnToBaseAlarm(text);

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

// "Suggest replacement" — read-only lookup for a fatigued volunteer's roster
// row/button (see renderParticipantLiveData above for how the button itself
// shows/hides). Deliberately never writes anything (no swap request, no
// notification) — api-suggest-replacement.php just hands back a phone list
// for the admin to call manually. See suggestReplacementModal markup.
document.querySelectorAll('.suggest-replacement-btn').forEach(btn => {
    btn.addEventListener('click', () => openSuggestReplacementModal(btn.dataset.volunteerId, btn.dataset.volunteerName));
});

function openSuggestReplacementModal(volunteerId, volunteerName) {
    const modalEl = document.getElementById('suggestReplacementModal');
    if (!modalEl) return;
    const list = document.getElementById('suggestReplacementList');
    const header = `<p class="text-muted small mb-2">${t('fatigue.suggest_replacement_for', {name: volunteerName})}</p>`;
    list.innerHTML = header + `<p class="text-muted small">${t('common.loading')}</p>`;
    bootstrap.Modal.getOrCreateInstance(modalEl).show();

    const body = new URLSearchParams({csrf_token: csrfToken, mission_id: '<?= $missionId ?>', volunteer_id: volunteerId});
    fetch('api-suggest-replacement.php', {method: 'POST', body}).then(r => r.json()).then(result => {
        if (result.error) {
            list.innerHTML = header + `<p class="text-danger small">${escapeHtml(result.error)}</p>`;
            return;
        }
        const rows = result.volunteers || [];
        if (!rows.length) {
            list.innerHTML = header + `<p class="text-muted small">${t('fatigue.suggest_replacement_empty')}</p>`;
            return;
        }
        list.innerHTML = header + '<div class="list-group list-group-flush">' + rows.map(v => `
            <div class="list-group-item d-flex justify-content-between align-items-center">
                <span>${escapeHtml(v.name)}</span>
                ${v.phone
                    ? `<a href="tel:${escapeHtml(v.phone)}" class="btn btn-sm btn-outline-success"><i class="bi bi-telephone-fill me-1"></i>${escapeHtml(v.phone)}</a>`
                    : `<span class="text-muted small">${t('fatigue.no_phone')}</span>`}
            </div>
        `).join('') + '</div>';
    }).catch(() => {
        list.innerHTML = header + `<p class="text-danger small">${t('common.load_failed')}</p>`;
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

// Battery Status API — Chrome/Android works, disabled in Firefox, never
// shipped in Safari; irrelevant here since this app's native companion is
// Android-only. Never rejects/throws, resolving to null when unsupported —
// matches accuracy's own graceful `|| ''` omission below, must never block
// or fail a ping over a missing/unsupported reading.
function getBatteryLevelPct() {
    try {
        if (!navigator.getBattery) return Promise.resolve(null);
        return navigator.getBattery().then(b => Math.round(b.level * 100)).catch(() => null);
    } catch (e) {
        return Promise.resolve(null);
    }
}

document.querySelectorAll('.send-ping').forEach(button => button.addEventListener('click', () => {
    const status = document.getElementById('pingStatus-' + button.dataset.prId);
    if (!navigator.geolocation) { status.textContent = t('myping.gps_unsupported'); return; }
    button.disabled = true; status.textContent = t('myping.locating');
    // Fired concurrently with, not chained before, getCurrentPosition() below
    // so the geolocation call stays the very next thing invoked synchronously
    // from this click handler — no reason to risk a stricter browser's
    // user-activation gating over a battery read.
    const batteryPromise = getBatteryLevelPct();
    navigator.geolocation.getCurrentPosition(position => {
        batteryPromise.then(batteryLevel => {
            const data = new URLSearchParams({csrf_token: csrfToken, shift_id: button.dataset.shiftId, lat: position.coords.latitude, lng: position.coords.longitude, accuracy: position.coords.accuracy || '', battery_level: batteryLevel ?? ''});
            fetch('ping-location.php', {method:'POST', body:data}).then(response => {
                if (!checkSessionAlive(response)) { status.textContent = t('myping.ping_send_failed'); status.className = 'small mb-2 text-danger'; return null; }
                return response.json();
            }).then(result => {
                if (!result) return;
                status.textContent = result.ok ? t('myping.ping_sent_prefix', {time: result.ts}) : result.error;
                status.className = 'small mb-2 ' + (result.ok ? 'text-success' : 'text-danger');
            }).catch(() => { status.textContent = t('myping.ping_send_failed'); status.className = 'small mb-2 text-danger'; }).finally(() => button.disabled = false);
        });
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
    getBatteryLevelPct().then(batteryLevel => {
        buttons.forEach(button => {
            const data = new URLSearchParams({csrf_token: csrfToken, shift_id: button.dataset.shiftId, lat: position.coords.latitude, lng: position.coords.longitude, accuracy: position.coords.accuracy || '', battery_level: batteryLevel ?? '', source: 'auto'});
            fetch('ping-location.php', {method: 'POST', body: data}).then(checkSessionAlive).catch(() => {});
        });
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

// Native background GPS — Capacitor Android app only, no-op in any browser
// (including the installed PWA). Everything above this block keeps running
// unchanged for browser users; this is purely additive.
//
// The whole reason this exists: watchPosition() above — like any page JS —
// gets frozen by the OS the instant the screen locks or the app is
// backgrounded, so the pings above stop the moment a volunteer's phone
// screen turns off. That's not a bug in this file, it's a browser sandboxing
// limit no web code can lift (see includes/auth.php's WAR_ROOM_ACTION_SCRIPTS
// comment for the real incident this caused). Only a native OS background-
// location API can keep reporting through that. Same activation condition as
// the web auto-ping above (.send-ping buttons present = an active approved
// participation on an open mission) so the two mechanisms never disagree
// about whether tracking should be running.
//
// Uses the plugin's raw native-bridge API (Capacitor.Plugins.*) rather than
// the npm package's JS wrapper (import BackgroundGeolocation from '...'):
// this app loads the live site via capacitor.config.json's server.url with
// no JS bundler, so ES imports aren't available here — Capacitor.Plugins is
// Capacitor's own documented mechanism for exactly this remote-content case.
//
// Plugin is @capgo/background-geolocation (free — see mobile-app*/patches/
// for two small, deliberate patches on top of it: (1) upstream hardcodes its
// LocationManager update interval at 1s with no "check in periodically while
// stationary" concept beyond that, so a stationary volunteer waiting at a
// checkpoint would otherwise never get pinged at all under a real
// distanceFilter — patched to accept "intervalMs" from the start() call
// below instead; (2) upstream's native POST only ever sends Accept/
// Content-Type, no way to authenticate it — patched to accept "authToken"
// and send it as a real Authorization header. shift_id, not being a secret,
// rides the URL's query string instead — no patch needed for that part.
//
// Only the FIRST shift found drives native tracking if a volunteer somehow
// has more than one active shift on this same mission — the web auto-ping
// above already covers any additional ones whenever the tab is actually open.
// TEMPORARY diagnostic — see mobile-debug-log.php. Fire-and-forget, must
// never itself throw/delay the real tracking flow below. Session+CSRF authed
// (not bearer-token) specifically so it can log the EARLIEST possible
// lifecycle points too — plugin missing, button missing, token issuance
// failing — none of which have a bearer token yet. Deliberately declared
// OUTSIDE the plugin-presence check below, so "the plugin isn't even there"
// is itself something this can report.
const bgDebugLog = (event, detail) => {
    try {
        fetch('mobile-debug-log.php', {
            method: 'POST',
            body: new URLSearchParams({ csrf_token: csrfToken, source: 'js', event, detail: String(detail || '') })
        }).catch(() => {});
    } catch (e) {}
};

// The Capacitor bridge is not guaranteed to exist by the time this script
// runs, and on some WebViews it never arrives on its own at all. Capacitor has
// two injection mechanisms and BOTH can silently no-op for a server.url app:
// the modern addDocumentStartJavaScript() needs WebView 106+, and the older
// fallback re-fetches and rewrites each page through its own connection, which
// it skips outright for any non-GET navigation (so the page you land on right
// after submitting a form never gets a bridge) and for any fetch that errors
// or times out. The Android app's MainActivity now re-injects the bridge when
// it sees a page without one, but that runs asynchronously and can easily land
// after this script has already executed.
//
// Hence a bounded poll rather than the one-shot check this used to be. The old
// version tested once at parse time and gave up permanently, so a bridge that
// showed up even a few hundred ms late was indistinguishable from "not running
// in the native app" — background GPS simply never started, silently, for the
// entire shift. That is the bug this whole block exists to prevent.
const BG_BRIDGE_WAIT_MS = 20000;
const BG_BRIDGE_POLL_MS = 250;

function bgPluginReady() {
    return !!(window.Capacitor && window.Capacitor.Plugins && window.Capacitor.Plugins.BackgroundGeolocation);
}

let bgTrackingKickedOff = false;

function startNativeBackgroundTracking() {
    // Guarded: the poll and the pageshow listener below can both reach here.
    if (bgTrackingKickedOff) return;
    bgTrackingKickedOff = true;
    bgDebugLog('plugin_present', '');
    (async () => {
        const { BackgroundGeolocation, Preferences } = window.Capacitor.Plugins;
        const pingButton = document.querySelector('.send-ping');

        if (!pingButton) {
            bgDebugLog('no_ping_button', 'not in an active approved participation on an open mission');
            try { await BackgroundGeolocation.stop(); } catch (e) {}
            return;
        }

        try {
            const stored = await Preferences.get({ key: 'mobile_api_token' });
            let token = stored && stored.value;
            if (!token) {
                const issueResp = await fetch('mobile-token-issue.php', {
                    method: 'POST',
                    body: new URLSearchParams({ csrf_token: csrfToken, device_label: 'Android' })
                });
                const issued = await issueResp.json();
                if (issued.ok) {
                    token = issued.token;
                    await Preferences.set({ key: 'mobile_api_token', value: token });
                }
            }
            // No token (issuance failed) — leave native tracking off rather
            // than start a background service that can never authenticate.
            // The web auto-ping above still covers this tab while it's open.
            if (!token) {
                bgDebugLog('no_token', 'mobile-token-issue.php did not return a usable token');
                return;
            }
            bgDebugLog('hook_ready', 'pingButton found, token available');

            const shiftId = pingButton.dataset.shiftId;
            const pingUrl = window.location.origin + '/mobile-ping-location.php?shift_id=' + encodeURIComponent(shiftId);
            const startOptions = {
                backgroundTitle: <?= json_encode(getSetting('app_name', 'VolunteerOps')) ?>,
                backgroundMessage: t('bgtrack.notification_text'),
                distanceFilter: 0, // periodic cadence comes from intervalMs below, not movement — a stationary volunteer still needs to stay visible to command staff
                intervalMs: AUTO_PING_CADENCE_MS,
                url: pingUrl,
                authToken: token,
                requestPermissions: true
            };
            const onLocation = (location, error) => {
                // Native POST already handles delivery; this callback is
                // mainly useful for debugging via a connected device.
                if (error) console.error('[BackgroundGeolocation] location error', error);
            };

            try {
                bgDebugLog('start_attempt', 'intervalMs=' + AUTO_PING_CADENCE_MS + ' url=' + pingUrl);
                await BackgroundGeolocation.start(startOptions, onLocation);
                await Preferences.set({ key: 'bg_tracking_interval_ms', value: String(AUTO_PING_CADENCE_MS) });
                bgDebugLog('start_success', '');
            } catch (e) {
                // The plugin flatly rejects a 2nd start() call within the same
                // app process as ALREADY_STARTED — confirmed in
                // BackgroundGeolocation.java: it checks serviceConnectionFuture
                // and rejects BEFORE ever reaching the Service code that would
                // apply new params, even when intervalMs has genuinely changed
                // server-side. Real bug found live: an admin lowered the ping
                // cadence in Settings and it had zero effect on an
                // already-running session — the device kept pinging at its
                // stale original interval indefinitely, silently, until the
                // app was force-closed and reopened. Only force a stop+restart
                // when the interval actually changed since the last
                // successful start (tracked in Preferences, since the plugin
                // has no "read current config" API) — not on every ordinary
                // reload/tab-refocus, which would otherwise cause a brief
                // tracking gap for no reason.
                if (e && e.code === 'ALREADY_STARTED') {
                    const storedInterval = await Preferences.get({ key: 'bg_tracking_interval_ms' });
                    bgDebugLog('already_started', 'storedInterval=' + storedInterval.value + ' wantInterval=' + AUTO_PING_CADENCE_MS);
                    if (storedInterval.value !== String(AUTO_PING_CADENCE_MS)) {
                        try {
                            await BackgroundGeolocation.stop();
                            await BackgroundGeolocation.start(startOptions, onLocation);
                            await Preferences.set({ key: 'bg_tracking_interval_ms', value: String(AUTO_PING_CADENCE_MS) });
                            bgDebugLog('restart_success', '');
                        } catch (e2) {
                            console.error('[BackgroundGeolocation] restart with new interval failed', e2);
                            bgDebugLog('restart_failed', (e2 && (e2.code || e2.message)) || String(e2));
                        }
                    }
                } else {
                    console.error('[BackgroundGeolocation] setup failed', e);
                    bgDebugLog('start_failed', (e && (e.code || e.message)) || String(e));
                }
            }
        } catch (e) {
            console.error('[BackgroundGeolocation] setup failed', e);
            bgDebugLog('hook_exception', (e && e.message) || String(e));
        }
    })();
}

// Only reached after BG_BRIDGE_WAIT_MS with still no bridge, i.e. this is
// almost certainly an ordinary browser or the installed PWA rather than the
// Android app — in which case there is nothing to start and nothing is wrong.
// It still reports, because the one case that matters is the app failing to
// inject, and the two are indistinguishable from in here. The user agent is
// what tells them apart after the fact: a Capacitor WebView says "wv".
function reportMissingBridge(waitedMs) {
    // Distinguishes "no Capacitor plugins registered at all" (points at
    // BridgeActivity's plugin-loading try/catch swallowing a
    // ClassNotFoundException for SOME plugin — Capacitor's own PluginManager
    // aborts loading the whole list if any single entry's Class.forName()
    // fails, not just that one) from "only BackgroundGeolocation is missing".
    const hasCapacitor = !!window.Capacitor;
    const pluginKeys = (hasCapacitor && window.Capacitor.Plugins) ? Object.keys(window.Capacitor.Plugins) : [];
    bgDebugLog(
        'plugin_missing',
        'waitedMs=' + waitedMs + ' hasCapacitor=' + hasCapacitor + ' registeredPlugins=[' + pluginKeys.join(',') + ']'
    );
    // Logged unconditionally, not just as a one-off diagnostic round: the
    // WebView/Chromium version in here is the single fact that decides which
    // injection mechanism the device can even use (addDocumentStartJavaScript
    // needs roughly Chrome 106+), so it is worth having attached to every
    // report rather than needing another build to go find out.
    bgDebugLog('diag_useragent', navigator.userAgent || '');
}

// Start immediately when the bridge is already there (the normal case on a
// modern WebView), otherwise wait for the app's re-injection to land.
(function awaitCapacitorBridge() {
    if (bgPluginReady()) {
        startNativeBackgroundTracking();
        return;
    }
    const startedAt = Date.now();
    const timer = setInterval(() => {
        if (bgPluginReady()) {
            clearInterval(timer);
            bgDebugLog('plugin_late', 'bridge became usable after ' + (Date.now() - startedAt) + 'ms');
            startNativeBackgroundTracking();
        } else if (Date.now() - startedAt >= BG_BRIDGE_WAIT_MS) {
            clearInterval(timer);
            reportMissingBridge(Date.now() - startedAt);
        }
    }, BG_BRIDGE_POLL_MS);
})();

// Restoring this page from the back/forward cache re-runs no inline script, so
// without this a volunteer who leaves the app and comes back can sit on a War
// Room page whose native tracking was never started.
window.addEventListener('pageshow', () => {
    if (!bgTrackingKickedOff && bgPluginReady()) startNativeBackgroundTracking();
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
            renderRouteComposerAreas();
            if (data.media) {
                const sig = JSON.stringify(data.media);
                if (sig !== mediaSignature) {
                    mediaSignature = sig;
                    renderMedia(media = data.media);
                }
            }
        }
        if (data.broadcastPhotos) renderBroadcastPhotos(broadcastPhotos = data.broadcastPhotos);
        if (data.myTasks) renderMyTasks(myTasks = data.myTasks);
        if (data.routes) {
            routes = data.routes;
            renderMyRoutes(routes);
            if (!fieldMode) { renderRoutesAdmin(routes); renderRouteLayer(routes); }
        }
        if (data.shortageReports) renderShortageReports(shortageReports = data.shortageReports);
        if (data.incidents) {
            missionIncidents = data.incidents;
            renderMissionIncidents(missionIncidents);
            if (!fieldMode) renderIncidentLayer(missionIncidents);
        }
        if (data.sosAlerts) {
            renderSosAlerts(sosAlerts = data.sosAlerts);
            if (!fieldMode) updateSosAlarmState(sosAlerts);
        }
        if (data.pointsOfInterest) {
            pointsOfInterest = data.pointsOfInterest;
            renderPointsOfInterest(pointsOfInterest);
            if (!fieldMode) renderPoiLayer(pointsOfInterest);
        }
        // !== undefined, not a truthy check like the blocks above/below —
        // null is this field's normal "no profile filled in yet" value, not
        // an absent-key sentinel, since the PHP side always includes the key
        // (object or null) whenever isMissingPersonMission is true.
        if (data.missingPerson !== undefined) {
            missingPerson = data.missingPerson;
            renderMissingPersonCard(missingPerson);
            if (!fieldMode) { renderMissingPersonMarker(missingPerson); renderSearchRingsLayer(missingPerson); }
        }
        if (data.weather !== undefined) {
            weather = data.weather;
            exposureUrgency = data.exposureUrgency;
            renderWeatherCard(weather, exposureUrgency);
            if (!fieldMode) renderWeatherControl(weather);
        }
        if (data.firesOverlayOn !== undefined) {
            firesOverlayOn = data.firesOverlayOn;
            fireHotspots = data.fireHotspots;
            updateFiresToggleBtn();
            if (!fieldMode) renderFireLayer(fireHotspots);
        }
        if (data.areas) areas = data.areas;
        if (!fieldMode && data.teams) renderTeamRosters(data.teams);
        if (data.sectors) {
            sectors = data.sectors;
            renderMySectors(sectors);
            if (!fieldMode) { renderSectorLayer(sectors); renderSectorsList(sectors); renderAreaLayer(areas); }
        }
        if (data.restrictedAreas) {
            restrictedAreas = data.restrictedAreas;
            if (!fieldMode) { renderRestrictedAreaLayer(restrictedAreas); renderRestrictedAreasList(restrictedAreas); }
        }
        if (data.restrictedAreaBreaches) {
            restrictedAreaBreaches = data.restrictedAreaBreaches;
            // Deliberately NOT !fieldMode-gated, unlike updateSosAlarmState just
            // above — SOS is command-only by design, but this alarm's primary
            // audience is the field volunteer's own device. Mirrors how
            // triggerReturnToBaseAlarm (via the banners loop below) already
            // reaches fieldMode unconditionally.
            updateRestrictedAreaAlarmState(restrictedAreaBreaches);
        }
        if (data.restrictedAreaBreachHistory) {
            restrictedAreaBreachHistory = data.restrictedAreaBreachHistory;
            if (!fieldMode) renderRestrictedAreaBreachesList(restrictedAreaBreachHistory);
        }
        if (data.onlinePresence) renderPresence(data.onlinePresence, data.pingStaleness);
        if (data.pingStaleness) renderPingStaleness(data.pingStaleness);
        if (data.participantLive) renderParticipantLiveData(data.participantLive);
        if (data.nearbyTeams) renderNearbyTeams(nearbyTeams = data.nearbyTeams);
        if (data.restrictedAreaProximity) renderRestrictedAreaProximity(restrictedAreaProximity = data.restrictedAreaProximity);
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
        metaText.innerHTML = guestNameHtml(msg.name, msg.is_external, msg.home_team_name, msg.home_team_color_bg, msg.home_team_color_fg, msg.guest_country_code) + ' · ' + escapeHtml(msg.time);
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
    const coordsInput = document.getElementById('dispatchCoordsInput');
    const coordsAddBtn = document.getElementById('dispatchCoordsAddBtn');
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
        renderFullMapReference(refLayer);
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

    // Shared by real map clicks and the manual coordinates field below — one
    // place that actually appends to drawPoints, so a future third way to add
    // a point can't diverge from the marker/preview/send-state bookkeeping
    // the other two already do correctly.
    function addDrawPoint(lat, lng) {
        drawPoints.push([lat, lng]);
        vertexMarkers.push(L.circleMarker([lat, lng], {radius:7, color:'#7c3aed', fillColor:'#fff', fillOpacity:1, weight:2}).addTo(dispatchMap));
        updateShapePreview();
        updateSendState();
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
        addDrawPoint(e.latlng.lat, e.latlng.lng);
    }

    // Exact coordinates handed over verbally/in writing (e.g. a partner
    // organization reporting a possible sighting) — bypasses needing to find
    // and click the right spot on the map at all. Always just adds a point,
    // even past 3 vertices — the "click near point 1 closes the shape"
    // gesture above is a map-click-only affordance; typed coordinates that
    // happen to land near point 1 are far more likely to be a second real
    // point than an attempt at that gesture.
    function addCoordsFromInput() {
        const parsed = parseCoordsInput(coordsInput.value);
        if (!parsed) {
            alert(t('dispatch.coords_invalid'));
            return;
        }
        if (isClosed) {
            alert(t('dispatch.coords_shape_closed'));
            return;
        }
        addDrawPoint(parsed.lat, parsed.lng);
        dispatchMap.setView([parsed.lat, parsed.lng], Math.max(dispatchMap.getZoom(), 15));
        coordsInput.value = '';
    }
    coordsAddBtn.addEventListener('click', addCoordsFromInput);
    coordsInput.addEventListener('keydown', e => {
        if (e.key === 'Enter') { e.preventDefault(); addCoordsFromInput(); }
    });

    modalEl.addEventListener('shown.bs.modal', () => {
        if (!dispatchMap) {
            const center = missionLocation.lat ? [missionLocation.lat, missionLocation.lng] : [37.97, 23.73];
            dispatchMap = L.map('dispatchMap').setView(center, missionLocation.lat ? 13 : 7);
            addMapBaseLayers(dispatchMap, 'dispatchSatelliteToggle');
            refLayer = L.layerGroup().addTo(dispatchMap);
            dispatchMap.on('click', onMapClick);
        }
        renderDispatchContext();
        // Pre-fill from a ring's "send team here" button (openDispatchForRing()
        // in the !fieldMode block) — one-shot, same consume-then-null pattern
        // pendingDivideAreaId uses. addDrawPoint() alone leaves isClosed false
        // and Send disabled (it only tracks point count, never shape-closedness
        // on its own) so both must be set explicitly here, same as the
        // "click near point 1" gesture already does for a hand-drawn polygon.
        if (pendingDispatchSeed) {
            pendingDispatchSeed.points.forEach(pt => addDrawPoint(pt[0], pt[1]));
            isClosed = true;
            updateShapePreview();
            updateSendState();
            noteInput.value = pendingDispatchSeed.label;
            if (drawPoints.length) dispatchMap.fitBounds(L.latLngBounds(drawPoints), {padding: [30, 30]});
            pendingDispatchSeed = null;
        }
        setTimeout(() => dispatchMap.invalidateSize(), 100);
    });

    modalEl.addEventListener('hidden.bs.modal', () => {
        resetDrawing();
        addressInput.value = '';
        addressStatus.textContent = '';
        lastAddressLabel = '';
        coordsInput.value = '';
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

(function() {
    const modalEl = document.getElementById('divideSectorsModal');
    if (!modalEl) return;

    const areaLabelEl = document.getElementById('divideSectorsAreaLabel');
    const wedgeListEl = document.getElementById('divideSectorsWedgeList');
    const clearBtn = document.getElementById('divideSectorsClearBtn');
    const saveBtn = document.getElementById('divideSectorsSaveBtn');

    // Greek capitals, matching the wedge-lettering convention already used
    // for the placeholder sector label ("Τομέας Α1") — 24 letters is far
    // beyond any realistic single-area subdivision.
    const GREEK_LETTERS = ['Α','Β','Γ','Δ','Ε','Ζ','Η','Θ','Ι','Κ','Λ','Μ','Ν','Ξ','Ο','Π','Ρ','Σ','Τ','Υ','Φ','Χ','Ψ','Ω'];
    const WEDGE_COLORS = ['#0d6efd','#198754','#dc3545','#fd7e14','#6f42c1','#20c997','#d63384','#0dcaf0'];

    let composerMap = null;
    let wedgeLayer = null;
    let refLayer = null;
    let currentArea = null;
    let vertexMarkers = [];
    // Each entry is [aIndex, bIndex] — a straight line between two of the
    // area's own vertices. Lines don't need to share a common point like
    // the old hub-and-spoke model required; any two vertices can be
    // connected as long as the line doesn't cross one already drawn.
    let chords = [];
    // First vertex of a line in progress, or null between clicks.
    let pendingFirst = null;

    function edgeKey(a, b) { return a < b ? a + '_' + b : b + '_' + a; }

    // A vertex pair is rejected before it ever reaches the crossing math
    // below if it's already an edge of the original area (adjacent
    // vertices — a "line" between them has zero width) or duplicates a
    // line already drawn.
    function isEdgeAlready(a, b) {
        const n = currentArea.geo.length;
        const diff = Math.abs(a - b);
        if (diff === 1 || diff === n - 1) return true;
        return chords.some(([ca, cb]) => edgeKey(ca, cb) === edgeKey(a, b));
    }

    // Standard orientation/on-segment test so both a proper crossing and a
    // collinear overlap are caught, not just simple transversal crossings.
    function orientation(p, q, r) {
        const val = (q[1] - p[1]) * (r[0] - q[0]) - (q[0] - p[0]) * (r[1] - q[1]);
        if (Math.abs(val) < 1e-12) return 0;
        return val > 0 ? 1 : 2;
    }
    function onSegment(p, q, r) {
        return Math.min(p[0], r[0]) - 1e-12 <= q[0] && q[0] <= Math.max(p[0], r[0]) + 1e-12 &&
               Math.min(p[1], r[1]) - 1e-12 <= q[1] && q[1] <= Math.max(p[1], r[1]) + 1e-12;
    }
    function segmentsCross(p1, p2, p3, p4) {
        const o1 = orientation(p1, p2, p3), o2 = orientation(p1, p2, p4);
        const o3 = orientation(p3, p4, p1), o4 = orientation(p3, p4, p2);
        if (o1 !== o2 && o3 !== o4) return true;
        if (o1 === 0 && onSegment(p1, p3, p2)) return true;
        if (o2 === 0 && onSegment(p1, p4, p2)) return true;
        if (o3 === 0 && onSegment(p3, p1, p4)) return true;
        if (o4 === 0 && onSegment(p3, p2, p4)) return true;
        return false;
    }

    // Whether a new line a-b is allowed: not already an edge/duplicate, and
    // doesn't cross any line already drawn. Lines sharing one endpoint
    // (e.g. A-F and F-C) are fine — only pairs with 4 distinct vertices are
    // checked for crossing.
    function isValidChord(a, b) {
        if (a === b || isEdgeAlready(a, b)) return false;
        const [pa, pb] = [currentArea.geo[a], currentArea.geo[b]];
        return !chords.some(([ca, cb]) => {
            if (ca === a || ca === b || cb === a || cb === b) return false;
            return segmentsCross(pa, pb, currentArea.geo[ca], currentArea.geo[cb]);
        });
    }

    // Walks a cyclic vertex-index ring forward from a to b and from b to a,
    // giving the two rings that result from splitting it at that line.
    function splitRing(ring, a, b) {
        const n = ring.length;
        const ia = ring.indexOf(a), ib = ring.indexOf(b);
        const arc1 = [];
        for (let k = ia; ; k = (k + 1) % n) {
            arc1.push(ring[k]);
            if (k === ib) break;
        }
        const arc2 = [];
        for (let k = ib; ; k = (k + 1) % n) {
            arc2.push(ring[k]);
            if (k === ia) break;
        }
        return [arc1, arc2];
    }

    // Starts from the whole area as a single face and cuts it once per
    // drawn line, splitting whichever current face contains that line's
    // two endpoints. Because lines are validated as non-crossing up front,
    // that face is always unique — this same function covers the old
    // "one sector = whole area" case with zero lines drawn, and the old
    // hub-and-spoke fan as the special case where every line shares a
    // vertex.
    function computeWedges() {
        if (!currentArea) return [];
        const n = currentArea.geo.length;
        let faces = [Array.from({length: n}, (_, i) => i)];
        chords.forEach(([a, b]) => {
            const fi = faces.findIndex(f => f.includes(a) && f.includes(b));
            if (fi === -1) return; // shouldn't happen for validated chords
            const [arc1, arc2] = splitRing(faces[fi], a, b);
            faces.splice(fi, 1, arc1, arc2);
        });
        return faces.map(ring => ring.map(idx => currentArea.geo[idx]));
    }

    function wedgeCentroid(poly) {
        const lat = poly.reduce((s, p) => s + p[0], 0) / poly.length;
        const lng = poly.reduce((s, p) => s + p[1], 0) / poly.length;
        return [lat, lng];
    }

    function renderVertexMarkers() {
        vertexMarkers.forEach(m => composerMap.removeLayer(m));
        vertexMarkers = [];
        if (!currentArea) return;
        currentArea.geo.forEach((pt, idx) => {
            const isPending = idx === pendingFirst;
            const marker = L.circleMarker(pt, {radius: isPending ? 10 : 7, color: '#fff', weight: 2, fillColor: isPending ? '#212529' : '#0d6efd', fillOpacity: 1}).addTo(composerMap);
            marker.on('click', () => onVertexClick(idx));
            vertexMarkers.push(marker);
        });
    }

    function onVertexClick(idx) {
        if (pendingFirst === null) {
            pendingFirst = idx;
        } else if (idx === pendingFirst) {
            pendingFirst = null; // clicking the pending point again cancels it
        } else if (isValidChord(pendingFirst, idx)) {
            chords.push([pendingFirst, idx]);
            pendingFirst = null;
        } else {
            alert(t('sector.line_invalid'));
            return;
        }
        renderVertexMarkers();
        renderWedges();
    }

    function undo() {
        if (pendingFirst !== null) {
            pendingFirst = null;
        } else if (chords.length) {
            chords.pop();
        } else {
            return;
        }
        renderVertexMarkers();
        renderWedges();
    }

    function keydownHandler(e) {
        if (!(e.ctrlKey || e.metaKey) || e.key.toLowerCase() !== 'z') return;
        const activeTag = document.activeElement ? document.activeElement.tagName : '';
        if (activeTag === 'INPUT' || activeTag === 'TEXTAREA') return; // let native text-undo happen
        e.preventDefault();
        undo();
    }

    function renderWedges() {
        wedgeLayer.clearLayers();
        const wedges = computeWedges();
        const existingValues = Array.from(wedgeListEl.querySelectorAll('.wedge-label-input')).map(inp => inp.value);
        wedgeListEl.innerHTML = '';
        wedges.forEach((poly, i) => {
            const color = WEDGE_COLORS[i % WEDGE_COLORS.length];
            const letter = GREEK_LETTERS[i % GREEK_LETTERS.length];
            // interactive:false — this fill is pure visual feedback, never
            // meant to be clickable; without it, it silently steals clicks
            // from the vertex circle-markers underneath (later-added SVG
            // paths paint on top and win hit-testing), found live when a
            // second/third vertex click stopped registering as soon as the
            // first wedge existed.
            L.polygon(poly, {color, weight: 2, fillColor: color, fillOpacity: 0.35, interactive: false}).addTo(wedgeLayer);
            const [lat, lng] = wedgeCentroid(poly);
            L.marker([lat, lng], {
                icon: L.divIcon({className: '', html: `<div style="font-weight:700;font-size:16px;color:${color};text-shadow:0 0 3px #fff,0 0 3px #fff,0 0 3px #fff;">${letter}</div>`, iconSize: [24, 24], iconAnchor: [12, 12]}),
                interactive: false,
            }).addTo(wedgeLayer);

            const row = document.createElement('div');
            row.className = 'input-group input-group-sm mb-2';
            const swatch = document.createElement('span');
            swatch.className = 'input-group-text justify-content-center text-white';
            swatch.style.cssText = `background:${color};min-width:34px;`;
            swatch.textContent = letter;
            const input = document.createElement('input');
            input.type = 'text';
            input.className = 'form-control wedge-label-input';
            input.maxLength = 255;
            // Preserve any label the admin already typed for this position
            // across a re-render (e.g. after adding one more cut) rather
            // than clobbering it back to the auto-suggested default.
            input.value = existingValues[i] || t('sector.wedge_label_placeholder', {letter});
            row.appendChild(swatch);
            row.appendChild(input);
            wedgeListEl.appendChild(row);
        });
        saveBtn.disabled = wedges.length === 0;
    }

    function resetDivision() {
        chords = [];
        pendingFirst = null;
        renderVertexMarkers();
        renderWedges();
    }

    clearBtn.addEventListener('click', resetDivision);

    saveBtn.addEventListener('click', () => {
        const wedges = computeWedges();
        if (!wedges.length || !currentArea) return;
        const labelInputs = wedgeListEl.querySelectorAll('.wedge-label-input');
        saveBtn.disabled = true;
        const posts = wedges.map((poly, i) => {
            const label = (labelInputs[i] ? labelInputs[i].value.trim() : '') || `${t('sector.wedge_label_placeholder', {letter: GREEK_LETTERS[i % GREEK_LETTERS.length]})}`;
            const data = new URLSearchParams({
                csrf_token: csrfToken, action: 'create', mission_id: <?= $missionId ?>,
                area_id: currentArea.id, label, geo: JSON.stringify(poly),
            });
            return fetch('mission-sector.php', {method: 'POST', body: data}).then(r => r.json());
        });
        Promise.all(posts).then(results => {
            const failed = results.find(r => !r.ok);
            // Re-sync from the server's own truth rather than trust any one
            // POST's own echoed payload — safer when several requests landed
            // concurrently and one of them failed partway through the batch.
            fetch('mission-sector.php?mission_id=<?= $missionId ?>').then(r => r.json()).then(fresh => {
                if (fresh.ok) sectorRefreshAfter(fresh.sectors, fresh.areas);
                if (failed) {
                    alert(failed.error || t('common.send_failed'));
                    saveBtn.disabled = false;
                } else {
                    bootstrap.Modal.getInstance(modalEl).hide();
                }
            });
        }).catch(() => { alert(t('common.send_failed')); saveBtn.disabled = false; });
    });

    modalEl.addEventListener('shown.bs.modal', () => {
        currentArea = areas.find(a => a.id === pendingDivideAreaId) || null;
        pendingDivideAreaId = null;
        areaLabelEl.textContent = currentArea ? currentArea.label : '';
        if (!composerMap) {
            composerMap = L.map('divideSectorsMap');
            addMapBaseLayers(composerMap, 'divideSectorsSatelliteToggle');
            refLayer = L.layerGroup().addTo(composerMap);
            wedgeLayer = L.layerGroup().addTo(composerMap);
        }
        refLayer.clearLayers();
        renderFullMapReference(refLayer);
        if (currentArea && currentArea.geo && currentArea.geo.length) {
            composerMap.fitBounds(L.latLngBounds(currentArea.geo), {padding: [30, 30]});
        }
        resetDivision();
        document.addEventListener('keydown', keydownHandler);
        setTimeout(() => composerMap.invalidateSize(), 100);
    });

    modalEl.addEventListener('hidden.bs.modal', () => {
        document.removeEventListener('keydown', keydownHandler);
    });
})();

(function() {
    const modalEl = document.getElementById('splitSectorModal');
    if (!modalEl) return;

    const labelEl = document.getElementById('splitSectorLabel');
    const previewEl = document.getElementById('splitSectorPreview');
    const swatch1 = document.getElementById('splitSectorSwatch1');
    const swatch2 = document.getElementById('splitSectorSwatch2');
    const labelInput1 = document.getElementById('splitSectorLabelInput1');
    const labelInput2 = document.getElementById('splitSectorLabelInput2');
    const clearBtn = document.getElementById('splitSectorClearBtn');
    const saveBtn = document.getElementById('splitSectorSaveBtn');

    const COLOR_1 = '#0d6efd', COLOR_2 = '#198754';
    // Screen-pixel distance a click must land within an edge to be accepted
    // as a cut point — same order of magnitude as the existing "click near
    // the first point to close the shape" threshold elsewhere in this file.
    const SNAP_PX = 18;

    let composerMap = null;
    let previewLayer = null;
    let refLayer = null;
    let currentSector = null;
    // Each is null, or a "ring position": floor(r) = edge index (edge from
    // geo[i] to geo[(i+1)%n]), frac(r) = how far along that edge (0 = right
    // at geo[i]). This one representation covers both "clicked an existing
    // corner" (frac exactly 0) and "clicked partway along an edge" — a
    // corner-only model can never split a triangle, since connecting any two
    // of its 3 corners is just an existing edge, not a new cut.
    let cutPoint1 = null;
    let cutPoint2 = null;
    let actionStack = [];

    function pointAtRingPos(ring, r) {
        const n = ring.length;
        const i = ((Math.floor(r) % n) + n) % n;
        const t = r - Math.floor(r);
        const a = ring[i];
        if (t < 1e-9) return a.slice();
        const b = ring[(i + 1) % n];
        return [a[0] + (b[0] - a[0]) * t, a[1] + (b[1] - a[1]) * t];
    }

    // Closest point on segment a-b to point p, all as [lat,lng] — flat local
    // approximation, accurate enough at mission scale (a few km at most).
    function closestPointOnSegment(p, a, b) {
        const dx = b[1] - a[1], dy = b[0] - a[0];
        const lenSq = dx * dx + dy * dy;
        if (lenSq < 1e-15) return {point: a.slice(), t: 0};
        let t = ((p[1] - a[1]) * dx + (p[0] - a[0]) * dy) / lenSq;
        t = Math.max(0, Math.min(1, t));
        return {point: [a[0] + dy * t, a[1] + dx * t], t};
    }

    // Projects a raw map click onto the sector's own boundary: finds the
    // closest edge, and if that closest point is within SNAP_PX on screen,
    // returns its ring position (snapped to the nearer endpoint if very
    // close to one) — otherwise null (click was too far from the boundary
    // to mean anything, e.g. deep in the polygon's interior).
    function ringPositionAt(latlng) {
        if (!currentSector) return null;
        const ring = currentSector.geo;
        const n = ring.length;
        const clickPt = composerMap.latLngToContainerPoint(latlng);
        let best = null;
        for (let i = 0; i < n; i++) {
            const proj = closestPointOnSegment([latlng.lat, latlng.lng], ring[i], ring[(i + 1) % n]);
            const projPt = composerMap.latLngToContainerPoint(proj.point);
            const dist = clickPt.distanceTo(projPt);
            if (best === null || dist < best.dist) best = {dist, edgeIndex: i, t: proj.t};
        }
        if (!best || best.dist > SNAP_PX) return null;
        if (best.t < 0.04) return best.edgeIndex;
        if (best.t > 0.96) return (best.edgeIndex + 1) % n;
        return best.edgeIndex + best.t;
    }

    // Given the two accepted ring positions, walks the original vertex ring
    // to build both halves: polygon A gets everything strictly between r1
    // and r2 (plus the two new cut points closing it), polygon B gets
    // everything strictly outside that range (wrapping past the end). A cut
    // that leaves either half under 3 points (e.g. both points on the same
    // edge) is rejected here, before Save is ever enabled.
    function computeSplit() {
        if (cutPoint1 === null || cutPoint2 === null || !currentSector || cutPoint1 === cutPoint2) return [];
        let r1 = cutPoint1, r2 = cutPoint2;
        if (r1 > r2) { const tmp = r1; r1 = r2; r2 = tmp; }
        const ring = currentSector.geo;
        const p1 = pointAtRingPos(ring, r1);
        const p2 = pointAtRingPos(ring, r2);
        const polyA = [p1];
        const polyB = [p2];
        for (let i = 0; i < ring.length; i++) {
            if (i > r1 && i < r2) polyA.push(ring[i]);
            else if (i > r2 || i < r1) polyB.push(ring[i]);
        }
        polyA.push(p2);
        polyB.push(p1);
        if (polyA.length < 3 || polyB.length < 3) return [];
        return [polyA, polyB];
    }

    function renderBoundary() {
        if (!currentSector) return;
        L.polygon(currentSector.geo, {color: '#6c757d', weight: 2, dashArray: '6,4', fillOpacity: 0.03, interactive: false}).addTo(previewLayer);
        currentSector.geo.forEach(pt => {
            L.circleMarker(pt, {radius: 5, color: '#fff', weight: 1.5, fillColor: '#6c757d', fillOpacity: 1, interactive: false}).addTo(previewLayer);
        });
    }

    function renderCutPointMarker(r, color) {
        if (r === null) return;
        const pt = pointAtRingPos(currentSector.geo, r);
        L.circleMarker(pt, {radius: 8, color: '#fff', weight: 2, fillColor: color, fillOpacity: 1, interactive: false}).addTo(previewLayer);
    }

    function renderSplitPreview() {
        previewLayer.clearLayers();
        renderBoundary();
        renderCutPointMarker(cutPoint1, COLOR_1);
        renderCutPointMarker(cutPoint2, COLOR_2);

        const halves = computeSplit();
        if (halves.length === 2) {
            L.polygon(halves[0], {color: COLOR_1, weight: 2, fillColor: COLOR_1, fillOpacity: 0.35, interactive: false}).addTo(previewLayer);
            L.polygon(halves[1], {color: COLOR_2, weight: 2, fillColor: COLOR_2, fillOpacity: 0.35, interactive: false}).addTo(previewLayer);
            swatch1.style.background = COLOR_1;
            swatch2.style.background = COLOR_2;
            if (!labelInput1.value) labelInput1.value = t('sector.split_label_suffix', {label: currentSector.label, letter: 'Α'});
            if (!labelInput2.value) labelInput2.value = t('sector.split_label_suffix', {label: currentSector.label, letter: 'Β'});
            previewEl.style.display = '';
        } else {
            previewEl.style.display = 'none';
        }
        saveBtn.disabled = halves.length !== 2;
    }

    function onSplitMapClick(e) {
        const r = ringPositionAt(e.latlng);
        if (r === null) return;
        if (cutPoint1 === null) {
            cutPoint1 = r;
            actionStack.push('point1');
        } else if (cutPoint2 === null) {
            if (Math.abs(r - cutPoint1) < 0.02) return; // same spot as point 1, ignore
            cutPoint2 = r;
            actionStack.push('point2');
        } else {
            return; // both already set — Clear or Ctrl+Z to change either
        }
        renderSplitPreview();
    }

    function resetSplit() {
        cutPoint1 = null;
        cutPoint2 = null;
        actionStack = [];
        labelInput1.value = '';
        labelInput2.value = '';
        renderSplitPreview();
    }

    function undo() {
        if (!actionStack.length) return;
        const last = actionStack.pop();
        if (last === 'point2') cutPoint2 = null;
        else cutPoint1 = null;
        renderSplitPreview();
    }

    function keydownHandler(e) {
        if (!(e.ctrlKey || e.metaKey) || e.key.toLowerCase() !== 'z') return;
        const activeTag = document.activeElement ? document.activeElement.tagName : '';
        if (activeTag === 'INPUT' || activeTag === 'TEXTAREA') return;
        e.preventDefault();
        undo();
    }

    clearBtn.addEventListener('click', resetSplit);

    saveBtn.addEventListener('click', () => {
        const halves = computeSplit();
        if (halves.length !== 2 || !currentSector) return;
        const label1 = labelInput1.value.trim() || t('sector.split_label_suffix', {label: currentSector.label, letter: 'Α'});
        const label2 = labelInput2.value.trim() || t('sector.split_label_suffix', {label: currentSector.label, letter: 'Β'});
        const missionId = <?= $missionId ?>;
        const originalId = currentSector.id;
        const areaId = currentSector.area_id;
        saveBtn.disabled = true;
        const post = (label, geo) => {
            const data = new URLSearchParams({csrf_token: csrfToken, action: 'create', mission_id: missionId, area_id: areaId, label, geo: JSON.stringify(geo)});
            return fetch('mission-sector.php', {method: 'POST', body: data}).then(r => r.json());
        };
        // Create both halves BEFORE deleting the original — if anything
        // fails partway through, the failure mode is "3 sectors instead of
        // 2" (recoverable by hand), never "0 sectors" from deleting first.
        Promise.all([post(label1, halves[0]), post(label2, halves[1])]).then(createResults => {
            const createFailed = createResults.find(r => !r.ok);
            const delData = new URLSearchParams({csrf_token: csrfToken, action: 'delete', mission_id: missionId, id: originalId});
            return fetch('mission-sector.php', {method: 'POST', body: delData}).then(r => r.json())
                .then(delResult => ({createFailed, delResult}));
        }).then(({createFailed, delResult}) => {
            fetch('mission-sector.php?mission_id=' + missionId).then(r => r.json()).then(fresh => {
                if (fresh.ok) sectorRefreshAfter(fresh.sectors, fresh.areas);
                if (createFailed || !delResult.ok) {
                    alert((createFailed && createFailed.error) || delResult.error || t('common.send_failed'));
                    saveBtn.disabled = false;
                } else {
                    bootstrap.Modal.getInstance(modalEl).hide();
                }
            });
        }).catch(() => { alert(t('common.send_failed')); saveBtn.disabled = false; });
    });

    modalEl.addEventListener('shown.bs.modal', () => {
        currentSector = sectors.find(s => s.id === pendingSplitSectorId) || null;
        pendingSplitSectorId = null;
        labelEl.textContent = currentSector ? currentSector.label : '';
        if (!composerMap) {
            composerMap = L.map('splitSectorMap');
            addMapBaseLayers(composerMap, 'splitSectorSatelliteToggle');
            refLayer = L.layerGroup().addTo(composerMap);
            previewLayer = L.layerGroup().addTo(composerMap);
            composerMap.on('click', onSplitMapClick);
        }
        refLayer.clearLayers();
        renderFullMapReference(refLayer);
        if (currentSector && currentSector.geo && currentSector.geo.length) {
            composerMap.fitBounds(L.latLngBounds(currentSector.geo), {padding: [30, 30]});
        }
        resetSplit();
        document.addEventListener('keydown', keydownHandler);
        setTimeout(() => composerMap.invalidateSize(), 100);
    });

    modalEl.addEventListener('hidden.bs.modal', () => {
        document.removeEventListener('keydown', keydownHandler);
    });
})();

(function() {
    const modalEl = document.getElementById('searchAreaMapModal');
    if (!modalEl) return;

    const addressInput = document.getElementById('areaAddressInput');
    const addressSearchBtn = document.getElementById('areaAddressSearch');
    const addressStatus = document.getElementById('areaAddressStatus');
    const coordsInput = document.getElementById('areaCoordsInput');
    const coordsAddBtn = document.getElementById('areaCoordsAddBtn');
    const labelInput = document.getElementById('areaLabelInput');
    const clearBtn = document.getElementById('areaClearBtn');
    const sendBtn = document.getElementById('areaSendBtn');

    let composerMap = null;
    let refLayer = null;
    let drawPoints = [];
    let vertexMarkers = [];
    let shapeLayer = null;
    let isClosed = false;
    // Undo history: {type:'add'} | {type:'move', index, from} | {type:'close'}.
    let actionStack = [];

    function vertexIcon() {
        return L.divIcon({className: '', html: '<div style="width:14px;height:14px;border-radius:50%;background:#fff;border:3px solid #495057;box-shadow:0 1px 3px #0006;"></div>', iconSize: [14, 14], iconAnchor: [7, 7]});
    }

    // Dimmed, read-only copy of the live map's pins + existing areas — same
    // technique as the sector composer's own renderSectorContext() above,
    // simplified (no sectors — the point here is spacing against other
    // areas, not what's already inside any one of them).
    function renderAreaComposerContext() {
        if (!refLayer) return;
        refLayer.clearLayers();
        renderFullMapReference(refLayer);
    }

    function resetDrawing() {
        drawPoints = [];
        isClosed = false;
        actionStack = [];
        vertexMarkers.forEach(m => composerMap.removeLayer(m));
        vertexMarkers = [];
        if (shapeLayer) { composerMap.removeLayer(shapeLayer); shapeLayer = null; }
        sendBtn.disabled = true;
    }

    function updateShapePreview() {
        if (shapeLayer) { composerMap.removeLayer(shapeLayer); shapeLayer = null; }
        if (drawPoints.length < 2) return;
        shapeLayer = isClosed
            ? L.polygon(drawPoints, {color:'#495057', fillOpacity:0.1}).addTo(composerMap)
            : L.polyline(drawPoints, {color:'#495057'}).addTo(composerMap);
    }

    // Polygon-only, same reasoning as the sector composer — an area is
    // always a boundary, so Save only enables once closed with ≥3 vertices.
    function updateSendState() {
        sendBtn.disabled = !(isClosed && drawPoints.length >= 3);
    }

    function addDrawPoint(lat, lng) {
        const idx = vertexMarkers.length;
        drawPoints.push([lat, lng]);
        const marker = L.marker([lat, lng], {icon: vertexIcon(), draggable: true}).addTo(composerMap);
        marker.on('dragend', () => {
            const ll = marker.getLatLng();
            actionStack.push({type: 'move', index: idx, from: drawPoints[idx].slice()});
            drawPoints[idx] = [ll.lat, ll.lng];
            updateShapePreview();
        });
        vertexMarkers.push(marker);
        actionStack.push({type: 'add'});
        updateShapePreview();
        updateSendState();
    }

    function undo() {
        if (!actionStack.length) return;
        const last = actionStack.pop();
        if (last.type === 'add') {
            const marker = vertexMarkers.pop();
            if (marker) composerMap.removeLayer(marker);
            drawPoints.pop();
        } else if (last.type === 'move') {
            drawPoints[last.index] = last.from;
            vertexMarkers[last.index].setLatLng(last.from);
        } else if (last.type === 'close') {
            isClosed = false;
        }
        updateShapePreview();
        updateSendState();
    }

    function keydownHandler(e) {
        if (!(e.ctrlKey || e.metaKey) || e.key.toLowerCase() !== 'z') return;
        const activeTag = document.activeElement ? document.activeElement.tagName : '';
        if (activeTag === 'INPUT' || activeTag === 'TEXTAREA') return; // let native text-undo happen
        e.preventDefault();
        undo();
    }

    function onMapClick(e) {
        if (isClosed) return;
        if (drawPoints.length >= 3) {
            const firstPoint = composerMap.latLngToContainerPoint(L.latLng(drawPoints[0]));
            const clickPoint = composerMap.latLngToContainerPoint(e.latlng);
            if (firstPoint.distanceTo(clickPoint) < 16) {
                isClosed = true;
                actionStack.push({type: 'close'});
                updateShapePreview();
                updateSendState();
                return;
            }
        }
        addDrawPoint(e.latlng.lat, e.latlng.lng);
    }

    function addCoordsFromInput() {
        const parsed = parseCoordsInput(coordsInput.value);
        if (!parsed) {
            alert(t('dispatch.coords_invalid'));
            return;
        }
        if (isClosed) {
            alert(t('dispatch.coords_shape_closed'));
            return;
        }
        addDrawPoint(parsed.lat, parsed.lng);
        composerMap.setView([parsed.lat, parsed.lng], Math.max(composerMap.getZoom(), 15));
        coordsInput.value = '';
    }
    coordsAddBtn.addEventListener('click', addCoordsFromInput);
    coordsInput.addEventListener('keydown', e => {
        if (e.key === 'Enter') { e.preventDefault(); addCoordsFromInput(); }
    });

    modalEl.addEventListener('shown.bs.modal', () => {
        if (!composerMap) {
            const center = missionLocation.lat ? [missionLocation.lat, missionLocation.lng] : [37.97, 23.73];
            composerMap = L.map('areaComposerMap').setView(center, missionLocation.lat ? 13 : 7);
            addMapBaseLayers(composerMap, 'areaComposerSatelliteToggle');
            refLayer = L.layerGroup().addTo(composerMap);
            composerMap.on('click', onMapClick);
        }
        renderAreaComposerContext();
        document.addEventListener('keydown', keydownHandler);
        setTimeout(() => composerMap.invalidateSize(), 100);
    });

    modalEl.addEventListener('hidden.bs.modal', () => {
        resetDrawing();
        addressInput.value = '';
        addressStatus.textContent = '';
        coordsInput.value = '';
        labelInput.value = '';
        document.removeEventListener('keydown', keydownHandler);
    });

    clearBtn.addEventListener('click', resetDrawing);

    addressSearchBtn.addEventListener('click', () => {
        const q = addressInput.value.trim();
        if (!q) return;
        addressStatus.textContent = t('dispatch.searching');
        fetch('geocode-address.php?q=' + encodeURIComponent(q)).then(response => response.json()).then(result => {
            if (result.ok) {
                composerMap.setView([result.lat, result.lng], 16);
                addressStatus.textContent = '✓ ' + (result.display_name || q);
            } else {
                addressStatus.textContent = result.error || t('dispatch.address_not_found');
            }
        }).catch(() => { addressStatus.textContent = t('dispatch.search_failed'); });
    });

    sendBtn.addEventListener('click', () => {
        const label = labelInput.value.trim();
        if (!label) {
            alert(t('sector.invalid_label'));
            return;
        }
        const data = new URLSearchParams({
            csrf_token: csrfToken, action: 'create_area', mission_id: <?= $missionId ?>,
            geo: JSON.stringify(drawPoints), label: label,
        });
        sendBtn.disabled = true;
        fetch('mission-sector.php', {method:'POST', body:data}).then(response => response.json()).then(result => {
            if (result.ok) {
                bootstrap.Modal.getInstance(modalEl).hide();
                sectorRefreshAfter(result.sectors, result.areas);
            } else {
                alert(result.error || t('common.send_failed'));
                sendBtn.disabled = false;
            }
        }).catch(() => { alert(t('common.send_failed')); sendBtn.disabled = false; });
    });
})();

// Restricted-area composer — structural clone of the searchAreaMapModal IIFE
// directly above (draggable vertices, Ctrl+Z action-stack, address/coords
// input, polygon-only). Save posts to mission-restricted-area.php's own
// create action instead of mission-sector.php's create_area.
(function() {
    const modalEl = document.getElementById('restrictedAreaMapModal');
    if (!modalEl) return;

    const addressInput = document.getElementById('restrictedAreaAddressInput');
    const addressSearchBtn = document.getElementById('restrictedAreaAddressSearch');
    const addressStatus = document.getElementById('restrictedAreaAddressStatus');
    const coordsInput = document.getElementById('restrictedAreaCoordsInput');
    const coordsAddBtn = document.getElementById('restrictedAreaCoordsAddBtn');
    const labelInput = document.getElementById('restrictedAreaLabelInput');
    const clearBtn = document.getElementById('restrictedAreaClearBtn');
    const sendBtn = document.getElementById('restrictedAreaSendBtn');

    let composerMap = null;
    let refLayer = null;
    let drawPoints = [];
    let vertexMarkers = [];
    let shapeLayer = null;
    let isClosed = false;
    let actionStack = [];

    function vertexIcon() {
        return L.divIcon({className: '', html: '<div style="width:14px;height:14px;border-radius:50%;background:#fff;border:3px solid #dc3545;box-shadow:0 1px 3px #0006;"></div>', iconSize: [14, 14], iconAnchor: [7, 7]});
    }

    // Dimmed, read-only copy of the live map's pins + existing search areas +
    // existing restricted areas — same technique as the search-area composer's
    // own renderAreaComposerContext(), extended to also show other restricted
    // zones so an admin drawing a new one can see it doesn't overlap another.
    function renderRestrictedAreaComposerContext() {
        if (!refLayer) return;
        refLayer.clearLayers();
        renderFullMapReference(refLayer);
    }

    function resetDrawing() {
        drawPoints = [];
        isClosed = false;
        actionStack = [];
        vertexMarkers.forEach(m => composerMap.removeLayer(m));
        vertexMarkers = [];
        if (shapeLayer) { composerMap.removeLayer(shapeLayer); shapeLayer = null; }
        sendBtn.disabled = true;
    }

    function updateShapePreview() {
        if (shapeLayer) { composerMap.removeLayer(shapeLayer); shapeLayer = null; }
        if (drawPoints.length < 2) return;
        shapeLayer = isClosed
            ? L.polygon(drawPoints, {color:'#dc3545', fillOpacity:0.15}).addTo(composerMap)
            : L.polyline(drawPoints, {color:'#dc3545'}).addTo(composerMap);
    }

    function updateSendState() {
        sendBtn.disabled = !(isClosed && drawPoints.length >= 3);
    }

    function addDrawPoint(lat, lng) {
        const idx = vertexMarkers.length;
        drawPoints.push([lat, lng]);
        const marker = L.marker([lat, lng], {icon: vertexIcon(), draggable: true}).addTo(composerMap);
        marker.on('dragend', () => {
            const ll = marker.getLatLng();
            actionStack.push({type: 'move', index: idx, from: drawPoints[idx].slice()});
            drawPoints[idx] = [ll.lat, ll.lng];
            updateShapePreview();
        });
        vertexMarkers.push(marker);
        actionStack.push({type: 'add'});
        updateShapePreview();
        updateSendState();
    }

    function undo() {
        if (!actionStack.length) return;
        const last = actionStack.pop();
        if (last.type === 'add') {
            const marker = vertexMarkers.pop();
            if (marker) composerMap.removeLayer(marker);
            drawPoints.pop();
        } else if (last.type === 'move') {
            drawPoints[last.index] = last.from;
            vertexMarkers[last.index].setLatLng(last.from);
        } else if (last.type === 'close') {
            isClosed = false;
        }
        updateShapePreview();
        updateSendState();
    }

    function keydownHandler(e) {
        if (!(e.ctrlKey || e.metaKey) || e.key.toLowerCase() !== 'z') return;
        const activeTag = document.activeElement ? document.activeElement.tagName : '';
        if (activeTag === 'INPUT' || activeTag === 'TEXTAREA') return;
        e.preventDefault();
        undo();
    }

    function onMapClick(e) {
        if (isClosed) return;
        if (drawPoints.length >= 3) {
            const firstPoint = composerMap.latLngToContainerPoint(L.latLng(drawPoints[0]));
            const clickPoint = composerMap.latLngToContainerPoint(e.latlng);
            if (firstPoint.distanceTo(clickPoint) < 16) {
                isClosed = true;
                actionStack.push({type: 'close'});
                updateShapePreview();
                updateSendState();
                return;
            }
        }
        addDrawPoint(e.latlng.lat, e.latlng.lng);
    }

    function addCoordsFromInput() {
        const parsed = parseCoordsInput(coordsInput.value);
        if (!parsed) {
            alert(t('dispatch.coords_invalid'));
            return;
        }
        if (isClosed) {
            alert(t('dispatch.coords_shape_closed'));
            return;
        }
        addDrawPoint(parsed.lat, parsed.lng);
        composerMap.setView([parsed.lat, parsed.lng], Math.max(composerMap.getZoom(), 15));
        coordsInput.value = '';
    }
    coordsAddBtn.addEventListener('click', addCoordsFromInput);
    coordsInput.addEventListener('keydown', e => {
        if (e.key === 'Enter') { e.preventDefault(); addCoordsFromInput(); }
    });

    modalEl.addEventListener('shown.bs.modal', () => {
        if (!composerMap) {
            const center = missionLocation.lat ? [missionLocation.lat, missionLocation.lng] : [37.97, 23.73];
            composerMap = L.map('restrictedAreaComposerMap').setView(center, missionLocation.lat ? 13 : 7);
            addMapBaseLayers(composerMap, 'restrictedAreaSatelliteToggle');
            refLayer = L.layerGroup().addTo(composerMap);
            composerMap.on('click', onMapClick);
        }
        renderRestrictedAreaComposerContext();
        document.addEventListener('keydown', keydownHandler);
        setTimeout(() => composerMap.invalidateSize(), 100);
    });

    modalEl.addEventListener('hidden.bs.modal', () => {
        resetDrawing();
        addressInput.value = '';
        addressStatus.textContent = '';
        coordsInput.value = '';
        labelInput.value = '';
        document.removeEventListener('keydown', keydownHandler);
    });

    clearBtn.addEventListener('click', resetDrawing);

    addressSearchBtn.addEventListener('click', () => {
        const q = addressInput.value.trim();
        if (!q) return;
        addressStatus.textContent = t('dispatch.searching');
        fetch('geocode-address.php?q=' + encodeURIComponent(q)).then(response => response.json()).then(result => {
            if (result.ok) {
                composerMap.setView([result.lat, result.lng], 16);
                addressStatus.textContent = '✓ ' + (result.display_name || q);
            } else {
                addressStatus.textContent = result.error || t('dispatch.address_not_found');
            }
        }).catch(() => { addressStatus.textContent = t('dispatch.search_failed'); });
    });

    sendBtn.addEventListener('click', () => {
        const label = labelInput.value.trim();
        if (!label) {
            alert(t('sector.invalid_label'));
            return;
        }
        const data = new URLSearchParams({
            csrf_token: csrfToken, action: 'create', mission_id: <?= $missionId ?>,
            geo: JSON.stringify(drawPoints), label: label,
        });
        sendBtn.disabled = true;
        fetch('mission-restricted-area.php', {method:'POST', body:data}).then(response => response.json()).then(result => {
            if (result.ok) {
                bootstrap.Modal.getInstance(modalEl).hide();
                restrictedAreas = result.areas;
                renderRestrictedAreaLayer(restrictedAreas);
                renderRestrictedAreasList(restrictedAreas);
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

// Same whole-array-JSON signature technique as renderMyRoutes — a route
// member's waypoint popup can now hold the exact same live note textarea the
// card does (see below), so skipping the rebuild on an unchanged poll tick
// protects in-progress typing here too, not just in the card.
let routeLayerRenderedSig = null;
function renderRouteLayer(allRoutes) {
    if (!routeLayer) return;
    const sig = JSON.stringify(allRoutes);
    if (sig === routeLayerRenderedSig) return;
    routeLayerRenderedSig = sig;

    // A poll tick (or a teammate's own action elsewhere, since routes are
    // shared state) can land while this viewer has a waypoint popup open —
    // clearLayers() below would otherwise silently kill it and any button
    // inside mid-tap, same problem renderDispatches() already solved for
    // dispatch pins. Remember which waypoint was open and reopen its
    // freshly-rendered marker afterward.
    let openWaypointId = null;
    routeLayer.eachLayer(layer => {
        if (layer.waypointId !== undefined && layer.isPopupOpen && layer.isPopupOpen()) {
            openWaypointId = layer.waypointId;
        }
    });

    routeLayer.clearLayers();
    let reopenLayer = null;
    // Live map only — a completed route (like a cancelled one) drops off
    // here once done, so numbered waypoint pins/lines from finished patrols
    // don't accumulate over a long multi-team mission. Nothing is lost: the
    // full permanent record stays exactly as before in "Πορείες Ομάδων",
    // each volunteer's own "Η Πορεία μου" card, the Activity feed, and the
    // printed report — this is a live-map declutter, not a data change.
    (allRoutes || []).filter(r => r.status === 'active').forEach(route => {
        if (!route.waypoints.length) return;
        const currentSeq = (route.waypoints.find(w => !w.completed_at && !w.skipped_at) || {}).seq;
        route.waypoints.forEach(wp => {
            const color = routeWaypointColor(wp, wp.seq === currentSeq);
            const icon = L.divIcon({
                className: '',
                html: `<div style="background:${color};color:#fff;width:24px;height:24px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:12px;border:2px solid #fff;box-shadow:0 1px 4px #0008;">${wp.seq}</div>`,
                iconSize: [24, 24], iconAnchor: [12, 12],
            });
            let popupHtml;
            // Route member (one of their own waypoints): the popup becomes a
            // full "Η Πορεία μου" card entry for this stop — same ack gate,
            // same depart/arrive/deliverables/complete buttons, reusing the
            // exact same render functions and click handlers as the card —
            // so every action is reachable right from the pin and nobody has
            // to scroll down to the card to act. Command staff / non-members
            // keep today's plain read-only popup.
            if (route.is_route_member) {
                const teamPrefix = route.team_label ? `<div class="small text-muted mb-1">${escapeHtml(route.team_label)}</div>` : '';
                if (!route.my_acknowledged_at) {
                    const label = wp.label ? escapeHtml(wp.label) : t('route.waypoint_fallback_label', {seq: wp.seq});
                    popupHtml = teamPrefix + `<strong class="small">${wp.seq}. ${label}</strong><br>` +
                        `<button type="button" class="btn btn-sm wr-touch-btn btn-warning w-100 mt-1 route-ack-btn" data-id="${route.id}" data-order-id="${route.order_id}"><i class="bi bi-check2 me-1"></i>${t('banner.ack_btn')}</button>`;
                } else if (wp.completed_at || wp.skipped_at) {
                    popupHtml = teamPrefix + renderRouteWaypointClosed(wp);
                } else if (wp.seq === currentSeq) {
                    popupHtml = teamPrefix + renderRouteWaypointCurrent(wp);
                } else {
                    popupHtml = teamPrefix + renderRouteWaypointUpcoming(wp);
                }
            } else {
                const statusText = wp.skipped_at ? t('route.skipped_prefix')
                    : wp.completed_at ? t('route.completed_at_prefix', {time: wp.completed_at_display})
                    : wp.arrived_at ? t('route.onsite_since_prefix', {time: wp.arrived_at_display})
                    : wp.departed_at ? t('route.enroute_since_prefix', {time: wp.departed_at_display})
                    : '';
                const label = wp.label ? escapeHtml(wp.label) : t('route.waypoint_fallback_label', {seq: wp.seq});
                popupHtml = `<strong>${escapeHtml(route.team_label || '')} — ${wp.seq}. ${label}</strong>` +
                    (wp.instructions ? `<br><span class="small">${escapeHtml(wp.instructions)}</span>` : '') +
                    (statusText ? `<br><span class="small text-muted">${statusText}</span>` : '') +
                    `<br><a href="${routeWaypointDirectionsUrl(wp)}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-success mt-1"><i class="bi bi-signpost-2-fill me-1"></i>${t('dispatch.directions_btn')}</a>`;
            }
            const marker = L.marker([wp.lat, wp.lng], {icon}).addTo(routeLayer).bindPopup(popupHtml, {minWidth: 220});
            marker.waypointId = wp.id;
            if (String(wp.id) === String(openWaypointId)) reopenLayer = marker;
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
    if (reopenLayer) reopenLayer.openPopup();
}

// Delegated the same way dispatchLayer's popup buttons are (see that block
// above): rebuilt into a fresh DOM node every render, so listeners are wired
// on 'popupopen' rather than at creation time. Route-member-only actions —
// admin/command-staff popups render no buttons for these selectors to match
// (see renderRouteLayer above), so this handler simply finds nothing and
// does nothing on their popups.
if (!fieldMode) {
routeLayer.on('popupopen', event => {
    const popupEl = event.popup.getElement();
    const ackBtn = popupEl.querySelector('.route-ack-btn');
    if (ackBtn) ackBtn.addEventListener('click', () => routeAcknowledge(ackBtn.dataset.id, ackBtn.dataset.orderId, ackBtn));
    const departBtn = popupEl.querySelector('.route-depart-btn');
    if (departBtn) departBtn.addEventListener('click', () => { departBtn.disabled = true; routeDepart(departBtn.dataset.id, false); });
    const arriveBtn = popupEl.querySelector('.route-arrive-btn');
    if (arriveBtn) arriveBtn.addEventListener('click', () => { arriveBtn.disabled = true; routeArrive(arriveBtn.dataset.id, false); });
    const completeBtn = popupEl.querySelector('.route-complete-btn');
    if (completeBtn) completeBtn.addEventListener('click', () => { completeBtn.disabled = true; routeComplete(completeBtn.dataset.id, false, popupEl); });
    const jumpBtn = popupEl.querySelector('.route-jump-btn');
    if (jumpBtn) jumpBtn.addEventListener('click', () => { if (confirm(t('route.confirm_out_of_sequence'))) routeDepart(jumpBtn.dataset.id, true); });
    popupEl.querySelectorAll('.route-media-btn').forEach(btn => btn.addEventListener('click', () => {
        const statusEl = btn.closest('.d-flex').nextElementSibling;
        triggerWaypointUpload(btn.dataset.id, btn.dataset.mediaType, statusEl);
    }));
});
}

// ── Route Order admin sidebar list (every team's routes, cancel/skip) ───────
// Which routes are expanded in the admin panel — module-level so the 5s poll's
// renderRoutesAdmin() re-render doesn't collapse a route the admin just opened
// (same class of "poll wipes UI state" bug fixed elsewhere in this file for the
// shortage-report textarea; sidestepped here at the root instead of a
// signature-skip guard, since collapse/expand state has to survive anyway).
let expandedRouteIds = new Set();
// Cancelled routes stay in the list (this is the mission's permanent record,
// same reasoning as everywhere else in the app — see renderRouteLayer()'s own
// comment on why the live *map* drops them but nothing else does) but a
// long-running mission can accumulate several, burying the routes someone
// actually cares about below a wall of "Ακυρώθηκε" cards. Collapsed by
// default behind a single toggle rather than deleted.
let showCancelledRoutes = false;

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
                ${wp.video ? `<video src="mission-photo-view.php?id=${wp.video.id}" class="route-media-view" data-id="${wp.video.id}" data-media-type="video" style="max-height:50px;border-radius:4px;cursor:pointer;" muted${wp.video.has_thumb ? ` poster="mission-photo-view.php?id=${wp.video.id}&thumb=1"` : ''}></video>` : ''}
              </div>`
            : '';
        const noteHtml = wp.note ? `<div class="small fst-italic mt-1">"${escapeHtml(wp.note)}"</div>` : '';
        return `<div class="py-1 border-bottom">
            <div class="d-flex justify-content-between align-items-center">
                <div class="small">${wp.seq}. ${label}${wp.out_of_sequence ? ' ⚠️' : ''}<br>${routeAdminWaypointStatusHtml(wp)}</div>
                <div class="d-flex gap-1">
                    <a href="${routeWaypointDirectionsUrl(wp)}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-success py-0 px-1" title="${t('dispatch.directions_btn')}"><i class="bi bi-signpost-2-fill"></i></a>
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
    const sig = JSON.stringify(allRoutes) + '|' + [...expandedRouteIds].sort().join(',') + '|' + showCancelledRoutes;
    if (sig === routesAdminRenderedSig) return;
    routesAdminRenderedSig = sig;

    const list = document.getElementById('routesAdminList');
    if (!list) return;
    if (!allRoutes.length) {
        list.innerHTML = '<p class="text-muted mb-0">' + t('route.admin_empty') + '</p>';
        return;
    }
    const activeRoutes = allRoutes.filter(r => r.status !== 'cancelled');
    const cancelledRoutes = allRoutes.filter(r => r.status === 'cancelled');
    const renderRouteCard = route => {
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
    };

    let html = activeRoutes.map(renderRouteCard).join('');
    if (cancelledRoutes.length) {
        html += `<div class="text-muted small p-2 d-flex align-items-center gap-1 route-cancelled-toggle" style="cursor:pointer;">
            <i class="bi bi-chevron-${showCancelledRoutes ? 'up' : 'down'}"></i>${t('route.cancelled_toggle', {count: cancelledRoutes.length})}
        </div>`;
        if (showCancelledRoutes) html += cancelledRoutes.map(renderRouteCard).join('');
    }
    list.innerHTML = html;

    list.querySelectorAll('.route-admin-toggle').forEach(el => el.addEventListener('click', () => {
        const id = +el.dataset.id;
        if (expandedRouteIds.has(id)) expandedRouteIds.delete(id); else expandedRouteIds.add(id);
        renderRoutesAdmin(allRoutes);
    }));
    const cancelledToggleEl = list.querySelector('.route-cancelled-toggle');
    if (cancelledToggleEl) cancelledToggleEl.addEventListener('click', () => {
        showCancelledRoutes = !showCancelledRoutes;
        renderRoutesAdmin(allRoutes);
    });
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
let routeComposerPinLayer = null, routeComposerAnnotationLayer = null, routeComposerAreasLayer = null;
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
    // interactive=false — this map is read-only reference info with no
    // popupopen listener wired up, so the charge-alert button would render
    // but silently do nothing if left enabled here.
    items.forEach(pin => buildPinMarker(pin, false).addTo(routeComposerPinLayer));
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

// Fills in the rest of renderFullMapReference()'s coverage for the route
// composer specifically — pins and annotations already have their own
// dedicated, longer-standing layers/functions above (kept as-is, not
// replaced), this just adds the pieces those two don't cover: search areas,
// sectors, restricted areas, dispatches. Called from the same two places
// (pollWarRoomData() + the initial full-page render) the pin/annotation
// pair already are, so it stays live even while the modal is closed.
function renderRouteComposerAreas() {
    if (!routeComposerAreasLayer) return;
    routeComposerAreasLayer.clearLayers();
    renderSearchAreasReference(routeComposerAreasLayer);
    renderSectorsReference(routeComposerAreasLayer);
    renderRestrictedAreasReference(routeComposerAreasLayer);
    renderDispatchesReference(routeComposerAreasLayer);
    renderMissingPersonReference(routeComposerAreasLayer);
    renderSearchRingsReference(routeComposerAreasLayer);
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
    const coordsInput = document.getElementById('routeCoordsInput');
    const coordsAddBtn = document.getElementById('routeCoordsAddBtn');
    const clearBtn = document.getElementById('routeClearBtn');
    const sendBtn = document.getElementById('routeSendBtn');
    const teamSelect = document.getElementById('routeTeamSelect');
    const memberPicker = document.getElementById('routeMemberPicker');
    // routeTeamSelect/routeMemberPicker live in routeOrderCard — the SIDEBAR
    // card, not this modal's own HTML — and that whole card only renders
    // inside a server-side "teams non-empty" guard (war-room.php ~2742). On
    // a zero-team mission neither element exists at all.
    //
    // Used to be a hard `if (!teamSelect) return;` right here, which — since
    // this is the top of the IIFE — meant shown.bs.modal never got attached
    // and this entire composer (map, waypoint drawing, everything) silently
    // did nothing, no error, no explanation. That was never hit in practice
    // while the ONLY way to open this modal was routeOrderCard's own "New
    // Route" button, itself behind the identical guard — but a second entry
    // point now exists (a ring's "sweep this ring" button, openRouteForRing()
    // near openDivideSectorsForArea()) that can open this modal on a mission
    // with a missing-person profile but no teams yet, a genuinely plausible
    // early-incident state.
    //
    // Fix: let the team-independent parts of the composer (the map, drawing/
    // pre-filling waypoints, the title/address/coords fields) work regardless
    // — there's real value in being able to plan a route's shape before a
    // team exists to send it to. Only the parts that genuinely can't mean
    // anything without a team (renderRouteMemberPicker, and send itself) are
    // individually guarded below, each degrading to the same clear
    // "no teams yet" message rather than a silent no-op or a thrown error.

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
    if (teamSelect) {
        renderRouteMemberPicker();
        teamSelect.addEventListener('change', renderRouteMemberPicker);
    }

    // Shared by real map clicks and the manual coordinates field below — same
    // reasoning as the dispatch composer's addDrawPoint().
    function addRouteWaypoint(lat, lng) {
        routeWaypoints.push({lat, lng, label: '', instructions: '', dwell_minutes: 10, require_photo: false, require_video: false, require_note: false});
        renderRouteComposerMap();
        renderWaypointPanel();
        updateRouteSendState();
    }

    // Same rationale as the dispatch composer's addCoordsFromInput() — exact
    // coordinates handed over verbally/in writing, no need to find the right
    // spot on the map first. Always just adds a waypoint, even past 3 points;
    // the "click near point 1" closed-loop gesture below is a map-click-only
    // affordance.
    function addCoordsFromInput() {
        const parsed = parseCoordsInput(coordsInput.value);
        if (!parsed) {
            alert(t('dispatch.coords_invalid'));
            return;
        }
        if (routeClosed) {
            alert(t('dispatch.coords_shape_closed'));
            return;
        }
        addRouteWaypoint(parsed.lat, parsed.lng);
        routeMap.setView([parsed.lat, parsed.lng], Math.max(routeMap.getZoom(), 15));
        coordsInput.value = '';
    }
    coordsAddBtn.addEventListener('click', addCoordsFromInput);
    coordsInput.addEventListener('keydown', e => {
        if (e.key === 'Enter') { e.preventDefault(); addCoordsFromInput(); }
    });

    // routeMap/routeWaypoints and the render/reset functions are module-level
    // (declared above renderWaypointPanel) rather than local to this IIFE,
    // since renderWaypointPanel's own per-waypoint "remove" button needs to
    // call back into the map redraw — one shared state, whichever caller
    // touches it first.
    modalEl.addEventListener('shown.bs.modal', () => {
        if (!routeMap) {
            const center = missionLocation.lat ? [missionLocation.lat, missionLocation.lng] : [37.97, 23.73];
            routeMap = L.map('routeMap').setView(center, missionLocation.lat ? 13 : 7);
            addMapBaseLayers(routeMap, 'routeSatelliteToggle');
            // Added before any waypoint markers exist, so once the admin
            // starts clicking points those numbered markers land on top of
            // (not under) the reference pins/annotations, matching Leaflet's
            // added-later-renders-on-top default — no explicit pane needed.
            routeComposerAreasLayer = L.layerGroup().addTo(routeMap);
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
                addRouteWaypoint(e.latlng.lat, e.latlng.lng);
            });
        }
        // Refreshed on every open, not just the first — pins/annotations may
        // well have changed since the last time this admin opened the
        // composer, even though the underlying data itself has stayed live
        // (polled) the whole time in the background.
        renderRouteComposerPins(pins);
        renderRouteComposerAnnotations(annotations);
        renderRouteComposerAreas();
        // Pre-fill from a ring's "sweep this ring" button (openRouteForRing()
        // in the !fieldMode block) — same one-shot consume-then-null pattern
        // as the dispatch composer's own pendingDispatchSeed handling above.
        // addRouteWaypoint() alone never sets routeClosed (that only happens
        // via the "click near point 1" gesture above), so it must be set
        // explicitly here too, or the send payload's is_closed_loop stays '0'
        // and the map draws an open line instead of the intended loop.
        if (pendingRouteSeed) {
            pendingRouteSeed.points.forEach(pt => addRouteWaypoint(pt[0], pt[1]));
            routeClosed = true;
            renderRouteComposerMap();
            renderWaypointPanel();
            titleInput.value = pendingRouteSeed.label;
            if (routeWaypoints.length) routeMap.fitBounds(L.latLngBounds(routeWaypoints.map(wp => [wp.lat, wp.lng])), {padding: [30, 30]});
            pendingRouteSeed = null;
        }
        setTimeout(() => routeMap.invalidateSize(), 100);
    });

    modalEl.addEventListener('hidden.bs.modal', () => {
        resetRouteComposer();
        titleInput.value = '';
        addressInput.value = '';
        addressStatus.textContent = '';
        coordsInput.value = '';
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
        // A route fundamentally has nowhere to go without a team to pick
        // recipients from — same "no teams yet" message openDispatchForRing's
        // sibling used to show up-front before this fix, now surfaced here
        // instead so the map/waypoint-planning part of the composer stays
        // usable even before a team exists (see the top-of-IIFE comment).
        if (!teamSelect) { alert(t('briefing.no_teams_yet')); return; }
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

document.querySelectorAll('.wr-briefing-copy-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        navigator.clipboard.writeText(btn.dataset.copyText || '').then(() => {
            const icon = btn.querySelector('i');
            const prevClass = icon.className;
            icon.className = 'bi bi-check-lg';
            btn.classList.add('btn-success');
            btn.classList.remove('btn-outline-secondary');
            setTimeout(() => {
                icon.className = prevClass;
                btn.classList.remove('btn-success');
                btn.classList.add('btn-outline-secondary');
            }, 1500);
        });
    });
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
