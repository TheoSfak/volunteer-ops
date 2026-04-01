<?php
/**
 * VolunteerOps - VAPID Key Generator
 * Run once to generate and store VAPID keys for Web Push notifications. 
 * Usage: Access via browser as admin or run from CLI.
 */

require_once __DIR__ . '/bootstrap.php';

// Allow CLI execution
$isCli = php_sapi_name() === 'cli';

if (!$isCli) {
    requireLogin();
    requireRole([ROLE_SYSTEM_ADMIN]);
}

// Check if keys already exist
$existingPublic = getSetting('vapid_public_key', '');
$existingPrivate = getSetting('vapid_private_key', '');

if ($existingPublic && $existingPrivate) {
    $msg = "VAPID keys already exist.\n\nPublic Key: $existingPublic\n\nTo regenerate, delete the 'vapid_public_key' and 'vapid_private_key' settings first.";
    if ($isCli) {
        echo $msg . "\n";
    } else {
        echo '<pre>' . h($msg) . '</pre>';
        echo '<p><a href="settings.php">← Πίσω στις Ρυθμίσεις</a></p>';
    }
    exit;
}

// Generate ECDH key pair using P-256 curve (required for Web Push)
$pkey = openssl_pkey_new([
    'curve_name'       => 'prime256v1',
    'private_key_type' => OPENSSL_KEYTYPE_EC,
]);

if (!$pkey) {
    $error = "Failed to generate ECDH key pair. Ensure OpenSSL supports EC keys.\n" . openssl_error_string();
    if ($isCli) { die($error . "\n"); }
    die('<div class="alert alert-danger">' . h($error) . '</div>');
}

$details = openssl_pkey_get_details($pkey);

// Extract the raw public key (uncompressed point: 04 || x || y) — 65 bytes
// Pad coordinates to exactly 32 bytes each (leading zeros may be stripped by OpenSSL)
$x = str_pad($details['ec']['x'], 32, "\x00", STR_PAD_LEFT);
$y = str_pad($details['ec']['y'], 32, "\x00", STR_PAD_LEFT);
$publicKeyUncompressed = "\x04" . $x . $y;
$publicKeyB64 = rtrim(strtr(base64_encode($publicKeyUncompressed), '+/', '-_'), '=');

// Extract the raw private key (d parameter) — pad to 32 bytes
$privateKeyRaw = str_pad($details['ec']['d'], 32, "\x00", STR_PAD_LEFT);
$privateKeyB64 = rtrim(strtr(base64_encode($privateKeyRaw), '+/', '-_'), '=');

// Store in settings table
$existCheck = dbFetchOne("SELECT id FROM settings WHERE setting_key = ?", ['vapid_public_key']);
if ($existCheck) {
    dbExecute("UPDATE settings SET setting_value = ? WHERE setting_key = ?", [$publicKeyB64, 'vapid_public_key']);
} else {
    dbInsert("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)", ['vapid_public_key', $publicKeyB64]);
}

$existCheck2 = dbFetchOne("SELECT id FROM settings WHERE setting_key = ?", ['vapid_private_key']);
if ($existCheck2) {
    dbExecute("UPDATE settings SET setting_value = ? WHERE setting_key = ?", [$privateKeyB64, 'vapid_private_key']);
} else {
    dbInsert("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)", ['vapid_private_key', $privateKeyB64]);
}

// Also store contact email (required by VAPID spec)
$contactEmail = getSetting('contact_email', getSetting('smtp_from', 'admin@volunteerops.gr'));
$existCheck3 = dbFetchOne("SELECT id FROM settings WHERE setting_key = ?", ['vapid_contact']);
if (!$existCheck3) {
    dbInsert("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)", ['vapid_contact', 'mailto:' . $contactEmail]);
}

$msg = "VAPID keys generated successfully!\n\nPublic Key:\n$publicKeyB64\n\nPrivate Key:\n$privateKeyB64\n\nKeys have been stored in the settings table.";

if ($isCli) {
    echo $msg . "\n";
} else {
    $pageTitle = 'VAPID Keys Generated';
    include __DIR__ . '/includes/header.php';
    echo '<div class="container py-4">';
    echo '<div class="alert alert-success"><i class="bi bi-check-circle me-2"></i>VAPID keys δημιουργήθηκαν επιτυχώς!</div>';
    echo '<div class="card"><div class="card-body"><pre class="mb-0">' . h($msg) . '</pre></div></div>';
    echo '<p class="mt-3"><a href="settings.php" class="btn btn-primary">← Πίσω στις Ρυθμίσεις</a></p>';
    echo '</div>';
    include __DIR__ . '/includes/footer.php';
}
