/**
 * Training case editor (v3.505.0). One plain form: details, story, findings as rows, ordered step cards with 2 to 5
 * choices each, closing lessons. No drag and drop: steps move with up/down buttons, and the FIRST step is the start.
 *
 * The first half of this file is pure (the model of the form and the operations on it, exported for Node:
 * tests/js/training-case-editor.test.js). Every operation returns a NEW model and never touches its input. The second
 * half is the browser wiring: it draws the findings and the step cards from the model with createElement/textContent
 * (case text is never parsed as HTML), writes the document as JSON into the hidden `document` field on submit, and
 * plays the case through the player (assets/js/training-case.js, preview mode) for the «Δοκιμή» button.
 *
 * The server (trainingCaseSave) validates everything again, so nothing here is a gate.
 */
(function (root) {
    'use strict';

    const ID_RE = /^[a-z][a-z0-9_]{0,23}$/;
    const MAX_STEPS = 12;       // mirrors TRAINING_CASE_MAX_STEPS
    const MAX_FINDINGS = 12;    // mirrors TRAINING_CASE_MAX_FINDINGS
    const MIN_CHOICES = 2;
    const MAX_CHOICES = 5;
    const RATINGS = ['best', 'acceptable', 'harmful'];

    // ── Pure: ids ──────────────────────────────────────────────────────────
    function isValidId(id) {
        return typeof id === 'string' && ID_RE.test(id);
    }

    /** s<n>, the lowest n whose id is not in use. */
    function nextStepId(ids) {
        const used = ids || [];
        let n = 1;
        while (used.indexOf('s' + n) !== -1) n++;
        return 's' + n;
    }

    /** The next unused letter; past z, x<n> (still a valid id). */
    function nextChoiceId(ids) {
        const used = ids || [];
        for (let code = 97; code < 123; code++) {
            const id = String.fromCharCode(code);
            if (used.indexOf(id) === -1) return id;
        }
        let n = used.length;
        while (used.indexOf('x' + n) !== -1) n++;
        return 'x' + n;
    }

    // ── Pure: the document ─────────────────────────────────────────────────
    function buildDocument(form) {
        const steps = {};
        (form.steps || []).forEach(step => {
            steps[step.id] = {
                prompt: step.prompt || '', hint: step.hint || '',
                choices: (step.choices || []).map(c => ({id: c.id, label: c.label || '', rating: c.rating, feedback: c.feedback || '', next: c.next ? c.next : null})),
            };
        });
        return {
            title: form.title || '', story: form.story || '', closing: form.closing || '',
            findings: (form.findings || []).filter(f => (f.label || '').trim() !== '' && (f.value || '').trim() !== ''),
            start: form.steps && form.steps.length ? form.steps[0].id : '',
            steps: steps,
        };
    }

    // ── Pure: the model and its operations ─────────────────────────────────
    const clone = v => JSON.parse(JSON.stringify(v));
    const text = v => (typeof v === 'string' ? v : (typeof v === 'number' ? String(v) : ''));

    function newChoice(id, rating) {
        return {id: id, label: '', rating: rating, feedback: '', next: ''};
    }

    function newStep(id) {
        return {id: id, prompt: '', hint: '', choices: [newChoice('a', 'best'), newChoice('b', 'harmful')]};
    }

    /**
     * Reads whatever the server put in the page (or null) into the one model shape: every id valid and unique, every
     * rating one of the three, at least one step. An unknown rating becomes «acceptable»: a value the author sees and
     * decides on, never a silent «best».
     */
    function normalizeModel(raw) {
        const src = raw && typeof raw === 'object' ? raw : {};
        const rawSteps = Array.isArray(src.steps) ? src.steps.filter(s => s && typeof s === 'object') : [];
        const used = [];
        rawSteps.forEach(s => { if (isValidId(s.id) && used.indexOf(s.id) === -1) used.push(s.id); });
        const taken = [];
        const steps = rawSteps.map(s => {
            let id = isValidId(s.id) && taken.indexOf(s.id) === -1 ? s.id : nextStepId(used);
            if (used.indexOf(id) === -1) used.push(id);
            taken.push(id);
            const choiceIds = (Array.isArray(s.choices) ? s.choices : []).filter(c => c && typeof c === 'object').map(c => c.id).filter(isValidId);
            const seen = [];
            const choices = (Array.isArray(s.choices) ? s.choices : []).filter(c => c && typeof c === 'object').map(c => {
                let cid = isValidId(c.id) && seen.indexOf(c.id) === -1 ? c.id : nextChoiceId(choiceIds.concat(seen));
                seen.push(cid);
                return {
                    id: cid, label: text(c.label), rating: RATINGS.indexOf(c.rating) !== -1 ? c.rating : 'acceptable',
                    feedback: text(c.feedback), next: isValidId(c.next) ? c.next : '',
                };
            });
            return {id: id, prompt: text(s.prompt), hint: text(s.hint), choices: choices};
        });
        const findings = (Array.isArray(src.findings) ? src.findings : []).filter(f => f && typeof f === 'object')
            .map(f => ({label: text(f.label), value: text(f.value), unit: text(f.unit)}));
        if (!steps.length) steps.push(newStep('s1'));
        return {title: text(src.title), story: text(src.story), closing: text(src.closing), findings: findings, steps: steps};
    }

    function addStep(form) {
        const f = clone(form);
        if (f.steps.length >= MAX_STEPS) return f;
        f.steps.push(newStep(nextStepId(f.steps.map(s => s.id))));
        return f;
    }

    /** Removes a step (never the last one) and turns every next that pointed at it into the end. */
    function removeStep(form, stepId) {
        const f = clone(form);
        if (f.steps.length <= 1 || !f.steps.some(s => s.id === stepId)) return f;
        f.steps = f.steps.filter(s => s.id !== stepId);
        f.steps.forEach(s => s.choices.forEach(c => { if (c.next === stepId) c.next = ''; }));
        return f;
    }

    /** Swaps a step with its neighbour (delta -1 up, +1 down). The step that ends up first is the start. */
    function moveStep(form, index, delta) {
        const f = clone(form);
        const to = index + delta;
        if (index < 0 || index >= f.steps.length || to < 0 || to >= f.steps.length) return f;
        const moved = f.steps[index];
        f.steps[index] = f.steps[to];
        f.steps[to] = moved;
        return f;
    }

    function addChoice(form, stepId) {
        const f = clone(form);
        const step = f.steps.find(s => s.id === stepId);
        if (!step || step.choices.length >= MAX_CHOICES) return f;
        step.choices.push(newChoice(nextChoiceId(step.choices.map(c => c.id)), 'acceptable'));
        return f;
    }

    function removeChoice(form, stepId, choiceId) {
        const f = clone(form);
        const step = f.steps.find(s => s.id === stepId);
        if (!step || step.choices.length <= MIN_CHOICES) return f;
        step.choices = step.choices.filter(c => c.id !== choiceId);
        return f;
    }

    /** Renames a step id and every next that points at it. {ok, form}: an invalid or taken id changes nothing. */
    function renameStep(form, oldId, newId) {
        const f = clone(form);
        if (!f.steps.some(s => s.id === oldId) || !isValidId(newId)) return {ok: false, form: f};
        if (newId === oldId) return {ok: true, form: f};
        if (f.steps.some(s => s.id === newId)) return {ok: false, form: f};
        f.steps.forEach(s => {
            if (s.id === oldId) s.id = newId;
            s.choices.forEach(c => { if (c.next === oldId) c.next = newId; });
        });
        return {ok: true, form: f};
    }

    function addFinding(form) {
        const f = clone(form);
        if (f.findings.length < MAX_FINDINGS) f.findings.push({label: '', value: '', unit: ''});
        return f;
    }

    function removeFinding(form, index) {
        const f = clone(form);
        if (index >= 0 && index < f.findings.length) f.findings.splice(index, 1);
        return f;
    }

    // ── Browser wiring ─────────────────────────────────────────────────────
    function init(formEl) {
        const doc = formEl.ownerDocument;
        const parse = name => { try { return JSON.parse(formEl.getAttribute(name) || 'null'); } catch (e) { return null; } };
        const labels = parse('data-labels') || {};
        const playerLabels = parse('data-player-labels') || {};
        let model = normalizeModel(parse('data-model'));
        let uidCounter = 0;
        const uid = () => 'vcEd' + (++uidCounter);
        const L = key => (labels[key] != null ? labels[key] : key);

        const findingsBox = formEl.querySelector('#caseFindings');
        const stepsBox = formEl.querySelector('#caseSteps');
        const addFindingBtn = formEl.querySelector('#addFinding');
        const addStepBtn = formEl.querySelector('#addStep');
        const tryBtn = formEl.querySelector('#tryOut');
        const msgEl = doc.getElementById('caseEditorMsg');   // sits above the form, not inside it
        const hidden = formEl.querySelector('input[name="document"]');
        let nextSelects = [];

        function showMsg(message) {
            if (!msgEl) return;
            msgEl.textContent = message || '';
            msgEl.classList.toggle('d-none', !message);
        }

        function el(tag, props, kids) {
            const node = doc.createElement(tag);
            Object.keys(props || {}).forEach(k => {
                const v = props[k];
                if (v === false || v == null) return;
                if (k === 'class') node.className = v;
                else if (k === 'text') node.textContent = v;
                else if (k === 'value') node.value = v;
                else if (k.slice(0, 2) === 'on') node.addEventListener(k.slice(2), v);
                else node.setAttribute(k, v === true ? '' : v);
            });
            (kids || []).forEach(kid => { if (kid) node.appendChild(kid); });
            return node;
        }

        function field(labelText, control) {
            const id = uid();
            control.id = id;
            return el('div', {class: 'mb-2'}, [el('label', {class: 'form-label small mb-1', for: id, text: labelText}), control]);
        }

        function textInput(value, max, onInput, extra) {
            return el('input', Object.assign({type: 'text', class: 'form-control form-control-sm', maxlength: max, value: value, oninput: e => onInput(e.target.value)}, extra || {}));
        }

        function textArea(value, max, rows, onInput) {
            return el('textarea', {class: 'form-control form-control-sm', maxlength: max, rows: rows, value: value, oninput: e => onInput(e.target.value)});
        }

        function iconButton(icon, title, onClick, disabled, cls) {
            return el('button', {type: 'button', class: 'btn btn-sm ' + (cls || 'btn-outline-secondary'), title: title, 'aria-label': title, disabled: disabled, onclick: onClick},
                [el('i', {class: 'bi ' + icon, 'aria-hidden': 'true'})]);
        }

        function excerpt(s) { return s.length > 40 ? s.slice(0, 40) + '…' : s; }

        function fillNext(entry) {
            const {select, step, choice} = entry;
            select.textContent = '';
            select.appendChild(el('option', {value: '', text: L('end')}));
            model.steps.forEach((s, i) => {
                if (s.id === step.id) return;
                select.appendChild(el('option', {value: s.id, text: (i + 1) + '. ' + s.id + (s.prompt ? ' - ' + excerpt(s.prompt) : '')}));
            });
            select.value = choice.next;
            if (select.value !== choice.next) choice.next = select.value;   // pointed at nothing real: shows (and posts) «Τέλος»
        }

        function updateNextOptions() { nextSelects.forEach(fillNext); }

        function rerender(focusSelector) {
            renderFindings();
            renderSteps();
            if (focusSelector) {
                const target = formEl.querySelector(focusSelector);
                if (target && target.focus) target.focus();
            }
        }

        function renderFindings() {
            findingsBox.textContent = '';
            model.findings.forEach((f, i) => {
                findingsBox.appendChild(el('div', {class: 'row g-2 align-items-end mb-1', 'data-finding': String(i)}, [
                    el('div', {class: 'col-12 col-md-4'}, [field(L('finding_label'), textInput(f.label, 60, v => { f.label = v; }))]),
                    el('div', {class: 'col-7 col-md-4'}, [field(L('finding_value'), textInput(f.value, 60, v => { f.value = v; }))]),
                    el('div', {class: 'col-5 col-md-2'}, [field(L('finding_unit'), textInput(f.unit, 20, v => { f.unit = v; }))]),
                    el('div', {class: 'col-12 col-md-2 mb-2'}, [iconButton('bi-trash', L('remove'), () => { model = removeFinding(model, i); rerender('#addFinding'); }, false, 'btn-outline-danger')]),
                ]));
            });
            if (addFindingBtn) addFindingBtn.disabled = model.findings.length >= MAX_FINDINGS;
        }

        function renderChoice(step, choice, canRemove) {
            const ratingSelect = el('select', {class: 'form-select form-select-sm', onchange: e => { choice.rating = e.target.value; }},
                RATINGS.map(r => el('option', {value: r, text: L('rating_' + r)})));
            ratingSelect.value = choice.rating;
            const nextSelect = el('select', {class: 'form-select form-select-sm', onchange: e => { choice.next = e.target.value; }});
            const entry = {select: nextSelect, step: step, choice: choice};
            nextSelects.push(entry);
            fillNext(entry);
            return el('div', {class: 'border rounded p-2 mb-2 bg-body-tertiary', 'data-choice': choice.id}, [
                el('div', {class: 'row g-2'}, [
                    el('div', {class: 'col-12 col-md-8'}, [field(L('choice_label'), textInput(choice.label, 200, v => { choice.label = v; }, {'data-f': 'label'}))]),
                    el('div', {class: 'col-12 col-md-4'}, [field(L('rating'), ratingSelect)]),
                ]),
                field(L('feedback'), textArea(choice.feedback, 600, 2, v => { choice.feedback = v; })),
                el('div', {class: 'row g-2 align-items-end'}, [
                    el('div', {class: 'col-9 col-md-10'}, [field(L('next'), nextSelect)]),
                    el('div', {class: 'col-3 col-md-2 mb-2 text-end'}, [iconButton('bi-x-lg', L('remove_choice'), () => {
                        model = removeChoice(model, step.id, choice.id); rerender();
                    }, !canRemove, 'btn-outline-danger')]),
                ]),
            ]);
        }

        function renderStep(step, index) {
            const total = model.steps.length;
            const idInput = el('input', {type: 'text', class: 'form-control form-control-sm d-inline-block', style: 'width:9rem;', maxlength: 24, value: step.id, 'aria-label': L('step_id'), title: L('step_id'), 'data-f': 'id',
                onchange: e => {
                    const wanted = e.target.value.trim();
                    const r = renameStep(model, step.id, wanted);
                    if (!r.ok) {
                        e.target.value = step.id;
                        showMsg(isValidId(wanted) ? L('id_taken') : L('bad_id'));
                        return;
                    }
                    showMsg('');
                    model = r.form;
                    rerender();
                }});
            const body = el('div', {class: 'card-body'}, [
                field(L('prompt'), textArea(step.prompt, 400, 2, v => { step.prompt = v; })),
                field(L('hint'), textInput(step.hint, 300, v => { step.hint = v; })),
                el('div', {class: 'fw-semibold small mb-1', text: L('choices')}),
                el('div', {'data-choices': step.id}, step.choices.map(c => renderChoice(step, c, step.choices.length > MIN_CHOICES))),
                el('button', {type: 'button', class: 'btn btn-sm btn-outline-primary', disabled: step.choices.length >= MAX_CHOICES, 'data-add-choice': step.id,
                    onclick: () => { model = addChoice(model, step.id); rerender('[data-choices="' + step.id + '"] [data-choice]:last-child [data-f="label"]'); }},
                    [el('i', {class: 'bi bi-plus-lg me-1', 'aria-hidden': 'true'}), doc.createTextNode(L('add_choice'))]),
            ]);
            const prompt = body.querySelector('textarea');
            prompt.addEventListener('change', updateNextOptions);
            return el('div', {class: 'card mb-3', 'data-step': step.id}, [
                el('div', {class: 'card-header d-flex flex-wrap align-items-center gap-2'}, [
                    el('strong', {text: L('step').replace('{n}', String(index + 1))}),
                    index === 0 ? el('span', {class: 'badge bg-primary', text: L('step_start')}) : null,
                    el('span', {class: 'ms-auto d-flex align-items-center gap-1'}, [
                        idInput,
                        iconButton('bi-arrow-up', L('move_up'), () => { model = moveStep(model, index, -1); rerender(); }, index === 0),
                        iconButton('bi-arrow-down', L('move_down'), () => { model = moveStep(model, index, 1); rerender(); }, index === total - 1),
                        iconButton('bi-trash', L('remove_step'), () => { model = removeStep(model, step.id); rerender('#addStep'); }, total <= 1, 'btn-outline-danger'),
                    ]),
                ]),
                body,
            ]);
        }

        function renderSteps() {
            nextSelects = [];
            stepsBox.textContent = '';
            model.steps.forEach((s, i) => stepsBox.appendChild(renderStep(s, i)));
            if (addStepBtn) addStepBtn.disabled = model.steps.length >= MAX_STEPS;
        }

        function syncDetails() {
            const read = id => { const node = formEl.querySelector('#' + id); return node ? node.value : ''; };
            model.title = read('caseTitle');
            model.story = read('caseStory');
            model.closing = read('caseClosing');
        }

        if (addFindingBtn) addFindingBtn.addEventListener('click', () => { model = addFinding(model); rerender('[data-finding]:last-child input'); });
        if (addStepBtn) addStepBtn.addEventListener('click', () => {
            model = addStep(model);
            rerender();
            const cards = stepsBox.querySelectorAll('[data-step]');
            const last = cards[cards.length - 1];
            if (last) { const p = last.querySelector('textarea'); if (p) p.focus(); }
        });

        // Enter in a one-line field moves nothing and saves nothing: a half-built case is easy to send by accident otherwise.
        formEl.addEventListener('keydown', e => {
            if (e.key === 'Enter' && e.target && e.target.tagName === 'INPUT' && e.target.type !== 'submit') e.preventDefault();
        });

        formEl.addEventListener('submit', () => {
            syncDetails();
            if (hidden) hidden.value = JSON.stringify(buildDocument(model));
        });

        if (tryBtn) tryBtn.addEventListener('click', () => {
            syncDetails();
            const player = root.VoTrainingCase;
            const document_ = buildDocument(model);
            if (!player || !player.validDoc(document_)) { showMsg(L('try_invalid')); return; }
            showMsg('');
            // Preview mode: no placement, no queue, no network, nothing stored on this device.
            player.open({preview: true, document: document_, placementId: 0, missionId: 0, userId: 0, briefing: '', t: k => (playerLabels[k] != null ? playerLabels[k] : k)});
        });

        rerender();
        return {getModel: () => model};
    }

    const api = {
        isValidId, nextStepId, nextChoiceId, buildDocument, normalizeModel,
        addStep, removeStep, moveStep, addChoice, removeChoice, renameStep, addFinding, removeFinding, init,
        MAX_STEPS, MAX_FINDINGS, MIN_CHOICES, MAX_CHOICES,
    };
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    root.VoTrainingCaseEditor = api;

    if (typeof document !== 'undefined') {
        const start = () => { const f = document.getElementById('caseEditorForm'); if (f) init(f); };
        if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
    }
})(typeof window !== 'undefined' ? window : globalThis);
