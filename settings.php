<?php
/**
 * VolunteerOps - Settings (SMTP, Email Templates, Notifications)
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/sidebar-theme.php';
requireLogin();
requireRole([ROLE_SYSTEM_ADMIN]);

$pageTitle = 'Ρυθμίσεις';

// Get active tab
// Empty (not 'general') so a bare settings.php lands on the section grid
// rather than dropping straight into one form. Every existing
// ?tab=... link and redirect keeps working untouched.
$activeTab = get('tab', '');

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
    'aithsh_bg_image' => '',
    // Used by mission-report-print.php/inventory-print.php/mission-certificate-print.php
    // for printed-document headers — previously read via getSetting() but never
    // actually in $defaults or the form below, so it silently fell back to the
    // literal string "VolunteerOps" everywhere until now.
    'org_name' => 'VolunteerOps',
    'org_president_name' => '',
    'org_secretary_name' => '',
    'org_contact_phone' => '',
    'org_contact_email' => '',
    'org_contact_address' => '',
    'cert_signature_font_size' => '7',
    'war_room_banner_font_size' => '1.35',
    'war_room_ticker_position' => 'top',
    'war_room_auto_ping_seconds' => '180',
    'war_room_low_battery_pct' => '60',
    'war_room_max_shift_minutes' => '480',
    'war_room_grid_max_size_m' => '900',
    'war_room_grid_max_cells' => '120',
    'war_room_area_unit' => 'auto',
    // Rescuer heart rate (volunteer_vitals). Off by default: it needs a
    // sensor per volunteer and it collects health data, so it must be an
    // explicit decision by the org, never something that starts working
    // because they upgraded. Defaults mirror vitalsConfig() exactly.
    'vitals_enabled' => '0',
    'vitals_sample_seconds' => '5',
    'vitals_elevated_pct' => '75',
    'vitals_critical_pct' => '88',
    'vitals_low_bpm' => '45',
    'vitals_reference_age' => '40',
    'vitals_stale_seconds' => '120',
    'vitals_retention_days' => '365',
    'vitals_episode_tachy_minutes' => '10',
    'vitals_episode_brady_minutes' => '5',
    'vitals_episode_strain_minutes' => '20',
    'admin_email' => '',
    'developer_email' => '',
    'timezone' => 'Europe/Athens',
    'date_format' => 'd/m/Y',
    'points_per_hour' => '10',
    'weekend_multiplier' => '1.5',
    'night_multiplier' => '1.5',
    'medical_multiplier' => '2.0',
    'achievements_enabled' => '1',
    'points_enabled' => '1',
    'registration_enabled' => '1',
    'show_register_button' => '0',
    'require_approval' => '0',
    'maintenance_mode' => '0',
    'session_timeout_minutes' => '120',
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
    'citizen_cert_notify_enabled' => '0',
    'citizen_cert_notify_3months' => '1',
    'citizen_cert_notify_1month' => '1',
    'citizen_cert_notify_1week' => '1',
    'citizen_cert_notify_expired' => '1',
    'subscription_reactivation_days' => '90',
    'subscription_iris_renewal_days' => '90',
    'subscription_iris_annual_amount' => '30',
    'subscription_iris_tax_id' => '996695642',
    'subscription_iris_phone' => '',
    // Prerequisites
    'prereq_attendance_enabled' => '1',
    'prereq_attendance_goal' => '10',
    'prereq_hours_enabled' => '0',
    'prereq_hours_goal' => '0',
    'prereq_mission_types' => '',
    'prereq_tep_attendance_enabled' => '0',
    'prereq_tep_attendance_goal' => '0',
    'prereq_tep_hours_enabled' => '1',
    'prereq_tep_hours_goal' => '40',
    'prereq_tep_mission_types' => '',
    'prereq_edu_attendance_enabled' => '1',
    'prereq_edu_attendance_goal' => '2',
    'prereq_edu_hours_enabled' => '0',
    'prereq_edu_hours_goal' => '0',
    'prereq_edu_mission_types' => '',
];

foreach ($defaults as $key => $value) {
    if (!isset($settings[$key])) {
        $settings[$key] = $value;
    }
}

// Get notification settings
$notificationSettings = dbFetchAll("SELECT * FROM notification_settings ORDER BY name");

$testEmailResult = null;

/**
 * Run all health checks and return structured results
 */
function runHealthChecks() {
    $results = ['checks' => [], 'score' => 0, 'total' => 0, 'passed' => 0];
    
    // ── 1. SYSTEM ENVIRONMENT ──
    $env = [];
    
    // PHP version
    $phpVer = PHP_VERSION;
    $env[] = ['label' => 'PHP Version', 'value' => $phpVer, 
              'status' => version_compare($phpVer, '8.0.0', '>=') ? 'ok' : 'error',
              'detail' => version_compare($phpVer, '8.0.0', '>=') ? '' : 'Απαιτείται PHP ≥ 8.0'];
    
    // PHP Extensions
    $requiredExt = ['pdo_mysql', 'mbstring', 'json', 'fileinfo', 'openssl'];
    $optionalExt = ['gd', 'zip', 'curl'];
    $missingReq = array_filter($requiredExt, fn($e) => !extension_loaded($e));
    $missingOpt = array_filter($optionalExt, fn($e) => !extension_loaded($e));
    $extCount = count($requiredExt) - count($missingReq);
    $env[] = ['label' => 'PHP Extensions (απαιτούμενα)', 'value' => "$extCount/" . count($requiredExt),
              'status' => empty($missingReq) ? 'ok' : 'error',
              'detail' => empty($missingReq) ? 'Όλα εγκατεστημένα' : 'Λείπουν: ' . implode(', ', $missingReq)];
    if (!empty($missingOpt)) {
        $env[] = ['label' => 'PHP Extensions (προαιρετικά)', 'value' => (count($optionalExt) - count($missingOpt)) . '/' . count($optionalExt),
                  'status' => 'warning', 'detail' => 'Λείπουν: ' . implode(', ', $missingOpt)];
    }
    
    // MySQL version
    try {
        $mysqlVer = dbFetchValue("SELECT VERSION()");
        $env[] = ['label' => 'MySQL Version', 'value' => $mysqlVer, 'status' => 'ok', 'detail' => ''];
    } catch (Exception $e) {
        $env[] = ['label' => 'MySQL Version', 'value' => 'N/A', 'status' => 'error', 'detail' => $e->getMessage()];
    }
    
    // Timezone sync
    $phpTz = date_default_timezone_get();
    try {
        $mysqlTz = dbFetchValue("SELECT @@session.time_zone");
        $phpOffset = (new DateTime('now', new DateTimeZone($phpTz)))->format('P');
        $tzMatch = ($mysqlTz === $phpOffset || $mysqlTz === $phpTz);
        $env[] = ['label' => 'Timezone Sync', 'value' => "PHP: $phpTz | MySQL: $mysqlTz",
                  'status' => $tzMatch ? 'ok' : 'warning',
                  'detail' => $tzMatch ? 'Συγχρονισμένα' : 'Ασυμφωνία timezone'];
    } catch (Exception $e) {
        $env[] = ['label' => 'Timezone', 'value' => $phpTz, 'status' => 'warning', 'detail' => 'Δεν ήταν δυνατός ο έλεγχος MySQL timezone'];
    }
    
    // Debug mode
    $debugOn = defined('DEBUG_MODE') && DEBUG_MODE;
    $env[] = ['label' => 'Debug Mode', 'value' => $debugOn ? 'ΕΝΕΡΓΟ' : 'Ανενεργό',
              'status' => $debugOn ? 'warning' : 'ok',
              'detail' => $debugOn ? 'Απενεργοποιήστε το σε production' : ''];
    
    // PHP settings
    $env[] = ['label' => 'Memory Limit', 'value' => ini_get('memory_limit'), 'status' => 'ok', 'detail' => ''];
    $env[] = ['label' => 'Upload Max Size', 'value' => ini_get('upload_max_filesize'), 'status' => 'ok', 'detail' => ''];
    $env[] = ['label' => 'Post Max Size', 'value' => ini_get('post_max_size'), 'status' => 'ok', 'detail' => ''];
    $env[] = ['label' => 'Max Execution Time', 'value' => ini_get('max_execution_time') . 's', 'status' => 'ok', 'detail' => ''];
    
    // App version
    $env[] = ['label' => 'App Version', 'value' => APP_VERSION, 'status' => 'ok', 'detail' => ''];
    
    $results['checks']['environment'] = $env;
    
    // ── 2. FILE SYSTEM ──
    $fs = [];
    $dirs = [
        'uploads' => __DIR__ . '/uploads',
        'uploads/logos' => __DIR__ . '/uploads/logos',
        'uploads/documents' => __DIR__ . '/uploads/documents',
        'uploads/training' => __DIR__ . '/uploads/training',
        'uploads/photos' => __DIR__ . '/uploads/photos',
        'uploads/profile_photos' => __DIR__ . '/uploads/profile_photos',
        'exports' => __DIR__ . '/exports',
    ];
    $missingDirs = 0;
    foreach ($dirs as $name => $path) {
        $exists = is_dir($path);
        $writable = $exists && is_writable($path);
        if (!$exists) {
            $fs[] = ['label' => $name . '/', 'value' => 'Δεν υπάρχει', 'status' => 'error', 'detail' => 'Χρειάζεται δημιουργία'];
            $missingDirs++;
        } elseif (!$writable) {
            $fs[] = ['label' => $name . '/', 'value' => 'Μη εγγράψιμο', 'status' => 'warning', 'detail' => 'Ελέγξτε τα δικαιώματα'];
        } else {
            $fs[] = ['label' => $name . '/', 'value' => 'OK', 'status' => 'ok', 'detail' => 'Εγγράψιμο'];
        }
    }
    
    // Disk space
    $freeSpace = @disk_free_space(__DIR__);
    if ($freeSpace !== false) {
        $freeGB = round($freeSpace / (1024*1024*1024), 1);
        $fs[] = ['label' => 'Ελεύθερος χώρος δίσκου', 'value' => $freeGB . ' GB',
                 'status' => $freeGB > 1 ? 'ok' : ($freeGB > 0.2 ? 'warning' : 'error'),
                 'detail' => $freeGB < 1 ? 'Χαμηλός ελεύθερος χώρος' : ''];
    }
    
    $results['checks']['filesystem'] = $fs;
    $results['missing_dirs'] = $missingDirs;
    
    // ── 3. DATABASE STRUCTURE ──
    $dbStruct = [];
    
    $expectedTables = [
        'achievements', 'audit_logs', 'certificate_types', 'citizen_certificate_types',
        'citizen_certificates', 'citizens', 'departments', 'documents', 'email_logs',
        'email_templates', 'exam_attempts', 'inventory_bookings', 'inventory_categories',
        'inventory_department_access', 'inventory_fixed_assets', 'inventory_items',
        'inventory_kit_items', 'inventory_kits', 'inventory_locations', 'inventory_notes',
        'inventory_shelf_items', 'mission_chat_messages', 'mission_debriefs', 'mission_types',
        'missions', 'newsletter_sends', 'newsletter_unsubscribes', 'newsletters',
        'notification_settings', 'notifications', 'participation_requests', 'password_reset_tokens',
        'quiz_attempts', 'settings', 'shifts', 'skill_categories', 'skills', 'subtasks',
        'task_assignments', 'task_comments', 'tasks', 'training_categories', 'training_exam_questions',
        'training_exams', 'training_materials', 'training_quiz_questions', 'training_quizzes',
        'training_user_progress', 'user_achievements', 'user_answers', 'user_notification_preferences',
        'user_skills', 'users', 'volunteer_certificates', 'volunteer_documents', 'volunteer_pings',
        'volunteer_points', 'volunteer_positions',
        // additional tables
        'complaints', 'migrations', 'volunteer_profiles',
    ];
    
    try {
        $actualTables = db()->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        $missing = array_diff($expectedTables, $actualTables);
        $extra = array_diff($actualTables, $expectedTables);
        
        $dbStruct[] = ['label' => 'Πίνακες βάσης', 'value' => count($actualTables) . '/' . count($expectedTables) . ' αναμενόμενοι',
                       'status' => empty($missing) ? 'ok' : 'error',
                       'detail' => empty($missing) ? 'Όλοι οι πίνακες υπάρχουν' : 'Λείπουν: ' . implode(', ', $missing)];
        if (!empty($extra)) {
            $dbStruct[] = ['label' => 'Επιπλέον πίνακες', 'value' => count($extra),
                           'status' => 'warning', 'detail' => implode(', ', $extra)];
        }
    } catch (Exception $e) {
        $dbStruct[] = ['label' => 'Πίνακες βάσης', 'value' => 'Σφάλμα', 'status' => 'error', 'detail' => $e->getMessage()];
    }
    
    // Migration version
    try {
        $dbVersion = (int) dbFetchValue("SELECT setting_value FROM settings WHERE setting_key = 'db_schema_version'");
        $latestVersion = defined('LATEST_MIGRATION_VERSION') ? LATEST_MIGRATION_VERSION : '?';
        $dbStruct[] = ['label' => 'Schema Version', 'value' => "$dbVersion / $latestVersion",
                       'status' => ($dbVersion >= $latestVersion) ? 'ok' : 'warning',
                       'detail' => ($dbVersion >= $latestVersion) ? 'Ενημερωμένο' : 'Εκκρεμούν migrations'];
    } catch (Exception $e) {
        $dbStruct[] = ['label' => 'Schema Version', 'value' => 'N/A', 'status' => 'warning', 'detail' => 'Δεν βρέθηκε'];
    }
    
    // Table sizes
    try {
        $dbName = DB_NAME;
        $tableSizes = dbFetchAll("
            SELECT TABLE_NAME, TABLE_ROWS, 
                   ROUND((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024, 2) AS size_mb,
                   ROUND(DATA_LENGTH / 1024 / 1024, 2) AS data_mb,
                   ROUND(INDEX_LENGTH / 1024 / 1024, 2) AS index_mb
            FROM INFORMATION_SCHEMA.TABLES 
            WHERE TABLE_SCHEMA = ? 
            ORDER BY (DATA_LENGTH + INDEX_LENGTH) DESC
        ", [$dbName]);
        
        $totalSize = 0;
        $totalRows = 0;
        $topTables = [];
        foreach ($tableSizes as $i => $ts) {
            $totalSize += (float)$ts['size_mb'];
            $totalRows += (int)$ts['TABLE_ROWS'];
            if ($i < 5) {
                $topTables[] = $ts['TABLE_NAME'] . ' (' . number_format($ts['TABLE_ROWS']) . ' rows, ' . $ts['size_mb'] . ' MB)';
            }
        }
        
        $dbStruct[] = ['label' => 'Μέγεθος βάσης', 'value' => round($totalSize, 2) . ' MB',
                       'status' => 'ok', 'detail' => number_format($totalRows) . ' συνολικές εγγραφές'];
        $dbStruct[] = ['label' => 'Top 5 πίνακες', 'value' => '',
                       'status' => 'ok', 'detail' => implode(' | ', $topTables)];
        
        $results['table_sizes'] = $tableSizes;
    } catch (Exception $e) {
        $dbStruct[] = ['label' => 'Μέγεθος βάσης', 'value' => 'N/A', 'status' => 'warning', 'detail' => $e->getMessage()];
    }
    
    $results['checks']['database'] = $dbStruct;
    
    // ── 4. DATA INTEGRITY ──
    $integrity = [];
    
    try {
        // Orphan participation_requests
        $orphanPR = (int) dbFetchValue("SELECT COUNT(*) FROM participation_requests pr LEFT JOIN shifts s ON pr.shift_id = s.id WHERE s.id IS NULL");
        $integrity[] = ['label' => 'Ορφανές συμμετοχές (shift deleted)', 'value' => $orphanPR,
                        'status' => $orphanPR === 0 ? 'ok' : 'warning', 'detail' => $orphanPR > 0 ? 'Χρειάζεται καθαρισμός' : ''];
        
        // Orphan shifts
        $orphanShifts = (int) dbFetchValue("SELECT COUNT(*) FROM shifts sh LEFT JOIN missions m ON sh.mission_id = m.id WHERE m.id IS NULL");
        $integrity[] = ['label' => 'Ορφανές βάρδιες (mission deleted)', 'value' => $orphanShifts,
                        'status' => $orphanShifts === 0 ? 'ok' : 'warning', 'detail' => $orphanShifts > 0 ? 'Χρειάζεται καθαρισμός' : ''];
        
        // Orphan volunteer_points
        $orphanPoints = (int) dbFetchValue("SELECT COUNT(*) FROM volunteer_points vp LEFT JOIN users u ON vp.user_id = u.id WHERE u.id IS NULL");
        $integrity[] = ['label' => 'Ορφανοί πόντοι (user deleted)', 'value' => $orphanPoints,
                        'status' => $orphanPoints === 0 ? 'ok' : 'warning', 'detail' => $orphanPoints > 0 ? 'Χρειάζεται καθαρισμός' : ''];
        
        // Orphan notifications
        $orphanNotif = (int) dbFetchValue("SELECT COUNT(*) FROM notifications n LEFT JOIN users u ON n.user_id = u.id WHERE u.id IS NULL");
        $integrity[] = ['label' => 'Ορφανές ειδοποιήσεις', 'value' => $orphanNotif,
                        'status' => $orphanNotif === 0 ? 'ok' : 'warning', 'detail' => $orphanNotif > 0 ? 'Χρειάζεται καθαρισμός' : ''];
        
        // Users active but soft-deleted
        $ghostUsers = (int) dbFetchValue("SELECT COUNT(*) FROM users WHERE is_active = 1 AND deleted_at IS NOT NULL");
        $integrity[] = ['label' => 'Ασυνέπεια (active + deleted)', 'value' => $ghostUsers,
                        'status' => $ghostUsers === 0 ? 'ok' : 'warning', 'detail' => $ghostUsers > 0 ? 'Χρήστες active αλλά deleted' : ''];
        
        // Duplicate emails
        $dupEmails = (int) dbFetchValue("SELECT COUNT(*) FROM (SELECT email, COUNT(*) c FROM users WHERE deleted_at IS NULL GROUP BY email HAVING c > 1) t");
        $integrity[] = ['label' => 'Διπλότυπα emails', 'value' => $dupEmails,
                        'status' => $dupEmails === 0 ? 'ok' : 'error', 'detail' => $dupEmails > 0 ? 'Υπάρχουν διπλότυπα emails' : ''];
        
        // Approved decisions without decided_by
        $noDecider = (int) dbFetchValue("SELECT COUNT(*) FROM participation_requests WHERE status IN ('APPROVED','REJECTED') AND decided_by IS NULL");
        $integrity[] = ['label' => 'Αποφάσεις χωρίς decided_by', 'value' => $noDecider,
                        'status' => $noDecider === 0 ? 'ok' : 'warning', 'detail' => $noDecider > 0 ? 'Ιστορικά δεδομένα χωρίς αποφασίζοντα' : ''];
        
        $totalOrphans = $orphanPR + $orphanShifts + $orphanPoints + $orphanNotif;
        $results['total_orphans'] = $totalOrphans;
        
    } catch (Exception $e) {
        $integrity[] = ['label' => 'Έλεγχος ακεραιότητας', 'value' => 'Σφάλμα', 'status' => 'error', 'detail' => $e->getMessage()];
    }
    
    $results['checks']['integrity'] = $integrity;
    
    // ── 5. PERFORMANCE ──
    $perf = [];
    
    try {
        // Audit logs size
        $auditCount = (int) dbFetchValue("SELECT COUNT(*) FROM audit_logs");
        $perf[] = ['label' => 'Εγγραφές audit_logs', 'value' => number_format($auditCount),
                   'status' => $auditCount < 50000 ? 'ok' : ($auditCount < 100000 ? 'warning' : 'error'),
                   'detail' => $auditCount >= 50000 ? 'Σκεφτείτε καθαρισμό παλαιών εγγραφών' : ''];
        
        // Email logs size
        $emailLogCount = (int) dbFetchValue("SELECT COUNT(*) FROM email_logs");
        $perf[] = ['label' => 'Εγγραφές email_logs', 'value' => number_format($emailLogCount),
                   'status' => $emailLogCount < 50000 ? 'ok' : ($emailLogCount < 100000 ? 'warning' : 'error'),
                   'detail' => $emailLogCount >= 50000 ? 'Σκεφτείτε καθαρισμό παλαιών εγγραφών' : ''];
        
        // Notifications unread ratio
        $totalNotif = (int) dbFetchValue("SELECT COUNT(*) FROM notifications");
        $unreadNotif = (int) dbFetchValue("SELECT COUNT(*) FROM notifications WHERE read_at IS NULL");
        $perf[] = ['label' => 'Ειδοποιήσεις (αδιάβαστες/σύνολο)', 'value' => number_format($unreadNotif) . '/' . number_format($totalNotif),
                   'status' => ($totalNotif === 0 || ($unreadNotif / max($totalNotif, 1)) < 0.8) ? 'ok' : 'warning',
                   'detail' => ''];
        
    } catch (Exception $e) {
        $perf[] = ['label' => 'Στατιστικά απόδοσης', 'value' => 'Σφάλμα', 'status' => 'error', 'detail' => $e->getMessage()];
    }
    
    $results['checks']['performance'] = $perf;
    
    // ── 6. CONFIG & SECURITY ──
    $security = [];
    
    // Admin email
    $adminEmail = getSetting('admin_email', '');
    $security[] = ['label' => 'Admin email', 'value' => !empty($adminEmail) ? $adminEmail : 'Μη ρυθμισμένο',
                   'status' => !empty($adminEmail) ? 'ok' : 'warning', 'detail' => empty($adminEmail) ? 'Ρυθμίστε στις Γενικές ρυθμίσεις' : ''];
    
    // SMTP
    $smtpHost = getSetting('smtp_host', '');
    $security[] = ['label' => 'SMTP Email', 'value' => !empty($smtpHost) ? $smtpHost : 'Μη ρυθμισμένο',
                   'status' => !empty($smtpHost) ? 'ok' : 'warning', 'detail' => empty($smtpHost) ? 'Τα email δεν αποστέλλονται' : ''];
    
    // System admins exist
    $adminCount = (int) dbFetchValue("SELECT COUNT(*) FROM users WHERE role = 'SYSTEM_ADMIN' AND is_active = 1 AND deleted_at IS NULL");
    $security[] = ['label' => 'System Admins', 'value' => $adminCount,
                   'status' => $adminCount >= 1 ? 'ok' : 'error', 'detail' => $adminCount === 0 ? 'Δεν υπάρχει ενεργός admin!' : ''];
    
    // Maintenance mode
    $maintMode = getSetting('maintenance_mode', '0');
    $security[] = ['label' => 'Λειτουργία συντήρησης', 'value' => $maintMode === '1' ? 'ΕΝΕΡΓΗ' : 'Ανενεργή',
                   'status' => $maintMode === '1' ? 'warning' : 'ok', 'detail' => $maintMode === '1' ? 'Μόνο admins έχουν πρόσβαση' : ''];
    
    // Email templates
    try {
        $totalTemplates = (int) dbFetchValue("SELECT COUNT(*) FROM email_templates");
        $emptyTemplates = (int) dbFetchValue("SELECT COUNT(*) FROM email_templates WHERE body_html IS NULL OR body_html = ''");
        $security[] = ['label' => 'Email templates', 'value' => $totalTemplates . ' templates',
                       'status' => $emptyTemplates === 0 ? 'ok' : 'warning',
                       'detail' => $emptyTemplates > 0 ? "$emptyTemplates κενά templates" : 'Όλα ρυθμισμένα'];
    } catch (Exception $e) { /* skip */ }
    
    // Session lifetime
    $security[] = ['label' => 'Session Lifetime', 'value' => (defined('SESSION_LIFETIME') ? (SESSION_LIFETIME / 60) . ' λεπτά' : 'Default'),
                   'status' => 'ok', 'detail' => ''];
    
    $results['checks']['security'] = $security;
    
    // ── 7. HEALTH STATS ──
    $stats = [];
    try {
        $totalUsers = (int) dbFetchValue("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL");
        $activeUsers = (int) dbFetchValue("SELECT COUNT(*) FROM users WHERE is_active = 1 AND deleted_at IS NULL");
        $deletedUsers = (int) dbFetchValue("SELECT COUNT(*) FROM users WHERE deleted_at IS NOT NULL");
        $stats[] = ['label' => 'Χρήστες (ενεργοί / σύνολο / διαγραμμένοι)', 'value' => "$activeUsers / $totalUsers / $deletedUsers", 'status' => 'ok', 'detail' => ''];
        
        $missionsByStatus = dbFetchAll("SELECT status, COUNT(*) as cnt FROM missions WHERE deleted_at IS NULL GROUP BY status");
        $missionStr = implode(', ', array_map(fn($m) => $m['status'] . ': ' . $m['cnt'], $missionsByStatus));
        $stats[] = ['label' => 'Αποστολές κατά κατάσταση', 'value' => $missionStr ?: 'Δεν υπάρχουν', 'status' => 'ok', 'detail' => ''];
        
        // Last audit entry
        $lastAudit = dbFetchValue("SELECT MAX(created_at) FROM audit_logs");
        $stats[] = ['label' => 'Τελευταία ενέργεια (audit)', 'value' => $lastAudit ? formatDateTime($lastAudit) : 'Κενό', 'status' => 'ok', 'detail' => ''];
        
        // Last cron run
        $lastCron = getSetting('cron_last_manual_run', '');
        $stats[] = ['label' => 'Τελευταίο cron run', 'value' => $lastCron ? formatDateTime($lastCron) : 'Ποτέ', 
                    'status' => !empty($lastCron) ? 'ok' : 'warning', 'detail' => empty($lastCron) ? 'Δεν έχει τρέξει ποτέ' : ''];
        
    } catch (Exception $e) {
        $stats[] = ['label' => 'Στατιστικά', 'value' => 'Σφάλμα', 'status' => 'error', 'detail' => $e->getMessage()];
    }
    
    $results['checks']['stats'] = $stats;
    
    // ── CALCULATE SCORE ──
    $total = 0;
    $passed = 0;
    foreach ($results['checks'] as $category => $checks) {
        foreach ($checks as $check) {
            $total++;
            if ($check['status'] === 'ok') $passed++;
            elseif ($check['status'] === 'warning') $passed += 0.5;
        }
    }
    $results['total'] = $total;
    $results['passed'] = $passed;
    $results['score'] = $total > 0 ? round(($passed / $total) * 100) : 0;
    
    return $results;
}

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
            if (class_exists('finfo')) {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $detectedMime = $finfo->file($file['tmp_name']);
            } else {
                $detectedMime = mime_content_type($file['tmp_name']);
            }
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

        // Handle aithsh.php background-image upload (same validation/storage
        // shape as the logo upload above, own folder/filename prefix)
        if (!empty($_FILES['aithsh_bg_image']['name'])) {
            $file = $_FILES['aithsh_bg_image'];
            $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            $maxSize = 4 * 1024 * 1024; // 4MB — a hero photo is larger than an icon-sized logo

            if (class_exists('finfo')) {
                $finfo = new finfo(FILEINFO_MIME_TYPE);
                $detectedMime = $finfo->file($file['tmp_name']);
            } else {
                $detectedMime = mime_content_type($file['tmp_name']);
            }
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

            if (!in_array($detectedMime, $allowedTypes) || !in_array($ext, $allowedExtensions)) {
                setFlash('error', 'Μη αποδεκτός τύπος αρχείου για το φόντο. Επιτρέπονται: JPG, PNG, GIF, WebP.');
                redirect('settings.php?tab=general');
            }

            if ($file['size'] > $maxSize) {
                setFlash('error', 'Το αρχείο φόντου είναι πολύ μεγάλο. Μέγιστο μέγεθος: 4MB.');
                redirect('settings.php?tab=general');
            }

            $uploadDir = __DIR__ . '/uploads/backgrounds/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }

            $oldBg = $settings['aithsh_bg_image'] ?? '';
            if (!empty($oldBg) && file_exists($uploadDir . $oldBg)) {
                unlink($uploadDir . $oldBg);
            }

            $newFilename = 'aithsh_bg_' . time() . '.' . $ext;

            if (move_uploaded_file($file['tmp_name'], $uploadDir . $newFilename)) {
                $exists = dbFetchValue("SELECT COUNT(*) FROM settings WHERE setting_key = 'aithsh_bg_image'");
                if ($exists) {
                    dbExecute("UPDATE settings SET setting_value = ?, updated_at = NOW() WHERE setting_key = 'aithsh_bg_image'", [$newFilename]);
                } else {
                    dbInsert("INSERT INTO settings (setting_key, setting_value, created_at, updated_at) VALUES ('aithsh_bg_image', ?, NOW(), NOW())", [$newFilename]);
                }
                $settings['aithsh_bg_image'] = $newFilename;
            } else {
                setFlash('error', 'Σφάλμα κατά την αποθήκευση του αρχείου φόντου.');
                redirect('settings.php?tab=general');
            }
        }

        // Handle aithsh.php background-image deletion
        if (post('delete_aithsh_bg') === '1') {
            $uploadDir = __DIR__ . '/uploads/backgrounds/';
            $oldBg = $settings['aithsh_bg_image'] ?? '';
            if (!empty($oldBg) && file_exists($uploadDir . $oldBg)) {
                unlink($uploadDir . $oldBg);
            }
            dbExecute("UPDATE settings SET setting_value = '', updated_at = NOW() WHERE setting_key = 'aithsh_bg_image'");
            $settings['aithsh_bg_image'] = '';
        }

        // Save general settings
        $fieldsToUpdate = [
            'app_name', 'app_description', 'org_name', 'org_president_name', 'org_secretary_name', 'org_contact_phone', 'org_contact_email', 'org_contact_address', 'cert_signature_font_size', 'war_room_banner_font_size', 'war_room_ticker_position', 'war_room_auto_ping_seconds', 'war_room_low_battery_pct', 'war_room_max_shift_minutes', 'war_room_grid_max_size_m', 'war_room_grid_max_cells', 'war_room_area_unit',
            'vitals_enabled', 'vitals_sample_seconds', 'vitals_elevated_pct', 'vitals_critical_pct', 'vitals_low_bpm', 'vitals_reference_age', 'vitals_stale_seconds', 'vitals_retention_days',
            'vitals_episode_tachy_minutes', 'vitals_episode_brady_minutes', 'vitals_episode_strain_minutes',
            'admin_email', 'developer_email', 'timezone', 'date_format',
            'points_per_hour', 'weekend_multiplier', 'night_multiplier', 'medical_multiplier',
            'achievements_enabled', 'points_enabled',
            'registration_enabled', 'show_register_button', 'require_approval', 'maintenance_mode',
            'session_timeout_minutes',
            'shift_reminder_hours', 'resend_mission_hours_before', 'resend_mission_enabled',
            'qr_checkin_enabled',
            'openweathermap_api_key', 'weather_map_compass_enabled', 'exposure_urgency_enabled',
            'google_maps_api_key',
            'search_rings_enabled',
            'ai_enabled', 'ai_provider', 'ai_playbook',
        ];
        // Key, model and base URL are stored per provider, and the provider
        // list is meant to grow — so the field list is derived from
        // aiProviders() rather than spelled out and forgotten about.
        foreach (array_keys(aiProviders()) as $aiKey) {
            $fieldsToUpdate[] = 'ai_api_key_' . $aiKey;
            $fieldsToUpdate[] = 'ai_model_' . $aiKey;
            $fieldsToUpdate[] = 'ai_base_url_' . $aiKey;
        }

        foreach ($fieldsToUpdate as $field) {
            $value = isset($_POST[$field]) ? $_POST[$field] : '';

            if (in_array($field, ['achievements_enabled', 'points_enabled', 'registration_enabled', 'show_register_button', 'require_approval', 'maintenance_mode', 'resend_mission_enabled', 'qr_checkin_enabled', 'weather_map_compass_enabled', 'exposure_urgency_enabled', 'search_rings_enabled', 'vitals_enabled', 'ai_enabled'])) {
                $value = isset($_POST[$field]) ? '1' : '0';
            }

            // Trim the API key to avoid whitespace issues from copy-paste
            $isAiKey   = str_starts_with($field, 'ai_api_key_');
            $isAiModel = str_starts_with($field, 'ai_model_');
            $isAiUrl   = str_starts_with($field, 'ai_base_url_');
            if ($field === 'openweathermap_api_key' || $field === 'google_maps_api_key'
                || $isAiKey || $isAiModel || $isAiUrl) {
                $value = trim($value);
            }

            // Same allowlist-or-fall-back shape as war_room_ticker_position:
            // this key selects which stored API key is used and which
            // endpoint is called, so a crafted POST must not be able to name
            // a provider aiProviders() has never heard of.
            if ($field === 'ai_provider' && !array_key_exists($value, aiProviders())) {
                $value = 'gemini';
            }

            // Only http(s), and never a bare path. The base URL is where an
            // API key is sent, so a malformed or non-http value must fall
            // back to the provider's own default rather than be stored.
            if ($isAiUrl && $value !== '' && !preg_match('#^https?://#i', $value)) {
                $value = '';
            }

            // Clamp to the range this same form's number input already
            // advertises (min="5" max="1440") — that attribute alone is only
            // a browser-side hint, not enforced against a crafted request, and
            // every consumer of this value (includes/auth.php's session
            // cookie lifetime + inactivity check, includes/footer.php's
            // client-side timer) trusts whatever is stored here directly.
            if ($field === 'session_timeout_minutes') {
                $value = (string) max(5, min(1440, (int) $value ?: 120));
            }

            // Same "form attribute is only a browser hint" reasoning as
            // session_timeout_minutes above — this value drives the War
            // Room fatigue flag shown to every viewer, so it's worth
            // clamping server-side too.
            if ($field === 'war_room_max_shift_minutes') {
                $value = (string) max(30, min(2880, (int) $value ?: 480));
            }

            // Upper end of the sector-size slider in the Action Room's grid
            // tool. Same "form attribute is only a browser hint" reasoning as
            // the two above. The floor is 200 rather than the slider's own
            // 150m minimum so the slider always has room to move, and the
            // ceiling matches GRID_SECTOR_SIZE_MAX_M (config.php), which is
            // where buildSectorGridCells() clamps on both sides regardless.
            if ($field === 'war_room_grid_max_size_m') {
                $value = (string) max(200, min(GRID_SECTOR_SIZE_MAX_M, (int) $value ?: 900));
            }

            // Ceiling is MAX_GRID_CELLS (config.php), which exists because
            // every sector rides the 5-second Action Room poll to every open
            // tab — see that constant's own note for the measured cost. Same
            // browser-hint reasoning as every clamp above for why this is
            // enforced here and not just by the form's max attribute.
            if ($field === 'war_room_grid_max_cells') {
                $value = (string) max(10, min(MAX_GRID_CELLS, (int) $value ?: 120));
            }

            // Same allowlist-or-fall-back shape as war_room_ticker_position
            // below: a value outside the three the form offers can only come
            // from a hand-made POST, and 'auto' is the harmless answer.
            if ($field === 'war_room_area_unit' && !in_array($value, ['auto', 'mid', 'm2'], true)) {
                $value = 'auto';
            }

            // vitalsConfig() clamps every one of these again on read, so this
            // is not the safety guard — it exists so the number an admin sees
            // in this form is the number the app is actually using, instead of
            // a stored 9999 silently behaving as 1800 everywhere.
            $vitalsBounds = [
                'vitals_sample_seconds'  => [1, 60, 5],
                'vitals_elevated_pct'    => [40, 100, 75],
                'vitals_critical_pct'    => [50, 100, 88],
                'vitals_low_bpm'         => [25, 60, 45],
                'vitals_reference_age'   => [16, 90, 40],
                'vitals_stale_seconds'   => [30, 1800, 120],
                'vitals_retention_days'  => [7, 3650, 365],
                'vitals_episode_tachy_minutes' => [1, 120, 10],
                'vitals_episode_brady_minutes' => [1, 120, 5],
                'vitals_episode_strain_minutes'=> [5, 240, 20],
            ];
            if (isset($vitalsBounds[$field])) {
                [$vMin, $vMax, $vDefault] = $vitalsBounds[$field];
                $value = (string) max($vMin, min($vMax, (int) $value ?: $vDefault));
            }

            // Closed <select> in the form only offers these two — a crafted
            // request could still post anything, and this drives a live CSS
            // attribute selector in war-room.php that must never see a
            // third value.
            if ($field === 'war_room_ticker_position' && !in_array($value, ['top', 'bottom'], true)) {
                $value = 'top';
            }

            // Don't overwrite API key if form was submitted empty (acts like a "keep existing" field)
            if ($field === 'openweathermap_api_key' && empty($value) && !empty($settings['openweathermap_api_key'] ?? '')) {
                continue;
            }
            // Same "keep existing" behaviour for both AI keys. They are stored
            // per provider rather than in one shared field on purpose: an
            // admin comparing the two flips the dropdown back and forth, and a
            // single field would make them re-paste a key every time — which
            // is exactly the moment a key gets pasted into the wrong provider.
            if ($isAiKey && $value === '' && !empty($settings[$field] ?? '')) {
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

        // ── Telegram bot token: validated against Telegram itself (getMe)
        // before saving, unlike the plain-text API keys above — a typo here
        // would otherwise silently break every future mobilization broadcast
        // instead of just one feature. Never part of the generic loop above.
        $telegramFlash = null;
        $telegramTokenInput = trim(post('telegram_bot_token', ''));
        if ($telegramTokenInput !== '' && $telegramTokenInput !== ($settings['telegram_bot_token'] ?? '')) {
            $me = tgApiCall('getMe', [], $telegramTokenInput);
            if ($me === null || empty($me['ok'])) {
                $telegramFlash = ['error', 'Το Telegram Bot Token δεν είναι έγκυρο — ελέγξτε ότι το αντιγράψατε σωστά από το BotFather.'];
            } else {
                $botUsername = $me['result']['username'] ?? '';
                foreach (['telegram_bot_token' => $telegramTokenInput, 'telegram_bot_username' => $botUsername] as $k => $v) {
                    $exists = dbFetchValue("SELECT COUNT(*) FROM settings WHERE setting_key = ?", [$k]);
                    if ($exists) {
                        dbExecute("UPDATE settings SET setting_value = ?, updated_at = NOW() WHERE setting_key = ?", [$v, $k]);
                    } else {
                        dbInsert("INSERT INTO settings (setting_key, setting_value, created_at, updated_at) VALUES (?, ?, NOW(), NOW())", [$k, $v]);
                    }
                }
                $webhook = registerTelegramWebhook($telegramTokenInput);
                logAudit('update_settings', 'settings', null, 'Telegram bot token');
                $telegramFlash = $webhook['ok']
                    ? ['success', 'Το Telegram bot συνδέθηκε (@' . $botUsername . ') και ενεργοποιήθηκε ο webhook.']
                    : ['warning', 'Το bot token αποθηκεύτηκε (@' . $botUsername . ') αλλά η καταχώρηση webhook απέτυχε' . ($webhook['description'] !== '' ? ': ' . $webhook['description'] : '') . '. Δοκιμάστε «Επανεγγραφή Webhook» παρακάτω.'];
            }
        } elseif ($telegramTokenInput === '' && post('telegram_bot_token_clear') === '1') {
            dbExecute("UPDATE settings SET setting_value = '', updated_at = NOW() WHERE setting_key IN ('telegram_bot_token', 'telegram_bot_username')");
            logAudit('update_settings', 'settings', null, 'Telegram bot token κατάργηση');
            $telegramFlash = ['success', 'Η σύνδεση με το Telegram bot καταργήθηκε.'];
        }

        logAudit('update_settings', 'settings', null, 'Γενικές ρυθμίσεις');
        if ($telegramFlash !== null) {
            setFlash($telegramFlash[0], $telegramFlash[1]);
        } else {
            setFlash('success', 'Οι γενικές ρυθμίσεις αποθηκεύτηκαν.');
        }
        redirect('settings.php?tab=general');

    } elseif ($action === 'telegram_reregister_webhook') {
        // Own action, deliberately not folded into save_general above: that
        // handler's fieldsToUpdate loop treats any field missing from the
        // POST body as "clear it", so a minimal one-button form posting only
        // this action would wipe every other general setting to empty.
        if (isTelegramConfigured()) {
            $webhook = registerTelegramWebhook();
            logAudit('update_settings', 'settings', null, 'Telegram webhook re-register');
            if ($webhook['ok']) {
                setFlash('success', 'Ο webhook ενεργοποιήθηκε: ' . $webhook['url']);
            } else {
                setFlash('error', 'Απέτυχε η καταχώρηση webhook' . ($webhook['description'] !== '' ? ': ' . $webhook['description'] : '.'));
            }
        }
        redirect('settings.php?tab=general');

    } elseif ($action === 'save_menu') {
        // Left menu appearance. The palette is stored as one JSON blob rather
        // than eleven rows: it is read on every single page render through
        // getSetting(), and eleven separate keys would be eleven entries to
        // keep in step every time a section is added or renamed.
        $state = post('sidebar_default_state', 'current');
        if (!in_array($state, ['current', 'expanded', 'collapsed'], true)) {
            $state = 'current';
        }
        // An unchecked switch posts nothing at all, so absence is the "off"
        // signal here rather than a missing field to be ignored.
        $colorsOn = isset($_POST['sidebar_colors_enabled']) ? '1' : '0';

        if (post('reset_palette') === '1') {
            // Only the colours reset; the open/closed choice sits on its own
            // card and is submitted by the same form, so honouring it here
            // keeps "reset the colours" from also undoing an unsaved change
            // the admin just made above it.
            $palette = sidebarDefaultPalette();
            $flashMessage = 'Τα χρώματα επανήλθαν στις προεπιλογές.';
        } else {
            $submitted = $_POST['sidebar_color'] ?? [];
            $palette = [];
            foreach (sidebarDefaultPalette() as $secKey => $fallback) {
                $value = is_array($submitted) ? ($submitted[$secKey] ?? null) : null;
                // A rejected value falls back to the shipped colour rather than
                // to whatever was stored: a section is never left without one.
                $palette[$secKey] = (is_string($value) && sidebarIsHex($value))
                    ? strtolower($value)
                    : $fallback;
            }
            $flashMessage = 'Οι ρυθμίσεις του μενού αποθηκεύτηκαν.';
        }

        $toStore = [
            'sidebar_palette'        => json_encode($palette),
            'sidebar_default_state'  => $state,
            'sidebar_colors_enabled' => $colorsOn,
        ];
        foreach ($toStore as $key => $value) {
            $exists = dbFetchValue("SELECT COUNT(*) FROM settings WHERE setting_key = ?", [$key]);
            if ($exists) {
                dbExecute("UPDATE settings SET setting_value = ?, updated_at = NOW() WHERE setting_key = ?", [$value, $key]);
            } else {
                dbInsert("INSERT INTO settings (setting_key, setting_value, created_at, updated_at) VALUES (?, ?, NOW(), NOW())", [$key, $value]);
            }
            $settings[$key] = $value;
        }

        clearSettingsCache();
        logAudit('update_settings', 'settings', null, 'Εμφάνιση πλαϊνού μενού');
        setFlash('success', $flashMessage);
        redirect('settings.php?tab=menu');

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
        
    } elseif ($action === 'save_citizens') {
        // Save citizen certificate notification settings
        $citizenFields = [
            'citizen_cert_notify_enabled',
            'citizen_cert_notify_3months',
            'citizen_cert_notify_1month',
            'citizen_cert_notify_1week',
            'citizen_cert_notify_expired',
        ];
        
        foreach ($citizenFields as $field) {
            $value = isset($_POST[$field]) ? '1' : '0';
            
            $exists = dbFetchValue("SELECT COUNT(*) FROM settings WHERE setting_key = ?", [$field]);
            if ($exists) {
                dbExecute("UPDATE settings SET setting_value = ?, updated_at = NOW() WHERE setting_key = ?", [$value, $field]);
            } else {
                dbInsert("INSERT INTO settings (setting_key, setting_value, created_at, updated_at) VALUES (?, ?, NOW(), NOW())", [$field, $value]);
            }
            $settings[$field] = $value;
        }
        
        clearSettingsCache();
        logAudit('update_settings', 'settings', null, 'Ρυθμίσεις Πολιτών');
        setFlash('success', 'Οι ρυθμίσεις πολιτών αποθηκεύτηκαν.');
        redirect('settings.php?tab=citizens');

    } elseif ($action === 'save_subscriptions') {
        $values = [
            'subscription_reactivation_days' => (string)max(0, min(3650, (int)post('subscription_reactivation_days', 90))),
            'subscription_iris_renewal_days' => (string)max(0, min(3650, (int)post('subscription_iris_renewal_days', 90))),
            'subscription_iris_annual_amount' => (string)max(0, (float)str_replace(',', '.', post('subscription_iris_annual_amount', '30'))),
            'subscription_iris_tax_id' => preg_replace('/\D+/', '', post('subscription_iris_tax_id', '996695642')),
            'subscription_iris_phone' => preg_replace('/[^0-9+]/', '', post('subscription_iris_phone', '')),
        ];
        foreach ($values as $key => $value) {
            dbExecute("INSERT INTO settings (setting_key, setting_value, created_at, updated_at) VALUES (?, ?, NOW(), NOW()) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()", [$key, $value]);
        }
        clearSettingsCache();
        setFlash('success', 'Οι ρυθμίσεις ετήσιας συνδρομής και IRIS αποθηκεύτηκαν.');
        redirect('settings.php?tab=subscriptions');
        
    } elseif ($action === 'save_livekit') {
        // Same "blank means keep" contract the weather key uses, and for
        // the same reason: the secret is rendered masked, so an admin editing
        // only the URL would otherwise wipe it without noticing.
        $url = trim(post('livekit_url', ''));
        $key = trim(post('livekit_api_key', ''));
        $sec = trim(post('livekit_api_secret', ''));

        if ($url !== '' && !preg_match('#^wss?://#i', $url)) {
            setFlash('error', 'Το URL του LiveKit πρέπει να ξεκινά με wss:// (π.χ. wss://example.livekit.cloud).');
            redirect('settings.php?tab=livekit');
        }

        // Closed list: this value drives an encoder config, and an unexpected
        // string there would silently fall back rather than fail loudly.
        $quality = post('livekit_quality', '540');
        if (!in_array($quality, ['auto', '360', '540', '720'], true)) { $quality = 'auto'; }

        $codec = post('livekit_codec', 'vp8');
        if (!in_array($codec, ['vp8', 'vp9', 'h264'], true)) { $codec = 'vp8'; }

        $values = ['livekit_url' => $url, 'livekit_api_key' => $key, 'livekit_quality' => $quality, 'livekit_codec' => $codec];
        if ($sec !== '' || empty($settings['livekit_api_secret'] ?? '')) {
            $values['livekit_api_secret'] = $sec;
        }
        foreach ($values as $k => $v) {
            dbExecute("INSERT INTO settings (setting_key, setting_value, created_at, updated_at) VALUES (?, ?, NOW(), NOW()) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()", [$k, $v]);
        }
        clearSettingsCache();
        logAudit('save_livekit_settings', 'settings', null);
        setFlash('success', 'Οι ρυθμίσεις ζωντανής μετάδοσης αποθηκεύτηκαν.');
        redirect('settings.php?tab=livekit');

    } elseif ($action === 'test_livekit') {
        clearSettingsCache();
        $res = livekitTestConnection();
        setFlash($res['ok'] ? 'success' : 'error', $res['message']);
        redirect('settings.php?tab=livekit');

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
        
        // Allow cron scripts to run from web context
        if (!defined('CRON_MANUAL_RUN')) {
            define('CRON_MANUAL_RUN', true);
        }
        
        $cronJobs = [
            'task_reminders'      => ['file' => 'cron_task_reminders.php',      'label' => 'Υπενθυμίσεις Εργασιών'],
            'shift_reminders'     => ['file' => 'cron_shift_reminders.php',     'label' => 'Υπενθυμίσεις Βαρδιών'],
            'incomplete_missions' => ['file' => 'cron_incomplete_missions.php', 'label' => 'Ελλιπείς Αποστολές'],
            'certificate_expiry'  => ['file' => 'cron_certificate_expiry.php',  'label' => 'Λήξη Πιστοποιητικών'],
            'citizen_cert_expiry' => ['file' => 'cron_citizen_cert_expiry.php', 'label' => 'Λήξη Πιστ/κών Πολιτών'],
            'shelf_expiry'        => ['file' => 'cron_shelf_expiry.php',        'label' => 'Λήξη Υλικών Ραφιού'],
            'subscription_expiry' => ['file' => 'cron_subscription_expiry.php', 'label' => 'Λήξη Ετήσιων Συνδρομών'],
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
        
    } elseif ($action === 'run_health_check') {
        // Run health checks — results stored in session
        $_SESSION['health_results'] = runHealthChecks();
        $_SESSION['health_ran'] = true;
        logAudit('health_check', 'system', null, 'Εκτέλεση ελέγχου υγείας');
        redirect('settings.php?tab=health');

    } elseif ($action === 'health_fix_dirs') {
        $dirs = [
            __DIR__ . '/uploads',
            __DIR__ . '/uploads/logos',
            __DIR__ . '/uploads/documents',
            __DIR__ . '/uploads/training',
            __DIR__ . '/uploads/photos',
            __DIR__ . '/uploads/profile_photos',
            __DIR__ . '/exports',
        ];
        $created = 0;
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
                $created++;
            }
        }
        logAudit('health_fix', 'system', null, "Δημιουργία $created φακέλων");
        setFlash('success', "Δημιουργήθηκαν $created φάκελοι επιτυχώς.");
        redirect('settings.php?tab=health');

    } elseif ($action === 'health_fix_orphans') {
        $fixed = 0;
        // Orphan participation_requests (shift deleted)
        $fixed += dbExecute("DELETE pr FROM participation_requests pr LEFT JOIN shifts s ON pr.shift_id = s.id WHERE s.id IS NULL");
        // Orphan shifts (mission deleted)
        $fixed += dbExecute("DELETE sh FROM shifts sh LEFT JOIN missions m ON sh.mission_id = m.id WHERE m.id IS NULL");
        // Orphan volunteer_points (user deleted)
        $fixed += dbExecute("DELETE vp FROM volunteer_points vp LEFT JOIN users u ON vp.user_id = u.id WHERE u.id IS NULL");
        // Orphan notifications (user deleted)
        $fixed += dbExecute("DELETE n FROM notifications n LEFT JOIN users u ON n.user_id = u.id WHERE u.id IS NULL");
        // Orphan user_achievements (user deleted)
        $fixed += dbExecute("DELETE ua FROM user_achievements ua LEFT JOIN users u ON ua.user_id = u.id WHERE u.id IS NULL");
        logAudit('health_fix', 'system', null, "Καθαρισμός $fixed ορφανών εγγραφών");
        setFlash('success', "Καθαρίστηκαν $fixed ορφανές εγγραφές.");
        redirect('settings.php?tab=health');

    } elseif ($action === 'health_optimize') {
        $tables = db()->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        $optimized = 0;
        foreach ($tables as $table) {
            try {
                db()->exec("OPTIMIZE TABLE `$table`");
                $optimized++;
            } catch (Exception $e) { /* skip */ }
        }
        logAudit('health_optimize', 'system', null, "OPTIMIZE TABLE σε $optimized πίνακες");
        setFlash('success', "Βελτιστοποιήθηκαν $optimized πίνακες επιτυχώς.");
        redirect('settings.php?tab=health');

    } elseif ($action === 'health_analyze') {
        $tables = db()->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
        $analyzed = 0;
        foreach ($tables as $table) {
            try {
                db()->exec("ANALYZE TABLE `$table`");
                $analyzed++;
            } catch (Exception $e) { /* skip */ }
        }
        logAudit('health_analyze', 'system', null, "ANALYZE TABLE σε $analyzed πίνακες");
        setFlash('success', "Ανάλυση στατιστικών σε $analyzed πίνακες ολοκληρώθηκε.");
        redirect('settings.php?tab=health');

    } elseif ($action === 'health_cleanup_logs') {
        $months = (int) post('cleanup_months', 1);
        if ($months < 1) $months = 1;
        // "1 μηνών" is not Greek. The cutoff is a hidden field rather than a
        // fixed literal, so both forms have to read correctly.
        $monthLabel = $months === 1 ? 'ενός μήνα' : "$months μηνών";
        $deleted = dbExecute("DELETE FROM audit_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? MONTH)", [$months]);
        logAudit('health_cleanup', 'audit_logs', null, "Διαγραφή $deleted εγγραφών παλαιότερων $monthLabel");
        setFlash('success', "Διαγράφηκαν $deleted εγγραφές audit log παλαιότερες $monthLabel.");
        redirect('settings.php?tab=health');

    } elseif ($action === 'health_cleanup_email_logs') {
        $months = (int) post('cleanup_months', 1);
        if ($months < 1) $months = 1;
        $monthLabel = $months === 1 ? 'ενός μήνα' : "$months μηνών";
        $deleted = dbExecute("DELETE FROM email_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? MONTH)", [$months]);
        logAudit('health_cleanup', 'email_logs', null, "Διαγραφή $deleted email logs παλαιότερων $monthLabel");
        setFlash('success', "Διαγράφηκαν $deleted εγγραφές email log παλαιότερες $monthLabel.");
        redirect('settings.php?tab=health');

    // Same age-only rule as the two cleanups above: a notification past the
    // cutoff goes whether or not it was ever read. Unread ones are
    // deliberately NOT spared — sparing them was tried first and left the
    // button unable to shrink the table at all on an install where nobody
    // marks anything read (the demo database has 5.463 notifications, every
    // one of them unread), and a month-old unread notification is not
    // actionable anyway. Because this does remove user-facing state rather
    // than a pure log, the button's confirm says so in as many words.
    } elseif ($action === 'health_cleanup_notifications') {
        $months = (int) post('cleanup_months', 1);
        if ($months < 1) $months = 1;
        $monthLabel = $months === 1 ? 'ενός μήνα' : "$months μηνών";
        $deleted = dbExecute("DELETE FROM notifications WHERE created_at < DATE_SUB(NOW(), INTERVAL ? MONTH)", [$months]);
        logAudit('health_cleanup', 'notifications', null, "Διαγραφή $deleted ειδοποιήσεων παλαιότερων $monthLabel");
        setFlash('success', "Διαγράφηκαν $deleted ειδοποιήσεις παλαιότερες $monthLabel.");
        redirect('settings.php?tab=health');

    } elseif ($action === 'save_prerequisites') {
        $prereqKeys = [
            'prereq_attendance_enabled', 'prereq_attendance_goal',
            'prereq_hours_enabled', 'prereq_hours_goal',
            'prereq_mission_types',
            'prereq_tep_attendance_enabled', 'prereq_tep_attendance_goal',
            'prereq_tep_hours_enabled', 'prereq_tep_hours_goal',
            'prereq_tep_mission_types',
            'prereq_edu_attendance_enabled', 'prereq_edu_attendance_goal',
            'prereq_edu_hours_enabled', 'prereq_edu_hours_goal',
            'prereq_edu_mission_types',
        ];
        foreach ($prereqKeys as $key) {
            if (strpos($key, '_enabled') !== false) {
                $value = isset($_POST[$key]) ? '1' : '0';
            } elseif (strpos($key, '_mission_types') !== false) {
                $arr = $_POST[$key] ?? [];
                $value = is_array($arr) ? implode(',', array_map('intval', $arr)) : '';
            } else {
                $value = (string)(int) post($key, '0');
            }
            dbExecute(
                "INSERT INTO settings (setting_key, setting_value, created_at, updated_at)
                 VALUES (?, ?, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()",
                [$key, $value]
            );
        }
        logAudit('settings_update', 'settings', null, 'Ενημέρωση προαπαιτούμενων');
        setFlash('success', 'Τα προαπαιτούμενα ενημερώθηκαν επιτυχώς.');
        redirect('settings.php?tab=prerequisites');

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
<?php
// The 13 settings sections, grouped. One source for both the landing grid and
// the sidebar, so the two can never disagree about what exists. 'danger' marks
// the destructive one so it can be visually separated in both places.
$settingsNav = [
    'Βασικά' => [
        ['tab' => 'general',       'icon' => 'bi-sliders',            'label' => 'Γενικά',            'hint' => 'Όνομα, λογότυπο, ζώνη ώρας'],
        ['tab' => 'notifications', 'icon' => 'bi-bell',               'label' => 'Ειδοποιήσεις',      'hint' => 'Τι στέλνεται και πού'],
        ['tab' => 'menu',          'icon' => 'bi-palette',            'label' => 'Πλαϊνό Μενού',      'hint' => 'Χρώματα και άνοιγμα ενοτήτων'],
    ],
    'Επικοινωνία' => [
        ['tab' => 'smtp',      'icon' => 'bi-envelope',           'label' => 'SMTP Email',     'hint' => 'Διακομιστής αποστολής'],
        ['tab' => 'templates', 'icon' => 'bi-file-earmark-code',  'label' => 'Πρότυπα Email',  'hint' => 'Κείμενα μηνυμάτων'],
    ],
    'Λειτουργίες' => [
        ['tab' => 'inventory',     'icon' => 'bi-box-seam',     'label' => 'Απόθεμα',           'hint' => 'Κατηγορίες και χώροι'],
        ['tab' => 'citizens',      'icon' => 'bi-person-vcard', 'label' => 'Πολίτες',           'hint' => 'Μητρώο και σεμινάρια'],
        ['tab' => 'subscriptions', 'icon' => 'bi-cash-coin',    'label' => 'Συνδρομές',         'hint' => 'Ετήσια συνδρομή, IRIS'],
        ['tab' => 'livekit',       'icon' => 'bi-broadcast',    'label' => 'Ζωντανή Μετάδοση',  'hint' => 'LiveKit και ποιότητα'],
    ],
    'Σύστημα' => [
        ['tab' => 'cron',          'icon' => 'bi-clock-history',   'label' => 'Cron Jobs',         'hint' => 'Προγραμματισμένες εργασίες'],
        ['tab' => 'health',        'icon' => 'bi-heart-pulse',     'label' => 'Υγεία Εφαρμογής',   'hint' => 'Έλεγχοι και καθαρισμός'],
        ['tab' => 'prerequisites', 'icon' => 'bi-list-check',      'label' => 'Προαπαιτούμενα',    'hint' => 'Τι χρειάζεται ο server'],
        ['url' => 'update.php',    'icon' => 'bi-cloud-download',  'label' => 'Ενημερώσεις',       'hint' => 'Νέα έκδοση εφαρμογής'],
    ],
    'Επικίνδυνα' => [
        ['tab' => 'reset', 'icon' => 'bi-trash3', 'label' => 'Επαναφορά', 'hint' => 'Διαγραφή δεδομένων', 'danger' => true],
    ],
];
$settingsHref = fn(array $i) => $i['url'] ?? ('settings.php?tab=' . $i['tab']);
?>

<?php if ($activeTab === ''): ?>
<p class="text-muted mb-4">Διαλέξτε ενότητα.</p>
<?php foreach ($settingsNav as $groupLabel => $groupItems): ?>
    <h6 class="text-uppercase text-muted fw-semibold mb-2" style="font-size:.72rem;letter-spacing:.06em;"><?= h($groupLabel) ?></h6>
    <div class="row g-3 mb-4">
        <?php foreach ($groupItems as $item): ?>
        <div class="col-12 col-sm-6 col-lg-4 col-xxl-3">
            <a href="<?= h($settingsHref($item)) ?>" class="text-decoration-none d-block h-100">
                <div class="card h-100 settings-tile <?= !empty($item['danger']) ? 'border-danger' : '' ?>">
                    <div class="card-body py-3">
                        <i class="bi <?= h($item['icon']) ?> fs-4 <?= !empty($item['danger']) ? 'text-danger' : 'text-primary' ?>"></i>
                        <div class="fw-semibold mt-2 <?= !empty($item['danger']) ? 'text-danger' : '' ?>"><?= h($item['label']) ?></div>
                        <div class="small text-muted"><?= h($item['hint']) ?></div>
                    </div>
                </div>
            </a>
        </div>
        <?php endforeach; ?>
    </div>
<?php endforeach; ?>
<?php else: ?>

<div class="row g-4">
    <div class="col-lg-3">
        <div class="list-group list-group-flush settings-side sticky-lg-top" style="top:1rem;">
            <a href="settings.php" class="list-group-item list-group-item-action text-muted px-2 py-2">
                <i class="bi bi-grid me-2"></i>Όλες οι ρυθμίσεις
            </a>
            <?php foreach ($settingsNav as $groupLabel => $groupItems): ?>
                <div class="text-uppercase text-muted fw-semibold px-2 pt-3 pb-1" style="font-size:.68rem;letter-spacing:.06em;"><?= h($groupLabel) ?></div>
                <?php foreach ($groupItems as $item): ?>
                <a href="<?= h($settingsHref($item)) ?>"
                   class="list-group-item list-group-item-action px-2 py-2 <?= (isset($item['tab']) && $activeTab === $item['tab']) ? 'active' : '' ?> <?= !empty($item['danger']) ? 'text-danger' : '' ?>">
                    <i class="bi <?= h($item['icon']) ?> me-2"></i><?= h($item['label']) ?>
                </a>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="col-lg-9">
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

                    <!-- Background image for the public membership form (aithsh.php) -->
                    <div class="mb-3">
                        <label class="form-label">Φόντο Φόρμας Αίτησης Μέλους (aithsh.php)</label>
                        <?php if (!empty($settings['aithsh_bg_image']) && file_exists(__DIR__ . '/uploads/backgrounds/' . $settings['aithsh_bg_image'])): ?>
                            <div class="mb-2 p-3 bg-light rounded d-flex align-items-center">
                                <img src="uploads/backgrounds/<?= h($settings['aithsh_bg_image']) ?>" alt="Φόντο" style="max-height: 60px; max-width: 150px; object-fit: cover;" class="me-3 rounded">
                                <div>
                                    <small class="text-muted d-block"><?= h($settings['aithsh_bg_image']) ?></small>
                                    <label class="form-check-label">
                                        <input type="checkbox" name="delete_aithsh_bg" value="1" class="form-check-input">
                                        <span class="text-danger">Διαγραφή φόντου</span>
                                    </label>
                                </div>
                            </div>
                        <?php endif; ?>
                        <input type="file" class="form-control" name="aithsh_bg_image" accept="image/*">
                        <small class="text-muted">Προαιρετική φωτογραφία πίσω από τον τίτλο της δημόσιας φόρμας αίτησης — εμφανίζεται κάτω από ένα ημιδιάφανο μπλε επίστρωμα, ώστε το κείμενο να παραμένει ευανάγνωστο. Χωρίς αυτήν, η φόρμα δείχνει το προεπιλεγμένο μπλε φόντο. Μέγιστο: 4MB.</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Περιγραφή</label>
                        <textarea class="form-control" name="app_description" rows="2"><?= h($settings['app_description']) ?></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email Διαχειριστή</label>
                        <input type="email" class="form-control" name="admin_email" value="<?= h($settings['admin_email']) ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email Προγραμματιστή</label>
                        <input type="email" class="form-control" name="developer_email" value="<?= h($settings['developer_email']) ?>">
                        <small class="text-muted">Εδώ στέλνονται οι αναφορές από το "Αποστολή Bug" του μενού χρήστη.</small>
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
                    <hr>
                    <h6 class="mb-2">Στοιχεία Οργανισμού (για Πιστοποιητικά/Εκτυπώσεις)</h6>
                    <div class="mb-3">
                        <label class="form-label">Πλήρης Επωνυμία Οργανισμού</label>
                        <input type="text" class="form-control" name="org_name" value="<?= h($settings['org_name']) ?>" placeholder="π.χ. Ελληνική Ομάδα Διάσωσης Χανίων">
                        <small class="text-muted">Εμφανίζεται σε εκτυπώσεις/πιστοποιητικά — διαφορετικό από το «Όνομα Εφαρμογής» παραπάνω.</small>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Ονοματεπώνυμο Προέδρου</label>
                            <input type="text" class="form-control" name="org_president_name" value="<?= h($settings['org_president_name']) ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Ονοματεπώνυμο Γεν. Γραμματέα</label>
                            <input type="text" class="form-control" name="org_secretary_name" value="<?= h($settings['org_secretary_name']) ?>">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Τηλέφωνο Επικοινωνίας</label>
                            <input type="text" class="form-control" name="org_contact_phone" value="<?= h($settings['org_contact_phone']) ?>" placeholder="π.χ. 2810 123456">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Email Επικοινωνίας</label>
                            <input type="email" class="form-control" name="org_contact_email" value="<?= h($settings['org_contact_email']) ?>" placeholder="info@example.gr">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Διεύθυνση / Έδρα</label>
                            <input type="text" class="form-control" name="org_contact_address" value="<?= h($settings['org_contact_address']) ?>">
                        </div>
                        <small class="text-muted">Εμφανίζονται στο footer της δημόσιας φόρμας αίτησης νέου μέλους (aithsh.php). Ένα κενό πεδίο απλά δεν εμφανίζεται.</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Μέγεθος Γραμματοσειράς Ονομάτων Υπογραφής (pt)</label>
                        <input type="number" class="form-control" style="max-width:160px;" name="cert_signature_font_size"
                               value="<?= h($settings['cert_signature_font_size']) ?>" min="4" max="24" step="0.5">
                        <small class="text-muted">Μέγεθος γραμμάτων του ονόματος Προέδρου/Γεν. Γραμματέα πάνω από την υπογραφή στη Βεβαίωση Συμμετοχής.</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Μέγεθος Κυλιόμενου Κειμένου Action Room (rem)</label>
                        <input type="number" class="form-control" style="max-width:160px;" name="war_room_banner_font_size"
                               value="<?= h($settings['war_room_banner_font_size']) ?>" min="0.8" max="3" step="0.05">
                        <small class="text-muted">Μέγεθος του κυλιόμενου κειμένου συναγερμού (banner) στο Action Room, σε desktop οθόνες.</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Θέση Κυλιόμενης Μπάρας Action Room</label>
                        <select class="form-select" style="max-width:220px;" name="war_room_ticker_position">
                            <option value="top" <?= $settings['war_room_ticker_position'] === 'top' ? 'selected' : '' ?>>Πάνω στη σελίδα</option>
                            <option value="bottom" <?= $settings['war_room_ticker_position'] === 'bottom' ? 'selected' : '' ?>>Κάτω στη σελίδα</option>
                        </select>
                        <small class="text-muted">Η μπάρα (SOS/απαγορευμένη ζώνη ενεργά, ανακοινώσεις &amp; εντολές) μένει πάντα ορατή στην οθόνη, ό,τι tab ή σημείο της σελίδας κι αν βρίσκεται ο χρήστης — αυτό επιλέγει αν κάθεται πάνω ή κάτω.</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Συχνότητα Αυτόματου Στίγματος Action Room (δευτ.)</label>
                        <input type="number" class="form-control" style="max-width:160px;" name="war_room_auto_ping_seconds"
                               value="<?= h($settings['war_room_auto_ping_seconds']) ?>" min="5" max="1800" step="5">
                        <small class="text-muted">Πόσο συχνά στέλνεται αυτόματα το στίγμα GPS ενός εθελοντή όσο έχει ανοιχτό το Action Room. Λειτουργεί μόνο ενώ η σελίδα παραμένει ανοιχτή στο προσκήνιο — αν κλειδώσει η οθόνη ή αλλάξει εφαρμογή, το πρόγραμμα περιήγησης σταματά το αυτόματο στίγμα (περιορισμός των κινητών, όχι της εφαρμογής).</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Όριο Χαμηλής Μπαταρίας Action Room (%)</label>
                        <input type="number" class="form-control" style="max-width:160px;" name="war_room_low_battery_pct"
                               value="<?= h($settings['war_room_low_battery_pct']) ?>" min="0" max="100" step="5">
                        <small class="text-muted">Ποσοστό μπαταρίας κινητού κάτω από το οποίο εμφανίζεται προειδοποίηση στο στίγμα εθελοντή στο Action Room (χάρτης, Κοντινές Ομάδες, Αποστάσεις Ομάδων). Η "κρίσιμη" ένδειξη (κόκκινο) εμφανίζεται στο μισό αυτού του ποσοστού.</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Όριο Συνεχόμενης Βάρδιας Action Room (λεπτά)</label>
                        <input type="number" class="form-control" style="max-width:160px;" name="war_room_max_shift_minutes"
                               value="<?= h($settings['war_room_max_shift_minutes']) ?>" min="30" max="2880" step="30">
                        <small class="text-muted">Λεπτά συνεχόμενης παρουσίας εθελοντή σε αλυσίδα εγκεκριμένων βαρδιών στην ίδια αποστολή, πάνω από τα οποία εμφανίζεται προειδοποίηση κόπωσης στο Action Room (ρόστερ, χάρτης, Κοντινές Ομάδες, Αποστάσεις Ομάδων) και προτείνεται αντικατάσταση. Προεπιλογή 480 = 8 ώρες. Η "κρίσιμη" ένδειξη (κόκκινο) εμφανίζεται στο 1,5x του ορίου.</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Μέγιστο Μέγεθος Τομέα Αυτόματου Πλέγματος (μ.)</label>
                        <input type="number" class="form-control" style="max-width:160px;" name="war_room_grid_max_size_m"
                               value="<?= h($settings['war_room_grid_max_size_m'] ?? '900') ?>" min="200" max="<?= GRID_SECTOR_SIZE_MAX_M ?>" step="50">
                        <small class="text-muted">Πόσο μεγάλο τομέα μπορεί να ζητήσει ο συντονιστής στο «Αυτόματο πλέγμα» του Action Room — το πάνω άκρο του διακόπτη. Το κάτω άκρο μένει στα 150 μ. Μεγαλύτερος τομέας σημαίνει λιγότερους τομείς για την ίδια περιοχή: σε μεγάλες ορεινές περιοχές το 900 μπορεί να μη φτάνει και το πλέγμα να κόβεται από το όριο τομέων ανά περιοχή που ορίζεται ακριβώς παρακάτω. Προεπιλογή 900, μέγιστο <?= GRID_SECTOR_SIZE_MAX_M ?>.</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Μέγιστοι Τομείς ανά Αυτόματο Πλέγμα</label>
                        <input type="number" class="form-control" style="max-width:160px;" name="war_room_grid_max_cells"
                               value="<?= h($settings['war_room_grid_max_cells'] ?? '120') ?>" min="10" max="<?= MAX_GRID_CELLS ?>" step="10">
                        <small class="text-muted">Πόσους τομείς το πολύ μπορεί να παράγει ένα πλέγμα σε μία περιοχή έρευνας. Πάνω από αυτό, το κουμπί δημιουργίας κλειδώνει και ζητείται μεγαλύτερο μέγεθος τομέα. <strong>Δεν είναι όριο της βάσης — είναι όριο δικτύου:</strong> κάθε τομέας της αποστολής στέλνεται ολόκληρος σε κάθε ανανέωση των 5 δευτερολέπτων, σε κάθε ανοιχτή οθόνη Action Room, και κοστίζει περίπου 739 bytes (μετρημένο σε πραγματική αποστολή). Με 120 τομείς αυτό είναι ~87KB ανά 5 δευτερόλεπτα ανά οθόνη, με 400 γίνεται ~290KB. Ανεβάστε το μόνο αν χρειάζεστε πυκνότερο πλέγμα και οι συντονιστές δεν κρατούν πολλές οθόνες ανοιχτές ταυτόχρονα. Επίσης: κάθε τομέας ζωγραφίζει μόνιμη ετικέτα στον χάρτη, και ήδη στους 49 αρχίζουν να στριμώχνονται μεταξύ τους. Προεπιλογή 120, μέγιστο <?= MAX_GRID_CELLS ?>.</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Μονάδα Έκτασης Action Room</label>
                        <select class="form-select" style="max-width:260px;" name="war_room_area_unit">
                            <option value="auto" <?= ($settings['war_room_area_unit'] ?? 'auto') === 'auto' ? 'selected' : '' ?>>Αυτόματη επιλογή</option>
                            <option value="mid" <?= ($settings['war_room_area_unit'] ?? 'auto') === 'mid' ? 'selected' : '' ?>>Πάντα στρέμματα</option>
                            <option value="m2" <?= ($settings['war_room_area_unit'] ?? 'auto') === 'm2' ? 'selected' : '' ?>>Πάντα τετραγωνικά μέτρα</option>
                        </select>
                        <small class="text-muted">Σε ποια μονάδα εμφανίζεται η έκταση μιας περιοχής έρευνας ή ενός τομέα — όσο τη σχεδιάζετε, στο «Αυτόματο πλέγμα», στη διαίρεση σε τομείς και στα popup του χάρτη. <strong>Αυτόματη επιλογή:</strong> τετραγωνικά μέτρα κάτω από 10 στρέμματα, στρέμματα μέχρι το 1 τ.χλμ., τετραγωνικά χιλιόμετρα πάνω από εκεί — και όσα νούμερα εμφανίζονται μαζί μοιράζονται πάντα την ίδια μονάδα, αυτή του μικρότερου, ώστε να συγκρίνονται με τη μία. <strong>Πάντα στρέμματα:</strong> ποτέ τ.χλμ., οπότε μια περιοχή 35 τ.χλμ. γράφει 35.604 στρ. <strong>Πάντα τετραγωνικά μέτρα:</strong> η ίδια περιοχή γράφει 35.604.000 τ.μ. — διαβάζεται δύσκολα σε μεγάλες περιοχές, αλλά είναι η μονάδα που ζητούν κάποιες υπηρεσίες σε αναφορά. Σε αγγλικό περιβάλλον η μεσαία μονάδα είναι εκτάρια (1 εκτάριο = 10 στρέμματα).</small>
                    </div>

                    <hr class="my-4">
                    <h6 class="fw-bold mb-2"><i class="bi bi-heart-pulse me-1"></i>Καρδιακοί Παλμοί Διασώστη</h6>
                    <p class="text-muted small">
                        Ζωντανή ένδειξη παλμών δίπλα στο όνομα κάθε εθελοντή στο Action Room, και αναλυτική καμπύλη παλμών ανά εθελοντή στην αναφορά μετά την αποστολή — από τις ίδιες μετρήσεις.
                        Χρειάζεται αισθητήρα με <strong>τυπικό Bluetooth LE Heart Rate Service</strong>: ζώνη στήθους ή περιβραχιόνιο (Polar, Garmin, Wahoo, ή οικονομικές ζώνες), ή ρολόι/band Huawei σε λειτουργία «Εκπομπή καρδιακών παλμών» (Ρυθμίσεις → HR Data Broadcasts — δεν το διαθέτουν όλα τα μοντέλα).
                        Οι παλμοί είναι <strong>δεδομένα υγείας (άρθρο 9 GDPR)</strong>: τους βλέπει μόνο το επιτελείο και ο ίδιος ο εθελοντής, ποτέ οι υπόλοιποι εθελοντές. Χρειάζεται ρητή συγκατάθεση κάθε εθελοντή πριν φορέσει αισθητήρα.
                    </p>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="vitals_enabled" id="vitalsEnabled"
                               <?= ($settings['vitals_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="vitalsEnabled">
                            Ενεργοποίηση παρακολούθησης καρδιακών παλμών
                        </label>
                        <div><small class="text-muted">Όσο είναι κλειστό, η εφαρμογή δεν ζητά, δεν αποθηκεύει και δεν εμφανίζει καμία μέτρηση παλμών — ούτε εκτελεί επιπλέον ερώτημα στη βάση.</small></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Συχνότητα Καταγραφής (δευτ.)</label>
                        <input type="number" class="form-control" style="max-width:160px;" name="vitals_sample_seconds"
                               value="<?= h($settings['vitals_sample_seconds'] ?? '5') ?>" min="1" max="60" step="1">
                        <small class="text-muted">Ο αισθητήρας στέλνει μέτρηση κάθε δευτερόλεπτο· εδώ ορίζεται πόσα δευτερόλεπτα συμπυκνώνονται σε μία αποθηκευμένη τιμή. Προεπιλογή 5 δευτ. — αρκετά πυκνό για την καμπύλη της αναφοράς, χωρίς να γράφει ~21.600 γραμμές ανά εθελοντή σε βάρδια 6 ωρών.</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Όριο «Αυξημένων» Παλμών (% μέγιστης καρδιακής συχνότητας)</label>
                        <input type="number" class="form-control" style="max-width:160px;" name="vitals_elevated_pct"
                               value="<?= h($settings['vitals_elevated_pct'] ?? '75') ?>" min="40" max="100" step="1">
                        <small class="text-muted">Πάνω από αυτό το ποσοστό η ένδειξη γίνεται πορτοκαλί. Η μέγιστη καρδιακή συχνότητα υπολογίζεται ως 220 − ηλικία αναφοράς (βλ. παρακάτω).</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Ηλικία Αναφοράς (έτη)</label>
                        <input type="number" class="form-control" style="max-width:160px;" name="vitals_reference_age"
                               value="<?= h($settings['vitals_reference_age'] ?? '40') ?>" min="16" max="90" step="1">
                        <small class="text-muted">Η εφαρμογή <strong>δεν αποθηκεύει ημερομηνία γέννησης εθελοντή</strong> (υπάρχει μόνο στις αιτήσεις υποψηφίων και στους πολίτες), οπότε τα όρια ζωνών υπολογίζονται με κοινή ηλικία αναφοράς για όλους: μέγιστη καρδιακή συχνότητα = 220 − αυτή η τιμή. Με 40 έτη βγαίνει 180 bpm, άρα «αυξημένοι» στους 135 και «κρίσιμοι» στους ~158. Βάλτε τη μέση ηλικία του δικού σας μητρώου.</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Όριο «Κρίσιμων» Παλμών (% μέγιστης καρδιακής συχνότητας)</label>
                        <input type="number" class="form-control" style="max-width:160px;" name="vitals_critical_pct"
                               value="<?= h($settings['vitals_critical_pct'] ?? '88') ?>" min="50" max="100" step="1">
                        <small class="text-muted">Πάνω από αυτό το ποσοστό η ένδειξη γίνεται κόκκινη και αναβοσβήνει. Πρέπει να είναι μεγαλύτερο από το όριο των αυξημένων — αλλιώς διορθώνεται αυτόματα.</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Όριο Επικίνδυνα Χαμηλών Παλμών (bpm)</label>
                        <input type="number" class="form-control" style="max-width:160px;" name="vitals_low_bpm"
                               value="<?= h($settings['vitals_low_bpm'] ?? '40') ?>" min="25" max="60" step="1">
                        <small class="text-muted">Απόλυτο όριο, όχι ποσοστό: η βραδυκαρδία είναι το ίδιο επικίνδυνη σε κάθε ηλικία. Κάτω από αυτό η ένδειξη γίνεται μπλε — σκόπιμα διαφορετικό χρώμα από το κόκκινο των υψηλών, ώστε να ξεχωρίζει από απόσταση ποιο από τα δύο συμβαίνει.</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Όριο Παλαιότητας Μέτρησης (δευτ.)</label>
                        <input type="number" class="form-control" style="max-width:160px;" name="vitals_stale_seconds"
                               value="<?= h($settings['vitals_stale_seconds'] ?? '120') ?>" min="30" max="1800" step="10">
                        <small class="text-muted">Μετά από τόση ώρα χωρίς νέα μέτρηση, η ένδειξη γκριζάρει ως «χωρίς σήμα». Σκόπιμα μικρότερο από το αντίστοιχο όριο του GPS: αισθητήρας που σταμάτησε σημαίνει συνήθως ότι έφυγε η ζώνη ή κόπηκε το Bluetooth, και αυτό το θέλει γρήγορα το επιτελείο.</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Διατήρηση Μετρήσεων (ημέρες)</label>
                        <input type="number" class="form-control" style="max-width:160px;" name="vitals_retention_days"
                               value="<?= h($settings['vitals_retention_days'] ?? '365') ?>" min="7" max="3650" step="1">
                        <small class="text-muted">Μετά από τόσες ημέρες οι μετρήσεις διαγράφονται αυτόματα. Είναι ο πυκνότερος πίνακας της εφαρμογής και ταυτόχρονα δεδομένα υγείας — κρατήστε τον όσο χρειάζεται για τις αναφορές των αποστολών, όχι περισσότερο.</small>
                    </div>

                    <h6 class="fw-bold mt-4 mb-2">Κατώφλια Επεισοδίων (Αναφορά Παλμών)</h6>
                    <p class="text-muted small">
                        Ορίζουν πότε μια περίοδος καταγράφεται ως <strong>επεισόδιο</strong> στην Αναφορά Παλμών του Action Room.
                        Εδώ ρυθμίζετε <strong>μόνο τη διάρκεια</strong>: τα όρια σε bpm είναι τα ίδια ακριβώς που χρωματίζουν το badge και το στίγμα στον χάρτη (παραπάνω) — μία γραμμή ανά ζώνη για όλη την εφαρμογή, ώστε να μη γράφει ποτέ ο πίνακας «Φυσιολογικοί» δίπλα σε επεισόδιο «Βραδυκαρδία».
                        Η διάρκεια είναι που ξεχωρίζει το σήμα από τον θόρυβο: διασώστης που ανεβαίνει πλαγιά με εξοπλισμό αγγίζει στιγμιαία το όριο συνέχεια — δέκα λεπτά <em>πάνω</em> από αυτό είναι εντελώς άλλη δήλωση. Αν τις χαλαρώσετε, η σελίδα θα είναι μόνιμα κόκκινη και θα πάψει να σημαίνει κάτι.
                    </p>
                    <?php $__vc = vitalsConfig(); ?>
                    <p class="small mb-2">
                        Με τις τρέχουσες ρυθμίσεις: <strong>ταχυκαρδία ≥ <?= (int) $__vc['tachy_bpm'] ?> bpm</strong> ·
                        <strong>βραδυκαρδία ≤ <?= (int) $__vc['brady_bpm'] ?> bpm</strong> ·
                        <strong>καταπόνηση ≥ <?= vitalsZoneBpm($__vc['elevated_pct']) ?> bpm</strong>.
                        Για να γίνει η ταχυκαρδία π.χ. 150 bpm, αλλάξτε το ποσοστό «κρίσιμων» παραπάνω.
                    </p>
                    <div class="row g-3 mb-3">
                        <div class="col-6 col-md-3">
                            <label class="form-label small">Ταχυκαρδία: λεπτά</label>
                            <input type="number" class="form-control" name="vitals_episode_tachy_minutes" value="<?= h($settings['vitals_episode_tachy_minutes'] ?? '10') ?>" min="1" max="120" step="1">
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label small">Βραδυκαρδία: λεπτά</label>
                            <input type="number" class="form-control" name="vitals_episode_brady_minutes" value="<?= h($settings['vitals_episode_brady_minutes'] ?? '5') ?>" min="1" max="120" step="1">
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label small">Παρατεταμένη καταπόνηση: λεπτά</label>
                            <input type="number" class="form-control" name="vitals_episode_strain_minutes" value="<?= h($settings['vitals_episode_strain_minutes'] ?? '20') ?>" min="5" max="240" step="1">
                            <small class="text-muted">Συνεχόμενος χρόνος πάνω από το όριο «αυξημένων» παλμών.</small>
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
                        <input class="form-check-input" type="checkbox" name="show_register_button" id="showRegBtn"
                               <?= ($settings['show_register_button'] ?? '0') === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="showRegBtn">
                            Εμφάνιση κουμπιού εγγραφής στη σελίδα σύνδεσης
                        </label>
                        <div class="form-text">Αν είναι απενεργοποιημένο, οι χρήστες δεν βλέπουν σύνδεσμο εγγραφής στο login.</div>
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
                    <hr>
                    <div class="mb-0">
                        <label class="form-label" for="sessionTimeout"><i class="bi bi-clock-history me-1"></i>Αυτόματη αποσύνδεση μετά από αδράνεια (λεπτά)</label>
                        <input type="number" class="form-control" name="session_timeout_minutes" id="sessionTimeout"
                               value="<?= h($settings['session_timeout_minutes'] ?? '120') ?>" min="5" max="1440" style="max-width:200px;">
                        <div class="form-text">Αν ο χρήστης είναι ανενεργός για τόσα λεπτά, αποσυνδέεται αυτόματα. (5-1440 λεπτά)</div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="col-lg-6">
            <!-- Points Settings -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-star me-1"></i>Ρυθμίσεις Πόντων & Επιτευγμάτων</h5>
                </div>
                <div class="card-body">
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="points_enabled" id="pointsEnabled"
                               <?= $settings['points_enabled'] === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="pointsEnabled">
                            <strong>Ενεργοποίηση Συστήματος Πόντων</strong>
                        </label>
                        <div class="form-text">Αν απενεργοποιηθεί, οι πόντοι, η κατάταξη (leaderboard) και οι σχετικές στατιστικές κρύβονται από όλες τις σελίδες. Τα δεδομένα διατηρούνται.</div>
                    </div>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="achievements_enabled" id="achievementsEnabled"
                               <?= $settings['achievements_enabled'] === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="achievementsEnabled">
                            <strong>Ενεργοποίηση Επιτευγμάτων (Badges)</strong>
                        </label>
                        <div class="form-text">Αν απενεργοποιηθεί, τα επιτεύγματα κρύβονται από όλες τις σελίδες. Τα δεδομένα διατηρούνται.</div>
                    </div>
                    <hr>
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

            <!-- Weather API Settings -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-cloud-sun me-1"></i>Ρυθμίσεις Καιρού</h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label" for="weatherApiKey">OpenWeatherMap API Key</label>
                        <div class="input-group">
                            <input type="password" class="form-control" id="weatherApiKey"
                                   name="openweathermap_api_key"
                                   autocomplete="new-password"
                                   value="<?= h($settings['openweathermap_api_key'] ?? '') ?>"
                                   placeholder="Εισάγετε το API key σας">
                            <button type="button" class="btn btn-outline-secondary" onclick="toggleKeyVisibility('weatherApiKey')" tabindex="-1">
                                <i class="bi bi-eye" id="eye-weatherApiKey"></i>
                            </button>
                        </div>
                        <div class="form-text">
                            Απαιτείται για την εμφάνιση πρόβλεψης καιρού στις αποστολές.
                            <a href="https://openweathermap.org/appid" target="_blank" rel="noopener noreferrer">Δωρεάν εγγραφή στο OpenWeatherMap</a>
                        </div>
                    </div>
                    <?php if (!empty($settings['openweathermap_api_key'] ?? '')): ?>
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <div class="alert alert-success py-1 px-2 mb-0 small flex-grow-1">
                            <i class="bi bi-check-circle me-1"></i>API Key έχει οριστεί
                        </div>
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="btnTestWeatherKey">
                            <i class="bi bi-plug me-1"></i>Έλεγχος σύνδεσης
                        </button>
                    </div>
                    <div id="weatherTestResult" class="mt-2" style="display:none;"></div>
                    <?php else: ?>
                    <div class="alert alert-secondary py-1 px-2 mb-0 small">
                        <i class="bi bi-info-circle me-1"></i>Χωρίς API key η πρόβλεψη καιρού δεν εμφανίζεται στις αποστολές
                    </div>
                    <?php endif; ?>

                    <hr>

                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="weather_map_compass_enabled" id="weatherCompassEnabled"
                               <?= ($settings['weather_map_compass_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="weatherCompassEnabled">
                            <strong>Πυξίδα Ανέμου στον Χάρτη Action Room</strong>
                        </label>
                        <div class="form-text">Κατεύθυνση και ένταση ανέμου ως στοιχείο ελέγχου στον χάρτη κάθε αποστολής. Χρησιμοποιεί το ίδιο API key παραπάνω — καμία επιπλέον ρύθμιση.</div>
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="exposure_urgency_enabled" id="exposureUrgencyEnabled"
                               <?= ($settings['exposure_urgency_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="exposureUrgencyEnabled">
                            <strong>Ένδειξη Επείγοντος λόγω Έκθεσης</strong>
                        </label>
                        <div class="form-text">Μόνο σε αποστολές τύπου «Αγνοούμενο άτομο». Ενδεικτικός υπολογισμός από ηλικία, θερμοκρασία και άνεμο — <strong>όχι κλινική πρόγνωση</strong>. Προτείνεται έλεγχος πριν την ενεργοποίηση σε πραγματική επιχείρηση.</div>
                    </div>
                </div>
            </div>

            <!-- Route distance -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-signpost-split me-1"></i>Αποστάσεις Διαδρομής</h5>
                </div>
                <div class="card-body">
                    <p class="small text-muted mb-3">
                        Ο βοηθός του Action Room υπολογίζει πόσο απέχει κάθε άτομο από το σημείο,
                        τον τομέα ή την πορεία που του ανατέθηκε. Η <strong>ευθεία γραμμή</strong>
                        υπολογίζεται πάντα τοπικά και δεν χρειάζεται καμία ρύθμιση. Το πεδίο εδώ
                        αφορά μόνο την <strong>απόσταση διαδρομής</strong>.
                    </p>
                    <div class="mb-3">
                        <label class="form-label" for="googleMapsApiKey">Google Routes API Key <span class="text-muted">(προαιρετικό)</span></label>
                        <div class="input-group">
                            <input type="password" class="form-control" id="googleMapsApiKey"
                                   name="google_maps_api_key"
                                   autocomplete="new-password"
                                   value="<?= h($settings['google_maps_api_key'] ?? '') ?>"
                                   placeholder="Χωρίς κλειδί χρησιμοποιείται το OSRM">
                            <button type="button" class="btn btn-outline-secondary" onclick="toggleKeyVisibility('googleMapsApiKey')" tabindex="-1">
                                <i class="bi bi-eye" id="eye-googleMapsApiKey"></i>
                            </button>
                        </div>
                        <div class="form-text">
                            <a href="https://console.cloud.google.com/apis/library/routes.googleapis.com" target="_blank" rel="noopener noreferrer">Routes API στο Google Cloud</a>
                            — χρεώνεται στον δικό σας λογαριασμό.
                        </div>
                    </div>
                    <?php /* Which router is in use is not a detail an admin
                             should have to infer from whether a field is
                             empty: the two give materially different numbers
                             — a walking route up a monopati and a driving
                             route round the mountain are not the same answer
                             to "how far". */ ?>
                    <?php if (!empty($settings['google_maps_api_key'] ?? '')): ?>
                    <div class="alert alert-success py-2 px-2 mb-0 small">
                        <i class="bi bi-check-circle me-1"></i>
                        Σε χρήση: <strong>Google Routes</strong> με <strong>πεζοπορία</strong>.
                    </div>
                    <?php else: ?>
                    <div class="alert alert-secondary py-2 px-2 mb-0 small">
                        <i class="bi bi-info-circle me-1"></i>
                        Σε χρήση: <strong>OSRM</strong> (δωρεάν, χωρίς κλειδί) — υπολογίζει
                        <strong>οδικώς</strong> και κολλάει τη θέση στον κοντινότερο δρόμο, που
                        στο βουνό μπορεί να απέχει. Η ευθεία γραμμή δίπλα του παραμένει πάντα
                        το τίμιο νούμερο.
                    </div>
                    <?php endif; ?>
                    <div class="alert alert-warning py-2 px-2 mt-3 mb-0 small">
                        <i class="bi bi-shield-lock me-1"></i>
                        Στον δρομολογητή στέλνονται <strong>μόνο δύο ζεύγη συντεταγμένων</strong> —
                        κανένα όνομα, καμία ομάδα, κανένα αναγνωριστικό.
                    </div>
                </div>
            </div>

            <!-- AI Settings -->
            <?php
            $aiProviders    = aiProviders();
            $aiProviderKey  = $settings['ai_provider'] ?? 'gemini';
            if (!isset($aiProviders[$aiProviderKey])) $aiProviderKey = 'gemini';
            $aiHasAnyKey    = false;
            foreach (array_keys($aiProviders) as $pkCheck) {
                if (!empty($settings['ai_api_key_' . $pkCheck] ?? '')) { $aiHasAnyKey = true; break; }
            }
            ?>
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-stars me-1"></i>Ρυθμίσεις Τεχνητής Νοημοσύνης</h5>
                </div>
                <div class="card-body">
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="ai_enabled" id="aiEnabled"
                               <?= ($settings['ai_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="aiEnabled">
                            <strong>Ανάλυση Εμπειρογνώμονα στις Εκθέσεις Αποστολών</strong>
                        </label>
                        <div class="form-text">
                            Προσθέτει στην «Αξιολόγηση Παρατηρητή» μια αξιολόγηση των ομάδων, της αποστολής και του
                            συντονιστικού, γραμμένη από μοντέλο με βάση τα μετρημένα δεδομένα. Παράγεται μόνο με κουμπί
                            από διαχειριστή και αποθηκεύεται — δεν τρέχει ποτέ μόνη της και δεν επηρεάζει καμία βαθμολογία.
                        </div>
                    </div>

                    <div class="alert alert-warning py-2 px-3 small">
                        <i class="bi bi-shield-lock me-1"></i>
                        <strong>Τι στέλνεται:</strong> μόνο αριθμοί, κωδικά ονόματα ομάδων και ψευδώνυμα προσώπων
                        (ΜΕΛΟΣ-1, ΜΕΛΟΣ-2…). Ονόματα εθελοντών, τηλέφωνα, στοιχεία περιστατικών και συντεταγμένες
                        δεν φεύγουν ποτέ από τον server — ο έλεγχος είναι αυτόματος και μπλοκάρει την αποστολή αν
                        εντοπίσει οτιδήποτε τέτοιο.
                    </div>

                    <?php // Full width, not a two-column row: this card lives in the
                          // settings page's narrow right-hand column, and col-md-*
                          // keys off the VIEWPORT, so a side-by-side pair ends up
                          // about 165px wide even on a large desktop. ?>
                    <div class="mb-3">
                        <label class="form-label" for="aiProvider">Κύριος πάροχος</label>
                        <select class="form-select" name="ai_provider" id="aiProvider">
                            <?php foreach ($aiProviders as $pk => $pm): ?>
                            <option value="<?= h($pk) ?>" <?= $pk === $aiProviderKey ? 'selected' : '' ?>><?= h($pm['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text" id="aiProviderHint"></div>
                    </div>

                    <?php
                    // The failover order, shown rather than described: an admin
                    // needs to know at a glance which provider a busy primary
                    // will hand the work to.
                    //
                    // Built by calling the SAME function the real calls order
                    // themselves with, rather than repeating the rule here. The
                    // previous copy of it was already one release behind the
                    // moment ranking arrived, and a settings page that shows a
                    // different order from the one used is worse than showing
                    // nothing: it is the only place an admin can check.
                    $aiKeyed = [];
                    foreach (array_keys($aiProviders) as $ck) {
                        if (!empty($settings['ai_api_key_' . $ck] ?? '')) $aiKeyed[] = $ck;
                    }
                    $aiChainKeys = aiBuildChain($aiProviderKey, aiFailoverOrder(), $aiKeyed);
                    $aiChain = [];
                    foreach ($aiChainKeys as $ck) {
                        $aiChain[$ck] = $aiProviders[$ck]['label'];
                    }
                    // Whether the chain actually ends on a metered provider, so
                    // the "paid last" sentence is only shown when it describes
                    // this install and not as a general claim.
                    $aiLastKey = $aiChainKeys ? end($aiChainKeys) : null;
                    $aiMeteredLast = count($aiChainKeys) > 1 && $aiLastKey !== null
                        && ($aiProviders[$aiLastKey]['failover_rank'] ?? 50) >= 90;
                    ?>
                    <div class="alert <?= count($aiChain) > 1 ? 'alert-success' : 'alert-secondary' ?> py-2 px-3 small">
                        <i class="bi bi-arrow-repeat me-1"></i>
                        <?php if (count($aiChain) > 1): ?>
                        <strong>Αυτόματη εναλλαγή ενεργή.</strong> Σειρά: <?= h(implode(' → ', $aiChain)) ?>.
                        Αν ο πρώτος είναι υπερφορτωμένος (HTTP 503), σε υπέρβαση ορίου ή δεν απαντά, το αίτημα
                        πηγαίνει αυτόματα στον επόμενο. Η έκθεση καταγράφει ποιος την έγραψε τελικά.
                        <?php if ($aiMeteredLast): ?>
                        Ο <strong><?= h($aiProviders[$aiLastKey]['label']) ?></strong> χρεώνεται ανά κλήση, γι' αυτό μπαίνει
                        τελευταίος: τον πληρώνετε μόνο όταν όντως δεν απάντησε κανένας άλλος.
                        <?php endif; ?>
                        <?php else: ?>
                        <strong>Χωρίς εφεδρεία.</strong> Με key σε έναν μόνο πάροχο, μια υπερφόρτωση (HTTP 503)
                        σημαίνει ότι η ανάλυση δεν μπορεί να παραχθεί εκείνη τη στιγμή. Προσθέστε key και σε δεύτερο
                        πάροχο για αυτόματη εναλλαγή.
                        <?php endif; ?>
                    </div>

                    <?php
                    // Fallback blocks start open only when one of them already
                    // holds a key: an admin who has configured failover must
                    // see it on arrival, while one who has not is not shown
                    // fields for providers they are not using.
                    $aiFallbackConfigured = false;
                    foreach ($aiProviders as $pkF => $pmF) {
                        if ($pkF !== $aiProviderKey && !empty($settings['ai_api_key_' . $pkF] ?? '')) {
                            $aiFallbackConfigured = true;
                        }
                    }
                    ?>
                    <?php foreach ($aiProviders as $pk => $pm):
                        $stored = !empty($settings['ai_api_key_' . $pk] ?? ''); ?>
                    <?php // A bordered card per provider, not an <hr> between runs of
                          // fields: when two or three of these are open at once, the
                          // fields of one provider have to be visibly INSIDE that
                          // provider, or an admin reads the wrong Μοντέλο line and
                          // believes the app is offering another provider's models. ?>
                    <div class="ai-provider-block card mb-3" data-ai-provider="<?= h($pk) ?>">
                    <div class="card-header py-2 fw-bold">
                        <?= h($pm['label']) ?>
                        <span class="badge bg-primary ms-1" data-ai-badge="primary" hidden>κύριος</span>
                        <span class="badge bg-success-subtle text-success-emphasis ms-1" data-ai-badge="fallback" hidden>εφεδρεία</span>
                    </div>
                    <div class="card-body py-2">
                    <div class="mb-2">
                        <label class="form-label small mb-1" for="aiKey_<?= h($pk) ?>">API Key</label>
                        <div class="input-group">
                            <input type="password" class="form-control" id="aiKey_<?= h($pk) ?>"
                                   name="ai_api_key_<?= h($pk) ?>" autocomplete="new-password" value=""
                                   placeholder="<?= $stored ? 'Αποθηκευμένο — αφήστε κενό για να παραμείνει' : 'Εισάγετε το API key σας' ?>">
                            <button type="button" class="btn btn-outline-secondary" onclick="toggleKeyVisibility('aiKey_<?= h($pk) ?>')" tabindex="-1">
                                <i class="bi bi-eye" id="eye-aiKey_<?= h($pk) ?>"></i>
                            </button>
                        </div>
                        <div class="form-text">
                            <?php if ($stored): ?><i class="bi bi-check-circle text-success me-1"></i>Έχει οριστεί key. <?php endif; ?>
                            <?= h($pm['key_hint']) ?>
                            <a href="<?= h($pm['key_url']) ?>" target="_blank" rel="noopener noreferrer">Λήψη key</a>.
                            Επεξεργασία δεδομένων: <?= h($pm['jurisdiction']) ?>.
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small mb-1" for="aiModel_<?= h($pk) ?>">Μοντέλο</label>
                        <input type="text" class="form-control form-control-sm" id="aiModel_<?= h($pk) ?>"
                               name="ai_model_<?= h($pk) ?>" list="aiModels_<?= h($pk) ?>" autocomplete="off"
                               placeholder="<?= h($pm['default_model']) ?>"
                               value="<?= h($settings['ai_model_' . $pk] ?? '') ?>">
                        <datalist id="aiModels_<?= h($pk) ?>">
                            <?php foreach ($pm['models'] as $mName): ?><option value="<?= h($mName) ?>"><?php endforeach; ?>
                        </datalist>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small mb-1" for="aiBaseUrl_<?= h($pk) ?>">Base URL <span class="text-muted fw-normal">(προχωρημένο)</span></label>
                        <input type="text" class="form-control form-control-sm" id="aiBaseUrl_<?= h($pk) ?>"
                               name="ai_base_url_<?= h($pk) ?>" autocomplete="off"
                               placeholder="<?= h($pm['base_url']) ?>"
                               value="<?= h($settings['ai_base_url_' . $pk] ?? '') ?>">
                    </div>
                    </div>
                    </div>
                    <?php endforeach; ?>

                    <?php // Hidden blocks keep submitting their stored values, and an
                          // untouched key field posts empty which the save handler
                          // reads as "keep existing" — so collapsing a provider can
                          // never erase its configuration. ?>
                    <button type="button" class="btn btn-sm btn-outline-secondary w-100 mb-2" id="aiToggleFallbacks"
                            aria-expanded="<?= $aiFallbackConfigured ? 'true' : 'false' ?>">
                        <i class="bi bi-chevron-down me-1"></i><span>Εφεδρικοί πάροχοι</span>
                    </button>
                    <hr>

                    <?php if (!$aiHasAnyKey): ?>
                    <div class="alert alert-secondary py-1 px-2 mb-2 small">
                        <i class="bi bi-info-circle me-1"></i>Χωρίς API key η ανάλυση δεν εμφανίζεται πουθενά στην εφαρμογή.
                    </div>
                    <?php endif; ?>
                    <?php // Always available, even before a first save: the endpoint takes
                          // the provider and key shown on screen, so a freshly pasted key
                          // can be proven before it is stored. ?>
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <div class="alert alert-secondary py-1 px-2 mb-0 small flex-grow-1">
                            <i class="bi bi-info-circle me-1"></i>Ελέγχεται ο πάροχος που είναι <strong>επιλεγμένος τώρα</strong>, με τα πεδία που βλέπετε — χωρίς αποθήκευση και χωρίς εναλλαγή σε άλλον.
                        </div>
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="btnTestAiKey">
                            <i class="bi bi-plug me-1"></i>Έλεγχος σύνδεσης
                        </button>
                    </div>
                    <div id="aiTestResult" class="mt-2" style="display:none;"></div>
                    <hr>

                    <?php /* The organisation's own doctrine, in its own words,
                             injected into every operational prompt. Without it
                             the assistant is a generic twenty-year rescuer: it
                             gives correct advice that is not THIS org's advice.
                             With it, "rotate the team" becomes "rotate at 90
                             minutes, which is your rule".

                             Capped rather than unlimited, and the cap is
                             enforced server-side too: this text is prepended to
                             every question, every handover and every drafted
                             order, so a page of it is paid for on every call. */ ?>
                    <div class="mb-2">
                        <label class="form-label" for="aiPlaybook">
                            <i class="bi bi-journal-text me-1"></i>Εγχειρίδιο οργανισμού
                            <span class="text-muted fw-normal">(προαιρετικό)</span>
                        </label>
                        <textarea class="form-control form-control-sm" id="aiPlaybook" name="ai_playbook"
                                  rows="6" maxlength="<?= AI_PLAYBOOK_CAP ?>"
                                  placeholder="π.χ. Εναλλαγή ομάδων κάθε 90 λεπτά. Διακοπή έρευνας μόνο με απόφαση του επικεφαλής βάρδιας. Στα φαράγγια ο ασύρματος χάνεται — ραντεβού επικοινωνίας κάθε 30′. Λέμε «σημείο συνάντησης», όχι «RV»."><?= h($settings['ai_playbook'] ?? '') ?></textarea>
                        <div class="form-text">
                            Πώς δουλεύει <strong>ο δικός σας</strong> οργανισμός: κανόνες εναλλαγής, ποιος αποφασίζει τι,
                            τοπικοί κίνδυνοι, ορολογία που χρησιμοποιείτε. Μπαίνει σε κάθε απάντηση του βοηθού στο Action Room,
                            στην παράδοση βάρδιας και στη διατύπωση εντολών.
                            <strong>Δεν υπερισχύει των ορίων ασφαλείας</strong> — ο βοηθός εξακολουθεί να μη στέλνει εντολές
                            και να μη δίνει ιατρικές οδηγίες.
                            Μην γράφετε ονόματα ή τηλέφωνα εδώ: το κείμενο φεύγει στον πάροχο AI αυτούσιο.
                            Έως <?= AI_PLAYBOOK_CAP ?> χαρακτήρες.
                        </div>
                    </div>
                </div>
            </div>

            <!-- LPB Search Rings Settings -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-bullseye me-1"></i>Ρυθμίσεις Ζωνών Αναζήτησης (LPB Rings)</h5>
                </div>
                <div class="card-body">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="search_rings_enabled" id="searchRingsEnabled"
                               <?= ($settings['search_rings_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="searchRingsEnabled">
                            <strong>Ενδεικτικές Ζώνες Αναζήτησης στον Χάρτη</strong>
                        </label>
                        <div class="form-text">
                            Μόνο σε αποστολές τύπου «Αγνοούμενο άτομο». Σχεδιάζει ομόκεντρους κύκλους γύρω από το σημείο τελευταίας θέασης, με ακτίνα ανάλογη της κατηγορίας ατόμου (παιδί, πεζοπόρος, άτομο με άνοια κ.λπ.). <strong>Ενδεικτικό εργαλείο σχεδιασμού, όχι επιχειρησιακή βεβαιότητα</strong> — οι αποστάσεις είναι κατά προσέγγιση τιμές από γενική βιβλιογραφία SAR, όχι επικυρωμένα δεδομένα. Συνιστάται έλεγχος από άτομο με εκπαίδευση SAR πριν τη χρήση σε πραγματική επιχείρηση.
                        </div>
                    </div>
                </div>
            </div>

            <!-- Telegram Bot Settings -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-telegram me-1"></i>Ρυθμίσεις Telegram (Άμεση Κινητοποίηση)</h5>
                </div>
                <div class="card-body">
                    <?php if (!isTelegramConfigured()): ?>
                    <div class="alert alert-info">
                        <strong><i class="bi bi-info-circle me-1"></i>Πώς να συνδέσετε ένα δωρεάν bot — βήμα βήμα:</strong>
                        <ol class="mt-2 mb-3 ps-3">
                            <li class="mb-2">
                                Πατήστε το κουμπί για να ανοίξει το Telegram με τον επίσημο «κατασκευαστή bot»:
                                <div class="mt-1">
                                    <a href="https://t.me/BotFather" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-primary">
                                        <i class="bi bi-telegram me-1"></i>Άνοιγμα @BotFather στο Telegram
                                    </a>
                                </div>
                            </li>
                            <li class="mb-2">Στη συνομιλία που θα ανοίξει, πατήστε <strong>START</strong> (ή γράψτε <code>/start</code>).</li>
                            <li class="mb-2">Γράψτε την εντολή <code>/newbot</code> και πατήστε αποστολή.</li>
                            <li class="mb-2">Θα σας ρωτήσει για ένα <strong>όνομα</strong> — γράψτε ό,τι θέλετε, π.χ. «<?= h(getSetting('app_name', APP_NAME)) ?> Ειδοποιήσεις». Αυτό θα το βλέπουν οι εθελοντές.</li>
                            <li class="mb-2">Μετά θα ζητήσει ένα <strong>username</strong> που πρέπει να τελειώνει σε <code>bot</code>, π.χ. <code>epidrasis_alerts_bot</code>. Αν σας πει ότι είναι πιασμένο, δοκιμάστε άλλο.</li>
                            <li class="mb-2">
                                Θα λάβετε μήνυμα «Done!» με έναν κωδικό (token) σαν κι αυτόν:
                                <div class="mt-1"><code>123456789:AAExampleTokenTextGoesHere1234</code></div>
                                Πατήστε πάνω του στο Telegram για να αντιγραφεί <strong>ολόκληρος</strong> αυτόματα.
                            </li>
                            <li>Επικολλήστε τον εδώ στο πεδίο <strong>«Telegram Bot Token»</strong> από κάτω, πατήστε <strong>«Αποθήκευση Ρυθμίσεων»</strong> στο τέλος της σελίδας, και θα τον ελέγξουμε αυτόματα.</li>
                        </ol>
                        <div class="small text-muted mb-0">Περίπου 2 λεπτά, χωρίς κάρτα ή εγγραφή κάπου — μόνο το ίδιο σας το Telegram.</div>
                    </div>
                    <?php endif; ?>
                    <div class="mb-3">
                        <label class="form-label" for="telegramBotToken">Telegram Bot Token</label>
                        <div class="input-group">
                            <input type="password" class="form-control" id="telegramBotToken"
                                   name="telegram_bot_token"
                                   autocomplete="new-password"
                                   value="<?= h($settings['telegram_bot_token'] ?? '') ?>"
                                   placeholder="π.χ. 123456789:AAExampleTokenTextGoesHere1234">
                            <button type="button" class="btn btn-outline-secondary" onclick="toggleKeyVisibility('telegramBotToken')" tabindex="-1">
                                <i class="bi bi-eye" id="eye-telegramBotToken"></i>
                            </button>
                        </div>
                    </div>
                    <?php if (isTelegramConfigured()): ?>
                    <div class="alert alert-success py-1 px-2 mb-0 small">
                        <i class="bi bi-check-circle me-1"></i>Συνδεδεμένο bot: @<?= h($settings['telegram_bot_username'] ?? '') ?>
                    </div>
                    <div class="form-check mt-2">
                        <input type="checkbox" name="telegram_bot_token_clear" value="1" class="form-check-input" id="telegramClear">
                        <label class="form-check-label" for="telegramClear"><span class="text-danger">Κατάργηση σύνδεσης bot</span></label>
                    </div>
                    <div class="mt-2">
                        <!-- Submits telegramReregisterForm, a standalone form placed after
                             this whole tab closes — nesting a form here would be invalid,
                             since this card already sits inside the "Αποθήκευση Ρυθμίσεων" form. -->
                        <button type="submit" form="telegramReregisterForm" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-repeat"></i> Επανεγγραφή Webhook</button>
                        <span class="form-text">Πατήστε το αν η σύνδεση εθελοντών δεν λειτουργεί παρόλο που το bot είναι συνδεδεμένο.</span>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-secondary py-1 px-2 mb-0 small">
                        <i class="bi bi-info-circle me-1"></i>Χωρίς bot, το κουμπί «Άμεση Κινητοποίηση» και η σύνδεση Telegram των εθελοντών δεν είναι διαθέσιμα.
                    </div>
                    <?php endif; ?>
                    <div class="form-text mt-2">
                        Μόλις αποθηκευτεί έγκυρο token, κάθε εθελοντής μπορεί να συνδέσει το δικό του Telegram από τις
                        <a href="notification-preferences.php">Ρυθμίσεις Ειδοποιήσεων</a> του, και αποκτάτε το κουμπί
                        <a href="mobilization.php">Άμεση Κινητοποίηση</a> για μαζικό μήνυμα σε όλους τους συνδεδεμένους — εντελώς δωρεάν, χωρίς όριο μηνυμάτων.
                    </div>
                </div>
            </div>
        </div>

            <!-- QR Check-in Settings -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-qr-code me-1"></i>QR Check-in Παρουσίας</h5>
                </div>
                <div class="card-body">
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" name="qr_checkin_enabled" id="qrCheckinEnabled"
                               <?= ($settings['qr_checkin_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="qrCheckinEnabled">
                            <strong>Ενεργοποίηση QR Check-in</strong>
                        </label>
                    </div>
                    <div class="form-text">
                        Όταν είναι ενεργό, κάθε βάρδια αποκτά μοναδικό QR κωδικό. Ο υπεύθυνος βάρδιας ανοίγει το QR από τη βάρδια και οι εθελοντές σκανάρουν για αυτόματο check-in παρουσίας.
                    </div>
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
<form id="telegramReregisterForm" method="post" class="d-none">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="telegram_reregister_webhook">
</form>
<?php endif; ?>

<!-- Left Menu Tab -->
<?php if ($activeTab === 'menu'): ?>
<?php
$menuPalette = sidebarPalette();
$menuState = sidebarDefaultState();
$menuColorsOn = sidebarColorsEnabled();
$menuStateOptions = [
    'current'   => ['Μόνο η τρέχουσα ενότητα', 'Ανοίγει η ενότητα της σελίδας που βλέπετε· οι υπόλοιπες μένουν διπλωμένες.'],
    'expanded'  => ['Όλες ανοιχτές', 'Ολόκληρο το μενού ανοιχτό, όπως ήταν πριν μπει το δίπλωμα.'],
    'collapsed' => ['Όλες διπλωμένες', 'Το πιο σύντομο μενού. Μια κουκκίδα στην επικεφαλίδα δείχνει σε ποια ενότητα βρίσκεστε.'],
];
?>
<form method="post">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="save_menu">

    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0"><i class="bi bi-list-nested me-1"></i>Άνοιγμα ενοτήτων</h5>
        </div>
        <div class="card-body">
            <p class="text-muted small">
                Πώς εμφανίζεται το πλαϊνό μενού μόλις φορτώσει μια σελίδα. Ό,τι ανοιγοκλείνει
                μετά ο κάθε χρήστης αποθηκεύεται στον browser του και υπερισχύει — μέχρι να
                αλλάξετε αυτή τη ρύθμιση, οπότε η νέα επιλογή εφαρμόζεται ξανά σε όλους.
            </p>
            <?php foreach ($menuStateOptions as $stateValue => $stateMeta): ?>
            <div class="form-check mb-2">
                <input class="form-check-input" type="radio" name="sidebar_default_state"
                       id="menuState<?= h($stateValue) ?>" value="<?= h($stateValue) ?>"
                       <?= $menuState === $stateValue ? 'checked' : '' ?>>
                <label class="form-check-label" for="menuState<?= h($stateValue) ?>">
                    <span class="fw-semibold"><?= h($stateMeta[0]) ?></span>
                    <span class="d-block small text-muted"><?= h($stateMeta[1]) ?></span>
                </label>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="bi bi-palette me-1"></i>Χρώματα ενοτήτων</h5>
            <button type="submit" name="reset_palette" value="1" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-counterclockwise me-1"></i>Επαναφορά προεπιλογών
            </button>
        </div>
        <div class="card-body">
            <p class="text-muted small">
                Διαλέγετε ένα χρώμα ανά ενότητα. Ο δεύτερος, πιο ανοιχτός τόνος που διαβάζεται
                πάνω στο μπλε του μενού υπολογίζεται μόνος του: φωτίζεται όσο χρειάζεται για να
                περάσει το 4,5:1 και ούτε βήμα παραπάνω, ώστε να μη χάνεται η απόχρωση.
                Αποφύγετε μπλε και γαλάζιο — χάνονται πάνω στο φόντο του μενού.
            </p>
            <div class="form-check form-switch mb-3 pb-3 border-bottom">
                <input class="form-check-input" type="checkbox" role="switch" value="1"
                       id="sidebarColorsEnabled" name="sidebar_colors_enabled"
                       <?= $menuColorsOn ? 'checked' : '' ?>>
                <label class="form-check-label" for="sidebarColorsEnabled">
                    <span class="fw-semibold">Χρωματισμός ενοτήτων</span>
                    <span class="d-block small text-muted">
                        Κλειστός, το μενού επιστρέφει στην αρχική του εμφάνιση: όλα στο ίδιο
                        μπλε, χωρίς χρωματιστές ζώνες. Το δίπλωμα των ενοτήτων δεν επηρεάζεται
                        και τα χρώματα παρακάτω μένουν αποθηκευμένα για όταν το ξανανοίξετε.
                    </span>
                </label>
            </div>
            <div class="row g-4">
                <div class="col-lg-7">
                    <?php foreach (sidebarSections() as $secKey => $secLabel): ?>
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <input type="color" class="form-control form-control-color flex-shrink-0"
                               name="sidebar_color[<?= h($secKey) ?>]"
                               value="<?= h($menuPalette[$secKey]) ?>"
                               data-menu-color="<?= h($secKey) ?>"
                               title="<?= h($secLabel) ?>" style="width:2.6rem;">
                        <span class="flex-grow-1"><?= h($secLabel) ?></span>
                        <code class="small text-muted" data-menu-hex="<?= h($secKey) ?>"><?= h($menuPalette[$secKey]) ?></code>
                        <span class="badge bg-light text-dark border" data-menu-ratio="<?= h($secKey) ?>">—</span>
                    </div>
                    <?php endforeach; ?>
                    <button type="submit" class="btn btn-primary mt-3">
                        <i class="bi bi-check-lg me-1"></i>Αποθήκευση
                    </button>
                </div>
                <div class="col-lg-5">
                    <div class="small text-muted mb-2">Προεπισκόπηση</div>
                    <ul class="nav flex-column sticky-lg-top" id="menuPreview"
                        style="top:1rem;background:linear-gradient(180deg,#1e3c72 0%,#2a5298 50%,#1e3c72 100%);border-radius:8px;padding:0.4rem 0;"></ul>
                    <div class="small text-muted mt-2">
                        Ο αριθμός δίπλα σε κάθε χρώμα είναι η μετρούμενη αντίθεση της
                        επικεφαλίδας. Δεν πέφτει ποτέ κάτω από 4,5:1, όσο σκούρο χρώμα κι αν
                        διαλέξετε.
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
(function () {
    /* The numbers come from sidebarToneConfig() rather than being retyped, so
       the preview and the real menu can never disagree about the layers or the
       target. Only the walk below exists twice — it mirrors
       sidebarReadableTone() in includes/sidebar-theme.php; change one, change
       the other. */
    var CFG = <?= json_encode(sidebarToneConfig()) ?>;
    var SECTIONS = <?= json_encode(sidebarSections()) ?>;

    function hexToRgb(hex) {
        hex = hex.replace('#', '');
        return [
            parseInt(hex.slice(0, 2), 16),
            parseInt(hex.slice(2, 4), 16),
            parseInt(hex.slice(4, 6), 16)
        ];
    }
    function blend(rgb, alpha, over) {
        return [0, 1, 2].map(function (i) {
            return Math.round(rgb[i] * alpha + over[i] * (1 - alpha));
        });
    }
    function luminance(rgb) {
        var lin = rgb.map(function (v) {
            v /= 255;
            return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
        });
        return 0.2126 * lin[0] + 0.7152 * lin[1] + 0.0722 * lin[2];
    }
    function contrast(a, b) {
        var la = luminance(a), lb = luminance(b);
        return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05);
    }
    function headingBand(rgb) {
        var ground = hexToRgb(CFG.ground);
        return blend([0, 0, 0], CFG.darken,
               blend(rgb, CFG.strip, blend(rgb, CFG.body, ground)));
    }
    function readableTone(rgb) {
        var band = headingBand(rgb);
        for (var step = 0; step <= CFG.steps; step++) {
            var t = step / CFG.steps;
            var candidate = [0, 1, 2].map(function (i) {
                return Math.round(rgb[i] + (255 - rgb[i]) * t);
            });
            if (contrast(candidate, band) >= CFG.target) { return candidate; }
        }
        return [255, 255, 255];
    }

    var preview = document.getElementById('menuPreview');
    var inputs = Array.prototype.slice.call(document.querySelectorAll('[data-menu-color]'));
    if (!preview || !inputs.length) { return; }

    function render() {
        preview.innerHTML = '';
        inputs.forEach(function (input, index) {
            var key = input.getAttribute('data-menu-color');
            var rgb = hexToRgb(input.value);
            var tone = readableTone(rgb);

            document.querySelector('[data-menu-hex="' + key + '"]').textContent = input.value;
            document.querySelector('[data-menu-ratio="' + key + '"]').textContent =
                contrast(tone, headingBand(rgb)).toFixed(2).replace('.', ',') + ':1';

            var block = document.createElement('li');
            block.className = 'sidebar-sec' + (index === 0 ? ' sidebar-sec--plain' : ' collapsed');
            block.style.setProperty('--sc', rgb.join(','));
            block.style.setProperty('--sl', tone.join(','));

            if (index === 0) {
                var link = document.createElement('a');
                link.className = 'nav-link';
                link.innerHTML = '<i class="bi bi-speedometer2"></i>';
                link.appendChild(document.createTextNode(' Πίνακας Ελέγχου'));
                block.appendChild(link);
            } else {
                var head = document.createElement('button');
                head.type = 'button';
                head.className = 'sidebar-sec-h';
                head.innerHTML = '<span class="sec-t"></span>'
                               + '<i class="bi bi-chevron-down sec-chev"></i>';
                head.querySelector('.sec-t').textContent = SECTIONS[key];
                block.appendChild(head);
            }
            preview.appendChild(block);
        });
    }

    /* The preview borrows .sidebar-mono from header.php rather than restating
       what "colour off" looks like, so the two cannot drift apart. */
    var toggle = document.getElementById('sidebarColorsEnabled');

    function applyMode() {
        var on = !toggle || toggle.checked;
        preview.classList.toggle('sidebar-mono', !on);
        /* Dimmed but deliberately still live and still submitting: disabling
           them would post nothing, and the handler reads a missing colour as
           "fall back to the shipped one" - so saving with the switch off would
           quietly wipe a palette the admin spent time on. */
        inputs.forEach(function (input) {
            input.parentNode.style.opacity = on ? '' : '0.5';
        });
    }

    inputs.forEach(function (input) { input.addEventListener('input', render); });
    if (toggle) { toggle.addEventListener('change', applyMode); }
    render();
    applyMode();
})();
</script>
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
                                   autocomplete="new-password"
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

<!-- Live Streaming (LiveKit) Tab -->
<?php if ($activeTab === 'livekit'): ?>
<?php
$lkConfigured = livekitConfigured();
$lkSiteKey    = trim((string) getSetting('livekit_site_key', ''));
?>
<div class="row">
    <div class="col-lg-8">
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-broadcast me-1"></i>Ζωντανή Μετάδοση (LiveKit)</h5>
                <?php if ($lkConfigured): ?>
                    <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i>Ρυθμισμένο</span>
                <?php else: ?>
                    <span class="badge bg-secondary"><i class="bi bi-dash-circle me-1"></i>Ανενεργό</span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <p class="text-muted small">
                    Επιτρέπει στο Επιχειρησιακό να ζητήσει ζωντανή εικόνα από εθελοντή στο πεδίο.
                    Χωρίς αυτές τις ρυθμίσεις η λειτουργία δεν εμφανίζεται πουθενά — δεν χαλάει τίποτα,
                    απλώς δεν υπάρχει.
                </p>

                <form method="post">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="save_livekit">

                    <div class="mb-3">
                        <label class="form-label" for="livekit_url">URL διακομιστή</label>
                        <input type="text" class="form-control" id="livekit_url" name="livekit_url"
                               placeholder="wss://example.livekit.cloud" spellcheck="false"
                               value="<?= h($settings['livekit_url'] ?? '') ?>">
                        <div class="form-text">Από το LiveKit Cloud, στο project σας. Ξεκινά με <code>wss://</code></div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="livekit_api_key">API Key</label>
                        <input type="text" class="form-control" id="livekit_api_key" name="livekit_api_key"
                               autocomplete="off" spellcheck="false"
                               value="<?= h($settings['livekit_api_key'] ?? '') ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="livekit_api_secret">API Secret</label>
                        <input type="password" class="form-control" id="livekit_api_secret" name="livekit_api_secret"
                               autocomplete="new-password" spellcheck="false" value="">
                        <?php if (!empty($settings['livekit_api_secret'] ?? '')): ?>
                            <div class="form-text text-success">
                                <i class="bi bi-check-circle me-1"></i>Έχει αποθηκευτεί. Αφήστε το κενό για να μείνει ως έχει.
                            </div>
                        <?php else: ?>
                            <div class="form-text">Το secret που αντιστοιχεί στο παραπάνω key.</div>
                        <?php endif; ?>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="livekit_quality">Ποιότητα μετάδοσης</label>
                        <?php $lkQ = (string) ($settings['livekit_quality'] ?? 'auto'); ?>
                        <select class="form-select" id="livekit_quality" name="livekit_quality">
                            <option value="auto" <?= $lkQ === 'auto' ? 'selected' : '' ?>>Αυτόματο — προσαρμόζεται στη γραμμή (προεπιλογή)</option>
                            <option value="360" <?= $lkQ === '360' ? 'selected' : '' ?>>360p — ανθεκτικό σε κακό σήμα</option>
                            <option value="540" <?= $lkQ === '540' ? 'selected' : '' ?>>540p — ισορροπία</option>
                            <option value="720" <?= $lkQ === '720' ? 'selected' : '' ?>>720p — καθαρή εικόνα, θέλει καλή γραμμή</option>
                        </select>
                        <div class="form-text">
                            Ο ρυθμός προσαρμόζεται συνεχώς στη γραμμή <strong>σε κάθε επιλογή</strong> —
                            αυτό που ορίζετε εδώ είναι πόσο ψηλά επιτρέπεται να στοχεύσει.
                            Στο <strong>Αυτόματο</strong> ξεκινά στα 540p και κατεβαίνει μία φορά στα 360p
                            αν η σύνδεση παραμείνει κακή. Σε αδύναμο σήμα διατηρείται η ομαλή κίνηση
                            αντί της λεπτομέρειας — για κάποιον που περπατά δείχνοντας έδαφος, η κίνηση
                            βοηθά τον προσανατολισμό περισσότερο.
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label" for="livekit_codec">Κωδικοποίηση βίντεο</label>
                        <?php $lkC = (string) ($settings['livekit_codec'] ?? 'vp8'); ?>
                        <select class="form-select" id="livekit_codec" name="livekit_codec">
                            <option value="vp8" <?= $lkC === 'vp8' ? 'selected' : '' ?>>VP8 — συμβατό παντού (προεπιλογή)</option>
                            <option value="h264" <?= $lkC === 'h264' ? 'selected' : '' ?>>H.264 — λιγότερη ζέστη στο κινητό</option>
                            <option value="vp9" <?= $lkC === 'vp9' ? 'selected' : '' ?>>VP9 — καλύτερη εικόνα, πιο βαρύ στο κινητό</option>
                        </select>
                        <div class="form-text">
                            Ποιο αποδίδει καλύτερα εξαρτάται από τις <strong>δικές σας συσκευές</strong>, γι' αυτό
                            είναι επιλογή και όχι σταθερά. Το H.264 κωδικοποιείται από το υλικό σχεδόν κάθε κινητού,
                            άρα ζεσταίνεται λιγότερο — και η ζέστη είναι ανεξάρτητη αιτία κολλήματος μετά από λίγα
                            λεπτά. Το VP9 δίνει καλύτερη εικόνα στον ίδιο ρυθμό αλλά συχνά κωδικοποιείται σε
                            λογισμικό. Δοκιμάστε τα στο ίδιο σημείο πεδίου πριν αποφασίσετε.
                            <br><strong>Εξαίρεση:</strong> σε iPhone και iPad χρησιμοποιείται πάντα H.264,
                            ανεξάρτητα από την επιλογή εδώ. Είναι το μόνο που κωδικοποιεί το υλικό τους·
                            με οτιδήποτε άλλο η μετάδοση δεν φτάνει στο Επιχειρησιακό.
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save me-1"></i>Αποθήκευση
                    </button>
                </form>

                <?php if ($lkConfigured): ?>
                <hr>
                <form method="post" class="mb-0">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="test_livekit">
                    <button type="submit" class="btn btn-outline-secondary">
                        <i class="bi bi-plug me-1"></i>Δοκιμή σύνδεσης
                    </button>
                    <span class="form-text ms-2">Επαληθεύει URL, key και secret μαζί.</span>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-info-circle me-1"></i>Πληροφορίες</h5>
            </div>
            <div class="card-body">
                <table class="table table-sm mb-3">
                    <tr>
                        <td>Όριο ανά μετάδοση</td>
                        <td><strong><?= (int) round(MISSION_LIVE_MAX_SECONDS / 60) ?>′</strong></td>
                    </tr>
                    <tr>
                        <td>Ποιότητα</td>
                        <td><?php $lkP = livekitQualityProfile(); ?><strong><?= $lkP['auto'] ? 'Αυτόματο' : ((int) $lkP['height'] . 'p') ?></strong> <span class="text-muted">/ έως <?= (int) round($lkP['maxBitrate'] / 1000) ?> kb</span></td>
                    </tr>
                    <tr>
                        <td>Κωδικοποίηση</td>
                        <td><strong><?= h(strtoupper($lkP['codec'])) ?></strong></td>
                    </tr>
                    <tr>
                        <td>Κλειδί εγκατάστασης</td>
                        <td><code><?= h($lkSiteKey !== '' ? $lkSiteKey : '—') ?></code></td>
                    </tr>
                </table>
                <p class="small text-muted mb-0">
                    Το κλειδί εγκατάστασης μπαίνει μπροστά από κάθε όνομα δωματίου. Υπάρχει επειδή οι
                    κωδικοί αποστολών είναι ανά βάση: δύο διαφορετικές εγκαταστάσεις έχουν και οι δύο
                    αποστολή με τον ίδιο αριθμό, και χωρίς αυτό το κλειδί θα κατέληγαν στο ίδιο δωμάτιο.
                    Παράγεται αυτόματα και <strong>δεν πρέπει να αλλάξει</strong> όσο τρέχει αποστολή.
                </p>
            </div>
        </div>

        <div class="card mb-4 border-warning">
            <div class="card-header bg-warning bg-opacity-25">
                <h6 class="mb-0"><i class="bi bi-shield-exclamation me-1"></i>Ιδιωτικότητα</h6>
            </div>
            <div class="card-body small text-muted">
                Οι ροές <strong>δεν καταγράφονται</strong>. Τις βλέπει μόνο το Επιχειρησιακό, ο εθελοντής
                αποδέχεται πάντα ρητά, και μπορεί να σταματήσει τη μετάδοση ή να κόψει το μικρόφωνο
                οποιαδήποτε στιγμή.
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
    'citizen_cert_expiry' => ['label' => 'Λήξη Πιστ/κών Πολιτών',   'icon' => 'bi-person-vcard',       'desc' => 'Στέλνει ειδοποιήσεις λήξης πιστοποιητικών πολιτών (3μ, 1μ, 1εβδ, ληγμένα).', 'color' => 'secondary'],
    'shelf_expiry'        => ['label' => 'Λήξη Υλικών Ραφιού',      'icon' => 'bi-box-seam',           'desc' => 'Ελέγχει για ληγμένα ή υπό λήξη υλικά ραφιού (εντός ' . h($settings['shelf_expiry_reminder_days'] ?? '30') . ' ημερών).', 'color' => 'danger'],
    'subscription_expiry' => ['label' => 'Λήξη Ετήσιων Συνδρομών',  'icon' => 'bi-cash-coin',          'desc' => 'Στέλνει ειδοποιήσεις για την τελευταία συνδρομή κάθε εθελοντή (3μ, 1μ, 1εβδ, ληγμένη).', 'color' => 'primary'],
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
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-hdd-network me-2"></i>Ρύθμιση αυτόματης εκτέλεσης (Hostinger)</h5>
            </div>
            <div class="card-body">
                <p class="small text-muted">
                    Χρειάζεται <strong>μία μόνο</strong> προγραμματισμένη εργασία. Το <code>cron_daily.php</code>
                    τρέχει με τη σειρά και τις <?= count($cronJobs) ?> εργασίες που βλέπετε παραπάνω.
                </p>

                <ol class="small mb-3">
                    <li class="mb-1">Συνδεθείτε στο hPanel και επιλέξτε τον λογαριασμό φιλοξενίας.</li>
                    <li class="mb-1">Πηγαίνετε <strong>Advanced</strong> &rarr; <strong>Cron Jobs</strong>.</li>
                    <li class="mb-1">Στο <em>Create New Cron Job</em> επιλέξτε <strong>Custom</strong> και συχνότητα
                        <strong>μία φορά την ημέρα</strong> — προτείνεται νωρίς το πρωί, π.χ. 07:00.</li>
                    <li class="mb-1">Στο πεδίο εντολής επικολλήστε ακριβώς αυτό:</li>
                </ol>

                <?php
                // On the live (Linux) host __DIR__ IS the path to paste, so show
                // it verbatim. On a Windows dev box it would render a nonsense
                // command like "/usr/bin/php C:\xampp\..." — there, show the
                // shape of a Hostinger path instead and say it is an example.
                $cronIsWindows = (DIRECTORY_SEPARATOR === '\\');
                $cronPath = $cronIsWindows
                    ? '/home/uXXXXXXXXX/domains/example.gr/public_html'
                    : __DIR__;
                ?>
                <div class="d-flex align-items-start gap-2 mb-2">
                    <code id="cronCmd" class="flex-grow-1 d-block bg-dark text-light p-2 rounded" style="font-size:12px;word-break:break-all;">/usr/bin/php <?= h($cronPath) ?>/cron_daily.php</code>
                    <button type="button" class="btn btn-sm btn-outline-secondary flex-shrink-0" id="cronCopyBtn">
                        <i class="bi bi-clipboard"></i>
                    </button>
                </div>
                <?php if ($cronIsWindows): ?>
                <p class="small text-muted">
                    Αυτή είναι εγκατάσταση Windows, οπότε η διαδρομή παραπάνω είναι <strong>παράδειγμα</strong>.
                    Ανοίγοντας την ίδια σελίδα στο live site, θα δείτε εκεί την πραγματική του διαδρομή
                    έτοιμη για επικόλληση.
                </p>
                <?php else: ?>
                <p class="small text-muted">
                    Η διαδρομή παραπάνω είναι η πραγματική διαδρομή <strong>αυτής</strong> της εγκατάστασης —
                    δεν χρειάζεται να αλλάξετε τίποτα.
                </p>
                <?php endif; ?>

                <div class="alert alert-warning py-2 small mb-3">
                    <i class="bi bi-exclamation-triangle me-1"></i>
                    <strong>Μην χρησιμοποιήσετε <code>wget</code> ή <code>curl</code> με διεύθυνση ιστοσελίδας.</strong>
                    Τα scripts δέχονται κλήση μόνο από γραμμή εντολών και θα απαντήσουν
                    «This script can only be run from command line». Είναι σκόπιμο: αλλιώς οποιοσδήποτε
                    γνώριζε τη διεύθυνση θα μπορούσε να πυροδοτεί μαζικές αποστολές email.
                </div>

                <p class="small text-muted mb-0">
                    Σε πακέτα κοινόχρηστης φιλοξενίας υπάρχει ελάχιστο διάστημα μεταξύ εκτελέσεων — δεν αφορά
                    εδώ, αφού η εργασία τρέχει μία φορά την ημέρα. Αν χρειαστεί να ελέγξετε ότι δουλεύει
                    χωρίς να περιμένετε, χρησιμοποιήστε το <strong>Εκτέλεση Όλων</strong> παραπάνω.
                </p>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header">
                <h6 class="mb-0"><i class="bi bi-pc-display me-2"></i>Τοπική εγκατάσταση (XAMPP)</h6>
            </div>
            <div class="card-body">
                <p class="small text-muted mb-2">Σε Windows, μέσω Task Scheduler:</p>
                <code class="d-block bg-dark text-light p-2 rounded" style="font-size:12px;word-break:break-all;">C:\xampp\php\php.exe <?= h(__DIR__) ?>\cron_daily.php</code>
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
                    Δείτε τις αναλυτικές οδηγίες εγκατάστασης πιο κάτω σε αυτή τη σελίδα.
                </p>

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

<script>
(function () {
    var btn = document.getElementById('cronCopyBtn');
    var cmd = document.getElementById('cronCmd');
    if (!btn || !cmd) return;
    btn.addEventListener('click', function () {
        navigator.clipboard.writeText(cmd.textContent.trim()).then(function () {
            btn.innerHTML = '<i class="bi bi-check-lg"></i>';
            btn.classList.add('btn-success');
            btn.classList.remove('btn-outline-secondary');
            setTimeout(function () {
                btn.innerHTML = '<i class="bi bi-clipboard"></i>';
                btn.classList.remove('btn-success');
                btn.classList.add('btn-outline-secondary');
            }, 1500);
        });
    });
})();
</script>

<!-- Citizens Settings Tab -->
<?php if ($activeTab === 'citizens'): ?>
<?php
$citizenStats = [
    'total_citizens' => (int)dbFetchValue("SELECT COUNT(*) FROM citizens"),
    'total_certs' => (int)dbFetchValue("SELECT COUNT(*) FROM citizen_certificates"),
    'expired_certs' => (int)dbFetchValue("SELECT COUNT(*) FROM citizen_certificates WHERE expiry_date IS NOT NULL AND expiry_date < CURDATE()"),
    'expiring_3m' => (int)dbFetchValue("SELECT COUNT(*) FROM citizen_certificates WHERE expiry_date IS NOT NULL AND expiry_date >= CURDATE() AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 3 MONTH)"),
];
?>
<div class="row">
    <div class="col-lg-7">
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save_citizens">
            
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-bell me-1"></i>Ειδοποιήσεις Λήξης Πιστοποιητικών Πολιτών</h5>
                </div>
                <div class="card-body">
                    <div class="form-check form-switch mb-4">
                        <input class="form-check-input" type="checkbox" name="citizen_cert_notify_enabled" id="citizenNotifyEnabled"
                               <?= ($settings['citizen_cert_notify_enabled'] ?? '0') === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label fw-bold" for="citizenNotifyEnabled">
                            Ενεργοποίηση αυτόματων ειδοποιήσεων λήξης
                        </label>
                        <small class="text-muted d-block">Αν είναι ενεργό, το σύστημα θα στέλνει email ειδοποιήσεις πριν τη λήξη πιστοποιητικών πολιτών.</small>
                    </div>
                    
                    <hr>
                    <h6 class="text-muted mb-3"><i class="bi bi-clock-history me-1"></i>Χρόνοι Ειδοποίησης</h6>
                    <p class="small text-muted mb-3">Επιλέξτε πότε θα στέλνεται email ειδοποίηση:</p>
                    
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="citizen_cert_notify_3months" id="citizenNotify3m"
                               <?= ($settings['citizen_cert_notify_3months'] ?? '1') === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="citizenNotify3m">
                            <span class="badge bg-info text-dark me-1">3 μήνες</span> πριν τη λήξη
                        </label>
                    </div>
                    
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="citizen_cert_notify_1month" id="citizenNotify1m"
                               <?= ($settings['citizen_cert_notify_1month'] ?? '1') === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="citizenNotify1m">
                            <span class="badge bg-warning text-dark me-1">1 μήνα</span> πριν τη λήξη
                        </label>
                    </div>
                    
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="citizen_cert_notify_1week" id="citizenNotify1w"
                               <?= ($settings['citizen_cert_notify_1week'] ?? '1') === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="citizenNotify1w">
                            <span class="badge bg-warning text-dark me-1">1 εβδομάδα</span> πριν τη λήξη
                        </label>
                    </div>
                    
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="citizen_cert_notify_expired" id="citizenNotifyExpired"
                               <?= ($settings['citizen_cert_notify_expired'] ?? '1') === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="citizenNotifyExpired">
                            <span class="badge bg-danger me-1">Κατά τη λήξη</span> (ημέρα λήξης)
                        </label>
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
        <!-- Stats -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-bar-chart me-1"></i>Στατιστικά Πολιτών</h5>
            </div>
            <div class="card-body">
                <table class="table table-sm mb-0">
                    <tr>
                        <td>Σύνολο Πολιτών</td>
                        <td class="text-end"><strong><?= $citizenStats['total_citizens'] ?></strong></td>
                    </tr>
                    <tr>
                        <td>Σύνολο Πιστοποιητικών</td>
                        <td class="text-end"><strong><?= $citizenStats['total_certs'] ?></strong></td>
                    </tr>
                    <tr>
                        <td>Ληγμένα Πιστοποιητικά</td>
                        <td class="text-end"><strong class="text-danger"><?= $citizenStats['expired_certs'] ?></strong></td>
                    </tr>
                    <tr>
                        <td>Λήγουν σε 3 μήνες</td>
                        <td class="text-end"><strong class="text-warning"><?= $citizenStats['expiring_3m'] ?></strong></td>
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
                <a href="citizens.php" class="btn btn-outline-primary">
                    <i class="bi bi-person-vcard me-1"></i>Λίστα Πολιτών
                </a>
                <a href="citizen-certificates.php" class="btn btn-outline-primary">
                    <i class="bi bi-file-earmark-medical me-1"></i>Πιστοποιητικά Πολιτών
                </a>
                <a href="citizen-certificate-types.php" class="btn btn-outline-primary">
                    <i class="bi bi-tags me-1"></i>Τύποι Πιστοποιητικών
                </a>
            </div>
        </div>
        
        <!-- Color Legend -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-palette me-1"></i>Υπόμνημα Χρωμάτων</h5>
            </div>
            <div class="card-body">
                <div class="d-flex align-items-center mb-2">
                    <span class="badge bg-info text-dark me-2" style="width: 80px;">Μπλε</span>
                    <small>Λήγει σε 6 μήνες ή λιγότερο</small>
                </div>
                <div class="d-flex align-items-center mb-2">
                    <span class="badge bg-warning text-dark me-2" style="width: 80px;">Κίτρινο</span>
                    <small>Λήγει σε 3 μήνες ή λιγότερο</small>
                </div>
                <div class="d-flex align-items-center">
                    <span class="badge bg-danger me-2" style="width: 80px;">Κόκκινο</span>
                    <small>Ληγμένο πιστοποιητικό</small>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($activeTab === 'subscriptions'): ?>
<div class="row"><div class="col-lg-7"><form method="post">
<?= csrfField() ?><input type="hidden" name="action" value="save_subscriptions">
<div class="card"><div class="card-header"><h5 class="mb-0"><i class="bi bi-arrow-repeat me-1"></i>Επανενεργοποίηση ετήσιας συνδρομής</h5></div><div class="card-body">
<p class="text-muted">Μετά από αυτό το διάστημα από τη λήξη, η νέα πληρωμή ξεκινά νέα ετήσια συνδρομή από την ημερομηνία πληρωμής. Πριν από το όριο, η συνδρομή ανανεώνεται από την προηγούμενη λήξη.</p>
<label class="form-label fw-semibold" for="subscriptionReactivationDays">Όριο επανενεργοποίησης (ημέρες)</label>
<input type="number" class="form-control" style="max-width:220px" id="subscriptionReactivationDays" name="subscription_reactivation_days" min="0" max="3650" value="<?= h($settings['subscription_reactivation_days']) ?>" required>
<div class="form-text">Προτεινόμενη τιμή: 90 ημέρες. Το 0 κάνει κάθε καθυστερημένη πληρωμή επανενεργοποίηση.</div>
</div></div>
<div class="card mt-3"><div class="card-header"><h5 class="mb-0"><i class="bi bi-phone-vibrate me-1"></i>Ανανέωση συνδρομής με IRIS</h5></div><div class="card-body">
<p class="text-muted">Ο εθελοντής ενημερώνεται για την πληρωμή IRIS και ο admin επιβεβαιώνει την πραγματική είσπραξη πριν ενεργοποιηθεί η συνδρομή.</p>
<div class="row g-3"><div class="col-md-4"><label class="form-label fw-semibold" for="subscriptionIrisAnnualAmount">Ετήσιο ποσό (€)</label><input type="number" class="form-control" id="subscriptionIrisAnnualAmount" name="subscription_iris_annual_amount" min="0" step="0.01" value="<?= h($settings['subscription_iris_annual_amount']) ?>" required></div><div class="col-md-4"><label class="form-label fw-semibold" for="subscriptionIrisRenewalDays">Διαθέσιμη ανανέωση πριν από (ημέρες)</label><input type="number" class="form-control" id="subscriptionIrisRenewalDays" name="subscription_iris_renewal_days" min="0" max="3650" value="<?= h($settings['subscription_iris_renewal_days']) ?>" required><div class="form-text">Προεπιλογή: 90 ημέρες.</div></div><div class="col-md-4"><label class="form-label fw-semibold" for="subscriptionIrisTaxId">ΑΦΜ πληρωμής IRIS</label><input type="text" class="form-control" id="subscriptionIrisTaxId" name="subscription_iris_tax_id" inputmode="numeric" maxlength="20" value="<?= h($settings['subscription_iris_tax_id']) ?>" required></div></div>
<div class="row g-3 mt-1"><div class="col-md-4"><label class="form-label fw-semibold" for="subscriptionIrisPhone">Τηλέφωνο πληρωμής IRIS</label><input type="text" class="form-control" id="subscriptionIrisPhone" name="subscription_iris_phone" inputmode="tel" maxlength="20" placeholder="69XXXXXXXX" value="<?= h($settings['subscription_iris_phone']) ?>"><div class="form-text">Προαιρετικό. Εμφανίζεται στον εθελοντή ως εναλλακτικός τρόπος πληρωμής IRIS.</div></div></div>
</div><div class="card-footer"><button class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Αποθήκευση</button></div></div>
</form></div></div>
<?php endif; ?>

<?php if ($activeTab === 'health'): ?>
<?php
$healthResults = $_SESSION['health_results'] ?? null;
$healthRan = $_SESSION['health_ran'] ?? false;
unset($_SESSION['health_results'], $_SESSION['health_ran']);
?>

<!-- Action Buttons -->
<div class="d-flex gap-2 mb-4 flex-wrap">
    <form method="post" class="d-inline">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="run_health_check">
        <button type="submit" class="btn btn-primary btn-lg">
            <i class="bi bi-heart-pulse me-2"></i>Εκτέλεση Ελέγχου Υγείας
        </button>
    </form>
    <form method="post" class="d-inline" onsubmit="return confirm('Εκτέλεση OPTIMIZE TABLE σε όλους τους πίνακες;')">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="health_optimize">
        <button type="submit" class="btn btn-outline-success">
            <i class="bi bi-rocket-takeoff me-1"></i>Optimize Tables
        </button>
    </form>
    <form method="post" class="d-inline">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="health_analyze">
        <button type="submit" class="btn btn-outline-info">
            <i class="bi bi-bar-chart me-1"></i>Analyze Tables
        </button>
    </form>
</div>

<?php if ($healthRan && $healthResults): ?>
<!-- Overall Score -->
<div class="card mb-4 <?= $healthResults['score'] >= 90 ? 'border-success' : ($healthResults['score'] >= 70 ? 'border-warning' : 'border-danger') ?>">
    <div class="card-body text-center py-4">
        <h4 class="mb-3">Συνολική Βαθμολογία Υγείας</h4>
        <div class="d-flex justify-content-center align-items-center gap-3">
            <?php
            $scoreColor = $healthResults['score'] >= 90 ? 'success' : ($healthResults['score'] >= 70 ? 'warning' : 'danger');
            $scoreIcon = $healthResults['score'] >= 90 ? 'bi-check-circle-fill' : ($healthResults['score'] >= 70 ? 'bi-exclamation-triangle-fill' : 'bi-x-circle-fill');
            ?>
            <i class="bi <?= $scoreIcon ?> text-<?= $scoreColor ?>" style="font-size: 3rem;"></i>
            <div>
                <span class="display-3 fw-bold text-<?= $scoreColor ?>"><?= $healthResults['score'] ?>%</span>
                <div class="text-muted"><?= $healthResults['passed'] ?> / <?= $healthResults['total'] ?> έλεγχοι</div>
            </div>
        </div>
        <div class="progress mt-3 mx-auto" style="max-width: 500px; height: 12px;">
            <div class="progress-bar bg-<?= $scoreColor ?>" style="width: <?= $healthResults['score'] ?>%"></div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-lg-6">
        <!-- 1. System Environment -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-cpu me-1"></i>Περιβάλλον Συστήματος</h5>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm table-hover mb-0">
                    <tbody>
                    <?php foreach ($healthResults['checks']['environment'] ?? [] as $check): ?>
                        <tr>
                            <td class="ps-3" style="width:40%"><?= h($check['label']) ?></td>
                            <td>
                                <?php if ($check['status'] === 'ok'): ?>
                                    <span class="badge bg-success"><i class="bi bi-check-lg"></i></span>
                                <?php elseif ($check['status'] === 'warning'): ?>
                                    <span class="badge bg-warning text-dark"><i class="bi bi-exclamation-triangle"></i></span>
                                <?php else: ?>
                                    <span class="badge bg-danger"><i class="bi bi-x-lg"></i></span>
                                <?php endif; ?>
                                <strong><?= h($check['value']) ?></strong>
                                <?php if (!empty($check['detail'])): ?>
                                    <small class="text-muted d-block"><?= h($check['detail']) ?></small>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 3. Database Structure -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-database me-1"></i>Βάση Δεδομένων - Δομή</h5>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm table-hover mb-0">
                    <tbody>
                    <?php foreach ($healthResults['checks']['database'] ?? [] as $check): ?>
                        <tr>
                            <td class="ps-3" style="width:40%"><?= h($check['label']) ?></td>
                            <td>
                                <?php if ($check['status'] === 'ok'): ?>
                                    <span class="badge bg-success"><i class="bi bi-check-lg"></i></span>
                                <?php elseif ($check['status'] === 'warning'): ?>
                                    <span class="badge bg-warning text-dark"><i class="bi bi-exclamation-triangle"></i></span>
                                <?php else: ?>
                                    <span class="badge bg-danger"><i class="bi bi-x-lg"></i></span>
                                <?php endif; ?>
                                <strong><?= h($check['value']) ?></strong>
                                <?php if (!empty($check['detail'])): ?>
                                    <small class="text-muted d-block"><?= h($check['detail']) ?></small>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 5. Performance -->
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-speedometer2 me-1"></i>Απόδοση</h5>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm table-hover mb-0">
                    <tbody>
                    <?php foreach ($healthResults['checks']['performance'] ?? [] as $check): ?>
                        <tr>
                            <td class="ps-3" style="width:40%"><?= h($check['label']) ?></td>
                            <td>
                                <?php if ($check['status'] === 'ok'): ?>
                                    <span class="badge bg-success"><i class="bi bi-check-lg"></i></span>
                                <?php elseif ($check['status'] === 'warning'): ?>
                                    <span class="badge bg-warning text-dark"><i class="bi bi-exclamation-triangle"></i></span>
                                <?php else: ?>
                                    <span class="badge bg-danger"><i class="bi bi-x-lg"></i></span>
                                <?php endif; ?>
                                <strong><?= h($check['value']) ?></strong>
                                <?php if (!empty($check['detail'])): ?>
                                    <small class="text-muted d-block"><?= h($check['detail']) ?></small>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-footer">
                <div class="d-flex gap-2 flex-wrap">
                    <form method="post" class="d-inline">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="health_cleanup_logs">
                        <input type="hidden" name="cleanup_months" value="1">
                        <button type="submit" class="btn btn-sm btn-outline-warning" onclick="return confirm('Διαγραφή audit logs παλαιότερων ενός μήνα;')">
                            <i class="bi bi-trash me-1"></i>Καθαρισμός Audit Log (&gt; 1μ)
                        </button>
                    </form>
                    <form method="post" class="d-inline">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="health_cleanup_email_logs">
                        <input type="hidden" name="cleanup_months" value="1">
                        <button type="submit" class="btn btn-sm btn-outline-warning" onclick="return confirm('Διαγραφή email logs παλαιότερων ενός μήνα;')">
                            <i class="bi bi-trash me-1"></i>Καθαρισμός Email Log (&gt; 1μ)
                        </button>
                    </form>
                    <form method="post" class="d-inline">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="health_cleanup_notifications">
                        <input type="hidden" name="cleanup_months" value="1">
                        <button type="submit" class="btn btn-sm btn-outline-warning" onclick="return confirm('Διαγραφή ΟΛΩΝ των ειδοποιήσεων παλαιότερων ενός μήνα — και των αδιάβαστων. Συνέχεια;')">
                            <i class="bi bi-trash me-1"></i>Καθαρισμός Ειδοποιήσεων (&gt; 1μ)
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <!-- 2. File System -->
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-folder me-1"></i>Σύστημα Αρχείων</h5>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm table-hover mb-0">
                    <tbody>
                    <?php foreach ($healthResults['checks']['filesystem'] ?? [] as $check): ?>
                        <tr>
                            <td class="ps-3" style="width:40%"><code><?= h($check['label']) ?></code></td>
                            <td>
                                <?php if ($check['status'] === 'ok'): ?>
                                    <span class="badge bg-success"><i class="bi bi-check-lg"></i></span>
                                <?php elseif ($check['status'] === 'warning'): ?>
                                    <span class="badge bg-warning text-dark"><i class="bi bi-exclamation-triangle"></i></span>
                                <?php else: ?>
                                    <span class="badge bg-danger"><i class="bi bi-x-lg"></i></span>
                                <?php endif; ?>
                                <strong><?= h($check['value']) ?></strong>
                                <?php if (!empty($check['detail'])): ?>
                                    <small class="text-muted d-block"><?= h($check['detail']) ?></small>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if (($healthResults['missing_dirs'] ?? 0) > 0): ?>
            <div class="card-footer">
                <form method="post" class="d-inline">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="health_fix_dirs">
                    <button type="submit" class="btn btn-sm btn-warning">
                        <i class="bi bi-folder-plus me-1"></i>Δημιουργία Φακέλων (<?= $healthResults['missing_dirs'] ?>)
                    </button>
                </form>
            </div>
            <?php endif; ?>
        </div>

        <!-- 4. Data Integrity -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-link-45deg me-1"></i>Ακεραιότητα Δεδομένων</h5>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm table-hover mb-0">
                    <tbody>
                    <?php foreach ($healthResults['checks']['integrity'] ?? [] as $check): ?>
                        <tr>
                            <td class="ps-3" style="width:50%"><?= h($check['label']) ?></td>
                            <td>
                                <?php if ($check['status'] === 'ok'): ?>
                                    <span class="badge bg-success"><i class="bi bi-check-lg"></i></span>
                                <?php elseif ($check['status'] === 'warning'): ?>
                                    <span class="badge bg-warning text-dark"><i class="bi bi-exclamation-triangle"></i></span>
                                <?php else: ?>
                                    <span class="badge bg-danger"><i class="bi bi-x-lg"></i></span>
                                <?php endif; ?>
                                <strong><?= h($check['value']) ?></strong>
                                <?php if (!empty($check['detail'])): ?>
                                    <small class="text-muted d-block"><?= h($check['detail']) ?></small>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if (($healthResults['total_orphans'] ?? 0) > 0): ?>
            <div class="card-footer">
                <form method="post" class="d-inline" onsubmit="return confirm('Θα διαγραφούν <?= $healthResults['total_orphans'] ?> ορφανές εγγραφές. Συνέχεια;')">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="health_fix_orphans">
                    <button type="submit" class="btn btn-sm btn-warning">
                        <i class="bi bi-trash me-1"></i>Καθαρισμός Ορφανών (<?= $healthResults['total_orphans'] ?>)
                    </button>
                </form>
            </div>
            <?php endif; ?>
        </div>

        <!-- 6. Config & Security -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-shield-lock me-1"></i>Ρυθμίσεις &amp; Ασφάλεια</h5>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm table-hover mb-0">
                    <tbody>
                    <?php foreach ($healthResults['checks']['security'] ?? [] as $check): ?>
                        <tr>
                            <td class="ps-3" style="width:40%"><?= h($check['label']) ?></td>
                            <td>
                                <?php if ($check['status'] === 'ok'): ?>
                                    <span class="badge bg-success"><i class="bi bi-check-lg"></i></span>
                                <?php elseif ($check['status'] === 'warning'): ?>
                                    <span class="badge bg-warning text-dark"><i class="bi bi-exclamation-triangle"></i></span>
                                <?php else: ?>
                                    <span class="badge bg-danger"><i class="bi bi-x-lg"></i></span>
                                <?php endif; ?>
                                <strong><?= h($check['value']) ?></strong>
                                <?php if (!empty($check['detail'])): ?>
                                    <small class="text-muted d-block"><?= h($check['detail']) ?></small>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 7. Health Stats -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-graph-up me-1"></i>Στατιστικά Υγείας</h5>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm table-hover mb-0">
                    <tbody>
                    <?php foreach ($healthResults['checks']['stats'] ?? [] as $check): ?>
                        <tr>
                            <td class="ps-3" style="width:50%"><?= h($check['label']) ?></td>
                            <td>
                                <?php if ($check['status'] === 'ok'): ?>
                                    <span class="badge bg-success"><i class="bi bi-check-lg"></i></span>
                                <?php elseif ($check['status'] === 'warning'): ?>
                                    <span class="badge bg-warning text-dark"><i class="bi bi-exclamation-triangle"></i></span>
                                <?php else: ?>
                                    <span class="badge bg-danger"><i class="bi bi-x-lg"></i></span>
                                <?php endif; ?>
                                <strong><?= h($check['value']) ?></strong>
                                <?php if (!empty($check['detail'])): ?>
                                    <small class="text-muted d-block"><?= h($check['detail']) ?></small>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Table Sizes Detail -->
<?php if (!empty($healthResults['table_sizes'])): ?>
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="mb-0"><i class="bi bi-table me-1"></i>Μεγέθη Πινάκων</h5>
        <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#tableSizesCollapse">
            <i class="bi bi-chevron-down me-1"></i>Εμφάνιση/Απόκρυψη
        </button>
    </div>
    <div class="collapse" id="tableSizesCollapse">
        <div class="card-body p-0">
            <table class="table table-sm table-striped table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-3">Πίνακας</th>
                        <th class="text-end">Εγγραφές</th>
                        <th class="text-end">Data (MB)</th>
                        <th class="text-end">Index (MB)</th>
                        <th class="text-end">Σύνολο (MB)</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($healthResults['table_sizes'] as $ts): ?>
                    <tr>
                        <td class="ps-3"><code><?= h($ts['TABLE_NAME']) ?></code></td>
                        <td class="text-end"><?= number_format($ts['TABLE_ROWS']) ?></td>
                        <td class="text-end"><?= $ts['data_mb'] ?></td>
                        <td class="text-end"><?= $ts['index_mb'] ?></td>
                        <td class="text-end"><strong><?= $ts['size_mb'] ?></strong></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php else: ?>
<!-- No results yet -->
<div class="text-center py-5">
    <i class="bi bi-heart-pulse text-muted" style="font-size: 4rem;"></i>
    <h4 class="text-muted mt-3">Έλεγχος Υγείας Εφαρμογής</h4>
    <p class="text-muted">Πατήστε <strong>«Εκτέλεση Ελέγχου Υγείας»</strong> για πλήρη διαγνωστικό έλεγχο του συστήματος.</p>
    <p class="text-muted small">
        Ελέγχονται: Περιβάλλον PHP/MySQL, σύστημα αρχείων, δομή βάσης, ακεραιότητα δεδομένων,<br>
        απόδοση, ρυθμίσεις ασφαλείας και στατιστικά λειτουργίας.
    </p>
</div>
<?php endif; ?>
<?php endif; ?>

<?php if ($activeTab === 'prerequisites'): ?>
<?php
    $missionTypes = dbFetchAll("SELECT id, name FROM mission_types ORDER BY name");
    $selAttendance = array_filter(explode(',', getSetting('prereq_mission_types', '')));
    $selTep        = array_filter(explode(',', getSetting('prereq_tep_mission_types', '')));
    $selEdu        = array_filter(explode(',', getSetting('prereq_edu_mission_types', '')));
?>
<form method="post">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="save_prerequisites">

    <div class="alert alert-info mb-4">
        <i class="bi bi-info-circle me-1"></i>
        Ρυθμίστε τους στόχους προαπαιτούμενων για κάθε κατηγορία. Οι στόχοι μπορούν να βασίζονται σε αριθμό παρουσιών ή/και ωρών.
        Επιλέξτε τους τύπους αποστολών που μετρούν για κάθε κατηγορία.
    </div>

    <!-- Παρουσίες Αποστολών -->
    <div class="card mb-4">
        <div class="card-header"><h6 class="mb-0"><i class="bi bi-clipboard-check me-2"></i>Παρουσίες Αποστολών</h6></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" id="prereq_attendance_enabled" name="prereq_attendance_enabled" value="1"
                            <?= getSetting('prereq_attendance_enabled', '1') === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="prereq_attendance_enabled">Παρουσίες</label>
                    </div>
                    <input type="number" class="form-control form-control-sm" name="prereq_attendance_goal" min="0"
                        value="<?= h(getSetting('prereq_attendance_goal', '10')) ?>" placeholder="Στόχος παρουσιών">
                </div>
                <div class="col-md-3">
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" id="prereq_hours_enabled" name="prereq_hours_enabled" value="1"
                            <?= getSetting('prereq_hours_enabled', '0') === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="prereq_hours_enabled">Ώρες</label>
                    </div>
                    <input type="number" class="form-control form-control-sm" name="prereq_hours_goal" min="0"
                        value="<?= h(getSetting('prereq_hours_goal', '0')) ?>" placeholder="Στόχος ωρών">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Τύποι Αποστολών</label>
                    <select class="form-select form-select-sm" name="prereq_mission_types[]" multiple size="4">
                        <?php foreach ($missionTypes as $mt): ?>
                        <option value="<?= $mt['id'] ?>" <?= in_array($mt['id'], $selAttendance) ? 'selected' : '' ?>><?= h($mt['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Ctrl+click για πολλαπλή επιλογή. Αν δεν επιλέξετε κανένα, μετρούν όλοι οι τύποι.</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Τ.Ε.Π. -->
    <div class="card mb-4">
        <div class="card-header"><h6 class="mb-0"><i class="bi bi-hospital me-2"></i>Τ.Ε.Π.</h6></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" id="prereq_tep_attendance_enabled" name="prereq_tep_attendance_enabled" value="1"
                            <?= getSetting('prereq_tep_attendance_enabled', '0') === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="prereq_tep_attendance_enabled">Παρουσίες</label>
                    </div>
                    <input type="number" class="form-control form-control-sm" name="prereq_tep_attendance_goal" min="0"
                        value="<?= h(getSetting('prereq_tep_attendance_goal', '0')) ?>" placeholder="Στόχος παρουσιών">
                </div>
                <div class="col-md-3">
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" id="prereq_tep_hours_enabled" name="prereq_tep_hours_enabled" value="1"
                            <?= getSetting('prereq_tep_hours_enabled', '1') === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="prereq_tep_hours_enabled">Ώρες</label>
                    </div>
                    <input type="number" class="form-control form-control-sm" name="prereq_tep_hours_goal" min="0"
                        value="<?= h(getSetting('prereq_tep_hours_goal', '40')) ?>" placeholder="Στόχος ωρών">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Τύποι Αποστολών</label>
                    <select class="form-select form-select-sm" name="prereq_tep_mission_types[]" multiple size="4">
                        <?php foreach ($missionTypes as $mt): ?>
                        <option value="<?= $mt['id'] ?>" <?= in_array($mt['id'], $selTep) ? 'selected' : '' ?>><?= h($mt['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Ctrl+click για πολλαπλή επιλογή.</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Επανεκπαίδευση Εθελοντών -->
    <div class="card mb-4">
        <div class="card-header"><h6 class="mb-0"><i class="bi bi-mortarboard me-2"></i>Επανεκπαίδευση Εθελοντών</h6></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-3">
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" id="prereq_edu_attendance_enabled" name="prereq_edu_attendance_enabled" value="1"
                            <?= getSetting('prereq_edu_attendance_enabled', '1') === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="prereq_edu_attendance_enabled">Παρουσίες</label>
                    </div>
                    <input type="number" class="form-control form-control-sm" name="prereq_edu_attendance_goal" min="0"
                        value="<?= h(getSetting('prereq_edu_attendance_goal', '2')) ?>" placeholder="Στόχος παρουσιών">
                </div>
                <div class="col-md-3">
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" id="prereq_edu_hours_enabled" name="prereq_edu_hours_enabled" value="1"
                            <?= getSetting('prereq_edu_hours_enabled', '0') === '1' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="prereq_edu_hours_enabled">Ώρες</label>
                    </div>
                    <input type="number" class="form-control form-control-sm" name="prereq_edu_hours_goal" min="0"
                        value="<?= h(getSetting('prereq_edu_hours_goal', '0')) ?>" placeholder="Στόχος ωρών">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Τύποι Αποστολών</label>
                    <select class="form-select form-select-sm" name="prereq_edu_mission_types[]" multiple size="4">
                        <?php foreach ($missionTypes as $mt): ?>
                        <option value="<?= $mt['id'] ?>" <?= in_array($mt['id'], $selEdu) ? 'selected' : '' ?>><?= h($mt['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Ctrl+click για πολλαπλή επιλογή.</div>
                </div>
            </div>
        </div>
    </div>

    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Αποθήκευση</button>
</form>
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

<script>
// Used by the OpenWeatherMap API-key field — same show/hide idiom as
// reset-password.php's togglePass(), reused under a
// distinct name since this file's inputs use id-based lookup directly
// rather than that file's id+'-confirm' pairing.
function toggleKeyVisibility(id) {
    const input = document.getElementById(id);
    const eye = document.getElementById('eye-' + id);
    if (!input || !eye) return;
    if (input.type === 'password') {
        input.type = 'text';
        eye.className = 'bi bi-eye-slash';
    } else {
        input.type = 'password';
        eye.className = 'bi bi-eye';
    }
}
</script>

<?php if (!empty($settings['openweathermap_api_key'] ?? '')): ?>
<script>
document.getElementById('btnTestWeatherKey') && document.getElementById('btnTestWeatherKey').addEventListener('click', function() {
    var btn = this;
    var result = document.getElementById('weatherTestResult');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Έλεγχος...';
    result.style.display = 'none';

    fetch('api-weather-test.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest'},
        body: 'csrf_token=' + encodeURIComponent('<?= csrfToken() ?>')
    })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            result.style.display = '';
            if (data.ok) {
                result.innerHTML = '<div class="alert alert-success py-1 px-2 small mb-0"><i class="bi bi-check-circle me-1"></i>' + data.message + '</div>';
            } else {
                result.innerHTML = '<div class="alert alert-danger py-1 px-2 small mb-0"><i class="bi bi-exclamation-triangle me-1"></i>' + data.message + '</div>';
            }
        })
        .catch(function() {
            result.style.display = '';
            result.innerHTML = '<div class="alert alert-danger py-1 px-2 small mb-0"><i class="bi bi-exclamation-triangle me-1"></i>Αποτυχία επικοινωνίας</div>';
        })
        .finally(function() {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-plug me-1"></i>Έλεγχος σύνδεσης';
        });
});
</script>
<?php endif; ?>

<script>
// Provider metadata mirrored from aiProviders() so the model datalist, the
// base-URL placeholder and the hint line follow the dropdown without a page
// reload. The values are suggestions only — both fields stay free text, and
// the server falls back to the provider's own default when either is blank.
(function () {
    var meta = <?= json_encode(array_map(fn($p) => [
        'hint' => $p['key_hint'] . ' Επεξεργασία: ' . $p['jurisdiction'] . '.',
    ], aiProviders()), JSON_UNESCAPED_UNICODE) ?>;

    var sel  = document.getElementById('aiProvider');
    var hint = document.getElementById('aiProviderHint');
    if (!sel) return;

    var blocks  = Array.prototype.slice.call(document.querySelectorAll('[data-ai-provider]'));
    var toggle  = document.getElementById('aiToggleFallbacks');
    var showAll = toggle ? toggle.getAttribute('aria-expanded') === 'true' : false;

    // The fields follow the dropdown immediately, without a save: picking
    // DeepSeek and still being shown Gemini's key field is how an admin ends
    // up pasting a key into the wrong provider.
    function apply() {
        var chosen = sel.value;
        var m = meta[chosen];
        if (m && hint) hint.textContent = m.hint;

        var hidden = 0;
        blocks.forEach(function (block) {
            var isPrimary = block.getAttribute('data-ai-provider') === chosen;
            block.hidden = !isPrimary && !showAll;
            if (!isPrimary && !showAll) hidden++;
            // Outlined when chosen. With the fallbacks expanded this is what
            // separates "the provider I picked" from "the two I can also
            // configure" at a glance, rather than on a badge.
            block.classList.toggle('border-primary', isPrimary);

            var pb = block.querySelector('[data-ai-badge="primary"]');
            var fb = block.querySelector('[data-ai-badge="fallback"]');
            if (pb) pb.hidden = !isPrimary;
            if (fb) fb.hidden = isPrimary;
        });

        if (toggle) {
            toggle.querySelector('span').textContent = showAll
                ? 'Απόκρυψη εφεδρικών παρόχων'
                : 'Εφεδρικοί πάροχοι' + (hidden ? ' (' + hidden + ')' : '');
            toggle.querySelector('i').className = 'bi me-1 ' + (showAll ? 'bi-chevron-up' : 'bi-chevron-down');
            toggle.hidden = blocks.length < 2;
        }
    }

    // Choosing a provider is an act of focus, so the other two get out of the
    // way — even if they were expanded when the page loaded because one of them
    // already holds a key. Reported: picked Grok, read the Μοντέλο field of the
    // Gemini block still sitting above it, and concluded Grok was offering
    // Gemini's models. Three near-identical stacks of API Key / Μοντέλο / Base
    // URL are genuinely easy to read across.
    //
    // The arrival behaviour is deliberately left alone: landing on a page that
    // hides a configured fallback would be its own way of losing track of it.
    // This is only about what happens when the admin actively picks one.
    sel.addEventListener('change', function () {
        showAll = false;
        if (toggle) toggle.setAttribute('aria-expanded', 'false');
        apply();
    });
    if (toggle) {
        toggle.addEventListener('click', function () {
            showAll = !showAll;
            toggle.setAttribute('aria-expanded', showAll ? 'true' : 'false');
            apply();
        });
    }
    apply();

    var btn = document.getElementById('btnTestAiKey');
    if (!btn) return;
    btn.addEventListener('click', function () {
        var result = document.getElementById('aiTestResult');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Έλεγχος...';
        result.style.display = 'none';

        // Post what is on screen, not what is saved. Reading the stored
        // settings meant an admin who picked DeepSeek and pressed this was
        // told "connected to Google Gemini" and reasonably concluded the
        // feature was broken. An untouched key field posts empty, which the
        // endpoint reads as "use the stored key" — so a stored key needs no
        // re-typing and a freshly pasted one needs no save.
        var chosen = sel.value;
        var val = function (id) {
            var el = document.getElementById(id + '_' + chosen);
            return el ? el.value : '';
        };
        var body = 'csrf_token=' + encodeURIComponent('<?= csrfToken() ?>')
                 + '&provider=' + encodeURIComponent(chosen)
                 + '&api_key=' + encodeURIComponent(val('aiKey'))
                 + '&model=' + encodeURIComponent(val('aiModel'))
                 + '&base_url=' + encodeURIComponent(val('aiBaseUrl'));

        fetch('api-ai-test.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest'},
            body: body
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                result.style.display = '';
                // textContent, not innerHTML: on success this message quotes
                // the model's own reply verbatim, and on failure the
                // provider's error text. Neither is ours to trust as markup.
                var box = document.createElement('div');
                box.className = 'alert py-1 px-2 small mb-0 ' + (data.ok ? 'alert-success' : 'alert-danger');
                var icon = document.createElement('i');
                icon.className = 'me-1 bi ' + (data.ok ? 'bi-check-circle' : 'bi-exclamation-triangle');
                box.appendChild(icon);
                box.appendChild(document.createTextNode(data.message || ''));
                result.replaceChildren(box);
            })
            .catch(function () {
                result.style.display = '';
                result.innerHTML = '<div class="alert alert-danger py-1 px-2 small mb-0"><i class="bi bi-exclamation-triangle me-1"></i>Αποτυχία επικοινωνίας</div>';
            })
            .finally(function () {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-plug me-1"></i>Έλεγχος σύνδεσης';
            });
    });
})();
</script>

    </div>
</div>
<?php endif; ?>
<?php include __DIR__ . '/includes/footer.php'; ?>
