<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Downloading all of Crete's OpenStreetMap points ahead of time
 * (includes/osm-bulk.php): how the island is cut into chunks, which chunk is
 * next, and when a job is finished. Never reaches Overpass: the tests only meet
 * a chunk that needs nothing, or look at the pure choices. Runs inside a
 * transaction that is always rolled back.
 */
final class OsmBulkTest extends TestCase
{
    protected function setUp(): void
    {
        require_once __DIR__ . '/../includes/osm-bulk.php';
        db()->beginTransaction();
    }

    protected function tearDown(): void
    {
        db()->rollBack();
    }

    public function testTheIslandIsCutIntoRowsOfAtMostFourTiles(): void
    {
        $chunks = osmBulkChunks();
        $tiles = 0;
        foreach ($chunks as $i => $chunk) {
            $this->assertSame($i, $chunk['index']);
            $this->assertLessThanOrEqual(OSM_CHUNK_TILES, count($chunk['tiles']));
            $rows = array_unique(array_map(fn($t) => explode('_', $t[0])[0], $chunk['tiles']));
            $this->assertCount(1, $rows, 'a chunk is one row of tiles');
            $tiles += count($chunk['tiles']);
        }
        // Crete's land: 458 of the 1,200 tiles of its box, 18 rows, in chunks of four.
        $this->assertSame(458, $tiles);
        $this->assertCount(133, $chunks);
        $this->assertSame('696_480', $chunks[0]['tiles'][0][0]);
        $last = end($chunks)['tiles']; $this->assertSame('713_475', end($last)[0]);
    }

    public function testAChunkNeedsFetchingUntilEveryTileHoldsRowsNewerThanTheJob(): void
    {
        $chunk = ['index' => 0, 'tiles' => [['1_1', 0, 0, 0, 0], ['1_2', 0, 0, 0, 0]]];
        $all = fn(int $age) => ['points' => $age, 'paths' => $age, 'cliffs' => $age];

        $this->assertTrue(osmBulkChunkNeeds($chunk, [], 600), 'nothing stored');
        $this->assertTrue(osmBulkChunkNeeds($chunk, ['1_1' => $all(10)], 600), 'one tile missing');
        $this->assertTrue(osmBulkChunkNeeds($chunk, ['1_1' => $all(10), '1_2' => ['points' => 10, 'paths' => 10]], 600), 'a group missing');
        $this->assertTrue(osmBulkChunkNeeds($chunk, ['1_1' => $all(10), '1_2' => $all(5000)], 600), 'one tile older than the job');
        $this->assertFalse(osmBulkChunkNeeds($chunk, ['1_1' => $all(10), '1_2' => $all(599)], 600), 'all fetched since the job began');
    }

    public function testTheNextChunkIsTheFirstOneStillMissingAtOrAfterTheCursor(): void
    {
        $chunks = [];
        for ($i = 0; $i < 4; $i++) $chunks[] = ['index' => $i, 'tiles' => [["t$i", 0, 0, 0, 0]]];
        $fresh = ['points' => 5, 'paths' => 5, 'cliffs' => 5];
        $stale = ['points' => 9999, 'paths' => 9999, 'cliffs' => 9999];
        $ages = ['t0' => $fresh, 't1' => $stale, 't2' => $fresh, 't3' => $stale];

        $this->assertSame(1, osmBulkNextIndex($chunks, $ages, 600, 0));
        $this->assertSame(3, osmBulkNextIndex($chunks, $ages, 600, 2));
        $this->assertNull(osmBulkNextIndex($chunks, $ages, 600, 4));
        $this->assertSame(2, osmBulkRemaining($chunks, $ages, 600));
        // Chunk 1 failed earlier and is behind the cursor: still counted, so a
        // second pass from 0 comes back for it.
        $this->assertNull(osmBulkNextIndex($chunks, ['t0' => $fresh, 't1' => $stale, 't2' => $fresh, 't3' => $fresh], 600, 2));
        $this->assertSame(1, osmBulkRemaining($chunks, ['t0' => $fresh, 't1' => $stale, 't2' => $fresh, 't3' => $fresh], 600));
    }

    public function testStoredTilesIgnoreTheRetryMarkerOfAFailedFetch(): void
    {
        $chunks = [['index' => 0, 'tiles' => [['a', 0, 0, 0, 0], ['b', 0, 0, 0, 0], ['c', 0, 0, 0, 0]]]];
        $real = ['points' => 100, 'paths' => 100, 'cliffs' => 100];
        $marker = ['points' => OSM_CACHE_TTL - OSM_RETRY_AFTER + 10, 'paths' => OSM_CACHE_TTL - OSM_RETRY_AFTER + 10, 'cliffs' => OSM_CACHE_TTL - OSM_RETRY_AFTER + 10];
        $this->assertSame(1, osmBulkStoredTiles($chunks, ['a' => $real, 'b' => $marker]));
        // … unless the marker sits on a tile that holds features: a refresh that
        // failed leaves them, and the tile is still stored.
        $this->assertSame(2, osmBulkStoredTiles($chunks, ['a' => $real, 'b' => $marker], ['b' => 12]));
        $this->assertSame(1, osmBulkStoredTiles($chunks, ['a' => $real, 'b' => $marker, 'c' => ['points' => 5]], ['c' => 99]), 'a tile with a group missing is not stored');
    }

    public function testAForcedRefreshFetchesWhatTheCacheWouldStillTrust(): void
    {
        $budget = 0;
        $why = null;
        dbExecute(
            "INSERT INTO osm_feature_cache (tile_key, layer_group, payload, element_count, fetched_at)
             VALUES ('zz_9', 'points', '[{\"t\":\"n\",\"id\":1,\"c\":\"peak\",\"lat\":1,\"lng\":1}]', 1, DATE_SUB(NOW(), INTERVAL 100 SECOND)),
                    ('zz_9', 'paths', '[]', 0, DATE_SUB(NOW(), INTERVAL 100 SECOND)),
                    ('zz_9', 'cliffs', '[]', 0, DATE_SUB(NOW(), INTERVAL 100 SECOND))"
        );
        $tile = [['zz_9', 0.0, 0.0, 0.05, 0.05]];

        // Trusted by the cache (a year), so nothing to do …
        [, $states] = osmChunkBundle($tile, 'core', $budget);
        $this->assertSame('fresh', $states['zz_9']);
        // … but older than a job begun 50 s ago: it wants fetching, and with no
        // fetch allowed it is served as it is, as "stale".
        [$byTile, $states] = osmChunkBundle($tile, 'core', $budget, $why, 50);
        $this->assertSame('stale', $states['zz_9']);
        $this->assertCount(1, $byTile['zz_9']['points'], 'the old answer keeps being served');
        // A job begun 500 s ago has nothing to redo here.
        [, $states] = osmChunkBundle($tile, 'core', $budget, $why, 500);
        $this->assertSame('fresh', $states['zz_9']);
    }

    public function testAJobWithNothingLeftFinishesAndRecordsIt(): void
    {
        // Every land tile of the island holds fresh rows: the step has nothing to ask Overpass for.
        $values = [];
        foreach (osmBulkChunks() as $chunk) {
            foreach ($chunk['tiles'] as $tile) {
                foreach (OSM_BUNDLES['core'] as $group) $values[] = "('" . $tile[0] . "', '$group', '[]', 0, NOW())";
            }
        }
        foreach (array_chunk($values, 500) as $batch) {
            dbExecute("INSERT INTO osm_feature_cache (tile_key, layer_group, payload, element_count, fetched_at) VALUES " . implode(',', $batch));
        }
        osmBulkSet(OSM_BULK_STARTED, osmBulkNow() - 30);

        $status = osmBulkStatus();
        $this->assertSame(458, $status['stored_tiles']);
        $this->assertSame(0, $status['job_remaining']);

        $step = osmBulkStep(0);
        $this->assertTrue($step['ok']);
        $this->assertTrue($step['finished']);
        $this->assertSame(0, $step['remaining']);
        $this->assertNotNull(osmBulkGet(OSM_BULK_COMPLETED));
    }

    public function testAStepWithoutAJobIsRefused(): void
    {
        dbExecute("DELETE FROM settings WHERE setting_key = ?", [OSM_BULK_STARTED]);
        $this->assertFalse(osmBulkStep(0)['ok']);
    }
}
