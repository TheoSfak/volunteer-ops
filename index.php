<?php
/**
 * VolunteerOps - Index / Redirect
 */

require_once __DIR__ . '/bootstrap.php';

if (isLoggedIn()) {
    redirect('dashboard.php');
} else {
    redirect('login.php');
}
