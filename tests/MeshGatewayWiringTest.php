<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * The LoRa gateway's plumbing (v3.427.0): the things that only fail in
 * production, where nothing but the real request chain is loaded, or on a
 * screen nobody reloads.
 *
 * tests/bootstrap.php loads every include, so a function the real bootstrap.php
 * never loads passes the whole suite and fatals live (v3.358.1). The first test
 * therefore runs the new file in a fresh process with only what the real
 * bootstrap.php loads. The rest pin that the endpoint is on the allow-list of
 * long-open Action Room scripts, that the page loads the scripts and the window
 * for command staff only, that every sentence exists in both languages, and
 * that the radio's positions stay out of the phone's GPS report.
 */
final class MeshGatewayWiringTest extends TestCase
{
    private static function source(string $file): string
    {
        return (string) \WarRoomSource::read($file);
    }

    public function testTheRealBootstrapLoadsTheMeshFunctions(): void
    {
        $this->assertStringContainsString("includes/functions-mesh.php", self::source('bootstrap.php'));
    }

    public function testEveryMeshFunctionRunsWithOnlyTheRealBootstrapChainLoaded(): void
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
// Exactly the chain bootstrap.php loads for these functions, in its order.
require_once $argv[1] . '/config.php';
require_once $argv[1] . '/includes/db.php';
require_once $argv[1] . '/includes/functions-core.php';
require_once $argv[1] . '/includes/functions-warroom.php';
require_once $argv[1] . '/includes/functions-baro.php';
require_once $argv[1] . '/includes/functions-mesh.php';
// A mission that does not exist: every reader answers empty, and getting
// that far is the point - an undefined function is a fatal in this process.
$out = [
    json_encode(meshParseRecord(['node' => 5, 'id' => 6, 'lat' => 35.1, 'lng' => 25.1])['node']),
    json_encode(meshBlurRadiusM(13) > 1000),
    json_encode(meshShiftFor(0, 0)),
    json_encode(meshBoundUsers([])),
    json_encode(meshBoundNodes(0)),
    json_encode(meshCandidates(0)),
    json_encode(meshIngest(0, [])['recorded']),
    json_encode(meshUnbindNode(0)),
    json_encode(meshBindNode(0, 0, null, 0)),
];
echo implode('|', $out);
PHP;
        $file = tempnam(sys_get_temp_dir(), 'vo_mesh_');
        file_put_contents($file, "<?php\n" . $script);
        try {
            $output = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file) . ' ' . escapeshellarg($root) . ' 2>&1');
        } finally {
            @unlink($file);
        }
        $this->assertStringNotContainsString('undefined function', $output, $output);
        $this->assertStringNotContainsString('Fatal error', $output, $output);
        $this->assertSame('5|true|null|[]|[]|[]|0|0|false', trim($output), $output);
    }

    public function testTheEndpointIsOnTheAllowListOfLongOpenActionRoomScripts(): void
    {
        $this->assertContains('mesh-ingest.php', WAR_ROOM_ACTION_SCRIPTS);
        $endpoint = self::source('mesh-ingest.php');
        // Session-authed like the page that calls it - the bearer-only mobile
        // endpoints are the ones that must NOT be on that list.
        $this->assertStringContainsString('requireLogin()', $endpoint);
        $this->assertStringContainsString('canManageActionRoom(', $endpoint);
        $this->assertStringContainsString('hash_equals(', $endpoint);
        // The page that holds the radio posts for hours: it must not queue behind the session lock.
        $this->assertStringContainsString('session_write_close()', $endpoint);
    }

    public function testOnlyCommandStaffGetTheGatewayScriptAndWindow(): void
    {
        // mesh-gateway.js (the USB gateway itself) and its window stay
        // command-staff only - unlike mesh-protocol.js/mesh-ble.js below,
        // which M3 opened to field participants too.
        $page = self::source('war-room.php');
        $this->assertMatchesRegularExpression(
            '/<\?php if \(\$canManageWarRoom\): \?>\s*<script src="[^"]*mesh-gateway\.js[^"]*"><\/script>\s*<\?php endif; \?>/',
            $page
        );
        $this->assertMatchesRegularExpression(
            '/<\?php if \(\$canManageWarRoom\): \?>\s*<\?php \/\*[^*]*«Πύλη LoRa»[^?]*\?>\s*<div class="modal fade" id="meshGatewayModal"/s',
            $page
        );
        $this->assertStringContainsString('meshGatewayOpen()', self::source('assets/js/war-room-console.js'));
    }

    public function testMeshBleReachesFieldParticipantsTooButOnlyWhenMeshBleAvailable(): void
    {
        $page = self::source('war-room.php');
        // Command staff are covered unconditionally - $meshBleAvailable's own
        // definition ORs in $canManageWarRoom - so this one gate serves both
        // the admin test tool and M3's field-participant pairing button.
        $this->assertMatchesRegularExpression(
            '/\$meshBleAvailable\s*=\s*\$canManageWarRoom\s*\|\|/',
            $page
        );
        $this->assertMatchesRegularExpression(
            '/<\?php if \(\$meshBleAvailable\): \?>\s*<script src="[^"]*mesh-protocol\.js[^"]*"><\/script>\s*<script src="[^"]*mesh-ble\.js[^"]*"><\/script>\s*<\?php endif; \?>/',
            $page
        );
        // Regression guard for the bug caught live in M3 (v3.480.0): the modal
        // landed inside an unrelated, much larger $canManageWarRoom block and
        // silently never rendered for anyone else. Pinning the exact markup
        // immediately before the div, not just "the div exists somewhere".
        $this->assertMatchesRegularExpression(
            '/<\?php if \(\$meshBleAvailable\): \?>\s*<div class="modal fade" id="meshBleModal"/',
            $page
        );
        // The protocol file loads before the modules that need it.
        $this->assertLessThan(strpos($page, 'mesh-ble.js?v='), strpos($page, 'mesh-protocol.js?v='));
        $this->assertLessThan(strpos($page, 'mesh-gateway.js?v='), strpos($page, 'mesh-protocol.js?v='));
    }

    public function testTheRadiosPositionsStayOutOfThePhonesGpsReport(): void
    {
        $this->assertStringContainsString("vp.via <> 'mesh'", self::source('includes/functions-gps-quality.php'));
    }

    public function testThePinPopupSaysWhenThePositionCameOverTheRadio(): void
    {
        $page = self::source('war-room.php');
        $this->assertStringContainsString("pin.via === 'mesh'", $page);
        $this->assertStringContainsString("t('map.tracked_by_mesh')", $page);
        // The marker itself is untouched: the live map face gets no new marks.
        preg_match('/function pinMarkerIcon\([^)]*\)\s*\{(.*?)\n\}/s', $page, $m);
        $this->assertNotEmpty($m, 'pinMarkerIcon() found');
        $this->assertStringNotContainsString('mesh', $m[1]);
    }

    public function testNoCoordinateIsKeptForANodeNobodyCarries(): void
    {
        $schema = self::source('sql/schema.sql');
        $start = (int) strpos($schema, 'CREATE TABLE IF NOT EXISTS `mesh_packets`');
        $table = substr($schema, $start, (int) strpos($schema, ') ENGINE=InnoDB', $start) - $start);
        $this->assertNotEmpty($table);
        $this->assertStringNotContainsString('`lat`', $table);
        $this->assertStringNotContainsString('`lng`', $table);
        // And the uniqueness the dedupe relies on.
        $this->assertStringContainsString('UNIQUE KEY `uk_mesh_packet` (`node_num`, `packet_id`)', $table);
    }

    public function testEverySentenceTheFeatureCanPrintExistsInBothLanguages(): void
    {
        $strings = loadLangStrings('war-room');
        $used = [];
        $files = ['war-room.php', 'mesh-ingest.php', 'assets/js/mesh-gateway.js', 'assets/js/war-room-console.js'];
        foreach ($files as $file) {
            preg_match_all("/['\"]((?:mesh\\.[a-z_]+)|map\\.tracked_by_mesh)['\"]/", self::source($file), $m);
            $used = array_merge($used, $m[1]);
        }
        $used = array_values(array_unique($used));
        $this->assertGreaterThan(20, count($used), 'the scan found the feature\'s sentences');
        foreach ($used as $key) {
            $this->assertArrayHasKey($key, $strings['el'], "$key is missing in Greek");
            $this->assertArrayHasKey($key, $strings['en'], "$key is missing in English");
            $this->assertNotSame('', trim($strings['en'][$key]), "$key is empty in English");
        }
        $keysOf = fn(string $lang) => array_filter(array_keys($strings[$lang]), fn($k) => str_starts_with($k, 'mesh.'));
        $this->assertEqualsCanonicalizing($keysOf('el'), $keysOf('en'));
    }
}
