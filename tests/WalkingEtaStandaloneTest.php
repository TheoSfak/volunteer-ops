<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * v3.357.0 made computeDispatchEta() call fetchWalkingEtaMinutes() for a team
 * on foot, which calls routeDistanceAvailable() - a function in
 * includes/route-distance.php. The real request chain (bootstrap.php) never
 * loads that file, so the Action Room page and poll died with "Call to
 * undefined function routeDistanceAvailable()" the moment a dispatch had a
 * walking ETA to work out.
 *
 * Nothing in this suite noticed, because tests/bootstrap.php loads ai-live.php,
 * which does require route-distance.php: the dependency was always satisfied by
 * accident. So this test runs the call in a fresh PHP process that loads only
 * what bootstrap.php loads, and fails if the function comes up undefined.
 */
final class WalkingEtaStandaloneTest extends TestCase
{
    public function testWalkingEtaWorksWithOnlyTheRealBootstrapChainLoaded(): void
    {
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
require_once $argv[1] . '/includes/functions-warroom.php';
if (function_exists('routeDistanceAvailable')) {
    fwrite(STDERR, "precondition broken: route-distance.php is already loaded\n");
    exit(3);
}
// No Google key in a fresh process, so the answer is null (caller falls back
// to the straight line) - what matters is that it gets as far as answering.
$r = fetchWalkingEtaMinutes(35.30, 25.10, 35.31, 25.11);
echo $r === null ? 'null' : 'array';
PHP;
        $file = tempnam(sys_get_temp_dir(), 'vo_eta_');
        file_put_contents($file, "<?php\n" . $script);
        try {
            $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file) . ' ' . escapeshellarg($root) . ' 2>&1';
            $output = (string) shell_exec($cmd);
        } finally {
            @unlink($file);
        }
        $this->assertStringNotContainsString('undefined function', $output, $output);
        $this->assertStringNotContainsString('Fatal error', $output, $output);
        $this->assertSame('null', trim($output), $output);
    }
}
