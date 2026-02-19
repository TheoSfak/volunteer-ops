<?php
require_once __DIR__ . '/bootstrap.php';
requireLogin();
requireRole([ROLE_SYSTEM_ADMIN]);

// Rename warehouse departments: remove "Αποθήκη" prefix
dbExecute("UPDATE departments SET name = 'Ηράκλειο' WHERE id = 38");
dbExecute("UPDATE departments SET name = 'Χερσόνησος' WHERE id = 39");

$rows = dbFetchAll("SELECT id, name, has_inventory FROM departments ORDER BY name");
foreach ($rows as $r) {
    echo $r['id'] . ' | ' . $r['name'] . ' | inv=' . $r['has_inventory'] . PHP_EOL;
}
echo "Done.\n";
