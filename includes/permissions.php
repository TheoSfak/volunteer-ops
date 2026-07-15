<?php
/**
 * VolunteerOps - Custom Role Permission Map
 * Fine-grained page permissions for custom roles.
 * SYSTEM_ADMIN always has full access regardless of this map.
 * Applies only to VOLUNTEER-base users with a custom_role_id.
 *
 * Implication rules (enforced in auth.php::hasPagePermission):
 *   missions_manage   → implies missions_view
 *   complaints_manage → implies complaints_view
 *   training_manage   → implies training_view
 *   questions_manage  → implies training_view
 *   citizens_manage   → implies citizens_view
 *   inventory_manage  → implies inventory_view
 *   volunteers_manage → implies volunteers_view
 */

if (!defined('VOLUNTEEROPS')) {
    die('Direct access not permitted');
}

/**
 * Returns the full permission map grouped by section.
 * Each entry: ['slug' => string, 'label' => string, 'icon' => string, 'description' => string]
 */
function getPermissionMap(): array {
    return [
        'Επιχειρήσεις' => [
            ['slug' => 'ops_dashboard',      'label' => 'Επιχειρησιακό Dashboard',              'icon' => 'bi-broadcast',        'description' => 'Ζωντανή προβολή αποστολών & βαρδιών'],
            ['slug' => 'attendance_manage',  'label' => 'Παρουσιολόγιο Βάρδιας',               'icon' => 'bi-clipboard-check',  'description' => 'Καταγραφή παρουσιών εθελοντών'],
        ],
        'Αποστολές' => [
            ['slug' => 'missions_view',      'label' => 'Προβολή Αποστολών (όλες)',             'icon' => 'bi-eye',              'description' => 'Βλέπει πρόχειρες, κλειστές & ολοκληρωμένες (implied από missions_manage)'],
            ['slug' => 'missions_manage',    'label' => 'Διαχείριση Αποστολών',                 'icon' => 'bi-flag',             'description' => 'Δημιουργία, επεξεργασία, αλλαγή κατάστασης, διαγραφή αποστολών'],
            ['slug' => 'shifts_manage',      'label' => 'Διαχείριση Βάρδιων',                  'icon' => 'bi-clock',            'description' => 'Δημιουργία βαρδιών, έγκριση/απόρριψη συμμετοχών'],
            ['slug' => 'tasks_manage',       'label' => 'Δημιουργία Εργασιών',                 'icon' => 'bi-list-task',        'description' => 'Δημιουργία & ανάθεση εργασιών'],
        ],
        'Εθελοντές' => [
            ['slug' => 'volunteers_view',    'label' => 'Προβολή Προφίλ Εθελοντών',            'icon' => 'bi-person-badge',     'description' => 'Ανάγνωση προφίλ & εγγράφων (implied από volunteers_manage)'],
            ['slug' => 'volunteers_manage',  'label' => 'Διαχείριση Εθελοντών',                'icon' => 'bi-people',           'description' => 'Πλήρης λίστα, δημιουργία, επεξεργασία εθελοντών'],
            ['slug' => 'inactive_volunteers','label' => 'Ανενεργοί Εθελοντές',                 'icon' => 'bi-person-x',         'description' => 'Προβολή & ενεργοποίηση ανενεργών εθελοντών'],
            ['slug' => 'reports',            'label' => 'Αναφορές',                            'icon' => 'bi-graph-up',         'description' => 'Στατιστικές αναφορές & αναφορά δήμου'],
            ['slug' => 'complaints_view',    'label' => 'Παράπονα (Προβολή)',                  'icon' => 'bi-chat-left-dots',   'description' => 'Βλέπει παράπονα (implied από complaints_manage)'],
            ['slug' => 'complaints_manage',  'label' => 'Παράπονα (Διαχείριση)',               'icon' => 'bi-chat-left-text',   'description' => 'Αλλαγή κατάστασης, ανάθεση, απάντηση σε παράπονα'],
        ],
        'Εκπαίδευση' => [
            ['slug' => 'training_view',      'label' => 'Εκπαίδευση (Προβολή)',                'icon' => 'bi-book',             'description' => 'Διαχείριση κατηγοριών & υλικού, στατιστικά εξετάσεων (implied από training_manage & questions_manage)'],
            ['slug' => 'training_manage',    'label' => 'Εκπαίδευση (Διαχείριση)',             'icon' => 'bi-mortarboard',      'description' => 'Δημιουργία διαγωνισμάτων, κουίζ & εκπαιδευτικού υλικού'],
            ['slug' => 'questions_manage',   'label' => 'Διαχείριση Ερωτήσεων',               'icon' => 'bi-question-circle',  'description' => 'Pool ερωτήσεων, ερωτήσεις εξετάσεων & κουίζ'],
        ],
        'Απόθεμα' => [
            ['slug' => 'inventory_view',     'label' => 'Απόθεμα (Προβολή Διαχείρισης)',       'icon' => 'bi-eye',              'description' => 'Ράφια, κατηγορίες, τοποθεσίες (implied από inventory_manage)'],
            ['slug' => 'inventory_manage',   'label' => 'Απόθεμα (Διαχείριση)',                'icon' => 'bi-box-seam',         'description' => 'Σημειώσεις υλικών, φόρμες & διαχείριση αποθέματος'],
        ],
        'Πολίτες' => [
            ['slug' => 'citizens_view',      'label' => 'Πολίτες (Προβολή)',                   'icon' => 'bi-person-vcard',     'description' => 'Λίστα πολιτών (implied από citizens_manage)'],
            ['slug' => 'citizens_manage',    'label' => 'Πολίτες (Διαχείριση)',                'icon' => 'bi-file-earmark-medical', 'description' => 'Πιστοποιητικά πολιτών & τύποι πιστοποιητικών'],
        ],
        'Ρυθμίσεις' => [
            ['slug' => 'positions_manage',   'label' => 'Θέσεις Εθελοντών',                   'icon' => 'bi-person-badge',     'description' => 'Διαχείριση θέσεων/ρόλων εντός του σώματος'],
            ['slug' => 'skills_manage',      'label' => 'Δεξιότητες',                         'icon' => 'bi-stars',            'description' => 'Διαχείριση δεξιοτήτων εθελοντών'],
            ['slug' => 'certificates_manage','label' => 'Πιστοποιητικά Εθελοντών',            'icon' => 'bi-award',            'description' => 'Διαχείριση πιστοποιητικών & ειδικοτήτων'],
            ['slug' => 'subscriptions_manage','label' => 'Ετήσιες Συνδρομές',                 'icon' => 'bi-cash-coin',        'description' => 'Καταχώρηση πληρωμών, αποδείξεων και λήξεων συνδρομών'],
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

/**
 * Returns slugs that are implied by another slug (so they cannot be independently set).
 * Key = implied slug, Value = the slug that grants it.
 */
function getImpliedSlugs(): array {
    return [
        'missions_view'   => 'missions_manage',
        'complaints_view' => 'complaints_manage',
        'training_view'   => ['training_manage', 'questions_manage'],
        'citizens_view'   => 'citizens_manage',
        'inventory_view'  => 'inventory_manage',
        'volunteers_view' => 'volunteers_manage',
    ];
}
