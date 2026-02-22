<?php
/**
 * VolunteerOps - Auto Migrations
 * Runs pending schema migrations automatically on each request.
 * Tracks applied migrations in the settings table (db_schema_version).
 * Safe to run multiple times — each migration checks before applying.
 */

if (!defined('VOLUNTEEROPS')) {
    die('Direct access not permitted');
}

/**
 * Run all pending migrations.
 * Add new migrations at the bottom of the $migrations array.
 */
if (!function_exists('runSchemaMigrations')) {
function runSchemaMigrations(): void {
    // Read current schema version (0 = fresh install, no migrations tracked yet)
    try {
        $currentVersion = (int) dbFetchValue(
            "SELECT setting_value FROM settings WHERE setting_key = 'db_schema_version'"
        );
    } catch (Exception $e) {
        // settings table may not exist yet (install in progress)
        return;
    }

    // ── Migration definitions ────────────────────────────────────────────────
    // Each entry: [version (int), description (str), callable]
    // The callable receives no arguments. Throw an exception on failure.
    $migrations = [

        [
            'version'     => 1,
            'description' => 'Add deleted_at + deleted_by to users (soft delete)',
            'up' => function () {
                // Check and add deleted_at
                $col = dbFetchOne(
                    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME   = 'users'
                       AND COLUMN_NAME  = 'deleted_at'"
                );
                if (!$col) {
                    dbExecute("ALTER TABLE users ADD COLUMN deleted_at TIMESTAMP NULL DEFAULT NULL AFTER updated_at");
                }

                // Check and add deleted_by
                $col2 = dbFetchOne(
                    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME   = 'users'
                       AND COLUMN_NAME  = 'deleted_by'"
                );
                if (!$col2) {
                    dbExecute("ALTER TABLE users ADD COLUMN deleted_by INT UNSIGNED NULL DEFAULT NULL AFTER deleted_at");
                }
            },
        ],

        // ── Add future migrations below this line ──────────────────────────

        [
            'version'     => 2,
            'description' => 'Create inventory booking triggers (trg_booking_insert, trg_booking_return)',
            'up' => function () {
                // Only run if inventory_bookings table exists
                $tableExists = dbFetchOne(
                    "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME = 'inventory_bookings'"
                );
                if (!$tableExists) return;

                $pdo = db();

                // trg_booking_insert: mark item as booked on active booking insert
                // Uses WHERE ... AND NEW.status = 'active' instead of IF/BEGIN/END
                // so it works without DELIMITER in PDO
                $pdo->exec("DROP TRIGGER IF EXISTS `trg_booking_insert`");
                $pdo->exec("
                    CREATE TRIGGER `trg_booking_insert`
                    AFTER INSERT ON `inventory_bookings`
                    FOR EACH ROW
                      UPDATE `inventory_items`
                      SET `status`              = 'booked',
                          `booked_by_user_id`   = NEW.user_id,
                          `booked_by_name`      = NEW.volunteer_name,
                          `booking_date`        = NEW.created_at,
                          `expected_return_date`= NEW.expected_return_date
                      WHERE `id` = NEW.item_id
                        AND NEW.status = 'active'
                ");

                // trg_booking_return: mark item as available when booking returned/lost
                // Uses IF() function instead of IF/THEN/END IF to avoid BEGIN/END
                $pdo->exec("DROP TRIGGER IF EXISTS `trg_booking_return`");
                $pdo->exec("
                    CREATE TRIGGER `trg_booking_return`
                    AFTER UPDATE ON `inventory_bookings`
                    FOR EACH ROW
                      UPDATE `inventory_items`
                      SET `status`            = IF(NEW.status IN ('returned','lost') AND OLD.status NOT IN ('returned','lost'), 'available',   `status`),
                          `booked_by_user_id` = IF(NEW.status IN ('returned','lost') AND OLD.status NOT IN ('returned','lost'), NULL,          `booked_by_user_id`),
                          `booked_by_name`    = IF(NEW.status IN ('returned','lost') AND OLD.status NOT IN ('returned','lost'), NULL,          `booked_by_name`),
                          `booking_date`      = IF(NEW.status IN ('returned','lost') AND OLD.status NOT IN ('returned','lost'), NULL,          `booking_date`)
                      WHERE `id` = NEW.item_id
                ");
            },
        ],

        [
            'version'     => 3,
            'description' => 'Create newsletter tables + newsletter_unsubscribed column on users',
            'up' => function () {
                // 1. newsletters table
                $t = dbFetchOne("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'newsletters'");
                if (!$t) {
                    dbExecute("CREATE TABLE `newsletters` (
                        `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
                        `title`             VARCHAR(255) NOT NULL,
                        `subject`           VARCHAR(255) NOT NULL,
                        `body_html`         MEDIUMTEXT NOT NULL,
                        `status`            ENUM('draft','sending','sent','failed') NOT NULL DEFAULT 'draft',
                        `filter_roles`      JSON NULL COMMENT 'Array of roles to send to, NULL = all',
                        `filter_dept_id`    INT UNSIGNED NULL COMMENT 'Limit to one department, NULL = all',
                        `total_recipients`  INT UNSIGNED NOT NULL DEFAULT 0,
                        `sent_count`        INT UNSIGNED NOT NULL DEFAULT 0,
                        `failed_count`      INT UNSIGNED NOT NULL DEFAULT 0,
                        `created_by`        INT UNSIGNED NOT NULL,
                        `sent_at`           TIMESTAMP NULL DEFAULT NULL,
                        `created_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        `updated_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        PRIMARY KEY (`id`),
                        KEY `idx_newsletters_status` (`status`),
                        KEY `idx_newsletters_created_by` (`created_by`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                }

                // 2. newsletter_sends table
                $t2 = dbFetchOne("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'newsletter_sends'");
                if (!$t2) {
                    dbExecute("CREATE TABLE `newsletter_sends` (
                        `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
                        `newsletter_id`    INT UNSIGNED NOT NULL,
                        `user_id`          INT UNSIGNED NULL,
                        `email`            VARCHAR(255) NOT NULL,
                        `name`             VARCHAR(255) NOT NULL DEFAULT '',
                        `status`           ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
                        `error_msg`        TEXT NULL,
                        `sent_at`          TIMESTAMP NULL DEFAULT NULL,
                        PRIMARY KEY (`id`),
                        KEY `idx_ns_newsletter_id` (`newsletter_id`),
                        KEY `idx_ns_user_id` (`user_id`),
                        KEY `idx_ns_status` (`status`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                }

                // 3. newsletter_unsubscribes table
                $t3 = dbFetchOne("SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'newsletter_unsubscribes'");
                if (!$t3) {
                    dbExecute("CREATE TABLE `newsletter_unsubscribes` (
                        `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
                        `user_id`             INT UNSIGNED NULL,
                        `email`               VARCHAR(255) NOT NULL,
                        `token`               VARCHAR(64) NOT NULL,
                        `newsletter_id`       INT UNSIGNED NULL COMMENT 'Campaign that triggered unsubscribe',
                        `unsubscribed_at`     TIMESTAMP NULL DEFAULT NULL,
                        `created_at`          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        PRIMARY KEY (`id`),
                        UNIQUE KEY `uq_nu_token` (`token`),
                        KEY `idx_nu_email` (`email`),
                        KEY `idx_nu_user_id` (`user_id`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                }

                // 4. Add newsletter_unsubscribed column to users
                $col = dbFetchOne("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'newsletter_unsubscribed'");
                if (!$col) {
                    dbExecute("ALTER TABLE users ADD COLUMN `newsletter_unsubscribed` TINYINT(1) NOT NULL DEFAULT 0 AFTER `deleted_by`");
                }
            },
        ],

        [
            'version'     => 4,
            'description' => 'Rename completed_at to submitted_at in exam/quiz attempts; add profile_photo to users',
            'up' => function () {
                // 1. exam_attempts: completed_at -> submitted_at
                $col = dbFetchOne(
                    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME   = 'exam_attempts'
                       AND COLUMN_NAME  = 'completed_at'"
                );
                if ($col) {
                    dbExecute("ALTER TABLE exam_attempts CHANGE COLUMN completed_at submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP");
                }

                // 2. quiz_attempts: completed_at -> submitted_at
                $col2 = dbFetchOne(
                    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME   = 'quiz_attempts'
                       AND COLUMN_NAME  = 'completed_at'"
                );
                if ($col2) {
                    dbExecute("ALTER TABLE quiz_attempts CHANGE COLUMN completed_at submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP");
                }

                // 3. users: add profile_photo
                $col3 = dbFetchOne(
                    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME   = 'users'
                       AND COLUMN_NAME  = 'profile_photo'"
                );
                if (!$col3) {
                    dbExecute("ALTER TABLE users ADD COLUMN profile_photo VARCHAR(255) NULL DEFAULT NULL AFTER phone");
                }
            },
        ],

        [
            'version'     => 5,
            'description' => 'Add cohort_year to users',
            'up' => function () {
                $col = dbFetchOne(
                    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME   = 'users'
                       AND COLUMN_NAME  = 'cohort_year'"
                );
                if (!$col) {
                    dbExecute("ALTER TABLE users ADD COLUMN cohort_year SMALLINT NULL DEFAULT NULL AFTER profile_photo");
                }
            },
        ],

        [
            'version'     => 6,
            'description' => 'Create skill_categories table and migrate existing categories',
            'up' => function () {
                // Create skill_categories table
                $tbl = dbFetchOne(
                    "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'skill_categories'"
                );
                if (!$tbl) {
                    dbExecute("CREATE TABLE skill_categories (
                        id   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        name VARCHAR(100) NOT NULL UNIQUE,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                }
                // Migrate existing distinct category names from skills table
                $existing = dbFetchAll(
                    "SELECT DISTINCT category FROM skills WHERE category IS NOT NULL AND category != ''"
                );
                foreach ($existing as $row) {
                    dbExecute(
                        "INSERT IGNORE INTO skill_categories (name) VALUES (?)",
                        [$row['category']]
                    );
                }
            },
        ],

        [
            'version'     => 7,
            'description' => 'Revert submitted_at rename back to completed_at (nullable) on quiz_attempts and exam_attempts',
            'up' => function () {
                // quiz_attempts: submitted_at -> completed_at TIMESTAMP NULL
                $col = dbFetchOne(
                    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME   = 'quiz_attempts'
                       AND COLUMN_NAME  = 'submitted_at'"
                );
                if ($col) {
                    dbExecute("ALTER TABLE quiz_attempts CHANGE COLUMN submitted_at completed_at TIMESTAMP NULL DEFAULT NULL");
                }
                // exam_attempts: submitted_at -> completed_at TIMESTAMP NULL
                $col2 = dbFetchOne(
                    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME   = 'exam_attempts'
                       AND COLUMN_NAME  = 'submitted_at'"
                );
                if ($col2) {
                    dbExecute("ALTER TABLE exam_attempts CHANGE COLUMN submitted_at completed_at TIMESTAMP NULL DEFAULT NULL");
                }
            },
        ],

        [
            'version'     => 8,
            'description' => 'Insert admin_added_volunteer and points_earned email templates and notification settings',
            'up' => function () {
                // admin_added_volunteer email template
                $existing = dbFetchOne("SELECT id FROM email_templates WHERE code = 'admin_added_volunteer'");
                if (!$existing) {
                    $bodyHtml  = '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">';
                    $bodyHtml .= '<div style="background: #2c3e50; color: white; padding: 20px; text-align: center;">';
                    $bodyHtml .= '<h1>&#128203; Τοποθέτηση σε Βάρδια</h1>';
                    $bodyHtml .= '</div>';
                    $bodyHtml .= '<div style="padding: 30px; background: #fff;">';
                    $bodyHtml .= '<h2>Γεια σας {{user_name}},</h2>';
                    $bodyHtml .= '<p>Ο διαχειριστής σας τοποθέτησε απευθείας στην παρακάτω βάρδια:</p>';
                    $bodyHtml .= '<div style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #2c3e50;">';
                    $bodyHtml .= '<p><strong>Αποστολή:</strong> {{mission_title}}</p>';
                    $bodyHtml .= '<p><strong>Ημερομηνία:</strong> {{shift_date}}</p>';
                    $bodyHtml .= '<p><strong>Ώρα:</strong> {{shift_time}}</p>';
                    $bodyHtml .= '<p><strong>Τοποθεσία:</strong> {{location}}</p>';
                    $bodyHtml .= '</div>';
                    $bodyHtml .= '{{#admin_notes}}<div style="background: #fff3cd; padding: 15px; border-radius: 8px; margin: 15px 0;">';
                    $bodyHtml .= '<p><strong>Σημείωση διαχειριστή:</strong> {{admin_notes}}</p>';
                    $bodyHtml .= '</div>{{/admin_notes}}';
                    $bodyHtml .= '<p>Παρακαλούμε να είστε στην τοποθεσία έγκαιρα.</p>';
                    $bodyHtml .= '<p style="text-align: center; margin-top: 30px;">';
                    $bodyHtml .= '<a href="{{login_url}}" style="background: #2c3e50; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px;">Σύνδεση στην Πλατφόρμα</a>';
                    $bodyHtml .= '</p>';
                    $bodyHtml .= '</div>';
                    $bodyHtml .= '<div style="padding: 15px; background: #f8f9fa; text-align: center; font-size: 12px; color: #666;">';
                    $bodyHtml .= '{{app_name}} - Σύστημα Διαχείρισης Εθελοντών';
                    $bodyHtml .= '</div>';
                    $bodyHtml .= '</div>';

                    dbInsert(
                        "INSERT INTO email_templates (code, name, subject, body_html, description, available_variables)
                         VALUES (?, ?, ?, ?, ?, ?)",
                        [
                            'admin_added_volunteer',
                            'Προσθήκη από Διαχειριστή',
                            'Ο διαχειριστής σας τοποθέτησε απευθείας σε βάρδια',
                            $bodyHtml,
                            'Αποστέλλεται στον εθελοντή όταν ο διαχειριστής τον προσθέτει απευθείας σε βάρδια',
                            '{{app_name}}, {{user_name}}, {{mission_title}}, {{shift_date}}, {{shift_time}}, {{location}}, {{admin_notes}}, {{login_url}}',
                        ]
                    );
                }

                // points_earned email template
                $existing2 = dbFetchOne("SELECT id FROM email_templates WHERE code = 'points_earned'");
                if (!$existing2) {
                    $bodyHtml2  = '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">';
                    $bodyHtml2 .= '<div style="background: #27ae60; color: white; padding: 20px; text-align: center;">';
                    $bodyHtml2 .= '<h1>&#127881; Συγχαρητήρια!</h1>';
                    $bodyHtml2 .= '</div>';
                    $bodyHtml2 .= '<div style="padding: 30px; background: #fff;">';
                    $bodyHtml2 .= '<h2>Γεια σας {{user_name}},</h2>';
                    $bodyHtml2 .= '<p style="font-size: 24px; text-align: center; color: #27ae60;"><strong>+{{points}} πόντοι</strong></p>';
                    $bodyHtml2 .= '<div style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0;">';
                    $bodyHtml2 .= '<p><strong>Βάρδια:</strong> {{shift_date}}</p>';
                    $bodyHtml2 .= '<p><strong>Αποστολή:</strong> {{mission_title}}</p>';
                    $bodyHtml2 .= '</div>';
                    $bodyHtml2 .= '<p>Συνολικοί πόντοι: <strong>{{total_points}}</strong></p>';
                    $bodyHtml2 .= '</div>';
                    $bodyHtml2 .= '<div style="padding: 15px; background: #f8f9fa; text-align: center; font-size: 12px; color: #666;">';
                    $bodyHtml2 .= '{{app_name}}';
                    $bodyHtml2 .= '</div>';
                    $bodyHtml2 .= '</div>';

                    dbInsert(
                        "INSERT INTO email_templates (code, name, subject, body_html, description, available_variables)
                         VALUES (?, ?, ?, ?, ?, ?)",
                        [
                            'points_earned',
                            'Κέρδος Πόντων',
                            'Κερδίσατε {{points}} πόντους!',
                            $bodyHtml2,
                            'Αποστέλλεται όταν ο εθελοντής κερδίζει πόντους',
                            '{{app_name}}, {{user_name}}, {{points}}, {{mission_title}}, {{shift_date}}, {{total_points}}',
                        ]
                    );
                }

                // admin_added_volunteer notification setting
                $ns1 = dbFetchOne("SELECT id FROM notification_settings WHERE code = 'admin_added_volunteer'");
                if (!$ns1) {
                    $tmplId = dbFetchValue("SELECT id FROM email_templates WHERE code = 'admin_added_volunteer'");
                    dbInsert(
                        "INSERT INTO notification_settings (code, name, description, email_enabled, email_template_id)
                         VALUES (?, ?, ?, 1, ?)",
                        [
                            'admin_added_volunteer',
                            'Προσθήκη από Διαχειριστή',
                            'Όταν ο διαχειριστής προσθέτει εθελοντή απευθείας σε βάρδια',
                            $tmplId,
                        ]
                    );
                }

                // points_earned notification setting
                $ns2 = dbFetchOne("SELECT id FROM notification_settings WHERE code = 'points_earned'");
                if (!$ns2) {
                    $tmplId2 = dbFetchValue("SELECT id FROM email_templates WHERE code = 'points_earned'");
                    dbInsert(
                        "INSERT INTO notification_settings (code, name, description, email_enabled, email_template_id)
                         VALUES (?, ?, ?, 0, ?)",
                        [
                            'points_earned',
                            'Κέρδος Πόντων',
                            'Όταν ο εθελοντής κερδίζει πόντους',
                            $tmplId2,
                        ]
                    );
                }
            },
        ],

    ];
    // ────────────────────────────────────────────────────────────────────────

    $highest = $currentVersion;

    foreach ($migrations as $migration) {
        if ($migration['version'] <= $currentVersion) {
            continue; // already applied
        }

        try {
            ($migration['up'])();
            $highest = max($highest, $migration['version']);
        } catch (Exception $e) {
            // Log but don't crash the app — migration will retry next request
            error_log(
                "[migrations] Failed migration v{$migration['version']} " .
                "({$migration['description']}): " . $e->getMessage()
            );
            break; // stop at the first failure so order is preserved
        }
    }

    // Persist the highest successfully applied version
    if ($highest > $currentVersion) {
        dbExecute(
            "INSERT INTO settings (setting_key, setting_value, updated_at)
             VALUES ('db_schema_version', ?, NOW())
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()",
            [(string) $highest]
        );
    }
} // end function runSchemaMigrations
} // end function_exists check

// Run on every request (fast: single SELECT if already up-to-date)
runSchemaMigrations();
