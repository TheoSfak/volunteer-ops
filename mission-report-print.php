<?php
/**
 * VolunteerOps - Mission Report Print View
 * War Room: archival export combining the Activity feed, the response-time
 * report (orders + shortage reports), the computed mission score/leaderboard,
 * team roster, recap map and field photos/videos into one printable document
 * — meant to be generated *after* a mission closes, for a unit's own records.
 * Own <!DOCTYPE html>, no shared header/footer, @media print styling, browser
 * print-to-PDF instead of a server-side PDF library — this app has none.
 * Unlike inventory-print.php's plain table export, this page pulls in
 * Chart.js + Leaflet (neither loaded automatically here since there's no
 * shared header.php) to mirror mission-stats.php's visual language for an
 * actual keepable/shareable document, not just a stripped-down table dump.
 *
 * Deliberately does NOT gate on mission status/show_in_ops like every other
 * War Room endpoint does — those gates exist to lock down the *live-ops*
 * tools once a mission closes, which is correct for them but would make an
 * *archival* export unusable for the exact moment it's meant to be used.
 * Only the permission check matters here. mission-photo-view.php's own gate
 * was loosened (OPEN or CLOSED) to match, so embedded photos keep working.
 *
 * Activity/response-time/score data comes from shared includes/functions.php
 * helpers (loadMissionActivityEventsForReport(), computeMissionResponseReport(),
 * computeMissionScore()) rather than a proxy through mission-history.php/
 * mission-response-report.php/mission-stats.php — those endpoints inherit the
 * STATUS_OPEN gate (or, for mission-stats.php, its own score-validation POST
 * handler) this page must not have. Photos/videos are reused as-is via
 * loadMissionPhotosForUser(), a plain function call with no HTTP-level gate
 * of its own.
 *
 * Greek-only by the same deliberate, repeatedly-reconfirmed precedent as
 * mission-stats.php — computeMissionResponseReport()'s $lang param defaults
 * to 'el' specifically so this file's output stays byte-stable regardless of
 * the viewer's own language setting. New content added here follows suit:
 * plain Greek literals, no t() calls.
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

// Attendance reconciliation only happens once a mission is closed (attendance.php),
// same gate mission-stats.php already applies to its volunteer-hours tile —
// needed here too now that the roster shows per-volunteer hours.
$attendanceReady = in_array($mission['status'], [STATUS_CLOSED, STATUS_COMPLETED], true);

// ═══════════════════════════════════════════════════════════════════════════
// 1. Activity feed — shared with mission-stats.php via
//    loadMissionActivityEventsForReport() in includes/functions.php (the
//    same 7 sources as mission-history.php's live feed, but unconditionally
//    admin-scoped since this whole page already is, and uncapped since this
//    is an archival document, not the bounded live UI).
// ═══════════════════════════════════════════════════════════════════════════
$events = loadMissionActivityEventsForReport($missionId);
foreach ($events as &$e) {
    $e['time'] = date('d/m/Y H:i', $e['ts']);
}
unset($e);

$timelineBuckets = [];
foreach ($events as $e) {
    $bucket = date('Y-m-d H:00', $e['ts']);
    $timelineBuckets[$bucket] = ($timelineBuckets[$bucket] ?? 0) + 1;
}
ksort($timelineBuckets);
$timelineLabels = array_map(fn($k) => date('d/m H:i', strtotime($k)), array_keys($timelineBuckets));
$timelineData = array_values($timelineBuckets);

// ═══════════════════════════════════════════════════════════════════════════
// 2. Response-time report + mission score — shared with mission-stats.php /
//    mission-response-report.php via computeMissionResponseReport(); score
//    via computeMissionScore(), passed the same $report to avoid a duplicate
//    query. This page applies its own d/m/Y H:i (with year) date format,
//    unlike the live report's compact d/m H:i.
// ═══════════════════════════════════════════════════════════════════════════
$report = computeMissionResponseReport($missionId);
$score = computeMissionScore($missionId, $report);
$scoreReview = dbFetchOne(
    "SELECT r.*, u.name AS validator_name FROM mission_score_reviews r
     JOIN users u ON u.id = r.validated_by WHERE r.mission_id = ?",
    [$missionId]
);
$scoreTierHex = ['good' => '#0ca30c', 'warning' => '#a56600', 'critical' => '#d03b3b'];

// Per-order-type breakdown (ack vs fulfill, same unit/scale) — computed from
// $report['detail'] (raw datetimes/minutes) BEFORE the display-formatting
// array_map below reassigns the local $detail; array_map returns a new array
// so $report['detail'] itself is untouched either way, but computing this
// first keeps the numeric-vs-display distinction obvious while reading.
$byOrderType = [];
foreach ($report['detail'] as $row) {
    $t = $row['order_type'];
    if (!isset($byOrderType[$t])) {
        $byOrderType[$t] = ['label' => $row['type_label'], 'ack_count' => 0, 'ack_sum' => 0.0, 'fulfill_count' => 0, 'fulfill_sum' => 0.0, 'count' => 0];
    }
    $byOrderType[$t]['count']++;
    if ($row['ack_minutes'] !== null) { $byOrderType[$t]['ack_count']++; $byOrderType[$t]['ack_sum'] += $row['ack_minutes']; }
    if ($row['fulfill_minutes'] !== null) { $byOrderType[$t]['fulfill_count']++; $byOrderType[$t]['fulfill_sum'] += $row['fulfill_minutes']; }
}
$orderTypeLabels = [];
$orderTypeCounts = [];
$orderTypeAvgAck = [];
$orderTypeAvgFulfill = [];
foreach ($byOrderType as $s) {
    $orderTypeLabels[] = $s['label'];
    $orderTypeCounts[] = $s['count'];
    $orderTypeAvgAck[] = $s['ack_count'] ? round($s['ack_sum'] / $s['ack_count'], 1) : 0;
    $orderTypeAvgFulfill[] = $s['fulfill_count'] ? round($s['fulfill_sum'] / $s['fulfill_count'], 1) : 0;
}

$summary = $report['summary'];
$shortageSummary = $report['shortageSummary'];
$detail = array_map(function ($row) {
    $row['sent_at'] = date('d/m/Y H:i', strtotime($row['sent_at']));
    $row['ack_at'] = $row['ack_at'] ? date('d/m/Y H:i', strtotime($row['ack_at'])) : null;
    $row['fulfill_at'] = $row['fulfill_at'] ? date('d/m/Y H:i', strtotime($row['fulfill_at'])) : null;
    return $row;
}, $report['detail']);
$shortageDetail = array_map(function ($row) {
    $row['sent_at'] = date('d/m/Y H:i', strtotime($row['sent_at']));
    $row['seen_at'] = $row['seen_at'] ? date('d/m/Y H:i', strtotime($row['seen_at'])) : null;
    $row['resolved_at'] = $row['resolved_at'] ? date('d/m/Y H:i', strtotime($row['resolved_at'])) : null;
    return $row;
}, $report['shortageDetail']);

$totalOrders = count($report['detail']);
$ackRows = array_filter($report['detail'], fn($d) => $d['ack_minutes'] !== null);
$fulfillCount = count(array_filter($report['detail'], fn($d) => $d['fulfill_minutes'] !== null));
$avgAckMinutes = count($ackRows) ? round(array_sum(array_column($ackRows, 'ack_minutes')) / count($ackRows), 1) : null;
$fulfillRate = $totalOrders ? round($fulfillCount / $totalOrders * 100) : 0;

$totalShortage = count($report['shortageDetail']);
$resolvedShortage = count(array_filter($report['shortageDetail'], fn($d) => $d['resolved_at'] !== null));
$resolvedShortageRate = $totalShortage ? round($resolvedShortage / $totalShortage * 100) : 0;

// ═══════════════════════════════════════════════════════════════════════════
// 3. Debrief narrative — same table mission-stats.php already reads.
// ═══════════════════════════════════════════════════════════════════════════
$debrief = dbFetchOne("SELECT * FROM mission_debriefs WHERE mission_id = ?", [$missionId]);

// ═══════════════════════════════════════════════════════════════════════════
// 4. Team roster — mirrors war-room.php's team query (leader/members),
//    extended with a fan-out-safe pre-aggregated hours subquery: a volunteer
//    can hold >1 shift row in one mission (participation_requests is unique
//    per shift, not per mission), so hours are summed per-volunteer before
//    the join rather than after, or a raw join would duplicate/overcount.
// ═══════════════════════════════════════════════════════════════════════════
$rosterRows = dbFetchAll(
    "SELECT mt.id AS team_id, mt.codename, mt.team_number, mt.color,
            mt.leader_id, l.name AS leader_name, l.is_external AS leader_is_external, l.guest_org_name AS leader_guest_org_name,
            mtm.user_id, u.name AS member_name, u.is_external AS member_is_external, u.guest_org_name AS member_guest_org_name,
            COALESCE(hrs.hours, 0) AS member_hours
     FROM mission_teams mt
     LEFT JOIN users l ON l.id = mt.leader_id
     LEFT JOIN mission_team_members mtm ON mtm.team_id = mt.id
     LEFT JOIN users u ON u.id = mtm.user_id
     LEFT JOIN (
         SELECT pr.volunteer_id, COALESCE(SUM(pr.actual_hours), 0) AS hours
         FROM participation_requests pr JOIN shifts s ON s.id = pr.shift_id
         WHERE s.mission_id = ? AND pr.attended = 1
         GROUP BY pr.volunteer_id
     ) hrs ON hrs.volunteer_id = mtm.user_id
     WHERE mt.mission_id = ?
     ORDER BY mt.created_at, u.name",
    [$missionId, $missionId]
);
$roster = [];
foreach ($rosterRows as $row) {
    $tid = (int) $row['team_id'];
    if (!isset($roster[$tid])) {
        $roster[$tid] = [
            'codename' => $row['codename'], 'team_number' => $row['team_number'], 'color' => $row['color'] ?: '#898781',
            'leader_name' => $row['leader_name'],
            'leader_is_external' => (bool) $row['leader_is_external'], 'leader_guest_org_name' => $row['leader_guest_org_name'],
            'members' => [],
        ];
    }
    if ($row['user_id'] !== null) {
        $roster[$tid]['members'][] = [
            'name' => $row['member_name'], 'is_external' => (bool) $row['member_is_external'],
            'guest_org_name' => $row['member_guest_org_name'], 'hours' => (float) $row['member_hours'],
        ];
    }
}
$teamCount = count($roster);
$teamColorByLabel = [];
foreach ($roster as $team) {
    $teamColorByLabel[$team['codename'] . ' ' . $team['team_number']] = $team['color'];
}

// ═══════════════════════════════════════════════════════════════════════════
// 5. Headline KPIs — same tiles/data mission-stats.php already computes.
// ═══════════════════════════════════════════════════════════════════════════
$approvedCount = (int) dbFetchValue(
    "SELECT COUNT(DISTINCT pr.volunteer_id) FROM participation_requests pr
     JOIN shifts s ON s.id = pr.shift_id
     WHERE s.mission_id = ? AND pr.status = ?",
    [$missionId, PARTICIPATION_APPROVED]
);
$volunteerHours = $attendanceReady ? (float) dbFetchValue(
    "SELECT COALESCE(SUM(pr.actual_hours), 0) FROM participation_requests pr
     JOIN shifts s ON s.id = pr.shift_id
     WHERE s.mission_id = ? AND pr.attended = 1",
    [$missionId]
) : null;
$pingCount = (int) dbFetchValue(
    "SELECT COUNT(*) FROM volunteer_pings vp JOIN shifts s ON s.id = vp.shift_id WHERE s.mission_id = ? AND vp.source = 'manual'",
    [$missionId]
);
$chatCount = (int) dbFetchValue("SELECT COUNT(*) FROM mission_chat_messages WHERE mission_id = ?", [$missionId]);

// ═══════════════════════════════════════════════════════════════════════════
// 6. Photos/videos — reused via the existing loader, not duplicated.
// ═══════════════════════════════════════════════════════════════════════════
$media = loadMissionPhotosForUser($missionId, $userId, true, 100000);
$photos = array_values(array_filter($media, fn($m) => $m['media_type'] === 'photo'));
$videos = array_values(array_filter($media, fn($m) => $m['media_type'] === 'video'));

// ═══════════════════════════════════════════════════════════════════════════
// 7. Recap map data — same three sources mission-stats.php's map uses.
// ═══════════════════════════════════════════════════════════════════════════
$lastPings = dbFetchAll(
    "SELECT vp.lat, vp.lng, vp.created_at, u.name
     FROM volunteer_pings vp
     JOIN shifts s ON s.id = vp.shift_id
     JOIN users u ON u.id = vp.user_id
     WHERE s.mission_id = ?
       AND vp.id = (SELECT MAX(vp2.id) FROM volunteer_pings vp2 JOIN shifts s2 ON s2.id = vp2.shift_id WHERE s2.mission_id = ? AND vp2.user_id = vp.user_id)",
    [$missionId, $missionId]
);
$dispatchGeo = dbFetchAll("SELECT type, geo, label FROM mission_dispatch_points WHERE mission_id = ?", [$missionId]);
$photoPoints = array_values(array_filter($media, fn($m) => $m['lat'] !== null));

// Expected canvas/map-tile "ready" signals for the auto-print gate — mirrors
// each block's own conditional rendering below exactly, so the JS counter
// only waits for elements that will actually exist on the page.
$expectedReady = 0;
if ($score['overall'] !== null) $expectedReady++; // gauge
if (!empty($timelineData)) $expectedReady++; // timeline
if (!empty($summary)) $expectedReady += 2; // teamCount + teamAck
if (!empty($orderTypeLabels)) $expectedReady += 2; // orderType pie + responseDetail bar
if (!empty($shortageSummary)) $expectedReady++; // shortageSeverity pie
if (!empty($lastPings) || !empty($dispatchGeo) || !empty($photoPoints)) $expectedReady++; // map tiles

$orgName = getSetting('org_name', 'VolunteerOps');
$appLogo = getSetting('app_logo', '');
$hasLogo = !empty($appLogo) && file_exists(__DIR__ . '/uploads/logos/' . $appLogo);
$printDate = date('d/m/Y H:i');
?><!DOCTYPE html>
<html lang="el">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Αναφορά Αποστολής - <?= h($mission['title']) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', Arial, Helvetica, sans-serif; font-size: 10pt; color: #1a1a1a; background: #f4f4f2; padding: 15mm 12mm; -webkit-print-color-adjust: exact; print-color-adjust: exact; }

        .pr-hero { background: linear-gradient(135deg, #172554, #b91c1c); color: #fff; border-radius: 16px; padding: 26px 28px; margin-top: 36px; margin-bottom: 16px; page-break-inside: avoid; }
        .pr-hero-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; flex-wrap: wrap; }
        .pr-hero .org { font-size: 9pt; opacity: .85; display: flex; align-items: center; gap: 8px; }
        .pr-hero .org img { height: 26px; width: auto; border-radius: 5px; }
        .pr-hero .title { font-size: 19pt; font-weight: 800; margin: 6px 0 4px; }
        .pr-hero .subtitle { font-size: 9.5pt; opacity: .9; }
        .pr-hero .meta { text-align: right; font-size: 8.5pt; opacity: .92; line-height: 1.7; }
        .pr-hero .status-badge { display: inline-block; background: rgba(255,255,255,.18); border: 1px solid rgba(255,255,255,.35); padding: 3px 12px; border-radius: 999px; font-weight: 700; font-size: 8.5pt; }

        .pr-card { background: #fff; border: 1px solid #eee; border-radius: 14px; padding: 18px 20px; margin-bottom: 14px; page-break-inside: avoid; }
        .pr-card h2 { font-size: 13pt; font-weight: 800; margin-bottom: 12px; display: flex; align-items: center; gap: 8px; color: #172554; }
        .pr-card h3 { font-size: 10pt; font-weight: 700; margin: 10px 0 6px; color: #333; }
        .pr-empty { color: #898781; font-size: 9pt; padding: 8px 0; }

        .score-gauge-wrap { position: relative; width: 168px; height: 168px; margin: 0 auto; flex-shrink: 0; }
        .score-gauge-center { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; }
        .score-gauge-center .value { font-size: 2rem; font-weight: 800; line-height: 1; }
        .score-gauge-center .tier-label { font-size: 7.8pt; font-weight: 600; margin-top: 3px; color: #52514e; }
        .score-pillar-row { display: flex; align-items: center; gap: 10px; margin-bottom: 8px; }
        .score-pillar-label { width: 150px; flex-shrink: 0; font-size: 8.5pt; font-weight: 600; color: #52514e; }
        .score-pillar-track { flex: 1; height: 11px; background: #eee; border-radius: 999px; overflow: hidden; }
        .score-pillar-fill { height: 100%; border-radius: 999px; }
        .score-pillar-value { width: 38px; flex-shrink: 0; text-align: right; font-weight: 700; font-size: 8.5pt; }
        .score-validation-line { margin-top: 14px; padding-top: 12px; border-top: 1px solid #eee; font-size: 8.5pt; color: #444; }
        .score-validation-line .validated { color: #0ca30c; font-weight: 700; }
        .score-validation-line .pending { color: #a56600; font-weight: 700; }

        .lb-row { display: flex; align-items: center; gap: 10px; padding: 7px 12px; border-radius: 8px; background: #f9f9f7; margin-bottom: 6px; border-left: 4px solid; page-break-inside: avoid; }
        .lb-rank { font-size: 1.05rem; width: 26px; text-align: center; flex-shrink: 0; }
        .lb-team-badge { padding: 2px 10px; border-radius: 999px; color: #fff; font-weight: 700; font-size: 7.8pt; white-space: nowrap; }
        .lb-tier { flex: 1; font-size: 8.3pt; color: #555; }
        .lb-score { font-weight: 800; font-size: 10.5pt; }

        .pr-tiles { display: grid; grid-template-columns: repeat(5, 1fr); gap: 8px; margin-bottom: 14px; }
        .pr-tile { background: #fff; border: 1px solid #eee; border-radius: 12px; padding: 10px 6px; text-align: center; page-break-inside: avoid; }
        .pr-tile .v { font-size: 1.35rem; font-weight: 800; color: #172554; line-height: 1.1; }
        .pr-tile .l { font-size: 6.8pt; color: #52514e; text-transform: uppercase; letter-spacing: .02em; margin-top: 3px; }

        .pr-chart-wrap { position: relative; height: 220px; }
        .pr-chart-wrap.tall { height: 250px; }
        .pr-chart-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }

        .roster-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .roster-team-card { border-radius: 10px; border: 1px solid #eee; padding: 10px 14px; page-break-inside: avoid; }
        .roster-team-header { display: flex; align-items: center; gap: 6px; margin-bottom: 6px; font-weight: 700; font-size: 9.5pt; flex-wrap: wrap; }
        .roster-swatch { width: 11px; height: 11px; border-radius: 3px; flex-shrink: 0; }
        .roster-member-row { display: flex; justify-content: space-between; padding: 3px 0; font-size: 8.3pt; border-bottom: 1px solid #f2f2f0; }
        .roster-member-row:last-child { border-bottom: none; }

        #printMap { height: 330px; border-radius: 10px; }

        table { width: 100%; border-collapse: collapse; margin-top: 4px; }
        thead tr { background: #172554; color: #fff; }
        thead th { padding: 5px 7px; text-align: left; font-size: 8pt; font-weight: bold; white-space: nowrap; }
        tbody tr { border-bottom: 1px solid #e0e0e0; }
        tbody tr:nth-child(even) { background: #f9f9f9; }
        tbody td { padding: 4px 7px; font-size: 8.5pt; vertical-align: middle; }

        .event-row { display: flex; justify-content: space-between; gap: 10px; padding: 3px 0; border-bottom: 1px solid #eee; font-size: 8.5pt; page-break-inside: avoid; }
        .event-time { color: #888; white-space: nowrap; font-size: 8pt; }

        .badge { display: inline-block; padding: 1px 7px; border-radius: 999px; font-size: 7.5pt; color: #fff; font-weight: 600; }
        .badge-secondary { background: #6c757d; } .badge-info { background: #0dcaf0; color: #000; }
        .badge-warning { background: #ffc107; color: #000; } .badge-danger { background: #dc3545; }

        .media-grid { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 6px; }
        .media-item { width: 140px; font-size: 7.5pt; text-align: center; page-break-inside: avoid; }
        .media-item img { width: 140px; height: 105px; object-fit: cover; border: 1px solid #ccc; border-radius: 6px; display: block; }
        .video-list { font-size: 8.5pt; }
        .video-list li { margin-bottom: 3px; }

        .pr-quote { border-left: 4px solid #2a78d6; background: #f9f9f7; border-radius: 0 10px 10px 0; padding: 12px 16px; margin-bottom: 12px; page-break-inside: avoid; }
        .pr-quote h6 { font-weight: 700; margin-bottom: 5px; font-size: 9pt; display: flex; align-items: center; gap: 6px; }
        .pr-quote.incidents { border-left-color: #d03b3b; }
        .pr-quote.equipment { border-left-color: #eda100; }

        .pr-footer { margin-top: 16px; border-top: 1px solid #ccc; padding-top: 6px; font-size: 8pt; color: #888; display: flex; justify-content: space-between; }

        .screen-notice { position: fixed; top: 0; left: 0; right: 0; background: #17375e; color: #fff; text-align: center; padding: 8px 16px; font-size: 10pt; z-index: 9999; display: flex; align-items: center; justify-content: center; gap: 16px; flex-wrap: wrap; }
        .screen-notice button { background: #fff; color: #17375e; border: none; padding: 4px 14px; border-radius: 4px; cursor: pointer; font-size: 9pt; font-weight: bold; }
        .screen-notice button:hover { background: #e0e8f5; }
        .screen-notice .hint { font-size: 8pt; opacity: .8; }

        @media print {
            .screen-notice { display: none !important; }
            body { padding: 0; background: #fff; }
            @page { size: A4 portrait; margin: 14mm 12mm; }
            .pr-hero { margin-top: 0; }
        }
    </style>
</head>
<body>

<div class="screen-notice">
    <span>Προεπισκόπηση Εκτύπωσης &mdash; Αναφορά Αποστολής</span>
    <button onclick="window.print()">&#128438; Εκτύπωση / PDF</button>
    <button onclick="window.close()">&#10005; Κλείσιμο</button>
    <span class="hint">Η εκτύπωση ξεκινά αυτόματα μόλις φορτώσουν τα γραφήματα &amp; ο χάρτης</span>
</div>

<div class="pr-hero">
    <div class="pr-hero-top">
        <div>
            <div class="org">
                <?php if ($hasLogo): ?><img src="uploads/logos/<?= h($appLogo) ?>" alt=""><?php endif; ?>
                <span><?= h($orgName) ?></span>
            </div>
            <div class="title">📋 Αναφορά Αποστολής: <?= h($mission['title']) ?></div>
            <div class="subtitle">
                <?= h($mission['location'] ?? '') ?>
                <?php if ($mission['department_name']): ?> · <?= h($mission['department_name']) ?><?php endif; ?>
                <?php if ($mission['responsible_name']): ?> · Υπεύθυνος: <?= h($mission['responsible_name']) ?><?php endif; ?>
            </div>
        </div>
        <div class="meta">
            <div class="status-badge"><?= h(STATUS_LABELS[$mission['status']] ?? $mission['status']) ?></div>
            <div style="margin-top:8px;"><?= formatDateTime($mission['start_datetime']) ?> &ndash; <?= formatDateTime($mission['end_datetime']) ?></div>
            <div>Ημ/νία εξαγωγής: <?= $printDate ?></div>
        </div>
    </div>
</div>

<!-- Mission score -->
<div class="pr-card">
    <h2>🏅 Βαθμολογία Άσκησης</h2>
    <?php if ($score['overall'] === null): ?>
        <p class="pr-empty">Δεν υπάρχουν αρκετά δεδομένα (εντολές, βάρδιες ή αναφορά debrief) για τον υπολογισμό βαθμολογίας.</p>
    <?php else: ?>
    <div style="display:flex; gap:24px; flex-wrap:wrap; align-items:center;">
        <div class="score-gauge-wrap">
            <canvas id="scoreGaugeChart"></canvas>
            <div class="score-gauge-center">
                <div class="value" style="color:<?= $scoreTierHex[$score['tier'][0]] ?>;"><?= number_format($score['overall'], 1) ?></div>
                <div class="tier-label"><?= h($score['tier'][1]) ?></div>
            </div>
        </div>
        <div style="flex:1; min-width:260px;">
            <?php foreach ($score['pillars'] as $p): ?>
                <div class="score-pillar-row">
                    <div class="score-pillar-label"><?= h($p['label']) ?></div>
                    <?php if ($p['available']): $pTier = missionScoreTierMeta($p['score']); ?>
                    <div class="score-pillar-track"><div class="score-pillar-fill" style="width:<?= round($p['score']) ?>%;background:<?= $scoreTierHex[$pTier[0]] ?>;"></div></div>
                    <div class="score-pillar-value"><?= number_format($p['score'], 0) ?></div>
                    <?php else: ?>
                    <div class="score-pillar-track"><div class="score-pillar-fill" style="width:0;"></div></div>
                    <div class="score-pillar-value" style="color:#aaa;">—</div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="score-validation-line">
        <?php if ($scoreReview): ?>
            <span class="validated">✓ Επικυρώθηκε</span> από <?= h($scoreReview['validator_name']) ?> στις <?= formatDateTime($scoreReview['validated_at']) ?> — Τελική βαθμολογία: <strong><?= number_format($scoreReview['final_score'], 1) ?></strong>
            <?php if (!empty($scoreReview['verdict_note'])): ?><div style="margin-top:6px; font-style:italic;">&laquo;<?= nl2br(h($scoreReview['verdict_note'])) ?>&raquo;</div><?php endif; ?>
        <?php else: ?>
            <span class="pending">⏳ Εκκρεμεί επικύρωση</span> — αυτόματη βαθμολόγηση, δεν έχει επικυρωθεί ακόμα από υπεύθυνο. (Επικύρωση από τη σελίδα Στατιστικών.)
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<?php if (!empty($score['teams'])): ?>
<div class="pr-card">
    <h2>🏆 Κατάταξη Ομάδων</h2>
    <?php foreach ($score['teams'] as $t): ?>
        <div class="lb-row" style="border-left-color:<?= h($t['color']) ?>;">
            <div class="lb-rank"><?= $t['rank'] === 1 ? '🥇' : ($t['rank'] === 2 ? '🥈' : ($t['rank'] === 3 ? '🥉' : $t['rank'])) ?></div>
            <span class="lb-team-badge" style="background:<?= h($t['color']) ?>;"><?= h($t['codename'] . ' ' . $t['team_number']) ?></span>
            <div class="lb-tier"><?= h($t['tier'][1]) ?></div>
            <div class="lb-score" style="color:<?= $scoreTierHex[$t['tier'][0]] ?>;"><?= number_format($t['score'], 1) ?></div>
        </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Headline KPIs -->
<div class="pr-tiles">
    <div class="pr-tile"><div class="v"><?= $attendanceReady ? number_format($volunteerHours, 1) : '—' ?></div><div class="l">Ώρες Εθελοντισμού</div></div>
    <div class="pr-tile"><div class="v"><?= $approvedCount ?></div><div class="l">Εγκεκριμένοι Εθελοντές</div></div>
    <div class="pr-tile"><div class="v"><?= $teamCount ?></div><div class="l">Ομάδες</div></div>
    <div class="pr-tile"><div class="v"><?= $avgAckMinutes !== null ? number_format($avgAckMinutes, 1) : '—' ?></div><div class="l">Μ.Ο. Απόκρισης (λεπ.)</div></div>
    <div class="pr-tile"><div class="v"><?= $totalOrders ? $fulfillRate . '%' : '—' ?></div><div class="l">Ολοκλήρωση Εντολών</div></div>
    <div class="pr-tile"><div class="v"><?= $totalShortage ?></div><div class="l">Αναφορές Έλλειψης</div></div>
    <div class="pr-tile"><div class="v"><?= count($photos) + count($videos) ?></div><div class="l">Φωτό / Βίντεο</div></div>
    <div class="pr-tile"><div class="v"><?= $pingCount ?></div><div class="l">GPS Στίγματα</div></div>
    <div class="pr-tile"><div class="v"><?= $chatCount ?></div><div class="l">Μηνύματα</div></div>
</div>

<!-- Activity over time -->
<div class="pr-card">
    <h2>📈 Δραστηριότητα στον Χρόνο</h2>
    <?php if (empty($timelineData)): ?>
        <p class="pr-empty">Δεν υπάρχουν καταγεγραμμένα γεγονότα.</p>
    <?php else: ?>
        <div class="pr-chart-wrap tall"><canvas id="timelineChart"></canvas></div>
    <?php endif; ?>
</div>

<?php if (!empty($summary)): ?>
<div class="pr-card">
    <h2>📊 Εντολές &amp; Χρόνος Απόκρισης ανά Ομάδα</h2>
    <div class="pr-chart-grid">
        <div class="pr-chart-wrap"><canvas id="teamCountChart"></canvas></div>
        <div class="pr-chart-wrap"><canvas id="teamAckChart"></canvas></div>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($orderTypeLabels) || !empty($shortageSummary)): ?>
<div class="pr-card">
    <h2>🥧 Κατανομή Εντολών &amp; Ελλείψεων</h2>
    <div class="pr-chart-grid">
        <div>
            <h3>Εντολές ανά Τύπο</h3>
            <?php if (empty($orderTypeLabels)): ?><p class="pr-empty">Δεν έχουν σταλεί εντολές.</p><?php else: ?>
            <div class="pr-chart-wrap"><canvas id="orderTypeChart"></canvas></div>
            <?php endif; ?>
        </div>
        <div>
            <h3>Ελλείψεις ανά Σοβαρότητα</h3>
            <?php if (empty($shortageSummary)): ?><p class="pr-empty">Δεν έχουν υποβληθεί αναφορές έλλειψης.</p><?php else: ?>
            <div class="pr-chart-wrap"><canvas id="shortageSeverityChart"></canvas></div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($orderTypeLabels)): ?>
<div class="pr-card">
    <h2>⏱️ Χρόνοι Απόκρισης ανά Τύπο Εντολής</h2>
    <div class="pr-chart-wrap tall"><canvas id="responseDetailChart"></canvas></div>
</div>
<?php endif; ?>

<!-- Team roster -->
<div class="pr-card">
    <h2>👥 Ρόστερ Ομάδων</h2>
    <?php if (empty($roster)): ?>
        <p class="pr-empty">Δεν έχουν δημιουργηθεί ομάδες.</p>
    <?php else: ?>
    <div class="roster-grid">
        <?php foreach ($roster as $team): ?>
        <div class="roster-team-card">
            <div class="roster-team-header">
                <span class="roster-swatch" style="background:<?= h($team['color']) ?>;"></span>
                <span><?= h($team['codename'] . ' ' . $team['team_number']) ?></span>
                <?php if ($team['leader_name']): ?><span style="color:#888; font-weight:400; font-size:8pt;">&middot; Υπεύθυνος: <?= guestNameHtml($team['leader_name'], $team['leader_is_external'], $team['leader_guest_org_name']) ?></span><?php endif; ?>
            </div>
            <?php if (empty($team['members'])): ?>
                <div style="color:#888; font-size:8.3pt;">Χωρίς μέλη.</div>
            <?php else: ?>
                <?php foreach ($team['members'] as $m): ?>
                <div class="roster-member-row">
                    <span><?= guestNameHtml($m['name'], $m['is_external'], $m['guest_org_name']) ?></span>
                    <span style="color:#888;"><?= $attendanceReady ? number_format($m['hours'], 1) . ' ώρες' : 'Εκκρεμεί' ?></span>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Recap map -->
<div class="pr-card">
    <h2>🗺️ Χάρτης Δραστηριότητας</h2>
    <?php if (empty($lastPings) && empty($dispatchGeo) && empty($photoPoints)): ?>
        <p class="pr-empty">Δεν υπάρχουν καταγεγραμμένα σημεία στον χάρτη.</p>
    <?php else: ?>
        <div id="printMap"></div>
        <div style="display:flex; gap:16px; flex-wrap:wrap; font-size:8pt; margin-top:8px; color:#555;">
            <span>🔵 Τελευταίο στίγμα εθελοντή</span>
            <span>🟣 Σημείο/Περιοχή αποστολής</span>
            <span>🟢 Φωτογραφία/Βίντεο</span>
        </div>
    <?php endif; ?>
</div>

<!-- Debrief narrative -->
<?php if ($debrief): ?>
<div class="pr-card">
    <h2>📔 Αναφορά Debrief</h2>
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; flex-wrap:wrap; gap:8px;">
        <div style="color:#fab219; font-size:1.1rem; letter-spacing:2px;"><?= str_repeat('★', (int)$debrief['rating']) . str_repeat('☆', 5 - (int)$debrief['rating']) ?></div>
        <?php
            $objMeta = [
                'YES'     => ['#0ca30c', 'rgba(12,163,12,.12)', 'Στόχοι επιτεύχθηκαν'],
                'PARTIAL' => ['#a56600', 'rgba(250,178,25,.16)', 'Μερική επίτευξη στόχων'],
                'NO'      => ['#d03b3b', 'rgba(208,59,59,.12)', 'Στόχοι δεν επιτεύχθηκαν'],
            ];
            $om = $objMeta[$debrief['objectives_met']] ?? $objMeta['PARTIAL'];
        ?>
        <span style="display:inline-block; padding:4px 12px; border-radius:999px; font-weight:700; font-size:8.5pt; color:<?= $om[0] ?>; background:<?= $om[1] ?>;"><?= $om[2] ?></span>
    </div>
    <div class="pr-quote">
        <h6>📝 Σύνοψη</h6>
        <div><?= nl2br(h($debrief['summary'])) ?></div>
    </div>
    <?php if (!empty($debrief['incidents'])): ?>
    <div class="pr-quote incidents">
        <h6>⚠️ Συμβάντα / Ατυχήματα</h6>
        <div><?= nl2br(h($debrief['incidents'])) ?></div>
    </div>
    <?php endif; ?>
    <?php if (!empty($debrief['equipment_issues'])): ?>
    <div class="pr-quote equipment">
        <h6>🛠️ Προβλήματα Εξοπλισμού</h6>
        <div><?= nl2br(h($debrief['equipment_issues'])) ?></div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Response-time detail (preserved in full) -->
<div class="pr-card">
    <h2>📋 Αναφορά Χρόνων Απόκρισης &mdash; Ανά Ομάδα</h2>
    <?php if (empty($summary)): ?>
        <p class="pr-empty">Δεν έχουν σταλεί εντολές σε αυτή την αποστολή.</p>
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
        <p class="pr-empty">Δεν υπάρχουν λεπτομέρειες.</p>
    <?php else: ?>
        <?php foreach ($detail as $d): ?>
        <div class="event-row">
            <div><?= $d['type_label'] ?> <strong><?= h($d['team_label']) ?></strong> — <?= h($d['user_name']) ?><?= $d['label'] ? ' («' . h($d['label']) . '»)' : '' ?></div>
            <div class="event-time">Στάλθηκε <?= $d['sent_at'] ?> · Ελήφθη <?= $d['ack_at'] ? $d['ack_at'] . ' (' . $d['ack_minutes'] . ' λεπ.)' : '—' ?> · Ολοκληρώθηκε <?= $d['fulfill_at'] ? $d['fulfill_at'] . ' (' . $d['fulfill_minutes'] . ' λεπ.)' : '—' ?></div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<div class="pr-card">
    <h2>⚠️ Αναφορές Έλλειψης &mdash; Ανά Σοβαρότητα</h2>
    <?php if (empty($shortageDetail)): ?>
        <p class="pr-empty">Δεν έχουν υποβληθεί αναφορές έλλειψης.</p>
    <?php else: ?>
        <?php foreach ($shortageDetail as $d): ?>
        <div class="event-row">
            <div><span class="badge badge-<?= SHORTAGE_SEVERITY_COLORS[$d['severity']] ?? 'secondary' ?>"><?= h($d['severity_label']) ?></span> <?= h($d['type_label']) ?> <strong><?= h($d['team_label']) ?></strong> — <?= h($d['reporter_name']) ?> («<?= h($d['title']) ?>»)</div>
            <div class="event-time">Στάλθηκε <?= $d['sent_at'] ?> · Είδε <?= $d['seen_at'] ? $d['seen_at'] . ' (' . $d['seen_minutes'] . ' λεπ.)' : '—' ?> · Λύθηκε <?= $d['resolved_at'] ? $d['resolved_at'] . ' (' . $d['resolved_minutes'] . ' λεπ.)' : '—' ?></div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<div class="pr-card">
    <h2>🕒 Δραστηριότητα</h2>
    <?php if (empty($events)): ?>
        <p class="pr-empty">Δεν υπάρχουν καταγεγραμμένα γεγονότα.</p>
    <?php else: ?>
        <?php foreach ($events as $e): ?>
        <div class="event-row"><div><?= $e['icon'] ?> <?= $e['text'] ?></div><div class="event-time"><?= $e['time'] ?></div></div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<div class="pr-card">
    <h2>📸 Φωτογραφίες Πεδίου</h2>
    <?php if (empty($photos)): ?>
        <p class="pr-empty">Δεν έχουν σταλεί φωτογραφίες.</p>
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
</div>

<div class="pr-card">
    <h2>🎥 Βίντεο Πεδίου</h2>
    <?php if (empty($videos)): ?>
        <p class="pr-empty">Δεν έχουν σταλεί βίντεο.</p>
    <?php else: ?>
    <ul class="video-list">
        <?php foreach ($videos as $v): ?>
        <li>🎥 <?= h($v['user_name']) ?> · <?= h($v['time']) ?> <span style="color:#888;">(δεν ενσωματώνεται σε έντυπη μορφή)</span></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</div>

<div class="pr-footer">
    <span><?= h($orgName) ?> &mdash; Αναφορά Αποστολής</span>
    <span>Εξήχθη: <?= $printDate ?></span>
</div>

<script>
const PALETTE = ['#2a78d6','#008300','#e87ba4','#eda100','#1baf7a','#eb6834','#4a3aa7','#e34948'];
Chart.defaults.font.family = "'Inter', 'Segoe UI', system-ui, sans-serif";

// ── Print-readiness gate — the previous version fired window.print() blind
// at a fixed 400ms, which raced Chart.js animations and Leaflet's async tile
// loads once this page grew real charts/a map. Each chart's animation
// completion and the map's tile-layer load event now count up toward
// $expectedReady (computed server-side from the exact same conditionals that
// gate each block below); auto-print fires once every expected signal has
// reported in, or after a 2500ms fallback — whichever comes first. The
// manual "Εκτύπωση / PDF" button stays the reliable primary path regardless.
let readyCount = 0;
const expectedReady = <?= json_encode($expectedReady) ?>;
let autoprinted = false;
function tryAutoprint() { if (!autoprinted && readyCount >= expectedReady) { autoprinted = true; window.print(); } }
function markReady() { readyCount++; tryAutoprint(); }

function mc(id, type, data, options = {}) {
    const el = document.getElementById(id);
    if (!el) { markReady(); return null; }
    let fired = false;
    return new Chart(el, { type, data, options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { position: 'bottom', labels: { boxWidth: 11, font: { size: 9 } } } },
        animation: { onComplete: () => { if (!fired) { fired = true; markReady(); } } },
        ...options
    }});
}

<?php if ($score['overall'] !== null): ?>
mc('scoreGaugeChart', 'doughnut', {
    labels: ['Βαθμολογία', ''],
    datasets: [{ data: [<?= $score['overall'] ?>, <?= max(0, 100 - $score['overall']) ?>], backgroundColor: ['<?= $scoreTierHex[$score['tier'][0]] ?>', '#e9ecef'], borderWidth: 0 }]
}, { cutout: '76%', plugins: { legend: { display: false }, tooltip: { enabled: false } } });
<?php endif; ?>

<?php if (!empty($timelineData)): ?>
mc('timelineChart', 'line', {
    labels: <?= json_encode($timelineLabels) ?>,
    datasets: [{ label: 'Γεγονότα', data: <?= json_encode($timelineData) ?>, borderColor: PALETTE[0], backgroundColor: 'rgba(42,120,214,.12)', fill: true, tension: 0.3, pointRadius: 2 }]
}, { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } });
<?php endif; ?>

<?php if (!empty($summary)): ?>
mc('teamCountChart', 'bar', {
    labels: <?= json_encode(array_column($summary, 'team_label')) ?>,
    datasets: [{ label: 'Εντολές', data: <?= json_encode(array_column($summary, 'order_count')) ?>, backgroundColor: <?= json_encode(array_map(fn($s) => $teamColorByLabel[$s['team_label']] ?? '#2a78d6', $summary)) ?>, borderRadius: 4 }]
}, { indexAxis: 'y', plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true, ticks: { precision: 0 } } } });

mc('teamAckChart', 'bar', {
    labels: <?= json_encode(array_column($summary, 'team_label')) ?>,
    datasets: [{ label: 'Λεπτά', data: <?= json_encode(array_map(fn($s) => $s['avg_ack_minutes'] ?? 0, $summary)) ?>, backgroundColor: <?= json_encode(array_map(fn($s) => $teamColorByLabel[$s['team_label']] ?? '#1baf7a', $summary)) ?>, borderRadius: 4 }]
}, { indexAxis: 'y', plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true } } });
<?php endif; ?>

<?php if (!empty($orderTypeLabels)): ?>
mc('orderTypeChart', 'pie', {
    labels: <?= json_encode($orderTypeLabels) ?>,
    datasets: [{ data: <?= json_encode($orderTypeCounts) ?>, backgroundColor: PALETTE }]
}, {});

mc('responseDetailChart', 'bar', {
    labels: <?= json_encode($orderTypeLabels) ?>,
    datasets: [
        { label: 'Μέσος χρόνος αποδοχής (λεπ.)', data: <?= json_encode($orderTypeAvgAck) ?>, backgroundColor: PALETTE[0], borderRadius: 4 },
        { label: 'Μέσος χρόνος ολοκλήρωσης (λεπ.)', data: <?= json_encode($orderTypeAvgFulfill) ?>, backgroundColor: PALETTE[5], borderRadius: 4 }
    ]
}, { scales: { y: { beginAtZero: true } } });
<?php endif; ?>

<?php if (!empty($shortageSummary)): ?>
mc('shortageSeverityChart', 'pie', {
    labels: <?= json_encode(array_column($shortageSummary, 'severity_label')) ?>,
    datasets: [{
        data: <?= json_encode(array_column($shortageSummary, 'report_count')) ?>,
        backgroundColor: <?= json_encode(array_map(fn($s) => ['critical' => '#d03b3b', 'high' => '#ec835a', 'medium' => '#fab219', 'low' => '#898781'][$s['severity']] ?? '#898781', $shortageSummary)) ?>
    }]
}, {});
<?php endif; ?>

// ── Recap map — read-only, rendered once, no polling (static archival doc).
window.__printMap = null;
const mapEl = document.getElementById('printMap');
if (mapEl) {
    const missionLatLng = <?= json_encode($mission['latitude'] ? [(float)$mission['latitude'], (float)$mission['longitude']] : null) ?>;
    const map = L.map('printMap').setView(missionLatLng || [37.97, 23.73], missionLatLng ? 13 : 7);
    window.__printMap = map;
    const tiles = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© OpenStreetMap' }).addTo(map);
    tiles.on('load', markReady);
    const bounds = [];

    const pings = <?= json_encode($lastPings) ?>;
    pings.forEach(p => {
        const icon = L.divIcon({ className: '', html: '<span style="display:block;width:14px;height:14px;background:#2a78d6;border:2px solid white;border-radius:50%;box-shadow:0 1px 4px #0008"></span>', iconSize: [14, 14], iconAnchor: [7, 7] });
        L.marker([parseFloat(p.lat), parseFloat(p.lng)], { icon }).addTo(map);
        bounds.push([parseFloat(p.lat), parseFloat(p.lng)]);
    });

    const dispatches = <?= json_encode($dispatchGeo) ?>;
    dispatches.forEach(d => {
        const geo = JSON.parse(d.geo);
        if (d.type === 'point') {
            const icon = L.divIcon({ className: '', html: '<i class="bi bi-geo-alt-fill" style="font-size:22px;color:#4a3aa7;"></i>', iconSize: [22, 22], iconAnchor: [11, 20] });
            L.marker([geo.lat, geo.lng], { icon }).addTo(map);
            bounds.push([geo.lat, geo.lng]);
        } else {
            L.polygon(geo, { color: '#4a3aa7', fillOpacity: 0.15 }).addTo(map);
            geo.forEach(pt => bounds.push(pt));
        }
    });

    const photoPoints = <?= json_encode($photoPoints) ?>;
    photoPoints.forEach(p => {
        const icon = L.divIcon({ className: '', html: '<i class="bi bi-camera-fill" style="font-size:16px;color:#1baf7a;"></i>', iconSize: [16, 16], iconAnchor: [8, 8] });
        L.marker([parseFloat(p.lat), parseFloat(p.lng)], { icon }).addTo(map);
        bounds.push([parseFloat(p.lat), parseFloat(p.lng)]);
    });

    if (missionLatLng) bounds.push(missionLatLng);
    if (bounds.length > 1) map.fitBounds(L.latLngBounds(bounds), { padding: [24, 24] });
    else if (bounds.length === 1) map.setView(bounds[0], 14);
}

// Leaflet computes its tile grid at on-screen width; @page changes the
// effective width once Chromium switches to print layout, so force a refit
// right before any print snapshot — covers both the manual button and the
// auto-print timer, since both trigger the native beforeprint event.
window.addEventListener('beforeprint', function () {
    if (window.__printMap) window.__printMap.invalidateSize();
});

setTimeout(tryAutoprint, 2500);
</script>
</body>
</html>
