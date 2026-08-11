<?php
/**
 * VolunteerOps - Verified Coverage: GPS ground-truth for search sectors
 * Grid-samples volunteer_pings against each sector polygon to estimate real
 * swept coverage, independent of whatever status a team leader self-reported.
 * Read-only/GET only — see computeMissionSectorCoverage() in
 * functions-warroom.php for the algorithm. Admin-only decision support, same
 * gate as mission-track.php.
 * Also returns the same estimate for the 4 LPB search rings (if enabled) —
 * see computeMissionRingCoverage(), same file. Kept in this one endpoint
 * rather than a separate file since it's the same feature applied to a
 * second shape type, not a distinct one.
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

if (!canManageActionRoom($mission['responsible_user_id'] ? (int) $mission['responsible_user_id'] : null, $userId)) {
    echo json_encode(['ok' => false, 'error' => t('sector.no_manage_permission')]);
    exit;
}

echo json_encode([
    'ok' => true,
    'coverage' => computeMissionSectorCoverage($missionId),
    // Always present, even as [] when the feature's off, so the client never
    // has to special-case a missing key.
    'rings' => getSetting('search_rings_enabled', '0') === '1' ? computeMissionRingCoverage($missionId) : [],
]);
