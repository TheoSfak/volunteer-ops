<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../includes/export-functions.php';
requireRole([ROLE_SYSTEM_ADMIN, ROLE_DEPARTMENT_ADMIN]);

// Mirrors volunteers.php's filters exactly, so the export always matches
// whatever tab/filters are currently on screen instead of the whole table.
$isGuestTab = get('is_external', '') === '1';

$filters = [
    'search' => get('search'),
    'volunteer_type' => get('volunteer_type'),
    'department_id' => get('department_id'),
    'warehouse_id' => get('warehouse_id'),
    'skill_id' => (int) get('skill_id', 0),
    'dog_handler' => get('dog_handler') === '1',
    'is_external' => $isGuestTab ? 1 : 0,
    'guest_kind' => $isGuestTab ? get('guest_kind', '') : '',
];

// Department admins can only export their own department
$currentUser = getCurrentUser();
if ($currentUser['role'] === ROLE_DEPARTMENT_ADMIN) {
    $filters['department_id'] = $currentUser['department_id'];
}

exportVolunteersToCsv($filters);
