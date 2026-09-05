-- Migration: Add volunteer_applications — public "Υποψήφιοι Εθελοντές"
-- candidate new-member form (aithsh.php) + its email templates.
-- Created: 2026-09-05

CREATE TABLE IF NOT EXISTS `volunteer_applications` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `full_name` VARCHAR(100) NOT NULL COMMENT 'Ονοματεπώνυμο',
    `patronymic` VARCHAR(100) NULL COMMENT 'Πατρώνυμο',
    `birth_date` DATE NULL COMMENT 'Ημερομηνία Γέννησης',
    `address` VARCHAR(255) NULL,
    `postal_code` VARCHAR(10) NULL COMMENT 'Τ.Κ.',
    `city` VARCHAR(100) NULL,
    `home_phone` VARCHAR(30) NULL COMMENT 'Τηλέφωνο Οικίας',
    `mobile_phone` VARCHAR(30) NOT NULL COMMENT 'Τηλέφωνο Κινητό',
    `email` VARCHAR(255) NOT NULL,
    `occupation` VARCHAR(150) NULL COMMENT 'Επαγγελματική ιδιότητα',
    `gdpr_consent_at` DATETIME NOT NULL,
    `status` ENUM('NEW','CONTACTED','CONVERTED','REJECTED') NOT NULL DEFAULT 'NEW',
    `admin_notes` TEXT NULL,
    `contacted_at` DATETIME NULL,
    `converted_user_id` INT UNSIGNED NULL,
    `converted_at` DATETIME NULL,
    `rejected_at` DATETIME NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (`converted_user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    INDEX `idx_volunteer_applications_status` (`status`),
    INDEX `idx_volunteer_applications_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `email_templates` (`code`, `name`, `subject`, `body_html`, `description`, `available_variables`)
VALUES (
    'member_application_submitted',
    'Νέα Αίτηση Μέλους (Admin)',
    'Νέα αίτηση υποψηφίου μέλους - {{applicant_name}}',
    '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;"><div style="background:#005596;color:white;padding:20px;text-align:center;"><h1>&#128100; Νέα Αίτηση Υποψηφίου Μέλους</h1></div><div style="padding:30px;background:#fff;"><h2>Γεια σας {{admin_name}},</h2><p>Υποβλήθηκε νέα αίτηση υποψηφίου νέου μέλους.</p><ul><li><strong>Ονοματεπώνυμο:</strong> {{applicant_name}}</li><li><strong>Τηλέφωνο:</strong> {{applicant_phone}}</li><li><strong>Email:</strong> {{applicant_email}}</li></ul><p style="text-align:center;"><a href="{{application_url}}" style="background:#005596;color:white;padding:12px 28px;text-decoration:none;border-radius:5px;">Δείτε την Αίτηση</a></p></div><div style="padding:12px;background:#f8f9fa;text-align:center;font-size:12px;color:#666;">{{app_name}}</div></div>',
    'Αποστέλλεται στους διαχειριστές όταν υποβάλλεται νέα αίτηση υποψηφίου νέου μέλους (aithsh.php)',
    '{{app_name}}, {{admin_name}}, {{applicant_name}}, {{applicant_phone}}, {{applicant_email}}, {{application_url}}'
)
ON DUPLICATE KEY UPDATE updated_at = updated_at;

INSERT INTO `email_templates` (`code`, `name`, `subject`, `body_html`, `description`, `available_variables`)
VALUES (
    'member_application_confirmation',
    'Επιβεβαίωση Αίτησης Μέλους',
    'Λάβαμε την αίτησή σας - {{app_name}}',
    '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;"><div style="background:#005596;color:white;padding:20px;text-align:center;"><h1>&#9989; Λάβαμε την Αίτησή σας</h1></div><div style="padding:30px;background:#fff;"><h2>Γεια σας {{applicant_name}},</h2><p>Σας ευχαριστούμε για το ενδιαφέρον σας να γίνετε μέλος της ομάδας μας. Λάβαμε την αίτησή σας και θα επικοινωνήσουμε μαζί σας σύντομα.</p></div><div style="padding:12px;background:#f8f9fa;text-align:center;font-size:12px;color:#666;">{{app_name}}</div></div>',
    'Αποστέλλεται στον υποψήφιο μόλις υποβάλει την αίτηση νέου μέλους (aithsh.php)',
    '{{app_name}}, {{applicant_name}}'
)
ON DUPLICATE KEY UPDATE updated_at = updated_at;

INSERT INTO `notification_settings` (`code`, `name`, `email_enabled`, `email_template_id`)
SELECT 'member_application_submitted', 'Νέα Αίτηση Μέλους (Admin)', 1, `id`
FROM `email_templates` WHERE `code` = 'member_application_submitted'
ON DUPLICATE KEY UPDATE updated_at = updated_at;

INSERT INTO `notification_settings` (`code`, `name`, `email_enabled`, `email_template_id`)
SELECT 'member_application_confirmation', 'Επιβεβαίωση Αίτησης Μέλους', 1, `id`
FROM `email_templates` WHERE `code` = 'member_application_confirmation'
ON DUPLICATE KEY UPDATE updated_at = updated_at;
