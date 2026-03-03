<?php
/**
 * VolunteerOps - API: Shifts Calendar Feed
 * Returns FullCalendar-compatible EventInput JSON for the given date range.
 *
 * GET params:
 *   start          ISO date (FullCalendar range start, required)
 *   end            ISO date (FullCalendar range end, required)
 *   department_id  int (optional, admin-only filter)
 *   mission_type_id int (optional)
 *   mine           1/0 (optional, only shifts the current user participates in)
 */

define('VOLUNTEEROPS', true);
require_once __DIR__ . '/bootstrap.php';

// Must be logged in
if (!isLoggedIn()) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

// ── Parameters ────────────────────────────────────────────────────────────────
$rangeStart    = get('start', '');
$rangeEnd      = get('end', '');
$deptId        = (int) get('department_id', 0);
$missionTypeId = (int) get('mission_type_id', 0);
$mineOnly      = get('mine', '0') === '1';

// Validate date range
if (!$rangeStart || !$rangeEnd) {
    echo json_encode([]);
    exit;
}

$userId = getCurrentUserId();

// ── Build WHERE ───────────────────────────────────────────────────────────────
$where  = ['m.deleted_at IS NULL'];
$params = [];

// Date range — FullCalendar sends ISO strings like 2026-03-01T00:00:00
$where[]  = 's.start_time < ?';
$params[] = date('Y-m-d H:i:s', strtotime($rangeEnd));
$where[]  = 's.end_time > ?';
$params[] = date('Y-m-d H:i:s', strtotime($rangeStart));

// Non-admins: only open/closed/completed missions they can see
if (!isAdmin()) {
    $where[]  = "(m.status IN ('OPEN', 'CLOSED', 'COMPLETED')
                   OR EXISTS (SELECT 1 FROM participation_requests pr2
                              WHERE pr2.shift_id = s.id AND pr2.volunteer_id = ?))";
    $params[] = $userId;
}

// Τ.Ε.Π. visibility
if (!canSeeTep()) {
    $where[]  = '(m.mission_type_id != ? OR m.responsible_user_id = ?)';
    $params[] = getTepMissionTypeId();
    $params[] = $userId;
}

// Department filter (admin-only; volunteers only see their own dept shifts anyway)
if ($deptId && isAdmin()) {
    $where[]  = 'm.department_id = ?';
    $params[] = $deptId;
}

// Mission type filter
if ($missionTypeId) {
    $where[]  = 'm.mission_type_id = ?';
    $params[] = $missionTypeId;
}

// "Mine only" filter
if ($mineOnly) {
    $where[]  = 'EXISTS (SELECT 1 FROM participation_requests pr_mine
                         WHERE pr_mine.shift_id = s.id AND pr_mine.volunteer_id = ?)';
    $params[] = $userId;
}

$whereClause = implode(' AND ', $where);

// ── Query ─────────────────────────────────────────────────────────────────────
$shifts = dbFetchAll(
    "SELECT
         s.id,
         s.mission_id,
         s.start_time,
         s.end_time,
         s.max_volunteers,
         s.min_volunteers,
         s.notes,
         m.title          AS mission_title,
         m.status         AS mission_status,
         m.is_urgent,
         m.location,
         mt.name          AS type_name,
         mt.color         AS type_color,
         COALESCE(pr_app.cnt, 0)  AS approved_count,
         COALESCE(pr_pend.cnt, 0) AS pending_count,
         my_pr.status            AS my_status
     FROM shifts s
     JOIN missions m  ON s.mission_id = m.id
     LEFT JOIN mission_types mt ON m.mission_type_id = mt.id
     LEFT JOIN (
         SELECT shift_id, COUNT(*) AS cnt
         FROM   participation_requests
         WHERE  status = '" . PARTICIPATION_APPROVED . "'
         GROUP  BY shift_id
     ) pr_app  ON s.id = pr_app.shift_id
     LEFT JOIN (
         SELECT shift_id, COUNT(*) AS cnt
         FROM   participation_requests
         WHERE  status = '" . PARTICIPATION_PENDING . "'
         GROUP  BY shift_id
     ) pr_pend ON s.id = pr_pend.shift_id
     LEFT JOIN participation_requests my_pr
           ON  my_pr.shift_id = s.id
           AND my_pr.volunteer_id = ?
     WHERE $whereClause
     ORDER BY s.start_time",
    array_merge([$userId], $params)
);

// ── Build FullCalendar events ─────────────────────────────────────────────────
$now    = time();
$events = [];

foreach ($shifts as $s) {
    $approved    = (int) $s['approved_count'];
    $max         = (int) $s['max_volunteers'];
    $isPast      = strtotime($s['end_time']) < $now;
    $isCanceled  = in_array($s['mission_status'], ['CANCELED']);

    // Determine fill rate colour (always used for both bg and border)
    if ($isCanceled) {
        $color = '#6c757d'; // grey — canceled
    } elseif ($isPast) {
        $color = '#495057'; // dark grey — past
    } else {
        $fillPct = $max > 0 ? ($approved / $max) * 100 : 0;
        if ($fillPct >= 80) {
            $color = '#146c43'; // dark green — full
        } elseif ($fillPct >= 50) {
            $color = '#cc6c0a'; // dark orange — medium
        } else {
            $color = '#b02a37'; // dark red — low
        }
    }

    // Build title
    $shiftLabel  = 'Βάρδια #' . $s['id'];
    $title       = $s['mission_title'] . ' — ' . $shiftLabel;
    if ($s['is_urgent']) {
        $title = '🔴 ' . $title;
    }

    $event = [
        'id'              => 'shift-' . $s['id'],
        'title'           => $title,
        'start'           => $s['start_time'],
        'end'             => $s['end_time'],
        'url'             => 'shift-view.php?id=' . $s['id'],
        'backgroundColor' => $color,
        'borderColor'     => $color,
        'textColor'       => '#ffffff',
        'extendedProps'   => [
            'shift_id'       => $s['id'],
            'mission_title'  => $s['mission_title'],
            'mission_status' => $s['mission_status'],
            'type_name'      => $s['type_name'],
            'approved_count' => $approved,
            'max_volunteers' => $max,
            'pending_count'  => (int) $s['pending_count'],
            'my_status'      => $s['my_status'],
            'location'       => $s['location'],
            'notes'          => $s['notes'],
            'is_urgent'      => (bool) $s['is_urgent'],
            'is_past'        => $isPast,
            'fill_pct'       => $max > 0 ? round(($approved / $max) * 100) : 0,
            'color'          => $color,
        ],
    ];

    $events[] = $event;
}

echo json_encode($events, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;
