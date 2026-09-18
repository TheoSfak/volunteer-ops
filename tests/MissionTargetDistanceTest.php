<?php

use PHPUnit\Framework\TestCase;

/**
 * How far somebody is from where they were sent.
 *
 * Everything here is pure: the geometry that reduces a sector or a route to
 * one point, the wording that reports a distance, and the guard that catches a
 * router going round by road. No database and no network — the outbound call
 * itself is exercised by the scratchpad probe against real data, because a
 * test that needs OSRM to be up is a test that fails for reasons that have
 * nothing to do with this code.
 */
final class MissionTargetDistanceTest extends TestCase
{
    // ── The middle of a shape ──────────────────────────────────────────────

    public function testTheMiddleOfASimpleSectorIsItsCentre(): void
    {
        $square = [[0, 0], [0, 10], [10, 10], [10, 0]];
        $mid = polygonCentroid($square);

        $this->assertEqualsWithDelta(5.0, $mid['lat'], 0.0001);
        $this->assertEqualsWithDelta(5.0, $mid['lng'], 0.0001);
    }

    public function testTheMiddleOfAConcaveSectorIsInsideIt(): void
    {
        // A sector traced around a gorge is concave, and the centroid of a
        // concave shape can sit outside it — the middle of a horseshoe is not
        // in the horseshoe. Sent to a team as "the middle of your sector",
        // such a point is on the wrong side of a ridge from every part of the
        // ground they were given.
        $horseshoe = [[0, 0], [0, 10], [2, 10], [2, 2], [8, 2], [8, 10], [10, 10], [10, 0]];
        $mid = polygonCentroid($horseshoe);

        $this->assertTrue(
            pointInPolygon($mid['lat'], $mid['lng'], $horseshoe),
            'the middle of a sector must be somewhere a team can actually stand'
        );

        // And the plain area-weighted centroid, which is what a first cut
        // reaches for, genuinely is outside — so the test above is not passing
        // by accident.
        $this->assertFalse(pointInPolygon(5.0, 4.077, $horseshoe));
    }

    public function testDegenerateShapesStillYieldAPointRatherThanNothing(): void
    {
        // A target nobody can locate is worse than no target, but a two-point
        // "polygon" still has a usable middle and losing it would drop a real
        // assignment off the board.
        $this->assertNull(polygonCentroid([]));
        $this->assertSame(['lat' => 3.0, 'lng' => 4.0], polygonCentroid([[3, 4]]));
        $this->assertEqualsWithDelta(5.0, polygonCentroid([[0, 0], [10, 0]])['lat'], 0.0001);
        // Every vertex on one line: no area, but the mean is still its middle.
        $collinear = polygonCentroid([[0, 0], [5, 0], [10, 0]]);
        $this->assertEqualsWithDelta(5.0, $collinear['lat'], 0.0001);
    }

    public function testRubbishCoordinatesAreIgnoredRatherThanCounted(): void
    {
        $mid = polygonCentroid([[0, 0], ['x', 'y'], [0, 10], null, [10, 10], [10, 0]]);
        $this->assertEqualsWithDelta(5.0, $mid['lat'], 0.0001);
    }

    // ── Reading a stored target ────────────────────────────────────────────

    public function testAStoredPointAndAStoredRingAreBothUnderstood(): void
    {
        // The two shapes this app stores, told apart by their own structure
        // rather than by a type column that a caller has to remember to pass.
        $point = missionTargetPointFromGeo('{"lat":35.1464,"lng":24.9159}');
        $this->assertEqualsWithDelta(35.1464, $point['lat'], 0.0001);

        $ring = missionTargetPointFromGeo('[[0,0],[0,10],[10,10],[10,0]]');
        $this->assertEqualsWithDelta(5.0, $ring['lat'], 0.0001);

        $this->assertNull(missionTargetPointFromGeo('not json'));
        $this->assertNull(missionTargetPointFromGeo('[]'));
    }

    public function testATeamTargetBeatsTheMissionWideOne(): void
    {
        $targets = [
            0  => ['kind' => 'point',  'label' => 'Όλοι', 'lat' => 1.0, 'lng' => 1.0, 'ts' => 10, 'detail' => null],
            40 => ['kind' => 'sector', 'label' => 'Τομέας Α', 'lat' => 2.0, 'lng' => 2.0, 'ts' => 5, 'detail' => null],
        ];

        // Even though the mission-wide one is NEWER: a team that was given its
        // own ground is not also being sent to the general point.
        $this->assertSame('Τομέας Α', missionTargetForTeam($targets, 40)['label']);
        // Somebody on no team, and somebody on a team with nothing of its own,
        // both fall back to the mission-wide target.
        $this->assertSame('Όλοι', missionTargetForTeam($targets, null)['label']);
        $this->assertSame('Όλοι', missionTargetForTeam($targets, 99)['label']);
        $this->assertNull(missionTargetForTeam([], 40));
    }

    // ── How the distance is said ───────────────────────────────────────────

    public function testTheStraightLineIsAlwaysThereAndAlwaysSaysSo(): void
    {
        // It never fails, it needs no network, and off-road it is the only one
        // of the two that is true.
        $this->assertSame('850 μ σε ευθεία ΒΑ', aiLiveDistanceToTargetWords(850, 'ΒΑ', null));
        $this->assertSame('3.4 χλμ σε ευθεία Ν', aiLiveDistanceToTargetWords(3400, 'Ν', null));
    }

    public function testARoutedFigureIsLabelledByHowItWasTravelled(): void
    {
        // "8,4 χλμ driving" and "8,4 χλμ walking" are different facts about
        // the same two points, and which router answered decides which it is.
        $driving = aiLiveDistanceToTargetWords(3000, 'Β', ['meters' => 4300, 'minutes' => 12, 'mode' => 'driving']);
        $this->assertStringContainsString('4.3 χλμ οδικώς', $driving);
        $this->assertStringContainsString('12 λεπτά', $driving);
        $this->assertStringContainsString('3 χλμ σε ευθεία Β', $driving);

        $walking = aiLiveDistanceToTargetWords(3000, 'Β', ['meters' => 3600, 'minutes' => 47, 'mode' => 'walking']);
        $this->assertStringContainsString('3.6 χλμ με τα πόδια', $walking);
    }

    public function testARouterThatWentRoundTheMountainIsFlaggedNotJustStated(): void
    {
        // Measured on a real Psiloritis mission: a crew 6,2 km from their
        // sector came back as 70 km by road, because the router snapped both
        // ends to asphalt and went round the whole massif. Stated flatly, that
        // is the kind of number that sends a vehicle on a two-hour drive to
        // reach people an hour's walk away.
        $absurd = aiLiveDistanceToTargetWords(6200, 'ΝΑ', ['meters' => 70000, 'minutes' => 139, 'mode' => 'driving']);
        $this->assertStringContainsString('70 χλμ οδικώς', $absurd, 'the real figure is kept');
        $this->assertStringContainsString('ΠΡΟΣΟΧΗ', $absurd, 'and is labelled for what it is');

        // A genuine road detour round a valley runs two to three times the
        // straight line and must NOT be flagged, or the warning becomes noise
        // that gets ignored on the one occasion it matters.
        $ordinary = aiLiveDistanceToTargetWords(5800, 'ΝΑ', ['meters' => 18700, 'minutes' => 126, 'mode' => 'driving']);
        $this->assertStringNotContainsString('ΠΡΟΣΟΧΗ', $ordinary);
    }

    public function testShortLegsAreNeverFlaggedBecauseTheyAreAllDetour(): void
    {
        // Two hundred metres apart across a one-way street is legitimately a
        // kilometre by road. The ratio only means something once the straight
        // line is long enough to have a shape.
        $this->assertFalse(aiLiveRouteIsDetour(200, 1500));
        $this->assertTrue(aiLiveRouteIsDetour(6200, 70000));
        $this->assertFalse(aiLiveRouteIsDetour(5800, 18700));
    }

    public function testAMissingDurationIsLeftOutRatherThanPrintedAsZero(): void
    {
        $words = aiLiveDistanceToTargetWords(3000, 'Β', ['meters' => 4300, 'minutes' => 0, 'mode' => 'driving']);
        $this->assertStringNotContainsString('0 λεπτά', $words);
        $this->assertStringContainsString('4.3 χλμ οδικώς', $words);
    }

    // ── Which router is in use ─────────────────────────────────────────────

    public function testTheProviderIsChosenByWhetherAKeyExists(): void
    {
        // One less setting to reason about: an organisation that does not want
        // Google used simply does not store a key — the same rule the AI
        // provider chain follows.
        $this->assertSame('osrm', routeDistanceProviderFor('')['name']);
        $this->assertSame('driving', routeDistanceProviderFor('   ')['mode']);
        $this->assertSame('google', routeDistanceProviderFor('AIza-something')['name']);
        $this->assertSame('walking', routeDistanceProviderFor('AIza-something')['mode']);
    }

    public function testGoogleIsAskedForAWalkingRouteAndForNothingElse(): void
    {
        $body = json_decode(routeDistanceGoogleBody(35.1464, 24.9159, 35.1960, 24.9271), true);

        // WALK, not DRIVE. The whole reason for supporting Google at all is
        // that a search team is on foot and the free router cannot say so.
        $this->assertSame('WALK', $body['travelMode']);
        $this->assertSame(35.1464, $body['origin']['location']['latLng']['latitude']);
        $this->assertSame(24.9159, $body['origin']['location']['latLng']['longitude']);
        $this->assertSame(35.1960, $body['destination']['location']['latLng']['latitude']);

        // COORDINATES AND NOTHING ELSE. The router is asked how far between two
        // spots and must not be able to tell who is standing on either — that
        // is what makes this path allowed to carry real positions at all, when
        // the AI digest is forbidden them.
        $this->assertSame(['origin', 'destination', 'travelMode'], array_keys($body));
    }

    public function testEachRoutersAnswerIsReadCorrectlyAndRubbishIsRefused(): void
    {
        $osrm = routeDistanceParse('osrm', '{"code":"Ok","routes":[{"distance":4321.6,"duration":780}]}');
        $this->assertSame(4322, $osrm['meters']);
        $this->assertSame(13, $osrm['minutes']);

        // Google states a duration as the string "780s".
        $google = routeDistanceParse('google', '{"routes":[{"distanceMeters":4322,"duration":"780s"}]}');
        $this->assertSame(4322, $google['meters']);
        $this->assertSame(13, $google['minutes']);

        // A quietly wrong distance is worse than an absent one: the absent one
        // still leaves the straight line on screen and says that is what it is.
        foreach (['', 'not json', '{}', '{"code":"NoRoute","routes":[]}', '{"routes":[{}]}'] as $junk) {
            $this->assertNull(routeDistanceParse('osrm', $junk), "osrm: {$junk}");
            $this->assertNull(routeDistanceParse('google', $junk), "google: {$junk}");
        }
        $this->assertNull(routeDistanceParse('osrm', null));
    }
}
