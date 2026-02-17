<?php
/**
 * VolunteerOps - Registration Page
 */

require_once __DIR__ . '/bootstrap.php';

if (isLoggedIn()) {
    redirect('dashboard.php');
}

$errors = [];
$departments = dbFetchAll("SELECT id, name FROM departments WHERE is_active = 1 ORDER BY name");

if (isPost()) {
    verifyCsrf();
    $name = post('name');
    $email = post('email');
    $phone = post('phone');
    $password = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';
    $department_id = post('department_id');
    
    // Validation
    if (empty($name)) $errors[] = 'Το όνομα είναι υποχρεωτικό.';
    if (empty($email)) $errors[] = 'Το email είναι υποχρεωτικό.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Το email δεν είναι έγκυρο.';
    if (empty($password)) $errors[] = 'Ο κωδικός είναι υποχρεωτικός.';
    if (strlen($password) < 6) $errors[] = 'Ο κωδικός πρέπει να έχει τουλάχιστον 6 χαρακτήρες.';
    if ($password !== $password_confirm) $errors[] = 'Οι κωδικοί δεν ταιριάζουν.';
    
    if (empty($errors)) {
        $result = registerUser([
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'password' => $password,
            'department_id' => $department_id ?: null
        ]);
        
        if ($result['success']) {
            setFlash('success', 'Η εγγραφή ολοκληρώθηκε! Μπορείτε τώρα να συνδεθείτε.');
            redirect('login.php');
        } else {
            $errors[] = $result['message'];
        }
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
    <title>Εγγραφή - <?= h($appName) ?></title>
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
        .register-card {
            background: white;
            border-radius: 1rem;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            max-width: 450px;
            width: 100%;
        }
        .register-header {
            background: linear-gradient(135deg, #2c3e50 0%, #1a252f 100%);
            color: white;
            padding: 2rem;
            text-align: center;
            border-radius: 1rem 1rem 0 0;
        }
        .register-body {
            padding: 2rem;
        }
        .btn-register {
            background: linear-gradient(135deg, #27ae60 0%, #1e8449 100%);
            border: none;
            padding: 0.75rem;
            font-weight: 500;
        }
        .btn-register:hover {
            background: linear-gradient(135deg, #1e8449 0%, #196f3d 100%);
        }
    </style>
</head>
<body>
    <div class="register-card">
        <div class="register-header">
            <h2><i class="bi bi-person-plus me-2"></i>Εγγραφή</h2>
            <p class="mb-0 opacity-75">Γίνετε μέλος της ομάδας εθελοντών</p>
        </div>
        <div class="register-body">
            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <ul class="mb-0">
                        <?php foreach ($errors as $error): ?>
                            <li><?= h($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            
            <form method="post" action="">
                <?= csrfField() ?>
                <div class="mb-3">
                    <label for="name" class="form-label">Ονοματεπώνυμο *</label>
                    <input type="text" class="form-control" id="name" name="name" 
                           value="<?= h(post('name')) ?>" required>
                </div>
                
                <div class="mb-3">
                    <label for="email" class="form-label">Email *</label>
                    <input type="email" class="form-control" id="email" name="email" 
                           value="<?= h(post('email')) ?>" required>
                </div>
                
                <div class="mb-3">
                    <label for="phone" class="form-label">Τηλέφωνο</label>
                    <input type="tel" class="form-control" id="phone" name="phone" 
                           value="<?= h(post('phone')) ?>">
                </div>
                
                <div class="mb-3">
                    <label for="department_id" class="form-label">Τμήμα</label>
                    <select class="form-select" id="department_id" name="department_id">
                        <option value="">-- Επιλέξτε τμήμα --</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?= $dept['id'] ?>" <?= post('department_id') == $dept['id'] ? 'selected' : '' ?>>
                                <?= h($dept['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="mb-3">
                    <label for="password" class="form-label">Κωδικός *</label>
                    <input type="password" class="form-control" id="password" name="password" 
                           minlength="6" required>
                    <div class="form-text">Τουλάχιστον 6 χαρακτήρες</div>
                </div>
                
                <div class="mb-4">
                    <label for="password_confirm" class="form-label">Επιβεβαίωση Κωδικού *</label>
                    <input type="password" class="form-control" id="password_confirm" name="password_confirm" required>
                </div>
                
                <button type="submit" class="btn btn-success btn-register w-100">
                    <i class="bi bi-person-plus me-2"></i>Εγγραφή
                </button>
            </form>
            
            <hr class="my-4">
            
            <p class="text-center text-muted mb-0">
                Έχετε ήδη λογαριασμό; <a href="login.php">Συνδεθείτε</a>
            </p>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
