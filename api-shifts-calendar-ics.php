<?php
/**
 * VolunteerOps - ICS Calendar Export
 * Downloads an iCalendar (.ics) file for upcoming / filtered shifts.
 * Compatible with Google Calendar, Apple Calendar, Outlook.
 *
 * GET params (same as api-shifts-calendar.php):
 *   department_id    int   (optional, admin-only)
 *   mission_type_id  int   (optional)
 *   mine             1/0   (optional, default 1 for volunteers / 0 for admins)
 *   range_days       int   (optional, how many days ahead, default 90)
 */

define('VOLUNTEEROPS', true);
require_once __DIR__ . '/bootstrap.php';

if (!isLoggedIn()) {
    http_response_code(401);
    header('Content-Type: text/plain');
    echo 'Unauthorized';
    exit;
}

$userId        = getCurrentUserId();
$deptId        = (int) get('department_id', 0);
$missionTypeId = (int) get('mission_type_id', 0);
$mineOnly      = get('mine', isAdmin() ? '0' : '1') === '1';
$rangeDays     = min(max((int) get('range_days', 90), 7), 365);

// ── Build date range ──────────────────────────────────────────────────────────
$now     = date('Y-m-d H:i:s');
$rangeEnd = date('Y-m-d H:i:s', strtotime("+{$rangeDays} days"));

// ── Build WHERE ───────────────────────────────────────────────────────────────
$where  = ['m.deleted_at IS NULL', 's.end_time > ?', 's.start_time < ?'];
$params = [$now, $rangeEnd];

if (!isAdmin()) {
    $where[]  = "(m.status IN (?,?,?)
                   OR EXISTS (SELECT 1 FROM participation_requests pr2
                              WHERE pr2.shift_id = s.id AND pr2.volunteer_id = ?))";
    $params[] = STATUS_OPEN;
    $params[] = STATUS_CLOSED;
    $params[] = STATUS_COMPLETED;
    $params[] = $userId;
}

if (!canSeeTep()) {
    $where[]  = '(m.mission_type_id != ? OR m.responsible_user_id = ?)';
    $params[] = getTepMissionTypeId();
    $params[] = $userId;
}

if ($deptId && isAdmin()) {
    $where[]  = 'm.department_id = ?';
    $params[] = $deptId;
}

if ($missionTypeId) {
    $where[]  = 'm.mission_type_id = ?';
    $params[] = $missionTypeId;
}

if ($mineOnly) {
    $where[]  = "EXISTS (SELECT 1 FROM participation_requests pr_mine
                         WHERE pr_mine.shift_id = s.id AND pr_mine.volunteer_id = ?)";
    $params[] = $userId;
}

$whereClause = implode(' AND ', $where);

// ── Fetch shifts ──────────────────────────────────────────────────────────────
$shifts = dbFetchAll(
    "SELECT
         s.id,
         s.start_time,
         s.end_time,
         s.notes,
         m.title       AS mission_title,
         m.location,
         m.is_urgent,
         mt.name       AS type_name,
         d.name        AS dept_name,
         COALESCE(pr_app.cnt, 0) AS approved_count,
         s.max_volunteers
     FROM shifts s
     JOIN missions m  ON s.mission_id = m.id
     LEFT JOIN mission_types mt  ON m.mission_type_id = mt.id
     LEFT JOIN departments d     ON m.department_id = d.id
     LEFT JOIN (
         SELECT shift_id, COUNT(*) AS cnt
         FROM   participation_requests
         WHERE  status = '" . PARTICIPATION_APPROVED . "'
         GROUP  BY shift_id
     ) pr_app ON s.id = pr_app.shift_id
     WHERE $whereClause
     ORDER BY s.start_time",
    $params
);

// ── ICS helpers ───────────────────────────────────────────────────────────────

/**
 * Fold long ICS lines at 75 octets (RFC 5545 §3.1).
 */
function icsFold(string $line): string {
    $out   = '';
    $bytes = 0;
    $chars = mb_str_split($line);
    foreach ($chars as $ch) {
        $len = strlen($ch); // byte length
        if ($bytes + $len > 75) {
            $out  .= "\r\n ";
            $bytes = 1; // leading space counts
        }
        $out   .= $ch;
        $bytes += $len;
    }
    return $out;
}

/**
 * Escape ICS text values (commas, semicolons, backslashes, newlines).
 */
function icsText(string $val): string {
    $val = str_replace('\\', '\\\\', $val);
    $val = str_replace(',',  '\\,',  $val);
    $val = str_replace(';',  '\\;',  $val);
    $val = str_replace("\n", '\\n',  $val);
    return $val;
}

/**
 * Format a MySQL datetime as iCalendar DATETIME (local, no UTC suffix).
 */
function icsDate(string $mysqlDt): string {
    return date('Ymd\THis', strtotime($mysqlDt));
}

// ── Build ICS ─────────────────────────────────────────────────────────────────
$appName  = getSetting('app_name', 'VolunteerOps');
$prodId   = '-//VolunteerOps//ShiftCalendar//EL';
$tzid     = 'Europe/Athens';

$lines = [];
$lines[] = 'BEGIN:VCALENDAR';
$lines[] = 'VERSION:2.0';
$lines[] = 'PRODID:' . $prodId;
$lines[] = 'CALSCALE:GREGORIAN';
$lines[] = 'METHOD:PUBLISH';
$lines[] = 'X-WR-CALNAME:' . icsText($appName . ' - Βάρδιες');
$lines[] = 'X-WR-TIMEZONE:' . $tzid;

// VTIMEZONE block for Europe/Athens (EET/EEST)
$lines[] = 'BEGIN:VTIMEZONE';
$lines[] = 'TZID:' . $tzid;
$lines[] = 'BEGIN:STANDARD';
$lines[] = 'DTSTART:19701025T040000';
$lines[] = 'RRULE:FREQ=YEARLY;BYDAY=-1SU;BYMONTH=10';
$lines[] = 'TZNAME:EET';
$lines[] = 'TZOFFSETFROM:+0300';
$lines[] = 'TZOFFSETTO:+0200';
$lines[] = 'END:STANDARD';
$lines[] = 'BEGIN:DAYLIGHT';
$lines[] = 'DTSTART:19700329T030000';
$lines[] = 'RRULE:FREQ=YEARLY;BYDAY=-1SU;BYMONTH=3';
$lines[] = 'TZNAME:EEST';
$lines[] = 'TZOFFSETFROM:+0200';
$lines[] = 'TZOFFSETTO:+0300';
$lines[] = 'END:DAYLIGHT';
$lines[] = 'END:VTIMEZONE';

foreach ($shifts as $s) {
    $title       = ($s['is_urgent'] ? '[ΕΠΕΙΓΟΝ] ' : '') . $s['mission_title'] . ' — Βάρδια #' . $s['id'];
    $uid         = 'shift-' . $s['id'] . '@volunteerops';
    $dtstamp     = gmdate('Ymd\THis\Z');
    $dtstart     = icsDate($s['start_time']);
    $dtend       = icsDate($s['end_time']);

    // Build description
    $desc  = $s['mission_title'];
    if ($s['type_name'])  $desc .= "\nΤύπος: " . $s['type_name'];
    if ($s['dept_name'])  $desc .= "\nΤμήμα: " . $s['dept_name'];
    $desc .= "\nΕθελοντές: " . $s['approved_count'] . '/' . $s['max_volunteers'];
    if ($s['notes'])      $desc .= "\nΣημειώσεις: " . $s['notes'];
    $desc .= "\n\nΛεπτομέρειες: " . (isset($_SERVER['HTTP_HOST'])
        ? 'http' . ((!empty($_SERVER['HTTPS'])) ? 's' : '') . '://' . $_SERVER['HTTP_HOST']
          . dirname($_SERVER['PHP_SELF']) . '/shift-view.php?id=' . $s['id']
        : 'shift-view.php?id=' . $s['id']);

    $lines[] = 'BEGIN:VEVENT';
    $lines[] = icsFold('UID:' . $uid);
    $lines[] = 'DTSTAMP:' . $dtstamp;
    $lines[] = 'DTSTART;TZID=' . $tzid . ':' . $dtstart;
    $lines[] = 'DTEND;TZID=' . $tzid . ':' . $dtend;
    $lines[] = icsFold('SUMMARY:' . icsText($title));
    $lines[] = icsFold('DESCRIPTION:' . icsText($desc));
    if ($s['location']) {
        $lines[] = icsFold('LOCATION:' . icsText($s['location']));
    }
    if ($s['is_urgent']) {
        $lines[] = 'PRIORITY:1';
    }
    $lines[] = 'END:VEVENT';
}

$lines[] = 'END:VCALENDAR';

// ── Output ────────────────────────────────────────────────────────────────────
$filename = 'volunteerops-shifts-' . date('Ymd') . '.ics';
header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');
echo implode("\r\n", $lines) . "\r\n";
exit;
