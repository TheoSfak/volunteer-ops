<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../includes/export-functions.php';
requireRole([ROLE_SYSTEM_ADMIN, ROLE_DEPARTMENT_ADMIN]);

$filters = [
    'role' => get('role'),
    'department_id' => get('department_id')
];

// Department admins can only export their own department
$currentUser = getCurrentUser();
if ($currentUser['role'] === ROLE_DEPARTMENT_ADMIN) {
    $filters['department_id'] = $currentUser['department_id'];
}

exportVolunteersToCsv($filters);
