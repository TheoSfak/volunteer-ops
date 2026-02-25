<?php
require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../includes/export-functions.php';
requireRole([ROLE_SYSTEM_ADMIN, ROLE_DEPARTMENT_ADMIN]);

$period = get('period', 'monthly');

// Department admins pass their department_id
$currentUser = getCurrentUser();
$deptId = ($currentUser['role'] === ROLE_DEPARTMENT_ADMIN) ? $currentUser['department_id'] : null;

exportStatisticsToCsv($period, $deptId);
