<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * What the OpenStreetMap cache tells the page about a tile (includes/osm.php,
 * osmTileBundle): which answers it may treat as finished and which it must ask
 * for again.
 *
 * The one that matters: after a failed refresh the cache keeps an empty "try
 * again later" row. If that were reported as an ordinary fresh answer the page
 * would take it for "nothing on this hillside" and never ask again until it was
 * reloaded — one slow moment at Overpass, and a tile blank for the whole search.
 *
 * Budget 0 throughout: these never reach for Overpass. Runs inside a
 * transaction that is always rolled back.
 */
final class OsmCacheStateTest extends TestCase
{
    private const TILE = 'zz_test';

    protected function setUp(): void
    {
        require_once __DIR__ . '/../includes/osm.php';
        db()->beginTransaction();
    }

    protected function tearDown(): void
    {
        db()->rollBack();
    }

    /** Rows for the three groups of the core bundle, $age seconds old; points hold $items. */
    private function seed(int $age, array $items): void
    {
        foreach (OSM_BUNDLES['core'] as $group) {
            $held = $group === 'points' ? $items : [];
            dbExecute(
                "INSERT INTO osm_feature_cache (tile_key, layer_group, payload, element_count, fetched_at)
                 VALUES (?, ?, ?, ?, DATE_SUB(NOW(), INTERVAL $age SECOND))",
                [self::TILE, $group, json_encode($held), count($held)]
            );
        }
    }

    /** [state, number of points] as the page would be told, with no Overpass call allowed. */
    private function ask(): array
    {
        $budget = 0;
        [$byGroup, $state] = osmTileBundle(self::TILE, 'core', 0.0, 0.0, 0.05, 0.05, $budget);
        return [$state, count($byGroup['points'])];
    }

    private const PEAK = [['t' => 'n', 'id' => 1, 'c' => 'peak', 'lat' => 1.0, 'lng' => 1.0]];

    public function testARecentAnswerIsFinishedWhetherOrNotItHoldsAnything(): void
    {
        $this->seed(0, self::PEAK);
        $this->assertSame(['fresh', 1], $this->ask());

        dbExecute("DELETE FROM osm_feature_cache WHERE tile_key = ?", [self::TILE]);
        $this->seed(0, []);   // a hillside nobody has mapped is a real answer
        $this->assertSame(['fresh', 0], $this->ask());
    }

    public function testTheRetryMarkerIsNotAFinishedAnswer(): void
    {
        // The marker osmCacheRetryLater() leaves: aged to just short of expiry.
        $this->seed(OSM_CACHE_TTL - OSM_RETRY_AFTER + 30, []);
        $this->assertSame(['failed', 0], $this->ask());

        // Over an older real answer it still serves what it has, and still says ask again.
        dbExecute("DELETE FROM osm_feature_cache WHERE tile_key = ?", [self::TILE]);
        $this->seed(OSM_CACHE_TTL - OSM_RETRY_AFTER + 30, self::PEAK);
        $this->assertSame(['failed', 1], $this->ask());
    }

    public function testAnExpiredAnswerWithNoFetchAllowedIsServedOrPending(): void
    {
        $this->seed(OSM_CACHE_TTL + 100, self::PEAK);
        $this->assertSame(['stale', 1], $this->ask());   // old but real: draw it

        // An expired empty row is only ever a spent marker: nothing known.
        dbExecute("DELETE FROM osm_feature_cache WHERE tile_key = ?", [self::TILE]);
        $this->seed(OSM_CACHE_TTL + 100, []);
        $this->assertSame(['pending', 0], $this->ask());
    }

    public function testARowOfTilesIsAnsweredTileByTile(): void
    {
        // Three tiles side by side: one with an answer, one in its retry window,
        // one never seen. With no fetch allowed each gets its own state.
        foreach (['zz_1' => [0, self::PEAK], 'zz_2' => [OSM_CACHE_TTL - OSM_RETRY_AFTER + 30, []]] as $key => [$age, $items]) {
            foreach (OSM_BUNDLES['core'] as $group) {
                $held = $group === 'points' ? $items : [];
                dbExecute(
                    "INSERT INTO osm_feature_cache (tile_key, layer_group, payload, element_count, fetched_at)
                     VALUES (?, ?, ?, ?, DATE_SUB(NOW(), INTERVAL $age SECOND))",
                    [$key, $group, json_encode($held), count($held)]
                );
            }
        }
        $budget = 0;
        [$byTile, $states] = osmChunkBundle(
            [['zz_1', 0.0, 0.0, 0.05, 0.05], ['zz_2', 0.0, 0.05, 0.05, 0.10], ['zz_3', 0.0, 0.10, 0.05, 0.15]],
            'core', $budget
        );
        $this->assertSame(['zz_1' => 'fresh', 'zz_2' => 'failed', 'zz_3' => 'pending'], $states);
        $this->assertCount(1, $byTile['zz_1']['points']);
        $this->assertSame([], $byTile['zz_3']['points']);
    }

    public function testATileNeverSeenIsPending(): void
    {
        $this->assertSame(['pending', 0], $this->ask());
    }
}
