<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../includes/export-functions.php';
requireRole([ROLE_SYSTEM_ADMIN, ROLE_DEPARTMENT_ADMIN]);

$filters = [
    'status' => get('status'),
    'mission_id' => get('mission_id'),
    'volunteer_id' => get('volunteer_id')
];

exportParticipationsToCsv($filters);
