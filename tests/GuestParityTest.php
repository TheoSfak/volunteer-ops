<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * A guest has to be able to do everything a plain volunteer can in the Action
 * Room. The audit of 2026-10-16 drove a partner-org guest, a mission visitor
 * and a plain volunteer through the same orders, pins, teams and sectors on one
 * mission: the partner guest matched the volunteer everywhere, bar one routing
 * detail, and the mission visitor — the account type nobody tests as — did not.
 * One test per finding, each pinning the rule that was broken:
 *
 *  1. a visitor was bounced between war-room.php and a page the gate sends them
 *     straight back from (ERR_TOO_MANY_REDIRECTS) whenever the mission left the
 *     board or they left the roster;
 *  2. a visitor was put on the LAST shift of the mission, which with a later
 *     shift had not begun, so "everybody on duty" skipped them;
 *  3. removing a visitor switched the account off and left the person on every
 *     roster and recipient list, waiting for answers that could not come;
 *  4. the activity and chat exports were offered to a visitor and only bounced;
 *  5. a visitor's invented @mission-visitor.invalid address was mailed by the
 *     shift-reminder cron and the cancel/delete notices;
 *  6. a returning partner guest, with an old mission on the account, was sent to
 *     the mission list instead of the live one.
 *
 * Runs inside a transaction that is always rolled back.
 */
final class GuestParityTest extends TestCase
{
    private int $adminId;
    private int $missionId;
    private int $shiftId;

    protected function setUp(): void
    {
        db()->beginTransaction();
        $this->adminId = $this->makeUser('Parity Admin');
        $this->missionId = $this->makeMission('Parity Mission');
        $this->shiftId = $this->makeShift($this->missionId, -3600, 3600);
        forgetActionRoomParticipantIds($this->missionId);
    }

    protected function tearDown(): void
    {
        db()->rollBack();
        forgetActionRoomParticipantIds($this->missionId);
    }

    // ── fixtures ────────────────────────────────────────────────────────────

    private function makeUser(string $name, array $extra = []): int
    {
        $id = (int) dbInsert(
            "INSERT INTO users (name, email, password) VALUES (?, ?, ?)",
            [$name, 'parity-' . uniqid('', true) . '@example.invalid', 'x']
        );
        foreach ($extra as $column => $value) {
            dbExecute("UPDATE users SET `$column` = ? WHERE id = ?", [$value, $id]);
        }
        return $id;
    }

    private function makeMission(string $title, string $status = STATUS_OPEN, int $showInOps = 1, bool $deleted = false): int
    {
        $typeId = (int) dbFetchValue("SELECT id FROM mission_types ORDER BY id LIMIT 1");
        $id = (int) dbInsert(
            "INSERT INTO missions (title, location, start_datetime, end_datetime, mission_type_id, status, show_in_ops)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [$title, 'Ηράκλειο', date('Y-m-d H:i:s', time() - 7200), date('Y-m-d H:i:s', time() + 86400 * 3), $typeId, $status, $showInOps]
        );
        if ($deleted) {
            dbExecute("UPDATE missions SET deleted_at = NOW() WHERE id = ?", [$id]);
        }
        return $id;
    }

    /** A shift starting $startOffset seconds from now and ending $endOffset seconds from now. */
    private function makeShift(int $missionId, int $startOffset, int $endOffset): int
    {
        return (int) dbInsert(
            "INSERT INTO shifts (mission_id, start_time, end_time) VALUES (?, ?, ?)",
            [$missionId, date('Y-m-d H:i:s', time() + $startOffset), date('Y-m-d H:i:s', time() + $endOffset)]
        );
    }

    private function approve(int $userId, int $shiftId, string $status = PARTICIPATION_APPROVED): void
    {
        dbInsert(
            "INSERT INTO participation_requests (shift_id, volunteer_id, status) VALUES (?, ?, ?)",
            [$shiftId, $userId, $status]
        );
    }

    private function makeVisitor(string $name, ?int $missionId = null): int
    {
        return $this->makeUser($name, [
            'is_external' => 1,
            'is_mission_visitor' => 1,
            'mission_visitor_mission_id' => $missionId ?? $this->missionId,
        ]);
    }

    private function visitorAllowed(): array
    {
        return array_merge(WAR_ROOM_ACTION_SCRIPTS, WAR_ROOM_GUEST_PAGES, ['logout.php', 'mobile-token-check.php']);
    }

    // ── 6. a returning partner guest lands on the live mission ──────────────

    public function testAReturningGuestIsTakenToTheLiveMissionNotTheList(): void
    {
        $guest = $this->makeUser('Returning Guest', ['is_external' => 1]);
        $old = $this->makeMission('Last year\'s exercise', STATUS_CLOSED);
        $this->approve($guest, $this->makeShift($old, -86400 * 30, -86400 * 30 + 3600));
        $this->approve($guest, $this->shiftId);

        $all = getExternalGuestMissionIds($guest);
        $live = getExternalGuestLiveMissionIds($guest);

        $this->assertCount(2, $all, 'the list still shows the history');
        $this->assertSame([$this->missionId], $live);
        $this->assertSame('war-room.php?id=' . $this->missionId, externalGuestLandingUrl($all, $live));
    }

    public function testALiveMissionOffTheBoardOrDeletedIsNotLive(): void
    {
        $guest = $this->makeUser('Guest', ['is_external' => 1]);
        $hidden = $this->makeMission('Off the board', STATUS_OPEN, 0);
        $deleted = $this->makeMission('Deleted', STATUS_OPEN, 1, true);
        $draft = $this->makeMission('Draft', STATUS_DRAFT);
        foreach ([$hidden, $deleted, $draft] as $missionId) {
            $this->approve($guest, $this->makeShift($missionId, -3600, 3600));
        }
        $this->assertSame([], getExternalGuestLiveMissionIds($guest));
    }

    public function testAnUnapprovedGuestHasNoLiveMission(): void
    {
        $guest = $this->makeUser('Guest', ['is_external' => 1]);
        $this->approve($guest, $this->shiftId, PARTICIPATION_PENDING);
        $this->assertSame([], getExternalGuestLiveMissionIds($guest));
    }

    public function testTheLandingRule(): void
    {
        $this->assertSame('missions.php', externalGuestLandingUrl([], []));
        $this->assertSame('war-room.php?id=5', externalGuestLandingUrl([5], [5]));
        $this->assertSame('war-room.php?id=9', externalGuestLandingUrl([5, 9], [9]), 'one live mission wins over the history');
        $this->assertSame('missions.php', externalGuestLandingUrl([5, 9], [5, 9]), 'two live missions: the guest chooses');
        $this->assertSame('war-room.php?id=5', externalGuestLandingUrl([5], []), 'only mission, finished: war-room.php explains it');
        $this->assertSame('missions.php', externalGuestLandingUrl([5, 9], []));
    }

    public function testBootstrapRoutesGuestsThroughTheLandingRule(): void
    {
        $bootstrap = (string) file_get_contents(__DIR__ . '/../bootstrap.php');
        $this->assertStringContainsString('externalGuestLandingUrl(', $bootstrap);
        $this->assertStringContainsString('getExternalGuestLiveMissionIds(', $bootstrap);
    }

    // ── 2. the shift a QR visitor registers on ──────────────────────────────

    public function testAVisitorIsPlacedOnTheShiftRunningNow(): void
    {
        $later = $this->makeShift($this->missionId, 7200, 10800);
        $earlier = $this->makeShift($this->missionId, -10800, -7200);
        $this->assertNotSame($later, missionVisitorShiftId($this->missionId));
        $this->assertNotSame($earlier, missionVisitorShiftId($this->missionId));
        $this->assertSame($this->shiftId, missionVisitorShiftId($this->missionId));
    }

    public function testAVisitorIsPlacedOnTheNextShiftWhenNoneIsRunning(): void
    {
        $mission = $this->makeMission('Between shifts');
        $this->makeShift($mission, -10800, -7200);
        $next = $this->makeShift($mission, 3600, 7200);
        $this->makeShift($mission, 86400, 86400 + 3600);
        $this->assertSame($next, missionVisitorShiftId($mission));
    }

    public function testAVisitorFallsBackToTheShiftThatStartedLast(): void
    {
        $mission = $this->makeMission('Everything is over');
        $this->makeShift($mission, -86400 * 2, -86400 * 2 + 3600);
        $latest = $this->makeShift($mission, -86400, -86400 + 3600);
        $this->assertSame($latest, missionVisitorShiftId($mission));
    }

    public function testAMissionWithNoShiftGivesNothing(): void
    {
        $this->assertNull(missionVisitorShiftId($this->makeMission('No shifts')));
    }

    public function testTheShiftChoiceFollowsTheClockItIsGiven(): void
    {
        $mission = $this->makeMission('Clock');
        $first = $this->makeShift($mission, -86400, -86400 + 7200);
        $second = $this->makeShift($mission, 86400, 86400 + 7200);
        $this->assertSame($first, missionVisitorShiftId($mission, date('Y-m-d H:i:s', time() - 86400 + 60)));
        $this->assertSame($second, missionVisitorShiftId($mission, date('Y-m-d H:i:s', time() + 86400 + 60)));
    }

    public function testAVisitorOnTheRunningShiftIsReachedByEverybodyOnDuty(): void
    {
        // The symptom: a second shift later in the day. The visitor registered
        // on it was not "on duty", so every broadcast and request skipped them.
        $this->makeShift($this->missionId, 7200, 10800);
        $registered = $this->makeVisitor('Visitor on the running shift');
        $this->approve($registered, (int) missionVisitorShiftId($this->missionId));
        setActionRoomParticipation($this->missionId, $registered, true, $this->adminId);

        $this->assertContains($registered, activeMissionRecipientIds($this->missionId));

        // ...and the mechanism the old rule fell into, so the test cannot pass
        // for the wrong reason: the same visitor on the later shift is not.
        $onTheLaterShift = $this->makeVisitor('Visitor on the later shift');
        $this->approve($onTheLaterShift, (int) dbFetchValue("SELECT id FROM shifts WHERE mission_id = ? ORDER BY start_time DESC LIMIT 1", [$this->missionId]));
        setActionRoomParticipation($this->missionId, $onTheLaterShift, true, $this->adminId);
        $this->assertNotContains($onTheLaterShift, activeMissionRecipientIds($this->missionId));
    }

    public function testVisitorJoinUsesTheShiftChoice(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../visitor-join.php');
        $this->assertStringContainsString('missionVisitorShiftId(', $source);
        $this->assertStringNotContainsString('ORDER BY start_time DESC LIMIT 1', $source);
    }

    // ── 3. removing a visitor takes them off the operation ──────────────────

    public function testRemovingAVisitorTakesThemOffTheOperation(): void
    {
        $visitor = $this->makeVisitor('Visitor to remove');
        $other = $this->makeUser('Other member');
        foreach ([$visitor, $other] as $id) {
            $this->approve($id, $this->shiftId);
            setActionRoomParticipation($this->missionId, $id, true, $this->adminId);
        }
        $teamId = (int) dbInsert(
            "INSERT INTO mission_teams (mission_id, codename, team_number, leader_id, created_by) VALUES (?, ?, ?, ?, ?)",
            [$this->missionId, 'ΑΛΦΑ', 1, $visitor, $this->adminId]
        );
        foreach ([$visitor, $other] as $id) {
            dbInsert("INSERT INTO mission_team_members (team_id, mission_id, user_id) VALUES (?, ?, ?)", [$teamId, $this->missionId, $id]);
        }
        $unanswered = (int) dbInsert("INSERT INTO mission_orders (mission_id, order_type, created_by) VALUES (?, 'task', ?)", [$this->missionId, $this->adminId]);
        $answered = (int) dbInsert("INSERT INTO mission_orders (mission_id, order_type, created_by) VALUES (?, 'task', ?)", [$this->missionId, $this->adminId]);
        dbInsert("INSERT INTO mission_order_recipients (order_id, user_id) VALUES (?, ?)", [$unanswered, $visitor]);
        dbInsert("INSERT INTO mission_order_recipients (order_id, user_id) VALUES (?, ?)", [$unanswered, $other]);
        dbInsert("INSERT INTO mission_order_recipients (order_id, user_id, acknowledged_at) VALUES (?, ?, NOW())", [$answered, $visitor]);
        $this->assertContains($visitor, activeMissionRecipientIds($this->missionId), 'precondition: reachable before removal');

        $this->assertTrue(removeMissionVisitor($this->missionId, $visitor, $this->adminId));
        forgetActionRoomParticipantIds($this->missionId);

        $row = dbFetchOne("SELECT is_active, deleted_at, deleted_by FROM users WHERE id = ?", [$visitor]);
        $this->assertSame(0, (int) $row['is_active']);
        $this->assertNotNull($row['deleted_at']);
        $this->assertSame($this->adminId, (int) $row['deleted_by']);
        $this->assertSame(PARTICIPATION_CANCELED_BY_ADMIN, dbFetchValue("SELECT status FROM participation_requests WHERE volunteer_id = ?", [$visitor]));
        $this->assertFalse(isActionRoomParticipant($this->missionId, $visitor));
        $this->assertNotContains($visitor, activeMissionRecipientIds($this->missionId));
        $this->assertSame(0, (int) dbFetchValue("SELECT COUNT(*) FROM mission_team_members WHERE user_id = ?", [$visitor]));
        $this->assertNull(dbFetchValue("SELECT leader_id FROM mission_teams WHERE id = ?", [$teamId]), 'a team they led is left for command to give a new leader');
        $this->assertSame(0, (int) dbFetchValue("SELECT COUNT(*) FROM mission_order_recipients WHERE order_id = ? AND user_id = ?", [$unanswered, $visitor]), 'nobody waits for an answer that cannot come');
        $this->assertSame(1, (int) dbFetchValue("SELECT COUNT(*) FROM mission_order_recipients WHERE order_id = ? AND user_id = ?", [$answered, $visitor]), 'what they did answer stays as history');

        // Everyone else is untouched.
        $this->assertTrue(isActionRoomParticipant($this->missionId, $other));
        $this->assertContains($other, activeMissionRecipientIds($this->missionId));
        $this->assertSame(1, (int) dbFetchValue("SELECT COUNT(*) FROM mission_order_recipients WHERE order_id = ? AND user_id = ?", [$unanswered, $other]));
        $this->assertSame(1, (int) dbFetchValue("SELECT COUNT(*) FROM mission_team_members WHERE user_id = ?", [$other]));
        $this->assertSame(PARTICIPATION_APPROVED, dbFetchValue("SELECT status FROM participation_requests WHERE volunteer_id = ?", [$other]));
    }

    public function testRemovalOnlyEverTouchesThisMissionsVisitors(): void
    {
        $visitor = $this->makeVisitor('Visitor');
        $this->approve($visitor, $this->shiftId);
        $otherMission = $this->makeMission('Another mission');
        $normal = $this->makeUser('Plain volunteer');
        $this->approve($normal, $this->shiftId);

        $this->assertFalse(removeMissionVisitor($otherMission, $visitor, $this->adminId), 'a visitor of a different mission');
        $this->assertFalse(removeMissionVisitor($this->missionId, $normal, $this->adminId), 'a plain account is never removed this way');
        $this->assertSame(1, (int) dbFetchValue("SELECT is_active FROM users WHERE id = ?", [$visitor]));
        $this->assertSame(1, (int) dbFetchValue("SELECT is_active FROM users WHERE id = ?", [$normal]));
        $this->assertSame(PARTICIPATION_APPROVED, dbFetchValue("SELECT status FROM participation_requests WHERE volunteer_id = ?", [$normal]));
    }

    public function testASwitchedOffOrDeletedAccountIsNotAnOrderRecipient(): void
    {
        $user = $this->makeUser('Volunteer');
        $this->approve($user, $this->shiftId);
        setActionRoomParticipation($this->missionId, $user, true, $this->adminId);
        $this->assertContains($user, activeMissionRecipientIds($this->missionId));

        dbExecute("UPDATE users SET is_active = 0 WHERE id = ?", [$user]);
        $this->assertNotContains($user, activeMissionRecipientIds($this->missionId));

        dbExecute("UPDATE users SET is_active = 1, deleted_at = NOW() WHERE id = ?", [$user]);
        $this->assertNotContains($user, activeMissionRecipientIds($this->missionId));

        dbExecute("UPDATE users SET deleted_at = NULL WHERE id = ?", [$user]);
        $this->assertContains($user, activeMissionRecipientIds($this->missionId));
    }

    public function testWarRoomRemovesVisitorsThroughTheOneFunction(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../war-room.php');
        $this->assertStringContainsString('removeMissionVisitor(', $source);
        $this->assertStringNotContainsString('function activeMissionRecipientIds', $source, 'defined once, in includes/, where the tests reach it');
    }

    // ── 1. no redirect loop for a visitor ───────────────────────────────────

    public function testAVisitorIsNeverSentWhereTheGateBouncesThemBack(): void
    {
        $allowed = $this->visitorAllowed();
        foreach (['ended', 'paused', 'removed'] as $reason) {
            foreach (['dashboard.php', 'mission-view.php?id=7', 'profile.php', 'missions.php'] as $default) {
                [$url, $signOut] = actionRoomRefusalExit($reason, $default, true);
                $script = basename((string) parse_url($url, PHP_URL_PATH));
                $this->assertTrue(
                    $signOut || in_array($script, $allowed, true),
                    "a visitor sent to $url while signed in would be redirected straight back (reason $reason, default $default)"
                );
                $this->assertTrue($signOut, 'visitor-join.php is not on a visitor\'s list, so they must be signed out first');
            }
        }
    }

    public function testEveryoneElseKeepsTheirOwnDestination(): void
    {
        foreach (['ended', 'paused', 'removed'] as $reason) {
            $this->assertSame(['dashboard.php', false], actionRoomRefusalExit($reason, 'dashboard.php', false));
            $this->assertSame(['profile.php', false], actionRoomRefusalExit($reason, 'profile.php', false));
        }
    }

    public function testEachRefusalReasonHasItsOwnScreen(): void
    {
        $this->assertSame(['visitor-join.php?ended=1', true], actionRoomRefusalExit('ended', 'dashboard.php', true));
        $this->assertSame(['visitor-join.php?ended=paused', true], actionRoomRefusalExit('paused', 'dashboard.php', true));
        $this->assertSame(['visitor-join.php?ended=removed', true], actionRoomRefusalExit('removed', 'dashboard.php', true));
        $this->assertSame(['visitor-join.php?ended=1', true], actionRoomRefusalExit('anything else', 'dashboard.php', true));

        $lang = loadLangStrings('war-room');
        foreach (['el', 'en'] as $code) {
            foreach (['paused', 'removed'] as $reason) {
                foreach (['', '_detail'] as $suffix) {
                    $key = 'visitor.session_ended_' . $reason . $suffix;
                    $this->assertNotEmpty($lang[$code][$key] ?? '', "$code is missing $key");
                }
            }
        }
        $page = (string) file_get_contents(__DIR__ . '/../visitor-join.php');
        $this->assertStringContainsString("['paused', 'removed']", $page, 'visitor-join.php shows the two new screens');
    }

    public function testWarRoomRefusesThroughOneExitOnly(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../war-room.php');
        // Everything before the first POST handler is the page's access checks.
        $head = substr($source, 0, (int) strpos($source, 'if (isPost()) {'));
        $this->assertGreaterThan(2000, strlen($head), 'the scan must cover the access checks');
        $this->assertStringNotContainsString("redirect('dashboard.php')", $head);
        $this->assertStringNotContainsString("redirect('mission-view.php", $head);
        $this->assertSame(5, substr_count($head, 'leaveActionRoom('), 'the function and its four uses: no mission id, no mission, not on the roster, not live');
    }

    public function testAVisitorWhoseMissionIsGoneIsSignedOut(): void
    {
        $open = $this->makeMission('Open');
        $this->assertTrue(missionVisitorMissionIsOpen($open));
        $this->assertFalse(missionVisitorMissionIsOpen($this->makeMission('Draft', STATUS_DRAFT)));
        $this->assertFalse(missionVisitorMissionIsOpen($this->makeMission('Closed', STATUS_CLOSED)));
        $this->assertFalse(missionVisitorMissionIsOpen($this->makeMission('Deleted but still open', STATUS_OPEN, 1, true)), 'a deleted mission kept the visitor signed in with nothing to show');
        $this->assertFalse(missionVisitorMissionIsOpen(0));
        $this->assertFalse(missionVisitorMissionIsOpen(999999999));

        $bootstrap = (string) file_get_contents(__DIR__ . '/../bootstrap.php');
        $this->assertStringContainsString('missionVisitorMissionIsOpen(', $bootstrap);
        $this->assertStringNotContainsString('SELECT status FROM missions WHERE id = ?', $bootstrap);
    }

    // ── 4. nothing is offered that cannot work ──────────────────────────────

    public function testExportControlsAreNotOfferedToAVisitor(): void
    {
        $lines = file(__DIR__ . '/../war-room.php', FILE_IGNORE_NEW_LINES);
        $seen = 0;
        foreach ($lines as $index => $line) {
            if (!preg_match('~href="exports/export-mission-(activity|chat)\.php~', $line)) {
                continue;
            }
            $seen++;
            $guarded = false;
            for ($back = $index; $back >= max(0, $index - 14); $back--) {
                if (str_contains($lines[$back], '$canExportMissionData')) {
                    $guarded = true;
                    break;
                }
            }
            $this->assertTrue($guarded, 'war-room.php line ' . ($index + 1) . ' offers an export a mission visitor is not allowed to open');
        }
        $this->assertGreaterThanOrEqual(3, $seen, 'activity, general chat room and per-team chat rooms');
        $this->assertContains('export-mission-activity.php', WAR_ROOM_PARTNER_GUEST_PAGES, 'a partner guest keeps the export');
        $this->assertNotContains('export-mission-activity.php', $this->visitorAllowed(), 'which a visitor does not');
        $this->assertNotContains('export-mission-chat.php', $this->visitorAllowed());
        $this->assertStringContainsString('$canExportMissionData = !isMissionVisitor();', (string) file_get_contents(__DIR__ . '/../war-room.php'));
    }

    // ── 5. nothing is mailed to an address that cannot receive ──────────────

    public function testUndeliverableAddresses(): void
    {
        foreach (['mv-12-0a1b2c@mission-visitor.invalid', 'x@y.INVALID', '  a@b.invalid  '] as $address) {
            $this->assertTrue(isUndeliverableEmail($address), $address);
        }
        foreach (['volunteer@example.com', 'a@b.gr', 'invalid@example.com', 'a@invalid.example', ''] as $address) {
            $this->assertFalse(isUndeliverableEmail($address), $address);
        }
    }

    public function testSendEmailSkipsThemWithoutTryingOrLogging(): void
    {
        $before = (int) dbFetchValue("SELECT COUNT(*) FROM email_logs");
        $result = sendEmail('mv-1-abcdef@mission-visitor.invalid', 'Reminder', '<p>Hello</p>');
        $this->assertFalse($result['success']);
        $this->assertTrue($result['skipped'] ?? false, 'a skip, not a failure: callers tell the two apart');
        $this->assertSame($before, (int) dbFetchValue("SELECT COUNT(*) FROM email_logs"), 'no log row for a mail that was never meant to go');

        $real = sendEmail('someone@example.com', 'Reminder', '<p>Hello</p>');
        $this->assertArrayNotHasKey('skipped', $real, 'a real address still goes through the normal path');
    }

    public function testNoMailLeavesTheOneFunnel(): void
    {
        // The skip lives in sendEmail(); it only protects every caller if
        // nothing reaches the SMTP socket or mail() another way.
        $root = dirname(__DIR__);
        $files = array_merge(glob($root . '/*.php') ?: [], glob($root . '/includes/*.php') ?: [], glob($root . '/exports/*.php') ?: []);
        $offenders = [];
        foreach ($files as $file) {
            if (basename($file) === 'email.php') {
                continue;
            }
            $code = (string) file_get_contents($file);
            if (preg_match('~\bsendSmtpEmail\s*\(|@mail\s*\(|(?<![\w>:$])mail\s*\(~', $code)) {
                $offenders[] = basename($file);
            }
        }
        $this->assertSame([], $offenders, 'these send mail without going through sendEmail()');
    }
}
