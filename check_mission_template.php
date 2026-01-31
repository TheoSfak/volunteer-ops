<?php
require_once __DIR__ . '/bootstrap.php';

$template = dbFetchOne('SELECT * FROM email_templates WHERE code = ?', ['mission_needs_volunteers']);
echo json_encode($template, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
