<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../includes/export-functions.php';
requireRole([ROLE_SYSTEM_ADMIN, ROLE_DEPARTMENT_ADMIN]);

// Get filters from query string
$filters = [
    'status' => get('status'),
    'department_id' => get('department_id'),
    'type' => get('type'),
    'start_date' => get('start_date'),
    'end_date' => get('end_date')
];

exportMissionsToCsv($filters);
