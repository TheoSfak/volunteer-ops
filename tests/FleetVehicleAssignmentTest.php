<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Fleet management, M1 (v3.482.0): a vehicle in 'maintenance' or
 * 'out_of_service' cannot be newly assigned to a shift, but an existing
 * assignment is never silently dropped just because the vehicle later moves
 * into maintenance - shift-form.php's save path (syncShiftVehicles(), see
 * includes/fleet-functions.php) must keep that distinction.
 *
 * Requires includes/fleet-functions.php itself rather than leaning on
 * tests/bootstrap.php's preload list, on purpose - that file does not
 * preload inventory-functions.php either (it's a per-page require in the
 * real app, like fleet-functions.php), so a missing require in a real page
 * would otherwise go unnoticed here. See tests/README.md /
 * feedback_test_bootstrap_hides_missing_requires.
 */
final class FleetVehicleAssignmentTest extends TestCase
{
    private static int $shiftId;
    private static int $missionId;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap.php';
        require_once __DIR__ . '/../includes/fleet-functions.php';

        $missionTypeId = dbFetchValue("SELECT id FROM mission_types LIMIT 1");
        self::$missionId = (int) dbInsert(
            "INSERT INTO missions (title, location, status, mission_type_id, start_datetime, end_datetime, created_at)
             VALUES ('Fleet test mission', 'Test location', 'OPEN', ?, NOW(), NOW(), NOW())",
            [$missionTypeId]
        );
        self::$shiftId = (int) dbInsert(
            "INSERT INTO shifts (mission_id, start_time, end_time, created_at, updated_at)
             VALUES (?, NOW(), NOW(), NOW(), NOW())",
            [self::$missionId]
        );
    }

    public static function tearDownAfterClass(): void
    {
        dbExecute("DELETE FROM shifts WHERE id = ?", [self::$shiftId]);
        dbExecute("DELETE FROM missions WHERE id = ?", [self::$missionId]);
    }

    protected function tearDown(): void
    {
        dbExecute("DELETE FROM shift_vehicles WHERE shift_id = ?", [self::$shiftId]);
        dbExecute("DELETE FROM vehicles WHERE plate_number LIKE 'TEST-%'");
    }

    private function makeVehicle(string $plate, string $status): int
    {
        return (int) dbInsert(
            "INSERT INTO vehicles (plate_number, label, type, status) VALUES (?, ?, 'car', ?)",
            [$plate, 'Fleet test vehicle ' . $plate, $status]
        );
    }

    public function testAnAvailableVehicleCanBeAssigned(): void
    {
        $vehicleId = $this->makeVehicle('TEST-AVAIL', 'available');

        $skipped = syncShiftVehicles(self::$shiftId, [$vehicleId], null);

        $this->assertSame([], $skipped);
        $this->assertSame([$vehicleId], array_column(getShiftVehicles(self::$shiftId), 'id'));
    }

    public function testAMaintenanceVehicleCannotBeNewlyAssigned(): void
    {
        $vehicleId = $this->makeVehicle('TEST-MAINT', 'maintenance');

        $skipped = syncShiftVehicles(self::$shiftId, [$vehicleId], null);

        $this->assertSame([$vehicleId], $skipped, 'a vehicle in maintenance is reported back as skipped');
        $this->assertSame([], getShiftVehicles(self::$shiftId), 'and never actually linked to the shift');
    }

    public function testAnOutOfServiceVehicleCannotBeNewlyAssignedEither(): void
    {
        $vehicleId = $this->makeVehicle('TEST-OOS', 'out_of_service');

        $skipped = syncShiftVehicles(self::$shiftId, [$vehicleId], null);

        $this->assertSame([$vehicleId], $skipped);
    }

    public function testAnAlreadyAssignedVehicleIsKeptAfterItMovesToMaintenance(): void
    {
        $vehicleId = $this->makeVehicle('TEST-KEEP', 'available');
        syncShiftVehicles(self::$shiftId, [$vehicleId], null);

        dbExecute("UPDATE vehicles SET status = 'maintenance' WHERE id = ?", [$vehicleId]);

        // Re-saving the shift with the same (now-in-maintenance) vehicle still
        // checked must not drop it - only a genuinely NEW assignment is blocked.
        $skipped = syncShiftVehicles(self::$shiftId, [$vehicleId], null);

        $this->assertSame([], $skipped, 'keeping an already-assigned vehicle is not a new assignment');
        $this->assertSame([$vehicleId], array_column(getShiftVehicles(self::$shiftId), 'id'));
    }

    public function testUncheckingARemovesIt(): void
    {
        $vehicleId = $this->makeVehicle('TEST-REMOVE', 'available');
        syncShiftVehicles(self::$shiftId, [$vehicleId], null);

        $skipped = syncShiftVehicles(self::$shiftId, [], null);

        $this->assertSame([], $skipped);
        $this->assertSame([], getShiftVehicles(self::$shiftId));
    }

    public function testInactiveVehicleCannotBeAssigned(): void
    {
        $vehicleId = $this->makeVehicle('TEST-INACTIVE', 'available');
        dbExecute("UPDATE vehicles SET is_active = 0 WHERE id = ?", [$vehicleId]);

        $skipped = syncShiftVehicles(self::$shiftId, [$vehicleId], null);

        $this->assertSame([$vehicleId], $skipped);
    }
}
