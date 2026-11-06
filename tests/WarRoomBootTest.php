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
        $this->assertStringContainsString($boot . "<script>\n<?php \$__wrLight = wrLightScriptEnabled();", $page, 'it runs before the script that reads it');
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
}
