<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * What v3.443.0 added around finished orders on the Action Room map:
 *
 *   · «Ξανάνοιγμα»: command takes back a finish pressed by mistake or too soon - on a dispatch point/area
 *     (one team's finish at a time) and on a route (reopenMissionDispatch(), reopenMissionRoute());
 *   · a finished point whose team wrote something on finishing stays brighter than the quiet tick;
 *   · hovering a row of the finished-orders list lights that order up on the map;
 *   · the people in the field see what is running: a finished point, area or route leaves their map once its
 *     ten bright minutes are over (a sector stays as an outline for everyone), with a tick to show them all.
 *
 * The first block writes inside a transaction that is always rolled back; the second runs the browser's own
 * rules in node (skipped where node is not installed); the rest reads how the page is wired.
 */
final class FinishedOrdersToolsTest extends TestCase
{
    private int $missionId;
    private int $adminId;
    private int $teamA;
    private int $memberA;
    private array $mission;

    protected function setUp(): void
    {
        db()->beginTransaction();
        forgetActiveOrderDeclines();

        $this->adminId = (int) dbInsert(
            "INSERT INTO users (name, email, password) VALUES (?, ?, ?)",
            ['Reopen Admin', 'reopen-' . uniqid('', true) . '@example.invalid', 'x']
        );
        $missionTypeId = (int) dbFetchValue("SELECT id FROM mission_types ORDER BY id LIMIT 1");
        $this->missionId = (int) dbInsert(
            "INSERT INTO missions (title, location, start_datetime, end_datetime, mission_type_id, status, show_in_ops, responsible_user_id)
             VALUES (?, ?, ?, ?, ?, ?, 1, ?)",
            ['Reopen Mission', 'Ηράκλειο', date('Y-m-d H:i:s', time() - 7200), date('Y-m-d H:i:s', time() + 7200), $missionTypeId, STATUS_OPEN, $this->adminId]
        );
        $this->mission = ['id' => $this->missionId, 'title' => 'Reopen Mission', 'responsible_user_id' => $this->adminId];
        $this->teamA = (int) dbInsert(
            "INSERT INTO mission_teams (mission_id, codename, team_number, color, created_by) VALUES (?, ?, ?, ?, ?)",
            [$this->missionId, 'ΑΛΦΑ', 1, '#2a78d6', $this->adminId]
        );
        $this->memberA = (int) dbInsert(
            "INSERT INTO users (name, email, password) VALUES (?, ?, ?)",
            ['Reopen Member', 'reopen-m-' . uniqid('', true) . '@example.invalid', 'x']
        );
        dbInsert("INSERT INTO mission_team_members (team_id, mission_id, user_id) VALUES (?, ?, ?)", [$this->teamA, $this->missionId, $this->memberA]);
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

    private function finishedDispatch(?string $note = 'Αποχώρησε περιπατητικός'): int
    {
        $id = (int) dbInsert(
            "INSERT INTO mission_dispatch_points (mission_id, team_id, type, geo, label, created_by) VALUES (?, ?, 'point', ?, ?, ?)",
            [$this->missionId, $this->teamA, json_encode(['lat' => 35.2, 'lng' => 24.9]), 'Γέφυρα', $this->adminId]
        );
        dbInsert(
            "INSERT INTO mission_dispatch_progress (dispatch_id, team_id, scope_key, departed_at, arrived_at, completed_at, completed_by, completed_note)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            [$id, $this->teamA, 't' . $this->teamA, date('Y-m-d H:i:s', time() - 900), date('Y-m-d H:i:s', time() - 600), date('Y-m-d H:i:s', time() - 300), $this->adminId, $note]
        );
        return $id;
    }

    // ── «Ξανάνοιγμα» of a dispatch ──────────────────────────────────────────

    public function testReopeningADispatchTakesBackTheFinishAndTheNoteButNotTheDepartureOrTheArrival(): void
    {
        $id = $this->finishedDispatch();

        $this->assertNull(reopenMissionDispatch($this->mission, $id, 't' . $this->teamA, $this->adminId));

        $line = dbFetchOne("SELECT departed_at, arrived_at, completed_at, completed_by, completed_note FROM mission_dispatch_progress WHERE dispatch_id = ?", [$id]);
        $this->assertNull($line['completed_at']);
        $this->assertNull($line['completed_by']);
        $this->assertNull($line['completed_note']);
        $this->assertNotNull($line['departed_at'], 'the team did leave');
        $this->assertNotNull($line['arrived_at'], 'and did arrive');

        foreach (loadMissionDispatchesForUser($this->missionId, $this->adminId, true, false) as $item) {
            if ($item['id'] === $id) {
                $this->assertNull($item['completed_ts'], 'a live order again, which the map stops fading');
            }
        }
    }

    public function testTheTeamIsToldAndTheCommanderWhoDidItIsNot(): void
    {
        $id = $this->finishedDispatch();
        $before = (int) dbFetchValue("SELECT COUNT(*) FROM notifications WHERE user_id = ?", [$this->memberA]);
        $adminBefore = (int) dbFetchValue("SELECT COUNT(*) FROM notifications WHERE user_id = ?", [$this->adminId]);

        reopenMissionDispatch($this->mission, $id, 't' . $this->teamA, $this->adminId);

        $this->assertSame($before + 1, (int) dbFetchValue("SELECT COUNT(*) FROM notifications WHERE user_id = ?", [$this->memberA]));
        $this->assertSame($adminBefore, (int) dbFetchValue("SELECT COUNT(*) FROM notifications WHERE user_id = ?", [$this->adminId]));
    }

    public function testOnlyAFinishedLineOfThisMissionCanBeReopened(): void
    {
        $id = $this->finishedDispatch();
        $open = (int) dbInsert(
            "INSERT INTO mission_dispatch_points (mission_id, team_id, type, geo, label, created_by) VALUES (?, ?, 'point', ?, ?, ?)",
            [$this->missionId, $this->teamA, json_encode(['lat' => 35.3, 'lng' => 24.9]), 'Ανοιχτό', $this->adminId]
        );
        $this->assertNotNull(reopenMissionDispatch($this->mission, $open, 't' . $this->teamA, $this->adminId), 'never finished');
        $this->assertNotNull(reopenMissionDispatch($this->mission, $id, 't999999', $this->adminId), 'no such team line');
        $this->assertNotNull(reopenMissionDispatch($this->mission, $id, "t1' OR '1'='1", $this->adminId), 'a scope is a letter and digits, nothing else');
        $this->assertNotNull(reopenMissionDispatch(['id' => 1] + $this->mission, $id, 't' . $this->teamA, $this->adminId), 'another mission\'s order');
        // Reopening twice is not an error that undoes anything.
        $this->assertNull(reopenMissionDispatch($this->mission, $id, 't' . $this->teamA, $this->adminId));
        $this->assertNotNull(reopenMissionDispatch($this->mission, $id, 't' . $this->teamA, $this->adminId), 'already reopened');
    }

    public function testReopeningOneTeamsFinishLeavesTheOthersOnAnOrderToEveryTeam(): void
    {
        $teamB = (int) dbInsert(
            "INSERT INTO mission_teams (mission_id, codename, team_number, color, created_by) VALUES (?, ?, ?, ?, ?)",
            [$this->missionId, 'ΒΗΤΑ', 2, '#2a78d6', $this->adminId]
        );
        $id = (int) dbInsert(
            "INSERT INTO mission_dispatch_points (mission_id, team_id, type, geo, label, created_by) VALUES (?, NULL, 'point', ?, ?, ?)",
            [$this->missionId, json_encode(['lat' => 35.2, 'lng' => 24.9]), 'Για όλους', $this->adminId]
        );
        foreach ([$this->teamA, $teamB] as $team) {
            dbInsert("INSERT INTO mission_dispatch_progress (dispatch_id, team_id, scope_key, completed_at, completed_by) VALUES (?, ?, ?, ?, ?)",
                [$id, $team, 't' . $team, date('Y-m-d H:i:s', time() - 300), $this->adminId]);
        }
        reopenMissionDispatch($this->mission, $id, 't' . $this->teamA, $this->adminId);

        $this->assertNull(dbFetchValue("SELECT completed_at FROM mission_dispatch_progress WHERE dispatch_id = ? AND scope_key = ?", [$id, 't' . $this->teamA]));
        $this->assertNotNull(dbFetchValue("SELECT completed_at FROM mission_dispatch_progress WHERE dispatch_id = ? AND scope_key = ?", [$id, 't' . $teamB]));
    }

    // ── «Ξανάνοιγμα διαδρομής» ──────────────────────────────────────────────

    private function finishedRoute(): array
    {
        $orderId = (int) dbInsert(
            "INSERT INTO mission_orders (mission_id, order_type, task_text, created_by) VALUES (?, 'route', ?, ?)",
            [$this->missionId, 'Πορεία', $this->adminId]
        );
        dbInsert("INSERT INTO mission_order_recipients (order_id, user_id, fulfilled_at) VALUES (?, ?, ?)", [$orderId, $this->memberA, date('Y-m-d H:i:s', time() - 100)]);
        $routeId = (int) dbInsert(
            "INSERT INTO mission_routes (mission_id, team_id, order_id, title, created_by, completed_at) VALUES (?, ?, ?, ?, ?, ?)",
            [$this->missionId, $this->teamA, $orderId, 'Περίπολος', $this->adminId, date('Y-m-d H:i:s', time() - 100)]
        );
        dbInsert("INSERT INTO mission_route_members (route_id, user_id) VALUES (?, ?)", [$routeId, $this->memberA]);
        $waypoints = [];
        foreach ([1 => 900, 2 => 500, 3 => 100] as $seq => $ago) {
            $wp = (int) dbInsert("INSERT INTO mission_route_waypoints (route_id, seq, lat, lng) VALUES (?, ?, ?, ?)", [$routeId, $seq, 35.4, 25.2]);
            dbInsert(
                "INSERT INTO mission_route_progress (waypoint_id, route_id, team_id, completed_at, completed_by) VALUES (?, ?, ?, ?, ?)",
                [$wp, $routeId, $this->teamA, date('Y-m-d H:i:s', time() - $ago), $this->adminId]
            );
            $waypoints[$seq] = $wp;
        }
        return [$routeId, $orderId, $waypoints];
    }

    public function testReopeningARouteOpensItsLastPointAndTheOrderBehindItAndKeepsTheRest(): void
    {
        [$routeId, $orderId, $waypoints] = $this->finishedRoute();

        $this->assertNull(reopenMissionRoute($this->mission, $routeId, $this->adminId));

        $this->assertNull(dbFetchValue("SELECT completed_at FROM mission_routes WHERE id = ?", [$routeId]));
        $this->assertNull(dbFetchValue("SELECT completed_at FROM mission_route_progress WHERE waypoint_id = ?", [$waypoints[3]]), 'the point that closed it is open again');
        $this->assertNotNull(dbFetchValue("SELECT completed_at FROM mission_route_progress WHERE waypoint_id = ?", [$waypoints[1]]), 'the others stay done');
        $this->assertNotNull(dbFetchValue("SELECT completed_at FROM mission_route_progress WHERE waypoint_id = ?", [$waypoints[2]]));
        $this->assertNull(dbFetchValue("SELECT fulfilled_at FROM mission_order_recipients WHERE order_id = ?", [$orderId]), 'the order is outstanding again');
        foreach (loadRoutesForUser($this->missionId, $this->adminId, true) as $row) {
            if ($row['id'] === $routeId) {
                $this->assertSame('active', $row['status']);
                $this->assertNull($row['completed_ts']);
            }
        }
    }

    public function testAReopenedRouteCompletesAgainWhenItsLastPointIsClosedAgain(): void
    {
        [$routeId, , $waypoints] = $this->finishedRoute();
        reopenMissionRoute($this->mission, $routeId, $this->adminId);
        $this->assertFalse(maybeCompleteRoute($routeId, $this->adminId), 'one point open: not complete');

        dbExecute("UPDATE mission_route_progress SET completed_at = NOW(), completed_by = ? WHERE waypoint_id = ?", [$this->adminId, $waypoints[3]]);
        $this->assertTrue(maybeCompleteRoute($routeId, $this->adminId));
        $this->assertNotNull(dbFetchValue("SELECT completed_at FROM mission_routes WHERE id = ?", [$routeId]));
    }

    public function testARouteThatIsNotFinishedOrWasCalledOffCannotBeReopened(): void
    {
        [$routeId] = $this->finishedRoute();
        $open = (int) dbInsert("INSERT INTO mission_routes (mission_id, team_id, title, created_by) VALUES (?, ?, ?, ?)", [$this->missionId, $this->teamA, 'Ανοιχτή', $this->adminId]);
        $off = (int) dbInsert(
            "INSERT INTO mission_routes (mission_id, team_id, title, created_by, cancelled_at) VALUES (?, ?, ?, ?, ?)",
            [$this->missionId, $this->teamA, 'Ακυρωμένη', $this->adminId, date('Y-m-d H:i:s', time() - 60)]
        );
        $this->assertNotNull(reopenMissionRoute($this->mission, $open, $this->adminId));
        $this->assertNotNull(reopenMissionRoute($this->mission, $off, $this->adminId));
        $this->assertNotNull(reopenMissionRoute(['id' => 1] + $this->mission, $routeId, $this->adminId), 'another mission\'s route');
    }

    public function testBothEndpointsKeepTheReopenBehindTheCommandGate(): void
    {
        $dispatch = $this->source('mission-dispatch.php');
        $this->assertLessThan(
            strpos($dispatch, "if (\$action === 'reopen')"),
            strpos($dispatch, 'if (!$canManageWarRoom) {'),
            'after the command-only check of mission-dispatch.php'
        );
        $route = $this->source('mission-route.php');
        $this->assertMatchesRegularExpression('/\$action === \'reopen\'\) \{\s*if \(!\$canManageWarRoom\) \{/', $route);
    }

    // ── What the browser works out ──────────────────────────────────────────

    /** @return array<string, mixed> */
    private function harness(): array
    {
        $node = trim((string) @shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where node 2>NUL' : 'command -v node 2>/dev/null'));
        if ($node === '') {
            $this->markTestSkipped('node is not installed');
        }
        $output = (string) shell_exec('node ' . escapeshellarg($this->root() . '/tests/fixtures/done-orders-harness.js') . ' ' . escapeshellarg($this->root()) . ' 2>&1');
        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, 'the harness did not print JSON: ' . $output);
        return $decoded;
    }

    public function testCommandKeepsTheArchiveOfFinishedOrdersAsBefore(): void
    {
        $command = $this->harness()['command'];
        $this->assertSame('archived', $command['dispatchOld']);
        $this->assertSame('archived', $command['routeOld']);
        $this->assertSame('fresh', $command['dispatchFresh']);
        $this->assertSame('live', $command['dispatchLive']);
        $this->assertSame('live', $command['routeActive']);
    }

    public function testTheFieldSeesWhatIsRunningAndASectorStaysAsItsOutline(): void
    {
        $field = $this->harness()['field'];
        $this->assertSame('hidden', $field['dispatchOld'], 'a finished point leaves the field\'s map after its bright minutes');
        $this->assertSame('hidden', $field['routeOld']);
        $this->assertSame('archived', $field['sectorOld'], 'what is already covered stays visible to every team, so nobody searches it twice');
        $this->assertSame('fresh', $field['dispatchFresh'], 'the ten bright minutes are everybody\'s');
        $this->assertSame('fresh', $field['routeFresh']);
        $this->assertSame('live', $field['dispatchLive']);
        $this->assertSame('live', $field['routeActive']);
    }

    public function testTheRedrawSignatureChangesWithTheFieldDefaultAndTheTick(): void
    {
        $out = $this->harness();
        $this->assertStringContainsString('q', $out['field']['sigDispatch']);
        $this->assertStringContainsString('q', $out['field']['sigRoutes']);
        $this->assertStringNotContainsString('q', $out['field']['sigSector'], 'a sector is never quiet');
        $this->assertStringNotContainsString('q', $out['command']['sigDispatch']);
    }

    public function testTheTickBringsTheFinishedOrdersBackForTheField(): void
    {
        $shown = $this->harness()['fieldShowAll'];
        $this->assertSame('archived', $shown['dispatchOld']);
        $this->assertSame('archived', $shown['routeOld']);
    }

    public function testTheChoicesOfCommandStillWinForTheField(): void
    {
        $out = $this->harness();
        $this->assertSame('hidden', $out['fieldModeHidden']['dispatchOld']);
        $this->assertSame('live', $out['fieldModeNormal']['dispatchOld']);
    }

    public function testOnlyWhatTheViewerMayReadMakesAPointBrighter(): void
    {
        $notes = $this->harness()['notes'];
        $this->assertSame('Αποχώρησε περιπατητικός', $notes['own']);
        $this->assertNull($notes['otherTeamsNote'], 'the order\'s own team is the one whose words count');
        $this->assertNull($notes['notFinishedYet']);
        $this->assertSame('last', $notes['broadcastLast'], 'an order to every team: the last note');
        $this->assertNull($notes['none']);
        $this->assertNull($notes['noProgress']);
    }

    public function testTheListBehindTheCounterMarksTheOrdersWithANote(): void
    {
        $this->assertSame([[1, true], [2, false]], $this->harness()['entries']);
    }

    // ── How the page is wired ───────────────────────────────────────────────

    public function testOnlyWhoCannotChooseTheModeGetsTheFieldDefault(): void
    {
        $done = $this->source('assets/js/war-room-done-orders.js');
        $this->assertStringContainsString('wrDoneFieldQuiet = !canChoose;', $done);
        $page = $this->source('war-room.php');
        $this->assertMatchesRegularExpression('/wrDoneInit\(<\?= \(int\) \$missionId \?>, MAP_LAYER_TOOLS_ENABLED\);/', $page, 'the same flag that gates the layer tools for command');
    }

    public function testASectorIsNeverTakenOffTheFieldsMap(): void
    {
        $done = $this->source('assets/js/war-room-done-orders.js');
        $this->assertMatchesRegularExpression("/function wrDoneFieldHides\\(kind\\) \\{\\s*return wrDoneFieldQuiet && !wrDoneFieldShowAll && kind !== 'sector';/", $done);
        $page = $this->source('war-room.php');
        $this->assertStringContainsString("wrDonePhase(item.completed_ts, item.team_id, 'sector')", $page);
        $this->assertStringContainsString("wrDonePhase(item.completed_ts, item.team_id, 'dispatch')", $page);
    }

    public function testTheListIsNeverSilentAboutWhatIsOffTheFieldsMap(): void
    {
        $done = $this->source('assets/js/war-room-done-orders.js');
        $this->assertStringContainsString('data-done-field', $done);
        $this->assertStringContainsString('bi-eye-slash', $done, 'the chip says so');
        $this->assertStringContainsString('if (wrDoneFieldHides(kind)) wrDoneFieldSet(true);', $done, 'asking to see an order brings it back');
    }

    public function testTheHoverIsOnAnOwnLayerAndNeverLeftBehind(): void
    {
        $done = $this->source('assets/js/war-room-done-orders.js');
        foreach (['mouseover', 'mouseout', 'focusin', 'focusout'] as $event) {
            $this->assertStringContainsString("div.addEventListener('" . $event . "'", $done, $event);
        }
        $this->assertStringContainsString('wrDoneHoverLayer = L.layerGroup(shapes).addTo(map);', $done);
        // The rows are replaced when the list is rebuilt: the light must not outlive them.
        $this->assertMatchesRegularExpression('/wrDoneChipSig = sig;\s*wrDoneHoverOff\(\);/', $done);
    }

    public function testTheReopenButtonsAreWiredAndOnlyForCommand(): void
    {
        $page = $this->source('war-room.php');
        $this->assertStringContainsString("sendDispatchAction('reopen', btn.dataset.id, btn, null, {scope: btn.dataset.scope});", $page);
        $this->assertStringContainsString("const finishedLines = item.can_delete ? (item.progress || [])", $page, 'command only: can_delete is the server\'s flag for command');
        $this->assertStringContainsString("if (!route.can_manage || route.status !== 'completed') return '';", $page);
        $this->assertStringContainsString("postRouteAction('reopen', btn.dataset.id, {})", $page);
        $this->assertStringContainsString('routeReopenBtnHtml(route)', $this->source('assets/js/war-room-done-orders.js'), 'the faded record of a finished route offers it too');
    }

    public function testEveryStringTheseToolsAskForIsTranslated(): void
    {
        $lang = $this->source('includes/lang/war-room.php');
        $keys = [];
        foreach (['assets/js/war-room-done-orders.js', 'war-room.php', 'includes/functions-warroom.php'] as $file) {
            preg_match_all("/t\\('((?:dispatch\\.reopen_[a-z_]+|route\\.reopen_[a-z_]+|route\\.notify_reopened_[a-z]+|map\\.done_note_tip|map\\.done_field_[a-z]+))'/", $this->source($file), $m);
            $keys = array_merge($keys, $m[1]);
        }
        $keys = array_values(array_unique($keys));
        $this->assertGreaterThanOrEqual(9, count($keys));
        foreach ($keys as $key) {
            $this->assertSame(2, substr_count($lang, "'" . $key . "' =>"), $key . ' must be in the Greek AND the English strings');
        }
    }
}
