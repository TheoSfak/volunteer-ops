// Run with: node --test tests/js
//
// The JS half of the shared triage fixture check.
//
// triageEvaluate() / normalizeTriageCardNo() (assets/js/triage.js) and their
// twins in includes/functions-triage.php are the same logic written twice:
// the phone asks the START/JumpSTART questions offline, the server recomputes
// the colour from the answers. Both are pinned to
// tests/fixtures/triage-cases.json, this file asserting the JS side and
// tests/TriageProtocolTest.php the PHP side.

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const {
    TRIAGE_PROTOCOLS, triageStep, triageEvaluate, triageSecondaryScore, triageEvacuationQueue, normalizeTriageCardNo, triageFallbackCode, triageUuid,
} = require('../../assets/js/triage.js');

const fixture = JSON.parse(
    fs.readFileSync(path.join(__dirname, '../fixtures/triage-cases.json'), 'utf8')
);

test('triageSecondaryScore matches the shared fixture', async (t) => {
    for (const c of fixture.secondary_cases) {
        await t.test(c.name, () => {
            const result = triageSecondaryScore(c.vitals);
            if (c.expect === null) {
                assert.equal(result, null);
                return;
            }
            assert.ok(result);
            assert.equal(result.rts, c.expect.rts);
            assert.equal(result.category, c.expect.category);
        });
    }
});

test('evacuation queue: red, then yellow, then green; longest waiting first; no dead, no transported', () => {
    const v = (id, category, first_ts, status = 'on_scene') => ({id, category, first_ts, status});
    const input = [
        v(1, 'green', 100), v(2, 'yellow', 300), v(3, 'red', 500), v(4, 'red', 200),
        v(5, 'black', 50), v(6, 'red', 100, 'transported'), v(7, 'yellow', 100, 'at_ccp'), v(8, 'red', 200),
    ];
    const copy = JSON.parse(JSON.stringify(input));
    const ids = triageEvacuationQueue(input).map(x => x.id);
    // reds by wait (4 and 8 tie on time -> lower id first, then 3), yellows (7, 2), green (1)
    assert.deepEqual(ids, [4, 8, 3, 7, 2, 1]);
    assert.deepEqual(input, copy, 'the input must not be reordered or changed');
    assert.deepEqual(triageEvacuationQueue([]), []);
    assert.deepEqual(triageEvacuationQueue(null), []);
});

test('evacuation queue: a casualty who is at the CCP is still ranked by priority, not by place', () => {
    const q = triageEvacuationQueue([
        {id: 1, category: 'yellow', first_ts: 10, status: 'at_ccp'},
        {id: 2, category: 'red', first_ts: 900, status: 'on_scene'},
    ]);
    assert.deepEqual(q.map(x => x.id), [2, 1]);
});

test('triageEvaluate matches the shared fixture', async (t) => {
    for (const c of fixture.protocol_cases) {
        await t.test(c.name, () => {
            const result = triageEvaluate(c.protocol, c.answers);
            if (c.expect === null) {
                assert.equal(result, null);
                return;
            }
            assert.ok(result);
            assert.equal(result.category, c.expect.category);
            assert.equal(result.reason, c.expect.reason);
            assert.deepEqual(result.path, c.expect.path);
        });
    }
});

test('normalizeTriageCardNo matches the shared fixture', async (t) => {
    for (const c of fixture.card_cases) {
        await t.test(c.name, () => {
            assert.equal(normalizeTriageCardNo(c.raw), c.expect);
        });
    }
});

test('triageStep asks the next question until a leaf is reached', () => {
    assert.deepEqual(triageStep('start', {}), {question: 'walk', path: {}});
    assert.deepEqual(triageStep('start', {walk: false}), {question: 'breathing', path: {walk: false}});
    assert.deepEqual(triageStep('jumpstart', {walk: false, breathing: false, airway: false}),
        {question: 'pulse_apneic', path: {walk: false, breathing: false, airway: false}});
    assert.deepEqual(triageStep('start', {walk: true}), {question: 'walk_bleeding', path: {walk: true}}, 'A walker is asked about bleeding before being called green.');
    assert.equal(triageStep('start', {walk: true, walk_bleeding: false}).category, 'green');
    assert.equal(triageStep('start', {walk: true, walk_bleeding: true}).category, 'red');
    assert.equal(triageStep('nope', {}), null);
});

test('every question can be answered both ways without a dead end', () => {
    // Walks every route of both trees: each branch must end in a leaf within
    // the step cap, which is what keeps the phone from ever showing a
    // question with no way forward.
    for (const [name, tree] of Object.entries(TRIAGE_PROTOCOLS)) {
        const walk = (answers) => {
            const step = triageStep(name, answers);
            assert.ok(step, `${name} ${JSON.stringify(answers)}`);
            if (step.category) return 1;
            return walk({...answers, [step.question]: true}) + walk({...answers, [step.question]: false});
        };
        assert.ok(walk({}) >= 5, name);
    }
});

test('fallback code and uuid', () => {
    assert.equal(triageFallbackCode(65, 3), 'T65-03');
    assert.equal(triageFallbackCode(7, 120), 'T7-120');
    assert.match(triageUuid(), /^[A-Za-z0-9-]{8,64}$/);
    assert.notEqual(triageUuid(), triageUuid());
});
