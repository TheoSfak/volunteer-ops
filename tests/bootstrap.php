<?php
/**
 * PHPUnit bootstrap - loads the same app dependency chain as bootstrap.php,
 * minus the per-request handling (session, headers, guest routing) that
 * doesn't apply outside an HTTP request. Points the app at a disposable
 * test database instead of the real one.
 *
 * Does NOT require includes/migrations.php - that file runs
 * runSchemaMigrations() as a side effect of being loaded, so individual
 * tests require it explicitly once the DB is in the state they want.
 */

define('DEBUG_MODE', true);
define('VOLUNTEEROPS', true);

define('DB_HOST', getenv('TEST_DB_HOST') ?: 'localhost');
define('DB_PORT', getenv('TEST_DB_PORT') ?: '3306');
define('DB_NAME', getenv('TEST_DB_NAME') ?: 'volunteer_ops_test');
define('DB_USER', getenv('TEST_DB_USER') ?: 'root');
define('DB_PASS', getenv('TEST_DB_PASS') ?: '');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions-core.php';
require_once __DIR__ . '/../includes/functions-warroom.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/i18n.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/email.php';
require_once __DIR__ . '/../includes/webpush.php';
require_once __DIR__ . '/../includes/newsletter-functions.php';
require_once __DIR__ . '/../includes/training-functions.php';
require_once __DIR__ . '/../includes/achievements-functions.php';
