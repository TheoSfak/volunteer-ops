<?php
/**
 * VolunteerOps - Αναφορά Παλμών (heart-rate report)
 *
 * The command-staff view of rescuer vitals: what every volunteer's heart is
 * doing right now, which stretches of the deployment went outside a safe band
 * long enough to matter, and how the load is distributed across teams.
 *
 * Deliberately NOT gated on the mission being closed, unlike mission-stats.php.
 * That page is a recap; this one exists to be read at hour four of an
 * eight-hour search, while there is still time to pull somebody out. It
 * refreshes itself while the mission is open and says plainly when it is
 * looking at history instead.
 *
 * Health data under Article 9 GDPR, so the gate is command staff only — the
 * same canManageActionRoom() test the Action Room's own command tools use.
 * A volunteer sees their own heart rate on their own phone and nowhere else.
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();

$user      = getCurrentUser();
$userId    = (int) $user['id'];
$missionId = (int) get('id');

$mission = dbFetchOne(
    "SELECT m.*, d.name AS department_name FROM missions m
     LEFT JOIN departments d ON d.id = m.department_id
     WHERE m.id = ? AND m.deleted_at IS NULL",
    [$missionId]
);
if (!$mission) {
    setFlash('error', 'Η αποστολή δεν βρέθηκε.');
    redirect('dashboard.php');
}

if (!canManageActionRoom($mission['responsible_user_id'] ? (int) $mission['responsible_user_id'] : null, $userId)) {
    setFlash('error', 'Η αναφορά παλμών είναι διαθέσιμη μόνο στο επιτελείο της αποστολής.');
    redirect('mission-view.php?id=' . $missionId);
}

if (!vitalsEnabled()) {
    setFlash('error', 'Η παρακολούθηση καρδιακών παλμών είναι απενεργοποιημένη στις ρυθμίσεις.');
    redirect('war-room.php?id=' . $missionId);
}

$isLive    = $mission['status'] === STATUS_OPEN;
$now       = loadVitalsNowForMission($missionId);
$episodes  = detectVitalsEpisodes($missionId);
$teamLoad  = loadVitalsTeamLoadForMission($missionId, $episodes);
// Chart window. A live mission defaults to the last eight hours — one shift,
// the span a command post is actually reasoning about — while a closed one
// shows everything, because by then the question is "what happened", not
// "what is happening". Only the chart's axis narrows; every total, episode and
// table below is whole-mission regardless.
$windows = ['2' => 2, '8' => 8, '24' => 24, 'all' => null];
$windowKey = (string) (get('window') ?: ($isLive ? '8' : 'all'));
if (!array_key_exists($windowKey, $windows)) $windowKey = 'all';
$windowHours = $windows[$windowKey];
$report    = loadVitalsReportForMission($missionId, $windowHours ? time() - $windowHours * 3600 : null);
$config    = vitalsConfig();
$maxHr     = vitalsMaxHeartRate();
// Computed here, not read back out of $report: that array is empty when the
// mission recorded nothing at all, and the episode card's thresholds have to
// render either way.
$elevatedBpm = vitalsZoneBpm($config['elevated_pct'], $maxHr);
$criticalBpm = vitalsZoneBpm($config['critical_pct'], $maxHr);

// Tachycardia and bradycardia are EVENTS — a moment something happened to one
// person, worth a card each. Sustained strain is a WORKLOAD, and on a real
// mountain callout it fires for nearly everyone: a rescuer climbing for two
// hours with a pack sits above 75% of maximum almost the whole way. A drill of
// ten people produced twenty-one episodes of which seventeen were strain, and
// the two moments that actually mattered — a rescuer's fall, and the medic's
// resting bradycardia — were buried in the middle of them.
//
// So the two clinical kinds keep their cards, and strain is collapsed to one
// row per volunteer with a total. Nothing is hidden; the same minutes are all
// still reported, in the shape that answers the question they belong to.
$clinical      = array_values(array_filter($episodes, fn($e) => $e['type'] !== 'strain'));
$activeEpisodes = array_values(array_filter($clinical, fn($e) => $e['active']));
$pastEpisodes   = array_values(array_filter($clinical, fn($e) => !$e['active']));

$strainByUser = [];
foreach ($episodes as $e) {
    if ($e['type'] !== 'strain') continue;
    $u = $e['user_id'];
    if (!isset($strainByUser[$u])) {
        $strainByUser[$u] = ['name' => $e['name'], 'team_label' => $e['team_label'],
                             'spells' => 0, 'minutes' => 0, 'longest' => 0, 'peak' => 0, 'active' => false];
    }
    $strainByUser[$u]['spells']++;
    $strainByUser[$u]['minutes'] += $e['minutes'];
    $strainByUser[$u]['longest']  = max($strainByUser[$u]['longest'], $e['minutes']);
    $strainByUser[$u]['peak']     = max($strainByUser[$u]['peak'], $e['bpm_peak']);
    $strainByUser[$u]['active']   = $strainByUser[$u]['active'] || $e['active'];
}
uasort($strainByUser, fn($a, $b) => $b['minutes'] <=> $a['minutes']);

// Same compact duration format as the mission recap — four duration columns on
// one row stop being comparable at a glance in the long form.
$vDur = function (int $seconds): string {
    if ($seconds <= 0) return '—';
    if ($seconds < 60) return $seconds . 'δ';
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    return $h > 0 ? ($m > 0 ? "{$h}ω {$m}λ" : "{$h}ω") : "{$m}λ";
};
$vMin = fn(int $minutes): string => $minutes >= 60
    ? (intdiv($minutes, 60) . 'ω' . ($minutes % 60 ? ' ' . ($minutes % 60) . 'λ' : ''))
    : ($minutes . 'λ');

$zoneLabel = [
    'critical' => 'Ταχυκαρδία', 'low' => 'Βραδυκαρδία', 'elevated' => 'Αυξημένοι',
    'ok' => 'Φυσιολογικοί', 'stale' => 'Χωρίς σήμα', 'none' => 'Χωρίς αισθητήρα',
];
$episodeLabel = ['tachycardia' => 'Ταχυκαρδία', 'bradycardia' => 'Βραδυκαρδία', 'strain' => 'Παρατεταμένη καταπόνηση'];
$episodeIcon  = ['tachycardia' => 'bi-heart-pulse-fill', 'bradycardia' => 'bi-arrow-down-circle-fill', 'strain' => 'bi-hourglass-split'];
$episodeCls   = ['tachycardia' => 'crit', 'bradycardia' => 'low', 'strain' => 'elev'];

$pageTitle = 'Αναφορά Παλμών: ' . $mission['title'];
include __DIR__ . '/includes/header.php';
?>

<style>
    .vr-hero { background: linear-gradient(135deg, #7f1d1d, #172554); color: #fff; border-radius: 16px; padding: 20px 24px; }
    .vr-hero h1 { color: #fff; font-weight: 700; font-size: 1.5rem; margin: 0; }
    .vr-live { display: inline-flex; align-items: center; gap: 6px; background: rgba(255,255,255,.18); padding: 3px 12px; border-radius: 999px; font-size: .8rem; font-weight: 600; }
    .vr-live-dot { width: 8px; height: 8px; border-radius: 50%; background: #4ade80; animation: vrPulse 1.6s ease-in-out infinite; }
    @keyframes vrPulse { 0%,100% { opacity: 1; } 50% { opacity: .25; } }
    @media (prefers-reduced-motion: reduce) { .vr-live-dot { animation: none; } }

    .vr-card { background: #fff; border-radius: 14px; box-shadow: 0 2px 10px rgba(0,0,0,.06); padding: 18px 20px; margin-bottom: 20px; }
    .vr-card h2 { font-size: 1.1rem; font-weight: 700; margin-bottom: 12px; display: flex; align-items: center; gap: 8px; }
    .vr-empty { color: #898781; font-style: italic; margin: 0; }

    .vr-tile { background: #fff; border-radius: 14px; box-shadow: 0 2px 10px rgba(0,0,0,.06); padding: 14px 16px; height: 100%; border-left: 5px solid #cfd4da; }
    .vr-tile .v { font-size: 1.9rem; font-weight: 700; line-height: 1.05; }
    .vr-tile .l { font-size: .72rem; text-transform: uppercase; letter-spacing: .03em; color: #52514e; margin-top: 2px; }
    .vr-tile.crit { border-left-color: #b91c1c; } .vr-tile.crit .v { color: #b91c1c; }
    .vr-tile.low  { border-left-color: #1d4ed8; } .vr-tile.low .v  { color: #1d4ed8; }
    .vr-tile.elev { border-left-color: #b45309; } .vr-tile.elev .v { color: #b45309; }
    .vr-tile.ok   { border-left-color: #15803d; } .vr-tile.ok .v   { color: #15803d; }
    .vr-tile.mute { border-left-color: #6c757d; } .vr-tile.mute .v { color: #6c757d; }

    .vr-table { width: 100%; font-size: .88rem; }
    .vr-table th { text-align: left; color: #898781; font-weight: 600; font-size: .72rem; text-transform: uppercase; padding: 5px 8px; border-bottom: 2px solid #eee; white-space: nowrap; }
    .vr-table td { padding: 7px 8px; border-bottom: 1px solid #f2f2f0; vertical-align: middle; }
    .vr-zone { display: inline-block; padding: 1px 9px; border-radius: 999px; font-size: .74rem; font-weight: 700; color: #fff; }
    .vr-zone.critical { background: #b91c1c; } .vr-zone.low { background: #1d4ed8; }
    .vr-zone.elevated { background: #b45309; } .vr-zone.ok { background: #15803d; }
    .vr-zone.stale, .vr-zone.none { background: #6c757d; }
    .vr-bpm { font-size: 1.15rem; font-weight: 700; }

    .vr-episode { border-left: 5px solid #cfd4da; background: #fbfbfa; border-radius: 10px; padding: 10px 14px; margin-bottom: 10px; }
    .vr-episode.crit { border-left-color: #b91c1c; }
    .vr-episode.low  { border-left-color: #1d4ed8; }
    .vr-episode.elev { border-left-color: #b45309; }
    .vr-episode.active { background: #fff1f1; box-shadow: 0 0 0 2px rgba(185,28,28,.18); }
    .vr-episode .who { font-weight: 700; }
    .vr-episode .meta { font-size: .82rem; color: #52514e; }
    .vr-episode .big { font-size: 1.25rem; font-weight: 700; }

    /* Focus mode. Mirrors war-room.php's .war-room-focus exactly — hide the
       chrome, give the content the whole width — because this page is meant
       to end up on the second screen of a command post next to the map, and
       a sidebar is not what anyone drove there to look at. */
    body.vr-focus .sidebar,
    body.vr-focus .sidebar-overlay,
    body.vr-focus .sidebar-toggle { display: none; }
    body.vr-focus .main-content { margin-left: 0; }

    /* Chart.js with responsive+maintainAspectRatio:false sizes a canvas to its
       PARENT, and writes an inline height while doing it — which beats any
       height set on the canvas itself. Both charts therefore live in a wrapper
       with a fixed height; without it the sparklines came out 256px instead of
       38 and every volunteer card grew sevenfold, turning the page into a
       7.000-pixel scroll. */
    .vr-chart-wrap { position: relative; height: 340px; }
    .vr-spark-wrap { position: relative; height: 40px; }
    .vr-spark { display: block; }
    .vr-teambar { height: 8px; border-radius: 999px; background: #eee; overflow: hidden; }
    .vr-teambar > span { display: block; height: 100%; background: #b45309; }
</style>

<!-- Everything inside #vrContent is what the auto-refresh replaces. The
     chart payload rides along as JSON inside it, so a refreshed fragment
     carries its own new data and vrDrawCharts() can simply run again —
     no page reload, which is what would otherwise throw a wall display
     out of fullscreen every thirty seconds. -->
<div class="container-fluid px-0" id="vrContent">

    <div class="vr-hero mb-4 d-flex justify-content-between align-items-start flex-wrap gap-3">
        <div>
            <div class="small opacity-75 mb-1"><i class="bi bi-heart-pulse-fill me-1"></i>ΑΝΑΦΟΡΑ ΠΑΛΜΩΝ</div>
            <h1><?= h($mission['title']) ?></h1>
            <div class="small opacity-75 mt-1">
                <?= h($mission['location'] ?? '') ?>
                <?php if (!empty($mission['department_name'])): ?> · <?= h($mission['department_name']) ?><?php endif; ?>
            </div>
        </div>
        <div class="text-end">
            <?php if ($isLive): ?>
                <span class="vr-live"><span class="vr-live-dot"></span>ΣΕ ΕΞΕΛΙΞΗ · ανανέωση κάθε 30''</span>
            <?php else: ?>
                <span class="vr-live">ΚΛΕΙΣΤΗ ΑΠΟΣΤΟΛΗ · ιστορικό</span>
            <?php endif; ?>
            <div class="small opacity-75 mt-2">Ενημερώθηκε <?= date('H:i:s') ?></div>
            <div class="mt-2 d-flex gap-2 justify-content-end">
                <button type="button" id="vrFocusToggle" class="btn btn-sm btn-outline-light"><i class="bi bi-arrows-fullscreen me-1"></i>Πλήρης Οθόνη</button>
                <a href="war-room.php?id=<?= $missionId ?>" class="btn btn-sm btn-outline-light"><i class="bi bi-arrow-left me-1"></i>Action Room</a>
            </div>
        </div>
    </div>

    <!-- Dashboard tiles -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-2"><div class="vr-tile ok"><div class="v"><?= (int) $now['summary']['wearing'] ?>/<?= (int) $now['summary']['expected'] ?></div><div class="l">Με ενεργό αισθητήρα</div></div></div>
        <div class="col-6 col-lg-2"><div class="vr-tile crit"><div class="v"><?= (int) $now['summary']['critical'] ?></div><div class="l">Σε ταχυκαρδία τώρα</div></div></div>
        <div class="col-6 col-lg-2"><div class="vr-tile low"><div class="v"><?= (int) $now['summary']['low'] ?></div><div class="l">Σε βραδυκαρδία τώρα</div></div></div>
        <div class="col-6 col-lg-2"><div class="vr-tile elev"><div class="v"><?= (int) $now['summary']['elevated'] ?></div><div class="l">Με αυξημένους παλμούς</div></div></div>
        <div class="col-6 col-lg-2"><div class="vr-tile mute"><div class="v"><?= (int) $now['summary']['stale'] ?></div><div class="l">Έχασαν σήμα</div></div></div>
        <div class="col-6 col-lg-2"><div class="vr-tile mute"><div class="v"><?= (int) $now['summary']['no_sensor'] ?></div><div class="l">Χωρίς αισθητήρα</div></div></div>
    </div>

    <!-- Active episodes first: this is the reason someone opens the page mid-mission -->
    <?php if ($activeEpisodes): ?>
    <div class="vr-card" style="border: 2px solid #b91c1c;">
        <h2 class="text-danger"><i class="bi bi-exclamation-octagon-fill"></i>Σε εξέλιξη τώρα (<?= count($activeEpisodes) ?>)</h2>
        <?php foreach ($activeEpisodes as $e): ?>
            <div class="vr-episode active <?= $episodeCls[$e['type']] ?>">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                    <div>
                        <span class="who"><?= h($e['name']) ?></span>
                        <?php if ($e['team_label']): ?> <span class="badge bg-secondary"><?= h($e['team_label']) ?></span><?php endif; ?>
                        <div class="meta">
                            <i class="bi <?= $episodeIcon[$e['type']] ?> me-1"></i><?= $episodeLabel[$e['type']] ?>
                            · από <?= h($e['from']) ?> (<?= $vMin($e['minutes']) ?>)
                            · μέσος <?= (int) $e['bpm_avg'] ?> bpm
                        </div>
                    </div>
                    <div class="text-end">
                        <div class="big text-danger"><?= (int) $e['bpm_peak'] ?> <small>bpm</small></div>
                        <div class="meta"><?= $e['type'] === 'bradycardia' ? 'ελάχιστο' : 'κορυφή' ?></div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Live table -->
    <div class="vr-card">
        <h2><i class="bi bi-people-fill text-primary"></i>Τώρα, ανά εθελοντή</h2>
        <?php if (empty($now['volunteers'])): ?>
            <p class="vr-empty">Δεν υπάρχουν εγκεκριμένοι συμμετέχοντες σε αυτή την αποστολή.</p>
        <?php else: ?>
        <p class="text-muted small">Ταξινομημένοι με τη χειρότερη κατάσταση πρώτη — σε τρέχουσα αποστολή διαβάζεις την κορυφή, όχι όλη τη λίστα.</p>
        <div class="table-responsive">
        <table class="vr-table">
            <thead><tr>
                <th>Εθελοντής</th><th>Ομάδα</th><th>Παλμοί</th><th>Κατάσταση</th><th>Τάση</th>
                <th>Συνεχόμενα</th><th>Τελευταία μέτρηση</th>
            </tr></thead>
            <tbody>
            <?php foreach ($now['volunteers'] as $v): ?>
                <tr>
                    <td><?= guestNameHtml($v['name'], $v['is_external'], $v['home_team_name'], $v['home_team_color'], $v['guest_country_code']) ?><?= k9BadgeHtml((int) $v['user_id'], false, 'el') ?><?= captainBadgeHtml((int) $v['user_id'], false, 'el') ?></td>
                    <td class="small text-muted"><?= h($v['team_label'] ?: '—') ?></td>
                    <td class="vr-bpm"><?= $v['bpm'] !== null ? (int) $v['bpm'] : '—' ?></td>
                    <td><span class="vr-zone <?= h($v['zone']) ?>"><?= $zoneLabel[$v['zone']] ?? $v['zone'] ?></span></td>
                    <td>
                        <?php if ($v['trend'] === 'up'): ?><span class="text-danger" title="ανεβαίνει τα τελευταία 10 λεπτά">▲</span>
                        <?php elseif ($v['trend'] === 'down'): ?><span class="text-primary" title="πέφτει τα τελευταία 10 λεπτά">▼</span>
                        <?php elseif ($v['trend'] === 'flat'): ?><span class="text-muted" title="σταθεροί">—</span>
                        <?php else: ?><span class="text-muted">·</span><?php endif; ?>
                    </td>
                    <td class="small"><?= $v['zone_minutes'] !== null ? $vMin((int) $v['zone_minutes']) : '—' ?></td>
                    <td class="small text-muted">
                        <?= $v['last_at'] ? h($v['last_at']) : '—' ?>
                        <?php if ($v['age_seconds'] !== null && $v['age_seconds'] > $config['stale_seconds']): ?>
                            <span class="text-warning">(<?= $vDur((int) $v['age_seconds']) ?> πριν)</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- Chart -->
    <div class="vr-card">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
            <h2 class="mb-0"><i class="bi bi-graph-up text-primary"></i>Πορεία παλμών</h2>
            <div class="btn-group btn-group-sm" role="group">
                <?php foreach (['2' => '2 ώρες', '8' => '8 ώρες', '24' => '24 ώρες', 'all' => 'Όλη η αποστολή'] as $wk => $wl): ?>
                <!-- (string) on the key, not a bare ===: PHP silently turns
                     numeric-string array keys into integers, so '8' === 8 is
                     false and no button ever looked selected. -->
                <a href="?id=<?= $missionId ?>&amp;window=<?= $wk ?>" class="btn btn-outline-secondary <?= $windowKey === (string) $wk ? 'active' : '' ?>"><?= $wl ?></a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php if (empty($report['volunteers'])): ?>
            <p class="vr-empty">Δεν έχουν καταγραφεί μετρήσεις ακόμη.</p>
        <?php else: ?>
            <div class="vr-chart-wrap"><canvas id="vrChart"></canvas></div>
            <p class="text-muted small mt-2 mb-0">
                Μέσος όρος ανά <?= (int) $report['bucket_minutes'] ?> <?= $report['bucket_minutes'] === 1 ? 'λεπτό' : 'λεπτά' ?>.
                Διακεκομμένες: κρίσιμοι <?= (int) $report['thresholds']['critical'] ?>, αυξημένοι <?= (int) $report['thresholds']['elevated'] ?>, χαμηλοί <?= (int) $report['thresholds']['low'] ?> bpm
                (μέγιστη καρδιακή συχνότητα <?= (int) $maxHr ?>).
            </p>
        <?php endif; ?>
    </div>

    <!-- Episode history -->
    <div class="vr-card">
        <h2><i class="bi bi-list-columns-reverse text-primary"></i>Έκτακτα συμβάντα παλμών<?= $pastEpisodes ? ' (' . count($pastEpisodes) . ')' : '' ?></h2>
        <p class="text-muted small">
            Ταχυκαρδία ≥ <?= (int) $config['tachy_bpm'] ?> bpm για ≥ <?= (int) $config['tachy_minutes'] ?> λεπτά ·
            βραδυκαρδία ≤ <?= (int) $config['brady_bpm'] ?> bpm για ≥ <?= (int) $config['brady_minutes'] ?> λεπτά.
            Η <strong>διάρκεια</strong> είναι που ξεχωρίζει το σήμα από τον θόρυβο: διασώστης που ανεβαίνει πλαγιά με εξοπλισμό αγγίζει στιγμιαία τους <?= $criticalBpm ?> συνεχώς — <?= (int) $config['tachy_minutes'] ?> λεπτά <em>πάνω</em> από αυτούς είναι εντελώς άλλη δήλωση.
            Η παρατεταμένη καταπόνηση είναι φορτίο, όχι συμβάν, και συνοψίζεται χωριστά παρακάτω.
        </p>
        <?php if (empty($pastEpisodes)): ?>
            <p class="vr-empty">Κανένα ολοκληρωμένο έκτακτο συμβάν παλμών.</p>
        <?php else: ?>
            <?php foreach ($pastEpisodes as $e): ?>
                <div class="vr-episode <?= $episodeCls[$e['type']] ?>">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                        <div>
                            <span class="who"><?= h($e['name']) ?></span>
                            <?php if ($e['team_label']): ?> <span class="badge bg-secondary"><?= h($e['team_label']) ?></span><?php endif; ?>
                            <div class="meta">
                                <i class="bi <?= $episodeIcon[$e['type']] ?> me-1"></i><?= $episodeLabel[$e['type']] ?>
                                · <?= h($e['date']) ?> <?= h($e['from']) ?>–<?= h($e['to']) ?> (<?= $vMin($e['minutes']) ?>)
                                · μέσος <?= (int) $e['bpm_avg'] ?> bpm
                            </div>
                        </div>
                        <div class="text-end">
                            <div class="big"><?= (int) $e['bpm_peak'] ?> <small>bpm</small></div>
                            <div class="meta"><?= $e['type'] === 'bradycardia' ? 'ελάχιστο' : 'κορυφή' ?></div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Sustained load, summarised per person rather than listed per spell -->
    <?php if ($strainByUser): ?>
    <div class="vr-card">
        <h2><i class="bi bi-hourglass-split text-warning"></i>Παρατεταμένη καταπόνηση</h2>
        <p class="text-muted small">
            Συνεχόμενος χρόνος πάνω από <?= $elevatedBpm ?> bpm σε περιόδους ≥ <?= (int) $config['strain_minutes'] ?> λεπτών.
            Σε ορεινή αποστολή αυτό αφορά σχεδόν όλους — γι' αυτό μετράει το <strong>σύνολο</strong> και όχι το πλήθος:
            δείχνει ποιος δούλεψε πιο σκληρά και ποιος χρειάζεται αντικατάσταση πρώτος.
        </p>
        <div class="table-responsive">
        <table class="vr-table">
            <thead><tr><th>Εθελοντής</th><th>Ομάδα</th><th>Συνολικά</th><th>Περίοδοι</th><th>Μεγαλύτερη</th><th>Κορυφή</th><th></th></tr></thead>
            <tbody>
            <?php $strainMax = max(array_column($strainByUser, 'minutes')) ?: 1; ?>
            <?php foreach ($strainByUser as $uid => $st): ?>
                <tr>
                    <td class="fw-bold"><?= h($st['name']) ?><?= k9BadgeHtml((int) $uid, false, 'el') ?><?= captainBadgeHtml((int) $uid, false, 'el') ?></td>
                    <td class="small text-muted"><?= h($st['team_label'] ?: '—') ?></td>
                    <td class="fw-bold"><?= $vMin((int) $st['minutes']) ?></td>
                    <td><?= (int) $st['spells'] ?></td>
                    <td><?= $vMin((int) $st['longest']) ?></td>
                    <td><?= (int) $st['peak'] ?> bpm</td>
                    <td style="min-width:130px;">
                        <div class="vr-teambar"><span style="width: <?= (int) round(($st['minutes'] / $strainMax) * 100) ?>%;"></span></div>
                        <?php if ($st['active']): ?><span class="badge bg-warning text-dark mt-1">σε εξέλιξη</span><?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Per volunteer -->
    <?php if (!empty($report['volunteers'])): ?>
    <div class="vr-card">
        <h2><i class="bi bi-person-lines-fill text-primary"></i>Ανά εθελοντή</h2>
        <p class="text-muted small">Τα νούμερα εδώ υπολογίζονται από <strong>κάθε μεμονωμένη μέτρηση</strong> (ανά <?= (int) $report['sample_seconds'] ?> δευτ.), όχι από τους μέσους όρους του γραφήματος — μια σύντομη αιχμή δεν πρέπει να εξαφανίζεται μέσα στον μέσο όρο του λεπτού της.</p>
        <div class="row g-3">
            <?php foreach ($report['volunteers'] as $i => $v):
                $own = array_values(array_filter($episodes, fn($e) => $e['user_id'] === $v['user_id']));
                $z = $v['zone_secs'];
            ?>
            <div class="col-12 col-xl-6">
                <div class="border rounded p-3 h-100">
                    <div class="d-flex justify-content-between align-items-start">
                        <div class="fw-bold"><?= h($v['name']) ?><?= k9BadgeHtml((int) $v['user_id'], false, 'el') ?><?= captainBadgeHtml((int) $v['user_id'], false, 'el') ?></div>
                        <div class="text-end small">
                            <span class="fw-bold fs-5"><?= (int) $v['bpm_avg'] ?></span> <small class="text-muted">μ.ο.</small><br>
                            <span class="<?= $v['bpm_min'] <= $report['thresholds']['low'] ? 'text-primary fw-bold' : 'text-muted' ?>"><?= (int) $v['bpm_min'] ?></span>
                            –
                            <span class="<?= $v['bpm_max'] >= $report['thresholds']['critical'] ? 'text-danger fw-bold' : 'text-muted' ?>"><?= (int) $v['bpm_max'] ?></span>
                        </div>
                    </div>
                    <div class="vr-spark-wrap mt-2"><canvas class="vr-spark" data-series="<?= h(json_encode($v['series'])) ?>"></canvas></div>
                    <div class="small mt-2">
                        <span class="text-success">Φυσιολογικοί <?= $vDur($z['ok']) ?></span> ·
                        <span class="<?= $z['elevated'] ? 'text-warning' : 'text-muted' ?>">Αυξημένοι <?= $vDur($z['elevated']) ?></span> ·
                        <span class="<?= $z['critical'] ? 'text-danger fw-bold' : 'text-muted' ?>">Ταχυκαρδία <?= $vDur($z['critical']) ?></span> ·
                        <span class="<?= $z['low'] ? 'text-primary fw-bold' : 'text-muted' ?>">Βραδυκαρδία <?= $vDur($z['low']) ?></span>
                    </div>
                    <?php if ($own): ?>
                    <div class="small text-muted mt-2">
                        <i class="bi bi-flag-fill me-1"></i><?= count($own) ?> επεισόδι<?= count($own) === 1 ? 'ο' : 'α' ?>:
                        <?= h(implode(' · ', array_map(fn($e) => $episodeLabel[$e['type']] . ' ' . $e['from'] . '–' . $e['to'], array_slice($own, 0, 4)))) ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Teams -->
    <?php if (count($teamLoad) > 1): ?>
    <div class="vr-card">
        <h2><i class="bi bi-diagram-3-fill text-primary"></i>Φόρτος ανά ομάδα</h2>
        <p class="text-muted small">Μέσος όρος πάνω σε κάθε καταγεγραμμένο λεπτό κάθε μέλους, όχι μέσος όρος των μέσων όρων — ομάδα όπου ένας έκανε όλη την ανάβαση δεν διαβάζεται ίδια με ομάδα που το μοιράστηκε.</p>
        <div class="table-responsive">
        <table class="vr-table">
            <thead><tr><th>Ομάδα</th><th>Άτομα</th><th>Μ.Ο.</th><th>Μέγιστο</th><th>Χρόνος σε αυξημένους</th><th>Επεισόδια</th><th></th></tr></thead>
            <tbody>
            <?php $worst = max(array_map(fn($t) => $t['bpm_avg'] ?? 0, $teamLoad)) ?: 1; ?>
            <?php foreach ($teamLoad as $t): ?>
                <tr>
                    <td class="fw-bold"><?= h($t['label']) ?></td>
                    <td><?= (int) $t['members'] ?></td>
                    <td class="vr-bpm"><?= $t['bpm_avg'] !== null ? (int) $t['bpm_avg'] : '—' ?></td>
                    <td><?= (int) $t['bpm_max'] ?></td>
                    <td><?= $vMin((int) $t['elevated_minutes']) ?></td>
                    <td class="<?= $t['episodes'] ? 'text-danger fw-bold' : 'text-muted' ?>"><?= (int) $t['episodes'] ?: '—' ?></td>
                    <td style="min-width:120px;"><div class="vr-teambar"><span style="width: <?= (int) round((($t['bpm_avg'] ?? 0) / $worst) * 100) ?>%;"></span></div></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
    <?php endif; ?>

    <p class="text-muted small">
        <i class="bi bi-info-circle me-1"></i>
        Οι μετρήσεις προέρχονται από αισθητήρα Bluetooth που φοράει ο ίδιος ο εθελοντής και είναι <strong>ένδειξη, όχι ιατρική διάγνωση</strong>.
        Οι ζώνες υπολογίζονται με κοινή ηλικία αναφοράς <?= (int) $config['reference_age'] ?> ετών (μέγιστη καρδιακή συχνότητα <?= (int) $maxHr ?> bpm).
        Δεδομένα υγείας — ορατά μόνο στο επιτελείο της αποστολής.
    </p>
<script type="application/json" id="vrChartData"><?= json_encode([
        'labels' => $report['labels'] ?? [],
        'volunteers' => array_map(fn($v) => ['name' => $v['name'], 'series' => $v['series']], $report['volunteers'] ?? []),
        'thresholds' => $report['thresholds'] ?? null,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
</div>

<script>
const VR_PALETTE = ['#2a78d6','#008300','#e87ba4','#eda100','#1baf7a','#eb6834','#4a3aa7','#e34948'];

// Built from the JSON block inside #vrContent rather than from PHP echoed
// straight into this script, so the auto-refresh below can swap that container
// and call this again. The alternative - re-running an inline <script> pulled
// out of fetched HTML - works, but it re-runs everything else in here too.
function vrDrawCharts() {
    const raw = document.getElementById('vrChartData');
    if (!raw) return;
    const d = JSON.parse(raw.textContent || '{}');
    if (!d.volunteers || !d.volunteers.length || !d.thresholds) return;

    const canvas = document.getElementById('vrChart');
    if (canvas) {
        // A swapped container gives a brand-new canvas, but a window resize
        // does not: destroy any chart still bound to this node first, or
        // Chart.js refuses to take it.
        const existing = Chart.getChart(canvas);
        if (existing) existing.destroy();

        const flat = v => new Array(d.labels.length).fill(v);
        // The three dashed guides are datasets because the UMD Chart.js build
        // this app loads ships no annotation plugin. They are filtered out of
        // the legend - an entry reading "critical" among real names looks like
        // one more person.
        const datasets = d.volunteers.map((v, i) => ({
            label: v.name, data: v.series,
            borderColor: VR_PALETTE[i % VR_PALETTE.length], backgroundColor: VR_PALETTE[i % VR_PALETTE.length],
            borderWidth: 2, pointRadius: 0, pointHitRadius: 8, tension: .25, spanGaps: false
        })).concat([
            {label: '__c', data: flat(d.thresholds.critical), borderColor: '#b91c1c', borderWidth: 1, borderDash: [6,4], pointRadius: 0},
            {label: '__e', data: flat(d.thresholds.elevated), borderColor: '#b45309', borderWidth: 1, borderDash: [6,4], pointRadius: 0},
            {label: '__l', data: flat(d.thresholds.low),      borderColor: '#1d4ed8', borderWidth: 1, borderDash: [6,4], pointRadius: 0}
        ]);

        new Chart(canvas, {
            type: 'line',
            data: {labels: d.labels, datasets},
            options: {
                responsive: true, maintainAspectRatio: false,
                // A wall display that re-animates every thirty seconds is a
                // distraction, not feedback.
                animation: false,
                interaction: {mode: 'nearest', axis: 'x', intersect: false},
                plugins: {
                    legend: {position: 'bottom', labels: {filter: i => !i.text.startsWith('__')}},
                    tooltip: {filter: i => !i.dataset.label.startsWith('__'),
                              callbacks: {label: c => c.dataset.label + ': ' + c.parsed.y + ' bpm'}}
                },
                // Not beginAtZero: no heart rate is near zero, and anchoring
                // there squashes every real line into the top of the chart.
                scales: {y: {suggestedMin: 40, suggestedMax: Math.max(180, d.thresholds.critical + 20), title: {display: true, text: 'bpm'}},
                         x: {ticks: {maxTicksLimit: 14, autoSkip: true}}}
            }
        });
    }

    // Sparklines: same series, stripped of every axis and label. At this size
    // the shape is the whole message - the numbers are printed beside it.
    document.querySelectorAll('.vr-spark').forEach(el => {
        const prev = Chart.getChart(el);
        if (prev) prev.destroy();
        const data = JSON.parse(el.dataset.series || '[]');
        new Chart(el, {
            type: 'line',
            data: {labels: data.map((_, i) => i), datasets: [{data, borderColor: '#b91c1c', borderWidth: 1.5, pointRadius: 0, tension: .3, spanGaps: false}]},
            options: {responsive: true, maintainAspectRatio: false, animation: false,
                      plugins: {legend: {display: false}, tooltip: {enabled: false}},
                      scales: {x: {display: false}, y: {display: false}}}
        });
    });
}
vrDrawCharts();

// Focus mode: body class plus the native Fullscreen API, tied to one button so
// a native Esc exit also brings the sidebar back rather than leaving it hidden
// with no visible way to undo it. Same arrangement as the Action Room's own
// hero button, deliberately - someone who has learnt one has learnt both.
//
// Delegated from document, not bound to the button, because the refresh below
// replaces that button along with the rest of the container; a listener on the
// old node would stop working after the first refresh.
function vrSetFocus(active) {
    document.body.classList.toggle('vr-focus', active);
    const btn = document.getElementById('vrFocusToggle');
    if (btn) btn.innerHTML = active
        ? '<i class="bi bi-fullscreen-exit me-1"></i>\u0388\u03be\u03bf\u03b4\u03bf\u03c2'
        : '<i class="bi bi-arrows-fullscreen me-1"></i>\u03a0\u03bb\u03ae\u03c1\u03b7\u03c2 \u039f\u03b8\u03cc\u03bd\u03b7';
}
document.addEventListener('click', e => {
    if (!e.target.closest('#vrFocusToggle')) return;
    const entering = !document.body.classList.contains('vr-focus');
    vrSetFocus(entering);
    if (entering) {
        if (document.documentElement.requestFullscreen) document.documentElement.requestFullscreen().catch(() => {});
    } else if (document.fullscreenElement) {
        document.exitFullscreen().catch(() => {});
    }
});
document.addEventListener('fullscreenchange', () => {
    if (!document.fullscreenElement) vrSetFocus(false);
});

<?php if ($isLive): ?>
// A page about who is in trouble right now must not go quietly stale while it
// sits on a second screen in the command post.
//
// It refreshes by fetching itself and swapping #vrContent rather than calling
// location.reload(), and that is not an optimisation. A reload unloads the
// document, which drops the browser out of fullscreen - so a wall display
// would have fallen out of fullscreen every thirty seconds, which is exactly
// the setup the fullscreen button exists for. Swapping also keeps the scroll
// position and avoids a white flash.
//
// The focus state survives because vr-focus lives on <body>, outside the
// swapped container, and the click handler is delegated from document.
async function vrRefresh() {
    try {
        const res = await fetch(location.href, {credentials: 'same-origin'});
        if (!res.ok) return;
        const doc = new DOMParser().parseFromString(await res.text(), 'text/html');
        const fresh = doc.getElementById('vrContent');
        const current = document.getElementById('vrContent');
        // A session that expired mid-shift returns the login page, which has
        // no #vrContent. Leave the last good render on screen rather than
        // blanking the command post's display.
        if (!fresh || !current) return;
        current.replaceWith(fresh);
        vrDrawCharts();
        // The swap brings a freshly rendered button, which PHP always prints
        // with the default label. Re-sync it from the state that actually
        // survived — the body class — or a display left in fullscreen would
        // start inviting the viewer to enter fullscreen again.
        vrSetFocus(document.body.classList.contains('vr-focus'));
    } catch (e) {
        // Offline, or the server blinked - keep the last good render.
    }
}
setInterval(() => { if (!document.hidden) vrRefresh(); }, 30000);
<?php endif; ?>
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
