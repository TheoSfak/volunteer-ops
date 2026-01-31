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
    `icon` VARCHAR(50) DEFAULT 'Ã°Å¸Ââ€ ',
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
('ÃŽâ€œÃŽÂµÃŽÂ½ÃŽÂ¹ÃŽÂºÃÅ’Ãâ€š ÃŽâ€¢ÃŽÂ¸ÃŽÂµÃŽÂ»ÃŽÂ¿ÃŽÂ½Ãâ€žÃŽÂ¹ÃÆ’ÃŽÂ¼ÃÅ’Ãâ€š', 'ÃŽâ€œÃŽÂµÃŽÂ½ÃŽÂ¹ÃŽÂºÃŽÂ­Ãâ€š ÃŽÂµÃŽÂ¸ÃŽÂµÃŽÂ»ÃŽÂ¿ÃŽÂ½Ãâ€žÃŽÂ¹ÃŽÂºÃŽÂ­Ãâ€š ÃŽÂ´ÃÂÃŽÂ¬ÃÆ’ÃŽÂµÃŽÂ¹Ãâ€š'),
('ÃŽÂ¥ÃŽÂ³ÃŽÂµÃŽÂ¹ÃŽÂ¿ÃŽÂ½ÃŽÂ¿ÃŽÂ¼ÃŽÂ¹ÃŽÂºÃŽÂ® ÃŽÅ¡ÃŽÂ¬ÃŽÂ»Ãâ€¦ÃË†ÃŽÂ·', 'ÃŽÂ¥ÃŽÂ³ÃŽÂµÃŽÂ¹ÃŽÂ¿ÃŽÂ½ÃŽÂ¿ÃŽÂ¼ÃŽÂ¹ÃŽÂºÃŽÂ­Ãâ€š ÃŽÂ±Ãâ‚¬ÃŽÂ¿ÃÆ’Ãâ€žÃŽÂ¿ÃŽÂ»ÃŽÂ­Ãâ€š ÃŽÂºÃŽÂ±ÃŽÂ¹ Ãâ‚¬ÃŽÂ±ÃÂÃŽÂ¿Ãâ€¡ÃŽÂ® Ãâ‚¬ÃÂÃÅ½Ãâ€žÃâ€°ÃŽÂ½ ÃŽÂ²ÃŽÂ¿ÃŽÂ·ÃŽÂ¸ÃŽÂµÃŽÂ¹ÃÅ½ÃŽÂ½'),
('ÃŽÂ ÃŽÂµÃÂÃŽÂ¹ÃŽÂ²ÃŽÂ±ÃŽÂ»ÃŽÂ»ÃŽÂ¿ÃŽÂ½Ãâ€žÃŽÂ¹ÃŽÂºÃŽÂ­Ãâ€š ÃŽâ€ÃÂÃŽÂ¬ÃÆ’ÃŽÂµÃŽÂ¹Ãâ€š', 'ÃŽâ€ÃÂÃŽÂ¬ÃÆ’ÃŽÂµÃŽÂ¹Ãâ€š ÃŽÂ³ÃŽÂ¹ÃŽÂ± Ãâ€žÃŽÂ¿ Ãâ‚¬ÃŽÂµÃÂÃŽÂ¹ÃŽÂ²ÃŽÂ¬ÃŽÂ»ÃŽÂ»ÃŽÂ¿ÃŽÂ½'),
('ÃŽÅ¡ÃŽÂ¿ÃŽÂ¹ÃŽÂ½Ãâ€°ÃŽÂ½ÃŽÂ¹ÃŽÂºÃŽÂ® ÃŽâ€˜ÃŽÂ»ÃŽÂ»ÃŽÂ·ÃŽÂ»ÃŽÂµÃŽÂ³ÃŽÂ³ÃÂÃŽÂ·', 'ÃŽâ€ÃÂÃŽÂ¬ÃÆ’ÃŽÂµÃŽÂ¹Ãâ€š ÃŽÂºÃŽÂ¿ÃŽÂ¹ÃŽÂ½Ãâ€°ÃŽÂ½ÃŽÂ¹ÃŽÂºÃŽÂ®Ãâ€š ÃŽÂ±ÃŽÂ»ÃŽÂ»ÃŽÂ·ÃŽÂ»ÃŽÂµÃŽÂ³ÃŽÂ³ÃÂÃŽÂ·Ãâ€š');

-- Default skills
INSERT INTO `skills` (`name`, `category`) VALUES
('ÃŽÂ ÃÂÃÅ½Ãâ€žÃŽÂµÃâ€š ÃŽâ€™ÃŽÂ¿ÃŽÂ®ÃŽÂ¸ÃŽÂµÃŽÂ¹ÃŽÂµÃâ€š', 'ÃŽÂ¥ÃŽÂ³ÃŽÂµÃŽÂ¯ÃŽÂ±'),
('ÃŽÂÃŽÂ¿ÃÆ’ÃŽÂ·ÃŽÂ»ÃŽÂµÃâ€¦Ãâ€žÃŽÂ¹ÃŽÂºÃŽÂ®', 'ÃŽÂ¥ÃŽÂ³ÃŽÂµÃŽÂ¯ÃŽÂ±'),
('ÃŽÅ¸ÃŽÂ´ÃŽÂ®ÃŽÂ³ÃŽÂ·ÃÆ’ÃŽÂ·', 'ÃŽÅ“ÃŽÂµÃâ€žÃŽÂ±Ãâ€ ÃŽÂ¿ÃÂÃŽÂ­Ãâ€š'),
('ÃŽÅ¸ÃŽÂ´ÃŽÂ®ÃŽÂ³ÃŽÂ·ÃÆ’ÃŽÂ· ÃŽÅ“ÃŽÂ¿Ãâ€žÃŽÂ¿ÃÆ’Ãâ€¦ÃŽÂºÃŽÂ»ÃŽÂ­Ãâ€žÃŽÂ±Ãâ€š', 'ÃŽÅ“ÃŽÂµÃâ€žÃŽÂ±Ãâ€ ÃŽÂ¿ÃÂÃŽÂ­Ãâ€š'),
('ÃŽÅ¸ÃÂÃŽÂ³ÃŽÂ¬ÃŽÂ½Ãâ€°ÃÆ’ÃŽÂ· ÃŽâ€¢ÃŽÂºÃŽÂ´ÃŽÂ·ÃŽÂ»ÃÅ½ÃÆ’ÃŽÂµÃâ€°ÃŽÂ½', 'ÃŽâ€ÃŽÂ¹ÃŽÂ¿ÃŽÂ¯ÃŽÂºÃŽÂ·ÃÆ’ÃŽÂ·'),
('ÃŽâ€ÃŽÂ¹ÃŽÂ±Ãâ€¡ÃŽÂµÃŽÂ¯ÃÂÃŽÂ¹ÃÆ’ÃŽÂ· ÃŽÅ¡ÃÂÃŽÂ¯ÃÆ’ÃŽÂµÃâ€°ÃŽÂ½', 'ÃŽâ€ÃŽÂ¹ÃŽÂ¿ÃŽÂ¯ÃŽÂºÃŽÂ·ÃÆ’ÃŽÂ·'),
('ÃŽâ€¢Ãâ‚¬ÃŽÂ¹ÃŽÂºÃŽÂ¿ÃŽÂ¹ÃŽÂ½Ãâ€°ÃŽÂ½ÃŽÂ¯ÃŽÂ±', 'ÃŽâ€œÃŽÂµÃŽÂ½ÃŽÂ¹ÃŽÂºÃŽÂ¬'),
('ÃŽÅ¾ÃŽÂ­ÃŽÂ½ÃŽÂµÃâ€š ÃŽâ€œÃŽÂ»ÃÅ½ÃÆ’ÃÆ’ÃŽÂµÃâ€š', 'ÃŽâ€œÃŽÂµÃŽÂ½ÃŽÂ¹ÃŽÂºÃŽÂ¬'),
('ÃŽÂ¦Ãâ€°Ãâ€žÃŽÂ¿ÃŽÂ³ÃÂÃŽÂ±Ãâ€ ÃŽÂ¯ÃŽÂ±', 'ÃŽÂ¤ÃŽÂµÃâ€¡ÃŽÂ½ÃŽÂ¹ÃŽÂºÃŽÂ¬'),
('ÃŽÂ ÃŽÂ»ÃŽÂ·ÃÂÃŽÂ¿Ãâ€ ÃŽÂ¿ÃÂÃŽÂ¹ÃŽÂºÃŽÂ®', 'ÃŽÂ¤ÃŽÂµÃâ€¡ÃŽÂ½ÃŽÂ¹ÃŽÂºÃŽÂ¬');

-- Default achievements
INSERT INTO `achievements` (`code`, `name`, `description`, `category`, `icon`, `required_points`, `threshold`) VALUES
('first_shift', 'ÃŽÂ ÃÂÃÅ½Ãâ€žÃŽÂ· ÃŽâ€™ÃŽÂ¬ÃÂÃŽÂ´ÃŽÂ¹ÃŽÂ±', 'ÃŽÅ¸ÃŽÂ»ÃŽÂ¿ÃŽÂºÃŽÂ»ÃŽÂ®ÃÂÃâ€°ÃÆ’ÃŽÂµ Ãâ€žÃŽÂ·ÃŽÂ½ Ãâ‚¬ÃÂÃÅ½Ãâ€žÃŽÂ· ÃÆ’ÃŽÂ¿Ãâ€¦ ÃŽÂ²ÃŽÂ¬ÃÂÃŽÂ´ÃŽÂ¹ÃŽÂ±', 'milestone', 'Ã¢Â­Â', 0, 1),
('shifts_5', '5 ÃŽâ€™ÃŽÂ¬ÃÂÃŽÂ´ÃŽÂ¹ÃŽÂµÃâ€š', 'ÃŽÅ¸ÃŽÂ»ÃŽÂ¿ÃŽÂºÃŽÂ»ÃŽÂ®ÃÂÃâ€°ÃÆ’ÃŽÂµ 5 ÃŽÂ²ÃŽÂ¬ÃÂÃŽÂ´ÃŽÂ¹ÃŽÂµÃâ€š', 'shifts', 'Ã°Å¸â€œâ€¦', 0, 5),
('shifts_10', '10 ÃŽâ€™ÃŽÂ¬ÃÂÃŽÂ´ÃŽÂ¹ÃŽÂµÃâ€š', 'ÃŽÅ¸ÃŽÂ»ÃŽÂ¿ÃŽÂºÃŽÂ»ÃŽÂ®ÃÂÃâ€°ÃÆ’ÃŽÂµ 10 ÃŽÂ²ÃŽÂ¬ÃÂÃŽÂ´ÃŽÂ¹ÃŽÂµÃâ€š', 'shifts', 'Ã°Å¸â€œâ€¦', 0, 10),
('shifts_25', '25 ÃŽâ€™ÃŽÂ¬ÃÂÃŽÂ´ÃŽÂ¹ÃŽÂµÃâ€š', 'ÃŽÅ¸ÃŽÂ»ÃŽÂ¿ÃŽÂºÃŽÂ»ÃŽÂ®ÃÂÃâ€°ÃÆ’ÃŽÂµ 25 ÃŽÂ²ÃŽÂ¬ÃÂÃŽÂ´ÃŽÂ¹ÃŽÂµÃâ€š', 'shifts', 'Ã°Å¸â€œâ€¦', 0, 25),
('shifts_50', '50 ÃŽâ€™ÃŽÂ¬ÃÂÃŽÂ´ÃŽÂ¹ÃŽÂµÃâ€š', 'ÃŽÅ¸ÃŽÂ»ÃŽÂ¿ÃŽÂºÃŽÂ»ÃŽÂ®ÃÂÃâ€°ÃÆ’ÃŽÂµ 50 ÃŽÂ²ÃŽÂ¬ÃÂÃŽÂ´ÃŽÂ¹ÃŽÂµÃâ€š', 'shifts', 'Ã°Å¸Å½Â¯', 0, 50),
('hours_10', '10 ÃŽÂÃÂÃŽÂµÃâ€š', 'ÃŽÂ£Ãâ€¦ÃŽÂ¼Ãâ‚¬ÃŽÂ»ÃŽÂ®ÃÂÃâ€°ÃÆ’ÃŽÂµ 10 ÃÅ½ÃÂÃŽÂµÃâ€š ÃŽÂµÃŽÂ¸ÃŽÂµÃŽÂ»ÃŽÂ¿ÃŽÂ½Ãâ€žÃŽÂ¹ÃÆ’ÃŽÂ¼ÃŽÂ¿ÃÂ', 'hours', 'Ã¢ÂÂ°', 0, 10),
('hours_50', '50 ÃŽÂÃÂÃŽÂµÃâ€š', 'ÃŽÂ£Ãâ€¦ÃŽÂ¼Ãâ‚¬ÃŽÂ»ÃŽÂ®ÃÂÃâ€°ÃÆ’ÃŽÂµ 50 ÃÅ½ÃÂÃŽÂµÃâ€š ÃŽÂµÃŽÂ¸ÃŽÂµÃŽÂ»ÃŽÂ¿ÃŽÂ½Ãâ€žÃŽÂ¹ÃÆ’ÃŽÂ¼ÃŽÂ¿ÃÂ', 'hours', 'Ã¢ÂÂ°', 0, 50),
('hours_100', '100 ÃŽÂÃÂÃŽÂµÃâ€š', 'ÃŽÂ£Ãâ€¦ÃŽÂ¼Ãâ‚¬ÃŽÂ»ÃŽÂ®ÃÂÃâ€°ÃÆ’ÃŽÂµ 100 ÃÅ½ÃÂÃŽÂµÃâ€š ÃŽÂµÃŽÂ¸ÃŽÂµÃŽÂ»ÃŽÂ¿ÃŽÂ½Ãâ€žÃŽÂ¹ÃÆ’ÃŽÂ¼ÃŽÂ¿ÃÂ', 'hours', 'Ã¢ÂÂ°', 0, 100),
('hours_250', '250 ÃŽÂÃÂÃŽÂµÃâ€š', 'ÃŽÂ£Ãâ€¦ÃŽÂ¼Ãâ‚¬ÃŽÂ»ÃŽÂ®ÃÂÃâ€°ÃÆ’ÃŽÂµ 250 ÃÅ½ÃÂÃŽÂµÃâ€š ÃŽÂµÃŽÂ¸ÃŽÂµÃŽÂ»ÃŽÂ¿ÃŽÂ½Ãâ€žÃŽÂ¹ÃÆ’ÃŽÂ¼ÃŽÂ¿ÃÂ', 'hours', 'Ã°Å¸Ââ€ ', 0, 250),
('weekend_warrior', 'ÃŽÂ ÃŽÂ¿ÃŽÂ»ÃŽÂµÃŽÂ¼ÃŽÂ¹ÃÆ’Ãâ€žÃŽÂ®Ãâ€š ÃŽÂ£ÃŽÂ±ÃŽÂ²ÃŽÂ²ÃŽÂ±Ãâ€žÃŽÂ¿ÃŽÂºÃÂÃÂÃŽÂ¹ÃŽÂ±ÃŽÂºÃŽÂ¿Ãâ€¦', 'ÃŽÅ¸ÃŽÂ»ÃŽÂ¿ÃŽÂºÃŽÂ»ÃŽÂ®ÃÂÃâ€°ÃÆ’ÃŽÂµ 10 ÃŽÂ²ÃŽÂ¬ÃÂÃŽÂ´ÃŽÂ¹ÃŽÂµÃâ€š ÃŽÂ£ÃŽÂ±ÃŽÂ²ÃŽÂ²ÃŽÂ±Ãâ€žÃŽÂ¿ÃŽÂºÃÂÃÂÃŽÂ¹ÃŽÂ±ÃŽÂºÃŽÂ¿Ãâ€¦', 'special', 'Ã¢Ëœâ‚¬Ã¯Â¸Â', 100, 10),
('night_owl', 'ÃŽÂÃâ€¦Ãâ€¡Ãâ€žÃŽÂ¿Ãâ‚¬ÃŽÂ¿ÃÂÃŽÂ»ÃŽÂ¹', 'ÃŽÅ¸ÃŽÂ»ÃŽÂ¿ÃŽÂºÃŽÂ»ÃŽÂ®ÃÂÃâ€°ÃÆ’ÃŽÂµ 10 ÃŽÂ½Ãâ€¦Ãâ€¡Ãâ€žÃŽÂµÃÂÃŽÂ¹ÃŽÂ½ÃŽÂ­Ãâ€š ÃŽÂ²ÃŽÂ¬ÃÂÃŽÂ´ÃŽÂ¹ÃŽÂµÃâ€š', 'special', 'Ã°Å¸Å’â„¢', 100, 10),
('medical_hero', 'ÃŽâ€°ÃÂÃâ€°ÃŽÂ±Ãâ€š ÃŽÂ¥ÃŽÂ³ÃŽÂµÃŽÂ¯ÃŽÂ±Ãâ€š', 'ÃŽÅ¸ÃŽÂ»ÃŽÂ¿ÃŽÂºÃŽÂ»ÃŽÂ®ÃÂÃâ€°ÃÆ’ÃŽÂµ 10 Ãâ€¦ÃŽÂ³ÃŽÂµÃŽÂ¹ÃŽÂ¿ÃŽÂ½ÃŽÂ¿ÃŽÂ¼ÃŽÂ¹ÃŽÂºÃŽÂ­Ãâ€š ÃŽÂ±Ãâ‚¬ÃŽÂ¿ÃÆ’Ãâ€žÃŽÂ¿ÃŽÂ»ÃŽÂ­Ãâ€š', 'special', 'Ã¢ÂÂ¤Ã¯Â¸Â', 200, 10),
('points_100', '100 ÃŽÂ ÃÅ’ÃŽÂ½Ãâ€žÃŽÂ¿ÃŽÂ¹', 'ÃŽÂ£Ãâ€¦ÃŽÂ³ÃŽÂºÃŽÂ­ÃŽÂ½Ãâ€žÃÂÃâ€°ÃÆ’ÃŽÂµ 100 Ãâ‚¬ÃÅ’ÃŽÂ½Ãâ€žÃŽÂ¿Ãâ€¦Ãâ€š', 'points', 'Ã°Å¸â€™Â¯', 100, 0),
('points_500', '500 ÃŽÂ ÃÅ’ÃŽÂ½Ãâ€žÃŽÂ¿ÃŽÂ¹', 'ÃŽÂ£Ãâ€¦ÃŽÂ³ÃŽÂºÃŽÂ­ÃŽÂ½Ãâ€žÃÂÃâ€°ÃÆ’ÃŽÂµ 500 Ãâ‚¬ÃÅ’ÃŽÂ½Ãâ€žÃŽÂ¿Ãâ€¦Ãâ€š', 'points', 'Ã°Å¸Å’Å¸', 500, 0),
('points_1000', '1000 ÃŽÂ ÃÅ’ÃŽÂ½Ãâ€žÃŽÂ¿ÃŽÂ¹', 'ÃŽÂ£Ãâ€¦ÃŽÂ³ÃŽÂºÃŽÂ­ÃŽÂ½Ãâ€žÃÂÃâ€°ÃÆ’ÃŽÂµ 1000 Ãâ‚¬ÃÅ’ÃŽÂ½Ãâ€žÃŽÂ¿Ãâ€¦Ãâ€š', 'points', 'Ã°Å¸â€˜â€˜', 1000, 0);

-- Default admin user (password: admin123)
INSERT INTO `users` (`name`, `email`, `password`, `role`, `is_active`) VALUES
('ÃŽâ€ÃŽÂ¹ÃŽÂ±Ãâ€¡ÃŽÂµÃŽÂ¹ÃÂÃŽÂ¹ÃÆ’Ãâ€žÃŽÂ®Ãâ€š', 'admin@volunteerops.gr', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'SYSTEM_ADMIN', 1);

-- Default settings
INSERT INTO `settings` (`setting_key`, `setting_value`) VALUES
('app_name', 'VolunteerOps'),
('app_description', 'ÃŽÂ£ÃÂÃÆ’Ãâ€žÃŽÂ·ÃŽÂ¼ÃŽÂ± ÃŽâ€ÃŽÂ¹ÃŽÂ±Ãâ€¡ÃŽÂµÃŽÂ¯ÃÂÃŽÂ¹ÃÆ’ÃŽÂ·Ãâ€š ÃŽâ€¢ÃŽÂ¸ÃŽÂµÃŽÂ»ÃŽÂ¿ÃŽÂ½Ãâ€žÃÅ½ÃŽÂ½'),
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
('new_mission', 'ÃŽÂÃŽÂ­ÃŽÂ± ÃŽâ€˜Ãâ‚¬ÃŽÂ¿ÃÆ’Ãâ€žÃŽÂ¿ÃŽÂ»ÃŽÂ®', 'ÃŽÅ’Ãâ€žÃŽÂ±ÃŽÂ½ ÃŽÂ´ÃŽÂ·ÃŽÂ¼ÃŽÂ¿ÃÆ’ÃŽÂ¹ÃŽÂµÃÂÃŽÂµÃâ€žÃŽÂ±ÃŽÂ¹ ÃŽÂ½ÃŽÂ­ÃŽÂ± ÃŽÂ±Ãâ‚¬ÃŽÂ¿ÃÆ’Ãâ€žÃŽÂ¿ÃŽÂ»ÃŽÂ®', 1, (SELECT id FROM email_templates WHERE code = 'new_mission')),
('participation_approved', 'ÃŽË†ÃŽÂ³ÃŽÂºÃÂÃŽÂ¹ÃÆ’ÃŽÂ· ÃŽÂ£Ãâ€¦ÃŽÂ¼ÃŽÂ¼ÃŽÂµÃâ€žÃŽÂ¿Ãâ€¡ÃŽÂ®Ãâ€š', 'ÃŽÅ’Ãâ€žÃŽÂ±ÃŽÂ½ ÃŽÂµÃŽÂ³ÃŽÂºÃÂÃŽÂ¯ÃŽÂ½ÃŽÂµÃâ€žÃŽÂ±ÃŽÂ¹ ÃŽÂ· ÃÆ’Ãâ€¦ÃŽÂ¼ÃŽÂ¼ÃŽÂµÃâ€žÃŽÂ¿Ãâ€¡ÃŽÂ® ÃŽÂµÃŽÂ¸ÃŽÂµÃŽÂ»ÃŽÂ¿ÃŽÂ½Ãâ€žÃŽÂ® ÃÆ’ÃŽÂµ ÃŽÂ²ÃŽÂ¬ÃÂÃŽÂ´ÃŽÂ¹ÃŽÂ±', 1, (SELECT id FROM email_templates WHERE code = 'participation_approved')),
('participation_rejected', 'ÃŽâ€˜Ãâ‚¬ÃÅ’ÃÂÃÂÃŽÂ¹ÃË†ÃŽÂ· ÃŽÂ£Ãâ€¦ÃŽÂ¼ÃŽÂ¼ÃŽÂµÃâ€žÃŽÂ¿Ãâ€¡ÃŽÂ®Ãâ€š', 'ÃŽÅ’Ãâ€žÃŽÂ±ÃŽÂ½ ÃŽÂ±Ãâ‚¬ÃŽÂ¿ÃÂÃÂÃŽÂ¯Ãâ‚¬Ãâ€žÃŽÂµÃâ€žÃŽÂ±ÃŽÂ¹ ÃŽÂ· ÃÆ’Ãâ€¦ÃŽÂ¼ÃŽÂ¼ÃŽÂµÃâ€žÃŽÂ¿Ãâ€¡ÃŽÂ® ÃŽÂµÃŽÂ¸ÃŽÂµÃŽÂ»ÃŽÂ¿ÃŽÂ½Ãâ€žÃŽÂ®', 1, (SELECT id FROM email_templates WHERE code = 'participation_rejected')),
('shift_reminder', 'ÃŽÂ¥Ãâ‚¬ÃŽÂµÃŽÂ½ÃŽÂ¸ÃÂÃŽÂ¼ÃŽÂ¹ÃÆ’ÃŽÂ· ÃŽâ€™ÃŽÂ¬ÃÂÃŽÂ´ÃŽÂ¹ÃŽÂ±Ãâ€š', 'ÃŽÅ“ÃŽÂ¯ÃŽÂ± ÃŽÂ¼ÃŽÂ­ÃÂÃŽÂ± Ãâ‚¬ÃÂÃŽÂ¹ÃŽÂ½ Ãâ€žÃŽÂ· ÃŽÂ²ÃŽÂ¬ÃÂÃŽÂ´ÃŽÂ¹ÃŽÂ±', 1, (SELECT id FROM email_templates WHERE code = 'shift_reminder')),
('mission_canceled', 'ÃŽâ€˜ÃŽÂºÃÂÃÂÃâ€°ÃÆ’ÃŽÂ· ÃŽâ€˜Ãâ‚¬ÃŽÂ¿ÃÆ’Ãâ€žÃŽÂ¿ÃŽÂ»ÃŽÂ®Ãâ€š', 'ÃŽÅ’Ãâ€žÃŽÂ±ÃŽÂ½ ÃŽÂ±ÃŽÂºÃâ€¦ÃÂÃÅ½ÃŽÂ½ÃŽÂµÃâ€žÃŽÂ±ÃŽÂ¹ ÃŽÂ±Ãâ‚¬ÃŽÂ¿ÃÆ’Ãâ€žÃŽÂ¿ÃŽÂ»ÃŽÂ®', 1, (SELECT id FROM email_templates WHERE code = 'mission_canceled')),
('shift_canceled', 'ÃŽâ€˜ÃŽÂºÃÂÃÂÃâ€°ÃÆ’ÃŽÂ· ÃŽâ€™ÃŽÂ¬ÃÂÃŽÂ´ÃŽÂ¹ÃŽÂ±Ãâ€š', 'ÃŽÅ’Ãâ€žÃŽÂ±ÃŽÂ½ ÃŽÂ±ÃŽÂºÃâ€¦ÃÂÃÅ½ÃŽÂ½ÃŽÂµÃâ€žÃŽÂ±ÃŽÂ¹ ÃŽÂ²ÃŽÂ¬ÃÂÃŽÂ´ÃŽÂ¹ÃŽÂ±', 1, (SELECT id FROM email_templates WHERE code = 'shift_canceled')),
('points_earned', 'ÃŽÅ¡ÃŽÂ­ÃÂÃŽÂ´ÃŽÂ¿Ãâ€š ÃŽÂ ÃÅ’ÃŽÂ½Ãâ€žÃâ€°ÃŽÂ½', 'ÃŽÅ’Ãâ€žÃŽÂ±ÃŽÂ½ ÃŽÂ¿ ÃŽÂµÃŽÂ¸ÃŽÂµÃŽÂ»ÃŽÂ¿ÃŽÂ½Ãâ€žÃŽÂ®Ãâ€š ÃŽÂºÃŽÂµÃÂÃŽÂ´ÃŽÂ¯ÃŽÂ¶ÃŽÂµÃŽÂ¹ Ãâ‚¬ÃÅ’ÃŽÂ½Ãâ€žÃŽÂ¿Ãâ€¦Ãâ€š', 0, (SELECT id FROM email_templates WHERE code = 'points_earned')),
('welcome', 'ÃŽÅ¡ÃŽÂ±ÃŽÂ»Ãâ€°ÃÆ’ÃÅ’ÃÂÃŽÂ¹ÃÆ’ÃŽÂ¼ÃŽÂ±', 'ÃŽÅ“ÃŽÂµÃâ€žÃŽÂ¬ Ãâ€žÃŽÂ·ÃŽÂ½ ÃŽÂµÃŽÂ³ÃŽÂ³ÃÂÃŽÂ±Ãâ€ ÃŽÂ® ÃŽÂ½ÃŽÂ­ÃŽÂ¿Ãâ€¦ Ãâ€¡ÃÂÃŽÂ®ÃÆ’Ãâ€žÃŽÂ·', 1, (SELECT id FROM email_templates WHERE code = 'welcome'));


