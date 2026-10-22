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
 * Bumped whenever the DEFAULT order changes in a way a layout saved earlier
 * should pick up too. Stored in the saved row as 'v' (a row without it is
 * version 1); see warRoomUpgradeSavedLayout() for what each step does.
 */
const WAR_ROOM_LAYOUT_VERSION = 2;

/**
 * The 44 admin-desktop-view cards, in default reading order, split into the
 * two drag zones (main/left column, sidebar/right column). This list IS the
 * server-side whitelist — api-war-room-layout.php rejects any card id not
 * present here.
 *
 * The sidebar leads with the two alert cards (SOS, field voice messages): they
 * used to sit between the shift list and the broadcast form, two and a half
 * screens down, and the end-of-mission card used to sit between the broadcast
 * and dispatch forms although it is used once per mission. The six request
 * cards (location, photo, video, live, general order, voice announcement) moved
 * from the left column to sit with the other order forms: with the "me" cards
 * gone and the rarely used cards folded, the left column was 800px longer than
 * the sidebar, and they are the part of it that is a form rather than a view.
 */
function warRoomDefaultLayout(): array {
    return [
        'main' => [
            'triageCard', 'mapCard', 'weatherCard', 'missingPersonCard', 'trailEventsCard', 'shortageFormCard', 'incidentFormCard',
            'shortageListCard', 'incidentsListCard', 'poiListCard', 'sectorsListCard', 'teamsCard',
            'participantsCard', 'liveStreamsCard', 'activityCard', 'chatCard',
        ],
        'sidebar' => [
            'sosAlertsCard', 'voiceMessagesCard',
            'myLocationCard', 'myLiveCard', 'mediaCard', 'broadcastPhotoCard', 'nearbyTeamsCard', 'restrictedAreaProximityCard', 'myRouteCard',
            'myTasksCard', 'mySectorsCard', 'shiftsCard', 'broadcastCard',
            'dispatchCard', 'sectorsCard', 'restrictedAreasCard', 'routeOrderCard',
            'teamRoutesAdminCard',
            'requestLocationCard', 'requestPhotoCard', 'requestVideoCard', 'requestLiveCard', 'requestTaskCard', 'requestSpeakCard',
            'briefingCard', 'missionVisitorsCard', 'missionMgmtCard', 'endMissionCard',
        ],
    ];
}

/**
 * The cards that describe the viewer as a FIELD RESPONDER: their own location
 * and field-status buttons, their own orders, sectors and route, the teams and
 * restricted areas near them. For an admin who is not on the mission's roster
 * every one of them is empty (the location card says "you have no shift") and
 * the first of them sat at the very top of the sidebar, so they are not
 * rendered at all in that case — see $showPersonalCards in war-room.php.
 */
function warRoomPersonalCardIds(): array {
    return [
        'myLocationCard', 'myLiveCard', 'nearbyTeamsCard', 'restrictedAreaProximityCard',
        'myRouteCard', 'myTasksCard', 'mySectorsCard',
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
function warRoomRenderedCardIds(bool $isApprovedParticipant, bool $hasTeams, bool $isSpecialMission = false, bool $isMissingPersonMission = false, bool $weatherEnabled = false, bool $liveEnabled = false, bool $showPersonalCards = true): array {
    $default = warRoomDefaultLayout();
    $excluded = [];
    if (!$showPersonalCards) {
        $excluded = array_merge($excluded, warRoomPersonalCardIds());
    }
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
    // No LiveKit credentials configured -> the whole feature is absent rather
    // than present-but-broken, the same treatment weatherCard gets without an
    // API key. Also stops a saved layout from pinning a card that cannot work.
    if (!$liveEnabled) {
        $excluded[] = 'requestLiveCard';
        $excluded[] = 'liveStreamsCard';
        $excluded[] = 'myLiveCard';
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
function getWarRoomLayoutForUser(int $userId, bool $isApprovedParticipant, bool $hasTeams, bool $isSpecialMission = false, bool $isMissingPersonMission = false, bool $weatherEnabled = false, bool $liveEnabled = false, bool $showPersonalCards = true): array {
    $rendered = warRoomRenderedCardIds($isApprovedParticipant, $hasTeams, $isSpecialMission, $isMissingPersonMission, $weatherEnabled, $liveEnabled, $showPersonalCards);
    $allRenderedIds = array_merge($rendered['main'], $rendered['sidebar']);

    $saved = [];
    $row = dbFetchOne("SELECT layout_json FROM war_room_layouts WHERE user_id = ?", [$userId]);
    if ($row) {
        $decoded = json_decode($row['layout_json'], true);
        if (is_array($decoded)) {
            $saved = warRoomUpgradeSavedLayout($decoded);
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
    // (brand-new admin, or a card added after they last customized) goes to
    // its default zone, just before the first card that follows it in the
    // default order and is already there — so a new card lands where it
    // belongs instead of at the bottom of the column. That matters for
    // triageCard: it is added above the map, and a Μαζικό Συμβάν card that
    // turned up under twenty others on every coordinator's saved layout would
    // be found last, which is the one thing it must not be. With no such
    // successor in the zone it is appended, as before.
    foreach (['main', 'sidebar'] as $zone) {
        $defaultOrder = $rendered[$zone];
        foreach ($defaultOrder as $pos => $id) {
            if (isset($claimed[$id])) {
                continue;
            }
            $insertAt = count($result[$zone]);
            for ($next = $pos + 1; $next < count($defaultOrder); $next++) {
                $at = array_search($defaultOrder[$next], $result[$zone], true);
                if ($at !== false) {
                    $insertAt = $at;
                    break;
                }
            }
            array_splice($result[$zone], $insertAt, 0, [$id]);
            $claimed[$id] = true;
        }
    }

    $savedHidden = is_array($saved['hidden'] ?? null) ? $saved['hidden'] : [];
    $result['hidden'] = array_values(array_intersect(array_unique($savedHidden), $allRenderedIds));

    // 'half' mirrors 'hidden' exactly: a flat subset marker, orthogonal to
    // zone and order, intersected against what renders today with no
    // append-the-missing step (absent from the set = full width, which is
    // already the wanted default). Absent entirely from a layout saved
    // before this feature shipped, so those admins keep every card full
    // width and nothing about their arrangement changes.
    $savedHalf = is_array($saved['half'] ?? null) ? $saved['half'] : [];
    $result['half'] = array_values(array_intersect(array_unique($savedHalf), $allRenderedIds));

    return $result;
}

/**
 * Brings a layout saved under an older default up to the current one, once.
 * A saved layout is the user's own arrangement and normally wins over any
 * default, which is exactly why a default that moves something never reached
 * anyone who had ever dragged a card: they kept the old order for good. Each
 * step here is a deliberate exception, applied on read until the user next
 * changes their layout (the save endpoint stamps WAR_ROOM_LAYOUT_VERSION, and
 * from then on the arrangement is theirs again, untouched).
 *
 *  v2: the SOS alerts card and the field voice messages card lead the sidebar.
 *      Only when SOS is in the sidebar: someone who moved it to the left
 *      column did that on purpose. And the six request cards that are still
 *      in the left column move to the sidebar, after the last order form that
 *      is there (the end of the sidebar if none is); one that was already
 *      dragged into the sidebar stays where it is.
 */
function warRoomUpgradeSavedLayout(array $saved): array {
    if ((int)($saved['v'] ?? 1) >= WAR_ROOM_LAYOUT_VERSION) {
        return $saved;
    }
    $sidebar = is_array($saved['sidebar'] ?? null) ? array_values($saved['sidebar']) : [];
    if (in_array('sosAlertsCard', $sidebar, true)) {
        $alerts = ['sosAlertsCard', 'voiceMessagesCard'];
        $sidebar = array_merge($alerts, array_values(array_diff($sidebar, $alerts)));
        $saved['sidebar'] = $sidebar;
    }

    $main = is_array($saved['main'] ?? null) ? array_values($saved['main']) : [];
    $requests = ['requestLocationCard', 'requestPhotoCard', 'requestVideoCard', 'requestLiveCard', 'requestTaskCard', 'requestSpeakCard'];
    $moving = array_values(array_intersect($main, $requests));
    if ($moving) {
        $orderForms = ['dispatchCard', 'sectorsCard', 'restrictedAreasCard', 'routeOrderCard', 'teamRoutesAdminCard', 'broadcastCard'];
        $after = count($sidebar);
        foreach ($sidebar as $i => $id) {
            if (in_array($id, $orderForms, true)) {
                $after = $i + 1;
            }
        }
        array_splice($sidebar, $after, 0, $moving);
        $saved['main'] = array_values(array_diff($main, $moving));
        $saved['sidebar'] = $sidebar;
    }
    return $saved;
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
        'triageCard' => t('triage.card_title'),
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
        'liveStreamsCard' => t('live.card_title'),
        'requestLocationCard' => t('request.location.card_title'),
        'requestPhotoCard' => t('request.photo.card_title'),
        'requestVideoCard' => t('request.video.card_title'),
        'requestLiveCard' => t('request.live.card_title'),
        'requestTaskCard' => t('request.task.card_title'),
        'requestSpeakCard' => t('request.speak.card_title'),
        'activityCard' => t('activity.panel_title'),
        'chatCard' => t('chat.panel_title'),
        'mediaCard' => t('media.panel_title'),
        'myLocationCard' => t('myping.panel_title'),
        'myLiveCard' => t('mylive.panel_title'),
        'broadcastPhotoCard' => t('broadcast_photo.card_title'),
        'nearbyTeamsCard' => t('nearby.panel_title'),
        'restrictedAreaProximityCard' => t('restricted_area.proximity_card_title'),
        'myRouteCard' => t('route.my_panel_title'),
        'myTasksCard' => t('mytasks.panel_title'),
        'mySectorsCard' => t('sector.my_panel_title'),
        'shiftsCard' => t('shifts.panel_title'),
        'sosAlertsCard' => t('sos.panel_title'),
        'voiceMessagesCard' => t('voice.staff_card_title'),
        'broadcastCard' => t('global_message.card_title'),
        'endMissionCard' => t('end_mission_broadcast.card_title'),
        'dispatchCard' => t('dispatch.card_title'),
        'sectorsCard' => t('sector.card_title'),
        'restrictedAreasCard' => t('restricted_area.card_title'),
        'routeOrderCard' => t('route.card_title'),
        'teamRoutesAdminCard' => t('route.admin_panel_title'),
        'briefingCard' => t('briefing.card_title'),
        'missionVisitorsCard' => t('visitor.card_title'),
        'missionMgmtCard' => t('admin.mission_mgmt_title'),
    ];
}

/**
 * Upserts $userId's chosen layout. Caller is responsible for validating
 * $layout beforehand (api-war-room-layout.php whitelists ids/shape before
 * calling this) — this function just persists whatever it's given.
 */
function saveWarRoomLayoutForUser(int $userId, array $layout): void {
    $layout['v'] = WAR_ROOM_LAYOUT_VERSION;
    dbExecute(
        "INSERT INTO war_room_layouts (user_id, layout_json, updated_at) VALUES (?, ?, NOW())
         ON DUPLICATE KEY UPDATE layout_json = VALUES(layout_json), updated_at = NOW()",
        [$userId, json_encode($layout)]
    );
}
