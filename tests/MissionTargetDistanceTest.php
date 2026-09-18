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

    public function testEveryPlaceSomebodyWasSentIsReportedNotJustTheLatest(): void
    {
        // Reported from the field as "it works for a point and not for a
        // sector". A team legitimately holds a sector AND a rendezvous point
        // at once; keeping only the newest threw one away silently, and since
        // dispatch points were read first, a tie on the second-granularity
        // timestamp handed it to the point as well. The coordinator had just
        // assigned a sector and the assistant went on measuring to a point
        // sent minutes earlier.
        $targets = [
            0  => [['kind' => 'point',  'label' => 'Όλοι',     'lat' => 1.0, 'lng' => 1.0, 'ts' => 10, 'detail' => null]],
            40 => [['kind' => 'sector', 'label' => 'Τομέας Α', 'lat' => 2.0, 'lng' => 2.0, 'ts' => 5,  'detail' => null],
                   ['kind' => 'point',  'label' => 'Ραντεβού', 'lat' => 3.0, 'lng' => 3.0, 'ts' => 5,  'detail' => null]],
        ];

        $forTeam = missionTargetsForTeam($targets, 40);
        $this->assertSame(['Τομέας Α', 'Ραντεβού', 'Όλοι'], array_column($forTeam, 'label'));

        // Team assignments come before the mission-wide one even when the
        // mission-wide one is newer: an instruction to YOUR team is more yours
        // than a broadcast. But the broadcast is still reported, which is the
        // part that used to be missing.
        $this->assertSame('Τομέας Α', $forTeam[0]['label']);

        // Somebody on no team, and on a team with nothing of its own, still
        // get what was addressed to everybody.
        $this->assertSame(['Όλοι'], array_column(missionTargetsForTeam($targets, null), 'label'));
        $this->assertSame(['Όλοι'], array_column(missionTargetsForTeam($targets, 99), 'label'));
        $this->assertSame([], missionTargetsForTeam([], 40));
    }

    public function testTheListOfTargetsIsCappedSoItStaysAnOrderNotATable(): void
    {
        $many = [40 => []];
        foreach (range(1, 8) as $i) {
            $many[40][] = ['kind' => 'point', 'label' => 'Σημείο ' . $i, 'lat' => 1.0, 'lng' => 1.0, 'ts' => $i, 'detail' => null];
        }
        $this->assertCount(MISSION_TARGET_CAP, missionTargetsForTeam($many, 40));
    }

    // ── How the distance is said ───────────────────────────────────────────

    public function testTheStraightLineIsAlwaysThereAndAlwaysSaysSo(): void
    {
        // It never fails, it needs no network, and off-road it is the only one
        // of the two that is true.
        $this->assertSame('850 μ σε ευθεία ΒΑ', aiLiveDistanceToTargetWords(850, 'ΒΑ', null));
        $this->assertSame('3.4 χλμ σε ευθεία Ν', aiLiveDistanceToTargetWords(3400, 'Ν', null));
    }

    public function testBothWaysOfGettingThereAreNamed(): void
    {
        // The coordinator is CHOOSING between them: on foot is what is true in
        // this terrain, by vehicle is what is faster when a road happens to go
        // the right way. Reporting one makes the other invisible, and the
        // choice is the decision being made.
        $both = aiLiveDistanceToTargetWords(3000, 'Β', [
            'walking' => ['meters' => 3600, 'minutes' => 47],
            'driving' => ['meters' => 4300, 'minutes' => 12],
        ], true);

        $this->assertStringContainsString('3 χλμ σε ευθεία Β', $both);
        $this->assertStringContainsString('με τα πόδια 3.6 χλμ, 47 λεπτά', $both);
        $this->assertStringContainsString('με αμάξι 4.3 χλμ, 12 λεπτά', $both);
        // On foot first: it is the one that holds in this terrain.
        $this->assertLessThan(mb_strpos($both, 'με αμάξι'), mb_strpos($both, 'με τα πόδια'));
    }

    public function testAMissingWalkingTimeIsExplainedRatherThanLeftAsAGap(): void
    {
        // Google WALK finds nothing on a mountain because the paths are not
        // mapped — which is NOT the same as walking being impossible, and the
        // difference decides whether somebody is sent on foot. Reported from a
        // live mission as "the Google key must not be working".
        $tried = aiLiveDistanceToTargetWords(1500, 'ΒΑ', [
            'walking' => null,
            'driving' => ['meters' => 2400, 'minutes' => 6],
            'walk_tried' => true,
        ], true);

        $this->assertStringContainsString('με αμάξι 2.4 χλμ, 6 λεπτά', $tried);
        $this->assertStringNotContainsString('με τα πόδια', $tried);
        $this->assertStringContainsString(AI_LIVE_ROUTE_NO_WALK_MARK, $tried, 'the absence must be marked');

        // With no Google key none is ever requested, so saying "no walking
        // time" on every row would be reporting the absence of something
        // nobody looked for — eight copies of a non-problem.
        $notTried = aiLiveDistanceToTargetWords(1500, 'ΒΑ', [
            'walking' => null,
            'driving' => ['meters' => 2400, 'minutes' => 6],
            'walk_tried' => false,
        ], true);
        $this->assertStringNotContainsString(AI_LIVE_ROUTE_NO_WALK_MARK, $notTried);
    }

    public function testTheExplanationIsGivenOncePerSectionNotOncePerPerson(): void
    {
        // Eight copies of the same sentence is a paragraph of the digest spent
        // saying one thing, and it reads as eight separate problems rather
        // than one fact about the terrain.
        $marked = ['στοχοι' => [['τι' => 'Τομέας Α', 'αποσταση' => '1.5 χλμ σε ευθεία ΒΑ — με αμάξι 2.4 χλμ ' . AI_LIVE_ROUTE_NO_WALK_MARK]]];
        $plain  = ['στοχοι' => [['τι' => 'Τομέας Β', 'αποσταση' => '1.5 χλμ σε ευθεία ΒΑ — με αμάξι 2.4 χλμ, 6 λεπτά']]];

        $this->assertTrue(aiLiveAnyMissingWalk([$plain, $marked]));
        $this->assertFalse(aiLiveAnyMissingWalk([$plain, $plain]));
        $this->assertFalse(aiLiveAnyMissingWalk([]));
        // A row with no distance at all must not trip it.
        $this->assertFalse(aiLiveAnyMissingWalk([['ονομα' => 'ΜΕΛΟΣ-1']]));
    }

    public function testARouteThatWasLookedForAndNotFoundSaysSo(): void
    {
        // The exact complaint from the field: a bare straight line, with no
        // way to tell whether a routed figure had even been attempted. A
        // coordinator with a paid key reasonably concluded it was broken.
        $attempted = aiLiveDistanceToTargetWords(1500, 'ΒΑ', null, true);
        $this->assertStringContainsString('δεν βρέθηκε διαδρομή', $attempted);

        // And when nothing was asked for, nothing is claimed.
        $notAttempted = aiLiveDistanceToTargetWords(1500, 'ΒΑ', null, false);
        $this->assertSame('1.5 χλμ σε ευθεία ΒΑ', $notAttempted);
    }

    public function testAStaleFixStillGetsItsDistanceWithTheAgeWeldedOn(): void
    {
        // Reported from the field: with a stale fix the assistant reported no
        // distance at all. The figure was in the digest the whole time — the
        // model saw «σιωπηλος: true» two fields away and declined to state a
        // distance from a position it judged unreliable. Defensible caution,
        // useless answer: "he was 1,5 km out forty minutes ago" is something a
        // coordinator can act on; "I cannot say" is not.
        //
        // Welding the caveat to the number means it cannot be reported without
        // the caveat, and the caveat cannot be used as a reason to report
        // nothing.
        $stale = aiLiveDistanceToTargetWords(1500, 'ΒΑ', [
            'walking' => null,
            'driving' => ['meters' => 2400, 'minutes' => 6],
        ], true, 40);

        $this->assertStringContainsString('1.5 χλμ σε ευθεία ΒΑ', $stale, 'the distance is still stated');
        $this->assertStringContainsString('40 λεπτά', $stale, 'and its age travels with it');
        $this->assertStringContainsString('με αμάξι 2.4 χλμ', $stale, 'as does the routed figure');

        // A fresh fix needs no apology; attaching an age to every distance
        // would bury the one case that matters.
        $fresh = aiLiveDistanceToTargetWords(1500, 'ΒΑ', null, false, null);
        $this->assertSame('1.5 χλμ σε ευθεία ΒΑ', $fresh);
    }

    public function testAnAgeIsSaidInAUnitSomebodyWouldActuallyUse(): void
    {
        // "43339 λεπτά" reads as a typo, not as a month, and a coordinator
        // skims past the one caveat that mattered.
        $this->assertSame('40 λεπτά', aiLiveAgeWords(40));
        $this->assertSame('89 λεπτά', aiLiveAgeWords(89));
        $this->assertSame('2 ώρες', aiLiveAgeWords(90));
        $this->assertSame('30 ημέρες', aiLiveAgeWords(43339));
    }

    public function testARouterThatWentRoundTheMountainIsFlaggedNotJustStated(): void
    {
        // Measured on a real Psiloritis mission: a crew 6,2 km from their
        // sector came back as 70 km by road, because the router snapped both
        // ends to asphalt and went round the whole massif. Stated flatly, that
        // is the kind of number that sends a vehicle on a two-hour drive to
        // reach people an hour's walk away.
        $absurd = aiLiveDistanceToTargetWords(6200, 'ΝΑ', [
            'walking' => null,
            'driving' => ['meters' => 70000, 'minutes' => 139],
        ], true);
        $this->assertStringContainsString('με αμάξι 70 χλμ', $absurd, 'the real figure is kept');
        $this->assertStringContainsString('ΠΡΟΣΟΧΗ', $absurd, 'and is labelled for what it is');

        // A genuine road detour round a valley runs two to three times the
        // straight line and must NOT be flagged, or the warning becomes noise
        // that gets ignored on the one occasion it matters.
        $ordinary = aiLiveDistanceToTargetWords(5800, 'ΝΑ', [
            'walking' => null,
            'driving' => ['meters' => 18700, 'minutes' => 126],
        ], true);
        $this->assertStringNotContainsString('ΠΡΟΣΟΧΗ', $ordinary);

        // The warning belongs to the DRIVING figure. On foot the long way
        // round is not what anybody would do anyway.
        $walkFine = aiLiveDistanceToTargetWords(6200, 'ΝΑ', [
            'walking' => ['meters' => 7100, 'minutes' => 95],
            'driving' => null,
        ], true);
        $this->assertStringNotContainsString('ΠΡΟΣΟΧΗ', $walkFine);
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
        $words = aiLiveDistanceToTargetWords(3000, 'Β', [
            'walking' => ['meters' => 3600, 'minutes' => 0],
            'driving' => ['meters' => 4300, 'minutes' => 0],
        ], true);
        $this->assertStringNotContainsString('0 λεπτά', $words);
        $this->assertStringContainsString('με τα πόδια 3.6 χλμ', $words);
        $this->assertStringContainsString('με αμάξι 4.3 χλμ', $words);
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

    public function testTheProvidersOwnComplaintReachesTheAdminIntact(): void
    {
        // The sentence that names the actual problem is Google's, not ours —
        // a key restricted to the wrong referrer, the Routes API never enabled
        // on the project, billing not set up. Whoever pressed the button is
        // the person who can go and fix it, so it is passed through rather
        // than replaced with something reassuring. Verified against the real
        // API with a deliberately invalid key, which answers exactly this.
        $real = '{"error":{"code":400,"message":"API key not valid. Please pass a valid API key.","status":"INVALID_ARGUMENT"}}';
        $this->assertSame(
            'INVALID_ARGUMENT: API key not valid. Please pass a valid API key.',
            routeDistanceErrorText('google', $real)
        );

        $denied = '{"error":{"code":403,"message":"Routes API has not been used in project 123 before.","status":"PERMISSION_DENIED"}}';
        $this->assertStringContainsString('PERMISSION_DENIED', routeDistanceErrorText('google', $denied));
        $this->assertStringContainsString('has not been used', routeDistanceErrorText('google', $denied));

        $this->assertStringContainsString('NoRoute', routeDistanceErrorText('osrm', '{"code":"NoRoute","message":"no route found"}'));
    }

    public function testAPageOfHtmlFromSomethingInTheWayIsNotDumpedOnTheAdmin(): void
    {
        // A proxy, a captive portal or a 502 page answers with markup, and
        // pasting it into a Bootstrap alert would fill the settings page with
        // somebody else's website.
        $html = '<html><head><title>502</title></head><body><h1>Bad Gateway</h1>' . str_repeat('x', 500) . '</body></html>';
        $text = routeDistanceErrorText('google', $html);

        $this->assertStringNotContainsString('<', $text);
        $this->assertLessThanOrEqual(200, mb_strlen($text));
        $this->assertStringContainsString('Bad Gateway', $text);
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
