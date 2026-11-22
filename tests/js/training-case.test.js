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

const overviewT = (k, v) => k + (v ? JSON.stringify(v) : '');

test('renderOverview shows each volunteer with a state and the per-step breakdown, and escapes names', () => {
    const t = overviewT;
    const html = TC.renderOverview({placements: [{
        id: 1, title: 'Περιστατικό <1>', label: 'Σημείο', audience: 'team',
        volunteers: [
            {user_id: 1, name: '<b>Άννα</b>', state: 'done', step: null, score_points: 3, score_max: 4, percent: 75, label: 'good',
             answers: [{step_id: 's1', choice_id: 'a', choice_label: 'Ελέγχω <i>αεραγωγό</i>', rating: 'best', ms_taken: 4200}]},
            {user_id: 2, name: 'Γιώργος', state: 'running', step: 's2', score_points: 2, score_max: 2, percent: 100, label: 'excellent', answers: [{step_id: 's1', choice_id: 'a', choice_label: 'Α', rating: 'best', ms_taken: 1000}]},
            {user_id: 3, name: 'Μαρία', state: 'new', step: null, score_points: 0, score_max: 0, percent: 0, label: null, answers: []},
        ],
        steps: [{step_id: 's1', prompt: 'Πρώτο;', choices: [{id: 'a', label: 'Α', rating: 'best', count: 2}, {id: 'b', label: 'Β', rating: 'harmful', count: 1}]}],
    }]}, t);
    assert.ok(!html.includes('<b>Άννα</b>'), 'names are escaped');
    assert.ok(html.includes('&lt;b&gt;Άννα&lt;/b&gt;'));
    assert.ok(!html.includes('Περιστατικό <1>'), 'the case title is escaped');
    assert.ok(html.includes('75%'));
    assert.ok(html.includes('3/4'), 'the score as points');
    assert.ok(html.includes('case.state.running'));
    assert.ok(html.includes('case.state.new'));
    assert.ok(html.includes('case.state.done'));
    assert.ok(html.includes('Πρώτο;'));
    assert.ok(html.includes('>2<'), 'the count of the best choice');
    assert.ok(html.includes('Ελέγχω &lt;i&gt;αεραγωγό&lt;/i&gt;'), 'a run opens to show its choices, escaped');
    assert.ok(!html.includes('<i>αεραγωγό</i>'));
    assert.ok(html.includes('4s'), 'and the time taken');
});

test('renderOverview says which step a volunteer in progress is at (their next step on their own path)', () => {
    const html = TC.renderOverview({placements: [{
        id: 1, title: 'T', label: '', audience: 'all', steps: [],
        volunteers: [{user_id: 2, name: 'Γ', state: 'running', step: 's3', score_points: 4, score_max: 4, percent: 100, label: 'excellent',
            answers: [{step_id: 's1', choice_label: 'a', rating: 'best', ms_taken: 1000}, {step_id: 's2', choice_label: 'b', rating: 'best', ms_taken: 2000}]}],
    }]}, (k, v) => k + (v ? ':' + v.n : ''));
    assert.ok(html.includes('case.overview_step:3'), html);
});

test('renderOverview shows the time of a finished run as minutes and seconds, and stays quiet without times', () => {
    const run = ms => TC.renderOverview({placements: [{
        id: 1, title: 'T', label: '', audience: 'all', steps: [],
        volunteers: [{user_id: 1, name: 'A', state: 'done', step: null, score_points: 2, score_max: 2, percent: 100, label: 'excellent',
            answers: ms.map((m, i) => ({step_id: 's' + i, choice_label: 'x', rating: 'best', ms_taken: m}))}],
    }]}, k => k);
    assert.ok(run([65000, 60000]).includes('2m 05s'));
    assert.ok(run([null]).includes('100%'));
    assert.ok(!run([null]).includes('0s'), 'no time when none was recorded');
});

test('renderOverview tolerates a volunteer without answers or a placement without steps', () => {
    const html = TC.renderOverview({placements: [{id: 1, title: 'T', label: null, audience: 'all',
        volunteers: [{user_id: 1, name: 'A', state: 'new'}]}]}, k => k);
    assert.ok(html.includes('case.state.new'));
});

test('renderOverview says when nothing has been placed', () => {
    assert.ok(TC.renderOverview({placements: []}, k => k).includes('case.overview_empty'));
    assert.ok(TC.renderOverview(null, k => k).includes('case.overview_empty'));
});

test('caseOptionLabel names the case with its stars, no review flag; the title stays plain text', () => {
    assert.equal(TC.caseOptionLabel({title: 'Πνιγμός', difficulty: 2}), '★★ Πνιγμός');
    assert.equal(TC.caseOptionLabel({title: 'Πνιγμός', difficulty: 1, reviewed: false}), '★ Πνιγμός', 'a stray reviewed flag from an old answer adds nothing');
    assert.equal(TC.caseOptionLabel({title: '<b>x</b>', difficulty: 0}), '<b>x</b>', 'a label for textContent is not escaped');
});

test('caseStageFields adds nothing without a case and carries the choice, the draw and the briefing with one', () => {
    assert.deepEqual(TC.caseStageFields(null), {});
    assert.deepEqual(TC.caseStageFields({caseId: ''}), {});
    assert.deepEqual(TC.caseStageFields({caseId: 7, mode: 'chosen', filter: {type: 'x'}, briefing: ' Ακούστηκε φωνή '}),
        {case_id: '7', case_mode: 'chosen', case_pool: '{}', briefing: 'Ακούστηκε φωνή'}, 'a chosen case records no pool');
    assert.deepEqual(TC.caseStageFields({caseId: 7, mode: 'random', filter: {type: 'airway', difficulty: 2}, briefing: ''}),
        {case_id: '7', case_mode: 'random', case_pool: '{"type":"airway","difficulty":2}', briefing: ''});
    assert.equal(TC.caseStageFields({caseId: 7, mode: 'bogus'}).case_mode, 'chosen');
});

test('renderOverview says who found the victim and when, escaping the name', () => {
    const t = (k, v) => k === 'case.overview_found' ? 'FOUND ' + v.time + ' ' + v.name : k;
    const html = TC.renderOverview({placements: [{
        id: 1, title: 'Τ', label: '', audience: 'all', volunteers: [], steps: [],
        finds: [{scope_key: 't3', found_at: '2026-11-22 14:32:10', by: '<b>Ann</b>'}],
    }]}, t);
    assert.match(html, /FOUND 14:32 &lt;b&gt;Ann&lt;\/b&gt;/);
    const none = TC.renderOverview({placements: [{id: 1, title: 'Τ', label: '', audience: 'all', volunteers: [], steps: [], finds: []}]}, t);
    assert.doesNotMatch(none, /FOUND/);
});

test('renderOverview prints no dangling "by" when the finder is gone (a deleted user)', () => {
    const t = (k, v) => k === 'case.overview_found' ? 'FOUND ' + v.time + ' by ' + v.name : (k === 'case.overview_found_unknown' ? 'FOUND ' + v.time : k);
    const page = by => TC.renderOverview({placements: [{id: 1, title: 'T', label: '', audience: 'all', volunteers: [], steps: [],
        finds: [{scope_key: 't3', found_at: '2026-11-22 14:32:10', by: by}]}]}, t);
    [null, '', undefined].forEach(by => {
        const html = page(by);
        assert.match(html, /FOUND 14:32</);
        assert.doesNotMatch(html, / by /);
    });
    assert.match(page('Ann'), /FOUND 14:32 by Ann/);
});

test('renderOverview gives each opening section a stable key, so a refresh can keep it open', () => {
    const html = TC.renderOverview({placements: [{id: 4, title: 'T', label: '', audience: 'all', steps: [],
        volunteers: [{user_id: 9, name: 'A', state: 'done', score_points: 1, score_max: 2, percent: 50, answers: [{step_id: 's1', choice_label: 'x', rating: 'best', ms_taken: 1000}]}]}]}, k => k);
    assert.ok(html.includes('data-k="r4-9"'), 'the run of one volunteer');
    assert.ok(html.includes('data-k="s4"'), 'the per-step breakdown of one case');
});

test('previewOptions: a try-out neither queues answers nor loads from the server; a real run does both', () => {
    assert.deepEqual(TC.previewOptions({preview: true}), {enqueue: false, load: false});
    assert.deepEqual(TC.previewOptions({}), {enqueue: true, load: true});
    assert.deepEqual(TC.previewOptions(null), {enqueue: true, load: true});
    assert.deepEqual(TC.previewOptions({preview: 'yes'}), {enqueue: true, load: true}, 'only a real true previews');
});

test('prefetch keeps a valid document under the player\'s own key and refuses an invalid one', async () => {
    const store = {};
    global.localStorage = {getItem: k => (k in store ? store[k] : null), setItem: (k, v) => { store[k] = v; }};
    const urls = [];
    global.fetch = async url => { urls.push(url); return {json: async () => ({ok: true, document: doc})}; };
    assert.equal(await TC.prefetch({placementId: 5, missionId: 9, userId: 3}), true);
    assert.deepEqual(JSON.parse(store[TC.storageKeys(3, 5).doc]), doc);
    assert.match(urls[0], /action=load&mission_id=9&placement_id=5/);
    urls.length = 0;
    assert.equal(await TC.prefetch({placementId: 5, missionId: 9, userId: 3}), true);
    assert.equal(urls.length, 0, 'a document already kept is not fetched again');
    global.fetch = async () => ({json: async () => ({ok: true, document: {nope: 1}})});
    assert.equal(await TC.prefetch({placementId: 6, missionId: 9, userId: 3}), false);
    global.fetch = async () => { throw new Error('offline'); };
    assert.equal(await TC.prefetch({placementId: 7, missionId: 9, userId: 3}), false);
    delete global.fetch;
    delete global.localStorage;
});

test('caseStageFields carries the hidden spot of an area order', () => {
    const f = TC.caseStageFields({caseId: 4, mode: 'chosen', briefing: ' b ', spot: {lat: 35.405, lng: 25.205}});
    assert.equal(f.spot_lat, '35.405');
    assert.equal(f.spot_lng, '25.205');
    const plain = TC.caseStageFields({caseId: 4, mode: 'chosen', briefing: ''});
    assert.equal('spot_lat' in plain, false);
    assert.deepEqual(TC.caseStageFields({spot: {lat: 1, lng: 2}}), {});
});
