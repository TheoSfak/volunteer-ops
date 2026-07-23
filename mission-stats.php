<?php
/**
 * VolunteerOps - Mission Stats
 * A post-mission "highlight reel" for War Room missions: a visual recap of
 * response times, participation, shortage handling, field media and the
 * debrief, reusing computeMissionResponseReport()/loadMissionActivityEventsForReport()/
 * loadMissionPhotosForUser() (includes/functions.php) rather than duplicating
 * their query logic a third time. Same permission gate as mission-report-print.php
 * (no status restriction — this is meant to work for a closed/completed mission,
 * but a live OPEN one just shows a partial snapshot, which is harmless).
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();

$userId = getCurrentUserId();
$missionId = (int) get('id');

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

$canManageMissions = hasPagePermission('missions_manage');
$isResponsible = !empty($mission['responsible_user_id']) && (int)$mission['responsible_user_id'] === (int)$userId;
if (!$canManageMissions && !$isResponsible) {
    setFlash('error', 'Αυτή η σελίδα είναι διαθέσιμη μόνο σε διαχειριστές.');
    redirect('mission-view.php?id=' . $missionId);
}

$debrief = dbFetchOne("SELECT * FROM mission_debriefs WHERE mission_id = ?", [$missionId]);

$teams = dbFetchAll("SELECT id, codename, team_number FROM mission_teams WHERE mission_id = ? ORDER BY team_number", [$missionId]);
$teamCount = count($teams);

$approvedCount = (int) dbFetchValue(
    "SELECT COUNT(DISTINCT pr.volunteer_id) FROM participation_requests pr
     JOIN shifts s ON s.id = pr.shift_id
     WHERE s.mission_id = ? AND pr.status = ?",
    [$missionId, PARTICIPATION_APPROVED]
);

// Same idiom as reports.php's "Ώρες Εθελοντισμού" tile — SUM(actual_hours)
// WHERE attended=1, scoped to this mission. Attendance reconciliation only
// happens once a mission is closed (attendance.php's "Διαχείριση Παρουσιών"),
// so before that this would just read 0/near-empty — show a note instead.
$attendanceReady = in_array($mission['status'], [STATUS_CLOSED, STATUS_COMPLETED], true);
$volunteerHours = $attendanceReady ? (float) dbFetchValue(
    "SELECT COALESCE(SUM(pr.actual_hours), 0) FROM participation_requests pr
     JOIN shifts s ON s.id = pr.shift_id
     WHERE s.mission_id = ? AND pr.attended = 1",
    [$missionId]
) : null;

// Shared response-time computation (also used by mission-response-report.php
// and mission-report-print.php) — raw timestamps, this page formats its own.
$report = computeMissionResponseReport($missionId);
$summary = $report['summary'];
$detail = $report['detail'];
$shortageSummary = $report['shortageSummary'];
$shortageDetail = $report['shortageDetail'];

$totalOrders = count($detail);
$ackRows = array_filter($detail, fn($d) => $d['ack_minutes'] !== null);
$fulfillCount = count(array_filter($detail, fn($d) => $d['fulfill_minutes'] !== null));
$avgAckMinutes = count($ackRows) ? round(array_sum(array_column($ackRows, 'ack_minutes')) / count($ackRows), 1) : null;
$fulfillRate = $totalOrders ? round($fulfillCount / $totalOrders * 100) : 0;

$totalShortage = count($shortageDetail);
$resolvedShortage = count(array_filter($shortageDetail, fn($d) => $d['resolved_at'] !== null));
$resolvedShortageRate = $totalShortage ? round($resolvedShortage / $totalShortage * 100) : 0;

// Per-order-type response times (ack vs fulfill, same unit/scale — a single
// shared axis, unlike team order-count vs minutes which stay two charts).
$byOrderType = [];
foreach ($detail as $row) {
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

// Activity feed, for the timeline chart — hourly buckets.
$events = loadMissionActivityEventsForReport($missionId);
$timelineBuckets = [];
foreach ($events as $e) {
    $bucket = date('Y-m-d H:00', $e['ts']);
    $timelineBuckets[$bucket] = ($timelineBuckets[$bucket] ?? 0) + 1;
}
ksort($timelineBuckets);
$timelineLabels = array_map(fn($k) => date('d/m H:i', strtotime($k)), array_keys($timelineBuckets));
$timelineData = array_values($timelineBuckets);

// Media
$media = loadMissionPhotosForUser($missionId, $userId, true, 100000);
$photos = array_values(array_filter($media, fn($m) => $m['media_type'] === 'photo'));
$videos = array_values(array_filter($media, fn($m) => $m['media_type'] === 'video'));

$pingCount = (int) dbFetchValue(
    "SELECT COUNT(*) FROM volunteer_pings vp JOIN shifts s ON s.id = vp.shift_id WHERE s.mission_id = ? AND vp.source = 'manual'",
    [$missionId]
);
$chatCount = (int) dbFetchValue("SELECT COUNT(*) FROM mission_chat_messages WHERE mission_id = ?", [$missionId]);

// Recap map data: last-known ping per volunteer, dispatch points/areas, geo-tagged photos.
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

// ── Mission score + validation review ───────────────────────────────────────
$score = computeMissionScore($missionId, $report);
$scoreReview = dbFetchOne(
    "SELECT r.*, u.name AS validator_name FROM mission_score_reviews r
     JOIN users u ON u.id = r.validated_by WHERE r.mission_id = ?",
    [$missionId]
);
$scoreTierHex = ['good' => '#0ca30c', 'warning' => '#a56600', 'critical' => '#d03b3b'];

// ── Team roster — mirrors war-room.php's team query (leader/members), extended
//    with a fan-out-safe pre-aggregated hours subquery (a volunteer can hold
//    >1 shift row per mission, so hours are summed per-volunteer before the
//    join, not after — a raw join would duplicate/overcount) ────────────────
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

// ── Score validation — the responsible admin can endorse the computed score
//    as-is, or override the final number and add a written verdict. One row
//    per mission (mission_score_reviews.unique_mission_score_review); a
//    re-submit overwrites it, re-stamping validator/time. ───────────────────
if (isPost()) {
    verifyCsrf();
    if (!$canManageMissions && !$isResponsible) {
        setFlash('error', 'Δεν έχετε δικαίωμα.');
        redirect('mission-stats.php?id=' . $missionId);
    }
    if ($score['overall'] === null) {
        setFlash('error', 'Δεν υπάρχουν αρκετά δεδομένα για επικύρωση βαθμολογίας.');
        redirect('mission-stats.php?id=' . $missionId);
    }
    $overrideRaw = trim(post('override_score'));
    $verdictNote = trim(post('verdict_note'));
    $errors = [];
    $finalScore = $score['overall'];
    if ($overrideRaw !== '') {
        if (!is_numeric($overrideRaw) || (float) $overrideRaw < 0 || (float) $overrideRaw > 100) {
            $errors[] = 'Η βαθμολογία πρέπει να είναι αριθμός από 0 έως 100.';
        } else {
            $finalScore = round((float) $overrideRaw, 2);
        }
    }
    if (empty($errors)) {
        dbExecute(
            "INSERT INTO mission_score_reviews (mission_id, computed_score, final_score, verdict_note, validated_by, validated_at)
             VALUES (?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE computed_score = VALUES(computed_score), final_score = VALUES(final_score),
                 verdict_note = VALUES(verdict_note), validated_by = VALUES(validated_by), validated_at = VALUES(validated_at)",
            [$missionId, $score['overall'], $finalScore, $verdictNote !== '' ? $verdictNote : null, $userId]
        );
        logAudit('validate_mission_score', 'missions', $missionId);
        setFlash('success', 'Η βαθμολογία επικυρώθηκε επιτυχώς.');
        redirect('mission-stats.php?id=' . $missionId);
    }
    foreach ($errors as $e) {
        setFlash('error', $e);
    }
}

$pageTitle = 'Στατιστικά Αποστολής: ' . $mission['title'];
include __DIR__ . '/includes/header.php';
?>

<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<style>
    .mstats-hero { background: linear-gradient(135deg, #172554, #b91c1c); color: #fff; border-radius: 16px; }
    .mstats-hero h1 { color: #fff; font-weight: 700; }
    .mstats-stars { color: #fab219; font-size: 1.4rem; letter-spacing: 2px; }
    .mstats-chip { display: inline-flex; align-items: center; gap: 6px; padding: 5px 14px; border-radius: 999px; font-weight: 600; font-size: .85rem; }
    .mstats-chip.good { background: rgba(12,163,12,.15); color: #0ca30c; }
    .mstats-chip.warning { background: rgba(250,178,25,.18); color: #a56600; }
    .mstats-chip.critical { background: rgba(208,59,59,.15); color: #d03b3b; }

    .stat-tile { background: #fff; border-radius: 14px; padding: 20px; box-shadow: 0 2px 10px rgba(0,0,0,.06); height: 100%; }
    .stat-tile .stat-value { font-size: 2rem; font-weight: 700; color: #0b0b0b; line-height: 1.1; }
    .stat-tile.headline .stat-value { font-size: 2.4rem; }
    .stat-tile .stat-label { font-size: .8rem; color: #52514e; text-transform: uppercase; letter-spacing: .03em; margin-top: 4px; }
    .stat-tile .stat-icon { font-size: 1.3rem; color: #2a78d6; margin-bottom: 6px; }
    .stat-tile .stat-note { font-size: .78rem; color: #898781; margin-top: 4px; }

    .mstats-card { background: #fff; border-radius: 14px; box-shadow: 0 2px 10px rgba(0,0,0,.06); padding: 20px; margin-bottom: 24px; }
    .mstats-card h2 { font-size: 1.15rem; font-weight: 700; margin-bottom: 14px; display: flex; align-items: center; gap: 8px; }
    .mstats-empty { color: #898781; font-size: .9rem; padding: 20px 0; text-align: center; }

    .mstats-chart-wrap { position: relative; height: 260px; }
    .mstats-chart-wrap.tall { height: 320px; }

    .mstats-gallery { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 10px; }
    .mstats-gallery img { width: 100%; height: 110px; object-fit: cover; border-radius: 8px; cursor: pointer; transition: transform .15s; }
    .mstats-gallery img:hover { transform: scale(1.03); }
    .mstats-video-row { display: flex; align-items: center; gap: 8px; padding: 6px 0; border-bottom: 1px solid #eee; font-size: .9rem; }

    .mstats-lightbox { position: fixed; inset: 0; background: rgba(0,0,0,.85); display: none; align-items: center; justify-content: center; z-index: 2000; cursor: zoom-out; padding: 20px; }
    .mstats-lightbox.active { display: flex; }
    .mstats-lightbox img { max-width: 100%; max-height: 100%; border-radius: 6px; }

    .mstats-quote { border-left: 4px solid #2a78d6; background: #f9f9f7; border-radius: 0 10px 10px 0; padding: 14px 18px; margin-bottom: 14px; }
    .mstats-quote h6 { font-weight: 700; margin-bottom: 6px; display: flex; align-items: center; gap: 6px; }
    .mstats-quote.incidents { border-left-color: #d03b3b; }
    .mstats-quote.equipment { border-left-color: #eda100; }

    #mstatsMap { height: 380px; border-radius: 10px; }
    .mstats-map-legend { display: flex; gap: 16px; flex-wrap: wrap; font-size: .85rem; margin-top: 10px; }
    .mstats-map-legend span { display: inline-flex; align-items: center; gap: 6px; }
    .mstats-map-legend i { width: 10px; height: 10px; border-radius: 50%; display: inline-block; }

    .score-gauge-wrap { position: relative; width: 200px; height: 200px; margin: 0 auto; }
    .score-gauge-center { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; }
    .score-gauge-center .value { font-size: 2.3rem; font-weight: 800; line-height: 1; }
    .score-gauge-center .tier-label { font-size: .78rem; font-weight: 600; margin-top: 4px; color: #52514e; }
    .score-pillar-row { display: flex; align-items: center; gap: 12px; margin-bottom: 12px; }
    .score-pillar-label { width: 180px; flex-shrink: 0; font-size: .85rem; font-weight: 600; color: #52514e; }
    .score-pillar-track { flex: 1; height: 14px; background: #eee; border-radius: 999px; overflow: hidden; }
    .score-pillar-fill { height: 100%; border-radius: 999px; }
    .score-pillar-value { width: 46px; flex-shrink: 0; text-align: right; font-weight: 700; font-size: .85rem; }
    .score-leaderboard-row { display: flex; align-items: center; gap: 12px; padding: 10px 14px; border-radius: 10px; background: #f9f9f7; margin-bottom: 8px; border-left: 5px solid; }
    .score-leaderboard-rank { font-size: 1.3rem; width: 32px; text-align: center; flex-shrink: 0; }
    .score-leaderboard-team { font-weight: 700; flex: 1; }
    .score-leaderboard-score { font-weight: 800; font-size: 1.1rem; }
    .score-validation-box { background: #f9f9f7; border-radius: 12px; padding: 18px; margin-top: 20px; }
    .roster-team-card { border-radius: 12px; border: 1px solid #eee; padding: 14px 16px; margin-bottom: 12px; height: 100%; }
    .roster-team-header { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; font-weight: 700; flex-wrap: wrap; }
    .roster-swatch { width: 14px; height: 14px; border-radius: 4px; flex-shrink: 0; }
    .roster-member-row { display: flex; justify-content: space-between; padding: 4px 0; font-size: .88rem; border-bottom: 1px solid #f2f2f0; }
    .roster-member-row:last-child { border-bottom: none; }
</style>

<div class="container-fluid py-4">

<div class="mstats-hero p-4 mb-4 shadow-sm">
    <div class="d-flex flex-wrap justify-content-between gap-3 align-items-start">
        <div>
            <div class="text-uppercase small fw-semibold opacity-75 mb-1"><i class="bi bi-graph-up-arrow me-1"></i>Στατιστικά & Αναφορά Αποστολής</div>
            <h1 class="h3 mb-2"><?= h($mission['title']) ?></h1>
            <div class="small opacity-75"><i class="bi bi-geo-alt me-1"></i><?= h($mission['location']) ?> · <?= formatDateTime($mission['start_datetime']) ?> έως <?= formatDateTime($mission['end_datetime']) ?></div>
        </div>
        <div class="text-end">
            <span class="badge fs-6 bg-light text-dark mb-2"><?= h(STATUS_LABELS[$mission['status']] ?? $mission['status']) ?></span>
            <?php if ($debrief): ?>
            <div class="mstats-stars">
                <?= str_repeat('★', (int)$debrief['rating']) . str_repeat('☆', 5 - (int)$debrief['rating']) ?>
            </div>
            <?php
                $objMeta = [
                    'YES'     => ['good', 'bi-check-circle-fill', 'Στόχοι επιτεύχθηκαν'],
                    'PARTIAL' => ['warning', 'bi-exclamation-circle-fill', 'Μερική επίτευξη στόχων'],
                    'NO'      => ['critical', 'bi-x-circle-fill', 'Στόχοι δεν επιτεύχθηκαν'],
                ];
                $om = $objMeta[$debrief['objectives_met']] ?? $objMeta['PARTIAL'];
            ?>
            <div class="mstats-chip <?= $om[0] ?>"><i class="bi <?= $om[1] ?>"></i><?= $om[2] ?></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Mission score -->
<div class="mstats-card">
    <h2><i class="bi bi-award-fill text-primary"></i>Βαθμολογία Άσκησης</h2>
    <?php if ($score['overall'] === null): ?>
        <p class="mstats-empty">Δεν υπάρχουν ακόμα αρκετά δεδομένα (εντολές, βάρδιες ή αναφορά debrief) για τον υπολογισμό βαθμολογίας.</p>
    <?php else: ?>
    <div class="row g-4 align-items-center mb-2">
        <div class="col-md-4 text-center">
            <div class="score-gauge-wrap">
                <canvas id="scoreGaugeChart"></canvas>
                <div class="score-gauge-center">
                    <div class="value" style="color:<?= $scoreTierHex[$score['tier'][0]] ?>;"><?= number_format($score['overall'], 1) ?></div>
                    <div class="tier-label"><?= h($score['tier'][1]) ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-8">
            <?php foreach ($score['pillars'] as $p): ?>
                <div class="score-pillar-row">
                    <div class="score-pillar-label"><?= h($p['label']) ?></div>
                    <?php if ($p['available']): $pTier = missionScoreTierMeta($p['score']); ?>
                    <div class="score-pillar-track"><div class="score-pillar-fill" style="width:<?= round($p['score']) ?>%;background:<?= $scoreTierHex[$pTier[0]] ?>;"></div></div>
                    <div class="score-pillar-value"><?= number_format($p['score'], 0) ?></div>
                    <?php else: ?>
                    <div class="score-pillar-track"><div class="score-pillar-fill" style="width:0;"></div></div>
                    <div class="score-pillar-value text-muted">—</div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if (!empty($score['teams'])): ?>
    <h3 class="mt-3 mb-2"><i class="bi bi-trophy-fill text-warning"></i> Κατάταξη Ομάδων</h3>
    <?php foreach ($score['teams'] as $t): ?>
        <div class="score-leaderboard-row" style="border-left-color:<?= h($t['color']) ?>;">
            <div class="score-leaderboard-rank"><?= $t['rank'] === 1 ? '🥇' : ($t['rank'] === 2 ? '🥈' : ($t['rank'] === 3 ? '🥉' : $t['rank'])) ?></div>
            <span class="badge" style="background:<?= h($t['color']) ?>;color:#fff;"><?= h($t['codename'] . ' ' . $t['team_number']) ?></span>
            <div class="score-leaderboard-team"><?= h($t['tier'][1]) ?></div>
            <div class="score-leaderboard-score" style="color:<?= $scoreTierHex[$t['tier'][0]] ?>;"><?= number_format($t['score'], 1) ?></div>
        </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <div class="score-validation-box">
        <?php if ($scoreReview): ?>
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                <div>
                    <div class="fw-bold text-success mb-1"><i class="bi bi-patch-check-fill"></i> Επικυρώθηκε από <?= h($scoreReview['validator_name']) ?> στις <?= formatDateTime($scoreReview['validated_at']) ?></div>
                    <div class="fs-4 fw-bold mb-1">Τελική Βαθμολογία: <?= number_format($scoreReview['final_score'], 1) ?></div>
                    <?php if (!empty($scoreReview['verdict_note'])): ?><div class="fst-italic">&laquo;<?= nl2br(h($scoreReview['verdict_note'])) ?>&raquo;</div><?php endif; ?>
                </div>
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="document.getElementById('scoreValidationForm').classList.toggle('d-none')"><i class="bi bi-pencil"></i> Επεξεργασία</button>
            </div>
            <form method="post" action="" id="scoreValidationForm" class="d-none mt-3 pt-3 border-top">
        <?php else: ?>
            <h3 class="mb-2"><i class="bi bi-clipboard-check"></i> Επικύρωση Βαθμολογίας</h3>
            <form method="post" action="">
        <?php endif; ?>
                <?= csrfField() ?>
                <div class="row g-3 align-items-end">
                    <div class="col-sm-4">
                        <label class="form-label small fw-semibold">Τελική βαθμολογία <span class="text-muted fw-normal">(προαιρετική διόρθωση, αλλιώς <?= number_format($score['overall'], 1) ?>)</span></label>
                        <input type="number" step="0.1" min="0" max="100" class="form-control" name="override_score" placeholder="<?= number_format($score['overall'], 1) ?>" value="<?= isPost() ? h(post('override_score')) : ($scoreReview ? h(number_format((float) $scoreReview['final_score'], 1)) : '') ?>">
                    </div>
                    <div class="col-sm-8">
                        <label class="form-label small fw-semibold">Σχόλιο / τελική κρίση παρατηρητή</label>
                        <textarea class="form-control" name="verdict_note" rows="1" maxlength="1000"><?= isPost() ? h(post('verdict_note')) : h($scoreReview['verdict_note'] ?? '') ?></textarea>
                    </div>
                </div>
                <button type="submit" class="btn btn-success mt-3"><i class="bi bi-check2-circle"></i> Επικύρωση Βαθμολογίας</button>
            </form>
    </div>
    <?php endif; ?>
</div>

<!-- Team roster -->
<div class="mstats-card">
    <h2><i class="bi bi-people-fill text-primary"></i>Ρόστερ Ομάδων</h2>
    <?php if (empty($roster)): ?>
        <p class="mstats-empty">Δεν έχουν δημιουργηθεί ομάδες.</p>
    <?php else: ?>
    <div class="row g-3">
        <?php foreach ($roster as $team): ?>
        <div class="col-md-6">
            <div class="roster-team-card">
                <div class="roster-team-header">
                    <span class="roster-swatch" style="background:<?= h($team['color']) ?>;"></span>
                    <span><?= h($team['codename'] . ' ' . $team['team_number']) ?></span>
                    <?php if ($team['leader_name']): ?><span class="text-muted fw-normal small">&middot; Υπεύθυνος: <?= guestNameHtml($team['leader_name'], $team['leader_is_external'], $team['leader_guest_org_name']) ?></span><?php endif; ?>
                </div>
                <?php if (empty($team['members'])): ?>
                    <div class="text-muted small">Χωρίς μέλη.</div>
                <?php else: ?>
                    <?php foreach ($team['members'] as $m): ?>
                    <div class="roster-member-row">
                        <span><?= guestNameHtml($m['name'], $m['is_external'], $m['guest_org_name']) ?></span>
                        <span class="text-muted"><?= $attendanceReady ? number_format($m['hours'], 1) . ' ώρες' : 'Εκκρεμεί' ?></span>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Headline stat tiles -->
<div class="row row-cols-2 row-cols-lg-5 g-3 mb-3">
    <div class="col">
        <div class="stat-tile headline">
            <div class="stat-icon"><i class="bi bi-clock-history"></i></div>
            <?php if ($attendanceReady): ?>
            <div class="stat-value" data-count="<?= $volunteerHours ?>">0</div>
            <div class="stat-label">Ώρες Εθελοντισμού</div>
            <?php else: ?>
            <div class="stat-value text-muted" style="font-size:1.1rem;">—</div>
            <div class="stat-label">Ώρες Εθελοντισμού</div>
            <div class="stat-note">Εκκρεμεί καταγραφή παρουσιών</div>
            <?php endif; ?>
        </div>
    </div>
    <div class="col">
        <div class="stat-tile headline">
            <div class="stat-icon"><i class="bi bi-people-fill"></i></div>
            <div class="stat-value" data-count="<?= $approvedCount ?>">0</div>
            <div class="stat-label">Εγκεκριμένοι Εθελοντές</div>
        </div>
    </div>
    <div class="col">
        <div class="stat-tile headline">
            <div class="stat-icon"><i class="bi bi-diagram-3-fill"></i></div>
            <div class="stat-value" data-count="<?= $teamCount ?>">0</div>
            <div class="stat-label">Ομάδες</div>
        </div>
    </div>
    <div class="col">
        <div class="stat-tile headline">
            <div class="stat-icon"><i class="bi bi-stopwatch-fill"></i></div>
            <?php if ($avgAckMinutes !== null): ?>
            <div class="stat-value" data-count="<?= $avgAckMinutes ?>">0</div>
            <div class="stat-label">Μέσος Χρόνος Απόκρισης (λεπ.)</div>
            <?php else: ?>
            <div class="stat-value text-muted" style="font-size:1.1rem;">—</div>
            <div class="stat-label">Μέσος Χρόνος Απόκρισης</div>
            <?php endif; ?>
        </div>
    </div>
    <div class="col">
        <div class="stat-tile headline">
            <div class="stat-icon"><i class="bi bi-check2-circle"></i></div>
            <?php if ($totalOrders): ?>
            <div class="stat-value" data-count="<?= $fulfillRate ?>">0</div>
            <div class="stat-label">Ποσοστό Ολοκλήρωσης (%)</div>
            <?php else: ?>
            <div class="stat-value text-muted" style="font-size:1.1rem;">—</div>
            <div class="stat-label">Ποσοστό Ολοκλήρωσης</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Secondary stat tiles -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="stat-tile">
            <div class="stat-icon"><i class="bi bi-exclamation-triangle-fill"></i></div>
            <div class="stat-value" data-count="<?= $totalShortage ?>">0</div>
            <div class="stat-label">Αναφορές Έλλειψης</div>
            <?php if ($totalShortage): ?><div class="stat-note"><?= $resolvedShortageRate ?>% λύθηκαν</div><?php endif; ?>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-tile">
            <div class="stat-icon"><i class="bi bi-camera-fill"></i></div>
            <div class="stat-value" data-count="<?= count($photos) + count($videos) ?>">0</div>
            <div class="stat-label">Φωτογραφίες / Βίντεο</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-tile">
            <div class="stat-icon"><i class="bi bi-broadcast"></i></div>
            <div class="stat-value" data-count="<?= $pingCount ?>">0</div>
            <div class="stat-label">GPS Στίγματα</div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-tile">
            <div class="stat-icon"><i class="bi bi-chat-dots-fill"></i></div>
            <div class="stat-value" data-count="<?= $chatCount ?>">0</div>
            <div class="stat-label">Μηνύματα Συνομιλίας</div>
        </div>
    </div>
</div>

<!-- Activity over time -->
<div class="mstats-card">
    <h2><i class="bi bi-activity text-primary"></i>Δραστηριότητα στον Χρόνο</h2>
    <?php if (empty($timelineData)): ?>
        <p class="mstats-empty">Δεν υπάρχουν καταγεγραμμένα γεγονότα.</p>
    <?php else: ?>
        <div class="mstats-chart-wrap tall"><canvas id="timelineChart"></canvas></div>
    <?php endif; ?>
</div>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="mstats-card">
            <h2><i class="bi bi-diagram-3-fill text-primary"></i>Εντολές ανά Ομάδα</h2>
            <?php if (empty($summary)): ?>
                <p class="mstats-empty">Δεν έχουν σταλεί εντολές.</p>
            <?php else: ?>
                <div class="mstats-chart-wrap"><canvas id="teamCountChart"></canvas></div>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="mstats-card">
            <h2><i class="bi bi-stopwatch-fill text-primary"></i>Μέσος Χρόνος Απόκρισης ανά Ομάδα</h2>
            <?php if (empty($summary)): ?>
                <p class="mstats-empty">Δεν έχουν σταλεί εντολές.</p>
            <?php else: ?>
                <div class="mstats-chart-wrap"><canvas id="teamAckChart"></canvas></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="mstats-card">
            <h2><i class="bi bi-list-check text-primary"></i>Εντολές ανά Τύπο</h2>
            <?php if (empty($orderTypeLabels)): ?>
                <p class="mstats-empty">Δεν έχουν σταλεί εντολές.</p>
            <?php else: ?>
                <div class="mstats-chart-wrap"><canvas id="orderTypeChart"></canvas></div>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="mstats-card">
            <h2><i class="bi bi-exclamation-triangle-fill text-primary"></i>Ελλείψεις ανά Σοβαρότητα</h2>
            <?php if (empty($shortageSummary)): ?>
                <p class="mstats-empty">Δεν έχουν υποβληθεί αναφορές έλλειψης.</p>
            <?php else: ?>
                <div class="mstats-chart-wrap"><canvas id="shortageSeverityChart"></canvas></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="mstats-card">
    <h2><i class="bi bi-hourglass-split text-primary"></i>Χρόνοι Απόκρισης ανά Τύπο Εντολής</h2>
    <?php if (empty($orderTypeLabels)): ?>
        <p class="mstats-empty">Δεν έχουν σταλεί εντολές.</p>
    <?php else: ?>
        <div class="mstats-chart-wrap tall"><canvas id="responseDetailChart"></canvas></div>
    <?php endif; ?>
</div>

<!-- Photo/video gallery -->
<div class="mstats-card">
    <h2><i class="bi bi-images text-primary"></i>Φωτογραφίες & Βίντεο Πεδίου</h2>
    <?php if (empty($photos) && empty($videos)): ?>
        <p class="mstats-empty">Δεν έχουν σταλεί φωτογραφίες ή βίντεο.</p>
    <?php else: ?>
        <?php if (!empty($photos)): ?>
        <div class="mstats-gallery mb-3">
            <?php foreach ($photos as $p): ?>
            <img class="gallery-thumb" src="mission-photo-view.php?id=<?= $p['id'] ?>" data-full="mission-photo-view.php?id=<?= $p['id'] ?>" alt="" title="<?= h($p['user_name']) ?> · <?= h($p['time']) ?>">
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <?php if (!empty($videos)): ?>
        <div>
            <?php foreach ($videos as $v): ?>
            <div class="mstats-video-row"><i class="bi bi-camera-reels-fill text-danger"></i><a href="mission-photo-view.php?id=<?= $v['id'] ?>" target="_blank"><?= h($v['user_name']) ?> · <?= h($v['time']) ?></a></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<!-- Recap map -->
<div class="mstats-card">
    <h2><i class="bi bi-map-fill text-primary"></i>Χάρτης Δραστηριότητας</h2>
    <?php if (empty($lastPings) && empty($dispatchGeo) && empty($photoPoints)): ?>
        <p class="mstats-empty">Δεν υπάρχουν καταγεγραμμένα σημεία στον χάρτη.</p>
    <?php else: ?>
        <div id="mstatsMap"></div>
        <div class="mstats-map-legend">
            <span><i style="background:#2a78d6;"></i>Τελευταίο στίγμα εθελοντή</span>
            <span><i style="background:#4a3aa7;"></i>Σημείο/Περιοχή αποστολής</span>
            <span><i style="background:#1baf7a;"></i>Φωτογραφία/Βίντεο</span>
        </div>
    <?php endif; ?>
</div>

<!-- Debrief narrative -->
<?php if ($debrief): ?>
<div class="mstats-card">
    <h2><i class="bi bi-journal-text text-primary"></i>Αναφορά Debrief</h2>
    <div class="mstats-quote">
        <h6><i class="bi bi-card-text"></i>Σύνοψη</h6>
        <div><?= nl2br(h($debrief['summary'])) ?></div>
    </div>
    <?php if (!empty($debrief['incidents'])): ?>
    <div class="mstats-quote incidents">
        <h6><i class="bi bi-exclamation-octagon-fill text-danger"></i>Συμβάντα / Ατυχήματα</h6>
        <div><?= nl2br(h($debrief['incidents'])) ?></div>
    </div>
    <?php endif; ?>
    <?php if (!empty($debrief['equipment_issues'])): ?>
    <div class="mstats-quote equipment">
        <h6><i class="bi bi-tools text-warning"></i>Προβλήματα Εξοπλισμού</h6>
        <div><?= nl2br(h($debrief['equipment_issues'])) ?></div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<a href="mission-view.php?id=<?= $missionId ?>" class="btn btn-secondary"><i class="bi bi-arrow-left me-1"></i>Επιστροφή στην Αποστολή</a>

</div>

<div class="mstats-lightbox" id="lightboxOverlay"><img id="lightboxImg" src="" alt=""></div>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
const PALETTE = ['#2a78d6','#008300','#e87ba4','#eda100','#1baf7a','#eb6834','#4a3aa7','#e34948'];
Chart.defaults.font.family = "'Segoe UI', system-ui, sans-serif";

<?php if ($score['overall'] !== null): ?>
mc('scoreGaugeChart', 'doughnut', {
    labels: ['Βαθμολογία', ''],
    datasets: [{ data: [<?= $score['overall'] ?>, <?= max(0, 100 - $score['overall']) ?>], backgroundColor: ['<?= $scoreTierHex[$score['tier'][0]] ?>', '#e9ecef'], borderWidth: 0 }]
}, { cutout: '76%', plugins: { legend: { display: false }, tooltip: { enabled: false } } });
<?php endif; ?>

function mc(id, type, data, options = {}) {
    const el = document.getElementById(id);
    if (!el) return null;
    return new Chart(el, { type, data, options: {
        responsive: true, maintainAspectRatio: false,
        plugins: { legend: { position: 'bottom' } },
        ...options
    }});
}

mc('timelineChart', 'line', {
    labels: <?= json_encode($timelineLabels) ?>,
    datasets: [{ label: 'Γεγονότα', data: <?= json_encode($timelineData) ?>, borderColor: PALETTE[0], backgroundColor: 'rgba(42,120,214,.12)', fill: true, tension: 0.3, pointRadius: 2 }]
}, { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } });

mc('teamCountChart', 'bar', {
    labels: <?= json_encode(array_column($summary, 'team_label')) ?>,
    datasets: [{ label: 'Εντολές', data: <?= json_encode(array_column($summary, 'order_count')) ?>, backgroundColor: PALETTE[0], borderRadius: 4 }]
}, { indexAxis: 'y', plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true, ticks: { precision: 0 } } } });

mc('teamAckChart', 'bar', {
    labels: <?= json_encode(array_column($summary, 'team_label')) ?>,
    datasets: [{ label: 'Λεπτά', data: <?= json_encode(array_map(fn($s) => $s['avg_ack_minutes'] ?? 0, $summary)) ?>, backgroundColor: PALETTE[4], borderRadius: 4 }]
}, { indexAxis: 'y', plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true } } });

mc('orderTypeChart', 'bar', {
    labels: <?= json_encode($orderTypeLabels) ?>,
    datasets: [{ label: 'Πλήθος', data: <?= json_encode($orderTypeCounts) ?>, backgroundColor: PALETTE, borderRadius: 4 }]
}, { indexAxis: 'y', plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true, ticks: { precision: 0 } } } });

mc('shortageSeverityChart', 'bar', {
    labels: <?= json_encode(array_column($shortageSummary, 'severity_label')) ?>,
    datasets: [{
        label: 'Αναφορές',
        data: <?= json_encode(array_column($shortageSummary, 'report_count')) ?>,
        backgroundColor: <?= json_encode(array_map(fn($s) => ['critical' => '#d03b3b', 'high' => '#ec835a', 'medium' => '#fab219', 'low' => '#898781'][$s['severity']] ?? '#898781', $shortageSummary)) ?>,
        borderRadius: 4
    }]
}, { indexAxis: 'y', plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true, ticks: { precision: 0 } } } });

mc('responseDetailChart', 'bar', {
    labels: <?= json_encode($orderTypeLabels) ?>,
    datasets: [
        { label: 'Μέσος χρόνος αποδοχής (λεπ.)', data: <?= json_encode($orderTypeAvgAck) ?>, backgroundColor: PALETTE[0], borderRadius: 4 },
        { label: 'Μέσος χρόνος ολοκλήρωσης (λεπ.)', data: <?= json_encode($orderTypeAvgFulfill) ?>, backgroundColor: PALETTE[5], borderRadius: 4 }
    ]
}, { scales: { y: { beginAtZero: true } } });

// Count-up animation for stat tiles. requestAnimationFrame is throttled/paused
// on hidden or unfocused tabs (e.g. opened via "open in new tab" without
// switching to it) — skip straight to the final value in that case instead of
// leaving the tile stuck at its placeholder "0" indefinitely.
function countUp(el, target, duration = 900) {
    const isFloat = !Number.isInteger(target);
    if (document.hidden) {
        el.textContent = isFloat ? target.toFixed(1) : target.toLocaleString('el-GR');
        return;
    }
    const start = performance.now();
    function step(now) {
        const progress = Math.min((now - start) / duration, 1);
        const eased = 1 - Math.pow(1 - progress, 3);
        const val = target * eased;
        el.textContent = isFloat ? val.toFixed(1) : Math.round(val).toLocaleString('el-GR');
        if (progress < 1) requestAnimationFrame(step);
        else el.textContent = isFloat ? target.toFixed(1) : target.toLocaleString('el-GR');
    }
    requestAnimationFrame(step);
}
document.querySelectorAll('.stat-value[data-count]').forEach(el => countUp(el, parseFloat(el.dataset.count)));

// Lightbox.
document.querySelectorAll('.gallery-thumb').forEach(img => {
    img.addEventListener('click', () => {
        document.getElementById('lightboxImg').src = img.dataset.full;
        document.getElementById('lightboxOverlay').classList.add('active');
    });
});
document.getElementById('lightboxOverlay')?.addEventListener('click', function () { this.classList.remove('active'); });

// Recap map — read-only, rendered once, no polling.
const mapEl = document.getElementById('mstatsMap');
if (mapEl) {
    const missionLatLng = <?= json_encode($mission['latitude'] ? [(float)$mission['latitude'], (float)$mission['longitude']] : null) ?>;
    const map = L.map('mstatsMap').setView(missionLatLng || [37.97, 23.73], missionLatLng ? 13 : 7);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '© OpenStreetMap' }).addTo(map);
    const bounds = [];

    const pings = <?= json_encode($lastPings) ?>;
    pings.forEach(p => {
        const icon = L.divIcon({ className: '', html: '<span style="display:block;width:14px;height:14px;background:#2a78d6;border:2px solid white;border-radius:50%;box-shadow:0 1px 4px #0008"></span>', iconSize: [14, 14], iconAnchor: [7, 7] });
        L.marker([parseFloat(p.lat), parseFloat(p.lng)], { icon }).addTo(map).bindPopup(`<strong>${p.name}</strong><br>${p.created_at}`);
        bounds.push([parseFloat(p.lat), parseFloat(p.lng)]);
    });

    const dispatches = <?= json_encode($dispatchGeo) ?>;
    dispatches.forEach(d => {
        const geo = JSON.parse(d.geo);
        if (d.type === 'point') {
            const icon = L.divIcon({ className: '', html: '<i class="bi bi-geo-alt-fill" style="font-size:24px;color:#4a3aa7;"></i>', iconSize: [24, 24], iconAnchor: [12, 22] });
            L.marker([geo.lat, geo.lng], { icon }).addTo(map).bindPopup(d.label || 'Σημείο αποστολής');
            bounds.push([geo.lat, geo.lng]);
        } else {
            L.polygon(geo, { color: '#4a3aa7', fillOpacity: 0.15 }).addTo(map).bindPopup(d.label || 'Περιοχή αποστολής');
            geo.forEach(pt => bounds.push(pt));
        }
    });

    const photoPoints = <?= json_encode($photoPoints) ?>;
    photoPoints.forEach(p => {
        const icon = L.divIcon({ className: '', html: '<i class="bi bi-camera-fill" style="font-size:18px;color:#1baf7a;"></i>', iconSize: [18, 18], iconAnchor: [9, 9] });
        L.marker([parseFloat(p.lat), parseFloat(p.lng)], { icon }).addTo(map).bindPopup(`<img src="mission-photo-view.php?id=${p.id}" style="width:120px;border-radius:4px;">`);
        bounds.push([parseFloat(p.lat), parseFloat(p.lng)]);
    });

    if (missionLatLng) bounds.push(missionLatLng);
    if (bounds.length > 1) map.fitBounds(L.latLngBounds(bounds), { padding: [30, 30] });
    else if (bounds.length === 1) map.setView(bounds[0], 14);
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
