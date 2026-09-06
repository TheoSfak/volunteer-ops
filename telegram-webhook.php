<?php
/**
 * VolunteerOps - Telegram Bot Webhook
 *
 * Called directly by Telegram's servers, never by a browser - no session, no
 * CSRF, same reasoning as mobile-ping-location.php. The only auth available
 * for a public endpoint like this is the secret_token Telegram echoes back on
 * every request once registerTelegramWebhook() (includes/telegram.php)
 * registers this URL with one.
 *
 * The only update this currently handles is "/start <token>" - the second
 * half of the account-linking handshake started by a "Σύνδεση Telegram"
 * click on notification-preferences.php. Everything else is acknowledged
 * and ignored: Telegram retries on anything but a fast 200, so this must
 * never block on or fail because of an update shape it doesn't understand.
 */
require_once __DIR__ . '/bootstrap.php';

header('Content-Type: application/json');

$expectedSecret = trim(getSetting('telegram_webhook_secret', ''));
$givenSecret = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
if ($expectedSecret === '' || !hash_equals($expectedSecret, $givenSecret)) {
    http_response_code(401);
    echo json_encode(['ok' => false]);
    exit;
}

$update = json_decode(file_get_contents('php://input'), true);
$text = trim($update['message']['text'] ?? '');
$chatId = $update['message']['chat']['id'] ?? null;

if ($chatId !== null && preg_match('/^\/start\s+([A-Za-z0-9]+)$/', $text, $matches)) {
    $userId = consumeTelegramLinkToken($matches[1]);

    if ($userId !== null) {
        $username = $update['message']['from']['username'] ?? null;
        dbExecute(
            "UPDATE users SET telegram_chat_id = ?, telegram_username = ?, telegram_linked_at = NOW() WHERE id = ?",
            [$chatId, $username, $userId]
        );
        sendTelegramMessage($chatId, '✅ Συνδεθήκατε επιτυχώς με το ' . getSetting('app_name', APP_NAME) . '! Θα λαμβάνετε εδώ μηνύματα άμεσης κινητοποίησης από τον διαχειριστή.');
    } else {
        sendTelegramMessage($chatId, 'Ο σύνδεσμος σύνδεσης έχει λήξει ή δεν είναι έγκυρος. Δοκιμάστε ξανά από τις Ρυθμίσεις Ειδοποιήσεων της εφαρμογής.');
    }
}

echo json_encode(['ok' => true]);
