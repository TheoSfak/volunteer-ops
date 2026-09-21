<?php
/**
 * VolunteerOps — Mission Voice Message Player
 *
 * Streams one push-to-talk emergency clip, gated to people with Action Room
 * access to that clip's mission. Same secure-serve shape as
 * mission-photo-view.php, and it shares that file's two serving helpers
 * (emitImmutableMediaCacheHeaders / streamMediaFileWithRanges) rather than
 * carrying a second copy of them.
 *
 * Range support is load-bearing, not an optimisation: mobile Safari will not
 * play an <audio> element at all from a source that does not answer ranged
 * requests, and the command post runs on whatever is to hand, iPads included.
 *
 * WHO CAN LISTEN. Command staff, and the person who recorded it. Deliberately
 * NOT every approved participant, which is what mission-photo-view.php allows
 * for field media: a photo of a blocked road is shared situational awareness,
 * while a voice message is somebody's own voice, recorded under stress,
 * addressed to command. Widening this later is a decision to take on purpose.
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();

$userId  = getCurrentUserId();
$voiceId = (int) get('id');

// Same reason as mission-photo-view.php: nothing below writes to the session,
// and holding the lock serialises the coordinator's other requests behind this
// one for its whole duration.
session_write_close();

$voice = dbFetchOne("SELECT * FROM mission_voice_messages WHERE id = ?", [$voiceId]);
if (!$voice) {
    http_response_code(404);
    exit(t('common.not_found'));
}

$mission = dbFetchOne(
    "SELECT id, status, show_in_ops, responsible_user_id FROM missions WHERE id = ? AND deleted_at IS NULL",
    [$voice['mission_id']]
);
// CLOSED and COMPLETED stay playable so the mission report and stats recap can
// still reach a clip after the operation ends — same reasoning, and the same
// permission check below either way, as mission-photo-view.php.
if (!$mission || !in_array($mission['status'], [STATUS_OPEN, STATUS_CLOSED, STATUS_COMPLETED], true)) {
    http_response_code(404);
    exit(t('common.not_found'));
}
if ($mission['status'] === STATUS_OPEN && empty($mission['show_in_ops'])) {
    http_response_code(404);
    exit(t('common.not_found'));
}

$canManageWarRoom = canManageActionRoom($mission['responsible_user_id'] ? (int) $mission['responsible_user_id'] : null, (int) $userId);
$isSender = ((int) $voice['user_id'] === (int) $userId);
if (!$canManageWarRoom && !$isSender) {
    http_response_code(403);
    exit(t('common.no_access'));
}

$filePath = __DIR__ . '/uploads/mission-voice/' . basename($voice['stored_name']);
if (!is_file($filePath) || !is_readable($filePath)) {
    http_response_code(404);
    exit(t('media.file_not_found_on_disk'));
}

// Trust the file, not the stored MIME — same rule as the photo viewer. An MP4
// audio clip is routinely sniffed as video/mp4 because the container is
// identical; it is remapped rather than refused, so the browser gets an
// <audio>-friendly type instead of one that makes it expect a picture.
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime  = $finfo->file($filePath) ?: 'application/octet-stream';
$allowedMimes = [
    'audio/mp4', 'audio/x-m4a', 'audio/aac', 'video/mp4',
    'audio/webm', 'video/webm',
    'audio/ogg', 'application/ogg',
];
if (!in_array($mime, $allowedMimes, true)) {
    http_response_code(415);
    exit(t('media.unsupported_file_type'));
}
$mime = ['video/mp4' => 'audio/mp4', 'video/webm' => 'audio/webm', 'application/ogg' => 'audio/ogg'][$mime] ?? $mime;

emitImmutableMediaCacheHeaders($filePath, 'v' . $voiceId);
streamMediaFileWithRanges($filePath, $mime, 'voice-' . $voiceId . '.' . pathinfo($filePath, PATHINFO_EXTENSION));
