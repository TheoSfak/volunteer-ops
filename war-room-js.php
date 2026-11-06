<?php
/**
 * VolunteerOps - sends the pieces of the Action Room page's script that are files of their own (see
 * includes/war-room-bundles.php for what they are and why). Only the page's <script src> asks for it.
 *
 * Deliberately not the usual entry point: no bootstrap, no session, no database. A script file that started a session
 * would hold the session lock the page's own poll waits for, and the file is the same for everybody, so there is
 * nothing here to log in for. What it reads is the translation file, which holds no secret and is already sent to
 * every viewer inside the page.
 */

define('VOLUNTEEROPS', true);

try {
    require_once __DIR__ . '/includes/i18n.php';
    require_once __DIR__ . '/includes/war-room-bundles.php';
    $answer = wrBundleRespond($_GET, $_SERVER, __DIR__ . '/storage/wr-js');
} catch (\Throwable $e) {
    $answer = [
        'status' => 500,
        'headers' => ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff'],
        'body' => "Could not build this file\n",
    ];
}

http_response_code($answer['status']);
foreach ($answer['headers'] as $name => $value) {
    header($name . ': ' . $value);
}
echo $answer['body'];
