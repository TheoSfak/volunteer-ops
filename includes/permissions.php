<?php
/**
 * VolunteerOps - Custom Role Permission Map
 * Defines all configurable page permissions for custom roles.
 * SYSTEM_ADMIN always has full access regardless of this map.
 * These permissions apply only to VOLUNTEER-role users with a custom_role_id.
 */

if (!defined('VOLUNTEEROPS')) {
    die('Direct access not permitted');
}

/**
 * Returns the full permission map grouped by section.
 * Each entry: ['slug' => string, 'label' => string, 'icon' => string]
 */
function getPermissionMap(): array {
    return [
        'Επιχειρήσεις' => [
            ['slug' => 'ops_dashboard',      'label' => 'Επιχειρησιακό Dashboard',         'icon' => 'bi-broadcast'],
            ['slug' => 'attendance',         'label' => 'Παρουσιολόγιο Βάρδιας',           'icon' => 'bi-clipboard-check'],
        ],
        'Αποστολές' => [
            ['slug' => 'missions_manage',    'label' => 'Δημιουργία/Επεξεργασία Αποστολών','icon' => 'bi-flag'],
            ['slug' => 'shift_manage',       'label' => 'Δημιουργία/Επεξεργασία Βάρδιων',  'icon' => 'bi-clock'],
            ['slug' => 'tasks_manage',       'label' => 'Δημιουργία Εργασιών',             'icon' => 'bi-list-task'],
        ],
        'Εθελοντές' => [
            ['slug' => 'volunteers_view',    'label' => 'Προβολή Προφίλ Εθελοντών',        'icon' => 'bi-person-badge'],
            ['slug' => 'volunteers_manage',  'label' => 'Διαχείριση Εθελοντών',            'icon' => 'bi-people'],
            ['slug' => 'inactive_volunteers','label' => 'Ανενεργοί Εθελοντές',             'icon' => 'bi-person-x'],
            ['slug' => 'reports',            'label' => 'Αναφορές',                        'icon' => 'bi-graph-up'],
            ['slug' => 'complaints',         'label' => 'Παράπονα',                        'icon' => 'bi-chat-left-dots'],
        ],
        'Εκπαίδευση' => [
            ['slug' => 'training_admin',     'label' => 'Διαχείριση Εκπαίδευσης',         'icon' => 'bi-gear'],
        ],
        'Απόθεμα' => [
            ['slug' => 'inventory_admin',    'label' => 'Διαχείριση Αποθέματος',           'icon' => 'bi-box-seam'],
        ],
        'Πολίτες' => [
            ['slug' => 'citizens',           'label' => 'Πολίτες & Πιστοποιητικά',         'icon' => 'bi-person-vcard'],
        ],
        'Ρυθμίσεις' => [
            ['slug' => 'positions',          'label' => 'Θέσεις Εθελοντών',               'icon' => 'bi-person-badge'],
            ['slug' => 'skills',             'label' => 'Δεξιότητες',                     'icon' => 'bi-stars'],
            ['slug' => 'certificates',       'label' => 'Πιστοποιητικά Εθελοντών',        'icon' => 'bi-award'],
        ],
    ];
}

/**
 * Returns a flat array of all permission slugs.
 */
function getAllPermissionSlugs(): array {
    $slugs = [];
    foreach (getPermissionMap() as $perms) {
        foreach ($perms as $perm) {
            $slugs[] = $perm['slug'];
        }
    }
    return $slugs;
}
