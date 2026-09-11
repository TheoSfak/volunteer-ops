<?php
/**
 * VolunteerOps - LiveKit helpers (Action Room live video)
 *
 * Deliberately dependency-free: composer.json carries no runtime packages and
 * there is no official LiveKit PHP SDK, so the access token is built by hand.
 * That is less code than it sounds — a LiveKit token is a plain HS256 JWT and
 * the server API is one JSON POST — and it keeps the "no vendor runtime deps"
 * property this project has always had.
 *
 * Credentials live in `settings` (livekit_url / livekit_api_key /
 * livekit_api_secret), managed from settings.php alongside the weather and
 * FIRMS keys. Everything here degrades to "not configured" rather than
 * throwing, so the Action Room cards can simply not render — see
 * livekitConfigured().
 */

if (!defined('VOLUNTEEROPS')) {
    die('Direct access not permitted');
}

/**
 * Room name: one room per mission, prefixed by a per-install key.
 *
 * The prefix is load-bearing, not cosmetic. Mission ids are per-database, so
 * yphresies.gr and epidrasi.iloveweb.gr each have their own mission 42. If the
 * two ever point at the same LiveKit project, an unprefixed name would drop
 * two different organisations into the SAME room — one's live incident footage
 * appearing in the other's Action Room. livekit_site_key is generated once by
 * migration v148 and never rotated (rotating it mid-mission would split a live
 * publisher from its viewers).
 */
function livekitRoomName(int $missionId): string {
    $siteKey = trim((string) getSetting('livekit_site_key', ''));
    if ($siteKey === '') {
        // Migration has not run, or the row was deleted by hand. Deriving from
        // BASE_URL is weaker (www/non-www would split rooms) but an
        // UNPREFIXED room is the one outcome that must never happen.
        $siteKey = substr(sha1(defined('BASE_URL') ? BASE_URL : 'volunteerops'), 0, 10);
    }
    return $siteKey . '-mission-' . $missionId;
}

/**
 * Participant identity. The role prefix is not decoration — it is what lets
 * the viewer side tell a publishing volunteer from another watching admin
 * without a second lookup, and it keeps a user who is somehow both from
 * colliding with themselves.
 */
function livekitIdentity(int $userId, string $role): string {
    return ($role === 'publisher' ? 'v' : 'c') . $userId;
}

/**
 * Configured credentials, or null. Returns null if ANY of the three is
 * missing — a half-configured LiveKit is indistinguishable from an unusable
 * one, and silently building tokens against a blank secret would produce
 * tokens that fail at connect time with a far less obvious error.
 *
 * @return array{host:string, key:string, secret:string}|null
 */
function livekitCreds(): ?array {
    $url    = trim((string) getSetting('livekit_url', ''));
    $key    = trim((string) getSetting('livekit_api_key', ''));
    $secret = trim((string) getSetting('livekit_api_secret', ''));
    if ($url === '' || $key === '' || $secret === '') {
        return null;
    }
    return ['host' => $url, 'key' => $key, 'secret' => $secret];
}

function livekitConfigured(): bool {
    return livekitCreds() !== null;
}

/** base64url per RFC 7515 — JWT segments are not plain base64. */
function livekitB64Url(string $raw): string {
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

/**
 * Build a LiveKit access token.
 *
 * Kept credential-parameterised rather than reading settings itself so it can
 * be exercised standalone (scripts/one-off token generation) and unit-tested
 * without a database. livekitToken() below is the wrapper the app actually
 * calls.
 *
 * @param array $video the LiveKit VideoGrant, e.g.
 *        ['room' => 'mission-5', 'roomJoin' => true, 'canPublish' => true,
 *         'canSubscribe' => false, 'canPublishData' => false]
 */
function livekitBuildToken(
    string $apiKey, string $apiSecret, string $identity, string $name,
    array $video, int $ttlSeconds = 900
): string {
    $now = time();
    $header  = ['alg' => 'HS256', 'typ' => 'JWT'];
    $payload = [
        'iss'   => $apiKey,       // LiveKit identifies the project by the API key
        'sub'   => $identity,
        'name'  => $name,
        'nbf'   => $now - 10,     // small skew allowance; phones drift
        'exp'   => $now + $ttlSeconds,
        'video' => $video,
    ];

    $segments = [
        livekitB64Url(json_encode($header,  JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
        livekitB64Url(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
    ];
    $signingInput = implode('.', $segments);
    $signature    = hash_hmac('sha256', $signingInput, $apiSecret, true);

    return $signingInput . '.' . livekitB64Url($signature);
}

/**
 * Token for a mission room using the configured credentials, or null when
 * LiveKit is not set up.
 *
 * The publish/subscribe split is the security boundary and it is enforced
 * here, server-side, inside the signed token: a volunteer gets canPublish
 * without canSubscribe (they broadcast, they never watch anyone), command
 * staff get the exact inverse. A tampered client cannot widen this.
 */
function livekitToken(
    int $missionId, int $userId, string $displayName, string $role, int $ttlSeconds = 900
): ?string {
    $creds = livekitCreds();
    if ($creds === null) {
        return null;
    }
    $isPublisher = ($role === 'publisher');
    return livekitBuildToken(
        $creds['key'], $creds['secret'],
        livekitIdentity($userId, $role), $displayName,
        [
            'room'           => livekitRoomName($missionId),
            'roomJoin'       => true,
            'canPublish'     => $isPublisher,
            'canSubscribe'   => !$isPublisher,
            'canPublishData' => false,
        ],
        $ttlSeconds
    );
}

/**
 * The three publish profiles offered in settings.php.
 *
 * maxBitrate is the load-bearing number, more than the resolution: an encoder
 * left to decide for itself will happily aim above what a mobile uplink can
 * actually sustain, and the result is the stutter you see rather than a
 * gracefully softer picture. Values are per top simulcast layer; LiveKit sends
 * two smaller layers alongside, so real total usage runs roughly 1.4x these.
 *
 * @return array{width:int, height:int, fps:int, maxBitrate:int, label:string}
 */
function livekitQualityProfile(?string $key = null): array {
    static $profiles = [
        '360' => ['width' => 640,  'height' => 360, 'fps' => 20, 'maxBitrate' => 500000],
        '540' => ['width' => 960,  'height' => 540, 'fps' => 24, 'maxBitrate' => 1200000],
        '720' => ['width' => 1280, 'height' => 720, 'fps' => 24, 'maxBitrate' => 2200000],
    ];
    $key = $key ?? (string) getSetting('livekit_quality', 'auto');
    // 'auto' starts from the middle profile and lets the client step the
    // ceiling down once if the link turns out not to sustain it. It is NOT a
    // different encoder setting — the continuous, second-by-second adaptation
    // happens either way; this only decides how high the encoder is allowed
    // to aim in the first place.
    $auto = ($key === 'auto');
    $base = $profiles[$auto ? '540' : $key] ?? $profiles['540'];
    $base['auto'] = $auto;
    $base['floor'] = $profiles['360'];

    // Codec travels with the profile purely so the publisher receives one
    // object; it is an independent choice, not a property of the resolution.
    $codec = (string) getSetting('livekit_codec', 'vp8');
    $base['codec'] = in_array($codec, ['vp8', 'vp9', 'h264'], true) ? $codec : 'vp8';

    return $base;
}

/** wss://x.livekit.cloud -> https://x.livekit.cloud (the REST API host). */
function livekitApiBase(string $wsUrl): string {
    return rtrim(preg_replace('#^wss?://#i', 'https://', trim($wsUrl)), '/');
}

/**
 * Verify a LiveKit webhook. LiveKit signs the request with the same API
 * secret: the Authorization header carries a JWT whose `sha256` claim is the
 * base64 SHA-256 of the raw body. Checking BOTH the signature and that hash
 * is what stops someone who has merely seen one valid header from replaying
 * it with a body of their choosing.
 *
 * Returns the decoded event payload, or null if anything fails to check out.
 */
function livekitVerifyWebhook(string $rawBody, string $authHeader): ?array {
    $creds = livekitCreds();
    if ($creds === null || $rawBody === '' || $authHeader === '') {
        return null;
    }
    $jwt = trim(preg_replace('/^Bearer\s+/i', '', $authHeader));
    $parts = explode('.', $jwt);
    if (count($parts) !== 3) {
        return null;
    }
    [$h, $p, $sig] = $parts;

    $expected = livekitB64Url(hash_hmac('sha256', $h . '.' . $p, $creds['secret'], true));
    if (!hash_equals($expected, $sig)) {
        return null;
    }

    $claims = json_decode(base64_decode(strtr($p, '-_', '+/')), true);
    if (!is_array($claims)) {
        return null;
    }
    if (isset($claims['exp']) && time() > (int) $claims['exp'] + 60) {
        return null;   // small grace for clock skew
    }
    $bodyHash = base64_encode(hash('sha256', $rawBody, true));
    if (empty($claims['sha256']) || !hash_equals((string) $claims['sha256'], $bodyHash)) {
        return null;
    }

    $event = json_decode($rawBody, true);
    return is_array($event) ? $event : null;
}

/**
 * Forcibly disconnect a participant. THIS is what "stop the stream" means —
 * a stop that only asks the phone to stop is not a stop: the device may be
 * wedged, out of signal, or backgrounded, and the feed would keep flowing.
 * Command authority has to live on the server.
 *
 * Returns false on any failure; the caller still marks the stream ended
 * locally, because a LiveKit that cannot be reached is not a reason to leave
 * a row looking live forever.
 */
/**
 * Cheap read-only call that proves URL + key + secret actually work together,
 * for the "Δοκιμή σύνδεσης" button in settings.php. Worth having as a button:
 * the alternative is discovering a typo'd secret during an incident, because
 * nothing else in the app touches LiveKit until someone asks for a stream.
 *
 * @return array{ok:bool, message:string}
 */
function livekitTestConnection(): array {
    $creds = livekitCreds();
    if ($creds === null) {
        return ['ok' => false, 'message' => 'Λείπουν στοιχεία (URL, key ή secret).'];
    }
    if (!preg_match('#^wss?://#i', $creds['host'])) {
        return ['ok' => false, 'message' => 'Το URL πρέπει να ξεκινά με wss://'];
    }

    $token = livekitBuildToken(
        $creds['key'], $creds['secret'], 'server', 'server',
        ['roomList' => true], 60
    );
    $ch = curl_init(livekitApiBase($creds['host']) . '/twirp/livekit.RoomService/ListRooms');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => '{}',
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($code === 200) {
        $rooms = json_decode((string) $body, true);
        $n = is_array($rooms['rooms'] ?? null) ? count($rooms['rooms']) : 0;
        return ['ok' => true, 'message' => 'Επιτυχής σύνδεση. Ενεργά δωμάτια αυτή τη στιγμή: ' . $n . '.'];
    }
    if ($code === 401 || $code === 403) {
        return ['ok' => false, 'message' => 'Το LiveKit απέρριψε τα κλειδιά (' . $code . '). Ελέγξτε API key και secret.'];
    }
    if ($code === 0) {
        return ['ok' => false, 'message' => 'Δεν έγινε σύνδεση: ' . ($err ?: 'άγνωστο σφάλμα δικτύου') . '. Ελέγξτε το URL.'];
    }
    return ['ok' => false, 'message' => 'Σφάλμα ' . $code . ': ' . substr((string) $body, 0, 200)];
}

function livekitRemoveParticipant(int $missionId, string $identity): bool {
    $creds = livekitCreds();
    if ($creds === null) {
        return false;
    }
    $room = livekitRoomName($missionId);
    // roomAdmin (not roomJoin) is the grant the RoomService API checks.
    $token = livekitBuildToken(
        $creds['key'], $creds['secret'], 'server', 'server',
        ['room' => $room, 'roomAdmin' => true],
        60
    );

    $ch = curl_init(livekitApiBase($creds['host']) . '/twirp/livekit.RoomService/RemoveParticipant');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode(['room' => $room, 'identity' => $identity]),
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($code !== 200) {
        error_log('livekitRemoveParticipant failed (' . $code . '): ' . ($err ?: $body));
        return false;
    }
    return true;
}
