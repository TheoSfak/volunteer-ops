<?php
/**
 * VolunteerOps - Helper Functions
 */

if (!defined('VOLUNTEEROPS')) {
    die('Direct access not permitted');
}

/**
 * Redirect to a page.
 * Relative paths are resolved against BASE_URL so the Location header
 * is always an absolute URI (required by RFC 7231).
 */
function redirect($url) {
    if (!preg_match('/^https?:\/\//i', $url)) {
        $url = rtrim(BASE_URL, '/') . '/' . ltrim($url, '/');
    }
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
        $colors = PARTICIPATION_COLORS;
        $labels = PARTICIPATION_LABELS;
    } else {
        $colors = STATUS_COLORS;
        $labels = STATUS_LABELS;
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
        ROLE_SYSTEM_ADMIN     => 'danger',
        ROLE_DEPARTMENT_ADMIN => 'warning',
        ROLE_SHIFT_LEADER     => 'info',
        ROLE_VOLUNTEER        => 'primary',
    ];

    $color = $colors[$role] ?? 'secondary';
    $label = ROLE_LABELS[$role] ?? $role;

    return '<span class="badge bg-' . $color . '">' . h($label) . '</span>';
}

/**
 * Get volunteer type badge HTML (returns empty string for plain VOLUNTEER)
 */
function volunteerTypeBadge($type) {
    if (empty($type) || $type === VTYPE_VOLUNTEER) {
        return '';
    }
    $color = VOLUNTEER_TYPE_COLORS[$type] ?? 'secondary';
    $icon = VOLUNTEER_TYPE_ICONS[$type] ?? '';
    $label = VOLUNTEER_TYPE_LABELS[$type] ?? $type;
    
    return ' <span class="badge bg-' . $color . '">' . $icon . ' ' . h($label) . '</span>';
}

/**
 * Check if user is a trainee rescuer
 */
function isTraineeRescuer($user = null) {
    if ($user === null) {
        $user = getCurrentUser();
    }
    return ($user['volunteer_type'] ?? VTYPE_VOLUNTEER) === VTYPE_TRAINEE;
}

/**
 * Check if user is a rescuer (graduated)
 */
function isRescuer($user = null) {
    if ($user === null) {
        $user = getCurrentUser();
    }
    return ($user['volunteer_type'] ?? VTYPE_VOLUNTEER) === VTYPE_RESCUER;
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
/**
 * Verify CSRF token.
 */
function verifyCsrf() {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
        setFlash('error', 'Μη έγκυρο αίτημα. Παρακαλώ δοκιμάστε ξανά.');
        // Do NOT use HTTP_REFERER — it is attacker-controlled and enables open redirect
        redirect('dashboard.php');
    }
}

/**
 * Sanitize input — trims whitespace only.
 * Do NOT strip HTML here: data must be stored as-is and escaped at output
 * with h(). Stripping tags here corrupts rich-text fields (email templates,
 * mission descriptions with formatting, etc.).
 */
function sanitize($input) {
    if (is_array($input)) {
        return array_map('sanitize', $input);
    }
    return trim($input ?? '');
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
 * Render pagination links.
 * Automatically preserves all current GET query parameters so filters are
 * never lost when clicking to a different page.
 * The legacy $baseUrl parameter is accepted but ignored.
 */
function paginationLinks($pagination, $baseUrl = null) {
    if ($pagination['total_pages'] <= 1) return '';

    // Build base URL from current request, stripping any existing 'page' param
    $params = $_GET;
    unset($params['page']);
    $base = '?' . ($params ? http_build_query($params) . '&' : '');

    $html  = '<nav aria-label="Σελιδοποίηση"><ul class="pagination justify-content-center">';

    // Previous
    if ($pagination['has_prev']) {
        $html .= '<li class="page-item"><a class="page-link" href="' . h($base) . 'page=' . ($pagination['current_page'] - 1) . '">«</a></li>';
    } else {
        $html .= '<li class="page-item disabled"><span class="page-link">«</span></li>';
    }

    // Pages — collapse long ranges with ellipsis
    $current    = $pagination['current_page'];
    $total      = $pagination['total_pages'];
    $showAlways = [1, $total];

    for ($i = 1; $i <= $total; $i++) {
        $near = abs($i - $current) <= 2;
        if (!$near && !in_array($i, $showAlways)) {
            // Show a single ellipsis placeholder (only once per gap)
            if ($i === 2 || $i === $total - 1) {
                $html .= '<li class="page-item disabled"><span class="page-link">…</span></li>';
            }
            continue;
        }
        if ($i === $current) {
            $html .= '<li class="page-item active"><span class="page-link">' . $i . '</span></li>';
        } else {
            $html .= '<li class="page-item"><a class="page-link" href="' . h($base) . 'page=' . $i . '">' . $i . '</a></li>';
        }
    }

    // Next
    if ($pagination['has_next']) {
        $html .= '<li class="page-item"><a class="page-link" href="' . h($base) . 'page=' . ($pagination['current_page'] + 1) . '">»</a></li>';
    } else {
        $html .= '<li class="page-item disabled"><span class="page-link">»</span></li>';
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
 * Get application setting from database (with per-request static cache)
 * Using static instead of $_SESSION prevents stale values across users
 * when an admin updates settings.
 */
function getSetting($key, $default = null) {
    static $cache = null;

    if ($cache === null) {
        $cache = [];
        try {
            $rows = dbFetchAll("SELECT setting_key, setting_value FROM settings");
            foreach ($rows as $row) {
                $cache[$row['setting_key']] = $row['setting_value'];
            }
        } catch (Exception $e) {
            // Database might not be ready
            return $default;
        }
    }

    return $cache[$key] ?? $default;
}

/**
 * Clear settings cache (forces reload on next getSetting() call)
 */
function clearSettingsCache() {
    // No-op for backward compatibility — static cache auto-resets per request.
    // Call getSetting() after saving settings; new request will fetch fresh data.
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

// =============================================
// TRAINING MODULE HELPER FUNCTIONS
// =============================================

/**
 * Get random questions from an exam question pool
 */
function getRandomExamQuestions($examId, $count) {
    $questions = dbFetchAll(
        "SELECT * FROM training_exam_questions WHERE exam_id = ? ORDER BY RAND() LIMIT ?",
        [$examId, $count]
    );
    return $questions;
}

/**
 * Get random questions from a quiz
 */
function getRandomQuizQuestions($quizId, $count = null) {
    if ($count === null) {
        return dbFetchAll(
            "SELECT * FROM training_quiz_questions WHERE quiz_id = ? ORDER BY id",
            [$quizId]
        );
    }
    return dbFetchAll(
        "SELECT * FROM training_quiz_questions WHERE quiz_id = ? ORDER BY RAND() LIMIT ?",
        [$quizId, $count]
    );
}

/**
 * Calculate score for an attempt
 */
function calculateAttemptScore($attemptId, $attemptType) {
    $answers = dbFetchAll(
        "SELECT * FROM user_answers WHERE attempt_id = ? AND attempt_type = ?",
        [$attemptId, $attemptType]
    );
    
    $correct = 0;
    foreach ($answers as $answer) {
        if ($answer['is_correct']) {
            $correct++;
        }
    }
    
    return [
        'correct' => $correct,
        'total' => count($answers),
        'percentage' => count($answers) > 0 ? round(($correct / count($answers)) * 100, 2) : 0
    ];
}

/**
 * Check if a user can take an exam (hasn't taken it yet)
 */
function canUserTakeExam($examId, $userId) {
    // Check if user has a COMPLETED attempt for this exam
    $completedAttempt = dbFetchOne(
        "SELECT id FROM exam_attempts WHERE exam_id = ? AND user_id = ? AND completed_at IS NOT NULL",
        [$examId, $userId]
    );
    return $completedAttempt === null;
}

/**
 * Get user's exam attempt
 */
function getUserExamAttempt($examId, $userId) {
    return dbFetchOne(
        "SELECT * FROM exam_attempts WHERE exam_id = ? AND user_id = ?",
        [$examId, $userId]
    );
}

/**
 * Get question type badge HTML
 */
function questionTypeBadge($type) {
    $badges = [
        QUESTION_TYPE_MC => '<span class="badge bg-primary">Πολλαπλής Επιλογής</span>',
        QUESTION_TYPE_TF => '<span class="badge bg-info">Σωστό/Λάθος</span>',
        QUESTION_TYPE_OPEN => '<span class="badge bg-warning text-dark">Ανοιχτή</span>',
    ];
    return $badges[$type] ?? '<span class="badge bg-secondary">Άγνωστο</span>';
}

/**
 * Get pass/fail badge HTML
 */
function passFailBadge($passed) {
    return $passed 
        ? '<span class="badge bg-success">ΕΠΙΤΥΧΙΑ</span>' 
        : '<span class="badge bg-danger">ΑΠΟΤΥΧΙΑ</span>';
}

/**
 * Grade a user's answer for a question
 */
function gradeAnswer($question, $userAnswer) {
    if ($question['question_type'] === QUESTION_TYPE_OPEN) {
        // Open-ended questions require manual grading
        return null;
    }
    
    // For multiple choice and true/false
    return $userAnswer === $question['correct_option'];
}

/**
 * Format file size for display
 */
function formatFileSize($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } else {
        return $bytes . ' bytes';
    }
}

/**
 * Get user's progress in a category
 */
function getUserCategoryProgress($userId, $categoryId) {
    $progress = dbFetchOne(
        "SELECT * FROM training_user_progress WHERE user_id = ? AND category_id = ?",
        [$userId, $categoryId]
    );
    
    if (!$progress) {
        // Create initial progress record
        dbInsert(
            "INSERT INTO training_user_progress (user_id, category_id, materials_viewed_json, quizzes_completed, exams_passed) 
             VALUES (?, ?, '[]', 0, 0)",
            [$userId, $categoryId]
        );
        return [
            'materials_viewed' => [],
            'quizzes_completed' => 0,
            'exams_passed' => 0
        ];
    }
    
    return [
        'materials_viewed' => json_decode($progress['materials_viewed_json'] ?? '[]', true),
        'quizzes_completed' => (int)$progress['quizzes_completed'],
        'exams_passed' => (int)$progress['exams_passed']
    ];
}

/**
 * Update user progress for viewing a material
 */
function trackMaterialView($userId, $categoryId, $materialId) {
    $progress = getUserCategoryProgress($userId, $categoryId);
    $viewed = $progress['materials_viewed'];
    
    if (!in_array($materialId, $viewed)) {
        $viewed[] = $materialId;
        dbExecute(
            "UPDATE training_user_progress 
             SET materials_viewed_json = ?, last_activity = NOW() 
             WHERE user_id = ? AND category_id = ?",
            [json_encode($viewed), $userId, $categoryId]
        );
    }
}

/**
 * Increment quiz completion count
 */
function incrementQuizCompletion($userId, $categoryId) {
    dbExecute(
        "UPDATE training_user_progress 
         SET quizzes_completed = quizzes_completed + 1, last_activity = NOW() 
         WHERE user_id = ? AND category_id = ?",
        [$userId, $categoryId]
    );
}

/**
 * Increment quizzes passed count (for when user passes with required percentage)
 */
function incrementQuizzesPassed($userId, $categoryId) {
    dbExecute(
        "UPDATE training_user_progress 
         SET quizzes_passed = quizzes_passed + 1, last_activity = NOW() 
         WHERE user_id = ? AND category_id = ?",
        [$userId, $categoryId]
    );
}

/**
 * Increment exams passed count
 */
function incrementExamsPassed($userId, $categoryId) {
    dbExecute(
        "UPDATE training_user_progress 
         SET exams_passed = exams_passed + 1, last_activity = NOW() 
         WHERE user_id = ? AND category_id = ?",
        [$userId, $categoryId]
    );
}

/**
 * Format time duration in seconds to readable format
 */
function formatDuration($seconds) {
    if ($seconds < 60) {
        return $seconds . ' δευτερόλεπτα';
    } elseif ($seconds < 3600) {
        $minutes = floor($seconds / 60);
        $secs = $seconds % 60;
        return $minutes . ' λεπτά' . ($secs > 0 ? ' ' . $secs . ' δευτερόλεπτα' : '');
    } else {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        return $hours . ' ώρες' . ($minutes > 0 ? ' ' . $minutes . ' λεπτά' : '');
    }
}

/**
 * Check if an exam is currently available based on datetime restrictions
 */
function isExamAvailable($exam) {
    $now = time();
    
    // Check available_from
    if (!empty($exam['available_from'])) {
        $from = strtotime($exam['available_from']);
        if ($now < $from) {
            return [
                'available' => false,
                'status' => 'not_started',
                'message' => 'Το διαγώνισμα θα είναι διαθέσιμο από ' . formatDateTime($exam['available_from'])
            ];
        }
    }
    
    // Check available_until
    if (!empty($exam['available_until'])) {
        $until = strtotime($exam['available_until']);
        if ($now > $until) {
            return [
                'available' => false,
                'status' => 'expired',
                'message' => 'Το διαγώνισμα έληξε στις ' . formatDateTime($exam['available_until'])
            ];
        }
    }
    
    return [
        'available' => true,
        'status' => 'active',
        'message' => null
    ];
}

/**
 * Get exam availability status badge HTML
 */
function examAvailabilityBadge($exam) {
    $check = isExamAvailable($exam);
    
    if ($check['available']) {
        // Check if there's an end time to show countdown
        if (!empty($exam['available_until'])) {
            $until = strtotime($exam['available_until']);
            $remaining = $until - time();
            if ($remaining > 0 && $remaining < 3600) { // Less than 1 hour
                return '<span class="badge bg-warning"><i class="bi bi-clock"></i> Λήγει σε ' . formatDuration($remaining) . '</span>';
            }
        }
        return '<span class="badge bg-success"><i class="bi bi-check-circle"></i> Διαθέσιμο</span>';
    } elseif ($check['status'] === 'not_started') {
        return '<span class="badge bg-info"><i class="bi bi-hourglass"></i> Προγραμματισμένο</span>';
    } else {
        return '<span class="badge bg-danger"><i class="bi bi-x-circle"></i> Έληξε</span>';
    }
}
