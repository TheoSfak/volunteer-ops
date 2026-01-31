-- ============================================================================
-- VolunteerOps v2.1 Migration Script
-- Run this directly on production database
-- ============================================================================

-- Tasks tables
CREATE TABLE IF NOT EXISTS tasks (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    description TEXT NULL,
    status ENUM('TODO', 'IN_PROGRESS', 'COMPLETED', 'CANCELED') DEFAULT 'TODO',
    priority ENUM('LOW', 'MEDIUM', 'HIGH', 'URGENT') DEFAULT 'MEDIUM',
    progress INT UNSIGNED DEFAULT 0 COMMENT 'Progress percentage 0-100',
    start_date DATE NULL,
    due_date DATE NULL,
    completed_at DATETIME NULL,
    mission_id INT UNSIGNED NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (mission_id) REFERENCES missions(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_status (status),
    INDEX idx_priority (priority),
    INDEX idx_due_date (due_date),
    INDEX idx_mission (mission_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS subtasks (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    task_id INT UNSIGNED NOT NULL,
    title VARCHAR(200) NOT NULL,
    status ENUM('TODO', 'IN_PROGRESS', 'COMPLETED') DEFAULT 'TODO',
    completed_at DATETIME NULL,
    sort_order INT UNSIGNED DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    INDEX idx_task (task_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS task_assignments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    task_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    assigned_by INT UNSIGNED NOT NULL,
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_assignment (task_id, user_id),
    INDEX idx_task (task_id),
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS task_comments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    task_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    comment TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_task (task_id),
    INDEX idx_user (user_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Email Templates (13 total: 8 basic + 5 tasks)
INSERT IGNORE INTO email_templates (code, name, subject, body_html, description, available_variables, is_active, created_at, updated_at) VALUES
('welcome', 'Καλωσόρισμα', 'Καλώς ήρθατε στο {{app_name}}!', '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;"><div style="background: #3498db; color: white; padding: 20px; text-align: center;"><h1>{{app_name}}</h1></div><div style="padding: 30px; background: #fff;"><h2>Καλώς ήρθατε, {{user_name}}!</h2><p>Ευχαριστούμε για την εγγραφή σας στην πλατφόρμα εθελοντισμού.</p></div></div>', 'Αποστέλλεται σε νέους χρήστες μετά την εγγραφή', '{{app_name}}, {{user_name}}, {{user_email}}, {{login_url}}', 1, NOW(), NOW()),

('participation_approved', 'Έγκριση Συμμετοχής', 'Η συμμετοχή σας εγκρίθηκε - {{mission_title}}', '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;"><div style="background: #27ae60; color: white; padding: 20px; text-align: center;"><h1>✓ Εγκρίθηκε!</h1></div><div style="padding: 30px; background: #fff;"><h2>Γεια σας {{user_name}},</h2><p>Η συμμετοχή σας στη βάρδια εγκρίθηκε!</p><p><strong>Αποστολή:</strong> {{mission_title}}</p></div></div>', 'Αποστέλλεται όταν εγκρίνεται η συμμετοχή εθελοντή σε βάρδια', '{{app_name}}, {{user_name}}, {{mission_title}}, {{shift_date}}, {{shift_time}}, {{location}}', 1, NOW(), NOW()),

('participation_rejected', 'Απόρριψη Συμμετοχής', 'Η συμμετοχή σας δεν εγκρίθηκε - {{mission_title}}', '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;"><div style="background: #e74c3c; color: white; padding: 20px; text-align: center;"><h1>Ενημέρωση Συμμετοχής</h1></div><div style="padding: 30px; background: #fff;"><h2>Γεια σας {{user_name}},</h2><p>Δυστυχώς η αίτηση συμμετοχής σας δεν μπόρεσε να εγκριθεί.</p></div></div>', 'Αποστέλλεται όταν απορρίπτεται η συμμετοχή εθελοντή', '{{app_name}}, {{user_name}}, {{mission_title}}, {{shift_date}}', 1, NOW(), NOW()),

('shift_reminder', 'Υπενθύμιση Βάρδιας', 'Υπενθύμιση: Αύριο έχετε βάρδια - {{mission_title}}', '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;"><div style="background: #f39c12; color: white; padding: 20px; text-align: center;"><h1>⏰ Υπενθύμιση</h1></div><div style="padding: 30px; background: #fff;"><h2>Γεια σας {{user_name}},</h2><p>Σας υπενθυμίζουμε ότι αύριο έχετε βάρδια.</p></div></div>', 'Αποστέλλεται την προηγούμενη μέρα της βάρδιας', '{{app_name}}, {{user_name}}, {{mission_title}}, {{shift_date}}, {{shift_time}}, {{location}}', 1, NOW(), NOW()),

('new_mission', 'Νέα Αποστολή', 'Νέα αποστολή: {{mission_title}}', '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;"><div style="background: #3498db; color: white; padding: 20px; text-align: center;"><h1>🚀 Νέα Αποστολή!</h1></div><div style="padding: 30px; background: #fff;"><h2>{{mission_title}}</h2><p>{{mission_description}}</p></div></div>', 'Αποστέλλεται σε εθελοντές όταν δημοσιεύεται νέα αποστολή', '{{app_name}}, {{mission_title}}, {{mission_description}}, {{location}}, {{start_date}}, {{end_date}}, {{mission_url}}', 1, NOW(), NOW()),

('mission_canceled', 'Ακύρωση Αποστολής', 'Ακυρώθηκε η αποστολή: {{mission_title}}', '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;"><div style="background: #e74c3c; color: white; padding: 20px; text-align: center;"><h1>Ακύρωση Αποστολής</h1></div><div style="padding: 30px; background: #fff;"><h2>Γεια σας {{user_name}},</h2><p>Η αποστολή {{mission_title}} ακυρώθηκε.</p></div></div>', 'Αποστέλλεται σε εθελοντές όταν ακυρώνεται αποστολή', '{{app_name}}, {{user_name}}, {{mission_title}}', 1, NOW(), NOW()),

('shift_canceled', 'Ακύρωση Βάρδιας', 'Ακυρώθηκε η βάρδια: {{shift_date}} - {{mission_title}}', '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;"><div style="background: #e74c3c; color: white; padding: 20px; text-align: center;"><h1>Ακύρωση Βάρδιας</h1></div><div style="padding: 30px; background: #fff;"><h2>Γεια σας {{user_name}},</h2><p>Η βάρδια στις {{shift_date}} ακυρώθηκε.</p></div></div>', 'Αποστέλλεται σε εθελοντές όταν ακυρώνεται βάρδια', '{{app_name}}, {{user_name}}, {{mission_title}}, {{shift_date}}, {{shift_time}}', 1, NOW(), NOW()),

('points_earned', 'Κέρδος Πόντων', 'Κερδίσατε {{points}} πόντους!', '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;"><div style="background: #27ae60; color: white; padding: 20px; text-align: center;"><h1>🎉 Συγχαρητήρια!</h1></div><div style="padding: 30px; background: #fff;"><h2>Γεια σας {{user_name}},</h2><p style="font-size: 24px; color: #27ae60;"><strong>+{{points}} πόντοι</strong></p></div></div>', 'Αποστέλλεται όταν ο εθελοντής κερδίζει πόντους', '{{app_name}}, {{user_name}}, {{points}}, {{mission_title}}, {{shift_date}}, {{total_points}}', 0, NOW(), NOW()),

('task_assigned', 'Ανάθεση Εργασίας', 'Νέα εργασία: {{task_title}}', '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;"><div style="background: #3498db; color: white; padding: 20px; text-align: center;"><h1>📋 Νέα Εργασία</h1></div><div style="padding: 30px; background: #fff;"><h2>Γεια σας {{user_name}},</h2><p>Σας ανατέθηκε η εργασία: {{task_title}}</p></div></div>', 'Αποστέλλεται όταν ανατίθεται εργασία σε χρήστη', '{{app_name}}, {{user_name}}, {{task_title}}, {{priority}}, {{due_date}}, {{task_url}}', 1, NOW(), NOW()),

('task_comment', 'Σχόλιο σε Εργασία', 'Νέο σχόλιο στην εργασία: {{task_title}}', '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;"><div style="background: #9b59b6; color: white; padding: 20px; text-align: center;"><h1>💬 Νέο Σχόλιο</h1></div><div style="padding: 30px; background: #fff;"><h2>Γεια σας {{user_name}},</h2><p>Νέο σχόλιο: {{comment_text}}</p></div></div>', 'Αποστέλλεται όταν προστίθεται σχόλιο σε εργασία', '{{app_name}}, {{user_name}}, {{task_title}}, {{commenter_name}}, {{comment_text}}, {{task_url}}', 1, NOW(), NOW()),

('task_status_changed', 'Αλλαγή Κατάστασης Εργασίας', 'Η εργασία {{task_title}} άλλαξε κατάσταση', '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;"><div style="background: #f39c12; color: white; padding: 20px; text-align: center;"><h1>🔄 Αλλαγή Κατάστασης</h1></div><div style="padding: 30px; background: #fff;"><h2>Γεια σας {{user_name}},</h2><p>Νέα κατάσταση: {{new_status}}</p></div></div>', 'Αποστέλλεται όταν αλλάζει η κατάσταση εργασίας', '{{app_name}}, {{user_name}}, {{task_title}}, {{new_status}}, {{changed_by}}, {{task_url}}', 1, NOW(), NOW()),

('task_due_soon', 'Υπενθύμιση Προθεσμίας Εργασίας', 'Υπενθύμιση: Η εργασία {{task_title}} λήγει σύντομα', '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;"><div style="background: #e67e22; color: white; padding: 20px; text-align: center;"><h1>⏰ Υπενθύμιση Προθεσμίας</h1></div><div style="padding: 30px; background: #fff;"><h2>Γεια σας {{user_name}},</h2><p>Η εργασία λήγει: {{due_date}}</p></div></div>', 'Αποστέλλεται πριν τη λήξη της προθεσμίας εργασίας', '{{app_name}}, {{user_name}}, {{task_title}}, {{due_date}}, {{progress}}, {{task_url}}', 1, NOW(), NOW()),

('task_overdue', 'Εκπρόθεσμη Εργασία', 'Εκπρόθεσμη εργασία: {{task_title}}', '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;"><div style="background: #e74c3c; color: white; padding: 20px; text-align: center;"><h1>❗ Εκπρόθεσμη Εργασία</h1></div><div style="padding: 30px; background: #fff;"><h2>Γεια σας {{user_name}},</h2><p>Η εργασία {{task_title}} είναι εκπρόθεσμη.</p></div></div>', 'Αποστέλλεται όταν μια εργασία είναι εκπρόθεσμη', '{{app_name}}, {{user_name}}, {{task_title}}, {{due_date}}, {{progress}}, {{task_url}}', 1, NOW(), NOW());

-- Link notification_settings to email_templates
INSERT IGNORE INTO notification_settings (code, name, description, email_enabled, email_template_id, created_at, updated_at)
SELECT 'welcome', 'Καλωσόρισμα', 'Μετά την εγγραφή νέου χρήστη', 1, id, NOW(), NOW() FROM email_templates WHERE code = 'welcome'
UNION ALL
SELECT 'new_mission', 'Νέα Αποστολή', 'Όταν δημοσιεύεται νέα αποστολή', 1, id, NOW(), NOW() FROM email_templates WHERE code = 'new_mission'
UNION ALL
SELECT 'participation_approved', 'Έγκριση Συμμετοχής', 'Όταν εγκρίνεται η συμμετοχή εθελοντή σε βάρδια', 1, id, NOW(), NOW() FROM email_templates WHERE code = 'participation_approved'
UNION ALL
SELECT 'participation_rejected', 'Απόρριψη Συμμετοχής', 'Όταν απορρίπτεται η συμμετοχή εθελοντή', 1, id, NOW(), NOW() FROM email_templates WHERE code = 'participation_rejected'
UNION ALL
SELECT 'shift_reminder', 'Υπενθύμιση Βάρδιας', 'Μία μέρα πριν τη βάρδια', 1, id, NOW(), NOW() FROM email_templates WHERE code = 'shift_reminder'
UNION ALL
SELECT 'mission_canceled', 'Ακύρωση Αποστολής', 'Όταν ακυρώνεται αποστολή', 1, id, NOW(), NOW() FROM email_templates WHERE code = 'mission_canceled'
UNION ALL
SELECT 'shift_canceled', 'Ακύρωση Βάρδιας', 'Όταν ακυρώνεται βάρδια', 1, id, NOW(), NOW() FROM email_templates WHERE code = 'shift_canceled'
UNION ALL
SELECT 'points_earned', 'Κέρδος Πόντων', 'Όταν ο εθελοντής κερδίζει πόντους', 0, id, NOW(), NOW() FROM email_templates WHERE code = 'points_earned'
UNION ALL
SELECT 'task_assigned', 'Ανάθεση Εργασίας', 'Όταν ανατίθεται εργασία σε χρήστη', 1, id, NOW(), NOW() FROM email_templates WHERE code = 'task_assigned'
UNION ALL
SELECT 'task_comment', 'Σχόλιο σε Εργασία', 'Όταν προστίθεται σχόλιο σε εργασία', 1, id, NOW(), NOW() FROM email_templates WHERE code = 'task_comment'
UNION ALL
SELECT 'task_status_changed', 'Αλλαγή Κατάστασης Εργασίας', 'Όταν αλλάζει η κατάσταση εργασίας', 1, id, NOW(), NOW() FROM email_templates WHERE code = 'task_status_changed'
UNION ALL
SELECT 'task_due_soon', 'Υπενθύμιση Προθεσμίας Εργασίας', 'Πριν τη λήξη της προθεσμίας εργασίας', 1, id, NOW(), NOW() FROM email_templates WHERE code = 'task_due_soon'
UNION ALL
SELECT 'task_overdue', 'Εκπρόθεσμη Εργασία', 'Όταν μια εργασία είναι εκπρόθεσμη', 1, id, NOW(), NOW() FROM email_templates WHERE code = 'task_overdue';

-- Done
SELECT 'Migration v2.1 completed successfully!' as status;
