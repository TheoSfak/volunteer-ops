<?php
/**
 * VolunteerOps - Logout
 */

require_once __DIR__ . '/bootstrap.php';

if (isPost()) {
    verifyCsrf();
    logout();
    setFlash('success', 'Αποσυνδεθήκατε επιτυχώς.');
}

redirect('login.php');
