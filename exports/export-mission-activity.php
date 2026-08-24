<?php
/**
 * VolunteerOps - Mission Activity ("Δραστηριότητα") CSV export
 * Archival export of a mission's full Activity feed — reuses the exact same
 * loadMissionActivityEventsForReport() union query the on-screen War Room
 * card and the print/response reports already share, so this can never
 * silently disagree with what's shown live.
 *
 * Gate mirrors war-room.php's own view access (command staff OR approved
 * participant), because the feed itself is visible to both there. But the
 * gate alone is only half of "download what you can already see": WHO may
 * open this page and WHICH EVENTS they get are two separate questions, and
 * for a long time this file only answered the first. It called
 * loadMissionActivityEventsForReport(), which by design has no per-viewer
 * team-scoping, while the live feed (mission-history.php) applies three
 * scoping predicates — so an approved volunteer could download roughly 80
 * events they could not see on screen on a real mission, including other
 * teams' GPS pings and field-status changes.
 *
 * Both questions are answered here now. Command staff still get the full
 * uncapped archive; anyone else gets exactly the scoped feed they see live,
 * because the loader is handed their user id and applies the very same
 * predicate fragments mission-history.php does (they are shared functions in
 * includes/functions-warroom.php, not two copies). Staff-only activity notes
 * were always filtered correctly and still are, via the second argument.
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

// Both arguments are the non-staff-safe value for an approved participant,
// and both matter (see the docblock): $canManageWarRoom keeps staff-only
// notes out of their copy, and the viewer id makes every other source obey
// the same team-scoping the live feed applies. Command staff pass null and
// get the unscoped archive, which is the whole point of the export for them.
$events = loadMissionActivityEventsForReport(
    $missionId,
    $canManageWarRoom,
    $canManageWarRoom ? null : (int) $user['id']
);
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

fputcsvSafe($out, ['Ημερομηνία/Ώρα', 'Γεγονός']);
foreach ($events as $event) {
    fputcsvSafe($out, [
        date('d/m/Y H:i:s', $event['ts']),
        $event['icon'] . ' ' . html_entity_decode(strip_tags($event['text']), ENT_QUOTES, 'UTF-8'),
    ]);
}

fclose($out);
exit;
