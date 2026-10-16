<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The live map's layers menu in two columns (v3.388.0).
 *
 * With the OpenStreetMap section the menu had 28 rows in one column: 1126px
 * against a 520px map, and 1109px on a phone, wider than the phone's screen.
 * The map's card is overflow:hidden, so whatever hung below the card's bottom
 * edge was cut off and could not be reached at all. The menu is now what is
 * drawn (map views, shapes, labels) on the left and the OpenStreetMap data on
 * the right, 498px tall on a desktop and 674px on a phone, and the card lets
 * it out while it is open.
 *
 * Layout cannot be measured from PHPUnit — that was done in a browser at three
 * widths — so these pin the pieces the layout depends on, in the places a later
 * edit would break them: a second divider put back in front of the OSM heading,
 * the class added where the OSM section does not exist, the card's overflow
 * rule dropped, the section order changed so a section lands in the wrong
 * column.
 */
final class MapLayersMenuTest extends TestCase
{
    private string $source;

    protected function setUp(): void
    {
        $this->source = (string) file_get_contents(__DIR__ . '/../war-room.php');
    }

    /** The body of the addOsmLayer() IIFE: where the OpenStreetMap section is added to the menu. */
    private function osmSection(): string
    {
        $start = strpos($this->source, '(function addOsmLayer() {');
        $this->assertNotFalse($start, 'addOsmLayer() is gone');
        $end = strpos($this->source, "\n})();", $start);
        $this->assertNotFalse($end);
        return substr($this->source, $start, $end - $start);
    }

    public function testTheMenuIsTwoColumnsWithTheBreakOnTheOsmHeading(): void
    {
        $this->assertMatchesRegularExpression('~\.dropdown-menu\.wr-layers-2col\.show\s*\{[^}]*column-count:\s*2~', $this->source);
        $this->assertMatchesRegularExpression('~\.wr-layers-2col\s*>\s*li\.wr-col-break\s*\{\s*break-before:\s*column;~', $this->source);
        $this->assertMatchesRegularExpression('~\.wr-layers-2col\s*>\s*li\s*\{\s*break-inside:\s*avoid;~', $this->source, 'a row must never be cut across the two columns');

        $osm = $this->osmSection();
        $this->assertStringContainsString("menu.classList.add('wr-layers-2col')", $osm);
        $this->assertStringContainsString("header.className = 'wr-col-break'", $osm);
    }

    public function testNoDividerIsLeftInFrontOfTheSecondColumn(): void
    {
        // The divider that used to separate the two sections would now sit at the
        // top of the right-hand column, under nothing.
        $this->assertStringNotContainsString('dropdown-divider', $this->osmSection());
    }

    public function testTheColumnsAreOnlyUsedWhenTheOsmSectionExists(): void
    {
        // Without the OSM layer (kill switch) the menu is the 13 rows it always
        // was; balancing those across two columns would cut a section in half.
        $this->assertSame(1, substr_count($this->source, "classList.add('wr-layers-2col')"));
        $this->assertGreaterThan(
            (int) strpos($this->source, '(function addOsmLayer() {'),
            (int) strpos($this->source, "classList.add('wr-layers-2col')")
        );
    }

    public function testTheSectionsAreBuiltInTheOrderThatPutsThemInTheRightColumn(): void
    {
        // Left column: views, then shapes, then the pin-label switch. Right: OSM.
        $views = strpos($this->source, 'function addMapBaseLayers(');
        $shapes = strpos($this->source, '(function addMapOverlayToggles() {');
        $labels = strpos($this->source, '(function addPinTeamLabelToggle() {');
        $osm = strpos($this->source, '(function addOsmLayer() {');
        foreach ([$views, $shapes, $labels, $osm] as $position) {
            $this->assertNotFalse($position);
        }
        $this->assertTrue($views < $shapes && $shapes < $labels && $labels < $osm);
    }

    public function testTheCardLetsTheOpenMenuOutOfItsOverflowHidden(): void
    {
        $this->assertMatchesRegularExpression('~\.card\.wr-menu-open\s*\{\s*overflow:\s*visible;~', $this->source);
        $this->assertStringContainsString("classList.add('wr-menu-open')", $this->source);
        $this->assertStringContainsString("classList.remove('wr-menu-open')", $this->source);
        $this->assertStringContainsString("addEventListener('show.bs.dropdown'", $this->source);
        $this->assertStringContainsString("addEventListener('hidden.bs.dropdown'", $this->source);
    }

    public function testTheWidthFollowsTheCardAndNeverPassesTheCap(): void
    {
        $osm = $this->osmSection();
        $this->assertStringContainsString("btn.addEventListener('show.bs.dropdown'", $osm);
        $this->assertMatchesRegularExpression('~Math\.min\(672,\s*room\s*-\s*16\)~', $osm, '42rem is 672px');
        $this->assertMatchesRegularExpression('~\.dropdown-menu\.wr-layers-2col\.show\s*\{[^}]*width:\s*42rem;[^}]*max-width:\s*calc\(100vw\s*-\s*1rem\)~', $this->source);
    }

    public function testTheMenuStaysAboveTheMapsOwnControlsAndTextWraps(): void
    {
        // Leaflet's controls and the OSM chip are z-index 1000 and were painted over the menu.
        $this->assertMatchesRegularExpression('~\.dropdown-menu\.wr-layers-2col\.show\s*\{[^}]*z-index:\s*1100~', $this->source);
        $this->assertMatchesRegularExpression('~\.wr-layers-2col\s+\.dropdown-item\s*\{[^}]*white-space:\s*normal~', $this->source);
        $this->assertMatchesRegularExpression('~\.wr-layers-2col\s+\.dropdown-header\s*\{\s*white-space:\s*normal;~', $this->source);
    }

    public function testThePhoneGetsTighterRows(): void
    {
        $this->assertMatchesRegularExpression('~@media\s*\(max-width:\s*575\.98px\)\s*\{[^}]*\.wr-layers-2col\s+\.dropdown-item\s*\{[^}]*padding:\s*\.35rem\s+\.5rem~s', $this->source);
    }

    public function testEveryRowStillHasItsHandlers(): void
    {
        // The menu is restyled, not rebuilt: the code that reads the ticks and
        // the picked view still finds the same attributes.
        foreach (['data-layer', 'data-overlay', 'data-pin-team-labels', 'data-osm-master', 'data-osm'] as $hook) {
            $this->assertStringContainsString($hook, $this->source, $hook);
        }
        $this->assertStringContainsString("'data-bs-auto-close', 'outside'", $this->source, 'ticking a box must not close the menu');
    }
}
