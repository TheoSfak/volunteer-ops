<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Search areas in the scenario (v3.459.0): the outer polygon a search zone is divided into sectors from, written down in a
 * phase and made when play is pressed - and, the reason it is in a plan at all, sectors written down against an area that
 * does not exist yet. includes/functions-scenario.php (addScenarioAreaItem, scenarioAreaInputFromPayload,
 * scenarioStagedAreaForSector, scenarioResolveStagedSectorArea, scenarioUnsentSectorsOfStagedArea, the 'area' branches of
 * scenarioSendItem / scenarioItemLiveStates / scenarioPhaseProgress / withdrawScenarioPhase, the refusals in
 * deleteScenarioItem / deleteScenarioPhase) and prepare/createMissionSearchArea() in includes/functions-warroom.php, which
 * mission-sector.php's create_area now sends through.
 *
 * The things that must hold: the area is the one the window's save makes (same row, audit and checks); an area has no team,
 * no slot and no «όλες οι ομάδες»; a sector written against the plan's own area is cut from the live area that item became
 * and from NO other - if the area is not there (not played yet, failed, taken off the plan, deleted since) the sector FAILS
 * with that reason and makes nothing; an area that sectors still wait for cannot be taken off the plan, nor can its phase;
 * and taking a phase back never deletes an area (it would delete the sectors on it).
 *
 * Runs inside a transaction that is always rolled back.
 */
final class ScenarioAreaTest extends TestCase
{
    private int $missionId;
    private int $otherMissionId;
    private int $adminId;
    private int $teamId;
    private array $mission;

    protected function setUp(): void
    {
        db()->beginTransaction();
        $this->adminId = (int) dbInsert("INSERT INTO users (name, email, password) VALUES (?, ?, ?)", ['Area Admin', 'sa-' . uniqid('', true) . '@example.invalid', 'x']);
        $missionTypeId = (int) dbFetchValue("SELECT id FROM mission_types ORDER BY id LIMIT 1");
        $mk = function (string $title) use ($missionTypeId): int {
            return (int) dbInsert(
                "INSERT INTO missions (title, location, start_datetime, end_datetime, mission_type_id) VALUES (?, ?, ?, ?, ?)",
                [$title, 'Ηράκλειο', date('Y-m-d H:i:s', time() - 7200), date('Y-m-d H:i:s', time() + 7200), $missionTypeId]
            );
        };
        $this->missionId = $mk('Area Mission');
        $this->otherMissionId = $mk('Other Area Mission');
        $this->mission = ['id' => $this->missionId, 'title' => 'Area Mission'];
        $this->teamId = (int) dbInsert("INSERT INTO mission_teams (mission_id, codename, team_number, created_by) VALUES (?, 'ΑΛΦΑ', 1, ?)", [$this->missionId, $this->adminId]);
    }

    protected function tearDown(): void
    {
        db()->rollBack();
    }

    private const TRI = '[[35.0,24.8],[35.0,24.9],[35.1,24.9]]';

    private function phase(): int
    {
        $r = addScenarioPhase($this->mission, $this->adminId, '');
        $this->assertTrue($r['ok']);
        return $r['id'];
    }

    private function stageArea(int $phaseId, array $over = []): array
    {
        return addScenarioAreaItem($this->mission, $phaseId, $this->adminId, $over + ['label' => 'Περιοχή Α', 'geo' => self::TRI]);
    }

    private function row(array $over = []): array
    {
        return $over + ['team_id' => (string) $this->teamId, 'label' => 'Τομέας 1', 'geo' => [[35.0, 24.8], [35.0, 24.9], [35.1, 24.9]]];
    }

    /** Sectors written against the plan's own area $areaItemId. */
    private function stageSectors(int $phaseId, int $areaItemId, array $rows): array
    {
        return addScenarioSectorItems($this->mission, $phaseId, $this->adminId, 0, $rows, $areaItemId);
    }

    private function play(int $phaseId): array
    {
        return playScenarioPhase($this->mission, $phaseId, $this->adminId, 'Area Admin');
    }

    private function areas(?int $missionId = null): array
    {
        return dbFetchAll("SELECT * FROM mission_search_areas WHERE mission_id = ? ORDER BY id", [$missionId ?? $this->missionId]);
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

    /** A search area that exists already, made by hand. */
    private function liveArea(string $label = 'Παλιά περιοχή'): int
    {
        return (int) createMissionSearchArea($this->mission, $this->adminId, ['label' => $label, 'geo' => self::TRI])['id'];
    }

    private function source(string $rel): string
    {
        $path = __DIR__ . '/../' . $rel;
        return $rel === 'war-room.php' ? str_replace("\r\n", "\n", (string) WarRoomSource::full()) : str_replace("\r\n", "\n", (string) file_get_contents($path));
    }

    // ── The moved functions ─────────────────────────────────────────────────────────────────────────────────────────

    public function testTheChecksAreTheOnesTheEndpointAlwaysHad(): void
    {
        $bad = [
            'no name'              => [['label' => '   ', 'geo' => self::TRI], 'sector.invalid_label'],
            'not json'             => [['label' => 'x', 'geo' => 'not json'], 'dispatch.polygon_needs_3_points'],
            'no geo at all'        => [['label' => 'x'], 'dispatch.polygon_needs_3_points'],
            'two points'           => [['label' => 'x', 'geo' => '[[35.0,24.8],[35.0,24.9]]'], 'dispatch.polygon_needs_3_points'],
            'null island'          => [['label' => 'x', 'geo' => '[[35.0,24.8],[0,0],[35.1,24.9]]'], 'dispatch.invalid_point'],
            'latitude 91'          => [['label' => 'x', 'geo' => '[[91,24.8],[35.0,24.9],[35.1,24.9]]'], 'dispatch.invalid_point'],
            'longitude 180.5'      => [['label' => 'x', 'geo' => '[[35.0,24.8],[35.0,24.9],[35.1,180.5]]'], 'dispatch.invalid_point'],
            'a point that is text' => [['label' => 'x', 'geo' => '["12","34","56"]'], 'dispatch.invalid_point'],
            'a short point'        => [['label' => 'x', 'geo' => '[[35.0,24.8],[35.0],[35.1,24.9]]'], 'dispatch.invalid_point'],
            'ring 4'               => [['label' => 'x', 'geo' => self::TRI, 'ring_index' => '4'], 'common.invalid_request'],
            'ring -1'              => [['label' => 'x', 'geo' => self::TRI, 'ring_index' => '-1'], 'common.invalid_request'],
            // The order of the checks is the endpoint's: the name, then the points, then the ring.
            'name before ring'     => [['label' => '', 'geo' => 'not json', 'ring_index' => '9'], 'sector.invalid_label'],
            'points before ring'   => [['label' => 'x', 'geo' => 'not json', 'ring_index' => '9'], 'dispatch.polygon_needs_3_points'],
        ];
        foreach ($bad as $why => [$input, $key]) {
            $r = prepareMissionSearchArea($input);
            $this->assertFalse($r['ok'], $why);
            $this->assertSame(t($key), $r['error'], $why);
        }

        $plain = prepareMissionSearchArea(['label' => '  Περιοχή  ', 'geo' => '[["35.0","24.8"],["35.0","24.9"],["35.1","24.9"]]', 'ring_index' => '']);
        $this->assertTrue($plain['ok']);
        $this->assertSame('Περιοχή', $plain['label']);
        $this->assertNull($plain['ring_index'], 'a hand-drawn area has no ring');
        $this->assertEquals([[35.0, 24.8], [35.0, 24.9], [35.1, 24.9]], $plain['geo'], 'numeric text becomes numbers');
        $this->assertSame(255, mb_strlen(prepareMissionSearchArea(['label' => str_repeat('Ω', 300), 'geo' => self::TRI])['label']));
        foreach (['0' => 0, '3' => 3, ' 2' => 2, '1.9' => 1, 'abc' => 0] as $raw => $ring) {
            $this->assertSame($ring, prepareMissionSearchArea(['label' => 'x', 'geo' => self::TRI, 'ring_index' => (string) $raw])['ring_index'], 'ring ' . $raw);
        }
    }

    public function testCreateWritesTheRowTheAuditAndARefusalWritesNothing(): void
    {
        $audits = (int) dbFetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'create_mission_search_area'");
        $r = createMissionSearchArea($this->mission, $this->adminId, ['label' => 'Κοίτη', 'geo' => self::TRI, 'ring_index' => '2']);
        $this->assertTrue($r['ok']);
        $row = dbFetchOne("SELECT * FROM mission_search_areas WHERE id = ?", [$r['id']]);
        $this->assertSame([$this->missionId, 'Κοίτη', 2, $this->adminId], [(int) $row['mission_id'], $row['label'], (int) $row['ring_index'], (int) $row['created_by']]);
        $this->assertEquals([[35.0, 24.8], [35.0, 24.9], [35.1, 24.9]], json_decode($row['geo'], true));
        $this->assertSame($audits + 1, (int) dbFetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'create_mission_search_area'"));
        $this->assertSame(['Κοίτη'], array_column(loadMissionSearchAreasForUser($this->missionId, true), 'label'));

        $refused = createMissionSearchArea($this->mission, $this->adminId, ['label' => '', 'geo' => self::TRI]);
        $this->assertFalse($refused['ok']);
        $this->assertCount(1, $this->areas(), 'a refused area writes nothing');
        $this->assertSame($audits + 1, (int) dbFetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'create_mission_search_area'"));
    }

    public function testTheLoaderBreaksTiesById(): void
    {
        // Areas made in the same second (a play makes several) used to come back in either order.
        $ids = [];
        foreach (['Ε', 'Δ', 'Γ', 'Β', 'Α'] as $label) {
            $ids[] = (int) dbInsert(
                "INSERT INTO mission_search_areas (mission_id, label, geo, created_by, created_at) VALUES (?, ?, ?, ?, '2026-11-01 10:00:00')",
                [$this->missionId, $label, self::TRI, $this->adminId]
            );
        }
        $this->assertSame($ids, array_column(loadMissionSearchAreasForUser($this->missionId, true), 'id'));
    }

    // ── Staging an area ─────────────────────────────────────────────────────────────────────────────────────────────

    public function testStagingWritesAnAreaItemWithNoTeamAndNothingLive(): void
    {
        $phase = $this->phase();
        $r = $this->stageArea($phase, ['label' => '  Κοίτη  ', 'ring_index' => '3']);
        $this->assertTrue($r['ok']);
        $row = $this->item($r['id']);
        $this->assertSame('area', $row['kind']);
        $this->assertNull($row['slot_id']);
        $this->assertNull($row['sent_at']);
        $payload = json_decode($row['payload'], true);
        $this->assertSame(['label', 'geo'], array_keys($payload), 'a label and points, nothing else: no team, no ring');
        $this->assertSame('Κοίτη', $payload['label']);
        $this->assertSame([], $this->areas(), 'nothing live until play');
        $this->assertSame(1, (int) dbFetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'stage_scenario_item' AND notes LIKE '%\"type\":\"search_area\"%'"));
    }

    public function testStagingRefusesWhatCouldNeverBeMade(): void
    {
        $phase = $this->phase();
        $cases = [
            'no name'    => [['label' => ''], 'sector.invalid_label'],
            'two points' => [['geo' => '[[35.0,24.8],[35.0,24.9]]'], 'dispatch.polygon_needs_3_points'],
            'bad point'  => [['geo' => '[[35.0,24.8],[0,0],[35.1,24.9]]'], 'dispatch.invalid_point'],
            'not json'   => [['geo' => 'nope'], 'dispatch.polygon_needs_3_points'],
        ];
        foreach ($cases as $why => [$over, $key]) {
            $r = $this->stageArea($phase, $over);
            $this->assertFalse($r['ok'], $why);
            $this->assertSame(t($key), $r['error'], $why);
        }
        $this->assertSame(0, (int) dbFetchValue("SELECT COUNT(*) FROM mission_scenario_items WHERE phase_id = ?", [$phase]), 'a refusal writes nothing');
    }

    public function testAPlayedPhaseAnotherMissionsPhaseAndAFullPhaseTakeNoMoreAreas(): void
    {
        $phase = $this->phase();
        $this->assertTrue($this->stageArea($phase)['ok']);
        $this->assertTrue($this->play($phase)['ok']);
        $this->assertSame(t('scenario.phase_closed_for_orders'), $this->stageArea($phase)['error']);

        $foreign = addScenarioPhase(['id' => $this->otherMissionId, 'title' => 'O'], $this->adminId, '')['id'];
        $this->assertSame(t('scenario.phase_not_found'), $this->stageArea($foreign)['error']);

        $full = $this->phase();
        for ($i = 0; $i < SCENARIO_MAX_ITEMS_PER_PHASE; $i++) {
            $this->assertTrue($this->stageArea($full, ['label' => 'Π' . $i])['ok']);
        }
        $this->assertSame(t('scenario.too_many_orders', ['max' => SCENARIO_MAX_ITEMS_PER_PHASE]), $this->stageArea($full)['error']);
    }

    public function testTheCardGivesAnAreaNoAudienceNoTeamAndItsOwnType(): void
    {
        $phase = $this->phase();
        $id = $this->stageArea($phase, ['label' => 'Δάσος'])['id'];
        $card = $this->cardItem($id);
        $this->assertSame('area', $card['kind']);
        $this->assertSame('search_area', $card['type'], 'not "polygon": a dispatch to an area is another thing');
        $this->assertSame('none', $card['audience']);
        $this->assertNull($card['team_id']);
        $this->assertFalse($card['team_missing']);
        $this->assertFalse($card['unmapped']);
        $this->assertSame('Δάσος', $card['label']);
        $this->assertEquals([[35.0, 24.8], [35.0, 24.9], [35.1, 24.9]], $card['geo']);
        $this->assertNull($card['dispatch_id']);
        $this->assertNull($card['search_area_id']);
        $this->assertNull($card['state']);
        $this->assertFalse($card['area_missing'], 'an area is not a sector: it has no area to miss');
    }

    // ── Play: the area ──────────────────────────────────────────────────────────────────────────────────────────────

    public function testPlayMakesTheAreaTheWindowWouldHaveMadeAndItStands(): void
    {
        $phase = $this->phase();
        $id = $this->stageArea($phase, ['label' => 'Κοίτη'])['id'];
        $audits = (int) dbFetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'create_mission_search_area'");

        $played = $this->play($phase);
        $this->assertTrue($played['ok']);
        $this->assertSame([1, 0], [$played['sent'], $played['failed']]);
        $this->assertSame('area', $played['results'][0]['kind']);

        $areas = $this->areas();
        $this->assertCount(1, $areas);
        $this->assertSame(['Κοίτη', $this->adminId], [$areas[0]['label'], (int) $areas[0]['created_by']]);
        $this->assertNull($areas[0]['ring_index'], 'a staged area is a hand-drawn one');
        $this->assertSame((int) $areas[0]['id'], $played['results'][0]['search_area_id']);
        $this->assertNull($played['results'][0]['dispatch_id']);
        $this->assertNull($played['results'][0]['sector_id']);
        $this->assertSame($audits + 1, (int) dbFetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'create_mission_search_area'"), 'the same audit entry a hand-drawn area has');

        $row = $this->item($id);
        $this->assertNotNull($row['sent_at']);
        $this->assertSame((int) $areas[0]['id'], (int) $row['sent_ref_id']);
        $card = $this->cardItem($id);
        $this->assertSame('standing', $card['state']);
        $this->assertSame((int) $areas[0]['id'], $card['search_area_id']);
        $this->assertNull($card['dispatch_id'], 'an area of the search is not a dispatch to an area');
        $this->assertNull($card['sector_id']);
    }

    public function testAnAreaOfAnotherMissionIsNeverReadAsStanding(): void
    {
        $foreign = (int) createMissionSearchArea(['id' => $this->otherMissionId, 'title' => 'O'], $this->adminId, ['label' => 'Ξένη', 'geo' => self::TRI])['id'];
        $phase = $this->phase();
        $id = $this->stageArea($phase)['id'];
        $this->play($phase);
        dbExecute("UPDATE mission_scenario_items SET sent_ref_id = ? WHERE id = ?", [$foreign, $id]);
        $this->assertSame('withdrawn', $this->cardItem($id)['state']);
        $this->assertCount(1, $this->areas($this->otherMissionId));
    }

    public function testAnUnreadableAreaFailsAndMakesNothing(): void
    {
        $unreadable = [
            'no words'    => ['label' => '  ', 'geo' => [[35.0, 24.8], [35.0, 24.9], [35.1, 24.9]]],
            'no label'    => ['geo' => [[35.0, 24.8], [35.0, 24.9], [35.1, 24.9]]],
            'geo is text' => ['label' => 'x', 'geo' => 'not a list'],
            'no geo'      => ['label' => 'x'],
        ];
        foreach ($unreadable as $why => $payload) {
            $this->assertNull(scenarioAreaInputFromPayload($payload), $why);
        }
        $this->assertNull(scenarioAreaInputFromPayload('x'));
        $this->assertSame('', scenarioAreaInputFromPayload(['label' => 'x', 'geo' => [[1, 2]]])['ring_index'], 'never a ring');

        $phase = $this->phase();
        $id = $this->stageArea($phase)['id'];
        dbExecute("UPDATE mission_scenario_items SET payload = ? WHERE id = ?", [json_encode($unreadable['no label']), $id]);
        $played = $this->play($phase);
        $this->assertSame([0, 1], [$played['sent'], $played['failed']]);
        $this->assertSame(t('scenario.item_unreadable'), $played['results'][0]['error']);
        $this->assertSame([], $this->areas(), 'an area nobody asked for is never made');
        $this->assertNull($this->item($id)['sent_at']);
    }

    // ── Sectors written against the plan's own area ─────────────────────────────────────────────────────────────────

    public function testASectorWrittenAgainstAnAreaOfThePlanIsMadeAfterItAndCutFromIt(): void
    {
        $phase = $this->phase();
        $area = $this->stageArea($phase, ['label' => 'Κοίτη'])['id'];
        $this->liveArea('Άλλη περιοχή'); // an area that exists: the sector must not end up in it
        $r = $this->stageSectors($phase, $area, [$this->row(['label' => 'Τομέας 1']), $this->row(['label' => 'Τομέας 2'])]);
        $this->assertTrue($r['ok']);
        $this->assertSame(2, $r['count']);
        $this->assertCount(1, $this->areas(), 'only the hand-made area exists before play');

        $items = dbFetchAll("SELECT * FROM mission_scenario_items WHERE phase_id = ? AND kind = 'sector' ORDER BY seq", [$phase]);
        $payload = json_decode($items[0]['payload'], true);
        $this->assertSame($area, $payload['area_item_id']);
        $this->assertArrayNotHasKey('area_id', $payload, 'there is no area id yet: the plan\'s own item stands for it');
        $this->assertSame('Κοίτη', $payload['area_label']);

        $before = $this->cardItem((int) $items[0]['id']);
        $this->assertTrue($before['area_staged']);
        $this->assertFalse($before['area_missing']);
        $this->assertSame('Κοίτη', $before['area_label']);
        $this->assertSame($area, $before['area_item_id']);
        $this->assertNull($before['area_id']);
        $this->assertSame(1, $before['area_phase_n']);

        $played = $this->play($phase);
        $this->assertSame([3, 0], [$played['sent'], $played['failed']], 'the area and its two sectors');
        $this->assertSame(['area', 'sector', 'sector'], array_column($played['results'], 'kind'), 'the area goes first');
        $made = dbFetchOne("SELECT id FROM mission_search_areas WHERE mission_id = ? AND label = 'Κοίτη'", [$this->missionId]);
        $this->assertNotNull($made);
        $sectors = $this->sectors();
        $this->assertCount(2, $sectors);
        foreach ($sectors as $sector) {
            $this->assertSame((int) $made['id'], (int) $sector['area_id'], 'cut from the area play made');
            $this->assertSame($this->teamId, (int) $sector['team_id']);
            $this->assertSame('assigned', $sector['status']);
        }
        $after = $this->cardItem((int) $items[0]['id']);
        $this->assertFalse($after['area_staged']);
        $this->assertSame((int) $made['id'], $after['area_id']);
        $this->assertSame('Κοίτη', $after['area_label']);
    }

    public function testAnAreaOfAnEarlierPhaseServesTheSectorsOfALaterOne(): void
    {
        $first = $this->phase();
        $second = $this->phase();
        $area = $this->stageArea($first, ['label' => 'Κοίτη'])['id'];
        $this->assertTrue($this->stageSectors($second, $area, [$this->row()])['ok'], 'a later phase may name it');

        $this->assertTrue($this->play($first)['ok']);
        $made = (int) $this->areas()[0]['id'];
        $played = $this->play($second);
        $this->assertSame([1, 0], [$played['sent'], $played['failed']]);
        $this->assertSame($made, (int) $this->sectors()[0]['area_id']);
    }

    public function testASectorPlayedBeforeItsAreaFailsSaysWhichPhaseAndGuessesNothing(): void
    {
        $first = $this->phase();
        $second = $this->phase();
        $other = $this->liveArea('Παλιά περιοχή');
        $area = $this->stageArea($first, ['label' => 'Κοίτη'])['id'];
        $this->stageSectors($second, $area, [$this->row()]);
        $sectorItem = (int) dbFetchValue("SELECT id FROM mission_scenario_items WHERE phase_id = ? AND kind = 'sector'", [$second]);

        // The second phase goes first by mistake.
        $played = $this->play($second);
        $this->assertSame([0, 1], [$played['sent'], $played['failed']]);
        $this->assertSame(t('scenario.item_area_not_made', ['area' => 'Κοίτη', 'phase' => t('scenario.phase_n', ['n' => 1])]), $played['results'][0]['error']);
        $this->assertSame([], $this->sectors(), 'nothing was made - not in the area that exists, not anywhere');
        $this->assertSame([$other], array_map(fn($a) => (int) $a['id'], $this->areas()));
        $this->assertNull($this->item($sectorItem)['sent_at']);

        // The area is made by its own phase; the sector, tried again, is cut from it.
        $this->assertTrue($this->play($first)['ok']);
        $retried = retryScenarioItem($this->mission, $sectorItem, $this->adminId, 'Area Admin');
        $this->assertTrue($retried['ok']);
        $this->assertTrue($retried['item']['ok']);
        $made = dbFetchOne("SELECT id FROM mission_search_areas WHERE mission_id = ? AND label = 'Κοίτη'", [$this->missionId]);
        $this->assertSame((int) $made['id'], (int) $this->sectors()[0]['area_id']);
    }

    public function testASectorBeforeItsAreaInTheSamePhaseFailsToo(): void
    {
        // Items play in order. Staging always puts the sector after its area, so this puts them the wrong way round by hand.
        $phase = $this->phase();
        $area = $this->stageArea($phase)['id'];
        $this->stageSectors($phase, $area, [$this->row()]);
        $sectorItem = (int) dbFetchValue("SELECT id FROM mission_scenario_items WHERE phase_id = ? AND kind = 'sector'", [$phase]);
        dbExecute("UPDATE mission_scenario_items SET seq = 9 WHERE id = ?", [$area]);

        $played = $this->play($phase);
        $this->assertSame(['sector', 'area'], array_column($played['results'], 'kind'), 'the sector is asked first');
        $this->assertFalse($played['results'][0]['ok']);
        $this->assertSame(t('scenario.item_area_not_made', ['area' => 'Περιοχή Α', 'phase' => t('scenario.phase_n', ['n' => 1])]), $played['results'][0]['error']);
        $this->assertTrue($played['results'][1]['ok']);
        $this->assertSame([], $this->sectors());
        $this->assertTrue(retryScenarioItem($this->mission, $sectorItem, $this->adminId, 'Area Admin')['item']['ok']);
        $this->assertCount(1, $this->sectors());
    }

    public function testASectorInAnEarlierPhaseThanItsAreaIsRefusedWhenItIsWrittenDown(): void
    {
        $first = $this->phase();
        $second = $this->phase();
        $area = $this->stageArea($second, ['label' => 'Κοίτη'])['id'];
        $r = $this->stageSectors($first, $area, [$this->row()]);
        $this->assertFalse($r['ok']);
        $this->assertSame(t('scenario.sector_before_area', ['area' => 'Κοίτη', 'phase' => t('scenario.phase_n', ['n' => 2])]), $r['error']);
        $this->assertSame(0, (int) dbFetchValue("SELECT COUNT(*) FROM mission_scenario_items WHERE phase_id = ?", [$first]), 'nothing written');
        $this->assertTrue($this->stageSectors($second, $area, [$this->row()])['ok'], 'the same phase is fine');
    }

    public function testNamingAnItemThatIsNotAnAreaOfThisScenarioIsRefused(): void
    {
        $phase = $this->phase();
        $foreignPhase = addScenarioPhase(['id' => $this->otherMissionId, 'title' => 'O'], $this->adminId, '')['id'];
        $foreignArea = (int) addScenarioAreaItem(['id' => $this->otherMissionId, 'title' => 'O'], $foreignPhase, $this->adminId, ['label' => 'Ξένη', 'geo' => self::TRI])['id'];
        $point = addScenarioDispatchItem($this->mission, $phase, $this->adminId, [
            'team_id' => (string) $this->teamId, 'type' => 'point', 'label' => 'Σημείο', 'geo' => json_encode(['lat' => 35.1, 'lng' => 25.1]), 'travel_mode' => '',
        ]);
        $this->assertTrue($point['ok']);
        foreach (['another mission\'s area' => $foreignArea, 'an order that is not an area' => (int) $point['id'], 'no such item' => 987654321] as $why => $itemId) {
            $r = $this->stageSectors($phase, $itemId, [$this->row()]);
            $this->assertFalse($r['ok'], $why);
            $this->assertSame(t('scenario.area_item_not_found'), $r['error'], $why);
        }
        $this->assertSame(1, (int) dbFetchValue("SELECT COUNT(*) FROM mission_scenario_items WHERE phase_id = ?", [$phase]), 'only the point');
    }

    public function testASectorOfAStagedAreaIsStillCheckedAsAnySectorIs(): void
    {
        $phase = $this->phase();
        $area = $this->stageArea($phase)['id'];
        $cases = [
            'no name'     => [$this->row(['label' => '']), t('sector.invalid_label')],
            'two points'  => [$this->row(['geo' => [[35.0, 24.8], [35.0, 24.9]]]), t('dispatch.polygon_needs_3_points')],
            'no team'     => [$this->row(['team_id' => '']), t('scenario.sector_needs_team')],
            'all teams'   => [$this->row(['team_id' => 'all']), t('scenario.sector_needs_team')],
            'a foreign team' => [$this->row(['team_id' => (string) (int) dbInsert("INSERT INTO mission_teams (mission_id, codename, team_number, created_by) VALUES (?, 'ΞΕΝΗ', 1, ?)", [$this->otherMissionId, $this->adminId])]), t('common.team_not_found')],
        ];
        foreach ($cases as $why => [$row, $error]) {
            $r = $this->stageSectors($phase, $area, [$row]);
            $this->assertFalse($r['ok'], $why);
            $this->assertSame($error, $r['error'], $why);
        }
        $this->assertSame(1, (int) dbFetchValue("SELECT COUNT(*) FROM mission_scenario_items WHERE phase_id = ?", [$phase]), 'all or none: only the area is there');
    }

    public function testAnAreaThatFailedToBeMadeTakesItsSectorsWithItAndNothingIsGuessed(): void
    {
        $phase = $this->phase();
        $other = $this->liveArea('Παλιά περιοχή');
        $area = $this->stageArea($phase, ['label' => 'Κοίτη'])['id'];
        $this->stageSectors($phase, $area, [$this->row()]);
        $sectorItem = (int) dbFetchValue("SELECT id FROM mission_scenario_items WHERE phase_id = ? AND kind = 'sector'", [$phase]);
        $good = $this->item($area)['payload'];
        dbExecute("UPDATE mission_scenario_items SET payload = ? WHERE id = ?", [json_encode(['label' => 'Κοίτη']), $area]);

        $played = $this->play($phase);
        $this->assertSame([0, 2], [$played['sent'], $played['failed']]);
        $this->assertSame(t('scenario.item_unreadable'), $played['results'][0]['error']);
        $this->assertSame(t('scenario.item_area_not_made', ['area' => 'Κοίτη', 'phase' => t('scenario.phase_n', ['n' => 1])]), $played['results'][1]['error']);
        $this->assertSame([], $this->sectors(), 'no sector, in no area');
        $this->assertSame([$other], array_map(fn($a) => (int) $a['id'], $this->areas()));

        // Mend the area and send it again, then the sector.
        dbExecute("UPDATE mission_scenario_items SET payload = ? WHERE id = ?", [$good, $area]);
        $this->assertTrue(retryScenarioItem($this->mission, $area, $this->adminId, 'Area Admin')['item']['ok']);
        $this->assertTrue(retryScenarioItem($this->mission, $sectorItem, $this->adminId, 'Area Admin')['item']['ok']);
        $made = dbFetchOne("SELECT id FROM mission_search_areas WHERE mission_id = ? AND label = 'Κοίτη'", [$this->missionId]);
        $this->assertSame((int) $made['id'], (int) $this->sectors()[0]['area_id']);
    }

    public function testAStagedAreaThatHasBeenMadeIsARealAreaForSectorsWrittenLater(): void
    {
        $first = $this->phase();
        $second = $this->phase();
        $area = $this->stageArea($first, ['label' => 'Κοίτη'])['id'];
        $this->play($first);
        $made = (int) $this->areas()[0]['id'];

        $this->assertTrue($this->stageSectors($second, $area, [$this->row()])['ok']);
        $payload = json_decode((string) dbFetchValue("SELECT payload FROM mission_scenario_items WHERE phase_id = ? AND kind = 'sector'", [$second]), true);
        $this->assertSame($made, $payload['area_id'], 'written against the live area, like any sector');
        $this->assertArrayNotHasKey('area_item_id', $payload);
        // ...so the plan's item can go without leaving the sector without an area.
        $this->assertNull(deleteScenarioItem($this->mission, $area, $this->adminId));
        $this->assertTrue($this->play($second)['ok']);
        $this->assertSame($made, (int) $this->sectors()[0]['area_id']);
    }

    public function testAnAreaDeletedAfterItWasMadeFailsTheSectorWithTheOldReason(): void
    {
        $first = $this->phase();
        $second = $this->phase();
        $area = $this->stageArea($first, ['label' => 'Κοίτη'])['id'];
        $this->stageSectors($second, $area, [$this->row()]);
        $this->play($first);
        dbExecute("DELETE FROM mission_search_areas WHERE id = ?", [(int) $this->areas()[0]['id']]);

        $sectorItem = (int) dbFetchValue("SELECT id FROM mission_scenario_items WHERE phase_id = ? AND kind = 'sector'", [$second]);
        $card = $this->cardItem($sectorItem);
        $this->assertTrue($card['area_missing'], 'the card says so beforehand');
        $played = $this->play($second);
        $this->assertSame([0, 1], [$played['sent'], $played['failed']]);
        $this->assertSame(t('scenario.item_area_gone', ['area' => 'Κοίτη']), $played['results'][0]['error']);
        $this->assertSame([], $this->sectors());
        $this->assertSame('withdrawn', $this->cardItem($area)['state'], 'and the area itself reads as taken away');
    }

    public function testAnAreaTakenOffThePlanBehindTheGuardsBackFailsTheSector(): void
    {
        $phase = $this->phase();
        $area = $this->stageArea($phase, ['label' => 'Κοίτη'])['id'];
        $this->stageSectors($phase, $area, [$this->row()]);
        $sectorItem = (int) dbFetchValue("SELECT id FROM mission_scenario_items WHERE phase_id = ? AND kind = 'sector'", [$phase]);
        dbExecute("DELETE FROM mission_scenario_items WHERE id = ?", [$area]);

        $card = $this->cardItem($sectorItem);
        $this->assertTrue($card['area_missing']);
        $this->assertFalse($card['area_staged']);
        $played = $this->play($phase);
        $this->assertSame(t('scenario.item_area_item_gone', ['area' => 'Κοίτη']), $played['results'][0]['error']);
        $this->assertSame([], $this->sectors());
        $this->assertSame([], $this->areas());
    }

    // ── Taking an area off the plan ─────────────────────────────────────────────────────────────────────────────────

    public function testAnAreaThatSectorsStillWaitForCannotBeTakenOffThePlan(): void
    {
        $phase = $this->phase();
        $area = $this->stageArea($phase, ['label' => 'Κοίτη'])['id'];
        $this->stageSectors($phase, $area, [$this->row(['label' => 'Α']), $this->row(['label' => 'Β'])]);
        $sectorItems = array_map('intval', array_column(dbFetchAll("SELECT id FROM mission_scenario_items WHERE phase_id = ? AND kind = 'sector' ORDER BY seq", [$phase]), 'id'));

        $this->assertSame(t('scenario.area_in_use', ['area' => 'Κοίτη', 'n' => 2]), deleteScenarioItem($this->mission, $area, $this->adminId));
        $this->assertNotNull(dbFetchOne("SELECT id FROM mission_scenario_items WHERE id = ?", [$area]), 'still on the plan');

        $this->assertNull(deleteScenarioItem($this->mission, $sectorItems[0], $this->adminId));
        $this->assertSame(t('scenario.area_in_use', ['area' => 'Κοίτη', 'n' => 1]), deleteScenarioItem($this->mission, $area, $this->adminId));
        $this->assertNull(deleteScenarioItem($this->mission, $sectorItems[1], $this->adminId));
        $this->assertNull(deleteScenarioItem($this->mission, $area, $this->adminId), 'free once nothing waits for it');
    }

    public function testAnAreaWithNoSectorsAndASectorOnAnExistingAreaAreFreeToGo(): void
    {
        $phase = $this->phase();
        $lone = $this->stageArea($phase)['id'];
        $this->assertNull(deleteScenarioItem($this->mission, $lone, $this->adminId));

        $live = $this->liveArea();
        $this->assertTrue(addScenarioSectorItems($this->mission, $phase, $this->adminId, $live, [$this->row()])['ok']);
        $sectorItem = (int) dbFetchValue("SELECT id FROM mission_scenario_items WHERE phase_id = ? AND kind = 'sector'", [$phase]);
        $this->assertNull(deleteScenarioItem($this->mission, $sectorItem, $this->adminId));
    }

    public function testAPhaseWhoseAreaSectorsOfAnotherPhaseNeedCannotBeDeleted(): void
    {
        $first = $this->phase();
        $second = $this->phase();
        $area = $this->stageArea($first, ['label' => 'Κοίτη'])['id'];
        $this->stageSectors($second, $area, [$this->row()]);

        $this->assertSame(t('scenario.phase_area_in_use', ['area' => 'Κοίτη']), deleteScenarioPhase($this->mission, $first, $this->adminId));
        $this->assertNotNull(scenarioPhaseRow($this->missionId, $first), 'the phase is still there');
        // The phase of the sectors goes first, then the one with the area.
        $this->assertNull(deleteScenarioPhase($this->mission, $second, $this->adminId));
        $this->assertNull(deleteScenarioPhase($this->mission, $first, $this->adminId));
    }

    public function testAPhaseHoldingAnAreaAndItsOwnSectorsGoesTogether(): void
    {
        $phase = $this->phase();
        $area = $this->stageArea($phase)['id'];
        $this->stageSectors($phase, $area, [$this->row()]);
        $this->assertNull(deleteScenarioPhase($this->mission, $phase, $this->adminId));
        $this->assertSame(0, (int) dbFetchValue("SELECT COUNT(*) FROM mission_scenario_items WHERE mission_id = ?", [$this->missionId]));
    }

    public function testASectorThatWasSentNoLongerHoldsItsAreaOnThePlan(): void
    {
        $first = $this->phase();
        $second = $this->phase();
        $area = $this->stageArea($first)['id'];
        $this->stageSectors($second, $area, [$this->row()]);
        $this->play($first);
        $this->play($second);
        // Both are live now: nothing waits for the area, so its phase may be taken off the plan.
        $this->assertNull(deleteScenarioPhase($this->mission, $first, $this->adminId));
        $this->assertCount(1, $this->areas(), 'the live area stays');
        $this->assertCount(1, $this->sectors());
    }

    // ── Progress and withdrawing ────────────────────────────────────────────────────────────────────────────────────

    public function testAStandingAreaIsNotCountedAndCannotBeWithdrawnByItself(): void
    {
        $phase = $this->phase();
        $this->stageArea($phase);
        $this->play($phase);
        $this->assertSame(['total' => 0, 'done' => 0, 'open' => 0, 'standing' => 0, 'withdrawn' => 0, 'failed' => 0, 'complete' => false, 'withdrawable' => 0, 'can_withdraw' => false], $this->progress($phase), 'an area is in none of the counts: `standing` is the zones that withdrawing deletes');
        $this->assertSame(t('scenario.withdraw_nothing'), withdrawScenarioPhase($this->mission, $phase, $this->adminId)['error']);
        $this->assertCount(1, $this->areas(), 'the area is still there');

        // A zone stands too, and it CAN be taken back: the difference is the kind.
        $mixed = scenarioPhaseProgress([
            ['kind' => 'area', 'sent' => true, 'state' => 'standing'],
            ['kind' => 'restricted', 'sent' => true, 'state' => 'standing'],
        ]);
        $this->assertSame([1, 1, true], [$mixed['standing'], $mixed['withdrawable'], $mixed['can_withdraw']]);
        $this->assertSame(1, scenarioPhaseProgress([['kind' => 'area', 'sent' => false, 'state' => null, 'error' => 'x']])['failed']);
    }

    public function testWithdrawingThePhaseTakesTheSectorsOffTheirTeamsAndLeavesTheArea(): void
    {
        $phase = $this->phase();
        $area = $this->stageArea($phase, ['label' => 'Κοίτη'])['id'];
        $this->stageSectors($phase, $area, [$this->row(['label' => 'Α']), $this->row(['label' => 'Β'])]);
        $this->play($phase);
        $made = (int) $this->areas()[0]['id'];
        $this->assertEquals(['standing' => 0, 'open' => 2, 'withdrawable' => 2], array_intersect_key($this->progress($phase), ['standing' => 0, 'open' => 0, 'withdrawable' => 0]));

        $w = withdrawScenarioPhase($this->mission, $phase, $this->adminId);
        $this->assertTrue($w['ok']);
        $this->assertSame([2, 0], [$w['withdrawn'], $w['failed']], 'the sectors; the area is not among them');
        $this->assertSame(['sector', 'sector'], array_column($w['results'], 'kind'));
        $this->assertSame([$made], array_map(fn($a) => (int) $a['id'], $this->areas()), 'the area stays on the map');
        $this->assertCount(2, $this->sectors(), 'the sectors stay, unassigned');
        foreach ($this->sectors() as $sector) {
            $this->assertNull($sector['team_id']);
            $this->assertSame($made, (int) $sector['area_id']);
        }
        $this->assertSame('standing', $this->cardItem($area)['state'], 'still in force');
    }

    // ── Where the code is ───────────────────────────────────────────────────────────────────────────────────────────

    public function testTheScenarioNeverWritesAnAreaItselfAndTheEndpointUsesTheSharedFunctions(): void
    {
        $src = $this->source('includes/functions-scenario.php');
        $this->assertStringContainsString('createMissionSearchArea($mission, $userId, $input)', $src);
        $this->assertStringContainsString('prepareMissionSearchArea([', $src);
        $this->assertStringNotContainsString('INSERT INTO mission_search_areas', $src);
        $this->assertStringNotContainsString('DELETE FROM mission_search_areas', $src, 'taking a phase back never deletes an area');

        $endpoint = $this->source('mission-sector.php');
        $this->assertStringContainsString("if (\$action === 'create_area') {", $endpoint);
        $this->assertStringContainsString('createMissionSearchArea($mission, (int) $userId', $endpoint);
        $this->assertStringNotContainsString('INSERT INTO mission_search_areas', $endpoint);
        $this->assertSame(1, substr_count($this->source('includes/functions-warroom.php'), 'INSERT INTO mission_search_areas'));
    }

    public function testTheScenarioEndpointStagesAreasForCommandOnlyAndNamesTheStagedAreaOfASector(): void
    {
        $src = $this->source('mission-scenario.php');
        $add = substr($src, (int) strpos($src, "if (\$action === 'add_area') {"), 1500);
        $this->assertStringContainsString('addScenarioAreaItem($mission, $phaseId, (int) $userId', $add);
        $this->assertStringContainsString("'label' => post('label')", $add);
        $this->assertStringContainsString("'geo'   => post('geo')", $add);
        $this->assertStringNotContainsString("post('team_id')", $add, 'an area has no team to name');
        $this->assertStringNotContainsString("post('ring_index')", $add, 'and no ring');
        $this->assertStringContainsString("deleteScenarioPhase(\$mission, \$phaseId, (int) \$userId)", $add, 'a refused area takes its new phase with it');

        $sectors = substr($src, (int) strpos($src, "if (\$action === 'add_sectors') {"), 2600);
        $this->assertStringContainsString("preg_match('/^item:([1-9][0-9]{0,9})\$/'", $sectors);
        $this->assertStringContainsString('$areaItemId > 0 ? 0 : (int) $areaRef, $sectors, $areaItemId)', $sectors);
        $this->assertStringContainsString("in_array(\$r['kind'] ?? '', ['sector', 'area'], true)", $src, 'play and retry hand the areas back with the sectors');
    }

    public function testTheKindColumnKnowsAreaInTheSchemaTheMigrationAndTheTestDatabase(): void
    {
        $this->assertGreaterThanOrEqual(209, DB_SCHEMA_VERSION);
        $this->assertStringContainsString("`kind` ENUM('dispatch','sector','route','order','restricted','area')", $this->source('sql/schema.sql'));
        $this->assertStringContainsString("'version'     => 209,", $this->source('includes/migrations.php'));
        $this->assertStringContainsString("MODIFY COLUMN kind ENUM('dispatch','sector','route','order','restricted','area')", $this->source('includes/migrations.php'));
        $type = (string) dbFetchValue("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mission_scenario_items' AND COLUMN_NAME = 'kind'");
        $this->assertStringContainsString("'area'", $type, 'run php tests/migrate-fixture.php after adding a migration');
    }

    // ── The page: the search-area window, and the division window on an area of the plan (v3.459.0) ────────────────────

    public function testTheSearchAreaWindowHasAScenarioButtonThatIsReadyExactlyWhenSaveIsAndNeverForASector(): void
    {
        $page = $this->source('war-room.php');
        $this->assertStringContainsString('id="areaScenarioBtn"', $page);
        $this->assertStringContainsString('id="areaScenarioMenu"', $page);
        $this->assertStringContainsString('id="areaScenarioStatus"', $page);
        $this->assertStringContainsString("t('scenario.area_add_to_scenario_title')", $page);
        // Disabled at the start, like «Αποθήκευση».
        $this->assertMatchesRegularExpression('/id="areaScenarioBtn"[^>]*title="[^"]*"\s+disabled>/', $page);

        $start = (int) strpos($page, "const scenarioBtn = document.getElementById('areaScenarioBtn')");
        $this->assertGreaterThan(0, $start);
        $js = substr($page, $start, 16000);
        // Ready exactly when «Αποθήκευση» is - but not while the window draws a SECTOR of an existing area (activeSeed).
        $this->assertStringContainsString('if (scenarioBtn) scenarioBtn.disabled = sendBtn.disabled || !!activeSeed;', $js);
        $this->assertStringContainsString("if (!window.wrScenario || scenarioBtn.disabled || activeSeed) return;", $js, 'and once more in the handler');
        // Cleared with the drawing and when the window closes.
        $this->assertStringContainsString("sendBtn.disabled = true;\n        if (scenarioBtn) scenarioBtn.disabled = true;\n        updateSizeReadout();", $js, 'resetDrawing');
        $this->assertStringContainsString("if (scenarioStatus) scenarioStatus.textContent = '';", $js);
        // The name is required, as for «Αποθήκευση»: checked in the menu handler, BEFORE anything is staged.
        $handlerStart = (int) strpos($js, "scenarioMenu.addEventListener('click'");
        $stageCall = (int) strpos($js, 'window.wrScenario.stageArea(', $handlerStart);
        $this->assertGreaterThan($handlerStart, $stageCall);
        $beforeStage = substr($js, $handlerStart, $stageCall - $handlerStart);
        $this->assertStringContainsString("const label = labelInput.value.trim();
            if (!label) {
                alert(t('sector.invalid_label'));
                return;
            }", $beforeStage);
        // The menu is closed BEFORE the button is disabled (Bootstrap's hide() does nothing for a disabled toggle).
        $this->assertLessThan(
            (int) strpos($js, "scenarioBtn.disabled = true;\n            window.wrScenario.stageArea("),
            (int) strpos($js, 'bootstrap.Dropdown.getOrCreateInstance(scenarioBtn).hide();'),
            'the menu closes before the button is disabled'
        );
        // It stages the drawing and the name, and nothing about a team or a ring.
        $this->assertStringContainsString('window.wrScenario.stageArea(choice.dataset.scPhase, {label: label, geo: JSON.stringify(drawPoints)})', $js);
        // After success the drawing and the name start afresh and the line says where it went.
        $after = substr($js, (int) strpos($js, 'window.wrScenario.stageArea('));
        $this->assertStringContainsString("resetDrawing();
                    labelInput.value = '';
                    if (scenarioStatus && phase) scenarioStatus.textContent = t('scenario.staged_ok'", $after);
        // A failed save re-enables both through one function.
        $this->assertStringContainsString('sendBtn.disabled = false; updateSendState(); };', $js);
    }

    public function testTheDivisionWindowCanOpenOnAnAreaOfThePlanAndCanOnlyWriteItsPiecesIntoThePlan(): void
    {
        $page = $this->source('war-room.php');
        // Cross-scope state, at the top level of the script like pendingDivideAreaId.
        $this->assertStringContainsString("\nlet pendingDivideStagedArea = null;\n", $page);
        $this->assertStringContainsString("\nfunction openDivideSectorsForStagedArea(stagedArea) {", $page);
        $open = substr($page, (int) strpos($page, 'function openDivideSectorsForStagedArea('), 900);
        $this->assertStringContainsString('stagedArea.geo.length < 3', $open, 'an area with no outline opens nothing');
        $this->assertStringContainsString('pendingDivideAreaId = null;', $open, 'one or the other, never both');
        $this->assertStringContainsString('function openDivideSectorsForArea(areaId) {', $page);
        $live = substr($page, (int) strpos($page, 'function openDivideSectorsForArea('), 300);
        $this->assertStringContainsString('pendingDivideStagedArea = null;', $live, 'opening a live area forgets a staged one');

        $start = (int) strpos($page, "const scenarioBtn = document.getElementById('divideScenarioBtn')");
        $end = (int) strpos($page, "document.getElementById('splitSectorModal')", $start);
        $this->assertGreaterThan($start, $end);
        $js = substr($page, $start, $end - $start); // the division window's own IIFE
        // The window's area for a staged one: no id, the item, and the flag every "needs a stored area" path checks.
        $this->assertStringContainsString("? {id: null, item_id: stagedArea.item_id, label: stagedArea.label, geo: stagedArea.geo, staged: true}", $js);
        $this->assertStringContainsString("const stagedArea = pendingDivideStagedArea;\n        pendingDivideStagedArea = null;", $js, 'consumed once');
        // «Αποθήκευση Τομέων» makes sectors now and there is no area to make them in: off, and once more in its handler.
        $this->assertStringContainsString('const staged = !!(currentArea && currentArea.staged);', $js);
        $this->assertStringContainsString('saveBtn.disabled = staged || values.length === 0 || hasSlot;', $js);
        $this->assertStringContainsString('if (!wedges.length || !currentArea || currentArea.staged) return;', $js);
        // The grid is cut by the server from a stored area: no tab for it, and a guard on its button.
        $this->assertStringContainsString("divideTabs.classList.toggle('d-none', isStaged)", $js);
        $this->assertStringContainsString('if (!currentArea || currentArea.staged) return;', $js);
        // «Στο σενάριο» names the plan's own area as item:<id>.
        $this->assertStringContainsString("const areaRef = currentArea.staged ? 'item:' + currentArea.item_id : currentArea.id;", $js);
        $this->assertStringContainsString('window.wrScenario.stageSectors(choice.dataset.scPhase, areaRef, sectors)', $js);
        // And the note that says why nothing is made now.
        $this->assertStringContainsString('id="divideStagedNote"', $page);
        $this->assertStringContainsString("t('scenario.divide_staged_note')", $page);
    }

    public function testTheCardScriptKnowsAreasAndEveryNewStringIsInBothLanguages(): void
    {
        $js = $this->source('assets/js/war-room-scenario.js');
        $this->assertStringContainsString("request('add_area',", $js);
        $this->assertStringContainsString('stageArea: stageArea', $js);
        $this->assertStringContainsString('data-sc="divide-area"', $js);
        $this->assertStringContainsString('openDivideSectorsForStagedArea({item_id: hit.item.id', $js);
        $lang = (string) file_get_contents(__DIR__ . '/../includes/lang/war-room.php');
        foreach ([
            'scenario.kind_search_area', 'scenario.area_add_to_scenario_title', 'scenario.area_item_not_found', 'scenario.sector_before_area',
            'scenario.item_area_item_gone', 'scenario.item_area_not_made', 'scenario.area_in_use', 'scenario.phase_area_in_use',
            'scenario.sector_in_staged_area', 'scenario.area_stays_hint', 'scenario.area_divide_btn', 'scenario.area_divide_title',
            'scenario.divide_staged_note',
        ] as $key) {
            $this->assertSame(2, substr_count($lang, "'$key' =>"), "$key is in Greek and in English");
        }
        // Each is used by the code (a string nobody asks for is a string to remove).
        $everything = $js . $this->source('war-room.php') . $this->source('includes/functions-scenario.php');
        foreach (['scenario.area_add_to_scenario_title', 'scenario.sector_in_staged_area', 'scenario.area_stays_hint', 'scenario.area_divide_btn', 'scenario.area_divide_title', 'scenario.divide_staged_note', 'scenario.kind_search_area'] as $key) {
            $this->assertStringContainsString($key, $everything, "$key is used");
        }
    }
}
