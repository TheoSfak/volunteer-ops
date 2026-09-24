<?php
/**
 * VolunteerOps - Logout
 */

require_once __DIR__ . '/bootstrap.php';

// Handle inactivity auto-logout (GET request from JS timer)
if (get('reason') === 'inactivity') {
    // A remembered device is never idle-logged-out (see includes/auth.php);
    // a tab rendered before the box was ticked can still land here, and
    // logging out would also forget the device.
    if (isLoggedIn() && rememberedDeviceStillValid()) {
        redirect('dashboard.php');
    }
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
