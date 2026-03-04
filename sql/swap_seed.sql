-- Shift Swap email templates
INSERT IGNORE INTO email_templates (code, name, subject, body_html, is_active) VALUES
('shift_swap_requested',
 'Αίτημα Αντικατάστασης Βάρδιας',
 'Αίτημα αντικατάστασης για αποστολή {{mission_title}}',
 '<div style="font-family:Arial,sans-serif"><div style="background:#8e44ad;color:#fff;padding:16px 20px"><h2 style="margin:0">&#128257; Αίτημα Αντικατάστασης</h2></div><div style="padding:20px"><p>Γεια, <strong>{{user_name}}</strong>!</p><p>Ο/Η <strong>{{requester_name}}</strong> σας ζητά να τον/την αντικαταστήσετε στη βάρδια:</p><ul><li><strong>Αποστολή:</strong> {{mission_title}}</li><li><strong>Ημερομηνία:</strong> {{shift_date}}</li><li><strong>Ώρα:</strong> {{shift_time}}</li></ul>{{#message}}<blockquote>{{message}}</blockquote>{{/message}}<p>Συνδεθείτε για να αποδεχτείτε ή να αρνηθείτε το αίτημα.</p></div></div>',
 1),
('shift_swap_accepted',
 'Αποδοχή Αιτήματος Αντικατάστασης',
 'Ο/Η {{replacement_name}} αποδέχτηκε το αίτημα αντικατάστασης',
 '<div style="font-family:Arial,sans-serif"><div style="background:#27ae60;color:#fff;padding:16px 20px"><h2 style="margin:0">&#10003; Αποδοχή Αντικατάστασης</h2></div><div style="padding:20px"><p>Γεια, <strong>{{user_name}}</strong>!</p><p>Ο/Η <strong>{{replacement_name}}</strong> αποδέχτηκε το αίτημά σας για αντικατάσταση στη βάρδια:</p><ul><li><strong>Αποστολή:</strong> {{mission_title}}</li><li><strong>Ημερομηνία:</strong> {{shift_date}}</li><li><strong>Ώρα:</strong> {{shift_time}}</li></ul><p>Αναμένεται η τελική έγκριση από τον διαχειριστή.</p></div></div>',
 1),
('shift_swap_approved',
 'Έγκριση Αντικατάστασης Βάρδιας',
 'Η αντικατάσταση για {{mission_title}} εγκρίθηκε',
 '<div style="font-family:Arial,sans-serif"><div style="background:#2980b9;color:#fff;padding:16px 20px"><h2 style="margin:0">&#9989; Αντικατάσταση Εγκρίθηκε</h2></div><div style="padding:20px"><p>Γεια, <strong>{{user_name}}</strong>!</p><p>Η αντικατάσταση για τη βάρδια εγκρίθηκε από τον διαχειριστή:</p><ul><li><strong>Αποστολή:</strong> {{mission_title}}</li><li><strong>Ημερομηνία:</strong> {{shift_date}}</li><li><strong>Ώρα:</strong> {{shift_time}}</li></ul></div></div>',
 1);

-- Notification settings for shift swap
INSERT IGNORE INTO notification_settings (code, name, email_enabled, email_template_id) VALUES
('shift_swap_requested', 'Αίτημα αντικατάστασης βάρδιας (προς αντικατάστατη)', 1, (SELECT id FROM email_templates WHERE code = 'shift_swap_requested')),
('shift_swap_accepted',  'Αποδοχή αιτήματος αντικατάστασης (προς αιτούντα)',   1, (SELECT id FROM email_templates WHERE code = 'shift_swap_accepted')),
('shift_swap_approved',  'Έγκριση αντικατάστασης (και στους δύο)',               1, (SELECT id FROM email_templates WHERE code = 'shift_swap_approved'));
