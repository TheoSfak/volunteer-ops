<?php
/**
 * VolunteerOps - Task Deadline Reminders (Run daily via cron)
 * This script sends reminders for tasks with deadlines within 24 hours
 */

// CLI only - prevent web access
if (php_sapi_name() !== 'cli') {
    die('This script can only be run from command line.');
}

if (!defined('VOLUNTEEROPS')) {
    require_once __DIR__ . '/bootstrap.php';
}

// Get tasks with deadline within 24 hours that are not completed/canceled
$tomorrow = date('Y-m-d H:i:s', strtotime('+24 hours'));
$now = date('Y-m-d H:i:s');

$tasks = dbFetchAll(
    "SELECT t.*, u.name as creator_name 
     FROM tasks t 
     INNER JOIN users u ON t.created_by = u.id 
     WHERE t.deadline BETWEEN ? AND ? 
     AND t.status NOT IN ('COMPLETED', 'CANCELED')
     AND t.deadline IS NOT NULL",
    [$now, $tomorrow]
);

$statusLabels = ['TODO' => 'Προς Εκτέλεση', 'IN_PROGRESS' => 'Σε Εξέλιξη', 'COMPLETED' => 'Ολοκληρωμένη', 'CANCELED' => 'Ακυρωμένη'];

$sentCount = 0;

foreach ($tasks as $task) {
    // Check if notification is enabled
    if (!isNotificationEnabled('task_deadline_reminder')) {
        continue;
    }
    
    // Check if reminder was already sent (don't spam)
    $alreadySent = dbFetchValue(
        "SELECT COUNT(*) FROM notifications 
         WHERE user_id IN (SELECT user_id FROM task_assignments WHERE task_id = ?) 
         AND title = ? 
         AND created_at > DATE_SUB(NOW(), INTERVAL 12 HOUR)",
        [$task['id'], 'Υπενθύμιση Προθεσμίας']
    );
    
    if ($alreadySent > 0) {
        continue; // Already sent reminder in last 12 hours
    }
    
    // Get all assigned users
    $assignedUsers = dbFetchAll(
        "SELECT u.* FROM users u 
         INNER JOIN task_assignments ta ON u.id = ta.user_id 
         WHERE ta.task_id = ?",
        [$task['id']]
    );
    
    foreach ($assignedUsers as $user) {
        if ($user['email']) {
            $result = sendNotificationEmail('task_deadline_reminder', $user['email'], [
                'user_name' => $user['name'],
                'task_title' => $task['title'],
                'task_deadline' => formatDateTime($task['deadline']),
                'task_status' => $statusLabels[$task['status']] ?? $task['status'],
                'task_progress' => $task['progress']
            ]);
            
            if ($result['success']) {
                $sentCount++;
            }
        }
        
        // In-app notification
        sendNotification(
            $user['id'], 
            'Υπενθύμιση Προθεσμίας', 
            "Η εργασία '{$task['title']}' λήγει σε λιγότερο από 24 ώρες!"
        );
    }
    
    // Also notify responsible user
    if ($task['responsible_user_id']) {
        $responsible = dbFetchOne("SELECT * FROM users WHERE id = ?", [$task['responsible_user_id']]);
        if ($responsible && $responsible['email']) {
            $result = sendNotificationEmail('task_deadline_reminder', $responsible['email'], [
                'user_name' => $responsible['name'],
                'task_title' => $task['title'],
                'task_deadline' => formatDateTime($task['deadline']),
                'task_status' => $statusLabels[$task['status']] ?? $task['status'],
                'task_progress' => $task['progress']
            ]);
            
            if ($result['success']) {
                $sentCount++;
            }
        }
        
        sendNotification(
            $task['responsible_user_id'], 
            'Υπενθύμιση Προθεσμίας', 
            "Η εργασία '{$task['title']}' λήγει σε λιγότερο από 24 ώρες!"
        );
    }
}

echo "Task deadline reminders sent: $sentCount\n";
echo "Tasks processed: " . count($tasks) . "\n";
echo "Completed at: " . date('Y-m-d H:i:s') . "\n";
