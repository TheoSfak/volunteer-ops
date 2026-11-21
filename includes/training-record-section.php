<?php
/**
 * The «Εκπαιδευτικές ασκήσεις» card: drills attended, hours, dated list with a
 * link to the certificate when one was issued. Included by profile.php and
 * volunteer-view.php, which define $trainingRecord (getVolunteerTrainingRecord())
 * and $trainingRecordCanViewCertificate first. Prints nothing when there is no
 * record, so volunteers who never took part see no empty card.
 */
if (empty($trainingRecord) || ($trainingRecord['drills'] ?? 0) < 1) {
    return;
}
?>
<div class="card mb-4">
    <div class="card-header">
        <h5 class="mb-0"><i class="bi bi-mortarboard-fill text-warning me-2"></i>Εκπαιδευτικές ασκήσεις</h5>
    </div>
    <div class="card-body">
        <div class="row text-center mb-3">
            <div class="col-6">
                <div class="fs-3 fw-bold"><?= (int) $trainingRecord['drills'] ?></div>
                <div class="text-muted small">Ασκήσεις</div>
            </div>
            <div class="col-6">
                <div class="fs-3 fw-bold"><?= number_format((float) $trainingRecord['hours'], 1) ?></div>
                <div class="text-muted small">Εκπαιδευτικές ώρες</div>
            </div>
        </div>
        <ul class="list-group list-group-flush">
            <?php foreach ($trainingRecord['rows'] as $row): ?>
                <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                    <span>
                        <strong><?= h($row['title']) ?></strong><br>
                        <small class="text-muted"><?= formatDate($row['first_start']) ?> · <?= number_format($row['hours'], 1) ?> ώρες</small>
                        <?php if (!empty($row['cases'])): ?>
                            <br><small class="text-muted"><i class="bi bi-clipboard2-pulse me-1" aria-hidden="true"></i><?= h(t('case.record.line', ['cases' => (int) $row['cases'], 'percent' => (int) $row['case_percent']])) ?></small>
                        <?php endif; ?>
                    </span>
                    <?php if (!empty($row['certificate_id']) && !empty($trainingRecordCanViewCertificate)): ?>
                        <a class="btn btn-sm btn-outline-success" target="_blank"
                           href="mission-certificate-print.php?id=<?= (int) $row['certificate_id'] ?>">
                            <i class="bi bi-patch-check me-1"></i>Βεβαίωση
                        </a>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <div class="form-text mt-2">Οι εκπαιδευτικές ώρες καταγράφονται χωριστά και δεν προστίθενται στις ώρες εθελοντισμού.</div>
    </div>
</div>
