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
 * quietly widened to everyone. Slots for teams that do not exist yet are a later step.
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

/**
 * The whole scenario of a mission for the panel and the map: phases in order, each with its items. Plain arrays
 * for json_encode(). `n` is the number shown ("Φάση n"): the position in the list, so a deleted phase leaves no
 * gap. An item's team_label is null for «όλες οι ομάδες»; team_missing is true when it names a team that is no
 * longer in the mission (it will fail at play).
 */
function loadMissionScenario(int $missionId): array {
    $phases = dbFetchAll(
        "SELECT p.id, p.seq, p.title, p.played_at, p.played_by, u.name AS played_by_name
         FROM mission_scenario_phases p
         LEFT JOIN users u ON u.id = p.played_by
         WHERE p.mission_id = ?
         ORDER BY p.seq, p.id",
        [$missionId]
    );
    if (!$phases) {
        return ['phases' => []];
    }
    $items = dbFetchAll(
        "SELECT id, phase_id, seq, kind, payload, sent_at, sent_ref_id, last_error
         FROM mission_scenario_items WHERE mission_id = ? ORDER BY phase_id, seq, id",
        [$missionId]
    );
    $teamLabels = [];
    foreach (dbFetchAll("SELECT id, codename, team_number FROM mission_teams WHERE mission_id = ?", [$missionId]) as $team) {
        $teamLabels[(int) $team['id']] = teamLabel($team['codename'], $team['team_number']);
    }

    $byPhase = [];
    foreach ($items as $row) {
        $payload = json_decode((string) $row['payload'], true);
        if (!is_array($payload)) {
            $payload = [];
        }
        $audience = ($payload['audience'] ?? '') === 'all' ? 'all' : 'team';
        $teamId = $audience === 'team' ? (int) ($payload['team_id'] ?? 0) : 0;
        $byPhase[(int) $row['phase_id']][] = [
            'id'          => (int) $row['id'],
            'kind'        => $row['kind'],
            'audience'    => $audience,
            'team_id'     => $teamId ?: null,
            'team_label'  => $audience === 'all' ? null : ($teamLabels[$teamId] ?? null),
            'team_missing' => $audience === 'team' && !isset($teamLabels[$teamId]),
            'type'        => $payload['type'] ?? null,
            'geo'         => $payload['geo'] ?? null,
            'label'       => $payload['label'] ?? null,
            'travel_mode' => $payload['travel_mode'] ?? null,
            'sent'        => $row['sent_at'] !== null,
            'sent_at'     => $row['sent_at'],
            'dispatch_id' => $row['sent_ref_id'] !== null ? (int) $row['sent_ref_id'] : null,
            'error'       => $row['last_error'],
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
    return ['phases' => $out];
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
 * or the word 'all' for every team - anything else is refused, an empty team is NOT "all"), type ('point' or
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
    if ($teamRaw === 'all') {
        $audience = 'all';
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
        'travel_mode' => $prepared['travel_mode'],
    ];
    $seq = 1 + (int) dbFetchValue("SELECT COALESCE(MAX(seq), 0) FROM mission_scenario_items WHERE phase_id = ?", [$phaseId]);
    $id = (int) dbInsert(
        "INSERT INTO mission_scenario_items (phase_id, mission_id, seq, kind, payload, created_by, created_at) VALUES (?, ?, ?, 'dispatch', ?, ?, NOW())",
        [$phaseId, $missionId, $seq, json_encode($payload), $userId]
    );
    logAudit('stage_scenario_item', 'mission_scenario_items', $id, null, ['mission_id' => $missionId, 'phase_id' => $phaseId, 'type' => $payload['type'], 'audience' => $audience, 'team_id' => $payload['team_id']]);
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
 * ONLY when the payload says audience 'all'; an audience 'team' without a real team id is null, never ''.
 */
function scenarioDispatchInputFromPayload($payload): ?array {
    if (!is_array($payload) || !isset($payload['type'], $payload['geo'])) {
        return null;
    }
    if (($payload['audience'] ?? '') === 'all') {
        $teamId = '';
    } elseif (($payload['audience'] ?? '') === 'team' && (int) ($payload['team_id'] ?? 0) > 0) {
        $teamId = (string) (int) $payload['team_id'];
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
    $input = scenarioDispatchInputFromPayload(json_decode((string) $item['payload'], true));
    if ($input === null) {
        $result = ['ok' => false, 'error' => t('scenario.item_unreadable')];
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
 * stop the rest. Returns ['ok' => false, 'error' => text], or ['ok' => true, 'sent' => n, 'failed' => n,
 * 'results' => [per item, see scenarioSendItem()]].
 */
function playScenarioPhase(array $mission, int $phaseId, int $userId, string $userName): array {
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
