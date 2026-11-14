<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Fleet management, M3 (v3.484.0): refuelling and the maintenance cost log,
 * in includes/fleet-functions.php. Opening a maintenance record moves a
 * vehicle to 'maintenance' (unless it is already 'out_of_service' - a
 * deliberate retirement no maintenance ticket, automatic or not, should
 * silently reverse); closing the LAST open one for that vehicle returns it
 * to 'available'. A handover checkin flagging damage (M2) must now open a
 * real vehicle_maintenance row rather than only flipping a status flag.
 *
 * Requires includes/fleet-functions.php itself rather than leaning on
 * tests/bootstrap.php's preload list - see FleetVehicleAssignmentTest's
 * docblock / feedback_test_bootstrap_hides_missing_requires.
 */
final class FleetMaintenanceTest extends TestCase
{
    private static int $userId;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap.php';
        require_once __DIR__ . '/../includes/fleet-functions.php';

        self::$userId = (int) dbInsert(
            "INSERT INTO users (name, email, password) VALUES (?, ?, ?)",
            ['Fleet Maintenance Test User', 'fleet-maintenance-test@example.invalid', password_hash('x', PASSWORD_DEFAULT)]
        );
    }

    public static function tearDownAfterClass(): void
    {
        dbExecute("DELETE FROM users WHERE id = ?", [self::$userId]);
    }

    protected function tearDown(): void
    {
        dbExecute("DELETE FROM vehicles WHERE plate_number LIKE 'TESTMX-%'");
        dbExecute("DELETE FROM vehicle_checklist_items WHERE label LIKE 'TESTMX %'");
    }

    private function makeVehicle(string $plate, string $status = 'available'): int
    {
        return (int) dbInsert(
            "INSERT INTO vehicles (plate_number, label, type, status) VALUES (?, ?, 'car', ?)",
            [$plate, 'Maintenance test vehicle ' . $plate, $status]
        );
    }

    public function testOpeningMaintenanceMovesAnAvailableVehicleToMaintenance(): void
    {
        $vehicleId = $this->makeVehicle('TESTMX-OPEN');

        $result = openMaintenance($vehicleId, 'repair', 'test repair', 50.0, 'Garage X', 1200.0, self::$userId);

        $this->assertTrue($result['success']);
        $this->assertSame('maintenance', dbFetchValue("SELECT status FROM vehicles WHERE id = ?", [$vehicleId]));
        $this->assertNotEmpty(getOpenMaintenanceRecords($vehicleId));
    }

    public function testOpeningMaintenanceNeverUnRetiresOutOfService(): void
    {
        $vehicleId = $this->makeVehicle('TESTMX-OOS', 'out_of_service');

        openMaintenance($vehicleId, 'repair', 'test', null, null, null, self::$userId);

        $this->assertSame('out_of_service', dbFetchValue("SELECT status FROM vehicles WHERE id = ?", [$vehicleId]));
    }

    public function testClosingTheLastOpenRecordReturnsVehicleToAvailable(): void
    {
        $vehicleId = $this->makeVehicle('TESTMX-CLOSE');
        $result = openMaintenance($vehicleId, 'repair', 'test', null, null, null, self::$userId);

        $closeResult = closeMaintenance($result['maintenance_id'], self::$userId, 'done', 75.50);

        $this->assertTrue($closeResult['success']);
        $this->assertSame('available', dbFetchValue("SELECT status FROM vehicles WHERE id = ?", [$vehicleId]));
        $this->assertSame(75.5, (float) dbFetchValue("SELECT cost FROM vehicle_maintenance WHERE id = ?", [$result['maintenance_id']]));
    }

    public function testClosingOneOfTwoOpenRecordsLeavesVehicleInMaintenance(): void
    {
        $vehicleId = $this->makeVehicle('TESTMX-TWO');
        $first = openMaintenance($vehicleId, 'repair', 'first', null, null, null, self::$userId);
        $second = openMaintenance($vehicleId, 'inspection', 'second', null, null, null, self::$userId);

        closeMaintenance($first['maintenance_id'], self::$userId, null, null);

        $this->assertSame('maintenance', dbFetchValue("SELECT status FROM vehicles WHERE id = ?", [$vehicleId]), 'still one open record left');
        $this->assertCount(1, getOpenMaintenanceRecords($vehicleId));
    }

    public function testClosingAnAlreadyClosedRecordFails(): void
    {
        $vehicleId = $this->makeVehicle('TESTMX-DOUBLECLOSE');
        $result = openMaintenance($vehicleId, 'repair', 'test', null, null, null, self::$userId);
        closeMaintenance($result['maintenance_id'], self::$userId, null, null);

        $second = closeMaintenance($result['maintenance_id'], self::$userId, null, null);

        $this->assertFalse($second['success']);
    }

    public function testAddingARefuelUpdatesOdometerAndIsRetrievable(): void
    {
        $vehicleId = $this->makeVehicle('TESTMX-REFUEL');

        addRefuel($vehicleId, 35.5, 48.20, 2000.0, 'Shell Ηράκλειο', self::$userId);

        $this->assertSame(2000.0, (float) dbFetchValue("SELECT odometer_value FROM vehicles WHERE id = ?", [$vehicleId]));
        $refuels = getVehicleRefuels($vehicleId);
        $this->assertCount(1, $refuels);
        $this->assertSame('Shell Ηράκλειο', $refuels[0]['station']);
    }

    public function testCostSummaryAddsFuelAndMaintenance(): void
    {
        $vehicleId = $this->makeVehicle('TESTMX-COST');
        addRefuel($vehicleId, 20.0, 30.0, null, null, self::$userId);
        addRefuel($vehicleId, 10.0, 15.0, null, null, self::$userId);
        $m = openMaintenance($vehicleId, 'repair', 'test', 100.0, null, null, self::$userId);
        closeMaintenance($m['maintenance_id'], self::$userId, null, 100.0);

        $summary = getVehicleCostSummary($vehicleId);

        $this->assertSame(45.0, $summary['fuel_cost']);
        $this->assertSame(30.0, $summary['fuel_liters']);
        $this->assertSame(100.0, $summary['maintenance_cost']);
        $this->assertSame(145.0, $summary['total_cost']);
    }

    public function testADamagedHandoverCheckinOpensARealMaintenanceRecord(): void
    {
        $vehicleId = $this->makeVehicle('TESTMX-HANDOVER');
        $itemId = (int) dbInsert("INSERT INTO vehicle_checklist_items (type, label) VALUES (NULL, 'TESTMX Item')");
        openHandover($vehicleId, null, self::$userId, 1000.0, 80, '', [$itemId => ['status' => 'ok', 'note' => '']]);

        closeHandover($vehicleId, self::$userId, 1050.0, 60, '', [$itemId => ['status' => 'missing', 'note' => 'λείπει']]);

        $openRecords = getOpenMaintenanceRecords($vehicleId);
        $this->assertCount(1, $openRecords, 'the flagged checkin opened a real maintenance ticket');
        $this->assertStringContainsString('TESTMX Item', $openRecords[0]['description']);
        $this->assertSame('maintenance', dbFetchValue("SELECT status FROM vehicles WHERE id = ?", [$vehicleId]));
    }
}
