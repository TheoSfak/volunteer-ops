<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Finished orders on the live map (v3.439.0): an order whose team has finished
 * it stops being something to act on and becomes a record of what was covered.
 * The server only says WHEN each one was finished (completed_ts); the browser
 * counts the minutes (assets/js/war-room-done-orders.js), because the 5-second
 * poll is hashed and nothing in it may slide with the time of the request.
 *
 * The first block runs inside a transaction that is always rolled back; the
 * rest reads the source, because the drawing itself is browser code.
 */
final class CompletedOrdersFadeTest extends TestCase
{
    private int $missionId;
    private int $adminId;
    private int $teamA;
    private int $teamB;

    protected function setUp(): void
    {
        db()->beginTransaction();
        forgetActiveOrderDeclines();

        $this->adminId = (int) dbInsert(
            "INSERT INTO users (name, email, password) VALUES (?, ?, ?)",
            ['Done Admin', 'done-' . uniqid('', true) . '@example.invalid', 'x']
        );
        $missionTypeId = (int) dbFetchValue("SELECT id FROM mission_types ORDER BY id LIMIT 1");
        $this->missionId = (int) dbInsert(
            "INSERT INTO missions (title, location, start_datetime, end_datetime, mission_type_id, status, show_in_ops, responsible_user_id)
             VALUES (?, ?, ?, ?, ?, ?, 1, ?)",
            ['Done Mission', 'Ηράκλειο', date('Y-m-d H:i:s', time() - 7200), date('Y-m-d H:i:s', time() + 7200), $missionTypeId, STATUS_OPEN, $this->adminId]
        );
        $this->teamA = $this->makeTeam('ΑΛΦΑ', 1);
        $this->teamB = $this->makeTeam('ΒΗΤΑ', 2);
    }

    protected function tearDown(): void
    {
        db()->rollBack();
        forgetActiveOrderDeclines();
    }

    private function makeTeam(string $codename, int $number): int
    {
        return (int) dbInsert(
            "INSERT INTO mission_teams (mission_id, codename, team_number, color, created_by) VALUES (?, ?, ?, ?, ?)",
            [$this->missionId, $codename, $number, '#2a78d6', $this->adminId]
        );
    }

    private function dispatchPoint(?int $teamId): int
    {
        return (int) dbInsert(
            "INSERT INTO mission_dispatch_points (mission_id, team_id, type, geo, label, created_by) VALUES (?, ?, 'point', ?, ?, ?)",
            [$this->missionId, $teamId, json_encode(['lat' => 35.2, 'lng' => 24.9]), 'Γέφυρα', $this->adminId]
        );
    }

    private function progress(int $dispatchId, int $teamId, ?string $completedAt, ?string $departedAt = null): void
    {
        dbInsert(
            "INSERT INTO mission_dispatch_progress (dispatch_id, team_id, scope_key, departed_at, completed_at, completed_by) VALUES (?, ?, ?, ?, ?, ?)",
            [$dispatchId, $teamId, 't' . $teamId, $departedAt, $completedAt, $completedAt ? $this->adminId : null]
        );
    }

    private function dispatchItem(int $dispatchId): array
    {
        foreach (loadMissionDispatchesForUser($this->missionId, $this->adminId, true, false) as $item) {
            if ($item['id'] === $dispatchId) {
                return $item;
            }
        }
        $this->fail('dispatch ' . $dispatchId . ' is not in the payload');
    }

    private function sector(?int $teamId, string $status, string $statusUpdatedAt): int
    {
        $areaId = (int) dbInsert(
            "INSERT INTO mission_search_areas (mission_id, label, geo, created_by) VALUES (?, ?, ?, ?)",
            [$this->missionId, 'Ζώνη', json_encode([[35.1, 25.1], [35.2, 25.1], [35.2, 25.2]]), $this->adminId]
        );
        return (int) dbInsert(
            "INSERT INTO mission_search_sectors (mission_id, area_id, team_id, label, geo, status, status_updated_at, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            [$this->missionId, $areaId, $teamId, 'Τομέας Α', json_encode([[35.30, 25.10], [35.30, 25.12], [35.32, 25.12], [35.32, 25.10]]), $status, $statusUpdatedAt, $this->adminId]
        );
    }

    private function sectorItem(int $sectorId): array
    {
        foreach (loadMissionSectorsForUser($this->missionId, $this->adminId, true, false) as $item) {
            if ($item['id'] === $sectorId) {
                return $item;
            }
        }
        $this->fail('sector ' . $sectorId . ' is not in the payload');
    }

    private function route(int $teamId, ?string $completedAt, ?string $cancelledAt = null): int
    {
        $routeId = (int) dbInsert(
            "INSERT INTO mission_routes (mission_id, team_id, title, created_by, completed_at, cancelled_at) VALUES (?, ?, ?, ?, ?, ?)",
            [$this->missionId, $teamId, 'Περίπολος', $this->adminId, $completedAt, $cancelledAt]
        );
        dbInsert("INSERT INTO mission_route_waypoints (route_id, seq, lat, lng) VALUES (?, 1, ?, ?)", [$routeId, 35.40, 25.20]);
        return $routeId;
    }

    private function routeItem(int $routeId): array
    {
        foreach (loadRoutesForUser($this->missionId, $this->adminId, true) as $item) {
            if ($item['id'] === $routeId) {
                return $item;
            }
        }
        $this->fail('route ' . $routeId . ' is not in the payload');
    }

    // ── What the server says ────────────────────────────────────────────────

    public function testAFinishedPointCarriesTheMomentItsTeamFinished(): void
    {
        $id = $this->dispatchPoint($this->teamA);
        $at = date('Y-m-d H:i:s', time() - 180);
        $this->progress($id, $this->teamA, $at);

        $item = $this->dispatchItem($id);
        $this->assertSame(strtotime($at), $item['completed_ts']);
        $this->assertSame(date('H:i', strtotime($at)), $item['completed_display']);
        $this->assertNull($item['eta'], 'a finished order has no time left to guess, even if nobody pressed «Έφτασα» first');
    }

    public function testAPointNobodyFinishedHasNoTimestamp(): void
    {
        $started = $this->dispatchPoint($this->teamA);
        $this->progress($started, $this->teamA, null, date('Y-m-d H:i:s', time() - 60));
        $untouched = $this->dispatchPoint($this->teamB);

        $this->assertNull($this->dispatchItem($started)['completed_ts']);
        $this->assertNull($this->dispatchItem($untouched)['completed_ts']);
    }

    public function testAnOrderSentToEveryTeamNeverFades(): void
    {
        // No single team finishes it, so one team's «Ολοκληρώθηκε» must not
        // take it off everybody's map.
        $id = $this->dispatchPoint(null);
        $this->progress($id, $this->teamA, date('Y-m-d H:i:s', time() - 3600));

        $this->assertNull($this->dispatchItem($id)['completed_ts']);
    }

    public function testAnotherTeamsCompletionDoesNotFinishThisOne(): void
    {
        $id = $this->dispatchPoint($this->teamA);
        $this->progress($id, $this->teamB, date('Y-m-d H:i:s', time() - 3600));

        $this->assertNull($this->dispatchItem($id)['completed_ts']);
    }

    public function testASectorIsFinishedOnlyWhileItIsCompleted(): void
    {
        $at = date('Y-m-d H:i:s', time() - 300);
        $done = $this->sector($this->teamA, 'completed', $at);
        $recheck = $this->sector($this->teamA, 'needs_recheck', $at);
        $assigned = $this->sector($this->teamB, 'assigned', $at);

        $this->assertSame(strtotime($at), $this->sectorItem($done)['completed_ts']);
        $this->assertNull($this->sectorItem($recheck)['completed_ts'], 'a sector sent back for a recheck is a live one again');
        $this->assertNull($this->sectorItem($assigned)['completed_ts']);
    }

    public function testARouteCarriesItsCompletionButNotACancellation(): void
    {
        $at = date('Y-m-d H:i:s', time() - 600);
        $done = $this->route($this->teamA, $at);
        $open = $this->route($this->teamA, null);
        $cancelled = $this->route($this->teamB, null, $at);

        $item = $this->routeItem($done);
        $this->assertSame('completed', $item['status']);
        $this->assertSame(strtotime($at), $item['completed_ts']);
        $this->assertNull($this->routeItem($open)['completed_ts']);
        $this->assertSame('cancelled', $this->routeItem($cancelled)['status']);
        $this->assertNull($this->routeItem($cancelled)['completed_ts']);
    }

    public function testThePayloadHoldsNothingThatSlidesWithTheClock(): void
    {
        // The poll is md5-hashed: a field that changed with the time of the
        // request would make every poll look new. Two reads, same bytes.
        $id = $this->dispatchPoint($this->teamA);
        $this->progress($id, $this->teamA, date('Y-m-d H:i:s', time() - 90));
        $this->sector($this->teamA, 'completed', date('Y-m-d H:i:s', time() - 90));
        $this->route($this->teamA, date('Y-m-d H:i:s', time() - 90));

        $first = json_encode([
            loadMissionDispatchesForUser($this->missionId, $this->adminId, true, false),
            loadMissionSectorsForUser($this->missionId, $this->adminId, true, false),
            loadRoutesForUser($this->missionId, $this->adminId, true),
        ]);
        $second = json_encode([
            loadMissionDispatchesForUser($this->missionId, $this->adminId, true, false),
            loadMissionSectorsForUser($this->missionId, $this->adminId, true, false),
            loadRoutesForUser($this->missionId, $this->adminId, true),
        ]);
        $this->assertSame($first, $second);
    }

    // ── What the page does with it ──────────────────────────────────────────

    private function page(): string
    {
        return (string) file_get_contents(__DIR__ . '/../war-room.php');
    }

    private function script(): string
    {
        return (string) file_get_contents(__DIR__ . '/../assets/js/war-room-done-orders.js');
    }

    public function testThePageLoadsTheScriptAndCountsAgainstTheServersClock(): void
    {
        $page = $this->page();
        $this->assertStringContainsString('assets/js/war-room-done-orders.js', $page);
        $this->assertMatchesRegularExpression('/const WR_CLOCK_OFFSET = <\?= time\(\) \?> - Date\.now\(\) \/ 1000;/', $page);
        $this->assertStringContainsString('function wrDoneNowSec()', $this->script());
        $this->assertStringContainsString('WR_CLOCK_OFFSET', $this->script());
    }

    public function testTheChoiceIsRestoredBeforeTheFirstDrawing(): void
    {
        // The page's own memo variables do not exist any earlier than its first
        // render, and a drawing made before the stored choice is read would be
        // thrown away at once.
        $page = $this->page();
        $init = strpos($page, 'wrDoneInit(<?= (int) $missionId ?>, MAP_LAYER_TOOLS_ENABLED);');
        $first = strpos($page, 'renderPins(pins); renderDispatches(dispatches);');
        $this->assertNotFalse($init);
        $this->assertNotFalse($first);
        $this->assertLessThan($first, $init);
    }

    public function testEveryRenderMemoKnowsThePhasesSoTheClockCanRedrawThem(): void
    {
        $page = $this->page();
        $this->assertStringContainsString("const sig = JSON.stringify(items) + '|' + wrDonePhaseSig(items);", $page, 'dispatches');
        $this->assertMatchesRegularExpression("/\\+ '\\|' \\+ openBuildingId \\+ '\\|' \\+ wrDonePhaseSig\\(items\\);/", $page, 'sectors');
        $this->assertStringContainsString("JSON.stringify(allRoutes) + '|' + wrDoneRoutesSig(allRoutes)", $page, 'routes');
    }

    public function testEachKindOfShapeHasItsArchivedAndHiddenBranch(): void
    {
        $page = $this->page();
        $this->assertStringContainsString("if (donePhase === 'hidden') return;", $page, 'a pin');
        $this->assertStringContainsString('layer = wrDoneDrawDispatch(item, popupHtml);', $page);
        $this->assertStringContainsString("if (donePhase === 'fresh') wrDoneAddRing(item);", $page);
        $this->assertStringContainsString("if (donePhase === 'hidden' && String(item.id) !== String(openSectorId)) return;", $page, 'a sector');
        $this->assertStringContainsString('wrDoneSectorOutline(item, color)', $page);
        $this->assertStringContainsString("if (donePhase === 'archived' || donePhase === 'hidden') return;", $page, 'the buildings of a finished sector');
        $this->assertStringContainsString("!['skip', 'hidden'].includes(wrDoneRoutePhase(r))", $page, 'a route');
        $this->assertStringContainsString('wrDoneDrawRoute(route)', $page);
    }

    public function testNothingThatStillAsksForSomethingIsEverFaded(): void
    {
        $js = $this->script();
        // Only a completion time puts an order into a phase at all.
        // (Since v3.440.0 the first line looks at «Εστίαση σε ομάδα», which hides an order of another team; the rule is unchanged.)
        $this->assertMatchesRegularExpression("/function wrDonePhase\\(ts, teamId\\) \\{\\s*if \\(wrDoneFocusHides\\(teamId\\)\\) return 'hidden';\\s*if \\(!ts \\|\\| wrDoneMode === 'normal'\\) return 'live';/", $js);
        // A route that is open is live; a cancelled one never was on the map.
        $this->assertStringContainsString("if (route.status === 'active') return 'live';", $js);
        $this->assertStringContainsString("if (route.status !== 'completed' || wrDoneMode === 'normal') return 'skip';", $js);
    }

    public function testAVolunteersTabNeverStartsWithAStateItCannotUndo(): void
    {
        $js = $this->script();
        $this->assertStringContainsString('if (canChoose && WR_DONE_MODES.includes(stored)) wrDoneMode = stored;', $js);
        $this->assertStringContainsString('if (canChoose) wrDoneAddMenu();', $js);
    }

    public function testTheHiddenChoiceIsAnnouncedLikeAnyHiddenLayer(): void
    {
        $page = $this->page();
        $this->assertStringContainsString("if (wrDoneMode === 'hidden' && wrDoneHiddenCount > 0) hidden.push(t('map.done_hidden_item', {n: wrDoneHiddenCount}));", $page);
        $this->assertStringContainsString("if (wrDoneMode === 'hidden') wrDoneSetMode('faded');", $page, '«Εμφάνιση όλων» brings them back');
    }

    public function testEveryWordIsTranslatedBothWays(): void
    {
        $strings = loadLangStrings('war-room');
        $keys = ['map.done_header', 'map.done_mode_normal', 'map.done_mode_faded', 'map.done_mode_hidden', 'map.done_hint',
                 'map.done_chip', 'map.done_hidden_item', 'map.done_list_title'];
        foreach (['el', 'en'] as $lang) {
            foreach ($keys as $key) {
                $this->assertArrayHasKey($key, $strings[$lang], $key . ' in ' . $lang);
                $this->assertNotSame('', trim($strings[$lang][$key]), $key . ' in ' . $lang);
            }
        }
        foreach (['map.done_chip', 'map.done_hidden_item'] as $key) {
            $this->assertStringContainsString('{n}', $strings['el'][$key]);
            $this->assertStringContainsString('{n}', $strings['en'][$key]);
        }
        $this->assertStringContainsString('{min}', $strings['el']['map.done_hint']);
        $this->assertStringContainsString('{min}', $strings['en']['map.done_hint']);
    }
}
