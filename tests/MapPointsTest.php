<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Imported map points on the Action Room map, v3.361.0.
 *
 * What these tests pin: the pasted-list reader (positional lines and a header
 * line, comma / semicolon / tab / space separators, a Greek decimal comma, a
 * quoted name with a comma, a bare altitude, access words in both languages, a
 * swapped latitude/longitude, and a bad line reported by number without
 * sinking the rest); the import (a re-pasted list adds nothing, the per-mission
 * cap, a default name); the edit and delete being scoped to their own mission;
 * and the version string changing with every change, since that is all the
 * poll carries.
 *
 * Runs inside a transaction that is always rolled back.
 */
final class MapPointsTest extends TestCase
{
    private int $missionId;
    private int $otherMissionId;
    private int $userId;

    protected function setUp(): void
    {
        db()->beginTransaction();
        $this->userId = (int) dbInsert(
            "INSERT INTO users (name, email, password) VALUES (?, ?, ?)",
            ['Συντονιστής Τεστ', 'mp-' . uniqid('', true) . '@example.invalid', 'x']
        );
        $missionTypeId = (int) dbFetchValue("SELECT id FROM mission_types ORDER BY id LIMIT 1");
        $make = fn(string $title) => (int) dbInsert(
            "INSERT INTO missions (title, location, start_datetime, end_datetime, mission_type_id, status, show_in_ops, responsible_user_id) VALUES (?, ?, ?, ?, ?, ?, 1, ?)",
            [$title, 'Ζαρός', date('Y-m-d H:i:s', time() - 7200), date('Y-m-d H:i:s', time() + 7200), $missionTypeId, STATUS_OPEN, $this->userId]
        );
        $this->missionId = $make('MP Mission');
        $this->otherMissionId = $make('MP Other Mission');
    }

    protected function tearDown(): void
    {
        db()->rollBack();
    }

    public function testPositionalLineWithEveryField(): void
    {
        $r = parseMapPointText("35.3387, 24.2155, Σπηλιά Αγίου, 540 μ., πεζή, Μονοπάτι 20 λεπτά\n");
        $this->assertSame([], $r['errors']);
        $this->assertCount(1, $r['rows']);
        $row = $r['rows'][0];
        $this->assertSame(35.3387, $row['lat']);
        $this->assertSame(24.2155, $row['lng']);
        $this->assertSame('Σπηλιά Αγίου', $row['name']);
        $this->assertSame(540, $row['elevation']);
        $this->assertSame('foot', $row['access']);
        $this->assertSame('Μονοπάτι 20 λεπτά', $row['note']);
    }

    public function testCoordinatesAloneNeedNoName(): void
    {
        $r = parseMapPointText("35.3387,24.2155");
        $this->assertCount(1, $r['rows']);
        $this->assertNull($r['rows'][0]['name']);
        $this->assertNull($r['rows'][0]['elevation']);
        $this->assertNull($r['rows'][0]['note']);
    }

    public function testBareAltitudeAfterTheCoordinatesIsNotAName(): void
    {
        $r = parseMapPointText("35.5,24.4,610");
        $this->assertNull($r['rows'][0]['name']);
        $this->assertSame(610, $r['rows'][0]['elevation']);
    }

    public function testSemicolonTabSpaceAndGreekDecimalCommaAreAllRead(): void
    {
        $text = "35.30;24.20;Βρύση;;όχημα;Δρόμος 4x4\n"
              . "35.4\t24.3\t\"Όνομα, με κόμμα\"\n"
              . "35,3412 24,2230 Πηγή\n"
              . "35.1 24.1 Στάνη, 300, και τα δύο\n";
        $r = parseMapPointText($text);
        $this->assertSame([], $r['errors']);
        $this->assertCount(4, $r['rows']);
        $this->assertSame('vehicle', $r['rows'][0]['access']);
        $this->assertSame('Δρόμος 4x4', $r['rows'][0]['note']);
        $this->assertSame('Όνομα, με κόμμα', $r['rows'][1]['name']);
        $this->assertSame(35.3412, $r['rows'][2]['lat']);
        $this->assertSame(24.223, $r['rows'][2]['lng']);
        $this->assertSame('Πηγή', $r['rows'][2]['name']);
        $this->assertSame('Στάνη', $r['rows'][3]['name']);
        $this->assertSame(300, $r['rows'][3]['elevation']);
        $this->assertSame('both', $r['rows'][3]['access']);
    }

    public function testHeaderLineMapsColumnsInAnyOrderInEitherLanguage(): void
    {
        $r = parseMapPointText("όνομα;μήκος;πλάτος;υψόμετρο;πρόσβαση;σημείωση\nΠηγή;24.2230;35.3412;610;Όχημα;Μόνο το καλοκαίρι\n");
        $this->assertSame([], $r['errors']);
        $this->assertCount(1, $r['rows']);
        $row = $r['rows'][0];
        $this->assertSame(35.3412, $row['lat']);
        $this->assertSame(24.223, $row['lng']);
        $this->assertSame('Πηγή', $row['name']);
        $this->assertSame(610, $row['elevation']);
        $this->assertSame('vehicle', $row['access']);
        $this->assertSame('Μόνο το καλοκαίρι', $row['note']);
    }

    public function testUnknownAccessWordIsKeptInTheNoteNotLost(): void
    {
        $r = parseMapPointText("lat,lng,name,access,note\n35.1,24.1,Α,μουλάρι,Στενό\n");
        $this->assertNull($r['rows'][0]['access']);
        $this->assertSame('μουλάρι · Στενό', $r['rows'][0]['note']);
    }

    public function testBadLinesAreReportedByNumberAndTheRestStillImport(): void
    {
        $text = "# a comment\n\n35.1,24.1,Καλό\nabc\n35.2,24.2,Ψηλό,99999\n0,0,Μηδέν\n35.3,24.3,Άλλο\n";
        $r = parseMapPointText($text);
        $this->assertSame(['Καλό', 'Άλλο'], array_column($r['rows'], 'name'));
        $this->assertSame([4, 5, 6], array_column($r['errors'], 'line'));
        $this->assertSame(['mp.err_line_coords', 'mp.err_line_elevation', 'mp.err_line_range'], array_column($r['errors'], 'reason'));
    }

    public function testLatitudeOverNinetyMeansTheTwoWereSwapped(): void
    {
        $r = parseMapPointText("120.5,35.5,Ανάποδα");
        $this->assertSame(35.5, $r['rows'][0]['lat']);
        $this->assertSame(120.5, $r['rows'][0]['lng']);
    }

    public function testByteOrderMarkFromExcelIsIgnored(): void
    {
        $r = parseMapPointText("\xEF\xBB\xBFlat,lng,name\n35.1,24.1,Α\n");
        $this->assertCount(1, $r['rows']);
        $this->assertSame('Α', $r['rows'][0]['name']);
    }

    public function testImportStoresThePointsAndAGivenNameWinsOverTheDefault(): void
    {
        $rows = parseMapPointText("35.1,24.1,Σπηλιά,540,πεζή,Σημείωση\n35.2,24.2\n")['rows'];
        $result = importMissionMapPoints($this->missionId, $rows, $this->userId);
        $this->assertSame(2, $result['added']);
        $this->assertSame(0, $result['duplicates']);

        $points = loadMissionMapPoints($this->missionId);
        $this->assertCount(2, $points);
        $byName = array_column($points, null, 'name');
        $this->assertSame(540, $byName['Σπηλιά']['elevation']);
        $this->assertSame('foot', $byName['Σπηλιά']['access']);
        $this->assertSame('Σημείωση', $byName['Σπηλιά']['note']);
        // The unnamed one got a default name rather than an empty one.
        $unnamed = array_values(array_filter($points, fn($p) => $p['name'] !== 'Σπηλιά'))[0];
        $this->assertNotSame('', $unnamed['name']);
        $this->assertNull($unnamed['elevation']);
    }

    public function testPastingTheSameListTwiceAddsNothing(): void
    {
        $rows = parseMapPointText("35.1,24.1,Α\n35.2,24.2,Β\n")['rows'];
        importMissionMapPoints($this->missionId, $rows, $this->userId);
        $again = importMissionMapPoints($this->missionId, $rows, $this->userId);
        $this->assertSame(0, $again['added']);
        $this->assertSame(2, $again['duplicates']);
        $this->assertCount(2, loadMissionMapPoints($this->missionId));
    }

    public function testTheSameNameElsewhereOnTheMissionIsAnotherPoint(): void
    {
        $rows = parseMapPointText("35.1,24.1,Πηγή\n35.9,24.9,Πηγή\n")['rows'];
        $this->assertSame(2, importMissionMapPoints($this->missionId, $rows, $this->userId)['added']);
    }

    public function testAMissionHoldsNoMoreThanTheCap(): void
    {
        $rows = [];
        for ($i = 0; $i < MAP_POINT_MAX_PER_MISSION; $i++) {
            $rows[] = ['lat' => 35.0 + $i / 100000, 'lng' => 24.0, 'name' => 'P' . $i, 'elevation' => null, 'access' => null, 'note' => null];
        }
        $this->assertSame(MAP_POINT_MAX_PER_MISSION, importMissionMapPoints($this->missionId, $rows, $this->userId)['added']);
        $more = importMissionMapPoints($this->missionId, [['lat' => 36.0, 'lng' => 25.0, 'name' => 'Έξτρα', 'elevation' => null, 'access' => null, 'note' => null]], $this->userId);
        $this->assertSame(0, $more['added']);
        $this->assertTrue($more['over_limit']);
    }

    public function testEditChangesOnlyThatPointAndOnlyOnItsOwnMission(): void
    {
        importMissionMapPoints($this->missionId, parseMapPointText("35.1,24.1,Α\n35.2,24.2,Β\n")['rows'], $this->userId);
        [$a, $b] = loadMissionMapPoints($this->missionId);

        $this->assertTrue(updateMissionMapPoint($this->missionId, $a['id'], 'Α νέο', 700, 'both', 'Νέα σημείωση', $this->userId));
        $after = array_column(loadMissionMapPoints($this->missionId), null, 'id');
        $this->assertSame('Α νέο', $after[$a['id']]['name']);
        $this->assertSame(700, $after[$a['id']]['elevation']);
        $this->assertSame('both', $after[$a['id']]['access']);
        $this->assertSame('Νέα σημείωση', $after[$a['id']]['note']);
        $this->assertSame($b['name'], $after[$b['id']]['name']);

        // The same id asked for through another mission is not found, and is untouched.
        $this->assertFalse(updateMissionMapPoint($this->otherMissionId, $a['id'], 'Hack', null, null, null, $this->userId));
        $this->assertFalse(deleteMissionMapPoint($this->otherMissionId, $a['id']));
        $this->assertSame('Α νέο', array_column(loadMissionMapPoints($this->missionId), null, 'id')[$a['id']]['name']);

        // An empty note clears it; nonsense access and altitude are dropped.
        $this->assertTrue(updateMissionMapPoint($this->missionId, $a['id'], 'Α νέο', 99999, 'helicopter', '', $this->userId));
        $cleared = array_column(loadMissionMapPoints($this->missionId), null, 'id')[$a['id']];
        $this->assertNull($cleared['note']);
        $this->assertNull($cleared['access']);
        $this->assertNull($cleared['elevation']);
    }

    public function testDeleteAndClear(): void
    {
        importMissionMapPoints($this->missionId, parseMapPointText("35.1,24.1,Α\n35.2,24.2,Β\n35.3,24.3,Γ\n")['rows'], $this->userId);
        importMissionMapPoints($this->otherMissionId, parseMapPointText("35.1,24.1,Α\n")['rows'], $this->userId);
        $points = loadMissionMapPoints($this->missionId);

        $this->assertTrue(deleteMissionMapPoint($this->missionId, $points[0]['id']));
        $this->assertFalse(deleteMissionMapPoint($this->missionId, $points[0]['id']));
        $this->assertCount(2, loadMissionMapPoints($this->missionId));

        $this->assertSame(2, clearMissionMapPoints($this->missionId));
        $this->assertSame([], loadMissionMapPoints($this->missionId));
        $this->assertCount(1, loadMissionMapPoints($this->otherMissionId));
    }

    public function testTheVersionChangesWithEveryChange(): void
    {
        $empty = missionMapPointsVersion($this->missionId);
        importMissionMapPoints($this->missionId, parseMapPointText("35.1,24.1,Α\n35.2,24.2,Β\n")['rows'], $this->userId);
        $two = missionMapPointsVersion($this->missionId);
        $this->assertNotSame($empty, $two);
        $this->assertSame($two, missionMapPointsVersion($this->missionId), 'asking again changes nothing');

        $points = loadMissionMapPoints($this->missionId);
        deleteMissionMapPoint($this->missionId, $points[0]['id']);
        $one = missionMapPointsVersion($this->missionId);
        $this->assertNotSame($two, $one);

        // An edit within the same second as the insert still has to show. The
        // stamp is whole seconds, so move it back to make the change visible.
        dbExecute("UPDATE mission_map_points SET updated_at = NULL WHERE mission_id = ?", [$this->missionId]);
        $before = missionMapPointsVersion($this->missionId);
        updateMissionMapPoint($this->missionId, $points[1]['id'], 'Β2', null, null, null, $this->userId);
        $this->assertNotSame($before, missionMapPointsVersion($this->missionId));
    }

    public function testDeletingTheMissionTakesItsPointsAlong(): void
    {
        importMissionMapPoints($this->missionId, parseMapPointText("35.1,24.1,Α\n")['rows'], $this->userId);
        dbExecute("DELETE FROM missions WHERE id = ?", [$this->missionId]);
        $this->assertSame(0, (int) dbFetchValue("SELECT COUNT(*) FROM mission_map_points WHERE mission_id = ?", [$this->missionId]));
    }

    public function testPointsComeBackInImportOrderNotByName(): void
    {
        importMissionMapPoints($this->missionId, parseMapPointText("35.1,24.1,Ωμέγα
35.2,24.2,Άλφα
35.3,24.3,Βήτα
")['rows'], $this->userId);
        $this->assertSame(['Ωμέγα', 'Άλφα', 'Βήτα'], array_column(loadMissionMapPoints($this->missionId), 'name'));
    }

    public function testLinkingFilterKeepsFootAndBothOrVehicleAndBoth(): void
    {
        importMissionMapPoints($this->missionId, parseMapPointText("lat,lng,name,access
35.1,24.1,P,foot
35.2,24.2,V,vehicle
35.3,24.3,B,both
35.4,24.4,N,
")['rows'], $this->userId);
        $this->assertSame(['P', 'V', 'B', 'N'], array_column(mapPointsForLinking($this->missionId, 'all'), 'name'));
        $this->assertSame(['P', 'B'], array_column(mapPointsForLinking($this->missionId, 'foot'), 'name'));
        $this->assertSame(['V', 'B'], array_column(mapPointsForLinking($this->missionId, 'vehicle'), 'name'));
    }

    /** A stand-in for Google: every leg answers with a two-point shape, except the keys in $noRoute. */
    private function fakeRouter(array $noRoute = [], array &$seen = []): callable
    {
        return function (array $jobs, ?array &$failed) use ($noRoute, &$seen) {
            $failed = [];
            $out = [];
            foreach ($jobs as $key => $job) {
                $seen[$key] = $job;
                if (in_array($key, $noRoute, true)) {
                    continue;
                }
                [$a, $b, $c, $d] = $job['leg'];
                $out[$key] = ['meters' => 1000, 'minutes' => 10, 'points' => [[$a, $b], [$c, $d]], 'source' => 'google', 'mode' => $job['provider']['mode']];
            }
            return $out;
        };
    }

    public function testLinkingRoutesEachPointToTheNextInListOrder(): void
    {
        importMissionMapPoints($this->missionId, parseMapPointText("35.1,24.1,A
35.2,24.2,B
35.3,24.3,C
")['rows'], $this->userId);
        $points = loadMissionMapPoints($this->missionId);
        $seen = [];
        $r = mapPointsLinkLegs($points, 'foot', 'KEY', $this->fakeRouter([], $seen));

        $this->assertCount(2, $r['legs']);
        $this->assertSame([$points[0]['id'], $points[1]['id']], [$r['legs'][0]['from_id'], $r['legs'][0]['to_id']]);
        $this->assertSame([$points[1]['id'], $points[2]['id']], [$r['legs'][1]['from_id'], $r['legs'][1]['to_id']]);
        $this->assertSame(2000, $r['meters']);
        $this->assertSame(20, $r['minutes']);
        $this->assertSame(0, $r['unrouted']);
        $this->assertSame('walking', $seen['leg:0']['provider']['mode']);
        $this->assertSame('google', $seen['leg:0']['provider']['name']);
        $this->assertTrue($seen['leg:0']['geometry']);
        $this->assertSame([35.1, 24.1, 35.2, 24.2], $seen['leg:0']['leg']);

        $seen = [];
        mapPointsLinkLegs($points, 'vehicle', 'KEY', $this->fakeRouter([], $seen));
        $this->assertSame('driving', $seen['leg:0']['provider']['mode']);
    }

    public function testALegWithNoRouteIsCountedNotDrawnAsAStraightLine(): void
    {
        importMissionMapPoints($this->missionId, parseMapPointText("35.1,24.1,A
35.2,24.2,B
35.3,24.3,C
")['rows'], $this->userId);
        $r = mapPointsLinkLegs(loadMissionMapPoints($this->missionId), 'foot', 'KEY', $this->fakeRouter(['leg:0']));
        $this->assertSame(1, $r['unrouted']);
        $this->assertNull($r['legs'][0]['points']);
        $this->assertNull($r['legs'][0]['meters']);
        $this->assertNotNull($r['legs'][1]['points']);
        $this->assertSame(1000, $r['meters']);
    }

    public function testLinkingStopsAtTwentyFivePoints(): void
    {
        $rows = [];
        for ($i = 0; $i < 40; $i++) {
            $rows[] = ['lat' => 35.0 + $i / 1000, 'lng' => 24.0, 'name' => 'P' . $i, 'elevation' => null, 'access' => null, 'note' => null];
        }
        importMissionMapPoints($this->missionId, $rows, $this->userId);
        $r = mapPointsLinkLegs(loadMissionMapPoints($this->missionId), 'foot', 'KEY', $this->fakeRouter());
        $this->assertSame(MAP_POINT_LINK_MAX - 1, count($r['legs']));
        $this->assertSame(25, MAP_POINT_LINK_MAX);
    }

    public function testNothingToConnectWithFewerThanTwoPoints(): void
    {
        importMissionMapPoints($this->missionId, parseMapPointText("35.1,24.1,A
")['rows'], $this->userId);
        $r = mapPointsLinkLegs(loadMissionMapPoints($this->missionId), 'foot', 'KEY', $this->fakeRouter());
        $this->assertSame([], $r['legs']);
        $this->assertSame(0, $r['unrouted']);
    }
}
