<?php
/**
 * VolunteerOps - Mission Photo/Video Endpoint
 * War Room: any approved participant uploads a field photo or video (sender,
 * timestamp, and best-effort GPS are captured); admins or the sender can
 * delete one. POST only, AJAX/multipart.
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();

header('Content-Type: application/json');

/**
 * Notify command staff (system/department admins, this mission's shift leaders,
 * and its responsible user) that a field photo/video came in. Mirrors the
 * recipient resolution in mission-dispatch.php's notifyDispatchArrival().
 */
function notifyPhotoReceived(int $missionId, string $missionTitle, ?int $responsibleUserId, string $senderName, int $senderId, string $mediaType): void {
    $warRoomUrl = rtrim(BASE_URL, '/') . '/war-room.php?id=' . $missionId;

    $recipientIds = getMissionCommandStaffIds($missionId, $responsibleUserId, $senderId);

    $titleKey = $mediaType === 'video' ? 'photo.notify_title_video' : 'photo.notify_title_photo';
    $kindKey = $mediaType === 'video' ? 'photo.kind_video' : 'photo.kind_photo';
    $code = $mediaType === 'video' ? 'mission_video_received' : 'mission_photo_received';
    $langByUserId = getUserLanguages($recipientIds);
    foreach ($recipientIds as $recipientId) {
        $lang = $langByUserId[$recipientId] ?? DEFAULT_LANGUAGE;
        $message = t('photo.notify_message', ['name' => $senderName, 'kind' => t($kindKey, [], $lang), 'mission' => $missionTitle], $lang);
        sendNotification($recipientId, t($titleKey, [], $lang), $message, 'info', $code, [
            'url' => $warRoomUrl,
            'tag' => 'photo-received-mission-' . $missionId,
            'bannerMission' => $missionId,
        ]);
    }
}

/**
 * Notify command staff — like notifyPhotoReceived() above, but every
 * approved participant too, not just command staff: unlike a routine field
 * photo, a Point of Interest is exactly the kind of thing other teams
 * searching nearby should hear about, per the mission owner's explicit
 * answer when this feature was scoped ([[project-action-room-point-of-interest-idea]]
 * in memory — visible to everyone, same reasoning as incidents). $isMerge
 * only changes the wording (new lead vs. a second person corroborating an
 * existing one) — same recipients, same mandatory/un-mutable severity
 * either way (empty code, matching incidents/SOS/needs_help).
 */
function notifyPoiReported(int $missionId, string $missionTitle, ?int $responsibleUserId, string $senderName, int $senderId, bool $isMerge): void {
    $warRoomUrl = rtrim(BASE_URL, '/') . '/war-room.php?id=' . $missionId;

    $participantIds = array_map('intval', array_column(
        dbFetchAll(
            "SELECT DISTINCT pr.volunteer_id AS user_id FROM participation_requests pr
             JOIN shifts s ON s.id = pr.shift_id
             WHERE s.mission_id = ? AND pr.status = ?",
            [$missionId, PARTICIPATION_APPROVED]
        ),
        'user_id'
    ));
    $recipientIds = array_values(array_unique(array_diff(
        array_merge($participantIds, getMissionCommandStaffIds($missionId, $responsibleUserId, $senderId)),
        [$senderId]
    )));

    $titleKey = $isMerge ? 'poi.notify_title_merged' : 'poi.notify_title_new';
    $langByUserId = getUserLanguages($recipientIds);
    foreach ($recipientIds as $recipientId) {
        $lang = $langByUserId[$recipientId] ?? DEFAULT_LANGUAGE;
        $message = t('poi.notify_message', ['name' => $senderName, 'mission' => $missionTitle], $lang);
        sendNotification($recipientId, t($titleKey, [], $lang), $message, 'danger', '', [
            'url' => $warRoomUrl,
            'tag' => 'poi-mission-' . $missionId,
            'bannerMission' => $missionId,
        ]);
    }
}

/**
 * Notify whoever photographed this Point of Interest (every distinct
 * reporter across every photo merged into it, minus whoever just clicked
 * "checked") once command staff verifies it. Configurable code (unlike the
 * report notification above), same "seen"-tier severity as shortage/incident
 * acknowledgement, not a hard alarm.
 */
function notifyPoiChecked(int $missionId, string $missionTitle, int $poiId, int $actingUserId): void {
    $warRoomUrl = rtrim(BASE_URL, '/') . '/war-room.php?id=' . $missionId;
    $recipientIds = array_values(array_diff(array_map('intval', array_column(
        dbFetchAll("SELECT DISTINCT user_id FROM mission_photos WHERE poi_id = ?", [$poiId]),
        'user_id'
    )), [$actingUserId]));
    if (!$recipientIds) {
        return;
    }
    $langByUserId = getUserLanguages($recipientIds);
    foreach ($recipientIds as $recipientId) {
        $lang = $langByUserId[$recipientId] ?? DEFAULT_LANGUAGE;
        sendNotification(
            $recipientId, t('poi.checked_notify_title', [], $lang),
            t('poi.checked_notify_message', ['mission' => $missionTitle], $lang),
            'success', 'mission_poi_checked', ['url' => $warRoomUrl, 'tag' => 'poi-checked-' . $poiId]
        );
    }
}

$userId = getCurrentUserId();
$user = getCurrentUser();

$missionId = (int) post('mission_id');

$mission = dbFetchOne(
    "SELECT id, title, status, show_in_ops, responsible_user_id FROM missions WHERE id = ? AND deleted_at IS NULL",
    [$missionId]
);
if (!$mission || $mission['status'] !== STATUS_OPEN || empty($mission['show_in_ops'])) {
    echo json_encode(['ok' => false, 'error' => t('common.mission_not_found_or_inactive')]);
    exit;
}

$canManageWarRoom = canManageActionRoom($mission['responsible_user_id'] ? (int)$mission['responsible_user_id'] : null, (int)$userId);
$isApprovedParticipant = (bool) dbFetchValue(
    "SELECT COUNT(*) FROM participation_requests pr
     JOIN shifts s ON s.id = pr.shift_id
     WHERE s.mission_id = ? AND pr.volunteer_id = ? AND pr.status = ?",
    [$missionId, $userId, PARTICIPATION_APPROVED]
);
if (!$canManageWarRoom && !$isApprovedParticipant) {
    echo json_encode(['ok' => false, 'error' => t('common.no_access_action_room')]);
    exit;
}

if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string) $_POST['csrf_token'])) {
    echo json_encode(['ok' => false, 'error' => t('common.invalid_request')]);
    exit;
}

$action = post('action');

if ($action === 'upload') {
    if (!$isApprovedParticipant) {
        echo json_encode(['ok' => false, 'error' => t('photo.only_approved_can_send')]);
        exit;
    }
    if (empty($_FILES['media']['name']) || $_FILES['media']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['ok' => false, 'error' => t('photo.select_file')]);
        exit;
    }

    $file = $_FILES['media'];
    $photoExt   = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $photoMime  = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $videoExt   = ['mp4', 'webm', 'mov', 'm4v'];
    $videoMime  = ['video/mp4', 'video/webm', 'video/quicktime', 'video/x-m4v'];
    $origName = basename($file['name']);
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);

    if (in_array($ext, $photoExt, true) && in_array($mime, $photoMime, true)) {
        $mediaType = 'photo';
        $maxSize = UPLOAD_MAX_SIZE;
    } elseif (in_array($ext, $videoExt, true) && in_array($mime, $videoMime, true)) {
        $mediaType = 'video';
        $maxSize = VIDEO_MAX_SIZE;
    } else {
        echo json_encode(['ok' => false, 'error' => t('photo.invalid_type')]);
        exit;
    }
    if ($file['size'] > $maxSize) {
        echo json_encode(['ok' => false, 'error' => t('photo.file_too_large', ['size' => $maxSize / 1024 / 1024])]);
        exit;
    }

    $latRaw = post('lat');
    $lngRaw = post('lng');
    $lat = ($latRaw !== '' && $latRaw !== null && is_numeric($latRaw)) ? (float) $latRaw : null;
    $lng = ($lngRaw !== '' && $lngRaw !== null && is_numeric($lngRaw)) ? (float) $lngRaw : null;
    if ($lat !== null && ($lat < -90 || $lat > 90)) { $lat = null; }
    if ($lng !== null && ($lng < -180 || $lng > 180)) { $lng = null; }
    if ($lat === 0.0 && $lng === 0.0) { $lat = null; $lng = null; }

    // Point of Interest: a photographed physical clue (e.g. clothing found
    // while searching for a missing person), auto-GPS-tagged and shown as
    // its own persistent map pin — unlike a regular field photo, whose
    // location (if any) is locate-on-demand only. GPS is mandatory here,
    // checked before touching the filesystem at all — a POI with no location
    // isn't a POI, it's just a photo, so fail fast rather than save a file
    // that will immediately turn out to be unusable for this mode.
    $isPoi = post('is_poi') === '1';
    if ($isPoi && ($lat === null || $lng === null)) {
        echo json_encode(['ok' => false, 'error' => t('poi.gps_required')]);
        exit;
    }
    // Optional — "found an object that might belong to the missing person"
    // is exactly the kind of context a bare photo can't convey on its own.
    // Per-photo, not per-POI-group (see mission_points_of_interest schema
    // docblock): a merged POI can carry several of these, one per reporter.
    $poiNote = $isPoi ? (trim((string) post('note')) ?: null) : null;
    if ($poiNote !== null) {
        $poiNote = mb_substr($poiNote, 0, 500);
    }

    $destDir = __DIR__ . '/uploads/mission-photos/';
    if (!is_dir($destDir)) {
        mkdir($destDir, 0755, true);
    }
    $storedName = 'mphoto_' . $missionId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $destDir . $storedName)) {
        echo json_encode(['ok' => false, 'error' => t('photo.save_failed')]);
        exit;
    }

    // Optional: this upload is the photo/video deliverable for one Route Order
    // waypoint (war-room.php's "Η Πορεία μου" card — field mode has no map/media
    // panel, so that card ships its own upload button hitting this same action).
    // Validated against mission_route_members (not team membership) so a
    // stray/forged id can't attach someone else's field media to a waypoint —
    // mirrors mission-route.php's depart/arrive/complete gate exactly: a route
    // may only involve a subset of its nominal team (migration v109), so a
    // team-membership check here would let an excluded team member satisfy a
    // sub-route's require_photo/require_video anyway, defeating the whole
    // point of assigning it to only 2 of 4 people.
    $routeWaypointId = null;
    $routeWaypointIdRaw = post('route_waypoint_id');
    if ($routeWaypointIdRaw !== '' && $routeWaypointIdRaw !== null) {
        $waypointRoute = dbFetchOne(
            "SELECT w.id, r.id AS route_id FROM mission_route_waypoints w
             JOIN mission_routes r ON r.id = w.route_id
             WHERE w.id = ? AND r.mission_id = ?",
            [(int) $routeWaypointIdRaw, $missionId]
        );
        if ($waypointRoute) {
            $isRouteMember = (bool) dbFetchValue(
                "SELECT 1 FROM mission_route_members WHERE route_id = ? AND user_id = ?",
                [$waypointRoute['route_id'], $userId]
            );
            if ($canManageWarRoom || $isRouteMember) {
                $routeWaypointId = (int) $waypointRoute['id'];
            }
        }
    }

    // Find-or-create the POI this photo belongs to: any existing point of
    // interest for this mission within ~30m counts as "the same spot" —
    // GPS accuracy on a phone is typically 5-20m, so this is generous enough
    // to absorb that jitter while still keeping genuinely distinct nearby
    // finds separate. Straight linear scan (no spatial index) is fine here —
    // a single mission will only ever accumulate a handful of these.
    $poiId = null;
    $poiIsMerge = false;
    if ($isPoi) {
        $poiMergeThresholdMeters = 30;
        $existingPois = dbFetchAll("SELECT id, lat, lng FROM mission_points_of_interest WHERE mission_id = ?", [$missionId]);
        foreach ($existingPois as $candidate) {
            if (gpsDistanceMeters($lat, $lng, (float) $candidate['lat'], (float) $candidate['lng']) <= $poiMergeThresholdMeters) {
                $poiId = (int) $candidate['id'];
                $poiIsMerge = true;
                break;
            }
        }
        if ($poiId === null) {
            $poiId = dbInsert(
                "INSERT INTO mission_points_of_interest (mission_id, lat, lng, created_at) VALUES (?, ?, ?, NOW())",
                [$missionId, $lat, $lng]
            );
        }
    }

    $photoId = dbInsert(
        "INSERT INTO mission_photos (mission_id, user_id, media_type, stored_name, original_name, mime_type, file_size, lat, lng, route_waypoint_id, poi_id, poi_note, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
        [$missionId, $userId, $mediaType, $storedName, $origName, $mime, (int) $file['size'], $lat, $lng, $routeWaypointId, $poiId, $poiNote]
    );
    logAudit('upload_mission_photo', 'mission_photos', $photoId, null, ['mission_id' => $missionId, 'media_type' => $mediaType, 'route_waypoint_id' => $routeWaypointId, 'poi_id' => $poiId]);

    // Auto-fulfill any outstanding War Room "send a photo/video" orders of this type for this user.
    dbExecute(
        "UPDATE mission_order_recipients r
         JOIN mission_orders o ON o.id = r.order_id
         SET r.fulfilled_at = NOW()
         WHERE r.user_id = ? AND o.mission_id = ? AND o.order_type = ? AND r.fulfilled_at IS NULL",
        [$userId, $missionId, $mediaType]
    );

    if ($isPoi) {
        notifyPoiReported($missionId, $mission['title'], $mission['responsible_user_id'] ? (int) $mission['responsible_user_id'] : null, $user['name'], $userId, $poiIsMerge);
    } else {
        notifyPhotoReceived($missionId, $mission['title'], $mission['responsible_user_id'] ? (int) $mission['responsible_user_id'] : null, $user['name'], $userId, $mediaType);
    }

    $teamLabel = null;
    $myTeamId = getUserTeamIdForMission($missionId, $userId);
    if ($myTeamId) {
        $teamRow = dbFetchOne("SELECT codename, team_number FROM mission_teams WHERE id = ?", [$myTeamId]);
        if ($teamRow) {
            $teamLabel = teamLabel($teamRow['codename'], $teamRow['team_number']);
        }
    }

    echo json_encode(['ok' => true, 'media' => [
        'id'             => (int) $photoId,
        'media_type'     => $mediaType,
        'user_name'      => $user['name'],
        'is_external'    => (bool) $user['is_external'],
        'guest_org_name' => $user['guest_org_name'],
        'team_label'     => $teamLabel,
        'time'           => date('d/m H:i'),
        'lat'            => $lat,
        'lng'            => $lng,
        'can_delete'     => true,
        'poi_id'         => $poiId,
    ]]);
    exit;
}

if ($action === 'delete') {
    $photoId = (int) post('id');
    $photo = dbFetchOne("SELECT id, user_id, stored_name, poi_id FROM mission_photos WHERE id = ? AND mission_id = ?", [$photoId, $missionId]);
    if (!$photo) {
        echo json_encode(['ok' => false, 'error' => t('common.not_found')]);
        exit;
    }
    if (!$canManageWarRoom && (int) $photo['user_id'] !== $userId) {
        echo json_encode(['ok' => false, 'error' => t('photo.no_delete_permission')]);
        exit;
    }

    $filePath = __DIR__ . '/uploads/mission-photos/' . basename($photo['stored_name']);
    if (is_file($filePath)) {
        unlink($filePath);
    }
    dbExecute("DELETE FROM mission_photos WHERE id = ?", [$photoId]);
    logAudit('delete_mission_photo', 'mission_photos', $photoId, null, ['mission_id' => $missionId]);

    // A POI's whole reason to exist is "at least one photo of this spot" —
    // once the last one is gone there is nothing left to check, so the pin
    // itself goes too rather than lingering empty on the map forever.
    if ($photo['poi_id']) {
        $remainingPhotos = (int) dbFetchValue("SELECT COUNT(*) FROM mission_photos WHERE poi_id = ?", [$photo['poi_id']]);
        if ($remainingPhotos === 0) {
            dbExecute("DELETE FROM mission_points_of_interest WHERE id = ?", [$photo['poi_id']]);
        }
    }

    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'check_poi') {
    if (!$canManageWarRoom) {
        echo json_encode(['ok' => false, 'error' => t('poi.no_manage_permission')]);
        exit;
    }
    $poiId = (int) post('poi_id');
    $poi = dbFetchOne("SELECT id, checked_at FROM mission_points_of_interest WHERE id = ? AND mission_id = ?", [$poiId, $missionId]);
    if (!$poi) {
        echo json_encode(['ok' => false, 'error' => t('common.not_found')]);
        exit;
    }
    if (!$poi['checked_at']) {
        dbExecute("UPDATE mission_points_of_interest SET checked_at = NOW(), checked_by = ? WHERE id = ?", [$userId, $poiId]);
        logAudit('check_mission_poi', 'mission_points_of_interest', $poiId, null, ['mission_id' => $missionId]);
        notifyPoiChecked($missionId, $mission['title'], $poiId, $userId);
    }
    echo json_encode(['ok' => true]);
    exit;
}

echo json_encode(['ok' => false, 'error' => t('common.unknown_action')]);
