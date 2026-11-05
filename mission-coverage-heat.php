<?php
/**
 * VolunteerOps - The heat layer of the «Πίνακας» console look: where the teams have been, and for how long.
 * GET only, AJAX, read-only. Command staff only, the same gate as mission-sector-coverage.php (the GPS
 * trails it is made of are command's, like the team trail's). includes/functions-coverage-heat.php says
 * what is counted and why.
 *
 * The page asks every half minute while the layer is on and says which pings it already has the picture
 * of (`sig`): when nothing new has arrived the answer is one short line and the heavy part is not done.
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();

header('Content-Type: application/json');
header('Cache-Control: no-store');

$userId = (int) getCurrentUserId();
$missionId = (int) get('mission_id');

$mission = dbFetchOne(
    "SELECT id, status, show_in_ops, responsible_user_id FROM missions WHERE id = ? AND deleted_at IS NULL",
    [$missionId]
);
if (!$mission || $mission['status'] !== STATUS_OPEN || empty($mission['show_in_ops'])) {
    echo json_encode(['ok' => false, 'error' => t('common.mission_not_found_or_inactive')]);
    exit;
}

if (!canManageActionRoom($mission['responsible_user_id'] ? (int) $mission['responsible_user_id'] : null, $userId)) {
    echo json_encode(['ok' => false, 'error' => t('sector.no_manage_permission')]);
    exit;
}

// Everything above needed the session; the rest can take a second on a big mission and must not hold its
// lock (every other request of this browser, the 5 s poll included, would queue behind it).
session_write_close();

// `by=team` (v3.440.0): the same ground kept apart per team, for the «Κάλυψη ομάδων» layer of the
// layers menu, which every skin has. Same gate, same «anything new?» answer.
$byTeam = (string) get('by') === 'team';
$sig = $byTeam ? coverageTeamSignature($missionId) : coverageHeatSignature($missionId);
if ($sig === (string) get('sig')) {
    echo json_encode(['ok' => true, 'unchanged' => true, 'sig' => $sig]);
    exit;
}

echo json_encode(['ok' => true] + ($byTeam ? computeMissionTeamCoverage($missionId) : computeMissionCoverageHeat($missionId)));
