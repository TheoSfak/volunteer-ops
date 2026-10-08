<?php
/**
 * VolunteerOps - Mass-casualty triage (Μαζικό Συμβάν / Διαλογή)
 *
 * A pile-up or a collapsed building produces thirty casualties in the first
 * ten minutes, and the one incident form (mission_incidents) cannot carry
 * that: it asks eight questions including a name, it sounds a siren at every
 * coordinator for every report, and each report then has to be acknowledged
 * and closed on its own. Triage is a different job — sort everybody fast,
 * find the reds, get them out first — so it gets its own tables and its own
 * rules, switched on per mission by command («Μαζικό Συμβάν»).
 *
 * The decisions this file encodes (the mission owner's, 2026-09-24):
 *
 *   · START for adults, JumpSTART for children. The category is DERIVED from
 *     the rescuer's yes/no answers, never typed, and the server recomputes it
 *     from the answers rather than trusting the client's result. The one
 *     exception is the direct choice for trained staff, stored as such.
 *
 *   · The team carries pre-numbered physical triage cards, so the card number
 *     is the victim's identity. Scanning or typing a card that is already on
 *     this mission is a re-assessment of that person, never a second person.
 *     When a rescuer has no card, the phone makes a code up (T<user>-<n>) that
 *     is unique without a network, so triage keeps working in a dead zone.
 *
 *   · Every approved participant can triage, and every category — black
 *     included — is visible to everyone on the mission. Only a casualty's
 *     name, phone and notes are masked, with the same helpers the incident
 *     log uses.
 *
 *   · A victim is created at the moment the colour is known. The card number
 *     is attached after, on the same screen: a rescuer called away between
 *     the two must never lose the casualty they just sorted.
 *
 * Lives in its own file for the same reason functions-vitals.php does: a
 * self-contained sub-domain with its own tables, and functions-warroom.php is
 * already past 8.000 lines.
 */

if (!defined('VOLUNTEEROPS')) {
    die('Direct access not permitted');
}

/** The four triage categories, in transport priority order. */
const TRIAGE_CATEGORIES = ['red', 'yellow', 'green', 'black'];

/**
 * Where a casualty physically is. Deliberately three states and no "closed":
 * a transported casualty stays on the board (greyed, at the bottom) because
 * "where did we send the woman from the second car" is asked for hours.
 */
const TRIAGE_STATUSES = ['on_scene', 'at_ccp', 'transported'];

/**
 * How long after the last assessment a casualty still waiting on scene is
 * due to be looked at again. START is a snapshot: a yellow can be red ten
 * minutes later, and the only defence is going back. Black has no entry —
 * nobody re-triages the dead to a schedule. Minutes.
 */
const TRIAGE_RETRIAGE_MINUTES = ['red' => 15, 'yellow' => 30, 'green' => 60];

/** Longest physical card number accepted, after normalisation. */
const TRIAGE_CARD_MAX = 30;

/** Largest "walking wounded sent to the green area" count one tap may add. */
const TRIAGE_BULK_MAX = 200;

/** Most casualties one «loaded a vehicle» tap may mark transported. */
const TRIAGE_BATCH_MAX = 50;

/**
 * The two protocols as decision trees. Each node is a yes/no question; a
 * branch is either the next node's key or a leaf [category, reason].
 *
 * THIS IS HALF OF A PAIR. assets/js/triage.js carries the identical trees for
 * the phone (it has to ask the questions offline), and both are pinned to
 * tests/fixtures/triage-cases.json by TriageProtocolTest and
 * tests/js/triage.test.js. Change one without the other and CI fails —
 * which is the point: a rescuer's screen and the server must never disagree
 * about what colour an answer means.
 *
 * Question wording is deliberately the polarity the protocols use, so "yes"
 * is sometimes the good answer (walks, breathes, obeys) and sometimes the bad
 * one (breathing over 30, no radial pulse). The UI paints ΝΑΙ and ΟΧΙ in the
 * same neutral colour for exactly that reason: a green ΝΑΙ would nudge the
 * rescuer towards it.
 */
function triageProtocols(): array {
    return [
        // START — Simple Triage And Rapid Treatment, adults.
        'start' => [
            'root'  => 'walk',
            'nodes' => [
                // Walking is green only when nothing is bleeding badly: a
                // person can walk and still be bleeding out (v3.376.0).
                'walk'       => ['yes' => 'walk_bleeding', 'no' => 'breathing'],
                'walk_bleeding' => ['yes' => ['red', 'major_bleeding'], 'no' => ['green', 'walks'], 'legacy_no' => true],
                'breathing'  => ['yes' => 'rr_over_30', 'no' => 'airway'],
                'airway'     => ['yes' => ['red', 'breathes_after_airway'], 'no' => ['black', 'apneic']],
                'rr_over_30' => ['yes' => ['red', 'rr_over_30'], 'no' => 'bleeding'],
                // Added v3.371.0. START has no step for bleeding, and a
                // casualty who breathes normally, obeys and bleeds out would
                // come out yellow. «Yes» is red; the question tells the
                // rescuer to stop the bleeding NOW, before moving on.
                // `legacy_no`: see triageEvaluate().
                'bleeding'   => ['yes' => ['red', 'major_bleeding'], 'no' => 'perfusion', 'legacy_no' => true],
                // Radial pulse absent, OR capillary refill over 2 seconds.
                'perfusion'  => ['yes' => ['red', 'poor_perfusion'], 'no' => 'obeys'],
                'obeys'      => ['yes' => ['yellow', 'obeys'], 'no' => ['red', 'no_obey']],
            ],
        ],
        // JumpSTART — the paediatric variant (roughly ages 1-8). Two real
        // differences, both life-and-death: an apnoeic child with a pulse
        // gets five rescue breaths before anyone calls it, and the breathing
        // band is 15-45, not "over 30".
        'jumpstart' => [
            'root'  => 'walk',
            'nodes' => [
                'walk'           => ['yes' => 'walk_bleeding', 'no' => 'breathing'],
                'walk_bleeding'  => ['yes' => ['red', 'major_bleeding'], 'no' => ['green', 'walks'], 'legacy_no' => true],
                'breathing'      => ['yes' => 'rr_child', 'no' => 'airway'],
                'airway'         => ['yes' => ['red', 'breathes_after_airway'], 'no' => 'pulse_apneic'],
                'pulse_apneic'   => ['yes' => 'rescue_breaths', 'no' => ['black', 'apneic_no_pulse']],
                'rescue_breaths' => ['yes' => ['red', 'breathes_after_rescue'], 'no' => ['black', 'apneic']],
                // Breathing under 15 or over 45 a minute.
                'rr_child'       => ['yes' => ['red', 'rr_child'], 'no' => 'bleeding'],
                // Added v3.371.0, same step as in START.
                'bleeding'       => ['yes' => ['red', 'major_bleeding'], 'no' => 'pulse', 'legacy_no' => true],
                'pulse'          => ['yes' => 'avpu', 'no' => ['red', 'no_pulse']],
                // AVPU: Alert, responds to Voice, or localises Pain appropriately.
                'avpu'           => ['yes' => ['yellow', 'avpu_ok'], 'no' => ['red', 'avpu']],
            ],
        ],
    ];
}

/**
 * Walks a protocol tree with the rescuer's answers. Returns
 * ['category', 'reason', 'path'] where path is only the answers actually on
 * the route taken (anything extra the client sent is dropped, so what gets
 * stored is exactly what decided the colour), or null when an answer the
 * route needs is missing or is not a plain yes/no.
 *
 * $tolerateLegacy is for the SERVER only (recordTriageAssessment): a phone can
 * hold an assessment queued offline from before a question was added to the
 * tree, and refusing it would lose a casualty over a question its rescuer was
 * never shown. A node marked `legacy_no` is then taken as «no» when its answer
 * is absent, which is exactly what the old tree did, and the stored path
 * leaves it out (so the record shows it was not asked). The phone's own
 * walk through the tree never sets this: it always asks.
 */
function triageEvaluate(string $protocol, array $answers, bool $tolerateLegacy = false): ?array {
    $tree = triageProtocols()[$protocol] ?? null;
    if (!$tree) {
        return null;
    }
    $node = $tree['root'];
    $path = [];
    // Bounded by the deepest tree, so a malformed tree can never loop.
    for ($step = 0; $step < 12; $step++) {
        if (!array_key_exists($node, $answers)) {
            if ($tolerateLegacy && !empty($tree['nodes'][$node]['legacy_no'])) {
                // The «no» of that question is either the next question or,
                // for the walking wounded, the end of the route.
                $no = $tree['nodes'][$node]['no'];
                if (is_array($no)) {
                    return ['category' => $no[0], 'reason' => $no[1], 'path' => $path];
                }
                $node = $no;
                continue;
            }
            return null;
        }
        $raw = $answers[$node];
        if ($raw === true || $raw === 1 || $raw === '1') {
            $yes = true;
        } elseif ($raw === false || $raw === 0 || $raw === '0') {
            $yes = false;
        } else {
            return null;
        }
        $path[$node] = $yes;
        $branch = $tree['nodes'][$node][$yes ? 'yes' : 'no'];
        if (is_array($branch)) {
            return ['category' => $branch[0], 'reason' => $branch[1], 'path' => $path];
        }
        $node = $branch;
    }
    return null;
}

/**
 * Secondary triage at the collection point: the Triage Revised Trauma Score
 * (T-RTS, Champion 1989), the score the UK's "Triage Sort" uses. Three
 * measurements, each coded 0-4, summed; 12 is physiologically normal.
 *
 *   respiratory rate  10-29 = 4   >=30 = 3   6-9 = 2   1-5 = 1   0 = 0
 *   systolic pressure >=90  = 4   76-89 = 3   50-75 = 2  1-49 = 1  0 = 0
 *   GCS               13-15 = 4   9-12 = 3    6-8 = 2   4-5 = 1    3 = 0
 *
 *   total 12 -> green, 11 -> yellow, 1-10 -> red, 0 -> black.
 *
 * It is a SUGGESTION. The rescuer accepts or changes the colour, and the
 * phone warns when the score would lower the casualty's category, because
 * vital signs miss fractures and internal injuries. The score is validated
 * for adults only, so a child gets no suggestion and the colour is chosen.
 *
 * Returns ['rts', 'category', 'vitals' => [rr, sbp, gcs]] or null when a
 * value is missing or is not a whole number in range. Mirrored by
 * triageSecondaryScore() in assets/js/triage.js, pinned by the same fixture.
 */
function triageSecondaryScore(array $v): ?array {
    $ranges = ['rr' => [0, 80], 'sbp' => [0, 300], 'gcs' => [3, 15]];
    $clean = [];
    foreach ($ranges as $key => [$min, $max]) {
        $raw = $v[$key] ?? null;
        if (is_int($raw) || (is_string($raw) && preg_match('/^\d{1,3}$/', $raw))) {
            $n = (int) $raw;
        } elseif (is_float($raw) && floor($raw) === $raw) {
            $n = (int) $raw;
        } else {
            return null;
        }
        if ($n < $min || $n > $max) {
            return null;
        }
        $clean[$key] = $n;
    }
    $rr = $clean['rr'];
    $sbp = $clean['sbp'];
    $gcs = $clean['gcs'];
    $rrCode = $rr === 0 ? 0 : ($rr <= 5 ? 1 : ($rr <= 9 ? 2 : ($rr <= 29 ? 4 : 3)));
    $sbpCode = $sbp === 0 ? 0 : ($sbp <= 49 ? 1 : ($sbp <= 75 ? 2 : ($sbp <= 89 ? 3 : 4)));
    $gcsCode = $gcs <= 3 ? 0 : ($gcs <= 5 ? 1 : ($gcs <= 8 ? 2 : ($gcs <= 12 ? 3 : 4)));
    $rts = $rrCode + $sbpCode + $gcsCode;
    $category = $rts === 0 ? 'black' : ($rts <= 10 ? 'red' : ($rts === 11 ? 'yellow' : 'green'));
    return ['rts' => $rts, 'category' => $category, 'vitals' => $clean];
}

/**
 * A card number as typed or scanned, made comparable. Cards are compared as
 * strings, so "0457", " 0457 " and "0457" typed on a Greek keyboard must all
 * land on the same person: whitespace goes, letters are upper-cased, and the
 * fourteen Greek capitals that look exactly like Latin ones are folded to
 * Latin — a rescuer switching keyboard layout mid-incident must not create a
 * second casualty. Anything outside A-Z, 0-9 and "-" is dropped.
 *
 * Mirrored by normalizeTriageCardNo() in assets/js/triage.js, pinned by the
 * same fixture as the protocol trees.
 */
function normalizeTriageCardNo(?string $raw): ?string {
    if ($raw === null) {
        return null;
    }
    $s = mb_strtoupper(trim($raw), 'UTF-8');
    $s = strtr($s, [
        'Α' => 'A', 'Β' => 'B', 'Ε' => 'E', 'Ζ' => 'Z', 'Η' => 'H', 'Ι' => 'I', 'Κ' => 'K',
        'Μ' => 'M', 'Ν' => 'N', 'Ο' => 'O', 'Ρ' => 'P', 'Τ' => 'T', 'Υ' => 'Y', 'Χ' => 'X',
    ]);
    $s = preg_replace('/[^A-Z0-9\-]/', '', $s);
    $s = trim((string) $s, '-');
    if ($s === '') {
        return null;
    }
    return mb_substr($s, 0, TRIAGE_CARD_MAX);
}

/**
 * The code a phone makes up for a casualty when the rescuer has no card:
 * T<user id>-<sequence>. Unique without a network because the user id is.
 */
function triageFallbackCode(int $userId, int $seq): string {
    return 'T' . $userId . '-' . str_pad((string) max(1, $seq), 2, '0', STR_PAD_LEFT);
}

/** A client-generated id (victim or assessment). Anything else is refused. */
function isValidTriageUuid(?string $uuid): bool {
    return is_string($uuid) && (bool) preg_match('/^[A-Za-z0-9\-]{8,64}$/', $uuid);
}

function triageCategoryLabel(string $category, ?string $lang = null): string {
    return t('triage.cat.' . $category, [], $lang);
}

function triageStatusLabel(string $status, ?string $lang = null): string {
    return t('triage.status.' . $status, [], $lang);
}

function triageReasonLabel(?string $reason, ?string $lang = null): string {
    if (!$reason) {
        return '';
    }
    $key = 'triage.reason.' . $reason;
    $text = t($key, [], $lang);
    return $text !== $key ? $text : $reason;
}

/** The mission's Μαζικό Συμβάν row, or null when it was never switched on. */
/**
 * The size-up: command's first read of the scene, filled in AFTER the switch
 * is on and never in the way of it (activation is one tap; this is what
 * follows). Modelled on the UK major-incident METHANE report, cut to what a
 * rescue team here can fill in: hazards, access, an estimate of how many
 * casualties, what is needed, and whether the ambulance service knows.
 * Hazards are shown to everybody on the mission, because the people walking
 * in should read them before they go.
 */
const TRIAGE_SIZEUP_HAZARDS = ['fire', 'collapse', 'rockfall', 'hazmat', 'electrical', 'water', 'weather', 'traffic'];
const TRIAGE_SIZEUP_RESOURCES = ['ambulances', 'helicopter', 'fire_service', 'police', 'doctor', 'rescuers', 'equipment'];
const TRIAGE_SIZEUP_ACCESS = ['open', 'partial', 'none'];

/**
 * A size-up as posted, reduced to what is allowed: unknown keys dropped, lists
 * limited to the known options (in their canonical order, no duplicates),
 * notes trimmed and cut, the estimate a whole number 0-999 or null. Always
 * returns the full shape, so readers never test for a missing key.
 */
function normalizeTriageSizeup(array $in): array {
    $pick = function ($list, array $allowed): array {
        $list = is_array($list) ? $list : [];
        return array_values(array_intersect($allowed, array_map('strval', $list)));
    };
    $note = fn($v): string => mb_substr(trim((string) ($v ?? '')), 0, 500);
    $estimate = $in['casualties_estimate'] ?? null;
    if (is_string($estimate) && preg_match('/^\d{1,3}$/', trim($estimate))) {
        $estimate = (int) $estimate;
    }
    $estimate = is_int($estimate) && $estimate >= 0 && $estimate <= 999 ? $estimate : null;
    $access = (string) ($in['access'] ?? '');
    return [
        'hazards'             => $pick($in['hazards'] ?? [], TRIAGE_SIZEUP_HAZARDS),
        'hazards_note'        => $note($in['hazards_note'] ?? ''),
        'access'              => in_array($access, TRIAGE_SIZEUP_ACCESS, true) ? $access : null,
        'access_note'         => $note($in['access_note'] ?? ''),
        'casualties_estimate' => $estimate,
        'resources'           => $pick($in['resources'] ?? [], TRIAGE_SIZEUP_RESOURCES),
        'resources_note'      => $note($in['resources_note'] ?? ''),
        'ekab_notified'       => !empty($in['ekab_notified']) && $in['ekab_notified'] !== '0' && $in['ekab_notified'] !== 'false',
    ];
}

/** True when a normalised size-up has nothing in it. */
function triageSizeupIsEmpty(array $s): bool {
    return !$s['hazards'] && $s['hazards_note'] === '' && $s['access'] === null && $s['access_note'] === ''
        && $s['casualties_estimate'] === null && !$s['resources'] && $s['resources_note'] === '' && !$s['ekab_notified'];
}

/**
 * Saves the size-up of a mission that has had a Μαζικό Συμβάν. Works while
 * the incident is on or after it (a debrief corrects what was written).
 * Returns ['ok' => true, 'hazards_added' => keys that were not there before]
 * or ['ok' => false, 'error' => lang key].
 */
function saveMissionMciSizeup(int $missionId, array $in, int $userId): array {
    $mci = loadMissionMci($missionId);
    if (!$mci) {
        return ['ok' => false, 'error' => 'triage.err_inactive'];
    }
    $new = normalizeTriageSizeup($in);
    $old = !empty($mci['sizeup']) ? normalizeTriageSizeup((array) json_decode((string) $mci['sizeup'], true)) : normalizeTriageSizeup([]);
    if (triageSizeupIsEmpty($new)) {
        dbExecute("UPDATE mission_mci SET sizeup = NULL, sizeup_at = NULL, sizeup_by = NULL WHERE mission_id = ?", [$missionId]);
    } else {
        dbExecute(
            "UPDATE mission_mci SET sizeup = ?, sizeup_at = NOW(), sizeup_by = ? WHERE mission_id = ?",
            [json_encode($new, JSON_UNESCAPED_UNICODE), $userId, $missionId]
        );
    }
    if ($new !== $old) {
        dbInsert("INSERT INTO mission_mci_log (mission_id, action, user_id) VALUES (?, 'sizeup', ?)", [$missionId, $userId]);
    }
    return ['ok' => true, 'hazards_added' => array_values(array_diff($new['hazards'], $old['hazards']))];
}

function loadMissionMci(int $missionId): ?array {
    $row = dbFetchOne(
        "SELECT mc.*, u.name AS activated_by_name, (SELECT name FROM users WHERE id = mc.sizeup_by) AS sizeup_by_name
         FROM mission_mci mc
         LEFT JOIN users u ON u.id = mc.activated_by
         WHERE mc.mission_id = ?",
        [$missionId]
    );
    return $row ?: null;
}

function isMissionMciActive(int $missionId): bool {
    return (bool) dbFetchValue("SELECT is_active FROM mission_mci WHERE mission_id = ?", [$missionId]);
}

/**
 * Switch Μαζικό Συμβάν on or off. Returns true when the state actually
 * changed, so the caller only notifies and logs a real transition — a double
 * tap on «Ενεργοποίηση» must not page the whole field twice.
 */
function setMissionMciActive(int $missionId, bool $active, int $userId): bool {
    $current = loadMissionMci($missionId);
    if ($current && (bool) $current['is_active'] === $active) {
        return false;
    }
    if (!$current && !$active) {
        return false;
    }
    if ($active) {
        dbExecute(
            "INSERT INTO mission_mci (mission_id, is_active, activated_at, activated_by)
             VALUES (?, 1, NOW(), ?)
             ON DUPLICATE KEY UPDATE is_active = 1, activated_at = NOW(), activated_by = VALUES(activated_by),
                                     deactivated_at = NULL, deactivated_by = NULL",
            [$missionId, $userId]
        );
    } else {
        dbExecute(
            "UPDATE mission_mci SET is_active = 0, deactivated_at = NOW(), deactivated_by = ? WHERE mission_id = ?",
            [$userId, $missionId]
        );
    }
    dbInsert(
        "INSERT INTO mission_mci_log (mission_id, action, user_id) VALUES (?, ?, ?)",
        [$missionId, $active ? 'activated' : 'deactivated', $userId]
    );
    return true;
}

/**
 * Place (or clear, with null coordinates) the casualty collection point or
 * the green area. Both are single points per mission: an incident with two
 * collection points is two incidents' worth of command, not one.
 */
function setMissionMciPoint(int $missionId, string $kind, ?float $lat, ?float $lng, int $userId): void {
    $col = $kind === 'green' ? 'green' : 'ccp';
    dbExecute(
        "INSERT INTO mission_mci (mission_id, is_active, {$col}_lat, {$col}_lng) VALUES (?, 0, ?, ?)
         ON DUPLICATE KEY UPDATE {$col}_lat = VALUES({$col}_lat), {$col}_lng = VALUES({$col}_lng)",
        [$missionId, $lat, $lng]
    );
    dbInsert(
        "INSERT INTO mission_mci_log (mission_id, action, user_id, lat, lng) VALUES (?, ?, ?, ?, ?)",
        [$missionId, $col . '_set', $userId, $lat, $lng]
    );
}

/** The next sequence number this user's phone should use for a no-card code. */
function nextTriageFallbackSeq(int $missionId, int $userId): int {
    $prefix = 'T' . $userId . '-';
    $codes = dbFetchAll(
        "SELECT fallback_code FROM mission_triage_victims WHERE mission_id = ? AND fallback_code LIKE ?",
        [$missionId, $prefix . '%']
    );
    $max = 0;
    foreach ($codes as $row) {
        $n = (int) substr($row['fallback_code'], strlen($prefix));
        $max = max($max, $n);
    }
    return $max + 1;
}

/**
 * Re-derives a victim's current category from its assessments: the one with
 * the latest FIELD time wins. Not "the last one inserted" — an assessment
 * queued on a phone in a dead zone can reach the server twenty minutes after
 * a newer one made by somebody else, and must not overwrite it.
 */
function refreshTriageVictimCategory(int $victimId): void {
    // previous_category is what the timeline and the report call a
    // re-triage ("yellow → red"), so it follows the same field-time order:
    // a merge brings in assessments recorded against another row, and a late
    // delivery lands in the middle of the history, and both would otherwise
    // leave a link pointing at whatever happened to be current at insert time.
    $previous = null;
    foreach (dbFetchAll(
        "SELECT id, category, previous_category FROM mission_triage_assessments
         WHERE victim_id = ? ORDER BY assessed_at ASC, id ASC",
        [$victimId]
    ) as $row) {
        if ($row['previous_category'] !== $previous) {
            dbExecute("UPDATE mission_triage_assessments SET previous_category = ? WHERE id = ?", [$previous, (int) $row['id']]);
        }
        $previous = $row['category'];
    }
    $latest = dbFetchOne(
        "SELECT category, reason, assessed_at FROM mission_triage_assessments
         WHERE victim_id = ? ORDER BY assessed_at DESC, id DESC LIMIT 1",
        [$victimId]
    );
    $first = dbFetchValue("SELECT MIN(assessed_at) FROM mission_triage_assessments WHERE victim_id = ?", [$victimId]);
    if (!$latest) {
        return;
    }
    dbExecute(
        "UPDATE mission_triage_victims
         SET category = ?, reason = ?, last_assessed_at = ?, first_assessed_at = ?
         WHERE id = ?",
        [$latest['category'], $latest['reason'], $latest['assessed_at'], $first, $victimId]
    );
}

/**
 * A vehicle (or a helicopter) leaves with several casualties: marks them all
 * transported with the same vehicle and destination, in one transaction so the
 * board never shows half a load gone. Casualties already transported or not
 * on this mission are left alone (their vehicle and time are not rewritten).
 * Returns ['ok' => true, 'count' => how many were marked] or ['ok' => false,
 * 'error' => lang key].
 */
function setTriageVictimsTransported(int $missionId, array $victimIds, ?string $vehicle, ?string $destination, int $userId): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $victimIds), fn($id) => $id > 0)));
    if (!$ids || count($ids) > TRIAGE_BATCH_MAX) {
        return ['ok' => false, 'error' => 'triage.err_invalid'];
    }
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $rows = dbFetchAll(
        "SELECT id FROM mission_triage_victims WHERE mission_id = ? AND status <> 'transported' AND id IN ($marks)",
        array_merge([$missionId], $ids)
    );
    if (!$rows) {
        return ['ok' => false, 'error' => 'triage.err_not_found'];
    }
    $pdo = db();
    $ownTransaction = !$pdo->inTransaction();
    if ($ownTransaction) {
        $pdo->beginTransaction();
    }
    try {
        foreach ($rows as $row) {
            setTriageVictimStatus($missionId, (int) $row['id'], 'transported', $vehicle, $destination, $userId);
        }
        if ($ownTransaction) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($ownTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    return ['ok' => true, 'count' => count($rows)];
}

/**
 * Records one assessment — a first triage or a re-triage — and returns what
 * happened. The single write path for both, so the phone's online call and
 * its offline-queue replay are the same request.
 *
 * $in keys: victim_uuid, assessment_uuid, card_no, fallback_code, protocol
 * ('start'|'jumpstart'|'direct'|'secondary'), answers (array; for
 * 'secondary' the vitals rr/sbp/gcs), category (direct and secondary),
 * age_group, lat, lng, accuracy, assessed_at (field time, already resolved by
 * resolveEventTimestamp()), reported_at.
 *
 * Which person it is, in order: the victim_uuid the phone already knows; else
 * the card number, if that card is on this mission; else a new casualty.
 *
 * Returns ['ok' => true, 'victim_id', 'created', 'duplicate', 'previous_category',
 * 'category', 'reason', 'card_conflict'] or ['ok' => false, 'error' => lang key].
 */
function recordTriageAssessment(int $missionId, int $userId, array $in): array {
    $victimUuid = (string) ($in['victim_uuid'] ?? '');
    $assessmentUuid = (string) ($in['assessment_uuid'] ?? '');
    if (!isValidTriageUuid($victimUuid) || !isValidTriageUuid($assessmentUuid)) {
        return ['ok' => false, 'error' => 'triage.err_invalid'];
    }

    // A replay of something already stored: answer as if it just happened,
    // so the queue drops it, and change nothing.
    $existingAssessment = dbFetchOne(
        "SELECT a.victim_id, a.category, a.reason FROM mission_triage_assessments a
         WHERE a.mission_id = ? AND a.assessment_uuid = ?",
        [$missionId, $assessmentUuid]
    );
    if ($existingAssessment) {
        return [
            'ok' => true, 'victim_id' => (int) $existingAssessment['victim_id'], 'created' => false,
            'duplicate' => true, 'previous_category' => null,
            'category' => $existingAssessment['category'], 'reason' => $existingAssessment['reason'],
            'card_conflict' => null,
        ];
    }

    $protocol = (string) ($in['protocol'] ?? '');
    if ($protocol === 'direct') {
        $category = (string) ($in['category'] ?? '');
        if (!in_array($category, TRIAGE_CATEGORIES, true)) {
            return ['ok' => false, 'error' => 'triage.err_invalid'];
        }
        $reason = 'direct';
        $path = null;
    } elseif ($protocol === 'secondary') {
        // Decided below, once the casualty is found: whether a score applies
        // depends on whether it is a child.
        $category = '';
        $reason = '';
        $path = null;
    } else {
        $result = triageEvaluate($protocol, is_array($in['answers'] ?? null) ? $in['answers'] : [], true);
        if (!$result) {
            return ['ok' => false, 'error' => 'triage.err_answers'];
        }
        $category = $result['category'];
        $reason = $result['reason'];
        $path = $result['path'];
    }

    $ageGroup = ($in['age_group'] ?? '') === 'child' || $protocol === 'jumpstart' ? 'child' : 'adult';
    $cardNo = normalizeTriageCardNo($in['card_no'] ?? null);
    $lat = isset($in['lat']) && is_numeric($in['lat']) ? (float) $in['lat'] : null;
    $lng = isset($in['lng']) && is_numeric($in['lng']) ? (float) $in['lng'] : null;
    if ($lat === null || $lng === null || abs($lat) > 90 || abs($lng) > 180) {
        $lat = null;
        $lng = null;
    }
    $accuracy = parseAccuracyMeters($in['accuracy'] ?? null, $lat);
    $assessedAt = (string) ($in['assessed_at'] ?? date('Y-m-d H:i:s'));
    $reportedAt = (string) ($in['reported_at'] ?? $assessedAt);
    $teamId = getUserTeamIdForMission($missionId, $userId);

    $victim = dbFetchOne(
        "SELECT id, category, card_no, age_group FROM mission_triage_victims WHERE mission_id = ? AND victim_uuid = ?",
        [$missionId, $victimUuid]
    );
    if (!$victim && $cardNo !== null) {
        $victim = dbFetchOne(
            "SELECT id, category, card_no, age_group FROM mission_triage_victims WHERE mission_id = ? AND card_no = ?",
            [$missionId, $cardNo]
        );
    }

    if ($protocol === 'secondary') {
        // A second look at somebody already triaged: it never creates a casualty.
        if (!$victim) {
            return ['ok' => false, 'error' => 'triage.err_secondary_unknown'];
        }
        $chosen = (string) ($in['category'] ?? '');
        $chosenOk = in_array($chosen, TRIAGE_CATEGORIES, true);
        $vitals = is_array($in['answers'] ?? null) ? $in['answers'] : [];
        // The T-RTS is validated for adults; a child gets the vitals recorded
        // and the colour from the rescuer.
        $score = $victim['age_group'] === 'child' ? null : triageSecondaryScore($vitals);
        if ($score) {
            $category = $chosenOk ? $chosen : $score['category'];
            $reason = $category === $score['category'] ? 'trts' : 'secondary_override';
            $path = $score['vitals'] + ['rts' => $score['rts']];
        } else {
            if (!$chosenOk) {
                return ['ok' => false, 'error' => 'triage.err_answers'];
            }
            $category = $chosen;
            $reason = 'secondary_manual';
            $path = [];
            foreach (['rr', 'sbp', 'gcs'] as $key) {
                if (isset($vitals[$key]) && is_numeric($vitals[$key]) && (int) $vitals[$key] >= 0 && (int) $vitals[$key] <= 999) {
                    $path[$key] = (int) $vitals[$key];
                }
            }
            $path = $path ?: null;
        }
    }

    $created = false;
    $cardConflict = null;
    $pdo = db();
    $ownTransaction = !$pdo->inTransaction();
    if ($ownTransaction) {
        $pdo->beginTransaction();
    }
    try {
        if (!$victim) {
            $fallback = (string) ($in['fallback_code'] ?? '');
            if (!preg_match('/^T' . $userId . '-\d{1,6}$/', $fallback)) {
                $fallback = triageFallbackCode($userId, nextTriageFallbackSeq($missionId, $userId));
            }
            // Same user on two phones, or a cleared browser: the made-up code
            // is taken. Move on to the next free number rather than refuse a
            // casualty over a label.
            if (dbFetchValue("SELECT id FROM mission_triage_victims WHERE mission_id = ? AND fallback_code = ?", [$missionId, $fallback])) {
                $fallback = triageFallbackCode($userId, nextTriageFallbackSeq($missionId, $userId));
            }
            $victimId = (int) dbInsert(
                "INSERT INTO mission_triage_victims
                    (mission_id, victim_uuid, card_no, fallback_code, category, reason, age_group,
                     lat, lng, accuracy_m, created_by, team_id, first_assessed_at, last_assessed_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [$missionId, $victimUuid, $cardNo, $fallback, $category, $reason, $ageGroup,
                 $lat, $lng, $accuracy, $userId, $teamId, $assessedAt, $assessedAt]
            );
            $created = true;
            $previous = null;
        } else {
            $victimId = (int) $victim['id'];
            $previous = $victim['category'];
            // The phone knew this casualty by its uuid and has now also got a
            // card number for it — attach it, unless that card is somebody
            // else's, which is for the rescuer to resolve, not the server.
            if ($cardNo !== null && $victim['card_no'] === null) {
                $owner = dbFetchValue(
                    "SELECT id FROM mission_triage_victims WHERE mission_id = ? AND card_no = ? AND id <> ?",
                    [$missionId, $cardNo, $victimId]
                );
                if ($owner) {
                    $cardConflict = $cardNo;
                } else {
                    dbExecute("UPDATE mission_triage_victims SET card_no = ? WHERE id = ?", [$cardNo, $victimId]);
                }
            }
            // A re-triage from somewhere else is also a better idea of where
            // the person is now (moved to the collection point, say) — but
            // only when this assessment came with a position at all.
            if ($lat !== null) {
                dbExecute(
                    "UPDATE mission_triage_victims SET lat = ?, lng = ?, accuracy_m = ? WHERE id = ? AND ? >= last_assessed_at",
                    [$lat, $lng, $accuracy, $victimId, $assessedAt]
                );
            }
            if ($ageGroup === 'child') {
                dbExecute("UPDATE mission_triage_victims SET age_group = 'child' WHERE id = ?", [$victimId]);
            }
        }

        dbInsert(
            "INSERT INTO mission_triage_assessments
                (victim_id, mission_id, assessment_uuid, category, reason, protocol, answers, previous_category,
                 assessed_by, team_id, lat, lng, accuracy_m, assessed_at, reported_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [$victimId, $missionId, $assessmentUuid, $category, $reason, $protocol,
             $path !== null ? json_encode($path) : null, $previous,
             $userId, $teamId, $lat, $lng, $accuracy, $assessedAt, $reportedAt]
        );
        if (!$created) {
            refreshTriageVictimCategory($victimId);
        }
        if ($ownTransaction) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($ownTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    $current = dbFetchOne("SELECT category, reason FROM mission_triage_victims WHERE id = ?", [$victimId]);
    return [
        'ok' => true,
        'victim_id' => $victimId,
        'created' => $created,
        'duplicate' => false,
        'previous_category' => $previous,
        'category' => $current['category'],
        'reason' => $current['reason'],
        'card_conflict' => $cardConflict,
    ];
}

/**
 * Attach a physical card number to a casualty the phone created without
 * one. Returns ['ok' => true] or ['ok' => false, 'error', 'owner' => victim]
 * when the card already belongs to somebody else on this mission — the
 * rescuer then decides: same person (merge) or a mistyped number.
 */
function bindTriageCard(int $missionId, string $victimUuid, ?string $rawCard): array {
    $cardNo = normalizeTriageCardNo($rawCard);
    if ($cardNo === null || !isValidTriageUuid($victimUuid)) {
        return ['ok' => false, 'error' => 'triage.err_card_invalid'];
    }
    $victim = dbFetchOne(
        "SELECT id, card_no FROM mission_triage_victims WHERE mission_id = ? AND victim_uuid = ?",
        [$missionId, $victimUuid]
    );
    if (!$victim) {
        return ['ok' => false, 'error' => 'triage.err_not_found'];
    }
    if ($victim['card_no'] === $cardNo) {
        return ['ok' => true, 'victim_id' => (int) $victim['id'], 'card_no' => $cardNo];
    }
    $owner = dbFetchOne(
        "SELECT id, victim_uuid, card_no, fallback_code, category, last_assessed_at FROM mission_triage_victims
         WHERE mission_id = ? AND card_no = ? AND id <> ?",
        [$missionId, $cardNo, (int) $victim['id']]
    );
    if ($owner) {
        return [
            'ok' => false, 'error' => 'triage.err_card_in_use', 'card_no' => $cardNo,
            'owner' => [
                'code' => $owner['card_no'],
                'category' => $owner['category'],
                'at' => date('H:i', strtotime($owner['last_assessed_at'])),
            ],
        ];
    }
    dbExecute("UPDATE mission_triage_victims SET card_no = ? WHERE id = ?", [$cardNo, (int) $victim['id']]);
    return ['ok' => true, 'victim_id' => (int) $victim['id'], 'card_no' => $cardNo];
}

/**
 * The rescuer says the casualty they just sorted is the same person as the
 * card's owner: fold the new record into the card's. Every assessment moves
 * across (the history is the whole point of re-triage), the card's owner is
 * re-derived, and the duplicate disappears. Refused when the duplicate has a
 * card of its own — two cards on one person is a question for command.
 */
function mergeTriageVictimIntoCard(int $missionId, string $fromUuid, ?string $rawCard): array {
    $cardNo = normalizeTriageCardNo($rawCard);
    $from = isValidTriageUuid($fromUuid) ? dbFetchOne(
        "SELECT id, card_no FROM mission_triage_victims WHERE mission_id = ? AND victim_uuid = ?",
        [$missionId, $fromUuid]
    ) : null;
    $into = $cardNo !== null ? dbFetchOne(
        "SELECT id FROM mission_triage_victims WHERE mission_id = ? AND card_no = ?",
        [$missionId, $cardNo]
    ) : null;
    if (!$from || !$into) {
        return ['ok' => false, 'error' => 'triage.err_not_found'];
    }
    if ((int) $from['id'] === (int) $into['id']) {
        return ['ok' => true, 'victim_id' => (int) $into['id']];
    }
    if ($from['card_no'] !== null) {
        return ['ok' => false, 'error' => 'triage.err_merge_has_card'];
    }
    $pdo = db();
    $ownTransaction = !$pdo->inTransaction();
    if ($ownTransaction) {
        $pdo->beginTransaction();
    }
    try {
        dbExecute("UPDATE mission_triage_assessments SET victim_id = ? WHERE victim_id = ?", [(int) $into['id'], (int) $from['id']]);
        dbExecute("UPDATE mission_triage_status_log SET victim_id = ? WHERE victim_id = ?", [(int) $into['id'], (int) $from['id']]);
        dbExecute("DELETE FROM mission_triage_victims WHERE id = ?", [(int) $from['id']]);
        refreshTriageVictimCategory((int) $into['id']);
        if ($ownTransaction) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($ownTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    return ['ok' => true, 'victim_id' => (int) $into['id']];
}

/**
 * "N walking wounded sent to the green area", counted without a card each:
 * START's very first instruction is to send everyone who can walk to one
 * place, and nobody tags thirty people on the way. Idempotent on client_uuid
 * for the offline queue.
 */
function recordTriageBulkGreen(int $missionId, int $userId, string $clientUuid, int $count, ?float $lat, ?float $lng, ?float $accuracy, string $reportedAt): array {
    if (!isValidTriageUuid($clientUuid) || $count < 1 || $count > TRIAGE_BULK_MAX) {
        return ['ok' => false, 'error' => 'triage.err_bulk_count'];
    }
    if (dbFetchValue("SELECT id FROM mission_triage_bulk WHERE mission_id = ? AND client_uuid = ?", [$missionId, $clientUuid])) {
        return ['ok' => true, 'duplicate' => true];
    }
    dbInsert(
        "INSERT INTO mission_triage_bulk (mission_id, client_uuid, walking_count, reported_by, team_id, lat, lng, accuracy_m, reported_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
        [$missionId, $clientUuid, $count, $userId, getUserTeamIdForMission($missionId, $userId), $lat, $lng, $accuracy, $reportedAt]
    );
    return ['ok' => true, 'duplicate' => false];
}

/**
 * Move a casualty on: to the collection point, or away in a vehicle. Every
 * change is logged (mission_triage_status_log) so the report can say when the
 * last red actually left, not just that it did.
 */
function setTriageVictimStatus(int $missionId, int $victimId, string $status, ?string $vehicle, ?string $destination, int $userId): array {
    if (!in_array($status, TRIAGE_STATUSES, true)) {
        return ['ok' => false, 'error' => 'triage.err_invalid'];
    }
    $victim = dbFetchOne("SELECT id FROM mission_triage_victims WHERE id = ? AND mission_id = ?", [$victimId, $missionId]);
    if (!$victim) {
        return ['ok' => false, 'error' => 'triage.err_not_found'];
    }
    $vehicle = $status === 'transported' ? (mb_substr(trim((string) $vehicle), 0, 100) ?: null) : null;
    $destination = $status === 'transported' ? (mb_substr(trim((string) $destination), 0, 255) ?: null) : null;
    dbExecute(
        "UPDATE mission_triage_victims
         SET status = ?, status_at = NOW(), status_by = ?, transport_vehicle = ?, transport_destination = ?
         WHERE id = ?",
        [$status, $userId, $vehicle, $destination, $victimId]
    );
    dbInsert(
        "INSERT INTO mission_triage_status_log (victim_id, mission_id, status, vehicle, destination, user_id)
         VALUES (?, ?, ?, ?, ?, ?)",
        [$victimId, $missionId, $status, $vehicle, $destination, $userId]
    );
    return ['ok' => true];
}

/**
 * Who the casualty is, filled in at the collection point when there is time.
 * Never asked for at triage: a name costs a minute the next casualty does
 * not have.
 */
function setTriageVictimDetails(int $missionId, int $victimId, array $in): array {
    $victim = dbFetchOne("SELECT id FROM mission_triage_victims WHERE id = ? AND mission_id = ?", [$victimId, $missionId]);
    if (!$victim) {
        return ['ok' => false, 'error' => 'triage.err_not_found'];
    }
    $gender = (string) ($in['gender'] ?? '');
    if ($gender !== '' && !in_array($gender, ['male', 'female', 'unknown'], true)) {
        return ['ok' => false, 'error' => 'triage.err_invalid'];
    }
    dbExecute(
        "UPDATE mission_triage_victims
         SET patient_name = ?, estimated_age = ?, gender = ?, phone = ?, notes = ?
         WHERE id = ?",
        [
            mb_substr(trim((string) ($in['patient_name'] ?? '')), 0, 255) ?: null,
            mb_substr(trim((string) ($in['estimated_age'] ?? '')), 0, 50) ?: null,
            $gender ?: null,
            mb_substr(trim((string) ($in['phone'] ?? '')), 0, 30) ?: null,
            mb_substr(trim((string) ($in['notes'] ?? '')), 0, 2000) ?: null,
            $victimId,
        ]
    );
    return ['ok' => true];
}

/**
 * Loud notice to command staff: the first casualty of the incident, and any
 * casualty that is (or has just become) red or black. Yellow and green are
 * deliberately silent — the board updates itself, and thirty sirens for
 * thirty sprained ankles is how a coordinator stops hearing the one that
 * matters. The text carries the running totals and the tag is the same for
 * every one of them, so each notice REPLACES the last on a phone instead of
 * stacking into a wall.
 */
function notifyTriageCommand(int $missionId, string $missionTitle, ?int $responsibleUserId, int $actorId, string $actorName, string $code, string $category, bool $isFirst): void {
    $counts = triageCategoryCounts($missionId);
    $recipientIds = getMissionCommandStaffIds($missionId, $responsibleUserId, $actorId);
    if (!$recipientIds) {
        return;
    }
    $warRoomUrl = rtrim(BASE_URL, '/') . '/war-room.php?id=' . $missionId;
    $langs = getUserLanguages($recipientIds);
    foreach ($recipientIds as $recipientId) {
        $lang = $langs[$recipientId] ?? DEFAULT_LANGUAGE;
        $title = t($isFirst ? 'triage.notify_first_title' : 'triage.notify_title', ['mission' => $missionTitle], $lang);
        $message = t('triage.notify_message', [
            'name' => $actorName,
            'code' => $code,
            'category' => triageCategoryLabel($category, $lang),
            'red' => $counts['red'], 'yellow' => $counts['yellow'],
            'green' => $counts['green'] + $counts['walking'], 'black' => $counts['black'],
        ], $lang);
        // Mandatory (empty code) for the same reason incidents are: a red
        // casualty must never be muted by a notification preference.
        sendNotification($recipientId, $title, $message, 'danger', '', [
            'url' => $warRoomUrl,
            'tag' => 'triage-mission-' . $missionId,
            'bannerMission' => $missionId,
            'vibrate' => [300, 100, 300, 100, 500],
        ]);
    }
}

/**
 * A hazard that was not on the size-up before: tell the people on the mission
 * once, naming it, because somebody may already be on the way in. Not sent for
 * every edit of the form, only for a newly ticked hazard.
 *
 * @param string[] $hazardKeys keys from TRIAGE_SIZEUP_HAZARDS
 */
function notifyMciHazards(int $missionId, string $missionTitle, ?int $responsibleUserId, int $actorId, array $hazardKeys): void {
    if (!$hazardKeys) {
        return;
    }
    $ids = array_values(array_unique(array_merge(
        actionRoomNotifyRecipientIds($missionId, null, $actorId),
        getMissionCommandStaffIds($missionId, $responsibleUserId, $actorId)
    )));
    if (!$ids) {
        return;
    }
    $warRoomUrl = rtrim(BASE_URL, '/') . '/war-room.php?id=' . $missionId;
    $langs = getUserLanguages($ids);
    foreach ($ids as $id) {
        $lang = $langs[$id] ?? DEFAULT_LANGUAGE;
        $names = implode(', ', array_map(fn($k) => t('triage.sizeup.hazard.' . $k, [], $lang), $hazardKeys));
        sendNotification($id, t('triage.hazards_title', ['mission' => $missionTitle], $lang), t('triage.hazards_message', ['hazards' => $names], $lang), 'danger', '', [
            'url' => $warRoomUrl,
            'tag' => 'triage-hazards-' . $missionId,
            'bannerMission' => $missionId,
            'vibrate' => [200, 100, 200],
        ]);
    }
}

/**
 * Tell the field that Μαζικό Συμβάν is on: every Action Room participant and
 * every other coordinator, loud, because this is the moment the triage button
 * appears on their phone and they need to know why.
 */
function notifyMciActivated(int $missionId, string $missionTitle, ?int $responsibleUserId, int $actorId): void {
    $ids = array_values(array_unique(array_merge(
        actionRoomNotifyRecipientIds($missionId, null, $actorId),
        getMissionCommandStaffIds($missionId, $responsibleUserId, $actorId)
    )));
    if (!$ids) {
        return;
    }
    $warRoomUrl = rtrim(BASE_URL, '/') . '/war-room.php?id=' . $missionId;
    $langs = getUserLanguages($ids);
    foreach ($ids as $id) {
        $lang = $langs[$id] ?? DEFAULT_LANGUAGE;
        sendNotification($id, t('triage.mci_on_title', ['mission' => $missionTitle], $lang), t('triage.mci_on_message', [], $lang), 'danger', '', [
            'url' => $warRoomUrl,
            'tag' => 'triage-mci-' . $missionId,
            'bannerMission' => $missionId,
            'vibrate' => [300, 100, 300, 100, 500],
        ]);
    }
}

/** Current totals per category, plus walking wounded counted in bulk. */
function triageCategoryCounts(int $missionId): array {
    $counts = ['red' => 0, 'yellow' => 0, 'green' => 0, 'black' => 0, 'walking' => 0];
    foreach (dbFetchAll("SELECT category, COUNT(*) AS n FROM mission_triage_victims WHERE mission_id = ? GROUP BY category", [$missionId]) as $row) {
        $counts[$row['category']] = (int) $row['n'];
    }
    $counts['walking'] = (int) dbFetchValue("SELECT COALESCE(SUM(walking_count), 0) FROM mission_triage_bulk WHERE mission_id = ?", [$missionId]);
    return $counts;
}

/**
 * Sort key shared by every list of casualties: still on scene before gone,
 * then by transport priority (red, yellow, green, black — the dead go last,
 * nobody drives them to a hospital first), then whoever has waited longest.
 */
function triageVictimSortKey(array $v): array {
    $priority = array_flip(TRIAGE_CATEGORIES);
    return [$v['status'] === 'transported' ? 1 : 0, $priority[$v['category']] ?? 9, $v['first_ts']];
}

/**
 * Everything the Action Room shows about triage, for the page and every poll.
 *
 * $unmasked (command staff) gets a casualty's real name, phone and notes;
 * everyone else gets maskPatientName()/maskPatientPhone() and no notes — the
 * incident log's rule, unchanged. Categories, black included, are the same
 * for everybody (the mission owner's decision).
 *
 * Nothing in here is relative to now(): the poll hashes this payload and a
 * "12 minutes ago" would change it every tick. Ages are computed on the page
 * from the *_ts fields.
 *
 * Returns null when Μαζικό Συμβάν was never switched on and there is nothing
 * to show, so a normal mission's payload carries one null and nothing else.
 */
function loadTriageStateForMission(int $missionId, bool $unmasked, int $viewerId): ?array {
    $mci = loadMissionMci($missionId);
    $victimRows = dbFetchAll(
        "SELECT v.*, u.name AS created_by_name, mt.codename, mt.team_number
         FROM mission_triage_victims v
         LEFT JOIN users u ON u.id = v.created_by
         LEFT JOIN mission_teams mt ON mt.id = v.team_id
         WHERE v.mission_id = ?",
        [$missionId]
    );
    if (!$mci && !$victimRows) {
        return null;
    }

    $historyByVictim = [];
    if ($victimRows) {
        $rows = dbFetchAll(
            "SELECT a.victim_id, a.category, a.reason, a.protocol, a.answers, a.assessed_at, u.name AS by_name
             FROM mission_triage_assessments a
             LEFT JOIN users u ON u.id = a.assessed_by
             WHERE a.mission_id = ?
             ORDER BY a.assessed_at ASC, a.id ASC",
            [$missionId]
        );
        foreach ($rows as $row) {
            // The measurements of a secondary triage (rr, sbp, gcs, rts), for
            // the history line; the yes/no answers of START are not shown.
            $vitals = null;
            if ($row['protocol'] === 'secondary' && $row['answers'] !== null) {
                $decoded = json_decode((string) $row['answers'], true);
                $vitals = is_array($decoded) && $decoded ? $decoded : null;
            }
            $historyByVictim[(int) $row['victim_id']][] = [
                'category' => $row['category'],
                'reason' => triageReasonLabel($row['reason']),
                'protocol' => $row['protocol'],
                'vitals' => $vitals,
                'at' => date('H:i', strtotime($row['assessed_at'])),
                'by' => $row['by_name'],
            ];
        }
    }

    $victims = array_map(function ($v) use ($unmasked, $historyByVictim) {
        $history = $historyByVictim[(int) $v['id']] ?? [];
        $name = $v['patient_name'] !== null ? ($unmasked ? $v['patient_name'] : maskPatientName($v['patient_name'])) : null;
        $phone = $v['phone'] !== null ? ($unmasked ? $v['phone'] : maskPatientPhone($v['phone'])) : null;
        return [
            'id'            => (int) $v['id'],
            'uuid'          => $v['victim_uuid'],
            'code'          => $v['card_no'] ?? $v['fallback_code'],
            'card_no'       => $v['card_no'],
            'fallback_code' => $v['fallback_code'],
            'category'      => $v['category'],
            'reason'        => triageReasonLabel($v['reason']),
            'reason_key'    => $v['reason'],
            'age_group'     => $v['age_group'],
            'status'        => $v['status'],
            'lat'           => $v['lat'] !== null ? (float) $v['lat'] : null,
            'lng'           => $v['lng'] !== null ? (float) $v['lng'] : null,
            'accuracy_m'    => $v['accuracy_m'] !== null ? (int) round((float) $v['accuracy_m']) : null,
            'first_ts'      => strtotime($v['first_assessed_at']),
            'last_ts'       => strtotime($v['last_assessed_at']),
            'first_at'      => date('H:i', strtotime($v['first_assessed_at'])),
            'last_at'       => date('H:i', strtotime($v['last_assessed_at'])),
            'status_at'     => $v['status_at'] ? date('H:i', strtotime($v['status_at'])) : null,
            'vehicle'       => $v['transport_vehicle'],
            'destination'   => $v['transport_destination'],
            'created_by'    => $v['created_by_name'],
            'team_label'    => $v['team_id'] ? teamLabel($v['codename'], $v['team_number']) : null,
            'patient_name'  => $name,
            'estimated_age' => $v['estimated_age'],
            'gender'        => $v['gender'],
            'phone'         => $phone,
            'notes'         => $unmasked ? $v['notes'] : null,
            'history'       => $history,
        ];
    }, $victimRows);
    usort($victims, fn($a, $b) => triageVictimSortKey($a) <=> triageVictimSortKey($b));

    $counts = ['red' => 0, 'yellow' => 0, 'green' => 0, 'black' => 0];
    $waitingRed = 0;
    foreach ($victims as $v) {
        $counts[$v['category']]++;
        if ($v['category'] === 'red' && $v['status'] !== 'transported') {
            $waitingRed++;
        }
    }
    $walking = (int) dbFetchValue("SELECT COALESCE(SUM(walking_count), 0) FROM mission_triage_bulk WHERE mission_id = ?", [$missionId]);

    return [
        'active'        => $mci ? (bool) $mci['is_active'] : false,
        'activated_at'  => $mci && $mci['activated_at'] ? date('H:i', strtotime($mci['activated_at'])) : null,
        'activated_by'  => $mci['activated_by_name'] ?? null,
        // Hazards are for everybody on the mission; the rest of the size-up is
        // command's working note, but there is nothing in it worth hiding.
        'sizeup'        => !empty($mci['sizeup']) ? normalizeTriageSizeup((array) json_decode((string) $mci['sizeup'], true)) : null,
        'sizeup_at'     => !empty($mci['sizeup']) && $mci['sizeup_at'] ? date('H:i', strtotime($mci['sizeup_at'])) : null,
        'sizeup_by'     => !empty($mci['sizeup']) ? ($mci['sizeup_by_name'] ?? null) : null,
        'ccp'           => $mci && $mci['ccp_lat'] !== null ? ['lat' => (float) $mci['ccp_lat'], 'lng' => (float) $mci['ccp_lng']] : null,
        'green'         => $mci && $mci['green_lat'] !== null ? ['lat' => (float) $mci['green_lat'], 'lng' => (float) $mci['green_lng']] : null,
        'counts'        => $counts,
        'walking'       => $walking,
        'waiting_red'   => $waitingRed,
        'victims'       => $victims,
        'my_next_seq'   => nextTriageFallbackSeq($missionId, $viewerId),
    ];
}

/**
 * Every triage event for the two activity timelines (mission-history.php's
 * live «Δραστηριότητα» and loadMissionActivityEventsForReport()'s PDF/Excel
 * feed). One loader for both on purpose: those two were written separately
 * and have drifted before (see the mission_incidents history in both files),
 * and a timeline that shows a casualty in one place and not the other is
 * worse than none. Each caller maps these into its own event shape.
 *
 * Never carries a name or a phone — both consumers reach people the live
 * board's masking does not cover (exports, the PDF).
 *
 * Returns rows of ['kind', 'ts' (unix), 'actor', 'team_id', 'code',
 * 'category', 'previous', 'reason', 'count', 'status', 'vehicle',
 * 'destination', 'lat', 'lng'].
 */
function loadTriageActivityEvents(int $missionId): array {
    $events = [];
    foreach (dbFetchAll(
        "SELECT l.action, l.created_at, l.lat, l.lng, u.name AS actor
         FROM mission_mci_log l LEFT JOIN users u ON u.id = l.user_id
         WHERE l.mission_id = ?",
        [$missionId]
    ) as $row) {
        $events[] = [
            'kind' => 'mci_' . $row['action'], 'ts' => strtotime($row['created_at']), 'actor' => $row['actor'],
            'team_id' => null, 'lat' => $row['lat'] !== null ? (float) $row['lat'] : null, 'lng' => $row['lng'] !== null ? (float) $row['lng'] : null,
        ];
    }
    foreach (dbFetchAll(
        "SELECT a.category, a.previous_category, a.reason, a.assessed_at, a.team_id, a.lat, a.lng,
                COALESCE(v.card_no, v.fallback_code) AS code, u.name AS actor
         FROM mission_triage_assessments a
         JOIN mission_triage_victims v ON v.id = a.victim_id
         LEFT JOIN users u ON u.id = a.assessed_by
         WHERE a.mission_id = ?",
        [$missionId]
    ) as $row) {
        $events[] = [
            'kind' => $row['previous_category'] === null ? 'triaged' : 'retriaged',
            'ts' => strtotime($row['assessed_at']), 'actor' => $row['actor'], 'team_id' => $row['team_id'] !== null ? (int) $row['team_id'] : null,
            'code' => $row['code'], 'category' => $row['category'], 'previous' => $row['previous_category'],
            'reason' => $row['reason'],
            'lat' => $row['lat'] !== null ? (float) $row['lat'] : null, 'lng' => $row['lng'] !== null ? (float) $row['lng'] : null,
        ];
    }
    foreach (dbFetchAll(
        "SELECT b.walking_count, b.reported_at, b.team_id, b.lat, b.lng, u.name AS actor
         FROM mission_triage_bulk b LEFT JOIN users u ON u.id = b.reported_by
         WHERE b.mission_id = ?",
        [$missionId]
    ) as $row) {
        $events[] = [
            'kind' => 'bulk_green', 'ts' => strtotime($row['reported_at']), 'actor' => $row['actor'],
            'team_id' => $row['team_id'] !== null ? (int) $row['team_id'] : null, 'count' => (int) $row['walking_count'],
            'lat' => $row['lat'] !== null ? (float) $row['lat'] : null, 'lng' => $row['lng'] !== null ? (float) $row['lng'] : null,
        ];
    }
    foreach (dbFetchAll(
        "SELECT s.status, s.vehicle, s.destination, s.created_at, v.category,
                COALESCE(v.card_no, v.fallback_code) AS code, u.name AS actor
         FROM mission_triage_status_log s
         JOIN mission_triage_victims v ON v.id = s.victim_id
         LEFT JOIN users u ON u.id = s.user_id
         WHERE s.mission_id = ?",
        [$missionId]
    ) as $row) {
        $events[] = [
            'kind' => 'status', 'ts' => strtotime($row['created_at']), 'actor' => $row['actor'], 'team_id' => null,
            'code' => $row['code'], 'category' => $row['category'], 'status' => $row['status'],
            'vehicle' => $row['vehicle'], 'destination' => $row['destination'], 'lat' => null, 'lng' => null,
        ];
    }
    usort($events, fn($a, $b) => $a['ts'] <=> $b['ts']);
    return $events;
}

/**
 * One activity line, in the viewer's language. Shared by both timelines so
 * the wording cannot drift between the live tab and the PDF either.
 */
function triageActivityText(array $e, ?string $lang = null): string {
    $cat = fn($c) => $c ? triageCategoryLabel($c, $lang) : '';
    switch ($e['kind']) {
        case 'mci_activated':   return t('triage.act_mci_on', ['name' => $e['actor'] ?? '—'], $lang);
        case 'mci_deactivated': return t('triage.act_mci_off', ['name' => $e['actor'] ?? '—'], $lang);
        case 'mci_sizeup':      return t('triage.act_sizeup', ['name' => $e['actor'] ?? '—'], $lang);
        case 'mci_ccp_set':     return t($e['lat'] !== null ? 'triage.act_ccp_set' : 'triage.act_ccp_clear', ['name' => $e['actor'] ?? '—'], $lang);
        case 'mci_green_set':   return t($e['lat'] !== null ? 'triage.act_green_set' : 'triage.act_green_clear', ['name' => $e['actor'] ?? '—'], $lang);
        case 'triaged':
            return t('triage.act_triaged', ['name' => $e['actor'] ?? '—', 'code' => $e['code'], 'category' => $cat($e['category'])], $lang)
                . ($e['reason'] && $e['reason'] !== 'direct' ? ' — ' . triageReasonLabel($e['reason'], $lang) : '');
        case 'retriaged':
            return t('triage.act_retriaged', ['name' => $e['actor'] ?? '—', 'code' => $e['code'], 'from' => $cat($e['previous']), 'to' => $cat($e['category'])], $lang);
        case 'bulk_green':      return t('triage.act_bulk', ['name' => $e['actor'] ?? '—', 'count' => $e['count']], $lang);
        case 'status':
            if ($e['status'] === 'transported') {
                $where = trim(implode(' · ', array_filter([$e['vehicle'], $e['destination']])));
                return t('triage.act_transported', ['name' => $e['actor'] ?? '—', 'code' => $e['code'], 'category' => $cat($e['category'])], $lang)
                    . ($where !== '' ? ' — ' . $where : '');
            }
            return t('triage.act_status', ['name' => $e['actor'] ?? '—', 'code' => $e['code'], 'status' => triageStatusLabel($e['status'], $lang)], $lang);
    }
    return '';
}

/**
 * The post-mission picture for the PDF and the stats page: totals, the
 * timeline that matters (switched on, first casualty, last red away) and
 * one row per casualty — always masked, never notes, whoever prints it.
 * Null when the mission never had a Μαζικό Συμβάν.
 */
/**
 * How the sorting held up, for the mission report and the stats page.
 *
 * There is no ground truth here (we do not learn how anybody did in hospital),
 * so this is NOT an error rate. Each casualty's FIRST assessment is compared
 * with their first RE-assessment, the nearest second look:
 *
 *   under  - the first look put them lower than the second did (green or
 *            yellow, then found more urgent): the primary triage probably
 *            under-called them. under_to_red are the ones then found red.
 *   over   - the first look put them higher than the second (a reduction can
 *            also be a real improvement after treatment).
 *   same   - no change.
 *   black_changed - first or second assessment was black and the other not:
 *            kept apart, a dead casualty is not a point on a scale.
 *
 * Only a casualty who was assessed twice can show up in under/over, so the
 * rates are given against the re-assessed AND against everybody.
 *
 * $assessments: rows ['victim_id', 'category', 'protocol', 'reason', 'ts'
 * (unix, field time)] in any order. $transportedAt: victim_id => unix time
 * the casualty left (only for casualties currently marked transported).
 *
 * Returns the counts plus, per colour (by FINAL category), how many minutes
 * from first assessment to leaving: n, median, max.
 */
function triageQualityStats(array $assessments, array $transportedAt): array {
    $urgency = ['green' => 1, 'yellow' => 2, 'red' => 3];
    $byVictim = [];
    foreach ($assessments as $a) {
        $byVictim[(int) $a['victim_id']][] = $a;
    }
    $out = [
        'victims' => count($byVictim), 'reassessed' => 0,
        'under' => 0, 'under_to_red' => 0, 'over' => 0, 'same' => 0, 'black_changed' => 0,
        'secondary' => ['total' => 0, 'accepted' => 0, 'overridden' => 0, 'manual' => 0],
        'reassess_median_minutes' => null,
        'transport' => ['red' => ['n' => 0, 'median' => null, 'max' => null], 'yellow' => ['n' => 0, 'median' => null, 'max' => null], 'green' => ['n' => 0, 'median' => null, 'max' => null]],
    ];
    $toReassess = [];
    $toLeave = ['red' => [], 'yellow' => [], 'green' => []];
    foreach ($byVictim as $victimId => $list) {
        usort($list, fn($x, $y) => $x['ts'] <=> $y['ts']);
        foreach ($list as $a) {
            if (($a['protocol'] ?? '') === 'secondary') {
                $out['secondary']['total']++;
                $key = $a['reason'] === 'trts' ? 'accepted' : ($a['reason'] === 'secondary_override' ? 'overridden' : 'manual');
                $out['secondary'][$key]++;
            }
        }
        $first = $list[0];
        if (count($list) >= 2) {
            $second = $list[1];
            $out['reassessed']++;
            $toReassess[] = max(0, (int) round(($second['ts'] - $first['ts']) / 60));
            if (isset($urgency[$first['category']], $urgency[$second['category']])) {
                $d = $urgency[$second['category']] <=> $urgency[$first['category']];
                if ($d > 0) {
                    $out['under']++;
                    if ($second['category'] === 'red') {
                        $out['under_to_red']++;
                    }
                } elseif ($d < 0) {
                    $out['over']++;
                } else {
                    $out['same']++;
                }
            } elseif ($first['category'] !== $second['category']) {
                $out['black_changed']++;
            } else {
                $out['same']++;
            }
        }
        $final = end($list)['category'];
        if (isset($transportedAt[$victimId], $toLeave[$final])) {
            $toLeave[$final][] = max(0, (int) round(($transportedAt[$victimId] - $first['ts']) / 60));
        }
    }
    $median = function (array $v): ?int {
        if (!$v) {
            return null;
        }
        sort($v);
        $n = count($v);
        return $n % 2 ? $v[intdiv($n, 2)] : (int) round(($v[$n / 2 - 1] + $v[$n / 2]) / 2);
    };
    $out['reassess_median_minutes'] = $median($toReassess);
    foreach ($toLeave as $cat => $mins) {
        $out['transport'][$cat] = ['n' => count($mins), 'median' => $median($mins), 'max' => $mins ? max($mins) : null];
    }
    return $out;
}

/**
 * The words for triageQualityStats(), shared by the mission report and the
 * stats page (Greek only, like both of them). Plain text, one sentence per
 * line; the caller escapes. An empty array when nobody was assessed.
 */
function triageQualityLines(array $q): array {
    if ((int) $q['victims'] === 0) {
        return [];
    }
    $pct = fn(int $a, int $b): string => $b > 0 ? (string) round($a * 100 / $b) . '%' : '—';
    $victim = fn(int $n): string => $n === 1 ? 'θύμα' : 'θύματα';
    $lines = [];
    $lines[] = sprintf(
        '%s %d από %d %s με κάρτα (%s).',
        $q['reassessed'] === 1 ? 'Επανεκτιμήθηκε' : 'Επανεκτιμήθηκαν', $q['reassessed'], $q['victims'], $victim($q['victims']),
        $pct($q['reassessed'], $q['victims'])
    );
    if ($q['reassessed'] > 0) {
        $toRed = $q['under_to_red'] === 1 ? ', από τα οποία 1 βρέθηκε κόκκινο' : sprintf(', από τα οποία %d βρέθηκαν κόκκινα', $q['under_to_red']);
        $lines[] = sprintf(
            'Η πρώτη εκτίμηση ήταν χαμηλότερη από την πρώτη επανεκτίμηση (πιθανό υπο-triage) σε %d %s (%s των επανεκτιμηθέντων, %s όλων)%s.',
            $q['under'], $victim($q['under']), $pct($q['under'], $q['reassessed']), $pct($q['under'], $q['victims']),
            $q['under'] > 0 ? $toRed : ''
        );
        $lines[] = sprintf(
            'Ήταν υψηλότερη (πιθανό υπερ-triage) σε %d (%s των επανεκτιμηθέντων, %s όλων) και αμετάβλητη σε %d.',
            $q['over'], $pct($q['over'], $q['reassessed']), $pct($q['over'], $q['victims']), $q['same']
        );
        if ($q['black_changed'] > 0) {
            $lines[] = sprintf('Θύματα όπου το μαύρο άλλαξε (ή έγινε μαύρο) στην επανεκτίμηση: %d.', $q['black_changed']);
        }
    }
    $s = $q['secondary'];
    if ($s['total'] > 0) {
        $lines[] = sprintf(
            'Δευτερογενείς εκτιμήσεις (ζωτικά): %d. Ο διασώστης δέχτηκε τη βαθμολογία σε %d, άλλαξε το χρώμα σε %d και διάλεξε χρώμα χωρίς βαθμολογία σε %d.',
            $s['total'], $s['accepted'], $s['overridden'], $s['manual']
        );
    }
    if ($q['reassess_median_minutes'] !== null) {
        $lines[] = sprintf('Διάμεσος χρόνος από την πρώτη εκτίμηση ως την πρώτη επανεκτίμηση: %d′.', $q['reassess_median_minutes']);
    }
    $names = ['red' => 'Κόκκινα', 'yellow' => 'Κίτρινα', 'green' => 'Πράσινα'];
    $parts = [];
    foreach ($names as $cat => $name) {
        $t = $q['transport'][$cat];
        if ($t['n'] > 0) {
            $parts[] = sprintf('%s %d′ / %d′ (%d %s)', $name, $t['median'], $t['max'], $t['n'], $victim($t['n']));
        }
    }
    if ($parts) {
        $lines[] = 'Από την πρώτη εκτίμηση ως τη διακομιδή (διάμεσος / μέγιστος): ' . implode(' · ', $parts) . '.';
    }
    return $lines;
}

/**
 * The size-up in words for the mission report and the stats page (Greek, plain
 * text, the caller escapes). $recorded is how many casualties were actually
 * recorded, set beside the estimate so a debrief sees how far off it was.
 * Empty when no size-up was written.
 */
function triageSizeupLines(?array $s, int $recorded): array {
    if (!$s) {
        return [];
    }
    $names = fn(array $keys, string $group): string => implode(', ', array_map(fn($k) => t('triage.sizeup.' . $group . '.' . $k, [], 'el'), $keys));
    $withNote = fn(string $head, string $note): string => $note !== '' ? $head . ($head !== '' ? ' — ' : '') . $note : $head;
    $lines = [];
    if ($s['hazards'] || $s['hazards_note'] !== '') {
        $lines[] = 'Κίνδυνοι: ' . $withNote($names($s['hazards'], 'hazard'), $s['hazards_note']);
    }
    if ($s['access'] !== null || $s['access_note'] !== '') {
        $lines[] = 'Πρόσβαση οχημάτων: ' . $withNote($s['access'] !== null ? t('triage.sizeup.access.' . $s['access'], [], 'el') : '', $s['access_note']);
    }
    if ($s['casualties_estimate'] !== null) {
        $lines[] = sprintf('Εκτίμηση θυμάτων: %d · Καταγράφηκαν τελικά: %d.', $s['casualties_estimate'], $recorded);
    }
    if ($s['resources'] || $s['resources_note'] !== '') {
        $lines[] = 'Τι ζητήθηκε: ' . $withNote($names($s['resources'], 'resource'), $s['resources_note']);
    }
    if ($s['ekab_notified']) {
        $lines[] = 'Το ΕΚΑΒ ενημερώθηκε.';
    }
    return $lines;
}

/** What the figures from triageQualityStats() do and do not say. */
const TRIAGE_QUALITY_NOTE = 'Η σύγκριση γίνεται με την πρώτη επανεκτίμηση και όχι με την πραγματική έκβαση των θυμάτων, άρα δεν δείχνει λάθος. Μια μείωση της κατηγορίας μπορεί να είναι πραγματική βελτίωση μετά από θεραπεία, και μόνο όσοι επανεκτιμήθηκαν μπορούν να εμφανιστούν.';

function loadTriageReportForMission(int $missionId): ?array {
    $state = loadTriageStateForMission($missionId, false, 0);
    if (!$state || (!$state['victims'] && !$state['walking'] && !$state['activated_at'])) {
        return null;
    }
    $firstActivated = dbFetchValue("SELECT MIN(created_at) FROM mission_mci_log WHERE mission_id = ? AND action = 'activated'", [$missionId]);
    $firstVictim = dbFetchValue("SELECT MIN(first_assessed_at) FROM mission_triage_victims WHERE mission_id = ?", [$missionId]);
    $lastRedOut = dbFetchValue(
        "SELECT MAX(s.created_at) FROM mission_triage_status_log s
         JOIN mission_triage_victims v ON v.id = s.victim_id
         WHERE s.mission_id = ? AND s.status = 'transported' AND v.category = 'red'",
        [$missionId]
    );
    $transported = 0;
    $initialCounts = ['red' => 0, 'yellow' => 0, 'green' => 0, 'black' => 0];
    $firstCategory = [];
    foreach (dbFetchAll(
        "SELECT a.victim_id, a.category FROM mission_triage_assessments a
         WHERE a.mission_id = ? ORDER BY a.assessed_at ASC, a.id ASC",
        [$missionId]
    ) as $row) {
        if (!isset($firstCategory[(int) $row['victim_id']])) {
            $firstCategory[(int) $row['victim_id']] = $row['category'];
            $initialCounts[$row['category']]++;
        }
    }
    foreach ($state['victims'] as &$v) {
        if ($v['status'] === 'transported') {
            $transported++;
        }
        $v['first_category'] = $firstCategory[$v['id']] ?? $v['category'];
        $v['notes'] = null;
    }
    unset($v);

    $qualityRows = [];
    foreach (dbFetchAll(
        "SELECT victim_id, category, protocol, reason, assessed_at FROM mission_triage_assessments
         WHERE mission_id = ? ORDER BY assessed_at ASC, id ASC",
        [$missionId]
    ) as $row) {
        $qualityRows[] = [
            'victim_id' => (int) $row['victim_id'], 'category' => $row['category'], 'protocol' => $row['protocol'],
            'reason' => $row['reason'], 'ts' => strtotime($row['assessed_at']),
        ];
    }
    $leftAt = [];
    foreach (dbFetchAll(
        "SELECT s.victim_id, MAX(s.created_at) AS left_at FROM mission_triage_status_log s
         JOIN mission_triage_victims v ON v.id = s.victim_id AND v.status = 'transported'
         WHERE s.mission_id = ? AND s.status = 'transported' GROUP BY s.victim_id",
        [$missionId]
    ) as $row) {
        $leftAt[(int) $row['victim_id']] = strtotime($row['left_at']);
    }

    return [
        'quality'        => triageQualityStats($qualityRows, $leftAt),
        'sizeup'         => $state['sizeup'],
        'sizeup_at'      => $state['sizeup_at'],
        'sizeup_by'      => $state['sizeup_by'],
        'recorded'       => count($state['victims']) + (int) $state['walking'],
        'counts'         => $state['counts'],
        'initial_counts' => $initialCounts,
        'walking'        => $state['walking'],
        'transported'    => $transported,
        'retriaged'      => (int) dbFetchValue("SELECT COUNT(*) FROM mission_triage_assessments WHERE mission_id = ? AND previous_category IS NOT NULL", [$missionId]),
        // Re-triages that found somebody WORSE than first thought — the
        // number that says whether going back was worth it. Ranked by
        // severity (green < yellow < red < black), not by transport priority.
        'deteriorated'   => (int) dbFetchValue(
            "SELECT COUNT(*) FROM mission_triage_assessments
             WHERE mission_id = ? AND previous_category IS NOT NULL
               AND FIELD(category, 'green', 'yellow', 'red', 'black') > FIELD(previous_category, 'green', 'yellow', 'red', 'black')",
            [$missionId]
        ),
        'activated_at'   => $firstActivated,
        'first_victim_at'=> $firstVictim,
        'last_red_out_at'=> $lastRedOut,
        'victims'        => $state['victims'],
    ];
}
