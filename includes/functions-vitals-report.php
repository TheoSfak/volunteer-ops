<?php
/**
 * VolunteerOps - Heart-rate report data (mission-vitals-report.php)
 *
 * The analysis half of rescuer vitals: what is happening right now, and which
 * stretches of the deployment are worth a second look. Separate from
 * functions-vitals.php, which is the pipeline — storing samples, colouring one
 * reading, feeding the live Action Room. This file never touches the
 * five-second poll; it is only ever loaded by a page someone opened on purpose.
 *
 * Everything here reads the per-minute buckets rather than raw samples. A
 * six-hour deployment of a dozen people is ~50.000 rows, and none of the
 * questions on that page — "is anyone in trouble now", "did anyone stay above
 * 150 for ten minutes", "which team is working hardest" — are answered any
 * better at five-second resolution than at one minute. The report's own
 * per-volunteer totals still come from every raw sample (see
 * loadVitalsReportForMission), because a peak is exactly what a minute average
 * would hide.
 */

if (!defined('VOLUNTEEROPS')) {
    die('Direct access not permitted');
}

/**
 * Longest gap, in minutes, that an episode may span without evidence.
 *
 * A run of "above 150" cannot be claimed across minutes where nobody was
 * recording: the strap may have been off, the phone out of Bluetooth range, or
 * the volunteer sitting in a vehicle. Three minutes tolerates one missed
 * sampling window plus slack; beyond that the run is closed and a new one may
 * start. Without this, a strap reconnecting an hour later would silently weld
 * two unrelated minutes into one 60-minute "episode".
 */
const VITALS_EPISODE_MAX_GAP_MINUTES = 3;

/**
 * Per-volunteer time series for a mission, oldest first:
 *   [userId => [['ts' => unix, 'bpm' => int, 'zone' => string], ...]]
 *
 * One read that every function below shares, because they all walk the same
 * minutes and three separate loaders would mean three identical queries on a
 * page that already refreshes itself every thirty seconds.
 */
function vitalsOrderedSeries(int $missionId): array {
    $buckets = loadVitalsBucketedForMission($missionId, 1);
    $series  = [];

    foreach ($buckets as $userId => $byKey) {
        $points = [];
        foreach ($byKey as $key => $entry) {
            // strtotime on a string this app formatted itself, compared only
            // against other values from the same source — the epoch never
            // leaves PHP here, so the MySQL/PHP timezone trap that cost us the
            // February report does not apply.
            $points[] = ['ts' => strtotime($key), 'bpm' => (int) $entry['bpm'], 'zone' => $entry['zone']];
        }
        usort($points, fn($a, $b) => $a['ts'] <=> $b['ts']);
        $series[(int) $userId] = $points;
    }

    return $series;
}

/**
 * Name, team and shift context for everyone who has an approved participation
 * on this mission, keyed by user id. Used to put a name and a team colour next
 * to a user id without every caller writing the same four joins.
 */
function vitalsParticipantContext(int $missionId): array {
    try {
        $rows = dbFetchAll(
            "SELECT DISTINCT pr.volunteer_id, u.name, u.is_external, u.guest_org_name, u.guest_country_code,
                    COALESCE(ht.name, mvt.label) AS home_team_name, COALESCE(ht.color, mvt.color) AS home_team_color,
                    mt.codename, mt.team_number, mt.color AS team_color
             FROM participation_requests pr
             JOIN shifts s ON s.id = pr.shift_id
             JOIN users u ON u.id = pr.volunteer_id
             LEFT JOIN volunteer_teams ht ON ht.id = u.volunteer_team_id
             LEFT JOIN mission_visitor_tags mvt ON mvt.id = u.mission_visitor_tag_id
             LEFT JOIN mission_team_members mtm ON mtm.mission_id = s.mission_id AND mtm.user_id = pr.volunteer_id
             LEFT JOIN mission_teams mt ON mt.id = mtm.team_id
             WHERE s.mission_id = ? AND pr.status = ?",
            [$missionId, PARTICIPATION_APPROVED]
        );
    } catch (Exception $e) {
        return [];
    }

    $out = [];
    foreach ($rows as $row) {
        $out[(int) $row['volunteer_id']] = [
            'name'               => $row['name'],
            'is_external'        => (bool) $row['is_external'],
            'guest_org_name'     => $row['guest_org_name'],
            'guest_country_code' => $row['guest_country_code'],
            'home_team_name'     => $row['home_team_name'],
            'home_team_color'    => $row['home_team_color'],
            'team_label'         => teamLabel($row['codename'], $row['team_number']),
            'team_color'         => $row['team_color'],
        ];
    }
    return $out;
}

/**
 * Current state per volunteer, plus the counts for the dashboard tiles.
 *
 * Sorted worst-zone-first rather than alphabetically. During a live operation
 * nobody reads this table top to bottom; they glance at it, and what has to be
 * at the top is whoever needs attention, not whoever is called Anna.
 */
function loadVitalsNowForMission(int $missionId): array {
    if (!vitalsEnabled()) {
        return ['volunteers' => [], 'summary' => []];
    }

    $config  = vitalsConfig();
    $series  = vitalsOrderedSeries($missionId);
    $context = vitalsParticipantContext($missionId);
    $now     = time();

    // Worst first. 'stale' sits between the two emergencies and the healthy
    // states on purpose: a volunteer whose sensor went quiet mid-operation is
    // a question mark, which matters more than someone comfortably in range
    // and less than someone measurably in trouble.
    $zoneRank = ['critical' => 0, 'low' => 1, 'stale' => 2, 'elevated' => 3, 'ok' => 4, 'none' => 5];

    $volunteers = [];
    foreach ($context as $userId => $info) {
        $points = $series[$userId] ?? [];
        if (!$points) {
            $volunteers[] = $info + [
                'user_id' => $userId, 'bpm' => null, 'zone' => 'none', 'rank' => $zoneRank['none'],
                'trend' => null, 'zone_minutes' => null, 'last_at' => null, 'age_seconds' => null,
            ];
            continue;
        }

        $last     = end($points);
        $age      = $now - $last['ts'];
        $isStale  = $age > $config['stale_seconds'];
        $zone     = $isStale ? 'stale' : $last['zone'];

        // How long they have been continuously in this zone — the number that
        // turns "142 bpm" into either "he just started climbing" or "he has
        // been at this for forty minutes and nobody has relieved him".
        $zoneMinutes = 0;
        for ($i = count($points) - 1; $i >= 0; $i--) {
            if ($points[$i]['zone'] !== $last['zone']) break;
            $zoneMinutes++;
        }

        $volunteers[] = $info + [
            'user_id'      => $userId,
            'bpm'          => (int) $last['bpm'],
            'zone'         => $zone,
            'rank'         => $zoneRank[$zone] ?? 5,
            'trend'        => vitalsTrend($points),
            'zone_minutes' => $isStale ? null : $zoneMinutes,
            'last_at'      => date('H:i:s', $last['ts']),
            'age_seconds'  => $age,
        ];
    }

    usort($volunteers, function ($a, $b) {
        if ($a['rank'] !== $b['rank']) return $a['rank'] <=> $b['rank'];
        // Within the same zone the higher heart rate goes first for the high
        // zones and the lower one first for bradycardia — in both cases the
        // more extreme reading, which is what "worst first" actually means.
        if ($a['zone'] === 'low') return ($a['bpm'] ?? 999) <=> ($b['bpm'] ?? 999);
        return ($b['bpm'] ?? -1) <=> ($a['bpm'] ?? -1);
    });

    $summary = [
        'wearing'    => count(array_filter($volunteers, fn($v) => $v['bpm'] !== null && $v['zone'] !== 'stale')),
        'expected'   => count($context),
        'critical'   => count(array_filter($volunteers, fn($v) => $v['zone'] === 'critical')),
        'low'        => count(array_filter($volunteers, fn($v) => $v['zone'] === 'low')),
        'elevated'   => count(array_filter($volunteers, fn($v) => $v['zone'] === 'elevated')),
        'stale'      => count(array_filter($volunteers, fn($v) => $v['zone'] === 'stale')),
        'no_sensor'  => count(array_filter($volunteers, fn($v) => $v['zone'] === 'none')),
    ];

    return ['volunteers' => $volunteers, 'summary' => $summary];
}

/**
 * Direction of travel over the last ten minutes: the mean of the most recent
 * five compared with the five before it.
 *
 * Deliberately not "this reading versus the previous one", which on a heart
 * rate is almost pure noise — a single minute swings by more than this
 * threshold while someone is simply breathing. Returns null when there is not
 * enough history to say anything, which is honest rather than defaulting to
 * "steady".
 */
function vitalsTrend(array $points): ?string {
    $n = count($points);
    if ($n < 6) return null;

    $recent = array_slice($points, -5);
    $before = array_slice($points, -10, max(1, min(5, $n - 5)));
    if (!$before) return null;

    $avg = fn(array $p) => array_sum(array_column($p, 'bpm')) / count($p);
    $delta = $avg($recent) - $avg($before);

    if ($delta >= 4)  return 'up';
    if ($delta <= -4) return 'down';
    return 'flat';
}

/**
 * Episodes: stretches where someone stayed outside a safe band long enough for
 * it to mean something.
 *
 * Three rules, each independent and each defined by a value AND a duration:
 *   tachycardia  — at or above an absolute bpm (default 150) for N minutes
 *   bradycardia  — at or below an absolute bpm (default 45) for N minutes
 *   strain       — at or above the elevated zone (75% of max) for N minutes
 *
 * The absolute thresholds are absolute on purpose: 150 means 150 to the person
 * reading the report and to the first-aid training they did, and a percentage
 * of an estimated maximum does not. Strain is the one percentage-based rule
 * because it is the one about effort rather than about a number a paramedic
 * would recognise.
 *
 * A strain episode entirely inside a tachycardia episode is dropped — the same
 * forty minutes listed twice under two names is not two findings, and the more
 * serious of the two is the one worth showing.
 */
function detectVitalsEpisodes(int $missionId): array {
    if (!vitalsEnabled()) {
        return [];
    }

    $config   = vitalsConfig();
    $series   = vitalsOrderedSeries($missionId);
    $context  = vitalsParticipantContext($missionId);
    $maxHr    = vitalsMaxHeartRate();
    $elevated = vitalsZoneBpm($config['elevated_pct'], $maxHr);
    $now      = time();

    $rules = [
        ['type' => 'tachycardia', 'severity' => 0, 'minutes' => $config['tachy_minutes'],
         'test' => fn(int $bpm) => $bpm >= $config['tachy_bpm'], 'threshold' => $config['tachy_bpm']],
        ['type' => 'bradycardia', 'severity' => 0, 'minutes' => $config['brady_minutes'],
         'test' => fn(int $bpm) => $bpm <= $config['brady_bpm'], 'threshold' => $config['brady_bpm']],
        ['type' => 'strain', 'severity' => 1, 'minutes' => $config['strain_minutes'],
         'test' => fn(int $bpm) => $bpm >= $elevated, 'threshold' => $elevated],
    ];

    $episodes = [];

    foreach ($series as $userId => $points) {
        $perType = [];

        foreach ($rules as $rule) {
            $runs = [];
            $run  = null;

            foreach ($points as $p) {
                $matches = ($rule['test'])($p['bpm']);
                $gapMinutes = $run ? (($p['ts'] - $run['last_ts']) / 60) : 0;

                if ($run && ($gapMinutes > VITALS_EPISODE_MAX_GAP_MINUTES || !$matches)) {
                    $runs[] = $run;
                    $run = null;
                }
                if (!$matches) continue;

                if (!$run) {
                    $run = ['from' => $p['ts'], 'last_ts' => $p['ts'], 'values' => []];
                }
                $run['last_ts'] = $p['ts'];
                $run['values'][] = $p['bpm'];
            }
            if ($run) $runs[] = $run;

            foreach ($runs as $r) {
                // +1 because a run of N one-minute buckets covers N minutes;
                // first and last timestamps are N-1 minutes apart.
                $minutes = (int) round(($r['last_ts'] - $r['from']) / 60) + 1;
                if ($minutes < $rule['minutes']) continue;

                $perType[$rule['type']][] = [
                    'user_id'   => $userId,
                    'type'      => $rule['type'],
                    'severity'  => $rule['severity'],
                    'threshold' => $rule['threshold'],
                    'from_ts'   => $r['from'],
                    'to_ts'     => $r['last_ts'],
                    'minutes'   => $minutes,
                    'bpm_peak'  => $rule['type'] === 'bradycardia' ? min($r['values']) : max($r['values']),
                    'bpm_avg'   => (int) round(array_sum($r['values']) / count($r['values'])),
                    // Still going: the run reaches the present, so this is not
                    // history being reviewed but a person to check on.
                    'active'    => ($now - $r['last_ts']) <= $config['stale_seconds'],
                ];
            }
        }

        foreach ($perType as $type => $found) {
            foreach ($found as $episode) {
                if ($type === 'strain') {
                    $covered = false;
                    foreach ($perType['tachycardia'] ?? [] as $t) {
                        if ($episode['from_ts'] >= $t['from_ts'] && $episode['to_ts'] <= $t['to_ts']) {
                            $covered = true;
                            break;
                        }
                    }
                    if ($covered) continue;
                }
                $episodes[] = $episode + [
                    'name'       => $context[$userId]['name'] ?? ('#' . $userId),
                    'team_label' => $context[$userId]['team_label'] ?? '',
                    'team_color' => $context[$userId]['team_color'] ?? null,
                    'from'       => date('H:i', $episode['from_ts']),
                    'to'         => date('H:i', $episode['to_ts']),
                    'date'       => date('d/m', $episode['from_ts']),
                ];
            }
        }
    }

    // Active first, then by severity, then most recent — the reading order of
    // someone who opened this page because something is happening.
    usort($episodes, function ($a, $b) {
        if ($a['active'] !== $b['active']) return $a['active'] ? -1 : 1;
        if ($a['severity'] !== $b['severity']) return $a['severity'] <=> $b['severity'];
        return $b['from_ts'] <=> $a['from_ts'];
    });

    return $episodes;
}

/**
 * Load per mission team: who is working hardest as a group.
 *
 * Averaged over every recorded minute of every member, not over the members'
 * own averages, so a team where one person did all the climbing does not read
 * the same as one where everybody shared it — the minutes are the work.
 */
function loadVitalsTeamLoadForMission(int $missionId, array $episodes = []): array {
    if (!vitalsEnabled()) {
        return [];
    }

    $config   = vitalsConfig();
    $series   = vitalsOrderedSeries($missionId);
    $context  = vitalsParticipantContext($missionId);
    $maxHr    = vitalsMaxHeartRate();
    $elevated = vitalsZoneBpm($config['elevated_pct'], $maxHr);

    $teams = [];
    foreach ($context as $userId => $info) {
        $points = $series[$userId] ?? [];
        if (!$points) continue;

        $label = $info['team_label'] !== '' ? $info['team_label'] : t('vitals.report_no_team');
        if (!isset($teams[$label])) {
            $teams[$label] = [
                'label' => $label, 'color' => $info['team_color'],
                'members' => 0, 'minutes' => 0, 'bpm_sum' => 0, 'bpm_max' => 0,
                'elevated_minutes' => 0, 'episodes' => 0,
            ];
        }

        $teams[$label]['members']++;
        foreach ($points as $p) {
            $teams[$label]['minutes']++;
            $teams[$label]['bpm_sum'] += $p['bpm'];
            $teams[$label]['bpm_max'] = max($teams[$label]['bpm_max'], $p['bpm']);
            if ($p['bpm'] >= $elevated) $teams[$label]['elevated_minutes']++;
        }
        foreach ($episodes as $e) {
            if ($e['user_id'] === $userId) $teams[$label]['episodes']++;
        }
    }

    foreach ($teams as &$team) {
        $team['bpm_avg'] = $team['minutes'] ? (int) round($team['bpm_sum'] / $team['minutes']) : null;
        unset($team['bpm_sum']);
    }
    unset($team);

    uasort($teams, fn($a, $b) => ($b['bpm_avg'] ?? 0) <=> ($a['bpm_avg'] ?? 0));
    return array_values($teams);
}

/**
 * How long somebody may stay continuously in the elevated zone before the
 * absence of a relief becomes the finding rather than the effort.
 *
 * Derived from the org's own strain threshold rather than being a second knob:
 * an organisation that considers twenty minutes above 75% an episode considers
 * forty minutes of it unrelieved. The floor stops a very short strain setting
 * from firing this on everyone who walks uphill.
 */
function vitalsUnrelievedMinutes(array $config): int {
    return max(30, ((int) ($config['strain_minutes'] ?? 20)) * 2);
}

/**
 * Minutes in hours and minutes once there are enough of them to matter.
 *
 * "3342λ" is a number the reader has to divide before it means anything, and
 * on a closed mission — where the last reading can be days old — that is every
 * row in the panel.
 */
function vitalsMinutesWords(int $minutes): string {
    if ($minutes < 60) {
        return max(0, $minutes) . 'λ';
    }
    $h = intdiv($minutes, 60);
    $m = $minutes % 60;
    if ($h >= 48) {
        return intdiv($h, 24) . ' ημέρες';
    }
    return $m > 0 ? $h . 'ω ' . $m . 'λ' : $h . 'ω';
}

/**
 * At most this many people are named in one finding; the count in the title
 * stays truthful and the rest become "+N ακόμη". Six names with a duration
 * each is a wall of text in a card meant to be read in one glance.
 */
const VITALS_PROBLEM_NAME_CAP = 4;

/** "Α, Β, Γ +2 ακόμη" — the cap applied, with the remainder acknowledged. */
function vitalsNameList(array $parts): string {
    $shown = array_slice($parts, 0, VITALS_PROBLEM_NAME_CAP);
    $rest  = count($parts) - count($shown);
    return implode(' · ', $shown) . ($rest > 0 ? ' +' . $rest . ' ακόμη' : '');
}

/**
 * A team is carrying a disproportionate share when its elevated-time ratio is
 * this many times the mission's own average. Not an absolute percentage: on a
 * mountain callout everybody is elevated most of the time, and the finding is
 * about IMBALANCE between teams, not about effort being high.
 */
const VITALS_TEAM_IMBALANCE_FACTOR = 1.5;

/** Below this much elevated time a team's ratio is noise, whatever it is. */
const VITALS_TEAM_IMBALANCE_MIN_MINUTES = 20;

/**
 * What is WRONG, in words, rather than what the numbers are.
 *
 * The rest of this page answers "what is everyone's heart doing" and leaves
 * the conclusion to the reader. At hour four of an eight-hour search, with six
 * tiles, three tables and a chart on screen, that reading is exactly the work
 * a tired coordinator does badly. This does it for them, in the same shape as
 * the Action Room's «Τι μου ξέφυγε» panel — and for the same reason it is
 * DETERMINISTIC. A heart-rate problem IS a threshold; there is no judgement
 * here for a model to add, and heart rate is Article 9 health data that has no
 * business leaving this server to have prose written about it.
 *
 * Pure: takes what the page has already computed and issues no query of its
 * own. The page reloads itself every thirty seconds while a mission is live.
 *
 * Returns ['findings' => [...], 'nothing_to_assess' => bool, 'expected' => int].
 * The second is the ordinary case for an organisation that owns no straps:
 * every volunteer reads zero, and a panel full of "no data" rows would be
 * worse than one sentence saying nobody is wearing a sensor.
 */
function detectVitalsProblems(array $now, array $episodes, array $teamLoad, array $config, int $elevatedBpm): array
{
    $volunteers = $now['volunteers'] ?? [];
    $summary    = $now['summary'] ?? [];
    $expected   = (int) ($summary['expected'] ?? count($volunteers));

    $byZone = fn(string $zone) => array_values(array_filter(
        $volunteers,
        fn($v) => ($v['zone'] ?? '') === $zone
    ));

    // "Nobody ever wore one" is NOT the same as summary['wearing'] === 0, which
    // also counts zero the moment everyone's signal goes stale — including a
    // finished mission with hours of recorded data behind it, where there is a
    // great deal to assess. The real test is whether anyone produced a reading
    // at all, which is exactly the volunteers NOT in the 'none' zone.
    $measured = count($volunteers) - count($byZone('none'));
    if ($measured === 0) {
        return ['findings' => [], 'nothing_to_assess' => true, 'expected' => $expected];
    }

    $findings = [];
    $withBpm = fn(array $rows) => vitalsNameList(array_map(
        fn($v) => trim((string) ($v['name'] ?? '')) . ' (' . (int) ($v['bpm'] ?? 0) . ')',
        $rows
    ));

    // ── Someone is measurably in trouble right now ──────────────────────
    $acute = [
        ['critical', 'bi-heart-pulse-fill', 'Σε ταχυκαρδία αυτή τη στιγμή',
         'Διακόψτε τη δραστηριότητά τους και ελέγξτε τους. '],
        ['low', 'bi-arrow-down-circle-fill', 'Σε βραδυκαρδία αυτή τη στιγμή',
         'Χαμηλοί παλμοί σε άνθρωπο που κινείται δεν είναι φυσιολογικοί — ελέγξτε τους. '],
    ];
    foreach ($acute as [$zone, $icon, $title, $advice]) {
        $rows = $byZone($zone);
        if (!$rows) continue;
        $findings[] = [
            'sev'    => 'high',
            'icon'   => $icon,
            'title'  => $title . ' (' . count($rows) . ')',
            'detail' => $advice . $withBpm($rows),
        ];
    }

    // ── Effort nobody has relieved ──────────────────────────────────────
    // The zone is not the finding; the DURATION with no relief is. Someone at
    // 145 bpm for four minutes is climbing. The same person at 145 for fifty
    // minutes is a rotation that never happened.
    $unrelieved = vitalsUnrelievedMinutes($config);
    $tired = array_values(array_filter(
        $volunteers,
        fn($v) => ($v['zone'] ?? '') === 'elevated'
            && ($v['zone_minutes'] ?? null) !== null
            && (int) $v['zone_minutes'] >= $unrelieved
    ));
    if ($tired) {
        usort($tired, fn($a, $b) => (int) $b['zone_minutes'] <=> (int) $a['zone_minutes']);
        $findings[] = [
            'sev'    => 'high',
            'icon'   => 'bi-hourglass-bottom',
            'title'  => 'Πάνω από ' . $unrelieved . ' λεπτά σε αυξημένους παλμούς χωρίς ανάπαυλα (' . count($tired) . ')',
            'detail' => 'Σκεφτείτε αντικατάσταση. ' . vitalsNameList(array_map(
                fn($v) => trim((string) ($v['name'] ?? '')) . ' — '
                    . vitalsMinutesWords((int) $v['zone_minutes']) . ' στη ζώνη',
                $tired
            )),
        ];
    }

    // ── A sensor that went quiet mid-operation ──────────────────────────
    // Not the same as never wearing one: this person WAS being monitored and
    // now is not, which is a question mark rather than a known absence.
    $stale = $byZone('stale');
    if ($stale) {
        usort($stale, fn($a, $b) => (int) ($b['age_seconds'] ?? 0) <=> (int) ($a['age_seconds'] ?? 0));
        $findings[] = [
            'sev'    => 'warn',
            'icon'   => 'bi-reception-0',
            'title'  => 'Έχασαν σήμα ενώ φορούσαν αισθητήρα (' . count($stale) . ')',
            'detail' => 'Ο ιμάντας μπορεί να έφυγε ή να έπεσε το Bluetooth. ' . vitalsNameList(array_map(
                fn($v) => trim((string) ($v['name'] ?? '')) . ' — '
                    . vitalsMinutesWords(max(1, (int) round(((int) ($v['age_seconds'] ?? 0)) / 60)))
                    . ' χωρίς μέτρηση',
                $stale
            )),
        ];
    }

    // ── One team doing more than its share ──────────────────────────────
    $withMinutes = array_values(array_filter($teamLoad, fn($t) => (int) ($t['minutes'] ?? 0) > 0));
    if (count($withMinutes) >= 2) {
        $totalMinutes  = array_sum(array_column($withMinutes, 'minutes'));
        $totalElevated = array_sum(array_column($withMinutes, 'elevated_minutes'));
        $missionRatio  = $totalMinutes > 0 ? $totalElevated / $totalMinutes : 0.0;
        if ($missionRatio > 0) {
            foreach ($withMinutes as $team) {
                $elevated = (int) $team['elevated_minutes'];
                $minutes  = (int) $team['minutes'];
                if ($elevated < VITALS_TEAM_IMBALANCE_MIN_MINUTES) continue;
                $ratio = $elevated / $minutes;
                if ($ratio < $missionRatio * VITALS_TEAM_IMBALANCE_FACTOR) continue;
                $findings[] = [
                    'sev'    => 'warn',
                    'icon'   => 'bi-diagram-3-fill',
                    'title'  => 'Η ' . $team['label'] . ' σηκώνει δυσανάλογο φορτίο',
                    'detail' => round($ratio * 100) . '% του χρόνου της σε αυξημένους παλμούς, έναντι '
                        . round($missionRatio * 100) . '% κατά μέσο όρο στην αποστολή'
                        . (!empty($team['bpm_avg']) ? ' · μέσος όρος ' . (int) $team['bpm_avg'] . ' bpm' : '') . '.',
                ];
            }
        }
    }

    // ── Clinical episodes that have already ended ───────────────────────
    // Worth one line, not a finding each: they are listed in full lower down,
    // and what the reader needs here is whether today had any at all.
    $past = array_values(array_filter(
        $episodes,
        fn($e) => ($e['type'] ?? '') !== 'strain' && empty($e['active'])
    ));
    if ($past) {
        $worst = 0;
        foreach ($past as $e) {
            $worst = max($worst, (int) ($e['bpm_peak'] ?? 0));
        }
        $findings[] = [
            'sev'    => 'warn',
            'icon'   => 'bi-clock-history',
            'title'  => 'Κλινικά επεισόδια που πέρασαν (' . count($past) . ')',
            'detail' => 'Τελείωσαν, αλλά συνέβησαν σε αυτή την αποστολή'
                . ($worst ? ' · ακραία τιμή ' . $worst . ' bpm' : '') . '.',
        ];
    }

    // ── People the report simply cannot see ─────────────────────────────
    $none = count($byZone('none'));
    if ($none > 0) {
        $findings[] = [
            'sev'    => 'info',
            'icon'   => 'bi-eye-slash',
            'title'  => $none . ' από ' . $expected . ' χωρίς καμία μέτρηση',
            'detail' => 'Ό,τι λέει αυτή η αναφορά αφορά μόνο τους ' . $measured
                . ' που κατέγραψαν παλμούς. Για τους υπόλοιπους δεν ξέρουμε τίποτα — που δεν σημαίνει ότι είναι καλά.',
        ];
    }

    $rank = ['high' => 0, 'warn' => 1, 'info' => 2];
    usort($findings, fn($a, $b) => ($rank[$a['sev']] ?? 3) <=> ($rank[$b['sev']] ?? 3));

    return ['findings' => $findings, 'nothing_to_assess' => false, 'expected' => $expected];
}
