<?php
/**
 * VolunteerOps - Email Verification Page
 * Validates the token sent during registration and marks the email as verified.
 * After verification the account still needs admin approval before login.
 */

require_once __DIR__ . '/bootstrap.php';

if (isLoggedIn()) {
    redirect('dashboard.php');
}

$token  = get('token');
$status = 'invalid'; // 'invalid' | 'already' | 'ok'
$userName = '';

if (!empty($token)) {
    $user = dbFetchOne(
        "SELECT id, name, email, email_verified_at
         FROM users
         WHERE email_verification_token = ? AND deleted_at IS NULL",
        [$token]
    );

    if (!$user) {
        $status = 'invalid';
    } elseif ($user['email_verified_at']) {
        $status = 'already';
    } else {
        // Mark email as verified
        dbExecute(
            "UPDATE users SET email_verified_at = NOW(), email_verification_token = NULL WHERE id = ?",
            [$user['id']]
        );

        // Notify all system admins (in-app)
        $admins = dbFetchAll(
            "SELECT id FROM users WHERE role = ? AND is_active = 1 AND deleted_at IS NULL",
            [ROLE_SYSTEM_ADMIN]
        );
        foreach ($admins as $admin) {
            sendNotification(
                $admin['id'],
                'Νέα Αίτηση Εγγραφής',
                'Ο χρήστης ' . $user['name'] . ' (' . $user['email'] . ') επιβεβαίωσε το email του και αναμένει έγκριση εγγραφής.',
                'info'
            );
        }

        logAudit('email_verified', 'users', $user['id']);
        $status   = 'ok';
        $userName = $user['name'];
    }
}

$appName = getSetting('app_name', 'VolunteerOps');
$appLogo = getSetting('app_logo', '');
?>
<!DOCTYPE html>
<html lang="el">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Επιβεβαίωση Email - <?= h($appName) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #2c3e50 0%, #3498db 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 0;
        }
        .verify-card {
            background: white;
            border-radius: 1rem;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            max-width: 480px;
            width: 100%;
            text-align: center;
            padding: 3rem 2rem;
        }
        .icon-circle {
            width: 80px; height: 80px;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 2.5rem;
            margin: 0 auto 1.5rem;
        }
    </style>
</head>
<body>
<div class="verify-card">
    <?php if ($appLogo): ?>
        <img src="<?= h($appLogo) ?>" alt="Logo" style="max-height:60px;margin-bottom:1rem">
    <?php else: ?>
        <h4 class="fw-bold text-primary mb-4"><?= h($appName) ?></h4>
    <?php endif; ?>

    <?php if ($status === 'ok'): ?>
        <div class="icon-circle bg-success text-white mx-auto"><i class="bi bi-check-lg"></i></div>
        <h4 class="fw-bold text-success mb-2">Email Επιβεβαιώθηκε!</h4>
        <p class="text-muted mb-3">
            Γεια σας <strong><?= h($userName) ?></strong>!<br>
            Η ηλεκτρονική σας διεύθυνση επιβεβαιώθηκε επιτυχώς.
        </p>
        <div class="alert alert-warning text-start" role="alert">
            <i class="bi bi-hourglass-split me-2"></i>
            <strong>Αναμονή έγκρισης διαχειριστή</strong><br>
            <small>Ο λογαριασμός σας βρίσκεται τώρα σε αναμονή έγκρισης από τον Διαχειριστή Συστήματος.
            Θα ειδοποιηθείτε μόλις εγκριθεί η εγγραφή σας.</small>
        </div>

    <?php elseif ($status === 'already'): ?>
        <div class="icon-circle bg-info text-white mx-auto"><i class="bi bi-info-lg"></i></div>
        <h4 class="fw-bold text-info mb-2">Ήδη Επιβεβαιωμένο</h4>
        <p class="text-muted">Το email σας έχει ήδη επιβεβαιωθεί. Αν δεν μπορείτε να συνδεθείτε, επικοινωνήστε με τον διαχειριστή.</p>

    <?php else: ?>
        <div class="icon-circle bg-danger text-white mx-auto"><i class="bi bi-x-lg"></i></div>
        <h4 class="fw-bold text-danger mb-2">Μη Έγκυρος Σύνδεσμος</h4>
        <p class="text-muted">Ο σύνδεσμος επιβεβαίωσης δεν είναι έγκυρος ή έχει ήδη χρησιμοποιηθεί.</p>

    <?php endif; ?>

    <a href="login.php" class="btn btn-primary mt-3">
        <i class="bi bi-box-arrow-in-right me-1"></i>Μετάβαση στη Σύνδεση
    </a>
</div>
</body>
</html>
