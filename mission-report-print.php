<?php
/**
 * VolunteerOps - Mission Report Print View
 * War Room: archival export combining the Activity feed, the response-time
 * report (orders + shortage reports), and field photos/videos into one
 * printable document — meant to be generated *after* a mission closes, for
 * a unit's own records. Mirrors inventory-print.php's structure (own
 * <!DOCTYPE html>, no shared header/footer, @media print styling, browser
 * print-to-PDF instead of a server-side PDF library — this app has none).
 *
 * Deliberately does NOT gate on mission status/show_in_ops like every other
 * War Room endpoint does — those gates exist to lock down the *live-ops*
 * tools once a mission closes, which is correct for them but would make an
 * *archival* export unusable for the exact moment it's meant to be used.
 * Only the permission check matters here. mission-photo-view.php's own gate
 * was loosened (OPEN or CLOSED) to match, so embedded photos keep working.
 *
 * Query logic below is a deliberate copy of mission-history.php's 7 event
 * sources and mission-response-report.php's summary/detail math, not a
 * proxy through those endpoints — they inherit the STATUS_OPEN gate this
 * page must not have. Photos/videos are the one piece that's reused as-is
 * via loadMissionPhotosForUser(), since that's a plain function call with
 * no HTTP-level gate of its own.
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();

$userId = getCurrentUserId();
$missionId = (int) get('mission_id');

$mission = dbFetchOne(
    "SELECT m.*, d.name AS department_name, r.name AS responsible_name
     FROM missions m
     LEFT JOIN departments d ON d.id = m.department_id
     LEFT JOIN users r ON r.id = m.responsible_user_id
     WHERE m.id = ? AND m.deleted_at IS NULL",
    [$missionId]
);
if (!$mission) {
    setFlash('error', 'Η αποστολή δεν βρέθηκε.');
    redirect('dashboard.php');
}

$canManageWarRoom = hasPagePermission('missions_manage') || (int)$mission['responsible_user_id'] === (int)$userId;
if (!$canManageWarRoom) {
    setFlash('error', 'Η αναφορά αυτή είναι διαθέσιμη μόνο σε διαχειριστές.');
    redirect('mission-view.php?id=' . $missionId);
}

function reportMinutesBetween(?string $from, ?string $to): ?float {
    if (!$from || !$to) {
        return null;
    }
    return round((strtotime($to) - strtotime($from)) / 60, 1);
}

// ═══════════════════════════════════════════════════════════════════════════
// 1. Activity feed — same 7 sources as mission-history.php, unscoped (this
//    whole page is admin-only already) and uncapped (archival, not live UI).
// ═══════════════════════════════════════════════════════════════════════════
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

$orderTypeIcons = ['location' => '📍', 'photo' => '📷', 'video' => '🎥', 'task' => '📋', 'message' => '📢'];
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
     WHERE s.mission_id = ?
     ORDER BY vp.created_at DESC",
    [$missionId]
);
foreach ($pingRows as $row) {
    $events[] = ['icon' => '📡', 'text' => h($row['actor_name']) . ' έστειλε στίγμα GPS', 'ts' => strtotime($row['created_at'])];
}

$shortageEventRows = dbFetchAll(
    "SELECT r.shortage_type, r.title, r.created_at, r.acknowledged_at, r.resolved_at, u.name AS actor_name
     FROM mission_shortage_reports r
     JOIN users u ON u.id = r.reporter_id
     WHERE r.mission_id = ?",
    [$missionId]
);
foreach ($shortageEventRows as $row) {
    $label = SHORTAGE_TYPE_LABELS[$row['shortage_type']] ?? $row['shortage_type'];
    $events[] = ['icon' => '⚠️', 'text' => h($row['actor_name']) . ' ανέφερε έλλειψη (' . h($label) . ') — «' . h($row['title']) . '»', 'ts' => strtotime($row['created_at'])];
    if ($row['acknowledged_at']) {
        $events[] = ['icon' => '👁️', 'text' => 'Η αναφορά «' . h($row['title']) . '» ελέγχθηκε', 'ts' => strtotime($row['acknowledged_at'])];
    }
    if ($row['resolved_at']) {
        $events[] = ['icon' => '✅', 'text' => 'Η αναφορά «' . h($row['title']) . '» λύθηκε', 'ts' => strtotime($row['resolved_at'])];
    }
}

usort($events, fn($a, $b) => $b['ts'] <=> $a['ts']);
foreach ($events as &$e) {
    $e['time'] = date('d/m/Y H:i', $e['ts']);
}
unset($e);

// ═══════════════════════════════════════════════════════════════════════════
// 2. Response-time report — same math as mission-response-report.php,
//    already fully admin-scoped there so nothing to strip.
// ═══════════════════════════════════════════════════════════════════════════
$typeMeta = [
    'location' => '📍 Στίγμα GPS', 'photo' => '📷 Φωτογραφία', 'video' => '🎥 Βίντεο',
    'task' => '📋 Γενική Εντολή', 'message' => '📢 Καθολικό Μήνυμα', 'dispatch' => '🧭 Εντολή Κίνησης',
];
$teamLabels = [];
foreach (dbFetchAll("SELECT id, codename, team_number FROM mission_teams WHERE mission_id = ?", [$missionId]) as $t) {
    $teamLabels[(int) $t['id']] = $t['codename'] . ' ' . $t['team_number'];
}

$detail = [];
$orderDetailRows = dbFetchAll(
    "SELECT o.order_type, o.task_text, o.created_at AS sent_at, r.team_id, u.name AS user_name, r.acknowledged_at, r.fulfilled_at
     FROM mission_order_recipients r
     JOIN mission_orders o ON o.id = r.order_id
     JOIN users u ON u.id = r.user_id
     WHERE o.mission_id = ?
     ORDER BY o.created_at DESC",
    [$missionId]
);
foreach ($orderDetailRows as $row) {
    $teamId = $row['team_id'] ? (int) $row['team_id'] : null;
    $detail[] = [
        'type_label' => $typeMeta[$row['order_type']] ?? $row['order_type'],
        'team_label' => $teamId ? ($teamLabels[$teamId] ?? '—') : 'Χωρίς ομάδα',
        'user_name'  => $row['user_name'],
        'label'      => in_array($row['order_type'], ['task', 'message'], true) ? $row['task_text'] : null,
        'sent_at'    => $row['sent_at'], 'ack_at' => $row['acknowledged_at'], 'fulfill_at' => $row['fulfilled_at'],
    ];
}
$dispatchRows = dbFetchAll("SELECT id, label, created_at AS sent_at, team_id FROM mission_dispatch_points WHERE mission_id = ?", [$missionId]);
if (!empty($dispatchRows)) {
    $dispatchById = [];
    foreach ($dispatchRows as $d) { $dispatchById[(int) $d['id']] = $d; }
    $dispatchIds = array_keys($dispatchById);
    $placeholders = implode(',', array_fill(0, count($dispatchIds), '?'));
    $byDispatchUser = [];
    foreach (dbFetchAll("SELECT r.dispatch_id, r.user_id, r.team_id, r.created_at, u.name AS user_name FROM mission_dispatch_receipts r JOIN users u ON u.id = r.user_id WHERE r.dispatch_id IN ($placeholders)", $dispatchIds) as $r) {
        $key = $r['dispatch_id'] . ':' . $r['user_id'];
        $byDispatchUser[$key] = ['dispatch_id' => (int)$r['dispatch_id'], 'user_name' => $r['user_name'], 'team_id' => $r['team_id'] ? (int)$r['team_id'] : null, 'ack_at' => $r['created_at'], 'fulfill_at' => null];
    }
    foreach (dbFetchAll("SELECT a.dispatch_id, a.user_id, a.team_id, a.created_at, u.name AS user_name FROM mission_dispatch_acks a JOIN users u ON u.id = a.user_id WHERE a.dispatch_id IN ($placeholders)", $dispatchIds) as $a) {
        $key = $a['dispatch_id'] . ':' . $a['user_id'];
        if (!isset($byDispatchUser[$key])) {
            $byDispatchUser[$key] = ['dispatch_id' => (int)$a['dispatch_id'], 'user_name' => $a['user_name'], 'team_id' => $a['team_id'] ? (int)$a['team_id'] : null, 'ack_at' => null, 'fulfill_at' => null];
        }
        $byDispatchUser[$key]['fulfill_at'] = $a['created_at'];
        if (!$byDispatchUser[$key]['team_id'] && $a['team_id']) { $byDispatchUser[$key]['team_id'] = (int) $a['team_id']; }
    }
    foreach ($byDispatchUser as $entry) {
        $d = $dispatchById[$entry['dispatch_id']];
        $detail[] = [
            'type_label' => $typeMeta['dispatch'], 'team_label' => $entry['team_id'] ? ($teamLabels[$entry['team_id']] ?? '—') : 'Χωρίς ομάδα',
            'user_name' => $entry['user_name'], 'label' => $d['label'],
            'sent_at' => $d['sent_at'], 'ack_at' => $entry['ack_at'], 'fulfill_at' => $entry['fulfill_at'],
        ];
    }
}
foreach ($detail as &$row) {
    $row['ack_minutes'] = reportMinutesBetween($row['sent_at'], $row['ack_at']);
    $row['fulfill_minutes'] = reportMinutesBetween($row['sent_at'], $row['fulfill_at']);
}
unset($row);
usort($detail, fn($a, $b) => strtotime($b['sent_at']) <=> strtotime($a['sent_at']));

$byTeam = [];
foreach ($detail as $row) {
    $label = $row['team_label'];
    if (!isset($byTeam[$label])) { $byTeam[$label] = ['count' => 0, 'ack_count' => 0, 'fulfill_count' => 0, 'ack_sum' => 0.0, 'fulfill_sum' => 0.0]; }
    $byTeam[$label]['count']++;
    if ($row['ack_minutes'] !== null) { $byTeam[$label]['ack_count']++; $byTeam[$label]['ack_sum'] += $row['ack_minutes']; }
    if ($row['fulfill_minutes'] !== null) { $byTeam[$label]['fulfill_count']++; $byTeam[$label]['fulfill_sum'] += $row['fulfill_minutes']; }
}
$summary = [];
foreach ($byTeam as $label => $s) {
    $summary[] = [
        'team_label' => $label, 'order_count' => $s['count'],
        'ack_rate' => $s['count'] ? round($s['ack_count'] / $s['count'] * 100) : 0,
        'fulfill_rate' => $s['count'] ? round($s['fulfill_count'] / $s['count'] * 100) : 0,
        'avg_ack_minutes' => $s['ack_count'] ? round($s['ack_sum'] / $s['ack_count'], 1) : null,
        'avg_fulfill_minutes' => $s['fulfill_count'] ? round($s['fulfill_sum'] / $s['fulfill_count'], 1) : null,
    ];
}
usort($summary, fn($a, $b) => $b['order_count'] <=> $a['order_count']);
$detail = array_map(function ($row) {
    $row['sent_at'] = date('d/m/Y H:i', strtotime($row['sent_at']));
    $row['ack_at'] = $row['ack_at'] ? date('d/m/Y H:i', strtotime($row['ack_at'])) : null;
    $row['fulfill_at'] = $row['fulfill_at'] ? date('d/m/Y H:i', strtotime($row['fulfill_at'])) : null;
    return $row;
}, $detail);

$shortageRows = dbFetchAll(
    "SELECT r.shortage_type, r.severity, r.title, r.created_at AS sent_at, r.team_id, u.name AS user_name, r.acknowledged_at, r.resolved_at
     FROM mission_shortage_reports r JOIN users u ON u.id = r.reporter_id WHERE r.mission_id = ? ORDER BY r.created_at DESC",
    [$missionId]
);
$shortageDetail = [];
foreach ($shortageRows as $row) {
    $teamId = $row['team_id'] ? (int) $row['team_id'] : null;
    $shortageDetail[] = [
        'type_label' => SHORTAGE_TYPE_LABELS[$row['shortage_type']] ?? $row['shortage_type'],
        'severity' => $row['severity'],
        'severity_label' => SHORTAGE_SEVERITY_LABELS[$row['severity']] ?? $row['severity'],
        'team_label' => $teamId ? ($teamLabels[$teamId] ?? '—') : 'Χωρίς ομάδα',
        'reporter_name' => $row['user_name'], 'title' => $row['title'],
        'sent_at' => $row['sent_at'], 'seen_at' => $row['acknowledged_at'], 'resolved_at' => $row['resolved_at'],
    ];
}
foreach ($shortageDetail as &$row) {
    $row['seen_minutes'] = reportMinutesBetween($row['sent_at'], $row['seen_at']);
    $row['resolved_minutes'] = reportMinutesBetween($row['sent_at'], $row['resolved_at']);
}
unset($row);
usort($shortageDetail, fn($a, $b) => strtotime($b['sent_at']) <=> strtotime($a['sent_at']));
$shortageDetail = array_map(function ($row) {
    $row['sent_at'] = date('d/m/Y H:i', strtotime($row['sent_at']));
    $row['seen_at'] = $row['seen_at'] ? date('d/m/Y H:i', strtotime($row['seen_at'])) : null;
    $row['resolved_at'] = $row['resolved_at'] ? date('d/m/Y H:i', strtotime($row['resolved_at'])) : null;
    return $row;
}, $shortageDetail);

// ═══════════════════════════════════════════════════════════════════════════
// 3. Photos/videos — reused via the existing loader, not duplicated.
// ═══════════════════════════════════════════════════════════════════════════
$media = loadMissionPhotosForUser($missionId, $userId, true, 100000);
$photos = array_values(array_filter($media, fn($m) => $m['media_type'] === 'photo'));
$videos = array_values(array_filter($media, fn($m) => $m['media_type'] === 'video'));

$orgName = getSetting('org_name', 'VolunteerOps');
$printDate = date('d/m/Y H:i');
?><!DOCTYPE html>
<html lang="el">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Αναφορά Αποστολής - <?= h($mission['title']) ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: Arial, Helvetica, sans-serif; font-size: 10pt; color: #000; background: #fff; padding: 15mm 12mm; }

        .print-header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #333; padding-bottom: 8px; margin-bottom: 10px; }
        .print-header .org { font-size: 8.5pt; color: #555; }
        .print-header .title { font-size: 14pt; font-weight: bold; margin: 2px 0; }
        .print-header .meta { font-size: 8pt; color: #555; text-align: right; line-height: 1.6; }

        h2 { font-size: 12pt; margin: 16px 0 6px; border-bottom: 1px solid #ccc; padding-bottom: 3px; page-break-after: avoid; }
        h3 { font-size: 10pt; margin: 10px 0 4px; color: #333; }

        table { width: 100%; border-collapse: collapse; margin-top: 4px; }
        thead tr { background: #2c3e50; color: #fff; }
        thead th { padding: 4px 6px; text-align: left; font-size: 8pt; font-weight: bold; white-space: nowrap; }
        tbody tr { border-bottom: 1px solid #e0e0e0; }
        tbody tr:nth-child(even) { background: #f9f9f9; }
        tbody td { padding: 3px 6px; font-size: 8.5pt; vertical-align: middle; }

        .event-row { display: flex; justify-content: space-between; gap: 10px; padding: 2px 0; border-bottom: 1px solid #eee; font-size: 8.5pt; page-break-inside: avoid; }
        .event-time { color: #888; white-space: nowrap; font-size: 8pt; }

        .badge { display: inline-block; padding: 1px 6px; border-radius: 3px; font-size: 7.5pt; color: #fff; }
        .badge-secondary { background: #6c757d; } .badge-info { background: #0dcaf0; color: #000; }
        .badge-warning { background: #ffc107; color: #000; } .badge-danger { background: #dc3545; }

        .media-grid { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 6px; }
        .media-item { width: 140px; font-size: 7.5pt; text-align: center; page-break-inside: avoid; }
        .media-item img { width: 140px; height: 105px; object-fit: cover; border: 1px solid #ccc; display: block; }
        .video-list { font-size: 8.5pt; }
        .video-list li { margin-bottom: 3px; }

        .empty-note { color: #888; font-size: 8.5pt; padding: 4px 0; }

        .print-footer { margin-top: 16px; border-top: 1px solid #ccc; padding-top: 6px; font-size: 8pt; color: #888; display: flex; justify-content: space-between; }

        .screen-notice { position: fixed; top: 0; left: 0; right: 0; background: #17375e; color: #fff; text-align: center; padding: 8px 16px; font-size: 10pt; z-index: 9999; display: flex; align-items: center; justify-content: center; gap: 16px; }
        .screen-notice button { background: #fff; color: #17375e; border: none; padding: 4px 14px; border-radius: 4px; cursor: pointer; font-size: 9pt; font-weight: bold; }
        .screen-notice button:hover { background: #e0e8f5; }

        @media print {
            .screen-notice { display: none !important; }
            body { padding: 0; }
            @page { size: A4 portrait; margin: 14mm 12mm; }
        }
    </style>
</head>
<body>

<div class="screen-notice">
    <span>Προεπισκόπηση Εκτύπωσης &mdash; Αναφορά Αποστολής</span>
    <button onclick="window.print()">&#128438; Εκτύπωση / PDF</button>
    <button onclick="window.close()">&#10005; Κλείσιμο</button>
</div>

<div class="print-header" style="margin-top: 36px;">
    <div>
        <div class="org"><?= h($orgName) ?></div>
        <div class="title">&#128220; Αναφορά Αποστολής: <?= h($mission['title']) ?></div>
        <div style="font-size:8.5pt;color:#555;margin-top:2px;">
            <?= h($mission['location'] ?? '') ?>
            <?php if ($mission['department_name']): ?> · <?= h($mission['department_name']) ?><?php endif; ?>
            <?php if ($mission['responsible_name']): ?> · Υπεύθυνος: <?= h($mission['responsible_name']) ?><?php endif; ?>
        </div>
    </div>
    <div class="meta">
        <div>Ημ/νία εξαγωγής: <strong><?= $printDate ?></strong></div>
        <div>Κατάσταση: <strong><?= h(STATUS_LABELS[$mission['status']] ?? $mission['status']) ?></strong></div>
        <div><?= formatDateTime($mission['start_datetime']) ?> &ndash; <?= formatDateTime($mission['end_datetime']) ?></div>
    </div>
</div>

<h2>&#128203; Αναφορά Χρόνων Απόκρισης &mdash; Ανά Ομάδα</h2>
<?php if (empty($summary)): ?>
    <p class="empty-note">Δεν έχουν σταλεί εντολές σε αυτή την αποστολή.</p>
<?php else: ?>
<table>
    <thead><tr><th>Ομάδα</th><th>Εντολές</th><th>Ελήφθη</th><th>Ολοκληρώθηκε</th><th>Μέσος χρόνος αποδοχής</th><th>Μέσος χρόνος ολοκλήρωσης</th></tr></thead>
    <tbody>
        <?php foreach ($summary as $s): ?>
        <tr>
            <td><?= h($s['team_label']) ?></td>
            <td><?= $s['order_count'] ?></td>
            <td><?= $s['ack_rate'] ?>%</td>
            <td><?= $s['fulfill_rate'] ?>%</td>
            <td><?= $s['avg_ack_minutes'] !== null ? $s['avg_ack_minutes'] . ' λεπ.' : '—' ?></td>
            <td><?= $s['avg_fulfill_minutes'] !== null ? $s['avg_fulfill_minutes'] . ' λεπ.' : '—' ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<h3>Λεπτομέρειες Εντολών</h3>
<?php if (empty($detail)): ?>
    <p class="empty-note">Δεν υπάρχουν λεπτομέρειες.</p>
<?php else: ?>
    <?php foreach ($detail as $d): ?>
    <div class="event-row">
        <div><?= $d['type_label'] ?> <strong><?= h($d['team_label']) ?></strong> — <?= h($d['user_name']) ?><?= $d['label'] ? ' («' . h($d['label']) . '»)' : '' ?></div>
        <div class="event-time">Στάλθηκε <?= $d['sent_at'] ?> · Ελήφθη <?= $d['ack_at'] ? $d['ack_at'] . ' (' . $d['ack_minutes'] . ' λεπ.)' : '—' ?> · Ολοκληρώθηκε <?= $d['fulfill_at'] ? $d['fulfill_at'] . ' (' . $d['fulfill_minutes'] . ' λεπ.)' : '—' ?></div>
    </div>
    <?php endforeach; ?>
<?php endif; ?>

<h2>&#9888;&#65039; Αναφορές Έλλειψης &mdash; Ανά Σοβαρότητα</h2>
<?php if (empty($shortageDetail)): ?>
    <p class="empty-note">Δεν έχουν υποβληθεί αναφορές έλλειψης.</p>
<?php else: ?>
    <?php foreach ($shortageDetail as $d): ?>
    <div class="event-row">
        <div><span class="badge badge-<?= SHORTAGE_SEVERITY_COLORS[$d['severity']] ?? 'secondary' ?>"><?= h($d['severity_label']) ?></span> <?= h($d['type_label']) ?> <strong><?= h($d['team_label']) ?></strong> — <?= h($d['reporter_name']) ?> («<?= h($d['title']) ?>»)</div>
        <div class="event-time">Στάλθηκε <?= $d['sent_at'] ?> · Είδε <?= $d['seen_at'] ? $d['seen_at'] . ' (' . $d['seen_minutes'] . ' λεπ.)' : '—' ?> · Λύθηκε <?= $d['resolved_at'] ? $d['resolved_at'] . ' (' . $d['resolved_minutes'] . ' λεπ.)' : '—' ?></div>
    </div>
    <?php endforeach; ?>
<?php endif; ?>

<h2>&#128338; Δραστηριότητα</h2>
<?php if (empty($events)): ?>
    <p class="empty-note">Δεν υπάρχουν καταγεγραμμένα γεγονότα.</p>
<?php else: ?>
    <?php foreach ($events as $e): ?>
    <div class="event-row"><div><?= $e['icon'] ?> <?= $e['text'] ?></div><div class="event-time"><?= $e['time'] ?></div></div>
    <?php endforeach; ?>
<?php endif; ?>

<h2>&#128248; Φωτογραφίες Πεδίου</h2>
<?php if (empty($photos)): ?>
    <p class="empty-note">Δεν έχουν σταλεί φωτογραφίες.</p>
<?php else: ?>
<div class="media-grid">
    <?php foreach ($photos as $p): ?>
    <div class="media-item">
        <img src="mission-photo-view.php?id=<?= $p['id'] ?>" alt="">
        <div><?= h($p['user_name']) ?> · <?= h($p['time']) ?></div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<h2>&#127909; Βίντεο Πεδίου</h2>
<?php if (empty($videos)): ?>
    <p class="empty-note">Δεν έχουν σταλεί βίντεο.</p>
<?php else: ?>
<ul class="video-list">
    <?php foreach ($videos as $v): ?>
    <li>&#127909; <?= h($v['user_name']) ?> · <?= h($v['time']) ?> <span style="color:#888;">(δεν ενσωματώνεται σε έντυπη μορφή)</span></li>
    <?php endforeach; ?>
</ul>
<?php endif; ?>

<div class="print-footer">
    <span><?= h($orgName) ?> &mdash; Αναφορά Αποστολής</span>
    <span>Εξήχθη: <?= $printDate ?></span>
</div>

<script>
    window.addEventListener('load', function () {
        setTimeout(function () { window.print(); }, 400);
    });
</script>
</body>
</html>
