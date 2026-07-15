<?php
/** Annual volunteer subscription management. */
require_once __DIR__ . '/bootstrap.php';
requirePermission('subscriptions_manage');

$pageTitle = 'Ετήσιες Συνδρομές';

if (isPost()) {
    verifyCsrf();
    if (post('action') === 'record_payment') {
        $userId = (int) post('user_id');
        $paymentDate = post('payment_date');
        $amount = str_replace(',', '.', trim(post('amount')));
        $method = trim(post('payment_method'));
        $notes = trim(post('notes'));
        $volunteer = dbFetchOne("SELECT id, name FROM users WHERE id = ? AND is_active = 1", [$userId]);

        if (!$volunteer || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $paymentDate)) {
            setFlash('error', 'Επιλέξτε έγκυρο εθελοντή και ημερομηνία πληρωμής.');
        } else {
            $latest = dbFetchOne("SELECT expiry_date FROM volunteer_subscriptions WHERE user_id = ? ORDER BY expiry_date DESC, id DESC LIMIT 1", [$userId]);
            $reactivationLimit = max(0, (int)getSetting('subscription_reactivation_days', 90));
            $daysSinceExpiry = $latest ? (int)floor((strtotime($paymentDate) - strtotime($latest['expiry_date'])) / 86400) : 0;
            $isReactivation = post('force_reactivation') === '1' || ($latest && $daysSinceExpiry > $reactivationLimit);
            $baseDate = $isReactivation || !$latest ? $paymentDate : $latest['expiry_date'];
            $expiryDate = (new DateTime($baseDate))->modify('+1 year')->format('Y-m-d');
            $receiptOriginal = null;
            $receiptStored = null;

            if (!empty($_FILES['receipt']['name'])) {
                $file = $_FILES['receipt'];
                $allowed = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png'];
                $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
                if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > 10 * 1024 * 1024 || !isset($allowed[$mime])) {
                    setFlash('error', 'Η απόδειξη πρέπει να είναι PDF, JPG ή PNG έως 10MB.');
                    redirect('subscriptions.php');
                }
                $dir = __DIR__ . '/uploads/subscription-receipts/';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $receiptOriginal = basename($file['name']);
                $receiptStored = 'subscription_' . $userId . '_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $allowed[$mime];
                if (!move_uploaded_file($file['tmp_name'], $dir . $receiptStored)) {
                    setFlash('error', 'Δεν ήταν δυνατή η αποθήκευση της απόδειξης.');
                    redirect('subscriptions.php');
                }
            }

            dbInsert("INSERT INTO volunteer_subscriptions
                (user_id, payment_date, expiry_date, renewal_kind, amount, payment_method, receipt_original_name, receipt_stored_name, notes, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", [
                $userId, $paymentDate, $expiryDate, $isReactivation ? 'REACTIVATION' : 'RENEWAL', $amount === '' ? null : $amount,
                $method ?: null, $receiptOriginal, $receiptStored, $notes ?: null, getCurrentUserId()
            ]);
            logAudit('record_subscription_payment', 'volunteer_subscriptions', $userId, null, ['expiry_date' => $expiryDate]);
            setFlash('success', ($isReactivation ? 'Η συνδρομή επανενεργοποιήθηκε. ' : 'Η πληρωμή καταχωρήθηκε. ') . 'Η συνδρομή λήγει στις ' . formatDate($expiryDate) . '.');
        }
        redirect('subscriptions.php');
    }
}

$volunteers = dbFetchAll("SELECT id, name, email FROM users WHERE is_active = 1 ORDER BY name");
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

include __DIR__ . '/includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0"><i class="bi bi-cash-coin me-2"></i>Ετήσιες Συνδρομές</h1>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#paymentModal"><i class="bi bi-plus-lg me-1"></i>Καταχώρηση πληρωμής</button>
</div>
<div class="d-flex flex-wrap gap-2 mb-3">
    <?php foreach (['all' => ['Όλες', null, 'secondary'], 'week' => ['Λήγουν σε 1 εβδομάδα', $counts['week'], 'danger'], 'month' => ['Λήγουν σε 1 μήνα', $counts['month'], 'warning'], 'quarter' => ['Λήγουν σε 3 μήνες', $counts['quarter'], 'info'], 'expired' => ['Ληγμένες', $counts['expired'], 'dark']] as $key => [$label, $count, $color]): ?>
        <a href="subscriptions.php?filter=<?= $key ?>" class="btn btn-sm <?= $filter === $key ? 'btn-' . $color : 'btn-outline-' . $color ?>"><?= $label ?><?php if ($count !== null): ?> <span class="badge text-bg-light ms-1"><?= $count ?></span><?php endif; ?></a>
    <?php endforeach; ?>
</div>
<div class="card shadow-sm"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
<thead><tr><th>Εθελοντής</th><th>Πληρωμή</th><th>Λήξη</th><th>Ποσό</th><th>Τρόπος</th><th>Απόδειξη</th><th>Κατάσταση</th></tr></thead><tbody>
<?php foreach ($rows as $row): $days = (int)floor((strtotime($row['expiry_date']) - strtotime(date('Y-m-d'))) / 86400); $badge = $days < 0 ? 'danger' : ($days <= 7 ? 'danger' : ($days <= 30 ? 'warning text-dark' : ($days <= 90 ? 'info text-dark' : 'success'))); $label = $days < 0 ? 'Ληγμένη' : ($days === 0 ? 'Λήγει σήμερα' : 'Ενεργή (' . $days . ' ημ.)'); ?>
<tr><td><a href="volunteer-view.php?id=<?= $row['user_id'] ?>"><?= h($row['volunteer_name']) ?></a></td><td><?= formatDate($row['payment_date']) ?></td><td><?= formatDate($row['expiry_date']) ?></td><td><?= $row['amount'] !== null ? number_format((float)$row['amount'], 2, ',', '.') . ' €' : '—' ?></td><td><?= h($row['payment_method'] ?: '—') ?></td><td><?php if ($row['receipt_stored_name']): ?><a class="btn btn-sm btn-outline-secondary" href="subscription-receipt.php?id=<?= $row['id'] ?>"><i class="bi bi-file-earmark-text"></i> <?= h($row['receipt_original_name']) ?></a><?php else: ?>—<?php endif; ?></td><td><span class="badge bg-<?= $badge ?>"><?= $label ?></span></td></tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-4">Δεν υπάρχουν καταχωρημένες συνδρομές.</td></tr><?php endif; ?>
</tbody></table></div></div>

<div class="modal fade" id="paymentModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><form method="post" enctype="multipart/form-data">
<?= csrfField() ?><input type="hidden" name="action" value="record_payment"><div class="modal-header"><h5 class="modal-title">Καταχώρηση ετήσιας συνδρομής</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
<div class="modal-body"><div class="mb-3"><label class="form-label">Εθελοντής</label><select class="form-select" name="user_id" required><option value="">Επιλέξτε…</option><?php foreach ($volunteers as $v): ?><option value="<?= $v['id'] ?>"><?= h($v['name']) ?> — <?= h($v['email']) ?></option><?php endforeach; ?></select></div><div class="mb-3"><label class="form-label">Ημερομηνία πληρωμής</label><input type="date" class="form-control" name="payment_date" value="<?= date('Y-m-d') ?>" required><div class="form-text">Η λήξη υπολογίζεται ένα έτος μετά από την προηγούμενη λήξη· στην πρώτη πληρωμή, ένα έτος μετά την πληρωμή.</div></div><div class="row"><div class="col"><label class="form-label">Ποσό (€)</label><input type="number" step="0.01" min="0" class="form-control" name="amount"></div><div class="col"><label class="form-label">Τρόπος</label><select class="form-select" name="payment_method"><option value="">—</option><option>Μετρητά</option><option>Τραπεζική κατάθεση</option><option>Κάρτα</option><option>Άλλο</option></select></div></div><div class="mt-3"><label class="form-label">Απόδειξη</label><input type="file" class="form-control" name="receipt" accept=".pdf,.jpg,.jpeg,.png"><div class="form-text">PDF, JPG ή PNG έως 10MB.</div></div><div class="mt-3"><label class="form-label">Σημειώσεις</label><textarea class="form-control" name="notes" rows="2"></textarea></div></div>
<div class="px-3 pb-3"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" value="1" name="force_reactivation" id="forceReactivation"><label class="form-check-label" for="forceReactivation">Επανενεργοποίηση συνδρομής</label><div class="form-text">Ξεκινά νέα ετήσια περίοδο από την ημερομηνία πληρωμής, ανεξάρτητα από την προηγούμενη λήξη.</div></div></div>
<div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Ακύρωση</button><button class="btn btn-primary">Αποθήκευση</button></div></form></div></div></div>
<?php include __DIR__ . '/includes/footer.php'; ?>
