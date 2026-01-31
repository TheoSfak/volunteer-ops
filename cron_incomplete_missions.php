<?php
/**
 * VolunteerOps - Incomplete Mission Alerts (Run daily via cron)
 * This script sends alerts for missions/shifts that are approaching and haven't been filled
 */

if (!defined('VOLUNTEEROPS')) {
    require_once __DIR__ . '/bootstrap.php';
}

// Check if this feature is enabled
$resendEnabled = getSetting('resend_mission_enabled', '1') === '1';
if (!$resendEnabled) {
    echo "Resend mission feature is disabled in settings.\n";
    exit;
}

// Get hours before from settings (default 48)
$hoursBefore = (int)getSetting('resend_mission_hours_before', 48);

// Get shifts starting within the configured hours that are not fully booked
$futureTime = date('Y-m-d H:i:s', strtotime("+{$hoursBefore} hours"));
$now = date('Y-m-d H:i:s');

$incompleteShifts = dbFetchAll(
    "SELECT s.*, m.title as mission_title, m.description as mission_description,
            COUNT(pr.id) as filled_spots,
            (s.max_volunteers - COUNT(pr.id)) as available_spots
     FROM shifts s 
     INNER JOIN missions m ON s.mission_id = m.id 
     LEFT JOIN participation_requests pr ON s.id = pr.shift_id AND pr.status = ?
     WHERE s.start_time BETWEEN ? AND ? 
     AND m.status = 'OPEN'
     GROUP BY s.id
     HAVING available_spots > 0",
    [PARTICIPATION_APPROVED, $now, $futureTime]
);

$sentCount = 0;

foreach ($incompleteShifts as $shift) {
    // Check if notification is enabled
    if (!isNotificationEnabled('mission_needs_volunteers')) {
        continue;
    }
    
    // Check if we already sent this alert recently (don't spam)
    $alreadySent = dbFetchValue(
        "SELECT COUNT(*) FROM notifications 
         WHERE title = ? 
         AND message LIKE ?
         AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)",
        ['Αποστολή Χρειάζεται Εθελοντές', '%' . $shift['mission_title'] . '%']
    );
    
    if ($alreadySent > 0) {
        continue; // Already sent alert in last 24 hours
    }
    
    // Get all active volunteers (not just those in this mission)
    $allVolunteers = dbFetchAll(
        "SELECT * FROM users 
         WHERE role = ? 
         AND is_active = 1",
        [ROLE_VOLUNTEER]
    );
    
    foreach ($allVolunteers as $user) {
        if ($user['email']) {
            $result = sendNotificationEmail('mission_needs_volunteers', $user['email'], [
                'user_name' => $user['name'],
                'mission_title' => $shift['mission_title'],
                'mission_date' => formatDateTime($shift['start_time']),
                'available_spots' => $shift['available_spots'],
                'total_spots' => $shift['max_volunteers']
            ]);
            
            if ($result['success']) {
                $sentCount++;
            }
        }
        
        // In-app notification
        sendNotification(
            $user['id'], 
            'Αποστολή Χρειάζεται Εθελοντές', 
            "Η αποστολή '{$shift['mission_title']}' χρειάζεται {$shift['available_spots']} ακόμα εθελοντές! Ημερομηνία: " . formatDate($shift['start_time'])
        );
    }
}

echo "Incomplete mission alerts sent: $sentCount\n";
echo "Incomplete shifts found: " . count($incompleteShifts) . "\n";
echo "Hours before threshold: {$hoursBefore}\n";
echo "Feature enabled: " . ($resendEnabled ? 'Yes' : 'No') . "\n";
echo "Completed at: " . date('Y-m-d H:i:s') . "\n";
