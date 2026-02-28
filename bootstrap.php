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
