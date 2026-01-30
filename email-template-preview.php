<?php
/**
 * VolunteerOps - Email Template Preview
 * Returns raw HTML for iframe preview
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();
requireRole([ROLE_SYSTEM_ADMIN]);

$id = (int)get('id', 0);

if (!$id) {
    die('Template not found');
}

$template = dbFetchOne("SELECT * FROM email_templates WHERE id = ?", [$id]);

if (!$template) {
    die('Template not found');
}

// Sample data for preview
$sampleData = [
    'app_name' => getSetting('app_name', 'VolunteerOps'),
    'user_name' => 'Γιάννης Παπαδόπουλος',
    'user_email' => 'giannis@example.com',
    'mission_title' => 'Εθελοντική Δράση - Καθαρισμός Παραλίας',
    'mission_description' => 'Συμμετέχετε στην εθελοντική δράση για τον καθαρισμό της παραλίας. Θα παρέχονται γάντια, σακούλες και εξοπλισμός.',
    'shift_date' => '15/02/2026',
    'shift_time' => '09:00 - 14:00',
    'location' => 'Παραλία Γλυφάδας, Αθήνα',
    'start_date' => '10/02/2026',
    'end_date' => '20/02/2026',
    'points' => '50',
    'total_points' => '350',
    'login_url' => 'http://localhost/volunteerops/login.php',
    'mission_url' => 'http://localhost/volunteerops/mission-view.php?id=1',
];

$html = $template['body_html'];

// Replace all variables
foreach ($sampleData as $key => $value) {
    $html = str_replace('{{' . $key . '}}', $value, $html);
}

// Output raw HTML
header('Content-Type: text/html; charset=utf-8');
echo $html;
