<?php
/**
 * VolunteerOps - Mission Photo/Video Viewer
 * Streams a single War Room field photo or video inline, gated to users who
 * have War Room access to that item's mission. Mirrors volunteer-doc-download.php's
 * secure-serve pattern; videos additionally support HTTP Range requests so
 * <video> seeking/scrubbing works (required by mobile Safari in particular).
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();

$userId = getCurrentUserId();
$photoId = (int) get('id');

$photo = dbFetchOne("SELECT * FROM mission_photos WHERE id = ?", [$photoId]);
if (!$photo) {
    http_response_code(404);
    exit(t('common.not_found'));
}

$mission = dbFetchOne(
    "SELECT id, status, show_in_ops, responsible_user_id FROM missions WHERE id = ? AND deleted_at IS NULL",
    [$photo['mission_id']]
);
// Allow CLOSED and COMPLETED too (not just OPEN) so the mission-report-print.php
// archival export and mission-stats.php's own gallery can still embed photos
// after a mission closes — the permission check right below is unaffected,
// this only extends *when*, not *who*. COMPLETED specifically matters here:
// submitting a debrief (mission-debrief.php) auto-transitions a mission
// straight to COMPLETED, and a mission with a debrief already on file is
// exactly the kind whose print report/stats recap gets pulled up later.
if (!$mission || !in_array($mission['status'], [STATUS_OPEN, STATUS_CLOSED, STATUS_COMPLETED], true)) {
    http_response_code(404);
    exit(t('common.not_found'));
}
// show_in_ops only matters while the mission is still OPEN and live (same
// gate every other War Room endpoint applies) — it's a "does this currently
// show on the live ops dashboard" toggle, editable at any time via
// mission-form.php with no lock once a mission closes. Requiring it here too
// for CLOSED/COMPLETED would mean unchecking that box on an old finished
// mission (nothing stops an admin from doing so) silently 404s every photo/
// video already captured, including the gallery thumbnails embedded in the
// stats recap and PDF report above — media that has nothing to do with
// whether the mission is still being live-tracked.
if ($mission['status'] === STATUS_OPEN && empty($mission['show_in_ops'])) {
    http_response_code(404);
    exit(t('common.not_found'));
}

$canManageWarRoom = canManageActionRoom($mission['responsible_user_id'] ? (int)$mission['responsible_user_id'] : null, (int)$userId);
$isApprovedParticipant = (bool) dbFetchValue(
    "SELECT COUNT(*) FROM participation_requests pr
     JOIN shifts s ON s.id = pr.shift_id
     WHERE s.mission_id = ? AND pr.volunteer_id = ? AND pr.status = ?",
    [$mission['id'], $userId, PARTICIPATION_APPROVED]
);
if (!$canManageWarRoom && !$isApprovedParticipant) {
    http_response_code(403);
    exit(t('common.no_access'));
}

// Client-generated poster frame for a video (see mission-photo.php's
// 'upload' action) — same permission gates as the real media above, just a
// different, much smaller file. No Range support needed, it's a single JPEG.
if (get('thumb') === '1') {
    if (empty($photo['thumb_stored_name'])) {
        http_response_code(404);
        exit(t('media.file_not_found_on_disk'));
    }
    $thumbPath = __DIR__ . '/uploads/mission-photos/' . basename($photo['thumb_stored_name']);
    if (!is_file($thumbPath) || !is_readable($thumbPath)) {
        http_response_code(404);
        exit(t('media.file_not_found_on_disk'));
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: image/jpeg');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store, max-age=0');
    header('Content-Length: ' . filesize($thumbPath));
    readfile($thumbPath);
    exit;
}

$filePath = __DIR__ . '/uploads/mission-photos/' . basename($photo['stored_name']);
if (!is_file($filePath) || !is_readable($filePath)) {
    http_response_code(404);
    exit(t('media.file_not_found_on_disk'));
}

// Trust the actual file contents rather than the MIME value stored in the DB.
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($filePath) ?: 'application/octet-stream';
$allowedMimes = [
    'image/jpeg', 'image/png', 'image/gif', 'image/webp',
    'video/mp4', 'video/webm', 'video/quicktime', 'video/x-m4v',
];
if (!in_array($mime, $allowedMimes, true)) {
    http_response_code(415);
    exit(t('media.unsupported_file_type'));
}

while (ob_get_level() > 0) {
    ob_end_clean();
}

$fileSize = filesize($filePath);
$downloadName = preg_replace('/[^A-Za-z0-9._-]/', '_', basename((string)($photo['original_name'] ?: $photo['stored_name'])));

header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline; filename="' . $downloadName . '"');
header('Cache-Control: private, no-store, max-age=0');
header('Accept-Ranges: bytes');

// Videos need Range support for seeking/scrubbing — mobile Safari refuses
// to play otherwise. Photos never send a Range header so this is a no-op for them.
$rangeHeader = $_SERVER['HTTP_RANGE'] ?? null;
if ($rangeHeader && preg_match('/bytes=(\d*)-(\d*)/', $rangeHeader, $matches)) {
    $start = $matches[1] === '' ? 0 : (int) $matches[1];
    $end = $matches[2] === '' ? $fileSize - 1 : (int) $matches[2];
    $end = min($end, $fileSize - 1);

    if ($start > $end || $start >= $fileSize) {
        http_response_code(416);
        header('Content-Range: bytes */' . $fileSize);
        exit;
    }

    http_response_code(206);
    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $fileSize);
    header('Content-Length: ' . ($end - $start + 1));

    $handle = fopen($filePath, 'rb');
    fseek($handle, $start);
    $remaining = $end - $start + 1;
    while ($remaining > 0 && !feof($handle)) {
        $chunk = min(8192, $remaining);
        echo fread($handle, $chunk);
        $remaining -= $chunk;
        flush();
    }
    fclose($handle);
    exit;
}

header('Content-Length: ' . $fileSize);
readfile($filePath);
exit;
