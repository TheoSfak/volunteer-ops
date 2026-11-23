<?php
/** VolunteerOps — Οικονομικά: Αναφορές for the accountant (v3.510.0). */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/finance-functions.php';
requirePermission('finance_manage');

// Every date comes from the URL: validate, fall back to the current year, and put a reversed range in order.
$from = financeValidDate(get('from')) ? get('from') : date('Y-01-01');
$to = financeValidDate(get('to')) ? get('to') : date('Y-12-31');
if ($from > $to) {
    [$from, $to] = [$to, $from];
}
$byCategory = financeReportByCategory($from, $to);
$byAccount = financeReportByAccount($from, $to);
$vat = financeVatSummary($from, $to);
$totals = financePeriodTotals($from, $to);
$dirLabel = ['IN' => 'Έσοδα', 'OUT' => 'Έξοδα'];

if (get('export') === 'csv') {
    if (ob_get_level() > 0) ob_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="oikonomika-' . $from . '-' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    $eur = fn(int $c) => str_replace('.', ',', financeFromCents($c));
    fputcsvSafe($out, ['Περίοδος', $from, $to], ';');
    fputcsvSafe($out, [], ';');
    fputcsvSafe($out, ['Ανά κατηγορία', 'Είδος', 'Κατηγορία', 'Ποσό'], ';');
    foreach ($byCategory as $r) fputcsvSafe($out, ['', $dirLabel[$r['direction']], $r['category'], $eur($r['total'])], ';');
    fputcsvSafe($out, [], ';');
    fputcsvSafe($out, ['Ανά λογαριασμό', 'Λογαριασμός', 'Έσοδα', 'Έξοδα'], ';');
    foreach ($byAccount as $r) fputcsvSafe($out, ['', $r['name'], $eur($r['total_in']), $eur($r['total_out'])], ';');
    fputcsvSafe($out, [], ';');
    fputcsvSafe($out, ['ΦΠΑ', 'Είδος', 'Συντελεστής', 'Καθαρό', 'ΦΠΑ', 'Σύνολο'], ';');
    foreach ($vat as $r) fputcsvSafe($out, ['', $dirLabel[$r['direction']], $r['rate'] . '%', $eur($r['net']), $eur($r['vat']), $eur($r['gross'])], ';');
    fclose($out);
    exit;
}

$pageTitle = 'Αναφορές Οικονομικών';
include __DIR__ . '/includes/header.php';
?>
<style>@media print { .sidebar, nav, .no-print, footer { display: none !important; } main, .container-fluid { margin: 0 !important; width: 100% !important; } }</style>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h3 mb-0"><i class="bi bi-graph-up-arrow me-2"></i>Αναφορές <small class="text-muted fs-6"><?= h(formatDate($from)) ?> – <?= h(formatDate($to)) ?></small></h1>
    <div class="d-flex gap-2 no-print">
        <a class="btn btn-outline-secondary" href="?<?= h(http_build_query(['from' => $from, 'to' => $to, 'export' => 'csv'])) ?>"><i class="bi bi-filetype-csv me-1"></i>CSV</a>
        <button class="btn btn-outline-secondary" onclick="window.print()"><i class="bi bi-printer me-1"></i>Εκτύπωση</button>
    </div>
</div>
<form class="card card-body mb-3 no-print" method="get"><div class="row g-2">
    <div class="col-6 col-md-3"><input type="date" class="form-control" name="from" value="<?= h($from) ?>"></div>
    <div class="col-6 col-md-3"><input type="date" class="form-control" name="to" value="<?= h($to) ?>"></div>
    <div class="col-12 col-md-2"><button class="btn btn-primary w-100">Εμφάνιση</button></div>
</div></form>

<div class="row g-3 mb-3">
    <div class="col-md-4"><div class="card card-body"><div class="text-muted small">Έσοδα</div><div class="fs-5 text-success"><?= financeFormat($totals['in']) ?></div></div></div>
    <div class="col-md-4"><div class="card card-body"><div class="text-muted small">Έξοδα</div><div class="fs-5 text-danger"><?= financeFormat($totals['out']) ?></div></div></div>
    <div class="col-md-4"><div class="card card-body"><div class="text-muted small">Αποτέλεσμα</div><div class="fs-5"><?= financeFormat($totals['in'] - $totals['out']) ?></div></div></div>
</div>

<div class="row g-3">
    <div class="col-lg-6"><div class="card"><div class="card-header">Ανά κατηγορία (ταμειακά)</div><table class="table mb-0">
        <?php foreach (['IN', 'OUT'] as $d): $rows = array_filter($byCategory, fn($r) => $r['direction'] === $d); ?>
            <tr class="table-light"><th colspan="2"><?= $dirLabel[$d] ?></th></tr>
            <?php if (!$rows): ?><tr><td colspan="2" class="text-muted">—</td></tr><?php endif; ?>
            <?php foreach ($rows as $r): ?><tr><td><?= h($r['category']) ?></td><td class="text-end"><?= financeFormat($r['total']) ?></td></tr><?php endforeach; ?>
        <?php endforeach; ?>
    </table></div></div>
    <div class="col-lg-6"><div class="card mb-3"><div class="card-header">Ανά λογαριασμό</div><table class="table mb-0">
        <thead><tr><th>Λογαριασμός</th><th class="text-end">Έσοδα</th><th class="text-end">Έξοδα</th></tr></thead>
        <?php foreach ($byAccount as $r): ?><tr><td><?= h($r['name']) ?></td><td class="text-end"><?= financeFormat($r['total_in']) ?></td><td class="text-end"><?= financeFormat($r['total_out']) ?></td></tr><?php endforeach; ?>
    </table><div class="card-footer small text-muted">Περιλαμβάνει τις μεταφορές μεταξύ λογαριασμών.</div></div>
    <div class="card"><div class="card-header">ΦΠΑ ανά συντελεστή (με ημερομηνία παραστατικού)</div><table class="table mb-0">
        <thead><tr><th>Είδος</th><th>Συντ.</th><th class="text-end">Καθαρό</th><th class="text-end">ΦΠΑ</th><th class="text-end">Σύνολο</th></tr></thead>
        <?php if (!$vat): ?><tr><td colspan="5" class="text-muted">Κανένα παραστατικό στο διάστημα.</td></tr><?php endif; ?>
        <?php foreach ($vat as $r): ?><tr><td><?= $dirLabel[$r['direction']] ?></td><td><?= $r['rate'] ?>%</td><td class="text-end"><?= financeFormat($r['net']) ?></td><td class="text-end"><?= financeFormat($r['vat']) ?></td><td class="text-end"><?= financeFormat($r['gross']) ?></td></tr><?php endforeach; ?>
    </table></div></div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
