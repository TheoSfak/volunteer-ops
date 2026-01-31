INSERT INTO notification_settings (code, name, description, email_enabled) VALUES
('task_assigned', 'Ανάθεση Εργασίας', 'Όταν ανατίθεται μια εργασία σε εθελοντή', 1),
('task_comment', 'Σχόλιο σε Εργασία', 'Όταν προστίθεται σχόλιο σε εργασία', 1),
('task_deadline_reminder', 'Υπενθύμιση Προθεσμίας', 'Όταν πλησιάζει η προθεσμία εργασίας (24h πριν)', 1),
('task_status_changed', 'Αλλαγή Κατάστασης Εργασίας', 'Όταν αλλάζει η κατάσταση μιας εργασίας', 1),
('task_subtask_completed', 'Ολοκλήρωση Υποεργασίας', 'Όταν ολοκληρώνεται μια υποεργασία', 1);
