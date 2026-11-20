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

test('storageKeys are per placement', () => {
    assert.deepEqual(TC.storageKeys(7), {doc: 'wr_case_doc_7', answers: 'wr_case_answers_7'});
});

// ── Replaying a queued answer ────────────────────────────────────────────
const RETRY = ['mission not found', 'bad token', 'went wrong'];

test('queueOutcome: a success or a duplicate is sent', () => {
    assert.equal(TC.queueOutcome({ok: true, duplicate: false}, RETRY), 'sent');
    assert.equal(TC.queueOutcome({ok: true, duplicate: true}, RETRY), 'sent');
});

test('queueOutcome: no signal, a login redirect or a non-JSON reply keeps the item queued', () => {
    assert.equal(TC.queueOutcome(null, RETRY), 'retry');
    assert.equal(TC.queueOutcome({ok: false, networkError: true, error: 'x'}, RETRY), 'retry');
    assert.equal(TC.queueOutcome(undefined, RETRY), 'retry');
});

test('queueOutcome: a closed mission, a stale token or a server error is not a rejection', () => {
    assert.equal(TC.queueOutcome({ok: false, error: 'mission not found'}, RETRY), 'retry');
    assert.equal(TC.queueOutcome({ok: false, error: 'bad token'}, RETRY), 'retry');
    assert.equal(TC.queueOutcome({ok: false, error: 'went wrong'}, RETRY), 'retry');
    assert.equal(TC.queueOutcome({ok: false, csrf: true, error: 'q'}, RETRY), 'retry');
});

test('queueOutcome: an explicit refusal is dropped', () => {
    assert.equal(TC.queueOutcome({ok: false, error: 'not valid now'}, RETRY), 'drop');
    assert.equal(TC.queueOutcome({ok: false}, RETRY), 'drop');
    assert.equal(TC.queueOutcome({ok: false, error: 'x'}, undefined), 'drop');
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
