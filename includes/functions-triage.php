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
                'walk'       => ['yes' => ['green', 'walks'], 'no' => 'breathing'],
                'breathing'  => ['yes' => 'rr_over_30', 'no' => 'airway'],
                'airway'     => ['yes' => ['red', 'breathes_after_airway'], 'no' => ['black', 'apneic']],
                'rr_over_30' => ['yes' => ['red', 'rr_over_30'], 'no' => 'perfusion'],
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
                'walk'           => ['yes' => ['green', 'walks'], 'no' => 'breathing'],
                'breathing'      => ['yes' => 'rr_child', 'no' => 'airway'],
                'airway'         => ['yes' => ['red', 'breathes_after_airway'], 'no' => 'pulse_apneic'],
                'pulse_apneic'   => ['yes' => 'rescue_breaths', 'no' => ['black', 'apneic_no_pulse']],
                'rescue_breaths' => ['yes' => ['red', 'breathes_after_rescue'], 'no' => ['black', 'apneic']],
                // Breathing under 15 or over 45 a minute.
                'rr_child'       => ['yes' => ['red', 'rr_child'], 'no' => 'pulse'],
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
 */
function triageEvaluate(string $protocol, array $answers): ?array {
    $tree = triageProtocols()[$protocol] ?? null;
    if (!$tree) {
        return null;
    }
    $node = $tree['root'];
    $path = [];
    // Bounded by the deepest tree, so a malformed tree can never loop.
    for ($step = 0; $step < 12; $step++) {
        if (!array_key_exists($node, $answers)) {
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
function loadMissionMci(int $missionId): ?array {
    $row = dbFetchOne(
        "SELECT mc.*, u.name AS activated_by_name
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
 * Records one assessment — a first triage or a re-triage — and returns what
 * happened. The single write path for both, so the phone's online call and
 * its offline-queue replay are the same request.
 *
 * $in keys: victim_uuid, assessment_uuid, card_no, fallback_code, protocol
 * ('start'|'jumpstart'|'direct'), answers (array), category (direct only),
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
    } else {
        $result = triageEvaluate($protocol, is_array($in['answers'] ?? null) ? $in['answers'] : []);
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
        "SELECT id, category, card_no FROM mission_triage_victims WHERE mission_id = ? AND victim_uuid = ?",
        [$missionId, $victimUuid]
    );
    if (!$victim && $cardNo !== null) {
        $victim = dbFetchOne(
            "SELECT id, category, card_no FROM mission_triage_victims WHERE mission_id = ? AND card_no = ?",
            [$missionId, $cardNo]
        );
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
            "SELECT a.victim_id, a.category, a.reason, a.protocol, a.assessed_at, u.name AS by_name
             FROM mission_triage_assessments a
             LEFT JOIN users u ON u.id = a.assessed_by
             WHERE a.mission_id = ?
             ORDER BY a.assessed_at ASC, a.id ASC",
            [$missionId]
        );
        foreach ($rows as $row) {
            $historyByVictim[(int) $row['victim_id']][] = [
                'category' => $row['category'],
                'reason' => triageReasonLabel($row['reason']),
                'protocol' => $row['protocol'],
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
    return [
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
