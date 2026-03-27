<?php
/**
 * VolunteerOps - Logout
 */

require_once __DIR__ . '/bootstrap.php';

// Handle inactivity auto-logout (GET request from JS timer)
if (get('reason') === 'inactivity') {
    logout();
    setFlash('warning', 'Αποσυνδεθήκατε αυτόματα λόγω αδράνειας. Παρακαλώ συνδεθείτε ξανά.');
    redirect('login.php');
}

if (isPost()) {
    verifyCsrf();
    logout();
    setFlash('success', 'Αποσυνδεθήκατε επιτυχώς.');
}

redirect('login.php');
