<?php
/**
 * VolunteerOps - Mission Activity ("Δραστηριότητα") CSV export
 * Archival export of a mission's full Activity feed — reuses the exact same
 * loadMissionActivityEventsForReport() union query the on-screen War Room
 * card and the print/response reports already share, so this can never
 * silently disagree with what's shown live. Gate mirrors war-room.php's own
 * view access exactly (command staff OR approved participant) since the
 * feed itself is already visible to both there — this only lets someone
 * download what they can already see on screen.
 */

require_once __DIR__ . '/../bootstrap.php';
requireLogin();

$missionId = (int) get('mission_id');
if (!$missionId) {
    setFlash('error', t('common.mission_not_found'));
    redirect('dashboard.php');
}

$user = getCurrentUser();
$mission = dbFetchOne("SELECT * FROM missions WHERE id = ? AND deleted_at IS NULL", [$missionId]);
if (!$mission) {
    setFlash('error', t('common.mission_not_found'));
    redirect('dashboard.php');
}

$canManageWarRoom = canManageActionRoom($mission['responsible_user_id'] ? (int) $mission['responsible_user_id'] : null, (int) $user['id']);
$isApprovedParticipant = (bool) dbFetchValue(
    "SELECT COUNT(*) FROM participation_requests pr
     JOIN shifts s ON s.id = pr.shift_id
     WHERE s.mission_id = ? AND pr.volunteer_id = ? AND pr.status = ?",
    [$missionId, $user['id'], PARTICIPATION_APPROVED]
);
if (!$canManageWarRoom && !$isApprovedParticipant) {
    setFlash('error', t('wr.access_denied'));
    redirect('dashboard.php');
}

$events = loadMissionActivityEventsForReport($missionId);
// The live feed shows newest-first; a downloaded archive reads more
// naturally as a chronological log, oldest first.
usort($events, fn($a, $b) => $a['ts'] <=> $b['ts']);

if (ob_get_level()) ob_end_clean();

$dateStr = date('Y-m-d');
// html_entity_decode: loadMissionActivityEventsForReport()'s text is
// pre-escaped with h() for safe on-screen HTML rendering (its main
// consumer) — decode back to plain text here so a literal "&" doesn't show
// up as "&amp;" in a spreadsheet cell.
$asciiSlug = preg_replace('/[^A-Za-z0-9]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $mission['title']));
$asciiSlug = trim($asciiSlug, '_') ?: 'mission';
$fallbackName = 'drastiriotita_' . $asciiSlug . '_' . $dateStr . '.csv';
$utf8Name = 'Δραστηριότητα_' . $mission['title'] . '_' . $dateStr . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $fallbackName . '"; filename*=UTF-8\'\'' . rawurlencode($utf8Name));

$out = fopen('php://output', 'w');
fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

fputcsv($out, ['Ημερομηνία/Ώρα', 'Γεγονός']);
foreach ($events as $event) {
    fputcsv($out, [
        date('d/m/Y H:i:s', $event['ts']),
        $event['icon'] . ' ' . html_entity_decode(strip_tags($event['text']), ENT_QUOTES, 'UTF-8'),
    ]);
}

fclose($out);
exit;
