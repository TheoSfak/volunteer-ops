-- Migration: Add mission recurrences support (v3.62.0)
-- Created: 2026-03-15

-- Create mission_recurrences table
CREATE TABLE IF NOT EXISTS `mission_recurrences` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `type` ENUM('weekly', 'random_days', 'interval') NOT NULL,
    `weekdays` JSON NULL,
    `random_dates` JSON NULL,
    `interval_days` TINYINT UNSIGNED NULL,
    `interval_start_date` DATE NULL,
    `end_date` DATE NOT NULL,
    `created_by` INT UNSIGNED NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `missions`
    ADD COLUMN `recurrence_id` INT UNSIGNED NULL AFTER `updated_at`;

ALTER TABLE `missions`
    ADD COLUMN `recurrence_instance_date` DATE NULL AFTER `recurrence_id`;

ALTER TABLE `missions`
    ADD INDEX `idx_missions_recurrence` (`recurrence_id`);
