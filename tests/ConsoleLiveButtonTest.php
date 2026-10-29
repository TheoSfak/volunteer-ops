<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The red LIVE button in the console's counter bar (v3.411.0). Whether it flashes is
 * pure and tested in tests/js/war-room-console.test.js (wrcLiveSignal); this pins what
 * only the page can get wrong: that the button reads the Live card's real markup, that
 * it exists only where the Live card does, that the flashing has a still fallback, and
 * that its texts exist in both languages.
 */
final class ConsoleLiveButtonTest extends TestCase
{
    private function source(string $relative): string
    {
        return (string) file_get_contents(__DIR__ . '/../' . $relative);
    }

    public function testTheButtonReadsTheMarkupTheLiveCardActuallyHas(): void
    {
        $js = $this->source('assets/js/war-room-console.js');
        $page = $this->source('war-room.php');

        $this->assertStringContainsString("#liveStreamsBody .live-stream-item[data-stream-id]", $js);
        $this->assertStringContainsString("id: el.dataset.streamId, status: el.dataset.status", $js);

        // The server draws the card with these hooks ...
        $this->assertStringContainsString('id="liveStreamsBody"', $page);
        $this->assertStringContainsString('live-stream-item" data-stream-id="<?= (int)$ls[\'id\'] ?>"', $page);
        $this->assertStringContainsString('data-status="<?= h($ls[\'status\']) ?>"', $page);
        // ... and the poll keeps the same two attributes current when a stream changes state.
        $this->assertStringContainsString('item.dataset.streamId = r.id;', $page);
        $this->assertStringContainsString('item.dataset.status = r.status;', $page);
    }

    public function testThereIsNoButtonWhereTheLiveCardIsNotOnThePage(): void
    {
        $js = $this->source('assets/js/war-room-console.js');
        $this->assertMatchesRegularExpression(
            "~function liveKpi\\(\\) \\{\\s*//[^\\n]*\\n\\s*if \\(!cardNode\\('liveStreamsCard'\\)\\) return \\[\\];~",
            $js,
            'LiveKit is unconfigured on some installs: the card is absent there, so the button must be too'
        );
        $this->assertStringContainsString("if (b.dataset.kpi === 'live') watchLive();", $js);
        $this->assertStringContainsString("card: 'liveStreamsCard'", $js);
    }

    public function testTheFlashingHasAStillFallbackForPeopleWhoAskForLessMotion(): void
    {
        $css = $this->source('assets/css/war-room-console.css');
        $this->assertStringContainsString('@keyframes wrcLiveFlash', $css);
        $this->assertStringContainsString('.wrc-kpi-live.flash { animation: wrcLiveFlash', $css);
        $start = strpos($css, 'prefers-reduced-motion: reduce) {', (int) strpos($css, '@keyframes wrcLiveFlash'));
        $this->assertNotFalse($start);
        $block = substr($css, $start, 300);
        $this->assertStringContainsString('.wrc-kpi-live.flash', $block);
        $this->assertStringContainsString('animation: none', $block);
        $this->assertStringContainsString('outline:', $block, 'without the animation the alarm must still be visible');
    }

    public function testEveryTextOfTheButtonExistsInBothLanguages(): void
    {
        $js = $this->source('assets/js/war-room-console.js');
        preg_match_all("~'(console\\.kpi_live_[a-z]+)'~", $js, $m);
        $keys = array_unique($m[1]);
        $this->assertEqualsCanonicalizing(['console.kpi_live_none', 'console.kpi_live_on', 'console.kpi_live_wait'], $keys);

        $strings = require __DIR__ . '/../includes/lang/war-room.php';
        foreach (['el', 'en'] as $lang) {
            foreach ($keys as $key) {
                $this->assertArrayHasKey($key, $strings[$lang], "'$lang' has no $key");
                $this->assertNotSame('', $strings[$lang][$key]);
            }
            foreach (['console.kpi_live_on', 'console.kpi_live_wait'] as $key) {
                $this->assertStringContainsString('{n}', $strings[$lang][$key]);
            }
        }
    }
}
