<?php
/** Annual volunteer subscription management. */
require_once __DIR__ . '/bootstrap.php';
requirePermission('subscriptions_manage');

$pageTitle = 'Ετήσιες Συνδρομές';

/**
 * Store either a normally selected receipt or a photo captured by the mobile camera.
 * Returns [originalName, storedName], or null when no file was submitted.
 */
function storeSubscriptionReceipt(int $userId, string $volunteerName): ?array {
    $file = null;
    foreach (['receipt', 'receipt_camera'] as $field) {
        if (!empty($_FILES[$field]['name'])) {
            $file = $_FILES[$field];
            break;
        }
    }
    if (!$file) return null;

    $allowed = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];
    $mime = !empty($file['tmp_name']) ? (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) : false;
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || ($file['size'] ?? 0) > 10 * 1024 * 1024 || !isset($allowed[$mime])) {
        throw new RuntimeException('Η απόδειξη πρέπει να είναι PDF, JPG ή PNG έως 10MB.');
    }

    $dir = __DIR__ . '/uploads/subscription-receipts/';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Δεν ήταν δυνατή η δημιουργία του φακέλου αποδείξεων.');
    }
    $nameParts = preg_split('/\s+/', trim($volunteerName));
    $surname = end($nameParts) ?: 'receipt-' . $userId;
    $greekToLatin = [
        'Α'=>'A','Β'=>'V','Γ'=>'G','Δ'=>'D','Ε'=>'E','Ζ'=>'Z','Η'=>'I','Θ'=>'Th','Ι'=>'I','Κ'=>'K','Λ'=>'L','Μ'=>'M','Ν'=>'N','Ξ'=>'X','Ο'=>'O','Π'=>'P','Ρ'=>'R','Σ'=>'S','Τ'=>'T','Υ'=>'Y','Φ'=>'F','Χ'=>'Ch','Ψ'=>'Ps','Ω'=>'O',
        'ά'=>'a','έ'=>'e','ή'=>'i','ί'=>'i','ό'=>'o','ύ'=>'y','ώ'=>'o','ϊ'=>'i','ϋ'=>'y','ΐ'=>'i','ΰ'=>'y',
        'α'=>'a','β'=>'v','γ'=>'g','δ'=>'d','ε'=>'e','ζ'=>'z','η'=>'i','θ'=>'th','ι'=>'i','κ'=>'k','λ'=>'l','μ'=>'m','ν'=>'n','ξ'=>'x','ο'=>'o','π'=>'p','ρ'=>'r','σ'=>'s','ς'=>'s','τ'=>'t','υ'=>'y','φ'=>'f','χ'=>'ch','ψ'=>'ps','ω'=>'o',
    ];
    $safeBase = strtolower(strtr($surname, $greekToLatin));
    $safeBase = preg_replace('/[^a-z0-9]+/i', '-', $safeBase);
    $safeBase = trim($safeBase, '-') ?: 'receipt-' . $userId;
    $extension = $allowed[$mime];
    $storedName = $safeBase . '.' . $extension;
    for ($suffix = 2; is_file($dir . $storedName); $suffix++) {
        $storedName = $safeBase . '-' . $suffix . '.' . $extension;
    }
    if (!move_uploaded_file($file['tmp_name'], $dir . $storedName)) {
        throw new RuntimeException('Δεν ήταν δυνατή η αποθήκευση της απόδειξης.');
    }
    return [$storedName, $storedName];
}

if (isPost()) {
    verifyCsrf();
    if (post('action') === 'record_payment') {
        $userId = (int) post('user_id');
        $paymentDate = post('payment_date');
        $requestedExpiryDate = post('expiry_date');
        $amount = str_replace(',', '.', trim(post('amount')));
        $method = trim(post('payment_method'));
        $receiptNumber = trim(post('receipt_number'));
        $notes = trim(post('notes'));
        $volunteer = dbFetchOne("SELECT id, name FROM users WHERE id = ? AND is_active = 1", [$userId]);

        if (!$volunteer || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $paymentDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $requestedExpiryDate)) {
            setFlash('error', 'Επιλέξτε έγκυρο εθελοντή και ημερομηνία πληρωμής.');
        } else {
            $latest = dbFetchOne("SELECT expiry_date FROM volunteer_subscriptions WHERE user_id = ? ORDER BY expiry_date DESC, id DESC LIMIT 1", [$userId]);
            $activeSubscription = dbFetchOne("SELECT id, expiry_date FROM volunteer_subscriptions WHERE user_id = ? AND expiry_date >= CURDATE() ORDER BY expiry_date DESC, id DESC LIMIT 1", [$userId]);
            if ($activeSubscription) {
                setFlash('warning', 'Υπάρχει ήδη ενεργή συνδρομή έως ' . formatDate($activeSubscription['expiry_date']) . '. Επιλέξτε επεξεργασία ή επιβεβαιώστε ότι θέλετε νέα καταχώρηση.');
                redirect('subscriptions.php?edit=' . $activeSubscription['id']);
            }
            $reactivationLimit = max(0, (int)getSetting('subscription_reactivation_days', 90));
            $coverageYears = max(1, min(5, (int)post('coverage_years', 1)));
            $daysSinceExpiry = $latest ? (int)floor((strtotime($paymentDate) - strtotime($latest['expiry_date'])) / 86400) : 0;
            $isReactivation = post('force_reactivation') === '1' || ($latest && $daysSinceExpiry > $reactivationLimit);
            $baseDate = $isReactivation || !$latest ? $paymentDate : $latest['expiry_date'];
            $calculatedExpiryDate = (new DateTime($baseDate))->modify('+' . $coverageYears . ' years')->format('Y-m-d');
            $expiryDate = $requestedExpiryDate;
            if ($expiryDate !== $calculatedExpiryDate && post('expiry_override_confirmed') !== '1') {
                setFlash('warning', 'Η ημερομηνία λήξης διαφέρει από την προτεινόμενη (' . formatDate($calculatedExpiryDate) . '). Επιβεβαιώστε την αλλαγή πριν την αποθήκευση.');
                redirect('subscriptions.php');
            }
            $receiptOriginal = null;
            $receiptStored = null;
            try {
                $storedReceipt = storeSubscriptionReceipt($userId, $volunteer['name']);
                if ($storedReceipt) [$receiptOriginal, $receiptStored] = $storedReceipt;
            } catch (RuntimeException $e) {
                setFlash('error', $e->getMessage());
                redirect('subscriptions.php');
            }

            dbInsert("INSERT INTO volunteer_subscriptions
                (user_id, payment_date, expiry_date, renewal_kind, coverage_years, amount, payment_method, receipt_number, receipt_original_name, receipt_stored_name, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", [
                $userId, $paymentDate, $expiryDate, $isReactivation ? 'REACTIVATION' : 'RENEWAL', $coverageYears, $amount === '' ? null : $amount,
                $method ?: null, $receiptNumber ?: null, $receiptOriginal, $receiptStored, $notes ?: null, getCurrentUserId()
            ]);
            logAudit('record_subscription_payment', 'volunteer_subscriptions', $userId, null, ['expiry_date' => $expiryDate]);
            setFlash('success', ($isReactivation ? 'Η συνδρομή επανενεργοποιήθηκε. ' : 'Η πληρωμή καταχωρήθηκε. ') . 'Η συνδρομή λήγει στις ' . formatDate($expiryDate) . '.');
        }
        redirect('subscriptions.php');
    }
    if (post('action') === 'edit_subscription') {
        $subscriptionId = (int)post('subscription_id');
        $paymentDate = post('payment_date');
        $expiryDate = post('expiry_date');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $paymentDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiryDate)) {
            setFlash('error', 'Οι ημερομηνίες δεν είναι έγκυρες.');
        } else {
            $old = dbFetchOne("SELECT vs.*, u.name AS volunteer_name FROM volunteer_subscriptions vs JOIN users u ON u.id = vs.user_id WHERE vs.id = ?", [$subscriptionId]);
            if ($old) {
                $amount = str_replace(',', '.', trim(post('amount')));
                $receiptNumber = trim(post('receipt_number'));
                $reset = $expiryDate !== $old['expiry_date'];
                $receiptOriginal = $old['receipt_original_name'];
                $receiptStored = $old['receipt_stored_name'];
                try {
                    $storedReceipt = storeSubscriptionReceipt((int)$old['user_id'], $old['volunteer_name']);
                    if ($storedReceipt) [$receiptOriginal, $receiptStored] = $storedReceipt;
                } catch (RuntimeException $e) {
                    setFlash('error', $e->getMessage());
                    redirect('subscriptions.php?edit=' . $subscriptionId);
                }
                dbExecute("UPDATE volunteer_subscriptions SET payment_date = ?, expiry_date = ?, amount = ?, payment_method = ?, receipt_number = ?, receipt_original_name = ?, receipt_stored_name = ?, notes = ?, renewal_kind = ?, reminder_sent_3m = ?, reminder_sent_1m = ?, reminder_sent_1w = ?, reminder_sent_expired = ? WHERE id = ?", [$paymentDate, $expiryDate, $amount === '' ? null : $amount, trim(post('payment_method')) ?: null, $receiptNumber ?: null, $receiptOriginal, $receiptStored, trim(post('notes')) ?: null, post('renewal_kind') === 'REACTIVATION' ? 'REACTIVATION' : 'RENEWAL', $reset ? 0 : $old['reminder_sent_3m'], $reset ? 0 : $old['reminder_sent_1m'], $reset ? 0 : $old['reminder_sent_1w'], $reset ? 0 : $old['reminder_sent_expired'], $subscriptionId]);
                logAudit('edit_subscription', 'volunteer_subscriptions', $subscriptionId);
                setFlash('success', 'Η συνδρομή ενημερώθηκε.');
            }
        }
        redirect('subscriptions.php');
    }
}

$volunteers = dbFetchAll("SELECT id, name, email FROM users WHERE is_active = 1 ORDER BY name");
$latestSubscriptions = dbFetchAll("SELECT vs.user_id, vs.expiry_date FROM volunteer_subscriptions vs WHERE vs.id = (SELECT vs2.id FROM volunteer_subscriptions vs2 WHERE vs2.user_id = vs.user_id ORDER BY vs2.expiry_date DESC, vs2.id DESC LIMIT 1)");
$latestSubscriptionExpiryMap = [];
foreach ($latestSubscriptions as $subscription) $latestSubscriptionExpiryMap[$subscription['user_id']] = $subscription['expiry_date'];
$subscriptionReactivationDays = max(0, (int)getSetting('subscription_reactivation_days', 90));
$subscriptionDateFormat = getSetting('date_format', 'd/m/Y');
if (!in_array($subscriptionDateFormat, ['d/m/Y', 'Y-m-d', 'd.m.Y'], true)) $subscriptionDateFormat = 'd/m/Y';
$filter = get('filter', 'all');
$validFilters = ['all', 'week', 'month', 'quarter', 'expired'];
if (!in_array($filter, $validFilters, true)) $filter = 'all';
$latestOnly = "vs.id = (SELECT vs2.id FROM volunteer_subscriptions vs2 WHERE vs2.user_id = vs.user_id ORDER BY vs2.expiry_date DESC, vs2.id DESC LIMIT 1)";
$filterSql = [
    'all' => '1=1',
    'week' => 'vs.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)',
    'month' => 'vs.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)',
    'quarter' => 'vs.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)',
    'expired' => 'vs.expiry_date < CURDATE()',
];
$counts = [];
foreach (['week', 'month', 'quarter', 'expired'] as $key) {
    $counts[$key] = (int)dbFetchValue("SELECT COUNT(*) FROM volunteer_subscriptions vs WHERE {$latestOnly} AND {$filterSql[$key]}");
}
$rows = dbFetchAll("SELECT vs.*, u.name AS volunteer_name, u.email, creator.name AS created_by_name
    FROM volunteer_subscriptions vs JOIN users u ON u.id = vs.user_id LEFT JOIN users creator ON creator.id = vs.created_by
    WHERE {$latestOnly} AND {$filterSql[$filter]} ORDER BY vs.expiry_date ASC, vs.id DESC");
$editing = get('edit') ? dbFetchOne("SELECT vs.*, u.name AS volunteer_name FROM volunteer_subscriptions vs JOIN users u ON u.id = vs.user_id WHERE vs.id = ?", [(int)get('edit')]) : null;

if (get('export') === 'excel') {
    $filterLabels = ['all' => 'oles', 'week' => '1-evdomada', 'month' => '1-minas', 'quarter' => '3-mines', 'expired' => 'ligmenes'];
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="subscriptions-' . $filterLabels[$filter] . '-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel Greek text
    fputcsv($out, ['Εθελοντής', 'Email', 'Ημερομηνία Πληρωμής', 'Ημερομηνία Λήξης', 'Έτη κάλυψης', 'Τύπος', 'Ποσό', 'Τρόπος Πληρωμής', 'Αριθμός Απόδειξης', 'Κατάσταση', 'Σημειώσεις'], ';');
    foreach ($rows as $row) {
        $days = (int)floor((strtotime($row['expiry_date']) - strtotime(date('Y-m-d'))) / 86400);
        $status = $days < 0 ? 'Ληγμένη' : ($days === 0 ? 'Λήγει σήμερα' : 'Ενεργή (' . $days . ' ημέρες)');
        fputcsv($out, [
            $row['volunteer_name'], $row['email'], $row['payment_date'], $row['expiry_date'], $row['coverage_years'] ?? 1,
            $row['renewal_kind'] === 'REACTIVATION' ? 'Επανενεργοποίηση' : 'Ανανέωση',
            $row['amount'] ?? '', $row['payment_method'] ?? '', $row['receipt_number'] ?? '', $status, $row['notes'] ?? ''
        ], ';');
    }
    fclose($out);
    exit;
}

include __DIR__ . '/includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0"><i class="bi bi-cash-coin me-2"></i>Ετήσιες Συνδρομές</h1>
    <div class="d-flex gap-2"><a class="btn btn-outline-success" href="subscriptions.php?filter=<?= h($filter) ?>&export=excel"><i class="bi bi-file-earmark-excel me-1"></i>Εξαγωγή Excel</a><button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#paymentModal"><i class="bi bi-plus-lg me-1"></i>Καταχώρηση πληρωμής</button></div>
</div>
<?php if ($editing): ?>
<div class="card border-primary mb-3">
    <div class="card-header"><strong>Επεξεργασία συνδρομής: <?= h($editing['volunteer_name']) ?></strong></div>
    <form id="editSubscriptionForm" method="post" enctype="multipart/form-data">
        <div class="card-body row g-3">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="edit_subscription">
            <input type="hidden" name="subscription_id" value="<?= $editing['id'] ?>">
            <div class="col-md-3"><label class="form-label">Πληρωμή</label><input class="form-control subscription-datepicker" type="text" name="payment_date" value="<?= h($editing['payment_date']) ?>" required></div>
            <div class="col-md-3"><label class="form-label">Λήξη</label><input class="form-control subscription-datepicker" type="text" name="expiry_date" value="<?= h($editing['expiry_date']) ?>" required></div>
            <div class="col-md-2"><label class="form-label">Ποσό</label><input class="form-control" type="number" step="0.01" name="amount" value="<?= h($editing['amount']) ?>"></div>
            <div class="col-md-2"><label class="form-label">Τύπος</label><select class="form-select" name="renewal_kind"><option value="RENEWAL" <?= $editing['renewal_kind'] === 'RENEWAL' ? 'selected' : '' ?>>Ανανέωση</option><option value="REACTIVATION" <?= $editing['renewal_kind'] === 'REACTIVATION' ? 'selected' : '' ?>>Επανενεργοποίηση</option></select></div>
            <div class="col-md-2"><label class="form-label">Τρόπος</label><input class="form-control" name="payment_method" value="<?= h($editing['payment_method']) ?>"></div>
            <div class="col-md-3"><label class="form-label">Αριθμός απόδειξης</label><input class="form-control" name="receipt_number" maxlength="100" value="<?= h($editing['receipt_number'] ?? '') ?>"></div>
            <div class="col-md-6" id="editReceiptUpload"><label class="form-label">Αντικατάσταση απόδειξης</label><input type="file" class="form-control" name="receipt" accept=".pdf,.jpg,.jpeg,.png"><div class="form-text">PDF, JPG ή PNG έως 10MB. Η νέα απόδειξη αντικαθιστά την καταχωρημένη.</div></div>
            <div class="col-12"><label class="form-label">Σημειώσεις</label><textarea class="form-control" name="notes" rows="2"><?= h($editing['notes']) ?></textarea></div>
        </div>
        <div class="card-footer"><button class="btn btn-primary">Αποθήκευση αλλαγών</button> <a href="subscriptions.php" class="btn btn-outline-secondary">Ακύρωση</a></div>
    </form>
</div>
<?php endif; ?>
<div class="d-flex flex-wrap gap-2 mb-3">
    <?php foreach (['all' => ['Όλες', null, 'secondary'], 'week' => ['Λήγουν σε 1 εβδομάδα', $counts['week'], 'danger'], 'month' => ['Λήγουν σε 1 μήνα', $counts['month'], 'warning'], 'quarter' => ['Λήγουν σε 3 μήνες', $counts['quarter'], 'info'], 'expired' => ['Ληγμένες', $counts['expired'], 'dark']] as $key => [$label, $count, $color]): ?>
        <a href="subscriptions.php?filter=<?= $key ?>" class="btn btn-sm <?= $filter === $key ? 'btn-' . $color : 'btn-outline-' . $color ?>"><?= $label ?><?php if ($count !== null): ?> <span class="badge text-bg-light ms-1"><?= $count ?></span><?php endif; ?></a>
    <?php endforeach; ?>
</div>
<div class="card shadow-sm"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
<thead><tr><th>Εθελοντής</th><th>Πληρωμή</th><th>Λήξη</th><th>Έτη</th><th style="min-width:130px">Ποσό</th><th>Τρόπος</th><th>Αρ. απόδειξης</th><th style="max-width:150px">Απόδειξη</th><th>Κατάσταση</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $row): $days = (int)floor((strtotime($row['expiry_date']) - strtotime(date('Y-m-d'))) / 86400); $badge = $days < 0 ? 'danger' : ($days <= 7 ? 'danger' : ($days <= 30 ? 'warning text-dark' : ($days <= 90 ? 'info text-dark' : 'success'))); $label = $days < 0 ? 'Ληγμένη' : ($days === 0 ? 'Λήγει σήμερα' : 'Ενεργή (' . $days . ' ημ.)'); $hasReceiptFile = !empty($row['receipt_stored_name']) && is_file(__DIR__ . '/uploads/subscription-receipts/' . basename($row['receipt_stored_name'])); ?>
<?php $isReceiptImage = $hasReceiptFile && in_array(strtolower(pathinfo($row['receipt_stored_name'], PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png'], true); ?>
<tr><td><a href="volunteer-view.php?id=<?= $row['user_id'] ?>"><?= h($row['volunteer_name']) ?></a></td><td><?= formatDate($row['payment_date']) ?></td><td><?= formatDate($row['expiry_date']) ?></td><td><?= (int)($row['coverage_years'] ?? 1) ?></td><td class="text-nowrap" style="min-width:130px"><?= $row['amount'] !== null ? number_format((float)$row['amount'], 2, ',', '.') . ' €' : '—' ?></td><td><?= h($row['payment_method'] ?: '—') ?></td><td><?= h($row['receipt_number'] ?: '—') ?></td><td style="max-width:150px"><?php if ($isReceiptImage): ?><button type="button" class="btn btn-sm btn-outline-secondary receipt-preview-btn text-truncate mw-100" data-bs-toggle="modal" data-bs-target="#receiptPreviewModal" data-preview-url="subscription-receipt.php?id=<?= $row['id'] ?>" data-preview-name="<?= h($row['receipt_original_name']) ?>"><i class="bi bi-eye"></i> Προβολή</button><?php elseif ($hasReceiptFile): ?><a class="btn btn-sm btn-outline-secondary text-truncate mw-100" href="subscription-receipt.php?id=<?= $row['id'] ?>" target="_blank" rel="noopener"><i class="bi bi-file-earmark-text"></i> <?= h($row['receipt_original_name']) ?></a><?php elseif (!empty($row['receipt_stored_name'])): ?><span class="text-danger small"><i class="bi bi-exclamation-triangle"></i> Μη διαθέσιμη</span><?php else: ?>—<?php endif; ?></td><td><span class="badge bg-<?= $badge ?>"><?= $label ?></span></td><td><a class="btn btn-sm btn-outline-primary" href="subscriptions.php?filter=<?= h($filter) ?>&edit=<?= $row['id'] ?>"><i class="bi bi-pencil"></i></a></td></tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="10" class="text-center text-muted py-4">Δεν υπάρχουν καταχωρημένες συνδρομές.</td></tr><?php endif; ?>
</tbody></table></div></div>

<div class="modal fade" id="receiptPreviewModal" tabindex="-1" aria-labelledby="receiptPreviewModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-truncate" id="receiptPreviewModalTitle">Προεπισκόπηση απόδειξης</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Κλείσιμο"></button>
            </div>
            <div class="modal-body text-center">
                <img id="receiptPreviewImage" src="" alt="Προεπισκόπηση απόδειξης" class="img-fluid rounded border" style="max-width:320px;max-height:65vh;object-fit:contain;">
                <div id="receiptPreviewError" class="alert alert-danger mt-3 d-none mb-0">Δεν ήταν δυνατή η προβολή της εικόνας. Δοκιμάστε να την ανοίξετε ξανά.</div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="paymentModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><form method="post" enctype="multipart/form-data">
<?= csrfField() ?><input type="hidden" name="action" value="record_payment"><div class="modal-header"><h5 class="modal-title">Καταχώρηση ετήσιας συνδρομής</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body"><div class="mb-3"><label class="form-label">Εθελοντής</label><select class="form-select" name="user_id" id="subscriptionVolunteer" required><option value="">Επιλέξτε…</option><?php foreach ($volunteers as $v): ?><option value="<?= $v['id'] ?>"><?= h($v['name']) ?> — <?= h($v['email']) ?></option><?php endforeach; ?></select></div><div class="row g-3"><div class="col-md-6"><label class="form-label">Ημερομηνία πληρωμής</label><input type="text" class="form-control subscription-datepicker" name="payment_date" id="subscriptionPaymentDate" value="<?= date('Y-m-d') ?>" required></div><div class="col-md-6"><label class="form-label">Διάρκεια κάλυψης</label><select class="form-select" name="coverage_years" id="subscriptionCoverageYears"><option value="1">1 έτος</option><option value="2">2 έτη</option><option value="3">3 έτη</option><option value="4">4 έτη</option><option value="5">5 έτη</option></select></div><div class="col-12"><label class="form-label">Ημερομηνία λήξης</label><input type="text" class="form-control subscription-datepicker" name="expiry_date" id="subscriptionExpiryDate" required><div class="form-text" id="subscriptionExpiryHint">Επιλέξτε εθελοντή για τον αυτόματο υπολογισμό.</div><div class="alert alert-warning mt-2 mb-0 d-none" id="subscriptionExpiryWarning"><i class="bi bi-exclamation-triangle me-1"></i>Η ημερομηνία λήξης άλλαξε από την προτεινόμενη. Ελέγξτε την πριν την αποθήκευση.</div><input type="hidden" name="expiry_override_confirmed" id="subscriptionExpiryOverrideConfirmed" value="0"></div></div><div class="row mt-1"><div class="col"><label class="form-label">Ποσό (€)</label><input type="number" step="0.01" min="0" class="form-control" name="amount"></div><div class="col"><label class="form-label">Τρόπος</label><select class="form-select" name="payment_method"><option value="">—</option><option>Μετρητά</option><option>Τραπεζική κατάθεση</option><option>Κάρτα</option><option>Άλλο</option></select></div></div><div class="mt-3"><label class="form-label">Αριθμός απόδειξης</label><input type="text" class="form-control" name="receipt_number" maxlength="100"></div><div class="mt-3"><label class="form-label">Απόδειξη</label><input type="file" class="form-control" name="receipt" accept=".pdf,.jpg,.jpeg,.png"><div class="form-text">PDF, JPG ή PNG έως 10MB.</div></div><div class="mt-3"><label class="form-label">Σημειώσεις</label><textarea class="form-control" name="notes" rows="2"></textarea></div></div>
<div class="px-3 pb-3"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" value="1" name="force_reactivation" id="forceReactivation"><label class="form-check-label" for="forceReactivation">Επανενεργοποίηση συνδρομής</label><div class="form-text">Ξεκινά νέα περίοδο από την ημερομηνία πληρωμής, ανεξάρτητα από την προηγούμενη λήξη.</div></div></div>
<div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Ακύρωση</button><button class="btn btn-primary">Αποθήκευση</button></div></form></div></div></div>
<script>
(() => {
    const receiptPreviewModal = document.getElementById('receiptPreviewModal');
    if (receiptPreviewModal) {
        const previewImage = document.getElementById('receiptPreviewImage');
        const previewTitle = document.getElementById('receiptPreviewModalTitle');
        const previewError = document.getElementById('receiptPreviewError');
        receiptPreviewModal.addEventListener('show.bs.modal', (event) => {
            const button = event.relatedTarget;
            previewTitle.textContent = button.dataset.previewName || 'Προεπισκόπηση απόδειξης';
            previewError.classList.add('d-none');
            previewImage.src = button.dataset.previewUrl;
        });
        previewImage.addEventListener('error', () => previewError.classList.remove('d-none'));
        receiptPreviewModal.addEventListener('hidden.bs.modal', () => { previewImage.removeAttribute('src'); });
    }

    const latestExpiryByVolunteer = <?= json_encode($latestSubscriptionExpiryMap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const reactivationDays = <?= (int)$subscriptionReactivationDays ?>;
    const volunteer = document.getElementById('subscriptionVolunteer');
    const payment = document.getElementById('subscriptionPaymentDate');
    const years = document.getElementById('subscriptionCoverageYears');
    const reactivation = document.getElementById('forceReactivation');
    const expiry = document.getElementById('subscriptionExpiryDate');
    const hint = document.getElementById('subscriptionExpiryHint');
    const warning = document.getElementById('subscriptionExpiryWarning');
    const confirmed = document.getElementById('subscriptionExpiryOverrideConfirmed');
    const form = expiry.closest('form');
    const setDateValue = (field, value) => {
        if (field._flatpickr) {
            field._flatpickr.setDate(value, false, 'Y-m-d');
        } else {
            field.value = value;
        }
    };

    const toDate = (value) => value && /^\d{4}-\d{2}-\d{2}$/.test(value) ? new Date(value + 'T12:00:00') : null;
    const isoDate = (date) => date.toISOString().slice(0, 10);
    const addYears = (value, count) => {
        const date = toDate(value);
        if (!date) return '';
        date.setFullYear(date.getFullYear() + Number(count));
        return isoDate(date);
    };
    const formatDate = (value) => {
        const date = toDate(value);
        return date ? date.toLocaleDateString('el-GR') : value;
    };
    const refreshExpiry = () => {
        const paidOn = payment.value;
        if (!toDate(paidOn)) return;
        const previousExpiry = latestExpiryByVolunteer[volunteer.value] || '';
        const expiredDays = previousExpiry ? Math.floor((toDate(paidOn) - toDate(previousExpiry)) / 86400000) : 0;
        const isReactivation = reactivation.checked || (previousExpiry && expiredDays > reactivationDays);
        const baseDate = isReactivation || !previousExpiry ? paidOn : previousExpiry;
        const expectedExpiry = addYears(baseDate, years.value);
        setDateValue(expiry, expectedExpiry);
        expiry.dataset.expectedExpiry = expectedExpiry;
        confirmed.value = '0';
        warning.classList.add('d-none');
        hint.textContent = isReactivation || !previousExpiry
            ? `Υπολογίστηκε από την πληρωμή: ${formatDate(baseDate)} + ${years.value} έτη.`
            : `Υπολογίστηκε από την προηγούμενη λήξη: ${formatDate(baseDate)} + ${years.value} έτη.`;
    };
    const checkManualExpiry = () => {
        const changed = expiry.value && expiry.dataset.expectedExpiry && expiry.value !== expiry.dataset.expectedExpiry;
        warning.classList.toggle('d-none', !changed);
        confirmed.value = '0';
    };
    [volunteer, payment, years, reactivation].forEach((field) => field.addEventListener('change', refreshExpiry));
    expiry.addEventListener('input', checkManualExpiry);
    form.addEventListener('submit', (event) => {
        if (expiry.value !== expiry.dataset.expectedExpiry && confirmed.value !== '1') {
            const proceed = window.confirm(`Η λήξη άλλαξε από ${formatDate(expiry.dataset.expectedExpiry)} σε ${formatDate(expiry.value)}. Θέλετε να αποθηκευτεί η χειροκίνητη ημερομηνία;`);
            if (!proceed) {
                event.preventDefault();
                return;
            }
            confirmed.value = '1';
        }
    });
    const addCameraInput = (regularInput, formId, target, cameraId) => {
        if (!target || document.getElementById(cameraId)) return;
        const cameraInput = document.createElement('input');
        cameraInput.type = 'file';
        cameraInput.name = 'receipt_camera';
        cameraInput.id = cameraId;
        cameraInput.accept = 'image/jpeg,image/png';
        cameraInput.setAttribute('capture', 'environment');
        cameraInput.className = 'd-none';
        if (formId) cameraInput.setAttribute('form', formId);

        const cameraButton = document.createElement('label');
        cameraButton.className = 'btn btn-outline-primary btn-sm mt-2';
        cameraButton.htmlFor = cameraId;
        cameraButton.innerHTML = '<i class="bi bi-camera-fill me-1"></i>Λήψη με κάμερα';
        const hint = document.createElement('div');
        hint.className = 'form-text';
        hint.textContent = 'Σε κινητό ανοίγει την πίσω κάμερα για φωτογράφιση της απόδειξης.';
        target.append(cameraInput, cameraButton, hint);

        cameraInput.addEventListener('change', () => {
            if (cameraInput.files.length) regularInput.value = '';
        });
        regularInput.addEventListener('change', () => {
            if (regularInput.files.length) cameraInput.value = '';
        });
    };
    const newReceiptInput = document.querySelector('#paymentModal input[name="receipt"]');
    if (newReceiptInput) addCameraInput(newReceiptInput, null, newReceiptInput.parentElement, 'subscriptionCameraNew');

    const editReceiptInput = document.querySelector('#editSubscriptionForm input[name="receipt"]');
    if (editReceiptInput) addCameraInput(editReceiptInput, null, document.getElementById('editReceiptUpload'), 'subscriptionCameraEdit');
    refreshExpiry();
    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('.subscription-datepicker').forEach((field) => {
            flatpickr(field, {
                dateFormat: 'Y-m-d',
                altInput: true,
                altFormat: <?= json_encode($subscriptionDateFormat) ?>,
                allowInput: true,
                disableMobile: true,
                onValueUpdate: () => {
                    field.dispatchEvent(new Event('input', {bubbles: true}));
                    field.dispatchEvent(new Event('change', {bubbles: true}));
                }
            });
        });
    });
})();
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
