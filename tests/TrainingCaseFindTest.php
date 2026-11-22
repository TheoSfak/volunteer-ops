<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Finding the victim (v3.506.0): the proximity rule, the gate, the found report, the card, the overview. */
final class TrainingCaseFindTest extends TestCase
{
    private int $admin;
    private array $drill;
    private array $real;
    private int $teamId;
    private int $caseId;
    private int $otherTeamId;
    private int $member;
    private int $mate;
    private int $stranger;
    private int $pointDispatch;
    private int $areaDispatch;
    private int $pointPlacement;
    private int $areaPlacement;

    protected function setUp(): void
    {
        db()->beginTransaction();
        $this->admin = TrainingFixtures::user('Case Stager');
        $this->drill = TrainingFixtures::mission(true);
        $this->real = TrainingFixtures::mission(false);
        $this->teamId = (int) dbInsert("INSERT INTO mission_teams (mission_id, codename, team_number, created_by) VALUES (?, 'ΑΛΦΑ', 1, ?)", [$this->drill['mission'], $this->admin]);
        trainingCaseSeedStarters();
        $this->caseId = (int) dbFetchValue("SELECT id FROM training_cases WHERE is_starter = 1 ORDER BY id LIMIT 1");
        $this->otherTeamId = (int) dbInsert("INSERT INTO mission_teams (mission_id, codename, team_number, created_by) VALUES (?, 'ΒΗΤΑ', 2, ?)", [$this->drill['mission'], $this->admin]);
        $this->member = $this->volunteer('Member', $this->teamId);
        $this->mate = $this->volunteer('Mate', $this->teamId);
        $this->stranger = $this->volunteer('Stranger', $this->otherTeamId);
        $doc = ['title' => 'Δοκιμή', 'story' => 'Ιστορία.', 'closing' => 'Τέλος.', 'findings' => [], 'start' => 's1', 'steps' => [
            's1' => ['prompt' => 'Πρώτο;', 'hint' => '', 'choices' => [
                ['id' => 'a', 'label' => 'Α', 'rating' => 'best', 'feedback' => 'Σωστά.', 'next' => null],
                ['id' => 'b', 'label' => 'Β', 'rating' => 'harmful', 'feedback' => 'Λάθος.', 'next' => null],
            ]],
        ]];
        $this->pointDispatch = $this->order('point', ['lat' => 35.3, 'lng' => 25.1]);
        $this->areaDispatch = $this->order('polygon', self::SQUARE);
        $this->pointPlacement = (int) trainingCaseCreatePlacement($this->drill['mission'], 0, $this->pointDispatch, ['case' => [
            'case_id' => 0, 'title' => 'Σημείο', 'draw_mode' => 'chosen', 'briefing' => 'Φωνή', 'document' => $doc,
            'spot' => ['lat' => 35.3, 'lng' => 25.1], 'spot_hidden' => false]]);
        $this->areaPlacement = (int) trainingCaseCreatePlacement($this->drill['mission'], 0, $this->areaDispatch, ['case' => [
            'case_id' => 0, 'title' => 'Τομέας', 'draw_mode' => 'chosen', 'briefing' => 'Αγνοείται', 'document' => $doc,
            'spot' => ['lat' => 35.405, 'lng' => 25.205], 'spot_hidden' => true]]);
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

    private const SQUARE = [[35.40, 25.20], [35.40, 25.21], [35.41, 25.21], [35.41, 25.20]];

    private function pointForm(array $extra = []): array
    {
        return $extra + [
            'team_id' => (string) $this->teamId, 'type' => 'point', 'geo' => json_encode(['lat' => 35.33, 'lng' => 25.14]),
            'label' => '', 'travel_mode' => 'foot', 'case_id' => $this->caseId, 'case_mode' => 'chosen',
        ];
    }

    private function areaForm(array $extra = []): array
    {
        return $extra + [
            'team_id' => (string) $this->teamId, 'type' => 'polygon', 'geo' => json_encode(self::SQUARE),
            'label' => '', 'travel_mode' => 'foot', 'case_id' => $this->caseId, 'case_mode' => 'chosen',
        ];
    }

    private function stagedCase(int $itemId): array
    {
        return json_decode((string) dbFetchValue("SELECT payload FROM mission_scenario_items WHERE id = ?", [$itemId]), true)['case'];
    }

    private function volunteer(string $name, int $team): int
    {
        $u = TrainingFixtures::user($name);
        TrainingFixtures::participation($this->drill['shift'], $u, false, null);
        dbExecute("INSERT INTO mission_team_members (mission_id, team_id, user_id) VALUES (?, ?, ?)", [$this->drill['mission'], $team, $u]);
        return $u;
    }

    private function order(string $type, array $geo): int
    {
        return (int) dbInsert(
            "INSERT INTO mission_dispatch_points (mission_id, team_id, type, geo, label, created_by) VALUES (?, ?, ?, ?, 'Περιστατικό', ?)",
            [$this->drill['mission'], $this->teamId, $type, json_encode($geo), $this->admin]
        );
    }

    private function arrive(int $dispatchId, int $teamId): void
    {
        dbExecute("INSERT INTO mission_dispatch_progress (dispatch_id, team_id, scope_key, arrived_at) VALUES (?, ?, ?, NOW())", [$dispatchId, $teamId, 't' . $teamId]);
    }

    /** A point `$metres` north of (lat, lng). One degree of latitude is 111194.9 m on the sphere gpsDistanceMeters() uses. */
    private function north(float $lat, float $metres): float
    {
        return $lat + $metres / 111194.9;
    }

    public function test_a_point_case_takes_the_point_as_its_spot(): void
    {
        $r = addScenarioCaseItem($this->mission($this->drill), $this->phase($this->drill), $this->admin, $this->pointForm());
        $this->assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        $case = $this->stagedCase($r['id']);
        $this->assertEqualsWithDelta(35.33, $case['spot']['lat'], 0.000001);
        $this->assertEqualsWithDelta(25.14, $case['spot']['lng'], 0.000001);
        $this->assertFalse($case['spot_hidden']);
    }

    public function test_an_area_case_needs_a_hidden_spot_inside_the_area(): void
    {
        $m = $this->mission($this->drill);
        $phase = $this->phase($this->drill);
        $none = addScenarioCaseItem($m, $phase, $this->admin, $this->areaForm());
        $this->assertFalse($none['ok']);
        $this->assertSame(t('case.err_spot_needed'), $none['error']);

        $outside = addScenarioCaseItem($m, $phase, $this->admin, $this->areaForm(['spot_lat' => '35.50', 'spot_lng' => '25.20']));
        $this->assertFalse($outside['ok']);
        $this->assertSame(t('case.err_spot_outside'), $outside['error']);

        $ok = addScenarioCaseItem($m, $phase, $this->admin, $this->areaForm(['spot_lat' => '35.4050', 'spot_lng' => '25.2050']));
        $this->assertTrue($ok['ok'], (string) ($ok['error'] ?? ''));
        $case = $this->stagedCase($ok['id']);
        $this->assertTrue($case['spot_hidden']);
        $this->assertEqualsWithDelta(35.405, $case['spot']['lat'], 0.000001);
        $this->assertSame(0, (int) dbFetchValue("SELECT COUNT(*) FROM mission_scenario_items WHERE phase_id = ? AND payload LIKE '%35.5%'", [$phase]), 'a refused spot leaves nothing behind');
    }

    public function test_a_spot_given_for_a_point_is_ignored(): void
    {
        $r = addScenarioCaseItem($this->mission($this->drill), $this->phase($this->drill), $this->admin, $this->pointForm(['spot_lat' => '35.9', 'spot_lng' => '25.9']));
        $this->assertTrue($r['ok']);
        $this->assertEqualsWithDelta(35.33, $this->stagedCase($r['id'])['spot']['lat'], 0.000001);
    }

    public function test_a_real_mission_refuses_both_kinds(): void
    {
        $m = $this->mission($this->real);
        $phase = $this->phase($this->real);
        $this->assertFalse(addScenarioCaseItem($m, $phase, $this->admin, $this->pointForm())['ok']);
        $this->assertFalse(addScenarioCaseItem($m, $phase, $this->admin, $this->areaForm(['spot_lat' => '35.405', 'spot_lng' => '25.205']))['ok']);
    }

    public function test_a_sent_order_records_the_spot_on_its_placement(): void
    {
        $m = $this->mission($this->drill);
        $phase = $this->phase($this->drill);
        $point = addScenarioCaseItem($m, $phase, $this->admin, $this->pointForm());
        $area = addScenarioCaseItem($m, $phase, $this->admin, $this->areaForm(['spot_lat' => '35.405', 'spot_lng' => '25.205']));
        foreach ([$point['id'], $area['id']] as $itemId) {
            $item = dbFetchOne("SELECT * FROM mission_scenario_items WHERE id = ?", [$itemId]);
            $payload = json_decode($item['payload'], true);
            $dispatch = (int) dbInsert(
                "INSERT INTO mission_dispatch_points (mission_id, team_id, type, geo, label, created_by) VALUES (?, ?, ?, ?, 'Περιστατικό', ?)",
                [$this->drill['mission'], $this->teamId, $payload['type'], json_encode($payload['geo']), $this->admin]
            );
            trainingCaseCreatePlacement($this->drill['mission'], (int) $itemId, $dispatch, $payload);
        }
        $rows = dbFetchAll("SELECT spot_lat, spot_lng, spot_hidden FROM mission_case_placements WHERE mission_id = ? AND id NOT IN (?, ?) ORDER BY id", [$this->drill['mission'], $this->pointPlacement, $this->areaPlacement]);
        $this->assertCount(2, $rows);
        $this->assertEqualsWithDelta(35.33, (float) $rows[0]['spot_lat'], 0.000001);
        $this->assertSame(0, (int) $rows[0]['spot_hidden']);
        $this->assertEqualsWithDelta(35.405, (float) $rows[1]['spot_lat'], 0.000001);
        $this->assertSame(1, (int) $rows[1]['spot_hidden']);
    }

    public function test_an_older_staged_point_without_a_spot_block_still_gets_its_point_as_spot(): void
    {
        $dispatch = (int) dbInsert(
            "INSERT INTO mission_dispatch_points (mission_id, team_id, type, geo, label, created_by) VALUES (?, ?, 'point', ?, 'x', ?)",
            [$this->drill['mission'], $this->teamId, json_encode(['lat' => 35.2, 'lng' => 25.3]), $this->admin]
        );
        $payload = ['case' => ['case_id' => 0, 'title' => 'T', 'draw_mode' => 'chosen', 'briefing' => '', 'document' => ['title' => 'T', 'start' => 's1', 'steps' => []]]];
        $id = (int) trainingCaseCreatePlacement($this->drill['mission'], 0, $dispatch, $payload);
        $row = dbFetchOne("SELECT spot_lat, spot_lng, spot_hidden FROM mission_case_placements WHERE id = ?", [$id]);
        $this->assertEqualsWithDelta(35.2, (float) $row['spot_lat'], 0.000001);
        $this->assertSame(0, (int) $row['spot_hidden']);
    }

    public function test_the_distances_are_the_agreed_ones(): void
    {
        $this->assertSame(10, TRAINING_CASE_FOUND_M);
        $this->assertSame(50, TRAINING_CASE_CLOSE_M);
        $this->assertSame(15, TRAINING_CASE_ACCURACY_CREDIT_M);
    }

    public function test_the_boundaries(): void
    {
        $this->assertSame('found', trainingCaseProximity(10.0, null));
        $this->assertSame('close', trainingCaseProximity(10.1, null));
        $this->assertSame('close', trainingCaseProximity(50.0, null));
        $this->assertSame('far', trainingCaseProximity(50.1, null));
    }

    public function test_gps_error_is_credited_but_capped(): void
    {
        $this->assertSame('found', trainingCaseProximity(14.0, 10.0));   // 14 - 10 = 4
        $this->assertSame('found', trainingCaseProximity(25.0, 15.0));   // credit 15 -> 10
        $this->assertSame('close', trainingCaseProximity(25.1, 100.0));  // credit capped at 15 -> 10.1
        $this->assertSame('found', trainingCaseProximity(9.0, -5.0));    // a negative accuracy credits nothing
        $this->assertSame('close', trainingCaseProximity(64.9, 15.0));   // 49.9
        $this->assertSame('far', trainingCaseProximity(65.1, 15.0));     // 50.1
    }

    public function test_a_point_case_opens_on_arrival_or_on_a_find(): void
    {
        $p = trainingCasePlacement($this->pointPlacement);
        $this->assertSame('case.err_not_arrived', trainingCaseGate($p, $this->member, true));
        $this->arrive($this->pointDispatch, $this->teamId);
        $this->assertNull(trainingCaseGate($p, $this->member, true));
    }

    public function test_an_area_case_does_not_open_on_arrival(): void
    {
        $p = trainingCasePlacement($this->areaPlacement);
        $this->arrive($this->areaDispatch, $this->teamId);
        $this->assertSame('case.err_not_found_yet', trainingCaseGate($p, $this->member, true));
        $this->assertNull(trainingCaseGate($p, $this->member, false), 'the document may still be loaded (prefetch)');
    }

    public function test_a_find_opens_the_case_for_the_whole_team_only(): void
    {
        $p = trainingCasePlacement($this->areaPlacement);
        $r = trainingCaseRecordFound($this->areaPlacement, $this->member, 35.405, 25.205, 8.0);
        $this->assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        $this->assertFalse($r['duplicate']);
        $this->assertNull(trainingCaseGate($p, $this->member, true));
        $this->assertNull(trainingCaseGate($p, $this->mate, true), 'a teammate who did not walk there may play');
        $this->assertSame('case.err_not_audience', trainingCaseGate($p, $this->stranger, false));
        $this->assertSame('t' . $this->teamId, dbFetchValue("SELECT scope_key FROM mission_case_finds WHERE placement_id = ?", [$this->areaPlacement]));
    }

    public function test_the_server_rechecks_the_distance_with_the_same_rule(): void
    {
        $lat = 35.405;
        $forget = fn() => dbExecute("DELETE FROM mission_case_finds WHERE placement_id = ?", [$this->areaPlacement]);

        $this->assertTrue(trainingCaseRecordFound($this->areaPlacement, $this->member, $this->north($lat, 9.0), 25.205, null)['ok']);
        $forget();

        $this->assertTrue(trainingCaseRecordFound($this->areaPlacement, $this->member, $this->north($lat, 14.0), 25.205, 10.0)['ok'], '14 m with a 10 m error is a find');
        $forget();

        $far = trainingCaseRecordFound($this->areaPlacement, $this->member, $this->north($lat, 30.0), 25.205, null);
        $this->assertFalse($far['ok']);
        $this->assertSame('refused', $far['code'], 'a refusal the phone must drop, not retry for ever');

        // The credit for a poor fix is capped at 15 m: 30 m with a 100 m error is 15 m effective, not found.
        $this->assertFalse(trainingCaseRecordFound($this->areaPlacement, $this->member, $this->north($lat, 30.0), 25.205, 100.0)['ok']);
        $this->assertSame(0, (int) dbFetchValue("SELECT COUNT(*) FROM mission_case_finds WHERE placement_id = ?", [$this->areaPlacement]));
    }

    public function test_a_second_report_is_a_quiet_duplicate_and_another_team_cannot_report(): void
    {
        $first = trainingCaseRecordFound($this->areaPlacement, $this->member, 35.405, 25.205, 5.0);
        $again = trainingCaseRecordFound($this->areaPlacement, $this->mate, 35.405, 25.205, 5.0);
        $this->assertTrue($again['ok']);
        $this->assertTrue($again['duplicate']);
        $this->assertSame(1, (int) dbFetchValue("SELECT COUNT(*) FROM mission_case_finds WHERE placement_id = ?", [$this->areaPlacement]));
        $foreign = trainingCaseRecordFound($this->areaPlacement, $this->stranger, 35.405, 25.205, 5.0);
        $this->assertFalse($foreign['ok']);
        $this->assertSame('refused', $foreign['code']);
    }

    public function test_a_real_mission_records_nothing(): void
    {
        dbExecute("UPDATE missions SET is_training = 0 WHERE id = ?", [$this->drill['mission']]);
        $this->assertFalse(trainingCaseRecordFound($this->areaPlacement, $this->member, 35.405, 25.205, 5.0)['ok']);
        dbExecute("UPDATE missions SET is_training = 1 WHERE id = ?", [$this->drill['mission']]);
        $this->assertSame(0, (int) dbFetchValue("SELECT COUNT(*) FROM mission_case_finds"));
    }

    public function test_a_placement_with_no_spot_cannot_be_found(): void
    {
        $dispatch = $this->order('point', ['lat' => 35.6, 'lng' => 25.6]);
        $id = (int) trainingCaseCreatePlacement($this->drill['mission'], 0, $dispatch, ['case' => ['case_id' => 0, 'title' => 'X', 'draw_mode' => 'chosen', 'briefing' => '', 'document' => ['title' => 'X', 'start' => 's1', 'steps' => []]]]);
        dbExecute("UPDATE mission_case_placements SET spot_lat = NULL, spot_lng = NULL WHERE id = ?", [$id]);
        $this->assertFalse(trainingCaseRecordFound($id, $this->member, 35.6, 25.6, 5.0)['ok']);
    }

    public function test_the_other_members_are_told_once_and_the_finder_is_not(): void
    {
        dbExecute("UPDATE shifts SET start_time = ?, end_time = ? WHERE id = ?", [date('Y-m-d H:i:s', time() - 3600), date('Y-m-d H:i:s', time() + 3600), $this->drill['shift']]);
        foreach ([$this->member, $this->mate, $this->stranger] as $u) {
            dbExecute("INSERT INTO mission_action_room_participants (mission_id, user_id) VALUES (?, ?)", [$this->drill['mission'], $u]);
        }
        trainingCaseRecordFound($this->areaPlacement, $this->member, 35.405, 25.205, 5.0);
        trainingCaseRecordFound($this->areaPlacement, $this->member, 35.405, 25.205, 5.0);
        $count = fn(int $u): int => (int) dbFetchValue("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND data LIKE ?", [$u, '%case-' . $this->areaPlacement . '%']);
        $this->assertSame(1, $count($this->mate));
        $this->assertSame(0, $count($this->member));
        $this->assertSame(0, $count($this->stranger));
    }
}
