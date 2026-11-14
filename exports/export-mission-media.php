<?php
/**
 * VolunteerOps - Action Room field media export (ZIP)
 *
 * Bundles every field photo/video a mission's War Room has collected into one
 * ZIP, named after the mission and its day, so command staff can hand the
 * whole set to the family/authorities or archive it without opening each
 * item one at a time from the gallery. Command-staff only ($canManageWarRoom,
 * same gate exports/export-mission-geo.php uses) - not every approved
 * participant, since this is a bulk pull of everyone's field media, including
 * anything flagged as sensitive enough to need staff-only handling elsewhere
 * in the Action Room.
 *
 * Only the full files (mission_photos.stored_name) go in - thumbnails/poster
 * frames exist purely to make the on-screen gallery light to load and have
 * no value once the real file is in hand.
 *
 * URL: /exports/export-mission-media.php?mission_id=N
 */

require_once __DIR__ . '/../bootstrap.php';
requireLogin();

$missionId = (int) get('mission_id');
if (!$missionId) {
    setFlash('error', t('common.mission_not_found'));
    redirect('dashboard.php');
}

$user = getCurrentUser();
$mission = dbFetchOne(
    "SELECT id, title, status, show_in_ops, responsible_user_id, start_datetime FROM missions WHERE id = ? AND deleted_at IS NULL",
    [$missionId]
);
// Same status window as mission-photo-view.php: a closed/completed mission's
// media is still a legitimate archive pull, just no longer gated by the
// live-ops visibility toggle.
if (!$mission || !in_array($mission['status'], [STATUS_OPEN, STATUS_CLOSED, STATUS_COMPLETED], true)) {
    setFlash('error', t('common.mission_not_found'));
    redirect('dashboard.php');
}
if ($mission['status'] === STATUS_OPEN && empty($mission['show_in_ops'])) {
    setFlash('error', t('common.mission_not_found'));
    redirect('dashboard.php');
}

$canManageWarRoom = canManageActionRoom($mission['responsible_user_id'] ? (int) $mission['responsible_user_id'] : null, (int) $user['id']);
if (!$canManageWarRoom) {
    setFlash('error', t('wr.access_denied'));
    redirect('war-room.php?id=' . $missionId);
}

if (!class_exists('ZipArchive')) {
    setFlash('error', t('media.download_all_zip_unavailable'));
    redirect('war-room.php?id=' . $missionId);
}

$rows = dbFetchAll(
    "SELECT ph.id, ph.stored_name, ph.original_name, ph.file_purged_at, ph.created_at, u.name AS user_name
     FROM mission_photos ph JOIN users u ON u.id = ph.user_id
     WHERE ph.mission_id = ? ORDER BY ph.created_at, ph.id",
    [$missionId]
);

$mediaDir = realpath(__DIR__ . '/../uploads/mission-photos') . DIRECTORY_SEPARATOR;

$seq = 0;
$entries = []; // [diskPath, entryName]
foreach ($rows as $row) {
    // Purged by the video retention sweep (purgeExpiredMissionVideos()) - the
    // DB row is kept for the activity feed, the file on disk is gone.
    if (!empty($row['file_purged_at'])) {
        continue;
    }
    $diskPath = $mediaDir . basename($row['stored_name']);
    if (!is_file($diskPath) || !is_readable($diskPath)) {
        continue;
    }
    $seq++;
    $ext = strtolower(pathinfo($row['stored_name'], PATHINFO_EXTENSION)) ?: 'bin';
    // Kept as UTF-8 rather than transliterated: ASCII//TRANSLIT has no Greek
    // mapping, so every sender name here would collapse to the same "user"
    // fallback - exactly the distinction this filename exists to preserve.
    // ZipArchive marks the UTF-8 flag on any non-ASCII entry name itself, and
    // every modern unzip tool (Explorer, 7-Zip, macOS Archive Utility) reads
    // it correctly; only the characters a filesystem actually forbids are
    // stripped.
    $senderSlug = preg_replace('/[\/\\\\:*?"<>|]+/u', '_', trim((string) $row['user_name']));
    $senderSlug = trim(preg_replace('/\s+/u', '_', (string) $senderSlug), '_ ');
    if ($senderSlug === '' || !mb_check_encoding($senderSlug, 'UTF-8')) {
        $senderSlug = 'user';
    } elseif (mb_strlen($senderSlug, 'UTF-8') > 40) {
        $senderSlug = mb_substr($senderSlug, 0, 40, 'UTF-8');
    }
    $whenSlug = date('Y-m-d_Hi', strtotime((string) $row['created_at']));
    $entries[] = [$diskPath, sprintf('%03d', $seq) . '_' . $whenSlug . '_' . $senderSlug . '.' . $ext];
}

if (empty($entries)) {
    setFlash('error', t('media.download_all_empty'));
    redirect('war-room.php?id=' . $missionId);
}

$zipFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'vomedia_' . $missionId . '_' . bin2hex(random_bytes(4)) . '.zip';

$zip = new ZipArchive();
if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    setFlash('error', t('media.download_all_zip_unavailable'));
    redirect('war-room.php?id=' . $missionId);
}

set_time_limit(300); // A mission's whole media set can run into several hundred MB of video.
foreach ($entries as [$diskPath, $entryName]) {
    $zip->addFile($diskPath, $entryName);
}
$zip->close();

logAudit('download_mission_media_zip', 'missions', $missionId, null, ['mission_id' => $missionId, 'count' => count($entries)]);

$dayStr = date('Y-m-d', strtotime((string) $mission['start_datetime']));
$asciiSlug = trim(preg_replace('/[^A-Za-z0-9]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string) $mission['title'])), '_') ?: 'mission';
$fallbackName = 'media_' . $asciiSlug . '_' . $dayStr . '.zip';
$utf8Name = t('media.download_all_file_prefix') . '_' . $mission['title'] . '_' . $dayStr . '.zip';

if (ob_get_level()) {
    ob_end_clean();
}
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $fallbackName . '"; filename*=UTF-8\'\'' . rawurlencode($utf8Name));
header('Content-Length: ' . filesize($zipFile));
header('Cache-Control: no-cache, no-store, must-revalidate');
readfile($zipFile);
unlink($zipFile);
exit;
