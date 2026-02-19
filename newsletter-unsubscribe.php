<?php
/**
 * VolunteerOps - Newsletter Unsubscribe (PUBLIC - no login required)
 *
 * URL: /newsletter-unsubscribe.php?token=XXXX
 */
require_once __DIR__ . '/bootstrap.php';
// No requireLogin() — this page is intentionally public

$token = get('token');
$status = 'invalid'; // invalid | already | success

if (!empty($token)) {
    $record = dbFetchOne(
        "SELECT * FROM newsletter_unsubscribes WHERE token = ?",
        [$token]
    );

    if (!$record) {
        $status = 'invalid';
    } elseif ($record['unsubscribed_at'] !== null) {
        $status = 'already';
    } else {
        // Mark as unsubscribed
        dbExecute(
            "UPDATE newsletter_unsubscribes SET unsubscribed_at = NOW() WHERE token = ?",
            [$token]
        );

        // Also set the flag on the user row for fast filtering
        if ($record['user_id']) {
            dbExecute(
                "UPDATE users SET newsletter_unsubscribed = 1 WHERE id = ?",
                [$record['user_id']]
            );
        }

        $status = 'success';
    }
}

$appName = getSetting('smtp_from_name', 'VolunteerOps');
?>
<!DOCTYPE html>
<html lang="el">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Διαγραφή από λίστα - <?= h($appName) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background: #f4f6f9; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .card { max-width: 480px; width: 100%; }
    </style>
</head>
<body>
<div class="container">
    <div class="card shadow-sm border-0 mx-auto">
        <div class="card-body text-center py-5 px-4">

            <?php if ($status === 'success'): ?>
                <i class="bi bi-check-circle-fill text-success" style="font-size:3rem;"></i>
                <h2 class="mt-3 mb-2">Διαγραφή ολοκληρώθηκε</h2>
                <p class="text-muted">Το email σας αφαιρέθηκε επιτυχώς από τη λίστα αλληλογραφίας του <strong><?= h($appName) ?></strong>.</p>
                <p class="text-muted small">Δεν θα λαμβάνετε πλέον ενημερωτικά δελτία. Αν αλλάξετε γνώμη, επικοινωνήστε μαζί μας.</p>

            <?php elseif ($status === 'already'): ?>
                <i class="bi bi-info-circle-fill text-info" style="font-size:3rem;"></i>
                <h2 class="mt-3 mb-2">Ήδη διαγραμμένο</h2>
                <p class="text-muted">Το email σας έχει ήδη αφαιρεθεί από τη λίστα αλληλογραφίας.</p>

            <?php else: ?>
                <i class="bi bi-exclamation-triangle-fill text-warning" style="font-size:3rem;"></i>
                <h2 class="mt-3 mb-2">Μη έγκυρος σύνδεσμος</h2>
                <p class="text-muted">Ο σύνδεσμος διαγραφής δεν είναι έγκυρος ή έχει ήδη χρησιμοποιηθεί.</p>

            <?php endif; ?>

            <hr class="my-4">
            <small class="text-muted"><?= h($appName) ?></small>
        </div>
    </div>
</div>
</body>
</html>
