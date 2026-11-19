<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Automatic PAR (Personnel Accountability Report) — the dead-man's switch
 * (v3.501.0). A command-only checkbox + interval (missions.par_enabled /
 * par_interval_minutes, set from the console's Προετοιμασία panel) and a
 * per-participant «Είμαστε ΟΚ» clock (mission_par_checkins), checked on every
 * GPS ping by checkParDue() exactly like checkArrivalPrompts()/
 * checkSectorExitWarnings() above it in functions-warroom.php.
 *
 * Every Action Room participant answers for themselves — this is never
 * team-scoped — and a missed check-in is visual only (loadParStatusForMission()
 * for the console's team badges): nothing here escalates anything.
 *
 * Runs inside a transaction that is always rolled back.
 */
final class ParCheckinTest extends TestCase
{
    private int $missionId;
    private int $shiftId;
    private int $adminId;
    private int $teamId;
    private int $member;
    private int $teammate;

    protected function setUp(): void
    {
        db()->beginTransaction();

        $this->adminId = $this->makeUser('PAR Admin');
        dbExecute("UPDATE users SET role = ? WHERE id = ?", [ROLE_DEPARTMENT_ADMIN, $this->adminId]);
        $missionTypeId = (int) dbFetchValue("SELECT id FROM mission_types ORDER BY id LIMIT 1");
        $this->missionId = (int) dbInsert(
            "INSERT INTO missions (title, location, start_datetime, end_datetime, mission_type_id, status, show_in_ops, responsible_user_id) VALUES (?, ?, ?, ?, ?, ?, 1, ?)",
            ['PAR Mission', 'Ηράκλειο', date('Y-m-d H:i:s', time() - 7200), date('Y-m-d H:i:s', time() + 7200), $missionTypeId, STATUS_OPEN, $this->adminId]
        );
        $this->shiftId = (int) dbInsert(
            "INSERT INTO shifts (mission_id, start_time, end_time) VALUES (?, ?, ?)",
            [$this->missionId, date('Y-m-d H:i:s', time() - 3600), date('Y-m-d H:i:s', time() + 3600)]
        );
        $this->teamId = (int) dbInsert(
            "INSERT INTO mission_teams (mission_id, codename, team_number, created_by) VALUES (?, ?, ?, ?)",
            [$this->missionId, 'ΑΛΦΑ', 1, $this->adminId]
        );
        $this->member = $this->makeVolunteer('Νίκος Ν.');
        $this->teammate = $this->makeVolunteer('Ελένη Ε.');
        setActionRoomParticipation($this->missionId, $this->member, true, $this->adminId);
        setActionRoomParticipation($this->missionId, $this->teammate, true, $this->adminId);
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
            [$name, 'par-' . uniqid('', true) . '@example.invalid', 'x']
        );
    }

    private function makeVolunteer(string $name): int
    {
        $id = $this->makeUser($name);
        dbInsert(
            "INSERT INTO participation_requests (shift_id, volunteer_id, status) VALUES (?, ?, ?)",
            [$this->shiftId, $id, PARTICIPATION_APPROVED]
        );
        dbInsert(
            "INSERT INTO mission_team_members (team_id, mission_id, user_id) VALUES (?, ?, ?)",
            [$this->teamId, $this->missionId, $id]
        );
        return $id;
    }

    private function enablePar(int $minutes = 30): void
    {
        dbExecute("UPDATE missions SET par_enabled = 1, par_interval_minutes = ? WHERE id = ?", [$minutes, $this->missionId]);
    }

    private function checkinRow(int $userId): ?array
    {
        $row = dbFetchOne("SELECT last_ok_at, last_prompted_at FROM mission_par_checkins WHERE mission_id = ? AND user_id = ?", [$this->missionId, $userId]);
        return $row === false ? null : $row;
    }

    private function notices(int $userId): array
    {
        return dbFetchAll(
            "SELECT title, message, data FROM notifications WHERE user_id = ? AND data LIKE '%mission_par_checkin%'",
            [$userId]
        );
    }

    public function testOffByDefaultDoesNothingAtAll(): void
    {
        checkParDue($this->missionId, $this->member);
        $this->assertNull($this->checkinRow($this->member));
        $this->assertSame([], $this->notices($this->member));
        $this->assertNull(loadParStatusForMission($this->missionId));
    }

    public function testFirstPingAfterEnablingStartsTheClockWithoutAsking(): void
    {
        $this->enablePar(30);
        checkParDue($this->missionId, $this->member);
        $row = $this->checkinRow($this->member);
        $this->assertNotNull($row);
        $this->assertNull($row['last_prompted_at']);
        $this->assertSame([], $this->notices($this->member), 'the clock just started: nothing to ask yet');
    }

    public function testNotYetDueAsksNothing(): void
    {
        $this->enablePar(30);
        dbInsert(
            "INSERT INTO mission_par_checkins (mission_id, user_id, last_ok_at) VALUES (?, ?, DATE_SUB(NOW(), INTERVAL 10 MINUTE))",
            [$this->missionId, $this->member]
        );
        checkParDue($this->missionId, $this->member);
        $this->assertSame([], $this->notices($this->member));
    }

    public function testOverdueAsksOnceAndNotAgainUntilAnswered(): void
    {
        $this->enablePar(30);
        dbInsert(
            "INSERT INTO mission_par_checkins (mission_id, user_id, last_ok_at) VALUES (?, ?, DATE_SUB(NOW(), INTERVAL 31 MINUTE))",
            [$this->missionId, $this->member]
        );
        checkParDue($this->missionId, $this->member);
        $notices = $this->notices($this->member);
        $this->assertCount(1, $notices, 'overdue: asked once');
        $data = json_decode($notices[0]['data'], true);
        $this->assertSame('mission_par_checkin', $data['popupInfo']);
        $this->assertSame($this->missionId, $data['bannerMission'], 'order-class: a popup on the page, the loud channel on the phone');
        $this->assertSame(
            ['kind' => 'info', 'info' => 'mission_par_checkin', 'id' => 0],
            notificationPopupRef($data)
        );

        // Still overdue, more pings arrive: not nagged again.
        checkParDue($this->missionId, $this->member);
        checkParDue($this->missionId, $this->member);
        $this->assertCount(1, $this->notices($this->member), 'one ask per overdue stint, not one per ping');
        $this->assertSame([], $this->notices($this->teammate), 'nobody else is asked for this person\'s check-in');
    }

    public function testCheckingInResetsTheClockAndAllowsBeingAskedAgainLater(): void
    {
        $this->enablePar(5);
        dbInsert(
            "INSERT INTO mission_par_checkins (mission_id, user_id, last_ok_at) VALUES (?, ?, DATE_SUB(NOW(), INTERVAL 6 MINUTE))",
            [$this->missionId, $this->member]
        );
        checkParDue($this->missionId, $this->member);
        $this->assertCount(1, $this->notices($this->member));

        // «Είμαστε ΟΚ» — mission-par.php's own upsert, reset to now.
        dbExecute(
            "INSERT INTO mission_par_checkins (mission_id, user_id, last_ok_at, last_prompted_at) VALUES (?, ?, NOW(), NULL)
             ON DUPLICATE KEY UPDATE last_ok_at = NOW(), last_prompted_at = NULL",
            [$this->missionId, $this->member]
        );
        $row = $this->checkinRow($this->member);
        $this->assertNull($row['last_prompted_at']);

        // Not overdue again straight away.
        checkParDue($this->missionId, $this->member);
        $this->assertCount(1, $this->notices($this->member), 'still just the one, from before the check-in');

        // Gone stale again, a whole interval later: asked again.
        dbExecute("UPDATE mission_par_checkins SET last_ok_at = DATE_SUB(NOW(), INTERVAL 6 MINUTE) WHERE mission_id = ? AND user_id = ?", [$this->missionId, $this->member]);
        checkParDue($this->missionId, $this->member);
        $this->assertCount(2, $this->notices($this->member));
    }

    public function testLoadParStatusListsOnlyThoseActuallyOverdue(): void
    {
        $this->enablePar(30);
        dbInsert(
            "INSERT INTO mission_par_checkins (mission_id, user_id, last_ok_at) VALUES (?, ?, DATE_SUB(NOW(), INTERVAL 40 MINUTE))",
            [$this->missionId, $this->member]
        );
        dbInsert(
            "INSERT INTO mission_par_checkins (mission_id, user_id, last_ok_at) VALUES (?, ?, DATE_SUB(NOW(), INTERVAL 5 MINUTE))",
            [$this->missionId, $this->teammate]
        );
        $status = loadParStatusForMission($this->missionId);
        $this->assertNotNull($status);
        $this->assertTrue($status['enabled']);
        $this->assertSame(30, $status['intervalMinutes']);
        $this->assertSame([$this->member], $status['overdueUserIds']);
    }

    public function testAParticipantWithNoCheckinRowYetIsNeverOverdue(): void
    {
        $this->enablePar(30);
        $status = loadParStatusForMission($this->missionId);
        $this->assertSame([], $status['overdueUserIds'], 'their clock has not started yet');
    }

    public function testSomeoneWhoIsNotAnActionRoomParticipantIsNeverAskedOrListed(): void
    {
        $this->enablePar(5);
        $outsider = $this->makeUser('Χωρίς GPS');
        dbInsert(
            "INSERT INTO participation_requests (shift_id, volunteer_id, status) VALUES (?, ?, ?)",
            [$this->shiftId, $outsider, PARTICIPATION_APPROVED]
        );
        // Never ticked for the Action Room — checkParDue still starts a clock for
        // them if called directly (callers only reach it via a GPS ping, which an
        // unticked volunteer's page never sends — see the .send-ping gate), but
        // the console's status must never count them.
        dbInsert(
            "INSERT INTO mission_par_checkins (mission_id, user_id, last_ok_at) VALUES (?, ?, DATE_SUB(NOW(), INTERVAL 10 MINUTE))",
            [$this->missionId, $outsider]
        );
        $status = loadParStatusForMission($this->missionId);
        $this->assertNotContains($outsider, $status['overdueUserIds']);
    }

    public function testARealPingThroughTheServerStartsTheClockAndThenAsks(): void
    {
        dbExecute(
            "INSERT INTO settings (setting_key, setting_value, updated_at) VALUES ('war_room_gps_smoothing', '0', NOW())
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()"
        );
        $this->enablePar(30);
        $user = dbFetchOne("SELECT * FROM users WHERE id = ?", [$this->member]);

        $result = recordVolunteerPing($user, $this->shiftId, 35.2, 24.9, 8.0, 80, 'auto', 'browser');
        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $this->assertNotNull($this->checkinRow($this->member));
        $this->assertSame([], $this->notices($this->member));

        dbExecute("UPDATE mission_par_checkins SET last_ok_at = DATE_SUB(NOW(), INTERVAL 31 MINUTE) WHERE mission_id = ? AND user_id = ?", [$this->missionId, $this->member]);
        dbExecute("UPDATE volunteer_pings SET created_at = DATE_SUB(created_at, INTERVAL 1 HOUR) WHERE user_id = ?", [$this->member]);
        $result = recordVolunteerPing($user, $this->shiftId, 35.2001, 24.9001, 8.0, 80, 'auto', 'browser');
        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $this->assertCount(1, $this->notices($this->member));
    }

    public function testThePingHooksItAndTheEnglishWordingExists(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../includes/functions-warroom.php');
        $start = strpos($source, 'function recordVolunteerPing(');
        $end = strpos($source, "\nfunction ", $start + 10);
        $this->assertNotFalse($start);
        $this->assertStringContainsString('checkParDue(', substr($source, $start, $end - $start), 'recordVolunteerPing() must call it on every fix');

        $lang = loadLangStrings('war-room');
        foreach (['el', 'en'] as $code) {
            foreach (['par.title', 'par.message', 'popup.type.par_checkin', 'popup.btn.par_ok', 'popup.par_ok_toast',
                      'console.par_title', 'console.par_enable', 'console.par_interval_label', 'console.par_minutes',
                      'console.par_hint', 'console.badge_par'] as $key) {
                $this->assertArrayHasKey($key, $lang[$code], "$code $key");
            }
        }
    }
}
