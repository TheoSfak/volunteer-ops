<?php
/**
 * VolunteerOps - Mission Highlight Video Viewer
 *
 * Streams the one generated highlight video a mission has (mission_highlight_videos),
 * with HTTP Range support so <video> seeking/scrubbing and the download link both
 * work. Mirrors mission-photo-view.php's secure-serve pattern.
 *
 * Gated to $canManageWarRoom - the same tier that can see the media zip
 * download - not just system admins: generating/replacing it is admin-only
 * (mission-highlight-video.php), but watching/downloading the result is for
 * anyone who already manages this mission's War Room.
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();

$userId = getCurrentUserId();
$missionId = (int) get('mission_id');

// session_write_close(): see mission-photo-view.php for why this matters -
// nothing below writes to $_SESSION.
session_write_close();

$mission = dbFetchOne(
    "SELECT id, status, show_in_ops, responsible_user_id FROM missions WHERE id = ? AND deleted_at IS NULL",
    [$missionId]
);
if (!$mission || !in_array($mission['status'], [STATUS_OPEN, STATUS_CLOSED, STATUS_COMPLETED], true)) {
    http_response_code(404);
    exit(t('common.not_found'));
}
if ($mission['status'] === STATUS_OPEN && empty($mission['show_in_ops'])) {
    http_response_code(404);
    exit(t('common.not_found'));
}

$canManageWarRoom = canManageActionRoom($mission['responsible_user_id'] ? (int) $mission['responsible_user_id'] : null, $userId);
if (!$canManageWarRoom) {
    http_response_code(403);
    exit(t('common.no_access'));
}

$video = loadMissionHighlightVideo($missionId);
if (!$video) {
    http_response_code(404);
    exit(t('media.file_not_found_on_disk'));
}

// Writes the deny-all .htaccess beside these files if it is not there yet -
// same reasoning as mission-photo-view.php.
ensurePrivateUploadDir(__DIR__ . '/uploads/mission-highlight-videos/');

$filePath = __DIR__ . '/uploads/mission-highlight-videos/' . basename((string) $video['stored_name']);
if (!is_file($filePath) || !is_readable($filePath)) {
    http_response_code(404);
    exit(t('media.file_not_found_on_disk'));
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($filePath) ?: 'video/webm';
// MP4 where the browser's MediaRecorder could produce it, WebM otherwise -
// see mission-highlight-video.php's upload handler.
$extensionByMime = ['video/webm' => 'webm', 'video/mp4' => 'mp4'];
if (!isset($extensionByMime[$mime])) {
    http_response_code(415);
    exit(t('media.unsupported_file_type'));
}

$dayStr = date('Y-m-d', strtotime((string) $video['created_at']));
$downloadName = preg_replace('/[^A-Za-z0-9._-]/', '_', 'highlight_' . $missionId . '_' . $dayStr) . '.' . $extensionByMime[$mime];

// Cache validators FIRST, exempt from the 304 on a ranged (scrubbing) request -
// same ordering as mission-photo-view.php, for the same reason.
emitImmutableMediaCacheHeaders($filePath, 'hv' . $video['id']);
streamMediaFileWithRanges($filePath, $mime, $downloadName);
