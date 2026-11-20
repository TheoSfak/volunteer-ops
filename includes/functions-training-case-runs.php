<?php
/**
 * VolunteerOps - Training cases: placements, runs, answers (v3.505.0).
 *
 * A PLACEMENT links one sent point order (mission_dispatch_points) to the snapshot of the case staged for it. A RUN is
 * one volunteer's attempt at a placement; an ANSWER is one decision in a run. Everything is keyed by the mission and
 * works only in training missions. See functions-training-cases.php for the document rules.
 */

if (!defined('VOLUNTEEROPS')) {
    die('Direct access not permitted');
}

/**
 * Records the placement of a case whose point order has just been sent. $payload is the staged item's payload (its
 * `case` block holds the snapshot); who it is for is read from the live order (team_id NULL = every team). Returns
 * the placement id, or null when the payload carries no case.
 */
function trainingCaseCreatePlacement(int $missionId, int $itemId, int $dispatchId, array $payload): ?int {
    $case = $payload['case'] ?? null;
    if (!is_array($case) || !is_array($case['document'] ?? null)) {
        return null;
    }
    $teamId = dbFetchValue("SELECT team_id FROM mission_dispatch_points WHERE id = ? AND mission_id = ?", [$dispatchId, $missionId]);
    $caseId = (int) ($case['case_id'] ?? 0);
    return (int) dbInsert(
        "INSERT INTO mission_case_placements (mission_id, scenario_item_id, dispatch_id, case_id, title, snapshot, audience, team_id, briefing, draw_mode)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        [
            $missionId, $itemId, $dispatchId,
            $caseId > 0 && dbFetchValue("SELECT 1 FROM training_cases WHERE id = ?", [$caseId]) ? $caseId : null,
            mb_substr((string) ($case['title'] ?? ($case['document']['title'] ?? '')), 0, 160),
            json_encode($case['document'], JSON_UNESCAPED_UNICODE),
            $teamId !== null && $teamId !== false ? 'team' : 'all',
            $teamId !== null && $teamId !== false ? (int) $teamId : null,
            mb_substr((string) ($case['briefing'] ?? ''), 0, 300),
            ($case['draw_mode'] ?? '') === 'random' ? 'random' : 'chosen',
        ]
    );
}

/** A placement with its snapshot decoded into ['doc'], or null. */
function trainingCasePlacement(int $placementId): ?array {
    $row = dbFetchOne("SELECT * FROM mission_case_placements WHERE id = ?", [$placementId]);
    return $row ? trainingCasePlacementWithDoc($row) : null;
}

function trainingCasePlacementForDispatch(int $dispatchId): ?array {
    $row = dbFetchOne("SELECT * FROM mission_case_placements WHERE dispatch_id = ?", [$dispatchId]);
    return $row ? trainingCasePlacementWithDoc($row) : null;
}

function trainingCasePlacementWithDoc(array $row): array {
    $row['doc'] = trainingCaseNormalize(json_decode((string) $row['snapshot'], true)) ?? [];
    return $row;
}
