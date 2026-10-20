<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The people on the mission's roster reach every command screen without F5
 * (v3.395.0).
 *
 * The roster card is fed by the same approved participations as the eight
 * recipient lists, the route composer's picker and the team forms, all drawn by
 * PHP at page load. So a change of roster is not repainted into one card: the
 * poll carries a short fingerprint (missionRosterSignature) and a page whose
 * copy no longer matches reloads itself through the soft reload the GPS switch
 * already uses.
 *
 * What is pinned, and why:
 *  - the fingerprint moves for everything the roster card would show differently
 *    (approval, withdrawal, move to another shift) and for nothing else - a
 *    fingerprint that moved with pings or presence would reload the page every
 *    few seconds;
 *  - it is per mission, so another mission's approvals never reload this page;
 *  - only command staff receive it: a reload on a volunteer's phone could land
 *    in the middle of a photo, a video or a report;
 *  - the notice the admin sees says the right thing, in both languages.
 *
 * The loader runs against the test database in a transaction that is always
 * rolled back.
 */
final class RosterSignatureTest extends TestCase
{
    private int $missionId;
    private int $shiftId;
    private int $otherShiftId;

    protected function setUp(): void
    {
        db()->beginTransaction();
        $this->missionId = $this->makeMission();
        $this->shiftId = $this->makeShift($this->missionId);
        $this->otherShiftId = $this->makeShift($this->missionId);
    }

    protected function tearDown(): void
    {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
    }

    private function makeMission(): int
    {
        $missionTypeId = (int) dbFetchValue("SELECT id FROM mission_types ORDER BY id LIMIT 1");
        return (int) dbInsert(
            "INSERT INTO missions (title, location, start_datetime, end_datetime, mission_type_id, status, show_in_ops)
             VALUES (?, ?, ?, ?, ?, ?, 1)",
            ['Roster signature mission', 'Ηράκλειο', date('Y-m-d H:i:s', time() - 7200), date('Y-m-d H:i:s', time() + 7200), $missionTypeId, STATUS_OPEN]
        );
    }

    private function makeShift(int $missionId): int
    {
        return (int) dbInsert(
            "INSERT INTO shifts (mission_id, start_time, end_time) VALUES (?, ?, ?)",
            [$missionId, date('Y-m-d H:i:s', time() - 3600), date('Y-m-d H:i:s', time() + 3600)]
        );
    }

    private function makeUser(string $name): int
    {
        return (int) dbInsert(
            "INSERT INTO users (name, email, password) VALUES (?, ?, ?)",
            [$name, 'roster-' . uniqid('', true) . '@example.invalid', 'x']
        );
    }

    private function request(int $shiftId, int $volunteerId, string $status): int
    {
        return (int) dbInsert(
            "INSERT INTO participation_requests (shift_id, volunteer_id, status) VALUES (?, ?, ?)",
            [$shiftId, $volunteerId, $status]
        );
    }

    public function testItIsStableWhileNothingChanges(): void
    {
        $this->request($this->shiftId, $this->makeUser('Α'), PARTICIPATION_APPROVED);

        $this->assertSame(missionRosterSignature($this->missionId), missionRosterSignature($this->missionId));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{12}$/', missionRosterSignature($this->missionId));
    }

    public function testApprovingSomebodyChangesIt(): void
    {
        $before = missionRosterSignature($this->missionId);
        $requestId = $this->request($this->shiftId, $this->makeUser('Β'), PARTICIPATION_PENDING);

        $this->assertSame($before, missionRosterSignature($this->missionId), 'a request nobody has approved is not on the roster');

        dbExecute("UPDATE participation_requests SET status = ? WHERE id = ?", [PARTICIPATION_APPROVED, $requestId]);
        $this->assertNotSame($before, missionRosterSignature($this->missionId));
    }

    public function testWithdrawingSomebodyChangesItBackToWhatItWas(): void
    {
        $before = missionRosterSignature($this->missionId);
        $requestId = $this->request($this->shiftId, $this->makeUser('Γ'), PARTICIPATION_APPROVED);
        $approved = missionRosterSignature($this->missionId);
        $this->assertNotSame($before, $approved);

        foreach ([PARTICIPATION_CANCELED_BY_ADMIN, PARTICIPATION_CANCELED_BY_USER, PARTICIPATION_REJECTED] as $status) {
            dbExecute("UPDATE participation_requests SET status = ? WHERE id = ?", [$status, $requestId]);
            $this->assertSame($before, missionRosterSignature($this->missionId), $status . ' takes them off the roster');
            dbExecute("UPDATE participation_requests SET status = ? WHERE id = ?", [PARTICIPATION_APPROVED, $requestId]);
        }
    }

    public function testMovingSomebodyToAnotherShiftChangesIt(): void
    {
        $requestId = $this->request($this->shiftId, $this->makeUser('Δ'), PARTICIPATION_APPROVED);
        $before = missionRosterSignature($this->missionId);

        dbExecute("UPDATE participation_requests SET shift_id = ? WHERE id = ?", [$this->otherShiftId, $requestId]);

        $this->assertNotSame($before, missionRosterSignature($this->missionId), 'the roster card lists them under the other shift now');
    }

    public function testAnotherMissionsApprovalsDoNotMoveIt(): void
    {
        $before = missionRosterSignature($this->missionId);
        $otherMissionShift = $this->makeShift($this->makeMission());

        $this->request($otherMissionShift, $this->makeUser('Ε'), PARTICIPATION_APPROVED);

        $this->assertSame($before, missionRosterSignature($this->missionId));
    }

    public function testThingsThatMoveOnTheirOwnDoNotMoveIt(): void
    {
        $volunteerId = $this->makeUser('ΣΤ');
        $requestId = $this->request($this->shiftId, $volunteerId, PARTICIPATION_APPROVED);
        $before = missionRosterSignature($this->missionId);

        // What the poll already redraws in place: attendance, a field status, a name.
        dbExecute("UPDATE participation_requests SET attended = 1 WHERE id = ?", [$requestId]);
        dbExecute("UPDATE users SET name = ? WHERE id = ?", ['Άλλο όνομα', $volunteerId]);

        $this->assertSame($before, missionRosterSignature($this->missionId));
    }

    // --- wiring (source pins; the gate needs a signed-in session) ----------

    public function testOnlyCommandStaffAreGivenTheFingerprint(): void
    {
        $page = file_get_contents(__DIR__ . '/../war-room.php');

        $this->assertStringContainsString('$rosterSig = $canManageWarRoom ? missionRosterSignature($missionId) : null;', $page);
        $this->assertStringContainsString("'rosterSig' => \$rosterSig,", $page);
        $this->assertStringContainsString('const ROSTER_SIG = <?= json_encode($rosterSig) ?>;', $page);
    }

    public function testAMismatchReloadsThroughTheSoftReloadWithItsOwnNotice(): void
    {
        $page = file_get_contents(__DIR__ . '/../war-room.php');

        $this->assertStringContainsString(
            "else if (data.rosterSig && ROSTER_SIG && data.rosterSig !== ROSTER_SIG) reloadForGpsChange(false, 'roster');",
            $page
        );
        $this->assertStringContainsString('function reloadForGpsChange(extend, reason) {', $page);
        $this->assertStringContainsString("t(noticeKey + '.countdown', {s: left})", $page);
        $this->assertStringContainsString("t(noticeKey + '.waiting')", $page);
        // The switch on this screen still asks for the GPS wording.
        $this->assertStringContainsString('reloadForGpsChange(true);', $page);
    }

    public function testTheNoticeExistsInBothLanguages(): void
    {
        $strings = loadLangStrings('war-room');

        foreach (['el', 'en'] as $lang) {
            foreach (['roster_reload.countdown', 'roster_reload.waiting'] as $key) {
                $this->assertNotEmpty($strings[$lang][$key] ?? '', "$lang is missing $key");
            }
            $this->assertStringContainsString('{s}', $strings[$lang]['roster_reload.countdown']);
        }
        $this->assertNotSame($strings['en']['gps_reload.countdown'], $strings['en']['roster_reload.countdown'], 'it must not say that GPS changed');
    }
}
