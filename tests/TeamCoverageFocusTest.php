<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * «Κάλυψη ομάδων» and «Εστίαση σε ομάδα» (v3.440.0): where each team has really been, kept apart per team on the
 * same idea of «seen» as Verified Coverage and the heat layer; and looking at one team's orders only.
 *
 * The first block runs inside a transaction that is always rolled back; the rest reads the source, because the
 * drawing and the menu are browser code.
 */
final class TeamCoverageFocusTest extends TestCase
{
    private const LAT0 = 35.2000;
    private const LNG0 = 24.9000;
    private const M_PER_DEG_LAT = 110941.0;
    private const M_PER_DEG_LNG = 91288.0;

    private int $missionId;
    private int $shiftId;
    private int $teamA;
    private int $teamB;
    private int $teamC;
    private int $userA;
    private int $userB;
    private int $userNoTeam;

    protected function setUp(): void
    {
        db()->beginTransaction();
        $admin = $this->makeUser('Cov Admin');
        $typeId = (int) dbFetchValue("SELECT id FROM mission_types ORDER BY id LIMIT 1");
        $this->missionId = (int) dbInsert(
            "INSERT INTO missions (title, location, start_datetime, end_datetime, mission_type_id, status, show_in_ops, responsible_user_id)
             VALUES (?, ?, ?, ?, ?, 'OPEN', 1, ?)",
            ['Cov Mission', 'Ηράκλειο', date('Y-m-d H:i:s', time() - 7200), date('Y-m-d H:i:s', time() + 7200), $typeId, $admin]
        );
        $this->shiftId = (int) dbInsert(
            "INSERT INTO shifts (mission_id, start_time, end_time) VALUES (?, ?, ?)",
            [$this->missionId, date('Y-m-d H:i:s', time() - 3600), date('Y-m-d H:i:s', time() + 3600)]
        );
        $this->teamA = $this->makeTeam('ΑΛΦΑ', 1, '#e34948', $admin);
        $this->teamB = $this->makeTeam('ΒΗΤΑ', 2, '#2a78d6', $admin);
        $this->teamC = $this->makeTeam('ΓΑΜΑ', 3, '#198754', $admin);   // nobody in it
        $this->userA = $this->makeUser('Cov A');
        $this->userB = $this->makeUser('Cov B');
        $this->userNoTeam = $this->makeUser('Cov Alone');
        $this->join($this->teamA, $this->userA);
        $this->join($this->teamB, $this->userB);
    }

    protected function tearDown(): void
    {
        db()->rollBack();
    }

    private function makeUser(string $name): int
    {
        return (int) dbInsert("INSERT INTO users (name, email, password) VALUES (?, ?, ?)", [$name, 'cov-' . uniqid('', true) . '@example.invalid', 'x']);
    }

    private function makeTeam(string $codename, int $number, string $color, int $admin): int
    {
        return (int) dbInsert(
            "INSERT INTO mission_teams (mission_id, codename, team_number, color, created_by) VALUES (?, ?, ?, ?, ?)",
            [$this->missionId, $codename, $number, $color, $admin]
        );
    }

    private function join(int $teamId, int $userId): void
    {
        dbInsert("INSERT INTO mission_team_members (team_id, mission_id, user_id) VALUES (?, ?, ?)", [$teamId, $this->missionId, $userId]);
    }

    /** A walk east: 100 m at 1 m/s from (x0, y0) metres off the corner, a fix every 10 s, starting $minutesAgo minutes ago. */
    private function walk(int $userId, float $x0, float $y0, int $minutesAgo = 30): void
    {
        $start = time() - $minutesAgo * 60;
        for ($s = 0; $s <= 100; $s += 10) {
            dbInsert(
                "INSERT INTO volunteer_pings (user_id, shift_id, lat, lng, accuracy_meters, source, created_at) VALUES (?, ?, ?, ?, ?, 'auto', ?)",
                [$userId, $this->shiftId, self::LAT0 + $y0 / self::M_PER_DEG_LAT, self::LNG0 + ($x0 + $s) / self::M_PER_DEG_LNG, 5.0, date('Y-m-d H:i:s', $start + $s)]
            );
        }
    }

    /** @return array{id: int, label: string, color: string, cells: array, cell_m: int, people: int, fixes: int} */
    private function team(array $coverage, int $teamId): array
    {
        foreach ($coverage['teams'] as $team) {
            if ($team['id'] === $teamId) {
                return $team;
            }
        }
        $this->fail('team ' . $teamId . ' is not in the answer');
    }

    private function metresFromWalk(array $cell, float $x0, float $y0): float
    {
        // Distance from a cell centre to the 100 m segment of walk().
        $cx = ($cell[1] - self::LNG0) * self::M_PER_DEG_LNG;
        $cy = ($cell[0] - self::LAT0) * self::M_PER_DEG_LAT;
        $px = max($x0, min($x0 + 100, $cx));
        return hypot($cx - $px, $cy - $y0);
    }

    // ── What the server says ────────────────────────────────────────────────

    public function testEachTeamsGroundIsItsOwn(): void
    {
        $this->walk($this->userA, 0, 0);
        $this->walk($this->userB, 2000, 0);   // two kilometres east of the first

        $coverage = computeMissionTeamCoverage($this->missionId);
        $a = $this->team($coverage, $this->teamA);
        $b = $this->team($coverage, $this->teamB);

        $this->assertNotEmpty($a['cells']);
        $this->assertNotEmpty($b['cells']);
        foreach ($a['cells'] as $cell) {
            $this->assertLessThan(120, $this->metresFromWalk($cell, 0, 0), 'a cell of team A lies where A walked');
        }
        foreach ($b['cells'] as $cell) {
            $this->assertLessThan(120, $this->metresFromWalk($cell, 2000, 0), 'a cell of team B lies where B walked');
        }
        $this->assertSame(1, $a['people']);
        $this->assertSame(11, $a['fixes']);
    }

    public function testEveryCellIsJustALatitudeAndALongitude(): void
    {
        $this->walk($this->userA, 0, 0);
        $a = $this->team(computeMissionTeamCoverage($this->missionId), $this->teamA);
        foreach ($a['cells'] as $cell) {
            $this->assertCount(2, $cell, 'the seconds are the heat layer\'s business, not this layer\'s');
            $this->assertIsFloat($cell[0]);
            $this->assertIsFloat($cell[1]);
        }
    }

    public function testSomeoneInNoTeamBelongsToNoOne(): void
    {
        $this->walk($this->userNoTeam, 5000, 5000);

        $coverage = computeMissionTeamCoverage($this->missionId);
        foreach ($coverage['teams'] as $team) {
            $this->assertSame([], $team['cells'], 'their ground is on the heat layer, not on a team');
        }
    }

    public function testATeamWithNoFixesIsStillThereWithItsColour(): void
    {
        $coverage = computeMissionTeamCoverage($this->missionId);
        $this->assertCount(3, $coverage['teams'], 'every team of the mission, so the page can offer it');

        $c = $this->team($coverage, $this->teamC);
        $this->assertSame([], $c['cells']);
        $this->assertSame(COVERAGE_HEAT_CELL_M, $c['cell_m']);
        $this->assertSame('#198754', $c['color']);
        $this->assertSame(teamLabel('ΓΑΜΑ', 3), $c['label']);
        $this->assertSame('#e34948', $this->team($coverage, $this->teamA)['color']);
    }

    public function testAPersonsWholeWalkMovesWithThemToTheirNewTeam(): void
    {
        $this->walk($this->userA, 0, 0);
        dbExecute("UPDATE mission_team_members SET team_id = ? WHERE mission_id = ? AND user_id = ?", [$this->teamB, $this->missionId, $this->userA]);

        $coverage = computeMissionTeamCoverage($this->missionId);
        $this->assertSame([], $this->team($coverage, $this->teamA)['cells']);
        $this->assertNotEmpty($this->team($coverage, $this->teamB)['cells']);
    }

    public function testThePerTeamSignatureNoticesMoreThanThePingsDo(): void
    {
        $this->walk($this->userA, 0, 0);
        $heat = coverageHeatSignature($this->missionId);
        $team = coverageTeamSignature($this->missionId);
        $this->assertStringStartsWith($heat . '-t', $team, 'it is the heat signature, plus who belongs where');
        $this->assertSame($team, coverageTeamSignature($this->missionId), 'asking twice is the same answer');

        dbExecute("UPDATE mission_team_members SET team_id = ? WHERE mission_id = ? AND user_id = ?", [$this->teamB, $this->missionId, $this->userA]);
        $this->assertSame($heat, coverageHeatSignature($this->missionId), 'no ping came, so the heat layer has nothing new');
        $this->assertNotSame($team, coverageTeamSignature($this->missionId), 'but a team layer built before the move is out of date');

        $before = coverageTeamSignature($this->missionId);
        dbExecute("UPDATE mission_teams SET color = '#000000' WHERE id = ?", [$this->teamA]);
        $this->assertNotSame($before, coverageTeamSignature($this->missionId), 'and so is one painted in a colour the team no longer has');
    }

    public function testTheAnswerIsTheSignatureOfWhatItWasMadeFrom(): void
    {
        $this->walk($this->userA, 0, 0);
        $this->assertSame(coverageTeamSignature($this->missionId), computeMissionTeamCoverage($this->missionId)['sig']);
    }

    // ── What the endpoint and the page do with it ───────────────────────────

    private function source(string $file): string
    {
        return str_replace("\r\n", "\n", (string) file_get_contents(__DIR__ . '/../' . $file));
    }

    public function testTheEndpointAnswersPerTeamBehindTheSameGateAndTheSameUnchangedLine(): void
    {
        $src = $this->source('mission-coverage-heat.php');
        $this->assertStringContainsString("(string) get('by') === 'team'", $src);
        $this->assertStringContainsString('coverageTeamSignature($missionId)', $src);
        $this->assertStringContainsString('computeMissionTeamCoverage($missionId)', $src);
        $this->assertLessThan(strpos($src, 'coverageTeamSignature'), strpos($src, 'canManageActionRoom('), 'the gate comes first');
        $this->assertLessThan(strpos($src, 'computeMissionTeamCoverage'), strpos($src, "'unchanged' => true"), 'and the cheap answer before the work');
    }

    public function testThePageLoadsTheScriptAndStartsItForCommandBeforeTheFirstDrawing(): void
    {
        $page = $this->source('war-room.php');
        $this->assertStringContainsString('assets/js/war-room-team-coverage.js', $page);
        $done = strpos($page, 'wrDoneInit(<?= (int) $missionId ?>, MAP_LAYER_TOOLS_ENABLED);');
        $cov = strpos($page, 'wrCovInit(<?= (int) $missionId ?>, MAP_LAYER_TOOLS_ENABLED);');
        $first = strpos($page, 'renderPins(pins); renderDispatches(dispatches);');
        $this->assertNotFalse($done);
        $this->assertNotFalse($cov);
        $this->assertLessThan($cov, $done, 'the menu section goes in after the one it follows');
        $this->assertLessThan($first, $cov, 'a focus that came back from the session is in force before anything is drawn');
        $this->assertStringContainsString("'color_bg' => teamBadgeColors(", $page, 'the chips wear the colour of their team');
    }

    public function testOnlyCommandHasTheLayerOrTheFocus(): void
    {
        $js = $this->source('assets/js/war-room-team-coverage.js');
        $this->assertStringContainsString('if (!canChoose || typeof map === \'undefined\' || !map) return;', $js);
        // Nothing before that line may set a focus or turn the layer on.
        $init = substr($js, strpos($js, 'function wrCovInit'));
        $this->assertLessThan(strpos($init, 'wrFocusTeam = stored'), strpos($init, 'if (!canChoose'));
        $this->assertLessThan(strpos($init, 'wrCovOn = localStorage'), strpos($init, 'if (!canChoose'));
    }

    public function testAnOrderOfNoSingleTeamIsNeverFocusedAway(): void
    {
        $js = $this->source('assets/js/war-room-team-coverage.js');
        $this->assertMatchesRegularExpression(
            '/function wrFocusExcludes\(teamId\) \{\s*return wrFocusTeam !== null && teamId !== null && teamId !== undefined && String\(teamId\) !== String\(wrFocusTeam\);/',
            $js
        );
    }

    public function testTheFocusIsTheSameHiddenTheFinishedOrdersUse(): void
    {
        $done = $this->source('assets/js/war-room-done-orders.js');
        $this->assertStringContainsString('function wrDonePhase(ts, teamId, kind) {', $done);
        $this->assertStringContainsString("if (wrDoneFocusHides(teamId)) return 'hidden';", $done);
        $this->assertStringContainsString("if (wrDoneFocusHides(route.team_id)) return 'hidden';", $done);
        $this->assertStringContainsString('wrDonePhase(i.completed_ts, i.team_id, kind)', $done, 'so the redraw signature changes with the focus');
        $this->assertStringContainsString('wrDoneFocusHides(d.team_id)', $done, 'and the counter lists only what is on the map');

        $page = $this->source('war-room.php');
        $this->assertSame(1, substr_count($page, "wrDonePhase(item.completed_ts, item.team_id, 'dispatch')"), 'a pin');
        $this->assertSame(1, substr_count($page, "wrDonePhase(item.completed_ts, item.team_id, 'sector')"), 'and a sector');
        $this->assertStringContainsString("if (wrFocusTeam !== null) hidden.push(t('map.focus_hidden_item', {team: wrFocusLabel()}));", $page, 'never silent');
        $this->assertStringContainsString('wrFocusSet(null);', $page, '«Εμφάνιση όλων» ends it');

        $arcs = $this->source('assets/js/war-room-team-arcs.js');
        $this->assertStringContainsString('wrFocusExcludes(arc.team_id)', $arcs);
    }

    public function testTheLayerIsAskedForOnlyWhileItIsOnAndOnlyForCommand(): void
    {
        $js = $this->source('assets/js/war-room-team-coverage.js');
        $this->assertStringContainsString("'&by=team&sig='", $js);
        $this->assertStringContainsString('if (!wrCovOn || wrCovBusy) return;', $js);
        $this->assertStringContainsString('if (!document.hidden) wrCovLoad();', $js);
        $this->assertStringContainsString("localStorage.getItem(WR_COV_KEY) === 'on'", $js, 'off until asked for');
    }

    public function testEveryWordIsTranslatedBothWays(): void
    {
        $strings = loadLangStrings('war-room');
        $keys = ['map.cov_header', 'map.cov_toggle', 'map.cov_sum_gps', 'map.cov_focus_label', 'map.cov_focus_all', 'map.cov_hint',
                 'map.cov_none', 'map.cov_failed', 'map.focus_hidden_item'];
        foreach (['el', 'en'] as $lang) {
            foreach ($keys as $key) {
                $this->assertArrayHasKey($key, $strings[$lang], $key . ' in ' . $lang);
                $this->assertNotSame('', trim($strings[$lang][$key]), $key . ' in ' . $lang);
            }
            $this->assertStringContainsString('{team}', $strings[$lang]['map.focus_hidden_item']);
        }
    }
}
