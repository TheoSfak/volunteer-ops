<?php
/**
 * VolunteerOps - Configuration
 * Ρυθμίσεις εφαρμογής
 */

// Prevent direct access
if (!defined('VOLUNTEEROPS')) {
    die('Direct access not permitted');
}

// Application
define('APP_NAME', 'VolunteerOps');
define('APP_VERSION', '3.180.0');
define('DB_SCHEMA_VERSION', 138);

// Android APK versionName, matching mobile-app/android/app/build.gradle.
// The download filename embeds this on purpose: the APK used to live at a
// fixed assets/downloads/epidrasis.apk and was replaced in place six times,
// and since the server sends no Cache-Control for it, browsers happily served
// a months-old cached copy from that unchanged URL — a real reported bug where
// tapping "update" reinstalled the exact same old version. A version-stamped
// filename is a new URL every release, so a stale cache entry cannot exist.
// Bump this in the same commit as build.gradle's versionName.
define('ANDROID_APK_VERSION', '1.1.7');

// Load local config if exists (created by installer)
if (file_exists(__DIR__ . '/config.local.php')) {
    require_once __DIR__ . '/config.local.php';
}

// BASE_URL can be overridden in config.local.php
// If not set, auto-detect from the server environment (works on both localhost and production)
if (!defined('BASE_URL')) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $docRoot = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
    $appRoot = rtrim(str_replace('\\', '/', __DIR__), '/');
    $subPath = ($docRoot && strpos($appRoot, $docRoot) === 0)
        ? substr($appRoot, strlen($docRoot))
        : '';
    define('BASE_URL', $scheme . '://' . $host . $subPath);
}

// Database defaults - overridden by config.local.php
if (!defined('DB_HOST')) define('DB_HOST', 'localhost');
if (!defined('DB_PORT')) define('DB_PORT', '3306');
if (!defined('DB_NAME')) define('DB_NAME', 'volunteer_ops');
if (!defined('DB_USER')) define('DB_USER', 'root');
if (!defined('DB_PASS')) define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// Session
define('SESSION_NAME', 'volunteerops_session');
define('SESSION_LIFETIME', 7200); // 2 hours

// Timezone
date_default_timezone_set('Europe/Athens');

// Debug mode - SET TO false IN PRODUCTION
if (!defined('DEBUG_MODE')) define('DEBUG_MODE', false);

if (DEBUG_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

// Upload settings
define('UPLOAD_MAX_SIZE', 10 * 1024 * 1024); // 10MB
define('VIDEO_MAX_SIZE', 25 * 1024 * 1024); // 25MB
define('UPLOAD_PATH', __DIR__ . '/uploads/');
define('ALLOWED_EXTENSIONS', ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png']);

// Training module upload settings
define('TRAINING_UPLOAD_PATH', __DIR__ . '/uploads/training/materials/');
define('TRAINING_MAX_FILE_SIZE', 20 * 1024 * 1024); // 20MB
define('TRAINING_ALLOWED_TYPES', ['application/pdf']);

// Gamification
define('POINTS_PER_HOUR', 10);
define('WEEKEND_MULTIPLIER', 1.5);
define('NIGHT_MULTIPLIER', 1.5);
define('MEDICAL_MULTIPLIER', 2.0);

// User roles
define('ROLE_SYSTEM_ADMIN', 'SYSTEM_ADMIN');
define('ROLE_DEPARTMENT_ADMIN', 'DEPARTMENT_ADMIN');
define('ROLE_SHIFT_LEADER', 'SHIFT_LEADER');
define('ROLE_VOLUNTEER', 'VOLUNTEER');

// Mission statuses
define('STATUS_DRAFT', 'DRAFT');
define('STATUS_OPEN', 'OPEN');
define('STATUS_CLOSED', 'CLOSED');
define('STATUS_COMPLETED', 'COMPLETED');
define('STATUS_CANCELED', 'CANCELED');

// War Room mission scoring — past this many minutes, an order-ack /
// shortage-seen / shortage-resolved gap counts as "forgotten"/off-shift
// rather than merely slow (see computeMissionScore() in functions.php).
define('MISSION_SCORE_FORGOTTEN_MINUTES', 240);

// War Room battery-alert button (war-room.php's pin popup): the fixed bar
// for actually sending a "charge your phone" order, deliberately stricter
// and separate from the configurable war_room_low_battery_pct badge
// threshold. Single source read by both the JS button's active/inactive
// state and mission-battery-alert.php's own server-side re-check — never
// duplicate this number, the whole point is one place to change it.
define('CHARGE_ALERT_THRESHOLD_PCT', 40);

// Participation statuses
define('PARTICIPATION_PENDING', 'PENDING');
define('PARTICIPATION_APPROVED', 'APPROVED');
define('PARTICIPATION_REJECTED', 'REJECTED');
define('PARTICIPATION_CANCELED_BY_USER', 'CANCELED_BY_USER');
define('PARTICIPATION_CANCELED_BY_ADMIN', 'CANCELED_BY_ADMIN');

// User registration approval statuses (users.approval_status)
define('APPROVAL_PENDING',  'PENDING');
define('APPROVAL_APPROVED', 'APPROVED');
define('APPROVAL_REJECTED', 'REJECTED');

// Shift swap request statuses
define('SWAP_PENDING_RESPONSE', 'PENDING_RESPONSE');
define('SWAP_ACCEPTED',         'ACCEPTED');
define('SWAP_DECLINED',         'DECLINED');
define('SWAP_APPROVED',         'APPROVED');
define('SWAP_REJECTED',         'REJECTED');
define('SWAP_CANCELED',         'CANCELED');

// Training question types
define('QUESTION_TYPE_MC', 'MULTIPLE_CHOICE');
define('QUESTION_TYPE_TF', 'TRUE_FALSE');
define('QUESTION_TYPE_OPEN', 'OPEN_ENDED');

// Volunteer types (2 categories: full rescuer or trainee)
define('VTYPE_TRAINEE', 'TRAINEE_RESCUER');
define('VTYPE_RESCUER', 'RESCUER');

// Greek labels (using define for PHP 7+ array constant support)
define('ROLE_LABELS', [
    ROLE_SYSTEM_ADMIN => 'Διαχειριστής Συστήματος',
    ROLE_DEPARTMENT_ADMIN => 'Διαχειριστής Τμήματος',
    ROLE_SHIFT_LEADER => 'Αρχηγός Βάρδιας',
    ROLE_VOLUNTEER => 'Εθελοντής',
]);

define('STATUS_LABELS', [
    STATUS_DRAFT => 'Πρόχειρο',
    STATUS_OPEN => 'Ανοιχτή',
    STATUS_CLOSED => 'Κλειστή',
    STATUS_COMPLETED => 'Ολοκληρωμένη',
    STATUS_CANCELED => 'Ακυρωμένη',
]);

define('PARTICIPATION_LABELS', [
    PARTICIPATION_PENDING => 'Εκκρεμεί',
    PARTICIPATION_APPROVED => 'Εγκεκριμένη',
    PARTICIPATION_REJECTED => 'Απορρίφθηκε',
    PARTICIPATION_CANCELED_BY_USER => 'Ακυρώθηκε από χρήστη',
    PARTICIPATION_CANCELED_BY_ADMIN => 'Ακυρώθηκε από διαχειριστή',
]);

define('QUESTION_TYPE_LABELS', [
    QUESTION_TYPE_MC => 'Πολλαπλής Επιλογής',
    QUESTION_TYPE_TF => 'Σωστό/Λάθος',
    QUESTION_TYPE_OPEN => 'Ανοιχτή Ερώτηση',
]);

define('MISSION_TYPES', [
    'VOLUNTEER' => 'Εθελοντική',
    'MEDICAL' => 'Υγειονομική',
]);

define('VOLUNTEER_TYPE_LABELS', [
    VTYPE_TRAINEE => 'Δόκιμος Διασώστης',
    VTYPE_RESCUER => 'Εθελοντής Διασώστης',
]);

define('VOLUNTEER_TYPE_COLORS', [
    VTYPE_TRAINEE => 'warning',
    VTYPE_RESCUER => 'success',
]);

define('VOLUNTEER_TYPE_ICONS', [
    VTYPE_TRAINEE => '📚',
    VTYPE_RESCUER => '⛑️',
]);

// mission_types.color stores a Bootstrap contextual-class suffix, not a hex
// code — 'purple'/'teal' among the seed rows have no real CSS anywhere in
// this app (no bg-purple/text-teal defined), so charts/badges that need an
// actual paintable color (Chart.js backgroundColor, inline styles) must look
// it up here rather than using the raw column value directly.
define('MISSION_TYPE_COLOR_HEX', [
    'primary'   => '#4e73df',
    'success'   => '#1cc88a',
    'info'      => '#36b9cc',
    'warning'   => '#f6c23e',
    'danger'    => '#e74a3b',
    'secondary' => '#858796',
    'dark'      => '#5a5c69',
    'teal'      => '#2c9faf',
    'purple'    => '#6f42c1',
]);

define('STATUS_COLORS', [
    STATUS_DRAFT => 'secondary',
    STATUS_OPEN => 'success',
    STATUS_CLOSED => 'warning',
    STATUS_COMPLETED => 'primary',
    STATUS_CANCELED => 'danger',
]);

define('PARTICIPATION_COLORS', [
    PARTICIPATION_PENDING => 'warning',
    PARTICIPATION_APPROVED => 'success',
    PARTICIPATION_REJECTED => 'danger',
    PARTICIPATION_CANCELED_BY_USER => 'secondary',
    PARTICIPATION_CANCELED_BY_ADMIN => 'secondary',
]);

// Also set as globals for backward compatibility
$GLOBALS['ROLE_LABELS'] = ROLE_LABELS;
$GLOBALS['STATUS_LABELS'] = STATUS_LABELS;
$GLOBALS['PARTICIPATION_LABELS'] = PARTICIPATION_LABELS;
$GLOBALS['STATUS_COLORS'] = STATUS_COLORS;
$GLOBALS['PARTICIPATION_COLORS'] = PARTICIPATION_COLORS;
$GLOBALS['VOLUNTEER_TYPE_LABELS'] = VOLUNTEER_TYPE_LABELS;
$GLOBALS['VOLUNTEER_TYPE_COLORS'] = VOLUNTEER_TYPE_COLORS;
$GLOBALS['VOLUNTEER_TYPE_ICONS'] = VOLUNTEER_TYPE_ICONS;

// Complaint statuses
define('COMPLAINT_NEW', 'NEW');
define('COMPLAINT_IN_REVIEW', 'IN_REVIEW');
define('COMPLAINT_RESOLVED', 'RESOLVED');
define('COMPLAINT_REJECTED', 'REJECTED');

// Complaint categories
define('COMPLAINT_CAT_MISSION', 'MISSION');
define('COMPLAINT_CAT_EQUIPMENT', 'EQUIPMENT');
define('COMPLAINT_CAT_BEHAVIOR', 'BEHAVIOR');
define('COMPLAINT_CAT_ADMIN', 'ADMIN');
define('COMPLAINT_CAT_OTHER', 'OTHER');

// Complaint priority
define('COMPLAINT_PRIORITY_LOW', 'LOW');
define('COMPLAINT_PRIORITY_MEDIUM', 'MEDIUM');
define('COMPLAINT_PRIORITY_HIGH', 'HIGH');

define('COMPLAINT_STATUS_LABELS', [
    COMPLAINT_NEW => 'Νέο',
    COMPLAINT_IN_REVIEW => 'Σε Εξέταση',
    COMPLAINT_RESOLVED => 'Επιλύθηκε',
    COMPLAINT_REJECTED => 'Απορρίφθηκε',
]);

define('COMPLAINT_STATUS_COLORS', [
    COMPLAINT_NEW => 'primary',
    COMPLAINT_IN_REVIEW => 'warning',
    COMPLAINT_RESOLVED => 'success',
    COMPLAINT_REJECTED => 'danger',
]);

define('COMPLAINT_CATEGORY_LABELS', [
    COMPLAINT_CAT_MISSION => 'Αποστολή / Βάρδια',
    COMPLAINT_CAT_EQUIPMENT => 'Εξοπλισμός',
    COMPLAINT_CAT_BEHAVIOR => 'Συμπεριφορά',
    COMPLAINT_CAT_ADMIN => 'Διοίκηση',
    COMPLAINT_CAT_OTHER => 'Άλλο',
]);

define('COMPLAINT_PRIORITY_LABELS', [
    COMPLAINT_PRIORITY_LOW => 'Χαμηλή',
    COMPLAINT_PRIORITY_MEDIUM => 'Μεσαία',
    COMPLAINT_PRIORITY_HIGH => 'Υψηλή',
]);

// Bug report statuses ("Αποστολή Bug" — reports an app problem to the developer)
define('BUG_REPORT_NEW', 'NEW');
define('BUG_REPORT_IN_REVIEW', 'IN_REVIEW');
define('BUG_REPORT_RESOLVED', 'RESOLVED');
define('BUG_REPORT_REJECTED', 'REJECTED');

define('BUG_REPORT_STATUS_LABELS', [
    BUG_REPORT_NEW => 'Νέο',
    BUG_REPORT_IN_REVIEW => 'Σε Εξέταση',
    BUG_REPORT_RESOLVED => 'Επιλύθηκε',
    BUG_REPORT_REJECTED => 'Απορρίφθηκε',
]);

define('BUG_REPORT_STATUS_COLORS', [
    BUG_REPORT_NEW => 'primary',
    BUG_REPORT_IN_REVIEW => 'warning',
    BUG_REPORT_RESOLVED => 'success',
    BUG_REPORT_REJECTED => 'danger',
]);

define('COMPLAINT_PRIORITY_COLORS', [
    COMPLAINT_PRIORITY_LOW => 'secondary',
    COMPLAINT_PRIORITY_MEDIUM => 'warning',
    COMPLAINT_PRIORITY_HIGH => 'danger',
]);

// War Room shortage reports
define('SHORTAGE_TYPE_LABELS', [
    'people'    => 'Προσωπικό',
    'equipment' => 'Εξοπλισμός',
    'medical'   => 'Υγειονομικό υλικό',
    'vehicle'   => 'Όχημα',
    'other'     => 'Άλλο',
]);

define('SHORTAGE_SEVERITY_LABELS', [
    'low'      => 'Χαμηλή',
    'medium'   => 'Μεσαία',
    'high'     => 'Υψηλή',
    'critical' => 'Κρίσιμη',
]);

define('SHORTAGE_SEVERITY_COLORS', [
    'low'      => 'secondary',
    'medium'   => 'info',
    'high'     => 'warning',
    'critical' => 'danger',
]);

define('SECTOR_STATUS_LABELS', [
    'not_started'   => 'Δεν έχει ξεκινήσει',
    'assigned'      => 'Ανατέθηκε',
    'en_route'      => 'Καθ\' οδόν',
    'in_progress'   => 'Σε εξέλιξη',
    'completed'     => 'Ολοκληρώθηκε',
    'needs_recheck' => 'Χρειάζεται επανέλεγχο',
]);

define('SECTOR_STATUS_COLORS', [
    'not_started'   => 'secondary',
    'assigned'      => 'info',
    'en_route'      => 'primary',
    'in_progress'   => 'warning',
    'completed'     => 'success',
    'needs_recheck' => 'danger',
]);

// War Room incident/casualty reports — severity reuses SHORTAGE_SEVERITY_LABELS/COLORS
// (same low/medium/high/critical scale) rather than duplicating it.
define('INCIDENT_TYPE_LABELS', [
    'trauma'      => 'Τραύμα',
    'cardiac'     => 'Ανακοπή',
    'respiratory' => 'Αναπνευστικό',
    'fainting'    => 'Λιποθυμία / Απώλεια αισθήσεων',
    'burn'        => 'Έγκαυμα',
    'allergic'    => 'Αλλεργική αντίδραση',
    'heat_cold'   => 'Θερμοπληξία / Υποθερμία',
    'other'       => 'Άλλο',
]);

define('INCIDENT_OUTCOME_LABELS', [
    'stayed_on_site' => 'Παρέμεινε επί τόπου',
    'transported'     => 'Διακομίστηκε',
    'declined'        => 'Αρνήθηκε βοήθεια',
    'deceased'        => 'Θανατηφόρο',
]);

define('INCIDENT_GENDER_LABELS', [
    'male'    => 'Άνδρας',
    'female'  => 'Γυναίκα',
    'unknown' => 'Άγνωστο',
]);




