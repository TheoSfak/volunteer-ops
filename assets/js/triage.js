/**
 * War Room (Action Room) - mass-casualty triage: the protocols and the card
 * scanner.
 *
 * THE PROTOCOL TREES ARE HALF OF A PAIR. triageProtocols() in
 * includes/functions-triage.php carries the identical trees for the server,
 * which recomputes every category from the rescuer's answers rather than
 * trusting this file's result. Both are pinned to
 * tests/fixtures/triage-cases.json (tests/js/triage.test.js here,
 * TriageProtocolTest on the PHP side), so a change to one without the other
 * fails CI. The phone needs its own copy because triage has to work with no
 * signal at all: the questions are asked here and only the answers travel.
 *
 * Loaded as a plain <script src> like war-room-utils.js, so everything below
 * is an ordinary global in the page and a CommonJS export under Node.
 */

const TRIAGE_PROTOCOLS = {
    // START — adults.
    start: {
        root: 'walk',
        nodes: {
            walk:       {yes: ['green', 'walks'], no: 'breathing'},
            breathing:  {yes: 'rr_over_30', no: 'airway'},
            airway:     {yes: ['red', 'breathes_after_airway'], no: ['black', 'apneic']},
            rr_over_30: {yes: ['red', 'rr_over_30'], no: 'perfusion'},
            perfusion:  {yes: ['red', 'poor_perfusion'], no: 'obeys'},
            obeys:      {yes: ['yellow', 'obeys'], no: ['red', 'no_obey']},
        },
    },
    // JumpSTART — children.
    jumpstart: {
        root: 'walk',
        nodes: {
            walk:           {yes: ['green', 'walks'], no: 'breathing'},
            breathing:      {yes: 'rr_child', no: 'airway'},
            airway:         {yes: ['red', 'breathes_after_airway'], no: 'pulse_apneic'},
            pulse_apneic:   {yes: 'rescue_breaths', no: ['black', 'apneic_no_pulse']},
            rescue_breaths: {yes: ['red', 'breathes_after_rescue'], no: ['black', 'apneic']},
            rr_child:       {yes: ['red', 'rr_child'], no: 'pulse'},
            pulse:          {yes: 'avpu', no: ['red', 'no_pulse']},
            avpu:           {yes: ['yellow', 'avpu_ok'], no: ['red', 'avpu']},
        },
    },
};

const TRIAGE_CATEGORY_ORDER = ['red', 'yellow', 'green', 'black'];

/**
 * Where the rescuer is in the tree given the answers so far:
 * {question: key} for the next thing to ask, {category, reason, path} once a
 * leaf is reached, or null for an unknown protocol / a non yes-no answer.
 * `answers` maps question key -> true/false (1/0 accepted, as the server
 * accepts them).
 */
function triageStep(protocol, answers) {
    const tree = TRIAGE_PROTOCOLS[protocol];
    if (!tree) return null;
    let node = tree.root;
    const path = {};
    for (let step = 0; step < 12; step++) {
        if (!Object.prototype.hasOwnProperty.call(answers || {}, node)) return {question: node, path};
        const raw = answers[node];
        let yes;
        if (raw === true || raw === 1 || raw === '1') yes = true;
        else if (raw === false || raw === 0 || raw === '0') yes = false;
        else return null;
        path[node] = yes;
        const branch = tree.nodes[node][yes ? 'yes' : 'no'];
        if (Array.isArray(branch)) return {category: branch[0], reason: branch[1], path};
        node = branch;
    }
    return null;
}

/**
 * The server's triageEvaluate(): a finished result or null. A route that
 * still has a question outstanding is incomplete, i.e. null, same as PHP.
 */
function triageEvaluate(protocol, answers) {
    const step = triageStep(protocol, answers);
    return step && step.category ? step : null;
}

// Greek capitals that are indistinguishable from Latin ones, folded so a
// card typed on either keyboard layout is the same card. Mirrors the strtr()
// map in normalizeTriageCardNo() (includes/functions-triage.php).
const TRIAGE_GREEK_TO_LATIN = {
    'Α': 'A', 'Β': 'B', 'Ε': 'E', 'Ζ': 'Z', 'Η': 'H', 'Ι': 'I', 'Κ': 'K',
    'Μ': 'M', 'Ν': 'N', 'Ο': 'O', 'Ρ': 'P', 'Τ': 'T', 'Υ': 'Y', 'Χ': 'X',
};
const TRIAGE_CARD_MAX = 30;

function normalizeTriageCardNo(raw) {
    if (raw === null || raw === undefined) return null;
    // Plain toUpperCase(), NOT toLocaleUpperCase('el-GR'): the Greek locale
    // strips accents ('ά' -> 'Α' -> 'A'), PHP's mb_strtoupper() keeps them
    // ('ά' -> 'Ά', then dropped as not A-Z), and the two must agree.
    let s = String(raw).trim().toUpperCase();
    s = Array.from(s).map(ch => TRIAGE_GREEK_TO_LATIN[ch] || ch).join('');
    s = s.replace(/[^A-Z0-9-]/g, '').replace(/^-+|-+$/g, '');
    if (s === '') return null;
    return s.slice(0, TRIAGE_CARD_MAX);
}

function triageFallbackCode(userId, seq) {
    return 'T' + userId + '-' + String(Math.max(1, seq)).padStart(2, '0');
}

// crypto.randomUUID() needs a secure context; the Action Room always is one
// in production, but a plain-http LAN test box is not, and a triage must
// never fail over how its id was made.
function triageUuid() {
    try {
        if (typeof crypto !== 'undefined' && crypto.randomUUID) return crypto.randomUUID();
    } catch (e) { /* fall through */ }
    let s = '';
    for (let i = 0; i < 32; i++) s += Math.floor(Math.random() * 16).toString(16);
    return s.slice(0, 8) + '-' + s.slice(8, 12) + '-' + s.slice(12, 16) + '-' + s.slice(16, 20) + '-' + s.slice(20);
}

/**
 * Minutes after its last assessment that a casualty still on scene is due to
 * be looked at again. Mirrors TRIAGE_RETRIAGE_MINUTES (functions-triage.php).
 */
const TRIAGE_RETRIAGE_MINUTES = {red: 15, yellow: 30, green: 60};

// ── Card scanner ────────────────────────────────────────────────────────────
// Reads the barcode or QR code on a physical triage card with the phone's
// camera and hands back its text. Two engines, one behaviour:
//
//   · BarcodeDetector — built into Chrome on Android and the Android app's
//     WebView. Fast, and works with no network at all.
//   · ZXing (@zxing/library) — for everything that has no BarcodeDetector,
//     which is iPhone and desktop browsers. Loaded from jsDelivr with an SRI
//     hash, the way Leaflet is, and the service worker's CDN cache keeps it
//     for offline use once fetched. preloadTriageScanner() fetches it the
//     moment Μαζικό Συμβάν is switched on, so the first scan in a dead zone
//     does not depend on a download.
//
// Formats: whatever triage cards commonly carry — QR, Code 128/39, EAN/UPC,
// ITF, Codabar, Data Matrix. The scanned text lands in the card field for
// the rescuer to see and correct; nothing is saved on a scan alone.
const TRIAGE_ZXING_URL = 'https://cdn.jsdelivr.net/npm/@zxing/library@0.21.3/umd/index.min.js';
const TRIAGE_ZXING_SRI = 'sha384-BzBxP10ZE72aitqj5UMmUsbKFliP/DZqA8Wq+BNNhlIJDGoEd1tpkMYXOg9+n6sB';
let triageZxingPromise = null;

function loadTriageZxing() {
    if (typeof window === 'undefined') return Promise.reject(new Error('no window'));
    if (window.ZXing) return Promise.resolve(window.ZXing);
    if (!triageZxingPromise) {
        triageZxingPromise = new Promise((resolve, reject) => {
            const s = document.createElement('script');
            s.src = TRIAGE_ZXING_URL;
            s.integrity = TRIAGE_ZXING_SRI;
            s.crossOrigin = 'anonymous';
            s.onload = () => window.ZXing ? resolve(window.ZXing) : reject(new Error('ZXing missing'));
            // Forget the failure so the next scan tries again once signal is back.
            s.onerror = () => { triageZxingPromise = null; s.remove(); reject(new Error('ZXing load failed')); };
            document.head.appendChild(s);
        });
    }
    return triageZxingPromise;
}

function preloadTriageScanner() {
    if (typeof window === 'undefined' || 'BarcodeDetector' in window) return;
    loadTriageZxing().catch(() => {});
}

async function triageNativeDetector() {
    if (typeof window === 'undefined' || !('BarcodeDetector' in window)) return null;
    try {
        const formats = await window.BarcodeDetector.getSupportedFormats();
        if (!formats || !formats.length) return null;
        return new window.BarcodeDetector({formats});
    } catch (e) {
        return null;
    }
}

/**
 * One frame through ZXing. The canvas holds a grey-scale copy of the frame;
 * ZXing wants one luminance byte per pixel, which is what the loop builds.
 * Returns the decoded text or null (ZXing throws when a frame holds no code,
 * which is the normal case between hits, not an error).
 */
function triageZxingDecode(ZX, canvas) {
    const ctx = canvas.getContext('2d', {willReadFrequently: true});
    const {width, height} = canvas;
    const rgba = ctx.getImageData(0, 0, width, height).data;
    const lum = new Uint8ClampedArray(width * height);
    for (let i = 0, p = 0; p < lum.length; i += 4, p++) {
        lum[p] = (rgba[i] * 77 + rgba[i + 1] * 150 + rgba[i + 2] * 29) >> 8;
    }
    const hints = new Map();
    hints.set(ZX.DecodeHintType.TRY_HARDER, true);
    hints.set(ZX.DecodeHintType.POSSIBLE_FORMATS, [
        ZX.BarcodeFormat.QR_CODE, ZX.BarcodeFormat.CODE_128, ZX.BarcodeFormat.CODE_39,
        ZX.BarcodeFormat.EAN_13, ZX.BarcodeFormat.EAN_8, ZX.BarcodeFormat.UPC_A,
        ZX.BarcodeFormat.ITF, ZX.BarcodeFormat.CODABAR, ZX.BarcodeFormat.DATA_MATRIX,
    ]);
    try {
        const source = new ZX.RGBLuminanceSource(lum, width, height);
        const bitmap = new ZX.BinaryBitmap(new ZX.HybridBinarizer(source));
        const result = new ZX.MultiFormatReader().decode(bitmap, hints);
        return result ? result.getText() : null;
    } catch (e) {
        return null;
    }
}

/**
 * Opens the full-screen camera, scans until a code is read, closes itself
 * and calls onResult(text). onError(messageKey) on anything that stops the
 * scan (no camera, permission refused, engine unavailable). Returns a close
 * function. `labels` carries the three strings the overlay shows, already
 * translated by the page.
 */
function openTriageScanner({onResult, onError, labels}) {
    const root = document.createElement('div');
    root.className = 'triage-scanner';
    root.setAttribute('role', 'dialog');
    root.innerHTML = `
        <video playsinline muted autoplay></video>
        <div class="triage-scanner-frame" aria-hidden="true"></div>
        <div class="triage-scanner-status">${labels.hint}</div>
        <button type="button" class="btn btn-light btn-lg triage-scanner-close">${labels.close}</button>`;
    document.body.appendChild(root);
    const video = root.querySelector('video');
    const statusEl = root.querySelector('.triage-scanner-status');
    let stream = null, timer = null, closed = false;

    function close() {
        if (closed) return;
        closed = true;
        clearTimeout(timer);
        if (stream) stream.getTracks().forEach(tr => tr.stop());
        root.remove();
    }
    function fail(key) {
        close();
        if (onError) onError(key);
    }
    root.querySelector('.triage-scanner-close').addEventListener('click', close);

    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        fail('triage.scan_no_camera');
        return close;
    }

    (async () => {
        try {
            stream = await navigator.mediaDevices.getUserMedia({
                video: {facingMode: {ideal: 'environment'}, width: {ideal: 1280}, height: {ideal: 720}},
                audio: false,
            });
        } catch (e) {
            fail(e && e.name === 'NotAllowedError' ? 'triage.scan_denied' : 'triage.scan_no_camera');
            return;
        }
        if (closed) { stream.getTracks().forEach(tr => tr.stop()); return; }
        video.srcObject = stream;
        try { await video.play(); } catch (e) { /* autoplay attribute covers it */ }

        const detector = await triageNativeDetector();
        let ZX = null;
        if (!detector) {
            statusEl.textContent = labels.loading;
            try { ZX = await loadTriageZxing(); } catch (e) { fail('triage.scan_engine_failed'); return; }
            statusEl.textContent = labels.hint;
        }
        const canvas = document.createElement('canvas');

        const tick = async () => {
            if (closed) return;
            let text = null;
            try {
                if (video.readyState >= 2 && video.videoWidth) {
                    if (detector) {
                        const found = await detector.detect(video);
                        if (found && found.length) text = found[0].rawValue;
                    } else {
                        // Centre crop, capped at 900px wide: a card fills the
                        // middle of the frame, and full-resolution frames make
                        // ZXing slow enough on an older phone to feel broken.
                        const cropW = Math.round(video.videoWidth * 0.8);
                        const cropH = Math.round(video.videoHeight * 0.6);
                        const scale = Math.min(1, 900 / cropW);
                        canvas.width = Math.round(cropW * scale);
                        canvas.height = Math.round(cropH * scale);
                        canvas.getContext('2d', {willReadFrequently: true}).drawImage(
                            video, (video.videoWidth - cropW) / 2, (video.videoHeight - cropH) / 2, cropW, cropH,
                            0, 0, canvas.width, canvas.height
                        );
                        text = triageZxingDecode(ZX, canvas);
                    }
                }
            } catch (e) { text = null; }
            if (text && String(text).trim()) {
                if (navigator.vibrate) navigator.vibrate(80);
                close();
                onResult(String(text).trim());
                return;
            }
            timer = setTimeout(tick, detector ? 120 : 200);
        };
        tick();
    })();
    return close;
}

if (typeof module !== 'undefined' && module.exports) {
    module.exports = {
        TRIAGE_PROTOCOLS,
        TRIAGE_CATEGORY_ORDER,
        TRIAGE_RETRIAGE_MINUTES,
        triageStep,
        triageEvaluate,
        normalizeTriageCardNo,
        triageFallbackCode,
        triageUuid,
    };
}
