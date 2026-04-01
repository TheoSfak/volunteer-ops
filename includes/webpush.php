<?php
/**
 * VolunteerOps - Web Push Notification Library
 * Raw PHP implementation using openssl + curl (no Composer required)
 * Implements RFC 8291 (Message Encryption for Web Push) + RFC 8188 (aes128gcm) + RFC 8292 (VAPID)
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
            // Subscription expired or invalid  clean up
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
 * RFC 8291 (Message Encryption) + RFC 8188 (aes128gcm) + RFC 8292 (VAPID)
 *
 * @return true|int|false true on success, HTTP status code on failure, false on error
 */
function sendWebPush(string $endpoint, string $userPublicKey, string $userAuthToken, string $payload) {
    $vapidPublicKey  = getSetting('vapid_public_key', '');
    $vapidPrivateKey = getSetting('vapid_private_key', '');
    $vapidContact    = getSetting('vapid_contact', 'mailto:admin@volunteerops.gr');

    if (!$vapidPublicKey || !$vapidPrivateKey) {
        return false;
    }

    try {
        //  Decode keys 
        $userPubKeyBin = base64UrlDecode($userPublicKey);
        $userAuthBin   = base64UrlDecode($userAuthToken);
        $serverPubBin  = base64UrlDecode($vapidPublicKey);
        $serverPrivBin = base64UrlDecode($vapidPrivateKey);

        //  Generate ephemeral ECDH key pair 
        $localKey = openssl_pkey_new([
            'curve_name'       => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]);
        if (!$localKey) throw new \Exception('Failed to generate EC key pair');

        $localDetails = openssl_pkey_get_details($localKey);
        // Pad x and y to exactly 32 bytes each (required for correct uncompressed point format)
        $x = str_pad($localDetails['ec']['x'], 32, "\x00", STR_PAD_LEFT);
        $y = str_pad($localDetails['ec']['y'], 32, "\x00", STR_PAD_LEFT);
        $localPubRaw = "\x04" . $x . $y; // 65-byte uncompressed EC point

        //  ECDH shared secret 
        // Correct order: openssl_pkey_derive(peer_pub_key, our_priv_key, keylen)
        $userPubPem = makeEcPublicPem($userPubKeyBin);
        $userPubKey = openssl_pkey_get_public($userPubPem);
        if (!$userPubKey) throw new \Exception('Invalid subscriber public key');

        $sharedSecret = openssl_pkey_derive($userPubKey, $localKey, 32);
        if (!$sharedSecret) throw new \Exception('ECDH key derivation failed');

        //  WebPush IKM derivation (RFC 8291 3.3) 
        // IKM = HKDF(salt=auth_secret, IKM=ecdh_secret, info="WebPush: info\x00"+ua_pub+as_pub, L=32)
        $ikmInfo = "WebPush: info\x00" . $userPubKeyBin . $localPubRaw;
        $prk1 = hash_hmac('sha256', $sharedSecret, $userAuthBin, true);             // HKDF-Extract
        $ikm  = substr(hash_hmac('sha256', $ikmInfo . "\x01", $prk1, true), 0, 32); // HKDF-Expand T(1)

        //  Derive CEK and Nonce (RFC 8188 2.2) 
        $salt  = random_bytes(16);
        $prk2  = hash_hmac('sha256', $ikm, $salt, true); // HKDF-Extract(salt=random_salt, IKM=ikm)

        $cek   = substr(hash_hmac('sha256', "Content-Encoding: aes128gcm\x00\x01", $prk2, true), 0, 16);
        $nonce = substr(hash_hmac('sha256', "Content-Encoding: nonce\x00\x01",      $prk2, true), 0, 12);

        //  Encrypt payload (aes128gcm: append 0x02 record delimiter for last record) 
        $tag = '';
        $encrypted = openssl_encrypt(
            $payload . "\x02",
            'aes-128-gcm',
            $cek,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            '',
            16
        );
        if ($encrypted === false) throw new \Exception('AES-GCM encryption failed');
        $ciphertext = $encrypted . $tag;

        //  Build aes128gcm body (RFC 8188 2.1) 
        $body = $salt           // 16 bytes: random salt
              . pack('N', 4096) // 4 bytes:  record size (big-endian uint32)
              . chr(65)         // 1 byte:   keyid length (65-byte uncompressed P-256 point)
              . $localPubRaw    // 65 bytes: ephemeral server public key
              . $ciphertext;    // n bytes:  ciphertext + 16-byte GCM auth tag

        //  VAPID JWT (RFC 8292) 
        $audience = parse_url($endpoint, PHP_URL_SCHEME) . '://' . parse_url($endpoint, PHP_URL_HOST);
        $jwt = createVapidJwt($audience, $vapidContact, $serverPubBin, $serverPrivBin);
        $vapidHeader = 'vapid t=' . $jwt . ', k=' . $vapidPublicKey;

        //  Send via cURL 
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
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            return true;
        }

        error_log("WebPush: HTTP $httpCode  " . substr($endpoint, 0, 60) . "  $curlErr  $response");
        return $httpCode;

    } catch (\Exception $e) {
        error_log('WebPush error: ' . $e->getMessage());
        return false;
    }
}

//  Helper functions 

function base64UrlDecode(string $input): string {
    return base64_decode(strtr($input, '-_', '+/') . str_repeat('=', (4 - strlen($input) % 4) % 4));
}

function base64UrlEncode(string $input): string {
    return rtrim(strtr(base64_encode($input), '+/', '-_'), '=');
}

/**
 * Build a PEM-encoded EC public key from raw uncompressed point (65 bytes: 0x04 || x || y)
 */
function makeEcPublicPem(string $rawPublicKey): string {
    // ASN.1 structure for EC public key on P-256
    $ecOid   = "\x06\x07\x2a\x86\x48\xce\x3d\x02\x01";         // OID 1.2.840.10045.2.1
    $p256Oid = "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07";     // OID 1.2.840.10045.3.1.7
    $algId   = "\x30" . chr(strlen($ecOid) + strlen($p256Oid)) . $ecOid . $p256Oid;
    $bitStr  = "\x03" . chr(strlen($rawPublicKey) + 1) . "\x00" . $rawPublicKey;
    $der     = "\x30" . chr(strlen($algId) + strlen($bitStr)) . $algId . $bitStr;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

/**
 * Build a PEM-encoded EC private key from raw d (private scalar) and uncompressed public point
 */
function makeEcPrivatePem(string $privKeyRaw, string $pubKeyRaw): string {
    $pubPoint = (strlen($pubKeyRaw) === 65) ? $pubKeyRaw : ("\x04" . $pubKeyRaw);

    $p256Oid   = "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07"; // OID 1.2.840.10045.3.1.7
    $version   = "\x02\x01\x01"; // INTEGER 1
    $privOctet = "\x04" . chr(strlen($privKeyRaw)) . $privKeyRaw;
    $oidTag    = "\xa0" . chr(strlen($p256Oid)) . $p256Oid;
    $pubBit    = "\x03" . chr(strlen($pubPoint) + 1) . "\x00" . $pubPoint;
    $pubTag    = "\xa1" . chr(strlen($pubBit)) . $pubBit;

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
 * Create a VAPID JWT token signed with ES256
 */
function createVapidJwt(string $audience, string $subject, string $serverPubBin, string $serverPrivBin): string {
    $header  = base64UrlEncode(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $payload = base64UrlEncode(json_encode([
        'aud' => $audience,
        'exp' => time() + 86400,
        'sub' => $subject,
    ]));

    $signingInput = $header . '.' . $payload;

    $privPem = makeEcPrivatePem($serverPrivBin, $serverPubBin);
    $pkey    = openssl_pkey_get_private($privPem);
    openssl_sign($signingInput, $derSig, $pkey, OPENSSL_ALGO_SHA256);

    return $header . '.' . $payload . '.' . base64UrlEncode(derToRaw($derSig));
}

/**
 * Convert DER-encoded ECDSA signature to raw r||s (64 bytes)
 */
function derToRaw(string $der): string {
    $pos = 0;
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

    // Pad/trim to exactly 32 bytes each
    $r = str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT);
    $s = str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);

    return $r . $s;
}