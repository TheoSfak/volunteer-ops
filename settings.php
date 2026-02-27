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
    'shift_reminder_hours' => '24',
    'resend_mission_hours_before' => '48',
    'resend_mission_enabled' => '1',
    'smtp_host' => '',
    'smtp_port' => '587',
    'smtp_username' => '',
    'smtp_password' => '',
    'smtp_encryption' => 'tls',
    'smtp_from_email' => '',
    'smtp_from_name' => 'VolunteerOps',
    'inventory_overdue_days' => '3',
    'inventory_default_warehouse' => '',
    'inventory_require_location' => '0',
    'inventory_require_notes' => '0',
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
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            $maxSize = 2 * 1024 * 1024; // 2MB

            // Detect MIME from actual file content, not browser-supplied header
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $detectedMime = $finfo->file($file['tmp_name']);
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

            if (!in_array($detectedMime, $allowedTypes) || !in_array($ext, $allowedExtensions)) {
                setFlash('error', 'Μη αποδεκτός τύπος αρχείου. Επιτρέπονται: JPG, PNG, GIF, WebP.');
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
            
            // Generate unique filename (use already-validated extension)
            $newFilename = 'logo_' . time() . '.' . $ext;
            
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
            'registration_enabled', 'require_approval', 'maintenance_mode',
            'shift_reminder_hours', 'resend_mission_hours_before', 'resend_mission_enabled'
        ];
        
        foreach ($fieldsToUpdate as $field) {
            $value = isset($_POST[$field]) ? $_POST[$field] : '';
            
            if (in_array($field, ['registration_enabled', 'require_approval', 'maintenance_mode', 'resend_mission_enabled'])) {
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
        
        // Clear settings cache after update
        clearSettingsCache();
        
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
        
        // Clear settings cache after update
        clearSettingsCache();
        
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
        
    } elseif ($action === 'save_inventory') {
        // Save inventory settings
        $invFields = ['inventory_overdue_days', 'inventory_default_warehouse', 'inventory_require_location', 'inventory_require_notes'];
        
        foreach ($invFields as $field) {
            $value = isset($_POST[$field]) ? $_POST[$field] : '';
            
            if (in_array($field, ['inventory_require_location', 'inventory_require_notes'])) {
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
        
        clearSettingsCache();
        logAudit('update_settings', 'settings', null, 'Ρυθμίσεις Αποθέματος');
        setFlash('success', 'Οι ρυθμίσεις αποθέματος αποθηκεύτηκαν.');
        redirect('settings.php?tab=inventory');
        
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
        
        // Clear settings cache after notification update
        clearSettingsCache();
        
        logAudit('update_settings', 'notification_settings', null, 'Ρυθμίσεις ειδοποιήσεων');
        setFlash('success', 'Οι ρυθμίσεις ειδοποιήσεων αποθηκεύτηκαν.');
        redirect('settings.php?tab=notifications');

    } elseif ($action === 'run_cron') {
        $cronJob = post('cron_job', 'all');
        
        // Capture output from cron scripts
        ob_start();
        $startTime = microtime(true);
        $results = [];
        
        $cronJobs = [
            'task_reminders'      => ['file' => 'cron_task_reminders.php',      'label' => 'Υπενθυμίσεις Εργασιών'],
            'shift_reminders'     => ['file' => 'cron_shift_reminders.php',     'label' => 'Υπενθυμίσεις Βαρδιών'],
            'incomplete_missions' => ['file' => 'cron_incomplete_missions.php', 'label' => 'Ελλιπείς Αποστολές'],
            'certificate_expiry'  => ['file' => 'cron_certificate_expiry.php',  'label' => 'Λήξη Πιστοποιητικών'],
            'shelf_expiry'        => ['file' => 'cron_shelf_expiry.php',        'label' => 'Λήξη Υλικών Ραφιού'],
        ];
        
        $jobsToRun = ($cronJob === 'all') ? array_keys($cronJobs) : [$cronJob];
        
        foreach ($jobsToRun as $jobKey) {
            if (!isset($cronJobs[$jobKey])) continue;
            $job = $cronJobs[$jobKey];
            $file = __DIR__ . '/' . $job['file'];
            if (!file_exists($file)) {
                $results[$jobKey] = ['label' => $job['label'], 'status' => 'error', 'output' => 'Αρχείο δεν βρέθηκε: ' . $job['file']];
                continue;
            }
            ob_start();
            try {
                include $file;
                $output = ob_get_clean();
                $results[$jobKey] = ['label' => $job['label'], 'status' => 'success', 'output' => $output];
            } catch (Exception $e) {
                $output = ob_get_clean();
                $results[$jobKey] = ['label' => $job['label'], 'status' => 'error', 'output' => $output . ' Error: ' . $e->getMessage()];
            }
        }
        
        $elapsed = round(microtime(true) - $startTime, 2);
        ob_end_clean();
        
        // Save last run timestamp
        $lastRunKey = 'cron_last_manual_run';
        $exists = dbFetchValue("SELECT COUNT(*) FROM settings WHERE setting_key = ?", [$lastRunKey]);
        $lastRunValue = date('Y-m-d H:i:s');
        if ($exists) {
            dbExecute("UPDATE settings SET setting_value = ?, updated_at = NOW() WHERE setting_key = ?", [$lastRunValue, $lastRunKey]);
        } else {
            dbInsert("INSERT INTO settings (setting_key, setting_value, created_at, updated_at) VALUES (?, ?, NOW(), NOW())", [$lastRunKey, $lastRunValue]);
        }
        
        // Store results in session for display
        $_SESSION['cron_results'] = $results;
        $_SESSION['cron_elapsed'] = $elapsed;
        
        $jobLabel = ($cronJob === 'all') ? 'Όλες οι εργασίες' : ($cronJobs[$cronJob]['label'] ?? $cronJob);
        logAudit('run_cron', 'system', null, 'Χειροκίνητη εκτέλεση: ' . $jobLabel);
        setFlash('success', "Η εκτέλεση ολοκληρώθηκε σε {$elapsed}s.");
        redirect('settings.php?tab=cron');
        
    } elseif ($action === 'reset_data') {
        $confirmation = post('confirmation', '');
        if ($confirmation !== 'DELETE') {
            setFlash('error', 'Πρέπει να πληκτρολογήσετε DELETE για επιβεβαίωση.');
            redirect('settings.php?tab=reset');
        }

        try {
            db()->beginTransaction();

            // Mission activity
            dbExecute("DELETE FROM mission_chat_messages");
            dbExecute("DELETE FROM mission_debriefs");
            dbExecute("DELETE FROM participation_requests");
            dbExecute("DELETE FROM shifts");
            dbExecute("UPDATE missions SET deleted_at = NOW() WHERE deleted_at IS NULL");
            dbExecute("DELETE FROM missions");

            // Points & badges
            dbExecute("DELETE FROM volunteer_points");
            dbExecute("DELETE FROM user_achievements");
            dbExecute("UPDATE users SET total_points = 0, monthly_points = 0");

            // Exam / quiz history (keep definitions & questions pool)
            dbExecute("DELETE FROM user_answers");
            dbExecute("DELETE FROM exam_attempts");
            dbExecute("DELETE FROM quiz_attempts");
            dbExecute("DELETE FROM training_user_progress");

            // Notifications & audit trail
            dbExecute("DELETE FROM notifications");
            dbExecute("DELETE FROM audit_logs");

            db()->commit();

            logAudit('reset_data', 'system', null, 'Επαναφορά δεδομένων — πλήρης καθαρισμός');
            setFlash('success', 'Η επαναφορά δεδομένων ολοκληρώθηκε επιτυχώς. Το σύστημα είναι έτοιμο.');
            redirect('settings.php?tab=reset');
        } catch (Exception $e) {
            db()->rollBack();
            setFlash('error', 'Σφάλμα κατά την επαναφορά: ' . h($e->getMessage()));
            redirect('settings.php?tab=reset');
        }
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
        <a class="nav-link <?= $activeTab === 'inventory' ? 'active' : '' ?>" href="settings.php?tab=inventory">
            <i class="bi bi-box-seam me-1"></i>Απόθεμα
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'cron' ? 'active' : '' ?>" href="settings.php?tab=cron">
            <i class="bi bi-clock-history me-1"></i>Cron Jobs
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link" href="update.php">
            <i class="bi bi-cloud-download me-1"></i>Ενημερώσεις
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $activeTab === 'reset' ? 'active' : '' ?> text-danger fw-semibold" href="settings.php?tab=reset">
            <i class="bi bi-trash3 me-1"></i>Επαναφορά
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
            
            <!-- Notification Settings -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-bell me-1"></i>Ρυθμίσεις Ειδοποιήσεων</h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label for="shift_reminder_hours" class="form-label">Υπενθύμιση Βάρδιας (ώρες πριν)</label>
                        <input type="number" class="form-control" id="shift_reminder_hours" name="shift_reminder_hours" 
                               value="<?= h($settings['shift_reminder_hours']) ?>" min="1" max="168" required>
                        <small class="text-muted">Πόσες ώρες πριν τη βάρδια να στέλνεται υπενθύμιση (προεπιλογή: 24)</small>
                    </div>
                    
                    <hr>
                    
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="resend_mission_enabled" id="resendMission"
                               <?= $settings['resend_mission_enabled'] === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="resendMission">
                            <strong>Ξαναστείλε Αποστολή αν δεν έχει συμπληρωθεί</strong>
                        </label>
                    </div>
                    
                    <div class="mb-3">
                        <label for="resend_mission_hours_before" class="form-label">Ξαναστείλε Αποστολή (ώρες πριν)</label>
                        <input type="number" class="form-control" id="resend_mission_hours_before" name="resend_mission_hours_before" 
                               value="<?= h($settings['resend_mission_hours_before']) ?>" min="1" max="720" required>
                        <small class="text-muted">Αν μια βάρδια δεν έχει συμπληρωθεί, στείλε email προς όλους τους χρήστες Χ ώρες πριν (προεπιλογή: 48)</small>
                    </div>
                    
                    <hr>
                    <h6 class="text-muted"><i class="bi bi-terminal me-1"></i>Cron Jobs (Linux)</h6>
                    <p class="small text-muted mb-2">Προσθέστε τις παρακάτω εντολές στο crontab (<code>crontab -e</code>). Αλλάξτε το path ανάλογα με τον server σας:</p>
                    <div class="bg-dark text-light p-3 rounded small" style="font-family: monospace; white-space: pre-wrap;">
# Καθημερινές εργασίες (08:00)
0 8 * * * /usr/bin/php /home/USERNAME/public_html/volunteerops/cron_daily.php

# Υπενθυμίσεις βαρδιών (κάθε 6 ώρες)
0 */6 * * * /usr/bin/php /home/USERNAME/public_html/volunteerops/cron_shift_reminders.php

# Αποστολές χωρίς εθελοντές (09:00)
0 9 * * * /usr/bin/php /home/USERNAME/public_html/volunteerops/cron_incomplete_missions.php

# Υπενθυμίσεις εργασιών (κάθε 6 ώρες)
0 */6 * * * /usr/bin/php /home/USERNAME/public_html/volunteerops/cron_task_reminders.php</div>
                    <small class="text-muted mt-2 d-block">
                        <i class="bi bi-info-circle me-1"></i>Αντικαταστήστε <code>USERNAME</code> με το username του hosting σας και 
                        <code>/home/USERNAME/public_html/volunteerops/</code> με το πλήρες path εγκατάστασης.
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

<!-- Inventory Settings Tab -->
<?php if ($activeTab === 'inventory'): ?>
<?php
$warehouses = dbFetchAll("SELECT id, name FROM departments WHERE has_inventory = 1 AND is_active = 1 ORDER BY name");
$invLocations = dbFetchAll("SELECT l.*, d.name AS warehouse_name FROM inventory_locations l LEFT JOIN departments d ON l.department_id = d.id WHERE l.is_active = 1 ORDER BY l.name");
$invStats = [
    'total_items' => (int)dbFetchValue("SELECT COUNT(*) FROM inventory_items WHERE is_active = 1"),
    'booked' => (int)dbFetchValue("SELECT COUNT(*) FROM inventory_items WHERE status = 'booked' AND is_active = 1"),
    'locations' => count($invLocations),
    'warehouses' => count($warehouses),
];
?>
<div class="row">
    <div class="col-lg-7">
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save_inventory">
            
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-box-seam me-1"></i>Ρυθμίσεις Αποθέματος</h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">Ημέρες μέχρι εκπρόθεσμο (κόκκινο)</label>
                        <input type="number" class="form-control" name="inventory_overdue_days" 
                               value="<?= h($settings['inventory_overdue_days']) ?>" min="1" max="365">
                        <small class="text-muted">Μετά από πόσες ημέρες χωρίς επιστροφή εμφανίζεται ως εκπρόθεσμο (προεπιλογή: 3). Εφαρμόζεται όταν δεν έχει οριστεί ημερομηνία επιστροφής.</small>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Προεπιλεγμένη Αποθήκη</label>
                        <select class="form-select" name="inventory_default_warehouse">
                            <option value="">— Καμία —</option>
                            <?php foreach ($warehouses as $wh): ?>
                                <option value="<?= $wh['id'] ?>" <?= $settings['inventory_default_warehouse'] == $wh['id'] ? 'selected' : '' ?>>
                                    <?= h($wh['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Προεπιλεγμένη αποθήκη για νέα υλικά.</small>
                    </div>
                    
                    <hr>
                    
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="inventory_require_location" id="invReqLoc"
                               <?= $settings['inventory_require_location'] === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="invReqLoc">
                            Υποχρεωτική τοποθεσία στη φόρμα υλικού
                        </label>
                        <small class="text-muted d-block">Αν είναι ενεργό, η τοποθεσία θα είναι υποχρεωτική κατά τη δημιουργία/επεξεργασία υλικού.</small>
                    </div>
                    
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="inventory_require_notes" id="invReqNotes"
                               <?= $settings['inventory_require_notes'] === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="invReqNotes">
                            Υποχρεωτικές σημειώσεις στη χρέωση
                        </label>
                        <small class="text-muted d-block">Αν είναι ενεργό, οι σημειώσεις θα είναι υποχρεωτικές κατά τη χρέωση υλικού.</small>
                    </div>
                </div>
                <div class="card-footer">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-1"></i>Αποθήκευση
                    </button>
                </div>
            </div>
        </form>
    </div>
    
    <div class="col-lg-5">
        <!-- Inventory Stats -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-bar-chart me-1"></i>Στατιστικά Αποθέματος</h5>
            </div>
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <tr>
                        <td>Σύνολο Υλικών</td>
                        <td class="text-end"><strong><?= $invStats['total_items'] ?></strong></td>
                    </tr>
                    <tr>
                        <td>Χρεωμένα</td>
                        <td class="text-end"><strong class="text-warning"><?= $invStats['booked'] ?></strong></td>
                    </tr>
                    <tr>
                        <td>Αποθήκες</td>
                        <td class="text-end"><strong><?= $invStats['warehouses'] ?></strong></td>
                    </tr>
                    <tr>
                        <td>Τοποθεσίες</td>
                        <td class="text-end"><strong><?= $invStats['locations'] ?></strong></td>
                    </tr>
                </table>
            </div>
        </div>
        
        <!-- Quick Links -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-link-45deg me-1"></i>Γρήγορη Διαχείριση</h5>
            </div>
            <div class="card-body d-grid gap-2">
                <a href="inventory-warehouses.php" class="btn btn-outline-primary">
                    <i class="bi bi-building me-1"></i>Διαχείριση Αποθηκών
                </a>
                <a href="inventory-categories.php" class="btn btn-outline-primary">
                    <i class="bi bi-tags me-1"></i>Κατηγορίες Υλικών
                </a>
                <a href="inventory-notes.php" class="btn btn-outline-primary">
                    <i class="bi bi-sticky me-1"></i>Σημειώσεις / Ελλείψεις
                </a>
                <a href="inventory.php" class="btn btn-outline-secondary">
                    <i class="bi bi-box-seam me-1"></i>Όλα τα Υλικά
                </a>
            </div>
        </div>
        
        <!-- Locations List -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-geo-alt me-1"></i>Τοποθεσίες (<?= count($invLocations) ?>)</h5>
            </div>
            <div class="card-body p-0">
                <?php if (empty($invLocations)): ?>
                    <p class="text-muted text-center py-3">Δεν υπάρχουν τοποθεσίες.</p>
                <?php else: ?>
                    <div class="list-group list-group-flush" style="max-height: 300px; overflow-y: auto;">
                        <?php foreach ($invLocations as $loc): ?>
                            <div class="list-group-item d-flex justify-content-between align-items-center py-2">
                                <div>
                                    <strong><?= h($loc['name']) ?></strong>
                                    <?php if ($loc['warehouse_name']): ?>
                                        <br><small class="text-muted"><?= h($loc['warehouse_name']) ?></small>
                                    <?php endif; ?>
                                </div>
                                <span class="badge bg-secondary"><?= h($loc['location_type']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($activeTab === 'cron'): ?>
<?php
$cronJobs = [
    'task_reminders'      => ['label' => 'Υπενθυμίσεις Εργασιών',   'icon' => 'bi-list-task',          'desc' => 'Ειδοποιεί τους εθελοντές για εργασίες με προθεσμία εντός 24 ωρών.', 'color' => 'primary'],
    'shift_reminders'     => ['label' => 'Υπενθυμίσεις Βαρδιών',    'icon' => 'bi-alarm',              'desc' => 'Ειδοποιεί τους εγκεκριμένους εθελοντές για βάρδιες εντός ' . h($settings['shift_reminder_hours'] ?? '24') . ' ωρών.', 'color' => 'info'],
    'incomplete_missions' => ['label' => 'Ελλιπείς Αποστολές',      'icon' => 'bi-people',             'desc' => 'Ειδοποιεί εθελοντές για αποστολές που χρειάζονται ακόμα εθελοντές (εντός ' . h($settings['resend_mission_hours_before'] ?? '48') . ' ωρών).', 'color' => 'warning'],
    'certificate_expiry'  => ['label' => 'Λήξη Πιστοποιητικών',     'icon' => 'bi-award',              'desc' => 'Στέλνει υπενθυμίσεις 30 & 7 ημερών πριν τη λήξη πιστοποιητικών.', 'color' => 'success'],
    'shelf_expiry'        => ['label' => 'Λήξη Υλικών Ραφιού',      'icon' => 'bi-box-seam',           'desc' => 'Ελέγχει για ληγμένα ή υπό λήξη υλικά ραφιού (εντός ' . h($settings['shelf_expiry_reminder_days'] ?? '30') . ' ημερών).', 'color' => 'danger'],
];
$lastManualRun = getSetting('cron_last_manual_run', '');
$cronResults = $_SESSION['cron_results'] ?? null;
$cronElapsed = $_SESSION['cron_elapsed'] ?? null;
unset($_SESSION['cron_results'], $_SESSION['cron_elapsed']);
?>
<div class="row">
    <div class="col-lg-8">
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-clock-history me-2"></i>Χειροκίνητη Εκτέλεση Cron Jobs</h5>
                <form method="post" class="d-inline">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="run_cron">
                    <input type="hidden" name="cron_job" value="all">
                    <button type="submit" class="btn btn-primary" onclick="return confirm('Εκτέλεση όλων των cron jobs;')">
                        <i class="bi bi-play-fill me-1"></i>Εκτέλεση Όλων
                    </button>
                </form>
            </div>
            <div class="card-body">
                <p class="text-muted mb-3">
                    <i class="bi bi-info-circle me-1"></i>
                    Οι παρακάτω εργασίες εκτελούνται αυτόματα καθημερινά μέσω του <code>cron_daily.php</code>.
                    Μπορείτε να τις εκτελέσετε χειροκίνητα ανά πάσα στιγμή.
                </p>

                <?php if ($lastManualRun): ?>
                <div class="alert alert-light py-2 mb-3">
                    <i class="bi bi-clock me-1"></i>
                    <strong>Τελευταία χειροκίνητη εκτέλεση:</strong> <?= h(formatDateTime($lastManualRun)) ?>
                    <?php if ($cronElapsed): ?>
                        <span class="text-muted">(<?= h($cronElapsed) ?>s)</span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ($cronResults): ?>
                <div class="mb-4">
                    <h6><i class="bi bi-terminal me-1"></i>Αποτελέσματα Τελευταίας Εκτέλεσης:</h6>
                    <?php foreach ($cronResults as $key => $result): ?>
                    <div class="card mb-2 border-<?= $result['status'] === 'success' ? 'success' : 'danger' ?>">
                        <div class="card-header py-2 bg-<?= $result['status'] === 'success' ? 'success' : 'danger' ?> bg-opacity-10">
                            <i class="bi <?= $result['status'] === 'success' ? 'bi-check-circle text-success' : 'bi-x-circle text-danger' ?> me-1"></i>
                            <strong><?= h($result['label']) ?></strong>
                        </div>
                        <div class="card-body py-2">
                            <pre class="mb-0" style="font-size: 12px; white-space: pre-wrap;"><?= h(trim($result['output'])) ?></pre>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Εργασία</th>
                                <th>Περιγραφή</th>
                                <th class="text-end" style="width: 140px;">Ενέργεια</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($cronJobs as $key => $job): ?>
                            <tr>
                                <td>
                                    <span class="badge bg-<?= $job['color'] ?> me-2"><i class="bi <?= $job['icon'] ?>"></i></span>
                                    <strong><?= h($job['label']) ?></strong>
                                </td>
                                <td class="text-muted small"><?= $job['desc'] ?></td>
                                <td class="text-end">
                                    <form method="post" class="d-inline">
                                        <?= csrfField() ?>
                                        <input type="hidden" name="action" value="run_cron">
                                        <input type="hidden" name="cron_job" value="<?= h($key) ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-<?= $job['color'] ?>">
                                            <i class="bi bi-play-fill me-1"></i>Εκτέλεση
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-info-circle me-2"></i>Πληροφορίες</h5>
            </div>
            <div class="card-body">
                <h6>Αυτόματη εκτέλεση</h6>
                <p class="small text-muted">
                    Για καθημερινή αυτόματη εκτέλεση, ρυθμίστε στο Windows Task Scheduler:
                </p>
                <div class="bg-dark text-light p-2 rounded mb-3" style="font-size: 12px;">
                    <code class="text-light">php C:\xampp\htdocs\volunteerops\cron_daily.php</code>
                </div>

                <h6>Σχετικές ρυθμίσεις</h6>
                <ul class="list-unstyled small">
                    <li class="mb-1">
                        <i class="bi bi-gear me-1"></i>
                        <strong>Ώρες υπενθύμισης βάρδιας:</strong> <?= h($settings['shift_reminder_hours'] ?? '24') ?>h
                        <a href="settings.php?tab=general" class="ms-1"><i class="bi bi-pencil-square"></i></a>
                    </li>
                    <li class="mb-1">
                        <i class="bi bi-gear me-1"></i>
                        <strong>Ώρες πριν αποστολές:</strong> <?= h($settings['resend_mission_hours_before'] ?? '48') ?>h
                        <a href="settings.php?tab=general" class="ms-1"><i class="bi bi-pencil-square"></i></a>
                    </li>
                    <li class="mb-1">
                        <i class="bi bi-gear me-1"></i>
                        <strong>Λήξη ραφιού (ημέρες):</strong> <?= h($settings['shelf_expiry_reminder_days'] ?? '30') ?>
                    </li>
                    <li class="mb-1">
                        <i class="bi bi-gear me-1"></i>
                        <strong>Αποστολές ενεργές:</strong>
                        <?= ($settings['resend_mission_enabled'] ?? '1') === '1' ? '<span class="text-success">Ναι</span>' : '<span class="text-danger">Όχι</span>' ?>
                    </li>
                </ul>

                <h6 class="mt-3">Ειδοποιήσεις Email</h6>
                <p class="small text-muted mb-2">Μπορείτε να ενεργοποιήσετε/απενεργοποιήσετε τα email από την καρτέλα <a href="settings.php?tab=notifications">Ειδοποιήσεις</a>.</p>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($activeTab === 'reset'): ?>
<div class="row justify-content-center">
    <div class="col-lg-7">
        <div class="card border-danger">
            <div class="card-header bg-danger text-white">
                <h5 class="mb-0"><i class="bi bi-exclamation-triangle-fill me-2"></i>Επαναφορά Δεδομένων Συστήματος</h5>
            </div>
            <div class="card-body">
                <div class="alert alert-warning">
                    <strong><i class="bi bi-exclamation-triangle me-1"></i>Προσοχή — Μη αναστρέψιμη ενέργεια!</strong>
                    <p class="mb-0 mt-1">Αυτή η ενέργεια θα διαγράψει μόνιμα τα παρακάτω δεδομένα. Οι χρήστες, τα εκπαιδευτικά αρχεία και η τράπεζα ερωτήσεων <strong>δεν</strong> επηρεάζονται.</p>
                </div>

                <h6 class="text-danger mt-3 mb-2"><i class="bi bi-trash3 me-1"></i>Τι θα διαγραφεί:</h6>
                <div class="row">
                    <div class="col-md-6">
                        <ul class="list-group list-group-flush mb-3">
                            <li class="list-group-item py-1"><i class="bi bi-x-circle-fill text-danger me-2"></i>Όλες οι αποστολές &amp; βάρδιες</li>
                            <li class="list-group-item py-1"><i class="bi bi-x-circle-fill text-danger me-2"></i>Αιτήσεις συμμετοχής &amp; παρουσίες</li>
                            <li class="list-group-item py-1"><i class="bi bi-x-circle-fill text-danger me-2"></i>Σχόλια αποστολών &amp; απολογισμοί</li>
                            <li class="list-group-item py-1"><i class="bi bi-x-circle-fill text-danger me-2"></i>Πόντοι &amp; ιστορικό πόντων</li>
                            <li class="list-group-item py-1"><i class="bi bi-x-circle-fill text-danger me-2"></i>Badges εθελοντών</li>
                            <li class="list-group-item py-1"><i class="bi bi-x-circle-fill text-danger me-2"></i>Σύνολο πόντων (→ 0)</li>
                        </ul>
                    </div>
                    <div class="col-md-6">
                        <ul class="list-group list-group-flush mb-3">
                            <li class="list-group-item py-1"><i class="bi bi-x-circle-fill text-danger me-2"></i>Ιστορικό quiz &amp; εξετάσεων</li>
                            <li class="list-group-item py-1"><i class="bi bi-x-circle-fill text-danger me-2"></i>Απαντήσεις χρηστών</li>
                            <li class="list-group-item py-1"><i class="bi bi-x-circle-fill text-danger me-2"></i>Πρόοδος εκπαιδευτικού υλικού</li>
                            <li class="list-group-item py-1"><i class="bi bi-x-circle-fill text-danger me-2"></i>Ειδοποιήσεις</li>
                            <li class="list-group-item py-1"><i class="bi bi-x-circle-fill text-danger me-2"></i>Αρχείο ενεργειών (audit log)</li>
                        </ul>
                    </div>
                </div>

                <h6 class="text-success mt-2 mb-2"><i class="bi bi-shield-check me-1"></i>Τι διατηρείται:</h6>
                <ul class="list-group list-group-flush mb-4">
                    <li class="list-group-item py-1 text-success"><i class="bi bi-check-circle-fill me-2"></i>Όλοι οι χρήστες &amp; λογαριασμοί</li>
                    <li class="list-group-item py-1 text-success"><i class="bi bi-check-circle-fill me-2"></i>Εκπαιδευτικά αρχεία &amp; κατηγορίες</li>
                    <li class="list-group-item py-1 text-success"><i class="bi bi-check-circle-fill me-2"></i>Τράπεζα ερωτήσεων &amp; ορισμοί quiz/εξετάσεων</li>
                    <li class="list-group-item py-1 text-success"><i class="bi bi-check-circle-fill me-2"></i>Τμήματα, παραρτήματα, δεξιότητες</li>
                    <li class="list-group-item py-1 text-success"><i class="bi bi-check-circle-fill me-2"></i>Πιστοποιητικά χρηστών</li>
                    <li class="list-group-item py-1 text-success"><i class="bi bi-check-circle-fill me-2"></i>Ορισμοί Badges &amp; ρυθμίσεις συστήματος</li>
                </ul>

                <form method="post" id="resetForm" onsubmit="return confirmReset()">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="reset_data">
                    <div class="mb-3">
                        <label class="form-label fw-semibold text-danger">Για επιβεβαίωση, πληκτρολογήστε <code>DELETE</code>:</label>
                        <input type="text" class="form-control border-danger" name="confirmation"
                               id="resetConfirmInput" autocomplete="off"
                               placeholder="Πληκτρολογήστε DELETE">
                    </div>
                    <button type="submit" class="btn btn-danger" id="resetBtn" disabled>
                        <i class="bi bi-trash3-fill me-1"></i>Εκτέλεση Επαναφοράς
                    </button>
                    <a href="settings.php?tab=general" class="btn btn-secondary ms-2">Ακύρωση</a>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('resetConfirmInput').addEventListener('input', function() {
    document.getElementById('resetBtn').disabled = (this.value !== 'DELETE');
});
function confirmReset() {
    return confirm('ΤΕΛΕΥΤΑΙΑ ΕΠΙΒΕΒΑΙΩΣΗ: Είστε απολύτως σίγουροι; Αυτή η ενέργεια είναι ΜΗ ΑΝΑΣΤΡΕΨΙΜΗ.');
}
</script>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
