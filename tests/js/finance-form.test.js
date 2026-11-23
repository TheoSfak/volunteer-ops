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
    assert.equal(F.toCents('1.234,56'), 123456);
    assert.equal(F.toCents(''), 0);
    assert.equal(F.fromCents(1250), '12.50');
    assert.equal(F.fromCents(-5), '-0.05');
    assert.equal(F.display(123450), '1234,50');
});


test('parseCents reads el-GR amounts (same table as FinanceMoneyTest::amountExamples)', () => {
    const table = [
        ['1.500', 150000], ['1.234,56', 123456], ['12,40', 1240], ['1500.00', 150000], ['12.5', 1250],
        ['12.000', 1200000], ['1.000.000', 100000000], ['1500', 150000], ['0,5', 50], ['0.500', 50],
        ['1000.500', 100050], ['-1.500,25', -150025], [' 1 234,50 ', 123450],
        ['1.23.4', null], ['abc', null], ['1,2,3', null], ['', null], ['1.2345,6', null], ['1,5.0', null], ['--5', null],
    ];
    for (const [input, cents] of table) {
        assert.equal(F.parseCents(input), cents, input);
        assert.equal(F.toCents(input), cents === null ? 0 : cents, input);
    }
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

test('validVat mirrors financeValidVatNumber() (same values as FinanceMoneyTest)', () => {
    assert.equal(F.validVat('090000045'), true);
    assert.equal(F.validVat('090000046'), false);
    assert.equal(F.validVat('000000000'), false);
    assert.equal(F.validVat('12345'), false);
    assert.equal(F.validVat('09000004A'), false);
    assert.equal(F.validVat('090000045\n'), false);
});

test('the submit check strips non-digits like the server', () => {
    assert.equal(F.validVat(F.cleanVat('EL090000045')), true);
    assert.equal(F.validVat(F.cleanVat('090 000 045')), true);
    assert.equal(F.validVat(F.cleanVat('EL090000046')), false);
    assert.equal(F.cleanVat('EL'), '');
});

test('pdf.js is opened with eval disabled (CVE-2024-4367)', () => {
    const src = fs.readFileSync(path.join(__dirname, '../../assets/js/finance-form.js'), 'utf8');
    assert.match(src, /getDocument\(\{[^}]*isEvalSupported:\s*false/);
});

test('the VAT mirror docblock sits directly above validVat', () => {
    const src = fs.readFileSync(path.join(__dirname, '../../assets/js/finance-form.js'), 'utf8');
    assert.match(src, /Mirror of financeValidVatNumber\(\)[^\n]*\*\/\r?\n\s*function validVat\(/);
});

test('paidBeforeOpening compares ISO dates strictly; an empty opening date never warns', () => {
    assert.equal(F.paidBeforeOpening('2026-01-01', '2025-12-31'), true);
    assert.equal(F.paidBeforeOpening('2026-01-01', '2026-01-01'), false);
    assert.equal(F.paidBeforeOpening('2026-01-01', '2026-02-01'), false);
    assert.equal(F.paidBeforeOpening('', '2025-12-31'), false);
    assert.equal(F.paidBeforeOpening('2026-01-01', ''), false);
    assert.equal(F.paidBeforeOpening('garbage', '2025-12-31'), false);
});

test('openingWarning words the Greek warning with the dd/mm/yyyy opening date, or stays empty', () => {
    assert.equal(F.formatDmy('2026-03-07'), '07/03/2026');
    const w = F.openingWarning('Ταμείο <b>', '2026-03-07', '2026-01-02');
    assert.match(w, /^Προσοχή: η ημερομηνία πληρωμής είναι πριν από την έναρξη του λογαριασμού «Ταμείο <b>» \(07\/03\/2026\)\. /);
    assert.match(w, /θα μετρηθεί δύο φορές\.$/);
    assert.equal(F.openingWarning('Ταμείο', '', '2026-01-02'), '');
    assert.equal(F.openingWarning('Ταμείο', '2026-03-07', '2026-03-07'), '');
});

test('the account name only reaches the page through textContent', () => {
    const src = fs.readFileSync(path.join(__dirname, '../../assets/js/finance-form.js'), 'utf8');
    const block = src.slice(src.indexOf('function initOpeningWarnings'));
    assert.match(block, /box\.textContent = text/);
    assert.doesNotMatch(block, /innerHTML/);
});
test('nextPayDate follows the document date until the user takes over', () => {
    assert.equal(F.nextPayDate(true, '2026-10-12', '2026-11-23'), '2026-10-12');
    assert.equal(F.nextPayDate(false, '2026-10-12', '2026-11-23'), '2026-11-23');
    assert.equal(F.nextPayDate(true, '', '2026-11-23'), '2026-11-23', 'a cleared document date leaves the pay date alone');
    assert.equal(F.nextPayDate(true, 'garbage', '2026-11-23'), '2026-11-23');
});

test('shouldUntickPaid: a due date unticks «Πληρώθηκε ήδη» once, never against the user', () => {
    const base = {due: '2026-12-01', checked: true, handled: false, userSet: false};
    assert.equal(F.shouldUntickPaid(base), true);
    assert.equal(F.shouldUntickPaid({...base, due: ''}), false, 'no due date');
    assert.equal(F.shouldUntickPaid({...base, due: '  '}), false);
    assert.equal(F.shouldUntickPaid({...base, checked: false}), false, 'already unticked');
    assert.equal(F.shouldUntickPaid({...base, handled: true}), false, 'only once: a second due date does not untick again');
    assert.equal(F.shouldUntickPaid({...base, userSet: true}), false, 'the user re-ticked it (or ticked it themselves)');
});
