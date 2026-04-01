<?php
/**
 * VolunteerOps - Ρυθμίσεις Ειδοποιήσεων Χρήστη
 * Allows each user to control which notifications they receive (email & in-app).
 */
require_once __DIR__ . '/bootstrap.php';
requireLogin();

$pageTitle = 'Ρυθμίσεις Ειδοποιήσεων';
$currentPage = 'notification-preferences';

$userId = getCurrentUserId();

// ── Handle POST ────────────────────────────────────────────────────────────
if (isPost()) {
    verifyCsrf();

    // Fetch all configurable notification codes
    $allSettings = dbFetchAll("SELECT code FROM notification_settings ORDER BY id");
    $prefs = [];

    foreach ($allSettings as $ns) {
        $code = $ns['code'];
        if (in_array($code, NON_CONFIGURABLE_NOTIFICATIONS)) {
            continue; // skip mandatory notifications
        }
        $prefs[$code] = [
            'email_enabled'  => isset($_POST['email_' . $code]) ? 1 : 0,
            'in_app_enabled' => isset($_POST['inapp_' . $code]) ? 1 : 0,
            'push_enabled'   => isset($_POST['push_' . $code]) ? 1 : 0,
        ];
    }

    saveUserNotificationPrefs($userId, $prefs);
    setFlash('success', 'Οι ρυθμίσεις ειδοποιήσεων αποθηκεύτηκαν.');
    redirect('notification-preferences.php');
}

// ── Fetch data for display ─────────────────────────────────────────────────
// Global notification settings (what the admin has enabled)
$globalSettings = dbFetchAll("SELECT * FROM notification_settings ORDER BY id");

// User's current preferences
$userPrefs = getUserNotificationPrefs($userId);

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-bell"></i> <?= h($pageTitle) ?></h2>
</div>

<!-- ══ Push Notification Subscription Card ══ -->
<div class="card shadow-sm mb-4 border-primary border-opacity-25">
    <div class="card-body">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
            <div>
                <h5 class="mb-1"><i class="bi bi-phone-vibrate text-primary"></i> Push Ειδοποιήσεις</h5>
                <p class="text-muted mb-0 small">Λάβετε ειδοποιήσεις στον browser σας ακόμα και όταν δεν είστε στην εφαρμογή.</p>
            </div>
            <div class="text-end">
                <div id="vo-push-status" class="mb-2"><span class="badge bg-secondary">Έλεγχος...</span></div>
                <button type="button" id="vo-push-toggle" class="btn btn-secondary btn-sm" disabled>Φόρτωση...</button>
                <button type="button" id="vo-push-test" class="btn btn-outline-success btn-sm ms-1" style="display:none;" onclick="VoPush.sendTest().then(function(r){alert(r.success?'Στάλθηκε!':'Αποτυχία');})">
                    <i class="bi bi-send"></i> Δοκιμή
                </button>
            </div>
        </div>

        <!-- iOS instructions — shown only on iOS Safari -->
        <div id="vo-ios-push-info" style="display:none;" class="mt-3 alert alert-info mb-0 small">
            <strong><i class="bi bi-apple"></i> Χρήστες iPhone / iPad:</strong>
            Οι push ειδοποιήσεις στο iOS απαιτούν να έχετε <strong>εγκαταστήσει την εφαρμογή</strong> στην αρχική οθόνη
            <em>και</em> να την ανοίγετε από εκεί (όχι από το Safari).
            <ol class="mt-2 mb-0 ps-3">
                <li>Ανοίξτε <strong>Safari</strong> και πηγαίνετε στο <code><?= h(BASE_URL) ?></code></li>
                <li>Πατήστε το κουμπί <strong>Κοινοποίηση</strong> <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" fill="currentColor" viewBox="0 0 16 16"><path d="M11 2.5a2.5 2.5 0 1 1 .603 1.628l-6.718 3.12a2.499 2.499 0 0 1 0 1.504l6.718 3.12a2.5 2.5 0 1 1-.488.876l-6.718-3.12a2.5 2.5 0 1 1 0-3.256l6.718-3.12A2.5 2.5 0 0 1 11 2.5z"/></svg></li>
                <li>Επιλέξτε <strong>«Προσθήκη στην Αρχική Οθόνη»</strong></li>
                <li>Ανοίξτε την εφαρμογή <strong>από το εικονίδιο</strong> στην αρχική οθόνη</li>
                <li>Επιστρέψτε σε αυτή τη σελίδα και πατήστε <strong>«Ενεργοποίηση Push»</strong></li>
            </ol>
            <div class="mt-2 text-muted">Απαιτείται iOS 16.4 ή νεότερο.</div>
        </div>

        <!-- Android battery optimization tip — shown only on Android -->
        <div id="vo-android-push-info" style="display:none;" class="mt-3 alert alert-warning mb-0 small">
            <strong><i class="bi bi-android2"></i> Χρήστες Android:</strong>
            Αν λαμβάνετε σφάλμα σύνδεσης με FCM, ελέγξτε:
            <ul class="mt-1 mb-0 ps-3">
                <li>Ρυθμίσεις → Εφαρμογές → Chrome → Μπαταρία → επιλέξτε <strong>«Χωρίς περιορισμούς»</strong></li>
                <li>Απενεργοποιήστε το <strong>«Παύση δραστηριότητας εφαρμογής»</strong> για το Chrome</li>
            </ul>
        </div>
    </div>
</div>
<script>
(function() {
    var ua = navigator.userAgent;
    var isIos = /iphone|ipad|ipod/i.test(ua);
    var isAndroid = /android/i.test(ua);
    var isStandalone = window.navigator.standalone === true || window.matchMedia('(display-mode: standalone)').matches;
    if (isIos) {
        document.getElementById('vo-ios-push-info').style.display = '';
        // Hide the toggle button on iOS non-standalone (push won't work)
        if (!isStandalone) {
            var btn = document.getElementById('vo-push-toggle');
            if (btn) { btn.style.display = 'none'; }
            var st = document.getElementById('vo-push-status');
            if (st) { st.innerHTML = '<span class="badge bg-warning text-dark"><i class="bi bi-exclamation-circle"></i> Απαιτείται εγκατάσταση PWA</span>'; }
        }
    } else if (isAndroid) {
        document.getElementById('vo-android-push-info').style.display = '';
    }
})();
</script>

<div class="card shadow-sm">
    <div class="card-body">
        <p class="text-muted mb-4">
            <i class="bi bi-info-circle"></i>
            Επιλέξτε ποιες ειδοποιήσεις θέλετε να λαμβάνετε. Οι ειδοποιήσεις που είναι απενεργοποιημένες
            από τον διαχειριστή δεν μπορούν να ενεργοποιηθούν.
        </p>

        <form method="post">
            <?= csrfField() ?>

            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 30%;">Ειδοποίηση</th>
                            <th class="text-center" style="width: 15%;">
                                <i class="bi bi-envelope"></i> Email
                            </th>
                            <th class="text-center" style="width: 15%;">
                                <i class="bi bi-bell"></i> Εντός Εφ.
                            </th>
                            <th class="text-center" style="width: 15%;">
                                <i class="bi bi-phone-vibrate"></i> Push
                            </th>
                            <th style="width: 15%;">Κατάσταση</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($globalSettings as $gs):
                            $code = $gs['code'];
                            $isMandatory = in_array($code, NON_CONFIGURABLE_NOTIFICATIONS);
                            $globalEnabled = (int)$gs['email_enabled'];

                            // User prefs: default to ON if no row exists
                            $userEmail = isset($userPrefs[$code]) ? $userPrefs[$code]['email_enabled'] : 1;
                            $userInApp = isset($userPrefs[$code]) ? $userPrefs[$code]['in_app_enabled'] : 1;
                            $userPush  = isset($userPrefs[$code]) ? ($userPrefs[$code]['push_enabled'] ?? 1) : 1;
                        ?>
                        <tr class="<?= (!$globalEnabled && !$isMandatory) ? 'table-secondary' : '' ?>">
                            <td>
                                <strong><?= h($gs['name']) ?></strong>
                                <?php if (!empty($gs['description'])): ?>
                                    <br><small class="text-muted"><?= h($gs['description']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if ($isMandatory): ?>
                                    <i class="bi bi-check-circle-fill text-success fs-5" title="Υποχρεωτική"></i>
                                    <input type="hidden" name="email_<?= h($code) ?>" value="1">
                                <?php elseif (!$globalEnabled): ?>
                                    <i class="bi bi-x-circle text-muted fs-5" title="Απενεργοποιημένο από διαχειριστή"></i>
                                <?php else: ?>
                                    <div class="form-check form-switch d-flex justify-content-center">
                                        <input class="form-check-input" type="checkbox" role="switch"
                                               name="email_<?= h($code) ?>" value="1"
                                               id="email_<?= h($code) ?>"
                                               <?= $userEmail ? 'checked' : '' ?>>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if ($isMandatory): ?>
                                    <i class="bi bi-check-circle-fill text-success fs-5" title="Υποχρεωτική"></i>
                                    <input type="hidden" name="inapp_<?= h($code) ?>" value="1">
                                <?php else: ?>
                                    <div class="form-check form-switch d-flex justify-content-center">
                                        <input class="form-check-input" type="checkbox" role="switch"
                                               name="inapp_<?= h($code) ?>" value="1"
                                               id="inapp_<?= h($code) ?>"
                                               <?= $userInApp ? 'checked' : '' ?>>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if ($isMandatory): ?>
                                    <i class="bi bi-check-circle-fill text-success fs-5" title="Υποχρεωτική"></i>
                                    <input type="hidden" name="push_<?= h($code) ?>" value="1">
                                <?php elseif (!$globalEnabled): ?>
                                    <i class="bi bi-x-circle text-muted fs-5" title="Απενεργοποιημένο από διαχειριστή"></i>
                                <?php else: ?>
                                    <div class="form-check form-switch d-flex justify-content-center">
                                        <input class="form-check-input" type="checkbox" role="switch"
                                               name="push_<?= h($code) ?>" value="1"
                                               id="push_<?= h($code) ?>"
                                               <?= $userPush ? 'checked' : '' ?>>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($isMandatory): ?>
                                    <span class="badge bg-info">Υποχρεωτική</span>
                                <?php elseif (!$globalEnabled): ?>
                                    <span class="badge bg-secondary">Απενεργ. από Διαχ.</span>
                                <?php else: ?>
                                    <span class="badge bg-success">Ενεργή</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="d-flex justify-content-between align-items-center mt-3">
                <div>
                    <button type="button" class="btn btn-outline-success btn-sm" onclick="toggleAll(true)">
                        <i class="bi bi-check-all"></i> Ενεργοποίηση Όλων
                    </button>
                    <button type="button" class="btn btn-outline-danger btn-sm" onclick="toggleAll(false)">
                        <i class="bi bi-x-lg"></i> Απενεργοποίηση Όλων
                    </button>
                </div>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg"></i> Αποθήκευση
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function toggleAll(state) {
    document.querySelectorAll('.form-check-input[type="checkbox"]').forEach(function(cb) {
        cb.checked = state;
    });
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
