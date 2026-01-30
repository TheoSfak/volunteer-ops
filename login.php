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
    $email = post('email');
    $password = $_POST['password'] ?? ''; // Don't sanitize password
    
    if (empty($email) || empty($password)) {
        $error = 'Παρακαλώ συμπληρώστε email και κωδικό.';
    } else {
        $result = login($email, $password);
        if ($result['success']) {
            redirect('dashboard.php');
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
            background: linear-gradient(135deg, #2c3e50 0%, #3498db 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .login-card {
            background: white;
            border-radius: 1rem;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
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
            
            <hr class="my-4">
            
            <p class="text-center text-muted mb-0">
                Δεν έχετε λογαριασμό; <a href="register.php">Εγγραφείτε</a>
            </p>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
