<?php
/**
 * VolunteerOps — one-page team debrief sheet, printable, bilingual.
 *
 * The thing you physically hand a crew after an exercise. Built for foreign
 * guest teams above all: they cannot open mission-stats.php or
 * mission-report-print.php at all (both are gated on missions_manage or the
 * mission responsible), so without this page a visiting team leaves with
 * nothing in writing about their own performance.
 *
 * SCOPE, enforced in three places rather than trusted once: this page renders
 * only this team, buildTeamAiDigest() sends the model only this team, and the
 * prompt forbids ranking or comparison outright. No other crew's weaknesses
 * leave the organisation on a sheet of paper.
 *
 * Its own labels live in the $L array below rather than in
 * includes/lang/war-room.php, which is scoped to the Action Room by its own
 * docblock, and rather than in a new global translation file for the
 * twenty-odd strings one page needs. Both languages sit side by side per key
 * so a missing translation shows up in a diff, which is the same rule the
 * Action Room file follows.
 *
 * Same permission gate as the report it belongs to. Read-only: it never
 * generates anything, it prints what an admin already generated.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/ai-observer-render.php';
require_once __DIR__ . '/includes/mission-score-explain.php';

requireLogin();

$userId    = getCurrentUserId();
$missionId = (int) get('mission_id');
$teamId    = (int) get('team_id');
$lang      = get('lang') === 'en' ? 'en' : 'el';

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
if (!hasPagePermission('missions_manage') && (int) $mission['responsible_user_id'] !== $userId) {
    setFlash('error', 'Η αναφορά αυτή είναι διαθέσιμη μόνο σε διαχειριστές.');
    redirect('mission-view.php?id=' . $missionId);
}
if (!in_array($mission['status'], [STATUS_CLOSED, STATUS_COMPLETED], true)) {
    setFlash('error', 'Διαθέσιμο μόνο για κλειστές ή ολοκληρωμένες αποστολές.');
    redirect('mission-view.php?id=' . $missionId);
}

$team = dbFetchOne("SELECT * FROM mission_teams WHERE id = ? AND mission_id = ?", [$teamId, $missionId]);
if (!$team) {
    setFlash('error', 'Η ομάδα δεν βρέθηκε σε αυτή την αποστολή.');
    redirect('mission-stats.php?id=' . $missionId);
}

$debrief = loadTeamAiDebrief($missionId, $teamId, $lang);
if (!$debrief) {
    setFlash('error', 'Δεν έχει δημιουργηθεί φύλλο απολογισμού για αυτή την ομάδα σε αυτή τη γλώσσα.');
    redirect('mission-stats.php?id=' . $missionId);
}

// The handful of figures printed beside the assessment, so the crew can see
// what the judgement was built on. Same source as every other report page.
$report = computeMissionResponseReport($missionId);
$score  = computeMissionScore($missionId, $report);
$teamScore = null;
foreach ($score['teams'] as $t) {
    if ((int) $t['team_id'] === $teamId) { $teamScore = $t; break; }
}

$memberRows = dbFetchAll(
    "SELECT u.is_external, u.guest_org_name, u.guest_country_code
     FROM mission_team_members mtm JOIN users u ON u.id = mtm.user_id
     WHERE mtm.mission_id = ? AND mtm.team_id = ?",
    [$missionId, $teamId]
);
$guestOrgs = [];
$countryCode = null;
foreach ($memberRows as $m) {
    if ((int) $m['is_external'] === 1 && !empty($m['guest_org_name'])) {
        $guestOrgs[$m['guest_org_name']] = true;
        $countryCode = $countryCode ?: $m['guest_country_code'];
    }
}

$L = [
    'title'        => ['el' => 'Φύλλο Απολογισμού Ομάδας',      'en' => 'Team Debrief Sheet'],
    'exercise'     => ['el' => 'Άσκηση',                        'en' => 'Exercise'],
    'team'         => ['el' => 'Ομάδα',                         'en' => 'Team'],
    'date'         => ['el' => 'Ημερομηνία',                    'en' => 'Date'],
    'duration'     => ['el' => 'Διάρκεια',                      'en' => 'Duration'],
    'hours'        => ['el' => 'ώρες',                          'en' => 'hours'],
    'members'      => ['el' => 'Μέλη',                          'en' => 'Members'],
    'key_figures'  => ['el' => 'Βασικά Μεγέθη',                 'en' => 'Key Figures'],
    'orders'       => ['el' => 'Εντολές',                       'en' => 'Orders'],
    'completed'    => ['el' => 'Ολοκληρωμένες',                 'en' => 'Completed'],
    'avg_ack'      => ['el' => 'Μ.Ο. επιβεβαίωσης (λεπ.)',      'en' => 'Avg. acknowledgement (min)'],
    'unanswered'   => ['el' => 'Χωρίς απάντηση',                'en' => 'Never answered'],
    'waypoints'    => ['el' => 'Σταθμοί διαδρομής',             'en' => 'Route stops'],
    'sectors'      => ['el' => 'Τομείς έρευνας',                'en' => 'Search sectors'],
    'shortages'    => ['el' => 'Αναφορές έλλειψης',             'en' => 'Shortage reports'],
    'sos'          => ['el' => 'Σήματα SOS / περιστατικά',      'en' => 'SOS alerts / incidents'],
    'score'        => ['el' => 'Βαθμολογία',                    'en' => 'Score'],
    'assessment'   => ['el' => 'Αξιολόγηση',                    'en' => 'Assessment'],
    'small_sample' => ['el' => 'Περιορισμένο δείγμα μετρήσεων — η βαθμολογία είναι ενδεικτική.', 'en' => 'Limited measurement sample — the score is indicative only.'],
    'how_built'    => ['el' => 'Πώς προκύπτει η βαθμολογία', 'en' => 'How this score is built'],
    'how_intro'    => [
        'el' => 'Κάθε τομέας βαθμολογείται από όσα έκανε πραγματικά η ομάδα και μετά σταθμίζεται με το πόσες παρατηρήσεις (εντολές, αναφορές, σημεία πορείας, τομείς) τον στηρίζουν. Μια τέλεια επίδοση σε μία μόνο εντολή αποδεικνύει λιγότερα από μια σταθερά καλή επίδοση σε εννέα· γι\' αυτό ένα σύντομο ιστορικό πλησιάζει το ουδέτερο επίπεδο ' . (int) MISSION_SCORE_EVIDENCE_BASELINE . ', ενώ ένα μακρύ μένει σχεδόν ανέγγιχτο. Οι τομείς χωρίς δεδομένα δεν μετρούν — ούτε υπέρ ούτε εναντίον.',
        'en' => 'Each area is scored from what the team actually did, then weighted by how many observations (orders, reports, route stops, sectors) stand behind it. A perfect result on a single order proves less than a steady result on nine, so a short record is pulled toward a neutral ' . (int) MISSION_SCORE_EVIDENCE_BASELINE . ' while a long record is left almost untouched. Areas with no data are simply not counted — they never help or hurt.',
    ],
    'area'         => ['el' => 'Τομέας', 'en' => 'Area'],
    'raw'          => ['el' => 'Ακατέργαστος', 'en' => 'Raw'],
    'obs'          => ['el' => 'Παρατηρήσεις', 'en' => 'Observations'],
    'weighted'     => ['el' => 'Μετά τη στάθμιση', 'en' => 'Weighted'],
    'weight'       => ['el' => 'Βάρος', 'en' => 'Weight'],
    'total'        => ['el' => 'Σύνολο', 'en' => 'Total'],
    'evidence'     => ['el' => 'Βεβαιότητα', 'en' => 'Confidence'],
    'ev_low'       => ['el' => 'Χαμηλή', 'en' => 'Low'],
    'ev_medium'    => ['el' => 'Μέτρια', 'en' => 'Medium'],
    'ev_high'      => ['el' => 'Υψηλή', 'en' => 'High'],
    'adj_down'     => ['el' => 'Ακατέργαστος βαθμός {raw}, μετά τη στάθμιση {score}: η επίδοση είναι ισχυρή αλλά το ιστορικό σύντομο, οπότε βαραίνει λιγότερο μέχρι να μεγαλώσει.', 'en' => 'Raw score {raw}, weighted {score}: the record is strong but short, so it counts for less until it grows.'],
    'adj_up'       => ['el' => 'Ακατέργαστος βαθμός {raw}, μετά τη στάθμιση {score}: το ιστορικό είναι σύντομο, οπότε λίγες αποτυχίες δεν θεωρούνται το τελικό συμπέρασμα για την ομάδα.', 'en' => 'Raw score {raw}, weighted {score}: the record is short, so a few misses are not treated as the final word on the team.'],
    'adj_same'     => ['el' => 'Το ιστορικό είναι αρκετά μακρύ ώστε η στάθμιση να αλλάξει τον βαθμό κατά λιγότερο από 0,1.', 'en' => 'The record is long enough that weighting changed the score by less than 0.1.'],
    'p_response'   => ['el' => 'Ταχύτητα Απόκρισης', 'en' => 'Response speed'],
    'p_completion' => ['el' => 'Ολοκλήρωση Εντολών', 'en' => 'Order completion'],
    'p_shortage'   => ['el' => 'Διαχείριση Ελλείψεων', 'en' => 'Shortage handling'],
    'p_discipline' => ['el' => 'Πειθαρχία Πεδίου', 'en' => 'Field discipline'],
    'not_scored'   => ['el' => 'Δεν υπολογίστηκε', 'en' => 'Not measured'],
    'print'        => ['el' => 'Εκτύπωση / PDF',                'en' => 'Print / PDF'],
    'close'        => ['el' => 'Κλείσιμο',                      'en' => 'Close'],
    'preview'      => ['el' => 'Προεπισκόπηση Εκτύπωσης',       'en' => 'Print preview'],
    'footer_note'  => [
        'el' => 'Η αξιολόγηση αφορά αποκλειστικά αυτή την ομάδα. Δεν περιέχει σύγκριση ή κατάταξη έναντι άλλων ομάδων της άσκησης.',
        'en' => 'This assessment covers this team only. It contains no comparison with, or ranking against, the other teams in the exercise.',
    ],
];
$x = fn(string $key) => $L[$key][$lang] ?? $L[$key]['el'] ?? $key;

$orgName   = getSetting('org_name', 'VolunteerOps');
$appLogo   = getSetting('app_logo', '');
$hasLogo   = !empty($appLogo) && file_exists(__DIR__ . '/uploads/logos/' . $appLogo);
$printDate = date('d/m/Y H:i');
$teamLabel = teamLabel($team['codename'], $team['team_number']);
$teamColor = $team['color'] ?: '#172554';

$startTs = strtotime((string) $mission['start_datetime']);
$endTs   = strtotime((string) $mission['end_datetime']);
$hours   = ($startTs && $endTs && $endTs > $startTs) ? round(($endTs - $startTs) / 3600, 1) : null;

$fw = $teamScore['fieldwork'] ?? [];
$p  = $teamScore['pillars'] ?? [];
$section = $debrief['payload']['debrief'];
?><!DOCTYPE html>
<html lang="<?= h($lang) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($x('title')) ?> — <?= h($teamLabel) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', Arial, Helvetica, sans-serif; font-size: 10pt; color: #1a1a1a; background: #f4f4f2; padding: 15mm 12mm; -webkit-print-color-adjust: exact; print-color-adjust: exact; }

        .screen-notice { position: fixed; top: 0; left: 0; right: 0; background: #172554; color: #fff; padding: 8px 14px; display: flex; align-items: center; gap: 12px; font-size: 9pt; z-index: 50; }
        .screen-notice button { background: #fff; color: #172554; border: 0; border-radius: 6px; padding: 4px 12px; font-weight: 700; cursor: pointer; font-family: inherit; }
        .screen-notice .hint { opacity: .8; }

        .td-hero { background: linear-gradient(135deg, #172554, var(--team-color, #b91c1c)); color: #fff; border-radius: 16px; padding: 24px 26px; margin-top: 36px; margin-bottom: 14px; page-break-inside: avoid; }
        .td-hero .org { font-size: 9pt; opacity: .85; display: flex; align-items: center; gap: 8px; }
        .td-hero .org img { height: 26px; width: auto; border-radius: 5px; }
        .td-hero .kicker { font-size: 8.5pt; letter-spacing: .1em; text-transform: uppercase; opacity: .85; margin-top: 10px; }
        .td-hero .title { font-size: 21pt; font-weight: 800; margin: 2px 0 6px; }
        .td-hero .sub { font-size: 9.5pt; opacity: .92; line-height: 1.65; }

        .td-card { background: #fff; border: 1px solid #eee; border-radius: 14px; padding: 18px 20px; margin-bottom: 12px; page-break-inside: avoid; }
        .td-card h2 { font-size: 12pt; font-weight: 800; margin-bottom: 12px; color: #172554; }

        .td-figs { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; }
        .td-fig { background: #fafaf9; border: 1px solid #eee; border-radius: 10px; padding: 10px 12px; }
        .td-fig .v { font-size: 15pt; font-weight: 800; line-height: 1.1; color: #172554; }
        .td-fig .l { font-size: 7.5pt; color: #6b665c; margin-top: 3px; line-height: 1.35; }

        .td-score { display: flex; align-items: baseline; gap: 10px; margin-bottom: 10px; }
        .td-score .n { font-size: 26pt; font-weight: 800; line-height: 1; }
        .td-score .t { font-size: 10pt; font-weight: 700; }
        .td-note { font-size: 8pt; color: #6b665c; margin-top: 4px; }

        .td-p { font-size: 8.6pt; line-height: 1.6; color: #33322d; margin-bottom: 10px; }
        .td-table { width: 100%; border-collapse: collapse; font-size: 8.4pt; margin-bottom: 8px; }
        .td-table th { text-align: left; font-weight: 700; color: #6b665c; border-bottom: 1px solid #d9d5cc; padding: 4px 6px; }
        .td-table td { border-bottom: 1px solid #eeece6; padding: 4px 6px; }
        .td-table th.n, .td-table td.n { text-align: right; white-space: nowrap; }
        .td-table tfoot td { border-top: 2px solid #d9d5cc; border-bottom: 0; background: #f6f5f1; font-weight: 700; }

        .td-foot { font-size: 7.5pt; color: #7a746a; text-align: center; margin-top: 10px; line-height: 1.6; }

<?= aiObserverStyles() ?>
        .aio-wrap { border-left-color: var(--team-color, #5b6bbf); font-size: 9.5pt; page-break-inside: avoid; }
        .aio-meta { font-size: 7pt; }

        @media print {
            .screen-notice { display: none !important; }
            body { padding: 0; background: #fff; }
            @page { size: A4 portrait; margin: 14mm 12mm; }
            .td-hero { margin-top: 0; }
        }
    </style>
</head>
<body style="--team-color: <?= h($teamColor) ?>;">

<div class="screen-notice">
    <span><?= h($x('preview')) ?> &mdash; <?= h($x('title')) ?></span>
    <button onclick="window.print()"><?= h($x('print')) ?></button>
    <button onclick="vopsClosePage()"><?= h($x('close')) ?></button>
</div>

<div class="td-hero">
    <div class="org">
        <?php if ($hasLogo): ?><img src="uploads/logos/<?= h($appLogo) ?>" alt=""><?php endif; ?>
        <span><?= h($orgName) ?></span>
    </div>
    <div class="kicker"><?= h($x('title')) ?></div>
    <div class="title"><?= h($teamLabel) ?><?= $countryCode ? ' ' . flagHtml($countryCode) : '' ?></div>
    <div class="sub">
        <?php if ($guestOrgs): ?><?= h(implode(', ', array_keys($guestOrgs))) ?><br><?php endif; ?>
        <?= h($x('exercise')) ?>: <strong><?= h($mission['title']) ?></strong><br>
        <?= h($x('date')) ?>: <?= $startTs ? date('d/m/Y', $startTs) : '—' ?>
        <?php if ($hours !== null): ?> &middot; <?= h($x('duration')) ?>: <?= $hours ?> <?= h($x('hours')) ?><?php endif; ?>
        &middot; <?= h($x('members')) ?>: <?= count($memberRows) ?>
    </div>
</div>

<div class="td-card">
    <h2><?= h($x('key_figures')) ?></h2>
    <?php if ($teamScore !== null): ?>
    <div class="td-score">
        <span class="n" style="color:<?= h($teamColor) ?>;"><?= number_format((float) $teamScore['score'], 1) ?></span>
        <span class="t"><?= h($x('score')) ?> / 100</span>
    </div>
    <?php if (empty($teamScore['ranked'])): ?>
    <div class="td-note"><?= h($x('small_sample')) ?></div>
    <?php endif; ?>
    <?php endif; ?>

    <div class="td-figs" style="margin-top:12px;">
        <div class="td-fig">
            <div class="v"><?= (int) ($teamScore['order_count'] ?? 0) ?></div>
            <div class="l"><?= h($x('orders')) ?></div>
        </div>
        <div class="td-fig">
            <div class="v"><?= isset($p['completion']['raw']['fulfilled']) ? (int) $p['completion']['raw']['fulfilled'] : '—' ?></div>
            <div class="l"><?= h($x('completed')) ?></div>
        </div>
        <div class="td-fig">
            <div class="v"><?= isset($p['response']['raw']['avg_minutes']) && $p['response']['raw']['avg_minutes'] !== null ? number_format((float) $p['response']['raw']['avg_minutes'], 1) : '—' ?></div>
            <div class="l"><?= h($x('avg_ack')) ?></div>
        </div>
        <div class="td-fig">
            <div class="v"><?= (int) ($teamScore['unanswered_count'] ?? 0) ?></div>
            <div class="l"><?= h($x('unanswered')) ?></div>
        </div>
        <div class="td-fig">
            <div class="v"><?= (int) ($fw['waypoints_completed'] ?? 0) ?> / <?= (int) ($fw['waypoints_total'] ?? 0) ?></div>
            <div class="l"><?= h($x('waypoints')) ?></div>
        </div>
        <div class="td-fig">
            <div class="v"><?= (int) ($fw['sectors_completed'] ?? 0) ?> / <?= (int) ($fw['sectors_assigned'] ?? 0) ?></div>
            <div class="l"><?= h($x('sectors')) ?></div>
        </div>
        <div class="td-fig">
            <div class="v"><?= (int) ($teamScore['shortage_count'] ?? 0) ?></div>
            <div class="l"><?= h($x('shortages')) ?></div>
        </div>
        <div class="td-fig">
            <div class="v"><?= (int) ($fw['sos_alerts'] ?? 0) ?> / <?= (int) ($fw['incidents'] ?? 0) ?></div>
            <div class="l"><?= h($x('sos')) ?></div>
        </div>
    </div>
</div>

<?php if ($teamScore !== null):
    // Same rows the leaderboard page prints, minus the Greek-only "what was
    // measured" prose. Only THIS team's numbers appear: this sheet never
    // carries a comparison with any other crew.
    $sxRows = array_values(array_filter(missionTeamScoreRows($teamScore), fn($r) => $r['available']));
    $adjDiff = (float) ($teamScore['adjustment'] ?? 0);
    $evKey = ['low' => 'ev_low', 'medium' => 'ev_medium', 'high' => 'ev_high'][$teamScore['evidence_level'] ?? 'medium'] ?? 'ev_medium';
    $adjKey = abs($adjDiff) < 0.05 ? 'adj_same' : ($adjDiff < 0 ? 'adj_down' : 'adj_up');
    $adjText = str_replace(
        ['{raw}', '{score}'],
        [number_format((float) ($teamScore['raw_score'] ?? $teamScore['score']), 1), number_format((float) $teamScore['score'], 1)],
        $x($adjKey)
    );
?>
<div class="td-card">
    <h2><?= h($x('how_built')) ?></h2>
    <p class="td-p"><?= h($x('how_intro')) ?></p>
    <?php if ($sxRows): ?>
    <table class="td-table">
        <thead><tr>
            <th><?= h($x('area')) ?></th><th class="n"><?= h($x('raw')) ?></th><th class="n"><?= h($x('obs')) ?></th>
            <th class="n"><?= h($x('weighted')) ?></th><th class="n"><?= h($x('weight')) ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($sxRows as $r): ?>
            <tr>
                <td><?= h($x('p_' . $r['key'])) ?></td>
                <td class="n"><?= number_format($r['raw'], 1) ?></td>
                <td class="n"><?= (int) $r['n'] ?></td>
                <td class="n"><?= number_format($r['adjusted'], 1) ?></td>
                <td class="n"><?= (int) round($r['share'] * 100) ?>%</td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot><tr>
            <td><?= h($x('total')) ?></td>
            <td class="n"><?= number_format((float) ($teamScore['raw_score'] ?? $teamScore['score']), 1) ?></td>
            <td class="n"><?= (int) ($teamScore['evidence_n'] ?? 0) ?></td>
            <td class="n"><?= number_format((float) $teamScore['score'], 1) ?></td>
            <td class="n">100%</td>
        </tr></tfoot>
    </table>
    <?php endif; ?>
    <div class="td-note"><strong><?= h($x('evidence')) ?>: <?= h($x($evKey)) ?></strong> — <?= h($adjText) ?></div>
</div>
<?php endif; ?>

<div class="td-card">
    <?= renderAiObserverSection($section, $x('assessment'), $lang) ?>
    <?= renderAiObserverMeta($debrief) ?>
</div>

<div class="td-foot">
    <?= h($x('footer_note')) ?><br>
    <?= h($orgName) ?> &middot; <?= h($printDate) ?>
</div>

<?php require __DIR__ . '/includes/print-close-script.php'; ?>
</body>
</html>
