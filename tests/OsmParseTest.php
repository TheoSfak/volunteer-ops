<?php

use PHPUnit\Framework\TestCase;

/**
 * The OpenStreetMap layer's pure parts: how the map is cut into tiles, what
 * Overpass' answer is turned into, and which answers are refused.
 *
 * No database and no network, like the elevation parser's tests: the call to
 * Overpass itself is probed against the real service, because a test that
 * needs a public server to be up fails for reasons that are not this code.
 */
final class OsmParseTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../includes/osm.php';
    }

    // ── Tiles ──────────────────────────────────────────────────────────────

    public function testABoxInsideOneTileIsOneTile(): void
    {
        // Psiloritis. 35.2 / 0.05 = 704, 24.8 / 0.05 = 496.
        $tiles = osmTileKeys(35.21, 24.81, 35.24, 24.84);
        $this->assertCount(1, $tiles);
        $this->assertSame('704_496', $tiles[0][0]);
        $this->assertEqualsWithDelta(35.20, $tiles[0][1], 1e-9);
        $this->assertEqualsWithDelta(24.80, $tiles[0][2], 1e-9);
        $this->assertEqualsWithDelta(35.25, $tiles[0][3], 1e-9);
        $this->assertEqualsWithDelta(24.85, $tiles[0][4], 1e-9);
    }

    public function testABoxAcrossAnEdgeTakesBothTiles(): void
    {
        $tiles = osmTileKeys(35.21, 24.84, 35.24, 24.86);
        $this->assertSame(['704_496', '704_497'], array_column($tiles, 0));
    }

    public function testABoxTooBigOrNotABoxIsRefused(): void
    {
        // 5 x 4 = 20 tiles, past the cap of OSM_MAX_TILES.
        $this->assertNull(osmTileKeys(35.0, 24.0, 35.24, 24.19));
        $this->assertNull(osmTileKeys(35.3, 24.0, 35.2, 24.1));      // south above north
        $this->assertNull(osmTileKeys('abc', 24.0, 35.2, 24.1));
        $this->assertNull(osmTileKeys(35.2, 24.0, 95.0, 24.1));      // not on the Earth
    }

    public function testTheTilesTileTheBoxExactly(): void
    {
        $tiles = osmTileKeys(35.01, 24.01, 35.16, 24.11);
        // Rows 700..703, columns 480..482: every tile edge meets the next.
        $this->assertCount(12, $tiles);
        foreach ($tiles as [$key, $s, $w, $n, $e]) {
            $this->assertEqualsWithDelta(0.05, $n - $s, 1e-9);
            $this->assertEqualsWithDelta(0.05, $e - $w, 1e-9);
        }
    }

    // ── Queries ────────────────────────────────────────────────────────────

    public function testEveryGroupHasAQueryAndAnUnknownOneHasNot(): void
    {
        foreach (OSM_GROUPS as $group) {
            $q = osmGroupQuery($group, 35.2, 24.8, 35.25, 24.85);
            $this->assertNotNull($q, $group);
            $this->assertStringContainsString('(35.20000,24.80000,35.25000,24.85000)', $q, $group);
            $this->assertStringContainsString('[out:json]', $q);
        }
        $this->assertNull(osmGroupQuery('hotels', 35.2, 24.8, 35.25, 24.85));
        $this->assertStringContainsString('cave_entrance', osmGroupQuery('points', 35.2, 24.8, 35.25, 24.85));
        $this->assertStringContainsString('"highway"="path"', osmGroupQuery('paths', 35.2, 24.8, 35.25, 24.85));
    }

    public function testABundleIsOneQueryThatReadsEachGroupBack(): void
    {
        $this->assertSame('core', osmBundleOf('points'));
        $this->assertSame('core', osmBundleOf('paths'));
        $this->assertSame('core', osmBundleOf('cliffs'));
        $this->assertSame('tracks', osmBundleOf('tracks'));
        $this->assertNull(osmBundleOf('hotels'));

        $q = osmBundleQuery('core', 35.2, 24.8, 35.25, 24.85);
        $this->assertSame(1, substr_count($q, '[out:json]'));
        $this->assertStringContainsString('->.g0;', $q);
        $this->assertStringContainsString('->.g2;', $q);
        $this->assertStringContainsString('.g0 out center tags;', $q);   // points: a centre
        $this->assertStringContainsString('.g1 out geom tags;', $q);     // paths: the shape
        $this->assertStringNotContainsString('"highway"="track"', $q);   // dirt roads are their own query
        $this->assertNull(osmBundleQuery('nothing', 35.2, 24.8, 35.25, 24.85));
    }

    public function testABundleAnswerIsSortedIntoItsGroupsByTags(): void
    {
        $line = [['lat' => 35.2, 'lon' => 24.8], ['lat' => 35.21, 'lon' => 24.81]];
        $body = json_encode(['elements' => [
            ['type' => 'node', 'id' => 1, 'lat' => 35.2, 'lon' => 24.8, 'tags' => ['natural' => 'spring']],
            ['type' => 'way', 'id' => 2, 'geometry' => $line, 'tags' => ['highway' => 'path']],
            ['type' => 'way', 'id' => 3, 'geometry' => $line, 'tags' => ['natural' => 'cliff']],
            ['type' => 'way', 'id' => 4, 'geometry' => $line, 'tags' => ['highway' => 'track']],
            // A chapel drawn as a building is a point, whatever else it is.
            ['type' => 'way', 'id' => 5, 'center' => ['lat' => 35.2, 'lon' => 24.8], 'tags' => ['building' => 'chapel']],
        ]]);
        $all = osmParseAll($body);
        $this->assertSame([1, 5], array_column($all['points'], 'id'));
        $this->assertSame([2], array_column($all['paths'], 'id'));
        $this->assertSame([3], array_column($all['cliffs'], 'id'));
        $this->assertSame([4], array_column($all['tracks'], 'id'));
        $this->assertNull(osmParseAll('{"remark":"runtime error: out of memory","elements":[]}'));
    }

    // ── Points ─────────────────────────────────────────────────────────────

    public function testPointsAreReducedToWhatTheMapDraws(): void
    {
        $body = json_encode(['version' => 0.6, 'elements' => [
            ['type' => 'node', 'id' => 11, 'lat' => 35.2412345, 'lon' => 24.8123456,
             'tags' => ['natural' => 'cave_entrance', 'name' => 'Σπηλιά Ιδαίον', 'ele' => '1450 m']],
            ['type' => 'node', 'id' => 12, 'lat' => 35.22, 'lon' => 24.82,
             'tags' => ['natural' => 'spring', 'drinking_water' => 'yes']],
            // A way gives a centre instead of a position.
            ['type' => 'way', 'id' => 13, 'center' => ['lat' => 35.23, 'lon' => 24.83],
             'tags' => ['building' => 'chapel', 'name:en' => 'St John']],
            ['type' => 'node', 'id' => 14, 'lat' => 35.24, 'lon' => 24.84,
             'tags' => ['emergency' => 'defibrillator']],
        ]]);

        $items = osmParse($body, 'points');
        $this->assertCount(4, $items);
        $this->assertSame(['t' => 'n', 'id' => 11, 'c' => 'cave', 'lat' => 35.24123, 'lng' => 24.81235, 'n' => 'Σπηλιά Ιδαίον', 'e' => 1450], $items[0]);
        $this->assertSame('spring', $items[1]['c']);
        $this->assertSame('yes', $items[1]['x']);
        $this->assertSame(['t' => 'w', 'id' => 13, 'c' => 'chapel', 'lat' => 35.23, 'lng' => 24.83, 'n' => 'St John'], $items[2]);
        $this->assertSame('emergency', $items[3]['c']);
        $this->assertSame('defibrillator', $items[3]['x']);
    }

    public function testFeaturesTheMapHasNoUseForAreLeftOut(): void
    {
        $body = json_encode(['elements' => [
            ['type' => 'node', 'id' => 1, 'lat' => 35.2, 'lon' => 24.8, 'tags' => ['shop' => 'bakery']],  // not a kind
            ['type' => 'node', 'id' => 2, 'lat' => 35.2, 'lon' => 24.8],                                  // no tags at all
            ['type' => 'node', 'id' => 3, 'tags' => ['natural' => 'peak']],                               // nowhere
            ['type' => 'area', 'id' => 4, 'lat' => 35.2, 'lon' => 24.8, 'tags' => ['natural' => 'peak']],  // not an OSM kind
            ['type' => 'node', 'id' => 5, 'lat' => 35.2, 'lon' => 24.8, 'tags' => ['natural' => 'peak']],
        ]]);
        $items = osmParse($body, 'points');
        $this->assertCount(1, $items);
        $this->assertSame(5, $items[0]['id']);
    }

    public function testClassificationCoversEveryKindTheMenuNames(): void
    {
        $cases = [
            ['cave', ['natural' => 'cave_entrance']],
            ['hut', ['tourism' => 'alpine_hut']],
            ['hut', ['tourism' => 'wilderness_hut']],
            ['shelter', ['amenity' => 'shelter', 'shelter_type' => 'basic_hut']],
            ['spring', ['natural' => 'spring']],
            ['water', ['amenity' => 'drinking_water']],
            ['well', ['man_made' => 'water_well']],
            ['tank', ['emergency' => 'water_tank']],
            ['emergency', ['emergency' => 'phone']],
            ['emergency', ['emergency' => 'assembly_point']],
            ['chapel', ['building' => 'chapel']],
            ['peak', ['natural' => 'peak']],
            ['saddle', ['natural' => 'saddle']],
            ['helipad', ['aeroway' => 'helipad']],
            ['trailhead', ['highway' => 'trailhead']],
            ['guidepost', ['information' => 'guidepost']],
            ['guidepost', ['information' => 'board']],
        ];
        foreach ($cases as [$kind, $tags]) {
            $this->assertSame($kind, osmClassifyPoint($tags)[0], json_encode($tags));
        }
        $this->assertNull(osmClassifyPoint(['information' => 'office']));
    }

    // ── Lines ──────────────────────────────────────────────────────────────

    public function testAPathKeepsItsShapeDifficultyAndVisibility(): void
    {
        $body = json_encode(['elements' => [
            ['type' => 'way', 'id' => 99,
             'geometry' => [['lat' => 35.2, 'lon' => 24.8], ['lat' => 35.2001, 'lon' => 24.8001], ['lat' => 35.2002, 'lon' => 24.8002]],
             'tags' => ['highway' => 'path', 'sac_scale' => 'mountain_hiking', 'trail_visibility' => 'bad', 'name' => 'Ε4']],
        ]]);
        $items = osmParse($body, 'paths');
        $this->assertCount(1, $items);
        $this->assertSame('path', $items[0]['c']);
        $this->assertSame('mountain_hiking', $items[0]['s']);
        $this->assertSame('bad', $items[0]['v']);
        $this->assertSame('Ε4', $items[0]['n']);
        $this->assertSame([35.2, 24.8], $items[0]['p'][0]);
        $this->assertSame([35.2002, 24.8002], end($items[0]['p']));
    }

    public function testTracksAndCliffsGetTheirOwnCategoryAndNoPathFields(): void
    {
        $way = fn(array $tags) => ['type' => 'way', 'id' => 5, 'tags' => $tags,
            'geometry' => [['lat' => 35.2, 'lon' => 24.8], ['lat' => 35.21, 'lon' => 24.81]]];
        $track = osmParse(json_encode(['elements' => [$way(['highway' => 'track', 'sac_scale' => 'hiking'])]]), 'tracks');
        $cliff = osmParse(json_encode(['elements' => [$way(['natural' => 'cliff'])]]), 'cliffs');
        $this->assertSame('track', $track[0]['c']);
        $this->assertArrayNotHasKey('s', $track[0]);
        $this->assertSame('cliff', $cliff[0]['c']);
    }

    public function testALineIsThinnedButKeepsItsEnd(): void
    {
        // Ten points 1 m apart (0.00001°): the run collapses to a few, and the
        // last one is always kept so the line still reaches where it ends.
        $geometry = [];
        for ($i = 0; $i < 10; $i++) $geometry[] = ['lat' => 35.2 + $i * 0.00001, 'lon' => 24.8];
        $thin = osmThin($geometry);
        $this->assertLessThan(10, count($thin));
        $this->assertSame([35.2, 24.8], $thin[0]);
        $this->assertSame([35.20009, 24.8], end($thin));

        // Points far enough apart all stay.
        $apart = [['lat' => 35.2, 'lon' => 24.8], ['lat' => 35.21, 'lon' => 24.8], ['lat' => 35.22, 'lon' => 24.8]];
        $this->assertCount(3, osmThin($apart));
    }

    public function testALineWithOnePointIsNotALine(): void
    {
        $body = json_encode(['elements' => [
            ['type' => 'way', 'id' => 7, 'geometry' => [['lat' => 35.2, 'lon' => 24.8]], 'tags' => ['highway' => 'path']],
        ]]);
        $this->assertSame([], osmParse($body, 'paths'));
    }

    // ── Answers that are not answers ───────────────────────────────────────

    public function testAnAnswerThatIsNotJsonOrRanOutOfTimeIsRefused(): void
    {
        $this->assertNull(osmParse(null, 'points'));
        $this->assertNull(osmParse('', 'points'));
        $this->assertNull(osmParse('<html>Too many requests</html>', 'points'));
        $this->assertNull(osmParse('{"version":0.6}', 'points'));

        // Overpass answers 200 with part of the data and a remark when it runs
        // out of time; keeping that for a month would be a hillside with holes.
        $partial = json_encode(['elements' => [['type' => 'node', 'id' => 1, 'lat' => 35.2, 'lon' => 24.8, 'tags' => ['natural' => 'peak']]],
            'remark' => 'runtime error: Query timed out in "query" at line 1 after 26 seconds.']);
        $this->assertNull(osmParse($partial, 'points'));

        // An empty tile is a real answer: nothing there.
        $this->assertSame([], osmParse('{"elements":[]}', 'points'));
    }

    public function testNamesAndElevationsAreCleanedUp(): void
    {
        $this->assertSame('', osmName([]));
        $this->assertSame(80, mb_strlen(osmName(['name' => str_repeat('α', 200)])));
        $this->assertSame('Ιδαίον', osmName(['name:el' => 'Ιδαίον']));
        $this->assertSame(1450, osmElevation(['ele' => '1450']));
        $this->assertSame(1450, osmElevation(['ele' => '1449.6 m']));
        $this->assertSame(-3, osmElevation(['ele' => '-3']));
        $this->assertNull(osmElevation(['ele' => 'high']));
        $this->assertNull(osmElevation([]));
    }
}
