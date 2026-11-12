<?php
/**
 * VolunteerOps - Verified Coverage: GPS ground-truth for search sectors
 * Grid-samples volunteer_pings against each sector polygon to estimate real
 * swept coverage, independent of whatever status a team leader self-reported.
 * Read-only/GET only — see computeMissionSectorCoverage() in
 * functions-warroom.php for the algorithm.
 *
 * Two audiences, deliberately different in how much they see:
 *  - Command staff (canManageActionRoom, same gate as mission-track.php):
 *    every sector + the 4 LPB search rings (if enabled) — full decision
 *    support, unchanged since this endpoint was built.
 *  - Anyone else, with ?sector_id=: the AR ground-paint layer
 *    (assets/js/war-room-ar.js) for the ONE sector their own team is
 *    currently working, nothing else — no other team's progress, no rings.
 *    "Their own team's sector" is mission_search_sectors.team_id, the same
 *    assignment the dispatch/order system itself already uses; a volunteer
 *    off that team, or asking for a sector that isn't theirs, gets the same
 *    refusal an outsider would. computeMissionSectorCoverage() still grids
 *    every sector of the mission (sector counts are small; reusing the exact
 *    tested function beats a second, narrower implementation that could
 *    silently drift from it) — this just returns the one entry asked for.
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/lpb-rings.php';
requireLogin();

header('Content-Type: application/json');

$userId = getCurrentUserId();
$missionId = (int) get('mission_id');

$mission = dbFetchOne(
    "SELECT id, status, show_in_ops, responsible_user_id FROM missions WHERE id = ? AND deleted_at IS NULL",
    [$missionId]
);
if (!$mission || $mission['status'] !== STATUS_OPEN || empty($mission['show_in_ops'])) {
    echo json_encode(['ok' => false, 'error' => t('common.mission_not_found_or_inactive')]);
    exit;
}

$canManageWarRoom = canManageActionRoom($mission['responsible_user_id'] ? (int) $mission['responsible_user_id'] : null, $userId);

if (!$canManageWarRoom) {
    $sectorId = (int) get('sector_id');
    if (!$sectorId || !canViewOwnSectorCoverage($missionId, $sectorId, $userId)) {
        echo json_encode(['ok' => false, 'error' => t('sector.no_manage_permission')]);
        exit;
    }
    $mine = computeMissionSectorCoverage($missionId)[$sectorId] ?? null;
    echo json_encode(['ok' => true, 'coverage' => $mine ? [$sectorId => $mine] : [], 'rings' => []]);
    exit;
}

echo json_encode([
    'ok' => true,
    'coverage' => computeMissionSectorCoverage($missionId),
    // Always present, even as [] when the feature's off, so the client never
    // has to special-case a missing key.
    'rings' => getSetting('search_rings_enabled', '0') === '1' ? computeMissionRingCoverage($missionId) : [],
]);
