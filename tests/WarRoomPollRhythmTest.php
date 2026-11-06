<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The Action Room's polls slow down while nothing changes (v3.450.0), up to a cap chosen in Settings > Action Room.
 *
 * What the ladder does is pinned in tests/js/war-room-poll-rhythm.test.js (the decision itself is plain JavaScript).
 * What is pinned here is everything around it: the rule that turns the two settings into the one number the page
 * receives, the settings (off by default, a closed list, who they apply to), and the page's wiring - that the three
 * timers ask the rhythm before they run, that exactly the right things put it back to fast (a changed payload, a failed
 * poll, the screen coming back, a retry, a new chat message) and that a poll which found nothing new does not, and that
 * the "lost contact" limit follows the rhythm. With the cap off none of it may change what the page does.
 */
final class WarRoomPollRhythmTest extends TestCase
{
    private function page(): string
    {
        return str_replace("\r\n", "\n", WarRoomSource::full());
    }

    // ── the number the page receives ───────────────────────────────────────

    #[DataProvider('capCases')]
    public function testTheCapIsTheSettingInMillisecondsAndZeroWhereItDoesNotApply(string $cap, string $staffToo, bool $canManage, int $expected): void
    {
        $this->assertSame($expected, wrPollIdleMaxMsDecision($cap, $staffToo, $canManage));
    }

    public static function capCases(): array
    {
        return [
            'off, a volunteer' => ['0', '0', false, 0],
            'off, command' => ['0', '1', true, 0],
            'empty is off' => ['', '1', false, 0],
            'not a listed value is off' => ['45', '1', false, 0],
            'negative is off' => ['-10', '0', false, 0],
            'text is off' => ['fast', '0', false, 0],
            '10 s, a volunteer' => ['10', '0', false, 10000],
            '15 s, a volunteer' => ['15', '0', false, 15000],
            '30 s, a volunteer' => ['30', '0', false, 30000],
            '30 s, command, staff not included' => ['30', '0', true, 0],
            '30 s, command, staff not included (empty)' => ['30', '', true, 0],
            '30 s, command, staff included' => ['30', '1', true, 30000],
            '15 s, command, staff included' => ['15', '1', true, 15000],
            '30 s, a volunteer, staff included is the same' => ['30', '1', false, 30000],
        ];
    }

    public function testTheSettingsAreReadWithTheirDefaultsOff(): void
    {
        $source = str_replace("\r\n", "\n", WarRoomSource::read('includes/functions-warroom.php'));
        $this->assertStringContainsString("getSetting('war_room_poll_idle_max_seconds', '0')", $source);
        $this->assertStringContainsString("getSetting('war_room_poll_idle_staff', '0')", $source);
        // with nothing stored (the test database has no such rows) the answer is off for everybody
        $this->assertSame(0, wrPollIdleMaxMs(false));
        $this->assertSame(0, wrPollIdleMaxMs(true));
    }

    // ── the settings ───────────────────────────────────────────────────────

    public function testTheSettingsAreOffByDefaultOwnedByTheActionRoomTabAndAClosedList(): void
    {
        $settings = str_replace("\r\n", "\n", WarRoomSource::read('settings.php'));
        $this->assertStringContainsString("'war_room_poll_idle_max_seconds' => '0',", $settings);
        $this->assertStringContainsString("'war_room_poll_idle_staff' => '0',", $settings);
        $this->assertMatchesRegularExpression("~\\\$actionRoomFields = \\[[^;]*'war_room_poll_idle_max_seconds', 'war_room_poll_idle_staff'~s", $settings, 'saved with the Action Room tab');
        $this->assertStringContainsString("if (\$field === 'war_room_poll_idle_max_seconds' && !in_array(\$value, ['0', '10', '15', '30'], true)) {\n                        \$value = '0';", $settings);

        // the staff box is a checkbox (an unticked one is saved as 0); the cap is a select, so it must not be in that list
        $this->assertSame(1, preg_match("~in_array\\(\\\$field, \\['achievements_enabled'[^\\]]*\\]\\)~", $settings, $m));
        $this->assertStringContainsString("'war_room_poll_idle_staff'", $m[0]);
        $this->assertStringNotContainsString('war_room_poll_idle_max_seconds', $m[0]);

        $this->assertMatchesRegularExpression('~<select[^>]*name="war_room_poll_idle_max_seconds"~', $settings);
        foreach (['0', '10', '15', '30'] as $v) {
            $this->assertStringContainsString('<option value="' . $v . '" <?= $pollIdle === \'' . $v . '\'', $settings);
        }
        $this->assertStringContainsString('name="war_room_poll_idle_staff"', $settings);
        $this->assertStringContainsString("(\$settings['war_room_poll_idle_staff'] ?? '0') === '1' ? 'checked' : ''", $settings, 'off unless ticked: command stays on 5 s by default');
    }

    // ── the page ───────────────────────────────────────────────────────────

    public function testThePageGetsTheCapFromTheBootObjectAndReadsItOnce(): void
    {
        $page = $this->page();
        $this->assertSame(1, substr_count($page, '    pollIdleMaxMs: <?= wrPollIdleMaxMs($canManageWarRoom) ?>,'));
        $this->assertSame(1, substr_count($page, 'WR_BOOT.pollIdleMaxMs'), 'read in one place');
        $this->assertSame(1, substr_count($page, 'const wrRhythm = wrCreatePollRhythm(WR_BOOT.pollIdleMaxMs);'));
        $this->assertSame(1, substr_count($page, 'wrWatchPollActivity(wrRhythm, document);'));
    }

    public function testTheRhythmFileLoadsBeforeTheBigScriptAndTheRhythmExistsBeforeAnythingAsksIt(): void
    {
        $page = $this->page();
        $tag = strpos($page, '/assets/js/war-room-poll-rhythm.js?v=<?= APP_VERSION ?>"></script>');
        $this->assertNotFalse($tag);
        $this->assertSame(1, substr_count($page, '<script src="<?= rtrim(BASE_URL, \'/\') ?>/assets/js/war-room-poll-rhythm.js'), 'one tag');
        $bigOpen = strpos($page, "<script>\n<?php \$__wrLight = wrLightScriptEnabled();");
        $this->assertNotFalse($bigOpen);
        $this->assertLessThan($bigOpen, $tag, 'a classic script that the big one calls: it must come first');

        $made = (int) strpos($page, 'const wrRhythm = wrCreatePollRhythm(');
        $first = (int) strpos($page, 'wrRhythm.');
        $this->assertGreaterThan($bigOpen, $made, 'made inside the big script, from the boot object');
        $this->assertLessThan($first, $made, 'made before the first line that asks it');
        $this->assertLessThan((int) strpos($page, 'setInterval(', $bigOpen), $made, 'before the first timer of the big script, so no timer can fire into a rhythm that does not exist yet');
    }

    public function testTheThreeTimersAskTheRhythmBeforeTheyRunAndKeepTheirOwnPeriods(): void
    {
        $page = $this->page();
        $this->assertSame(1, substr_count($page, "setInterval(() => { if (!document.hidden && wrRhythm.due('main', 5000)) pollWarRoomData(); }, 5000);"));
        $this->assertSame(1, substr_count($page, "setInterval(() => { if (!document.hidden && wrRhythm.due('chat', 5000)) pollRoom(); }, 5000);"));
        $this->assertSame(1, substr_count($page, "setInterval(() => { if (!document.hidden && wrRhythm.due('activity', 15000)) loadActivity(); }, 15000);"));
        // and no timer of these three is left that does not ask
        $this->assertSame(0, preg_match('~setInterval\(\(\) => \{ if \(!document\.hidden\) (?:pollWarRoomData|pollRoom|loadActivity)\(\); \}~', $page));
        $this->assertSame(0, preg_match('~setInterval\((?:pollWarRoomData|pollRoom|loadActivity)\b~', $page));
    }

    public function testOnlyAPollThatFoundSomethingNewOrFailedOrWasAskedForPutsTheRhythmBackToFast(): void
    {
        $page = $this->page();
        $this->assertSame(5, substr_count($page, 'wrRhythm.bump();'), 'the five places, no more, no fewer');

        // a payload that differs from the last one - after the "unchanged" return, before anything is drawn
        $this->assertStringContainsString("        wrRhythm.bump();\n        // Refreshed BEFORE any render below", $page);
        // a poll that failed
        $this->assertStringContainsString("        wrRhythm.bump();   // a poll that failed: look again at the base rhythm, and judge staleness by it\n        renderPollStaleness();\n    }).finally(() => {\n        clearTimeout(pollKiller);", $page);
        // the screen coming back
        $this->assertStringContainsString("    wrRhythm.bump();\n    // A poll still out from before the screen went dark is not waited for.", $page);
        // the banner's retry and every other restartPoll()
        $this->assertStringContainsString("    pollInFlight = false;\n    wrRhythm.bump();\n    return pollWarRoomData();", $page);
        // a chat message that arrived in the open room
        $this->assertStringContainsString("                data.messages.forEach(renderMessage);\n                wrRhythm.bump();\n", $page);

        // and the answer "nothing changed" is NOT activity: that is what lets the rhythm slow down
        $start = (int) strpos($page, '        if (data.unchanged) {');
        $end = (int) strpos($page, '        // Something changed: back to the base rhythm', $start);
        $this->assertGreaterThan($start, $end);
        $this->assertStringNotContainsString('wrRhythm', substr($page, $start, $end - $start));
    }

    public function testTheLostContactLimitFollowsTheRhythmInsteadOfBeingAFixed20Seconds(): void
    {
        $page = $this->page();
        $this->assertStringNotContainsString('POLL_STALE_MS', $page);
        $this->assertSame(1, substr_count($page, '    if (ageMs < wrRhythm.staleMs()) {'));
        // the other limits are not the rhythm's business
        $this->assertSame(1, substr_count($page, 'const POLL_ABORT_MS = 30000;'));
        $this->assertSame(1, substr_count($page, 'const POLL_RESUME_STALE_MS = 10000;'));
        $this->assertSame(1, substr_count($page, 'setInterval(renderPollStaleness, 5000);'), 'the banner is still judged every 5 s');
    }

    public function testNothingElseInThePageNamesTheFilesFunctionsOrDeclaresThemAgain(): void
    {
        $page = $this->page();
        foreach (['WR_POLL_BASE_MS', 'WR_POLL_LADDER', 'function wrPollIntervalMs', 'function wrPollStaleMs', 'function wrCreatePollRhythm', 'function wrWatchPollActivity'] as $name) {
            $this->assertStringNotContainsString('const ' . $name, $page, $name);
            $this->assertStringNotContainsString($name . ' =', $page, $name);
            if (str_starts_with($name, 'function')) {
                $this->assertStringNotContainsString($name, $page, 'declared in the file only: two classic scripts declaring one function name is silent, two const are an error');
            }
        }
    }

    public function testTheRhythmFileIsAPlainClassicScript(): void
    {
        $js = str_replace("\r\n", "\n", (string) file_get_contents(__DIR__ . '/../assets/js/war-room-poll-rhythm.js'));
        $this->assertDoesNotMatchRegularExpression('~^\s*(?:import|export)\s~m', $js, 'it is loaded with a <script> tag, not as a module');
        $this->assertStringNotContainsString("'use strict'", $js);
        $this->assertStringContainsString('module.exports', $js, 'and it can be required by the Node tests');
        // the ladder in the file is the one the setting's help text and the release notes say
        $this->assertStringContainsString('[[180000, 30000], [90000, 15000], [30000, 10000]]', $js);
        $this->assertStringContainsString('const WR_POLL_BASE_MS = 5000;', $js);
        $this->assertStringContainsString('return Math.max(20000, 3 * intervalMs + 5000);', $js);
    }
}
