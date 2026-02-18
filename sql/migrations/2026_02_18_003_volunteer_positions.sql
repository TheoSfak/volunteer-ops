-- Migration: Volunteer Positions (organizational roles/titles)
-- Adds volunteer_positions table and position_id column on users

CREATE TABLE IF NOT EXISTS `volunteer_positions` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(100) NOT NULL,
  `color` VARCHAR(20) DEFAULT 'secondary',
  `icon` VARCHAR(50) NULL,
  `description` TEXT NULL,
  `is_active` TINYINT(1) DEFAULT 1,
  `sort_order` INT DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE users ADD COLUMN IF NOT EXISTS position_id INT UNSIGNED NULL AFTER volunteer_type;

-- Add FK only if not already exists (safe to run multiple times)
SET @fk_exists = (
  SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
  AND CONSTRAINT_NAME = 'fk_user_position'
);
SET @sql = IF(@fk_exists = 0,
  'ALTER TABLE users ADD CONSTRAINT fk_user_position FOREIGN KEY (position_id) REFERENCES volunteer_positions(id) ON DELETE SET NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Seed default positions
INSERT IGNORE INTO `volunteer_positions` (id, name, color, icon, sort_order) VALUES
  (1, 'Υπεύθυνος Τμήματος',   'primary', 'bi-person-lines-fill', 1),
  (2, 'Υπεύθυνος Γραμματείας','info',    'bi-envelope-paper',    2),
  (3, 'Εκπαιδευτής',          'success', 'bi-mortarboard',       3),
  (4, 'Ταμίας',               'warning', 'bi-cash-coin',         4);
