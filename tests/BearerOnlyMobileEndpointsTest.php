<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The phone's background service is not logged in as anybody (v3.420.3).
 *
 * The Android app's foreground service shares the WebView's cookie jar, so the
 * alert poll and the position posts it sends with its bearer token also carry
 * the volunteer's session cookie. A partner-org guest is "logged in" through
 * that cookie, bootstrap.php's guest gate then redirected these requests to the
 * guest's landing page, and the service read a 200 HTML page: no order alerts
 * («Value <!DOCTYPE ... cannot be converted to JSONObject») and no stored
 * positions with the screen off. The scripts that need no session now never
 * start one.
 */
final class BearerOnlyMobileEndpointsTest extends TestCase
{
    private array $serverBackup = [];

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
    }

    private static function source(string $file): string
    {
        return (string) file_get_contents(__DIR__ . '/../' . $file);
    }

    private function request(string $script, ?string $authorization, string $key = 'HTTP_AUTHORIZATION'): void
    {
        unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);
        $_SERVER['SCRIPT_NAME'] = '/' . $script;
        if ($authorization !== null) {
            $_SERVER[$key] = $authorization;
        }
    }

    public function testABearerRequestToTheServiceEndpointsStartsNoSession(): void
    {
        foreach (['mobile-ping-location.php', 'mobile-alerts.php', 'mobile-order-ack.php', 'mobile-gps-error.php', 'vehicle-ping.php'] as $script) {
            $this->request($script, 'Bearer abc123DEF456');
            $this->assertTrue(isBearerOnlyMobileRequest(), "$script with a bearer token runs without a session");
        }
    }

    public function testTheRedirectedHeaderSpellingCountsToo(): void
    {
        // Apache with mod_rewrite hands the header over under this name.
        $this->request('mobile-alerts.php', 'Bearer abc123DEF456', 'REDIRECT_HTTP_AUTHORIZATION');
        $this->assertTrue(isBearerOnlyMobileRequest());
    }

    public function testWithoutAUsableBearerTokenTheSessionStaysAsItWas(): void
    {
        $this->request('mobile-alerts.php', null);
        $this->assertFalse(isBearerOnlyMobileRequest(), 'no header: the old 401 answer comes from the endpoint itself');

        foreach (['Basic dXNlcjpwYXNz', 'Bearer', 'Bearer ', 'abc123', ''] as $header) {
            $this->request('mobile-alerts.php', $header);
            $this->assertFalse(isBearerOnlyMobileRequest(), "'$header' is not a bearer token");
        }
    }

    public function testPagesAndMixedEndpointsKeepTheirSession(): void
    {
        // These read who is logged in (token-check compares the token's owner
        // with the session user; the debug log and vitals take either), so a
        // bearer header on them must NOT switch the session off.
        foreach (['mobile-token-check.php', 'mobile-debug-log.php', 'vitals-ingest.php', 'war-room.php', 'dashboard.php', 'ping-location.php'] as $script) {
            $this->request($script, 'Bearer abc123DEF456');
            $this->assertFalse(isBearerOnlyMobileRequest(), "$script needs its session");
        }
    }

    public function testInitSessionReturnsBeforeAnythingStartsASession(): void
    {
        $auth = self::source('includes/auth.php');
        $start = strpos($auth, 'function initSession()');
        $this->assertNotFalse($start);
        $body = substr($auth, $start, 600);

        $this->assertLessThan(
            strpos($body, 'session_status()'),
            strpos($body, 'isBearerOnlyMobileRequest()'),
            'the bearer check comes first in initSession()'
        );
        $this->assertStringContainsString('$_SESSION = [];', $body);
        $this->assertStringContainsString('return;', $body);
    }

    public function testTheListedScriptsReallyNeedNoSession(): void
    {
        // If one of them starts reading the session, it must come off the list.
        foreach (MOBILE_BEARER_ONLY_SCRIPTS as $script) {
            $src = self::source($script);
            $this->assertSame(0, preg_match('/\b(isLoggedIn|requireLogin|getCurrentUser|getCurrentUserId|verifyCsrf|requirePermission)\s*\(/', $src), "$script reads the session");
            $this->assertSame(0, preg_match('/\$_SESSION\s*\[/', $src), "$script reads \$_SESSION");
            $this->assertStringContainsString('HTTP_AUTHORIZATION', $src, "$script authenticates with the bearer token");
        }
        $this->assertCount(5, MOBILE_BEARER_ONLY_SCRIPTS);
    }

    public function testTheGuestGateStillExemptsNothingNew(): void
    {
        // The fix is that these requests are no longer "logged in", not that
        // guests may now open more pages. The allow-lists are as before.
        $this->assertNotContains('mobile-alerts.php', WAR_ROOM_ACTION_SCRIPTS);
        $this->assertNotContains('mobile-alerts.php', WAR_ROOM_PARTNER_GUEST_PAGES);
        $this->assertNotContains('mobile-ping-location.php', WAR_ROOM_ACTION_SCRIPTS);
    }
}
