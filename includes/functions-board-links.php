<?php
/**
 * VolunteerOps - Board View Link functions (v3.499.0)
 * A read-only, non-interactive big-font mirror of the live map + team roster
 * (board-view.php), for a second screen in the ops room or a liaising
 * authority. Same token-row idiom as mission_locate_links. Deliberately a
 * TRIMMED data set, not a reuse of war-room.php's own $loadPins (which is an
 * inline closure there, tied to page-local state and to vitals/heart-rate
 * visibility this public page should never carry): no vitals, no chat, no
 * SOS/incident detail, no phone numbers - position, team colour and basic
 * status only.
 */

if (!defined('VOLUNTEEROPS')) {
    die('Direct access not permitted');
}

function loadMissionBoardLinks(int $missionId): array {
    $rows = dbFetchAll(
        "SELECT bl.*, u.name AS created_by_name
         FROM mission_board_links bl
         LEFT JOIN users u ON u.id = bl.created_by
         WHERE bl.mission_id = ?
         ORDER BY bl.id DESC",
        [$missionId]
    );
    $now = date('Y-m-d H:i:s');
    return array_map(static function (array $r) use ($now) {
        $status = 'active';
        if ($r['revoked_at']) {
            $status = 'revoked';
        } elseif ($r['expires_at'] && $r['expires_at'] < $now) {
            $status = 'expired';
        }
        return [
            'id'              => (int) $r['id'],
            'token'           => (string) $r['token'],
            // Built server-side with BASE_URL - see the matching comment in
            // loadMissionLocateLinks() for why this is never reconstructed
            // client-side from location.origin.
            'url'             => rtrim(BASE_URL, '/') . '/board-view.php?token=' . $r['token'],
            'label'           => $r['label'] !== null && $r['label'] !== '' ? (string) $r['label'] : null,
            'created_by_name' => $r['created_by_name'] ?? null,
            'created_at'      => $r['created_at'],
            'expires_at'      => $r['expires_at'],
            'status'          => $status,
            'last_viewed_at'  => $r['last_viewed_at'],
            'view_count'      => (int) $r['view_count'],
        ];
    }, $rows);
}

function generateBoardLinkToken(): string {
    return bin2hex(random_bytes(32));
}

/** @return array The new row's id and token. */
function createMissionBoardLink(int $missionId, ?string $label, int $createdBy, ?int $durationHours): array {
    $token = generateBoardLinkToken();
    $expiresAt = $durationHours !== null ? date('Y-m-d H:i:s', time() + $durationHours * 3600) : null;
    $id = dbInsert(
        "INSERT INTO mission_board_links (mission_id, token, label, created_by, created_at, expires_at)
         VALUES (?, ?, ?, ?, NOW(), ?)",
        [$missionId, $token, $label !== null && $label !== '' ? $label : null, $createdBy, $expiresAt]
    );
    return ['id' => (int) $id, 'token' => $token];
}

function revokeMissionBoardLink(int $linkId, int $missionId): bool {
    $affected = dbExecute(
        "UPDATE mission_board_links SET revoked_at = NOW() WHERE id = ? AND mission_id = ? AND revoked_at IS NULL",
        [$linkId, $missionId]
    );
    return $affected > 0;
}

/** The public-page lookup (board-view.php): same self-expiry idiom as the locate link. */
function findActiveMissionBoardLink(string $token): ?array {
    if ($token === '') {
        return null;
    }
    $row = dbFetchOne(
        "SELECT bl.id, bl.mission_id
         FROM mission_board_links bl
         JOIN missions m ON m.id = bl.mission_id
         WHERE bl.token = ? AND bl.revoked_at IS NULL
           AND (bl.expires_at IS NULL OR bl.expires_at > NOW())
           AND m.status = ? AND m.deleted_at IS NULL",
        [$token, STATUS_OPEN]
    );
    return $row ?: null;
}

function recordBoardLinkView(int $linkId): void {
    dbExecute(
        "UPDATE mission_board_links SET last_viewed_at = NOW(), view_count = view_count + 1 WHERE id = ?",
        [$linkId]
    );
}

/**
 * The latest position per volunteer actually taking part in the Action Room,
 * trimmed to what a shared screen should show. Mirrors the performant
 * MAX(id) GROUP BY shape war-room.php's $loadPins uses (see its own docblock
 * for why: a window function over every historic ping does not scale),
 * without the moving/heading/vitals machinery a kiosk screen does not need.
 */
function loadBoardViewPins(int $missionId): array {
    $participantIds = actionRoomParticipantIds($missionId);
    if (!$participantIds) {
        return [];
    }
    $shiftRows = dbFetchAll("SELECT id FROM shifts WHERE mission_id = ?", [$missionId]);
    $shiftBinds = array_map('intval', array_column($shiftRows, 'id')) ?: [0];
    $placeholders = implode(',', array_fill(0, count($shiftBinds), '?'));

    $rows = dbFetchAll(
        "SELECT vp.user_id, vp.lat, vp.lng, vp.accuracy_meters, vp.created_at, u.name,
                mt.id AS team_id, mt.color AS team_color, mt.codename, mt.team_number
         FROM (SELECT user_id, shift_id, MAX(id) AS max_id
                 FROM volunteer_pings
                WHERE shift_id IN ({$placeholders})
                GROUP BY " . pingLatestGroupBy() . ") sel
         JOIN volunteer_pings vp ON vp.id = sel.max_id
         JOIN shifts s ON s.id = vp.shift_id
         JOIN users u ON u.id = vp.user_id
         LEFT JOIN mission_team_members mtm ON mtm.user_id = vp.user_id AND mtm.mission_id = s.mission_id
         LEFT JOIN mission_teams mt ON mt.id = mtm.team_id
         ORDER BY vp.created_at DESC",
        $shiftBinds
    );

    $staleAfter = warRoomPingStaleThresholdSeconds();
    $pins = [];
    foreach ($rows as $r) {
        if (!in_array((int) $r['user_id'], $participantIds, true)) {
            continue;
        }
        $ageSeconds = time() - strtotime($r['created_at']);
        $pins[] = [
            'user_id'     => (int) $r['user_id'],
            'lat'         => (float) $r['lat'],
            'lng'         => (float) $r['lng'],
            'accuracy_m'  => $r['accuracy_meters'] !== null ? (int) round($r['accuracy_meters']) : null,
            'name'        => (string) $r['name'],
            'team_id'     => $r['team_id'] !== null ? (int) $r['team_id'] : null,
            'team_color'  => $r['team_color'] ?: null,
            'team_label'  => $r['codename'] !== null ? teamLabel($r['codename'], $r['team_number']) : null,
            'time'        => $r['created_at'],
            'is_stale'    => $ageSeconds > $staleAfter,
        ];
    }
    return $pins;
}

/**
 * One card per team: colour, how many of its members currently have a pin
 * (vs. its total roster), and a traffic-light tone from the worst pin in it.
 * Deliberately no "current order" text - that needs the same order-target
 * machinery war-room.php builds inline, and the map itself already carries
 * the one thing this screen exists for: where everybody is.
 */
function loadBoardViewTeams(int $missionId, array $pins): array {
    $teams = dbFetchAll(
        "SELECT id, codename, team_number, color FROM mission_teams WHERE mission_id = ? ORDER BY team_number, codename",
        [$missionId]
    );
    $memberCounts = dbFetchAll(
        "SELECT mtm.team_id, COUNT(*) AS c
         FROM mission_team_members mtm
         WHERE mtm.mission_id = ?
         GROUP BY mtm.team_id",
        [$missionId]
    );
    $memberCountByTeam = [];
    foreach ($memberCounts as $r) {
        $memberCountByTeam[(int) $r['team_id']] = (int) $r['c'];
    }

    $pinsByTeam = [];
    foreach ($pins as $p) {
        if ($p['team_id'] !== null) {
            $pinsByTeam[$p['team_id']][] = $p;
        }
    }

    $out = [];
    foreach ($teams as $t) {
        $teamId = (int) $t['id'];
        $teamPins = $pinsByTeam[$teamId] ?? [];
        $anyStale = false;
        foreach ($teamPins as $p) {
            if ($p['is_stale']) {
                $anyStale = true;
            }
        }
        $out[] = [
            'id'            => $teamId,
            'label'         => teamLabel($t['codename'], $t['team_number']),
            'color'         => $t['color'] ?: '#6c757d',
            'gps_on'        => count($teamPins),
            'members_total' => $memberCountByTeam[$teamId] ?? 0,
            'tone'          => empty($teamPins) ? 'none' : ($anyStale ? 'warn' : 'ok'),
        ];
    }
    return $out;
}
