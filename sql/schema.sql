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
    `icon` VARCHAR(50) DEFAULT 'ÃƒÂ°Ã…Â¸Ã‚ÂÃ¢â‚¬Â ',
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
('ÃƒÅ½Ã¢â‚¬Å“ÃƒÅ½Ã‚ÂµÃƒÅ½Ã‚Â½ÃƒÅ½Ã‚Â¹ÃƒÅ½Ã‚ÂºÃƒÂÃ…â€™ÃƒÂÃ¢â‚¬Å¡ ÃƒÅ½Ã¢â‚¬Â¢ÃƒÅ½Ã‚Â¸ÃƒÅ½Ã‚ÂµÃƒÅ½Ã‚Â»ÃƒÅ½Ã‚Â¿ÃƒÅ½Ã‚Â½ÃƒÂÃ¢â‚¬Å¾ÃƒÅ½Ã‚Â¹ÃƒÂÃ†â€™ÃƒÅ½Ã‚Â¼ÃƒÂÃ…â€™ÃƒÂÃ¢â‚¬Å¡', 'ÃƒÅ½Ã¢â‚¬Å“ÃƒÅ½Ã‚ÂµÃƒÅ½Ã‚Â½ÃƒÅ½Ã‚Â¹ÃƒÅ½Ã‚ÂºÃƒÅ½Ã‚Â­ÃƒÂÃ¢â‚¬Å¡ ÃƒÅ½Ã‚ÂµÃƒÅ½Ã‚Â¸ÃƒÅ½Ã‚ÂµÃƒÅ½Ã‚Â»ÃƒÅ½Ã‚Â¿ÃƒÅ½Ã‚Â½ÃƒÂÃ¢â‚¬Å¾ÃƒÅ½Ã‚Â¹ÃƒÅ½Ã‚ÂºÃƒÅ½Ã‚Â­ÃƒÂÃ¢â‚¬Å¡ ÃƒÅ½Ã‚Â´ÃƒÂÃ‚ÂÃƒÅ½Ã‚Â¬ÃƒÂÃ†â€™ÃƒÅ½Ã‚ÂµÃƒÅ½Ã‚Â¹ÃƒÂÃ¢â‚¬Å¡'),
('ÃƒÅ½Ã‚Â¥ÃƒÅ½Ã‚Â³ÃƒÅ½Ã‚ÂµÃƒÅ½Ã‚Â¹ÃƒÅ½Ã‚Â¿ÃƒÅ½Ã‚Â½ÃƒÅ½Ã‚Â¿ÃƒÅ½Ã‚Â¼ÃƒÅ½Ã‚Â¹ÃƒÅ½Ã‚ÂºÃƒÅ½Ã‚Â® ÃƒÅ½Ã…Â¡ÃƒÅ½Ã‚Â¬ÃƒÅ½Ã‚Â»ÃƒÂÃ¢â‚¬Â¦ÃƒÂÃ‹â€ ÃƒÅ½Ã‚Â·', 'ÃƒÅ½Ã‚Â¥ÃƒÅ½Ã‚Â³ÃƒÅ½Ã‚ÂµÃƒÅ½Ã‚Â¹ÃƒÅ½Ã‚Â¿ÃƒÅ½Ã‚Â½ÃƒÅ½Ã‚Â¿ÃƒÅ½Ã‚Â¼ÃƒÅ½Ã‚Â¹ÃƒÅ½Ã‚ÂºÃƒÅ½Ã‚Â­ÃƒÂÃ¢â‚¬Å¡ ÃƒÅ½Ã‚Â±ÃƒÂÃ¢â€šÂ¬ÃƒÅ½Ã‚Â¿ÃƒÂÃ†â€™ÃƒÂÃ¢â‚¬Å¾ÃƒÅ½Ã‚Â¿ÃƒÅ½Ã‚Â»ÃƒÅ½Ã‚Â­ÃƒÂÃ¢â‚¬Å¡ ÃƒÅ½Ã‚ÂºÃƒÅ½Ã‚Â±ÃƒÅ½Ã‚Â¹ ÃƒÂÃ¢â€šÂ¬ÃƒÅ½Ã‚Â±ÃƒÂÃ‚ÂÃƒÅ½Ã‚Â¿ÃƒÂÃ¢â‚¬Â¡ÃƒÅ½Ã‚Â® ÃƒÂÃ¢â€šÂ¬ÃƒÂÃ‚ÂÃƒÂÃ…Â½ÃƒÂÃ¢â‚¬Å¾ÃƒÂÃ¢â‚¬Â°ÃƒÅ½Ã‚Â½ ÃƒÅ½Ã‚Â²ÃƒÅ½Ã‚Â¿ÃƒÅ½Ã‚Â·ÃƒÅ½Ã‚Â¸ÃƒÅ½Ã‚ÂµÃƒÅ½Ã‚Â¹ÃƒÂÃ…Â½ÃƒÅ½Ã‚Â½'),
('ÃƒÅ½Ã‚Â ÃƒÅ½Ã‚ÂµÃƒÂÃ‚ÂÃƒÅ½Ã‚Â¹ÃƒÅ½Ã‚Â²ÃƒÅ½Ã‚Â±ÃƒÅ½Ã‚Â»ÃƒÅ½Ã‚Â»ÃƒÅ½Ã‚Â¿ÃƒÅ½Ã‚Â½ÃƒÂÃ¢â‚¬Å¾ÃƒÅ½Ã‚Â¹ÃƒÅ½Ã‚ÂºÃƒÅ½Ã‚Â­ÃƒÂÃ¢â‚¬Å¡ ÃƒÅ½Ã¢â‚¬ÂÃƒÂÃ‚ÂÃƒÅ½Ã‚Â¬ÃƒÂÃ†â€™ÃƒÅ½Ã‚ÂµÃƒÅ½Ã‚Â¹ÃƒÂÃ¢â‚¬Å¡', 'ÃƒÅ½Ã¢â‚¬ÂÃƒÂÃ‚ÂÃƒÅ½Ã‚Â¬ÃƒÂÃ†â€™ÃƒÅ½Ã‚ÂµÃƒÅ½Ã‚Â¹ÃƒÂÃ¢â‚¬Å¡ ÃƒÅ½Ã‚Â³ÃƒÅ½Ã‚Â¹ÃƒÅ½Ã‚Â± ÃƒÂÃ¢â‚¬Å¾ÃƒÅ½Ã‚Â¿ ÃƒÂÃ¢â€šÂ¬ÃƒÅ½Ã‚ÂµÃƒÂÃ‚ÂÃƒÅ½Ã‚Â¹ÃƒÅ½Ã‚Â²ÃƒÅ½Ã‚Â¬ÃƒÅ½Ã‚Â»ÃƒÅ½Ã‚Â»ÃƒÅ½Ã‚Â¿ÃƒÅ½Ã‚Â½'),
('ÃƒÅ½Ã…Â¡ÃƒÅ½Ã‚Â¿ÃƒÅ½Ã‚Â¹ÃƒÅ½Ã‚Â½ÃƒÂÃ¢â‚¬Â°ÃƒÅ½Ã‚Â½ÃƒÅ½Ã‚Â¹ÃƒÅ½Ã‚ÂºÃƒÅ½Ã‚Â® ÃƒÅ½Ã¢â‚¬ËœÃƒÅ½Ã‚Â»ÃƒÅ½Ã‚Â»ÃƒÅ½Ã‚Â·ÃƒÅ½Ã‚Â»ÃƒÅ½Ã‚ÂµÃƒÅ½Ã‚Â³ÃƒÅ½Ã‚Â³ÃƒÂÃ‚ÂÃƒÅ½Ã‚Â·', 'ÃƒÅ½Ã¢â‚¬ÂÃƒÂÃ‚ÂÃƒÅ½Ã‚Â¬ÃƒÂÃ†â€™ÃƒÅ½Ã‚ÂµÃƒÅ½Ã‚Â¹ÃƒÂÃ¢â‚¬Å¡ ÃƒÅ½Ã‚ÂºÃƒÅ½Ã‚Â¿ÃƒÅ½Ã‚Â¹ÃƒÅ½Ã‚Â½ÃƒÂÃ¢â‚¬Â°ÃƒÅ½Ã‚Â½ÃƒÅ½Ã‚Â¹ÃƒÅ½Ã‚ÂºÃƒÅ½Ã‚Â®ÃƒÂÃ¢â‚¬Å¡ ÃƒÅ½Ã‚Â±ÃƒÅ½Ã‚Â»ÃƒÅ½Ã‚Â»ÃƒÅ½Ã‚Â·ÃƒÅ½Ã‚Â»ÃƒÅ½Ã‚ÂµÃƒÅ½Ã‚Â³ÃƒÅ½Ã‚Â³ÃƒÂÃ‚ÂÃƒÅ½Ã‚Â·ÃƒÂÃ¢â‚¬Å¡');

-- Default skills
INSERT INTO `skills` (`name`, `category`) VALUES
('ÃƒÅ½Ã‚Â ÃƒÂÃ‚ÂÃƒÂÃ…Â½ÃƒÂÃ¢â‚¬Å¾ÃƒÅ½Ã‚ÂµÃƒÂÃ¢â‚¬Å¡ ÃƒÅ½Ã¢â‚¬â„¢ÃƒÅ½Ã‚Â¿ÃƒÅ½Ã‚Â®ÃƒÅ½Ã‚Â¸ÃƒÅ½Ã‚ÂµÃƒÅ½Ã‚Â¹ÃƒÅ½Ã‚ÂµÃƒÂÃ¢â‚¬Å¡', 'ÃƒÅ½Ã‚Â¥ÃƒÅ½Ã‚Â³ÃƒÅ½Ã‚ÂµÃƒÅ½Ã‚Â¯ÃƒÅ½Ã‚Â±'),
('ÃƒÅ½Ã‚ÂÃƒÅ½Ã‚Â¿ÃƒÂÃ†â€™ÃƒÅ½Ã‚Â·ÃƒÅ½Ã‚Â»ÃƒÅ½Ã‚ÂµÃƒÂÃ¢â‚¬Â¦ÃƒÂÃ¢â‚¬Å¾ÃƒÅ½Ã‚Â¹ÃƒÅ½Ã‚ÂºÃƒÅ½Ã‚Â®', 'ÃƒÅ½Ã‚Â¥ÃƒÅ½Ã‚Â³ÃƒÅ½Ã‚ÂµÃƒÅ½Ã‚Â¯ÃƒÅ½Ã‚Â±'),
('ÃƒÅ½Ã…Â¸ÃƒÅ½Ã‚Â´ÃƒÅ½Ã‚Â®ÃƒÅ½Ã‚Â³ÃƒÅ½Ã‚Â·ÃƒÂÃ†â€™ÃƒÅ½Ã‚Â·', 'ÃƒÅ½Ã…â€œÃƒÅ½Ã‚ÂµÃƒÂÃ¢â‚¬Å¾ÃƒÅ½Ã‚Â±ÃƒÂÃ¢â‚¬Â ÃƒÅ½Ã‚Â¿ÃƒÂÃ‚ÂÃƒÅ½Ã‚Â­ÃƒÂÃ¢â‚¬Å¡'),
('ÃƒÅ½Ã…Â¸ÃƒÅ½Ã‚Â´ÃƒÅ½Ã‚Â®ÃƒÅ½Ã‚Â³ÃƒÅ½Ã‚Â·ÃƒÂÃ†â€™ÃƒÅ½Ã‚Â· ÃƒÅ½Ã…â€œÃƒÅ½Ã‚Â¿ÃƒÂÃ¢â‚¬Å¾ÃƒÅ½Ã‚Â¿ÃƒÂÃ†â€™ÃƒÂÃ¢â‚¬Â¦ÃƒÅ½Ã‚ÂºÃƒÅ½Ã‚Â»ÃƒÅ½Ã‚Â­ÃƒÂÃ¢â‚¬Å¾ÃƒÅ½Ã‚Â±ÃƒÂÃ¢â‚¬Å¡', 'ÃƒÅ½Ã…â€œÃƒÅ½Ã‚ÂµÃƒÂÃ¢â‚¬Å¾ÃƒÅ½Ã‚Â±ÃƒÂÃ¢â‚¬Â ÃƒÅ½Ã‚Â¿ÃƒÂÃ‚ÂÃƒÅ½Ã‚Â­ÃƒÂÃ¢â‚¬Å¡'),
('ÃƒÅ½Ã…Â¸ÃƒÂÃ‚ÂÃƒÅ½Ã‚Â³ÃƒÅ½Ã‚Â¬ÃƒÅ½Ã‚Â½ÃƒÂÃ¢â‚¬Â°ÃƒÂÃ†â€™ÃƒÅ½Ã‚Â· ÃƒÅ½Ã¢â‚¬Â¢ÃƒÅ½Ã‚ÂºÃƒÅ½Ã‚Â´ÃƒÅ½Ã‚Â·ÃƒÅ½Ã‚Â»ÃƒÂÃ…Â½ÃƒÂÃ†â€™ÃƒÅ½Ã‚ÂµÃƒÂÃ¢â‚¬Â°ÃƒÅ½Ã‚Â½', 'ÃƒÅ½Ã¢â‚¬ÂÃƒÅ½Ã‚Â¹ÃƒÅ½Ã‚Â¿ÃƒÅ½Ã‚Â¯ÃƒÅ½Ã‚ÂºÃƒÅ½Ã‚Â·ÃƒÂÃ†â€™ÃƒÅ½Ã‚Â·'),
('ÃƒÅ½Ã¢â‚¬ÂÃƒÅ½Ã‚Â¹ÃƒÅ½Ã‚Â±ÃƒÂÃ¢â‚¬Â¡ÃƒÅ½Ã‚ÂµÃƒÅ½Ã‚Â¯ÃƒÂÃ‚ÂÃƒÅ½Ã‚Â¹ÃƒÂÃ†â€™ÃƒÅ½Ã‚Â· ÃƒÅ½Ã…Â¡ÃƒÂÃ‚ÂÃƒÅ½Ã‚Â¯ÃƒÂÃ†â€™ÃƒÅ½Ã‚ÂµÃƒÂÃ¢â‚¬Â°ÃƒÅ½Ã‚Â½', 'ÃƒÅ½Ã¢â‚¬ÂÃƒÅ½Ã‚Â¹ÃƒÅ½Ã‚Â¿ÃƒÅ½Ã‚Â¯ÃƒÅ½Ã‚ÂºÃƒÅ½Ã‚Â·ÃƒÂÃ†â€™ÃƒÅ½Ã‚Â·'),
('ÃƒÅ½Ã¢â‚¬Â¢ÃƒÂÃ¢â€šÂ¬ÃƒÅ½Ã‚Â¹ÃƒÅ½Ã‚ÂºÃƒÅ½Ã‚Â¿ÃƒÅ½Ã‚Â¹ÃƒÅ½Ã‚Â½ÃƒÂÃ¢â‚¬Â°ÃƒÅ½Ã‚Â½ÃƒÅ½Ã‚Â¯ÃƒÅ½Ã‚Â±', 'ÃƒÅ½Ã¢â‚¬Å“ÃƒÅ½Ã‚ÂµÃƒÅ½Ã‚Â½ÃƒÅ½Ã‚Â¹ÃƒÅ½Ã‚ÂºÃƒÅ½Ã‚Â¬'),
('ÃƒÅ½Ã…Â¾ÃƒÅ½Ã‚Â­ÃƒÅ½Ã‚Â½ÃƒÅ½Ã‚ÂµÃƒÂÃ¢â‚¬Å¡ ÃƒÅ½Ã¢â‚¬Å“ÃƒÅ½Ã‚Â»ÃƒÂÃ…Â½ÃƒÂÃ†â€™ÃƒÂÃ†â€™ÃƒÅ½Ã‚ÂµÃƒÂÃ¢â‚¬Å¡', 'ÃƒÅ½Ã¢â‚¬Å“ÃƒÅ½Ã‚ÂµÃƒÅ½Ã‚Â½ÃƒÅ½Ã‚Â¹ÃƒÅ½Ã‚ÂºÃƒÅ½Ã‚Â¬'),
('ÃƒÅ½Ã‚Â¦ÃƒÂÃ¢â‚¬Â°ÃƒÂÃ¢â‚¬Å¾ÃƒÅ½Ã‚Â¿ÃƒÅ½Ã‚Â³ÃƒÂÃ‚ÂÃƒÅ½Ã‚Â±ÃƒÂÃ¢â‚¬Â ÃƒÅ½Ã‚Â¯ÃƒÅ½Ã‚Â±', 'ÃƒÅ½Ã‚Â¤ÃƒÅ½Ã‚ÂµÃƒÂÃ¢â‚¬Â¡ÃƒÅ½Ã‚Â½ÃƒÅ½Ã‚Â¹ÃƒÅ½Ã‚ÂºÃƒÅ½Ã‚Â¬'),
('ÃƒÅ½Ã‚Â ÃƒÅ½Ã‚Â»ÃƒÅ½Ã‚Â·ÃƒÂÃ‚ÂÃƒÅ½Ã‚Â¿ÃƒÂÃ¢â‚¬Â ÃƒÅ½Ã‚Â¿ÃƒÂÃ‚ÂÃƒÅ½Ã‚Â¹ÃƒÅ½Ã‚ÂºÃƒÅ½Ã‚Â®', 'ÃƒÅ½Ã‚Â¤ÃƒÅ½Ã‚ÂµÃƒÂÃ¢â‚¬Â¡ÃƒÅ½Ã‚Â½ÃƒÅ½Ã‚Â¹ÃƒÅ½Ã‚ÂºÃƒÅ½Ã‚Â¬');

-- Default achievements
INSERT INTO `achievements` (`code`, `name`, `description`, `category`, `icon`, `required_points`, `threshold`) VALUES
('first_shift', 'ÃƒÅ½Ã‚Â ÃƒÂÃ‚ÂÃƒÂÃ…Â½ÃƒÂÃ¢â‚¬Å¾ÃƒÅ½Ã‚Â· ÃƒÅ½Ã¢â‚¬â„¢ÃƒÅ½Ã‚Â¬ÃƒÂÃ‚ÂÃƒÅ½Ã‚Â´ÃƒÅ½Ã‚Â¹ÃƒÅ½Ã‚Â±', 'ÃƒÅ½Ã…Â¸ÃƒÅ½Ã‚Â»ÃƒÅ½Ã‚Â¿ÃƒÅ½Ã‚ÂºÃƒÅ½Ã‚Â»ÃƒÅ½Ã‚Â®ÃƒÂÃ‚ÂÃƒÂÃ¢â‚¬Â°ÃƒÂÃ†â€™ÃƒÅ½Ã‚Âµ ÃƒÂÃ¢â‚¬Å¾ÃƒÅ½Ã‚Â·ÃƒÅ½Ã‚Â½ ÃƒÂÃ¢â€šÂ¬ÃƒÂÃ‚ÂÃƒÂÃ…Â½ÃƒÂÃ¢â‚¬Å¾ÃƒÅ½Ã‚Â· ÃƒÂÃ†â€™ÃƒÅ½Ã‚Â¿ÃƒÂÃ¢â‚¬Â¦ ÃƒÅ½Ã‚Â²ÃƒÅ½Ã‚Â¬ÃƒÂÃ‚ÂÃƒÅ½Ã‚Â´ÃƒÅ½Ã‚Â¹ÃƒÅ½Ã‚Â±', 'milestone', 'ÃƒÂ¢Ã‚Â­Ã‚Â', 0, 1),
('shifts_5', '5 ÃƒÅ½Ã¢â‚¬â„¢ÃƒÅ½Ã‚Â¬ÃƒÂÃ‚ÂÃƒÅ½Ã‚Â´ÃƒÅ½Ã‚Â¹ÃƒÅ½Ã‚ÂµÃƒÂÃ¢â‚¬Å¡', 'ÃƒÅ½Ã…Â¸ÃƒÅ½Ã‚Â»ÃƒÅ½Ã‚Â¿ÃƒÅ½Ã‚ÂºÃƒÅ½Ã‚Â»ÃƒÅ½Ã‚Â®ÃƒÂÃ‚ÂÃƒÂÃ¢â‚¬Â°ÃƒÂÃ†â€™ÃƒÅ½Ã‚Âµ 5 ÃƒÅ½Ã‚Â²ÃƒÅ½Ã‚Â¬ÃƒÂÃ‚ÂÃƒÅ½Ã‚Â´ÃƒÅ½Ã‚Â¹ÃƒÅ½Ã‚ÂµÃƒÂÃ¢â‚¬Å¡', 'shifts', 'ÃƒÂ°Ã…Â¸Ã¢â‚¬Å“Ã¢â‚¬Â¦', 0, 5),
('shifts_10', '10 ÃƒÅ½Ã¢â‚¬â„¢ÃƒÅ½Ã‚Â¬ÃƒÂÃ‚ÂÃƒÅ½Ã‚Â´ÃƒÅ½Ã‚Â¹ÃƒÅ½Ã‚ÂµÃƒÂÃ¢â‚¬Å¡', 'ÃƒÅ½Ã…Â¸ÃƒÅ½Ã‚Â»ÃƒÅ½Ã‚Â¿ÃƒÅ½Ã‚ÂºÃƒÅ½Ã‚Â»ÃƒÅ½Ã‚Â®ÃƒÂÃ‚ÂÃƒÂÃ¢â‚¬Â°ÃƒÂÃ†â€™ÃƒÅ½Ã‚Âµ 10 ÃƒÅ½Ã‚Â²ÃƒÅ½Ã‚Â¬ÃƒÂÃ‚ÂÃƒÅ½Ã‚Â´ÃƒÅ½Ã‚Â¹ÃƒÅ½Ã‚ÂµÃƒÂÃ¢â‚¬Å¡', 'shifts', 'ÃƒÂ°Ã…Â¸Ã¢â‚¬Å“Ã¢â‚¬Â¦', 0, 10),
('shifts_25', '25 ÃƒÅ½Ã¢â‚¬â„¢ÃƒÅ½Ã‚Â¬ÃƒÂÃ‚ÂÃƒÅ½Ã‚Â´ÃƒÅ½Ã‚Â¹ÃƒÅ½Ã‚ÂµÃƒÂÃ¢â‚¬Å¡', 'ÃƒÅ½Ã…Â¸ÃƒÅ½Ã‚Â»ÃƒÅ½Ã‚Â¿ÃƒÅ½Ã‚ÂºÃƒÅ½Ã‚Â»ÃƒÅ½Ã‚Â®ÃƒÂÃ‚ÂÃƒÂÃ¢â‚¬Â°ÃƒÂÃ†â€™ÃƒÅ½Ã‚Âµ 25 ÃƒÅ½Ã‚Â²ÃƒÅ½Ã‚Â¬ÃƒÂÃ‚ÂÃƒÅ½Ã‚Â´ÃƒÅ½Ã‚Â¹ÃƒÅ½Ã‚ÂµÃƒÂÃ¢â‚¬Å¡', 'shifts', 'ÃƒÂ°Ã…Â¸Ã¢â‚¬Å“Ã¢â‚¬Â¦', 0, 25),
('shifts_50', '50 ÃƒÅ½Ã¢â‚¬â„¢ÃƒÅ½Ã‚Â¬ÃƒÂÃ‚ÂÃƒÅ½Ã‚Â´ÃƒÅ½Ã‚Â¹ÃƒÅ½Ã‚ÂµÃƒÂÃ¢â‚¬Å¡', 'ÃƒÅ½Ã…Â¸ÃƒÅ½Ã‚Â»ÃƒÅ½Ã‚Â¿ÃƒÅ½Ã‚ÂºÃƒÅ½Ã‚Â»ÃƒÅ½Ã‚Â®ÃƒÂÃ‚ÂÃƒÂÃ¢â‚¬Â°ÃƒÂÃ†â€™ÃƒÅ½Ã‚Âµ 50 ÃƒÅ½Ã‚Â²ÃƒÅ½Ã‚Â¬ÃƒÂÃ‚ÂÃƒÅ½Ã‚Â´ÃƒÅ½Ã‚Â¹ÃƒÅ½Ã‚ÂµÃƒÂÃ¢â‚¬Å¡', 'shifts', 'ÃƒÂ°Ã…Â¸Ã…Â½Ã‚Â¯', 0, 50),
('hours_10', '10 ÃƒÅ½Ã‚ÂÃƒÂÃ‚ÂÃƒÅ½Ã‚ÂµÃƒÂÃ¢â‚¬Å¡', 'ÃƒÅ½Ã‚Â£ÃƒÂÃ¢â‚¬Â¦ÃƒÅ½Ã‚Â¼ÃƒÂÃ¢â€šÂ¬ÃƒÅ½Ã‚Â»ÃƒÅ½Ã‚Â®ÃƒÂÃ‚ÂÃƒÂÃ¢â‚¬Â°ÃƒÂÃ†â€™ÃƒÅ½Ã‚Âµ 10 ÃƒÂÃ…Â½ÃƒÂÃ‚ÂÃƒÅ½Ã‚ÂµÃƒÂÃ¢â‚¬Å¡ ÃƒÅ½Ã‚ÂµÃƒÅ½Ã‚Â¸ÃƒÅ½Ã‚ÂµÃƒÅ½Ã‚Â»ÃƒÅ½Ã‚Â¿ÃƒÅ½Ã‚Â½ÃƒÂÃ¢â‚¬Å¾ÃƒÅ½Ã‚Â¹ÃƒÂÃ†â€™ÃƒÅ½Ã‚Â¼ÃƒÅ½Ã‚Â¿ÃƒÂÃ‚Â', 'hours', 'ÃƒÂ¢Ã‚ÂÃ‚Â°', 0, 10),
('hours_50', '50 ÃƒÅ½Ã‚ÂÃƒÂÃ‚ÂÃƒÅ½Ã‚ÂµÃƒÂÃ¢â‚¬Å¡', 'ÃƒÅ½Ã‚Â£ÃƒÂÃ¢â‚¬Â¦ÃƒÅ½Ã‚Â¼ÃƒÂÃ¢â€šÂ¬ÃƒÅ½Ã‚Â»ÃƒÅ½Ã‚Â®ÃƒÂÃ‚ÂÃƒÂÃ¢â‚¬Â°ÃƒÂÃ†â€™ÃƒÅ½Ã‚Âµ 50 ÃƒÂÃ…Â½ÃƒÂÃ‚ÂÃƒÅ½Ã‚ÂµÃƒÂÃ¢â‚¬Å¡ ÃƒÅ½Ã‚ÂµÃƒÅ½Ã‚Â¸ÃƒÅ½Ã‚ÂµÃƒÅ½Ã‚Â»ÃƒÅ½Ã‚Â¿ÃƒÅ½Ã‚Â½ÃƒÂÃ¢â‚¬Å¾ÃƒÅ½Ã‚Â¹ÃƒÂÃ†â€™ÃƒÅ½Ã‚Â¼ÃƒÅ½Ã‚Â¿ÃƒÂÃ‚Â', 'hours', 'ÃƒÂ¢Ã‚ÂÃ‚Â°', 0, 50),
('hours_100', '100 ÃƒÅ½Ã‚ÂÃƒÂÃ‚ÂÃƒÅ½Ã‚ÂµÃƒÂÃ¢â‚¬Å¡', 'ÃƒÅ½Ã‚Â£ÃƒÂÃ¢â‚¬Â¦ÃƒÅ½Ã‚Â¼ÃƒÂÃ¢â€šÂ¬ÃƒÅ½Ã‚Â»ÃƒÅ½Ã‚Â®ÃƒÂÃ‚ÂÃƒÂÃ¢â‚¬Â°ÃƒÂÃ†â€™ÃƒÅ½Ã‚Âµ 100 ÃƒÂÃ…Â½ÃƒÂÃ‚ÂÃƒÅ½Ã‚ÂµÃƒÂÃ¢â‚¬Å¡ ÃƒÅ½Ã‚ÂµÃƒÅ½Ã‚Â¸ÃƒÅ½Ã‚ÂµÃƒÅ½Ã‚Â»ÃƒÅ½Ã‚Â¿ÃƒÅ½Ã‚Â½ÃƒÂÃ¢â‚¬Å¾ÃƒÅ½Ã‚Â¹ÃƒÂÃ†â€™ÃƒÅ½Ã‚Â¼ÃƒÅ½Ã‚Â¿ÃƒÂÃ‚Â', 'hours', 'ÃƒÂ¢Ã‚ÂÃ‚Â°', 0, 100),
('hours_250', '250 ÃƒÅ½Ã‚ÂÃƒÂÃ‚ÂÃƒÅ½Ã‚ÂµÃƒÂÃ¢â‚¬Å¡', 'ÃƒÅ½Ã‚Â£ÃƒÂÃ¢â‚¬Â¦ÃƒÅ½Ã‚Â¼ÃƒÂÃ¢â€šÂ¬ÃƒÅ½Ã‚Â»ÃƒÅ½Ã‚Â®ÃƒÂÃ‚ÂÃƒÂÃ¢â‚¬Â°ÃƒÂÃ†â€™ÃƒÅ½Ã‚Âµ 250 ÃƒÂÃ…Â½ÃƒÂÃ‚ÂÃƒÅ½Ã‚ÂµÃƒÂÃ¢â‚¬Å¡ ÃƒÅ½Ã‚ÂµÃƒÅ½Ã‚Â¸ÃƒÅ½Ã‚ÂµÃƒÅ½Ã‚Â»ÃƒÅ½Ã‚Â¿ÃƒÅ½Ã‚Â½ÃƒÂÃ¢â‚¬Å¾ÃƒÅ½Ã‚Â¹ÃƒÂÃ†â€™ÃƒÅ½Ã‚Â¼ÃƒÅ½Ã‚Â¿ÃƒÂÃ‚Â', 'hours', 'ÃƒÂ°Ã…Â¸Ã‚ÂÃ¢â‚¬Â ', 0, 250),
('weekend_warrior', 'ÃƒÅ½Ã‚Â ÃƒÅ½Ã‚Â¿ÃƒÅ½Ã‚Â»ÃƒÅ½Ã‚ÂµÃƒÅ½Ã‚Â¼ÃƒÅ½Ã‚Â¹ÃƒÂÃ†â€™ÃƒÂÃ¢â‚¬Å¾ÃƒÅ½Ã‚Â®ÃƒÂÃ¢â‚¬Å¡ ÃƒÅ½Ã‚Â£ÃƒÅ½Ã‚Â±ÃƒÅ½Ã‚Â²ÃƒÅ½Ã‚Â²ÃƒÅ½Ã‚Â±ÃƒÂÃ¢â‚¬Å¾ÃƒÅ½Ã‚Â¿ÃƒÅ½Ã‚ÂºÃƒÂÃ‚ÂÃƒÂÃ‚ÂÃƒÅ½Ã‚Â¹ÃƒÅ½Ã‚Â±ÃƒÅ½Ã‚ÂºÃƒÅ½Ã‚Â¿ÃƒÂÃ¢â‚¬Â¦', 'ÃƒÅ½Ã…Â¸ÃƒÅ½Ã‚Â»ÃƒÅ½Ã‚Â¿ÃƒÅ½Ã‚ÂºÃƒÅ½Ã‚Â»ÃƒÅ½Ã‚Â®ÃƒÂÃ‚ÂÃƒÂÃ¢â‚¬Â°ÃƒÂÃ†â€™ÃƒÅ½Ã‚Âµ 10 ÃƒÅ½Ã‚Â²ÃƒÅ½Ã‚Â¬ÃƒÂÃ‚ÂÃƒÅ½Ã‚Â´ÃƒÅ½Ã‚Â¹ÃƒÅ½Ã‚ÂµÃƒÂÃ¢â‚¬Å¡ ÃƒÅ½Ã‚Â£ÃƒÅ½Ã‚Â±ÃƒÅ½Ã‚Â²ÃƒÅ½Ã‚Â²ÃƒÅ½Ã‚Â±ÃƒÂÃ¢â‚¬Å¾ÃƒÅ½Ã‚Â¿ÃƒÅ½Ã‚ÂºÃƒÂÃ‚ÂÃƒÂÃ‚ÂÃƒÅ½Ã‚Â¹ÃƒÅ½Ã‚Â±ÃƒÅ½Ã‚ÂºÃƒÅ½Ã‚Â¿ÃƒÂÃ¢â‚¬Â¦', 'special', 'ÃƒÂ¢Ã‹Å“Ã¢â€šÂ¬ÃƒÂ¯Ã‚Â¸Ã‚Â', 100, 10),
('night_owl', 'ÃƒÅ½Ã‚ÂÃƒÂÃ¢â‚¬Â¦ÃƒÂÃ¢â‚¬Â¡ÃƒÂÃ¢â‚¬Å¾ÃƒÅ½Ã‚Â¿ÃƒÂÃ¢â€šÂ¬ÃƒÅ½Ã‚Â¿ÃƒÂÃ‚ÂÃƒÅ½Ã‚Â»ÃƒÅ½Ã‚Â¹', 'ÃƒÅ½Ã…Â¸ÃƒÅ½Ã‚Â»ÃƒÅ½Ã‚Â¿ÃƒÅ½Ã‚ÂºÃƒÅ½Ã‚Â»ÃƒÅ½Ã‚Â®ÃƒÂÃ‚ÂÃƒÂÃ¢â‚¬Â°ÃƒÂÃ†â€™ÃƒÅ½Ã‚Âµ 10 ÃƒÅ½Ã‚Â½ÃƒÂÃ¢â‚¬Â¦ÃƒÂÃ¢â‚¬Â¡ÃƒÂÃ¢â‚¬Å¾ÃƒÅ½Ã‚ÂµÃƒÂÃ‚ÂÃƒÅ½Ã‚Â¹ÃƒÅ½Ã‚Â½ÃƒÅ½Ã‚Â­ÃƒÂÃ¢â‚¬Å¡ ÃƒÅ½Ã‚Â²ÃƒÅ½Ã‚Â¬ÃƒÂÃ‚ÂÃƒÅ½Ã‚Â´ÃƒÅ½Ã‚Â¹ÃƒÅ½Ã‚ÂµÃƒÂÃ¢â‚¬Å¡', 'special', 'ÃƒÂ°Ã…Â¸Ã…â€™Ã¢â€žÂ¢', 100, 10),
('medical_hero', 'ÃƒÅ½Ã¢â‚¬Â°ÃƒÂÃ‚ÂÃƒÂÃ¢â‚¬Â°ÃƒÅ½Ã‚Â±ÃƒÂÃ¢â‚¬Å¡ ÃƒÅ½Ã‚Â¥ÃƒÅ½Ã‚Â³ÃƒÅ½Ã‚ÂµÃƒÅ½Ã‚Â¯ÃƒÅ½Ã‚Â±ÃƒÂÃ¢â‚¬Å¡', 'ÃƒÅ½Ã…Â¸ÃƒÅ½Ã‚Â»ÃƒÅ½Ã‚Â¿ÃƒÅ½Ã‚ÂºÃƒÅ½Ã‚Â»ÃƒÅ½Ã‚Â®ÃƒÂÃ‚ÂÃƒÂÃ¢â‚¬Â°ÃƒÂÃ†â€™ÃƒÅ½Ã‚Âµ 10 ÃƒÂÃ¢â‚¬Â¦ÃƒÅ½Ã‚Â³ÃƒÅ½Ã‚ÂµÃƒÅ½Ã‚Â¹ÃƒÅ½Ã‚Â¿ÃƒÅ½Ã‚Â½ÃƒÅ½Ã‚Â¿ÃƒÅ½Ã‚Â¼ÃƒÅ½Ã‚Â¹ÃƒÅ½Ã‚ÂºÃƒÅ½Ã‚Â­ÃƒÂÃ¢â‚¬Å¡ ÃƒÅ½Ã‚Â±ÃƒÂÃ¢â€šÂ¬ÃƒÅ½Ã‚Â¿ÃƒÂÃ†â€™ÃƒÂÃ¢â‚¬Å¾ÃƒÅ½Ã‚Â¿ÃƒÅ½Ã‚Â»ÃƒÅ½Ã‚Â­ÃƒÂÃ¢â‚¬Å¡', 'special', 'ÃƒÂ¢Ã‚ÂÃ‚Â¤ÃƒÂ¯Ã‚Â¸Ã‚Â', 200, 10),
('points_100', '100 ÃƒÅ½Ã‚Â ÃƒÂÃ…â€™ÃƒÅ½Ã‚Â½ÃƒÂÃ¢â‚¬Å¾ÃƒÅ½Ã‚Â¿ÃƒÅ½Ã‚Â¹', 'ÃƒÅ½Ã‚Â£ÃƒÂÃ¢â‚¬Â¦ÃƒÅ½Ã‚Â³ÃƒÅ½Ã‚ÂºÃƒÅ½Ã‚Â­ÃƒÅ½Ã‚Â½ÃƒÂÃ¢â‚¬Å¾ÃƒÂÃ‚ÂÃƒÂÃ¢â‚¬Â°ÃƒÂÃ†â€™ÃƒÅ½Ã‚Âµ 100 ÃƒÂÃ¢â€šÂ¬ÃƒÂÃ…â€™ÃƒÅ½Ã‚Â½ÃƒÂÃ¢â‚¬Å¾ÃƒÅ½Ã‚Â¿ÃƒÂÃ¢â‚¬Â¦ÃƒÂÃ¢â‚¬Å¡', 'points', 'ÃƒÂ°Ã…Â¸Ã¢â‚¬â„¢Ã‚Â¯', 100, 0),
('points_500', '500 ÃƒÅ½Ã‚Â ÃƒÂÃ…â€™ÃƒÅ½Ã‚Â½ÃƒÂÃ¢â‚¬Å¾ÃƒÅ½Ã‚Â¿ÃƒÅ½Ã‚Â¹', 'ÃƒÅ½Ã‚Â£ÃƒÂÃ¢â‚¬Â¦ÃƒÅ½Ã‚Â³ÃƒÅ½Ã‚ÂºÃƒÅ½Ã‚Â­ÃƒÅ½Ã‚Â½ÃƒÂÃ¢â‚¬Å¾ÃƒÂÃ‚ÂÃƒÂÃ¢â‚¬Â°ÃƒÂÃ†â€™ÃƒÅ½Ã‚Âµ 500 ÃƒÂÃ¢â€šÂ¬ÃƒÂÃ…â€™ÃƒÅ½Ã‚Â½ÃƒÂÃ¢â‚¬Å¾ÃƒÅ½Ã‚Â¿ÃƒÂÃ¢â‚¬Â¦ÃƒÂÃ¢â‚¬Å¡', 'points', 'ÃƒÂ°Ã…Â¸Ã…â€™Ã…Â¸', 500, 0),
('points_1000', '1000 ÃƒÅ½Ã‚Â ÃƒÂÃ…â€™ÃƒÅ½Ã‚Â½ÃƒÂÃ¢â‚¬Å¾ÃƒÅ½Ã‚Â¿ÃƒÅ½Ã‚Â¹', 'ÃƒÅ½Ã‚Â£ÃƒÂÃ¢â‚¬Â¦ÃƒÅ½Ã‚Â³ÃƒÅ½Ã‚ÂºÃƒÅ½Ã‚Â­ÃƒÅ½Ã‚Â½ÃƒÂÃ¢â‚¬Å¾ÃƒÂÃ‚ÂÃƒÂÃ¢â‚¬Â°ÃƒÂÃ†â€™ÃƒÅ½Ã‚Âµ 1000 ÃƒÂÃ¢â€šÂ¬ÃƒÂÃ…â€™ÃƒÅ½Ã‚Â½ÃƒÂÃ¢â‚¬Å¾ÃƒÅ½Ã‚Â¿ÃƒÂÃ¢â‚¬Â¦ÃƒÂÃ¢â‚¬Å¡', 'points', 'ÃƒÂ°Ã…Â¸Ã¢â‚¬ËœÃ¢â‚¬Ëœ', 1000, 0);

-- Default admin user (password: admin123)
INSERT INTO `users` (`name`, `email`, `password`, `role`, `is_active`) VALUES
('ÃƒÅ½Ã¢â‚¬ÂÃƒÅ½Ã‚Â¹ÃƒÅ½Ã‚Â±ÃƒÂÃ¢â‚¬Â¡ÃƒÅ½Ã‚ÂµÃƒÅ½Ã‚Â¹ÃƒÂÃ‚ÂÃƒÅ½Ã‚Â¹ÃƒÂÃ†â€™ÃƒÂÃ¢â‚¬Å¾ÃƒÅ½Ã‚Â®ÃƒÂÃ¢â‚¬Å¡', 'admin@volunteerops.gr', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'SYSTEM_ADMIN', 1);

-- Default settings
INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
('app_name', 'VolunteerOps'),
('app_description', 'ÃƒÅ½Ã‚Â£ÃƒÂÃ‚ÂÃƒÂÃ†â€™ÃƒÂÃ¢â‚¬Å¾ÃƒÅ½Ã‚Â·ÃƒÅ½Ã‚Â¼ÃƒÅ½Ã‚Â± ÃƒÅ½Ã¢â‚¬ÂÃƒÅ½Ã‚Â¹ÃƒÅ½Ã‚Â±ÃƒÂÃ¢â‚¬Â¡ÃƒÅ½Ã‚ÂµÃƒÅ½Ã‚Â¯ÃƒÂÃ‚ÂÃƒÅ½Ã‚Â¹ÃƒÂÃ†â€™ÃƒÅ½Ã‚Â·ÃƒÂÃ¢â‚¬Å¡ ÃƒÅ½Ã¢â‚¬Â¢ÃƒÅ½Ã‚Â¸ÃƒÅ½Ã‚ÂµÃƒÅ½Ã‚Â»ÃƒÅ½Ã‚Â¿ÃƒÅ½Ã‚Â½ÃƒÂÃ¢â‚¬Å¾ÃƒÂÃ…Â½ÃƒÅ½Ã‚Â½'),
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

-- Default notification settings (email_template_id will be linked by install.php)
INSERT INTO `notification_settings` (`code`, `name`, `description`, `email_enabled`) VALUES
('new_mission', 'Νέα Αποστολή', 'Όταν δημοσιεύεται νέα αποστολή', 1),
('participation_approved', 'Έγκριση Συμμετοχής', 'Όταν εγκρίνεται η συμμετοχή εθελοντή σε βάρδια', 1),
('participation_rejected', 'Απόρριψη Συμμετοχής', 'Όταν απορρίπτεται η συμμετοχή εθελοντή', 1),
('shift_reminder', 'Υπενθύμιση Βάρδιας', 'Μία μέρα πριν τη βάρδια', 1),
('mission_canceled', 'Ακύρωση Αποστολής', 'Όταν ακυρώνεται αποστολή', 1),
('shift_canceled', 'Ακύρωση Βάρδιας', 'Όταν ακυρώνεται βάρδια', 1),
('points_earned', 'Κέρδος Πόντων', 'Όταν ο εθελοντής κερδίζει πόντους', 0),
('welcome', 'Καλωσόρισμα', 'Μετά την εγγραφή νέου χρήστη', 1);



