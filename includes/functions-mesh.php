<?php
/**
 * VolunteerOps - The LoRa gateway at the command post (v3.427.0).
 *
 * When a rescuer's mobile data dies, a Meshtastic node in their pocket can
 * still send their position over the radio. A second node on a USB cable at
 * the command post hears it, and the Action Room page (assets/js/mesh-gateway.js,
 * over Web Serial) forwards what it hears here. Nothing is installed: the page
 * is the gateway.
 *
 * What comes in is a list of position packets, each saying which node sent it
 * (a 32-bit number, shown as !570dee8c), the sender's packet id, the place, how
 * blurred the place is (precision_bits) and how well it was heard. Three rules
 * decide what happens to one:
 *
 *  - The same packet reaches the gateway more than once when two nodes repeat
 *    it, and the page resends a batch it was not sure arrived. (node, packet id)
 *    is the key, kept in mesh_packets, so a packet is recorded once.
 *  - A node is somebody's only after command has bound it to a person
 *    (mesh_nodes). A packet from a node nobody carries - the public mesh has
 *    plenty - is remembered as "heard" (who, how well, no coordinates) so it
 *    can be offered for binding, and is otherwise dropped.
 *  - A bound node's position goes through recordVolunteerPing() with via='mesh',
 *    the same funnel as every other position: approved on the mission, taking
 *    part in the Action Room, plausible speed. A position blurred beyond the
 *    accuracy the Action Room accepts is not stored at all (a node on the
 *    default public channel sends a ~3 km cell, which is not a place).
 *
 * SOS and status codes over the radio are a later step; this is positions only.
 */

/** Most positions one ingest request may carry; bounds what a hostile body can make a request do. */
const MESH_BATCH_MAX = 100;
/** mesh_packets rows older than this are deleted; they only exist for dedupe and the "heard" list. */
const MESH_PACKETS_KEEP_HOURS = 48;
/**
 * A radio position is not stored while the person's phone has delivered a fix
 * of its own within this many seconds: the phone is online and finer, and two
 * receivers would alternate the pin (recordVolunteerPing()).
 */
const MESH_PHONE_ACTIVE_SECONDS = 90;
/** A node heard within this many minutes is offered for binding. */
const MESH_HEARD_WINDOW_MINUTES = 30;

/**
 * M3 (Settings > Action Room): whether war-room.php arms the automatic
 * NORMAL/MESH trigger for field participants at all. Off by default - the
 * per-volunteer BLE pairing (assets/js/mesh-ble.js) works either way as a
 * manual test tool, this only gates the unattended part.
 */
function meshFallbackEnabled(): bool {
    return getSetting('war_room_mesh_fallback_enabled', '0') === '1';
}

/**
 * One record from the page, validated, or null. Numbers are range-checked and
 * rejected rather than clamped, like every other ingest in the app.
 */
function meshParseRecord($raw): ?array {
    if (!is_array($raw)) {
        return null;
    }
    foreach (['node', 'id'] as $key) {
        if (!isset($raw[$key]) || !is_numeric($raw[$key]) || (float) $raw[$key] < 1 || (float) $raw[$key] > 4294967295.0) {
            return null;
        }
    }
    if (!isset($raw['lat'], $raw['lng']) || !is_numeric($raw['lat']) || !is_numeric($raw['lng'])) {
        return null;
    }
    $lat = (float) $raw['lat'];
    $lng = (float) $raw['lng'];
    if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 || ($lat == 0.0 && $lng == 0.0)) {
        return null;
    }
    $precision = null;
    if (isset($raw['prec']) && is_numeric($raw['prec'])) {
        $precision = (int) $raw['prec'];
        if ($precision < 0 || $precision > 32) {
            return null;
        }
    }
    $snr = (isset($raw['snr']) && is_numeric($raw['snr']) && abs((float) $raw['snr']) <= 100) ? round((float) $raw['snr'], 2) : null;
    $rssi = (isset($raw['rssi']) && is_numeric($raw['rssi']) && (int) $raw['rssi'] >= -200 && (int) $raw['rssi'] <= 0) ? (int) $raw['rssi'] : null;
    $hops = (isset($raw['hops']) && is_numeric($raw['hops']) && (int) $raw['hops'] >= 0 && (int) $raw['hops'] <= 15) ? (int) $raw['hops'] : null;
    $age = (isset($raw['age_s']) && is_numeric($raw['age_s'])) ? (int) min(max((float) $raw['age_s'], 0.0), 86400.0) : 0;
    return [
        'node'  => (int) $raw['node'],
        'id'    => (int) $raw['id'],
        'lat'   => $lat,
        'lng'   => $lng,
        'prec'  => $precision,
        'age_s' => $age,
        'speed' => parseSpeedMps($raw['speed'] ?? null),
        'snr'   => $snr,
        'rssi'  => $rssi,
        'hops'  => $hops,
    ];
}

/**
 * How far from the truth a position can be because the sender rounded it, in
 * metres, or null when it was not rounded. Meshtastic keeps `precision_bits`
 * of the 32-bit coordinate, so a cell is 2^(32-bits) x 1e-7 degrees on a side
 * and the truth is somewhere in it; half a cell is the honest error. A node
 * that sends no precision (older firmware) or all 32 bits sent the exact fix.
 */
function meshBlurRadiusM(?int $precisionBits): ?float {
    if ($precisionBits === null || $precisionBits <= 0 || $precisionBits >= 32) {
        return null;
    }
    $cellDegrees = (2 ** (32 - $precisionBits)) * 1e-7;
    return round($cellDegrees / 2 * 111320.0, 1);
}

/** The shift a person is approved on in this mission (a ping is stored against a shift), or null. */
function meshShiftFor(int $missionId, int $userId): ?int {
    $shiftId = dbFetchValue(
        "SELECT pr.shift_id FROM participation_requests pr
           JOIN shifts s ON s.id = pr.shift_id
          WHERE s.mission_id = ? AND pr.volunteer_id = ? AND pr.status = ?
          ORDER BY pr.id DESC LIMIT 1",
        [$missionId, $userId, PARTICIPATION_APPROVED]
    );
    return $shiftId === false || $shiftId === null ? null : (int) $shiftId;
}

/** node_num => user_id for the bound nodes among $nodeNums. */
function meshBoundUsers(array $nodeNums): array {
    $nodeNums = array_values(array_unique(array_map('intval', $nodeNums)));
    if (!$nodeNums) {
        return [];
    }
    $marks = implode(',', array_fill(0, count($nodeNums), '?'));
    $map = [];
    foreach (dbFetchAll("SELECT node_num, user_id FROM mesh_nodes WHERE node_num IN ({$marks})", $nodeNums) as $row) {
        $map[(int) $row['node_num']] = (int) $row['user_id'];
    }
    return $map;
}

/**
 * Record what the gateway forwarded. $records are meshParseRecord() inputs
 * (raw, from the page). Returns the counts the page shows:
 *   recorded    stored as a position of the person who carries the node
 *   duplicate   already seen
 *   unbound     heard, but nobody has been bound to that node
 *   blurred     bound, but rounded too coarsely to be a place
 *   skipped     bound, but not needed: the phone is reporting for itself, or a
 *               newer position is already stored
 *   refused     bound, but the position funnel turned it away (not on this
 *               mission, not in the Action Room, an impossible jump...)
 *   invalid     not a position the server could read
 */
function meshIngest(int $missionId, array $records): array {
    $summary = ['recorded' => 0, 'duplicate' => 0, 'unbound' => 0, 'blurred' => 0, 'skipped' => 0, 'refused' => 0, 'invalid' => 0];

    $parsed = [];
    foreach (array_slice(array_values($records), 0, MESH_BATCH_MAX) as $raw) {
        $rec = meshParseRecord($raw);
        if ($rec === null) {
            $summary['invalid']++;
        } else {
            $parsed[] = $rec;
        }
    }
    // Oldest first: the position funnel skips a fix older than the newest one
    // it already holds, so the order decides whether a backlog is kept.
    usort($parsed, fn($a, $b) => $b['age_s'] <=> $a['age_s']);

    $boundUsers = meshBoundUsers(array_column($parsed, 'node'));
    $maxAccuracy = (float) getSetting('war_room_max_ping_accuracy_m', '50');
    $userRows = [];

    foreach ($parsed as $rec) {
        $userId = $boundUsers[$rec['node']] ?? null;
        $blur = meshBlurRadiusM($rec['prec']);
        if ($userId === null) {
            $outcome = 'unbound';
        } elseif ($blur !== null && $maxAccuracy > 0 && $blur > $maxAccuracy) {
            $outcome = 'blurred';
        } else {
            $outcome = 'recorded';
        }

        // The key first: a packet already seen does nothing else.
        $inserted = dbExecute(
            "INSERT IGNORE INTO mesh_packets (node_num, packet_id, mission_id, snr, rssi, hops, outcome, heard_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW())",
            [$rec['node'], $rec['id'], $missionId, $rec['snr'], $rec['rssi'], $rec['hops'], $outcome]
        );
        if ($inserted === 0) {
            $summary['duplicate']++;
            continue;
        }
        if ($outcome !== 'recorded') {
            $summary[$outcome]++;
            continue;
        }

        $shiftId = meshShiftFor($missionId, $userId);
        $user = $userRows[$userId] ?? ($userRows[$userId] = dbFetchOne("SELECT id, name, language FROM users WHERE id = ?", [$userId]));
        $result = ($shiftId !== null && $user)
            ? recordVolunteerPing(
                $user, $shiftId, $rec['lat'], $rec['lng'],
                $blur, null, 'auto', 'mesh',
                $rec['age_s'] * 1000, false, $rec['speed'], null
            )
            : ['ok' => false];
        if (!empty($result['ok']) && empty($result['skipped'])) {
            $summary['recorded']++;
        } elseif (!empty($result['ok'])) {
            // Not an error: the phone is reporting for itself, or a newer
            // position is already stored.
            $summary['skipped']++;
            dbExecute(
                "UPDATE mesh_packets SET outcome = 'skipped' WHERE node_num = ? AND packet_id = ?",
                [$rec['node'], $rec['id']]
            );
        } else {
            $summary['refused']++;
            dbExecute(
                "UPDATE mesh_packets SET outcome = 'refused' WHERE node_num = ? AND packet_id = ?",
                [$rec['node'], $rec['id']]
            );
        }
    }

    // Housekeeping on a small share of requests: the table only serves dedupe
    // and the "heard" list, and a gateway on a busy public mesh adds to it all day.
    if (random_int(1, 40) === 1) {
        dbExecute("DELETE FROM mesh_packets WHERE heard_at < DATE_SUB(NOW(), INTERVAL " . MESH_PACKETS_KEEP_HOURS . " HOUR)");
    }
    return $summary;
}

/** The nodes bound to people of this mission, with when each was last heard and how well. */
function meshBoundNodes(int $missionId): array {
    $participants = actionRoomParticipantIds($missionId);
    if (!$participants) {
        return [];
    }
    $marks = implode(',', array_fill(0, count($participants), '?'));
    $rows = dbFetchAll(
        "SELECT n.node_num, n.label, n.user_id, u.name,
                p.snr, p.rssi, p.hops, p.outcome,
                TIMESTAMPDIFF(SECOND, p.heard_at, NOW()) AS age_s
           FROM mesh_nodes n
           JOIN users u ON u.id = n.user_id
           LEFT JOIN mesh_packets p ON p.id = (SELECT MAX(id) FROM mesh_packets WHERE node_num = n.node_num)
          WHERE n.user_id IN ({$marks})
          ORDER BY u.name, n.node_num",
        $participants
    );
    return array_map(fn($r) => [
        'node'    => (int) $r['node_num'],
        'label'   => $r['label'],
        'user_id' => (int) $r['user_id'],
        'name'    => $r['name'],
        'age_s'   => $r['age_s'] === null ? null : (int) $r['age_s'],
        'snr'     => $r['snr'] === null ? null : (float) $r['snr'],
        'rssi'    => $r['rssi'] === null ? null : (int) $r['rssi'],
        'hops'    => $r['hops'] === null ? null : (int) $r['hops'],
        'outcome' => $r['outcome'],
    ], $rows);
}

/** Nodes heard lately that nobody carries yet - the choices for "this is X's node". */
function meshHeardUnbound(): array {
    $rows = dbFetchAll(
        "SELECT p.node_num, p.snr, p.rssi, p.hops, TIMESTAMPDIFF(SECOND, p.heard_at, NOW()) AS age_s
           FROM mesh_packets p
          WHERE p.id IN (SELECT MAX(id) FROM mesh_packets
                          WHERE heard_at > DATE_SUB(NOW(), INTERVAL " . MESH_HEARD_WINDOW_MINUTES . " MINUTE)
                          GROUP BY node_num)
            AND p.node_num NOT IN (SELECT node_num FROM mesh_nodes)
          ORDER BY p.id DESC
          LIMIT 60"
    );
    return array_map(fn($r) => [
        'node'  => (int) $r['node_num'],
        'age_s' => (int) $r['age_s'],
        'snr'   => $r['snr'] === null ? null : (float) $r['snr'],
        'rssi'  => $r['rssi'] === null ? null : (int) $r['rssi'],
        'hops'  => $r['hops'] === null ? null : (int) $r['hops'],
    ], $rows);
}

/** Bind a node to a person (replacing whoever had it). False when the number is not a node number. */
function meshBindNode(int $nodeNum, int $userId, ?string $label, int $boundBy): bool {
    if ($nodeNum < 1 || $nodeNum > 4294967295 || $userId < 1) {
        return false;
    }
    $label = $label !== null ? mb_substr(trim($label), 0, 60) : null;
    dbExecute(
        "INSERT INTO mesh_nodes (node_num, user_id, label, created_by, created_at)
         VALUES (?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), label = VALUES(label),
                                 created_by = VALUES(created_by), created_at = NOW()",
        [$nodeNum, $userId, $label === '' ? null : $label, $boundBy]
    );
    return true;
}

function meshUnbindNode(int $nodeNum): int {
    return dbExecute("DELETE FROM mesh_nodes WHERE node_num = ?", [$nodeNum]);
}

/** The people of the mission a node can be bound to, grouped by team like the ground-level window. */
function meshCandidates(int $missionId): array {
    $participants = actionRoomParticipantIds($missionId);
    if (!$participants) {
        return [];
    }
    $marks = implode(',', array_fill(0, count($participants), '?'));
    $names = [];
    foreach (dbFetchAll("SELECT id, name FROM users WHERE id IN ({$marks})", $participants) as $row) {
        $names[(int) $row['id']] = $row['name'];
    }
    $groups = [];
    $placed = [];
    foreach (loadMissionTeamsForMission($missionId) as $team) {
        $members = [];
        foreach ($team['members'] as $member) {
            $uid = (int) $member['user_id'];
            if (isset($names[$uid]) && !isset($placed[$uid])) {
                $members[] = ['user_id' => $uid, 'name' => $names[$uid]];
                $placed[$uid] = true;
            }
        }
        if ($members) {
            $groups[] = ['label' => teamLabel($team['codename'], $team['team_number']), 'members' => $members];
        }
    }
    $loose = [];
    foreach ($names as $uid => $name) {
        if (!isset($placed[$uid])) {
            $loose[] = ['user_id' => $uid, 'name' => $name];
        }
    }
    if ($loose) {
        usort($loose, fn($a, $b) => strcasecmp($a['name'], $b['name']));
        $groups[] = ['label' => null, 'members' => $loose];
    }
    return $groups;
}
