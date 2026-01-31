-- Fix Greek encoding in notification_settings

UPDATE notification_settings SET 
    name = 'Καλωσόρισμα',
    description = 'Όταν γίνεται νέος χρήστης'
WHERE code = 'welcome';

UPDATE notification_settings SET 
    name = 'Νέα Αποστολή',
    description = 'Όταν δημιουργείται νέα αποστολή'
WHERE code = 'new_mission';

UPDATE notification_settings SET 
    name = 'Έγκριση Συμμετοχής',
    description = 'Όταν εγκρίνεται η αίτηση συμμετοχής σε βάρδια'
WHERE code = 'participation_approved';

UPDATE notification_settings SET 
    name = 'Απόρριψη Συμμετοχής',
    description = 'Όταν απορρίπτεται η αίτηση συμμετοχής'
WHERE code = 'participation_rejected';

UPDATE notification_settings SET 
    name = 'Υπενθύμιση Βάρδιας',
    description = 'Μία μέρα πριν τη βάρδια'
WHERE code = 'shift_reminder';

UPDATE notification_settings SET 
    name = 'Ακύρωση Αποστολής',
    description = 'Όταν ακυρώνεται αποστολή'
WHERE code = 'mission_canceled';

UPDATE notification_settings SET 
    name = 'Ακύρωση Βάρδιας',
    description = 'Όταν ακυρώνεται βάρδια'
WHERE code = 'shift_canceled';

UPDATE notification_settings SET 
    name = 'Πόντοι Βαθμολογίας',
    description = 'Όταν ο εθελοντής κερδίζει πόντους'
WHERE code = 'points_earned';

UPDATE notification_settings SET 
    name = 'Ανάθεση Εργασίας',
    description = 'Όταν ανατίθεται μια εργασία σε εθελοντή'
WHERE code = 'task_assigned';

UPDATE notification_settings SET 
    name = 'Σχόλιο σε Εργασία',
    description = 'Όταν προστίθεται σχόλιο σε εργασία'
WHERE code = 'task_comment';

UPDATE notification_settings SET 
    name = 'Υπενθύμιση Προθεσμίας',
    description = 'Όταν πλησιάζει η προθεσμία εργασίας (24h πριν)'
WHERE code = 'task_deadline_reminder';

UPDATE notification_settings SET 
    name = 'Αλλαγή Κατάστασης Εργασίας',
    description = 'Όταν αλλάζει η κατάσταση μιας εργασίας'
WHERE code = 'task_status_changed';

UPDATE notification_settings SET 
    name = 'Ολοκλήρωση Υποεργασίας',
    description = 'Όταν ολοκληρώνεται μια υποεργασία'
WHERE code = 'task_subtask_completed';

UPDATE notification_settings SET 
    name = 'Αποστολή Χρειάζεται Εθελοντές',
    description = 'Όταν μια αποστολή πλησιάζει και δεν έχει αρκετούς εθελοντές'
WHERE code = 'mission_needs_volunteers';
