<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Fleet management, M2 (v3.483.0): vehicle handover (checkout/check-in) and
 * its equipment checklist, in includes/fleet-functions.php. A checkin tick
 * other than 'ok' must move the vehicle into maintenance - that is the whole
 * point of checking the equipment both ways, not just logging kilometres.
 *
 * Requires includes/fleet-functions.php itself rather than leaning on
 * tests/bootstrap.php's preload list - see FleetVehicleAssignmentTest's
 * docblock / feedback_test_bootstrap_hides_missing_requires.
 */
final class FleetHandoverTest extends TestCase
{
    private static int $userId;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap.php';
        require_once __DIR__ . '/../includes/fleet-functions.php';

        self::$userId = (int) dbInsert(
            "INSERT INTO users (name, email, password) VALUES (?, ?, ?)",
            ['Fleet Handover Test User', 'fleet-handover-test@example.invalid', password_hash('x', PASSWORD_DEFAULT)]
        );
    }

    public static function tearDownAfterClass(): void
    {
        dbExecute("DELETE FROM users WHERE id = ?", [self::$userId]);
    }

    protected function tearDown(): void
    {
        dbExecute("DELETE FROM vehicles WHERE plate_number LIKE 'TESTHO-%'");
        dbExecute("DELETE FROM vehicle_checklist_items WHERE label LIKE 'TEST %'");
    }

    private function makeVehicle(string $plate, string $type = 'car', string $status = 'available'): int
    {
        return (int) dbInsert(
            "INSERT INTO vehicles (plate_number, label, type, status) VALUES (?, ?, ?, ?)",
            [$plate, 'Handover test vehicle ' . $plate, $type, $status]
        );
    }

    private function makeChecklistItem(string $label, ?string $type = null): int
    {
        return (int) dbInsert(
            "INSERT INTO vehicle_checklist_items (type, label) VALUES (?, ?)",
            [$type, $label]
        );
    }

    public function testChecklistItemsForTypeIncludeBothTypeSpecificAndGeneric(): void
    {
        $this->makeChecklistItem('TEST Πυροσβεστήρας', null);
        $this->makeChecklistItem('TEST Σωσίβιο', 'boat');
        $this->makeChecklistItem('TEST Κράνος', 'motorbike');

        $carItems = array_column(getChecklistItemsForType('car'), 'label');
        $boatItems = array_column(getChecklistItemsForType('boat'), 'label');

        $this->assertContains('TEST Πυροσβεστήρας', $carItems);
        $this->assertNotContains('TEST Σωσίβιο', $carItems);
        $this->assertContains('TEST Σωσίβιο', $boatItems);
        $this->assertContains('TEST Πυροσβεστήρας', $boatItems, 'a type-agnostic item applies everywhere');
    }

    public function testOpeningAHandoverRecordsItAndUpdatesOdometer(): void
    {
        $vehicleId = $this->makeVehicle('TESTHO-OPEN');

        $result = openHandover($vehicleId, null, self::$userId, 1000.0, 80, 'test checkout', []);

        $this->assertTrue($result['success']);
        $this->assertNotNull(getOpenHandover($vehicleId));
        $this->assertSame(1000.0, (float) dbFetchValue("SELECT odometer_value FROM vehicles WHERE id = ?", [$vehicleId]));
    }

    public function testCannotOpenASecondHandoverWhileOneIsAlreadyOpen(): void
    {
        $vehicleId = $this->makeVehicle('TESTHO-DOUBLE');
        openHandover($vehicleId, null, self::$userId, 1000.0, 80, '', []);

        $result = openHandover($vehicleId, null, self::$userId, 1100.0, 70, '', []);

        $this->assertFalse($result['success']);
    }

    public function testClosingAHandoverWithAllOkChecksLeavesVehicleAvailable(): void
    {
        $vehicleId = $this->makeVehicle('TESTHO-OK');
        $itemId = $this->makeChecklistItem('TEST Item OK');
        openHandover($vehicleId, null, self::$userId, 1000.0, 80, '', [$itemId => ['status' => 'ok', 'note' => '']]);

        $result = closeHandover($vehicleId, self::$userId, 1050.0, 60, 'test checkin', [$itemId => ['status' => 'ok', 'note' => '']]);

        $this->assertTrue($result['success']);
        $this->assertFalse($result['flagged']);
        $this->assertNull(getOpenHandover($vehicleId), 'the handover is closed');
        $this->assertSame('available', dbFetchValue("SELECT status FROM vehicles WHERE id = ?", [$vehicleId]));
        $this->assertSame(1050.0, (float) dbFetchValue("SELECT odometer_value FROM vehicles WHERE id = ?", [$vehicleId]));
    }

    public function testClosingAHandoverWithADamagedCheckMovesVehicleToMaintenance(): void
    {
        $vehicleId = $this->makeVehicle('TESTHO-DAMAGE');
        $itemId = $this->makeChecklistItem('TEST Item Damaged');
        openHandover($vehicleId, null, self::$userId, 1000.0, 80, '', [$itemId => ['status' => 'ok', 'note' => '']]);

        $result = closeHandover($vehicleId, self::$userId, 1050.0, 60, '', [$itemId => ['status' => 'damaged', 'note' => 'σπασμένο φανάρι']]);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['flagged']);
        $this->assertSame('maintenance', dbFetchValue("SELECT status FROM vehicles WHERE id = ?", [$vehicleId]));
    }

    public function testClosingAHandoverNeverOverridesOutOfService(): void
    {
        $vehicleId = $this->makeVehicle('TESTHO-OOS', 'car', 'out_of_service');
        $itemId = $this->makeChecklistItem('TEST Item Missing');
        // Open directly in the DB since openHandover() does not check vehicle status itself
        // (that gate lives in shift-form.php / vehicle-handover.php's caller).
        dbInsert(
            "INSERT INTO vehicle_handovers (vehicle_id, checkout_by, checkout_at, checkout_km) VALUES (?, ?, NOW(), 1000)",
            [$vehicleId, self::$userId]
        );

        closeHandover($vehicleId, self::$userId, 1050.0, 60, '', [$itemId => ['status' => 'missing', 'note' => '']]);

        $this->assertSame('out_of_service', dbFetchValue("SELECT status FROM vehicles WHERE id = ?", [$vehicleId]), 'a damaged check must not un-retire a vehicle');
    }

    public function testCheckingInWithNothingOpenFails(): void
    {
        $vehicleId = $this->makeVehicle('TESTHO-NOOPEN');

        $result = closeHandover($vehicleId, self::$userId, 1000.0, 50, '', []);

        $this->assertFalse($result['success']);
    }
}
