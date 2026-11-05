<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Destination arcs (v3.437.0): from every team that has been sent somewhere and
 * has not got there yet, an arch to where it was sent, with the time it will
 * take and the metres still to go.
 *
 * The first block runs inside a transaction that is always rolled back; the
 * rest reads the source, because the drawing itself is browser code.
 */
final class TeamArcsTest extends TestCase
{
    private const LAT = 35.2000000;
    private const LNG = 24.9000000;

    private int $missionId;
    private int $shiftId;
    private int $adminId;
    private int $teamA;
    private int $teamB;

    protected function setUp(): void
    {
        db()->beginTransaction();
        forgetActiveOrderDeclines();

        $this->adminId = $this->makeUser('Arc Admin');
        $missionTypeId = (int) dbFetchValue("SELECT id FROM mission_types ORDER BY id LIMIT 1");
        $this->missionId = (int) dbInsert(
            "INSERT INTO missions (title, location, start_datetime, end_datetime, mission_type_id, status, show_in_ops, responsible_user_id)
             VALUES (?, ?, ?, ?, ?, ?, 1, ?)",
            ['Arc Mission', 'Ηράκλειο', date('Y-m-d H:i:s', time() - 7200), date('Y-m-d H:i:s', time() + 7200), $missionTypeId, STATUS_OPEN, $this->adminId]
        );
        $this->shiftId = (int) dbInsert(
            "INSERT INTO shifts (mission_id, start_time, end_time) VALUES (?, ?, ?)",
            [$this->missionId, date('Y-m-d H:i:s', time() - 3600), date('Y-m-d H:i:s', time() + 3600)]
        );
        $this->teamA = $this->makeTeam('ΑΛΦΑ', 1, '#e34948');
        $this->teamB = $this->makeTeam('ΒΗΤΑ', 2, '#2a78d6');
    }

    protected function tearDown(): void
    {
        db()->rollBack();
        forgetActiveOrderDeclines();
    }

    private function makeUser(string $name): int
    {
        return (int) dbInsert(
            "INSERT INTO users (name, email, password) VALUES (?, ?, ?)",
            [$name, 'arc-' . uniqid('', true) . '@example.invalid', 'x']
        );
    }

    private function makeTeam(string $codename, int $number, string $color): int
    {
        return (int) dbInsert(
            "INSERT INTO mission_teams (mission_id, codename, team_number, color, created_by) VALUES (?, ?, ?, ?, ?)",
            [$this->missionId, $codename, $number, $color, $this->adminId]
        );
    }

    private function dispatchPoint(int $teamId, int $minutesAgo = 0): int
    {
        return (int) dbInsert(
            "INSERT INTO mission_dispatch_points (mission_id, team_id, type, geo, label, created_by, created_at)
             VALUES (?, ?, 'point', ?, ?, ?, ?)",
            [$this->missionId, $teamId, json_encode(['lat' => self::LAT, 'lng' => self::LNG]), 'Πηγή', $this->adminId, date('Y-m-d H:i:s', time() - $minutesAgo * 60)]
        );
    }

    private function sector(int $teamId, string $status = 'assigned'): int
    {
        $areaId = (int) dbInsert(
            "INSERT INTO mission_search_areas (mission_id, label, geo, created_by) VALUES (?, ?, ?, ?)",
            [$this->missionId, 'Ζώνη', json_encode([[35.1, 25.1], [35.2, 25.1], [35.2, 25.2]]), $this->adminId]
        );
        return (int) dbInsert(
            "INSERT INTO mission_search_sectors (mission_id, area_id, team_id, label, geo, status, status_updated_at, created_by)
             VALUES (?, ?, ?, ?, ?, ?, NOW(), ?)",
            [$this->missionId, $areaId, $teamId, 'Τομέας Α', json_encode([[35.30, 25.10], [35.30, 25.12], [35.32, 25.12], [35.32, 25.10]]), $status, $this->adminId]
        );
    }

    /** @return array{0:int,1:int} route id and the id of its second waypoint */
    private function routeWithTwoPoints(int $teamId, bool $firstReached): array
    {
        $routeId = (int) dbInsert(
            "INSERT INTO mission_routes (mission_id, team_id, title, created_by) VALUES (?, ?, ?, ?)",
            [$this->missionId, $teamId, 'Περίπολος', $this->adminId]
        );
        $first = (int) dbInsert("INSERT INTO mission_route_waypoints (route_id, seq, lat, lng) VALUES (?, 1, ?, ?)", [$routeId, 35.40, 25.20]);
        $second = (int) dbInsert("INSERT INTO mission_route_waypoints (route_id, seq, lat, lng) VALUES (?, 2, ?, ?)", [$routeId, 35.45, 25.25]);
        dbInsert(
            "INSERT INTO mission_route_progress (waypoint_id, route_id, team_id, arrived_at) VALUES (?, ?, ?, " . ($firstReached ? 'NOW()' : 'NULL') . ")",
            [$first, $routeId, $teamId]
        );
        return [$routeId, $second];
    }

    /** A team position $metresSouth below the dispatch point's latitude. */
    private function position(int $teamId, float $metresSouth, bool $stale = false): array
    {
        return [
            'team_id' => $teamId,
            'label'   => $teamId === $this->teamA ? 'ΑΛΦΑ 1' : 'ΒΗΤΑ 2',
            'color'   => $teamId === $this->teamA ? '#e34948' : '#2a78d6',
            'lat'     => self::LAT - $metresSouth / 111320.0,
            'lng'     => self::LNG,
            'ts'      => time() - ($stale ? 3600 : 5),
            'is_stale' => $stale,
        ];
    }

    private function arcs(array $positions, bool $manage = true, ?int $myTeam = null): array
    {
        return teamArcsForMission($this->missionId, $positions, $manage, $myTeam);
    }

    // ── What an arc says ────────────────────────────────────────────────────

    public function testAPointGivesAnArcWithTheMetresAndATimeOnFoot(): void
    {
        $this->dispatchPoint($this->teamA);
        $arcs = $this->arcs([$this->position($this->teamA, 1000)]);

        $this->assertCount(1, $arcs);
        $arc = $arcs[0];
        $this->assertSame($this->teamA, $arc['team_id']);
        $this->assertSame('point', $arc['kind']);
        $this->assertSame('#e34948', $arc['color']);
        $this->assertSame('foot', $arc['transport']);
        $this->assertEqualsWithDelta(1000, $arc['distance_m'], 10);
        // No routing key in a test database, so this is the straight line at
        // 4 km/h: 1 km is 15 minutes, and the arc says it is an estimate.
        $this->assertSame('straight_line', $arc['eta']['source']);
        $this->assertSame('foot', $arc['eta']['mode']);
        $this->assertEqualsWithDelta(15, $arc['eta']['minutes'], 1);
        $this->assertFalse($arc['from']['stale']);
        $this->assertEqualsWithDelta(self::LAT, $arc['to']['lat'], 0.000001);
    }

    public function testASectorIsReachedAtTheMiddleOfItsPolygon(): void
    {
        $this->sector($this->teamA);
        $arcs = $this->arcs([$this->position($this->teamA, 1000)]);

        $this->assertCount(1, $arcs);
        $this->assertSame('sector', $arcs[0]['kind']);
        $this->assertEqualsWithDelta(35.31, $arcs[0]['to']['lat'], 0.0001);
        $this->assertEqualsWithDelta(25.11, $arcs[0]['to']['lng'], 0.0001);
    }

    public function testARouteIsReachedAtTheNextWaypointNotTheFirst(): void
    {
        $this->routeWithTwoPoints($this->teamA, true);
        $arcs = $this->arcs([$this->position($this->teamA, 1000)]);

        $this->assertCount(1, $arcs);
        $this->assertSame('route', $arcs[0]['kind']);
        $this->assertEqualsWithDelta(35.45, $arcs[0]['to']['lat'], 0.000001);
        $this->assertEqualsWithDelta(25.25, $arcs[0]['to']['lng'], 0.000001);
    }

    public function testARouteWhoseFirstPointIsNotReachedPointsAtTheFirst(): void
    {
        $this->routeWithTwoPoints($this->teamA, false);
        $arcs = $this->arcs([$this->position($this->teamA, 1000)]);

        $this->assertEqualsWithDelta(35.40, $arcs[0]['to']['lat'], 0.000001);
    }

    public function testTheNewestTargetWins(): void
    {
        $this->dispatchPoint($this->teamA, 30);
        $this->sector($this->teamA);
        $arcs = $this->arcs([$this->position($this->teamA, 1000)]);

        $this->assertCount(1, $arcs, 'one arc per team, however many places it was sent');
        $this->assertSame('sector', $arcs[0]['kind']);
    }

    // ── When there is none ──────────────────────────────────────────────────

    public function testNoArcOnceTheTeamHasArrived(): void
    {
        $dispatchId = $this->dispatchPoint($this->teamA);
        dbInsert(
            "INSERT INTO mission_dispatch_progress (dispatch_id, team_id, scope_key, arrived_at) VALUES (?, ?, ?, NOW())",
            [$dispatchId, $this->teamA, 't' . $this->teamA]
        );
        $this->assertSame([], $this->arcs([$this->position($this->teamA, 1000)]));
    }

    public function testNoArcOnceTheTeamHasCompletedWithoutAnArrivalRow(): void
    {
        $dispatchId = $this->dispatchPoint($this->teamA);
        dbInsert(
            "INSERT INTO mission_dispatch_progress (dispatch_id, team_id, scope_key, completed_at) VALUES (?, ?, ?, NOW())",
            [$dispatchId, $this->teamA, 't' . $this->teamA]
        );
        $this->assertSame([], $this->arcs([$this->position($this->teamA, 1000)]));
    }

    public function testAnArrivalFromBeforeTheProgressTableStillCounts(): void
    {
        $dispatchId = $this->dispatchPoint($this->teamA);
        $volunteer = $this->makeUser('Legacy V.');
        dbInsert(
            "INSERT INTO mission_dispatch_acks (dispatch_id, team_id, user_id) VALUES (?, ?, ?)",
            [$dispatchId, $this->teamA, $volunteer]
        );
        $this->assertSame([], $this->arcs([$this->position($this->teamA, 1000)]));
    }

    public function testNoArcForASectorTheTeamIsAlreadyWorking(): void
    {
        $this->sector($this->teamA, 'in_progress');
        $this->assertSame([], $this->arcs([$this->position($this->teamA, 1000)]));
    }

    public function testNoArcForATeamThatSaidItCannot(): void
    {
        $dispatchId = $this->dispatchPoint($this->teamA);
        dbInsert(
            "INSERT INTO mission_order_declines (mission_id, target_kind, target_id, scope_key, team_id, reason, declined_by, declined_at, active)
             VALUES (?, 'dispatch', ?, ?, ?, 'busy', ?, NOW(), 1)",
            [$this->missionId, $dispatchId, 't' . $this->teamA, $this->teamA, $this->adminId]
        );
        forgetActiveOrderDeclines();
        $this->assertSame([], $this->arcs([$this->position($this->teamA, 1000)]));
    }

    public function testNoArcForATargetAddressedToEveryTeam(): void
    {
        dbInsert(
            "INSERT INTO mission_dispatch_points (mission_id, team_id, type, geo, label, created_by) VALUES (?, NULL, 'point', ?, ?, ?)",
            [$this->missionId, json_encode(['lat' => self::LAT, 'lng' => self::LNG]), 'Όλοι', $this->adminId]
        );
        $this->assertSame([], $this->arcs([$this->position($this->teamA, 1000)]));
    }

    public function testNoArcForATeamWithNoPosition(): void
    {
        $this->dispatchPoint($this->teamA);
        $this->assertSame([], $this->arcs([]));
    }

    public function testNoArcWhenTheTeamIsAlreadyStandingOnTheTarget(): void
    {
        $this->dispatchPoint($this->teamA);
        $this->assertSame([], $this->arcs([$this->position($this->teamA, 5)]));
        $this->assertCount(1, $this->arcs([$this->position($this->teamA, 60)]));
    }

    // ── Who sees whose ──────────────────────────────────────────────────────

    public function testCommandSeesEveryTeamAndAParticipantOnlyTheirOwn(): void
    {
        $this->dispatchPoint($this->teamA);
        $this->dispatchPoint($this->teamB);
        $positions = [$this->position($this->teamA, 1000), $this->position($this->teamB, 2000)];

        $this->assertSame([$this->teamA, $this->teamB], array_column($this->arcs($positions, true), 'team_id'));
        $this->assertSame([$this->teamB], array_column($this->arcs($positions, false, $this->teamB), 'team_id'));
        $this->assertSame([], $this->arcs($positions, false, null), 'somebody on no team has no arc to see');
    }

    // ── It follows the team ─────────────────────────────────────────────────

    public function testHowMuchOfTheWayIsDoneGrowsAsTheTeamApproaches(): void
    {
        $this->dispatchPoint($this->teamA);
        $first = $this->arcs([$this->position($this->teamA, 1000)]);
        $this->assertSame(0, $first[0]['covered_pct']);

        $halfway = $this->arcs([$this->position($this->teamA, 500)]);
        $this->assertEqualsWithDelta(50, $halfway[0]['covered_pct'], 2);

        $moving_away = $this->arcs([$this->position($this->teamA, 1500)]);
        $this->assertSame(0, $moving_away[0]['covered_pct'], 'walking away never reads as negative progress');
    }

    public function testATripTooShortToMeasureHasNoProgress(): void
    {
        $this->dispatchPoint($this->teamA);
        $arcs = $this->arcs([$this->position($this->teamA, 40)]);
        $this->assertCount(1, $arcs);
        $this->assertNull($arcs[0]['covered_pct']);
    }

    public function testTheTimeFollowsTheDistanceBetweenAsksOfTheRouter(): void
    {
        $this->sector($this->teamA);
        // The sector's middle is (35.31, 25.11), so the positions are measured from there.
        $southOfSector = fn(float $metres) => array_merge($this->position($this->teamA, 0), [
            'lat' => 35.31 - $metres / 111320.0, 'lng' => 25.11,
        ]);
        $far = $this->arcs([$southOfSector(4000)]);
        $this->assertEqualsWithDelta(60, $far[0]['eta']['minutes'], 2, '4 km on foot at 4 km/h');

        // Seconds later, half way there: the remembered figure is scaled, not asked for again.
        $near = $this->arcs([$southOfSector(2000)]);
        $this->assertEqualsWithDelta($far[0]['eta']['minutes'] / 2, $near[0]['eta']['minutes'], 1.5);
        $this->assertLessThan($far[0]['eta']['minutes'], $near[0]['eta']['minutes']);
    }

    public function testANewTargetStartsTheProgressAgain(): void
    {
        $dispatchId = $this->dispatchPoint($this->teamA, 30);
        $this->arcs([$this->position($this->teamA, 1000)]);
        $this->assertSame('dispatch', dbFetchValue("SELECT target_kind FROM team_arc_state WHERE team_id = ?", [$this->teamA]));

        $this->sector($this->teamA);
        $arcs = $this->arcs([$this->position($this->teamA, 800)]);
        $this->assertSame('sector', dbFetchValue("SELECT target_kind FROM team_arc_state WHERE team_id = ?", [$this->teamA]));
        $this->assertSame(0, $arcs[0]['covered_pct'], 'the new place is measured from where the team was when it was sent there');
        $this->assertGreaterThan(0, $dispatchId);
    }

    public function testAStaleFixIsSaidToBeStaleAndCarriesItsAbsoluteTime(): void
    {
        $this->dispatchPoint($this->teamA);
        $position = $this->position($this->teamA, 1000, true);
        $arc = $this->arcs([$position])[0];

        $this->assertTrue($arc['from']['stale']);
        $this->assertSame($position['ts'], $arc['from']['ts']);
        $this->assertArrayNotHasKey('age_seconds', $arc['from'], 'nothing in the payload may slide with the clock: the poll hashes it');
    }

    public function testTeamPositionsCarryTheAbsoluteTimeOfTheFix(): void
    {
        $volunteer = $this->makeUser('Pinger P.');
        dbInsert("INSERT INTO participation_requests (shift_id, volunteer_id, status) VALUES (?, ?, ?)", [$this->shiftId, $volunteer, PARTICIPATION_APPROVED]);
        dbInsert("INSERT INTO mission_team_members (team_id, mission_id, user_id) VALUES (?, ?, ?)", [$this->teamA, $this->missionId, $volunteer]);
        forgetActionRoomParticipantIds($this->missionId);
        setActionRoomParticipation($this->missionId, $volunteer, true, $this->adminId);
        dbExecute(
            "INSERT INTO volunteer_pings (user_id, shift_id, lat, lng, accuracy_meters, source, via, created_at)
             VALUES (?, ?, ?, ?, 6, 'auto', 'native', DATE_SUB(NOW(), INTERVAL 30 SECOND))",
            [$volunteer, $this->shiftId, self::LAT, self::LNG]
        );

        $positions = loadTeamPositionsForMission($this->missionId);
        $this->assertCount(1, $positions);
        $this->assertIsInt($positions[0]['ts']);
        $this->assertEqualsWithDelta(time() - 30, $positions[0]['ts'], 5);
        forgetActionRoomParticipantIds($this->missionId);
    }

    // ── Wiring ──────────────────────────────────────────────────────────────

    private function source(string $relative): string
    {
        return (string) file_get_contents(__DIR__ . '/../' . $relative);
    }

    public function testThePollCarriesTheArcsAndThePageSeedsThemAndDrawsThem(): void
    {
        $page = $this->source('war-room.php');
        $this->assertStringContainsString("'teamArcs' => \$teamProximity['teamArcs']", $page, 'the poll payload');
        $this->assertStringContainsString('let teamArcs = <?= json_encode($teamArcs) ?>;', $page, 'the first paint');
        $this->assertStringContainsString('if (data.teamArcs) renderTeamArcs(teamArcs = data.teamArcs);', $page, 'every poll after');
        $this->assertStringContainsString('renderTeamArcs(teamArcs);', $page, 'the first draw');
        $this->assertStringContainsString('teamArcsInit();', $page);
        $this->assertStringContainsString('/assets/js/war-room-team-arcs.js', $page);
        $this->assertMatchesRegularExpression("/key: 'team_arcs'.*teamArcLayer, teamArcLabelLayer/", $page, 'listed in «Εμφάνιση στον χάρτη»');
        $this->assertStringContainsString('teamArcsSuspend(true);', $page, 'not drawn over a trail replay');
        $this->assertStringContainsString('teamArcsSuspend(false);', $page);
    }

    public function testTheScriptLoadsBeforeThePagesOwnScriptSoItsLayersAreDeclaredWhenTheMenuAsksForThem(): void
    {
        $page = $this->source('war-room.php');
        $this->assertLessThan(
            strpos($page, 'let map = null, dispatchLayer'),
            strpos($page, 'war-room-team-arcs.js'),
            'MAP_OVERLAYS reads teamArcLayer; a let declared after the page script ran would still be in its dead zone'
        );
    }

    public function testEveryStringTheDrawingUsesExistsInBothLanguages(): void
    {
        $js = $this->source('assets/js/war-room-team-arcs.js');
        preg_match_all("/\bt\('(arc\.[a-z_]+)'/", $js, $m);
        $keys = array_unique($m[1]);
        $this->assertNotEmpty($keys);
        $keys[] = 'map.overlay_team_arcs';

        $strings = require __DIR__ . '/../includes/lang/war-room.php';
        foreach (['el', 'en'] as $lang) {
            foreach ($keys as $key) {
                $this->assertArrayHasKey($key, $strings[$lang], "$key is missing in $lang");
                $this->assertNotSame('', $strings[$lang][$key]);
            }
        }
        // A pair that has to differ between the languages, or one of them is the other's copy.
        $this->assertNotSame($strings['el']['arc.decimal_sep'], $strings['en']['arc.decimal_sep']);
        $this->assertNotSame($strings['el']['arc.minutes'], $strings['en']['arc.minutes']);
    }

    public function testTheMigrationAndTheInstallSchemaBothCreateTheTable(): void
    {
        $this->assertMatchesRegularExpression("/'version'\s*=>\s*201,.*?CREATE TABLE IF NOT EXISTS team_arc_state/s", $this->source('includes/migrations.php'));
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS `team_arc_state`', $this->source('sql/schema.sql'));
        $this->assertStringContainsString("define('DB_SCHEMA_VERSION', 201);", $this->source('config.php'));
    }

    /**
     * tests/bootstrap.php loads more than the real request chain does, so a
     * missing require passes here and fatals on the live site (it did, once,
     * for route-distance.php; see WalkingEtaStandaloneTest). This asks the
     * question in a process that loads only what bootstrap.php loads.
     */
    public function testTheArcCodeRunsWithOnlyTheRealBootstrapChainLoaded(): void
    {
        $this->assertStringContainsString("includes/functions-team-arcs.php", $this->source('bootstrap.php'));

        $root = realpath(__DIR__ . '/..');
        $script = <<<'PHP'
define('VOLUNTEEROPS', true);
define('DEBUG_MODE', true);
define('DB_HOST', getenv('TEST_DB_HOST') ?: 'localhost');
define('DB_PORT', getenv('TEST_DB_PORT') ?: '3306');
define('DB_NAME', getenv('TEST_DB_NAME') ?: 'volunteer_ops_test');
define('DB_USER', getenv('TEST_DB_USER') ?: 'root');
define('DB_PASS', getenv('TEST_DB_PASS') ?: '');
require_once $argv[1] . '/config.php';
require_once $argv[1] . '/includes/db.php';
require_once $argv[1] . '/includes/functions-core.php';
require_once $argv[1] . '/includes/functions-warroom.php';
require_once $argv[1] . '/includes/functions-team-arcs.php';
$targets = teamArcActiveTargets(0);
$arcs = teamArcsForMission(0, [['team_id' => 1, 'label' => 'X', 'color' => '#fff', 'lat' => 35.1, 'lng' => 25.1, 'ts' => time(), 'is_stale' => false]], true, null);
echo json_encode([$targets, $arcs]);
PHP;
        $file = tempnam(sys_get_temp_dir(), 'vo_arc_');
        file_put_contents($file, "<?php\n" . $script);
        try {
            $output = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file) . ' ' . escapeshellarg($root) . ' 2>&1');
        } finally {
            @unlink($file);
        }
        $this->assertStringNotContainsString('undefined function', $output, $output);
        $this->assertStringNotContainsString('Fatal error', $output, $output);
        $this->assertSame('[[],[]]', trim($output), $output);
    }
}
