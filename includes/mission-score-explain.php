<?php
/**
 * VolunteerOps — the explanation printed beside the post-mission team
 * leaderboard.
 *
 * One renderer for both mission-stats.php (screen) and
 * mission-report-print.php (PDF/print), for the same reason
 * aiObserverStyles() / renderAiObserverSection() are shared: two inline copies
 * of the same prose drift, and a reader comparing the screen with the printed
 * report must not find two different explanations of the same number.
 *
 * A leaderboard that says "ΒΗΤΑ 84.1, ΑΛΦΑ 73.0" and nothing else invites the
 * obvious objection — ΑΛΦΑ answered its only order perfectly, how can it be
 * last? — so every figure is printed with the record behind it, the number it
 * would have been without the weighting, and the reason in plain Greek. The
 * wording is deliberately written for a volunteer reading their own team's
 * line, not for a statistician.
 *
 * Everything that is a NUMBER here is read from the team array computeMissionScore()
 * returns (or from the MISSION_SCORE_EVIDENCE_* constants), never retyped, so
 * changing a constant changes the explanation with it.
 */

/** "1 εντολή" / "9 εντολές" — the count plus the right Greek form. */
function missionScoreCount(int $n, string $one, string $many): string {
    return $n . ' ' . ($n === 1 ? $one : $many);
}

/** Pillar labels and the unit its n is counted in (singular, plural), in display order. */
function missionScorePillarMeta(): array {
    return [
        'response'   => ['Ταχύτητα Απόκρισης', 'εντολή', 'εντολές'],
        'completion' => ['Ολοκλήρωση Εντολών', 'εντολή', 'εντολές'],
        'shortage'   => ['Διαχείριση Ελλείψεων', 'αναφορά', 'αναφορές'],
        'discipline' => ['Πειθαρχία Πεδίου', 'σημείο ή τομέας', 'σημεία & τομείς'],
    ];
}

/** What the observations behind a team's score are, e.g. "9 εντολές, 2 αναφορές έλλειψης, 5 σημεία πορείας". */
function missionTeamEvidenceBreakdown(array $team): string {
    $fw = $team['fieldwork'] ?? [];
    $parts = [];
    if (($team['order_count'] ?? 0) > 0)      $parts[] = missionScoreCount((int) $team['order_count'], 'εντολή', 'εντολές');
    if (($team['shortage_count'] ?? 0) > 0)   $parts[] = missionScoreCount((int) $team['shortage_count'], 'αναφορά έλλειψης', 'αναφορές έλλειψης');
    if (($fw['waypoints_total'] ?? 0) > 0)    $parts[] = missionScoreCount((int) $fw['waypoints_total'], 'σημείο πορείας', 'σημεία πορείας');
    if (($fw['sectors_assigned'] ?? 0) > 0)   $parts[] = missionScoreCount((int) $fw['sectors_assigned'], 'τομέας έρευνας', 'τομείς έρευνας');
    return implode(', ', $parts);
}

/** The plain-words "what was measured" cell for one pillar. */
function missionTeamPillarMeasured(string $key, array $team): string {
    $p = $team['pillars'][$key] ?? null;
    if ($p === null || empty($p['available'])) return '';
    $raw = $p['raw'] ?? [];
    if ($key === 'response') {
        $bits = [];
        $bits[] = $raw['avg_minutes'] !== null
            ? 'Μ.Ο. επιβεβαίωσης ' . rtrim(rtrim(number_format((float) $raw['avg_minutes'], 1, '.', ''), '0'), '.') . ' λεπ.'
            : 'όλες οι απαντήσεις ήρθαν μετά από 4 ώρες';
        $un = (int) ($team['unanswered_count'] ?? 0);
        if ($un > 0) $bits[] = missionScoreCount($un, 'εντολή χωρίς καμία απάντηση', 'εντολές χωρίς καμία απάντηση');
        $fo = (int) ($team['forgotten_count'] ?? 0);
        if ($fo > 0) $bits[] = missionScoreCount($fo, 'ξεχασμένη (>4ώρες, −15)', 'ξεχασμένες (>4ώρες, −15 η καθεμία)');
        return implode(' · ', $bits);
    }
    if ($key === 'completion') {
        $t = (int) ($raw['total'] ?? 0);
        $f = (int) ($raw['fulfilled'] ?? 0);
        return $f . '/' . $t . ' ολοκληρώθηκαν (' . ($t ? round($f / $t * 100) : 0) . '%)';
    }
    if ($key === 'shortage') {
        $t = (int) ($raw['total'] ?? 0);
        $r = (int) ($raw['resolved'] ?? 0);
        $s = $r . '/' . $t . ' λύθηκαν';
        $uc = (int) ($raw['unresolved_critical'] ?? 0);
        if ($uc > 0) $s .= ' · ' . missionScoreCount($uc, 'κρίσιμη παρέμεινε ανοιχτή (−15)', 'κρίσιμες παρέμειναν ανοιχτές (−15 η καθεμία)');
        return $s;
    }
    if ($key === 'discipline') {
        $bits = [];
        if (($raw['waypoints_total'] ?? 0) > 0) {
            $s = 'σημεία ' . (int) $raw['waypoints_completed'] . '/' . (int) $raw['waypoints_total'];
            if (($raw['waypoints_skipped_with_reason'] ?? 0) > 0) $s .= ' (+' . (int) $raw['waypoints_skipped_with_reason'] . ' παραλείψεις με αιτιολόγηση, μισή πίστωση)';
            $bits[] = $s;
        }
        if (($raw['sectors_assigned'] ?? 0) > 0) {
            $s = 'τομείς ' . (int) $raw['sectors_completed'] . '/' . (int) $raw['sectors_assigned'];
            if (($raw['sectors_in_progress'] ?? 0) > 0) $s .= ' (+' . (int) $raw['sectors_in_progress'] . ' σε εξέλιξη, μισή πίστωση)';
            $bits[] = $s;
        }
        if (($raw['out_of_sequence'] ?? 0) > 0) $bits[] = (int) $raw['out_of_sequence'] . ' εκτός σειράς (−5 το καθένα)';
        if (($raw['breaches'] ?? 0) > 0) $bits[] = (int) $raw['breaches'] . ' είσοδοι σε απαγορευμένη ζώνη (−15 η καθεμία)';
        return implode(' · ', $bits);
    }
    return '';
}

/** Why an unavailable pillar is absent — said as "it does not count", never as a zero. */
function missionTeamPillarMissingReason(string $key): string {
    return [
        'response'   => 'Δεν στάλθηκαν εντολές στην ομάδα.',
        'completion' => 'Δεν στάλθηκαν εντολές στην ομάδα.',
        'shortage'   => 'Δεν υπέβαλε αναφορές έλλειψης. Δεν μετράει ούτε υπέρ ούτε εναντίον — δεν δίνεται «δώρο» 100 σε όποιον δεν ανέφερε τίποτα.',
        'discipline' => 'Δεν είχε οριστεί πορεία ή τομέας έρευνας για την ομάδα.',
    ][$key] ?? '';
}

/**
 * The rows of one team's score table, in display order. Each available row
 * carries its raw score, the record behind it, the weight that record earns,
 * the adjusted score and the points it contributes — the points of all rows add
 * up to the team's score, which is the property that lets a reader check the
 * arithmetic with a pocket calculator.
 */
function missionTeamScoreRows(array $team): array {
    $weightSum = 0;
    foreach (missionScorePillarMeta() as $key => $_) {
        $p = $team['pillars'][$key] ?? null;
        if ($p !== null && !empty($p['available'])) $weightSum += $p['weight'];
    }
    $rows = [];
    foreach (missionScorePillarMeta() as $key => [$label, $unitOne, $unitMany]) {
        $p = $team['pillars'][$key] ?? null;
        if ($p === null) continue;
        if (empty($p['available'])) {
            $rows[] = ['key' => $key, 'label' => $label, 'available' => false, 'reason' => missionTeamPillarMissingReason($key)];
            continue;
        }
        $share = $weightSum > 0 ? $p['weight'] / $weightSum : 0.0;
        $rows[] = [
            'key'         => $key,
            'label'       => $label,
            'available'   => true,
            'measured'    => missionTeamPillarMeasured($key, $team),
            'n'           => (int) $p['n'],
            'unit'        => (int) $p['n'] === 1 ? $unitOne : $unitMany,
            'raw'         => (float) $p['score'],
            'credibility' => (float) $p['credibility'],
            'adjusted'    => (float) $p['adjusted'],
            'share'       => $share,
            'points'      => $share * (float) $p['adjusted'],
        ];
    }
    return $rows;
}

/**
 * The plain-language reasons for where one team landed. Returned as an array of
 * strings so a caller can render a list (screen) or a paragraph (anything else).
 */
function missionTeamScoreReasons(array $team, int $rankedCount): array {
    $out = [];
    $cred = (float) ($team['credibility'] ?? 0);
    $credPct = (int) round($cred * 100);
    $n = (int) ($team['evidence_n'] ?? 0);
    $breakdown = missionTeamEvidenceBreakdown($team);

    if (!empty($team['ranked']) && $team['rank'] !== null) {
        $out[] = 'Θέση ' . (int) $team['rank'] . ' από ' . $rankedCount . ' στην κατάταξη.';
    } else {
        $out[] = 'Η ομάδα δεν παίρνει θέση στην κατάταξη: μετρήθηκε σε έναν μόνο τομέα, οπότε ο βαθμός της εμφανίζεται αλλά δεν είναι συγκρίσιμος με τους βαθμούς ομάδων που μετρήθηκαν σε περισσότερους τομείς.';
    }

    $out[] = 'Ο βαθμός στηρίζεται σε ' . ($n === 1 ? 'μία μόνο παρατήρηση' : $n . ' παρατηρήσεις') . ($breakdown !== '' ? " ({$breakdown})" : '')
        . '. Βεβαιότητα: ' . ($team['evidence_label'] ?? '—') . ' — περίπου το ' . $credPct . '% του βαθμού προέρχεται από το δικό της ιστορικό και το υπόλοιπο ' . (100 - $credPct)
        . '% από την «πίστωση εμπιστοσύνης» (επίπεδο ' . (int) MISSION_SCORE_EVIDENCE_BASELINE . ').';

    $adj = (float) ($team['adjustment'] ?? 0);
    $rawScore = number_format((float) ($team['raw_score'] ?? $team['score']), 1);
    if (abs($adj) < 0.05) {
        $out[] = 'Η στάθμιση δεν άλλαξε ουσιαστικά τον βαθμό (' . $rawScore . ' πριν, ' . number_format((float) $team['score'], 1) . ' μετά): το ιστορικό της ομάδας είναι αρκετό ώστε το ποσοστό να λέει σχεδόν όλη την αλήθεια.';
    } elseif ($adj < 0) {
        $out[] = 'Χωρίς στάθμιση ο βαθμός θα ήταν ' . $rawScore . '. Η στάθμιση τον κατέβασε κατά ' . number_format(abs($adj), 1)
            . ' μονάδες, γιατί η επίδοση είναι πάνω από το μέσο επίπεδο αλλά στηρίζεται σε περιορισμένο ιστορικό — δεν έχει ακόμη «αποδείξει» όσα δείχνει το ποσοστό.';
    } else {
        $out[] = 'Χωρίς στάθμιση ο βαθμός θα ήταν ' . $rawScore . '. Η στάθμιση τον ανέβασε κατά ' . number_format($adj, 1)
            . ' μονάδες, γιατί η επίδοση είναι κάτω από το μέσο επίπεδο αλλά στηρίζεται σε περιορισμένο ιστορικό — λίγες αποτυχίες δεν αρκούν για να κριθεί οριστικά μια ομάδα.';
    }

    if (!empty($team['ranked']) && ($team['raw_rank'] ?? null) !== null && $team['raw_rank'] !== $team['rank']) {
        $out[] = 'Αν μετρούσε μόνο το ποσοστό, η ομάδα θα ήταν στην ' . (int) $team['raw_rank'] . 'η θέση· με τη στάθμιση είναι στην ' . (int) $team['rank'] . 'η.';
    }

    $missing = [];
    foreach (missionScorePillarMeta() as $key => [$plabel]) {
        $p = $team['pillars'][$key] ?? null;
        if ($p !== null && empty($p['available'])) $missing[] = $plabel;
    }
    if ($missing) {
        $out[] = 'Τομείς χωρίς δεδομένα (δεν μετρούν ούτε υπέρ ούτε εναντίον): ' . implode(', ', $missing) . '.';
    }

    $fw = $team['fieldwork'] ?? [];
    $sos = (int) ($fw['sos_alerts'] ?? 0);
    $inc = (int) ($fw['incidents'] ?? 0);
    if ($sos + $inc > 0) {
        $bits = [];
        if ($sos > 0) $bits[] = missionScoreCount($sos, 'συναγερμό SOS', 'συναγερμούς SOS');
        if ($inc > 0) $bits[] = missionScoreCount($inc, 'περιστατικό τραυματισμού', 'περιστατικά τραυματισμού');
        $out[] = 'Η ομάδα σήμανε/χειρίστηκε ' . implode(' και ', $bits) . '. Αυτά δεν επηρεάζουν τον βαθμό της — και δεν πρέπει ποτέ — αλλά συχνά εξηγούν γιατί φαίνεται πιο αργή στους άλλους δείκτες.';
    }
    return $out;
}

/** 1–4 filled pips for a 0–1 credibility. */
function missionScoreMeterHtml(float $credibility): string {
    $on = (int) min(4, max(1, round($credibility * 4)));
    $html = '<span class="sx-meter" title="Βάρος του δικού της ιστορικού στον βαθμό: ' . (int) round($credibility * 100) . '%">';
    for ($i = 1; $i <= 4; $i++) {
        $html .= '<i class="' . ($i <= $on ? 'on' : '') . '"></i>';
    }
    return $html . '</span>';
}

/**
 * The fewest observations at which a pillar scoring $raw reaches $target after
 * weighting, or null when it never can. Used to tell the reader, in numbers,
 * what «Άριστη Επίδοση» costs.
 */
function missionScoreMinObservationsFor(float $raw, float $target): ?int {
    for ($n = 1; $n <= 200; $n++) {
        if (missionScoreEvidenceAdjust($raw, $n)['adjusted'] + 1e-9 >= $target) return $n;
    }
    return null;
}

/**
 * Everything printed under one team's leaderboard row: a one-line summary that
 * is always visible, and the full "how this score was built" table. On screen
 * the table sits in a <details> (closed — a mission can have a dozen teams); in
 * print it is always open, because paper cannot be clicked.
 */
function renderMissionTeamExplain(array $team, int $rankedCount, bool $print = false): string {
    $label = teamLabel($team['codename'], $team['team_number']);
    $adj = (float) ($team['adjustment'] ?? 0);

    $line  = '<div class="sx-line">';
    $line .= '<span class="sx-line-k">Βεβαιότητα</span> ' . missionScoreMeterHtml((float) ($team['credibility'] ?? 0))
        . ' <strong>' . h((string) ($team['evidence_label'] ?? '—')) . '</strong>';
    $breakdown = missionTeamEvidenceBreakdown($team);
    if ($breakdown !== '') {
        $line .= '<span class="sx-sep">·</span>' . h($breakdown);
    }
    if (abs($adj) >= 0.05) {
        $line .= '<span class="sx-sep">·</span>ακατέργαστος <strong>' . number_format((float) $team['raw_score'], 1) . '</strong> '
            . '<span class="sx-arrow">→</span> μετά τη στάθμιση <strong>' . number_format((float) $team['score'], 1) . '</strong>'
            . ' <span class="sx-delta ' . ($adj < 0 ? 'down' : 'up') . '">(' . ($adj < 0 ? '−' : '+') . number_format(abs($adj), 1) . ')</span>';
    }
    if (empty($team['ranked'])) {
        $line .= '<span class="sx-sep">·</span><span class="sx-unranked">δεν κατατάσσεται</span>';
    }
    $line .= '</div>';

    $rows = missionTeamScoreRows($team);
    $table  = '<div class="sx-scroll"><table class="sx-table"><thead><tr>'
        . '<th>Τομέας</th><th>Τι μετρήθηκε</th><th class="n">Ακατέργαστος</th><th class="n">Στοιχεία</th>'
        . '<th class="n">Μετά τη στάθμιση</th><th class="n">Βάρος</th><th class="n">Μονάδες</th>'
        . '</tr></thead><tbody>';
    $pointsSum = 0.0;
    foreach ($rows as $r) {
        if (!$r['available']) {
            $table .= '<tr class="sx-missing"><td>' . h($r['label']) . '</td><td colspan="6">' . h($r['reason']) . '</td></tr>';
            continue;
        }
        $pointsSum += $r['points'];
        $table .= '<tr><td><strong>' . h($r['label']) . '</strong></td>'
            . '<td>' . h($r['measured']) . '</td>'
            . '<td class="n">' . number_format($r['raw'], 1) . '</td>'
            . '<td class="n">' . $r['n'] . ' <span class="sx-unit">' . h($r['unit']) . '</span><br><span class="sx-unit">βάρος ιστορικού ' . (int) round($r['credibility'] * 100) . '%</span></td>'
            . '<td class="n"><strong>' . number_format($r['adjusted'], 1) . '</strong></td>'
            . '<td class="n">' . (int) round($r['share'] * 100) . '%</td>'
            . '<td class="n">' . number_format($r['points'], 1) . '</td></tr>';
    }
    $table .= '</tbody><tfoot><tr><td colspan="2"><strong>Σύνολο ομάδας</strong></td>'
        . '<td class="n">' . number_format((float) $team['raw_score'], 1) . '</td><td class="n"></td>'
        . '<td class="n"><strong>' . number_format((float) $team['score'], 1) . '</strong></td>'
        . '<td class="n">100%</td><td class="n"><strong>' . number_format($pointsSum, 1) . '</strong></td></tr></tfoot></table></div>';

    $reasons = '<ul class="sx-reasons">';
    foreach (missionTeamScoreReasons($team, $rankedCount) as $r) {
        $reasons .= '<li>' . h($r) . '</li>';
    }
    $reasons .= '</ul>';

    if ($print) {
        return $line . '<div class="sx-body">' . $table . $reasons . '</div>';
    }
    return $line . '<details class="sx-team"><summary>Πώς προέκυψε ο βαθμός της ' . h($label) . '</summary><div class="sx-body">' . $table . $reasons . '</div></details>';
}

/**
 * Before/after box: the order the plain percentages would have produced next to
 * the order the leaderboard uses. The most direct possible answer to "why is
 * the team with the best percentage not first?" — and, when nothing moved,
 * to "did the weighting rig anything?".
 */
function renderMissionRankingShift(array $teams): string {
    $ranked = array_values(array_filter($teams, fn($t) => !empty($t['ranked']) && $t['rank'] !== null && ($t['raw_rank'] ?? null) !== null));
    if (count($ranked) < 2) return '';

    $byRank = $ranked;
    usort($byRank, fn($a, $b) => $a['rank'] <=> $b['rank']);
    $byRaw = $ranked;
    usort($byRaw, fn($a, $b) => $a['raw_rank'] <=> $b['raw_rank']);
    $changed = false;
    foreach ($ranked as $t) {
        if ($t['rank'] !== $t['raw_rank']) $changed = true;
    }

    $li = function (array $t, string $scoreKey, bool $arrows) {
        $s = '<li><span class="sx-badge" style="background:' . h($t['color']) . ';">' . h(teamLabel($t['codename'], $t['team_number'])) . '</span> '
            . '<strong>' . number_format((float) $t[$scoreKey], 1) . '</strong> <span class="sx-unit">(' . missionScoreCount((int) $t['evidence_n'], 'παρατήρηση', 'παρατηρήσεις') . ')</span>';
        if ($arrows && $t['rank'] !== $t['raw_rank']) {
            $up = $t['rank'] < $t['raw_rank'];
            $s .= ' <span class="sx-delta ' . ($up ? 'up' : 'down') . '">' . ($up ? '▲' : '▼') . ' ' . abs($t['rank'] - $t['raw_rank']) . '</span>';
        }
        return $s . '</li>';
    };

    $html  = '<div class="sx-shift">';
    $html .= '<div class="sx-shift-col"><div class="sx-shift-h">Αν μετρούσε μόνο το ποσοστό</div><ol>';
    foreach ($byRaw as $t) $html .= $li($t, 'raw_score', false);
    $html .= '</ol></div><div class="sx-shift-col"><div class="sx-shift-h">Με στάθμιση — η κατάταξη της αναφοράς</div><ol>';
    foreach ($byRank as $t) $html .= $li($t, 'score', true);
    $html .= '</ol></div>';
    $html .= '<div class="sx-shift-note">' . ($changed
        ? 'Η στάθμιση άλλαξε τη σειρά: οι ομάδες με περισσότερα στοιχεία πίσω από τον βαθμό τους ανεβαίνουν, και όσες φαίνονται εξαιρετικές μόνο επειδή έλαβαν ελάχιστες εντολές κατεβαίνουν. Τα βελάκια δείχνουν πόσες θέσεις μετακινήθηκε κάθε ομάδα.'
        : 'Η στάθμιση δεν άλλαξε τη σειρά: όλες οι ομάδες έχουν αρκετό ιστορικό ώστε το ποσοστό τους να είναι αξιόπιστο, οπότε κατατάσσονται όπως θα κατατάσσονταν και χωρίς αυτήν.')
        . '</div></div>';
    return $html;
}

/**
 * The long-form "how to read this ranking" guide. Static prose with numbers
 * pulled from the live constants; the worked example below it uses the real
 * formula, so the figures printed can never disagree with the figures computed.
 */
function renderMissionScoreGuide(array $teams, bool $print = false): string {
    $k = (int) MISSION_SCORE_EVIDENCE_PRIOR_N;
    $base = (int) MISSION_SCORE_EVIDENCE_BASELINE;
    $minCompare = (int) MISSION_SCORE_MIN_COMPARE_N;

    $aEx = missionScoreEvidenceAdjust(100.0, 1)['adjusted'];
    $bEx = missionScoreEvidenceAdjust(8 / 9 * 100, 9)['adjusted'];
    $nPerfect = missionScoreMinObservationsFor(100.0, 85.0);
    $n92 = missionScoreMinObservationsFor(92.0, 85.0);

    $tbl = '<table class="sx-table sx-small"><thead><tr><th class="n">Παρατηρήσεις (n)</th><th class="n">Βάρος του δικού της ιστορικού</th><th>Τι σημαίνει</th></tr></thead><tbody>';
    foreach ([
        1  => 'σχεδόν ολόκληρος ο βαθμός είναι η «πίστωση εμπιστοσύνης»',
        3  => 'μισό ιστορικό, μισή πίστωση',
        5  => 'το ιστορικό αρχίζει να βαραίνει περισσότερο',
        9  => 'το ιστορικό καθορίζει τα τρία τέταρτα του βαθμού',
        15 => 'ο βαθμός είναι κυρίως ό,τι έκανε η ομάδα',
        30 => 'πρακτικά ο βαθμός είναι το ακατέργαστο ποσοστό',
    ] as $n => $what) {
        $tbl .= '<tr><td class="n">' . $n . '</td><td class="n"><strong>' . (int) round(missionScoreEvidenceWeight($n) * 100) . '%</strong></td><td>' . h($what) . '</td></tr>';
    }
    $tbl .= '</tbody></table>';

    $sec = function (string $title, string $body) {
        return '<div class="sx-sec"><h4>' . h($title) . '</h4>' . $body . '</div>';
    };

    $html  = '<div class="sx-guide">';
    $html .= '<p class="sx-lead">Η βαθμολογία κάθε ομάδας δεν είναι απλώς ένα ποσοστό. Μετράει δύο πράγματα μαζί: <strong>πόσο καλή</strong> ήταν η επίδοσή της και <strong>πόσο στέρεα αποδεδειγμένη</strong> είναι αυτή η επίδοση. '
        . 'Μια ομάδα που έκανε σωστά μία μόνο εντολή δεν έχει αποδείξει ακόμη το ίδιο με μια ομάδα που έκανε σωστά οκτώ από εννέα.</p>';

    $html .= $sec('Το βασικό παράδειγμα: 1/1 ή 8/9;',
        '<p>Η ομάδα Α έλαβε <strong>1 εντολή</strong> και την ολοκλήρωσε: 1/1 = 100%. Η ομάδα Β έλαβε <strong>9 εντολές</strong> και ολοκλήρωσε τις 8: 8/9 = 89%. '
        . 'Αν συγκρίναμε μόνο ποσοστά, η Α θα κέρδιζε — δηλαδή θα έβγαινε πρώτη η ομάδα στην οποία απλώς εμπιστεύθηκαν λιγότερη δουλειά.</p>'
        . '<p>Όμως μία επιτυχία μπορεί να είναι τύχη ή μια εύκολη εντολή. Οκτώ επιτυχίες σε εννέα δοκιμασίες είναι αποδεδειγμένη αξιοπιστία. '
        . 'Με τη στάθμιση ο τομέας «Ολοκλήρωση Εντολών» βγαίνει <strong>' . number_format($aEx, 1) . '</strong> για την Α και <strong>' . number_format($bEx, 1) . '</strong> για τη Β: η Β κατατάσσεται πάνω από την Α, '
        . 'και η Α δεν «τιμωρείται» — απλώς περιμένει να συγκεντρώσει ιστορικό.</p>');

    $html .= $sec('Πώς γίνεται η στάθμιση',
        '<p>Κάθε ομάδα ξεκινά με μια <strong>«πίστωση εμπιστοσύνης»</strong>: υποθέτουμε ότι βρίσκεται στο επίπεδο <strong>' . $base . '</strong> (το κατώτερο όριο της «Καλής Επίδοσης»), '
        . 'με βάρος ίσο με <strong>' . $k . ' εικονικές παρατηρήσεις</strong>. Κάθε πραγματική παρατήρηση — εντολή, αναφορά έλλειψης, σημείο πορείας, τομέας έρευνας — παίρνει σταδιακά τη θέση της πίστωσης.</p>'
        . '<div class="sx-formula">Βαθμός τομέα = ' . $base . ' + n ÷ (n + ' . $k . ') × (Ακατέργαστος − ' . $base . ')</div>'
        . $tbl
        . '<p>Η στάθμιση είναι <strong>συμμετρική</strong>: μία αποτυχημένη εντολή δεν «καταδικάζει» μια ομάδα όσο δέκα, και μία επιτυχία δεν την «στέφει». Μόνο ένα μακρύτερο ιστορικό απομακρύνει μια ομάδα από το μέσο επίπεδο — προς τα πάνω ή προς τα κάτω.</p>');

    $html .= $sec('Από πού προκύπτει ο βαθμός',
        '<p>Έως τέσσερις τομείς, με σχετικά βάρη: <strong>Ταχύτητα Απόκρισης</strong> (45), <strong>Ολοκλήρωση Εντολών</strong> (35), <strong>Διαχείριση Ελλείψεων</strong> (20) και '
        . '<strong>Πειθαρχία Πεδίου</strong> (25 — κλείσιμο σημείων πορείας, κάλυψη τομέων, είσοδοι σε απαγορευμένες ζώνες). '
        . 'Κάθε τομέας σταθμίζεται χωριστά με το δικό του πλήθος παρατηρήσεων και ο μέσος όρος παίρνεται μόνο πάνω στους τομείς όπου η ομάδα έχει δεδομένα.</p>'
        . '<p>Ένας τομέας χωρίς δεδομένα (π.χ. καμία αναφορά έλλειψης) <strong>δεν μετράει ούτε υπέρ ούτε εναντίον</strong>. Μια εντολή που δεν επιβεβαιώθηκε ποτέ μετράει ως αποτυχία ταχύτητας· '
        . 'μια απάντηση μετά από 4 ώρες θεωρείται «ξεχασμένη» και κοστίζει 15 μονάδες, χωρίς να παραμορφώνει τον μέσο όρο των υπόλοιπων.</p>'
        . '<p><strong>Ποιες εντολές μετράνε στην Ολοκλήρωση.</strong> Μόνο όσες έχουν κάτι να ολοκληρωθεί: εργασίες, τοποθεσία, φωτογραφία ή βίντεο, ζωντανή μετάδοση, διαδρομή. '
        . 'Τα μηνύματα προς όλους, οι ηχητικές ανακοινώσεις, οι υπενθυμίσεις φόρτισης και η «επιστροφή στη βάση» δεν έχουν ενέργεια ολοκλήρωσης — η επιβεβαίωση λήψης τους <em>είναι</em> η εκτέλεσή τους. '
        . 'Γι\' αυτό μετράνε στην Ταχύτητα Απόκρισης (αν επιβεβαιώθηκαν και πόσο γρήγορα) αλλά δεν μπορούν να μειώσουν την Ολοκλήρωση: μια ομάδα που έλαβε και μηνύματα δεν «χάνει» ποσοστό για κάτι που δεν είχε τρόπο να ολοκληρώσει. Γι\' αυτό η στήλη «Στοιχεία» μπορεί να δείχνει λιγότερες εντολές στην Ολοκλήρωση απ\' ό,τι στην Ταχύτητα.</p>'
        . '<p>Η στήλη «Μονάδες» στον πίνακα κάθε ομάδας είναι η συμβολή του τομέα στον τελικό βαθμό — αν τις προσθέσετε, βγαίνει ακριβώς ο βαθμός της ομάδας.</p>');

    $html .= $sec('Τι δεν βαθμολογείται ποτέ — και γιατί',
        '<ul>'
        . '<li><strong>Συναγερμοί SOS και περιστατικά τραυματισμού.</strong> Δεν πρέπει να υπάρχει ποτέ αριθμητικός λόγος να αποφύγει μια ομάδα να ειδοποιήσει. Αντίθετα, συχνά εξηγούν γιατί μια ομάδα φαίνεται αργή.</li>'
        . '<li><strong>Ευρήματα και φωτογραφίες πεδίου.</strong> Εξαρτώνται από το τι υπήρχε στον τομέα, όχι από το πόσο καλά έψαξε η ομάδα.</li>'
        . '<li><strong>Χρόνος μετακίνησης και άφιξης.</strong> Εξαρτάται από την απόσταση του σημείου, όχι από την επιμέλεια της ομάδας.</li>'
        . '<li><strong>Όγκος μηνυμάτων και μέγεθος ομάδας.</strong> Περισσότερα δεν σημαίνει καλύτερα.</li>'
        . '</ul>');

    $html .= $sec('Επίπεδα και βεβαιότητα',
        '<p><strong>Άριστη Επίδοση</strong> από 85 · <strong>Καλή Επίδοση</strong> από 65 · <strong>Χρειάζεται Βελτίωση</strong> κάτω από 65. '
        . 'Επειδή ο βαθμός περιλαμβάνει τη στάθμιση, η «Άριστη» απαιτεί <em>και</em> υψηλή επίδοση <em>και</em> αρκετό ιστορικό: ακόμη και με τέλεια επίδοση χρειάζονται τουλάχιστον <strong>'
        . ($nPerfect ?? '—') . ' παρατηρήσεις</strong>, ενώ με επίδοση 92% χρειάζονται <strong>' . ($n92 ?? '—') . '</strong>.</p>'
        . '<p><strong>Βεβαιότητα</strong>: <em>Χαμηλή</em> όταν το δικό της ιστορικό βαραίνει λιγότερο από 40% στον βαθμό, <em>Μέτρια</em> από 40% έως 70%, <em>Υψηλή</em> πάνω από 70%.</p>');

    $html .= $sec('Πότε μια ομάδα δεν κατατάσσεται, και πότε λέγεται «καλύτερη»',
        '<p>Αν μια ομάδα μετρήθηκε σε <strong>έναν μόνο τομέα</strong> (για παράδειγμα μόνο σε σημεία πορείας, χωρίς καμία εντολή), ο βαθμός της εμφανίζεται αλλά δεν παίρνει θέση (—), γιατί δεν συγκρίνεται με ομάδες που μετρήθηκαν σε περισσότερους τομείς.</p>'
        . '<p>Στη λεκτική αξιολόγηση, μια ομάδα χαρακτηρίζεται «καλύτερη» ή «χειρότερη» σε ένα μέγεθος μόνο αν έχει <strong>τουλάχιστον ' . $minCompare . ' παρατηρήσεις</strong> σε αυτό και η διαφορά παραμένει αφού ληφθεί υπόψη το μέγεθος του δείγματος. Έτσι το «100% σε 4 εντολές» δεν παρουσιάζεται ως νίκη επί του «89% σε 9».</p>');

    $html .= $sec('Ο συνολικός βαθμός της άσκησης',
        '<p>Ο βαθμός στο πάνω μέρος της σελίδας υπολογίζεται χωριστά, από το σύνολο των εντολών, των ελλείψεων, της στελέχωσης και του debrief. <strong>Δεν επηρεάζεται</strong> από τη στάθμιση των ομάδων και μένει συγκρίσιμος με προηγούμενες αποστολές του ίδιου τύπου.</p>');

    $html .= $sec('Πώς να χρησιμοποιηθεί',
        '<p>Η κατάταξη είναι αφορμή για συζήτηση στο debrief, όχι ετυμηγορία. Δύο ομάδες με διαφορά κάτω από 5 μονάδες είναι πρακτικά ισοδύναμες. '
        . 'Διαβάστε πάντα τον βαθμό μαζί με τον όγκο εντολών: τον φόρτο τον μοιράζει η διοίκηση, όχι οι ομάδες — μια ομάδα που έλαβε λίγες εντολές δεν έκανε κάτι λάθος, απλώς δεν έχει ακόμη αρκετό ιστορικό για να κριθεί με βεβαιότητα.</p>');

    $html .= '</div>';

    if ($print) {
        return '<div class="sx-guide-wrap">' . $html . '</div>';
    }
    return '<details class="sx-guide-details" open><summary><i class="bi bi-info-circle"></i> Πώς διαβάζεται η κατάταξη — και γιατί είναι δίκαιη</summary>' . $html . '</details>';
}

/**
 * Styles for everything above. Self-contained and light-only, matching the two
 * report pages (neither has a dark theme). $print switches the sizes to points.
 */
function missionScoreExplainStyles(bool $print = false): string {
    $s = $print
        ? ['base' => '7.8pt', 'small' => '7pt', 'h4' => '9pt', 'lead' => '8.6pt', 'sum' => '9pt']
        : ['base' => '.84rem', 'small' => '.76rem', 'h4' => '.95rem', 'lead' => '.92rem', 'sum' => '.9rem'];
    return <<<CSS
.lb-row, .score-leaderboard-row { flex-wrap: wrap; }
.sx-line { flex: 0 0 100%; font-size: {$s['base']}; color: #55534d; line-height: 1.6; padding: 2px 2px 0 0; }
.sx-line-k { color: #7a746a; margin-right: 2px; }
.sx-sep { margin: 0 6px; color: #b3afa5; }
.sx-arrow { color: #8d887c; }
.sx-delta { font-weight: 700; white-space: nowrap; }
.sx-delta.down { color: #b5651d; }
.sx-delta.up { color: #1d7a4b; }
.sx-unranked { color: #a56600; font-weight: 700; }
.sx-meter { display: inline-flex; gap: 2px; vertical-align: middle; margin: 0 4px 1px 2px; }
.sx-meter i { display: inline-block; width: 9px; height: 9px; border-radius: 2px; background: #dedbd2; }
.sx-meter i.on { background: #4a3aa7; }
.sx-team { flex: 0 0 100%; margin-top: 4px; }
.sx-team summary { cursor: pointer; font-size: {$s['base']}; color: #4a3aa7; font-weight: 700; padding: 2px 0; }
.sx-body { flex: 0 0 100%; margin-top: 6px; }
.sx-scroll { overflow-x: auto; }
.sx-table { width: 100%; border-collapse: collapse; font-size: {$s['small']}; background: #fff; }
.sx-table th { text-align: left; font-weight: 700; color: #6b665c; border-bottom: 1px solid #d9d5cc; padding: 4px 6px; vertical-align: bottom; }
.sx-table td { border-bottom: 1px solid #eeece6; padding: 4px 6px; vertical-align: top; line-height: 1.45; }
.sx-table th.n, .sx-table td.n { text-align: right; white-space: nowrap; }
.sx-table tfoot td { border-top: 2px solid #d9d5cc; border-bottom: 0; background: #f6f5f1; }
.sx-table tr.sx-missing td { color: #8d887c; font-style: italic; }
.sx-unit { color: #8d887c; font-size: {$s['small']}; }
.sx-reasons { margin: 8px 0 2px; padding-left: 18px; font-size: {$s['base']}; line-height: 1.6; color: #33322d; }
.sx-reasons li { margin-bottom: 3px; }
.sx-shift { display: flex; flex-wrap: wrap; gap: 14px; margin: 10px 0 4px; padding: 12px 14px; background: #fff; border: 1px solid #e4e1d8; border-radius: 10px; page-break-inside: avoid; }
.sx-shift-col { flex: 1 1 220px; }
.sx-shift-h { font-size: {$s['small']}; font-weight: 700; letter-spacing: .03em; text-transform: uppercase; color: #6b665c; margin-bottom: 4px; }
.sx-shift ol { margin: 0; padding-left: 20px; font-size: {$s['base']}; line-height: 1.9; }
.sx-badge { display: inline-block; padding: 1px 9px; border-radius: 999px; color: #fff; font-weight: 700; font-size: {$s['small']}; }
.sx-shift-note { flex: 0 0 100%; font-size: {$s['base']}; color: #55534d; line-height: 1.55; border-top: 1px dashed #d9d5cc; padding-top: 8px; }
.sx-guide-details { margin-top: 12px; background: #f9f9f7; border: 1px solid #e4e1d8; border-left: 4px solid #4a3aa7; border-radius: 0 10px 10px 0; padding: 10px 16px; }
.sx-guide-details > summary { cursor: pointer; font-weight: 700; font-size: {$s['sum']}; color: #4a3aa7; padding: 4px 0; }
.sx-guide-wrap { margin-top: 4px; }
.sx-guide { font-size: {$s['base']}; line-height: 1.62; color: #33322d; }
.sx-guide p { margin: 0 0 8px; }
.sx-guide ul { margin: 0 0 8px; padding-left: 18px; }
.sx-guide li { margin-bottom: 4px; }
.sx-lead { font-size: {$s['lead']}; }
.sx-sec { margin-top: 12px; page-break-inside: avoid; }
.sx-sec h4 { font-size: {$s['h4']}; font-weight: 800; color: #172554; margin: 0 0 5px; }
.sx-formula { margin: 6px 0 10px; padding: 8px 12px; background: #fff; border: 1px dashed #b9b4a8; border-radius: 8px; font-weight: 700; text-align: center; font-size: {$s['base']}; }
.sx-small { max-width: 560px; margin-bottom: 8px; }
@media print { .sx-team { display: block; } }
CSS;
}
