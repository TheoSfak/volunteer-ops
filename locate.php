<?php
/**
 * VolunteerOps - Locate Link (PUBLIC - no login required, v3.499.0)
 *
 * Sent to a missing person's or a caller's own phone by command staff, from
 * the Action Room's «Σύνδεσμοι» tool. Opens a one-tap consent page; once
 * accepted, the browser's own geolocation drops/updates a single pin on the
 * mission map until the link is revoked, expires, or the mission closes.
 *
 * URL: /locate.php?token=XXXX
 *
 * Same idiom as briefing-view.php / visitor-join.php: the token IS the access
 * control and self-expires (see findActiveMissionLocateLink()), with no
 * distinction in the error state between "never existed" and "no longer
 * valid" - anti-enumeration, not a bug.
 *
 * Bilingual on one page (Greek + English together, not via t()) since an
 * anonymous visitor has no known language preference - same reasoning
 * briefing-view.php already uses.
 */
require_once __DIR__ . '/bootstrap.php';
// No requireLogin() - this page is intentionally public.

$token = trim(get('token', ''));

// AJAX position update - handled before any HTML output.
if (isPost() && post('ajax') === '1') {
    header('Content-Type: application/json');
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string) $_POST['csrf_token'])) {
        echo json_encode(['ok' => false, 'error' => 'invalid_request']);
        exit;
    }
    $link = findActiveMissionLocateLink($token);
    if (!$link) {
        echo json_encode(['ok' => false, 'error' => 'link_inactive']);
        exit;
    }
    $lat = (float) post('lat');
    $lng = (float) post('lng');
    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || ($lat === 0.0 && $lng === 0.0)) {
        echo json_encode(['ok' => false, 'error' => 'bad_position']);
        exit;
    }
    $accRaw = post('accuracy');
    $accuracyM = ($accRaw === '' || $accRaw === null) ? null : max(0, min(50000, (int) round((float) $accRaw)));
    recordLocateLinkPosition((int) $link['id'], $lat, $lng, $accuracyM);
    echo json_encode(['ok' => true]);
    exit;
}

$link = findActiveMissionLocateLink($token);
$csrfToken = csrfToken();
?><!DOCTYPE html>
<html lang="el">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#172554">
    <meta name="robots" content="noindex, nofollow">
    <title>Εντοπισμός θέσης / Share your location</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" integrity="sha384-XGjxtQfXaH2tnPFa9x+ruJTuLE3Aa6LhHSWRr1XeTyhezb4abCG4ccI5AkVDxqC+" crossorigin="anonymous">
    <style>
        body { background: #eef0f4; }
        .lp-page { max-width: 480px; margin: 24px auto; background: #fff; border-radius: 18px; overflow: hidden; box-shadow: 0 4px 18px rgba(23,37,84,.12); }
        .lp-hero { background: linear-gradient(135deg, #172554, #b91c1c); color: #fff; padding: 24px 22px; text-align: center; }
        .lp-section { padding: 22px; }
        .lp-status { font-size: 13px; font-weight: 700; padding: 10px 12px; border-radius: 10px; margin-top: 14px; }
        .lp-status.ok { background: #eaf3de; color: #1a5a1a; }
        .lp-status.wait { background: #faeeda; color: #7a4a00; }
        .lp-status.err { background: #fde8e8; color: #8a1f1f; }
        @media (max-width: 480px) { .lp-page { margin: 0; border-radius: 0; } body { background: #fff; } }
    </style>
</head>
<body>
<div class="lp-page">
<?php if (!$link): ?>
    <div class="lp-section text-center py-5">
        <i class="bi bi-x-circle text-danger" style="font-size:2.5rem;"></i>
        <h2 class="mt-3 mb-2" style="font-size:1.2rem;">Μη έγκυρος ή ληγμένος σύνδεσμος<br><span class="fs-6">Invalid or expired link</span></h2>
        <p class="text-muted small mb-0">Αυτός ο σύνδεσμος δεν λειτουργεί πια.<br>This link no longer works.<br>Επικοινωνήστε με όποιον σας τον έστειλε. / Contact whoever sent it to you.</p>
    </div>
<?php else: ?>
    <div class="lp-hero">
        <i class="bi bi-geo-alt-fill" style="font-size:2rem;"></i>
        <h1 class="mt-2 mb-1" style="font-size:1.25rem;font-weight:800;">Στείλε τη θέση σου</h1>
        <div style="font-size:12px;opacity:.9;">Share your location</div>
    </div>
    <div class="lp-section">
        <p style="font-size:13.5px;line-height:1.6;">
            Πατώντας «Αποδέχομαι» η θέση του κινητού σου θα εμφανίζεται στον χάρτη της ομάδας διάσωσης, ώστε να σε βρουν πιο εύκολα. Η θέση σταματά να στέλνεται αν κλείσεις αυτή τη σελίδα.<br><br>
            <span class="text-muted">By tapping "I agree", your phone's location will appear on the rescue team's map so they can find you. Sending stops if you close this page.</span>
        </p>
        <button type="button" id="lpConsentBtn" class="btn btn-primary btn-lg w-100 fw-semibold">
            <i class="bi bi-check-circle me-1"></i>Αποδέχομαι / I agree
        </button>
        <div id="lpStatus"></div>
    </div>
<?php endif; ?>
</div>

<?php if ($link): ?>
<script>
(function() {
    const csrfToken = <?= json_encode($csrfToken) ?>;
    const token = <?= json_encode($token) ?>;
    const btn = document.getElementById('lpConsentBtn');
    const statusEl = document.getElementById('lpStatus');
    let watchId = null;
    let lastSent = 0;
    const MIN_INTERVAL_MS = 10000;

    function setStatus(cls, text) {
        statusEl.className = 'lp-status ' + cls;
        statusEl.textContent = text;
    }

    function sendPosition(pos) {
        const now = Date.now();
        if (now - lastSent < MIN_INTERVAL_MS) return;
        lastSent = now;
        const body = new URLSearchParams({
            ajax: '1', csrf_token: csrfToken, token: token,
            lat: pos.coords.latitude, lng: pos.coords.longitude,
            accuracy: pos.coords.accuracy || ''
        });
        fetch('locate.php?token=' + encodeURIComponent(token), { method: 'POST', body })
            .then(r => r.json())
            .then(data => {
                if (data.ok) {
                    setStatus('ok', 'Η θέση σου στάλθηκε · Location sent (' + new Date().toLocaleTimeString('el-GR') + ')');
                } else {
                    setStatus('err', 'Ο σύνδεσμος δεν είναι πλέον ενεργός. / This link is no longer active.');
                    if (watchId !== null) navigator.geolocation.clearWatch(watchId);
                }
            })
            .catch(() => setStatus('err', 'Πρόβλημα σύνδεσης, θα ξαναπροσπαθήσω. / Connection problem, retrying.'));
    }

    btn.addEventListener('click', function() {
        if (!navigator.geolocation) {
            setStatus('err', 'Το κινητό δεν υποστηρίζει εντοπισμό θέσης. / This phone has no geolocation support.');
            return;
        }
        btn.disabled = true;
        btn.innerHTML = '<i class="bi bi-broadcast me-1"></i>Αποστολή θέσης... / Sending...';
        setStatus('wait', 'Περιμένω GPS... / Waiting for GPS...');
        watchId = navigator.geolocation.watchPosition(sendPosition, function(err) {
            setStatus('err', 'Δεν επιτράπηκε ο εντοπισμός θέσης. / Location permission was not granted.');
        }, { enableHighAccuracy: true, maximumAge: 5000, timeout: 20000 });
    });
})();
</script>
<?php endif; ?>
</body>
</html>
