<?php
/**
 * VolunteerOps - Shift Reminders (Run daily via cron)
 * This script sends reminders for approved shifts happening within configured hours
 */

// CLI or manual admin trigger only
if (php_sapi_name() !== 'cli' && !defined('CRON_MANUAL_RUN')) {
    die('This script can only be run from command line.');
}

if (!defined('VOLUNTEEROPS')) {
    require_once __DIR__ . '/bootstrap.php';
}

// Get reminder hours from settings (default 24)
$reminderHours = (int)getSetting('shift_reminder_hours', 24);

// Get shifts starting within the configured hours
$futureTime = date('Y-m-d H:i:s', strtotime("+{$reminderHours} hours"));
$now = date('Y-m-d H:i:s');

try {
    $shifts = dbFetchAll(
        "SELECT s.*, m.title as mission_title, m.description as mission_description
         FROM shifts s 
         INNER JOIN missions m ON s.mission_id = m.id 
         WHERE s.start_time BETWEEN ? AND ? 
         AND (m.status = '" . STATUS_OPEN . "' OR m.status = '" . STATUS_CLOSED . "')",
        [$now, $futureTime]
    );
} catch (Exception $e) {
    echo "Error fetching shifts: " . $e->getMessage() . "\n";
    return;
}

$sentCount = 0;

foreach ($shifts as $shift) {
    // Check if notification is enabled
    if (!isNotificationEnabled('shift_reminder')) {
        continue;
    }
    
    // Skip duplicate checks for now - simplified
    
    // Get all approved participants for this shift
    $participants = dbFetchAll(
        "SELECT u.*, pr.id as participation_id 
         FROM users u 
         INNER JOIN participation_requests pr ON u.id = pr.volunteer_id 
         WHERE pr.shift_id = ? 
         AND pr.status = ?",
        [$shift['id'], PARTICIPATION_APPROVED]
    );
    
    foreach ($participants as $user) {
        if ($user['email']) {
            $result = sendNotificationEmail('shift_reminder', $user['email'], [
                'user_name' => $user['name'],
                'mission_title' => $shift['mission_title'],
                'shift_time' => formatDateTime($shift['start_time']) . ' - ' . date('H:i', strtotime($shift['end_time'])),
                'shift_date' => formatDate($shift['start_time']),
                'mission_description' => $shift['mission_description'] ?: 'Χωρίς περιγραφή'
            ]);
            
            if ($result['success']) {
                $sentCount++;
            }
        }
        
        // In-app notification
        sendNotification(
            $user['id'], 
            'Υπενθύμιση Βάρδιας', 
            "Σε {$reminderHours} ώρες έχετε βάρδια στην αποστολή '{$shift['mission_title']}' στις " . date('H:i', strtotime($shift['start_time']))
        );
    }
}

echo "Shift reminders sent: $sentCount\n";
echo "Shifts processed: " . count($shifts) . "\n";
echo "Reminder hours before: {$reminderHours}\n";
echo "Completed at: " . date('Y-m-d H:i:s') . "\n";
