// Run with: node --test tests/js
//
// floorChipModel() / floorPopupLineHtml() (assets/js/floor-display.js): how the
// barometer floor is written down. Pinned: the three states are told apart in
// words (a stale floor is "last known", never the current one; a drifting
// ground level carries a warning), the metres always travel with the floor, a
// basement is negative, and nothing from the server goes into markup unchecked.

const test = require('node:test');
const assert = require('node:assert/strict');

const {
    floorSigned, floorLabel, floorSafeTime, floorChipModel, floorChipHtml, floorPopupLineHtml,
} = require('../../assets/js/floor-display.js');

// A translator that shows its key and variables, so a test can see which
// sentence was chosen without depending on the wording.
const tr = (key, vars) => key + (vars && Object.keys(vars).length ? '(' + Object.entries(vars).map(([k, v]) => k + '=' + v).join(',') + ')' : '');
const esc = s => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

const ok = {m: 6, floor: 2, conf: 'ok', at: '14:32', stale: false};

test('floors are written with their sign', () => {
    assert.equal(floorSigned(2), '+2');
    assert.equal(floorSigned(-1), '-1');
    assert.equal(floorSigned(0), '0');
    assert.equal(floorSigned(1.6), '+2');
    assert.equal(floorSigned(undefined), '0');
});

test('ground, upper floors and basements have their own words', () => {
    assert.equal(floorLabel(0, tr), 'floor.label_ground');
    assert.equal(floorLabel(3, tr), 'floor.label_up(n=3)');
    assert.equal(floorLabel(-2, tr), 'floor.label_down(n=2)');
});

test('nothing to show without a floor', () => {
    assert.equal(floorChipModel(null, tr), null);
    assert.equal(floorChipModel(undefined, tr), null);
    assert.equal(floorChipModel({}, tr), null);
    assert.equal(floorChipHtml(null, tr, esc), '');
    assert.equal(floorPopupLineHtml(null, tr, esc), '');
});

test('a current floor shows the floor number and the metres beside it', () => {
    const chip = floorChipModel(ok, tr);
    assert.equal(chip.cls, 'floor-chip floor-ok');
    assert.match(chip.html, /<b>\+2<\/b>/);
    assert.match(chip.html, /floor\.metres\(m=\+6\)/);
    assert.doesNotMatch(chip.html, /14:32/, 'a current floor does not need its time on the chip');
    assert.match(chip.title, /^floor\.tip\(/);
    assert.doesNotMatch(chip.title, /tip_drift/);
});

test('a ground floor reading is shown as 0, not hidden', () => {
    const chip = floorChipModel({m: 0, floor: 0, conf: 'ok', at: '10:00', stale: false}, tr);
    assert.match(chip.html, /<b>0<\/b>/);
});

test('a basement is negative', () => {
    const chip = floorChipModel({m: -4, floor: -1, conf: 'ok', at: '10:00', stale: false}, tr);
    assert.match(chip.html, /<b>-1<\/b>/);
    assert.match(chip.html, /m=-4/);
});

test('a drifting ground level carries a warning in the chip and the sentence', () => {
    const chip = floorChipModel(Object.assign({}, ok, {conf: 'drift'}), tr);
    assert.equal(chip.cls, 'floor-chip floor-drift');
    assert.match(chip.html, /exclamation-triangle/);
    assert.match(chip.title, /floor\.tip_drift/);
});

test('a stale floor is the last known one, with the time, and is greyed', () => {
    const chip = floorChipModel(Object.assign({}, ok, {stale: true}), tr);
    assert.equal(chip.cls, 'floor-chip floor-stale');
    assert.match(chip.html, /14:32/);
    assert.match(chip.title, /^floor\.tip_stale\(/);
    assert.doesNotMatch(chip.title, /floor\.tip\(/);
});

test('stale wins over drift: an old reading is not "current but drifting"', () => {
    const chip = floorChipModel({m: 6, floor: 2, conf: 'drift', at: '14:32', stale: true}, tr);
    assert.equal(chip.cls, 'floor-chip floor-stale');
    assert.doesNotMatch(chip.html, /exclamation-triangle/);
});

test('only a real H:i time ever reaches the markup', () => {
    assert.equal(floorSafeTime('14:32'), '14:32');
    assert.equal(floorSafeTime('9:05'), '9:05');
    assert.equal(floorSafeTime('<img src=x>'), '');
    assert.equal(floorSafeTime(undefined), '');
    const chip = floorChipModel(Object.assign({}, ok, {stale: true, at: '"><script>'}), tr);
    assert.doesNotMatch(chip.html, /script/);
});

test('the chip as a string escapes the title', () => {
    const html = floorChipHtml(ok, (k, v) => '"quoted" <' + k + '>', esc);
    assert.doesNotMatch(html, /title="[^"]*"[^>]*"quoted"/);
    assert.match(html, /&quot;quoted&quot;/);
});

test('the pin popup line says the floor and metres, and what is uncertain', () => {
    assert.match(floorPopupLineHtml(ok, tr, esc), /floor\.popup\(label=floor\.label_up\(n=2\),m=floor\.metres\(m=\+6\)\)/);
    assert.match(floorPopupLineHtml(ok, tr, esc), /text-body/);
    const drift = floorPopupLineHtml(Object.assign({}, ok, {conf: 'drift'}), tr, esc);
    assert.match(drift, /popup_drift/);
    assert.match(drift, /text-warning/);
    const stale = floorPopupLineHtml(Object.assign({}, ok, {stale: true}), tr, esc);
    assert.match(stale, /floor\.popup_stale\(/);
    assert.match(stale, /text-muted/);
});
