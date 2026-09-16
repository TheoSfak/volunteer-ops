<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * The PHP half of the shared sector-grid fixture check.
 *
 * buildSectorGridCells() (includes/functions-warroom.php) and
 * gridCellsForPolygon() (assets/js/war-room-utils.js) are the same algorithm
 * written twice: the server creates the sectors, the client previews them and
 * tells the coordinator "Create 13 sectors" before anything exists. If the
 * two ever disagree, that button lies during a callout — so both are pinned
 * to tests/fixtures/grid-cases.json, this file asserting the PHP side and
 * tests/js/sector-grid.test.js the JS side. Change one implementation without
 * the other and one of the two fails in CI.
 *
 * What the algorithm SHOULD do is proved in SectorGridTest; this file only
 * proves the two implementations still do the same thing.
 */
final class SectorGridFixtureTest extends TestCase
{
    /**
     * PHP rounds halves away from zero, JS rounds them toward +Infinity, and
     * the two languages' cos() can differ in the last bit — so coordinates
     * are compared at 1e-6 degrees, about 11cm, far inside GPS error. Counts
     * are compared exactly: they are what the UI says out loud.
     */
    private const COORD_TOLERANCE = 1e-6;

    /** @return array<string, array{0: array<mixed>}> */
    public static function caseProvider(): array
    {
        $fixture = json_decode(file_get_contents(__DIR__ . '/fixtures/grid-cases.json'), true);
        self::assertIsArray($fixture, 'tests/fixtures/grid-cases.json is not readable JSON');

        $cases = [];
        foreach ($fixture['cases'] as $case) {
            $cases[$case['name']] = [$case];
        }
        return $cases;
    }

    /**
     * @param array<mixed> $case
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('caseProvider')]
    public function testPhpMatchesTheSharedFixture(array $case): void
    {
        $grid = buildSectorGridCells($case['geo'], $case['size_m']);
        $expect = $case['expect'];

        $this->assertSame($expect['cols'], $grid['cols'], 'cols');
        $this->assertSame($expect['rows'], $grid['rows'], 'rows');
        $this->assertSame($expect['total'], $grid['total'], 'total');
        $this->assertSame($expect['kept'], $grid['kept'], 'kept');
        $this->assertSame($expect['requested_m'], $grid['requested_m'], 'requested_m');
        $this->assertEqualsWithDelta($expect['actual_w_m'], $grid['actual_w_m'], 0.1, 'actual_w_m');
        $this->assertEqualsWithDelta($expect['actual_h_m'], $grid['actual_h_m'], 0.1, 'actual_h_m');

        // Kept and dropped are both compared: the preview draws the dropped
        // ones dashed, so "where the grid is" has to agree across languages
        // even for the cells nobody is going to search.
        foreach (['cells', 'dropped'] as $set) {
            $this->assertCount(count($expect[$set]), $grid[$set], "$set count");
            foreach ($expect[$set] as $i => $expectedCell) {
                foreach ($expectedCell as $corner => [$lat, $lng]) {
                    $this->assertEqualsWithDelta($lat, $grid[$set][$i][$corner][0], self::COORD_TOLERANCE, "$set $i corner $corner lat");
                    $this->assertEqualsWithDelta($lng, $grid[$set][$i][$corner][1], self::COORD_TOLERANCE, "$set $i corner $corner lng");
                }
            }
        }
        $this->assertSame(
            $grid['total'],
            count($grid['cells']) + count($grid['dropped']),
            'every cell of the grid must be either kept or dropped, never neither'
        );
    }
}
