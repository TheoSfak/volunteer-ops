<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * An administrator's hand-made attendance, and the certificate recipient list
 * that reads it (v3.414.0).
 *
 * The shift page's «Παρουσία» button and the operations dashboard's quick mark
 * set attended = 1 but left attendance_confirmed_at/by empty, unlike the
 * attendance page and the QR scan. The dashboard went on counting the volunteer
 * as "χωρίς επιβεβαίωση", and a later QR scan overwrote the entry as the
 * volunteer's own check-in. And the mission-certificates page marked everybody
 * with an approved sign-up «Συμμετείχε», whether or not they ever turned up.
 * Pinned here:
 *
 *  1. recordManualAttendance() records the presence and stamps the confirmation
 *     with the administrator, with the given hours or else the shift's own;
 *  2. it touches only an APPROVED sign-up of the shift it is given;
 *  3. a QR scan afterwards changes nothing;
 *  4. missionCertificatePickList() tells recorded presence from a bare approval
 *     and puts the first before the second.
 *
 * Runs inside a transaction that is always rolled back.
 */
final class ManualAttendanceTest extends TestCase
{
    private int $adminId;
    private int $volunteerId;
    private int $missionId;
    private int $shiftId;

    protected function setUp(): void
    {
        db()->beginTransaction();
        $this->adminId = $this->makeUser('Manual Attendance Admin');
        $this->volunteerId = $this->makeUser('Manual Attendance Volunteer');
        $typeId = (int) dbFetchValue("SELECT id FROM mission_types ORDER BY id LIMIT 1");
        $this->missionId = (int) dbInsert(
            "INSERT INTO missions (title, location, start_datetime, end_datetime, mission_type_id, status, show_in_ops)
             VALUES (?, ?, ?, ?, ?, ?, 1)",
            ['Manual Attendance Mission', 'Heraklion', date('Y-m-d H:i:s', time() - 14400), date('Y-m-d H:i:s', time() - 3600), $typeId, STATUS_CLOSED]
        );
        // A two-hour shift, so the hours the shift's own default gives are known.
        $this->shiftId = $this->makeShift($this->missionId);
    }

    protected function tearDown(): void
    {
        db()->rollBack();
    }

    private function makeUser(string $name): int
    {
        return (int) dbInsert(
            "INSERT INTO users (name, email, password) VALUES (?, ?, ?)",
            [$name, 'ma-' . uniqid('', true) . '@example.invalid', 'x']
        );
    }

    private function makeShift(int $missionId): int
    {
        return (int) dbInsert(
            "INSERT INTO shifts (mission_id, start_time, end_time) VALUES (?, ?, ?)",
            [$missionId, date('Y-m-d H:i:s', time() - 10800), date('Y-m-d H:i:s', time() - 3600)]
        );
    }

    private function signUp(int $volunteerId, int $shiftId, string $status = PARTICIPATION_APPROVED): int
    {
        return (int) dbInsert(
            "INSERT INTO participation_requests (shift_id, volunteer_id, status) VALUES (?, ?, ?)",
            [$shiftId, $volunteerId, $status]
        );
    }

    private function row(int $prId): array
    {
        return dbFetchOne("SELECT * FROM participation_requests WHERE id = ?", [$prId]);
    }

    // ── 1. the presence and the stamp ───────────────────────────────────────

    public function testItRecordsThePresenceAndStampsItWithTheAdministrator(): void
    {
        $prId = $this->signUp($this->volunteerId, $this->shiftId);

        $this->assertTrue(recordManualAttendance($prId, $this->shiftId, $this->adminId, 1.5));

        $row = $this->row($prId);
        $this->assertSame(1, (int) $row['attended']);
        $this->assertEqualsWithDelta(1.5, (float) $row['actual_hours'], 0.01);
        $this->assertNotEmpty($row['attendance_confirmed_at'], 'without the stamp the dashboard still counts the volunteer as unconfirmed');
        $this->assertSame($this->adminId, (int) $row['attendance_confirmed_by'], 'confirmed by the administrator, not passed off as the volunteer\'s own QR check-in');
    }

    public function testWithoutHoursItTakesTheShiftsOwn(): void
    {
        $prId = $this->signUp($this->volunteerId, $this->shiftId);

        $this->assertTrue(recordManualAttendance($prId, $this->shiftId, $this->adminId));

        $this->assertEqualsWithDelta(2.0, (float) $this->row($prId)['actual_hours'], 0.01);
    }

    // ── 2. only an approved sign-up of that shift ───────────────────────────

    public function testItIgnoresAParticipationIdFromAnotherShift(): void
    {
        $otherShift = $this->makeShift($this->missionId);
        $prId = $this->signUp($this->volunteerId, $otherShift);

        $this->assertFalse(recordManualAttendance($prId, $this->shiftId, $this->adminId));

        $row = $this->row($prId);
        $this->assertSame(0, (int) $row['attended']);
        $this->assertEmpty($row['attendance_confirmed_at']);
    }

    public function testItIgnoresASignUpThatIsNotApproved(): void
    {
        $prId = $this->signUp($this->volunteerId, $this->shiftId, PARTICIPATION_PENDING);

        $this->assertFalse(recordManualAttendance($prId, $this->shiftId, $this->adminId));

        $this->assertSame(0, (int) $this->row($prId)['attended']);
    }

    // ── 3. a QR scan afterwards ─────────────────────────────────────────────

    public function testAQrScanAfterwardsChangesNothing(): void
    {
        $prId = $this->signUp($this->volunteerId, $this->shiftId);
        recordManualAttendance($prId, $this->shiftId, $this->adminId, 1.5);

        $this->assertFalse(recordQrCheckin($prId, $this->volunteerId), 'the scan must not turn the administrator\'s entry into a self check-in');

        $row = $this->row($prId);
        $this->assertSame($this->adminId, (int) $row['attendance_confirmed_by']);
        $this->assertEqualsWithDelta(1.5, (float) $row['actual_hours'], 0.01);
    }

    // ── 4. the certificate recipient list ───────────────────────────────────

    public function testTheRecipientListSeparatesRecordedPresenceFromABareApproval(): void
    {
        $approvedOnly = $this->makeUser('Manual Attendance Approved Only');
        $outsider = $this->makeUser('Manual Attendance Outsider');
        $presentPr = $this->signUp($this->volunteerId, $this->shiftId);
        $this->signUp($approvedOnly, $this->shiftId);
        $this->signUp($outsider, $this->shiftId, PARTICIPATION_PENDING);
        recordManualAttendance($presentPr, $this->shiftId, $this->adminId);

        $byId = [];
        $order = [];
        foreach (missionCertificatePickList($this->missionId) as $r) {
            $byId[(int) $r['id']] = $r;
            $order[] = (int) $r['id'];
        }

        $this->assertSame(1, (int) $byId[$this->volunteerId]['has_checked_in']);
        $this->assertSame(1, (int) $byId[$this->volunteerId]['is_participant']);
        $this->assertSame(0, (int) $byId[$approvedOnly]['has_checked_in'], 'approved but never recorded present is not a check-in');
        $this->assertSame(1, (int) $byId[$approvedOnly]['is_participant']);
        $this->assertSame(0, (int) $byId[$outsider]['has_checked_in'], 'a pending sign-up is neither');
        $this->assertSame(0, (int) $byId[$outsider]['is_participant']);

        $this->assertLessThan(array_search($approvedOnly, $order, true), array_search($this->volunteerId, $order, true), 'the checked-in come before the merely approved');
        $this->assertLessThan(array_search($outsider, $order, true), array_search($approvedOnly, $order, true), 'the approved come before everybody else');
    }

    public function testSeveralShiftsCountAsCheckedInWhenAnyOneIsAttended(): void
    {
        $secondShift = $this->makeShift($this->missionId);
        $this->signUp($this->volunteerId, $this->shiftId);
        $secondPr = $this->signUp($this->volunteerId, $secondShift);
        recordManualAttendance($secondPr, $secondShift, $this->adminId);

        $rows = array_values(array_filter(
            missionCertificatePickList($this->missionId),
            fn($r) => (int) $r['id'] === $this->volunteerId
        ));

        $this->assertCount(1, $rows, 'one row per person however many shifts');
        $this->assertSame(1, (int) $rows[0]['has_checked_in']);
    }

    public function testAMissionWhereNobodyWasRecordedPresentChecksNobodyIn(): void
    {
        $this->signUp($this->volunteerId, $this->shiftId);

        $checkedIn = array_filter(
            missionCertificatePickList($this->missionId),
            fn($r) => (int) $r['has_checked_in'] === 1
        );

        $this->assertSame([], array_values($checkedIn));
    }
}
