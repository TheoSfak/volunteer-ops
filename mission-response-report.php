<?php
/**
 * VolunteerOps - Mission Response Time Report
 * War Room: admin-only report of how long teams/volunteers took to
 * acknowledge ("Ελήφθη") and fulfill (arrived / sent the ping / sent the
 * photo-video) every order sent this mission. Merges two storage shapes —
 * the generic mission_orders/mission_order_recipients system (location,
 * photo, video) and dispatch's native tables (mission_dispatch_points +
 * mission_dispatch_receipts + mission_dispatch_acks) — into one normalized
 * detail list, same merge-in-PHP technique mission-history.php already uses.
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
if (!$canManageWarRoom) {
    echo json_encode(['ok' => false, 'error' => 'Η αναφορά αυτή είναι διαθέσιμη μόνο σε διαχειριστές.']);
    exit;
}

function reportMinutesBetween(?string $from, ?string $to): ?float {
    if (!$from || !$to) {
        return null;
    }
    return round((strtotime($to) - strtotime($from)) / 60, 1);
}

$typeMeta = [
    'location' => '📍 Στίγμα GPS',
    'photo'    => '📷 Φωτογραφία',
    'video'    => '🎥 Βίντεο',
    'task'     => '📋 Γενική Εντολή',
    'message'  => '📢 Καθολικό Μήνυμα',
    'dispatch' => '🧭 Εντολή Κίνησης',
];

// Team names, loaded once so both the order-recipient rows (which only store
// team_id) and the dispatch merge below can resolve a label without re-joining.
$teamLabels = [];
foreach (dbFetchAll("SELECT id, codename, team_number FROM mission_teams WHERE mission_id = ?", [$missionId]) as $t) {
    $teamLabels[(int) $t['id']] = $t['codename'] . ' ' . $t['team_number'];
}

$detail = [];

// ── location/photo/video orders ─────────────────────────────────────────────
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
        'team_label'  => $teamId ? ($teamLabels[$teamId] ?? '—') : 'Χωρίς ομάδα',
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

    // Merge receipts ("Ελήφθη") and acks ("Άφιξη") per (dispatch, user) — a
    // user may have one, the other, or both; there's no fixed recipient list
    // for dispatch (unlike the generic orders above), so only people who
    // actually interacted appear here.
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
            'team_label' => $entry['team_id'] ? ($teamLabels[$entry['team_id']] ?? '—') : 'Χωρίς ομάδα',
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
        'team_label'        => $label,
        'order_count'       => $s['count'],
        'ack_rate'          => $s['count'] ? round($s['ack_count'] / $s['count'] * 100) : 0,
        'fulfill_rate'      => $s['count'] ? round($s['fulfill_count'] / $s['count'] * 100) : 0,
        'avg_ack_minutes'   => $s['ack_count'] ? round($s['ack_sum'] / $s['ack_count'], 1) : null,
        'avg_fulfill_minutes' => $s['fulfill_count'] ? round($s['fulfill_sum'] / $s['fulfill_count'], 1) : null,
    ];
}
usort($summary, fn($a, $b) => $b['order_count'] <=> $a['order_count']);

// Format timestamps for display after all math is done.
$detail = array_map(function ($row) {
    $row['sent_at'] = date('d/m H:i', strtotime($row['sent_at']));
    $row['ack_at'] = $row['ack_at'] ? date('d/m H:i', strtotime($row['ack_at'])) : null;
    $row['fulfill_at'] = $row['fulfill_at'] ? date('d/m H:i', strtotime($row['fulfill_at'])) : null;
    return $row;
}, $detail);

// ── shortage reports (inverse direction: admin responding to a team's report) ──
$shortageRows = dbFetchAll(
    "SELECT r.shortage_type, r.severity, r.title, r.created_at AS sent_at, r.team_id, u.name AS user_name,
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
        'type_label'     => SHORTAGE_TYPE_LABELS[$row['shortage_type']] ?? $row['shortage_type'],
        'severity'       => $row['severity'],
        'severity_label' => SHORTAGE_SEVERITY_LABELS[$row['severity']] ?? $row['severity'],
        'team_label'     => $teamId ? ($teamLabels[$teamId] ?? '—') : 'Χωρίς ομάδα',
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

$shortageDetail = array_map(function ($row) {
    $row['sent_at'] = date('d/m H:i', strtotime($row['sent_at']));
    $row['seen_at'] = $row['seen_at'] ? date('d/m H:i', strtotime($row['seen_at'])) : null;
    $row['resolved_at'] = $row['resolved_at'] ? date('d/m H:i', strtotime($row['resolved_at'])) : null;
    return $row;
}, $shortageDetail);

echo json_encode([
    'ok' => true, 'summary' => $summary, 'detail' => $detail,
    'shortageSummary' => $shortageSummary, 'shortageDetail' => $shortageDetail,
]);
