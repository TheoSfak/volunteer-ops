<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Requests this server sends to somebody else must never be able to hold the
 * site hostage: yphresies.gr stopped finishing page loads while Overpass (the
 * OpenStreetMap server) timed out or answered 502, because every map request
 * held a PHP worker and a database connection for up to 25 s and the host has
 * only a handful of each.
 *
 * What is checked here:
 *  - the diagnostic line (host, status, duration, limit; never a key);
 *  - the pause after a failed Overpass call, and that a paused request does not
 *    touch the network at all;
 *  - the one slot at Overpass for the whole site;
 *  - a REAL server that accepts the connection and never answers: the web call
 *    comes back at its 8 s limit, not later, and the next one is instant;
 *  - the weather and GitHub answers that are remembered, and for how long;
 *  - that the OSM and update code loads and runs with only what bootstrap.php loads.
 *
 * Runs inside a transaction that is always rolled back.
 */
final class ExternalCallGuardTest extends TestCase
{
    /** A tile no real map has, so the cache holds nothing for it. */
    private const TILE = [['zz_guard', 0.0, 0.0, 0.05, 0.05]];

    /** @var resource|null a listening socket that never accepts */
    private $blackHole = null;
    private ?string $logFile = null;
    private $previousLog = null;

    protected function setUp(): void
    {
        require_once __DIR__ . '/../includes/osm.php';
        require_once __DIR__ . '/../includes/weather.php';
        require_once __DIR__ . '/../includes/update-check.php';
        db()->beginTransaction();
        dbExecute("DELETE FROM settings WHERE setting_key IN (?, ?)", [OSM_BREAKER_KEY, UPDATE_CHECK_KEY]);
    }

    protected function tearDown(): void
    {
        osmEndpoints([]);
        if ($this->blackHole !== null) {
            fclose($this->blackHole);
            $this->blackHole = null;
        }
        if ($this->logFile !== null) {
            ini_set('error_log', (string) $this->previousLog);
            @unlink($this->logFile);
            $this->logFile = null;
        }
        db()->rollBack();
    }

    /** Sends error_log() to a scratch file for the rest of the test. */
    private function captureLog(): void
    {
        $this->logFile = (string) tempnam(sys_get_temp_dir(), 'vo_ext_');
        $this->previousLog = ini_set('error_log', $this->logFile);
    }

    private function logged(): string
    {
        return (string) file_get_contents((string) $this->logFile);
    }

    /** A TCP server that completes the handshake and then says nothing, ever. */
    private function startBlackHole(): string
    {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($server, "no listening socket: $errstr");
        $this->blackHole = $server;
        $name = stream_socket_get_name($server, false);
        return 'http://' . $name . '/api/interpreter';
    }

    // --- the log line --------------------------------------------------------

    public function testAFastSuccessIsNotLoggedButAFailureIs(): void
    {
        $this->captureLog();
        externalCallLog('overpass', 'overpass-api.de', 200, microtime(true) - 0.2, 8);
        $this->assertSame('', $this->logged());

        externalCallLog('overpass', 'overpass-api.de', 502, microtime(true) - 1.5, 8, 'Bad Gateway');
        $line = $this->logged();
        $this->assertStringContainsString('[ext] type=overpass host=overpass-api.de status=502', $line);
        $this->assertStringContainsString('timeout=8s', $line);
        $this->assertMatchesRegularExpression('/ms=1[45]\d\d /', $line);
    }

    public function testASlowSuccessIsLoggedToo(): void
    {
        $this->captureLog();
        externalCallLog('github', 'api.github.com', 200, microtime(true) - 4.0, 5);
        $this->assertStringContainsString('status=200', $this->logged());
    }

    public function testKeysAndTokensNeverReachTheLog(): void
    {
        $this->captureLog();
        externalCallLog('openweathermap', 'api.openweathermap.org', 0, microtime(true), 5,
            'Operation timed out for https://api.openweathermap.org/data/2.5/forecast?lat=1&appid=SECRET123&units=metric');
        $line = $this->logged();
        $this->assertStringNotContainsString('SECRET123', $line);
        $this->assertStringContainsString('appid=[hidden]', $line);
        $this->assertStringContainsString('status=0', $line);
    }

    public function testAHostWithJunkInItCannotBreakTheLine(): void
    {
        $this->captureLog();
        externalCallLog("ove rpass\n", "evil.example\n[ext] forged", 500, microtime(true), 8);
        $line = $this->logged();
        $this->assertSame(1, substr_count($line, '[ext]'), $line);
    }

    // --- the pause after a failure -------------------------------------------

    public function testThePauseCanBeSetAndLifted(): void
    {
        $this->assertSame(0, osmOverpassPausedUntil());
        osmOverpassPause();
        $until = osmOverpassPausedUntil();
        $this->assertGreaterThan(time() + OSM_BREAKER_SECONDS - 5, $until);
        $this->assertLessThanOrEqual(time() + OSM_BREAKER_SECONDS, $until);
        osmOverpassResume();
        $this->assertSame(0, osmOverpassPausedUntil());
    }

    public function testAPausedRequestAnswersFromTheCacheWithoutTouchingTheNetwork(): void
    {
        // If it did call, this would hang for the whole web limit.
        osmEndpoints([$this->startBlackHole()]);
        osmOverpassPause();

        $budget = 1;
        $why = null;
        $t0 = microtime(true);
        [, $states] = osmChunkBundle(self::TILE, 'core', $budget, $why);
        $this->assertLessThan(1.0, microtime(true) - $t0, 'a paused request must not wait for anybody');
        $this->assertSame(['zz_guard' => 'failed'], $states);
        $this->assertSame(1, $budget, 'nothing was spent: no call was made');
        $this->assertStringContainsString('did not answer a moment ago', (string) $why);
    }

    public function testBackgroundJobsIgnoreThePause(): void
    {
        // Refused at once (nothing listens on port 1), so this is quick.
        osmEndpoints(['http://127.0.0.1:1/api/interpreter']);
        osmOverpassPause();

        $budget = 1;
        $why = null;
        osmChunkBundle(self::TILE, 'core', $budget, $why, null, true);
        $this->assertSame(0, $budget, 'a background job makes its call even while people are kept away');
    }

    // --- the one slot --------------------------------------------------------

    public function testWhileAnotherRequestHoldsTheSlotThisOneWaitsForNobody(): void
    {
        osmEndpoints([$this->startBlackHole()]);
        $other = new PDO(
            'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS
        );
        $this->assertSame(1, (int) $other->query("SELECT GET_LOCK('" . osmGlobalLockName() . "', 0)")->fetchColumn());
        try {
            $budget = 1;
            $why = null;
            $t0 = microtime(true);
            [, $states] = osmChunkBundle(self::TILE, 'core', $budget, $why);
            $this->assertLessThan(1.0, microtime(true) - $t0);
            $this->assertSame(['zz_guard' => 'pending'], $states, 'the page is told to ask again, not that it failed');
            $this->assertSame(1, $budget);
            $this->assertSame(0, osmOverpassPausedUntil(), 'a busy slot is not a failure');
        } finally {
            $other->query("SELECT RELEASE_LOCK('" . osmGlobalLockName() . "')");
        }
    }

    // --- a server that never answers -----------------------------------------

    public function testAServerThatNeverAnswersCostsAtMostTheWebLimitAndThenNothing(): void
    {
        osmEndpoints([$this->startBlackHole()]);

        $budget = 1;
        $why = null;
        $t0 = microtime(true);
        [, $states] = osmChunkBundle(self::TILE, 'core', $budget, $why);
        $took = microtime(true) - $t0;

        $this->assertSame(['zz_guard' => 'failed'], $states);
        $this->assertLessThan(OSM_WEB_DEADLINE + 3, $took, 'the call must end at its limit, not hang');
        $this->assertGreaterThan(OSM_WEB_DEADLINE - 3, $took, 'it really did wait for the server');
        $this->assertGreaterThan(time(), osmOverpassPausedUntil(), 'a failure pauses Overpass for everybody');

        // The very next request, from anybody, is instant and asks nobody.
        $budget = 1;
        $t0 = microtime(true);
        [, $states] = osmChunkBundle(self::TILE, 'core', $budget, $why);
        $this->assertLessThan(1.0, microtime(true) - $t0);
        $this->assertSame(['zz_guard' => 'failed'], $states);
        $this->assertSame(1, $budget);
    }

    public function testASuccessfulCallLiftsThePause(): void
    {
        // A server that answers properly (one canned reply, no features). This
        // process cannot be both the client and the server, so a child serves it.
        $probe = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($probe);
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);

        $script = (string) tempnam(sys_get_temp_dir(), 'vo_srv_');
        file_put_contents($script, '<?php $srv = stream_socket_server("tcp://127.0.0.1:' . $port . '", $e, $m);'
            . '$s = stream_socket_accept($srv, 20); fread($s, 65536); $b = json_encode(["elements" => []]);'
            . 'fwrite($s, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: " . strlen($b) . "\r\nConnection: close\r\n\r\n" . $b);'
            . 'fclose($s);');
        $proc = proc_open([PHP_BINARY, $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        try {
            usleep(800000); // the child binds the port
            osmEndpoints(['http://127.0.0.1:' . $port . '/api/interpreter']);
            osmOverpassPause();

            // A background caller ignores the pause, so it is the one that finds out Overpass is back.
            $budget = 1;
            $why = null;
            [, $states] = osmChunkBundle(self::TILE, 'core', $budget, $why, null, true);
            $this->assertSame(['zz_guard' => 'fresh'], $states);
            $this->assertSame(0, osmOverpassPausedUntil(), 'an answer means Overpass is back');
        } finally {
            if (is_resource($proc)) { proc_terminate($proc); proc_close($proc); }
            @unlink($script);
        }
    }

    // --- the weather ---------------------------------------------------------

    public function testAFreshWeatherFailureIsRememberedBrieflyAndAGoodAnswerLonger(): void
    {
        $ok = json_encode(['status' => 'ok', 'temp' => 20]);
        $err = json_encode(['status' => 'api_error', 'message' => 'x']);

        $this->assertSame('ok', _owmCacheUsable($ok, 100)['status']);
        $this->assertNull(_owmCacheUsable($ok, WEATHER_CACHE_TTL + 1));

        $this->assertSame('api_error', _owmCacheUsable($err, 10)['status']);
        $this->assertNull(_owmCacheUsable($err, WEATHER_ERROR_TTL + 1), 'the service is asked again after a few minutes');
        $this->assertNull(_owmCacheUsable($err, -3600), 'a failure with a nonsense clock must not stick');
    }

    public function testAnUnreadableWeatherRowIsAskedAgain(): void
    {
        $this->assertNull(_owmCacheUsable(null, 10));
        $this->assertNull(_owmCacheUsable('not json', 10));
        $this->assertNull(_owmCacheUsable(json_encode(['status' => 'too_far']), 10));
    }

    // --- GitHub --------------------------------------------------------------

    public function testTheLastStatusLineOfAResponseWins(): void
    {
        $this->assertSame(0, updateCheckHttpStatus([]));
        $this->assertSame(404, updateCheckHttpStatus(['HTTP/1.1 404 Not Found', 'Content-Type: x']));
        $this->assertSame(200, updateCheckHttpStatus(['HTTP/1.1 302 Found', 'Location: y', 'HTTP/2 200']));
    }

    public function testGithubIsAskedOnceAndThenTheStoredAnswerIsUsed(): void
    {
        $calls = 0;
        $fetch = function () use (&$calls) { $calls++; return ['tag_name' => 'v9.9.9', 'body' => str_repeat('x', 5000)]; };
        $t = 1_800_000_000;

        $first = updateCheckCached(false, $fetch, $t);
        $this->assertSame('v9.9.9', $first['tag_name']);
        $this->assertSame(1, $calls);

        $again = updateCheckCached(false, $fetch, $t + UPDATE_CHECK_OK_TTL - 5);
        $this->assertSame('v9.9.9', $again['tag_name']);
        $this->assertSame(1, $calls, 'inside the window GitHub is not asked');
        $this->assertSame(UPDATE_CHECK_BODY_MAX, mb_strlen($again['body']), 'the stored notes are cut');

        updateCheckCached(false, $fetch, $t + UPDATE_CHECK_OK_TTL + 5);
        $this->assertSame(2, $calls, 'after the window it is');
    }

    public function testThePressedButtonAsksGithubAgainAtOnce(): void
    {
        $calls = 0;
        $fetch = function () use (&$calls) { $calls++; return ['tag_name' => 'v1.0.0']; };
        updateCheckCached(false, $fetch, 1_800_000_000);
        updateCheckCached(true, $fetch, 1_800_000_001);
        $this->assertSame(2, $calls);
    }

    public function testABlockedGithubIsNotRetriedOnEveryPageView(): void
    {
        $calls = 0;
        $down = function () use (&$calls) { $calls++; return ['error' => 'Αδυναμία σύνδεσης με GitHub.']; };
        $t = 1_800_000_000;

        $first = updateCheckCached(false, $down, $t);
        $this->assertArrayHasKey('error', $first);

        $second = updateCheckCached(false, $down, $t + 30);
        $this->assertSame(1, $calls, 'a failure is remembered for a few minutes');
        $this->assertStringContainsString('Αδυναμία σύνδεσης με GitHub.', $second['error']);
        $this->assertStringContainsString('Έλεγχος Τώρα', $second['error'], 'it says how to try again');

        updateCheckCached(false, $down, $t + UPDATE_CHECK_FAIL_TTL + 5);
        $this->assertSame(2, $calls);
    }

    // --- loading ---------------------------------------------------------------

    public function testTheCodeLoadsAndRunsWithOnlyWhatBootstrapLoads(): void
    {
        // tests/bootstrap.php loads far more than bootstrap.php does, so a missing
        // require would pass here and fatal on the live site. A fresh process loads
        // only config, db and functions-core, as the real chain does for these files.
        //
        // The child commits its own work, so the transaction setUp() opened (which
        // holds the settings rows it clears) is replaced by an empty one first.
        db()->rollBack();
        db()->beginTransaction();
        $root = realpath(__DIR__ . '/..');
        $script = <<<'PHP'
define('VOLUNTEEROPS', true);
define('DEBUG_MODE', true);
define('DB_HOST', getenv('TEST_DB_HOST') ?: 'localhost');
define('DB_PORT', getenv('TEST_DB_PORT') ?: '3306');
define('DB_NAME', getenv('TEST_DB_NAME') ?: 'volunteer_ops_test');
define('DB_USER', getenv('TEST_DB_USER') ?: 'root');
define('DB_PASS', getenv('TEST_DB_PASS') ?: '');
require_once $argv[1] . '/config.php';
require_once $argv[1] . '/includes/db.php';
require_once $argv[1] . '/includes/functions-core.php';
require_once $argv[1] . '/includes/osm.php';
require_once $argv[1] . '/includes/update-check.php';
osmEndpoints(['http://127.0.0.1:1/api/interpreter']);
$budget = 1; $why = null;
[, $states] = osmChunkBundle([['zz_fresh', 0.0, 0.0, 0.05, 0.05]], 'core', $budget, $why);
echo $states['zz_fresh'], '|', updateCheckHttpStatus(['HTTP/1.1 204 No Content']);
dbExecute("DELETE FROM osm_feature_cache WHERE tile_key = 'zz_fresh'");
dbExecute("DELETE FROM settings WHERE setting_key = ?", [OSM_BREAKER_KEY]);
PHP;
        $file = tempnam(sys_get_temp_dir(), 'vo_ext_');
        file_put_contents($file, "<?php\n" . $script);
        try {
            $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file) . ' ' . escapeshellarg($root) . ' 2>&1';
            $output = (string) shell_exec($cmd);
        } finally {
            @unlink($file);
        }
        $this->assertStringNotContainsString('undefined function', $output, $output);
        $this->assertStringNotContainsString('Fatal error', $output, $output);
        $this->assertSame('failed|204', trim($output), $output);
    }

    public function testEveryCallerThatWaitsNobodyIsBoundedAndTheOnesThatDoNotAreTagged(): void
    {
        $root = realpath(__DIR__ . '/..');
        $read = fn(string $f) => (string) file_get_contents($root . '/' . $f);

        // The two jobs nobody waits for are the only callers allowed the long deadline.
        $this->assertStringContainsString('$maxAge, true);', $read('includes/osm-bulk.php'));
        $this->assertStringContainsString("'core', \$budget, \$why, null, true);", $read('cron_osm_prewarm.php'));
        // ...and the page a person is looking at is not one of them.
        $this->assertStringContainsString('osmChunkBundle($chunk, $bundle, $budget, $why);', $read('mission-osm.php'));

        // update.php loads what it uses and no longer asks GitHub on every view.
        $update = $read('update.php');
        $this->assertStringContainsString("require_once __DIR__ . '/includes/update-check.php';", $update);
        $this->assertStringContainsString("updateCheckCached(\$forceCheck, 'getLatestRelease')", $update);
        $this->assertStringNotContainsString("= getLatestRelease();", $update);
        $this->assertStringContainsString("'timeout' => UPDATE_CHECK_TIMEOUT", $update);

        // No request made while a person waits may run longer than 8 s.
        $this->assertStringContainsString('CURLOPT_TIMEOUT        => 8,', $read('includes/webpush.php'));
        $this->assertStringContainsString('WEATHER_HTTP_TIMEOUT', $read('includes/weather.php'));
        $this->assertSame(8, OSM_WEB_DEADLINE);
        $this->assertLessThanOrEqual(8, WEATHER_HTTP_TIMEOUT);
        $this->assertLessThanOrEqual(8, UPDATE_CHECK_TIMEOUT);

        // The page gives up on a map request long before the old two minutes.
        $this->assertStringContainsString('setTimeout(() => ctrl.abort(), 30000)', $read('war-room.php'));
    }
}
