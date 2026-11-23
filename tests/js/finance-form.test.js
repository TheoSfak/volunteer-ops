// Run with: node --test tests/js
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const F = require('../../assets/js/finance-form.js');

test('rates and tolerance match the PHP side', () => {
    assert.deepEqual(F.VAT_RATES, [24, 13, 6, 17, 9, 4, 0]);
    assert.equal(F.TOLERANCE_CENTS, 2);
    const php = fs.readFileSync(path.join(__dirname, '../../includes/finance-functions.php'), 'utf8');
    assert.match(php, /const FINANCE_VAT_RATES = \[24, 13, 6, 17, 9, 4, 0\];/);
    assert.match(php, /const FINANCE_TOTAL_TOLERANCE_CENTS = 2;/);
});

test('toCents / fromCents / display agree with financeToCents()', () => {
    assert.equal(F.toCents('12,50'), 1250);
    assert.equal(F.toCents('12.5'), 1250);
    assert.equal(F.toCents(' 12 '), 1200);
    assert.equal(F.toCents('0.005'), 1);
    assert.equal(F.toCents('1.234,56'), 0);
    assert.equal(F.toCents(''), 0);
    assert.equal(F.fromCents(1250), '12.50');
    assert.equal(F.fromCents(-5), '-0.05');
    assert.equal(F.display(123450), '1234,50');
});

test('splits match the PHP results', () => {
    assert.deepEqual(F.splitGross(1240, 24), {rate: 24, net: 1000, vat: 240, gross: 1240});
    assert.deepEqual(F.splitGross(1000, 13), {rate: 13, net: 885, vat: 115, gross: 1000});
    assert.deepEqual(F.splitNet(333, 6), {rate: 6, net: 333, vat: 20, gross: 353});
    for (const r of F.VAT_RATES) for (const g of [1, 99, 1001, 123456]) {
        const s = F.splitGross(g, r);
        assert.equal(s.net + s.vat, g);
    }
});

test('totals and mismatch', () => {
    assert.deepEqual(F.totals([{net: 1000, vat: 240}, {net: 500, vat: 65}]), {net: 1500, vat: 305, gross: 1805});
    assert.equal(F.mismatch(1807, 1805), false);
    assert.equal(F.mismatch(1808, 1805), true);
});
