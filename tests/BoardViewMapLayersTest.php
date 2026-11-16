<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Board view (v3.499.1) map layers beyond team GPS pins: search sectors,
 * hazard/restricted-area polygons, unresolved incident reports and points of
 * interest. Each wrapper in includes/functions-board-links.php reuses the
 * same loader war-room.php itself calls, then trims it for an
 * unauthenticated public screen - this file pins exactly what each trim
 * keeps and drops (see that file's docblocks for why).
 *
 * Runs inside a transaction that is always rolled back.
 */
final class BoardViewMapLayersTest extends TestCase
{
    private int $missionId;
    private int $userId;
    private int $areaId;

    protected function setUp(): void
    {
        db()->beginTransaction();
        $this->userId = (int) dbInsert(
            "INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, 'DEPARTMENT_ADMIN')",
            ['Συντονιστής Τεστ', 'bvl-' . uniqid('', true) . '@example.invalid', 'x']
        );
        $missionTypeId = (int) dbFetchValue("SELECT id FROM mission_types ORDER BY id LIMIT 1");
        $this->missionId = (int) dbInsert(
            "INSERT INTO missions (title, location, start_datetime, end_datetime, mission_type_id, status, show_in_ops, responsible_user_id) VALUES (?, ?, ?, ?, ?, ?, 1, ?)",
            ['BVL Mission', 'Ζαρός', date('Y-m-d H:i:s', time() - 7200), date('Y-m-d H:i:s', time() + 7200), $missionTypeId, STATUS_OPEN, $this->userId]
        );
        $this->areaId = (int) dbInsert(
            "INSERT INTO mission_search_areas (mission_id, label, geo, created_by) VALUES (?, 'Ζώνη Α', ?, ?)",
            [$this->missionId, json_encode([[35.0, 24.0], [35.1, 24.0], [35.1, 24.1]]), $this->userId]
        );
    }

    protected function tearDown(): void
    {
        db()->rollBack();
    }

    public function testSectorsKeepThePolygonAndDropStaffNames(): void
    {
        $teamId = (int) dbInsert(
            "INSERT INTO mission_teams (mission_id, codename, team_number, color, created_by) VALUES (?, 'Alpha', 1, '#ff0000', ?)",
            [$this->missionId, $this->userId]
        );
        $geo = [[35.0, 24.0], [35.1, 24.0], [35.1, 24.1]];
        dbInsert(
            "INSERT INTO mission_search_sectors (mission_id, area_id, team_id, label, geo, status, status_updated_by, created_by)
             VALUES (?, ?, ?, 'Α1', ?, 'in_progress', ?, ?)",
            [$this->missionId, $this->areaId, $teamId, json_encode($geo), $this->userId, $this->userId]
        );

        $sectors = loadBoardViewSectors($this->missionId);
        $this->assertCount(1, $sectors);
        $s = $sectors[0];
        // assertEquals, not assertSame: a whole-number coordinate round-trips
        // through json_encode/decode as a PHP int, not the float it started
        // as - harmless for a JS consumer (Leaflet only sees JSON numbers).
        $this->assertEquals($geo, $s['geo']);
        $this->assertSame('in_progress', $s['status']);
        $this->assertStringContainsString('Alpha', $s['team_label']);
        $this->assertArrayNotHasKey('status_updated_by_name', $s);
        $this->assertArrayNotHasKey('acknowledged_by_name', $s);
        $this->assertArrayNotHasKey('buildings', $s);
        $this->assertArrayNotHasKey('status_log', $s);
    }

    public function testRestrictedAreasPassThroughWithNoPersonalData(): void
    {
        $geo = [[35.2, 24.2], [35.21, 24.21]];
        dbInsert(
            "INSERT INTO mission_restricted_areas (mission_id, label, kind, shape, geo, created_by) VALUES (?, 'Γκρεμός', 'cliff', 'line', ?, ?)",
            [$this->missionId, json_encode($geo), $this->userId]
        );

        $areas = loadBoardViewRestrictedAreas($this->missionId);
        $this->assertCount(1, $areas);
        $this->assertSame('Γκρεμός', $areas[0]['label']);
        $this->assertSame('cliff', $areas[0]['kind']);
        $this->assertSame('line', $areas[0]['shape']);
        $this->assertEquals($geo, $areas[0]['geo']);
    }

    public function testIncidentsDropPatientAndReporterDetailsButKeepSeverityAndLocation(): void
    {
        dbInsert(
            "INSERT INTO mission_incidents (mission_id, reporter_id, lat, lng, incident_type, severity, patient_name, estimated_age, gender, phone, notes)
             VALUES (?, ?, 35.3, 24.5, 'trauma', 'critical', 'Παναγιώτης Παναγιωτόπουλος', '40', 'male', '6900000000', 'ιδιωτικό πλαίσιο')",
            [$this->missionId, $this->userId]
        );

        $incidents = loadBoardViewIncidents($this->missionId);
        $this->assertCount(1, $incidents);
        $i = $incidents[0];
        $this->assertSame(35.3, $i['lat']);
        $this->assertSame(24.5, $i['lng']);
        $this->assertSame('critical', $i['severity']);
        $this->assertArrayNotHasKey('patient_name', $i);
        $this->assertArrayNotHasKey('phone', $i);
        $this->assertArrayNotHasKey('notes', $i);
        $this->assertArrayNotHasKey('estimated_age', $i);
        $this->assertArrayNotHasKey('gender_label', $i);
        $this->assertArrayNotHasKey('reporter_name', $i);
        $this->assertArrayNotHasKey('home_team_name', $i);
    }

    public function testAnIncidentWithNoLocationIsLeftOffTheMap(): void
    {
        // patient_name set (not null) purely to avoid an unrelated, pre-existing
        // maskPatientName(null) TypeError in loadUnresolvedIncidentsForMission
        // when is_unknown_patient is left at its default 0 with no name - not
        // this test's concern, which is only the lat/lng null-filter.
        dbInsert(
            "INSERT INTO mission_incidents (mission_id, reporter_id, incident_type, severity, patient_name) VALUES (?, ?, 'other', 'low', 'Χ')",
            [$this->missionId, $this->userId]
        );

        $this->assertSame([], loadBoardViewIncidents($this->missionId));
    }

    public function testAResolvedIncidentDoesNotAppear(): void
    {
        dbInsert(
            "INSERT INTO mission_incidents (mission_id, reporter_id, lat, lng, incident_type, severity, patient_name, resolved_at)
             VALUES (?, ?, 35.3, 24.5, 'other', 'low', 'Χ', NOW())",
            [$this->missionId, $this->userId]
        );

        $this->assertSame([], loadBoardViewIncidents($this->missionId));
    }

    public function testPoiKeepsOnlyThePinAndCheckedState(): void
    {
        dbInsert(
            "INSERT INTO mission_points_of_interest (mission_id, lat, lng, checked_by, checked_at) VALUES (?, 35.4, 24.6, ?, NOW())",
            [$this->missionId, $this->userId]
        );
        dbInsert(
            "INSERT INTO mission_points_of_interest (mission_id, lat, lng) VALUES (?, 35.41, 24.61)",
            [$this->missionId]
        );

        $pois = loadBoardViewPoi($this->missionId);
        $this->assertCount(2, $pois);
        $byChecked = [];
        foreach ($pois as $p) {
            $byChecked[$p['checked'] ? 'checked' : 'open'] = $p;
        }
        $this->assertTrue($byChecked['checked']['checked']);
        $this->assertFalse($byChecked['open']['checked']);
        foreach ($pois as $p) {
            $this->assertArrayNotHasKey('reporter_names', $p);
            $this->assertArrayNotHasKey('photos', $p);
            $this->assertArrayNotHasKey('checked_by_name', $p);
        }
    }
}
