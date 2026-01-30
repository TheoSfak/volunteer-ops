-- Add missing tables for email functionality

CREATE TABLE IF NOT EXISTS `email_templates` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(50) NOT NULL UNIQUE,
    `name` VARCHAR(100) NOT NULL,
    `subject` VARCHAR(255) NOT NULL,
    `body_html` TEXT NOT NULL,
    `description` TEXT NULL,
    `available_variables` TEXT NULL,
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `notification_settings` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(50) NOT NULL UNIQUE,
    `name` VARCHAR(100) NOT NULL,
    `description` TEXT NULL,
    `email_enabled` TINYINT(1) DEFAULT 1,
    `email_template_id` INT UNSIGNED NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`email_template_id`) REFERENCES `email_templates`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert default email templates
INSERT INTO `email_templates` (`code`, `name`, `subject`, `body_html`, `description`, `available_variables`) VALUES
('welcome', 'Καλωσόρισμα', 'Καλώς ήρθατε στο {{app_name}}!', '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;"><div style="background: #3498db; color: white; padding: 20px; text-align: center;"><h1>{{app_name}}</h1></div><div style="padding: 30px; background: #fff;"><h2>Καλώς ήρθατε, {{user_name}}!</h2><p>Ευχαριστούμε για την εγγραφή σας.</p></div></div>', 'Αποστέλλεται σε νέους χρήστες', '{{app_name}}, {{user_name}}, {{user_email}}, {{login_url}}'),
('participation_approved', 'Έγκριση Συμμετοχής', 'Η συμμετοχή σας εγκρίθηκε - {{mission_title}}', '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;"><div style="background: #27ae60; color: white; padding: 20px; text-align: center;"><h1>Εγκρίθηκε!</h1></div><div style="padding: 30px; background: #fff;"><h2>Γεια σας {{user_name}},</h2><p>Η συμμετοχή σας στη βάρδια εγκρίθηκε!</p><p><strong>Αποστολή:</strong> {{mission_title}}</p><p><strong>Βάρδια:</strong> {{shift_date}} ({{shift_time}})</p></div></div>', 'Όταν εγκρίνεται η συμμετοχή', '{{app_name}}, {{user_name}}, {{mission_title}}, {{shift_date}}, {{shift_time}}, {{location}}'),
('participation_rejected', 'Απόρριψη Συμμετοχής', 'Η συμμετοχή σας δεν εγκρίθηκε - {{mission_title}}', '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;"><div style="background: #e74c3c; color: white; padding: 20px; text-align: center;"><h1>Ενημέρωση</h1></div><div style="padding: 30px; background: #fff;"><h2>Γεια σας {{user_name}},</h2><p>Δυστυχώς η αίτηση συμμετοχής σας δεν εγκρίθηκε.</p><p><strong>Αποστολή:</strong> {{mission_title}}</p></div></div>', 'Όταν απορρίπτεται η συμμετοχή', '{{app_name}}, {{user_name}}, {{mission_title}}, {{shift_date}}'),
('shift_reminder', 'Υπενθύμιση Βάρδιας', 'Υπενθύμιση: Αύριο έχετε βάρδια', '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;"><div style="background: #f39c12; color: white; padding: 20px; text-align: center;"><h1>Υπενθύμιση</h1></div><div style="padding: 30px; background: #fff;"><h2>Γεια σας {{user_name}},</h2><p>Αύριο έχετε βάρδια.</p><p><strong>Αποστολή:</strong> {{mission_title}}</p><p><strong>Ώρα:</strong> {{shift_time}}</p></div></div>', 'Μία μέρα πριν τη βάρδια', '{{app_name}}, {{user_name}}, {{mission_title}}, {{shift_date}}, {{shift_time}}, {{location}}'),
('new_mission', 'Νέα Αποστολή', 'Νέα αποστολή: {{mission_title}}', '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;"><div style="background: #3498db; color: white; padding: 20px; text-align: center;"><h1>Νέα Αποστολή!</h1></div><div style="padding: 30px; background: #fff;"><h2>{{mission_title}}</h2><p>{{mission_description}}</p><p><strong>Τοποθεσία:</strong> {{location}}</p></div></div>', 'Όταν δημοσιεύεται νέα αποστολή', '{{app_name}}, {{mission_title}}, {{mission_description}}, {{location}}, {{start_date}}, {{end_date}}, {{mission_url}}'),
('mission_canceled', 'Ακύρωση Αποστολής', 'Ακυρώθηκε η αποστολή: {{mission_title}}', '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;"><div style="background: #e74c3c; color: white; padding: 20px; text-align: center;"><h1>Ακύρωση</h1></div><div style="padding: 30px; background: #fff;"><h2>Γεια σας {{user_name}},</h2><p>Η αποστολή {{mission_title}} ακυρώθηκε.</p></div></div>', 'Όταν ακυρώνεται αποστολή', '{{app_name}}, {{user_name}}, {{mission_title}}'),
('shift_canceled', 'Ακύρωση Βάρδιας', 'Ακυρώθηκε η βάρδια - {{mission_title}}', '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;"><div style="background: #e74c3c; color: white; padding: 20px; text-align: center;"><h1>Ακύρωση Βάρδιας</h1></div><div style="padding: 30px; background: #fff;"><h2>Γεια σας {{user_name}},</h2><p>Η βάρδια στις {{shift_date}} ακυρώθηκε.</p></div></div>', 'Όταν ακυρώνεται βάρδια', '{{app_name}}, {{user_name}}, {{mission_title}}, {{shift_date}}, {{shift_time}}'),
('points_earned', 'Κέρδος Πόντων', 'Κερδίσατε {{points}} πόντους!', '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;"><div style="background: #27ae60; color: white; padding: 20px; text-align: center;"><h1>Συγχαρητήρια!</h1></div><div style="padding: 30px; background: #fff;"><h2>Γεια σας {{user_name}},</h2><p style="font-size: 24px; color: #27ae60;"><strong>+{{points}} πόντοι</strong></p><p>Συνολικοί πόντοι: {{total_points}}</p></div></div>', 'Όταν κερδίζει πόντους ο εθελοντής', '{{app_name}}, {{user_name}}, {{points}}, {{mission_title}}, {{shift_date}}, {{total_points}}');

-- Insert default notification settings
INSERT INTO `notification_settings` (`code`, `name`, `description`, `email_enabled`, `email_template_id`) VALUES
('new_mission', 'Νέα Αποστολή', 'Όταν δημοσιεύεται νέα αποστολή', 1, (SELECT id FROM email_templates WHERE code = 'new_mission')),
('participation_approved', 'Έγκριση Συμμετοχής', 'Όταν εγκρίνεται η συμμετοχή', 1, (SELECT id FROM email_templates WHERE code = 'participation_approved')),
('participation_rejected', 'Απόρριψη Συμμετοχής', 'Όταν απορρίπτεται η συμμετοχή', 1, (SELECT id FROM email_templates WHERE code = 'participation_rejected')),
('shift_reminder', 'Υπενθύμιση Βάρδιας', 'Μία μέρα πριν τη βάρδια', 1, (SELECT id FROM email_templates WHERE code = 'shift_reminder')),
('mission_canceled', 'Ακύρωση Αποστολής', 'Όταν ακυρώνεται αποστολή', 1, (SELECT id FROM email_templates WHERE code = 'mission_canceled')),
('shift_canceled', 'Ακύρωση Βάρδιας', 'Όταν ακυρώνεται βάρδια', 1, (SELECT id FROM email_templates WHERE code = 'shift_canceled')),
('points_earned', 'Κέρδος Πόντων', 'Όταν κερδίζει πόντους', 0, (SELECT id FROM email_templates WHERE code = 'points_earned')),
('welcome', 'Καλωσόρισμα', 'Μετά την εγγραφή', 1, (SELECT id FROM email_templates WHERE code = 'welcome'));

-- Add SMTP settings if not exist
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES
('smtp_host', ''),
('smtp_port', '587'),
('smtp_username', ''),
('smtp_password', ''),
('smtp_encryption', 'tls'),
('smtp_from_email', ''),
('smtp_from_name', 'VolunteerOps');
