<?php
/** VolunteerOps — Οικονομικά: Κινήσεις ταμείου (v3.510.0). */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/finance-functions.php';
requirePermission('finance_manage');

$uid = (int) getCurrentUserId();
if (isPost()) {
    verifyCsrf();
    try {
        switch (post('action')) {
            case 'add_movement':
                $mid = financeAddMovement([
                    'account_id' => (int) post('account_id'), 'direction' => post('direction'), 'amount' => financeToCents(post('amount')),
                    'movement_date' => post('movement_date'), 'category_id' => post('category_id'), 'description' => post('description'),
                ], $uid);
                logAudit('finance_create_movement', 'finance_movements', $mid);
                setFlash('success', 'Η κίνηση καταχωρήθηκε.');
                break;
            case 'transfer':
                $group = financeTransfer((int) post('from_account_id'), (int) post('to_account_id'), financeToCents(post('amount')), post('movement_date'), post('description'), $uid);
                logAudit('finance_transfer', 'finance_movements', null, null, ['transfer_group' => $group]);
                setFlash('success', 'Η μεταφορά καταχωρήθηκε.');
                break;
            case 'delete':
                $rows = financeDeleteMovement((int) post('movement_id'));
                logAudit('finance_delete_movement', 'finance_movements', (int) post('movement_id'), $rows);
                setFlash('success', count($rows) > 1 ? 'Η μεταφορά διαγράφηκε (και οι δύο κινήσεις).' : 'Η κίνηση διαγράφηκε.');
                break;
        }
    } catch (PDOException $e) { // first: PDOException extends RuntimeException
        error_log('finance-movements: ' . $e->getMessage());
        setFlash('error', 'Παρουσιάστηκε σφάλμα βάσης δεδομένων. Η ενέργεια δεν ολοκληρώθηκε.');
    } catch (InvalidArgumentException $e) {
        setFlash('error', $e->getMessage());
    }
    redirect('finance-movements.php?' . http_build_query(array_filter(['from' => get('from'), 'to' => get('to'), 'account_id' => get('account_id'), 'direction' => get('direction')])));
}

$pageTitle = 'Κινήσεις';
$from = financeValidDate(get('from')) ? get('from') : date('Y-m-01');
$to = financeValidDate(get('to')) ? get('to') : date('Y-m-t');
$accountId = (int) get('account_id');
$dir = in_array(get('direction'), FINANCE_DIRECTIONS, true) ? get('direction') : '';
$where = ['m.movement_date BETWEEN ? AND ?'];
$params = [$from, $to];
if ($accountId > 0) { $where[] = 'm.account_id = ?'; $params[] = $accountId; }
if ($dir !== '') { $where[] = 'm.direction = ?'; $params[] = $dir; }
$rows = dbFetchAll("SELECT m.*, a.name AS account_name, d.counterparty_name, d.doc_number,
        COALESCE(c1.name, c2.name) AS category_name, vs.user_id AS dues_user_id
    FROM finance_movements m
    JOIN finance_accounts a ON a.id = m.account_id
    LEFT JOIN finance_documents d ON d.id = m.document_id
    LEFT JOIN finance_categories c1 ON c1.id = d.category_id
    LEFT JOIN finance_categories c2 ON c2.id = m.category_id
    LEFT JOIN volunteer_subscriptions vs ON vs.id = m.subscription_id
    WHERE " . implode(' AND ', $where) . " ORDER BY m.movement_date DESC, m.id DESC LIMIT 500", $params);
$accounts = financeAccounts();
$allAccounts = financeAccounts(false);
$catsIn = financeCategories('IN');
$catsOut = financeCategories('OUT');
$tot = ['IN' => 0, 'OUT' => 0];
// Totals come from their own aggregate, so the 500-row display limit never skews them.
foreach (dbFetchAll("SELECT m.direction, ROUND(SUM(m.amount) * 100) AS cents FROM finance_movements m WHERE " . implode(' AND ', $where) . " AND m.transfer_group IS NULL GROUP BY m.direction", $params) as $t) {
    $tot[$t['direction']] = (int) $t['cents'];
}
$truncated = count($rows) >= 500;

include __DIR__ . '/includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h3 mb-0"><i class="bi bi-arrow-left-right me-2"></i>Κινήσεις</h1>
    <div class="d-flex gap-2">
        <button class="btn btn-outline-primary" data-bs-toggle="collapse" data-bs-target="#newMovement"><i class="bi bi-plus-lg me-1"></i>Κίνηση χωρίς παραστατικό</button>
        <button class="btn btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#newTransfer"><i class="bi bi-arrow-left-right me-1"></i>Μεταφορά</button>
    </div>
</div>
<?php displayFlash(); ?>

<div class="collapse mb-3" id="newMovement"><form method="post" class="card card-body">
    <?= csrfField() ?><input type="hidden" name="action" value="add_movement">
    <p class="small text-muted">Για ποσά χωρίς χαρτί, π.χ. τραπεζικά έξοδα ή κατάθεση μετρητών από κουτί δωρεών. Για ό,τι έχει απόδειξη, χρησιμοποιήστε τα Παραστατικά.</p>
    <div class="row g-2">
        <div class="col-md-2"><select class="form-select" name="direction" id="mvDir">
            <option value="OUT">Έξοδο</option><option value="IN">Έσοδο</option></select></div>
        <div class="col-md-2"><select class="form-select" name="account_id"><?php foreach ($accounts as $a): ?><option value="<?= (int) $a['id'] ?>"><?= h($a['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><input class="form-control text-end" name="amount" inputmode="decimal" placeholder="Ποσό" required></div>
        <div class="col-md-2"><input type="date" class="form-control" name="movement_date" value="<?= date('Y-m-d') ?>"></div>
        <div class="col-md-2">
            <select class="form-select mv-cat" data-dir="OUT" name="category_id"><option value="">Κατηγορία</option><?php foreach ($catsOut as $c): ?><option value="<?= (int) $c['id'] ?>"><?= h($c['name']) ?></option><?php endforeach; ?></select>
            <select class="form-select mv-cat" data-dir="IN" name="category_id" hidden disabled><option value="">Κατηγορία</option><?php foreach ($catsIn as $c): ?><option value="<?= (int) $c['id'] ?>"><?= h($c['name']) ?></option><?php endforeach; ?></select>
        </div>
        <div class="col-md-2"><input class="form-control" name="description" maxlength="255" placeholder="Περιγραφή"></div>
    </div>
    <div class="mt-2"><button class="btn btn-primary btn-sm">Καταχώρηση</button></div>
    <script>
    // Both category selects are named category_id; the hidden one is also disabled, so only the visible one is submitted.
    document.getElementById('mvDir').addEventListener('change', function () {
        document.querySelectorAll('.mv-cat').forEach(s => { const off = s.dataset.dir !== this.value; s.disabled = off; s.hidden = off; });
    });
    </script>
</form></div>

<div class="collapse mb-3" id="newTransfer"><form method="post" class="card card-body">
    <?= csrfField() ?><input type="hidden" name="action" value="transfer">
    <div class="row g-2">
        <div class="col-md-3"><label class="form-label small">Από</label><select class="form-select" name="from_account_id"><?php foreach ($accounts as $a): ?><option value="<?= (int) $a['id'] ?>"><?= h($a['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><label class="form-label small">Προς</label><select class="form-select" name="to_account_id"><?php foreach ($accounts as $i => $a): ?><option value="<?= (int) $a['id'] ?>" <?= $i === 1 ? 'selected' : '' ?>><?= h($a['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><label class="form-label small">Ποσό</label><input class="form-control text-end" name="amount" inputmode="decimal" required></div>
        <div class="col-md-2"><label class="form-label small">Ημερομηνία</label><input type="date" class="form-control" name="movement_date" value="<?= date('Y-m-d') ?>"></div>
        <div class="col-md-2"><label class="form-label small">Περιγραφή</label><input class="form-control" name="description" placeholder="Κατάθεση"></div>
    </div>
    <div class="mt-2"><button class="btn btn-primary btn-sm">Μεταφορά</button></div>
</form></div>

<form class="card card-body mb-3" method="get"><div class="row g-2">
    <div class="col-6 col-md-3"><input type="date" class="form-control" name="from" value="<?= h($from) ?>"></div>
    <div class="col-6 col-md-3"><input type="date" class="form-control" name="to" value="<?= h($to) ?>"></div>
    <div class="col-6 col-md-3"><select class="form-select" name="account_id"><option value="0">Όλοι οι λογαριασμοί</option><?php foreach ($allAccounts as $a): ?><option value="<?= (int) $a['id'] ?>" <?= $accountId === (int) $a['id'] ? 'selected' : '' ?>><?= h($a['name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-6 col-md-2"><select class="form-select" name="direction"><option value="">Έσοδα & έξοδα</option><option value="IN" <?= $dir === 'IN' ? 'selected' : '' ?>>Έσοδα</option><option value="OUT" <?= $dir === 'OUT' ? 'selected' : '' ?>>Έξοδα</option></select></div>
    <div class="col-12 col-md-1"><button class="btn btn-primary w-100">OK</button></div>
</div></form>

<div class="d-flex gap-3 mb-2 small"><span class="text-success">Έσοδα <?= financeFormat($tot['IN']) ?></span><span class="text-danger">Έξοδα <?= financeFormat($tot['OUT']) ?></span><span>Διαφορά <?= financeFormat($tot['IN'] - $tot['OUT']) ?></span><span class="text-muted">(χωρίς μεταφορές)</span></div>
<?php if ($truncated): ?><div class="alert alert-warning py-2 small">Εμφανίζονται οι 500 πιο πρόσφατες κινήσεις — περιορίστε τις ημερομηνίες.</div><?php endif; ?>
<div class="card"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
    <thead><tr><th>Ημ/νία</th><th>Λογαριασμός</th><th>Περιγραφή</th><th>Κατηγορία</th><th class="text-end">Ποσό</th><th></th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="6" class="text-center text-muted py-4">Καμία κίνηση στο διάστημα.</td></tr><?php endif; ?>
    <?php foreach ($rows as $r): $amt = financeToCents($r['amount']); ?>
        <tr>
            <td><?= h(formatDate($r['movement_date'])) ?></td>
            <td><?= h($r['account_name']) ?></td>
            <td>
                <?= h($r['description'] ?? '') ?>
                <?php if ($r['transfer_group']): ?><span class="badge bg-secondary ms-1">Μεταφορά</span><?php endif; ?>
                <?php if ($r['document_id']): ?><a class="ms-1 small" href="finance-document-form.php?id=<?= (int) $r['document_id'] ?>"><i class="bi bi-receipt"></i> <?= h(trim(($r['doc_number'] ?? '') . ' ' . ($r['counterparty_name'] ?? ''))) ?></a>
                <?php elseif (!$r['transfer_group'] && !$r['subscription_id']): ?><span class="badge bg-light text-muted border ms-1">χωρίς παραστατικό</span><?php endif; ?>
            </td>
            <td><?= h($r['category_name'] ?? '—') ?></td>
            <td class="text-end fw-semibold <?= $r['direction'] === 'IN' ? 'text-success' : 'text-danger' ?>"><?= ($r['direction'] === 'IN' ? '+' : '−') . financeFormat($amt) ?></td>
            <td class="text-nowrap">
                <?php if ($r['subscription_id']): ?>
                    <a class="btn btn-sm btn-outline-secondary" href="subscriptions.php?show_history=1&history_user_id=<?= (int) $r['dues_user_id'] ?>#subscriptionPaymentHistory" title="Αλλάζει μόνο από τις Ετήσιες Συνδρομές"><i class="bi bi-cash-coin"></i></a>
                <?php else: ?>
                    <form method="post" class="d-inline" onsubmit="return confirm('<?= $r['transfer_group'] ? 'Διαγραφή της μεταφοράς (και των δύο κινήσεων);' : 'Διαγραφή της κίνησης;' ?>');">
                        <?= csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="movement_id" value="<?= (int) $r['id'] ?>">
                        <button class="btn btn-sm btn-outline-danger" title="Διαγραφή"><i class="bi bi-trash"></i></button>
                    </form>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div></div>
<?php include __DIR__ . '/includes/footer.php'; ?>
