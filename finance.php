<?php
/** VolunteerOps — Οικονομικά: Επισκόπηση (v3.510.0). */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/finance-functions.php';
requirePermission('finance_manage');

$pageTitle = 'Οικονομικά';
$today = date('Y-m-d');
$balances = array_filter(financeAccountBalances(), fn($a) => (int) $a['is_active'] === 1);
$month = financePeriodTotals(date('Y-m-01'), date('Y-m-t'));
$payables = financeOpenPayables($today);
$owed = array_sum(array_column($payables, 'remaining'));
$overdue = count(array_filter($payables, fn($r) => $r['urgency'] === 'overdue'));
$dueSoon = array_slice(array_values(array_filter($payables, fn($r) => in_array($r['urgency'], ['overdue', 'soon'], true))), 0, 5);
$recent = dbFetchAll("SELECT m.*, a.name AS account_name FROM finance_movements m JOIN finance_accounts a ON a.id = m.account_id ORDER BY m.movement_date DESC, m.id DESC LIMIT 10");

include __DIR__ . '/includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h3 mb-0"><i class="bi bi-wallet2 me-2"></i>Οικονομικά</h1>
    <div class="d-flex gap-2">
        <a class="btn btn-danger" href="finance-document-form.php?direction=OUT"><i class="bi bi-camera me-1"></i>Νέο έξοδο</a>
        <a class="btn btn-success" href="finance-document-form.php?direction=IN"><i class="bi bi-plus-circle me-1"></i>Νέο έσοδο</a>
    </div>
</div>
<?php displayFlash(); ?>
<div class="row g-3 mb-3">
    <?php foreach ($balances as $a): ?>
    <div class="col-6 col-lg-3"><div class="card card-body h-100"><div class="text-muted small"><?= h($a['name']) ?></div><div class="fs-4 fw-semibold <?= $a['balance'] < 0 ? 'text-danger' : '' ?>"><?= financeFormat($a['balance']) ?></div></div></div>
    <?php endforeach; ?>
</div>
<div class="row g-3 mb-4">
    <div class="col-md-4"><div class="card card-body"><div class="text-muted small">Έσοδα μήνα</div><div class="fs-5 text-success"><?= financeFormat($month['in']) ?></div></div></div>
    <div class="col-md-4"><div class="card card-body"><div class="text-muted small">Έξοδα μήνα</div><div class="fs-5 text-danger"><?= financeFormat($month['out']) ?></div></div></div>
    <div class="col-md-4"><a class="card card-body text-decoration-none" href="finance-payables.php"><div class="text-muted small">Οφειλές</div><div class="fs-5"><?= financeFormat($owed) ?><?php if ($overdue): ?> <span class="badge bg-danger"><?= $overdue ?> ληγμένα</span><?php endif; ?></div></a></div>
</div>
<div class="row g-3">
    <div class="col-lg-5"><div class="card h-100"><div class="card-header">Λήγουν σύντομα</div><ul class="list-group list-group-flush">
        <?php if (!$dueSoon): ?><li class="list-group-item text-muted">Τίποτα επείγον.</li><?php endif; ?>
        <?php foreach ($dueSoon as $r): ?><li class="list-group-item d-flex justify-content-between">
            <a href="finance-document-form.php?id=<?= (int) $r['id'] ?>#pay"><?= h($r['counterparty_name']) ?></a>
            <span class="<?= $r['urgency'] === 'overdue' ? 'text-danger' : 'text-warning' ?>"><?= h(formatDate($r['due_date'])) ?> · <?= financeFormat($r['remaining']) ?></span></li><?php endforeach; ?>
    </ul></div></div>
    <div class="col-lg-7"><div class="card h-100"><div class="card-header d-flex justify-content-between">Τελευταίες κινήσεις <a href="finance-movements.php" class="small">Όλες</a></div><ul class="list-group list-group-flush">
        <?php if (!$recent): ?><li class="list-group-item text-muted">Καμία κίνηση ακόμα.</li><?php endif; ?>
        <?php foreach ($recent as $m): ?><li class="list-group-item d-flex justify-content-between">
            <span><?= h(formatDate($m['movement_date'])) ?> · <?= h($m['description'] ?? '') ?> <span class="text-muted small">(<?= h($m['account_name']) ?>)</span></span>
            <span class="<?= $m['direction'] === 'IN' ? 'text-success' : 'text-danger' ?>"><?= ($m['direction'] === 'IN' ? '+' : '−') . financeFormat(financeToCents($m['amount'])) ?></span></li><?php endforeach; ?>
    </ul></div></div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
