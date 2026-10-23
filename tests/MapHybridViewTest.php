<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * «Δορυφορική με ονομασίες» — the aerial photo with road and place names on it (v3.403.0).
 *
 * The satellite view is Esri World Imagery, which is a photograph and carries no
 * text: zoomed in on a town or a hillside the live map never said which street or
 * which village it was showing. The fourth view lays Esri's two free Reference
 * layers (World_Transportation: road names and house numbers, then
 * World_Boundaries_and_Places: settlements and landmarks) over the same photo.
 * It needs no key, which is the standing rule for this map — CARTO's label-only
 * tiles were the first idea and now answer "API KEY REQUIRED".
 *
 * What the labels look like was checked in a browser (Heraklion, Archanes, Zaros);
 * these pin the pieces an edit would quietly undo: the entry in the menu, the
 * order the layers are stacked in, a string for both languages, and the same
 * view on the two other pages that draw an aerial photo.
 */
final class MapHybridViewTest extends TestCase
{
    private string $warRoom;

    protected function setUp(): void
    {
        $this->warRoom = (string) file_get_contents(__DIR__ . '/../war-room.php');
    }

    private function addMapBaseLayers(): string
    {
        $start = strpos($this->warRoom, 'function addMapBaseLayers(');
        $this->assertNotFalse($start, 'addMapBaseLayers() is gone');
        $end = strpos($this->warRoom, 'let map = null,', $start);
        $this->assertNotFalse($end);
        return substr($this->warRoom, $start, $end - $start);
    }

    public function testTheMenuOffersTheHybridViewAfterTheSatelliteOne(): void
    {
        $this->assertStringContainsString("const MAP_BASE_LAYER_KEYS = ['street', 'topo', 'satellite', 'hybrid'];", $this->warRoom);
        $this->assertMatchesRegularExpression("~hybrid:\s*\{icon: 'bi-pin-map',\s*label: 'map\.btn_hybrid_view'\}~", $this->addMapBaseLayers());
    }

    public function testTheLabelsAreStackedAboveThePhotoRoadsBelowPlaces(): void
    {
        $body = $this->addMapBaseLayers();
        $this->assertStringContainsString('hybrid: L.layerGroup([', $body);
        $this->assertSame(1, preg_match('~hybrid: L\.layerGroup\(\[\s*satelliteTiles,(.*?)\]\),\s*\};~s', $body, $m), 'the hybrid group is photo + two label layers');
        $labels = $m[1];
        $roads = strpos($labels, 'World_Transportation');
        $places = strpos($labels, 'World_Boundaries_and_Places');
        $this->assertNotFalse($roads);
        $this->assertNotFalse($places);
        $this->assertLessThan($places, $roads, 'places are drawn over roads');
        $this->assertMatchesRegularExpression('~World_Transportation.*?zIndex:\s*2~s', $labels);
        $this->assertMatchesRegularExpression('~World_Boundaries_and_Places.*?zIndex:\s*3~s', $labels);
    }

    public function testThePlainSatelliteViewIsStillThePlainPhoto(): void
    {
        // Tracing a building is done on the clean photo; a label across a roof is in the way.
        $this->assertStringContainsString('satellite: satelliteTiles,', $this->addMapBaseLayers());
    }

    public function testBothLanguagesHaveTheStrings(): void
    {
        $lang = (string) file_get_contents(__DIR__ . '/../includes/lang/war-room.php');
        $this->assertSame(2, substr_count($lang, "'map.btn_hybrid_view' =>"), 'el + en');
        $this->assertSame(2, substr_count($lang, "'archive.view_hybrid' =>"), 'el + en');
    }

    public function testTheMissionArchiveMapGetsTheSameView(): void
    {
        $archive = (string) file_get_contents(__DIR__ . '/../mission-archive.php');
        $this->assertStringContainsString("'archive.view_hybrid'", $archive);
        $this->assertStringContainsString('[STR[\'archive.view_hybrid\']]: L.layerGroup([', $archive);
        $this->assertStringContainsString('World_Transportation', $archive);
        $this->assertStringContainsString('World_Boundaries_and_Places', $archive);
    }

    public function testTheMissionFormPickerShowsNamesOnItsSatelliteView(): void
    {
        // The picker turns an address into a point, so its two-state toggle
        // (street / satellite) keeps two states and the satellite one has names.
        $form = (string) file_get_contents(__DIR__ . '/../mission-form.php');
        $this->assertMatchesRegularExpression('~satellite: L\.layerGroup\(\[.*?World_Imagery.*?World_Transportation.*?World_Boundaries_and_Places.*?\]\)~s', $form);
    }
}
