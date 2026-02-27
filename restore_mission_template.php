<?php
/**
 * Restore Mission Email Template with proper Greek encoding
 */

require_once __DIR__ . '/bootstrap.php';
requireRole([ROLE_SYSTEM_ADMIN]);

try {
    // Delete existing mission template
    dbExecute("DELETE FROM email_templates WHERE code = 'mission_needs_volunteers'");
    echo "Διαγραφή παλιού template... ✓\n";
    
    // Insert with proper Greek encoding
    dbExecute(
        "INSERT INTO email_templates (code, name, subject, body_html, available_variables, description) VALUES (?, ?, ?, ?, ?, ?)",
        [
            'mission_needs_volunteers',
            'Αποστολή Χρειάζεται Εθελοντές',
            'Επείγον: Χρειάζονται Εθελοντές - {{mission_title}}',
            '<p>Γεια σας {{user_name}},</p>
<p>Η αποστολή "<strong>{{mission_title}}</strong>" χρειάζεται επειγόντως περισσότερους εθελοντές!</p>
<h3 style="color: #dc3545;">Θέσεις Διαθέσιμες</h3>
<ul>
<li><strong>Ημερομηνία:</strong> {{mission_date}}</li>
<li><strong>Θέσεις Ανοιχτές:</strong> {{available_spots}}</li>
<li><strong>Συνολικές Θέσεις:</strong> {{total_spots}}</li>
</ul>
<p>Αν ενδιαφέρεστε να συμμετέχετε, παρακαλούμε συνδεθείτε στο σύστημα και κάντε αίτηση συμμετοχής.</p>
<p style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0;">
<strong>Η βοήθειά σας χρειάζεται!</strong><br>
Κάθε εθελοντής κάνει τη διαφορά.
</p>',
            'user_name, mission_title, mission_date, available_spots, total_spots',
            'Αποστέλλεται όταν αποστολή χρειάζεται και δεν είναι πλήρης'
        ]
    );
    
    echo "Προσθήκη template: Αποστολή Χρειάζεται Εθελοντές ✓\n";
    echo "\n=== Ολοκληρώθηκε ===\n";
    echo "Επαναφορά mission email template με σωστά ελληνικά.\n";
    
} catch (Exception $e) {
    echo "ΣΦΑΛΜΑ: " . $e->getMessage() . "\n";
    exit(1);
}
