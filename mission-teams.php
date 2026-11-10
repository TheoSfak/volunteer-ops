<?php
/**
 * VolunteerOps - Mission Teams Endpoint (v3.463.0)
 * The «Ομάδες» page (mission-teams-view.php): GET answers with the teams of the mission and everybody approved on it;
 * POST makes teams (by hand, or the automatic split by a number of people per team), edits a team (its name, its
 * leader, how it moves), deletes it, moves one person between teams, and switches GPS on and off. Everything it
 * does is in includes/functions-mission-teams.php. COMMAND ONLY, for GET as for POST. AJAX only.
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();

header('Content-Type: application/json');

$userId = (int) getCurrentUserId();

$missionId = (int) (isPost() ? post('mission_id') : get('mission_id'));

$mission = dbFetchOne(
    "SELECT id, title, status, show_in_ops, responsible_user_id FROM missions WHERE id = ? AND deleted_at IS NULL",
    [$missionId]
);
if (!$mission || $mission['status'] !== STATUS_OPEN || empty($mission['show_in_ops'])) {
    echo json_encode(['ok' => false, 'error' => t('common.mission_not_found_or_inactive')]);
    exit;
}

if (!canManageActionRoom($mission['responsible_user_id'] ? (int) $mission['responsible_user_id'] : null, $userId)) {
    echo json_encode(['ok' => false, 'error' => t('teamsp.no_permission')]);
    exit;
}

// ── GET: the teams ─────────────────────────────────────────────────────────
if (!isPost()) {
    echo json_encode(['ok' => true, 'state' => missionTeamsPageState($missionId)]);
    exit;
}

// ── POST ───────────────────────────────────────────────────────────────────
if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string) $_POST['csrf_token'])) {
    echo json_encode(['ok' => false, 'error' => t('common.invalid_request')]);
    exit;
}

$action = (string) post('action');
// Every answer carries the teams as they are now, a refusal included: the page then shows what is true instead of
// what it guessed, which is what makes two command posts editing at once safe.
$answer = fn(array $extra = []) => json_encode(['ok' => true, 'state' => missionTeamsPageState($missionId)] + $extra);
$fail = fn(string $error) => json_encode(['ok' => false, 'error' => $error, 'state' => missionTeamsPageState($missionId)]);
$ids = fn(string $field) => array_values(array_filter(array_map('intval', (array) ($_POST[$field] ?? []))));

if ($action === 'auto_preview') {
    $size = (int) post('size');
    if ($size < 1 || $size > MISSION_TEAM_AUTO_MAX_SIZE) {
        echo $fail(t('teamsp.auto_size_invalid', ['max' => MISSION_TEAM_AUTO_MAX_SIZE]));
        exit;
    }
    echo $answer(['plan' => missionTeamsAutoPreview($missionId, $size, post('redo') === '1')]);
    exit;
}

if ($action === 'auto_create') {
    $result = autoCreateMissionTeams($mission, $userId, (int) post('size'), post('redo') === '1');
    echo $result['ok']
        ? $answer(['message' => t('teamsp.auto_done', ['teams' => count($result['teams']), 'people' => $result['people']])])
        : $fail($result['error']);
    exit;
}

if ($action === 'create_team') {
    // GPS is not part of making a team on this page: nobody's switch is touched.
    $result = createMissionTeam($mission, $userId, [
        'member_ids' => $ids('member_ids'),
        'leader_id'  => (int) post('leader_id'),
        'name'       => (string) post('name'),
        'transport'  => post('transport'),
    ]);
    echo $result['ok'] ? $answer(['message' => t('team.create.success_flash', ['team' => $result['label']])]) : $fail($result['error']);
    exit;
}

if ($action === 'update_team') {
    // The «Επεξεργασία» of a team: its name, its leader and how it moves, checked together and saved together.
    $teamId = (int) post('team_id');
    $error = updateMissionTeam($mission, $teamId, [
        'name'      => (string) post('name'),
        'leader_id' => (int) post('leader_id'),
        'transport' => (string) post('transport'),
    ]);
    if ($error !== null) {
        echo $fail($error);
        exit;
    }
    $saved = dbFetchOne("SELECT codename, team_number FROM mission_teams WHERE id = ? AND mission_id = ?", [$teamId, $missionId]);
    echo $answer(['message' => t('teamsp.edit_saved', ['team' => teamLabel($saved['codename'], $saved['team_number'])])]);
    exit;
}

if ($action === 'set_leader') {
    $error = setMissionTeamLeader($mission, (int) post('team_id'), (int) post('user_id'));
    echo $error === null ? $answer() : $fail($error);
    exit;
}

if ($action === 'move_member') {
    $toTeamId = (int) post('to_team_id');
    $error = moveMissionTeamMember($mission, (int) post('user_id'), $toTeamId > 0 ? $toTeamId : null, $userId);
    echo $error === null ? $answer() : $fail($error);
    exit;
}

if ($action === 'delete_team') {
    $label = deleteMissionTeam($mission, (int) post('team_id'));
    echo $label !== null ? $answer(['message' => t('team.delete.success_flash', ['team' => $label])]) : $fail(t('common.team_not_found'));
    exit;
}

if ($action === 'set_gps') {
    // The state that is WANTED, never «flip it»: two taps racing from a slow connection then agree on an answer.
    // One request carries both lists, so «μόνο ο υπεύθυνος» is one press and one answer, not two that could cross.
    $onIds = $ids('on_ids');
    $changed = setMissionTeamsGps($mission, $onIds, true, $userId)
             + setMissionTeamsGps($mission, array_diff($ids('off_ids'), $onIds), false, $userId);
    echo $answer(['changed' => $changed]);
    exit;
}

echo $fail(t('common.invalid_request'));
