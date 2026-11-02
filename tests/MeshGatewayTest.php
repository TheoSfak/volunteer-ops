<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The LoRa gateway's server side (v3.427.0): what becomes of a position the
 * command post's radio forwarded.
 *
 * What is pinned: a packet is recorded once however often it arrives; a node
 * nobody carries is remembered as heard and its place is never stored; a
 * position rounded to a ~3 km cell (the public channel's default) is not
 * stored as somebody's place; a bound node's position goes through the same
 * funnel as every other (approved, in the Action Room, plausible) with
 * via='mesh'; a phone that is reporting for itself is not overruled by its
 * node; the order of a backlog does not cost positions.
 *
 * Runs inside a transaction that is always rolled back.
 */
final class MeshGatewayTest extends TestCase
{
    private int $missionId;
    private int $shiftId;
    private int $adminId;
    private int $alice;
    private int $bob;
    private int $stranger;

    private const NODE_ALICE = 0x570dee8c;
    private const NODE_BOB = 0x1234abcd;
    private const NODE_PUBLIC = 0x0badf00d;

    protected function setUp(): void
    {
        db()->beginTransaction();

        $this->adminId = $this->makeUser('Mesh Admin');
        $missionTypeId = (int) dbFetchValue("SELECT id FROM mission_types ORDER BY id LIMIT 1");
        $this->missionId = (int) dbInsert(
            "INSERT INTO missions (title, location, start_datetime, end_datetime, mission_type_id, status, show_in_ops)
             VALUES (?, ?, ?, ?, ?, ?, 1)",
            ['Mesh Mission', 'Ηράκλειο', date('Y-m-d H:i:s', time() - 7200), date('Y-m-d H:i:s', time() + 7200), $missionTypeId, STATUS_OPEN]
        );
        $this->shiftId = (int) dbInsert(
            "INSERT INTO shifts (mission_id, start_time, end_time) VALUES (?, ?, ?)",
            [$this->missionId, date('Y-m-d H:i:s', time() - 3600), date('Y-m-d H:i:s', time() + 3600)]
        );
        forgetActionRoomParticipantIds($this->missionId);
        $this->alice = $this->makeParticipant('Alice A.');
        $this->bob = $this->makeParticipant('Bob B.');
        $this->stranger = $this->makeUser('Not On Mission');
        // The gate under test is not the accuracy setting's default being changed by another test.
        dbExecute("DELETE FROM mesh_packets WHERE node_num IN (?, ?, ?)", [self::NODE_ALICE, self::NODE_BOB, self::NODE_PUBLIC]);
    }

    protected function tearDown(): void
    {
        db()->rollBack();
        forgetActionRoomParticipantIds($this->missionId);
    }

    private function makeUser(string $name): int
    {
        return (int) dbInsert(
            "INSERT INTO users (name, email, password) VALUES (?, ?, ?)",
            [$name, 'mesh-' . uniqid('', true) . '@example.invalid', 'x']
        );
    }

    private function makeParticipant(string $name): int
    {
        $id = $this->makeUser($name);
        dbInsert(
            "INSERT INTO participation_requests (shift_id, volunteer_id, status) VALUES (?, ?, ?)",
            [$this->shiftId, $id, PARTICIPATION_APPROVED]
        );
        setActionRoomParticipation($this->missionId, $id, true, $this->adminId);
        return $id;
    }

    private function record(int $node, int $id, array $over = []): array
    {
        return $over + [
            'node' => $node, 'id' => $id, 'lat' => 35.3387, 'lng' => 25.1442,
            'prec' => 32, 'age_s' => 0, 'speed' => 1, 'snr' => 6.5, 'rssi' => -97, 'hops' => 1,
        ];
    }

    private function pingsOf(int $userId): array
    {
        return dbFetchAll("SELECT lat, lng, via, accuracy_meters FROM volunteer_pings WHERE user_id = ? ORDER BY id", [$userId]);
    }

    public function testABoundNodesPositionBecomesThatPersonsPingViaTheRadio(): void
    {
        meshBindNode(self::NODE_ALICE, $this->alice, 'Radio A', $this->adminId);
        $summary = meshIngest($this->missionId, [$this->record(self::NODE_ALICE, 1)]);

        $this->assertSame(1, $summary['recorded'], json_encode($summary));
        $pings = $this->pingsOf($this->alice);
        $this->assertCount(1, $pings);
        $this->assertSame('mesh', $pings[0]['via']);
        $this->assertEqualsWithDelta(35.3387, (float) $pings[0]['lat'], 1e-5);
    }

    public function testTheSamePacketIsRecordedOnceHoweverOftenItArrives(): void
    {
        meshBindNode(self::NODE_ALICE, $this->alice, null, $this->adminId);
        $first = meshIngest($this->missionId, [$this->record(self::NODE_ALICE, 7)]);
        $again = meshIngest($this->missionId, [$this->record(self::NODE_ALICE, 7), $this->record(self::NODE_ALICE, 7)]);

        $this->assertSame(1, $first['recorded']);
        $this->assertSame(2, $again['duplicate']);
        $this->assertSame(0, $again['recorded']);
        $this->assertCount(1, $this->pingsOf($this->alice));
    }

    public function testANodeNobodyCarriesIsHeardButItsPlaceIsNeverStored(): void
    {
        $summary = meshIngest($this->missionId, [$this->record(self::NODE_PUBLIC, 5, ['lat' => 35.0, 'lng' => 24.5])]);

        $this->assertSame(1, $summary['unbound']);
        $this->assertSame(0, (int) dbFetchValue("SELECT COUNT(*) FROM volunteer_pings WHERE via = 'mesh' AND lat = 35.0"));
        $heard = array_column(meshHeardUnbound(), 'node');
        $this->assertContains(self::NODE_PUBLIC, $heard);
        // The heard list carries signal and age, not coordinates.
        $row = dbFetchOne("SELECT * FROM mesh_packets WHERE node_num = ?", [self::NODE_PUBLIC]);
        $this->assertArrayNotHasKey('lat', $row);
        $this->assertArrayNotHasKey('lng', $row);
    }

    public function testAPositionRoundedToAKilometreCellIsNotAPlace(): void
    {
        meshBindNode(self::NODE_ALICE, $this->alice, null, $this->adminId);
        // 13 bits is the public channel's default: a cell several kilometres wide.
        $summary = meshIngest($this->missionId, [$this->record(self::NODE_ALICE, 9, ['prec' => 13])]);

        $this->assertSame(1, $summary['blurred']);
        $this->assertSame([], $this->pingsOf($this->alice));
        // And it did not flag the person's GPS as imprecise on the roster.
        $this->assertFalse((bool) dbFetchValue("SELECT 1 FROM volunteer_pings WHERE user_id = ? LIMIT 1", [$this->alice]));
    }

    public function testBlurRadiusFollowsThePrecisionBits(): void
    {
        $this->assertNull(meshBlurRadiusM(null));
        $this->assertNull(meshBlurRadiusM(0));
        $this->assertNull(meshBlurRadiusM(32));
        // 24 bits: a cell of 2^8 x 1e-7 degrees = 2.56e-5 degrees, about 2.9 m, half of it.
        $this->assertEqualsWithDelta(1.4, meshBlurRadiusM(24), 0.1);
        // 13 bits: 2^19 x 1e-7 degrees, about 5.8 km a side.
        $this->assertGreaterThan(2500, meshBlurRadiusM(13));
        $this->assertLessThan(3500, meshBlurRadiusM(13));
    }

    public function testSomebodyNotOnTheMissionIsRefusedByTheSamePositionFunnel(): void
    {
        meshBindNode(self::NODE_BOB, $this->stranger, null, $this->adminId);
        $summary = meshIngest($this->missionId, [$this->record(self::NODE_BOB, 3)]);

        $this->assertSame(1, $summary['refused']);
        $this->assertSame([], $this->pingsOf($this->stranger));
    }

    public function testAPhoneThatReportsForItselfIsNotOverruledByItsNode(): void
    {
        meshBindNode(self::NODE_ALICE, $this->alice, null, $this->adminId);
        dbInsert(
            "INSERT INTO volunteer_pings (user_id, shift_id, lat, lng, accuracy_meters, source, via, created_at)
             VALUES (?, ?, 35.3387, 25.1442, 5, 'auto', 'native', DATE_SUB(NOW(), INTERVAL 20 SECOND))",
            [$this->alice, $this->shiftId]
        );

        $summary = meshIngest($this->missionId, [$this->record(self::NODE_ALICE, 11)]);

        $this->assertSame(1, $summary['skipped'], json_encode($summary));
        $this->assertCount(1, $this->pingsOf($this->alice));
    }

    public function testTheRadioTakesOverOnceThePhoneHasBeenQuiet(): void
    {
        meshBindNode(self::NODE_ALICE, $this->alice, null, $this->adminId);
        dbInsert(
            "INSERT INTO volunteer_pings (user_id, shift_id, lat, lng, accuracy_meters, source, via, created_at)
             VALUES (?, ?, 35.3387, 25.1442, 5, 'auto', 'native', DATE_SUB(NOW(), INTERVAL 5 MINUTE))",
            [$this->alice, $this->shiftId]
        );

        $summary = meshIngest($this->missionId, [$this->record(self::NODE_ALICE, 12, ['lat' => 35.3390, 'lng' => 25.1445])]);

        $this->assertSame(1, $summary['recorded'], json_encode($summary));
        $pings = $this->pingsOf($this->alice);
        $this->assertSame('mesh', end($pings)['via']);
    }

    public function testABacklogIsRecordedOldestFirstWhateverOrderItArrivesIn(): void
    {
        meshBindNode(self::NODE_ALICE, $this->alice, null, $this->adminId);
        $summary = meshIngest($this->missionId, [
            $this->record(self::NODE_ALICE, 21, ['age_s' => 10, 'lat' => 35.33880]),
            $this->record(self::NODE_ALICE, 22, ['age_s' => 100, 'lat' => 35.33860]),
            $this->record(self::NODE_ALICE, 23, ['age_s' => 50, 'lat' => 35.33870]),
        ]);

        $this->assertSame(3, $summary['recorded'], json_encode($summary));
        $lats = array_map(fn($p) => round((float) $p['lat'], 5), $this->pingsOf($this->alice));
        $this->assertSame([35.3386, 35.3387, 35.3388], $lats);
    }

    public function testRecordsThePageCouldHaveGarbledAreCountedAsInvalid(): void
    {
        $summary = meshIngest($this->missionId, [
            ['node' => 0, 'id' => 1, 'lat' => 35, 'lng' => 25],
            ['node' => 5, 'id' => 1, 'lat' => 95, 'lng' => 25],
            ['node' => 5, 'id' => 2, 'lat' => 0, 'lng' => 0],
            ['node' => 5, 'id' => 3, 'lat' => 35, 'lng' => 25, 'prec' => 40],
            'nonsense',
        ]);
        $this->assertSame(5, $summary['invalid'], json_encode($summary));
        $this->assertSame(0, (int) dbFetchValue("SELECT COUNT(*) FROM mesh_packets WHERE node_num = 5"));
    }

    public function testBindingReplacesTheOldOwnerAndUnbindingFrees(): void
    {
        meshBindNode(self::NODE_ALICE, $this->alice, null, $this->adminId);
        meshBindNode(self::NODE_ALICE, $this->bob, 'now Bob', $this->adminId);
        $this->assertSame([self::NODE_ALICE => $this->bob], meshBoundUsers([self::NODE_ALICE]));

        $this->assertSame(1, meshUnbindNode(self::NODE_ALICE));
        $this->assertSame([], meshBoundUsers([self::NODE_ALICE]));
        $this->assertFalse(meshBindNode(0, $this->bob, null, $this->adminId));
    }

    public function testTheNodeListShowsWhoWasHeardAndHowWell(): void
    {
        meshBindNode(self::NODE_ALICE, $this->alice, 'Radio A', $this->adminId);
        meshBindNode(self::NODE_BOB, $this->stranger, null, $this->adminId);
        meshIngest($this->missionId, [
            $this->record(self::NODE_ALICE, 31, ['snr' => -4.5, 'rssi' => -110, 'hops' => 2]),
            $this->record(self::NODE_PUBLIC, 32),
        ]);

        $bound = meshBoundNodes($this->missionId);
        $this->assertCount(1, $bound, 'a node carried by somebody off the mission is not listed');
        $this->assertSame(self::NODE_ALICE, $bound[0]['node']);
        $this->assertSame('Alice A.', $bound[0]['name']);
        $this->assertSame(-4.5, $bound[0]['snr']);
        $this->assertSame(-110, $bound[0]['rssi']);
        $this->assertSame(2, $bound[0]['hops']);
        $this->assertContains(self::NODE_PUBLIC, array_column(meshHeardUnbound(), 'node'));

        $names = [];
        foreach (meshCandidates($this->missionId) as $group) {
            foreach ($group['members'] as $m) {
                $names[] = $m['name'];
            }
        }
        $this->assertContains('Alice A.', $names);
        $this->assertContains('Bob B.', $names);
        $this->assertNotContains('Not On Mission', $names);
    }
}
