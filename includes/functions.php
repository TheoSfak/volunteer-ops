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
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string) $_POST['csrf_token'])) {
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
 * External/guest accounts (users.is_external) are locked to Action Room for
 * only the mission(s) an admin has approved them on — this is that scope,
 * derived from the same participation_requests rows normal volunteers use
 * (an admin approves them via mission-view.php's "manual_add_volunteer",
 * no separate binding needed). Most-recently-started mission first.
 */
function getExternalGuestMissionIds(int $userId): array {
    $rows = dbFetchAll(
        "SELECT DISTINCT s.mission_id, MAX(s.start_time) as last_start
         FROM participation_requests pr
         JOIN shifts s ON pr.shift_id = s.id
         JOIN missions m ON m.id = s.mission_id AND m.deleted_at IS NULL
         WHERE pr.volunteer_id = ? AND pr.status = ?
         GROUP BY s.mission_id
         ORDER BY last_start DESC",
        [$userId, PARTICIPATION_APPROVED]
    );
    return array_map('intval', array_column($rows, 'mission_id'));
}

// Team color palette, same index basis as war-room.php's own MISSION_TEAM_CODENAMES
// (team N gets codename[N % 26] and color[N % 8] — colors cycle every 8 teams
// since a colorblind-safe categorical palette only stays distinguishable up to
// ~8 slots; ordering was picked, and validated with the dataviz skill's
// scripts/validate_palette.js, to lead with red for Alpha / green for Bravo as
// requested, then fill the rest by worst-adjacent-pair CVD separation).
// Moved here from war-room.php once loadMissionDispatchesForUser() below also
// needed the same [bg, fg] pair, for the dispatch map's permanent team-label
// pills — same palette team badges/pins/trail lines already use.
if (!defined('MISSION_TEAM_COLORS')) {
    define('MISSION_TEAM_COLORS', ['#e34948','#008300','#4a3aa7','#eda100','#2a78d6','#e87ba4','#1baf7a','#eb6834']);
}
if (!defined('MISSION_TEAM_COLOR_TEXT')) {
    define('MISSION_TEAM_COLOR_TEXT', ['#008300' => '#fff', '#4a3aa7' => '#fff']);
}
/** Returns [background, text] hex pair for a team badge; null color falls back to the old bg-dark look. */
function teamBadgeColors(?string $color): array {
    if (!$color) return ['#212529', '#fff'];
    return [$color, MISSION_TEAM_COLOR_TEXT[$color] ?? '#000'];
}

/**
 * War Room: load dispatch points/areas visible to $userId, each augmented with
 * its receipt (mission_dispatch_receipts, "Ελήφθη") and arrival (mission_dispatch_acks,
 * "Άφιξη") acknowledgements — shared by war-room.php (live map, twice) and
 * mission-dispatch.php (AJAX poll) so all three stay in sync.
 */
function loadMissionDispatchesForUser(int $missionId, int $userId, bool $canManageWarRoom, bool $isApprovedParticipant): array {
    $rows = dbFetchAll(
        "SELECT d.id, d.team_id, d.type, d.geo, d.label, mt.codename, mt.team_number, mt.color
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
        "SELECT a.dispatch_id, a.team_id, a.user_id, a.created_at, u.name AS user_name,
                u.is_external, u.guest_org_name, mt.codename, mt.team_number
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
            'team_label'     => $ack['team_id'] ? ($ack['codename'] . ' ' . $ack['team_number']) : null,
            'user_name'      => $ack['user_name'],
            'is_external'    => (bool) $ack['is_external'],
            'guest_org_name' => $ack['guest_org_name'],
            'user_id'        => (int) $ack['user_id'],
            'time'           => date('H:i', strtotime($ack['created_at'])),
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
        [$teamColorBg, $teamColorFg] = teamBadgeColors($teamId ? $row['color'] : null);

        return [
            'id'          => $dispatchId,
            'type'        => $row['type'],
            'geo'         => json_decode($row['geo'], true),
            'label'       => $row['label'],
            'team_label'  => $teamId ? ($row['codename'] . ' ' . $row['team_number']) : t('common.all_teams'),
            'team_color_bg' => $teamColorBg,
            'team_color_fg' => $teamColorFg,
            'can_delete'  => $canManageWarRoom,
            'acks'        => array_map(fn($a) => [
                'team_label' => $a['team_label'] ?? '—', 'user_name' => $a['user_name'],
                'is_external' => $a['is_external'], 'guest_org_name' => $a['guest_org_name'], 'time' => $a['time'],
            ], $acks),
            'my_ack'      => $myAck,
            'can_ack'     => $isApprovedParticipant && !$myAck && $eligible,
            'my_receipt'  => $myReceipt,
            'can_receive' => $isApprovedParticipant && !$myReceipt && $eligible,
        ];
    }, $rows);
}

/**
 * War Room "Πορεία Ομάδων": full historical GPS trail per volunteer for a
 * mission (not just the latest ping like the live map's $loadPins), grouped
 * by user_id (not user+shift — a volunteer has at most one team per mission
 * per uniq_mission_user, so merging pings across their shift assignments
 * into one continuous trail is correct). $teamId = 0 means all teams; the
 * mtm.mission_id = s.mission_id join condition makes a team_id from another
 * mission safely match nothing, no separate ownership check needed.
 * Auto-captured pings (source='auto') are excluded unless $includeAuto is
 * true — admin-only opt-in filter, off by default everywhere else.
 */
function loadMissionTrailForMission(int $missionId, int $teamId, bool $includeAuto): array {
    $rows = dbFetchAll(
        "SELECT vp.user_id, vp.lat, vp.lng, vp.created_at, vp.source,
                u.name, mtm.team_id, mt.color AS team_color
         FROM volunteer_pings vp
         JOIN shifts s ON s.id = vp.shift_id
         JOIN users u ON u.id = vp.user_id
         LEFT JOIN mission_team_members mtm ON mtm.mission_id = s.mission_id AND mtm.user_id = vp.user_id
         LEFT JOIN mission_teams mt ON mt.id = mtm.team_id
         WHERE s.mission_id = ?
           AND (? = 0 OR mtm.team_id = ?)
           AND (vp.source = 'manual' OR ? = 1)
         ORDER BY vp.user_id, vp.created_at
         LIMIT 20000",
        [$missionId, $teamId, $teamId, $includeAuto ? 1 : 0]
    );

    $trailsByUser = [];
    foreach ($rows as $row) {
        $userId = (int) $row['user_id'];
        if (!isset($trailsByUser[$userId])) {
            $trailsByUser[$userId] = [
                'user_id'    => $userId,
                'name'       => $row['name'],
                'team_color' => $row['team_color'],
                'points'     => [],
            ];
        }
        // Flat safety ceiling per trail, not a real pagination control — this
        // codebase has no window-function usage anywhere, so trimming here in
        // PHP (keep the most recent points) matches its existing style.
        if (count($trailsByUser[$userId]['points']) >= 1000) {
            array_shift($trailsByUser[$userId]['points']);
        }
        $trailsByUser[$userId]['points'][] = [
            'lat'    => (float) $row['lat'],
            'lng'    => (float) $row['lng'],
            // 'd/m H:i' (not the live dot's bare 'H:i') — a trail is often
            // reviewed on a different day than it was recorded. Formatted
            // server-side since PHP/MySQL are both synced to Europe/Athens;
            // sending raw created_at for client-side Date parsing would use
            // the viewer's own browser timezone instead.
            'time'   => date('d/m H:i', strtotime($row['created_at'])),
            'source' => $row['source'],
        ];
    }

    return array_values($trailsByUser);
}

/**
 * War Room: load field photos/videos for a mission, newest first. Visibility is
 * "everyone with War Room access sees everything" (unlike dispatches, which
 * are team-scoped) — so this is a flat query, no per-user filtering.
 */
function loadMissionPhotosForUser(int $missionId, int $currentUserId, bool $canManageWarRoom, int $limit = 30): array {
    $rows = dbFetchAll(
        "SELECT p.id, p.user_id, p.media_type, p.lat, p.lng, p.created_at, u.name AS user_name,
                u.is_external, u.guest_org_name, mt.codename, mt.team_number
         FROM mission_photos p
         JOIN users u ON u.id = p.user_id
         LEFT JOIN mission_team_members mtm ON mtm.user_id = p.user_id AND mtm.mission_id = p.mission_id
         LEFT JOIN mission_teams mt ON mt.id = mtm.team_id
         WHERE p.mission_id = ?
         ORDER BY p.created_at DESC
         LIMIT ?",
        [$missionId, $limit]
    );

    return array_map(fn($row) => [
        'id'             => (int) $row['id'],
        'media_type'     => $row['media_type'],
        'user_name'      => $row['user_name'],
        'is_external'    => (bool) $row['is_external'],
        'guest_org_name' => $row['guest_org_name'],
        'team_label'     => $row['codename'] ? $row['codename'] . ' ' . $row['team_number'] : null,
        'time'           => date('d/m H:i', strtotime($row['created_at'])),
        'lat'            => $row['lat'] !== null ? (float) $row['lat'] : null,
        'lng'            => $row['lng'] !== null ? (float) $row['lng'] : null,
        'can_delete'     => $canManageWarRoom || (int) $row['user_id'] === $currentUserId,
    ], $rows);
}

/**
 * Action Room: load every shared battle-map annotation (freehand/arrow/text)
 * for a mission. Unlike dispatch points, there's no per-team targeting or
 * per-viewer ack state — every approved participant sees every annotation,
 * and only command staff can create/delete (enforced entirely by whether the
 * drawing toolbar renders at all, not by a per-row flag here).
 */
function loadMissionAnnotationsForMission(int $missionId): array {
    $rows = dbFetchAll(
        "SELECT a.id, a.type, a.geo, a.label, u.name AS created_by_name, a.created_at
         FROM mission_annotations a
         JOIN users u ON u.id = a.created_by
         WHERE a.mission_id = ?
         ORDER BY a.created_at",
        [$missionId]
    );
    return array_map(fn($row) => [
        'id'              => (int) $row['id'],
        'type'            => $row['type'],
        'geo'             => json_decode($row['geo'], true),
        'label'           => $row['label'],
        'created_by_name' => $row['created_by_name'],
        'time'            => date('H:i', strtotime($row['created_at'])),
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
 * War Room: whether $userId has admin/manager-level control of an Action Room
 * (close the mission, broadcast, manage teams, issue orders, view reports, ...).
 * External/guest accounts (users.is_external) are hard-excluded here regardless
 * of hasPagePermission()/responsible_user_id — bootstrap.php's allow-list only
 * restricts *which pages* a guest can reach, this is what stops them gaining
 * admin *powers* once on an allowed one. Concretely: mission-form.php's
 * "Υπεύθυνος Αποστολής" dropdown has no reason to exclude guests by itself
 * (fixed separately, defense-in-depth), so without this check here, picking a
 * partner org's lead as a mission's responsible_user_id would silently hand
 * that guest full Action Room admin powers for that mission.
 */
function canManageActionRoom(?int $responsibleUserId, int $userId): bool {
    if (isExternalGuest()) return false;
    return hasPagePermission('missions_manage') || ($responsibleUserId !== null && $responsibleUserId === $userId);
}

/**
 * War Room: appends an always-visible small badge showing a guest's home
 * rescue-team/organization right after their name — only for is_external
 * accounts (users.guest_org_name). Everyone else's name renders exactly as
 * before (plain escaped text, byte-identical to a bare h($name) call). The
 * badge itself also carries a native title= tooltip (full org name, in case
 * it's ever truncated visually) on top of always being visible — "visible at
 * a glance AND on hover", not hover-only. Used everywhere a person's name is
 * shown app-wide, including the guest's own profile hero (not just Action
 * Room). Mirrored client-side by the same-named JS function in war-room.php
 * for names that render from a JS poll (chat, media, dispatch, SOS, shortage).
 */
function guestNameHtml(string $name, bool $isExternal, ?string $orgName): string {
    if (!$isExternal) {
        return h($name);
    }
    $org = ($orgName !== null && trim($orgName) !== '') ? $orgName : t('guest.org_unknown');
    return h($name) . '<sup class="guest-org-badge" title="' . h(t('guest.org_tooltip', ['org' => $org])) . '">' . h($org) . '</sup>';
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
 * Guest Mission Debrief: one-time invite to every guest approved-participant
 * of $missionId who doesn't already have a mission_guest_debriefs row for it.
 * Call this from every place a mission's status first enters {CLOSED,
 * COMPLETED} (see call sites in mission-view.php/war-room.php/
 * ops-dashboard.php/dashboard.php) — the NOT EXISTS guard means calling it
 * again for an already-notified/already-submitted guest is always a no-op,
 * so callers don't need to track "have I already invited this guest" state
 * themselves (this also absorbs the case where an admin manually reopens a
 * CLOSED mission back to OPEN via mission-form.php and re-closes it later).
 */
function notifyGuestsMissionDebriefEligible(int $missionId): void {
    $mission = dbFetchOne("SELECT title FROM missions WHERE id = ?", [$missionId]);
    if (!$mission) return;

    $guestIds = array_column(dbFetchAll(
        "SELECT DISTINCT u.id
         FROM participation_requests pr
         JOIN shifts s ON s.id = pr.shift_id
         JOIN users u ON u.id = pr.volunteer_id
         WHERE s.mission_id = ? AND pr.status = ? AND u.is_external = 1
           AND NOT EXISTS (
               SELECT 1 FROM mission_guest_debriefs mgd
               WHERE mgd.mission_id = ? AND mgd.user_id = u.id
           )",
        [$missionId, PARTICIPATION_APPROVED, $missionId]
    ), 'id');
    if (empty($guestIds)) return;

    $url = rtrim(BASE_URL, '/') . '/mission-guest-debrief.php?mission_id=' . $missionId;
    $langByUserId = getUserLanguages($guestIds);
    foreach ($guestIds as $guestId) {
        $lang = $langByUserId[$guestId] ?? DEFAULT_LANGUAGE;
        sendNotification(
            (int) $guestId,
            t('notif.guest_debrief_invite_title', [], $lang),
            t('notif.guest_debrief_invite_message', ['mission' => $mission['title']], $lang),
            'info',
            'mission_guest_debrief_invite',
            ['url' => $url]
        );
    }
}

/**
 * Guest Mission Debrief: quiet FYI to command staff when a guest submits
 * their own feedback. Mirrors notifyPhotoReceived()'s (mission-photo.php)
 * recipient-resolution shape.
 */
function notifyCommandStaffGuestDebriefSubmitted(int $missionId, ?int $responsibleUserId, int $guestId, string $guestName, string $missionTitle): void {
    $recipientIds = getMissionCommandStaffIds($missionId, $responsibleUserId, $guestId);
    if (empty($recipientIds)) return;

    $url = rtrim(BASE_URL, '/') . '/mission-debrief.php?id=' . $missionId;
    $langByUserId = getUserLanguages($recipientIds);
    foreach ($recipientIds as $recipientId) {
        $lang = $langByUserId[$recipientId] ?? DEFAULT_LANGUAGE;
        sendNotification(
            $recipientId,
            t('notif.guest_debrief_submitted_title', [], $lang),
            t('notif.guest_debrief_submitted_message', ['name' => $guestName, 'mission' => $missionTitle], $lang),
            'info',
            'mission_guest_debrief_submitted',
            ['url' => $url]
        );
    }
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
                r.team_id, u.name AS reporter_name, u.is_external, u.guest_org_name, mt.codename, mt.team_number
         FROM mission_shortage_reports r
         JOIN users u ON u.id = r.reporter_id
         LEFT JOIN mission_teams mt ON mt.id = r.team_id
         WHERE r.mission_id = ? AND r.resolved_at IS NULL AND r.not_resolved_at IS NULL
         ORDER BY FIELD(r.severity, 'critical', 'high', 'medium', 'low'), r.created_at ASC",
        [$missionId]
    );

    return array_map(fn($row) => [
        'id'              => (int) $row['id'],
        'type_label'      => shortageTypeLabel($row['shortage_type']),
        'severity'        => $row['severity'],
        'severity_label'  => shortageSeverityLabel($row['severity']),
        'title'           => $row['title'],
        'description'     => $row['description'],
        'reporter_name'   => $row['reporter_name'],
        'is_external'     => (bool) $row['is_external'],
        'guest_org_name'  => $row['guest_org_name'],
        'team_label'      => $row['team_id'] ? ($row['codename'] . ' ' . $row['team_number']) : t('history.no_team_capitalized'),
        'created_at'      => date('d/m H:i', strtotime($row['created_at'])),
        'acknowledged_at' => $row['acknowledged_at'] ? date('d/m H:i', strtotime($row['acknowledged_at'])) : null,
    ], $rows);
}

/**
 * War Room: open (unresolved) SOS alerts for the command-staff alarm overlay +
 * "Ειδοποιήσεις SOS" card. Caller MUST gate this behind $canManageWarRoom before
 * calling — reporter identity and live location are sensitive, this function has
 * no built-in permission check of its own. Mirrors loadUnresolvedShortageReportsForMission,
 * except user_name/team_label are escaped here (not left raw) since this feeds an
 * auto-triggered, no-click-required surface rather than a manually-opened list.
 */
function loadOpenSosAlertsForMission(int $missionId): array {
    $rows = dbFetchAll(
        "SELECT a.id, a.pr_id, a.lat, a.lng, a.created_at, a.acknowledged_at,
                a.team_id, u.name AS user_name, u.is_external, u.guest_org_name, mt.codename, mt.team_number
         FROM mission_sos_alerts a
         JOIN users u ON u.id = a.user_id
         LEFT JOIN mission_teams mt ON mt.id = a.team_id
         WHERE a.mission_id = ? AND a.resolved_at IS NULL
         ORDER BY a.created_at ASC",
        [$missionId]
    );

    // user_name is deliberately NOT pre-escaped here (unlike before) — it now
    // rides along with is_external/guest_org_name for the JS-side
    // guestNameHtml() helper to wrap and escape together, same as the
    // dispatch-acks/media loaders. team_label keeps its original
    // pre-escaped-server-side treatment, unchanged.
    return array_map(fn($row) => [
        'id'              => (int) $row['id'],
        'user_name'       => $row['user_name'],
        'is_external'     => (bool) $row['is_external'],
        'guest_org_name'  => $row['guest_org_name'],
        'team_label'      => h($row['team_id'] ? ($row['codename'] . ' ' . $row['team_number']) : t('history.no_team_capitalized')),
        'lat'             => $row['lat'] !== null ? (float) $row['lat'] : null,
        'lng'             => $row['lng'] !== null ? (float) $row['lng'] : null,
        'created_at'      => date('d/m H:i', strtotime($row['created_at'])),
        'acknowledged_at' => $row['acknowledged_at'] ? date('d/m H:i', strtotime($row['acknowledged_at'])) : null,
    ], $rows);
}

/**
 * War Room: user_ids currently "present" on this mission's War Room — last
 * touched the 15s ajax poll within the last 2x its interval. Shared by
 * war-room.php's full-page render (initial dot state) and its own ajax
 * branch (per-poll dot state) so both compute "online" identically.
 */
function loadOnlinePresenceUserIds(int $missionId): array {
    $rows = dbFetchAll(
        "SELECT user_id FROM mission_presence WHERE mission_id = ? AND last_seen_at > DATE_SUB(NOW(), INTERVAL 30 SECOND)",
        [$missionId]
    );
    return array_map('intval', array_column($rows, 'user_id'));
}

function reportMinutesBetween(?string $from, ?string $to): ?float {
    if (!$from || !$to) {
        return null;
    }
    return round((strtotime($to) - strtotime($from)) / 60, 1);
}

/**
 * Great-circle distance between two GPS points, in meters (Haversine).
 * Used to flag a volunteer_pings row as "in motion" vs. stationary GPS
 * jitter — see $loadPins in war-room.php.
 */
function gpsDistanceMeters(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $earthRadiusMeters = 6371000;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $earthRadiusMeters * $c;
}

/**
 * War Room: response-time computation shared by the live report modal
 * (mission-response-report.php) and the archival print export
 * (mission-report-print.php) — merges the generic mission_orders/
 * mission_order_recipients system with dispatch's native ack/receipt
 * tables into one normalized detail list, same merge-in-PHP technique
 * mission-history.php uses. Returns raw, unformatted datetime strings
 * (plus already-computed *_minutes deltas) — callers apply their own
 * date() format, since the live report and the archival export intentionally
 * format timestamps differently (compact vs. with year).
 */
function computeMissionResponseReport(int $missionId, ?string $lang = null): array {
    // $lang defaults to null (-> DEFAULT_LANGUAGE, i.e. today's Greek text) so the
    // two out-of-scope callers (mission-stats.php, mission-report-print.php) are
    // completely unaffected; only mission-response-report.php passes the viewer's
    // real language. See includes/i18n.php's note on SHORTAGE_TYPE_LABELS for why
    // this function must not change shape for those two callers.
    $lang = $lang ?? DEFAULT_LANGUAGE;
    $typeMeta = [
        'location' => t('report.type_location', [], $lang),
        'photo'    => t('report.type_photo', [], $lang),
        'video'    => t('report.type_video', [], $lang),
        'task'     => t('report.type_task', [], $lang),
        'message'  => t('report.type_message', [], $lang),
        'dispatch' => t('report.type_dispatch', [], $lang),
        'return_to_base' => t('report.type_return_to_base', [], $lang),
    ];

    $teamLabels = [];
    foreach (dbFetchAll("SELECT id, codename, team_number FROM mission_teams WHERE mission_id = ?", [$missionId]) as $t) {
        $teamLabels[(int) $t['id']] = $t['codename'] . ' ' . $t['team_number'];
    }

    $detail = [];

    // ── location/photo/video/task/message orders ────────────────────────────────
    $orderRows = dbFetchAll(
        "SELECT o.order_type, o.task_text, o.created_at AS sent_at, r.team_id, r.user_id, u.name AS user_name,
                r.acknowledged_at, r.fulfilled_at
         FROM mission_order_recipients r
         JOIN mission_orders o ON o.id = r.order_id
         JOIN users u ON u.id = r.user_id
         WHERE o.mission_id = ?
         ORDER BY o.created_at DESC",
        [$missionId]
    );
    foreach ($orderRows as $row) {
        $teamId = $row['team_id'] ? (int) $row['team_id'] : null;
        $detail[] = [
            'type_label'  => $typeMeta[$row['order_type']] ?? $row['order_type'],
            'order_type'  => $row['order_type'],
            'team_id'     => $teamId,
            'team_label'  => $teamId ? ($teamLabels[$teamId] ?? '—') : t('history.no_team_capitalized', [], $lang),
            'user_id'     => (int) $row['user_id'],
            'user_name'   => $row['user_name'],
            'label'       => in_array($row['order_type'], ['task', 'message'], true) ? $row['task_text'] : null,
            'sent_at'     => $row['sent_at'],
            'ack_at'      => $row['acknowledged_at'],
            'fulfill_at'  => $row['fulfilled_at'],
        ];
    }

    // ── dispatch orders ──────────────────────────────────────────────────────────
    $dispatchRows = dbFetchAll(
        "SELECT id, label, created_at AS sent_at, team_id FROM mission_dispatch_points WHERE mission_id = ?",
        [$missionId]
    );
    if (!empty($dispatchRows)) {
        $dispatchById = [];
        foreach ($dispatchRows as $d) {
            $dispatchById[(int) $d['id']] = $d;
        }
        $dispatchIds = array_keys($dispatchById);
        $placeholders = implode(',', array_fill(0, count($dispatchIds), '?'));

        $byDispatchUser = [];
        foreach (dbFetchAll(
            "SELECT r.dispatch_id, r.user_id, r.team_id, r.created_at, u.name AS user_name
             FROM mission_dispatch_receipts r JOIN users u ON u.id = r.user_id
             WHERE r.dispatch_id IN ($placeholders)",
            $dispatchIds
        ) as $r) {
            $key = $r['dispatch_id'] . ':' . $r['user_id'];
            $byDispatchUser[$key] = [
                'dispatch_id' => (int) $r['dispatch_id'],
                'user_id'     => (int) $r['user_id'],
                'user_name'   => $r['user_name'],
                'team_id'     => $r['team_id'] ? (int) $r['team_id'] : null,
                'ack_at'      => $r['created_at'],
                'fulfill_at'  => null,
            ];
        }
        foreach (dbFetchAll(
            "SELECT a.dispatch_id, a.user_id, a.team_id, a.created_at, u.name AS user_name
             FROM mission_dispatch_acks a JOIN users u ON u.id = a.user_id
             WHERE a.dispatch_id IN ($placeholders)",
            $dispatchIds
        ) as $a) {
            $key = $a['dispatch_id'] . ':' . $a['user_id'];
            if (!isset($byDispatchUser[$key])) {
                $byDispatchUser[$key] = [
                    'dispatch_id' => (int) $a['dispatch_id'],
                    'user_id'     => (int) $a['user_id'],
                    'user_name'   => $a['user_name'],
                    'team_id'     => $a['team_id'] ? (int) $a['team_id'] : null,
                    'ack_at'      => null,
                    'fulfill_at'  => null,
                ];
            }
            $byDispatchUser[$key]['fulfill_at'] = $a['created_at'];
            if (!$byDispatchUser[$key]['team_id'] && $a['team_id']) {
                $byDispatchUser[$key]['team_id'] = (int) $a['team_id'];
            }
        }

        foreach ($byDispatchUser as $entry) {
            $d = $dispatchById[$entry['dispatch_id']];
            $detail[] = [
                'type_label' => $typeMeta['dispatch'],
                'order_type' => 'dispatch',
                'team_id'    => $entry['team_id'],
                'team_label' => $entry['team_id'] ? ($teamLabels[$entry['team_id']] ?? '—') : t('history.no_team_capitalized', [], $lang),
                'user_id'    => $entry['user_id'],
                'user_name'  => $entry['user_name'],
                'label'      => $d['label'],
                'sent_at'    => $d['sent_at'],
                'ack_at'     => $entry['ack_at'],
                'fulfill_at' => $entry['fulfill_at'],
            ];
        }
    }

    // ── minute deltas + sort ─────────────────────────────────────────────────────
    foreach ($detail as &$row) {
        $row['ack_minutes'] = reportMinutesBetween($row['sent_at'], $row['ack_at']);
        $row['fulfill_minutes'] = reportMinutesBetween($row['sent_at'], $row['fulfill_at']);
    }
    unset($row);
    usort($detail, fn($a, $b) => strtotime($b['sent_at']) <=> strtotime($a['sent_at']));

    // ── per-team summary, computed from the same $detail rows ──────────────────
    $byTeam = [];
    foreach ($detail as $row) {
        $label = $row['team_label'];
        if (!isset($byTeam[$label])) {
            $byTeam[$label] = ['count' => 0, 'ack_count' => 0, 'fulfill_count' => 0, 'ack_sum' => 0.0, 'fulfill_sum' => 0.0];
        }
        $byTeam[$label]['count']++;
        if ($row['ack_minutes'] !== null) {
            $byTeam[$label]['ack_count']++;
            $byTeam[$label]['ack_sum'] += $row['ack_minutes'];
        }
        if ($row['fulfill_minutes'] !== null) {
            $byTeam[$label]['fulfill_count']++;
            $byTeam[$label]['fulfill_sum'] += $row['fulfill_minutes'];
        }
    }
    $summary = [];
    foreach ($byTeam as $label => $s) {
        $summary[] = [
            'team_label'          => $label,
            'order_count'         => $s['count'],
            'ack_rate'            => $s['count'] ? round($s['ack_count'] / $s['count'] * 100) : 0,
            'fulfill_rate'        => $s['count'] ? round($s['fulfill_count'] / $s['count'] * 100) : 0,
            'avg_ack_minutes'     => $s['ack_count'] ? round($s['ack_sum'] / $s['ack_count'], 1) : null,
            'avg_fulfill_minutes' => $s['fulfill_count'] ? round($s['fulfill_sum'] / $s['fulfill_count'], 1) : null,
        ];
    }
    usort($summary, fn($a, $b) => $b['order_count'] <=> $a['order_count']);

    // ── shortage reports (inverse direction: admin responding to a team's report) ──
    $shortageRows = dbFetchAll(
        "SELECT r.shortage_type, r.severity, r.title, r.created_at AS sent_at, r.team_id, r.reporter_id, u.name AS user_name,
                r.acknowledged_at, r.resolved_at
         FROM mission_shortage_reports r
         JOIN users u ON u.id = r.reporter_id
         WHERE r.mission_id = ?
         ORDER BY r.created_at DESC",
        [$missionId]
    );
    $shortageDetail = [];
    foreach ($shortageRows as $row) {
        $teamId = $row['team_id'] ? (int) $row['team_id'] : null;
        $shortageDetail[] = [
            'type_label'     => shortageTypeLabel($row['shortage_type'], $lang),
            'severity'       => $row['severity'],
            'severity_label' => shortageSeverityLabel($row['severity'], $lang),
            'team_id'        => $teamId,
            'team_label'     => $teamId ? ($teamLabels[$teamId] ?? '—') : t('history.no_team_capitalized', [], $lang),
            'reporter_id'    => (int) $row['reporter_id'],
            'reporter_name'  => $row['user_name'],
            'title'          => $row['title'],
            'sent_at'        => $row['sent_at'],
            'seen_at'        => $row['acknowledged_at'],
            'resolved_at'    => $row['resolved_at'],
        ];
    }
    foreach ($shortageDetail as &$row) {
        $row['seen_minutes'] = reportMinutesBetween($row['sent_at'], $row['seen_at']);
        $row['resolved_minutes'] = reportMinutesBetween($row['sent_at'], $row['resolved_at']);
    }
    unset($row);
    usort($shortageDetail, fn($a, $b) => strtotime($b['sent_at']) <=> strtotime($a['sent_at']));

    $bySeverity = [];
    foreach ($shortageDetail as $row) {
        $sev = $row['severity'];
        if (!isset($bySeverity[$sev])) {
            $bySeverity[$sev] = ['label' => $row['severity_label'], 'count' => 0, 'seen_count' => 0, 'resolved_count' => 0, 'seen_sum' => 0.0, 'resolved_sum' => 0.0];
        }
        $bySeverity[$sev]['count']++;
        if ($row['seen_minutes'] !== null) {
            $bySeverity[$sev]['seen_count']++;
            $bySeverity[$sev]['seen_sum'] += $row['seen_minutes'];
        }
        if ($row['resolved_minutes'] !== null) {
            $bySeverity[$sev]['resolved_count']++;
            $bySeverity[$sev]['resolved_sum'] += $row['resolved_minutes'];
        }
    }
    $severityRank = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
    $shortageSummary = [];
    foreach ($bySeverity as $sev => $s) {
        $shortageSummary[] = [
            'severity'             => $sev,
            'severity_label'       => $s['label'],
            'report_count'         => $s['count'],
            'seen_rate'            => $s['count'] ? round($s['seen_count'] / $s['count'] * 100) : 0,
            'resolved_rate'        => $s['count'] ? round($s['resolved_count'] / $s['count'] * 100) : 0,
            'avg_seen_minutes'     => $s['seen_count'] ? round($s['seen_sum'] / $s['seen_count'], 1) : null,
            'avg_resolved_minutes' => $s['resolved_count'] ? round($s['resolved_sum'] / $s['resolved_count'], 1) : null,
        ];
    }
    usort($shortageSummary, fn($a, $b) => ($severityRank[$a['severity']] ?? 9) <=> ($severityRank[$b['severity']] ?? 9));

    return [
        'summary' => $summary,
        'detail' => $detail,
        'shortageSummary' => $shortageSummary,
        'shortageDetail' => $shortageDetail,
    ];
}

/**
 * War Room: maps a 0-100 score to its display tier. Shared by both the
 * mission-wide score and every per-team leaderboard score computed in
 * computeMissionScore() below, and by both of that function's Greek-only
 * callers (mission-stats.php, mission-report-print.php) — kept here rather
 * than duplicated so the two pages can never drift on the band cutoffs.
 * Returns [tier, label]; tier is one of 'good'/'warning'/'critical', matching
 * mission-stats.php's pre-existing .mstats-chip.good/.warning/.critical CSS.
 */
function missionScoreTierMeta(float $score): array {
    if ($score >= 85) return ['good', 'Άριστη Επίδοση'];
    if ($score >= 65) return ['warning', 'Καλή Επίδοση'];
    return ['critical', 'Χρειάζεται Βελτίωση'];
}

/**
 * War Room: raw speed-bucket stats for a list of minute-deltas — splits into
 * "normal" (<= $thresholdMinutes) and "forgotten" (> $thresholdMinutes,
 * excluded from the average, kept only as a count), with NO score/decay
 * logic at all. Shared by missionScoreForgottenAwareSpeed() below (which
 * layers a decay curve + per-occurrence penalty on top, for the 0-100 score
 * pillars/leaderboard) and by every *display-only* forgotten-aware average
 * (order-type breakdown, per-team summary, fulfillment-time hero metric —
 * see computeMissionOrderTypeBreakdown()/computeMissionTeamSpeedBreakdown())
 * that needs avg_minutes + forgotten_count but must never see a meaningless
 * 0-100 "score" for what is just a raw minutes figure.
 */
function missionSpeedBucketStats(array $minutesList, int $thresholdMinutes): array {
    $present = array_values(array_filter($minutesList, fn($m) => $m !== null));
    $normal = array_values(array_filter($present, fn($m) => $m <= $thresholdMinutes));
    $forgottenCount = count($present) - count($normal);
    return [
        'avg_minutes'     => count($normal) ? round(array_sum($normal) / count($normal), 1) : null,
        'forgotten_count' => $forgottenCount,
        'normal_count'    => count($normal),
        'total_count'     => count($present),
    ];
}

/**
 * War Room: turns a raw list of minute-deltas (order-ack, shortage-seen,
 * shortage-resolved — anything "how long until X happened") into a 0-100
 * speed score that a single forgotten outlier can't destroy. Built on
 * missionSpeedBucketStats() above for the normal/forgotten split, then
 * applies a smooth exponential half-life decay: score = 100 * 0.5^(avg /
 * $halfLifeMinutes). This replaced an earlier linear "100 - avg*decayFactor"
 * formula that hit an exact 0 floor at a fixed number of minutes well before
 * $thresholdMinutes, collapsing a wide "kinda slow but not forgotten" band
 * to an identical 0 and losing all ordering within it — confirmed on a real
 * production PDF where a 1000+-minute outlier had already shown how badly a
 * plain mean distorts this same data; the decay curve had the same class of
 * problem one level down. The exponential is asymptotic — never hits
 * exactly 0, always monotonically decreasing — so relative ordering is
 * preserved across the whole 0-$thresholdMinutes range. On top of the decay,
 * anything past $thresholdMinutes still docks the score directly as a
 * per-occurrence penalty (the same shape already used for
 * unresolved-critical shortages elsewhere in computeMissionScore()) — so a
 * forgotten item still costs real points, it just can't single-handedly
 * zero out an otherwise-fast team.
 */
function missionScoreForgottenAwareSpeed(array $minutesList, float $halfLifeMinutes, int $thresholdMinutes, int $penaltyPerForgotten): array {
    $bucket = missionSpeedBucketStats($minutesList, $thresholdMinutes);
    if ($bucket['total_count'] === 0) {
        return ['available' => false, 'score' => null, 'avg_minutes' => null, 'forgotten_count' => 0];
    }
    $avgNormal = $bucket['avg_minutes'];
    // No normal-speed data at all (every single response was forgotten) —
    // start from a neutral 100 rather than assuming the worst on top of the
    // forgotten penalty below, which already does the real punishing.
    // min(100, ...) guards a theoretical negative $avgNormal (clock-skew/bad
    // data — reportMinutesBetween() doesn't clamp negative deltas) from
    // pushing 0.5^negative above 100; no lower clamp is needed since the
    // exponential is already bounded in (0,100] for any non-negative input.
    $base = $avgNormal !== null ? min(100, 100 * (0.5 ** ($avgNormal / $halfLifeMinutes))) : 100.0;
    $score = max(0, $base - $bucket['forgotten_count'] * $penaltyPerForgotten);
    return [
        'available'       => true,
        'score'           => $score,
        'avg_minutes'     => $avgNormal,
        'forgotten_count' => $bucket['forgotten_count'],
    ];
}

/**
 * War Room: post-mission performance score — an overall 0-100 grade plus a
 * per-team leaderboard, for mission-stats.php's score-validation section and
 * mission-report-print.php's read-only display. Reuses
 * computeMissionResponseReport()'s $detail/$shortageDetail rather than
 * re-querying orders/shortages a third time; pass an already-fetched $report
 * to avoid a duplicate call when the caller has one (both current callers do).
 *
 * Every pillar is independently gated on having real data — an unavailable
 * pillar drops out and the rest renormalize, the same "show — not a fake 0"
 * idea mission-stats.php already applies to its volunteer-hours tile via
 * $attendanceReady. $overall is null (not 0 or 100) when none of the
 * response/completion/staffing/debrief pillars have data — otherwise a
 * completely inactive mission would vacuously score 100 from the shortage
 * pillar's neutral "nothing went wrong" default alone. Callers must treat a
 * null overall as "insufficient data to score", not persist it.
 *
 * Two fairness passes happen before any pillar is computed, both prompted by
 * a real production PDF where a single forgotten order distorted everything
 * downstream of it:
 *  1. Rows whose actor is no longer an approved participant in this mission
 *     (their participation was later canceled/removed — "left by mistake")
 *     are dropped from every score computation entirely, via $approvedIds.
 *     This ONLY affects scoring, never the archival detail — $report itself
 *     is untouched (PHP arrays are copy-on-write), so mission-stats.php /
 *     mission-report-print.php's detail tables and activity feed keep
 *     showing literally everything that happened, unfiltered.
 *  2. Any surviving response/seen/resolved time past
 *     MISSION_SCORE_FORGOTTEN_MINUTES is excluded from the relevant speed
 *     *average* (so one 1000-minute gap can't flatten nine 2-minute ones)
 *     but still docks the score directly as a per-occurrence penalty, via
 *     missionScoreForgottenAwareSpeed() above — see that function's own
 *     comment for why a mean alone isn't enough here.
 */
function computeMissionScore(int $missionId, ?array $report = null): array {
    $report = $report ?? computeMissionResponseReport($missionId);
    $detail = $report['detail'];
    $shortageDetail = $report['shortageDetail'];

    $debrief = dbFetchOne("SELECT rating, objectives_met FROM mission_debriefs WHERE mission_id = ?", [$missionId]);
    $teams = dbFetchAll("SELECT id, codename, team_number, color FROM mission_teams WHERE mission_id = ? ORDER BY team_number", [$missionId]);
    $approvedIds = array_map('intval', array_column(dbFetchAll(
        "SELECT DISTINCT pr.volunteer_id FROM participation_requests pr
         JOIN shifts s ON s.id = pr.shift_id
         WHERE s.mission_id = ? AND pr.status = ?",
        [$missionId, PARTICIPATION_APPROVED]
    ), 'volunteer_id'));
    $approvedCount = count($approvedIds);
    $capacity = (int) dbFetchValue("SELECT COALESCE(SUM(max_volunteers), 0) FROM shifts WHERE mission_id = ?", [$missionId]);

    $forgottenThresholdMinutes = MISSION_SCORE_FORGOTTEN_MINUTES; // 4 hours — past this, treat as "forgotten"/off-shift, not merely slow
    $forgottenPenalty = 15; // same magnitude as the unresolved-critical-shortage penalty below, one consistent scale
    // Half-life (minutes) for the exponential speed-decay curve below — the
    // resolution half-life is deliberately larger (decays gentler) than the
    // response/seen one, matching this function's existing documented intent
    // that fixing something inherently takes longer than merely noticing it.
    $responseHalfLifeMinutes = 24;
    $resolutionHalfLifeMinutes = 66;

    $scoredDetail = array_values(array_filter($detail, fn($d) => in_array($d['user_id'], $approvedIds, true)));
    $scoredShortage = array_values(array_filter($shortageDetail, fn($d) => in_array($d['reporter_id'], $approvedIds, true)));

    // Named incidents for the narrative to cite directly ("η εντολή X προς Y
    // έμεινε αναπάντητη Z ώρες") rather than only ever speaking in aggregates.
    $forgottenOrders = [];
    foreach ($scoredDetail as $row) {
        if ($row['ack_minutes'] !== null && $row['ack_minutes'] > $forgottenThresholdMinutes) {
            $forgottenOrders[] = ['label' => $row['type_label'], 'user_name' => $row['user_name'], 'team_label' => $row['team_label'], 'minutes' => $row['ack_minutes']];
        }
    }
    usort($forgottenOrders, fn($a, $b) => $b['minutes'] <=> $a['minutes']);
    $forgottenShortages = [];
    foreach ($scoredShortage as $row) {
        if ($row['seen_minutes'] !== null && $row['seen_minutes'] > $forgottenThresholdMinutes) {
            $forgottenShortages[] = ['title' => $row['title'], 'reporter_name' => $row['reporter_name'], 'team_label' => $row['team_label'], 'minutes' => $row['seen_minutes']];
        }
    }
    usort($forgottenShortages, fn($a, $b) => $b['minutes'] <=> $a['minutes']);

    // ── mission-wide pillars ─────────────────────────────────────────────────
    $totalOrders = count($scoredDetail);
    $pillars = [];

    $ackMinutesForScoring = array_column($scoredDetail, 'ack_minutes');
    $responseCalc = missionScoreForgottenAwareSpeed($ackMinutesForScoring, $responseHalfLifeMinutes, $forgottenThresholdMinutes, $forgottenPenalty);
    if ($responseCalc['available']) {
        $pillars['response'] = ['label' => 'Ταχύτητα Απόκρισης', 'weight' => 25, 'available' => true, 'score' => $responseCalc['score'], 'raw' => ['avg_minutes' => $responseCalc['avg_minutes'], 'forgotten_count' => $responseCalc['forgotten_count']]];
    } else {
        $pillars['response'] = ['label' => 'Ταχύτητα Απόκρισης', 'weight' => 25, 'available' => false, 'score' => null, 'raw' => []];
    }

    // ── response-time distribution (mission-report-print.php) — same
    //    $scoredDetail population the response pillar itself scores, so the
    //    histogram can never visually disagree with that pillar/hero-tile
    //    number. Bucket boundaries match the existing normal/forgotten
    //    cutoff exactly: 60-240 is inclusive of 240 ("normal"), forgotten is
    //    strictly >240. Never-acknowledged orders (null) are excluded
    //    entirely — "no response at all" is a different story than
    //    "responded slowly", and missionSpeedBucketStats() excludes them too.
    $histogramBuckets = [
        ['key' => 'lt5',       'label' => '<5',               'count' => 0],
        ['key' => '5to15',     'label' => '5-15',              'count' => 0],
        ['key' => '15to60',    'label' => '15-60',             'count' => 0],
        ['key' => '60to240',   'label' => '60-240',            'count' => 0],
        ['key' => 'forgotten', 'label' => 'Ξεχασμένες (>240)', 'count' => 0],
    ];
    $totalAcknowledged = 0;
    foreach ($ackMinutesForScoring as $m) {
        if ($m === null) continue;
        $totalAcknowledged++;
        if ($m < 5) $histogramBuckets[0]['count']++;
        elseif ($m < 15) $histogramBuckets[1]['count']++;
        elseif ($m < 60) $histogramBuckets[2]['count']++;
        elseif ($m <= $forgottenThresholdMinutes) $histogramBuckets[3]['count']++;
        else $histogramBuckets[4]['count']++;
    }
    $responseHistogram = [
        'available'          => $totalAcknowledged > 0,
        'buckets'            => $histogramBuckets,
        'total_acknowledged' => $totalAcknowledged,
    ];

    $fulfilledRows = array_filter($scoredDetail, fn($d) => $d['fulfill_minutes'] !== null);
    $fulfilled = count($fulfilledRows);
    if ($totalOrders > 0) {
        $pillars['completion'] = ['label' => 'Ολοκλήρωση Εντολών', 'weight' => 20, 'available' => true, 'score' => $fulfilled / $totalOrders * 100, 'raw' => ['fulfilled' => $fulfilled, 'total' => $totalOrders]];
    } else {
        $pillars['completion'] = ['label' => 'Ολοκλήρωση Εντολών', 'weight' => 20, 'available' => false, 'score' => null, 'raw' => []];
    }
    // Forgotten-aware, matching the ack-minutes hero tile right next to this
    // one — previously a plain mean with no outlier protection or footnote.
    $fulfillSpeedStats = missionSpeedBucketStats(array_column($scoredDetail, 'fulfill_minutes'), $forgottenThresholdMinutes);
    $avgFulfillMinutes = $fulfillSpeedStats['avg_minutes'];

    $totalShortage = count($scoredShortage);
    $resolved = count(array_filter($scoredShortage, fn($d) => $d['resolved_at'] !== null));
    $unresolvedCritical = count(array_filter($scoredShortage, fn($d) => $d['resolved_at'] === null && $d['severity'] === 'critical'));
    if ($totalShortage > 0) {
        $shortageScore = max(0, min(100, ($resolved / $totalShortage * 100) - $unresolvedCritical * 15));
    } else {
        $shortageScore = 100.0; // nothing reported broken — neutral, not penalized
    }
    $pillars['shortage'] = ['label' => 'Διαχείριση Ελλείψεων', 'weight' => 20, 'available' => true, 'score' => $shortageScore, 'raw' => ['resolved' => $resolved, 'total' => $totalShortage, 'unresolved_critical' => $unresolvedCritical]];

    if ($capacity > 0) {
        $pillars['staffing'] = ['label' => 'Στελέχωση / Κάλυψη', 'weight' => 15, 'available' => true, 'score' => max(0, min(100, $approvedCount / $capacity * 100)), 'raw' => ['approved' => $approvedCount, 'capacity' => $capacity]];
    } else {
        $pillars['staffing'] = ['label' => 'Στελέχωση / Κάλυψη', 'weight' => 15, 'available' => false, 'score' => null, 'raw' => []];
    }

    if ($debrief) {
        $ratingScore = ((int) $debrief['rating']) / 5 * 100;
        $objectivesScore = ['YES' => 100, 'PARTIAL' => 55, 'NO' => 15][$debrief['objectives_met']] ?? 55;
        $pillars['debrief'] = ['label' => 'Απολογισμός Debrief', 'weight' => 20, 'available' => true, 'score' => $ratingScore * 0.6 + $objectivesScore * 0.4, 'raw' => ['rating' => (int) $debrief['rating'], 'objectives' => $debrief['objectives_met']]];
    } else {
        $pillars['debrief'] = ['label' => 'Απολογισμός Debrief', 'weight' => 20, 'available' => false, 'score' => null, 'raw' => []];
    }

    // ── shared headline timing metrics (hero tiles on both pages) + command/
    //    admin responsiveness — how fast the command staff SAW (acknowledged)
    //    and RESOLVED teams' shortage reports. Deliberately a separate
    //    evaluation from the 'shortage' pillar above (which grades the
    //    *outcome* — resolved or not, penalized for unresolved criticals) —
    //    this one grades *speed*, and is null (not defaulted to neutral) when
    //    there are zero shortage reports, since there's nothing to judge the
    //    command staff's reaction time on. Same forgotten-aware treatment as
    //    the response pillar — a shortage seen 16 hours late shouldn't erase
    //    every other same-day acknowledgment from the average. ────────────
    $seenCalc = missionScoreForgottenAwareSpeed(array_column($scoredShortage, 'seen_minutes'), $responseHalfLifeMinutes, $forgottenThresholdMinutes, $forgottenPenalty);
    $resolvedCalc = missionScoreForgottenAwareSpeed(array_column($scoredShortage, 'resolved_minutes'), $resolutionHalfLifeMinutes, $forgottenThresholdMinutes, $forgottenPenalty);
    $seenCount = count(array_filter($scoredShortage, fn($d) => $d['seen_minutes'] !== null));

    $commandParts = [];
    if ($seenCalc['available']) $commandParts[] = ['score' => $seenCalc['score'], 'weight' => 50];
    if ($resolvedCalc['available']) $commandParts[] = ['score' => $resolvedCalc['score'], 'weight' => 50];
    if ($totalShortage > 0 && !empty($commandParts)) {
        $cWeightSum = array_sum(array_column($commandParts, 'weight'));
        $cWeightedScore = array_sum(array_map(fn($p) => $p['score'] * $p['weight'], $commandParts));
        $commandScore = round($cWeightedScore / $cWeightSum, 2);
        $commandTier = missionScoreTierMeta($commandScore);
        $commandAvailable = true;
    } else {
        $commandScore = null;
        $commandTier = null;
        $commandAvailable = false;
    }
    $command = [
        'available'          => $commandAvailable,
        'score'              => $commandScore,
        'tier'               => $commandTier,
        'avg_seen'           => $seenCalc['avg_minutes'],
        'avg_resolved'       => $resolvedCalc['avg_minutes'],
        'seen_rate'          => $totalShortage ? round($seenCount / $totalShortage * 100) : null,
        'resolved_rate'      => $totalShortage ? round($resolved / $totalShortage * 100) : null,
        'total_reports'      => $totalShortage,
        'forgotten_incidents' => $forgottenShortages,
    ];
    // Captured now, before the per-team loop below reuses $avgAck as its own
    // local (per-team) variable name — these are the mission-wide values.
    $metrics = [
        'avg_ack'           => $responseCalc['avg_minutes'],
        'avg_fulfill'       => $avgFulfillMinutes,
        'avg_seen'          => $seenCalc['avg_minutes'],
        'avg_resolved'      => $resolvedCalc['avg_minutes'],
        'forgotten_orders'  => $responseCalc['forgotten_count'],
        'forgotten_fulfill' => $fulfillSpeedStats['forgotten_count'],
        'forgotten_seen'    => $seenCalc['forgotten_count'],
        'forgotten_resolved' => $resolvedCalc['forgotten_count'],
    ];

    // ── historical baseline — same mission_type_id, everything except this
    //    mission, same forgotten-minutes cutoff applied to every side of the
    //    comparison so a past fluke can't distort the baseline either.
    //    Cheap set-based aggregation (not a per-mission computeMissionScore()
    //    call in a loop) since only a handful of numbers are needed. Gated on
    //    a small minimum sample so 1-2 historical data points don't produce a
    //    noisy "50% faster than history" claim. avg_fulfill/avg_seen/
    //    avg_resolved back the hero-tile trend arrows (mission-report-print.php)
    //    only — no narrative sentence reads them, unlike avg_ack/completion_rate
    //    which generateMissionObserverNarrative() already cites. ─────────────
    $missionTypeId = (int) dbFetchValue("SELECT mission_type_id FROM missions WHERE id = ?", [$missionId]);
    $historical = [
        'avg_ack' => null, 'avg_ack_sample' => 0,
        'completion_rate' => null, 'completion_sample' => 0,
        'avg_fulfill' => null, 'avg_fulfill_sample' => 0,
        'avg_seen' => null, 'avg_seen_sample' => 0,
        'avg_resolved' => null, 'avg_resolved_sample' => 0,
    ];
    if ($missionTypeId) {
        $histAck = dbFetchOne(
            "SELECT AVG(TIMESTAMPDIFF(MINUTE, o.created_at, r.acknowledged_at)) AS avg_ack, COUNT(*) AS n
             FROM mission_order_recipients r
             JOIN mission_orders o ON o.id = r.order_id
             JOIN missions m ON m.id = o.mission_id
             WHERE m.mission_type_id = ? AND m.id != ? AND m.deleted_at IS NULL
               AND r.acknowledged_at IS NOT NULL
               AND TIMESTAMPDIFF(MINUTE, o.created_at, r.acknowledged_at) <= ?",
            [$missionTypeId, $missionId, $forgottenThresholdMinutes]
        );
        $histCompletion = dbFetchOne(
            "SELECT COUNT(*) AS total, SUM(CASE WHEN r.fulfilled_at IS NOT NULL THEN 1 ELSE 0 END) AS fulfilled
             FROM mission_order_recipients r
             JOIN mission_orders o ON o.id = r.order_id
             JOIN missions m ON m.id = o.mission_id
             WHERE m.mission_type_id = ? AND m.id != ? AND m.deleted_at IS NULL",
            [$missionTypeId, $missionId]
        );
        $histFulfill = dbFetchOne(
            "SELECT AVG(TIMESTAMPDIFF(MINUTE, o.created_at, r.fulfilled_at)) AS avg_fulfill, COUNT(*) AS n
             FROM mission_order_recipients r
             JOIN mission_orders o ON o.id = r.order_id
             JOIN missions m ON m.id = o.mission_id
             WHERE m.mission_type_id = ? AND m.id != ? AND m.deleted_at IS NULL
               AND r.fulfilled_at IS NOT NULL
               AND TIMESTAMPDIFF(MINUTE, o.created_at, r.fulfilled_at) <= ?",
            [$missionTypeId, $missionId, $forgottenThresholdMinutes]
        );
        $histSeen = dbFetchOne(
            "SELECT AVG(TIMESTAMPDIFF(MINUTE, r.created_at, r.acknowledged_at)) AS avg_seen, COUNT(*) AS n
             FROM mission_shortage_reports r
             JOIN missions m ON m.id = r.mission_id
             WHERE m.mission_type_id = ? AND m.id != ? AND m.deleted_at IS NULL
               AND r.acknowledged_at IS NOT NULL
               AND TIMESTAMPDIFF(MINUTE, r.created_at, r.acknowledged_at) <= ?",
            [$missionTypeId, $missionId, $forgottenThresholdMinutes]
        );
        $histResolved = dbFetchOne(
            "SELECT AVG(TIMESTAMPDIFF(MINUTE, r.created_at, r.resolved_at)) AS avg_resolved, COUNT(*) AS n
             FROM mission_shortage_reports r
             JOIN missions m ON m.id = r.mission_id
             WHERE m.mission_type_id = ? AND m.id != ? AND m.deleted_at IS NULL
               AND r.resolved_at IS NOT NULL
               AND TIMESTAMPDIFF(MINUTE, r.created_at, r.resolved_at) <= ?",
            [$missionTypeId, $missionId, $forgottenThresholdMinutes]
        );

        $histAckN = $histAck ? (int) $histAck['n'] : 0;
        $histTotal = $histCompletion ? (int) $histCompletion['total'] : 0;
        $histFulfillN = $histFulfill ? (int) $histFulfill['n'] : 0;
        $histSeenN = $histSeen ? (int) $histSeen['n'] : 0;
        $histResolvedN = $histResolved ? (int) $histResolved['n'] : 0;

        $historical = [
            'avg_ack'            => ($histAckN >= 3 && $histAck['avg_ack'] !== null) ? round((float) $histAck['avg_ack'], 1) : null,
            'avg_ack_sample'     => $histAckN,
            'completion_rate'    => ($histTotal >= 3) ? round(((int) $histCompletion['fulfilled']) / $histTotal * 100) : null,
            'completion_sample'  => $histTotal,
            'avg_fulfill'        => ($histFulfillN >= 3 && $histFulfill['avg_fulfill'] !== null) ? round((float) $histFulfill['avg_fulfill'], 1) : null,
            'avg_fulfill_sample' => $histFulfillN,
            'avg_seen'           => ($histSeenN >= 3 && $histSeen['avg_seen'] !== null) ? round((float) $histSeen['avg_seen'], 1) : null,
            'avg_seen_sample'    => $histSeenN,
            'avg_resolved'       => ($histResolvedN >= 3 && $histResolved['avg_resolved'] !== null) ? round((float) $histResolved['avg_resolved'], 1) : null,
            'avg_resolved_sample' => $histResolvedN,
        ];
    }

    $hasSubstantiveData = $pillars['response']['available'] || $pillars['completion']['available']
        || $pillars['staffing']['available'] || $pillars['debrief']['available'];
    $weightSum = 0;
    $weightedScore = 0.0;
    foreach ($pillars as $p) {
        if ($p['available']) {
            $weightSum += $p['weight'];
            $weightedScore += $p['weight'] * $p['score'];
        }
    }
    $overall = ($hasSubstantiveData && $weightSum > 0) ? round($weightedScore / $weightSum, 2) : null;
    $overallTier = $overall !== null ? missionScoreTierMeta($overall) : null;

    // ── per-team leaderboard, grouped by team_id (not the display-string
    //    team_label) so it can't be confused by two teams that happen to
    //    render identically — see computeMissionResponseReport()'s $detail
    //    (already carries team_id) and $shortageDetail (carries it as of the
    //    field added just above this function). Built from the same
    //    approved-only $scoredDetail/$scoredShortage as the mission-wide
    //    pillars above, not the raw $detail/$shortageDetail. ──────────────
    $byTeamOrders = [];
    foreach ($scoredDetail as $row) {
        $tid = $row['team_id'];
        if ($tid === null) continue;
        if (!isset($byTeamOrders[$tid])) {
            $byTeamOrders[$tid] = ['count' => 0, 'ack_minutes' => [], 'fulfill_count' => 0];
        }
        $byTeamOrders[$tid]['count']++;
        if ($row['ack_minutes'] !== null) {
            $byTeamOrders[$tid]['ack_minutes'][] = $row['ack_minutes'];
        }
        if ($row['fulfill_minutes'] !== null) {
            $byTeamOrders[$tid]['fulfill_count']++;
        }
    }
    $byTeamShortage = [];
    foreach ($scoredShortage as $row) {
        $tid = $row['team_id'];
        if ($tid === null) continue;
        if (!isset($byTeamShortage[$tid])) {
            $byTeamShortage[$tid] = ['count' => 0, 'resolved' => 0, 'unresolvedCritical' => 0];
        }
        $byTeamShortage[$tid]['count']++;
        if ($row['resolved_at'] !== null) {
            $byTeamShortage[$tid]['resolved']++;
        } elseif ($row['severity'] === 'critical') {
            $byTeamShortage[$tid]['unresolvedCritical']++;
        }
    }

    $teamScores = [];
    foreach ($teams as $team) {
        $tid = (int) $team['id'];
        $orders = $byTeamOrders[$tid] ?? null;
        $shortages = $byTeamShortage[$tid] ?? null;
        if ($orders === null && $shortages === null) {
            continue; // nothing to score this team on — excluded from the leaderboard entirely
        }

        // raw fields deliberately mirror only the distance-independent
        // dimensions (order-ACKNOWLEDGMENT speed — a device/UI action, not
        // travel — completion rate, and shortage handling) so anything built
        // from them (the team-comparison narrative below) can never compare
        // teams on dispatch-arrival time, which depends on how far each
        // team's point was and isn't a fair performance signal.
        $teamPillars = [];
        $teamResponseCalc = $orders ? missionScoreForgottenAwareSpeed($orders['ack_minutes'], $responseHalfLifeMinutes, $forgottenThresholdMinutes, $forgottenPenalty) : ['available' => false, 'score' => null, 'avg_minutes' => null, 'forgotten_count' => 0];
        if ($teamResponseCalc['available']) {
            $teamPillars['response'] = ['weight' => 45, 'available' => true, 'score' => $teamResponseCalc['score'], 'raw' => ['avg_minutes' => $teamResponseCalc['avg_minutes'], 'forgotten_count' => $teamResponseCalc['forgotten_count']]];
        } else {
            $teamPillars['response'] = ['weight' => 45, 'available' => false, 'score' => null, 'raw' => []];
        }
        if ($orders && $orders['count'] > 0) {
            $teamPillars['completion'] = ['weight' => 35, 'available' => true, 'score' => $orders['fulfill_count'] / $orders['count'] * 100, 'raw' => ['fulfilled' => $orders['fulfill_count'], 'total' => $orders['count']]];
        } else {
            $teamPillars['completion'] = ['weight' => 35, 'available' => false, 'score' => null, 'raw' => []];
        }
        if ($shortages && $shortages['count'] > 0) {
            $rate = $shortages['resolved'] / $shortages['count'] * 100;
            $teamPillars['shortage'] = ['weight' => 20, 'available' => true, 'score' => max(0, min(100, $rate - $shortages['unresolvedCritical'] * 15)), 'raw' => ['resolved' => $shortages['resolved'], 'total' => $shortages['count']]];
        } else {
            $teamPillars['shortage'] = ['weight' => 20, 'available' => true, 'score' => 100.0, 'raw' => ['resolved' => 0, 'total' => 0]];
        }

        $tWeightSum = 0;
        $tWeightedScore = 0.0;
        foreach ($teamPillars as $p) {
            if ($p['available']) {
                $tWeightSum += $p['weight'];
                $tWeightedScore += $p['weight'] * $p['score'];
            }
        }
        if ($tWeightSum === 0) continue;
        $tScore = round($tWeightedScore / $tWeightSum, 2);

        $teamScores[] = [
            'team_id'     => $tid,
            'codename'    => $team['codename'],
            'team_number' => $team['team_number'],
            'color'       => $team['color'] ?: '#898781',
            'score'       => $tScore,
            'tier'        => missionScoreTierMeta($tScore),
            'order_count' => $orders['count'] ?? 0,
            'pillars'     => $teamPillars,
        ];
    }
    usort($teamScores, fn($a, $b) => ($b['score'] <=> $a['score']) ?: ($b['order_count'] <=> $a['order_count']));
    foreach ($teamScores as $i => &$t) {
        $t['rank'] = $i + 1;
    }
    unset($t);

    return [
        'overall'            => $overall,
        'tier'               => $overallTier,
        'pillars'            => $pillars,
        'teams'              => $teamScores,
        'metrics'            => $metrics,
        'command'            => $command,
        'forgotten_orders'   => $forgottenOrders,
        'historical'         => $historical,
        'response_histogram' => $responseHistogram,
    ];
}

/**
 * War Room: per-order-type breakdown (pie + bar chart + table) shared by
 * mission-stats.php and mission-report-print.php — previously two
 * byte-identical inline loops over $report['detail'], now one function so
 * the two pages can't drift. Forgotten-aware (missionSpeedBucketStats()) on
 * both the ack and fulfill side, unlike the plain mean it replaces. Operates
 * on the raw, unfiltered $detail rows computeMissionResponseReport() already
 * returns (canceled-participant rows included, same as the rest of that
 * archival dataset) — this changes only the averaging formula, never which
 * rows are counted.
 */
function computeMissionOrderTypeBreakdown(array $detail, int $thresholdMinutes): array {
    $byType = [];
    foreach ($detail as $row) {
        $t = $row['order_type'];
        if (!isset($byType[$t])) {
            $byType[$t] = ['label' => $row['type_label'], 'count' => 0, 'ack_minutes' => [], 'fulfill_minutes' => []];
        }
        $byType[$t]['count']++;
        if ($row['ack_minutes'] !== null) $byType[$t]['ack_minutes'][] = $row['ack_minutes'];
        if ($row['fulfill_minutes'] !== null) $byType[$t]['fulfill_minutes'][] = $row['fulfill_minutes'];
    }
    $result = [];
    foreach ($byType as $type => $s) {
        $ackStats = missionSpeedBucketStats($s['ack_minutes'], $thresholdMinutes);
        $fulfillStats = missionSpeedBucketStats($s['fulfill_minutes'], $thresholdMinutes);
        $result[] = [
            'order_type'              => $type,
            'label'                   => $s['label'],
            'count'                   => $s['count'],
            'avg_ack_minutes'         => $ackStats['avg_minutes'],
            'forgotten_ack_count'     => $ackStats['forgotten_count'],
            'avg_fulfill_minutes'     => $fulfillStats['avg_minutes'],
            'forgotten_fulfill_count' => $fulfillStats['forgotten_count'],
        ];
    }
    return $result;
}

/**
 * War Room: per-team order-response rollup (bar charts + print table) for
 * mission-stats.php/mission-report-print.php — same shape as
 * computeMissionResponseReport()'s own $summary, but with forgotten-aware
 * avg_ack_minutes/avg_fulfill_minutes instead of a plain mean. Deliberately
 * NOT folded into computeMissionResponseReport() itself: that function's
 * $summary is also read verbatim by mission-response-report.php's live
 * (STATUS_OPEN-gated) JSON endpoint, rendered directly inside war-room.php's
 * own "Αναφορά Χρόνων Απόκρισης" modal — mutating $summary's averaging
 * formula in place would silently change that live in-mission surface's
 * numbers too, with no forgotten-count context ever shown there, which is
 * out of scope here. Operates on the same raw, unfiltered $detail rows
 * $summary already uses (canceled-participant rows included) — only the
 * averaging formula differs, never which rows are counted.
 */
function computeMissionTeamSpeedBreakdown(array $detail, int $thresholdMinutes): array {
    $byTeam = [];
    foreach ($detail as $row) {
        $label = $row['team_label'];
        if (!isset($byTeam[$label])) {
            $byTeam[$label] = ['count' => 0, 'ack_count' => 0, 'fulfill_count' => 0, 'ack_minutes' => [], 'fulfill_minutes' => []];
        }
        $byTeam[$label]['count']++;
        if ($row['ack_minutes'] !== null) { $byTeam[$label]['ack_count']++; $byTeam[$label]['ack_minutes'][] = $row['ack_minutes']; }
        if ($row['fulfill_minutes'] !== null) { $byTeam[$label]['fulfill_count']++; $byTeam[$label]['fulfill_minutes'][] = $row['fulfill_minutes']; }
    }
    $result = [];
    foreach ($byTeam as $label => $s) {
        $ackStats = missionSpeedBucketStats($s['ack_minutes'], $thresholdMinutes);
        $fulfillStats = missionSpeedBucketStats($s['fulfill_minutes'], $thresholdMinutes);
        $result[] = [
            'team_label'              => $label,
            'order_count'             => $s['count'],
            'ack_rate'                => $s['count'] ? round($s['ack_count'] / $s['count'] * 100) : 0,
            'fulfill_rate'            => $s['count'] ? round($s['fulfill_count'] / $s['count'] * 100) : 0,
            'avg_ack_minutes'         => $ackStats['avg_minutes'],
            'forgotten_ack_count'     => $ackStats['forgotten_count'],
            'avg_fulfill_minutes'     => $fulfillStats['avg_minutes'],
            'forgotten_fulfill_count' => $fulfillStats['forgotten_count'],
        ];
    }
    usort($result, fn($a, $b) => $b['order_count'] <=> $a['order_count']);
    return $result;
}

/**
 * War Room: classifies a current-vs-historical minutes comparison into
 * better/worse/neutral at the same ±10% dead zone
 * generateMissionObserverNarrative()'s avg_ack-vs-historical sentence uses
 * (see its own dead-zone block below), so the hero-tile trend arrows
 * (mission-report-print.php) and that narrative sentence can never disagree
 * on the cutoff. Lower minutes is always "better" here (every caller is a
 * response/resolution-speed metric). Returns null when either side of the
 * comparison is missing.
 */
function missionMinutesTrend(?float $current, ?float $historical): ?array {
    if ($current === null || $historical === null || $historical <= 0) {
        return null;
    }
    $diffPct = round((($current - $historical) / $historical) * 100);
    if ($diffPct <= -10) return ['direction' => 'better', 'pct' => (int) abs($diffPct)];
    if ($diffPct >= 10) return ['direction' => 'worse', 'pct' => (int) $diffPct];
    return ['direction' => 'neutral', 'pct' => (int) $diffPct];
}

/**
 * War Room: composes the "expert observer" paragraph for the score section —
 * one paragraph of Greek prose grounded in the actual pillar numbers (not
 * generic boilerplate), naming the strongest/weakest measured area and, for
 * a low score, explicitly recommending what to improve. Pure function of
 * computeMissionScore()'s own output — no new queries, always regenerated
 * live rather than stored, same "computed, not cached" philosophy as every
 * other number on this report.
 */
function missionScorePillarPhrase(string $key, array $pillar, bool $positive): string {
    $raw = $pillar['raw'] ?? [];
    switch ($key) {
        case 'response':
            $m = $raw['avg_minutes'] ?? null;
            if ($m === null) return '';
            return $positive
                ? "Ο μέσος χρόνος απόκρισης των ομάδων στις εντολές ήταν {$m} λεπτά, χρόνος που υποδηλώνει υψηλή ετοιμότητα και καλή ροή επικοινωνίας."
                : "Ο μέσος χρόνος απόκρισης των ομάδων στις εντολές έφτασε τα {$m} λεπτά, χρόνος αυξημένος για επιχειρησιακό περιβάλλον που απαιτεί άμεση αντίδραση.";
        case 'completion':
            $f = $raw['fulfilled'] ?? 0;
            $t = $raw['total'] ?? 0;
            if ($t === 0) return '';
            $rate = round($f / $t * 100);
            return $positive
                ? "Ολοκληρώθηκαν {$f} από τις {$t} εντολές ({$rate}%), δείχνοντας συνέπεια στην εκτέλεση του έργου που ανατέθηκε."
                : "Ολοκληρώθηκαν μόλις {$f} από τις {$t} εντολές ({$rate}%), ποσοστό που αφήνει σημαντικό αριθμό εντολών ημιτελείς ή αναπάντητες.";
        case 'shortage':
            $r = $raw['resolved'] ?? 0;
            $t = $raw['total'] ?? 0;
            if ($t === 0) return 'Δεν αναφέρθηκαν ελλείψεις κατά τη διάρκεια της αποστολής.';
            $rate = round($r / $t * 100);
            $uc = $raw['unresolved_critical'] ?? 0;
            $extra = '';
            if ($uc > 0) {
                $extra = ' Ιδιαίτερη ανησυχία προκαλεί το γεγονός ότι ' . ($uc === 1 ? 'μία κρίσιμη αναφορά παρέμεινε ανεπίλυτη' : "{$uc} κρίσιμες αναφορές παρέμειναν ανεπίλυτες") . '.';
            }
            return $positive
                ? "Λύθηκαν {$r} από τις {$t} αναφορές έλλειψης ({$rate}%), απόδειξη αποτελεσματικής διαχείρισης προβλημάτων στο πεδίο."
                : "Λύθηκαν μόνο {$r} από τις {$t} αναφορές έλλειψης ({$rate}%).{$extra}";
        case 'staffing':
            $a = $raw['approved'] ?? 0;
            $c = $raw['capacity'] ?? 0;
            if ($c === 0) return '';
            $rate = round($a / $c * 100);
            return $positive
                ? "Η κάλυψη βαρδιών ήταν επαρκής, με {$a} από {$c} διαθέσιμες θέσεις εθελοντών καλυμμένες ({$rate}%)."
                : "Η κάλυψη βαρδιών ήταν ανεπαρκής, με μόλις {$a} από {$c} διαθέσιμες θέσεις εθελοντών καλυμμένες ({$rate}%), γεγονός που περιόρισε τους διαθέσιμους πόρους στο πεδίο.";
        case 'debrief':
            $rt = $raw['rating'] ?? null;
            if ($rt === null) return '';
            $objText = ['YES' => 'πλήρως επιτεύχθηκαν', 'PARTIAL' => 'επιτεύχθηκαν εν μέρει', 'NO' => 'δεν επιτεύχθηκαν'][$raw['objectives'] ?? ''] ?? 'επιτεύχθηκαν εν μέρει';
            return $positive
                ? "Ο υπεύθυνος αποστολής βαθμολόγησε την άσκηση με {$rt}/5 στο debrief, αναφέροντας ότι οι στόχοι {$objText}."
                : "Ο υπεύθυνος αποστολής βαθμολόγησε την άσκηση με {$rt}/5 στο debrief, σημειώνοντας ότι οι στόχοι {$objText} — αυτοαξιολόγηση που επιβεβαιώνει τα περιθώρια βελτίωσης.";
    }
    return '';
}

function generateMissionObserverNarrative(array $score, string $missionTitle): string {
    if ($score['overall'] === null) {
        return 'Δεν υπάρχουν επαρκή δεδομένα (εντολές, βάρδιες ή αναφορά debrief) ώστε ο παρατηρητής να διατυπώσει τεκμηριωμένη αξιολόγηση για αυτή την αποστολή.';
    }
    $tier = $score['tier'][0];
    $overallFmt = number_format($score['overall'], 1);
    $title = $missionTitle !== '' ? " «{$missionTitle}»" : '';

    $openers = [
        'good'     => "Η άσκηση{$title} ολοκληρώθηκε με άριστη συνολική επίδοση ({$overallFmt}/100), αντανακλώντας αποτελεσματικό συντονισμό μεταξύ ομάδων και διοίκησης.",
        'warning'  => "Η άσκηση{$title} ολοκληρώθηκε με ικανοποιητική συνολική επίδοση ({$overallFmt}/100), με σαφή όμως περιθώρια βελτίωσης σε επιμέρους τομείς.",
        'critical' => "Η συνολική επίδοση της άσκησης{$title} ({$overallFmt}/100) υστερεί αισθητά από τον επιθυμητό στόχο και καταδεικνύει σοβαρά περιθώρια βελτίωσης.",
    ];
    $sentences = [$openers[$tier]];

    // Positive/negative phrasing is picked at a stricter 75-point bar than
    // the 65/85 tier-color bands — a "warning"-tier pillar (65-84) still
    // reads as "needs improvement" in prose, since a narrative benefits from
    // being a bit more discerning than a 3-color badge.
    $available = array_filter($score['pillars'], fn($p) => $p['available']);
    $weakest = null;
    if (count($available) >= 2) {
        uasort($available, fn($a, $b) => $a['score'] <=> $b['score']);
        $weakestKey = array_key_first($available);
        $strongestKey = array_key_last($available);
        $weakest = $available[$weakestKey];

        $strongPhrase = missionScorePillarPhrase($strongestKey, $available[$strongestKey], $available[$strongestKey]['score'] >= 75);
        if ($strongPhrase !== '') $sentences[] = $strongPhrase;
        if ($weakestKey !== $strongestKey) {
            $weakPhrase = missionScorePillarPhrase($weakestKey, $weakest, $weakest['score'] >= 75);
            if ($weakPhrase !== '') $sentences[] = $weakPhrase;
        }
    } elseif (count($available) === 1) {
        $onlyKey = array_key_first($available);
        $weakest = $available[$onlyKey];
        $onlyPhrase = missionScorePillarPhrase($onlyKey, $weakest, $weakest['score'] >= 75);
        if ($onlyPhrase !== '') $sentences[] = $onlyPhrase;
    }

    if ($score['historical']['avg_ack'] !== null && $score['metrics']['avg_ack'] !== null) {
        $hist = $score['historical']['avg_ack'];
        $diffPct = $hist > 0 ? round((($score['metrics']['avg_ack'] - $hist) / $hist) * 100) : 0;
        if ($diffPct <= -10) {
            $sentences[] = 'Ο μέσος χρόνος απόκρισης ήταν ' . abs($diffPct) . "% ταχύτερος από τον ιστορικό μέσο όρο ({$hist} λεπ.) για αποστολές ίδιου τύπου.";
        } elseif ($diffPct >= 10) {
            $sentences[] = 'Ο μέσος χρόνος απόκρισης ήταν ' . $diffPct . "% πιο αργός από τον ιστορικό μέσο όρο ({$hist} λεπ.) για αποστολές ίδιου τύπου.";
        } else {
            $sentences[] = "Ο μέσος χρόνος απόκρισης ήταν σε γενικές γραμμές στα ίδια επίπεδα με τον ιστορικό μέσο όρο ({$hist} λεπ.) για αποστολές ίδιου τύπου.";
        }
    }

    if (!empty($score['forgotten_orders'])) {
        $worst = $score['forgotten_orders'][0];
        $hours = round($worst['minutes'] / 60, 1);
        $extra = count($score['forgotten_orders']) > 1 ? ', εκ των οποίων δεν ήταν η μοναδική τέτοια περίπτωση.' : '.';
        $sentences[] = "Ιδιαίτερη προσοχή χρειάζεται η εντολή «{$worst['label']}» προς {$worst['user_name']} ({$worst['team_label']}), η οποία παρέμεινε αναπάντητη για περίπου {$hours} ώρες" . $extra;
    }

    $recommendations = [
        'response'   => 'Εξετάστε συντομότερες υπενθυμίσεις (push notification) προς τις ομάδες όταν μια εντολή μένει αναπάντητη για μεγάλο διάστημα.',
        'completion' => 'Εξετάστε αν οι εντολές ήταν σαφείς και εφικτές εντός του διαθέσιμου χρόνου κάθε ομάδας.',
        'shortage'   => 'Εξετάστε ταχύτερη πρώτη ανταπόκριση στις αναφορές έλλειψης, ιδίως τις κρίσιμες.',
        'staffing'   => 'Εξετάστε αύξηση του αριθμού διαθέσιμων εθελοντών ή καλύτερη προ-δρομολόγηση βαρδιών στην επόμενη αποστολή.',
        'debrief'    => 'Εξετάστε πιο αναλυτική τεκμηρίωση των στόχων πριν την έναρξη της επόμενης αποστολής.',
    ];
    if ($tier === 'critical') {
        $weakKeys = [];
        foreach ($available as $key => $p) {
            if ($p['score'] < 65) $weakKeys[] = $key;
        }
        $weakKeys = array_slice($weakKeys, 0, 2);
        if (!empty($weakKeys)) {
            $weakLabels = array_map(fn($k) => $available[$k]['label'], $weakKeys);
            $sentences[] = 'Προτεραιότητα για μελλοντικές ασκήσεις θα πρέπει να αποτελέσει η βελτίωση σε: ' . implode(' και ', $weakLabels) . '.';
            foreach ($weakKeys as $k) {
                if (isset($recommendations[$k])) $sentences[] = $recommendations[$k];
            }
        }
    } elseif ($tier === 'warning' && $weakest !== null) {
        $sentences[] = "Ο τομέας «{$weakest['label']}» παραμένει ο πιο αδύναμος κρίκος και αξίζει ιδιαίτερη προσοχή στην επόμενη άσκηση.";
        $weakestKeyFinal = array_search($weakest, $available, true);
        if ($weakestKeyFinal !== false && isset($recommendations[$weakestKeyFinal])) $sentences[] = $recommendations[$weakestKeyFinal];
    } elseif ($tier === 'good') {
        if ($weakest !== null && $weakest['score'] < 85) {
            $sentences[] = "Παρά τη συνολικά άριστη εικόνα, ο τομέας «{$weakest['label']}» υπολείπεται ελαφρώς των υπολοίπων και θα μπορούσε να βελτιωθεί περαιτέρω.";
        } else {
            $sentences[] = 'Δεν εντοπίζονται αδυναμίες που να απαιτούν άμεση παρέμβαση.';
        }
    }

    $unavailable = array_filter($score['pillars'], fn($p) => !$p['available']);
    if (!empty($unavailable)) {
        $names = array_map(fn($p) => $p['label'], $unavailable);
        $sentences[] = 'Σημειώνεται ότι η αξιολόγηση δεν περιλαμβάνει: ' . implode(', ', $names) . ', λόγω έλλειψης σχετικών δεδομένων.';
    }

    return implode(' ', $sentences);
}

/**
 * War Room: the shorter companion paragraph for the new, deliberately
 * separate "command responsiveness" evaluation ($score['command']) — how
 * fast command staff saw and resolved teams' shortage reports, as judged on
 * its own rather than folded into the team-facing overall score.
 */
function generateCommandNarrative(array $command): string {
    if (!$command['available']) {
        return 'Δεν έχουν υποβληθεί αναφορές έλλειψης κατά τη διάρκεια της αποστολής, συνεπώς δεν υπάρχουν επαρκή δεδομένα για αξιολόγηση του χρόνου ανταπόκρισης της διοίκησης.';
    }
    $seenTxt = $command['avg_seen'] !== null ? number_format($command['avg_seen'], 1) . ' λεπτά' : '—';
    $resolvedTxt = $command['avg_resolved'] !== null ? number_format($command['avg_resolved'], 1) . ' λεπτά' : '—';
    $tier = $command['tier'][0];

    $base = "Η διοίκηση ανταποκρίθηκε στις {$command['total_reports']} αναφορές έλλειψης των ομάδων σε μέσο χρόνο παρατήρησης {$seenTxt} και προχώρησε σε επίλυση εντός {$resolvedTxt} κατά μέσο όρο.";
    $verdicts = [
        'good'     => 'Η ταχύτητα αυτή αντανακλά αποτελεσματική επιχειρησιακή εποπτεία και άμεση διαθεσιμότητα της διοίκησης.',
        'warning'  => 'Ο χρόνος αυτός είναι αποδεκτός, ωστόσο ταχύτερη πρώτη αντίδραση στις αναφορές θα ενίσχυε την επιχειρησιακή εικόνα.',
        'critical' => 'Ο χρόνος αυτός κρίνεται αυξημένος και ενδέχεται να έχει επιβαρύνει τη διαχείριση προβλημάτων στο πεδίο — συνιστάται στενότερη παρακολούθηση του καναλιού αναφορών σε επόμενες ασκήσεις.',
    ];
    $sentences = [$base, $verdicts[$tier]];

    if (!empty($command['forgotten_incidents'])) {
        $worst = $command['forgotten_incidents'][0];
        $hours = round($worst['minutes'] / 60, 1);
        $sentences[] = "Ιδιαίτερα αξιοσημείωτη είναι η αναφορά «{$worst['title']}» από {$worst['reporter_name']} ({$worst['team_label']}), η οποία παρέμεινε χωρίς παρατήρηση για περίπου {$hours} ώρες.";
    }

    return implode(' ', $sentences);
}

/**
 * War Room: the team-vs-team comparison paragraph appended after the main
 * observer narrative. Deliberately compares teams ONLY on dimensions that
 * are fair regardless of geography — order-ACKNOWLEDGMENT speed (a
 * device/UI action: tapping "Ελήφθη" doesn't require traveling anywhere),
 * completion RATE (measures follow-through, not raw travel time), and
 * shortage-report resolution. It never compares raw dispatch arrival/travel
 * time between teams, since a team sent to a point 1 hour away isn't
 * performing worse than one sent 10 minutes away — that's geography, not
 * diligence. (Dispatch arrival times stay visible in the existing
 * tables/charts as neutral per-team facts, just never used for a
 * comparative claim here.) Takes $score['teams'] (already ranked, each with
 * its own $pillars sub-array carrying the same 'raw' shape as the
 * mission-wide pillars).
 */
function generateTeamComparisonNarrative(array $teams): string {
    if (count($teams) < 2) {
        return '';
    }
    $label = fn($t) => $t['codename'] . ' ' . $t['team_number'];
    $sentences = [];

    // ── response speed (order acknowledgment) ───────────────────────────
    $withResponse = array_values(array_filter($teams, fn($t) => $t['pillars']['response']['available']));
    if (count($withResponse) >= 2) {
        usort($withResponse, fn($a, $b) => $a['pillars']['response']['raw']['avg_minutes'] <=> $b['pillars']['response']['raw']['avg_minutes']);
        $fastest = $withResponse[0];
        $slowest = $withResponse[count($withResponse) - 1];
        $fMin = $fastest['pillars']['response']['raw']['avg_minutes'];
        $sMin = $slowest['pillars']['response']['raw']['avg_minutes'];
        if ($label($fastest) === $label($slowest) || abs($fMin - $sMin) < 0.5) {
            $sentences[] = 'Ως προς την ταχύτητα αποδοχής εντολών, οι ομάδες παρουσίασαν παρόμοια απόδοση.';
        } else {
            $sentences[] = 'Ως προς την ταχύτητα αποδοχής εντολών, η ομάδα ' . $label($fastest) . " ξεχώρισε με μέσο χρόνο {$fMin} λεπτών, έναντι {$sMin} λεπτών της " . $label($slowest) . '.';
        }
    }

    // ── completion rate ──────────────────────────────────────────────────
    $withCompletion = array_values(array_filter($teams, fn($t) => $t['pillars']['completion']['available']));
    if (count($withCompletion) >= 2) {
        $rates = array_map(function ($t) use ($label) {
            $raw = $t['pillars']['completion']['raw'];
            return ['label' => $label($t), 'rate' => $raw['total'] ? round($raw['fulfilled'] / $raw['total'] * 100) : 0, 'raw' => $raw];
        }, $withCompletion);
        usort($rates, fn($a, $b) => $b['rate'] <=> $a['rate']);
        $best = $rates[0];
        $worst = $rates[count($rates) - 1];
        if ($best['label'] === $worst['label'] || abs($best['rate'] - $worst['rate']) < 10) {
            $sentences[] = 'Στο ποσοστό ολοκλήρωσης εντολών, οι ομάδες κινήθηκαν σε παρόμοια επίπεδα.';
        } else {
            $sentences[] = "Στο ποσοστό ολοκλήρωσης εντολών, η {$best['label']} πέτυχε {$best['rate']}% ({$best['raw']['fulfilled']}/{$best['raw']['total']}), έναντι {$worst['rate']}% ({$worst['raw']['fulfilled']}/{$worst['raw']['total']}) της {$worst['label']}.";
        }
    }

    // ── shortage handling — only teams that actually reported ≥1, so a team
    //    with zero reports (neutral 100 by design) never gets falsely
    //    compared against a team that genuinely resolved real reports ──────
    $withShortage = array_values(array_filter($teams, fn($t) => $t['pillars']['shortage']['raw']['total'] > 0));
    if (count($withShortage) >= 2) {
        $rates = array_map(function ($t) use ($label) {
            $raw = $t['pillars']['shortage']['raw'];
            return ['label' => $label($t), 'rate' => round($raw['resolved'] / $raw['total'] * 100), 'raw' => $raw];
        }, $withShortage);
        usort($rates, fn($a, $b) => $b['rate'] <=> $a['rate']);
        $best = $rates[0];
        $worst = $rates[count($rates) - 1];
        if ($best['label'] !== $worst['label'] && $best['rate'] !== $worst['rate']) {
            $sentences[] = "Στη διαχείριση αναφορών έλλειψης, η {$best['label']} έλυσε {$best['raw']['resolved']}/{$best['raw']['total']} αναφορές, ενώ η {$worst['label']} μόλις {$worst['raw']['resolved']}/{$worst['raw']['total']}.";
        }
    }

    // ── overall score gap ────────────────────────────────────────────────
    $byScore = $teams;
    usort($byScore, fn($a, $b) => $b['score'] <=> $a['score']);
    $top = $byScore[0];
    $bottom = $byScore[count($byScore) - 1];
    $topLabel = $label($top);
    $bottomLabel = $label($bottom);
    $topFmt = number_format($top['score'], 1);
    $bottomFmt = number_format($bottom['score'], 1);
    $gap = round($top['score'] - $bottom['score'], 1);
    if ($gap < 5) {
        $sentences[] = "Η συνολική βαθμολογία των ομάδων ήταν ιδιαίτερα ομοιογενής, με διαφορά μόλις {$gap} μονάδων μεταξύ {$topLabel} και {$bottomLabel}.";
    } elseif ($gap < 20) {
        $sentences[] = "Υπήρξε μέτρια απόκλιση {$gap} μονάδων μεταξύ της κορυφαίας ομάδας ({$topLabel}) και της {$bottomLabel}.";
    } else {
        $sentences[] = "Η απόσταση βαθμολογίας μεταξύ της κορυφαίας ομάδας ({$topLabel}, {$topFmt}) και της {$bottomLabel} ({$bottomFmt}) έφτασε τις {$gap} μονάδες, υποδεικνύοντας σημαντική ανομοιογένεια στην απόδοση μεταξύ των ομάδων.";
    }

    return implode(' ', $sentences);
}

/**
 * War Room: activity-feed events for the archival print/stats surfaces —
 * same 7 sources as mission-history.php's live Activity feed, but
 * unconditionally admin-scoped (no viewer-filtering predicates — every
 * caller of this helper is already permission-gated to admins) and
 * uncapped (no LIMIT 150 on pings, no 200-event slice). Deliberately NOT
 * unified with mission-history.php's query, which needs real per-viewer
 * WHERE-clause scoping this helper must not have. Returns events sorted
 * newest-first with a Unix `ts` — callers format their own display string.
 */
function loadMissionActivityEventsForReport(int $missionId): array {
    $events = [];

    $sentRows = dbFetchAll(
        "SELECT d.type, d.label, d.created_at, d.team_id, mt.codename, mt.team_number, u.name AS actor_name
         FROM mission_dispatch_points d
         LEFT JOIN mission_teams mt ON mt.id = d.team_id
         JOIN users u ON u.id = d.created_by
         WHERE d.mission_id = ?",
        [$missionId]
    );
    foreach ($sentRows as $row) {
        $teamLabel = $row['team_id'] ? ($row['codename'] . ' ' . $row['team_number']) : 'όλες τις ομάδες';
        $kind = $row['type'] === 'point' ? 'σημείο' : 'περιοχή';
        $events[] = [
            'icon' => '📍',
            'text' => h($row['actor_name']) . ' έστειλε ' . $kind . ' στη ' . h($teamLabel)
                . ($row['label'] ? ' — «' . h($row['label']) . '»' : ''),
            'ts'   => strtotime($row['created_at']),
        ];
    }

    $receivedRows = dbFetchAll(
        "SELECT rc.created_at, d.team_id, d.label, mt.codename, mt.team_number, u.name AS actor_name
         FROM mission_dispatch_receipts rc
         JOIN mission_dispatch_points d ON d.id = rc.dispatch_id
         LEFT JOIN mission_teams mt ON mt.id = d.team_id
         JOIN users u ON u.id = rc.user_id
         WHERE d.mission_id = ?",
        [$missionId]
    );
    foreach ($receivedRows as $row) {
        $teamLabel = $row['team_id'] ? ($row['codename'] . ' ' . $row['team_number']) : 'όλες τις ομάδες';
        $events[] = [
            'icon' => '🚩',
            'text' => h($row['actor_name']) . ' έλαβε εντολή προς ' . h($teamLabel)
                . ($row['label'] ? ' — «' . h($row['label']) . '»' : ''),
            'ts'   => strtotime($row['created_at']),
        ];
    }

    $arrivedRows = dbFetchAll(
        "SELECT a.created_at, a.team_id AS ack_team_id, amt.codename AS ack_codename, amt.team_number AS ack_team_number,
                au.name AS actor_name, d.label AS dispatch_label
         FROM mission_dispatch_acks a
         JOIN mission_dispatch_points d ON d.id = a.dispatch_id
         JOIN users au ON au.id = a.user_id
         LEFT JOIN mission_teams amt ON amt.id = a.team_id
         WHERE d.mission_id = ?",
        [$missionId]
    );
    foreach ($arrivedRows as $row) {
        $teamLabel = $row['ack_team_id'] ? ($row['ack_codename'] . ' ' . $row['ack_team_number']) : null;
        $events[] = [
            'icon' => '✅',
            'text' => ($teamLabel ? 'Η ομάδα ' . h($teamLabel) : h($row['actor_name'])) . ' ανέφερε άφιξη'
                . ($row['dispatch_label'] ? ' στο «' . h($row['dispatch_label']) . '»' : '')
                . ($teamLabel ? ' (' . h($row['actor_name']) . ')' : ''),
            'ts'   => strtotime($row['created_at']),
        ];
    }

    $orderTypeIcons = ['location' => '📍', 'photo' => '📷', 'video' => '🎥', 'task' => '📋', 'message' => '📢', 'return_to_base' => '🏁'];
    $orderRows = dbFetchAll(
        "SELECT o.order_type, o.task_text, o.created_at AS sent_at, r.team_id, r.acknowledged_at, r.fulfilled_at,
                u.name AS actor_name, mt.codename, mt.team_number
         FROM mission_order_recipients r
         JOIN mission_orders o ON o.id = r.order_id
         JOIN users u ON u.id = r.user_id
         LEFT JOIN mission_teams mt ON mt.id = r.team_id
         WHERE o.mission_id = ?",
        [$missionId]
    );
    foreach ($orderRows as $row) {
        $icon = $orderTypeIcons[$row['order_type']] ?? '📋';
        $teamLabel = $row['team_id'] ? ($row['codename'] . ' ' . $row['team_number']) : 'χωρίς ομάδα';
        $extra = '';
        if (in_array($row['order_type'], ['task', 'message'], true) && $row['task_text']) {
            $snippet = mb_strlen($row['task_text']) > 120 ? mb_substr($row['task_text'], 0, 117) . '…' : $row['task_text'];
            $extra = ' — «' . h($snippet) . '»';
        }
        $events[] = ['icon' => $icon, 'text' => 'Εντολή προς ' . h($row['actor_name']) . ' (' . h($teamLabel) . ')' . $extra, 'ts' => strtotime($row['sent_at'])];
        if ($row['acknowledged_at']) {
            $events[] = ['icon' => '👍', 'text' => h($row['actor_name']) . ' έλαβε εντολή (' . h($teamLabel) . ')' . $extra, 'ts' => strtotime($row['acknowledged_at'])];
        }
        if ($row['fulfilled_at']) {
            $events[] = ['icon' => '✅', 'text' => h($row['actor_name']) . ' ολοκλήρωσε εντολή (' . h($teamLabel) . ')' . $extra, 'ts' => strtotime($row['fulfilled_at'])];
        }
    }

    $fieldStatusIcons = ['field_status_on_way' => '🚗', 'field_status_on_site' => '✅', 'needs_help' => '🆘'];
    $fieldStatusText  = ['field_status_on_way' => 'σε κίνηση', 'field_status_on_site' => 'επί τόπου', 'needs_help' => 'χρειάζεται βοήθεια (SOS)'];
    $statusRows = dbFetchAll(
        "SELECT al.action, al.created_at, u.name AS actor_name
         FROM audit_logs al
         JOIN participation_requests pr ON pr.id = al.record_id
         JOIN shifts s ON s.id = pr.shift_id
         JOIN users u ON u.id = pr.volunteer_id
         WHERE al.table_name = 'participation_requests'
           AND al.action IN ('field_status_on_way', 'field_status_on_site', 'needs_help')
           AND s.mission_id = ?
         ORDER BY al.created_at DESC",
        [$missionId]
    );
    foreach ($statusRows as $row) {
        $events[] = ['icon' => $fieldStatusIcons[$row['action']] ?? '📶', 'text' => h($row['actor_name']) . ' → ' . $fieldStatusText[$row['action']], 'ts' => strtotime($row['created_at'])];
    }

    $pingRows = dbFetchAll(
        "SELECT vp.created_at, u.name AS actor_name
         FROM volunteer_pings vp
         JOIN shifts s ON s.id = vp.shift_id
         JOIN users u ON u.id = vp.user_id
         WHERE s.mission_id = ? AND vp.source = 'manual'
         ORDER BY vp.created_at DESC",
        [$missionId]
    );
    foreach ($pingRows as $row) {
        $events[] = ['icon' => '📡', 'text' => h($row['actor_name']) . ' έστειλε στίγμα GPS', 'ts' => strtotime($row['created_at'])];
    }

    $shortageEventRows = dbFetchAll(
        "SELECT r.shortage_type, r.title, r.created_at, r.acknowledged_at, r.resolved_at, r.not_resolved_at, r.outcome_note, u.name AS actor_name
         FROM mission_shortage_reports r
         JOIN users u ON u.id = r.reporter_id
         WHERE r.mission_id = ?",
        [$missionId]
    );
    foreach ($shortageEventRows as $row) {
        $label = SHORTAGE_TYPE_LABELS[$row['shortage_type']] ?? $row['shortage_type'];
        $noteSuffix = $row['outcome_note'] ? ' — «' . h($row['outcome_note']) . '»' : '';
        $events[] = ['icon' => '⚠️', 'text' => h($row['actor_name']) . ' ανέφερε έλλειψη (' . h($label) . ') — «' . h($row['title']) . '»', 'ts' => strtotime($row['created_at'])];
        if ($row['acknowledged_at']) {
            $events[] = ['icon' => '👁️', 'text' => 'Η αναφορά «' . h($row['title']) . '» ελέγχθηκε', 'ts' => strtotime($row['acknowledged_at'])];
        }
        if ($row['resolved_at']) {
            $events[] = ['icon' => '✅', 'text' => 'Η αναφορά «' . h($row['title']) . '» λύθηκε' . $noteSuffix, 'ts' => strtotime($row['resolved_at'])];
        }
        if ($row['not_resolved_at']) {
            $events[] = ['icon' => '🚫', 'text' => 'Η αναφορά «' . h($row['title']) . '» δεν λύθηκε' . $noteSuffix, 'ts' => strtotime($row['not_resolved_at'])];
        }
    }

    usort($events, fn($a, $b) => $b['ts'] <=> $a['ts']);
    return $events;
}
