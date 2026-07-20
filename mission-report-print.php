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
 * Activity/response-time data comes from two shared includes/functions.php
 * helpers (loadMissionActivityEventsForReport(), computeMissionResponseReport())
 * rather than a proxy through mission-history.php/mission-response-report.php —
 * those endpoints inherit the STATUS_OPEN gate this page must not have.
 * Photos/videos are reused as-is via loadMissionPhotosForUser(), a plain
 * function call with no HTTP-level gate of its own.
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

// ═══════════════════════════════════════════════════════════════════════════
// 2. Response-time report — shared with mission-response-report.php and
//    mission-stats.php via computeMissionResponseReport() in
//    includes/functions.php. This page applies its own d/m/Y H:i (with year)
//    format, unlike the live report's compact d/m H:i.
// ═══════════════════════════════════════════════════════════════════════════
$report = computeMissionResponseReport($missionId);
$summary = $report['summary'];
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
