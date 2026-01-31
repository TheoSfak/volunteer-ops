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
    `department_id` INT UNSIGNED NULL,
    `is_active` TINYINT(1) DEFAULT 1,
    `total_points` INT DEFAULT 0,
    `monthly_points` INT DEFAULT 0,
    `email_verified_at` TIMESTAMP NULL,
    `remember_token` VARCHAR(100) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
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
-- MISSIONS TABLE
-- =============================================
CREATE TABLE IF NOT EXISTS `missions` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `department_id` INT UNSIGNED NULL,
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT NULL,
    `type` ENUM('VOLUNTEER', 'MEDICAL') DEFAULT 'VOLUNTEER',
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
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    FOREIGN KEY (`canceled_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_missions_status` (`status`),
    INDEX `idx_missions_start` (`start_datetime`)
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
    `icon` VARCHAR(50) DEFAULT 'ðŸ†',
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

SET FOREIGN_KEY_CHECKS = 1;

-- =============================================
-- DEFAULT DATA
-- =============================================

-- Default departments
INSERT INTO `departments` (`name`, `description`) VALUES
('Î“ÎµÎ½Î¹ÎºÏŒÏ‚ Î•Î¸ÎµÎ»Î¿Î½Ï„Î¹ÏƒÎ¼ÏŒÏ‚', 'Î“ÎµÎ½Î¹ÎºÎ­Ï‚ ÎµÎ¸ÎµÎ»Î¿Î½Ï„Î¹ÎºÎ­Ï‚ Î´ÏÎ¬ÏƒÎµÎ¹Ï‚'),
('Î¥Î³ÎµÎ¹Î¿Î½Î¿Î¼Î¹ÎºÎ® ÎšÎ¬Î»Ï…ÏˆÎ·', 'Î¥Î³ÎµÎ¹Î¿Î½Î¿Î¼Î¹ÎºÎ­Ï‚ Î±Ï€Î¿ÏƒÏ„Î¿Î»Î­Ï‚ ÎºÎ±Î¹ Ï€Î±ÏÎ¿Ï‡Î® Ï€ÏÏŽÏ„Ï‰Î½ Î²Î¿Î·Î¸ÎµÎ¹ÏŽÎ½'),
('Î ÎµÏÎ¹Î²Î±Î»Î»Î¿Î½Ï„Î¹ÎºÎ­Ï‚ Î”ÏÎ¬ÏƒÎµÎ¹Ï‚', 'Î”ÏÎ¬ÏƒÎµÎ¹Ï‚ Î³Î¹Î± Ï„Î¿ Ï€ÎµÏÎ¹Î²Î¬Î»Î»Î¿Î½'),
('ÎšÎ¿Î¹Î½Ï‰Î½Î¹ÎºÎ® Î‘Î»Î»Î·Î»ÎµÎ³Î³ÏÎ·', 'Î”ÏÎ¬ÏƒÎµÎ¹Ï‚ ÎºÎ¿Î¹Î½Ï‰Î½Î¹ÎºÎ®Ï‚ Î±Î»Î»Î·Î»ÎµÎ³Î³ÏÎ·Ï‚');

-- Default skills
INSERT INTO `skills` (`name`, `category`) VALUES
('Î ÏÏŽÏ„ÎµÏ‚ Î’Î¿Î®Î¸ÎµÎ¹ÎµÏ‚', 'Î¥Î³ÎµÎ¯Î±'),
('ÎÎ¿ÏƒÎ·Î»ÎµÏ…Ï„Î¹ÎºÎ®', 'Î¥Î³ÎµÎ¯Î±'),
('ÎŸÎ´Î®Î³Î·ÏƒÎ·', 'ÎœÎµÏ„Î±Ï†Î¿ÏÎ­Ï‚'),
('ÎŸÎ´Î®Î³Î·ÏƒÎ· ÎœÎ¿Ï„Î¿ÏƒÏ…ÎºÎ»Î­Ï„Î±Ï‚', 'ÎœÎµÏ„Î±Ï†Î¿ÏÎ­Ï‚'),
('ÎŸÏÎ³Î¬Î½Ï‰ÏƒÎ· Î•ÎºÎ´Î·Î»ÏŽÏƒÎµÏ‰Î½', 'Î”Î¹Î¿Î¯ÎºÎ·ÏƒÎ·'),
('Î”Î¹Î±Ï‡ÎµÎ¯ÏÎ¹ÏƒÎ· ÎšÏÎ¯ÏƒÎµÏ‰Î½', 'Î”Î¹Î¿Î¯ÎºÎ·ÏƒÎ·'),
('Î•Ï€Î¹ÎºÎ¿Î¹Î½Ï‰Î½Î¯Î±', 'Î“ÎµÎ½Î¹ÎºÎ¬'),
('ÎžÎ­Î½ÎµÏ‚ Î“Î»ÏŽÏƒÏƒÎµÏ‚', 'Î“ÎµÎ½Î¹ÎºÎ¬'),
('Î¦Ï‰Ï„Î¿Î³ÏÎ±Ï†Î¯Î±', 'Î¤ÎµÏ‡Î½Î¹ÎºÎ¬'),
('Î Î»Î·ÏÎ¿Ï†Î¿ÏÎ¹ÎºÎ®', 'Î¤ÎµÏ‡Î½Î¹ÎºÎ¬');

-- Default achievements
INSERT INTO `achievements` (`code`, `name`, `description`, `category`, `icon`, `required_points`, `threshold`) VALUES
('first_shift', 'Î ÏÏŽÏ„Î· Î’Î¬ÏÎ´Î¹Î±', 'ÎŸÎ»Î¿ÎºÎ»Î®ÏÏ‰ÏƒÎµ Ï„Î·Î½ Ï€ÏÏŽÏ„Î· ÏƒÎ¿Ï… Î²Î¬ÏÎ´Î¹Î±', 'milestone', 'â­', 0, 1),
('shifts_5', '5 Î’Î¬ÏÎ´Î¹ÎµÏ‚', 'ÎŸÎ»Î¿ÎºÎ»Î®ÏÏ‰ÏƒÎµ 5 Î²Î¬ÏÎ´Î¹ÎµÏ‚', 'shifts', 'ðŸ“…', 0, 5),
('shifts_10', '10 Î’Î¬ÏÎ´Î¹ÎµÏ‚', 'ÎŸÎ»Î¿ÎºÎ»Î®ÏÏ‰ÏƒÎµ 10 Î²Î¬ÏÎ´Î¹ÎµÏ‚', 'shifts', 'ðŸ“…', 0, 10),
('shifts_25', '25 Î’Î¬ÏÎ´Î¹ÎµÏ‚', 'ÎŸÎ»Î¿ÎºÎ»Î®ÏÏ‰ÏƒÎµ 25 Î²Î¬ÏÎ´Î¹ÎµÏ‚', 'shifts', 'ðŸ“…', 0, 25),
('shifts_50', '50 Î’Î¬ÏÎ´Î¹ÎµÏ‚', 'ÎŸÎ»Î¿ÎºÎ»Î®ÏÏ‰ÏƒÎµ 50 Î²Î¬ÏÎ´Î¹ÎµÏ‚', 'shifts', 'ðŸŽ¯', 0, 50),
('hours_10', '10 ÎÏÎµÏ‚', 'Î£Ï…Î¼Ï€Î»Î®ÏÏ‰ÏƒÎµ 10 ÏŽÏÎµÏ‚ ÎµÎ¸ÎµÎ»Î¿Î½Ï„Î¹ÏƒÎ¼Î¿Ï', 'hours', 'â°', 0, 10),
('hours_50', '50 ÎÏÎµÏ‚', 'Î£Ï…Î¼Ï€Î»Î®ÏÏ‰ÏƒÎµ 50 ÏŽÏÎµÏ‚ ÎµÎ¸ÎµÎ»Î¿Î½Ï„Î¹ÏƒÎ¼Î¿Ï', 'hours', 'â°', 0, 50),
('hours_100', '100 ÎÏÎµÏ‚', 'Î£Ï…Î¼Ï€Î»Î®ÏÏ‰ÏƒÎµ 100 ÏŽÏÎµÏ‚ ÎµÎ¸ÎµÎ»Î¿Î½Ï„Î¹ÏƒÎ¼Î¿Ï', 'hours', 'â°', 0, 100),
('hours_250', '250 ÎÏÎµÏ‚', 'Î£Ï…Î¼Ï€Î»Î®ÏÏ‰ÏƒÎµ 250 ÏŽÏÎµÏ‚ ÎµÎ¸ÎµÎ»Î¿Î½Ï„Î¹ÏƒÎ¼Î¿Ï', 'hours', 'ðŸ†', 0, 250),
('weekend_warrior', 'Î Î¿Î»ÎµÎ¼Î¹ÏƒÏ„Î®Ï‚ Î£Î±Î²Î²Î±Ï„Î¿ÎºÏÏÎ¹Î±ÎºÎ¿Ï…', 'ÎŸÎ»Î¿ÎºÎ»Î®ÏÏ‰ÏƒÎµ 10 Î²Î¬ÏÎ´Î¹ÎµÏ‚ Î£Î±Î²Î²Î±Ï„Î¿ÎºÏÏÎ¹Î±ÎºÎ¿Ï…', 'special', 'â˜€ï¸', 100, 10),
('night_owl', 'ÎÏ…Ï‡Ï„Î¿Ï€Î¿ÏÎ»Î¹', 'ÎŸÎ»Î¿ÎºÎ»Î®ÏÏ‰ÏƒÎµ 10 Î½Ï…Ï‡Ï„ÎµÏÎ¹Î½Î­Ï‚ Î²Î¬ÏÎ´Î¹ÎµÏ‚', 'special', 'ðŸŒ™', 100, 10),
('medical_hero', 'Î‰ÏÏ‰Î±Ï‚ Î¥Î³ÎµÎ¯Î±Ï‚', 'ÎŸÎ»Î¿ÎºÎ»Î®ÏÏ‰ÏƒÎµ 10 Ï…Î³ÎµÎ¹Î¿Î½Î¿Î¼Î¹ÎºÎ­Ï‚ Î±Ï€Î¿ÏƒÏ„Î¿Î»Î­Ï‚', 'special', 'â¤ï¸', 200, 10),
('points_100', '100 Î ÏŒÎ½Ï„Î¿Î¹', 'Î£Ï…Î³ÎºÎ­Î½Ï„ÏÏ‰ÏƒÎµ 100 Ï€ÏŒÎ½Ï„Î¿Ï…Ï‚', 'points', 'ðŸ’¯', 100, 0),
('points_500', '500 Î ÏŒÎ½Ï„Î¿Î¹', 'Î£Ï…Î³ÎºÎ­Î½Ï„ÏÏ‰ÏƒÎµ 500 Ï€ÏŒÎ½Ï„Î¿Ï…Ï‚', 'points', 'ðŸŒŸ', 500, 0),
('points_1000', '1000 Î ÏŒÎ½Ï„Î¿Î¹', 'Î£Ï…Î³ÎºÎ­Î½Ï„ÏÏ‰ÏƒÎµ 1000 Ï€ÏŒÎ½Ï„Î¿Ï…Ï‚', 'points', 'ðŸ‘‘', 1000, 0);

-- Default admin user (password: admin123)
INSERT INTO `users` (`name`, `email`, `password`, `role`, `is_active`) VALUES
('Î”Î¹Î±Ï‡ÎµÎ¹ÏÎ¹ÏƒÏ„Î®Ï‚', 'admin@volunteerops.gr', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'SYSTEM_ADMIN', 1);

-- Default settings
INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
('app_name', 'VolunteerOps'),
('app_description', 'Î£ÏÏƒÏ„Î·Î¼Î± Î”Î¹Î±Ï‡ÎµÎ¯ÏÎ¹ÏƒÎ·Ï‚ Î•Î¸ÎµÎ»Î¿Î½Ï„ÏŽÎ½'),
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

-- Email templates will be inserted programmatically by install.php

-- Default notification settings
INSERT INTO `notification_settings` (`code`, `name`, `description`, `email_enabled`, `email_template_id`) VALUES
('new_mission', 'ÎÎ­Î± Î‘Ï€Î¿ÏƒÏ„Î¿Î»Î®', 'ÎŒÏ„Î±Î½ Î´Î·Î¼Î¿ÏƒÎ¹ÎµÏÎµÏ„Î±Î¹ Î½Î­Î± Î±Ï€Î¿ÏƒÏ„Î¿Î»Î®', 1, (SELECT id FROM email_templates WHERE code = 'new_mission')),
('participation_approved', 'ÎˆÎ³ÎºÏÎ¹ÏƒÎ· Î£Ï…Î¼Î¼ÎµÏ„Î¿Ï‡Î®Ï‚', 'ÎŒÏ„Î±Î½ ÎµÎ³ÎºÏÎ¯Î½ÎµÏ„Î±Î¹ Î· ÏƒÏ…Î¼Î¼ÎµÏ„Î¿Ï‡Î® ÎµÎ¸ÎµÎ»Î¿Î½Ï„Î® ÏƒÎµ Î²Î¬ÏÎ´Î¹Î±', 1, (SELECT id FROM email_templates WHERE code = 'participation_approved')),
('participation_rejected', 'Î‘Ï€ÏŒÏÏÎ¹ÏˆÎ· Î£Ï…Î¼Î¼ÎµÏ„Î¿Ï‡Î®Ï‚', 'ÎŒÏ„Î±Î½ Î±Ï€Î¿ÏÏÎ¯Ï€Ï„ÎµÏ„Î±Î¹ Î· ÏƒÏ…Î¼Î¼ÎµÏ„Î¿Ï‡Î® ÎµÎ¸ÎµÎ»Î¿Î½Ï„Î®', 1, (SELECT id FROM email_templates WHERE code = 'participation_rejected')),
('shift_reminder', 'Î¥Ï€ÎµÎ½Î¸ÏÎ¼Î¹ÏƒÎ· Î’Î¬ÏÎ´Î¹Î±Ï‚', 'ÎœÎ¯Î± Î¼Î­ÏÎ± Ï€ÏÎ¹Î½ Ï„Î· Î²Î¬ÏÎ´Î¹Î±', 1, (SELECT id FROM email_templates WHERE code = 'shift_reminder')),
('mission_canceled', 'Î‘ÎºÏÏÏ‰ÏƒÎ· Î‘Ï€Î¿ÏƒÏ„Î¿Î»Î®Ï‚', 'ÎŒÏ„Î±Î½ Î±ÎºÏ…ÏÏŽÎ½ÎµÏ„Î±Î¹ Î±Ï€Î¿ÏƒÏ„Î¿Î»Î®', 1, (SELECT id FROM email_templates WHERE code = 'mission_canceled')),
('shift_canceled', 'Î‘ÎºÏÏÏ‰ÏƒÎ· Î’Î¬ÏÎ´Î¹Î±Ï‚', 'ÎŒÏ„Î±Î½ Î±ÎºÏ…ÏÏŽÎ½ÎµÏ„Î±Î¹ Î²Î¬ÏÎ´Î¹Î±', 1, (SELECT id FROM email_templates WHERE code = 'shift_canceled')),
('points_earned', 'ÎšÎ­ÏÎ´Î¿Ï‚ Î ÏŒÎ½Ï„Ï‰Î½', 'ÎŒÏ„Î±Î½ Î¿ ÎµÎ¸ÎµÎ»Î¿Î½Ï„Î®Ï‚ ÎºÎµÏÎ´Î¯Î¶ÎµÎ¹ Ï€ÏŒÎ½Ï„Î¿Ï…Ï‚', 0, (SELECT id FROM email_templates WHERE code = 'points_earned')),
('welcome', 'ÎšÎ±Î»Ï‰ÏƒÏŒÏÎ¹ÏƒÎ¼Î±', 'ÎœÎµÏ„Î¬ Ï„Î·Î½ ÎµÎ³Î³ÏÎ±Ï†Î® Î½Î­Î¿Ï… Ï‡ÏÎ®ÏƒÏ„Î·', 1, (SELECT id FROM email_templates WHERE code = 'welcome'));

