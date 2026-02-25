<?php
/**
 * VolunteerOps - Reset Password
 * Validates the reset token and allows the user to set a new password.
 */
require_once __DIR__ . '/bootstrap.php';

if (isLoggedIn()) {
    redirect('dashboard.php');
}

$token   = get('token');
$success = false;
$error   = '';
$tokenData = null;

if (empty($token)) {
    setFlash('error', 'Μη έγκυρος σύνδεσμος επαναφοράς.');
    redirect('login.php');
}

// Validate token
$tokenData = dbFetchOne(
    "SELECT prt.*, u.name, u.email
     FROM password_reset_tokens prt
     JOIN users u ON prt.user_id = u.id
     WHERE prt.token = ? AND prt.used_at IS NULL AND prt.expires_at > NOW() AND u.deleted_at IS NULL",
    [$token]
);

if (!$tokenData) {
    setFlash('error', 'Ο σύνδεσμος επαναφοράς δεν είναι έγκυρος ή έχει λήξει.');
    redirect('login.php');
}

if (isPost()) {
    verifyCsrf();
    $password        = $_POST['password'] ?? '';
    $passwordConfirm = $_POST['password_confirm'] ?? '';

    if (strlen($password) < 6) {
        $error = 'Ο κωδικός πρέπει να έχει τουλάχιστον 6 χαρακτήρες.';
    } elseif ($password !== $passwordConfirm) {
        $error = 'Οι κωδικοί δεν ταιριάζουν.';
    } else {
        $hashed = password_hash($password, PASSWORD_DEFAULT);
        dbExecute(
            "UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?",
            [$hashed, $tokenData['user_id']]
        );
        dbExecute(
            "UPDATE password_reset_tokens SET used_at = NOW() WHERE id = ?",
            [$tokenData['id']]
        );
        logAudit('password_reset', 'users', $tokenData['user_id']);
        $success = true;
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
    <title>Επαναφορά Κωδικού - <?= h($appName) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #2c3e50 0%, #3498db 100%);
            min-height: 100vh;
            display: flex; align-items: center; justify-content: center;
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
        .password-strength { height: 4px; border-radius: 2px; transition: all .3s; }
    </style>
</head>
<body>
<div class="card-auth">
    <div class="card-header-auth">
        <?php if (!empty($appLogo) && file_exists(__DIR__ . '/uploads/logos/' . $appLogo)): ?>
            <img src="uploads/logos/<?= h($appLogo) ?>" alt="<?= h($appName) ?>" style="max-height:55px;margin-bottom:8px"><br>
        <?php endif; ?>
        <h4 class="mb-0"><i class="bi bi-shield-lock me-2"></i>Νέος Κωδικός</h4>
        <p class="mb-0 opacity-75 mt-1 small"><?= h($appName) ?></p>
    </div>
    <div class="p-4">

        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="bi bi-check-circle me-2"></i>
                Ο κωδικός σας άλλαξε επιτυχώς!
            </div>
            <a href="login.php" class="btn btn-primary w-100">
                <i class="bi bi-box-arrow-in-right me-2"></i>Σύνδεση
            </a>

        <?php else: ?>
            <p class="text-muted small mb-3">
                Ορίστε νέο κωδικό για τον λογαριασμό <strong><?= h($tokenData['email']) ?></strong>.
            </p>

            <?php if ($error): ?>
                <div class="alert alert-danger"><?= h($error) ?></div>
            <?php endif; ?>

            <form method="post">
                <?= csrfField() ?>
                <input type="hidden" name="token" value="<?= h($token) ?>">

                <div class="mb-3">
                    <label class="form-label">Νέος Κωδικός</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-lock"></i></span>
                        <input type="password" class="form-control" id="password" name="password"
                               minlength="6" required placeholder="Τουλάχιστον 6 χαρακτήρες"
                               oninput="updateStrength(this.value)">
                        <button type="button" class="btn btn-outline-secondary" onclick="togglePass('password')">
                            <i class="bi bi-eye" id="eye-password"></i>
                        </button>
                    </div>
                    <div class="mt-1 password-strength bg-secondary" id="strength-bar" style="width:0%"></div>
                </div>

                <div class="mb-4">
                    <label class="form-label">Επιβεβαίωση Κωδικού</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-lock-fill"></i></span>
                        <input type="password" class="form-control" id="password_confirm" name="password_confirm"
                               minlength="6" required>
                        <button type="button" class="btn btn-outline-secondary" onclick="togglePass('password_confirm')">
                            <i class="bi bi-eye" id="eye-password_confirm"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary w-100 mb-3">
                    <i class="bi bi-check-lg me-2"></i>Αποθήκευση Κωδικού
                </button>
            </form>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function togglePass(id) {
    const input = document.getElementById(id);
    const eye   = document.getElementById('eye-' + id);
    if (input.type === 'password') {
        input.type = 'text';
        eye.className = 'bi bi-eye-slash';
    } else {
        input.type = 'password';
        eye.className = 'bi bi-eye';
    }
}
function updateStrength(val) {
    const bar = document.getElementById('strength-bar');
    let score = 0;
    if (val.length >= 6)  score++;
    if (val.length >= 10) score++;
    if (/[A-Z]/.test(val)) score++;
    if (/[0-9]/.test(val)) score++;
    if (/[^A-Za-z0-9]/.test(val)) score++;
    const colors = ['bg-danger','bg-danger','bg-warning','bg-info','bg-success','bg-success'];
    const widths  = ['0%','25%','40%','65%','85%','100%'];
    bar.className = 'mt-1 password-strength ' + (colors[score] || 'bg-secondary');
    bar.style.width = widths[score] || '0%';
}
</script>

<footer class="text-center mt-4 pb-3" style="color:rgba(255,255,255,0.7);font-size:0.82rem">
    <div>&copy; <?= date('Y') ?> <?= h($appName) ?>. Με επιφύλαξη παντός δικαιώματος.</div>
    <div class="mt-1">Made with <span style="color:#e74c3c">&hearts;</span> by <strong>Theodore Sfakianakis</strong> &bull; Powered by <a href="https://activeweb.gr" target="_blank" rel="noopener" style="color:rgba(255,255,255,0.85)">ActiveWeb</a></div>
</footer>
</body>
</html>
