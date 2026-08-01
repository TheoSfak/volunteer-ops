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
require_once __DIR__ . '/includes/i18n.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/email.php';
require_once __DIR__ . '/includes/webpush.php';
require_once __DIR__ . '/includes/newsletter-functions.php';
require_once __DIR__ . '/includes/training-functions.php';
require_once __DIR__ . '/includes/achievements-functions.php';
// inventory-functions.php is loaded on-demand by inventory pages and branches.php only

// Migrations: only load the heavy 180KB file if schema needs updating.
// IMPORTANT: Update this number whenever you add a new migration!
define('LATEST_MIGRATION_VERSION', DB_SCHEMA_VERSION);
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
header('Permissions-Policy: camera=(self), microphone=(), geolocation=(self)');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net https://unpkg.com; style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://unpkg.com https://fonts.googleapis.com; img-src 'self' data: https:; font-src 'self' https://cdn.jsdelivr.net https://fonts.gstatic.com; connect-src 'self' https://cdn.jsdelivr.net https://unpkg.com https://*.push.services.mozilla.com https://fcm.googleapis.com https://updates.push.services.mozilla.com; worker-src 'self'");
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

// Maintenance mode — only admins can access
$__currentScript = basename($_SERVER['SCRIPT_NAME'] ?? '');
$__maintenanceExcluded = ['login.php', 'logout.php', 'install.php', 'cron_daily.php', 'cron_shift_reminders.php', 'cron_task_reminders.php', 'cron_certificate_expiry.php', 'cron_citizen_cert_expiry.php', 'cron_shelf_expiry.php', 'cron_incomplete_missions.php'];
if (getSetting('maintenance_mode', '0') && !in_array($__currentScript, $__maintenanceExcluded)) {
    if (isLoggedIn()) {
        $__mUser = getCurrentUser();
        if ($__mUser && $__mUser['role'] !== ROLE_SYSTEM_ADMIN) {
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

// Role preview mode: handle exit request
if (isLoggedIn() && isset($_GET['exit_preview'])) {
    unset($_SESSION['preview_role_id']);
    redirect('roles.php');
}

// External/guest accounts (partner rescue teams, users.is_external): locked down
// to Action Room for their own approved mission(s) only. Everything outside this
// allow-list — including the app's own dashboard/leaderboard/training/etc. — redirects
// away. The AJAX/JSON endpoints below already carry their own per-mission auth checks
// (isApprovedParticipant / canManageWarRoom), so allow-listing them just lets those
// existing checks answer normally instead of this gate intercepting the request first.
if (isLoggedIn() && isExternalGuest()) {
    $__extScript = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $__extAllowed = [
        'war-room.php', 'missions.php', 'profile.php', 'logout.php',
        'mission-chat.php', 'mission-photo.php', 'mission-photo-view.php',
        'mission-dispatch.php', 'mission-order.php', 'mission-sos.php',
        'mission-shortage.php', 'mission-history.php', 'mission-response-report.php',
        'mission-track.php', 'ping-location.php', 'volunteer-status.php',
        // mission-incident.php — new endpoint, same guest-lockdown gap class
        // mission-route.php hit above; allow-listed from day one this time.
        'mission-incident.php',
        'geocode-address.php', 'api-push-subscribe.php',
        'mission-certificate-print.php', 'certificate-verify.php',
        'mission-guest-debrief.php', 'export-mission-activity.php', 'export-mission-chat.php',
        // Route Orders (mission-route.php, shipped v3.124.0) was missed here
        // too — a separate list from WAR_ROOM_TIMEOUT_EXEMPT_SCRIPTS in
        // includes/auth.php (which already got this same fix), and the real
        // cause of a guest volunteer's "Ξεκίνησε" tap redirecting to
        // missions.php on every single attempt: every request to a
        // non-allow-listed script bounces a guest away unconditionally, not
        // just after idle time, so v3.140.1's timeout fix alone could never
        // have resolved this for a guest/partner-org account.
        'mission-route.php',
        // mobile-token-issue.php — native Android app requests its
        // background-ping bearer token here, session-authed like everything
        // else on this list. Guest/partner-org volunteers are exactly who
        // field GPS reliability matters most for, so they need this too.
        // (mobile-ping-location.php itself is NOT on this list: it's
        // bearer-token-authed with no session at all, so isLoggedIn() is
        // false and this whole guest gate never runs for it.)
        'mobile-token-issue.php',
    ];
    if (!in_array($__extScript, $__extAllowed, true)) {
        $__extMissionIds = getExternalGuestMissionIds(getCurrentUserId());
        if (count($__extMissionIds) === 1) {
            redirect('war-room.php?id=' . $__extMissionIds[0]);
        }
        redirect('missions.php');
    }
    unset($__extScript, $__extAllowed);
}
