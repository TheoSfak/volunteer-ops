<?php
/**
 * VolunteerOps — Mission Voice Message Endpoint
 *
 * The Action Room push-to-talk emergency channel: a volunteer holds one button,
 * speaks, releases, and the clip lands in front of command staff with a siren.
 * One direction only, volunteer → command. POST only, AJAX/multipart.
 *
 * DELIBERATELY INDEPENDENT OF SOS. A voice message does not set field_status to
 * 'needs_help' and does not open a mission_sos_alerts ticket somebody then has
 * to resolve. Two reasons, and both are operational:
 *
 *   · SOS must never depend on microphone permission. If recording were part of
 *     raising an SOS, a denied prompt, a mic held by another app, or an engine
 *     that cannot record at all would silently cost a rescuer their alarm. The
 *     SOS button is untouched by this file for exactly that reason.
 *
 *   · Not every urgent thing worth saying out loud is "I am in danger". A
 *     volunteer reporting a blocked road or a found object needs to be heard
 *     now, without opening a casualty ticket that has to be closed later.
 *
 * The acknowledgement below is not a courtesy. Somebody who has just called for
 * help over a radio expects to hear that the call was received, and this is the
 * only thing that tells them.
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();

header('Content-Type: application/json');

/** Longest clip accepted. The browser stops at 60s; this is the server's own floor under that. */
const VOICE_MAX_SIZE = 8 * 1024 * 1024;

/**
 * Tell command staff a voice message came in. Same recipient resolution and
 * bannerMission treatment as every other loud Action Room event — see
 * notifyCommandStaffBanner()'s docblock in includes/functions-warroom.php.
 */
function notifyVoiceMessageReceived(int $missionId, string $missionTitle, ?int $responsibleUserId, string $senderName, int $senderId): void {
    notifyCommandStaffBanner(
        $missionId, $missionTitle, $responsibleUserId, $senderId,
        'mission_voice_message', 'voice.notify_title', [],
        'voice.notify_message', ['name' => $senderName, 'mission' => $missionTitle]
    );
}

$userId = getCurrentUserId();

if (!isPost()) {
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string) $_POST['csrf_token'])) {
    echo json_encode(['ok' => false, 'error' => t('common.invalid_request')]);
    exit;
}

$action    = post('action');
$missionId = (int) post('mission_id');

$mission = dbFetchOne(
    "SELECT id, title, responsible_user_id FROM missions
     WHERE id = ? AND status = ? AND show_in_ops = 1 AND deleted_at IS NULL",
    [$missionId, STATUS_OPEN]
);
if (!$mission) {
    echo json_encode(['ok' => false, 'error' => t('common.mission_not_found')]);
    exit;
}

$canManageWarRoom = canManageActionRoom($mission['responsible_user_id'] ? (int) $mission['responsible_user_id'] : null, $userId);

// ── Send ────────────────────────────────────────────────────────────────────
if ($action === 'send') {
    // The sender's own approved participation, which is also where pr_id and
    // the shift come from. An admin watching from the command post is not a
    // participant and has no business sending on this channel — it is the
    // field's way of reaching command, not a general intercom.
    $pr = dbFetchOne(
        "SELECT pr.id
         FROM participation_requests pr
         JOIN shifts s ON s.id = pr.shift_id
         WHERE s.mission_id = ? AND pr.volunteer_id = ? AND pr.status = ?
         ORDER BY (s.start_time <= NOW() AND s.end_time > NOW()) DESC, s.start_time DESC
         LIMIT 1",
        [$missionId, $userId, PARTICIPATION_APPROVED]
    );
    if (!$pr) {
        echo json_encode(['ok' => false, 'error' => t('voice.only_approved_can_send')]);
        exit;
    }

    if (empty($_FILES['clip']['name']) || $_FILES['clip']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['ok' => false, 'error' => t('voice.no_clip')]);
        exit;
    }

    $file     = $_FILES['clip'];
    $origName = basename($file['name']);
    $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $mime     = $finfo->file($file['tmp_name']);

    // Extension AND sniffed type must both be on the list, same belt-and-braces
    // shape mission-photo.php uses. The audio containers here are the ones
    // AUDIO_RECORDER_MIME_CANDIDATES (assets/js/war-room-utils.js) can actually
    // produce — keep the two lists in step.
    //
    // An MP4 audio clip is very often sniffed as video/mp4: the container is
    // identical and finfo reports the container, not the track layout. Refusing
    // it would reject every clip Safari records, which is the one engine that
    // has no fallback.
    $allowedExt  = ['m4a', 'mp4', 'webm', 'ogg'];
    $allowedMime = [
        'audio/mp4', 'audio/x-m4a', 'audio/aac', 'video/mp4',
        'audio/webm', 'video/webm',
        'audio/ogg', 'application/ogg',
    ];
    if (!in_array($ext, $allowedExt, true) || !in_array($mime, $allowedMime, true)) {
        echo json_encode(['ok' => false, 'error' => t('voice.invalid_type')]);
        exit;
    }
    if ($file['size'] <= 0 || $file['size'] > VOICE_MAX_SIZE) {
        echo json_encode(['ok' => false, 'error' => t('voice.file_too_large', ['size' => VOICE_MAX_SIZE / 1024 / 1024])]);
        exit;
    }

    $durationRaw = post('duration_ms');
    $durationMs  = ($durationRaw !== '' && $durationRaw !== null && is_numeric($durationRaw))
        ? max(0, min(600000, (int) $durationRaw))
        : null;

    // Same "a bad fix is no fix, never a reason to reject" rule as
    // volunteer-status.php's SOS path. A voice call must go out even when the
    // GPS is wrong or absent.
    $latRaw = post('lat');
    $lngRaw = post('lng');
    $lat = ($latRaw !== '' && $latRaw !== null && is_numeric($latRaw)) ? (float) $latRaw : null;
    $lng = ($lngRaw !== '' && $lngRaw !== null && is_numeric($lngRaw)) ? (float) $lngRaw : null;
    if ($lat !== null && ($lat < -90 || $lat > 90)) { $lat = null; }
    if ($lng !== null && ($lng < -180 || $lng > 180)) { $lng = null; }
    if ($lat === 0.0 && $lng === 0.0) { $lat = null; $lng = null; }

    $destDir = __DIR__ . '/uploads/mission-voice/';
    if (!is_dir($destDir)) {
        mkdir($destDir, 0755, true);
    }
    // Written from here rather than shipped in the repo because uploads/ is
    // gitignored, so a committed file would never reach a deployment.
    //
    // These clips are people's voices, recorded under stress, and the
    // permission gate that decides who may hear one lives in
    // mission-voice-play.php. A file Apache will serve directly is a file that
    // never reaches that gate — the stored name is unguessable, but obscurity
    // is not the access control this deserves. (uploads/mission-photos/ has no
    // such rule and IS directly fetchable; that is pre-existing and deliberately
    // not changed here, where it would silently break every gallery URL.)
    $guard = $destDir . '.htaccess';
    if (!is_file($guard)) {
        file_put_contents($guard, "Require all denied\n<IfModule !mod_authz_core.c>\n    Order Deny,Allow\n    Deny from all\n</IfModule>\n");
    }
    $storedName = 'mvoice_' . $missionId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $destDir . $storedName)) {
        echo json_encode(['ok' => false, 'error' => t('voice.save_failed')]);
        exit;
    }

    $voiceId = dbInsert(
        "INSERT INTO mission_voice_messages
            (mission_id, user_id, pr_id, team_id, stored_name, mime_type, file_size, duration_ms, lat, lng, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
        [
            $missionId, $userId, (int) $pr['id'], getUserTeamIdForMission($missionId, $userId),
            $storedName, $mime, (int) $file['size'], $durationMs, $lat, $lng,
        ]
    );
    logAudit('send_mission_voice_message', 'mission_voice_messages', $voiceId, null, [
        'mission_id' => $missionId, 'duration_ms' => $durationMs, 'file_size' => (int) $file['size'],
    ]);

    notifyVoiceMessageReceived(
        $missionId, $mission['title'],
        $mission['responsible_user_id'] ? (int) $mission['responsible_user_id'] : null,
        getCurrentUser()['name'] ?? '', $userId
    );

    echo json_encode(['ok' => true, 'id' => (int) $voiceId]);
    exit;
}

// ── Acknowledge ─────────────────────────────────────────────────────────────
if ($action === 'acknowledge') {
    if (!$canManageWarRoom) {
        echo json_encode(['ok' => false, 'error' => t('voice.no_manage_permission')]);
        exit;
    }

    $voiceId = (int) post('id');
    $voice = dbFetchOne(
        "SELECT id, user_id, acknowledged_at FROM mission_voice_messages WHERE id = ? AND mission_id = ?",
        [$voiceId, $missionId]
    );
    if (!$voice) {
        echo json_encode(['ok' => false, 'error' => t('voice.not_found')]);
        exit;
    }

    if (!$voice['acknowledged_at']) {
        dbExecute(
            "UPDATE mission_voice_messages SET acknowledged_at = NOW(), acknowledged_by = ? WHERE id = ?",
            [$userId, $voiceId]
        );
        logAudit('acknowledge_mission_voice_message', 'mission_voice_messages', $voiceId, null, ['mission_id' => $missionId]);

        // Back to the person who called, and this is the point of the whole
        // acknowledgement: somebody who has just spoken into a handset needs to
        // know a human heard it. Not notifyCommandStaffBanner() — this goes one
        // way, to the sender, exactly like the battery-alert receipt does.
        $senderId = (int) $voice['user_id'];
        if ($senderId !== $userId) {
            $senderLang = getUserLanguages([$senderId])[$senderId] ?? DEFAULT_LANGUAGE;
            sendNotification(
                $senderId,
                t('voice.ack_notify_title', [], $senderLang),
                t('voice.ack_notify_message', ['mission' => $mission['title']], $senderLang),
                'success', 'mission_voice_acknowledged',
                [
                    'url' => rtrim(BASE_URL, '/') . '/war-room.php?id=' . $missionId,
                    'tag' => 'voice-ack-' . $voiceId,
                    'bannerMission' => $missionId,
                ]
            );
        }
    }

    echo json_encode(['ok' => true]);
    exit;
}

echo json_encode(['ok' => false, 'error' => t('common.unknown_action')]);
