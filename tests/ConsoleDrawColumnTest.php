<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * The drawing tools of the «Πίνακας» look (v3.434.0): the page's annotation toolbar (the pen, the arrow, the text,
 * the eraser and clear-all) in a column of its own at the right edge of the window, folded to one button.
 *
 * What the button shows for each state is a plain function, tested in tests/js/war-room-console-overlay.test.js
 * (wrcOvDrawFace). What is pinned here, by source, is what only fails on a screen:
 *  - the toolbar is MOVED, never copied, so the annotation code and the ruler's «one tool at a time» keep finding it;
 *  - the choice is remembered per browser and folded is the default, while a tool switched on by another route opens
 *    the column without being remembered, and Esc folds it only from inside it;
 *  - the dock yields by one variable, so everything that was anchored to its left edge follows it, and the column
 *    and the dock leave a gap;
 *  - the layout, the narrow fallback and the words.
 */
final class ConsoleDrawColumnTest extends TestCase
{
    private static function source(string $file): string
    {
        return str_replace("\r\n", "\n", (string) \WarRoomSource::read($file));
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
        return self::between(self::source('assets/js/war-room-console-overlay.js'), '// ── the drawing column:', '// ── the map tools column:');
    }

    public function testTheRealToolbarIsMovedAndNeverCopied(): void
    {
        $js = self::source('assets/js/war-room-console-overlay.js');
        $this->assertStringContainsString("take(\$('#annotationToolbar'), drawCol)", $js);
        $module = self::module();
        foreach (['cloneNode', 'outerHTML', 'createElement(\'button\')', "mk('button', 'btn"] as $copy) {
            $this->assertStringNotContainsString($copy, $module, "the toolbar is not rebuilt here: «{$copy}»");
        }
        // The page's markup keeps its ids: the annotation code, the ruler and the erase handlers look for them.
        $page = self::source('war-room.php');
        $this->assertSame(1, substr_count($page, 'id="annotationToolbar"'));
        foreach (['annoToolFreehand', 'annoToolArrow', 'annoToolText', 'annoToolErase', 'annoToolClearAll'] as $id) {
            $this->assertSame(1, substr_count($page, 'id="' . $id . '"'), $id);
        }
        // The stack has no drawing column any more; the tools are not taken to it a second time.
        $this->assertStringNotContainsString('data-g="draw"', $js);
        $this->assertSame(1, substr_count($js, "take(\$('#annotationToolbar')"));
    }

    public function testTheColumnIsFoldedByDefaultAndTheChoiceIsRememberedPerBrowser(): void
    {
        $module = self::module();
        $this->assertStringContainsString("const DRAW_KEY = 'wr-console-ov-draw';", $module);
        $this->assertStringContainsString("draw.open = localStorage.getItem(DRAW_KEY) === 'open';", $module, 'only an explicit «open» opens it: no key, a blocked store or any other value is folded');
        $this->assertMatchesRegularExpression('~try \{ draw\.open = localStorage\.getItem\(DRAW_KEY\)[^\n]*catch~', $module, 'a blocked storage must not stop the board');
        $this->assertStringContainsString("localStorage.setItem(DRAW_KEY, open ? 'open' : 'closed')", $module);
        // The button's own press is the choice, and is remembered.
        $this->assertStringContainsString("drawBtn.addEventListener('click', () => setDraw(!draw.open, true));", $module);
    }

    public function testAToolSwitchedOnByAnotherRouteOpensTheColumnWithoutBeingRemembered(): void
    {
        $module = self::module();
        $watch = self::between($module, 'function watchDraw()', "drawBtn.addEventListener('click'");
        $this->assertStringContainsString("attributeFilter: ['class']", $watch, 'the page marks its armed tool with a class: nothing else is watched');
        $this->assertStringContainsString('subtree: true', $watch);
        $callback = self::between($watch, 'new MutationObserver(', ');');
        $this->assertStringContainsString('const opened = !!armedTool() && !draw.open;', $callback);
        $this->assertStringContainsString('if (opened) draw.open = true;', $callback);
        $this->assertStringContainsString("if (opened) fitEdge('draw');", $watch, 'a column opened by a tool makes room for itself (the map tools column folds if the window is too short)');
        $this->assertStringNotContainsString('localStorage', $callback, 'it is not a choice: not remembered');
        $this->assertStringNotContainsString('setDraw(', $callback);
        $this->assertStringContainsString('if (!bar || draw.observer ||', $watch, 'watched once, however often the board is engaged');
    }

    public function testEscFoldsTheColumnOnlyFromInsideIt(): void
    {
        $module = self::module();
        $this->assertStringContainsString(
            "if (e.key === 'Escape' && engaged && draw.open && !e.defaultPrevented && drawCol.contains(document.activeElement)) { setDraw(false, true); drawBtn.focus(); }",
            $module,
            'Esc keeps its other meanings (a measurement, a pick, a modal) everywhere else'
        );
    }

    public function testTheButtonShowsTheArmedToolAndNamesItInItsTitle(): void
    {
        $module = self::module();
        $this->assertStringContainsString('wrcOvDrawFace(draw.open, icon)', $module, 'the face is the tested function\'s, not a second rule');
        $this->assertStringContainsString("drawBtn.classList.toggle('on', face.on);", $module);
        $this->assertStringContainsString("t('console.ov_draw_armed', {tool: (tool.title || '').trim()})", $module, 'the title names the tool with the page\'s own words for it');
        // The icon of the armed tool is read off its own button, so a tool added to the page's toolbar is shown with no change here.
        $this->assertStringContainsString("Array.from(glyph.classList).find(c => c !== 'bi' && c.indexOf('bi-') === 0)", $module);
        $this->assertStringContainsString("drawBtn.setAttribute('aria-expanded'", $module);
        $this->assertStringContainsString("drawBtn.setAttribute('aria-controls', 'annotationToolbar');", $module);
    }

    public function testTheDockYieldsByOneVariableAndLeavesAGap(): void
    {
        $css = (string) preg_replace('~/\*.*?\*/~s', '', self::source('assets/css/war-room-console-overlay.css'));
        $this->assertMatchesRegularExpression('~--ov-dock-pw: 330px; --ov-draw-w: 60px; --ov-dock-w: calc\(var\(--ov-dock-pw\) \+ var\(--ov-draw-w\)\);~', $css);
        $this->assertMatchesRegularExpression('~--ov-dock-pw: 360px;~', $css, 'the wider panel at 1440 px is the panel\'s width, not the width with the column');
        // The panel is as wide as it was and stands the column's width further in...
        $this->assertMatchesRegularExpression('~\.wrc-dock \{[^}]*right: calc\(20px \+ var\(--ov-draw-w\)\); width: var\(--ov-dock-pw\);~', $css);
        // ...and everything that hung off its left edge follows by the sum.
        // (The map's tools used to be one of them; they are at the window's edge now, in the column beside the drawing tools.)
        foreach (['.ack-tracker', '.wrc-composer', 'leaflet-top.leaflet-right', '#trailFilterBar'] as $follower) {
            $this->assertMatchesRegularExpression('~' . preg_quote($follower, '~') . '[^{]*\{[^}]*var\(--ov-dock-w\)~', $css, "$follower follows the dock");
        }
        $this->assertDoesNotMatchRegularExpression('~width: var\(--ov-dock-w\)~', $css, 'the panel is never sized by the sum');
        // The column stands at the window's edge and clears the panel by a real gap.
        preg_match('~--ov-draw-w: (\d+)px~', $css, $draw);
        preg_match('~\.wrc-ov-edge \{[^}]*right: (\d+)px;~', $css, $right);
        $this->assertNotEmpty($draw);
        $this->assertNotEmpty($right);
        $gap = (20 + (int) $draw[1]) - ((int) $right[1] + 52);
        $this->assertGreaterThanOrEqual(12, $gap, 'the gap between the column and the panel, in pixels');
    }

    public function testTheColumnIsOnlyForTheBoardLayoutAndFoldsByHidingOnlyTheTools(): void
    {
        $css = (string) preg_replace('~/\*.*?\*/~s', '', self::source('assets/css/war-room-console-overlay.css'));
        // Hidden outside the layout with the other elements the script adds (ConsoleSkinTest checks the pair), shown inside it.
        $this->assertMatchesRegularExpression('~html\[data-wrc-skin="overlay"\] \.wrc-ov-draw,~', $css);
        // It is the first group of the right edge (ConsoleMapToolsColumnTest): the edge is placed, the column only lays its buttons out.
        $this->assertMatchesRegularExpression('~\[data-wrc-ov\] \.wrc-ov-draw \{[^}]*display: flex;[^}]*pointer-events: none;~', $css);
        $this->assertDoesNotMatchRegularExpression('~\[data-wrc-ov\] \.wrc-ov-draw \{[^}]*position: absolute;~', $css, 'placed once, by the edge: a second absolute column would sit on the map\'s tools');
        $this->assertMatchesRegularExpression('~\[data-wrc-ov\] \.wrc-ov-draw\[hidden\] \{ display: none; \}~', $css);
        // Folded: the page's toolbar is hidden by the column's own state, nothing else is.
        $this->assertMatchesRegularExpression('~\[data-wrc-ov\] \.wrc-ov-draw\[data-open="closed"\] #annotationToolbar \{ display: none; \}~', $css);
        // The tools are the squares of the board, and the armed one is lit above the page's own darker rule (an id more specific).
        $this->assertMatchesRegularExpression('~\[data-wrc-ov\] \.wrc-ov-draw \.btn,~', $css);
        $this->assertMatchesRegularExpression('~\[data-wrc-ov\] \.wrc-ov-draw #annotationToolbar > \.btn\.active \{ background: var\(--ov-org\);~', $css);
        $page = self::source('war-room.php');
        $this->assertStringContainsString('#annotationToolbar button.active { background: #1f2937;', $page, 'the page\'s rule the board has to outrank');
        // The other looks never hear of it.
        foreach (['assets/css/war-room-console-nocturne.css', 'assets/css/war-room-console-aurora.css'] as $other) {
            $this->assertStringNotContainsString('wrc-ov-draw', self::source($other));
        }
    }

    public function testNarrowerThanTheBoardTheToolbarGoesBackAndTheColumnIsGone(): void
    {
        $js = self::source('assets/js/war-room-console-overlay.js');
        // give back is the existing LIFO restore of everything take() moved: the toolbar goes back into the map's header.
        $this->assertStringContainsString('function giveBack() {', $js);
        $engage = self::between($js, 'function engage() {', 'function disengage() {');
        $this->assertStringContainsString("if (take(\$('#annotationToolbar'), drawCol)) { watchDraw(); }", $engage);
        $this->assertStringContainsString("drawCol.hidden = !\$('#annotationToolbar');", $engage, 'a viewer with no toolbar (not command) gets no empty column');
        $disengage = self::between($js, 'function disengage() {', '// ── what the page puts at the top');
        $this->assertStringContainsString('giveBack();', $disengage);
    }

    public function testTheColumnsWordsExistInBothLanguages(): void
    {
        $strings = loadLangStrings('war-room');
        $js = self::source('assets/js/war-room-console-overlay.js');
        preg_match_all("/'(console\.ov_draw[a-z_]*)'/", $js, $m);
        $used = array_values(array_unique($m[1]));
        sort($used);
        $this->assertSame(['console.ov_draw', 'console.ov_draw_armed', 'console.ov_draw_fold'], $used, 'named by their keys, never built up');
        foreach (['el', 'en'] as $lang) {
            foreach ($used as $key) {
                $this->assertArrayHasKey($key, $strings[$lang], "$key in $lang");
                $this->assertNotSame('', trim((string) $strings[$lang][$key]), "$key in $lang is empty");
            }
        }
        $this->assertStringContainsString('{tool}', $strings['el']['console.ov_draw_armed']);
        $this->assertStringContainsString('{tool}', $strings['en']['console.ov_draw_armed']);
        foreach ($used as $key) {
            $this->assertDoesNotMatchRegularExpression('~[Α-Ωα-ωάέήίόύώ]~u', (string) $strings['en'][$key], "$key in en");
        }
    }
}
