-- =============================================
-- VolunteerOps - Inventory System Schema
-- Version: 1.0.0 (Phase 1)
-- Date: February 2026
-- =============================================

-- =============================================
-- INVENTORY CATEGORIES TABLE
-- =============================================
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

-- =============================================
-- INVENTORY LOCATIONS TABLE
-- =============================================
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

-- =============================================
-- INVENTORY ITEMS TABLE (Main resource table)
-- =============================================
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

    -- Booking info (denormalized for quick status check)
    `booked_by_user_id` INT UNSIGNED NULL,
    `booked_by_name` VARCHAR(255) NULL,
    `booking_date` DATETIME NULL,
    `expected_return_date` DATETIME NULL,

    -- Metadata
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

-- =============================================
-- INVENTORY BOOKINGS TABLE (Checkout transactions)
-- =============================================
CREATE TABLE IF NOT EXISTS `inventory_bookings` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `item_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,

    -- Cached volunteer info
    `volunteer_name` VARCHAR(255) NULL,
    `volunteer_phone` VARCHAR(20) NULL,
    `volunteer_email` VARCHAR(255) NULL,

    -- Booking details
    `mission_location` VARCHAR(500) NULL,
    `booking_type` ENUM('single','bulk') DEFAULT 'single',
    `expected_return_date` DATE NULL,
    `notes` TEXT NULL,

    -- Status
    `status` ENUM('active','overdue','returned','lost') DEFAULT 'active',

    -- Return info
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

-- =============================================
-- INVENTORY NOTES TABLE (Real-time communication)
-- =============================================
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

-- =============================================
-- INVENTORY FIXED ASSETS TABLE (Long-term loans)
-- =============================================
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

-- =============================================
-- INVENTORY DEPARTMENT ACCESS TABLE (Permissions)
-- =============================================
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

-- =============================================
-- ALTER DEPARTMENTS TABLE (Add inventory fields)
-- =============================================
ALTER TABLE `departments`
    ADD COLUMN IF NOT EXISTS `has_inventory` TINYINT(1) DEFAULT 0,
    ADD COLUMN IF NOT EXISTS `inventory_settings` JSON NULL;

-- Add warehouse_id to users (multi-tenancy: volunteer belongs to a city/warehouse)
ALTER TABLE `users`
    ADD COLUMN IF NOT EXISTS `warehouse_id` INT UNSIGNED NULL AFTER `department_id`,
    ADD INDEX IF NOT EXISTS `idx_users_warehouse` (`warehouse_id`);
-- Note: FK constraint added manually: FOREIGN KEY (warehouse_id) REFERENCES departments(id) ON DELETE SET NULL

-- =============================================
-- DATABASE TRIGGERS
-- =============================================

-- Drop existing triggers if they exist
DROP TRIGGER IF EXISTS `trg_booking_insert`;
DROP TRIGGER IF EXISTS `trg_booking_return`;

DELIMITER $$

-- Auto-update item status on booking creation
CREATE TRIGGER `trg_booking_insert` AFTER INSERT ON `inventory_bookings`
FOR EACH ROW
BEGIN
    IF NEW.status = 'active' THEN
        UPDATE `inventory_items`
        SET `status` = 'booked',
            `booked_by_user_id` = NEW.user_id,
            `booked_by_name` = NEW.volunteer_name,
            `booking_date` = NEW.created_at,
            `expected_return_date` = NEW.expected_return_date
        WHERE `id` = NEW.item_id;
    END IF;
END$$

-- Auto-update item status on return
CREATE TRIGGER `trg_booking_return` AFTER UPDATE ON `inventory_bookings`
FOR EACH ROW
BEGIN
    IF OLD.status = 'active' AND NEW.status = 'returned' THEN
        UPDATE `inventory_items`
        SET `status` = 'available',
            `booked_by_user_id` = NULL,
            `booked_by_name` = NULL,
            `booking_date` = NULL,
            `expected_return_date` = NULL
        WHERE `id` = NEW.item_id;
    END IF;
END$$

DELIMITER ;

-- =============================================
-- SEED DATA - Categories
-- =============================================
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

-- =============================================
-- SEED DATA - Default Locations
-- =============================================
INSERT INTO `inventory_locations` (`name`, `location_type`, `notes`) VALUES
('Κεντρική Αποθήκη', 'warehouse', 'Κύρια αποθήκη υλικών'),
('Αποθήκη Οχημάτων', 'vehicle', 'Αποθήκη εντός οχημάτων'),
('Γραφείο', 'room', 'Γραφείο διοίκησης')
ON DUPLICATE KEY UPDATE `notes` = VALUES(`notes`);
