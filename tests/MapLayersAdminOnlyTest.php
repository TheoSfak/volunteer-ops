<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Two things taken off the live map for everybody who is not command (v3.389.2).
 *
 * 1. The layers button at the top of the map. Its menu has the map view (street,
 *    topographic, satellite, satellite with names) and, for command only, which
 *    shapes to show, pin labels and OpenStreetMap data. v3.389.2 took the whole
 *    button away from everybody else; v3.408.1 gave back the map-view choice
 *    (guests and mission visitors asked for it) and kept the rest — and the
 *    «Εδώ υπάρχουν N — επιλέξτε» list of the layers under a tap — command's.
 *    Everything that reads the button looks it up with a guard.
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

    /**
     * v3.408.1: the button itself is back for everybody (guests, mission visitors,
     * volunteers) so each can choose the map view - street, topographic, satellite,
     * satellite with names. What stays command's is the rest of the menu.
     */
    public function testTheMapViewButtonIsRenderedForEverybody(): void
    {
        $this->assertSame(1, substr_count($this->source, 'id="mapSatelliteToggle"'), 'one button');
        $this->assertMatchesRegularExpression(
            // The title attribute holds a PHP close tag, so [^>]* would stop short: .*? it is.
            '~<!--(?:(?!-->).)*-->\s*<button type="button" id="mapSatelliteToggle".*?<i class="bi bi-layers"></i>\s*</button>~s',
            $this->source
        );
        // …not wrapped in the command gate any more: the nearest PHP tag before it closes something else.
        $before = substr($this->source, 0, strpos($this->source, 'id="mapSatelliteToggle"'));
        $lastTag = max(strrpos($before, '<?php if ($canManageWarRoom): ?>'), strrpos($before, '<?php endif; ?>'));
        $this->assertSame('<?php endif; ?>', substr($before, $lastTag, 15), 'the button must not sit inside a $canManageWarRoom block');

        // The map-view list is built by addMapBaseLayers for any page that has the button.
        $base = $this->functionBody('function addMapBaseLayers(');
        $this->assertStringContainsString("const MAP_BASE_LAYER_KEYS = ['street', 'topo', 'satellite', 'hybrid'];", $this->source, 'the four views');
        $this->assertStringNotContainsString('MAP_LAYER_TOOLS_ENABLED', $base, 'choosing the map view is not a command tool');
    }

    public function testTheRestOfTheMenuIsBuiltOnlyForCommand(): void
    {
        $this->assertStringContainsString('const MAP_LAYER_TOOLS_ENABLED = <?= json_encode($canManageWarRoom) ?>;', $this->source);
        $this->assertSame(1, substr_count($this->source, 'const MAP_LAYER_TOOLS_ENABLED'));
        // Each builder of the menu's extra sections stops for anyone else, before it touches the menu.
        $overlays = substr($this->source, strpos($this->source, '(function addMapOverlayToggles() {'), 2600);
        $this->assertNotFalse(strpos($overlays, 'if (!MAP_LAYER_TOOLS_ENABLED) return;'));
        $this->assertLessThan(
            strpos($overlays, 'MAP_OVERLAYS.forEach(o => {'),
            strpos($overlays, 'if (!MAP_LAYER_TOOLS_ENABLED) return;'),
            'the overlay switches are added only after the command check'
        );
        $this->assertStringContainsString(
            "(function addPinTeamLabelToggle() {\n    if (!MAP_LAYER_TOOLS_ENABLED) return;",
            str_replace("\r\n", "\n", $this->source)
        );
        $this->assertStringContainsString('(function addOsmLayer() {', $this->source);
        $this->assertStringContainsString('if (!MAP_LAYER_TOOLS_ENABLED || !OSM_LAYER_ENABLED || !osmCluster) return;', $this->source);
        // The map card still lets the (shorter) view menu hang out for everybody.
        $head = substr($this->source, strpos($this->source, '(function addMapOverlayToggles() {'), 1800);
        $this->assertLessThan(
            strpos($head, 'if (!MAP_LAYER_TOOLS_ENABLED) return;'),
            strpos($head, "menuCard?.addEventListener('show.bs.dropdown'")
        );
        // A hidden-shapes state is restored only for the people who could have set it.
        $this->assertStringContainsString("if (MAP_LAYER_TOOLS_ENABLED) JSON.parse(sessionStorage.getItem(MAP_OVERLAYS_KEY)", $this->source);
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

    /**
     * v3.408.1: the «Εδώ υπάρχουν N — επιλέξτε» list, which names every layer under a
     * tapped point, is a command tool as well. A guest saw it although the layers
     * button was already gone.
     */
    public function testTheLayerPickerOpensOnlyForCommand(): void
    {
        $this->assertStringContainsString(
            'const MAP_LAYER_TOOLS_ENABLED = <?= json_encode($canManageWarRoom) ?>;',
            $this->source,
            'the picker flag comes from the command gate'
        );
        $mark = $this->functionBody('function markMapPickable(layer, info) {');
        $this->assertStringContainsString('if (!MAP_LAYER_TOOLS_ENABLED) return layer;', $mark);
        // The early return comes first: nothing may be tagged or re-bound before it.
        $this->assertLessThan(strpos($mark, 'layer._wrPick = info;'), strpos($mark, 'if (!MAP_LAYER_TOOLS_ENABLED) return layer;'));
        $this->assertLessThan(strpos($mark, "layer.on('click', mapPickClick);"), strpos($mark, 'if (!MAP_LAYER_TOOLS_ENABLED) return layer;'));

        // The one entry point to the list is that click handler: nothing else opens it.
        $this->assertSame(1, substr_count($this->source, 'openMapPickList(e.latlng, hits);'), 'a second way into the list would bypass the gate');
        $this->assertSame(2, substr_count($this->source, 'openMapPickList('), 'the call and the definition');
        $this->assertSame(1, substr_count($this->source, "layer.on('click', mapPickClick);"));

        // And a flag declared before any shape is drawn: a const read earlier would throw.
        $this->assertLessThan(strpos($this->source, 'function renderAreaLayer(items) {'), strpos($this->source, 'const MAP_LAYER_TOOLS_ENABLED'));
    }
}
