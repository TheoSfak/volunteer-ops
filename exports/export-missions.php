<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../includes/export-functions.php';
requireRole([ROLE_SYSTEM_ADMIN, ROLE_DEPARTMENT_ADMIN, ROLE_BRANCH_ADMIN]);

// Get filters from query string
$filters = [
    'status'        => get('status'),
    'department_id' => get('department_id'),
    'branch_id'     => get('branch_id'),
    'mission_type'  => get('mission_type'),
    'search'        => get('search'),
    'start_date'    => get('start_date'),
    'end_date'      => get('end_date')
];

// Department admins can only export their own department (Σώμα); branch
// admins, only their own branch (Παράρτημα)
$currentUser = getCurrentUser();
if ($currentUser['role'] === ROLE_DEPARTMENT_ADMIN) {
    $filters['department_id'] = $currentUser['department_id'];
} elseif ($currentUser['role'] === ROLE_BRANCH_ADMIN) {
    $filters['branch_id'] = $currentUser['warehouse_id'];
}

exportMissionsToCsv($filters);
