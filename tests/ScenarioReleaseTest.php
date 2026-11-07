<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The scenario's per-team release (v3.461.0): pressing play on a phase ARMS it. Each order goes out at once if its team has finished
 * the earlier phases, and otherwise waits and goes out BY ITSELF when the team does. includes/functions-scenario.php (scenarioPlayPhaseItems,
 * scenarioReleaseWaiting, scenarioGatingFacts, sendScenarioWaitingNow, cancelScenarioWait), the three places a team's order is finished
 * (recordDispatchProgress, maybeCompleteRoute, mission-sector.php `status`), mission-scenario.php, assets/js/war-room-scenario.js.
 *
 * The things that must hold: a team that has finished goes at once and the others wait; the release happens from EVERY way of finishing;
 * one waiting order is sent once however many things try; a team that said «Δεν μπορώ» still blocks; an order that is withdrawn, failed
 * or never sent never blocks; an order to every team is split per team only when some team is not ready; and an order whose team is gone is
 * never sent to everybody instead.
 *
 * Runs inside a transaction that is always rolled back.
 */
final class ScenarioReleaseTest extends TestCase
{
    private const SQUARE = [[35.300, 25.100], [35.300, 25.101], [35.301, 25.101], [35.301, 25.100]];

    private int $missionId;
    private int $otherMissionId;
    private int $adminId;
    private int $shiftId;
    private array $mission;
    /** @var array<string, array{team: int, member: int}> */
    private array $teams = [];

    protected function setUp(): void
    {
        db()->beginTransaction();
        $this->adminId = $this->makeUser('Release Admin');
        $missionTypeId = (int) dbFetchValue("SELECT id FROM mission_types ORDER BY id LIMIT 1");
        $mk = function (string $title) use ($missionTypeId): int {
            return (int) dbInsert(
                "INSERT INTO missions (title, location, start_datetime, end_datetime, mission_type_id) VALUES (?, ?, ?, ?, ?)",
                [$title, 'Ηράκλειο', date('Y-m-d H:i:s', time() - 7200), date('Y-m-d H:i:s', time() + 7200), $missionTypeId]
            );
        };
        $this->missionId = $mk('Release Mission');
        $this->otherMissionId = $mk('Other Release Mission');
        $this->mission = ['id' => $this->missionId, 'title' => 'Release Mission'];
        // A release only happens in a mission that is running (the endpoint is closed to any other).
        dbExecute("UPDATE missions SET status = ? WHERE id = ?", [STATUS_OPEN, $this->missionId]);
        $this->shiftId = (int) dbInsert(
            "INSERT INTO shifts (mission_id, start_time, end_time) VALUES (?, ?, ?)",
            [$this->missionId, date('Y-m-d H:i:s', time() - 3600), date('Y-m-d H:i:s', time() + 3600)]
        );
        $this->teams['A'] = $this->teamWithMember('ΑΛΦΑ', 1);
        $this->teams['B'] = $this->teamWithMember('ΒΗΤΑ', 2);
        $this->teams['C'] = $this->teamWithMember('ΓΑΜΜΑ', 3);
        forgetActiveOrderDeclines();
    }

    protected function tearDown(): void
    {
        db()->rollBack();
    }

    private function makeUser(string $name): int
    {
        return (int) dbInsert("INSERT INTO users (name, email, password) VALUES (?, ?, ?)", [$name, 'rl-' . uniqid('', true) . '@example.invalid', 'x']);
    }

    private function teamWithMember(string $codename, int $number): array
    {
        $team = (int) dbInsert(
            "INSERT INTO mission_teams (mission_id, codename, team_number, created_by) VALUES (?, ?, ?, ?)",
            [$this->missionId, $codename, $number, $this->adminId]
        );
        $member = $this->makeUser('Μέλος ' . $codename);
        dbInsert("INSERT INTO participation_requests (shift_id, volunteer_id, status) VALUES (?, ?, ?)", [$this->shiftId, $member, PARTICIPATION_APPROVED]);
        dbInsert("INSERT INTO mission_action_room_participants (mission_id, user_id) VALUES (?, ?)", [$this->missionId, $member]);
        dbInsert("INSERT INTO mission_team_members (team_id, mission_id, user_id) VALUES (?, ?, ?)", [$team, $this->missionId, $member]);
        forgetActionRoomParticipantIds($this->missionId);
        return ['team' => $team, 'member' => $member];
    }

    private function t(string $key): int
    {
        return $this->teams[$key]['team'];
    }

    private function phase(): int
    {
        $r = addScenarioPhase($this->mission, $this->adminId, '');
        $this->assertTrue($r['ok']);
        return $r['id'];
    }

    private function point(string $team, string $label = 'Ρέμα'): array
    {
        return [
            'team_id' => $team === 'all' ? 'all' : (string) $this->t($team), 'type' => 'point', 'label' => $label,
            'geo' => json_encode(['lat' => 35.1, 'lng' => 25.1]), 'travel_mode' => '',
        ];
    }

    private function stagePoint(int $phaseId, string $team, string $label = 'Ρέμα'): int
    {
        $r = addScenarioDispatchItem($this->mission, $phaseId, $this->adminId, $this->point($team, $label));
        $this->assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        return (int) $r['id'];
    }

    private function stageArea(int $phaseId, string $team = 'all'): int
    {
        $r = addScenarioDispatchItem($this->mission, $phaseId, $this->adminId, [
            'team_id' => $team === 'all' ? 'all' : (string) $this->t($team), 'type' => 'polygon', 'label' => 'Χωράφι',
            'geo' => json_encode([[35.0, 24.8], [35.0, 24.9], [35.1, 24.9]]), 'travel_mode' => '',
        ]);
        $this->assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        return (int) $r['id'];
    }

    private function liveArea(): int
    {
        return (int) createMissionSearchArea($this->mission, $this->adminId, ['label' => 'Περιοχή', 'geo' => json_encode(self::SQUARE)])['id'];
    }

    private function stageSector(int $phaseId, string $team, string $label = 'Τομέας Α'): int
    {
        $r = addScenarioSectorItems($this->mission, $phaseId, $this->adminId, $this->liveArea(), [['team_id' => (string) $this->t($team), 'label' => $label, 'geo' => self::SQUARE]]);
        $this->assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        return (int) dbFetchValue("SELECT id FROM mission_scenario_items WHERE phase_id = ? AND kind = 'sector' ORDER BY id DESC LIMIT 1", [$phaseId]);
    }

    private function stageRoute(int $phaseId, string $team): int
    {
        $r = addScenarioRouteItem($this->mission, $phaseId, $this->adminId, [
            'team_id' => (string) $this->t($team), 'title' => 'Περίπολος',
            'waypoints' => json_encode([['lat' => 35.1, 'lng' => 25.1, 'label' => 'Αρχή'], ['lat' => 35.2, 'lng' => 25.2]]),
            'is_closed_loop' => '0', 'add_return_waypoint' => '0', 'member_ids' => '[]', 'travel_mode' => 'foot',
        ]);
        $this->assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        return (int) $r['id'];
    }

    private function stageMessage(int $phaseId, string $team): int
    {
        $r = addScenarioOrderItem($this->mission, $phaseId, $this->adminId, [
            'order_type' => 'message', 'text' => 'Προσοχή στο ρέμα', 'team_id' => $team === 'all' ? 'all' : (string) $this->t($team),
        ]);
        $this->assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        return (int) $r['id'];
    }

    private function play(int $phaseId): array
    {
        $r = playScenarioPhase($this->mission, $phaseId, $this->adminId, 'Release Admin');
        $this->assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        return $r;
    }

    private function item(int $itemId): array
    {
        return dbFetchOne("SELECT * FROM mission_scenario_items WHERE id = ?", [$itemId]);
    }

    private function loaded(int $itemId): array
    {
        foreach (loadMissionScenario($this->missionId)['phases'] as $phase) {
            foreach ($phase['items'] as $item) {
                if ($item['id'] === $itemId) {
                    return $item;
                }
            }
        }
        $this->fail('item ' . $itemId . ' is not in the plan');
    }

    private function phaseOf(int $phaseId): array
    {
        foreach (loadMissionScenario($this->missionId)['phases'] as $phase) {
            if ($phase['id'] === $phaseId) {
                return $phase;
            }
        }
        $this->fail('phase ' . $phaseId . ' is not in the plan');
    }

    /** The live dispatch an item became, or 0. */
    private function dispatchOf(int $itemId): int
    {
        return (int) ($this->item($itemId)['sent_ref_id'] ?? 0);
    }

    private function finish(int $dispatchId, string $team): void
    {
        recordDispatchProgress($dispatchId, $this->t($team), $this->teams[$team]['member'], 'complete');
    }

    private function liveCount(): int
    {
        return (int) dbFetchValue("SELECT COUNT(*) FROM mission_dispatch_points WHERE mission_id = ?", [$this->missionId]);
    }

    private function isWaiting(int $itemId): bool
    {
        $row = $this->item($itemId);
        return $row['sent_at'] === null && $row['waiting_since'] !== null && $row['wait_cancelled_at'] === null;
    }

    private function source(string $rel): string
    {
        return str_replace("\r\n", "\n", (string) file_get_contents(__DIR__ . '/../' . $rel));
    }

    /** Phase 1 sends a point to each of A, B, C; returns [phase id, [team => item id]]. */
    private function threeTeamsInPhaseOne(): array
    {
        $one = $this->phase();
        $items = [];
        foreach (['A', 'B', 'C'] as $team) {
            $items[$team] = $this->stagePoint($one, $team, 'Σημείο ' . $team);
        }
        $this->play($one);
        return [$one, $items];
    }

    // ── Play arms the phase ──────────────────────────────────────────────────────────────────────────────────

    public function testWhenEveryTeamIsReadyAnOrderToEveryTeamStillGoesAsOneRealBroadcast(): void
    {
        [, $first] = $this->threeTeamsInPhaseOne();
        foreach (['A', 'B', 'C'] as $team) {
            $this->finish($this->dispatchOf($first[$team]), $team);
        }
        $two = $this->phase();
        $all = $this->stageArea($two, 'all');
        $result = $this->play($two);

        $this->assertSame(1, $result['sent']);
        $this->assertSame(0, $result['waiting']);
        $row = dbFetchOne("SELECT team_id FROM mission_dispatch_points WHERE id = ?", [$this->dispatchOf($all)]);
        $this->assertNull($row['team_id'], 'one broadcast to every team, as before');
        $this->assertSame(0, (int) dbFetchValue("SELECT COUNT(*) FROM mission_scenario_items WHERE parent_item_id IS NOT NULL"), 'nothing was split');
    }

    public function testOnlyTheTeamThatHasFinishedGetsTheNextPhaseAtOnceAndTheOthersWait(): void
    {
        [, $first] = $this->threeTeamsInPhaseOne();
        $this->finish($this->dispatchOf($first['A']), 'A');
        $two = $this->phase();
        $second = [];
        foreach (['A', 'B', 'C'] as $team) {
            $second[$team] = $this->stagePoint($two, $team, 'Δεύτερο ' . $team);
        }
        $live = $this->liveCount();

        $result = $this->play($two);

        $this->assertSame(1, $result['sent']);
        $this->assertSame(2, $result['waiting']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame($live + 1, $this->liveCount(), 'only A got its order');
        $this->assertGreaterThan(0, $this->dispatchOf($second['A']));
        $this->assertSame($this->t('A'), (int) dbFetchValue("SELECT team_id FROM mission_dispatch_points WHERE id = ?", [$this->dispatchOf($second['A'])]));
        $this->assertTrue($this->isWaiting($second['B']));
        $this->assertTrue($this->isWaiting($second['C']));
        $this->assertSame(0, $this->dispatchOf($second['B']));

        $phase = $this->phaseOf($two);
        $this->assertSame('waiting', $this->loaded($second['B'])['state']);
        $this->assertSame(['phase_n' => 1, 'done' => 0, 'of' => 1, 'cant' => false], array_intersect_key($this->loaded($second['B'])['wait'], array_flip(['phase_n', 'done', 'of', 'cant'])));
        $this->assertCount(2, $phase['waits']);
        $this->assertSame(2, $phase['progress']['waiting']);
        $this->assertSame(3, $phase['progress']['total']);
        $this->assertFalse($phase['progress']['complete']);
        $this->assertTrue($phase['progress']['can_withdraw']);
    }

    public function testWaitingTeamsAreNamedBeforePlayWithWhoGoesNowAndWhoWaits(): void
    {
        [, $first] = $this->threeTeamsInPhaseOne();
        $this->finish($this->dispatchOf($first['A']), 'A');
        $two = $this->phase();
        foreach (['A', 'B'] as $team) {
            $this->stagePoint($two, $team);
        }

        $preview = $this->phaseOf($two)['release_preview'];

        $this->assertSame([$this->t('A')], array_column($preview['now'], 'team_id'));
        $this->assertSame([$this->t('B')], array_column($preview['wait'], 'team_id'));
        $this->assertSame(['phase_n' => 1, 'done' => 0, 'of' => 1], array_intersect_key($preview['wait'][0], array_flip(['phase_n', 'done', 'of'])));
        $this->assertSame(['now' => [], 'wait' => []], $this->phaseOf($this->phase())['release_preview'], 'a phase with nothing staged names nobody');
        $first_phase = loadMissionScenario($this->missionId)['phases'][0];
        $this->assertNull($first_phase['release_preview'], 'the first phase has nothing before it');
    }

    public function testAnOrderToEveryTeamIsSplitPerTeamWhenSomeTeamIsNotReady(): void
    {
        $one = $this->phase();
        $a1 = $this->stagePoint($one, 'A');
        $this->play($one);
        $two = $this->phase();
        $all = $this->stageArea($two, 'all');

        $result = $this->play($two);

        $parent = $this->item($all);
        $this->assertNotNull($parent['sent_at'], 'the parent is marked as gone');
        $this->assertNull($parent['sent_ref_id'], 'but has no live order: its teams have');
        $children = dbFetchAll("SELECT * FROM mission_scenario_items WHERE parent_item_id = ? ORDER BY id", [$all]);
        $this->assertCount(3, $children, 'one per team that has people');
        $byTeam = [];
        foreach ($children as $child) {
            $payload = json_decode($child['payload'], true);
            $this->assertSame('team', $payload['audience']);
            $byTeam[(int) $payload['team_id']] = $child;
        }
        $this->assertTrue($byTeam[$this->t('A')]['waiting_since'] !== null && $byTeam[$this->t('A')]['sent_at'] === null, 'A has not finished phase 1: its part waits');
        foreach (['B', 'C'] as $team) {
            $this->assertNotNull($byTeam[$this->t($team)]['sent_at'], $team . ' had nothing before: its part went at once');
            $this->assertSame($this->t($team), (int) dbFetchValue("SELECT team_id FROM mission_dispatch_points WHERE id = ?", [$byTeam[$this->t($team)]['sent_ref_id']]));
        }
        $this->assertSame(2, $result['sent']);
        $this->assertSame(1, $result['waiting']);
        $this->assertSame('expanded', $this->loaded($all)['state']);
        $this->assertSame(3, $this->phaseOf($two)['progress']['total'], 'the parent counts nowhere, its parts do');

        // A finishes phase 1: its part of the broadcast goes by itself.
        $this->finish($this->dispatchOf($a1), 'A');
        $this->assertSame($this->t('A'), (int) dbFetchValue("SELECT team_id FROM mission_dispatch_points WHERE id = ?", [(int) $this->item((int) $byTeam[$this->t('A')]['id'])['sent_ref_id']]));
    }

    public function testMessagesAndZonesAndMessagesToEveryoneGoAtOnceButAMessageToATeamWaits(): void
    {
        [, $first] = $this->threeTeamsInPhaseOne();
        $two = $this->phase();
        $toAll = $this->stageMessage($two, 'all');
        $toB = $this->stageMessage($two, 'B');
        $zone = addScenarioRestrictedItem($this->mission, $two, $this->adminId, ['label' => 'Γκρεμός', 'kind' => 'cliff', 'shape' => 'line', 'geo' => json_encode([[35.1, 25.1], [35.2, 25.2]])]);

        $this->play($two);

        $this->assertNotNull($this->item($toAll)['sent_at'], 'an announcement to everyone on duty is not a team\'s stage');
        $this->assertTrue($this->isWaiting($toB), 'a message to a team that has not finished waits with its other orders');
        $this->assertTrue($zone['ok']);
        $this->assertNotNull($this->item((int) $zone['id'])['sent_at'], 'a zone belongs to no team');
        $this->assertNotNull($first);
    }

    // ── Release: from every way of finishing ─────────────────────────────────────────────────────────────────

    public function testAPointFinishedByItsTeamReleasesThatTeamsWaitingOrdersAndNoOtherTeams(): void
    {
        [, $first] = $this->threeTeamsInPhaseOne();
        $two = $this->phase();
        $second = [];
        foreach (['A', 'B', 'C'] as $team) {
            $second[$team] = $this->stagePoint($two, $team);
        }
        $result = $this->play($two);
        $this->assertSame(3, $result['waiting']);
        $notes = (int) dbFetchValue("SELECT COUNT(*) FROM notifications");

        $this->finish($this->dispatchOf($first['B']), 'B');

        $item = $this->item($second['B']);
        $this->assertNotNull($item['sent_at']);
        $this->assertSame('auto', $item['release_mode']);
        $this->assertNull($item['waiting_since']);
        $dispatch = dbFetchOne("SELECT * FROM mission_dispatch_points WHERE id = ?", [(int) $item['sent_ref_id']]);
        $this->assertSame($this->t('B'), (int) $dispatch['team_id']);
        $this->assertSame($this->adminId, (int) $dispatch['created_by'], 'made on behalf of the coordinator who pressed play');
        $this->assertTrue($this->isWaiting($second['A']));
        $this->assertTrue($this->isWaiting($second['C']));
        $this->assertGreaterThan($notes, (int) dbFetchValue("SELECT COUNT(*) FROM notifications"), 'the team is told in the ordinary way');
        $told = array_map('intval', array_column(dbFetchAll("SELECT user_id FROM notifications WHERE data LIKE ?", ['%"dispatchId":' . $dispatch['id'] . '}%']), 'user_id'));
        $this->assertSame([$this->teams['B']['member']], $told);
        $this->assertSame(1, (int) dbFetchValue("SELECT COUNT(*) FROM audit_logs WHERE action = 'release_scenario_item' AND record_id = ?", [$second['B']]));
        $this->assertSame('auto', $this->loaded($second['B'])['release_mode']);
        $this->assertNotNull($this->loaded($second['B'])['released_label']);
    }

    public function testATeamWithTwoOrdersIsReleasedOnlyWhenTheLastOneIsFinished(): void
    {
        $one = $this->phase();
        $x = $this->stagePoint($one, 'B', 'Πρώτο');
        $y = $this->stagePoint($one, 'B', 'Δεύτερο');
        $this->play($one);
        $two = $this->phase();
        $next = $this->stagePoint($two, 'B', 'Επόμενο');
        $this->play($two);

        $this->finish($this->dispatchOf($x), 'B');
        $this->assertTrue($this->isWaiting($next), '1 of 2 is not enough');
        $this->assertSame(['done' => 1, 'of' => 2], array_intersect_key($this->loaded($next)['wait'], array_flip(['done', 'of'])));

        $this->finish($this->dispatchOf($y), 'B');
        $this->assertFalse($this->isWaiting($next));
        $this->assertNotNull($this->item($next)['sent_at']);
    }

    public function testARouteCompletedByItsTeamReleasesTheNextPhase(): void
    {
        $one = $this->phase();
        $route = $this->stageRoute($one, 'B');
        $this->play($one);
        $two = $this->phase();
        $next = $this->stagePoint($two, 'B');
        $this->play($two);
        $this->assertTrue($this->isWaiting($next));
        $routeId = $this->dispatchOf($route);
        $this->assertGreaterThan(0, $routeId);

        dbExecute("UPDATE mission_route_progress SET completed_at = NOW(), completed_by = ? WHERE waypoint_id IN (SELECT id FROM mission_route_waypoints WHERE route_id = ?)", [$this->teams['B']['member'], $routeId]);
        $this->assertTrue(maybeCompleteRoute($routeId, $this->teams['B']['member']));

        $this->assertFalse($this->isWaiting($next), 'the route was the last thing the team had to finish');
        $this->assertNotNull($this->item($next)['sent_at']);
    }

    public function testASectorCompletedByItsTeamReleasesTheNextPhaseThroughTheSameReconcile(): void
    {
        $one = $this->phase();
        $sector = $this->stageSector($one, 'B');
        $this->play($one);
        $two = $this->phase();
        $next = $this->stagePoint($two, 'B');
        $this->play($two);
        $this->assertTrue($this->isWaiting($next));

        // The endpoint's own hook is checked in the source below; here the sector is finished the way its writer does and the reconcile runs.
        dbExecute("UPDATE mission_search_sectors SET status = 'completed' WHERE id = ?", [$this->dispatchOf($sector)]);
        $released = scenarioReleaseWaiting($this->missionId);

        $this->assertCount(1, $released);
        $this->assertSame($this->t('B'), $released[0]['team_id']);
        $this->assertFalse($this->isWaiting($next));
    }

    public function testEveryPlaceThatFinishesATeamsOrderCallsTheRelease(): void
    {
        $dispatch = $this->source('includes/functions-warroom.php');
        $fn = substr($dispatch, (int) strpos($dispatch, 'function recordDispatchProgress('), 1900);
        $this->assertStringContainsString("in_array('complete', \$recorded, true)", $fn);
        $this->assertStringContainsString('scenarioAfterOrderFinished($finishedMission)', $fn);

        $route = substr($dispatch, (int) strpos($dispatch, 'function maybeCompleteRoute('), 3200);
        $this->assertStringContainsString("scenarioAfterOrderFinished((int) \$route['mission_id'])", $route);
        $this->assertGreaterThan(strpos($route, 'SET completed_at = NOW()'), strpos($route, 'scenarioAfterOrderFinished('), 'after the route is stamped');

        $sector = $this->source('mission-sector.php');
        $status = substr($sector, (int) strpos($sector, "if (\$action === 'status')"), 5200);
        $this->assertStringContainsString("if (\$targetStatus === 'completed') {\n        scenarioAfterOrderFinished((int) \$missionId);", $status);
        $this->assertGreaterThan(strpos($status, 'UPDATE mission_search_sectors SET status'), strpos($status, 'scenarioAfterOrderFinished('), 'after the status is written');

        // The only places that write a sector as completed / a route as complete / a point as completed (see the handover's grep).
        foreach (['includes/functions-warroom.php', 'mission-sector.php', 'mission-route.php', 'mission-dispatch.php', 'mobile-order-ack.php'] as $file) {
            $src = $this->source($file);
            $this->assertSame(0, preg_match("/status\\s*=\\s*'completed'\\s*,/", $src), $file . ' must not write a sector as completed behind the hook\'s back');
        }
    }

    public function testTheCardRefreshHealsACompletionNobodyHookedAndTheHookNeverBreaksACompletion(): void
    {
        [, $first] = $this->threeTeamsInPhaseOne();
        $two = $this->phase();
        $next = $this->stagePoint($two, 'C');
        $this->play($two);
        // A completion written straight into the table (a path nobody hooked): nothing releases it...
        dbExecute("INSERT INTO mission_dispatch_progress (dispatch_id, team_id, scope_key, completed_at, completed_by) VALUES (?, ?, ?, NOW(), ?)", [$this->dispatchOf($first['C']), $this->t('C'), 't' . $this->t('C'), $this->teams['C']['member']]);
        $this->assertTrue($this->isWaiting($next));

        // ...until the card refreshes: the endpoint's GET runs the same reconcile first.
        $endpoint = $this->source('mission-scenario.php');
        $get = substr($endpoint, (int) strpos($endpoint, 'if (!isPost()) {'), 1800);
        $this->assertNotFalse(strpos($get, 'scenarioAfterOrderFinished($missionId)'), 'the card refresh runs the reconcile');
        $this->assertLessThan(strpos($get, 'loadMissionScenario('), strpos($get, 'scenarioAfterOrderFinished($missionId)'), 'the reconcile runs before the plan is read');
        $this->assertCount(1, scenarioAfterOrderFinished($this->missionId));
        $this->assertFalse($this->isWaiting($next));

        // The hook swallows everything: a mission that is gone is nothing to fail about.
        $this->assertSame([], scenarioAfterOrderFinished(999999999));

        // And the reconcile reads this mission's waiting orders only, in both of its queries.
        $functions = $this->source('includes/functions-scenario.php');
        $release = substr($functions, (int) strpos($functions, 'function scenarioReleaseWaiting('), 2600);
        $this->assertSame(2, substr_count($release, 'mission_id = ? AND'), 'the guard and the list are both limited to the mission');
        $this->assertStringContainsString('WHERE i.mission_id = ? AND i.waiting_since IS NOT NULL', $release);
    }

    // ── One send, never two ──────────────────────────────────────────────────────────────────────────────────

    public function testAWaitingOrderIsSentOnceHoweverManyThingsTryToRelease(): void
    {
        [, $first] = $this->threeTeamsInPhaseOne();
        $two = $this->phase();
        $next = $this->stagePoint($two, 'B');
        $this->play($two);
        dbExecute("INSERT INTO mission_dispatch_progress (dispatch_id, team_id, scope_key, completed_at, completed_by) VALUES (?, ?, ?, NOW(), ?)", [$this->dispatchOf($first['B']), $this->t('B'), 't' . $this->t('B'), $this->teams['B']['member']]);
        $row = ['id' => $next, 'kind' => 'dispatch', 'payload' => $this->item($next)['payload']];
        $live = $this->liveCount();

        $first_try = scenarioReleaseItem($this->mission, $row, 'auto', null, $this->adminId, 'x');
        $second_try = scenarioReleaseItem($this->mission, $row, 'auto', null, $this->adminId, 'x');

        $this->assertNotNull($first_try);
        $this->assertNull($second_try, 'the claim is one conditional UPDATE: the second caller gets nothing');
        $this->assertSame([], scenarioReleaseWaiting($this->missionId));
        $this->assertSame($live + 1, $this->liveCount());
    }

    public function testAnotherMissionsTeamIsNeverReleasedByThisMission(): void
    {
        [, $first] = $this->threeTeamsInPhaseOne();
        $two = $this->phase();
        $next = $this->stagePoint($two, 'B');
        $this->play($two);
        $this->finish($this->dispatchOf($first['A']), 'A');
        // The other mission is running too, and B is ready as far as it can tell (it has no orders there): its reconcile must still not
        // touch this mission's waiting order.
        dbExecute("UPDATE missions SET status = ? WHERE id = ?", [STATUS_OPEN, $this->otherMissionId]);
        $this->assertSame([], scenarioReleaseWaiting($this->otherMissionId));
        $this->assertTrue($this->isWaiting($next), 'A finishing says nothing about B');
        $this->assertSame(0, (int) dbFetchValue("SELECT COUNT(*) FROM mission_dispatch_points WHERE mission_id = ?", [$this->otherMissionId]));
    }

    public function testACancelledRouteNeverBlocksItsTeam(): void
    {
        $one = $this->phase();
        $route = $this->stageRoute($one, 'B');
        $this->play($one);
        $this->assertNull(cancelMissionRoute($this->mission, $this->adminId, $this->dispatchOf($route), 'x'));
        $two = $this->phase();
        $next = $this->stagePoint($two, 'B');

        $this->play($two);

        $this->assertNotNull($this->item($next)['sent_at'], 'a route that was called off is not something the team still has to finish');
    }

    // ── What blocks and what never does ──────────────────────────────────────────────────────────────────────

    public function testAnOrderThatWasWithdrawnOrNeverSentOrFailedNeverBlocksAndANoICantStillDoes(): void
    {
        $one = $this->phase();
        $b = $this->stagePoint($one, 'B');
        $c = $this->stagePoint($one, 'C');
        $a = $this->stagePoint($one, 'A');
        $this->play($one);
        // B's order is withdrawn by hand; C said «Δεν μπορώ» (still open); A is untouched (open).
        withdrawMissionDispatches($this->mission, [$this->dispatchOf($b)], $this->adminId);
        dbExecute(
            "INSERT INTO mission_order_declines (mission_id, target_kind, target_id, scope_key, team_id, reason, declined_by, declined_at, active) VALUES (?, 'dispatch', ?, ?, ?, 'busy', ?, NOW(), 1)",
            [$this->missionId, $this->dispatchOf($c), 't' . $this->t('C'), $this->t('C'), $this->teams['C']['member']]
        );
        forgetActiveOrderDeclines();
        $two = $this->phase();
        $items = [];
        foreach (['A', 'B', 'C'] as $team) {
            $items[$team] = $this->stagePoint($two, $team);
        }

        $this->play($two);

        $this->assertNotNull($this->item($items['B'])['sent_at'], 'a withdrawn order never blocks');
        $this->assertTrue($this->isWaiting($items['A']));
        $this->assertTrue($this->isWaiting($items['C']), '«Δεν μπορώ» does not finish anything: the team still blocks');
        $this->assertTrue($this->loaded($items['C'])['wait']['cant'], 'and command is shown that the team is stuck');
        $this->assertFalse($this->loaded($items['A'])['wait']['cant']);
        $this->assertSame([], scenarioReleaseWaiting($this->missionId), 'the reconcile does not release a team that said it cannot');
        $this->assertNotNull($a);
    }

    public function testAnEarlierOrderThatFailedToSendNeverBlocks(): void
    {
        $one = $this->phase();
        $failing = $this->stagePoint($one, 'B');
        dbExecute("UPDATE mission_scenario_items SET payload = ? WHERE id = ?", [json_encode(['audience' => 'team', 'team_id' => 999999999, 'type' => 'point', 'geo' => ['lat' => 35.1, 'lng' => 25.1], 'label' => 'x']), $failing]);
        $result = $this->play($one);
        $this->assertSame(1, $result['failed']);
        $two = $this->phase();
        $next = $this->stagePoint($two, 'B');

        $this->play($two);

        $this->assertNotNull($this->item($next)['sent_at'], 'nothing of B\'s was sent in phase 1, so nothing holds it back');
    }

    public function testAnOrderReopenedAfterTheReleaseDoesNotTakeTheReleasedOrderBack(): void
    {
        [, $first] = $this->threeTeamsInPhaseOne();
        $two = $this->phase();
        $next = $this->stagePoint($two, 'B');
        $this->play($two);
        $this->finish($this->dispatchOf($first['B']), 'B');
        $sent = $this->item($next)['sent_ref_id'];
        $this->assertNotNull($sent);

        dbExecute("UPDATE mission_dispatch_progress SET completed_at = NULL, completed_by = NULL WHERE dispatch_id = ?", [$this->dispatchOf($first['B'])]);
        scenarioReleaseWaiting($this->missionId);

        $this->assertSame($sent, $this->item($next)['sent_ref_id'], 'a team already released stays released');
    }

    public function testThreePhasesChainOneAfterTheOtherForTheSameTeam(): void
    {
        $one = $this->phase();
        $a = $this->stagePoint($one, 'A');
        $this->play($one);
        $two = $this->phase();
        $b = $this->stagePoint($two, 'A', 'Δεύτερο');
        $this->play($two);
        $three = $this->phase();
        $c = $this->stagePoint($three, 'A', 'Τρίτο');
        $this->play($three);
        $this->assertTrue($this->isWaiting($b));
        $this->assertTrue($this->isWaiting($c), 'phase 3 waits for phase 2 as well');

        $this->finish($this->dispatchOf($a), 'A');
        $this->assertNotNull($this->item($b)['sent_at'], 'phase 2 goes when phase 1 is finished');
        $this->assertTrue($this->isWaiting($c), 'the order just released is itself an open order: phase 3 keeps waiting');

        $this->finish($this->dispatchOf($b), 'A');
        $this->assertNotNull($this->item($c)['sent_at']);
    }

    // ── Never widened ────────────────────────────────────────────────────────────────────────────────────────

    public function testASlotThatStandsForNoTeamAtReleaseFailsTheOrderAndNeverSendsItToEveryone(): void
    {
        [, $first] = $this->threeTeamsInPhaseOne();
        $slot = addScenarioSlot($this->mission, $this->adminId, 'Ομάδα Χ');
        $this->assertTrue($slot['ok']);
        $this->assertNull(mapScenarioSlot($this->mission, $slot['id'], $this->t('B'), $this->adminId));
        $two = $this->phase();
        $r = addScenarioDispatchItem($this->mission, $two, $this->adminId, ['team_id' => 'slot:' . $slot['id']] + $this->point('B'));
        $this->assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        $this->play($two);
        $this->assertTrue($this->isWaiting((int) $r['id']));
        $live = $this->liveCount();

        // Command unmaps the slot while the order waits; then B finishes.
        $this->assertNull(mapScenarioSlot($this->mission, $slot['id'], null, $this->adminId));
        $this->finish($this->dispatchOf($first['B']), 'B');
        scenarioReleaseWaiting($this->missionId);

        $item = $this->item((int) $r['id']);
        $this->assertNull($item['sent_at']);
        $this->assertNotSame('', (string) $item['last_error'], 'it failed, with its reason');
        $this->assertSame($live, $this->liveCount(), 'nothing was sent to anybody');
    }

    public function testAnOrderToASlotWaitsForTheTeamTheSlotStandsForAndIsNotReleasedEarly(): void
    {
        [, $first] = $this->threeTeamsInPhaseOne();
        $slot = addScenarioSlot($this->mission, $this->adminId, 'Ομάδα Ψ');
        $this->assertNull(mapScenarioSlot($this->mission, $slot['id'], $this->t('B'), $this->adminId));
        $two = $this->phase();
        $r = addScenarioDispatchItem($this->mission, $two, $this->adminId, ['team_id' => 'slot:' . $slot['id']] + $this->point('B'));
        $this->assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        $this->play($two);
        $this->assertTrue($this->isWaiting((int) $r['id']), 'the slot stands for B, which has not finished');
        $this->assertSame($this->t('B'), $this->loaded((int) $r['id'])['wait_team_id']);

        $this->assertSame([], scenarioReleaseWaiting($this->missionId), 'a reconcile before B has finished sends nothing');
        $this->assertTrue($this->isWaiting((int) $r['id']));
        $this->finish($this->dispatchOf($first['B']), 'B');

        $this->assertFalse($this->isWaiting((int) $r['id']));
        $this->assertSame($this->t('B'), (int) dbFetchValue("SELECT team_id FROM mission_dispatch_points WHERE id = ?", [$this->dispatchOf((int) $r['id'])]));
    }

    public function testAClosedMissionReleasesNothing(): void
    {
        [, $first] = $this->threeTeamsInPhaseOne();
        $two = $this->phase();
        $next = $this->stagePoint($two, 'B');
        $this->play($two);
        dbExecute("UPDATE missions SET status = ? WHERE id = ?", [STATUS_CLOSED, $this->missionId]);
        dbExecute("INSERT INTO mission_dispatch_progress (dispatch_id, team_id, scope_key, completed_at, completed_by) VALUES (?, ?, ?, NOW(), ?)", [$this->dispatchOf($first['B']), $this->t('B'), 't' . $this->t('B'), $this->teams['B']['member']]);

        $this->assertSame([], scenarioReleaseWaiting($this->missionId));
        $this->assertTrue($this->isWaiting($next));
    }

    // ── A building follows its sector ────────────────────────────────────────────────────────────────────────

    public function testABuildingWaitsWithItsSectorAndIsMadeRightAfterIt(): void
    {
        $one = $this->phase();
        $b1 = $this->stagePoint($one, 'B');
        $this->play($one);
        $two = $this->phase();
        $sector = $this->stageSector($two, 'B');
        $building = addScenarioBuildingItem($this->mission, $two, $this->adminId, ['sector_id' => 'item:' . $sector, 'label' => 'Κτίριο 1', 'lat' => 35.3005, 'lng' => 25.1005, 'floor_count' => 2]);
        $this->assertTrue($building['ok'], (string) ($building['error'] ?? ''));

        $this->play($two);
        $this->assertTrue($this->isWaiting($sector));
        $this->assertTrue($this->isWaiting((int) $building['id']), 'the building waits with its sector');
        $this->assertSame($this->t('B'), $this->loaded((int) $building['id'])['wait_team_id']);

        $this->finish($this->dispatchOf($b1), 'B');

        $sectorRow = $this->item($sector);
        $this->assertNotNull($sectorRow['sent_ref_id']);
        $buildingRow = $this->item((int) $building['id']);
        $this->assertNotNull($buildingRow['sent_ref_id'], 'made right after the sector, in the same release');
        $this->assertSame((int) $sectorRow['sent_ref_id'], (int) dbFetchValue("SELECT sector_id FROM mission_sector_buildings WHERE id = ?", [$buildingRow['sent_ref_id']]));
        $this->assertSame(3, (int) dbFetchValue("SELECT COUNT(*) FROM mission_sector_building_floors WHERE building_id = ?", [$buildingRow['sent_ref_id']]), 'ground floor + 2');
    }

    // ── The two overrides, and taking the phase back ─────────────────────────────────────────────────────────

    public function testSendNowSendsOneTeamsWaitingOrdersAndCancelWaitKeepsThemInThePlan(): void
    {
        [, $first] = $this->threeTeamsInPhaseOne();
        $two = $this->phase();
        $b = $this->stagePoint($two, 'B');
        $c = $this->stagePoint($two, 'C');
        $a = $this->stagePoint($two, 'A');
        $this->play($two);
        $live = $this->liveCount();

        $now = sendScenarioWaitingNow($this->mission, $two, $this->t('B'), $this->adminId, 'Release Admin');
        $this->assertTrue($now['ok']);
        $this->assertSame(1, $now['sent']);
        $this->assertSame('manual', $this->item($b)['release_mode']);
        $this->assertSame($this->adminId, (int) $this->item($b)['released_by']);
        $this->assertSame($live + 1, $this->liveCount());
        $this->assertTrue($this->isWaiting($c), 'the others still wait');
        $this->assertFalse(sendScenarioWaitingNow($this->mission, $two, $this->t('B'), $this->adminId, 'x')['ok'], 'nothing is left to send for B');

        $cancelled = cancelScenarioWait($this->mission, $two, $this->t('C'), $this->adminId);
        $this->assertSame(['ok' => true, 'cancelled' => 1], $cancelled);
        $this->assertFalse($this->isWaiting($c));
        $this->assertSame('cancelled', $this->loaded($c)['state']);
        $this->assertTrue($this->phaseOf($two)['waits'][0]['cancelled'] ?? false);
        $this->finish($this->dispatchOf($first['C']), 'C');
        $this->assertNull($this->item($c)['sent_at'], 'a cancelled wait is never released by the team finishing');
        $this->assertSame($live + 1, $this->liveCount());

        // Command can still send it by hand.
        $late = sendScenarioWaitingNow($this->mission, $two, $this->t('C'), $this->adminId, 'Release Admin');
        $this->assertSame(1, $late['sent']);
        $this->assertNotNull($this->item($c)['sent_ref_id']);
        $this->assertNull($this->item($c)['wait_cancelled_at']);
        $this->assertFalse(cancelScenarioWait($this->mission, $two, $this->t('A'), $this->adminId)['ok'] === false, 'A still waits and can be cancelled');
        $this->assertTrue($this->isWaiting($a) || $this->item($a)['wait_cancelled_at'] !== null);
    }

    public function testTakingAPhaseBackCancelsItsWaitingOrdersAndWorksWhenOnlyWaitingOnesRemain(): void
    {
        [, $first] = $this->threeTeamsInPhaseOne();
        $two = $this->phase();
        $b = $this->stagePoint($two, 'B');
        $this->play($two);
        $this->assertTrue($this->isWaiting($b));

        $result = withdrawScenarioPhase($this->mission, $two, $this->adminId);

        $this->assertTrue($result['ok'], (string) ($result['error'] ?? ''));
        $this->assertSame(1, $result['cancelled']);
        $this->assertSame('cancelled', $this->loaded($b)['state']);
        $this->finish($this->dispatchOf($first['B']), 'B');
        $this->assertNull($this->item($b)['sent_at'], 'the team finishing later sends nothing for a phase that was taken back');
        $this->assertSame(1, $this->phaseOf($two)['progress']['withdrawn']);
        $this->assertFalse(withdrawScenarioPhase($this->mission, $two, $this->adminId)['ok'], 'nothing left to take back');
    }

    public function testTakingBackTheEarlierPhaseReleasesTheTeamsThatOnlyWaitedForIt(): void
    {
        [$one, $first] = $this->threeTeamsInPhaseOne();
        $two = $this->phase();
        $next = $this->stagePoint($two, 'B');
        $this->play($two);

        withdrawScenarioPhase($this->mission, $one, $this->adminId);
        scenarioReleaseWaiting($this->missionId);

        $this->assertNotNull($this->item($next)['sent_at'], 'by the agreed rule a withdrawn order never blocks: the card warns about it beforehand');
        $this->assertNotNull($first);
    }

    // ── The page ─────────────────────────────────────────────────────────────────────────────────────────────

    public function testTheEndpointOffersSendNowAndCancelWaitAndKeepsNothingWaitingOutOfTheLiveLists(): void
    {
        $endpoint = $this->source('mission-scenario.php');
        $this->assertStringContainsString("\$action === 'release_now'", $endpoint);
        $this->assertStringContainsString('sendScenarioWaitingNow($mission, (int) post(\'phase_id\')', $endpoint);
        $this->assertStringContainsString("\$action === 'cancel_wait'", $endpoint);
        $this->assertStringContainsString('cancelScenarioWait($mission, (int) post(\'phase_id\')', $endpoint);
        $this->assertSame(2, substr_count($endpoint, "&& empty(\$r['waiting'])"), 'an order that only waits has made nothing live');
        $this->assertStringContainsString("'waiting'    => \$result['waiting']", $endpoint);
    }

    public function testTheCardShowsWhoWaitsAndKeepsRefreshingWhileAnythingWaits(): void
    {
        $js = $this->source('assets/js/war-room-scenario.js');
        $this->assertStringContainsString('p.progress.open > 0 || p.progress.waiting > 0', $js);
        $this->assertStringContainsString('data-sc="release-now"', $js);
        $this->assertStringContainsString('data-sc="cancel-wait"', $js);
        $this->assertStringContainsString("run('release_now'", $js);
        $this->assertStringContainsString("run('cancel_wait'", $js);
        $this->assertStringContainsString('scenario.play_confirm_wait', $js);
        $this->assertStringContainsString('scenario.withdraw_confirm_later', $js);
        $this->assertStringContainsString('showReleased(res)', $js);
        $note = (string) (require __DIR__ . '/../includes/lang/war-room.php')['el']['scenario.note'] ?? '';
        $this->assertNotSame('', $note);
    }

    public function testBootstrapLoadsTheScenarioFunctionsSoTheHooksAlwaysFindThem(): void
    {
        $bootstrap = $this->source('bootstrap.php');
        $this->assertStringContainsString("require_once __DIR__ . '/includes/functions-scenario.php';", $bootstrap);
        $this->assertSame(1, preg_match('/functions-warroom\.php.*functions-scenario\.php/s', $bootstrap) + preg_match('/functions-scenario\.php.*functions-warroom\.php/s', $bootstrap), 'both are loaded for every request');
    }
}
