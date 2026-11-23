<?php
/** VolunteerOps — Οικονομικά: Παραστατικά, the list (v3.510.0). */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/finance-functions.php';
requirePermission('finance_manage');

$pageTitle = 'Παραστατικά';
$direction = in_array(get('direction'), FINANCE_DIRECTIONS, true) ? get('direction') : '';
$status = in_array(get('status'), ['open', 'paid'], true) ? get('status') : '';
$from = financeValidDate(get('from')) ? get('from') : '';
$to = financeValidDate(get('to')) ? get('to') : '';
$categoryId = (int) get('category_id');
$q = trim(get('q'));

$where = ['1=1'];
$params = [];
if ($direction !== '') { $where[] = 'd.direction = ?'; $params[] = $direction; }
if ($from !== '') { $where[] = 'd.doc_date >= ?'; $params[] = $from; }
if ($to !== '') { $where[] = 'd.doc_date <= ?'; $params[] = $to; }
if ($categoryId > 0) { $where[] = 'd.category_id = ?'; $params[] = $categoryId; }
if ($q !== '') {
    $where[] = '(d.counterparty_name LIKE ? OR d.doc_number LIKE ? OR d.counterparty_vat LIKE ?)';
    array_push($params, "%$q%", "%$q%", "%$q%");
}
if ($status === 'open') $where[] = 'COALESCE(p.paid, 0) < d.gross_total';
if ($status === 'paid') $where[] = 'COALESCE(p.paid, 0) >= d.gross_total';

$rows = dbFetchAll("SELECT d.*, c.name AS category_name, COALESCE(p.paid, 0) AS paid_sum
    FROM finance_documents d LEFT JOIN finance_categories c ON c.id = d.category_id " . FINANCE_PAID_JOIN . "
    WHERE " . implode(' AND ', $where) . " ORDER BY d.doc_date DESC, d.id DESC LIMIT 200", $params);
$categories = financeCategories(null, false);
$sum = ['net' => 0, 'vat' => 0, 'gross' => 0];
foreach ($rows as $r) {
    $sum['net'] += financeToCents($r['net_total']);
    $sum['vat'] += financeToCents($r['vat_total']);
    $sum['gross'] += financeToCents($r['gross_total']);
}

include __DIR__ . '/includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h3 mb-0"><i class="bi bi-receipt me-2"></i>Παραστατικά</h1>
    <div class="d-flex gap-2">
        <a class="btn btn-danger" href="finance-document-form.php?direction=OUT"><i class="bi bi-dash-circle me-1"></i>Νέο έξοδο</a>
        <a class="btn btn-success" href="finance-document-form.php?direction=IN"><i class="bi bi-plus-circle me-1"></i>Νέο έσοδο</a>
    </div>
</div>
<?php displayFlash(); ?>
<form class="card card-body mb-3" method="get">
    <div class="row g-2">
        <div class="col-6 col-md-2"><select class="form-select" name="direction"><option value="">Όλα</option>
            <option value="OUT" <?= $direction === 'OUT' ? 'selected' : '' ?>>Έξοδα</option><option value="IN" <?= $direction === 'IN' ? 'selected' : '' ?>>Έσοδα</option></select></div>
        <div class="col-6 col-md-2"><select class="form-select" name="status"><option value="">Κάθε κατάσταση</option>
            <option value="open" <?= $status === 'open' ? 'selected' : '' ?>>Ανοιχτά</option><option value="paid" <?= $status === 'paid' ? 'selected' : '' ?>>Εξοφλημένα</option></select></div>
        <div class="col-6 col-md-2"><input type="date" class="form-control" name="from" value="<?= h($from) ?>" title="Από"></div>
        <div class="col-6 col-md-2"><input type="date" class="form-control" name="to" value="<?= h($to) ?>" title="Έως"></div>
        <div class="col-6 col-md-2"><select class="form-select" name="category_id"><option value="0">Κάθε κατηγορία</option>
            <?php foreach ($categories as $c): ?><option value="<?= (int) $c['id'] ?>" <?= $categoryId === (int) $c['id'] ? 'selected' : '' ?>><?= h($c['name']) ?> (<?= $c['direction'] === 'IN' ? 'έσοδο' : 'έξοδο' ?>)</option><?php endforeach; ?></select></div>
        <div class="col-6 col-md-2"><input type="search" class="form-control" name="q" value="<?= h($q) ?>" placeholder="Επωνυμία, αριθμός, ΑΦΜ"></div>
    </div>
    <div class="mt-2"><button class="btn btn-primary btn-sm">Φίλτρο</button> <a class="btn btn-outline-secondary btn-sm" href="finance-documents.php">Καθαρισμός</a></div>
</form>
<div class="card"><div class="table-responsive">
<table class="table table-hover align-middle mb-0">
    <thead><tr><th>Ημ/νία</th><th>Είδος</th><th>Αντισυμβαλλόμενος</th><th>Αριθμός</th><th>Κατηγορία</th>
        <th class="text-end">Καθαρό</th><th class="text-end">ΦΠΑ</th><th class="text-end">Σύνολο</th><th>Κατάσταση</th><th></th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="10" class="text-center text-muted py-4">Δεν βρέθηκαν παραστατικά.</td></tr><?php endif; ?>
    <?php foreach ($rows as $r): $gross = financeToCents($r['gross_total']); $paid = financeToCents($r['paid_sum']); ?>
        <tr>
            <td><?= h(formatDate($r['doc_date'])) ?></td>
            <td><?= $r['direction'] === 'IN' ? '<span class="badge bg-success-subtle text-success-emphasis">Έσοδο</span>' : '<span class="badge bg-danger-subtle text-danger-emphasis">Έξοδο</span>' ?></td>
            <td><?= h($r['counterparty_name']) ?><?php if ($r['counterparty_vat']): ?><div class="small text-muted">ΑΦΜ <?= h($r['counterparty_vat']) ?></div><?php endif; ?></td>
            <td><?= h($r['doc_number'] ?? '') ?></td>
            <td><?= h($r['category_name'] ?? '—') ?></td>
            <td class="text-end"><?= financeFormat(financeToCents($r['net_total'])) ?></td>
            <td class="text-end"><?= financeFormat(financeToCents($r['vat_total'])) ?></td>
            <td class="text-end fw-semibold"><?= financeFormat($gross) ?></td>
            <td><?= financeStatusBadge(financePaidStatus($gross, $paid), $r['direction']) ?></td>
            <td class="text-nowrap">
                <?php if ($r['file_stored_name']): ?><a class="btn btn-sm btn-outline-secondary" href="finance-file.php?id=<?= (int) $r['id'] ?>" target="_blank" title="Αρχείο"><i class="bi bi-paperclip"></i></a><?php endif; ?>
                <a class="btn btn-sm btn-outline-primary" href="finance-document-form.php?id=<?= (int) $r['id'] ?>" title="Επεξεργασία"><i class="bi bi-pencil"></i></a>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
    <?php if ($rows): ?><tfoot><tr class="fw-semibold"><td colspan="5" class="text-end">Σύνολα (<?= count($rows) ?>)</td>
        <td class="text-end"><?= financeFormat($sum['net']) ?></td><td class="text-end"><?= financeFormat($sum['vat']) ?></td><td class="text-end"><?= financeFormat($sum['gross']) ?></td><td colspan="2"></td></tr></tfoot><?php endif; ?>
</table>
</div></div>
<?php if (count($rows) === 200): ?><p class="small text-muted mt-2">Εμφανίζονται τα 200 πιο πρόσφατα. Περιορίστε με φίλτρα.</p><?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
