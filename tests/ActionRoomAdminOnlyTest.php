<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Command staff in the Action Room are ADMIN ACCOUNTS only (v3.389.1).
 *
 * canManageActionRoom() used to be true for three kinds of person: an admin,
 * a user whose custom role carries «Διαχείριση Αποστολών» (an Υπεύθυνος ΤΕΠ),
 * and whoever was named the mission's responsible (an Υπεύθυνος Αποστολής).
 * The last two held more access elsewhere in the app and were silently handed
 * the whole command console in the Action Room: command menu, Διοίκηση tab,
 * «Όψη Διοίκησης», every command card. The Action Room is run by the admin
 * accounts; everyone else sees it as the plain user they are there.
 *
 * Two layers, both pinned:
 *  - the pure account-type rule and the recipient filter that decides who is
 *    sent command alerts (it must agree, or people are notified about screens
 *    they can no longer open), run against the test database in a transaction
 *    that is always rolled back;
 *  - the places that read the rule, as source pins, because the gate lives in
 *    functions that need a signed-in session to call.
 */
final class ActionRoomAdminOnlyTest extends TestCase
{
    protected function setUp(): void
    {
        db()->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
    }

    private function makeUser(string $role, ?int $customRoleId = null): int
    {
        return (int) dbInsert(
            "INSERT INTO users (name, email, password, role, custom_role_id, is_active) VALUES (?, ?, ?, ?, ?, 1)",
            ['Admin-only ' . $role, 'ao-' . uniqid('', true) . '@example.invalid', 'x', $role, $customRoleId]
        );
    }

    /** A custom role that carries «Διαχείριση Αποστολών», the way an Υπεύθυνος ΤΕΠ would hold it. */
    private function missionsManageRole(): int
    {
        $roleId = (int) dbInsert(
            "INSERT INTO custom_roles (name) VALUES (?)",
            ['TEP responsible ' . uniqid('', true)]
        );
        dbExecute("INSERT INTO custom_role_permissions (role_id, page_slug) VALUES (?, 'missions_manage')", [$roleId]);
        return $roleId;
    }

    public function testOnlyAdminAccountTypesCountAsAdministrators(): void
    {
        $this->assertTrue(isActionRoomAdminRole(ROLE_SYSTEM_ADMIN));
        $this->assertTrue(isActionRoomAdminRole(ROLE_DEPARTMENT_ADMIN));
        $this->assertFalse(isActionRoomAdminRole(ROLE_SHIFT_LEADER));
        $this->assertFalse(isActionRoomAdminRole(ROLE_VOLUNTEER));
        $this->assertFalse(isActionRoomAdminRole(null));
        $this->assertFalse(isActionRoomAdminRole(''));
    }

    public function testCommandAlertsGoOnlyToAdminAccounts(): void
    {
        $tepRole = $this->missionsManageRole();

        $systemAdmin     = $this->makeUser(ROLE_SYSTEM_ADMIN);
        $deptAdminPlain  = $this->makeUser(ROLE_DEPARTMENT_ADMIN);               // no permission, not responsible
        $deptAdminTep    = $this->makeUser(ROLE_DEPARTMENT_ADMIN, $tepRole);     // admin account WITH missions_manage
        $shiftLeader     = $this->makeUser(ROLE_SHIFT_LEADER);
        $tepVolunteer    = $this->makeUser(ROLE_VOLUNTEER, $tepRole);            // the Υπεύθυνος ΤΕΠ
        $tepShiftLeader  = $this->makeUser(ROLE_SHIFT_LEADER, $tepRole);
        $volunteerLead   = $this->makeUser(ROLE_VOLUNTEER);                      // named the mission's responsible

        $all = [$systemAdmin, $deptAdminPlain, $deptAdminTep, $shiftLeader, $tepVolunteer, $tepShiftLeader, $volunteerLead];

        $heard = filterActionRoomManagers($all, $volunteerLead);
        sort($heard);
        $expected = [$systemAdmin, $deptAdminTep];
        sort($expected);
        $this->assertSame($expected, $heard,
            'a volunteer-account responsible, a shift leader and a ΤΕΠ role holder are plain users in the Action Room');

        // The responsible user hears command alerts only when they ARE an admin account.
        $heard = filterActionRoomManagers($all, $deptAdminPlain);
        sort($heard);
        $expected = [$systemAdmin, $deptAdminTep, $deptAdminPlain];
        sort($expected);
        $this->assertSame($expected, $heard, 'an admin-account responsible still counts, as before');
    }

    public function testAnExternalGuestIsNeverCommandStaffEvenAsTheResponsible(): void
    {
        $guest = $this->makeUser(ROLE_DEPARTMENT_ADMIN);
        dbExecute("UPDATE users SET is_external = 1 WHERE id = ?", [$guest]);

        $this->assertSame([], filterActionRoomManagers([$guest], $guest));
    }

    private function source(string $file): string
    {
        return (string) file_get_contents(__DIR__ . '/../' . $file);
    }

    public function testBothManageFunctionsRefuseNonAdminAccountsBeforeAnyPermissionIsConsulted(): void
    {
        $src = $this->source('includes/functions-warroom.php');

        foreach (['function canManageActionRoom(', 'function canManageAnyActionRoom('] as $needle) {
            $start = strpos($src, $needle);
            $this->assertNotFalse($start, "$needle is gone");
            $body = substr($src, $start, 1400);
            $gate = strpos($body, 'if (!isActionRoomAdminAccount()) return false;');
            $perm = strpos($body, "hasPagePermission('missions_manage')");
            $this->assertNotFalse($gate, "$needle must refuse a non-admin account");
            $this->assertNotFalse($perm);
            $this->assertLessThan($perm, $gate, "$needle must check the account type BEFORE the permission or the responsible user");
        }
    }

    public function testThePreviewOfARoleRendersAsThatRolesHolderSeesIt(): void
    {
        $src = $this->source('includes/functions-warroom.php');
        $start = strpos($src, 'function isActionRoomAdminAccount(');
        $this->assertNotFalse($start);
        $body = substr($src, $start, 400);
        $this->assertStringContainsString('if (isPreviewMode()) return false;', $body);
        $this->assertStringNotContainsString('isAdmin()', $body, 'isAdmin() is true for any custom role, the very population this rule excludes');
    }

    public function testTheWarRoomPageReusesTheSameRule(): void
    {
        $src = $this->source('war-room.php');
        $this->assertStringContainsString('$isAdminAccount = isActionRoomAdminAccount();', $src,
            'the layout default and the command rule must be one definition, not two lists of roles');
    }

    public function testTheMissionPageOffersTheActionRoomOnlyToThoseWhoCanOpenIt(): void
    {
        $src = $this->source('mission-view.php');
        $this->assertStringNotContainsString('($canManageMissions || $isResponsible || $isApprovedParticipant)', $src,
            'a responsible volunteer or a ΤΕΠ role holder who is not a participant would be shown a button that opens an access-denied page');
        $start = strpos($src, 'Same gate as war-room.php itself (v3.389.1)');
        $this->assertNotFalse($start, 'the Action Room button on the mission page lost its gate');
        $gate = substr($src, $start, 500);
        $this->assertStringContainsString('canManageActionRoom(', $gate);
        $this->assertStringContainsString('|| $isApprovedParticipant)', $gate);
    }

    public function testCommandAlertRecipientsNoLongerAddTheResponsibleUserUnchecked(): void
    {
        $src = $this->source('includes/functions-warroom.php');
        $start = strpos($src, 'function filterActionRoomManagers(');
        $this->assertNotFalse($start);
        $body = substr($src, $start, 2600);
        $this->assertStringContainsString('AND u.role IN (?, ?)', $body);
        $this->assertStringNotContainsString('$allowed[] = (int) $responsibleUserId;', $body,
            'appending the responsible user after the query would re-admit a volunteer-account Υπεύθυνος');
    }
}
