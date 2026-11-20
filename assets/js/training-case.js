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
        const visited = {};   // a document kept on the phone may loop: every step is walked at most once
        (answers || []).forEach(a => { if (!byStep[a.stepId]) byStep[a.stepId] = a; });
        while (current && byStep[current] && !visited[current]) {
            visited[current] = true;
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
        const visited = {};
        let current = doc.start;
        while (current && doc.steps[current] && !visited[current]) {
            visited[current] = true;
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

    /** Runs are per placement AND volunteer, so the phone's copies are too: on a shared phone B must not see A's answers. */
    function storageKeys(userId, placementId) {
        const suffix = userId + '_' + placementId;
        return {doc: 'wr_case_doc_' + suffix, answers: 'wr_case_answers_' + suffix};
    }

    /**
     * What war-room.php's offline queue does with the server's reply to a queued answer: 'sent' (success, or a duplicate of
     * an answer that already landed), 'retry' (keep it queued) or 'drop' (the server explicitly refused it).
     *
     * The default is RETRY: an answer is dropped only when the reply says ok:false AND carries the machine code 'refused'
     * (training-case.php puts that code on every refusal of the answer itself: not valid now, not the audience, not arrived,
     * not a drill...). No reply, no signal, an expired login (the page passes null), a stale page token, a closed mission
     * ('mission_closed'), a server error ('server_error'), a missing placement ('not_found'), a reply with no code or one
     * this page does not know (a newer or older server) all say nothing about THIS answer: dropping it would lose what the
     * volunteer did, so it stays queued. The text of the error is never compared: it is translated.
     */
    function queueOutcome(result) {
        if (!result || typeof result !== 'object' || result.networkError || result.csrf) return 'retry';
        if (result.ok) return 'sent';
        return result.code === 'refused' ? 'drop' : 'retry';
    }

    /** A cheap fingerprint of what the screen is built from: the case itself and where the run stands. */
    function viewSignature(doc, answers) {
        const run = runFromAnswers(doc, answers);
        return JSON.stringify([doc, run.currentStep, run.finished]);
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
    function forgetLocalAnswer(userId, placementId, stepId) {
        try {
            const key = storageKeys(userId, placementId).answers;
            const kept = withoutAnswer(JSON.parse(localStorage.getItem(key) || '[]'), stepId);
            localStorage.setItem(key, JSON.stringify(kept));
        } catch (e) { /* nothing kept, nothing to forget */ }
    }

    /**
     * The shape the player relies on, checked on everything read from localStorage or the network: a start step, every
     * step an object with a non-empty list of choices, every choice an object with a string id and label. A document that
     * fails is treated as missing (and fetched again) instead of throwing in the middle of a case.
     */
    function validDoc(doc) {
        if (!doc || typeof doc !== 'object' || Array.isArray(doc)) return false;
        // (An array is fine here: PHP encodes steps keyed "0", "1"... as a list, and steps[id] reads either.)
        if (!doc.steps || typeof doc.steps !== 'object') return false;
        if (!doc.start || !doc.steps[doc.start]) return false;
        if (doc.findings != null && !Array.isArray(doc.findings)) return false;
        return Object.keys(doc.steps).every(id => {
            const step = doc.steps[id];
            return !!step && typeof step === 'object' && Array.isArray(step.choices) && step.choices.length > 0
                && step.choices.every(c => !!c && typeof c === 'object' && typeof c.id === 'string' && typeof c.label === 'string');
        });
    }

    /**
     * What the overlay may do with the outside world. A try-out from the case editor ({preview: true, document}) plays the
     * document it is given and records nothing: no answer is queued for the server, nothing is fetched, nothing is kept
     * on the device. Only a real true previews (a truthy string does not).
     */
    function previewOptions(opts) {
        const preview = !!opts && opts.preview === true;
        return {enqueue: !preview, load: !preview};
    }

    // ── The overlay (browser only) ─────────────────────────────────────────
    function open(opts) {
        const t = opts.t;
        const mode = previewOptions(opts);
        // Per viewer and placement: runs are, and a phone may be shared (the old per-placement keys are simply not read).
        const keys = storageKeys(opts.userId, opts.placementId);
        const read = k => { if (!mode.load) return null; try { return JSON.parse(localStorage.getItem(k) || 'null'); } catch (e) { return null; } };
        const write = (k, v) => { if (!mode.load) return; try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) { /* storage full: the server still has the answers */ } };

        let doc = mode.load ? read(keys.doc) : opts.document;
        if (!validDoc(doc)) doc = null;
        let answers = (read(keys.answers) || []).filter(a => a && a.stepId && a.choiceId);
        let stepStart = Date.now();
        let pendingOutcome = null;   // {stepId, choiceId, outcome} shown, waiting for «Συνέχεια»
        let started = answers.length > 0;
        let sosTimer = null;
        let shownSignature = null;   // what the screen was last built from (null: nothing usable on screen)
        const replayed = {};
        const previousFocus = document.activeElement;

        const overlay = document.createElement('div');
        overlay.id = 'voCaseOverlay';
        overlay.setAttribute('role', 'dialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-label', t('case.dialog_label'));
        overlay.tabIndex = -1;
        overlay.style.cssText = 'position:fixed;inset:0;z-index:2000;background:#fff;color:#212529;overflow-y:auto;-webkit-overflow-scrolling:touch;outline:none;';
        document.body.appendChild(overlay);
        document.body.style.overflow = 'hidden';
        try { overlay.focus({preventScroll: true}); } catch (e) { /* focus is a courtesy */ }

        function close() {
            if (sosTimer) clearTimeout(sosTimer);
            overlay.remove();
            document.body.style.overflow = '';
            if (typeof opts.onClose === 'function') opts.onClose();
            // Back to where the volunteer was (if that control is still on the page).
            try { if (previousFocus && typeof previousFocus.focus === 'function' && document.contains(previousFocus)) previousFocus.focus({preventScroll: true}); } catch (e) { /* a courtesy */ }
        }

        function setMessage(text) {
            const el = overlay.querySelector('[data-vc="msg"]');
            if (!el) return;
            el.textContent = text || '';
            el.classList.toggle('d-none', !text);
        }

        // SOS stays reachable (the overlay covers the page's own button): a real emergency beats the exercise. Held for a
        // second like the page's own SOS, so a thumb on the way to an answer cannot call for help by accident. The same
        // one-second hold works from the keyboard (hold Enter or Space; a plain press does nothing), so it is no easier to
        // trigger by accident and needs no confirm dialog. opts.onSos() looks the page's real needs_help button up when it
        // FIRES (the page may have redrawn since the overlay opened) and returns false when there is none.
        function bindSos(btn) {
            const start = () => {
                if (sosTimer) return;
                sosTimer = setTimeout(() => {
                    sosTimer = null;
                    if (navigator.vibrate) navigator.vibrate([90, 60, 90]);
                    setMessage(opts.onSos() === false ? t('common.failed') : t('tabs.sos_sent'));
                }, SOS_HOLD_MS);
            };
            const cancel = () => {
                if (!sosTimer) return;
                clearTimeout(sosTimer);
                sosTimer = null;
                setMessage(t('tabs.sos_hold_hint'));
            };
            btn.addEventListener('pointerdown', e => { e.preventDefault(); start(); });
            ['pointerup', 'pointerleave', 'pointercancel', 'blur'].forEach(ev => btn.addEventListener(ev, cancel));
            btn.addEventListener('keydown', e => {
                if (e.key !== 'Enter' && e.key !== ' ' && e.key !== 'Spacebar') return;
                e.preventDefault();   // no synthetic click; the key repeat of a held key must not restart the timer
                if (!e.repeat) start();
            });
            btn.addEventListener('keyup', e => {
                if (e.key === 'Enter' || e.key === ' ' || e.key === 'Spacebar') cancel();
            });
            btn.addEventListener('contextmenu', e => e.preventDefault());
        }

        function shell(inner) {
            overlay.innerHTML = '<div class="container py-3" style="max-width:640px;">'
                + '<div class="alert alert-warning py-2 d-flex justify-content-between align-items-center mb-3 gap-2">'
                + '<span><i class="bi bi-mortarboard-fill me-1"></i>' + escapeHtml(t('case.banner')) + '</span>'
                + '<span class="d-flex gap-1 flex-shrink-0">' + (opts.onSos ? '<button type="button" class="btn btn-danger fw-bold" data-vc="sos" aria-label="' + escapeHtml(t('tabs.sos_hold_hint')) + '" style="touch-action:none;user-select:none;min-width:64px;min-height:44px;">SOS</button>' : '')
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

        // Whatever the document turns out to hold, a throw while drawing it must end in «δεν είναι διαθέσιμο» with a retry,
        // never in «Φόρτωση…» for ever (a kept document can be stale, truncated or from another version of the app).
        function render() {
            if (!doc) return;
            try {
                renderCurrent();
                shownSignature = viewSignature(doc, answers);
            } catch (e) {
                renderUnavailable();
            }
        }

        function renderCurrent() {
            const run = runFromAnswers(doc, answers);
            if (pendingOutcome) return renderFeedback();
            if (run.finished) return renderEnd(run);
            if (!started) return renderIntro();
            if (!doc.steps[run.currentStep]) throw new Error('step missing');   // render() shows «δεν είναι διαθέσιμο»
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
            if (mode.enqueue) {
                opts.enqueue(answerQueueItem(opts.placementId, stepId, choiceId, ms));
                if (typeof opts.flush === 'function') opts.flush();
            }
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
            shownSignature = null;
            shell('<p class="text-danger fw-semibold">' + escapeHtml(t('case.err_gone')) + '</p>'
                + (mode.load ? '<button type="button" class="btn btn-primary btn-lg w-100" data-vc="retry">' + escapeHtml(t('case.retry')) + '</button>' : ''));
            const retryBtn = overlay.querySelector('[data-vc="retry"]');   // a try-out has nothing to fetch again: no button
            if (retryBtn) retryBtn.addEventListener('click', () => {
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
                // mergeAnswers already lets the server win per step. The merged list stays on the phone: without it, opening
                // the case again with no signal would restart at the first step.
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
        // The refresh must not swap the buttons under a thumb that is about to press one: it redraws only when the case or
        // where the run stands actually changed since what is on screen.
        const refreshIfChanged = () => {
            let same = false;
            try { same = shownSignature !== null && doc !== null && viewSignature(doc, answers) === shownSignature; } catch (e) { same = false; }
            if (!same) render();
        };
        if (!mode.load) { if (doc) render(); else renderUnavailable(); }   // a try-out: the document in hand is the whole case
        else if (doc) { render(); load().then(refreshIfChanged, () => {}); }
        else load().then(render, renderUnavailable);
        return {close: close};
    }

    // ── Command's side (the «Περιστατικά» card and the staging picker in war-room.php) ──────────────────────
    const RATING_BADGE = {best: 'bg-success', acceptable: 'bg-warning text-dark', harmful: 'bg-danger'};

    /** 65000 -> '1m 05s'; 4200 -> '4s'. Whole seconds, as the answers are recorded. */
    function formatSeconds(ms) {
        const s = Math.round(ms / 1000);
        return s >= 60 ? Math.floor(s / 60) + 'm ' + String(s % 60).padStart(2, '0') + 's' : s + 's';
    }

    /**
     * The body of the command card: for every case placed, each volunteer's state (not started / in progress at step N /
     * finished with score and time), the run of a volunteer opened to its choices, and for each step how many chose each
     * option (the group debrief). `data` is training-case.php?action=overview; t is the page's translator. Pure: every
     * value that comes from a person or a case goes through escapeHtml().
     */
    function renderOverview(data, t) {
        const placements = (data && data.placements) || [];
        if (!placements.length) return '<div class="small text-muted">' + escapeHtml(t('case.overview_empty')) + '</div>';
        return placements.map(p => {
            const steps = p.steps || [];
            const stateBadge = v => {
                const cls = {done: 'bg-success', running: 'bg-primary', new: 'bg-secondary'}[v.state] || 'bg-secondary';
                const answers = Array.isArray(v.answers) ? v.answers : null;
                let extra = '';
                if (v.state === 'done') {
                    const timed = (answers || []).filter(a => a.ms_taken != null);
                    extra = ' ' + escapeHtml(v.score_points + '/' + v.score_max) + ' · ' + escapeHtml(v.percent) + '%'
                        + (timed.length ? ' · ' + escapeHtml(formatSeconds(timed.reduce((sum, a) => sum + a.ms_taken, 0))) : '');
                } else if (v.state === 'running') {
                    // The step a volunteer is at counts along their own path (what they have answered, plus one).
                    const at = steps.findIndex(s => s.step_id === v.step);
                    const n = answers ? answers.length + 1 : (at >= 0 ? at + 1 : null);
                    extra = ' · ' + (n !== null ? escapeHtml(t('case.overview_step', {n: n})) : escapeHtml(v.step || ''));
                }
                return '<span class="badge ' + cls + '">' + escapeHtml(t('case.state.' + v.state)) + extra + '</span>';
            };
            // One run opens to show each step: the choice, its rating and the time taken.
            const runHtml = v => (v.answers && v.answers.length)
                ? '<details class="ms-2" data-k="r' + escapeHtml(p.id) + '-' + escapeHtml(v.user_id) + '"><summary class="small text-muted">' + escapeHtml(t('case.overview_run')) + '</summary><ol class="small mb-1">'
                    + v.answers.map(a => '<li>' + escapeHtml(a.choice_label) + ' <span class="badge ' + (RATING_BADGE[a.rating] || 'bg-secondary') + '">'
                        + escapeHtml(t('case.rating.' + a.rating)) + '</span>'
                        + (a.ms_taken != null ? ' <span class="text-muted">' + escapeHtml(formatSeconds(a.ms_taken)) + '</span>' : '') + '</li>').join('')
                    + '</ol></details>'
                : '';
            const people = (p.volunteers || []).map(v => '<li><div class="d-flex justify-content-between gap-2"><span>' + escapeHtml(v.name) + '</span>' + stateBadge(v) + '</div>' + runHtml(v) + '</li>').join('');
            const stepHtml = steps.map(s => '<div class="small mt-2"><div class="fw-semibold">' + escapeHtml(s.prompt) + '</div>'
                + (s.choices || []).map(c => '<div class="d-flex justify-content-between gap-2"><span>' + escapeHtml(c.label) + '</span><span class="badge '
                    + (RATING_BADGE[c.rating] || 'bg-secondary') + '">' + escapeHtml(c.count) + '</span></div>').join('') + '</div>').join('');
            return '<div class="border rounded p-2 mb-2"><div class="fw-semibold">' + escapeHtml(p.title) + '</div>'
                + (p.label ? '<div class="small text-muted mb-1">' + escapeHtml(p.label) + '</div>' : '')
                + '<ul class="list-unstyled small mb-1">' + people + '</ul>'
                + '<details data-k="s' + escapeHtml(p.id) + '"><summary class="small">' + escapeHtml(t('case.overview_breakdown')) + '</summary>' + stepHtml + '</details></div>';
        }).join('');
    }

    /** The text of one picker option (set with textContent / new Option, never as markup): stars, title, and a flag when unreviewed. */
    function caseOptionLabel(c, t) {
        const stars = c.difficulty > 0 ? '★'.repeat(c.difficulty) + ' ' : '';
        const flag = c.reviewed ? '' : ' · ' + t('case.picker_unreviewed_tag');
        return [stars, String(c.title == null ? '' : c.title), flag].join('');
    }

    /**
     * The extra fields «Στο σενάριο» sends for a case chosen in the dispatch window (mission-scenario.php add_item), or {} when none
     * is chosen. sel = {caseId, mode: 'chosen'|'random', filter, briefing}. Only a random draw records the filter it used.
     */
    function caseStageFields(sel) {
        if (!sel || sel.caseId === '' || sel.caseId == null) return {};
        const mode = sel.mode === 'random' ? 'random' : 'chosen';
        return {
            case_id: String(sel.caseId), case_mode: mode,
            case_pool: JSON.stringify(mode === 'random' ? (sel.filter || {}) : {}),
            briefing: String(sel.briefing || '').trim(),
        };
    }

    const api = {
        escapeHtml, scoreLabel, choiceOutcome, runFromAnswers, bestPathLabels, mergeAnswers, withoutAnswer, answerQueueItem,
        storageKeys, queueOutcome, replayCandidate, forgetLocalAnswer, validDoc, viewSignature, previewOptions, open,
        renderOverview, caseOptionLabel, caseStageFields, formatSeconds,
    };
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    root.VoTrainingCase = api;
})(typeof window !== 'undefined' ? window : globalThis);
