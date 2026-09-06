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
 * Every call is recorded via logAudit() (action 'telegram_webhook_message'/
 * 'telegram_webhook_rejected') so a failure is debuggable from Αρχείο
 * Καταγραφής in the admin UI - this is a public endpoint Telegram's servers
 * call directly, so there is no browser request/response to inspect and no
 * access to server-side PHP error logs from here.
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
    logAudit('telegram_webhook_rejected', null, null, [
        'reason' => $expectedSecret === '' ? 'no_secret_configured' : 'secret_mismatch',
    ]);
    http_response_code(401);
    echo json_encode(['ok' => false]);
    exit;
}

$update = json_decode(file_get_contents('php://input'), true);
$text = trim($update['message']['text'] ?? '');
$chatId = $update['message']['chat']['id'] ?? null;

$outcome = 'ignored_not_start_command';

if ($chatId !== null && preg_match('/^\/start(?:\s+([A-Za-z0-9]+))?$/', $text, $matches)) {
    $token = $matches[1] ?? '';

    if ($token === '') {
        // Deep-link opened against a chat Telegram already treats as
        // "known" (e.g. a second Σύνδεση attempt): some clients then just
        // prefill "/start" in the composer instead of resending the
        // original ?start= payload, so there is no token to resolve back
        // to a VolunteerOps account. Told plainly instead of silently
        // doing nothing, which is what happened before this branch existed.
        $outcome = 'start_without_token';
        sendTelegramMessage($chatId, 'Δεν βρέθηκε κωδικός σύνδεσης σε αυτό το μήνυμα. Επιστρέψτε στην εφαρμογή VolunteerOps, ανοίξτε τις Ρυθμίσεις Ειδοποιήσεων και πατήστε ξανά «Σύνδεση». Αν ξαναγράψει μόνο «/start» χωρίς κωδικό, διαγράψτε πρώτα αυτή τη συνομιλία εδώ στο Telegram και ξαναπατήστε «Σύνδεση».');
    } else {
        $userId = consumeTelegramLinkToken($token);

        if ($userId !== null) {
            $username = $update['message']['from']['username'] ?? null;
            dbExecute(
                "UPDATE users SET telegram_chat_id = ?, telegram_username = ?, telegram_linked_at = NOW() WHERE id = ?",
                [$chatId, $username, $userId]
            );
            $outcome = 'linked:' . $userId;
            sendTelegramMessage($chatId, '✅ Συνδεθήκατε επιτυχώς με το ' . getSetting('app_name', APP_NAME) . '! Θα λαμβάνετε εδώ μηνύματα άμεσης κινητοποίησης από τον διαχειριστή.');
        } else {
            $outcome = 'expired_or_unknown_token';
            sendTelegramMessage($chatId, 'Ο σύνδεσμος σύνδεσης έχει λήξει ή δεν είναι έγκυρος. Δοκιμάστε ξανά από τις Ρυθμίσεις Ειδοποιήσεων της εφαρμογής.');
        }
    }
}

logAudit('telegram_webhook_message', null, null, [
    'chat_id' => $chatId,
    'text' => mb_substr($text, 0, 200),
    'outcome' => $outcome,
]);

echo json_encode(['ok' => true]);
