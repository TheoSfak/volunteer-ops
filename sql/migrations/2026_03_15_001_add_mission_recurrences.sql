-- Migration: Add mission recurrences support (v3.62.0)
-- Created: 2026-03-15

-- Create mission_recurrences table
CREATE TABLE IF NOT EXISTS `mission_recurrences` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `type` ENUM('weekly', 'random_days', 'interval') NOT NULL,
    `weekdays` JSON NULL COMMENT 'ISO weekday numbers [1=Mon..7=Sun]',
    `random_dates` JSON NULL COMMENT 'Array of Y-m-d date strings',
    `interval_days` TINYINT UNSIGNED NULL COMMENT '1-6 days between instances',
    `interval_start_date` DATE NULL,
    `end_date` DATE NOT NULL COMMENT 'Last date to generate instances up to',
    `created_by` INT UNSIGNED NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add recurrence columns to missions table
ALTER TABLE `missions`
    ADD COLUMN IF NOT EXISTS `recurrence_id` INT UNSIGNED NULL AFTER `updated_at`,
    ADD COLUMN IF NOT EXISTS `recurrence_instance_date` DATE NULL AFTER `recurrence_id`;

-- Add foreign key and index (only if not already present)
ALTER TABLE `missions`
    ADD INDEX IF NOT EXISTS `idx_missions_recurrence` (`recurrence_id`);

-- Note: FK from missions.recurrence_id -> mission_recurrences.id is intentionally
-- handled by the application (ON DELETE SET NULL semantics via app logic)
-- to avoid circular dependency issues on fresh install.
