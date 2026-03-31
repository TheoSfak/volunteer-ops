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
        <div class="d-flex align-items-center justify-content-between">
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
    </div>
</div>

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
