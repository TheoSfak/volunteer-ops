<?php
/** VolunteerOps — Οικονομικά: Υποχρεώσεις, the unpaid invoices (v3.510.0). */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/finance-functions.php';
requirePermission('finance_manage');

$pageTitle = 'Υποχρεώσεις';
$rows = financeOpenPayables(date('Y-m-d'));
$total = array_sum(array_column($rows, 'remaining'));
$overdue = array_filter($rows, fn($r) => $r['urgency'] === 'overdue');
$rowClass = ['overdue' => 'table-danger', 'soon' => 'table-warning', 'later' => '', 'none' => ''];
$label = ['overdue' => 'Έληξε', 'soon' => 'Λήγει σύντομα', 'later' => '', 'none' => 'Χωρίς προθεσμία'];

include __DIR__ . '/includes/header.php';
?>
<h1 class="h3 mb-3"><i class="bi bi-hourglass-split me-2"></i>Υποχρεώσεις</h1>
<?php displayFlash(); ?>
<div class="row g-3 mb-3">
    <div class="col-md-4"><div class="card card-body"><div class="text-muted small">Οφειλές</div><div class="fs-4 fw-semibold"><?= financeFormat($total) ?></div></div></div>
    <div class="col-md-4"><div class="card card-body"><div class="text-muted small">Ανοιχτά παραστατικά</div><div class="fs-4 fw-semibold"><?= count($rows) ?></div></div></div>
    <div class="col-md-4"><div class="card card-body"><div class="text-muted small">Ληγμένα</div><div class="fs-4 fw-semibold text-danger"><?= count($overdue) ?></div></div></div>
</div>
<div class="card"><div class="table-responsive"><table class="table align-middle mb-0">
    <thead><tr><th>Προθεσμία</th><th>Εκδότης</th><th>Αριθμός</th><th>Κατηγορία</th><th class="text-end">Σύνολο</th><th class="text-end">Υπόλοιπο</th><th></th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-4">Δεν υπάρχουν απλήρωτα παραστατικά.</td></tr><?php endif; ?>
    <?php foreach ($rows as $r): ?>
        <tr class="<?= $rowClass[$r['urgency']] ?>">
            <td><?= $r['due_date'] ? h(formatDate($r['due_date'])) : '—' ?><?php if ($label[$r['urgency']]): ?><div class="small"><?= $label[$r['urgency']] ?></div><?php endif; ?></td>
            <td><?= h($r['counterparty_name']) ?></td>
            <td><?= h($r['doc_number'] ?? '') ?></td>
            <td><?= h($r['category_name'] ?? '—') ?></td>
            <td class="text-end"><?= financeFormat(financeToCents($r['gross_total'])) ?></td>
            <td class="text-end fw-semibold"><?= financeFormat($r['remaining']) ?></td>
            <td><a class="btn btn-sm btn-success" href="finance-document-form.php?id=<?= (int) $r['id'] ?>#pay">Πληρωμή</a></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table></div></div>
<?php include __DIR__ . '/includes/footer.php'; ?>
