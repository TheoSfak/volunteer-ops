<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Fleet management, M4 backend: vehicle_ping_tokens (bearer auth, keyed on
 * vehicle_id instead of user_id - a hardware tracker has no user to log in
 * as) and vehicle_pings (includes/fleet-functions.php). The actual sender
 * (phone app vs. dedicated tracker) is still undecided - these tests only
 * cover the backend vehicle-ping.php and vehicle-view.php now depend on.
 */
final class FleetVehiclePingTest extends TestCase
{
    private static int $vehicleA;
    private static int $vehicleB;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap.php';
        require_once __DIR__ . '/../includes/fleet-functions.php';

        self::$vehicleA = (int) dbInsert("INSERT INTO vehicles (plate_number, label, type) VALUES ('TESTPING-A', 'Ping Test A', 'car')");
        self::$vehicleB = (int) dbInsert("INSERT INTO vehicles (plate_number, label, type) VALUES ('TESTPING-B', 'Ping Test B', 'van')");
    }

    public static function tearDownAfterClass(): void
    {
        dbExecute("DELETE FROM vehicles WHERE plate_number LIKE 'TESTPING-%'");
    }

    protected function tearDown(): void
    {
        dbExecute("DELETE FROM vehicle_pings WHERE vehicle_id IN (?, ?)", [self::$vehicleA, self::$vehicleB]);
        dbExecute("DELETE FROM vehicle_ping_tokens WHERE vehicle_id IN (?, ?)", [self::$vehicleA, self::$vehicleB]);
    }

    public function testAFreshTokenAuthenticatesToItsOwnVehicleOnly(): void
    {
        $token = issueVehiclePingToken(self::$vehicleA, 'Dash tracker');

        $this->assertSame(self::$vehicleA, authenticateVehiclePingToken($token));
        $this->assertNotSame(self::$vehicleB, authenticateVehiclePingToken($token));
    }

    public function testAnUnknownTokenAuthenticatesToNothing(): void
    {
        $this->assertNull(authenticateVehiclePingToken('not-a-real-token'));
    }

    public function testIssuingAReplacementTokenRevokesTheOldOne(): void
    {
        $first = issueVehiclePingToken(self::$vehicleA, 'Old tracker');
        $this->assertSame(self::$vehicleA, authenticateVehiclePingToken($first));

        $second = issueVehiclePingToken(self::$vehicleA, 'New tracker');

        $this->assertNull(authenticateVehiclePingToken($first), 'the replaced token must stop working');
        $this->assertSame(self::$vehicleA, authenticateVehiclePingToken($second));
    }

    public function testRevokingTheActiveTokenStopsItAuthenticating(): void
    {
        $token = issueVehiclePingToken(self::$vehicleA, null);
        revokeVehiclePingToken(self::$vehicleA);

        $this->assertNull(authenticateVehiclePingToken($token));
        $this->assertNull(getVehiclePingTokenInfo(self::$vehicleA));
    }

    public function testAuthenticatingATokenTouchesLastUsedAt(): void
    {
        $token = issueVehiclePingToken(self::$vehicleA, null);
        $before = getVehiclePingTokenInfo(self::$vehicleA);
        $this->assertNull($before['last_used_at']);

        authenticateVehiclePingToken($token);

        $after = getVehiclePingTokenInfo(self::$vehicleA);
        $this->assertNotNull($after['last_used_at']);
    }

    public function testAddVehiclePingAndGetLatestVehiclePing(): void
    {
        $this->assertNull(getLatestVehiclePing(self::$vehicleA), 'no pings recorded yet');

        addVehiclePing(self::$vehicleA, 35.3387, 25.1442, 12.5, 8.0, 90.0, 'test-sender');
        $id2 = addVehiclePing(self::$vehicleA, 35.3390, 25.1450, 10.0, 6.0, 95.0, 'test-sender');

        $latest = getLatestVehiclePing(self::$vehicleA);
        $this->assertSame($id2, (int) $latest['id'], 'the most recently recorded fix, not the first');
        $this->assertEqualsWithDelta(35.3390, (float) $latest['lat'], 0.0001);
    }

    public function testGetVehicleRouteOnlyIncludesFixesWithinTheWindowAndOrdersOldestFirst(): void
    {
        $oldId = addVehiclePing(self::$vehicleA, 35.30, 25.10, null, null, null, null);
        dbExecute("UPDATE vehicle_pings SET created_at = DATE_SUB(NOW(), INTERVAL 30 HOUR) WHERE id = ?", [$oldId]);

        $recentId1 = addVehiclePing(self::$vehicleA, 35.31, 25.11, null, null, null, null);
        dbExecute("UPDATE vehicle_pings SET created_at = DATE_SUB(NOW(), INTERVAL 2 HOUR) WHERE id = ?", [$recentId1]);
        $recentId2 = addVehiclePing(self::$vehicleA, 35.32, 25.12, null, null, null, null);

        $route24h = getVehicleRoute(self::$vehicleA, 24);
        $ids24h = array_map(fn($p) => (int) $p['id'], $route24h);

        $this->assertNotContains($oldId, $ids24h, 'a 30h-old fix must not count as "last 24h"');
        $this->assertSame([$recentId1, $recentId2], $ids24h, 'oldest first, for drawing a route in order');

        $routeFull = getVehicleRoute(self::$vehicleA, 48);
        $this->assertCount(3, $routeFull, 'a wider window still sees the old fix');
    }

    public function testPingsAndTokensNeverCrossVehicles(): void
    {
        issueVehiclePingToken(self::$vehicleA, null);
        addVehiclePing(self::$vehicleA, 35.30, 25.10, null, null, null, null);

        $this->assertNull(getVehiclePingTokenInfo(self::$vehicleB), 'vehicle B has no token of its own');
        $this->assertNull(getLatestVehiclePing(self::$vehicleB), 'vehicle B has no pings of its own');
        $this->assertCount(0, getVehicleRoute(self::$vehicleB, 24));
    }
}
