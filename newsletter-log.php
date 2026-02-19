<?php
/**
 * VolunteerOps - Newsletter Log & Statistics
 */
require_once __DIR__ . '/bootstrap.php';
requireRole([ROLE_SYSTEM_ADMIN]);

$pageTitle = 'Στατιστικά Ενημερωτικών';

// Global totals
$totalCampaigns  = (int)dbFetchValue("SELECT COUNT(*) FROM newsletters WHERE status = 'sent'");
$totalSent       = (int)dbFetchValue("SELECT COALESCE(SUM(sent_count),0) FROM newsletters WHERE status = 'sent'");
$totalFailed     = (int)dbFetchValue("SELECT COALESCE(SUM(failed_count),0) FROM newsletters WHERE status = 'sent'");
$totalUnsubs     = (int)dbFetchValue("SELECT COUNT(*) FROM newsletter_unsubscribes WHERE unsubscribed_at IS NOT NULL");
$totalUnsubUsers = (int)dbFetchValue("SELECT COUNT(*) FROM users WHERE newsletter_unsubscribed = 1 AND deleted_at IS NULL");

// Monthly breakdown (last 12 months)
$monthly = dbFetchAll("
    SELECT DATE_FORMAT(sent_at, '%Y-%m') AS month,
           COUNT(*)         AS campaigns,
           SUM(sent_count)  AS emails_sent,
           SUM(failed_count) AS emails_failed
    FROM newsletters
    WHERE status = 'sent' AND sent_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY DATE_FORMAT(sent_at, '%Y-%m')
    ORDER BY month DESC
");

// All campaigns
$campaigns = dbFetchAll("
    SELECT n.*, u.name AS creator_name
    FROM newsletters n
    LEFT JOIN users u ON u.id = n.created_by
    ORDER BY n.created_at DESC
");

// Unsubscribed users
$unsubUsers = dbFetchAll("
    SELECT u.name, u.email, u.role, nu.unsubscribed_at, nu.newsletter_id,
           n.title AS campaign_title
    FROM newsletter_unsubscribes nu
    JOIN users u ON u.id = nu.user_id
    LEFT JOIN newsletters n ON n.id = nu.newsletter_id
    WHERE nu.unsubscribed_at IS NOT NULL
    ORDER BY nu.unsubscribed_at DESC
    LIMIT 100
");

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0"><i class="bi bi-bar-chart-fill me-2"></i><?= h($pageTitle) ?></h1>
    <a href="newsletters.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Πίσω</a>
</div>

<!-- Summary Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="fs-2 fw-bold text-primary"><?= $totalCampaigns ?></div>
            <small class="text-muted">Εκστρατείες</small>
        </div>
    </div>
    <div class="col-6 col-md">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="fs-2 fw-bold text-success"><?= number_format($totalSent) ?></div>
            <small class="text-muted">Emails αποστάλθηκαν</small>
        </div>
    </div>
    <div class="col-6 col-md">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="fs-2 fw-bold text-danger"><?= number_format($totalFailed) ?></div>
            <small class="text-muted">Αποτυχίες</small>
        </div>
    </div>
    <div class="col-6 col-md">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="fs-2 fw-bold text-warning"><?= $totalUnsubs ?></div>
            <small class="text-muted">Διαγραφές</small>
        </div>
    </div>
    <div class="col-6 col-md">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="fs-2 fw-bold text-secondary"><?= $totalUnsubUsers ?></div>
            <small class="text-muted">Χρήστες unsubscribed</small>
        </div>
    </div>
</div>

<?php if ($totalSent > 0 && $totalSent + $totalFailed > 0): ?>
<div class="mb-1 small text-muted">Ποσοστό επιτυχίας</div>
<div class="progress mb-4" style="height:12px;">
    <?php $successPct = round(($totalSent / ($totalSent + $totalFailed)) * 100) ?>
    <div class="progress-bar bg-success" style="width:<?= $successPct ?>%" title="<?= $successPct ?>% επιτυχία"></div>
    <div class="progress-bar bg-danger" style="width:<?= 100-$successPct ?>%"></div>
</div>
<?php endif; ?>

<div class="row g-4">
    <!-- Monthly table -->
    <div class="col-lg-5">
        <div class="card shadow-sm h-100">
            <div class="card-header"><strong>Μηνιαία Ανάλυση</strong></div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr><th>Μήνας</th><th>Εκστρ.</th><th class="text-success">Εστάλησαν</th><th class="text-danger">Αποτ.</th></tr>
                    </thead>
                    <tbody>
                    <?php if (empty($monthly)): ?>
                        <tr><td colspan="4" class="text-center text-muted py-3">Χωρίς δεδομένα</td></tr>
                    <?php else: ?>
                        <?php foreach ($monthly as $m): ?>
                        <tr>
                            <td><?= h($m['month']) ?></td>
                            <td><?= $m['campaigns'] ?></td>
                            <td class="text-success fw-semibold"><?= number_format($m['emails_sent']) ?></td>
                            <td class="text-danger"><?= $m['emails_failed'] ?: '—' ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- All campaigns -->
    <div class="col-lg-7">
        <div class="card shadow-sm">
            <div class="card-header"><strong>Όλες οι Εκστρατείες</strong></div>
            <div class="table-responsive" style="max-height:420px;overflow-y:auto;">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light sticky-top">
                        <tr><th>Τίτλος</th><th>Ημ/νία</th><th>✓</th><th>✗</th><th>Κατ.</th><th></th></tr>
                    </thead>
                    <tbody>
                    <?php if (empty($campaigns)): ?>
                        <tr><td colspan="6" class="text-center text-muted py-3">Χωρίς δεδομένα</td></tr>
                    <?php else: ?>
                        <?php foreach ($campaigns as $c): ?>
                        <?php
                        $sb = ['draft'=>'secondary','sending'=>'warning','sent'=>'success','failed'=>'danger'];
                        $sl = ['draft'=>'Πρόχ.','sending'=>'…','sent'=>'OK','failed'=>'FAIL'];
                        ?>
                        <tr>
                            <td><?= h(mb_strimwidth($c['title'], 0, 40, '…')) ?></td>
                            <td class="text-muted small"><?= $c['sent_at'] ? formatDateTime($c['sent_at']) : formatDate($c['created_at']) ?></td>
                            <td class="text-success fw-semibold"><?= $c['status']==='sent' ? $c['sent_count'] : '—' ?></td>
                            <td class="<?= $c['failed_count'] > 0 ? 'text-danger fw-semibold' : 'text-muted' ?>"><?= $c['status']==='sent' ? ($c['failed_count'] ?: '—') : '—' ?></td>
                            <td><span class="badge bg-<?= $sb[$c['status']] ?? 'secondary' ?>"><?= $sl[$c['status']] ?? $c['status'] ?></span></td>
                            <td><a href="newsletter-view.php?id=<?= $c['id'] ?>" class="btn btn-sm btn-outline-primary py-0"><i class="bi bi-eye"></i></a></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Unsubscribed users -->
<?php if (!empty($unsubUsers)): ?>
<div class="card shadow-sm mt-4">
    <div class="card-header d-flex justify-content-between">
        <strong><i class="bi bi-person-x me-1"></i>Χρήστες που Διαγράφηκαν</strong>
        <small class="text-muted"><?= count($unsubUsers) ?> τελευταίες εγγραφές</small>
    </div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead class="table-light">
                <tr><th>Χρήστης</th><th>Email</th><th>Ρόλος</th><th>Εκστρατεία</th><th>Ημ/νία</th></tr>
            </thead>
            <tbody>
            <?php foreach ($unsubUsers as $us): ?>
            <tr>
                <td><?= h($us['name']) ?></td>
                <td><?= h($us['email']) ?></td>
                <td><?= roleBadge($us['role']) ?></td>
                <td><?= $us['campaign_title'] ? h(mb_strimwidth($us['campaign_title'], 0, 40, '…')) : '—' ?></td>
                <td class="text-muted small"><?= formatDateTime($us['unsubscribed_at']) ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
