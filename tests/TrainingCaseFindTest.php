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

    protected function setUp(): void
    {
        db()->beginTransaction();
        $this->admin = TrainingFixtures::user('Case Stager');
        $this->drill = TrainingFixtures::mission(true);
        $this->real = TrainingFixtures::mission(false);
        $this->teamId = (int) dbInsert("INSERT INTO mission_teams (mission_id, codename, team_number, created_by) VALUES (?, 'ΑΛΦΑ', 1, ?)", [$this->drill['mission'], $this->admin]);
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
        $rows = dbFetchAll("SELECT spot_lat, spot_lng, spot_hidden FROM mission_case_placements WHERE mission_id = ? ORDER BY id", [$this->drill['mission']]);
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
}
