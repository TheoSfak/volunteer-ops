-- Migration: Add volunteer_documents table
-- Run this once on the database to enable document uploads per volunteer

CREATE TABLE IF NOT EXISTS `volunteer_documents` (
  `id`            INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id`       INT(10) UNSIGNED NOT NULL,
  `label`         VARCHAR(255)     NOT NULL,
  `original_name` VARCHAR(255)     NOT NULL,
  `stored_name`   VARCHAR(255)     NOT NULL,
  `mime_type`     VARCHAR(100)     NOT NULL,
  `file_size`     INT(11)          NOT NULL DEFAULT 0,
  `uploaded_by`   INT(10) UNSIGNED NOT NULL,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_vd_user_id` (`user_id`),
  CONSTRAINT `fk_vd_user`     FOREIGN KEY (`user_id`)     REFERENCES `users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_vd_uploader` FOREIGN KEY (`uploaded_by`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
