<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * «Αντιγραφή φάσης» (v3.467.0): duplicateScenarioPhase() in includes/functions-scenario.php, wired as the
 * endpoint's duplicate_phase action (mission-scenario.php) and a button beside rename/delete on every phase
 * card (assets/js/war-room-scenario.js, tested in tests/js/war-room-scenario.test.js).
 *
 * The things that must hold: the copy is a NEW phase at the end of the plan, never in the middle; every item of
 * the source is copied with a fresh, unplayed, unsent state regardless of how the source stood (sent, waiting,
 * withdrawn); a sector or building that names an AREA ITEM or SECTOR ITEM of the very phase being copied is
 * repointed at that item's own copy, never at the original; a reference to an earlier, DIFFERENT phase's item is
 * left alone; the source phase is never touched; and the usual limits and not-found refusals apply.
 *
 * Runs inside a transaction that is always rolled back.
 */
final class ScenarioDuplicateTest extends TestCase
{
    private int $missionId;
    private int $adminId;
    private int $teamId;
    private array $mission;

    private const TRI = '[[35.0,24.8],[35.0,24.9],[35.1,24.9]]';

    protected function setUp(): void
    {
        db()->beginTransaction();
        $this->adminId = (int) dbInsert("INSERT INTO users (name, email, password) VALUES (?, ?, ?)", ['Duplicate Admin', 'scd-' . uniqid('', true) . '@example.invalid', 'x']);
        $missionTypeId = (int) dbFetchValue("SELECT id FROM mission_types ORDER BY id LIMIT 1");
        $this->missionId = (int) dbInsert(
            "INSERT INTO missions (title, location, start_datetime, end_datetime, mission_type_id) VALUES (?, ?, ?, ?, ?)",
            ['Duplicate Mission', 'Ηράκλειο', date('Y-m-d H:i:s', time() - 7200), date('Y-m-d H:i:s', time() + 7200), $missionTypeId]
        );
        $this->mission = ['id' => $this->missionId, 'title' => 'Duplicate Mission'];
        $this->teamId = (int) dbInsert("INSERT INTO mission_teams (mission_id, codename, team_number, created_by) VALUES (?, 'ΑΛΦΑ', 1, ?)", [$this->missionId, $this->adminId]);
    }

    protected function tearDown(): void
    {
        db()->rollBack();
    }

    private function phase(string $title = ''): int
    {
        $r = addScenarioPhase($this->mission, $this->adminId, $title);
        $this->assertTrue($r['ok']);
        return $r['id'];
    }

    private function itemsOf(int $phaseId): array
    {
        foreach (loadMissionScenario($this->missionId)['phases'] as $phase) {
            if ($phase['id'] === $phaseId) {
                return $phase;
            }
        }
        $this->fail("phase $phaseId not in loadMissionScenario()");
    }

    public function testACopyIsAFreshUnplayedPhaseAtTheEndWithEveryItemReset(): void
    {
        $phase1 = $this->phase('Σάρωση Α');
        $point = addScenarioDispatchItem($this->mission, $phase1, $this->adminId, [
            'team_id' => (string) $this->teamId, 'type' => 'point', 'label' => 'Ρέμα', 'geo' => json_encode(['lat' => 35.1, 'lng' => 25.1]), 'travel_mode' => '',
        ]);
        $this->assertTrue($point['ok']);
        $area = addScenarioAreaItem($this->mission, $phase1, $this->adminId, ['label' => 'Περιοχή Α', 'geo' => self::TRI]);
        $this->assertTrue($area['ok']);
        $sector = addScenarioSectorItems($this->mission, $phase1, $this->adminId, 0, [
            ['team_id' => (string) $this->teamId, 'label' => 'Τομέας 1', 'geo' => [[35.0, 24.8], [35.0, 24.9], [35.1, 24.9]]],
        ], (int) $area['id']);
        $this->assertTrue($sector['ok']);

        // Phase 1 is played - the point, the area and its sector all go at once (nothing of a first phase ever
        // waits) - so the copy has three "sent" items to reset.
        $played = playScenarioPhase($this->mission, $phase1, $this->adminId, 'Duplicate Admin');
        $this->assertTrue($played['ok']);
        $this->assertSame(3, $played['sent']);

        $phase2 = $this->phase('Σάρωση Β'); // an ordinary second phase, so the copy's seq is checked against this, not 1
        $result = duplicateScenarioPhase($this->mission, $phase1, $this->adminId);
        $this->assertTrue($result['ok']);
        $newPhaseId = $result['id'];
        $this->assertNotSame($phase1, $newPhaseId);

        $scenario = loadMissionScenario($this->missionId);
        $phaseIds = array_column($scenario['phases'], 'id');
        $this->assertSame([$phase1, $phase2, $newPhaseId], $phaseIds, 'appended at the end, after phase 2, not spliced in after its source');

        $copy = $this->itemsOf($newPhaseId);
        $this->assertSame(3, $copy['n']);
        $this->assertNull($copy['played_at'], 'the copy is never played, whatever the source was');
        $this->assertSame('Σάρωση Α (αντίγραφο)', $copy['title']);
        $this->assertCount(3, $copy['items']);
        foreach ($copy['items'] as $item) {
            $this->assertFalse($item['sent'], $item['kind'] . ' is unsent in the copy');
            $this->assertNull($item['sent_at']);
        }

        // The sector's area_item_id is repointed at the AREA ITEM'S OWN COPY, not the original phase's area.
        $copySector = null;
        $copyArea = null;
        foreach ($copy['items'] as $item) {
            if ($item['kind'] === 'sector') {
                $copySector = $item;
            } elseif ($item['kind'] === 'area') {
                $copyArea = $item;
            }
        }
        $this->assertNotNull($copySector);
        $this->assertNotNull($copyArea);
        $this->assertSame($copyArea['id'], $copySector['area_item_id']);
        $this->assertNotSame((int) $area['id'], $copySector['area_item_id']);

        // The source phase is untouched: still played, still holding its own (now-sent) point and its own area/sector.
        $source = $this->itemsOf($phase1);
        $this->assertNotNull($source['played_at']);
        $this->assertCount(3, $source['items']);
    }

    public function testACrossPhaseReferenceIsLeftAloneWhenOnlyTheDependentPhaseIsCopied(): void
    {
        // The area lives in phase 1; a sector naming it (area_item_id) is written down in phase 2, which is the one copied.
        $phase1 = $this->phase();
        $area = addScenarioAreaItem($this->mission, $phase1, $this->adminId, ['label' => 'Περιοχή Α', 'geo' => self::TRI]);
        $this->assertTrue($area['ok']);
        $phase2 = $this->phase();
        $sector = addScenarioSectorItems($this->mission, $phase2, $this->adminId, 0, [
            ['team_id' => (string) $this->teamId, 'label' => 'Τομέας 1', 'geo' => [[35.0, 24.8], [35.0, 24.9], [35.1, 24.9]]],
        ], (int) $area['id']);
        $this->assertTrue($sector['ok']);

        $result = duplicateScenarioPhase($this->mission, $phase2, $this->adminId);
        $this->assertTrue($result['ok']);
        $copy = $this->itemsOf($result['id']);
        $this->assertSame((int) $area['id'], $copy['items'][0]['area_item_id'], 'phase 1 was not copied, so there is no copy of the area to repoint to');
    }

    public function testATitlelessPhaseStaysTitleless(): void
    {
        $phase1 = $this->phase('');
        $result = duplicateScenarioPhase($this->mission, $phase1, $this->adminId);
        $this->assertTrue($result['ok']);
        $this->assertNull($this->itemsOf($result['id'])['title']);
    }

    public function testAnEmptyPhaseCopiesCleanly(): void
    {
        $phase1 = $this->phase();
        $result = duplicateScenarioPhase($this->mission, $phase1, $this->adminId);
        $this->assertTrue($result['ok']);
        $this->assertSame([], $this->itemsOf($result['id'])['items']);
    }

    public function testAnUnknownOrForeignPhaseIsRefused(): void
    {
        $result = duplicateScenarioPhase($this->mission, 999999, $this->adminId);
        $this->assertFalse($result['ok']);
        $this->assertSame(t('scenario.phase_not_found'), $result['error']);
    }

    public function testTheMissionsPhaseLimitAppliesToACopyToo(): void
    {
        $phase1 = $this->phase();
        for ($i = 1; $i < SCENARIO_MAX_PHASES; $i++) {
            $this->phase();
        }
        $this->assertSame(SCENARIO_MAX_PHASES, (int) dbFetchValue("SELECT COUNT(*) FROM mission_scenario_phases WHERE mission_id = ?", [$this->missionId]));
        $result = duplicateScenarioPhase($this->mission, $phase1, $this->adminId);
        $this->assertFalse($result['ok']);
        $this->assertSame(t('scenario.too_many_phases', ['max' => SCENARIO_MAX_PHASES]), $result['error']);
    }

    public function testTheCopyIsAudited(): void
    {
        $phase1 = $this->phase();
        $result = duplicateScenarioPhase($this->mission, $phase1, $this->adminId);
        $this->assertTrue($result['ok']);
        $row = dbFetchOne("SELECT * FROM audit_logs WHERE action = 'duplicate_scenario_phase' AND record_id = ?", [$result['id']]);
        $this->assertNotNull($row);
    }

    public function testTheEndpointWiresDuplicatePhaseAfterTheCsrfCheck(): void
    {
        $src = file_get_contents(__DIR__ . '/../mission-scenario.php');
        $csrf = strpos($src, "hash_equals(\$_SESSION['csrf_token']");
        $action = strpos($src, "'duplicate_phase'");
        $call = strpos($src, 'duplicateScenarioPhase(');
        $this->assertNotFalse($csrf);
        $this->assertNotFalse($action);
        $this->assertNotFalse($call);
        $this->assertTrue($csrf < $action && $action < $call);
    }
}
