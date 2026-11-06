<?php
/**
 * VolunteerOps - Scenario (v3.452.0).
 *
 * An exercise (or a drill, or an operation planned in advance) is a list of orders the person running the
 * Action Room already knows will go out, in what order, to whom. A SCENARIO writes them down beforehand, in
 * PHASES (phase 1: three points; phase 2: a restricted area and five sectors), and when the exercise is
 * running a manual press of play on a phase sends every order in it. Nothing is timed, nothing pauses or
 * fast-forwards, and nothing is made up: what goes out is exactly what a coordinator would have sent by hand.
 *
 * WHERE A STAGED ORDER LIVES. In its OWN tables (mission_scenario_phases, mission_scenario_items), as the
 * order's JSON, and nowhere else. It is deliberately not a `staged` flag on mission_dispatch_points: every
 * reader of the live orders - the 5-second poll, the reports, the assistant, the team board, the statistics -
 * would have to remember to skip a flagged row, and one that forgot would show the plan to the field. As it is,
 * none of them can see a staged order because none of them reads these tables (a test lists the only files
 * that do). Command only, always: mission-scenario.php refuses anyone who cannot manage the Action Room.
 *
 * SENDING IS THE ORDINARY WAY. Play hands each item to createMissionDispatch() - the function the order
 * window's «Αποστολή» uses - so the checks, the row, the audit entry and the people told are the same ones.
 * The same function checks an order when it is STAGED (prepareMissionDispatch()), so an order that could never
 * be sent is refused when it is written down, not in the middle of the exercise.
 *
 * WHO IT GOES TO. A real team of this mission, or «όλες οι ομάδες», and the payload says which in words
 * (audience team|all), never by leaving the team out: for a dispatch no team means EVERY team and alarms
 * everybody, so an order whose team is gone, or was never named, FAILS at play and is reported; it is never
 * quietly widened to everyone.
 *
 * SLOTS (v3.453.0). Before the teams exist the plan can still name them: a slot («Ομάδα Α») is a name of the
 * scenario that stands for a team, and an order can go to a slot (audience 'slot'). Which real team a slot is
 * is chosen by command, in the card or in the dialog that play opens for the slots a phase still lacks, and is
 * kept (mission_scenario_slots.team_id) so the next phase needs no question. A slot with no team is NEVER an
 * order to everybody: play refuses a phase that uses one before it claims it, and the order itself fails if it
 * is ever asked to go (scenarioDispatchInputFromPayload() returns null for it, as for any team that is not there).
 *
 * SECTORS (v3.454.0). A staged order can also be a search sector (kind 'sector'): a polygon with a label, cut from
 * a search area that already exists, for a real team or a slot. Play hands it to createMissionSector() - the
 * function the sector window's save uses - so it is born exactly as a hand-drawn one is (assigned, status log,
 * audit entry, the team told), and prepareMissionSector() checks it when it is staged. Two differences from a
 * point or an area, both on purpose: a sector has no «όλες οι ομάδες» (an unassigned sector tells nobody and is
 * not an order, so staging one without a team or slot is refused), and it needs its search area at play - if
 * the area has been deleted since, the order FAILS with that reason and creates nothing, rather than guessing
 * an area. scenarioSectorInputFromPayload() is its own payload-to-form function with the same rule as the
 * dispatch one: a team that is missing, or a slot that stands for none, is null, never "no team".
 *
 * ROUTES (v3.455.0). A staged order can also be a route (kind 'route'): a patrol of one to thirty waypoints, each with its
 * instructions, dwell time and the photo/video/note it expects, for a real team or a slot. Play hands it to
 * createMissionRoute() - the function the route window's send uses - so it is born exactly as a hand-drawn one is (the
 * route, its members, the order behind it, every waypoint and its progress row, the audit entry, the team told), and
 * prepareMissionRoute() checks it when it is staged. A route has no «όλες οι ομάδες» either: with no team the route
 * window makes a route of people picked across teams, which a plan cannot name, so scenarioRouteInputFromPayload()
 * returns null for a team that is missing, a slot that stands for none and the word 'all' - never "no team". Two
 * things about a route are only known on the day: who is on the team (the people who take part in the Action Room) and
 * where the team is (the optional «επιστροφή στην αφετηρία» waypoint is its last ping). Both are asked when the route
 * is SENT; the card says beforehand when a team has nobody in the Action Room (team_empty) and play warns.
 *
 * FIRST PRESS WINS. Pressing play claims the phase with one conditional UPDATE (played_at IS NULL); two
 * coordinators pressing at once send it once. An item that fails (the team was deleted since, the shape no
 * longer passes) does not stop the others: it keeps its reason in last_error, and the panel offers to try
 * that one again.
 */

if (!defined('VOLUNTEEROPS')) {
    die('Direct access not permitted');
}

/** A scenario this long is a different tool's job; the limits only keep a runaway client from filling the table. */
const SCENARIO_MAX_PHASES = 20;
const SCENARIO_MAX_ITEMS_PER_PHASE = 60;
const SCENARIO_TITLE_MAX = 120;
const SCENARIO_MAX_SLOTS = 30;
const SCENARIO_SLOT_LABEL_MAX = 80;

/**
 * The whole scenario of a mission for the panel and the map: phases in order, each with its items, the slots, and
 * the mission's teams (so the card can offer them without another request). Plain arrays for json_encode().
 * `n` is the number shown ("Φάση n"): the position in the list, so a deleted phase leaves no gap. An item's
 * team_label is null for «όλες οι ομάδες»; team_missing is true when it names a team that is no longer in the
 * mission (it will fail at play). For an item to a slot, slot_label is the slot, team_id / team_label the team
 * the slot stands for now, and unmapped is true while it stands for none. An item of kind 'sector' has type
 * 'sector', the polygon in geo, and area_id / area_label for the search area it is cut from (area_missing is true
 * when that area has been deleted since: it will fail at play). An item of kind 'route' has type 'route', its
 * waypoints as [[lat, lng], ...] in geo, label = the route's title, point_count, closed_loop, return_to_start and,
 * for a real team, team_empty (true when nobody on that team takes part in the Action Room: it will fail at play).
 */
function loadMissionScenario(int $missionId): array {
    $teamLabels = [];
    $teams = [];
    foreach (dbFetchAll("SELECT id, codename, team_number FROM mission_teams WHERE mission_id = ? ORDER BY team_number, id", [$missionId]) as $team) {
        $teamLabels[(int) $team['id']] = teamLabel($team['codename'], $team['team_number']);
        $teams[] = ['id' => (int) $team['id'], 'label' => $teamLabels[(int) $team['id']]];
    }
    $slots = [];
    $slotById = [];
    foreach (dbFetchAll("SELECT id, label, team_id FROM mission_scenario_slots WHERE mission_id = ? ORDER BY id", [$missionId]) as $row) {
        $teamId = $row['team_id'] !== null ? (int) $row['team_id'] : null;
        $slot = [
            'id'         => (int) $row['id'],
            'label'      => $row['label'],
            'team_id'    => $teamId,
            'team_label' => $teamId !== null ? ($teamLabels[$teamId] ?? null) : null,
        ];
        $slot['mapped'] = $slot['team_label'] !== null;
        $slots[] = $slot;
        $slotById[$slot['id']] = $slot;
    }

    $phases = dbFetchAll(
        "SELECT p.id, p.seq, p.title, p.played_at, p.played_by, u.name AS played_by_name
         FROM mission_scenario_phases p
         LEFT JOIN users u ON u.id = p.played_by
         WHERE p.mission_id = ?
         ORDER BY p.seq, p.id",
        [$missionId]
    );
    if (!$phases) {
        return ['phases' => [], 'slots' => $slots, 'teams' => $teams];
    }
    $items = dbFetchAll(
        "SELECT id, phase_id, seq, kind, payload, sent_at, sent_ref_id, last_error
         FROM mission_scenario_items WHERE mission_id = ? ORDER BY phase_id, seq, id",
        [$missionId]
    );

    // The search areas that exist now, for the sectors that name one.
    $areaLabels = [];
    foreach ($items as $row) {
        if ($row['kind'] === 'sector') {
            foreach (dbFetchAll("SELECT id, label FROM mission_search_areas WHERE mission_id = ?", [$missionId]) as $area) {
                $areaLabels[(int) $area['id']] = (string) $area['label'];
            }
            break;
        }
    }

    // The people of each team who take part in the Action Room, for the routes that go to a team: a route with
    // nobody to receive it fails at play, and the card says so beforehand.
    $participantsByTeam = [];
    foreach ($items as $row) {
        if ($row['kind'] === 'route') {
            foreach (dbFetchAll(
                "SELECT m.team_id, COUNT(*) AS n FROM mission_team_members m
                 JOIN mission_action_room_participants p ON p.mission_id = m.mission_id AND p.user_id = m.user_id
                 WHERE m.mission_id = ? GROUP BY m.team_id",
                [$missionId]
            ) as $r) {
                $participantsByTeam[(int) $r['team_id']] = (int) $r['n'];
            }
            break;
        }
    }

    $byPhase = [];
    foreach ($items as $row) {
        $payload = json_decode((string) $row['payload'], true);
        if (!is_array($payload)) {
            $payload = [];
        }
        $isSector = $row['kind'] === 'sector';
        $isRoute = $row['kind'] === 'route';
        $areaId = $isSector ? (int) ($payload['area_id'] ?? 0) : 0;
        $audience = in_array($payload['audience'] ?? '', ['all', 'slot'], true) ? $payload['audience'] : 'team';
        $slotId = $audience === 'slot' ? (int) ($payload['slot_id'] ?? 0) : 0;
        $slot = $slotById[$slotId] ?? null;
        if ($audience === 'slot') {
            $teamId = $slot['team_id'] ?? 0;
            $teamLabelNow = $slot['team_label'] ?? null;
        } else {
            $teamId = $audience === 'team' ? (int) ($payload['team_id'] ?? 0) : 0;
            $teamLabelNow = $audience === 'all' ? null : ($teamLabels[$teamId] ?? null);
        }
        $byPhase[(int) $row['phase_id']][] = [
            'id'           => (int) $row['id'],
            'kind'         => $row['kind'],
            'audience'     => $audience,
            'team_id'      => $teamId ?: null,
            'team_label'   => $teamLabelNow,
            'team_missing' => $audience === 'team' && !isset($teamLabels[$teamId]),
            'slot_id'      => $slotId ?: null,
            'slot_label'   => $audience === 'slot' ? ($slot['label'] ?? ($payload['slot_label'] ?? null)) : null,
            'unmapped'     => $audience === 'slot' && $teamLabelNow === null,
            'type'         => $isSector ? 'sector' : ($isRoute ? 'route' : ($payload['type'] ?? null)),
            'geo'          => $isRoute ? scenarioRouteGhostPoints($payload) : ($payload['geo'] ?? null),
            'label'        => $payload['label'] ?? null,
            'travel_mode'  => $payload['travel_mode'] ?? null,
            'area_id'      => $isSector ? $areaId : null,
            'area_label'   => $isSector ? ($areaLabels[$areaId] ?? ($payload['area_label'] ?? null)) : null,
            'area_missing' => $isSector && !isset($areaLabels[$areaId]),
            'sent'         => $row['sent_at'] !== null,
            'sent_at'      => $row['sent_at'],
            'point_count'  => $isRoute ? count(is_array($payload['waypoints'] ?? null) ? $payload['waypoints'] : []) : null,
            'closed_loop'  => $isRoute ? !empty($payload['is_closed_loop']) : null,
            'return_to_start' => $isRoute ? !empty($payload['add_return_waypoint']) : null,
            'team_empty'   => $isRoute && $teamId > 0 && isset($teamLabels[(int) $teamId]) && empty($participantsByTeam[(int) $teamId]),
            'dispatch_id'  => !$isSector && !$isRoute && $row['sent_ref_id'] !== null ? (int) $row['sent_ref_id'] : null,
            'sector_id'    => $isSector && $row['sent_ref_id'] !== null ? (int) $row['sent_ref_id'] : null,
            'route_id'     => $isRoute && $row['sent_ref_id'] !== null ? (int) $row['sent_ref_id'] : null,
            'error'        => $row['last_error'],
        ];
    }

    $out = [];
    foreach ($phases as $i => $phase) {
        $out[] = [
            'id'             => (int) $phase['id'],
            'n'              => $i + 1,
            'title'          => $phase['title'],
            'played_at'      => $phase['played_at'],
            'played_label'   => $phase['played_at'] ? date('d/m H:i', strtotime($phase['played_at'])) : null,
            'played_by_name' => $phase['played_by_name'],
            'items'          => $byPhase[(int) $phase['id']] ?? [],
        ];
    }
    return ['phases' => $out, 'slots' => $slots, 'teams' => $teams];
}

/** One phase of this mission, or null. */
function scenarioPhaseRow(int $missionId, int $phaseId): ?array {
    $row = dbFetchOne(
        "SELECT id, mission_id, seq, title, played_at, played_by FROM mission_scenario_phases WHERE id = ? AND mission_id = ?",
        [$phaseId, $missionId]
    );
    return $row ?: null;
}

/** ['ok' => true, 'id' => n] or ['ok' => false, 'error' => text]. $title is optional ('' = just "Φάση n"). */
function addScenarioPhase(array $mission, int $userId, string $title = ''): array {
    $missionId = (int) $mission['id'];
    if ((int) dbFetchValue("SELECT COUNT(*) FROM mission_scenario_phases WHERE mission_id = ?", [$missionId]) >= SCENARIO_MAX_PHASES) {
        return ['ok' => false, 'error' => t('scenario.too_many_phases', ['max' => SCENARIO_MAX_PHASES])];
    }
    $title = mb_substr(trim($title), 0, SCENARIO_TITLE_MAX);
    $seq = 1 + (int) dbFetchValue("SELECT COALESCE(MAX(seq), 0) FROM mission_scenario_phases WHERE mission_id = ?", [$missionId]);
    $id = (int) dbInsert(
        "INSERT INTO mission_scenario_phases (mission_id, seq, title, created_by, created_at) VALUES (?, ?, ?, ?, NOW())",
        [$missionId, $seq, $title !== '' ? $title : null, $userId]
    );
    logAudit('create_scenario_phase', 'mission_scenario_phases', $id, null, ['mission_id' => $missionId, 'seq' => $seq]);
    return ['ok' => true, 'id' => $id];
}

/** Null on success, else the error to show. */
function renameScenarioPhase(array $mission, int $phaseId, string $title, int $userId): ?string {
    $missionId = (int) $mission['id'];
    if (!scenarioPhaseRow($missionId, $phaseId)) {
        return t('scenario.phase_not_found');
    }
    $title = mb_substr(trim($title), 0, SCENARIO_TITLE_MAX);
    dbExecute("UPDATE mission_scenario_phases SET title = ? WHERE id = ? AND mission_id = ?", [$title !== '' ? $title : null, $phaseId, $missionId]);
    logAudit('rename_scenario_phase', 'mission_scenario_phases', $phaseId, null, ['mission_id' => $missionId]);
    return null;
}

/**
 * Takes a phase and everything staged in it off the plan. Orders it already sent stay where they are: they are
 * live orders now and are withdrawn like any other. The phases after it move up one place. Null on success.
 */
function deleteScenarioPhase(array $mission, int $phaseId, int $userId): ?string {
    $missionId = (int) $mission['id'];
    $phase = scenarioPhaseRow($missionId, $phaseId);
    if (!$phase) {
        return t('scenario.phase_not_found');
    }
    dbExecute("DELETE FROM mission_scenario_phases WHERE id = ? AND mission_id = ?", [$phaseId, $missionId]);
    dbExecute("UPDATE mission_scenario_phases SET seq = seq - 1 WHERE mission_id = ? AND seq > ?", [$missionId, (int) $phase['seq']]);
    logAudit('delete_scenario_phase', 'mission_scenario_phases', $phaseId, null, ['mission_id' => $missionId, 'was_played' => $phase['played_at'] !== null]);
    return null;
}

/**
 * Who a staged order is for, from what the window posts in team_id: a team's id (digits), 'slot:<id>' for a slot of
 * this scenario, or - only when $allowAll - the word 'all' for every team. ANYTHING else, the empty string
 * included, is not an audience: an empty team is NOT "all". Returns null when it is not one of those, ['error' =>
 * text] for a slot that is not this mission's, else ['audience' => 'team'|'slot'|'all', 'slot' => row|null,
 * 'team_input' => what prepareMissionDispatch() / prepareMissionSector() should be given as team_id: the id for a
 * team, '' for a slot (checked as an order to nobody in particular, since the shape, the type and the note are
 * what can be wrong) and for 'all'].
 */
function scenarioParseAudience(int $missionId, string $raw, bool $allowAll): ?array {
    $raw = trim($raw);
    if ($allowAll && $raw === 'all') {
        return ['audience' => 'all', 'slot' => null, 'team_input' => ''];
    }
    if (preg_match('/^slot:([1-9][0-9]{0,9})$/', $raw, $m)) {
        $slot = scenarioSlotRow($missionId, (int) $m[1]);
        if (!$slot) {
            return ['error' => t('scenario.slot_not_found')];
        }
        return ['audience' => 'slot', 'slot' => $slot, 'team_input' => ''];
    }
    if (ctype_digit($raw) && (int) $raw > 0) {
        return ['audience' => 'team', 'slot' => null, 'team_input' => $raw];
    }
    return null;
}

/**
 * Writes an order to a point or an area into a phase. $input is the order window's form: team_id (a team's id,
 * 'slot:<id>' for a slot of this scenario, or the word 'all' for every team - anything else is refused, an empty
 * team is NOT "all"), type ('point' or
 * 'polygon'), geo (JSON text), label, travel_mode. Checked by prepareMissionDispatch(), exactly as sending it
 * would be. A phase that has been played takes no more orders. ['ok' => true, 'id' => n] or ['ok' => false, 'error' => text].
 */
function addScenarioDispatchItem(array $mission, int $phaseId, int $userId, array $input): array {
    $missionId = (int) $mission['id'];
    $phase = scenarioPhaseRow($missionId, $phaseId);
    if (!$phase) {
        return ['ok' => false, 'error' => t('scenario.phase_not_found')];
    }
    if ($phase['played_at'] !== null) {
        return ['ok' => false, 'error' => t('scenario.phase_closed_for_orders')];
    }
    if ((int) dbFetchValue("SELECT COUNT(*) FROM mission_scenario_items WHERE phase_id = ?", [$phaseId]) >= SCENARIO_MAX_ITEMS_PER_PHASE) {
        return ['ok' => false, 'error' => t('scenario.too_many_orders', ['max' => SCENARIO_MAX_ITEMS_PER_PHASE])];
    }

    $who = scenarioParseAudience($missionId, (string) ($input['team_id'] ?? ''), true);
    if ($who === null) {
        return ['ok' => false, 'error' => t('dispatch.pick_team_first')];
    }
    if (isset($who['error'])) {
        return ['ok' => false, 'error' => $who['error']];
    }
    $audience = $who['audience'];
    $slot = $who['slot'];
    $teamInput = $who['team_input'];

    $prepared = prepareMissionDispatch($mission, [
        'team_id'     => $teamInput,
        'type'        => $input['type'] ?? '',
        'label'       => $input['label'] ?? '',
        'geo'         => $input['geo'] ?? '',
        'travel_mode' => $input['travel_mode'] ?? null,
    ]);
    if (!$prepared['ok']) {
        return $prepared;
    }

    $payload = [
        'audience'    => $audience,
        'team_id'     => $audience === 'team' ? (int) $prepared['team_id'] : null,
        'type'        => $prepared['type'],
        'geo'         => $prepared['geo'],
        'label'       => $prepared['label'],
        // An order to a slot is checked as one to no team, which has no travel mode of its own; the person's
        // choice is what the team it ends up as will be sent with.
        'travel_mode' => $audience === 'slot' ? normalizeTravelMode($input['travel_mode'] ?? null) : $prepared['travel_mode'],
    ];
    if ($audience === 'slot') {
        $payload['slot_id'] = (int) $slot['id'];
        $payload['slot_label'] = $slot['label'];
    }
    $seq = 1 + (int) dbFetchValue("SELECT COALESCE(MAX(seq), 0) FROM mission_scenario_items WHERE phase_id = ?", [$phaseId]);
    $id = (int) dbInsert(
        "INSERT INTO mission_scenario_items (phase_id, mission_id, seq, kind, payload, slot_id, created_by, created_at) VALUES (?, ?, ?, 'dispatch', ?, ?, ?, NOW())",
        [$phaseId, $missionId, $seq, json_encode($payload), $slot ? (int) $slot['id'] : null, $userId]
    );
    logAudit('stage_scenario_item', 'mission_scenario_items', $id, null, ['mission_id' => $missionId, 'phase_id' => $phaseId, 'type' => $payload['type'], 'audience' => $audience, 'team_id' => $payload['team_id'], 'slot_id' => $payload['slot_id'] ?? null]);
    return ['ok' => true, 'id' => $id];
}

/**
 * Writes the sectors of ONE existing search area into a phase, all of them or none (the sector window's «Στο
 * σενάριο» stages every piece of the division at once, so a refusal of one must not leave the others behind).
 * $sectors is a list of the window's rows: team_id (a team's id, or 'slot:<id>' for a slot of this scenario - a
 * sector has no «όλες οι ομάδες», and the word 'all' or an empty team is REFUSED, not turned into "no team"),
 * label, geo (an array of [lat, lng], or its JSON text). Each is checked by prepareMissionSector(), exactly as
 * making it would be, so a sector that could never be made (no such area, a team of another mission, a polygon of
 * two points) is refused when it is written down. A phase that has been played takes no more orders.
 * ['ok' => true, 'count' => n] or ['ok' => false, 'error' => text] with nothing written.
 */
function addScenarioSectorItems(array $mission, int $phaseId, int $userId, int $areaId, array $sectors): array {
    $missionId = (int) $mission['id'];
    $phase = scenarioPhaseRow($missionId, $phaseId);
    if (!$phase) {
        return ['ok' => false, 'error' => t('scenario.phase_not_found')];
    }
    if ($phase['played_at'] !== null) {
        return ['ok' => false, 'error' => t('scenario.phase_closed_for_orders')];
    }
    $sectors = array_values($sectors);
    if (!$sectors) {
        return ['ok' => false, 'error' => t('scenario.sector_none')];
    }
    if ((int) dbFetchValue("SELECT COUNT(*) FROM mission_scenario_items WHERE phase_id = ?", [$phaseId]) + count($sectors) > SCENARIO_MAX_ITEMS_PER_PHASE) {
        return ['ok' => false, 'error' => t('scenario.too_many_orders', ['max' => SCENARIO_MAX_ITEMS_PER_PHASE])];
    }

    $rows = [];
    foreach ($sectors as $i => $row) {
        if (!is_array($row)) {
            return ['ok' => false, 'error' => t('common.invalid_request')];
        }
        $name = trim((string) ($row['label'] ?? ''));
        // With several sectors the person has to be told WHICH one was refused.
        $refuse = fn(string $error) => ['ok' => false, 'error' => count($sectors) > 1
            ? t('scenario.sector_refused', ['sector' => $name !== '' ? $name : '#' . ($i + 1), 'error' => $error])
            : $error];

        $who = scenarioParseAudience($missionId, (string) ($row['team_id'] ?? ''), false);
        if ($who === null) {
            return $refuse(t('scenario.sector_needs_team'));
        }
        if (isset($who['error'])) {
            return $refuse($who['error']);
        }
        $geo = $row['geo'] ?? '';
        $prepared = prepareMissionSector($mission, [
            'label'   => $name,
            'area_id' => $areaId,
            'team_id' => $who['team_input'],
            'geo'     => is_array($geo) ? json_encode($geo) : (string) $geo,
        ]);
        if (!$prepared['ok']) {
            return $refuse($prepared['error']);
        }
        $payload = [
            'audience'   => $who['audience'],
            'team_id'    => $who['audience'] === 'team' ? (int) $prepared['team_id'] : null,
            'area_id'    => $prepared['area_id'],
            'area_label' => $prepared['area_label'],
            'label'      => $prepared['label'],
            'geo'        => $prepared['geo'],
        ];
        if ($who['audience'] === 'slot') {
            $payload['slot_id'] = (int) $who['slot']['id'];
            $payload['slot_label'] = $who['slot']['label'];
        }
        $rows[] = ['payload' => $payload, 'slot_id' => $who['slot'] ? (int) $who['slot']['id'] : null];
    }

    // One statement, so a failure writes none of them.
    $seq = 1 + (int) dbFetchValue("SELECT COALESCE(MAX(seq), 0) FROM mission_scenario_items WHERE phase_id = ?", [$phaseId]);
    $values = [];
    $params = [];
    foreach ($rows as $i => $r) {
        $values[] = "(?, ?, ?, 'sector', ?, ?, ?, NOW())";
        array_push($params, $phaseId, $missionId, $seq + $i, json_encode($r['payload']), $r['slot_id'], $userId);
    }
    $firstId = (int) dbInsert(
        "INSERT INTO mission_scenario_items (phase_id, mission_id, seq, kind, payload, slot_id, created_by, created_at) VALUES " . implode(', ', $values),
        $params
    );
    logAudit('stage_scenario_sectors', 'mission_scenario_items', $firstId, null, ['mission_id' => $missionId, 'phase_id' => $phaseId, 'area_id' => $areaId, 'count' => count($rows)]);
    return ['ok' => true, 'count' => count($rows)];
}

/** A staged route's waypoints as [[lat, lng], ...] for the map, or null when it has none. */
function scenarioRouteGhostPoints($payload): ?array {
    if (!is_array($payload) || !is_array($payload['waypoints'] ?? null) || !$payload['waypoints']) {
        return null;
    }
    $points = [];
    foreach ($payload['waypoints'] as $wp) {
        if (is_array($wp) && isset($wp['lat'], $wp['lng'])) {
            $points[] = [(float) $wp['lat'], (float) $wp['lng']];
        }
    }
    return $points ?: null;
}

/**
 * Writes a route into a phase. $input is the route window's form: team_id (a team's id, or 'slot:<id>' for a slot of
 * this scenario - a route has no «όλες οι ομάδες», and the word 'all' or an empty team, which in the route window means
 * "people picked across teams", is REFUSED), title, waypoints (JSON text), is_closed_loop ('1'), add_return_waypoint
 * ('1'), member_ids (JSON text; only a real team has them: a slot goes to the whole team it comes to stand for),
 * travel_mode. Checked by prepareMissionRoute(), exactly as sending it would be, so a route that could never be sent
 * (a team of another mission, no waypoint, thirty-one of them, one out of range) is refused when it is written down.
 * The return waypoint, which is the team's last ping on the day, is not a point of the plan, but a route that already
 * has thirty could never take it, so that is refused here as it would be at send. A phase that has been played takes
 * no more orders. ['ok' => true, 'id' => n] or ['ok' => false, 'error' => text].
 */
function addScenarioRouteItem(array $mission, int $phaseId, int $userId, array $input): array {
    $missionId = (int) $mission['id'];
    $phase = scenarioPhaseRow($missionId, $phaseId);
    if (!$phase) {
        return ['ok' => false, 'error' => t('scenario.phase_not_found')];
    }
    if ($phase['played_at'] !== null) {
        return ['ok' => false, 'error' => t('scenario.phase_closed_for_orders')];
    }
    if ((int) dbFetchValue("SELECT COUNT(*) FROM mission_scenario_items WHERE phase_id = ?", [$phaseId]) >= SCENARIO_MAX_ITEMS_PER_PHASE) {
        return ['ok' => false, 'error' => t('scenario.too_many_orders', ['max' => SCENARIO_MAX_ITEMS_PER_PHASE])];
    }

    $who = scenarioParseAudience($missionId, (string) ($input['team_id'] ?? ''), false);
    if ($who === null) {
        return ['ok' => false, 'error' => t('scenario.route_needs_team')];
    }
    if (isset($who['error'])) {
        return ['ok' => false, 'error' => $who['error']];
    }
    $audience = $who['audience'];
    $slot = $who['slot'];

    $prepared = prepareMissionRoute($mission, [
        // '' for a slot: only the route's own rules are checked (the shape, the thirty points), not a team.
        'team_id'        => $who['team_input'],
        'ring_index'     => '',
        'title'          => $input['title'] ?? '',
        'waypoints'      => $input['waypoints'] ?? '',
        'member_ids'     => $audience === 'team' ? ($input['member_ids'] ?? '') : '',
        'is_closed_loop' => $input['is_closed_loop'] ?? '',
        'travel_mode'    => $input['travel_mode'] ?? null,
    ]);
    if (!$prepared['ok']) {
        return $prepared;
    }
    $addReturn = ($input['add_return_waypoint'] ?? '') === '1';
    if ($addReturn && count($prepared['waypoints']) >= ROUTE_MAX_WAYPOINTS) {
        return ['ok' => false, 'error' => t('route.too_many_waypoints_with_return')];
    }

    $payload = [
        'audience'            => $audience,
        'team_id'             => $audience === 'team' ? (int) $prepared['team_id'] : null,
        'label'               => $prepared['title'],
        'waypoints'           => $prepared['waypoints'],
        'is_closed_loop'      => $prepared['closed_loop_asked'] ? 1 : 0,
        'add_return_waypoint' => $addReturn ? 1 : 0,
        'travel_mode'         => $prepared['travel_mode'],
        'member_ids'          => $audience === 'team' ? array_values(array_unique(array_filter($prepared['member_ids'], fn($id) => $id > 0))) : [],
    ];
    if ($audience === 'slot') {
        $payload['slot_id'] = (int) $slot['id'];
        $payload['slot_label'] = $slot['label'];
    }
    $seq = 1 + (int) dbFetchValue("SELECT COALESCE(MAX(seq), 0) FROM mission_scenario_items WHERE phase_id = ?", [$phaseId]);
    $id = (int) dbInsert(
        "INSERT INTO mission_scenario_items (phase_id, mission_id, seq, kind, payload, slot_id, created_by, created_at) VALUES (?, ?, ?, 'route', ?, ?, ?, NOW())",
        [$phaseId, $missionId, $seq, json_encode($payload), $slot ? (int) $slot['id'] : null, $userId]
    );
    logAudit('stage_scenario_item', 'mission_scenario_items', $id, null, ['mission_id' => $missionId, 'phase_id' => $phaseId, 'type' => 'route', 'audience' => $audience, 'team_id' => $payload['team_id'], 'slot_id' => $payload['slot_id'] ?? null, 'waypoints' => count($payload['waypoints'])]);
    return ['ok' => true, 'id' => $id];
}

/** Takes one staged order off the plan. Null on success. */
function deleteScenarioItem(array $mission, int $itemId, int $userId): ?string {
    $missionId = (int) $mission['id'];
    $item = dbFetchOne("SELECT id, phase_id, sent_at FROM mission_scenario_items WHERE id = ? AND mission_id = ?", [$itemId, $missionId]);
    if (!$item) {
        return t('scenario.item_not_found');
    }
    dbExecute("DELETE FROM mission_scenario_items WHERE id = ? AND mission_id = ?", [$itemId, $missionId]);
    logAudit('delete_scenario_item', 'mission_scenario_items', $itemId, null, ['mission_id' => $missionId, 'phase_id' => (int) $item['phase_id'], 'was_sent' => $item['sent_at'] !== null]);
    return null;
}

/**
 * What createMissionDispatch() takes, built from a staged order's payload, or null when the payload cannot be
 * read as an order. The one place that turns a payload back into a form: «όλες οι ομάδες» is the empty team_id
 * ONLY when the payload says audience 'all'; an audience 'team' without a real team id is null, never ''; and an
 * audience 'slot' is the team its slot stands for ($slotTeams: slot id => team id, as scenarioSlotTeamMap() gives
 * it) or null while the slot stands for none - never ''.
 */
function scenarioDispatchInputFromPayload($payload, array $slotTeams = []): ?array {
    if (!is_array($payload) || !isset($payload['type'], $payload['geo'])) {
        return null;
    }
    if (($payload['audience'] ?? '') === 'all') {
        $teamId = '';
    } elseif (($payload['audience'] ?? '') === 'team' && (int) ($payload['team_id'] ?? 0) > 0) {
        $teamId = (string) (int) $payload['team_id'];
    } elseif (($payload['audience'] ?? '') === 'slot' && (int) ($slotTeams[(int) ($payload['slot_id'] ?? 0)] ?? 0) > 0) {
        $teamId = (string) (int) $slotTeams[(int) $payload['slot_id']];
    } else {
        return null;
    }
    return [
        'team_id'     => $teamId,
        'ring_index'  => '',
        'incident_id' => '',
        'type'        => (string) $payload['type'],
        'label'       => (string) ($payload['label'] ?? ''),
        'geo'         => json_encode($payload['geo']),
        'travel_mode' => $teamId === '' ? '' : (string) ($payload['travel_mode'] ?? ''),
    ];
}

/**
 * What createMissionSector() takes, built from a staged sector's payload, or null when the payload cannot be read
 * as a sector. The sector's twin of scenarioDispatchInputFromPayload(), with the same rule and one more: a sector
 * goes to a REAL team - audience 'team' with a team id, or audience 'slot' whose slot stands for a team
 * ($slotTeams: slot id => team id, as scenarioSlotTeamMap() gives it). A missing team, a slot that stands for
 * none and the word 'all' (which a sector does not have) are all null, never "no team": a sector made with no
 * team is an unassigned one that tells nobody, which is not the order that was staged.
 */
function scenarioSectorInputFromPayload($payload, array $slotTeams = []): ?array {
    if (!is_array($payload) || (int) ($payload['area_id'] ?? 0) <= 0 || !isset($payload['geo']) || !is_array($payload['geo'])) {
        return null;
    }
    if (($payload['audience'] ?? '') === 'team' && (int) ($payload['team_id'] ?? 0) > 0) {
        $teamId = (string) (int) $payload['team_id'];
    } elseif (($payload['audience'] ?? '') === 'slot' && (int) ($slotTeams[(int) ($payload['slot_id'] ?? 0)] ?? 0) > 0) {
        $teamId = (string) (int) $slotTeams[(int) $payload['slot_id']];
    } else {
        return null;
    }
    return [
        'area_id' => (string) (int) $payload['area_id'],
        'team_id' => $teamId,
        'label'   => (string) ($payload['label'] ?? ''),
        'geo'     => json_encode($payload['geo']),
    ];
}

/**
 * What createMissionRoute() takes, built from a staged route's payload, or null when the payload cannot be read as a
 * route. The route's twin of scenarioSectorInputFromPayload(), with the same rule: a route goes to a REAL team -
 * audience 'team' with a team id, or audience 'slot' whose slot stands for a team ($slotTeams: slot id => team id, as
 * scenarioSlotTeamMap() gives it). A missing team, a slot that stands for none and the word 'all' (which a route does
 * not have) are all null, never "no team": with no team the route window makes a route of people picked across
 * teams, which is not the order that was staged and would tell whoever happens to be in member_ids.
 */
function scenarioRouteInputFromPayload($payload, array $slotTeams = []): ?array {
    if (!is_array($payload) || !is_array($payload['waypoints'] ?? null) || !$payload['waypoints']) {
        return null;
    }
    if (($payload['audience'] ?? '') === 'team' && (int) ($payload['team_id'] ?? 0) > 0) {
        $teamId = (string) (int) $payload['team_id'];
    } elseif (($payload['audience'] ?? '') === 'slot' && (int) ($slotTeams[(int) ($payload['slot_id'] ?? 0)] ?? 0) > 0) {
        $teamId = (string) (int) $slotTeams[(int) $payload['slot_id']];
    } else {
        return null;
    }
    return [
        'team_id'             => $teamId,
        'ring_index'          => '',
        'title'               => (string) ($payload['label'] ?? ''),
        'waypoints'           => json_encode($payload['waypoints']),
        'add_return_waypoint' => !empty($payload['add_return_waypoint']) ? '1' : '0',
        'member_ids'          => json_encode(array_values(array_map('intval', is_array($payload['member_ids'] ?? null) ? $payload['member_ids'] : []))),
        'is_closed_loop'      => !empty($payload['is_closed_loop']) ? '1' : '0',
        'travel_mode'         => (string) ($payload['travel_mode'] ?? ''),
    ];
}

/**
 * Sends one staged order - a point or an area through createMissionDispatch(), a sector through
 * createMissionSector(), a route through createMissionRoute() - and writes down what came of it. Returns ['item_id' =>
 * n, 'kind' => 'dispatch'|'sector'|'route', 'ok' => bool, 'dispatch_id' => n|null (the live order, for a point or an
 * area), 'sector_id' => n|null (for a sector), 'route_id' => n|null (for a route), 'error' => text|null]. Never
 * throws: an item that blows up is a failed item, not an aborted phase. A sector whose search area has been deleted
 * since fails with that reason and creates nothing.
 */
function scenarioSendItem(array $mission, array $item, int $userId, string $userName): array {
    $itemId = (int) $item['id'];
    $kind = in_array($item['kind'] ?? 'dispatch', ['sector', 'route'], true) ? $item['kind'] : 'dispatch';
    $isSector = $kind === 'sector';
    $payload = json_decode((string) $item['payload'], true);
    $slotTeams = scenarioSlotTeamMap((int) $mission['id']);
    $input = match ($kind) {
        'sector' => scenarioSectorInputFromPayload($payload, $slotTeams),
        'route' => scenarioRouteInputFromPayload($payload, $slotTeams),
        default => scenarioDispatchInputFromPayload($payload, $slotTeams),
    };
    if ($input === null) {
        $result = ['ok' => false, 'error' => is_array($payload) && ($payload['audience'] ?? '') === 'slot'
            ? t('scenario.item_slot_unmapped', ['slot' => (string) ($payload['slot_label'] ?? '')])
            : t('scenario.item_unreadable')];
    } elseif ($isSector && !dbFetchValue("SELECT 1 FROM mission_search_areas WHERE id = ? AND mission_id = ?", [(int) $input['area_id'], (int) $mission['id']])) {
        $result = ['ok' => false, 'error' => t('scenario.item_area_gone', ['area' => (string) ($payload['area_label'] ?? '')])];
    } else {
        try {
            $result = match ($kind) {
                'sector' => createMissionSector($mission, $userId, $input),
                'route' => createMissionRoute($mission, $userId, $input),
                default => createMissionDispatch($mission, $userId, $userName, $input),
            };
        } catch (Throwable $e) {
            error_log('[scenario] item ' . $itemId . ' failed: ' . $e->getMessage());
            $result = ['ok' => false, 'error' => t('scenario.item_failed_unexpected')];
        }
    }
    if ($result['ok']) {
        dbExecute(
            "UPDATE mission_scenario_items SET sent_at = NOW(), sent_ref_id = ?, last_error = NULL WHERE id = ?",
            [(int) $result['id'], $itemId]
        );
        return [
            'item_id' => $itemId, 'kind' => $kind, 'ok' => true,
            'dispatch_id' => $kind === 'dispatch' ? (int) $result['id'] : null,
            'sector_id' => $isSector ? (int) $result['id'] : null,
            'route_id' => $kind === 'route' ? (int) $result['id'] : null,
            'error' => null,
        ];
    }
    $error = mb_substr((string) $result['error'], 0, 250);
    dbExecute("UPDATE mission_scenario_items SET last_error = ? WHERE id = ?", [$error, $itemId]);
    return ['item_id' => $itemId, 'kind' => $kind, 'ok' => false, 'dispatch_id' => null, 'sector_id' => null, 'route_id' => null, 'error' => $error];
}

/**
 * The first press wins: marks the phase played by $userId if, and only if, nobody has yet. One conditional UPDATE
 * (played_at IS NULL), so of two presses at the same moment exactly one gets true. Whoever gets false sends nothing.
 */
function claimScenarioPhase(int $missionId, int $phaseId, int $userId): bool {
    return dbExecute(
        "UPDATE mission_scenario_phases SET played_at = NOW(), played_by = ? WHERE id = ? AND mission_id = ? AND played_at IS NULL",
        [$userId, $phaseId, $missionId]
    ) > 0;
}

/**
 * PLAY: sends every order of a phase. The first press wins - one conditional UPDATE claims the phase, so a second
 * press (another coordinator, a double tap) is told it has gone and sends nothing. A phase with nothing in it
 * is refused before it is claimed. Each order then goes out in its staged order and one that fails does not
 * stop the rest. $mapping (slot id => team id) is what the dialog at play collects for the slots that stand for
 * no team yet; it is applied only to those, and a phase that still uses a slot with no team after it is refused
 * BEFORE the claim, with the list of those slots in 'unmapped' - nothing is sent and the phase stays a plan.
 * Returns ['ok' => false, 'error' => text (, 'unmapped' => [['id' =>, 'label' =>]])], or ['ok' => true, 'sent' =>
 * n, 'failed' => n, 'results' => [per item, see scenarioSendItem()]].
 */
function playScenarioPhase(array $mission, int $phaseId, int $userId, string $userName, array $mapping = []): array {
    $missionId = (int) $mission['id'];
    $phase = scenarioPhaseRow($missionId, $phaseId);
    if (!$phase) {
        return ['ok' => false, 'error' => t('scenario.phase_not_found')];
    }
    if ($phase['played_at'] !== null) {
        return ['ok' => false, 'error' => t('scenario.phase_already_played')];
    }
    if ((int) dbFetchValue("SELECT COUNT(*) FROM mission_scenario_items WHERE phase_id = ? AND sent_at IS NULL", [$phaseId]) === 0) {
        return ['ok' => false, 'error' => t('scenario.phase_empty')];
    }

    if ($mapping) {
        $error = mapScenarioSlotsIfFree($mission, $mapping, $userId);
        if ($error !== null) {
            return ['ok' => false, 'error' => $error];
        }
    }
    $unmapped = scenarioUnmappedSlotsOfPhase($missionId, $phaseId);
    if ($unmapped) {
        return [
            'ok' => false,
            'error' => t('scenario.slots_unmapped', ['slots' => implode(', ', array_column($unmapped, 'label'))]),
            'unmapped' => $unmapped,
        ];
    }

    if (!claimScenarioPhase($missionId, $phaseId, $userId)) {
        return ['ok' => false, 'error' => t('scenario.phase_already_played')];
    }

    $results = [];
    $items = dbFetchAll("SELECT id, kind, payload FROM mission_scenario_items WHERE phase_id = ? AND sent_at IS NULL ORDER BY seq, id", [$phaseId]);
    foreach ($items as $item) {
        $results[] = scenarioSendItem($mission, $item, $userId, $userName);
    }
    $sent = count(array_filter($results, fn($r) => $r['ok']));
    logAudit('play_scenario_phase', 'mission_scenario_phases', $phaseId, null, [
        'mission_id' => $missionId, 'orders' => count($results), 'sent' => $sent, 'failed' => count($results) - $sent,
    ]);
    return ['ok' => true, 'sent' => $sent, 'failed' => count($results) - $sent, 'results' => $results];
}

/**
 * Tries one order of a PLAYED phase again (it failed: the team was missing, the shape no longer passed). Only an
 * order that has a recorded failure can be tried again, and one press wins: the failure is cleared by a
 * conditional UPDATE that only one caller can make. ['ok' => false, 'error' => text], or ['ok' => true, 'item' =>
 * the result of scenarioSendItem()] (the item may still have failed again: look at item.ok).
 */
function retryScenarioItem(array $mission, int $itemId, int $userId, string $userName): array {
    $missionId = (int) $mission['id'];
    $item = dbFetchOne(
        "SELECT i.id, i.kind, i.payload, i.sent_at, i.last_error, p.played_at
         FROM mission_scenario_items i JOIN mission_scenario_phases p ON p.id = i.phase_id
         WHERE i.id = ? AND i.mission_id = ?",
        [$itemId, $missionId]
    );
    if (!$item) {
        return ['ok' => false, 'error' => t('scenario.item_not_found')];
    }
    if ($item['played_at'] === null) {
        return ['ok' => false, 'error' => t('scenario.phase_not_played_yet')];
    }
    $claimed = dbExecute(
        "UPDATE mission_scenario_items SET last_error = NULL WHERE id = ? AND sent_at IS NULL AND last_error IS NOT NULL AND last_error <> ''",
        [$itemId]
    );
    if (!$claimed) {
        return ['ok' => false, 'error' => t('scenario.nothing_to_retry')];
    }
    $result = scenarioSendItem($mission, $item, $userId, $userName);
    logAudit('retry_scenario_item', 'mission_scenario_items', $itemId, null, ['mission_id' => $missionId, 'ok' => $result['ok']]);
    return ['ok' => true, 'item' => $result];
}

// ── Slots ───────────────────────────────────────────────────────────────────────────────────────────────────────

/** One slot of this mission, or null. */
function scenarioSlotRow(int $missionId, int $slotId): ?array {
    $row = dbFetchOne("SELECT id, mission_id, label, team_id FROM mission_scenario_slots WHERE id = ? AND mission_id = ?", [$slotId, $missionId]);
    return $row ?: null;
}

/** slot id => the id of the team it stands for, or null while it stands for none. */
function scenarioSlotTeamMap(int $missionId): array {
    $map = [];
    foreach (dbFetchAll("SELECT id, team_id FROM mission_scenario_slots WHERE mission_id = ?", [$missionId]) as $row) {
        $map[(int) $row['id']] = $row['team_id'] !== null ? (int) $row['team_id'] : null;
    }
    return $map;
}

/**
 * The slots the unsent orders of a phase go to that stand for no team (or for a team that is no longer in the
 * mission): [['id' => n, 'label' => text], ...], in the order the orders come.
 */
function scenarioUnmappedSlotsOfPhase(int $missionId, int $phaseId): array {
    return array_map(
        fn($r) => ['id' => (int) $r['id'], 'label' => $r['label']],
        dbFetchAll(
            "SELECT s.id, s.label
             FROM mission_scenario_slots s
             JOIN mission_scenario_items i ON i.slot_id = s.id
             LEFT JOIN mission_teams t ON t.id = s.team_id AND t.mission_id = s.mission_id
             WHERE i.phase_id = ? AND i.sent_at IS NULL AND s.mission_id = ? AND t.id IS NULL
             GROUP BY s.id, s.label
             ORDER BY MIN(i.seq), s.id",
            [$phaseId, $missionId]
        )
    );
}

/** ['ok' => true, 'id' => n] or ['ok' => false, 'error' => text]. A name is unique within the mission, whatever its case. */
function addScenarioSlot(array $mission, int $userId, string $label): array {
    $missionId = (int) $mission['id'];
    $label = mb_substr(trim(preg_replace('/\s+/u', ' ', $label)), 0, SCENARIO_SLOT_LABEL_MAX);
    if ($label === '') {
        return ['ok' => false, 'error' => t('scenario.slot_label_empty')];
    }
    if ((int) dbFetchValue("SELECT COUNT(*) FROM mission_scenario_slots WHERE mission_id = ?", [$missionId]) >= SCENARIO_MAX_SLOTS) {
        return ['ok' => false, 'error' => t('scenario.too_many_slots', ['max' => SCENARIO_MAX_SLOTS])];
    }
    if ((int) dbFetchValue("SELECT COUNT(*) FROM mission_scenario_slots WHERE mission_id = ? AND label = ?", [$missionId, $label]) > 0) {
        return ['ok' => false, 'error' => t('scenario.slot_label_taken', ['label' => $label])];
    }
    $id = (int) dbInsert(
        "INSERT INTO mission_scenario_slots (mission_id, label, created_by, created_at) VALUES (?, ?, ?, NOW())",
        [$missionId, $label, $userId]
    );
    logAudit('create_scenario_slot', 'mission_scenario_slots', $id, null, ['mission_id' => $missionId, 'label' => $label]);
    return ['ok' => true, 'id' => $id];
}

/**
 * Says which real team a slot stands for ($teamId null = for none). The team must be one of this mission's.
 * Null on success, else the error to show.
 */
function mapScenarioSlot(array $mission, int $slotId, ?int $teamId, int $userId): ?string {
    $missionId = (int) $mission['id'];
    if (!scenarioSlotRow($missionId, $slotId)) {
        return t('scenario.slot_not_found');
    }
    if ($teamId !== null && !dbFetchValue("SELECT 1 FROM mission_teams WHERE id = ? AND mission_id = ?", [$teamId, $missionId])) {
        return t('common.team_not_found');
    }
    dbExecute("UPDATE mission_scenario_slots SET team_id = ? WHERE id = ? AND mission_id = ?", [$teamId, $slotId, $missionId]);
    logAudit('map_scenario_slot', 'mission_scenario_slots', $slotId, null, ['mission_id' => $missionId, 'team_id' => $teamId]);
    return null;
}

/**
 * The dialog at play: $mapping is slot id => team id for the slots a phase lacks a team for. Every pair is
 * checked first (the slot and the team are this mission's) and nothing is applied if one is not; then each is
 * applied ONLY to a slot that still stands for no team, so a press that arrives after another coordinator has
 * answered the same question does not overwrite their answer. Null on success, else the error to show.
 */
function mapScenarioSlotsIfFree(array $mission, array $mapping, int $userId): ?string {
    $missionId = (int) $mission['id'];
    $pairs = [];
    foreach ($mapping as $slotId => $teamId) {
        $slotId = (int) $slotId;
        $teamId = (int) $teamId;
        if (!scenarioSlotRow($missionId, $slotId)) {
            return t('scenario.slot_not_found');
        }
        if ($teamId <= 0 || !dbFetchValue("SELECT 1 FROM mission_teams WHERE id = ? AND mission_id = ?", [$teamId, $missionId])) {
            return t('common.team_not_found');
        }
        $pairs[$slotId] = $teamId;
    }
    foreach ($pairs as $slotId => $teamId) {
        $changed = dbExecute(
            "UPDATE mission_scenario_slots s
             LEFT JOIN mission_teams t ON t.id = s.team_id AND t.mission_id = s.mission_id
             SET s.team_id = ?
             WHERE s.id = ? AND s.mission_id = ? AND t.id IS NULL",
            [$teamId, $slotId, $missionId]
        );
        if ($changed) {
            logAudit('map_scenario_slot', 'mission_scenario_slots', $slotId, null, ['mission_id' => $missionId, 'team_id' => $teamId, 'at_play' => true]);
        }
    }
    return null;
}

/** Takes a slot off the plan. Refused while an order that has not been sent goes to it. Null on success. */
function deleteScenarioSlot(array $mission, int $slotId, int $userId): ?string {
    $missionId = (int) $mission['id'];
    if (!scenarioSlotRow($missionId, $slotId)) {
        return t('scenario.slot_not_found');
    }
    if ((int) dbFetchValue("SELECT COUNT(*) FROM mission_scenario_items WHERE slot_id = ? AND sent_at IS NULL", [$slotId]) > 0) {
        return t('scenario.slot_in_use');
    }
    dbExecute("DELETE FROM mission_scenario_slots WHERE id = ? AND mission_id = ?", [$slotId, $missionId]);
    logAudit('delete_scenario_slot', 'mission_scenario_slots', $slotId, null, ['mission_id' => $missionId]);
    return null;
}
