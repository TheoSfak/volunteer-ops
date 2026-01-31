INSERT IGNORE INTO notification_settings (code, name, description, email_enabled) VALUES 
('mission_needs_volunteers', 'Αποστολή Χρειάζεται Εθελοντές', 'Όταν μια αποστολή πλησιάζει και δεν έχει αρκετούς εθελοντές', 1);

INSERT INTO email_templates (code, name, subject, body_html, available_variables, description) VALUES
('mission_needs_volunteers', 'Αποστολή Χρειάζεται Εθελοντές', 'Επείγον: Χρειαζόμαστε Εθελοντές - {{mission_title}}',
'<p>Γεια σας {{user_name}},</p>
<p>Η αποστολή "<strong>{{mission_title}}</strong>" πλησιάζει και χρειάζεται περισσότερους εθελοντές!</p>
<h3 style="color: #dc3545;">⚠️ Επείγουσα Ανάγκη</h3>
<ul>
<li><strong>Ημερομηνία:</strong> {{mission_date}}</li>
<li><strong>Κενές Θέσεις:</strong> {{available_spots}}</li>
<li><strong>Σύνολο Θέσεων:</strong> {{total_spots}}</li>
</ul>
<p>Αν μπορείτε να βοηθήσετε, παρακαλούμε συνδεθείτε στο σύστημα και κάντε αίτηση συμμετοχής.</p>
<p style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0;">
<strong>Η βοήθειά σας χρειάζεται!</strong><br>
Κάθε εθελοντής κάνει τη διαφορά.
</p>',
'user_name, mission_title, mission_date, available_spots, total_spots',
'Αποστέλλεται όταν αποστολή πλησιάζει και δεν έχει συμπληρωθεί')
ON DUPLICATE KEY UPDATE body_html = VALUES(body_html);

UPDATE notification_settings ns 
INNER JOIN email_templates et ON ns.code = et.code 
SET ns.email_template_id = et.id 
WHERE ns.code = 'mission_needs_volunteers';

