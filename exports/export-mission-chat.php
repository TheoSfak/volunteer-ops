<?php
/**
 * VolunteerOps - Mission Chat CSV export, one room at a time
 * No zip/multi-sheet bundling (deliberate — this environment has no zip PHP
 * extension enabled, and adding a real multi-sheet Excel library would be
 * this app's first-ever Composer dependency; user chose separate per-room
 * downloads over either of those). Visibility mirrors war-room.php's own
 * $chatTeams scoping exactly: command staff can export any team's room,
 * a regular participant only the general room and their own team's room.
 */

require_once __DIR__ . '/../bootstrap.php';
requireLogin();

$missionId = (int) get('mission_id');
$teamIdParam = get('team_id', '');
$teamId = $teamIdParam === '' ? null : (int) $teamIdParam;

if (!$missionId) {
    setFlash('error', t('common.mission_not_found'));
    redirect('dashboard.php');
}

$user = getCurrentUser();
$mission = dbFetchOne("SELECT * FROM missions WHERE id = ? AND deleted_at IS NULL", [$missionId]);
if (!$mission) {
    setFlash('error', t('common.mission_not_found'));
    redirect('dashboard.php');
}

$canManageWarRoom = canManageActionRoom($mission['responsible_user_id'] ? (int) $mission['responsible_user_id'] : null, (int) $user['id']);
$isApprovedParticipant = (bool) dbFetchValue(
    "SELECT COUNT(*) FROM participation_requests pr
     JOIN shifts s ON s.id = pr.shift_id
     WHERE s.mission_id = ? AND pr.volunteer_id = ? AND pr.status = ?",
    [$missionId, $user['id'], PARTICIPATION_APPROVED]
);
if (!$canManageWarRoom && !$isApprovedParticipant) {
    setFlash('error', t('wr.access_denied'));
    redirect('dashboard.php');
}

$roomLabel = t('chat.general_room');
if ($teamId !== null) {
    $team = dbFetchOne("SELECT id, codename, team_number FROM mission_teams WHERE id = ? AND mission_id = ?", [$teamId, $missionId]);
    if (!$team) {
        setFlash('error', t('common.mission_not_found'));
        redirect('war-room.php?id=' . $missionId);
    }
    // Same scoping war-room.php itself applies to $chatTeams: a non-admin
    // may only export their OWN team's room, never another team's.
    if (!$canManageWarRoom) {
        $isMyTeam = (bool) dbFetchValue(
            "SELECT COUNT(*) FROM mission_team_members WHERE team_id = ? AND user_id = ?",
            [$teamId, $user['id']]
        );
        if (!$isMyTeam) {
            setFlash('error', t('wr.access_denied'));
            redirect('war-room.php?id=' . $missionId);
        }
    }
    $roomLabel = $team['codename'] . ' ' . $team['team_number'];
}

$messages = dbFetchAll(
    "SELECT cm.created_at, cm.message, u.name AS sender_name
     FROM mission_chat_messages cm
     JOIN users u ON u.id = cm.user_id
     WHERE cm.mission_id = ? AND cm.team_id " . ($teamId === null ? 'IS NULL' : '= ?') . "
     ORDER BY cm.id ASC",
    $teamId === null ? [$missionId] : [$missionId, $teamId]
);

if (ob_get_level()) ob_end_clean();

$dateStr = date('Y-m-d');
$asciiSlug = preg_replace('/[^A-Za-z0-9]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $mission['title'] . '_' . $roomLabel));
$asciiSlug = trim($asciiSlug, '_') ?: 'mission';
$fallbackName = 'chat_' . $asciiSlug . '_' . $dateStr . '.csv';
$utf8Name = 'Chat_' . $mission['title'] . '_' . $roomLabel . '_' . $dateStr . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $fallbackName . '"; filename*=UTF-8\'\'' . rawurlencode($utf8Name));

$out = fopen('php://output', 'w');
fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

fputcsv($out, ['Ημερομηνία/Ώρα', 'Αποστολέας', 'Μήνυμα']);
foreach ($messages as $msg) {
    fputcsv($out, [
        date('d/m/Y H:i:s', strtotime($msg['created_at'])),
        $msg['sender_name'],
        $msg['message'],
    ]);
}

fclose($out);
exit;
