<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * The barometer floor's plumbing (v3.426.0): the things that only fail in
 * production, where nothing but the real request chain is loaded.
 *
 * tests/bootstrap.php loads every include, so a function that the real
 * bootstrap.php never loads passes the whole suite and fatals live (v3.358.1).
 * The first test therefore runs the new file in a fresh process with only what
 * the real bootstrap.php loads. The rest pin that the new endpoint is on the
 * allow-list every long-open Action Room endpoint needs, that every sentence
 * the feature can print exists in both languages, and that the payload gate and
 * the setting are where the page expects them.
 */
final class BaroFloorWiringTest extends TestCase
{
    private static function source(string $file): string
    {
        return (string) file_get_contents(__DIR__ . '/../' . $file);
    }

    public function testTheRealBootstrapLoadsTheFloorFunctions(): void
    {
        $this->assertStringContainsString("includes/functions-baro.php", self::source('bootstrap.php'));
    }

    public function testEveryFloorFunctionRunsWithOnlyTheRealBootstrapChainLoaded(): void
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
// A mission that does not exist: every reader answers empty, and getting
// that far is the point - an undefined function is a fatal in this process.
$out = [
    json_encode(loadMissionFloors(0)),
    json_encode(loadLatestPressureByUser(0)),
    json_encode(loadFloorBaselinesByUser(0)),
    json_encode(recentPressureMedian(0, 0)),
    json_encode(setFloorBaselines(0, [], null, 0)['ok']),
    json_encode(clearFloorBaselines(0, [])),
    json_encode(loadHasBarometerByUser([])),
    json_encode(floorBaselineCandidates(0)['groups']),
    json_encode(baroFloorHeightM()),
    json_encode(recordVolunteerPressure(['id' => 0], 0, 1013.2)),
];
echo implode('|', $out);
PHP;
        $file = tempnam(sys_get_temp_dir(), 'vo_baro_');
        file_put_contents($file, "<?php\n" . $script);
        try {
            $output = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file) . ' ' . escapeshellarg($root) . ' 2>&1');
        } finally {
            @unlink($file);
        }
        $this->assertStringNotContainsString('undefined function', $output, $output);
        $this->assertStringNotContainsString('Fatal error', $output, $output);
        $this->assertSame('[]|[]|[]|null|true|0|[]|[]|3|false', trim($output), $output);
    }

    public function testTheEndpointIsOnTheAllowListOfLongOpenActionRoomScripts(): void
    {
        $this->assertContains('war-room-floor-baseline.php', WAR_ROOM_ACTION_SCRIPTS);
        // Session-authed like the page that calls it - the bearer-only mobile
        // endpoints are the ones that must NOT be on that list.
        $this->assertStringContainsString('requireLogin()', self::source('war-room-floor-baseline.php'));
        $this->assertStringContainsString('canManageActionRoom(', self::source('war-room-floor-baseline.php'));
        $this->assertStringContainsString("hash_equals(", self::source('war-room-floor-baseline.php'));
    }

    public function testEverySentenceTheFeatureCanPrintExistsInBothLanguages(): void
    {
        $strings = loadLangStrings('war-room');
        $used = [];
        $files = ['war-room.php', 'war-room-floor-baseline.php', 'assets/js/floor-display.js', 'assets/js/war-room-console.js'];
        foreach ($files as $file) {
            preg_match_all("/['\"](floor\\.[a-z_]+)['\"]/", self::source($file), $m);
            $used = array_merge($used, $m[1]);
        }
        // Built from a code at run time: the endpoint's errors and the skipped-person reasons.
        foreach (['ref_in_list', 'ref_not_participant', 'ref_no_reading'] as $code) {
            $used[] = 'floor.err_' . $code;
        }
        foreach (['no_reading', 'no_barometer', 'not_participant'] as $reason) {
            $used[] = 'floor.reason_' . $reason;
        }
        // A key ending in "_" is the front half of one of those built keys, not a sentence.
        $used = array_values(array_unique(array_filter($used, fn($key) => !str_ends_with($key, '_'))));
        $this->assertGreaterThan(30, count($used), 'the scan found the feature\'s sentences');
        foreach ($used as $key) {
            $this->assertArrayHasKey($key, $strings['el'], "$key is missing in Greek");
            $this->assertArrayHasKey($key, $strings['en'], "$key is missing in English");
            $this->assertNotSame('', trim($strings['en'][$key]), "$key is empty in English");
        }
        // And nothing is translated into one language only.
        $keysOf = fn(string $lang) => array_filter(array_keys($strings[$lang]), fn($k) => str_starts_with($k, 'floor.'));
        $this->assertEqualsCanonicalizing($keysOf('el'), $keysOf('en'));
    }

    public function testThePayloadGateIsTheSameOneTheHeartRateUses(): void
    {
        $page = self::source('war-room.php');
        $this->assertStringContainsString('loadMissionFloors($missionId)', $page);
        $this->assertMatchesRegularExpression(
            '/foreach \(loadMissionFloors\(\$missionId\) as \$floorUserId => \$floor\) \{\s+if \(!\$canSeeVitalsOf\(\$floorUserId\)/',
            $page,
            "a person's floor is only put in the payload for a viewer who may see it"
        );
        $this->assertStringContainsString("'floor' => \$floorByVolunteerId[(int) \$pin['user_id']] ?? null", $page, 'the pins carry the already-gated floor');
    }

    public function testTheFloorHeightIsASavedActionRoomSetting(): void
    {
        $settings = self::source('settings.php');
        $this->assertMatchesRegularExpression("/\\\$actionRoomFields = \\[[^\\]]*'war_room_floor_height_m'/s", $settings);
        $this->assertStringContainsString('name="war_room_floor_height_m"', $settings);
        $this->assertStringContainsString("if (\$field === 'war_room_floor_height_m')", $settings, 'clamped on save');
    }

    public function testTheLiveMapFaceGetsNoNewNumbers(): void
    {
        // The settled rule: the floor lives in the roster row, the console's member row and the pin's popup.
        $page = self::source('war-room.php');
        $this->assertStringContainsString('floorPopupLineHtml(pin.floor', $page);
        // pinMarkerIcon draws the marker itself; the floor must not reach it.
        preg_match('/function pinMarkerIcon\([^)]*\)\s*\{(.*?)\n\}/s', $page, $m);
        $this->assertNotEmpty($m, 'pinMarkerIcon() found');
        $this->assertStringNotContainsString('floor', $m[1]);
    }
}
