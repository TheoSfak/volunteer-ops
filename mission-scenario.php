<?php
/**
 * VolunteerOps - Mission Scenario Endpoint (v3.452.0)
 * Action Room: command writes orders down in phases before an exercise (GET lists the plan; POST adds and
 * removes phases and orders) and presses play on a phase to send it. The plan itself is in
 * includes/functions-scenario.php. COMMAND ONLY: anyone else - a volunteer, a participant, a shift leader -
 * gets an error and no data, for GET as for POST, because a staged order must never reach the field before it
 * is sent. AJAX only.
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
    echo json_encode(['ok' => false, 'error' => t('common.mission_not_found_or_inactive')]);
    exit;
}

$canManageWarRoom = canManageActionRoom($mission['responsible_user_id'] ? (int) $mission['responsible_user_id'] : null, (int) $userId);
if (!$canManageWarRoom) {
    echo json_encode(['ok' => false, 'error' => t('scenario.no_permission')]);
    exit;
}

// ── GET: the plan ──────────────────────────────────────────────────────────
if (!isPost()) {
    echo json_encode(['ok' => true, 'scenario' => loadMissionScenario($missionId)]);
    exit;
}

// ── POST ───────────────────────────────────────────────────────────────────
if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string) $_POST['csrf_token'])) {
    echo json_encode(['ok' => false, 'error' => t('common.invalid_request')]);
    exit;
}

$action = post('action');
$answer = fn(array $extra = []) => json_encode(['ok' => true, 'scenario' => loadMissionScenario($missionId)] + $extra);
$fail = fn(string $error, array $extra = []) => json_encode(['ok' => false, 'error' => $error, 'scenario' => loadMissionScenario($missionId)] + $extra);
// The sectors a play or a retry has just made are live now: hand back the live list (the same pair the sector
// endpoint answers with) so the map shows them at once, not at the next poll. Only when one was made.
$liveSectors = fn(array $results) => array_filter($results, fn($r) => ($r['kind'] ?? '') === 'sector' && !empty($r['ok']))
    ? ['sectors' => loadMissionSectorsForUser($missionId, (int) $userId, true, false), 'areas' => loadMissionSearchAreasForUser($missionId, true)]
    : [];
// Likewise a play or a retry that has sent routes: the live list of routes, the one the route endpoint answers with.
$liveRoutes = fn(array $results) => array_filter($results, fn($r) => ($r['kind'] ?? '') === 'route' && !empty($r['ok']))
    ? ['routes' => loadRoutesForUser($missionId, (int) $userId, true)]
    : [];

if ($action === 'add_phase') {
    $result = addScenarioPhase($mission, (int) $userId, (string) post('title'));
    echo $result['ok'] ? $answer(['phase_id' => $result['id']]) : $fail($result['error']);
    exit;
}

if ($action === 'rename_phase') {
    $error = renameScenarioPhase($mission, (int) post('phase_id'), (string) post('title'), (int) $userId);
    echo $error === null ? $answer() : $fail($error);
    exit;
}

if ($action === 'delete_phase') {
    $error = deleteScenarioPhase($mission, (int) post('phase_id'), (int) $userId);
    echo $error === null ? $answer() : $fail($error);
    exit;
}

if ($action === 'add_item') {
    // "New phase" from the order window: the phase is made and the order put in it in one step. If the order
    // is refused the new, empty phase is taken out again so a bad press leaves nothing behind.
    $phaseId = (int) post('phase_id');
    $madePhase = false;
    if ($phaseId === 0 && post('phase_id') === 'new') {
        $made = addScenarioPhase($mission, (int) $userId, '');
        if (!$made['ok']) {
            echo $fail($made['error']);
            exit;
        }
        $phaseId = (int) $made['id'];
        $madePhase = true;
    }
    $result = addScenarioDispatchItem($mission, $phaseId, (int) $userId, [
        'team_id'     => post('team_id'),
        'type'        => post('type'),
        'label'       => post('label'),
        'geo'         => post('geo'),
        'travel_mode' => post('travel_mode'),
    ]);
    if (!$result['ok']) {
        if ($madePhase) {
            deleteScenarioPhase($mission, $phaseId, (int) $userId);
        }
        echo $fail($result['error']);
        exit;
    }
    echo $answer(['item_id' => $result['id'], 'phase_id' => $phaseId]);
    exit;
}

if ($action === 'add_sectors') {
    // The sector window's «Στο σενάριο»: every piece of one division of an existing search area goes into the
    // phase together or not at all. sectors = JSON [{team_id, label, geo}, ...]; team_id is a team's id or
    // 'slot:<id>' - a sector has no «όλες οι ομάδες», and an empty team is refused. As for add_item, a "new
    // phase" made for a refused order is taken out again.
    $phaseId = (int) post('phase_id');
    $madePhase = false;
    if ($phaseId === 0 && post('phase_id') === 'new') {
        $made = addScenarioPhase($mission, (int) $userId, '');
        if (!$made['ok']) {
            echo $fail($made['error']);
            exit;
        }
        $phaseId = (int) $made['id'];
        $madePhase = true;
    }
    $sectors = json_decode((string) post('sectors'), true);
    $result = is_array($sectors)
        ? addScenarioSectorItems($mission, $phaseId, (int) $userId, (int) post('area_id'), $sectors)
        : ['ok' => false, 'error' => t('common.invalid_request')];
    if (!$result['ok']) {
        if ($madePhase) {
            deleteScenarioPhase($mission, $phaseId, (int) $userId);
        }
        echo $fail($result['error']);
        exit;
    }
    echo $answer(['count' => $result['count'], 'phase_id' => $phaseId]);
    exit;
}

if ($action === 'add_route') {
    // The route window's «Στο σενάριο»: the route drawn there goes into the phase instead of out to a team.
    // team_id is a team's id or 'slot:<id>' - a route has no «όλες οι ομάδες», and an empty team (which the route
    // window means as "people picked across teams") is refused. As for add_item, a "new phase" made for a refused
    // order is taken out again.
    $phaseId = (int) post('phase_id');
    $madePhase = false;
    if ($phaseId === 0 && post('phase_id') === 'new') {
        $made = addScenarioPhase($mission, (int) $userId, '');
        if (!$made['ok']) {
            echo $fail($made['error']);
            exit;
        }
        $phaseId = (int) $made['id'];
        $madePhase = true;
    }
    $result = addScenarioRouteItem($mission, $phaseId, (int) $userId, [
        'team_id'             => post('team_id'),
        'title'               => post('title'),
        'waypoints'           => post('waypoints'),
        'is_closed_loop'      => post('is_closed_loop'),
        'add_return_waypoint' => post('add_return_waypoint'),
        'member_ids'          => post('member_ids'),
        'travel_mode'         => post('travel_mode'),
    ]);
    if (!$result['ok']) {
        if ($madePhase) {
            deleteScenarioPhase($mission, $phaseId, (int) $userId);
        }
        echo $fail($result['error']);
        exit;
    }
    echo $answer(['item_id' => $result['id'], 'phase_id' => $phaseId]);
    exit;
}

if ($action === 'delete_item') {
    $error = deleteScenarioItem($mission, (int) post('item_id'), (int) $userId);
    echo $error === null ? $answer() : $fail($error);
    exit;
}

// Slots: the names a scenario gives to teams that may not exist yet, and which real team each stands for.
if ($action === 'add_slot') {
    $result = addScenarioSlot($mission, (int) $userId, (string) post('label'));
    echo $result['ok'] ? $answer(['slot_id' => $result['id']]) : $fail($result['error']);
    exit;
}

if ($action === 'map_slot') {
    $teamRaw = trim((string) post('team_id'));
    // '' = the slot stands for no team; anything else must be a real team's id (checked in mapScenarioSlot()).
    $error = mapScenarioSlot($mission, (int) post('slot_id'), $teamRaw === '' ? null : (int) $teamRaw, (int) $userId);
    echo $error === null ? $answer() : $fail($error);
    exit;
}

if ($action === 'delete_slot') {
    $error = deleteScenarioSlot($mission, (int) post('slot_id'), (int) $userId);
    echo $error === null ? $answer() : $fail($error);
    exit;
}

if ($action === 'play_phase') {
    // mapping[<slot id>] = <team id>: the answers of the dialog that play opens for slots with no team yet.
    $mapping = [];
    if (isset($_POST['mapping']) && is_array($_POST['mapping'])) {
        foreach ($_POST['mapping'] as $slotId => $teamId) {
            if (!is_array($teamId)) {
                $mapping[(int) $slotId] = (int) $teamId;
            }
        }
    }
    $result = playScenarioPhase($mission, (int) post('phase_id'), (int) $userId, (string) $user['name'], $mapping);
    if (!$result['ok']) {
        echo $fail($result['error'], isset($result['unmapped']) ? ['unmapped' => $result['unmapped']] : []);
        exit;
    }
    // The orders are live now: hand back the live list so the map shows them at once, not at the next poll.
    echo $answer([
        'sent'       => $result['sent'],
        'failed'     => $result['failed'],
        'results'    => $result['results'],
        'dispatches' => loadMissionDispatchesForUser($missionId, (int) $userId, true, false),
    ] + $liveSectors($result['results']) + $liveRoutes($result['results']));
    exit;
}

if ($action === 'withdraw_phase') {
    // «Ανάκληση φάσης» (v3.456.0): takes back what a played phase sent and is still under way, by the same steps a
    // coordinator takes by hand. The live lists come back so the map drops the withdrawn orders at once.
    $result = withdrawScenarioPhase($mission, (int) post('phase_id'), (int) $userId);
    if (!$result['ok']) {
        echo $fail($result['error']);
        exit;
    }
    echo $answer([
        'withdrawn'  => $result['withdrawn'],
        'failed'     => $result['failed'],
        'results'    => $result['results'],
        'dispatches' => loadMissionDispatchesForUser($missionId, (int) $userId, true, false),
        'routes'     => loadRoutesForUser($missionId, (int) $userId, true),
        'sectors'    => loadMissionSectorsForUser($missionId, (int) $userId, true, false),
        'areas'      => loadMissionSearchAreasForUser($missionId, true),
    ]);
    exit;
}

if ($action === 'retry_item') {
    $result = retryScenarioItem($mission, (int) post('item_id'), (int) $userId, (string) $user['name']);
    if (!$result['ok']) {
        echo $fail($result['error']);
        exit;
    }
    echo $answer([
        'item'       => $result['item'],
        'dispatches' => loadMissionDispatchesForUser($missionId, (int) $userId, true, false),
    ] + $liveSectors([$result['item']]) + $liveRoutes([$result['item']]));
    exit;
}

echo json_encode(['ok' => false, 'error' => t('common.unknown_action')]);
