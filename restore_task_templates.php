<?php
/**
 * Restore Task Email Templates with proper Greek encoding
 * Run this script to fix Greek character encoding in task-related email templates
 */

require_once __DIR__ . '/bootstrap.php';

try {
    // Delete existing task-related templates
    dbExecute("DELETE FROM email_templates WHERE code IN ('task_assigned', 'task_comment', 'task_deadline_reminder', 'task_status_changed', 'task_subtask_completed')");
    echo "Διαγραφή παλιών templates... ✓\n";
    
    // Insert task templates with proper Greek encoding
    $templates = [
        [
            'code' => 'task_assigned',
            'name' => 'Ανάθεση Εργασίας',
            'subject' => 'Νέα Εργασία: {{task_title}}',
            'body_html' => '<p>Γεια σας {{user_name}},</p>
<p>Σας ανατέθηκε μια νέα εργασία από τον/την <strong>{{assigned_by}}</strong>.</p>
<h3>Λεπτομέρειες Εργασίας</h3>
<ul>
<li><strong>Τίτλος:</strong> {{task_title}}</li>
<li><strong>Περιγραφή:</strong> {{task_description}}</li>
<li><strong>Προτεραιότητα:</strong> {{task_priority}}</li>
<li><strong>Προθεσμία:</strong> {{task_deadline}}</li>
</ul>
<p>Μπορείτε να δείτε τις λεπτομέρειες της εργασίας συνδεόμενοι στο σύστημα.</p>',
            'available_variables' => 'user_name, task_title, task_description, task_priority, task_deadline, assigned_by',
            'description' => 'Αποστέλλεται όταν ανατίθεται εργασία σε εθελοντή'
        ],
        [
            'code' => 'task_comment',
            'name' => 'Σχόλιο σε Εργασία',
            'subject' => 'Νέο Σχόλιο στην Εργασία: {{task_title}}',
            'body_html' => '<p>Γεια σας {{user_name}},</p>
<p>Ο/Η <strong>{{commented_by}}</strong> πρόσθεσε ένα νέο σχόλιο στην εργασία "<strong>{{task_title}}</strong>".</p>
<blockquote style="border-left: 3px solid #007bff; padding-left: 15px; margin: 20px 0; color: #555;">
{{comment}}
</blockquote>
<p>Συνδεθείτε στο σύστημα για να δείτε την εργασία και να απαντήσετε.</p>',
            'available_variables' => 'user_name, task_title, comment, commented_by',
            'description' => 'Αποστέλλεται όταν προστίθεται σχόλιο σε εργασία'
        ],
        [
            'code' => 'task_deadline_reminder',
            'name' => 'Υπενθύμιση Προθεσμίας',
            'subject' => 'Υπενθύμιση: Η εργασία {{task_title}} λήγει σύντομα',
            'body_html' => '<p>Γεια σας {{user_name}},</p>
<p>Σας υπενθυμίζουμε ότι η εργασία "<strong>{{task_title}}</strong>" λήγει σε λιγότερο από 24 ώρες.</p>
<ul>
<li><strong>Προθεσμία:</strong> {{task_deadline}}</li>
<li><strong>Κατάσταση:</strong> {{task_status}}</li>
<li><strong>Πρόοδος:</strong> {{task_progress}}%</li>
</ul>
<p>Παρακαλούμε συνδεθείτε στο σύστημα για να ολοκληρώσετε την εργασία έγκαιρα.</p>',
            'available_variables' => 'user_name, task_title, task_deadline, task_status, task_progress',
            'description' => 'Αποστέλλεται 24 ώρες πριν τη λήξη προθεσμίας εργασίας'
        ],
        [
            'code' => 'task_status_changed',
            'name' => 'Αλλαγή Κατάστασης Εργασίας',
            'subject' => 'Αλλαγή Κατάστασης: {{task_title}}',
            'body_html' => '<p>Γεια σας {{user_name}},</p>
<p>Ο/Η <strong>{{changed_by}}</strong> άλλαξε την κατάσταση της εργασίας "<strong>{{task_title}}</strong>".</p>
<p style="font-size: 16px; margin: 20px 0;">
<span style="background: #f8d7da; padding: 5px 10px; border-radius: 3px; text-decoration: line-through;">{{old_status}}</span>
<span style="margin: 0 10px;">→</span>
<span style="background: #d4edda; padding: 5px 10px; border-radius: 3px;">{{new_status}}</span>
</p>
<p>Συνδεθείτε στο σύστημα για να δείτε τις λεπτομέρειες.</p>',
            'available_variables' => 'user_name, task_title, old_status, new_status, changed_by',
            'description' => 'Αποστέλλεται όταν αλλάζει η κατάσταση εργασίας'
        ],
        [
            'code' => 'task_subtask_completed',
            'name' => 'Ολοκλήρωση Υποεργασίας',
            'subject' => 'Ολοκληρώθηκε Υποεργασία στην: {{task_title}}',
            'body_html' => '<p>Γεια σας {{user_name}},</p>
<p>Ο/Η <strong>{{completed_by}}</strong> ολοκλήρωσε την υποεργασία "<strong>{{subtask_title}}</strong>" στην εργασία "<strong>{{task_title}}</strong>".</p>
<p style="background: #d4edda; border-left: 4px solid #28a745; padding: 15px; margin: 20px 0;">
✓ Η υποεργασία έχει σημανθεί ως ολοκληρωμένη
</p>
<p>Συνδεθείτε στο σύστημα για να δείτε την πρόοδο της εργασίας.</p>',
            'available_variables' => 'user_name, task_title, subtask_title, completed_by',
            'description' => 'Αποστέλλεται όταν ολοκληρώνεται υποεργασία'
        ]
    ];
    
    $count = 0;
    foreach ($templates as $template) {
        dbExecute(
            "INSERT INTO email_templates (code, name, subject, body_html, available_variables, description) VALUES (?, ?, ?, ?, ?, ?)",
            [
                $template['code'],
                $template['name'],
                $template['subject'],
                $template['body_html'],
                $template['available_variables'],
                $template['description']
            ]
        );
        $count++;
        echo "Προσθήκη template: {$template['name']} ✓\n";
    }
    
    echo "\n=== Ολοκληρώθηκε ===\n";
    echo "Επαναφορά {$count} task email templates με σωστά ελληνικά.\n";
    
} catch (Exception $e) {
    echo "ΣΦΑΛΜΑ: " . $e->getMessage() . "\n";
    exit(1);
}
