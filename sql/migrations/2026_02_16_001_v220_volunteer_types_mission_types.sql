-- Migration: v2.2.0 - Volunteer Types & Dynamic Mission Types
-- Date: 2026-02-16
-- Description: Adds volunteer_type to users, creates mission_types table,
--              adds mission_type_id to missions with data migration
-- Note: Each statement is executed individually by the migration runner.
--        Errors on individual statements are logged but do not stop the migration.

-- 1. Volunteer Type column on users table
ALTER TABLE `users` ADD COLUMN `volunteer_type` ENUM('TRAINEE_RESCUER','RESCUER') NOT NULL DEFAULT 'RESCUER' AFTER `role`;

ALTER TABLE `users` ADD INDEX `idx_volunteer_type` (`volunteer_type`);

-- 2. Mission Types table
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

-- 3. Seed default mission types
INSERT INTO `mission_types` (`id`, `name`, `description`, `color`, `icon`, `sort_order`) VALUES (1, 'Εθελοντική', 'Γενική εθελοντική αποστολή', 'primary', 'bi-people', 1) ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

INSERT INTO `mission_types` (`id`, `name`, `description`, `color`, `icon`, `sort_order`) VALUES (2, 'Υγειονομική', 'Υγειονομική κάλυψη και πρώτες βοήθειες', 'danger', 'bi-heart-pulse', 2) ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

INSERT INTO `mission_types` (`id`, `name`, `description`, `color`, `icon`, `sort_order`) VALUES (3, 'Εκπαιδευτική', 'Εκπαιδευτική αποστολή και ασκήσεις', 'info', 'bi-mortarboard', 3) ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

INSERT INTO `mission_types` (`id`, `name`, `description`, `color`, `icon`, `sort_order`) VALUES (4, 'Διασωστική', 'Επιχείρηση διάσωσης και αντιμετώπιση κινδύνων', 'warning', 'bi-shield-exclamation', 4) ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- 4. Add mission_type_id to missions table
ALTER TABLE `missions` ADD COLUMN `mission_type_id` INT UNSIGNED DEFAULT 1 AFTER `status`;

-- 5. Migrate existing data from type ENUM to mission_type_id
UPDATE `missions` SET `mission_type_id` = 2 WHERE `type` = 'MEDICAL';

UPDATE `missions` SET `mission_type_id` = 1 WHERE `mission_type_id` IS NULL OR `mission_type_id` = 0;
