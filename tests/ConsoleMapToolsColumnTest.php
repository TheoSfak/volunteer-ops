<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * The map's tools of the «Πίνακας» look in one column at the right edge (v3.435.0), under the drawing tools, folding to
 * one button like them: the map view, follow and zoom, the ruler and the search rings, the heat layer, the command post
 * and the map points - what used to be the stack beside the panel and the pair in the bottom-right corner.
 *
 * What the button shows is a plain function, tested in tests/js/war-room-console-overlay.test.js (wrcOvMapFace). Pinned
 * here, by source, is what only fails on a screen:
 *  - the controls are MOVED (the stack and the pair themselves, with everything the page and the map put in them), never copied;
 *  - the drawing tools come first and the map's tools under them, both in one column that is placed once;
 *  - open is the default, the choice is remembered per browser, and a blocked store must not stop the board;
 *  - a window too short for the two gets smaller buttons first and then folds the column that was NOT just opened, in the
 *    page only (what was chosen stays what is remembered);
 *  - the layout, the narrow fallback and the words.
 */
final class ConsoleMapToolsColumnTest extends TestCase
{
    private static function source(string $file): string
    {
        return str_replace("\r\n", "\n", (string) file_get_contents(__DIR__ . '/../' . $file));
    }

    private static function between(string $text, string $from, string $to): string
    {
        $start = strpos($text, $from);
        self::assertNotFalse($start, "marker not found: $from");
        $end = strpos($text, $to, $start + strlen($from));
        self::assertNotFalse($end, "marker not found: $to");
        return substr($text, $start, $end - $start);
    }

    private static function module(): string
    {
        return self::between(self::source('assets/js/war-room-console-overlay.js'), '// ── the map tools column:', '// ── moving the real controls in, and back');
    }

    private static function css(): string
    {
        return (string) preg_replace('~/\*.*?\*/~s', '', self::source('assets/css/war-room-console-overlay.css'));
    }

    public function testTheStackAndThePairAreMovedIntoTheColumnAndNeverCopied(): void
    {
        $js = self::source('assets/js/war-room-console-overlay.js');
        // The very elements, with the controls that take() / adopt() put in them, go into the column's body.
        $this->assertStringContainsString('mapBody.append(stack, pair);', $js);
        $this->assertStringContainsString('mapCol.append(mapBtn, mapBody);', $js);
        $this->assertStringContainsString('edge.appendChild(mapCol);', $js);
        $this->assertStringContainsString('root.append(top, rail, edge, bottom, tools);', $js, 'the stack and the pair are not placed on the board a second time');
        $module = self::module();
        foreach (['cloneNode', 'outerHTML', 'createElement(\'button\')'] as $copy) {
            $this->assertStringNotContainsString($copy, $module, "the controls are not rebuilt here: «{$copy}»");
        }
        // Everything that used to go into them still does.
        foreach (["take(\$('.wr-cp-control'), pair);", "take(\$('.wr-mp-control'), pair);", "pair.insertBefore(heatBtn, pair.firstChild);",
                  "take(\$('.leaflet-control-zoom'), \$('[data-g=\"nav\"]', stack));", "take(\$('#mapRingsToggle'), \$('[data-g=\"extra\"]', stack));",
                  "\$('[data-g=\"extra\"]', stack).appendChild(ruler);", "take(layers && layers.parentNode, nav);", "take(\$('#mapFollowBtn'), nav);"] as $door) {
            $this->assertStringContainsString($door, $js, $door);
        }
        // The page's controls keep their ids: the page's code finds them by them.
        $page = self::source('war-room.php');
        foreach (['mapSatelliteToggle', 'mapFollowBtn', 'mapRingsToggle'] as $id) {
            $this->assertSame(1, substr_count($page, 'id="' . $id . '"'), $id);
        }
    }

    public function testTheDrawingToolsComeFirstAndTheMapToolsUnderThem(): void
    {
        $js = self::source('assets/js/war-room-console-overlay.js');
        $this->assertStringContainsString('edge.insertBefore(drawCol, mapCol);', $js, 'draw first, the map tools under it');
        $this->assertSame(1, substr_count($js, "mk('div', 'wrc-ov-edge', 'wrcOvEdge')"));
        $this->assertStringNotContainsString('root.appendChild(drawCol)', $js, 'the drawing column is not placed on the board on its own any more: a second absolute column would sit on the map\'s tools');
    }

    public function testTheColumnIsOpenByDefaultAndTheChoiceIsRememberedPerBrowser(): void
    {
        $module = self::module();
        $this->assertStringContainsString("const MAPG_KEY = 'wr-console-ov-mapg';", $module);
        // The opposite default to the drawing tools: these are used all the time, so only an explicit «closed» folds them.
        $this->assertStringContainsString("maptools.open = localStorage.getItem(MAPG_KEY) !== 'closed';", $module);
        $this->assertMatchesRegularExpression('~try \{ maptools\.open = localStorage\.getItem\(MAPG_KEY\)[^\n]*catch~', $module, 'a blocked storage must not stop the board');
        $this->assertStringContainsString("localStorage.setItem(MAPG_KEY, open ? 'open' : 'closed')", $module);
        $this->assertStringContainsString("const maptools = {open: true};", self::source('assets/js/war-room-console-overlay.js'), 'open until the browser says otherwise');
        // The button's own press is the choice, and is remembered.
        $this->assertStringContainsString("mapBtn.addEventListener('click', () => setMapTools(!maptools.open, true));", $module);
        // A key of its own, so folding one column does not fold the other.
        $this->assertSame(1, substr_count(self::source('assets/js/war-room-console-overlay.js'), "'wr-console-ov-mapg'"));
        $this->assertSame(1, substr_count(self::source('assets/js/war-room-console-overlay.js'), "'wr-console-ov-draw'"));
    }

    public function testTheButtonShowsTheMapIconAndIsLitWhenSomethingIsOnBehindTheFold(): void
    {
        $module = self::module();
        $this->assertStringContainsString('wrcOvMapFace(maptools.open, !!(m && m.active()) || heat.on)', $module, 'the face is the tested function\'s; lit = the ruler is waiting or the heat layer is on');
        $this->assertStringContainsString("mapBtn.classList.toggle('on', face.on);", $module);
        $this->assertStringContainsString("mapBtn.setAttribute('aria-expanded'", $module);
        $this->assertStringContainsString("mapBtn.setAttribute('aria-controls', 'wrcOvMapBody');", self::source('assets/js/war-room-console-overlay.js'));
        // It is repainted whenever the ruler or the heat layer change - they are the two things that can be on behind the fold.
        $js = self::source('assets/js/war-room-console-overlay.js');
        $paintRuler = self::between($js, 'function paintRuler() {', "ruler.addEventListener('click'");
        $this->assertStringContainsString('paintMapTools();', $paintRuler);
        $heatClick = self::between($js, "heatBtn.addEventListener('click'", '// ── the ruler:');
        $this->assertStringContainsString('paintMapTools();', $heatClick);
    }

    public function testAWindowTooShortGetsSmallerButtonsFirstAndThenFoldsTheOtherColumn(): void
    {
        $module = self::module();
        $fit = self::between($module, 'function fitEdge(keep) {', 'window.addEventListener(\'resize\'');
        // The ladder, in order: normal -> dense -> fold the column that was not just opened.
        $normal = strpos($fit, 'delete edge.dataset.dense;');
        $dense = strpos($fit, "edge.dataset.dense = 'on';");
        $fold = strpos($fit, "keep === 'map' ? ['draw']");
        $this->assertNotFalse($normal);
        $this->assertGreaterThan($normal, (int) $dense, 'the dense look is tried after the normal one has been measured');
        $this->assertGreaterThan((int) $dense, (int) $fold, 'a column folds only if the dense look still does not fit');
        // The column just opened is never the one that folds, and with no one to keep the drawing tools go first.
        $this->assertStringContainsString("keep === 'map' ? ['draw'] : (keep === 'draw' ? ['map'] : ['draw', 'map'])", $fit);
        // In the page only: nothing is written to the browser, so a taller window shows what was chosen.
        $this->assertStringNotContainsString('localStorage', $fit);
        $this->assertStringNotContainsString('setDraw(', $fit);
        $this->assertStringNotContainsString('setMapTools(', $fit);
        // Cannot loop on its own changes, and does nothing when the board is not engaged.
        $this->assertStringContainsString('if (!engaged || fitting || !edge.offsetHeight) return;', $fit);
        $this->assertStringContainsString('} finally { fitting = false; }', $fit);
        // The room is measured against the board's own height, with a margin.
        $this->assertStringContainsString('edge.offsetTop + edge.offsetHeight <= root.clientHeight - 10', $module);
    }

    public function testTheFitIsChosenWhenEitherColumnOpensWhenTheWindowChangesAndWhenTheColumnGrows(): void
    {
        $js = self::source('assets/js/war-room-console-overlay.js');
        $module = self::module();
        $this->assertStringContainsString("fitEdge(open ? 'map' : '');", $module, 'opening the map tools folds the drawing tools if there is no room, not the other way round');
        $this->assertStringContainsString("fitEdge(open ? 'draw' : '');", $js);
        $this->assertStringContainsString("window.addEventListener('resize', () => fitEdge(''));", $module);
        $this->assertStringContainsString("new ResizeObserver(() => fitEdge('')).observe(edge);", $module, 'the search rings\' switch comes and goes with the mission');
        $engage = self::between($js, 'function engage() {', 'function disengage() {');
        $this->assertStringContainsString("paintMapTools();\n        fitEdge('');", $engage);
    }

    public function testTheLayoutPlacesTheEdgeOnceAndFoldsByHidingOnlyTheBody(): void
    {
        $css = self::css();
        $this->assertMatchesRegularExpression('~\[data-wrc-ov\] \.wrc-ov-edge \{[^}]*display: flex;[^}]*flex-direction: column;[^}]*position: absolute;[^}]*top: 86px; right: 14px;[^}]*pointer-events: none;~', $css);
        // Neither the stack nor the pair is placed on its own any more: they are laid out inside the column.
        foreach (['wrc-ov-stack', 'wrc-ov-pair', 'wrc-ov-mapg', 'wrc-ov-mapg-body'] as $inner) {
            $this->assertMatchesRegularExpression('~\[data-wrc-ov\] \.' . $inner . ' \{[^}]*display: flex;[^}]*pointer-events: none;~', $css, $inner);
            $this->assertDoesNotMatchRegularExpression('~\[data-wrc-ov\] \.' . $inner . ' \{[^}]*position: absolute;~', $css, "$inner is placed by the edge");
        }
        $this->assertDoesNotMatchRegularExpression('~\[data-wrc-ov\] \.wrc-ov-pair \{[^}]*(?:right|bottom): \d+px~', $css, 'the bottom-right corner is empty now');
        // Folded: the column's own state hides the controls, and nothing else.
        $this->assertMatchesRegularExpression('~\[data-wrc-ov\] \.wrc-ov-mapg\[data-open="closed"\] \.wrc-ov-mapg-body \{ display: none; \}~', $css);
        // Hidden outside the layout with the other elements the script adds (ConsoleSkinTest checks that each is shown again).
        $this->assertMatchesRegularExpression('~html\[data-wrc-skin="overlay"\] \.wrc-ov-edge,~', $css);
        // The other looks never hear of it.
        foreach (['assets/css/war-room-console-nocturne.css', 'assets/css/war-room-console-aurora.css'] as $other) {
            $source = self::source($other);
            foreach (['wrc-ov-edge', 'wrc-ov-mapg'] as $name) {
                $this->assertStringNotContainsString($name, $source);
            }
        }
    }

    public function testTheAssistantsRoundButtonStandsBesideTheColumnNotOnItsLastButton(): void
    {
        $css = self::css();
        // The page's own rule puts it at the bottom right, 96 px up (the column's last button, the map points, would be under it).
        $page = self::source('war-room.php');
        $this->assertMatchesRegularExpression('~\.assistant-fab \{[^}]*position: fixed;[^}]*right: 16px;[^}]*bottom: 96px;~s', $page, 'the page\'s rule the board has to outrank');
        $this->assertMatchesRegularExpression('~\[data-wrc-ov\] \.assistant-fab \{ right: calc\(14px \+ 52px \+ 14px\); bottom: 20px; \}~', $css);
        // It must not land on the dock above (bottom 150 px) or on the heat legend (bottom 82 px) - read from the sheet, not remembered.
        preg_match('~\.wrc-dock \{[^}]*bottom: (\d+)px;~', $css, $dock);
        preg_match('~\.wrc-ov-heat-legend \{[^}]*bottom: (\d+)px;~', $css, $legend);
        $this->assertNotEmpty($dock);
        $this->assertNotEmpty($legend);
        $this->assertGreaterThanOrEqual(20 + 52 + 6, (int) $legend[1], 'the legend stands above the round button with a gap');
        $this->assertGreaterThan(20 + 52, (int) $dock[1]);
    }

    public function testThePanelsThatHungOffTheOldStackNowClearThePanelOnly(): void
    {
        $css = self::css();
        // They used to stand 96-100 px left of the dock to leave room for the stack; with it at the window's edge they keep a gap of their own.
        $this->assertDoesNotMatchRegularExpression('~var\(--ov-dock-w\) \+ (?:96|100)px~', $css, 'the old stack\'s room is not kept as a dead strip');
        foreach (['.ack-tracker', '.wrc-composer', 'leaflet-top.leaflet-right'] as $follower) {
            $this->assertMatchesRegularExpression('~' . preg_quote($follower, '~') . '[^{]*\{[^}]*right: calc\(var\(--ov-dock-w\) \+ (\d+)px\)~', $css, $follower);
            preg_match('~' . preg_quote($follower, '~') . '[^{]*\{[^}]*right: calc\(var\(--ov-dock-w\) \+ (\d+)px\)~', $css, $m);
            // The dock's left edge is --ov-dock-w + 20 px from the window's right; a real gap beyond it.
            $this->assertGreaterThanOrEqual(20 + 12, (int) $m[1], "$follower clears the panel by a gap");
        }
    }

    public function testADenseColumnShrinksEveryKindOfButtonItHolds(): void
    {
        $css = self::css();
        $this->assertMatchesRegularExpression('~\.wrc-ov-edge\[data-dense="on"\] \{ gap: 10px; \}~', $css);
        foreach (['.wrc-ov-sq', '.wrc-ov-draw .btn', '.wrc-ov-stack .btn', '.wrc-ov-stack .leaflet-control-zoom a', '.wrc-ov-pair .leaflet-bar a', '.wrc-ov-pair .leaflet-touch .leaflet-bar a'] as $kind) {
            $this->assertMatchesRegularExpression('~\.wrc-ov-edge\[data-dense="on"\] ' . preg_quote($kind, '~') . '[,\s][^{]*\{[^}]*width: 42px; height: 42px;~', $css, "$kind has a dense size");
        }
        // The dock yields by the column's width (52 px + the margin), which dense does not widen.
        $this->assertDoesNotMatchRegularExpression('~\[data-dense="on"\][^{]*\{[^}]*width: (?:5\d|6\d)px~', $css);
    }

    public function testNarrowerThanTheBoardTheControlsGoBackAndTheColumnIsGone(): void
    {
        $js = self::source('assets/js/war-room-console-overlay.js');
        $disengage = self::between($js, 'function disengage() {', '// ── what the page puts at the top');
        $this->assertStringContainsString('giveBack();', $disengage, 'the page\'s own controls go back where they were');
        // The elements the script made stay in the column, which is hidden outside the layout like the rest it adds.
        $this->assertStringNotContainsString('edge.remove', $js);
    }

    public function testTheColumnsWordsExistInBothLanguages(): void
    {
        $strings = loadLangStrings('war-room');
        $js = self::source('assets/js/war-room-console-overlay.js');
        preg_match_all("/'(console\.ov_map[a-z_]*)'/", $js, $m);
        $used = array_values(array_unique($m[1]));
        sort($used);
        $this->assertSame(['console.ov_map', 'console.ov_map_fold', 'console.ov_map_lit'], $used, 'named by their keys, never built up');
        foreach (['el', 'en'] as $lang) {
            foreach ($used as $key) {
                $this->assertArrayHasKey($key, $strings[$lang], "$key in $lang");
                $this->assertNotSame('', trim((string) $strings[$lang][$key]), "$key in $lang is empty");
            }
        }
        foreach ($used as $key) {
            $this->assertDoesNotMatchRegularExpression('~[Α-Ωα-ωάέήίόύώ]~u', (string) $strings['en'][$key], "$key in en");
            $this->assertMatchesRegularExpression('~[Α-Ωα-ωάέήίόύώ]~u', (string) $strings['el'][$key], "$key in el is Greek");
        }
    }
}
