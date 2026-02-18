-- Migration: Create inventory_shelf_items table
-- For the "Υλικά Ραφιού" feature - consumable items with expiry tracking

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

-- Add setting for shelf expiry reminder threshold
INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES ('shelf_expiry_reminder_days', '30');
