<?php
/** Daily annual subscription expiry reminders for volunteers and administrators. */
if (php_sapi_name() !== 'cli' && !defined('CRON_MANUAL_RUN')) die('CLI only.');
if (!defined('VOLUNTEEROPS')) require_once __DIR__ . '/bootstrap.php';

$intervals = [
    ['days' => 90, 'column' => 'reminder_sent_3m', 'label' => '3 μήνες'],
    ['days' => 30, 'column' => 'reminder_sent_1m', 'label' => '1 μήνας'],
    ['days' => 7,  'column' => 'reminder_sent_1w', 'label' => '1 εβδομάδα'],
    ['days' => 0,  'column' => 'reminder_sent_expired', 'label' => 'λήξη'],
];
$admins = dbFetchAll("SELECT id, name, email FROM users WHERE is_active = 1 AND role IN (?, ?)", [ROLE_SYSTEM_ADMIN, ROLE_DEPARTMENT_ADMIN]);
$processed = 0;

foreach ($intervals as $interval) {
    $column = $interval['column'];
    $subscriptions = dbFetchAll(
        $interval['days'] > 0
            ? "SELECT vs.*, u.name, u.email FROM volunteer_subscriptions vs JOIN users u ON u.id = vs.user_id WHERE vs.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY) AND vs.{$column} = 0"
            : "SELECT vs.*, u.name, u.email FROM volunteer_subscriptions vs JOIN users u ON u.id = vs.user_id WHERE vs.expiry_date <= CURDATE() AND vs.{$column} = 0",
        $interval['days'] > 0 ? [$interval['days']] : []
    );
    foreach ($subscriptions as $subscription) {
        $daysLeft = max(0, (int)floor((strtotime($subscription['expiry_date']) - strtotime(date('Y-m-d'))) / 86400));
        $expired = $interval['days'] === 0;
        $title = $expired ? 'Η ετήσια συνδρομή έληξε' : 'Υπενθύμιση ετήσιας συνδρομής';
        $message = $expired
            ? 'Η ετήσια συνδρομή σας έληξε στις ' . formatDate($subscription['expiry_date']) . '.'
            : 'Η ετήσια συνδρομή σας λήγει στις ' . formatDate($subscription['expiry_date']) . ' (σε ' . $daysLeft . ' ημέρες).';
        dbInsert("INSERT INTO notifications (user_id, type, title, message, created_at) VALUES (?, 'system', ?, ?, NOW())", [$subscription['user_id'], $title, $message]);
        if (!empty($subscription['email']) && isEmailConfigured()) sendEmail($subscription['email'], $title, '<p>Αγαπητέ/ή ' . h($subscription['name']) . ',</p><p>' . h($message) . '</p>');
        foreach ($admins as $admin) {
            $adminMessage = 'Η συνδρομή του/της ' . $subscription['name'] . ' ' . ($expired ? 'έληξε' : 'λήγει') . ' στις ' . formatDate($subscription['expiry_date']) . '.';
            dbInsert("INSERT INTO notifications (user_id, type, title, message, created_at) VALUES (?, 'system', ?, ?, NOW())", [$admin['id'], $title, $adminMessage]);
            if (!empty($admin['email']) && isEmailConfigured()) sendEmail($admin['email'], $title, '<p>' . h($adminMessage) . '</p>');
        }
        dbExecute("UPDATE volunteer_subscriptions SET {$column} = 1 WHERE id = ?", [$subscription['id']]);
        $processed++;
    }
}
echo "Subscription reminders processed: {$processed}\n";
