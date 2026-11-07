<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Buildings of a search sector in the scenario (v3.460.0): a point with a name and a number of floors above the ground, written
 * down in a phase and made - with its floors - when play is pressed; the last piece of the scenario. includes/functions-scenario.php
 * (addScenarioBuildingItem, scenarioStagedSectorForBuilding, scenarioBuildingInputFromPayload, scenarioResolveStagedBuildingSector,
 * scenarioUnsentBuildingsOfStagedSector, the 'building' branches of scenarioSendItem / scenarioItemLiveStates /
 * scenarioPhaseProgress / withdrawScenarioPhase, the refusals in deleteScenarioItem / deleteScenarioPhase) and
 * prepare/createMissionSectorBuilding() in includes/functions-warroom.php, which mission-sector.php's create_building now sends through.
 *
 * The things that must hold: the building is the one the popup's save makes (same checks, rows, floors and audit entry); a building
 * has no team, no slot and no «όλες οι ομάδες» (the team of its sector searches it); the point has to stand in its sector, within the
 * tolerance, also when the sector is only a line of the plan; a building written against the plan's own sector is made in the live
 * sector that item became and in NO other - if the sector is not there (not played yet, failed, taken off the plan, deleted since)
 * the building FAILS with that reason and makes nothing; a sector that buildings still wait for cannot be taken off the plan, nor
 * can its phase; and taking a phase back never deletes a building (it stays in its sector).
 *
 * Runs inside a transaction that is always rolled back.
 */
final class ScenarioBuildingTest extends TestCase
{
    private int $missionId;
    private int $otherMissionId;
    private int $adminId;
    private int $teamId;
    private array $mission;

    protected function setUp(): void
    {
        db()->beginTransaction();
        $this->adminId = (int) dbInsert("INSERT INTO users (name, email, password) VALUES (?, ?, ?)", ['Building Admin', 'sb-' . uniqid('', true) . '@example.invalid', 'x']);
        $missionTypeId = (int) dbFetchValue("SELECT id FROM mission_types ORDER BY id LIMIT 1");
        $mk = function (string $title) use ($missionTypeId): int {
            return (int) dbInsert(
                "INSERT INTO missions (title, location, start_datetime, end_datetime, mission_type_id) VALUES (?, ?, ?, ?, ?)",
                [$title, 'Ηράκλειο', date('Y-m-d H:i:s', time() - 7200), date('Y-m-d H:i:s', time() + 7200), $missionTypeId]
            );
        };
        $this->missionId = $mk('Building Mission');
        $this->otherMissionId = $mk('Other Building Mission');
        $this->mission = ['id' => $this->missionId, 'title' => 'Building Mission'];
        $this->teamId = (int) dbInsert("INSERT INTO mission_teams (mission_id, codename, team_number, created_by) VALUES (?, 'ΑΛΦΑ', 1, ?)", [$this->missionId, $this->adminId]);
    }

    protected function tearDown(): void
    {
        db()->rollBack();
    }

    /** A sector of about 111 m x 90 m; its south edge is latitude 35.300. */
    private const SQUARE = [[35.300, 25.100], [35.300, 25.101], [35.301, 25.101], [35.301, 25.100]];

    private function phase(): int
    {
        $r = addScenarioPhase($this->mission, $this->adminId, '');
        $this->assertTrue($r['ok']);
        return $r['id'];
    }

    /** A point $metres south of the sector's south edge, half-way along it. */
    private function south(float $metres): float
    {
        return 35.300 - $metres / 111320;
    }

    private function liveArea(?array $mission = null): int
    {
        return (int) createMissionSearchArea($mission ?? $this->mission, $this->adminId, ['label' => 'Περιοχή', 'geo' => json_encode(self::SQUARE)])['id'];
    }

    /** A sector that exists already, made by hand, in this mission or (with $mission) in another. */
    private function liveSector(string $label = 'Ζωντανός τομέας', ?array $mission = null): int
    {
        $mission = $mission ?? $this->mission;
        $made = createMissionSector($mission, $this->adminId, ['label' => $label, 'area_id' => (string) $this->liveArea($mission), 'team_id' => '', 'geo' => json_encode(self::SQUARE)]);
        $this->assertTrue($made['ok'], (string) ($made['error'] ?? ''));
        return (int) $made['id'];
    }

    /** A sector written down in $phaseId against a search area that exists. Returns the sector's item id. */
    private function stageSector(int $phaseId, string $label = 'Τομέας Α', array $geo = self::SQUARE): int
    {
        $r = addScenarioSectorItems($this->mission, $phaseId, $this->adminId, $this->liveArea(), [['team_id' => (string) $this->teamId, 'label' => $label, 'geo' => $geo]]);
        $this->assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        return (int) dbFetchValue("SELECT id FROM mission_scenario_items WHERE phase_id = ? AND kind = 'sector' ORDER BY id DESC LIMIT 1", [$phaseId]);
    }

    private function stageBuilding(int $phaseId, string $sectorRef, array $over = []): array
    {
        return addScenarioBuildingItem($this->mission, $phaseId, $this->adminId, $over + ['sector_id' => $sectorRef, 'label' => 'Κτίριο 1', 'lat' => 35.3005, 'lng' => 25.1005, 'floor_count' => 2]);
    }

    private function play(int $phaseId): array
    {
        return playScenarioPhase($this->mission, $phaseId, $this->adminId, 'Building Admin');
    }

    private function buildings(?int $sectorId = null): array
    {
        return dbFetchAll(
            "SELECT b.* FROM mission_sector_buildings b JOIN mission_search_sectors s ON s.id = b.sector_id WHERE s.mission_id = ?" . ($sectorId ? " AND b.sector_id = " . (int) $sectorId : '') . " ORDER BY b.id",
            [$this->missionId]
        );
    }

    private function sectors(): array
    {
        return dbFetchAll("SELECT * FROM mission_search_sectors WHERE mission_id = ? ORDER BY id", [$this->missionId]);
    }

    private function item(int $itemId): array
    {
        return dbFetchOne("SELECT * FROM mission_scenario_items WHERE id = ?", [$itemId]);
    }

    private function cardItem(int $itemId): array
    {
        foreach (loadMissionScenario($this->missionId)['phases'] as $phase) {
            foreach ($phase['items'] as $item) {
                if ($item['id'] === $itemId) {
                    return $item;
                }
            }
        }
        $this->fail('item not on the card');
    }

    private function progress(int $phaseId): ?array
    {
        foreach (loadMissionScenario($this->missionId)['phases'] as $phase) {
            if ($phase['id'] === $phaseId) {
                return $phase['progress'];
            }
        }
        return null;
    }

    private function source(string $rel): string
    {
        $path = __DIR__ . '/../' . $rel;
        return $rel === 'war-room.php' ? str_replace("\r\n", "\n", (string) WarRoomSource::full()) : str_replace("\r\n", "\n", (string) file_get_contents($path));
    }

    // ── The moved functions ─────────────────────────────────────────────────────────────────────────────────────────

    public function testTheChecksAreTheOnesTheEndpointAlwaysHad(): void
    {
        $sector = $this->liveSector('Τομέας Κοίτης');
        $otherSector = $this->liveSector('Ξένος τομέας', ['id' => $this->otherMissionId, 'title' => 'Other']);
        $ok = ['sector_id' => (string) $sector, 'label' => 'Κτίριο', 'lat' => '35.3005', 'lng' => '25.1005', 'floor_count' => '2'];
        $bad = [
            'no such sector'          => [['sector_id' => '999999999'], 'common.not_found'],
            'no sector at all'        => [['sector_id' => ''], 'common.not_found'],
            'a sector of another one' => [['sector_id' => (string) $otherSector], 'common.not_found'],
            'no name'                 => [['label' => '   '], 'sector.invalid_label'],
            'null island'             => [['lat' => '0', 'lng' => '0'], 'dispatch.invalid_point'],
            'latitude 91'             => [['lat' => '91'], 'dispatch.invalid_point'],
            'longitude 181'           => [['lng' => '181'], 'dispatch.invalid_point'],
            'a latitude that is text' => [['lat' => 'north'], 'dispatch.invalid_point'],
            'no latitude'             => [['lat' => ''], 'dispatch.invalid_point'],
            'minus one floor'         => [['floor_count' => '-1'], 'common.invalid_request'],
            'fifty-one floors'        => [['floor_count' => '51'], 'common.invalid_request'],
            // The order of the checks is the endpoint's: the sector, the name, the point, the floors, and last the ground.
            'sector before name'      => [['sector_id' => '999999999', 'label' => ''], 'common.not_found'],
            'name before point'       => [['label' => '', 'lat' => '0', 'lng' => '0'], 'sector.invalid_label'],
            'point before floors'     => [['lat' => '0', 'lng' => '0', 'floor_count' => '99'], 'dispatch.invalid_point'],
            'floors before ground'    => [['floor_count' => '99', 'lat' => '35.3100'], 'common.invalid_request'],
        ];
        foreach ($bad as $why => [$over, $key]) {
            $r = prepareMissionSectorBuilding($this->mission, $over + $ok);
            $this->assertFalse($r['ok'], $why);
            $this->assertSame(t($key), $r['error'], $why);
        }

        // 31 m outside is refused and says by how much and of which sector; 29 m is inside the tolerance.
        $out = prepareMissionSectorBuilding($this->mission, ['lat' => (string) $this->south(31), 'lng' => '25.1005'] + $ok);
        $this->assertFalse($out['ok']);
        $this->assertSame(t('sector.building_outside', ['sector' => 'Τομέας Κοίτης', 'metres' => 31]), $out['error']);
        $near = prepareMissionSectorBuilding($this->mission, ['lat' => (string) $this->south(29), 'lng' => '25.1005'] + $ok);
        $this->assertTrue($near['ok'], 'a doorway on the boundary is not refused');

        $plain = prepareMissionSectorBuilding($this->mission, ['label' => '  Κτίριο  ', 'floor_count' => ' 4 '] + $ok);
        $this->assertTrue($plain['ok']);
        $this->assertSame('Κτίριο', $plain['label']);
        $this->assertSame(4, $plain['floor_count']);
        $this->assertSame($sector, $plain['sector_id']);
        $this->assertSame('Τομέας Κοίτης', $plain['sector_label']);
        $this->assertSame([35.3005, 25.1005], [$plain['lat'], $plain['lng']], 'the point comes back as numbers');
        $this->assertSame(255, mb_strlen(prepareMissionSectorBuilding($this->mission, ['label' => str_repeat('Ω', 300)] + $ok)['label']));
        $this->assertSame(0, prepareMissionSectorBuilding($this->mission, ['floor_count' => ''] + $ok)['floor_count'], 'a blank number of floors is a single storey');
        $this->assertSame(50, prepareMissionSectorBuilding($this->mission, ['floor_count' => '50'] + $ok)['floor_count']);
    }

    public function testCreateWritesTheBuildingItsFloorsAndTheAuditAndARefusalWritesNothing(): void
    {
        $sector = $this->liveSector();
        $audits = (int) dbFetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'create_sector_building'");
        $r = createMissionSectorBuilding($this->mission, $this->adminId, ['sector_id' => (string) $sector, 'label' => 'Σχολείο', 'lat' => '35.3005', 'lng' => '25.1005', 'floor_count' => '2']);
        $this->assertTrue($r['ok']);
        $row = dbFetchOne("SELECT * FROM mission_sector_buildings WHERE id = ?", [$r['id']]);
        $this->assertSame([$sector, 'Σχολείο', 2, $this->adminId], [(int) $row['sector_id'], $row['label'], (int) $row['floor_count'], (int) $row['created_by']]);
        $this->assertEquals([35.3005, 25.1005], [(float) $row['lat'], (float) $row['lng']]);
        $floors = dbFetchAll("SELECT floor_number, is_required, checked_at FROM mission_sector_building_floors WHERE building_id = ? ORDER BY floor_number", [$r['id']]);
        $this->assertSame([0, 1, 2], array_map(fn($f) => (int) $f['floor_number'], $floors), 'the ground floor and one row for each floor above it');
        foreach ($floors as $f) {
            $this->assertSame(1, (int) $f['is_required']);
            $this->assertNull($f['checked_at']);
        }
        $this->assertSame($audits + 1, (int) dbFetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'create_sector_building'"));

        $single = createMissionSectorBuilding($this->mission, $this->adminId, ['sector_id' => (string) $sector, 'label' => 'Αποθήκη', 'lat' => '35.3002', 'lng' => '25.1002', 'floor_count' => '0']);
        $this->assertSame(1, (int) dbFetchValue("SELECT COUNT(*) FROM mission_sector_building_floors WHERE building_id = ?", [$single['id']]), 'a single-storey building still has its ground floor');

        $refused = createMissionSectorBuilding($this->mission, $this->adminId, ['sector_id' => (string) $sector, 'label' => '', 'lat' => '35.3005', 'lng' => '25.1005', 'floor_count' => '2']);
        $this->assertFalse($refused['ok']);
        $this->assertCount(2, $this->buildings(), 'a refused building writes nothing');
        $this->assertSame($audits + 2, (int) dbFetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'create_sector_building'"));

        // The lists the endpoint answers with carry it.
        $listed = array_column(loadMissionSectorsForUser($this->missionId, $this->adminId, true, false), null, 'id')[$sector];
        $this->assertSame(['Σχολείο', 'Αποθήκη'], array_column($listed['buildings'], 'label'));
    }

    public function testASectorOfThePlanStandsInForTheRowAndThePointMustStandInItsPolygon(): void
    {
        $staged = ['label' => 'Τομέας Α', 'geo' => self::SQUARE];
        $base = ['sector_id' => 0, 'label' => 'Κτίριο', 'lat' => '35.3005', 'lng' => '25.1005', 'floor_count' => '1'];
        $in = prepareMissionSectorBuilding($this->mission, $base, $staged);
        $this->assertTrue($in['ok']);
        $this->assertSame([0, 'Τομέας Α'], [$in['sector_id'], $in['sector_label']], 'there is no sector id yet');

        $out = prepareMissionSectorBuilding($this->mission, ['lat' => (string) $this->south(31)] + $base, $staged);
        $this->assertFalse($out['ok']);
        $this->assertSame(t('sector.building_outside', ['sector' => 'Τομέας Α', 'metres' => 31]), $out['error'], 'checked against the staged polygon');
        $this->assertTrue(prepareMissionSectorBuilding($this->mission, ['lat' => (string) $this->south(29)] + $base, $staged)['ok']);

        // The other checks still apply.
        $this->assertSame(t('sector.invalid_label'), prepareMissionSectorBuilding($this->mission, ['label' => ''] + $base, $staged)['error']);
        $this->assertSame(t('common.invalid_request'), prepareMissionSectorBuilding($this->mission, ['floor_count' => '51'] + $base, $staged)['error']);
        // As with a sector row that has no polygon to compare with, a staged sector without one cannot refuse anything.
        $this->assertTrue(prepareMissionSectorBuilding($this->mission, ['lat' => '35.9', 'lng' => '25.9'] + $base, ['label' => 'Χωρίς σχήμα', 'geo' => []])['ok']);
    }

    // ── Writing a building into a phase ─────────────────────────────────────────────────────────────────────────────

    public function testStagingWritesABuildingItemWithNoTeamAndNothingLive(): void
    {
        $phase = $this->phase();
        $sectorItem = $this->stageSector($phase, 'Τομέας Α');
        $r = $this->stageBuilding($phase, 'item:' . $sectorItem, ['label' => '  Κτίριο 1  ']);
        $this->assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        $row = $this->item($r['id']);
        $this->assertSame('building', $row['kind']);
        $this->assertNull($row['slot_id']);
        $this->assertNull($row['sent_at']);
        $payload = json_decode($row['payload'], true);
        $this->assertSame($sectorItem, $payload['sector_item_id']);
        $this->assertArrayNotHasKey('sector_id', $payload, 'there is no sector id yet: the plan\'s own item stands for it');
        $this->assertArrayNotHasKey('audience', $payload);
        $this->assertArrayNotHasKey('team_id', $payload);
        $this->assertSame(['Κτίριο 1', 2, 'Τομέας Α'], [$payload['label'], $payload['floor_count'], $payload['sector_label']]);
        $this->assertEquals([35.3005, 25.1005], [$payload['lat'], $payload['lng']]);
        $this->assertCount(0, $this->buildings(), 'nothing is made by writing it down');
        $this->assertCount(0, $this->sectors(), 'and neither is the sector');
    }

    public function testStagingRefusesWhatCouldNeverBeMade(): void
    {
        $phase = $this->phase();
        $sectorItem = $this->stageSector($phase);
        $ref = 'item:' . $sectorItem;
        $cases = [
            'no name'      => [['label' => ' '], t('sector.invalid_label')],
            'null island'  => [['lat' => 0, 'lng' => 0], t('dispatch.invalid_point')],
            'no latitude'  => [['lat' => null], t('dispatch.invalid_point')],
            'floors 51'    => [['floor_count' => 51], t('common.invalid_request')],
            'floors -3'    => [['floor_count' => -3], t('common.invalid_request')],
            'outside, 31 m' => [['lat' => $this->south(31), 'lng' => 25.1005], t('sector.building_outside', ['sector' => 'Τομέας Α', 'metres' => 31])],
            'far outside'  => [['lat' => 35.4, 'lng' => 25.4], null],
        ];
        foreach ($cases as $why => [$over, $error]) {
            $r = $this->stageBuilding($phase, $ref, $over);
            $this->assertFalse($r['ok'], $why);
            if ($error !== null) {
                $this->assertSame($error, $r['error'], $why);
            }
        }
        $this->assertSame(0, (int) dbFetchValue("SELECT COUNT(*) FROM mission_scenario_items WHERE kind = 'building'"), 'a refusal writes nothing');
        $this->assertTrue($this->stageBuilding($phase, $ref, ['lat' => $this->south(29), 'lng' => 25.1005])['ok'], '29 m is within the tolerance');
    }

    public function testASectorOfTheSameOrAnEarlierPhaseIsFineAndOfALaterOneIsRefused(): void
    {
        $first = $this->phase();
        $second = $this->phase();
        $inFirst = $this->stageSector($first, 'Τομέας Α');
        $inSecond = $this->stageSector($second, 'Τομέας Β');

        $this->assertTrue($this->stageBuilding($first, 'item:' . $inFirst)['ok'], 'same phase');
        $this->assertTrue($this->stageBuilding($second, 'item:' . $inFirst)['ok'], 'a sector of an earlier phase');
        $late = $this->stageBuilding($first, 'item:' . $inSecond);
        $this->assertFalse($late['ok'], 'a sector that is made after the building');
        $this->assertSame(t('scenario.building_before_sector', ['sector' => 'Τομέας Β', 'phase' => t('scenario.phase_n', ['n' => 2])]), $late['error']);
        $this->assertSame(2, (int) dbFetchValue("SELECT COUNT(*) FROM mission_scenario_items WHERE kind = 'building'"));
    }

    public function testNamingASectorThatIsNotThereOrNotOneIsRefused(): void
    {
        $phase = $this->phase();
        $other = $this->liveSector('Ξένος', ['id' => $this->otherMissionId, 'title' => 'Other']);
        $notASector = (int) dbInsert(
            "INSERT INTO mission_scenario_items (phase_id, mission_id, seq, kind, payload, created_by) VALUES (?, ?, 1, 'area', ?, ?)",
            [$phase, $this->missionId, json_encode(['label' => 'Περιοχή', 'geo' => self::SQUARE]), $this->adminId]
        );
        foreach (['' => 'building_sector_not_found', 'abc' => 'building_sector_not_found', 'item:0' => 'building_sector_not_found', '0' => 'building_sector_not_found',
                  '999999999' => 'building_sector_not_found', (string) $other => 'building_sector_not_found',
                  'item:999999999' => 'sector_item_not_found', 'item:' . $notASector => 'sector_item_not_found'] as $ref => $key) {
            $r = $this->stageBuilding($phase, (string) $ref);
            $this->assertFalse($r['ok'], "ref '$ref'");
            $this->assertSame(t('scenario.' . $key), $r['error'], "ref '$ref'");
        }
        // A sector item of ANOTHER mission's scenario is not this scenario's.
        $otherPhase = addScenarioPhase(['id' => $this->otherMissionId, 'title' => 'Other'], $this->adminId, '')['id'];
        $foreign = (int) dbInsert(
            "INSERT INTO mission_scenario_items (phase_id, mission_id, seq, kind, payload, created_by) VALUES (?, ?, 1, 'sector', ?, ?)",
            [$otherPhase, $this->otherMissionId, json_encode(['label' => 'Ξένο', 'geo' => self::SQUARE]), $this->adminId]
        );
        $this->assertSame(t('scenario.sector_item_not_found'), $this->stageBuilding($phase, 'item:' . $foreign)['error']);
        $this->assertSame(0, (int) dbFetchValue("SELECT COUNT(*) FROM mission_scenario_items WHERE kind = 'building'"));
    }

    public function testAPlayedPhaseAnUnknownPhaseAndAFullPhaseTakeNoMoreBuildings(): void
    {
        $phase = $this->phase();
        $sector = $this->liveSector();
        $this->assertTrue($this->stageBuilding($phase, (string) $sector)['ok']);
        $this->play($phase);
        $this->assertSame(t('scenario.phase_closed_for_orders'), $this->stageBuilding($phase, (string) $sector)['error']);
        $this->assertSame(t('scenario.phase_not_found'), $this->stageBuilding(999999999, (string) $sector)['error']);

        $full = $this->phase();
        for ($i = 0; $i < SCENARIO_MAX_ITEMS_PER_PHASE; $i++) {
            $this->assertTrue($this->stageBuilding($full, (string) $sector)['ok']);
        }
        $this->assertSame(t('scenario.too_many_orders', ['max' => SCENARIO_MAX_ITEMS_PER_PHASE]), $this->stageBuilding($full, (string) $sector)['error']);
    }

    public function testABuildingMayStandInASectorThatExists(): void
    {
        $phase = $this->phase();
        $sector = $this->liveSector('Παλιός τομέας');
        $r = $this->stageBuilding($phase, (string) $sector);
        $this->assertTrue($r['ok']);
        $payload = json_decode($this->item($r['id'])['payload'], true);
        $this->assertSame($sector, $payload['sector_id']);
        $this->assertArrayNotHasKey('sector_item_id', $payload);
        $this->assertSame('Παλιός τομέας', $payload['sector_label']);
        // ...and is checked against that sector's polygon.
        $this->assertFalse($this->stageBuilding($phase, (string) $sector, ['lat' => $this->south(31), 'lng' => 25.1005])['ok']);

        $card = $this->cardItem($r['id']);
        $this->assertFalse($card['sector_staged']);
        $this->assertFalse($card['sector_missing']);
        $this->assertNull($card['sector_item_id']);
        $this->assertSame('Παλιός τομέας', $card['sector_label']);
    }

    public function testASectorThatHasBeenMadeSinceIsUsedAsTheLiveSectorItIs(): void
    {
        $first = $this->phase();
        $second = $this->phase();
        $sectorItem = $this->stageSector($first, 'Τομέας Α');
        $this->assertTrue($this->play($first)['ok']);
        $liveSector = (int) $this->sectors()[0]['id'];

        $r = $this->stageBuilding($second, 'item:' . $sectorItem);
        $this->assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        $payload = json_decode($this->item($r['id'])['payload'], true);
        $this->assertSame($liveSector, $payload['sector_id'], 'written against the live sector');
        $this->assertArrayNotHasKey('sector_item_id', $payload, 'so nothing of the plan can come between them');
        // ...and it is checked against that sector's real polygon.
        $this->assertFalse($this->stageBuilding($second, 'item:' . $sectorItem, ['lat' => $this->south(31), 'lng' => 25.1005])['ok']);
    }

    // ── What the card knows ─────────────────────────────────────────────────────────────────────────────────────────

    public function testTheCardGivesABuildingNoAudienceNoTeamAndItsOwnType(): void
    {
        $first = $this->phase();
        $second = $this->phase();
        $sectorItem = $this->stageSector($first, 'Τομέας Α');
        $itemId = $this->stageBuilding($second, 'item:' . $sectorItem, ['label' => 'Σχολείο', 'floor_count' => 3])['id'];

        $card = $this->cardItem($itemId);
        $this->assertSame('building', $card['kind']);
        $this->assertSame('building', $card['type']);
        $this->assertSame('none', $card['audience'], 'nobody to widen to');
        $this->assertFalse($card['team_missing']);
        $this->assertFalse($card['unmapped']);
        $this->assertNull($card['team_id']);
        $this->assertNull($card['team_label']);
        $this->assertNull($card['slot_id']);
        $this->assertSame('Σχολείο', $card['label']);
        $this->assertSame(3, $card['floor_count']);
        $this->assertEquals([[35.3005, 25.1005]], $card['geo'], 'a point, as a list of one');
        $this->assertSame([$sectorItem, 'Τομέας Α', true, 1, false], [$card['sector_item_id'], $card['sector_label'], $card['sector_staged'], $card['sector_phase_n'], $card['sector_missing']]);
        $this->assertNull($card['building_id']);
        $this->assertNull($card['dispatch_id'], 'it is not a dispatch');
        $this->assertNull($card['area_id'], 'and not a sector row');
        $this->assertNull($card['state']);
    }

    // ── Play ────────────────────────────────────────────────────────────────────────────────────────────────────────

    public function testPlayMakesTheSectorThenTheBuildingInItWithItsFloors(): void
    {
        $phase = $this->phase();
        $sectorItem = $this->stageSector($phase, 'Τομέας Α');
        $this->liveSector('Ένας άλλος τομέας'); // a sector that exists: the building must not end up in it
        $b1 = $this->stageBuilding($phase, 'item:' . $sectorItem, ['label' => 'Σχολείο', 'floor_count' => 2])['id'];
        $b2 = $this->stageBuilding($phase, 'item:' . $sectorItem, ['label' => 'Αποθήκη', 'lat' => 35.3002, 'lng' => 25.1002, 'floor_count' => 0])['id'];

        $played = $this->play($phase);
        $this->assertSame([3, 0], [$played['sent'], $played['failed']]);
        $this->assertSame(['sector', 'building', 'building'], array_column($played['results'], 'kind'), 'the sector goes first');
        $made = dbFetchOne("SELECT id FROM mission_search_sectors WHERE mission_id = ? AND label = 'Τομέας Α'", [$this->missionId]);
        $this->assertNotNull($made);
        $buildings = $this->buildings((int) $made['id']);
        $this->assertSame(['Σχολείο', 'Αποθήκη'], array_column($buildings, 'label'), 'in the sector play made');
        $this->assertCount(0, $this->buildings((int) $this->sectors()[0]['id'] === (int) $made['id'] ? (int) $this->sectors()[1]['id'] : (int) $this->sectors()[0]['id']), 'and not in any other');
        $this->assertSame([2, 0], array_map(fn($b) => (int) $b['floor_count'], $buildings));
        $this->assertSame(
            [3, 1],
            array_map(fn($b) => (int) dbFetchValue("SELECT COUNT(*) FROM mission_sector_building_floors WHERE building_id = ?", [$b['id']]), $buildings),
            'the ground floor and the floors above it'
        );
        $this->assertSame(
            [(int) $buildings[0]['id'], (int) $buildings[1]['id']],
            array_map(fn($r) => $r['building_id'], array_slice($played['results'], 1)),
            'the results carry the buildings'
        );
        $this->assertNull($played['results'][0]['building_id']);
        $this->assertSame([$buildings[0]['id']], [$this->item($b1)['sent_ref_id']]);

        $card = $this->cardItem($b1);
        $this->assertTrue($card['sent']);
        $this->assertNull($card['dispatch_id'], 'a building is not a dispatch');
        $this->assertFalse($card['sector_staged']);
        $this->assertSame((int) $buildings[0]['id'], $card['building_id']);
        $this->assertSame('standing', $card['state'], 'a building is never finished: it is in force');
        $this->assertSame('standing', $this->cardItem($b2)['state']);
        $listed = array_column(loadMissionSectorsForUser($this->missionId, $this->adminId, true, false), null, 'id')[(int) $made['id']];
        $this->assertSame(['Σχολείο', 'Αποθήκη'], array_column($listed['buildings'], 'label'), 'the live list shows them');
    }

    public function testABuildingInALaterPhaseGoesIntoTheSectorTheEarlierPhaseMade(): void
    {
        $first = $this->phase();
        $second = $this->phase();
        $sectorItem = $this->stageSector($first, 'Τομέας Α');
        $this->stageBuilding($second, 'item:' . $sectorItem);
        $this->assertTrue($this->play($first)['ok']);
        $this->assertCount(0, $this->buildings());
        $r = $this->play($second);
        $this->assertSame([1, 0], [$r['sent'], $r['failed']]);
        $this->assertCount(1, $this->buildings((int) $this->sectors()[0]['id']));
    }

    public function testABuildingPlayedBeforeItsSectorFailsSaysWhichPhaseAndGuessesNothing(): void
    {
        $first = $this->phase();
        $second = $this->phase();
        $sectorItem = $this->stageSector($first, 'Τομέας Α');
        $building = $this->stageBuilding($second, 'item:' . $sectorItem)['id'];
        $this->liveSector('Τομέας που υπάρχει'); // not this one

        $r = $this->play($second);
        $this->assertSame([0, 1], [$r['sent'], $r['failed']]);
        $expected = t('scenario.item_sector_not_made', ['sector' => 'Τομέας Α', 'phase' => t('scenario.phase_n', ['n' => 1])]);
        $this->assertSame($expected, $r['results'][0]['error']);
        $this->assertCount(0, $this->buildings(), 'not put in the other sector');
        $this->assertSame($expected, $this->cardItem($building)['error']);
        $this->assertNull($this->item($building)['sent_at']);

        // Once the sector is made the retry makes the building in it.
        $this->assertTrue($this->play($first)['ok']);
        $retry = retryScenarioItem($this->mission, $building, $this->adminId, 'Building Admin');
        $this->assertTrue($retry['ok']);
        $this->assertTrue($retry['item']['ok'], (string) $retry['item']['error']);
        $this->assertSame($building, $retry['item']['item_id']);
        $sector = dbFetchOne("SELECT id FROM mission_search_sectors WHERE mission_id = ? AND label = 'Τομέας Α'", [$this->missionId]);
        $this->assertCount(1, $this->buildings((int) $sector['id']));
    }

    public function testABuildingBeforeItsSectorInTheSamePhaseFailsToo(): void
    {
        $phase = $this->phase();
        $sectorItem = $this->stageSector($phase, 'Τομέας Α');
        $building = $this->stageBuilding($phase, 'item:' . $sectorItem)['id'];
        // Put the building before its sector in the order of play.
        dbExecute("UPDATE mission_scenario_items SET seq = 1 WHERE id = ?", [$building]);
        dbExecute("UPDATE mission_scenario_items SET seq = 2 WHERE id = ?", [$sectorItem]);

        $r = $this->play($phase);
        $this->assertSame([1, 1], [$r['sent'], $r['failed']], 'the sector is made, the building was asked first');
        $this->assertSame(['building', 'sector'], array_column($r['results'], 'kind'));
        $this->assertSame(t('scenario.item_sector_not_made', ['sector' => 'Τομέας Α', 'phase' => t('scenario.phase_n', ['n' => 1])]), $r['results'][0]['error']);
        $this->assertCount(0, $this->buildings());
    }

    public function testASectorThatFailedToBeMadeTakesItsBuildingsWithItAndNothingIsGuessed(): void
    {
        $phase = $this->phase();
        $sectorItem = $this->stageSector($phase, 'Τομέας Α');
        $building = $this->stageBuilding($phase, 'item:' . $sectorItem)['id'];
        $this->liveSector('Τομέας που υπάρχει');
        dbExecute("DELETE FROM mission_teams WHERE id = ?", [$this->teamId]); // the sector's team is gone: it cannot be made

        $r = $this->play($phase);
        $this->assertSame([0, 2], [$r['sent'], $r['failed']]);
        $this->assertSame(t('scenario.item_sector_not_made', ['sector' => 'Τομέας Α', 'phase' => t('scenario.phase_n', ['n' => 1])]), $r['results'][1]['error']);
        $this->assertCount(1, $this->sectors(), 'only the sector that already existed');
        $this->assertCount(0, $this->buildings());
        $this->assertNull($this->item($building)['sent_at']);
    }

    public function testASectorTakenOffThePlanBehindTheGuardsBackFailsTheBuildingWithThatReason(): void
    {
        $phase = $this->phase();
        $sectorItem = $this->stageSector($phase, 'Τομέας Α');
        $building = $this->stageBuilding($phase, 'item:' . $sectorItem)['id'];
        dbExecute("DELETE FROM mission_scenario_items WHERE id = ?", [$sectorItem]);

        $card = $this->cardItem($building);
        $this->assertTrue($card['sector_missing'], 'the card shows it beforehand');
        $r = $this->play($phase);
        $this->assertSame([0, 1], [$r['sent'], $r['failed']]);
        $this->assertSame(t('scenario.item_sector_item_gone', ['sector' => 'Τομέας Α']), $r['results'][0]['error']);
        $this->assertCount(0, $this->buildings());
    }

    public function testASectorDeletedBeforeItsBuildingIsSentFailsItWithTheSectorsName(): void
    {
        $phase = $this->phase();
        $sector = $this->liveSector('Τομέας Κοίτης');
        $building = $this->stageBuilding($phase, (string) $sector)['id'];
        $this->liveSector('Άλλος τομέας');
        dbExecute("DELETE FROM mission_search_sectors WHERE id = ?", [$sector]);

        $this->assertTrue($this->cardItem($building)['sector_missing']);
        $r = $this->play($phase);
        $this->assertSame([0, 1], [$r['sent'], $r['failed']]);
        $this->assertSame(t('scenario.item_sector_gone', ['sector' => 'Τομέας Κοίτης']), $r['results'][0]['error']);
        $this->assertCount(0, $this->buildings(), 'nothing was made in the other sector');
    }

    public function testASectorOfAnotherMissionIsNeverAPlaceForTheBuilding(): void
    {
        $phase = $this->phase();
        $other = $this->liveSector('Ξένος', ['id' => $this->otherMissionId, 'title' => 'Other']);
        // Staging refuses it (testNamingASectorThat...); a payload that names it anyway fails at play.
        $itemId = (int) dbInsert(
            "INSERT INTO mission_scenario_items (phase_id, mission_id, seq, kind, payload, created_by) VALUES (?, ?, 1, 'building', ?, ?)",
            [$phase, $this->missionId, json_encode(['label' => 'Κτίριο', 'lat' => 35.3005, 'lng' => 25.1005, 'floor_count' => 1, 'sector_id' => $other, 'sector_label' => 'Ξένος']), $this->adminId]
        );
        $r = $this->play($phase);
        $this->assertSame([0, 1], [$r['sent'], $r['failed']]);
        $this->assertSame(t('scenario.item_sector_gone', ['sector' => 'Ξένος']), $r['results'][0]['error']);
        $this->assertSame(0, (int) dbFetchValue("SELECT COUNT(*) FROM mission_sector_buildings WHERE sector_id = ?", [$other]));
        $this->assertNull($this->item($itemId)['sent_at']);
    }

    public function testAnUnreadableBuildingFailsAndMakesNothing(): void
    {
        $phase = $this->phase();
        $sector = $this->liveSector();
        $unreadable = [
            'no words'      => ['label' => '  ', 'lat' => 35.3005, 'lng' => 25.1005, 'floor_count' => 1, 'sector_id' => $sector],
            'no point'      => ['label' => 'Κτίριο', 'lat' => 'x', 'lng' => 25.1005, 'floor_count' => 1, 'sector_id' => $sector],
            'no floors'     => ['label' => 'Κτίριο', 'lat' => 35.3005, 'lng' => 25.1005, 'sector_id' => $sector],
            'no sector'     => ['label' => 'Κτίριο', 'lat' => 35.3005, 'lng' => 25.1005, 'floor_count' => 1],
        ];
        foreach ($unreadable as $why => $payload) {
            $this->assertNull(scenarioBuildingInputFromPayload($payload), $why);
            dbInsert("INSERT INTO mission_scenario_items (phase_id, mission_id, seq, kind, payload, created_by) VALUES (?, ?, 1, 'building', ?, ?)", [$phase, $this->missionId, json_encode($payload), $this->adminId]);
        }
        $this->assertNotNull(scenarioBuildingInputFromPayload(['label' => 'Κτίριο', 'lat' => 35.3005, 'lng' => 25.1005, 'floor_count' => 0, 'sector_id' => $sector]));
        $r = $this->play($phase);
        $this->assertSame([0, 4], [$r['sent'], $r['failed']]);
        foreach ($r['results'] as $result) {
            $this->assertSame(t('scenario.item_unreadable'), $result['error']);
        }
        $this->assertCount(0, $this->buildings());
    }

    public function testAPointThatWasInsideWhenWrittenDownButNotAnymoreIsRefusedAtPlay(): void
    {
        $phase = $this->phase();
        $sector = $this->liveSector();
        $this->assertTrue($this->stageBuilding($phase, (string) $sector)['ok']);
        // The sector is redrawn elsewhere before play: the checks are the ones of the moment it is sent.
        dbExecute("UPDATE mission_search_sectors SET geo = ? WHERE id = ?", [json_encode([[35.5, 25.5], [35.5, 25.6], [35.6, 25.6], [35.6, 25.5]]), $sector]);
        $r = $this->play($phase);
        $this->assertSame([0, 1], [$r['sent'], $r['failed']]);
        $this->assertStringContainsString('μ.', $r['results'][0]['error']);
        $this->assertCount(0, $this->buildings());
    }

    // ── What is in the way of taking things off the plan ────────────────────────────────────────────────────────────

    public function testASectorThatBuildingsStillWaitForCannotBeTakenOffThePlan(): void
    {
        $phase = $this->phase();
        $sectorItem = $this->stageSector($phase, 'Τομέας Α');
        $b1 = $this->stageBuilding($phase, 'item:' . $sectorItem)['id'];
        $b2 = $this->stageBuilding($phase, 'item:' . $sectorItem, ['label' => 'Κτίριο 2'])['id'];

        $this->assertSame(t('scenario.sector_in_use', ['sector' => 'Τομέας Α', 'n' => 2]), deleteScenarioItem($this->mission, $sectorItem, $this->adminId));
        $this->assertNull(deleteScenarioItem($this->mission, $b1, $this->adminId));
        $this->assertSame(t('scenario.sector_in_use', ['sector' => 'Τομέας Α', 'n' => 1]), deleteScenarioItem($this->mission, $sectorItem, $this->adminId));
        $this->assertNull(deleteScenarioItem($this->mission, $b2, $this->adminId));
        $this->assertNull(deleteScenarioItem($this->mission, $sectorItem, $this->adminId), 'free once no building waits for it');
        $this->assertSame(0, (int) dbFetchValue("SELECT COUNT(*) FROM mission_scenario_items WHERE phase_id = ?", [$phase]));
    }

    public function testASectorWithNoBuildingsAndABuildingInALiveSectorAreFreeToGo(): void
    {
        $phase = $this->phase();
        $sectorItem = $this->stageSector($phase, 'Τομέας Α');
        $this->assertNull(deleteScenarioItem($this->mission, $sectorItem, $this->adminId));
        $live = $this->liveSector();
        $b = $this->stageBuilding($phase, (string) $live)['id'];
        $this->assertNull(deleteScenarioItem($this->mission, $b, $this->adminId), 'a building holds nothing of the plan');
    }

    public function testAPhaseWhoseSectorBuildingsOfAnotherPhaseNeedCannotBeDeleted(): void
    {
        $first = $this->phase();
        $second = $this->phase();
        $sectorItem = $this->stageSector($first, 'Τομέας Α');
        $building = $this->stageBuilding($second, 'item:' . $sectorItem)['id'];

        $this->assertSame(t('scenario.phase_sector_in_use', ['sector' => 'Τομέας Α']), deleteScenarioPhase($this->mission, $first, $this->adminId));
        $this->assertNotNull(scenarioPhaseRow($this->missionId, $first), 'it stays');
        $this->assertNull(deleteScenarioPhase($this->mission, $second, $this->adminId), 'the phase that holds only the building can go');
        $this->assertNull(deleteScenarioPhase($this->mission, $first, $this->adminId), 'and then the sector\'s');
        $this->assertNull(dbFetchOne("SELECT id FROM mission_scenario_items WHERE id = ?", [$building]));
    }

    public function testAPhaseHoldingASectorAndItsOwnBuildingsGoesTogether(): void
    {
        $phase = $this->phase();
        $sectorItem = $this->stageSector($phase, 'Τομέας Α');
        $this->stageBuilding($phase, 'item:' . $sectorItem);
        $this->assertNull(deleteScenarioPhase($this->mission, $phase, $this->adminId));
        $this->assertSame(0, (int) dbFetchValue("SELECT COUNT(*) FROM mission_scenario_items WHERE mission_id = ?", [$this->missionId]));
    }

    public function testASectorThatWasSentNoLongerHoldsItsBuildingsOnThePlan(): void
    {
        $first = $this->phase();
        $second = $this->phase();
        $sectorItem = $this->stageSector($first, 'Τομέας Α');
        $this->stageBuilding($second, 'item:' . $sectorItem);
        $this->play($first);
        // The sector is a real sector now; the building (written before) still names the item, which the plan keeps.
        $this->assertSame(t('scenario.sector_in_use', ['sector' => 'Τομέας Α', 'n' => 1]), deleteScenarioItem($this->mission, $sectorItem, $this->adminId), 'its unsent building would lose the sector it names');
    }

    // ── States, progress, withdrawal ────────────────────────────────────────────────────────────────────────────────

    public function testABuildingOfAnotherMissionIsNeverReadAsStanding(): void
    {
        $otherSector = $this->liveSector('Ξένος', ['id' => $this->otherMissionId, 'title' => 'Other']);
        $foreignBuilding = (int) createMissionSectorBuilding(['id' => $this->otherMissionId, 'title' => 'Other'], $this->adminId, ['sector_id' => (string) $otherSector, 'label' => 'Ξένο κτίριο', 'lat' => '35.3005', 'lng' => '25.1005', 'floor_count' => '1'])['id'];
        $mine = $this->liveSector('Δικός μου');
        $myBuilding = (int) createMissionSectorBuilding($this->mission, $this->adminId, ['sector_id' => (string) $mine, 'label' => 'Δικό μου', 'lat' => '35.3005', 'lng' => '25.1005', 'floor_count' => '1'])['id'];
        $rows = [
            ['id' => 1, 'kind' => 'building', 'sent_at' => '2026-11-07 10:00:00', 'sent_ref_id' => $myBuilding],
            ['id' => 2, 'kind' => 'building', 'sent_at' => '2026-11-07 10:00:00', 'sent_ref_id' => $foreignBuilding],
            ['id' => 3, 'kind' => 'building', 'sent_at' => '2026-11-07 10:00:00', 'sent_ref_id' => 999999999],
            ['id' => 4, 'kind' => 'building', 'sent_at' => null, 'sent_ref_id' => null],
        ];
        $states = scenarioItemLiveStates($this->missionId, $rows);
        $this->assertSame('standing', $states[1]['state']);
        $this->assertSame('withdrawn', $states[2]['state'], 'a building of another mission is not this scenario\'s');
        $this->assertSame('withdrawn', $states[3]['state'], 'one that is gone');
        $this->assertArrayNotHasKey(4, $states, 'one that was not sent has no state');
    }

    public function testADeletedSectorTakesItsBuildingsAndTheCardReadsThemAsTakenBack(): void
    {
        $phase = $this->phase();
        $sectorItem = $this->stageSector($phase, 'Τομέας Α');
        $b = $this->stageBuilding($phase, 'item:' . $sectorItem)['id'];
        $this->play($phase);
        $this->assertSame('standing', $this->cardItem($b)['state']);
        dbExecute("DELETE FROM mission_search_sectors WHERE id = ?", [(int) $this->sectors()[0]['id']]);
        $this->assertCount(0, $this->buildings());
        $this->assertSame('withdrawn', $this->cardItem($b)['state']);
    }

    public function testABuildingIsInNoCountOfAPhaseAndCannotBeWithdrawnByItself(): void
    {
        $phase = $this->phase();
        $sector = $this->liveSector();
        $this->stageBuilding($phase, (string) $sector);
        $this->stageBuilding($phase, (string) $sector, ['label' => 'Κτίριο 2']);
        $this->play($phase);
        $p = $this->progress($phase);
        $this->assertEquals(['total' => 0, 'done' => 0, 'open' => 0, 'standing' => 0, 'withdrawn' => 0, 'failed' => 0, 'withdrawable' => 0], array_intersect_key($p, array_flip(['total', 'done', 'open', 'standing', 'withdrawn', 'failed', 'withdrawable'])));
        $this->assertFalse($p['can_withdraw']);
        $this->assertFalse($p['complete']);
        $this->assertSame(t('scenario.withdraw_nothing'), withdrawScenarioPhase($this->mission, $phase, $this->adminId)['error']);
        $this->assertCount(2, $this->buildings(), 'nothing was taken');
    }

    public function testWithdrawingThePhaseTakesTheSectorOffItsTeamAndLeavesItsBuildings(): void
    {
        $phase = $this->phase();
        $sectorItem = $this->stageSector($phase, 'Τομέας Α');
        $b = $this->stageBuilding($phase, 'item:' . $sectorItem)['id'];
        $this->play($phase);
        $made = (int) $this->sectors()[0]['id'];
        $this->assertEquals(['total' => 1, 'open' => 1, 'standing' => 0, 'withdrawable' => 1], array_intersect_key($this->progress($phase), array_flip(['total', 'open', 'standing', 'withdrawable'])), 'only the sector counts');

        $w = withdrawScenarioPhase($this->mission, $phase, $this->adminId);
        $this->assertTrue($w['ok']);
        $this->assertSame([1, 0], [$w['withdrawn'], $w['failed']], 'the sector; the building is not among them');
        $this->assertSame(['sector'], array_column($w['results'], 'kind'));
        $this->assertNull($this->sectors()[0]['team_id'], 'the sector is unassigned');
        $this->assertCount(1, $this->buildings($made), 'the building stays in it');
        $this->assertCount(3, dbFetchAll("SELECT id FROM mission_sector_building_floors WHERE building_id = ?", [$this->buildings($made)[0]['id']]), 'and its three floors (the ground floor and two above it)');
        $this->assertSame('standing', $this->cardItem($b)['state'], 'still in force');
    }

    // ── Where the code is ───────────────────────────────────────────────────────────────────────────────────────────

    public function testTheScenarioNeverWritesABuildingItselfAndTheEndpointUsesTheSharedFunctions(): void
    {
        $src = $this->source('includes/functions-scenario.php');
        $this->assertStringContainsString('createMissionSectorBuilding($mission, $userId, $input)', $src);
        $this->assertStringContainsString('prepareMissionSectorBuilding($mission, [', $src);
        $this->assertStringNotContainsString('INSERT INTO mission_sector_buildings', $src);
        $this->assertStringNotContainsString('INSERT INTO mission_sector_building_floors', $src);
        $this->assertStringNotContainsString('DELETE FROM mission_sector_buildings', $src, 'taking a phase back never deletes a building');

        $endpoint = $this->source('mission-sector.php');
        $this->assertStringContainsString("if (\$action === 'create_building') {", $endpoint);
        $this->assertStringContainsString('createMissionSectorBuilding($mission, (int) $userId', $endpoint);
        $this->assertStringNotContainsString('INSERT INTO mission_sector_buildings', $endpoint);
        $this->assertStringNotContainsString('INSERT INTO mission_sector_building_floors', $endpoint);
        $this->assertStringNotContainsString('pointToPolygonDistanceMeters', $endpoint, 'the check lives in the function now');
        $fw = $this->source('includes/functions-warroom.php');
        $this->assertSame(1, substr_count($fw, 'INSERT INTO mission_sector_buildings'));
        $this->assertSame(1, substr_count($fw, 'INSERT INTO mission_sector_building_floors'));
    }

    public function testTheBuildingsOfThePlanArePlayedAfterTheirSectorByTheSendBranch(): void
    {
        $src = $this->source('includes/functions-scenario.php');
        $send = substr($src, (int) strpos($src, 'function scenarioSendItem('), 5200);
        $resolve = (int) strpos($send, 'scenarioResolveStagedBuildingSector(');
        $input = (int) strpos($send, "'building' => scenarioBuildingInputFromPayload(");
        $create = (int) strpos($send, "'building' => createMissionSectorBuilding(");
        $this->assertGreaterThan(0, $resolve);
        $this->assertLessThan($input, $resolve, 'the sector is looked up before the payload is read');
        $this->assertLessThan($create, $input);
        $this->assertStringContainsString("t('scenario.item_sector_gone'", $send);
        // The withdrawal and the progress both leave buildings out.
        $this->assertStringContainsString("['order', 'area', 'building']", $src);
        $this->assertStringContainsString("['area', 'building']", $src);
    }

    public function testTheScenarioEndpointStagesBuildingsForCommandOnlyAndHandsTheLivePairBack(): void
    {
        $src = $this->source('mission-scenario.php');
        $add = substr($src, (int) strpos($src, "if (\$action === 'add_building') {"), 2000);
        $this->assertStringContainsString('addScenarioBuildingItem($mission, $phaseId, (int) $userId', $add);
        foreach (['sector_id', 'label', 'lat', 'lng', 'floor_count'] as $field) {
            $this->assertStringContainsString("'$field'", $add);
            $this->assertStringContainsString("post('$field')", $add);
        }
        $this->assertStringNotContainsString("post('team_id')", $add, 'a building has no team to name');
        $this->assertStringContainsString("deleteScenarioPhase(\$mission, \$phaseId, (int) \$userId)", $add, 'a refused building takes its new phase with it');
        $this->assertStringContainsString("if (!\$result['ok']) {
        if (\$madePhase) {
            deleteScenarioPhase(", $add, 'and only when it made that phase');
        $this->assertStringContainsString("in_array(\$r['kind'] ?? '', ['sector', 'area', 'building'], true)", $src, 'play and retry hand the live pair back for a building');
        // The permission check comes before any action.
        $this->assertLessThan((int) strpos($src, "if (\$action === 'add_phase')"), (int) strpos($src, 'scenario.no_permission'));
    }

    public function testTheKindColumnKnowsBuildingInTheSchemaTheMigrationAndTheTestDatabase(): void
    {
        $this->assertGreaterThanOrEqual(210, DB_SCHEMA_VERSION);
        $this->assertStringContainsString("`kind` ENUM('dispatch','sector','route','order','restricted','area','building')", $this->source('sql/schema.sql'));
        $this->assertStringContainsString("'version'     => 210,", $this->source('includes/migrations.php'));
        $this->assertStringContainsString("MODIFY COLUMN kind ENUM('dispatch','sector','route','order','restricted','area','building')", $this->source('includes/migrations.php'));
        $type = (string) dbFetchValue("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mission_scenario_items' AND COLUMN_NAME = 'kind'");
        $this->assertStringContainsString("'building'", $type, 'run php tests/migrate-fixture.php after adding a migration');
    }

    // ── The page and the card script ────────────────────────────────────────────────────────────────────────────────

    public function testThePageArmsABuildingInAStagedSectorAndWritesItIntoThePlan(): void
    {
        $page = $this->source('war-room.php');
        $this->assertStringContainsString('let addingBuildingToStagedSector = null;', $page);
        $this->assertStringContainsString('function armStagedBuilding(sector) {', $page);
        $arm = substr($page, (int) strpos($page, 'function armStagedBuilding(sector) {'), 1300);
        $this->assertStringContainsString('if (!map || !sector || !Array.isArray(sector.geo) || sector.geo.length < 3) return;', $arm, 'only a sector with an outline can be armed');
        $this->assertStringContainsString('measureDisarm();', $arm);
        $this->assertStringContainsString('addingBuildingToSectorId = null;', $arm, 'one tool at a time');
        $this->assertStringContainsString("classList.add('wr-draw-active')", $arm);
        $this->assertStringContainsString("t('scenario.building_pick_hint')", $arm);

        // The click that places it.
        $branch = substr($page, (int) strpos($page, '} else if (addingBuildingToStagedSector !== null) {'), 4200);
        $this->assertStringContainsString('addingBuildingToStagedSector = null;', $branch, 'one-shot');
        $this->assertStringContainsString("classList.remove('wr-draw-active')", $branch);
        $this->assertStringContainsString('id="stagedBuildingLabelInput"', $branch);
        $this->assertStringContainsString('id="stagedBuildingFloorsInput"', $branch);
        $this->assertStringContainsString('id="stagedBuildingPhase"', $branch);
        $this->assertStringContainsString("t('scenario.add_to_scenario')", $branch);
        $this->assertStringContainsString("t('scenario.building_add_to_scenario_title')", $branch);
        $this->assertStringContainsString("scenarioApi.stageBuilding(document.getElementById('stagedBuildingPhase').value", $branch);
        $this->assertStringContainsString("sector_id: 'item:' + staged.item_id", $branch);
        $this->assertStringContainsString('lat: latlng.lat, lng: latlng.lng, floor_count: floorCount', $branch);
        // Only the unplayed phases from the sector's own phase on are offered.
        $this->assertStringContainsString('i >= fromIndex && !p.played_at', $branch);
        // It stays open when the server refuses (the person is told why by the card script), and closes when it takes it.
        $this->assertStringContainsString('if (res && res.ok) map.closePopup(); else saveBtn.disabled = false;', $branch);
        // The sector's own mode is checked first, so the live button keeps working as it did.
        $this->assertLessThan((int) strpos($page, '} else if (addingBuildingToStagedSector !== null) {'), (int) strpos($page, '} else if (addingBuildingToSectorId !== null) {'));
        // Given up wherever the live mode is given up.
        $this->assertStringContainsString("addingBuildingToSectorId = null;\n    addingBuildingToStagedSector = null;\n    measureClear();", $page);
        $this->assertStringContainsString('addingBuildingToSectorId !== null || addingBuildingToStagedSector !== null) return;', $page);
    }

    public function testTheCardScriptKnowsBuildingsAndEveryNewStringIsInBothLanguages(): void
    {
        $js = $this->source('assets/js/war-room-scenario.js');
        $this->assertStringContainsString("request('add_building',", $js);
        $this->assertStringContainsString('stageBuilding: stageBuilding', $js);
        $this->assertStringContainsString('data-sc="add-building"', $js);
        $this->assertStringContainsString('armStagedBuilding({item_id: hit.item.id', $js);
        $this->assertStringContainsString("item.type === 'sector' && !item.sent && Array.isArray(item.geo) && item.geo.length >= 3", $js, 'the button is only for a sector still to be made');
        $this->assertStringContainsString('buildingPinIcon(phase.n)', $js);
        $lang = (string) file_get_contents(__DIR__ . '/../includes/lang/war-room.php');
        $keys = [
            'scenario.kind_building', 'scenario.building_add_to_scenario_title', 'scenario.building_sector_not_found', 'scenario.sector_item_not_found',
            'scenario.building_before_sector', 'scenario.item_sector_item_gone', 'scenario.item_sector_not_made', 'scenario.item_sector_gone',
            'scenario.sector_gone_hint', 'scenario.sector_in_use', 'scenario.phase_sector_in_use', 'scenario.building_in_sector',
            'scenario.building_in_staged_sector', 'scenario.building_floors', 'scenario.building_ground_only', 'scenario.building_stays_hint',
            'scenario.building_add_btn', 'scenario.building_add_title', 'scenario.building_pick_hint', 'scenario.building_popup_phase',
            'scenario.play_confirm_sector_missing',
        ];
        foreach ($keys as $key) {
            $this->assertSame(2, substr_count($lang, "'$key' =>"), "$key is in Greek and in English");
        }
        // Each is used by the code (a string nobody asks for is a string to remove).
        $everything = $js . $this->source('war-room.php') . $this->source('includes/functions-scenario.php');
        foreach ($keys as $key) {
            $this->assertStringContainsString($key, $everything, "$key is used");
        }
    }
}
