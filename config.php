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
define('APP_VERSION', '3.59.2');

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

define('COMPLAINT_PRIORITY_COLORS', [
    COMPLAINT_PRIORITY_LOW => 'secondary',
    COMPLAINT_PRIORITY_MEDIUM => 'warning',
    COMPLAINT_PRIORITY_HIGH => 'danger',
]);




