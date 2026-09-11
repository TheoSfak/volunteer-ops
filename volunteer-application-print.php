<?php
/**
 * VolunteerOps - Print/export view for a single candidate application.
 *
 * volunteer-applications.php already exports the whole filtered list as CSV.
 * This is the other half: one candidate, laid out to be printed or saved as a
 * PDF and put in a folder — the form staff actually hand to whoever interviews
 * them. Same permission as the list it is reached from.
 *
 * Standalone page rather than a print stylesheet on the modal, matching
 * inventory-print.php and mission-report-print.php: the modal lives inside the
 * list's own layout, and printing it drags the whole table along with it.
 */

require_once __DIR__ . '/bootstrap.php';
requirePermission('volunteers_view');

$id = (int) get('id');
$app = $id ? dbFetchOne("SELECT * FROM volunteer_applications WHERE id = ?", [$id]) : null;

if (!$app) {
    setFlash('error', 'Η αίτηση δεν βρέθηκε.');
    redirect('volunteer-applications.php');
}

$appName = getSetting('app_name', APP_NAME);
$dash = '—';

// Same labels the list's CSV export uses, so a printed card and an exported
// row can be read side by side without translating between them.
$rows = [
    'Ονοματεπώνυμο'   => $app['full_name'],
    'Πατρώνυμο'       => $app['patronymic'] ?: $dash,
    'Ημ. Γέννησης'    => $app['birth_date'] ? formatDate($app['birth_date']) : $dash,
    'Διεύθυνση'       => $app['address'] ?: $dash,
    'Τ.Κ.'            => $app['postal_code'] ?: $dash,
    'Πόλη'            => $app['city'] ?: $dash,
    'Τηλ. Οικίας'     => $app['home_phone'] ?: $dash,
    'Τηλ. Κινητό'     => $app['mobile_phone'],
    'Email'           => $app['email'],
    'Επάγγελμα'       => $app['occupation'] ?: $dash,
];

$statusRows = [
    'Κατάσταση'          => VOL_APP_STATUS_LABELS[$app['status']] ?? $app['status'],
    'Ημερομηνία Αίτησης' => $app['created_at'] ? formatDateTime($app['created_at']) : $dash,
    'Ημ/νία Επικοινωνίας' => $app['contacted_at'] ? formatDateTime($app['contacted_at']) : $dash,
    'Ημ/νία Απόρριψης'   => $app['rejected_at'] ? formatDateTime($app['rejected_at']) : $dash,
    'Ημ/νία Έγκρισης'    => $app['converted_at'] ? formatDateTime($app['converted_at']) : $dash,
    'Συναίνεση GDPR'     => $app['gdpr_consent_at'] ? formatDateTime($app['gdpr_consent_at']) : $dash,
];
?>
<!DOCTYPE html>
<html lang="el">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Αίτηση Υποψηφίου — <?= h($app['full_name']) ?></title>
<style>
    body { font-family: "Segoe UI", Tahoma, Arial, sans-serif; color: #212529; margin: 0; padding: 24px; background: #f8f9fa; }
    .sheet { max-width: 780px; margin: 0 auto; background: #fff; padding: 32px 36px; border-radius: 6px; box-shadow: 0 1px 4px rgba(0,0,0,.12); }
    h1 { font-size: 20px; margin: 0 0 4px; }
    .sub { color: #6c757d; font-size: 13px; margin-bottom: 22px; }
    h2 { font-size: 14px; text-transform: uppercase; letter-spacing: .04em; color: #495057; margin: 26px 0 8px; border-bottom: 1px solid #dee2e6; padding-bottom: 4px; }
    table { width: 100%; border-collapse: collapse; }
    th, td { text-align: left; vertical-align: top; padding: 6px 8px; border-bottom: 1px solid #f1f3f5; font-size: 14px; }
    th { width: 190px; color: #495057; font-weight: 600; }
    .notes { white-space: pre-wrap; font-size: 14px; padding: 8px; background: #f8f9fa; border-radius: 4px; min-height: 48px; }
    .actions { max-width: 780px; margin: 0 auto 16px; }
    .actions a, .actions button { font: inherit; font-size: 14px; padding: 7px 14px; border-radius: 4px; border: 1px solid #adb5bd; background: #fff; color: #212529; cursor: pointer; text-decoration: none; display: inline-block; }
    .actions button { background: #0d6efd; border-color: #0d6efd; color: #fff; }
    @media print {
        body { background: #fff; padding: 0; }
        .sheet { box-shadow: none; border-radius: 0; max-width: none; padding: 0; }
        .actions { display: none; }
    }
</style>
</head>
<body>

<div class="actions">
    <button onclick="window.print()">&#128438; Εκτύπωση</button>
    <a href="volunteer-applications.php">&#8592; Πίσω στις αιτήσεις</a>
</div>

<div class="sheet">
    <h1>Αίτηση Υποψηφίου Εθελοντή</h1>
    <div class="sub"><?= h($appName) ?> — αίτηση #<?= (int) $app['id'] ?></div>

    <h2>Στοιχεία Υποψηφίου</h2>
    <table>
        <?php foreach ($rows as $label => $value): ?>
        <tr><th><?= h($label) ?></th><td><?= h((string) $value) ?></td></tr>
        <?php endforeach; ?>
    </table>

    <h2>Πορεία Αίτησης</h2>
    <table>
        <?php foreach ($statusRows as $label => $value): ?>
        <tr><th><?= h($label) ?></th><td><?= h((string) $value) ?></td></tr>
        <?php endforeach; ?>
    </table>

    <h2>Σημειώσεις Προσωπικού</h2>
    <div class="notes"><?= h((string) ($app['admin_notes'] ?? '')) ?></div>
</div>

<script>
    // Same as the other print views in this app: open the dialog by itself, far
    // enough after load that the sheet is laid out, but still cancellable for
    // anyone who only wanted to read or save it.
    window.addEventListener('load', function () {
        setTimeout(function () { window.print(); }, 400);
    });
</script>

</body>
</html>
