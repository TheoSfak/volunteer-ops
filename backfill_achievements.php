<?php
/**
 * VolunteerOps - One-time Achievement Backfill
 * Retroactively awards badges to all volunteers based on their existing history.
 * Run once as a system admin, then it can be deleted or left (it's idempotent).
 */
require_once __DIR__ . '/bootstrap.php';
requireRole([ROLE_SYSTEM_ADMIN]);

$pageTitle = 'Backfill Επιτευγμάτων';

$results   = [];
$ran       = false;
$totalNew  = 0;

if (isPost()) {
    verifyCsrf();

    // Fetch all active volunteers (not soft-deleted)
    $volunteers = dbFetchAll(
        "SELECT id, name, email FROM users
         WHERE is_active = 1 AND deleted_at IS NULL AND role = ?
         ORDER BY name ASC",
        [ROLE_VOLUNTEER]
    );

    foreach ($volunteers as $v) {
        $awarded = checkAndAwardAchievements((int)$v['id']);

        // Mark newly awarded as notified=0 (so the popup fires on their next login)
        if (!empty($awarded)) {
            dbExecute(
                "UPDATE user_achievements
                 SET notified = 0
                 WHERE user_id = ? AND achievement_id IN (" . implode(',', array_fill(0, count($awarded), '?')) . ")
                   AND notified = 1",
                array_merge([$v['id']], array_column($awarded, 'id'))
            );
        }

        $results[] = [
            'name'    => $v['name'],
            'email'   => $v['email'],
            'count'   => count($awarded),
            'badges'  => $awarded,
        ];
        $totalNew += count($awarded);
    }

    $ran = true;
    logAudit('backfill_achievements', 'users', null, "Awarded $totalNew badges to " . count($volunteers) . " volunteers");
}

// Pre-run stats
$totalVolunteers = (int)dbFetchValue(
    "SELECT COUNT(*) FROM users WHERE is_active = 1 AND deleted_at IS NULL AND role = ?",
    [ROLE_VOLUNTEER]
);
$alreadyHaveBadges = (int)dbFetchValue(
    "SELECT COUNT(DISTINCT user_id) FROM user_achievements"
);

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">
        <i class="bi bi-trophy-fill text-warning me-2"></i>Backfill Επιτευγμάτων
    </h1>
    <a href="achievements.php" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-grid me-1"></i>Επιτεύγματα
    </a>
</div>

<?php displayFlash(); ?>

<?php if (!$ran): ?>
<div class="card border-warning mb-4">
    <div class="card-header bg-warning bg-opacity-10">
        <h5 class="mb-0 text-warning"><i class="bi bi-exclamation-triangle me-2"></i>Αναδρομική Απόδοση Badges</h5>
    </div>
    <div class="card-body">
        <p class="mb-2">
            Αυτό το εργαλείο ελέγχει <strong>όλο το ιστορικό βαρδιών και πόντων</strong> κάθε εθελοντή
            και αποδίδει τα badges που έχει ήδη κερδίσει αλλά δεν καταγράφτηκαν.
        </p>
        <p class="mb-3">
            Τα νέα badges θα εμφανιστούν ως <strong>αδιάβαστα (notified=0)</strong>, οπότε ο κάθε εθελοντής
            θα δει το popup με confetti την επόμενη φορά που θα συνδεθεί.
        </p>

        <div class="row g-3 mb-4">
            <div class="col-sm-4">
                <div class="card text-center border-0 bg-light">
                    <div class="card-body py-3">
                        <div class="h2 text-primary mb-0"><?= $totalVolunteers ?></div>
                        <small class="text-muted">Ενεργοί Εθελοντές</small>
                    </div>
                </div>
            </div>
            <div class="col-sm-4">
                <div class="card text-center border-0 bg-light">
                    <div class="card-body py-3">
                        <div class="h2 text-success mb-0"><?= $alreadyHaveBadges ?></div>
                        <small class="text-muted">Έχουν ήδη badges</small>
                    </div>
                </div>
            </div>
            <div class="col-sm-4">
                <div class="card text-center border-0 bg-light">
                    <div class="card-body py-3">
                        <div class="h2 text-warning mb-0"><?= $totalVolunteers - $alreadyHaveBadges ?></div>
                        <small class="text-muted">Χωρίς badges ακόμα</small>
                    </div>
                </div>
            </div>
        </div>

        <div class="alert alert-info mb-4">
            <i class="bi bi-info-circle me-2"></i>
            Η διαδικασία είναι <strong>ασφαλής να τρέξει πολλές φορές</strong> — χρησιμοποιεί
            <code>INSERT IGNORE</code> οπότε δεν δημιουργεί διπλότυπα.
        </div>

        <form method="post">
            <?= csrfField() ?>
            <button type="submit" class="btn btn-warning btn-lg">
                <i class="bi bi-play-circle-fill me-2"></i>Εκτέλεση Backfill για <?= $totalVolunteers ?> εθελοντές
            </button>
        </form>
    </div>
</div>

<?php else: ?>

<div class="alert alert-success">
    <i class="bi bi-check-circle-fill me-2"></i>
    Ολοκληρώθηκε! Αποδόθηκαν συνολικά <strong><?= $totalNew ?> badges</strong> σε
    <strong><?= count(array_filter($results, fn($r) => $r['count'] > 0)) ?></strong> εθελοντές.
</div>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0">Αποτελέσματα (<?= count($results) ?> εθελοντές)</h5>
        <div>
            <button class="btn btn-sm btn-outline-secondary" onclick="toggleOnlyNew()">
                <i class="bi bi-funnel me-1"></i>Μόνο με νέα badges
            </button>
        </div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0" id="resultsTable">
                <thead class="table-light">
                    <tr>
                        <th>Εθελοντής</th>
                        <th class="text-center">Νέα Badges</th>
                        <th>Badges που αποδόθηκαν</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($results as $r): ?>
                    <tr class="<?= $r['count'] > 0 ? 'vo-has-new' : 'vo-no-new' ?>">
                        <td>
                            <strong><?= h($r['name']) ?></strong>
                            <br><small class="text-muted"><?= h($r['email']) ?></small>
                        </td>
                        <td class="text-center">
                            <?php if ($r['count'] > 0): ?>
                                <span class="badge bg-warning text-dark fs-6"><?= $r['count'] ?></span>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($r['badges'])): ?>
                                <?php foreach ($r['badges'] as $b): ?>
                                    <span class="badge bg-light text-dark border me-1 mb-1" title="<?= h($b['description'] ?: '') ?>">
                                        <?= $b['icon'] ?> <?= h($b['name']) ?>
                                    </span>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <span class="text-muted small">Κανένα νέο badge</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="mt-3">
    <form method="post" class="d-inline me-2">
        <?= csrfField() ?>
        <button type="submit" class="btn btn-outline-warning">
            <i class="bi bi-arrow-repeat me-1"></i>Εκτέλεση ξανά
        </button>
    </form>
    <a href="volunteers.php" class="btn btn-outline-secondary">
        <i class="bi bi-people me-1"></i>Λίστα Εθελοντών
    </a>
</div>

<script>
var showingOnlyNew = false;
function toggleOnlyNew() {
    showingOnlyNew = !showingOnlyNew;
    document.querySelectorAll('.vo-no-new').forEach(function(row) {
        row.style.display = showingOnlyNew ? 'none' : '';
    });
    document.querySelector('[onclick="toggleOnlyNew()"]').innerHTML =
        showingOnlyNew
            ? '<i class="bi bi-funnel-fill me-1"></i>Εμφάνιση όλων'
            : '<i class="bi bi-funnel me-1"></i>Μόνο με νέα badges';
}
</script>

<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
