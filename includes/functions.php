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
 * Return short Greek day name (Δευ, Τρι, Τετ, Πεμ, Παρ, Σαβ, Κυρ)
 */
function formatDayShort($date) {
    if (empty($date)) return '';
    $dt = is_string($date) ? new DateTime($date) : $date;
    $days = ['Κυριακή', 'Δευτέρα', 'Τρίτη', 'Τετάρτη', 'Πέμπτη', 'Παρασκευή', 'Σάββατο'];
    return $days[(int)$dt->format('w')];
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
 * Build a Google Calendar "Add Event" link for a shift.
 */
function buildGcalLink(string $title, string $startTime, string $endTime, string $location = ''): string {
    return 'https://calendar.google.com/calendar/render?action=TEMPLATE'
        . '&text=' . rawurlencode($title)
        . '&dates=' . date('Ymd\THis', strtotime($startTime)) . '/' . date('Ymd\THis', strtotime($endTime))
        . '&details=' . rawurlencode('Βάρδια εθελοντισμού')
        . '&location=' . rawurlencode($location);
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

    return '<span class="badge bg-' . $color . '" style="white-space:nowrap">' . h($label) . '</span>';
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
    if (empty($type)) {
        return '';
    }
    $color = VOLUNTEER_TYPE_COLORS[$type] ?? 'secondary';
    $icon = VOLUNTEER_TYPE_ICONS[$type] ?? '';
    $label = VOLUNTEER_TYPE_LABELS[$type] ?? $type;
    
    return ' <span class="badge bg-' . $color . '">' . $icon . ' ' . h($label) . '</span>';
}

function positionBadge($name) {
    if (empty($name)) return '';
    return ' <span class="badge" style="background-color:#6f42c1" title="Θέση στην ομάδα"><i class="bi bi-award-fill"></i> ' . h($name) . '</span>';
}

/**
 * Check if user is a trainee rescuer
 */
function isTraineeRescuer($user = null) {
    if ($user === null) {
        $user = getCurrentUser();
    }
    return ($user['volunteer_type'] ?? VTYPE_RESCUER) === VTYPE_TRAINEE;
}

/**
 * Check if user is a rescuer (graduated)
 */
function isRescuer($user = null) {
    if ($user === null) {
        $user = getCurrentUser();
    }
    return ($user['volunteer_type'] ?? VTYPE_RESCUER) === VTYPE_RESCUER;
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
    // Block all writes during role preview mode
    if (function_exists('isPreviewMode') && isPreviewMode()) {
        setFlash('error', 'Δεν μπορείτε να κάνετε αλλαγές κατά τη διάρκεια προεπισκόπησης ρόλου.');
        redirect('dashboard.php');
    }
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
    
    try {
        dbInsert(
            "INSERT INTO audit_logs (user_id, action, table_name, record_id, notes, ip_address, user_agent, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())",
            [
                $userId,
                $action,
                $tableName,
                $recordId,
                $notes,
                $_SERVER['REMOTE_ADDR'] ?? null,
                mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 1000)
            ]
        );
    } catch (Exception $e) {
        // Don't crash the app if audit_logs table is missing or broken
        error_log("[logAudit] Failed: " . $e->getMessage());
    }
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
 * Get all application settings.
 * Reuses getSetting()'s per-request cache — no duplicate DB query.
 */
function getSettings() {
    $defaults = [
        'app_name' => 'VolunteerOps',
        'app_description' => 'Σύστημα Διαχείρισης Εθελοντών',
        'app_logo' => '',
        'admin_email' => '',
        'timezone' => 'Europe/Athens',
        'date_format' => 'd/m/Y',
    ];
    
    $result = [];
    foreach ($defaults as $key => $defaultValue) {
        $result[$key] = getSetting($key, $defaultValue);
    }
    return $result;
}

// Training module functions moved to includes/training-functions.php

/**
 * Return mission_type IDs from a CSV setting key, with fallback DB lookup.
 */
function getPrereqMissionTypeIds(string $settingKey, string $fallbackSql = '', array $fallbackParams = []): array {
    static $cache = [];
    if (isset($cache[$settingKey])) return $cache[$settingKey];

    $csv = getSetting($settingKey, '');
    if ($csv !== '') {
        $cache[$settingKey] = array_map('intval', array_filter(explode(',', $csv), 'strlen'));
    } elseif ($fallbackSql) {
        $cache[$settingKey] = array_map('intval', array_column(dbFetchAll($fallbackSql, $fallbackParams), 'id'));
    } else {
        $cache[$settingKey] = [];
    }
    return $cache[$settingKey];
}

/**
 * Return the mission_type id for Τ.Ε.Π. (cached per request).
 */
function getTepMissionTypeId(): int {
    $ids = getPrereqMissionTypeIds('prereq_tep_mission_types', "SELECT id FROM mission_types WHERE name = 'Τ.Ε.Π.' LIMIT 1");
    return $ids[0] ?? 0;
}

/**
 * Return the mission_type id for Επανεκπαίδευση Εθελοντών missions (cached per request).
 */
function getEduMissionTypeId(): int {
    $ids = getPrereqMissionTypeIds('prereq_edu_mission_types', "SELECT id FROM mission_types WHERE name = 'Επανεκπαίδευση Εθελοντών' LIMIT 1");
    return $ids[0] ?? 0;
}

/**
 * Return mission_type IDs that count for annual attendance (Υγειονομική + Διασωστική).
 */
function getAttendanceMissionTypeIds(): array {
    return getPrereqMissionTypeIds('prereq_mission_types', "SELECT id FROM mission_types WHERE name IN ('Υγειονομική', 'Διασωστική')");
}

/**
 * Return true if the given mission_type_id is the Τ.Ε.Π. type.
 */
function isTepMission(int $missionTypeId): bool {
    return $missionTypeId === getTepMissionTypeId();
}

/**
 * Return true if the current user can see Τ.Ε.Π. missions.
 * Admins, trainees (TRAINEE_RESCUER), and the responsible person always can.
 */
function canSeeTep(?int $responsibleUserId = null): bool {
    if (isAdmin()) return true;
    if (isTraineeRescuer()) return true;
    if ($responsibleUserId && $responsibleUserId === getCurrentUserId()) return true;
    return false;
}

/**
 * War Room: the caller's team for a mission, or null if unassigned. Used to
 * snapshot team_id at order/receipt/ack creation time (mission_dispatch_acks,
 * mission_dispatch_receipts, mission_order_recipients) so later team
 * reassignment doesn't retroactively change historical reports.
 */
function getUserTeamIdForMission(int $missionId, int $userId): ?int {
    $teamId = dbFetchValue(
        "SELECT team_id FROM mission_team_members WHERE mission_id = ? AND user_id = ? LIMIT 1",
        [$missionId, $userId]
    );
    return $teamId ? (int) $teamId : null;
}

/**
 * War Room: load dispatch points/areas visible to $userId, each augmented with
 * its receipt (mission_dispatch_receipts, "Ελήφθη") and arrival (mission_dispatch_acks,
 * "Άφιξη") acknowledgements — shared by war-room.php (live map, twice) and
 * mission-dispatch.php (AJAX poll) so all three stay in sync.
 */
function loadMissionDispatchesForUser(int $missionId, int $userId, bool $canManageWarRoom, bool $isApprovedParticipant): array {
    $rows = dbFetchAll(
        "SELECT d.id, d.team_id, d.type, d.geo, d.label, mt.codename, mt.team_number
         FROM mission_dispatch_points d
         LEFT JOIN mission_teams mt ON mt.id = d.team_id
         WHERE d.mission_id = ?
           AND (d.team_id IS NULL OR ? = 1 OR d.team_id IN (
                SELECT team_id FROM mission_team_members WHERE user_id = ?
           ))
         ORDER BY d.created_at",
        [$missionId, $canManageWarRoom ? 1 : 0, $userId]
    );
    if (empty($rows)) {
        return [];
    }

    $dispatchIds = array_map('intval', array_column($rows, 'id'));
    $placeholders = implode(',', array_fill(0, count($dispatchIds), '?'));
    $ackRows = dbFetchAll(
        "SELECT a.dispatch_id, a.team_id, a.user_id, a.created_at, u.name AS user_name, mt.codename, mt.team_number
         FROM mission_dispatch_acks a
         JOIN users u ON u.id = a.user_id
         LEFT JOIN mission_teams mt ON mt.id = a.team_id
         WHERE a.dispatch_id IN ($placeholders)
         ORDER BY a.created_at",
        $dispatchIds
    );
    $acksByDispatch = [];
    foreach ($ackRows as $ack) {
        $acksByDispatch[(int) $ack['dispatch_id']][] = [
            'team_label' => $ack['team_id'] ? ($ack['codename'] . ' ' . $ack['team_number']) : null,
            'user_name'  => $ack['user_name'],
            'user_id'    => (int) $ack['user_id'],
            'time'       => date('H:i', strtotime($ack['created_at'])),
        ];
    }

    $receiptRows = dbFetchAll(
        "SELECT r.dispatch_id, r.user_id, r.created_at
         FROM mission_dispatch_receipts r
         WHERE r.dispatch_id IN ($placeholders)",
        $dispatchIds
    );
    $receiptsByDispatch = [];
    foreach ($receiptRows as $receipt) {
        $receiptsByDispatch[(int) $receipt['dispatch_id']][(int) $receipt['user_id']] = date('H:i', strtotime($receipt['created_at']));
    }

    $myTeamId = getUserTeamIdForMission($missionId, $userId);

    return array_map(function ($row) use ($canManageWarRoom, $isApprovedParticipant, $userId, $myTeamId, $acksByDispatch, $receiptsByDispatch) {
        $dispatchId = (int) $row['id'];
        $teamId = $row['team_id'] ? (int) $row['team_id'] : null;
        $acks = $acksByDispatch[$dispatchId] ?? [];
        $eligible = $teamId === null || $teamId === $myTeamId;

        $myAck = null;
        foreach ($acks as $ack) {
            if ($ack['user_id'] === $userId) {
                $myAck = $ack['time'];
                break;
            }
        }
        $myReceipt = $receiptsByDispatch[$dispatchId][$userId] ?? null;

        return [
            'id'          => $dispatchId,
            'type'        => $row['type'],
            'geo'         => json_decode($row['geo'], true),
            'label'       => $row['label'],
            'team_label'  => $teamId ? ($row['codename'] . ' ' . $row['team_number']) : 'Όλες οι ομάδες',
            'can_delete'  => $canManageWarRoom,
            'acks'        => array_map(fn($a) => ['team_label' => $a['team_label'] ?? '—', 'user_name' => $a['user_name'], 'time' => $a['time']], $acks),
            'my_ack'      => $myAck,
            'can_ack'     => $isApprovedParticipant && !$myAck && $eligible,
            'my_receipt'  => $myReceipt,
            'can_receive' => $isApprovedParticipant && !$myReceipt && $eligible,
        ];
    }, $rows);
}

/**
 * War Room: load field photos/videos for a mission, newest first. Visibility is
 * "everyone with War Room access sees everything" (unlike dispatches, which
 * are team-scoped) — so this is a flat query, no per-user filtering.
 */
function loadMissionPhotosForUser(int $missionId, int $currentUserId, bool $canManageWarRoom, int $limit = 30): array {
    $rows = dbFetchAll(
        "SELECT p.id, p.user_id, p.media_type, p.lat, p.lng, p.created_at, u.name AS user_name
         FROM mission_photos p
         JOIN users u ON u.id = p.user_id
         WHERE p.mission_id = ?
         ORDER BY p.created_at DESC
         LIMIT ?",
        [$missionId, $limit]
    );

    return array_map(fn($row) => [
        'id'         => (int) $row['id'],
        'media_type' => $row['media_type'],
        'user_name'  => $row['user_name'],
        'time'       => date('d/m H:i', strtotime($row['created_at'])),
        'lat'        => $row['lat'] !== null ? (float) $row['lat'] : null,
        'lng'        => $row['lng'] !== null ? (float) $row['lng'] : null,
        'can_delete' => $canManageWarRoom || (int) $row['user_id'] === $currentUserId,
    ], $rows);
}

/**
 * War Room: load $userId's own task-type orders for a mission — the "Οι Εντολές μου"
 * self-service checklist. Unlike location/photo/video (auto-fulfilled elsewhere),
 * task orders can only be marked complete by the recipient via mission-order.php
 * action=complete, so the UI needs each one's ack/fulfill state. Shared by
 * war-room.php (full render + ajax poll), like loadMissionPhotosForUser above.
 */
function loadMyTaskOrdersForUser(int $missionId, int $userId): array {
    $rows = dbFetchAll(
        "SELECT o.id AS order_id, o.task_text, o.created_at, r.acknowledged_at, r.fulfilled_at
         FROM mission_order_recipients r
         JOIN mission_orders o ON o.id = r.order_id
         WHERE o.mission_id = ? AND r.user_id = ? AND o.order_type = 'task'
         ORDER BY o.created_at DESC",
        [$missionId, $userId]
    );

    return array_map(fn($row) => [
        'order_id'        => (int) $row['order_id'],
        'task_text'       => $row['task_text'],
        'sent_at'         => date('d/m H:i', strtotime($row['created_at'])),
        'acknowledged_at' => $row['acknowledged_at'] ? date('d/m H:i', strtotime($row['acknowledged_at'])) : null,
        'fulfilled_at'    => $row['fulfilled_at'] ? date('d/m H:i', strtotime($row['fulfilled_at'])) : null,
    ], $rows);
}

/**
 * War Room: command-staff recipient set for admin-facing alerts (shortage
 * reports, and reusable for similar future cases) — system/dept admins +
 * this mission's shift leaders + the mission's responsible_user_id. Mirrors
 * the resolution already duplicated in mission-dispatch.php/mission-photo.php/
 * mission-chat.php/volunteer-status.php; centralized here for new code only,
 * not retrofitted into those four.
 */
function getMissionCommandStaffIds(int $missionId, ?int $responsibleUserId, int $excludeUserId = 0): array {
    $admins = dbFetchAll("SELECT id FROM users WHERE role IN (?, ?) AND is_active = 1", [ROLE_SYSTEM_ADMIN, ROLE_DEPARTMENT_ADMIN]);
    $leaders = dbFetchAll(
        "SELECT DISTINCT u.id FROM users u
         JOIN participation_requests pr ON pr.volunteer_id = u.id
         JOIN shifts s ON pr.shift_id = s.id
         WHERE s.mission_id = ? AND u.role = ? AND u.is_active = 1 AND pr.status = ?",
        [$missionId, ROLE_SHIFT_LEADER, PARTICIPATION_APPROVED]
    );
    $ids = array_merge(array_map('intval', array_column($admins, 'id')), array_map('intval', array_column($leaders, 'id')));
    if ($responsibleUserId) {
        $ids[] = (int) $responsibleUserId;
    }
    return array_values(array_unique(array_diff($ids, [$excludeUserId])));
}

/**
 * War Room: unresolved shortage reports for the admin "Αναφορές Έλλειψης" card.
 * Caller MUST gate this behind $canManageWarRoom before calling — titles,
 * descriptions and reporter identity are sensitive, this function has no
 * built-in permission check of its own.
 */
function loadUnresolvedShortageReportsForMission(int $missionId): array {
    $rows = dbFetchAll(
        "SELECT r.id, r.shortage_type, r.severity, r.title, r.description, r.created_at, r.acknowledged_at,
                r.team_id, u.name AS reporter_name, mt.codename, mt.team_number
         FROM mission_shortage_reports r
         JOIN users u ON u.id = r.reporter_id
         LEFT JOIN mission_teams mt ON mt.id = r.team_id
         WHERE r.mission_id = ? AND r.resolved_at IS NULL
         ORDER BY FIELD(r.severity, 'critical', 'high', 'medium', 'low'), r.created_at ASC",
        [$missionId]
    );

    return array_map(fn($row) => [
        'id'              => (int) $row['id'],
        'type_label'      => SHORTAGE_TYPE_LABELS[$row['shortage_type']] ?? $row['shortage_type'],
        'severity'        => $row['severity'],
        'severity_label'  => SHORTAGE_SEVERITY_LABELS[$row['severity']] ?? $row['severity'],
        'title'           => $row['title'],
        'description'     => $row['description'],
        'reporter_name'   => $row['reporter_name'],
        'team_label'      => $row['team_id'] ? ($row['codename'] . ' ' . $row['team_number']) : 'Χωρίς ομάδα',
        'created_at'      => date('d/m H:i', strtotime($row['created_at'])),
        'acknowledged_at' => $row['acknowledged_at'] ? date('d/m H:i', strtotime($row['acknowledged_at'])) : null,
    ], $rows);
}
