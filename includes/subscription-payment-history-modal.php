<?php
/**
 * Reusable payment-history and receipt-preview modals.
 *
 * Required variables:
 *   $paymentHistoryRows, $paymentHistoryModalId, $paymentReceiptModalId,
 *   $paymentHistoryTitle
 */
$paymentHistoryRows = is_array($paymentHistoryRows ?? null) ? $paymentHistoryRows : [];
$paymentHistoryTotal = array_reduce(
    $paymentHistoryRows,
    static fn(float $sum, array $row): float => $sum + ($row['amount'] !== null ? (float)$row['amount'] : 0.0),
    0.0
);
?>
<style>
.sph-summary-card { border: 1px solid var(--bs-border-color); border-radius: .75rem; background: var(--bs-tertiary-bg); }
.sph-summary-value { font-size: 1.2rem; font-weight: 700; }
.sph-notes { min-width: 180px; max-width: 280px; white-space: normal; overflow-wrap: anywhere; }
@media (max-width: 767.98px) {
    .sph-table, .sph-table tbody, .sph-table tr, .sph-table td { display: block; width: 100%; }
    .sph-table thead { display: none; }
    .sph-table tbody { padding: .75rem; }
    .sph-table tr { margin-bottom: .75rem; padding: .35rem .8rem; border: 1px solid var(--bs-border-color); border-radius: .75rem; background: var(--bs-body-bg); }
    .sph-table td { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; padding: .5rem 0; border: 0; border-bottom: 1px solid var(--bs-border-color-translucent); text-align: right; white-space: normal; overflow-wrap: anywhere; }
    .sph-table td:last-child { border-bottom: 0; }
    .sph-table td::before { content: attr(data-label); flex: 0 0 38%; color: var(--bs-secondary-color); font-weight: 600; text-align: left; }
    .sph-table .sph-empty { display: block; text-align: center; padding: 1.5rem 0; }
    .sph-table .sph-empty::before { display: none; }
    .sph-notes { min-width: 0; max-width: none; }
}
</style>

<div class="modal fade" id="<?= h($paymentHistoryModalId) ?>" tabindex="-1" aria-labelledby="<?= h($paymentHistoryModalId) ?>Title" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable modal-fullscreen-sm-down">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="<?= h($paymentHistoryModalId) ?>Title"><i class="bi bi-clock-history me-2"></i><?= h($paymentHistoryTitle) ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Κλείσιμο"></button>
            </div>
            <div class="modal-body p-0">
                <div class="row g-3 p-3 m-0 border-bottom">
                    <div class="col-sm-6">
                        <div class="sph-summary-card p-3 h-100">
                            <div class="small text-muted">Συνολικές πληρωμές</div>
                            <div class="sph-summary-value"><?= count($paymentHistoryRows) ?></div>
                        </div>
                    </div>
                    <div class="col-sm-6">
                        <div class="sph-summary-card p-3 h-100">
                            <div class="small text-muted">Συνολικό ποσό</div>
                            <div class="sph-summary-value"><?= number_format($paymentHistoryTotal, 2, ',', '.') ?> €</div>
                        </div>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 sph-table">
                        <thead class="table-light">
                            <tr><th>Πληρωμή</th><th>Κάλυψη έως</th><th>Έτη</th><th>Ποσό</th><th>Τρόπος</th><th>Αρ. απόδειξης</th><th>Απόδειξη</th><th>Σημειώσεις</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($paymentHistoryRows as $paymentRow): ?>
                            <?php
                            $paymentReceiptPath = __DIR__ . '/../uploads/subscription-receipts/' . basename((string)$paymentRow['receipt_stored_name']);
                            $paymentHasReceipt = !empty($paymentRow['receipt_stored_name']) && is_file($paymentReceiptPath);
                            $paymentReceiptIsImage = $paymentHasReceipt && in_array(strtolower(pathinfo($paymentRow['receipt_stored_name'], PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png'], true);
                            ?>
                            <tr>
                                <td data-label="Πληρωμή" class="text-nowrap"><?= formatDate($paymentRow['payment_date']) ?></td>
                                <td data-label="Κάλυψη έως" class="text-nowrap"><?= formatDate($paymentRow['expiry_date']) ?></td>
                                <td data-label="Έτη"><?= (int)($paymentRow['coverage_years'] ?? 1) ?></td>
                                <td data-label="Ποσό" class="text-nowrap"><?= $paymentRow['amount'] !== null ? number_format((float)$paymentRow['amount'], 2, ',', '.') . ' €' : '—' ?></td>
                                <td data-label="Τρόπος"><?= h($paymentRow['payment_method'] ?: '—') ?></td>
                                <td data-label="Αρ. απόδειξης"><?= h($paymentRow['receipt_number'] ?: '—') ?></td>
                                <td data-label="Απόδειξη">
                                    <?php if ($paymentHasReceipt): ?>
                                        <button type="button" class="btn btn-sm btn-outline-secondary subscription-history-receipt-btn"
                                                data-receipt-modal="<?= h($paymentReceiptModalId) ?>"
                                                data-preview-url="subscription-receipt.php?id=<?= (int)$paymentRow['id'] ?>"
                                                data-preview-type="<?= $paymentReceiptIsImage ? 'image' : 'pdf' ?>"
                                                data-preview-name="<?= h($paymentRow['receipt_original_name'] ?: 'Απόδειξη ' . formatDate($paymentRow['payment_date'])) ?>">
                                            <i class="bi <?= $paymentReceiptIsImage ? 'bi-image' : 'bi-file-earmark-pdf' ?> me-1"></i>Προβολή
                                        </button>
                                    <?php elseif (!empty($paymentRow['receipt_stored_name'])): ?>
                                        <span class="text-danger small"><i class="bi bi-exclamation-triangle"></i> Μη διαθέσιμη</span>
                                    <?php else: ?>—<?php endif; ?>
                                </td>
                                <td data-label="Σημειώσεις" class="sph-notes"><?= h($paymentRow['notes'] ?: '—') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$paymentHistoryRows): ?>
                            <tr><td colspan="8" class="sph-empty text-muted">Δεν υπάρχουν καταχωρημένες πληρωμές.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Κλείσιμο</button></div>
        </div>
    </div>
</div>

<div class="modal fade" id="<?= h($paymentReceiptModalId) ?>" tabindex="-1" aria-labelledby="<?= h($paymentReceiptModalId) ?>Title" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-fullscreen-sm-down">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-truncate" id="<?= h($paymentReceiptModalId) ?>Title">Προεπισκόπηση απόδειξης</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Κλείσιμο"></button>
            </div>
            <div class="modal-body text-center">
                <img data-receipt-preview-image src="" alt="Προεπισκόπηση απόδειξης" class="img-fluid rounded border d-none" style="max-width:100%;max-height:70vh;object-fit:contain;">
                <iframe data-receipt-preview-pdf src="" title="Προεπισκόπηση απόδειξης PDF" class="w-100 border rounded d-none" style="height:70vh;"></iframe>
                <div data-receipt-preview-error class="alert alert-danger mt-3 d-none mb-0">Δεν ήταν δυνατή η προβολή της απόδειξης.</div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const historyElement = document.getElementById(<?= json_encode($paymentHistoryModalId) ?>);
    const receiptElement = document.getElementById(<?= json_encode($paymentReceiptModalId) ?>);
    if (!historyElement || !receiptElement) return;

    const receiptTitle = document.getElementById(<?= json_encode($paymentReceiptModalId . 'Title') ?>);
    const receiptImage = receiptElement.querySelector('[data-receipt-preview-image]');
    const receiptPdf = receiptElement.querySelector('[data-receipt-preview-pdf]');
    const receiptError = receiptElement.querySelector('[data-receipt-preview-error]');
    let returnToHistory = false;

    document.querySelectorAll('.subscription-history-receipt-btn[data-receipt-modal="' + <?= json_encode($paymentReceiptModalId) ?> + '"]').forEach(button => {
        button.addEventListener('click', () => {
            const isImage = button.dataset.previewType === 'image';
            receiptTitle.textContent = button.dataset.previewName || 'Προεπισκόπηση απόδειξης';
            receiptError.classList.add('d-none');
            receiptImage.classList.toggle('d-none', !isImage);
            receiptPdf.classList.toggle('d-none', isImage);
            if (isImage) receiptImage.src = button.dataset.previewUrl;
            else receiptPdf.src = button.dataset.previewUrl;

            const showReceipt = () => bootstrap.Modal.getOrCreateInstance(receiptElement).show();
            if (historyElement.classList.contains('show')) {
                returnToHistory = true;
                historyElement.addEventListener('hidden.bs.modal', showReceipt, {once: true});
                bootstrap.Modal.getOrCreateInstance(historyElement).hide();
            } else {
                returnToHistory = false;
                showReceipt();
            }
        });
    });

    receiptImage.addEventListener('error', () => receiptError.classList.remove('d-none'));
    receiptElement.addEventListener('hidden.bs.modal', () => {
        receiptImage.removeAttribute('src');
        receiptPdf.removeAttribute('src');
        if (returnToHistory) {
            returnToHistory = false;
            bootstrap.Modal.getOrCreateInstance(historyElement).show();
        }
    });
});
</script>
