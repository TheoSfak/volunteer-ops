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
    `has_inventory` TINYINT(1) DEFAULT 0,
    `inventory_settings` JSON NULL,
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
    `profile_photo` VARCHAR(255) NULL DEFAULT NULL,
    `role` ENUM('SYSTEM_ADMIN', 'DEPARTMENT_ADMIN', 'SHIFT_LEADER', 'VOLUNTEER') DEFAULT 'VOLUNTEER',
    `volunteer_type` ENUM('TRAINEE_RESCUER','RESCUER') NOT NULL DEFAULT 'RESCUER',
    `position_id` INT UNSIGNED NULL,
    `cohort_year` YEAR NULL COMMENT 'Χρονιά σειράς δοκίμων διασωστών',
    `department_id` INT UNSIGNED NULL,
    `warehouse_id` INT UNSIGNED NULL,
    `is_active` TINYINT(1) DEFAULT 1,
    `total_points` INT DEFAULT 0,
    `monthly_points` INT DEFAULT 0,
    `email_verified_at` TIMESTAMP NULL,
    `email_verification_token` VARCHAR(100) NULL,
    `approval_status` ENUM('PENDING','APPROVED','REJECTED') NOT NULL DEFAULT 'APPROVED',
    `remember_token` VARCHAR(100) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `newsletter_unsubscribed` TINYINT(1) NOT NULL DEFAULT 0,
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
(4, 'Διασωστική', 'Επιχείρηση διάσωσης και αντιμετώπιση κινδύνων', 'warning', 'bi-shield-exclamation', 4),
(5, 'Τ.Ε.Π.', 'Αποστολές Τμήματος Επειγόντων Περιστατικών — Νοσοκομείο', 'purple', 'bi-hospital', 5);

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
    `responsible_user_id` INT UNSIGNED NULL,
    `cancellation_reason` TEXT NULL,
    `canceled_by` INT UNSIGNED NULL,
    `canceled_at` TIMESTAMP NULL,
    `deleted_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`department_id`) REFERENCES `departments`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`mission_type_id`) REFERENCES `mission_types`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`responsible_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
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
    `notified` TINYINT(1) NOT NULL DEFAULT 0,
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
-- VOLUNTEER DOCUMENTS TABLE
-- =============================================
CREATE TABLE IF NOT EXISTS `volunteer_documents` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `label` VARCHAR(255) NOT NULL,
    `original_name` VARCHAR(255) NOT NULL,
    `stored_name` VARCHAR(255) NOT NULL,
    `mime_type` VARCHAR(100) NOT NULL,
    `file_size` INT NOT NULL DEFAULT 0,
    `uploaded_by` INT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_vd_user_id` (`user_id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`uploaded_by`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- VOLUNTEER POSITIONS TABLE
-- =============================================
CREATE TABLE IF NOT EXISTS `volunteer_positions` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `color` VARCHAR(20) DEFAULT 'secondary',
    `icon` VARCHAR(50) NULL,
    `description` TEXT NULL,
    `is_active` TINYINT(1) DEFAULT 1,
    `sort_order` INT DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- SKILL CATEGORIES TABLE
-- =============================================
CREATE TABLE IF NOT EXISTS `skill_categories` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL UNIQUE,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- PASSWORD RESET TOKENS TABLE
-- =============================================
CREATE TABLE IF NOT EXISTS `password_reset_tokens` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `token` VARCHAR(100) NOT NULL UNIQUE,
    `expires_at` DATETIME NOT NULL,
    `used_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_prt_token` (`token`),
    INDEX `idx_prt_user` (`user_id`)
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
('first_shift',    'Πρώτη Βάρδια',            'Ολοκλήρωσε την πρώτη σου βάρδια',                 'milestone', '⭐', 0,    1   ),
('first_mission',  'Πρώτη Αποστολή',          'Ολοκλήρωσε την πρώτη σου αποστολή',               'milestone', '🚀', 0,    1   ),
('shifts_5',       '5 Βάρδιες',               'Ολοκλήρωσε 5 βάρδιες',                            'shifts',    '📅', 0,    5   ),
('shifts_10',      '10 Βάρδιες',              'Ολοκλήρωσε 10 βάρδιες',                           'shifts',    '📅', 0,    10  ),
('shifts_25',      '25 Βάρδιες',              'Ολοκλήρωσε 25 βάρδιες',                           'shifts',    '📅', 0,    25  ),
('shifts_50',      '50 Βάρδιες',              'Ολοκλήρωσε 50 βάρδιες',                           'shifts',    '🎯', 0,    50  ),
('shifts_100',     '100 Βάρδιες',             'Ολοκλήρωσε 100 βάρδιες',                          'shifts',    '🏅', 0,    100 ),
('missions_3',     '3 Αποστολές',             'Ολοκλήρωσε 3 αποστολές',                          'missions',  '📋', 0,    3   ),
('missions_10',    '10 Αποστολές',            'Ολοκλήρωσε 10 αποστολές',                         'missions',  '🌟', 0,    10  ),
('missions_25',    '25 Αποστολές',            'Ολοκλήρωσε 25 αποστολές',                         'missions',  '💫', 0,    25  ),
('missions_50',    '50 Αποστολές',            'Ολοκλήρωσε 50 αποστολές',                         'missions',  '🏆', 0,    50  ),
('hours_10',       '10 Ώρες',                 'Συμπλήρωσε 10 ώρες εθελοντισμού',                'hours',     '⏰', 0,    10  ),
('hours_50',       '50 Ώρες',                 'Συμπλήρωσε 50 ώρες εθελοντισμού',                'hours',     '⏰', 0,    50  ),
('hours_100',      '100 Ώρες',                'Συμπλήρωσε 100 ώρες εθελοντισμού',               'hours',     '⏰', 0,    100 ),
('hours_250',      '250 Ώρες',                'Συμπλήρωσε 250 ώρες εθελοντισμού',               'hours',     '🏆', 0,    250 ),
('hours_500',      '500 Ώρες',                'Συμπλήρωσε 500 ώρες εθελοντισμού',               'hours',     '⚡', 0,    500 ),
('hours_1000',     '1000 Ώρες',               'Συμπλήρωσε 1000 ώρες εθελοντισμού',              'hours',     '💎', 0,    1000),
('points_100',     '100 Πόντοι',              'Συγκέντρωσε 100 πόντους',                         'points',    '💯', 100,  0   ),
('points_500',     '500 Πόντοι',              'Συγκέντρωσε 500 πόντους',                         'points',    '🌟', 500,  0   ),
('points_1000',    '1000 Πόντοι',             'Συγκέντρωσε 1000 πόντους',                        'points',    '👑', 1000, 0   ),
('points_2000',    '2000 Πόντοι',             'Συγκέντρωσε 2000 πόντους',                        'points',    '🎖️',2000, 0   ),
('points_5000',    '5000 Πόντοι',             'Συγκέντρωσε 5000 πόντους',                        'points',    '🥇', 5000, 0   ),
('weekend_warrior','Πολεμιστής Σ/Κ',          'Ολοκλήρωσε 10 βάρδιες Σαββατοκύριακου',          'special',   '☀️', 0,    10  ),
('night_owl',      'Νυχτοπούλι',              'Ολοκλήρωσε 10 νυχτερινές βάρδιες',               'special',   '🌙', 0,    10  ),
('medical_hero',   'Ήρωας Υγείας',            'Ολοκλήρωσε 10 υγειονομικές αποστολές',           'special',   '❤️', 0,    10  ),
('early_bird',     'Πτηνό της Αυγής',         'Ολοκλήρωσε 5 βάρδιες πριν τις 8:00',             'special',   '🌅', 0,    5   ),
('dedicated',      'Αφοσιωμένος',             'Συμμετοχή σε 5+ διαφορετικούς μήνες',            'special',   '🗓️', 0,   5   ),
('loyal_member',   'Πιστό Μέλος',             'Μέλος της ομάδας για 1+ χρόνο',                  'special',   '💙', 0,    365 ),
('rescuer_elite',  'Ελίτ Διασώστης',          '250+ ώρες εθελοντισμού και 50+ αποστολές',       'special',   '⭐', 0,    0   );

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
'{{app_name}}, {{user_name}}, {{certificate_type}}, {{expiry_date}}, {{days_remaining}}'),

('task_assigned', 'Ανάθεση Εργασίας', 'Νέα Εργασία: {{task_title}}',
'<div style="background:#eef2f7;padding:28px 0 40px;font-family:Helvetica Neue,Arial,sans-serif;"><div style="max-width:600px;margin:0 auto;"><div style="background:#4f46e5;padding:30px 40px 26px;border-radius:12px 12px 0 0;text-align:center;">{{logo_html}}<p style="color:rgba(255,255,255,0.7);font-size:11px;letter-spacing:2px;text-transform:uppercase;margin:0 0 8px;">{{app_name}}</p><div style="font-size:36px;line-height:1;margin:0 0 8px;">&#128203;</div><h1 style="color:#fff;margin:0;font-size:23px;font-weight:700;line-height:1.3;">Νέα Ανάθεση Εργασίας</h1></div><div style="background:#fff;padding:36px 40px 40px;border-radius:0 0 12px 12px;box-shadow:0 4px 20px rgba(0,0,0,0.07);"><h2 style="color:#1f2937;font-size:18px;font-weight:700;margin:0 0 14px;">Γεια σας {{user_name}},</h2><p style="color:#4b5563;line-height:1.65;font-size:15px;margin:0 0 14px;">Σας ανατέθηκε μια νέα εργασία από τον/την <strong>{{assigned_by}}</strong>. Παρακαλούμε ελέγξτε τις λεπτομέρειες παρακάτω.</p><div style="background:#f9fafb;border-left:4px solid #4f46e5;padding:2px 20px;border-radius:0 8px 8px 0;margin:20px 0;"><div style="padding:7px 0;font-size:14px;border-bottom:1px solid #f3f4f6;"><span style="color:#9ca3af;display:inline-block;min-width:140px;">Εργασία:</span><span style="color:#111827;font-weight:600;">{{task_title}}</span></div><div style="padding:7px 0;font-size:14px;border-bottom:1px solid #f3f4f6;"><span style="color:#9ca3af;display:inline-block;min-width:140px;">Περιγραφή:</span><span style="color:#111827;font-weight:600;">{{task_description}}</span></div><div style="padding:7px 0;font-size:14px;border-bottom:1px solid #f3f4f6;"><span style="color:#9ca3af;display:inline-block;min-width:140px;">Προτεραιότητα:</span><span style="color:#111827;font-weight:600;">{{task_priority}}</span></div><div style="padding:7px 0;font-size:14px;"><span style="color:#9ca3af;display:inline-block;min-width:140px;">Προθεσμία:</span><span style="color:#111827;font-weight:600;">{{task_deadline}}</span></div></div><p style="color:#4b5563;line-height:1.65;font-size:15px;margin:0 0 14px;">Μπορείτε να δείτε τις λεπτομέρειες της εργασίας συνδεόμενοι στο σύστημα.</p><div style="text-align:center;margin:28px 0 4px;"><a href="{{login_url}}" style="background:#4f46e5;color:#ffffff;text-decoration:none;padding:13px 38px;border-radius:8px;font-size:15px;font-weight:700;display:inline-block;letter-spacing:0.3px;">Δείτε την Εργασία</a></div></div><div style="text-align:center;padding:18px 0 0;color:#9ca3af;font-size:12px;line-height:1.9;"><p style="margin:0;"><strong style="color:#6b7280;">{{app_name}}</strong> &bull; Σύστημα Διαχείρισης Εθελοντών</p><p style="margin:0;">Αυτό το μήνυμα στάλθηκε αυτόματα από το σύστημα.</p></div></div></div>',
'Αποστέλλεται όταν ανατίθεται εργασία σε εθελοντή',
'user_name, task_title, task_description, task_priority, task_deadline, assigned_by'),

('task_comment', 'Σχόλιο σε Εργασία', 'Νέο Σχόλιο στην Εργασία: {{task_title}}',
'<div style="background:#eef2f7;padding:28px 0 40px;font-family:Helvetica Neue,Arial,sans-serif;"><div style="max-width:600px;margin:0 auto;"><div style="background:#3b82f6;padding:30px 40px 26px;border-radius:12px 12px 0 0;text-align:center;">{{logo_html}}<p style="color:rgba(255,255,255,0.7);font-size:11px;letter-spacing:2px;text-transform:uppercase;margin:0 0 8px;">{{app_name}}</p><div style="font-size:36px;line-height:1;margin:0 0 8px;">&#128172;</div><h1 style="color:#fff;margin:0;font-size:23px;font-weight:700;line-height:1.3;">Νέο Σχόλιο στην Εργασία</h1></div><div style="background:#fff;padding:36px 40px 40px;border-radius:0 0 12px 12px;box-shadow:0 4px 20px rgba(0,0,0,0.07);"><h2 style="color:#1f2937;font-size:18px;font-weight:700;margin:0 0 14px;">Γεια σας {{user_name}},</h2><p style="color:#4b5563;line-height:1.65;font-size:15px;margin:0 0 14px;">Ο/Η <strong>{{commented_by}}</strong> πρόσθεσε ένα νέο σχόλιο στην εργασία "<strong>{{task_title}}</strong>".</p><div style="background:#f0f4ff;border-left:4px solid #3b82f6;padding:16px 20px;border-radius:0 8px 8px 0;margin:20px 0;"><p style="color:#1e40af;font-size:14px;line-height:1.65;margin:0;font-style:italic;">{{comment}}</p></div><p style="color:#4b5563;line-height:1.65;font-size:15px;margin:0 0 14px;">Συνδεθείτε στο σύστημα για να δείτε την εργασία και να απαντήσετε.</p><div style="text-align:center;margin:28px 0 4px;"><a href="{{login_url}}" style="background:#3b82f6;color:#ffffff;text-decoration:none;padding:13px 38px;border-radius:8px;font-size:15px;font-weight:700;display:inline-block;letter-spacing:0.3px;">Δείτε την Εργασία</a></div></div><div style="text-align:center;padding:18px 0 0;color:#9ca3af;font-size:12px;line-height:1.9;"><p style="margin:0;"><strong style="color:#6b7280;">{{app_name}}</strong> &bull; Σύστημα Διαχείρισης Εθελοντών</p><p style="margin:0;">Αυτό το μήνυμα στάλθηκε αυτόματα από το σύστημα.</p></div></div></div>',
'Αποστέλλεται όταν προστίθεται σχόλιο σε εργασία',
'user_name, task_title, comment, commented_by'),

('task_deadline_reminder', 'Υπενθύμιση Προθεσμίας', 'Υπενθύμιση: Η εργασία {{task_title}} λήγει σύντομα',
'<div style="background:#eef2f7;padding:28px 0 40px;font-family:Helvetica Neue,Arial,sans-serif;"><div style="max-width:600px;margin:0 auto;"><div style="background:#f97316;padding:30px 40px 26px;border-radius:12px 12px 0 0;text-align:center;">{{logo_html}}<p style="color:rgba(255,255,255,0.7);font-size:11px;letter-spacing:2px;text-transform:uppercase;margin:0 0 8px;">{{app_name}}</p><div style="font-size:36px;line-height:1;margin:0 0 8px;">&#9200;</div><h1 style="color:#fff;margin:0;font-size:23px;font-weight:700;line-height:1.3;">Υπενθύμιση Προθεσμίας Εργασίας</h1></div><div style="background:#fff;padding:36px 40px 40px;border-radius:0 0 12px 12px;box-shadow:0 4px 20px rgba(0,0,0,0.07);"><h2 style="color:#1f2937;font-size:18px;font-weight:700;margin:0 0 14px;">Γεια σας {{user_name}},</h2><p style="color:#4b5563;line-height:1.65;font-size:15px;margin:0 0 14px;">Σας υπενθυμίζουμε ότι η εργασία "<strong>{{task_title}}</strong>" λήγει σε <strong>λιγότερο από 24 ώρες</strong>.</p><div style="background:#f9fafb;border-left:4px solid #f97316;padding:2px 20px;border-radius:0 8px 8px 0;margin:20px 0;"><div style="padding:7px 0;font-size:14px;border-bottom:1px solid #f3f4f6;"><span style="color:#9ca3af;display:inline-block;min-width:140px;">Εργασία:</span><span style="color:#111827;font-weight:600;">{{task_title}}</span></div><div style="padding:7px 0;font-size:14px;border-bottom:1px solid #f3f4f6;"><span style="color:#9ca3af;display:inline-block;min-width:140px;">Προθεσμία:</span><span style="color:#111827;font-weight:600;">{{task_deadline}}</span></div><div style="padding:7px 0;font-size:14px;border-bottom:1px solid #f3f4f6;"><span style="color:#9ca3af;display:inline-block;min-width:140px;">Κατάσταση:</span><span style="color:#111827;font-weight:600;">{{task_status}}</span></div><div style="padding:7px 0;font-size:14px;"><span style="color:#9ca3af;display:inline-block;min-width:140px;">Πρόοδος:</span><span style="color:#111827;font-weight:600;">{{task_progress}}%</span></div></div><div style="background:#fff7ed;border:1px solid #fed7aa;border-radius:8px;padding:14px 20px;margin:20px 0;"><p style="color:#c2410c;font-size:14px;font-weight:600;margin:0;">⏰ Η προθεσμία πλησιάζει — παρακαλούμε ολοκληρώστε την εργασία εγκαίρως.</p></div><div style="text-align:center;margin:28px 0 4px;"><a href="{{login_url}}" style="background:#f97316;color:#ffffff;text-decoration:none;padding:13px 38px;border-radius:8px;font-size:15px;font-weight:700;display:inline-block;letter-spacing:0.3px;">Δείτε την Εργασία</a></div></div><div style="text-align:center;padding:18px 0 0;color:#9ca3af;font-size:12px;line-height:1.9;"><p style="margin:0;"><strong style="color:#6b7280;">{{app_name}}</strong> &bull; Σύστημα Διαχείρισης Εθελοντών</p><p style="margin:0;">Αυτό το μήνυμα στάλθηκε αυτόματα από το σύστημα.</p></div></div></div>',
'Αποστέλλεται 24 ώρες πριν τη λήξη προθεσμίας εργασίας',
'user_name, task_title, task_deadline, task_status, task_progress'),

('task_status_changed', 'Αλλαγή Κατάστασης Εργασίας', 'Αλλαγή Κατάστασης: {{task_title}}',
'<div style="background:#eef2f7;padding:28px 0 40px;font-family:Helvetica Neue,Arial,sans-serif;"><div style="max-width:600px;margin:0 auto;"><div style="background:#8b5cf6;padding:30px 40px 26px;border-radius:12px 12px 0 0;text-align:center;">{{logo_html}}<p style="color:rgba(255,255,255,0.7);font-size:11px;letter-spacing:2px;text-transform:uppercase;margin:0 0 8px;">{{app_name}}</p><div style="font-size:36px;line-height:1;margin:0 0 8px;">&#128260;</div><h1 style="color:#fff;margin:0;font-size:23px;font-weight:700;line-height:1.3;">Αλλαγή Κατάστασης Εργασίας</h1></div><div style="background:#fff;padding:36px 40px 40px;border-radius:0 0 12px 12px;box-shadow:0 4px 20px rgba(0,0,0,0.07);"><h2 style="color:#1f2937;font-size:18px;font-weight:700;margin:0 0 14px;">Γεια σας {{user_name}},</h2><p style="color:#4b5563;line-height:1.65;font-size:15px;margin:0 0 14px;">Ο/Η <strong>{{changed_by}}</strong> άλλαξε την κατάσταση της εργασίας "<strong>{{task_title}}</strong>".</p><div style="text-align:center;margin:24px 0;padding:20px;background:#f9fafb;border-radius:8px;"><span style="background:#fef2f2;color:#991b1b;padding:6px 14px;border-radius:6px;font-size:14px;font-weight:600;text-decoration:line-through;">{{old_status}}</span><span style="display:inline-block;margin:0 16px;color:#9ca3af;font-size:20px;">→</span><span style="background:#dcfce7;color:#166534;padding:6px 14px;border-radius:6px;font-size:14px;font-weight:600;">{{new_status}}</span></div><p style="color:#4b5563;line-height:1.65;font-size:15px;margin:0 0 14px;">Συνδεθείτε στο σύστημα για να δείτε τις λεπτομέρειες.</p><div style="text-align:center;margin:28px 0 4px;"><a href="{{login_url}}" style="background:#8b5cf6;color:#ffffff;text-decoration:none;padding:13px 38px;border-radius:8px;font-size:15px;font-weight:700;display:inline-block;letter-spacing:0.3px;">Δείτε την Εργασία</a></div></div><div style="text-align:center;padding:18px 0 0;color:#9ca3af;font-size:12px;line-height:1.9;"><p style="margin:0;"><strong style="color:#6b7280;">{{app_name}}</strong> &bull; Σύστημα Διαχείρισης Εθελοντών</p><p style="margin:0;">Αυτό το μήνυμα στάλθηκε αυτόματα από το σύστημα.</p></div></div></div>',
'Αποστέλλεται όταν αλλάζει η κατάσταση εργασίας',
'user_name, task_title, old_status, new_status, changed_by'),

('task_subtask_completed', 'Ολοκλήρωση Υποεργασίας', 'Ολοκληρώθηκε Υποεργασία στην: {{task_title}}',
'<div style="background:#eef2f7;padding:28px 0 40px;font-family:Helvetica Neue,Arial,sans-serif;"><div style="max-width:600px;margin:0 auto;"><div style="background:#22c55e;padding:30px 40px 26px;border-radius:12px 12px 0 0;text-align:center;">{{logo_html}}<p style="color:rgba(255,255,255,0.7);font-size:11px;letter-spacing:2px;text-transform:uppercase;margin:0 0 8px;">{{app_name}}</p><div style="font-size:36px;line-height:1;margin:0 0 8px;">&#9989;</div><h1 style="color:#fff;margin:0;font-size:23px;font-weight:700;line-height:1.3;">Ολοκλήρωση Υποεργασίας</h1></div><div style="background:#fff;padding:36px 40px 40px;border-radius:0 0 12px 12px;box-shadow:0 4px 20px rgba(0,0,0,0.07);"><h2 style="color:#1f2937;font-size:18px;font-weight:700;margin:0 0 14px;">Γεια σας {{user_name}},</h2><p style="color:#4b5563;line-height:1.65;font-size:15px;margin:0 0 14px;">Ο/Η <strong>{{completed_by}}</strong> ολοκλήρωσε μια υποεργασία στην εργασία "<strong>{{task_title}}</strong>".</p><div style="background:#f9fafb;border-left:4px solid #22c55e;padding:2px 20px;border-radius:0 8px 8px 0;margin:20px 0;"><div style="padding:7px 0;font-size:14px;border-bottom:1px solid #f3f4f6;"><span style="color:#9ca3af;display:inline-block;min-width:140px;">Υποεργασία:</span><span style="color:#111827;font-weight:600;">{{subtask_title}}</span></div><div style="padding:7px 0;font-size:14px;"><span style="color:#9ca3af;display:inline-block;min-width:140px;">Εργασία:</span><span style="color:#111827;font-weight:600;">{{task_title}}</span></div></div><div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:14px 20px;margin:20px 0;"><p style="color:#166534;font-size:14px;font-weight:600;margin:0;">✅ Η υποεργασία έχει σημανθεί ως ολοκληρωμένη.</p></div><p style="color:#4b5563;line-height:1.65;font-size:15px;margin:0 0 14px;">Συνδεθείτε στο σύστημα για να δείτε την πρόοδο της εργασίας.</p><div style="text-align:center;margin:28px 0 4px;"><a href="{{login_url}}" style="background:#22c55e;color:#ffffff;text-decoration:none;padding:13px 38px;border-radius:8px;font-size:15px;font-weight:700;display:inline-block;letter-spacing:0.3px;">Δείτε την Εργασία</a></div></div><div style="text-align:center;padding:18px 0 0;color:#9ca3af;font-size:12px;line-height:1.9;"><p style="margin:0;"><strong style="color:#6b7280;">{{app_name}}</strong> &bull; Σύστημα Διαχείρισης Εθελοντών</p><p style="margin:0;">Αυτό το μήνυμα στάλθηκε αυτόματα από το σύστημα.</p></div></div></div>',
'Αποστέλλεται όταν ολοκληρώνεται υποεργασία',
'user_name, task_title, subtask_title, completed_by'),

('mission_needs_volunteers', 'Αποστολή Χρειάζεται Εθελοντές', 'Η αποστολή {{mission_title}} χρειάζεται εθελοντές!',
'<div style="background:#eef2f7;padding:28px 0 40px;font-family:Helvetica Neue,Arial,sans-serif;"><div style="max-width:600px;margin:0 auto;"><div style="background:#dc2626;padding:30px 40px 26px;border-radius:12px 12px 0 0;text-align:center;">{{logo_html}}<p style="color:rgba(255,255,255,0.7);font-size:11px;letter-spacing:2px;text-transform:uppercase;margin:0 0 8px;">{{app_name}}</p><div style="font-size:36px;line-height:1;margin:0 0 8px;">&#128680;</div><h1 style="color:#fff;margin:0;font-size:23px;font-weight:700;line-height:1.3;">Χρειάζονται Εθελοντές!</h1></div><div style="background:#fff;padding:36px 40px 40px;border-radius:0 0 12px 12px;box-shadow:0 4px 20px rgba(0,0,0,0.07);"><h2 style="color:#1f2937;font-size:18px;font-weight:700;margin:0 0 14px;">Γεια σας {{user_name}},</h2><p style="color:#4b5563;line-height:1.65;font-size:15px;margin:0 0 14px;">Η αποστολή "<strong>{{mission_title}}</strong>" χρειάζεται <strong>επειγόντως</strong> περισσότερους εθελοντές!</p><div style="background:#f9fafb;border-left:4px solid #dc2626;padding:2px 20px;border-radius:0 8px 8px 0;margin:20px 0;"><div style="padding:7px 0;font-size:14px;border-bottom:1px solid #f3f4f6;"><span style="color:#9ca3af;display:inline-block;min-width:140px;">Αποστολή:</span><span style="color:#111827;font-weight:600;">{{mission_title}}</span></div><div style="padding:7px 0;font-size:14px;border-bottom:1px solid #f3f4f6;"><span style="color:#9ca3af;display:inline-block;min-width:140px;">Ημερομηνία:</span><span style="color:#111827;font-weight:600;">{{mission_date}}</span></div><div style="padding:7px 0;font-size:14px;border-bottom:1px solid #f3f4f6;"><span style="color:#9ca3af;display:inline-block;min-width:140px;">Θέσεις Ανοιχτές:</span><span style="color:#111827;font-weight:600;">{{available_spots}}</span></div><div style="padding:7px 0;font-size:14px;"><span style="color:#9ca3af;display:inline-block;min-width:140px;">Συνολικές Θέσεις:</span><span style="color:#111827;font-weight:600;">{{total_spots}}</span></div></div><div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:14px 20px;margin:20px 0;"><p style="color:#dc2626;font-size:14px;font-weight:600;margin:0;">🚨 Η βοήθειά σας χρειάζεται! Κάθε εθελοντής κάνει τη διαφορά.</p></div><p style="color:#4b5563;line-height:1.65;font-size:15px;margin:0 0 14px;">Αν ενδιαφέρεστε να συμμετέχετε, παρακαλούμε συνδεθείτε στο σύστημα και κάντε αίτηση συμμετοχής.</p><div style="text-align:center;margin:28px 0 4px;"><a href="{{login_url}}" style="background:#dc2626;color:#ffffff;text-decoration:none;padding:13px 38px;border-radius:8px;font-size:15px;font-weight:700;display:inline-block;letter-spacing:0.3px;">Δηλώστε Συμμετοχή</a></div></div><div style="text-align:center;padding:18px 0 0;color:#9ca3af;font-size:12px;line-height:1.9;"><p style="margin:0;"><strong style="color:#6b7280;">{{app_name}}</strong> &bull; Σύστημα Διαχείρισης Εθελοντών</p><p style="margin:0;">Αυτό το μήνυμα στάλθηκε αυτόματα από το σύστημα.</p></div></div></div>',
'Αποστέλλεται όταν μια αποστολή πλησιάζει και δεν έχει αρκετούς εθελοντές',
'{{app_name}}, {{user_name}}, {{mission_title}}, {{mission_description}}, {{mission_url}}'),

('shelf_expiry_reminder', 'Υπενθύμιση Λήξης Ραφιών Αποθήκης', 'Υπενθύμιση: Είδη Αποθήκης Λήγουν ή Έχουν Λήξει',
'<div style="background:#eef2f7;padding:28px 0 40px;font-family:Helvetica Neue,Arial,sans-serif;"><div style="max-width:600px;margin:0 auto;"><div style="background:#d97706;padding:30px 40px 26px;border-radius:12px 12px 0 0;text-align:center;">{{logo_html}}<p style="color:rgba(255,255,255,0.7);font-size:11px;letter-spacing:2px;text-transform:uppercase;margin:0 0 8px;">{{app_name}}</p><div style="font-size:36px;line-height:1;margin:0 0 8px;">&#128230;</div><h1 style="color:#fff;margin:0;font-size:23px;font-weight:700;line-height:1.3;">Υπενθύμιση Λήξης Ραφιών Αποθήκης</h1></div><div style="background:#fff;padding:36px 40px 40px;border-radius:0 0 12px 12px;box-shadow:0 4px 20px rgba(0,0,0,0.07);"><h2 style="color:#1f2937;font-size:18px;font-weight:700;margin:0 0 14px;">Γεια σας {{user_name}},</h2><p style="color:#4b5563;line-height:1.65;font-size:15px;margin:0 0 14px;">Υπάρχουν είδη αποθήκης που πλησιάζουν ή έχουν ξεπεράσει την ημερομηνία λήξης τους.</p><div style="background:#f9fafb;border-left:4px solid #d97706;padding:2px 20px;border-radius:0 8px 8px 0;margin:20px 0;"><div style="padding:7px 0;font-size:14px;border-bottom:1px solid #f3f4f6;"><span style="color:#9ca3af;display:inline-block;min-width:140px;">Ληγμένα Είδη:</span><span style="color:#111827;font-weight:600;">{{expired_count}}</span></div><div style="padding:7px 0;font-size:14px;"><span style="color:#9ca3af;display:inline-block;min-width:140px;">Κοντά σε Λήξη (εντός {{threshold_days}} ημερών):</span><span style="color:#111827;font-weight:600;">{{expiring_count}}</span></div></div><div style="background:#f9fafb;border-radius:8px;padding:16px 20px;margin:20px 0;"><p style="color:#6b7280;font-size:13px;font-weight:600;text-transform:uppercase;letter-spacing:1px;margin:0 0 8px;">Λεπτομέρειες:</p><pre style="background:#fff;border:1px solid #e5e7eb;border-radius:6px;padding:12px 16px;font-size:13px;color:#374151;margin:0;white-space:pre-wrap;word-break:break-word;">{{details}}</pre></div><p style="color:#4b5563;line-height:1.65;font-size:15px;margin:0 0 14px;">Συνδεθείτε στο σύστημα για να ελέγξετε τα είδη.</p><div style="text-align:center;margin:28px 0 4px;"><a href="{{login_url}}" style="background:#d97706;color:#ffffff;text-decoration:none;padding:13px 38px;border-radius:8px;font-size:15px;font-weight:700;display:inline-block;letter-spacing:0.3px;">Διαχείριση Αποθήκης</a></div></div><div style="text-align:center;padding:18px 0 0;color:#9ca3af;font-size:12px;line-height:1.9;"><p style="margin:0;"><strong style="color:#6b7280;">{{app_name}}</strong> &bull; Σύστημα Διαχείρισης Εθελοντών</p><p style="margin:0;">Αυτό το μήνυμα στάλθηκε αυτόματα από το σύστημα.</p></div></div></div>',
'Αποστέλλεται όταν υπάρχουν ληγμένα ή υπό λήξη υλικά ραφιού',
'user_name, expired_count, expiring_count, details, threshold_days'),

('mission_reminder', 'Υπενθύμιση Αποστολής', 'Υπενθύμιση Αποστολής: {{mission_title}}',
'<div style="background:#eef2f7;padding:28px 0 40px;font-family:Helvetica Neue,Arial,sans-serif;"><div style="max-width:600px;margin:0 auto;"><div style="background:#fd7e14;padding:30px 40px 26px;border-radius:12px 12px 0 0;text-align:center;">{{logo_html}}<p style="color:rgba(255,255,255,0.7);font-size:11px;letter-spacing:2px;text-transform:uppercase;margin:0 0 8px;">{{app_name}}</p><div style="font-size:36px;line-height:1;margin:0 0 8px;">&#128226;</div><h1 style="color:#fff;margin:0;font-size:23px;font-weight:700;line-height:1.3;">Υπενθύμιση Αποστολής</h1></div><div style="background:#fff;padding:36px 40px 40px;border-radius:0 0 12px 12px;box-shadow:0 4px 20px rgba(0,0,0,0.07);"><h2 style="color:#1f2937;font-size:18px;font-weight:700;margin:0 0 14px;">Γεια σας {{user_name}},</h2><p style="color:#4b5563;line-height:1.65;font-size:15px;margin:0 0 14px;">Η παρακάτω αποστολή είναι ακόμα <strong>ανοιχτή</strong> και αναζητά εθελοντές:</p><div style="background:#f9fafb;border-left:4px solid #fd7e14;padding:2px 20px;border-radius:0 8px 8px 0;margin:20px 0;"><div style="padding:7px 0;font-size:14px;border-bottom:1px solid #f3f4f6;"><span style="color:#9ca3af;display:inline-block;min-width:140px;">Αποστολή:</span><span style="color:#111827;font-weight:600;">{{mission_title}}</span></div><div style="padding:7px 0;font-size:14px;"><span style="color:#9ca3af;display:inline-block;min-width:140px;">Περιγραφή:</span><span style="color:#111827;font-weight:600;">{{mission_description}}</span></div></div><p style="color:#4b5563;line-height:1.65;font-size:15px;margin:0 0 14px;">Μη χάσετε την ευκαιρία να συμμετέχετε και να κάνετε τη διαφορά!</p><div style="text-align:center;margin:28px 0 4px;"><a href="{{mission_url}}" style="background:#fd7e14;color:#ffffff;text-decoration:none;padding:13px 38px;border-radius:8px;font-size:15px;font-weight:700;display:inline-block;letter-spacing:0.3px;">Δείτε την Αποστολή</a></div></div><div style="text-align:center;padding:18px 0 0;color:#9ca3af;font-size:12px;line-height:1.9;"><p style="margin:0;"><strong style="color:#6b7280;">{{app_name}}</strong> &bull; Σύστημα Διαχείρισης Εθελοντών</p><p style="margin:0;">Αυτό το μήνυμα στάλθηκε αυτόματα από το σύστημα.</p></div></div></div>',
'Όταν στέλνεται υπενθύμιση για ανοιχτή αποστολή',
'{{app_name}}, {{user_name}}, {{mission_title}}, {{mission_description}}, {{mission_url}}'),

('complaint_submitted', 'Νέο Παράπονο (Admin)', 'Νέο παράπονο εθελοντή - {{complaint_subject}}',
'<div style="background:#eef2f7;padding:28px 0 40px;font-family:Helvetica Neue,Arial,sans-serif;"><div style="max-width:600px;margin:0 auto;"><div style="background:#dc2626;padding:30px 40px 26px;border-radius:12px 12px 0 0;text-align:center;">{{logo_html}}<p style="color:rgba(255,255,255,0.7);font-size:11px;letter-spacing:2px;text-transform:uppercase;margin:0 0 8px;">{{app_name}}</p><div style="font-size:36px;line-height:1;margin:0 0 8px;">&#9888;&#65039;</div><h1 style="color:#fff;margin:0;font-size:23px;font-weight:700;line-height:1.3;">Νέο Παράπονο Εθελοντή</h1></div><div style="background:#fff;padding:36px 40px 40px;border-radius:0 0 12px 12px;box-shadow:0 4px 20px rgba(0,0,0,0.07);"><h2 style="color:#1f2937;font-size:18px;font-weight:700;margin:0 0 14px;">Γεια σας {{admin_name}},</h2><p style="color:#4b5563;line-height:1.65;font-size:15px;margin:0 0 14px;">Ο/Η εθελοντής <strong>{{volunteer_name}}</strong> υπέβαλε νέο παράπονο και χρειάζεται εξέταση.</p><div style="background:#f9fafb;border-left:4px solid #dc2626;padding:2px 20px;border-radius:0 8px 8px 0;margin:20px 0;"><div style="padding:7px 0;font-size:14px;border-bottom:1px solid #f3f4f6;"><span style="color:#9ca3af;display:inline-block;min-width:140px;">Εθελοντής:</span><span style="color:#111827;font-weight:600;">{{volunteer_name}}</span></div><div style="padding:7px 0;font-size:14px;border-bottom:1px solid #f3f4f6;"><span style="color:#9ca3af;display:inline-block;min-width:140px;">Θέμα:</span><span style="color:#111827;font-weight:600;">{{complaint_subject}}</span></div><div style="padding:7px 0;font-size:14px;border-bottom:1px solid #f3f4f6;"><span style="color:#9ca3af;display:inline-block;min-width:140px;">Κατηγορία:</span><span style="color:#111827;font-weight:600;">{{complaint_category}}</span></div><div style="padding:7px 0;font-size:14px;border-bottom:1px solid #f3f4f6;"><span style="color:#9ca3af;display:inline-block;min-width:140px;">Προτεραιότητα:</span><span style="color:#111827;font-weight:600;">{{complaint_priority}}</span></div><div style="padding:7px 0;font-size:14px;"><span style="color:#9ca3af;display:inline-block;min-width:140px;">Σχετική Αποστολή:</span><span style="color:#111827;font-weight:600;">{{mission_title}}</span></div></div><div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:16px 20px;margin:20px 0;"><p style="color:#6b7280;font-size:13px;font-weight:600;text-transform:uppercase;letter-spacing:1px;margin:0 0 8px;">Κείμενο Παραπόνου:</p><p style="color:#1f2937;font-size:14px;line-height:1.65;margin:0;">{{complaint_body}}</p></div><div style="text-align:center;margin:28px 0 4px;"><a href="{{complaint_url}}" style="background:#dc2626;color:#ffffff;text-decoration:none;padding:13px 38px;border-radius:8px;font-size:15px;font-weight:700;display:inline-block;letter-spacing:0.3px;">Δείτε το Παράπονο</a></div></div><div style="text-align:center;padding:18px 0 0;color:#9ca3af;font-size:12px;line-height:1.9;"><p style="margin:0;"><strong style="color:#6b7280;">{{app_name}}</strong> &bull; Σύστημα Διαχείρισης Εθελοντών</p><p style="margin:0;">Αυτό το μήνυμα στάλθηκε αυτόματα από το σύστημα.</p></div></div></div>',
'Αποστέλλεται στους διαχειριστές όταν υποβάλλεται νέο παράπονο εθελοντή',
'{{app_name}}, {{admin_name}}, {{volunteer_name}}, {{complaint_subject}}, {{complaint_category}}, {{complaint_priority}}, {{complaint_body}}, {{mission_title}}, {{complaint_url}}'),

('complaint_response', 'Απάντηση Παραπόνου', 'Απάντηση στο παράπονό σας: {{complaint_subject}}',
'<div style="background:#eef2f7;padding:28px 0 40px;font-family:Helvetica Neue,Arial,sans-serif;"><div style="max-width:600px;margin:0 auto;"><div style="background:#16a34a;padding:30px 40px 26px;border-radius:12px 12px 0 0;text-align:center;">{{logo_html}}<p style="color:rgba(255,255,255,0.7);font-size:11px;letter-spacing:2px;text-transform:uppercase;margin:0 0 8px;">{{app_name}}</p><div style="font-size:36px;line-height:1;margin:0 0 8px;">&#128172;</div><h1 style="color:#fff;margin:0;font-size:23px;font-weight:700;line-height:1.3;">Απάντηση στο Παράπονό σας</h1></div><div style="background:#fff;padding:36px 40px 40px;border-radius:0 0 12px 12px;box-shadow:0 4px 20px rgba(0,0,0,0.07);"><h2 style="color:#1f2937;font-size:18px;font-weight:700;margin:0 0 14px;">Γεια σας {{user_name}},</h2><p style="color:#4b5563;line-height:1.65;font-size:15px;margin:0 0 14px;">Η διοίκηση εξέτασε το παράπονό σας και σας στέλνει την παρακάτω απάντηση.</p><div style="background:#f9fafb;border-left:4px solid #16a34a;padding:2px 20px;border-radius:0 8px 8px 0;margin:20px 0;"><div style="padding:7px 0;font-size:14px;border-bottom:1px solid #f3f4f6;"><span style="color:#9ca3af;display:inline-block;min-width:140px;">Θέμα Παραπόνου:</span><span style="color:#111827;font-weight:600;">{{complaint_subject}}</span></div><div style="padding:7px 0;font-size:14px;border-bottom:1px solid #f3f4f6;"><span style="color:#9ca3af;display:inline-block;min-width:140px;">Νέα Κατάσταση:</span><span style="color:#111827;font-weight:600;">{{complaint_status}}</span></div><div style="padding:7px 0;font-size:14px;"><span style="color:#9ca3af;display:inline-block;min-width:140px;">Απάντηση από:</span><span style="color:#111827;font-weight:600;">{{responder_name}}</span></div></div><div style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:16px 20px;margin:20px 0;"><p style="color:#6b7280;font-size:13px;font-weight:600;text-transform:uppercase;letter-spacing:1px;margin:0 0 8px;">Απάντηση Διοίκησης:</p><p style="color:#1f2937;font-size:14px;line-height:1.65;margin:0;">{{admin_response}}</p></div><div style="text-align:center;margin:28px 0 4px;"><a href="{{complaint_url}}" style="background:#16a34a;color:#ffffff;text-decoration:none;padding:13px 38px;border-radius:8px;font-size:15px;font-weight:700;display:inline-block;letter-spacing:0.3px;">Δείτε το Παράπονο</a></div></div><div style="text-align:center;padding:18px 0 0;color:#9ca3af;font-size:12px;line-height:1.9;"><p style="margin:0;"><strong style="color:#6b7280;">{{app_name}}</strong> &bull; Σύστημα Διαχείρισης Εθελοντών</p><p style="margin:0;">Αυτό το μήνυμα στάλθηκε αυτόματα από το σύστημα.</p></div></div></div>',
'Αποστέλλεται στον εθελοντή όταν ο διαχειριστής απαντήσει στο παράπονό του',
'{{app_name}}, {{user_name}}, {{complaint_subject}}, {{complaint_status}}, {{responder_name}}, {{admin_response}}, {{complaint_url}}');

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
('certificate_expiry_reminder', 'Υπενθύμιση Λήξης Πιστοποιητικού', 'Όταν πλησιάζει η λήξη ενός πιστοποιητικού του εθελοντή', 1, (SELECT id FROM email_templates WHERE code = 'certificate_expiry_reminder')),
('task_assigned', 'Ανάθεση Εργασίας', 'Όταν ανατίθεται μια εργασία σε εθελοντή', 1, (SELECT id FROM email_templates WHERE code = 'task_assigned')),
('task_comment', 'Σχόλιο σε Εργασία', 'Όταν προστίθεται σχόλιο σε εργασία', 1, (SELECT id FROM email_templates WHERE code = 'task_comment')),
('task_deadline_reminder', 'Υπενθύμιση Προθεσμίας', 'Όταν πλησιάζει η προθεσμία εργασίας (24h πριν)', 1, (SELECT id FROM email_templates WHERE code = 'task_deadline_reminder')),
('task_status_changed', 'Αλλαγή Κατάστασης Εργασίας', 'Όταν αλλάζει η κατάσταση μιας εργασίας', 1, (SELECT id FROM email_templates WHERE code = 'task_status_changed')),
('task_subtask_completed', 'Ολοκλήρωση Υποεργασίας', 'Όταν ολοκληρώνεται μια υποεργασία', 1, (SELECT id FROM email_templates WHERE code = 'task_subtask_completed')),
('mission_needs_volunteers', 'Αποστολή Χρειάζεται Εθελοντές', 'Όταν μια αποστολή πλησιάζει και δεν έχει αρκετούς εθελοντές', 1, (SELECT id FROM email_templates WHERE code = 'mission_needs_volunteers')),
('shelf_expiry_reminder', 'Ειδοποίηση Λήξης Υλικών Ραφιού', 'Όταν υπάρχουν ληγμένα ή υπό λήξη υλικά ραφιού', 1, (SELECT id FROM email_templates WHERE code = 'shelf_expiry_reminder')),
('mission_reminder', 'Υπενθύμιση Αποστολής', 'Όταν στέλνεται υπενθύμιση για ανοιχτή αποστολή', 1, (SELECT id FROM email_templates WHERE code = 'mission_reminder')),
('complaint_submitted', 'Νέο Παράπονο (Admin)', 'Ειδοποίηση διαχειριστή όταν υποβάλλεται νέο παράπονο', 1, (SELECT id FROM email_templates WHERE code = 'complaint_submitted')),
('complaint_response', 'Απάντηση Παραπόνου', 'Ειδοποίηση εθελοντή όταν ο admin απαντήσει στο παράπονό του', 1, (SELECT id FROM email_templates WHERE code = 'complaint_response'));

INSERT IGNORE INTO `email_templates` (`code`, `name`, `subject`, `body_html`, `description`, `available_variables`) VALUES
('shift_swap_requested', 'Αίτημα Αντικατάστασης (προς αντικαταστάτη)', 'Ο {{requester_name}} σε προτείνει ως αντικαταστάτη - {{mission_title}}',
'<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
    <div style="background: #8e44ad; color: white; padding: 20px; text-align: center;">
        <h1>🔄 Αίτημα Αντικατάστασης</h1>
    </div>
    <div style="padding: 30px; background: #fff;">
        <h2>Γεια σας {{user_name}},</h2>
        <p>Ο/Η <strong>{{requester_name}}</strong> δεν μπορεί να παραστεί στη βάρδια και σας προτείνει ως αντικαταστάτη.</p>
        <div style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0;">
            <p><strong>Αποστολή:</strong> {{mission_title}}</p>
            <p><strong>Βάρδια:</strong> {{shift_date}} ({{shift_time}})</p>
            <p><strong>Τοποθεσία:</strong> {{location}}</p>
        </div>
        {{#message}}<div style="background: #ede7f6; padding: 15px; border-radius: 8px; margin: 10px 0; border-left: 4px solid #8e44ad;"><strong>Μήνυμα:</strong> {{message}}</div>{{/message}}
        <p>Μπορείτε να αποδεχτείτε ή να αρνηθείτε από <a href="{{action_url}}">εδώ</a>.</p>
    </div>
    <div style="padding: 15px; background: #f8f9fa; text-align: center; font-size: 12px; color: #666;">{{app_name}}</div>
</div>',
'Αποστέλλεται στον εθελοντή που προτείνεται ως αντικαταστάτης',
'{{app_name}}, {{user_name}}, {{requester_name}}, {{mission_title}}, {{shift_date}}, {{shift_time}}, {{location}}, {{message}}, {{action_url}}'),

('shift_swap_accepted', 'Αποδοχή Αντικατάστασης (προς αιτούντα)', 'Ο {{replacement_name}} αποδέχτηκε την αντικατάσταση - {{mission_title}}',
'<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
    <div style="background: #27ae60; color: white; padding: 20px; text-align: center;">
        <h1>✅ Αποδοχή Αντικατάστασης</h1>
    </div>
    <div style="padding: 30px; background: #fff;">
        <h2>Γεια σας {{user_name}},</h2>
        <p>Ο/Η <strong>{{replacement_name}}</strong> αποδέχτηκε να σας αντικαταστήσει στη βάρδια.</p>
        <div style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0;">
            <p><strong>Αποστολή:</strong> {{mission_title}}</p>
            <p><strong>Βάρδια:</strong> {{shift_date}} ({{shift_time}})</p>
        </div>
        <p>Το αίτημα αναμένει τελική έγκριση από τον διαχειριστή.</p>
    </div>
    <div style="padding: 15px; background: #f8f9fa; text-align: center; font-size: 12px; color: #666;">{{app_name}}</div>
</div>',
'Αποστέλλεται στον αιτούντα όταν ο αντικαταστάτης αποδεχτεί',
'{{app_name}}, {{user_name}}, {{replacement_name}}, {{mission_title}}, {{shift_date}}, {{shift_time}}'),

('shift_swap_approved', 'Έγκριση Αντικατάστασης (και στους δύο)', 'Η αντικατάσταση εγκρίθηκε - {{mission_title}}',
'<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
    <div style="background: #2980b9; color: white; padding: 20px; text-align: center;">
        <h1>✅ Αντικατάσταση Εγκρίθηκε</h1>
    </div>
    <div style="padding: 30px; background: #fff;">
        <h2>Γεια σας {{user_name}},</h2>
        <p>{{swap_message}}</p>
        <div style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0;">
            <p><strong>Αποστολή:</strong> {{mission_title}}</p>
            <p><strong>Βάρδια:</strong> {{shift_date}} ({{shift_time}})</p>
        </div>
    </div>
    <div style="padding: 15px; background: #f8f9fa; text-align: center; font-size: 12px; color: #666;">{{app_name}}</div>
</div>',
'Αποστέλλεται και στους δύο εθελοντές όταν ο admin εγκρίνει την αντικατάσταση',
'{{app_name}}, {{user_name}}, {{swap_message}}, {{mission_title}}, {{shift_date}}, {{shift_time}}');

INSERT IGNORE INTO `notification_settings` (`code`, `name`, `description`, `email_enabled`, `email_template_id`) VALUES
('shift_swap_requested', 'Αίτημα Αντικατάστασης', 'Όταν ένας εθελοντής ζητά αντικατάσταση από άλλον', 1, (SELECT id FROM email_templates WHERE code = 'shift_swap_requested')),
('shift_swap_accepted', 'Αποδοχή Αντικατάστασης', 'Όταν ο αντικαταστάτης αποδεχτεί το αίτημα', 1, (SELECT id FROM email_templates WHERE code = 'shift_swap_accepted')),
('shift_swap_approved', 'Έγκριση Αντικατάστασης', 'Όταν ο admin εγκρίνει την αντικατάσταση βάρδιας', 1, (SELECT id FROM email_templates WHERE code = 'shift_swap_approved'));

-- Default certificate types
INSERT INTO `certificate_types` (`name`, `description`, `default_validity_months`, `is_required`) VALUES
('Πρώτες Βοήθειες', 'Πιστοποίηση Πρώτων Βοηθειών (BLS)', 36, 1),
('BLS/AED', 'Βασική Υποστήριξη Ζωής & Αυτόματος Εξωτερικός Απινιδωτής', 36, 0),
('Δίπλωμα Οδήγησης', 'Άδεια οδήγησης αυτοκινήτου / μοτοσυκλέτας', NULL, 0),
('PHTLS', 'Prehospital Trauma Life Support', 48, 0);

-- Default certificate settings
INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
('certificate_reminder_days_first', '30'),
('certificate_reminder_days_urgent', '7'),
('shelf_expiry_reminder_days', '30')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

-- Default volunteer positions
INSERT INTO `volunteer_positions` (`id`, `name`, `color`, `icon`, `sort_order`) VALUES
(1, 'Υπεύθυνος Τμήματος',   'primary', 'bi-person-lines-fill', 1),
(2, 'Υπεύθυνος Γραμματείας','info',    'bi-envelope-paper',    2),
(3, 'Εκπαιδευτής',          'success', 'bi-mortarboard',       3),
(4, 'Ταμίας',               'warning', 'bi-cash-coin',         4);

-- Default inventory categories
INSERT INTO `inventory_categories` (`name`, `icon`, `color`, `sort_order`) VALUES
('Φαρμακεία', '💊', '#dc3545', 1),
('Ιατρικός Εξοπλισμός', '🏥', '#28a745', 2),
('Επικοινωνία', '📢', '#17a2b8', 3),
('Σκηνές & Εξοπλισμός', '⛺', '#ffc107', 4),
('Εκπαίδευση', '📚', '#6c757d', 5),
('Ασύρματοι', '📻', '#007bff', 6),
('Οχήματα', '🚑', '#e83e8c', 7),
('Γενικά', '📦', '#6c757d', 8)
ON DUPLICATE KEY UPDATE `sort_order` = VALUES(`sort_order`);

-- Default inventory locations
INSERT INTO `inventory_locations` (`name`, `location_type`, `notes`) VALUES
('Κεντρική Αποθήκη', 'warehouse', 'Κύρια αποθήκη υλικών'),
('Αποθήκη Οχημάτων', 'vehicle', 'Αποθήκη εντός οχημάτων'),
('Γραφείο', 'room', 'Γραφείο διοίκησης')
ON DUPLICATE KEY UPDATE `notes` = VALUES(`notes`);

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
    `questions_per_attempt` INT DEFAULT 10,
    `passing_percentage` INT DEFAULT 70,
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
    `selected_questions_json` JSON NULL COMMENT 'Array of question IDs that were randomly selected',
    `score` INT DEFAULT 0,
    `total_questions` INT DEFAULT 0,
    `passing_percentage` INT DEFAULT 70,
    `passed` TINYINT(1) DEFAULT 0,
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
    `selected_option` VARCHAR(10) NULL COMMENT 'A, B, C, D, T, or F',
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
    `progress` INT DEFAULT 0,
    `deadline` DATETIME,
    `created_by` INT UNSIGNED NOT NULL,
    `responsible_user_id` INT UNSIGNED NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `completed_at` DATETIME,
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT,
    FOREIGN KEY (`responsible_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
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
-- EMAIL LOGS TABLE
-- =============================================
CREATE TABLE IF NOT EXISTS `email_logs` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `recipient_email` VARCHAR(255) NOT NULL,
    `subject` VARCHAR(500) NOT NULL,
    `notification_code` VARCHAR(100) NULL,
    `status` ENUM('SUCCESS','FAILED') NOT NULL DEFAULT 'FAILED',
    `error_message` TEXT NULL,
    `smtp_log` TEXT NULL,
    `smtp_host` VARCHAR(255) NULL,
    `from_email` VARCHAR(255) NULL,
    `sent_by` INT UNSIGNED NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_email_logs_recipient` (`recipient_email`),
    INDEX `idx_email_logs_status` (`status`),
    INDEX `idx_email_logs_created` (`created_at`),
    INDEX `idx_email_logs_notification` (`notification_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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

-- =============================================
-- INVENTORY SYSTEM TABLES
-- =============================================

-- INVENTORY CATEGORIES
CREATE TABLE IF NOT EXISTS `inventory_categories` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL,
    `description` TEXT NULL,
    `icon` VARCHAR(10) DEFAULT '📦',
    `color` VARCHAR(7) DEFAULT '#6c757d',
    `sort_order` INT DEFAULT 0,
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_name` (`name`),
    INDEX `idx_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- INVENTORY LOCATIONS
CREATE TABLE IF NOT EXISTS `inventory_locations` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL,
    `department_id` INT UNSIGNED NULL,
    `location_type` ENUM('warehouse','vehicle','room','other') DEFAULT 'warehouse',
    `address` TEXT NULL,
    `capacity` INT NULL,
    `current_items_count` INT DEFAULT 0,
    `notes` TEXT NULL,
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`department_id`) REFERENCES `departments`(`id`) ON DELETE SET NULL,
    INDEX `idx_department` (`department_id`),
    INDEX `idx_type` (`location_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- INVENTORY ITEMS
CREATE TABLE IF NOT EXISTS `inventory_items` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `barcode` VARCHAR(50) NOT NULL,
    `name` VARCHAR(255) NOT NULL,
    `description` TEXT NULL,
    `category_id` INT UNSIGNED NULL,
    `department_id` INT UNSIGNED NULL,
    `location_id` INT UNSIGNED NULL,
    `location_notes` TEXT NULL,
    `status` ENUM('available','booked','maintenance','damaged') DEFAULT 'available',
    `condition_notes` TEXT NULL,
    `booked_by_user_id` INT UNSIGNED NULL,
    `booked_by_name` VARCHAR(255) NULL,
    `booking_date` DATETIME NULL,
    `expected_return_date` DATETIME NULL,
    `quantity` INT DEFAULT 1,
    `image_url` VARCHAR(500) NULL,
    `is_active` TINYINT(1) DEFAULT 1,
    `created_by` INT UNSIGNED NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`category_id`) REFERENCES `inventory_categories`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`department_id`) REFERENCES `departments`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`location_id`) REFERENCES `inventory_locations`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`booked_by_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    UNIQUE KEY `unique_barcode` (`barcode`),
    INDEX `idx_status` (`status`),
    INDEX `idx_department` (`department_id`),
    INDEX `idx_category` (`category_id`),
    INDEX `idx_location` (`location_id`),
    INDEX `idx_active` (`is_active`),
    INDEX `idx_dept_status` (`department_id`, `status`),
    FULLTEXT INDEX `idx_search` (`name`, `description`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- INVENTORY BOOKINGS
CREATE TABLE IF NOT EXISTS `inventory_bookings` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `item_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `volunteer_name` VARCHAR(255) NULL,
    `volunteer_phone` VARCHAR(20) NULL,
    `volunteer_email` VARCHAR(255) NULL,
    `mission_location` VARCHAR(500) NULL,
    `booking_type` ENUM('single','bulk') DEFAULT 'single',
    `expected_return_date` DATE NULL,
    `notes` TEXT NULL,
    `status` ENUM('active','overdue','returned','lost') DEFAULT 'active',
    `return_date` DATETIME NULL,
    `returned_by_user_id` INT UNSIGNED NULL,
    `return_notes` TEXT NULL,
    `actual_hours` DECIMAL(8,2) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`item_id`) REFERENCES `inventory_items`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`returned_by_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_status` (`status`),
    INDEX `idx_item` (`item_id`),
    INDEX `idx_user` (`user_id`),
    INDEX `idx_dates` (`created_at`, `return_date`),
    INDEX `idx_status_dates` (`status`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- INVENTORY NOTES
CREATE TABLE IF NOT EXISTS `inventory_notes` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `item_id` INT UNSIGNED NOT NULL,
    `item_name` VARCHAR(255) NULL,
    `note_type` ENUM('booking','return','maintenance','damage','general') DEFAULT 'general',
    `content` TEXT NOT NULL,
    `priority` ENUM('low','medium','high','urgent') DEFAULT 'medium',
    `status` ENUM('pending','acknowledged','in_progress','resolved','archived') DEFAULT 'pending',
    `status_history` JSON NULL,
    `related_booking_id` INT UNSIGNED NULL,
    `assigned_to_user_id` INT UNSIGNED NULL,
    `created_by_user_id` INT UNSIGNED NULL,
    `created_by_name` VARCHAR(255) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `resolved_at` DATETIME NULL,
    `resolved_by_user_id` INT UNSIGNED NULL,
    `resolution_notes` TEXT NULL,
    FOREIGN KEY (`item_id`) REFERENCES `inventory_items`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`related_booking_id`) REFERENCES `inventory_bookings`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`created_by_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`assigned_to_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`resolved_by_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_status` (`status`),
    INDEX `idx_priority` (`priority`),
    INDEX `idx_item` (`item_id`),
    INDEX `idx_type` (`note_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- INVENTORY FIXED ASSETS
CREATE TABLE IF NOT EXISTS `inventory_fixed_assets` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL,
    `barcode` VARCHAR(50) NULL,
    `description` TEXT NULL,
    `location` VARCHAR(255) NULL,
    `department_id` INT UNSIGNED NULL,
    `status` ENUM('available','checked_out','retired') DEFAULT 'available',
    `checked_out_to_user_id` INT UNSIGNED NULL,
    `checked_out_to_name` VARCHAR(255) NULL,
    `checked_out_phone` VARCHAR(20) NULL,
    `checked_out_at` DATETIME NULL,
    `checkout_notes` TEXT NULL,
    `purchase_date` DATE NULL,
    `purchase_cost` DECIMAL(10,2) NULL,
    `serial_number` VARCHAR(100) NULL,
    `condition_notes` TEXT NULL,
    `is_active` TINYINT(1) DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `unique_barcode` (`barcode`),
    FOREIGN KEY (`department_id`) REFERENCES `departments`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`checked_out_to_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_status` (`status`),
    INDEX `idx_department` (`department_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- INVENTORY DEPARTMENT ACCESS
CREATE TABLE IF NOT EXISTS `inventory_department_access` (
    `user_id` INT UNSIGNED NOT NULL,
    `department_id` INT UNSIGNED NOT NULL,
    `access_level` ENUM('viewer','manager','admin') DEFAULT 'viewer',
    `can_book` TINYINT(1) DEFAULT 1,
    `can_manage_items` TINYINT(1) DEFAULT 0,
    `can_approve_bookings` TINYINT(1) DEFAULT 0,
    `granted_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `granted_by_user_id` INT UNSIGNED NULL,
    PRIMARY KEY (`user_id`, `department_id`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`department_id`) REFERENCES `departments`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`granted_by_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_access_level` (`access_level`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- INVENTORY SHELF ITEMS (consumable items with expiry)
CREATE TABLE IF NOT EXISTS `inventory_shelf_items` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL,
    `quantity` INT NOT NULL DEFAULT 1,
    `shelf` VARCHAR(100) NULL,
    `expiry_date` DATE NULL,
    `notes` TEXT NULL,
    `department_id` INT UNSIGNED NULL,
    `sort_order` INT DEFAULT 0,
    `created_by` INT UNSIGNED NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`department_id`) REFERENCES `departments`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_expiry` (`expiry_date`),
    INDEX `idx_shelf` (`shelf`),
    INDEX `idx_department` (`department_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- INVENTORY KITS
CREATE TABLE IF NOT EXISTS `inventory_kits` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `barcode` VARCHAR(50) NOT NULL UNIQUE,
    `name` VARCHAR(255) NOT NULL,
    `description` TEXT NULL,
    `department_id` INT UNSIGNED NULL,
    `created_by` INT UNSIGNED NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`department_id`) REFERENCES `departments`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- INVENTORY KIT ITEMS (pivot)
CREATE TABLE IF NOT EXISTS `inventory_kit_items` (
    `kit_id` INT UNSIGNED NOT NULL,
    `item_id` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`kit_id`, `item_id`),
    FOREIGN KEY (`kit_id`) REFERENCES `inventory_kits`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`item_id`) REFERENCES `inventory_items`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- NEWSLETTER SYSTEM TABLES
-- =============================================

-- NEWSLETTERS
CREATE TABLE IF NOT EXISTS `newsletters` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `title` VARCHAR(255) NOT NULL,
    `subject` VARCHAR(255) NOT NULL,
    `body_html` MEDIUMTEXT NOT NULL,
    `status` ENUM('draft','sending','sent','failed') NOT NULL DEFAULT 'draft',
    `filter_roles` JSON NULL COMMENT 'Array of roles to send to, NULL = all',
    `filter_dept_id` INT UNSIGNED NULL COMMENT 'Limit to one department, NULL = all',
    `total_recipients` INT UNSIGNED NOT NULL DEFAULT 0,
    `sent_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `failed_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `created_by` INT UNSIGNED NOT NULL,
    `sent_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_newsletters_status` (`status`),
    INDEX `idx_newsletters_created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- NEWSLETTER SENDS
CREATE TABLE IF NOT EXISTS `newsletter_sends` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `newsletter_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NULL,
    `email` VARCHAR(255) NOT NULL,
    `name` VARCHAR(255) NOT NULL DEFAULT '',
    `status` ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
    `error_msg` TEXT NULL,
    `sent_at` TIMESTAMP NULL DEFAULT NULL,
    INDEX `idx_ns_newsletter_id` (`newsletter_id`),
    INDEX `idx_ns_user_id` (`user_id`),
    INDEX `idx_ns_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- NEWSLETTER UNSUBSCRIBES
CREATE TABLE IF NOT EXISTS `newsletter_unsubscribes` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NULL,
    `email` VARCHAR(255) NOT NULL,
    `token` VARCHAR(64) NOT NULL,
    `newsletter_id` INT UNSIGNED NULL COMMENT 'Campaign that triggered unsubscribe',
    `unsubscribed_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_nu_token` (`token`),
    INDEX `idx_nu_email` (`email`),
    INDEX `idx_nu_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- CITIZENS TABLE (Πολίτες)
-- =============================================
CREATE TABLE IF NOT EXISTS `citizens` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `first_name_gr` VARCHAR(100) NOT NULL COMMENT 'Όνομα (Ελληνικά)',
    `last_name_gr` VARCHAR(100) NOT NULL COMMENT 'Επίθετο (Ελληνικά)',
    `first_name_lat` VARCHAR(100) NULL COMMENT 'Όνομα (Λατινικά)',
    `last_name_lat` VARCHAR(100) NULL COMMENT 'Επίθετο (Λατινικά)',
    `birth_date` DATE NULL COMMENT 'Ημερομηνία γέννησης',
    `email` VARCHAR(255) NULL,
    `phone` VARCHAR(30) NULL,
    `contact_done` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Επικοινωνία',
    `contact_done_at` DATETIME NULL COMMENT 'Ημερομηνία επικοινωνίας',
    `payment_done` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Πληρωμή',
    `payment_done_at` DATETIME NULL COMMENT 'Ημερομηνία πληρωμής',
    `completed` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Έχει ολοκληρώσει',
    `completed_at` DATETIME NULL COMMENT 'Ημερομηνία ολοκλήρωσης',
    `notes` TEXT NULL,
    `created_by` INT UNSIGNED NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_citizens_name_gr` (`last_name_gr`, `first_name_gr`),
    INDEX `idx_citizens_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- CITIZEN CERTIFICATE TYPES TABLE (Τύποι Πιστοποιητικών)
-- =============================================
CREATE TABLE IF NOT EXISTS `citizen_certificate_types` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(150) NOT NULL,
    `description` TEXT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- CITIZEN CERTIFICATES TABLE (Πιστοποιητικά Πολιτών)
-- =============================================
CREATE TABLE IF NOT EXISTS `citizen_certificates` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `certificate_type_id` INT UNSIGNED NULL,
    `first_name` VARCHAR(100) NOT NULL COMMENT 'Όνομα',
    `last_name` VARCHAR(100) NOT NULL COMMENT 'Επίθετο',
    `phone` VARCHAR(30) NULL COMMENT 'Τηλέφωνο',
    `birth_date` DATE NULL COMMENT 'Ημερομηνία γέννησης',
    `issue_date` DATE NULL COMMENT 'Ημ. Έκδοσης',
    `expiry_date` DATE NULL COMMENT 'Ημ. Λήξης',
    `email` VARCHAR(255) NULL COMMENT 'Email πολίτη',
    `notes` TEXT NULL,
    `reminder_sent_3m` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Στάλθηκε υπενθύμιση 3 μηνών',
    `reminder_sent_1m` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Στάλθηκε υπενθύμιση 1 μήνα',
    `reminder_sent_1w` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Στάλθηκε υπενθύμιση 1 εβδομάδας',
    `reminder_sent_expired` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Στάλθηκε ειδοποίηση λήξης',
    `created_by` INT UNSIGNED NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_cc_type` (`certificate_type_id`),
    INDEX `idx_cc_name` (`last_name`, `first_name`),
    INDEX `idx_cc_expiry` (`expiry_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- COMPLAINTS TABLE (Παράπονα Εθελοντών)
-- =============================================
CREATE TABLE IF NOT EXISTS `complaints` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL COMMENT 'Ο εθελοντής που υπέβαλε',
    `mission_id` INT UNSIGNED NULL COMMENT 'Σχετική αποστολή (προαιρετικό)',
    `category` ENUM('MISSION','EQUIPMENT','BEHAVIOR','ADMIN','OTHER') NOT NULL DEFAULT 'OTHER' COMMENT 'Κατηγορία παραπόνου',
    `priority` ENUM('LOW','MEDIUM','HIGH') NOT NULL DEFAULT 'MEDIUM' COMMENT 'Προτεραιότητα',
    `subject` VARCHAR(255) NOT NULL COMMENT 'Θέμα',
    `body` TEXT NOT NULL COMMENT 'Κείμενο παραπόνου',
    `status` ENUM('NEW','IN_REVIEW','RESOLVED','REJECTED') NOT NULL DEFAULT 'NEW' COMMENT 'Κατάσταση',
    `admin_response` TEXT NULL COMMENT 'Απάντηση διαχειριστή',
    `responded_by` INT UNSIGNED NULL COMMENT 'Ποιος απάντησε',
    `responded_at` DATETIME NULL COMMENT 'Πότε απάντησε',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`mission_id`) REFERENCES `missions`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`responded_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_complaint_user` (`user_id`),
    INDEX `idx_complaint_status` (`status`),
    INDEX `idx_complaint_category` (`category`),
    INDEX `idx_complaint_mission` (`mission_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
