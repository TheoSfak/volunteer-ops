<?php
/**
 * VolunteerOps - Scenario (v3.452.0).
 *
 * An exercise (or a drill, or an operation planned in advance) is a list of orders the person running the
 * Action Room already knows will go out, in what order, to whom. A SCENARIO writes them down beforehand, in
 * PHASES (phase 1: three points; phase 2: a restricted area and five sectors), and when the exercise is
 * running a manual press of play on a phase sends every order in it. Nothing is timed, nothing pauses or
 * fast-forwards, and nothing is made up: what goes out is exactly what a coordinator would have sent by hand.
 *
 * WHERE A STAGED ORDER LIVES. In its OWN tables (mission_scenario_phases, mission_scenario_items), as the
 * order's JSON, and nowhere else. It is deliberately not a `staged` flag on mission_dispatch_points: every
 * reader of the live orders - the 5-second poll, the reports, the assistant, the team board, the statistics -
 * would have to remember to skip a flagged row, and one that forgot would show the plan to the field. As it is,
 * none of them can see a staged order because none of them reads these tables (a test lists the only files
 * that do). Command only, always: mission-scenario.php refuses anyone who cannot manage the Action Room.
 *
 * SENDING IS THE ORDINARY WAY. Play hands each item to createMissionDispatch() - the function the order
 * window's «Αποστολή» uses - so the checks, the row, the audit entry and the people told are the same ones.
 * The same function checks an order when it is STAGED (prepareMissionDispatch()), so an order that could never
 * be sent is refused when it is written down, not in the middle of the exercise.
 *
 * WHO IT GOES TO. A real team of this mission, or «όλες οι ομάδες», and the payload says which in words
 * (audience team|all), never by leaving the team out: for a dispatch no team means EVERY team and alarms
 * everybody, so an order whose team is gone, or was never named, FAILS at play and is reported; it is never
 * quietly widened to everyone.
 *
 * SLOTS (v3.453.0). Before the teams exist the plan can still name them: a slot («Ομάδα Α») is a name of the
 * scenario that stands for a team, and an order can go to a slot (audience 'slot'). Which real team a slot is
 * is chosen by command, in the card or in the dialog that play opens for the slots a phase still lacks, and is
 * kept (mission_scenario_slots.team_id) so the next phase needs no question. A slot with no team is NEVER an
 * order to everybody: play refuses a phase that uses one before it claims it, and the order itself fails if it
 * is ever asked to go (scenarioDispatchInputFromPayload() returns null for it, as for any team that is not there).
 *
 * SECTORS (v3.454.0). A staged order can also be a search sector (kind 'sector'): a polygon with a label, cut from
 * a search area that already exists, for a real team or a slot. Play hands it to createMissionSector() - the
 * function the sector window's save uses - so it is born exactly as a hand-drawn one is (assigned, status log,
 * audit entry, the team told), and prepareMissionSector() checks it when it is staged. Two differences from a
 * point or an area, both on purpose: a sector has no «όλες οι ομάδες» (an unassigned sector tells nobody and is
 * not an order, so staging one without a team or slot is refused), and it needs its search area at play - if
 * the area has been deleted since, the order FAILS with that reason and creates nothing, rather than guessing
 * an area. scenarioSectorInputFromPayload() is its own payload-to-form function with the same rule as the
 * dispatch one: a team that is missing, or a slot that stands for none, is null, never "no team".
 *
 * ROUTES (v3.455.0). A staged order can also be a route (kind 'route'): a patrol of one to thirty waypoints, each with its
 * instructions, dwell time and the photo/video/note it expects, for a real team or a slot. Play hands it to
 * createMissionRoute() - the function the route window's send uses - so it is born exactly as a hand-drawn one is (the
 * route, its members, the order behind it, every waypoint and its progress row, the audit entry, the team told), and
 * prepareMissionRoute() checks it when it is staged. A route has no «όλες οι ομάδες» either: with no team the route
 * window makes a route of people picked across teams, which a plan cannot name, so scenarioRouteInputFromPayload()
 * returns null for a team that is missing, a slot that stands for none and the word 'all' - never "no team". Two
 * things about a route are only known on the day: who is on the team (the people who take part in the Action Room) and
 * where the team is (the optional «επιστροφή στην αφετηρία» waypoint is its last ping). Both are asked when the route
 * is SENT; the card says beforehand when a team has nobody in the Action Room (team_empty) and play warns.
 *
 * GENERAL ORDERS (v3.457.0). A staged order can also be an order to PEOPLE (kind 'order'): a task, a spoken announcement, a
 * broadcast message, or a request for a location, a photo or a video, for a real team, a slot, or - the one place a plan may
 * say «όλοι» - every person on duty when it is sent. Play hands it to sendMissionGeneralOrder() - the function the Action
 * Room's own buttons now send through - so the order, its recipients, the audit entry and the notifications are the ones a
 * coordinator's button makes. Who is on duty is only known on the day, so the recipients are chosen at SEND
 * (scenarioOrderRecipients(): the team's members among those on duty, or all of them) and an order nobody would receive
 * FAILS, with a retry, rather than being recorded as sent. scenarioOrderInputFromPayload() has the same never-widened rule
 * as the others: 'all' only when the payload says so in words; a missing team or a slot that stands for none is null. Its
 * progress is read from the live order (everyone has done what it asked, or - for a message, which nothing can see being
 * read - has acknowledged it) and it is never taken back: «Ανάκληση φάσης» leaves it, as a delivered message cannot be
 * recalled by hand either.
 *
 * RESTRICTED AREAS (v3.458.0). A staged order can also be a restricted area (kind 'restricted'): a danger zone, an edge such
 * as a cliff, or a forbidden place, drawn as a polygon or a line, which raises the breach alarm for anyone whose GPS enters
 * it. Play hands it to createMissionRestrictedArea() - the function the restricted-area window's save uses - so the zone,
 * its audit entry and the alarm it arms are the ones a hand-drawn zone has, and prepareMissionRestrictedArea() checks it
 * when it is staged. A zone has NO team, no slot and no «όλες οι ομάδες»: it is for everybody who is in the field, so there
 * is nothing to widen (its audience reads 'none'). It is never finished - it stands until someone takes it away - so it
 * does not count in a phase's progress; the card reads it as «Σε ισχύ», and «Ανάκληση φάσης» deletes it through
 * deleteMissionRestrictedArea() (open breaches of it are resolved, exactly as by hand).
 *
 * SEARCH AREAS (v3.459.0). A staged order can also be a search area (kind 'area', type 'search_area'): the outer polygon a search
 * zone is later divided into sectors from. Play hands it to createMissionSearchArea() - the function the search-area window's
 * save uses - and prepareMissionSearchArea() checks it when it is staged. An area has no team, slot or «όλες οι ομάδες»
 * (audience 'none'); it is never finished, so it does not count in a phase's progress and the card reads it as «Σε ισχύ».
 * What an area is FOR is the sectors on it, and that is why it is in a plan at all: a sector can be written down against an
 * area that does not exist yet (payload area_item_id = the plan's own item, instead of area_id) and play makes the area first.
 * That reference is the one place an item of the plan depends on another, so it has rules: the area has to be in the same
 * phase as the sector or an earlier one (items play in order); at play the sector is cut from the live area the item became
 * (scenarioResolveStagedSectorArea()) and, if the area was taken off the plan, was not sent yet or failed to be made, the sector
 * FAILS with that reason - it is never cut from another area; and a staged area that unsent sectors still name cannot be taken
 * off the plan, nor can the phase that holds it (deleteScenarioItem(), deleteScenarioPhase()). Taking a phase back never
 * deletes an area, because deleting it deletes its sectors: the sectors are taken off their teams and the area stays on the map.
 *
 * SECTOR BUILDINGS (v3.460.0). A staged order can also be a building of a search sector (kind 'building', type 'building'): a
 * point with a name and the number of floors above the ground, which the team that searches the sector clears floor by floor.
 * Play hands it to createMissionSectorBuilding() - the function the building popup's save uses - so the building, its floors
 * (the ground floor and one row for each above it, all required) and its audit entry are those of a building recorded by hand, and
 * prepareMissionSectorBuilding() checks it when it is staged, including that the point stands in the sector (within the
 * tolerance). A building has no team, slot or «όλες οι ομάδες» (audience 'none'): the sector's team searches it. Like a search
 * area it is never finished, so it does not count in a phase's progress and the card reads it as «Σε ισχύ». Like a sector in a
 * staged area it depends on another item of the plan, the sector: it names the sector's item (payload sector_item_id) instead of a
 * sector that exists (sector_id); the sector has to be in the same phase or an earlier one, play makes the sector first, and
 * if the sector was taken off the plan, was not sent yet or failed to be made the building FAILS with that reason
 * (scenarioResolveStagedBuildingSector()) - it is never put in another sector. A staged sector that unsent buildings still name
 * cannot be taken off the plan, nor can the phase that holds it (deleteScenarioItem(), deleteScenarioPhase()). Taking a phase back
 * never deletes a building: the sector is taken off its team and stays on the map with its buildings.
 *
 * HOW FAR A PHASE HAS GOT (v3.456.0). Nothing about progress is stored: scenarioItemLiveStates() reads each sent order
 * from the live order itself (a point is finished when its team pressed «Ολοκληρώθηκε», a route when it is completed,
 * a sector when it is completed; an order that is gone, cancelled or taken off its team is withdrawn), and
 * scenarioPhaseProgress() turns that into «4/6 ✓». The card shows it, and a passive note when a phase is finished
 * and the next one has not gone; the note sends nothing - a phase goes only by its own press of play.
 *
 * TAKING A PHASE BACK (v3.456.0). withdrawScenarioPhase() takes back what a played phase sent and is still under way, by
 * the same steps a coordinator takes by hand: withdrawMissionDispatches() for points and areas, cancelMissionRoute() for a
 * route, assignMissionSector() with no team for a sector (the functions the three endpoints' delete / cancel / assign
 * now use). Finished orders stay. The phase stays played; nothing is ever sent again by a withdrawal.
 *
 * FIRST PRESS WINS. Pressing play claims the phase with one conditional UPDATE (played_at IS NULL); two
 * coordinators pressing at once send it once. An item that fails (the team was deleted since, the shape no
 * longer passes) does not stop the others: it keeps its reason in last_error, and the panel offers to try
 * that one again.
 */

if (!defined('VOLUNTEEROPS')) {
    die('Direct access not permitted');
}

/** A scenario this long is a different tool's job; the limits only keep a runaway client from filling the table. */
const SCENARIO_MAX_PHASES = 20;
const SCENARIO_MAX_ITEMS_PER_PHASE = 60;
const SCENARIO_TITLE_MAX = 120;
const SCENARIO_MAX_SLOTS = 30;
const SCENARIO_SLOT_LABEL_MAX = 80;

/**
 * The whole scenario of a mission for the panel and the map: phases in order, each with its items, the slots, and
 * the mission's teams (so the card can offer them without another request). Plain arrays for json_encode().
 * `n` is the number shown ("Φάση n"): the position in the list, so a deleted phase leaves no gap. An item's
 * team_label is null for «όλες οι ομάδες»; team_missing is true when it names a team that is no longer in the
 * mission (it will fail at play). For an item to a slot, slot_label is the slot, team_id / team_label the team
 * the slot stands for now, and unmapped is true while it stands for none. An item of kind 'sector' has type
 * 'sector', the polygon in geo, and area_id / area_label for the search area it is cut from (area_missing is true
 * when that area has been deleted since: it will fail at play). An item of kind 'route' has type 'route', its
 * waypoints as [[lat, lng], ...] in geo, label = the route's title, point_count, closed_loop, return_to_start and,
 * for a real team, team_empty (true when nobody on that team takes part in the Action Room: it will fail at play).
 */
function loadMissionScenario(int $missionId): array {
    $teamLabels = [];
    $teams = [];
    foreach (dbFetchAll("SELECT id, codename, team_number FROM mission_teams WHERE mission_id = ? ORDER BY team_number, id", [$missionId]) as $team) {
        $teamLabels[(int) $team['id']] = teamLabel($team['codename'], $team['team_number']);
        $teams[] = ['id' => (int) $team['id'], 'label' => $teamLabels[(int) $team['id']]];
    }
    $slots = [];
    $slotById = [];
    foreach (dbFetchAll("SELECT id, label, team_id FROM mission_scenario_slots WHERE mission_id = ? ORDER BY id", [$missionId]) as $row) {
        $teamId = $row['team_id'] !== null ? (int) $row['team_id'] : null;
        $slot = [
            'id'         => (int) $row['id'],
            'label'      => $row['label'],
            'team_id'    => $teamId,
            'team_label' => $teamId !== null ? ($teamLabels[$teamId] ?? null) : null,
        ];
        $slot['mapped'] = $slot['team_label'] !== null;
        $slots[] = $slot;
        $slotById[$slot['id']] = $slot;
    }

    $phases = dbFetchAll(
        "SELECT p.id, p.seq, p.title, p.played_at, p.played_by, u.name AS played_by_name
         FROM mission_scenario_phases p
         LEFT JOIN users u ON u.id = p.played_by
         WHERE p.mission_id = ?
         ORDER BY p.seq, p.id",
        [$missionId]
    );
    if (!$phases) {
        return ['phases' => [], 'slots' => $slots, 'teams' => $teams];
    }
    $items = dbFetchAll(
        "SELECT id, phase_id, seq, kind, payload, sent_at, sent_ref_id, last_error
         FROM mission_scenario_items WHERE mission_id = ? ORDER BY phase_id, seq, id",
        [$missionId]
    );

    // The search areas that exist now, for the sectors that name one.
    $areaLabels = [];
    foreach ($items as $row) {
        if ($row['kind'] === 'sector') {
            foreach (dbFetchAll("SELECT id, label FROM mission_search_areas WHERE mission_id = ?", [$missionId]) as $area) {
                $areaLabels[(int) $area['id']] = (string) $area['label'];
            }
            break;
        }
    }

    // The search areas the scenario itself writes down (v3.459.0), for the sectors that name one instead of an area that
    // exists, and the number of each phase ("Φάση n", the position in the list) to say where such an area is.
    $areaItems = [];
    foreach ($items as $row) {
        if ($row['kind'] === 'area') {
            $areaPayload = json_decode((string) $row['payload'], true);
            $areaItems[(int) $row['id']] = [
                'label'    => is_array($areaPayload) ? (string) ($areaPayload['label'] ?? '') : '',
                'sent'     => $row['sent_at'] !== null && $row['sent_ref_id'] !== null,
                'ref'      => $row['sent_ref_id'] !== null ? (int) $row['sent_ref_id'] : null,
                'phase_id' => (int) $row['phase_id'],
            ];
        }
    }
    $phaseNumbers = [];
    foreach ($phases as $i => $phase) {
        $phaseNumbers[(int) $phase['id']] = $i + 1;
    }

    // The sectors the scenario itself writes down, for the buildings that name one instead of a sector that exists (v3.460.0),
    // and the sectors that exist now, for the buildings that name one of those (or one the scenario has made).
    $sectorItems = [];
    $sectorLabels = [];
    foreach ($items as $row) {
        if ($row['kind'] === 'building') {
            foreach ($items as $sectorRow) {
                if ($sectorRow['kind'] === 'sector') {
                    $sectorPayload = json_decode((string) $sectorRow['payload'], true);
                    $sectorItems[(int) $sectorRow['id']] = [
                        'label'    => is_array($sectorPayload) ? (string) ($sectorPayload['label'] ?? '') : '',
                        'sent'     => $sectorRow['sent_at'] !== null && $sectorRow['sent_ref_id'] !== null,
                        'ref'      => $sectorRow['sent_ref_id'] !== null ? (int) $sectorRow['sent_ref_id'] : null,
                        'phase_id' => (int) $sectorRow['phase_id'],
                    ];
                }
            }
            foreach (dbFetchAll("SELECT id, label FROM mission_search_sectors WHERE mission_id = ?", [$missionId]) as $sector) {
                $sectorLabels[(int) $sector['id']] = (string) $sector['label'];
            }
            break;
        }
    }

    // The people of each team who take part in the Action Room, for the routes that go to a team: a route with
    // nobody to receive it fails at play, and the card says so beforehand.
    $participantsByTeam = [];
    foreach ($items as $row) {
        if ($row['kind'] === 'route') {
            foreach (dbFetchAll(
                "SELECT m.team_id, COUNT(*) AS n FROM mission_team_members m
                 JOIN mission_action_room_participants p ON p.mission_id = m.mission_id AND p.user_id = m.user_id
                 WHERE m.mission_id = ? GROUP BY m.team_id",
                [$missionId]
            ) as $r) {
                $participantsByTeam[(int) $r['team_id']] = (int) $r['n'];
            }
            break;
        }
    }

    // How each order that was sent stands now (v3.456.0): finished, still under way, or taken back.
    $states = scenarioItemLiveStates($missionId, $items);

    $byPhase = [];
    foreach ($items as $row) {
        $payload = json_decode((string) $row['payload'], true);
        if (!is_array($payload)) {
            $payload = [];
        }
        $isSector = $row['kind'] === 'sector';
        $isRoute = $row['kind'] === 'route';
        $isOrder = $row['kind'] === 'order';
        $isRestricted = $row['kind'] === 'restricted';
        $isSearchArea = $row['kind'] === 'area';
        $isBuilding = $row['kind'] === 'building';
        // A building names the sector it stands in: one that exists (sector_id) or one the scenario makes (sector_item_id),
        // which is either still to be made (then the card says in which phase), made already (it is a live sector now), or
        // gone from the plan (the building will fail at play).
        $buildingSectorId = $isBuilding ? (int) ($payload['sector_id'] ?? 0) : 0;
        $buildingSectorItemId = $isBuilding ? (int) ($payload['sector_item_id'] ?? 0) : 0;
        $buildingSectorLabel = $isBuilding ? ($payload['sector_label'] ?? null) : null;
        $buildingSectorStaged = false;
        $buildingSectorPhaseN = null;
        $buildingSectorMissing = false;
        if ($isBuilding && $buildingSectorItemId > 0) {
            $sectorItem = $sectorItems[$buildingSectorItemId] ?? null;
            if ($sectorItem === null) {
                $buildingSectorMissing = true;
            } elseif ($sectorItem['sent']) {
                $buildingSectorId = (int) $sectorItem['ref'];
                $buildingSectorLabel = $sectorLabels[$buildingSectorId] ?? $sectorItem['label'];
                $buildingSectorMissing = !isset($sectorLabels[$buildingSectorId]);
            } else {
                $buildingSectorLabel = $sectorItem['label'];
                $buildingSectorStaged = true;
                $buildingSectorPhaseN = $phaseNumbers[$sectorItem['phase_id']] ?? null;
            }
        } elseif ($isBuilding) {
            $buildingSectorMissing = !isset($sectorLabels[$buildingSectorId]);
            $buildingSectorLabel = $sectorLabels[$buildingSectorId] ?? $buildingSectorLabel;
        }
        $areaId = $isSector ? (int) ($payload['area_id'] ?? 0) : 0;
        // A sector may name a search area the scenario makes (area_item_id) instead of one that exists (area_id): then the
        // card says which, whether it is still to be made (area_staged, and in which phase) or gone, and a sector whose
        // area has been made points at the live area.
        $areaItemId = $isSector ? (int) ($payload['area_item_id'] ?? 0) : 0;
        $areaStaged = false;
        $areaPhaseN = null;
        $areaLabel = $isSector ? ($areaLabels[$areaId] ?? ($payload['area_label'] ?? null)) : null;
        $areaMissing = $isSector && !isset($areaLabels[$areaId]);
        if ($areaItemId > 0) {
            $areaItem = $areaItems[$areaItemId] ?? null;
            if ($areaItem === null) {
                $areaId = 0;
                $areaLabel = $payload['area_label'] ?? null;
                $areaMissing = true;
            } elseif ($areaItem['sent']) {
                $areaId = (int) $areaItem['ref'];
                $areaLabel = $areaItem['label'];
                $areaMissing = !isset($areaLabels[$areaId]);
            } else {
                $areaId = 0;
                $areaLabel = $areaItem['label'];
                $areaMissing = false;
                $areaStaged = true;
                $areaPhaseN = $phaseNumbers[$areaItem['phase_id']] ?? null;
            }
        }
        // A restricted area, a search area and a building go to nobody in particular: their audience is 'none', which is not
        // "everybody" to widen to (the team of the building's sector is the one that searches it).
        $audience = ($isRestricted || $isSearchArea || $isBuilding) ? 'none' : (in_array($payload['audience'] ?? '', ['all', 'slot'], true) ? $payload['audience'] : 'team');
        $slotId = $audience === 'slot' ? (int) ($payload['slot_id'] ?? 0) : 0;
        $slot = $slotById[$slotId] ?? null;
        if ($audience === 'slot') {
            $teamId = $slot['team_id'] ?? 0;
            $teamLabelNow = $slot['team_label'] ?? null;
        } else {
            $teamId = $audience === 'team' ? (int) ($payload['team_id'] ?? 0) : 0;
            $teamLabelNow = $audience === 'all' ? null : ($teamLabels[$teamId] ?? null);
        }
        $byPhase[(int) $row['phase_id']][] = [
            'id'           => (int) $row['id'],
            'kind'         => $row['kind'],
            'audience'     => $audience,
            'team_id'      => $teamId ?: null,
            'team_label'   => $teamLabelNow,
            'team_missing' => $audience === 'team' && !isset($teamLabels[$teamId]),
            'slot_id'      => $slotId ?: null,
            'slot_label'   => $audience === 'slot' ? ($slot['label'] ?? ($payload['slot_label'] ?? null)) : null,
            'unmapped'     => $audience === 'slot' && $teamLabelNow === null,
            'type'         => $isSector ? 'sector' : ($isRoute ? 'route' : ($isOrder ? 'order' : ($isRestricted ? 'restricted' : ($isSearchArea ? 'search_area' : ($isBuilding ? 'building' : ($payload['type'] ?? null)))))),
            // A building is one point, handed over as a list of one so the card's «δείξε στον χάρτη» and the ghost read it like any shape.
            'geo'          => $isRoute ? scenarioRouteGhostPoints($payload) : ($isOrder ? null : ($isBuilding ? (isset($payload['lat'], $payload['lng']) ? [[(float) $payload['lat'], (float) $payload['lng']]] : null) : ($payload['geo'] ?? null))),
            // An order to people has no place on the map; the words typed for it are what the card shows.
            'label'        => $isOrder ? ($payload['text'] ?? null) : ($payload['label'] ?? null),
            'order_type'   => $isOrder ? ($payload['order_type'] ?? null) : null,
            // A restricted area's own words: what it is (danger, cliff, forbidden) and its shape (polygon, line).
            'restricted_kind' => $isRestricted ? ($payload['kind'] ?? null) : null,
            'shape'        => $isRestricted ? ($payload['shape'] ?? null) : null,
            'travel_mode'  => $payload['travel_mode'] ?? null,
            'area_id'      => $isSector ? ($areaId ?: null) : null,
            'area_label'   => $areaLabel,
            'area_missing' => $areaMissing,
            // A sector that names a search area of the scenario: which one, whether it is still to be made, and in which phase.
            'area_item_id' => $areaItemId ?: null,
            'area_staged'  => $areaStaged,
            'area_phase_n' => $areaPhaseN,
            'sent'         => $row['sent_at'] !== null,
            'sent_at'      => $row['sent_at'],
            'point_count'  => $isRoute ? count(is_array($payload['waypoints'] ?? null) ? $payload['waypoints'] : []) : null,
            'closed_loop'  => $isRoute ? !empty($payload['is_closed_loop']) : null,
            'return_to_start' => $isRoute ? !empty($payload['add_return_waypoint']) : null,
            'team_empty'   => $isRoute && $teamId > 0 && isset($teamLabels[(int) $teamId]) && empty($participantsByTeam[(int) $teamId]),
            // A building: the sector it stands in (a live one, or one the scenario makes and where that is in the plan) and
            // how many floors it has above the ground (v3.460.0).
            'floor_count'  => $isBuilding ? (int) ($payload['floor_count'] ?? 0) : null,
            'sector_item_id' => $buildingSectorItemId ?: null,
            'sector_label' => $buildingSectorLabel,
            'sector_staged' => $buildingSectorStaged,
            'sector_phase_n' => $buildingSectorPhaseN,
            'sector_missing' => $buildingSectorMissing,
            'dispatch_id'  => !$isSector && !$isRoute && !$isOrder && !$isRestricted && !$isSearchArea && !$isBuilding && $row['sent_ref_id'] !== null ? (int) $row['sent_ref_id'] : null,
            'search_area_id' => $isSearchArea && $row['sent_ref_id'] !== null ? (int) $row['sent_ref_id'] : null,
            'building_id'  => $isBuilding && $row['sent_ref_id'] !== null ? (int) $row['sent_ref_id'] : null,
            'sector_id'    => $isSector && $row['sent_ref_id'] !== null ? (int) $row['sent_ref_id'] : null,
            'route_id'     => $isRoute && $row['sent_ref_id'] !== null ? (int) $row['sent_ref_id'] : null,
            'order_id'     => $isOrder && $row['sent_ref_id'] !== null ? (int) $row['sent_ref_id'] : null,
            'restricted_id' => $isRestricted && $row['sent_ref_id'] !== null ? (int) $row['sent_ref_id'] : null,
            'error'        => $row['last_error'],
            'state'        => $states[(int) $row['id']]['state'] ?? null,
            'done_n'       => $states[(int) $row['id']]['done'] ?? null,
            'of_n'         => $states[(int) $row['id']]['of'] ?? null,
        ];
    }

    $out = [];
    foreach ($phases as $i => $phase) {
        $phaseItems = $byPhase[(int) $phase['id']] ?? [];
        $out[] = [
            'id'             => (int) $phase['id'],
            'n'              => $i + 1,
            'title'          => $phase['title'],
            'played_at'      => $phase['played_at'],
            'played_label'   => $phase['played_at'] ? date('d/m H:i', strtotime($phase['played_at'])) : null,
            'played_by_name' => $phase['played_by_name'],
            'items'          => $phaseItems,
            'progress'       => $phase['played_at'] ? scenarioPhaseProgress($phaseItems) : null,
        ];
    }
    return ['phases' => $out, 'slots' => $slots, 'teams' => $teams];
}

/**
 * How the orders a scenario has SENT stand now, read from the live orders themselves (v3.456.0): nothing is stored
 * about it, so it can never disagree with the map. $items are rows of mission_scenario_items (id, kind, sent_at,
 * sent_ref_id); only sent ones are looked at. Returns itemId => ['state' => 'done'|'open'|'withdrawn', 'done' => n|null,
 * 'of' => n|null]:
 *   - a point or area is DONE when the team it went to has pressed «Ολοκληρώθηκε» (the same fact the live map fades
 *     it on); an order to every team when ALL the teams that have people have (done/of say how many); OPEN until
 *     then; WITHDRAWN when the live order is gone;
 *   - a route is DONE when it is completed, WITHDRAWN when it is cancelled or gone, else OPEN;
 *   - a sector is DONE when its status is completed, WITHDRAWN when it is gone or has been taken off its team, else OPEN;
 *   - a restricted area is never done: it is STANDING (in force) while it exists, WITHDRAWN when it is gone.
 * A «Δεν μπορώ» does not finish anything: the order stays open. Reopening a finished order makes it open again.
 */
function scenarioItemLiveStates(int $missionId, array $items): array {
    $byKind = ['dispatch' => [], 'route' => [], 'sector' => [], 'order' => [], 'restricted' => [], 'area' => [], 'building' => []];
    foreach ($items as $row) {
        if ($row['sent_at'] === null || $row['sent_ref_id'] === null) {
            continue;
        }
        $kind = in_array($row['kind'], ['route', 'sector', 'order', 'restricted', 'area', 'building'], true) ? $row['kind'] : 'dispatch';
        $byKind[$kind][(int) $row['id']] = (int) $row['sent_ref_id'];
    }
    $out = [];
    $in = fn(array $ids) => implode(',', array_fill(0, count($ids), '?'));

    if ($byKind['dispatch']) {
        $refs = array_values(array_unique($byKind['dispatch']));
        $live = [];
        foreach (dbFetchAll("SELECT id, team_id FROM mission_dispatch_points WHERE mission_id = ? AND id IN (" . $in($refs) . ")", array_merge([$missionId], $refs)) as $r) {
            $live[(int) $r['id']] = $r['team_id'] !== null ? (int) $r['team_id'] : null;
        }
        $progress = $live ? loadDispatchProgress(array_keys($live)) : [];
        $scopes = in_array(null, $live, true) ? missionBroadcastScopes($missionId) : [];
        foreach ($byKind['dispatch'] as $itemId => $ref) {
            if (!array_key_exists($ref, $live)) {
                $out[$itemId] = ['state' => 'withdrawn', 'done' => null, 'of' => null];
            } elseif ($live[$ref] === null) {
                $finish = broadcastFinish($scopes, $progress[$ref] ?? []);
                $out[$itemId] = ['state' => $finish['ts'] !== null ? 'done' : 'open', 'done' => $finish['done'], 'of' => count($scopes)];
            } else {
                $finished = ($progress[$ref]['t' . $live[$ref]]['completed_ts'] ?? null) !== null;
                $out[$itemId] = ['state' => $finished ? 'done' : 'open', 'done' => null, 'of' => null];
            }
        }
    }

    if ($byKind['route']) {
        $refs = array_values(array_unique($byKind['route']));
        $live = [];
        foreach (dbFetchAll("SELECT id, completed_at, cancelled_at FROM mission_routes WHERE mission_id = ? AND id IN (" . $in($refs) . ")", array_merge([$missionId], $refs)) as $r) {
            $live[(int) $r['id']] = $r['cancelled_at'] !== null ? 'withdrawn' : ($r['completed_at'] !== null ? 'done' : 'open');
        }
        foreach ($byKind['route'] as $itemId => $ref) {
            $out[$itemId] = ['state' => $live[$ref] ?? 'withdrawn', 'done' => null, 'of' => null];
        }
    }

    if ($byKind['sector']) {
        $refs = array_values(array_unique($byKind['sector']));
        $live = [];
        foreach (dbFetchAll("SELECT id, team_id, status FROM mission_search_sectors WHERE mission_id = ? AND id IN (" . $in($refs) . ")", array_merge([$missionId], $refs)) as $r) {
            $live[(int) $r['id']] = $r['status'] === 'completed' ? 'done' : ($r['team_id'] === null ? 'withdrawn' : 'open');
        }
        foreach ($byKind['sector'] as $itemId => $ref) {
            $out[$itemId] = ['state' => $live[$ref] ?? 'withdrawn', 'done' => null, 'of' => null];
        }
    }

    if ($byKind['restricted']) {
        // A zone is never finished: it stands until it is taken away.
        $refs = array_values(array_unique($byKind['restricted']));
        $live = [];
        foreach (dbFetchAll("SELECT id FROM mission_restricted_areas WHERE mission_id = ? AND id IN (" . $in($refs) . ")", array_merge([$missionId], $refs)) as $r) {
            $live[(int) $r['id']] = true;
        }
        foreach ($byKind['restricted'] as $itemId => $ref) {
            $out[$itemId] = ['state' => isset($live[$ref]) ? 'standing' : 'withdrawn', 'done' => null, 'of' => null];
        }
    }

    if ($byKind['area']) {
        // A search area is never finished either - its sectors are - so it is STANDING while it exists.
        $refs = array_values(array_unique($byKind['area']));
        $live = [];
        foreach (dbFetchAll("SELECT id FROM mission_search_areas WHERE mission_id = ? AND id IN (" . $in($refs) . ")", array_merge([$missionId], $refs)) as $r) {
            $live[(int) $r['id']] = true;
        }
        foreach ($byKind['area'] as $itemId => $ref) {
            $out[$itemId] = ['state' => isset($live[$ref]) ? 'standing' : 'withdrawn', 'done' => null, 'of' => null];
        }
    }

    if ($byKind['building']) {
        // A building is no order that can be finished (the team that searches its sector clears it floor by floor, in the
        // sector's own checklist), so it is STANDING while it exists. It exists as long as its sector does: a sector that is
        // deleted takes its buildings with it, and one of ANOTHER mission is not this scenario's building (v3.460.0).
        $refs = array_values(array_unique($byKind['building']));
        $live = [];
        foreach (dbFetchAll(
            "SELECT b.id FROM mission_sector_buildings b JOIN mission_search_sectors s ON s.id = b.sector_id
             WHERE s.mission_id = ? AND b.id IN (" . $in($refs) . ")",
            array_merge([$missionId], $refs)
        ) as $r) {
            $live[(int) $r['id']] = true;
        }
        foreach ($byKind['building'] as $itemId => $ref) {
            $out[$itemId] = ['state' => isset($live[$ref]) ? 'standing' : 'withdrawn', 'done' => null, 'of' => null];
        }
    }

    if ($byKind['order']) {
        // An order to people is DONE when everybody it went to has done what it asked - the same rule as the
        // acknowledgement panel's «finished»: for a task, a location, a photo or a video that is the thing itself
        // (fulfilled_at); for a message or a spoken announcement, which nothing can observe being heard, the
        // acknowledgement («Ελήφθη») is the completion. done/of count people. WITHDRAWN here only means there is
        // nobody left to wait for (the order or every recipient row is gone, e.g. a removed visitor).
        $refs = array_values(array_unique($byKind['order']));
        $types = [];
        foreach (dbFetchAll("SELECT id, order_type FROM mission_orders WHERE mission_id = ? AND id IN (" . $in($refs) . ")", array_merge([$missionId], $refs)) as $r) {
            $types[(int) $r['id']] = (string) $r['order_type'];
        }
        $people = [];
        foreach (dbFetchAll(
            "SELECT order_id, COUNT(*) AS n, SUM(fulfilled_at IS NOT NULL) AS fulfilled, SUM(acknowledged_at IS NOT NULL) AS acked
             FROM mission_order_recipients WHERE order_id IN (" . $in($refs) . ") GROUP BY order_id",
            $refs
        ) as $r) {
            $people[(int) $r['order_id']] = ['of' => (int) $r['n'], 'fulfilled' => (int) $r['fulfilled'], 'acked' => (int) $r['acked']];
        }
        foreach ($byKind['order'] as $itemId => $ref) {
            $type = $types[$ref] ?? null;
            $of = $people[$ref]['of'] ?? 0;
            if ($type === null || $of === 0) {
                $out[$itemId] = ['state' => 'withdrawn', 'done' => null, 'of' => null];
                continue;
            }
            $doneN = in_array($type, ACK_TRACKER_FULFIL_TYPES, true) ? $people[$ref]['fulfilled'] : $people[$ref]['acked'];
            $out[$itemId] = ['state' => $doneN >= $of ? 'done' : 'open', 'done' => $doneN, 'of' => $of];
        }
    }
    return $out;
}

/**
 * The progress of a PLAYED phase, from its items as loadMissionScenario() builds them: total = the orders that still
 * count (everything but the ones taken back; an order that failed to go counts, so the phase is not "finished"
 * while one is waiting for a retry), done = finished ones, open = sent and still under way, withdrawn, failed, and
 * complete (every order that still counts is finished). A restricted area that is in force is `standing`: it is not an order
 * that can be finished, so it is not in total (a phase is complete when its other orders are), but it can be taken back.
 * can_withdraw is true while any order is still open or any zone stands.
 */
function scenarioPhaseProgress(array $items): array {
    $done = $open = $withdrawn = $failed = $withdrawable = $standing = 0;
    foreach ($items as $item) {
        $state = $item['state'] ?? null;
        if ($state === 'done') {
            $done++;
        } elseif ($state === 'open') {
            $open++;
            // An order to people (a message, a task...) cannot be taken back once it has been delivered.
            if (($item['kind'] ?? '') !== 'order') {
                $withdrawable++;
            }
        } elseif ($state === 'standing') {
            // A search area stands too, but it is no order that can be finished and taking a phase back never deletes it
            // (it is the ground its sectors are on): it is in none of the counts. `standing` is the zones that taking the
            // phase back DELETES, and the card's confirmation says so.
            // Nor does a building (v3.460.0): it stays with its sector, which is what the phase takes back.
            if (!in_array($item['kind'] ?? '', ['area', 'building'], true)) {
                $standing++;
                $withdrawable++;
            }
        } elseif ($state === 'withdrawn') {
            $withdrawn++;
        } elseif (!$item['sent']) {
            $failed++;
        }
    }
    $total = $done + $open + $failed;
    return [
        'total' => $total, 'done' => $done, 'open' => $open, 'standing' => $standing, 'withdrawn' => $withdrawn, 'failed' => $failed,
        'complete' => $total > 0 && $done === $total,
        'withdrawable' => $withdrawable,
        'can_withdraw' => $withdrawable > 0,
    ];
}

/** One phase of this mission, or null. */
function scenarioPhaseRow(int $missionId, int $phaseId): ?array {
    $row = dbFetchOne(
        "SELECT id, mission_id, seq, title, played_at, played_by FROM mission_scenario_phases WHERE id = ? AND mission_id = ?",
        [$phaseId, $missionId]
    );
    return $row ?: null;
}

/** ['ok' => true, 'id' => n] or ['ok' => false, 'error' => text]. $title is optional ('' = just "Φάση n"). */
function addScenarioPhase(array $mission, int $userId, string $title = ''): array {
    $missionId = (int) $mission['id'];
    if ((int) dbFetchValue("SELECT COUNT(*) FROM mission_scenario_phases WHERE mission_id = ?", [$missionId]) >= SCENARIO_MAX_PHASES) {
        return ['ok' => false, 'error' => t('scenario.too_many_phases', ['max' => SCENARIO_MAX_PHASES])];
    }
    $title = mb_substr(trim($title), 0, SCENARIO_TITLE_MAX);
    $seq = 1 + (int) dbFetchValue("SELECT COALESCE(MAX(seq), 0) FROM mission_scenario_phases WHERE mission_id = ?", [$missionId]);
    $id = (int) dbInsert(
        "INSERT INTO mission_scenario_phases (mission_id, seq, title, created_by, created_at) VALUES (?, ?, ?, ?, NOW())",
        [$missionId, $seq, $title !== '' ? $title : null, $userId]
    );
    logAudit('create_scenario_phase', 'mission_scenario_phases', $id, null, ['mission_id' => $missionId, 'seq' => $seq]);
    return ['ok' => true, 'id' => $id];
}

/** Null on success, else the error to show. */
function renameScenarioPhase(array $mission, int $phaseId, string $title, int $userId): ?string {
    $missionId = (int) $mission['id'];
    if (!scenarioPhaseRow($missionId, $phaseId)) {
        return t('scenario.phase_not_found');
    }
    $title = mb_substr(trim($title), 0, SCENARIO_TITLE_MAX);
    dbExecute("UPDATE mission_scenario_phases SET title = ? WHERE id = ? AND mission_id = ?", [$title !== '' ? $title : null, $phaseId, $missionId]);
    logAudit('rename_scenario_phase', 'mission_scenario_phases', $phaseId, null, ['mission_id' => $missionId]);
    return null;
}

/**
 * Takes a phase and everything staged in it off the plan. Orders it already sent stay where they are: they are
 * live orders now and are withdrawn like any other. The phases after it move up one place. Null on success.
 */
function deleteScenarioPhase(array $mission, int $phaseId, int $userId): ?string {
    $missionId = (int) $mission['id'];
    $phase = scenarioPhaseRow($missionId, $phaseId);
    if (!$phase) {
        return t('scenario.phase_not_found');
    }
    // A search area of this phase that sectors of ANOTHER phase are waiting for cannot go with it: those sectors would be
    // left with an area that is nowhere (v3.459.0). Sectors of this very phase go with it.
    foreach (dbFetchAll("SELECT id, payload FROM mission_scenario_items WHERE phase_id = ? AND mission_id = ? AND kind = 'area'", [$phaseId, $missionId]) as $areaRow) {
        foreach (scenarioUnsentSectorsOfStagedArea($missionId, (int) $areaRow['id']) as $waiting) {
            if ($waiting['phase_id'] !== $phaseId) {
                $areaPayload = json_decode((string) $areaRow['payload'], true);
                return t('scenario.phase_area_in_use', ['area' => is_array($areaPayload) ? (string) ($areaPayload['label'] ?? '') : '']);
            }
        }
    }
    // Likewise a sector of this phase that buildings of ANOTHER phase are waiting for (v3.460.0): those buildings would be left
    // with a sector that is nowhere. Buildings of this very phase go with it.
    foreach (dbFetchAll("SELECT id, payload FROM mission_scenario_items WHERE phase_id = ? AND mission_id = ? AND kind = 'sector'", [$phaseId, $missionId]) as $sectorRow) {
        foreach (scenarioUnsentBuildingsOfStagedSector($missionId, (int) $sectorRow['id']) as $waiting) {
            if ($waiting['phase_id'] !== $phaseId) {
                $sectorPayload = json_decode((string) $sectorRow['payload'], true);
                return t('scenario.phase_sector_in_use', ['sector' => is_array($sectorPayload) ? (string) ($sectorPayload['label'] ?? '') : '']);
            }
        }
    }
    dbExecute("DELETE FROM mission_scenario_phases WHERE id = ? AND mission_id = ?", [$phaseId, $missionId]);
    dbExecute("UPDATE mission_scenario_phases SET seq = seq - 1 WHERE mission_id = ? AND seq > ?", [$missionId, (int) $phase['seq']]);
    logAudit('delete_scenario_phase', 'mission_scenario_phases', $phaseId, null, ['mission_id' => $missionId, 'was_played' => $phase['played_at'] !== null]);
    return null;
}

/**
 * Who a staged order is for, from what the window posts in team_id: a team's id (digits), 'slot:<id>' for a slot of
 * this scenario, or - only when $allowAll - the word 'all' for every team. ANYTHING else, the empty string
 * included, is not an audience: an empty team is NOT "all". Returns null when it is not one of those, ['error' =>
 * text] for a slot that is not this mission's, else ['audience' => 'team'|'slot'|'all', 'slot' => row|null,
 * 'team_input' => what prepareMissionDispatch() / prepareMissionSector() should be given as team_id: the id for a
 * team, '' for a slot (checked as an order to nobody in particular, since the shape, the type and the note are
 * what can be wrong) and for 'all'].
 */
function scenarioParseAudience(int $missionId, string $raw, bool $allowAll): ?array {
    $raw = trim($raw);
    if ($allowAll && $raw === 'all') {
        return ['audience' => 'all', 'slot' => null, 'team_input' => ''];
    }
    if (preg_match('/^slot:([1-9][0-9]{0,9})$/', $raw, $m)) {
        $slot = scenarioSlotRow($missionId, (int) $m[1]);
        if (!$slot) {
            return ['error' => t('scenario.slot_not_found')];
        }
        return ['audience' => 'slot', 'slot' => $slot, 'team_input' => ''];
    }
    if (ctype_digit($raw) && (int) $raw > 0) {
        return ['audience' => 'team', 'slot' => null, 'team_input' => $raw];
    }
    return null;
}

/**
 * Writes an order to a point or an area into a phase. $input is the order window's form: team_id (a team's id,
 * 'slot:<id>' for a slot of this scenario, or the word 'all' for every team - anything else is refused, an empty
 * team is NOT "all"), type ('point' or
 * 'polygon'), geo (JSON text), label, travel_mode. Checked by prepareMissionDispatch(), exactly as sending it
 * would be. A phase that has been played takes no more orders. ['ok' => true, 'id' => n] or ['ok' => false, 'error' => text].
 */
function addScenarioDispatchItem(array $mission, int $phaseId, int $userId, array $input): array {
    $missionId = (int) $mission['id'];
    $phase = scenarioPhaseRow($missionId, $phaseId);
    if (!$phase) {
        return ['ok' => false, 'error' => t('scenario.phase_not_found')];
    }
    if ($phase['played_at'] !== null) {
        return ['ok' => false, 'error' => t('scenario.phase_closed_for_orders')];
    }
    if ((int) dbFetchValue("SELECT COUNT(*) FROM mission_scenario_items WHERE phase_id = ?", [$phaseId]) >= SCENARIO_MAX_ITEMS_PER_PHASE) {
        return ['ok' => false, 'error' => t('scenario.too_many_orders', ['max' => SCENARIO_MAX_ITEMS_PER_PHASE])];
    }

    $who = scenarioParseAudience($missionId, (string) ($input['team_id'] ?? ''), true);
    if ($who === null) {
        return ['ok' => false, 'error' => t('dispatch.pick_team_first')];
    }
    if (isset($who['error'])) {
        return ['ok' => false, 'error' => $who['error']];
    }
    $audience = $who['audience'];
    $slot = $who['slot'];
    $teamInput = $who['team_input'];

    $prepared = prepareMissionDispatch($mission, [
        'team_id'     => $teamInput,
        'type'        => $input['type'] ?? '',
        'label'       => $input['label'] ?? '',
        'geo'         => $input['geo'] ?? '',
        'travel_mode' => $input['travel_mode'] ?? null,
    ]);
    if (!$prepared['ok']) {
        return $prepared;
    }

    $payload = [
        'audience'    => $audience,
        'team_id'     => $audience === 'team' ? (int) $prepared['team_id'] : null,
        'type'        => $prepared['type'],
        'geo'         => $prepared['geo'],
        'label'       => $prepared['label'],
        // An order to a slot is checked as one to no team, which has no travel mode of its own; the person's
        // choice is what the team it ends up as will be sent with.
        'travel_mode' => $audience === 'slot' ? normalizeTravelMode($input['travel_mode'] ?? null) : $prepared['travel_mode'],
    ];
    if ($audience === 'slot') {
        $payload['slot_id'] = (int) $slot['id'];
        $payload['slot_label'] = $slot['label'];
    }
    $seq = 1 + (int) dbFetchValue("SELECT COALESCE(MAX(seq), 0) FROM mission_scenario_items WHERE phase_id = ?", [$phaseId]);
    $id = (int) dbInsert(
        "INSERT INTO mission_scenario_items (phase_id, mission_id, seq, kind, payload, slot_id, created_by, created_at) VALUES (?, ?, ?, 'dispatch', ?, ?, ?, NOW())",
        [$phaseId, $missionId, $seq, json_encode($payload), $slot ? (int) $slot['id'] : null, $userId]
    );
    logAudit('stage_scenario_item', 'mission_scenario_items', $id, null, ['mission_id' => $missionId, 'phase_id' => $phaseId, 'type' => $payload['type'], 'audience' => $audience, 'team_id' => $payload['team_id'], 'slot_id' => $payload['slot_id'] ?? null]);
    return ['ok' => true, 'id' => $id];
}

/**
 * Writes the sectors of ONE existing search area into a phase, all of them or none (the sector window's «Στο
 * σενάριο» stages every piece of the division at once, so a refusal of one must not leave the others behind).
 * $sectors is a list of the window's rows: team_id (a team's id, or 'slot:<id>' for a slot of this scenario - a
 * sector has no «όλες οι ομάδες», and the word 'all' or an empty team is REFUSED, not turned into "no team"),
 * label, geo (an array of [lat, lng], or its JSON text). Each is checked by prepareMissionSector(), exactly as
 * making it would be, so a sector that could never be made (no such area, a team of another mission, a polygon of
 * two points) is refused when it is written down. A phase that has been played takes no more orders.
 * ['ok' => true, 'count' => n] or ['ok' => false, 'error' => text] with nothing written.
 */
function addScenarioSectorItems(array $mission, int $phaseId, int $userId, int $areaId, array $sectors, int $areaItemId = 0): array {
    $missionId = (int) $mission['id'];
    $phase = scenarioPhaseRow($missionId, $phaseId);
    if (!$phase) {
        return ['ok' => false, 'error' => t('scenario.phase_not_found')];
    }
    if ($phase['played_at'] !== null) {
        return ['ok' => false, 'error' => t('scenario.phase_closed_for_orders')];
    }
    // The sectors may be cut from a search area the scenario itself makes ($areaItemId, v3.459.0) rather than from one that
    // exists. If that area has been made since it was written down it is a real area now and they are cut from it as
    // always; if not, they are written down against the staged one, and play makes the area first.
    $stagedArea = null;
    if ($areaItemId > 0) {
        $ref = scenarioStagedAreaForSector($missionId, $phase, $areaItemId);
        if (isset($ref['error'])) {
            return ['ok' => false, 'error' => $ref['error']];
        }
        if (isset($ref['live_area_id'])) {
            $areaId = $ref['live_area_id'];
            $areaItemId = 0;
        } else {
            $stagedArea = ['label' => $ref['label']];
        }
    }
    $sectors = array_values($sectors);
    if (!$sectors) {
        return ['ok' => false, 'error' => t('scenario.sector_none')];
    }
    if ((int) dbFetchValue("SELECT COUNT(*) FROM mission_scenario_items WHERE phase_id = ?", [$phaseId]) + count($sectors) > SCENARIO_MAX_ITEMS_PER_PHASE) {
        return ['ok' => false, 'error' => t('scenario.too_many_orders', ['max' => SCENARIO_MAX_ITEMS_PER_PHASE])];
    }

    $rows = [];
    foreach ($sectors as $i => $row) {
        if (!is_array($row)) {
            return ['ok' => false, 'error' => t('common.invalid_request')];
        }
        $name = trim((string) ($row['label'] ?? ''));
        // With several sectors the person has to be told WHICH one was refused.
        $refuse = fn(string $error) => ['ok' => false, 'error' => count($sectors) > 1
            ? t('scenario.sector_refused', ['sector' => $name !== '' ? $name : '#' . ($i + 1), 'error' => $error])
            : $error];

        $who = scenarioParseAudience($missionId, (string) ($row['team_id'] ?? ''), false);
        if ($who === null) {
            return $refuse(t('scenario.sector_needs_team'));
        }
        if (isset($who['error'])) {
            return $refuse($who['error']);
        }
        $geo = $row['geo'] ?? '';
        $prepared = prepareMissionSector($mission, [
            'label'   => $name,
            'area_id' => $areaId,
            'team_id' => $who['team_input'],
            'geo'     => is_array($geo) ? json_encode($geo) : (string) $geo,
        ], $stagedArea);
        if (!$prepared['ok']) {
            return $refuse($prepared['error']);
        }
        $payload = [
            'audience'   => $who['audience'],
            'team_id'    => $who['audience'] === 'team' ? (int) $prepared['team_id'] : null,
            'area_id'    => $prepared['area_id'],
            'area_label' => $prepared['area_label'],
            'label'      => $prepared['label'],
            'geo'        => $prepared['geo'],
        ];
        if ($stagedArea !== null) {
            // The area is named by the plan's own item, not by an id: there is none yet. Play looks the item up.
            unset($payload['area_id']);
            $payload['area_item_id'] = $areaItemId;
        }
        if ($who['audience'] === 'slot') {
            $payload['slot_id'] = (int) $who['slot']['id'];
            $payload['slot_label'] = $who['slot']['label'];
        }
        $rows[] = ['payload' => $payload, 'slot_id' => $who['slot'] ? (int) $who['slot']['id'] : null];
    }

    // One statement, so a failure writes none of them.
    $seq = 1 + (int) dbFetchValue("SELECT COALESCE(MAX(seq), 0) FROM mission_scenario_items WHERE phase_id = ?", [$phaseId]);
    $values = [];
    $params = [];
    foreach ($rows as $i => $r) {
        $values[] = "(?, ?, ?, 'sector', ?, ?, ?, NOW())";
        array_push($params, $phaseId, $missionId, $seq + $i, json_encode($r['payload']), $r['slot_id'], $userId);
    }
    $firstId = (int) dbInsert(
        "INSERT INTO mission_scenario_items (phase_id, mission_id, seq, kind, payload, slot_id, created_by, created_at) VALUES " . implode(', ', $values),
        $params
    );
    logAudit('stage_scenario_sectors', 'mission_scenario_items', $firstId, null, ['mission_id' => $missionId, 'phase_id' => $phaseId, 'area_id' => $stagedArea !== null ? null : $areaId, 'area_item_id' => $stagedArea !== null ? $areaItemId : null, 'count' => count($rows)]);
    return ['ok' => true, 'count' => count($rows)];
}

/**
 * Where a sector written down in $sectorPhase may be cut from when it names the scenario's own search area $areaItemId
 * (v3.459.0): ['error' => text] when there is no such area in this scenario, or when it is in a LATER phase than the sector
 * (play makes things phase by phase and in order, so the area has to come first); ['live_area_id' => n] when the area has been
 * made already (it is a real search area now, and the sectors are written against that one); else ['label' => the staged
 * area's name], meaning the sectors are written against the staged area and play makes the area first.
 */
function scenarioStagedAreaForSector(int $missionId, array $sectorPhase, int $areaItemId): array {
    $row = dbFetchOne(
        "SELECT i.id, i.phase_id, i.payload, i.sent_at, i.sent_ref_id FROM mission_scenario_items i
         WHERE i.id = ? AND i.mission_id = ? AND i.kind = 'area'",
        [$areaItemId, $missionId]
    );
    if (!$row) {
        return ['error' => t('scenario.area_item_not_found')];
    }
    $payload = json_decode((string) $row['payload'], true);
    $label = is_array($payload) ? (string) ($payload['label'] ?? '') : '';
    if ($row['sent_at'] !== null && $row['sent_ref_id'] !== null) {
        return ['live_area_id' => (int) $row['sent_ref_id']];
    }
    $areaPhase = scenarioPhaseRow($missionId, (int) $row['phase_id']);
    if ($areaPhase && ((int) $areaPhase['seq'] > (int) $sectorPhase['seq'] || ((int) $areaPhase['seq'] === (int) $sectorPhase['seq'] && (int) $areaPhase['id'] > (int) $sectorPhase['id']))) {
        return ['error' => t('scenario.sector_before_area', ['area' => $label, 'phase' => t('scenario.phase_n', ['n' => scenarioPhaseNumber($missionId, $areaPhase)])])];
    }
    return ['label' => $label];
}

/** The number shown for a phase ("Φάση n"): its place in the list. $phase is a row of scenarioPhaseRow(). */
function scenarioPhaseNumber(int $missionId, array $phase): int {
    return 1 + (int) dbFetchValue(
        "SELECT COUNT(*) FROM mission_scenario_phases WHERE mission_id = ? AND (seq < ? OR (seq = ? AND id < ?))",
        [$missionId, (int) $phase['seq'], (int) $phase['seq'], (int) $phase['id']]
    );
}

/**
 * The sectors of this scenario that have not been sent and are cut from the scenario's own search area $areaItemId: [['id' =>
 * the sector item, 'phase_id' => its phase], ...]. A staged area with any of them cannot be taken off the plan (v3.459.0).
 */
function scenarioUnsentSectorsOfStagedArea(int $missionId, int $areaItemId): array {
    $out = [];
    foreach (dbFetchAll("SELECT id, phase_id, payload FROM mission_scenario_items WHERE mission_id = ? AND kind = 'sector' AND sent_at IS NULL", [$missionId]) as $row) {
        $payload = json_decode((string) $row['payload'], true);
        if (is_array($payload) && (int) ($payload['area_item_id'] ?? 0) === $areaItemId) {
            $out[] = ['id' => (int) $row['id'], 'phase_id' => (int) $row['phase_id']];
        }
    }
    return $out;
}

/**
 * Where a building written down in $buildingPhase may stand when it names the scenario's own sector $sectorItemId (v3.460.0):
 * ['error' => text] when there is no such sector in this scenario, or when it is in a LATER phase than the building (play makes
 * things phase by phase and in order, so the sector has to come first); ['live_sector_id' => n] when the sector has been made
 * already (it is a real sector now, and the building is written against that one); else ['label' => the staged sector's name,
 * 'geo' => its polygon], meaning the building is written against the staged sector and play makes the sector first.
 */
function scenarioStagedSectorForBuilding(int $missionId, array $buildingPhase, int $sectorItemId): array {
    $row = dbFetchOne(
        "SELECT i.id, i.phase_id, i.payload, i.sent_at, i.sent_ref_id FROM mission_scenario_items i
         WHERE i.id = ? AND i.mission_id = ? AND i.kind = 'sector'",
        [$sectorItemId, $missionId]
    );
    if (!$row) {
        return ['error' => t('scenario.sector_item_not_found')];
    }
    $payload = json_decode((string) $row['payload'], true);
    $label = is_array($payload) ? (string) ($payload['label'] ?? '') : '';
    if ($row['sent_at'] !== null && $row['sent_ref_id'] !== null) {
        return ['live_sector_id' => (int) $row['sent_ref_id']];
    }
    $sectorPhase = scenarioPhaseRow($missionId, (int) $row['phase_id']);
    if ($sectorPhase && ((int) $sectorPhase['seq'] > (int) $buildingPhase['seq'] || ((int) $sectorPhase['seq'] === (int) $buildingPhase['seq'] && (int) $sectorPhase['id'] > (int) $buildingPhase['id']))) {
        return ['error' => t('scenario.building_before_sector', ['sector' => $label, 'phase' => t('scenario.phase_n', ['n' => scenarioPhaseNumber($missionId, $sectorPhase)])])];
    }
    return ['label' => $label, 'geo' => is_array($payload) && is_array($payload['geo'] ?? null) ? $payload['geo'] : []];
}

/**
 * The buildings of this scenario that have not been sent and stand in the scenario's own sector $sectorItemId: [['id' => the
 * building item, 'phase_id' => its phase], ...]. A staged sector with any of them cannot be taken off the plan (v3.460.0).
 */
function scenarioUnsentBuildingsOfStagedSector(int $missionId, int $sectorItemId): array {
    $out = [];
    foreach (dbFetchAll("SELECT id, phase_id, payload FROM mission_scenario_items WHERE mission_id = ? AND kind = 'building' AND sent_at IS NULL", [$missionId]) as $row) {
        $payload = json_decode((string) $row['payload'], true);
        if (is_array($payload) && (int) ($payload['sector_item_id'] ?? 0) === $sectorItemId) {
            $out[] = ['id' => (int) $row['id'], 'phase_id' => (int) $row['phase_id']];
        }
    }
    return $out;
}

/** A staged route's waypoints as [[lat, lng], ...] for the map, or null when it has none. */
function scenarioRouteGhostPoints($payload): ?array {
    if (!is_array($payload) || !is_array($payload['waypoints'] ?? null) || !$payload['waypoints']) {
        return null;
    }
    $points = [];
    foreach ($payload['waypoints'] as $wp) {
        if (is_array($wp) && isset($wp['lat'], $wp['lng'])) {
            $points[] = [(float) $wp['lat'], (float) $wp['lng']];
        }
    }
    return $points ?: null;
}

/**
 * Writes a route into a phase. $input is the route window's form: team_id (a team's id, or 'slot:<id>' for a slot of
 * this scenario - a route has no «όλες οι ομάδες», and the word 'all' or an empty team, which in the route window means
 * "people picked across teams", is REFUSED), title, waypoints (JSON text), is_closed_loop ('1'), add_return_waypoint
 * ('1'), member_ids (JSON text; only a real team has them: a slot goes to the whole team it comes to stand for),
 * travel_mode. Checked by prepareMissionRoute(), exactly as sending it would be, so a route that could never be sent
 * (a team of another mission, no waypoint, thirty-one of them, one out of range) is refused when it is written down.
 * The return waypoint, which is the team's last ping on the day, is not a point of the plan, but a route that already
 * has thirty could never take it, so that is refused here as it would be at send. A phase that has been played takes
 * no more orders. ['ok' => true, 'id' => n] or ['ok' => false, 'error' => text].
 */
function addScenarioRouteItem(array $mission, int $phaseId, int $userId, array $input): array {
    $missionId = (int) $mission['id'];
    $phase = scenarioPhaseRow($missionId, $phaseId);
    if (!$phase) {
        return ['ok' => false, 'error' => t('scenario.phase_not_found')];
    }
    if ($phase['played_at'] !== null) {
        return ['ok' => false, 'error' => t('scenario.phase_closed_for_orders')];
    }
    if ((int) dbFetchValue("SELECT COUNT(*) FROM mission_scenario_items WHERE phase_id = ?", [$phaseId]) >= SCENARIO_MAX_ITEMS_PER_PHASE) {
        return ['ok' => false, 'error' => t('scenario.too_many_orders', ['max' => SCENARIO_MAX_ITEMS_PER_PHASE])];
    }

    $who = scenarioParseAudience($missionId, (string) ($input['team_id'] ?? ''), false);
    if ($who === null) {
        return ['ok' => false, 'error' => t('scenario.route_needs_team')];
    }
    if (isset($who['error'])) {
        return ['ok' => false, 'error' => $who['error']];
    }
    $audience = $who['audience'];
    $slot = $who['slot'];

    $prepared = prepareMissionRoute($mission, [
        // '' for a slot: only the route's own rules are checked (the shape, the thirty points), not a team.
        'team_id'        => $who['team_input'],
        'ring_index'     => '',
        'title'          => $input['title'] ?? '',
        'waypoints'      => $input['waypoints'] ?? '',
        'member_ids'     => $audience === 'team' ? ($input['member_ids'] ?? '') : '',
        'is_closed_loop' => $input['is_closed_loop'] ?? '',
        'travel_mode'    => $input['travel_mode'] ?? null,
    ]);
    if (!$prepared['ok']) {
        return $prepared;
    }
    $addReturn = ($input['add_return_waypoint'] ?? '') === '1';
    if ($addReturn && count($prepared['waypoints']) >= ROUTE_MAX_WAYPOINTS) {
        return ['ok' => false, 'error' => t('route.too_many_waypoints_with_return')];
    }

    $payload = [
        'audience'            => $audience,
        'team_id'             => $audience === 'team' ? (int) $prepared['team_id'] : null,
        'label'               => $prepared['title'],
        'waypoints'           => $prepared['waypoints'],
        'is_closed_loop'      => $prepared['closed_loop_asked'] ? 1 : 0,
        'add_return_waypoint' => $addReturn ? 1 : 0,
        'travel_mode'         => $prepared['travel_mode'],
        'member_ids'          => $audience === 'team' ? array_values(array_unique(array_filter($prepared['member_ids'], fn($id) => $id > 0))) : [],
    ];
    if ($audience === 'slot') {
        $payload['slot_id'] = (int) $slot['id'];
        $payload['slot_label'] = $slot['label'];
    }
    $seq = 1 + (int) dbFetchValue("SELECT COALESCE(MAX(seq), 0) FROM mission_scenario_items WHERE phase_id = ?", [$phaseId]);
    $id = (int) dbInsert(
        "INSERT INTO mission_scenario_items (phase_id, mission_id, seq, kind, payload, slot_id, created_by, created_at) VALUES (?, ?, ?, 'route', ?, ?, ?, NOW())",
        [$phaseId, $missionId, $seq, json_encode($payload), $slot ? (int) $slot['id'] : null, $userId]
    );
    logAudit('stage_scenario_item', 'mission_scenario_items', $id, null, ['mission_id' => $missionId, 'phase_id' => $phaseId, 'type' => 'route', 'audience' => $audience, 'team_id' => $payload['team_id'], 'slot_id' => $payload['slot_id'] ?? null, 'waypoints' => count($payload['waypoints'])]);
    return ['ok' => true, 'id' => $id];
}

/**
 * The general orders a scenario may carry, in the order the card offers them: a task, a spoken announcement, a
 * broadcast message, and a request for a location, a photo or a video. (The stream request and the end-of-operation
 * recall are not among them: see missionGeneralOrderSpec().)
 */
const SCENARIO_ORDER_TYPES = ['task', 'speak', 'message', 'location', 'photo', 'video'];

/**
 * Writes a general order - to people, not to a place - into a phase. $input is the card's form: order_type (one of
 * SCENARIO_ORDER_TYPES), text (the words of a task, an announcement or a message; required for those three, ignored
 * for the others), team_id (a team's id, 'slot:<id>' for a slot of this scenario, or the word 'all' for every person
 * on duty when the order is sent - anything else, the empty string included, is REFUSED: an empty audience is not
 * "everybody"). Checked as sending it would be (the type, the words, a team of this mission), so an order that could
 * never be sent is refused when it is written down. A phase that has been played takes no more orders.
 * ['ok' => true, 'id' => n] or ['ok' => false, 'error' => text].
 */
function addScenarioOrderItem(array $mission, int $phaseId, int $userId, array $input): array {
    $missionId = (int) $mission['id'];
    $phase = scenarioPhaseRow($missionId, $phaseId);
    if (!$phase) {
        return ['ok' => false, 'error' => t('scenario.phase_not_found')];
    }
    if ($phase['played_at'] !== null) {
        return ['ok' => false, 'error' => t('scenario.phase_closed_for_orders')];
    }
    if ((int) dbFetchValue("SELECT COUNT(*) FROM mission_scenario_items WHERE phase_id = ?", [$phaseId]) >= SCENARIO_MAX_ITEMS_PER_PHASE) {
        return ['ok' => false, 'error' => t('scenario.too_many_orders', ['max' => SCENARIO_MAX_ITEMS_PER_PHASE])];
    }

    $type = (string) ($input['order_type'] ?? '');
    $spec = in_array($type, SCENARIO_ORDER_TYPES, true) ? missionGeneralOrderSpec($type) : null;
    if ($spec === null) {
        return ['ok' => false, 'error' => t('scenario.order_type_unknown')];
    }
    $text = $spec['text'] ? missionGeneralOrderText((string) ($input['text'] ?? '')) : '';
    if ($spec['text'] && $text === '') {
        return ['ok' => false, 'error' => t($spec['empty_key'])];
    }

    $who = scenarioParseAudience($missionId, (string) ($input['team_id'] ?? ''), true);
    if ($who === null) {
        return ['ok' => false, 'error' => t('scenario.order_needs_audience')];
    }
    if (isset($who['error'])) {
        return ['ok' => false, 'error' => $who['error']];
    }
    $audience = $who['audience'];
    $slot = $who['slot'];
    if ($audience === 'team' && !dbFetchValue("SELECT 1 FROM mission_teams WHERE id = ? AND mission_id = ?", [(int) $who['team_input'], $missionId])) {
        return ['ok' => false, 'error' => t('common.team_not_found')];
    }

    $payload = [
        'audience'   => $audience,
        'team_id'    => $audience === 'team' ? (int) $who['team_input'] : null,
        'order_type' => $type,
        'text'       => $text,
    ];
    if ($audience === 'slot') {
        $payload['slot_id'] = (int) $slot['id'];
        $payload['slot_label'] = $slot['label'];
    }
    $seq = 1 + (int) dbFetchValue("SELECT COALESCE(MAX(seq), 0) FROM mission_scenario_items WHERE phase_id = ?", [$phaseId]);
    $id = (int) dbInsert(
        "INSERT INTO mission_scenario_items (phase_id, mission_id, seq, kind, payload, slot_id, created_by, created_at) VALUES (?, ?, ?, 'order', ?, ?, ?, NOW())",
        [$phaseId, $missionId, $seq, json_encode($payload, JSON_UNESCAPED_UNICODE), $slot ? (int) $slot['id'] : null, $userId]
    );
    logAudit('stage_scenario_item', 'mission_scenario_items', $id, null, ['mission_id' => $missionId, 'phase_id' => $phaseId, 'type' => 'order:' . $type, 'audience' => $audience, 'team_id' => $payload['team_id'], 'slot_id' => $payload['slot_id'] ?? null]);
    return ['ok' => true, 'id' => $id];
}

/**
 * Writes a restricted area into a phase. $input is the restricted-area window's form: label, kind (danger|cliff|forbidden),
 * shape (polygon|line) and geo (JSON text of [[lat, lng], ...]). There is no team or slot here: a zone is for everybody in the
 * field. Checked by prepareMissionRestrictedArea(), exactly as making it would be, so a zone that could never be made (no
 * name, three points missing, one outside the map) is refused when it is written down. The payload keeps the kind and the
 * shape as words - never the window's empty defaults - so playing it never has to guess. A phase that has been played takes
 * no more orders. ['ok' => true, 'id' => n] or ['ok' => false, 'error' => text].
 */
function addScenarioRestrictedItem(array $mission, int $phaseId, int $userId, array $input): array {
    $missionId = (int) $mission['id'];
    $phase = scenarioPhaseRow($missionId, $phaseId);
    if (!$phase) {
        return ['ok' => false, 'error' => t('scenario.phase_not_found')];
    }
    if ($phase['played_at'] !== null) {
        return ['ok' => false, 'error' => t('scenario.phase_closed_for_orders')];
    }
    if ((int) dbFetchValue("SELECT COUNT(*) FROM mission_scenario_items WHERE phase_id = ?", [$phaseId]) >= SCENARIO_MAX_ITEMS_PER_PHASE) {
        return ['ok' => false, 'error' => t('scenario.too_many_orders', ['max' => SCENARIO_MAX_ITEMS_PER_PHASE])];
    }

    $prepared = prepareMissionRestrictedArea([
        'label' => $input['label'] ?? '',
        'kind'  => $input['kind'] ?? '',
        'shape' => $input['shape'] ?? '',
        'geo'   => $input['geo'] ?? '',
    ]);
    if (!$prepared['ok']) {
        return $prepared;
    }

    $payload = [
        'label' => $prepared['label'],
        'kind'  => $prepared['kind'],
        'shape' => $prepared['shape'],
        'geo'   => $prepared['geo'],
    ];
    $seq = 1 + (int) dbFetchValue("SELECT COALESCE(MAX(seq), 0) FROM mission_scenario_items WHERE phase_id = ?", [$phaseId]);
    $id = (int) dbInsert(
        "INSERT INTO mission_scenario_items (phase_id, mission_id, seq, kind, payload, slot_id, created_by, created_at) VALUES (?, ?, ?, 'restricted', ?, NULL, ?, NOW())",
        [$phaseId, $missionId, $seq, json_encode($payload, JSON_UNESCAPED_UNICODE), $userId]
    );
    logAudit('stage_scenario_item', 'mission_scenario_items', $id, null, ['mission_id' => $missionId, 'phase_id' => $phaseId, 'type' => 'restricted', 'kind' => $payload['kind'], 'shape' => $payload['shape']]);
    return ['ok' => true, 'id' => $id];
}

/**
 * Writes a search area into a phase (v3.459.0). $input is the search-area window's form: label and geo (JSON text of [[lat,
 * lng], ...], at least three points). There is no team or slot here: an area is a boundary, not an order to anyone; and no ring
 * index, which only the page's ring shortcuts set on an area they make themselves. Checked by prepareMissionSearchArea(),
 * exactly as making it would be, so an area that could never be made (no name, two points, one outside the map) is refused
 * when it is written down. Sectors written down later can be cut from it (addScenarioSectorItems() with its item id), and
 * play makes the area before them. A phase that has been played takes no more orders. ['ok' => true, 'id' => n] or ['ok' =>
 * false, 'error' => text].
 */
function addScenarioAreaItem(array $mission, int $phaseId, int $userId, array $input): array {
    $missionId = (int) $mission['id'];
    $phase = scenarioPhaseRow($missionId, $phaseId);
    if (!$phase) {
        return ['ok' => false, 'error' => t('scenario.phase_not_found')];
    }
    if ($phase['played_at'] !== null) {
        return ['ok' => false, 'error' => t('scenario.phase_closed_for_orders')];
    }
    if ((int) dbFetchValue("SELECT COUNT(*) FROM mission_scenario_items WHERE phase_id = ?", [$phaseId]) >= SCENARIO_MAX_ITEMS_PER_PHASE) {
        return ['ok' => false, 'error' => t('scenario.too_many_orders', ['max' => SCENARIO_MAX_ITEMS_PER_PHASE])];
    }

    $prepared = prepareMissionSearchArea([
        'label'      => $input['label'] ?? '',
        'geo'        => $input['geo'] ?? '',
        'ring_index' => '',
    ]);
    if (!$prepared['ok']) {
        return $prepared;
    }

    $payload = ['label' => $prepared['label'], 'geo' => $prepared['geo']];
    $seq = 1 + (int) dbFetchValue("SELECT COALESCE(MAX(seq), 0) FROM mission_scenario_items WHERE phase_id = ?", [$phaseId]);
    $id = (int) dbInsert(
        "INSERT INTO mission_scenario_items (phase_id, mission_id, seq, kind, payload, slot_id, created_by, created_at) VALUES (?, ?, ?, 'area', ?, NULL, ?, NOW())",
        [$phaseId, $missionId, $seq, json_encode($payload, JSON_UNESCAPED_UNICODE), $userId]
    );
    logAudit('stage_scenario_item', 'mission_scenario_items', $id, null, ['mission_id' => $missionId, 'phase_id' => $phaseId, 'type' => 'search_area']);
    return ['ok' => true, 'id' => $id];
}

/**
 * Writes a building of a search sector into a phase (v3.460.0). $input is the building popup's form: sector_id (the id of a
 * sector that exists, or 'item:<id>' naming a sector the scenario itself makes), label, lat, lng and floor_count (floors above
 * the ground, 0-50). There is no team or slot here: the team that searches the sector searches its buildings. Checked by
 * prepareMissionSectorBuilding(), exactly as making it would be - including that the point stands in the sector (within the
 * tolerance), against the staged sector's own polygon when the sector is not made yet - so a building that could never be
 * made is refused when it is written down. A staged sector has to be in the same phase as the building or an earlier one
 * (play makes the sector first); a sector that has been made since is used as the live sector it is. A phase that has been
 * played takes no more orders. ['ok' => true, 'id' => n] or ['ok' => false, 'error' => text].
 */
function addScenarioBuildingItem(array $mission, int $phaseId, int $userId, array $input): array {
    $missionId = (int) $mission['id'];
    $phase = scenarioPhaseRow($missionId, $phaseId);
    if (!$phase) {
        return ['ok' => false, 'error' => t('scenario.phase_not_found')];
    }
    if ($phase['played_at'] !== null) {
        return ['ok' => false, 'error' => t('scenario.phase_closed_for_orders')];
    }
    if ((int) dbFetchValue("SELECT COUNT(*) FROM mission_scenario_items WHERE phase_id = ?", [$phaseId]) >= SCENARIO_MAX_ITEMS_PER_PHASE) {
        return ['ok' => false, 'error' => t('scenario.too_many_orders', ['max' => SCENARIO_MAX_ITEMS_PER_PHASE])];
    }

    $ref = trim((string) ($input['sector_id'] ?? ''));
    $sectorItemId = 0;
    $sectorId = 0;
    if (preg_match('/^item:([1-9][0-9]{0,9})$/', $ref, $m)) {
        $sectorItemId = (int) $m[1];
    } elseif (ctype_digit($ref) && (int) $ref > 0) {
        $sectorId = (int) $ref;
    } else {
        return ['ok' => false, 'error' => t('scenario.building_sector_not_found')];
    }

    $stagedSector = null;
    if ($sectorItemId > 0) {
        $found = scenarioStagedSectorForBuilding($missionId, $phase, $sectorItemId);
        if (isset($found['error'])) {
            return ['ok' => false, 'error' => $found['error']];
        }
        if (isset($found['live_sector_id'])) {
            $sectorId = $found['live_sector_id'];
            $sectorItemId = 0;
        } else {
            $stagedSector = ['label' => $found['label'], 'geo' => $found['geo']];
        }
    }
    if ($stagedSector === null && !dbFetchValue("SELECT 1 FROM mission_search_sectors WHERE id = ? AND mission_id = ?", [$sectorId, $missionId])) {
        return ['ok' => false, 'error' => t('scenario.building_sector_not_found')];
    }

    $prepared = prepareMissionSectorBuilding($mission, [
        'sector_id'   => $sectorId,
        'label'       => $input['label'] ?? '',
        'lat'         => $input['lat'] ?? null,
        'lng'         => $input['lng'] ?? null,
        'floor_count' => $input['floor_count'] ?? 0,
    ], $stagedSector);
    if (!$prepared['ok']) {
        return $prepared;
    }

    $payload = [
        'label'        => $prepared['label'],
        'lat'          => $prepared['lat'],
        'lng'          => $prepared['lng'],
        'floor_count'  => $prepared['floor_count'],
        'sector_label' => $prepared['sector_label'],
    ];
    if ($stagedSector !== null) {
        // The sector is named by the plan's own item, not by an id: there is none yet. Play looks the item up.
        $payload['sector_item_id'] = $sectorItemId;
    } else {
        $payload['sector_id'] = $prepared['sector_id'];
    }
    $seq = 1 + (int) dbFetchValue("SELECT COALESCE(MAX(seq), 0) FROM mission_scenario_items WHERE phase_id = ?", [$phaseId]);
    $id = (int) dbInsert(
        "INSERT INTO mission_scenario_items (phase_id, mission_id, seq, kind, payload, slot_id, created_by, created_at) VALUES (?, ?, ?, 'building', ?, NULL, ?, NOW())",
        [$phaseId, $missionId, $seq, json_encode($payload, JSON_UNESCAPED_UNICODE), $userId]
    );
    logAudit('stage_scenario_item', 'mission_scenario_items', $id, null, ['mission_id' => $missionId, 'phase_id' => $phaseId, 'type' => 'building', 'sector_id' => $payload['sector_id'] ?? null, 'sector_item_id' => $payload['sector_item_id'] ?? null, 'floor_count' => $payload['floor_count']]);
    return ['ok' => true, 'id' => $id];
}

/** Takes one staged order off the plan. Null on success. */
function deleteScenarioItem(array $mission, int $itemId, int $userId): ?string {
    $missionId = (int) $mission['id'];
    $item = dbFetchOne("SELECT id, phase_id, kind, payload, sent_at FROM mission_scenario_items WHERE id = ? AND mission_id = ?", [$itemId, $missionId]);
    if (!$item) {
        return t('scenario.item_not_found');
    }
    // A search area that sectors still waiting to be sent are cut from cannot be taken off the plan: they would have no
    // area to be made in (v3.459.0). Like a slot in use, it is refused until those sectors are gone.
    if ($item['kind'] === 'area') {
        $waiting = scenarioUnsentSectorsOfStagedArea($missionId, $itemId);
        if ($waiting) {
            $areaPayload = json_decode((string) $item['payload'], true);
            return t('scenario.area_in_use', ['area' => is_array($areaPayload) ? (string) ($areaPayload['label'] ?? '') : '', 'n' => count($waiting)]);
        }
    }
    // And a staged sector that buildings still waiting to be sent are in (v3.460.0): they would have no sector to be made in.
    if ($item['kind'] === 'sector') {
        $waiting = scenarioUnsentBuildingsOfStagedSector($missionId, $itemId);
        if ($waiting) {
            $sectorPayload = json_decode((string) $item['payload'], true);
            return t('scenario.sector_in_use', ['sector' => is_array($sectorPayload) ? (string) ($sectorPayload['label'] ?? '') : '', 'n' => count($waiting)]);
        }
    }
    dbExecute("DELETE FROM mission_scenario_items WHERE id = ? AND mission_id = ?", [$itemId, $missionId]);
    logAudit('delete_scenario_item', 'mission_scenario_items', $itemId, null, ['mission_id' => $missionId, 'phase_id' => (int) $item['phase_id'], 'was_sent' => $item['sent_at'] !== null]);
    return null;
}

/**
 * What createMissionDispatch() takes, built from a staged order's payload, or null when the payload cannot be
 * read as an order. The one place that turns a payload back into a form: «όλες οι ομάδες» is the empty team_id
 * ONLY when the payload says audience 'all'; an audience 'team' without a real team id is null, never ''; and an
 * audience 'slot' is the team its slot stands for ($slotTeams: slot id => team id, as scenarioSlotTeamMap() gives
 * it) or null while the slot stands for none - never ''.
 */
function scenarioDispatchInputFromPayload($payload, array $slotTeams = []): ?array {
    if (!is_array($payload) || !isset($payload['type'], $payload['geo'])) {
        return null;
    }
    if (($payload['audience'] ?? '') === 'all') {
        $teamId = '';
    } elseif (($payload['audience'] ?? '') === 'team' && (int) ($payload['team_id'] ?? 0) > 0) {
        $teamId = (string) (int) $payload['team_id'];
    } elseif (($payload['audience'] ?? '') === 'slot' && (int) ($slotTeams[(int) ($payload['slot_id'] ?? 0)] ?? 0) > 0) {
        $teamId = (string) (int) $slotTeams[(int) $payload['slot_id']];
    } else {
        return null;
    }
    return [
        'team_id'     => $teamId,
        'ring_index'  => '',
        'incident_id' => '',
        'type'        => (string) $payload['type'],
        'label'       => (string) ($payload['label'] ?? ''),
        'geo'         => json_encode($payload['geo']),
        'travel_mode' => $teamId === '' ? '' : (string) ($payload['travel_mode'] ?? ''),
    ];
}

/**
 * What createMissionSector() takes, built from a staged sector's payload, or null when the payload cannot be read
 * as a sector. The sector's twin of scenarioDispatchInputFromPayload(), with the same rule and one more: a sector
 * goes to a REAL team - audience 'team' with a team id, or audience 'slot' whose slot stands for a team
 * ($slotTeams: slot id => team id, as scenarioSlotTeamMap() gives it). A missing team, a slot that stands for
 * none and the word 'all' (which a sector does not have) are all null, never "no team": a sector made with no
 * team is an unassigned one that tells nobody, which is not the order that was staged.
 */
function scenarioSectorInputFromPayload($payload, array $slotTeams = []): ?array {
    if (!is_array($payload) || (int) ($payload['area_id'] ?? 0) <= 0 || !isset($payload['geo']) || !is_array($payload['geo'])) {
        return null;
    }
    if (($payload['audience'] ?? '') === 'team' && (int) ($payload['team_id'] ?? 0) > 0) {
        $teamId = (string) (int) $payload['team_id'];
    } elseif (($payload['audience'] ?? '') === 'slot' && (int) ($slotTeams[(int) ($payload['slot_id'] ?? 0)] ?? 0) > 0) {
        $teamId = (string) (int) $slotTeams[(int) $payload['slot_id']];
    } else {
        return null;
    }
    return [
        'area_id' => (string) (int) $payload['area_id'],
        'team_id' => $teamId,
        'label'   => (string) ($payload['label'] ?? ''),
        'geo'     => json_encode($payload['geo']),
    ];
}

/**
 * What createMissionRoute() takes, built from a staged route's payload, or null when the payload cannot be read as a
 * route. The route's twin of scenarioSectorInputFromPayload(), with the same rule: a route goes to a REAL team -
 * audience 'team' with a team id, or audience 'slot' whose slot stands for a team ($slotTeams: slot id => team id, as
 * scenarioSlotTeamMap() gives it). A missing team, a slot that stands for none and the word 'all' (which a route does
 * not have) are all null, never "no team": with no team the route window makes a route of people picked across
 * teams, which is not the order that was staged and would tell whoever happens to be in member_ids.
 */
function scenarioRouteInputFromPayload($payload, array $slotTeams = []): ?array {
    if (!is_array($payload) || !is_array($payload['waypoints'] ?? null) || !$payload['waypoints']) {
        return null;
    }
    if (($payload['audience'] ?? '') === 'team' && (int) ($payload['team_id'] ?? 0) > 0) {
        $teamId = (string) (int) $payload['team_id'];
    } elseif (($payload['audience'] ?? '') === 'slot' && (int) ($slotTeams[(int) ($payload['slot_id'] ?? 0)] ?? 0) > 0) {
        $teamId = (string) (int) $slotTeams[(int) $payload['slot_id']];
    } else {
        return null;
    }
    return [
        'team_id'             => $teamId,
        'ring_index'          => '',
        'title'               => (string) ($payload['label'] ?? ''),
        'waypoints'           => json_encode($payload['waypoints']),
        'add_return_waypoint' => !empty($payload['add_return_waypoint']) ? '1' : '0',
        'member_ids'          => json_encode(array_values(array_map('intval', is_array($payload['member_ids'] ?? null) ? $payload['member_ids'] : []))),
        'is_closed_loop'      => !empty($payload['is_closed_loop']) ? '1' : '0',
        'travel_mode'         => (string) ($payload['travel_mode'] ?? ''),
    ];
}

/**
 * What createMissionRestrictedArea() takes, built from a staged zone's payload, or null when the payload cannot be read as
 * one. The restricted area's twin of the other payload readers, minus the audience (a zone has none): the label must be
 * words, the kind and the shape must be named in the payload (an unnamed kind is not quietly "danger"), and the geo must be
 * a list. Anything else is null, and the item fails with «δεν διαβάζεται» rather than making a zone nobody asked for.
 */
function scenarioRestrictedInputFromPayload($payload): ?array {
    if (!is_array($payload) || trim((string) ($payload['label'] ?? '')) === '' || !is_array($payload['geo'] ?? null)) {
        return null;
    }
    if (!in_array($payload['kind'] ?? '', ['danger', 'cliff', 'forbidden'], true) || !in_array($payload['shape'] ?? '', ['polygon', 'line'], true)) {
        return null;
    }
    return [
        'label' => (string) $payload['label'],
        'kind'  => (string) $payload['kind'],
        'shape' => (string) $payload['shape'],
        'geo'   => json_encode($payload['geo']),
    ];
}

/**
 * What createMissionSearchArea() takes, built from a staged search area's payload, or null when the payload cannot be read as
 * one (v3.459.0). The area's twin of scenarioRestrictedInputFromPayload(): no audience to widen, the label must be words and
 * the geo a list. The ring index is always empty: a staged area is a hand-drawn one. Anything else is null, and the item
 * fails with «δεν διαβάζεται» rather than making an area nobody asked for.
 */
function scenarioAreaInputFromPayload($payload): ?array {
    if (!is_array($payload) || trim((string) ($payload['label'] ?? '')) === '' || !is_array($payload['geo'] ?? null)) {
        return null;
    }
    return [
        'label'      => (string) $payload['label'],
        'geo'        => json_encode($payload['geo']),
        'ring_index' => '',
    ];
}

/**
 * The live search area a staged sector is to be cut from, when its payload names one of the scenario's own areas
 * (area_item_id, v3.459.0): ['area_id' => the id of the area that item became] once that area has been made, else ['error' =>
 * text] - the area was taken off the plan, or has not been made (its phase has not been played, or its sending failed and is
 * waiting for a retry). Never another area, and never a guess: the sector fails and says why.
 */
function scenarioResolveStagedSectorArea(int $missionId, array $payload): array {
    $name = (string) ($payload['area_label'] ?? '');
    $row = dbFetchOne(
        "SELECT i.id, i.phase_id, i.payload, i.sent_at, i.sent_ref_id, p.seq, p.id AS pid FROM mission_scenario_items i
         JOIN mission_scenario_phases p ON p.id = i.phase_id
         WHERE i.id = ? AND i.mission_id = ? AND i.kind = 'area'",
        [(int) ($payload['area_item_id'] ?? 0), $missionId]
    );
    if (!$row) {
        return ['error' => t('scenario.item_area_item_gone', ['area' => $name])];
    }
    $areaPayload = json_decode((string) $row['payload'], true);
    if (is_array($areaPayload) && trim((string) ($areaPayload['label'] ?? '')) !== '') {
        $name = (string) $areaPayload['label'];
    }
    if ($row['sent_at'] === null || $row['sent_ref_id'] === null) {
        return ['error' => t('scenario.item_area_not_made', ['area' => $name, 'phase' => t('scenario.phase_n', ['n' => scenarioPhaseNumber($missionId, ['id' => $row['pid'], 'seq' => $row['seq']])])])];
    }
    return ['area_id' => (int) $row['sent_ref_id']];
}

/**
 * What createMissionSectorBuilding() takes, built from a staged building's payload, or null when the payload cannot be read as
 * one (v3.460.0). The building's twin of scenarioAreaInputFromPayload(): no audience to widen. The label must be words, the point
 * and the number of floors must be there as numbers, and the sector must already be an id (a building that names the plan's own
 * sector gets it from scenarioResolveStagedBuildingSector() first). Whether the numbers are in range, and whether the point stands
 * in the sector, is for prepareMissionSectorBuilding() to say when it is sent. Anything else is null, and the item fails with
 * «δεν διαβάζεται» rather than making a building nobody asked for.
 */
function scenarioBuildingInputFromPayload($payload): ?array {
    if (!is_array($payload) || trim((string) ($payload['label'] ?? '')) === '' || (int) ($payload['sector_id'] ?? 0) <= 0) {
        return null;
    }
    if (!is_numeric($payload['lat'] ?? null) || !is_numeric($payload['lng'] ?? null) || !is_numeric($payload['floor_count'] ?? null)) {
        return null;
    }
    return [
        'sector_id'   => (string) (int) $payload['sector_id'],
        'label'       => (string) $payload['label'],
        'lat'         => (string) $payload['lat'],
        'lng'         => (string) $payload['lng'],
        'floor_count' => (string) (int) $payload['floor_count'],
    ];
}

/**
 * The live sector a staged building is to be made in, when its payload names one of the scenario's own sectors (sector_item_id,
 * v3.460.0): ['sector_id' => the id of the sector that item became] once that sector has been made, else ['error' => text] - the
 * sector was taken off the plan, or has not been made (its phase has not been played, or its sending failed and is waiting for a
 * retry). Never another sector, and never a guess: the building fails and says why.
 */
function scenarioResolveStagedBuildingSector(int $missionId, array $payload): array {
    $name = (string) ($payload['sector_label'] ?? '');
    $row = dbFetchOne(
        "SELECT i.id, i.phase_id, i.payload, i.sent_at, i.sent_ref_id, p.seq, p.id AS pid FROM mission_scenario_items i
         JOIN mission_scenario_phases p ON p.id = i.phase_id
         WHERE i.id = ? AND i.mission_id = ? AND i.kind = 'sector'",
        [(int) ($payload['sector_item_id'] ?? 0), $missionId]
    );
    if (!$row) {
        return ['error' => t('scenario.item_sector_item_gone', ['sector' => $name])];
    }
    $sectorPayload = json_decode((string) $row['payload'], true);
    if (is_array($sectorPayload) && trim((string) ($sectorPayload['label'] ?? '')) !== '') {
        $name = (string) $sectorPayload['label'];
    }
    if ($row['sent_at'] === null || $row['sent_ref_id'] === null) {
        return ['error' => t('scenario.item_sector_not_made', ['sector' => $name, 'phase' => t('scenario.phase_n', ['n' => scenarioPhaseNumber($missionId, ['id' => $row['pid'], 'seq' => $row['seq']])])])];
    }
    return ['sector_id' => (int) $row['sent_ref_id']];
}

/**
 * What createScenarioOrder() takes, built from a staged general order's payload, or null when the payload cannot be
 * read as one. The general order's twin of scenarioDispatchInputFromPayload(), with the same rule: the audience 'all'
 * (every person on duty at the moment of sending) is read ONLY when the payload says so in words; an audience 'team'
 * without a real team id is null, and so is a slot that stands for no team ($slotTeams: slot id => team id, as
 * scenarioSlotTeamMap() gives it) - never "everybody". An order type that is not one of SCENARIO_ORDER_TYPES, and the
 * words of a task, an announcement or a message that are empty, are null too. Returns ['order_type' =>, 'text' =>,
 * 'audience' => 'all'|'team', 'team_id' => int|null].
 */
function scenarioOrderInputFromPayload($payload, array $slotTeams = []): ?array {
    if (!is_array($payload)) {
        return null;
    }
    $type = (string) ($payload['order_type'] ?? '');
    $spec = in_array($type, SCENARIO_ORDER_TYPES, true) ? missionGeneralOrderSpec($type) : null;
    if ($spec === null) {
        return null;
    }
    $text = $spec['text'] ? missionGeneralOrderText((string) ($payload['text'] ?? '')) : '';
    if ($spec['text'] && $text === '') {
        return null;
    }
    $audience = $payload['audience'] ?? '';
    if ($audience === 'all') {
        $teamId = null;
    } elseif ($audience === 'team' && (int) ($payload['team_id'] ?? 0) > 0) {
        $teamId = (int) $payload['team_id'];
    } elseif ($audience === 'slot' && (int) ($slotTeams[(int) ($payload['slot_id'] ?? 0)] ?? 0) > 0) {
        $teamId = (int) $slotTeams[(int) $payload['slot_id']];
    } else {
        return null;
    }
    return ['order_type' => $type, 'text' => $text, 'audience' => $teamId === null ? 'all' : 'team', 'team_id' => $teamId];
}

/**
 * Who a staged general order goes to at the moment it is sent (the people are only known on the day): everybody on duty
 * who takes part in the Action Room (activeMissionRecipientIds(), the one definition of "on duty"), or - for a team -
 * those of its members among them. A broadcast message never goes back to the person who sends it, exactly as the
 * Action Room's own «Καθολικό μήνυμα» does. $input is scenarioOrderInputFromPayload()'s answer.
 */
function scenarioOrderRecipients(int $missionId, int $userId, array $input): array {
    $ids = activeMissionRecipientIds($missionId);
    if ($input['audience'] === 'team') {
        $members = array_map('intval', array_column(
            dbFetchAll("SELECT user_id FROM mission_team_members WHERE mission_id = ? AND team_id = ?", [$missionId, (int) $input['team_id']]),
            'user_id'
        ));
        $ids = array_values(array_intersect($ids, $members));
    }
    if ($input['order_type'] === 'message') {
        $ids = array_values(array_diff($ids, [$userId]));
    }
    return $ids;
}

/**
 * Sends a staged general order the way a coordinator's own button does (sendMissionGeneralOrder()). A team that is no
 * longer in the mission fails with that reason, and so does an order nobody on duty would receive: an order that
 * reached nobody is not a sent order (the Action Room's own buttons warn «Επιλέξτε τουλάχιστον έναν εθελοντή με
 * ενεργή βάρδια» and send nothing), and the card offers to try it again. ['ok' => true, 'id' => the order's id] or
 * ['ok' => false, 'error' => text].
 */
function createScenarioOrder(array $mission, int $userId, array $input): array {
    $missionId = (int) $mission['id'];
    if ($input['audience'] === 'team' && !dbFetchValue("SELECT 1 FROM mission_teams WHERE id = ? AND mission_id = ?", [(int) $input['team_id'], $missionId])) {
        return ['ok' => false, 'error' => t('common.team_not_found')];
    }
    $ids = scenarioOrderRecipients($missionId, $userId, $input);
    if (!$ids) {
        return ['ok' => false, 'error' => t('scenario.order_nobody')];
    }
    $sent = sendMissionGeneralOrder($mission, $userId, $input['order_type'], $ids, $input['text']);
    return ['ok' => true, 'id' => $sent['order_id']];
}

/**
 * Sends one staged order - a point or an area through createMissionDispatch(), a sector through
 * createMissionSector(), a route through createMissionRoute(), an order to people through createScenarioOrder(), a
 * restricted area through createMissionRestrictedArea(), a search area through createMissionSearchArea(), a building through
 * createMissionSectorBuilding() - and writes down what came of it. Returns ['item_id' => n,
 * 'kind' => 'dispatch'|'sector'|'route'|'order'|'restricted'|'area'|'building', 'ok' => bool, 'building_id' => n|null (for a building), 'dispatch_id' => n|null (the live order, for a
 * point or an area), 'sector_id' => n|null (for a sector), 'route_id' => n|null (for a route), 'order_id' => n|null (for an
 * order to people), 'restricted_id' => n|null (for a restricted area), 'error' => text|null]. Never throws: an item
 * that blows up is a failed item, not an aborted phase. A sector whose search area has been deleted since fails with
 * that reason and creates nothing.
 */
function scenarioSendItem(array $mission, array $item, int $userId, string $userName): array {
    $itemId = (int) $item['id'];
    $kind = in_array($item['kind'] ?? 'dispatch', ['sector', 'route', 'order', 'restricted', 'area', 'building'], true) ? $item['kind'] : 'dispatch';
    $isSector = $kind === 'sector';
    $isBuilding = $kind === 'building';
    $payload = json_decode((string) $item['payload'], true);
    $slotTeams = scenarioSlotTeamMap((int) $mission['id']);
    // A sector written down against a search area the scenario makes (v3.459.0) is cut from the live area that item became:
    // looked up here, now, so a phase that makes the area and its sectors together has the area before the first sector
    // (they play in order). If the area is not there, the sector fails and says why; it is never cut from any other area.
    $areaError = null;
    if ($isSector && is_array($payload) && (int) ($payload['area_item_id'] ?? 0) > 0) {
        $resolved = scenarioResolveStagedSectorArea((int) $mission['id'], $payload);
        if (isset($resolved['error'])) {
            $areaError = $resolved['error'];
        } else {
            $payload['area_id'] = $resolved['area_id'];
        }
    }
    // A building written down for a sector the scenario makes (v3.460.0) is made in the live sector that item became: looked up here,
    // now, so a phase that makes the sector and its buildings together has the sector before the first building (they play in
    // order). If the sector is not there the building fails and says why; it is never put in any other sector.
    if ($isBuilding && is_array($payload) && (int) ($payload['sector_item_id'] ?? 0) > 0) {
        $resolvedSector = scenarioResolveStagedBuildingSector((int) $mission['id'], $payload);
        if (isset($resolvedSector['error'])) {
            $areaError = $resolvedSector['error'];
        } else {
            $payload['sector_id'] = $resolvedSector['sector_id'];
        }
    }
    $input = match ($kind) {
        'building' => scenarioBuildingInputFromPayload($payload),
        'sector' => scenarioSectorInputFromPayload($payload, $slotTeams),
        'route' => scenarioRouteInputFromPayload($payload, $slotTeams),
        'order' => scenarioOrderInputFromPayload($payload, $slotTeams),
        'restricted' => scenarioRestrictedInputFromPayload($payload),
        'area' => scenarioAreaInputFromPayload($payload),
        default => scenarioDispatchInputFromPayload($payload, $slotTeams),
    };
    if ($areaError !== null) {
        $result = ['ok' => false, 'error' => $areaError];
    } elseif ($input === null) {
        $result = ['ok' => false, 'error' => is_array($payload) && ($payload['audience'] ?? '') === 'slot'
            ? t('scenario.item_slot_unmapped', ['slot' => (string) ($payload['slot_label'] ?? '')])
            : t('scenario.item_unreadable')];
    } elseif ($isSector && !dbFetchValue("SELECT 1 FROM mission_search_areas WHERE id = ? AND mission_id = ?", [(int) $input['area_id'], (int) $mission['id']])) {
        $result = ['ok' => false, 'error' => t('scenario.item_area_gone', ['area' => (string) ($payload['area_label'] ?? '')])];
    } elseif ($isBuilding && !dbFetchValue("SELECT 1 FROM mission_search_sectors WHERE id = ? AND mission_id = ?", [(int) $input['sector_id'], (int) $mission['id']])) {
        $result = ['ok' => false, 'error' => t('scenario.item_sector_gone', ['sector' => (string) ($payload['sector_label'] ?? '')])];
    } else {
        try {
            $result = match ($kind) {
                'sector' => createMissionSector($mission, $userId, $input),
                'route' => createMissionRoute($mission, $userId, $input),
                'order' => createScenarioOrder($mission, $userId, $input),
                'restricted' => createMissionRestrictedArea($mission, $userId, $input),
                'area' => createMissionSearchArea($mission, $userId, $input),
                'building' => createMissionSectorBuilding($mission, $userId, $input),
                default => createMissionDispatch($mission, $userId, $userName, $input),
            };
        } catch (Throwable $e) {
            error_log('[scenario] item ' . $itemId . ' failed: ' . $e->getMessage());
            $result = ['ok' => false, 'error' => t('scenario.item_failed_unexpected')];
        }
    }
    if ($result['ok']) {
        dbExecute(
            "UPDATE mission_scenario_items SET sent_at = NOW(), sent_ref_id = ?, last_error = NULL WHERE id = ?",
            [(int) $result['id'], $itemId]
        );
        return [
            'item_id' => $itemId, 'kind' => $kind, 'ok' => true,
            'dispatch_id' => $kind === 'dispatch' ? (int) $result['id'] : null,
            'sector_id' => $isSector ? (int) $result['id'] : null,
            'route_id' => $kind === 'route' ? (int) $result['id'] : null,
            'order_id' => $kind === 'order' ? (int) $result['id'] : null,
            'restricted_id' => $kind === 'restricted' ? (int) $result['id'] : null,
            'search_area_id' => $kind === 'area' ? (int) $result['id'] : null,
            'building_id' => $isBuilding ? (int) $result['id'] : null,
            'error' => null,
        ];
    }
    $error = mb_substr((string) $result['error'], 0, 250);
    dbExecute("UPDATE mission_scenario_items SET last_error = ? WHERE id = ?", [$error, $itemId]);
    return ['item_id' => $itemId, 'kind' => $kind, 'ok' => false, 'dispatch_id' => null, 'sector_id' => null, 'route_id' => null, 'order_id' => null, 'restricted_id' => null, 'search_area_id' => null, 'building_id' => null, 'error' => $error];
}

/**
 * The first press wins: marks the phase played by $userId if, and only if, nobody has yet. One conditional UPDATE
 * (played_at IS NULL), so of two presses at the same moment exactly one gets true. Whoever gets false sends nothing.
 */
function claimScenarioPhase(int $missionId, int $phaseId, int $userId): bool {
    return dbExecute(
        "UPDATE mission_scenario_phases SET played_at = NOW(), played_by = ? WHERE id = ? AND mission_id = ? AND played_at IS NULL",
        [$userId, $phaseId, $missionId]
    ) > 0;
}

/**
 * PLAY: sends every order of a phase. The first press wins - one conditional UPDATE claims the phase, so a second
 * press (another coordinator, a double tap) is told it has gone and sends nothing. A phase with nothing in it
 * is refused before it is claimed. Each order then goes out in its staged order and one that fails does not
 * stop the rest. $mapping (slot id => team id) is what the dialog at play collects for the slots that stand for
 * no team yet; it is applied only to those, and a phase that still uses a slot with no team after it is refused
 * BEFORE the claim, with the list of those slots in 'unmapped' - nothing is sent and the phase stays a plan.
 * Returns ['ok' => false, 'error' => text (, 'unmapped' => [['id' =>, 'label' =>]])], or ['ok' => true, 'sent' =>
 * n, 'failed' => n, 'results' => [per item, see scenarioSendItem()]].
 */
function playScenarioPhase(array $mission, int $phaseId, int $userId, string $userName, array $mapping = []): array {
    $missionId = (int) $mission['id'];
    $phase = scenarioPhaseRow($missionId, $phaseId);
    if (!$phase) {
        return ['ok' => false, 'error' => t('scenario.phase_not_found')];
    }
    if ($phase['played_at'] !== null) {
        return ['ok' => false, 'error' => t('scenario.phase_already_played')];
    }
    if ((int) dbFetchValue("SELECT COUNT(*) FROM mission_scenario_items WHERE phase_id = ? AND sent_at IS NULL", [$phaseId]) === 0) {
        return ['ok' => false, 'error' => t('scenario.phase_empty')];
    }

    if ($mapping) {
        $error = mapScenarioSlotsIfFree($mission, $mapping, $userId);
        if ($error !== null) {
            return ['ok' => false, 'error' => $error];
        }
    }
    $unmapped = scenarioUnmappedSlotsOfPhase($missionId, $phaseId);
    if ($unmapped) {
        return [
            'ok' => false,
            'error' => t('scenario.slots_unmapped', ['slots' => implode(', ', array_column($unmapped, 'label'))]),
            'unmapped' => $unmapped,
        ];
    }

    if (!claimScenarioPhase($missionId, $phaseId, $userId)) {
        return ['ok' => false, 'error' => t('scenario.phase_already_played')];
    }

    $results = [];
    $items = dbFetchAll("SELECT id, kind, payload FROM mission_scenario_items WHERE phase_id = ? AND sent_at IS NULL ORDER BY seq, id", [$phaseId]);
    foreach ($items as $item) {
        $results[] = scenarioSendItem($mission, $item, $userId, $userName);
    }
    $sent = count(array_filter($results, fn($r) => $r['ok']));
    logAudit('play_scenario_phase', 'mission_scenario_phases', $phaseId, null, [
        'mission_id' => $missionId, 'orders' => count($results), 'sent' => $sent, 'failed' => count($results) - $sent,
    ]);
    return ['ok' => true, 'sent' => $sent, 'failed' => count($results) - $sent, 'results' => $results];
}

/**
 * Tries one order of a PLAYED phase again (it failed: the team was missing, the shape no longer passed). Only an
 * order that has a recorded failure can be tried again, and one press wins: the failure is cleared by a
 * conditional UPDATE that only one caller can make. ['ok' => false, 'error' => text], or ['ok' => true, 'item' =>
 * the result of scenarioSendItem()] (the item may still have failed again: look at item.ok).
 */
function retryScenarioItem(array $mission, int $itemId, int $userId, string $userName): array {
    $missionId = (int) $mission['id'];
    $item = dbFetchOne(
        "SELECT i.id, i.kind, i.payload, i.sent_at, i.last_error, p.played_at
         FROM mission_scenario_items i JOIN mission_scenario_phases p ON p.id = i.phase_id
         WHERE i.id = ? AND i.mission_id = ?",
        [$itemId, $missionId]
    );
    if (!$item) {
        return ['ok' => false, 'error' => t('scenario.item_not_found')];
    }
    if ($item['played_at'] === null) {
        return ['ok' => false, 'error' => t('scenario.phase_not_played_yet')];
    }
    $claimed = dbExecute(
        "UPDATE mission_scenario_items SET last_error = NULL WHERE id = ? AND sent_at IS NULL AND last_error IS NOT NULL AND last_error <> ''",
        [$itemId]
    );
    if (!$claimed) {
        return ['ok' => false, 'error' => t('scenario.nothing_to_retry')];
    }
    $result = scenarioSendItem($mission, $item, $userId, $userName);
    logAudit('retry_scenario_item', 'mission_scenario_items', $itemId, null, ['mission_id' => $missionId, 'ok' => $result['ok']]);
    return ['ok' => true, 'item' => $result];
}

/**
 * «Ανάκληση φάσης» (v3.456.0): takes back what a PLAYED phase sent and is still under way, through the same steps a
 * coordinator takes by hand - a point or an area is withdrawn (withdrawMissionDispatches: the withdrawal is recorded
 * and the people still on their way are told), a route is cancelled (cancelMissionRoute: its team is told), a sector
 * is taken off its team (assignMissionSector with no team: the sector stays on the map as an unassigned one and the
 * team is told). Orders that are already finished stay as they are - they are a fact, there is nothing to stop - and
 * so do orders already taken back. A restricted area that stands is taken away too (deleteMissionRestrictedArea(): open
 * breaches of it are resolved, as when a coordinator deletes the zone by hand). The phase stays played and its orders read
 * «ανακλήθηκε»; nothing is sent again.
 * Several orders to one team are told in one notice (sectors and points) or one per route, as by hand.
 * Returns ['ok' => false, 'error' => text], or ['ok' => true, 'withdrawn' => n, 'failed' => n, 'results' => [['item_id'
 * =>, 'kind' =>, 'ok' =>, 'error' =>], ...]].
 */
function withdrawScenarioPhase(array $mission, int $phaseId, int $userId): array {
    $missionId = (int) $mission['id'];
    $phase = scenarioPhaseRow($missionId, $phaseId);
    if (!$phase) {
        return ['ok' => false, 'error' => t('scenario.phase_not_found')];
    }
    if ($phase['played_at'] === null) {
        return ['ok' => false, 'error' => t('scenario.phase_not_played_yet')];
    }
    $rows = dbFetchAll(
        "SELECT id, kind, sent_at, sent_ref_id FROM mission_scenario_items
         WHERE phase_id = ? AND mission_id = ? AND sent_at IS NOT NULL AND sent_ref_id IS NOT NULL ORDER BY seq, id",
        [$phaseId, $missionId]
    );
    $states = scenarioItemLiveStates($missionId, $rows);
    // A general order to people (kind 'order') is not among them: once a message or a task has reached a phone there
    // is nothing to take back, and the Action Room has no way to do it by hand either.
    // Nor is a search area (v3.459.0): it is the ground its sectors lie on, and deleting it would delete them - the sectors
    // are taken off their teams, the area stays on the map.
    // Nor is a building (v3.460.0): it stays with its sector, which is taken off its team but stays on the map.
    $open = array_values(array_filter($rows, fn($r) => !in_array($r['kind'], ['order', 'area', 'building'], true) && in_array($states[(int) $r['id']]['state'] ?? '', ['open', 'standing'], true)));
    if (!$open) {
        return ['ok' => false, 'error' => t('scenario.withdraw_nothing')];
    }
    $phaseNumber = scenarioPhaseNumber($missionId, $phase);

    $results = [];
    $dispatchItems = [];
    foreach ($open as $row) {
        if ($row['kind'] === 'dispatch') {
            $dispatchItems[(int) $row['sent_ref_id']] = (int) $row['id'];
        }
    }
    if ($dispatchItems) {
        try {
            $taken = withdrawMissionDispatches($mission, array_keys($dispatchItems), $userId);
        } catch (Throwable $e) {
            error_log('[scenario] withdrawing the points of phase ' . $phaseId . ' failed: ' . $e->getMessage());
            $taken = [];
        }
        foreach ($dispatchItems as $dispatchId => $itemId) {
            $ok = in_array($dispatchId, $taken, true);
            $results[] = ['item_id' => $itemId, 'kind' => 'dispatch', 'ok' => $ok, 'error' => $ok ? null : t('scenario.withdraw_item_failed')];
        }
    }

    $sectorLines = [];
    foreach ($open as $row) {
        $itemId = (int) $row['id'];
        $ref = (int) $row['sent_ref_id'];
        try {
            if ($row['kind'] === 'route') {
                $error = cancelMissionRoute($mission, $userId, $ref, t('scenario.withdraw_reason', ['phase' => $phaseNumber]));
            } elseif ($row['kind'] === 'sector') {
                $answer = assignMissionSector($mission, $userId, $ref, null, null);
                $error = $answer['ok'] ? null : $answer['error'];
                foreach ($answer['withdrawn'] ?? [] as $personId => $lines) {
                    foreach ($lines as $line) {
                        $sectorLines[$personId][] = $line;
                    }
                }
            } elseif ($row['kind'] === 'restricted') {
                $error = deleteMissionRestrictedArea($mission, $userId, $ref);
            } else {
                continue;
            }
        } catch (Throwable $e) {
            error_log('[scenario] withdrawing item ' . $itemId . ' failed: ' . $e->getMessage());
            $error = t('scenario.withdraw_item_failed');
        }
        $results[] = ['item_id' => $itemId, 'kind' => $row['kind'], 'ok' => $error === null, 'error' => $error];
    }
    notifyOrdersWithdrawn($missionId, (string) $mission['title'], $sectorLines, 'mission_sector_assigned');

    $withdrawn = count(array_filter($results, fn($r) => $r['ok']));
    logAudit('withdraw_scenario_phase', 'mission_scenario_phases', $phaseId, null, [
        'mission_id' => $missionId, 'orders' => count($results), 'withdrawn' => $withdrawn, 'failed' => count($results) - $withdrawn,
    ]);
    return ['ok' => true, 'withdrawn' => $withdrawn, 'failed' => count($results) - $withdrawn, 'results' => $results];
}

// ── Slots ───────────────────────────────────────────────────────────────────────────────────────────────────────

/** One slot of this mission, or null. */
function scenarioSlotRow(int $missionId, int $slotId): ?array {
    $row = dbFetchOne("SELECT id, mission_id, label, team_id FROM mission_scenario_slots WHERE id = ? AND mission_id = ?", [$slotId, $missionId]);
    return $row ?: null;
}

/** slot id => the id of the team it stands for, or null while it stands for none. */
function scenarioSlotTeamMap(int $missionId): array {
    $map = [];
    foreach (dbFetchAll("SELECT id, team_id FROM mission_scenario_slots WHERE mission_id = ?", [$missionId]) as $row) {
        $map[(int) $row['id']] = $row['team_id'] !== null ? (int) $row['team_id'] : null;
    }
    return $map;
}

/**
 * The slots the unsent orders of a phase go to that stand for no team (or for a team that is no longer in the
 * mission): [['id' => n, 'label' => text], ...], in the order the orders come.
 */
function scenarioUnmappedSlotsOfPhase(int $missionId, int $phaseId): array {
    return array_map(
        fn($r) => ['id' => (int) $r['id'], 'label' => $r['label']],
        dbFetchAll(
            "SELECT s.id, s.label
             FROM mission_scenario_slots s
             JOIN mission_scenario_items i ON i.slot_id = s.id
             LEFT JOIN mission_teams t ON t.id = s.team_id AND t.mission_id = s.mission_id
             WHERE i.phase_id = ? AND i.sent_at IS NULL AND s.mission_id = ? AND t.id IS NULL
             GROUP BY s.id, s.label
             ORDER BY MIN(i.seq), s.id",
            [$phaseId, $missionId]
        )
    );
}

/** ['ok' => true, 'id' => n] or ['ok' => false, 'error' => text]. A name is unique within the mission, whatever its case. */
function addScenarioSlot(array $mission, int $userId, string $label): array {
    $missionId = (int) $mission['id'];
    $label = mb_substr(trim(preg_replace('/\s+/u', ' ', $label)), 0, SCENARIO_SLOT_LABEL_MAX);
    if ($label === '') {
        return ['ok' => false, 'error' => t('scenario.slot_label_empty')];
    }
    if ((int) dbFetchValue("SELECT COUNT(*) FROM mission_scenario_slots WHERE mission_id = ?", [$missionId]) >= SCENARIO_MAX_SLOTS) {
        return ['ok' => false, 'error' => t('scenario.too_many_slots', ['max' => SCENARIO_MAX_SLOTS])];
    }
    if ((int) dbFetchValue("SELECT COUNT(*) FROM mission_scenario_slots WHERE mission_id = ? AND label = ?", [$missionId, $label]) > 0) {
        return ['ok' => false, 'error' => t('scenario.slot_label_taken', ['label' => $label])];
    }
    $id = (int) dbInsert(
        "INSERT INTO mission_scenario_slots (mission_id, label, created_by, created_at) VALUES (?, ?, ?, NOW())",
        [$missionId, $label, $userId]
    );
    logAudit('create_scenario_slot', 'mission_scenario_slots', $id, null, ['mission_id' => $missionId, 'label' => $label]);
    return ['ok' => true, 'id' => $id];
}

/**
 * Says which real team a slot stands for ($teamId null = for none). The team must be one of this mission's.
 * Null on success, else the error to show.
 */
function mapScenarioSlot(array $mission, int $slotId, ?int $teamId, int $userId): ?string {
    $missionId = (int) $mission['id'];
    if (!scenarioSlotRow($missionId, $slotId)) {
        return t('scenario.slot_not_found');
    }
    if ($teamId !== null && !dbFetchValue("SELECT 1 FROM mission_teams WHERE id = ? AND mission_id = ?", [$teamId, $missionId])) {
        return t('common.team_not_found');
    }
    dbExecute("UPDATE mission_scenario_slots SET team_id = ? WHERE id = ? AND mission_id = ?", [$teamId, $slotId, $missionId]);
    logAudit('map_scenario_slot', 'mission_scenario_slots', $slotId, null, ['mission_id' => $missionId, 'team_id' => $teamId]);
    return null;
}

/**
 * The dialog at play: $mapping is slot id => team id for the slots a phase lacks a team for. Every pair is
 * checked first (the slot and the team are this mission's) and nothing is applied if one is not; then each is
 * applied ONLY to a slot that still stands for no team, so a press that arrives after another coordinator has
 * answered the same question does not overwrite their answer. Null on success, else the error to show.
 */
function mapScenarioSlotsIfFree(array $mission, array $mapping, int $userId): ?string {
    $missionId = (int) $mission['id'];
    $pairs = [];
    foreach ($mapping as $slotId => $teamId) {
        $slotId = (int) $slotId;
        $teamId = (int) $teamId;
        if (!scenarioSlotRow($missionId, $slotId)) {
            return t('scenario.slot_not_found');
        }
        if ($teamId <= 0 || !dbFetchValue("SELECT 1 FROM mission_teams WHERE id = ? AND mission_id = ?", [$teamId, $missionId])) {
            return t('common.team_not_found');
        }
        $pairs[$slotId] = $teamId;
    }
    foreach ($pairs as $slotId => $teamId) {
        $changed = dbExecute(
            "UPDATE mission_scenario_slots s
             LEFT JOIN mission_teams t ON t.id = s.team_id AND t.mission_id = s.mission_id
             SET s.team_id = ?
             WHERE s.id = ? AND s.mission_id = ? AND t.id IS NULL",
            [$teamId, $slotId, $missionId]
        );
        if ($changed) {
            logAudit('map_scenario_slot', 'mission_scenario_slots', $slotId, null, ['mission_id' => $missionId, 'team_id' => $teamId, 'at_play' => true]);
        }
    }
    return null;
}

/** Takes a slot off the plan. Refused while an order that has not been sent goes to it. Null on success. */
function deleteScenarioSlot(array $mission, int $slotId, int $userId): ?string {
    $missionId = (int) $mission['id'];
    if (!scenarioSlotRow($missionId, $slotId)) {
        return t('scenario.slot_not_found');
    }
    if ((int) dbFetchValue("SELECT COUNT(*) FROM mission_scenario_items WHERE slot_id = ? AND sent_at IS NULL", [$slotId]) > 0) {
        return t('scenario.slot_in_use');
    }
    dbExecute("DELETE FROM mission_scenario_slots WHERE id = ? AND mission_id = ?", [$slotId, $missionId]);
    logAudit('delete_scenario_slot', 'mission_scenario_slots', $slotId, null, ['mission_id' => $missionId]);
    return null;
}
