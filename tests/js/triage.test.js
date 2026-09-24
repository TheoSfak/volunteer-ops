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
    TRIAGE_PROTOCOLS, triageStep, triageEvaluate, normalizeTriageCardNo, triageFallbackCode, triageUuid,
} = require('../../assets/js/triage.js');

const fixture = JSON.parse(
    fs.readFileSync(path.join(__dirname, '../fixtures/triage-cases.json'), 'utf8')
);

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
    assert.equal(triageStep('start', {walk: true}).category, 'green');
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
