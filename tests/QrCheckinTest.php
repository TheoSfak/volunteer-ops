<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The shift's attendance QR, for volunteers AND guests (v3.406.0).
 *
 * A test with a partner-org guest showed the old failure: scanning the QR sent
 * them to the Action Room with no word about their attendance, because
 * checkin.php was on neither guest allow-list and bootstrap.php redirected them
 * away before the page ran. The page also needed a second tap on a button before
 * anything was recorded. Pinned here:
 *
 *  1. checkin.php is open to a partner-org guest and to a mission visitor;
 *  2. recordQrCheckin() records the attendance once, and a second scan, or a scan
 *     after an administrator marked an absence, changes nothing;
 *  3. every text checkin.php shows exists in Greek AND English.
 *
 * Runs inside a transaction that is always rolled back.
 */
final class QrCheckinTest extends TestCase
{
    private int $userId;
    private int $shiftId;

    protected function setUp(): void
    {
        db()->beginTransaction();
        $this->userId = (int) dbInsert(
            "INSERT INTO users (name, email, password) VALUES (?, ?, ?)",
            ['Qr Checkin Guest', 'qr-' . uniqid('', true) . '@example.invalid', 'x']
        );
        $typeId = (int) dbFetchValue("SELECT id FROM mission_types ORDER BY id LIMIT 1");
        $missionId = (int) dbInsert(
            "INSERT INTO missions (title, location, start_datetime, end_datetime, mission_type_id, status, show_in_ops)
             VALUES (?, ?, ?, ?, ?, ?, 1)",
            ['Qr Checkin Mission', 'Heraklion', date('Y-m-d H:i:s', time() - 7200), date('Y-m-d H:i:s', time() + 86400), $typeId, STATUS_OPEN]
        );
        // A two-hour shift, so the hours the scan records are known.
        $this->shiftId = (int) dbInsert(
            "INSERT INTO shifts (mission_id, start_time, end_time) VALUES (?, ?, ?)",
            [$missionId, date('Y-m-d H:i:s', time() - 3600), date('Y-m-d H:i:s', time() + 3600)]
        );
    }

    protected function tearDown(): void
    {
        db()->rollBack();
    }

    private function approve(): int
    {
        return (int) dbInsert(
            "INSERT INTO participation_requests (shift_id, volunteer_id, status) VALUES (?, ?, ?)",
            [$this->shiftId, $this->userId, PARTICIPATION_APPROVED]
        );
    }

    private function row(int $prId): array
    {
        return dbFetchOne("SELECT * FROM participation_requests WHERE id = ?", [$prId]);
    }

    // ── 1. a guest can open the page ────────────────────────────────────────

    public function testTheCheckinPageIsOpenToAPartnerGuestAndToAMissionVisitor(): void
    {
        $partner = array_merge(WAR_ROOM_ACTION_SCRIPTS, WAR_ROOM_GUEST_PAGES, WAR_ROOM_PARTNER_GUEST_PAGES);
        $visitor = array_merge(WAR_ROOM_ACTION_SCRIPTS, WAR_ROOM_GUEST_PAGES, ['logout.php', 'mobile-token-check.php']);
        $this->assertContains('checkin.php', $partner, 'a partner-org guest scanning the QR is thrown into the Action Room, unrecorded');
        $this->assertContains('checkin.php', $visitor, 'a mission visitor scanning the QR is thrown into the Action Room, unrecorded');
    }

    // ── 2. the scan records the attendance, once ────────────────────────────

    public function testTheScanRecordsThePresenceWithTheShiftsOwnHours(): void
    {
        $prId = $this->approve();

        $this->assertTrue(recordQrCheckin($prId, $this->userId));

        $row = $this->row($prId);
        $this->assertSame(1, (int) $row['attended']);
        $this->assertNotEmpty($row['attendance_confirmed_at']);
        $this->assertSame($this->userId, (int) $row['attendance_confirmed_by']);
        $this->assertEqualsWithDelta(2.0, (float) $row['actual_hours'], 0.01);
    }

    public function testASecondScanChangesNothing(): void
    {
        $prId = $this->approve();
        $this->assertTrue(recordQrCheckin($prId, $this->userId));
        dbExecute("UPDATE participation_requests SET attendance_confirmed_at = '2026-01-01 10:00:00' WHERE id = ?", [$prId]);

        $this->assertFalse(recordQrCheckin($prId, $this->userId), 'the second scan must not count as a new check-in');
        $this->assertSame('2026-01-01 10:00:00', $this->row($prId)['attendance_confirmed_at']);
    }

    public function testAnAbsenceAnAdministratorMarkedIsNeverOverwrittenByTheScan(): void
    {
        $prId = $this->approve();
        dbExecute(
            "UPDATE participation_requests SET attended = 0, attendance_confirmed_at = '2026-01-01 10:00:00' WHERE id = ?",
            [$prId]
        );

        $this->assertFalse(recordQrCheckin($prId, $this->userId));

        $row = $this->row($prId);
        $this->assertSame(0, (int) $row['attended']);
        $this->assertSame('2026-01-01 10:00:00', $row['attendance_confirmed_at']);
    }

    // ── 3. every text exists in both languages ──────────────────────────────

    public function testEveryTextTheCheckinPageShowsExistsInGreekAndEnglish(): void
    {
        $strings = require __DIR__ . '/../includes/lang/war-room.php';
        $page = (string) file_get_contents(__DIR__ . '/../checkin.php');

        preg_match_all("~'(checkin\\.[a-z_]+)'~", $page, $m);
        $keys = array_unique($m[1]);
        $this->assertGreaterThan(15, count($keys), 'the scan found too few keys to prove anything');

        foreach (['el', 'en'] as $lang) {
            foreach ($keys as $key) {
                $this->assertArrayHasKey($key, $strings[$lang], "checkin.php uses $key but '$lang' has no such text");
                $this->assertNotSame('', $strings[$lang][$key]);
            }
        }
        // …and no text sits in one language only.
        $greek = array_filter(array_keys($strings['el']), fn($k) => str_starts_with($k, 'checkin.'));
        $english = array_filter(array_keys($strings['en']), fn($k) => str_starts_with($k, 'checkin.'));
        $this->assertSame([], array_diff($greek, $english));
        $this->assertSame([], array_diff($english, $greek));
    }
}
