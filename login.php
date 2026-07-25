<?php
/**
 * VolunteerOps - Login Page
 */

require_once __DIR__ . '/bootstrap.php';

// Already logged in?
if (isLoggedIn()) {
    redirect('dashboard.php');
}

$error = '';

if (isPost()) {
    verifyCsrf();
    $email = post('email');
    $password = $_POST['password'] ?? ''; // Don't sanitize password
    
    if (empty($email) || empty($password)) {
        $error = 'Παρακαλώ συμπληρώστε email και κωδικό.';
    } else {
        $result = login($email, $password);
        if ($result['success']) {
            // Maintenance mode: block non-admin logins
            if (getSetting('maintenance_mode', '0')) {
                $loggedUser = getCurrentUser();
                if ($loggedUser && !in_array($loggedUser['role'], [ROLE_SYSTEM_ADMIN, ROLE_DEPARTMENT_ADMIN])) {
                    logout();
                    $error = 'Το σύστημα βρίσκεται σε συντήρηση. Παρακαλώ δοκιμάστε αργότερα.';
                } else {
                    redirect('dashboard.php');
                }
            } else {
                redirect('dashboard.php');
            }
        } else {
            $error = $result['message'];
        }
    }
}

$flash = getFlash();
$appName = getSetting('app_name', 'VolunteerOps');
$appDescription = getSetting('app_description', 'Σύστημα Διαχείρισης Εθελοντών');
$appLogo = getSetting('app_logo', '');
?>
<!DOCTYPE html>
<html lang="el">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Σύνδεση - <?= h($appName) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body {
            /* Team photo as backdrop, with the same blue gradient laid on top
               at partial opacity — dims the photo just enough that the
               footer text (outside the opaque login card below) stays
               readable over any part of it, bright sky included, without
               hiding the photo itself. No background-attachment:fixed —
               that renders badly on mobile Safari. */
            background:
                linear-gradient(135deg, rgba(44,62,80,0.82) 0%, rgba(52,152,219,0.82) 100%),
                url('assets/images/login-bg.jpg') center/cover no-repeat;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }
        .login-card {
            background: white;
            border-radius: 1rem;
            box-shadow: 0 10px 40px rgba(0,0,0,0.35);
            max-width: 400px;
            width: 100%;
        }
        .login-header {
            background: linear-gradient(135deg, #2c3e50 0%, #1a252f 100%);
            color: white;
            padding: 2rem;
            text-align: center;
            border-radius: 1rem 1rem 0 0;
        }
        .login-body {
            padding: 2rem;
        }
        .btn-login {
            background: linear-gradient(135deg, #3498db 0%, #2980b9 100%);
            border: none;
            padding: 0.75rem;
            font-weight: 500;
        }
        .btn-login:hover {
            background: linear-gradient(135deg, #2980b9 0%, #1f6691 100%);
        }
    </style>
</head>
<body>
    <div class="py-4 w-100 d-flex justify-content-center">
    <div class="login-card">
        <div class="login-header">
            <?php if (!empty($appLogo) && file_exists(__DIR__ . '/uploads/logos/' . $appLogo)): ?>
                <img src="uploads/logos/<?= h($appLogo) ?>" alt="<?= h($appName) ?>" style="max-height: 60px; margin-bottom: 10px;">
                <h2><?= h($appName) ?></h2>
            <?php else: ?>
                <h2><i class="bi bi-heart-pulse me-2"></i><?= h($appName) ?></h2>
            <?php endif; ?>
            <p class="mb-0 opacity-75"><?= h($appDescription) ?></p>
        </div>
        <div class="login-body">
            <?php if (getSetting('maintenance_mode', '0')): ?>
                <div class="alert alert-warning">
                    <i class="bi bi-tools me-1"></i>Το σύστημα βρίσκεται σε λειτουργία συντήρησης. Μόνο διαχειριστές μπορούν να συνδεθούν.
                </div>
            <?php endif; ?>
            <?php if (!empty($flash['error'])): ?>
                <div class="alert alert-danger"><?= h($flash['error']) ?></div>
            <?php endif; ?>
            <?php if (!empty($flash['success'])): ?>
                <div class="alert alert-success"><?= h($flash['success']) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger"><?= h($error) ?></div>
            <?php endif; ?>
            
            <form method="post" action="">
                <?= csrfField() ?>
                <div class="mb-3">
                    <label for="email" class="form-label">Email</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                        <input type="email" class="form-control" id="email" name="email" 
                               value="<?= h(post('email')) ?>" required autofocus>
                    </div>
                </div>
                
                <div class="mb-4">
                    <label for="password" class="form-label">Κωδικός</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-lock"></i></span>
                        <input type="password" class="form-control" id="password" name="password" required>
                    </div>
                </div>
                
                <button type="submit" class="btn btn-primary btn-login w-100">
                    <i class="bi bi-box-arrow-in-right me-2"></i>Σύνδεση
                </button>
            </form>
            
            <div class="text-center mt-3">
                <a href="forgot-password.php" class="text-muted small">
                    <i class="bi bi-key me-1"></i>Ξεχάσατε τον κωδικό σας;
                </a>
            </div>
            
            <?php if (getSetting('registration_enabled', '1') && getSetting('show_register_button', '0')): ?>
            <hr class="my-3">
            <p class="text-center text-muted mb-0">
                Δεν έχετε λογαριασμό; <a href="register.php">Εγγραφείτε</a>
            </p>
            <?php endif; ?>
        </div>
    </div>
    </div><!-- /.py-4 wrapper -->

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

    <script>
    // Reaching the login page means nobody is signed in on this device, so the
    // Action Room's offline field snapshot (teammates' names and phone
    // numbers, waypoint addresses) must not survive here — a shared or lost
    // phone would otherwise keep showing it to whoever opens the app next.
    // The pending-action queues are deliberately NOT touched: those are
    // unsent field reports, they carry no roster data, and a volunteer whose
    // session merely expired mid-mission must not lose them by landing here.
    try { localStorage.removeItem('wr_field_snapshot'); } catch (e) {}
    </script>

    <footer class="text-center mt-4 pb-3" style="color:rgba(255,255,255,0.7);font-size:0.82rem">
        <div>&copy; <?= date('Y') ?> <?= h($appName) ?>. Με επιφύλαξη παντός δικαιώματος.</div>
        <div class="mt-1">Made with <span style="color:#e74c3c">&hearts;</span> by <strong>Theodore Sfakianakis</strong> &bull; Powered by <a href="https://activeweb.gr" target="_blank" rel="noopener" style="color:rgba(255,255,255,0.85)">ActiveWeb</a></div>
    </footer>
</body>
</html>
