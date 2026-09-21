<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * The PHP half of the shared navigation-point fixture check.
 *
 * polygonCentroid() (includes/functions-warroom.php) and polygonNavPoint()
 * (assets/js/war-room-utils.js) are the same algorithm written twice: the
 * server measures how far a team is from the shape it was sent to, the client
 * turns that same shape into the one coordinate behind its «Πλοήγηση» button.
 * If the two ever disagree, the coordinator is measuring against one spot
 * while the team is walking to another — so both are pinned to
 * tests/fixtures/nav-point-cases.json, this file asserting the PHP side and
 * tests/js/nav-point.test.js the JS side. Change one implementation without
 * the other and one of them fails in CI.
 *
 * What the algorithm SHOULD do is proved in MissionTargetDistanceTest; this
 * file only proves the two implementations still do the same thing.
 */
final class NavPointFixtureTest extends TestCase
{
    /** About 11cm. PHP and JS round the last decimal differently and no
     *  operation on the ground cares. */
    private const COORD_TOLERANCE = 1e-6;

    /** @return array<string, array{0: array<mixed>}> */
    public static function caseProvider(): array
    {
        $fixture = json_decode(file_get_contents(__DIR__ . '/fixtures/nav-point-cases.json'), true);
        self::assertIsArray($fixture, 'tests/fixtures/nav-point-cases.json is not readable JSON');

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
    public function testPolygonCentroidMatchesTheSharedFixture(array $case): void
    {
        $mid = polygonCentroid($case['geo']);
        $expect = $case['expect'];

        if ($expect['lat'] === null) {
            $this->assertNull($mid);
            return;
        }

        $this->assertNotNull($mid, 'a shape with vertices must always yield a point a team can be sent to');
        $this->assertEqualsWithDelta($expect['lat'], $mid['lat'], self::COORD_TOLERANCE, 'lat');
        $this->assertEqualsWithDelta($expect['lng'], $mid['lng'], self::COORD_TOLERANCE, 'lng');

        // The whole reason this is not a plain area-weighted centroid: for a
        // real polygon the answer has to be somewhere a team can stand.
        if ($expect['inside'] === true) {
            $this->assertTrue(
                pointInPolygon($mid['lat'], $mid['lng'], $case['geo']),
                'the navigation point for a sector must be inside the sector'
            );
        }
    }
}
