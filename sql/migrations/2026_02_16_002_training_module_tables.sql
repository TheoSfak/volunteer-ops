-- Migration: v2.2.2 - Complete Training Module Tables
-- Date: 2026-02-16
-- Description: Creates ALL training module tables missing from production
-- Note: Uses IF NOT EXISTS so safe to run on any database state

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
-- TRAINING QUIZZES TABLE
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
-- TRAINING EXAMS TABLE
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
    `quiz_id` INT UNSIGNED NOT NULL,
    `question_type` ENUM('MULTIPLE_CHOICE', 'TRUE_FALSE', 'OPEN_ENDED') DEFAULT 'MULTIPLE_CHOICE',
    `question_text` TEXT NOT NULL,
    `option_a` VARCHAR(500) NULL,
    `option_b` VARCHAR(500) NULL,
    `option_c` VARCHAR(500) NULL,
    `option_d` VARCHAR(500) NULL,
    `correct_option` CHAR(1) NULL,
    `explanation` TEXT NULL,
    `display_order` INT DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`quiz_id`) REFERENCES `training_quizzes`(`id`) ON DELETE CASCADE,
    INDEX `idx_quiz_questions_quiz` (`quiz_id`),
    INDEX `idx_quiz_questions_order` (`display_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- TRAINING EXAM QUESTIONS TABLE
-- =============================================
CREATE TABLE IF NOT EXISTS `training_exam_questions` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `exam_id` INT UNSIGNED NOT NULL,
    `question_type` ENUM('MULTIPLE_CHOICE', 'TRUE_FALSE', 'OPEN_ENDED') DEFAULT 'MULTIPLE_CHOICE',
    `question_text` TEXT NOT NULL,
    `option_a` VARCHAR(500) NULL,
    `option_b` VARCHAR(500) NULL,
    `option_c` VARCHAR(500) NULL,
    `option_d` VARCHAR(500) NULL,
    `correct_option` CHAR(1) NULL,
    `explanation` TEXT NULL,
    `display_order` INT DEFAULT 0,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`exam_id`) REFERENCES `training_exams`(`id`) ON DELETE CASCADE,
    INDEX `idx_exam_questions_exam` (`exam_id`),
    INDEX `idx_exam_questions_order` (`display_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- QUIZ ATTEMPTS TABLE
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
-- EXAM ATTEMPTS TABLE
-- =============================================
CREATE TABLE IF NOT EXISTS `exam_attempts` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `exam_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `selected_questions_json` JSON NOT NULL,
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
-- USER ANSWERS TABLE
-- =============================================
CREATE TABLE IF NOT EXISTS `user_answers` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `attempt_id` INT UNSIGNED NOT NULL,
    `attempt_type` ENUM('QUIZ', 'EXAM') NOT NULL,
    `question_id` INT UNSIGNED NOT NULL,
    `selected_option` CHAR(1) NULL,
    `answer_text` TEXT NULL,
    `is_correct` TINYINT(1) NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_answers_attempt` (`attempt_id`, `attempt_type`),
    INDEX `idx_answers_question` (`question_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- TRAINING USER PROGRESS TABLE
-- =============================================
CREATE TABLE IF NOT EXISTS `training_user_progress` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `material_id` INT UNSIGNED NOT NULL,
    `completed` TINYINT(1) DEFAULT 0,
    `completed_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`material_id`) REFERENCES `training_materials`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `unique_user_material` (`user_id`, `material_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- ADD available_from/available_until COLUMNS TO training_exams (for Quick Launch feature)
-- =============================================
ALTER TABLE `training_exams` ADD COLUMN `available_from` DATETIME NULL AFTER `is_active`;

ALTER TABLE `training_exams` ADD COLUMN `available_until` DATETIME NULL AFTER `available_from`;
