<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The scenario (v3.452.0): orders written down before an exercise and sent, phase by phase, by a manual press of
 * play. includes/functions-scenario.php, mission-scenario.php, assets/js/war-room-scenario.js.
 *
 * The things that must hold: a staged order is invisible to every reader of the live orders; playing a phase sends
 * exactly what createMissionDispatch() sends by hand; the first press wins; one order that fails does not stop
 * the others; and an order whose team is gone is NEVER sent to everybody instead.
 *
 * Runs inside a transaction that is always rolled back.
 */
final class ScenarioTest extends TestCase
{
    private int $missionId;
    private int $otherMissionId;
    private int $adminId;
    private int $teamId;
    private int $secondTeamId;
    private int $shiftId;
    /** @var int[] On $teamId. */
    private array $members = [];
    private array $mission;

    protected function setUp(): void
    {
        db()->beginTransaction();

        $this->adminId = $this->makeUser('Scenario Admin');
        $missionTypeId = (int) dbFetchValue("SELECT id FROM mission_types ORDER BY id LIMIT 1");
        $mk = function (string $title) use ($missionTypeId): int {
            return (int) dbInsert(
                "INSERT INTO missions (title, location, start_datetime, end_datetime, mission_type_id) VALUES (?, ?, ?, ?, ?)",
                [$title, 'Ηράκλειο', date('Y-m-d H:i:s', time() - 7200), date('Y-m-d H:i:s', time() + 7200), $missionTypeId]
            );
        };
        $this->missionId = $mk('Scenario Mission');
        $this->otherMissionId = $mk('Other Scenario Mission');
        $this->mission = ['id' => $this->missionId, 'title' => 'Scenario Mission'];
        $this->shiftId = (int) dbInsert(
            "INSERT INTO shifts (mission_id, start_time, end_time) VALUES (?, ?, ?)",
            [$this->missionId, date('Y-m-d H:i:s', time() - 3600), date('Y-m-d H:i:s', time() + 3600)]
        );
        $this->teamId = $this->makeTeam('ΑΛΦΑ', 1);
        $this->secondTeamId = $this->makeTeam('ΒΗΤΑ', 2);
        foreach (['Άννα Α.', 'Βασίλης Β.'] as $name) {
            $id = $this->makeUser($name);
            dbInsert("INSERT INTO participation_requests (shift_id, volunteer_id, status) VALUES (?, ?, ?)", [$this->shiftId, $id, PARTICIPATION_APPROVED]);
            dbInsert("INSERT INTO mission_action_room_participants (mission_id, user_id) VALUES (?, ?)", [$this->missionId, $id]);
            dbInsert("INSERT INTO mission_team_members (team_id, mission_id, user_id) VALUES (?, ?, ?)", [$this->teamId, $this->missionId, $id]);
            $this->members[] = $id;
        }
    }

    protected function tearDown(): void
    {
        db()->rollBack();
    }

    private function makeUser(string $name): int
    {
        return (int) dbInsert("INSERT INTO users (name, email, password) VALUES (?, ?, ?)", [$name, 'sc-' . uniqid('', true) . '@example.invalid', 'x']);
    }

    private function makeTeam(string $codename, int $number, ?int $missionId = null): int
    {
        return (int) dbInsert(
            "INSERT INTO mission_teams (mission_id, codename, team_number, created_by) VALUES (?, ?, ?, ?)",
            [$missionId ?? $this->missionId, $codename, $number, $this->adminId]
        );
    }

    private function point(array $over = []): array
    {
        return $over + [
            'team_id' => (string) $this->teamId,
            'type' => 'point',
            'label' => 'Ρέμα',
            'geo' => json_encode(['lat' => 35.1, 'lng' => 25.1]),
            'travel_mode' => '',
        ];
    }

    private function area(array $over = []): array
    {
        return $over + [
            'team_id' => 'all',
            'type' => 'polygon',
            'label' => 'Χωράφι',
            'geo' => json_encode([[35.0, 24.8], [35.0, 24.9], [35.1, 24.9]]),
            'travel_mode' => '',
        ];
    }

    private function phase(string $title = ''): int
    {
        $r = addScenarioPhase($this->mission, $this->adminId, $title);
        $this->assertTrue($r['ok']);
        return $r['id'];
    }

    private function stage(int $phaseId, array $input): array
    {
        return addScenarioDispatchItem($this->mission, $phaseId, $this->adminId, $input);
    }

    private function play(int $phaseId): array
    {
        return playScenarioPhase($this->mission, $phaseId, $this->adminId, 'Scenario Admin');
    }

    private function liveCount(): int
    {
        return (int) dbFetchValue("SELECT COUNT(*) FROM mission_dispatch_points WHERE mission_id = ?", [$this->missionId]);
    }

    private function notificationCount(): int
    {
        return (int) dbFetchValue("SELECT COUNT(*) FROM notifications");
    }

    /** @return int[] ids that got a notification about $dispatchId. */
    private function notifiedAbout(int $dispatchId): array
    {
        $ids = [];
        foreach (dbFetchAll("SELECT user_id FROM notifications WHERE data LIKE ?", ['%"dispatchId":' . $dispatchId . '}%']) as $row) {
            $ids[] = (int) $row['user_id'];
        }
        sort($ids);
        return $ids;
    }

    private function source(string $rel): string
    {
        return str_replace("\r\n", "\n", (string) file_get_contents(__DIR__ . '/../' . $rel));
    }

    // ── Writing an order down ────────────────────────────────────────────────────────────────────────────────

    public function testAStagedOrderIsNotALiveOrderAndTellsNobody(): void
    {
        $phase = $this->phase();
        $notes = $this->notificationCount();

        $r = $this->stage($phase, $this->point());

        $this->assertTrue($r['ok']);
        $this->assertSame(0, $this->liveCount(), 'no live order exists');
        $this->assertSame($notes, $this->notificationCount(), 'nobody is told');
        // The team's own list, and command's, have nothing in them.
        $this->assertSame([], loadMissionDispatchesForUser($this->missionId, $this->members[0], false, true));
        $this->assertSame([], loadMissionDispatchesForUser($this->missionId, $this->adminId, true, false));
    }

    public function testTheStagedOrderIsStoredAsThePlanTheOrderWindowDescribed(): void
    {
        $phase = $this->phase();
        $this->stage($phase, $this->point(['travel_mode' => 'vehicle', 'label' => '  Ρέμα  ']));
        $this->stage($phase, $this->area());

        $scenario = loadMissionScenario($this->missionId);
        $this->assertCount(1, $scenario['phases']);
        $items = $scenario['phases'][0]['items'];
        $this->assertCount(2, $items);

        $this->assertSame('team', $items[0]['audience']);
        $this->assertSame($this->teamId, $items[0]['team_id']);
        $this->assertSame('ΑΛΦΑ 1', $items[0]['team_label']);
        $this->assertSame('point', $items[0]['type']);
        $this->assertSame(['lat' => 35.1, 'lng' => 25.1], $items[0]['geo']);
        $this->assertSame('Ρέμα', $items[0]['label'], 'trimmed like a sent label');
        $this->assertSame('vehicle', $items[0]['travel_mode']);
        $this->assertFalse($items[0]['sent']);

        $this->assertSame('all', $items[1]['audience']);
        $this->assertNull($items[1]['team_id']);
        $this->assertNull($items[1]['team_label']);
        $this->assertNull($items[1]['travel_mode'], 'an order to every team has no one team to travel');
    }

    public function testAnOrderThatCouldNeverBeSentIsRefusedWhenItIsWrittenDown(): void
    {
        $phase = $this->phase();
        $foreign = $this->makeTeam('ΔΕΛΤΑ', 9, $this->otherMissionId);

        $bad = [
            'a team of another mission' => $this->point(['team_id' => (string) $foreign]),
            'a point on 0,0' => $this->point(['geo' => json_encode(['lat' => 0, 'lng' => 0])]),
            'an area of two points' => $this->area(['geo' => json_encode([[35.0, 24.8], [35.0, 24.9]])]),
            'an unknown type' => $this->point(['type' => 'circle']),
        ];
        foreach ($bad as $why => $input) {
            $r = $this->stage($phase, $input);
            $this->assertFalse($r['ok'], $why);
            $this->assertNotSame('', (string) $r['error'], $why);
        }
        $this->assertSame([], loadMissionScenario($this->missionId)['phases'][0]['items']);
    }

    public function testNoTeamIsNotEveryTeam(): void
    {
        $phase = $this->phase();
        foreach (['', '0', 'ALL', 'everyone', '-3', '12abc', ' '] as $team) {
            $r = $this->stage($phase, $this->point(['team_id' => $team]));
            $this->assertFalse($r['ok'], 'team_id ' . var_export($team, true) . ' must not stage');
        }
        $this->assertSame([], loadMissionScenario($this->missionId)['phases'][0]['items']);
        $this->assertTrue($this->stage($phase, $this->point(['team_id' => 'all']))['ok'], 'only the word all means every team');
    }

    public function testPrepareMissionDispatchChecksAndWritesNothing(): void
    {
        $notes = $this->notificationCount();
        $audits = (int) dbFetchValue("SELECT COUNT(*) FROM audit_logs");

        $ok = prepareMissionDispatch($this->mission, $this->point());
        $this->assertTrue($ok['ok']);
        $this->assertSame($this->teamId, $ok['team_id']);
        $this->assertSame('ΑΛΦΑ 1', $ok['team_label']);
        $this->assertSame('point', $ok['type']);
        $this->assertFalse(prepareMissionDispatch($this->mission, $this->point(['team_id' => '987654321']))['ok']);

        $this->assertSame(0, $this->liveCount());
        $this->assertSame($notes, $this->notificationCount());
        $this->assertSame($audits, (int) dbFetchValue("SELECT COUNT(*) FROM audit_logs"));
    }

    public function testAPlayedPhaseTakesNoMoreOrders(): void
    {
        $phase = $this->phase();
        $this->stage($phase, $this->point());
        $this->assertTrue($this->play($phase)['ok']);

        $late = $this->stage($phase, $this->point());
        $this->assertFalse($late['ok']);
        $this->assertSame(t('scenario.phase_closed_for_orders'), $late['error']);
    }

    public function testAnotherMissionsPhaseIsNotFound(): void
    {
        $phase = $this->phase();
        $this->stage($phase, $this->point());
        $other = ['id' => $this->otherMissionId, 'title' => 'Other Scenario Mission'];

        $this->assertFalse(addScenarioDispatchItem($other, $phase, $this->adminId, $this->point())['ok']);
        $this->assertFalse(playScenarioPhase($other, $phase, $this->adminId, 'x')['ok']);
        $this->assertSame(t('scenario.phase_not_found'), renameScenarioPhase($other, $phase, 'x', $this->adminId));
        $this->assertSame(t('scenario.phase_not_found'), deleteScenarioPhase($other, $phase, $this->adminId));
        $this->assertSame([], loadMissionScenario($this->otherMissionId)['phases']);
        $this->assertSame(0, $this->liveCount());
        $this->assertCount(1, loadMissionScenario($this->missionId)['phases'][0]['items'], 'untouched');
    }

    public function testLimits(): void
    {
        for ($i = 0; $i < SCENARIO_MAX_PHASES; $i++) {
            $this->assertTrue(addScenarioPhase($this->mission, $this->adminId)['ok']);
        }
        $this->assertFalse(addScenarioPhase($this->mission, $this->adminId)['ok']);
    }

    // ── Phases ───────────────────────────────────────────────────────────────────────────────────────────────

    public function testPhasesAreNumberedByPositionAndAGapClosesWhenOneIsDeleted(): void
    {
        $a = $this->phase('Πρώτη');
        $b = $this->phase();
        $c = $this->phase();
        $this->stage($b, $this->point());

        $this->assertNull(deleteScenarioPhase($this->mission, $b, $this->adminId));

        $phases = loadMissionScenario($this->missionId)['phases'];
        $this->assertSame([$a, $c], array_column($phases, 'id'));
        $this->assertSame([1, 2], array_column($phases, 'n'));
        $this->assertSame('Πρώτη', $phases[0]['title']);
        $this->assertSame(0, (int) dbFetchValue("SELECT COUNT(*) FROM mission_scenario_items WHERE phase_id = ?", [$b]), 'its orders went with it');

        $this->assertNull(renameScenarioPhase($this->mission, $c, str_repeat('ω', 300), $this->adminId));
        $this->assertSame(SCENARIO_TITLE_MAX, mb_strlen(loadMissionScenario($this->missionId)['phases'][1]['title']));
        $this->assertNull(renameScenarioPhase($this->mission, $c, '   ', $this->adminId));
        $this->assertNull(loadMissionScenario($this->missionId)['phases'][1]['title'], 'an empty name is just the number');
    }

    public function testAnOrderCanBeTakenOffThePlan(): void
    {
        $phase = $this->phase();
        $id = $this->stage($phase, $this->point())['id'];
        $this->assertNull(deleteScenarioItem($this->mission, $id, $this->adminId));
        $this->assertSame([], loadMissionScenario($this->missionId)['phases'][0]['items']);
        $this->assertSame(t('scenario.item_not_found'), deleteScenarioItem($this->mission, $id, $this->adminId));
    }

    // ── Play ─────────────────────────────────────────────────────────────────────────────────────────────────

    public function testPlaySendsWhatTheOrderWindowWouldHaveSent(): void
    {
        $phase = $this->phase();
        $this->stage($phase, $this->point(['travel_mode' => 'vehicle']));
        $this->stage($phase, $this->area());

        $result = $this->play($phase);

        $this->assertTrue($result['ok']);
        $this->assertSame(2, $result['sent']);
        $this->assertSame(0, $result['failed']);
        $rows = dbFetchAll("SELECT * FROM mission_dispatch_points WHERE mission_id = ? ORDER BY id", [$this->missionId]);
        $this->assertCount(2, $rows);

        // The control: the same two orders sent by hand.
        $byHand = [
            createMissionDispatch($this->mission, $this->adminId, 'Scenario Admin', $this->point(['travel_mode' => 'vehicle'] + ['ring_index' => '', 'incident_id' => ''])),
            createMissionDispatch($this->mission, $this->adminId, 'Scenario Admin', $this->area(['team_id' => '', 'ring_index' => '', 'incident_id' => ''])),
        ];
        $control = dbFetchAll("SELECT * FROM mission_dispatch_points WHERE id IN (?, ?) ORDER BY id", [$byHand[0]['id'], $byHand[1]['id']]);
        foreach ([0, 1] as $i) {
            foreach (['mission_id', 'team_id', 'type', 'geo', 'label', 'ring_index', 'incident_id', 'map_point_id', 'travel_mode', 'created_by'] as $col) {
                $this->assertSame($control[$i][$col], $rows[$i][$col], "column $col of order " . ($i + 1));
            }
        }

        // The team in the first is told, exactly as for the hand-sent one.
        $this->assertSame($this->notifiedAbout((int) $byHand[0]['id']), $this->notifiedAbout((int) $rows[0]['id']));
        $this->assertSame($this->members, $this->notifiedAbout((int) $rows[0]['id']));
        $this->assertSame(1, (int) dbFetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'create_mission_dispatch' AND record_id = ?", [$rows[0]['id']]));

        // Both went to the live map, in staged order, and the plan remembers what each became.
        $items = loadMissionScenario($this->missionId)['phases'][0]['items'];
        $this->assertSame([(int) $rows[0]['id'], (int) $rows[1]['id']], array_column($items, 'dispatch_id'));
        $this->assertTrue($items[0]['sent'] && $items[1]['sent']);
        $this->assertNull($items[0]['error']);
        $phaseRow = loadMissionScenario($this->missionId)['phases'][0];
        $this->assertNotNull($phaseRow['played_at']);
        $this->assertSame('Scenario Admin', $phaseRow['played_by_name']);
    }

    public function testPlayingTwiceSendsOnce(): void
    {
        $phase = $this->phase();
        $this->stage($phase, $this->point());

        $first = $this->play($phase);
        $second = $this->play($phase);

        $this->assertTrue($first['ok']);
        $this->assertFalse($second['ok']);
        $this->assertSame(t('scenario.phase_already_played'), $second['error']);
        $this->assertSame(1, $this->liveCount());
    }

    public function testTheFirstPressWinsEvenWhenTheOtherReadTheStateBeforeIt(): void
    {
        // Two coordinators both saw an unplayed phase and press at the same moment: the claim is the one place
        // that decides, and only one of them gets it.
        $phase = $this->phase();
        $this->stage($phase, $this->point());
        $other = $this->makeUser('Second Coordinator');

        $this->assertTrue(claimScenarioPhase($this->missionId, $phase, $this->adminId));
        $this->assertFalse(claimScenarioPhase($this->missionId, $phase, $other), 'the second press finds it taken');
        $this->assertFalse(claimScenarioPhase($this->missionId, $phase, $this->adminId));
        $this->assertSame($this->adminId, (int) dbFetchValue("SELECT played_by FROM mission_scenario_phases WHERE id = ?", [$phase]), 'and does not take the credit');
        // A phase of another mission cannot be claimed through this one.
        $foreign = addScenarioPhase(['id' => $this->otherMissionId, 'title' => 'x'], $this->adminId)['id'];
        $this->assertFalse(claimScenarioPhase($this->missionId, $foreign, $this->adminId));
    }

    public function testAnOrderThatWasSentIsNeverSentAgainByTryAgain(): void
    {
        $phase = $this->phase();
        $item = $this->stage($phase, $this->point())['id'];
        $this->assertTrue($this->play($phase)['ok']);
        $this->assertSame(1, $this->liveCount());

        // Even with a failure text left over on a sent order, there is nothing to try again.
        dbExecute("UPDATE mission_scenario_items SET last_error = 'old failure' WHERE id = ?", [$item]);
        $r = retryScenarioItem($this->mission, $item, $this->adminId, 'x');

        $this->assertFalse($r['ok']);
        $this->assertSame(1, $this->liveCount());
    }

    public function testAnEmptyPhaseIsRefusedAndNotUsedUp(): void
    {
        $phase = $this->phase();
        $r = $this->play($phase);
        $this->assertFalse($r['ok']);
        $this->assertSame(t('scenario.phase_empty'), $r['error']);
        $this->assertNull(loadMissionScenario($this->missionId)['phases'][0]['played_at'], 'still a plan: orders can be added and it can be played');

        $this->stage($phase, $this->point());
        $this->assertTrue($this->play($phase)['ok']);
    }

    public function testOneOrderThatFailsDoesNotStopTheOthersAndIsNeverSentToEveryone(): void
    {
        $doomed = $this->makeTeam('ΓΑΜΜΑ', 3);
        $phase = $this->phase();
        $this->stage($phase, $this->point(['label' => 'Πρώτο']));
        $this->stage($phase, $this->point(['team_id' => (string) $doomed, 'label' => 'Για την ομάδα που φεύγει']));
        $this->stage($phase, $this->point(['team_id' => (string) $this->secondTeamId, 'label' => 'Τρίτο']));
        // The team is deleted between writing the plan and the exercise.
        dbExecute("DELETE FROM mission_teams WHERE id = ?", [$doomed]);

        $scenario = loadMissionScenario($this->missionId);
        $this->assertTrue($scenario['phases'][0]['items'][1]['team_missing'], 'the plan shows it before play');

        $result = $this->play($phase);

        $this->assertTrue($result['ok']);
        $this->assertSame(2, $result['sent']);
        $this->assertSame(1, $result['failed']);
        $this->assertSame([true, false, true], array_column($result['results'], 'ok'));
        $this->assertSame(t('common.team_not_found'), $result['results'][1]['error']);
        // Two live orders, each to its own team - and NO order without a team (which would alarm everybody).
        $this->assertSame(2, $this->liveCount());
        $this->assertSame(0, (int) dbFetchValue("SELECT COUNT(*) FROM mission_dispatch_points WHERE mission_id = ? AND team_id IS NULL", [$this->missionId]));

        $items = loadMissionScenario($this->missionId)['phases'][0]['items'];
        $this->assertTrue($items[0]['sent']);
        $this->assertFalse($items[1]['sent']);
        $this->assertSame(t('common.team_not_found'), $items[1]['error']);
        $this->assertTrue($items[2]['sent']);
    }

    public function testAnItemFailsRatherThanFallBackToEveryTeam(): void
    {
        // The only place a payload becomes an order: "every team" only when it SAYS all.
        $this->assertSame('', scenarioDispatchInputFromPayload(['audience' => 'all', 'type' => 'point', 'geo' => ['lat' => 35.1, 'lng' => 25.1]])['team_id']);
        $this->assertSame('7', scenarioDispatchInputFromPayload(['audience' => 'team', 'team_id' => 7, 'type' => 'point', 'geo' => ['lat' => 35.1, 'lng' => 25.1]])['team_id']);
        $geo = ['lat' => 35.1, 'lng' => 25.1];
        foreach ([
            'team without an id' => ['audience' => 'team', 'type' => 'point', 'geo' => $geo],
            'team id 0' => ['audience' => 'team', 'team_id' => 0, 'type' => 'point', 'geo' => $geo],
            'team id null' => ['audience' => 'team', 'team_id' => null, 'type' => 'point', 'geo' => $geo],
            'no audience at all' => ['team_id' => 7, 'type' => 'point', 'geo' => $geo],
            'an audience that is neither' => ['audience' => 'everyone', 'team_id' => 7, 'type' => 'point', 'geo' => $geo],
            'no shape' => ['audience' => 'all', 'type' => 'point'],
        ] as $why => $payload) {
            $this->assertNull(scenarioDispatchInputFromPayload($payload), $why);
        }
        $this->assertNull(scenarioDispatchInputFromPayload('not an array'));
    }

    public function testAnUnreadableItemFailsAndTheRestStillGo(): void
    {
        $phase = $this->phase();
        $this->stage($phase, $this->point(['label' => 'Bad']));
        $this->stage($phase, $this->point(['label' => 'Good']));
        dbExecute("UPDATE mission_scenario_items SET payload = '{broken' WHERE phase_id = ? ORDER BY seq LIMIT 1", [$phase]);

        $result = $this->play($phase);

        $this->assertSame(1, $result['sent']);
        $this->assertSame(1, $result['failed']);
        $this->assertSame(t('scenario.item_unreadable'), $result['results'][0]['error']);
        $this->assertSame(1, $this->liveCount());
        $this->assertSame(0, (int) dbFetchValue("SELECT COUNT(*) FROM mission_dispatch_points WHERE mission_id = ? AND team_id IS NULL", [$this->missionId]));
    }

    public function testAFailedOrderCanBeTriedAgainOnce(): void
    {
        $doomed = $this->makeTeam('ΓΑΜΜΑ', 3);
        $phase = $this->phase();
        $item = $this->stage($phase, $this->point(['team_id' => (string) $doomed]))['id'];
        dbExecute("DELETE FROM mission_teams WHERE id = ?", [$doomed]);
        $this->assertSame(1, $this->play($phase)['failed']);

        // Still broken: it fails again, and says so.
        $again = retryScenarioItem($this->mission, $item, $this->adminId, 'Scenario Admin');
        $this->assertTrue($again['ok']);
        $this->assertFalse($again['item']['ok']);
        $this->assertSame(0, $this->liveCount());

        // Command points it at a team that exists (here, by hand) and tries again: it goes out.
        dbExecute("UPDATE mission_scenario_items SET payload = ? WHERE id = ?", [
            json_encode(['audience' => 'team', 'team_id' => $this->teamId, 'type' => 'point', 'geo' => ['lat' => 35.1, 'lng' => 25.1], 'label' => 'x', 'travel_mode' => null]), $item,
        ]);
        $fixed = retryScenarioItem($this->mission, $item, $this->adminId, 'Scenario Admin');
        $this->assertTrue($fixed['item']['ok']);
        $this->assertSame(1, $this->liveCount());

        // Done: there is nothing left to retry, and a second press does not send it again.
        $third = retryScenarioItem($this->mission, $item, $this->adminId, 'Scenario Admin');
        $this->assertFalse($third['ok']);
        $this->assertSame(t('scenario.nothing_to_retry'), $third['error']);
        $this->assertSame(1, $this->liveCount());
    }

    public function testAnOrderOfAPhaseThatWasNotPlayedCannotBeRetried(): void
    {
        $phase = $this->phase();
        $item = $this->stage($phase, $this->point())['id'];
        $r = retryScenarioItem($this->mission, $item, $this->adminId, 'x');
        $this->assertFalse($r['ok']);
        $this->assertSame(t('scenario.phase_not_played_yet'), $r['error']);
        $this->assertSame(0, $this->liveCount());
    }

    public function testSeveralPhasesAreSentOneAtATimeInTheOrderPressed(): void
    {
        $one = $this->phase();
        $two = $this->phase();
        $this->stage($one, $this->point(['label' => 'P1']));
        $this->stage($two, $this->point(['label' => 'P2']));

        $this->assertTrue($this->play($two)['ok'], 'nothing forces phase 1 first: command decides');
        $this->assertSame(['P2'], array_column(dbFetchAll("SELECT label FROM mission_dispatch_points WHERE mission_id = ?", [$this->missionId]), 'label'));
        $this->assertTrue($this->play($one)['ok']);
        $this->assertSame(2, $this->liveCount());
    }

    // ── Slots: names for teams that do not exist yet ──────────────────────────────────────────────────────────────

    private function slot(string $label = 'Ομάδα Α'): int
    {
        $r = addScenarioSlot($this->mission, $this->adminId, $label);
        $this->assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        return $r['id'];
    }

    private function slotOrder(int $slot, array $over = []): array
    {
        return $over + $this->point(['team_id' => 'slot:' . $slot]);
    }

    public function testAnOrderCanBeWrittenDownForATeamThatDoesNotExistYet(): void
    {
        $slot = $this->slot('Ομάδα Α');
        $phase = $this->phase();
        $notes = $this->notificationCount();

        $r = $this->stage($phase, $this->slotOrder($slot, ['travel_mode' => 'vehicle']));

        $this->assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        $this->assertSame(0, $this->liveCount());
        $this->assertSame($notes, $this->notificationCount());
        $item = loadMissionScenario($this->missionId)['phases'][0]['items'][0];
        $this->assertSame('slot', $item['audience']);
        $this->assertSame($slot, $item['slot_id']);
        $this->assertSame('Ομάδα Α', $item['slot_label']);
        $this->assertTrue($item['unmapped']);
        $this->assertNull($item['team_id']);
        $this->assertNull($item['team_label']);
        $this->assertFalse($item['team_missing']);
        $this->assertSame('vehicle', $item['travel_mode'], 'the choice is kept for the team it becomes');
        $this->assertSame($slot, (int) dbFetchValue("SELECT slot_id FROM mission_scenario_items WHERE id = ?", [$r['id']]));
    }

    public function testThePlanListsTheSlotsAndTheTeamsEvenBeforeThereIsAPhase(): void
    {
        $slot = $this->slot('Ομάδα Β');
        mapScenarioSlot($this->mission, $slot, $this->secondTeamId, $this->adminId);

        $plan = loadMissionScenario($this->missionId);

        $this->assertSame([], $plan['phases']);
        $this->assertSame(
            [['id' => $slot, 'label' => 'Ομάδα Β', 'team_id' => $this->secondTeamId, 'team_label' => 'ΒΗΤΑ 2', 'mapped' => true]],
            $plan['slots']
        );
        $this->assertEqualsCanonicalizing(['ΑΛΦΑ 1', 'ΒΗΤΑ 2'], array_column($plan['teams'], 'label'));
    }

    public function testAPhaseThatUsesASlotWithNoTeamIsRefusedBeforeItIsClaimedAndSendsNothing(): void
    {
        $slot = $this->slot();
        $phase = $this->phase();
        $this->stage($phase, $this->slotOrder($slot));
        $this->stage($phase, $this->point());
        $notes = $this->notificationCount();

        $r = $this->play($phase);

        $this->assertFalse($r['ok']);
        $this->assertSame([['id' => $slot, 'label' => 'Ομάδα Α']], $r['unmapped'], 'the answer says which slots lack a team');
        $this->assertSame(t('scenario.slots_unmapped', ['slots' => 'Ομάδα Α']), $r['error']);
        $this->assertNull(loadMissionScenario($this->missionId)['phases'][0]['played_at'], 'still only a plan');
        $this->assertSame(0, $this->liveCount(), 'not even the order that has a team went out');
        $this->assertSame($notes, $this->notificationCount());
        $this->assertSame(0, (int) dbFetchValue("SELECT COUNT(*) FROM mission_dispatch_points WHERE mission_id = ? AND team_id IS NULL", [$this->missionId]));
    }

    public function testPlayWithTheDialogsAnswerSendsToThatTeamOnlyAndTheChoiceStays(): void
    {
        $slot = $this->slot();
        $one = $this->phase();
        $two = $this->phase();
        $this->stage($one, $this->slotOrder($slot, ['label' => 'Πρώτο']));
        $this->stage($two, $this->slotOrder($slot, ['label' => 'Δεύτερο']));

        $r = $this->play2($one, [$slot => $this->teamId]);

        $this->assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        $row = dbFetchOne("SELECT * FROM mission_dispatch_points WHERE id = ?", [$r['results'][0]['dispatch_id']]);
        $this->assertSame($this->teamId, (int) $row['team_id']);
        $this->assertSame($this->members, $this->notifiedAbout((int) $row['id']), 'the team the slot became is told, and nobody else');
        $this->assertSame($this->teamId, (int) dbFetchValue("SELECT team_id FROM mission_scenario_slots WHERE id = ?", [$slot]));

        // The next phase needs no question: the slot already stands for a team.
        $next = $this->play($two);
        $this->assertTrue($next['ok']);
        $this->assertSame($this->teamId, (int) dbFetchValue("SELECT team_id FROM mission_dispatch_points WHERE id = ?", [$next['results'][0]['dispatch_id']]));
        $item = loadMissionScenario($this->missionId)['phases'][1]['items'][0];
        $this->assertSame('ΑΛΦΑ 1', $item['team_label']);
        $this->assertFalse($item['unmapped']);
    }

    private function play2(int $phaseId, array $mapping): array
    {
        return playScenarioPhase($this->mission, $phaseId, $this->adminId, 'Scenario Admin', $mapping);
    }

    public function testTheDialogOnlyFillsASlotThatHasNoTeam(): void
    {
        $slot = $this->slot();
        mapScenarioSlot($this->mission, $slot, $this->teamId, $this->adminId);
        $phase = $this->phase();
        $this->stage($phase, $this->slotOrder($slot));

        // A second coordinator's late answer must not move a slot a first one has already settled.
        $r = $this->play2($phase, [$slot => $this->secondTeamId]);

        $this->assertTrue($r['ok']);
        $this->assertSame($this->teamId, (int) dbFetchValue("SELECT team_id FROM mission_dispatch_points WHERE id = ?", [$r['results'][0]['dispatch_id']]));
        $this->assertSame($this->teamId, (int) dbFetchValue("SELECT team_id FROM mission_scenario_slots WHERE id = ?", [$slot]));
    }

    public function testAnAnswerNamingAnotherMissionsTeamOrSlotIsRefusedAndAppliesNothing(): void
    {
        $slot = $this->slot();
        $other = ['id' => $this->otherMissionId, 'title' => 'Other'];
        $foreignTeam = $this->makeTeam('ΔΕΛΤΑ', 9, $this->otherMissionId);
        $foreignSlot = addScenarioSlot($other, $this->adminId, 'Ξένη')['id'];
        $phase = $this->phase();
        $this->stage($phase, $this->slotOrder($slot));

        foreach ([
            'a team of another mission' => [$slot => $foreignTeam],
            'a slot of another mission' => [$foreignSlot => $this->teamId],
            'no team at all' => [$slot => 0],
            'a team that does not exist' => [$slot => 987654321],
        ] as $why => $mapping) {
            $r = $this->play2($phase, $mapping);
            $this->assertFalse($r['ok'], $why);
        }
        $this->assertNull(dbFetchValue("SELECT team_id FROM mission_scenario_slots WHERE id = ?", [$slot]));
        $this->assertNull(dbFetchValue("SELECT team_id FROM mission_scenario_slots WHERE id = ?", [$foreignSlot]));
        $this->assertNull(loadMissionScenario($this->missionId)['phases'][0]['played_at']);
        $this->assertSame(0, $this->liveCount());
    }

    public function testASlotWhoseTeamIsDeletedStandsForNoTeamAgain(): void
    {
        $doomed = $this->makeTeam('ΓΑΜΜΑ', 3);
        $slot = $this->slot();
        mapScenarioSlot($this->mission, $slot, $doomed, $this->adminId);
        $phase = $this->phase();
        $this->stage($phase, $this->slotOrder($slot));

        dbExecute("DELETE FROM mission_teams WHERE id = ?", [$doomed]);

        $this->assertNull(dbFetchValue("SELECT team_id FROM mission_scenario_slots WHERE id = ?", [$slot]));
        $this->assertTrue(loadMissionScenario($this->missionId)['phases'][0]['items'][0]['unmapped']);
        $r = $this->play($phase);
        $this->assertFalse($r['ok']);
        $this->assertSame(0, $this->liveCount());
        $this->assertTrue($this->play2($phase, [$slot => $this->teamId])['ok'], 'asked again, and answered');
    }

    public function testASlotWithNoTeamIsNeverReadAsEveryTeam(): void
    {
        $geo = ['lat' => 35.1, 'lng' => 25.1];
        $payload = ['audience' => 'slot', 'slot_id' => 7, 'type' => 'point', 'geo' => $geo, 'travel_mode' => 'vehicle'];

        foreach ([[], [7 => null], [7 => 0], [8 => 5], ['7' => '']] as $map) {
            $this->assertNull(scenarioDispatchInputFromPayload($payload, $map), 'map ' . json_encode($map));
        }
        $this->assertNull(scenarioDispatchInputFromPayload($payload), 'no map at all');
        $input = scenarioDispatchInputFromPayload($payload, [7 => 41]);
        $this->assertSame('41', $input['team_id']);
        $this->assertSame('vehicle', $input['travel_mode']);
        // A slot payload that names no slot, or an order that says slot but carries a team id, is not an order to anyone.
        $this->assertNull(scenarioDispatchInputFromPayload(['audience' => 'slot', 'team_id' => 5, 'type' => 'point', 'geo' => $geo], [5 => 5]));
    }

    public function testTryingAgainAnOrderWhoseSlotHasNoTeamFailsAndSendsToNoOne(): void
    {
        $slot = $this->slot();
        $phase = $this->phase();
        $item = $this->stage($phase, $this->slotOrder($slot))['id'];
        // The phase was played at a time the slot had a team, and the order failed for another reason since.
        dbExecute("UPDATE mission_scenario_phases SET played_at = NOW(), played_by = ? WHERE id = ?", [$this->adminId, $phase]);
        dbExecute("UPDATE mission_scenario_items SET last_error = 'earlier failure' WHERE id = ?", [$item]);

        $r = retryScenarioItem($this->mission, $item, $this->adminId, 'x');

        $this->assertTrue($r['ok']);
        $this->assertFalse($r['item']['ok']);
        $this->assertSame(t('scenario.item_slot_unmapped', ['slot' => 'Ομάδα Α']), $r['item']['error']);
        $this->assertSame(0, $this->liveCount());
        $this->assertSame(0, (int) dbFetchValue("SELECT COUNT(*) FROM mission_dispatch_points WHERE mission_id = ? AND team_id IS NULL", [$this->missionId]));
    }

    public function testSlotNamesAreTrimmedUniqueAndLimited(): void
    {
        $this->assertFalse(addScenarioSlot($this->mission, $this->adminId, '   ')['ok']);
        $a = addScenarioSlot($this->mission, $this->adminId, "  Ομάδα   Α  ");
        $this->assertTrue($a['ok']);
        $this->assertSame('Ομάδα Α', dbFetchValue("SELECT label FROM mission_scenario_slots WHERE id = ?", [$a['id']]), 'spaces are tidied');
        $dup = addScenarioSlot($this->mission, $this->adminId, 'ομάδα α');
        $this->assertFalse($dup['ok'], 'the same name in another case is the same name');
        $this->assertSame(t('scenario.slot_label_taken', ['label' => 'ομάδα α']), $dup['error']);
        // Another mission may use the same name.
        $this->assertTrue(addScenarioSlot(['id' => $this->otherMissionId, 'title' => 'x'], $this->adminId, 'Ομάδα Α')['ok']);
        $long = addScenarioSlot($this->mission, $this->adminId, str_repeat('ω', 300));
        $this->assertSame(SCENARIO_SLOT_LABEL_MAX, mb_strlen((string) dbFetchValue("SELECT label FROM mission_scenario_slots WHERE id = ?", [$long['id']])));
        for ($i = 0; $i < SCENARIO_MAX_SLOTS; $i++) {
            addScenarioSlot($this->mission, $this->adminId, 'Θέση ' . $i);
        }
        $this->assertFalse(addScenarioSlot($this->mission, $this->adminId, 'Μία ακόμα')['ok']);
    }

    public function testASlotCannotBeDeletedFromUnderAnOrderThatHasNotBeenSent(): void
    {
        $slot = $this->slot();
        $phase = $this->phase();
        $item = $this->stage($phase, $this->slotOrder($slot))['id'];

        $this->assertSame(t('scenario.slot_in_use'), deleteScenarioSlot($this->mission, $slot, $this->adminId));
        $this->assertNotNull(scenarioSlotRow($this->missionId, $slot));

        $this->assertNull(deleteScenarioItem($this->mission, $item, $this->adminId));
        $this->assertNull(deleteScenarioSlot($this->mission, $slot, $this->adminId));
        $this->assertNull(scenarioSlotRow($this->missionId, $slot));
        $this->assertSame(t('scenario.slot_not_found'), deleteScenarioSlot($this->mission, $slot, $this->adminId));
    }

    public function testASentOrderDoesNotHoldItsSlotAndTheCardStillNamesIt(): void
    {
        $slot = $this->slot('Ομάδα Γ');
        $phase = $this->phase();
        $this->stage($phase, $this->slotOrder($slot));
        $this->assertTrue($this->play2($phase, [$slot => $this->teamId])['ok']);

        $this->assertNull(deleteScenarioSlot($this->mission, $slot, $this->adminId));
        $item = loadMissionScenario($this->missionId)['phases'][0]['items'][0];
        $this->assertTrue($item['sent']);
        $this->assertSame('Ομάδα Γ', $item['slot_label'], 'the name is kept in the order itself');
    }

    public function testMappingASlotChecksTheTeamAndCanTakeItBack(): void
    {
        $slot = $this->slot();
        $foreignTeam = $this->makeTeam('ΔΕΛΤΑ', 9, $this->otherMissionId);

        $this->assertSame(t('common.team_not_found'), mapScenarioSlot($this->mission, $slot, $foreignTeam, $this->adminId));
        $this->assertSame(t('scenario.slot_not_found'), mapScenarioSlot($this->mission, 987654321, $this->teamId, $this->adminId));
        $this->assertNull(mapScenarioSlot($this->mission, $slot, $this->teamId, $this->adminId));
        $this->assertSame($this->teamId, (int) dbFetchValue("SELECT team_id FROM mission_scenario_slots WHERE id = ?", [$slot]));
        $this->assertNull(mapScenarioSlot($this->mission, $slot, null, $this->adminId));
        $this->assertNull(dbFetchValue("SELECT team_id FROM mission_scenario_slots WHERE id = ?", [$slot]));
    }

    public function testStagingToAnotherMissionsSlotOrAnInventedOneIsRefused(): void
    {
        $foreign = addScenarioSlot(['id' => $this->otherMissionId, 'title' => 'x'], $this->adminId, 'Ξένη')['id'];
        $phase = $this->phase();

        foreach (['slot:' . $foreign, 'slot:987654321', 'slot:0', 'slot:', 'slot:abc', 'slot:-1'] as $team) {
            $this->assertFalse($this->stage($phase, $this->point(['team_id' => $team]))['ok'], $team);
        }
        $this->assertSame([], loadMissionScenario($this->missionId)['phases'][0]['items']);
    }

    public function testTheOrderToASlotIsCheckedLikeAnyOtherOrder(): void
    {
        $slot = $this->slot();
        $phase = $this->phase();

        $this->assertFalse($this->stage($phase, $this->slotOrder($slot, ['geo' => json_encode(['lat' => 0, 'lng' => 0])]))['ok']);
        $this->assertFalse($this->stage($phase, $this->slotOrder($slot, ['type' => 'circle']))['ok']);
        $this->assertTrue($this->stage($phase, $this->slotOrder($slot, ['type' => 'polygon', 'geo' => json_encode([[35.0, 24.8], [35.0, 24.9], [35.1, 24.9]])]))['ok']);
    }

    // ── Sectors (v3.454.0) ─────────────────────────────────────────────────────────────────────────────────────

    private function searchArea(?int $missionId = null, string $label = 'Περιοχή Ρέματος'): int
    {
        return (int) dbInsert(
            "INSERT INTO mission_search_areas (mission_id, label, geo, created_by, created_at) VALUES (?, ?, ?, ?, NOW())",
            [$missionId ?? $this->missionId, $label, json_encode([[35.0, 24.8], [35.0, 25.0], [35.2, 25.0], [35.2, 24.8]]), $this->adminId]
        );
    }

    /** One row of the sector window's division, as stageSectors() takes it. */
    private function sectorRow(array $over = []): array
    {
        return $over + ['team_id' => (string) $this->teamId, 'label' => 'Τομέας Α', 'geo' => [[35.0, 24.8], [35.0, 24.9], [35.1, 24.9]]];
    }

    private function stageSectors(int $phaseId, int $areaId, array $rows): array
    {
        return addScenarioSectorItems($this->mission, $phaseId, $this->adminId, $areaId, $rows);
    }

    private function liveSectorCount(): int
    {
        return (int) dbFetchValue("SELECT COUNT(*) FROM mission_search_sectors WHERE mission_id = ?", [$this->missionId]);
    }

    private function auditCount(string $action): int
    {
        return (int) dbFetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = ?", [$action]);
    }

    /** @return int[] ids that got a notification about the sector $sectorId. */
    private function notifiedAboutSector(int $sectorId): array
    {
        $ids = [];
        foreach (dbFetchAll("SELECT user_id FROM notifications WHERE data LIKE ?", ['%"sectorId":' . $sectorId . '}%']) as $row) {
            $ids[] = (int) $row['user_id'];
        }
        sort($ids);
        return $ids;
    }

    private function stagedItemCount(int $phaseId): int
    {
        return (int) dbFetchValue("SELECT COUNT(*) FROM mission_scenario_items WHERE phase_id = ?", [$phaseId]);
    }

    public function testASectorCanBeWrittenDownAndIsNotALiveSectorYet(): void
    {
        $area = $this->searchArea();
        $phase = $this->phase();
        $notes = $this->notificationCount();
        $audit = $this->auditCount('create_mission_sector');

        $r = $this->stageSectors($phase, $area, [$this->sectorRow(['geo' => [['35.0', '24.8'], ['35.0', '24.9'], ['35.1', '24.9']]])]);

        $this->assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        $this->assertSame(1, $r['count']);
        $this->assertSame(0, $this->liveSectorCount(), 'nothing is made until play');
        $this->assertSame($notes, $this->notificationCount(), 'and nobody is told');
        $this->assertSame($audit, $this->auditCount('create_mission_sector'));
        $this->assertSame('sector', dbFetchValue("SELECT kind FROM mission_scenario_items WHERE phase_id = ?", [$phase]));
        $item = loadMissionScenario($this->missionId)['phases'][0]['items'][0];
        $this->assertSame('sector', $item['kind']);
        $this->assertSame('sector', $item['type']);
        $this->assertSame('team', $item['audience']);
        $this->assertSame('ΑΛΦΑ 1', $item['team_label']);
        $this->assertSame('Τομέας Α', $item['label']);
        $this->assertEquals([[35.0, 24.8], [35.0, 24.9], [35.1, 24.9]], $item['geo'], 'stored as numbers, as the real sector would');
        $this->assertSame($area, $item['area_id']);
        $this->assertSame('Περιοχή Ρέματος', $item['area_label']);
        $this->assertFalse($item['area_missing']);
        $this->assertFalse($item['sent']);
        $this->assertNull($item['sector_id']);
        $this->assertNull($item['dispatch_id']);
        $this->assertSame(1, $this->auditCount('stage_scenario_sectors'));
    }

    public function testTheWholeDivisionGoesInTogetherOrNotAtAll(): void
    {
        $area = $this->searchArea();
        $phase = $this->phase();
        $foreignTeam = $this->makeTeam('ΔΕΛΤΑ', 9, $this->otherMissionId);

        foreach ([
            'a piece of two points' => [$this->sectorRow(['label' => 'Α']), $this->sectorRow(['label' => 'Β', 'geo' => [[35.0, 24.8], [35.0, 24.9]]])],
            'a piece for a team of another mission' => [$this->sectorRow(['label' => 'Α']), $this->sectorRow(['label' => 'Β', 'team_id' => (string) $foreignTeam])],
            'a piece with no name' => [$this->sectorRow(['label' => 'Α']), $this->sectorRow(['label' => '   '])],
            'a piece with an invalid point' => [$this->sectorRow(['label' => 'Α']), $this->sectorRow(['label' => 'Β', 'geo' => [[35.0, 24.8], [0, 0], [35.1, 24.9]]])],
        ] as $why => $rows) {
            $r = $this->stageSectors($phase, $area, $rows);
            $this->assertFalse($r['ok'], $why);
            $this->assertSame(0, $this->stagedItemCount($phase), "$why: the good piece is not left behind");
        }
        // The person is told WHICH piece was refused.
        $r = $this->stageSectors($phase, $area, [$this->sectorRow(['label' => 'Α']), $this->sectorRow(['label' => 'Β', 'geo' => [[35.0, 24.8]]])]);
        $this->assertSame(t('scenario.sector_refused', ['sector' => 'Β', 'error' => t('dispatch.polygon_needs_3_points')]), $r['error']);

        $this->assertSame(3, $this->stageSectors($phase, $area, [
            $this->sectorRow(['label' => 'Α']),
            $this->sectorRow(['label' => 'Β', 'team_id' => (string) $this->secondTeamId]),
            $this->sectorRow(['label' => 'Γ']),
        ])['count']);
        $this->assertSame(['Α', 'Β', 'Γ'], array_column(loadMissionScenario($this->missionId)['phases'][0]['items'], 'label'), 'in the order of the division');
        $this->assertFalse($this->stageSectors($phase, $area, [])['ok'], 'an empty division is not a staging');
    }

    public function testASectorGoesToATeamOrASlotNeverToEveryoneOrNobody(): void
    {
        $area = $this->searchArea();
        $phase = $this->phase();

        // A sector has no «όλες οι ομάδες», and an unassigned sector is not an order to anyone.
        foreach (['the word all' => 'all', 'no team' => '', 'spaces' => '  ', 'a word' => 'abc', 'zero' => '0', 'a negative id' => '-3'] as $why => $team) {
            $r = $this->stageSectors($phase, $area, [$this->sectorRow(['team_id' => $team])]);
            $this->assertFalse($r['ok'], $why);
            $this->assertSame(t('scenario.sector_needs_team'), $r['error'], $why);
        }
        $r = $this->stageSectors($phase, $area, [$this->sectorRow(['team_id' => 'slot:987654321'])]);
        $this->assertSame(t('scenario.slot_not_found'), $r['error']);
        $other = ['id' => $this->otherMissionId, 'title' => 'Other'];
        $foreignSlot = addScenarioSlot($other, $this->adminId, 'Ξένη')['id'];
        $this->assertFalse($this->stageSectors($phase, $area, [$this->sectorRow(['team_id' => 'slot:' . $foreignSlot])])['ok'], 'a slot of another mission');
        $this->assertSame(0, $this->stagedItemCount($phase));
    }

    public function testPlayMakesTheSectorExactlyAsTheSectorWindowWould(): void
    {
        $area = $this->searchArea();
        $phase = $this->phase();
        $row = $this->sectorRow(['label' => 'Τομέας Ρέματος']);
        $this->stageSectors($phase, $area, [$row]);

        $result = $this->play($phase);

        $this->assertTrue($result['ok'], (string) ($result['error'] ?? ''));
        $this->assertSame(1, $result['sent']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame('sector', $result['results'][0]['kind']);
        $this->assertNull($result['results'][0]['dispatch_id']);
        $made = dbFetchOne("SELECT * FROM mission_search_sectors WHERE mission_id = ?", [$this->missionId]);
        $this->assertNotNull($made);
        $this->assertSame((int) $made['id'], $result['results'][0]['sector_id']);

        // The control: the same sector made by hand, the way mission-sector.php's `create` does.
        $byHand = createMissionSector($this->mission, $this->adminId, [
            'label' => 'Τομέας Ρέματος', 'area_id' => (string) $area, 'team_id' => (string) $this->teamId, 'geo' => json_encode($row['geo']),
        ]);
        $control = dbFetchOne("SELECT * FROM mission_search_sectors WHERE id = ?", [$byHand['id']]);
        foreach (['mission_id', 'area_id', 'team_id', 'label', 'geo', 'status', 'created_by', 'status_updated_by', 'acknowledged_at'] as $col) {
            $this->assertSame($control[$col], $made[$col], "column $col");
        }
        $this->assertSame('assigned', $made['status']);
        $this->assertSame($this->teamId, (int) $made['team_id']);
        $this->assertNotNull($made['status_updated_at']);

        $log = dbFetchAll("SELECT from_status, to_status, team_id, user_id FROM mission_sector_status_log WHERE sector_id = ?", [$made['id']]);
        $controlLog = dbFetchAll("SELECT from_status, to_status, team_id, user_id FROM mission_sector_status_log WHERE sector_id = ?", [$byHand['id']]);
        $this->assertSame($controlLog, $log);
        $this->assertCount(1, $log);
        $this->assertSame(1, (int) dbFetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'create_mission_sector' AND record_id = ?", [$made['id']]));
        // The team - its approved members - is told, as for the hand-made one, and nobody else.
        $this->assertSame($this->members, $this->notifiedAboutSector((int) $made['id']));
        $this->assertSame($this->notifiedAboutSector((int) $byHand['id']), $this->notifiedAboutSector((int) $made['id']));

        $item = loadMissionScenario($this->missionId)['phases'][0]['items'][0];
        $this->assertTrue($item['sent']);
        $this->assertSame((int) $made['id'], $item['sector_id']);
        $this->assertNull($item['dispatch_id']);
        $this->assertSame(0, $this->liveCount(), 'a sector is not a dispatch');
    }

    public function testASectorForASlotAsksWhichTeamAndIsMadeForThatTeamOnly(): void
    {
        $area = $this->searchArea();
        $slot = $this->slot();
        $phase = $this->phase();
        $this->stageSectors($phase, $area, [
            $this->sectorRow(['label' => 'Για τη θέση', 'team_id' => 'slot:' . $slot]),
            $this->sectorRow(['label' => 'Για την ομάδα']),
        ]);
        $item = loadMissionScenario($this->missionId)['phases'][0]['items'][0];
        $this->assertSame('slot', $item['audience']);
        $this->assertTrue($item['unmapped']);
        $this->assertSame('Ομάδα Α', $item['slot_label']);
        $this->assertSame($slot, (int) dbFetchValue("SELECT slot_id FROM mission_scenario_items WHERE phase_id = ? ORDER BY seq LIMIT 1", [$phase]));

        // The slot mechanism of v3.453.0 covers sectors: the slot cannot be deleted from under one, and the
        // phase is refused BEFORE it is claimed, with nothing made - not even the sector that has a team.
        $this->assertSame(t('scenario.slot_in_use'), deleteScenarioSlot($this->mission, $slot, $this->adminId));
        $this->assertSame([['id' => $slot, 'label' => 'Ομάδα Α']], scenarioUnmappedSlotsOfPhase($this->missionId, $phase));
        $notes = $this->notificationCount();
        $r = $this->play($phase);
        $this->assertFalse($r['ok']);
        $this->assertSame([['id' => $slot, 'label' => 'Ομάδα Α']], $r['unmapped']);
        $this->assertNull(loadMissionScenario($this->missionId)['phases'][0]['played_at'], 'still only a plan');
        $this->assertSame(0, $this->liveSectorCount());
        $this->assertSame($notes, $this->notificationCount());

        // The dialog's answer: the sector is made for that team, and for no other.
        $r = $this->play2($phase, [$slot => $this->secondTeamId]);
        $this->assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        $this->assertSame(2, $r['sent']);
        $teams = dbFetchAll("SELECT label, team_id, status FROM mission_search_sectors WHERE mission_id = ? ORDER BY id", [$this->missionId]);
        $this->assertSame([['label' => 'Για τη θέση', 'team_id' => $this->secondTeamId, 'status' => 'assigned'], ['label' => 'Για την ομάδα', 'team_id' => $this->teamId, 'status' => 'assigned']], $teams);
        $this->assertSame($this->secondTeamId, (int) dbFetchValue("SELECT team_id FROM mission_scenario_slots WHERE id = ?", [$slot]), 'the choice stays');
        $this->assertSame(0, (int) dbFetchValue("SELECT COUNT(*) FROM mission_search_sectors WHERE mission_id = ? AND team_id IS NULL", [$this->missionId]));
    }

    public function testASectorWhoseSearchAreaWasDeletedFailsWithThatReasonAndMakesNothing(): void
    {
        $area = $this->searchArea(null, 'Περιοχή που θα φύγει');
        $phase = $this->phase();
        $this->stage($phase, $this->point(['label' => 'Σημείο']));
        $this->stageSectors($phase, $area, [$this->sectorRow(['label' => 'Ορφανός'])]);
        dbExecute("DELETE FROM mission_search_areas WHERE id = ?", [$area]);

        $item = loadMissionScenario($this->missionId)['phases'][0]['items'][1];
        $this->assertTrue($item['area_missing'], 'the plan shows it before play');
        $this->assertSame('Περιοχή που θα φύγει', $item['area_label'], 'and still names the area that was');
        $audit = $this->auditCount('create_mission_sector');

        $result = $this->play($phase);

        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['sent'], 'the other order still goes');
        $this->assertSame(1, $result['failed']);
        $reason = t('scenario.item_area_gone', ['area' => 'Περιοχή που θα φύγει']);
        $this->assertSame($reason, $result['results'][1]['error']);
        $this->assertSame(0, $this->liveSectorCount(), 'no sector, and above all not one with no area or no team');
        $this->assertSame($audit, $this->auditCount('create_mission_sector'));
        $items = loadMissionScenario($this->missionId)['phases'][0]['items'];
        $this->assertFalse($items[1]['sent']);
        $this->assertSame($reason, $items[1]['error']);

        // Trying again fails the same way, and still makes nothing.
        $again = retryScenarioItem($this->mission, $items[1]['id'], $this->adminId, 'x');
        $this->assertTrue($again['ok']);
        $this->assertFalse($again['item']['ok']);
        $this->assertSame($reason, $again['item']['error']);
        $this->assertSame(0, $this->liveSectorCount());
    }

    public function testASectorWhoseTeamIsGoneFailsAndIsNotMadeUnassigned(): void
    {
        $doomed = $this->makeTeam('ΓΑΜΜΑ', 3);
        $area = $this->searchArea();
        $phase = $this->phase();
        $this->stageSectors($phase, $area, [$this->sectorRow(['team_id' => (string) $doomed])]);
        dbExecute("DELETE FROM mission_teams WHERE id = ?", [$doomed]);
        $this->assertTrue(loadMissionScenario($this->missionId)['phases'][0]['items'][0]['team_missing']);
        $notes = $this->notificationCount();

        $result = $this->play($phase);

        $this->assertSame(1, $result['failed']);
        $this->assertSame(t('common.team_not_found'), $result['results'][0]['error']);
        $this->assertSame(0, $this->liveSectorCount(), 'an unassigned sector would tell nobody, and is not what was staged');
        $this->assertSame($notes, $this->notificationCount());
    }

    public function testAStagedSectorBecomesASectorWithATeamOrNotAtAll(): void
    {
        $poly = [[35.0, 24.8], [35.0, 24.9], [35.1, 24.9]];
        $team = ['audience' => 'team', 'team_id' => 7, 'area_id' => 3, 'label' => 'Α', 'geo' => $poly];
        $input = scenarioSectorInputFromPayload($team);
        $this->assertSame(['area_id' => '3', 'team_id' => '7', 'label' => 'Α', 'geo' => json_encode($poly)], $input);
        $this->assertSame('9', scenarioSectorInputFromPayload(['audience' => 'slot', 'slot_id' => 4] + $team, [4 => 9])['team_id']);

        foreach ([
            'every team' => ['audience' => 'all'] + $team,
            'team without an id' => ['team_id' => null] + $team,
            'team id 0' => ['team_id' => 0] + $team,
            'no audience at all' => array_diff_key($team, ['audience' => 1]),
            'an audience that is neither' => ['audience' => 'everyone'] + $team,
            'a slot that stands for no team' => ['audience' => 'slot', 'slot_id' => 4] + $team,
            'a slot of the payload that the map does not know' => ['audience' => 'slot', 'slot_id' => 5] + $team,
            'no area' => ['area_id' => 0] + $team,
            'no shape' => array_diff_key($team, ['geo' => 1]),
            'a shape that is not a list' => ['geo' => 'x'] + $team,
        ] as $why => $payload) {
            $this->assertNull(scenarioSectorInputFromPayload($payload, [4 => null]), $why);
        }
        $this->assertNull(scenarioSectorInputFromPayload('not an array'));
        $this->assertNull(scenarioSectorInputFromPayload(null));
        // «Whatever the payload says, the form it gives always names a team»: the one property that matters.
        $this->assertNotSame('', scenarioSectorInputFromPayload($team)['team_id']);
    }

    public function testASectorCannotBeStagedInAnotherMissionsAreaOrOneThatIsNotThere(): void
    {
        $phase = $this->phase();
        $foreign = $this->searchArea($this->otherMissionId, 'Ξένη περιοχή');
        $r = $this->stageSectors($phase, $foreign, [$this->sectorRow()]);
        $this->assertFalse($r['ok']);
        $this->assertSame(t('sector.area_not_found'), $r['error']);
        $this->assertFalse($this->stageSectors($phase, 987654321, [$this->sectorRow()])['ok']);
        $this->assertFalse($this->stageSectors($phase, 0, [$this->sectorRow()])['ok']);
        $this->assertSame(0, $this->stagedItemCount($phase));
        // Nor into a phase of another mission, or one that was already played.
        $area = $this->searchArea();
        $foreignPhase = addScenarioPhase(['id' => $this->otherMissionId, 'title' => 'x'], $this->adminId)['id'];
        $this->assertSame(t('scenario.phase_not_found'), $this->stageSectors($foreignPhase, $area, [$this->sectorRow()])['error']);
        $this->stage($phase, $this->point());
        $this->assertTrue($this->play($phase)['ok']);
        $this->assertSame(t('scenario.phase_closed_for_orders'), $this->stageSectors($phase, $area, [$this->sectorRow()])['error']);
    }

    public function testAPhaseCanHoldPointsAreasAndSectorsAndPlaysThemAllInOrder(): void
    {
        $area = $this->searchArea();
        $phase = $this->phase();
        $this->stage($phase, $this->point(['label' => 'Σημείο']));
        $this->stageSectors($phase, $area, [$this->sectorRow(['label' => 'Τομέας'])]);
        $this->stage($phase, $this->area(['label' => 'Περιοχή αποστολής']));

        $result = $this->play($phase);

        $this->assertSame(3, $result['sent']);
        $this->assertSame(['dispatch', 'sector', 'dispatch'], array_column($result['results'], 'kind'));
        $this->assertSame(2, $this->liveCount());
        $this->assertSame(1, $this->liveSectorCount());
        $items = loadMissionScenario($this->missionId)['phases'][0]['items'];
        $this->assertSame(['dispatch', 'sector', 'dispatch'], array_column($items, 'kind'));
        $this->assertNull($items[0]['sector_id']);
        $this->assertNull($items[1]['dispatch_id']);
        $this->assertNotNull($items[1]['sector_id']);
    }

    public function testSectorsCountAgainstTheLimitOfOrdersInAPhase(): void
    {
        $area = $this->searchArea();
        $phase = $this->phase();
        $rows = [];
        for ($i = 1; $i <= SCENARIO_MAX_ITEMS_PER_PHASE + 1; $i++) {
            $rows[] = $this->sectorRow(['label' => 'Τ' . $i]);
        }
        $r = $this->stageSectors($phase, $area, $rows);
        $this->assertFalse($r['ok']);
        $this->assertSame(t('scenario.too_many_orders', ['max' => SCENARIO_MAX_ITEMS_PER_PHASE]), $r['error']);
        $this->assertSame(0, $this->stagedItemCount($phase), 'none of them, not the first sixty');
        $this->assertTrue($this->stage($phase, $this->point())['ok']);
        $this->assertFalse($this->stageSectors($phase, $area, array_slice($rows, 0, SCENARIO_MAX_ITEMS_PER_PHASE))['ok'], 'the order already in the phase counts');
        $this->assertTrue($this->stageSectors($phase, $area, array_slice($rows, 0, SCENARIO_MAX_ITEMS_PER_PHASE - 1))['ok']);
    }

    public function testPrepareMissionSectorChecksAndWritesNothing(): void
    {
        $area = $this->searchArea(null, 'Περιοχή Έρευνας');
        $before = [$this->liveSectorCount(), $this->notificationCount(), $this->auditCount('create_mission_sector')];

        $ok = prepareMissionSector($this->mission, ['label' => '  Τομέας  ', 'area_id' => (string) $area, 'team_id' => (string) $this->teamId, 'geo' => json_encode([[35.0, 24.8], [35.0, 24.9], [35.1, 24.9]])]);
        $this->assertTrue($ok['ok']);
        $this->assertSame('Τομέας', $ok['label']);
        $this->assertSame($area, $ok['area_id']);
        $this->assertSame('Περιοχή Έρευνας', $ok['area_label']);
        $this->assertSame($this->teamId, $ok['team_id']);
        $this->assertSame('ΑΛΦΑ 1', $ok['team_label']);
        $noTeam = prepareMissionSector($this->mission, ['label' => 'Τ', 'area_id' => $area, 'team_id' => '', 'geo' => json_encode([[35.0, 24.8], [35.0, 24.9], [35.1, 24.9]])]);
        $this->assertNull($noTeam['team_id']);
        $this->assertNull($noTeam['team_label']);

        $this->assertSame($before, [$this->liveSectorCount(), $this->notificationCount(), $this->auditCount('create_mission_sector')]);
    }

    public function testPlayMakesSectorsOnlyThroughTheSectorFunction(): void
    {
        $src = $this->source('includes/functions-scenario.php');
        $this->assertStringContainsString('createMissionSector($mission, $userId, $input)', $src);
        $this->assertStringContainsString('prepareMissionSector($mission,', $src);
        $this->assertStringNotContainsString('INSERT INTO mission_search_sectors', $src, 'the scenario never writes a live sector itself');
        $this->assertStringNotContainsString('mission_sector_status_log', $src);
        $this->assertStringNotContainsString('notifySectorAssigned(', $src, 'and never tells the team itself');

        // The sector endpoint hands `create` to the same function, and the notification it needs lives where the
        // scenario's endpoint (which never loads the sector one) can reach it.
        $endpoint = $this->source('mission-sector.php');
        $create = substr($endpoint, (int) strpos($endpoint, "if (\$action === 'create') {"), 900);
        $this->assertStringContainsString('createMissionSector($mission, (int) $userId', $create);
        $this->assertStringNotContainsString('INSERT INTO mission_search_sectors', $create);
        $this->assertStringNotContainsString('function notifySectorAssigned', $endpoint);
        $this->assertSame(1, substr_count($this->source('includes/functions-warroom.php'), 'function notifySectorAssigned('));
    }

    public function testPlayAndRetryHandBackTheLiveSectorsTheyMade(): void
    {
        // The map has to show a sector the moment play made it, not at the next poll: the answer carries the pair
        // of lists the sector endpoint answers with, and only when a sector was made.
        $src = $this->source('mission-scenario.php');
        $this->assertStringContainsString("fn(\$r) => (\$r['kind'] ?? '') === 'sector' && !empty(\$r['ok'])", $src);
        $this->assertStringContainsString("'sectors' => loadMissionSectorsForUser(\$missionId, (int) \$userId, true, false), 'areas' => loadMissionSearchAreasForUser(\$missionId, true)", $src);
        $this->assertStringContainsString("] + \$liveSectors(\$result['results']));", $src, 'play');
        $this->assertStringContainsString("] + \$liveSectors([\$result['item']]));", $src, 'retry');
        $this->assertStringContainsString("if (\$action === 'add_sectors') {", $src);
    }

    public function testTheSectorWindowStagesButNeverMakesASectorForASlot(): void
    {
        $page = $this->source('war-room.php');
        $this->assertStringContainsString('id="divideScenarioBtn"', $page);
        $start = (int) strpos($page, "const scenarioBtn = document.getElementById('divideScenarioBtn')");
        $this->assertGreaterThan(0, $start);
        $js = substr($page, $start, 22000);
        // «Στο σενάριο» needs a team or a slot for every piece: never an empty one, never the unfinished "new slot" row.
        $this->assertStringContainsString("const lacking = values.some(v => v === '' || v === 'slot:new');", $js);
        $this->assertStringContainsString('window.wrScenario.stageSectors(choice.dataset.scPhase, currentArea.id, sectors)', $js);
        // «Αποθήκευση Τομέων» makes sectors now, so it refuses a slot - disabled, and once more in its handler.
        $this->assertStringContainsString("saveBtn.disabled = values.length === 0 || hasSlot;", $js);
        $this->assertStringContainsString("if (Array.from(teamSelects).some(sel => sel.value.indexOf('slot:') === 0)) return;", $js);
        // The window's ghosts: what is already staged is drawn under the division.
        $this->assertStringContainsString('window.wrScenario.drawGhostsInto(refLayer)', $js);

        $scenarioJs = $this->source('assets/js/war-room-scenario.js');
        $this->assertStringContainsString("request('add_sectors'", $scenarioJs);
        $this->assertStringContainsString('stageSectors: stageSectors', $scenarioJs);
        $this->assertStringContainsString('showNewSectors(res);', $scenarioJs, 'sectors made by play or retry appear on the map at once');
        $this->assertStringContainsString("item.type === 'sector'", $scenarioJs);
        // A sector whose search area has been deleted is red in the card and counted in the play confirmation.
        $this->assertStringContainsString('} else if (item.area_missing) {', $scenarioJs);
        $this->assertStringContainsString("t('scenario.play_confirm_area_missing'", $scenarioJs);
    }

    // ── Where it may be read, and who may ask ───────────────────────────────────────────────────────────────

    public function testOnlyTheScenarioCodeReadsTheScenarioTables(): void
    {
        // The reason for own tables: a reader of the live orders cannot show a staged one if it never reads
        // these. This lists every PHP file that so much as names them.
        $allowed = [
            'includes/functions-scenario.php',
            'includes/migrations.php',
            'sql/schema.sql',
        ];
        $root = realpath(__DIR__ . '/..');
        $found = [];
        $walk = function (string $dir) use (&$walk, &$found, $root): void {
            foreach (scandir($dir) as $name) {
                if ($name === '.' || $name === '..' || in_array($name, ['vendor', 'node_modules', '.git', '.claude', 'tests', 'DEPLOYMENT_PACKAGE', 'backups', 'storage', 'uploads', 'mobile-app', 'mobile-app-yphresies'], true)) {
                    continue;
                }
                $path = $dir . '/' . $name;
                if (is_dir($path)) {
                    $walk($path);
                } elseif (preg_match('/\.(php|js|sql)$/', $name) && str_contains((string) file_get_contents($path), 'mission_scenario_')) {
                    $found[] = str_replace('\\', '/', substr($path, strlen($root) + 1));
                }
            }
        };
        $walk($root);
        sort($found);
        $this->assertSame($allowed, $found, 'a new reader of the scenario tables must be a deliberate decision');
    }

    public function testTheEndpointRefusesAnyoneWhoCannotManageTheActionRoomBeforeItReadsAnything(): void
    {
        $src = $this->source('mission-scenario.php');
        $gate = strpos($src, 'canManageActionRoom(');
        $this->assertNotFalse($gate);
        $refuse = strpos($src, "if (!\$canManageWarRoom) {", $gate);
        $this->assertNotFalse($refuse);
        $this->assertStringContainsString("scenario.no_permission", substr($src, $refuse, 200));
        // GET and POST both come after the gate; so does every read of the plan.
        $this->assertGreaterThan($refuse, strpos($src, 'loadMissionScenario('));
        $this->assertGreaterThan($refuse, strpos($src, 'if (!isPost())'));
        // POSTs need the CSRF token before any action.
        $csrf = strpos($src, "hash_equals(\$_SESSION['csrf_token']");
        $this->assertNotFalse($csrf);
        foreach (['add_phase', 'rename_phase', 'delete_phase', 'add_item', 'add_sectors', 'delete_item', 'play_phase', 'retry_item'] as $action) {
            $this->assertGreaterThan($csrf, strpos($src, "'$action'"), "$action is after the CSRF check");
        }
        // The same open-mission gate as the dispatch endpoint.
        $this->assertStringContainsString('STATUS_OPEN', $src);
        $this->assertStringContainsString('show_in_ops', $src);
    }

    public function testPlayGoesThroughTheOrdinaryOrderFunction(): void
    {
        $src = $this->source('includes/functions-scenario.php');
        $this->assertStringContainsString('createMissionDispatch($mission, $userId, $userName, $input)', $src);
        $this->assertStringContainsString('prepareMissionDispatch($mission,', $src);
        $this->assertStringNotContainsString('INSERT INTO mission_dispatch_points', $src, 'the scenario never writes a live order itself');
        $this->assertStringNotContainsString('sendNotification(', $src, 'and never tells anyone itself');
    }

    public function testThePageLoadsTheScenarioOnlyForCommand(): void
    {
        $page = $this->source('war-room.php');
        $tag = strpos($page, '/assets/js/war-room-scenario.js?v=');
        $this->assertNotFalse($tag);
        $before = substr($page, max(0, $tag - 200), 200);
        $this->assertStringContainsString('if ($canManageWarRoom)', $before);
        // The card is inside a command-only block, and the plan is not in the page's data.
        $card = strpos($page, 'data-card-id="scenarioCard"');
        $this->assertNotFalse($card);
        $this->assertStringContainsString('<div id="scenarioBody"></div>', $page);
        $this->assertStringNotContainsString('mission_scenario', $page);
        $this->assertStringNotContainsString('loadMissionScenario', $page);
        $js = $this->source('assets/js/war-room-scenario.js');
        $this->assertStringContainsString("if (item.sent || !item.geo) return;", $js, 'a ghost is only ever an order not yet sent');
        $this->assertStringContainsString('mission-scenario.php', $js);
    }

    public function testStagingToASlotIsNotBlockedBySendButtonAndSendingToOneIsNot(): void
    {
        // Found with a real click (v3.453.0): the menu handler asked «Αποστολή» whether it was ready, and for a
        // slot «Αποστολή» is rightly disabled, so choosing a phase did nothing at all.
        $page = $this->source('war-room.php');
        $menu = strpos($page, "scenarioMenu.addEventListener('click'");
        $stage = strpos($page, 'window.wrScenario.stage(', (int) $menu);
        $this->assertNotFalse($menu);
        $this->assertNotFalse($stage);
        $handler = substr($page, $menu, $stage - $menu);
        $this->assertStringNotContainsString('sendBtn.disabled', $handler, 'the scenario button decides, not the send button');
        $this->assertStringContainsString('scenarioBtn.disabled', $handler);
        // And «Αποστολή» itself is never enabled for a slot: it is a name for a team, not a team.
        $this->assertStringContainsString("teamSelect.value.indexOf('slot:') === 0", $page);
    }

    public function testBothBootstrapsLoadTheScenarioFile(): void
    {
        $this->assertStringContainsString("includes/functions-scenario.php", $this->source('bootstrap.php'));
        $this->assertStringContainsString("includes/functions-scenario.php", $this->source('tests/bootstrap.php'));
        $this->assertTrue(function_exists('loadMissionScenario'));
    }

    public function testEveryScenarioStringExistsInBothLanguages(): void
    {
        $strings = require __DIR__ . '/../includes/lang/war-room.php';
        $used = [];
        foreach (['includes/functions-scenario.php', 'mission-scenario.php', 'assets/js/war-room-scenario.js', 'war-room.php'] as $file) {
            preg_match_all("/['\"](scenario\.[a-z_]+)['\"]/", $this->source($file), $m);
            $used = array_merge($used, $m[1]);
        }
        $used = array_unique($used);
        $this->assertNotEmpty($used);
        foreach ($used as $key) {
            $this->assertArrayHasKey($key, $strings['el'], "$key (el)");
            $this->assertArrayHasKey($key, $strings['en'], "$key (en)");
            $this->assertNotSame('', trim($strings['el'][$key]));
        }
        $this->assertSame(array_keys(array_filter($strings['el'], fn($k) => str_starts_with($k, 'scenario.'), ARRAY_FILTER_USE_KEY)),
            array_keys(array_filter($strings['en'], fn($k) => str_starts_with($k, 'scenario.'), ARRAY_FILTER_USE_KEY)), 'same keys, same order');
    }
}
