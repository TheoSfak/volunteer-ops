-- VolunteerOps Database Schema v2.0
-- Plain PHP Version
-- UTF-8 encoded with Greek characters support

SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- =============================================
-- DEPARTMENTS TABLE
-- =============================================
CREATE TABLE IF NOT EXISTS `departments` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `description` TEXT NULL,
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- USERS TABLE
-- =============================================
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `email` VARCHAR(255) NOT NULL UNIQUE,
    `password` VARCHAR(255) NOT NULL,
    `phone` VARCHAR(20) NULL,
    `role` ENUM('SYSTEM_ADMIN', 'DEPARTMENT_ADMIN', 'SHIFT_LEADER', 'VOLUNTEER') DEFAULT 'VOLUNTEER',
    `volunteer_type` ENUM('VOLUNTEER','TRAINEE_RESCUER','RESCUER') NOT NULL DEFAULT 'VOLUNTEER',
    `cohort_year` YEAR NULL COMMENT 'Χρονιά σειράς δοκίμων διασωστών',
    `department_id` INT UNSIGNED NULL,
    `is_active` TINYINT(1) DEFAULT 1,
    `total_points` INT DEFAULT 0,
    `monthly_points` INT DEFAULT 0,
    `email_verified_at` TIMESTAMP NULL,
    `remember_token` VARCHAR(100) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at` TIMESTAMP NULL DEFAULT NULL,
    `deleted_by` INT UNSIGNED NULL DEFAULT NULL,
    FOREIGN KEY (`department_id`) REFERENCES `departments`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- VOLUNTEER PROFILES TABLE
-- =============================================
CREATE TABLE IF NOT EXISTS `volunteer_profiles` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL UNIQUE,
    `bio` TEXT NULL,
    `address` VARCHAR(255) NULL,
    `city` VARCHAR(100) NULL,
    `postal_code` VARCHAR(10) NULL,
    `emergency_contact_name` VARCHAR(100) NULL,
    `emergency_contact_phone` VARCHAR(20) NULL,
    `blood_type` VARCHAR(5) NULL,
    `medical_notes` TEXT NULL,
    `available_weekdays` TINYINT(1) DEFAULT 1,
    `available_weekends` TINYINT(1) DEFAULT 1,
    `available_nights` TINYINT(1) DEFAULT 0,
    `has_driving_license` TINYINT(1) DEFAULT 0,
    `has_first_aid` TINYINT(1) DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- SKILLS TABLE
-- =============================================
CREATE TABLE IF NOT EXISTS `skills` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `category` VARCHAR(50) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- USER SKILLS (PIVOT)
-- =============================================
CREATE TABLE IF NOT EXISTS `user_skills` (
    `user_id` INT UNSIGNED NOT NULL,
    `skill_id` INT UNSIGNED NOT NULL,
    `level` ENUM('BEGINNER', 'INTERMEDIATE', 'ADVANCED', 'EXPERT') DEFAULT 'BEGINNER',
    PRIMARY KEY (`user_id`, `skill_id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`skill_id`) REFERENCES `skills`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- MISSION TYPES TABLE
-- =============================================
CREATE TABLE IF NOT EXISTS `mission_types` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `color` VARCHAR(20) NOT NULL DEFAULT 'primary',
    `icon` VARCHAR(50) NOT NULL DEFAULT 'bi-flag',
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `sort_order` INT NOT NULL DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `mission_types` (`id`, `name`, `description`, `color`, `icon`, `sort_order`) VALUES
(1, 'Εθελοντική', 'Γενική εθελοντική αποστολή', 'primary', 'bi-people', 1),
(2, 'Υγειονομική', 'Υγειονομική κάλυψη και πρώτες βοήθειες', 'danger', 'bi-heart-pulse', 2),
(3, 'Εκπαιδευτική', 'Εκπαιδευτική αποστολή και ασκήσεις', 'info', 'bi-mortarboard', 3),
(4, 'Διασωστική', 'Επιχείρηση διάσωσης και αντιμετώπιση κινδύνων', 'warning', 'bi-shield-exclamation', 4);

-- =============================================
-- MISSIONS TABLE
-- =============================================
CREATE TABLE IF NOT EXISTS `missions` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `department_id` INT UNSIGNED NULL,
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT NULL,
    `type` ENUM('VOLUNTEER', 'MEDICAL') DEFAULT 'VOLUNTEER',
    `mission_type_id` INT UNSIGNED DEFAULT 1,
    `location` VARCHAR(255) NOT NULL,
    `location_details` VARCHAR(255) NULL,
    `latitude` DECIMAL(10, 8) NULL,
    `longitude` DECIMAL(11, 8) NULL,
    `start_datetime` DATETIME NOT NULL,
    `end_datetime` DATETIME NOT NULL,
    `requirements` TEXT NULL,
    `notes` TEXT NULL,
    `is_urgent` TINYINT(1) DEFAULT 0,
    `coverage_percentage` INT DEFAULT 0,
    `status` ENUM('DRAFT', 'OPEN', 'CLOSED', 'COMPLETED', 'CANCELED') DEFAULT 'DRAFT',
    `created_by` INT UNSIGNED NULL,
    `cancellation_reason` TEXT NULL,
    `canceled_by` INT UNSIGNED NULL,
    `canceled_at` TIMESTAMP NULL,
    `deleted_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`department_id`) REFERENCES `departments`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`mission_type_id`) REFERENCES `mission_types`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`canceled_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_missions_status` (`status`),
    INDEX `idx_missions_start` (`start_datetime`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- MISSION DEBRIEFS TABLE
-- =============================================
CREATE TABLE IF NOT EXISTS `mission_debriefs` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `mission_id` INT UNSIGNED NOT NULL,
    `submitted_by` INT UNSIGNED NOT NULL,
    `summary` TEXT NOT NULL,
    `objectives_met` ENUM('YES', 'PARTIAL', 'NO') NOT NULL,
    `incidents` TEXT NULL,
    `equipment_issues` TEXT NULL,
    `rating` TINYINT UNSIGNED NOT NULL CHECK (`rating` BETWEEN 1 AND 5),
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`mission_id`) REFERENCES `missions`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`submitted_by`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `unique_mission_debrief` (`mission_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- SHIFTS TABLE
-- =============================================
CREATE TABLE IF NOT EXISTS `shifts` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `mission_id` INT UNSIGNED NOT NULL,
    `start_time` DATETIME NOT NULL,
    `end_time` DATETIME NOT NULL,
    `max_volunteers` INT DEFAULT 5,
    `min_volunteers` INT DEFAULT 1,
    `notes` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`mission_id`) REFERENCES `missions`(`id`) ON DELETE CASCADE,
    INDEX `idx_shifts_mission` (`mission_id`),
    INDEX `idx_shifts_time` (`start_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- PARTICIPATION REQUESTS TABLE
-- =============================================
CREATE TABLE IF NOT EXISTS `participation_requests` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `shift_id` INT UNSIGNED NOT NULL,
    `volunteer_id` INT UNSIGNED NOT NULL,
    `status` ENUM('PENDING', 'APPROVED', 'REJECTED', 'CANCELED_BY_USER', 'CANCELED_BY_ADMIN') DEFAULT 'PENDING',
    `notes` TEXT NULL,
    `rejection_reason` TEXT NULL,
    `decided_by` INT UNSIGNED NULL,
    `decided_at` TIMESTAMP NULL,
    `points_awarded` TINYINT(1) DEFAULT 0,
    `attended` TINYINT(1) DEFAULT 0,
    `actual_hours` DECIMAL(5, 2) NULL,
    `actual_start_time` TIME NULL,
    `actual_end_time` TIME NULL,
    `admin_notes` TEXT NULL,
    `attendance_confirmed_at` TIMESTAMP NULL,
    `attendance_confirmed_by` INT UNSIGNED NULL,
    `field_status` ENUM('on_way','on_site','needs_help') NULL DEFAULT NULL,
    `field_status_updated_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`shift_id`) REFERENCES `shifts`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`volunteer_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`decided_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`attendance_confirmed_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    UNIQUE KEY `unique_participation` (`shift_id`, `volunteer_id`),
    INDEX `idx_participation_status` (`status`),
    INDEX `idx_participation_volunteer` (`volunteer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- ACHIEVEMENTS TABLE
-- =============================================
CREATE TABLE IF NOT EXISTS `achievements` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `code` VARCHAR(50) NOT NULL UNIQUE,
    `name` VARCHAR(100) NOT NULL,
    `description` TEXT NULL,
    `category` VARCHAR(50) DEFAULT 'milestone',
    `icon` VARCHAR(50) DEFAULT '🏆',
    `required_points` INT DEFAULT 0,
    `threshold` INT DEFAULT 0,
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- USER ACHIEVEMENTS (PIVOT)
-- =============================================
CREATE TABLE IF NOT EXISTS `user_achievements` (
    `user_id` INT UNSIGNED NOT NULL,
    `achievement_id` INT UNSIGNED NOT NULL,
    `earned_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`user_id`, `achievement_id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`achievement_id`) REFERENCES `achievements`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- VOLUNTEER POINTS TABLE
-- =============================================
CREATE TABLE IF NOT EXISTS `volunteer_points` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `points` INT NOT NULL,
    `reason` VARCHAR(50) NOT NULL,
    `description` TEXT NULL,
    `pointable_type` VARCHAR(100) NULL,
    `pointable_id` INT UNSIGNED NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_points_user` (`user_id`),
    INDEX `idx_points_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- DOCUMENTS TABLE
-- =============================================
CREATE TABLE IF NOT EXISTS `documents` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `documentable_type` VARCHAR(100) NOT NULL,
    `documentable_id` INT UNSIGNED NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `filename` VARCHAR(255) NOT NULL,
    `filepath` VARCHAR(500) NOT NULL,
    `mime_type` VARCHAR(100) NULL,
    `file_size` INT UNSIGNED NULL,
    `uploaded_by` INT UNSIGNED NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`uploaded_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_documents_type` (`documentable_type`, `documentable_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- NOTIFICATIONS TABLE
-- =============================================
CREATE TABLE IF NOT EXISTS `notifications` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `type` VARCHAR(50) NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `message` TEXT NULL,
    `data` JSON NULL,
    `read_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_notifications_user` (`user_id`, `read_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- AUDIT LOGS TABLE
-- =============================================
CREATE TABLE IF NOT EXISTS `audit_logs` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NULL,
    `action` VARCHAR(50) NOT NULL,
    `table_name` VARCHAR(100) NULL,
    `record_id` INT UNSIGNED NULL,
    `notes` TEXT NULL,
    `ip_address` VARCHAR(45) NULL,
    `user_agent` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_audit_user` (`user_id`),
    INDEX `idx_audit_table` (`table_name`, `record_id`),
    INDEX `idx_audit_action` (`action`),
    INDEX `idx_audit_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- SETTINGS TABLE
-- =============================================
CREATE TABLE IF NOT EXISTS `settings` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `setting_key` VARCHAR(100) NOT NULL UNIQUE,
    `setting_value` TEXT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- EMAIL TEMPLATES TABLE
-- =============================================
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

-- =============================================
-- NOTIFICATION SETTINGS TABLE
-- =============================================
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

-- =============================================
-- USER NOTIFICATION PREFERENCES TABLE
-- =============================================
CREATE TABLE IF NOT EXISTS `user_notification_preferences` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `notification_code` VARCHAR(50) NOT NULL,
    `email_enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `in_app_enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_user_notif` (`user_id`, `notification_code`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- CERTIFICATE TYPES TABLE
-- =============================================
CREATE TABLE IF NOT EXISTS `certificate_types` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(150) NOT NULL,
    `description` TEXT NULL,
    `default_validity_months` INT UNSIGNED NULL COMMENT 'NULL = no expiry',
    `is_required` TINYINT(1) NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- VOLUNTEER CERTIFICATES TABLE
-- =============================================
CREATE TABLE IF NOT EXISTS `volunteer_certificates` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `certificate_type_id` INT UNSIGNED NOT NULL,
    `issue_date` DATE NOT NULL,
    `expiry_date` DATE NULL COMMENT 'NULL = never expires',
    `issuing_body` VARCHAR(255) NULL,
    `certificate_number` VARCHAR(100) NULL,
    `document_id` INT UNSIGNED NULL,
    `notes` TEXT NULL,
    `reminder_sent_30` TINYINT(1) NOT NULL DEFAULT 0,
    `reminder_sent_7` TINYINT(1) NOT NULL DEFAULT 0,
    `created_by` INT UNSIGNED NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_user_cert` (`user_id`, `certificate_type_id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`certificate_type_id`) REFERENCES `certificate_types`(`id`) ON DELETE RESTRICT,
    FOREIGN KEY (`document_id`) REFERENCES `volunteer_documents`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- =============================================
-- DEFAULT DATA
-- =============================================

-- Default departments
INSERT INTO `departments` (`name`, `description`) VALUES
('Γενικός Εθελοντισμός', 'Γενικές εθελοντικές δράσεις'),
('Υγειονομική Κάλυψη', 'Υγειονομικές αποστολές και παροχή πρώτων βοηθειών'),
('Περιβαλλοντικές Δράσεις', 'Δράσεις για το περιβάλλον'),
('Κοινωνική Αλληλεγγύη', 'Δράσεις κοινωνικής αλληλεγγύης');

-- Default skills
INSERT INTO `skills` (`name`, `category`) VALUES
('Πρώτες Βοήθειες', 'Υγεία'),
('Νοσηλευτική', 'Υγεία'),
('Οδήγηση', 'Μεταφορές'),
('Οδήγηση Μοτοσυκλέτας', 'Μεταφορές'),
('Οργάνωση Εκδηλώσεων', 'Διοίκηση'),
('Διαχείριση Κρίσεων', 'Διοίκηση'),
('Επικοινωνία', 'Γενικά'),
('Ξένες Γλώσσες', 'Γενικά'),
('Φωτογραφία', 'Τεχνικά'),
('Πληροφορική', 'Τεχνικά');

-- Default achievements
INSERT INTO `achievements` (`code`, `name`, `description`, `category`, `icon`, `required_points`, `threshold`) VALUES
('first_shift', 'Πρώτη Βάρδια', 'Ολοκλήρωσε την πρώτη σου βάρδια', 'milestone', '⭐', 0, 1),
('shifts_5', '5 Βάρδιες', 'Ολοκλήρωσε 5 βάρδιες', 'shifts', '📅', 0, 5),
('shifts_10', '10 Βάρδιες', 'Ολοκλήρωσε 10 βάρδιες', 'shifts', '📅', 0, 10),
('shifts_25', '25 Βάρδιες', 'Ολοκλήρωσε 25 βάρδιες', 'shifts', '📅', 0, 25),
('shifts_50', '50 Βάρδιες', 'Ολοκλήρωσε 50 βάρδιες', 'shifts', '🎯', 0, 50),
('hours_10', '10 Ώρες', 'Συμπλήρωσε 10 ώρες εθελοντισμού', 'hours', '⏰', 0, 10),
('hours_50', '50 Ώρες', 'Συμπλήρωσε 50 ώρες εθελοντισμού', 'hours', '⏰', 0, 50),
('hours_100', '100 Ώρες', 'Συμπλήρωσε 100 ώρες εθελοντισμού', 'hours', '⏰', 0, 100),
('hours_250', '250 Ώρες', 'Συμπλήρωσε 250 ώρες εθελοντισμού', 'hours', '🏆', 0, 250),
('weekend_warrior', 'Πολεμιστής Σαββατοκύριακου', 'Ολοκλήρωσε 10 βάρδιες Σαββατοκύριακου', 'special', '☀️', 100, 10),
('night_owl', 'Νυχτοπούλι', 'Ολοκλήρωσε 10 νυχτερινές βάρδιες', 'special', '🌙', 100, 10),
('medical_hero', 'Ήρωας Υγείας', 'Ολοκλήρωσε 10 υγειονομικές αποστολές', 'special', '❤️', 200, 10),
('points_100', '100 Πόντοι', 'Συγκέντρωσε 100 πόντους', 'points', '💯', 100, 0),
('points_500', '500 Πόντοι', 'Συγκέντρωσε 500 πόντους', 'points', '🌟', 500, 0),
('points_1000', '1000 Πόντοι', 'Συγκέντρωσε 1000 πόντους', 'points', '👑', 1000, 0);

-- Default admin user (password: admin123)
INSERT INTO `users` (`name`, `email`, `password`, `role`, `is_active`) VALUES
('Διαχειριστής', 'admin@volunteerops.gr', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'SYSTEM_ADMIN', 1);

-- Default settings
INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
('app_name', 'VolunteerOps'),
('app_description', 'Σύστημα Διαχείρισης Εθελοντών'),
('points_per_hour', '10'),
('weekend_multiplier', '1.5'),
('night_multiplier', '1.5'),
('medical_multiplier', '2.0'),
('registration_enabled', '1'),
('require_approval', '0'),
('maintenance_mode', '0'),
('timezone', 'Europe/Athens'),
('date_format', 'd/m/Y'),
('smtp_host', ''),
('smtp_port', '587'),
('smtp_username', ''),
('smtp_password', ''),
('smtp_encryption', 'tls'),
('smtp_from_email', ''),
('smtp_from_name', 'VolunteerOps');

-- Default email templates
INSERT INTO `email_templates` (`code`, `name`, `subject`, `body_html`, `description`, `available_variables`) VALUES
('welcome', 'Καλωσόρισμα', 'Καλώς ήρθατε στο {{app_name}}!', 
'<div style=\"font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;\">
    <div style=\"background: #3498db; color: white; padding: 20px; text-align: center;\">
        <h1>{{app_name}}</h1>
    </div>
    <div style=\"padding: 30px; background: #fff;\">
        <h2>Καλώς ήρθατε, {{user_name}}!</h2>
        <p>Ευχαριστούμε για την εγγραφή σας στην πλατφόρμα εθελοντισμού.</p>
        <p>Μπορείτε τώρα να:</p>
        <ul>
            <li>Δείτε τις διαθέσιμες αποστολές</li>
            <li>Δηλώσετε συμμετοχή σε βάρδιες</li>
            <li>Κερδίσετε πόντους και επιτεύγματα</li>
        </ul>
        <p style=\"text-align: center; margin-top: 30px;\">
            <a href=\"{{login_url}}\" style=\"background: #3498db; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px;\">Σύνδεση</a>
        </p>
    </div>
    <div style=\"padding: 15px; background: #f8f9fa; text-align: center; font-size: 12px; color: #666;\">
        {{app_name}} - Σύστημα Διαχείρισης Εθελοντών
    </div>
</div>', 
'Αποστέλλεται σε νέους χρήστες μετά την εγγραφή', 
'{{app_name}}, {{user_name}}, {{user_email}}, {{login_url}}'),

('participation_approved', 'Έγκριση Συμμετοχής', 'Η συμμετοχή σας εγκρίθηκε - {{mission_title}}',
'<div style=\"font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;\">
    <div style=\"background: #27ae60; color: white; padding: 20px; text-align: center;\">
        <h1>✓ Εγκρίθηκε!</h1>
    </div>
    <div style=\"padding: 30px; background: #fff;\">
        <h2>Γεια σας {{user_name}},</h2>
        <p>Η συμμετοχή σας στη βάρδια εγκρίθηκε!</p>
        <div style=\"background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0;\">
            <p><strong>Αποστολή:</strong> {{mission_title}}</p>
            <p><strong>Βάρδια:</strong> {{shift_date}} ({{shift_time}})</p>
            <p><strong>Τοποθεσία:</strong> {{location}}</p>
        </div>
        <p>Παρακαλούμε να είστε στην τοποθεσία έγκαιρα.</p>
    </div>
    <div style=\"padding: 15px; background: #f8f9fa; text-align: center; font-size: 12px; color: #666;\">
        {{app_name}}
    </div>
</div>',
'Αποστέλλεται όταν εγκρίνεται η συμμετοχή εθελοντή σε βάρδια',
'{{app_name}}, {{user_name}}, {{mission_title}}, {{shift_date}}, {{shift_time}}, {{location}}'),

('participation_rejected', 'Απόρριψη Συμμετοχής', 'Η συμμετοχή σας δεν εγκρίθηκε - {{mission_title}}',
'<div style=\"font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;\">
    <div style=\"background: #e74c3c; color: white; padding: 20px; text-align: center;\">
        <h1>Ενημέρωση Συμμετοχής</h1>
    </div>
    <div style=\"padding: 30px; background: #fff;\">
        <h2>Γεια σας {{user_name}},</h2>
        <p>Δυστυχώς η αίτηση συμμετοχής σας δεν μπόρεσε να εγκριθεί.</p>
        <div style=\"background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0;\">
            <p><strong>Αποστολή:</strong> {{mission_title}}</p>
            <p><strong>Βάρδια:</strong> {{shift_date}}</p>
        </div>
        <p>Μπορείτε να δηλώσετε συμμετοχή σε άλλες διαθέσιμες βάρδιες.</p>
    </div>
    <div style=\"padding: 15px; background: #f8f9fa; text-align: center; font-size: 12px; color: #666;\">
        {{app_name}}
    </div>
</div>',
'Αποστέλλεται όταν απορρίπτεται η συμμετοχή εθελοντή',
'{{app_name}}, {{user_name}}, {{mission_title}}, {{shift_date}}'),

('shift_reminder', 'Υπενθύμιση Βάρδιας', 'Υπενθύμιση: Αύριο έχετε βάρδια - {{mission_title}}',
'<div style=\"font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;\">
    <div style=\"background: #f39c12; color: white; padding: 20px; text-align: center;\">
        <h1>⏰ Υπενθύμιση</h1>
    </div>
    <div style=\"padding: 30px; background: #fff;\">
        <h2>Γεια σας {{user_name}},</h2>
        <p>Σας υπενθυμίζουμε ότι αύριο έχετε βάρδια.</p>
        <div style=\"background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0;\">
            <p><strong>Αποστολή:</strong> {{mission_title}}</p>
            <p><strong>Ημερομηνία:</strong> {{shift_date}}</p>
            <p><strong>Ώρα:</strong> {{shift_time}}</p>
            <p><strong>Τοποθεσία:</strong> {{location}}</p>
        </div>
        <p>Σε περίπτωση αδυναμίας, παρακαλούμε ενημερώστε μας έγκαιρα.</p>
    </div>
    <div style=\"padding: 15px; background: #f8f9fa; text-align: center; font-size: 12px; color: #666;\">
        {{app_name}}
    </div>
</div>',
'Αποστέλλεται την προηγούμενη μέρα της βάρδιας',
'{{app_name}}, {{user_name}}, {{mission_title}}, {{shift_date}}, {{shift_time}}, {{location}}'),

('new_mission', 'Νέα Αποστολή', 'Νέα αποστολή: {{mission_title}}',
'<div style=\"font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;\">
    <div style=\"background: #3498db; color: white; padding: 20px; text-align: center;\">
        <h1>🚀 Νέα Αποστολή!</h1>
    </div>
    <div style=\"padding: 30px; background: #fff;\">
        <h2>{{mission_title}}</h2>
        <p>{{mission_description}}</p>
        <div style=\"background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0;\">
            <p><strong>Τοποθεσία:</strong> {{location}}</p>
            <p><strong>Περίοδος:</strong> {{start_date}} - {{end_date}}</p>
        </div>
        <p style=\"text-align: center; margin-top: 30px;\">
            <a href=\"{{mission_url}}\" style=\"background: #3498db; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px;\">Δήλωση Συμμετοχής</a>
        </p>
    </div>
    <div style=\"padding: 15px; background: #f8f9fa; text-align: center; font-size: 12px; color: #666;\">
        {{app_name}}
    </div>
</div>',
'Αποστέλλεται σε εθελοντές όταν δημοσιεύεται νέα αποστολή',
'{{app_name}}, {{mission_title}}, {{mission_description}}, {{location}}, {{start_date}}, {{end_date}}, {{mission_url}}'),

('mission_canceled', 'Ακύρωση Αποστολής', 'Ακυρώθηκε η αποστολή: {{mission_title}}',
'<div style=\"font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;\">
    <div style=\"background: #e74c3c; color: white; padding: 20px; text-align: center;\">
        <h1>Ακύρωση Αποστολής</h1>
    </div>
    <div style=\"padding: 30px; background: #fff;\">
        <h2>Γεια σας {{user_name}},</h2>
        <p>Σας ενημερώνουμε ότι η παρακάτω αποστολή ακυρώθηκε:</p>
        <div style=\"background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0;\">
            <p><strong>Αποστολή:</strong> {{mission_title}}</p>
        </div>
        <p>Ζητούμε συγγνώμη για την όποια αναστάτωση.</p>
    </div>
    <div style=\"padding: 15px; background: #f8f9fa; text-align: center; font-size: 12px; color: #666;\">
        {{app_name}}
    </div>
</div>',
'Αποστέλλεται σε εθελοντές όταν ακυρώνεται αποστολή που είχαν δηλώσει συμμετοχή',
'{{app_name}}, {{user_name}}, {{mission_title}}'),

('shift_canceled', 'Ακύρωση Βάρδιας', 'Ακυρώθηκε η βάρδια: {{shift_date}} - {{mission_title}}',
'<div style=\"font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;\">
    <div style=\"background: #e74c3c; color: white; padding: 20px; text-align: center;\">
        <h1>Ακύρωση Βάρδιας</h1>
    </div>
    <div style=\"padding: 30px; background: #fff;\">
        <h2>Γεια σας {{user_name}},</h2>
        <p>Σας ενημερώνουμε ότι η βάρδια στην οποία είχατε δηλώσει συμμετοχή ακυρώθηκε:</p>
        <div style=\"background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0;\">
            <p><strong>Αποστολή:</strong> {{mission_title}}</p>
            <p><strong>Βάρδια:</strong> {{shift_date}} ({{shift_time}})</p>
        </div>
        <p>Ζητούμε συγγνώμη για την όποια αναστάτωση.</p>
    </div>
    <div style=\"padding: 15px; background: #f8f9fa; text-align: center; font-size: 12px; color: #666;\">
        {{app_name}}
    </div>
</div>',
'Αποστέλλεται σε εθελοντές όταν ακυρώνεται βάρδια',
'{{app_name}}, {{user_name}}, {{mission_title}}, {{shift_date}}, {{shift_time}}'),

('points_earned', 'Κέρδος Πόντων', 'Κερδίσατε {{points}} πόντους!',
'<div style=\"font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;\">
    <div style=\"background: #27ae60; color: white; padding: 20px; text-align: center;\">
        <h1>🎉 Συγχαρητήρια!</h1>
    </div>
    <div style=\"padding: 30px; background: #fff;\">
        <h2>Γεια σας {{user_name}},</h2>
        <p style=\"font-size: 24px; text-align: center; color: #27ae60;\">
            <strong>+{{points}} πόντοι</strong>
        </p>
        <div style=\"background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0;\">
            <p><strong>Βάρδια:</strong> {{shift_date}}</p>
            <p><strong>Αποστολή:</strong> {{mission_title}}</p>
        </div>
        <p>Συνολικοί πόντοι: <strong>{{total_points}}</strong></p>
    </div>
    <div style=\"padding: 15px; background: #f8f9fa; text-align: center; font-size: 12px; color: #666;\">
        {{app_name}}
    </div>
</div>',
'Αποστέλλεται όταν ο εθελοντής κερδίζει πόντους',
'{{app_name}}, {{user_name}}, {{points}}, {{mission_title}}, {{shift_date}}, {{total_points}}'),

('admin_added_volunteer', 'Προσθήκη από Διαχειριστή', 'Ο διαχειριστής σας τοποθέτησε απευθείας σε βάρδια',
'<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
    <div style="background: #2c3e50; color: white; padding: 20px; text-align: center;">
        <h1>📋 Τοποθέτηση σε Βάρδια</h1>
    </div>
    <div style="padding: 30px; background: #fff;">
        <h2>Γεια σας {{user_name}},</h2>
        <p>Ο διαχειριστής σας τοποθέτησε απευθείας στην παρακάτω βάρδια:</p>
        <div style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #2c3e50;">
            <p><strong>Αποστολή:</strong> {{mission_title}}</p>
            <p><strong>Ημερομηνία:</strong> {{shift_date}}</p>
            <p><strong>Ώρα:</strong> {{shift_time}}</p>
            <p><strong>Τοποθεσία:</strong> {{location}}</p>
        </div>
        {{#admin_notes}}<div style="background: #fff3cd; padding: 15px; border-radius: 8px; margin: 15px 0;">
            <p><strong>Σημείωση διαχειριστή:</strong> {{admin_notes}}</p>
        </div>{{/admin_notes}}
        <p>Παρακαλούμε να είστε στην τοποθεσία έγκαιρα.</p>
        <p style="text-align: center; margin-top: 30px;">
            <a href="{{login_url}}" style="background: #2c3e50; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px;">Σύνδεση στην Πλατφόρμα</a>
        </p>
    </div>
    <div style="padding: 15px; background: #f8f9fa; text-align: center; font-size: 12px; color: #666;">
        {{app_name}} - Σύστημα Διαχείρισης Εθελοντών
    </div>
</div>',
'Αποστέλλεται στον εθελοντή όταν ο διαχειριστής τον προσθέτει απευθείας σε βάρδια (shift-view ή mission-view)',
'{{app_name}}, {{user_name}}, {{mission_title}}, {{shift_date}}, {{shift_time}}, {{location}}, {{admin_notes}}, {{login_url}}'),

('certificate_expiry_reminder', 'Υπενθύμιση Λήξης Πιστοποιητικού', 'Υπενθύμιση: Το πιστοποιητικό σας «{{certificate_type}}» λήγει σε {{days_remaining}} ημέρες',
'<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
    <div style="background: #f39c12; color: white; padding: 20px; text-align: center;">
        <h1>⚠ Λήξη Πιστοποιητικού</h1>
    </div>
    <div style="padding: 30px; background: #fff;">
        <h2>Αγαπητέ/ή {{user_name}},</h2>
        <p>Σας ενημερώνουμε ότι το πιστοποιητικό σας <strong>«{{certificate_type}}»</strong> λήγει στις <strong>{{expiry_date}}</strong> (σε {{days_remaining}} ημέρες).</p>
        <p>Παρακαλούμε φροντίστε για την ανανέωσή του εγκαίρως.</p>
    </div>
    <div style="padding: 15px; background: #f8f9fa; text-align: center; font-size: 12px; color: #666;">
        {{app_name}} - Σύστημα Διαχείρισης Εθελοντών
    </div>
</div>',
'Αποστέλλεται όταν πλησιάζει η λήξη ενός πιστοποιητικού εθελοντή',
'{{app_name}}, {{user_name}}, {{certificate_type}}, {{expiry_date}}, {{days_remaining}}');

-- Default notification settings
INSERT INTO `notification_settings` (`code`, `name`, `description`, `email_enabled`, `email_template_id`) VALUES
('new_mission', 'Νέα Αποστολή', 'Όταν δημοσιεύεται νέα αποστολή', 1, (SELECT id FROM email_templates WHERE code = 'new_mission')),
('participation_approved', 'Έγκριση Συμμετοχής', 'Όταν εγκρίνεται η συμμετοχή εθελοντή σε βάρδια', 1, (SELECT id FROM email_templates WHERE code = 'participation_approved')),
('participation_rejected', 'Απόρριψη Συμμετοχής', 'Όταν απορρίπτεται η συμμετοχή εθελοντή', 1, (SELECT id FROM email_templates WHERE code = 'participation_rejected')),
('shift_reminder', 'Υπενθύμιση Βάρδιας', 'Μία μέρα πριν τη βάρδια', 1, (SELECT id FROM email_templates WHERE code = 'shift_reminder')),
('mission_canceled', 'Ακύρωση Αποστολής', 'Όταν ακυρώνεται αποστολή', 1, (SELECT id FROM email_templates WHERE code = 'mission_canceled')),
('shift_canceled', 'Ακύρωση Βάρδιας', 'Όταν ακυρώνεται βάρδια', 1, (SELECT id FROM email_templates WHERE code = 'shift_canceled')),
('points_earned', 'Κέρδος Πόντων', 'Όταν ο εθελοντής κερδίζει πόντους', 0, (SELECT id FROM email_templates WHERE code = 'points_earned')),
('welcome', 'Καλωσόρισμα', 'Μετά την εγγραφή νέου χρήστη', 1, (SELECT id FROM email_templates WHERE code = 'welcome')),
('admin_added_volunteer', 'Προσθήκη από Διαχειριστή', 'Όταν ο διαχειριστής προσθέτει εθελοντή απευθείας σε βάρδια', 1, (SELECT id FROM email_templates WHERE code = 'admin_added_volunteer')),
('certificate_expiry_reminder', 'Υπενθύμιση Λήξης Πιστοποιητικού', 'Όταν πλησιάζει η λήξη ενός πιστοποιητικού του εθελοντή', 1, (SELECT id FROM email_templates WHERE code = 'certificate_expiry_reminder'));

-- Default certificate types
INSERT INTO `certificate_types` (`name`, `description`, `default_validity_months`, `is_required`) VALUES
('Πρώτες Βοήθειες', 'Πιστοποίηση Πρώτων Βοηθειών (BLS)', 36, 1),
('BLS/AED', 'Βασική Υποστήριξη Ζωής & Αυτόματος Εξωτερικός Απινιδωτής', 36, 0),
('Δίπλωμα Οδήγησης', 'Άδεια οδήγησης αυτοκινήτου / μοτοσυκλέτας', NULL, 0),
('PHTLS', 'Prehospital Trauma Life Support', 48, 0);

-- Default certificate settings
INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
('certificate_reminder_days_first', '30'),
('certificate_reminder_days_urgent', '7')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

-- =============================================
-- TRAINING MODULE TABLES
-- =============================================

-- =============================================
-- TRAINING CATEGORIES TABLE
-- =============================================
CREATE TABLE IF NOT EXISTS `training_categories` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `description` TEXT NULL,
    `icon` VARCHAR(50) DEFAULT '📚',
    `display_order` INT DEFAULT 0,
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_categories_active` (`is_active`),
    INDEX `idx_categories_order` (`display_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- TRAINING MATERIALS TABLE
-- =============================================
CREATE TABLE IF NOT EXISTS `training_materials` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `category_id` INT UNSIGNED NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT NULL,
    `file_path` VARCHAR(500) NOT NULL,
    `file_type` VARCHAR(100) NOT NULL,
    `file_size` INT UNSIGNED NOT NULL,
    `uploaded_by` INT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`category_id`) REFERENCES `training_categories`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`uploaded_by`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_materials_category` (`category_id`),
    INDEX `idx_materials_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- TRAINING QUIZZES TABLE (Informal practice tests)
-- =============================================
CREATE TABLE IF NOT EXISTS `training_quizzes` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT NULL,
    `category_id` INT UNSIGNED NOT NULL,
    `time_limit_minutes` INT NULL,
    `is_active` TINYINT(1) DEFAULT 1,
    `created_by` INT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`category_id`) REFERENCES `training_categories`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_quizzes_category` (`category_id`),
    INDEX `idx_quizzes_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- TRAINING EXAMS TABLE (Official exams with random question selection)
-- =============================================
CREATE TABLE IF NOT EXISTS `training_exams` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT NULL,
    `category_id` INT UNSIGNED NOT NULL,
    `questions_per_attempt` INT DEFAULT 10,
    `passing_percentage` INT DEFAULT 70,
    `time_limit_minutes` INT NULL,
    `is_active` TINYINT(1) DEFAULT 1,
    `allow_retake` TINYINT(1) DEFAULT 0,
    `max_attempts` INT DEFAULT 1,
    `use_random_pool` TINYINT(1) DEFAULT 0,
    `available_from` DATETIME NULL,
    `available_until` DATETIME NULL,
    `created_by` INT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`category_id`) REFERENCES `training_categories`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_exams_category` (`category_id`),
    INDEX `idx_exams_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- TRAINING QUIZ QUESTIONS TABLE
-- =============================================
CREATE TABLE IF NOT EXISTS `training_quiz_questions` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `category_id` INT UNSIGNED NOT NULL COMMENT 'Required - questions always belong to a category',
    `quiz_id` INT UNSIGNED NULL COMMENT 'Nullable - questions remain in pool when quiz is deleted',
    `question_type` ENUM('MULTIPLE_CHOICE', 'TRUE_FALSE', 'OPEN_ENDED') DEFAULT 'MULTIPLE_CHOICE',
    `question_text` TEXT NOT NULL,
    `option_a` VARCHAR(500) NULL,
    `option_b` VARCHAR(500) NULL,
    `option_c` VARCHAR(500) NULL,
    `option_d` VARCHAR(500) NULL,
    `correct_option` CHAR(1) NULL COMMENT 'A, B, C, D, or T/F for True/False',
    `explanation` TEXT NULL,
    `display_order` INT DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`category_id`) REFERENCES `training_categories`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`quiz_id`) REFERENCES `training_quizzes`(`id`) ON DELETE SET NULL,
    INDEX `idx_quiz_questions_category` (`category_id`),
    INDEX `idx_quiz_questions_quiz` (`quiz_id`),
    INDEX `idx_quiz_questions_order` (`display_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- TRAINING EXAM QUESTIONS TABLE (Question pool for exams)
-- =============================================
CREATE TABLE IF NOT EXISTS `training_exam_questions` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `category_id` INT UNSIGNED NOT NULL COMMENT 'Required - questions always belong to a category',
    `exam_id` INT UNSIGNED NULL COMMENT 'Nullable - questions remain in pool when exam is deleted',
    `question_type` ENUM('MULTIPLE_CHOICE', 'TRUE_FALSE', 'OPEN_ENDED') DEFAULT 'MULTIPLE_CHOICE',
    `question_text` TEXT NOT NULL,
    `option_a` VARCHAR(500) NULL,
    `option_b` VARCHAR(500) NULL,
    `option_c` VARCHAR(500) NULL,
    `option_d` VARCHAR(500) NULL,
    `correct_option` CHAR(1) NULL COMMENT 'A, B, C, D, or T/F for True/False',
    `explanation` TEXT NULL,
    `display_order` INT DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`category_id`) REFERENCES `training_categories`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`exam_id`) REFERENCES `training_exams`(`id`) ON DELETE SET NULL,
    INDEX `idx_exam_questions_category` (`category_id`),
    INDEX `idx_exam_questions_exam` (`exam_id`),
    INDEX `idx_exam_questions_order` (`display_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- QUIZ ATTEMPTS TABLE (Repeatable practice)
-- =============================================
CREATE TABLE IF NOT EXISTS `quiz_attempts` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `quiz_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `score` INT DEFAULT 0,
    `total_questions` INT DEFAULT 0,
    `started_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `completed_at` TIMESTAMP NULL,
    `time_taken_seconds` INT NULL,
    FOREIGN KEY (`quiz_id`) REFERENCES `training_quizzes`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_quiz_attempts_user` (`user_id`),
    INDEX `idx_quiz_attempts_quiz` (`quiz_id`),
    INDEX `idx_quiz_attempts_completed` (`completed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- EXAM ATTEMPTS TABLE (Official one-time attempts)
-- =============================================
CREATE TABLE IF NOT EXISTS `exam_attempts` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `exam_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `selected_questions_json` JSON NOT NULL COMMENT 'Array of question IDs that were randomly selected',
    `score` INT DEFAULT 0,
    `total_questions` INT DEFAULT 0,
    `passing_percentage` INT DEFAULT 70,
    `passed` TINYINT(1) DEFAULT 0,
    `started_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `completed_at` TIMESTAMP NULL,
    `time_taken_seconds` INT NULL,
    FOREIGN KEY (`exam_id`) REFERENCES `training_exams`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `unique_exam_attempt` (`exam_id`, `user_id`),
    INDEX `idx_exam_attempts_user` (`user_id`),
    INDEX `idx_exam_attempts_completed` (`completed_at`),
    INDEX `idx_exam_attempts_passed` (`passed`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- USER ANSWERS TABLE (Stores all answers for quizzes and exams)
-- =============================================
CREATE TABLE IF NOT EXISTS `user_answers` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `attempt_id` INT UNSIGNED NOT NULL,
    `attempt_type` ENUM('QUIZ', 'EXAM') NOT NULL,
    `question_id` INT UNSIGNED NOT NULL,
    `selected_option` CHAR(1) NULL COMMENT 'A, B, C, D, T, or F',
    `answer_text` TEXT NULL,
    `is_correct` TINYINT(1) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_answers_attempt` (`attempt_id`, `attempt_type`),
    INDEX `idx_answers_question` (`question_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- TRAINING USER PROGRESS TABLE (Track overall progress)
-- =============================================
CREATE TABLE IF NOT EXISTS `training_user_progress` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `category_id` INT UNSIGNED NOT NULL,
    `materials_viewed_json` LONGTEXT NULL COMMENT 'JSON array of material IDs viewed',
    `quizzes_completed` INT DEFAULT 0,
    `quizzes_passed` INT DEFAULT 0,
    `exams_passed` INT DEFAULT 0,
    `last_activity` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`category_id`) REFERENCES `training_categories`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `unique_user_category` (`user_id`, `category_id`),
    INDEX `idx_progress_user` (`user_id`),
    INDEX `idx_progress_category` (`category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- TASK MANAGER TABLES
-- =============================================
CREATE TABLE IF NOT EXISTS `tasks` (
    `id` INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT,
    `priority` ENUM('LOW', 'MEDIUM', 'HIGH', 'URGENT') DEFAULT 'MEDIUM',
    `status` ENUM('TODO', 'IN_PROGRESS', 'COMPLETED', 'CANCELED') DEFAULT 'TODO',
    `deadline` DATETIME,
    `created_by` INT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `completed_at` DATETIME,
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `subtasks` (
    `id` INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    `task_id` INT UNSIGNED NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `is_completed` TINYINT(1) DEFAULT 0,
    `completed_at` DATETIME,
    `completed_by` INT UNSIGNED,
    `sort_order` INT DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`task_id`) REFERENCES `tasks`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`completed_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `task_assignments` (
    `id` INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    `task_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `assigned_by` INT UNSIGNED NOT NULL,
    `assigned_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_assignment` (`task_id`, `user_id`),
    FOREIGN KEY (`task_id`) REFERENCES `tasks`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT,
    FOREIGN KEY (`assigned_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `task_comments` (
    `id` INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    `task_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `comment` TEXT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`task_id`) REFERENCES `tasks`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- MISSION CHAT TABLE
-- =============================================
CREATE TABLE IF NOT EXISTS `mission_chat_messages` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `mission_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `message` TEXT NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`mission_id`) REFERENCES `missions`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_mission_created` (`mission_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- INDEXES FOR PERFORMANCE
-- =============================================
CREATE INDEX `idx_users_cohort_year` ON `users`(`cohort_year`);
CREATE INDEX IF NOT EXISTS `idx_tasks_status` ON `tasks`(`status`);
CREATE INDEX IF NOT EXISTS `idx_tasks_deadline` ON `tasks`(`deadline`);
CREATE INDEX IF NOT EXISTS `idx_tasks_created_by` ON `tasks`(`created_by`);
CREATE INDEX IF NOT EXISTS `idx_task_assignments_user` ON `task_assignments`(`user_id`);
CREATE INDEX IF NOT EXISTS `idx_task_comments_task` ON `task_comments`(`task_id`);

-- =============================================
-- VOLUNTEER GPS PINGS
-- =============================================
CREATE TABLE IF NOT EXISTS `volunteer_pings` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `shift_id` INT UNSIGNED NOT NULL,
    `lat` DECIMAL(10, 8) NOT NULL,
    `lng` DECIMAL(11, 8) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`shift_id`) REFERENCES `shifts`(`id`) ON DELETE CASCADE,
    INDEX `idx_pings_shift_time` (`shift_id`, `created_at`),
    INDEX `idx_pings_user_shift` (`user_id`, `shift_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
