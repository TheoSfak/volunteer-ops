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

// Releases the PHP session file lock immediately — nothing below this point
// writes to $_SESSION (reads still work after the close, and the two writes
// this request makes, last_activity and war_room_at, both happen inside
// bootstrap.php's session start, above).
//
// Without it, PHP's default session handler holds an exclusive lock on this
// session's file for the whole request, so the browser's parallel image
// requests do not actually run in parallel: they queue behind each other, one
// at a time, for the full duration of each. Proven with a probe that slept
// 0,7s inside the lock, four requests fired together: 0,77s / 1,46s / 2,15s /
// 2,84s — a perfect 0,7s staircase. An Action Room gallery is up to 30 items
// (loadMissionPhotosForUser()'s LIMIT) and every one of them is one of these
// requests.
//
// On its own this is worth little: measured over 24 requests it moved the wall
// clock from 534ms to 510ms, because the lock is only held for bootstrap plus
// the login check, and the real cost was always the re-download that the
// caching below now removes. It matters for what is left — a viewer whose
// cache has expired, or a first load — where the remaining requests can now
// overlap instead of forming that staircase.
session_write_close();

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

/**
 * Cache validators for access-gated but immutable media. Called only after
 * every permission gate above has passed, so nothing here can confirm the
 * existence of an item to someone who may not see it.
 *
 * This endpoint used to send `Cache-Control: private, no-store, max-age=0`,
 * which meant a browser kept nothing at all: every Action Room load
 * re-downloaded the entire gallery from scratch, each item a full PHP request
 * (bootstrap + login check + 3 queries + finfo, measured at 51ms against 6ms
 * for the same bytes served statically by Apache). Issuing an order is a plain
 * form POST that redirects back — a full page reload — so the whole
 * re-download ran again on every single command the coordinator sent.
 *
 * The bytes behind an id never change: stored_name is written once at upload
 * and mission-photo.php has no edit path, only delete, and ids are never
 * reused. So the browser can simply keep them.
 *   - `private` keeps it out of every shared/proxy cache.
 *   - `Vary: Cookie` keys the entry to the session that was authorised for it,
 *     so a different login on the same browser profile revalidates rather than
 *     being served from cache. Costs one re-fetch per login, and one if the
 *     viewer toggles Field Mode (wr_field_mode is a cookie too) — which hides
 *     the media card anyway.
 *   - The ETag covers the thumb/full split, since both are served off one id.
 *
 * Range requests are deliberately exempt from the 304: a browser scrubbing a
 * video sends If-Range, not If-None-Match, and answering a ranged request with
 * 304 would break seeking — the exact thing the Range support below exists for.
 */
$emitCacheHeaders = function (string $path, bool $isThumb) use ($photoId) {
    $mtime = filemtime($path);
    $etag = '"' . $photoId . ($isThumb ? 't' : 'f') . '-' . filesize($path) . '-' . $mtime . '"';

    // session_start() (inside bootstrap.php) applies PHP's default
    // session.cache_limiter, which stamps every response with
    //     Expires: Thu, 19 Nov 1981 08:52:00 GMT
    //     Pragma: no-cache
    // Overwriting Cache-Control below does NOT remove those two, and a
    // response that says max-age=86400 while also carrying a 1981 Expires and
    // Pragma: no-cache is self-contradictory — Chrome resolves it
    // conservatively and revalidates. Measured with them still present: all 31
    // gallery items sent a conditional request and got 304s on a plain
    // navigation, instead of being read straight out of the cache.
    //
    // Dropped here, per response, rather than by changing the cache limiter at
    // session_start() — that would change the caching posture of every page in
    // the app, and no other page has established that its output is safe to
    // keep. This one has: see the docblock above.
    header_remove('Expires');
    header_remove('Pragma');

    header('Cache-Control: private, max-age=86400');
    header('Vary: Cookie');
    header('ETag: ' . $etag);
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');

    if (isset($_SERVER['HTTP_RANGE'])) {
        return;
    }
    foreach (explode(',', (string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) as $candidate) {
        // Strips a weak-validator prefix if the client sent one; the tag
        // itself starts with a quote, so this never eats into it.
        if (ltrim(trim($candidate), 'W/') === $etag) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            http_response_code(304);
            exit;
        }
    }
};

// The small JPEG behind a tile — a video's client-captured poster frame, or a
// photo's thumbnail, which ensureMissionPhotoThumbnail() generates on the
// first request for it. Same permission gates as the real media above, just a
// different, much smaller file. No Range support needed, it's a single JPEG.
if (get('thumb') === '1') {
    $thumbPath = ensureMissionPhotoThumbnail($photo);
    if ($thumbPath !== null) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        header('Content-Type: image/jpeg');
        header('X-Content-Type-Options: nosniff');
        $emitCacheHeaders($thumbPath, true);
        header('Content-Length: ' . filesize($thumbPath));
        readfile($thumbPath);
        exit;
    }
    // A video whose uploader never sent a poster frame keeps the old 404: the
    // caller is a <video poster>, and answering it with the video itself would
    // download the very thing the poster exists to avoid.
    if ($photo['media_type'] !== 'photo') {
        http_response_code(404);
        exit(t('media.file_not_found_on_disk'));
    }
    // A photo falls through to the full image below instead, so a host without
    // GD, or an image GD cannot read, costs bandwidth rather than leaving a
    // broken tile in the gallery.
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
$emitCacheHeaders($filePath, false);
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
