<?php
/**
 * Admin warning for the training-missions gate. Prints nothing when drills are
 * available and no migration error is recorded. The including page decides who
 * sees it (admins only); this file only decides whether there is anything to say.
 */
if (!defined('VOLUNTEEROPS')) {
    die('Direct access not permitted');
}

$trainingGateMigrationError = trim((string) getSetting('migration_last_error', ''));
if (!trainingMissionsAvailable() || $trainingGateMigrationError !== '') {
    if (mb_strlen($trainingGateMigrationError) > 200) {
        $trainingGateMigrationError = mb_substr($trainingGateMigrationError, 0, 200) . '…';
    }
    ?>
<div class="alert alert-warning mb-4" role="alert">
    <i class="bi bi-exclamation-triangle-fill me-1"></i>Προσοχή: ο μηχανισμός απομόνωσης εκπαιδευτικών ασκήσεων (trigger trg_pr_set_training) δεν είναι ενεργός ή η βάση δεδομένων ανέφερε σφάλμα μετάβασης. Οι εκπαιδευτικές ασκήσεις είναι απενεργοποιημένες μέχρι να διορθωθεί.
    <?php if ($trainingGateMigrationError !== ''): ?>
        <div class="small mt-1"><?= h($trainingGateMigrationError) ?></div>
    <?php endif; ?>
</div>
<?php
}
