<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The «Κονσόλα» looks (Settings → Action Room → «Εμφάνιση Κονσόλας»).
 *
 * A look is one more stylesheet over the same markup, chosen by one setting. What is pinned:
 *  - the setting exists, defaults to the console as it always was, is saved with the Action Room
 *    form and is checked against a closed list on the way in AND on the way out (the value ends up
 *    in a file path and in an HTML attribute);
 *  - war-room.php loads a skin only for the console view, and only a skin that is not 'classic';
 *  - a skin can never leak into another look: every rule in its sheet sits behind
 *    html[data-wrc-skin="<its name>"].
 *
 * Two kinds of skin. A LOOK (nocturne, aurora) is colours, type and materials over the console's three
 * columns: it may not touch the grid or hide a region. A LAYOUT (overlay, «Πίνακας») re-places the regions
 * on purpose - the map takes the window and the rest floats over it - so it gets its own, narrower rules:
 * LAYOUT_MAY_HIDE below names the regions it may hide and the state that does it; everything else stays
 * on screen. The looks keep the strict rule untouched.
 */
final class ConsoleSkinTest extends TestCase
{
    /** Colour-only skins: no layout change, no hidden region. */
    private const SKINS = ['nocturne', 'aurora'];

    /** Skins that are a layout (they have a companion script) and say so here. */
    private const LAYOUT_SKINS = ['overlay'];

    /**
     * The only region rules a layout skin may hide with display:none, each with the state attribute that
     * must be in the same selector - so it is hidden because someone closed it, never for good.
     */
    private const LAYOUT_MAY_HIDE = [
        'overlay' => [
            '.wrc-teams' => '[data-ov-teams="closed"]', // the team list is a toggled panel; the bottom bar and the rail reach every team without it
            '.wrc-pane' => '[data-ov-dock="closed"]',   // the right panel folded to its title bar; its tabs stay
        ],
    ];

    private string $root;

    protected function setUp(): void
    {
        $this->root = (string) realpath(__DIR__ . '/..');
    }

    private function source(string $relative): string
    {
        $text = \WarRoomSource::read($relative);
        $this->assertNotFalse($text, $relative);
        return str_replace("\r\n", "\n", (string) $text);
    }

    public function testTheSettingDefaultsToTheClassicConsoleAndIsSavedWithTheActionRoomForm(): void
    {
        $settings = $this->source('settings.php');
        $this->assertStringContainsString("'war_room_console_skin' => 'classic',", $settings);
        $this->assertMatchesRegularExpression("/\\\$actionRoomFields = \\[[^\\]]*'war_room_console_skin'/s", $settings);
        $this->assertStringContainsString(
            "\$field === 'war_room_console_skin' && !in_array(\$value, ['classic', 'nocturne', 'aurora', 'overlay'], true)",
            $settings,
            'anything but a known skin is stored as the classic console'
        );
        foreach (['classic', 'nocturne', 'aurora', 'overlay'] as $skin) {
            $this->assertStringContainsString('name="war_room_console_skin" value="' . $skin . '"', $settings, "the chooser offers $skin");
        }
    }

    public function testWarRoomLoadsASkinForTheConsoleViewOnly(): void
    {
        $page = $this->source('war-room.php');
        $this->assertStringContainsString("in_array(\$wrConsoleSkin, ['classic', 'nocturne', 'aurora', 'overlay'], true)) \$wrConsoleSkin = 'classic';", $page);

        $start = strpos($page, '<?php if ($consoleView): ?>' . "\n" . '<?php /* Empty on purpose');
        $this->assertNotFalse($start, 'the console block');
        $end = strpos($page, '<?php endif; ?>', (int) strpos($page, 'id="wrConsole"', (int) $start));
        $block = substr($page, (int) $start, (int) $end - (int) $start);

        $this->assertStringContainsString("<?php if (\$wrConsoleSkin !== 'classic'): ?>", $block, 'classic loads nothing extra');
        $this->assertStringContainsString("/assets/css/war-room-console-<?= \$wrConsoleSkin ?>.css", $block);
        $this->assertStringContainsString("document.documentElement.setAttribute('data-wrc-skin','<?= \$wrConsoleSkin ?>')", $block);
        // A dark look decides the colour mode itself; every other look keeps the coordinator's own light / dark choice.
        $this->assertStringContainsString("if (in_array(\$wrConsoleSkin, ['nocturne', 'overlay'], true))", $block);
        $this->assertStringContainsString("localStorage.getItem('wr-console-theme')==='dark'", $block);
        // Nothing of a skin outside that block (the classic Action Room must not change).
        $outside = substr($page, 0, (int) $start) . substr($page, (int) $end);
        $this->assertStringNotContainsString('war-room-console-<?=', $outside);
        $this->assertStringNotContainsString('data-wrc-skin', $outside);
    }

    /** @return array<string, array{0: string}> */
    public static function skins(): array
    {
        return array_map(fn(string $s) => [$s], array_combine(self::SKINS, self::SKINS));
    }

    #[DataProvider('skins')]
    public function testEveryRuleOfASkinSitsBehindItsOwnAttribute(string $skin): void
    {
        $css = $this->source("assets/css/war-room-console-$skin.css");
        $css = (string) preg_replace('~/\*.*?\*/~s', '', $css);
        $prefix = 'html[data-wrc-skin="' . $skin . '"]';

        $selectors = $this->selectors($css);
        $this->assertGreaterThan(40, count($selectors), 'the sheet has rules');
        foreach ($selectors as $selector) {
            $this->assertStringStartsWith($prefix, $selector, "a rule outside the $skin attribute: $selector");
        }
    }

    #[DataProvider('skins')]
    public function testASkinNeverSwitchesTheConsoleOffOrMovesItsColumns(string $skin): void
    {
        $css = $this->source("assets/css/war-room-console-$skin.css");
        $css = (string) preg_replace('~/\*.*?\*/~s', '', $css);
        // A look, not a layout: nothing here may hide a region, change the three-column grid or take a control away.
        $this->assertStringNotContainsString('grid-template-columns', $css);
        $this->assertDoesNotMatchRegularExpression('/\.wrc(-left|-center|-dock|-composer|-queue|-teams)?\s*\{[^}]*display\s*:\s*none/', $css);
    }

    /** @return array<string, array{0: string}> */
    public static function layoutSkins(): array
    {
        return array_map(fn(string $s) => [$s], array_combine(self::LAYOUT_SKINS, self::LAYOUT_SKINS));
    }

    #[DataProvider('layoutSkins')]
    public function testEveryRuleOfALayoutSkinSitsBehindItsOwnAttribute(string $skin): void
    {
        $css = (string) preg_replace('~/\*.*?\*/~s', '', $this->source("assets/css/war-room-console-$skin.css"));
        $prefix = 'html[data-wrc-skin="' . $skin . '"]';

        $selectors = $this->selectors($css);
        $this->assertGreaterThan(100, count($selectors), 'the sheet has rules');
        foreach ($selectors as $selector) {
            $this->assertStringStartsWith($prefix, $selector, "a rule outside the $skin attribute: $selector");
        }
    }

    /**
     * A layout skin re-places the regions, but only while its layout is on: html[data-wrc-ov] is set at the
     * first paint when the window is 1200 px or wider (war-room.php) and kept true by the companion script,
     * which moves the controls out and back. So nothing that positions or hides may apply without it - a
     * narrower window must get the console's own columns.
     */
    #[DataProvider('layoutSkins')]
    public function testALayoutSkinRePlacesNothingWithoutItsLayoutAttribute(string $skin): void
    {
        $css = (string) preg_replace('~/\*.*?\*/~s', '', $this->source("assets/css/war-room-console-$skin.css"));
        $checked = 0;
        foreach ($this->rules($css) as [$selector, $body]) {
            if (!preg_match('~position\s*:\s*(fixed|absolute)|display\s*:\s*none~', $body)) {
                continue;
            }
            $checked++;
            // The elements the script adds are hidden by a block of their own (they only exist for the layout).
            $addsOnly = (bool) preg_match('~\.wrc-ov-[a-z-]+$~', $selector);
            $this->assertTrue(
                str_contains($selector, '[data-wrc-ov]') || $addsOnly || str_contains($selector, '.wrc-theme'),
                "«{$selector}» positions or hides without html[data-wrc-ov]: a window under 1200 px would get it too"
            );
        }
        $this->assertGreaterThan(20, $checked);
    }

    /**
     * The layout skin may not take a region of the console away - with the named exceptions in
     * LAYOUT_MAY_HIDE (the team list, a toggled panel, and the right panel's body while it is folded). The
     * strict rule for the looks above is untouched.
     */
    #[DataProvider('layoutSkins')]
    public function testALayoutSkinHidesOnlyTheRegionItNames(string $skin): void
    {
        $css = (string) preg_replace('~/\*.*?\*/~s', '', $this->source("assets/css/war-room-console-$skin.css"));
        $this->assertStringNotContainsString('grid-template-columns', $css, 'the layout is absolute positions, not a new grid');
        $regions = '\.wrc(?:-left|-center|-dock|-composer|-queue|-teams|-mapslot|-sec|-pane|-tabs)?';
        $hidden = [];
        foreach ($this->rules($css) as [$selector, $body]) {
            if (!preg_match('~(?<![\w-])display\s*:\s*none~', $body)) {
                continue;
            }
            foreach (explode(',', $selector) as $one) {
                $one = trim($one);
                if (!preg_match('~' . $regions . '$~', $one)) {
                    continue;
                }
                $matched = false;
                foreach (self::LAYOUT_MAY_HIDE[$skin] as $region => $state) {
                    if (str_ends_with($one, $region) && str_contains($one, $state)) {
                        $hidden[$region] = true;
                        $matched = true;
                    }
                }
                $this->assertTrue($matched, "«{$one}» hides a region of the console - only the named exception may");
            }
        }
        $this->assertSame(array_keys(self::LAYOUT_MAY_HIDE[$skin]), array_keys($hidden), 'a named exception is not in the sheet any more: drop it from the test');
    }

    /**
     * What the companion script adds is hidden for a window under 1200 px by one block of display:none; the
     * layout has to show each of those again, or the element disappears from the board it was built for
     * (it did once: the selected-team card and the tab icons were gone from a screenshot nobody compared).
     */
    public function testTheLayoutShowsEveryElementTheScriptAddsAgain(): void
    {
        $css = (string) preg_replace('~/\*.*?\*/~s', '', $this->source('assets/css/war-room-console-overlay.css'));
        $rules = $this->rules($css);
        $added = [];
        foreach ($rules as [$selector, $body]) {
            if (!str_contains($selector, '[data-wrc-ov]') && preg_match('~(?<![\w-])display\s*:\s*none~', $body)) {
                foreach (explode(',', $selector) as $one) {
                    if (preg_match('~(\.wrc-ov-[a-z-]+)$~', trim($one), $m)) {
                        $added[$m[1]] = true;
                    }
                }
            }
        }
        $this->assertGreaterThan(8, count($added), 'the hiding block names what the script adds');
        foreach (array_keys($added) as $class) {
            $shown = false;
            foreach ($rules as [$selector, $body]) {
                foreach (explode(',', $selector) as $one) {
                    $one = trim($one);
                    if (str_contains($one, '[data-wrc-ov]') && str_ends_with($one, $class) && preg_match('~(?<![\w-])display\s*:\s*(?!none)[a-z-]+~', $body)) {
                        $shown = true;
                    }
                }
            }
            $this->assertTrue($shown, "$class is hidden outside the layout but nothing shows it inside");
        }
    }

    public function testTheLayoutSkinLoadsItsScriptAfterTheConsoleAndOnlyForIt(): void
    {
        $page = $this->source('war-room.php');
        $this->assertSame(1, substr_count($page, 'war-room-console-overlay.js?v='));
        $console = (int) strpos($page, 'assets/js/war-room-console.js?v=');
        $overlay = (int) strpos($page, 'assets/js/war-room-console-overlay.js?v=');
        $this->assertGreaterThan($console, $overlay, 'a deferred script that reads the console runs after it');
        $before = substr($page, $console, $overlay - $console);
        $this->assertStringContainsString("<?php if (\$wrConsoleSkin === 'overlay'): ?>", $before);
        $this->assertStringContainsString("defer></script>", substr($page, $overlay, 400));
        // The layout is on from the first paint at 1200 px and wider, before the script has run.
        $this->assertStringContainsString("matchMedia('(min-width:1200px)').matches)document.documentElement.setAttribute('data-wrc-ov','pre')", $page);
        // And the script bows out of any other look.
        $js = $this->source('assets/js/war-room-console-overlay.js');
        $this->assertStringContainsString("html.getAttribute('data-wrc-skin') !== 'overlay'", $js);
    }

    /**
     * Every rule of $css as [selector group, declarations], looking inside @media / @supports.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function rules(string $css): array
    {
        $out = [];
        $i = 0;
        $n = strlen($css);
        while ($i < $n) {
            $open = strpos($css, '{', $i);
            if ($open === false) {
                break;
            }
            $head = trim(substr($css, $i, $open - $i));
            $depth = 1;
            $j = $open + 1;
            while ($j < $n && $depth > 0) {
                if ($css[$j] === '{') {
                    $depth++;
                } elseif ($css[$j] === '}') {
                    $depth--;
                }
                $j++;
            }
            $body = substr($css, $open + 1, $j - $open - 2);
            if ($head !== '' && $head[0] === '@') {
                if (preg_match('/^@(media|supports)\b/', $head)) {
                    array_push($out, ...$this->rules($body));
                }
            } elseif ($head !== '') {
                $out[] = [$head, $body];
            }
            $i = $j;
        }
        return $out;
    }

    /**
     * The selectors of every style rule in $css, looking inside @media / @supports and skipping
     * @keyframes and @font-face (whose blocks hold no selectors of ours).
     *
     * @return list<string>
     */
    private function selectors(string $css): array
    {
        $out = [];
        $i = 0;
        $n = strlen($css);
        while ($i < $n) {
            $open = strpos($css, '{', $i);
            if ($open === false) {
                break;
            }
            $head = trim(substr($css, $i, $open - $i));
            // find the matching close
            $depth = 1;
            $j = $open + 1;
            while ($j < $n && $depth > 0) {
                if ($css[$j] === '{') {
                    $depth++;
                } elseif ($css[$j] === '}') {
                    $depth--;
                }
                $j++;
            }
            $body = substr($css, $open + 1, $j - $open - 2);
            if ($head !== '' && $head[0] === '@') {
                if (preg_match('/^@(media|supports)\b/', $head)) {
                    array_push($out, ...$this->selectors($body));
                }
            } elseif ($head !== '') {
                foreach (explode(',', $head) as $one) {
                    $out[] = trim($one);
                }
            }
            $i = $j;
        }
        return $out;
    }
}
