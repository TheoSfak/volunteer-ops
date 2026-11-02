// Run with: node --test tests/js
//
// assets/js/esri-imagery.js: the satellite layer that does not go grey. Esri
// answers a tile deeper than its photographs with a flat grey 200 reply, not a
// 404, so the layer has to (1) work out which shallower tile covers the same
// ground and which square of it, and (2) remember which tiles are empty so a
// whole screen of z20/z21 tiles over one town costs no extra requests. These
// are those two pieces; the canvas painting is checked in the browser.

const test = require('node:test');
const assert = require('node:assert/strict');

const {
    ESRI_IMAGERY_URL, ESRI_SOURCE_SIZE, ESRI_NO_DATA_SAMPLE,
    esriTileUrl, esriAncestor, esriSamePixels, EsriNoDataIndex,
} = require('../../assets/js/esri-imagery.js');

test('the tile URL is Esri\'s z/y/x order', () => {
    assert.equal(
        esriTileUrl(18, 1500, 800),
        'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/18/800/1500'
    );
    assert.ok(ESRI_IMAGERY_URL.includes('{z}/{y}/{x}'));
});

test('a tile is its own ancestor at zero levels up: the whole picture', () => {
    assert.deepEqual(esriAncestor(19, 1000, 2000, 0, ESRI_SOURCE_SIZE), {z: 19, x: 1000, y: 2000, sx: 0, sy: 0, size: 256});
});

test('one level up, the four children are the four quarters of the parent', () => {
    // Children of parent (18, 500, 1000) are x 1000/1001, y 2000/2001 at z19.
    assert.deepEqual(esriAncestor(19, 1000, 2000, 1, 256), {z: 18, x: 500, y: 1000, sx: 0, sy: 0, size: 128});
    assert.deepEqual(esriAncestor(19, 1001, 2000, 1, 256), {z: 18, x: 500, y: 1000, sx: 128, sy: 0, size: 128});
    assert.deepEqual(esriAncestor(19, 1000, 2001, 1, 256), {z: 18, x: 500, y: 1000, sx: 0, sy: 128, size: 128});
    assert.deepEqual(esriAncestor(19, 1001, 2001, 1, 256), {z: 18, x: 500, y: 1000, sx: 128, sy: 128, size: 128});
});

test('two levels up, a tile is one sixteenth of the grandparent', () => {
    // z19 x 1003, y 2002 -> z17 x 250, y 500; offset (1003-1000, 2002-2000) = (3, 2) squares of 64 px.
    assert.deepEqual(esriAncestor(19, 1003, 2002, 2, 256), {z: 17, x: 250, y: 500, sx: 192, sy: 128, size: 64});
});

test('every ancestor square stays inside the 256 px picture', () => {
    for (let up = 0; up <= 6; up++) {
        for (const [x, y] of [[0, 0], [524287, 524287], [123456, 98765], [1, 1]]) {
            const a = esriAncestor(19, x, y, up, 256);
            assert.ok(a.sx >= 0 && a.sy >= 0, 'starts inside');
            assert.ok(a.sx + a.size <= 256 && a.sy + a.size <= 256, 'ends inside');
            assert.equal(a.z, 19 - up);
        }
    }
});

test('esriSamePixels: identical buffers match, any difference or length mismatch does not', () => {
    const a = new Uint8ClampedArray([1, 2, 3, 4, 5, 6, 7, 8]);
    assert.equal(esriSamePixels(a, new Uint8ClampedArray([1, 2, 3, 4, 5, 6, 7, 8])), true);
    assert.equal(esriSamePixels(a, new Uint8ClampedArray([1, 2, 3, 4, 5, 6, 7, 9])), false);
    assert.equal(esriSamePixels(a, new Uint8ClampedArray([1, 2, 3, 4])), false);
    assert.equal(esriSamePixels(a, null), false);
    assert.equal(esriSamePixels(null, a), false);
});

test('a tile known to be empty covers itself and everything beneath it, nothing else', () => {
    const index = new EsriNoDataIndex();
    index.add(19, 1000, 2000);

    assert.equal(index.covers(19, 1000, 2000), true, 'itself');
    assert.equal(index.covers(20, 2000, 4000), true, 'child (top-left)');
    assert.equal(index.covers(20, 2001, 4001), true, 'child (bottom-right)');
    assert.equal(index.covers(21, 4003, 8003), true, 'grandchild');
    assert.equal(index.covers(19, 1001, 2000), false, 'a sibling may have a photograph');
    assert.equal(index.covers(20, 2002, 4000), false, 'a child of a sibling');
    assert.equal(index.covers(18, 500, 1000), false, 'the parent is not known to be empty');
});

test('the empty-tile memory is bounded', () => {
    const index = new EsriNoDataIndex(10);
    for (let i = 0; i < 25; i++) index.add(19, i, 0);
    assert.ok(index.keys.size <= 10);
    assert.equal(index.covers(19, 24, 0), true, 'the newest entry survives a trim');
});

test('the reference "no data" tile is a deep one', () => {
    assert.ok(ESRI_NO_DATA_SAMPLE.z >= 20, 'deeper than any photograph Esri has');
    const n = Math.pow(2, ESRI_NO_DATA_SAMPLE.z);
    assert.ok(ESRI_NO_DATA_SAMPLE.x >= 0 && ESRI_NO_DATA_SAMPLE.x < n);
    assert.ok(ESRI_NO_DATA_SAMPLE.y >= 0 && ESRI_NO_DATA_SAMPLE.y < n);
});
