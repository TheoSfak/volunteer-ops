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

    public function test_the_case_endpoints_refuse_a_mission_that_is_not_a_drill_and_allow_unreviewed_is_strict(): void
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
        $this->assertStringContainsString("post('allow_unreviewed') === '1'", $src);
        $this->assertStringNotContainsString("!empty(post('allow_unreviewed'))", $src);
    }
}
