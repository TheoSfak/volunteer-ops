<?php
/**
 * VolunteerOps - Bootstrap file
 * Include this at the top of every page
 */

define('VOLUNTEEROPS', true);

// Load configuration
require_once __DIR__ . '/config.php';

// Load core includes
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/email.php';
require_once __DIR__ . '/includes/newsletter-functions.php';
require_once __DIR__ . '/includes/training-functions.php';
require_once __DIR__ . '/includes/achievements-functions.php';
// inventory-functions.php is loaded on-demand by inventory pages and branches.php only

// Migrations: only load the heavy 180KB file if schema needs updating.
// IMPORTANT: Update this number whenever you add a new migration!
define('LATEST_MIGRATION_VERSION', 36);
try {
    $__schemaVer = (int) dbFetchValue(
        "SELECT setting_value FROM settings WHERE setting_key = 'db_schema_version'"
    );
    if ($__schemaVer < LATEST_MIGRATION_VERSION) {
        require_once __DIR__ . '/includes/migrations.php';
    }
} catch (Exception $e) {
    // Fresh install or settings table missing — load migrations to bootstrap the DB
    require_once __DIR__ . '/includes/migrations.php';
}
unset($__schemaVer);
// Prevent PHP's default Session Garbage Collection from causing intermittent 5-7s pauses on shared hosting
ini_set('session.gc_probability', 0);
// Start session
initSession();

// Security headers — prevent clickjacking, MIME-sniffing, XSS
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=(self)');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

// Maintenance mode — only admins can access
$__currentScript = basename($_SERVER['SCRIPT_NAME'] ?? '');
$__maintenanceExcluded = ['login.php', 'logout.php', 'install.php', 'cron_daily.php', 'cron_shift_reminders.php', 'cron_task_reminders.php', 'cron_certificate_expiry.php', 'cron_citizen_cert_expiry.php', 'cron_shelf_expiry.php', 'cron_incomplete_missions.php'];
if (getSetting('maintenance_mode', '0') && !in_array($__currentScript, $__maintenanceExcluded)) {
    if (isLoggedIn()) {
        $__mUser = getCurrentUser();
        if ($__mUser && !in_array($__mUser['role'], [ROLE_SYSTEM_ADMIN, ROLE_DEPARTMENT_ADMIN])) {
            setFlash('warning', 'Το σύστημα βρίσκεται σε συντήρηση. Παρακαλώ δοκιμάστε αργότερα.');
            logout();
            redirect('login.php');
        }
    } elseif ($__currentScript !== 'login.php') {
        setFlash('warning', 'Το σύστημα βρίσκεται σε συντήρηση. Παρακαλώ δοκιμάστε αργότερα.');
        redirect('login.php');
    }
}
unset($__currentScript, $__maintenanceExcluded);
