/**
 * Training case player (v3.505.0). The volunteer's full-screen case: story, findings, then one decision at a time with
 * immediate feedback. The pure rules (outcome of a choice, the run from a list of answers, scoring, merging answers, what
 * to do with the server's reply to a queued answer) are exported for Node (tests/js/training-case.test.js) and mirror
 * includes/functions-training-case-runs.php, which recomputes everything itself: what this page shows is a courtesy, the
 * server's answer is the record.
 *
 * Offline: the document is fetched once (training-case.php?action=load) and kept in localStorage; each answer is shown at
 * once from the document and queued ({kind: 'case_answer', ...}) for war-room.php's offline queue, which replays it.
 * Case text is untrusted plain text and ALWAYS goes through escapeHtml() before it reaches innerHTML.
 */
(function (root) {
    'use strict';

    const POINTS = {best: 2, acceptable: 1, harmful: 0};
    const SOS_HOLD_MS = 1000;

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    }

    function scoreLabel(points, max) {
        if (max <= 0) return 'practice';
        const ratio = points / max;
        return ratio >= 0.8 ? 'excellent' : (ratio >= 0.5 ? 'good' : 'practice');
    }

    function choiceOutcome(doc, stepId, choiceId) {
        const step = doc && doc.steps && doc.steps[stepId];
        const choice = step && Array.isArray(step.choices) && step.choices.find(c => c.id === choiceId);
        return choice ? {rating: choice.rating, feedback: choice.feedback, next: choice.next} : null;
    }

    /** Walks the case from its start through the answers that follow the path; anything else is ignored. */
    function runFromAnswers(doc, answers) {
        let current = doc.start, points = 0, max = 0, finished = false;
        const byStep = {};
        (answers || []).forEach(a => { if (!byStep[a.stepId]) byStep[a.stepId] = a; });
        while (current && byStep[current]) {
            const out = choiceOutcome(doc, current, byStep[current].choiceId);
            if (!out) break;
            points += POINTS[out.rating] || 0;
            max += 2;
            current = out.next;
            if (current === null) { finished = true; break; }
        }
        return {
            currentStep: finished ? null : current, finished: finished, points: points, max: max,
            percent: max > 0 ? Math.round(100 * points / max) : 0, label: scoreLabel(points, max),
        };
    }

    function bestPathLabels(doc) {
        const out = [];
        let current = doc.start;
        while (current && doc.steps[current]) {
            const best = doc.steps[current].choices.find(c => c.rating === 'best');
            if (!best) break;
            out.push({stepId: current, label: best.label});
            current = best.next;
        }
        return out;
    }

    /** Server answers win per step; answers only this phone has (not synced yet) are kept after them. */
    function mergeAnswers(serverAnswers, localAnswers) {
        const seen = {};
        const out = [];
        (serverAnswers || []).concat(localAnswers || []).forEach(a => {
            if (seen[a.stepId]) return;
            seen[a.stepId] = true;
            out.push({stepId: a.stepId, choiceId: a.choiceId});
        });
        return out;
    }

    function withoutAnswer(answers, stepId) {
        return (answers || []).filter(a => a.stepId !== stepId);
    }

    function answerQueueItem(placementId, stepId, choiceId, ms) {
        return {kind: 'case_answer', placementId: placementId, stepId: stepId, choiceId: choiceId, ms: ms || 0, at: Date.now()};
    }

    function storageKeys(placementId) {
        return {doc: 'wr_case_doc_' + placementId, answers: 'wr_case_answers_' + placementId};
    }

    /**
     * What war-room.php's offline queue does with the server's reply to a queued answer: 'sent' (success, or a duplicate of
     * an answer that already landed), 'retry' (keep it queued) or 'drop' (the server explicitly refused it).
     *
     * Retry covers everything that is not the server judging THIS answer: no signal (networkError), an expired login (the
     * page passes null for a redirect to the login page), a stale page token, and the endpoint's own «cannot take anything
     * now» errors - a mission that is closed or not found, a server error. Those say nothing about the answer and end by
     * themselves; dropping the answer there would lose what the volunteer did. `retryableErrors` is those messages as the
     * server words them (the page builds the list with t()).
     */
    function queueOutcome(result, retryableErrors) {
        if (!result || result.networkError || result.csrf) return 'retry';
        if (result.ok) return 'sent';
        if (result.error && (retryableErrors || []).indexOf(result.error) !== -1) return 'retry';
        return 'drop';
    }

    /**
     * A run that lags: the server's run still stands at a step whose answer is already stored (or already given on this
     * phone). Replaying that answer makes the server finish the run's update (it answers duplicate:true with the right run)
     * so the NEXT answer is not refused as «not valid now». Returns {stepId, choiceId} or null.
     */
    function replayCandidate(serverRun, localAnswers) {
        if (!serverRun || serverRun.finished || !serverRun.current_step) return null;
        const step = serverRun.current_step;
        const stored = (serverRun.answers || []).find(a => a.step_id === step);
        if (stored) return {stepId: step, choiceId: stored.choice_id};
        const local = (localAnswers || []).find(a => a.stepId === step);
        return local ? {stepId: step, choiceId: local.choiceId} : null;
    }

    /** Takes one step's answer out of the phone's own list (the server refused it: the volunteer can answer again). */
    function forgetLocalAnswer(placementId, stepId) {
        try {
            const key = storageKeys(placementId).answers;
            const kept = withoutAnswer(JSON.parse(localStorage.getItem(key) || '[]'), stepId);
            localStorage.setItem(key, JSON.stringify(kept));
        } catch (e) { /* nothing kept, nothing to forget */ }
    }

    function validDoc(doc) {
        return !!(doc && typeof doc === 'object' && doc.steps && typeof doc.steps === 'object' && doc.start && doc.steps[doc.start]);
    }

    // ── The overlay (browser only) ─────────────────────────────────────────
    function open(opts) {
        const t = opts.t;
        const keys = storageKeys(opts.placementId);
        const read = k => { try { return JSON.parse(localStorage.getItem(k) || 'null'); } catch (e) { return null; } };
        const write = (k, v) => { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) { /* storage full: the server still has the answers */ } };

        let doc = read(keys.doc);
        if (!validDoc(doc)) doc = null;
        let answers = (read(keys.answers) || []).filter(a => a && a.stepId && a.choiceId);
        let stepStart = Date.now();
        let pendingOutcome = null;   // {stepId, choiceId, outcome} shown, waiting for «Συνέχεια»
        let started = answers.length > 0;
        let sosTimer = null;
        const replayed = {};

        const overlay = document.createElement('div');
        overlay.id = 'voCaseOverlay';
        overlay.setAttribute('role', 'dialog');
        overlay.style.cssText = 'position:fixed;inset:0;z-index:2000;background:#fff;color:#212529;overflow-y:auto;-webkit-overflow-scrolling:touch;';
        document.body.appendChild(overlay);
        document.body.style.overflow = 'hidden';

        function close() {
            if (sosTimer) clearTimeout(sosTimer);
            overlay.remove();
            document.body.style.overflow = '';
            if (typeof opts.onClose === 'function') opts.onClose();
        }

        function setMessage(text) {
            const el = overlay.querySelector('[data-vc="msg"]');
            if (!el) return;
            el.textContent = text || '';
            el.classList.toggle('d-none', !text);
        }

        // SOS stays reachable (the overlay covers the page's own button): a real emergency beats the exercise. Held for a
        // second like the page's own SOS, so a thumb on the way to an answer cannot call for help by accident.
        function bindSos(btn) {
            const cancel = () => {
                if (!sosTimer) return;
                clearTimeout(sosTimer);
                sosTimer = null;
                setMessage(t('tabs.sos_hold_hint'));
            };
            btn.addEventListener('pointerdown', e => {
                e.preventDefault();
                if (sosTimer) return;
                sosTimer = setTimeout(() => {
                    sosTimer = null;
                    if (navigator.vibrate) navigator.vibrate([90, 60, 90]);
                    opts.onSos();
                    setMessage(t('tabs.sos_sent'));
                }, SOS_HOLD_MS);
            });
            ['pointerup', 'pointerleave', 'pointercancel'].forEach(ev => btn.addEventListener(ev, cancel));
            btn.addEventListener('contextmenu', e => e.preventDefault());
        }

        function shell(inner) {
            overlay.innerHTML = '<div class="container py-3" style="max-width:640px;">'
                + '<div class="alert alert-warning py-2 d-flex justify-content-between align-items-center mb-3 gap-2">'
                + '<span><i class="bi bi-mortarboard-fill me-1"></i>' + escapeHtml(t('case.banner')) + '</span>'
                + '<span class="d-flex gap-1 flex-shrink-0">' + (opts.onSos ? '<button type="button" class="btn btn-sm btn-danger" data-vc="sos" style="touch-action:none;user-select:none;">SOS</button>' : '')
                + '<button type="button" class="btn btn-sm btn-outline-dark" data-vc="stop">' + escapeHtml(t('case.stop')) + '</button></span></div>'
                + '<div class="small fw-semibold text-danger mb-2 d-none" data-vc="msg" role="status"></div>'
                + inner + '</div>';
            overlay.querySelector('[data-vc="stop"]').addEventListener('click', close);
            const sos = overlay.querySelector('[data-vc="sos"]');
            if (sos) bindSos(sos);
        }

        function findingsHtml() {
            if (!doc.findings || !doc.findings.length) return '';
            return '<table class="table table-sm mb-3"><tbody>' + doc.findings.map(f =>
                '<tr><th class="fw-semibold">' + escapeHtml(f.label) + '</th><td>' + escapeHtml(f.value) + (f.unit ? ' ' + escapeHtml(f.unit) : '') + '</td></tr>').join('') + '</tbody></table>';
        }

        function ratingBadge(rating) {
            const cls = {best: 'bg-success', acceptable: 'bg-warning text-dark', harmful: 'bg-danger'}[rating] || 'bg-secondary';
            return '<span class="badge ' + cls + ' fs-6 mb-2">' + escapeHtml(t('case.rating.' + rating)) + '</span>';
        }

        function render() {
            if (!doc) return;
            const run = runFromAnswers(doc, answers);
            if (pendingOutcome) return renderFeedback();
            if (run.finished) return renderEnd(run);
            if (!started) return renderIntro();
            if (!doc.steps[run.currentStep]) return renderUnavailable();
            return renderStep(run);
        }

        function renderIntro() {
            shell('<h4>' + escapeHtml(doc.title) + '</h4>'
                + (opts.briefing ? '<p class="text-muted">' + escapeHtml(opts.briefing) + '</p>' : '')
                + '<p style="white-space:pre-line;">' + escapeHtml(doc.story) + '</p>' + findingsHtml()
                + '<button type="button" class="btn btn-primary btn-lg w-100" data-vc="begin">' + escapeHtml(t('case.begin')) + '</button>');
            overlay.querySelector('[data-vc="begin"]').addEventListener('click', () => { started = true; stepStart = Date.now(); render(); });
        }

        function renderStep(run) {
            const step = doc.steps[run.currentStep];
            shell('<div class="small text-muted mb-1">' + escapeHtml(doc.title) + '</div>'
                + '<h5 class="mb-3">' + escapeHtml(step.prompt) + '</h5>'
                + (step.hint ? '<p class="small text-muted">' + escapeHtml(step.hint) + '</p>' : '')
                + step.choices.map(c => '<button type="button" class="btn btn-outline-primary btn-lg w-100 text-start mb-2" data-vc-choice="' + escapeHtml(c.id) + '">' + escapeHtml(c.label) + '</button>').join(''));
            overlay.querySelectorAll('[data-vc-choice]').forEach(btn => btn.addEventListener('click', () => answer(run.currentStep, btn.dataset.vcChoice)));
        }

        function answer(stepId, choiceId) {
            const out = choiceOutcome(doc, stepId, choiceId);
            if (!out) return;
            const ms = Date.now() - stepStart;
            answers = mergeAnswers(answers, [{stepId: stepId, choiceId: choiceId}]);
            write(keys.answers, answers);
            opts.enqueue(answerQueueItem(opts.placementId, stepId, choiceId, ms));
            if (typeof opts.flush === 'function') opts.flush();
            pendingOutcome = {stepId: stepId, choiceId: choiceId, outcome: out};
            render();
        }

        function renderFeedback() {
            const o = pendingOutcome.outcome;
            shell(ratingBadge(o.rating) + '<p class="fs-5">' + escapeHtml(o.feedback) + '</p>'
                + '<button type="button" class="btn btn-primary btn-lg w-100" data-vc="next">' + escapeHtml(t('case.continue')) + '</button>');
            overlay.querySelector('[data-vc="next"]').addEventListener('click', () => { pendingOutcome = null; stepStart = Date.now(); render(); });
        }

        function renderEnd(run) {
            const mine = answers.map(a => {
                const out = choiceOutcome(doc, a.stepId, a.choiceId);
                const choice = out && doc.steps[a.stepId].choices.find(c => c.id === a.choiceId);
                return out ? '<li class="mb-1">' + escapeHtml(choice.label) + ' ' + ratingBadge(out.rating) + '</li>' : '';
            }).join('');
            const best = bestPathLabels(doc).map(b => '<li>' + escapeHtml(b.label) + '</li>').join('');
            shell('<h4>' + escapeHtml(t('case.end_title')) + '</h4>'
                + '<p class="fs-3 mb-1">' + run.points + ' / ' + run.max + ' (' + run.percent + '%)</p>'
                + '<p class="fw-semibold">' + escapeHtml(t('case.label.' + run.label)) + '</p>'
                + '<h6>' + escapeHtml(t('case.your_path')) + '</h6><ul class="small">' + mine + '</ul>'
                + '<h6>' + escapeHtml(t('case.best_path')) + '</h6><ul class="small">' + best + '</ul>'
                + (doc.closing ? '<h6>' + escapeHtml(t('case.lessons')) + '</h6><p style="white-space:pre-line;">' + escapeHtml(doc.closing) + '</p>' : '')
                + '<button type="button" class="btn btn-primary btn-lg w-100" data-vc="done">' + escapeHtml(t('case.close')) + '</button>');
            overlay.querySelector('[data-vc="done"]').addEventListener('click', close);
        }

        // «Το περιστατικό δεν είναι διαθέσιμο» with a retry: the placement is recorded just after the order, so a phone
        // that asks in the first seconds (or one with no signal and no copy yet) may simply be early.
        function renderUnavailable() {
            shell('<p class="text-danger fw-semibold">' + escapeHtml(t('case.err_gone')) + '</p>'
                + '<button type="button" class="btn btn-primary btn-lg w-100" data-vc="retry">' + escapeHtml(t('case.retry')) + '</button>');
            overlay.querySelector('[data-vc="retry"]').addEventListener('click', () => {
                shell('<p class="text-muted">' + escapeHtml(t('case.loading')) + '</p>');
                load().then(render, renderUnavailable);
            });
        }

        function load() {
            const url = 'training-case.php?action=load&mission_id=' + encodeURIComponent(opts.missionId) + '&placement_id=' + encodeURIComponent(opts.placementId);
            // A login redirect or a proxy page is not JSON: r.json() throws and the caller treats it like no answer.
            return fetch(url).then(r => r.json()).then(res => {
                if (!res || !res.ok || !validDoc(res.document)) throw new Error((res && res.error) || 'load');
                doc = res.document;
                write(keys.doc, doc);
                const run = res.run || null;
                const serverAnswers = ((run && run.answers) || []).map(a => ({stepId: a.step_id, choiceId: a.choice_id}));
                answers = mergeAnswers(serverAnswers, answers);
                write(keys.answers, answers);
                if (answers.length) started = true;
                // The server's run may lag behind an answer it already holds; replaying it lets the server catch up.
                const again = replayCandidate(run, answers);
                const queued = typeof opts.isQueued === 'function' ? opts.isQueued(opts.placementId, again && again.stepId) : false;
                if (again && !replayed[again.stepId] && !queued) {
                    replayed[again.stepId] = true;
                    opts.enqueue(answerQueueItem(opts.placementId, again.stepId, again.choiceId, 0));
                    if (typeof opts.flush === 'function') opts.flush();
                }
            });
        }

        shell('<p class="text-muted">' + escapeHtml(t('case.loading')) + '</p>');
        // A document kept from an earlier visit opens at once (and works with no signal); the server's copy refreshes it.
        if (doc) { render(); load().then(render, () => {}); }
        else load().then(render, renderUnavailable);
        return {close: close};
    }

    const api = {
        escapeHtml, scoreLabel, choiceOutcome, runFromAnswers, bestPathLabels, mergeAnswers, withoutAnswer, answerQueueItem,
        storageKeys, queueOutcome, replayCandidate, forgetLocalAnswer, open,
    };
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    root.VoTrainingCase = api;
})(typeof window !== 'undefined' ? window : globalThis);
