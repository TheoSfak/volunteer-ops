// Run with: node --test tests/js
const test = require('node:test');
const assert = require('node:assert/strict');
const E = require('../../assets/js/training-case-editor.js');

const ID_RE = /^[a-z][a-z0-9_]{0,23}$/;

function form() {
    return {
        title: 'T', story: 'S', closing: 'C',
        findings: [{label: 'L', value: 'V', unit: ''}],
        steps: [
            {id: 's1', prompt: 'P', hint: '', choices: [
                {id: 'a', label: 'A', rating: 'best', feedback: 'F', next: 's2'},
                {id: 'b', label: 'B', rating: 'harmful', feedback: 'F', next: ''}]},
            {id: 's2', prompt: 'Q', hint: 'h', choices: [
                {id: 'a', label: 'A', rating: 'best', feedback: 'F', next: 's3'},
                {id: 'b', label: 'B', rating: 'acceptable', feedback: 'F', next: 's1'}]},
            {id: 's3', prompt: 'R', hint: '', choices: [
                {id: 'a', label: 'A', rating: 'best', feedback: 'F', next: ''},
                {id: 'b', label: 'B', rating: 'harmful', feedback: 'F', next: ''}]},
        ],
    };
}

test('buildDocument makes the first step the start and a map of steps', () => {
    const doc = E.buildDocument({
        title: 'T', story: 'S', closing: 'C', findings: [{label: 'L', value: 'V', unit: ''}, {label: '', value: '', unit: ''}],
        steps: [
            {id: 's1', prompt: 'P', hint: '', choices: [{id: 'a', label: 'A', rating: 'best', feedback: 'F', next: 's2'}, {id: 'b', label: 'B', rating: 'harmful', feedback: 'F', next: ''}]},
            {id: 's2', prompt: 'Q', hint: 'h', choices: []},
        ],
    });
    assert.equal(doc.start, 's1');
    assert.deepEqual(Object.keys(doc.steps), ['s1', 's2']);
    assert.equal(doc.findings.length, 1, 'an empty finding row is dropped');
    assert.equal(doc.steps.s1.choices[1].next, null, 'an empty next means the end');
});

test('buildDocument serialises to the document shape and survives JSON', () => {
    const doc = E.buildDocument(form());
    assert.deepEqual(Object.keys(doc), ['title', 'story', 'closing', 'findings', 'start', 'steps']);
    assert.deepEqual(Object.keys(doc.steps.s1), ['prompt', 'hint', 'choices']);
    assert.deepEqual(Object.keys(doc.steps.s1.choices[0]), ['id', 'label', 'rating', 'feedback', 'next']);
    assert.deepEqual(JSON.parse(JSON.stringify(doc)), doc);
    assert.equal(E.buildDocument({}).start, '', 'an empty form has no start');
});

test('nextStepId and nextChoiceId pick unused ids', () => {
    assert.equal(E.nextStepId(['s1', 's2']), 's3');
    assert.equal(E.nextStepId([]), 's1');
    assert.equal(E.nextStepId(['s1', 's3']), 's2');
    assert.equal(E.nextChoiceId(['a', 'b']), 'c');
    assert.equal(E.nextChoiceId([]), 'a');
    assert.ok(ID_RE.test(E.nextStepId(['s1'])));
    const letters = 'abcdefghijklmnopqrstuvwxyz'.split('');
    const spare = E.nextChoiceId(letters);
    assert.ok(ID_RE.test(spare), 'still a valid id when every letter is taken');
    assert.ok(!letters.includes(spare));
});

test('isValidId matches the server rule', () => {
    ['s1', 'a', 'step_2', 'a'.repeat(24)].forEach(id => assert.equal(E.isValidId(id), true, id));
    ['', '1s', 'S1', 'a-b', 'a b', 'a'.repeat(25), 'é', null, undefined, 5].forEach(id => assert.equal(E.isValidId(id), false, String(id)));
});

test('addStep appends a step with a fresh valid id and two choices, leaving the input alone', () => {
    const f = form();
    const g = E.addStep(f);
    assert.equal(f.steps.length, 3, 'the input is not changed');
    assert.equal(g.steps.length, 4);
    const added = g.steps[3];
    assert.equal(added.id, 's4');
    assert.ok(E.isValidId(added.id));
    assert.equal(added.choices.length, 2);
    assert.notEqual(added.choices[0].id, added.choices[1].id);
    assert.ok(added.choices.some(c => c.rating === 'best'), 'a new step starts with a best choice');
    assert.ok(added.choices.every(c => c.next === ''));
});

test('addStep stops at the server limit of 12 steps', () => {
    let f = form();
    for (let i = 0; i < 20; i++) f = E.addStep(f);
    assert.equal(f.steps.length, 12);
    assert.equal(new Set(f.steps.map(s => s.id)).size, 12, 'ids stay unique');
});

test('removeStep drops the step and turns every next that pointed at it into the end', () => {
    const g = E.removeStep(form(), 's3');
    assert.deepEqual(g.steps.map(s => s.id), ['s1', 's2']);
    assert.equal(g.steps[1].choices[0].next, '', 'was s3');
    assert.equal(g.steps[1].choices[1].next, 's1', 'other references are kept');
    assert.equal(g.steps[0].choices[0].next, 's2');
});

test('removeStep never removes the last step', () => {
    let f = form();
    f = E.removeStep(E.removeStep(f, 's1'), 's2');
    assert.deepEqual(f.steps.map(s => s.id), ['s3']);
    assert.deepEqual(E.removeStep(f, 's3').steps.map(s => s.id), ['s3']);
});

test('moveStep swaps neighbours (the first step is the start) and ignores the edges', () => {
    const f = form();
    assert.deepEqual(E.moveStep(f, 1, -1).steps.map(s => s.id), ['s2', 's1', 's3']);
    assert.deepEqual(E.moveStep(f, 1, 1).steps.map(s => s.id), ['s1', 's3', 's2']);
    assert.deepEqual(E.moveStep(f, 0, -1).steps.map(s => s.id), ['s1', 's2', 's3']);
    assert.deepEqual(E.moveStep(f, 2, 1).steps.map(s => s.id), ['s1', 's2', 's3']);
    assert.equal(E.buildDocument(f).start, 's1');
    assert.equal(E.buildDocument(E.moveStep(f, 1, -1)).start, 's2', 'moving a step first makes it the start');
});

test('addChoice adds the next letter, up to 5; removeChoice keeps at least 2', () => {
    let f = form();
    f = E.addChoice(f, 's1');
    assert.deepEqual(f.steps[0].choices.map(c => c.id), ['a', 'b', 'c']);
    f = E.addChoice(E.addChoice(E.addChoice(f, 's1'), 's1'), 's1');
    assert.equal(f.steps[0].choices.length, 5, 'capped at 5');
    f = E.removeChoice(f, 's1', 'c');
    assert.deepEqual(f.steps[0].choices.map(c => c.id), ['a', 'b', 'd', 'e']);
    f = E.removeChoice(E.removeChoice(E.removeChoice(f, 's1', 'a'), 's1', 'b'), 's1', 'd');
    assert.equal(f.steps[0].choices.length, 2, 'never below 2');
    assert.equal(E.addChoice(form(), 'nope').steps.length, 3, 'an unknown step changes nothing');
});

test('renameStep keeps every next reference pointing at the renamed step', () => {
    const r = E.renameStep(form(), 's3', 'finale');
    assert.equal(r.ok, true);
    assert.deepEqual(r.form.steps.map(s => s.id), ['s1', 's2', 'finale']);
    assert.equal(r.form.steps[1].choices[0].next, 'finale');
    assert.equal(r.form.steps[1].choices[1].next, 's1', 'unrelated references are untouched');
    const doc = E.buildDocument(r.form);
    assert.ok(doc.steps.finale);
    assert.equal(doc.steps.s2.choices[0].next, 'finale');
});

test('renameStep of the start step moves the start with it', () => {
    const r = E.renameStep(form(), 's1', 'first');
    assert.equal(E.buildDocument(r.form).start, 'first');
    assert.equal(r.form.steps[1].choices[1].next, 'first');
});

test('renameStep refuses an invalid or taken id and changes nothing', () => {
    const f = form();
    ['S9', '9x', '', 'a-b', 'x'.repeat(25), 's2'].forEach(bad => {
        const r = E.renameStep(f, 's1', bad);
        assert.equal(r.ok, false, bad);
        assert.deepEqual(r.form, f, bad);
    });
    assert.equal(E.renameStep(f, 's1', 's1').ok, true, 'the same id is a no-op that is fine');
    assert.equal(E.renameStep(f, 'nope', 'zzz').ok, false);
});

test('the input form is never mutated by any operation', () => {
    const f = form();
    const before = JSON.stringify(f);
    E.addStep(f); E.removeStep(f, 's2'); E.moveStep(f, 1, -1); E.addChoice(f, 's1'); E.removeChoice(f, 's1', 'a'); E.renameStep(f, 's1', 'zz');
    assert.equal(JSON.stringify(f), before);
});

test('addFinding and removeFinding edit the rows, capped at 12', () => {
    let f = form();
    f = E.addFinding(f);
    assert.deepEqual(f.findings[1], {label: '', value: '', unit: ''});
    for (let i = 0; i < 20; i++) f = E.addFinding(f);
    assert.equal(f.findings.length, 12);
    f = E.removeFinding(f, 0);
    assert.equal(f.findings.length, 11);
    assert.equal(E.removeFinding(f, 99).findings.length, 11, 'an index that is not there changes nothing');
    assert.equal(E.removeFinding(f, -1).findings.length, 11);
});

test('normalizeModel fills ids and shapes for a model that came from the server', () => {
    const m = E.normalizeModel({title: 'T', steps: [{id: '', prompt: 'P', choices: [{label: 'x', rating: 'bogus'}, {id: 'a'}]}, {id: 's1'}], findings: null});
    assert.equal(m.steps[0].id, 's2', 's1 is taken by the next step');
    assert.equal(m.steps[1].id, 's1');
    assert.ok(m.steps[0].choices.every(c => E.isValidId(c.id)));
    assert.notEqual(m.steps[0].choices[0].id, m.steps[0].choices[1].id);
    assert.equal(m.steps[0].choices[0].rating, 'acceptable', 'an unknown rating becomes a choice the author must look at');
    assert.deepEqual(m.findings, []);
    assert.equal(E.normalizeModel(null).steps.length, 1, 'an empty model starts with one step');
});
