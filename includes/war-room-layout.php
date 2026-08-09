<?php
/**
 * VolunteerOps - War Room card layout (drag-and-drop + show/hide) persistence
 *
 * Required directly by war-room.php and api-war-room-layout.php only (not
 * required globally from bootstrap.php) — it defines the card whitelist,
 * default order, and hidden-card reconciliation shared by both, so the two
 * never drift apart.
 */

if (!defined('VOLUNTEEROPS')) {
    die('Direct access not permitted');
}

/**
 * The 35 admin-desktop-view cards, in default reading order, split into the
 * two drag zones (main/left column, sidebar/right column). This list IS the
 * server-side whitelist — api-war-room-layout.php rejects any card id not
 * present here.
 */
function warRoomDefaultLayout(): array {
    return [
        'main' => [
            'mapCard', 'weatherCard', 'missingPersonCard', 'trailEventsCard', 'shortageFormCard', 'incidentFormCard',
            'shortageListCard', 'incidentsListCard', 'poiListCard', 'sectorsListCard', 'teamsCard',
            'participantsCard', 'requestLocationCard', 'requestPhotoCard',
            'requestVideoCard', 'requestTaskCard', 'activityCard', 'chatCard',
        ],
        'sidebar' => [
            'myLocationCard', 'mediaCard', 'broadcastPhotoCard', 'nearbyTeamsCard', 'myRouteCard',
            'myTasksCard', 'mySectorsCard', 'shiftsCard', 'sosAlertsCard', 'broadcastCard',
            'endMissionCard', 'dispatchCard', 'sectorsCard', 'restrictedAreasCard', 'routeOrderCard',
            'teamRoutesAdminCard', 'briefingCard', 'missionMgmtCard',
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
function warRoomRenderedCardIds(bool $isApprovedParticipant, bool $hasTeams, bool $isSpecialMission = false, bool $isMissingPersonMission = false, bool $weatherEnabled = false): array {
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
    if (!$isSpecialMission) {
        $excluded[] = 'briefingCard';
    }
    if (!$isMissingPersonMission) {
        $excluded[] = 'missingPersonCard';
    }
    if (!$weatherEnabled) {
        $excluded[] = 'weatherCard';
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
 *  - 'hidden' (which cards the user has toggled off, defaulting to none) is
 *    reconciled the same way but is orthogonal to zone/order: a hidden id
 *    stays exactly where it is in main/sidebar (so un-hiding restores its
 *    position) and is simply intersected against what renders today, with
 *    no "append missing ids" step — an id absent from 'hidden' is visible
 *    by default, which is already what we want.
 */
function getWarRoomLayoutForUser(int $userId, bool $isApprovedParticipant, bool $hasTeams, bool $isSpecialMission = false, bool $isMissingPersonMission = false, bool $weatherEnabled = false): array {
    $rendered = warRoomRenderedCardIds($isApprovedParticipant, $hasTeams, $isSpecialMission, $isMissingPersonMission, $weatherEnabled);
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

    $savedHidden = is_array($saved['hidden'] ?? null) ? $saved['hidden'] : [];
    $result['hidden'] = array_values(array_intersect(array_unique($savedHidden), $allRenderedIds));

    return $result;
}

/**
 * Short plain-text label per card, for the show/hide checklist modal —
 * distinct from each card's own live header title (t()'d directly in
 * war-room.php) because a couple of those titles are unsuitable for a
 * checklist: participantsCard's interpolates a live {count}, which would
 * either show a stale number or require threading one in for no reason.
 * Resolved server-side (not just key names) since this gets json_encode'd
 * straight to JS, which can't call t() itself.
 */
function warRoomCardLabels(): array {
    return [
        'mapCard' => t('map.title'),
        'weatherCard' => t('weather.card_title'),
        'missingPersonCard' => t('missing_person.card_title'),
        'trailEventsCard' => t('trail.events_title'),
        'shortageFormCard' => t('shortage.card_title'),
        'incidentFormCard' => t('incident.card_title'),
        'shortageListCard' => t('shortage.list_panel_title'),
        'incidentsListCard' => t('incident.list_panel_title'),
        'poiListCard' => t('poi.list_panel_title'),
        'sectorsListCard' => t('sector.list_panel_title'),
        'teamsCard' => t('teams.panel_title'),
        'participantsCard' => t('card_visibility.label_participants'),
        'requestLocationCard' => t('request.location.card_title'),
        'requestPhotoCard' => t('request.photo.card_title'),
        'requestVideoCard' => t('request.video.card_title'),
        'requestTaskCard' => t('request.task.card_title'),
        'activityCard' => t('activity.panel_title'),
        'chatCard' => t('chat.panel_title'),
        'mediaCard' => t('media.panel_title'),
        'myLocationCard' => t('myping.panel_title'),
        'broadcastPhotoCard' => t('broadcast_photo.card_title'),
        'nearbyTeamsCard' => t('nearby.panel_title'),
        'myRouteCard' => t('route.my_panel_title'),
        'myTasksCard' => t('mytasks.panel_title'),
        'mySectorsCard' => t('sector.my_panel_title'),
        'shiftsCard' => t('shifts.panel_title'),
        'sosAlertsCard' => t('sos.panel_title'),
        'broadcastCard' => t('global_message.card_title'),
        'endMissionCard' => t('end_mission_broadcast.card_title'),
        'dispatchCard' => t('dispatch.card_title'),
        'sectorsCard' => t('sector.card_title'),
        'restrictedAreasCard' => t('restricted_area.card_title'),
        'routeOrderCard' => t('route.card_title'),
        'teamRoutesAdminCard' => t('route.admin_panel_title'),
        'briefingCard' => t('briefing.card_title'),
        'missionMgmtCard' => t('admin.mission_mgmt_title'),
    ];
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
