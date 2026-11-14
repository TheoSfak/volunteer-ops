<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Fleet management, M3: getVehicleCostSummary() (includes/fleet-functions.php)
 * is per-vehicle, but every page that uses it (vehicle-view.php, and any
 * future fleet-wide report) relies on costs from one vehicle never leaking
 * into another's total, and on the SQL SUM()s tolerating the real-world mix
 * this table actually sees: some refuels/maintenance with a NULL cost
 * (entered before the invoice arrives), some maintenance still open, and a
 * vehicle with no rows in one of the two tables at all.
 *
 * Runs three vehicles side by side on purpose - a bug that only shows up
 * with more than one vehicle (e.g. a WHERE clause missing, or a JOIN that
 * fans out rows) would pass a single-vehicle test and still be wrong.
 */
final class FleetCostSummaryMultiVehicleTest extends TestCase
{
    private static int $userId;
    private static int $vehicleA;
    private static int $vehicleB;
    private static int $vehicleC;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap.php';
        require_once __DIR__ . '/../includes/fleet-functions.php';

        self::$userId = (int) dbInsert(
            "INSERT INTO users (name, email, password) VALUES (?, ?, ?)",
            ['Fleet Cost Test User', 'fleet-cost-test@example.invalid', password_hash('x', PASSWORD_DEFAULT)]
        );

        self::$vehicleA = (int) dbInsert("INSERT INTO vehicles (plate_number, label, type) VALUES ('TESTCOST-A', 'Cost Test A', 'car')");
        self::$vehicleB = (int) dbInsert("INSERT INTO vehicles (plate_number, label, type) VALUES ('TESTCOST-B', 'Cost Test B', 'van')");
        self::$vehicleC = (int) dbInsert("INSERT INTO vehicles (plate_number, label, type) VALUES ('TESTCOST-C', 'Cost Test C', 'boat')");

        // Vehicle A: two refuels (one with a NULL cost - invoice not in yet),
        // one closed maintenance ticket with cost, one OPEN ticket with a
        // cost already quoted (should still count - the question is what it
        // will cost, not whether the invoice is paid).
        addRefuel(self::$vehicleA, 20.0, 30.00, null, 'Station 1', self::$userId);
        addRefuel(self::$vehicleA, 15.0, null, null, 'Station 2', self::$userId); // cost not yet known
        $closed = openMaintenance(self::$vehicleA, 'repair', 'closed ticket', 40.00, null, null, self::$userId);
        closeMaintenance($closed['maintenance_id'], self::$userId, null, null); // keep the opening cost (40.00) via COALESCE
        openMaintenance(self::$vehicleA, 'inspection', 'still open, quoted', 25.00, null, null, self::$userId);

        // Vehicle B: refuels only, no maintenance at all.
        addRefuel(self::$vehicleB, 50.0, 70.00, null, null, self::$userId);
        addRefuel(self::$vehicleB, 50.0, 70.00, null, null, self::$userId);

        // Vehicle C: maintenance only (one with a NULL cost - not quoted yet), no refuels.
        openMaintenance(self::$vehicleC, 'repair', 'no quote yet', null, null, null, self::$userId);
        $closedC = openMaintenance(self::$vehicleC, 'scheduled', 'service', 90.00, null, null, self::$userId);
        closeMaintenance($closedC['maintenance_id'], self::$userId, null, null);
    }

    public static function tearDownAfterClass(): void
    {
        dbExecute("DELETE FROM vehicles WHERE plate_number LIKE 'TESTCOST-%'");
        dbExecute("DELETE FROM users WHERE id = ?", [self::$userId]);
    }

    public function testVehicleATotalsFuelAndBothMaintenanceTickets(): void
    {
        $summary = getVehicleCostSummary(self::$vehicleA);

        $this->assertSame(30.0, $summary['fuel_cost'], 'the NULL-cost refuel must not block or zero out the sum');
        $this->assertSame(35.0, $summary['fuel_liters'], '20 + 15 liters, regardless of cost being known');
        $this->assertSame(65.0, $summary['maintenance_cost'], '40 (closed) + 25 (still open, but quoted) = 65');
        $this->assertSame(95.0, $summary['total_cost'], '30 fuel + 65 maintenance');
    }

    public function testVehicleBHasOnlyFuelCostAndNoMaintenanceLeakedIn(): void
    {
        $summary = getVehicleCostSummary(self::$vehicleB);

        $this->assertSame(140.0, $summary['fuel_cost'], '70 + 70');
        $this->assertSame(100.0, $summary['fuel_liters'], '50 + 50');
        $this->assertSame(0.0, $summary['maintenance_cost'], 'vehicle B has no maintenance rows at all');
        $this->assertSame(140.0, $summary['total_cost']);
    }

    public function testVehicleCHasOnlyMaintenanceCostAndTheNullQuoteDoesNotBreakTheSum(): void
    {
        $summary = getVehicleCostSummary(self::$vehicleC);

        $this->assertSame(0.0, $summary['fuel_cost'], 'vehicle C has no refuels at all');
        $this->assertSame(0.0, $summary['fuel_liters']);
        $this->assertSame(90.0, $summary['maintenance_cost'], 'the NULL-cost ticket contributes 0, not an error');
        $this->assertSame(90.0, $summary['total_cost']);
    }

    public function testTheThreeVehiclesTotalsAreMutuallyExclusive(): void
    {
        $a = getVehicleCostSummary(self::$vehicleA);
        $b = getVehicleCostSummary(self::$vehicleB);
        $c = getVehicleCostSummary(self::$vehicleC);

        // Sanity check against a from-scratch SQL aggregate across ALL three
        // vehicles at once, independent of getVehicleCostSummary()'s own
        // per-vehicle queries - this is what a fleet-wide report would run.
        $placeholders = implode(',', [self::$vehicleA, self::$vehicleB, self::$vehicleC]);
        $fleetFuel = (float) dbFetchValue("SELECT COALESCE(SUM(cost), 0) FROM vehicle_refuels WHERE vehicle_id IN ($placeholders)");
        $fleetMaintenance = (float) dbFetchValue("SELECT COALESCE(SUM(cost), 0) FROM vehicle_maintenance WHERE vehicle_id IN ($placeholders)");

        $this->assertSame($fleetFuel, $a['fuel_cost'] + $b['fuel_cost'] + $c['fuel_cost'], 'per-vehicle fuel sums must add up to the fleet-wide total');
        $this->assertSame($fleetMaintenance, $a['maintenance_cost'] + $b['maintenance_cost'] + $c['maintenance_cost'], 'per-vehicle maintenance sums must add up to the fleet-wide total');
        $this->assertSame(325.0, $fleetFuel + $fleetMaintenance, '95 + 140 + 90 across the whole mini-fleet');
    }

    public function testGetVehicleRefuelsAndMaintenanceHistoryDoNotCrossVehicles(): void
    {
        $this->assertCount(2, getVehicleRefuels(self::$vehicleA));
        $this->assertCount(0, getVehicleRefuels(self::$vehicleC), 'vehicle C has no refuels');
        $this->assertCount(2, getVehicleMaintenanceHistory(self::$vehicleA));
        $this->assertCount(0, getVehicleMaintenanceHistory(self::$vehicleB), 'vehicle B has no maintenance');
    }
}
