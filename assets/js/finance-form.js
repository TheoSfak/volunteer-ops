/**
 * Οικονομικά (v3.510.0): the document form. Pure VAT maths (Node-tested, mirrors includes/finance-functions.php:
 * the server recomputes everything on save, this only keeps the form honest while typing) plus the DOM wiring for
 * the VAT table and the AI scan. Money is integer cents here too.
 */
(function (root) {
    'use strict';

    const VAT_RATES = [24, 13, 6, 17, 9, 4, 0];
    const TOLERANCE_CENTS = 2;
    const PDFJS = 'https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/';
    const MAX_EDGE = 2000;

    function decimalCents(s) {
        const m = /^(-?)(\d{1,10})(?:\.(\d+))?$/.exec(s);
        if (!m) return null;
        const frac = ((m[3] || '') + '000').slice(0, 3);
        const cents = parseInt(m[2], 10) * 100 + parseInt(frac.slice(0, 2), 10) + (parseInt(frac[2], 10) >= 5 ? 1 : 0);
        return m[1] === '-' ? -cents : cents;
    }
    /**
     * Mirror of financeParseCents(): Greek-style amount → cents, or null when it does not parse. With a comma the dots
     * are thousands separators and the comma is the decimal mark. Without one, a single dot followed by exactly three
     * digits after a 1-3 digit integer part (not 0) is a thousands separator («1.500»); several dots must be valid
     * groups («1.000.000»); any other single dot is the decimal mark («12.5»).
     */
    function parseCents(value) {
        let s = String(value == null ? '' : value).replace(/[\s\u00A0]+/g, '');
        if (s === '') return null;
        let sign = '';
        if (s[0] === '-') { sign = '-'; s = s.slice(1); }
        const group = /^\d{1,3}(?:\.\d{3})+$/;
        if (s.includes(',')) {
            const parts = s.split(',');
            if (parts.length !== 2 || !/^\d+$/.test(parts[1])) return null;
            let int = parts[0];
            if (int.includes('.')) {
                if (!group.test(int)) return null;
                int = int.replace(/\./g, '');
            }
            return decimalCents(sign + int + '.' + parts[1]);
        }
        const dots = s.split('.').length - 1;
        if (dots >= 2) return group.test(s) ? decimalCents(sign + s.replace(/\./g, '')) : null;
        const t = /^([1-9]\d{0,2})\.(\d{3})$/.exec(s);
        if (dots === 1 && t) return decimalCents(sign + t[1] + t[2]);
        return decimalCents(sign + s);
    }
    /** 0 for anything invalid, like financeToCents() (the form only previews; the server refuses invalid input). */
    function toCents(value) { const c = parseCents(value); return c === null ? 0 : c; }
    function fromCents(c) {
        const a = Math.abs(c);
        return (c < 0 ? '-' : '') + Math.floor(a / 100) + '.' + String(a % 100).padStart(2, '0');
    }
    function display(c) { return fromCents(c).replace('.', ','); }
    function splitGross(gross, rate) {
        const net = Math.round(gross * 100 / (100 + rate));
        return {rate, net, vat: gross - net, gross};
    }
    function splitNet(net, rate) {
        const vat = Math.round(net * rate / 100);
        return {rate, net, vat, gross: net + vat};
    }
    function totals(lines) {
        let net = 0, vat = 0;
        for (const l of lines) { net += l.net; vat += l.vat; }
        return {net, vat, gross: net + vat};
    }
    function mismatch(typed, linesGross) { return Math.abs(typed - linesGross) > TOLERANCE_CENTS; }
    /** The server strips non-digits before checking (EL prefix, spaces). */
    function cleanVat(v) { return String(v).replace(/\D/g, ''); }
    /** Mirror of financeValidVatNumber(): 9 digits, weights 2^8..2^1, (sum mod 11) mod 10 = ninth digit. */
    function validVat(afm) {
        if (!/^\d{9}$/.test(afm) || afm === '000000000') return false;
        let sum = 0;
        for (let i = 0; i < 8; i++) sum += Number(afm[i]) * Math.pow(2, 8 - i);
        return (sum % 11) % 10 === Number(afm[8]);
    }

    /** The pay-now date: the document date while it still follows it (the user has not typed their own), else what is there. */
    function nextPayDate(follows, docDate, payDate) {
        return follows && /^\d{4}-\d{2}-\d{2}$/.test(String(docDate)) ? String(docDate) : String(payDate);
    }

    /**
     * A due date on a new document means the bill is still owed: untick «Πληρώθηκε ήδη» — once, and never after the
     * person has touched the switch themselves (so re-ticking it is final).
     */
    function shouldUntickPaid(state) {
        return String(state.due || '').trim() !== '' && !!state.checked && !state.handled && !state.userSet;
    }

    /** ISO yyyy-mm-dd → dd/mm/yyyy; anything else comes back unchanged. */
    function formatDmy(iso) {
        const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(iso));
        return m ? m[3] + '/' + m[2] + '/' + m[1] : String(iso);
    }
    /** True when the account has an opening date and the payment is dated strictly before it (both ISO dates; blank/invalid → false). */
    function paidBeforeOpening(openingDate, payDate) {
        const re = /^\d{4}-\d{2}-\d{2}$/;
        return re.test(String(openingDate)) && re.test(String(payDate)) && String(payDate) < String(openingDate);
    }
    /** The non-blocking Greek warning for a payment dated before the account's opening date, or '' when there is nothing to say. */
    function openingWarning(accountName, openingDate, payDate) {
        if (!paidBeforeOpening(openingDate, payDate)) return '';
        return 'Προσοχή: η ημερομηνία πληρωμής είναι πριν από την έναρξη του λογαριασμού «' + accountName + '» (' + formatDmy(openingDate) +
            '). Το αρχικό του υπόλοιπο μάλλον την περιλαμβάνει ήδη — θα μετρηθεί δύο φορές.';
    }

    // ── Browser only ────────────────────────────────────────────────────────
    function loadScript(src) {
        return new Promise((resolve, reject) => {
            const s = document.createElement('script');
            s.src = src; s.onload = resolve; s.onerror = () => reject(new Error('pdf.js'));
            document.head.appendChild(s);
        });
    }
    let pdfReady = null;
    function pdfjs() {
        // The CSP allows workers only from 'self' and blob:, so the worker is fetched and run from a Blob URL.
        if (!pdfReady) {
            pdfReady = loadScript(PDFJS + 'pdf.min.js')
                .then(() => fetch(PDFJS + 'pdf.worker.min.js'))
                .then(r => r.blob())
                .then(b => { root.pdfjsLib.GlobalWorkerOptions.workerSrc = URL.createObjectURL(b); return root.pdfjsLib; })
                .catch(e => { pdfReady = null; throw e; });
        }
        return pdfReady;
    }
    function canvasFrom(source, w, h) {
        const scale = Math.min(1, MAX_EDGE / Math.max(w, h));
        const c = document.createElement('canvas');
        c.width = Math.round(w * scale); c.height = Math.round(h * scale);
        c.getContext('2d').drawImage(source, 0, 0, c.width, c.height);
        return c;
    }
    /** A file → a JPEG data URL (real canvas-encoded bytes), long edge ≤ 2000 px. A PDF's first page is rendered. */
    async function prepareImage(file) {
        if (file.type === 'application/pdf') {
            const lib = await pdfjs();
            const pdf = await lib.getDocument({data: await file.arrayBuffer(), isEvalSupported: false}).promise;
            const page = await pdf.getPage(1);
            const base = page.getViewport({scale: 1});
            const viewport = page.getViewport({scale: Math.min(3, MAX_EDGE / Math.max(base.width, base.height))});
            const c = document.createElement('canvas');
            c.width = Math.round(viewport.width); c.height = Math.round(viewport.height);
            const ctx = c.getContext('2d');
            ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, c.width, c.height); // JPEG has no alpha: white page, not black
            // intent 'print' renders without requestAnimationFrame, so it also finishes in a background tab.
            await page.render({canvasContext: ctx, viewport, intent: 'print'}).promise;
            return c.toDataURL('image/jpeg', 0.85);
        }
        const url = URL.createObjectURL(file);
        try {
            const img = await new Promise((resolve, reject) => {
                const i = new Image(); i.onload = () => resolve(i); i.onerror = () => reject(new Error('image')); i.src = url;
            });
            return canvasFrom(img, img.naturalWidth, img.naturalHeight).toDataURL('image/jpeg', 0.85);
        } finally { URL.revokeObjectURL(url); }
    }

    /** POST the prepared image; resolves to the parsed success JSON or throws an Error with a Greek message. */
    async function postScan(form, dataUrl) {
        const body = new FormData();
        body.append('csrf_token', form.dataset.csrf);
        body.append('image', dataUrl);
        const res = await fetch(form.dataset.scanUrl, {method: 'POST', body, credentials: 'same-origin'});
        let json = null;
        try { json = await res.json(); } catch (e) { json = null; }
        if (!json || typeof json !== 'object') throw new Error('Η ανάγνωση απέτυχε (μη αναμενόμενη απάντηση από τον διακομιστή).');
        if (!res.ok || !json.ok) throw new Error(typeof json.error === 'string' && json.error ? json.error : 'Αποτυχία ανάγνωσης.');
        if (!json.data || typeof json.data !== 'object') throw new Error('Το AI δεν επέστρεψε αναγνώσιμα στοιχεία.');
        return json;
    }

    function initForm(form) {
        const $ = sel => form.querySelector(sel);
        const table = $('#vatLines');
        const tpl = document.getElementById('vatLineTemplate');

        function readLines() {
            return [...table.querySelectorAll('.vat-line')].map(tr => ({
                net: toCents(tr.querySelector('[name="vat_net[]"]').value),
                vat: toCents(tr.querySelector('[name="vat_vat[]"]').value),
            }));
        }
        function refresh() {
            const t = totals(readLines());
            $('#totNet').textContent = display(t.net);
            $('#totVat').textContent = display(t.vat);
            $('#totGross').textContent = display(t.gross);
            const typedRaw = $('#grossTyped').value.trim();
            $('#totalMismatch').classList.toggle('d-none', typedRaw === '' || !mismatch(toCents(typedRaw), t.gross));
        }
        function wire(tr) {
            const rate = () => parseInt(tr.querySelector('[name="vat_rate[]"]').value, 10);
            const net = tr.querySelector('[name="vat_net[]"]'), vat = tr.querySelector('[name="vat_vat[]"]'), gross = tr.querySelector('.vat-gross');
            gross.addEventListener('input', () => { const s = splitGross(toCents(gross.value), rate()); net.value = display(s.net); vat.value = display(s.vat); refresh(); });
            net.addEventListener('input', () => { const s = splitNet(toCents(net.value), rate()); vat.value = display(s.vat); gross.value = display(s.gross); refresh(); });
            vat.addEventListener('input', () => { gross.value = display(toCents(net.value) + toCents(vat.value)); refresh(); });
            tr.querySelector('[name="vat_rate[]"]').addEventListener('change', () => {
                if (gross.value.trim() !== '') gross.dispatchEvent(new Event('input'));
            });
            const del = tr.querySelector('.vat-remove');
            if (del) del.addEventListener('click', () => { if (table.querySelectorAll('.vat-line').length > 1) { tr.remove(); refresh(); } });
            if (net.value.trim() !== '') gross.value = display(toCents(net.value) + toCents(vat.value));
        }
        function addLine(l) {
            const tr = tpl.content.firstElementChild.cloneNode(true);
            table.querySelector('tbody').appendChild(tr);
            if (l) {
                tr.querySelector('[name="vat_rate[]"]').value = String(l.rate);
                tr.querySelector('[name="vat_net[]"]').value = String(l.net).replace('.', ',');
                tr.querySelector('[name="vat_vat[]"]').value = String(l.vat).replace('.', ',');
            }
            wire(tr);
            return tr;
        }
        table.querySelectorAll('.vat-line').forEach(wire);
        $('#addVatLine').addEventListener('click', () => { addLine(null).querySelector('[name="vat_rate[]"]').value = form.dataset.defaultRate; });
        $('#grossTyped').addEventListener('input', refresh);
        refresh();
        // Catch the two refusals here: a server round trip would lose the chosen file and everything the scan filled.
        form.addEventListener('submit', ev => {
            const badAmount = [...form.querySelectorAll('[name="vat_net[]"], [name="vat_vat[]"], #grossTyped')].find(el => el.value.trim() !== '' && parseCents(el.value) === null);
            if (badAmount) {
                ev.preventDefault(); badAmount.focus(); alert('Μη έγκυρο ποσό «' + badAmount.value.trim() + '». Γράψτε π.χ. 1.234,56'); return;
            }
            if (totals(readLines()).gross <= 0) {
                ev.preventDefault(); alert('Συμπληρώστε τουλάχιστον ένα ποσό.'); return;
            }
            const vatEl = $('#counterpartyVat');
            if (vatEl && cleanVat(vatEl.value) !== '' && !validVat(cleanVat(vatEl.value))) {
                ev.preventDefault(); vatEl.focus(); alert('Το ΑΦΜ δεν είναι έγκυρο (έλεγχος ψηφίου). Διορθώστε το ή αφήστε το κενό.'); return;
            }
            const box = $('[name="total_confirmed"]');
            if (!$('#totalMismatch').classList.contains('d-none') && !box.checked) {
                ev.preventDefault(); box.focus(); alert('Το τυπωμένο σύνολο διαφέρει από τις γραμμές ΦΠΑ. Διορθώστε ή επιβεβαιώστε.');
            }
        });

        // ── Pay-now date follows the document date until the user types their own ──
        const docDateEl = $('#docDate'), payDateEl = $('#payDate');
        let payFollows = !!payDateEl && payDateEl.dataset.follow === '1';
        function syncPayDate() {
            if (!payDateEl || !docDateEl) return;
            const next = nextPayDate(payFollows, docDateEl.value, payDateEl.value);
            if (next !== payDateEl.value) { payDateEl.value = next; payDateEl.dispatchEvent(new Event('change', {bubbles: true})); }
        }
        if (payDateEl && docDateEl) {
            payDateEl.addEventListener('input', () => { payFollows = false; }); // only a person typing fires 'input'; syncPayDate fires 'change'
            ['input', 'change'].forEach(n => docDateEl.addEventListener(n, syncPayDate));
            syncPayDate();
        }

        // ── A due date means «unpaid»: untick «Πληρώθηκε ήδη» once, with a note ──
        const payNowEl = $('#payNow'), dueEl = $('#dueDate'), dueNote = $('#payNowDueNote');
        let dueHandled = false, paidTouched = !!payNowEl && payNowEl.dataset.userSet === '1';
        function applyDueDate() {
            if (!payNowEl || !dueEl) return;
            if (!shouldUntickPaid({due: dueEl.value, checked: payNowEl.checked, handled: dueHandled, userSet: paidTouched})) return;
            dueHandled = true;
            payNowEl.checked = false;
            payNowEl.dispatchEvent(new Event('input', {bubbles: true})); // refresh the opening warning; not a 'change', so it is not a user choice
            if (dueNote) dueNote.classList.remove('d-none');
        }
        if (payNowEl && dueEl) {
            payNowEl.addEventListener('change', () => { paidTouched = true; if (dueNote) dueNote.classList.add('d-none'); });
            ['input', 'change'].forEach(n => dueEl.addEventListener(n, applyDueDate));
        }

        // ── Scan ───────────────────────────────────────────────────────────
        // AI output is untrusted: it only ever goes through .value / .textContent, never HTML.
        const file = $('#docFile'), btnCam = $('#btnCamera'), btnScan = $('#btnScan'), status = $('#scanStatus');
        if (btnCam) btnCam.addEventListener('click', () => { file.setAttribute('capture', 'environment'); file.click(); file.removeAttribute('capture'); });
        file.addEventListener('change', () => { if (btnScan) btnScan.disabled = !file.files.length; });
        if (!btnScan) return;
        const say = (cls, text) => { status.className = 'small mt-2 ' + cls; status.textContent = text; };
        const set = (id, v) => { if (v != null && v !== '') { const el = $('#' + id); el.value = String(v); el.classList.add('border-info'); } };
        btnScan.addEventListener('click', async () => {
            if (!file.files.length) return;
            btnScan.disabled = true;
            say('text-muted', 'Ανάγνωση με AI… (έως ~1 λεπτό)');
            try {
                let dataUrl;
                try { dataUrl = await prepareImage(file.files[0]); }
                catch (e) { throw new Error('Δεν ήταν δυνατή η προετοιμασία του αρχείου.'); }
                const json = await postScan(form, dataUrl);
                const d = json.data;
                if (d.direction && d.direction !== form.dataset.direction) {
                    say('text-warning', 'Το AI πιστεύει ότι είναι ' + (d.direction === 'IN' ? 'έσοδο' : 'έξοδο') + '. Ελέγξτε το είδος.');
                }
                set('counterpartyName', d.counterparty_name); set('counterpartyVat', d.counterparty_vat);
                set('docNumber', d.doc_number); set('docDate', d.doc_date); set('dueDate', d.due_date);
                set('docType', d.doc_type); if (d.category_id) set('categoryId', String(d.category_id));
                const lines = Array.isArray(d.vat_lines) ? d.vat_lines : [];
                if (lines.length) {
                    table.querySelectorAll('.vat-line').forEach(tr => tr.remove());
                    lines.forEach(addLine);
                }
                if (d.gross_total) $('#grossTyped').value = String(d.gross_total).replace('.', ',');
                (Array.isArray(d.uncertain) ? d.uncertain : []).forEach(f => {
                    const map = {counterparty_name: 'counterpartyName', counterparty_vat: 'counterpartyVat', doc_number: 'docNumber', doc_date: 'docDate', due_date: 'dueDate', gross_total: 'grossTyped'};
                    if (map[f]) $('#' + map[f]).classList.add('border-warning', 'border-2');
                });
                syncPayDate();
                applyDueDate();
                $('#scanSource').value = 'AI';
                $('#scanProvider').value = json.provider_label || '';
                refresh();
                if (!status.classList.contains('text-warning')) {
                    say(d.totals_ok ? 'text-success' : 'text-warning',
                        (d.totals_ok ? 'Συμπληρώθηκε από ' : 'Συμπληρώθηκε με απόκλιση στα σύνολα, από ') + (json.provider_label || 'AI') + '. Ελέγξτε τα πεδία πριν την αποθήκευση.');
                }
            } catch (e) {
                say('text-danger', (e && e.message ? e.message : 'Αποτυχία ανάγνωσης.') + ' Μπορείτε να συμπληρώσετε τα στοιχεία χειροκίνητα.');
            } finally { btnScan.disabled = false; }
        });
    }

    /** Live opening-date warning for every [data-opening-warning] block (pay-now card, payments form). Text via textContent only. */
    function initOpeningWarnings(doc) {
        doc.querySelectorAll('[data-opening-warning]').forEach(scope => {
            const acc = scope.querySelector('[name="pay_account_id"]'), date = scope.querySelector('[name="pay_date"]');
            const box = scope.querySelector('.opening-warning'), now = scope.querySelector('[name="pay_now"]');
            const fb = scope.dataset.dateFallback ? doc.querySelector(scope.dataset.dateFallback) : null;
            if (!acc || !date || !box) return;
            const update = () => {
                const opt = acc.selectedOptions && acc.selectedOptions[0];
                const day = date.value || (fb ? fb.value : '');
                const text = opt && !(now && !now.checked) ? openingWarning(opt.textContent, opt.dataset.openingDate || '', day) : '';
                box.textContent = text;
                box.classList.toggle('d-none', text === '');
            };
            [acc, date, now, fb].forEach(el => { if (el) { el.addEventListener('change', update); el.addEventListener('input', update); } });
            update();
        });
    }

    const api = {VAT_RATES, TOLERANCE_CENTS, toCents, fromCents, display, splitGross, splitNet, parseCents, totals, mismatch, cleanVat, validVat, nextPayDate, shouldUntickPaid, formatDmy, paidBeforeOpening, openingWarning, prepareImage, initForm, initOpeningWarnings};
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    root.VoFinance = api;
    if (typeof document !== 'undefined') {
        document.addEventListener('DOMContentLoaded', () => {
            const form = document.getElementById('financeDocumentForm');
            if (form) initForm(form);
            initOpeningWarnings(document);
        });
    }
})(typeof window !== 'undefined' ? window : globalThis);
