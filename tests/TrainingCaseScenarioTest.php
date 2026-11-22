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

    /** Team member on duty, so a sent order has somebody to reach. */
    private function onDutyMember(): int
    {
        $member = TrainingFixtures::user('Member');
        dbExecute("INSERT INTO mission_team_members (mission_id, team_id, user_id) VALUES (?, ?, ?)", [$this->drill['mission'], $this->teamId, $member]);
        dbExecute("UPDATE shifts SET start_time = ?, end_time = ? WHERE id = ?", [date('Y-m-d H:i:s', time() - 3600), date('Y-m-d H:i:s', time() + 3600), $this->drill['shift']]);
        TrainingFixtures::participation($this->drill['shift'], $member, false, null);
        dbExecute("INSERT INTO mission_action_room_participants (mission_id, user_id) VALUES (?, ?)", [$this->drill['mission'], $member]);
        return $member;
    }

    public function test_a_placement_that_cannot_be_recorded_does_not_stop_the_order(): void
    {
        $this->onDutyMember();
        $m = $this->mission($this->drill);
        $phase = $this->phase($this->drill);
        $staged = addScenarioCaseItem($m, $phase, $this->admin, $this->form());

        // Make the placement insert throw: one placement already holds the id the order is about to get (the dispatch id is unique
        // in the placements table). The id is read off a throwaway order, and the foreign key is switched off just for that row.
        $probe = (int) dbInsert(
            "INSERT INTO mission_dispatch_points (mission_id, team_id, type, geo, created_by) VALUES (?, ?, 'point', ?, ?)",
            [$this->drill['mission'], $this->teamId, json_encode(['lat' => 35.3, 'lng' => 25.1]), $this->admin]
        );
        dbExecute("DELETE FROM mission_dispatch_points WHERE id = ?", [$probe]);
        $next = $probe + 1;
        db()->exec("SET FOREIGN_KEY_CHECKS = 0");
        dbExecute(
            "INSERT INTO mission_case_placements (mission_id, dispatch_id, title, snapshot) VALUES (?, ?, 'squatter', '{}')",
            [$this->drill['mission'], $next]
        );
        db()->exec("SET FOREIGN_KEY_CHECKS = 1");

        $log = tempnam(sys_get_temp_dir(), 'tcl');
        $previous = ini_set('error_log', $log);
        try {
            $played = playScenarioPhase($m, $phase, $this->admin, 'Case Stager');
        } finally {
            ini_set('error_log', (string) $previous);
        }
        $logged = (string) file_get_contents($log);
        @unlink($log);

        $this->assertTrue($played['ok'], (string) ($played['error'] ?? ''));
        $this->assertSame(1, $played['sent']);
        $item = dbFetchOne("SELECT sent_at, sent_ref_id, last_error FROM mission_scenario_items WHERE id = ?", [$staged['id']]);
        $this->assertNotNull($item['sent_at'], 'the order is marked as sent');
        $this->assertSame($next, (int) $item['sent_ref_id'], 'the order took the id the squatter was holding');
        $this->assertNull($item['last_error']);
        $this->assertSame(1, (int) dbFetchValue("SELECT COUNT(*) FROM mission_dispatch_points WHERE id = ?", [$next]), 'the dispatch exists');
        $this->assertSame(1, (int) dbFetchValue("SELECT COUNT(*) FROM mission_case_placements WHERE dispatch_id = ?", [$next]), 'still only the squatter: nothing was recorded');
        $this->assertStringContainsString('recording the case of item', $logged, 'command sees the failure in the log');
    }

    public function test_the_recorded_snapshot_is_the_document_as_staged_not_as_edited_since(): void
    {
        $this->onDutyMember();
        $m = $this->mission($this->drill);
        $phase = $this->phase($this->drill);
        $staged = addScenarioCaseItem($m, $phase, $this->admin, $this->form());
        $stagedDoc = json_decode((string) dbFetchValue("SELECT payload FROM mission_scenario_items WHERE id = ?", [$staged['id']]), true)['case']['document'];

        // The library case is edited after staging and before the phase is played.
        $library = trainingCaseLoad($this->caseId);
        $edited = $library['doc'];
        $edited['title'] = 'Αλλαγμένος τίτλος';
        $edited['story'] = 'Εντελώς άλλη ιστορία.';
        dbExecute("UPDATE training_cases SET title = ?, document = ? WHERE id = ?", ['Αλλαγμένος τίτλος', json_encode($edited, JSON_UNESCAPED_UNICODE), $this->caseId]);
        $this->assertNotSame($stagedDoc['story'], json_decode((string) dbFetchValue("SELECT document FROM training_cases WHERE id = ?", [$this->caseId]), true)['story'], 'the library really changed');

        $played = playScenarioPhase($m, $phase, $this->admin, 'Case Stager');
        $this->assertTrue($played['ok'], (string) ($played['error'] ?? ''));
        $dispatchId = (int) dbFetchValue("SELECT sent_ref_id FROM mission_scenario_items WHERE id = ?", [$staged['id']]);
        $recorded = json_decode((string) dbFetchValue("SELECT snapshot FROM mission_case_placements WHERE dispatch_id = ?", [$dispatchId]), true);

        $this->assertSame($stagedDoc, $recorded, 'the placement holds the staged document, byte for byte');
        $this->assertNotSame('Εντελώς άλλη ιστορία.', $recorded['story']);
        $this->assertNotSame('Αλλαγμένος τίτλος', trainingCasePlacementForDispatch($dispatchId)['title']);
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
        // A real area with no hidden spot is refused for the spot (the point-shaped geo of form() is not an area at all).
        $square = json_encode([[35.40, 25.20], [35.40, 25.21], [35.41, 25.21], [35.41, 25.20]]);
        $noSpot = addScenarioCaseItem($m, $phase, $this->admin, $this->form(['type' => 'polygon', 'geo' => $square]));
        $this->assertFalse($noSpot['ok']);
        $this->assertSame(t('case.err_spot_needed'), $noSpot['error']);
        $this->assertFalse(addScenarioCaseItem($m, $phase, $this->admin, $this->form(['case_id' => 999999]))['ok']);
        trainingCaseArchive($this->caseId, true);
        $this->assertFalse(addScenarioCaseItem($m, $phase, $this->admin, $this->form())['ok']);
    }

    /** The items of the plan as the card gets them, by id. */
    private function planItems(): array
    {
        $items = [];
        foreach (loadMissionScenario($this->drill['mission'])['phases'] as $phase) {
            foreach ($phase['items'] as $item) {
                $items[$item['id']] = $item;
            }
        }
        return $items;
    }

    public function test_the_plan_and_the_staged_block_carry_no_review_flag(): void
    {
        $phase = $this->phase($this->drill);
        $m = $this->mission($this->drill);
        $result = addScenarioCaseItem($m, $phase, $this->admin, $this->form());
        $payload = json_decode((string) dbFetchValue("SELECT payload FROM mission_scenario_items WHERE id = ?", [$result['id']]), true);
        $this->assertArrayNotHasKey('reviewed', $payload['case']);
        $this->assertArrayNotHasKey('reviewed_at', $payload['case']);

        $item = $this->planItems()[$result['id']];
        $this->assertArrayNotHasKey('case_reviewed', $item, 'the card has no «unreviewed» badge any more');
        $this->assertNotSame('', $item['case_title']);
    }

    public function test_an_item_staged_by_an_older_build_with_a_reviewed_key_still_loads_and_plays(): void
    {
        $this->onDutyMember();
        $m = $this->mission($this->drill);
        $phase = $this->phase($this->drill);
        $staged = addScenarioCaseItem($m, $phase, $this->admin, $this->form());
        $payload = json_decode((string) dbFetchValue("SELECT payload FROM mission_scenario_items WHERE id = ?", [$staged['id']]), true);
        $payload['case']['reviewed'] = false;
        $payload['case']['reviewed_at'] = null;
        dbExecute("UPDATE mission_scenario_items SET payload = ? WHERE id = ?", [json_encode($payload, JSON_UNESCAPED_UNICODE), $staged['id']]);

        $item = $this->planItems()[$staged['id']];
        $this->assertNotSame('', $item['case_title'], 'the old key is ignored, the item loads');
        $this->assertArrayNotHasKey('case_reviewed', $item);

        $played = playScenarioPhase($m, $phase, $this->admin, 'Case Stager');
        $this->assertTrue($played['ok'], (string) ($played['error'] ?? ''));
        $this->assertSame(1, $played['sent']);
    }

    public function test_deleting_the_library_case_keeps_a_played_placement_and_its_snapshot(): void
    {
        $this->onDutyMember();
        $m = $this->mission($this->drill);
        $phase = $this->phase($this->drill);
        $item = addScenarioCaseItem($m, $phase, $this->admin, $this->form(['briefing' => 'Πρώτο']));
        $this->assertTrue(playScenarioPhase($m, $phase, $this->admin, 'Case Stager')['ok']);
        $dispatchId = (int) dbFetchValue("SELECT sent_ref_id FROM mission_scenario_items WHERE id = ?", [$item['id']]);
        $snapshot = dbFetchValue("SELECT snapshot FROM mission_case_placements WHERE dispatch_id = ?", [$dispatchId]);
        $this->assertSame($this->caseId, (int) dbFetchValue("SELECT case_id FROM mission_case_placements WHERE dispatch_id = ?", [$dispatchId]));

        $this->assertTrue(trainingCaseDelete($this->caseId), 'a starter case is deleted');

        $placement = trainingCasePlacementForDispatch($dispatchId);
        $this->assertNotNull($placement, 'the placement survives');
        $this->assertNull($placement['case_id'], 'it just no longer points at a library case');
        $this->assertSame($snapshot, dbFetchValue("SELECT snapshot FROM mission_case_placements WHERE dispatch_id = ?", [$dispatchId]), 'and keeps its own copy');
        $this->assertNotEmpty($placement['doc']['steps']);
        $this->assertArrayHasKey($item['id'], $this->planItems(), 'the played item stays in the plan');
        $this->assertNotSame('', $this->planItems()[$item['id']]['case_title'], 'and the snapshot still names the case');
    }

    public function test_an_item_staged_from_a_case_that_is_deleted_before_it_is_played_still_plays_from_its_snapshot(): void
    {
        $this->onDutyMember();
        $m = $this->mission($this->drill);
        $phase = $this->phase($this->drill);
        $item = addScenarioCaseItem($m, $phase, $this->admin, $this->form(['briefing' => 'Δεύτερο']));
        $this->assertTrue(trainingCaseDelete($this->caseId));

        $plan = $this->planItems();
        $this->assertArrayHasKey($item['id'], $plan, 'the staged item is still in the plan');
        $this->assertNotSame('', $plan[$item['id']]['case_title'], 'it still names the case');
        $this->assertSame([$this->caseId], scenarioStagedCaseIds($this->drill['mission']), 'the staged list is read from the item itself');

        $played = playScenarioPhase($m, $phase, $this->admin, 'Case Stager');
        $this->assertTrue($played['ok'], (string) ($played['error'] ?? ''));
        $this->assertSame(1, $played['sent']);
        $placement = trainingCasePlacementForDispatch((int) dbFetchValue("SELECT sent_ref_id FROM mission_scenario_items WHERE id = ?", [$item['id']]));
        $this->assertNotNull($placement, 'the order was sent and its placement recorded');
        $this->assertNull($placement['case_id'], 'with no library case to point at');
        $this->assertNotEmpty($placement['doc']['steps']);

        // The same case cannot be staged again: it is gone from the library.
        $this->assertFalse(addScenarioCaseItem($m, $phase, $this->admin, $this->form())['ok']);
    }

    public function test_the_scenario_page_gets_the_words_a_case_needs_on_its_card(): void
    {
        foreach (['el', 'en'] as $lang) {
            $strings = scenarioPageStrings($lang)['strings'];
            foreach (['case.plan_title', 'case.default_label'] as $key) {
                $this->assertArrayHasKey($key, $strings, "$key missing for $lang: the page would show the bare key");
            }
            foreach (['case.badge_unreviewed', 'case.picker_unreviewed_warning'] as $key) {
                $this->assertArrayNotHasKey($key, $strings, "$key belonged to the review logic that is gone");
            }
        }
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
