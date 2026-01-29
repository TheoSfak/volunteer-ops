-- VolunteerOps Clean Database v1.3.0
-- UTF-8 encoded with correct Greek characters

SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- =============================================
-- ACHIEVEMENTS TABLE
-- =============================================

DROP TABLE IF EXISTS `achievements`;
CREATE TABLE `achievements` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `icon` varchar(255) NOT NULL DEFAULT 'bi-award',
  `color` varchar(255) NOT NULL DEFAULT 'primary',
  `category` varchar(255) NOT NULL,
  `threshold` int(11) DEFAULT NULL,
  `points_reward` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `achievements_code_unique` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `achievements` (`id`, `code`, `name`, `description`, `icon`, `color`, `category`, `threshold`, `points_reward`, `is_active`, `sort_order`, `created_at`, `updated_at`) VALUES
(1, 'first_shift', 'Πρώτη Βάρδια', 'Ολοκλήρωσες την πρώτη σου βάρδια!', 'bi-star-fill', 'warning', 'milestone', 1, 50, 1, 0, NOW(), NOW()),
(2, 'hours_50', '50 Ώρες Εθελοντισμού', 'Συμπλήρωσες 50 ώρες εθελοντικής προσφοράς!', 'bi-clock-fill', 'info', 'hours', 50, 100, 1, 0, NOW(), NOW()),
(3, 'hours_100', '100 Ώρες Εθελοντισμού', 'Συμπλήρωσες 100 ώρες εθελοντικής προσφοράς! Είσαι αστέρι!', 'bi-award-fill', 'primary', 'hours', 100, 250, 1, 0, NOW(), NOW()),
(4, 'hours_250', '250 Ώρες Εθελοντισμού', 'Συμπλήρωσες 250 ώρες εθελοντικής προσφοράς! Αξιοθαύμαστη προσφορά!', 'bi-trophy-fill', 'success', 'hours', 250, 500, 1, 0, NOW(), NOW()),
(5, 'hours_500', '500 Ώρες Εθελοντισμού', 'Συμπλήρωσες 500 ώρες εθελοντικής προσφοράς! Θρύλος του εθελοντισμού!', 'bi-gem', 'warning', 'hours', 500, 1000, 1, 0, NOW(), NOW()),
(6, 'hours_1000', '1000 Ώρες Εθελοντισμού', 'Συμπλήρωσες 1000 ώρες! Είσαι ήρωας της κοινότητας!', 'bi-diamond-fill', 'danger', 'hours', 1000, 2500, 1, 0, NOW(), NOW()),
(7, 'shifts_10', '10 Βάρδιες', 'Ολοκλήρωσες 10 βάρδιες!', 'bi-calendar-check', 'info', 'shifts', 10, 75, 1, 0, NOW(), NOW()),
(8, 'shifts_25', '25 Βάρδιες', 'Ολοκλήρωσες 25 βάρδιες!', 'bi-calendar2-check', 'primary', 'shifts', 25, 150, 1, 0, NOW(), NOW()),
(9, 'shifts_50', '50 Βάρδιες', 'Ολοκλήρωσες 50 βάρδιες!', 'bi-calendar3', 'success', 'shifts', 50, 300, 1, 0, NOW(), NOW()),
(10, 'shifts_100', '100 Βάρδιες', 'Ολοκλήρωσες 100 βάρδιες! Απίστευτη αφοσίωση!', 'bi-calendar-star', 'warning', 'shifts', 100, 750, 1, 0, NOW(), NOW()),
(11, 'reliable_10', 'Αξιόπιστος', '10 βάρδιες χωρίς καμία ακύρωση!', 'bi-hand-thumbs-up', 'info', 'streak', 10, 100, 1, 0, NOW(), NOW()),
(12, 'reliable_25', 'Πολύ Αξιόπιστος', '25 βάρδιες χωρίς καμία ακύρωση!', 'bi-shield-check', 'primary', 'streak', 25, 250, 1, 0, NOW(), NOW()),
(13, 'reliable_50', 'Υπέρ-Αξιόπιστος', '50 βάρδιες χωρίς καμία ακύρωση! Μπορούμε πάντα να βασιστούμε σε εσένα!', 'bi-shield-fill-check', 'success', 'streak', 50, 500, 1, 0, NOW(), NOW()),
(14, 'weekend_warrior', 'Πολεμιστής Σαββατοκύριακου', 'Ολοκλήρωσες 10 βάρδιες σε Σαββατοκύριακα!', 'bi-sun-fill', 'warning', 'special', 10, 150, 1, 0, NOW(), NOW()),
(15, 'night_owl', 'Νυχτερινή Κουκουβάγια', 'Ολοκλήρωσες 10 νυχτερινές βάρδιες!', 'bi-moon-stars-fill', 'secondary', 'special', 10, 150, 1, 0, NOW(), NOW()),
(16, 'medical_hero', 'Υγειονομικός Ήρωας', 'Ολοκλήρωσες 10 υγειονομικές αποστολές!', 'bi-heart-pulse-fill', 'danger', 'special', 10, 200, 1, 0, NOW(), NOW()),
(17, 'early_adopter', 'Πρωτοπόρος', 'Εγγράφηκες στους πρώτους 100 εθελοντές!', 'bi-rocket-takeoff-fill', 'primary', 'special', 100, 100, 1, 0, NOW(), NOW()),
(18, 'team_player', 'Ομαδικός Παίκτης', 'Συμμετείχες σε 5 αποστολές με 10+ εθελοντές!', 'bi-people-fill', 'success', 'special', 5, 100, 1, 0, NOW(), NOW());

-- =============================================
-- DEPARTMENTS TABLE
-- =============================================

DROP TABLE IF EXISTS `departments`;
CREATE TABLE `departments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `parent_id` bigint(20) unsigned DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `departments_parent_id_index` (`parent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `departments` (`id`, `name`, `description`, `parent_id`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'Κεντρική Διοίκηση', 'Κεντρική διοίκηση και συντονισμός όλων των δράσεων.', NULL, 1, NOW(), NOW()),
(2, 'Τομέας Υγείας', 'Εθελοντικές δράσεις στον τομέα της υγείας και πρώτων βοηθειών.', 1, 1, NOW(), NOW()),
(3, 'Τομέας Περιβάλλοντος', 'Περιβαλλοντικές δράσεις και καθαρισμοί.', 1, 1, NOW(), NOW()),
(4, 'Τομέας Κοινωνικής Μέριμνας', 'Δράσεις κοινωνικής αλληλεγγύης και στήριξης.', 1, 1, NOW(), NOW()),
(5, 'Ομάδα Πρώτων Βοηθειών', 'Εξειδικευμένη ομάδα για παροχή πρώτων βοηθειών σε εκδηλώσεις.', 2, 1, NOW(), NOW()),
(6, 'Ομάδα Αιμοδοσίας', 'Οργάνωση και υποστήριξη εθελοντικών αιμοδοσιών.', 2, 1, NOW(), NOW()),
(7, 'Ομάδα Δασοπροστασίας', 'Εθελοντική δασοπροστασία και δασοπυρόσβεση.', 3, 1, NOW(), NOW()),
(8, 'Ομάδα Καθαρισμού', 'Δράσεις καθαρισμού ακτών, δασών και δημόσιων χώρων.', 3, 1, NOW(), NOW()),
(9, 'Ομάδα Διανομής Τροφίμων', 'Διανομή τροφίμων σε ευπαθείς ομάδες.', 4, 1, NOW(), NOW()),
(10, 'Ομάδα Συνοδείας Ηλικιωμένων', 'Υποστήριξη και συνοδεία ηλικιωμένων.', 4, 1, NOW(), NOW());

-- =============================================
-- SKILLS TABLE
-- =============================================

DROP TABLE IF EXISTS `skills`;
CREATE TABLE `skills` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `category` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `skills` (`id`, `name`, `description`, `category`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'Πρώτες Βοήθειες', 'Γνώση παροχής πρώτων βοηθειών', 'Υγεία', 1, NOW(), NOW()),
(2, 'Οδήγηση', 'Δίπλωμα οδήγησης αυτοκινήτου', 'Γενικά', 1, NOW(), NOW()),
(3, 'Επικοινωνία', 'Ικανότητα επικοινωνίας με κοινό', 'Κοινωνικά', 1, NOW(), NOW()),
(4, 'Τεχνικές Γνώσεις', 'Τεχνικές δεξιότητες και επισκευές', 'Τεχνικά', 1, NOW(), NOW()),
(5, 'Ξένες Γλώσσες', 'Γνώση ξένων γλωσσών', 'Γλώσσες', 1, NOW(), NOW());

-- =============================================
-- USERS TABLE  
-- =============================================

DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('SYSTEM_ADMIN','DEPARTMENT_ADMIN','SHIFT_LEADER','VOLUNTEER') NOT NULL DEFAULT 'VOLUNTEER',
  `department_id` bigint(20) unsigned DEFAULT NULL,
  `phone` varchar(255) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `city` varchar(255) DEFAULT NULL,
  `postal_code` varchar(255) DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `emergency_contact_name` varchar(255) DEFAULT NULL,
  `emergency_contact_phone` varchar(255) DEFAULT NULL,
  `bio` text DEFAULT NULL,
  `avatar` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `total_points` int(11) NOT NULL DEFAULT 0,
  `monthly_points` int(11) NOT NULL DEFAULT 0,
  `remember_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`),
  KEY `users_department_id_foreign` (`department_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Admin user (password: password123)
INSERT INTO `users` (`id`, `name`, `email`, `password`, `role`, `department_id`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'Διαχειριστής Συστήματος', 'admin@volunteerops.gr', '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'SYSTEM_ADMIN', 1, 1, NOW(), NOW());

-- =============================================
-- SETTINGS TABLE
-- =============================================

DROP TABLE IF EXISTS `settings`;
CREATE TABLE `settings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(255) NOT NULL,
  `value` text DEFAULT NULL,
  `type` varchar(255) NOT NULL DEFAULT 'string',
  `group` varchar(255) NOT NULL DEFAULT 'general',
  `description` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `settings_key_unique` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `settings` (`key`, `value`, `type`, `group`, `description`, `created_at`, `updated_at`) VALUES
('app_name', 'VolunteerOps', 'string', 'general', 'Όνομα εφαρμογής', NOW(), NOW()),
('organization_name', 'Εθελοντική Οργάνωση', 'string', 'general', 'Όνομα οργανισμού', NOW(), NOW()),
('contact_email', 'info@volunteerops.gr', 'string', 'general', 'Email επικοινωνίας', NOW(), NOW()),
('contact_phone', '+30 210 1234567', 'string', 'general', 'Τηλέφωνο επικοινωνίας', NOW(), NOW()),
('timezone', 'Europe/Athens', 'string', 'general', 'Ζώνη ώρας', NOW(), NOW()),
('points_per_hour', '10', 'integer', 'gamification', 'Πόντοι ανά ώρα εθελοντισμού', NOW(), NOW()),
('weekend_multiplier', '1.5', 'float', 'gamification', 'Πολλαπλασιαστής Σαββατοκύριακου', NOW(), NOW()),
('night_multiplier', '1.3', 'float', 'gamification', 'Πολλαπλασιαστής νυχτερινής βάρδιας', NOW(), NOW()),
('medical_multiplier', '1.2', 'float', 'gamification', 'Πολλαπλασιαστής υγειονομικής αποστολής', NOW(), NOW());

-- =============================================
-- EMAIL TEMPLATES TABLE
-- =============================================

DROP TABLE IF EXISTS `email_templates`;
CREATE TABLE `email_templates` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `subject` varchar(255) NOT NULL,
  `body` text NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email_templates_code_unique` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `email_templates` (`code`, `name`, `description`, `subject`, `body`, `is_active`, `created_at`, `updated_at`) VALUES
('welcome', 'Καλωσόρισμα Νέου Χρήστη', 'Αποστέλλεται κατά την εγγραφή νέου εθελοντή', 'Καλώς ήρθατε στο {{app_name}}!', '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">\r\n<h2>Καλώς ήρθατε, {{volunteer_name}}!</h2>\r\n<p>Σας ευχαριστούμε που εγγραφήκατε στο {{app_name}}.</p>\r\n<p>Μπορείτε τώρα να συνδεθείτε και να δείτε τις διαθέσιμες αποστολές.</p>\r\n<a href="{{login_url}}" style="background-color: #4f46e5; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px;">Σύνδεση</a>\r\n</div>', 1, NOW(), NOW()),
('participation_approved', 'Έγκριση Συμμετοχής', 'Αποστέλλεται όταν εγκρίνεται αίτηση συμμετοχής', 'Η συμμετοχή σας εγκρίθηκε - {{mission_title}}', '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">\r\n<h2>Η συμμετοχή σας εγκρίθηκε!</h2>\r\n<p>Αγαπητέ/ή {{volunteer_name}},</p>\r\n<p>Η αίτησή σας για τη βάρδια στην αποστολή <strong>{{mission_title}}</strong> εγκρίθηκε.</p>\r\n<p><strong>Ημερομηνία:</strong> {{shift_date}}</p>\r\n<p><strong>Ώρα:</strong> {{shift_time}}</p>\r\n<p><strong>Τοποθεσία:</strong> {{location}}</p>\r\n</div>', 1, NOW(), NOW()),
('participation_rejected', 'Απόρριψη Συμμετοχής', 'Αποστέλλεται όταν απορρίπτεται αίτηση συμμετοχής', 'Ενημέρωση για την αίτησή σας - {{mission_title}}', '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">\r\n<p>Αγαπητέ/ή {{volunteer_name}},</p>\r\n<p>Δυστυχώς, η αίτησή σας για τη βάρδια στην αποστολή <strong>{{mission_title}}</strong> δεν μπόρεσε να εγκριθεί.</p>\r\n<p><strong>Λόγος:</strong> {{rejection_reason}}</p>\r\n<p>Σας ενθαρρύνουμε να δηλώσετε συμμετοχή σε άλλες διαθέσιμες βάρδιες.</p>\r\n</div>', 1, NOW(), NOW()),
('shift_reminder', 'Υπενθύμιση Βάρδιας', 'Αποστέλλεται πριν από προγραμματισμένη βάρδια', 'Υπενθύμιση: Βάρδια σε {{hours_until}} ώρες', '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">\r\n<h2>Υπενθύμιση Βάρδιας</h2>\r\n<p>Αγαπητέ/ή {{volunteer_name}},</p>\r\n<p>Σας υπενθυμίζουμε ότι έχετε βάρδια σε <strong>{{hours_until}} ώρες</strong>.</p>\r\n<p><strong>Αποστολή:</strong> {{mission_title}}</p>\r\n<p><strong>Ημερομηνία:</strong> {{shift_date}}</p>\r\n<p><strong>Ώρα:</strong> {{shift_time}}</p>\r\n<p><strong>Τοποθεσία:</strong> {{location}}</p>\r\n</div>', 1, NOW(), NOW()),
('new_mission', 'Νέα Αποστολή', 'Αποστέλλεται όταν δημιουργείται νέα αποστολή', 'Νέα Αποστολή: {{mission_title}}', '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">\r\n<h2>Νέα Αποστολή Διαθέσιμη!</h2>\r\n<p>Αγαπητέ/ή {{volunteer_name}},</p>\r\n<p>Μια νέα αποστολή είναι τώρα διαθέσιμη και περιμένει τη συμμετοχή σας!</p>\r\n<h3>{{mission_title}}</h3>\r\n<p>{{mission_description}}</p>\r\n<p><strong>Έναρξη:</strong> {{start_date}}</p>\r\n<p><strong>Τοποθεσία:</strong> {{location}}</p>\r\n<a href="{{mission_url}}" style="background-color: #4f46e5; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px;">Δήλωση Συμμετοχής</a>\r\n</div>', 1, NOW(), NOW());

-- =============================================
-- OTHER REQUIRED TABLES (STRUCTURE ONLY)
-- =============================================

DROP TABLE IF EXISTS `missions`;
CREATE TABLE `missions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `department_id` bigint(20) unsigned NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `type` enum('VOLUNTEER','MEDICAL') NOT NULL DEFAULT 'VOLUNTEER',
  `status` enum('DRAFT','OPEN','CLOSED','COMPLETED','CANCELED') NOT NULL DEFAULT 'DRAFT',
  `location` varchar(255) DEFAULT NULL,
  `location_details` text DEFAULT NULL,
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `start_datetime` datetime NOT NULL,
  `end_datetime` datetime NOT NULL,
  `requirements` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `is_urgent` tinyint(1) NOT NULL DEFAULT 0,
  `cancellation_reason` text DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `missions_department_id_foreign` (`department_id`),
  KEY `missions_status_index` (`status`),
  KEY `missions_type_index` (`type`),
  KEY `missions_created_by_foreign` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `shifts`;
CREATE TABLE `shifts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `mission_id` bigint(20) unsigned NOT NULL,
  `title` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `start_time` datetime NOT NULL,
  `end_time` datetime NOT NULL,
  `max_capacity` int(11) NOT NULL DEFAULT 10,
  `current_count` int(11) NOT NULL DEFAULT 0,
  `leader_id` bigint(20) unsigned DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('DRAFT','OPEN','CLOSED','COMPLETED','CANCELED') NOT NULL DEFAULT 'OPEN',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `shifts_mission_id_foreign` (`mission_id`),
  KEY `shifts_leader_id_foreign` (`leader_id`),
  KEY `shifts_status_index` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `participation_requests`;
CREATE TABLE `participation_requests` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `shift_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `status` enum('PENDING','APPROVED','REJECTED','CANCELED_BY_USER','CANCELED_BY_ADMIN') NOT NULL DEFAULT 'PENDING',
  `notes` text DEFAULT NULL,
  `admin_notes` text DEFAULT NULL,
  `reviewed_by` bigint(20) unsigned DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `check_in_time` timestamp NULL DEFAULT NULL,
  `check_out_time` timestamp NULL DEFAULT NULL,
  `actual_hours` decimal(5,2) DEFAULT NULL,
  `attendance_notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `participation_requests_shift_id_foreign` (`shift_id`),
  KEY `participation_requests_user_id_foreign` (`user_id`),
  KEY `participation_requests_status_index` (`status`),
  KEY `participation_requests_reviewed_by_foreign` (`reviewed_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `volunteer_points`;
CREATE TABLE `volunteer_points` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `points` int(11) NOT NULL,
  `reason` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `shift_id` bigint(20) unsigned DEFAULT NULL,
  `achievement_id` bigint(20) unsigned DEFAULT NULL,
  `awarded_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `volunteer_points_user_id_foreign` (`user_id`),
  KEY `volunteer_points_shift_id_foreign` (`shift_id`),
  KEY `volunteer_points_achievement_id_foreign` (`achievement_id`),
  KEY `volunteer_points_awarded_by_foreign` (`awarded_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `achievement_user`;
CREATE TABLE `achievement_user` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `achievement_id` bigint(20) unsigned NOT NULL,
  `awarded_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `achievement_user_unique` (`user_id`, `achievement_id`),
  KEY `achievement_user_achievement_id_foreign` (`achievement_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `notifications`;
CREATE TABLE `notifications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `type` varchar(255) NOT NULL,
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `data` text DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `read_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `notifications_user_id_foreign` (`user_id`),
  KEY `notifications_is_read_index` (`is_read`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `audit_logs`;
CREATE TABLE `audit_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `action` varchar(255) NOT NULL,
  `entity_type` varchar(255) NOT NULL,
  `entity_id` bigint(20) unsigned DEFAULT NULL,
  `old_values` text DEFAULT NULL,
  `new_values` text DEFAULT NULL,
  `ip_address` varchar(255) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `audit_logs_user_id_index` (`user_id`),
  KEY `audit_logs_action_index` (`action`),
  KEY `audit_logs_entity_type_entity_id_index` (`entity_type`,`entity_id`),
  KEY `audit_logs_created_at_index` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `files`;
CREATE TABLE `files` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `path` varchar(255) NOT NULL,
  `mime_type` varchar(255) NOT NULL,
  `size` bigint(20) unsigned NOT NULL,
  `uploaded_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `files_uploaded_by_foreign` (`uploaded_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `documents`;
CREATE TABLE `documents` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `category` enum('GENERAL','MISSION','CERT') NOT NULL DEFAULT 'GENERAL',
  `visibility` enum('PUBLIC','ADMINS','PRIVATE') NOT NULL DEFAULT 'PUBLIC',
  `file_id` bigint(20) unsigned DEFAULT NULL,
  `mission_id` bigint(20) unsigned DEFAULT NULL,
  `department_id` bigint(20) unsigned DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `documents_file_id_foreign` (`file_id`),
  KEY `documents_category_index` (`category`),
  KEY `documents_visibility_index` (`visibility`),
  KEY `documents_mission_id_index` (`mission_id`),
  KEY `documents_department_id_index` (`department_id`),
  KEY `documents_created_by_index` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `skill_user`;
CREATE TABLE `skill_user` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `skill_id` bigint(20) unsigned NOT NULL,
  `level` enum('BEGINNER','INTERMEDIATE','ADVANCED','EXPERT') NOT NULL DEFAULT 'BEGINNER',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `skill_user_unique` (`user_id`, `skill_id`),
  KEY `skill_user_skill_id_foreign` (`skill_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `personal_access_tokens`;
CREATE TABLE `personal_access_tokens` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tokenable_type` varchar(255) NOT NULL,
  `tokenable_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `token` varchar(64) NOT NULL,
  `abilities` text DEFAULT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS `migrations`;
CREATE TABLE `migrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `migrations` (`migration`, `batch`) VALUES
('2024_01_01_000001_create_departments_table', 1),
('2024_01_01_000002_create_users_table', 1),
('2024_01_01_000003_create_missions_table', 1),
('2024_01_01_000004_create_shifts_table', 1),
('2024_01_01_000005_create_participation_requests_table', 1),
('2024_01_01_000006_create_files_table', 1),
('2024_01_01_000007_create_documents_table', 1),
('2024_01_01_000008_create_notifications_table', 1),
('2024_01_01_000009_create_audit_logs_table', 1),
('2024_01_01_000010_create_skills_table', 1),
('2024_01_01_000011_create_skill_user_table', 1),
('2024_01_01_000012_create_achievements_table', 1),
('2024_01_01_000013_create_volunteer_points_table', 1),
('2024_01_01_000014_create_achievement_user_table', 1),
('2024_01_01_000015_create_settings_table', 1),
('2024_01_01_000016_create_email_templates_table', 1),
('2024_01_01_000017_create_personal_access_tokens_table', 1),
('2024_01_01_000020_add_attendance_fields_to_participation_requests', 1),
('2024_01_01_000021_add_cancellation_reason_to_missions', 1);

DROP TABLE IF EXISTS `volunteer_yearly_stats`;
CREATE TABLE `volunteer_yearly_stats` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `year` int(11) NOT NULL,
  `total_hours` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total_shifts` int(11) NOT NULL DEFAULT 0,
  `total_points` int(11) NOT NULL DEFAULT 0,
  `missions_participated` int(11) NOT NULL DEFAULT 0,
  `achievements_earned` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `volunteer_yearly_stats_user_year_unique` (`user_id`, `year`),
  KEY `volunteer_yearly_stats_year_index` (`year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Add foreign key constraints
ALTER TABLE `departments` ADD CONSTRAINT `departments_parent_id_foreign` FOREIGN KEY (`parent_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL;
ALTER TABLE `users` ADD CONSTRAINT `users_department_id_foreign` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL;
ALTER TABLE `missions` ADD CONSTRAINT `missions_department_id_foreign` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE CASCADE;
ALTER TABLE `missions` ADD CONSTRAINT `missions_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;
ALTER TABLE `shifts` ADD CONSTRAINT `shifts_mission_id_foreign` FOREIGN KEY (`mission_id`) REFERENCES `missions` (`id`) ON DELETE CASCADE;
ALTER TABLE `shifts` ADD CONSTRAINT `shifts_leader_id_foreign` FOREIGN KEY (`leader_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;
ALTER TABLE `participation_requests` ADD CONSTRAINT `participation_requests_shift_id_foreign` FOREIGN KEY (`shift_id`) REFERENCES `shifts` (`id`) ON DELETE CASCADE;
ALTER TABLE `participation_requests` ADD CONSTRAINT `participation_requests_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
ALTER TABLE `participation_requests` ADD CONSTRAINT `participation_requests_reviewed_by_foreign` FOREIGN KEY (`reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;
ALTER TABLE `volunteer_points` ADD CONSTRAINT `volunteer_points_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
ALTER TABLE `volunteer_points` ADD CONSTRAINT `volunteer_points_shift_id_foreign` FOREIGN KEY (`shift_id`) REFERENCES `shifts` (`id`) ON DELETE SET NULL;
ALTER TABLE `volunteer_points` ADD CONSTRAINT `volunteer_points_achievement_id_foreign` FOREIGN KEY (`achievement_id`) REFERENCES `achievements` (`id`) ON DELETE SET NULL;
ALTER TABLE `volunteer_points` ADD CONSTRAINT `volunteer_points_awarded_by_foreign` FOREIGN KEY (`awarded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;
ALTER TABLE `achievement_user` ADD CONSTRAINT `achievement_user_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
ALTER TABLE `achievement_user` ADD CONSTRAINT `achievement_user_achievement_id_foreign` FOREIGN KEY (`achievement_id`) REFERENCES `achievements` (`id`) ON DELETE CASCADE;
ALTER TABLE `notifications` ADD CONSTRAINT `notifications_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
ALTER TABLE `audit_logs` ADD CONSTRAINT `audit_logs_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;
ALTER TABLE `files` ADD CONSTRAINT `files_uploaded_by_foreign` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;
ALTER TABLE `documents` ADD CONSTRAINT `documents_file_id_foreign` FOREIGN KEY (`file_id`) REFERENCES `files` (`id`) ON DELETE SET NULL;
ALTER TABLE `documents` ADD CONSTRAINT `documents_mission_id_foreign` FOREIGN KEY (`mission_id`) REFERENCES `missions` (`id`) ON DELETE SET NULL;
ALTER TABLE `documents` ADD CONSTRAINT `documents_department_id_foreign` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL;
ALTER TABLE `documents` ADD CONSTRAINT `documents_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;
ALTER TABLE `skill_user` ADD CONSTRAINT `skill_user_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
ALTER TABLE `skill_user` ADD CONSTRAINT `skill_user_skill_id_foreign` FOREIGN KEY (`skill_id`) REFERENCES `skills` (`id`) ON DELETE CASCADE;
ALTER TABLE `volunteer_yearly_stats` ADD CONSTRAINT `volunteer_yearly_stats_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

SET FOREIGN_KEY_CHECKS = 1;

SELECT 'Database initialized successfully with correct Greek characters!' AS result;
