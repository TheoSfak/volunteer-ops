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

    function toCents(value) {
        const s = String(value == null ? '' : value).trim().replace(/\s/g, '').replace(',', '.');
        const m = /^(-?)(\d+)(?:\.(\d+))?$/.exec(s);
        if (!m) return 0;
        const frac = ((m[3] || '') + '000').slice(0, 3);
        const cents = parseInt(m[2], 10) * 100 + parseInt(frac.slice(0, 2), 10) + (parseInt(frac[2], 10) >= 5 ? 1 : 0);
        return m[1] === '-' ? -cents : cents;
    }
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
            const pdf = await lib.getDocument({data: await file.arrayBuffer()}).promise;
            const page = await pdf.getPage(1);
            const base = page.getViewport({scale: 1});
            const viewport = page.getViewport({scale: Math.min(3, MAX_EDGE / Math.max(base.width, base.height))});
            const c = document.createElement('canvas');
            c.width = Math.round(viewport.width); c.height = Math.round(viewport.height);
            await page.render({canvasContext: c.getContext('2d'), viewport}).promise;
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
            if (totals(readLines()).gross <= 0) {
                ev.preventDefault(); alert('Συμπληρώστε τουλάχιστον ένα ποσό.'); return;
            }
            const box = $('[name="total_confirmed"]');
            if (!$('#totalMismatch').classList.contains('d-none') && !box.checked) {
                ev.preventDefault(); box.focus(); alert('Το τυπωμένο σύνολο διαφέρει από τις γραμμές ΦΠΑ. Διορθώστε ή επιβεβαιώστε.');
            }
        });

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
            say('text-muted', 'Ανάγνωση με AI… (έως 1 λεπτό)');
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

    const api = {VAT_RATES, TOLERANCE_CENTS, toCents, fromCents, display, splitGross, splitNet, totals, mismatch, prepareImage, initForm};
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    root.VoFinance = api;
    if (typeof document !== 'undefined') {
        document.addEventListener('DOMContentLoaded', () => {
            const form = document.getElementById('financeDocumentForm');
            if (form) initForm(form);
        });
    }
})(typeof window !== 'undefined' ? window : globalThis);
