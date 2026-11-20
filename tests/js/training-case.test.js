// Run with: node --test tests/js
const test = require('node:test');
const assert = require('node:assert/strict');
const TC = require('../../assets/js/training-case.js');

const doc = {
    title: 'T', story: 'S', closing: 'C', findings: [], start: 's1',
    steps: {
        s1: {prompt: 'P1', hint: '', choices: [
            {id: 'a', label: 'A', rating: 'best', feedback: 'fa', next: 's2'},
            {id: 'b', label: 'B', rating: 'harmful', feedback: 'fb', next: 's2'}]},
        s2: {prompt: 'P2', hint: '', choices: [
            {id: 'a', label: 'A', rating: 'acceptable', feedback: 'fa2', next: null},
            {id: 'b', label: 'B', rating: 'best', feedback: 'fb2', next: null}]},
    },
};

test('choiceOutcome reads rating, feedback and next from the document', () => {
    assert.deepEqual(TC.choiceOutcome(doc, 's1', 'b'), {rating: 'harmful', feedback: 'fb', next: 's2'});
    assert.equal(TC.choiceOutcome(doc, 's1', 'zzz'), null);
    assert.equal(TC.choiceOutcome(doc, 'nope', 'a'), null);
});

test('runFromAnswers walks the case and scores like the server', () => {
    assert.deepEqual(TC.runFromAnswers(doc, []), {currentStep: 's1', finished: false, points: 0, max: 0, percent: 0, label: 'practice'});
    const half = TC.runFromAnswers(doc, [{stepId: 's1', choiceId: 'a'}]);
    assert.equal(half.currentStep, 's2');
    assert.equal(half.points, 2);
    assert.equal(half.max, 2);
    const done = TC.runFromAnswers(doc, [{stepId: 's1', choiceId: 'a'}, {stepId: 's2', choiceId: 'a'}]);
    assert.equal(done.finished, true);
    assert.equal(done.currentStep, null);
    assert.equal(done.points, 3);
    assert.equal(done.max, 4);
    assert.equal(done.percent, 75);
    assert.equal(done.label, 'good');
});

test('runFromAnswers ignores an answer that does not follow the path', () => {
    const run = TC.runFromAnswers(doc, [{stepId: 's2', choiceId: 'b'}]);
    assert.equal(run.currentStep, 's1');
    assert.equal(run.points, 0);
});

test('labels: excellent from 80 percent, good from 50', () => {
    assert.equal(TC.scoreLabel(8, 10), 'excellent');
    assert.equal(TC.scoreLabel(5, 10), 'good');
    assert.equal(TC.scoreLabel(4, 10), 'practice');
    assert.equal(TC.scoreLabel(0, 0), 'practice');
});

test('mergeAnswers lets the server win per step and keeps local-only steps', () => {
    const merged = TC.mergeAnswers(
        [{stepId: 's1', choiceId: 'a'}],
        [{stepId: 's1', choiceId: 'b'}, {stepId: 's2', choiceId: 'b'}]);
    assert.deepEqual(merged, [{stepId: 's1', choiceId: 'a'}, {stepId: 's2', choiceId: 'b'}]);
});

test('bestPathLabels lists the best choice of each step on the path', () => {
    assert.deepEqual(TC.bestPathLabels(doc), [{stepId: 's1', label: 'A'}, {stepId: 's2', label: 'B'}]);
});

test('answerQueueItem has the shape war-room.php replays', () => {
    const item = TC.answerQueueItem(7, 's1', 'a', 1234);
    assert.equal(item.kind, 'case_answer');
    assert.equal(item.placementId, 7);
    assert.equal(item.stepId, 's1');
    assert.equal(item.choiceId, 'a');
    assert.equal(item.ms, 1234);
    assert.equal(typeof item.at, 'number');
});

test('escapeHtml neutralises markup (case text is rendered through it)', () => {
    assert.equal(TC.escapeHtml('<b>"x" & y</b>'), '&lt;b&gt;&quot;x&quot; &amp; y&lt;/b&gt;');
    assert.equal(TC.escapeHtml('<a href="'), '&lt;a href=&quot;');
});

test('storageKeys are per viewer and placement (a shared phone must not show A\'s answers to B)', () => {
    assert.deepEqual(TC.storageKeys(3, 7), {doc: 'wr_case_doc_3_7', answers: 'wr_case_answers_3_7'});
    assert.notEqual(TC.storageKeys(3, 7).answers, TC.storageKeys(4, 7).answers);
    assert.notEqual(TC.storageKeys(3, 7).doc, TC.storageKeys(4, 7).doc);
    assert.notEqual(TC.storageKeys(3, 7).answers, TC.storageKeys(3, 8).answers);
    // Neither is the legacy per-placement key.
    assert.notEqual(TC.storageKeys(3, 7).answers, 'wr_case_answers_7');
});

// ── Replaying a queued answer ────────────────────────────────────────────
test('queueOutcome: a success or a duplicate is sent', () => {
    assert.equal(TC.queueOutcome({ok: true, duplicate: false}), 'sent');
    assert.equal(TC.queueOutcome({ok: true, duplicate: true}), 'sent');
});

test('queueOutcome: no signal, a login redirect or a non-JSON reply keeps the item queued', () => {
    assert.equal(TC.queueOutcome(null), 'retry');
    assert.equal(TC.queueOutcome(undefined), 'retry');
    assert.equal(TC.queueOutcome({ok: false, networkError: true, error: 'x'}), 'retry');
    assert.equal(TC.queueOutcome({ok: false, networkError: true, code: 'refused'}), 'retry', 'no signal beats a code');
    assert.equal(TC.queueOutcome('<html>'), 'retry');
});

test('queueOutcome: only a reply with code "refused" is dropped', () => {
    assert.equal(TC.queueOutcome({ok: false, error: 'not valid now', code: 'refused'}), 'drop');
    assert.equal(TC.queueOutcome({ok: false, code: 'refused'}), 'drop');
});

test('queueOutcome: everything else is kept and retried, whatever language the text is in', () => {
    for (const code of ['mission_closed', 'bad_token', 'server_error', 'not_found', 'bad_request', 'something_new', '', null, undefined, 0]) {
        assert.equal(TC.queueOutcome({ok: false, error: 'Το περιστατικό δεν είναι διαθέσιμο.', code: code}), 'retry', String(code));
    }
    assert.equal(TC.queueOutcome({ok: false, error: 'Αυτή η απάντηση δεν είναι έγκυρη τώρα.'}), 'retry', 'a reply with no code at all (an older server)');
    assert.equal(TC.queueOutcome({ok: false, csrf: true, error: 'q', code: 'refused'}), 'retry', 'a stale page token');
    assert.equal(TC.queueOutcome({}), 'retry');
});

test('queueOutcome: a refusal code on a success reply does not turn it into a drop', () => {
    assert.equal(TC.queueOutcome({ok: true, duplicate: true, code: 'refused'}), 'sent');
});

test('replayCandidate: a run that lags behind a stored answer is replayed', () => {
    const run = {current_step: 's1', finished: false, answers: [{step_id: 's1', choice_id: 'a'}]};
    assert.deepEqual(TC.replayCandidate(run, []), {stepId: 's1', choiceId: 'a'});
});

test('replayCandidate: a step answered only on this phone is replayed too', () => {
    const run = {current_step: 's2', finished: false, answers: [{step_id: 's1', choice_id: 'a'}]};
    assert.deepEqual(TC.replayCandidate(run, [{stepId: 's2', choiceId: 'b'}]), {stepId: 's2', choiceId: 'b'});
});

test('replayCandidate: nothing to replay when the run is level, finished or missing', () => {
    assert.equal(TC.replayCandidate({current_step: 's2', finished: false, answers: [{step_id: 's1', choice_id: 'a'}]}, []), null);
    assert.equal(TC.replayCandidate({current_step: 's2', finished: true, answers: [{step_id: 's2', choice_id: 'a'}]}, []), null);
    assert.equal(TC.replayCandidate(null, [{stepId: 's1', choiceId: 'a'}]), null);
    assert.equal(TC.replayCandidate({current_step: null, finished: false, answers: []}, []), null);
});

test('withoutAnswer removes one step from a list of answers', () => {
    assert.deepEqual(TC.withoutAnswer([{stepId: 's1', choiceId: 'a'}, {stepId: 's2', choiceId: 'b'}], 's2'), [{stepId: 's1', choiceId: 'a'}]);
});

// ── A document read from localStorage is untrusted ───────────────────────
const cyclic = {
    title: 'T', story: 'S', start: 'a',
    steps: {
        a: {prompt: 'A', choices: [{id: 'x', label: 'X', rating: 'best', feedback: 'f', next: 'b'}, {id: 'y', label: 'Y', rating: 'harmful', feedback: 'f', next: 'b'}]},
        b: {prompt: 'B', choices: [{id: 'x', label: 'X', rating: 'best', feedback: 'f', next: 'a'}, {id: 'y', label: 'Y', rating: 'harmful', feedback: 'f', next: 'a'}]},
    },
};

test('bestPathLabels stops at a cycle instead of hanging', () => {
    assert.deepEqual(TC.bestPathLabels(cyclic), [{stepId: 'a', label: 'X'}, {stepId: 'b', label: 'X'}]);
});

test('runFromAnswers stops at a cycle instead of hanging', () => {
    const answers = [{stepId: 'a', choiceId: 'x'}, {stepId: 'b', choiceId: 'x'}, {stepId: 'a', choiceId: 'x'}];
    const run = TC.runFromAnswers(cyclic, answers);
    assert.equal(run.finished, false);
    assert.equal(run.points, 4, 'each step counted once');
    assert.equal(run.max, 4);
    assert.equal(run.currentStep, 'a');
});

test('validDoc accepts a sound document and refuses broken shapes', () => {
    assert.equal(TC.validDoc(doc), true);
    assert.equal(TC.validDoc(cyclic), true, 'a cycle is not a shape problem; the walkers guard against it');
    const bad = [
        null, 'x', 5, [], {}, {start: 's1'}, {start: 's1', steps: {}}, {start: 's1', steps: 'x'},
        {start: 's1', steps: {s1: null}},
        {start: 's1', steps: {s1: {prompt: 'P'}}},
        {start: 's1', steps: {s1: {prompt: 'P', choices: 'abc'}}},
        {start: 's1', steps: {s1: {prompt: 'P', choices: {}}}},
        {start: 's1', steps: {s1: {prompt: 'P', choices: []}}},
        {start: 's1', steps: {s1: {prompt: 'P', choices: [null]}}},
        {start: 's1', steps: {s1: {prompt: 'P', choices: ['a']}}},
        {start: 's1', steps: {s1: {prompt: 'P', choices: [{id: 1, label: 'A'}]}}},
        {start: 's1', steps: {s1: {prompt: 'P', choices: [{id: 'a', label: 5}]}}},
        {start: 's1', steps: {s1: {prompt: 'P', choices: [{id: 'a'}]}}},
        // One bad step anywhere refuses the whole document (a later step would throw mid-case).
        {start: 's1', steps: {s1: {prompt: 'P', choices: [{id: 'a', label: 'A'}]}, s2: {prompt: 'Q', choices: []}}},
        {start: 's1', steps: {s1: {prompt: 'P', choices: [{id: 'a', label: 'A'}]}}, findings: 'x'},
    ];
    bad.forEach((d, i) => assert.equal(TC.validDoc(d), false, 'bad document #' + i));
});

test('localOnlyAnswers keeps only what the server run does not report yet', () => {
    const server = [{stepId: 's1', choiceId: 'a'}];
    assert.deepEqual(TC.localOnlyAnswers(server, [{stepId: 's1', choiceId: 'b'}, {stepId: 's2', choiceId: 'b'}]), [{stepId: 's2', choiceId: 'b'}]);
    assert.deepEqual(TC.localOnlyAnswers([], [{stepId: 's1', choiceId: 'b'}]), [{stepId: 's1', choiceId: 'b'}]);
    assert.deepEqual(TC.localOnlyAnswers(undefined, undefined), []);
});

test('viewSignature changes when the case or where the run stands changes, not otherwise', () => {
    const a = [{stepId: 's1', choiceId: 'a'}];
    const same = TC.viewSignature(doc, a);
    assert.equal(TC.viewSignature(JSON.parse(JSON.stringify(doc)), [{stepId: 's1', choiceId: 'a'}]), same);
    const edited = JSON.parse(JSON.stringify(doc));
    edited.steps.s1.choices[0].label = 'changed';
    assert.notEqual(TC.viewSignature(edited, a), same);
    assert.notEqual(TC.viewSignature(doc, []), same, 'the run moved on');
    assert.notEqual(TC.viewSignature(doc, [{stepId: 's1', choiceId: 'a'}, {stepId: 's2', choiceId: 'a'}]), same, 'finished');
});

test('forgetLocalAnswer takes one step out of this viewer\'s copy only', () => {
    const store = {};
    global.localStorage = {getItem: k => (k in store ? store[k] : null), setItem: (k, v) => { store[k] = String(v); }};
    try {
        store['wr_case_answers_3_7'] = JSON.stringify([{stepId: 's1', choiceId: 'a'}, {stepId: 's2', choiceId: 'b'}]);
        store['wr_case_answers_4_7'] = JSON.stringify([{stepId: 's2', choiceId: 'a'}]);
        TC.forgetLocalAnswer(3, 7, 's2');
        assert.deepEqual(JSON.parse(store['wr_case_answers_3_7']), [{stepId: 's1', choiceId: 'a'}]);
        assert.deepEqual(JSON.parse(store['wr_case_answers_4_7']), [{stepId: 's2', choiceId: 'a'}], 'another viewer\'s copy is untouched');
    } finally {
        delete global.localStorage;
    }
});
