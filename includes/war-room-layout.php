<?php
/**
 * VolunteerOps - War Room card layout (drag-and-drop) persistence
 *
 * Required directly by war-room.php and api-war-room-layout.php only (not
 * required globally from bootstrap.php) — it defines the card whitelist and
 * default order shared by both, so the two never drift apart.
 */

if (!defined('VOLUNTEEROPS')) {
    die('Direct access not permitted');
}

/**
 * The 28 admin-desktop-view cards, in default reading order, split into the
 * two drag zones (main/left column, sidebar/right column). This list IS the
 * server-side whitelist — api-war-room-layout.php rejects any card id not
 * present here.
 */
function warRoomDefaultLayout(): array {
    return [
        'main' => [
            'mapCard', 'trailEventsCard', 'shortageFormCard', 'incidentFormCard',
            'shortageListCard', 'incidentsListCard', 'poiListCard', 'teamsCard',
            'participantsCard', 'requestLocationCard', 'requestPhotoCard',
            'requestVideoCard', 'requestTaskCard', 'activityCard', 'chatCard',
        ],
        'sidebar' => [
            'mediaCard', 'myLocationCard', 'nearbyTeamsCard', 'myRouteCard',
            'myTasksCard', 'shiftsCard', 'sosAlertsCard', 'broadcastCard',
            'endMissionCard', 'dispatchCard', 'routeOrderCard',
            'teamRoutesAdminCard', 'missionMgmtCard',
        ],
    ];
}

function warRoomAllCardIds(): array {
    $default = warRoomDefaultLayout();
    return array_merge($default['main'], $default['sidebar']);
}

/**
 * Which of the default cards actually render for this request — mirrors the
 * PHP conditionals already in war-room.php (report forms need an approved
 * participant, the two team-routing cards need at least one team to exist).
 */
function warRoomRenderedCardIds(bool $isApprovedParticipant, bool $hasTeams): array {
    $default = warRoomDefaultLayout();
    $excluded = [];
    if (!$isApprovedParticipant) {
        $excluded[] = 'shortageFormCard';
        $excluded[] = 'incidentFormCard';
    }
    if (!$hasTeams) {
        $excluded[] = 'routeOrderCard';
        $excluded[] = 'teamRoutesAdminCard';
    }
    return [
        'main'    => array_values(array_diff($default['main'], $excluded)),
        'sidebar' => array_values(array_diff($default['sidebar'], $excluded)),
    ];
}

/**
 * Loads $userId's saved layout (if any) and reconciles it against what will
 * actually render this request:
 *  - no saved row yet -> default order, unmodified
 *  - saved id that doesn't render now (e.g. no teams yet) -> silently dropped
 *  - card renders now but is missing from an older saved order (new card, or
 *    first customization) -> appended at the end of its zone
 */
function getWarRoomLayoutForUser(int $userId, bool $isApprovedParticipant, bool $hasTeams): array {
    $rendered = warRoomRenderedCardIds($isApprovedParticipant, $hasTeams);
    $allRenderedIds = array_merge($rendered['main'], $rendered['sidebar']);

    $saved = [];
    $row = dbFetchOne("SELECT layout_json FROM war_room_layouts WHERE user_id = ?", [$userId]);
    if ($row) {
        $decoded = json_decode($row['layout_json'], true);
        if (is_array($decoded)) {
            $saved = $decoded;
        }
    }

    // A saved id is valid in EITHER zone — the admin may have dragged it
    // across from its default zone, and that's the whole point of the
    // feature. $claimed guards against a corrupt/old save listing the same
    // id in both zones (first zone processed wins).
    $claimed = [];
    $result = [];
    foreach (['main', 'sidebar'] as $zone) {
        $savedZone = is_array($saved[$zone] ?? null) ? $saved[$zone] : [];
        $zoneIds = [];
        foreach ($savedZone as $id) {
            if (is_string($id) && in_array($id, $allRenderedIds, true) && !isset($claimed[$id])) {
                $zoneIds[] = $id;
                $claimed[$id] = true;
            }
        }
        $result[$zone] = $zoneIds;
    }
    // Anything that renders today but the saved layout never placed at all
    // (brand-new admin, or a card added after they last customized) gets
    // appended to its default zone, in default relative order.
    foreach (['main', 'sidebar'] as $zone) {
        foreach ($rendered[$zone] as $id) {
            if (!isset($claimed[$id])) {
                $result[$zone][] = $id;
                $claimed[$id] = true;
            }
        }
    }
    return $result;
}

/**
 * Upserts $userId's chosen layout. Caller is responsible for validating
 * $layout beforehand (api-war-room-layout.php whitelists ids/shape before
 * calling this) — this function just persists whatever it's given.
 */
function saveWarRoomLayoutForUser(int $userId, array $layout): void {
    dbExecute(
        "INSERT INTO war_room_layouts (user_id, layout_json, updated_at) VALUES (?, ?, NOW())
         ON DUPLICATE KEY UPDATE layout_json = VALUES(layout_json), updated_at = NOW()",
        [$userId, json_encode($layout)]
    );
}
