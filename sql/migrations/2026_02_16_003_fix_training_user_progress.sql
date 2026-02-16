-- Migration: Fix training_user_progress table structure
-- Date: 2026-02-16
-- Description: Fixes training_user_progress to use category_id instead of material_id

-- Drop the old table if it exists with wrong structure
DROP TABLE IF EXISTS `training_user_progress`;

-- Recreate with correct structure matching what the PHP code expects
CREATE TABLE IF NOT EXISTS `training_user_progress` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `category_id` INT UNSIGNED NOT NULL,
    `materials_viewed_json` JSON NULL,
    `quizzes_completed` INT DEFAULT 0,
    `exams_passed` INT DEFAULT 0,
    `last_activity` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`category_id`) REFERENCES `training_categories`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `unique_user_category` (`user_id`, `category_id`),
    INDEX `idx_progress_user` (`user_id`),
    INDEX `idx_progress_category` (`category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
