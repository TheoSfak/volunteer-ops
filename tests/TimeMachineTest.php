<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The time machine of «Πορεία Ομάδων» (v3.441.0): while the replay scrubber is back in the past, the orders on
 * the map stand as they did at that moment (assets/js/war-room-time-machine.js).
 *
 * Nothing is fetched for it: every order already carries the moments of its own history in the page's payload,
 * so the first block checks the server says them (as unix times, constant like every other field of the hashed
 * 5-second poll), the second runs the browser's own working-out in node against an invented mission (skipped
 * where node is not installed), and the rest pins how the page is wired. Writes run inside a transaction that is
 * always rolled back.
 */
final class TimeMachineTest extends TestCase
{
    private int $missionId;
    private int $adminId;
    private int $teamA;

    protected function setUp(): void
    {
        db()->beginTransaction();
        forgetActiveOrderDeclines();

        $this->adminId = (int) dbInsert(
            "INSERT INTO users (name, email, password) VALUES (?, ?, ?)",
            ['Time Admin', 'time-' . uniqid('', true) . '@example.invalid', 'x']
        );
        $missionTypeId = (int) dbFetchValue("SELECT id FROM mission_types ORDER BY id LIMIT 1");
        $this->missionId = (int) dbInsert(
            "INSERT INTO missions (title, location, start_datetime, end_datetime, mission_type_id, status, show_in_ops, responsible_user_id)
             VALUES (?, ?, ?, ?, ?, ?, 1, ?)",
            ['Time Mission', 'Ηράκλειο', date('Y-m-d H:i:s', time() - 7200), date('Y-m-d H:i:s', time() + 7200), $missionTypeId, STATUS_OPEN, $this->adminId]
        );
        $this->teamA = (int) dbInsert(
            "INSERT INTO mission_teams (mission_id, codename, team_number, color, created_by) VALUES (?, ?, ?, ?, ?)",
            [$this->missionId, 'ΑΛΦΑ', 1, '#2a78d6', $this->adminId]
        );
    }

    protected function tearDown(): void
    {
        db()->rollBack();
        forgetActiveOrderDeclines();
    }

    private function root(): string
    {
        return dirname(__DIR__);
    }

    private function source(string $relative): string
    {
        $source = file_get_contents($this->root() . '/' . $relative);
        $this->assertNotFalse($source, $relative . ' is missing');
        return $source;
    }

    // ── What the server says ────────────────────────────────────────────────

    public function testADispatchCarriesWhenItWasSentAndEachStepOfEachTeamAsUnixTimes(): void
    {
        $sent = date('Y-m-d H:i:s', time() - 5400);
        $departed = date('Y-m-d H:i:s', time() - 5000);
        $arrived = date('Y-m-d H:i:s', time() - 4000);
        $completed = date('Y-m-d H:i:s', time() - 3000);
        $id = (int) dbInsert(
            "INSERT INTO mission_dispatch_points (mission_id, team_id, type, geo, label, created_by, created_at) VALUES (?, ?, 'point', ?, ?, ?, ?)",
            [$this->missionId, $this->teamA, json_encode(['lat' => 35.2, 'lng' => 24.9]), 'Γέφυρα', $this->adminId, $sent]
        );
        dbInsert(
            "INSERT INTO mission_dispatch_progress (dispatch_id, team_id, scope_key, departed_at, arrived_at, completed_at, completed_by) VALUES (?, ?, ?, ?, ?, ?, ?)",
            [$id, $this->teamA, 't' . $this->teamA, $departed, $arrived, $completed, $this->adminId]
        );

        $item = null;
        foreach (loadMissionDispatchesForUser($this->missionId, $this->adminId, true, false) as $row) {
            if ($row['id'] === $id) {
                $item = $row;
            }
        }
        $this->assertNotNull($item);
        $this->assertSame(strtotime($sent), $item['sent_ts']);
        $this->assertCount(1, $item['progress']);
        $line = $item['progress'][0];
        $this->assertSame('t' . $this->teamA, $line['scope'], 'which team a progress line belongs to, so the page can tell the order\'s own team from another');
        $this->assertSame(strtotime($departed), $line['departed_ts']);
        $this->assertSame(strtotime($arrived), $line['arrived_ts']);
        $this->assertSame(strtotime($completed), $line['completed_ts']);
        $this->assertSame(strtotime($completed), $item['completed_ts'], 'the order\'s own finish is the same moment, as before');
    }

    public function testASectorCarriesWhenItWasMadeAndWhenEachStatusChangeHappened(): void
    {
        $made = date('Y-m-d H:i:s', time() - 7000);
        $changed = date('Y-m-d H:i:s', time() - 3000);
        $areaId = (int) dbInsert(
            "INSERT INTO mission_search_areas (mission_id, label, geo, created_by) VALUES (?, ?, ?, ?)",
            [$this->missionId, 'Ζώνη', json_encode([[35.1, 25.1], [35.2, 25.1], [35.2, 25.2]]), $this->adminId]
        );
        $sectorId = (int) dbInsert(
            "INSERT INTO mission_search_sectors (mission_id, area_id, team_id, label, geo, status, status_updated_at, created_by, created_at)
             VALUES (?, ?, ?, ?, ?, 'in_progress', ?, ?, ?)",
            [$this->missionId, $areaId, $this->teamA, 'Τομέας Α', json_encode([[35.30, 25.10], [35.30, 25.12], [35.32, 25.12]]), $changed, $this->adminId, $made]
        );
        dbInsert(
            "INSERT INTO mission_sector_status_log (sector_id, from_status, to_status, team_id, user_id, created_at) VALUES (?, 'assigned', 'in_progress', ?, ?, ?)",
            [$sectorId, $this->teamA, $this->adminId, $changed]
        );

        $item = null;
        foreach (loadMissionSectorsForUser($this->missionId, $this->adminId, true, false) as $row) {
            if ($row['id'] === $sectorId) {
                $item = $row;
            }
        }
        $this->assertNotNull($item);
        $this->assertSame(strtotime($made), $item['created_ts']);
        $this->assertCount(1, $item['status_log']);
        $this->assertSame(strtotime($changed), $item['status_log'][0]['ts']);
        $this->assertSame('in_progress', $item['status_log'][0]['to_status']);
        $this->assertSame('assigned', $item['status_log'][0]['from_status'], 'the status BEFORE the first change is how the page knows what a sector was earlier');
    }

    public function testARouteCarriesWhenItWasGivenAndWhenItWasCalledOff(): void
    {
        $made = date('Y-m-d H:i:s', time() - 6000);
        $cancelled = date('Y-m-d H:i:s', time() - 2000);
        $open = (int) dbInsert(
            "INSERT INTO mission_routes (mission_id, team_id, title, created_by, created_at) VALUES (?, ?, ?, ?, ?)",
            [$this->missionId, $this->teamA, 'Ανοιχτή', $this->adminId, $made]
        );
        $off = (int) dbInsert(
            "INSERT INTO mission_routes (mission_id, team_id, title, created_by, created_at, cancelled_at) VALUES (?, ?, ?, ?, ?, ?)",
            [$this->missionId, $this->teamA, 'Ακυρωμένη', $this->adminId, $made, $cancelled]
        );

        $byId = [];
        foreach (loadRoutesForUser($this->missionId, $this->adminId, true) as $row) {
            $byId[$row['id']] = $row;
        }
        $this->assertSame(strtotime($made), $byId[$open]['created_ts']);
        $this->assertNull($byId[$open]['cancelled_ts']);
        $this->assertSame(strtotime($cancelled), $byId[$off]['cancelled_ts']);
        $this->assertSame('cancelled', $byId[$off]['status']);
    }

    // ── What the browser works out ──────────────────────────────────────────

    /** @return array<string, mixed> */
    private function harness(): array
    {
        $node = trim((string) @shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where node 2>NUL' : 'command -v node 2>/dev/null'));
        if ($node === '') {
            $this->markTestSkipped('node is not installed');
        }
        $output = (string) shell_exec('node ' . escapeshellarg($this->root() . '/tests/fixtures/time-machine-harness.js') . ' ' . escapeshellarg($this->root()) . ' 2>&1');
        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, 'the harness did not print JSON: ' . $output);
        return $decoded;
    }

    public function testThePresentIsTheVeryListsAndAMomentNeverTouchesThem(): void
    {
        $out = $this->harness();
        $this->assertTrue($out['presentIsSameList'], 'with the machine off the render functions get exactly the list they always did');
        $this->assertSame([true, 130, 'completed', 'completed'], $out['liveUntouched'], 'looking at the past works on copies');
        $this->assertTrue($out['afterNull']);
        $this->assertTrue($out['nowIsWallClock'], 'back in the present the clock is the real one again');
    }

    public function testAnOrderThatDidNotExistYetIsNotThere(): void
    {
        $out = $this->harness();
        $this->assertSame([], $out['t50']['dispatches']);
        $this->assertSame([21, 22], array_column($out['t50']['routes'], 0));
        $routes = array_column($out['routes50'], 1, 0);
        $this->assertSame('active', $routes[22], 'a route called off at 60 was a live one at 50');
    }

    public function testADispatchShowsOnlyTheStepsDoneByThatMoment(): void
    {
        $out = $this->harness();

        $beforeLeaving = $out['t105'];
        $this->assertSame([], $beforeLeaving['progress']);
        $this->assertNull($beforeLeaving['completed_ts']);

        $enRoute = $out['t125'];
        $this->assertCount(1, $enRoute['progress']);
        $this->assertSame(110, $enRoute['progress'][0]['departed_ts']);
        $this->assertSame(120, $enRoute['progress'][0]['arrived_ts']);
        $this->assertNull($enRoute['progress'][0]['completed_ts']);
        $this->assertNull($enRoute['completed_ts'], 'not finished yet at 125: still a live order');
        $this->assertCount(1, $enRoute['declines'], 'a «Δεν μπορώ» of 140 is not known at 125, one of 112 is');

        $done = $out['t135']['one'];
        $this->assertSame(130, $done['completed_ts']);
        $this->assertSame('done here', $done['progress'][0]['note']);
    }

    public function testAnOrderSentToEveryTeamNeverFinishesInThePast(): void
    {
        $out = $this->harness();
        $this->assertNull($out['t135']['broadcast']['completed_ts']);
    }

    public function testThePastIsReadOnly(): void
    {
        $item = $this->harness()['t135']['one'];
        $this->assertNull($item['eta']);
        $this->assertSame([], $item['acks']);
        $this->assertNull($item['my_receipt']);
        $this->assertNull($item['my_completed']);
        $this->assertFalse($item['can_delete']);
        $this->assertFalse($item['can_complete']);
        $this->assertFalse($item['can_receive']);
        $this->assertFalse($item['can_depart']);
        $this->assertFalse($item['can_ack']);
        $this->assertFalse($item['can_decline']);
    }

    public function testASectorIsInTheStatusItHadAtThatMoment(): void
    {
        $out = $this->harness();
        $this->assertSame('assigned', $out['sector20']['status']);
        $this->assertSame('in_progress', $out['sector40']['status']);
        $this->assertSame('completed', $out['sector70']['status']);
        $this->assertSame(60, $out['sector70']['completed_ts'], 'the finish that counts is the one that had happened by then');
        $this->assertSame('00:02', $out['sector70']['status_updated_at']);
        $this->assertFalse($out['sector70']['can_manage']);
        $this->assertSame('needs_recheck', $out['sector100']['status']);
        $this->assertNull($out['sector100']['completed_ts'], 'sent back for a recheck: a live sector again');
        $this->assertSame(120, $out['sector130']['completed_ts']);
        $this->assertCount(5, $out['sector130']['status_log']);
    }

    public function testASectorBeforeItsFirstChangeIsInTheStatusThatChangeLeft(): void
    {
        $byId = array_column($this->harness()['sectorsBeforeFirstChange'], 1, 0);
        $this->assertSame('assigned', $byId[8], 'its first change (at 50) went from assigned');
        $this->assertSame('not_started', $byId[9], 'a sector that never changed is in the status it has');
    }

    public function testARouteIsActiveCompletedOrCalledOffAsOfTheMoment(): void
    {
        $out = $this->harness();
        $at70 = array_column($out['routes70'], 1, 0);
        $this->assertSame('active', $at70[21]);
        $this->assertSame('cancelled', $at70[22]);
        $at250 = array_column($out['routes250'], 1, 0);
        $this->assertSame('completed', $at250[21]);
        $this->assertArrayNotHasKey(23, $at250, 'a route given at 300 is not there at 250');
    }

    public function testAFinishedOrderFadesByTheMomentLookedAtNotByTheWallClock(): void
    {
        $out = $this->harness();
        $this->assertSame(135, $out['t135']['nowIsTheMoment']);
        $this->assertSame('fresh', $out['t135']['phaseOfOne'], '5 seconds after it was finished');
        $this->assertSame('archived', $out['phaseLater'], 'ten minutes and a second after');
    }

    // ── How the page is wired ───────────────────────────────────────────────

    public function testTheThreeRenderFunctionsGoThroughTheMachineAtTheirTop(): void
    {
        $page = $this->source('war-room.php');
        $this->assertMatchesRegularExpression('/function renderDispatches\(items\) \{\s*refreshMyOrdersCard\(\);[^}]*?items = wrTimeDispatches\(items\);/s', $page);
        $this->assertMatchesRegularExpression('/function renderSectorLayer\(items\) \{\s*if \(!sectorLayer \|\| !sectorBuildingLayer\) return;[^}]*?items = wrTimeSectors\(items\);/s', $page);
        $this->assertMatchesRegularExpression('/function renderRouteLayer\(allRoutes\) \{\s*if \(!routeLayer\) return;[^}]*?allRoutes = wrTimeRoutes\(allRoutes\);/s', $page);
    }

    public function testTheOrdersFollowEveryRenderOfTheTrailAndLeaveWithIt(): void
    {
        $page = $this->source('war-room.php');
        $this->assertMatchesRegularExpression('/renderTrailEventsUpTo\(cutoffTs\);[^}]*?wrTimeFollow\(cutoffTs\);\s*\}/s', $page, 'folded into renderTrailUpTo itself, so no call site can move the trails and leave the orders behind');
        $this->assertMatchesRegularExpression('/function exitTrailMode\(\) \{.*?wrTimeSet\(null\);/s', $page, 'closing the trail view brings the present back');
        $this->assertStringContainsString("getElementById('trailOrdersAsOf')?.addEventListener('change'", $page);
        $this->assertStringContainsString('id="trailOrdersAsOf" checked', $page, 'on by default');
        $this->assertStringContainsString('id="trailOrdersSummary"', $page);
        $this->assertStringContainsString('assets/js/war-room-time-machine.js', $page);
    }

    public function testTheFadeClockAndTheCounterReadTheMachine(): void
    {
        $done = $this->source('assets/js/war-room-done-orders.js');
        $this->assertMatchesRegularExpression('/function wrDoneNowSec\(\) \{[^}]*wrTimeNow\(\) !== null\) return wrTimeNow\(\);/s', $done);
        $this->assertStringContainsString("wrDoneView('dispatch')", $done);
        $this->assertStringContainsString("wrDoneView('sector')", $done);
        $this->assertStringContainsString("wrDoneView('route')", $done);
    }

    public function testNothingIsFetchedForIt(): void
    {
        $this->assertStringNotContainsString('fetch(', $this->source('assets/js/war-room-time-machine.js'));
    }

    public function testEveryStringTheMachineAsksForIsTranslated(): void
    {
        $keys = [];
        foreach (['assets/js/war-room-time-machine.js', 'war-room.php'] as $file) {
            preg_match_all("/t\\('(timemachine\\.[a-z_]+)'/", $this->source($file), $m);
            $keys = array_merge($keys, $m[1]);
        }
        $keys = array_values(array_unique($keys));
        $this->assertNotEmpty($keys);
        $lang = $this->source('includes/lang/war-room.php');
        foreach ($keys as $key) {
            $this->assertSame(2, substr_count($lang, "'" . $key . "' =>"), $key . ' must be in the Greek AND the English strings');
        }
    }
}
