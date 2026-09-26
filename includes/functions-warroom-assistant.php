<?php
/**
 * VolunteerOps — Action Room assistant: «Τι μου ξέφυγε»
 *
 * The deterministic half of the Action Room assistant. There is NO AI in this
 * file and there deliberately never will be: everything a coordinator "missed"
 * is already a row in this database with a NULL in the right column, and a
 * question that a plain SELECT answers exactly should not be answered by a
 * language model that costs ten seconds and can be wrong. The AI half (free
 * questions, shift handover) sits in the same popup and narrates on top of
 * this — it never replaces it, because a provider outage must degrade the
 * assistant, not blind the command post.
 *
 * Two sections, and the split is the whole design:
 *
 *   ΕΚΚΡΕΜΟΥΝ — open state. Things still unresolved right now, regardless of
 *   whether anyone has looked at them. These do NOT go away by being read;
 *   they go away when they are actually handled. That is why merely opening
 *   the panel must never clear them.
 *
 *   ΝΕΑ ΑΠΟ … — activity since this coordinator's own checkpoint: chat,
 *   media, field notes. These are read-once by nature, and the checkpoint only
 *   advances when the coordinator explicitly presses «Το είδα» — not when the
 *   panel opens. You open it, you get called away, and it would be gone.
 *
 * An item never appears in both. Something created after the checkpoint that
 * is also still open is a PENDING item carrying an is_new flag, because its
 * open-ness is the more important fact about it.
 *
 * COST: collectMissionAssistantRaw() runs ~9 thin indexed queries and is
 * called from the 5s Action Room poll, but ONLY for command staff — a
 * volunteer's tab pays nothing. That is a deliberate trade against a second
 * endpoint: with the whole panel already in the poll payload it opens with no
 * network round trip at all, and the badge count and the panel contents cannot
 * disagree, because they are the same object. See the note on timestamps in
 * assembleMissionAssistantItems() for the other half of that bargain.
 */

if (!defined('VOLUNTEEROPS')) {
    die('Direct access not permitted');
}

/**
 * How long an order is allowed to sit unacknowledged before it is worth
 * raising. Anything shorter and the panel screams about an order sent twenty
 * seconds ago that the volunteer is in the middle of reading.
 */
const ASSISTANT_ORDER_GRACE_MINUTES = 10;
const ASSISTANT_ORDER_LATE_MINUTES  = 30;

/** An acknowledged-but-still-open shortage/incident is worth a nudge after this. */
const ASSISTANT_OPEN_NUDGE_MINUTES = 30;

/**
 * Acknowledgement lowers urgency by ONE step; it does not flatten it.
 *
 * The first cut sent every acknowledged-but-open record to 'warn', which put a
 * critical trauma casualty still open after two hours in the same colour as a
 * low-priority kit shortage somebody had glanced at. Somebody being on it is a
 * real fact and deserves a step down — but the thing itself is still critical,
 * and the panel's whole value is that its shape can be read before its words.
 */
function assistantAcknowledgedSeverity(string $recordSeverity): string {
    return $recordSeverity === 'critical' ? 'high' : 'warn';
}

/** A volunteer on duty who has never sent a single position, after this long. */
const ASSISTANT_NEVER_PINGED_MINUTES = 15;

/** First-ever open of the panel looks back this far rather than at the whole mission. */
const ASSISTANT_DEFAULT_LOOKBACK_MINUTES = 30;

/**
 * The default window is snapped to a multiple of this. Not cosmetic — see
 * assistantWindowStart().
 */
const ASSISTANT_WINDOW_QUANTUM_SECONDS = 300;

/** Per-section display cap; the counts stay truthful and the rest is "+N ακόμη". */
const ASSISTANT_SECTION_CAP = 12;

/**
 * Where the panel's rows point. Values are data-card-id attributes in
 * war-room.php; 'reportModal' is the one special case (a modal, not a card).
 */
const ASSISTANT_TARGETS = [
    'sos'       => 'sosAlertsCard',
    'shortage'  => 'shortageListCard',
    'incident'  => 'incidentsListCard',
    'triage'    => 'triageCard',
    'order'     => 'reportModal',
    'dispatch'  => 'dispatchCard',
    'sector'    => 'sectorsListCard',
    'route'     => 'teamRoutesAdminCard',
    'voice'     => 'voiceMessagesCard',
    'breach'    => 'restrictedAreasCard',
    'silent'    => 'participantsCard',
    'stalled'   => 'teamsCard',
    'poi'       => 'poiListCard',
    'chat'      => 'chatCard',
    'media'     => 'mediaCard',
];

/**
 * Start of the "new since" window: the coordinator's own checkpoint, or a
 * default look-back for someone who has never pressed «Το είδα».
 *
 * THE QUANTISATION IS LOAD-BEARING, not tidiness. The naive default —
 * now minus thirty minutes — moves every single second, and this whole panel
 * rides inside the poll payload whose md5 is the only thing stopping 51KB
 * being re-sent to every open tab every 5 seconds. A coordinator who had
 * simply never pressed the button would have silently switched that
 * optimisation off for their own tab, for the entire operation, and nothing
 * would have looked wrong. Snapped to five minutes, the payload changes at
 * most once per five minutes instead of 720 times, and the heading reads
 * "από τις 12:05" rather than "από τις 12:07".
 *
 * Both the queries and the rendered heading must derive the window from HERE,
 * or the panel would name a time it did not actually search from.
 */
function assistantWindowStart(?int $checkpointTs, int $nowTs): int {
    if ($checkpointTs !== null) {
        return $checkpointTs;
    }
    $raw = $nowTs - ASSISTANT_DEFAULT_LOOKBACK_MINUTES * 60;
    return (int) (floor($raw / ASSISTANT_WINDOW_QUANTUM_SECONDS) * ASSISTANT_WINDOW_QUANTUM_SECONDS);
}

/**
 * How long a team may stay in one place before the stillness, rather than the
 * work, is the thing to look at.
 *
 * Longer than the AI's own movement window on purpose. Thirty minutes of not
 * moving is a rest, a briefing, or a slope being searched properly; three
 * quarters of an hour is a question. The panel raises questions, so it uses
 * the longer figure.
 */
const ASSISTANT_STALLED_MINUTES = 45;

/**
 * Below this much ground covered in the whole window, a team has not moved.
 * The same figure the assistant's own wording uses, kept as one number so the
 * panel and the answer cannot disagree about what "stationary" means.
 */
const ASSISTANT_STALLED_METRES = 120;

/**
 * The start of the stalled-team window, snapped to the same five-minute grid
 * as everything else on this panel.
 *
 * THIS SNAP IS WHY THE FEATURE IS AFFORDABLE. The panel rides the Action
 * Room's 5-second poll, whose payload is md5-hashed so that an unchanged
 * operation costs nothing to re-poll. A window that slid with the clock would
 * change the movement figures on every single tick, the hash would never
 * match, and 51KB would go out to every open tab twelve times a minute — the
 * exact failure this app has already been taken down by once.
 */
function assistantStalledWindowStart(int $nowTs): int {
    $raw = $nowTs - ASSISTANT_STALLED_MINUTES * 60;
    return (int) (floor($raw / ASSISTANT_WINDOW_QUANTUM_SECONDS) * ASSISTANT_WINDOW_QUANTUM_SECONDS);
}

/**
 * Teams that have not moved for three quarters of an hour, one row each.
 *
 * A team, not a person: one volunteer standing still is a volunteer standing
 * still, while a whole team that has not moved is either searching one spot
 * very thoroughly or waiting for something nobody told the command post
 * about. So the reading is that of the team's MOST-moving member — the
 * question behind "are they stuck" is whether ANYONE is moving.
 *
 * Only teams whose members are actually reporting: a team with no fixes in
 * the window is silent, which the panel already says, and calling them
 * stationary as well would be two findings about one absence of data.
 */
function assistantStalledTeams(int $missionId, array $shiftBinds, int $nowTs): array {
    $since = assistantStalledWindowStart($nowTs);
    $movement = volunteerMovementByUser($shiftBinds, $since);
    if (!$movement) {
        return [];
    }

    $members = dbFetchAll(
        "SELECT m.team_id, m.user_id, t.codename, t.team_number
           FROM mission_team_members m
           JOIN mission_teams t ON t.id = m.team_id
          WHERE m.mission_id = ?",
        [$missionId]
    );

    $byTeam = [];
    foreach ($members as $row) {
        $move = $movement[(int) $row['user_id']] ?? null;
        if ($move === null) {
            continue;
        }
        $teamId = (int) $row['team_id'];
        if (!isset($byTeam[$teamId])) {
            $byTeam[$teamId] = [
                'label'   => teamLabel($row['codename'], $row['team_number']),
                'best'    => 0,
                'members' => 0,
            ];
        }
        $byTeam[$teamId]['members']++;
        $byTeam[$teamId]['best'] = max($byTeam[$teamId]['best'], (int) $move['path']);
    }

    $out = [];
    foreach ($byTeam as $teamId => $team) {
        if ($team['best'] >= ASSISTANT_STALLED_METRES) {
            continue;
        }
        $out[] = [
            'team_id' => $teamId,
            'label'   => $team['label'] !== '' ? $team['label'] : ('#' . $teamId),
            'metres'  => $team['best'],
            'members' => $team['members'],
            // The quantised window start, not "now": this is the item's
            // timestamp, and a sliding one would make it new on every tick.
            'ts'      => $since,
        ];
    }
    return $out;
}

/**
 * The citable id for one record, e.g. INC-17.
 *
 * SHARED ON PURPOSE with includes/ai-live.php, which builds the same ids when
 * it assembles the AI digest. The «Εξήγησέ μου» button takes the ref off a row
 * of the deterministic panel and hands it to the model as part of a question,
 * so the two vocabularies have to be the same string — and two files each
 * concatenating 'INC-' . $id would agree right up until one of them changed,
 * silently, with the only symptom being an assistant that says it cannot find
 * a record the coordinator is looking straight at.
 *
 * Lives here rather than in ai-live.php because this file is loaded by
 * bootstrap.php on every request while that one is pulled in on demand.
 */
function assistantRecordRef(string $kind, int $id): string {
    $prefixes = [
        'sos'      => 'SOS',
        'shortage' => 'SHORT',
        'incident' => 'INC',
        'order'    => 'ORD',
        'poi'      => 'POI',
        'team'     => 'TEAM',
        'sector'   => 'SECT',
        'person'   => 'CREW',
        'zone'     => 'ZONE',
    ];
    return ($prefixes[$kind] ?? strtoupper($kind)) . '-' . $id;
}

/**
 * Severity ladder. Kept as a function rather than a constant lookup so an
 * unknown value sorts last instead of throwing — this drives a sort, and a
 * sort that fatals takes the whole poll down with it.
 */
function assistantSeverityRank(string $severity): int {
    switch ($severity) {
        case 'critical': return 4;
        case 'high':     return 3;
        case 'warn':     return 2;
        case 'info':     return 1;
        default:         return 0;
    }
}

/**
 * This coordinator's «Το είδα» checkpoint for this mission, or null if they
 * have never pressed it. Per (mission, user): two coordinators on the same
 * mission have genuinely different answers to "what did I miss", and the same
 * coordinator moving from laptop to phone has the same one.
 */
function missionAssistantCheckpointTs(int $missionId, int $userId): ?int {
    $ts = dbFetchValue(
        "SELECT UNIX_TIMESTAMP(seen_at) FROM mission_assistant_checkpoints WHERE mission_id = ? AND user_id = ?",
        [$missionId, $userId]
    );
    return $ts === null || $ts === false ? null : (int) $ts;
}

/**
 * Advances the checkpoint to now. Called only from mission-assistant.php, i.e.
 * only by an explicit press of «Το είδα».
 */
function setMissionAssistantCheckpoint(int $missionId, int $userId): void {
    dbExecute(
        "INSERT INTO mission_assistant_checkpoints (mission_id, user_id, seen_at)
         VALUES (?, ?, NOW())
         ON DUPLICATE KEY UPDATE seen_at = NOW()",
        [$missionId, $userId]
    );
}

/**
 * Every query the panel needs, in raw form: epochs, ids and names, no labels
 * and no formatting. Split from the assembler on purpose — the assembler holds
 * every judgement this feature makes (what is urgent, what counts as silence,
 * what is merely new) and is a pure function of these arrays, so it can be
 * tested against hand-written fixtures with no database at all.
 *
 * $missionShiftIds is passed in rather than looked up: war-room.php has
 * already resolved it, and every ping query on that page scopes by an explicit
 * IN list of constants because MySQL only answers "latest ping per volunteer"
 * from an index that way. Reaching the shifts through a join here instead
 * would put a full scan of the mission's entire ping history back into the 5s
 * poll — the exact regression that page was once crashing on.
 */
function collectMissionAssistantRaw(int $missionId, int $userId, array $missionShiftIds, ?int $checkpointTs, int $nowTs): array {
    // An empty IN () is a SQL syntax error; the impossible id 0 matches
    // nothing, which is the honest answer for a mission with no shifts.
    $shiftBinds = $missionShiftIds ?: [0];
    $shiftPlaceholders = implode(',', array_fill(0, count($shiftBinds), '?'));

    $sinceSql = date('Y-m-d H:i:s', assistantWindowStart($checkpointTs, $nowTs));

    // Bound in PHP, not as INTERVAL ? MINUTE: the placeholder form is not
    // portable across the MySQL/MariaDB pair this app runs on.
    $orderCutoffSql = date('Y-m-d H:i:s', $nowTs - ASSISTANT_ORDER_GRACE_MINUTES * 60);

    $teamLabelExpr = "mt.codename, mt.team_number";

    $raw = [];

    // ── Open SOS ────────────────────────────────────────────────────────────
    $raw['sos'] = dbFetchAll(
        "SELECT s.id, UNIX_TIMESTAMP(s.created_at) AS ts, UNIX_TIMESTAMP(s.acknowledged_at) AS ack_ts,
                u.name AS who, {$teamLabelExpr}
         FROM mission_sos_alerts s
         JOIN users u ON u.id = s.user_id
         LEFT JOIN mission_teams mt ON mt.id = s.team_id
         WHERE s.mission_id = ? AND s.resolved_at IS NULL
         ORDER BY s.created_at ASC",
        [$missionId]
    );

    // ── Shortage reports still open ─────────────────────────────────────────
    $raw['shortages'] = dbFetchAll(
        "SELECT r.id, r.severity, r.title, UNIX_TIMESTAMP(r.created_at) AS ts,
                UNIX_TIMESTAMP(r.acknowledged_at) AS ack_ts, u.name AS who, {$teamLabelExpr}
         FROM mission_shortage_reports r
         JOIN users u ON u.id = r.reporter_id
         LEFT JOIN mission_teams mt ON mt.id = r.team_id
         WHERE r.mission_id = ? AND r.resolved_at IS NULL AND r.not_resolved_at IS NULL
         ORDER BY r.created_at ASC",
        [$missionId]
    );

    // ── Incidents still open ────────────────────────────────────────────────
    // Patient identity is deliberately NOT selected. The panel says an
    // incident is open and who reported it; who the patient is belongs on the
    // incident card behind its own gate, not in a summary strip.
    $raw['incidents'] = dbFetchAll(
        "SELECT i.id, i.severity, i.incident_type, UNIX_TIMESTAMP(i.created_at) AS ts,
                UNIX_TIMESTAMP(i.acknowledged_at) AS ack_ts, u.name AS who, {$teamLabelExpr}
         FROM mission_incidents i
         JOIN users u ON u.id = i.reporter_id
         LEFT JOIN mission_teams mt ON mt.id = i.team_id
         WHERE i.mission_id = ? AND i.resolved_at IS NULL
         ORDER BY i.created_at ASC",
        [$missionId]
    );

    // ── Mass-casualty triage: casualties not yet gone ───────────────────────
    // Category and times only — the same "no casualty identity in a summary
    // strip" rule as incidents above.
    $raw['triage'] = dbFetchAll(
        "SELECT id, category, UNIX_TIMESTAMP(first_assessed_at) AS first_ts, UNIX_TIMESTAMP(last_assessed_at) AS last_ts
         FROM mission_triage_victims
         WHERE mission_id = ? AND status <> 'transported'",
        [$missionId]
    );

    // ── Orders nobody has confirmed ─────────────────────────────────────────
    // Grouped to one row per order: a task sent to a six-person team is one
    // thing the coordinator is waiting on, not six.
    //
    // «Δεν μπορώ» is an answer (v3.334.0): a recipient who handed the order
    // back is not somebody the coordinator is still waiting on, and counting
    // them here would ring the overdue alarm about an order the field already
    // replied to. The decline has a row of its own below. A route is declined
    // by its whole group, so every recipient of its order counts as answered.
    $raw['orders'] = dbFetchAll(
        "SELECT o.id, o.order_type, o.task_text, UNIX_TIMESTAMP(o.created_at) AS ts,
                COUNT(*) AS total,
                SUM(r.acknowledged_at IS NOT NULL OR EXISTS (
                    SELECT 1 FROM mission_order_declines od
                    WHERE od.active = 1
                      AND ((od.target_kind = 'order' AND od.target_id = o.id AND od.scope_key = CONCAT('u', r.user_id))
                        OR (od.target_kind = 'route' AND od.target_id = rt.id))
                )) AS acked
         FROM mission_orders o
         JOIN mission_order_recipients r ON r.order_id = o.id
         LEFT JOIN mission_routes rt ON rt.order_id = o.id
         WHERE o.mission_id = ? AND o.created_at <= ?
         GROUP BY o.id, o.order_type, o.task_text, o.created_at
         HAVING acked < total
         ORDER BY o.created_at ASC",
        [$missionId, $orderCutoffSql]
    );

    // ── Dispatch points nobody has confirmed ────────────────────────────────
    //
    // A dispatch is an order to go somewhere, but it is NOT a mission_orders
    // row — it has its own receipt/arrival tables — so the orders query above
    // could never see it. That made the single most common "go there" order in
    // the Action Room the one the panel was blind to.
    //
    // ZERO receipts, not "fewer receipts than team members". The honest
    // denominator would be the team's approved members who are on shift right
    // now, which is a three-way join per dispatch inside a query that runs on
    // the 5s poll — and the actionable fact is anyway "nobody on that team has
    // confirmed this", not "four of six". See this file's COST note.
    //
    // A team's «Δεν μπορώ» is an answer too, same as for orders above.
    $raw['dispatch'] = dbFetchAll(
        "SELECT d.id, d.label, d.type, UNIX_TIMESTAMP(d.created_at) AS ts, {$teamLabelExpr}
         FROM mission_dispatch_points d
         LEFT JOIN mission_teams mt ON mt.id = d.team_id
         LEFT JOIN mission_dispatch_receipts r ON r.dispatch_id = d.id
         WHERE d.mission_id = ? AND d.created_at <= ? AND r.id IS NULL
           AND NOT EXISTS (SELECT 1 FROM mission_order_declines od
                            WHERE od.target_kind = 'dispatch' AND od.target_id = d.id AND od.active = 1)
         ORDER BY d.created_at ASC",
        [$missionId, $orderCutoffSql]
    );

    // ── Sectors assigned to a team that never acknowledged them ─────────────
    //
    // Same blind spot, same reason: a sector assignment lives in
    // mission_search_sectors with its own acknowledged_at, so it was invisible
    // to a query that only reads mission_order_recipients. A sector sitting in
    // 'assigned' is a team that has not said it is going.
    //
    // The clock is status_updated_at, not created_at: sectors are usually
    // drawn (or generated as a grid) long before anyone is assigned to them,
    // and dating the wait from creation would report a brand-new assignment as
    // hours overdue.
    $raw['sector'] = dbFetchAll(
        "SELECT s.id, s.label, UNIX_TIMESTAMP(COALESCE(s.status_updated_at, s.created_at)) AS ts, {$teamLabelExpr}
         FROM mission_search_sectors s
         LEFT JOIN mission_teams mt ON mt.id = s.team_id
         WHERE s.mission_id = ? AND s.status = 'assigned' AND s.acknowledged_at IS NULL
           AND s.team_id IS NOT NULL
           AND COALESCE(s.status_updated_at, s.created_at) <= ?
           AND NOT EXISTS (SELECT 1 FROM mission_order_declines od
                            WHERE od.target_kind = 'sector' AND od.target_id = s.id
                              AND od.scope_key = CONCAT('t', s.team_id) AND od.active = 1)
         ORDER BY COALESCE(s.status_updated_at, s.created_at) ASC",
        [$missionId, $orderCutoffSql]
    );

    // ── «Δεν μπορώ» — orders handed back to command ────────────────────────
    //
    // Only those whose order is still there to act on: a dispatch deleted, a
    // route cancelled or finished, a sector completed has been answered one
    // way or another, and a line about it would be about nothing.
    $raw['declines'] = dbFetchAll(
        "SELECT d.target_kind, d.target_id, d.reason, d.note, UNIX_TIMESTAMP(d.declined_at) AS ts,
                u.name AS who, {$teamLabelExpr},
                o.order_type, o.task_text, dp.type AS dispatch_type, dp.label AS dispatch_label,
                rt.title AS route_title, ss.label AS sector_label
         FROM mission_order_declines d
         LEFT JOIN users u ON u.id = d.declined_by
         LEFT JOIN mission_teams mt ON mt.id = d.team_id
         LEFT JOIN mission_orders o ON d.target_kind = 'order' AND o.id = d.target_id
         LEFT JOIN mission_dispatch_points dp ON d.target_kind = 'dispatch' AND dp.id = d.target_id
         LEFT JOIN mission_routes rt ON d.target_kind = 'route' AND rt.id = d.target_id
              AND rt.completed_at IS NULL AND rt.cancelled_at IS NULL
         LEFT JOIN mission_search_sectors ss ON d.target_kind = 'sector' AND ss.id = d.target_id
              AND ss.status <> 'completed'
         WHERE d.mission_id = ? AND d.active = 1
           AND (o.id IS NOT NULL OR dp.id IS NOT NULL OR rt.id IS NOT NULL OR ss.id IS NOT NULL)
         ORDER BY d.declined_at ASC",
        [$missionId]
    );

    // ── Voice messages from the field nobody has confirmed hearing ──────────
    //
    // No grace period, unlike orders and dispatches above: those are waiting on
    // somebody ELSE to answer, and ten minutes of silence is normal. This is a
    // rescuer's own voice already sitting on the coordinator's screen, and the
    // only thing between it and being heard is pressing play.
    $raw['voice'] = dbFetchAll(
        "SELECT v.id, UNIX_TIMESTAMP(v.created_at) AS ts, v.duration_ms, u.name AS who, {$teamLabelExpr}
         FROM mission_voice_messages v
         JOIN users u ON u.id = v.user_id
         LEFT JOIN mission_teams mt ON mt.id = v.team_id
         WHERE v.mission_id = ? AND v.acknowledged_at IS NULL
         ORDER BY v.created_at ASC",
        [$missionId]
    );

    // ── Hazard-zone breaches still open ─────────────────────────────────────
    $raw['breaches'] = dbFetchAll(
        "SELECT b.id, b.area_label, UNIX_TIMESTAMP(b.created_at) AS ts,
                UNIX_TIMESTAMP(b.exited_at) AS exited_ts, UNIX_TIMESTAMP(b.acknowledged_at) AS ack_ts,
                u.name AS who, {$teamLabelExpr}
         FROM mission_restricted_area_breaches b
         JOIN users u ON u.id = b.user_id
         LEFT JOIN mission_teams mt ON mt.id = b.team_id
         WHERE b.mission_id = ? AND b.resolved_at IS NULL
         ORDER BY b.created_at ASC",
        [$missionId]
    );

    // ── People on duty who have gone quiet ──────────────────────────────────
    //
    // mtm.mission_id IS LOAD-BEARING. mission_team_members is UNIQUE on
    // (mission_id, user_id), so a volunteer who has been on teams in eight
    // previous missions has eight rows in that table — and joining on user_id
    // alone multiplied this whole result by that count. The panel showed the
    // same person as eight identical "gone quiet" lines, every one of them
    // labelled "Χωρίς ομάδα" because mission_teams was then filtered back to
    // this mission and matched nothing. Two bugs from one missing condition.
    //
    // The viewer is excluded: a coordinator sitting at the command post will
    // never send a GPS fix, and "you have gone quiet" is not information they
    // can act on. It was the single loudest row on a real screen.
    //
    // So is everyone without the Action Room GPS tick, for exactly the same
    // reason: their phone was never asked for a position, so reporting their
    // silence is reporting a decision the coordinator made on purpose. On a
    // ten-person operation run by two phones that was eight false alarms, and
    // the two real ones would have been lost among them.
    $raw['silent'] = dbFetchAll(
        "SELECT pr.volunteer_id AS id, u.name AS who,
                UNIX_TIMESTAMP(lp.created_at) AS last_ping_ts,
                UNIX_TIMESTAMP(s.start_time) AS on_duty_since,
                {$teamLabelExpr}
         FROM participation_requests pr
         JOIN shifts s ON s.id = pr.shift_id
         JOIN users u ON u.id = pr.volunteer_id
         LEFT JOIN (SELECT user_id, shift_id, MAX(id) AS max_id
                      FROM volunteer_pings
                     WHERE shift_id IN ({$shiftPlaceholders})
                     GROUP BY user_id, shift_id) l
                ON l.user_id = pr.volunteer_id AND l.shift_id = pr.shift_id
         LEFT JOIN volunteer_pings lp ON lp.id = l.max_id
         LEFT JOIN mission_team_members mtm
                ON mtm.user_id = pr.volunteer_id AND mtm.mission_id = ?
         LEFT JOIN mission_teams mt ON mt.id = mtm.team_id AND mt.mission_id = ?
         JOIN mission_action_room_participants arp
                ON arp.mission_id = s.mission_id AND arp.user_id = pr.volunteer_id
         WHERE s.mission_id = ? AND pr.status = ? AND pr.volunteer_id <> ?
           AND s.start_time <= NOW() AND s.end_time > NOW()",
        array_merge($shiftBinds, [$missionId, $missionId, $missionId, PARTICIPATION_APPROVED, $userId])
    );

    // ── Teams that have stopped moving ──────────────────────────────────────
    // Its own window, longer than the panel's and snapped to the same grid —
    // see assistantStalledWindowStart() for why the snap is load-bearing.
    $raw['stalled'] = assistantStalledTeams($missionId, $shiftBinds, $nowTs);

    // ── Clues nobody has ruled in or out ────────────────────────────────────
    $raw['poi'] = dbFetchAll(
        "SELECT p.id, UNIX_TIMESTAMP(p.created_at) AS ts, COUNT(ph.id) AS photos
         FROM mission_points_of_interest p
         LEFT JOIN mission_photos ph ON ph.poi_id = p.id
         WHERE p.mission_id = ? AND p.checked_at IS NULL
         GROUP BY p.id, p.created_at
         ORDER BY p.created_at ASC",
        [$missionId]
    );

    // ── Chat the coordinator has not caught up on ───────────────────────────
    // Rows, not a COUNT: the last line of a room is worth ten times a number,
    // and the question heuristic below needs the text. Own messages excluded —
    // you did not miss what you typed. Capped because a busy room over a long
    // absence is unbounded and the panel shows a summary either way.
    $raw['chat'] = dbFetchAll(
        "SELECT c.team_id, u.name AS who, c.message, UNIX_TIMESTAMP(c.created_at) AS ts, {$teamLabelExpr}
         FROM mission_chat_messages c
         JOIN users u ON u.id = c.user_id
         LEFT JOIN mission_teams mt ON mt.id = c.team_id
         WHERE c.mission_id = ? AND c.created_at > ? AND c.user_id <> ?
         ORDER BY c.created_at ASC
         LIMIT 300",
        [$missionId, $sinceSql, $userId]
    );

    // ── Media that arrived from the field ───────────────────────────────────
    $raw['media'] = dbFetchAll(
        "SELECT p.id, p.media_type, u.name AS who, UNIX_TIMESTAMP(p.created_at) AS ts
         FROM mission_photos p
         JOIN users u ON u.id = p.user_id
         WHERE p.mission_id = ? AND p.created_at > ? AND p.user_id <> ?
         ORDER BY p.created_at ASC
         LIMIT 100",
        [$missionId, $sinceSql, $userId]
    );

    return $raw;
}

/**
 * Collapses rows that say exactly the same thing into one, carrying a count.
 *
 * THE PANEL IS A BRIEFING, NOT A LOG. A real mission had ten separate orders
 * with identical text awaiting acknowledgement — ten genuine obligations, and
 * ten identical lines that a coordinator has to read past to reach anything
 * else. One line saying "×10" carries the same information in a tenth of the
 * space, and nothing is hidden: the badge still counts all ten, because it is
 * computed before this runs.
 *
 * Only exactly-equal rows merge (same kind, title AND detail), so two orders
 * that differ in how many people have answered stay apart — the difference
 * between them is the thing worth seeing.
 *
 * The survivor keeps the OLDEST timestamp and the HIGHEST severity of the
 * group: the group is as urgent as its worst member and as neglected as its
 * oldest, and rounding either the other way would understate it.
 */
function assistantCollapseIdentical(array $items): array {
    $out = [];
    foreach ($items as $item) {
        $key = $item['kind'] . "\0" . $item['title'] . "\0" . ($item['detail'] ?? '');
        if (!isset($out[$key])) {
            $item['count'] = 1;
            $out[$key] = $item;
            continue;
        }
        $out[$key]['count']++;
        $out[$key]['ts'] = min($out[$key]['ts'], $item['ts']);
        if (assistantSeverityRank($item['sev']) > assistantSeverityRank($out[$key]['sev'])) {
            $out[$key]['sev'] = $item['sev'];
        }
        $out[$key]['is_new'] = $out[$key]['is_new'] || $item['is_new'];
    }
    return array_values($out);
}

/**
 * A readable name for an order's type, never a raw translation key.
 *
 * `mission_orders.order_type` really does hold an empty string in production
 * data — 15 rows in the local database alone — and `t('report.type_')` then
 * returns its own key, because t() falls back to the key so a missing
 * translation is visibly wrong rather than blank. That fallback is right in
 * general and wrong here: it printed "report.type_ — 4 από 4 δεν έχουν
 * απαντήσει" on a real panel. An order whose type was never recorded is still
 * an order, so it is named as one.
 */
function assistantOrderTypeLabel(?string $type, string $lang): string {
    $type = trim((string) $type);
    if ($type === '') {
        return t('assistant.order_generic', [], $lang);
    }
    $key   = 'report.type_' . $type;
    $label = t($key, [], $lang);
    return $label === $key ? t('assistant.order_generic', [], $lang) : $label;
}

/**
 * Picks the singular or plural wording for a counted phrase.
 *
 * Greek inflects the noun, the adjective AND the verb with the number, so
 * "{n} νέα μηνύματα" reads as broken Greek the moment n is 1 — and "1 πιθανές
 * ερωτήσεις" is exactly the kind of line that makes a carefully built console
 * look machine-translated. Both languages get both forms; English needs it
 * too ("1 items need your attention").
 */
function assistantPlural(string $keyOne, string $keyMany, int $n, array $vars, string $lang): string {
    return t($n === 1 ? $keyOne : $keyMany, $vars + ['n' => $n], $lang);
}

/**
 * Formats a team badge from a mission_teams row's codename/team_number pair,
 * or the "no team" label when the row had neither.
 */
function assistantTeamLabel(array $row, string $lang): string {
    // teamLabel() returns an empty string for a row with no codename, and an
    // empty team badge in a list of findings reads as a rendering bug rather
    // than as "this person is not on a team" — which is itself worth knowing.
    $label = teamLabel($row['codename'] ?? null, $row['team_number'] ?? null);
    return $label !== '' ? $label : t('assistant.no_team', [], $lang);
}

/**
 * Does this message look like a question someone is waiting on an answer to?
 *
 * Greek's question mark is the semicolon — both the ASCII one people actually
 * type (U+003B) and the dedicated U+037E almost nobody has on a keyboard. A
 * heuristic, and named as one in the UI ("πιθανές ερωτήσεις"): it will miss a
 * question phrased as a statement and it will catch a rhetorical one. It is
 * still the difference between "14 μηνύματα" and "14 μηνύματα, 2 ερωτήσεις".
 */
function assistantLooksLikeQuestion(string $message): bool {
    $trimmed = rtrim(trim($message), " \t\n\r\0\x0B");
    if ($trimmed === '') {
        return false;
    }
    $last = mb_substr($trimmed, -1, 1, 'UTF-8');
    return $last === '?' || $last === ';' || $last === "\u{037E}";
}

/**
 * The judgement layer. Pure: same arrays in, same panel out, no database, no
 * clock of its own ($nowTs is a parameter for exactly that reason).
 *
 * TIMESTAMPS ARE ABSOLUTE ON PURPOSE. Every row carries an epoch and the
 * browser renders "πριν 7′" from it. Sending a pre-computed age instead would
 * change this object every single minute, and this object rides inside the
 * poll payload whose md5 is what stops 51KB being re-sent to every open tab
 * every 5 seconds. Severity IS computed here even though it depends on age —
 * but it steps at coarse thresholds, so it changes the payload once per order,
 * not once per minute.
 *
 * $pingStaleSeconds is a parameter rather than a getSetting() call inside so
 * that this function touches no database at all and can be exercised against
 * hand-written fixtures. getSetting() also caches statically per process,
 * which would make two different thresholds untestable in one test run.
 */
function assembleMissionAssistantItems(array $raw, ?int $checkpointTs, int $nowTs, string $lang = DEFAULT_LANGUAGE, ?int $pingStaleSeconds = null): array {
    $sinceTs = assistantWindowStart($checkpointTs, $nowTs);
    $pending = [];
    $new     = [];

    $isNew = function ($ts) use ($checkpointTs) {
        return $checkpointTs !== null && $ts !== null && (int) $ts > $checkpointTs;
    };

    // ── SOS ─────────────────────────────────────────────────────────────────
    foreach ($raw['sos'] ?? [] as $row) {
        $pending[] = [
            'kind'   => 'sos',
            'ref'    => assistantRecordRef('sos', (int) $row['id']),
            'sev'    => empty($row['ack_ts']) ? 'critical' : 'high',
            'icon'   => 'bi-exclamation-octagon-fill',
            'title'  => empty($row['ack_ts'])
                ? t('assistant.sos_unack', ['name' => $row['who']], $lang)
                : t('assistant.sos_open', ['name' => $row['who']], $lang),
            'detail' => assistantTeamLabel($row, $lang),
            'ts'     => (int) $row['ts'],
            'is_new' => $isNew($row['ts']),
            'target' => ASSISTANT_TARGETS['sos'],
        ];
    }

    // ── Shortages ───────────────────────────────────────────────────────────
    foreach ($raw['shortages'] ?? [] as $row) {
        $ageMin = (int) floor(($nowTs - (int) $row['ts']) / 60);
        if (empty($row['ack_ts'])) {
            // Never even acknowledged: the reporter has no sign anyone saw it.
            $sev = $row['severity'] === 'critical' ? 'critical' : 'high';
            $title = t('assistant.shortage_unack', ['title' => $row['title']], $lang);
        } elseif ($ageMin >= ASSISTANT_OPEN_NUDGE_MINUTES) {
            $sev = assistantAcknowledgedSeverity($row['severity']);
            $title = t('assistant.shortage_open', ['title' => $row['title']], $lang);
        } else {
            // Seen minutes ago and being worked on — not something you missed.
            continue;
        }
        $pending[] = [
            'kind'   => 'shortage',
            'ref'    => assistantRecordRef('shortage', (int) $row['id']),
            'sev'    => $sev,
            'icon'   => 'bi-box-seam',
            'title'  => $title,
            // Severity belongs on the row even though it already shaped the
            // colour: two reports from the same person in the same team are
            // otherwise two identical-looking lines, which is exactly what a
            // real mission produces.
            'detail' => t('assistant.from_who_sev', [
                'name' => $row['who'],
                'team' => assistantTeamLabel($row, $lang),
                'sev'  => shortageSeverityLabel($row['severity'], $lang),
            ], $lang),
            'ts'     => (int) $row['ts'],
            'is_new' => $isNew($row['ts']),
            'target' => ASSISTANT_TARGETS['shortage'],
        ];
    }

    // ── Incidents ───────────────────────────────────────────────────────────
    foreach ($raw['incidents'] ?? [] as $row) {
        $ageMin = (int) floor(($nowTs - (int) $row['ts']) / 60);
        if (empty($row['ack_ts'])) {
            $sev = $row['severity'] === 'critical' ? 'critical' : 'high';
            $title = t('assistant.incident_unack', ['type' => incidentTypeLabel($row['incident_type'], $lang)], $lang);
        } elseif ($ageMin >= ASSISTANT_OPEN_NUDGE_MINUTES) {
            $sev = assistantAcknowledgedSeverity($row['severity']);
            $title = t('assistant.incident_open', ['type' => incidentTypeLabel($row['incident_type'], $lang)], $lang);
        } else {
            continue;
        }
        $pending[] = [
            'kind'   => 'incident',
            'ref'    => assistantRecordRef('incident', (int) $row['id']),
            'sev'    => $sev,
            'icon'   => 'bi-bandaid',
            'title'  => $title,
            'detail' => t('assistant.from_who_sev', [
                'name' => $row['who'],
                'team' => assistantTeamLabel($row, $lang),
                'sev'  => incidentSeverityLabel($row['severity'], $lang),
            ], $lang),
            'ts'     => (int) $row['ts'],
            'is_new' => $isNew($row['ts']),
            'target' => ASSISTANT_TARGETS['incident'],
        ];
    }

    // ── Mass-casualty triage ────────────────────────────────────────────────
    // Two aggregate rows, never one per casualty: thirty casualties would
    // otherwise push everything else off a panel capped at twelve lines.
    // Reds waiting for an ambulance are critical for as long as they wait;
    // anyone past their re-triage interval is high (see
    // TRIAGE_RETRIAGE_MINUTES — START is a snapshot, a yellow can be red ten
    // minutes later).
    $triageRows = $raw['triage'] ?? [];
    $waitingReds = array_values(array_filter($triageRows, fn($r) => $r['category'] === 'red'));
    if ($waitingReds) {
        $oldestTs = min(array_map(fn($r) => (int) $r['first_ts'], $waitingReds));
        $pending[] = [
            'kind'   => 'triage',
            'ref'    => 'TRIAGE-RED',
            'sev'    => 'critical',
            'icon'   => 'bi-truck',
            'title'  => assistantPlural('triage.assistant_red_waiting_one', 'triage.assistant_red_waiting', count($waitingReds), ['minutes' => (int) floor(($nowTs - $oldestTs) / 60)], $lang),
            'detail' => '',
            'ts'     => $oldestTs,
            'is_new' => $isNew($oldestTs),
            'target' => ASSISTANT_TARGETS['triage'],
        ];
    }
    $dueRows = array_values(array_filter($triageRows, fn($r) =>
        isset(TRIAGE_RETRIAGE_MINUTES[$r['category']])
        && ($nowTs - (int) $r['last_ts']) >= TRIAGE_RETRIAGE_MINUTES[$r['category']] * 60));
    if ($dueRows) {
        $oldestDue = min(array_map(fn($r) => (int) $r['last_ts'], $dueRows));
        $pending[] = [
            'kind'   => 'triage',
            'ref'    => 'TRIAGE-DUE',
            'sev'    => 'high',
            'icon'   => 'bi-alarm',
            'title'  => assistantPlural('triage.assistant_retriage_due_one', 'triage.assistant_retriage_due', count($dueRows), [], $lang),
            'detail' => '',
            'ts'     => $oldestDue,
            'is_new' => $isNew($oldestDue),
            'target' => ASSISTANT_TARGETS['triage'],
        ];
    }

    // ── Unacknowledged orders ───────────────────────────────────────────────
    foreach ($raw['orders'] ?? [] as $row) {
        $ageMin  = (int) floor(($nowTs - (int) $row['ts']) / 60);
        $missing = (int) $row['total'] - (int) $row['acked'];
        $pending[] = [
            'kind'   => 'order',
            'ref'    => assistantRecordRef('order', (int) $row['id']),
            'sev'    => $ageMin >= ASSISTANT_ORDER_LATE_MINUTES ? 'high' : 'warn',
            'icon'   => 'bi-send-check',
            'title'  => assistantPlural(
                'assistant.order_unacked_one',
                'assistant.order_unacked_many',
                $missing,
                [
                    'type'  => assistantOrderTypeLabel($row['order_type'], $lang),
                    'total' => (int) $row['total'],
                ],
                $lang
            ),
            'detail' => trim((string) $row['task_text']) !== ''
                ? mb_substr(trim((string) $row['task_text']), 0, 90, 'UTF-8')
                : '',
            'ts'     => (int) $row['ts'],
            'is_new' => false, // an order you sent is never news to you
            'target' => ASSISTANT_TARGETS['order'],
        ];
    }

    // ── Dispatch points nobody confirmed ────────────────────────────────────
    foreach ($raw['dispatch'] ?? [] as $row) {
        $ageMin = (int) floor(($nowTs - (int) $row['ts']) / 60);
        $label  = trim((string) ($row['label'] ?? ''));
        $pending[] = [
            'kind'   => 'dispatch',
            // Deliberately NO ref, which is what hides «Εξήγησέ μου» on this
            // row. A ref is a promise that the AI digest can resolve the code
            // to a real record, and aiLiveBuildDigest() never reads
            // mission_dispatch_points at all — so DISP-12 would be a citation
            // pointing at nothing. Sector rows below DO carry one, because
            // that digest really does query mission_search_sectors.
            'sev'    => $ageMin >= ASSISTANT_ORDER_LATE_MINUTES ? 'high' : 'warn',
            'icon'   => 'bi-geo-alt-fill',
            // A dispatch with no team is not addressed to a team called
            // "Χωρίς ομάδα" — loadMissionDispatchesForUser() shows a
            // team_id IS NULL dispatch to EVERYONE on the mission. Naming a
            // team there would invent one, so that case gets its own wording.
            'title'  => ($row['codename'] === null && $row['team_number'] === null)
                ? t($row['type'] === 'polygon' ? 'assistant.dispatch_area_unconfirmed_anyone' : 'assistant.dispatch_point_unconfirmed_anyone', [], $lang)
                : t(
                    $row['type'] === 'polygon' ? 'assistant.dispatch_area_unconfirmed' : 'assistant.dispatch_point_unconfirmed',
                    ['team' => assistantTeamLabel($row, $lang)],
                    $lang
                ),
            'detail' => $label !== '' ? mb_substr($label, 0, 90, 'UTF-8') : '',
            'ts'     => (int) $row['ts'],
            'is_new' => false, // an order you sent is never news to you
            'target' => ASSISTANT_TARGETS['dispatch'],
        ];
    }

    // ── Sectors assigned but never acknowledged ─────────────────────────────
    foreach ($raw['sector'] ?? [] as $row) {
        $ageMin = (int) floor(($nowTs - (int) $row['ts']) / 60);
        $pending[] = [
            'kind'   => 'sector',
            'ref'    => assistantRecordRef('sector', (int) $row['id']),
            'sev'    => $ageMin >= ASSISTANT_ORDER_LATE_MINUTES ? 'high' : 'warn',
            'icon'   => 'bi-grid-3x3',
            'title'  => t('assistant.sector_unacked', ['team' => assistantTeamLabel($row, $lang)], $lang),
            'detail' => mb_substr((string) $row['label'], 0, 90, 'UTF-8'),
            'ts'     => (int) $row['ts'],
            'is_new' => false,
            'target' => ASSISTANT_TARGETS['sector'],
        ];
    }

    // ── «Δεν μπορώ» — orders handed back ────────────────────────────────────
    //
    // The field answered, and the answer is now command's to act on: re-send,
    // reassign, cancel. High, but NOT part of the overdue count — that alarm
    // means "still waiting on the field", and this is the opposite.
    //
    // «Το είδα» clears it, like the advisories. That is a choice, not an
    // oversight: a task or a photo request has no command-side "cancel", so a
    // row that only went away when command acted would stay for the rest of
    // the mission on an order command decided to let go. The decline already
    // arrived as a loud alert, and the order's acknowledgement card keeps its
    // red ✗ and the reason for as long as the card is open.
    foreach ($raw['declines'] ?? [] as $row) {
        if ($checkpointTs !== null && (int) $row['ts'] <= $checkpointTs) {
            continue;
        }
        $kind = (string) $row['target_kind'];
        [$whatKey, $subject] = match ($kind) {
            'order'    => ['order.' . $row['order_type'] . '.card_title', $row['order_type'] === 'task' ? (string) $row['task_text'] : ''],
            'dispatch' => [$row['dispatch_type'] === 'point' ? 'order.dispatch_point.card_title' : 'order.dispatch_area.card_title', (string) $row['dispatch_label']],
            'route'    => ['order.route.card_title', (string) $row['route_title']],
            default    => ['order.sector.card_title', (string) $row['sector_label']],
        };
        $subject = trim($subject);
        $what = t($whatKey, [], $lang) . ($subject !== '' ? ' «' . mb_substr($subject, 0, 60, 'UTF-8') . '»' : '');
        $who = ($row['codename'] !== null || $row['team_number'] !== null)
            ? t('decline.who_team', ['team' => assistantTeamLabel($row, $lang), 'name' => (string) $row['who']], $lang)
            : (string) $row['who'];
        $note = trim((string) $row['note']);
        $pending[] = [
            'kind'   => 'declined',
            'sev'    => 'high',
            'icon'   => 'bi-x-octagon',
            'title'  => t('assistant.declined', ['who' => $who, 'what' => $what], $lang),
            'detail' => t('decline.reason.' . $row['reason'], [], $lang)
                . ($note !== '' ? t('decline.note_part', ['note' => mb_substr($note, 0, 90, 'UTF-8')], $lang) : ''),
            'ts'     => (int) $row['ts'],
            'is_new' => $isNew($row['ts']),
            'target' => ASSISTANT_TARGETS[$kind] ?? ASSISTANT_TARGETS['order'],
        ];
    }

    // ── Voice messages nobody has listened to ───────────────────────────────
    foreach ($raw['voice'] ?? [] as $row) {
        $seconds = $row['duration_ms'] !== null ? max(1, (int) ceil(((int) $row['duration_ms']) / 1000)) : null;
        $pending[] = [
            'kind'   => 'voice',
            // No ref: aiLiveBuildDigest() does not read mission_voice_messages,
            // so a code here would cite nothing — same rule as dispatch above.
            // Nor could the model say anything useful about audio it cannot hear.
            'sev'    => 'high',
            'icon'   => 'bi-mic-fill',
            'title'  => t('assistant.voice_unheard', ['name' => $row['who']], $lang),
            'detail' => trim(assistantTeamLabel($row, $lang) . ($seconds !== null ? ' · ' . t('voice.duration_s', ['n' => $seconds], $lang) : '')),
            'ts'     => (int) $row['ts'],
            // Genuinely news to the coordinator, unlike an order they sent
            // themselves — somebody spoke and they have not heard it yet.
            'is_new' => $isNew($row['ts']),
            'target' => ASSISTANT_TARGETS['voice'],
        ];
    }

    // ── Hazard-zone breaches ────────────────────────────────────────────────
    foreach ($raw['breaches'] ?? [] as $row) {
        $stillInside = empty($row['exited_ts']);
        $pending[] = [
            'kind'   => 'breach',
            'sev'    => $stillInside ? 'high' : 'warn',
            'icon'   => 'bi-cone-striped',
            'title'  => $stillInside
                ? t('assistant.breach_inside', ['name' => $row['who'], 'area' => $row['area_label']], $lang)
                : t('assistant.breach_open', ['name' => $row['who'], 'area' => $row['area_label']], $lang),
            'detail' => assistantTeamLabel($row, $lang),
            'ts'     => (int) $row['ts'],
            'is_new' => $isNew($row['ts']),
            'target' => ASSISTANT_TARGETS['breach'],
        ];
    }

    // ── Silence from the field ──────────────────────────────────────────────
    // Threshold comes from the org's own auto-ping setting, so an org that
    // pings every 30s and one that pings every 5 minutes both get a sensible
    // answer without a second knob to configure.
    $staleAfter = $pingStaleSeconds ?? warRoomPingStaleThresholdSeconds();
    // One row per person, keeping their FRESHEST fix. A volunteer legitimately
    // holds two overlapping approved shifts on a long operation — a morning
    // one running late and the afternoon one already begun — and the query
    // returns a row per shift. Judged per row, the earlier shift's stale ping
    // would report someone as silent while their current shift is pinging
    // normally, and the same name would appear twice. Deduped here rather
    // than in SQL so the fix holds whatever else joins into that query later.
    $silentByPerson = [];
    foreach ($raw['silent'] ?? [] as $row) {
        $id = (int) ($row['id'] ?? 0);
        $seen = $silentByPerson[$id] ?? null;
        if ($seen === null
            || ($row['last_ping_ts'] !== null
                && ($seen['last_ping_ts'] === null || (int) $row['last_ping_ts'] > (int) $seen['last_ping_ts']))) {
            $silentByPerson[$id] = $row;
        }
    }

    foreach ($silentByPerson as $row) {
        $lastPing = $row['last_ping_ts'] === null ? null : (int) $row['last_ping_ts'];
        $onDutyMin = $row['on_duty_since'] === null ? 0 : (int) floor(($nowTs - (int) $row['on_duty_since']) / 60);

        if ($lastPing === null) {
            // Never sent a position at all. Only worth raising once they have
            // been on duty long enough for it to mean something — at the top
            // of a shift half the roster is still walking to the vehicle.
            if ($onDutyMin < ASSISTANT_NEVER_PINGED_MINUTES) {
                continue;
            }
            $sev = 'warn';
            $title = t('assistant.never_pinged', ['name' => $row['who']], $lang);
            $ts = (int) ($row['on_duty_since'] ?? $nowTs);
        } else {
            $silentFor = $nowTs - $lastPing;
            if ($silentFor < $staleAfter) {
                continue;
            }
            $sev = $silentFor >= $staleAfter * 3 ? 'high' : 'warn';
            $title = t('assistant.silent', ['name' => $row['who']], $lang);
            $ts = $lastPing;
        }
        // «Το είδα» DOES dismiss this one, unlike everything above it.
        //
        // The original rule was that nothing in ΕΚΚΡΕΜΟΥΝ clears by being
        // read. That is right for a command obligation — an unanswered SOS or
        // an order nobody confirmed does not stop needing an answer because
        // somebody looked at it. It is wrong for an advisory: a coordinator
        // who has already raised the silent crew on the radio pressed the
        // button, watched the list not change, and reasonably concluded the
        // button was broken.
        //
        // Dismissal is not permanent and needs no new state. The item's ts is
        // that person's last fix, so the moment they ping again and go quiet
        // afresh the item is newer than the checkpoint and comes straight
        // back. Silence that has genuinely continued is silence you already
        // know about.
        if ($checkpointTs !== null && $ts <= $checkpointTs) {
            continue;
        }
        $pending[] = [
            'kind'   => 'silent',
            'sev'    => $sev,
            'icon'   => 'bi-broadcast-pin',
            'title'  => $title,
            'detail' => assistantTeamLabel($row, $lang),
            'ts'     => $ts,
            'is_new' => false,
            'target' => ASSISTANT_TARGETS['silent'],
        ];
    }

    // ── Teams that have stopped moving ──────────────────────────────────────
    //
    // Beside the silence findings because it answers the neighbouring
    // question. Silence is "we cannot see them"; this is "we can see them and
    // they are not going anywhere", which has entirely different causes and
    // an entirely different response.
    foreach ($raw['stalled'] ?? [] as $row) {
        // «Το είδα» clears it, like the other advisories: a coordinator who
        // has raised the team on the radio and been told they are searching a
        // scree slope should not keep being told. It comes back on its own
        // once the window moves past the checkpoint and they still have not
        // moved.
        if ($checkpointTs !== null && (int) $row['ts'] <= $checkpointTs) {
            continue;
        }
        $pending[] = [
            'kind'   => 'stalled',
            'sev'    => 'warn',
            'icon'   => 'bi-pause-circle',
            'title'  => t('assistant.stalled', [
                'team'    => $row['label'],
                'minutes' => ASSISTANT_STALLED_MINUTES,
            ], $lang),
            'detail' => t('assistant.stalled_detail', [
                'metres'  => (int) $row['metres'],
                'members' => (int) $row['members'],
            ], $lang),
            'ts'     => (int) $row['ts'],
            'is_new' => false,
            'target' => ASSISTANT_TARGETS['stalled'],
        ];
    }

    // ── Unchecked clues ─────────────────────────────────────────────────────
    foreach ($raw['poi'] ?? [] as $row) {
        // Advisory, so «Το είδα» dismisses it — same reasoning as the silence
        // block above. A clue photographed before the coordinator last caught
        // up is one they have seen; the next one carries a newer timestamp and
        // appears on its own.
        if ($checkpointTs !== null && (int) $row['ts'] <= $checkpointTs) {
            continue;
        }
        $pending[] = [
            'kind'   => 'poi',
            'ref'    => assistantRecordRef('poi', (int) $row['id']),
            'sev'    => 'info',
            'icon'   => 'bi-pin-map',
            'title'  => (int) $row['photos'] === 0
                ? t('assistant.poi_unchecked_none', [], $lang)
                : assistantPlural(
                    'assistant.poi_unchecked_one',
                    'assistant.poi_unchecked_many',
                    (int) $row['photos'],
                    [],
                    $lang
                ),
            'detail' => '',
            'ts'     => (int) $row['ts'],
            'is_new' => $isNew($row['ts']),
            'target' => ASSISTANT_TARGETS['poi'],
        ];
    }

    // ── New chat, one row per room ──────────────────────────────────────────
    $rooms = [];
    foreach ($raw['chat'] ?? [] as $row) {
        $key = $row['team_id'] === null ? 'general' : (string) $row['team_id'];
        if (!isset($rooms[$key])) {
            $rooms[$key] = [
                'label'     => $row['team_id'] === null
                    ? t('assistant.room_general', [], $lang)
                    : assistantTeamLabel($row, $lang),
                'n'         => 0,
                'questions' => 0,
                'people'    => [],
                'last'      => '',
                'last_who'  => '',
                'ts'        => 0,
            ];
        }
        $rooms[$key]['n']++;
        if (assistantLooksLikeQuestion((string) $row['message'])) {
            $rooms[$key]['questions']++;
        }
        $rooms[$key]['people'][$row['who']] = true;
        $rooms[$key]['last']     = (string) $row['message'];
        $rooms[$key]['last_who'] = (string) $row['who'];
        $rooms[$key]['ts']       = max($rooms[$key]['ts'], (int) $row['ts']);
    }
    foreach ($rooms as $room) {
        $new[] = [
            'kind'   => 'chat',
            // A question nobody answered is the single thing a coordinator
            // most often means by "did I miss something"; plain chatter is not.
            'sev'    => $room['questions'] > 0 ? 'warn' : 'info',
            'icon'   => 'bi-chat-left-dots',
            // Built from two independently-numbered clauses rather than four
            // whole-sentence variants: the message count and the question
            // count vary separately, and one key per combination is how a
            // lang file grows a corner nobody ever reads again.
            'title'  => assistantPlural(
                    'assistant.chat_new_one',
                    'assistant.chat_new_many',
                    $room['n'],
                    ['room' => $room['label']],
                    $lang
                ) . ($room['questions'] > 0
                    ? assistantPlural(
                        'assistant.chat_questions_one',
                        'assistant.chat_questions_many',
                        $room['questions'],
                        [],
                        $lang
                    )
                    : ''),
            'detail' => t('assistant.chat_last', [
                'name' => $room['last_who'],
                'text' => mb_substr($room['last'], 0, 90, 'UTF-8'),
            ], $lang),
            'ts'     => $room['ts'],
            'is_new' => true,
            'target' => ASSISTANT_TARGETS['chat'],
        ];
    }

    // ── New media, one row for the lot ──────────────────────────────────────
    if (!empty($raw['media'])) {
        $people = [];
        $lastTs = 0;
        foreach ($raw['media'] as $row) {
            $people[$row['who']] = true;
            $lastTs = max($lastTs, (int) $row['ts']);
        }
        $names = array_keys($people);
        $new[] = [
            'kind'   => 'media',
            'sev'    => 'info',
            'icon'   => 'bi-camera',
            'title'  => assistantPlural(
                'assistant.media_new_one',
                'assistant.media_new_many',
                count($raw['media']),
                [],
                $lang
            ),
            'detail' => implode(', ', array_slice($names, 0, 4))
                . (count($names) > 4 ? ' ' . t('common.and_n_more', ['n' => count($names) - 4], $lang) : ''),
            'ts'     => $lastTs,
            'is_new' => true,
            'target' => ASSISTANT_TARGETS['media'],
        ];
    }

    // Worst first, then oldest first inside a severity — the thing that has
    // been ignored longest is the thing most worth naming first.
    $sorter = function (array $a, array $b) {
        $rank = assistantSeverityRank($b['sev']) <=> assistantSeverityRank($a['sev']);
        return $rank !== 0 ? $rank : ($a['ts'] <=> $b['ts']);
    };
    usort($pending, $sorter);
    usort($new, $sorter);

    $worst = null;
    foreach (array_merge($pending, $new) as $item) {
        if ($worst === null || assistantSeverityRank($item['sev']) > assistantSeverityRank($worst)) {
            $worst = $item['sev'];
        }
    }

    // Counted BEFORE collapsing: the badge must say how many things are
    // outstanding, not how many lines they print on.
    $pendingTotal = count($pending);
    $newTotal     = count($new);

    // Orders somebody was told to act on and has not answered, past the point
    // where waiting is still reasonable. This is the ONE number on this panel
    // that earns an alert of its own rather than a quiet badge — see the
    // ticker row in war-room.php. Counted here, not derived in the browser,
    // because the rows the browser receives are capped and collapsed and would
    // undercount on a busy mission.
    //
    // Deliberately only these three kinds. An open incident or a quiet
    // volunteer is also urgent, but neither is a question the coordinator
    // asked and is still waiting on an answer to, which is what this alarm
    // means. Widening it would make it fire almost continuously and therefore
    // mean nothing.
    $overdue = 0;
    foreach ($pending as $item) {
        if ($item['sev'] === 'high' && in_array($item['kind'], ['order', 'dispatch', 'sector'], true)) {
            $overdue++;
        }
    }

    $pendingRows = assistantCollapseIdentical($pending);
    $newRows     = assistantCollapseIdentical($new);

    return [
        'since_ts'     => $sinceTs,
        'is_first'     => $checkpointTs === null,
        'pending'      => array_slice($pendingRows, 0, ASSISTANT_SECTION_CAP),
        'new'          => array_slice($newRows, 0, ASSISTANT_SECTION_CAP),
        // Rows beyond the cap, not items — these two drive a "+N ακόμη" line
        // under a list of rows, while counts below drive the badge.
        'pending_more' => max(0, count($pendingRows) - ASSISTANT_SECTION_CAP),
        'new_more'     => max(0, count($newRows) - ASSISTANT_SECTION_CAP),
        'counts'       => [
            'pending' => $pendingTotal,
            'new'     => $newTotal,
            'total'   => $pendingTotal + $newTotal,
            'worst'   => $worst,
            'overdue' => $overdue,
        ],
        // Shipped so the ticker can name the threshold it is reporting against
        // instead of hardcoding "30" in a translation string that would then
        // lie the day the constant moves.
        'overdue_after_minutes' => ASSISTANT_ORDER_LATE_MINUTES,
    ];
}

/**
 * The whole panel, ready to ride the poll payload. Command staff only — the
 * caller enforces that, and must, because every query above reads the whole
 * mission's field traffic.
 */
function buildMissionAssistantPanel(int $missionId, int $userId, array $missionShiftIds, ?string $lang = null): array {
    $nowTs = time();
    $checkpointTs = missionAssistantCheckpointTs($missionId, $userId);
    $raw = collectMissionAssistantRaw($missionId, $userId, $missionShiftIds, $checkpointTs, $nowTs);
    return assembleMissionAssistantItems(
        $raw,
        $checkpointTs,
        $nowTs,
        $lang ?? getUserLanguage($userId),
        warRoomPingStaleThresholdSeconds()
    );
}
