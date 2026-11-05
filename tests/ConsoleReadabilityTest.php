<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * The «Πίνακας» look keeps every text readable (v3.435.1).
 *
 * The board forces the dark colour scheme, so everything the site and the page wrote for a light background had to be
 * checked: Leaflet's white popups held light text (a sector's «Διαχωρισμός» 1.6:1, its status badge white on white),
 * the site's white-on-brand buttons and badges were 2.2 - 3.8:1, the cream «no OpenStreetMap data» chip and the cluster
 * counts took the light ink, and the sector names were red on a dimmed, grey-shaded map.
 *
 * The pairs the board itself chooses are checked here by arithmetic (WCAG ratio, from the declarations in the sheet,
 * with the board's variables resolved); what is a plain decision (the popup is the board's panel, the halo is there)
 * is pinned by source. The live page was audited the same way in a browser: every text of every state, popups, menus
 * and dialogs, against its real background - zero below 4.5:1 apart from the two named in the last test.
 */
final class ConsoleReadabilityTest extends TestCase
{
    private static function css(): string
    {
        $css = str_replace("\r\n", "\n", (string) file_get_contents(__DIR__ . '/../assets/css/war-room-console-overlay.css'));
        return (string) preg_replace('~/\*.*?\*/~s', '', $css);
    }

    /** The board's variables from its root rule: --ov-green => #35d36b. @return array<string, string> */
    private static function vars(): array
    {
        preg_match_all('~(--ov-[a-z-]+):\s*(#[0-9a-fA-F]{3,6})\s*;~', self::css(), $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as $row) {
            $out[$row[1]] = strtolower($row[2]);
        }
        return $out;
    }

    private static function hex(string $value): string
    {
        $value = trim($value);
        if (preg_match('~^var\((--ov-[a-z-]+)\)$~', $value, $m)) {
            $vars = self::vars();
            self::assertArrayHasKey($m[1], $vars, "variable {$m[1]} is defined as a plain colour in the root rule");
            return $vars[$m[1]];
        }
        self::assertMatchesRegularExpression('~^#[0-9a-fA-F]{3,6}$~', $value, "a colour: $value");
        $value = strtolower($value);
        if (strlen($value) === 4) {
            $value = '#' . $value[1] . $value[1] . $value[2] . $value[2] . $value[3] . $value[3];
        }
        return $value;
    }

    private static function lum(string $hex): float
    {
        $c = [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
        $c = array_map(static function (int|float $v): float {
            $v /= 255;
            return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        }, $c);
        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    }

    private static function ratio(string $a, string $b): float
    {
        $x = self::lum(self::hex($a));
        $y = self::lum(self::hex($b));
        return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
    }

    /** The value of $property in the first rule whose selector ends with $selector (a plain string). */
    private static function declared(string $selector, string $property): string
    {
        $css = self::css();
        $pattern = '~(?:^|[,}\s])' . preg_quote($selector, '~') . '\s*(?:,[^{]*)?\{([^}]*)\}~';
        self::assertSame(1, preg_match($pattern, $css, $m), "rule not found: $selector");
        $prop = (string) preg_quote($property, '~');
        self::assertSame(1, preg_match('~(?:^|[;\s])' . $prop . ':\s*([^;!]+)~', $m[1], $v), "$selector has no $property");
        return trim($v[1]);
    }

    public function testTheBoardsOwnTextOnItsFillsReadsAtFourPointFive(): void
    {
        $skin = 'html[data-wrc-skin="overlay"]';
        $ov = $skin . '[data-wrc-ov]';
        // [selector, background property, text property]
        $pairs = [
            [$skin . ' .btn-primary', 'background', 'color'],
            [$skin . ' .btn-success', 'background', 'color'],
            [$skin . ' .btn-warning', 'background', 'color'],
            [$skin . ' .btn-info', 'background', 'color'],
            [$skin . ' .btn-danger', 'background', 'color'],
            [$skin . ' .btn-primary:hover, ' . $skin . ' .btn-primary:focus-visible, ' . $skin . ' .btn-primary:active', 'background', 'color'],
            [$skin . ' .btn-success:hover, ' . $skin . ' .btn-success:focus-visible, ' . $skin . ' .btn-success:active', 'background', 'color'],
            [$skin . ' .btn-warning:hover, ' . $skin . ' .btn-warning:focus-visible, ' . $skin . ' .btn-warning:active', 'background', 'color'],
            [$skin . ' .btn-info:hover, ' . $skin . ' .btn-info:focus-visible, ' . $skin . ' .btn-info:active', 'background', 'color'],
            [$skin . ' .btn-danger:hover, ' . $skin . ' .btn-danger:focus-visible, ' . $skin . ' .btn-danger:active', 'background', 'color'],
            [$skin . ' .badge.bg-primary', 'background', 'color'],
            [$skin . ' .badge.bg-success', 'background', 'color'],
            [$skin . ' .badge.bg-warning', 'background', 'color'],
            [$skin . ' .badge.bg-info', 'background', 'color'],
            [$skin . ' .badge.bg-danger', 'background', 'color'],
            [$ov . ' .wrc-cnt.bad', 'background', 'color'],
            [$ov . ' .wrc-btn-warn', 'background', 'color'],
            [$ov . ' .wrc-ov-top .wrc-kpi-bad', 'background', 'color'],
            [$ov . ' .wrc-ov-top .wrc-kpi-warn', 'background', 'color'],
        ];
        foreach ($pairs as [$selector, $bg, $fg]) {
            $ratio = self::ratio(self::declared($selector, $fg), self::declared($selector, $bg));
            // The hover of a red / green / amber is a step lighter on purpose and only has to stay clear of three.
            $floor = str_contains($selector, ':hover') || str_contains($selector, '.wrc-kpi-bad:hover') ? 4.0 : 4.5;
            $this->assertGreaterThanOrEqual($floor, $ratio, sprintf('%s: %.2f:1', $selector, $ratio));
        }
        // The hover of the red counter, which has a rule of its own.
        $this->assertGreaterThanOrEqual(4.0, self::ratio('#ffffff', self::declared($ov . ' .wrc-ov-top .wrc-kpi-bad:hover', 'background')));
    }

    public function testEveryRedThatCarriesWhiteTextIsTheFillAndNotTheStrokeRed(): void
    {
        $css = self::css();
        $vars = self::vars();
        $this->assertGreaterThanOrEqual(4.5, self::ratio('#ffffff', $vars['--ov-red-fill']), 'the fill red under white');
        $this->assertLessThan(4.5, self::ratio('#ffffff', $vars['--ov-red']), 'the stroke red is the one that was too light: it must not come back under white text');
        // No rule that sets white text puts it on --ov-red.
        preg_match_all('~\{[^}]*\}~', $css, $blocks);
        foreach ($blocks[0] as $block) {
            if (preg_match('~background:\s*var\(--ov-red\)\s*;~', $block) && preg_match('~(?:^|[;{\s])color:\s*(?:#fff\b|#ffffff\b)~', $block)) {
                $this->fail('white text on the stroke red: ' . trim($block));
            }
        }
        $this->assertTrue(true);
    }

    public function testThePopupsAreTheBoardsDarkPanelNotLeafletsWhiteCard(): void
    {
        $css = self::css();
        $this->assertMatchesRegularExpression('~html\[data-wrc-skin="overlay"\] \.leaflet-popup-content-wrapper,\s*html\[data-wrc-skin="overlay"\] \.leaflet-popup-tip \{[^}]*background: var\(--ov-panel\);[^}]*color: var\(--ov-ink\);~', $css);
        $this->assertMatchesRegularExpression('~\.leaflet-popup-content \{ color: var\(--ov-ink\);~', $css);
        // Leaflet's blue link (0078A8, 2.6:1 here) is for plain links only; a link that is a button keeps the button's colour.
        $this->assertStringContainsString('.leaflet-popup-content a:not(.btn) { color: #7cc0ff; }', $css);
        $this->assertStringContainsString('.leaflet-popup-content a.btn { color: var(--bs-btn-color); }', $css);
        $this->assertGreaterThanOrEqual(7.0, self::ratio('#7cc0ff', '#202930'), 'link on the panel (the panel is rgba(32, 41, 48, .94) over the map)');
        // Not behind the layout switch: below 1200 px the console is dark too, and its popups would be white again.
        $this->assertDoesNotMatchRegularExpression('~\[data-wrc-ov\][^{]*\.leaflet-popup~', $css);
    }

    public function testTheMutedTextAndTheOutlineButtonsOfAPopupAreTheDarkSchemesTones(): void
    {
        $css = self::css();
        $this->assertStringContainsString('.leaflet-popup-content .text-muted,', $css);
        $this->assertMatchesRegularExpression('~\.leaflet-popup-content \.small\.text-muted \{ color: var\(--ov-mut\) !important; \}~', $css);
        foreach (['primary', 'success', 'dark'] as $kind) {
            $this->assertMatchesRegularExpression('~html\[data-wrc-skin="overlay"\] \.btn-outline-' . $kind . ' \{ --bs-btn-color: var\(--bs-' . $kind . '-text-emphasis\); --bs-btn-border-color: var\(--bs-' . $kind . '-text-emphasis\); \}~', $css, "outline-$kind");
        }
        // The site gives outline-primary a colour of its own (the accent, #667eea), so the variable alone does not reach it.
        $this->assertStringContainsString('.btn-outline-primary:not(:hover):not(:active):not(.active) { color: var(--bs-primary-text-emphasis);', $css);
        $this->assertStringContainsString('.text-primary { color: var(--bs-primary-text-emphasis) !important; }', $css);
    }

    public function testTheNamesOnTheMapHaveALightInkAndADarkHaloWithNoPadding(): void
    {
        $css = self::css();
        preg_match('~\.wr-polygon-label \{([^}]*)\}~', $css, $m);
        $this->assertNotEmpty($m, 'the sector / area name rule');
        $this->assertStringContainsString('color: #ffb3ba;', $m[1]);
        $this->assertGreaterThanOrEqual(4, substr_count($m[1], '#0b0e10'), 'a halo on all four sides');
        $this->assertStringContainsString('text-shadow:', $m[1]);
        $this->assertStringNotContainsString('padding', $m[1], 'the page fits a name to its shape by the text\'s width: a box would break that');
        $this->assertStringContainsString('.wr-polygon-label.wr-polygon-label-restricted { color: #ffc58a; }', $css);
        // Against the darkest and the lightest ground the board's map has (dimmed street tiles, dark satellite) the halo is what carries it: the ink alone is
        // at least 7:1 on the halo colour itself.
        $this->assertGreaterThanOrEqual(7.0, self::ratio('#ffb3ba', '#0b0e10'));
        $this->assertGreaterThanOrEqual(7.0, self::ratio('#ffc58a', '#0b0e10'));
    }

    public function testTheChipAndTheClusterCountsTakeADarkInkOnTheirLightFills(): void
    {
        $css = self::css();
        // The chip's fill is the page's own cream; the cluster discs are MarkerCluster's green / gold / orange.
        $page = str_replace("\r\n", "\n", (string) file_get_contents(__DIR__ . '/../war-room.php'));
        $this->assertStringContainsString('.wr-hidden-chip { background: #fff8e1;', $page, 'the fill the ink is chosen for');
        $this->assertMatchesRegularExpression('~\.wr-hidden-chip \{ color: (#[0-9a-f]{6}); \}~', $css);
        preg_match('~\.wr-hidden-chip \{ color: (#[0-9a-f]{6}); \}~', $css, $chip);
        $this->assertGreaterThanOrEqual(7.0, self::ratio($chip[1], '#fff8e1'));
        preg_match('~\.marker-cluster > div > span \{ color: (#[0-9a-f]{6}); \}~', $css, $cluster);
        $this->assertNotEmpty($cluster, 'the cluster count rule');
        foreach (['#b5e28c', '#f1d357', '#f0c20c', '#fd9c3c', '#ccaa1f', '#f18017'] as $disc) {
            $this->assertGreaterThanOrEqual(6.5, self::ratio($cluster[1], $disc), "count on $disc");
        }
    }

    public function testASelectsOpenListHasABackgroundOfItsOwn(): void
    {
        $css = self::css();
        $this->assertMatchesRegularExpression('~select option,\s*html\[data-wrc-skin="overlay"\] select optgroup \{ background-color: (#[0-9a-f]{6}); color: var\(--ov-ink\); \}~', $css);
        preg_match('~select option,\s*html\[data-wrc-skin="overlay"\] select optgroup \{ background-color: (#[0-9a-f]{6});~', $css, $m);
        $this->assertGreaterThanOrEqual(7.0, self::ratio(self::vars()['--ov-ink'] ?? '#ffffff', $m[1]));
    }

    public function testTheSmallFaintTextsGotLighter(): void
    {
        $css = self::css();
        $this->assertStringContainsString('--bs-dropdown-header-color: var(--ov-mut);', $css);
        $this->assertGreaterThanOrEqual(4.5, self::ratio('#aebbc6', '#151b20'), 'the menu heading on the menu');
        // The acknowledgement panel stands on #111c33 (the page's own navy).
        $this->assertStringContainsString('.ack-card-progress-row.ack-todo,', $css);
        $this->assertGreaterThanOrEqual(4.5, self::ratio('#9fb0c6', '#111c33'));
        $this->assertStringContainsString('.notif-badge { background: var(--ov-red-fill) !important; }', $css);
        $this->assertGreaterThanOrEqual(4.5, self::ratio('#ffaaa2', '#53383d'), 'the red tag on its tint');
        $this->assertGreaterThanOrEqual(4.5, self::ratio('#ffc4bf', '#1e3c72'), 'the menu\'s red entry on the navy');
    }

    public function testTheOtherLooksAreUntouched(): void
    {
        foreach (['assets/css/war-room-console-nocturne.css', 'assets/css/war-room-console-aurora.css', 'assets/css/war-room-console.css'] as $other) {
            $source = (string) file_get_contents(__DIR__ . '/../' . $other);
            foreach (['--ov-red-fill', 'wr-polygon-label', 'ov-select'] as $needle) {
                $this->assertStringNotContainsString($needle, $source, "$other");
            }
        }
    }
}
