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
 * Finding the victim (v3.506.0). Within CLOSE_M of the spot the phone says «κοντά», within FOUND_M «βρέθηκε». A phone's GPS
 * error is credited to the volunteer, capped so one bad fix cannot find the victim from afar. The same three numbers and the
 * same rule are in assets/js/training-case-find.js; a test keeps them equal.
 */
const TRAINING_CASE_FOUND_M = 10;
const TRAINING_CASE_CLOSE_M = 50;
const TRAINING_CASE_ACCURACY_CREDIT_M = 15;

/** 'found', 'close' or 'far' for a distance in metres and the fix's reported accuracy in metres (null = unknown). */
function trainingCaseProximity(float $distanceM, ?float $accuracyM): string {
    $credit = min(max((float) $accuracyM, 0.0), (float) TRAINING_CASE_ACCURACY_CREDIT_M);
    $effective = $distanceM - $credit;
    if ($effective <= TRAINING_CASE_FOUND_M) {
        return 'found';
    }
    return $effective <= TRAINING_CASE_CLOSE_M ? 'close' : 'far';
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
    // The victim's spot (v3.506.0). A staged block carries it; an older staged POINT order (staged before this release) has
    // none, and its own point is the spot. An area order without one gets none and keeps opening on arrival.
    $spot = is_array($case['spot'] ?? null) && is_numeric($case['spot']['lat'] ?? null) && is_numeric($case['spot']['lng'] ?? null)
        ? [(float) $case['spot']['lat'], (float) $case['spot']['lng']] : null;
    $hidden = $spot !== null && !empty($case['spot_hidden']) ? 1 : 0;
    if ($spot === null) {
        $order = dbFetchOne("SELECT type, geo FROM mission_dispatch_points WHERE id = ? AND mission_id = ?", [$dispatchId, $missionId]);
        $geo = $order ? json_decode((string) $order['geo'], true) : null;
        if ($order && $order['type'] === 'point' && is_array($geo) && isset($geo['lat'], $geo['lng'])) {
            $spot = [(float) $geo['lat'], (float) $geo['lng']];
        }
    }
    return (int) dbInsert(
        "INSERT INTO mission_case_placements (mission_id, scenario_item_id, dispatch_id, case_id, title, snapshot, audience, team_id, briefing, draw_mode, spot_lat, spot_lng, spot_hidden)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        [
            $missionId, $itemId, $dispatchId,
            $caseId > 0 && dbFetchValue("SELECT 1 FROM training_cases WHERE id = ?", [$caseId]) ? $caseId : null,
            mb_substr((string) ($case['title'] ?? ($case['document']['title'] ?? '')), 0, 160),
            json_encode($case['document'], JSON_UNESCAPED_UNICODE),
            $teamId !== null && $teamId !== false ? 'team' : 'all',
            $teamId !== null && $teamId !== false ? (int) $teamId : null,
            mb_substr((string) ($case['briefing'] ?? ''), 0, 300),
            ($case['draw_mode'] ?? '') === 'random' ? 'random' : 'chosen',
            $spot ? round($spot[0], 6) : null, $spot ? round($spot[1], 6) : null, $hidden,
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

/** Has this team (or lone volunteer: the scope key from dispatchProgressScopeKey()) found the victim of the placement? */
function trainingCaseTeamFound(int $placementId, string $scopeKey): bool {
    return (bool) dbFetchValue("SELECT 1 FROM mission_case_finds WHERE placement_id = ? AND scope_key = ?", [$placementId, $scopeKey]);
}

/**
 * May this volunteer open (and, with $needArrival, play) this placement? Returns null when yes, else the t() key of
 * why not. Checked on every request of the endpoint: a drill only, the order still exists, the person is an approved
 * participant of the mission and in the order's audience (its team, or any team for an order to every team), and - to
 * PLAY - their team has arrived (a point order) or found the victim (arrival is a team fact, see recordDispatchProgress()).
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
        // Found is the team's, like arrival (v3.506.0): once anyone on the team has found the victim, the whole team may play.
        if (trainingCaseTeamFound((int) $placement['id'], dispatchProgressScopeKey($myTeam, $userId))) {
            return null;
        }
        // An area order hides its victim: arriving somewhere in the area proves nothing, only finding opens it.
        if (!empty($placement['spot_hidden'])) {
            return 'case.err_not_found_yet';
        }
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
        return ['ok' => false, 'error' => t('case.err_gone'), 'code' => 'not_found'];
    }
    $gate = trainingCaseGate($placement, $userId, true);
    if ($gate !== null) {
        return ['ok' => false, 'error' => t($gate), 'code' => 'refused'];
    }
    dbExecute(
        "INSERT IGNORE INTO mission_case_runs (placement_id, user_id, current_step) VALUES (?, ?, ?)",
        [$placementId, $userId, $placement['doc']['start']]
    );
    return ['ok' => true, 'run' => trainingCaseRunState($placementId, $userId)];
}

/**
 * A volunteer's phone reports that the team has found the victim (v3.506.0). Needs a placement the volunteer may open (the gate,
 * without the arrival) and that has a spot. The server does not take the phone's word: it measures the reported position against
 * the spot with the same rule as the phone (trainingCaseProximity()), so a hand-made request from the sofa cannot open a hidden
 * case. A report for a team that already found it is a quiet duplicate. The first find is audited and the rest of the team told.
 * ['ok' => true, 'duplicate' => bool] or ['ok' => false, 'error' => text, 'code' => 'refused'|'not_found']. A refusal carries the
 * code 'refused' on purpose: that is what makes the phone's offline queue DROP the report instead of retrying it for ever.
 */
function trainingCaseRecordFound(int $placementId, int $userId, float $lat, float $lng, ?float $accuracyM): array {
    $placement = trainingCasePlacement($placementId);
    if (!$placement || !$placement['doc']) {
        return ['ok' => false, 'error' => t('case.err_gone'), 'code' => 'not_found'];
    }
    $gate = trainingCaseGate($placement, $userId, false);
    if ($gate !== null) {
        return ['ok' => false, 'error' => t($gate), 'code' => 'refused'];
    }
    $missionId = (int) $placement['mission_id'];
    $teamId = getUserTeamIdForMission($missionId, $userId) ?: null;
    $scopeKey = dispatchProgressScopeKey($teamId, $userId);
    if (trainingCaseTeamFound($placementId, $scopeKey)) {
        return ['ok' => true, 'duplicate' => true]; // a late teammate's report, whatever its fix: nothing more to record
    }
    if ($placement['spot_lat'] === null || $placement['spot_lng'] === null) {
        return ['ok' => false, 'error' => t('case.err_no_spot'), 'code' => 'refused'];
    }
    $distance = gpsDistanceMeters($lat, $lng, (float) $placement['spot_lat'], (float) $placement['spot_lng']);
    if (trainingCaseProximity($distance, $accuracyM) !== 'found') {
        return ['ok' => false, 'error' => t('case.err_too_far'), 'code' => 'refused'];
    }
    $inserted = dbExecute(
        "INSERT IGNORE INTO mission_case_finds (placement_id, scope_key, found_by, lat, lng, accuracy_m) VALUES (?, ?, ?, ?, ?, ?)",
        [$placementId, $scopeKey, $userId, round($lat, 6), round($lng, 6), $accuracyM !== null ? (int) round(min(max($accuracyM, 0.0), 65000.0)) : null]
    );
    if ($inserted > 0) {
        // The find is recorded: an audit or notice that fails must not turn it into a server_error the phone would retry.
        try {
            logAudit('team_found_case', 'mission_case_placements', $placementId, null, ['mission_id' => $missionId, 'team_id' => $teamId, 'user_id' => $userId]);
            if ($placement['dispatch_id'] !== null) {
                trainingCaseNotifyFound($missionId, (int) $placement['dispatch_id'], $teamId, $userId);
            }
        } catch (Throwable $e) {
            error_log('[training-cases] found audit/notice failed: ' . $e->getMessage());
        }
    }
    return ['ok' => true, 'duplicate' => $inserted === 0];
}

/**
 * Runs $work inside a transaction: its own when none is open, the caller's otherwise (a test runs every case inside
 * one transaction that it rolls back). Anything thrown undoes the transaction this call opened and goes on up.
 */
function trainingCaseTransaction(callable $work) {
    $pdo = db();
    $ownTransaction = !$pdo->inTransaction();
    if ($ownTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $result = $work();
        if ($ownTransaction && $pdo->inTransaction()) {
            $pdo->commit();
        }
        return $result;
    } catch (Throwable $e) {
        if ($ownTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Brings a run up to date with the answers stored for it: the points from their stored ratings (best 2, acceptable 1,
 * harmful 0), the maximum as 2 per answered step, and - from the snapshot's `next` of the LAST answered step - where
 * the run stands or that it is finished. Used to finish the write a request that died after storing an answer never
 * made. Returns the refreshed run row. Call it with the run row locked.
 */
function trainingCaseCatchUpRun(array $run, string $lastStepId, array $lastChoice): array {
    $points = 0;
    $answered = 0;
    // A plain read, on purpose: the caller holds the run row's lock, and the transaction's snapshot only starts at its first
    // plain read - which is after that lock wait - so this sees every answer committed so far. (A FOR UPDATE here would
    // gap-lock when nothing matches and deadlock against another run's insert.)
    foreach (dbFetchAll("SELECT rating FROM mission_case_answers WHERE run_id = ?", [(int) $run['id']]) as $a) {
        $points += trainingCaseRatingPoints((string) $a['rating']);
        $answered++;
    }
    dbExecute(
        "UPDATE mission_case_runs SET score_points = ?, score_max = ?, current_step = ?,
                finished_at = " . ($lastChoice['next'] === null ? 'COALESCE(finished_at, NOW())' : 'NULL') . "
         WHERE id = ?",
        [$points, 2 * $answered, $lastChoice['next'] ?? $lastStepId, (int) $run['id']]
    );
    return dbFetchOne("SELECT * FROM mission_case_runs WHERE id = ? FOR UPDATE", [(int) $run['id']]);
}

/**
 * One decision. The rating, the feedback and the next step are read from the SNAPSHOT, never from the caller; the
 * score is added here. Idempotent: an answer to a step that already has one returns the stored result with
 * duplicate = true and adds nothing (a double tap, an offline replay). Only the run's current step can be
 * answered, and a finished run takes nothing.
 *
 * The answer and the run's update are one transaction with the run row locked, so a request that dies cannot leave
 * the answer without its score. Should one ever be left that way anyway (an older request, a failed commit), the
 * next call for that step finishes the run's update from the stored answer instead of answering 'duplicate' and
 * leaving the volunteer stuck on a step that can no longer be answered.
 *
 * A step or choice that is not in the snapshot, or a first answer that is not for the start step, is refused BEFORE
 * any run is created: a bad request from an arrived volunteer must not turn their card to «running».
 */
function trainingCaseAnswer(int $placementId, int $userId, string $stepId, string $choiceId, ?int $msTaken = null): array {
    $placement = trainingCasePlacement($placementId);
    if (!$placement || !$placement['doc']) {
        return ['ok' => false, 'error' => t('case.err_gone'), 'code' => 'not_found'];
    }
    $gate = trainingCaseGate($placement, $userId, true);
    if ($gate !== null) {
        return ['ok' => false, 'error' => t($gate), 'code' => 'refused'];
    }
    $doc = $placement['doc'];
    $step = $doc['steps'][$stepId] ?? null;
    $choiceIn = function (?array $step, string $id): ?array {
        foreach ($step['choices'] ?? [] as $candidate) {
            if ($candidate['id'] === $id) {
                return $candidate;
            }
        }
        return null;
    };
    $choice = $choiceIn($step, $choiceId);
    // Every refusal carries code 'refused': the phone's offline queue drops an answer only on that code (see queueOutcome()
    // in assets/js/training-case.js); anything else it keeps and retries.
    $bad = ['ok' => false, 'error' => t('case.err_bad_answer'), 'code' => 'refused'];
    $result = function (array $c, bool $duplicate, array $runRow) use ($stepId): array {
        return [
            'ok' => true, 'duplicate' => $duplicate, 'step_id' => $stepId, 'choice_id' => $c['id'], 'rating' => $c['rating'],
            'feedback' => $c['feedback'], 'next' => $c['next'], 'finished' => $runRow['finished_at'] !== null,
        ] + trainingCaseScoreFields($runRow);
    };

    // The existence check is a plain read and runs BEFORE the transaction (autocommit): InnoDB opens the REPEATABLE READ
    // snapshot at a transaction's first plain read, and the run's lock below may have to WAIT for another request that is
    // answering - which commits its answer meanwhile. A snapshot opened before that wait would not see the answer (nor
    // the advanced run) and this request would be refused as «not valid now» instead of answered «duplicate».
    $runExists = (bool) dbFetchValue("SELECT 1 FROM mission_case_runs WHERE placement_id = ? AND user_id = ?", [$placementId, $userId]);

    return trainingCaseTransaction(function () use ($placementId, $userId, $stepId, $choiceId, $msTaken, $doc, $step, $choice, $choiceIn, $bad, $result, $runExists) {
        // The first statement that touches data in here is the run row's locking read (preceded, for a first answer, by the
        // insert of the run, which is a write and opens no snapshot). Every plain read after it starts its snapshot once
        // the lock is held, so it sees the latest committed state. Nothing on mission_case_answers is a locking read: one
        // that matches no row takes a gap lock, and the insert that follows would deadlock against another run's (1213).
        //
        // No run yet: only a valid first answer may create one. The run row is created BEFORE the locking read: a
        // locking read of a row that is not there takes a gap lock, and two first answers at once deadlock on it (1213).
        if (!$runExists) {
            if ($step === null || $choice === null || $stepId !== $doc['start']) {
                return $bad;
            }
            // ON DUPLICATE KEY UPDATE (a no-op) takes the row's X lock straight away; INSERT IGNORE takes a shared lock on
            // a duplicate that has to be upgraded, and three first answers at once deadlock on that upgrade.
            dbExecute("INSERT INTO mission_case_runs (placement_id, user_id, current_step) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE id = id", [$placementId, $userId, $doc['start']]);
        }
        $run = dbFetchOne("SELECT * FROM mission_case_runs WHERE placement_id = ? AND user_id = ? FOR UPDATE", [$placementId, $userId]);
        if (!$run) {
            return $bad;
        }

        $stored = dbFetchOne("SELECT choice_id FROM mission_case_answers WHERE run_id = ? AND step_id = ?", [(int) $run['id'], $stepId]);
        if ($stored) {
            $storedChoice = $choiceIn($step, (string) $stored['choice_id']);
            if ($storedChoice === null) {
                return ['ok' => false, 'error' => t('case.err_gone'), 'code' => 'refused'];
            }
            // The answer is there but the run still stands at its step: the update that should have followed it never ran.
            if ($run['current_step'] === $stepId && $run['finished_at'] === null) {
                $run = trainingCaseCatchUpRun($run, $stepId, $storedChoice);
            }
            return $result($storedChoice, true, $run);
        }
        if ($step === null || $choice === null || $run['finished_at'] !== null || $run['current_step'] !== $stepId) {
            return $bad;
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
        } else {
            // Somebody else's answer for this step got in first: the STORED choice is the result, not this caller's.
            $stored = dbFetchOne("SELECT choice_id FROM mission_case_answers WHERE run_id = ? AND step_id = ?", [(int) $run['id'], $stepId]);
            $choice = $stored ? $choiceIn($step, (string) $stored['choice_id']) : null;
            if ($choice === null) {
                return ['ok' => false, 'error' => t('case.err_gone'), 'code' => 'refused'];
            }
        }
        $run = dbFetchOne("SELECT * FROM mission_case_runs WHERE id = ? FOR UPDATE", [(int) $run['id']]);
        return $result($choice, $inserted === 0, $run);
    });
}

/** Is this the database's "table does not exist" error (SQLSTATE 42S02, MySQL 1146)? */
function trainingCaseIsMissingTable(Throwable $e): bool {
    if (!$e instanceof PDOException) {
        return false;
    }
    $info = $e->errorInfo ?? [];
    return ($info[0] ?? $e->getCode()) === '42S02' || (int) ($info[1] ?? 0) === 1146 || stripos($e->getMessage(), "doesn't exist") !== false;
}

/** Is this a MySQL deadlock (1213) or lock-wait timeout (1205)? Those are worth one retry of the whole transaction. */
function trainingCaseIsLockError(Throwable $e): bool {
    if (!$e instanceof PDOException) {
        return false;
    }
    $code = (int) (($e->errorInfo ?? [])[1] ?? 0);
    return $code === 1213 || $code === 1205;
}

/** error_log()s $message the first time $key is seen in this process; returns whether it logged. */
function trainingCaseLogOnce(string $key, string $message): bool {
    static $seen = [];
    if (isset($seen[$key])) {
        return false;
    }
    $seen[$key] = true;
    error_log($message);
    return true;
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
            "SELECT p.id, p.dispatch_id, p.mission_id, p.title, p.briefing, p.spot_lat, p.spot_lng, p.spot_hidden,
                    r.finished_at, r.current_step, r.score_points, r.score_max, r.id AS run_id
             FROM mission_case_placements p
             LEFT JOIN mission_case_runs r ON r.placement_id = p.id AND r.user_id = ?
             WHERE p.dispatch_id IN ($in)",
            array_merge([$userId], array_map('intval', $dispatchIds))
        );
        // Which of these the volunteer's team has already found (v3.506.0): the scope is the team's of that mission.
        $found = [];
        $scopeOfPlacement = [];
        $scopeByMission = [];
        foreach ($rows as $r) {
            $m = (int) $r['mission_id'];
            $scopeByMission[$m] ??= dispatchProgressScopeKey(getUserTeamIdForMission($m, $userId) ?: null, $userId);
            $scopeOfPlacement[(int) $r['id']] = $scopeByMission[$m];
        }
        if ($scopeOfPlacement) {
            $placementIds = array_keys($scopeOfPlacement);
            $inP = implode(',', array_fill(0, count($placementIds), '?'));
            foreach (dbFetchAll("SELECT placement_id, found_at, scope_key FROM mission_case_finds WHERE placement_id IN ($inP)", $placementIds) as $f) {
                if (($scopeOfPlacement[(int) $f['placement_id']] ?? null) === $f['scope_key']) {
                    $found[(int) $f['placement_id']] = $f['found_at'];
                }
            }
        }
    } catch (Throwable $e) {
        // A site that has not migrated yet has no case tables: that is expected and silent (the Action Room polls every
        // few seconds, and a per-process once-only log would still repeat on every request). Anything else is logged.
        if (!trainingCaseIsMissingTable($e)) {
            trainingCaseLogOnce('cards', '[training-cases] cards: ' . $e->getMessage());
        }
        return [];
    }
    $out = [];
    foreach ($rows as $row) {
        $state = $row['run_id'] === null ? 'new' : ($row['finished_at'] !== null ? 'done' : 'running');
        $hidden = (int) $row['spot_hidden'] === 1;
        $foundAt = $found[(int) $row['id']] ?? null;
        $out[(int) $row['dispatch_id']] = [
            'placement_id' => (int) $row['id'],
            // A hidden victim's case title could give it away: the card says the neutral word until the team has found it.
            'title' => $hidden && $foundAt === null ? t('case.default_label') : $row['title'],
            'briefing' => (string) $row['briefing'], 'state' => $state,
            'percent' => $state === 'done' ? trainingCaseScorePercent((int) $row['score_points'], (int) $row['score_max']) : null,
            'label' => $state === 'done' ? trainingCaseScoreLabel((int) $row['score_points'], (int) $row['score_max']) : null,
            'spot' => $row['spot_lat'] !== null && $row['spot_lng'] !== null ? ['lat' => (float) $row['spot_lat'], 'lng' => (float) $row['spot_lng']] : null,
            'hidden' => $hidden, 'found' => $foundAt !== null, 'found_at' => $foundAt,
        ];
    }
    return $out;
}

/**
 * Tells the other on-duty members of a team that a case is now open for them (arrival is the team's, and so is a find, so only one
 * person's press or GPS fix records it). Never throws; sends nothing for an order without a case.
 */
function trainingCaseNotifyTeam(int $missionId, int $dispatchId, ?int $teamId, int $exceptUserId, string $titleKey, string $messageKey): void {
    try {
        $placement = trainingCasePlacementForDispatch($dispatchId);
        if (!$placement || !$teamId) {
            return;
        }
        $members = array_map('intval', array_column(
            dbFetchAll("SELECT user_id FROM mission_team_members WHERE mission_id = ? AND team_id = ?", [$missionId, $teamId]),
            'user_id'
        ));
        $recipients = array_values(array_diff(array_intersect(activeMissionRecipientIds($missionId), $members), [$exceptUserId]));
        foreach ($recipients as $uid) {
            $lang = getUserLanguage($uid);
            sendNotification($uid, t($titleKey, [], $lang), t($messageKey, ['title' => (string) $placement['briefing'] !== '' ? $placement['briefing'] : $placement['title']], $lang), 'info', '', [
                'url' => rtrim(BASE_URL, '/') . '/war-room.php?id=' . $missionId,
                'tag' => 'case-' . (int) $placement['id'],
                'bannerMission' => $missionId,
            ]);
        }
    } catch (Throwable $e) {
        error_log('[training-cases] team notice: ' . $e->getMessage());
    }
}

/**
 * The team has just arrived at a case's order: tell its other on-duty members that the case is open. A hidden victim is not
 * opened by arriving (the gate says err_not_found_yet), so an area order sends nothing here; the find notice does that later.
 */
function trainingCaseNotifyArrival(int $missionId, int $dispatchId, ?int $teamId, int $arrivedUserId): void {
    try {
        $placement = trainingCasePlacementForDispatch($dispatchId);
        if ($placement && !empty($placement['spot_hidden'])) {
            return;
        }
    } catch (Throwable $e) {
        error_log('[training-cases] team notice: ' . $e->getMessage());
        return;
    }
    trainingCaseNotifyTeam($missionId, $dispatchId, $teamId, $arrivedUserId, 'case.arrived_title', 'case.arrived_message');
}

/** The team has just found the victim of a case: tell its other on-duty members that the case is open. */
function trainingCaseNotifyFound(int $missionId, int $dispatchId, ?int $teamId, int $foundByUserId): void {
    trainingCaseNotifyTeam($missionId, $dispatchId, $teamId, $foundByUserId, 'case.found_title', 'case.found_message');
}

/**
 * The «Περιστατικά» card for command: for each case placed in the drill, every volunteer in its audience with their state
 * (new / running at a step / done with a score) and, for each step, how many chose each option (for the group debrief).
 * ['placements' => [['id', 'title', 'label', 'audience', 'volunteers' => [['user_id', 'name', 'state', 'step', 'score_points',
 * 'score_max', 'percent', 'label', 'finished_at', 'answers' => [['step_id', 'choice_id', 'choice_label', 'rating', 'ms_taken']]]],
 * 'steps' => [['step_id', 'prompt', 'choices' => [['id', 'label', 'rating', 'count']]]],
 * 'hidden' => bool (the victim's spot is hidden: the case opens on a find),
 * 'finds' => [['scope_key', 'found_at', 'by']] (one per team that found the victim; 'by' is the finder's name, or null when that
 * user was deleted)]]].
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
        // Who is listed: the approved participants in the audience, PLUS anyone who has a run of this placement even if
        // they are no longer approved - their answers are in the per-step counts, so their row must be here too.
        $teamed = $placement['team_id'] !== null;
        $people = dbFetchAll(
            "SELECT u.id, u.name FROM users u WHERE u.id IN (
                 SELECT pr.volunteer_id FROM participation_requests pr JOIN shifts s ON s.id = pr.shift_id
                 WHERE s.mission_id = ? AND pr.status = ?"
            . ($teamed ? " AND EXISTS (SELECT 1 FROM mission_team_members m WHERE m.mission_id = s.mission_id AND m.team_id = ? AND m.user_id = pr.volunteer_id)" : "")
            . "  UNION
                 SELECT r.user_id FROM mission_case_runs r WHERE r.placement_id = ?
             ) ORDER BY u.name",
            array_merge([$missionId, PARTICIPATION_APPROVED], $teamed ? [(int) $placement['team_id']] : [], [(int) $placement['id']])
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
        $finds = [];
        foreach (dbFetchAll(
            "SELECT f.scope_key, f.found_at, u.name AS by_name FROM mission_case_finds f LEFT JOIN users u ON u.id = f.found_by
             WHERE f.placement_id = ? ORDER BY f.found_at, f.id",
            [(int) $placement['id']]
        ) as $f) {
            $finds[] = ['scope_key' => $f['scope_key'], 'found_at' => $f['found_at'], 'by' => $f['by_name']];
        }
        $out[] = [
            'id' => (int) $placement['id'], 'title' => $placement['title'], 'label' => (string) ($row['dispatch_label'] ?? ''),
            'audience' => $placement['audience'], 'volunteers' => $volunteers, 'steps' => $steps,
            'hidden' => !empty($placement['spot_hidden']), 'finds' => $finds,
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
