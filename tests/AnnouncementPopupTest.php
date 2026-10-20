<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The «Νέα ανακοίνωση» popup is shown once, and never to guests (v3.396.0).
 *
 * Two reports, one root each:
 *
 *  - Partner-organisation guests and mission visitors got the popup back on
 *    every refresh. Their account may only reach the Action Room's own
 *    scripts, so the «Κατάλαβα» request to announcement-dismiss.php was
 *    answered with a 302 to the war room, nothing was written, and the next
 *    page load showed the popup again. They are now shown none at all.
 *  - Everybody else got it back whenever that request did not arrive: a weak
 *    signal in the field, a tab refreshed with the popup open, the page
 *    reloading under it - the popup used to hide on `.finally()`, success or
 *    not. It is now recorded as shown the moment footer.php writes it out.
 *
 * The loaders run against the test database in a transaction that is always
 * rolled back; the wiring is pinned as source because header.php and footer.php
 * need a signed-in browser session to execute.
 */
final class AnnouncementPopupTest extends TestCase
{
    protected function setUp(): void
    {
        db()->beginTransaction();
        // Whatever the shared test database holds must not leak into the counts.
        dbExecute("UPDATE announcements SET is_active = 0");
    }

    protected function tearDown(): void
    {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
    }

    private function makeUser(string $name, bool $external = false, bool $visitor = false): int
    {
        return (int) dbInsert(
            "INSERT INTO users (name, email, password, is_external, is_mission_visitor) VALUES (?, ?, ?, ?, ?)",
            [$name, 'ann-' . uniqid('', true) . '@example.invalid', 'x', $external ? 1 : 0, $visitor ? 1 : 0]
        );
    }

    /** $age: seconds ago, so the order is explicit rather than left to the clock. */
    private function announce(string $title, int $age = 0, bool $active = true): int
    {
        return (int) dbInsert(
            "INSERT INTO announcements (title, body, is_active, created_at) VALUES (?, ?, ?, ?)",
            [$title, 'body of ' . $title, $active ? 1 : 0, date('Y-m-d H:i:s', time() - $age)]
        );
    }

    private function dismissedBy(int $userId): array
    {
        return array_map('intval', array_column(
            dbFetchAll("SELECT announcement_id FROM announcement_dismissals WHERE user_id = ? ORDER BY announcement_id", [$userId]),
            'announcement_id'
        ));
    }

    // --- guests ------------------------------------------------------------

    public function testAPartnerGuestIsShownNothingAndNothingIsWritten(): void
    {
        $guest = $this->makeUser('Partner guest', true);
        $this->announce('One');
        $this->announce('Two', 10);
        $this->announce('Three', 20);
        $this->announce('Four', 30);

        $digest = loadPendingAnnouncementsForUser($guest, true);

        $this->assertSame([], $digest['pending']);
        $this->assertSame(0, $digest['earlier']);
        $this->assertSame([], $this->dismissedBy($guest), 'the older ones are not folded away for somebody who is shown none');
    }

    public function testAMissionVisitorIsTreatedTheSameWay(): void
    {
        $visitor = $this->makeUser('Mission visitor', true, true);
        $this->announce('One');

        $this->assertSame([], loadPendingAnnouncementsForUser($visitor, true)['pending']);
    }

    public function testTheHeaderAsksWithTheRealGuestFlagAndTheDenominatorLeavesGuestsOut(): void
    {
        $header = file_get_contents(__DIR__ . '/../includes/header.php');
        $this->assertStringContainsString('loadPendingAnnouncementsForUser((int) $uid, isExternalGuest())', $header);

        $admin = file_get_contents(__DIR__ . '/../announcements.php');
        $this->assertStringContainsString('AND deleted_at IS NULL AND is_external = 0', $admin);
    }

    public function testIsExternalGuestCoversBothKindsOfGuest(): void
    {
        // The gate that stops them reaching announcement-dismiss.php is the same
        // flag: a partner guest and a mission visitor are both is_external.
        $this->assertSame(1, (int) dbFetchValue("SELECT is_external FROM users WHERE id = ?", [$this->makeUser('G', true)]));
        $this->assertSame(1, (int) dbFetchValue("SELECT is_external FROM users WHERE id = ?", [$this->makeUser('V', true, true)]));
        $this->assertNotContains(
            'announcement-dismiss.php',
            array_merge(WAR_ROOM_ACTION_SCRIPTS, WAR_ROOM_GUEST_PAGES, WAR_ROOM_PARTNER_GUEST_PAGES),
            'if it ever joins the guest lists, revisit: guests are meant to see no announcements at all'
        );
    }

    // --- everybody else: once -----------------------------------------------

    public function testTheLatestThreeAreShownNewestFirstAndOlderOnesFoldedAway(): void
    {
        $user = $this->makeUser('Volunteer');
        $newest = $this->announce('Newest', 0);
        $second = $this->announce('Second', 10);
        $third = $this->announce('Third', 20);
        $fourth = $this->announce('Fourth', 30);
        $fifth = $this->announce('Fifth', 40);
        $this->announce('Switched off', 5, false);

        $digest = loadPendingAnnouncementsForUser($user, false);

        $this->assertSame([$newest, $second, $third], array_map(fn($a) => (int) $a['id'], $digest['pending']));
        $this->assertSame(2, $digest['earlier']);
        $this->assertSame([$fourth, $fifth], $this->dismissedBy($user), 'the folded-away ones are dismissed on the spot');
    }

    public function testMarkingTheShownOnesMeansTheNextLoadHasNothing(): void
    {
        $user = $this->makeUser('Volunteer');
        $this->announce('A');
        $this->announce('B', 10);

        $shown = loadPendingAnnouncementsForUser($user, false)['pending'];
        $this->assertCount(2, $shown);

        // What footer.php does as it writes the popup out - no button involved.
        markAnnouncementsDismissed($user, array_column($shown, 'id'));

        $this->assertSame([], loadPendingAnnouncementsForUser($user, false)['pending'], 'a refresh must not bring it back');
        $this->assertSame([], loadPendingAnnouncementsForUser($user, false)['pending'], 'nor the one after that');
    }

    public function testMarkingIsIdempotentAndPerUser(): void
    {
        $first = $this->makeUser('First');
        $second = $this->makeUser('Second');
        $announcementId = $this->announce('A');

        markAnnouncementsDismissed($first, [$announcementId]);
        markAnnouncementsDismissed($first, [$announcementId, $announcementId]);
        markAnnouncementsDismissed($first, []);
        markAnnouncementsDismissed($first, [0, 'junk']);

        $this->assertSame([$announcementId], $this->dismissedBy($first));
        $this->assertSame([$announcementId], array_map(fn($a) => (int) $a['id'], loadPendingAnnouncementsForUser($second, false)['pending']), 'somebody else has not seen it yet');
    }

    public function testANewAnnouncementIsShownOnceMoreAfterTheOldOnesAreDone(): void
    {
        $user = $this->makeUser('Volunteer');
        $this->announce('Old', 100);
        markAnnouncementsDismissed($user, array_column(loadPendingAnnouncementsForUser($user, false)['pending'], 'id'));
        $this->assertSame([], loadPendingAnnouncementsForUser($user, false)['pending']);

        $fresh = $this->announce('Fresh', 0);

        $this->assertSame([$fresh], array_map(fn($a) => (int) $a['id'], loadPendingAnnouncementsForUser($user, false)['pending']));
    }

    // --- wiring (source pins) -----------------------------------------------

    public function testTheFooterRecordsThePopupWhenItWritesItOut(): void
    {
        $footer = file_get_contents(__DIR__ . '/../includes/footer.php');

        $start = strpos($footer, '<?php if (!empty($pendingAnnouncements)): ?>');
        $this->assertNotFalse($start);
        $mark = strpos($footer, 'markAnnouncementsDismissed((int) getCurrentUserId(), array_column($pendingAnnouncements, \'id\'));', $start);
        $modal = strpos($footer, 'id="vo-announcement-modal"', $start);
        $this->assertNotFalse($mark, 'the popup is recorded as shown where it is written out');
        $this->assertLessThan($modal, $mark, 'and before the markup, so a request that dies later still counts');
    }

    public function testClosingNeverWaitsForTheNetworkAndOtherTabsFollow(): void
    {
        $footer = file_get_contents(__DIR__ . '/../includes/footer.php');

        $this->assertStringNotContainsString('.finally(function() {', substr($footer, (int) strpos($footer, 'vo-announcement-dismiss-btn')), 'hiding on finally() is what made a failed request look like a dismissal');
        $this->assertStringContainsString('rememberClosed();', $footer);
        $this->assertStringContainsString("window.addEventListener('storage'", $footer);
        $this->assertStringContainsString('if (!closedAlready()) modal.show();', $footer);
    }

    public function testTheDismissEndpointUsesTheSameWriter(): void
    {
        $endpoint = file_get_contents(__DIR__ . '/../announcement-dismiss.php');

        $this->assertStringContainsString("markAnnouncementsDismissed(\$userId, \$input['ids'] ?? []);", $endpoint);
        $this->assertStringNotContainsString('INSERT IGNORE', $endpoint, 'one writer, not two copies of the SQL');
    }
}
