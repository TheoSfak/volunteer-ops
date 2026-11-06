<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * A restricted zone drawn as an open LINE along an edge (v3.419.0), such as a cliff.
 *
 * A line has no inside, so the breach alarm cannot be "a fix inside the shape". It is
 * "a fix within RESTRICTED_LINE_BREACH_M (10 m) of the line", checked by the same
 * function that checks the polygons, opening and closing the same breach rows. What
 * must not change: a polygon zone behaves exactly as before, and a zone drawn before
 * the column existed is a polygon.
 *
 * checkRestrictedAreaBreach() opens a transaction of its own, so these tests cannot wrap
 * themselves in one: they commit, and tearDown deletes what they made (the mission takes
 * its shift, requests, zones and breaches with it).
 */
final class RestrictedAreaLineTest extends TestCase
{
    private const LAT = 35.2000000;
    private const LNG = 24.9000000;

    private int $missionId;
    private int $userId;
    private int $prId;

    protected function setUp(): void
    {
        $missionTypeId = (int) dbFetchValue("SELECT id FROM mission_types ORDER BY id LIMIT 1");
        $this->missionId = (int) dbInsert(
            "INSERT INTO missions (title, location, start_datetime, end_datetime, mission_type_id, status, show_in_ops) VALUES (?, ?, ?, ?, ?, ?, 1)",
            ['Line Zone Mission', 'Ηράκλειο', date('Y-m-d H:i:s', time() - 7200), date('Y-m-d H:i:s', time() + 7200), $missionTypeId, STATUS_OPEN]
        );
        $shiftId = (int) dbInsert(
            "INSERT INTO shifts (mission_id, start_time, end_time) VALUES (?, ?, ?)",
            [$this->missionId, date('Y-m-d H:i:s', time() - 3600), date('Y-m-d H:i:s', time() + 3600)]
        );
        $this->userId = (int) dbInsert(
            "INSERT INTO users (name, email, password) VALUES (?, ?, ?)",
            ['Line Walker', 'line-' . uniqid('', true) . '@example.invalid', 'x']
        );
        $this->prId = (int) dbInsert(
            "INSERT INTO participation_requests (shift_id, volunteer_id, status) VALUES (?, ?, ?)",
            [$shiftId, $this->userId, PARTICIPATION_APPROVED]
        );
    }

    protected function tearDown(): void
    {
        dbExecute("DELETE FROM notifications WHERE user_id = ?", [$this->userId]);
        dbExecute("DELETE FROM missions WHERE id = ?", [$this->missionId]);
        dbExecute("DELETE FROM users WHERE id = ?", [$this->userId]);
    }

    /** About 180 m of edge running east from (LAT, LNG). */
    private function edge(string $shape = 'line', string $kind = 'cliff'): int
    {
        return (int) dbInsert(
            "INSERT INTO mission_restricted_areas (mission_id, label, kind, shape, geo, created_at) VALUES (?, ?, ?, ?, ?, NOW())",
            [$this->missionId, 'Άκρη φαραγγιού', $kind, $shape, json_encode([[self::LAT, self::LNG], [self::LAT, self::LNG + 0.001], [self::LAT, self::LNG + 0.002]])]
        );
    }

    /** A fix this many metres north of the edge, above its middle. */
    private function fixNorthOfEdge(float $metres): array
    {
        return [self::LAT + $metres / 111190, self::LNG + 0.001];
    }

    private function openBreaches(): int
    {
        return (int) dbFetchValue(
            "SELECT COUNT(*) FROM mission_restricted_area_breaches WHERE mission_id = ? AND user_id = ? AND exited_at IS NULL AND resolved_at IS NULL",
            [$this->missionId, $this->userId]
        );
    }

    private function ping(float $lat, float $lng): void
    {
        checkRestrictedAreaBreach($this->missionId, $this->userId, null, $this->prId, $lat, $lng, 'manual', 5.0);
    }

    public function testTheDistanceToAnOpenLineIsTheDistanceToItsNearestSegmentAndItDoesNotClose(): void
    {
        $geo = [[self::LAT, self::LNG], [self::LAT, self::LNG + 0.001]];
        [$lat, $lng] = $this->fixNorthOfEdge(25);
        $this->assertEqualsWithDelta(25.0, pointToPolylineDistanceMeters($lat, self::LNG + 0.0005, $geo), 0.6);
        // Past the end: the distance is to the end point, and the two ends are NOT joined (a polygon would be 0 inside).
        $beyond = pointToPolylineDistanceMeters(self::LAT, self::LNG + 0.002, $geo);
        $this->assertEqualsWithDelta(91.0, $beyond, 2.0);
    }

    public function testSomeoneWithinTenMetresOfALineIsInBreachAndSomeoneFurtherIsNot(): void
    {
        $this->edge();
        [$nearLat, $nearLng] = $this->fixNorthOfEdge(6);
        $this->ping($nearLat, $nearLng);
        $this->assertSame(1, $this->openBreaches(), '6 m from the edge is a breach');

        // Walking away to 40 m closes it (a trustworthy fix).
        [$farLat, $farLng] = $this->fixNorthOfEdge(40);
        $this->ping($farLat, $farLng);
        $this->assertSame(0, $this->openBreaches(), '40 m away is not');
    }

    public function testAFixThirtyMetresFromALineRaisesNothing(): void
    {
        $this->edge();
        [$lat, $lng] = $this->fixNorthOfEdge(30);
        $this->ping($lat, $lng);
        $this->assertSame(0, $this->openBreaches());
    }

    public function testAPolygonZoneStillBreachesOnlyInsideIt(): void
    {
        // The same three points as a polygon: a triangle too thin to stand in. 6 m north of the middle of its base is outside.
        $this->edge('polygon', 'danger');
        [$lat, $lng] = $this->fixNorthOfEdge(6);
        $this->ping($lat, $lng);
        $this->assertSame(0, $this->openBreaches(), 'a polygon is a breach only inside it, however close');
    }

    public function testTheLoaderCarriesTheShapeAndAZoneWithoutOneIsAPolygon(): void
    {
        $this->edge();
        dbInsert("INSERT INTO mission_restricted_areas (mission_id, label, geo, created_at) VALUES (?, ?, ?, NOW())", [$this->missionId, 'Παλιά', json_encode([[35.2, 24.9], [35.2, 24.901], [35.201, 24.9]])]);
        $shapes = array_column(loadMissionRestrictedAreasForUser($this->missionId), 'shape', 'label');
        $this->assertSame(['Άκρη φαραγγιού' => 'line', 'Παλιά' => 'polygon'], $shapes);
    }

    public function testTheEndpointChecksTheShapeAndTheNumberOfPoints(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../mission-restricted-area.php');
        $this->assertStringContainsString("in_array(\$shape, ['polygon', 'line'], true)", $src);
        $this->assertStringContainsString("count(\$rawGeo) < (\$shape === 'line' ? 2 : 3)", $src);
        $this->assertStringContainsString('INSERT INTO mission_restricted_areas (mission_id, label, kind, shape, geo, created_by, created_at)', $src);
    }

    public function testTheSchemaTheMigrationAndTheExportAgree(): void
    {
        $this->assertGreaterThanOrEqual(196, DB_SCHEMA_VERSION);
        $this->assertStringContainsString("`shape` ENUM('polygon','line') NOT NULL DEFAULT 'polygon'", (string) file_get_contents(__DIR__ . '/../sql/schema.sql'));
        $this->assertStringContainsString("ADD COLUMN shape ENUM('polygon','line') NOT NULL DEFAULT 'polygon' AFTER kind", (string) file_get_contents(__DIR__ . '/../includes/migrations.php'));
        $this->assertStringContainsString("\$isLine ? 'Line' : 'Polygon'", (string) file_get_contents(__DIR__ . '/../exports/export-mission-geo.php'));
    }

    public function testTheComposerMapAndAIKnowTheLine(): void
    {
        $page = (string) WarRoomSource::full();
        $this->assertStringContainsString('id="restrictedAreaShapeSelect"', $page);
        $this->assertStringContainsString('<option value="line">', $page);
        $this->assertStringContainsString('shape: shapeSelect.value,', $page);
        $this->assertStringContainsString("sendBtn.disabled = isLine() ? drawPoints.length < 2 : !(isClosed && drawPoints.length >= 3);", $page);
        $this->assertStringContainsString("if (item.shape === 'line') {", $page);
        $this->assertStringContainsString("pointToPolylineDistanceMeters((float) \$myPing['lat']", $page);
        $ar = (string) file_get_contents(__DIR__ . '/../assets/js/war-room-ar.js');
        $this->assertStringContainsString("const AR_LINE_BREACH_M = 10;", $ar);
        $this->assertStringContainsString("arNearestOnPolygon(me, a.geo, line)", $ar);
        $this->assertStringContainsString("const RESTRICTED_LINE_BREACH_M = 10;", (string) file_get_contents(__DIR__ . '/../includes/functions-warroom.php'));
    }

    public function testEveryNewStringExistsInBothLanguages(): void
    {
        $lang = (string) file_get_contents(__DIR__ . '/../includes/lang/war-room.php');
        foreach (['shape_label', 'shape_polygon', 'shape_line', 'shape_line_short', 'line_length', 'line_needs_2_points', 'map_instructions_line', 'proximity_at_line'] as $key) {
            $this->assertSame(2, substr_count($lang, "'restricted_area.$key' =>"), "restricted_area.$key in both languages");
        }
    }
}
