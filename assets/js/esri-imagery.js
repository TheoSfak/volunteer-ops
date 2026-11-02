/**
 * Esri satellite imagery that never goes grey (v3.420.2).
 *
 * Esri's World_Imagery has real photographs only down to a depth that depends
 * on the place: z19 over Heraklion and Athens, but z18 over Chania, Rethymno,
 * Agios Nikolaos, Ierapetra and most of the island's villages, and z17 in
 * places. Asked for a tile deeper than it has, the server does not answer 404:
 * it answers 200 with a flat grey 256x256 picture reading "Map data not yet
 * available" (always the same 2521 bytes, wherever and at whatever zoom). The
 * maps are built with maxNativeZoom 19, so on every town but Heraklion the
 * satellite view turned into a grey screen from zoom 19 up - exactly where a
 * city operation needs the most detail.
 *
 * voEsriImagery() is a drop-in for L.tileLayer(<Esri URL>, options). It loads
 * each tile, recognises the grey one by comparing its pixels with a reference
 * "no data" tile, and when it meets it paints the same ground from the nearest
 * shallower tile that has a photograph, enlarged. Soft, but a picture, and the
 * same upscaling the street and topographic layers already do past their own
 * native depth (MAP_MAX_ZOOM in war-room.php).
 *
 * The tile is loaded as an <img crossorigin="anonymous"> and inspected on a
 * canvas, not fetched: bootstrap.php's connect-src does not list Esri (and
 * need not), while img-src allows any https host and Esri sends
 * Access-Control-Allow-Origin: *. If that header ever disappears the tile is
 * loaded plainly and painted unchecked - the layer then behaves as the old
 * L.tileLayer did, never worse.
 *
 * Shared by war-room.php, mission-form.php (the address picker) and
 * mission-archive.php. The pure helpers are exported for Node so
 * tests/js/esri-imagery.test.js can run them.
 */
(function (root) {
    'use strict';

    const ESRI_IMAGERY_URL = 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}';
    // Esri's photographs are 256 px squares whatever size the layer draws them.
    const ESRI_SOURCE_SIZE = 256;
    // A tile Esri has no imagery for at any place: z21 over Heraklion. Its
    // answer is the "no data" picture every other missing tile shares.
    const ESRI_NO_DATA_SAMPLE = {z: 21, x: 1195051, y: 828263};
    // How many zoom levels up to look for a photograph (z19 -> z13).
    const ESRI_MAX_LEVELS_UP = 6;
    // Remembered "no photograph here" tiles, and decoded parent tiles kept so
    // the four (or sixteen, or sixty-four) children of one parent share a load.
    const ESRI_NO_DATA_MEMORY = 4000;
    const ESRI_IMAGE_MEMORY = 48;

    function esriTileUrl(z, x, y) {
        return ESRI_IMAGERY_URL.replace('{z}', z).replace('{y}', y).replace('{x}', x);
    }

    /**
     * The tile `up` levels above (z, x, y), and which square of it the original
     * tile covers: {z, x, y} of the ancestor, and sx/sy/size, the source
     * rectangle to enlarge, in the ancestor's own pixels of `tileSize`.
     */
    function esriAncestor(z, x, y, up, tileSize) {
        const span = Math.pow(2, up);
        const ax = Math.floor(x / span);
        const ay = Math.floor(y / span);
        const size = tileSize / span;
        return {z: z - up, x: ax, y: ay, sx: (x - ax * span) * size, sy: (y - ay * span) * size, size};
    }

    /** True when two RGBA pixel buffers are identical. Stops at the first difference. */
    function esriSamePixels(a, b) {
        if (!a || !b || a.length !== b.length) return false;
        for (let i = 0; i < a.length; i++) {
            if (a[i] !== b[i]) return false;
        }
        return true;
    }

    /**
     * Tiles known to be "no data". A tile with no photograph has no deeper
     * photographs either, so a hit on a tile or ANY ancestor of it answers
     * without another request - at z20 and z21 over Chania nothing is asked
     * once the z19 tile has been seen empty. Siblings and other branches are
     * not covered: Esri's coverage ends at different depths in different places.
     */
    class EsriNoDataIndex {
        constructor(limit) {
            this.limit = limit || ESRI_NO_DATA_MEMORY;
            this.keys = new Set();
        }

        add(z, x, y) {
            if (this.keys.size >= this.limit) this.keys.clear();
            this.keys.add(z + '/' + x + '/' + y);
        }

        covers(z, x, y) {
            for (let up = 0; up <= z; up++) {
                const span = Math.pow(2, up);
                if (this.keys.has((z - up) + '/' + Math.floor(x / span) + '/' + Math.floor(y / span))) return true;
            }
            return false;
        }
    }

    // ---- browser side: nothing below runs in Node ------------------------------

    const noData = new EsriNoDataIndex();
    const images = new Map();
    let scratch = null;
    let reference = null;
    let referenceFailures = 0;
    let layerClass = null;

    /** {img, readable}: readable is false when the CORS load was refused and the tile was loaded plainly. */
    function loadImage(url) {
        const cached = images.get(url);
        if (cached) return cached;
        const pending = new Promise((resolve, reject) => {
            const img = new Image();
            img.crossOrigin = 'anonymous';
            img.onload = () => resolve({img, readable: true});
            img.onerror = () => {
                const plain = new Image();
                plain.onload = () => resolve({img: plain, readable: false});
                plain.onerror = () => reject(new Error('tile failed: ' + url));
                plain.src = url;
            };
            img.src = url;
        });
        images.set(url, pending);
        if (images.size > ESRI_IMAGE_MEMORY) images.delete(images.keys().next().value);
        pending.catch(() => images.delete(url));
        return pending;
    }

    function pixelsOf(img) {
        if (!scratch) {
            const canvas = document.createElement('canvas');
            canvas.width = canvas.height = ESRI_SOURCE_SIZE;
            scratch = canvas.getContext('2d', {willReadFrequently: true});
        }
        scratch.clearRect(0, 0, ESRI_SOURCE_SIZE, ESRI_SOURCE_SIZE);
        scratch.drawImage(img, 0, 0, ESRI_SOURCE_SIZE, ESRI_SOURCE_SIZE);
        return scratch.getImageData(0, 0, ESRI_SOURCE_SIZE, ESRI_SOURCE_SIZE).data;
    }

    /** The pixels of the "no data" picture, loaded once. Null when it cannot be had (then nothing is treated as empty). */
    function noDataReference() {
        if (reference) return reference;
        reference = loadImage(esriTileUrl(ESRI_NO_DATA_SAMPLE.z, ESRI_NO_DATA_SAMPLE.x, ESRI_NO_DATA_SAMPLE.y))
            .then(loaded => (loaded.readable ? pixelsOf(loaded.img) : null))
            .catch(() => {
                // A passing network failure must not switch the fix off for the
                // whole session: try again on the next tile, three times at most.
                referenceFailures++;
                if (referenceFailures < 3) reference = null;
                return null;
            });
        return reference;
    }

    async function isNoData(loaded) {
        if (!loaded.readable) return false;
        const expected = await noDataReference();
        if (!expected) return false;
        try {
            return esriSamePixels(pixelsOf(loaded.img), expected);
        } catch (e) {
            return false;
        }
    }

    /** Paints `canvas` with the photograph for `coords`, or the nearest shallower one enlarged. */
    async function paintTile(coords, canvas) {
        const ctx = canvas.getContext('2d');
        for (let up = 0; up <= ESRI_MAX_LEVELS_UP && coords.z - up >= 0; up++) {
            const a = esriAncestor(coords.z, coords.x, coords.y, up, ESRI_SOURCE_SIZE);
            if (noData.covers(a.z, a.x, a.y)) continue;
            const loaded = await loadImage(esriTileUrl(a.z, a.x, a.y));
            if (await isNoData(loaded)) {
                noData.add(a.z, a.x, a.y);
                continue;
            }
            ctx.drawImage(loaded.img, a.sx, a.sy, a.size, a.size, 0, 0, canvas.width, canvas.height);
            return;
        }
    }

    /** A Leaflet layer for Esri's World_Imagery, `options` as for L.tileLayer. */
    function voEsriImagery(options) {
        const L = root.L;
        if (!layerClass) {
            layerClass = L.TileLayer.extend({
                // Two parameters on purpose: Leaflet only waits for done() when
                // createTile declares it.
                createTile(coords, done) {
                    const size = this.getTileSize();
                    const tile = document.createElement('canvas');
                    tile.width = size.x;
                    tile.height = size.y;
                    paintTile(coords, tile).then(() => done(null, tile), err => done(err, tile));
                    return tile;
                },
            });
        }
        return new layerClass(ESRI_IMAGERY_URL, options);
    }

    root.voEsriImagery = voEsriImagery;

    if (typeof module !== 'undefined' && module.exports) {
        module.exports = {
            ESRI_IMAGERY_URL, ESRI_SOURCE_SIZE, ESRI_NO_DATA_SAMPLE, ESRI_MAX_LEVELS_UP,
            esriTileUrl, esriAncestor, esriSamePixels, EsriNoDataIndex,
        };
    }
})(typeof window !== 'undefined' ? window : globalThis);
