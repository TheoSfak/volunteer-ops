<?php
/**
 * VolunteerOps - Ποιότητα GPS (v3.321.0)
 *
 * How good were the positions this mission recorded, and — in a drill — how
 * far off were they really. The computation lives in
 * includes/functions-gps-quality.php; this page only asks and draws.
 *
 * Greek only, like its neighbour mission-vitals-report.php: a command-post
 * report page, outside the Action Room's own bilingual scope. Command staff
 * only (canManageActionRoom), and available for a CLOSED mission too — the
 * question "how accurate was the drill" is asked after it ends.
 *
 * v3.338.0: «Κενά στην καταγραφή» — every stretch without a stored position,
 * and whether the server refused fixes inside it or none arrived at all.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/functions-gps-quality.php';
require_once __DIR__ . '/includes/ai-live.php'; // aiLiveCompassLabel()
requireLogin();

$user      = getCurrentUser();
$userId    = (int) $user['id'];
$missionId = (int) get('id');

$mission = dbFetchOne("SELECT * FROM missions WHERE id = ? AND deleted_at IS NULL", [$missionId]);
if (!$mission) {
    setFlash('error', 'Η αποστολή δεν βρέθηκε.');
    redirect('dashboard.php');
}
if (!canManageActionRoom($mission['responsible_user_id'] ? (int) $mission['responsible_user_id'] : null, $userId)) {
    setFlash('error', 'Η Ποιότητα GPS είναι διαθέσιμη μόνο στο επιτελείο της αποστολής.');
    redirect('mission-view.php?id=' . $missionId);
}

$overview = loadMissionGpsQuality($missionId);

// Gaps: a position counts as stale past three cadences (the app's own
// definition), and nothing under a minute is worth a row.
$cadenceSeconds = max(1, (int) getSetting('war_room_auto_ping_seconds', '180'));
$gapMinSeconds  = max(60, warRoomPingStaleThresholdSeconds());
$allGaps        = get('gaps') === 'all';
$gapReport      = loadMissionGpsGaps($missionId, $gapMinSeconds, $allGaps ? 500 : 25);

// Reference points to measure against: the mission's own dispatch points,
// because in a drill the natural "stand here" is a point the coordinator has
// already dropped on the map. Or any coordinates typed in.
$refPoints = [];
foreach (dbFetchAll(
    "SELECT id, geo, label FROM mission_dispatch_points WHERE mission_id = ? AND type = 'point' ORDER BY created_at DESC",
    [$missionId]
) as $row) {
    $geo = json_decode($row['geo'], true);
    if (is_array($geo) && isset($geo['lat'], $geo['lng']) && is_numeric($geo['lat']) && is_numeric($geo['lng'])) {
        $refPoints[(int) $row['id']] = ['label' => $row['label'] ?: ('#' . $row['id']), 'lat' => (float) $geo['lat'], 'lng' => (float) $geo['lng']];
    }
}

$refId  = (int) get('ref');
$refLat = is_numeric(get('ref_lat')) ? (float) get('ref_lat') : null;
$refLng = is_numeric(get('ref_lng')) ? (float) get('ref_lng') : null;
if ($refId && isset($refPoints[$refId])) {
    $refLat = $refPoints[$refId]['lat'];
    $refLng = $refPoints[$refId]['lng'];
}
$from = (string) get('from');
$to   = (string) get('to');
$validTime = fn($v) => (bool) preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $v);
$calibration = null;
$calibrationError = null;
if (get('run') === '1') {
    if ($refLat === null || $refLng === null || $refLat < -90 || $refLat > 90 || $refLng < -180 || $refLng > 180) {
        $calibrationError = 'Διαλέξτε σημείο αναφοράς ή συμπληρώστε συντεταγμένες.';
    } elseif (!$validTime($from) || !$validTime($to) || strtotime($from) >= strtotime($to)) {
        $calibrationError = 'Το χρονικό διάστημα δεν είναι έγκυρο.';
    } else {
        $calibration = computeGpsCalibration(
            $missionId, $refLat, $refLng,
            date('Y-m-d H:i:s', strtotime($from)), date('Y-m-d H:i:s', strtotime($to))
        );
    }
}
// Default window for a live mission: the last half hour, which is what a
// drill of "everybody stand here for five minutes" fits into comfortably.
if (!$validTime($from)) $from = date('Y-m-d\TH:i', time() - 1800);
if (!$validTime($to))   $to   = date('Y-m-d\TH:i');

$m = fn(?float $v) => $v === null ? '—' : number_format($v, 0, ',', '.') . ' μ.';
$reasonLabels = [
    'imprecise' => 'κακή ακρίβεια', 'implausible' => 'αδύνατο άλμα',
    'mock' => 'ψεύτικη τοποθεσία', 'too_old' => 'πολύ παλιό',
];
$duration = fn(int $s) => $s >= 3600
    ? sprintf('%d ώ. %02d′', intdiv($s, 3600), intdiv($s % 3600, 60))
    : sprintf('%d′%02d″', intdiv($s, 60), $s % 60);

$pageTitle = 'Ποιότητα GPS: ' . $mission['title'];
$clockMission = $mission; // the clock strip names its prefecture (includes/clock-place.php)
include __DIR__ . '/includes/header.php';
?>

<style>
    .gq-hero { background: linear-gradient(135deg, #0f766e, #172554); color: #fff; border-radius: 16px; padding: 20px 24px; }
    .gq-hero h1 { color: #fff; font-weight: 700; font-size: 1.5rem; margin: 0; }
    .gq-card { background: #fff; border-radius: 14px; box-shadow: 0 2px 10px rgba(0,0,0,.06); padding: 18px 20px; margin-bottom: 20px; }
    .gq-card h2 { font-size: 1.1rem; font-weight: 700; margin-bottom: 12px; display: flex; align-items: center; gap: 8px; }
    .gq-table th { font-size: .75rem; text-transform: uppercase; letter-spacing: .03em; color: #52514e; white-space: nowrap; }
    .gq-table td { vertical-align: middle; }
    .gq-num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
    .gq-bad { color: #b91c1c; font-weight: 700; }
    .gq-warn { color: #b45309; font-weight: 600; }
    .gq-good { color: #15803d; font-weight: 600; }
    .gq-muted { color: #6c757d; }
    .gq-help { font-size: .85rem; color: #52514e; }
    .gq-all td { border-top: 2px solid #212529; font-weight: 700; }
</style>

<div class="container-fluid px-0">
    <div class="gq-hero mb-4 d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <h1><i class="bi bi-crosshair me-2"></i>Ποιότητα GPS</h1>
            <div class="small opacity-75"><?= h($mission['title']) ?></div>
        </div>
        <a href="war-room.php?id=<?= $missionId ?>" class="btn btn-outline-light btn-sm"><i class="bi bi-arrow-left me-1"></i>Action Room</a>
    </div>

    <div class="gq-card">
        <h2><i class="bi bi-phone"></i>Ανά κινητό, σε όλη την αποστολή</h2>
        <?php if (!$overview): ?>
            <p class="gq-muted fst-italic mb-0">Κανείς δεν συμμετέχει στο Action Room αυτής της αποστολής.</p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm gq-table mb-2">
                <thead><tr>
                    <th>Εθελοντής</th><th>Συσκευή</th>
                    <th class="gq-num">Στίγματα</th><th class="gq-num">Εφαρμογή / Browser</th>
                    <th class="gq-num">Δηλωμένη ακρίβεια<br>(τυπική · 90%)</th>
                    <th class="gq-num">Μετακίνηση<br>εξομάλυνσης</th>
                    <th class="gq-num">Δορυφόροι · σήμα<br>(τυπικά)</th>
                    <th>Απορρίψεις</th>
                </tr></thead>
                <tbody>
                <?php foreach ($overview as $p): ?>
                    <tr>
                        <td><?= h($p['name']) ?><?php if (!$p['ticked']): ?><br><small class="gq-muted">χωρίς GPS τώρα — στίγματα από πριν</small><?php endif; ?><?php if ($p['last_gps_error']): ?><br><small class="gq-bad"><?= h(t('gps_error.row_' . $p['last_gps_error'])) ?></small><?php endif; ?></td>
                        <td class="small"><?= $p['device'] ? h($p['device']) : '<span class="gq-muted">' . ($p['browser'] > 0 ? 'Browser' : '—') . '</span>' ?></td>
                        <td class="gq-num <?= $p['fixes'] === 0 ? 'gq-bad' : '' ?>"><?= $p['fixes'] ?></td>
                        <td class="gq-num"><?= $p['native'] ?> / <?= $p['browser'] ?></td>
                        <td class="gq-num <?= ($p['acc_p90'] ?? 0) > 50 ? 'gq-warn' : '' ?>"><?= $m($p['acc_median']) ?> · <?= $m($p['acc_p90']) ?></td>
                        <td class="gq-num"><?= $m($p['shift_median']) ?></td>
                        <td class="gq-num small">
                            <?php if (!$p['sats_reported'] && $p['cn0_median'] === null): ?><span class="gq-muted">—</span>
                            <?php else: ?>
                                <?php // A receiver that never marks a satellite as "used" still reports signal strength (VopsGnssMonitor); it used to be hidden behind «—» like a phone that reported nothing. ?>
                                <?= $p['sats_reported'] ? (int) $p['sats_median'] : '<span class="gq-muted" title="Ο δέκτης δεν δηλώνει πόσους δορυφόρους χρησιμοποίησε">?</span>' ?> ·
                                <?php if ($p['cn0_median'] !== null): ?>
                                    <span class="<?= $p['cn0_median'] < 25 ? 'gq-bad' : ($p['cn0_median'] < 30 ? 'gq-warn' : 'gq-good') ?>"><?= number_format($p['cn0_median'], 0) ?> dB-Hz</span>
                                <?php else: ?><span class="gq-muted">—</span><?php endif; ?>
                                <?php if ($p['dual_pct'] !== null): ?><br><span class="gq-muted">2η συχνότητα <?= $p['dual_pct'] ?>%</span><?php endif; ?>
                                <?php if ($p['no_sat'] > 0): ?><br><span class="gq-warn">χωρίς δορυφόρο: <?= $p['no_sat'] ?></span><?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td class="small">
                            <?php if (!$p['refused_total']): ?><span class="gq-muted">καμία</span>
                            <?php else: ?>
                                <span class="<?= $p['refused_total'] >= 10 ? 'gq-bad' : 'gq-warn' ?>"><?= $p['refused_total'] ?></span>:
                                <?= h(implode(', ', array_map(fn($r, $c) => ($reasonLabels[$r] ?? $r) . ' ' . $c, array_keys($p['refusals']), $p['refusals']))) ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="gq-help mb-0"><strong>Δηλωμένη ακρίβεια</strong>: το ±μ. που δίνει το ίδιο το κινητό για κάθε στίγμα (τυπική τιμή και η τιμή που δεν ξεπερνά το 90% των στιγμάτων). <strong>Μετακίνηση εξομάλυνσης</strong>: πόσο μετατόπισε τυπικά το φίλτρο το στίγμα της συσκευής. <strong>Απορρίψεις</strong>: στίγματα που ο server δεν κατέγραψε, ανά λόγο — πολλές απορρίψεις «κακής ακρίβειας» δείχνουν κινητό ή σημείο χωρίς καλό σήμα, «αδύνατα άλματα» δείχνουν ανακλάσεις. Η συσκευή φαίνεται μόνο για την εφαρμογή Android· ο browser δεν λέει σε ποιο κινητό τρέχει.</p>
        <p class="gq-help mb-0 mt-2"><strong>Δορυφόροι · σήμα</strong> (μόνο εφαρμογή Android, από την έκδοση με αυτή τη στήλη): πόσους δορυφόρους χρησιμοποίησε τυπικά ο δέκτης σε κάθε στίγμα, και πόσο δυνατό ήταν το σήμα των τεσσάρων ισχυρότερων (dB-Hz). Γύρω στα <strong>35–45</strong> είναι ανοιχτός ουρανός· <strong>κάτω από 25</strong> σημαίνει κινητό σε τσέπη, κάτω από σκεπή ή μέσα σε σακίδιο — τότε φταίει το πού το κουβαλάει, όχι το κινητό. Καλό σήμα με κακή ακρίβεια δείχνει αδύναμο δέκτη ή ανακλάσεις από κτίρια. <strong>2η συχνότητα</strong>: πόσα στίγματα χρησιμοποίησαν και δεύτερη συχνότητα (L5/E5a), που περιορίζει τις ανακλάσεις — το υποστηρίζουν μόνο ορισμένα κινητά. <strong>Χωρίς δορυφόρο</strong>: στίγματα που το Android έδωσε από Wi-Fi/κεραίες ενώ ο δέκτης δεν είχε κανέναν δορυφόρο.</p>
        <?php endif; ?>
    </div>

    <div class="gq-card">
        <h2><i class="bi bi-signpost-split"></i>Κενά στην καταγραφή</h2>
        <?php if (!$gapReport['gaps']): ?>
            <p class="gq-muted fst-italic mb-0">Κανένα κενό <?= h($duration($gapMinSeconds)) ?> ή μεγαλύτερο σε αυτή την αποστολή.</p>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm gq-table mb-2">
                <thead><tr>
                    <th>Εθελοντής</th><th>Από – Έως</th>
                    <th class="gq-num">Διάρκεια</th><th class="gq-num">Ευθεία<br>στον χάρτη</th>
                    <th>Τι έγινε</th>
                </tr></thead>
                <tbody>
                <?php foreach ($gapReport['gaps'] as $g):
                    $refusedTotal = array_sum($g['refused']);
                    $expected = max(1, (int) round($g['seconds'] / $cadenceSeconds) - 1); ?>
                    <tr>
                        <td><?= h($g['name']) ?></td>
                        <td class="small text-nowrap"><?= date('d/m H:i:s', $g['from']) ?> – <?= date(date('Y-m-d', $g['from']) === date('Y-m-d', $g['to']) ? 'H:i:s' : 'd/m H:i:s', $g['to']) ?></td>
                        <td class="gq-num"><?= h($duration($g['seconds'])) ?></td>
                        <td class="gq-num"><?= $m($g['line_m']) ?></td>
                        <td class="small">
                            <?php if ($refusedTotal > 0): ?>
                                <?php // "of ~N expected" only when fewer were refused than the cadence implies: the rest never arrived. The cadence is today's setting, so it is not a count to argue with when it is exceeded. ?>
                                <span class="gq-warn">Απορρίφθηκαν <?= $refusedTotal ?></span><?= $refusedTotal < $expected ? ' από ~' . $expected . ' αναμενόμενα' : '' ?>:
                                <?= h(implode(', ', array_map(function ($reason, $count) use ($g, $reasonLabels) {
                                    $label = ($reasonLabels[$reason] ?? $reason) . ' ' . $count;
                                    if ($reason === 'imprecise' && $g['acc_min'] !== null) {
                                        $label .= ' (±' . round($g['acc_min']) . ($g['acc_max'] - $g['acc_min'] >= 1 ? '–' . round($g['acc_max']) : '') . ' μ.)';
                                    } elseif ($reason === 'implausible' && $g['kmh_max'] !== null) {
                                        $label .= ' (έως ' . round($g['kmh_max']) . ' km/h)';
                                    }
                                    return $label;
                                }, array_keys($g['refused']), $g['refused']))) ?>
                            <?php elseif ($g['logged']): ?>
                                <span class="gq-bad">Δεν έφτασε κανένα στίγμα</span> — το κινητό δεν έστελνε
                            <?php else: ?>
                                <span class="gq-muted">Άγνωστο — πριν ξεκινήσει η καταγραφή απορρίψεων</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($gapReport['total'] > count($gapReport['gaps'])): ?>
            <p class="gq-help mb-2">Εμφανίζονται τα <?= count($gapReport['gaps']) ?> πιο πρόσφατα από <?= $gapReport['total'] ?>.<?php if (!$allGaps): ?> <a href="mission-gps-quality.php?id=<?= $missionId ?>&amp;gaps=all">Όλα τα κενά</a><?php endif; ?></p>
        <?php endif; ?>
        <p class="gq-help mb-0">Διαστήματα <?= h($duration($gapMinSeconds)) ?> και πάνω χωρίς αποθηκευμένο στίγμα. <strong>Ευθεία στον χάρτη</strong>: η γραμμή που τραβάει η Πορεία πάνω από το κενό — ό,τι έγινε ανάμεσα δεν φαίνεται. <strong>Απορρίφθηκαν</strong>: τα στίγματα έφταναν, αλλά ο server τα κράτησε έξω· με «κακή ακρίβεια» το ±μ. του κινητού ξεπερνούσε το όριο του Ρυθμίσεις → Action Room, με «αδύνατο άλμα» η θέση πήδηξε πιο γρήγορα απ' όσο επιτρέπει το όριο ταχύτητας. Λίγες απορρίψεις σε μεγάλο κενό σημαίνει ότι και τα περισσότερα στίγματα δεν έφτασαν. <strong>Δεν έφτασε κανένα στίγμα</strong>: το κινητό δεν έστελνε — χωρίς δορυφόρους, εφαρμογή ή σελίδα κλειστή, GPS σε παύση ή εκτός Action Room.</p>
        <?php endif; ?>
    </div>

    <div class="gq-card">
        <h2><i class="bi bi-bullseye"></i>Έλεγχος ακρίβειας σε σημείο αναφοράς</h2>
        <p class="gq-help">Σε άσκηση: οι εθελοντές στέκονται 3–5 λεπτά σε γνωστό σημείο, με την εφαρμογή ή τη σελίδα ανοιχτή. Εδώ διαλέγετε το σημείο και το διάστημα, και για κάθε κινητό βλέπετε πόσο απείχαν πραγματικά τα στίγματά του — όχι πόσο δήλωνε ότι απέχουν.</p>
        <form method="get" class="row g-2 align-items-end mb-3">
            <input type="hidden" name="id" value="<?= $missionId ?>">
            <input type="hidden" name="run" value="1">
            <div class="col-md-4">
                <label class="form-label small mb-1" for="gqRef">Σημείο αναφοράς</label>
                <select name="ref" id="gqRef" class="form-select form-select-sm">
                    <option value="">— συντεταγμένες δεξιά —</option>
                    <?php foreach ($refPoints as $id => $rp): ?>
                        <option value="<?= $id ?>" <?= $refId === $id ? 'selected' : '' ?>><?= h($rp['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-1" for="gqLat">Γεωγρ. πλάτος</label>
                <input type="text" inputmode="decimal" name="ref_lat" id="gqLat" class="form-control form-control-sm" value="<?= $refLat !== null && !$refId ? h((string) $refLat) : '' ?>" placeholder="35.33">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-1" for="gqLng">Γεωγρ. μήκος</label>
                <input type="text" inputmode="decimal" name="ref_lng" id="gqLng" class="form-control form-control-sm" value="<?= $refLng !== null && !$refId ? h((string) $refLng) : '' ?>" placeholder="25.13">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-1" for="gqFrom">Από</label>
                <input type="datetime-local" name="from" id="gqFrom" class="form-control form-control-sm" value="<?= h($from) ?>">
            </div>
            <div class="col-6 col-md-2">
                <label class="form-label small mb-1" for="gqTo">Έως</label>
                <input type="datetime-local" name="to" id="gqTo" class="form-control form-control-sm" value="<?= h($to) ?>">
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-calculator me-1"></i>Υπολογισμός</button>
            </div>
        </form>

        <?php if ($calibrationError): ?>
            <div class="alert alert-warning py-2 mb-0"><?= h($calibrationError) ?></div>
        <?php elseif ($calibration !== null && !$calibration['people']): ?>
            <p class="gq-muted fst-italic mb-0">Κανένα στίγμα σε αυτό το διάστημα.</p>
        <?php elseif ($calibration !== null): ?>
        <div class="table-responsive">
            <table class="table table-sm gq-table mb-2">
                <thead><tr>
                    <th>Εθελοντής</th><th class="gq-num">Στίγματα</th>
                    <th class="gq-num">Πραγματικό σφάλμα<br>(τυπικό · 95%)</th>
                    <th class="gq-num">Μετά την εξομάλυνση<br>(τυπικό · 95%)</th>
                    <th class="gq-num">Ειλικρινές ±</th>
                    <th class="gq-num">Σταθερή απόκλιση</th>
                </tr></thead>
                <tbody>
                <?php foreach (array_merge($calibration['people'], [array_merge($calibration['all'], ['name' => 'Όλα τα κινητά', '_all' => true])]) as $c):
                    $better = $c['est_median'] !== null && $c['raw_median'] !== null && $c['est_median'] < $c['raw_median'] - 0.5;
                    $honestCls = $c['honest_pct'] === null ? 'gq-muted' : ($c['honest_pct'] >= 60 ? 'gq-good' : ($c['honest_pct'] >= 40 ? 'gq-warn' : 'gq-bad')); ?>
                    <tr class="<?= !empty($c['_all']) ? 'gq-all' : '' ?>">
                        <td><?= h($c['name']) ?></td>
                        <td class="gq-num"><?= $c['fixes'] ?></td>
                        <td class="gq-num <?= ($c['raw_p95'] ?? 0) > 50 ? 'gq-warn' : '' ?>"><?= $m($c['raw_median']) ?> · <?= $m($c['raw_p95']) ?></td>
                        <td class="gq-num <?= $better ? 'gq-good' : '' ?>"><?= $m($c['est_median']) ?> · <?= $m($c['est_p95']) ?></td>
                        <td class="gq-num <?= $honestCls ?>"><?= $c['honest_pct'] === null ? '—' : $c['honest_pct'] . '%' ?></td>
                        <td class="gq-num"><?= $m($c['bias_m']) ?><?= $c['bias_m'] >= 3 ? ' ' . h(aiLiveCompassLabel($c['bias_deg'])) : '' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="gq-help mb-0"><strong>Πραγματικό σφάλμα</strong>: απόσταση του στίγματος της συσκευής από το σημείο αναφοράς — τυπική τιμή και η τιμή που δεν ξεπερνά το 95% των στιγμάτων (η «χειρότερη λογική περίπτωση»). <strong>Μετά την εξομάλυνση</strong>: το ίδιο για τη θέση που αποθηκεύεται και βλέπει ο χάρτης· πράσινο όταν είναι καλύτερη. <strong>Ειλικρινές ±</strong>: σε πόσα στίγματα το πραγματικό σφάλμα ήταν μέσα στο ±μ. που δήλωσε το κινητό· σωστό είναι γύρω στο 68%, πολύ χαμηλότερα σημαίνει κινητό που δηλώνει μεγαλύτερη βεβαιότητα απ' όση έχει. <strong>Σταθερή απόκλιση</strong>: ο μέσος όρος όλων των στιγμάτων απέχει τόσο από το σημείο, προς εκείνη την κατεύθυνση — τέτοιο σφάλμα δεν διορθώνεται με εξομάλυνση. Αν όλα τα κινητά αποκλίνουν μαζί προς την ίδια κατεύθυνση, ελέγξτε πρώτα αν το ίδιο το σημείο αναφοράς είναι σωστά τοποθετημένο.</p>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
