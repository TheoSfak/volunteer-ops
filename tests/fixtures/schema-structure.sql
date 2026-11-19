
/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `achievements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `achievements` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(50) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `category` varchar(50) DEFAULT 'milestone',
  `icon` varchar(50) DEFAULT '?',
  `required_points` int(11) DEFAULT 0,
  `threshold` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=16662 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ai_translation_cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ai_translation_cache` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `source_hash` char(64) NOT NULL COMMENT 'SHA-256 of the pseudonymised source text',
  `lang` varchar(5) NOT NULL,
  `source_text` text NOT NULL COMMENT 'Pseudonymised source, kept so a bad row can be found by reading it',
  `translated_text` text NOT NULL,
  `provider` varchar(32) DEFAULT NULL,
  `model` varchar(64) DEFAULT NULL,
  `used_at` timestamp NULL DEFAULT NULL COMMENT 'Last time this row was served, for pruning',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_translation` (`source_hash`,`lang`),
  KEY `idx_translation_lang` (`lang`,`used_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `announcement_dismissals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `announcement_dismissals` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `announcement_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `dismissed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_announcement_dismissals` (`announcement_id`,`user_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `announcement_dismissals_ibfk_1` FOREIGN KEY (`announcement_id`) REFERENCES `announcements` (`id`) ON DELETE CASCADE,
  CONSTRAINT `announcement_dismissals_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1441 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `announcements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `announcements` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `version` varchar(20) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `body` text NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `created_by` (`created_by`),
  KEY `idx_announcements_active` (`is_active`,`created_at`),
  CONSTRAINT `announcements_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2875 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `audit_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `audit_logs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned DEFAULT NULL,
  `action` varchar(50) NOT NULL,
  `table_name` varchar(100) DEFAULT NULL,
  `record_id` int(10) unsigned DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_audit_user` (`user_id`),
  KEY `idx_audit_table` (`table_name`,`record_id`),
  KEY `idx_audit_action` (`action`),
  KEY `idx_audit_created` (`created_at`),
  KEY `idx_audit_user_created` (`user_id`,`created_at`),
  KEY `idx_audit_table_rec_date` (`table_name`,`record_id`,`created_at`),
  KEY `idx_audit_action_table_date` (`action`,`table_name`,`created_at`),
  CONSTRAINT `audit_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=260491 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `bug_reports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `bug_reports` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `description` text NOT NULL,
  `screenshot` varchar(255) DEFAULT NULL,
  `page_url` varchar(500) DEFAULT NULL,
  `app_version` varchar(20) DEFAULT NULL,
  `status` enum('NEW','IN_REVIEW','RESOLVED','REJECTED') NOT NULL DEFAULT 'NEW',
  `admin_response` text DEFAULT NULL,
  `responded_by` int(10) unsigned DEFAULT NULL,
  `responded_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `responded_by` (`responded_by`),
  KEY `idx_bug_reports_user` (`user_id`),
  KEY `idx_bug_reports_status` (`status`),
  CONSTRAINT `bug_reports_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `bug_reports_ibfk_2` FOREIGN KEY (`responded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `certificate_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `certificate_types` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `default_validity_months` int(10) unsigned DEFAULT NULL COMMENT 'NULL = no expiry',
  `is_required` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `citizen_certificate_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `citizen_certificate_types` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `citizen_certificates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `citizen_certificates` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `certificate_type_id` int(10) unsigned DEFAULT NULL,
  `first_name` varchar(100) NOT NULL COMMENT '???Áa',
  `last_name` varchar(100) NOT NULL COMMENT '?p??et?',
  `phone` varchar(30) DEFAULT NULL COMMENT 'Τηλέφωνο',
  `birth_date` date DEFAULT NULL COMMENT '?Áe??Á???a ?????s??',
  `issue_date` date DEFAULT NULL COMMENT '?Á. ??d?s??',
  `expiry_date` date DEFAULT NULL COMMENT '?Á. ?????',
  `email` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `reminder_sent_3m` tinyint(1) NOT NULL DEFAULT 0,
  `reminder_sent_1m` tinyint(1) NOT NULL DEFAULT 0,
  `reminder_sent_1w` tinyint(1) NOT NULL DEFAULT 0,
  `reminder_sent_expired` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_cc_name` (`last_name`,`first_name`),
  KEY `idx_cc_expiry` (`expiry_date`),
  KEY `idx_cc_type` (`certificate_type_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `citizen_contacts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `citizen_contacts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `citizen_id` int(10) unsigned NOT NULL,
  `contact_date` date NOT NULL,
  `quick_note` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_cc_citizen` (`citizen_id`),
  CONSTRAINT `fk_cc_citizen` FOREIGN KEY (`citizen_id`) REFERENCES `citizens` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `citizens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `citizens` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `first_name_gr` varchar(100) NOT NULL COMMENT '???Áa (????????)',
  `last_name_gr` varchar(100) NOT NULL COMMENT '?p??et? (????????)',
  `first_name_lat` varchar(100) DEFAULT NULL COMMENT '???Áa (?at?????)',
  `last_name_lat` varchar(100) DEFAULT NULL COMMENT '?p??et? (?at?????)',
  `seminar_type` varchar(30) DEFAULT NULL,
  `birth_date` date DEFAULT NULL COMMENT '?Áe??Á???a ?????s??',
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `contact_done` tinyint(1) NOT NULL DEFAULT 0 COMMENT '?p????????a',
  `contact_done_at` datetime DEFAULT NULL COMMENT '?Áe??Á???a ep????????a?',
  `payment_done` tinyint(1) NOT NULL DEFAULT 0 COMMENT '?????Á?',
  `payment_done_at` datetime DEFAULT NULL COMMENT '?Áe??Á???a p????Á??',
  `completed` tinyint(1) NOT NULL DEFAULT 0 COMMENT '??e? ????????se?',
  `completed_at` datetime DEFAULT NULL COMMENT '?Áe??Á???a ????????s??',
  `notes` text DEFAULT NULL,
  `registered_at` date DEFAULT NULL,
  `referral_source` varchar(255) DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_citizens_name_gr` (`last_name_gr`,`first_name_gr`),
  KEY `idx_citizens_email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `complaints`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `complaints` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `mission_id` int(10) unsigned DEFAULT NULL,
  `category` enum('MISSION','EQUIPMENT','BEHAVIOR','ADMIN','OTHER') NOT NULL DEFAULT 'OTHER',
  `priority` enum('LOW','MEDIUM','HIGH') NOT NULL DEFAULT 'MEDIUM',
  `subject` varchar(255) NOT NULL,
  `body` text NOT NULL,
  `status` enum('NEW','IN_REVIEW','RESOLVED','REJECTED') NOT NULL DEFAULT 'NEW',
  `admin_response` text DEFAULT NULL,
  `responded_by` int(10) unsigned DEFAULT NULL,
  `responded_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `responded_by` (`responded_by`),
  KEY `idx_complaint_user` (`user_id`),
  KEY `idx_complaint_status` (`status`),
  KEY `idx_complaint_category` (`category`),
  KEY `idx_complaint_mission` (`mission_id`),
  KEY `idx_complaint_priority` (`priority`),
  KEY `idx_complaint_created` (`created_at`),
  KEY `idx_complaint_user_created` (`user_id`,`created_at`),
  CONSTRAINT `complaints_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `complaints_ibfk_2` FOREIGN KEY (`mission_id`) REFERENCES `missions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `complaints_ibfk_3` FOREIGN KEY (`responded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `custom_role_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `custom_role_permissions` (
  `role_id` int(10) unsigned NOT NULL,
  `page_slug` varchar(100) NOT NULL,
  PRIMARY KEY (`role_id`,`page_slug`),
  CONSTRAINT `fk_crp_role` FOREIGN KEY (`role_id`) REFERENCES `custom_roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `custom_roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `custom_roles` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `color` varchar(7) NOT NULL DEFAULT '#6c757d',
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_custom_roles_name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=198 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `departments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `departments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `has_inventory` tinyint(1) DEFAULT 0,
  `inventory_settings` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`inventory_settings`)),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=40 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `dispatch_eta_cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `dispatch_eta_cache` (
  `dispatch_id` int(10) unsigned NOT NULL,
  `minutes` smallint(5) unsigned NOT NULL,
  `source` enum('osrm','google','straight_line') NOT NULL,
  `mode` enum('foot','vehicle') NOT NULL DEFAULT 'vehicle',
  `ping_lat` decimal(10,8) NOT NULL,
  `ping_lng` decimal(11,8) NOT NULL,
  `ping_created_at` datetime NOT NULL,
  `fetched_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `route_m` int(10) unsigned DEFAULT NULL COMMENT 'Metres of the routed path (not the straight line)',
  PRIMARY KEY (`dispatch_id`),
  CONSTRAINT `dispatch_eta_cache_ibfk_1` FOREIGN KEY (`dispatch_id`) REFERENCES `mission_dispatch_points` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `documents` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `documentable_type` varchar(100) NOT NULL,
  `documentable_id` int(10) unsigned NOT NULL,
  `title` varchar(255) NOT NULL,
  `filename` varchar(255) NOT NULL,
  `filepath` varchar(500) NOT NULL,
  `mime_type` varchar(100) DEFAULT NULL,
  `file_size` int(10) unsigned DEFAULT NULL,
  `uploaded_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `uploaded_by` (`uploaded_by`),
  KEY `idx_documents_type` (`documentable_type`,`documentable_id`),
  CONSTRAINT `documents_ibfk_1` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `email_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `email_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `recipient_email` varchar(255) NOT NULL,
  `subject` varchar(500) NOT NULL,
  `notification_code` varchar(100) DEFAULT NULL,
  `status` enum('SUCCESS','FAILED') NOT NULL,
  `error_message` text DEFAULT NULL,
  `smtp_log` text DEFAULT NULL,
  `smtp_host` varchar(255) DEFAULT NULL,
  `from_email` varchar(255) DEFAULT NULL,
  `sent_by` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_email_logs_status` (`status`),
  KEY `idx_email_logs_recipient` (`recipient_email`),
  KEY `idx_email_logs_created` (`created_at`),
  KEY `idx_email_logs_code` (`notification_code`)
) ENGINE=InnoDB AUTO_INCREMENT=218 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `email_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `email_templates` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(50) NOT NULL,
  `name` varchar(100) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `body_html` text NOT NULL,
  `description` text DEFAULT NULL,
  `available_variables` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=15108 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `exam_attempts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `exam_attempts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `exam_id` int(11) NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `selected_questions_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`selected_questions_json`)),
  `score` int(11) DEFAULT NULL,
  `passed` tinyint(1) DEFAULT 0,
  `time_taken_seconds` int(11) DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_exam_attempt` (`exam_id`,`user_id`),
  KEY `idx_user` (`user_id`),
  CONSTRAINT `fk_exam_attempts_exam` FOREIGN KEY (`exam_id`) REFERENCES `training_exams` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_exam_attempts_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `inventory_bookings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inventory_bookings` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `item_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `volunteer_name` varchar(255) DEFAULT NULL,
  `volunteer_phone` varchar(20) DEFAULT NULL,
  `volunteer_email` varchar(255) DEFAULT NULL,
  `mission_location` varchar(500) DEFAULT NULL,
  `booking_type` enum('single','bulk') DEFAULT 'single',
  `expected_return_date` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('active','overdue','returned','lost') DEFAULT 'active',
  `return_date` datetime DEFAULT NULL,
  `returned_by_user_id` int(10) unsigned DEFAULT NULL,
  `return_notes` text DEFAULT NULL,
  `actual_hours` decimal(8,2) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `returned_by_user_id` (`returned_by_user_id`),
  KEY `idx_status` (`status`),
  KEY `idx_item` (`item_id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_dates` (`created_at`,`return_date`),
  KEY `idx_status_dates` (`status`,`created_at`),
  KEY `idx_inv_book_user_status` (`user_id`,`status`),
  CONSTRAINT `inventory_bookings_ibfk_1` FOREIGN KEY (`item_id`) REFERENCES `inventory_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `inventory_bookings_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `inventory_bookings_ibfk_3` FOREIGN KEY (`returned_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER `trg_booking_insert`

                    AFTER INSERT ON `inventory_bookings`

                    FOR EACH ROW

                      UPDATE `inventory_items`

                      SET `status`              = 'booked',

                          `booked_by_user_id`   = NEW.user_id,

                          `booked_by_name`      = NEW.volunteer_name,

                          `booking_date`        = NEW.created_at,

                          `expected_return_date`= NEW.expected_return_date

                      WHERE `id` = NEW.item_id

                        AND NEW.status = 'active' */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
/*!50003 SET @saved_cs_client      = @@character_set_client */ ;
/*!50003 SET @saved_cs_results     = @@character_set_results */ ;
/*!50003 SET @saved_col_connection = @@collation_connection */ ;
/*!50003 SET character_set_client  = utf8mb4 */ ;
/*!50003 SET character_set_results = utf8mb4 */ ;
/*!50003 SET collation_connection  = utf8mb4_unicode_ci */ ;
/*!50003 SET @saved_sql_mode       = @@sql_mode */ ;
/*!50003 SET sql_mode              = 'NO_ZERO_IN_DATE,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION' */ ;
DELIMITER ;;
/*!50003 CREATE*/ /*!50017 DEFINER=`root`@`localhost`*/ /*!50003 TRIGGER `trg_booking_return`

                    AFTER UPDATE ON `inventory_bookings`

                    FOR EACH ROW

                      UPDATE `inventory_items`

                      SET `status`            = IF(NEW.status IN ('returned','lost') AND OLD.status NOT IN ('returned','lost'), 'available',   `status`),

                          `booked_by_user_id` = IF(NEW.status IN ('returned','lost') AND OLD.status NOT IN ('returned','lost'), NULL,          `booked_by_user_id`),

                          `booked_by_name`    = IF(NEW.status IN ('returned','lost') AND OLD.status NOT IN ('returned','lost'), NULL,          `booked_by_name`),

                          `booking_date`      = IF(NEW.status IN ('returned','lost') AND OLD.status NOT IN ('returned','lost'), NULL,          `booking_date`)

                      WHERE `id` = NEW.item_id */;;
DELIMITER ;
/*!50003 SET sql_mode              = @saved_sql_mode */ ;
/*!50003 SET character_set_client  = @saved_cs_client */ ;
/*!50003 SET character_set_results = @saved_cs_results */ ;
/*!50003 SET collation_connection  = @saved_col_connection */ ;
DROP TABLE IF EXISTS `inventory_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inventory_categories` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `icon` varchar(10) DEFAULT '├░┼©ÔÇ£┬ª',
  `color` varchar(7) DEFAULT '#6c757d',
  `sort_order` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_name` (`name`),
  KEY `idx_active` (`is_active`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `inventory_department_access`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inventory_department_access` (
  `user_id` int(10) unsigned NOT NULL,
  `department_id` int(10) unsigned NOT NULL,
  `access_level` enum('viewer','manager','admin') DEFAULT 'viewer',
  `can_book` tinyint(1) DEFAULT 1,
  `can_manage_items` tinyint(1) DEFAULT 0,
  `can_approve_bookings` tinyint(1) DEFAULT 0,
  `granted_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `granted_by_user_id` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`user_id`,`department_id`),
  KEY `department_id` (`department_id`),
  KEY `granted_by_user_id` (`granted_by_user_id`),
  KEY `idx_access_level` (`access_level`),
  CONSTRAINT `inventory_department_access_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `inventory_department_access_ibfk_2` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `inventory_department_access_ibfk_3` FOREIGN KEY (`granted_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `inventory_fixed_assets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inventory_fixed_assets` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `barcode` varchar(50) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `department_id` int(10) unsigned DEFAULT NULL,
  `status` enum('available','checked_out','retired') DEFAULT 'available',
  `checked_out_to_user_id` int(10) unsigned DEFAULT NULL,
  `checked_out_to_name` varchar(255) DEFAULT NULL,
  `checked_out_phone` varchar(20) DEFAULT NULL,
  `checked_out_at` datetime DEFAULT NULL,
  `checkout_notes` text DEFAULT NULL,
  `purchase_date` date DEFAULT NULL,
  `purchase_cost` decimal(10,2) DEFAULT NULL,
  `serial_number` varchar(100) DEFAULT NULL,
  `condition_notes` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_barcode` (`barcode`),
  KEY `checked_out_to_user_id` (`checked_out_to_user_id`),
  KEY `idx_status` (`status`),
  KEY `idx_department` (`department_id`),
  CONSTRAINT `inventory_fixed_assets_ibfk_1` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `inventory_fixed_assets_ibfk_2` FOREIGN KEY (`checked_out_to_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `inventory_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inventory_items` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `barcode` varchar(50) NOT NULL,
  `name` varchar(255) NOT NULL,
  `registration_date` date DEFAULT NULL,
  `registration_number` varchar(50) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `category_id` int(10) unsigned DEFAULT NULL,
  `department_id` int(10) unsigned DEFAULT NULL,
  `location_id` int(10) unsigned DEFAULT NULL,
  `location_notes` text DEFAULT NULL,
  `status` enum('available','booked','maintenance','damaged') DEFAULT 'available',
  `condition_notes` text DEFAULT NULL,
  `booked_by_user_id` int(10) unsigned DEFAULT NULL,
  `booked_by_name` varchar(255) DEFAULT NULL,
  `booking_date` datetime DEFAULT NULL,
  `expected_return_date` datetime DEFAULT NULL,
  `quantity` int(11) DEFAULT 1,
  `image_url` varchar(500) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_barcode` (`barcode`),
  KEY `booked_by_user_id` (`booked_by_user_id`),
  KEY `created_by` (`created_by`),
  KEY `idx_status` (`status`),
  KEY `idx_department` (`department_id`),
  KEY `idx_category` (`category_id`),
  KEY `idx_location` (`location_id`),
  KEY `idx_active` (`is_active`),
  KEY `idx_dept_status` (`department_id`,`status`),
  KEY `idx_inv_items_dept_active_status` (`department_id`,`is_active`,`status`),
  FULLTEXT KEY `idx_search` (`name`,`description`),
  CONSTRAINT `inventory_items_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `inventory_categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `inventory_items_ibfk_2` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `inventory_items_ibfk_3` FOREIGN KEY (`location_id`) REFERENCES `inventory_locations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `inventory_items_ibfk_4` FOREIGN KEY (`booked_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `inventory_items_ibfk_5` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=51 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `inventory_kit_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inventory_kit_items` (
  `kit_id` int(10) unsigned NOT NULL,
  `item_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`kit_id`,`item_id`),
  KEY `item_id` (`item_id`),
  CONSTRAINT `inventory_kit_items_ibfk_1` FOREIGN KEY (`kit_id`) REFERENCES `inventory_kits` (`id`) ON DELETE CASCADE,
  CONSTRAINT `inventory_kit_items_ibfk_2` FOREIGN KEY (`item_id`) REFERENCES `inventory_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `inventory_kits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inventory_kits` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `barcode` varchar(50) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `department_id` int(10) unsigned DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `barcode` (`barcode`),
  KEY `department_id` (`department_id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `inventory_kits_ibfk_1` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `inventory_kits_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `inventory_locations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inventory_locations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `department_id` int(10) unsigned DEFAULT NULL,
  `location_type` enum('warehouse','vehicle','room','other') DEFAULT 'warehouse',
  `address` text DEFAULT NULL,
  `capacity` int(11) DEFAULT NULL,
  `current_items_count` int(11) DEFAULT 0,
  `notes` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_department` (`department_id`),
  KEY `idx_type` (`location_type`),
  CONSTRAINT `inventory_locations_ibfk_1` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `inventory_notes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inventory_notes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `item_id` int(10) unsigned NOT NULL,
  `item_name` varchar(255) DEFAULT NULL,
  `note_type` enum('booking','return','maintenance','damage','general') DEFAULT 'general',
  `content` text NOT NULL,
  `priority` enum('low','medium','high','urgent') DEFAULT 'medium',
  `status` enum('pending','acknowledged','in_progress','resolved','archived') DEFAULT 'pending',
  `status_history` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`status_history`)),
  `related_booking_id` int(10) unsigned DEFAULT NULL,
  `assigned_to_user_id` int(10) unsigned DEFAULT NULL,
  `created_by_user_id` int(10) unsigned DEFAULT NULL,
  `created_by_name` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `resolved_at` datetime DEFAULT NULL,
  `resolved_by_user_id` int(10) unsigned DEFAULT NULL,
  `resolution_notes` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `related_booking_id` (`related_booking_id`),
  KEY `created_by_user_id` (`created_by_user_id`),
  KEY `assigned_to_user_id` (`assigned_to_user_id`),
  KEY `resolved_by_user_id` (`resolved_by_user_id`),
  KEY `idx_status` (`status`),
  KEY `idx_priority` (`priority`),
  KEY `idx_item` (`item_id`),
  KEY `idx_type` (`note_type`),
  CONSTRAINT `inventory_notes_ibfk_1` FOREIGN KEY (`item_id`) REFERENCES `inventory_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `inventory_notes_ibfk_2` FOREIGN KEY (`related_booking_id`) REFERENCES `inventory_bookings` (`id`) ON DELETE SET NULL,
  CONSTRAINT `inventory_notes_ibfk_3` FOREIGN KEY (`created_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `inventory_notes_ibfk_4` FOREIGN KEY (`assigned_to_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `inventory_notes_ibfk_5` FOREIGN KEY (`resolved_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `inventory_shelf_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `inventory_shelf_items` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `shelf` varchar(100) DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `department_id` int(10) unsigned DEFAULT NULL,
  `sort_order` int(11) DEFAULT 0,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `created_by` (`created_by`),
  KEY `idx_expiry` (`expiry_date`),
  KEY `idx_shelf` (`shelf`),
  KEY `idx_department` (`department_id`),
  CONSTRAINT `inventory_shelf_items_ibfk_1` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `inventory_shelf_items_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mesh_nodes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mesh_nodes` (
  `node_num` int(10) unsigned NOT NULL COMMENT 'Meshtastic node number, 32 bits (shown as !570dee8c)',
  `user_id` int(10) unsigned NOT NULL COMMENT 'Whoever carries the node: its positions are theirs',
  `label` varchar(60) DEFAULT NULL,
  `created_by` int(10) unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`node_num`),
  KEY `idx_mesh_nodes_user` (`user_id`),
  CONSTRAINT `mesh_nodes_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mesh_packets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mesh_packets` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `node_num` int(10) unsigned NOT NULL,
  `packet_id` int(10) unsigned NOT NULL COMMENT 'The sender''s packet id; with node_num it names one transmission',
  `mission_id` int(10) unsigned DEFAULT NULL,
  `snr` decimal(5,2) DEFAULT NULL,
  `rssi` smallint(6) DEFAULT NULL,
  `hops` tinyint(3) unsigned DEFAULT NULL,
  `outcome` varchar(16) NOT NULL DEFAULT '' COMMENT 'recorded, unbound, blurred, refused...',
  `heard_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_mesh_packet` (`node_num`,`packet_id`),
  KEY `idx_mesh_packets_node` (`node_num`,`id`),
  KEY `idx_mesh_packets_heard` (`heard_at`)
) ENGINE=InnoDB AUTO_INCREMENT=1571 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mission_action_room_participants`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mission_action_room_participants` (
  `mission_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `added_by` int(10) unsigned DEFAULT NULL COMMENT 'Who ticked GPS for them; NULL for the rows created by this backfill',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_gps_error` enum('denied','unavailable','timeout','imprecise','implausible','unknown','mock','location_off','power_save','stopped_by_user') DEFAULT NULL COMMENT 'Last reason this volunteer produced no position; NULL = none outstanding',
  `last_gps_error_at` timestamp NULL DEFAULT NULL COMMENT 'When that failure was reported',
  PRIMARY KEY (`mission_id`,`user_id`),
  KEY `user_id` (`user_id`),
  KEY `added_by` (`added_by`),
  CONSTRAINT `mission_action_room_participants_ibfk_1` FOREIGN KEY (`mission_id`) REFERENCES `missions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mission_action_room_participants_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mission_action_room_participants_ibfk_3` FOREIGN KEY (`added_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mission_activity_notes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mission_activity_notes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `mission_id` int(10) unsigned NOT NULL,
  `author_id` int(10) unsigned DEFAULT NULL,
  `note` text NOT NULL,
  `staff_only` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `author_id` (`author_id`),
  KEY `idx_activity_note_mission` (`mission_id`,`created_at`),
  CONSTRAINT `mission_activity_notes_ibfk_1` FOREIGN KEY (`mission_id`) REFERENCES `missions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mission_activity_notes_ibfk_2` FOREIGN KEY (`author_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mission_ai_assessments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mission_ai_assessments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `mission_id` int(10) unsigned NOT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'Validated assessment, pseudonymised exactly as the provider returned it' CHECK (json_valid(`payload`)),
  `pseudonym_map` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'MELOS-n => real name. Server-side only; never sent anywhere' CHECK (json_valid(`pseudonym_map`)),
  `provider` varchar(32) NOT NULL,
  `model` varchar(64) NOT NULL,
  `prompt_version` smallint(5) unsigned NOT NULL DEFAULT 1,
  `digest_hash` char(64) DEFAULT NULL COMMENT 'SHA-256 of the digest sent, so a re-run on unchanged data is recognisable',
  `dropped_claims` smallint(5) unsigned NOT NULL DEFAULT 0 COMMENT 'Findings deleted for citing evidence that did not resolve',
  `duration_ms` int(10) unsigned DEFAULT NULL,
  `tokens_prompt` int(10) unsigned DEFAULT NULL,
  `tokens_completion` int(10) unsigned DEFAULT NULL,
  `generated_by` int(10) unsigned DEFAULT NULL,
  `generated_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_ai_assessment_mission` (`mission_id`),
  KEY `generated_by` (`generated_by`),
  CONSTRAINT `mission_ai_assessments_ibfk_1` FOREIGN KEY (`mission_id`) REFERENCES `missions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mission_ai_assessments_ibfk_2` FOREIGN KEY (`generated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mission_annotations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mission_annotations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `mission_id` int(10) unsigned NOT NULL,
  `type` enum('freehand','arrow','text') NOT NULL,
  `geo` text NOT NULL,
  `label` varchar(255) DEFAULT NULL,
  `created_by` int(10) unsigned NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `created_by` (`created_by`),
  KEY `idx_annotation_mission` (`mission_id`),
  CONSTRAINT `mission_annotations_ibfk_1` FOREIGN KEY (`mission_id`) REFERENCES `missions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mission_annotations_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mission_arrival_dwell`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mission_arrival_dwell` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `mission_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `target_kind` enum('dispatch','waypoint','sector') NOT NULL,
  `target_id` int(10) unsigned NOT NULL,
  `first_hit_at` datetime NOT NULL,
  `last_hit_at` datetime NOT NULL,
  `hits` smallint(5) unsigned NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_arrival_dwell` (`target_kind`,`target_id`,`user_id`),
  KEY `idx_arrival_dwell_user` (`mission_id`,`user_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `mission_arrival_dwell_ibfk_1` FOREIGN KEY (`mission_id`) REFERENCES `missions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mission_arrival_dwell_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2589 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mission_arrival_prompts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mission_arrival_prompts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `mission_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `target_kind` enum('dispatch','waypoint') NOT NULL,
  `target_id` int(10) unsigned NOT NULL,
  `lat` decimal(10,7) NOT NULL,
  `lng` decimal(10,7) NOT NULL,
  `accuracy_m` decimal(8,2) DEFAULT NULL,
  `distance_m` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_arrival_prompt` (`target_kind`,`target_id`,`user_id`),
  KEY `idx_arrival_prompt_mission` (`mission_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `mission_arrival_prompts_ibfk_1` FOREIGN KEY (`mission_id`) REFERENCES `missions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mission_arrival_prompts_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1527 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mission_assistant_checkpoints`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mission_assistant_checkpoints` (
  `mission_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `seen_at` timestamp NOT NULL DEFAULT current_timestamp() COMMENT 'Last explicit press of «Το είδα» - NOT advanced by merely opening the panel',
  PRIMARY KEY (`mission_id`,`user_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `mission_assistant_checkpoints_ibfk_1` FOREIGN KEY (`mission_id`) REFERENCES `missions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mission_assistant_checkpoints_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mission_board_links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mission_board_links` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `mission_id` int(10) unsigned NOT NULL,
  `token` char(64) NOT NULL,
  `label` varchar(120) DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `expires_at` datetime DEFAULT NULL COMMENT 'NULL = until the mission closes',
  `revoked_at` datetime DEFAULT NULL,
  `last_viewed_at` datetime DEFAULT NULL,
  `view_count` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_board_token` (`token`),
  KEY `idx_board_links_mission` (`mission_id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `mission_board_links_ibfk_1` FOREIGN KEY (`mission_id`) REFERENCES `missions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mission_board_links_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=26 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mission_certificates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mission_certificates` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `mission_id` int(10) unsigned NOT NULL,
  `recipient_user_id` int(10) unsigned NOT NULL,
  `language` enum('el','en') NOT NULL DEFAULT 'el',
  `certificate_number` varchar(40) DEFAULT NULL,
  `citation_text` text DEFAULT NULL,
  `issued_by` int(10) unsigned DEFAULT NULL,
  `issued_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_mission_certificate_number` (`certificate_number`),
  KEY `issued_by` (`issued_by`),
  KEY `idx_mission_certificates_mission` (`mission_id`),
  KEY `idx_mission_certificates_recipient` (`recipient_user_id`),
  CONSTRAINT `mission_certificates_ibfk_1` FOREIGN KEY (`mission_id`) REFERENCES `missions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mission_certificates_ibfk_2` FOREIGN KEY (`recipient_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mission_certificates_ibfk_3` FOREIGN KEY (`issued_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mission_chat_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mission_chat_messages` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `mission_id` int(10) unsigned NOT NULL,
  `team_id` int(10) unsigned DEFAULT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `message` text NOT NULL,
  `client_uuid` varchar(64) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_chat_client_uuid` (`mission_id`,`user_id`,`client_uuid`),
  KEY `user_id` (`user_id`),
  KEY `fk_mission_chat_team` (`team_id`),
  KEY `idx_chat_room` (`mission_id`,`team_id`,`id`),
  CONSTRAINT `fk_mission_chat_team` FOREIGN KEY (`team_id`) REFERENCES `mission_teams` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mission_chat_messages_ibfk_1` FOREIGN KEY (`mission_id`) REFERENCES `missions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mission_chat_messages_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7910 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mission_command_post_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mission_command_post_log` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `mission_id` int(10) unsigned NOT NULL,
  `action` enum('set','moved','note','cleared','follow','unfollow') NOT NULL,
  `lat` decimal(10,8) DEFAULT NULL,
  `lng` decimal(11,8) DEFAULT NULL,
  `moved_m` int(10) unsigned DEFAULT NULL COMMENT 'How far a move took it',
  `note` varchar(255) DEFAULT NULL,
  `user_id` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `via` enum('hand','follow') DEFAULT NULL COMMENT 'A move: by command, or arrived with the followed device',
  `followed_user_id` int(10) unsigned DEFAULT NULL COMMENT 'follow/unfollow, and a move with the device: whose device',
  PRIMARY KEY (`id`),
  KEY `idx_cp_log_mission` (`mission_id`,`created_at`),
  KEY `user_id` (`user_id`),
  KEY `fk_cp_log_followed` (`followed_user_id`),
  CONSTRAINT `fk_cp_log_followed` FOREIGN KEY (`followed_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `mission_command_post_log_ibfk_1` FOREIGN KEY (`mission_id`) REFERENCES `missions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mission_command_post_log_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=13326 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mission_command_posts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mission_command_posts` (
  `mission_id` int(10) unsigned NOT NULL,
  `lat` decimal(10,8) NOT NULL,
  `lng` decimal(11,8) NOT NULL,
  `note` varchar(255) DEFAULT NULL COMMENT 'How to find it on the spot, e.g. which vehicle',
  `last_action` enum('set','moved') NOT NULL DEFAULT 'set' COMMENT 'How it got to where it is now',
  `placed_at` datetime NOT NULL COMMENT 'When it got to where it is now',
  `placed_by` int(10) unsigned DEFAULT NULL,
  `follow_user_id` int(10) unsigned DEFAULT NULL COMMENT 'Follows this participant''s GPS; NULL = a fixed point',
  `fix_at` datetime DEFAULT NULL COMMENT 'Time of the last fix taken from that device',
  `anchor_lat` decimal(10,8) DEFAULT NULL COMMENT 'Where everybody was last told it is',
  `anchor_lng` decimal(11,8) DEFAULT NULL,
  `cand_lat` decimal(10,8) DEFAULT NULL COMMENT 'Where the device has stood still since cand_since',
  `cand_lng` decimal(11,8) DEFAULT NULL,
  `cand_since` datetime DEFAULT NULL,
  PRIMARY KEY (`mission_id`),
  KEY `placed_by` (`placed_by`),
  KEY `idx_cp_follow_user` (`follow_user_id`),
  CONSTRAINT `fk_cp_follow_user` FOREIGN KEY (`follow_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `mission_command_posts_ibfk_1` FOREIGN KEY (`mission_id`) REFERENCES `missions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mission_command_posts_ibfk_2` FOREIGN KEY (`placed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mission_debriefs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mission_debriefs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `mission_id` int(10) unsigned NOT NULL,
  `submitted_by` int(10) unsigned NOT NULL,
  `summary` text NOT NULL,
  `objectives_met` enum('YES','PARTIAL','NO') NOT NULL,
  `incidents` text DEFAULT NULL,
  `equipment_issues` text DEFAULT NULL,
  `rating` tinyint(3) unsigned NOT NULL CHECK (`rating` between 1 and 5),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_mission_debrief` (`mission_id`),
  KEY `submitted_by` (`submitted_by`),
  CONSTRAINT `mission_debriefs_ibfk_1` FOREIGN KEY (`mission_id`) REFERENCES `missions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mission_debriefs_ibfk_2` FOREIGN KEY (`submitted_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mission_dispatch_acks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mission_dispatch_acks` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `dispatch_id` int(10) unsigned NOT NULL,
  `team_id` int(10) unsigned DEFAULT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_dispatch_user` (`dispatch_id`,`user_id`),
  KEY `team_id` (`team_id`),
  KEY `user_id` (`user_id`),
  KEY `idx_dispatch_ack` (`dispatch_id`),
  CONSTRAINT `mission_dispatch_acks_ibfk_1` FOREIGN KEY (`dispatch_id`) REFERENCES `mission_dispatch_points` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mission_dispatch_acks_ibfk_2` FOREIGN KEY (`team_id`) REFERENCES `mission_teams` (`id`) ON DELETE SET NULL,
  CONSTRAINT `mission_dispatch_acks_ibfk_3` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4330 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mission_dispatch_points`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mission_dispatch_points` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `mission_id` int(10) unsigned NOT NULL,
  `team_id` int(10) unsigned DEFAULT NULL,
  `ring_index` tinyint(3) unsigned DEFAULT NULL,
  `type` enum('point','polygon') NOT NULL,
  `geo` text NOT NULL,
  `label` varchar(255) DEFAULT NULL,
  `created_by` int(10) unsigned NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `incident_id` int(10) unsigned DEFAULT NULL COMMENT 'The incident this dispatch was sent to; NULL = an ordinary dispatch',
  `map_point_id` int(10) unsigned DEFAULT NULL,
  `travel_mode` enum('foot','vehicle') DEFAULT NULL COMMENT 'How the team was told to go; NULL = its own transport',
  PRIMARY KEY (`id`),
  KEY `team_id` (`team_id`),
  KEY `created_by` (`created_by`),
  KEY `idx_dispatch_mission` (`mission_id`,`team_id`),
  KEY `idx_dispatch_incident` (`incident_id`),
  KEY `idx_dispatch_map_point` (`map_point_id`),
  CONSTRAINT `fk_dispatch_incident` FOREIGN KEY (`incident_id`) REFERENCES `mission_incidents` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_dispatch_map_point` FOREIGN KEY (`map_point_id`) REFERENCES `mission_map_points` (`id`) ON DELETE SET NULL,
  CONSTRAINT `mission_dispatch_points_ibfk_1` FOREIGN KEY (`mission_id`) REFERENCES `missions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mission_dispatch_points_ibfk_2` FOREIGN KEY (`team_id`) REFERENCES `mission_teams` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mission_dispatch_points_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=40264 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mission_dispatch_progress`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mission_dispatch_progress` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `dispatch_id` int(10) unsigned NOT NULL,
  `team_id` int(10) unsigned DEFAULT NULL COMMENT 'The team that moved; NULL for a volunteer with no team',
  `scope_key` varchar(20) NOT NULL COMMENT 't<team_id>, or u<user_id> for a volunteer with no team',
  `departed_at` datetime DEFAULT NULL,
  `departed_by` int(10) unsigned DEFAULT NULL,
  `arrived_at` datetime DEFAULT NULL,
  `arrived_by` int(10) unsigned DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `completed_by` int(10) unsigned DEFAULT NULL,
  `completed_note` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_dispatch_progress_scope` (`dispatch_id`,`scope_key`),
  KEY `team_id` (`team_id`),
  KEY `departed_by` (`departed_by`),
  KEY `arrived_by` (`arrived_by`),
  KEY `completed_by` (`completed_by`),
  CONSTRAINT `mission_dispatch_progress_ibfk_1` FOREIGN KEY (`dispatch_id`) REFERENCES `mission_dispatch_points` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mission_dispatch_progress_ibfk_2` FOREIGN KEY (`team_id`) REFERENCES `mission_teams` (`id`) ON DELETE SET NULL,
  CONSTRAINT `mission_dispatch_progress_ibfk_3` FOREIGN KEY (`departed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `mission_dispatch_progress_ibfk_4` FOREIGN KEY (`arrived_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `mission_dispatch_progress_ibfk_5` FOREIGN KEY (`completed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=12959 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mission_dispatch_receipts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mission_dispatch_receipts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `dispatch_id` int(10) unsigned NOT NULL,
  `team_id` int(10) unsigned DEFAULT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_dispatch_user` (`dispatch_id`,`user_id`),
  KEY `team_id` (`team_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `mission_dispatch_receipts_ibfk_1` FOREIGN KEY (`dispatch_id`) REFERENCES `mission_dispatch_points` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mission_dispatch_receipts_ibfk_2` FOREIGN KEY (`team_id`) REFERENCES `mission_teams` (`id`) ON DELETE SET NULL,
  CONSTRAINT `mission_dispatch_receipts_ibfk_3` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5565 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mission_dispatch_withdrawals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mission_dispatch_withdrawals` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `mission_id` int(10) unsigned NOT NULL,
  `dispatch_type` enum('point','polygon') NOT NULL,
  `label` varchar(255) DEFAULT NULL,
  `team_id` int(10) unsigned DEFAULT NULL,
  `sent_at` datetime NOT NULL,
  `sent_by` int(10) unsigned DEFAULT NULL,
  `withdrawn_at` datetime NOT NULL,
  `withdrawn_by` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_dispatch_withdrawn_mission` (`mission_id`,`withdrawn_at`),
  KEY `team_id` (`team_id`),
  KEY `sent_by` (`sent_by`),
  KEY `withdrawn_by` (`withdrawn_by`),
  CONSTRAINT `mission_dispatch_withdrawals_ibfk_1` FOREIGN KEY (`mission_id`) REFERENCES `missions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mission_dispatch_withdrawals_ibfk_2` FOREIGN KEY (`team_id`) REFERENCES `mission_teams` (`id`) ON DELETE SET NULL,
  CONSTRAINT `mission_dispatch_withdrawals_ibfk_3` FOREIGN KEY (`sent_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `mission_dispatch_withdrawals_ibfk_4` FOREIGN KEY (`withdrawn_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2041 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mission_guest_debriefs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mission_guest_debriefs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `mission_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `rating` tinyint(3) unsigned NOT NULL,
  `what_went_well` text DEFAULT NULL,
  `what_could_improve` text DEFAULT NULL,
  `additional_notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_mission_guest_debrief` (`mission_id`,`user_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `mission_guest_debriefs_ibfk_1` FOREIGN KEY (`mission_id`) REFERENCES `missions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mission_guest_debriefs_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mission_highlight_videos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mission_highlight_videos` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `mission_id` int(10) unsigned NOT NULL,
  `stored_name` varchar(255) NOT NULL,
  `orientation` enum('landscape','portrait') NOT NULL DEFAULT 'landscape',
  `music_track_id` varchar(50) DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_mission` (`mission_id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `mission_highlight_videos_ibfk_1` FOREIGN KEY (`mission_id`) REFERENCES `missions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mission_highlight_videos_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=120 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mission_incidents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mission_incidents` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `mission_id` int(10) unsigned NOT NULL,
  `reporter_id` int(10) unsigned NOT NULL,
  `team_id` int(10) unsigned DEFAULT NULL,
  `lat` decimal(10,7) DEFAULT NULL,
  `lng` decimal(10,7) DEFAULT NULL,
  `accuracy_m` decimal(8,2) DEFAULT NULL COMMENT 'Device-reported uncertainty radius of the fix, metres; NULL = not reported',
  `incident_type` enum('trauma','cardiac','respiratory','fainting','burn','allergic','heat_cold','other') NOT NULL,
  `severity` enum('low','medium','high','critical') NOT NULL DEFAULT 'medium',
  `is_unknown_patient` tinyint(1) NOT NULL DEFAULT 0,
  `patient_name` varchar(255) DEFAULT NULL,
  `estimated_age` varchar(50) DEFAULT NULL,
  `gender` enum('male','female','unknown') DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `notes` text DEFAULT NULL COMMENT 'Staff-only context of what happened — never shown in the PDF/stats, only the live command panel',
  `acknowledged_at` timestamp NULL DEFAULT NULL,
  `acknowledged_by` int(10) unsigned DEFAULT NULL,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `resolved_by` int(10) unsigned DEFAULT NULL,
  `outcome` enum('stayed_on_site','transported','declined','deceased','walked_away') DEFAULT NULL,
  `outcome_location` varchar(255) DEFAULT NULL COMMENT 'Where transported to, only meaningful when outcome=transported',
  `outcome_note` text DEFAULT NULL,
  `outcome_seen_at` timestamp NULL DEFAULT NULL COMMENT 'When the reporter dismissed the resolved popup; NULL = still owed one',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `reporter_id` (`reporter_id`),
  KEY `team_id` (`team_id`),
  KEY `acknowledged_by` (`acknowledged_by`),
  KEY `resolved_by` (`resolved_by`),
  KEY `idx_incident_mission` (`mission_id`,`resolved_at`),
  CONSTRAINT `mission_incidents_ibfk_1` FOREIGN KEY (`mission_id`) REFERENCES `missions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mission_incidents_ibfk_2` FOREIGN KEY (`reporter_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mission_incidents_ibfk_3` FOREIGN KEY (`team_id`) REFERENCES `mission_teams` (`id`) ON DELETE SET NULL,
  CONSTRAINT `mission_incidents_ibfk_4` FOREIGN KEY (`acknowledged_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `mission_incidents_ibfk_5` FOREIGN KEY (`resolved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=5840 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mission_live_streams`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mission_live_streams` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `mission_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `order_id` int(10) unsigned DEFAULT NULL,
  `status` enum('requested','live','ended') NOT NULL DEFAULT 'requested',
  `started_at` timestamp NULL DEFAULT NULL,
  `ended_at` timestamp NULL DEFAULT NULL,
  `end_reason` enum('command','volunteer','timeout','disconnect','mission_closed') DEFAULT NULL,
  `ended_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `order_id` (`order_id`),
  KEY `ended_by` (`ended_by`),
  KEY `idx_live_mission_status` (`mission_id`,`status`),
  CONSTRAINT `mission_live_streams_ibfk_1` FOREIGN KEY (`mission_id`) REFERENCES `missions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mission_live_streams_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mission_live_streams_ibfk_3` FOREIGN KEY (`order_id`) REFERENCES `mission_orders` (`id`) ON DELETE SET NULL,
  CONSTRAINT `mission_live_streams_ibfk_4` FOREIGN KEY (`ended_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mission_locate_links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mission_locate_links` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `mission_id` int(10) unsigned NOT NULL,
  `token` char(64) NOT NULL,
  `label` varchar(120) DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `expires_at` datetime DEFAULT NULL COMMENT 'NULL = until the mission closes',
  `revoked_at` datetime DEFAULT NULL,
  `consented_at` datetime DEFAULT NULL,
  `last_lat` decimal(10,8) DEFAULT NULL,
  `last_lng` decimal(11,8) DEFAULT NULL,
  `last_accuracy_m` smallint(5) unsigned DEFAULT NULL,
  `last_updated_at` datetime DEFAULT NULL,
  `update_count` int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_locate_token` (`token`),
  KEY `idx_locate_links_mission` (`mission_id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `mission_locate_links_ibfk_1` FOREIGN KEY (`mission_id`) REFERENCES `missions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mission_locate_links_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=131 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mission_map_point_routes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mission_map_point_routes` (
  `mission_id` int(10) unsigned NOT NULL,
  `mode` enum('foot','vehicle') NOT NULL,
  `filter_kind` varchar(10) NOT NULL DEFAULT 'all',
  `total_points` int(10) unsigned NOT NULL DEFAULT 0,
  `used_points` int(10) unsigned NOT NULL DEFAULT 0,
  `meters` int(10) unsigned NOT NULL DEFAULT 0,
  `minutes` int(10) unsigned NOT NULL DEFAULT 0,
  `unrouted` int(10) unsigned NOT NULL DEFAULT 0,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `legs` mediumtext NOT NULL COMMENT 'JSON: from_id, to_id, meters, minutes, points[] per leg',
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`mission_id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `mission_map_point_routes_ibfk_1` FOREIGN KEY (`mission_id`) REFERENCES `missions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mission_map_point_routes_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mission_map_points`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mission_map_points` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `mission_id` int(10) unsigned NOT NULL,
  `name` varchar(120) NOT NULL,
  `lat` decimal(10,8) NOT NULL,
  `lng` decimal(11,8) NOT NULL,
  `elevation_m` smallint(6) DEFAULT NULL COMMENT 'Altitude in metres',
  `access` enum('foot','vehicle','both') DEFAULT NULL COMMENT 'How to get there',
  `note` text DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_map_points_mission` (`mission_id`),
  KEY `created_by` (`created_by`),
  KEY `updated_by` (`updated_by`),
  CONSTRAINT `mission_map_points_ibfk_1` FOREIGN KEY (`mission_id`) REFERENCES `missions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mission_map_points_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `mission_map_points_ibfk_3` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=497547 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mission_mci`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mission_mci` (
  `mission_id` int(10) unsigned NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 0,
  `activated_at` datetime DEFAULT NULL,
  `activated_by` int(10) unsigned DEFAULT NULL,
  `deactivated_at` datetime DEFAULT NULL,
  `deactivated_by` int(10) unsigned DEFAULT NULL,
  `ccp_lat` decimal(10,7) DEFAULT NULL COMMENT 'Casualty collection point',
  `ccp_lng` decimal(10,7) DEFAULT NULL,
  `green_lat` decimal(10,7) DEFAULT NULL COMMENT 'Where the walking wounded are sent',
  `green_lng` decimal(10,7) DEFAULT NULL,
  `sizeup` text DEFAULT NULL COMMENT 'JSON: hazards, access, casualties_estimate, resources, ekab_notified (+ notes)',
  `sizeup_at` datetime DEFAULT NULL,
  `sizeup_by` int(10) unsigned DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`mission_id`),
  KEY `activated_by` (`activated_by`),
  KEY `deactivated_by` (`deactivated_by`),
  KEY `fk_mci_sizeup_by` (`sizeup_by`),
  CONSTRAINT `fk_mci_sizeup_by` FOREIGN KEY (`sizeup_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `mission_mci_ibfk_1` FOREIGN KEY (`mission_id`) REFERENCES `missions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mission_mci_ibfk_2` FOREIGN KEY (`activated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `mission_mci_ibfk_3` FOREIGN KEY (`deactivated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mission_mci_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mission_mci_log` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `mission_id` int(10) unsigned NOT NULL,
  `action` enum('activated','deactivated','ccp_set','green_set','sizeup') NOT NULL,
  `user_id` int(10) unsigned DEFAULT NULL,
  `lat` decimal(10,7) DEFAULT NULL,
  `lng` decimal(10,7) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_mci_log_mission` (`mission_id`,`created_at`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `mission_mci_log_ibfk_1` FOREIGN KEY (`mission_id`) REFERENCES `missions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mission_mci_log_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=7965 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mission_missing_persons`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mission_missing_persons` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `mission_id` int(10) unsigned NOT NULL,
  `full_name` varchar(255) NOT NULL,
  `age` smallint(5) unsigned DEFAULT NULL,
  `subject_category` varchar(40) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `clothing_description` text DEFAULT NULL,
  `vehicle` varchar(255) DEFAULT NULL,
  `photo` varchar(255) DEFAULT NULL,
  `last_seen_label` varchar(255) DEFAULT NULL,
  `last_seen_lat` decimal(10,7) DEFAULT NULL,
  `last_seen_lng` decimal(10,7) DEFAULT NULL,
  `last_seen_at` datetime DEFAULT NULL,
  `disappearance_circumstances` text DEFAULT NULL,
  `likely_direction` varchar(255) DEFAULT NULL,
  `witness_accounts` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_missing_person_mission` (`mission_id`),
  CONSTRAINT `mission_missing_persons_ibfk_1` FOREIGN KEY (`mission_id`) REFERENCES `missions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mission_order_declines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mission_order_declines` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `mission_id` int(10) unsigned NOT NULL,
  `target_kind` enum('order','dispatch','route','sector') NOT NULL,
  `target_id` int(10) unsigned NOT NULL,
  `scope_key` varchar(20) NOT NULL COMMENT 'u<user> for a person, t<team> for a team, all for a whole route',
  `team_id` int(10) unsigned DEFAULT NULL,
  `reason` enum('unsafe','no_access','busy','injury','other') NOT NULL,
  `note` varchar(500) DEFAULT NULL,
  `declined_by` int(10) unsigned DEFAULT NULL,
  `declined_at` datetime NOT NULL,
  `active` tinyint(1) DEFAULT 1 COMMENT '1 while it stands, NULL once resolved',
  `resolved_at` datetime DEFAULT NULL,
  `resolved_by` int(10) unsigned DEFAULT NULL,
  `resolution` enum('withdrawn','progress','reassigned') DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_order_decline_active` (`target_kind`,`target_id`,`scope_key`,`active`),
  KEY `idx_order_decline_mission` (`mission_id`,`active`),
  KEY `team_id` (`team_id`),
  KEY `declined_by` (`declined_by`),
  KEY `resolved_by` (`resolved_by`),
  CONSTRAINT `mission_order_declines_ibfk_1` FOREIGN KEY (`mission_id`) REFERENCES `missions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mission_order_declines_ibfk_2` FOREIGN KEY (`team_id`) REFERENCES `mission_teams` (`id`) ON DELETE SET NULL,
  CONSTRAINT `mission_order_declines_ibfk_3` FOREIGN KEY (`declined_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `mission_order_declines_ibfk_4` FOREIGN KEY (`resolved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=5529 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mission_order_recipients`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mission_order_recipients` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `order_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `team_id` int(10) unsigned DEFAULT NULL,
  `acknowledged_at` timestamp NULL DEFAULT NULL,
  `fulfilled_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_order_user` (`order_id`,`user_id`),
  KEY `user_id` (`user_id`),
  KEY `team_id` (`team_id`),
  CONSTRAINT `mission_order_recipients_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `mission_orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mission_order_recipients_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mission_order_recipients_ibfk_3` FOREIGN KEY (`team_id`) REFERENCES `mission_teams` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=67962 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mission_orders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mission_orders` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `mission_id` int(10) unsigned NOT NULL,
  `order_type` enum('location','photo','video','task','message','return_to_base','route','charge_phone','live','speak') NOT NULL,
  `task_text` text DEFAULT NULL,
  `created_by` int(10) unsigned NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `created_by` (`created_by`),
  KEY `idx_order_mission` (`mission_id`,`order_type`),
  CONSTRAINT `mission_orders_ibfk_1` FOREIGN KEY (`mission_id`) REFERENCES `missions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mission_orders_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=41996 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mission_par_checkins`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mission_par_checkins` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `mission_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `last_ok_at` datetime NOT NULL,
  `last_prompted_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_par_mission_user` (`mission_id`,`user_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `mission_par_checkins_ibfk_1` FOREIGN KEY (`mission_id`) REFERENCES `missions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mission_par_checkins_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mission_photos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mission_photos` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `mission_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `media_type` enum('photo','video') NOT NULL DEFAULT 'photo',
  `stored_name` varchar(255) NOT NULL,
  `thumb_stored_name` varchar(255) DEFAULT NULL,
  `original_name` varchar(255) DEFAULT NULL,
  `mime_type` varchar(100) NOT NULL,
  `file_size` int(10) unsigned NOT NULL,
  `lat` decimal(10,7) DEFAULT NULL,
  `lng` decimal(10,7) DEFAULT NULL,
  `accuracy_m` decimal(8,2) DEFAULT NULL COMMENT 'Device-reported uncertainty radius of the fix, metres; NULL = not reported',
  `route_waypoint_id` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `poi_id` int(10) unsigned DEFAULT NULL,
  `poi_note` text DEFAULT NULL,
  `client_uuid` varchar(64) DEFAULT NULL,
  `order_id` int(10) unsigned DEFAULT NULL,
  `file_purged_at` timestamp NULL DEFAULT NULL COMMENT 'When the media file was removed by the retention sweep; NULL = the file is still there',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_photo_client_uuid` (`mission_id`,`user_id`,`client_uuid`),
  KEY `user_id` (`user_id`),
  KEY `idx_photo_mission` (`mission_id`,`created_at`),
  KEY `fk_photo_route_waypoint` (`route_waypoint_id`),
  KEY `idx_photo_poi` (`poi_id`),
  KEY `idx_photo_order` (`order_id`),
  CONSTRAINT `fk_photo_route_waypoint` FOREIGN KEY (`route_waypoint_id`) REFERENCES `mission_route_waypoints` (`id`) ON DELETE SET NULL,
  CONSTRAINT `mission_photos_ibfk_1` FOREIGN KEY (`mission_id`) REFERENCES `missions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mission_photos_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mission_photos_ibfk_3` FOREIGN KEY (`poi_id`) REFERENCES `mission_points_of_interest` (`id`) ON DELETE SET NULL,
  CONSTRAINT `mission_photos_ibfk_4` FOREIGN KEY (`order_id`) REFERENCES `mission_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3737 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mission_place_cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mission_place_cache` (
  `mission_id` int(10) unsigned NOT NULL,
  `lat` decimal(10,8) NOT NULL COMMENT 'The pin this was looked up for',
  `lng` decimal(11,8) NOT NULL,
  `county` varchar(150) DEFAULT NULL COMMENT 'OSM name, e.g. Περιφερειακή Ενότητα Ηρακλείου; NULL = no answer',
  `county_en` varchar(150) DEFAULT NULL,
  `looked_up_at` datetime NOT NULL,
  PRIMARY KEY (`mission_id`),
  CONSTRAINT `mission_place_cache_ibfk_1` FOREIGN KEY (`mission_id`) REFERENCES `missions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mission_points_of_interest`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mission_points_of_interest` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `mission_id` int(10) unsigned NOT NULL,
  `lat` decimal(10,7) NOT NULL,
  `lng` decimal(10,7) NOT NULL,
  `checked_at` timestamp NULL DEFAULT NULL,
  `checked_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `checked_by` (`checked_by`),
  KEY `idx_poi_mission` (`mission_id`,`checked_at`),
  CONSTRAINT `mission_points_of_interest_ibfk_1` FOREIGN KEY (`mission_id`) REFERENCES `missions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mission_points_of_interest_ibfk_2` FOREIGN KEY (`checked_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mission_presence`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mission_presence` (
  `mission_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `last_seen_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`mission_id`,`user_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `mission_presence_ibfk_1` FOREIGN KEY (`mission_id`) REFERENCES `missions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mission_presence_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mission_recurrences`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mission_recurrences` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `type` enum('weekly','random_days','interval') NOT NULL,
  `weekdays` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'ISO weekday numbers [1=Mon..7=Sun]' CHECK (json_valid(`weekdays`)),
  `random_dates` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Array of Y-m-d date strings' CHECK (json_valid(`random_dates`)),
  `interval_days` tinyint(3) unsigned DEFAULT NULL COMMENT '1-6 days between instances',
  `interval_start_date` date DEFAULT NULL,
  `end_date` date NOT NULL COMMENT 'Last date to generate instances up to',
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `mission_recurrences_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mission_restricted_area_breaches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mission_restricted_area_breaches` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `mission_id` int(10) unsigned NOT NULL,
  `restricted_area_id` int(10) unsigned DEFAULT NULL,
  `area_label` varchar(255) NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `team_id` int(10) unsigned DEFAULT NULL,
  `lat` decimal(10,7) NOT NULL,
  `lng` decimal(10,7) NOT NULL,
  `exited_at` timestamp NULL DEFAULT NULL,
  `acknowledged_at` timestamp NULL DEFAULT NULL,
  `acknowledged_by` int(10) unsigned DEFAULT NULL,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `resolved_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `team_id` (`team_id`),
  KEY `acknowledged_by` (`acknowledged_by`),
  KEY `resolved_by` (`resolved_by`),
  KEY `idx_breach_mission` (`mission_id`,`resolved_at`),
  KEY `idx_breach_open_lookup` (`restricted_area_id`,`user_id`,`exited_at`,`resolved_at`),
  CONSTRAINT `mission_restricted_area_breaches_ibfk_1` FOREIGN KEY (`mission_id`) REFERENCES `missions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mission_restricted_area_breaches_ibfk_2` FOREIGN KEY (`restricted_area_id`) REFERENCES `mission_restricted_areas` (`id`) ON DELETE SET NULL,
  CONSTRAINT `mission_restricted_area_breaches_ibfk_3` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mission_restricted_area_breaches_ibfk_4` FOREIGN KEY (`team_id`) REFERENCES `mission_teams` (`id`) ON DELETE SET NULL,
  CONSTRAINT `mission_restricted_area_breaches_ibfk_5` FOREIGN KEY (`acknowledged_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `mission_restricted_area_breaches_ibfk_6` FOREIGN KEY (`resolved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=726 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mission_restricted_areas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mission_restricted_areas` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `mission_id` int(10) unsigned NOT NULL,
  `label` varchar(255) NOT NULL,
  `kind` enum('danger','cliff','forbidden') NOT NULL DEFAULT 'danger',
  `shape` enum('polygon','line') NOT NULL DEFAULT 'polygon',
  `geo` text NOT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `created_by` (`created_by`),
  KEY `idx_restricted_area_mission` (`mission_id`),
  CONSTRAINT `mission_restricted_areas_ibfk_1` FOREIGN KEY (`mission_id`) REFERENCES `missions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mission_restricted_areas_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=4965 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mission_route_members`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mission_route_members` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `route_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `added_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_route_user` (`route_id`,`user_id`),
  KEY `idx_route_members_user` (`user_id`),
  CONSTRAINT `mission_route_members_ibfk_1` FOREIGN KEY (`route_id`) REFERENCES `mission_routes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mission_route_members_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=13860 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mission_route_progress`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mission_route_progress` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `waypoint_id` int(10) unsigned NOT NULL,
  `route_id` int(10) unsigned NOT NULL,
  `team_id` int(10) unsigned DEFAULT NULL,
  `departed_at` timestamp NULL DEFAULT NULL,
  `departed_by` int(10) unsigned DEFAULT NULL,
  `arrived_at` timestamp NULL DEFAULT NULL,
  `arrived_by` int(10) unsigned DEFAULT NULL,
  `arrived_lat` decimal(10,7) DEFAULT NULL,
  `arrived_lng` decimal(10,7) DEFAULT NULL,
  `arrived_accuracy_m` decimal(8,2) DEFAULT NULL,
  `arrived_distance_m` int(10) unsigned DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `completed_by` int(10) unsigned DEFAULT NULL,
  `skipped_at` timestamp NULL DEFAULT NULL,
  `skipped_by` int(10) unsigned DEFAULT NULL,
  `skip_reason` varchar(255) DEFAULT NULL,
  `note` text DEFAULT NULL,
  `out_of_sequence` tinyint(1) NOT NULL DEFAULT 0,
  `reported_at` timestamp NULL DEFAULT NULL,
  `cant_at` timestamp NULL DEFAULT NULL COMMENT '«Δεν μπορώ»: the team cannot send what the point asks for',
  `cant_by` int(10) unsigned DEFAULT NULL,
  `cant_reason` enum('unsafe','device','not_allowed','other') DEFAULT NULL,
  `cant_note` varchar(500) DEFAULT NULL,
  `unlocked_at` timestamp NULL DEFAULT NULL COMMENT '«Ξεκλείδωμα»: command closed the point without what was missing',
  `unlocked_by` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_waypoint` (`waypoint_id`),
  KEY `team_id` (`team_id`),
  KEY `departed_by` (`departed_by`),
  KEY `arrived_by` (`arrived_by`),
  KEY `completed_by` (`completed_by`),
  KEY `skipped_by` (`skipped_by`),
  KEY `idx_progress_route` (`route_id`),
  KEY `fk_route_progress_cant_by` (`cant_by`),
  KEY `fk_route_progress_unlocked_by` (`unlocked_by`),
  CONSTRAINT `fk_route_progress_cant_by` FOREIGN KEY (`cant_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_route_progress_unlocked_by` FOREIGN KEY (`unlocked_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `mission_route_progress_ibfk_1` FOREIGN KEY (`waypoint_id`) REFERENCES `mission_route_waypoints` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mission_route_progress_ibfk_2` FOREIGN KEY (`route_id`) REFERENCES `mission_routes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mission_route_progress_ibfk_3` FOREIGN KEY (`team_id`) REFERENCES `mission_teams` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mission_route_progress_ibfk_4` FOREIGN KEY (`departed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `mission_route_progress_ibfk_5` FOREIGN KEY (`arrived_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `mission_route_progress_ibfk_6` FOREIGN KEY (`completed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `mission_route_progress_ibfk_7` FOREIGN KEY (`skipped_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=22884 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mission_route_waypoints`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mission_route_waypoints` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `route_id` int(10) unsigned NOT NULL,
  `seq` smallint(5) unsigned NOT NULL,
  `lat` decimal(10,7) NOT NULL,
  `lng` decimal(10,7) NOT NULL,
  `label` varchar(255) DEFAULT NULL,
  `instructions` text DEFAULT NULL,
  `dwell_minutes` smallint(5) unsigned DEFAULT NULL,
  `require_photo` tinyint(1) NOT NULL DEFAULT 0,
  `require_video` tinyint(1) NOT NULL DEFAULT 0,
  `require_note` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_route_seq` (`route_id`,`seq`),
  CONSTRAINT `mission_route_waypoints_ibfk_1` FOREIGN KEY (`route_id`) REFERENCES `mission_routes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=23471 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mission_routes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mission_routes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `mission_id` int(10) unsigned NOT NULL,
  `team_id` int(10) unsigned DEFAULT NULL,
  `ring_index` tinyint(3) unsigned DEFAULT NULL,
  `order_id` int(10) unsigned DEFAULT NULL,
  `title` varchar(255) DEFAULT NULL,
  `is_closed_loop` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` int(10) unsigned NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `completed_at` timestamp NULL DEFAULT NULL,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `cancelled_by` int(10) unsigned DEFAULT NULL,
  `cancel_reason` varchar(255) DEFAULT NULL,
  `travel_mode` enum('foot','vehicle') DEFAULT NULL COMMENT 'How the team was told to go; NULL = its own transport',
  PRIMARY KEY (`id`),
  KEY `team_id` (`team_id`),
  KEY `order_id` (`order_id`),
  KEY `created_by` (`created_by`),
  KEY `cancelled_by` (`cancelled_by`),
  KEY `idx_route_mission` (`mission_id`,`team_id`),
  CONSTRAINT `mission_routes_ibfk_1` FOREIGN KEY (`mission_id`) REFERENCES `missions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mission_routes_ibfk_2` FOREIGN KEY (`team_id`) REFERENCES `mission_teams` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mission_routes_ibfk_3` FOREIGN KEY (`order_id`) REFERENCES `mission_orders` (`id`) ON DELETE SET NULL,
  CONSTRAINT `mission_routes_ibfk_4` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mission_routes_ibfk_5` FOREIGN KEY (`cancelled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=9572 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mission_scenario_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mission_scenario_items` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `phase_id` int(10) unsigned NOT NULL,
  `mission_id` int(10) unsigned NOT NULL,
  `seq` smallint(5) unsigned NOT NULL DEFAULT 1,
  `kind` enum('dispatch','sector','route','order','restricted','area','building') NOT NULL DEFAULT 'dispatch' COMMENT 'What kind of order: dispatch = a point or an area (sent as a mission_dispatch_points row), sector = a search sector (sent as a mission_search_sectors row), route = a patrol of waypoints (sent as a mission_routes row), order = a general order to people (sent as a mission_orders row), restricted = a restricted area (sent as a mission_restricted_areas row), area = a search area (sent as a mission_search_areas row), building = a building of a search sector (sent as a mission_sector_buildings row with its floors)',
  `payload` text NOT NULL COMMENT 'JSON: audience (team|all), team_id, type, geo, label, travel_mode',
  `slot_id` int(10) unsigned DEFAULT NULL COMMENT 'The slot the order goes to, if it goes to one',
  `sent_at` datetime DEFAULT NULL COMMENT 'When it went out as a live order',
  `sent_ref_id` int(10) unsigned DEFAULT NULL COMMENT 'The live order it became (mission_dispatch_points.id); no foreign key, the plan outlives the order',
  `last_error` varchar(255) DEFAULT NULL COMMENT 'Why the last attempt to send it failed',
  `waiting_since` datetime DEFAULT NULL COMMENT 'Set by play while the item waits for its team to finish the earlier phases (v3.461.0); cleared when it is sent or its wait is cancelled',
  `wait_cancelled_at` datetime DEFAULT NULL COMMENT 'When command cancelled the wait: the item stays in the plan, counts as taken back, and can still be sent by hand',
  `parent_item_id` int(10) unsigned DEFAULT NULL COMMENT 'The order to every team this item was split from (one item per team), v3.461.0',
  `release_mode` varchar(10) DEFAULT NULL COMMENT 'auto = sent when its team finished the earlier phases, manual = command pressed send now, NULL = sent with play',
  `released_by` int(10) unsigned DEFAULT NULL COMMENT 'Who pressed send now (release_mode manual); no foreign key',
  `created_by` int(10) unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_scenario_item_phase` (`phase_id`,`seq`),
  KEY `idx_scenario_item_mission` (`mission_id`),
  KEY `created_by` (`created_by`),
  KEY `idx_scenario_item_slot` (`slot_id`),
  KEY `idx_scenario_item_waiting` (`mission_id`,`waiting_since`),
  KEY `idx_scenario_item_parent` (`parent_item_id`),
  CONSTRAINT `fk_scenario_item_parent` FOREIGN KEY (`parent_item_id`) REFERENCES `mission_scenario_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mission_scenario_items_ibfk_1` FOREIGN KEY (`phase_id`) REFERENCES `mission_scenario_phases` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mission_scenario_items_ibfk_2` FOREIGN KEY (`mission_id`) REFERENCES `missions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mission_scenario_items_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=108023 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mission_scenario_phases`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mission_scenario_phases` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `mission_id` int(10) unsigned NOT NULL,
  `seq` smallint(5) unsigned NOT NULL DEFAULT 1 COMMENT 'Position in the scenario; the number shown is the position, so a deleted phase leaves no gap',
  `title` varchar(120) DEFAULT NULL COMMENT 'Optional name; NULL = just Phase n',
  `played_at` datetime DEFAULT NULL COMMENT 'When play was pressed; NULL = still only a plan',
  `played_by` int(10) unsigned DEFAULT NULL,
  `created_by` int(10) unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_scenario_phase_mission` (`mission_id`,`seq`),
  KEY `played_by` (`played_by`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `mission_scenario_phases_ibfk_1` FOREIGN KEY (`mission_id`) REFERENCES `missions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mission_scenario_phases_ibfk_2` FOREIGN KEY (`played_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `mission_scenario_phases_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=45810 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mission_scenario_slots`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mission_scenario_slots` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `mission_id` int(10) unsigned NOT NULL,
  `label` varchar(80) NOT NULL,
  `team_id` int(10) unsigned DEFAULT NULL COMMENT 'The team this slot stands for; NULL = none chosen yet',
  `created_by` int(10) unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_scenario_slot_label` (`mission_id`,`label`),
  KEY `team_id` (`team_id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `mission_scenario_slots_ibfk_1` FOREIGN KEY (`mission_id`) REFERENCES `missions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mission_scenario_slots_ibfk_2` FOREIGN KEY (`team_id`) REFERENCES `mission_teams` (`id`) ON DELETE SET NULL,
  CONSTRAINT `mission_scenario_slots_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=15179 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mission_score_reviews`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mission_score_reviews` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `mission_id` int(10) unsigned NOT NULL,
  `computed_score` decimal(5,2) NOT NULL,
  `final_score` decimal(5,2) NOT NULL,
  `verdict_note` text DEFAULT NULL,
  `validated_by` int(10) unsigned NOT NULL,
  `validated_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_mission_score_review` (`mission_id`),
  KEY `validated_by` (`validated_by`),
  CONSTRAINT `mission_score_reviews_ibfk_1` FOREIGN KEY (`mission_id`) REFERENCES `missions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mission_score_reviews_ibfk_2` FOREIGN KEY (`validated_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mission_search_areas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mission_search_areas` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `mission_id` int(10) unsigned NOT NULL,
  `label` varchar(255) NOT NULL,
  `ring_index` tinyint(3) unsigned DEFAULT NULL,
  `terrain` enum('rural','mountain','urban') DEFAULT NULL,
  `geo` text NOT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `created_by` (`created_by`),
  KEY `idx_search_area_mission` (`mission_id`),
  CONSTRAINT `mission_search_areas_ibfk_1` FOREIGN KEY (`mission_id`) REFERENCES `missions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mission_search_areas_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=28114 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mission_search_sectors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mission_search_sectors` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `mission_id` int(10) unsigned NOT NULL,
  `area_id` int(10) unsigned NOT NULL,
  `team_id` int(10) unsigned DEFAULT NULL,
  `label` varchar(255) NOT NULL,
  `geo` text NOT NULL,
  `status` enum('not_started','assigned','en_route','in_progress','completed','needs_recheck') NOT NULL DEFAULT 'not_started',
  `status_updated_at` timestamp NULL DEFAULT NULL,
  `status_updated_by` int(10) unsigned DEFAULT NULL,
  `acknowledged_at` timestamp NULL DEFAULT NULL,
  `acknowledged_by` int(10) unsigned DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `travel_mode` enum('foot','vehicle') DEFAULT NULL COMMENT 'How the team was told to go; NULL = its own transport',
  PRIMARY KEY (`id`),
  KEY `status_updated_by` (`status_updated_by`),
  KEY `created_by` (`created_by`),
  KEY `idx_sector_mission` (`mission_id`,`status`),
  KEY `idx_sector_team` (`team_id`),
  KEY `idx_sector_area` (`area_id`),
  KEY `fk_sector_acknowledged_by` (`acknowledged_by`),
  CONSTRAINT `fk_sector_acknowledged_by` FOREIGN KEY (`acknowledged_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `mission_search_sectors_ibfk_1` FOREIGN KEY (`mission_id`) REFERENCES `missions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mission_search_sectors_ibfk_2` FOREIGN KEY (`team_id`) REFERENCES `mission_teams` (`id`) ON DELETE SET NULL,
  CONSTRAINT `mission_search_sectors_ibfk_3` FOREIGN KEY (`status_updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `mission_search_sectors_ibfk_4` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `mission_search_sectors_ibfk_5` FOREIGN KEY (`area_id`) REFERENCES `mission_search_areas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=20415 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mission_sector_building_floors`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mission_sector_building_floors` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `building_id` int(10) unsigned NOT NULL,
  `floor_number` smallint(6) NOT NULL,
  `is_required` tinyint(1) NOT NULL DEFAULT 1,
  `checked_at` timestamp NULL DEFAULT NULL,
  `checked_by` int(10) unsigned DEFAULT NULL,
  `note` varchar(500) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_building_floor` (`building_id`,`floor_number`),
  KEY `checked_by` (`checked_by`),
  KEY `idx_sector_floor_building` (`building_id`),
  CONSTRAINT `mission_sector_building_floors_ibfk_1` FOREIGN KEY (`building_id`) REFERENCES `mission_sector_buildings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mission_sector_building_floors_ibfk_2` FOREIGN KEY (`checked_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=3824 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mission_sector_buildings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mission_sector_buildings` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `sector_id` int(10) unsigned NOT NULL,
  `label` varchar(255) NOT NULL,
  `lat` decimal(10,8) NOT NULL,
  `lng` decimal(11,8) NOT NULL,
  `floor_count` tinyint(3) unsigned NOT NULL COMMENT 'Floors ABOVE ground; the ground floor is always floor_number 0 and is not counted here',
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `created_by` (`created_by`),
  KEY `idx_sector_building_sector` (`sector_id`),
  CONSTRAINT `mission_sector_buildings_ibfk_1` FOREIGN KEY (`sector_id`) REFERENCES `mission_search_sectors` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mission_sector_buildings_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=1813 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mission_sector_exit_watch`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mission_sector_exit_watch` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `sector_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `stint_at` datetime NOT NULL,
  `state` enum('near','warned') NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_sector_exit_watch` (`sector_id`,`user_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `mission_sector_exit_watch_ibfk_1` FOREIGN KEY (`sector_id`) REFERENCES `mission_search_sectors` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mission_sector_exit_watch_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2591 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mission_sector_status_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mission_sector_status_log` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `sector_id` int(10) unsigned NOT NULL,
  `from_status` enum('not_started','assigned','en_route','in_progress','completed','needs_recheck') NOT NULL,
  `to_status` enum('not_started','assigned','en_route','in_progress','completed','needs_recheck') NOT NULL,
  `team_id` int(10) unsigned DEFAULT NULL,
  `user_id` int(10) unsigned DEFAULT NULL,
  `note` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `team_id` (`team_id`),
  KEY `user_id` (`user_id`),
  KEY `idx_sector_status_log_sector` (`sector_id`,`created_at`),
  CONSTRAINT `mission_sector_status_log_ibfk_1` FOREIGN KEY (`sector_id`) REFERENCES `mission_search_sectors` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mission_sector_status_log_ibfk_2` FOREIGN KEY (`team_id`) REFERENCES `mission_teams` (`id`) ON DELETE SET NULL,
  CONSTRAINT `mission_sector_status_log_ibfk_3` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=8797 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mission_shortage_reports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mission_shortage_reports` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `mission_id` int(10) unsigned NOT NULL,
  `reporter_id` int(10) unsigned NOT NULL,
  `team_id` int(10) unsigned DEFAULT NULL,
  `shortage_type` enum('people','equipment','medical','vehicle','other') NOT NULL,
  `severity` enum('low','medium','high','critical') NOT NULL DEFAULT 'medium',
  `title` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `acknowledged_at` timestamp NULL DEFAULT NULL,
  `acknowledged_by` int(10) unsigned DEFAULT NULL,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `resolved_by` int(10) unsigned DEFAULT NULL,
  `not_resolved_at` timestamp NULL DEFAULT NULL,
  `not_resolved_by` int(10) unsigned DEFAULT NULL,
  `outcome_note` text DEFAULT NULL,
  `outcome_seen_at` timestamp NULL DEFAULT NULL COMMENT 'When the reporter dismissed the resolved/not-resolved popup; NULL = still owed one',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `reporter_id` (`reporter_id`),
  KEY `team_id` (`team_id`),
  KEY `acknowledged_by` (`acknowledged_by`),
  KEY `resolved_by` (`resolved_by`),
  KEY `idx_shortage_mission` (`mission_id`,`resolved_at`),
  KEY `fk_shortage_not_resolved_by` (`not_resolved_by`),
  CONSTRAINT `fk_shortage_not_resolved_by` FOREIGN KEY (`not_resolved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `mission_shortage_reports_ibfk_1` FOREIGN KEY (`mission_id`) REFERENCES `missions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mission_shortage_reports_ibfk_2` FOREIGN KEY (`reporter_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mission_shortage_reports_ibfk_3` FOREIGN KEY (`team_id`) REFERENCES `mission_teams` (`id`) ON DELETE SET NULL,
  CONSTRAINT `mission_shortage_reports_ibfk_4` FOREIGN KEY (`acknowledged_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `mission_shortage_reports_ibfk_5` FOREIGN KEY (`resolved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2537 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mission_sos_alerts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mission_sos_alerts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `mission_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `pr_id` int(10) unsigned DEFAULT NULL,
  `team_id` int(10) unsigned DEFAULT NULL,
  `lat` decimal(10,7) DEFAULT NULL,
  `lng` decimal(10,7) DEFAULT NULL,
  `accuracy_m` decimal(8,2) DEFAULT NULL COMMENT 'Device-reported uncertainty radius of the fix, metres; NULL = not reported',
  `acknowledged_at` timestamp NULL DEFAULT NULL,
  `acknowledged_by` int(10) unsigned DEFAULT NULL,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `resolved_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `pr_id` (`pr_id`),
  KEY `team_id` (`team_id`),
  KEY `acknowledged_by` (`acknowledged_by`),
  KEY `resolved_by` (`resolved_by`),
  KEY `idx_sos_mission` (`mission_id`,`resolved_at`),
  CONSTRAINT `mission_sos_alerts_ibfk_1` FOREIGN KEY (`mission_id`) REFERENCES `missions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mission_sos_alerts_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mission_sos_alerts_ibfk_3` FOREIGN KEY (`pr_id`) REFERENCES `participation_requests` (`id`) ON DELETE SET NULL,
  CONSTRAINT `mission_sos_alerts_ibfk_4` FOREIGN KEY (`team_id`) REFERENCES `mission_teams` (`id`) ON DELETE SET NULL,
  CONSTRAINT `mission_sos_alerts_ibfk_5` FOREIGN KEY (`acknowledged_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `mission_sos_alerts_ibfk_6` FOREIGN KEY (`resolved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=134 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mission_team_ai_debriefs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mission_team_ai_debriefs` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `mission_id` int(10) unsigned NOT NULL,
  `team_id` int(10) unsigned NOT NULL,
  `lang` char(2) NOT NULL DEFAULT 'el' COMMENT 'Language the assessment was written in',
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT 'Validated debrief, pseudonymised exactly as the provider returned it' CHECK (json_valid(`payload`)),
  `pseudonym_map` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'MEMBER-n => real name. Server-side only; never sent anywhere' CHECK (json_valid(`pseudonym_map`)),
  `provider` varchar(32) NOT NULL,
  `model` varchar(64) NOT NULL,
  `prompt_version` smallint(5) unsigned NOT NULL DEFAULT 1,
  `digest_hash` char(64) DEFAULT NULL,
  `dropped_claims` smallint(5) unsigned NOT NULL DEFAULT 0,
  `duration_ms` int(10) unsigned DEFAULT NULL,
  `tokens_prompt` int(10) unsigned DEFAULT NULL,
  `tokens_completion` int(10) unsigned DEFAULT NULL,
  `generated_by` int(10) unsigned DEFAULT NULL,
  `generated_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_team_debrief` (`mission_id`,`team_id`,`lang`),
  KEY `generated_by` (`generated_by`),
  KEY `idx_team_debrief_team` (`team_id`,`lang`),
  CONSTRAINT `mission_team_ai_debriefs_ibfk_1` FOREIGN KEY (`mission_id`) REFERENCES `missions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mission_team_ai_debriefs_ibfk_2` FOREIGN KEY (`team_id`) REFERENCES `mission_teams` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mission_team_ai_debriefs_ibfk_3` FOREIGN KEY (`generated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mission_team_equipment`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mission_team_equipment` (
  `team_id` int(10) unsigned NOT NULL,
  `equipment_type_id` int(10) unsigned NOT NULL,
  `set_by` int(10) unsigned DEFAULT NULL,
  `set_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`team_id`,`equipment_type_id`),
  KEY `equipment_type_id` (`equipment_type_id`),
  KEY `set_by` (`set_by`),
  CONSTRAINT `mission_team_equipment_ibfk_1` FOREIGN KEY (`team_id`) REFERENCES `mission_teams` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mission_team_equipment_ibfk_2` FOREIGN KEY (`equipment_type_id`) REFERENCES `team_equipment_types` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mission_team_equipment_ibfk_3` FOREIGN KEY (`set_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mission_team_members`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mission_team_members` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `team_id` int(10) unsigned NOT NULL,
  `mission_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `added_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_mission_user` (`mission_id`,`user_id`),
  KEY `team_id` (`team_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `mission_team_members_ibfk_1` FOREIGN KEY (`team_id`) REFERENCES `mission_teams` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mission_team_members_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=236124 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mission_teams`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mission_teams` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `mission_id` int(10) unsigned NOT NULL,
  `codename` varchar(20) NOT NULL,
  `team_number` tinyint(3) unsigned DEFAULT NULL,
  `color` varchar(7) DEFAULT NULL,
  `transport` enum('foot','motorbike','car') NOT NULL DEFAULT 'foot' COMMENT 'How the team moves: foot, motorbike or car',
  `briefing_token` char(64) DEFAULT NULL,
  `leader_id` int(10) unsigned DEFAULT NULL,
  `created_by` int(10) unsigned NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_mission_number` (`mission_id`,`team_number`),
  UNIQUE KEY `uniq_briefing_token` (`briefing_token`),
  KEY `leader_id` (`leader_id`),
  KEY `created_by` (`created_by`),
  CONSTRAINT `mission_teams_ibfk_1` FOREIGN KEY (`mission_id`) REFERENCES `missions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mission_teams_ibfk_2` FOREIGN KEY (`leader_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `mission_teams_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=198556 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mission_triage_assessments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mission_triage_assessments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `victim_id` int(10) unsigned NOT NULL,
  `mission_id` int(10) unsigned NOT NULL,
  `assessment_uuid` varchar(64) NOT NULL COMMENT 'Made by the phone; an offline replay of the same assessment is recognised and ignored',
  `category` enum('red','yellow','green','black') NOT NULL,
  `reason` varchar(40) DEFAULT NULL,
  `protocol` enum('start','jumpstart','direct','secondary') NOT NULL,
  `answers` varchar(255) DEFAULT NULL COMMENT 'JSON of the yes/no answers on the route taken; NULL for a direct choice',
  `previous_category` enum('red','yellow','green','black') DEFAULT NULL COMMENT 'NULL = first triage of this casualty',
  `assessed_by` int(10) unsigned DEFAULT NULL,
  `team_id` int(10) unsigned DEFAULT NULL,
  `lat` decimal(10,7) DEFAULT NULL,
  `lng` decimal(10,7) DEFAULT NULL,
  `accuracy_m` decimal(8,2) DEFAULT NULL,
  `assessed_at` datetime NOT NULL COMMENT 'Field time',
  `reported_at` datetime DEFAULT NULL COMMENT 'The phone''s own claim, kept even when not trusted',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_triage_assessment_uuid` (`mission_id`,`assessment_uuid`),
  KEY `idx_triage_assessment_victim` (`victim_id`,`assessed_at`),
  KEY `assessed_by` (`assessed_by`),
  KEY `team_id` (`team_id`),
  CONSTRAINT `mission_triage_assessments_ibfk_1` FOREIGN KEY (`victim_id`) REFERENCES `mission_triage_victims` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mission_triage_assessments_ibfk_2` FOREIGN KEY (`mission_id`) REFERENCES `missions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mission_triage_assessments_ibfk_3` FOREIGN KEY (`assessed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `mission_triage_assessments_ibfk_4` FOREIGN KEY (`team_id`) REFERENCES `mission_teams` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=12682 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mission_triage_bulk`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mission_triage_bulk` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `mission_id` int(10) unsigned NOT NULL,
  `client_uuid` varchar(64) NOT NULL,
  `walking_count` smallint(5) unsigned NOT NULL,
  `reported_by` int(10) unsigned DEFAULT NULL,
  `team_id` int(10) unsigned DEFAULT NULL,
  `lat` decimal(10,7) DEFAULT NULL,
  `lng` decimal(10,7) DEFAULT NULL,
  `accuracy_m` decimal(8,2) DEFAULT NULL,
  `reported_at` datetime NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_triage_bulk_uuid` (`mission_id`,`client_uuid`),
  KEY `reported_by` (`reported_by`),
  KEY `team_id` (`team_id`),
  CONSTRAINT `mission_triage_bulk_ibfk_1` FOREIGN KEY (`mission_id`) REFERENCES `missions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mission_triage_bulk_ibfk_2` FOREIGN KEY (`reported_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `mission_triage_bulk_ibfk_3` FOREIGN KEY (`team_id`) REFERENCES `mission_teams` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=615 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mission_triage_expectant_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mission_triage_expectant_log` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `victim_id` int(10) unsigned NOT NULL,
  `mission_id` int(10) unsigned NOT NULL,
  `action` enum('set','cleared') NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `user_id` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_triage_expectant_mission` (`mission_id`,`created_at`),
  KEY `victim_id` (`victim_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `mission_triage_expectant_log_ibfk_1` FOREIGN KEY (`victim_id`) REFERENCES `mission_triage_victims` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mission_triage_expectant_log_ibfk_2` FOREIGN KEY (`mission_id`) REFERENCES `missions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mission_triage_expectant_log_ibfk_3` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=842 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mission_triage_status_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mission_triage_status_log` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `victim_id` int(10) unsigned NOT NULL,
  `mission_id` int(10) unsigned NOT NULL,
  `status` enum('on_scene','at_ccp','transported') NOT NULL,
  `vehicle` varchar(100) DEFAULT NULL,
  `destination` varchar(255) DEFAULT NULL,
  `user_id` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_triage_status_mission` (`mission_id`,`created_at`),
  KEY `victim_id` (`victim_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `mission_triage_status_log_ibfk_1` FOREIGN KEY (`victim_id`) REFERENCES `mission_triage_victims` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mission_triage_status_log_ibfk_2` FOREIGN KEY (`mission_id`) REFERENCES `missions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mission_triage_status_log_ibfk_3` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=1481 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mission_triage_victims`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mission_triage_victims` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `mission_id` int(10) unsigned NOT NULL,
  `victim_uuid` varchar(64) NOT NULL COMMENT 'Made by the phone, so an offline triage has an identity before the server sees it',
  `card_no` varchar(30) DEFAULT NULL COMMENT 'Physical triage card, normalised; the same card is the same person',
  `fallback_code` varchar(20) NOT NULL COMMENT 'T<user>-<n>, made by the phone when there is no card',
  `category` enum('red','yellow','green','black') NOT NULL COMMENT 'Latest assessment by field time',
  `reason` varchar(40) DEFAULT NULL,
  `age_group` enum('adult','child') NOT NULL DEFAULT 'adult',
  `lat` decimal(10,7) DEFAULT NULL,
  `lng` decimal(10,7) DEFAULT NULL,
  `accuracy_m` decimal(8,2) DEFAULT NULL,
  `status` enum('on_scene','at_ccp','transported') NOT NULL DEFAULT 'on_scene',
  `status_at` datetime DEFAULT NULL,
  `status_by` int(10) unsigned DEFAULT NULL,
  `expectant_at` datetime DEFAULT NULL,
  `expectant_by` int(10) unsigned DEFAULT NULL,
  `expectant_reason` varchar(255) DEFAULT NULL,
  `transport_vehicle` varchar(100) DEFAULT NULL,
  `transport_destination` varchar(255) DEFAULT NULL,
  `patient_name` varchar(255) DEFAULT NULL,
  `estimated_age` varchar(50) DEFAULT NULL,
  `gender` enum('male','female','unknown') DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `notes` text DEFAULT NULL COMMENT 'Command staff only',
  `created_by` int(10) unsigned DEFAULT NULL,
  `team_id` int(10) unsigned DEFAULT NULL,
  `first_assessed_at` datetime NOT NULL,
  `last_assessed_at` datetime NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_triage_victim_uuid` (`mission_id`,`victim_uuid`),
  UNIQUE KEY `uk_triage_fallback` (`mission_id`,`fallback_code`),
  UNIQUE KEY `uk_triage_card` (`mission_id`,`card_no`),
  KEY `idx_triage_board` (`mission_id`,`status`,`category`),
  KEY `status_by` (`status_by`),
  KEY `created_by` (`created_by`),
  KEY `team_id` (`team_id`),
  KEY `fk_triage_victim_expectant_by` (`expectant_by`),
  CONSTRAINT `fk_triage_victim_expectant_by` FOREIGN KEY (`expectant_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `mission_triage_victims_ibfk_1` FOREIGN KEY (`mission_id`) REFERENCES `missions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mission_triage_victims_ibfk_2` FOREIGN KEY (`status_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `mission_triage_victims_ibfk_3` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `mission_triage_victims_ibfk_4` FOREIGN KEY (`team_id`) REFERENCES `mission_teams` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=9728 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mission_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mission_types` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `color` varchar(20) NOT NULL DEFAULT 'primary',
  `icon` varchar(50) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=403 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mission_visitor_tags`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mission_visitor_tags` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `label` varchar(100) NOT NULL,
  `color` varchar(7) NOT NULL DEFAULT '#6c757d',
  `icon` varchar(50) NOT NULL DEFAULT 'bi-person-badge',
  `sort_order` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_mission_visitor_tags_label` (`label`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mission_voice_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mission_voice_messages` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `mission_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `pr_id` int(10) unsigned DEFAULT NULL COMMENT 'The shift participation this was sent during, when there is one',
  `team_id` int(10) unsigned DEFAULT NULL,
  `stored_name` varchar(255) NOT NULL,
  `mime_type` varchar(100) NOT NULL,
  `file_size` int(10) unsigned NOT NULL,
  `duration_ms` int(10) unsigned DEFAULT NULL COMMENT 'Measured on the device while recording; NULL when the browser would not report it',
  `lat` decimal(10,7) DEFAULT NULL,
  `lng` decimal(10,7) DEFAULT NULL,
  `accuracy_m` decimal(8,2) DEFAULT NULL COMMENT 'Device-reported uncertainty radius of the fix, metres; NULL = not reported',
  `acknowledged_at` timestamp NULL DEFAULT NULL,
  `acknowledged_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `pr_id` (`pr_id`),
  KEY `team_id` (`team_id`),
  KEY `acknowledged_by` (`acknowledged_by`),
  KEY `idx_voice_mission` (`mission_id`,`acknowledged_at`),
  CONSTRAINT `mission_voice_messages_ibfk_1` FOREIGN KEY (`mission_id`) REFERENCES `missions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mission_voice_messages_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mission_voice_messages_ibfk_3` FOREIGN KEY (`pr_id`) REFERENCES `participation_requests` (`id`) ON DELETE SET NULL,
  CONSTRAINT `mission_voice_messages_ibfk_4` FOREIGN KEY (`team_id`) REFERENCES `mission_teams` (`id`) ON DELETE SET NULL,
  CONSTRAINT `mission_voice_messages_ibfk_5` FOREIGN KEY (`acknowledged_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=11188 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `missions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `missions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `department_id` int(10) unsigned DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `type` enum('VOLUNTEER','MEDICAL') DEFAULT 'VOLUNTEER',
  `mission_type_id` int(10) unsigned NOT NULL DEFAULT 1,
  `show_in_ops` tinyint(1) NOT NULL DEFAULT 0,
  `location` varchar(255) NOT NULL,
  `location_details` varchar(255) DEFAULT NULL,
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `start_datetime` datetime NOT NULL,
  `end_datetime` datetime NOT NULL,
  `requirements` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `is_urgent` tinyint(1) DEFAULT 0,
  `is_special_mission` tinyint(1) NOT NULL DEFAULT 0,
  `is_locked` tinyint(1) NOT NULL DEFAULT 0,
  `par_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `par_interval_minutes` smallint(5) unsigned DEFAULT 30,
  `rv_point_label` varchar(255) DEFAULT NULL,
  `radio_channel` varchar(100) DEFAULT NULL,
  `coverage_percentage` int(11) DEFAULT 0,
  `status` enum('DRAFT','OPEN','CLOSED','COMPLETED','CANCELED') DEFAULT 'DRAFT',
  `created_by` int(10) unsigned DEFAULT NULL,
  `responsible_user_id` int(10) unsigned DEFAULT NULL,
  `cancellation_reason` text DEFAULT NULL,
  `canceled_by` int(10) unsigned DEFAULT NULL,
  `canceled_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `recurrence_id` int(10) unsigned DEFAULT NULL,
  `recurrence_instance_date` date DEFAULT NULL,
  `visitor_join_token` varchar(64) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_missions_visitor_join_token` (`visitor_join_token`),
  KEY `department_id` (`department_id`),
  KEY `created_by` (`created_by`),
  KEY `canceled_by` (`canceled_by`),
  KEY `idx_missions_status` (`status`),
  KEY `idx_missions_start` (`start_datetime`),
  KEY `fk_missions_responsible` (`responsible_user_id`),
  KEY `fk_missions_type` (`mission_type_id`),
  KEY `idx_missions_status_dept` (`status`,`department_id`),
  KEY `idx_missions_status_start` (`status`,`start_datetime`),
  KEY `idx_missions_created` (`created_at`),
  KEY `idx_missions_recurrence` (`recurrence_id`),
  KEY `idx_missions_deleted_at` (`deleted_at`),
  KEY `idx_missions_status_del_start` (`status`,`deleted_at`,`start_datetime`),
  KEY `idx_missions_urgent` (`is_urgent`,`status`),
  CONSTRAINT `fk_missions_responsible` FOREIGN KEY (`responsible_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_missions_type` FOREIGN KEY (`mission_type_id`) REFERENCES `mission_types` (`id`),
  CONSTRAINT `missions_ibfk_1` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `missions_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `missions_ibfk_3` FOREIGN KEY (`canceled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=253960 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mobile_api_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mobile_api_tokens` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `token_hash` char(64) NOT NULL,
  `device_label` varchar(100) NOT NULL DEFAULT 'Android app',
  `has_barometer` tinyint(4) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_used_at` timestamp NULL DEFAULT NULL,
  `revoked_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_token_hash` (`token_hash`),
  KEY `idx_mobile_tokens_user` (`user_id`,`revoked_at`),
  CONSTRAINT `mobile_api_tokens_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=435 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mobilization_broadcast_recipients`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mobilization_broadcast_recipients` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `broadcast_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `status` enum('delivered','failed') NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `idx_mob_recipients_broadcast` (`broadcast_id`),
  CONSTRAINT `mobilization_broadcast_recipients_ibfk_1` FOREIGN KEY (`broadcast_id`) REFERENCES `mobilization_broadcasts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mobilization_broadcast_recipients_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mobilization_broadcasts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mobilization_broadcasts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `message` text NOT NULL,
  `sent_by` int(10) unsigned DEFAULT NULL,
  `recipients_count` int(10) unsigned NOT NULL DEFAULT 0,
  `delivered_count` int(10) unsigned NOT NULL DEFAULT 0,
  `failed_count` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `sent_by` (`sent_by`),
  KEY `idx_mobilization_created` (`created_at`),
  CONSTRAINT `mobilization_broadcasts_ibfk_1` FOREIGN KEY (`sent_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `newsletter_presets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `newsletter_presets` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `body_html` mediumtext NOT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `newsletter_sends`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `newsletter_sends` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `newsletter_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned DEFAULT NULL,
  `email` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL DEFAULT '',
  `status` enum('pending','sent','failed') NOT NULL DEFAULT 'pending',
  `error_msg` text DEFAULT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_ns_newsletter_id` (`newsletter_id`),
  KEY `idx_ns_user_id` (`user_id`),
  KEY `idx_ns_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `newsletter_templates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `newsletter_templates` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `body_html` mediumtext NOT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5958 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `newsletter_unsubscribes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `newsletter_unsubscribes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned DEFAULT NULL,
  `email` varchar(255) NOT NULL,
  `token` varchar(64) NOT NULL,
  `newsletter_id` int(10) unsigned DEFAULT NULL COMMENT 'Campaign that triggered unsubscribe',
  `unsubscribed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_nu_token` (`token`),
  KEY `idx_nu_email` (`email`),
  KEY `idx_nu_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `newsletters`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `newsletters` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `body_html` mediumtext NOT NULL,
  `status` enum('draft','sending','sent','failed') NOT NULL DEFAULT 'draft',
  `filter_roles` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Array of roles to send to, NULL = all' CHECK (json_valid(`filter_roles`)),
  `filter_dept_id` int(10) unsigned DEFAULT NULL COMMENT 'Limit to one department, NULL = all',
  `extra_emails` text DEFAULT NULL,
  `template_id` int(10) unsigned DEFAULT NULL,
  `total_recipients` int(10) unsigned NOT NULL DEFAULT 0,
  `sent_count` int(10) unsigned NOT NULL DEFAULT 0,
  `failed_count` int(10) unsigned NOT NULL DEFAULT 0,
  `created_by` int(10) unsigned NOT NULL,
  `sent_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_newsletters_status` (`status`),
  KEY `idx_newsletters_created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `notification_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notification_settings` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(50) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `email_enabled` tinyint(1) DEFAULT 1,
  `email_template_id` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `code` (`code`),
  KEY `email_template_id` (`email_template_id`),
  CONSTRAINT `notification_settings_ibfk_1` FOREIGN KEY (`email_template_id`) REFERENCES `email_templates` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=15163 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notifications` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `type` varchar(50) NOT NULL,
  `title` varchar(255) NOT NULL,
  `message` text DEFAULT NULL,
  `data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`data`)),
  `read_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `banner_mission_id` int(10) unsigned GENERATED ALWAYS AS (json_extract(`data`,'$.bannerMission')) VIRTUAL,
  PRIMARY KEY (`id`),
  KEY `idx_notifications_user` (`user_id`,`read_at`),
  KEY `idx_notifications_user_created` (`user_id`,`created_at`),
  KEY `idx_notifications_banner` (`user_id`,`banner_mission_id`,`id`),
  KEY `idx_notif_user_read_created` (`user_id`,`read_at`,`created_at`),
  CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=92596 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `osm_feature_cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `osm_feature_cache` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `tile_key` varchar(24) NOT NULL COMMENT 'Tile of 0.05 degrees: row_column, e.g. 703_474',
  `layer_group` varchar(16) NOT NULL COMMENT 'points | paths | tracks | cliffs',
  `payload` mediumtext NOT NULL COMMENT 'Compact JSON list of features, see includes/osm.php',
  `element_count` int(10) unsigned NOT NULL DEFAULT 0,
  `fetched_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_osm_tile_group` (`tile_key`,`layer_group`),
  KEY `idx_osm_fetched` (`fetched_at`)
) ENGINE=InnoDB AUTO_INCREMENT=349057 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `participation_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `participation_requests` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `shift_id` int(10) unsigned NOT NULL,
  `volunteer_id` int(10) unsigned NOT NULL,
  `status` enum('PENDING','APPROVED','REJECTED','CANCELED_BY_USER','CANCELED_BY_ADMIN') DEFAULT 'PENDING',
  `notes` text DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `decided_by` int(10) unsigned DEFAULT NULL,
  `decided_at` timestamp NULL DEFAULT NULL,
  `points_awarded` tinyint(1) DEFAULT 0,
  `attended` tinyint(1) DEFAULT 0,
  `actual_hours` decimal(5,2) DEFAULT NULL,
  `actual_start_time` time DEFAULT NULL,
  `actual_end_time` time DEFAULT NULL,
  `admin_notes` text DEFAULT NULL,
  `attendance_confirmed_at` timestamp NULL DEFAULT NULL,
  `attendance_confirmed_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `field_status` enum('on_way','on_site','needs_help') DEFAULT NULL,
  `field_status_updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_participation` (`shift_id`,`volunteer_id`),
  KEY `decided_by` (`decided_by`),
  KEY `attendance_confirmed_by` (`attendance_confirmed_by`),
  KEY `idx_participation_status` (`status`),
  KEY `idx_participation_volunteer` (`volunteer_id`),
  KEY `idx_pr_shift_status` (`shift_id`,`status`),
  KEY `idx_pr_vol_status` (`volunteer_id`,`status`),
  KEY `idx_pr_created` (`created_at`),
  KEY `idx_pr_attended` (`attended`,`shift_id`),
  KEY `idx_pr_points_attended` (`points_awarded`,`attended`),
  KEY `idx_pr_vol_attended` (`volunteer_id`,`attended`),
  CONSTRAINT `participation_requests_ibfk_1` FOREIGN KEY (`shift_id`) REFERENCES `shifts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `participation_requests_ibfk_2` FOREIGN KEY (`volunteer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `participation_requests_ibfk_3` FOREIGN KEY (`decided_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `participation_requests_ibfk_4` FOREIGN KEY (`attendance_confirmed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=315322 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `password_reset_tokens` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `token` varchar(100) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `idx_prt_token` (`token`),
  KEY `idx_prt_user` (`user_id`),
  CONSTRAINT `password_reset_tokens_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `push_subscriptions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `push_subscriptions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `endpoint` text NOT NULL,
  `p256dh_key` varchar(255) NOT NULL,
  `auth_key` varchar(255) NOT NULL,
  `user_agent` varchar(512) DEFAULT '',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_push_user` (`user_id`),
  CONSTRAINT `push_subscriptions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `quiz_attempts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `quiz_attempts` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `quiz_id` int(11) NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `selected_questions_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`selected_questions_json`)),
  `score` int(11) DEFAULT NULL,
  `total_questions` int(11) DEFAULT 0,
  `passing_percentage` int(11) DEFAULT 70,
  `passed` tinyint(1) DEFAULT 0,
  `started_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `time_taken_seconds` int(11) DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_quiz` (`quiz_id`),
  KEY `idx_user` (`user_id`),
  CONSTRAINT `fk_quiz_attempts_quiz` FOREIGN KEY (`quiz_id`) REFERENCES `training_quizzes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_quiz_attempts_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `settings` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting_key` (`setting_key`)
) ENGINE=InnoDB AUTO_INCREMENT=56776 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `shift_swap_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shift_swap_requests` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `participation_id` int(10) unsigned NOT NULL,
  `from_volunteer_id` int(10) unsigned NOT NULL,
  `to_volunteer_id` int(10) unsigned NOT NULL,
  `shift_id` int(10) unsigned NOT NULL,
  `message` text DEFAULT NULL,
  `status` enum('PENDING_RESPONSE','ACCEPTED','DECLINED','APPROVED','REJECTED','CANCELED') NOT NULL DEFAULT 'PENDING_RESPONSE',
  `to_volunteer_responded_at` datetime DEFAULT NULL,
  `decided_by` int(10) unsigned DEFAULT NULL,
  `decided_at` datetime DEFAULT NULL,
  `admin_notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_shift` (`shift_id`),
  KEY `idx_from_volunteer` (`from_volunteer_id`),
  KEY `idx_to_volunteer` (`to_volunteer_id`),
  KEY `idx_status` (`status`),
  KEY `participation_id` (`participation_id`),
  KEY `decided_by` (`decided_by`),
  CONSTRAINT `shift_swap_requests_ibfk_1` FOREIGN KEY (`participation_id`) REFERENCES `participation_requests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `shift_swap_requests_ibfk_2` FOREIGN KEY (`from_volunteer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `shift_swap_requests_ibfk_3` FOREIGN KEY (`to_volunteer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `shift_swap_requests_ibfk_4` FOREIGN KEY (`shift_id`) REFERENCES `shifts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `shift_swap_requests_ibfk_5` FOREIGN KEY (`decided_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `shift_vehicles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shift_vehicles` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `shift_id` int(10) unsigned NOT NULL,
  `vehicle_id` int(10) unsigned NOT NULL,
  `assigned_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_shift_vehicle` (`shift_id`,`vehicle_id`),
  KEY `assigned_by` (`assigned_by`),
  KEY `idx_shift_vehicles_vehicle` (`vehicle_id`),
  CONSTRAINT `shift_vehicles_ibfk_1` FOREIGN KEY (`shift_id`) REFERENCES `shifts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `shift_vehicles_ibfk_2` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `shift_vehicles_ibfk_3` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=109 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `shifts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `shifts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `mission_id` int(10) unsigned NOT NULL,
  `start_time` datetime NOT NULL,
  `end_time` datetime NOT NULL,
  `max_volunteers` int(11) DEFAULT 5,
  `min_volunteers` int(11) DEFAULT 1,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_shifts_mission` (`mission_id`),
  KEY `idx_shifts_time` (`start_time`),
  KEY `idx_shifts_mission_time` (`mission_id`,`start_time`),
  KEY `idx_shifts_end_time` (`end_time`),
  KEY `idx_shifts_time_mission` (`start_time`,`end_time`,`mission_id`),
  CONSTRAINT `shifts_ibfk_1` FOREIGN KEY (`mission_id`) REFERENCES `missions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=156928 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `skill_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `skill_categories` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `skills`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `skills` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `category` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `subscription_iris_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `subscription_iris_requests` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `subscription_id` int(10) unsigned NOT NULL,
  `coverage_years` tinyint(3) unsigned NOT NULL,
  `annual_amount` decimal(10,2) NOT NULL,
  `total_amount` decimal(10,2) NOT NULL,
  `status` enum('PREPARED','REPORTED','SEEN','COMPLETED','CANCELLED') NOT NULL DEFAULT 'PREPARED',
  `payment_reported_at` datetime DEFAULT NULL,
  `seen_at` datetime DEFAULT NULL,
  `seen_by` int(10) unsigned DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_sir_user_status` (`user_id`,`status`,`id`),
  KEY `idx_sir_subscription_status` (`subscription_id`,`status`,`id`),
  KEY `fk_sir_seen_by` (`seen_by`),
  CONSTRAINT `fk_sir_seen_by` FOREIGN KEY (`seen_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_sir_subscription` FOREIGN KEY (`subscription_id`) REFERENCES `volunteer_subscriptions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sir_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `subtasks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `subtasks` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `task_id` int(10) unsigned NOT NULL,
  `title` varchar(255) NOT NULL,
  `is_completed` tinyint(1) DEFAULT 0,
  `completed_at` datetime DEFAULT NULL,
  `completed_by` int(10) unsigned DEFAULT NULL,
  `sort_order` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `task_id` (`task_id`),
  KEY `completed_by` (`completed_by`),
  CONSTRAINT `subtasks_ibfk_1` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE,
  CONSTRAINT `subtasks_ibfk_2` FOREIGN KEY (`completed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=85 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `task_assignments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `task_assignments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `task_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `assigned_by` int(10) unsigned NOT NULL,
  `assigned_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_assignment` (`task_id`,`user_id`),
  KEY `assigned_by` (`assigned_by`),
  KEY `idx_task_assignments_user` (`user_id`),
  CONSTRAINT `task_assignments_ibfk_1` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE,
  CONSTRAINT `task_assignments_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `task_assignments_ibfk_3` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=68 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `task_comments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `task_comments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `task_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `comment` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `idx_task_comments_task` (`task_id`),
  CONSTRAINT `task_comments_ibfk_1` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE,
  CONSTRAINT `task_comments_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=43 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tasks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tasks` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `priority` enum('LOW','MEDIUM','HIGH','URGENT') DEFAULT 'MEDIUM',
  `status` enum('TODO','IN_PROGRESS','COMPLETED','CANCELED') DEFAULT 'TODO',
  `progress` int(11) DEFAULT 0,
  `deadline` datetime DEFAULT NULL,
  `created_by` int(10) unsigned NOT NULL,
  `responsible_user_id` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `completed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tasks_status` (`status`),
  KEY `idx_tasks_deadline` (`deadline`),
  KEY `idx_tasks_created_by` (`created_by`),
  KEY `fk_tasks_responsible` (`responsible_user_id`),
  KEY `idx_tasks_status_deadline` (`status`,`deadline`),
  KEY `idx_tasks_priority_status` (`priority`,`status`),
  CONSTRAINT `fk_tasks_responsible` FOREIGN KEY (`responsible_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `tasks_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=41 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `team_arc_state`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `team_arc_state` (
  `team_id` int(10) unsigned NOT NULL,
  `target_kind` varchar(12) NOT NULL COMMENT 'dispatch | sector | route',
  `target_id` int(10) unsigned NOT NULL,
  `first_distance_m` int(10) unsigned NOT NULL COMMENT 'Straight-line metres when the arc first appeared for this target',
  `minutes` smallint(5) unsigned DEFAULT NULL COMMENT 'Last routed or estimated time',
  `source` varchar(16) DEFAULT NULL COMMENT 'osrm | google | straight_line',
  `mode` varchar(8) DEFAULT NULL COMMENT 'foot | vehicle',
  `eta_distance_m` int(10) unsigned DEFAULT NULL COMMENT 'Straight-line metres at the moment minutes was worked out',
  `eta_at` int(10) unsigned DEFAULT NULL COMMENT 'Unix time minutes was worked out',
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `route_m` int(10) unsigned DEFAULT NULL COMMENT 'Metres of the routed path when minutes was worked out',
  `ping_ts` int(10) unsigned DEFAULT NULL COMMENT 'Unix time of the team fix minutes was worked out from',
  PRIMARY KEY (`team_id`),
  CONSTRAINT `team_arc_state_ibfk_1` FOREIGN KEY (`team_id`) REFERENCES `mission_teams` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `team_equipment_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `team_equipment_types` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `icon` varchar(10) NOT NULL DEFAULT '?',
  `sort_order` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_name` (`name`),
  KEY `created_by` (`created_by`),
  KEY `idx_active` (`is_active`),
  CONSTRAINT `team_equipment_types_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=274 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `telegram_link_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `telegram_link_tokens` (
  `token` varchar(64) NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`token`),
  KEY `idx_telegram_token_user` (`user_id`),
  CONSTRAINT `telegram_link_tokens_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `training_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `training_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `icon` varchar(50) DEFAULT NULL,
  `display_order` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `training_exam_questions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `training_exam_questions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `exam_id` int(11) DEFAULT NULL,
  `category_id` int(10) unsigned DEFAULT NULL,
  `question_text` text NOT NULL,
  `question_type` enum('MULTIPLE_CHOICE','TRUE_FALSE','OPEN_ENDED') NOT NULL,
  `correct_option` varchar(255) DEFAULT NULL,
  `option_a` varchar(500) DEFAULT NULL,
  `option_b` varchar(500) DEFAULT NULL,
  `option_c` varchar(500) DEFAULT NULL,
  `option_d` varchar(500) DEFAULT NULL,
  `explanation` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `display_order` int(11) DEFAULT 0,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_exam` (`exam_id`),
  KEY `idx_teq_category` (`category_id`),
  CONSTRAINT `fk_exam_questions` FOREIGN KEY (`exam_id`) REFERENCES `training_exams` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=203 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `training_exams`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `training_exams` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `category_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `questions_per_attempt` int(11) NOT NULL DEFAULT 10,
  `passing_percentage` int(11) NOT NULL DEFAULT 70,
  `time_limit_minutes` int(11) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `available_from` datetime DEFAULT NULL,
  `available_until` datetime DEFAULT NULL,
  `max_attempts` int(11) NOT NULL DEFAULT 1,
  `use_random_pool` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` int(10) unsigned NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_category` (`category_id`),
  KEY `idx_created_by` (`created_by`),
  CONSTRAINT `fk_exams_category` FOREIGN KEY (`category_id`) REFERENCES `training_categories` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_exams_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `training_materials`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `training_materials` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `category_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_type` varchar(100) NOT NULL,
  `file_size` bigint(20) DEFAULT NULL,
  `uploaded_by` int(10) unsigned NOT NULL,
  `views` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_category` (`category_id`),
  KEY `idx_uploaded_by` (`uploaded_by`),
  CONSTRAINT `fk_materials_category` FOREIGN KEY (`category_id`) REFERENCES `training_categories` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_materials_user` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `training_quiz_questions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `training_quiz_questions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `quiz_id` int(11) DEFAULT NULL,
  `category_id` int(11) DEFAULT NULL,
  `question_text` text NOT NULL,
  `question_type` enum('MULTIPLE_CHOICE','TRUE_FALSE','OPEN_ENDED') NOT NULL,
  `correct_option` varchar(255) DEFAULT NULL,
  `option_a` varchar(500) DEFAULT NULL,
  `option_b` varchar(500) DEFAULT NULL,
  `option_c` varchar(500) DEFAULT NULL,
  `option_d` varchar(500) DEFAULT NULL,
  `explanation` text DEFAULT NULL,
  `display_order` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_quiz` (`quiz_id`),
  KEY `idx_tqq_category` (`category_id`),
  CONSTRAINT `fk_quiz_questions` FOREIGN KEY (`quiz_id`) REFERENCES `training_quizzes` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=119 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `training_quizzes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `training_quizzes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `category_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `time_limit_minutes` int(11) DEFAULT NULL,
  `use_random_pool` tinyint(1) NOT NULL DEFAULT 0,
  `questions_per_attempt` int(11) DEFAULT 10,
  `passing_percentage` int(11) DEFAULT 70,
  `is_active` tinyint(1) DEFAULT 1,
  `created_by` int(10) unsigned NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_category` (`category_id`),
  KEY `idx_created_by` (`created_by`),
  CONSTRAINT `fk_quizzes_category` FOREIGN KEY (`category_id`) REFERENCES `training_categories` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_quizzes_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `training_user_progress`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `training_user_progress` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `category_id` int(11) NOT NULL,
  `materials_viewed_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`materials_viewed_json`)),
  `quizzes_completed` int(11) DEFAULT 0,
  `exams_passed` int(11) DEFAULT 0,
  `last_activity` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user_category` (`user_id`,`category_id`),
  KEY `idx_category` (`category_id`),
  CONSTRAINT `fk_progress_category` FOREIGN KEY (`category_id`) REFERENCES `training_categories` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_progress_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_achievements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_achievements` (
  `user_id` int(10) unsigned NOT NULL,
  `achievement_id` int(10) unsigned NOT NULL,
  `earned_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `notified` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`user_id`,`achievement_id`),
  KEY `achievement_id` (`achievement_id`),
  KEY `idx_ua_notified` (`notified`,`earned_at`),
  CONSTRAINT `user_achievements_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_achievements_ibfk_2` FOREIGN KEY (`achievement_id`) REFERENCES `achievements` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_answers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_answers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `attempt_id` int(11) NOT NULL,
  `attempt_type` enum('QUIZ','EXAM') NOT NULL,
  `question_id` int(11) NOT NULL,
  `selected_option` varchar(10) DEFAULT NULL,
  `answer_text` text DEFAULT NULL,
  `user_answer` text DEFAULT NULL,
  `is_correct` tinyint(1) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_attempt` (`attempt_id`,`attempt_type`)
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_notification_preferences`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_notification_preferences` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `notification_code` varchar(50) NOT NULL,
  `email_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `in_app_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `push_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_notif` (`user_id`,`notification_code`),
  CONSTRAINT `user_notification_preferences_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_remember_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_remember_tokens` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `selector` char(24) NOT NULL COMMENT 'Public half of the cookie, used to find the row',
  `validator_hash` char(64) NOT NULL COMMENT 'SHA-256 of the secret half; the secret itself is never stored',
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_remember_selector` (`selector`),
  KEY `idx_remember_user` (`user_id`,`expires_at`),
  CONSTRAINT `user_remember_tokens_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_skills`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_skills` (
  `user_id` int(10) unsigned NOT NULL,
  `skill_id` int(10) unsigned NOT NULL,
  `level` enum('BEGINNER','INTERMEDIATE','ADVANCED','EXPERT') DEFAULT 'BEGINNER',
  PRIMARY KEY (`user_id`,`skill_id`),
  KEY `skill_id` (`skill_id`),
  CONSTRAINT `user_skills_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `user_skills_ibfk_2` FOREIGN KEY (`skill_id`) REFERENCES `skills` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `telegram_chat_id` bigint(20) DEFAULT NULL,
  `telegram_username` varchar(100) DEFAULT NULL,
  `telegram_linked_at` timestamp NULL DEFAULT NULL,
  `profile_photo` varchar(255) DEFAULT NULL,
  `cohort_year` smallint(6) DEFAULT NULL,
  `id_card` varchar(20) DEFAULT NULL,
  `afm` varchar(20) DEFAULT NULL,
  `amka` varchar(11) DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `driving_license` varchar(30) DEFAULT NULL,
  `vehicle_plate` varchar(20) DEFAULT NULL,
  `pants_size` varchar(10) DEFAULT NULL,
  `shirt_size` varchar(10) DEFAULT NULL,
  `blouse_size` varchar(10) DEFAULT NULL,
  `fleece_size` varchar(10) DEFAULT NULL,
  `registry_epidrasis` varchar(50) DEFAULT NULL,
  `registry_ggpp` varchar(50) DEFAULT NULL,
  `role` enum('SYSTEM_ADMIN','DEPARTMENT_ADMIN','SHIFT_LEADER','VOLUNTEER') DEFAULT 'VOLUNTEER',
  `custom_role_id` int(10) unsigned DEFAULT NULL,
  `volunteer_type` enum('TRAINEE_RESCUER','RESCUER') NOT NULL DEFAULT 'RESCUER',
  `position_id` int(10) unsigned DEFAULT NULL,
  `department_id` int(10) unsigned DEFAULT NULL,
  `warehouse_id` int(10) unsigned DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `is_external` tinyint(1) NOT NULL DEFAULT 0,
  `is_team_captain` tinyint(1) NOT NULL DEFAULT 0,
  `language` enum('el','en') NOT NULL DEFAULT 'el',
  `guest_org_name` varchar(150) DEFAULT NULL,
  `guest_country` varchar(100) DEFAULT 'Ελλάδα',
  `total_points` int(11) DEFAULT 0,
  `monthly_points` int(11) DEFAULT 0,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `email_verification_token` varchar(100) DEFAULT NULL,
  `approval_status` enum('PENDING','APPROVED','REJECTED') NOT NULL DEFAULT 'APPROVED',
  `remember_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `deleted_at` timestamp NULL DEFAULT NULL,
  `deleted_by` int(10) unsigned DEFAULT NULL,
  `newsletter_unsubscribed` tinyint(1) NOT NULL DEFAULT 0,
  `volunteer_team_id` int(10) unsigned DEFAULT NULL,
  `guest_country_code` char(2) DEFAULT NULL,
  `is_mission_visitor` tinyint(1) NOT NULL DEFAULT 0,
  `mission_visitor_tag_id` int(10) unsigned DEFAULT NULL,
  `mission_visitor_mission_id` int(10) unsigned DEFAULT NULL,
  `mission_visitor_consent_at` datetime DEFAULT NULL,
  `is_dog_handler` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Handles a search dog — drives the ? badge next to this user''s name app-wide',
  `dog_name` varchar(100) DEFAULT NULL COMMENT 'Search dog''s name; NULL unless is_dog_handler = 1',
  `dog_training` text DEFAULT NULL COMMENT 'Free-text description of the dog''s training/certifications; NULL unless is_dog_handler = 1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `idx_users_telegram_chat` (`telegram_chat_id`),
  KEY `idx_volunteer_type` (`volunteer_type`),
  KEY `idx_users_warehouse` (`warehouse_id`),
  KEY `fk_user_position` (`position_id`),
  KEY `idx_users_role_active` (`role`,`is_active`),
  KEY `idx_users_dept_role` (`department_id`,`role`),
  KEY `idx_users_cohort` (`cohort_year`,`name`),
  KEY `fk_users_custom_role` (`custom_role_id`),
  KEY `idx_users_volunteer_team` (`volunteer_team_id`),
  KEY `idx_users_deleted_at` (`deleted_at`),
  KEY `idx_users_total_points` (`total_points`),
  KEY `idx_users_role_active_del` (`role`,`is_active`,`deleted_at`),
  KEY `idx_users_approval_status` (`approval_status`),
  KEY `idx_users_newsletter` (`newsletter_unsubscribed`,`is_active`,`deleted_at`),
  KEY `mission_visitor_tag_id` (`mission_visitor_tag_id`),
  KEY `idx_users_mission_visitor` (`mission_visitor_mission_id`,`is_mission_visitor`),
  KEY `idx_users_dog_handler` (`is_dog_handler`),
  KEY `idx_users_team_captain` (`is_team_captain`),
  CONSTRAINT `fk_user_position` FOREIGN KEY (`position_id`) REFERENCES `volunteer_positions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_users_custom_role` FOREIGN KEY (`custom_role_id`) REFERENCES `custom_roles` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_users_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `users_ibfk_1` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `users_ibfk_2` FOREIGN KEY (`volunteer_team_id`) REFERENCES `volunteer_teams` (`id`) ON DELETE SET NULL,
  CONSTRAINT `users_ibfk_3` FOREIGN KEY (`mission_visitor_tag_id`) REFERENCES `mission_visitor_tags` (`id`) ON DELETE SET NULL,
  CONSTRAINT `users_ibfk_4` FOREIGN KEY (`mission_visitor_mission_id`) REFERENCES `missions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=512281 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `vehicle_checklist_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `vehicle_checklist_items` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `type` enum('car','motorbike','van','truck','boat','other') DEFAULT NULL COMMENT 'NULL = applies to every vehicle type',
  `label` varchar(255) NOT NULL,
  `sort_order` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_checklist_items_type` (`type`)
) ENGINE=InnoDB AUTO_INCREMENT=165 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `vehicle_handover_checks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `vehicle_handover_checks` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `handover_id` int(10) unsigned NOT NULL,
  `checklist_item_id` int(10) unsigned NOT NULL,
  `stage` enum('checkout','checkin') NOT NULL,
  `status` enum('ok','damaged','missing') NOT NULL DEFAULT 'ok',
  `note` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_handover_check` (`handover_id`,`checklist_item_id`,`stage`),
  KEY `checklist_item_id` (`checklist_item_id`),
  CONSTRAINT `vehicle_handover_checks_ibfk_1` FOREIGN KEY (`handover_id`) REFERENCES `vehicle_handovers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `vehicle_handover_checks_ibfk_2` FOREIGN KEY (`checklist_item_id`) REFERENCES `vehicle_checklist_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=151 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `vehicle_handovers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `vehicle_handovers` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `vehicle_id` int(10) unsigned NOT NULL,
  `shift_id` int(10) unsigned DEFAULT NULL,
  `checkout_by` int(10) unsigned DEFAULT NULL,
  `checkout_at` datetime NOT NULL,
  `checkout_km` decimal(10,1) DEFAULT NULL COMMENT 'Same unit as vehicles.odometer_unit for this vehicle',
  `checkout_fuel_level` tinyint(3) unsigned DEFAULT NULL COMMENT '0-100 percent',
  `checkout_notes` text DEFAULT NULL,
  `checkin_by` int(10) unsigned DEFAULT NULL,
  `checkin_at` datetime DEFAULT NULL COMMENT 'NULL = the vehicle is still out',
  `checkin_km` decimal(10,1) DEFAULT NULL,
  `checkin_fuel_level` tinyint(3) unsigned DEFAULT NULL,
  `checkin_notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `shift_id` (`shift_id`),
  KEY `checkout_by` (`checkout_by`),
  KEY `checkin_by` (`checkin_by`),
  KEY `idx_handovers_vehicle_open` (`vehicle_id`,`checkin_at`),
  CONSTRAINT `vehicle_handovers_ibfk_1` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `vehicle_handovers_ibfk_2` FOREIGN KEY (`shift_id`) REFERENCES `shifts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `vehicle_handovers_ibfk_3` FOREIGN KEY (`checkout_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `vehicle_handovers_ibfk_4` FOREIGN KEY (`checkin_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=141 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `vehicle_maintenance`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `vehicle_maintenance` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `vehicle_id` int(10) unsigned NOT NULL,
  `type` enum('scheduled','repair','inspection') NOT NULL DEFAULT 'repair',
  `description` text DEFAULT NULL,
  `cost` decimal(8,2) DEFAULT NULL,
  `workshop` varchar(255) DEFAULT NULL,
  `odometer_value` decimal(10,1) DEFAULT NULL,
  `status` enum('open','done') NOT NULL DEFAULT 'open',
  `opened_by` int(10) unsigned DEFAULT NULL,
  `opened_at` datetime NOT NULL,
  `closed_by` int(10) unsigned DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `opened_by` (`opened_by`),
  KEY `closed_by` (`closed_by`),
  KEY `idx_maintenance_vehicle_status` (`vehicle_id`,`status`),
  CONSTRAINT `vehicle_maintenance_ibfk_1` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `vehicle_maintenance_ibfk_2` FOREIGN KEY (`opened_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `vehicle_maintenance_ibfk_3` FOREIGN KEY (`closed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=273 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `vehicle_ping_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `vehicle_ping_tokens` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `vehicle_id` int(10) unsigned NOT NULL,
  `token_hash` char(64) NOT NULL,
  `label` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_used_at` timestamp NULL DEFAULT NULL,
  `revoked_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_vehicle_ping_token_hash` (`token_hash`),
  KEY `idx_vehicle_ping_tokens_vehicle` (`vehicle_id`,`revoked_at`),
  CONSTRAINT `vehicle_ping_tokens_ibfk_1` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=103 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `vehicle_pings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `vehicle_pings` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `vehicle_id` int(10) unsigned NOT NULL,
  `lat` decimal(10,8) NOT NULL,
  `lng` decimal(11,8) NOT NULL,
  `accuracy_meters` decimal(8,2) DEFAULT NULL,
  `speed_mps` decimal(6,2) DEFAULT NULL,
  `heading_deg` decimal(5,1) DEFAULT NULL,
  `source` varchar(50) DEFAULT NULL COMMENT 'free text identifying the sender, e.g. a device label - no fixed source list yet, the ingestion method is still undecided',
  `recorded_at` timestamp NULL DEFAULT NULL COMMENT 'when the sender says the fix happened; NULL = unknown, fall back to created_at',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp() COMMENT 'when the server received it',
  PRIMARY KEY (`id`),
  KEY `idx_vehicle_pings_vehicle_time` (`vehicle_id`,`created_at`),
  CONSTRAINT `vehicle_pings_ibfk_1` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=103 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `vehicle_refuels`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `vehicle_refuels` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `vehicle_id` int(10) unsigned NOT NULL,
  `liters` decimal(6,2) DEFAULT NULL,
  `cost` decimal(8,2) DEFAULT NULL,
  `odometer_value` decimal(10,1) DEFAULT NULL,
  `station` varchar(255) DEFAULT NULL,
  `user_id` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `idx_refuels_vehicle` (`vehicle_id`,`created_at`),
  CONSTRAINT `vehicle_refuels_ibfk_1` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `vehicle_refuels_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=133 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `vehicles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `vehicles` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `plate_number` varchar(20) NOT NULL COMMENT 'Registration/plate number, or a boat''s registration',
  `label` varchar(100) NOT NULL COMMENT 'Friendly name shown everywhere, e.g. "Όχημα 1 - Ford Ranger"',
  `type` enum('car','motorbike','van','truck','boat','other') NOT NULL DEFAULT 'car',
  `department_id` int(10) unsigned DEFAULT NULL,
  `odometer_value` decimal(10,1) DEFAULT NULL COMMENT 'Current reading; km for road vehicles, engine hours for a boat',
  `odometer_unit` enum('km','engine_hours') NOT NULL DEFAULT 'km',
  `fuel_type` varchar(30) DEFAULT NULL COMMENT 'Free text: βενζίνη, πετρέλαιο, υβριδικό, ηλεκτρικό...',
  `seats` tinyint(3) unsigned DEFAULT NULL,
  `status` enum('available','maintenance','out_of_service') NOT NULL DEFAULT 'available' COMMENT 'maintenance/out_of_service block NEW shift assignment (shift-form.php); an existing assignment is left alone',
  `notes` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_plate_number` (`plate_number`),
  KEY `department_id` (`department_id`),
  KEY `created_by` (`created_by`),
  KEY `idx_vehicles_status` (`status`),
  KEY `idx_vehicles_active` (`is_active`),
  CONSTRAINT `vehicles_ibfk_1` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `vehicles_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=555 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `volunteer_applications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `volunteer_applications` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `full_name` varchar(100) NOT NULL COMMENT 'Ονοματεπώνυμο',
  `patronymic` varchar(100) DEFAULT NULL COMMENT 'Πατρώνυμο',
  `birth_date` date DEFAULT NULL COMMENT 'Ημερομηνία Γέννησης',
  `address` varchar(255) DEFAULT NULL,
  `postal_code` varchar(10) DEFAULT NULL COMMENT 'Τ.Κ.',
  `city` varchar(100) DEFAULT NULL,
  `home_phone` varchar(30) DEFAULT NULL COMMENT 'Τηλέφωνο Οικίας',
  `mobile_phone` varchar(30) NOT NULL COMMENT 'Τηλέφωνο Κινητό',
  `email` varchar(255) NOT NULL,
  `occupation` varchar(150) DEFAULT NULL COMMENT 'Επαγγελματική ιδιότητα',
  `gdpr_consent_at` datetime NOT NULL,
  `status` enum('NEW','CONTACTED','CONVERTED','REJECTED') NOT NULL DEFAULT 'NEW',
  `admin_notes` text DEFAULT NULL,
  `contacted_at` datetime DEFAULT NULL,
  `converted_user_id` int(10) unsigned DEFAULT NULL,
  `converted_at` datetime DEFAULT NULL,
  `rejected_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `converted_user_id` (`converted_user_id`),
  KEY `idx_volunteer_applications_status` (`status`),
  KEY `idx_volunteer_applications_email` (`email`),
  CONSTRAINT `volunteer_applications_ibfk_1` FOREIGN KEY (`converted_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `volunteer_certificates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `volunteer_certificates` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `certificate_type_id` int(10) unsigned NOT NULL,
  `issue_date` date NOT NULL,
  `expiry_date` date DEFAULT NULL COMMENT 'NULL = never expires',
  `issuing_body` varchar(255) DEFAULT NULL,
  `certificate_number` varchar(100) DEFAULT NULL,
  `document_id` int(10) unsigned DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `reminder_sent_30` tinyint(1) NOT NULL DEFAULT 0,
  `reminder_sent_7` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_cert` (`user_id`,`certificate_type_id`),
  KEY `certificate_type_id` (`certificate_type_id`),
  KEY `document_id` (`document_id`),
  KEY `created_by` (`created_by`),
  KEY `idx_vc_expiry` (`expiry_date`),
  KEY `idx_vc_expiry_reminder` (`expiry_date`,`reminder_sent_30`,`reminder_sent_7`),
  CONSTRAINT `volunteer_certificates_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `volunteer_certificates_ibfk_2` FOREIGN KEY (`certificate_type_id`) REFERENCES `certificate_types` (`id`),
  CONSTRAINT `volunteer_certificates_ibfk_3` FOREIGN KEY (`document_id`) REFERENCES `volunteer_documents` (`id`) ON DELETE SET NULL,
  CONSTRAINT `volunteer_certificates_ibfk_4` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `volunteer_documents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `volunteer_documents` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `label` varchar(255) NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `stored_name` varchar(255) NOT NULL,
  `mime_type` varchar(100) NOT NULL,
  `file_size` int(11) NOT NULL DEFAULT 0,
  `uploaded_by` int(10) unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_vd_user_id` (`user_id`),
  KEY `fk_vd_uploader` (`uploaded_by`),
  CONSTRAINT `fk_vd_uploader` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_vd_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `volunteer_floor_baselines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `volunteer_floor_baselines` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `mission_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `hpa0` decimal(6,2) NOT NULL COMMENT 'This phone''s own pressure at ground level',
  `ref_user_id` int(10) unsigned DEFAULT NULL COMMENT 'A phone left outside, to cancel the weather',
  `ref_hpa0` decimal(6,2) DEFAULT NULL COMMENT 'The reference phone''s pressure at the same moment',
  `set_by` int(10) unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_floor_baseline_mission_user` (`mission_id`,`user_id`,`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `volunteer_floor_baselines_ibfk_1` FOREIGN KEY (`mission_id`) REFERENCES `missions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `volunteer_floor_baselines_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1696 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `volunteer_ping_refusal_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `volunteer_ping_refusal_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `mission_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `reason` varchar(20) NOT NULL,
  `via` enum('browser','native') DEFAULT NULL,
  `lat` decimal(10,8) DEFAULT NULL,
  `lng` decimal(11,8) DEFAULT NULL,
  `accuracy_m` decimal(8,2) DEFAULT NULL COMMENT 'Device-reported accuracy of the refused fix',
  `speed_mps` decimal(6,2) DEFAULT NULL COMMENT 'Device-reported (Doppler) speed, m/s',
  `implied_kmh` decimal(8,1) DEFAULT NULL COMMENT 'Speed the jump implied (implausible only)',
  `gnss_used` tinyint(3) unsigned DEFAULT NULL,
  `gnss_cn0` decimal(4,1) DEFAULT NULL,
  `fix_at` datetime NOT NULL COMMENT 'When the fix was TAKEN, like volunteer_pings.created_at',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_refusal_log_mission_user` (`mission_id`,`user_id`,`fix_at`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `volunteer_ping_refusal_log_ibfk_1` FOREIGN KEY (`mission_id`) REFERENCES `missions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `volunteer_ping_refusal_log_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=10068 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `volunteer_ping_refusals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `volunteer_ping_refusals` (
  `mission_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `reason` varchar(20) NOT NULL,
  `refused_count` int(10) unsigned NOT NULL DEFAULT 0,
  `last_refused_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`mission_id`,`user_id`,`reason`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `volunteer_ping_refusals_ibfk_1` FOREIGN KEY (`mission_id`) REFERENCES `missions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `volunteer_ping_refusals_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `volunteer_pings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `volunteer_pings` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `shift_id` int(10) unsigned NOT NULL,
  `lat` decimal(10,8) NOT NULL,
  `lng` decimal(11,8) NOT NULL,
  `accuracy_meters` decimal(8,2) DEFAULT NULL,
  `battery_level` tinyint(3) unsigned DEFAULT NULL,
  `source` enum('manual','auto') NOT NULL DEFAULT 'manual',
  `via` enum('browser','native','mesh') DEFAULT NULL COMMENT 'Which client produced the fix; NULL = unknown (pre-v159 rows); mesh = over the LoRa radio via the command post gateway',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `raw_lat` decimal(10,8) DEFAULT NULL COMMENT 'What the device reported; lat/lng hold the filtered estimate (v163). NULL = pre-v163 row, lat/lng are raw',
  `raw_lng` decimal(11,8) DEFAULT NULL,
  `raw_accuracy_m` decimal(8,2) DEFAULT NULL COMMENT 'Device-reported accuracy of raw_lat/raw_lng; accuracy_meters is the estimate''s',
  `speed_mps` decimal(6,2) DEFAULT NULL COMMENT 'Device-reported (Doppler) speed, m/s; NULL = not reported',
  `gnss_used` tinyint(3) unsigned DEFAULT NULL COMMENT 'Satellites used in the fix (Android app); NULL = not reported',
  `gnss_cn0` decimal(4,1) DEFAULT NULL COMMENT 'Mean C/N0 (dB-Hz) of the 4 strongest satellites used',
  `gnss_dual` tinyint(1) DEFAULT NULL COMMENT '1 = a second frequency (L5/E5a/B2a) was used',
  PRIMARY KEY (`id`),
  KEY `idx_pings_shift_time` (`shift_id`,`created_at`),
  KEY `idx_pings_user_shift` (`user_id`,`shift_id`),
  KEY `idx_pings_shift_user` (`shift_id`,`user_id`),
  KEY `idx_pings_shift_source_time` (`shift_id`,`source`,`created_at`),
  CONSTRAINT `volunteer_pings_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `volunteer_pings_ibfk_2` FOREIGN KEY (`shift_id`) REFERENCES `shifts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=90576 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `volunteer_points`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `volunteer_points` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `points` int(11) NOT NULL,
  `reason` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  `pointable_type` varchar(100) DEFAULT NULL,
  `pointable_id` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_points_user` (`user_id`),
  KEY `idx_points_created` (`created_at`),
  KEY `idx_points_user_date` (`user_id`,`created_at`),
  KEY `idx_points_user_reason` (`user_id`,`reason`),
  KEY `idx_vp_user_points` (`user_id`,`points`,`created_at`),
  CONSTRAINT `volunteer_points_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=56 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `volunteer_positions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `volunteer_positions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `color` varchar(20) DEFAULT 'secondary',
  `icon` varchar(50) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `sort_order` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `volunteer_pressure`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `volunteer_pressure` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `mission_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `hpa` decimal(6,2) NOT NULL COMMENT 'Air pressure in hPa, as that phone measured it',
  `created_at` datetime NOT NULL COMMENT 'When the reading was TAKEN, like volunteer_pings.created_at',
  PRIMARY KEY (`id`),
  KEY `idx_pressure_mission_user` (`mission_id`,`user_id`,`id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `volunteer_pressure_ibfk_1` FOREIGN KEY (`mission_id`) REFERENCES `missions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `volunteer_pressure_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=13561 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `volunteer_profiles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `volunteer_profiles` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `bio` text DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `postal_code` varchar(10) DEFAULT NULL,
  `emergency_contact_name` varchar(100) DEFAULT NULL,
  `emergency_contact_phone` varchar(20) DEFAULT NULL,
  `blood_type` varchar(5) DEFAULT NULL,
  `medical_notes` text DEFAULT NULL,
  `available_weekdays` tinyint(1) DEFAULT 1,
  `available_weekends` tinyint(1) DEFAULT 1,
  `available_nights` tinyint(1) DEFAULT 0,
  `has_driving_license` tinyint(1) DEFAULT 0,
  `has_first_aid` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`),
  CONSTRAINT `volunteer_profiles_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `volunteer_subscriptions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `volunteer_subscriptions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `payment_date` date NOT NULL,
  `expiry_date` date NOT NULL,
  `renewal_kind` enum('RENEWAL','REACTIVATION') NOT NULL DEFAULT 'RENEWAL',
  `coverage_years` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `amount` decimal(10,2) DEFAULT NULL,
  `payment_method` varchar(50) DEFAULT NULL,
  `receipt_number` varchar(100) DEFAULT NULL,
  `receipt_original_name` varchar(255) DEFAULT NULL,
  `receipt_stored_name` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `reminder_sent_3m` tinyint(1) NOT NULL DEFAULT 0,
  `reminder_sent_1m` tinyint(1) NOT NULL DEFAULT 0,
  `reminder_sent_1w` tinyint(1) NOT NULL DEFAULT 0,
  `reminder_sent_expired` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_vs_user_expiry` (`user_id`,`expiry_date`),
  KEY `idx_vs_reminders` (`expiry_date`,`reminder_sent_3m`,`reminder_sent_1m`,`reminder_sent_1w`,`reminder_sent_expired`),
  KEY `fk_vs_creator` (`created_by`),
  CONSTRAINT `fk_vs_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_vs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `volunteer_teams`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `volunteer_teams` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `color` varchar(7) NOT NULL DEFAULT '#6c757d',
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_volunteer_teams_name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=1330 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `volunteer_vitals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `volunteer_vitals` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `shift_id` int(10) unsigned NOT NULL,
  `bpm` smallint(5) unsigned NOT NULL,
  `bpm_min` smallint(5) unsigned DEFAULT NULL,
  `bpm_max` smallint(5) unsigned DEFAULT NULL,
  `source` enum('ble','manual','simulated') NOT NULL DEFAULT 'ble',
  `device_name` varchar(64) DEFAULT NULL,
  `recorded_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_vitals_sample` (`user_id`,`shift_id`,`recorded_at`),
  KEY `idx_vitals_shift_user` (`shift_id`,`user_id`,`id`),
  KEY `idx_vitals_recorded` (`recorded_at`),
  CONSTRAINT `volunteer_vitals_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `volunteer_vitals_ibfk_2` FOREIGN KEY (`shift_id`) REFERENCES `shifts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `war_room_layouts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `war_room_layouts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `layout_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`layout_json`)),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_war_room_layouts_user` (`user_id`),
  CONSTRAINT `war_room_layouts_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=422 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `weather_cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `weather_cache` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `mission_id` int(10) unsigned NOT NULL,
  `weather_json` text NOT NULL,
  `fetched_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_weather_mission` (`mission_id`),
  CONSTRAINT `weather_cache_ibfk_1` FOREIGN KEY (`mission_id`) REFERENCES `missions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

