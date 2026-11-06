<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The war-room page sends a lighter script (v3.445.0): the full-line // comments of its two big inline scripts
 * are left out and, for a viewer who reads the default language, the translation table is sent once instead of
 * twice. The page is ~600 KB of gzip on every load and every reload, and about 40% of that was these two things.
 *
 * What is pinned here: the stripping function on small inputs (every doubt must end in KEEPING the line), the same
 * function on the page's own script (what it may and may not drop), the wiring in war-room.php (one decision, balanced
 * buffers, the full table still sent when it is needed) and the switch in the settings. That the script means the
 * same with and without the comments is checked on the rendered pages by comparing their token streams (acorn),
 * which no unit test here can do.
 */
final class WarRoomLightScriptTest extends TestCase
{
    protected function setUp(): void
    {
        db()->beginTransaction();
        clearSettingsCache();
        unset($_GET['rawjs']);
    }

    protected function tearDown(): void
    {
        db()->rollBack();
        clearSettingsCache();
        unset($_GET['rawjs']);
    }

    // ── the function ────────────────────────────────────────────────────────

    public function testALineThatIsOnlyACommentIsLeftOutWhateverItsIndent(): void
    {
        $js = "const a = 1;\n// a note\n    // indented\n\t//tab, no space\n//\nconst b = 2;\n";
        $this->assertSame("const a = 1;\nconst b = 2;\n", wrStripJsLineComments($js));
    }

    public function testCodeIsNeverTouchedEvenWhenItHasADoubleSlashInIt(): void
    {
        $js = implode("\n", [
            "const url = 'http://example.com/a';",
            "const b = 1; // trailing comment stays",
            "fetch('//cdn.example.com/x.js');",
            "const re = /a\\/\\//;",
            "x = a / b; // divide",
            "",
        ]);
        $this->assertSame($js, wrStripJsLineComments($js));
    }

    public function testAnInputWithoutAnyDoubleSlashComesBackAsTheSameString(): void
    {
        $this->assertSame('', wrStripJsLineComments(''));
        $this->assertSame("a();\nb();\n", wrStripJsLineComments("a();\nb();\n"));
    }

    public function testLinesEndingInCrLfKeepTheirEndings(): void
    {
        $this->assertSame("a();\r\nb();\r\n", wrStripJsLineComments("a();\r\n// c\r\nb();\r\n"));
        $this->assertSame("a();\r\nb();\r\n", wrStripJsLineComments("a();\r\n    //c\r\nb();\r\n"));
    }

    public function testALineInsideATemplateLiteralIsTextAndStays(): void
    {
        $js = "const html = `\n<p>one</p>\n// not a comment, it is the text\n<p>two</p>\n`;\n// a real comment\nconst x = 1;\n";
        $this->assertSame(
            "const html = `\n<p>one</p>\n// not a comment, it is the text\n<p>two</p>\n`;\nconst x = 1;\n",
            wrStripJsLineComments($js)
        );
    }

    public function testATemplateThatOpensAndClosesOnTheSameLineDoesNotSwallowTheNextComment(): void
    {
        $js = "const a = `x` + `y`;\n// gone\nconst b = `\${a}`;\n// gone too\n";
        $this->assertSame("const a = `x` + `y`;\nconst b = `\${a}`;\n", wrStripJsLineComments($js));
    }

    public function testAnEscapedBacktickDoesNotOpenATemplate(): void
    {
        $this->assertSame("const a = 'it\\`s';\nconst b = 1;\n", wrStripJsLineComments("const a = 'it\\`s';\n// gone\nconst b = 1;\n"));
    }

    public function testAStrayBacktickInAStringOnlyEverKeepsMore(): void
    {
        // The line scanner cannot tell a backtick in a string from a template's; the wrong guess is "inside a
        // template", which keeps comments. Keeping a comment is harmless; dropping text would be a broken page.
        $js = "const tick = '`';\n// kept, we cannot be sure\nconst b = 1;\n";
        $this->assertSame($js, wrStripJsLineComments($js));
    }

    public function testALineAfterABackslashContinuesTheStringAndStays(): void
    {
        $js = "const s = 'one \\\n// still the string';\nconst b = 1;\n// gone\n";
        $this->assertSame("const s = 'one \\\n// still the string';\nconst b = 1;\n", wrStripJsLineComments($js));
    }

    public function testALineThatClosesABlockCommentIsKept(): void
    {
        $js = "/* a note\n// that ends here */\nconst a = 1;\n";
        $this->assertSame($js, wrStripJsLineComments($js), 'dropping it would leave the comment open and swallow the code after it');
    }

    public function testTextThatIsNotValidUtf8PassesThroughByteForByte(): void
    {
        // The real page has a comment line with a broken multi-byte character; a /u regex would return null on it.
        $js = "a();\n// \xE2\x95 broken\nb('\xE2\x95');\n";
        $this->assertSame("a();\nb('\xE2\x95');\n", wrStripJsLineComments($js));
    }

    public function testRunningItTwiceChangesNothingTheSecondTime(): void
    {
        $js = "a();\n// c\nconst t = `\n// text\n`;\n/* x\n// y */\nb(); // z\n    //  w\n";
        $once = wrStripJsLineComments($js);
        $this->assertSame($once, wrStripJsLineComments($once));
    }

    // ── the function on the page's own script ───────────────────────────────

    /** @return string the text of the page's big inline script, as the source has it (PHP echoes included) */
    private function pageScript(): string
    {
        $page = str_replace("\r\n", "\n", WarRoomSource::full());
        $from = strpos($page, "<?php \$__wrLight = wrLightScriptEnabled();");
        $this->assertNotFalse($from, 'the page decides once whether the script is light');
        $from = strpos($page, "\n", $from) + 1;
        $to = strpos($page, "<?php if (\$__wrLight) { ob_end_flush(); } ?>", $from);
        $this->assertNotFalse($to);
        return substr($page, $from, $to - $from);
    }

    public function testOnThePagesOwnScriptItOnlyEverDropsWholeCommentLinesAndLeavesTheRest(): void
    {
        $in = $this->pageScript();
        $out = wrStripJsLineComments($in);
        $inLines = explode("\n", $in);
        $outLines = explode("\n", $out);

        // Every output line is an input line, in the same order; every line that is missing is a comment-only line.
        $i = 0;
        $dropped = 0;
        foreach ($outLines as $line) {
            while ($i < count($inLines) && $inLines[$i] !== $line) {
                $this->assertMatchesRegularExpression('~^[ \t]*//~', $inLines[$i], 'a dropped line must be a // comment, line ' . ($i + 1));
                $this->assertStringNotContainsString('*/', $inLines[$i], 'a line that may close a block comment must stay, line ' . ($i + 1));
                $dropped++;
                $i++;
            }
            $this->assertLessThan(count($inLines), $i, 'an output line that is not in the input');
            $i++;
        }
        while ($i < count($inLines)) {
            $this->assertMatchesRegularExpression('~^[ \t]*//~', $inLines[$i]);
            $dropped++;
            $i++;
        }

        $this->assertGreaterThan(3000, $dropped, 'the page has thousands of comment lines; the function must find them');
        $this->assertLessThan(strlen($in) * 0.85, strlen($out), 'at least 15% of the script is comments');
        $this->assertSame($out, wrStripJsLineComments($out), 'idempotent on the real script too');
    }

    // ── the wiring in war-room.php ──────────────────────────────────────────

    public function testThePageDecidesOnceAndEveryBufferItOpensIsClosed(): void
    {
        $page = str_replace("\r\n", "\n", WarRoomSource::full());
        $this->assertSame(1, substr_count($page, '$__wrLight = wrLightScriptEnabled();'), 'one decision for the whole page');
        $this->assertSame(2, substr_count($page, "ob_start('wrStripJsLineComments')"), 'the two big inline scripts');
        $this->assertSame(2, substr_count($page, 'if ($__wrLight) { ob_end_flush(); }'));
        // An ob_start that is only sometimes closed would run the rest of the page through a script filter.
        $this->assertSame(2, substr_count($page, 'if ($__wrLight) { ob_start('));

        preg_match_all('~<script>\n<\?php[^\n]*ob_start\(\'wrStripJsLineComments\'\)[^\n]*\n~', $page, $opens);
        $this->assertCount(2, $opens[0], 'each buffer opens on the line right after its <script> tag');
        preg_match_all('~<\?php if \(\$__wrLight\) \{ ob_end_flush\(\); \} \?>\n</script>~', $page, $closes);
        $this->assertCount(2, $closes[0], 'and closes on the line right before its </script> tag');
    }

    public function testNothingInsideTheBufferedScriptOpensOrClosesABufferOfItsOwn(): void
    {
        $this->assertDoesNotMatchRegularExpression('~\bob_(?:start|end_clean|end_flush|get_clean|get_flush|clean|flush)\s*\(~', $this->pageScript());
    }

    public function testTheTranslationTableIsSentOnceOnlyToAViewerWhoReadsTheDefaultLanguage(): void
    {
        $page = str_replace("\r\n", "\n", WarRoomSource::full());
        $this->assertStringContainsString(
            "<?php if (\$__wrLight && \$__viewerLang === DEFAULT_LANGUAGE):",
            $page
        );
        $this->assertStringContainsString("\nconst WR_STRINGS_FALLBACK = WR_STRINGS;\n<?php else: ?>\nconst WR_STRINGS_FALLBACK = <?= json_encode(\$__wrStrings[DEFAULT_LANGUAGE] ?? []", $page, 'the full table is still sent to everyone else, and when the switch is off');
        $this->assertSame(1, substr_count($page, 'WR_STRINGS_FALLBACK = WR_STRINGS;'));
    }

    public function testNothingWritesToEitherTranslationTable(): void
    {
        // The alias is only the same thing as two copies while nobody writes to one of them.
        $page = WarRoomSource::full();
        // The one exception is the page's check on the translation file (v3.449.0): when that file did not run, and only
        // then, it gives the script an empty table - both names one and the same object - so it shows keys instead of
        // stopping. That is a definition, before anything has read a table, not a write to a table in use; it must stay
        // the single such place.
        $page = str_replace("\r\n", "\n", $page);
        $healTables = "window.WR_STRINGS = {};\n    window.WR_STRINGS_FALLBACK = window.WR_STRINGS;";
        $this->assertSame(1, substr_count($page, $healTables), 'the exception exists once, as written');
        $page = str_replace($healTables, '', $page);
        $this->assertDoesNotMatchRegularExpression('~\bWR_STRINGS(?:_FALLBACK)?\s*(?:\[[^\]]*\]\s*)?=[^=]~', preg_replace('~const WR_STRINGS(?:_FALLBACK)? = ~', '', $page));
        $this->assertDoesNotMatchRegularExpression('~\bObject\.(?:assign|defineProperty)\(\s*WR_STRINGS~', $page);
    }

    // ── the switch ──────────────────────────────────────────────────────────

    public function testTheRuleIsOffForTheSettingZeroAndForARawRequestAndOnOtherwise(): void
    {
        $this->assertTrue(wrLightScriptDecision('1', false));
        $this->assertTrue(wrLightScriptDecision('', false), 'a cleared value is not an instruction to turn it off');
        $this->assertFalse(wrLightScriptDecision('0', false));
        $this->assertFalse(wrLightScriptDecision('1', true), '?rawjs=1 wins over the setting');
        $this->assertFalse(wrLightScriptDecision('0', true));
    }

    public function testTheSwitchReadsTheQueryStringAndTheSettingWithItsDefaultOn(): void
    {
        // getSetting() fills a static cache once per process, so what a row says cannot be changed from here;
        // the rule above covers the values, this pins where they come from.
        $_GET['rawjs'] = '1';
        $this->assertFalse(wrLightScriptEnabled());
        unset($_GET['rawjs']);
        $this->assertSame(wrLightScriptDecision((string) getSetting('war_room_light_script', '1'), false), wrLightScriptEnabled());
        $source = str_replace("\r\n", "\n", (string) WarRoomSource::read('includes/functions-warroom.php'));
        $this->assertStringContainsString("getSetting('war_room_light_script', '1')", $source, 'on unless the setting is 0');
    }

    public function testTheSettingIsDeclaredSavedAndOfferedInTheActionRoomTab(): void
    {
        $settings = str_replace("\r\n", "\n", (string) WarRoomSource::read('settings.php'));
        $this->assertStringContainsString("'war_room_light_script' => '1',", $settings, 'default on');
        $this->assertMatchesRegularExpression("~\\\$actionRoomFields = \\[[^;]*'war_room_light_script'~s", $settings, 'saved with the Action Room tab');
        $this->assertStringContainsString("'war_room_native_full_tracking', 'war_room_light_script'])", $settings, 'an unticked box is saved as 0');
        $this->assertStringContainsString('name="war_room_light_script"', $settings);
        $this->assertStringContainsString("(\$settings['war_room_light_script'] ?? '1') === '1' ? 'checked' : ''", $settings);
    }
}
