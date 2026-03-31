<?php
/**
 * VolunteerOps - Web Push Notification Library
 * Raw PHP implementation using openssl + curl (no Composer required)
 * Implements RFC 8291 (Message Encryption for Web Push) + VAPID (RFC 8292)
 */

if (!defined('VOLUNTEEROPS')) die('Direct access not permitted');

/**
 * Send a push notification to a specific user (all their subscriptions)
 */
function sendPushToUser(int $userId, string $title, string $body, array $extraData = []): int {
    $subscriptions = dbFetchAll(
        "SELECT * FROM push_subscriptions WHERE user_id = ?",
        [$userId]
    );

    if (empty($subscriptions)) return 0;

    $sent = 0;
    foreach ($subscriptions as $sub) {
        $payload = json_encode(array_merge([
            'title' => $title,
            'body'  => $body,
            'icon'  => './assets/icons/icon-192.png',
            'badge' => './assets/icons/icon-72.png',
        ], $extraData));

        $result = sendWebPush($sub['endpoint'], $sub['p256dh_key'], $sub['auth_key'], $payload);

        if ($result === true) {
            $sent++;
        } elseif ($result === 410 || $result === 404) {
            // Subscription expired or invalid — clean up
            dbExecute("DELETE FROM push_subscriptions WHERE id = ?", [$sub['id']]);
        }
    }

    return $sent;
}

/**
 * Send a push notification to all subscribed users
 */
function sendPushToAll(string $title, string $body, array $extraData = []): int {
    $subscriptions = dbFetchAll("SELECT * FROM push_subscriptions");
    if (empty($subscriptions)) return 0;

    $sent = 0;
    foreach ($subscriptions as $sub) {
        $payload = json_encode(array_merge([
            'title' => $title,
            'body'  => $body,
            'icon'  => './assets/icons/icon-192.png',
            'badge' => './assets/icons/icon-72.png',
        ], $extraData));

        $result = sendWebPush($sub['endpoint'], $sub['p256dh_key'], $sub['auth_key'], $payload);

        if ($result === true) {
            $sent++;
        } elseif ($result === 410 || $result === 404) {
            dbExecute("DELETE FROM push_subscriptions WHERE id = ?", [$sub['id']]);
        }
    }

    return $sent;
}

/**
 * Core Web Push send function
 * Implements VAPID authentication and payload encryption
 *
 * @return true|int true on success, HTTP status code on failure
 */
function sendWebPush(string $endpoint, string $userPublicKey, string $userAuthToken, string $payload) {
    $vapidPublicKey  = getSetting('vapid_public_key', '');
    $vapidPrivateKey = getSetting('vapid_private_key', '');
    $vapidContact    = getSetting('vapid_contact', 'mailto:admin@volunteerops.gr');

    if (!$vapidPublicKey || !$vapidPrivateKey) {
        return false;
    }

    try {
        // ── Decode keys from URL-safe Base64 ──
        $userPubKeyBin  = base64UrlDecode($userPublicKey);
        $userAuthBin    = base64UrlDecode($userAuthToken);
        $serverPubBin   = base64UrlDecode($vapidPublicKey);
        $serverPrivBin  = base64UrlDecode($vapidPrivateKey);

        // ── Generate local ECDH key pair for encryption ──
        $localKey = openssl_pkey_new([
            'curve_name'       => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);
        $localDetails = openssl_pkey_get_details($localKey);
        $localPubRaw = chr(4) . $localDetails['ec']['x'] . $localDetails['ec']['y'];

        // ── ECDH shared secret ──
        // Build PEM from user's public key for openssl_pkey_get_public
        $userPubPem = makeEcPublicPem($userPubKeyBin);
        $sharedSecret = '';
        openssl_pkey_derive($localKey, openssl_pkey_get_public($userPubPem), $sharedSecret, 32);

        // ── HKDF for key derivation (RFC 8291) ──
        // IKM
        $ikm = $sharedSecret;

        // Info for auth: "WebPush: info" || 0x00 || ua_public || as_public
        $authInfo = "WebPush: info\x00" . $userPubKeyBin . $localPubRaw;
        $prk = hkdfExtractExpand($userAuthBin, $ikm, $authInfo, 32);

        // Content encryption key
        $cekInfo = createInfo('aesgcm', $userPubKeyBin, $localPubRaw);
        $contentEncryptionKey = hkdfExtractExpand($prk, '', $cekInfo, 16, true);

        // Nonce
        $nonceInfo = createInfo('nonce', $userPubKeyBin, $localPubRaw);
        $nonce = hkdfExtractExpand($prk, '', $nonceInfo, 12, true);

        // ── Pad and encrypt payload (AES-128-GCM) ──
        $padding = pack('n', 0); // 2-byte padding length = 0
        $padded = $padding . $payload;

        $encrypted = openssl_encrypt(
            $padded,
            'aes-128-gcm',
            $contentEncryptionKey,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            '',
            16
        );
        $ciphertext = $encrypted . $tag;

        // ── Salt (random 16 bytes) ──
        $salt = random_bytes(16);

        // ── Build encrypted body ──
        $body = $salt                                    // 16 bytes
              . pack('N', 4096)                          // record size (4 bytes)
              . chr(strlen($localPubRaw)) . $localPubRaw // key length + key
              . $ciphertext;

        // ── VAPID Authorization header (JWT) ──
        $audience = parse_url($endpoint, PHP_URL_SCHEME) . '://' . parse_url($endpoint, PHP_URL_HOST);
        $jwt = createVapidJwt($audience, $vapidContact, $serverPubBin, $serverPrivBin);
        $vapidHeader = 'vapid t=' . $jwt . ', k=' . $vapidPublicKey;

        // ── Send via cURL ──
        $headers = [
            'Content-Type: application/octet-stream',
            'Content-Encoding: aes128gcm',
            'Content-Length: ' . strlen($body),
            'Authorization: ' . $vapidHeader,
            'TTL: 86400',
            'Urgency: normal',
        ];

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            return true;
        }

        return $httpCode;

    } catch (Exception $e) {
        error_log('WebPush error: ' . $e->getMessage());
        return false;
    }
}

// ── Helper functions ────────────────────────────────────────────────────────

function base64UrlDecode(string $input): string {
    return base64_decode(strtr($input, '-_', '+/') . str_repeat('=', (4 - strlen($input) % 4) % 4));
}

function base64UrlEncode(string $input): string {
    return rtrim(strtr(base64_encode($input), '+/', '-_'), '=');
}

/**
 * Build a PEM-encoded EC public key from raw uncompressed point
 */
function makeEcPublicPem(string $rawPublicKey): string {
    // ASN.1 structure for EC public key on P-256
    // SEQUENCE { SEQUENCE { OID ecPublicKey, OID prime256v1 }, BIT STRING { publicKey } }
    $ecOid = "\x06\x07\x2a\x86\x48\xce\x3d\x02\x01"; // 1.2.840.10045.2.1
    $p256Oid = "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07"; // 1.2.840.10045.3.1.7
    $algId = "\x30" . chr(strlen($ecOid) + strlen($p256Oid)) . $ecOid . $p256Oid;
    $bitString = "\x03" . chr(strlen($rawPublicKey) + 1) . "\x00" . $rawPublicKey;
    $der = "\x30" . chr(strlen($algId) + strlen($bitString)) . $algId . $bitString;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

/**
 * HKDF Extract and Expand (simplified for Web Push)
 */
function hkdfExtractExpand(string $salt, string $ikm, string $info, int $length, bool $skipExtract = false): string {
    if ($skipExtract) {
        // ikm is already the PRK (from previous extraction)
        $prk = $salt ?: str_repeat("\x00", 32);
    } else {
        $prk = hash_hmac('sha256', $ikm, $salt, true);
    }
    // Expand
    $infoHmac = hash_hmac('sha256', $info . "\x01", $prk, true);
    return substr($infoHmac, 0, $length);
}

/**
 * Create content encoding info per RFC 8188
 */
function createInfo(string $type, string $clientPublicKey, string $serverPublicKey): string {
    return "Content-Encoding: " . $type . "\x00"
         . "P-256" . "\x00"
         . pack('n', strlen($clientPublicKey)) . $clientPublicKey
         . pack('n', strlen($serverPublicKey)) . $serverPublicKey;
}

/**
 * Create a VAPID JWT token (ES256 signed)
 */
function createVapidJwt(string $audience, string $subject, string $serverPubBin, string $serverPrivBin): string {
    $header = base64UrlEncode(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));

    $payload = base64UrlEncode(json_encode([
        'aud' => $audience,
        'exp' => time() + 86400,
        'sub' => $subject,
    ]));

    $signingInput = $header . '.' . $payload;
    $hash = hash('sha256', $signingInput, true);

    // Build EC private key PEM for signing
    $privPem = makeEcPrivatePem($serverPrivBin, $serverPubBin);

    $pkey = openssl_pkey_get_private($privPem);
    openssl_sign($signingInput, $derSig, $pkey, OPENSSL_ALGO_SHA256);

    // Convert DER signature to raw r||s (64 bytes)
    $rawSig = derToRaw($derSig);

    return $header . '.' . $payload . '.' . base64UrlEncode($rawSig);
}

/**
 * Build a PEM-encoded EC private key from raw d and public point
 */
function makeEcPrivatePem(string $privKeyRaw, string $pubKeyRaw): string {
    // Reconstruct uncompressed public key if needed
    if (strlen($pubKeyRaw) === 65) {
        $pubPoint = $pubKeyRaw;
    } else {
        $pubPoint = chr(4) . $pubKeyRaw;
    }

    // ASN.1 ECPrivateKey structure:
    // SEQUENCE {
    //   INTEGER 1,
    //   OCTET STRING privateKey,
    //   [0] OID prime256v1,
    //   [1] BIT STRING publicKey
    // }
    $p256Oid = "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07"; // 1.2.840.10045.3.1.7
    $version = "\x02\x01\x01"; // INTEGER 1
    $privOctet = "\x04" . chr(strlen($privKeyRaw)) . $privKeyRaw;
    $oidTag = "\xa0" . chr(strlen($p256Oid)) . $p256Oid;
    $pubBit = "\x03" . chr(strlen($pubPoint) + 1) . "\x00" . $pubPoint;
    $pubTag = "\xa1" . chr(strlen($pubBit)) . $pubBit;

    $inner = $version . $privOctet . $oidTag . $pubTag;
    $ecKey = "\x30" . asn1Length(strlen($inner)) . $inner;

    return "-----BEGIN EC PRIVATE KEY-----\n" . chunk_split(base64_encode($ecKey), 64, "\n") . "-----END EC PRIVATE KEY-----\n";
}

function asn1Length(int $length): string {
    if ($length < 128) return chr($length);
    if ($length < 256) return "\x81" . chr($length);
    return "\x82" . pack('n', $length);
}

/**
 * Convert DER-encoded ECDSA signature to raw r||s (64 bytes)
 */
function derToRaw(string $der): string {
    $pos = 0;
    // SEQUENCE
    if (ord($der[$pos++]) !== 0x30) throw new \Exception('Invalid DER signature');
    $seqLen = ord($der[$pos++]);
    if ($seqLen > 127) $pos++; // skip extended length byte

    // r
    if (ord($der[$pos++]) !== 0x02) throw new \Exception('Invalid DER r');
    $rLen = ord($der[$pos++]);
    $r = substr($der, $pos, $rLen);
    $pos += $rLen;

    // s
    if (ord($der[$pos++]) !== 0x02) throw new \Exception('Invalid DER s');
    $sLen = ord($der[$pos++]);
    $s = substr($der, $pos, $sLen);

    // Pad/trim to 32 bytes each
    $r = str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT);
    $s = str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);

    return $r . $s;
}
