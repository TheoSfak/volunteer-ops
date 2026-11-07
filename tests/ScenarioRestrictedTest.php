<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Restricted areas in the scenario (v3.458.0): a danger zone, an edge such as a cliff, or a forbidden place - a polygon or a
 * line - written down in a phase and made when play is pressed. includes/functions-scenario.php (addScenarioRestrictedItem,
 * scenarioRestrictedInputFromPayload, the 'restricted' branches of scenarioSendItem / scenarioItemLiveStates /
 * scenarioPhaseProgress / withdrawScenarioPhase) and the prepare/create/deleteMissionRestrictedArea() in
 * includes/functions-warroom.php that mission-restricted-area.php now sends through.
 *
 * The things that must hold: the zone is the one the window's save makes (same row, audit and checks); a zone has no team,
 * no slot and no «όλες οι ομάδες» - nothing to widen; a payload that is not plainly a zone fails and makes nothing; a zone
 * is never "finished" (it stands), so it never holds a phase back from being complete, and «Ανάκληση φάσης» deletes it the
 * way the window's delete does (open breaches resolved).
 *
 * Runs inside a transaction that is always rolled back.
 */
final class ScenarioRestrictedTest extends TestCase
{
    private int $missionId;
    private int $otherMissionId;
    private int $adminId;
    private array $mission;

    protected function setUp(): void
    {
        db()->beginTransaction();
        $this->adminId = (int) dbInsert("INSERT INTO users (name, email, password) VALUES (?, ?, ?)", ['Zone Admin', 'sz-' . uniqid('', true) . '@example.invalid', 'x']);
        $missionTypeId = (int) dbFetchValue("SELECT id FROM mission_types ORDER BY id LIMIT 1");
        $mk = function (string $title) use ($missionTypeId): int {
            return (int) dbInsert(
                "INSERT INTO missions (title, location, start_datetime, end_datetime, mission_type_id) VALUES (?, ?, ?, ?, ?)",
                [$title, 'Ηράκλειο', date('Y-m-d H:i:s', time() - 7200), date('Y-m-d H:i:s', time() + 7200), $missionTypeId]
            );
        };
        $this->missionId = $mk('Zone Mission');
        $this->otherMissionId = $mk('Other Zone Mission');
        $this->mission = ['id' => $this->missionId, 'title' => 'Zone Mission'];
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

    private function stage(int $phaseId, array $over = []): array
    {
        return addScenarioRestrictedItem($this->mission, $phaseId, $this->adminId, $over + [
            'label' => 'Επικίνδυνο πρανές',
            'kind' => 'danger',
            'shape' => 'polygon',
            'geo' => self::TRI,
        ]);
    }

    private function play(int $phaseId): array
    {
        return playScenarioPhase($this->mission, $phaseId, $this->adminId, 'Zone Admin');
    }

    private function zones(?int $missionId = null): array
    {
        return dbFetchAll("SELECT * FROM mission_restricted_areas WHERE mission_id = ? ORDER BY id", [$missionId ?? $this->missionId]);
    }

    private function item(int $itemId): array
    {
        return dbFetchOne("SELECT * FROM mission_scenario_items WHERE id = ?", [$itemId]);
    }

    private function cardItem(int $phaseId, int $itemId): array
    {
        foreach (loadMissionScenario($this->missionId)['phases'] as $phase) {
            if ($phase['id'] === $phaseId) {
                foreach ($phase['items'] as $item) {
                    if ($item['id'] === $itemId) {
                        return $item;
                    }
                }
            }
        }
        $this->fail('item not on the card');
    }

    private function breach(int $areaId, bool $resolved): int
    {
        return (int) dbInsert(
            "INSERT INTO mission_restricted_area_breaches (mission_id, restricted_area_id, area_label, user_id, team_id, lat, lng, resolved_at, resolved_by, created_at)
             VALUES (?, ?, 'z', ?, NULL, 35.0, 24.8, ?, ?, NOW())",
            [$this->missionId, $areaId, $this->adminId, $resolved ? date('Y-m-d H:i:s') : null, $resolved ? $this->adminId : null]
        );
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
            'no name'            => [['label' => '   ', 'geo' => self::TRI], 'sector.invalid_label'],
            'unknown kind'       => [['label' => 'x', 'kind' => 'volcano', 'geo' => self::TRI], 'common.invalid_request'],
            'unknown shape'      => [['label' => 'x', 'shape' => 'circle', 'geo' => self::TRI], 'common.invalid_request'],
            'not json'           => [['label' => 'x', 'geo' => 'not json'], 'dispatch.polygon_needs_3_points'],
            'two-point polygon'  => [['label' => 'x', 'geo' => '[[35.0,24.8],[35.0,24.9]]'], 'dispatch.polygon_needs_3_points'],
            'one-point line'     => [['label' => 'x', 'shape' => 'line', 'geo' => '[[35.0,24.8]]'], 'restricted_area.line_needs_2_points'],
            'null island'        => [['label' => 'x', 'geo' => '[[35.0,24.8],[0,0],[35.1,24.9]]'], 'dispatch.invalid_point'],
            'latitude 91'        => [['label' => 'x', 'geo' => '[[91,24.8],[35.0,24.9],[35.1,24.9]]'], 'dispatch.invalid_point'],
            'a point that is text' => [['label' => 'x', 'geo' => '["12","34","56"]'], 'dispatch.invalid_point'],
        ];
        foreach ($bad as $why => [$input, $key]) {
            $r = prepareMissionRestrictedArea($input);
            $this->assertFalse($r['ok'], $why);
            $this->assertSame(t($key), $r['error'], $why);
        }

        // Two points are enough for a line, and nothing is guessed: an empty kind is danger, an empty shape a polygon.
        $line = prepareMissionRestrictedArea(['label' => 'Άκρη', 'kind' => 'cliff', 'shape' => 'line', 'geo' => '[[35.0,24.8],[35.0,24.9]]']);
        $this->assertTrue($line['ok']);
        $this->assertSame(['cliff', 'line'], [$line['kind'], $line['shape']]);
        $default = prepareMissionRestrictedArea(['label' => '  Ζώνη  ', 'kind' => '', 'shape' => '', 'geo' => '[["35.0","24.8"],["35.0","24.9"],["35.1","24.9"]]']);
        $this->assertTrue($default['ok']);
        $this->assertSame(['Ζώνη', 'danger', 'polygon'], [$default['label'], $default['kind'], $default['shape']]);
        $this->assertEquals([[35.0, 24.8], [35.0, 24.9], [35.1, 24.9]], $default['geo'], 'numeric text becomes numbers');
        $long = prepareMissionRestrictedArea(['label' => str_repeat('Ω', 300), 'geo' => self::TRI]);
        $this->assertSame(255, mb_strlen($long['label']));
    }

    public function testCreateWritesTheRowTheAuditAndTheLoaderSeesIt(): void
    {
        $audits = (int) dbFetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'create_mission_restricted_area'");
        $r = createMissionRestrictedArea($this->mission, $this->adminId, ['label' => 'Ρέμα', 'kind' => 'forbidden', 'shape' => 'polygon', 'geo' => self::TRI]);
        $this->assertTrue($r['ok']);
        $row = dbFetchOne("SELECT * FROM mission_restricted_areas WHERE id = ?", [$r['id']]);
        $this->assertSame([$this->missionId, 'Ρέμα', 'forbidden', 'polygon', $this->adminId], [(int) $row['mission_id'], $row['label'], $row['kind'], $row['shape'], (int) $row['created_by']]);
        $this->assertEquals([[35.0, 24.8], [35.0, 24.9], [35.1, 24.9]], json_decode($row['geo'], true));
        $this->assertSame($audits + 1, (int) dbFetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'create_mission_restricted_area'"));
        $this->assertSame(['Ρέμα'], array_column(loadMissionRestrictedAreasForUser($this->missionId), 'label'));

        $refused = createMissionRestrictedArea($this->mission, $this->adminId, ['label' => '', 'geo' => self::TRI]);
        $this->assertFalse($refused['ok']);
        $this->assertCount(1, $this->zones(), 'a refused zone writes nothing');
    }

    public function testDeleteResolvesOpenBreachesAndRefusesAZoneOfAnotherMission(): void
    {
        $id = createMissionRestrictedArea($this->mission, $this->adminId, ['label' => 'Α', 'geo' => self::TRI])['id'];
        $open = $this->breach($id, false);
        $done = $this->breach($id, true);
        $foreign = createMissionRestrictedArea(['id' => $this->otherMissionId, 'title' => 'O'], $this->adminId, ['label' => 'Ξένη', 'geo' => self::TRI])['id'];

        $this->assertSame(t('common.not_found'), deleteMissionRestrictedArea($this->mission, $this->adminId, $foreign));
        $this->assertCount(1, $this->zones($this->otherMissionId), 'the other mission keeps its zone');
        $this->assertSame(t('common.not_found'), deleteMissionRestrictedArea($this->mission, $this->adminId, 987654321));

        $this->assertNull(deleteMissionRestrictedArea($this->mission, $this->adminId, $id));
        $this->assertSame([], $this->zones());
        $this->assertNotNull(dbFetchValue("SELECT resolved_at FROM mission_restricted_area_breaches WHERE id = ?", [$open]), 'the open breach is resolved');
        $this->assertSame($this->adminId, (int) dbFetchValue("SELECT resolved_by FROM mission_restricted_area_breaches WHERE id = ?", [$open]));
        $this->assertNotNull(dbFetchValue("SELECT resolved_at FROM mission_restricted_area_breaches WHERE id = ?", [$done]));
        $this->assertSame(1, (int) dbFetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'delete_mission_restricted_area' AND table_name = 'mission_restricted_areas' AND notes LIKE ?", ['%"mission_id":' . $this->missionId . '%']));
    }

    public function testTheLoaderBreaksTiesById(): void
    {
        // Two zones drawn in the same second used to come back in either order (RestrictedAreaLineTest flaked once).
        $ids = [];
        foreach (['Ε', 'Δ', 'Γ', 'Β', 'Α'] as $label) {
            $ids[] = (int) dbInsert(
                "INSERT INTO mission_restricted_areas (mission_id, label, geo, created_at) VALUES (?, ?, ?, '2026-11-01 10:00:00')",
                [$this->missionId, $label, self::TRI]
            );
        }
        $this->assertSame($ids, array_column(loadMissionRestrictedAreasForUser($this->missionId), 'id'));
        $this->assertStringContainsString('ORDER BY created_at ASC, id ASC', $this->source('includes/functions-warroom.php'));
    }

    // ── Staging ─────────────────────────────────────────────────────────────────────────────────────────────────────

    public function testStagingWritesAZoneItemWithNoTeamAndNothingLive(): void
    {
        $phase = $this->phase();
        $r = $this->stage($phase, ['label' => 'Γκρεμός', 'kind' => 'cliff', 'shape' => 'line', 'geo' => '[[35.0,24.8],[35.0,24.9]]']);
        $this->assertTrue($r['ok']);
        $row = $this->item($r['id']);
        $this->assertSame('restricted', $row['kind']);
        $this->assertNull($row['slot_id']);
        $this->assertNull($row['sent_at']);
        $payload = json_decode($row['payload'], true);
        $this->assertEquals(['Γκρεμός', 'cliff', 'line', [[35.0, 24.8], [35.0, 24.9]]], [$payload['label'], $payload['kind'], $payload['shape'], $payload['geo']]);
        $this->assertArrayNotHasKey('audience', $payload, 'a zone is for nobody in particular');
        $this->assertArrayNotHasKey('team_id', $payload);
        $this->assertSame([], $this->zones(), 'nothing live until play');

        // The window's empty defaults are written down as words, so playing it never has to guess.
        $plain = $this->stage($phase, ['label' => 'Σκέτη', 'kind' => '', 'shape' => '']);
        $this->assertTrue($plain['ok']);
        $words = json_decode($this->item($plain['id'])['payload'], true);
        $this->assertSame(['danger', 'polygon'], [$words['kind'], $words['shape']]);
        $this->assertSame(2, (int) dbFetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'stage_scenario_item' AND notes LIKE '%\"type\":\"restricted\"%'"));
    }

    public function testStagingRefusesWhatCouldNeverBeMade(): void
    {
        $phase = $this->phase();
        $cases = [
            'no name'      => [['label' => ''], 'sector.invalid_label'],
            'bad kind'     => [['kind' => 'volcano'], 'common.invalid_request'],
            'bad shape'    => [['shape' => 'circle'], 'common.invalid_request'],
            'two points'   => [['geo' => '[[35.0,24.8],[35.0,24.9]]'], 'dispatch.polygon_needs_3_points'],
            'bad point'    => [['geo' => '[[35.0,24.8],[0,0],[35.1,24.9]]'], 'dispatch.invalid_point'],
        ];
        foreach ($cases as $why => [$over, $key]) {
            $r = $this->stage($phase, $over);
            $this->assertFalse($r['ok'], $why);
            $this->assertSame(t($key), $r['error'], $why);
        }
        $this->assertSame(0, (int) dbFetchValue("SELECT COUNT(*) FROM mission_scenario_items WHERE phase_id = ?", [$phase]), 'a refusal writes nothing');
    }

    public function testAPlayedPhaseAnotherMissionsPhaseAndAFullPhaseTakeNoMoreZones(): void
    {
        $phase = $this->phase();
        $this->assertTrue($this->stage($phase)['ok']);
        $this->assertTrue($this->play($phase)['ok']);
        $this->assertSame(t('scenario.phase_closed_for_orders'), $this->stage($phase)['error']);

        $foreign = addScenarioPhase(['id' => $this->otherMissionId, 'title' => 'O'], $this->adminId, '')['id'];
        $this->assertSame(t('scenario.phase_not_found'), $this->stage($foreign)['error']);

        $full = $this->phase();
        for ($i = 0; $i < SCENARIO_MAX_ITEMS_PER_PHASE; $i++) {
            $this->assertTrue($this->stage($full, ['label' => 'Ζ' . $i])['ok']);
        }
        $this->assertSame(t('scenario.too_many_orders', ['max' => SCENARIO_MAX_ITEMS_PER_PHASE]), $this->stage($full)['error']);
    }

    public function testTheCardGivesAZoneNoAudienceNoTeamAndItsOwnWords(): void
    {
        $phase = $this->phase();
        $id = $this->stage($phase, ['label' => 'Κατολίσθηση', 'kind' => 'forbidden'])['id'];
        $card = $this->cardItem($phase, $id);
        $this->assertSame('restricted', $card['kind']);
        $this->assertSame('restricted', $card['type']);
        $this->assertSame('none', $card['audience'], 'not "all", not "team"');
        $this->assertNull($card['team_id']);
        $this->assertNull($card['team_label']);
        $this->assertFalse($card['team_missing'], 'no team was ever named, so none is missing');
        $this->assertFalse($card['unmapped']);
        $this->assertSame('Κατολίσθηση', $card['label']);
        $this->assertSame('forbidden', $card['restricted_kind']);
        $this->assertSame('polygon', $card['shape']);
        $this->assertEquals([[35.0, 24.8], [35.0, 24.9], [35.1, 24.9]], $card['geo']);
        $this->assertNull($card['dispatch_id']);
        $this->assertNull($card['restricted_id']);
        $this->assertNull($card['state']);
    }

    // ── Play ────────────────────────────────────────────────────────────────────────────────────────────────────────

    public function testPlayMakesTheZoneTheWindowWouldHaveMadeAndItStands(): void
    {
        $phase = $this->phase();
        $id = $this->stage($phase, ['label' => 'Γκρεμός', 'kind' => 'cliff', 'shape' => 'line', 'geo' => '[[35.0,24.8],[35.0,24.9]]'])['id'];
        $audits = (int) dbFetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'create_mission_restricted_area'");

        $played = $this->play($phase);
        $this->assertTrue($played['ok']);
        $this->assertSame([1, 0], [$played['sent'], $played['failed']]);
        $this->assertSame('restricted', $played['results'][0]['kind']);

        $zones = $this->zones();
        $this->assertCount(1, $zones);
        $this->assertSame(['Γκρεμός', 'cliff', 'line', $this->adminId], [$zones[0]['label'], $zones[0]['kind'], $zones[0]['shape'], (int) $zones[0]['created_by']]);
        $this->assertEquals([[35.0, 24.8], [35.0, 24.9]], json_decode($zones[0]['geo'], true));
        $this->assertSame((int) $zones[0]['id'], $played['results'][0]['restricted_id']);
        $this->assertNull($played['results'][0]['dispatch_id']);
        $this->assertSame($audits + 1, (int) dbFetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'create_mission_restricted_area'"), 'the same audit entry a hand-drawn zone has');

        $row = $this->item($id);
        $this->assertNotNull($row['sent_at']);
        $this->assertSame((int) $zones[0]['id'], (int) $row['sent_ref_id']);
        $card = $this->cardItem($phase, $id);
        $this->assertSame('standing', $card['state']);
        $this->assertSame((int) $zones[0]['id'], $card['restricted_id']);
        $this->assertNull($card['dispatch_id'], 'a zone is not a point or an area: the card must not offer its id as one');
        $this->assertNull($card['sector_id']);
        $this->assertNull($card['route_id']);
        $this->assertNull($card['order_id']);
    }

    public function testAZoneOfAnotherMissionIsNeverReadAsStanding(): void
    {
        $foreign = createMissionRestrictedArea(['id' => $this->otherMissionId, 'title' => 'O'], $this->adminId, ['label' => 'Ξένη', 'geo' => self::TRI])['id'];
        $phase = $this->phase();
        $id = $this->stage($phase)['id'];
        $this->play($phase);
        // The record points at a zone that exists, but belongs to another mission: this mission's zone is not there.
        dbExecute("UPDATE mission_scenario_items SET sent_ref_id = ? WHERE id = ?", [$foreign, $id]);
        $this->assertSame('withdrawn', $this->cardItem($phase, $id)['state']);
        $this->assertCount(1, $this->zones($this->otherMissionId));
    }

    public function testAStandingZoneIsNotCountedInTheProgressAndNeverHoldsAPhaseBack(): void
    {
        $phase = $this->phase();
        $this->stage($phase);
        $this->play($phase);
        $p = null;
        foreach (loadMissionScenario($this->missionId)['phases'] as $ph) {
            if ($ph['id'] === $phase) {
                $p = $ph['progress'];
            }
        }
        $this->assertSame(['total' => 0, 'done' => 0, 'open' => 0, 'waiting' => 0, 'standing' => 1, 'withdrawn' => 0, 'failed' => 0, 'complete' => false, 'withdrawable' => 1, 'can_withdraw' => true], $p);

        // Beside orders that are finished the zone does not stop the phase being complete; it can still be taken back.
        $mixed = scenarioPhaseProgress([
            ['kind' => 'dispatch', 'sent' => true, 'state' => 'done'],
            ['kind' => 'restricted', 'sent' => true, 'state' => 'standing'],
        ]);
        $this->assertSame(['total' => 1, 'done' => 1, 'open' => 0, 'waiting' => 0, 'standing' => 1, 'withdrawn' => 0, 'failed' => 0, 'complete' => true, 'withdrawable' => 1, 'can_withdraw' => true], $mixed);
        // ...but an order that is still under way does, as before.
        $this->assertFalse(scenarioPhaseProgress([
            ['kind' => 'dispatch', 'sent' => true, 'state' => 'open'],
            ['kind' => 'restricted', 'sent' => true, 'state' => 'standing'],
        ])['complete']);
        // A zone that failed to be made counts like any order that failed: the phase is not finished while it waits.
        $this->assertSame(1, scenarioPhaseProgress([['kind' => 'restricted', 'sent' => false, 'state' => null, 'error' => 'x']])['failed']);
    }

    public function testAnUnreadableZoneFailsAndMakesNothing(): void
    {
        $unreadable = [
            'kind missing'  => ['label' => 'x', 'shape' => 'polygon', 'geo' => [[35.0, 24.8], [35.0, 24.9], [35.1, 24.9]]],
            'kind unknown'  => ['label' => 'x', 'kind' => 'volcano', 'shape' => 'polygon', 'geo' => [[35.0, 24.8], [35.0, 24.9], [35.1, 24.9]]],
            'shape missing' => ['label' => 'x', 'kind' => 'danger', 'geo' => [[35.0, 24.8], [35.0, 24.9], [35.1, 24.9]]],
            'no words'      => ['label' => '  ', 'kind' => 'danger', 'shape' => 'polygon', 'geo' => [[35.0, 24.8], [35.0, 24.9], [35.1, 24.9]]],
            'geo is text'   => ['label' => 'x', 'kind' => 'danger', 'shape' => 'polygon', 'geo' => 'not a list'],
        ];
        foreach ($unreadable as $why => $payload) {
            $this->assertNull(scenarioRestrictedInputFromPayload($payload), $why);
        }
        $this->assertNull(scenarioRestrictedInputFromPayload('x'));

        $phase = $this->phase();
        $id = $this->stage($phase)['id'];
        dbExecute("UPDATE mission_scenario_items SET payload = ? WHERE id = ?", [json_encode($unreadable['kind missing']), $id]);
        $played = $this->play($phase);
        $this->assertSame([0, 1], [$played['sent'], $played['failed']]);
        $this->assertSame(t('scenario.item_unreadable'), $played['results'][0]['error']);
        $this->assertSame([], $this->zones(), 'a zone nobody asked for is never made');
        $this->assertNull($this->item($id)['sent_at']);
    }

    public function testAZoneThatFailedCanBeTriedAgain(): void
    {
        $phase = $this->phase();
        $id = $this->stage($phase)['id'];
        $good = $this->item($id)['payload'];
        dbExecute("UPDATE mission_scenario_items SET payload = ? WHERE id = ?", [json_encode(['label' => 'x']), $id]);
        $this->assertSame(1, $this->play($phase)['failed']);

        dbExecute("UPDATE mission_scenario_items SET payload = ? WHERE id = ?", [$good, $id]);
        $retried = retryScenarioItem($this->mission, $id, $this->adminId, 'Zone Admin');
        $this->assertTrue($retried['ok']);
        $this->assertTrue($retried['item']['ok']);
        $this->assertSame('restricted', $retried['item']['kind']);
        $this->assertCount(1, $this->zones());
        $this->assertSame((int) $this->zones()[0]['id'], $retried['item']['restricted_id']);
    }

    // ── Withdrawing ─────────────────────────────────────────────────────────────────────────────────────────────────

    public function testWithdrawingThePhaseDeletesTheZoneAndResolvesItsOpenBreach(): void
    {
        $phase = $this->phase();
        $id = $this->stage($phase)['id'];
        $this->play($phase);
        $zoneId = (int) $this->zones()[0]['id'];
        $open = $this->breach($zoneId, false);

        $w = withdrawScenarioPhase($this->mission, $phase, $this->adminId);
        $this->assertTrue($w['ok']);
        $this->assertSame([1, 0], [$w['withdrawn'], $w['failed']]);
        $this->assertSame([], $this->zones(), 'the zone is gone, as when it is deleted by hand');
        $this->assertNotNull(dbFetchValue("SELECT resolved_at FROM mission_restricted_area_breaches WHERE id = ?", [$open]), 'and no alarm is left open on anyone\'s screen');
        $this->assertSame('withdrawn', $this->cardItem($phase, $id)['state']);
        $this->assertNotNull(dbFetchOne("SELECT played_at FROM mission_scenario_phases WHERE id = ?", [$phase])['played_at'], 'the phase stays played');

        $again = withdrawScenarioPhase($this->mission, $phase, $this->adminId);
        $this->assertFalse($again['ok']);
        $this->assertSame(t('scenario.withdraw_nothing'), $again['error']);
    }

    public function testAZoneSomeoneAlreadyDeletedHasNothingToWithdraw(): void
    {
        $phase = $this->phase();
        $id = $this->stage($phase)['id'];
        $this->play($phase);
        $this->assertNull(deleteMissionRestrictedArea($this->mission, $this->adminId, (int) $this->zones()[0]['id']));
        $this->assertSame('withdrawn', $this->cardItem($phase, $id)['state']);
        $this->assertSame(t('scenario.withdraw_nothing'), withdrawScenarioPhase($this->mission, $phase, $this->adminId)['error']);
    }

    public function testWithdrawingOnePhaseLeavesTheZoneOfAnotherPhaseAndTheMissionsOwn(): void
    {
        $own = createMissionRestrictedArea($this->mission, $this->adminId, ['label' => 'Με το χέρι', 'geo' => self::TRI])['id'];
        $first = $this->phase();
        $second = $this->phase();
        $this->stage($first, ['label' => 'Φάση 1']);
        $this->stage($second, ['label' => 'Φάση 2']);
        $this->play($first);
        $this->play($second);

        $this->assertTrue(withdrawScenarioPhase($this->mission, $first, $this->adminId)['ok']);
        $this->assertSame(['Με το χέρι', 'Φάση 2'], array_column($this->zones(), 'label'));
        $this->assertSame($own, (int) $this->zones()[0]['id']);
    }

    // ── Where the code is ───────────────────────────────────────────────────────────────────────────────────────────

    public function testTheScenarioNeverWritesAZoneItselfAndTheEndpointUsesTheSharedFunctions(): void
    {
        $src = $this->source('includes/functions-scenario.php');
        $this->assertStringContainsString('createMissionRestrictedArea($mission, $userId, $input)', $src);
        $this->assertStringContainsString('prepareMissionRestrictedArea([', $src);
        $this->assertStringContainsString('deleteMissionRestrictedArea($mission, $userId, $ref)', $src);
        $this->assertStringNotContainsString('INSERT INTO mission_restricted_areas', $src);
        $this->assertStringNotContainsString('DELETE FROM mission_restricted_areas', $src);
        $this->assertStringNotContainsString('mission_restricted_area_breaches', $src, 'the breaches are the live function\'s business');

        $endpoint = $this->source('mission-restricted-area.php');
        $this->assertStringContainsString('createMissionRestrictedArea($mission, (int) $userId', $endpoint);
        $this->assertStringContainsString('deleteMissionRestrictedArea($mission, (int) $userId, (int) post(\'id\'))', $endpoint);
        $this->assertStringNotContainsString('INSERT INTO mission_restricted_areas', $endpoint);
        $this->assertStringNotContainsString('DELETE FROM mission_restricted_areas', $endpoint);
    }

    public function testTheScenarioEndpointStagesZonesForCommandOnlyAndHandsBackTheLiveList(): void
    {
        $src = $this->source('mission-scenario.php');
        $add = substr($src, (int) strpos($src, "if (\$action === 'add_restricted') {"), 1700);
        $this->assertStringContainsString('addScenarioRestrictedItem($mission, $phaseId, (int) $userId', $add);
        $this->assertStringContainsString("'label' => post('label')", $add);
        $this->assertStringContainsString("'geo'   => post('geo')", $add);
        $this->assertStringNotContainsString("post('team_id')", $add, 'a zone has no team to name');
        $this->assertStringContainsString("deleteScenarioPhase(\$mission, \$phaseId, (int) \$userId)", $add, 'a refused zone takes its new phase with it');
        $this->assertStringContainsString("fn(\$r) => (\$r['kind'] ?? '') === 'restricted' && !empty(\$r['ok'])", $src);
        $this->assertStringContainsString("+ \$liveRestricted(\$result['results'])", $src, 'play');
        $this->assertStringContainsString("+ \$liveRestricted([\$result['item']])", $src, 'retry');
        $this->assertStringContainsString("'restricted_areas' => loadMissionRestrictedAreasForUser(\$missionId),\n    ]);", $src, 'withdraw hands the list back');
    }

    public function testTheWindowHasTheButtonAndTheCardTheWords(): void
    {
        $page = $this->source('war-room.php');
        $this->assertStringContainsString('id="restrictedAreaScenarioBtn"', $page);
        $this->assertStringContainsString('id="restrictedAreaScenarioMenu"', $page);
        $start = (int) strpos($page, "const scenarioBtn = document.getElementById('restrictedAreaScenarioBtn')");
        $this->assertGreaterThan(0, $start);
        $js = substr($page, $start, 20000);
        $this->assertStringContainsString("if (scenarioBtn) scenarioBtn.disabled = sendBtn.disabled;", $js, 'ready exactly when the save is');
        $this->assertStringContainsString('window.wrScenario.stageRestricted(choice.dataset.scPhase, {', $js);
        $this->assertStringContainsString('geo: JSON.stringify(drawPoints)', $js);
        // The zone is named before it is staged, goes in with the kind and the shape that were chosen, and the drawing and
        // the name are cleared once it is in (the window stays open for the next one).
        $this->assertStringContainsString("const label = labelInput.value.trim();\n            if (!label) {\n                alert(t('sector.invalid_label'));\n                return;\n            }", $js);
        $this->assertStringContainsString('label: label, kind: kindSelect.value, shape: shapeSelect.value, geo: JSON.stringify(drawPoints),', $js);
        $this->assertStringContainsString("resetDrawing();\n                    labelInput.value = '';\n                    if (scenarioStatus && phase)", $js);
        $this->assertStringContainsString("bootstrap.Dropdown.getOrCreateInstance(scenarioBtn).hide();\n            scenarioBtn.disabled = true;", $js, 'the menu is closed before the toggle is disabled');
        $this->assertStringNotContainsString('team_id', substr($js, (int) strpos($js, 'stageRestricted('), 400), 'no team is sent');

        $scenarioJs = $this->source('assets/js/war-room-scenario.js');
        $this->assertStringContainsString("request('add_restricted'", $scenarioJs);
        $this->assertStringContainsString('stageRestricted: stageRestricted', $scenarioJs);
        $this->assertStringContainsString("if (item.audience === 'none') return t('scenario.target_everyone');", $scenarioJs);

        foreach (['scenario.kind_restricted', 'scenario.target_everyone', 'scenario.state_standing', 'scenario.restricted_add_to_scenario_title', 'scenario.withdraw_confirm_zones'] as $key) {
            $lang = $this->source('includes/lang/war-room.php');
            $this->assertSame(2, substr_count($lang, "'" . $key . "' =>"), $key . ' in both languages');
        }
    }

    public function testTheKindColumnKnowsRestrictedInTheSchemaTheMigrationAndTheTestDatabase(): void
    {
        $this->assertGreaterThanOrEqual(208, DB_SCHEMA_VERSION);
        $this->assertStringContainsString("`kind` ENUM('dispatch','sector','route','order','restricted'", $this->source('sql/schema.sql'));
        $this->assertStringContainsString("'version'     => 208,", $this->source('includes/migrations.php'));
        $this->assertStringContainsString("MODIFY COLUMN kind ENUM('dispatch','sector','route','order','restricted')", $this->source('includes/migrations.php'));
        $type = (string) dbFetchValue("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mission_scenario_items' AND COLUMN_NAME = 'kind'");
        $this->assertStringContainsString("'restricted'", $type, 'run php tests/migrate-fixture.php after adding a migration');
    }
}
