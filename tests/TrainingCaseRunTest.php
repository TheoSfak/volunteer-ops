<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class TrainingCaseRunTest extends TestCase
{
    private array $drill;
    private int $admin;
    private int $teamId;
    private int $otherTeamId;
    private int $member;
    private int $mate;
    private int $stranger;
    private int $dispatchId;
    private int $placementId;

    protected function setUp(): void
    {
        db()->beginTransaction();
        $this->admin = TrainingFixtures::user('Admin');
        $this->drill = TrainingFixtures::mission(true);
        $this->teamId = (int) dbInsert("INSERT INTO mission_teams (mission_id, codename, team_number, created_by) VALUES (?, 'ΑΛΦΑ', 1, ?)", [$this->drill['mission'], $this->admin]);
        $this->otherTeamId = (int) dbInsert("INSERT INTO mission_teams (mission_id, codename, team_number, created_by) VALUES (?, 'ΒΗΤΑ', 2, ?)", [$this->drill['mission'], $this->admin]);
        $this->member = $this->volunteer('Member', $this->teamId);
        $this->mate = $this->volunteer('Mate', $this->teamId);
        $this->stranger = $this->volunteer('Stranger', $this->otherTeamId);

        $this->dispatchId = (int) dbInsert(
            "INSERT INTO mission_dispatch_points (mission_id, team_id, type, geo, label, created_by) VALUES (?, ?, 'point', ?, 'Περιστατικό', ?)",
            [$this->drill['mission'], $this->teamId, json_encode(['lat' => 35.3, 'lng' => 25.1]), $this->admin]
        );
        $doc = [
            'title' => 'Δοκιμή', 'story' => 'Ιστορία.', 'closing' => 'Τέλος.', 'findings' => [], 'start' => 's1',
            'steps' => [
                's1' => ['prompt' => 'Πρώτο;', 'hint' => '', 'choices' => [
                    ['id' => 'a', 'label' => 'Α', 'rating' => 'best', 'feedback' => 'Σωστά.', 'next' => 's2'],
                    ['id' => 'b', 'label' => 'Β', 'rating' => 'harmful', 'feedback' => 'Λάθος.', 'next' => 's2'],
                ]],
                's2' => ['prompt' => 'Δεύτερο;', 'hint' => '', 'choices' => [
                    ['id' => 'a', 'label' => 'Α', 'rating' => 'acceptable', 'feedback' => 'Εντάξει.', 'next' => null],
                    ['id' => 'b', 'label' => 'Β', 'rating' => 'best', 'feedback' => 'Άριστα.', 'next' => null],
                ]],
            ],
        ];
        $this->placementId = (int) trainingCaseCreatePlacement(
            $this->drill['mission'], 0, $this->dispatchId,
            ['case' => ['case_id' => 0, 'title' => 'Δοκιμή', 'draw_mode' => 'chosen', 'briefing' => 'Φωνή', 'document' => $doc]]
        );
    }

    protected function tearDown(): void
    {
        db()->rollBack();
    }

    private function volunteer(string $name, int $team): int
    {
        $u = TrainingFixtures::user($name);
        TrainingFixtures::participation($this->drill['shift'], $u, false, null);
        dbExecute("INSERT INTO mission_team_members (mission_id, team_id, user_id) VALUES (?, ?, ?)", [$this->drill['mission'], $team, $u]);
        return $u;
    }

    private function arrive(int $teamId): void
    {
        dbExecute("INSERT INTO mission_dispatch_progress (dispatch_id, team_id, scope_key, arrived_at) VALUES (?, ?, ?, NOW())", [$this->dispatchId, $teamId, 't' . $teamId]);
    }

    public function test_nobody_can_play_before_their_team_arrives(): void
    {
        $placement = trainingCasePlacement($this->placementId);
        $this->assertSame('case.err_not_arrived', trainingCaseGate($placement, $this->member, true));
        $this->assertNull(trainingCaseGate($placement, $this->member, false), 'loading the document needs no arrival');
        $this->arrive($this->teamId);
        $this->assertNull(trainingCaseGate($placement, $this->member, true));
        $this->assertNull(trainingCaseGate($placement, $this->mate, true), 'the whole team may play once the team arrived');
    }

    public function test_the_wrong_team_and_non_participants_are_refused(): void
    {
        $placement = trainingCasePlacement($this->placementId);
        $this->arrive($this->teamId);
        $this->assertSame('case.err_not_audience', trainingCaseGate($placement, $this->stranger, false));
        $outsider = TrainingFixtures::user('Outsider');
        $this->assertSame('case.err_not_participant', trainingCaseGate($placement, $outsider, false));
    }

    public function test_a_real_mission_or_a_withdrawn_order_closes_the_gate(): void
    {
        $this->arrive($this->teamId);
        dbExecute("UPDATE missions SET is_training = 0 WHERE id = ?", [$this->drill['mission']]);
        $this->assertSame('case.err_not_training', trainingCaseGate(trainingCasePlacement($this->placementId), $this->member, true));
        dbExecute("UPDATE missions SET is_training = 1 WHERE id = ?", [$this->drill['mission']]);
        dbExecute("DELETE FROM mission_dispatch_points WHERE id = ?", [$this->dispatchId]);
        $this->assertSame('case.err_gone', trainingCaseGate(trainingCasePlacement($this->placementId), $this->member, true));
    }

    public function test_a_full_run_is_scored_by_the_server(): void
    {
        $this->arrive($this->teamId);
        $first = trainingCaseAnswer($this->placementId, $this->member, 's1', 'a', 4200);
        $this->assertTrue($first['ok']);
        $this->assertSame('best', $first['rating']);
        $this->assertSame('s2', $first['next']);
        $this->assertFalse($first['finished']);
        $this->assertSame(2, $first['score_points']);
        $this->assertSame(2, $first['score_max']);

        $second = trainingCaseAnswer($this->placementId, $this->member, 's2', 'a');
        $this->assertSame('acceptable', $second['rating']);
        $this->assertTrue($second['finished']);
        $this->assertSame(3, $second['score_points']);
        $this->assertSame(4, $second['score_max']);
        $this->assertSame(75, $second['percent']);
        $this->assertSame('good', $second['label']);

        $state = trainingCaseRunState($this->placementId, $this->member);
        $this->assertTrue($state['finished']);
        $this->assertCount(2, $state['answers']);
    }

    public function test_a_repeated_answer_is_ignored_and_returns_the_stored_result(): void
    {
        $this->arrive($this->teamId);
        trainingCaseAnswer($this->placementId, $this->member, 's1', 'b');
        $again = trainingCaseAnswer($this->placementId, $this->member, 's1', 'a');
        $this->assertTrue($again['ok']);
        $this->assertTrue($again['duplicate']);
        $this->assertSame('harmful', $again['rating'], 'the first choice stands');
        $this->assertSame('b', $again['choice_id']);
        $this->assertSame(0, $again['score_points']);
        $this->assertSame(2, $again['score_max']);
    }

    public function test_out_of_order_unknown_and_finished_answers_are_refused(): void
    {
        $this->arrive($this->teamId);
        $this->assertFalse(trainingCaseAnswer($this->placementId, $this->member, 's2', 'a')['ok'], 'step 2 before step 1');
        $this->assertFalse(trainingCaseAnswer($this->placementId, $this->member, 's1', 'zzz')['ok'], 'a choice that does not exist');
        trainingCaseAnswer($this->placementId, $this->member, 's1', 'a');
        trainingCaseAnswer($this->placementId, $this->member, 's2', 'b');
        // The run is finished: a different choice for the last step changes nothing and returns the stored one.
        $again = trainingCaseAnswer($this->placementId, $this->member, 's2', 'a');
        $this->assertTrue($again['ok']);
        $this->assertTrue($again['duplicate']);
        $this->assertSame('best', $again['rating']);
        $this->assertSame(4, $again['score_points']);
    }

    public function test_each_volunteer_has_their_own_run(): void
    {
        $this->arrive($this->teamId);
        trainingCaseAnswer($this->placementId, $this->member, 's1', 'a');
        trainingCaseAnswer($this->placementId, $this->mate, 's1', 'b');
        $this->assertSame(2, trainingCaseRunState($this->placementId, $this->member)['score_points']);
        $this->assertSame(0, trainingCaseRunState($this->placementId, $this->mate)['score_points']);
        $this->assertNull(trainingCaseRunState($this->placementId, $this->stranger));
    }

    public function test_the_dispatch_row_card_reflects_the_volunteers_own_state(): void
    {
        $cards = trainingCaseCardsForDispatches([$this->dispatchId], $this->member);
        $this->assertSame('new', $cards[$this->dispatchId]['state']);
        $this->assertSame('Φωνή', $cards[$this->dispatchId]['briefing']);
        $this->arrive($this->teamId);
        trainingCaseAnswer($this->placementId, $this->member, 's1', 'a');
        $this->assertSame('running', trainingCaseCardsForDispatches([$this->dispatchId], $this->member)[$this->dispatchId]['state']);
        trainingCaseAnswer($this->placementId, $this->member, 's2', 'b');
        $done = trainingCaseCardsForDispatches([$this->dispatchId], $this->member)[$this->dispatchId];
        $this->assertSame('done', $done['state']);
        $this->assertSame(100, $done['percent']);
        $this->assertSame([], trainingCaseCardsForDispatches([], $this->member));
    }

    public function test_the_loaded_dispatch_list_carries_the_card_and_can_play(): void
    {
        $rows = loadMissionDispatchesForUser($this->drill['mission'], $this->member, false, true);
        $row = array_values(array_filter($rows, fn($r) => $r['id'] === $this->dispatchId))[0];
        $this->assertNotNull($row['case']);
        $this->assertTrue($row['case']['can_play']);
        $this->assertFalse($row['case']['available'], 'the team has not arrived');
        $this->arrive($this->teamId);
        $rows = loadMissionDispatchesForUser($this->drill['mission'], $this->member, false, true);
        $row = array_values(array_filter($rows, fn($r) => $r['id'] === $this->dispatchId))[0];
        $this->assertTrue($row['case']['available']);
    }

    public function test_the_overview_lists_every_audience_volunteer_and_the_choices_made(): void
    {
        $this->arrive($this->teamId);
        trainingCaseAnswer($this->placementId, $this->member, 's1', 'a');
        trainingCaseAnswer($this->placementId, $this->mate, 's1', 'b');
        $overview = trainingCaseOverview($this->drill['mission']);
        $this->assertCount(1, $overview['placements']);
        $p = $overview['placements'][0];
        $byUser = array_column($p['volunteers'], null, 'user_id');
        $this->assertSame('running', $byUser[$this->member]['state']);
        $this->assertSame('running', $byUser[$this->mate]['state']);
        $this->assertArrayNotHasKey($this->stranger, $byUser, 'another team is not in the audience');
        $s1 = array_column($p['steps'], null, 'step_id')['s1'];
        $counts = array_column($s1['choices'], 'count', 'id');
        $this->assertSame(1, $counts['a']);
        $this->assertSame(1, $counts['b']);
        // One run can be opened: each answer with the choice's text, its rating and the time taken.
        $answer = $byUser[$this->member]['answers'][0];
        $this->assertSame('s1', $answer['step_id']);
        $this->assertSame('Α', $answer['choice_label']);
        $this->assertSame('best', $answer['rating']);
    }

    public function test_the_record_counts_finished_runs_only(): void
    {
        $this->arrive($this->teamId);
        trainingCaseAnswer($this->placementId, $this->member, 's1', 'a');
        $this->assertSame([], trainingCaseRecordForUser($this->member), 'a run in progress is not in the record');
        trainingCaseAnswer($this->placementId, $this->member, 's2', 'a');
        $record = trainingCaseRecordForUser($this->member);
        $this->assertSame(1, $record[$this->drill['mission']]['cases']);
        $this->assertSame(75, $record[$this->drill['mission']]['percent']);
    }

    public function test_the_arrival_notice_goes_to_the_on_duty_teammates_only(): void
    {
        // "On duty" is activeMissionRecipientIds(): an approved participant whose shift is running now and who is in the Action Room.
        dbExecute("UPDATE shifts SET start_time = ?, end_time = ? WHERE id = ?", [date('Y-m-d H:i:s', time() - 3600), date('Y-m-d H:i:s', time() + 3600), $this->drill['shift']]);
        foreach ([$this->member, $this->mate, $this->stranger] as $u) {
            dbExecute("INSERT INTO mission_action_room_participants (mission_id, user_id) VALUES (?, ?)", [$this->drill['mission'], $u]);
        }

        trainingCaseNotifyArrival($this->drill['mission'], $this->dispatchId, $this->teamId, $this->member);

        $count = fn(int $u): int => (int) dbFetchValue("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND data LIKE ?", [$u, '%case-' . $this->placementId . '%']);
        $this->assertSame(1, $count($this->mate), 'the teammate is told the case is open');
        $this->assertSame(0, $count($this->member), 'the one who arrived already knows');
        $this->assertSame(0, $count($this->stranger), 'another team is not told');
    }

    public function test_an_order_without_a_case_sends_no_arrival_notice(): void
    {
        $plain = (int) dbInsert(
            "INSERT INTO mission_dispatch_points (mission_id, team_id, type, geo, created_by) VALUES (?, ?, 'point', ?, ?)",
            [$this->drill['mission'], $this->teamId, json_encode(['lat' => 35.4, 'lng' => 25.2]), $this->admin]
        );
        trainingCaseNotifyArrival($this->drill['mission'], $plain, $this->teamId, $this->member);
        $this->assertSame(0, (int) dbFetchValue("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND data LIKE '%case-%'", [$this->mate]));
    }

    // ── Task 6 review findings ─────────────────────────────────────────────────────────────────────────────

    private function runId(int $userId): int
    {
        return (int) dbFetchValue("SELECT id FROM mission_case_runs WHERE placement_id = ? AND user_id = ?", [$this->placementId, $userId]);
    }

    private function runCount(int $userId): int
    {
        return (int) dbFetchValue("SELECT COUNT(*) FROM mission_case_runs WHERE placement_id = ? AND user_id = ?", [$this->placementId, $userId]);
    }

    public function test_a_run_that_lags_behind_its_stored_answer_is_completed_by_the_next_call(): void
    {
        $this->arrive($this->teamId);
        trainingCaseStartRun($this->placementId, $this->member);
        $runId = $this->runId($this->member);
        // The request died between the two writes: the answer is stored, the run still stands at the old step with no score.
        dbExecute("INSERT INTO mission_case_answers (run_id, step_id, choice_id, rating) VALUES (?, 's1', 'a', 'best')", [$runId]);

        $healed = trainingCaseAnswer($this->placementId, $this->member, 's1', 'a');
        $this->assertTrue($healed['ok']);
        $this->assertTrue($healed['duplicate'], 'the answer itself was already stored');
        $this->assertSame('s2', $healed['next']);
        $this->assertFalse($healed['finished']);
        $this->assertSame(2, $healed['score_points']);
        $this->assertSame(2, $healed['score_max']);
        $run = dbFetchOne("SELECT * FROM mission_case_runs WHERE id = ?", [$runId]);
        $this->assertSame('s2', $run['current_step']);
        $this->assertSame(2, (int) $run['score_points']);
        $this->assertSame(2, (int) $run['score_max']);

        // A second call is a clean duplicate: nothing is scored twice, and the volunteer can go on.
        $again = trainingCaseAnswer($this->placementId, $this->member, 's1', 'a');
        $this->assertTrue($again['duplicate']);
        $this->assertSame(2, $again['score_points']);
        $this->assertSame(2, $again['score_max']);
        $this->assertSame(1, (int) dbFetchValue("SELECT COUNT(*) FROM mission_case_answers WHERE run_id = ?", [$runId]));
        $last = trainingCaseAnswer($this->placementId, $this->member, 's2', 'b');
        $this->assertTrue($last['finished']);
        $this->assertSame(4, $last['score_points']);
        $this->assertSame(4, $last['score_max']);
    }

    public function test_a_lagging_last_step_finishes_the_run(): void
    {
        $this->arrive($this->teamId);
        trainingCaseStartRun($this->placementId, $this->member);
        $runId = $this->runId($this->member);
        dbExecute("INSERT INTO mission_case_answers (run_id, step_id, choice_id, rating) VALUES (?, 's1', 'a', 'best'), (?, 's2', 'a', 'acceptable')", [$runId, $runId]);
        dbExecute("UPDATE mission_case_runs SET current_step = 's2', score_points = 2, score_max = 2 WHERE id = ?", [$runId]);

        $healed = trainingCaseAnswer($this->placementId, $this->member, 's2', 'b');
        $this->assertTrue($healed['duplicate']);
        $this->assertSame('a', $healed['choice_id'], 'the stored choice stands, not the caller\'s');
        $this->assertTrue($healed['finished']);
        $this->assertSame(3, $healed['score_points']);
        $this->assertSame(4, $healed['score_max']);
        $this->assertNotNull(dbFetchValue("SELECT finished_at FROM mission_case_runs WHERE id = ?", [$runId]));
        $this->assertSame(3, trainingCaseAnswer($this->placementId, $this->member, 's2', 'a')['score_points'], 'and a repeat adds nothing');
    }

    public function test_a_bogus_step_or_choice_does_not_start_a_run(): void
    {
        $this->arrive($this->teamId);
        $this->assertFalse(trainingCaseAnswer($this->placementId, $this->member, 'nope', 'a')['ok']);
        $this->assertFalse(trainingCaseAnswer($this->placementId, $this->member, 's1', 'zzz')['ok']);
        $this->assertFalse(trainingCaseAnswer($this->placementId, $this->member, 's2', 'a')['ok'], 'not the first step');
        $this->assertSame(0, $this->runCount($this->member), 'no run, so the card stays «new»');
        $this->assertSame('new', trainingCaseCardsForDispatches([$this->dispatchId], $this->member)[$this->dispatchId]['state']);
        // A real first answer still starts the run.
        $this->assertTrue(trainingCaseAnswer($this->placementId, $this->member, 's1', 'a')['ok']);
        $this->assertSame(1, $this->runCount($this->member));
    }

    public function test_starting_a_run_is_idempotent_and_needs_the_team_to_have_arrived(): void
    {
        $early = trainingCaseStartRun($this->placementId, $this->member);
        $this->assertFalse($early['ok']);
        $this->assertSame(t('case.err_not_arrived'), $early['error']);
        $this->assertSame(0, $this->runCount($this->member));

        $this->arrive($this->teamId);
        $first = trainingCaseStartRun($this->placementId, $this->member);
        $this->assertTrue($first['ok']);
        $this->assertSame('s1', $first['run']['current_step']);
        $this->assertFalse($first['run']['finished']);
        trainingCaseAnswer($this->placementId, $this->member, 's1', 'a');
        $second = trainingCaseStartRun($this->placementId, $this->member);
        $this->assertSame($first['run']['run_id'], $second['run']['run_id'], 'the same run comes back');
        $this->assertSame('s2', $second['run']['current_step'], 'and starting again does not rewind it');
        $this->assertSame(1, $this->runCount($this->member));
    }

    public function test_starting_a_run_is_refused_in_a_real_mission(): void
    {
        $this->arrive($this->teamId);
        dbExecute("UPDATE missions SET is_training = 0 WHERE id = ?", [$this->drill['mission']]);
        $res = trainingCaseStartRun($this->placementId, $this->member);
        $this->assertFalse($res['ok']);
        $this->assertSame(t('case.err_not_training'), $res['error']);
        $this->assertSame(0, $this->runCount($this->member));
    }

    public function test_answering_is_refused_to_a_non_audience_user_before_arrival_and_in_a_real_mission(): void
    {
        // Before the team has arrived.
        $res = trainingCaseAnswer($this->placementId, $this->member, 's1', 'a');
        $this->assertFalse($res['ok']);
        $this->assertSame(t('case.err_not_arrived'), $res['error']);

        // Another team's volunteer, even once that team has "arrived" at an order that is not theirs.
        $this->arrive($this->teamId);
        $res = trainingCaseAnswer($this->placementId, $this->stranger, 's1', 'a');
        $this->assertFalse($res['ok']);
        $this->assertSame(t('case.err_not_audience'), $res['error']);

        // A real mission.
        dbExecute("UPDATE missions SET is_training = 0 WHERE id = ?", [$this->drill['mission']]);
        $res = trainingCaseAnswer($this->placementId, $this->member, 's1', 'a');
        $this->assertFalse($res['ok']);
        $this->assertSame(t('case.err_not_training'), $res['error']);

        $this->assertSame(0, (int) dbFetchValue("SELECT COUNT(*) FROM mission_case_runs WHERE placement_id = ?", [$this->placementId]));
        $this->assertSame(0, (int) dbFetchValue("SELECT COUNT(*) FROM mission_case_answers"), 'nothing was stored');
    }

    public function test_every_refusal_carries_a_machine_code_the_phone_can_act_on(): void
    {
        // The phone's offline queue drops an answer ONLY on code 'refused' (never by comparing translated text).
        $early = trainingCaseStartRun($this->placementId, $this->member);
        $this->assertSame('refused', $early['code'], 'the gate (not arrived yet)');
        $res = trainingCaseAnswer($this->placementId, $this->member, 's1', 'a');
        $this->assertSame('refused', $res['code'], 'the gate on an answer');
        $this->assertSame(t('case.err_not_arrived'), $res['error'], 'the text is unchanged');

        $this->arrive($this->teamId);
        $this->assertSame('refused', trainingCaseAnswer($this->placementId, $this->stranger, 's1', 'a')['code'], 'not the audience');
        $bad = trainingCaseAnswer($this->placementId, $this->member, 's2', 'a');
        $this->assertFalse($bad['ok']);
        $this->assertSame('refused', $bad['code'], 'an answer that is not valid now');
        $this->assertSame('refused', trainingCaseAnswer($this->placementId, $this->member, 's1', 'zzz')['code'], 'an unknown choice');

        $missing = trainingCaseAnswer(2147483000, $this->member, 's1', 'a');
        $this->assertSame('not_found', $missing['code'], 'no such placement');
        $this->assertSame('not_found', trainingCaseStartRun(2147483000, $this->member)['code']);

        $ok = trainingCaseAnswer($this->placementId, $this->member, 's1', 'a');
        $this->assertTrue($ok['ok']);
        $this->assertArrayNotHasKey('code', $ok, 'a success carries no code');
    }

    public function test_the_dispatch_row_card_has_exactly_the_expected_keys(): void
    {
        $this->arrive($this->teamId);
        trainingCaseAnswer($this->placementId, $this->member, 's1', 'a');
        $rows = loadMissionDispatchesForUser($this->drill['mission'], $this->member, false, true);
        $row = array_values(array_filter($rows, fn($r) => $r['id'] === $this->dispatchId))[0];
        $keys = array_keys($row['case']);
        sort($keys);
        $expected = ['available', 'briefing', 'can_play', 'found', 'found_at', 'hidden', 'label', 'percent', 'placement_id', 'spot', 'state', 'title'];
        $this->assertSame($expected, $keys, 'no document, no ratings, no feedback ride on the poll');
    }

    public function test_the_overview_still_lists_a_volunteer_with_a_run_who_is_no_longer_approved(): void
    {
        $this->arrive($this->teamId);
        trainingCaseAnswer($this->placementId, $this->member, 's1', 'a');
        dbExecute("UPDATE participation_requests SET status = ? WHERE volunteer_id = ?", [PARTICIPATION_REJECTED, $this->member]);
        $p = trainingCaseOverview($this->drill['mission'])['placements'][0];
        $byUser = array_column($p['volunteers'], null, 'user_id');
        $this->assertArrayHasKey($this->member, $byUser, 'their answers are in the counts, so they are in the rows');
        $this->assertSame('running', $byUser[$this->member]['state']);
        $this->assertCount(1, $byUser[$this->member]['answers']);
        $this->assertArrayHasKey($this->mate, $byUser);
        $this->assertCount(2, $p['volunteers'], 'and nobody is listed twice');
    }

    public function test_the_missing_tables_are_logged_once_not_on_every_poll(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'tcl');
        $old = ini_set('error_log', $file);
        try {
            $this->assertTrue(trainingCaseLogOnce('test-key-' . $file, 'first'));
            $this->assertFalse(trainingCaseLogOnce('test-key-' . $file, 'second'));
            $this->assertTrue(trainingCaseLogOnce('test-key-other-' . $file, 'third'));
            $log = (string) file_get_contents($file);
            $this->assertStringContainsString('first', $log);
            $this->assertStringNotContainsString('second', $log);
        } finally {
            ini_set('error_log', $old === false ? '' : $old);
            @unlink($file);
        }
    }

    public function test_the_arrival_notice_is_sent_after_command_is_told_of_the_arrival(): void
    {
        $src = str_replace("\r\n", "\n", (string) file_get_contents(dirname(__DIR__) . '/includes/functions-warroom.php'));
        $start = strpos($src, 'function advanceMissionDispatch');
        $this->assertNotFalse($start);
        // The function's own body: up to the next top-level function declaration.
        $next = strpos($src, "\nfunction ", $start + 1);
        $body = $next === false ? substr($src, $start) : substr($src, $start, $next - $start);
        $command = strpos($body, 'notifyDispatchArrival(');
        $team = strpos($body, 'trainingCaseNotifyArrival(');
        $this->assertNotFalse($command);
        $this->assertNotFalse($team);
        $this->assertGreaterThan($command, $team, 'a slow teammate loop must not delay command\'s alert');
    }

    // ── Task 6 review findings (done with the endpoint) ──────────────────────────────────────────────────────

    public function test_a_missing_table_is_recognised_so_the_poll_stays_silent(): void
    {
        $missing = null;
        try {
            dbFetchAll("SELECT 1 FROM mission_case_table_that_does_not_exist");
        } catch (PDOException $e) {
            $missing = $e;
        }
        $this->assertNotNull($missing);
        $this->assertTrue(trainingCaseIsMissingTable($missing));
        $this->assertFalse(trainingCaseIsMissingTable(new RuntimeException('Connection refused')));
        $e = new PDOException('Deadlock found');
        $e->errorInfo = ['40001', 1213, 'Deadlock found'];
        $this->assertFalse(trainingCaseIsMissingTable($e));

        $src = str_replace("\r\n", "\n", (string) file_get_contents(dirname(__DIR__) . '/includes/functions-training-case-runs.php'));
        $from = strpos($src, 'function trainingCaseCardsForDispatches');
        $this->assertNotFalse($from);
        $catch = substr($src, strpos($src, '} catch (Throwable $e) {', $from), 400);
        $this->assertLessThan(strpos($catch, 'trainingCaseLogOnce('), strpos($catch, 'trainingCaseIsMissingTable('), 'a missing table returns [] before anything is logged');
    }

    public function test_a_first_answer_creates_the_run_before_it_locks_it(): void
    {
        // Locking a row that does not exist takes a gap lock, and two first answers at once deadlock on it.
        $src = str_replace("\r\n", "\n", (string) file_get_contents(dirname(__DIR__) . '/includes/functions-training-case-runs.php'));
        $from = strpos($src, 'function trainingCaseAnswer');
        $body = substr($src, $from, strpos($src, "\nfunction ", $from + 1) - $from);
        $insert = strpos($body, 'INSERT INTO mission_case_runs');
        $lock = strpos($body, 'mission_case_runs WHERE placement_id = ? AND user_id = ? FOR UPDATE');
        $this->assertNotFalse($insert);
        $this->assertNotFalse($lock);
        $this->assertLessThan($lock, $insert, 'the run row exists before the locking read');
    }

    public function test_the_first_run_is_created_with_an_x_lock_not_insert_ignore(): void
    {
        // INSERT IGNORE takes a shared lock on a duplicate that must be upgraded: three first answers at once deadlock on it.
        $src = str_replace("\r\n", "\n", (string) file_get_contents(dirname(__DIR__) . '/includes/functions-training-case-runs.php'));
        $from = strpos($src, 'function trainingCaseAnswer');
        $body = substr($src, $from, strpos($src, "\nfunction ", $from + 1) - $from);
        $this->assertStringContainsString('INSERT INTO mission_case_runs (placement_id, user_id, current_step) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE id = id', $body);
        $this->assertStringNotContainsString('INSERT IGNORE INTO mission_case_runs', $body);
    }

    public function test_no_locking_read_touches_the_answers_table(): void
    {
        // A FOR UPDATE on an answer row that is not there takes a gap lock under REPEATABLE READ, and the insert that follows
        // deadlocks against another run's gap lock (1213). The staleness is solved by ORDER instead (see the next test).
        $src = str_replace("\r\n", "\n", (string) file_get_contents(dirname(__DIR__) . '/includes/functions-training-case-runs.php'));
        preg_match_all('/"([^"]*mission_case_answers[^"]*)"/', $src, $m);
        $this->assertNotEmpty($m[1]);
        foreach ($m[1] as $sql) {
            $this->assertStringNotContainsString('FOR UPDATE', $sql, "a locking read of the answers table: $sql");
            $this->assertStringNotContainsString('LOCK IN SHARE MODE', $sql, "a locking read of the answers table: $sql");
        }
    }

    public function test_the_snapshot_starts_after_the_run_lock_not_before_it(): void
    {
        // InnoDB opens the REPEATABLE READ snapshot at a transaction's FIRST plain read. The existence check therefore runs
        // before the transaction (autocommit), and the first data statement inside it is the run row's locking read (or the
        // run's insert), so every later plain read sees what the request we waited on committed.
        // (The behaviour is driven for real in TrainingCaseConcurrencyTest; this pins the shape.)
        $src = str_replace("\r\n", "\n", (string) file_get_contents(dirname(__DIR__) . '/includes/functions-training-case-runs.php'));
        $from = strpos($src, 'function trainingCaseAnswer');
        $this->assertNotFalse($from);
        $body = substr($src, $from, strpos($src, "\nfunction ", $from + 1) - $from);

        $exists = strpos($body, 'SELECT 1 FROM mission_case_runs WHERE placement_id = ? AND user_id = ?');
        $tx = strpos($body, 'return trainingCaseTransaction(');
        $this->assertNotFalse($exists);
        $this->assertNotFalse($tx);
        $this->assertLessThan($tx, $exists, 'the existence check precedes the transaction');

        $inside = substr($body, $tx);
        $this->assertSame(1, preg_match('/\bdb(?:FetchValue|FetchOne|FetchAll|Execute|Insert)\(\s*"([^"]*)"/', $inside, $first), 'a statement inside the transaction');
        $this->assertStringStartsWith('INSERT INTO mission_case_runs', $first[1], 'the first statement inside is the run\'s insert...');
        $lock = strpos($inside, 'mission_case_runs WHERE placement_id = ? AND user_id = ? FOR UPDATE');
        $this->assertNotFalse($lock);
        $this->assertStringNotContainsString('SELECT 1 FROM mission_case_runs', $inside, 'no plain pre-check inside the transaction');
        foreach (['FROM mission_case_answers', 'trainingCaseCatchUpRun('] as $needle) {
            $pos = strpos($inside, $needle);
            $this->assertNotFalse($pos, $needle);
            $this->assertLessThan($pos, $lock, "...and the run lock comes before: $needle");
        }
        $this->assertLessThan($lock, strpos($inside, 'INSERT INTO mission_case_runs'), 'the run exists before the locking read');
    }

    // ── Task 5 review findings ─────────────────────────────────────────────────────────────────────────────

    public function test_no_placement_is_recorded_for_a_real_mission(): void
    {
        $real = TrainingFixtures::mission(false);
        $dispatch = (int) dbInsert(
            "INSERT INTO mission_dispatch_points (mission_id, team_id, type, geo, created_by) VALUES (?, NULL, 'point', ?, ?)",
            [$real['mission'], json_encode(['lat' => 35.3, 'lng' => 25.1]), $this->admin]
        );
        $payload = ['case' => ['case_id' => 0, 'title' => 'X', 'draw_mode' => 'chosen', 'briefing' => '', 'document' => ['title' => 'X', 'start' => 's1', 'steps' => []]]];
        $this->assertNull(trainingCaseCreatePlacement($real['mission'], 0, $dispatch, $payload));
        $this->assertSame(0, (int) dbFetchValue("SELECT COUNT(*) FROM mission_case_placements WHERE mission_id = ?", [$real['mission']]));
        // The same payload still records in a drill.
        $drillDispatch = (int) dbInsert(
            "INSERT INTO mission_dispatch_points (mission_id, team_id, type, geo, created_by) VALUES (?, NULL, 'point', ?, ?)",
            [$this->drill['mission'], json_encode(['lat' => 35.3, 'lng' => 25.1]), $this->admin]
        );
        $this->assertNotNull(trainingCaseCreatePlacement($this->drill['mission'], 0, $drillDispatch, $payload));
    }

    public function test_the_case_endpoints_refuse_a_mission_that_is_not_a_drill_and_have_no_review_switch(): void
    {
        $src = str_replace("\r\n", "\n", (string) \WarRoomSource::read('mission-scenario.php'));
        foreach (["if (\$action === 'case_list') {", "if (\$action === 'case_draw') {"] as $marker) {
            $at = strpos($src, $marker);
            $this->assertNotFalse($at, $marker);
            $this->assertStringContainsString(
                "if (!isTrainingMission(\$mission)) {\n        echo json_encode(['ok' => false, 'error' => t('case.err_not_training')]);\n        exit;\n    }",
                substr($src, $at, 420),
                $marker . ' must refuse a real mission before it answers anything'
            );
        }
        $this->assertStringNotContainsString('allow_unreviewed', $src, 'the draw has no review switch any more');
        $this->assertStringContainsString("trainingCaseDraw(\$filter, scenarioStagedCaseIds(\$missionId))", $src);
    }
}
