<?php
/**
 * VolunteerOps - Mission Highlight Video Endpoint
 *
 * Stores/serves-status-for/deletes the one generated highlight video a mission
 * can have (mission_highlight_videos, UNIQUE on mission_id - a new upload
 * always replaces the previous one, via saveMissionHighlightVideo()).
 *
 * The video itself is rendered entirely in the admin's browser (Canvas +
 * MediaRecorder - no ffmpeg/exec on the server, see assets/js/mission-highlight-video.js)
 * and handed to this endpoint as a finished blob - MP4 where the browser's
 * MediaRecorder could produce it, WebM otherwise. This file only stores it
 * and answers status/delete - it does no rendering of its own.
 *
 * action=status (GET) - anyone with $canManageWarRoom (same tier as the media
 *   zip download) may check/see the current video.
 * action=upload (POST, multipart) and action=delete (POST) - system admins
 *   only ($canManageWarRoom && isSystemAdmin(), mirroring war-room.php's
 *   war-room-scenario.js gate), since generating/replacing/removing it is the
 *   locked-in admin-only action, not just viewing it.
 *
 * POST only for writes, AJAX/multipart.
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();

header('Content-Type: application/json');

$userId = getCurrentUserId();
$action = get('action') ?: post('action');
$readActions = ['status', 'media-list'];
$missionId = in_array($action, $readActions, true) ? (int) get('mission_id') : (int) post('mission_id');

$mission = dbFetchOne(
    "SELECT id, title, status, show_in_ops, responsible_user_id FROM missions WHERE id = ? AND deleted_at IS NULL",
    [$missionId]
);
// Same status window as mission-photo-view.php / export-mission-media.php: a
// closed/completed mission's media (and now its highlight video) is still a
// legitimate archive pull, just no longer gated by the live-ops toggle.
if (!$mission || !in_array($mission['status'], [STATUS_OPEN, STATUS_CLOSED, STATUS_COMPLETED], true)) {
    echo json_encode(['ok' => false, 'error' => t('common.mission_not_found_or_inactive')]);
    exit;
}
if ($mission['status'] === STATUS_OPEN && empty($mission['show_in_ops'])) {
    echo json_encode(['ok' => false, 'error' => t('common.mission_not_found_or_inactive')]);
    exit;
}

$canManageWarRoom = canManageActionRoom($mission['responsible_user_id'] ? (int) $mission['responsible_user_id'] : null, $userId);
if (!$canManageWarRoom) {
    echo json_encode(['ok' => false, 'error' => t('wr.access_denied')]);
    exit;
}

if ($action === 'status') {
    $video = loadMissionHighlightVideo($missionId);
    echo json_encode([
        'ok'    => true,
        'video' => $video ? [
            'id'              => (int) $video['id'],
            'orientation'     => $video['orientation'],
            'music_track_id'  => $video['music_track_id'],
            'created_at'      => $video['created_at'],
            'created_by_name' => $video['created_by_name'],
            'view_url'        => rtrim(BASE_URL, '/') . '/mission-highlight-video-view.php?mission_id=' . $missionId,
        ] : null,
        'canGenerate' => isSystemAdmin(),
    ]);
    exit;
}

if ($action === 'media-list') {
    // Reuses the archive's full media loader for both pages (live or closed/
    // completed) rather than mission-photo.php's narrower "last 30" live
    // gallery query - the modal should be able to pick from everything, same
    // as the zip download already does.
    $items = array_values(array_filter(
        array_map(function ($m) use ($missionId) {
            if ($m['purged']) {
                return null; // its file is gone, nothing to render from
            }
            return [
                'id'        => $m['id'],
                'type'      => $m['media_type'],
                'label'     => trim(implode(' · ', array_filter([$m['team_label'], $m['user_name'], $m['time']]))),
                'thumb_url' => rtrim(BASE_URL, '/') . '/mission-photo-view.php?id=' . $m['id'] . '&thumb=1',
                'full_url'  => rtrim(BASE_URL, '/') . '/mission-photo-view.php?id=' . $m['id'],
            ];
        }, loadMissionMediaForArchive($missionId))
    ));
    echo json_encode(['ok' => true, 'items' => $items]);
    exit;
}

// Every action below writes - admin-only, and CSRF-checked.
if (!isSystemAdmin()) {
    echo json_encode(['ok' => false, 'error' => t('wr.access_denied')]);
    exit;
}
if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string) $_POST['csrf_token'])) {
    echo json_encode(['ok' => false, 'csrf' => true, 'error' => t('common.invalid_request')]);
    exit;
}

if ($action === 'delete') {
    $existing = loadMissionHighlightVideo($missionId);
    if ($existing) {
        $path = __DIR__ . '/uploads/mission-highlight-videos/' . basename((string) $existing['stored_name']);
        if (is_file($path)) {
            @unlink($path);
        }
        dbExecute("DELETE FROM mission_highlight_videos WHERE mission_id = ?", [$missionId]);
    }
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'upload') {
    if (empty($_FILES['video']['name']) || $_FILES['video']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['ok' => false, 'error' => t('video.select_file')]);
        exit;
    }

    $orientation = post('orientation') === 'portrait' ? 'portrait' : 'landscape';
    $musicTrackId = trim((string) post('music_track_id'));
    if ($musicTrackId !== '' && !preg_match('/^[a-z0-9_-]{1,50}$/i', $musicTrackId)) {
        $musicTrackId = null;
    } elseif ($musicTrackId === '') {
        $musicTrackId = null;
    }

    $file = $_FILES['video'];
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    // The renderer tries MP4 (H.264/AAC) first where the browser's
    // MediaRecorder supports it - far better native playback elsewhere
    // (iOS, WhatsApp, Windows' own player) than WebM - falling back to WebM
    // where it doesn't (see assets/js/mission-highlight-video.js's pickMimeType()).
    $extensionByMime = ['video/webm' => 'webm', 'video/mp4' => 'mp4'];
    if (!isset($extensionByMime[$mime])) {
        echo json_encode(['ok' => false, 'error' => t('video.invalid_type')]);
        exit;
    }
    if ($file['size'] > HIGHLIGHT_VIDEO_MAX_SIZE) {
        echo json_encode(['ok' => false, 'error' => t('video.file_too_large', ['size' => HIGHLIGHT_VIDEO_MAX_SIZE / 1024 / 1024])]);
        exit;
    }

    // Creates the directory when missing and drops the deny-all .htaccess
    // beside these files, same as mission-photo.php's uploads/mission-photos/.
    $destDir = __DIR__ . '/uploads/mission-highlight-videos/';
    ensurePrivateUploadDir($destDir);
    $storedName = 'mhvideo_' . $missionId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $extensionByMime[$mime];
    if (!move_uploaded_file($file['tmp_name'], $destDir . $storedName)) {
        echo json_encode(['ok' => false, 'error' => t('video.save_failed')]);
        exit;
    }

    saveMissionHighlightVideo($missionId, $userId, $storedName, $orientation, $musicTrackId);
    logAudit('generate_mission_highlight_video', 'missions', $missionId, null, ['mission_id' => $missionId, 'orientation' => $orientation]);

    echo json_encode([
        'ok'       => true,
        'view_url' => rtrim(BASE_URL, '/') . '/mission-highlight-video-view.php?mission_id=' . $missionId,
    ]);
    exit;
}

echo json_encode(['ok' => false, 'error' => t('common.invalid_request')]);
