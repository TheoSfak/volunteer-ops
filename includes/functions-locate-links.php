<?php
/**
 * VolunteerOps - Locate Link functions (v3.499.0)
 * A one-use consent link sent to a missing person's or a caller's own phone
 * (locate.php): their browser's geolocation drops a pin on the mission map.
 * Same idiom as missions.visitor_join_token / mission_teams.briefing_token -
 * the token IS the access control, checked at read time against the mission
 * being open and the link not expired/revoked, with no distinction between
 * "never existed" and "no longer valid" on the public side.
 */

if (!defined('VOLUNTEEROPS')) {
    die('Direct access not permitted');
}

/** Every link for a mission, newest first, for the command-staff management list. */
function loadMissionLocateLinks(int $missionId): array {
    $rows = dbFetchAll(
        "SELECT ll.*, u.name AS created_by_name
         FROM mission_locate_links ll
         LEFT JOIN users u ON u.id = ll.created_by
         WHERE ll.mission_id = ?
         ORDER BY ll.id DESC",
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
            // Built server-side with BASE_URL, same as the 'create' action -
            // never reconstructed client-side from location.origin, which
            // breaks the moment the app isn't served from the domain root
            // (e.g. this local install under /volunteerops/).
            'url'             => rtrim(BASE_URL, '/') . '/locate.php?token=' . $r['token'],
            'label'           => $r['label'] !== null && $r['label'] !== '' ? (string) $r['label'] : null,
            'created_by_name' => $r['created_by_name'] ?? null,
            'created_at'      => $r['created_at'],
            'expires_at'      => $r['expires_at'],
            'status'          => $status,
            'consented_at'    => $r['consented_at'],
            'last_lat'        => $r['last_lat'] !== null ? (float) $r['last_lat'] : null,
            'last_lng'        => $r['last_lng'] !== null ? (float) $r['last_lng'] : null,
            'last_accuracy_m' => $r['last_accuracy_m'] !== null ? (int) $r['last_accuracy_m'] : null,
            'last_updated_at' => $r['last_updated_at'],
            'update_count'    => (int) $r['update_count'],
        ];
    }, $rows);
}

/**
 * Only the links a map should actually draw a pin for right now: active,
 * consented (the phone said yes) and with a position on file. Polled
 * separately from the main war-room.php poll (its own short interval, command
 * staff only) rather than threading a rarely-used, privacy-sensitive channel
 * into that already-large payload.
 */
function loadActiveLocateLinkPositions(int $missionId): array {
    $rows = dbFetchAll(
        "SELECT id, token, label, last_lat, last_lng, last_accuracy_m, last_updated_at
         FROM mission_locate_links
         WHERE mission_id = ? AND revoked_at IS NULL AND consented_at IS NOT NULL
           AND last_lat IS NOT NULL
           AND (expires_at IS NULL OR expires_at > NOW())
         ORDER BY id DESC",
        [$missionId]
    );
    return array_map(static function (array $r) {
        return [
            'id'          => (int) $r['id'],
            'label'       => $r['label'] !== null && $r['label'] !== '' ? (string) $r['label'] : null,
            'lat'         => (float) $r['last_lat'],
            'lng'         => (float) $r['last_lng'],
            'accuracy_m'  => $r['last_accuracy_m'] !== null ? (int) $r['last_accuracy_m'] : null,
            'updated_at'  => $r['last_updated_at'],
        ];
    }, $rows);
}

/** Random, unguessable, and distinct per feature so a leaked link can't be confused with a board-view one. */
function generateLocateLinkToken(): string {
    return bin2hex(random_bytes(32));
}

/** @return array The new row's id and token. */
function createMissionLocateLink(int $missionId, ?string $label, int $createdBy, ?int $durationHours): array {
    $token = generateLocateLinkToken();
    $expiresAt = $durationHours !== null ? date('Y-m-d H:i:s', time() + $durationHours * 3600) : null;
    $id = dbInsert(
        "INSERT INTO mission_locate_links (mission_id, token, label, created_by, created_at, expires_at)
         VALUES (?, ?, ?, ?, NOW(), ?)",
        [$missionId, $token, $label !== null && $label !== '' ? $label : null, $createdBy, $expiresAt]
    );
    return ['id' => (int) $id, 'token' => $token];
}

/** False when the link does not belong to this mission (defends against a forged link_id from another mission). */
function revokeMissionLocateLink(int $linkId, int $missionId): bool {
    $affected = dbExecute(
        "UPDATE mission_locate_links SET revoked_at = NOW() WHERE id = ? AND mission_id = ? AND revoked_at IS NULL",
        [$linkId, $missionId]
    );
    return $affected > 0;
}

/**
 * The public-page lookup (locate.php): the mission must still be open, same
 * self-expiry idiom as visitor_join_token/briefing_token. Deliberately a
 * single query with no separate "why not" branch - see the file docblock.
 */
function findActiveMissionLocateLink(string $token): ?array {
    if ($token === '') {
        return null;
    }
    $row = dbFetchOne(
        "SELECT ll.id, ll.mission_id, ll.consented_at
         FROM mission_locate_links ll
         JOIN missions m ON m.id = ll.mission_id
         WHERE ll.token = ? AND ll.revoked_at IS NULL
           AND (ll.expires_at IS NULL OR ll.expires_at > NOW())
           AND m.status = ? AND m.deleted_at IS NULL",
        [$token, STATUS_OPEN]
    );
    return $row ?: null;
}

/** First call per link sets consented_at; every call updates the position and bumps the counter. */
function recordLocateLinkPosition(int $linkId, float $lat, float $lng, ?int $accuracyM): void {
    dbExecute(
        "UPDATE mission_locate_links
         SET last_lat = ?, last_lng = ?, last_accuracy_m = ?, last_updated_at = NOW(),
             update_count = update_count + 1, consented_at = COALESCE(consented_at, NOW())
         WHERE id = ?",
        [$lat, $lng, $accuracyM, $linkId]
    );
}
