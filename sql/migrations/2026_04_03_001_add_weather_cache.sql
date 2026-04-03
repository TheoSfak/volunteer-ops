-- Migration: Add weather_cache table (v3.62.6)
-- Created: 2026-04-03

CREATE TABLE IF NOT EXISTS `weather_cache` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `mission_id` INT UNSIGNED NOT NULL,
    `weather_json` TEXT NOT NULL,
    `fetched_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_weather_mission` (`mission_id`),
    FOREIGN KEY (`mission_id`) REFERENCES `missions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
