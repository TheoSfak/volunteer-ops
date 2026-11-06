<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The per-request values of the war-room page's script, read from one small boot script instead of being printed
 * by PHP into the middle of the code (v3.446.0, step R1a of the plan for moving that script out of war-room.php).
 *
 * Why: while PHP prints the mission id into 95 places of a 1.7 MB script, the script is different for every mission
 * and cannot be a file the browser keeps. With the id in one place the script is the same text for every mission.
 * That the script does exactly what it did is checked on the rendered pages (token streams, old against new) and on
 * the source (the rewrite undone gives the old file byte for byte); these pin the shape so it stays that way.
 */
final class WarRoomBootTest extends TestCase
{
    private function page(): string
    {
        return str_replace("\r\n", "\n", WarRoomSource::full());
    }

    /** @return string[] the text of the two big inline scripts, as the source has them (PHP echoes included) */
    private function bigScripts(): array
    {
        $page = $this->page();
        $scripts = [];
        $offset = 0;
        $needle = "ob_start('wrStripJsLineComments'); } ?>\n";
        while (($open = strpos($page, $needle, $offset)) !== false) {
            $from = $open + strlen($needle);
            $to = strpos($page, '<?php if ($__wrLight) { ob_end_flush(); } ?>', $from);
            $this->assertNotFalse($to, 'every buffered script is closed');
            $scripts[] = substr($page, $from, $to - $from);
            $offset = $to;
        }
        $this->assertCount(2, $scripts, 'the page and the live-video script');
        return $scripts;
    }

    public function testTheMissionIdIsPrintedOnceByAOneLineBootScriptRightBeforeTheBigScript(): void
    {
        $page = $this->page();
        $boot = "<script>const WR_MISSION_ID = <?= (int) \$missionId ?>;</script>\n";
        $this->assertSame(1, substr_count($page, $boot));
        $this->assertStringContainsString($boot, $page);
        $this->assertLessThan(strpos($page, "<script>\n<?php \$__wrLight = wrLightScriptEnabled();"), strpos($page, $boot), 'it runs before the script that reads it');
    }

    public function testNoScriptPrintsTheMissionIdItselfAnyMore(): void
    {
        // Only the bare id. Other per-request values (the mission's title and place, the state of the mission, the
        // settings) are still printed in place; they move to the boot script in the next steps of the plan.
        foreach ($this->bigScripts() as $n => $script) {
            $this->assertDoesNotMatchRegularExpression('~<\?=\s*(?:\(int\)\s*)?\$missionId\s*\?>~', $script, 'script ' . ($n + 1) . ' still has PHP printing the mission id');
        }
    }

    public function testTheScriptReadsTheBootConstantInAllItsShapesAndNeverWritesToIt(): void
    {
        [$script] = $this->bigScripts();
        $uses = preg_match_all('~(?<![A-Za-z0-9_])WR_MISSION_ID(?![A-Za-z0-9_])~', $script);
        $this->assertGreaterThanOrEqual(90, $uses, 'the page used to print the id in 95 places');
        // As a number, as a string by itself, and inside a longer string (a storage key, a URL).
        $this->assertGreaterThanOrEqual(25, substr_count($script, 'String(WR_MISSION_ID)'));
        $this->assertStringContainsString("const OFFLINE_QUEUE_KEY = 'wr_offline_queue_' + WR_MISSION_ID;", $script);
        $this->assertStringContainsString("fetch('war-room.php?id=' + WR_MISSION_ID + '&ajax=1&banner_after=' + bannerAfterId)", $script);
        $this->assertStringContainsString('mission_id: WR_MISSION_ID,', $script);
        $this->assertDoesNotMatchRegularExpression('~(?<![A-Za-z0-9_])(?:const|let|var)\s+WR_MISSION_ID\b~', $script, 'declared once, in the boot script');
        $this->assertDoesNotMatchRegularExpression('~(?<![A-Za-z0-9_])WR_MISSION_ID\s*(?:[-+*/]?=(?!=)|\+\+|--)~', $script, 'never assigned to');
    }

    public function testAStringBuiltFromTheIdIsNeverAnOperandOfAnOperatorThatBindsTighterThanPlus(): void
    {
        // 'war-room.php?id=' + WR_MISSION_ID.split(...) would split the number, not the string: when PHP printed
        // the id inside the literal, a method called on that literal was a method of the whole string.
        [$script] = $this->bigScripts();
        $this->assertDoesNotMatchRegularExpression("~'\\s*\\+\\s*WR_MISSION_ID\\s*(?:\\.|\\[|\\?\\.)~", $script);
        $this->assertDoesNotMatchRegularExpression("~(?:[*/%-])\\s*'[^'\\n]*'\\s*\\+\\s*WR_MISSION_ID~", $script);
    }

    // ── v3.447.0, step R1b: the single values and small tables PHP works out for the request ──────────────────

    /** @return array<string,array{0:string,1:string}> key => [mode (bare|string), the PHP expression that is printed] */
    private function bootEntries(): array
    {
        $page = $this->page();
        $from = strpos($page, "const WR_BOOT = {\n");
        $this->assertNotFalse($from, 'the page has a WR_BOOT object');
        $from += strlen("const WR_BOOT = {\n");
        $to = strpos($page, "\n};\n</script>", $from);
        $this->assertNotFalse($to);
        $body = substr($page, $from, $to - $from);
        // A key, a colon and the PHP echo (or the same inside quotes), then a comma; the PHP may run over several lines.
        preg_match_all("~^    (\\w+): ('?)(<\\?=.*?\\?>)\\2,\$~sm", $body, $found, PREG_SET_ORDER);
        $entries = [];
        $covered = [];
        foreach ($found as $e) {
            $covered[] = $e[0];
            $entries[$e[1]] = [$e[2] === "'" ? 'string' : 'bare', trim(substr($e[3], 3, -2))];
        }
        $this->assertSame($body, implode("\n", $covered), 'every line of the boot is a "key: <?= php ?>," entry and nothing else');
        return $entries;
    }

    public function testTheBootObjectIsPrintedOnceBeforeTheScriptThatReadsIt(): void
    {
        $page = $this->page();
        $this->assertSame(1, substr_count($page, 'const WR_BOOT = {'));
        $boot = strpos($page, 'const WR_BOOT = {');
        $this->assertLessThan(strpos($page, "<script>\n<?php \$__wrLight = wrLightScriptEnabled();"), $boot, 'before the big script');
        // The language the boot's jsLocale and viewerLang read is worked out on the line right above the boot.
        $lang = strpos($page, "\$__viewerLang = \$user['language'] ?? DEFAULT_LANGUAGE;");
        $this->assertNotFalse($lang);
        $this->assertLessThan($boot, $lang);
        $this->assertSame(1, substr_count($page, "\$__viewerLang = \$user['language']"));
    }

    public function testEveryBootKeyIsReadAndEveryReadIsAKey(): void
    {
        $keys = array_keys($this->bootEntries());
        $this->assertGreaterThanOrEqual(85, count($keys), 'the single values (R1b) and the state of the mission (R1c)');
        [$script] = $this->bigScripts();
        preg_match_all('~(?<![A-Za-z0-9_])WR_BOOT\.(\w+)~', $script, $m);
        $read = array_unique($m[1]);
        $this->assertSame([], array_values(array_diff($read, $keys)), 'the script reads a key the boot does not have');
        $this->assertSame([], array_values(array_diff($keys, $read)), 'the boot holds a key nothing reads');
        $this->assertDoesNotMatchRegularExpression('~(?<![A-Za-z0-9_])WR_BOOT(?!\.\w)~', $script, 'only ever WR_BOOT.key');
    }

    public function testWhatTheBootPrintsIsNotPrintedInsideTheScriptAnyMore(): void
    {
        [$script] = $this->bigScripts();
        foreach ($this->bootEntries() as $key => [, $php]) {
            $this->assertStringNotContainsString('<?= ' . preg_replace('~\\s+~', ' ', $php) . ' ?>', preg_replace('~\\s+~', ' ', $script), $key . ' is printed by the boot, not in the middle of the script');
        }
    }

    public function testTheBootIsOnlyEverRead(): void
    {
        [$script] = $this->bigScripts();
        $this->assertDoesNotMatchRegularExpression('~(?<![A-Za-z0-9_])WR_BOOT\.\w+\s*(?:[-+*/]?=(?!=)|\+\+|--)~', $script, 'never assigned to');
        $this->assertDoesNotMatchRegularExpression('~(?:delete\s+WR_BOOT|Object\.(?:assign|defineProperty|freeze)\(\s*WR_BOOT)~', $script);
        $this->assertDoesNotMatchRegularExpression("~'\\s*\\+\\s*WR_BOOT\\.\\w+\\s*(?:\\.|\\[|\\?\\.)~", $script, 'no method or index on a string built from a boot value');
    }

    public function testEachBootKeyIsReadByOneVariableExceptTheFourPlainValuesSeveralReadersShare(): void
    {
        // The script used to get its own copy of every list (let pins = [...]; let sectors = [...]). A key read in two
        // places would hand both variables the SAME array, and a push in one would show up in the other.
        [$script] = $this->bigScripts();
        $shared = ['serverTime', 'canManage', 'baseUrl', 'mapPointAssignMax'];
        foreach (array_keys($this->bootEntries()) as $key) {
            $reads = preg_match_all('~(?<![A-Za-z0-9_])WR_BOOT\.' . $key . '(?![A-Za-z0-9_])~', $script);
            if (in_array($key, $shared, true)) {
                $this->assertGreaterThanOrEqual(2, $reads, $key . ' is a plain value read in several places');
            } else {
                $this->assertSame(1, $reads, $key . ' must be read by exactly one variable');
            }
        }
    }

    public function testTheSpotsThatNeedAStringOrAnUnbrokenNumberStillGetOne(): void
    {
        $entries = $this->bootEntries();
        // The four whose value is text inside quotes: a token, a unit, a base address, the mission's title.
        $strings = array_keys(array_filter($entries, fn($e) => $e[0] === 'string'));
        sort($strings);
        $this->assertSame(['areaUnit', 'baseUrl', 'csrf', 'missionTitleHtml'], $strings);
        // The title is the very literal the script used to hold: addslashes inside, html-escaped outside.
        $this->assertSame("h(addslashes(\$mission['title']))", $entries['missionTitleHtml'][1]);
        [$script] = $this->bigScripts();
        $this->assertStringContainsString("'</strong><br>' + WR_BOOT.missionTitleHtml + '<br>'", $script);
        $this->assertStringContainsString("const FLAG_ASSET_BASE = WR_BOOT.baseUrl + '/assets/flags/';", $script);
        $this->assertStringContainsString('const csrfToken = WR_BOOT.csrf;', $script);
        // The setting that turns the Android app's full tracking on is a JS boolean, not a string.
        $this->assertSame("getSetting('war_room_native_full_tracking', '0') === '1' ? 'true' : 'false'", $entries['nativeFullTracking'][1]);
        $this->assertSame('bare', $entries['nativeFullTracking'][0]);
    }

    public function testTheEchoesThatAreStillPrintedInPlaceOnlyGoDown(): void
    {
        // What is left in the first script after R1c (8): the two translation tables and the six inside PHP
        // conditions (vitals twice, the assistant panel, aiIsConfigured, the heart-rate flag, the ack cards), which
        // go when those chunks move out. A new echo here is a new per-request value in the middle of the code; put
        // it in the boot instead.
        [$script] = $this->bigScripts();
        $this->assertLessThanOrEqual(8, preg_match_all('~<\?=~', $script));
    }
}
