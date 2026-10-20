<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Two things taken off the live map for everybody who is not command (v3.389.2).
 *
 * 1. The layers button at the top of the map. Its menu (map view, which shapes to
 *    show, pin labels, OpenStreetMap data) is a command tool, so the button is
 *    rendered only for accounts that can manage the Action Room — admin accounts
 *    since v3.389.1. Everything that reads the button looks it up with a guard,
 *    so a page served without it just never builds the menu.
 * 2. The red name of a search AREA. Only the sectors inside it are named on the
 *    map; the area is still named in its popup and the areas list.
 *
 * What is actually on screen was measured in a browser; these pin the pieces a
 * later edit would quietly undo.
 */
final class MapLayersAdminOnlyTest extends TestCase
{
    private string $source;

    protected function setUp(): void
    {
        $this->source = (string) file_get_contents(__DIR__ . '/../war-room.php');
    }

    /** The body of a top-level function in war-room.php's script. */
    private function functionBody(string $signature): string
    {
        $start = strpos($this->source, $signature);
        $this->assertNotFalse($start, "$signature is gone");
        // The working copy is CRLF, the repository LF: a top-level function ends
        // at a "}" in column 0 either way.
        $this->assertSame(1, preg_match('~\r?\n\}\r?\n~', $this->source, $m, PREG_OFFSET_CAPTURE, $start), "$signature never closes");
        return substr($this->source, $start, $m[0][1] - $start);
    }

    public function testTheLayersButtonIsRenderedOnlyForAccountsThatCanManage(): void
    {
        $this->assertMatchesRegularExpression(
            // The title attribute holds a PHP close tag, so [^>]* would stop short: .*? it is.
            '~<\?php if \(\$canManageWarRoom\): \?>\s*<!--(?:(?!-->).)*-->\s*<button type="button" id="mapSatelliteToggle".*?<i class="bi bi-layers"></i>\s*</button>\s*<\?php endif; \?>~s',
            $this->source,
            'the layers button must sit inside the $canManageWarRoom gate'
        );
        $this->assertSame(1, substr_count($this->source, 'id="mapSatelliteToggle"'), 'one button, one gate');
    }

    public function testEveryReaderOfTheButtonToleratesItBeingAbsent(): void
    {
        // addMapBaseLayers(map, id) takes the base layer from storage and only
        // builds the menu when the button exists.
        $this->assertStringContainsString('const btn = toggleBtnId ? document.getElementById(toggleBtnId) : null;', $this->source);
        $this->assertStringContainsString('if (btn) {', $this->functionBody('function addMapBaseLayers('));
        // The three menu builders bail out when there is no button / menu.
        foreach (['(function addMapOverlayToggles() {', '(function addPinTeamLabelToggle() {', '(function addOsmLayer() {'] as $builder) {
            $start = strpos($this->source, $builder);
            $this->assertNotFalse($start, $builder);
            $head = substr($this->source, $start, 700);
            $this->assertStringContainsString("document.getElementById('mapSatelliteToggle')", $head, $builder);
            $this->assertStringContainsString('btn?.parentNode', $head, "$builder must use ?. on the button");
            $this->assertMatchesRegularExpression('~if \(!(?:mapEl \|\| !)?menu\)~', $head, "$builder must return when there is no menu");
        }
        // The only other touch is optional-chained.
        $this->assertStringContainsString("document.getElementById('mapSatelliteToggle')?.classList.toggle('wr-overlays-hidden'", $this->source);
    }

    public function testAnAreaHasNoPermanentNameOnTheMap(): void
    {
        $area = $this->functionBody('function renderAreaLayer(items) {');
        $this->assertStringNotContainsString('bindTieredPolygonLabel', $area);
        $this->assertStringNotContainsString('areaLabelAnchor', $area);
        $this->assertStringNotContainsString('bindTooltip', $area);
        $this->assertStringNotContainsString('areaLabelAnchor', $this->source, 'the anchor helper had no other caller');
    }

    public function testTheNameIsStillOnTheAreaPopupAndSectorsAndHazardsKeepTheirs(): void
    {
        $area = $this->functionBody('function renderAreaLayer(items) {');
        $this->assertStringContainsString('<strong>${escapeHtml(item.label)}</strong>', $area, 'the popup still names the area');
        $this->assertStringContainsString("markMapPickable(layer, {kind: 'area'", $area, '«Τι υπάρχει εδώ;» still names it');

        $sectors = $this->functionBody('function renderSectorLayer(');
        $this->assertStringContainsString("bindTieredPolygonLabel(layer, item.label, item.geo, 'wr-polygon-label'", $sectors, 'sectors stay named');
        $restricted = $this->functionBody('function renderRestrictedAreaLayer(items) {');
        $this->assertStringContainsString('wr-polygon-label wr-polygon-label-restricted', $restricted, 'a hazard must never lose its name');
    }
}
