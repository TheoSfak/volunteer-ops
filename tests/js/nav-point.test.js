// Run with: node --test tests/js
//
// The JS half of the shared navigation-point fixture check.
//
// polygonNavPoint() (assets/js/war-room-utils.js) and polygonCentroid()
// (includes/functions-warroom.php) are the same algorithm written twice: the
// server measures how far a team is from the shape it was sent to, this side
// turns that same shape into the one coordinate behind its «Πλοήγηση» button.
// If the two ever disagree, the coordinator is measuring against one spot
// while the team is walking to another — so both are pinned to
// tests/fixtures/nav-point-cases.json, this file asserting the JS side and
// tests/NavPointFixtureTest.php the PHP side.

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const { polygonNavPoint, pointInPolygon } = require('../../assets/js/war-room-utils.js');

const fixture = JSON.parse(
    fs.readFileSync(path.join(__dirname, '../fixtures/nav-point-cases.json'), 'utf8')
);

// About 11cm — PHP and JS round the last decimal differently and no operation
// on the ground cares.
const COORD_TOLERANCE = 1e-6;

test('polygonNavPoint matches the shared fixture', async (t) => {
    for (const c of fixture.cases) {
        await t.test(c.name, () => {
            const mid = polygonNavPoint(c.geo);

            if (c.expect.lat === null) {
                assert.equal(mid, null);
                return;
            }

            assert.ok(mid, 'a shape with vertices must always yield a point a team can be sent to');
            assert.ok(Math.abs(mid.lat - c.expect.lat) <= COORD_TOLERANCE, `lat ${mid.lat} vs ${c.expect.lat}`);
            assert.ok(Math.abs(mid.lng - c.expect.lng) <= COORD_TOLERANCE, `lng ${mid.lng} vs ${c.expect.lng}`);

            // The whole reason this is not a plain area-weighted centroid: for
            // a real polygon the answer has to be somewhere a team can stand.
            if (c.expect.inside === true) {
                assert.ok(
                    pointInPolygon(mid.lat, mid.lng, c.geo),
                    'the navigation point for a sector must be inside the sector'
                );
            }
        });
    }
});

test('the naive vertex mean this replaces really would send a team outside a horseshoe', () => {
    // Not decoration. dispatchDirectionsUrl() averaged the vertices, and this
    // pins down that the shape in the fixture genuinely defeats that approach —
    // otherwise the test above could pass for the wrong reason.
    const horseshoe = fixture.cases.find(c => c.name.includes('horseshoe'));
    assert.ok(horseshoe, 'fixture no longer has a horseshoe case');

    const n = horseshoe.geo.length;
    const meanLat = horseshoe.geo.reduce((a, p) => a + p[0], 0) / n;
    const meanLng = horseshoe.geo.reduce((a, p) => a + p[1], 0) / n;

    assert.equal(
        pointInPolygon(meanLat, meanLng, horseshoe.geo),
        false,
        'the vertex mean should land in the notch — if it does not, this case no longer proves anything'
    );
    assert.ok(pointInPolygon(polygonNavPoint(horseshoe.geo).lat, polygonNavPoint(horseshoe.geo).lng, horseshoe.geo));
});
