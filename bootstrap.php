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
require_once __DIR__ . '/includes/countries.php';
require_once __DIR__ . '/includes/functions-core.php';
require_once __DIR__ . '/includes/functions-warroom.php';
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
//
// Built from WAR_ROOM_ACTION_SCRIPTS (includes/auth.php) plus a few guest-only
// pages below, rather than its own separately hand-maintained array — that's
// what this used to be, and mission-incident.php then mission-route.php each
// shipped to only one of the two lists, silently breaking guest access (or
// the guest's session timeout) until the gap was noticed and patched. A new
// War Room AJAX endpoint now only needs adding to WAR_ROOM_ACTION_SCRIPTS
// once to cover both behaviors — see that constant's docblock for the history.
if (isLoggedIn() && isExternalGuest()) {
    $__extUser = getCurrentUser();
    $__isMissionVisitor = isMissionVisitor($__extUser);

    // Mission Visitors (users.is_mission_visitor=1, a narrower sub-type of
    // is_external — see includes/auth.php) are scoped to exactly one OPEN
    // mission. Enforced read-time, right here, rather than hooked onto the
    // several places missions.status changes (close_mission, publish/cancel
    // in mission-view.php, quick-close in ops-dashboard.php) — a write-time
    // hook is exactly the "one call site forgets it" bug class this same
    // guest gate has already been bitten by twice (see the docblock above).
    // Every mission-visitor request passes through here anyway, so this
    // can't drift out of sync with a future close/cancel code path.
    if ($__isMissionVisitor) {
        $__vMissionId = (int) ($__extUser['mission_visitor_mission_id'] ?? 0);
        $__vStatus = $__vMissionId ? dbFetchValue("SELECT status FROM missions WHERE id = ?", [$__vMissionId]) : null;
        if ($__vStatus !== STATUS_OPEN) {
            dbExecute("UPDATE users SET deleted_at = NOW(), is_active = 0, updated_at = NOW() WHERE id = ?", [$__extUser['id']]);
            logAudit('soft_delete_user', 'users', $__extUser['id']);
            // logout() destroys the session (session_destroy()) before this
            // redirect happens, so a setFlash() here would be writing into a
            // session that's already gone — it would silently never reach
            // the next request. Pass the message via query param instead,
            // which survives the logout cleanly.
            logout();
            redirect('visitor-join.php?ended=1');
        }
    }

    $__extScript = basename($_SERVER['SCRIPT_NAME'] ?? '');
    // Mission Visitors get a strictly narrower allow-list than a partner-org
    // guest: no profile.php (no profile page, by design), no missions.php
    // (they only ever have the one mission — nothing else to list), and no
    // mobile-token-issue.php/mobile-app-setup.php (that issues a long-lived
    // native-app bearer token — more standing access than a disposable,
    // unvetted, single-mission walk-up account should get).
    $__extAllowed = $__isMissionVisitor
        ? array_merge(WAR_ROOM_ACTION_SCRIPTS, ['logout.php'])
        : array_merge(WAR_ROOM_ACTION_SCRIPTS, [
            'missions.php', 'profile.php', 'logout.php',
            'mission-certificate-print.php', 'certificate-verify.php',
            'mission-guest-debrief.php', 'export-mission-activity.php', 'export-mission-chat.php',
            // mobile-token-issue.php — native Android app requests its
            // background-ping bearer token here, session-authed like everything
            // else on this list. Guest/partner-org volunteers are exactly who
            // field GPS reliability matters most for, so they need this too.
            // (mobile-ping-location.php itself is NOT on this list: it's
            // bearer-token-authed with no session at all, so isLoggedIn() is
            // false and this whole guest gate never runs for it.)
            'mobile-token-issue.php', 'mobile-app-setup.php',
        ]);
    if (!in_array($__extScript, $__extAllowed, true)) {
        if ($__isMissionVisitor) {
            redirect(!empty($__extUser['mission_visitor_mission_id'])
                ? ('war-room.php?id=' . (int) $__extUser['mission_visitor_mission_id'])
                : 'visitor-join.php');
        }
        $__extMissionIds = getExternalGuestMissionIds(getCurrentUserId());
        if (count($__extMissionIds) === 1) {
            redirect('war-room.php?id=' . $__extMissionIds[0]);
        }
        redirect('missions.php');
    }
    unset($__extScript, $__extAllowed, $__extUser, $__isMissionVisitor);
}
