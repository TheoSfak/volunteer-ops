<?php
/**
 * VolunteerOps - Push Notification Diagnostics
 * Accessible only by System Admin
 */
require_once __DIR__ . '/bootstrap.php';
requireRole([ROLE_SYSTEM_ADMIN]);

$pageTitle = 'Διαγνωστικά Push Notifications';

// ── Run test push and capture result ──────────────────────────────────────────
$testResult = null;
if (isPost() && post('action') === 'test') {
    verifyCsrf();
    $userId = (int)getCurrentUserId();

    // Temporarily override error_log to capture it
    $logFile = sys_get_temp_dir() . '/vo_push_debug_' . $userId . '.log';
    ini_set('error_log', $logFile);

    $sent = sendPushToUser($userId, 'Διαγνωστική Ειδοποίηση', 'Εγχείρημα: ' . date('H:i:s'), [
        'url' => 'push-debug.php',
        'tag' => 'vo-debug',
    ]);

    // Collect any logged errors
    $logOutput = '';
    if (file_exists($logFile)) {
        $logOutput = file_get_contents($logFile);
        @unlink($logFile);
    }

    $testResult = ['sent' => $sent, 'log' => $logOutput];
}

// ── Gather diagnostics ────────────────────────────────────────────────────────
$userId = (int)getCurrentUserId();

// VAPID keys
$vapidPub  = getSetting('vapid_public_key', '');
$vapidPriv = getSetting('vapid_private_key', '');
$vapidContact = getSetting('vapid_contact', '');

// Subscriptions for current user
$mySubs = dbFetchAll("SELECT id, endpoint, user_agent, created_at, updated_at FROM push_subscriptions WHERE user_id = ?", [$userId]);

// All subscription counts
$totalSubs   = (int)dbFetchValue("SELECT COUNT(*) FROM push_subscriptions");
$distinctUsers = (int)dbFetchValue("SELECT COUNT(DISTINCT user_id) FROM push_subscriptions");

// PHP capabilities
$hasOpenssl = extension_loaded('openssl');
$hasCurl    = extension_loaded('curl');
$hasEcKey   = false;
$ecError    = '';
if ($hasOpenssl) {
    $testKey = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    $hasEcKey = (bool)$testKey;
    if (!$hasEcKey) $ecError = openssl_error_string();
}

// HTTPS check
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

include __DIR__ . '/includes/header.php';
?>

<div class="container-fluid py-4">
<div class="d-flex align-items-center gap-3 mb-4">
    <h2 class="mb-0"><i class="bi bi-bell-fill text-primary"></i> Διαγνωστικά Push Notifications</h2>
</div>

<?php if ($testResult !== null): ?>
<div class="alert <?= $testResult['sent'] > 0 ? 'alert-success' : 'alert-danger' ?> mb-4">
    <h5 class="alert-heading">
        <?= $testResult['sent'] > 0 ? '<i class="bi bi-check-circle-fill"></i> Εστάλη επιτυχώς!' : '<i class="bi bi-x-circle-fill"></i> Δεν εστάλη' ?>
    </h5>
    <p>Subscriptions που λήφθηκαν απόκριση: <strong><?= $testResult['sent'] ?></strong></p>
    <?php if ($testResult['log']): ?>
    <hr>
    <p class="mb-1"><strong>PHP error_log output:</strong></p>
    <pre class="mb-0 small bg-dark text-light p-3 rounded" style="white-space:pre-wrap;word-break:break-all;"><?= h($testResult['log']) ?></pre>
    <?php else: ?>
    <p class="mb-0 small text-muted">(Κανένα PHP error_log output — τα πάντα ήταν εντάξει server-side)</p>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="row g-4">

<!-- Server Environment -->
<div class="col-md-6">
<div class="card h-100">
<div class="card-header fw-semibold"><i class="bi bi-server"></i> Περιβάλλον Server</div>
<div class="card-body">
<table class="table table-sm mb-0">
<tbody>
<tr>
    <td>BASE_URL</td>
    <td><code><?= h(BASE_URL) ?></code></td>
</tr>
<tr>
    <td>HTTPS</td>
    <td>
        <?php if ($isHttps): ?>
            <span class="badge bg-success"><i class="bi bi-lock-fill"></i> Ναι</span>
        <?php else: ?>
            <span class="badge bg-danger"><i class="bi bi-exclamation-triangle-fill"></i> ΌΧΙ — Push δεν λειτουργεί χωρίς HTTPS!</span>
        <?php endif; ?>
    </td>
</tr>
<tr>
    <td>PHP Version</td>
    <td><?= h(PHP_VERSION) ?></td>
</tr>
<tr>
    <td>OpenSSL</td>
    <td><?= $hasOpenssl ? '<span class="badge bg-success">Ενεργό</span>' : '<span class="badge bg-danger">Ανενεργό</span>' ?></td>
</tr>
<tr>
    <td>EC Key Generation</td>
    <td>
        <?php if ($hasEcKey): ?>
            <span class="badge bg-success">OK</span>
        <?php else: ?>
            <span class="badge bg-danger">ΣΦΑΛΜΑ</span>
            <?php if ($ecError): ?><br><small class="text-danger"><?= h($ecError) ?></small><?php endif; ?>
        <?php endif; ?>
    </td>
</tr>
<tr>
    <td>cURL</td>
    <td><?= $hasCurl ? '<span class="badge bg-success">Ενεργό</span>' : '<span class="badge bg-danger">Ανενεργό</span>' ?></td>
</tr>
</tbody>
</table>
</div>
</div>
</div>

<!-- VAPID Keys -->
<div class="col-md-6">
<div class="card h-100">
<div class="card-header fw-semibold"><i class="bi bi-key-fill"></i> VAPID Keys</div>
<div class="card-body">
<?php if (!$vapidPub || !$vapidPriv): ?>
    <div class="alert alert-danger mb-0"><i class="bi bi-exclamation-triangle-fill"></i> Τα VAPID keys δεν έχουν οριστεί! Εκτελέστε <code>generate_vapid_keys.php</code>.</div>
<?php else: ?>
<table class="table table-sm mb-0">
<tbody>
<tr>
    <td>Public Key</td>
    <td>
        <code class="small"><?= h(substr($vapidPub, 0, 12)) ?>…</code>
        <?php $pubLen = strlen($vapidPub); ?>
        <?php if ($pubLen === 87): ?>
            <span class="badge bg-success"><?= $pubLen ?> χαρ. ✓</span>
        <?php else: ?>
            <span class="badge bg-danger"><?= $pubLen ?> χαρ. — Πρέπει να είναι 87!</span>
        <?php endif; ?>
    </td>
</tr>
<tr>
    <td>Private Key</td>
    <td>
        <?php $privLen = strlen($vapidPriv); ?>
        <?php if ($privLen === 43): ?>
            <span class="badge bg-success"><?= $privLen ?> χαρ. ✓</span>
        <?php else: ?>
            <span class="badge bg-danger"><?= $privLen ?> χαρ. — Πρέπει να είναι 43!</span>
        <?php endif; ?>
    </td>
</tr>
<tr>
    <td>Contact</td>
    <td><code class="small"><?= h($vapidContact ?: '(κενό)') ?></code></td>
</tr>
</tbody>
</table>
<?php if ($pubLen !== 87 || $privLen !== 43): ?>
<div class="alert alert-warning mt-3 mb-0 small">
    <i class="bi bi-exclamation-triangle-fill"></i>
    Τα VAPID keys έχουν λάθος μέγεθος. Διαγράψτε τα από τον πίνακα <code>settings</code> και εκτελέστε ξανά <code>generate_vapid_keys.php</code>.
</div>
<?php endif; ?>
<?php endif; ?>
</div>
</div>
</div>

<!-- Your Subscriptions -->
<div class="col-12">
<div class="card">
<div class="card-header fw-semibold d-flex justify-content-between align-items-center">
    <span><i class="bi bi-phone"></i> Οι Συνδρομές σας (user_id=<?= $userId ?>)</span>
    <span class="badge bg-primary"><?= count($mySubs) ?> συνδρομές</span>
</div>
<div class="card-body">
<?php if (empty($mySubs)): ?>
    <div class="alert alert-warning mb-0">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <strong>Δεν υπάρχουν εγγεγραμμένες συσκευές!</strong>
        Ανοίξτε το <a href="notification-preferences.php">Notification Preferences</a> και πατήστε
        «Ενεργοποίηση Push» για να εγγράψετε αυτή τη συσκευή.
    </div>
<?php else: ?>
<div class="table-responsive">
<table class="table table-sm table-bordered mb-0">
<thead class="table-light">
    <tr><th>#</th><th>Endpoint (πρώτα 80 χαρ.)</th><th>User Agent</th><th>Δημιουργήθηκε</th><th>Ενημερώθηκε</th></tr>
</thead>
<tbody>
<?php foreach ($mySubs as $s): ?>
<tr>
    <td><?= (int)$s['id'] ?></td>
    <td><code class="small"><?= h(substr($s['endpoint'], 0, 80)) ?>…</code></td>
    <td><small><?= h($s['user_agent'] ?? '') ?></small></td>
    <td><?= h($s['created_at']) ?></td>
    <td><?= h($s['updated_at'] ?? '') ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
<?php endif; ?>
</div>
</div>
</div>

<!-- System totals -->
<div class="col-md-6">
<div class="card">
<div class="card-header fw-semibold"><i class="bi bi-graph-up"></i> Σύστημα</div>
<div class="card-body">
<table class="table table-sm mb-0">
<tr><td>Σύνολο subscriptions</td><td><strong><?= $totalSubs ?></strong></td></tr>
<tr><td>Μοναδικοί χρήστες με push</td><td><strong><?= $distinctUsers ?></strong></td></tr>
</table>
</div>
</div>
</div>

<!-- Send test -->
<div class="col-md-6">
<div class="card">
<div class="card-header fw-semibold"><i class="bi bi-send-fill"></i> Δοκιμαστική Αποστολή</div>
<div class="card-body">
<?php if (empty($mySubs)): ?>
    <p class="text-muted small mb-0">Δεν υπάρχουν subscriptions για δοκιμή.</p>
<?php else: ?>
    <p class="small mb-3">Στέλνει push αμέσως στη συσκευή/ές σας. Ελέγξτε το error_log output παρακάτω για λεπτομέρειες σφαλμάτων.</p>
    <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="test">
        <button type="submit" class="btn btn-primary">
            <i class="bi bi-send"></i> Αποστολή Δοκιμαστικής Ειδοποίησης
        </button>
    </form>
<?php endif; ?>
</div>
</div>
</div>

</div><!-- row -->
</div><!-- container -->

<?php include __DIR__ . '/includes/footer.php'; ?>
