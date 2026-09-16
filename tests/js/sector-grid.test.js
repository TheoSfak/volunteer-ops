// Run with: node --test tests/js
//
// The JS half of the shared sector-grid fixture check.
//
// gridCellsForPolygon() (assets/js/war-room-utils.js) and
// buildSectorGridCells() (includes/functions-warroom.php) are the same
// algorithm written twice: the server creates the sectors, this side previews
// them and tells the coordinator "Create 13 sectors" before anything exists.
// If the two ever disagree, that button lies during a callout — so both are
// pinned to tests/fixtures/grid-cases.json, this file asserting the JS side
// and tests/SectorGridFixtureTest.php the PHP side. Change one implementation
// without the other and one of the two fails in CI.

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const { gridCellsForPolygon, pointInPolygon } = require('../../assets/js/war-room-utils.js');

const fixture = JSON.parse(
    fs.readFileSync(path.join(__dirname, '../fixtures/grid-cases.json'), 'utf8')
);

// PHP rounds halves away from zero, JS rounds them toward +Infinity, and the
// two languages' cos() can differ in the last bit — so coordinates are
// compared at 1e-6 degrees, about 11cm, far inside GPS error. Counts are
// compared exactly: they are what the UI says out loud.
const COORD_TOLERANCE = 1e-6;

test('gridCellsForPolygon matches the shared fixture', async (t) => {
    for (const c of fixture.cases) {
        await t.test(c.name, () => {
            const grid = gridCellsForPolygon(c.geo, c.size_m);
            const expect = c.expect;

            assert.equal(grid.cols, expect.cols, 'cols');
            assert.equal(grid.rows, expect.rows, 'rows');
            assert.equal(grid.total, expect.total, 'total');
            assert.equal(grid.kept, expect.kept, 'kept');
            assert.equal(grid.requested_m, expect.requested_m, 'requested_m');
            assert.ok(Math.abs(grid.actual_w_m - expect.actual_w_m) <= 0.1, `actual_w_m ${grid.actual_w_m} vs ${expect.actual_w_m}`);
            assert.ok(Math.abs(grid.actual_h_m - expect.actual_h_m) <= 0.1, `actual_h_m ${grid.actual_h_m} vs ${expect.actual_h_m}`);

            // Kept and dropped are both compared: this side draws the dropped
            // ones dashed, so "where the grid is" has to agree across
            // languages even for the cells nobody is going to search.
            for (const set of ['cells', 'dropped']) {
                assert.equal(grid[set].length, expect[set].length, `${set} count`);
                expect[set].forEach((expectedCell, i) => {
                    expectedCell.forEach(([lat, lng], corner) => {
                        assert.ok(
                            Math.abs(grid[set][i][corner][0] - lat) <= COORD_TOLERANCE,
                            `${set} ${i} corner ${corner} lat: ${grid[set][i][corner][0]} vs ${lat}`
                        );
                        assert.ok(
                            Math.abs(grid[set][i][corner][1] - lng) <= COORD_TOLERANCE,
                            `${set} ${i} corner ${corner} lng: ${grid[set][i][corner][1]} vs ${lng}`
                        );
                    });
                });
            }
            assert.equal(grid.cells.length + grid.dropped.length, grid.total,
                'every cell of the grid must be either kept or dropped, never neither');
        });
    }
});

// Not fixture-driven: the preview panel reads these numbers straight out of
// the returned object to build its own warning line, so they have to be
// self-consistent regardless of what any fixture says.
test('the reported cell size is what the cells actually are', () => {
    const grid = gridCellsForPolygon(fixture.cases[0].geo, fixture.cases[0].size_m);
    assert.ok(grid.actual_w_m < grid.requested_m, 'actual width must never exceed the requested size');
    assert.ok(grid.actual_h_m < grid.requested_m, 'actual height must never exceed the requested size');
    assert.equal(grid.total, grid.cols * grid.rows);
    assert.equal(grid.kept, grid.cells.length);
});

test('pointInPolygon agrees with the cells that were kept', () => {
    // The triangle case is the one that drops cells; every survivor must be
    // centred inside, and the count must be lower than the full grid.
    const c = fixture.cases.find(x => x.name === 'crete_triangle_500');
    const grid = gridCellsForPolygon(c.geo, c.size_m);

    assert.ok(grid.kept < grid.total);
    for (const [nw, , se] of grid.cells) {
        assert.ok(
            pointInPolygon((nw[0] + se[0]) / 2, (nw[1] + se[1]) / 2, c.geo),
            'a kept cell is not centred inside the area'
        );
    }
});
