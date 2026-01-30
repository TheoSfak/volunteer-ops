<?php
/**
 * VolunteerOps - Settings (SMTP, Email Templates, Notifications)
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();
requireRole([ROLE_SYSTEM_ADMIN]);

$pageTitle = 'Ρυθμίσεις';

// Get active tab
$activeTab = get('tab', 'general');

// Get current settings
$settings = [];
$rows = dbFetchAll("SELECT setting_key, setting_value FROM settings");
foreach ($rows as $row) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Defaults
$defaults = [
    'app_name' => 'VolunteerOps',
    'app_description' => 'Σύστημα Διαχείρισης Εθελοντών',
    'app_logo' => '',
    'admin_email' => '',
    'timezone' => 'Europe/Athens',
    'date_format' => 'd/m/Y',
    'points_per_hour' => '10',
    'weekend_multiplier' => '1.5',
    'night_multiplier' => '1.5',
    'medical_multiplier' => '2.0',
    'registration_enabled' => '1',
    'require_approval' => '0',
    'maintenance_mode' => '0',
    'smtp_host' => '',
    'smtp_port' => '587',
    'smtp_username' => '',
    'smtp_password' => '',
    'smtp_encryption' => 'tls',
    'smtp_from_email' => '',
    'smtp_from_name' => 'VolunteerOps',
];

foreach ($defaults as $key => $value) {
    if (!isset($settings[$key])) {
        $settings[$key] = $value;
    }
}

// Get notification settings
$notificationSettings = dbFetchAll("SELECT * FROM notification_settings ORDER BY name");

$testEmailResult = null;

if (isPost()) {
    verifyCsrf();
    $action = post('action', 'save_general');
    
    if ($action === 'save_general') {
        // Handle logo upload
        if (!empty($_FILES['app_logo']['name'])) {
            $file = $_FILES['app_logo'];
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/svg+xml', 'image/webp'];
            $maxSize = 2 * 1024 * 1024; // 2MB
            
            if (!in_array($file['type'], $allowedTypes)) {
                setFlash('error', 'Μη αποδεκτός τύπος αρχείου. Επιτρέπονται: JPG, PNG, GIF, SVG, WebP.');
                redirect('settings.php?tab=general');
            }
            
            if ($file['size'] > $maxSize) {
                setFlash('error', 'Το αρχείο είναι πολύ μεγάλο. Μέγιστο μέγεθος: 2MB.');
                redirect('settings.php?tab=general');
            }
            
            // Create logos directory if it doesn't exist
            $uploadDir = __DIR__ . '/uploads/logos/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            // Delete old logo if exists
            $oldLogo = $settings['app_logo'] ?? '';
            if (!empty($oldLogo) && file_exists($uploadDir . $oldLogo)) {
                unlink($uploadDir . $oldLogo);
            }
            
            // Generate unique filename
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $newFilename = 'logo_' . time() . '.' . strtolower($ext);
            
            if (move_uploaded_file($file['tmp_name'], $uploadDir . $newFilename)) {
                // Save logo setting
                $exists = dbFetchValue("SELECT COUNT(*) FROM settings WHERE setting_key = 'app_logo'");
                if ($exists) {
                    dbExecute("UPDATE settings SET setting_value = ?, updated_at = NOW() WHERE setting_key = 'app_logo'", [$newFilename]);
                } else {
                    dbInsert("INSERT INTO settings (setting_key, setting_value, created_at, updated_at) VALUES ('app_logo', ?, NOW(), NOW())", [$newFilename]);
                }
                $settings['app_logo'] = $newFilename;
            } else {
                setFlash('error', 'Σφάλμα κατά την αποθήκευση του αρχείου.');
                redirect('settings.php?tab=general');
            }
        }
        
        // Handle logo deletion
        if (post('delete_logo') === '1') {
            $uploadDir = __DIR__ . '/uploads/logos/';
            $oldLogo = $settings['app_logo'] ?? '';
            if (!empty($oldLogo) && file_exists($uploadDir . $oldLogo)) {
                unlink($uploadDir . $oldLogo);
            }
            dbExecute("UPDATE settings SET setting_value = '', updated_at = NOW() WHERE setting_key = 'app_logo'");
            $settings['app_logo'] = '';
        }
        
        // Save general settings
        $fieldsToUpdate = [
            'app_name', 'app_description', 'admin_email', 'timezone', 'date_format',
            'points_per_hour', 'weekend_multiplier', 'night_multiplier', 'medical_multiplier',
            'registration_enabled', 'require_approval', 'maintenance_mode'
        ];
        
        foreach ($fieldsToUpdate as $field) {
            $value = isset($_POST[$field]) ? $_POST[$field] : '';
            
            if (in_array($field, ['registration_enabled', 'require_approval', 'maintenance_mode'])) {
                $value = isset($_POST[$field]) ? '1' : '0';
            }
            
            $exists = dbFetchValue("SELECT COUNT(*) FROM settings WHERE setting_key = ?", [$field]);
            
            if ($exists) {
                dbExecute("UPDATE settings SET setting_value = ?, updated_at = NOW() WHERE setting_key = ?", [$value, $field]);
            } else {
                dbInsert("INSERT INTO settings (setting_key, setting_value, created_at, updated_at) VALUES (?, ?, NOW(), NOW())", [$field, $value]);
            }
            
            $settings[$field] = $value;
        }
        
        logAudit('update_settings', 'settings', null, 'Γενικές ρυθμίσεις');
        setFlash('success', 'Οι γενικές ρυθμίσεις αποθηκεύτηκαν.');
        redirect('settings.php?tab=general');
        
    } elseif ($action === 'save_smtp') {
        // Save SMTP settings
        $smtpFields = ['smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_encryption', 'smtp_from_email', 'smtp_from_name'];
        
        foreach ($smtpFields as $field) {
            $value = post($field, '');
            
            // Don't overwrite password if empty
            if ($field === 'smtp_password' && empty($value) && !empty($settings['smtp_password'])) {
                continue;
            }
            
            $exists = dbFetchValue("SELECT COUNT(*) FROM settings WHERE setting_key = ?", [$field]);
            
            if ($exists) {
                dbExecute("UPDATE settings SET setting_value = ?, updated_at = NOW() WHERE setting_key = ?", [$value, $field]);
            } else {
                dbInsert("INSERT INTO settings (setting_key, setting_value, created_at, updated_at) VALUES (?, ?, NOW(), NOW())", [$field, $value]);
            }
            
            $settings[$field] = $value;
        }
        
        logAudit('update_settings', 'settings', null, 'Ρυθμίσεις SMTP');
        setFlash('success', 'Οι ρυθμίσεις SMTP αποθηκεύτηκαν.');
        redirect('settings.php?tab=smtp');
        
    } elseif ($action === 'send_test_email') {
        $testTo = post('test_email', '');
        if (!empty($testTo) && filter_var($testTo, FILTER_VALIDATE_EMAIL)) {
            $testEmailResult = sendTestEmail($testTo);
        } else {
            $testEmailResult = ['success' => false, 'message' => 'Μη έγκυρη διεύθυνση email'];
        }
        $activeTab = 'smtp';
        
    } elseif ($action === 'save_notifications') {
        // Save notification settings
        foreach ($_POST['notifications'] ?? [] as $code => $enabled) {
            dbExecute("UPDATE notification_settings SET email_enabled = ?, updated_at = NOW() WHERE code = ?", 
                [$enabled ? 1 : 0, $code]);
        }
        
        // Handle unchecked checkboxes
        $allCodes = dbFetchAll("SELECT code FROM notification_settings");
        foreach ($allCodes as $row) {
            if (!isset($_POST['notifications'][$row['code']])) {
                dbExecute("UPDATE notification_settings SET email_enabled = 0, updated_at = NOW() WHERE code = ?", 
                    [$row['code']]);
            }
        }
        
        logAudit('update_settings', 'notification_settings', null, 'Ρυθμίσεις ειδοποιήσεων');
        setFlash('success', 'Οι ρυθμίσεις ειδοποιήσεων αποθηκεύτηκαν.');
        redirect('settings.php?tab=notifications');
    }
}

// Refresh notification settings
$notificationSettings = dbFetchAll("SELECT * FROM notification_settings ORDER BY name");

include __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">
        <i class="bi bi-gear me-2"></i>Ρυθμίσεις Συστήματος
    </h1>
</div>

<?= showFlash() ?>

<?php if ($testEmailResult): ?>
    <div class="alert alert-<?= $testEmailResult['success'] ? 'success' : 'danger' ?> alert-dismissible fade show">
        <strong><?= $testEmailResult['success'] ? 'Επιτυχία!' : 'Σφάλμα!' ?></strong>
        <?= h($testEmailResult['message']) ?>
        <?php if (!empty($testEmailResult['log'])): ?>
            <hr>
            <details>
                <summary>Λεπτομέρειες SMTP</summary>
                <pre class="mb-0 mt-2" style="font-size: 12px;"><?php foreach ($testEmailResult['log'] as $line): echo h($line) . "\n"; endforeach; ?></pre>
            </details>
        <?php endif; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Tabs -->
<ul class="nav nav-tabs mb-4" role="tablist">
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'general' ? 'active' : '' ?>" href="settings.php?tab=general">
            <i class="bi bi-sliders me-1"></i>Γενικά
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'smtp' ? 'active' : '' ?>" href="settings.php?tab=smtp">
            <i class="bi bi-envelope me-1"></i>SMTP Email
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'templates' ? 'active' : '' ?>" href="settings.php?tab=templates">
            <i class="bi bi-file-earmark-code me-1"></i>Email Templates
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'notifications' ? 'active' : '' ?>" href="settings.php?tab=notifications">
            <i class="bi bi-bell me-1"></i>Ειδοποιήσεις
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link" href="update.php">
            <i class="bi bi-cloud-download me-1"></i>Ενημερώσεις
        </a>
    </li>
</ul>

<!-- General Settings Tab -->
<?php if ($activeTab === 'general'): ?>
<form method="post" enctype="multipart/form-data">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="save_general">
    
    <div class="row">
        <div class="col-lg-6">
            <!-- General Settings -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-sliders me-1"></i>Γενικές Ρυθμίσεις</h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">Όνομα Εφαρμογής</label>
                        <input type="text" class="form-control" name="app_name" value="<?= h($settings['app_name']) ?>">
                    </div>
                    
                    <!-- Logo Upload -->
                    <div class="mb-3">
                        <label class="form-label">Λογότυπο</label>
                        <?php if (!empty($settings['app_logo']) && file_exists(__DIR__ . '/uploads/logos/' . $settings['app_logo'])): ?>
                            <div class="mb-2 p-3 bg-light rounded d-flex align-items-center">
                                <img src="uploads/logos/<?= h($settings['app_logo']) ?>" alt="Logo" style="max-height: 50px; max-width: 150px;" class="me-3">
                                <div>
                                    <small class="text-muted d-block"><?= h($settings['app_logo']) ?></small>
                                    <label class="form-check-label">
                                        <input type="checkbox" name="delete_logo" value="1" class="form-check-input">
                                        <span class="text-danger">Διαγραφή λογότυπου</span>
                                    </label>
                                </div>
                            </div>
                        <?php endif; ?>
                        <input type="file" class="form-control" name="app_logo" accept="image/*">
                        <small class="text-muted">Μέγιστο: 2MB. Τύποι: JPG, PNG, GIF, SVG, WebP</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Περιγραφή</label>
                        <textarea class="form-control" name="app_description" rows="2"><?= h($settings['app_description']) ?></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email Διαχειριστή</label>
                        <input type="email" class="form-control" name="admin_email" value="<?= h($settings['admin_email']) ?>">
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Ζώνη Ώρας</label>
                            <select class="form-select" name="timezone">
                                <option value="Europe/Athens" <?= $settings['timezone'] === 'Europe/Athens' ? 'selected' : '' ?>>Ελλάδα (Athens)</option>
                                <option value="Europe/London" <?= $settings['timezone'] === 'Europe/London' ? 'selected' : '' ?>>UK (London)</option>
                                <option value="UTC" <?= $settings['timezone'] === 'UTC' ? 'selected' : '' ?>>UTC</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Μορφή Ημ/νίας</label>
                            <select class="form-select" name="date_format">
                                <option value="d/m/Y" <?= $settings['date_format'] === 'd/m/Y' ? 'selected' : '' ?>>31/12/2024</option>
                                <option value="Y-m-d" <?= $settings['date_format'] === 'Y-m-d' ? 'selected' : '' ?>>2024-12-31</option>
                                <option value="d.m.Y" <?= $settings['date_format'] === 'd.m.Y' ? 'selected' : '' ?>>31.12.2024</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Access Settings -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-shield-lock me-1"></i>Πρόσβαση</h5>
                </div>
                <div class="card-body">
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="registration_enabled" id="regEnabled"
                               <?= $settings['registration_enabled'] === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="regEnabled">
                            Επιτρέπεται η εγγραφή νέων χρηστών
                        </label>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="require_approval" id="reqApproval"
                               <?= $settings['require_approval'] === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="reqApproval">
                            Απαιτείται έγκριση νέων λογαριασμών
                        </label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="maintenance_mode" id="maintenance"
                               <?= $settings['maintenance_mode'] === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label text-danger" for="maintenance">
                            <strong>Λειτουργία Συντήρησης</strong> (μόνο διαχειριστές έχουν πρόσβαση)
                        </label>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-lg-6">
            <!-- Points Settings -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-star me-1"></i>Ρυθμίσεις Πόντων</h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">Πόντοι ανά ώρα</label>
                        <input type="number" class="form-control" name="points_per_hour" 
                               value="<?= h($settings['points_per_hour']) ?>" min="1">
                    </div>
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Πολ/στής Σ/Κ</label>
                            <input type="number" step="0.1" class="form-control" name="weekend_multiplier" 
                                   value="<?= h($settings['weekend_multiplier']) ?>" min="1">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Πολ/στής Νυχτ.</label>
                            <input type="number" step="0.1" class="form-control" name="night_multiplier" 
                                   value="<?= h($settings['night_multiplier']) ?>" min="1">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Πολ/στής Ιατρ.</label>
                            <input type="number" step="0.1" class="form-control" name="medical_multiplier" 
                                   value="<?= h($settings['medical_multiplier']) ?>" min="1">
                        </div>
                    </div>
                    <small class="text-muted">
                        Οι πολλαπλασιαστές εφαρμόζονται για βάρδιες Σαββατοκύριακου, νυχτερινές (22:00-06:00), 
                        και ιατρικές αποστολές.
                    </small>
                </div>
            </div>
            
            <!-- System Info -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-info-circle me-1"></i>Πληροφορίες Συστήματος</h5>
                </div>
                <div class="card-body">
                    <table class="table table-sm mb-0">
                        <tr>
                            <td>Έκδοση</td>
                            <td><strong><?= APP_VERSION ?></strong></td>
                        </tr>
                        <tr>
                            <td>PHP</td>
                            <td><?= PHP_VERSION ?></td>
                        </tr>
                        <tr>
                            <td>MySQL</td>
                            <td><?= dbFetchValue("SELECT VERSION()") ?></td>
                        </tr>
                        <tr>
                            <td>Χρήστες</td>
                            <td><?= dbFetchValue("SELECT COUNT(*) FROM users WHERE is_active = 1") ?></td>
                        </tr>
                        <tr>
                            <td>Αποστολές</td>
                            <td><?= dbFetchValue("SELECT COUNT(*) FROM missions WHERE deleted_at IS NULL") ?></td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <div class="card">
        <div class="card-body">
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-check-lg me-1"></i>Αποθήκευση Ρυθμίσεων
            </button>
        </div>
    </div>
</form>
<?php endif; ?>

<!-- SMTP Settings Tab -->
<?php if ($activeTab === 'smtp'): ?>
<div class="row">
    <div class="col-lg-8">
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save_smtp">
            
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-envelope me-1"></i>Ρυθμίσεις SMTP</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-8 mb-3">
                            <label class="form-label">SMTP Host</label>
                            <input type="text" class="form-control" name="smtp_host" 
                                   value="<?= h($settings['smtp_host']) ?>" 
                                   placeholder="π.χ. smtp.gmail.com, smtp.mail.yahoo.com">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Port</label>
                            <input type="number" class="form-control" name="smtp_port" 
                                   value="<?= h($settings['smtp_port']) ?>">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Username</label>
                            <input type="text" class="form-control" name="smtp_username" 
                                   value="<?= h($settings['smtp_username']) ?>"
                                   placeholder="π.χ. your@email.com">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Password</label>
                            <input type="password" class="form-control" name="smtp_password" 
                                   placeholder="<?= !empty($settings['smtp_password']) ? '••••••••' : '' ?>">
                            <small class="text-muted">Αφήστε κενό για να διατηρηθεί ο υπάρχων κωδικός</small>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Κρυπτογράφηση</label>
                        <select class="form-select" name="smtp_encryption">
                            <option value="tls" <?= $settings['smtp_encryption'] === 'tls' ? 'selected' : '' ?>>TLS (Συνιστάται - Port 587)</option>
                            <option value="ssl" <?= $settings['smtp_encryption'] === 'ssl' ? 'selected' : '' ?>>SSL (Port 465)</option>
                            <option value="none" <?= $settings['smtp_encryption'] === 'none' ? 'selected' : '' ?>>Καμία</option>
                        </select>
                    </div>
                    
                    <hr>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Email Αποστολέα</label>
                            <input type="email" class="form-control" name="smtp_from_email" 
                                   value="<?= h($settings['smtp_from_email']) ?>"
                                   placeholder="π.χ. noreply@volunteerops.gr">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Όνομα Αποστολέα</label>
                            <input type="text" class="form-control" name="smtp_from_name" 
                                   value="<?= h($settings['smtp_from_name']) ?>">
                        </div>
                    </div>
                </div>
                <div class="card-footer">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-1"></i>Αποθήκευση
                    </button>
                </div>
            </div>
        </form>
        
        <!-- Test Email -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-send me-1"></i>Δοκιμαστικό Email</h5>
            </div>
            <div class="card-body">
                <form method="post" class="row g-3 align-items-end">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="send_test_email">
                    <div class="col-md-8">
                        <label class="form-label">Email Παραλήπτη</label>
                        <input type="email" class="form-control" name="test_email" 
                               value="<?= h($settings['admin_email'] ?? '') ?>"
                               placeholder="Εισάγετε email για δοκιμή">
                    </div>
                    <div class="col-md-4">
                        <button type="submit" class="btn btn-outline-primary w-100" <?= empty($settings['smtp_host']) ? 'disabled' : '' ?>>
                            <i class="bi bi-envelope-paper me-1"></i>Αποστολή Δοκιμής
                        </button>
                    </div>
                </form>
                <?php if (empty($settings['smtp_host'])): ?>
                    <div class="alert alert-warning mt-3 mb-0">
                        <i class="bi bi-exclamation-triangle me-1"></i>
                        Συμπληρώστε και αποθηκεύστε πρώτα τις ρυθμίσεις SMTP
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-question-circle me-1"></i>Οδηγίες</h5>
            </div>
            <div class="card-body">
                <h6>Gmail</h6>
                <ul class="small">
                    <li>Host: smtp.gmail.com</li>
                    <li>Port: 587 (TLS) ή 465 (SSL)</li>
                    <li>Χρησιμοποιήστε App Password</li>
                </ul>
                
                <h6>Yahoo</h6>
                <ul class="small">
                    <li>Host: smtp.mail.yahoo.com</li>
                    <li>Port: 587 (TLS)</li>
                </ul>
                
                <h6>Outlook/Office 365</h6>
                <ul class="small">
                    <li>Host: smtp.office365.com</li>
                    <li>Port: 587 (TLS)</li>
                </ul>
                
                <div class="alert alert-info mb-0">
                    <i class="bi bi-info-circle me-1"></i>
                    <small>Για Gmail/Yahoo μπορεί να χρειαστεί να ενεργοποιήσετε "App Passwords" ή "Less secure apps"</small>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Email Templates Tab -->
<?php if ($activeTab === 'templates'): ?>
<?php $templates = getEmailTemplates(); ?>
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="bi bi-file-earmark-code me-1"></i>Email Templates</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>Όνομα</th>
                        <th>Θέμα</th>
                        <th>Περιγραφή</th>
                        <th>Κατάσταση</th>
                        <th class="text-end">Ενέργειες</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($templates as $template): ?>
                    <tr>
                        <td><strong><?= h($template['name']) ?></strong></td>
                        <td><code><?= h($template['subject']) ?></code></td>
                        <td class="text-muted small"><?= h($template['description']) ?></td>
                        <td>
                            <?php if ($template['is_active']): ?>
                                <span class="badge bg-success">Ενεργό</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Ανενεργό</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <a href="email-template-edit.php?id=<?= $template['id'] ?>" class="btn btn-sm btn-outline-primary">
                                <i class="bi bi-pencil"></i> Επεξεργασία
                            </a>
                            <button type="button" class="btn btn-sm btn-outline-secondary" 
                                    onclick="previewTemplate(<?= $template['id'] ?>)">
                                <i class="bi bi-eye"></i> Προεπισκόπηση
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Preview Modal -->
<div class="modal fade" id="previewModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Προεπισκόπηση Email</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <iframe id="previewFrame" style="width: 100%; height: 500px; border: none;"></iframe>
            </div>
        </div>
    </div>
</div>

<script>
function previewTemplate(id) {
    document.getElementById('previewFrame').src = 'email-template-preview.php?id=' + id;
    new bootstrap.Modal(document.getElementById('previewModal')).show();
}
</script>
<?php endif; ?>

<!-- Notifications Settings Tab -->
<?php if ($activeTab === 'notifications'): ?>
<form method="post">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="save_notifications">
    
    <div class="card">
        <div class="card-header">
            <h5 class="mb-0"><i class="bi bi-bell me-1"></i>Ρυθμίσεις Ειδοποιήσεων Email</h5>
        </div>
        <div class="card-body">
            <p class="text-muted mb-4">
                Επιλέξτε ποιες ειδοποιήσεις θα στέλνονται μέσω email στους χρήστες.
            </p>
            
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th style="width: 60px;">Email</th>
                            <th>Ειδοποίηση</th>
                            <th>Περιγραφή</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($notificationSettings as $ns): ?>
                        <tr>
                            <td>
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" 
                                           name="notifications[<?= h($ns['code']) ?>]" 
                                           value="1"
                                           <?= $ns['email_enabled'] ? 'checked' : '' ?>
                                           id="notif_<?= h($ns['code']) ?>">
                                </div>
                            </td>
                            <td>
                                <label for="notif_<?= h($ns['code']) ?>" class="mb-0">
                                    <strong><?= h($ns['name']) ?></strong>
                                </label>
                            </td>
                            <td class="text-muted"><?= h($ns['description']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <?php if (!isEmailConfigured()): ?>
            <div class="alert alert-warning">
                <i class="bi bi-exclamation-triangle me-1"></i>
                <strong>Προσοχή:</strong> Δεν έχουν ρυθμιστεί οι παράμετροι SMTP. 
                <a href="settings.php?tab=smtp">Ρυθμίστε τα SMTP settings</a> για να λειτουργήσουν οι ειδοποιήσεις.
            </div>
            <?php endif; ?>
        </div>
        <div class="card-footer">
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-check-lg me-1"></i>Αποθήκευση Ρυθμίσεων
            </button>
        </div>
    </div>
</form>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
