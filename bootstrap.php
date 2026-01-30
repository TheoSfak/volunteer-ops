<?php
/**
 * VolunteerOps - Bootstrap file
 * Include this at the top of every page
 */

define('VOLUNTEEROPS', true);

// Load configuration
require_once __DIR__ . '/config.php';

// Load core includes
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/email.php';

// Start session
initSession();
