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
 * the placement id, or null when the payload carries no case or the mission is not a training mission.
 */
function trainingCaseCreatePlacement(int $missionId, int $itemId, int $dispatchId, array $payload): ?int {
    $case = $payload['case'] ?? null;
    if (!is_array($case) || !is_array($case['document'] ?? null)) {
        return null;
    }
    // Training only is checked at play too, not just at staging: a real mission never gets a placement.
    if (!isTrainingMission($missionId)) {
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

/**
 * May this volunteer open (and, with $needArrival, play) this placement? Returns null when yes, else the t() key of
 * why not. Checked on every request of the endpoint: a drill only, the order still exists, the person is an approved
 * participant of the mission and in the order's audience (its team, or any team for an order to every team), and - to
 * PLAY - their team has arrived (arrival is a team fact, see recordDispatchProgress()).
 */
function trainingCaseGate(array $placement, int $userId, bool $needArrival): ?string {
    $missionId = (int) $placement['mission_id'];
    if (!isTrainingMission($missionId)) {
        return 'case.err_not_training';
    }
    if ($placement['dispatch_id'] === null || !dbFetchValue("SELECT 1 FROM mission_dispatch_points WHERE id = ? AND mission_id = ?", [(int) $placement['dispatch_id'], $missionId])) {
        return 'case.err_gone';
    }
    $participant = dbFetchValue(
        "SELECT 1 FROM participation_requests pr JOIN shifts s ON s.id = pr.shift_id
         WHERE s.mission_id = ? AND pr.volunteer_id = ? AND pr.status = ? LIMIT 1",
        [$missionId, $userId, PARTICIPATION_APPROVED]
    );
    if (!$participant) {
        return 'case.err_not_participant';
    }
    $myTeam = getUserTeamIdForMission($missionId, $userId);
    if ($placement['team_id'] !== null && (int) $placement['team_id'] !== $myTeam) {
        return 'case.err_not_audience';
    }
    if ($needArrival) {
        $arrived = dbFetchValue(
            "SELECT 1 FROM mission_dispatch_progress WHERE dispatch_id = ? AND scope_key = ? AND (arrived_at IS NOT NULL OR completed_at IS NOT NULL)",
            [(int) $placement['dispatch_id'], dispatchProgressScopeKey($myTeam, $userId)]
        ) || dbFetchValue(
            $myTeam ? "SELECT 1 FROM mission_dispatch_acks WHERE dispatch_id = ? AND team_id = ? LIMIT 1"
                    : "SELECT 1 FROM mission_dispatch_acks WHERE dispatch_id = ? AND user_id = ? AND team_id IS NULL LIMIT 1",
            [(int) $placement['dispatch_id'], $myTeam ?: $userId]
        );
        if (!$arrived) {
            return 'case.err_not_arrived';
        }
    }
    return null;
}

/** Score fields for a run row. */
function trainingCaseScoreFields(array $run): array {
    $points = (int) $run['score_points'];
    $max = (int) $run['score_max'];
    return ['score_points' => $points, 'score_max' => $max, 'percent' => trainingCaseScorePercent($points, $max), 'label' => trainingCaseScoreLabel($points, $max)];
}

/** One volunteer's run of a placement, with what they have answered so far; null when they have not started. */
function trainingCaseRunState(int $placementId, int $userId): ?array {
    $run = dbFetchOne("SELECT * FROM mission_case_runs WHERE placement_id = ? AND user_id = ?", [$placementId, $userId]);
    if (!$run) {
        return null;
    }
    $answers = dbFetchAll("SELECT step_id, choice_id, rating FROM mission_case_answers WHERE run_id = ? ORDER BY id", [(int) $run['id']]);
    return [
        'run_id' => (int) $run['id'], 'current_step' => $run['current_step'], 'finished' => $run['finished_at'] !== null,
        'answers' => $answers,
    ] + trainingCaseScoreFields($run);
}

/** Starts (or returns) the volunteer's run. Idempotent; needs the team to have arrived. */
function trainingCaseStartRun(int $placementId, int $userId): array {
    $placement = trainingCasePlacement($placementId);
    if (!$placement || !$placement['doc']) {
        return ['ok' => false, 'error' => t('case.err_gone')];
    }
    $gate = trainingCaseGate($placement, $userId, true);
    if ($gate !== null) {
        return ['ok' => false, 'error' => t($gate)];
    }
    dbExecute(
        "INSERT IGNORE INTO mission_case_runs (placement_id, user_id, current_step) VALUES (?, ?, ?)",
        [$placementId, $userId, $placement['doc']['start']]
    );
    return ['ok' => true, 'run' => trainingCaseRunState($placementId, $userId)];
}

/**
 * One decision. The rating, the feedback and the next step are read from the SNAPSHOT, never from the caller; the
 * score is added here. Idempotent: an answer to a step that already has one returns the stored result with
 * duplicate = true and changes nothing (a double tap, an offline replay). Only the run's current step can be
 * answered, and a finished run takes nothing.
 */
function trainingCaseAnswer(int $placementId, int $userId, string $stepId, string $choiceId, ?int $msTaken = null): array {
    $placement = trainingCasePlacement($placementId);
    if (!$placement || !$placement['doc']) {
        return ['ok' => false, 'error' => t('case.err_gone')];
    }
    $gate = trainingCaseGate($placement, $userId, true);
    if ($gate !== null) {
        return ['ok' => false, 'error' => t($gate)];
    }
    $doc = $placement['doc'];
    dbExecute("INSERT IGNORE INTO mission_case_runs (placement_id, user_id, current_step) VALUES (?, ?, ?)", [$placementId, $userId, $doc['start']]);
    $run = dbFetchOne("SELECT * FROM mission_case_runs WHERE placement_id = ? AND user_id = ?", [$placementId, $userId]);

    $step = $doc['steps'][$stepId] ?? null;
    $choice = null;
    foreach ($step['choices'] ?? [] as $candidate) {
        if ($candidate['id'] === $choiceId) {
            $choice = $candidate;
        }
    }
    $result = function (array $c, bool $duplicate, array $runRow) use ($stepId): array {
        return [
            'ok' => true, 'duplicate' => $duplicate, 'step_id' => $stepId, 'choice_id' => $c['id'], 'rating' => $c['rating'],
            'feedback' => $c['feedback'], 'next' => $c['next'], 'finished' => $runRow['finished_at'] !== null,
        ] + trainingCaseScoreFields($runRow);
    };

    $stored = dbFetchOne("SELECT choice_id FROM mission_case_answers WHERE run_id = ? AND step_id = ?", [(int) $run['id'], $stepId]);
    if ($stored) {
        foreach ($step['choices'] ?? [] as $candidate) {
            if ($candidate['id'] === $stored['choice_id']) {
                return $result($candidate, true, $run);
            }
        }
        return ['ok' => false, 'error' => t('case.err_gone')];
    }
    if ($step === null || $choice === null) {
        return ['ok' => false, 'error' => t('case.err_bad_answer')];
    }
    if ($run['finished_at'] !== null || $run['current_step'] !== $stepId) {
        return ['ok' => false, 'error' => t('case.err_bad_answer')];
    }
    $inserted = dbExecute(
        "INSERT IGNORE INTO mission_case_answers (run_id, step_id, choice_id, rating, ms_taken) VALUES (?, ?, ?, ?, ?)",
        [(int) $run['id'], $stepId, $choiceId, $choice['rating'], $msTaken !== null ? max(0, min(3600000, $msTaken)) : null]
    );
    if ($inserted > 0) {
        dbExecute(
            "UPDATE mission_case_runs SET score_points = score_points + ?, score_max = score_max + 2, current_step = ?,
                    finished_at = " . ($choice['next'] === null ? 'NOW()' : 'NULL') . "
             WHERE id = ?",
            [trainingCaseRatingPoints($choice['rating']), $choice['next'] ?? $stepId, (int) $run['id']]
        );
    }
    $run = dbFetchOne("SELECT * FROM mission_case_runs WHERE id = ?", [(int) $run['id']]);
    return $result($choice, $inserted === 0, $run);
}

/**
 * What the Action Room's dispatch list carries for each order that has a case, for ONE volunteer: the placement, the
 * title and briefing, and their own state ('new', 'running', 'done' + their percent). No document: the phone fetches that
 * once (training-case.php?action=load) and keeps it. Any database error returns [] so a site that has not migrated yet
 * never loses its Action Room over this.
 */
function trainingCaseCardsForDispatches(array $dispatchIds, int $userId): array {
    if (!$dispatchIds) {
        return [];
    }
    try {
        $in = implode(',', array_fill(0, count($dispatchIds), '?'));
        $rows = dbFetchAll(
            "SELECT p.id, p.dispatch_id, p.title, p.briefing, r.finished_at, r.current_step, r.score_points, r.score_max, r.id AS run_id
             FROM mission_case_placements p
             LEFT JOIN mission_case_runs r ON r.placement_id = p.id AND r.user_id = ?
             WHERE p.dispatch_id IN ($in)",
            array_merge([$userId], array_map('intval', $dispatchIds))
        );
    } catch (Throwable $e) {
        error_log('[training-cases] cards: ' . $e->getMessage());
        return [];
    }
    $out = [];
    foreach ($rows as $row) {
        $state = $row['run_id'] === null ? 'new' : ($row['finished_at'] !== null ? 'done' : 'running');
        $out[(int) $row['dispatch_id']] = [
            'placement_id' => (int) $row['id'], 'title' => $row['title'], 'briefing' => (string) $row['briefing'], 'state' => $state,
            'percent' => $state === 'done' ? trainingCaseScorePercent((int) $row['score_points'], (int) $row['score_max']) : null,
            'label' => $state === 'done' ? trainingCaseScoreLabel((int) $row['score_points'], (int) $row['score_max']) : null,
        ];
    }
    return $out;
}

/**
 * The team has just arrived at a case's order: tell its other on-duty members that the case is open (arrival is the
 * team's, so only one person's press or GPS fix records it). Never throws; sends nothing for an order without a case.
 */
function trainingCaseNotifyArrival(int $missionId, int $dispatchId, ?int $teamId, int $arrivedUserId): void {
    try {
        $placement = trainingCasePlacementForDispatch($dispatchId);
        if (!$placement || !$teamId) {
            return;
        }
        $members = array_map('intval', array_column(
            dbFetchAll("SELECT user_id FROM mission_team_members WHERE mission_id = ? AND team_id = ?", [$missionId, $teamId]),
            'user_id'
        ));
        $recipients = array_values(array_diff(array_intersect(activeMissionRecipientIds($missionId), $members), [$arrivedUserId]));
        foreach ($recipients as $uid) {
            $lang = getUserLanguage($uid);
            sendNotification($uid, t('case.arrived_title', [], $lang), t('case.arrived_message', ['title' => (string) $placement['briefing'] !== '' ? $placement['briefing'] : $placement['title']], $lang), 'info', '', [
                'url' => rtrim(BASE_URL, '/') . '/war-room.php?id=' . $missionId,
                'tag' => 'case-' . (int) $placement['id'],
                'bannerMission' => $missionId,
            ]);
        }
    } catch (Throwable $e) {
        error_log('[training-cases] arrival notice: ' . $e->getMessage());
    }
}

/**
 * The «Περιστατικά» card for command: for each case placed in the drill, every volunteer in its audience with their state
 * (new / running at a step / done with a score) and, for each step, how many chose each option (for the group debrief).
 * ['placements' => [['id', 'title', 'label', 'audience', 'volunteers' => [['user_id', 'name', 'state', 'step', 'score_points',
 * 'score_max', 'percent', 'label', 'finished_at', 'answers' => [['step_id', 'choice_id', 'choice_label', 'rating', 'ms_taken']]]],
 * 'steps' => [['step_id', 'prompt', 'choices' => [['id', 'label', 'rating', 'count']]]]]]].
 */
function trainingCaseOverview(int $missionId): array {
    $out = [];
    $placements = dbFetchAll(
        "SELECT p.*, d.label AS dispatch_label FROM mission_case_placements p
         LEFT JOIN mission_dispatch_points d ON d.id = p.dispatch_id
         WHERE p.mission_id = ? ORDER BY p.id",
        [$missionId]
    );
    foreach ($placements as $row) {
        $placement = trainingCasePlacementWithDoc($row);
        $people = dbFetchAll(
            "SELECT DISTINCT u.id, u.name FROM participation_requests pr
             JOIN shifts s ON s.id = pr.shift_id JOIN users u ON u.id = pr.volunteer_id
             WHERE s.mission_id = ? AND pr.status = ?"
            . ($placement['team_id'] !== null ? " AND EXISTS (SELECT 1 FROM mission_team_members m WHERE m.mission_id = s.mission_id AND m.team_id = " . (int) $placement['team_id'] . " AND m.user_id = u.id)" : "")
            . " ORDER BY u.name",
            [$missionId, PARTICIPATION_APPROVED]
        );
        $runs = [];
        foreach (dbFetchAll("SELECT * FROM mission_case_runs WHERE placement_id = ?", [(int) $placement['id']]) as $run) {
            $runs[(int) $run['user_id']] = $run;
        }
        // Every answer of the placement, grouped by volunteer, with the text of the choice from the snapshot.
        $answersByUser = [];
        foreach (dbFetchAll(
            "SELECT r.user_id, a.step_id, a.choice_id, a.rating, a.ms_taken FROM mission_case_answers a
             JOIN mission_case_runs r ON r.id = a.run_id WHERE r.placement_id = ? ORDER BY a.id",
            [(int) $placement['id']]
        ) as $a) {
            $label = '';
            foreach ($placement['doc']['steps'][$a['step_id']]['choices'] ?? [] as $c) {
                if ($c['id'] === $a['choice_id']) {
                    $label = $c['label'];
                }
            }
            $answersByUser[(int) $a['user_id']][] = [
                'step_id' => $a['step_id'], 'choice_id' => $a['choice_id'], 'choice_label' => $label, 'rating' => $a['rating'],
                'ms_taken' => $a['ms_taken'] !== null ? (int) $a['ms_taken'] : null,
            ];
        }
        $volunteers = [];
        foreach ($people as $person) {
            $run = $runs[(int) $person['id']] ?? null;
            $volunteers[] = [
                'user_id' => (int) $person['id'], 'name' => $person['name'],
                'state' => $run === null ? 'new' : ($run['finished_at'] !== null ? 'done' : 'running'),
                'step' => $run['current_step'] ?? null,
                'finished_at' => $run['finished_at'] ?? null,
                'answers' => $answersByUser[(int) $person['id']] ?? [],
            ] + ($run !== null ? trainingCaseScoreFields($run) : ['score_points' => 0, 'score_max' => 0, 'percent' => 0, 'label' => null]);
        }
        $counts = [];
        foreach (dbFetchAll(
            "SELECT a.step_id, a.choice_id, COUNT(*) AS n FROM mission_case_answers a JOIN mission_case_runs r ON r.id = a.run_id
             WHERE r.placement_id = ? GROUP BY a.step_id, a.choice_id",
            [(int) $placement['id']]
        ) as $c) {
            $counts[$c['step_id']][$c['choice_id']] = (int) $c['n'];
        }
        $steps = [];
        foreach ($placement['doc']['steps'] ?? [] as $stepId => $step) {
            $steps[] = ['step_id' => (string) $stepId, 'prompt' => $step['prompt'], 'choices' => array_map(fn($c) => [
                'id' => $c['id'], 'label' => $c['label'], 'rating' => $c['rating'], 'count' => $counts[$stepId][$c['id']] ?? 0,
            ], $step['choices'])];
        }
        $out[] = [
            'id' => (int) $placement['id'], 'title' => $placement['title'], 'label' => (string) ($row['dispatch_label'] ?? ''),
            'audience' => $placement['audience'], 'volunteers' => $volunteers, 'steps' => $steps,
        ];
    }
    return ['placements' => $out];
}

/**
 * A volunteer's finished case runs per drill, for the training record: [mission id => ['cases' => n, 'percent' => int]].
 * A run that is not finished is not in the record. Reads only the case tables, never participation numbers.
 */
function trainingCaseRecordForUser(int $userId): array {
    $out = [];
    foreach (dbFetchAll(
        "SELECT p.mission_id, COUNT(*) AS n, SUM(r.score_points) AS pts, SUM(r.score_max) AS mx
         FROM mission_case_runs r JOIN mission_case_placements p ON p.id = r.placement_id
         WHERE r.user_id = ? AND r.finished_at IS NOT NULL GROUP BY p.mission_id",
        [$userId]
    ) as $row) {
        $out[(int) $row['mission_id']] = ['cases' => (int) $row['n'], 'percent' => trainingCaseScorePercent((int) $row['pts'], (int) $row['mx'])];
    }
    return $out;
}
