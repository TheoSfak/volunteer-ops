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
 * the slot stands for now, and unmapped is true while it stands for none.
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

    $byPhase = [];
    foreach ($items as $row) {
        $payload = json_decode((string) $row['payload'], true);
        if (!is_array($payload)) {
            $payload = [];
        }
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
            'type'         => $payload['type'] ?? null,
            'geo'          => $payload['geo'] ?? null,
            'label'        => $payload['label'] ?? null,
            'travel_mode'  => $payload['travel_mode'] ?? null,
            'sent'         => $row['sent_at'] !== null,
            'sent_at'      => $row['sent_at'],
            'dispatch_id'  => $row['sent_ref_id'] !== null ? (int) $row['sent_ref_id'] : null,
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

    $teamRaw = trim((string) ($input['team_id'] ?? ''));
    $slot = null;
    if ($teamRaw === 'all') {
        $audience = 'all';
        $teamInput = '';
    } elseif (preg_match('/^slot:([1-9][0-9]{0,9})$/', $teamRaw, $m)) {
        $audience = 'slot';
        $slot = scenarioSlotRow($missionId, (int) $m[1]);
        if (!$slot) {
            return ['ok' => false, 'error' => t('scenario.slot_not_found')];
        }
        // Checked as an order to nobody in particular: the shape, the type and the note are what can be wrong.
        $teamInput = '';
    } elseif (ctype_digit($teamRaw) && (int) $teamRaw > 0) {
        $audience = 'team';
        $teamInput = $teamRaw;
    } else {
        return ['ok' => false, 'error' => t('dispatch.pick_team_first')];
    }

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
 * Sends one staged order through createMissionDispatch() and writes down what came of it. Returns
 * ['item_id' => n, 'ok' => bool, 'dispatch_id' => n|null, 'error' => text|null]. Never throws: an item that
 * blows up is a failed item, not an aborted phase.
 */
function scenarioSendItem(array $mission, array $item, int $userId, string $userName): array {
    $itemId = (int) $item['id'];
    $payload = json_decode((string) $item['payload'], true);
    $input = scenarioDispatchInputFromPayload($payload, scenarioSlotTeamMap((int) $mission['id']));
    if ($input === null) {
        $result = ['ok' => false, 'error' => is_array($payload) && ($payload['audience'] ?? '') === 'slot'
            ? t('scenario.item_slot_unmapped', ['slot' => (string) ($payload['slot_label'] ?? '')])
            : t('scenario.item_unreadable')];
    } else {
        try {
            $result = createMissionDispatch($mission, $userId, $userName, $input);
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
        return ['item_id' => $itemId, 'ok' => true, 'dispatch_id' => (int) $result['id'], 'error' => null];
    }
    $error = mb_substr((string) $result['error'], 0, 250);
    dbExecute("UPDATE mission_scenario_items SET last_error = ? WHERE id = ?", [$error, $itemId]);
    return ['item_id' => $itemId, 'ok' => false, 'dispatch_id' => null, 'error' => $error];
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
    $items = dbFetchAll("SELECT id, payload FROM mission_scenario_items WHERE phase_id = ? AND sent_at IS NULL ORDER BY seq, id", [$phaseId]);
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
        "SELECT i.id, i.payload, i.sent_at, i.last_error, p.played_at
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
