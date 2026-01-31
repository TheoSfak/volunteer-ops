<?php
/**
 * VolunteerOps - Helper Functions
 */

if (!defined('VOLUNTEEROPS')) {
    die('Direct access not permitted');
}

/**
 * Redirect to a page
 */
function redirect($url) {
    header("Location: $url");
    exit;
}

/**
 * Set flash message
 */
function setFlash($type, $message) {
    $_SESSION['flash'][$type] = $message;
}

/**
 * Get and clear flash messages
 */
function getFlash() {
    $flash = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flash;
}

/**
 * Display flash messages as Bootstrap alerts
 */
function displayFlash() {
    $flash = getFlash();
    foreach ($flash as $type => $message) {
        $alertClass = $type === 'error' ? 'danger' : $type;
        echo '<div class="alert alert-' . h($alertClass) . ' alert-dismissible fade show">';
        echo h($message);
        echo '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
        echo '</div>';
    }
}

/**
 * Return flash messages as HTML string
 */
function showFlash() {
    ob_start();
    displayFlash();
    return ob_get_clean();
}

/**
 * Escape HTML
 */
function h($string) {
    return htmlspecialchars($string ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * Format date in Greek
 */
function formatDate($date, $format = 'd/m/Y') {
    if (empty($date)) return '-';
    $dt = is_string($date) ? new DateTime($date) : $date;
    return $dt->format($format);
}

/**
 * Format datetime in Greek
 */
function formatDateTime($date, $format = 'd/m/Y H:i') {
    if (empty($date)) return '-';
    $dt = is_string($date) ? new DateTime($date) : $date;
    return $dt->format($format);
}

/**
 * Format date with Greek day name
 */
function formatDateGreek($date) {
    if (empty($date)) return '-';
    $dt = is_string($date) ? new DateTime($date) : $date;
    
    $days = ['Κυριακή', 'Δευτέρα', 'Τρίτη', 'Τετάρτη', 'Πέμπτη', 'Παρασκευή', 'Σάββατο'];
    $months = ['', 'Ιανουαρίου', 'Φεβρουαρίου', 'Μαρτίου', 'Απριλίου', 'Μαΐου', 'Ιουνίου', 
               'Ιουλίου', 'Αυγούστου', 'Σεπτεμβρίου', 'Οκτωβρίου', 'Νοεμβρίου', 'Δεκεμβρίου'];
    
    $dayName = $days[(int)$dt->format('w')];
    $day = $dt->format('j');
    $month = $months[(int)$dt->format('n')];
    $year = $dt->format('Y');
    
    return "$dayName, $day $month $year";
}

/**
 * Calculate hours between two times
 */
function calculateHours($start, $end) {
    $startDt = new DateTime($start);
    $endDt = new DateTime($end);
    $diff = $startDt->diff($endDt);
    return round($diff->h + ($diff->days * 24) + ($diff->i / 60), 2);
}

/**
 * Get status badge HTML
 */
function statusBadge($status, $type = 'status') {
    if ($type === 'participation') {
        $colors = $GLOBALS['PARTICIPATION_COLORS'];
        $labels = $GLOBALS['PARTICIPATION_LABELS'];
    } else {
        $colors = $GLOBALS['STATUS_COLORS'];
        $labels = $GLOBALS['STATUS_LABELS'];
    }
    
    $color = $colors[$status] ?? 'secondary';
    $label = $labels[$status] ?? $status;
    
    return '<span class="badge bg-' . $color . '">' . h($label) . '</span>';
}

/**
 * Get role badge HTML
 */
function roleBadge($role) {
    $colors = [
        ROLE_SYSTEM_ADMIN => 'danger',
        ROLE_DEPARTMENT_ADMIN => 'warning',
        ROLE_SHIFT_LEADER => 'info',
        ROLE_VOLUNTEER => 'primary',
    ];
    
    $color = $colors[$role] ?? 'secondary';
    $label = $GLOBALS['ROLE_LABELS'][$role] ?? $role;
    
    return '<span class="badge bg-' . $color . '">' . h($label) . '</span>';
}

/**
 * CSRF token generation
 */
function csrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * CSRF token input field
 */
function csrfField() {
    return '<input type="hidden" name="csrf_token" value="' . csrfToken() . '">';
}

/**
 * Verify CSRF token
 */
function verifyCsrf() {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
        setFlash('error', 'Μη έγκυρο αίτημα. Παρακαλώ δοκιμάστε ξανά.');
        redirect($_SERVER['HTTP_REFERER'] ?? 'dashboard.php');
    }
}

/**
 * Sanitize input
 */
function sanitize($input) {
    if (is_array($input)) {
        return array_map('sanitize', $input);
    }
    return trim(strip_tags($input));
}

/**
 * Get POST value
 */
function post($key, $default = '') {
    return isset($_POST[$key]) ? sanitize($_POST[$key]) : $default;
}

/**
 * Get GET value
 */
function get($key, $default = '') {
    return isset($_GET[$key]) ? sanitize($_GET[$key]) : $default;
}

/**
 * Log audit trail
 */
function logAudit($action, $tableName = null, $recordId = null, $oldData = null, $newData = null) {
    $userId = getCurrentUserId();
    
    // If newData is provided, convert both oldData and newData to JSON for notes
    $notes = null;
    if ($newData !== null) {
        $notes = json_encode(['old' => $oldData, 'new' => $newData]);
    } elseif (is_array($oldData)) {
        // If oldData is array but no newData, treat oldData as additional data
        $notes = json_encode($oldData);
    } else {
        // Otherwise, oldData is just notes text
        $notes = $oldData;
    }
    
    dbInsert(
        "INSERT INTO audit_logs (user_id, action, table_name, record_id, notes, ip_address, created_at) 
         VALUES (?, ?, ?, ?, ?, ?, NOW())",
        [
            $userId,
            $action,
            $tableName,
            $recordId,
            $notes,
            $_SERVER['REMOTE_ADDR'] ?? null
        ]
    );
}

/**
 * Pagination helper
 */
function paginate($totalItems, $currentPage = 1, $perPage = 20) {
    $totalPages = ceil($totalItems / $perPage);
    $currentPage = max(1, min($currentPage, $totalPages));
    $offset = ($currentPage - 1) * $perPage;
    
    return [
        'total' => $totalItems,
        'per_page' => $perPage,
        'current_page' => $currentPage,
        'total_pages' => $totalPages,
        'offset' => $offset,
        'has_prev' => $currentPage > 1,
        'has_next' => $currentPage < $totalPages,
    ];
}

/**
 * Render pagination links
 */
function paginationLinks($pagination, $baseUrl = '?') {
    if ($pagination['total_pages'] <= 1) return '';
    
    $html = '<nav><ul class="pagination justify-content-center">';
    
    // Previous
    if ($pagination['has_prev']) {
        $html .= '<li class="page-item"><a class="page-link" href="' . $baseUrl . 'page=' . ($pagination['current_page'] - 1) . '">«</a></li>';
    }
    
    // Pages
    for ($i = 1; $i <= $pagination['total_pages']; $i++) {
        if ($i == $pagination['current_page']) {
            $html .= '<li class="page-item active"><span class="page-link">' . $i . '</span></li>';
        } else {
            $html .= '<li class="page-item"><a class="page-link" href="' . $baseUrl . 'page=' . $i . '">' . $i . '</a></li>';
        }
    }
    
    // Next
    if ($pagination['has_next']) {
        $html .= '<li class="page-item"><a class="page-link" href="' . $baseUrl . 'page=' . ($pagination['current_page'] + 1) . '">»</a></li>';
    }
    
    $html .= '</ul></nav>';
    return $html;
}

/**
 * Check if request is POST
 */
function isPost() {
    return $_SERVER['REQUEST_METHOD'] === 'POST';
}

/**
 * Check if request is AJAX
 */
function isAjax() {
    return !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

/**
 * JSON response
 */
function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Get application setting from database (with caching)
 */
function getSetting($key, $default = null) {
    // Check session cache first
    if (!isset($_SESSION['app_settings_cache'])) {
        $_SESSION['app_settings_cache'] = [];
        $_SESSION['app_settings_loaded_at'] = time();
    }
    
    // Reload cache if older than 5 minutes
    if ((time() - ($_SESSION['app_settings_loaded_at'] ?? 0)) > 300) {
        $_SESSION['app_settings_cache'] = [];
        $_SESSION['app_settings_loaded_at'] = time();
    }
    
    // Return from session cache if exists
    if (isset($_SESSION['app_settings_cache'][$key])) {
        return $_SESSION['app_settings_cache'][$key];
    }
    
    // Load from database
    try {
        if (empty($_SESSION['app_settings_cache'])) {
            $rows = dbFetchAll("SELECT setting_key, setting_value FROM settings");
            foreach ($rows as $row) {
                $_SESSION['app_settings_cache'][$row['setting_key']] = $row['setting_value'];
            }
        }
        
        return $_SESSION['app_settings_cache'][$key] ?? $default;
    } catch (Exception $e) {
        // Database might not be ready
        return $default;
    }
}

/**
 * Clear settings cache
 */
function clearSettingsCache() {
    unset($_SESSION['app_settings_cache']);
    unset($_SESSION['app_settings_loaded_at']);
}

/**
 * Input validation helpers
 */
function validateRequired($value, $fieldName = 'Πεδίο') {
    if (empty($value)) {
        return "$fieldName είναι υποχρεωτικό";
    }
    return null;
}

function validateEmail($email) {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return "Μη έγκυρη διεύθυνση email";
    }
    return null;
}

function validateLength($value, $min, $max, $fieldName = 'Πεδίο') {
    $len = mb_strlen($value);
    if ($len < $min) {
        return "$fieldName πρέπει να είναι τουλάχιστον $min χαρακτήρες";
    }
    if ($len > $max) {
        return "$fieldName δεν μπορεί να υπερβαίνει τους $max χαρακτήρες";
    }
    return null;
}

function validateNumber($value, $min = null, $max = null, $fieldName = 'Πεδίο') {
    if (!is_numeric($value)) {
        return "$fieldName πρέπει να είναι αριθμός";
    }
    $num = (float)$value;
    if ($min !== null && $num < $min) {
        return "$fieldName πρέπει να είναι τουλάχιστον $min";
    }
    if ($max !== null && $num > $max) {
        return "$fieldName δεν μπορεί να υπερβαίνει το $max";
    }
    return null;
}

function validateDate($date, $fieldName = 'Ημερομηνία') {
    $d = DateTime::createFromFormat('Y-m-d', $date);
    if (!$d || $d->format('Y-m-d') !== $date) {
        return "$fieldName δεν είναι έγκυρη";
    }
    return null;
}

function validateDateTime($datetime, $fieldName = 'Ημερομηνία/Ώρα') {
    $d = DateTime::createFromFormat('Y-m-d H:i', $datetime);
    if (!$d || $d->format('Y-m-d H:i') !== $datetime) {
        return "$fieldName δεν είναι έγκυρη";
    }
    return null;
}

/**
 * Validate multiple fields and return all errors
 */
function validateFields($validations) {
    $errors = [];
    foreach ($validations as $validation) {
        $error = call_user_func_array($validation[0], array_slice($validation, 1));
        if ($error !== null) {
            $errors[] = $error;
        }
    }
    return $errors;
}

/**
 * Get all application settings (with caching)
 */
function getSettings() {
    static $cache = null;
    
    if ($cache === null) {
        $cache = [];
        $defaults = [
            'app_name' => 'VolunteerOps',
            'app_description' => 'Σύστημα Διαχείρισης Εθελοντών',
            'app_logo' => '',
            'admin_email' => '',
            'timezone' => 'Europe/Athens',
            'date_format' => 'd/m/Y',
        ];
        
        try {
            $rows = dbFetchAll("SELECT setting_key, setting_value FROM settings");
            foreach ($rows as $row) {
                $cache[$row['setting_key']] = $row['setting_value'];
            }
        } catch (Exception $e) {
            return $defaults;
        }
        
        // Apply defaults for missing keys
        foreach ($defaults as $key => $value) {
            if (!isset($cache[$key])) {
                $cache[$key] = $value;
            }
        }
    }
    
    return $cache;
}
