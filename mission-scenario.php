<?php
/**
 * VolunteerOps - Mission Scenario Endpoint (v3.452.0, system-admin only since v3.466.0)
 * Action Room: command writes orders down in phases before an exercise (GET lists the plan; POST adds and
 * removes phases and orders) and presses play on a phase to send it. The plan itself is in
 * includes/functions-scenario.php. SYSTEM ADMIN ONLY: not a volunteer, a participant or a shift leader, but not
 * a department admin either - every other gate of the Action Room draws that line at canManageActionRoom(), this
 * one draws it tighter, because a staged order must never be seen, or reach the field, before the one account
 * trusted with it presses play. GET as for POST, no error message hints at what exists either way. AJAX only.
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
// System admins only (v3.466.0): a department admin can run everything else of the Action Room, but a staged
// plan is not sent until a system admin presses play, so only a system admin is ever given it, played or not.
if (!$canManageWarRoom || !isSystemAdmin()) {
    echo json_encode(['ok' => false, 'error' => t('scenario.no_permission')]);
    exit;
}

// ── GET: the plan ──────────────────────────────────────────────────────────
if (!isPost()) {
    // The safety net of the per-team release (v3.461.0): every refresh of the card first sends what has been waiting for a team that
    // has finished since (a completion this request missed, an order taken back), so a miss heals while somebody watches. What it sent
    // comes back with the live lists, like a play, so the map shows it at once.
    $released = scenarioAfterOrderFinished($missionId);
    $live = $released ? [
        'released'   => $released,
        'dispatches' => loadMissionDispatchesForUser($missionId, (int) $userId, true, false),
        'sectors'    => loadMissionSectorsForUser($missionId, (int) $userId, true, false),
        'areas'      => loadMissionSearchAreasForUser($missionId, true),
        'routes'     => loadRoutesForUser($missionId, (int) $userId, true),
    ] : [];
    echo json_encode(['ok' => true, 'scenario' => loadMissionScenario($missionId)] + $live);
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
// A search area made by play (v3.459.0) is handed back the same way: the pair carries it. So is a building (v3.460.0): the sectors
// of the pair carry their buildings and floors.
$liveSectors = fn(array $results) => array_filter($results, fn($r) => in_array($r['kind'] ?? '', ['sector', 'area', 'building'], true) && !empty($r['ok']) && empty($r['waiting']))
    ? ['sectors' => loadMissionSectorsForUser($missionId, (int) $userId, true, false), 'areas' => loadMissionSearchAreasForUser($missionId, true)]
    : [];
// Likewise a play or a retry that has sent routes: the live list of routes, the one the route endpoint answers with.
$liveRoutes = fn(array $results) => array_filter($results, fn($r) => ($r['kind'] ?? '') === 'route' && !empty($r['ok']) && empty($r['waiting']))
    ? ['routes' => loadRoutesForUser($missionId, (int) $userId, true)]
    : [];
// Likewise a play or a retry that has made restricted areas: the live list of zones, the one the restricted-area endpoint
// answers with, so the map and the list show them at once (v3.458.0).
$liveRestricted = fn(array $results) => array_filter($results, fn($r) => ($r['kind'] ?? '') === 'restricted' && !empty($r['ok']))
    ? ['restricted_areas' => loadMissionRestrictedAreasForUser($missionId)]
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

if ($action === 'duplicate_phase') {
    // «Αντιγραφή φάσης» (v3.467.0): a fresh, unplayed copy of a phase at the end of the plan, for a pattern that
    // repeats - a second sweep of the same sectors, the same patrol for a different team.
    $result = duplicateScenarioPhase($mission, (int) post('phase_id'), (int) $userId);
    echo $result['ok'] ? $answer(['phase_id' => $result['id']]) : $fail($result['error']);
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
    // area_id is the id of a search area that exists, or - for an area the scenario itself makes (v3.459.0) -
    // 'item:<id>' naming the staged area item the sectors are cut from.
    $areaRef = trim((string) post('area_id'));
    $areaItemId = preg_match('/^item:([1-9][0-9]{0,9})$/', $areaRef, $areaMatch) ? (int) $areaMatch[1] : 0;
    $sectors = json_decode((string) post('sectors'), true);
    $result = is_array($sectors)
        ? addScenarioSectorItems($mission, $phaseId, (int) $userId, $areaItemId > 0 ? 0 : (int) $areaRef, $sectors, $areaItemId)
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

if ($action === 'add_restricted') {
    // The restricted-area window's «Στο σενάριο» (v3.458.0): the zone drawn there goes into the phase instead of being made
    // now. A zone has no team: the fields are the window's own (label, kind, shape, geo). As for add_item, a "new phase"
    // made for a refused order is taken out again.
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
    $result = addScenarioRestrictedItem($mission, $phaseId, (int) $userId, [
        'label' => post('label'),
        'kind'  => post('kind'),
        'shape' => post('shape'),
        'geo'   => post('geo'),
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

if ($action === 'add_area') {
    // The search-area window's «Στο σενάριο» (v3.459.0): the area drawn there goes into the phase instead of being made now,
    // so that sectors can be written down against it. An area has no team: the fields are the window's own (label, geo). As
    // for add_item, a "new phase" made for a refused order is taken out again.
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
    $result = addScenarioAreaItem($mission, $phaseId, (int) $userId, [
        'label' => post('label'),
        'geo'   => post('geo'),
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

if ($action === 'add_building') {
    // The building popup's «Στο σενάριο» (v3.460.0): the building put on the map in a sector goes into the phase instead of being
    // made now. sector_id is the id of a sector that exists, or 'item:<id>' naming the staged sector the building stands in. A
    // building has no team: the sector's team searches it. As for add_item, a "new phase" made for a refused order is taken out
    // again.
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
    $result = addScenarioBuildingItem($mission, $phaseId, (int) $userId, [
        'sector_id'   => post('sector_id'),
        'label'       => post('label'),
        'lat'         => post('lat'),
        'lng'         => post('lng'),
        'floor_count' => post('floor_count'),
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

if ($action === 'add_order') {
    // The card's «Εντολή σε άτομα» (v3.457.0): a general order - a task, a spoken announcement, a broadcast message,
    // or a request for a location, a photo or a video - goes into the phase instead of out to people. team_id is a
    // team's id, 'slot:<id>' or the word 'all' (every person on duty when it is sent); an empty audience is refused.
    // As for add_item, a "new phase" made for a refused order is taken out again.
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
    $result = addScenarioOrderItem($mission, $phaseId, (int) $userId, [
        'order_type' => post('order_type'),
        'text'       => post('text'),
        'team_id'    => post('team_id'),
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
        'waiting'    => $result['waiting'],
        'failed'     => $result['failed'],
        'results'    => $result['results'],
        'dispatches' => loadMissionDispatchesForUser($missionId, (int) $userId, true, false),
    ] + $liveSectors($result['results']) + $liveRoutes($result['results']) + $liveRestricted($result['results']));
    exit;
}

if ($action === 'release_now') {
    // «Στείλε τώρα» (v3.461.0): sends what a played phase holds back for one team (team_id) - or for all of them when team_id is empty -
    // without waiting for the team to finish, including orders whose wait was cancelled.
    $teamRaw = trim((string) post('team_id'));
    $result = sendScenarioWaitingNow($mission, (int) post('phase_id'), $teamRaw === '' ? null : (int) $teamRaw, (int) $userId, (string) $user['name']);
    if (!$result['ok']) {
        echo $fail($result['error']);
        exit;
    }
    echo $answer([
        'sent'       => $result['sent'],
        'failed'     => $result['failed'],
        'results'    => $result['results'],
        'dispatches' => loadMissionDispatchesForUser($missionId, (int) $userId, true, false),
    ] + $liveSectors($result['results']) + $liveRoutes($result['results']) + $liveRestricted($result['results']));
    exit;
}

if ($action === 'cancel_wait') {
    // «Ακύρωση αναμονής» (v3.461.0): the orders a played phase holds back for one team (or for all) will not go; they stay in the plan.
    $teamRaw = trim((string) post('team_id'));
    $result = cancelScenarioWait($mission, (int) post('phase_id'), $teamRaw === '' ? null : (int) $teamRaw, (int) $userId);
    echo $result['ok'] ? $answer(['cancelled' => $result['cancelled']]) : $fail($result['error']);
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
        'cancelled'  => $result['cancelled'],
        'failed'     => $result['failed'],
        'results'    => $result['results'],
        'dispatches' => loadMissionDispatchesForUser($missionId, (int) $userId, true, false),
        'routes'     => loadRoutesForUser($missionId, (int) $userId, true),
        'sectors'    => loadMissionSectorsForUser($missionId, (int) $userId, true, false),
        'areas'      => loadMissionSearchAreasForUser($missionId, true),
        'restricted_areas' => loadMissionRestrictedAreasForUser($missionId),
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
    ] + $liveSectors([$result['item']]) + $liveRoutes([$result['item']]) + $liveRestricted([$result['item']]));
    exit;
}

echo json_encode(['ok' => false, 'error' => t('common.unknown_action')]);
