<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * buildSectorGridCells() (includes/functions-warroom.php) — the pure geometry
 * behind mission-sector.php's generate_grid action.
 *
 * These are behavioural assertions: they prove the grid is right, not that it
 * still matches whatever it produced last time. The companion
 * SectorGridFixtureTest reads tests/fixtures/grid-cases.json to prove the PHP
 * and JS implementations still agree with each other cell for cell; both are
 * needed, and neither replaces the other.
 *
 * The squareness test deliberately measures cells with gpsDistanceMeters()
 * (Haversine, spherical) rather than the flat local projection the function
 * under test uses — an independent second opinion. A grid built naively in
 * degrees passes every self-consistent check and still comes out ~20% oblong
 * in Crete; only an outside measurement catches that.
 */
final class SectorGridTest extends TestCase
{
    /**
     * A rectangle on the Psiloritis massif, the same ground the mountain-
     * rescue demo simulation uses. 0.02 deg of latitude by 0.04 deg of
     * longitude: about 2226m by 3637m, deliberately NOT a whole number of
     * 500m cells in either direction.
     */
    private const CRETE_RECT = [
        [35.240, 24.750],
        [35.240, 24.790],
        [35.220, 24.790],
        [35.220, 24.750],
    ];

    /** Same bounding box, but only the southern half of it is really inside. */
    private const CRETE_TRIANGLE = [
        [35.240, 24.750],
        [35.240, 24.790],
        [35.220, 24.770],
    ];

    public function testCellsAreSquareOnTheGroundNotObloingInDegrees(): void
    {
        $grid = buildSectorGridCells(self::CRETE_RECT, 500);

        $this->assertNotEmpty($grid['cells']);
        [$nw, $ne, $se] = $grid['cells'][0];

        $widthMeters  = gpsDistanceMeters($nw[0], $nw[1], $ne[0], $ne[1]);
        $heightMeters = gpsDistanceMeters($ne[0], $ne[1], $se[0], $se[1]);

        $ratio = $widthMeters / $heightMeters;
        $this->assertGreaterThan(0.95, $ratio, "cell is {$widthMeters}m x {$heightMeters}m — too oblong");
        $this->assertLessThan(1.05, $ratio, "cell is {$widthMeters}m x {$heightMeters}m — too oblong");
    }

    public function testRequestedSizeIsARequestAndTheActualCellIsAlwaysSmaller(): void
    {
        $grid = buildSectorGridCells(self::CRETE_RECT, 500);

        $this->assertSame(500, $grid['requested_m']);
        $this->assertLessThan(500, $grid['actual_w_m']);
        $this->assertLessThan(500, $grid['actual_h_m']);

        // 3637m / 8 columns and 2226m / 5 rows. Whole cells, covering the
        // whole bounding box, which is the entire point of rounding the count
        // up rather than the size down.
        $this->assertSame(8, $grid['cols']);
        $this->assertSame(5, $grid['rows']);
        $this->assertEqualsWithDelta(454.7, $grid['actual_w_m'], 1.0);
        $this->assertEqualsWithDelta(445.3, $grid['actual_h_m'], 1.0);
    }

    public function testEveryCellOfARectangularAreaIsKept(): void
    {
        $grid = buildSectorGridCells(self::CRETE_RECT, 500);

        $this->assertSame(40, $grid['total']);
        $this->assertSame(40, $grid['kept']);
        $this->assertCount(40, $grid['cells']);
    }

    public function testCellsOutsideTheAreaAreDroppedAndEveryKeptCellIsCentredInside(): void
    {
        $grid = buildSectorGridCells(self::CRETE_TRIANGLE, 500);

        $this->assertGreaterThan(0, $grid['kept']);
        $this->assertLessThan($grid['total'], $grid['kept'], 'a triangle cannot fill its own bounding box');

        foreach ($grid['cells'] as $i => $cell) {
            [$nw, , $se] = $cell;
            $centreLat = ($nw[0] + $se[0]) / 2;
            $centreLng = ($nw[1] + $se[1]) / 2;
            $this->assertTrue(
                pointInPolygon($centreLat, $centreLng, self::CRETE_TRIANGLE),
                "cell $i was kept but its centre is outside the area"
            );
        }
    }

    public function testCellsComeBackInReadingOrderNorthToSouthThenWestToEast(): void
    {
        $grid = buildSectorGridCells(self::CRETE_RECT, 500);
        $cols = $grid['cols'];

        // First cell is the north-west corner of the bounding box.
        $this->assertSame(35.24, $grid['cells'][0][0][0]);
        $this->assertSame(24.75, $grid['cells'][0][0][1]);

        // Second cell is its eastern neighbour on the same row...
        $this->assertSame($grid['cells'][0][0][0], $grid['cells'][1][0][0]);
        $this->assertGreaterThan($grid['cells'][0][0][1], $grid['cells'][1][0][1]);

        // ...and the first cell of the second row has dropped south.
        $this->assertLessThan($grid['cells'][0][0][0], $grid['cells'][$cols][0][0]);
    }

    public function testNeighbouringCellsShareTheirEdgesExactly(): void
    {
        $grid = buildSectorGridCells(self::CRETE_RECT, 500);
        $cols = $grid['cols'];

        // Rounding each corner independently could leave hairline gaps or
        // overlaps between neighbours; identical shared corners prove it does
        // not, which is what makes "the grid covers the area" true.
        [, $ne, $se] = $grid['cells'][0];
        [$rightNw, , , $rightSw] = $grid['cells'][1];
        $this->assertSame($ne, $rightNw, 'east edge of a cell must be the west edge of the next');
        $this->assertSame($se, $rightSw, 'east edge of a cell must be the west edge of the next');

        [, , $firstSe, $firstSw] = $grid['cells'][0];
        [$belowNw, $belowNe] = $grid['cells'][$cols];
        $this->assertSame($firstSw, $belowNw, 'south edge of a cell must be the north edge of the one below');
        $this->assertSame($firstSe, $belowNe, 'south edge of a cell must be the north edge of the one below');
    }

    public function testSizeIsClampedToTheAcceptedRange(): void
    {
        // 20m cells over a 5km area is the case the brief calls "the point
        // where the feature turns on you" — the clamp is the first of the two
        // guards against it, MAX_GRID_CELLS in the endpoint is the second.
        $this->assertSame(GRID_SECTOR_SIZE_MIN_M, buildSectorGridCells(self::CRETE_RECT, 20)['requested_m']);
        $this->assertSame(GRID_SECTOR_SIZE_MAX_M, buildSectorGridCells(self::CRETE_RECT, 99999)['requested_m']);
        $this->assertSame(GRID_SECTOR_SIZE_MIN_M, buildSectorGridCells(self::CRETE_RECT, -1)['requested_m']);
    }

    public function testAnAreaSmallerThanOneCellStillYieldsExactlyOneCell(): void
    {
        $grid = buildSectorGridCells(self::CRETE_RECT, 2000);

        $this->assertSame(2, $grid['cols']);
        $this->assertSame(2, $grid['rows']);
        $this->assertSame(4, $grid['kept']);
    }

    public function testDegenerateAreaYieldsNoCellsInsteadOfDividingByZero(): void
    {
        $sameP = [[35.24, 24.75], [35.24, 24.75], [35.24, 24.75]];
        $grid = buildSectorGridCells($sameP, 500);

        $this->assertSame(1, $grid['cols']);
        $this->assertSame(1, $grid['rows']);
        $this->assertSame(0, $grid['kept']);
        $this->assertSame([], $grid['cells']);
        $this->assertSame(0.0, $grid['actual_w_m']);
    }

    public function testCoordinatesAreRoundedToSixDecimals(): void
    {
        // 7 cells across an odd span forces repeating decimals, so nothing
        // here is accidentally exact.
        $grid = buildSectorGridCells([
            [35.2412345678, 24.7512345678],
            [35.2412345678, 24.7912345678],
            [35.2212345678, 24.7912345678],
            [35.2212345678, 24.7512345678],
        ], 550);

        foreach ($grid['cells'] as $cell) {
            foreach ($cell as [$lat, $lng]) {
                $this->assertSame(round($lat, 6), $lat);
                $this->assertSame(round($lng, 6), $lng);
            }
        }
    }
}
