<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Staging a case (a point order carrying a snapshot) and playing it (the order is sent, a placement is recorded). */
final class TrainingCaseScenarioTest extends TestCase
{
    private int $admin;
    private array $drill;
    private array $real;
    private int $teamId;
    private int $caseId;

    protected function setUp(): void
    {
        db()->beginTransaction();
        $this->admin = TrainingFixtures::user('Case Stager');
        $this->drill = TrainingFixtures::mission(true);
        $this->real = TrainingFixtures::mission(false);
        $this->teamId = (int) dbInsert(
            "INSERT INTO mission_teams (mission_id, codename, team_number, created_by) VALUES (?, 'ΑΛΦΑ', 1, ?)",
            [$this->drill['mission'], $this->admin]
        );
        trainingCaseSeedStarters();
        $this->caseId = (int) dbFetchValue("SELECT id FROM training_cases WHERE is_starter = 1 ORDER BY id LIMIT 1");
    }

    protected function tearDown(): void
    {
        db()->rollBack();
    }

    private function mission(array $m): array
    {
        return ['id' => $m['mission'], 'title' => 'T', 'status' => STATUS_OPEN, 'responsible_user_id' => $this->admin];
    }

    private function phase(array $m): int
    {
        return (int) addScenarioPhase($this->mission($m), $this->admin, '')['id'];
    }

    private function form(array $extra = []): array
    {
        return $extra + [
            'team_id' => (string) $this->teamId, 'type' => 'point', 'geo' => json_encode(['lat' => 35.33, 'lng' => 25.14]),
            'label' => '', 'travel_mode' => 'foot', 'case_id' => $this->caseId, 'case_mode' => 'chosen',
        ];
    }

    public function test_staging_stores_a_snapshot_in_an_ordinary_dispatch_item(): void
    {
        $phase = $this->phase($this->drill);
        $result = addScenarioCaseItem($this->mission($this->drill), $phase, $this->admin, $this->form(['briefing' => 'Ακούστηκε φωνή']));
        $this->assertTrue($result['ok'], (string) ($result['error'] ?? ''));
        $row = dbFetchOne("SELECT kind, payload FROM mission_scenario_items WHERE id = ?", [$result['id']]);
        $this->assertSame('dispatch', $row['kind']);
        $payload = json_decode($row['payload'], true);
        $this->assertSame($this->caseId, $payload['case']['case_id']);
        $this->assertSame('chosen', $payload['case']['draw_mode']);
        $this->assertSame('Ακούστηκε φωνή', $payload['case']['briefing']);
        $this->assertNotEmpty($payload['case']['document']['steps']);
        $this->assertSame('point', $payload['type']);
        $this->assertNotSame('', $payload['label'], 'a neutral label is filled in so the order never spoils the case');
    }

    public function test_the_snapshot_does_not_change_when_the_library_case_is_edited(): void
    {
        $phase = $this->phase($this->drill);
        $result = addScenarioCaseItem($this->mission($this->drill), $phase, $this->admin, $this->form());
        $before = dbFetchValue("SELECT payload FROM mission_scenario_items WHERE id = ?", [$result['id']]);
        dbExecute("UPDATE training_cases SET document = REPLACE(document, 'story', 'sXXXX') WHERE id = ?", [$this->caseId]);
        $this->assertSame($before, dbFetchValue("SELECT payload FROM mission_scenario_items WHERE id = ?", [$result['id']]));
    }

    public function test_a_real_mission_refuses_a_case(): void
    {
        $phase = $this->phase($this->real);
        $result = addScenarioCaseItem($this->mission($this->real), $phase, $this->admin, $this->form(['team_id' => 'all']));
        $this->assertFalse($result['ok']);
        $this->assertSame(0, (int) dbFetchValue("SELECT COUNT(*) FROM mission_scenario_items WHERE phase_id = ?", [$phase]));
    }

    public function test_an_area_or_a_missing_or_archived_case_is_refused(): void
    {
        $phase = $this->phase($this->drill);
        $m = $this->mission($this->drill);
        $this->assertFalse(addScenarioCaseItem($m, $phase, $this->admin, $this->form(['type' => 'polygon']))['ok']);
        $this->assertFalse(addScenarioCaseItem($m, $phase, $this->admin, $this->form(['case_id' => 999999]))['ok']);
        trainingCaseArchive($this->caseId, true);
        $this->assertFalse(addScenarioCaseItem($m, $phase, $this->admin, $this->form())['ok']);
    }

    public function test_scenario_staged_case_ids_lists_what_is_already_in_the_plan(): void
    {
        $phase = $this->phase($this->drill);
        addScenarioCaseItem($this->mission($this->drill), $phase, $this->admin, $this->form());
        $this->assertSame([$this->caseId], scenarioStagedCaseIds($this->drill['mission']));
    }

    public function test_play_sends_the_order_and_records_the_placement(): void
    {
        $member = TrainingFixtures::user('Member');
        dbExecute("INSERT INTO mission_team_members (mission_id, team_id, user_id) VALUES (?, ?, ?)", [$this->drill['mission'], $this->teamId, $member]);
        dbExecute("UPDATE shifts SET start_time = ?, end_time = ? WHERE id = ?", [date('Y-m-d H:i:s', time() - 3600), date('Y-m-d H:i:s', time() + 3600), $this->drill['shift']]);
        TrainingFixtures::participation($this->drill['shift'], $member, false, null);
        dbExecute("INSERT INTO mission_action_room_participants (mission_id, user_id) VALUES (?, ?)", [$this->drill['mission'], $member]);

        $m = $this->mission($this->drill);
        $phase = $this->phase($this->drill);
        $staged = addScenarioCaseItem($m, $phase, $this->admin, $this->form(['briefing' => 'Βρέθηκε άνδρας']));
        $played = playScenarioPhase($m, $phase, $this->admin, 'Case Stager');

        $this->assertTrue($played['ok'], (string) ($played['error'] ?? ''));
        $this->assertSame(1, $played['sent']);
        $item = dbFetchOne("SELECT sent_ref_id FROM mission_scenario_items WHERE id = ?", [$staged['id']]);
        $dispatchId = (int) $item['sent_ref_id'];
        $placement = trainingCasePlacementForDispatch($dispatchId);
        $this->assertNotNull($placement);
        $this->assertSame($this->drill['mission'], (int) $placement['mission_id']);
        $this->assertSame($staged['id'], (int) $placement['scenario_item_id']);
        $this->assertSame($this->teamId, (int) $placement['team_id']);
        $this->assertSame('team', $placement['audience']);
        $this->assertNotEmpty($placement['doc']['steps']);
    }

    public function test_an_order_to_every_team_that_is_split_makes_one_placement_per_team(): void
    {
        $teamB = (int) dbInsert("INSERT INTO mission_teams (mission_id, codename, team_number, created_by) VALUES (?, 'ΒΗΤΑ', 2, ?)", [$this->drill['mission'], $this->admin]);
        foreach ([$this->teamId, $teamB] as $team) {
            $u = TrainingFixtures::user('M' . $team);
            dbExecute("INSERT INTO mission_team_members (mission_id, team_id, user_id) VALUES (?, ?, ?)", [$this->drill['mission'], $team, $u]);
        }
        $m = $this->mission($this->drill);
        $phase1 = $this->phase($this->drill);
        $phase2 = $this->phase($this->drill);
        // Phase 1 holds team A's task; team A has not finished it, so a phase-2 order to everyone is split per team.
        addScenarioDispatchItem($m, $phase1, $this->admin, ['team_id' => (string) $this->teamId, 'type' => 'point', 'geo' => json_encode(['lat' => 35.3, 'lng' => 25.1]), 'label' => 'x']);
        addScenarioCaseItem($m, $phase2, $this->admin, $this->form(['team_id' => 'all']));
        playScenarioPhase($m, $phase1, $this->admin, 'A');
        $played = playScenarioPhase($m, $phase2, $this->admin, 'A');
        $this->assertTrue($played['ok']);
        $placements = (int) dbFetchValue("SELECT COUNT(*) FROM mission_case_placements WHERE mission_id = ?", [$this->drill['mission']]);
        $this->assertSame(1, $placements, 'only team B was ready: its child order is sent now, team A\'s waits for its phase');
    }
}
