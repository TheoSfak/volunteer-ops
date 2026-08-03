<?php
/**
 * VolunteerOps - Forgot Password
 * Sends a password reset link to the user's email.
 */
require_once __DIR__ . '/bootstrap.php';

if (isLoggedIn()) {
    redirect('dashboard.php');
}

$sent = false;
$error = '';

if (isPost()) {
    verifyCsrf();
    $email = trim(post('email'));

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Παρακαλώ εισάγετε έγκυρο email.';
    } else {
        $user = dbFetchOne(
            "SELECT id, name, email FROM users WHERE email = ? AND is_active = 1 AND deleted_at IS NULL",
            [$email]
        );

        if ($user) {
            // Invalidate old tokens for this user
            dbExecute("DELETE FROM password_reset_tokens WHERE user_id = ?", [$user['id']]);

            $token   = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', time() + 3600); // 1 hour

            dbInsert(
                "INSERT INTO password_reset_tokens (user_id, token, expires_at) VALUES (?, ?, ?)",
                [$user['id'], $token, $expires]
            );

            $appName = getSetting('app_name', 'VolunteerOps');
            $proto   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $path    = dirname($_SERVER['SCRIPT_NAME'] ?? '/');
            $baseUrl = getSetting('app_url', $proto . '://' . $host . rtrim($path, '/'));
            $resetUrl = rtrim($baseUrl, '/') . '/reset-password.php?token=' . $token;

            $subject = 'Επαναφορά Κωδικού - ' . $appName;
            $body = '
<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:20px">
  <h2 style="color:#2c3e50">Επαναφορά Κωδικού</h2>
  <p>Γεια σας <strong>' . htmlspecialchars($user['name']) . '</strong>,</p>
  <p>Λάβαμε αίτημα επαναφοράς κωδικού για τον λογαριασμό σας. Ο σύνδεσμος ισχύει για <strong>1 ώρα</strong>.</p>
  <p style="text-align:center;margin:2rem 0">
    <a href="' . $resetUrl . '" style="background:#3498db;color:white;padding:14px 32px;text-decoration:none;border-radius:6px;display:inline-block;font-size:16px">
      🔑 Επαναφορά Κωδικού
    </a>
  </p>
  <p>Ή αντιγράψτε αυτό το link:<br><small style="color:#666">' . $resetUrl . '</small></p>
  <hr style="border:1px solid #eee;margin:1.5rem 0">
  <p style="color:#888;font-size:13px">Αν δεν ζητήσατε επαναφορά κωδικού, αγνοήστε αυτό το email.</p>
</div>';

            sendEmail($user['email'], $subject, $body);
            logAudit('password_reset_request', 'users', $user['id']);
        }

        // Always show success (don't reveal if email exists)
        $sent = true;
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
    <title>Ξεχάσατε τον κωδικό; - <?= h($appName) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-T3c6CoIi6uLrA9TneNEoa7RxnatzjcDSCmG1MXxSR1GAsXEV/Dwwykc2MPK8M2HN" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet" integrity="sha384-4LISF5TTJX/fLmGSxO53rV4miRxdg84mZsxmO8Rx5jGtp/LbrixFETvWa5a6sESd" crossorigin="anonymous">
    <style>
        body {
            background: linear-gradient(135deg, #2c3e50 0%, #3498db 100%);
            min-height: 100vh;
            display: flex; flex-direction: column; align-items: center; justify-content: center;
        }
        .card-auth {
            background: white; border-radius: 1rem;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            max-width: 420px; width: 100%;
        }
        .card-header-auth {
            background: linear-gradient(135deg, #2c3e50 0%, #1a252f 100%);
            color: white; padding: 2rem; text-align: center;
            border-radius: 1rem 1rem 0 0;
        }
    </style>
</head>
<body>
<div class="py-4 w-100 d-flex justify-content-center">
<div class="card-auth">
    <div class="card-header-auth">
        <?php if (!empty($appLogo) && file_exists(__DIR__ . '/uploads/logos/' . $appLogo)): ?>
            <img src="uploads/logos/<?= h($appLogo) ?>" alt="<?= h($appName) ?>" style="max-height:55px;margin-bottom:8px"><br>
        <?php endif; ?>
        <h4 class="mb-0"><i class="bi bi-key me-2"></i>Ξεχάσατε τον κωδικό;</h4>
        <p class="mb-0 opacity-75 mt-1 small"><?= h($appName) ?></p>
    </div>
    <div class="p-4">

        <?php if ($sent): ?>
            <div class="alert alert-success">
                <i class="bi bi-check-circle me-2"></i>
                Αν το email υπάρχει στο σύστημα, θα λάβετε σύντομα οδηγίες επαναφοράς κωδικού.
            </div>
            <a href="login.php" class="btn btn-primary w-100">
                <i class="bi bi-box-arrow-in-right me-2"></i>Επιστροφή στη Σύνδεση
            </a>

        <?php else: ?>
            <p class="text-muted small mb-3">Εισάγετε το email σας και θα σας στείλουμε σύνδεσμο επαναφοράς κωδικού.</p>

            <?php if ($error): ?>
                <div class="alert alert-danger"><?= h($error) ?></div>
            <?php endif; ?>

            <form method="post">
                <?= csrfField() ?>
                <div class="mb-3">
                    <label class="form-label">Email</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                        <input type="email" class="form-control" name="email"
                               value="<?= h(post('email')) ?>" required autofocus
                               placeholder="yourname@example.com">
                    </div>
                </div>
                <button type="submit" class="btn btn-primary w-100 mb-3">
                    <i class="bi bi-send me-2"></i>Αποστολή Συνδέσμου
                </button>
            </form>

            <div class="text-center">
                <a href="login.php" class="text-muted small">
                    <i class="bi bi-arrow-left me-1"></i>Επιστροφή στη Σύνδεση
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>
</div><!-- /.py-4 wrapper -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-C6RzsynM9kWDrMNeT87bh95OGNyZPhcTNXj1NW7RuBCsyN/o0jlpcV8Qyq46cDfL" crossorigin="anonymous"></script>

<footer class="text-center mt-4 pb-3" style="color:rgba(255,255,255,0.7);font-size:0.82rem">
    <div>&copy; <?= date('Y') ?> <?= h($appName) ?>. Με επιφύλαξη παντός δικαιώματος.</div>
    <div class="mt-1">Made with <span style="color:#e74c3c">&hearts;</span> by <strong>Theodore Sfakianakis</strong> &bull; Powered by <a href="https://activeweb.gr" target="_blank" rel="noopener" style="color:rgba(255,255,255,0.85)">ActiveWeb</a></div>
</footer>
</body>
</html>
