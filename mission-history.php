<?php
/**
 * VolunteerOps - Mission Activity Feed Endpoint ("Δραστηριότητα")
 * War Room: unified timeline of everything timestamped in a mission —
 * dispatch (sent/received/arrived), all 4 order types (sent/acknowledged/
 * fulfilled), field-status changes (incl. SOS), GPS pings, and shortage
 * reports (submitted/seen/resolved). Each source is fetched separately and
 * normalized, then merged+sorted+capped in PHP (same technique this file
 * already used for dispatch-only, and mission-response-report.php reused).
 *
 * Visibility uses two different predicates depending on what team_id means
 * for that source:
 *  - Dispatch family: team_id is the point's deliberate TARGET — NULL means
 *    "sent to all teams", so it's visible to everyone. ($dispatchScopeSql,
 *    unchanged from the original dispatch-only version of this file.)
 *  - Everything else: team_id is the ACTOR's own team membership — NULL
 *    means "this person currently has no team", so it must be private to
 *    them + admin, NOT broadcast to every other team. Using the dispatch
 *    predicate here would leak a teamless volunteer's pings/status/reports
 *    to every other team. Predicate 2 never has a "team_id IS NULL" clause.
 *
 * GET only, AJAX.
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();

header('Content-Type: application/json');

$userId = getCurrentUserId();

$missionId = (int) get('mission_id');

$mission = dbFetchOne(
    "SELECT id, title, status, show_in_ops, responsible_user_id FROM missions WHERE id = ? AND deleted_at IS NULL",
    [$missionId]
);
if (!$mission || $mission['status'] !== STATUS_OPEN || empty($mission['show_in_ops'])) {
    echo json_encode(['ok' => false, 'error' => 'Η αποστολή δεν βρέθηκε ή δεν είναι ενεργή στο Επιχειρησιακό.']);
    exit;
}

$canManageWarRoom = hasPagePermission('missions_manage') || (int)$mission['responsible_user_id'] === (int)$userId;
$isApprovedParticipant = (bool) dbFetchValue(
    "SELECT COUNT(*) FROM participation_requests pr
     JOIN shifts s ON s.id = pr.shift_id
     WHERE s.mission_id = ? AND pr.volunteer_id = ? AND pr.status = ?",
    [$missionId, $userId, PARTICIPATION_APPROVED]
);
if (!$canManageWarRoom && !$isApprovedParticipant) {
    echo json_encode(['ok' => false, 'error' => 'Δεν έχετε πρόσβαση στο War Room αυτής της αποστολής.']);
    exit;
}

$isAdminParam = $canManageWarRoom ? 1 : 0;
$viewerTeamId = getUserTeamIdForMission($missionId, $userId);

// Predicate 1 — dispatch family only (sent/received/arrived).
$dispatchScopeSql = "(d.team_id IS NULL OR ? = 1 OR d.team_id IN (SELECT team_id FROM mission_team_members WHERE user_id = ?))";

$events = [];

// ── dispatch sent ────────────────────────────────────────────────────────────
$sentRows = dbFetchAll(
    "SELECT d.id, d.type, d.label, d.created_at, d.team_id, mt.codename, mt.team_number, u.name AS actor_name
     FROM mission_dispatch_points d
     LEFT JOIN mission_teams mt ON mt.id = d.team_id
     JOIN users u ON u.id = d.created_by
     WHERE d.mission_id = ? AND $dispatchScopeSql",
    [$missionId, $isAdminParam, $userId]
);
foreach ($sentRows as $row) {
    $teamLabel = $row['team_id'] ? ($row['codename'] . ' ' . $row['team_number']) : 'όλες τις ομάδες';
    $kind = $row['type'] === 'point' ? 'σημείο' : 'περιοχή';
    $events[] = [
        'icon' => '📍',
        'text' => h($row['actor_name']) . ' έστειλε ' . $kind . ' στη ' . h($teamLabel)
            . ($row['label'] ? ' — «' . h($row['label']) . '»' : ''),
        'time' => date('d/m H:i', strtotime($row['created_at'])),
        'ts'   => strtotime($row['created_at']),
    ];
}

// ── dispatch received ("Ελήφθη") ─────────────────────────────────────────────
$receivedRows = dbFetchAll(
    "SELECT rc.created_at, d.team_id, d.label, mt.codename, mt.team_number, u.name AS actor_name
     FROM mission_dispatch_receipts rc
     JOIN mission_dispatch_points d ON d.id = rc.dispatch_id
     LEFT JOIN mission_teams mt ON mt.id = d.team_id
     JOIN users u ON u.id = rc.user_id
     WHERE d.mission_id = ? AND $dispatchScopeSql",
    [$missionId, $isAdminParam, $userId]
);
foreach ($receivedRows as $row) {
    $teamLabel = $row['team_id'] ? ($row['codename'] . ' ' . $row['team_number']) : 'όλες τις ομάδες';
    $events[] = [
        'icon' => '🚩',
        'text' => h($row['actor_name']) . ' έλαβε εντολή προς ' . h($teamLabel)
            . ($row['label'] ? ' — «' . h($row['label']) . '»' : ''),
        'time' => date('d/m H:i', strtotime($row['created_at'])),
        'ts'   => strtotime($row['created_at']),
    ];
}

// ── dispatch arrived ──────────────────────────────────────────────────────────
$arrivedRows = dbFetchAll(
    "SELECT a.created_at, a.team_id AS ack_team_id, amt.codename AS ack_codename, amt.team_number AS ack_team_number,
            au.name AS actor_name, d.label AS dispatch_label
     FROM mission_dispatch_acks a
     JOIN mission_dispatch_points d ON d.id = a.dispatch_id
     JOIN users au ON au.id = a.user_id
     LEFT JOIN mission_teams amt ON amt.id = a.team_id
     WHERE d.mission_id = ? AND $dispatchScopeSql",
    [$missionId, $isAdminParam, $userId]
);
foreach ($arrivedRows as $row) {
    $teamLabel = $row['ack_team_id'] ? ($row['ack_codename'] . ' ' . $row['ack_team_number']) : null;
    $events[] = [
        'icon' => '✅',
        'text' => ($teamLabel ? 'Η ομάδα ' . h($teamLabel) : h($row['actor_name'])) . ' ανέφερε άφιξη'
            . ($row['dispatch_label'] ? ' στο «' . h($row['dispatch_label']) . '»' : '')
            . ($teamLabel ? ' (' . h($row['actor_name']) . ')' : ''),
        'time' => date('d/m H:i', strtotime($row['created_at'])),
        'ts'   => strtotime($row['created_at']),
    ];
}

// ── orders (location/photo/video/task): sent / acknowledged / fulfilled ───────
$orderTypeIcons = ['location' => '📍', 'photo' => '📷', 'video' => '🎥', 'task' => '📋'];
$orderRows = dbFetchAll(
    "SELECT o.order_type, o.task_text, o.created_at AS sent_at, r.team_id, r.acknowledged_at, r.fulfilled_at,
            u.name AS actor_name, mt.codename, mt.team_number
     FROM mission_order_recipients r
     JOIN mission_orders o ON o.id = r.order_id
     JOIN users u ON u.id = r.user_id
     LEFT JOIN mission_teams mt ON mt.id = r.team_id
     WHERE o.mission_id = ? AND (? = 1 OR r.user_id = ? OR r.team_id = ?)",
    [$missionId, $isAdminParam, $userId, $viewerTeamId]
);
foreach ($orderRows as $row) {
    $icon = $orderTypeIcons[$row['order_type']] ?? '📋';
    $teamLabel = $row['team_id'] ? ($row['codename'] . ' ' . $row['team_number']) : 'χωρίς ομάδα';
    $extra = '';
    if ($row['order_type'] === 'task' && $row['task_text']) {
        $snippet = mb_strlen($row['task_text']) > 120 ? mb_substr($row['task_text'], 0, 117) . '…' : $row['task_text'];
        $extra = ' — «' . h($snippet) . '»';
    }
    $events[] = [
        'icon' => $icon,
        'text' => 'Εντολή προς ' . h($row['actor_name']) . ' (' . h($teamLabel) . ')' . $extra,
        'time' => date('d/m H:i', strtotime($row['sent_at'])),
        'ts'   => strtotime($row['sent_at']),
    ];
    if ($row['acknowledged_at']) {
        $events[] = [
            'icon' => '👍',
            'text' => h($row['actor_name']) . ' έλαβε εντολή (' . h($teamLabel) . ')' . $extra,
            'time' => date('d/m H:i', strtotime($row['acknowledged_at'])),
            'ts'   => strtotime($row['acknowledged_at']),
        ];
    }
    if ($row['fulfilled_at']) {
        $events[] = [
            'icon' => '✅',
            'text' => h($row['actor_name']) . ' ολοκλήρωσε εντολή (' . h($teamLabel) . ')' . $extra,
            'time' => date('d/m H:i', strtotime($row['fulfilled_at'])),
            'ts'   => strtotime($row['fulfilled_at']),
        ];
    }
}

// ── field-status changes (on_way / on_site / needs_help) via audit_logs ───────
// table_name filter is load-bearing: audit_logs.record_id has no FK, so
// without it this join would match unrelated logAudit() call sites by
// coincidental numeric id.
$fieldStatusIcons = ['field_status_on_way' => '🚗', 'field_status_on_site' => '✅', 'needs_help' => '🆘'];
$fieldStatusText  = ['field_status_on_way' => 'σε κίνηση', 'field_status_on_site' => 'επί τόπου', 'needs_help' => 'χρειάζεται βοήθεια (SOS)'];
$statusRows = dbFetchAll(
    "SELECT al.action, al.created_at, u.name AS actor_name, mtm.team_id AS actor_team_id
     FROM audit_logs al
     JOIN participation_requests pr ON pr.id = al.record_id
     JOIN shifts s ON s.id = pr.shift_id
     JOIN users u ON u.id = pr.volunteer_id
     LEFT JOIN mission_team_members mtm ON mtm.mission_id = s.mission_id AND mtm.user_id = pr.volunteer_id
     WHERE al.table_name = 'participation_requests'
       AND al.action IN ('field_status_on_way', 'field_status_on_site', 'needs_help')
       AND s.mission_id = ? AND (? = 1 OR pr.volunteer_id = ? OR mtm.team_id = ?)
     ORDER BY al.created_at DESC",
    [$missionId, $isAdminParam, $userId, $viewerTeamId]
);
foreach ($statusRows as $row) {
    $events[] = [
        'icon' => $fieldStatusIcons[$row['action']] ?? '📶',
        'text' => h($row['actor_name']) . ' → ' . $fieldStatusText[$row['action']],
        'time' => date('d/m H:i', strtotime($row['created_at'])),
        'ts'   => strtotime($row['created_at']),
    ];
}

// ── GPS pings ("στίγματα") ─────────────────────────────────────────────────────
$pingRows = dbFetchAll(
    "SELECT vp.created_at, u.name AS actor_name, mtm.team_id AS actor_team_id
     FROM volunteer_pings vp
     JOIN shifts s ON s.id = vp.shift_id
     JOIN users u ON u.id = vp.user_id
     LEFT JOIN mission_team_members mtm ON mtm.mission_id = s.mission_id AND mtm.user_id = vp.user_id
     WHERE s.mission_id = ? AND (? = 1 OR vp.user_id = ? OR mtm.team_id = ?)
     ORDER BY vp.created_at DESC LIMIT 150",
    [$missionId, $isAdminParam, $userId, $viewerTeamId]
);
foreach ($pingRows as $row) {
    $events[] = [
        'icon' => '📡',
        'text' => h($row['actor_name']) . ' έστειλε στίγμα GPS',
        'time' => date('d/m H:i', strtotime($row['created_at'])),
        'ts'   => strtotime($row['created_at']),
    ];
}

// ── shortage reports: submitted / seen / resolved ──────────────────────────────
// Keyed off the report's own reporter_id/team_id for all three sub-events —
// never acknowledged_by/resolved_by (almost always the admin), which would
// silently break the reporter's own visibility into their report's outcome.
$shortageRows = dbFetchAll(
    "SELECT r.shortage_type, r.title, r.created_at, r.acknowledged_at, r.resolved_at, u.name AS actor_name
     FROM mission_shortage_reports r
     JOIN users u ON u.id = r.reporter_id
     WHERE r.mission_id = ? AND (? = 1 OR r.reporter_id = ? OR r.team_id = ?)",
    [$missionId, $isAdminParam, $userId, $viewerTeamId]
);
foreach ($shortageRows as $row) {
    $label = SHORTAGE_TYPE_LABELS[$row['shortage_type']] ?? $row['shortage_type'];
    $events[] = [
        'icon' => '⚠️',
        'text' => h($row['actor_name']) . ' ανέφερε έλλειψη (' . h($label) . ') — «' . h($row['title']) . '»',
        'time' => date('d/m H:i', strtotime($row['created_at'])),
        'ts'   => strtotime($row['created_at']),
    ];
    if ($row['acknowledged_at']) {
        $events[] = [
            'icon' => '👁️',
            'text' => 'Η αναφορά «' . h($row['title']) . '» ελέγχθηκε',
            'time' => date('d/m H:i', strtotime($row['acknowledged_at'])),
            'ts'   => strtotime($row['acknowledged_at']),
        ];
    }
    if ($row['resolved_at']) {
        $events[] = [
            'icon' => '✅',
            'text' => 'Η αναφορά «' . h($row['title']) . '» λύθηκε',
            'time' => date('d/m H:i', strtotime($row['resolved_at'])),
            'ts'   => strtotime($row['resolved_at']),
        ];
    }
}

usort($events, fn($a, $b) => $b['ts'] <=> $a['ts']);
$events = array_slice($events, 0, 200);

echo json_encode([
    'ok'     => true,
    'events' => array_map(fn($e) => ['icon' => $e['icon'], 'text' => $e['text'], 'time' => $e['time']], $events),
]);
