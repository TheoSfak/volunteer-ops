<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * A partner-org guest and a mission visitor are approved participants who see
 * the same buttons as everyone else in the Action Room, but bootstrap.php sends
 * every request to a script that is not on their allow-list to war-room.php.
 * For an AJAX endpoint that is a 302 to an HTML page, so the page's r.json()
 * throws and the feature fails with no explanation. mission-voice.php,
 * mission-live.php and vitals-ingest.php shipped like that, and so did the
 * missing-person guide and the notification bell's links: nobody noticed
 * because every developer tests as a plain volunteer.
 *
 * This reads the page's own source for every endpoint it calls and refuses a
 * new one that is neither reachable by a guest nor explicitly command-only, so
 * the decision is made when the endpoint is written instead of by a guest in
 * the field. No database needed: it only compares lists.
 */
final class GuestAccessTest extends TestCase
{
    /**
     * Endpoints only command staff may call. Listed here, not inferred, so
     * parking a participant endpoint on this list takes a deliberate edit —
     * and every entry must really carry canManageActionRoom() (asserted below).
     */
    private const COMMAND_ONLY = [
        'api-suggest-replacement.php',
        'mission-action-room-gps.php',
        'mission-annotation.php',
        'mission-assistant.php',
        'mission-battery-alert.php',
    ];

    /** Reachable by a partner-org guest but deliberately not by a mission visitor. */
    private const VISITOR_EXCLUDED_BY_DESIGN = [
        // Issues a long-lived native-app bearer token; bootstrap.php keeps that
        // away from disposable single-mission accounts.
        'mobile-token-issue.php',
    ];

    private static function root(): string
    {
        return dirname(__DIR__);
    }

    /** @return string[] every .php endpoint the Action Room page and its scripts call */
    private static function calledEndpoints(): array
    {
        $sources = array_merge([self::root() . '/war-room.php'], glob(self::root() . '/assets/js/*.js') ?: []);
        $found = [];
        foreach ($sources as $file) {
            $code = (string) file_get_contents($file);
            // fetch(), the page's own fetchWithTimeout()/post() wrappers, the
            // vitals sensor's `endpoint:` option and sendBeacon — a pattern that
            // only knew fetch( missed ping-location.php and volunteer-status.php.
            $pattern = '~(?:\b(?:fetch|post|get|send|ajax)[A-Za-z]*\(\s*|endpoint:\s*|sendBeacon\(\s*)[\'"`]([a-z0-9_-]+\.php)~';
            if (preg_match_all($pattern, $code, $m)) {
                foreach ($m[1] as $endpoint) {
                    $found[$endpoint] = true;
                }
            }
        }
        $names = array_keys($found);
        sort($names);
        return $names;
    }

    private static function guestAllowed(): array
    {
        return array_merge(WAR_ROOM_ACTION_SCRIPTS, WAR_ROOM_GUEST_PAGES, WAR_ROOM_PARTNER_GUEST_PAGES);
    }

    private static function visitorAllowed(): array
    {
        return array_merge(WAR_ROOM_ACTION_SCRIPTS, WAR_ROOM_GUEST_PAGES, ['logout.php', 'mobile-token-check.php']);
    }

    public function testTheScanActuallyFindsTheEndpoints(): void
    {
        // A parser that silently finds nothing would make every test below pass.
        $called = self::calledEndpoints();
        $this->assertGreaterThan(15, count($called));
        foreach (['mission-sos.php', 'mission-voice.php', 'mission-live.php', 'ping-location.php'] as $known) {
            $this->assertContains($known, $called, "$known is called from the Action Room but the scan missed it");
        }
    }

    public function testEveryEndpointIsReachableByAGuestOrIsCommandOnly(): void
    {
        $unreachable = [];
        foreach (self::calledEndpoints() as $endpoint) {
            if (in_array($endpoint, self::COMMAND_ONLY, true)) {
                continue;
            }
            if (!in_array($endpoint, self::guestAllowed(), true)) {
                $unreachable[] = $endpoint;
            }
        }
        $this->assertSame(
            [],
            $unreachable,
            'The Action Room calls these, but bootstrap.php redirects a partner-org guest away from them '
            . '(r.json() then throws). Add each to WAR_ROOM_ACTION_SCRIPTS in includes/auth.php — or, if only '
            . 'command staff use it, to COMMAND_ONLY here after giving it a canManageActionRoom() gate.'
        );
    }

    public function testEveryEndpointIsReachableByAMissionVisitorOrIsExcludedOnPurpose(): void
    {
        $unreachable = [];
        foreach (self::calledEndpoints() as $endpoint) {
            if (in_array($endpoint, self::COMMAND_ONLY, true) || in_array($endpoint, self::VISITOR_EXCLUDED_BY_DESIGN, true)) {
                continue;
            }
            if (!in_array($endpoint, self::visitorAllowed(), true)) {
                $unreachable[] = $endpoint;
            }
        }
        $this->assertSame([], $unreachable, 'Reachable by a partner-org guest but not by a mission visitor, and not excluded on purpose.');
    }

    public function testEveryCommandOnlyEndpointReallyChecksCommandPermission(): void
    {
        foreach (self::COMMAND_ONLY as $endpoint) {
            $path = self::root() . '/' . $endpoint;
            $this->assertFileExists($path, "$endpoint is listed as command-only but does not exist");
            $this->assertStringContainsString(
                'canManageActionRoom(',
                (string) file_get_contents($path),
                "$endpoint is listed as command-only but never calls canManageActionRoom() — a participant endpoint must be allow-listed instead"
            );
        }
    }

    public function testThePagesTheActionRoomLinksToAreOpenToGuests(): void
    {
        // Links rather than fetch() calls, so the scan above cannot see them.
        foreach (['notifications.php', 'missing-person-guide.php', 'briefing-view.php'] as $page) {
            $this->assertContains($page, self::guestAllowed(), "$page is linked from the Action Room / header");
            $this->assertContains($page, self::visitorAllowed(), "$page is linked from the Action Room / header");
        }
    }

    public function testBootstrapBuildsBothGuestListsFromTheSharedConstants(): void
    {
        $bootstrap = (string) file_get_contents(self::root() . '/bootstrap.php');
        $this->assertStringContainsString('WAR_ROOM_GUEST_PAGES', $bootstrap);
        $this->assertStringContainsString('WAR_ROOM_PARTNER_GUEST_PAGES', $bootstrap);
        // A private copy of the list is exactly how mission-incident.php and
        // mission-route.php went missing the first time.
        $this->assertStringNotContainsString("'mission-guest-debrief.php'", $bootstrap);
    }

    public function testEveryAllowListedScriptExists(): void
    {
        // A typo here is silent: the real file stays blocked and nothing fails.
        foreach (array_unique(self::guestAllowed()) as $script) {
            $exists = is_file(self::root() . '/' . $script) || is_file(self::root() . '/exports/' . $script);
            $this->assertTrue($exists, "$script is on a guest allow-list but no such file exists");
        }
    }
}
