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

    // ── Quick return if already up-to-date ───────────────────────────────────
    // IMPORTANT: Update DB_SCHEMA_VERSION in config.php whenever you add a new migration!
    // This prevents PHP from building ~180KB of closures on every page load.
    $LATEST_MIGRATION_VERSION = defined('DB_SCHEMA_VERSION') ? DB_SCHEMA_VERSION : 51;
    if ($currentVersion >= $LATEST_MIGRATION_VERSION) {
        return;
    }

    // ── Cooldown after migration failure ─────────────────────────────────────
    // If a migration failed recently, don't retry for 5 minutes to avoid
    // hammering the server on every request when a migration is stuck.
    try {
        $lastFailure = dbFetchValue(
            "SELECT setting_value FROM settings WHERE setting_key = 'migration_last_failure'"
        );
        if ($lastFailure && (time() - (int)$lastFailure) < 300) {
            return; // Wait 5 minutes before retrying
        }
    } catch (Exception $e) {
        // Ignore — settings table might not have this key yet
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

        [
            'version'     => 9,
            'description' => 'Beautify all email templates with modern responsive design + enable points_earned email',
            'up' => function () {
                // ── Shared builder helpers (anonymous fns, safe for repeated migration runs) ──

                $outer = function (string $h, string $b, string $f) : string {
                    return '<div style="background:#eef2f7;padding:28px 0 40px;font-family:Helvetica Neue,Arial,sans-serif;">'
                         . '<div style="max-width:600px;margin:0 auto;">'
                         . $h . $b . $f
                         . '</div></div>';
                };

                $hdr = function (string $c, string $icon, string $title) : string {
                    return '<div style="background:' . $c . ';padding:30px 40px 26px;border-radius:12px 12px 0 0;text-align:center;">'
                         . '<p style="color:rgba(255,255,255,0.7);font-size:11px;letter-spacing:2px;text-transform:uppercase;margin:0 0 8px;">{{app_name}}</p>'
                         . '<div style="font-size:36px;line-height:1;margin:0 0 8px;">' . $icon . '</div>'
                         . '<h1 style="color:#fff;margin:0;font-size:23px;font-weight:700;line-height:1.3;">' . $title . '</h1>'
                         . '</div>';
                };

                $bdy = function (string $inner) : string {
                    return '<div style="background:#fff;padding:36px 40px 40px;border-radius:0 0 12px 12px;box-shadow:0 4px 20px rgba(0,0,0,0.07);">'
                         . $inner . '</div>';
                };

                $ftr = function () : string {
                    return '<div style="text-align:center;padding:18px 0 0;color:#9ca3af;font-size:12px;line-height:1.9;">'
                         . '<p style="margin:0;"><strong style="color:#6b7280;">{{app_name}}</strong> &bull; Σύστημα Διαχείρισης Εθελοντών</p>'
                         . '<p style="margin:0;">Αυτό το μήνυμα στάλθηκε αυτόματα από το σύστημα.</p>'
                         . '</div>';
                };

                $card = function (string $c, array $rows) : string {
                    $html = '<div style="background:#f9fafb;border-left:4px solid ' . $c . ';padding:2px 20px;border-radius:0 8px 8px 0;margin:20px 0;">';
                    foreach ($rows as [$l, $v]) {
                        $html .= '<div style="padding:7px 0;font-size:14px;border-bottom:1px solid #f3f4f6;">'
                               . '<span style="color:#9ca3af;display:inline-block;min-width:130px;">' . $l . '</span>'
                               . '<span style="color:#111827;font-weight:600;">' . $v . '</span></div>';
                    }
                    return $html . '</div>';
                };

                $btn = function (string $url, string $lbl, string $c) : string {
                    return '<div style="text-align:center;margin:28px 0 4px;">'
                         . '<a href="' . $url . '" style="background:' . $c . ';color:#ffffff;text-decoration:none;'
                         . 'padding:13px 38px;border-radius:8px;font-size:15px;font-weight:700;display:inline-block;letter-spacing:0.3px;">'
                         . $lbl . '</a></div>';
                };

                $greet = function () : string {
                    return '<h2 style="color:#1f2937;font-size:18px;font-weight:700;margin:0 0 14px;">Γεια σας {{user_name}},</h2>';
                };

                $p = function (string $txt) : string {
                    return '<p style="color:#4b5563;line-height:1.65;font-size:15px;margin:0 0 14px;">' . $txt . '</p>';
                };

                // ── WELCOME ──────────────────────────────────────────────────────────────
                $inner = $greet()
                    . $p('Ευχαριστούμε που εγγραφήκατε στην πλατφόρμα εθελοντισμού <strong>{{app_name}}</strong>. Είστε πλέον μέλος της ομάδας μας!')
                    . '<div style="background:#eff6ff;border-left:4px solid #2563eb;padding:14px 20px;border-radius:0 8px 8px 0;margin:20px 0;">'
                    . '<p style="color:#1e40af;font-weight:700;font-size:14px;margin:0 0 8px;">Τι μπορείτε να κάνετε:</p>'
                    . '<div style="font-size:14px;color:#374151;line-height:1.9;">'
                    . '<div>&#10003;&nbsp; Δείτε τις διαθέσιμες αποστολές</div>'
                    . '<div>&#10003;&nbsp; Δηλώσετε συμμετοχή σε βάρδιες</div>'
                    . '<div>&#10003;&nbsp; Κερδίσετε πόντους και επιτεύγματα</div>'
                    . '</div></div>'
                    . $btn('{{login_url}}', 'Σύνδεση στην Πλατφόρμα', '#2563eb');
                $html = $outer($hdr('#2563eb', '&#127881;', 'Καλώς ήρθατε!'), $bdy($inner), $ftr());
                dbExecute("UPDATE email_templates SET body_html = ?, subject = 'Καλώς ήρθατε στο {{app_name}}!' WHERE code = 'welcome'", [$html]);

                // ── PARTICIPATION APPROVED ────────────────────────────────────────────────
                $inner = $greet()
                    . $p('Χαρούμαστε να σας ενημερώσουμε ότι η αίτηση συμμετοχής σας <strong>εγκρίθηκε</strong>! Σας περιμένουμε στη βάρδια!')
                    . $card('#16a34a', [
                        ['Αποστολή:', '{{mission_title}}'],
                        ['Ημερομηνία:', '{{shift_date}}'],
                        ['Ώρα:', '{{shift_time}}'],
                        ['Τοποθεσία:', '{{location}}'],
                    ])
                    . $p('Παρακαλούμε να είστε στην τοποθεσία έγκαιρα. Σε περίπτωση αδυναμίας, ενημερώστε μας το συντομότερο.')
                    . $btn('{{login_url}}', 'Δείτε τις Λεπτομέρειες', '#16a34a');
                $html = $outer($hdr('#16a34a', '&#10003;', 'Η Συμμετοχή σας Εγκρίθηκε!'), $bdy($inner), $ftr());
                dbExecute("UPDATE email_templates SET body_html = ?, subject = 'Εγκρίθηκε η συμμετοχή σας - {{mission_title}}' WHERE code = 'participation_approved'", [$html]);

                // ── PARTICIPATION REJECTED ────────────────────────────────────────────────
                $inner = $greet()
                    . $p('Δυστυχώς, η αίτηση συμμετοχής σας <strong>δεν εγκρίθηκε</strong> αυτή τη φορά.')
                    . $card('#dc2626', [
                        ['Αποστολή:', '{{mission_title}}'],
                        ['Βάρδια:', '{{shift_date}}'],
                    ])
                    . $p('Ελπίζουμε να σας δούμε στην επόμενη ευκαιρία! Μπορείτε να δηλώσετε συμμετοχή σε άλλες διαθέσιμες βάρδιες.')
                    . $btn('{{login_url}}', 'Δείτε Άλλες Βάρδιες', '#dc2626');
                $html = $outer($hdr('#dc2626', '&#9888;', 'Ενημέρωση Αίτησης Συμμετοχής'), $bdy($inner), $ftr());
                dbExecute("UPDATE email_templates SET body_html = ?, subject = 'Ενημέρωση αίτησης συμμετοχής - {{mission_title}}' WHERE code = 'participation_rejected'", [$html]);

                // ── SHIFT REMINDER ────────────────────────────────────────────────────────
                $inner = $greet()
                    . $p('Σας υπενθυμίζουμε ότι <strong>αύριο</strong> έχετε μια προγραμματισμένη βάρδια. Είστε έτοιμοι;')
                    . $card('#d97706', [
                        ['Αποστολή:', '{{mission_title}}'],
                        ['Ημερομηνία:', '{{shift_date}}'],
                        ['Ώρα:', '{{shift_time}}'],
                        ['Τοποθεσία:', '{{location}}'],
                    ])
                    . $p('Σε περίπτωση αδυναμίας, παρακαλούμε ενημερώστε μας το συντομότερο δυνατό.')
                    . $btn('{{login_url}}', 'Δείτε τη Βάρδια', '#d97706');
                $html = $outer($hdr('#d97706', '&#9200;', 'Υπενθύμιση Αυριανής Βάρδιας'), $bdy($inner), $ftr());
                dbExecute("UPDATE email_templates SET body_html = ?, subject = 'Υπενθύμιση: Αύριο έχετε βάρδια - {{mission_title}}' WHERE code = 'shift_reminder'", [$html]);

                // ── NEW MISSION ───────────────────────────────────────────────────────────
                $inner = '<h2 style="color:#1f2937;font-size:20px;font-weight:700;margin:0 0 12px;">{{mission_title}}</h2>'
                    . $p('{{mission_description}}')
                    . $card('#7c3aed', [
                        ['Τοποθεσία:', '{{location}}'],
                        ['Έναρξη:', '{{start_date}}'],
                        ['Λήξη:', '{{end_date}}'],
                    ])
                    . $p('Βιαστείτε — οι θέσεις είναι περιορισμένες! Δηλώστε συμμετοχή σήμερα.')
                    . $btn('{{mission_url}}', 'Δηλώστε Συμμετοχή', '#7c3aed');
                $html = $outer($hdr('#7c3aed', '&#128640;', 'Νέα Αποστολή Διαθέσιμη!'), $bdy($inner), $ftr());
                dbExecute("UPDATE email_templates SET body_html = ?, subject = 'Νέα αποστολή: {{mission_title}}' WHERE code = 'new_mission'", [$html]);

                // ── MISSION CANCELED ──────────────────────────────────────────────────────
                $inner = $greet()
                    . $p('Σας ενημερώνουμε ότι η παρακάτω αποστολή <strong>ακυρώθηκε</strong>.')
                    . $card('#dc2626', [
                        ['Αποστολή:', '{{mission_title}}'],
                    ])
                    . $p('Ζητούμε συγγνώμη για την αναστάτωση. Ελπίζουμε να σας δούμε σε μελλοντικές αποστολές!')
                    . $btn('{{login_url}}', 'Δείτε Άλλες Αποστολές', '#dc2626');
                $html = $outer($hdr('#dc2626', '&#9888;', 'Ακύρωση Αποστολής'), $bdy($inner), $ftr());
                dbExecute("UPDATE email_templates SET body_html = ?, subject = 'Ακυρώθηκε η αποστολή: {{mission_title}}' WHERE code = 'mission_canceled'", [$html]);

                // ── SHIFT CANCELED ────────────────────────────────────────────────────────
                $inner = $greet()
                    . $p('Σας ενημερώνουμε ότι η βάρδια στην οποία είχατε δηλώσει συμμετοχή <strong>ακυρώθηκε</strong>.')
                    . $card('#dc2626', [
                        ['Αποστολή:', '{{mission_title}}'],
                        ['Βάρδια:', '{{shift_date}}'],
                        ['Ώρα:', '{{shift_time}}'],
                    ])
                    . $p('Ζητούμε συγγνώμη για την αναστάτωση. Μπορείτε να δηλώσετε συμμετοχή σε άλλες διαθέσιμες βάρδιες.')
                    . $btn('{{login_url}}', 'Δείτε Άλλες Βάρδιες', '#dc2626');
                $html = $outer($hdr('#dc2626', '&#9888;', 'Ακύρωση Βάρδιας'), $bdy($inner), $ftr());
                dbExecute("UPDATE email_templates SET body_html = ?, subject = 'Ακυρώθηκε η βάρδια - {{mission_title}} ({{shift_date}})' WHERE code = 'shift_canceled'", [$html]);

                // ── POINTS EARNED ─────────────────────────────────────────────────────────
                $inner = $greet()
                    . $p('Συγχαρητήρια! Ολοκληρώσατε μια βάρδια και κερδίσατε πόντους!')
                    . '<div style="background:#fefce8;border-left:4px solid #d97706;padding:16px 20px;border-radius:0 8px 8px 0;margin:20px 0;text-align:center;">'
                    . '<div style="font-size:38px;margin:0 0 6px;">&#127942;</div>'
                    . '<div style="font-size:42px;font-weight:800;color:#d97706;line-height:1;">+{{points}}</div>'
                    . '<div style="font-size:13px;color:#92400e;margin:6px 0 0;letter-spacing:0.5px;text-transform:uppercase;">πόντοι κερδήθηκαν</div>'
                    . '</div>'
                    . $card('#d97706', [
                        ['Αποστολή:', '{{mission_title}}'],
                        ['Βάρδια:', '{{shift_date}}'],
                        ['Σύνολο πόντων:', '{{total_points}}'],
                    ])
                    . $btn('{{login_url}}', 'Δείτε τους Πόντους σας', '#d97706');
                $html = $outer($hdr('#d97706', '&#9733;', 'Κερδίσατε Πόντους!'), $bdy($inner), $ftr());
                dbExecute("UPDATE email_templates SET body_html = ?, subject = 'Κερδίσατε {{points}} πόντους!' WHERE code = 'points_earned'", [$html]);

                // ── ADMIN ADDED VOLUNTEER ─────────────────────────────────────────────────
                $inner = $greet()
                    . $p('Ο διαχειριστής σας τοποθέτησε <strong>απευθείας</strong> στην παρακάτω βάρδια:')
                    . $card('#1e3a5f', [
                        ['Αποστολή:', '{{mission_title}}'],
                        ['Ημερομηνία:', '{{shift_date}}'],
                        ['Ώρα:', '{{shift_time}}'],
                        ['Τοποθεσία:', '{{location}}'],
                    ])
                    . '{{#admin_notes}}<div style="background:#fef3c7;border-left:4px solid #f59e0b;padding:12px 16px;border-radius:0 8px 8px 0;margin:14px 0;font-size:14px;">'
                    . '<strong style="color:#92400e;">Σημείωση διαχειριστή:</strong> '
                    . '<span style="color:#78350f;">{{admin_notes}}</span>'
                    . '</div>{{/admin_notes}}'
                    . $p('Παρακαλούμε να είστε στην τοποθεσία έγκαιρα.')
                    . $btn('{{login_url}}', 'Σύνδεση στην Πλατφόρμα', '#1e3a5f');
                $html = $outer($hdr('#1e3a5f', '&#128203;', 'Τοποθέτηση σε Βάρδια'), $bdy($inner), $ftr());
                dbExecute("UPDATE email_templates SET body_html = ?, subject = 'Τοποθετηθήκατε σε βάρδια - {{mission_title}}' WHERE code = 'admin_added_volunteer'", [$html]);

                // ── Enable points_earned email ────────────────────────────────────────────
                dbExecute("UPDATE notification_settings SET email_enabled = 1 WHERE code = 'points_earned'");
            },
        ],

        [
            'version'     => 10,
            'description' => 'Add {{logo_html}} to all email template headers',
            'up' => function () {
                // ── Shared builder helpers (same as v9 but $hdr includes {{logo_html}}) ──

                $outer = function (string $h, string $b, string $f) : string {
                    return '<div style="background:#eef2f7;padding:28px 0 40px;font-family:Helvetica Neue,Arial,sans-serif;">'
                         . '<div style="max-width:600px;margin:0 auto;">'
                         . $h . $b . $f
                         . '</div></div>';
                };

                // Header now includes {{logo_html}} before the icon/title
                $hdr = function (string $c, string $icon, string $title) : string {
                    return '<div style="background:' . $c . ';padding:30px 40px 26px;border-radius:12px 12px 0 0;text-align:center;">'
                         . '{{logo_html}}'
                         . '<p style="color:rgba(255,255,255,0.7);font-size:11px;letter-spacing:2px;text-transform:uppercase;margin:0 0 8px;">{{app_name}}</p>'
                         . '<div style="font-size:36px;line-height:1;margin:0 0 8px;">' . $icon . '</div>'
                         . '<h1 style="color:#fff;margin:0;font-size:23px;font-weight:700;line-height:1.3;">' . $title . '</h1>'
                         . '</div>';
                };

                $bdy = function (string $inner) : string {
                    return '<div style="background:#fff;padding:36px 40px 40px;border-radius:0 0 12px 12px;box-shadow:0 4px 20px rgba(0,0,0,0.07);">'
                         . $inner . '</div>';
                };

                $ftr = function () : string {
                    return '<div style="text-align:center;padding:18px 0 0;color:#9ca3af;font-size:12px;line-height:1.9;">'
                         . '<p style="margin:0;"><strong style="color:#6b7280;">{{app_name}}</strong> &bull; Σύστημα Διαχείρισης Εθελοντών</p>'
                         . '<p style="margin:0;">Αυτό το μήνυμα στάλθηκε αυτόματα από το σύστημα.</p>'
                         . '</div>';
                };

                $card = function (string $c, array $rows) : string {
                    $html = '<div style="background:#f9fafb;border-left:4px solid ' . $c . ';padding:2px 20px;border-radius:0 8px 8px 0;margin:20px 0;">';
                    foreach ($rows as [$l, $v]) {
                        $html .= '<div style="padding:7px 0;font-size:14px;border-bottom:1px solid #f3f4f6;">'
                               . '<span style="color:#9ca3af;display:inline-block;min-width:130px;">' . $l . '</span>'
                               . '<span style="color:#111827;font-weight:600;">' . $v . '</span></div>';
                    }
                    return $html . '</div>';
                };

                $btn = function (string $url, string $lbl, string $c) : string {
                    return '<div style="text-align:center;margin:28px 0 4px;">'
                         . '<a href="' . $url . '" style="background:' . $c . ';color:#ffffff;text-decoration:none;'
                         . 'padding:13px 38px;border-radius:8px;font-size:15px;font-weight:700;display:inline-block;letter-spacing:0.3px;">'
                         . $lbl . '</a></div>';
                };

                $greet = function () : string {
                    return '<h2 style="color:#1f2937;font-size:18px;font-weight:700;margin:0 0 14px;">Γεια σας {{user_name}},</h2>';
                };

                $p = function (string $txt) : string {
                    return '<p style="color:#4b5563;line-height:1.65;font-size:15px;margin:0 0 14px;">' . $txt . '</p>';
                };

                // ── WELCOME ──────────────────────────────────────────────────────────────
                $inner = $greet()
                    . $p('Ευχαριστούμε που εγγραφήκατε στην πλατφόρμα εθελοντισμού <strong>{{app_name}}</strong>. Είστε πλέον μέλος της ομάδας μας!')
                    . '<div style="background:#eff6ff;border-left:4px solid #2563eb;padding:14px 20px;border-radius:0 8px 8px 0;margin:20px 0;">'
                    . '<p style="color:#1e40af;font-weight:700;font-size:14px;margin:0 0 8px;">Τι μπορείτε να κάνετε:</p>'
                    . '<div style="font-size:14px;color:#374151;line-height:1.9;">'
                    . '<div>&#10003;&nbsp; Δείτε τις διαθέσιμες αποστολές</div>'
                    . '<div>&#10003;&nbsp; Δηλώσετε συμμετοχή σε βάρδιες</div>'
                    . '<div>&#10003;&nbsp; Κερδίσετε πόντους και επιτεύγματα</div>'
                    . '</div></div>'
                    . $btn('{{login_url}}', 'Σύνδεση στην Πλατφόρμα', '#2563eb');
                $html = $outer($hdr('#2563eb', '&#127881;', 'Καλώς ήρθατε!'), $bdy($inner), $ftr());
                dbExecute("UPDATE email_templates SET body_html = ? WHERE code = 'welcome'", [$html]);

                // ── PARTICIPATION APPROVED ────────────────────────────────────────────────
                $inner = $greet()
                    . $p('Χαρούμαστε να σας ενημερώσουμε ότι η αίτηση συμμετοχής σας <strong>εγκρίθηκε</strong>! Σας περιμένουμε στη βάρδια!')
                    . $card('#16a34a', [
                        ['Αποστολή:', '{{mission_title}}'],
                        ['Ημερομηνία:', '{{shift_date}}'],
                        ['Ώρα:', '{{shift_time}}'],
                        ['Τοποθεσία:', '{{location}}'],
                    ])
                    . $p('Παρακαλούμε να είστε στην τοποθεσία έγκαιρα. Σε περίπτωση αδυναμίας, ενημερώστε μας το συντομότερο.')
                    . $btn('{{login_url}}', 'Δείτε τις Λεπτομέρειες', '#16a34a');
                $html = $outer($hdr('#16a34a', '&#10003;', 'Η Συμμετοχή σας Εγκρίθηκε!'), $bdy($inner), $ftr());
                dbExecute("UPDATE email_templates SET body_html = ? WHERE code = 'participation_approved'", [$html]);

                // ── PARTICIPATION REJECTED ────────────────────────────────────────────────
                $inner = $greet()
                    . $p('Δυστυχώς, η αίτηση συμμετοχής σας <strong>δεν εγκρίθηκε</strong> αυτή τη φορά.')
                    . $card('#dc2626', [
                        ['Αποστολή:', '{{mission_title}}'],
                        ['Βάρδια:', '{{shift_date}}'],
                    ])
                    . $p('Ελπίζουμε να σας δούμε στην επόμενη ευκαιρία! Μπορείτε να δηλώσετε συμμετοχή σε άλλες διαθέσιμες βάρδιες.')
                    . $btn('{{login_url}}', 'Δείτε Άλλες Βάρδιες', '#dc2626');
                $html = $outer($hdr('#dc2626', '&#9888;', 'Ενημέρωση Αίτησης Συμμετοχής'), $bdy($inner), $ftr());
                dbExecute("UPDATE email_templates SET body_html = ? WHERE code = 'participation_rejected'", [$html]);

                // ── SHIFT REMINDER ────────────────────────────────────────────────────────
                $inner = $greet()
                    . $p('Σας υπενθυμίζουμε ότι <strong>αύριο</strong> έχετε μια προγραμματισμένη βάρδια. Είστε έτοιμοι;')
                    . $card('#d97706', [
                        ['Αποστολή:', '{{mission_title}}'],
                        ['Ημερομηνία:', '{{shift_date}}'],
                        ['Ώρα:', '{{shift_time}}'],
                        ['Τοποθεσία:', '{{location}}'],
                    ])
                    . $p('Σε περίπτωση αδυναμίας, παρακαλούμε ενημερώστε μας το συντομότερο δυνατό.')
                    . $btn('{{login_url}}', 'Δείτε τη Βάρδια', '#d97706');
                $html = $outer($hdr('#d97706', '&#9200;', 'Υπενθύμιση Αυριανής Βάρδιας'), $bdy($inner), $ftr());
                dbExecute("UPDATE email_templates SET body_html = ? WHERE code = 'shift_reminder'", [$html]);

                // ── NEW MISSION ───────────────────────────────────────────────────────────
                $inner = '<h2 style="color:#1f2937;font-size:20px;font-weight:700;margin:0 0 12px;">{{mission_title}}</h2>'
                    . $p('{{mission_description}}')
                    . $card('#7c3aed', [
                        ['Τοποθεσία:', '{{location}}'],
                        ['Έναρξη:', '{{start_date}}'],
                        ['Λήξη:', '{{end_date}}'],
                    ])
                    . $p('Βιαστείτε — οι θέσεις είναι περιορισμένες! Δηλώστε συμμετοχή σήμερα.')
                    . $btn('{{mission_url}}', 'Δηλώστε Συμμετοχή', '#7c3aed');
                $html = $outer($hdr('#7c3aed', '&#128640;', 'Νέα Αποστολή Διαθέσιμη!'), $bdy($inner), $ftr());
                dbExecute("UPDATE email_templates SET body_html = ? WHERE code = 'new_mission'", [$html]);

                // ── MISSION CANCELED ──────────────────────────────────────────────────────
                $inner = $greet()
                    . $p('Σας ενημερώνουμε ότι η παρακάτω αποστολή <strong>ακυρώθηκε</strong>.')
                    . $card('#dc2626', [
                        ['Αποστολή:', '{{mission_title}}'],
                    ])
                    . $p('Ζητούμε συγγνώμη για την αναστάτωση. Ελπίζουμε να σας δούμε σε μελλοντικές αποστολές!')
                    . $btn('{{login_url}}', 'Δείτε Άλλες Αποστολές', '#dc2626');
                $html = $outer($hdr('#dc2626', '&#9888;', 'Ακύρωση Αποστολής'), $bdy($inner), $ftr());
                dbExecute("UPDATE email_templates SET body_html = ? WHERE code = 'mission_canceled'", [$html]);

                // ── SHIFT CANCELED ────────────────────────────────────────────────────────
                $inner = $greet()
                    . $p('Σας ενημερώνουμε ότι η βάρδια στην οποία είχατε δηλώσει συμμετοχή <strong>ακυρώθηκε</strong>.')
                    . $card('#dc2626', [
                        ['Αποστολή:', '{{mission_title}}'],
                        ['Βάρδια:', '{{shift_date}}'],
                        ['Ώρα:', '{{shift_time}}'],
                    ])
                    . $p('Ζητούμε συγγνώμη για την αναστάτωση. Μπορείτε να δηλώσετε συμμετοχή σε άλλες διαθέσιμες βάρδιες.')
                    . $btn('{{login_url}}', 'Δείτε Άλλες Βάρδιες', '#dc2626');
                $html = $outer($hdr('#dc2626', '&#9888;', 'Ακύρωση Βάρδιας'), $bdy($inner), $ftr());
                dbExecute("UPDATE email_templates SET body_html = ? WHERE code = 'shift_canceled'", [$html]);

                // ── POINTS EARNED ─────────────────────────────────────────────────────────
                $inner = $greet()
                    . $p('Συγχαρητήρια! Ολοκληρώσατε μια βάρδια και κερδίσατε πόντους!')
                    . '<div style="background:#fefce8;border-left:4px solid #d97706;padding:16px 20px;border-radius:0 8px 8px 0;margin:20px 0;text-align:center;">'
                    . '<div style="font-size:38px;margin:0 0 6px;">&#127942;</div>'
                    . '<div style="font-size:42px;font-weight:800;color:#d97706;line-height:1;">+{{points}}</div>'
                    . '<div style="font-size:13px;color:#92400e;margin:6px 0 0;letter-spacing:0.5px;text-transform:uppercase;">πόντοι κερδήθηκαν</div>'
                    . '</div>'
                    . $card('#d97706', [
                        ['Αποστολή:', '{{mission_title}}'],
                        ['Βάρδια:', '{{shift_date}}'],
                        ['Σύνολο πόντων:', '{{total_points}}'],
                    ])
                    . $btn('{{login_url}}', 'Δείτε τους Πόντους σας', '#d97706');
                $html = $outer($hdr('#d97706', '&#9733;', 'Κερδίσατε Πόντους!'), $bdy($inner), $ftr());
                dbExecute("UPDATE email_templates SET body_html = ? WHERE code = 'points_earned'", [$html]);

                // ── ADMIN ADDED VOLUNTEER ─────────────────────────────────────────────────
                $inner = $greet()
                    . $p('Ο διαχειριστής σας τοποθέτησε <strong>απευθείας</strong> στην παρακάτω βάρδια:')
                    . $card('#1e3a5f', [
                        ['Αποστολή:', '{{mission_title}}'],
                        ['Ημερομηνία:', '{{shift_date}}'],
                        ['Ώρα:', '{{shift_time}}'],
                        ['Τοποθεσία:', '{{location}}'],
                    ])
                    . '{{#admin_notes}}<div style="background:#fef3c7;border-left:4px solid #f59e0b;padding:12px 16px;border-radius:0 8px 8px 0;margin:14px 0;font-size:14px;">'
                    . '<strong style="color:#92400e;">Σημείωση διαχειριστή:</strong> '
                    . '<span style="color:#78350f;">{{admin_notes}}</span>'
                    . '</div>{{/admin_notes}}'
                    . $p('Παρακαλούμε να είστε στην τοποθεσία έγκαιρα.')
                    . $btn('{{login_url}}', 'Σύνδεση στην Πλατφόρμα', '#1e3a5f');
                $html = $outer($hdr('#1e3a5f', '&#128203;', 'Τοποθέτηση σε Βάρδια'), $bdy($inner), $ftr());
                dbExecute("UPDATE email_templates SET body_html = ? WHERE code = 'admin_added_volunteer'", [$html]);
            },
        ],

        [
            'version'     => 11,
            'description' => 'Add {{reason}} to mission_canceled email template',
            'up' => function () {
                $outer = function (string $h, string $b, string $f) : string {
                    return '<div style="background:#eef2f7;padding:28px 0 40px;font-family:Helvetica Neue,Arial,sans-serif;">'
                         . '<div style="max-width:600px;margin:0 auto;">'
                         . $h . $b . $f . '</div></div>';
                };
                $hdr = function (string $c, string $icon, string $title) : string {
                    return '<div style="background:' . $c . ';padding:30px 40px 26px;border-radius:12px 12px 0 0;text-align:center;">'
                         . '{{logo_html}}'
                         . '<p style="color:rgba(255,255,255,0.7);font-size:11px;letter-spacing:2px;text-transform:uppercase;margin:0 0 8px;">{{app_name}}</p>'
                         . '<div style="font-size:36px;line-height:1;margin:0 0 8px;">' . $icon . '</div>'
                         . '<h1 style="color:#fff;margin:0;font-size:23px;font-weight:700;line-height:1.3;">' . $title . '</h1>'
                         . '</div>';
                };
                $bdy = function (string $inner) : string {
                    return '<div style="background:#fff;padding:36px 40px 40px;border-radius:0 0 12px 12px;box-shadow:0 4px 20px rgba(0,0,0,0.07);">'
                         . $inner . '</div>';
                };
                $ftr = function () : string {
                    return '<div style="text-align:center;padding:18px 0 0;color:#9ca3af;font-size:12px;line-height:1.9;">'
                         . '<p style="margin:0;"><strong style="color:#6b7280;">{{app_name}}</strong> &bull; Σύστημα Διαχείρισης Εθελοντών</p>'
                         . '<p style="margin:0;">Αυτό το μήνυμα στάλθηκε αυτόματα από το σύστημα.</p>'
                         . '</div>';
                };
                $card = function (string $c, array $rows) : string {
                    $html = '<div style="background:#f9fafb;border-left:4px solid ' . $c . ';padding:2px 20px;border-radius:0 8px 8px 0;margin:20px 0;">';
                    foreach ($rows as [$l, $v]) {
                        $html .= '<div style="padding:7px 0;font-size:14px;border-bottom:1px solid #f3f4f6;">'
                               . '<span style="color:#9ca3af;display:inline-block;min-width:130px;">' . $l . '</span>'
                               . '<span style="color:#111827;font-weight:600;">' . $v . '</span></div>';
                    }
                    return $html . '</div>';
                };
                $btn = function (string $url, string $lbl, string $c) : string {
                    return '<div style="text-align:center;margin:28px 0 4px;">'
                         . '<a href="' . $url . '" style="background:' . $c . ';color:#ffffff;text-decoration:none;'
                         . 'padding:13px 38px;border-radius:8px;font-size:15px;font-weight:700;display:inline-block;letter-spacing:0.3px;">'
                         . $lbl . '</a></div>';
                };
                $p = function (string $txt) : string {
                    return '<p style="color:#4b5563;line-height:1.65;font-size:15px;margin:0 0 14px;">' . $txt . '</p>';
                };
                $greet = function () : string {
                    return '<h2 style="color:#1f2937;font-size:18px;font-weight:700;margin:0 0 14px;">Γεια σας {{user_name}},</h2>';
                };

                $inner = $greet()
                    . $p('Σας ενημερώνουμε ότι η παρακάτω αποστολή <strong>ακυρώθηκε</strong>.')
                    . $card('#dc2626', [
                        ['Αποστολή:', '{{mission_title}}'],
                        ['Αιτιολογία:', '{{reason}}'],
                    ])
                    . $p('Ζητούμε συγγνώμη για την αναστάτωση. Ελπίζουμε να σας δούμε σε μελλοντικές αποστολές!')
                    . $btn('{{login_url}}', 'Δείτε Άλλες Αποστολές', '#dc2626');
                $html = $outer($hdr('#dc2626', '&#9888;', 'Ακύρωση Αποστολής'), $bdy($inner), $ftr());
                dbExecute(
                    "UPDATE email_templates SET body_html = ?, available_variables = ? WHERE code = 'mission_canceled'",
                    [$html, '{{app_name}}, {{user_name}}, {{mission_title}}, {{reason}}, {{login_url}}']
                );
            },
        ],

        [
            'version'     => 12,
            'description' => 'Add Google Calendar button to participation_approved and admin_added_volunteer emails',
            'up' => function () {
                $btn = function (string $url, string $lbl, string $c) : string {
                    return '<div style="text-align:center;margin:28px 0 4px;">'
                         . '<a href="' . $url . '" style="background:' . $c . ';color:#ffffff;text-decoration:none;'
                         . 'padding:13px 38px;border-radius:8px;font-size:15px;font-weight:700;display:inline-block;letter-spacing:0.3px;">'
                         . $lbl . '</a></div>';
                };
                $gcalBtn = $btn('{{gcal_link}}', '&#128197; Προσθήκη στο Google Calendar', '#1a73e8');

                // ── participation_approved ────────────────────────────────────────────────
                $current = dbFetchValue("SELECT body_html FROM email_templates WHERE code = 'participation_approved'");
                if ($current) {
                    // Insert gcal button right before closing </div> of body block
                    $updated = str_replace(
                        $btn('{{login_url}}', 'Δείτε τις Λεπτομέρειες', '#16a34a'),
                        $btn('{{login_url}}', 'Δείτε τις Λεπτομέρειες', '#16a34a') . $gcalBtn,
                        $current
                    );
                    dbExecute(
                        "UPDATE email_templates SET body_html = ?, available_variables = ? WHERE code = 'participation_approved'",
                        [$updated, '{{app_name}}, {{user_name}}, {{mission_title}}, {{shift_date}}, {{shift_time}}, {{location}}, {{login_url}}, {{gcal_link}}']
                    );
                }

                // ── admin_added_volunteer ─────────────────────────────────────────────────
                $current = dbFetchValue("SELECT body_html FROM email_templates WHERE code = 'admin_added_volunteer'");
                if ($current) {
                    $updated = str_replace(
                        $btn('{{login_url}}', 'Σύνδεση στην Πλατφόρμα', '#1e3a5f'),
                        $btn('{{login_url}}', 'Σύνδεση στην Πλατφόρμα', '#1e3a5f') . $gcalBtn,
                        $current
                    );
                    dbExecute(
                        "UPDATE email_templates SET body_html = ?, available_variables = ? WHERE code = 'admin_added_volunteer'",
                        [$updated, '{{app_name}}, {{user_name}}, {{mission_title}}, {{shift_date}}, {{shift_time}}, {{location}}, {{admin_notes}}, {{login_url}}, {{gcal_link}}']
                    );
                }
            },
        ],

        [
            'version'     => 13,
            'description' => 'Add field_status to participation_requests + create volunteer_pings table (GPS live ops)',
            'up' => function () {
                // Add field_status column
                $col = dbFetchOne(
                    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME   = 'participation_requests'
                       AND COLUMN_NAME  = 'field_status'"
                );
                if (!$col) {
                    dbExecute(
                        "ALTER TABLE participation_requests
                         ADD COLUMN field_status ENUM('on_way','on_site','needs_help') NULL DEFAULT NULL
                         AFTER admin_notes"
                    );
                }

                // Add field_status_updated_at column
                $col2 = dbFetchOne(
                    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME   = 'participation_requests'
                       AND COLUMN_NAME  = 'field_status_updated_at'"
                );
                if (!$col2) {
                    dbExecute(
                        "ALTER TABLE participation_requests
                         ADD COLUMN field_status_updated_at TIMESTAMP NULL DEFAULT NULL
                         AFTER field_status"
                    );
                }

                // Create volunteer_pings table
                $table = dbFetchOne(
                    "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME = 'volunteer_pings'"
                );
                if (!$table) {
                    dbExecute(
                        "CREATE TABLE volunteer_pings (
                            id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                            user_id   INT UNSIGNED NOT NULL,
                            shift_id  INT UNSIGNED NOT NULL,
                            lat       DECIMAL(10, 8) NOT NULL,
                            lng       DECIMAL(11, 8) NOT NULL,
                            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                            FOREIGN KEY (user_id)  REFERENCES users(id)  ON DELETE CASCADE,
                            FOREIGN KEY (shift_id) REFERENCES shifts(id) ON DELETE CASCADE,
                            INDEX idx_pings_shift_time (shift_id, created_at),
                            INDEX idx_pings_user_shift (user_id, shift_id)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
                    );
                }
            },
        ],

        [
            'version'     => 14,
            'description' => 'Create mission_debriefs table for post-mission reports',
            'up' => function () {
                $table = dbFetchOne(
                    "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME = 'mission_debriefs'"
                );
                if (!$table) {
                    dbExecute(
                        "CREATE TABLE mission_debriefs (
                            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                            mission_id INT UNSIGNED NOT NULL,
                            submitted_by INT UNSIGNED NOT NULL,
                            summary TEXT NOT NULL,
                            objectives_met ENUM('YES', 'PARTIAL', 'NO') NOT NULL,
                            incidents TEXT NULL,
                            equipment_issues TEXT NULL,
                            rating TINYINT UNSIGNED NOT NULL CHECK (rating BETWEEN 1 AND 5),
                            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                            FOREIGN KEY (mission_id) REFERENCES missions(id) ON DELETE CASCADE,
                            FOREIGN KEY (submitted_by) REFERENCES users(id) ON DELETE CASCADE,
                            UNIQUE KEY unique_mission_debrief (mission_id)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
                    );
                }
            },
        ],

        [
            'version'     => 15,
            'description' => 'Create inventory_kits and inventory_kit_items tables',
            'up' => function () {
                $table1 = dbFetchOne(
                    "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME = 'inventory_kits'"
                );
                if (!$table1) {
                    dbExecute(
                        "CREATE TABLE inventory_kits (
                            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                            barcode VARCHAR(50) NOT NULL UNIQUE,
                            name VARCHAR(255) NOT NULL,
                            description TEXT NULL,
                            department_id INT UNSIGNED NULL,
                            created_by INT UNSIGNED NULL,
                            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                            FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
                            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
                    );
                }

                $table2 = dbFetchOne(
                    "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME = 'inventory_kit_items'"
                );
                if (!$table2) {
                    dbExecute(
                        "CREATE TABLE inventory_kit_items (
                            kit_id INT UNSIGNED NOT NULL,
                            item_id INT UNSIGNED NOT NULL,
                            PRIMARY KEY (kit_id, item_id),
                            FOREIGN KEY (kit_id) REFERENCES inventory_kits(id) ON DELETE CASCADE,
                            FOREIGN KEY (item_id) REFERENCES inventory_items(id) ON DELETE CASCADE
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
                    );
                }
            },
        ],

        [
            'version'     => 16,
            'description' => 'Add mission_reminder email template',
            'up' => function () {
                $exists = dbFetchOne("SELECT id FROM email_templates WHERE code = 'mission_reminder'");
                if (!$exists) {
                    $bodyHtml = '<div style="background:#eef2f7;padding:28px 0 40px;font-family:Helvetica Neue,Arial,sans-serif;"><div style="max-width:600px;margin:0 auto;"><div style="background:#fd7e14;padding:30px 40px 26px;border-radius:12px 12px 0 0;text-align:center;">{{logo_html}}<p style="color:rgba(255,255,255,0.7);font-size:11px;letter-spacing:2px;text-transform:uppercase;margin:0 0 8px;">{{app_name}}</p><div style="font-size:36px;line-height:1;margin:0 0 8px;">&#128226;</div><h1 style="color:#fff;margin:0;font-size:23px;font-weight:700;line-height:1.3;">Υπενθύμιση Αποστολής</h1></div><div style="background:#fff;padding:36px 40px 40px;border-radius:0 0 12px 12px;box-shadow:0 4px 20px rgba(0,0,0,0.07);"><h2 style="color:#1f2937;font-size:18px;font-weight:700;margin:0 0 14px;">Γεια σας {{user_name}},</h2><p style="color:#4b5563;line-height:1.65;font-size:15px;margin:0 0 14px;">Η παρακάτω αποστολή είναι ακόμα <strong>ανοιχτή</strong> και αναζητά εθελοντές:</p><div style="background:#f9fafb;border-left:4px solid #fd7e14;padding:2px 20px;border-radius:0 8px 8px 0;margin:20px 0;"><div style="padding:7px 0;font-size:14px;border-bottom:1px solid #f3f4f6;"><span style="color:#9ca3af;display:inline-block;min-width:140px;">Αποστολή:</span><span style="color:#111827;font-weight:600;">{{mission_title}}</span></div><div style="padding:7px 0;font-size:14px;"><span style="color:#9ca3af;display:inline-block;min-width:140px;">Περιγραφή:</span><span style="color:#111827;font-weight:600;">{{mission_description}}</span></div></div><p style="color:#4b5563;line-height:1.65;font-size:15px;margin:0 0 14px;">Μη χάσετε την ευκαιρία να συμμετέχετε και να κάνετε τη διαφορά!</p><div style="text-align:center;margin:28px 0 4px;"><a href="{{mission_url}}" style="background:#fd7e14;color:#ffffff;text-decoration:none;padding:13px 38px;border-radius:8px;font-size:15px;font-weight:700;display:inline-block;letter-spacing:0.3px;">Δείτε την Αποστολή</a></div></div><div style="text-align:center;padding:18px 0 0;color:#9ca3af;font-size:12px;line-height:1.9;"><p style="margin:0;"><strong style="color:#6b7280;">{{app_name}}</strong> &bull; Σύστημα Διαχείρισης Εθελοντών</p><p style="margin:0;">Αυτό το μήνυμα στάλθηκε αυτόματα από το σύστημα.</p></div></div></div>';
                    dbInsert(
                        "INSERT INTO email_templates (code, name, subject, body_html, description, available_variables) 
                         VALUES ('mission_reminder', 'Υπενθύμιση Αποστολής', 'Υπενθύμιση Αποστολής: {{mission_title}}', ?, 'Όταν στέλνεται υπενθύμιση για ανοιχτή αποστολή', '{{app_name}}, {{user_name}}, {{mission_title}}, {{mission_description}}, {{mission_url}}')",
                        [$bodyHtml]
                    );
                }
                
                $settingExists = dbFetchOne("SELECT id FROM notification_settings WHERE code = 'mission_reminder'");
                if (!$settingExists) {
                    dbInsert(
                        "INSERT INTO notification_settings (code, name, description, email_enabled, email_template_id) 
                         VALUES ('mission_reminder', 'Υπενθύμιση Αποστολής', 'Όταν στέλνεται υπενθύμιση για ανοιχτή αποστολή', 1, (SELECT id FROM email_templates WHERE code = 'mission_reminder'))"
                    );
                }
            },
        ],

        [
            'version'     => 17,
            'description' => 'Create user_notification_preferences table for per-user opt-out',
            'up' => function () {
                $tableExists = dbFetchOne(
                    "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME   = 'user_notification_preferences'"
                );
                if (!$tableExists) {
                    dbExecute("
                        CREATE TABLE user_notification_preferences (
                            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                            user_id INT UNSIGNED NOT NULL,
                            notification_code VARCHAR(50) NOT NULL,
                            email_enabled TINYINT(1) NOT NULL DEFAULT 1,
                            in_app_enabled TINYINT(1) NOT NULL DEFAULT 1,
                            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                            UNIQUE KEY uq_user_notif (user_id, notification_code),
                            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                    ");
                }
            },
        ],

        [
            'version'     => 18,
            'description' => 'Certificate expiry tracking — types, volunteer certificates, email template, settings',
            'up' => function () {
                // 1. certificate_types table
                $exists = dbFetchOne(
                    "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'certificate_types'"
                );
                if (!$exists) {
                    dbExecute("
                        CREATE TABLE certificate_types (
                            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                            name VARCHAR(150) NOT NULL,
                            description TEXT NULL,
                            default_validity_months INT UNSIGNED NULL COMMENT 'NULL = no expiry',
                            is_required TINYINT(1) NOT NULL DEFAULT 0,
                            is_active TINYINT(1) NOT NULL DEFAULT 1,
                            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                    ");

                    // Seed default certificate types
                    dbExecute("INSERT INTO certificate_types (name, description, default_validity_months, is_required) VALUES
                        ('Πρώτες Βοήθειες', 'Πιστοποίηση Πρώτων Βοηθειών (BLS)', 36, 1),
                        ('BLS/AED', 'Βασική Υποστήριξη Ζωής & Αυτόματος Εξωτερικός Απινιδωτής', 36, 0),
                        ('Δίπλωμα Οδήγησης', 'Άδεια οδήγησης αυτοκινήτου / μοτοσυκλέτας', NULL, 0),
                        ('PHTLS', 'Prehospital Trauma Life Support', 48, 0)
                    ");
                }

                // 2. volunteer_certificates table
                $exists2 = dbFetchOne(
                    "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'volunteer_certificates'"
                );
                if (!$exists2) {
                    dbExecute("
                        CREATE TABLE volunteer_certificates (
                            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                            user_id INT UNSIGNED NOT NULL,
                            certificate_type_id INT UNSIGNED NOT NULL,
                            issue_date DATE NOT NULL,
                            expiry_date DATE NULL COMMENT 'NULL = never expires',
                            issuing_body VARCHAR(255) NULL,
                            certificate_number VARCHAR(100) NULL,
                            document_id INT UNSIGNED NULL,
                            notes TEXT NULL,
                            reminder_sent_30 TINYINT(1) NOT NULL DEFAULT 0,
                            reminder_sent_7 TINYINT(1) NOT NULL DEFAULT 0,
                            created_by INT UNSIGNED NULL,
                            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                            UNIQUE KEY uq_user_cert (user_id, certificate_type_id),
                            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                            FOREIGN KEY (certificate_type_id) REFERENCES certificate_types(id) ON DELETE RESTRICT,
                            FOREIGN KEY (document_id) REFERENCES volunteer_documents(id) ON DELETE SET NULL,
                            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                    ");
                }

                // 3. Email template for certificate expiry reminder
                $tmplExists = dbFetchOne("SELECT id FROM email_templates WHERE code = 'certificate_expiry_reminder'");
                if (!$tmplExists) {
                    dbInsert(
                        "INSERT INTO email_templates (code, name, subject, body_html, is_active) VALUES (?, ?, ?, ?, 1)",
                        [
                            'certificate_expiry_reminder',
                            'Υπενθύμιση Λήξης Πιστοποιητικού',
                            'Υπενθύμιση: Το πιστοποιητικό σας «{{certificate_type}}» λήγει σε {{days_remaining}} ημέρες',
                            '<p>Αγαπητέ/ή {{user_name}},</p>
<p>Σας ενημερώνουμε ότι το πιστοποιητικό σας <strong>«{{certificate_type}}»</strong> λήγει στις <strong>{{expiry_date}}</strong> (σε {{days_remaining}} ημέρες).</p>
<p>Παρακαλούμε φροντίστε για την ανανέωσή του εγκαίρως.</p>
<p>Με εκτίμηση,<br>{{app_name}}</p>'
                        ]
                    );
                }

                // 4. Notification setting
                $nsExists = dbFetchOne("SELECT id FROM notification_settings WHERE code = 'certificate_expiry_reminder'");
                if (!$nsExists) {
                    dbInsert(
                        "INSERT INTO notification_settings (code, name, description, email_enabled, email_template_id)
                         VALUES ('certificate_expiry_reminder', 'Υπενθύμιση Λήξης Πιστοποιητικού',
                                 'Όταν πλησιάζει η λήξη ενός πιστοποιητικού του εθελοντή', 1,
                                 (SELECT id FROM email_templates WHERE code = 'certificate_expiry_reminder'))"
                    );
                }

                // 5. Default settings
                $s1 = dbFetchOne("SELECT setting_key FROM settings WHERE setting_key = 'certificate_reminder_days_first'");
                if (!$s1) {
                    dbInsert("INSERT INTO settings (setting_key, setting_value) VALUES ('certificate_reminder_days_first', '30')");
                }
                $s2 = dbFetchOne("SELECT setting_key FROM settings WHERE setting_key = 'certificate_reminder_days_urgent'");
                if (!$s2) {
                    dbInsert("INSERT INTO settings (setting_key, setting_value) VALUES ('certificate_reminder_days_urgent', '7')");
                }
            },
        ],

        [
            'version'     => 19,
            'description' => 'Add available_from, available_until, max_attempts to training_exams',
            'up' => function () {
                $col1 = dbFetchOne(
                    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME   = 'training_exams'
                       AND COLUMN_NAME  = 'available_from'"
                );
                if (!$col1) {
                    dbExecute("ALTER TABLE training_exams ADD COLUMN available_from DATETIME NULL AFTER is_active");
                }
                $col2 = dbFetchOne(
                    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME   = 'training_exams'
                       AND COLUMN_NAME  = 'available_until'"
                );
                if (!$col2) {
                    dbExecute("ALTER TABLE training_exams ADD COLUMN available_until DATETIME NULL AFTER available_from");
                }
                $col3 = dbFetchOne(
                    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME   = 'training_exams'
                       AND COLUMN_NAME  = 'max_attempts'"
                );
                if (!$col3) {
                    dbExecute("ALTER TABLE training_exams ADD COLUMN max_attempts INT NOT NULL DEFAULT 1 AFTER available_until");
                }
            },
        ],

        [
            'version'     => 20,
            'description' => 'Add use_random_pool to training_exams',
            'up' => function () {
                $col = dbFetchOne(
                    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME   = 'training_exams'
                       AND COLUMN_NAME  = 'use_random_pool'"
                );
                if (!$col) {
                    dbExecute("ALTER TABLE training_exams ADD COLUMN use_random_pool TINYINT(1) NOT NULL DEFAULT 0 AFTER max_attempts");
                }
            },
        ],

        [
            'version'     => 21,
            'description' => 'Add composite indexes for performance optimization',
            'up' => function () {
                // Helper: create index only if it doesn't already exist
                $addIndex = function (string $table, string $indexName, string $columns) {
                    $exists = dbFetchOne(
                        "SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
                         WHERE TABLE_SCHEMA = DATABASE()
                           AND TABLE_NAME   = ?
                           AND INDEX_NAME   = ?",
                        [$table, $indexName]
                    );
                    if (!$exists) {
                        dbExecute("CREATE INDEX {$indexName} ON {$table}({$columns})");
                    }
                };

                // participation_requests
                $addIndex('participation_requests', 'idx_pr_shift_status',  'shift_id, status');
                $addIndex('participation_requests', 'idx_pr_vol_status',    'volunteer_id, status');

                // missions
                $addIndex('missions', 'idx_missions_status_dept',  'status, department_id');
                $addIndex('missions', 'idx_missions_status_start', 'status, start_datetime');

                // shifts
                $addIndex('shifts', 'idx_shifts_mission_time', 'mission_id, start_time');

                // users
                $addIndex('users', 'idx_users_role_active', 'role, is_active');
                $addIndex('users', 'idx_users_dept_role',   'department_id, role');

                // notifications
                $addIndex('notifications', 'idx_notifications_user_created', 'user_id, created_at');

                // volunteer_points
                $addIndex('volunteer_points', 'idx_points_user_date',   'user_id, created_at');
                $addIndex('volunteer_points', 'idx_points_user_reason', 'user_id, reason');

                // audit_logs
                $addIndex('audit_logs', 'idx_audit_user_created',    'user_id, created_at');
                $addIndex('audit_logs', 'idx_audit_table_rec_date',  'table_name, record_id, created_at');

                // tasks
                $addIndex('tasks', 'idx_tasks_priority_status',  'priority, status');
                $addIndex('tasks', 'idx_tasks_status_deadline',  'status, deadline');

                // inventory_bookings
                $addIndex('inventory_bookings', 'idx_inv_book_user_status', 'user_id, status');

                // inventory_items
                $addIndex('inventory_items', 'idx_inv_items_dept_active_status', 'department_id, is_active, status');
            },
        ],

        [
            'version'     => 22,
            'description' => 'Add email_verification_token and approval_status to users',
            'up' => function () {
                $col = dbFetchOne(
                    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME   = 'users'
                       AND COLUMN_NAME  = 'email_verification_token'"
                );
                if (!$col) {
                    dbExecute("ALTER TABLE users ADD COLUMN email_verification_token VARCHAR(100) NULL AFTER email_verified_at");
                }
                $col2 = dbFetchOne(
                    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME   = 'users'
                       AND COLUMN_NAME  = 'approval_status'"
                );
                if (!$col2) {
                    dbExecute("ALTER TABLE users ADD COLUMN approval_status ENUM('PENDING','APPROVED','REJECTED') NOT NULL DEFAULT 'APPROVED' AFTER email_verification_token");
                }
            },
        ],

        [
            'version'     => 23,
            'description' => 'Create password_reset_tokens table',
            'up' => function () {
                $table = dbFetchOne(
                    "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'password_reset_tokens'"
                );
                if (!$table) {
                    dbExecute("
                        CREATE TABLE password_reset_tokens (
                            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                            user_id INT UNSIGNED NOT NULL,
                            token VARCHAR(100) NOT NULL UNIQUE,
                            expires_at DATETIME NOT NULL,
                            used_at TIMESTAMP NULL,
                            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                            INDEX idx_prt_token (token),
                            INDEX idx_prt_user (user_id)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                    ");
                }
            },
        ],

        [
            'version'     => 24,
            'description' => 'Add notified column to user_achievements for badge popup system',
            'up' => function () {
                $col = dbFetchOne(
                    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME   = 'user_achievements'
                       AND COLUMN_NAME  = 'notified'"
                );
                if (!$col) {
                    dbExecute("ALTER TABLE user_achievements ADD COLUMN notified TINYINT(1) NOT NULL DEFAULT 1 AFTER earned_at");
                    // Mark existing achievements as already notified (don't popup for old ones)
                    dbExecute("UPDATE user_achievements SET notified = 1");
                }

                // Add new achievement codes if missing
                $newAchievements = [
                    ['shifts_100',    '100 Βάρδιες',           'Ολοκλήρωσε 100 βάρδιες',                       'shifts',    '🏅', 0,    100],
                    ['first_mission', 'Πρώτη Αποστολή',        'Ολοκλήρωσε την πρώτη σου αποστολή',            'milestone', '🚀', 0,    1  ],
                    ['missions_3',    '3 Αποστολές',           'Ολοκλήρωσε 3 αποστολές',                       'missions',  '📋', 0,    3  ],
                    ['missions_10',   '10 Αποστολές',          'Ολοκλήρωσε 10 αποστολές',                      'missions',  '🌟', 0,    10 ],
                    ['missions_25',   '25 Αποστολές',          'Ολοκλήρωσε 25 αποστολές',                      'missions',  '💫', 0,    25 ],
                    ['missions_50',   '50 Αποστολές',          'Ολοκλήρωσε 50 αποστολές',                      'missions',  '🏆', 0,    50 ],
                    ['hours_500',     '500 Ώρες',              'Συμπλήρωσε 500 ώρες εθελοντισμού',             'hours',     '⚡', 0,    500],
                    ['hours_1000',    '1000 Ώρες',             'Συμπλήρωσε 1000 ώρες εθελοντισμού',            'hours',     '💎', 0,    1000],
                    ['points_2000',   '2000 Πόντοι',           'Συγκέντρωσε 2000 πόντους',                     'points',    '🎖️', 2000, 0  ],
                    ['points_5000',   '5000 Πόντοι',           'Συγκέντρωσε 5000 πόντους',                     'points',    '👑', 5000, 0  ],
                    ['early_bird',    'Πτηνό της Αυγής',       'Ολοκλήρωσε 5 βάρδιες πριν τις 8:00',          'special',   '🌅', 0,    5  ],
                    ['dedicated',     'Αφοσιωμένος',           'Συμμετοχή σε 5+ διαφορετικούς μήνες',          'special',   '🗓️', 0,   5  ],
                    ['loyal_member',  'Πιστό Μέλος',           'Μέλος της ομάδας για 1+ χρόνο',                'special',   '💙', 0,    365],
                    ['rescuer_elite', 'Ελίτ Διασώστης',        '250+ ώρες και 50+ αποστολές',                  'special',   '⭐', 0,    0  ],
                ];
                foreach ($newAchievements as $a) {
                    dbExecute(
                        "INSERT IGNORE INTO achievements (code, name, description, category, icon, required_points, threshold)
                         VALUES (?, ?, ?, ?, ?, ?, ?)",
                        $a
                    );
                }
            },
        ],

        [
            'version'     => 25,
            'description' => 'Fix quiz system tables – add missing columns to training_quizzes, quiz_attempts, user_answers, training_quiz_questions',
            'up' => function () {
                $checkCol = function ($table, $column) {
                    return (bool) dbFetchOne(
                        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
                        [$table, $column]
                    );
                };

                // training_quizzes: add questions_per_attempt, passing_percentage
                if (!$checkCol('training_quizzes', 'questions_per_attempt')) {
                    dbExecute("ALTER TABLE training_quizzes ADD COLUMN `questions_per_attempt` INT DEFAULT 10 AFTER `time_limit_minutes`");
                }
                if (!$checkCol('training_quizzes', 'passing_percentage')) {
                    dbExecute("ALTER TABLE training_quizzes ADD COLUMN `passing_percentage` INT DEFAULT 70 AFTER `questions_per_attempt`");
                }

                // quiz_attempts: add selected_questions_json, total_questions, passing_percentage, passed, started_at
                if (!$checkCol('quiz_attempts', 'selected_questions_json')) {
                    dbExecute("ALTER TABLE quiz_attempts ADD COLUMN `selected_questions_json` JSON NULL AFTER `user_id`");
                }
                if (!$checkCol('quiz_attempts', 'total_questions')) {
                    dbExecute("ALTER TABLE quiz_attempts ADD COLUMN `total_questions` INT DEFAULT 0 AFTER `score`");
                }
                if (!$checkCol('quiz_attempts', 'passing_percentage')) {
                    dbExecute("ALTER TABLE quiz_attempts ADD COLUMN `passing_percentage` INT DEFAULT 70 AFTER `total_questions`");
                }
                if (!$checkCol('quiz_attempts', 'passed')) {
                    dbExecute("ALTER TABLE quiz_attempts ADD COLUMN `passed` TINYINT(1) DEFAULT 0 AFTER `passing_percentage`");
                }
                if (!$checkCol('quiz_attempts', 'started_at')) {
                    dbExecute("ALTER TABLE quiz_attempts ADD COLUMN `started_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER `passed`");
                }

                // user_answers: add selected_option, answer_text
                if (!$checkCol('user_answers', 'selected_option')) {
                    dbExecute("ALTER TABLE user_answers ADD COLUMN `selected_option` VARCHAR(10) NULL AFTER `question_id`");
                }
                if (!$checkCol('user_answers', 'answer_text')) {
                    dbExecute("ALTER TABLE user_answers ADD COLUMN `answer_text` TEXT NULL AFTER `selected_option`");
                }

                // training_quiz_questions: add category_id, display_order, updated_at
                if (!$checkCol('training_quiz_questions', 'category_id')) {
                    dbExecute("ALTER TABLE training_quiz_questions ADD COLUMN `category_id` INT NULL AFTER `quiz_id`");
                }
                if (!$checkCol('training_quiz_questions', 'display_order')) {
                    dbExecute("ALTER TABLE training_quiz_questions ADD COLUMN `display_order` INT DEFAULT 0 AFTER `explanation`");
                }
                if (!$checkCol('training_quiz_questions', 'updated_at')) {
                    dbExecute("ALTER TABLE training_quiz_questions ADD COLUMN `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`");
                }

                // Fix existing TF questions with wrong correct_option values
                dbExecute("UPDATE training_quiz_questions SET correct_option = 'T' WHERE question_type = 'TRUE_FALSE' AND LOWER(correct_option) IN ('true', 'σωστό')");
                dbExecute("UPDATE training_quiz_questions SET correct_option = 'F' WHERE question_type = 'TRUE_FALSE' AND LOWER(correct_option) IN ('false', 'λάθος')");
            },
        ],

        [
            'version'     => 26,
            'description' => 'Robust TF correct_option fix – also match single-char t/f from CHAR(1) truncation',
            'up' => function () {
                // Fix all TF questions to normalized T/F values
                // Covers: 't', 'true', 'T', 'TRUE', 'Σωστό' → 'T'
                dbExecute("
                    UPDATE training_quiz_questions 
                    SET correct_option = 'T' 
                    WHERE question_type = 'TRUE_FALSE' 
                      AND correct_option != 'T'
                      AND LOWER(TRIM(correct_option)) IN ('t', 'true', '1', 'σωστό')
                ");
                // Covers: 'f', 'false', 'F', 'FALSE', 'Λάθος' → 'F'
                dbExecute("
                    UPDATE training_quiz_questions 
                    SET correct_option = 'F' 
                    WHERE question_type = 'TRUE_FALSE' 
                      AND correct_option != 'F'
                      AND LOWER(TRIM(correct_option)) IN ('f', 'false', '0', 'λάθος')
                ");
                // Also fix exam questions with same issue
                dbExecute("
                    UPDATE training_exam_questions 
                    SET correct_option = 'T' 
                    WHERE question_type = 'TRUE_FALSE' 
                      AND correct_option != 'T'
                      AND LOWER(TRIM(correct_option)) IN ('t', 'true', '1', 'σωστό')
                ");
                dbExecute("
                    UPDATE training_exam_questions 
                    SET correct_option = 'F' 
                    WHERE question_type = 'TRUE_FALSE' 
                      AND correct_option != 'F'
                      AND LOWER(TRIM(correct_option)) IN ('f', 'false', '0', 'λάθος')
                ");

                // Widen correct_option from CHAR(1) to VARCHAR(10) if it's still CHAR(1)
                // This prevents future truncation issues
                $col = dbFetchOne(
                    "SELECT DATA_TYPE, CHARACTER_MAXIMUM_LENGTH 
                     FROM INFORMATION_SCHEMA.COLUMNS 
                     WHERE TABLE_SCHEMA = DATABASE() 
                       AND TABLE_NAME = 'training_quiz_questions' 
                       AND COLUMN_NAME = 'correct_option'"
                );
                if ($col && strtolower($col['DATA_TYPE']) === 'char' && (int)$col['CHARACTER_MAXIMUM_LENGTH'] === 1) {
                    dbExecute("ALTER TABLE training_quiz_questions MODIFY COLUMN `correct_option` VARCHAR(10) NULL");
                }
                $col2 = dbFetchOne(
                    "SELECT DATA_TYPE, CHARACTER_MAXIMUM_LENGTH 
                     FROM INFORMATION_SCHEMA.COLUMNS 
                     WHERE TABLE_SCHEMA = DATABASE() 
                       AND TABLE_NAME = 'training_exam_questions' 
                       AND COLUMN_NAME = 'correct_option'"
                );
                if ($col2 && strtolower($col2['DATA_TYPE']) === 'char' && (int)$col2['CHARACTER_MAXIMUM_LENGTH'] === 1) {
                    dbExecute("ALTER TABLE training_exam_questions MODIFY COLUMN `correct_option` VARCHAR(10) NULL");
                }
            },
        ],

        // ── v27: Fix corrupted TF questions saved with MC correct_option (A/B/C/D) ──
        [
            'version' => 27,
            'description' => 'Fix TF questions that have MC-style correct_option (A/B/C/D) from questions-pool bug',
            'up' => function () {
                // TF questions should only have T or F as correct_option
                // If they have A/B/C/D, it's from the questions-pool.php bug
                // Since we can't know the intended answer, default to 'T' and log it
                
                // Fix quiz questions
                $corruptQuiz = dbFetchAll("
                    SELECT id, quiz_id, question_text, correct_option 
                    FROM training_quiz_questions 
                    WHERE question_type = 'TRUE_FALSE' 
                      AND correct_option IN ('A', 'B', 'C', 'D')
                ");
                if (!empty($corruptQuiz)) {
                    dbExecute("
                        UPDATE training_quiz_questions 
                        SET correct_option = 'T' 
                        WHERE question_type = 'TRUE_FALSE' 
                          AND correct_option IN ('A', 'B', 'C', 'D')
                    ");
                    // Create admin notification about corrupted questions
                    $count = count($corruptQuiz);
                    $qIds = array_column($corruptQuiz, 'id');
                    $adminUsers = dbFetchAll("SELECT id FROM users WHERE role IN ('SYSTEM_ADMIN', 'DEPARTMENT_ADMIN')");
                    foreach ($adminUsers as $admin) {
                        try {
                            dbInsert(
                                "INSERT INTO notifications (user_id, type, title, message, data, created_at)
                                 VALUES (?, 'system', ?, ?, ?, NOW())",
                                [
                                    $admin['id'],
                                    'Διόρθωση ερωτήσεων Σωστό/Λάθος',
                                    $count . ' ερωτήσεις Σωστό/Λάθος κουίζ είχαν λάθος σωστή απάντηση (A/B/C/D αντί T/F). Διορθώθηκαν σε "Σωστό" (T). Ελέγξτε τις ερωτήσεις ID: ' . implode(', ', $qIds),
                                    json_encode(['question_ids' => $qIds])
                                ]
                            );
                        } catch (Exception $e) {
                            // Notifications table may differ
                        }
                    }
                    error_log("[migrations] v27: Fixed $count corrupt quiz TF questions (IDs: " . implode(', ', $qIds) . ") - set to T");
                }
                
                // Fix exam questions
                $corruptExam = dbFetchAll("
                    SELECT id, exam_id, question_text, correct_option 
                    FROM training_exam_questions 
                    WHERE question_type = 'TRUE_FALSE' 
                      AND correct_option IN ('A', 'B', 'C', 'D')
                ");
                if (!empty($corruptExam)) {
                    dbExecute("
                        UPDATE training_exam_questions 
                        SET correct_option = 'T' 
                        WHERE question_type = 'TRUE_FALSE' 
                          AND correct_option IN ('A', 'B', 'C', 'D')
                    ");
                    $count2 = count($corruptExam);
                    $eIds = array_column($corruptExam, 'id');
                    error_log("[migrations] v27: Fixed $count2 corrupt exam TF questions (IDs: " . implode(', ', $eIds) . ") - set to T");
                }
                
                // Also fix any TF questions with empty/null correct_option
                dbExecute("
                    UPDATE training_quiz_questions 
                    SET correct_option = 'T' 
                    WHERE question_type = 'TRUE_FALSE' 
                      AND (correct_option IS NULL OR correct_option = '')
                ");
                dbExecute("
                    UPDATE training_exam_questions 
                    SET correct_option = 'T' 
                    WHERE question_type = 'TRUE_FALSE' 
                      AND (correct_option IS NULL OR correct_option = '')
                ");
            },
        ],

        [
            'version'     => 28,
            'description' => 'Add category_id/display_order/updated_at to training_exam_questions; make exam_id and quiz_id nullable for pool support',
            'up' => function () {
                $checkCol = function ($table, $col) {
                    return (bool) dbFetchOne(
                        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?",
                        [$table, $col]
                    );
                };
                if (!$checkCol('training_exam_questions', 'category_id')) {
                    dbExecute("ALTER TABLE training_exam_questions ADD COLUMN category_id INT UNSIGNED NULL AFTER exam_id");
                }
                if (!$checkCol('training_exam_questions', 'display_order')) {
                    dbExecute("ALTER TABLE training_exam_questions ADD COLUMN display_order INT DEFAULT 0");
                }
                if (!$checkCol('training_exam_questions', 'updated_at')) {
                    dbExecute("ALTER TABLE training_exam_questions ADD COLUMN updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
                }
                // Make exam_id nullable for pool questions (drop FK, modify, re-add)
                $fk1 = dbFetchOne("SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
                    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='training_exam_questions' AND COLUMN_NAME='exam_id' AND REFERENCED_TABLE_NAME IS NOT NULL LIMIT 1");
                if ($fk1) {
                    dbExecute("ALTER TABLE training_exam_questions DROP FOREIGN KEY `{$fk1['CONSTRAINT_NAME']}`");
                }
                dbExecute("ALTER TABLE training_exam_questions MODIFY exam_id INT NULL");
                if ($fk1) {
                    dbExecute("ALTER TABLE training_exam_questions ADD CONSTRAINT `{$fk1['CONSTRAINT_NAME']}` FOREIGN KEY (exam_id) REFERENCES training_exams(id) ON DELETE SET NULL");
                }
                // Make quiz_id nullable for pool questions
                $fk2 = dbFetchOne("SELECT CONSTRAINT_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
                    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='training_quiz_questions' AND COLUMN_NAME='quiz_id' AND REFERENCED_TABLE_NAME IS NOT NULL LIMIT 1");
                if ($fk2) {
                    dbExecute("ALTER TABLE training_quiz_questions DROP FOREIGN KEY `{$fk2['CONSTRAINT_NAME']}`");
                }
                dbExecute("ALTER TABLE training_quiz_questions MODIFY quiz_id INT NULL");
                if ($fk2) {
                    dbExecute("ALTER TABLE training_quiz_questions ADD CONSTRAINT `{$fk2['CONSTRAINT_NAME']}` FOREIGN KEY (quiz_id) REFERENCES training_quizzes(id) ON DELETE SET NULL");
                }
            },
        ],

        [
            'version'     => 29,
            'description' => 'Add use_random_pool to training_quizzes',
            'up' => function () {
                $exists = (bool) dbFetchOne(
                    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='training_quizzes' AND COLUMN_NAME='use_random_pool'"
                );
                if (!$exists) {
                    dbExecute("ALTER TABLE training_quizzes ADD COLUMN use_random_pool TINYINT(1) NOT NULL DEFAULT 0 AFTER time_limit_minutes");
                }
            },
        ],

        [
            'version'     => 30,
            'description' => 'Schema consolidation — add missing tables, columns, and seed data for v3.39',
            'up' => function () {
                $checkCol = function ($table, $column) {
                    return (bool) dbFetchOne(
                        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
                        [$table, $column]
                    );
                };
                $tableExists = function ($table) {
                    return (bool) dbFetchOne(
                        "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
                        [$table]
                    );
                };

                // ── Missing columns on users ──
                if (!$checkCol('users', 'profile_photo')) {
                    dbExecute("ALTER TABLE users ADD COLUMN profile_photo VARCHAR(255) NULL DEFAULT NULL AFTER phone");
                }
                if (!$checkCol('users', 'position_id')) {
                    dbExecute("ALTER TABLE users ADD COLUMN position_id INT UNSIGNED NULL AFTER volunteer_type");
                }
                if (!$checkCol('users', 'warehouse_id')) {
                    dbExecute("ALTER TABLE users ADD COLUMN warehouse_id INT UNSIGNED NULL AFTER department_id");
                }
                if (!$checkCol('users', 'newsletter_unsubscribed')) {
                    dbExecute("ALTER TABLE users ADD COLUMN newsletter_unsubscribed TINYINT(1) NOT NULL DEFAULT 0 AFTER deleted_by");
                }

                // ── Missing columns on departments ──
                if (!$checkCol('departments', 'has_inventory')) {
                    dbExecute("ALTER TABLE departments ADD COLUMN has_inventory TINYINT(1) DEFAULT 0 AFTER is_active");
                }
                if (!$checkCol('departments', 'inventory_settings')) {
                    dbExecute("ALTER TABLE departments ADD COLUMN inventory_settings JSON NULL AFTER has_inventory");
                }

                // ── Missing column on missions ──
                if (!$checkCol('missions', 'responsible_user_id')) {
                    dbExecute("ALTER TABLE missions ADD COLUMN responsible_user_id INT UNSIGNED NULL AFTER created_by");
                }

                // ── Missing columns on tasks ──
                if (!$checkCol('tasks', 'progress')) {
                    dbExecute("ALTER TABLE tasks ADD COLUMN progress INT DEFAULT 0 AFTER status");
                }
                if (!$checkCol('tasks', 'responsible_user_id')) {
                    dbExecute("ALTER TABLE tasks ADD COLUMN responsible_user_id INT UNSIGNED NULL AFTER created_by");
                }

                // ── volunteer_documents table ──
                if (!$tableExists('volunteer_documents')) {
                    dbExecute("CREATE TABLE volunteer_documents (
                        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        user_id INT UNSIGNED NOT NULL,
                        label VARCHAR(255) NOT NULL,
                        original_name VARCHAR(255) NOT NULL,
                        stored_name VARCHAR(255) NOT NULL,
                        mime_type VARCHAR(100) NOT NULL,
                        file_size INT NOT NULL DEFAULT 0,
                        uploaded_by INT UNSIGNED NOT NULL,
                        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        KEY idx_vd_user_id (user_id),
                        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                        FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE CASCADE
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                }

                // ── volunteer_positions table ──
                if (!$tableExists('volunteer_positions')) {
                    dbExecute("CREATE TABLE volunteer_positions (
                        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        name VARCHAR(100) NOT NULL,
                        color VARCHAR(20) DEFAULT 'secondary',
                        icon VARCHAR(50) NULL,
                        description TEXT NULL,
                        is_active TINYINT(1) DEFAULT 1,
                        sort_order INT DEFAULT 0,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                    dbExecute("INSERT IGNORE INTO volunteer_positions (id, name, color, icon, sort_order) VALUES
                        (1, 'Υπεύθυνος Τμήματος', 'primary', 'bi-person-lines-fill', 1),
                        (2, 'Υπεύθυνος Γραμματείας', 'info', 'bi-envelope-paper', 2),
                        (3, 'Εκπαιδευτής', 'success', 'bi-mortarboard', 3),
                        (4, 'Ταμίας', 'warning', 'bi-cash-coin', 4)");
                }

                // ── inventory_shelf_items table ──
                if (!$tableExists('inventory_shelf_items')) {
                    dbExecute("CREATE TABLE inventory_shelf_items (
                        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        name VARCHAR(255) NOT NULL,
                        quantity INT NOT NULL DEFAULT 1,
                        shelf VARCHAR(100) NULL,
                        expiry_date DATE NULL,
                        notes TEXT NULL,
                        department_id INT UNSIGNED NULL,
                        sort_order INT DEFAULT 0,
                        created_by INT UNSIGNED NULL,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE SET NULL,
                        FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
                        INDEX idx_expiry (expiry_date),
                        INDEX idx_shelf (shelf),
                        INDEX idx_department (department_id)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                }

                // ── shelf_expiry_reminder_days setting ──
                $s = dbFetchOne("SELECT setting_key FROM settings WHERE setting_key = 'shelf_expiry_reminder_days'");
                if (!$s) {
                    dbInsert("INSERT INTO settings (setting_key, setting_value) VALUES ('shelf_expiry_reminder_days', '30')");
                }

                // ── Missing email templates (task-related + mission_needs_volunteers) ──
                // Helper: wrap content in styled email layout
                $wrap30 = function($headerBg, $icon, $title, $body) {
                    return '<div style="background:#eef2f7;padding:28px 0 40px;font-family:Helvetica Neue,Arial,sans-serif;"><div style="max-width:600px;margin:0 auto;"><div style="background:'.$headerBg.';padding:30px 40px 26px;border-radius:12px 12px 0 0;text-align:center;">{{logo_html}}<p style="color:rgba(255,255,255,0.7);font-size:11px;letter-spacing:2px;text-transform:uppercase;margin:0 0 8px;">{{app_name}}</p><div style="font-size:36px;line-height:1;margin:0 0 8px;">'.$icon.'</div><h1 style="color:#fff;margin:0;font-size:23px;font-weight:700;line-height:1.3;">'.$title.'</h1></div><div style="background:#fff;padding:36px 40px 40px;border-radius:0 0 12px 12px;box-shadow:0 4px 20px rgba(0,0,0,0.07);">'.$body.'</div><div style="text-align:center;padding:18px 0 0;color:#9ca3af;font-size:12px;line-height:1.9;"><p style="margin:0;"><strong style="color:#6b7280;">{{app_name}}</strong> &bull; Σύστημα Διαχείρισης Εθελοντών</p><p style="margin:0;">Αυτό το μήνυμα στάλθηκε αυτόματα από το σύστημα.</p></div></div></div>';
                };
                $info30 = function($borderColor, $rows) {
                    $html = '<div style="background:#f9fafb;border-left:4px solid '.$borderColor.';padding:2px 20px;border-radius:0 8px 8px 0;margin:20px 0;">';
                    $last = count($rows) - 1;
                    foreach ($rows as $i => $row) {
                        $border = $i < $last ? 'border-bottom:1px solid #f3f4f6;' : '';
                        $html .= '<div style="padding:7px 0;font-size:14px;'.$border.'"><span style="color:#9ca3af;display:inline-block;min-width:140px;">'.$row[0].':</span><span style="color:#111827;font-weight:600;">'.$row[1].'</span></div>';
                    }
                    $html .= '</div>';
                    return $html;
                };
                $btn30 = function($bg, $text, $url = '{{login_url}}') {
                    return '<div style="text-align:center;margin:28px 0 4px;"><a href="'.$url.'" style="background:'.$bg.';color:#ffffff;text-decoration:none;padding:13px 38px;border-radius:8px;font-size:15px;font-weight:700;display:inline-block;letter-spacing:0.3px;">'.$text.'</a></div>';
                };
                $greet30 = '<h2 style="color:#1f2937;font-size:18px;font-weight:700;margin:0 0 14px;">Γεια σας {{user_name}},</h2>';
                $p30 = function($text) { return '<p style="color:#4b5563;line-height:1.65;font-size:15px;margin:0 0 14px;">'.$text.'</p>'; };
                $alert30 = function($bg, $border, $color, $text) { return '<div style="background:'.$bg.';border:1px solid '.$border.';border-radius:8px;padding:14px 20px;margin:20px 0;"><p style="color:'.$color.';font-size:14px;font-weight:600;margin:0;">'.$text.'</p></div>'; };

                $taskTemplates = [
                    ['task_assigned', 'Ανάθεση Εργασίας', 'Νέα Εργασία: {{task_title}}',
                     $wrap30('#4f46e5', '&#128203;', 'Νέα Ανάθεση Εργασίας',
                        $greet30.$p30('Σας ανατέθηκε μια νέα εργασία από τον/την <strong>{{assigned_by}}</strong>. Παρακαλούμε ελέγξτε τις λεπτομέρειες παρακάτω.').$info30('#4f46e5', [['Εργασία','{{task_title}}'],['Περιγραφή','{{task_description}}'],['Προτεραιότητα','{{task_priority}}'],['Προθεσμία','{{task_deadline}}']]).$p30('Μπορείτε να δείτε τις λεπτομέρειες της εργασίας συνδεόμενοι στο σύστημα.').$btn30('#4f46e5','Δείτε την Εργασία')),
                     'Αποστέλλεται όταν ανατίθεται εργασία', 'user_name, task_title, task_description, task_priority, task_deadline, assigned_by'],
                    ['task_comment', 'Σχόλιο σε Εργασία', 'Νέο Σχόλιο: {{task_title}}',
                     $wrap30('#3b82f6', '&#128172;', 'Νέο Σχόλιο στην Εργασία',
                        $greet30.$p30('Ο/Η <strong>{{commented_by}}</strong> πρόσθεσε ένα νέο σχόλιο στην εργασία "<strong>{{task_title}}</strong>".').'<div style="background:#f0f4ff;border-left:4px solid #3b82f6;padding:16px 20px;border-radius:0 8px 8px 0;margin:20px 0;"><p style="color:#1e40af;font-size:14px;line-height:1.65;margin:0;font-style:italic;">{{comment}}</p></div>'.$p30('Συνδεθείτε στο σύστημα για να δείτε την εργασία και να απαντήσετε.').$btn30('#3b82f6','Δείτε την Εργασία')),
                     'Αποστέλλεται όταν προστίθεται σχόλιο', 'user_name, task_title, comment, commented_by'],
                    ['task_deadline_reminder', 'Υπενθύμιση Προθεσμίας', 'Υπενθύμιση: {{task_title}} λήγει σύντομα',
                     $wrap30('#f97316', '&#9200;', 'Υπενθύμιση Προθεσμίας Εργασίας',
                        $greet30.$p30('Σας υπενθυμίζουμε ότι η εργασία "<strong>{{task_title}}</strong>" λήγει σε <strong>λιγότερο από 24 ώρες</strong>.').$info30('#f97316', [['Εργασία','{{task_title}}'],['Προθεσμία','{{task_deadline}}'],['Κατάσταση','{{task_status}}'],['Πρόοδος','{{task_progress}}%']]).$alert30('#fff7ed','#fed7aa','#c2410c','⏰ Η προθεσμία πλησιάζει — παρακαλούμε ολοκληρώστε την εργασία εγκαίρως.').$btn30('#f97316','Δείτε την Εργασία')),
                     'Αποστέλλεται 24h πριν τη λήξη', 'user_name, task_title, task_deadline, task_status, task_progress'],
                    ['task_status_changed', 'Αλλαγή Κατάστασης Εργασίας', 'Αλλαγή: {{task_title}}',
                     $wrap30('#8b5cf6', '&#128260;', 'Αλλαγή Κατάστασης Εργασίας',
                        $greet30.$p30('Ο/Η <strong>{{changed_by}}</strong> άλλαξε την κατάσταση της εργασίας "<strong>{{task_title}}</strong>".').'<div style="text-align:center;margin:24px 0;padding:20px;background:#f9fafb;border-radius:8px;"><span style="background:#fef2f2;color:#991b1b;padding:6px 14px;border-radius:6px;font-size:14px;font-weight:600;text-decoration:line-through;">{{old_status}}</span><span style="display:inline-block;margin:0 16px;color:#9ca3af;font-size:20px;">→</span><span style="background:#dcfce7;color:#166534;padding:6px 14px;border-radius:6px;font-size:14px;font-weight:600;">{{new_status}}</span></div>'.$p30('Συνδεθείτε στο σύστημα για να δείτε τις λεπτομέρειες.').$btn30('#8b5cf6','Δείτε την Εργασία')),
                     'Αποστέλλεται όταν αλλάζει η κατάσταση', 'user_name, task_title, old_status, new_status, changed_by'],
                    ['task_subtask_completed', 'Ολοκλήρωση Υποεργασίας', 'Ολοκληρώθηκε: {{subtask_title}}',
                     $wrap30('#22c55e', '&#9989;', 'Ολοκλήρωση Υποεργασίας',
                        $greet30.$p30('Ο/Η <strong>{{completed_by}}</strong> ολοκλήρωσε μια υποεργασία στην εργασία "<strong>{{task_title}}</strong>".').$info30('#22c55e', [['Υποεργασία','{{subtask_title}}'],['Εργασία','{{task_title}}']]).$alert30('#f0fdf4','#bbf7d0','#166534','✅ Η υποεργασία έχει σημανθεί ως ολοκληρωμένη.').$p30('Συνδεθείτε στο σύστημα για να δείτε την πρόοδο της εργασίας.').$btn30('#22c55e','Δείτε την Εργασία')),
                     'Αποστέλλεται όταν ολοκληρώνεται υποεργασία', 'user_name, task_title, subtask_title, completed_by'],
                    ['mission_needs_volunteers', 'Αποστολή Χρειάζεται Εθελοντές', 'Η αποστολή {{mission_title}} χρειάζεται εθελοντές!',
                     $wrap30('#dc2626', '&#128680;', 'Χρειάζονται Εθελοντές!',
                        $greet30.$p30('Η αποστολή "<strong>{{mission_title}}</strong>" χρειάζεται <strong>επειγόντως</strong> περισσότερους εθελοντές!').$info30('#dc2626', [['Αποστολή','{{mission_title}}'],['Ημερομηνία','{{mission_date}}'],['Θέσεις Ανοιχτές','{{available_spots}}'],['Συνολικές Θέσεις','{{total_spots}}']]).$alert30('#fef2f2','#fecaca','#dc2626','🚨 Η βοήθειά σας χρειάζεται! Κάθε εθελοντής κάνει τη διαφορά.').$p30('Αν ενδιαφέρεστε να συμμετέχετε, παρακαλούμε συνδεθείτε στο σύστημα και κάντε αίτηση συμμετοχής.').$btn30('#dc2626','Δηλώστε Συμμετοχή')),
                     'Αποστέλλεται για αποστολές χωρίς αρκετούς εθελοντές', '{{app_name}}, {{user_name}}, {{mission_title}}, {{mission_description}}, {{mission_url}}'],
                    ['shelf_expiry_reminder', 'Υπενθύμιση Λήξης Ραφιών Αποθήκης', 'Υπενθύμιση: Είδη Αποθήκης Λήγουν ή Έχουν Λήξει',
                     $wrap30('#d97706', '&#128230;', 'Υπενθύμιση Λήξης Ραφιών Αποθήκης',
                        $greet30.$p30('Υπάρχουν είδη αποθήκης που πλησιάζουν ή έχουν ξεπεράσει την ημερομηνία λήξης τους.').$info30('#d97706', [['Ληγμένα Είδη','{{expired_count}}'],['Κοντά σε Λήξη (εντός {{threshold_days}} ημερών)','{{expiring_count}}']]).'<div style="background:#f9fafb;border-radius:8px;padding:16px 20px;margin:20px 0;"><p style="color:#6b7280;font-size:13px;font-weight:600;text-transform:uppercase;letter-spacing:1px;margin:0 0 8px;">Λεπτομέρειες:</p><pre style="background:#fff;border:1px solid #e5e7eb;border-radius:6px;padding:12px 16px;font-size:13px;color:#374151;margin:0;white-space:pre-wrap;word-break:break-word;">{{details}}</pre></div>'.$p30('Συνδεθείτε στο σύστημα για να ελέγξετε τα είδη.').$btn30('#d97706','Διαχείριση Αποθήκης')),
                     'Αποστέλλεται όταν υπάρχουν ληγμένα ή υπό λήξη υλικά ραφιού', 'user_name, expired_count, expiring_count, details, threshold_days'],
                ];
                foreach ($taskTemplates as $t) {
                    $exists = dbFetchOne("SELECT id FROM email_templates WHERE code = ?", [$t[0]]);
                    if (!$exists) {
                        dbInsert(
                            "INSERT INTO email_templates (code, name, subject, body_html, description, available_variables) VALUES (?, ?, ?, ?, ?, ?)",
                            $t
                        );
                    }
                }

                // ── Missing notification_settings entries ──
                $notifCodes = [
                    ['task_assigned', 'Ανάθεση Εργασίας', 'Όταν ανατίθεται εργασία σε εθελοντή', 1],
                    ['task_comment', 'Σχόλιο σε Εργασία', 'Όταν προστίθεται σχόλιο σε εργασία', 1],
                    ['task_deadline_reminder', 'Υπενθύμιση Προθεσμίας', 'Όταν πλησιάζει η προθεσμία εργασίας', 1],
                    ['task_status_changed', 'Αλλαγή Κατάστασης Εργασίας', 'Όταν αλλάζει η κατάσταση εργασίας', 1],
                    ['task_subtask_completed', 'Ολοκλήρωση Υποεργασίας', 'Όταν ολοκληρώνεται υποεργασία', 1],
                    ['mission_needs_volunteers', 'Αποστολή Χρειάζεται Εθελοντές', 'Όταν αποστολή πλησιάζει χωρίς αρκετούς εθελοντές', 1],
                    ['shelf_expiry_reminder', 'Ειδοποίηση Λήξης Υλικών Ραφιού', 'Όταν υπάρχουν ληγμένα ή υπό λήξη υλικά ραφιού', 1],
                ];
                foreach ($notifCodes as $n) {
                    $exists = dbFetchOne("SELECT id FROM notification_settings WHERE code = ?", [$n[0]]);
                    if (!$exists) {
                        $tmplId = dbFetchValue("SELECT id FROM email_templates WHERE code = ?", [$n[0]]);
                        dbInsert(
                            "INSERT INTO notification_settings (code, name, description, email_enabled, email_template_id) VALUES (?, ?, ?, ?, ?)",
                            [$n[0], $n[1], $n[2], $n[3], $tmplId]
                        );
                    }
                }
            },
        ],

        [
            'version'     => 31,
            'description' => 'Add citizens and citizen_certificates tables',
            'up' => function () {
                $tableExists = function ($table) {
                    return (bool) dbFetchOne(
                        "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
                        [$table]
                    );
                };

                if (!$tableExists('citizens')) {
                    dbExecute("CREATE TABLE citizens (
                        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        first_name_gr VARCHAR(100) NOT NULL COMMENT 'Όνομα (Ελληνικά)',
                        last_name_gr VARCHAR(100) NOT NULL COMMENT 'Επίθετο (Ελληνικά)',
                        first_name_lat VARCHAR(100) NULL COMMENT 'Όνομα (Λατινικά)',
                        last_name_lat VARCHAR(100) NULL COMMENT 'Επίθετο (Λατινικά)',
                        birth_date DATE NULL COMMENT 'Ημερομηνία γέννησης',
                        email VARCHAR(255) NULL,
                        phone VARCHAR(30) NULL,
                        contact_done TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Επικοινωνία',
                        payment_done TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Πληρωμή',
                        completed TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Έχει ολοκληρώσει',
                        notes TEXT NULL,
                        created_by INT UNSIGNED NULL,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        INDEX idx_citizens_name_gr (last_name_gr, first_name_gr),
                        INDEX idx_citizens_email (email)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                }

                if (!$tableExists('citizen_certificates')) {
                    dbExecute("CREATE TABLE citizen_certificates (
                        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        first_name VARCHAR(100) NOT NULL COMMENT 'Όνομα',
                        last_name VARCHAR(100) NOT NULL COMMENT 'Επίθετο',
                        father_name VARCHAR(100) NULL COMMENT 'Όνομα Πατρός',
                        birth_date DATE NULL COMMENT 'Ημερομηνία γέννησης',
                        issue_date DATE NULL COMMENT 'Ημ. Έκδοσης',
                        expiry_date DATE NULL COMMENT 'Ημ. Λήξης',
                        notes TEXT NULL,
                        created_by INT UNSIGNED NULL,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        INDEX idx_cc_name (last_name, first_name),
                        INDEX idx_cc_expiry (expiry_date)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                }
            },
        ],

        [
            'version'     => 32,
            'description' => 'Add citizen_certificate_types table and certificate_type_id column',
            'up' => function () {
                $tableExists = function ($table) {
                    return (bool) dbFetchOne(
                        "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
                        [$table]
                    );
                };
                $checkCol = function ($table, $column) {
                    return (bool) dbFetchOne(
                        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
                        [$table, $column]
                    );
                };

                if (!$tableExists('citizen_certificate_types')) {
                    dbExecute("CREATE TABLE citizen_certificate_types (
                        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        name VARCHAR(150) NOT NULL,
                        description TEXT NULL,
                        is_active TINYINT(1) NOT NULL DEFAULT 1,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                }

                if (!$checkCol('citizen_certificates', 'certificate_type_id')) {
                    dbExecute("ALTER TABLE citizen_certificates ADD COLUMN certificate_type_id INT UNSIGNED NULL AFTER id, ADD INDEX idx_cc_type (certificate_type_id)");
                }
            },
        ],

        [
            'version'     => 33,
            'description' => 'Add email + reminder columns to citizen_certificates, email templates & notification settings for citizen cert expiry',
            'up' => function () {
                $checkCol = function ($table, $column) {
                    return (bool) dbFetchOne(
                        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?",
                        [$table, $column]
                    );
                };

                // Add email column
                if (!$checkCol('citizen_certificates', 'email')) {
                    dbExecute("ALTER TABLE citizen_certificates ADD COLUMN email VARCHAR(255) NULL AFTER expiry_date");
                }

                // Add reminder tracking columns
                $reminderCols = ['reminder_sent_3m', 'reminder_sent_1m', 'reminder_sent_1w', 'reminder_sent_expired'];
                foreach ($reminderCols as $col) {
                    if (!$checkCol('citizen_certificates', $col)) {
                        dbExecute("ALTER TABLE citizen_certificates ADD COLUMN {$col} TINYINT(1) NOT NULL DEFAULT 0");
                    }
                }

                // Insert email templates for citizen cert expiry
                // Helper: wrap content in styled email layout
                $wrap = function($headerBg, $icon, $title, $body) {
                    return '<div style="background:#eef2f7;padding:28px 0 40px;font-family:Helvetica Neue,Arial,sans-serif;"><div style="max-width:600px;margin:0 auto;"><div style="background:'.$headerBg.';padding:30px 40px 26px;border-radius:12px 12px 0 0;text-align:center;">{{logo_html}}<p style="color:rgba(255,255,255,0.7);font-size:11px;letter-spacing:2px;text-transform:uppercase;margin:0 0 8px;">{{app_name}}</p><div style="font-size:36px;line-height:1;margin:0 0 8px;">'.$icon.'</div><h1 style="color:#fff;margin:0;font-size:23px;font-weight:700;line-height:1.3;">'.$title.'</h1></div><div style="background:#fff;padding:36px 40px 40px;border-radius:0 0 12px 12px;box-shadow:0 4px 20px rgba(0,0,0,0.07);">'.$body.'</div><div style="text-align:center;padding:18px 0 0;color:#9ca3af;font-size:12px;line-height:1.9;"><p style="margin:0;"><strong style="color:#6b7280;">{{app_name}}</strong> &bull; Σύστημα Διαχείρισης Εθελοντών</p><p style="margin:0;">Αυτό το μήνυμα στάλθηκε αυτόματα από το σύστημα.</p></div></div></div>';
                };
                $certBlock = function($borderColor) {
                    return '<div style="background:#f9fafb;border-left:4px solid '.$borderColor.';padding:2px 20px;border-radius:0 8px 8px 0;margin:20px 0;"><div style="padding:7px 0;font-size:14px;border-bottom:1px solid #f3f4f6;"><span style="color:#9ca3af;display:inline-block;min-width:140px;">Πιστοποιητικό:</span><span style="color:#111827;font-weight:600;">{{certificate_type}}</span></div><div style="padding:7px 0;font-size:14px;border-bottom:1px solid #f3f4f6;"><span style="color:#9ca3af;display:inline-block;min-width:140px;">Ημ. Λήξης:</span><span style="color:#111827;font-weight:600;">{{expiry_date}}</span></div><div style="padding:7px 0;font-size:14px;"><span style="color:#9ca3af;display:inline-block;min-width:140px;">Υπόλοιπες Ημέρες:</span><span style="color:#111827;font-weight:600;">{{days_remaining}} ημέρες</span></div></div>';
                };
                $btn = function($bg, $text) {
                    return '<div style="text-align:center;margin:28px 0 4px;"><a href="{{login_url}}" style="background:'.$bg.';color:#ffffff;text-decoration:none;padding:13px 38px;border-radius:8px;font-size:15px;font-weight:700;display:inline-block;letter-spacing:0.3px;">'.$text.'</a></div>';
                };

                $templates = [
                    [
                        'code' => 'citizen_cert_expiry_3months',
                        'name' => 'Λήξη Πιστοποιητικού Πολίτη (3 μήνες)',
                        'subject' => 'Υπενθύμιση: Το πιστοποιητικό σας λήγει σε 3 μήνες',
                        'body_html' => $wrap('#0ea5e9', '&#128203;', 'Υπενθύμιση Λήξης Πιστοποιητικού',
                            '<h2 style="color:#1f2937;font-size:18px;font-weight:700;margin:0 0 14px;">Αγαπητέ/ή {{citizen_name}},</h2><p style="color:#4b5563;line-height:1.65;font-size:15px;margin:0 0 14px;">Σας ενημερώνουμε ότι το πιστοποιητικό σας πλησιάζει στην ημερομηνία λήξης του. Παρακαλούμε ξεκινήστε τη διαδικασία ανανέωσής του εγκαίρως.</p>'.$certBlock('#0ea5e9').'<p style="color:#4b5563;line-height:1.65;font-size:15px;margin:0 0 14px;">Διαθέτετε ακόμη αρκετό χρόνο για να ολοκληρώσετε τη διαδικασία ανανέωσης.</p>'.$btn('#0ea5e9','Σύνδεση στο Σύστημα')),
                        'description' => 'Αποστέλλεται 3 μήνες πριν τη λήξη πιστοποιητικού πολίτη',
                    ],
                    [
                        'code' => 'citizen_cert_expiry_1month',
                        'name' => 'Λήξη Πιστοποιητικού Πολίτη (1 μήνα)',
                        'subject' => 'Υπενθύμιση: Το πιστοποιητικό σας λήγει σε 1 μήνα',
                        'body_html' => $wrap('#eab308', '&#9888;', 'Το Πιστοποιητικό σας Λήγει Σύντομα',
                            '<h2 style="color:#1f2937;font-size:18px;font-weight:700;margin:0 0 14px;">Αγαπητέ/ή {{citizen_name}},</h2><p style="color:#4b5563;line-height:1.65;font-size:15px;margin:0 0 14px;">Το πιστοποιητικό σας λήγει <strong>σε λιγότερο από 1 μήνα</strong>. Παρακαλούμε φροντίστε άμεσα για την ανανέωσή του.</p>'.$certBlock('#eab308').'<p style="color:#4b5563;line-height:1.65;font-size:15px;margin:0 0 14px;">Μην αφήσετε το πιστοποιητικό σας να λήξει — ξεκινήστε τη διαδικασία ανανέωσης σήμερα.</p>'.$btn('#eab308','Σύνδεση στο Σύστημα')),
                        'description' => 'Αποστέλλεται 1 μήνα πριν τη λήξη πιστοποιητικού πολίτη',
                    ],
                    [
                        'code' => 'citizen_cert_expiry_1week',
                        'name' => 'Λήξη Πιστοποιητικού Πολίτη (1 εβδομάδα)',
                        'subject' => '⚠ Επείγον: Το πιστοποιητικό σας λήγει σε 1 εβδομάδα',
                        'body_html' => $wrap('#f97316', '&#9888;', 'Επείγουσα Υπενθύμιση — Λήξη Πιστοποιητικού',
                            '<h2 style="color:#1f2937;font-size:18px;font-weight:700;margin:0 0 14px;">Αγαπητέ/ή {{citizen_name}},</h2><p style="color:#4b5563;line-height:1.65;font-size:15px;margin:0 0 14px;"><strong style="color:#dc2626;">Επείγουσα ειδοποίηση:</strong> Το πιστοποιητικό σας λήγει <strong>σε μόλις λίγες ημέρες</strong>. Απαιτείται άμεση ενέργεια.</p>'.$certBlock('#f97316').'<div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:14px 20px;margin:20px 0;"><p style="color:#dc2626;font-size:14px;font-weight:600;margin:0;">⚠ Αν δεν ανανεωθεί εγκαίρως, το πιστοποιητικό σας θα λήξει και δεν θα είναι έγκυρο.</p></div>'.$btn('#f97316','Σύνδεση στο Σύστημα')),
                        'description' => 'Αποστέλλεται 1 εβδομάδα πριν τη λήξη πιστοποιητικού πολίτη',
                    ],
                    [
                        'code' => 'citizen_cert_expiry_expired',
                        'name' => 'Λήξη Πιστοποιητικού Πολίτη (Ληγμένο)',
                        'subject' => '🔴 Το πιστοποιητικό σας έχει λήξει',
                        'body_html' => $wrap('#dc2626', '&#10060;', 'Το Πιστοποιητικό σας Έληξε',
                            '<h2 style="color:#1f2937;font-size:18px;font-weight:700;margin:0 0 14px;">Αγαπητέ/ή {{citizen_name}},</h2><p style="color:#4b5563;line-height:1.65;font-size:15px;margin:0 0 14px;">Σας ενημερώνουμε ότι το πιστοποιητικό σας <strong style="color:#dc2626;">έχει λήξει</strong>. Παρακαλούμε φροντίστε για την <strong>άμεση ανανέωσή</strong> του.</p><div style="background:#f9fafb;border-left:4px solid #dc2626;padding:2px 20px;border-radius:0 8px 8px 0;margin:20px 0;"><div style="padding:7px 0;font-size:14px;border-bottom:1px solid #f3f4f6;"><span style="color:#9ca3af;display:inline-block;min-width:140px;">Πιστοποιητικό:</span><span style="color:#111827;font-weight:600;">{{certificate_type}}</span></div><div style="padding:7px 0;font-size:14px;border-bottom:1px solid #f3f4f6;"><span style="color:#9ca3af;display:inline-block;min-width:140px;">Ημ. Λήξης:</span><span style="color:#dc2626;font-weight:600;">{{expiry_date}}</span></div><div style="padding:7px 0;font-size:14px;"><span style="color:#9ca3af;display:inline-block;min-width:140px;">Κατάσταση:</span><span style="background:#dc2626;color:#fff;padding:2px 10px;border-radius:4px;font-size:13px;font-weight:600;">ΛΗΓΜΕΝΟ</span></div></div><div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:14px 20px;margin:20px 0;"><p style="color:#dc2626;font-size:14px;font-weight:600;margin:0;">🔴 Το πιστοποιητικό δεν είναι πλέον σε ισχύ. Επικοινωνήστε μαζί μας ή ξεκινήστε τη διαδικασία ανανέωσης.</p></div>'.$btn('#dc2626','Σύνδεση στο Σύστημα')),
                        'description' => 'Αποστέλλεται όταν ένα πιστοποιητικό πολίτη λήξει',
                    ],
                ];

                foreach ($templates as $tpl) {
                    $exists = dbFetchValue("SELECT COUNT(*) FROM email_templates WHERE code = ?", [$tpl['code']]);
                    if (!$exists) {
                        dbInsert(
                            "INSERT INTO email_templates (code, name, subject, body_html, description, is_active, created_at, updated_at)
                             VALUES (?, ?, ?, ?, ?, 1, NOW(), NOW())",
                            [$tpl['code'], $tpl['name'], $tpl['subject'], $tpl['body_html'], $tpl['description']]
                        );
                    }
                }

                // Insert notification settings for each template, linking via email_template_id
                $notifCodes = [
                    ['code' => 'citizen_cert_expiry_3months', 'name' => 'Λήξη Πιστοποιητικού Πολίτη (3 μήνες)', 'description' => 'Email 3 μήνες πριν τη λήξη πιστοποιητικού πολίτη'],
                    ['code' => 'citizen_cert_expiry_1month',  'name' => 'Λήξη Πιστοποιητικού Πολίτη (1 μήνα)',  'description' => 'Email 1 μήνα πριν τη λήξη πιστοποιητικού πολίτη'],
                    ['code' => 'citizen_cert_expiry_1week',   'name' => 'Λήξη Πιστοποιητικού Πολίτη (1 εβδομάδα)', 'description' => 'Email 1 εβδομάδα πριν τη λήξη πιστοποιητικού πολίτη'],
                    ['code' => 'citizen_cert_expiry_expired', 'name' => 'Λήξη Πιστοποιητικού Πολίτη (Ληγμένο)', 'description' => 'Email κατά τη λήξη πιστοποιητικού πολίτη'],
                ];

                foreach ($notifCodes as $ns) {
                    $exists = dbFetchValue("SELECT COUNT(*) FROM notification_settings WHERE code = ?", [$ns['code']]);
                    if (!$exists) {
                        // Link to the email template we just inserted
                        $templateId = dbFetchValue("SELECT id FROM email_templates WHERE code = ?", [$ns['code']]);
                        dbInsert(
                            "INSERT INTO notification_settings (code, name, description, email_enabled, email_template_id, created_at, updated_at)
                             VALUES (?, ?, ?, 1, ?, NOW(), NOW())",
                            [$ns['code'], $ns['name'], $ns['description'], $templateId]
                        );
                    }
                }

                // Insert default settings
                $defaultSettings = [
                    'citizen_cert_notify_enabled'  => '0',
                    'citizen_cert_notify_3months'  => '1',
                    'citizen_cert_notify_1month'   => '1',
                    'citizen_cert_notify_1week'    => '1',
                    'citizen_cert_notify_expired'  => '1',
                ];
                foreach ($defaultSettings as $key => $val) {
                    $exists = dbFetchValue("SELECT COUNT(*) FROM settings WHERE setting_key = ?", [$key]);
                    if (!$exists) {
                        dbInsert("INSERT INTO settings (setting_key, setting_value, created_at, updated_at) VALUES (?, ?, NOW(), NOW())", [$key, $val]);
                    }
                }
            },
        ],

        [
            'version'     => 34,
            'description' => 'Re-style all plain email templates to match styled format',
            'up' => function () {
                // Helper functions for styled email layout
                $wrap = function($headerBg, $icon, $title, $body) {
                    return '<div style="background:#eef2f7;padding:28px 0 40px;font-family:Helvetica Neue,Arial,sans-serif;"><div style="max-width:600px;margin:0 auto;"><div style="background:'.$headerBg.';padding:30px 40px 26px;border-radius:12px 12px 0 0;text-align:center;">{{logo_html}}<p style="color:rgba(255,255,255,0.7);font-size:11px;letter-spacing:2px;text-transform:uppercase;margin:0 0 8px;">{{app_name}}</p><div style="font-size:36px;line-height:1;margin:0 0 8px;">'.$icon.'</div><h1 style="color:#fff;margin:0;font-size:23px;font-weight:700;line-height:1.3;">'.$title.'</h1></div><div style="background:#fff;padding:36px 40px 40px;border-radius:0 0 12px 12px;box-shadow:0 4px 20px rgba(0,0,0,0.07);">'.$body.'</div><div style="text-align:center;padding:18px 0 0;color:#9ca3af;font-size:12px;line-height:1.9;"><p style="margin:0;"><strong style="color:#6b7280;">{{app_name}}</strong> &bull; Σύστημα Διαχείρισης Εθελοντών</p><p style="margin:0;">Αυτό το μήνυμα στάλθηκε αυτόματα από το σύστημα.</p></div></div></div>';
                };
                $info = function($borderColor, $rows) {
                    $html = '<div style="background:#f9fafb;border-left:4px solid '.$borderColor.';padding:2px 20px;border-radius:0 8px 8px 0;margin:20px 0;">';
                    $last = count($rows) - 1;
                    foreach ($rows as $i => $row) {
                        $border = $i < $last ? 'border-bottom:1px solid #f3f4f6;' : '';
                        $html .= '<div style="padding:7px 0;font-size:14px;'.$border.'"><span style="color:#9ca3af;display:inline-block;min-width:140px;">'.$row[0].':</span><span style="color:#111827;font-weight:600;">'.$row[1].'</span></div>';
                    }
                    $html .= '</div>';
                    return $html;
                };
                $btn = function($bg, $text, $url = '{{login_url}}') {
                    return '<div style="text-align:center;margin:28px 0 4px;"><a href="'.$url.'" style="background:'.$bg.';color:#ffffff;text-decoration:none;padding:13px 38px;border-radius:8px;font-size:15px;font-weight:700;display:inline-block;letter-spacing:0.3px;">'.$text.'</a></div>';
                };
                $greet = '<h2 style="color:#1f2937;font-size:18px;font-weight:700;margin:0 0 14px;">Γεια σας {{user_name}},</h2>';
                $p = function($text) { return '<p style="color:#4b5563;line-height:1.65;font-size:15px;margin:0 0 14px;">'.$text.'</p>'; };
                $alert = function($bg, $border, $color, $text) { return '<div style="background:'.$bg.';border:1px solid '.$border.';border-radius:8px;padding:14px 20px;margin:20px 0;"><p style="color:'.$color.';font-size:14px;font-weight:600;margin:0;">'.$text.'</p></div>'; };

                $updates = [
                    'task_assigned' => $wrap('#4f46e5', '&#128203;', 'Νέα Ανάθεση Εργασίας',
                        $greet.$p('Σας ανατέθηκε μια νέα εργασία από τον/την <strong>{{assigned_by}}</strong>. Παρακαλούμε ελέγξτε τις λεπτομέρειες παρακάτω.').$info('#4f46e5', [['Εργασία','{{task_title}}'],['Περιγραφή','{{task_description}}'],['Προτεραιότητα','{{task_priority}}'],['Προθεσμία','{{task_deadline}}']]).$p('Μπορείτε να δείτε τις λεπτομέρειες της εργασίας συνδεόμενοι στο σύστημα.').$btn('#4f46e5','Δείτε την Εργασία')),

                    'task_comment' => $wrap('#3b82f6', '&#128172;', 'Νέο Σχόλιο στην Εργασία',
                        $greet.$p('Ο/Η <strong>{{commented_by}}</strong> πρόσθεσε ένα νέο σχόλιο στην εργασία "<strong>{{task_title}}</strong>".').'<div style="background:#f0f4ff;border-left:4px solid #3b82f6;padding:16px 20px;border-radius:0 8px 8px 0;margin:20px 0;"><p style="color:#1e40af;font-size:14px;line-height:1.65;margin:0;font-style:italic;">{{comment}}</p></div>'.$p('Συνδεθείτε στο σύστημα για να δείτε την εργασία και να απαντήσετε.').$btn('#3b82f6','Δείτε την Εργασία')),

                    'task_deadline_reminder' => $wrap('#f97316', '&#9200;', 'Υπενθύμιση Προθεσμίας Εργασίας',
                        $greet.$p('Σας υπενθυμίζουμε ότι η εργασία "<strong>{{task_title}}</strong>" λήγει σε <strong>λιγότερο από 24 ώρες</strong>.').$info('#f97316', [['Εργασία','{{task_title}}'],['Προθεσμία','{{task_deadline}}'],['Κατάσταση','{{task_status}}'],['Πρόοδος','{{task_progress}}%']]).$alert('#fff7ed','#fed7aa','#c2410c','⏰ Η προθεσμία πλησιάζει — παρακαλούμε ολοκληρώστε την εργασία εγκαίρως.').$btn('#f97316','Δείτε την Εργασία')),

                    'task_status_changed' => $wrap('#8b5cf6', '&#128260;', 'Αλλαγή Κατάστασης Εργασίας',
                        $greet.$p('Ο/Η <strong>{{changed_by}}</strong> άλλαξε την κατάσταση της εργασίας "<strong>{{task_title}}</strong>".').'<div style="text-align:center;margin:24px 0;padding:20px;background:#f9fafb;border-radius:8px;"><span style="background:#fef2f2;color:#991b1b;padding:6px 14px;border-radius:6px;font-size:14px;font-weight:600;text-decoration:line-through;">{{old_status}}</span><span style="display:inline-block;margin:0 16px;color:#9ca3af;font-size:20px;">→</span><span style="background:#dcfce7;color:#166534;padding:6px 14px;border-radius:6px;font-size:14px;font-weight:600;">{{new_status}}</span></div>'.$p('Συνδεθείτε στο σύστημα για να δείτε τις λεπτομέρειες.').$btn('#8b5cf6','Δείτε την Εργασία')),

                    'task_subtask_completed' => $wrap('#22c55e', '&#9989;', 'Ολοκλήρωση Υποεργασίας',
                        $greet.$p('Ο/Η <strong>{{completed_by}}</strong> ολοκλήρωσε μια υποεργασία στην εργασία "<strong>{{task_title}}</strong>".').$info('#22c55e', [['Υποεργασία','{{subtask_title}}'],['Εργασία','{{task_title}}']]).$alert('#f0fdf4','#bbf7d0','#166534','✅ Η υποεργασία έχει σημανθεί ως ολοκληρωμένη.').$p('Συνδεθείτε στο σύστημα για να δείτε την πρόοδο της εργασίας.').$btn('#22c55e','Δείτε την Εργασία')),

                    'mission_needs_volunteers' => $wrap('#dc2626', '&#128680;', 'Χρειάζονται Εθελοντές!',
                        $greet.$p('Η αποστολή "<strong>{{mission_title}}</strong>" χρειάζεται <strong>επειγόντως</strong> περισσότερους εθελοντές!').$info('#dc2626', [['Αποστολή','{{mission_title}}'],['Ημερομηνία','{{mission_date}}'],['Θέσεις Ανοιχτές','{{available_spots}}'],['Συνολικές Θέσεις','{{total_spots}}']]).$alert('#fef2f2','#fecaca','#dc2626','🚨 Η βοήθειά σας χρειάζεται! Κάθε εθελοντής κάνει τη διαφορά.').$p('Αν ενδιαφέρεστε να συμμετέχετε, παρακαλούμε συνδεθείτε στο σύστημα και κάντε αίτηση συμμετοχής.').$btn('#dc2626','Δηλώστε Συμμετοχή')),

                    'mission_reminder' => $wrap('#fd7e14', '&#128226;', 'Υπενθύμιση Αποστολής',
                        $greet.$p('Η παρακάτω αποστολή είναι ακόμα <strong>ανοιχτή</strong> και αναζητά εθελοντές:').$info('#fd7e14', [['Αποστολή','{{mission_title}}'],['Περιγραφή','{{mission_description}}']]).$p('Μη χάσετε την ευκαιρία να συμμετέχετε και να κάνετε τη διαφορά!').$btn('#fd7e14','Δείτε την Αποστολή','{{mission_url}}')),

                    'shelf_expiry_reminder' => $wrap('#d97706', '&#128230;', 'Υπενθύμιση Λήξης Ραφιών Αποθήκης',
                        $greet.$p('Υπάρχουν είδη αποθήκης που πλησιάζουν ή έχουν ξεπεράσει την ημερομηνία λήξης τους.').$info('#d97706', [['Ληγμένα Είδη','{{expired_count}}'],['Κοντά σε Λήξη (εντός {{threshold_days}} ημερών)','{{expiring_count}}']]).'<div style="background:#f9fafb;border-radius:8px;padding:16px 20px;margin:20px 0;"><p style="color:#6b7280;font-size:13px;font-weight:600;text-transform:uppercase;letter-spacing:1px;margin:0 0 8px;">Λεπτομέρειες:</p><pre style="background:#fff;border:1px solid #e5e7eb;border-radius:6px;padding:12px 16px;font-size:13px;color:#374151;margin:0;white-space:pre-wrap;word-break:break-word;">{{details}}</pre></div>'.$p('Συνδεθείτε στο σύστημα για να ελέγξετε τα είδη.').$btn('#d97706','Διαχείριση Αποθήκης')),
                ];

                foreach ($updates as $code => $html) {
                    $exists = dbFetchOne("SELECT id FROM email_templates WHERE code = ?", [$code]);
                    if ($exists) {
                        dbExecute("UPDATE email_templates SET body_html = ?, updated_at = NOW() WHERE code = ?", [$html, $code]);
                    }
                }

                // Fix shelf_expiry_reminder name/subject (broken encoding in earlier migration)
                dbExecute("UPDATE email_templates SET name = ?, subject = ?, updated_at = NOW() WHERE code = 'shelf_expiry_reminder'", [
                    'Υπενθύμιση Λήξης Ραφιών Αποθήκης',
                    'Υπενθύμιση: Είδη Αποθήκης Λήγουν ή Έχουν Λήξει'
                ]);
            },
        ],

        [
            'version'     => 35,
            'description' => 'Re-apply all email template styling (retry via PHP parameterized queries)',
            'up' => function () {
                $wrap = function($headerBg, $icon, $title, $body) {
                    return '<div style="background:#eef2f7;padding:28px 0 40px;font-family:Helvetica Neue,Arial,sans-serif;"><div style="max-width:600px;margin:0 auto;"><div style="background:'.$headerBg.';padding:30px 40px 26px;border-radius:12px 12px 0 0;text-align:center;">{{logo_html}}<p style="color:rgba(255,255,255,0.7);font-size:11px;letter-spacing:2px;text-transform:uppercase;margin:0 0 8px;">{{app_name}}</p><div style="font-size:36px;line-height:1;margin:0 0 8px;">'.$icon.'</div><h1 style="color:#fff;margin:0;font-size:23px;font-weight:700;line-height:1.3;">'.$title.'</h1></div><div style="background:#fff;padding:36px 40px 40px;border-radius:0 0 12px 12px;box-shadow:0 4px 20px rgba(0,0,0,0.07);">'.$body.'</div><div style="text-align:center;padding:18px 0 0;color:#9ca3af;font-size:12px;line-height:1.9;"><p style="margin:0;"><strong style="color:#6b7280;">{{app_name}}</strong> &bull; Σύστημα Διαχείρισης Εθελοντών</p><p style="margin:0;">Αυτό το μήνυμα στάλθηκε αυτόματα από το σύστημα.</p></div></div></div>';
                };
                $info = function($borderColor, $rows) {
                    $html = '<div style="background:#f9fafb;border-left:4px solid '.$borderColor.';padding:2px 20px;border-radius:0 8px 8px 0;margin:20px 0;">';
                    $last = count($rows) - 1;
                    foreach ($rows as $i => $row) {
                        $border = $i < $last ? 'border-bottom:1px solid #f3f4f6;' : '';
                        $html .= '<div style="padding:7px 0;font-size:14px;'.$border.'"><span style="color:#9ca3af;display:inline-block;min-width:140px;">'.$row[0].':</span><span style="color:#111827;font-weight:600;">'.$row[1].'</span></div>';
                    }
                    $html .= '</div>';
                    return $html;
                };
                $btn = function($bg, $text, $url = '{{login_url}}') {
                    return '<div style="text-align:center;margin:28px 0 4px;"><a href="'.$url.'" style="background:'.$bg.';color:#ffffff;text-decoration:none;padding:13px 38px;border-radius:8px;font-size:15px;font-weight:700;display:inline-block;letter-spacing:0.3px;">'.$text.'</a></div>';
                };
                $greet = '<h2 style="color:#1f2937;font-size:18px;font-weight:700;margin:0 0 14px;">Γεια σας {{user_name}},</h2>';
                $citizenGreet = '<h2 style="color:#1f2937;font-size:18px;font-weight:700;margin:0 0 14px;">Αγαπητέ/ή {{citizen_name}},</h2>';
                $p = function($text) { return '<p style="color:#4b5563;line-height:1.65;font-size:15px;margin:0 0 14px;">'.$text.'</p>'; };
                $alert = function($bg, $border, $color, $text) { return '<div style="background:'.$bg.';border:1px solid '.$border.';border-radius:8px;padding:14px 20px;margin:20px 0;"><p style="color:'.$color.';font-size:14px;font-weight:600;margin:0;">'.$text.'</p></div>'; };
                $certBlock = function($borderColor) {
                    return '<div style="background:#f9fafb;border-left:4px solid '.$borderColor.';padding:2px 20px;border-radius:0 8px 8px 0;margin:20px 0;"><div style="padding:7px 0;font-size:14px;border-bottom:1px solid #f3f4f6;"><span style="color:#9ca3af;display:inline-block;min-width:140px;">Πιστοποιητικό:</span><span style="color:#111827;font-weight:600;">{{certificate_type}}</span></div><div style="padding:7px 0;font-size:14px;border-bottom:1px solid #f3f4f6;"><span style="color:#9ca3af;display:inline-block;min-width:140px;">Ημ. Λήξης:</span><span style="color:#111827;font-weight:600;">{{expiry_date}}</span></div><div style="padding:7px 0;font-size:14px;"><span style="color:#9ca3af;display:inline-block;min-width:140px;">Υπόλοιπες Ημέρες:</span><span style="color:#111827;font-weight:600;">{{days_remaining}} ημέρες</span></div></div>';
                };

                // All 13 templates: code => [name, subject, body_html]
                $updates = [
                    'task_assigned' => ['Ανάθεση Εργασίας', 'Νέα Εργασία: {{task_title}}',
                        $wrap('#4f46e5', '&#128203;', 'Νέα Ανάθεση Εργασίας',
                            $greet.$p('Σας ανατέθηκε μια νέα εργασία από τον/την <strong>{{assigned_by}}</strong>. Παρακαλούμε ελέγξτε τις λεπτομέρειες παρακάτω.').$info('#4f46e5', [['Εργασία','{{task_title}}'],['Περιγραφή','{{task_description}}'],['Προτεραιότητα','{{task_priority}}'],['Προθεσμία','{{task_deadline}}']]).$p('Μπορείτε να δείτε τις λεπτομέρειες της εργασίας συνδεόμενοι στο σύστημα.').$btn('#4f46e5','Δείτε την Εργασία'))],

                    'task_comment' => ['Σχόλιο σε Εργασία', 'Νέο Σχόλιο στην Εργασία: {{task_title}}',
                        $wrap('#3b82f6', '&#128172;', 'Νέο Σχόλιο στην Εργασία',
                            $greet.$p('Ο/Η <strong>{{commented_by}}</strong> πρόσθεσε ένα νέο σχόλιο στην εργασία "<strong>{{task_title}}</strong>".').'<div style="background:#f0f4ff;border-left:4px solid #3b82f6;padding:16px 20px;border-radius:0 8px 8px 0;margin:20px 0;"><p style="color:#1e40af;font-size:14px;line-height:1.65;margin:0;font-style:italic;">{{comment}}</p></div>'.$p('Συνδεθείτε στο σύστημα για να δείτε την εργασία και να απαντήσετε.').$btn('#3b82f6','Δείτε την Εργασία'))],

                    'task_deadline_reminder' => ['Υπενθύμιση Προθεσμίας', 'Υπενθύμιση: Η εργασία {{task_title}} λήγει σύντομα',
                        $wrap('#f97316', '&#9200;', 'Υπενθύμιση Προθεσμίας Εργασίας',
                            $greet.$p('Σας υπενθυμίζουμε ότι η εργασία "<strong>{{task_title}}</strong>" λήγει σε <strong>λιγότερο από 24 ώρες</strong>.').$info('#f97316', [['Εργασία','{{task_title}}'],['Προθεσμία','{{task_deadline}}'],['Κατάσταση','{{task_status}}'],['Πρόοδος','{{task_progress}}%']]).$alert('#fff7ed','#fed7aa','#c2410c','⏰ Η προθεσμία πλησιάζει — παρακαλούμε ολοκληρώστε την εργασία εγκαίρως.').$btn('#f97316','Δείτε την Εργασία'))],

                    'task_status_changed' => ['Αλλαγή Κατάστασης Εργασίας', 'Αλλαγή Κατάστασης: {{task_title}}',
                        $wrap('#8b5cf6', '&#128260;', 'Αλλαγή Κατάστασης Εργασίας',
                            $greet.$p('Ο/Η <strong>{{changed_by}}</strong> άλλαξε την κατάσταση της εργασίας "<strong>{{task_title}}</strong>".').'<div style="text-align:center;margin:24px 0;padding:20px;background:#f9fafb;border-radius:8px;"><span style="background:#fef2f2;color:#991b1b;padding:6px 14px;border-radius:6px;font-size:14px;font-weight:600;text-decoration:line-through;">{{old_status}}</span><span style="display:inline-block;margin:0 16px;color:#9ca3af;font-size:20px;">→</span><span style="background:#dcfce7;color:#166534;padding:6px 14px;border-radius:6px;font-size:14px;font-weight:600;">{{new_status}}</span></div>'.$p('Συνδεθείτε στο σύστημα για να δείτε τις λεπτομέρειες.').$btn('#8b5cf6','Δείτε την Εργασία'))],

                    'task_subtask_completed' => ['Ολοκλήρωση Υποεργασίας', 'Ολοκληρώθηκε Υποεργασία στην: {{task_title}}',
                        $wrap('#22c55e', '&#9989;', 'Ολοκλήρωση Υποεργασίας',
                            $greet.$p('Ο/Η <strong>{{completed_by}}</strong> ολοκλήρωσε μια υποεργασία στην εργασία "<strong>{{task_title}}</strong>".').$info('#22c55e', [['Υποεργασία','{{subtask_title}}'],['Εργασία','{{task_title}}']]).$alert('#f0fdf4','#bbf7d0','#166534','✅ Η υποεργασία έχει σημανθεί ως ολοκληρωμένη.').$p('Συνδεθείτε στο σύστημα για να δείτε την πρόοδο της εργασίας.').$btn('#22c55e','Δείτε την Εργασία'))],

                    'mission_needs_volunteers' => ['Αποστολή Χρειάζεται Εθελοντές', 'Επείγον: Χρειάζονται Εθελοντές - {{mission_title}}',
                        $wrap('#dc2626', '&#128680;', 'Χρειάζονται Εθελοντές!',
                            $greet.$p('Η αποστολή "<strong>{{mission_title}}</strong>" χρειάζεται <strong>επειγόντως</strong> περισσότερους εθελοντές!').$info('#dc2626', [['Αποστολή','{{mission_title}}'],['Ημερομηνία','{{mission_date}}'],['Θέσεις Ανοιχτές','{{available_spots}}'],['Συνολικές Θέσεις','{{total_spots}}']]).$alert('#fef2f2','#fecaca','#dc2626','🚨 Η βοήθειά σας χρειάζεται! Κάθε εθελοντής κάνει τη διαφορά.').$p('Αν ενδιαφέρεστε να συμμετέχετε, παρακαλούμε συνδεθείτε στο σύστημα και κάντε αίτηση συμμετοχής.').$btn('#dc2626','Δηλώστε Συμμετοχή'))],

                    'mission_reminder' => ['Υπενθύμιση Αποστολής', 'Υπενθύμιση Αποστολής: {{mission_title}}',
                        $wrap('#fd7e14', '&#128226;', 'Υπενθύμιση Αποστολής',
                            $greet.$p('Η παρακάτω αποστολή είναι ακόμα <strong>ανοιχτή</strong> και αναζητά εθελοντές:').$info('#fd7e14', [['Αποστολή','{{mission_title}}'],['Περιγραφή','{{mission_description}}']]).$p('Μη χάσετε την ευκαιρία να συμμετέχετε και να κάνετε τη διαφορά!').$btn('#fd7e14','Δείτε την Αποστολή','{{mission_url}}'))],

                    'shelf_expiry_reminder' => ['Υπενθύμιση Λήξης Ραφιών Αποθήκης', 'Υπενθύμιση: Είδη Αποθήκης Λήγουν ή Έχουν Λήξει',
                        $wrap('#d97706', '&#128230;', 'Υπενθύμιση Λήξης Ραφιών Αποθήκης',
                            $greet.$p('Υπάρχουν είδη αποθήκης που πλησιάζουν ή έχουν ξεπεράσει την ημερομηνία λήξης τους.').$info('#d97706', [['Ληγμένα Είδη','{{expired_count}}'],['Κοντά σε Λήξη (εντός {{threshold_days}} ημερών)','{{expiring_count}}']]).'<div style="background:#f9fafb;border-radius:8px;padding:16px 20px;margin:20px 0;"><p style="color:#6b7280;font-size:13px;font-weight:600;text-transform:uppercase;letter-spacing:1px;margin:0 0 8px;">Λεπτομέρειες:</p><pre style="background:#fff;border:1px solid #e5e7eb;border-radius:6px;padding:12px 16px;font-size:13px;color:#374151;margin:0;white-space:pre-wrap;word-break:break-word;">{{details}}</pre></div>'.$p('Συνδεθείτε στο σύστημα για να ελέγξετε τα είδη.').$btn('#d97706','Διαχείριση Αποθήκης'))],

                    'citizen_cert_expiry_3months' => ['Λήξη Πιστοποιητικού Πολίτη (3 μήνες)', 'Υπενθύμιση: Το πιστοποιητικό σας λήγει σε 3 μήνες',
                        $wrap('#0ea5e9', '&#128203;', 'Υπενθύμιση Λήξης Πιστοποιητικού',
                            $citizenGreet.$p('Σας ενημερώνουμε ότι το πιστοποιητικό σας πλησιάζει στην ημερομηνία λήξης του. Παρακαλούμε ξεκινήστε τη διαδικασία ανανέωσής του εγκαίρως.').$certBlock('#0ea5e9').$p('Διαθέτετε ακόμη αρκετό χρόνο για να ολοκληρώσετε τη διαδικασία ανανέωσης χωρίς πρόβλημα.').$btn('#0ea5e9','Σύνδεση στο Σύστημα'))],

                    'citizen_cert_expiry_1month' => ['Λήξη Πιστοποιητικού Πολίτη (1 μήνα)', 'Υπενθύμιση: Το πιστοποιητικό σας λήγει σε 1 μήνα',
                        $wrap('#eab308', '&#9888;', 'Το Πιστοποιητικό σας Λήγει Σύντομα',
                            $citizenGreet.$p('Το πιστοποιητικό σας λήγει <strong>σε λιγότερο από 1 μήνα</strong>. Παρακαλούμε φροντίστε άμεσα για την ανανέωσή του.').$certBlock('#eab308').$p('Μην αφήσετε το πιστοποιητικό σας να λήξει — ξεκινήστε τη διαδικασία ανανέωσης σήμερα.').$btn('#eab308','Σύνδεση στο Σύστημα'))],

                    'citizen_cert_expiry_1week' => ['Λήξη Πιστοποιητικού Πολίτη (1 εβδομάδα)', '⚠ Επείγον: Το πιστοποιητικό σας λήγει σε 1 εβδομάδα',
                        $wrap('#f97316', '&#9888;', 'Επείγουσα Υπενθύμιση — Λήξη Πιστοποιητικού',
                            $citizenGreet.$p('<strong style="color:#dc2626;">Επείγουσα ειδοποίηση:</strong> Το πιστοποιητικό σας λήγει <strong>σε μόλις λίγες ημέρες</strong>. Απαιτείται άμεση ενέργεια για ανανέωση.').$certBlock('#f97316').$alert('#fef2f2','#fecaca','#dc2626','<span style="font-size:16px;">⚠</span> Αν δεν ανανεωθεί εγκαίρως, το πιστοποιητικό σας θα λήξει και δεν θα είναι έγκυρο.').$btn('#f97316','Σύνδεση στο Σύστημα'))],

                    'citizen_cert_expiry_expired' => ['Λήξη Πιστοποιητικού Πολίτη (Ληγμένο)', '🔴 Το πιστοποιητικό σας έχει λήξει',
                        $wrap('#dc2626', '&#10060;', 'Το Πιστοποιητικό σας Έληξε',
                            $citizenGreet.$p('Σας ενημερώνουμε ότι το πιστοποιητικό σας <strong style="color:#dc2626;">έχει λήξει</strong>. Παρακαλούμε φροντίστε για την <strong>άμεση ανανέωσή</strong> του.').'<div style="background:#f9fafb;border-left:4px solid #dc2626;padding:2px 20px;border-radius:0 8px 8px 0;margin:20px 0;"><div style="padding:7px 0;font-size:14px;border-bottom:1px solid #f3f4f6;"><span style="color:#9ca3af;display:inline-block;min-width:140px;">Πιστοποιητικό:</span><span style="color:#111827;font-weight:600;">{{certificate_type}}</span></div><div style="padding:7px 0;font-size:14px;border-bottom:1px solid #f3f4f6;"><span style="color:#9ca3af;display:inline-block;min-width:140px;">Ημ. Λήξης:</span><span style="color:#dc2626;font-weight:600;">{{expiry_date}}</span></div><div style="padding:7px 0;font-size:14px;"><span style="color:#9ca3af;display:inline-block;min-width:140px;">Κατάσταση:</span><span style="background:#dc2626;color:#fff;padding:2px 10px;border-radius:4px;font-size:13px;font-weight:600;">ΛΗΓΜΕΝΟ</span></div></div>'.$alert('#fef2f2','#fecaca','#dc2626','<span style="font-size:16px;">🔴</span> Το πιστοποιητικό δεν είναι πλέον σε ισχύ. Επικοινωνήστε μαζί μας ή ξεκινήστε τη διαδικασία ανανέωσης.').$btn('#dc2626','Σύνδεση στο Σύστημα'))],

                    'certificate_expiry_reminder' => ['Υπενθύμιση Λήξης Πιστοποιητικού', 'Υπενθύμιση: Το πιστοποιητικό σας «{{certificate_type}}» λήγει σε {{days_remaining}} ημέρες',
                        $wrap('#eab308', '&#128203;', 'Υπενθύμιση Λήξης Πιστοποιητικού',
                            '<h2 style="color:#1f2937;font-size:18px;font-weight:700;margin:0 0 14px;">Αγαπητέ/ή {{user_name}},</h2>'.$p('Σας ενημερώνουμε ότι το πιστοποιητικό σας πλησιάζει στην ημερομηνία λήξης του.').$certBlock('#eab308').$p('Παρακαλούμε φροντίστε για την ανανέωσή του εγκαίρως.').$btn('#eab308','Σύνδεση στο Σύστημα'))],
                ];

                foreach ($updates as $code => $data) {
                    $exists = dbFetchOne("SELECT id FROM email_templates WHERE code = ?", [$code]);
                    if ($exists) {
                        dbExecute("UPDATE email_templates SET body_html = ?, name = ?, subject = ?, updated_at = NOW() WHERE code = ?", [$data[2], $data[0], $data[1], $code]);
                    }
                }
            },
        ],

        [
            'version'     => 36,
            'description' => 'Ensure audit_logs table exists',
            'up' => function () {
                $tableExists = (bool) dbFetchOne(
                    "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_logs'"
                );
                if (!$tableExists) {
                    dbExecute("CREATE TABLE audit_logs (
                        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        user_id INT UNSIGNED NULL,
                        action VARCHAR(50) NOT NULL,
                        table_name VARCHAR(100) NULL,
                        record_id INT UNSIGNED NULL,
                        notes TEXT NULL,
                        ip_address VARCHAR(45) NULL,
                        user_agent TEXT NULL,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_audit_user (user_id),
                        INDEX idx_audit_table (table_name, record_id),
                        INDEX idx_audit_action (action),
                        INDEX idx_audit_created (created_at)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                }
            },
        ],

        [
            'version'     => 36,
            'description' => 'Ensure audit_logs table exists',
            'up' => function () {
                $tableExists = (bool) dbFetchOne(
                    "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_logs'"
                );
                if (!$tableExists) {
                    dbExecute("CREATE TABLE audit_logs (
                        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        user_id INT UNSIGNED NULL,
                        action VARCHAR(50) NOT NULL,
                        table_name VARCHAR(100) NULL,
                        record_id INT UNSIGNED NULL,
                        notes TEXT NULL,
                        ip_address VARCHAR(45) NULL,
                        user_agent TEXT NULL,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_audit_user (user_id),
                        INDEX idx_audit_table (table_name, record_id),
                        INDEX idx_audit_action (action),
                        INDEX idx_audit_created (created_at)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                }
            },
        ],

        [
            'version'     => 37,
            'description' => 'Add missing performance indexes: deleted_at, total_points, attended, expiry, achievements',
            'up' => function () {
                // Helper: add index only if not already present (safe to run multiple times)
                $addIndex = function (string $table, string $indexName, string $columns) {
                    // First check the table exists (guards against optional tables)
                    $tblExists = dbFetchOne(
                        "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
                        [$table]
                    );
                    if (!$tblExists) return;
                    $exists = dbFetchOne(
                        "SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
                         WHERE TABLE_SCHEMA = DATABASE()
                           AND TABLE_NAME   = ?
                           AND INDEX_NAME   = ?",
                        [$table, $indexName]
                    );
                    if (!$exists) {
                        dbExecute("ALTER TABLE `{$table}` ADD INDEX `{$indexName}` ({$columns})");
                    }
                };

                // ── missions ────────────────────────────────────────────────
                // deleted_at: every query has `deleted_at IS NULL` — no index before
                $addIndex('missions', 'idx_missions_deleted_at', 'deleted_at');
                // Covering composite: status + deleted_at + start_datetime
                // covers: WHERE status=? AND deleted_at IS NULL ORDER BY start_datetime
                $addIndex('missions', 'idx_missions_status_del_start', 'status, deleted_at, start_datetime');
                // Urgent missions filter: WHERE is_urgent=1 AND status='OPEN'
                $addIndex('missions', 'idx_missions_urgent', 'is_urgent, status');

                // ── users ───────────────────────────────────────────────────
                // deleted_at: every user listing has `deleted_at IS NULL`
                $addIndex('users', 'idx_users_deleted_at', 'deleted_at');
                // leaderboard ORDER BY total_points DESC
                $addIndex('users', 'idx_users_total_points', 'total_points');
                // Covering composite for volunteer listings (role + is_active + deleted_at)
                $addIndex('users', 'idx_users_role_active_del', 'role, is_active, deleted_at');
                // Admin pending-approval queue: WHERE approval_status='PENDING'
                $addIndex('users', 'idx_users_approval_status', 'approval_status');
                // Newsletter recipient query: WHERE newsletter_unsubscribed=0 AND is_active=1 AND deleted_at IS NULL
                $addIndex('users', 'idx_users_newsletter', 'newsletter_unsubscribed, is_active, deleted_at');

                // ── participation_requests ───────────────────────────────────
                // attended flag: presence in all attendance reports / points calculation
                $addIndex('participation_requests', 'idx_pr_attended', 'attended, shift_id');
                // points_awarded + attended: "which attended rows haven't got points yet"
                $addIndex('participation_requests', 'idx_pr_points_attended', 'points_awarded, attended');
                // Per-volunteer attendance history
                $addIndex('participation_requests', 'idx_pr_vol_attended', 'volunteer_id, attended');

                // ── shifts ──────────────────────────────────────────────────
                // end_time: past-shift queries (WHERE end_time < NOW()), cron, dashboard
                $addIndex('shifts', 'idx_shifts_end_time', 'end_time');
                // Covering range composite for calendar API date-range scan
                $addIndex('shifts', 'idx_shifts_time_mission', 'start_time, end_time, mission_id');

                // ── volunteer_certificates ───────────────────────────────────
                // Cron expiry: WHERE expiry_date BETWEEN ? AND ? AND reminder_sent_30 = 0
                $addIndex('volunteer_certificates', 'idx_vc_expiry', 'expiry_date');
                $addIndex('volunteer_certificates', 'idx_vc_expiry_reminder', 'expiry_date, reminder_sent_30, reminder_sent_7');

                // ── user_achievements ───────────────────────────────────────
                // Achievement notification cron: WHERE notified = 0
                $addIndex('user_achievements', 'idx_ua_notified', 'notified, earned_at');

                // ── volunteer_points ────────────────────────────────────────
                // Covering index for leaderboard SUM: user_id, points, created_at
                $addIndex('volunteer_points', 'idx_vp_user_points', 'user_id, points, created_at');

                // ── notifications ───────────────────────────────────────────
                // Extend to 3-column: user_id + read_at + created_at (unread count + date sort)
                $addIndex('notifications', 'idx_notif_user_read_created', 'user_id, read_at, created_at');

                // ── audit_logs ──────────────────────────────────────────────
                // Combined filter for audit viewer: action + table_name + created_at
                $addIndex('audit_logs', 'idx_audit_action_table_date', 'action, table_name, created_at');
            },
        ],

        [
            'version'     => 38,
            'description' => 'Create complaints table + add missing priority/created_at/user indexes',
            'up' => function () {
                // ── 1. Create complaints table if missing ──────────────────────────
                $tableExists = (bool) dbFetchOne(
                    "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaints'"
                );
                if (!$tableExists) {
                    dbExecute(
                        "CREATE TABLE `complaints` (
                            `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                            `user_id`        INT UNSIGNED NOT NULL,
                            `mission_id`     INT UNSIGNED NULL,
                            `category`       ENUM('MISSION','EQUIPMENT','BEHAVIOR','ADMIN','OTHER') NOT NULL DEFAULT 'OTHER',
                            `priority`       ENUM('LOW','MEDIUM','HIGH') NOT NULL DEFAULT 'MEDIUM',
                            `subject`        VARCHAR(255) NOT NULL,
                            `body`           TEXT NOT NULL,
                            `status`         ENUM('NEW','IN_REVIEW','RESOLVED','REJECTED') NOT NULL DEFAULT 'NEW',
                            `admin_response` TEXT NULL,
                            `responded_by`   INT UNSIGNED NULL,
                            `responded_at`   DATETIME NULL,
                            `created_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                            `updated_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                            FOREIGN KEY (`user_id`)       REFERENCES `users`(`id`)    ON DELETE CASCADE,
                            FOREIGN KEY (`mission_id`)    REFERENCES `missions`(`id`) ON DELETE SET NULL,
                            FOREIGN KEY (`responded_by`)  REFERENCES `users`(`id`)   ON DELETE SET NULL,
                            INDEX `idx_complaint_user`         (`user_id`),
                            INDEX `idx_complaint_status`       (`status`),
                            INDEX `idx_complaint_category`     (`category`),
                            INDEX `idx_complaint_mission`      (`mission_id`),
                            INDEX `idx_complaint_priority`     (`priority`),
                            INDEX `idx_complaint_created`      (`created_at`),
                            INDEX `idx_complaint_user_created` (`user_id`, `created_at`)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
                    );
                } else {
                    // Table exists (e.g. via migrate_complaints.php) — add new indexes only
                    $addIdx = function (string $idxName, string $cols) {
                        $exists = dbFetchOne(
                            "SELECT INDEX_NAME FROM INFORMATION_SCHEMA.STATISTICS
                             WHERE TABLE_SCHEMA = DATABASE()
                               AND TABLE_NAME   = 'complaints'
                               AND INDEX_NAME   = ?",
                            [$idxName]
                        );
                        if (!$exists) {
                            dbExecute("ALTER TABLE `complaints` ADD INDEX `{$idxName}` ({$cols})");
                        }
                    };
                    $addIdx('idx_complaint_priority',     'priority');
                    $addIdx('idx_complaint_created',      'created_at');
                    $addIdx('idx_complaint_user_created', 'user_id, created_at');
                }

                // ── 2. Email templates (safe: ON DUPLICATE KEY touches nothing) ────
                $tplHtml = [
                    'complaint_submitted' => '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;"><div style="background:#dc2626;color:white;padding:20px;text-align:center;"><h1>&#9888; Νέο Παράπονο Εθελοντή</h1></div><div style="padding:30px;background:#fff;"><h2>Γεια σας {{admin_name}},</h2><p>Ο/Η εθελοντής <strong>{{volunteer_name}}</strong> υπέβαλε νέο παράπονο.</p><ul><li><strong>Θέμα:</strong> {{complaint_subject}}</li><li><strong>Κατηγορία:</strong> {{complaint_category}}</li><li><strong>Προτεραιότητα:</strong> {{complaint_priority}}</li><li><strong>Αποστολή:</strong> {{mission_title}}</li></ul><p>{{complaint_body}}</p><p style="text-align:center;"><a href="{{complaint_url}}" style="background:#dc2626;color:white;padding:12px 28px;text-decoration:none;border-radius:5px;">Δείτε το Παράπονο</a></p></div><div style="padding:12px;background:#f8f9fa;text-align:center;font-size:12px;color:#666;">{{app_name}}</div></div>',
                    'complaint_response'  => '<div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;"><div style="background:#16a34a;color:white;padding:20px;text-align:center;"><h1>&#128172; Απάντηση στο Παράπονό σας</h1></div><div style="padding:30px;background:#fff;"><h2>Γεια σας {{user_name}},</h2><p>Λάβαμε το παράπονό σας και σας αποστέλλουμε την απάντησή μας.</p><ul><li><strong>Θέμα:</strong> {{complaint_subject}}</li><li><strong>Νέα Κατάσταση:</strong> {{complaint_status}}</li><li><strong>Απάντηση από:</strong> {{responder_name}}</li></ul><blockquote style="background:#f0fdf4;border-left:4px solid #16a34a;padding:12px 16px;">{{admin_response}}</blockquote><p style="text-align:center;"><a href="{{complaint_url}}" style="background:#16a34a;color:white;padding:12px 28px;text-decoration:none;border-radius:5px;">Δείτε το Παράπονο</a></p></div><div style="padding:12px;background:#f8f9fa;text-align:center;font-size:12px;color:#666;">{{app_name}}</div></div>',
                ];
                $tplMeta = [
                    'complaint_submitted' => [
                        'name'    => 'Νέο Παράπονο (Admin)',
                        'subject' => 'Νέο παράπονο εθελοντή - {{complaint_subject}}',
                        'desc'    => 'Αποστέλλεται στους διαχειριστές όταν υποβάλλεται νέο παράπονο εθελοντή',
                        'vars'    => '{{app_name}}, {{admin_name}}, {{volunteer_name}}, {{complaint_subject}}, {{complaint_category}}, {{complaint_priority}}, {{complaint_body}}, {{mission_title}}, {{complaint_url}}',
                    ],
                    'complaint_response'  => [
                        'name'    => 'Απάντηση Παραπόνου',
                        'subject' => 'Απάντηση στο παράπονό σας: {{complaint_subject}}',
                        'desc'    => 'Αποστέλλεται στον εθελοντή όταν ο διαχειριστής απαντήσει στο παράπονό του',
                        'vars'    => '{{app_name}}, {{user_name}}, {{complaint_subject}}, {{complaint_status}}, {{responder_name}}, {{admin_response}}, {{complaint_url}}',
                    ],
                ];
                foreach ($tplMeta as $code => $meta) {
                    dbExecute(
                        "INSERT INTO email_templates
                            (code, name, subject, body_html, description, available_variables)
                         VALUES (?, ?, ?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE updated_at = updated_at",
                        [$code, $meta['name'], $meta['subject'], $tplHtml[$code], $meta['desc'], $meta['vars']]
                    );
                }

                // ── 3. Notification settings rows ─────────────────────────────────
                $notifCodes = [
                    'complaint_submitted' => 'Νέο Παράπονο (Admin)',
                    'complaint_response'  => 'Απάντηση Παραπόνου',
                ];
                foreach ($notifCodes as $code => $name) {
                    $tplId = dbFetchValue(
                        "SELECT id FROM email_templates WHERE code = ?", [$code]
                    );
                    dbExecute(
                        "INSERT INTO notification_settings (code, name, email_enabled, email_template_id)
                         VALUES (?, ?, 1, ?)
                         ON DUPLICATE KEY UPDATE updated_at = updated_at",
                        [$code, $name, $tplId]
                    );
                }
            },
        ],

        [
            'version'     => 39,
            'description' => 'Shift swap requests: table, email templates, notification settings',
            'up' => function () {
                // ── 1. Create shift_swap_requests table ──────────────────────────────
                dbExecute(
                    "CREATE TABLE IF NOT EXISTS shift_swap_requests (
                        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        participation_id  INT UNSIGNED NOT NULL,
                        from_volunteer_id INT UNSIGNED NOT NULL,
                        to_volunteer_id   INT UNSIGNED NOT NULL,
                        shift_id          INT UNSIGNED NOT NULL,
                        message           TEXT NULL,
                        status ENUM('PENDING_RESPONSE','ACCEPTED','DECLINED','APPROVED','REJECTED','CANCELED')
                               NOT NULL DEFAULT 'PENDING_RESPONSE',
                        to_volunteer_responded_at DATETIME NULL,
                        decided_by INT UNSIGNED NULL,
                        decided_at DATETIME NULL,
                        admin_notes TEXT NULL,
                        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        INDEX idx_ssr_shift        (shift_id),
                        INDEX idx_ssr_from_vol     (from_volunteer_id),
                        INDEX idx_ssr_to_vol       (to_volunteer_id),
                        INDEX idx_ssr_status       (status),
                        FOREIGN KEY (participation_id)  REFERENCES participation_requests(id) ON DELETE CASCADE,
                        FOREIGN KEY (from_volunteer_id) REFERENCES users(id) ON DELETE CASCADE,
                        FOREIGN KEY (to_volunteer_id)   REFERENCES users(id) ON DELETE CASCADE,
                        FOREIGN KEY (shift_id)          REFERENCES shifts(id) ON DELETE CASCADE,
                        FOREIGN KEY (decided_by)        REFERENCES users(id) ON DELETE SET NULL
                    )"
                );

                // ── 2. Email templates ────────────────────────────────────────────────
                $templates = [
                    'shift_swap_requested' => [
                        'name'    => 'Αίτημα Αντικατάστασης Βάρδιας',
                        'subject' => 'Αίτημα αντικατάστασης για αποστολή {{mission_title}}',
                        'desc'    => 'Αποστέλλεται στον εθελοντή που ζητήθηκε να καλύψει τη βάρδια',
                        'vars'    => '{{user_name}}, {{requester_name}}, {{mission_title}}, {{shift_date}}, {{shift_time}}, {{message}}',
                        'html'    => '<div style="font-family:Arial,sans-serif"><div style="background:#8e44ad;color:#fff;padding:16px 20px"><h2 style="margin:0">&#128257; Αίτημα Αντικατάστασης</h2></div><div style="padding:20px"><p>Γεια, <strong>{{user_name}}</strong>!</p><p>Ο/Η <strong>{{requester_name}}</strong> σας ζητά να τον/την αντικαταστήσετε στη βάρδια:</p><ul><li><strong>Αποστολή:</strong> {{mission_title}}</li><li><strong>Ημ/νία:</strong> {{shift_date}}</li><li><strong>Ώρα:</strong> {{shift_time}}</li></ul><p>Συνδεθείτε για να αποδεχτείτε ή να αρνηθείτε το αίτημα.</p></div></div>',
                    ],
                    'shift_swap_accepted' => [
                        'name'    => 'Αποδοχή Αιτήματος Αντικατάστασης',
                        'subject' => 'Ο/Η {{replacement_name}} αποδέχτηκε το αίτημα αντικατάστασης',
                        'desc'    => 'Αποστέλλεται στον αιτούντα όταν ο αντικατάστατης αποδεχτεί',
                        'vars'    => '{{user_name}}, {{replacement_name}}, {{mission_title}}, {{shift_date}}, {{shift_time}}',
                        'html'    => '<div style="font-family:Arial,sans-serif"><div style="background:#27ae60;color:#fff;padding:16px 20px"><h2 style="margin:0">&#10003; Αποδοχή Αντικατάστασης</h2></div><div style="padding:20px"><p>Γεια, <strong>{{user_name}}</strong>!</p><p>Ο/Η <strong>{{replacement_name}}</strong> αποδέχτηκε το αίτημά σας για αντικατάσταση στη βάρδια:</p><ul><li><strong>Αποστολή:</strong> {{mission_title}}</li><li><strong>Ημ/νία:</strong> {{shift_date}}</li><li><strong>Ώρα:</strong> {{shift_time}}</li></ul><p>Αναμένεται η τελική έγκριση από τον διαχειριστή.</p></div></div>',
                    ],
                    'shift_swap_approved' => [
                        'name'    => 'Έγκριση Αντικατάστασης Βάρδιας',
                        'subject' => 'Η αντικατάσταση για {{mission_title}} εγκρίθηκε',
                        'desc'    => 'Αποστέλλεται και στους δύο εθελοντές όταν ο διαχειριστής εγκρίνει',
                        'vars'    => '{{user_name}}, {{replacement_name}}, {{mission_title}}, {{shift_date}}, {{shift_time}}',
                        'html'    => '<div style="font-family:Arial,sans-serif"><div style="background:#2980b9;color:#fff;padding:16px 20px"><h2 style="margin:0">&#9989; Αντικατάσταση Εγκρίθηκε</h2></div><div style="padding:20px"><p>Γεια, <strong>{{user_name}}</strong>!</p><p>Η αντικατάσταση για τη βάρδια εγκρίθηκε από τον διαχειριστή:</p><ul><li><strong>Αποστολή:</strong> {{mission_title}}</li><li><strong>Ημ/νία:</strong> {{shift_date}}</li><li><strong>Ώρα:</strong> {{shift_time}}</li></ul></div></div>',
                    ],
                ];

                foreach ($templates as $code => $meta) {
                    dbExecute(
                        "INSERT INTO email_templates
                            (code, name, subject, body_html, description, available_variables)
                         VALUES (?, ?, ?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE
                            name = VALUES(name), subject = VALUES(subject),
                            body_html = VALUES(body_html), description = VALUES(description),
                            available_variables = VALUES(available_variables)",
                        [$code, $meta['name'], $meta['subject'], $meta['html'], $meta['desc'], $meta['vars']]
                    );
                }

                // ── 3. Notification settings rows ─────────────────────────────────
                $notifSettings = [
                    'shift_swap_requested' => 'Αίτημα αντικατάστασης βάρδιας (προς αντικατάστατη)',
                    'shift_swap_accepted'  => 'Αποδοχή αιτήματος αντικατάστασης (προς αιτούντα)',
                    'shift_swap_approved'  => 'Έγκριση αντικατάστασης (και στους δύο)',
                ];
                foreach ($notifSettings as $code => $name) {
                    $tplId = dbFetchValue(
                        "SELECT id FROM email_templates WHERE code = ?", [$code]
                    );
                    dbExecute(
                        "INSERT INTO notification_settings (code, name, email_enabled, email_template_id)
                         VALUES (?, ?, 1, ?)
                         ON DUPLICATE KEY UPDATE name = VALUES(name), email_template_id = VALUES(email_template_id)",
                        [$code, $name, $tplId]
                    );
                }
            },
        ],

        // v40 — force-fix Greek encoding in shift swap email templates (v39 used \u escapes in single-quoted strings)
        [
            'version'     => 40,
            'description' => 'Fix Greek encoding in shift swap email templates',
            'up' => function () {
                $templates = [
                    'shift_swap_requested' => [
                        'name'    => 'Αίτημα Αντικατάστασης Βάρδιας',
                        'subject' => 'Αίτημα αντικατάστασης για αποστολή {{mission_title}}',
                        'desc'    => 'Αποστέλλεται στον εθελοντή που ζητήθηκε να καλύψει τη βάρδια',
                        'vars'    => '{{user_name}}, {{requester_name}}, {{mission_title}}, {{shift_date}}, {{shift_time}}, {{message}}',
                        'html'    => '<div style="background:#eef2f7;padding:28px 0 40px;font-family:Helvetica Neue,Arial,sans-serif;"><div style="max-width:600px;margin:0 auto;"><div style="background:#8e44ad;padding:30px 40px 26px;border-radius:12px 12px 0 0;text-align:center;">{{logo_html}}<p style="color:rgba(255,255,255,0.7);font-size:11px;letter-spacing:2px;text-transform:uppercase;margin:0 0 8px;">{{app_name}}</p><div style="font-size:36px;line-height:1;margin:0 0 8px;">&#128257;</div><h1 style="color:#fff;margin:0;font-size:23px;font-weight:700;line-height:1.3;">Αίτημα Αντικατάστασης Βάρδιας</h1></div><div style="background:#fff;padding:36px 40px 40px;border-radius:0 0 12px 12px;box-shadow:0 4px 20px rgba(0,0,0,0.07);"><h2 style="color:#1f2937;font-size:18px;font-weight:700;margin:0 0 14px;">Γεια σας {{user_name}},</h2><p style="color:#4b5563;line-height:1.65;font-size:15px;margin:0 0 14px;">Ο/Η <strong>{{requester_name}}</strong> σας ζητά να τον/την αντικαταστήσετε στην παρακάτω βάρδια. Συνδεθείτε για να αποδεχτείτε ή να αρνηθείτε το αίτημα.</p><div style="background:#f9fafb;border-left:4px solid #8e44ad;padding:2px 20px;border-radius:0 8px 8px 0;margin:20px 0;"><div style="padding:7px 0;font-size:14px;border-bottom:1px solid #f3f4f6;"><span style="color:#9ca3af;display:inline-block;min-width:140px;">Αποστολή:</span><span style="color:#111827;font-weight:600;">{{mission_title}}</span></div><div style="padding:7px 0;font-size:14px;border-bottom:1px solid #f3f4f6;"><span style="color:#9ca3af;display:inline-block;min-width:140px;">Ημερομηνία:</span><span style="color:#111827;font-weight:600;">{{shift_date}}</span></div><div style="padding:7px 0;font-size:14px;border-bottom:1px solid #f3f4f6;"><span style="color:#9ca3af;display:inline-block;min-width:140px;">Ώρα:</span><span style="color:#111827;font-weight:600;">{{shift_time}}</span></div><div style="padding:7px 0;font-size:14px;border-bottom:1px solid #f3f4f6;"><span style="color:#9ca3af;display:inline-block;min-width:140px;">Μήνυμα:</span><span style="color:#111827;font-weight:600;">{{message}}</span></div></div></div></div></div>',
                    ],
                    'shift_swap_accepted' => [
                        'name'    => 'Αποδοχή Αιτήματος Αντικατάστασης',
                        'subject' => 'Ο/Η {{replacement_name}} αποδέχτηκε το αίτημα αντικατάστασης',
                        'desc'    => 'Αποστέλλεται στον αιτούντα όταν ο αντικατάστατης αποδεχτεί',
                        'vars'    => '{{user_name}}, {{replacement_name}}, {{mission_title}}, {{shift_date}}, {{shift_time}}',
                        'html'    => '<div style="background:#eef2f7;padding:28px 0 40px;font-family:Helvetica Neue,Arial,sans-serif;"><div style="max-width:600px;margin:0 auto;"><div style="background:#16a34a;padding:30px 40px 26px;border-radius:12px 12px 0 0;text-align:center;">{{logo_html}}<p style="color:rgba(255,255,255,0.7);font-size:11px;letter-spacing:2px;text-transform:uppercase;margin:0 0 8px;">{{app_name}}</p><div style="font-size:36px;line-height:1;margin:0 0 8px;">&#10003;</div><h1 style="color:#fff;margin:0;font-size:23px;font-weight:700;line-height:1.3;">Αποδοχή Αιτήματος Αντικατάστασης</h1></div><div style="background:#fff;padding:36px 40px 40px;border-radius:0 0 12px 12px;box-shadow:0 4px 20px rgba(0,0,0,0.07);"><h2 style="color:#1f2937;font-size:18px;font-weight:700;margin:0 0 14px;">Γεια σας {{user_name}},</h2><p style="color:#4b5563;line-height:1.65;font-size:15px;margin:0 0 14px;">Χαρούμαστε να σας ενημερώσουμε ότι ο/η <strong>{{replacement_name}}</strong> αποδέχτηκε το αίτημά σας για αντικατάσταση στη βάρδια. Αναμένεται η τελική έγκριση από τον διαχειριστή.</p><div style="background:#f9fafb;border-left:4px solid #16a34a;padding:2px 20px;border-radius:0 8px 8px 0;margin:20px 0;"><div style="padding:7px 0;font-size:14px;border-bottom:1px solid #f3f4f6;"><span style="color:#9ca3af;display:inline-block;min-width:140px;">Αποστολή:</span><span style="color:#111827;font-weight:600;">{{mission_title}}</span></div><div style="padding:7px 0;font-size:14px;border-bottom:1px solid #f3f4f6;"><span style="color:#9ca3af;display:inline-block;min-width:140px;">Ημερομηνία:</span><span style="color:#111827;font-weight:600;">{{shift_date}}</span></div><div style="padding:7px 0;font-size:14px;border-bottom:1px solid #f3f4f6;"><span style="color:#9ca3af;display:inline-block;min-width:140px;">Ώρα:</span><span style="color:#111827;font-weight:600;">{{shift_time}}</span></div></div></div></div></div>',
                    ],
                    'shift_swap_approved' => [
                        'name'    => 'Έγκριση Αντικατάστασης Βάρδιας',
                        'subject' => 'Η αντικατάσταση για {{mission_title}} εγκρίθηκε',
                        'desc'    => 'Αποστέλλεται και στους δύο εθελοντές όταν ο διαχειριστής εγκρίνει',
                        'vars'    => '{{user_name}}, {{replacement_name}}, {{mission_title}}, {{shift_date}}, {{shift_time}}',
                        'html'    => '<div style="background:#eef2f7;padding:28px 0 40px;font-family:Helvetica Neue,Arial,sans-serif;"><div style="max-width:600px;margin:0 auto;"><div style="background:#2563eb;padding:30px 40px 26px;border-radius:12px 12px 0 0;text-align:center;">{{logo_html}}<p style="color:rgba(255,255,255,0.7);font-size:11px;letter-spacing:2px;text-transform:uppercase;margin:0 0 8px;">{{app_name}}</p><div style="font-size:36px;line-height:1;margin:0 0 8px;">&#9989;</div><h1 style="color:#fff;margin:0;font-size:23px;font-weight:700;line-height:1.3;">Αντικατάσταση Βάρδιας Εγκρίθηκε</h1></div><div style="background:#fff;padding:36px 40px 40px;border-radius:0 0 12px 12px;box-shadow:0 4px 20px rgba(0,0,0,0.07);"><h2 style="color:#1f2937;font-size:18px;font-weight:700;margin:0 0 14px;">Γεια σας {{user_name}},</h2><p style="color:#4b5563;line-height:1.65;font-size:15px;margin:0 0 14px;">Η αντικατάσταση στη βάρδια έχει εγκριθεί από τον διαχειριστή. Παρακαλούμε ελέγξτε τις ενημερωμένες βάρδιες σας.</p><div style="background:#f9fafb;border-left:4px solid #2563eb;padding:2px 20px;border-radius:0 8px 8px 0;margin:20px 0;"><div style="padding:7px 0;font-size:14px;border-bottom:1px solid #f3f4f6;"><span style="color:#9ca3af;display:inline-block;min-width:140px;">Αποστολή:</span><span style="color:#111827;font-weight:600;">{{mission_title}}</span></div><div style="padding:7px 0;font-size:14px;border-bottom:1px solid #f3f4f6;"><span style="color:#9ca3af;display:inline-block;min-width:140px;">Ημερομηνία:</span><span style="color:#111827;font-weight:600;">{{shift_date}}</span></div><div style="padding:7px 0;font-size:14px;border-bottom:1px solid #f3f4f6;"><span style="color:#9ca3af;display:inline-block;min-width:140px;">Ώρα:</span><span style="color:#111827;font-weight:600;">{{shift_time}}</span></div></div><p style="color:#6b7280;font-size:13px;line-height:1.6;margin:24px 0 0;">Αν έχετε απορίες, επικοινωνήστε με τον διαχειριστή σας.</p></div></div></div>',
                    ],
                ];
                foreach ($templates as $code => $meta) {
                    dbExecute(
                        "UPDATE email_templates
                         SET name=?, subject=?, description=?, available_variables=?, body_html=?
                         WHERE code=?",
                        [$meta['name'], $meta['subject'], $meta['desc'], $meta['vars'], $meta['html'], $code]
                    );
                }
                $notifNames = [
                    'shift_swap_requested' => 'Αίτημα αντικατάστασης βάρδιας (προς αντικατάστατη)',
                    'shift_swap_accepted'  => 'Αποδοχή αιτήματος αντικατάστασης (προς αιτούντα)',
                    'shift_swap_approved'  => 'Έγκριση αντικατάστασης (και στους δύο)',
                ];
                foreach ($notifNames as $code => $name) {
                    dbExecute(
                        "UPDATE notification_settings SET name=? WHERE code=?",
                        [$name, $code]
                    );
                }
            },
        ],

        // ── v37: Citizens status timestamp columns ──
        [
            'version'     => 37,
            'description' => 'Add timestamp columns to citizens for contact/payment/completed',
            'up'          => function () {
                $cols = dbFetchAll("SHOW COLUMNS FROM citizens LIKE 'contact_done_at'");
                if (empty($cols)) {
                    dbExecute("ALTER TABLE citizens
                        ADD COLUMN contact_done_at DATETIME NULL COMMENT 'Ημερομηνία επικοινωνίας' AFTER contact_done,
                        ADD COLUMN payment_done_at DATETIME NULL COMMENT 'Ημερομηνία πληρωμής' AFTER payment_done,
                        ADD COLUMN completed_at DATETIME NULL COMMENT 'Ημερομηνία ολοκλήρωσης' AFTER completed");
                    // Backfill existing checked rows
                    dbExecute("UPDATE citizens SET contact_done_at = updated_at WHERE contact_done = 1 AND contact_done_at IS NULL");
                    dbExecute("UPDATE citizens SET payment_done_at = updated_at WHERE payment_done = 1 AND payment_done_at IS NULL");
                    dbExecute("UPDATE citizens SET completed_at = updated_at WHERE completed = 1 AND completed_at IS NULL");
                }
            },
        ],

        // ── v38: Rename father_name to phone in citizen_certificates ──
        [
            'version'     => 38,
            'description' => 'Rename father_name column to phone in citizen_certificates',
            'up'          => function () {
                $cols = dbFetchAll("SHOW COLUMNS FROM citizen_certificates LIKE 'father_name'");
                if (!empty($cols)) {
                    dbExecute("ALTER TABLE citizen_certificates CHANGE `father_name` `phone` VARCHAR(30) NULL COMMENT 'Τηλέφωνο'");
                }
            },
        ],

        // ── v41: Prerequisite settings (goals, hours, mission types) ──
        [
            'version'     => 41,
            'description' => 'Seed prerequisite settings for attendance, TEP, and education goals',
            'up' => function () {
                // Lookup mission type IDs by name
                $medicalId = (int) dbFetchValue("SELECT id FROM mission_types WHERE name = 'Υγειονομική' LIMIT 1");
                $rescueId  = (int) dbFetchValue("SELECT id FROM mission_types WHERE name = 'Διασωστική' LIMIT 1");
                $tepId     = (int) dbFetchValue("SELECT id FROM mission_types WHERE name = 'Τ.Ε.Π.' LIMIT 1");
                $eduId     = (int) dbFetchValue("SELECT id FROM mission_types WHERE name LIKE '%Επανεκπαίδευση%' LIMIT 1");

                $prereqDefaults = [
                    'prereq_attendance_enabled' => '1',
                    'prereq_attendance_goal'    => '10',
                    'prereq_hours_enabled'      => '0',
                    'prereq_hours_goal'         => '0',
                    'prereq_mission_types'      => implode(',', array_filter([$medicalId, $rescueId])),
                    'prereq_tep_attendance_enabled' => '0',
                    'prereq_tep_attendance_goal'    => '0',
                    'prereq_tep_hours_enabled'      => '1',
                    'prereq_tep_hours_goal'         => '40',
                    'prereq_tep_mission_types'      => $tepId ? (string) $tepId : '',
                    'prereq_edu_attendance_enabled' => '1',
                    'prereq_edu_attendance_goal'    => '2',
                    'prereq_edu_hours_enabled'      => '0',
                    'prereq_edu_hours_goal'         => '0',
                    'prereq_edu_mission_types'      => $eduId ? (string) $eduId : '',
                ];

                foreach ($prereqDefaults as $key => $value) {
                    dbExecute(
                        "INSERT INTO settings (setting_key, setting_value, created_at, updated_at)
                         VALUES (?, ?, NOW(), NOW())
                         ON DUPLICATE KEY UPDATE setting_key = setting_key",
                        [$key, $value]
                    );
                }
            },
        ],

        // ── v42: Add registration_date & registration_number to inventory_items ──
        [
            'version'     => 42,
            'description' => 'Add registration_date and registration_number columns to inventory_items',
            'up' => function () {
                $col = dbFetchOne(
                    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME   = 'inventory_items'
                       AND COLUMN_NAME  = 'registration_date'"
                );
                if (!$col) {
                    dbExecute("ALTER TABLE inventory_items ADD COLUMN registration_date DATE NULL AFTER name");
                }

                $col2 = dbFetchOne(
                    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME   = 'inventory_items'
                       AND COLUMN_NAME  = 'registration_number'"
                );
                if (!$col2) {
                    dbExecute("ALTER TABLE inventory_items ADD COLUMN registration_number VARCHAR(50) NULL AFTER registration_date");
                }
            },
        ],

        // ── v43: Newsletter template settings + extra_emails column ──
        [
            'version'     => 43,
            'description' => 'Add newsletter_template_header/footer settings and newsletters.extra_emails column',
            'up' => function () {
                // 1. Add extra_emails column to newsletters
                $col = dbFetchOne(
                    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME   = 'newsletters'
                       AND COLUMN_NAME  = 'extra_emails'"
                );
                if (!$col) {
                    dbExecute("ALTER TABLE newsletters ADD COLUMN extra_emails TEXT NULL AFTER filter_dept_id");
                }

                // 2. Insert default newsletter template header
                $defaultHeader = '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<style>body{font-family:Arial,sans-serif;background:#f4f4f4;margin:0;padding:0}
.wrap{max-width:600px;margin:30px auto;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.1)}
.hdr{background:#c0392b;padding:24px 32px;color:#fff}.hdr h2{margin:0;font-size:22px}
.body{padding:32px;color:#333;line-height:1.6}.ftr{background:#f8f8f8;padding:16px 32px;font-size:12px;color:#aaa;text-align:center}
</style></head><body>
<div class="wrap">
  <div class="hdr"><h2>{from_name}</h2></div>
  <div class="body">';

                $defaultFooter = '</div>
  <div class="ftr"><p>Αυτό το email στάλθηκε από {from_name}.</p></div>
</div></body></html>';

                dbExecute(
                    "INSERT INTO settings (setting_key, setting_value, created_at, updated_at)
                     VALUES ('newsletter_template_header', ?, NOW(), NOW())
                     ON DUPLICATE KEY UPDATE setting_key = setting_key",
                    [$defaultHeader]
                );
                dbExecute(
                    "INSERT INTO settings (setting_key, setting_value, created_at, updated_at)
                     VALUES ('newsletter_template_footer', ?, NOW(), NOW())
                     ON DUPLICATE KEY UPDATE setting_key = setting_key",
                    [$defaultFooter]
                );
            },
        ],

        // ── v44: Newsletter templates table + newsletters.template_id ──
        [
            'version'     => 44,
            'description' => 'Create newsletter_templates table, add newsletters.template_id, seed default template',
            'up' => function () {
                // 1. Create newsletter_templates table
                $tbl = dbFetchOne(
                    "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'newsletter_templates'"
                );
                if (!$tbl) {
                    dbExecute("CREATE TABLE newsletter_templates (
                        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        name VARCHAR(100) NOT NULL,
                        header_html MEDIUMTEXT NOT NULL,
                        footer_html MEDIUMTEXT NOT NULL,
                        is_default TINYINT(1) NOT NULL DEFAULT 0,
                        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                }

                // 2. Seed default template if table is empty
                $cnt = (int)dbFetchValue("SELECT COUNT(*) FROM newsletter_templates");
                if ($cnt === 0) {
                    $header = '<!DOCTYPE html>
<html lang="el">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body { margin:0; padding:0; background:#eef1f6; font-family: "Segoe UI", -apple-system, BlinkMacSystemFont, Roboto, "Helvetica Neue", Arial, sans-serif; -webkit-font-smoothing: antialiased; }
.email-container { max-width:600px; margin:0 auto; }
.email-preheader { display:none !important; font-size:1px; line-height:1px; max-height:0; overflow:hidden; }
.email-top-accent { height:6px; background: linear-gradient(90deg, #c0392b 0%, #e74c3c 25%, #f39c12 50%, #27ae60 75%, #2980b9 100%); }
.email-header { background: linear-gradient(160deg, #1a1a2e 0%, #16213e 40%, #0f3460 100%); padding:40px 32px 32px; text-align:center; }
.email-header .logo-circle { display:inline-block; width:72px; height:72px; border-radius:50%; background:rgba(255,255,255,.12); border:2px solid rgba(255,255,255,.2); padding:10px; margin-bottom:16px; }
.email-header .logo-circle img { max-height:48px; max-width:48px; vertical-align:middle; }
.email-header h1 { margin:0; color:#ffffff; font-size:26px; font-weight:700; letter-spacing:0.5px; text-shadow:0 2px 8px rgba(0,0,0,.3); }
.email-header .tagline { margin:8px 0 0; color:rgba(255,255,255,.7); font-size:13px; font-weight:400; letter-spacing:1px; text-transform:uppercase; }
.email-divider { height:4px; background: linear-gradient(90deg, #e74c3c, #f39c12, #e74c3c); margin:0; border:0; }
.email-body { background:#ffffff; padding:40px 36px; color:#2c3e50; line-height:1.8; font-size:15px; }
.email-body h2 { color:#1a1a2e; margin:0 0 16px; font-size:20px; font-weight:700; }
.email-body a { color:#e74c3c; text-decoration:none; font-weight:600; }
.email-body a:hover { text-decoration:underline; }
@media only screen and (max-width:620px) {
  .email-body { padding:28px 20px !important; }
  .email-header { padding:28px 20px 24px !important; }
  .email-header h1 { font-size:22px !important; }
}
</style>
</head>
<body>
<div class="email-preheader">&nbsp;</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eef1f6;">
<tr><td align="center" style="padding:30px 12px;">
<div class="email-container">
  <div class="email-top-accent"></div>
  <div class="email-header">
    <div class="logo-circle">{logo_url}</div>
    <h1>{from_name}</h1>
    <p class="tagline">Ενημερωτικό Δελτίο</p>
  </div>
  <div class="email-divider"></div>
  <div class="email-body">';

                    $footer = '</div>
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#1a1a2e;">
    <tr><td style="padding:28px 32px; text-align:center;">
      <p style="margin:0 0 6px; font-size:13px; color:rgba(255,255,255,.6);">&copy; ' . date('Y') . ' {from_name}</p>
      <p style="margin:0 0 12px; font-size:11px; color:rgba(255,255,255,.35);">Λάβατε αυτό το email επειδή είστε εγγεγραμμένος/η στο σύστημά μας.</p>
      <div style="border-top:1px solid rgba(255,255,255,.1); padding-top:12px; margin-top:4px;">
        <p style="margin:0; font-size:11px; color:rgba(255,255,255,.3);">Powered by <span style="color:#e74c3c;">VolunteerOps</span></p>
      </div>
    </td></tr>
  </table>
</div>
</td></tr>
</table>
</body>
</html>';

                    // Check which schema we have (fresh install may have body_html from schema.sql)
                    $hasBodyCol = dbFetchOne(
                        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'newsletter_templates' AND COLUMN_NAME = 'body_html'"
                    );
                    if ($hasBodyCol) {
                        // Fresh install with v46+ schema: insert as single body_html
                        dbInsert(
                            "INSERT INTO newsletter_templates (name, body_html, is_default, created_at, updated_at) VALUES (?, ?, 1, NOW(), NOW())",
                            ['Βασικό πρότυπο', $header . '{content}' . $footer]
                        );
                    } else {
                        dbInsert(
                            "INSERT INTO newsletter_templates (name, header_html, footer_html, is_default, created_at, updated_at) VALUES (?, ?, ?, 1, NOW(), NOW())",
                            ['Βασικό πρότυπο', $header, $footer]
                        );
                    }
                }

                // 3. Add template_id column to newsletters
                $col = dbFetchOne(
                    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME   = 'newsletters'
                       AND COLUMN_NAME  = 'template_id'"
                );
                if (!$col) {
                    dbExecute("ALTER TABLE newsletters ADD COLUMN template_id INT UNSIGNED NULL AFTER extra_emails");
                }

                // 4. Clean up old settings-based template (optional)
                dbExecute("DELETE FROM settings WHERE setting_key IN ('newsletter_template_header', 'newsletter_template_footer')");
            },
        ],

        // ── v45 ── Upgrade default newsletter template to premium design ──
        [
            'version'     => 45,
            'description' => 'Upgrade default newsletter template to premium design',
            'up'          => function () {
                $defaultTpl = dbFetchOne("SELECT id FROM newsletter_templates WHERE is_default = 1 AND name = 'Βασικό πρότυπο' LIMIT 1");
                if ($defaultTpl) {
                    $header = '<!DOCTYPE html>
<html lang="el">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body { margin:0; padding:0; background:#eef1f6; font-family: "Segoe UI", -apple-system, BlinkMacSystemFont, Roboto, "Helvetica Neue", Arial, sans-serif; -webkit-font-smoothing: antialiased; }
.email-container { max-width:600px; margin:0 auto; }
.email-preheader { display:none !important; font-size:1px; line-height:1px; max-height:0; overflow:hidden; }
.email-top-accent { height:6px; background: linear-gradient(90deg, #c0392b 0%, #e74c3c 25%, #f39c12 50%, #27ae60 75%, #2980b9 100%); }
.email-header { background: linear-gradient(160deg, #1a1a2e 0%, #16213e 40%, #0f3460 100%); padding:40px 32px 32px; text-align:center; }
.email-header .logo-circle { display:inline-block; width:72px; height:72px; border-radius:50%; background:rgba(255,255,255,.12); border:2px solid rgba(255,255,255,.2); padding:10px; margin-bottom:16px; }
.email-header .logo-circle img { max-height:48px; max-width:48px; vertical-align:middle; }
.email-header h1 { margin:0; color:#ffffff; font-size:26px; font-weight:700; letter-spacing:0.5px; text-shadow:0 2px 8px rgba(0,0,0,.3); }
.email-header .tagline { margin:8px 0 0; color:rgba(255,255,255,.7); font-size:13px; font-weight:400; letter-spacing:1px; text-transform:uppercase; }
.email-divider { height:4px; background: linear-gradient(90deg, #e74c3c, #f39c12, #e74c3c); margin:0; border:0; }
.email-body { background:#ffffff; padding:40px 36px; color:#2c3e50; line-height:1.8; font-size:15px; }
.email-body h2 { color:#1a1a2e; margin:0 0 16px; font-size:20px; font-weight:700; }
.email-body a { color:#e74c3c; text-decoration:none; font-weight:600; }
.email-body a:hover { text-decoration:underline; }
@media only screen and (max-width:620px) {
  .email-body { padding:28px 20px !important; }
  .email-header { padding:28px 20px 24px !important; }
  .email-header h1 { font-size:22px !important; }
}
</style>
</head>
<body>
<div class="email-preheader">&nbsp;</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eef1f6;">
<tr><td align="center" style="padding:30px 12px;">
<div class="email-container">
  <div class="email-top-accent"></div>
  <div class="email-header">
    <div class="logo-circle">{logo_url}</div>
    <h1>{from_name}</h1>
    <p class="tagline">Ενημερωτικό Δελτίο</p>
  </div>
  <div class="email-divider"></div>
  <div class="email-body">';

                    $footer = '</div>
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#1a1a2e;">
    <tr><td style="padding:28px 32px; text-align:center;">
      <p style="margin:0 0 6px; font-size:13px; color:rgba(255,255,255,.6);">&copy; ' . date('Y') . ' {from_name}</p>
      <p style="margin:0 0 12px; font-size:11px; color:rgba(255,255,255,.35);">Λάβατε αυτό το email επειδή είστε εγγεγραμμένος/η στο σύστημά μας.</p>
      <div style="border-top:1px solid rgba(255,255,255,.1); padding-top:12px; margin-top:4px;">
        <p style="margin:0; font-size:11px; color:rgba(255,255,255,.3);">Powered by <span style="color:#e74c3c;">VolunteerOps</span></p>
      </div>
    </td></tr>
  </table>
</div>
</td></tr>
</table>
</body>
</html>';

                    // Check which schema we have
                    $hasBodyCol = dbFetchOne(
                        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'newsletter_templates' AND COLUMN_NAME = 'body_html'"
                    );
                    if ($hasBodyCol) {
                        dbExecute("UPDATE newsletter_templates SET name='Βασικό πρότυπο', body_html=?, updated_at=NOW() WHERE id=?",
                            [$header . '{content}' . $footer, $defaultTpl['id']]);
                    } else {
                        dbExecute("UPDATE newsletter_templates SET name='Βασικό πρότυπο', header_html=?, footer_html=?, updated_at=NOW() WHERE id=?",
                            [$header, $footer, $defaultTpl['id']]);
                    }
                }
            },
        ],

        // ── v46 ── Merge header_html + footer_html into single body_html with {content} ──
        [
            'version'     => 46,
            'description' => 'Merge newsletter template header/footer into single body_html with {content}',
            'up'          => function () {
                // 1. Add body_html column if not exists
                $col = dbFetchOne(
                    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'newsletter_templates' AND COLUMN_NAME = 'body_html'"
                );
                if (!$col) {
                    dbExecute("ALTER TABLE newsletter_templates ADD COLUMN body_html MEDIUMTEXT NULL AFTER name");
                }

                // 2. Migrate existing data: body_html = header_html + {content} + footer_html
                $hCol = dbFetchOne(
                    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'newsletter_templates' AND COLUMN_NAME = 'header_html'"
                );
                if ($hCol) {
                    $templates = dbFetchAll("SELECT id, header_html, footer_html FROM newsletter_templates WHERE body_html IS NULL");
                    foreach ($templates as $tpl) {
                        $bodyHtml = ($tpl['header_html'] ?? '') . '{content}' . ($tpl['footer_html'] ?? '');
                        dbExecute("UPDATE newsletter_templates SET body_html = ? WHERE id = ?", [$bodyHtml, $tpl['id']]);
                    }
                }

                // 3. Make body_html NOT NULL and drop old columns
                dbExecute("ALTER TABLE newsletter_templates MODIFY body_html MEDIUMTEXT NOT NULL");

                if ($hCol) {
                    dbExecute("ALTER TABLE newsletter_templates DROP COLUMN header_html, DROP COLUMN footer_html");
                }
            },
        ],

        // ── v47 ── Add demo newsletter templates + session_timeout_minutes setting ──
        [
            'version'     => 47,
            'description' => 'Add 5 demo newsletter templates with different designs + session_timeout_minutes setting',
            'up'          => function () {
                // Shared helper to build a template
                $buildTemplate = function(string $headerBg, string $accentColor, string $headerText, string $footerBg, string $tagline, string $bodyLinkColor): string {
                    return '<!DOCTYPE html>
<html lang="el">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body { margin:0; padding:0; background:#f4f6f9; font-family: "Segoe UI", -apple-system, BlinkMacSystemFont, Roboto, "Helvetica Neue", Arial, sans-serif; -webkit-font-smoothing: antialiased; }
.email-container { max-width:600px; margin:0 auto; }
.email-preheader { display:none !important; font-size:1px; line-height:1px; max-height:0; overflow:hidden; }
.email-top-accent { height:5px; background: ' . $accentColor . '; }
.email-header { background: ' . $headerBg . '; padding:36px 32px 28px; text-align:center; }
.email-header .logo-circle { display:inline-block; width:68px; height:68px; border-radius:50%; background:rgba(255,255,255,.15); border:2px solid rgba(255,255,255,.25); padding:10px; margin-bottom:14px; }
.email-header .logo-circle img { max-height:44px; max-width:44px; vertical-align:middle; }
.email-header h1 { margin:0; color:' . $headerText . '; font-size:24px; font-weight:700; letter-spacing:0.5px; }
.email-header .tagline { margin:6px 0 0; color:rgba(255,255,255,.7); font-size:12px; font-weight:400; letter-spacing:1px; text-transform:uppercase; }
.email-divider { height:3px; background: ' . $accentColor . '; margin:0; border:0; }
.email-body { background:#ffffff; padding:36px 32px; color:#2c3e50; line-height:1.8; font-size:15px; }
.email-body h2 { color:#1a1a2e; margin:0 0 14px; font-size:19px; font-weight:700; }
.email-body a { color:' . $bodyLinkColor . '; text-decoration:none; font-weight:600; }
.email-body a:hover { text-decoration:underline; }
@media only screen and (max-width:620px) {
  .email-body { padding:24px 18px !important; }
  .email-header { padding:24px 18px 20px !important; }
  .email-header h1 { font-size:20px !important; }
}
</style>
</head>
<body>
<div class="email-preheader">&nbsp;</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f9;">
<tr><td align="center" style="padding:28px 12px;">
<div class="email-container">
  <div class="email-top-accent"></div>
  <div class="email-header">
    <div class="logo-circle">{logo_url}</div>
    <h1>{from_name}</h1>
    <p class="tagline">' . $tagline . '</p>
  </div>
  <div class="email-divider"></div>
  <div class="email-body">
{content}
  </div>
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:' . $footerBg . ';">
    <tr><td style="padding:24px 32px; text-align:center;">
      <p style="margin:0 0 5px; font-size:12px; color:rgba(255,255,255,.6);">&copy; ' . date('Y') . ' {from_name}</p>
      <p style="margin:0; font-size:11px; color:rgba(255,255,255,.35);">Λάβατε αυτό το email επειδή είστε εγγεγραμμένος/η.</p>
    </td></tr>
  </table>
</div>
</td></tr>
</table>
</body>
</html>';
                };

                $templates = [
                    [
                        'name' => 'Μπλε Κλασικό',
                        'body' => $buildTemplate(
                            'linear-gradient(135deg, #1e3a5f 0%, #2c5f8a 100%)',
                            'linear-gradient(90deg, #2980b9, #3498db, #2980b9)',
                            '#ffffff',
                            '#1e3a5f',
                            'Ενημερωτικό Δελτίο',
                            '#2980b9'
                        ),
                    ],
                    [
                        'name' => 'Πράσινο Φύση',
                        'body' => $buildTemplate(
                            'linear-gradient(135deg, #1a472a 0%, #2d6a4f 100%)',
                            'linear-gradient(90deg, #27ae60, #2ecc71, #27ae60)',
                            '#ffffff',
                            '#1a472a',
                            'Εθελοντικές Δράσεις',
                            '#27ae60'
                        ),
                    ],
                    [
                        'name' => 'Κόκκινο Επείγον',
                        'body' => $buildTemplate(
                            'linear-gradient(135deg, #7b1c1c 0%, #c0392b 100%)',
                            'linear-gradient(90deg, #e74c3c, #ff6b6b, #e74c3c)',
                            '#ffffff',
                            '#7b1c1c',
                            'Σημαντική Ενημέρωση',
                            '#e74c3c'
                        ),
                    ],
                    [
                        'name' => 'Μωβ Εκδήλωση',
                        'body' => $buildTemplate(
                            'linear-gradient(135deg, #2d1b4e 0%, #6c3483 100%)',
                            'linear-gradient(90deg, #8e44ad, #9b59b6, #8e44ad)',
                            '#ffffff',
                            '#2d1b4e',
                            'Πρόσκληση Εκδήλωσης',
                            '#8e44ad'
                        ),
                    ],
                    [
                        'name' => 'Πορτοκαλί Ζεστό',
                        'body' => $buildTemplate(
                            'linear-gradient(135deg, #7c4a03 0%, #d35400 100%)',
                            'linear-gradient(90deg, #e67e22, #f39c12, #e67e22)',
                            '#ffffff',
                            '#7c4a03',
                            'Νέα & Ανακοινώσεις',
                            '#e67e22'
                        ),
                    ],
                ];

                foreach ($templates as $tpl) {
                    // Only insert if name doesn't already exist
                    $exists = dbFetchValue("SELECT COUNT(*) FROM newsletter_templates WHERE name = ?", [$tpl['name']]);
                    if (!$exists) {
                        dbInsert(
                            "INSERT INTO newsletter_templates (name, body_html, is_default, created_at, updated_at) VALUES (?, ?, 0, NOW(), NOW())",
                            [$tpl['name'], $tpl['body']]
                        );
                    }
                }
            },
        ],

        // ── v48 ── Replace basic demo templates with truly unique designs ──
        [
            'version'     => 48,
            'description' => 'Replace color-only demo templates with unique header/footer designs',
            'up'          => function () {
                // Delete the 5 basic color-swap templates from v47
                $oldNames = ['Μπλε Κλασικό', 'Πράσινο Φύση', 'Κόκκινο Επείγον', 'Μωβ Εκδήλωση', 'Πορτοκαλί Ζεστό'];
                foreach ($oldNames as $name) {
                    // Only delete if not used by any newsletter
                    $used = (int)dbFetchValue(
                        "SELECT COUNT(*) FROM newsletters n JOIN newsletter_templates t ON n.template_id = t.id WHERE t.name = ?",
                        [$name]
                    );
                    if (!$used) {
                        dbExecute("DELETE FROM newsletter_templates WHERE name = ? AND is_default = 0", [$name]);
                    }
                }

                $year = date('Y');

                // ── 1. Minimal Clean ──────────────────────────────────────
                $tpl1 = '<!DOCTYPE html>
<html lang="el"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<style>
body{margin:0;padding:0;background:#ffffff;font-family:Georgia,"Times New Roman",serif;color:#333;}
</style></head><body>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0">
<tr><td align="center" style="padding:40px 20px 0;">
  <table role="presentation" width="560" cellpadding="0" cellspacing="0">
    <tr><td style="border-bottom:2px solid #333;padding-bottom:20px;">
      <table role="presentation" width="100%"><tr>
        <td style="font-size:28px;font-weight:bold;color:#1a1a1a;letter-spacing:-0.5px;">{from_name}</td>
        <td align="right" style="vertical-align:middle;">{logo_url}</td>
      </tr></table>
    </td></tr>
    <tr><td style="padding:32px 0;font-size:16px;line-height:1.8;color:#444;">
{content}
    </td></tr>
    <tr><td style="border-top:1px solid #ddd;padding-top:20px;padding-bottom:40px;text-align:center;">
      <p style="margin:0;font-size:12px;color:#999;">&copy; ' . $year . ' {from_name} &middot; Ενημερωτικό Δελτίο</p>
    </td></tr>
  </table>
</td></tr></table>
</body></html>';

                // ── 2. Bold Magazine ──────────────────────────────────────
                $tpl2 = '<!DOCTYPE html>
<html lang="el"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<style>
body{margin:0;padding:0;background:#f0f0f0;font-family:"Segoe UI",Roboto,Arial,sans-serif;}
</style></head><body>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f0f0f0;">
<tr><td align="center" style="padding:30px 16px;">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:0;">
  <!-- Thick top bar -->
  <tr><td style="height:8px;background:#ff2d55;"></td></tr>
  <!-- Header: large bold title -->
  <tr><td style="padding:40px 40px 10px;text-align:left;">
    <table role="presentation" width="100%"><tr>
      <td>{logo_url}</td>
      <td align="right" style="font-size:11px;text-transform:uppercase;letter-spacing:2px;color:#999;font-weight:600;">Τεύχος ' . date('m/Y') . '</td>
    </tr></table>
  </td></tr>
  <tr><td style="padding:0 40px 30px;">
    <h1 style="margin:0;font-size:36px;font-weight:900;color:#1a1a1a;line-height:1.1;letter-spacing:-1px;">{from_name}</h1>
    <div style="width:60px;height:4px;background:#ff2d55;margin-top:16px;border-radius:2px;"></div>
  </td></tr>
  <!-- Body -->
  <tr><td style="padding:10px 40px 40px;font-size:15px;line-height:1.8;color:#333;">
{content}
  </td></tr>
  <!-- Footer -->
  <tr><td style="background:#1a1a1a;padding:30px 40px;">
    <table role="presentation" width="100%"><tr>
      <td style="font-size:13px;color:#888;">&copy; ' . $year . ' {from_name}</td>
      <td align="right" style="font-size:11px;color:#666;">Powered by <span style="color:#ff2d55;">VolunteerOps</span></td>
    </tr></table>
  </td></tr>
</table>
</td></tr></table>
</body></html>';

                // ── 3. Card Style ─────────────────────────────────────────
                $tpl3 = '<!DOCTYPE html>
<html lang="el"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<style>
body{margin:0;padding:0;background:#e8ecf1;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;}
</style></head><body>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#e8ecf1;">
<tr><td align="center" style="padding:40px 16px;">
  <!-- Logo bar -->
  <table role="presentation" width="580" cellpadding="0" cellspacing="0">
    <tr><td align="center" style="padding-bottom:24px;">
      <div style="display:inline-block;background:#ffffff;border-radius:50%;width:70px;height:70px;text-align:center;line-height:70px;box-shadow:0 4px 15px rgba(0,0,0,.1);">
        {logo_url}
      </div>
    </td></tr>
    <tr><td align="center" style="padding-bottom:8px;">
      <h1 style="margin:0;font-size:22px;font-weight:700;color:#2c3e50;">{from_name}</h1>
      <p style="margin:4px 0 20px;font-size:13px;color:#7f8c8d;letter-spacing:0.5px;">Ενημερωτικό Δελτίο</p>
    </td></tr>
  </table>
  <!-- Content card -->
  <table role="presentation" width="580" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:12px;box-shadow:0 2px 20px rgba(0,0,0,.08);">
    <tr><td style="padding:36px 40px;font-size:15px;line-height:1.8;color:#2c3e50;">
{content}
    </td></tr>
  </table>
  <!-- Footer -->
  <table role="presentation" width="580" cellpadding="0" cellspacing="0">
    <tr><td align="center" style="padding:24px 0;">
      <p style="margin:0;font-size:12px;color:#95a5a6;">&copy; ' . $year . ' {from_name}</p>
      <p style="margin:4px 0 0;font-size:11px;color:#bdc3c7;">Αυτό το email στάλθηκε μέσω VolunteerOps</p>
    </td></tr>
  </table>
</td></tr></table>
</body></html>';

                // ── 4. Split Header ───────────────────────────────────────
                $tpl4 = '<!DOCTYPE html>
<html lang="el"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<style>
body{margin:0;padding:0;background:#f5f5f5;font-family:"Segoe UI",Roboto,Arial,sans-serif;}
</style></head><body>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f5f5;">
<tr><td align="center" style="padding:30px 16px;">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="border-radius:8px;overflow:hidden;box-shadow:0 1px 12px rgba(0,0,0,.1);">
  <!-- Two-tone header -->
  <tr>
    <td width="180" style="background:#2c3e50;padding:28px 24px;vertical-align:middle;text-align:center;">
      <div style="display:inline-block;background:rgba(255,255,255,.12);border-radius:12px;padding:12px;">{logo_url}</div>
    </td>
    <td style="background:#34495e;padding:28px 32px;vertical-align:middle;">
      <h1 style="margin:0;font-size:22px;font-weight:700;color:#ffffff;">{from_name}</h1>
      <p style="margin:6px 0 0;font-size:12px;color:#bdc3c7;text-transform:uppercase;letter-spacing:1.5px;">Εθελοντική Ομάδα</p>
    </td>
  </tr>
  <!-- Accent line -->
  <tr><td colspan="2" style="height:4px;background:linear-gradient(90deg,#3498db,#2ecc71);"></td></tr>
  <!-- Body -->
  <tr><td colspan="2" style="background:#ffffff;padding:36px 40px;font-size:15px;line-height:1.8;color:#2c3e50;">
{content}
  </td></tr>
  <!-- Footer -->
  <tr><td colspan="2" style="background:#ecf0f1;padding:20px 40px;">
    <table role="presentation" width="100%"><tr>
      <td style="font-size:12px;color:#7f8c8d;">{from_name} &copy; ' . $year . '</td>
      <td align="right" style="font-size:11px;color:#95a5a6;">
        <span style="color:#3498db;">&hearts;</span> Powered by VolunteerOps
      </td>
    </tr></table>
  </td></tr>
</table>
</td></tr></table>
</body></html>';

                // ── 5. Dark Mode ──────────────────────────────────────────
                $tpl5 = '<!DOCTYPE html>
<html lang="el"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<style>
body{margin:0;padding:0;background:#0d1117;font-family:"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;}
</style></head><body>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#0d1117;">
<tr><td align="center" style="padding:30px 16px;">
<table role="presentation" width="600" cellpadding="0" cellspacing="0">
  <!-- Header -->
  <tr><td style="background:#161b22;padding:32px 40px;border-bottom:1px solid #30363d;">
    <table role="presentation" width="100%"><tr>
      <td style="vertical-align:middle;">
        <div style="display:inline-block;vertical-align:middle;margin-right:14px;">{logo_url}</div>
        <span style="font-size:20px;font-weight:700;color:#f0f6fc;vertical-align:middle;">{from_name}</span>
      </td>
      <td align="right" style="vertical-align:middle;">
        <span style="display:inline-block;background:#238636;color:#fff;font-size:11px;font-weight:600;padding:4px 12px;border-radius:20px;text-transform:uppercase;letter-spacing:0.5px;">Newsletter</span>
      </td>
    </tr></table>
  </td></tr>
  <!-- Body -->
  <tr><td style="background:#0d1117;padding:36px 40px;font-size:15px;line-height:1.8;color:#c9d1d9;">
{content}
  </td></tr>
  <!-- Footer -->
  <tr><td style="background:#161b22;padding:24px 40px;border-top:1px solid #30363d;">
    <table role="presentation" width="100%"><tr>
      <td style="font-size:12px;color:#484f58;">&copy; ' . $year . ' {from_name}</td>
      <td align="right" style="font-size:11px;color:#484f58;">
        Made with <span style="color:#238636;">&hearts;</span> by VolunteerOps
      </td>
    </tr></table>
  </td></tr>
</table>
</td></tr></table>
</body></html>';

                $newTemplates = [
                    ['name' => 'Minimal Clean',    'body' => $tpl1],
                    ['name' => 'Bold Magazine',    'body' => $tpl2],
                    ['name' => 'Card Style',       'body' => $tpl3],
                    ['name' => 'Split Header',     'body' => $tpl4],
                    ['name' => 'Dark Mode',        'body' => $tpl5],
                ];

                foreach ($newTemplates as $tpl) {
                    $exists = dbFetchValue("SELECT COUNT(*) FROM newsletter_templates WHERE name = ?", [$tpl['name']]);
                    if (!$exists) {
                        dbInsert(
                            "INSERT INTO newsletter_templates (name, body_html, is_default, created_at, updated_at) VALUES (?, ?, 0, NOW(), NOW())",
                            [$tpl['name'], $tpl['body']]
                        );
                    }
                }
            },
        ],

        // ── v49 ── Newsletter content presets table + seed data ──────────
        [
            'version'     => 49,
            'description' => 'Create newsletter_presets table with 4 Greek seed presets',
            'up'          => function () {
                // Create table if not exists
                $exists = dbFetchOne(
                    "SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'newsletter_presets'"
                );
                if (!$exists) {
                    dbExecute("
                        CREATE TABLE `newsletter_presets` (
                            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                            `name` VARCHAR(150) NOT NULL,
                            `description` VARCHAR(255) NULL,
                            `body_html` MEDIUMTEXT NOT NULL,
                            `created_by` INT UNSIGNED NULL,
                            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                    ");
                }

                // Seed 4 default presets
                $presets = [
                    [
                        'name' => 'Μηνιαία Ενημέρωση',
                        'description' => 'Μηνιαία ανασκόπηση δραστηριοτήτων και νέων',
                        'body' => '<h2 style="color:#2c3e50;">📋 Μηνιαία Ενημέρωση</h2>
<p>Αγαπητοί εθελοντές,</p>
<p>Ακολουθεί η μηνιαία ανασκόπηση των δραστηριοτήτων μας:</p>

<h3 style="color:#3498db;">🎯 Αποστολές του μήνα</h3>
<ul>
<li>[Περιγραφή αποστολής 1]</li>
<li>[Περιγραφή αποστολής 2]</li>
</ul>

<h3 style="color:#27ae60;">🏆 Κορυφαίοι Εθελοντές</h3>
<p>[Αναφέρετε τους εθελοντές με τους περισσότερους πόντους]</p>

<h3 style="color:#e67e22;">📅 Προσεχείς Δραστηριότητες</h3>
<p>[Προσθέστε τις επερχόμενες αποστολές και εκδηλώσεις]</p>

<p>Σας ευχαριστούμε για την προσφορά σας!</p>
<p>Με εκτίμηση,<br>{name}</p>',
                    ],
                    [
                        'name' => 'Πρόσκληση Αποστολής',
                        'description' => 'Πρόσκληση συμμετοχής σε νέα αποστολή',
                        'body' => '<h2 style="color:#e74c3c;">🚨 Νέα Αποστολή - Χρειαζόμαστε τη βοήθειά σας!</h2>
<p>Αγαπητέ/ή {name},</p>
<p>Σας ενημερώνουμε για μια <strong>νέα αποστολή</strong> που χρειάζεται εθελοντές:</p>

<table style="width:100%;border-collapse:collapse;margin:16px 0;">
<tr style="background:#f8f9fa;"><td style="padding:10px;border:1px solid #dee2e6;font-weight:bold;">📍 Τοποθεσία</td><td style="padding:10px;border:1px solid #dee2e6;">[Τοποθεσία]</td></tr>
<tr><td style="padding:10px;border:1px solid #dee2e6;font-weight:bold;">📅 Ημερομηνία</td><td style="padding:10px;border:1px solid #dee2e6;">[Ημερομηνία]</td></tr>
<tr style="background:#f8f9fa;"><td style="padding:10px;border:1px solid #dee2e6;font-weight:bold;">⏰ Ώρες</td><td style="padding:10px;border:1px solid #dee2e6;">[Ώρες]</td></tr>
<tr><td style="padding:10px;border:1px solid #dee2e6;font-weight:bold;">👥 Εθελοντές που χρειάζονται</td><td style="padding:10px;border:1px solid #dee2e6;">[Αριθμός]</td></tr>
</table>

<p><strong>Δηλώστε συμμετοχή</strong> μέσω της πλατφόρμας VolunteerOps το συντομότερο δυνατό!</p>

<p>Με εκτίμηση,<br>Η Ομάδα Διαχείρισης</p>',
                    ],
                    [
                        'name' => 'Γενική Ανακοίνωση',
                        'description' => 'Γενικού σκοπού ανακοίνωση προς εθελοντές',
                        'body' => '<h2 style="color:#2c3e50;">📢 Ανακοίνωση</h2>
<p>Αγαπητοί εθελοντές,</p>

<p>[Γράψτε εδώ το κύριο μήνυμα της ανακοίνωσης]</p>

<div style="background:#f8f9fa;border-left:4px solid #3498db;padding:16px;margin:16px 0;border-radius:4px;">
<strong>ℹ️ Σημαντική πληροφορία:</strong><br>
[Προσθέστε οποιαδήποτε σημαντική λεπτομέρεια]
</div>

<p>Για ερωτήσεις ή διευκρινίσεις, μη διστάσετε να επικοινωνήσετε μαζί μας.</p>

<p>Με εκτίμηση,<br>Η Ομάδα Διαχείρισης</p>',
                    ],
                    [
                        'name' => 'Καλωσόρισμα Νέων Μελών',
                        'description' => 'Μήνυμα καλωσορίσματος για νέους εθελοντές',
                        'body' => '<h2 style="color:#27ae60;">🎉 Καλώς ήρθατε στην ομάδα μας!</h2>
<p>Αγαπητέ/ή {name},</p>
<p>Σας καλωσορίζουμε στην εθελοντική μας ομάδα! Είμαστε χαρούμενοι που είστε μαζί μας.</p>

<h3 style="color:#3498db;">🚀 Πρώτα βήματα</h3>
<ol>
<li><strong>Συνδεθείτε</strong> στην πλατφόρμα VolunteerOps</li>
<li><strong>Συμπληρώστε</strong> το προφίλ σας με τα στοιχεία σας</li>
<li><strong>Δείτε</strong> τις διαθέσιμες αποστολές</li>
<li><strong>Δηλώστε</strong> συμμετοχή στη βάρδια που σας ενδιαφέρει</li>
</ol>

<h3 style="color:#e67e22;">🏆 Σύστημα Πόντων</h3>
<p>Κερδίζετε πόντους για κάθε βάρδια που ολοκληρώνετε! Παρακολουθήστε τη βαθμολογία σας στο leaderboard.</p>

<p>Ανυπομονούμε να σας δούμε στην πρώτη σας αποστολή!</p>

<p>Με εκτίμηση,<br>Η Ομάδα Διαχείρισης</p>',
                    ],
                ];

                foreach ($presets as $p) {
                    $exists = dbFetchValue("SELECT COUNT(*) FROM newsletter_presets WHERE name = ?", [$p['name']]);
                    if (!$exists) {
                        dbInsert(
                            "INSERT INTO newsletter_presets (name, description, body_html, created_at, updated_at) VALUES (?, ?, ?, NOW(), NOW())",
                            [$p['name'], $p['description'], $p['body']]
                        );
                    }
                }
            },
        ],

        [
            'version'     => 50,
            'description' => 'Create push_subscriptions table and add push_enabled column',
            'up' => function () {
                // Create push_subscriptions table
                $tbl = dbFetchOne(
                    "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'push_subscriptions'"
                );
                if (!$tbl) {
                    dbExecute("CREATE TABLE push_subscriptions (
                        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                        user_id INT UNSIGNED NOT NULL,
                        endpoint TEXT NOT NULL,
                        p256dh_key VARCHAR(255) NOT NULL,
                        auth_key VARCHAR(255) NOT NULL,
                        user_agent VARCHAR(512) DEFAULT '',
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                        INDEX idx_push_user (user_id)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                }

                // Add push_enabled column to user_notification_preferences
                $col = dbFetchOne(
                    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME = 'user_notification_preferences'
                       AND COLUMN_NAME = 'push_enabled'"
                );
                if (!$col) {
                    dbExecute("ALTER TABLE user_notification_preferences ADD COLUMN push_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER in_app_enabled");
                }
            },
        ],

        [
            'version'     => 51,
            'description' => 'Make default newsletter template email-client safe',
            'up' => function () {
                $year = date('Y');
                $bodyHtml = '<!DOCTYPE html>
<html lang="el">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{title}</title>
</head>
<body style="margin:0;padding:0;background:#eef1f6;font-family:Arial,Helvetica,sans-serif;">
<div style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;">&nbsp;</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100%;background:#eef1f6;border-collapse:collapse;">
  <tr>
    <td align="center" style="padding:30px 12px;">
      <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;border-collapse:collapse;background:#ffffff;">
        <tr>
          <td style="height:6px;line-height:6px;font-size:1px;background:#2980b9;">&nbsp;</td>
        </tr>
        <tr>
          <td align="center" style="background:#0f3460;padding:38px 32px 30px;text-align:center;">
            <table role="presentation" cellpadding="0" cellspacing="0" align="center" style="margin:0 auto 16px;border-collapse:collapse;">
              <tr>
                <td align="center" valign="middle" style="width:76px;height:76px;border-radius:38px;background:#ffffff;padding:10px;text-align:center;">
                  {logo_url}
                </td>
              </tr>
            </table>
            <h1 style="margin:0;color:#ffffff;font-family:Arial,Helvetica,sans-serif;font-size:26px;line-height:1.3;font-weight:700;letter-spacing:.4px;">{from_name}</h1>
            <p style="margin:8px 0 0;color:#dbeafe;font-family:Arial,Helvetica,sans-serif;font-size:13px;line-height:1.5;font-weight:400;letter-spacing:1px;text-transform:uppercase;">Newsletter</p>
          </td>
        </tr>
        <tr>
          <td style="height:4px;line-height:4px;font-size:1px;background:#e74c3c;">&nbsp;</td>
        </tr>
        <tr>
          <td style="background:#ffffff;padding:40px 36px;color:#2c3e50;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.8;">
            {content}
          </td>
        </tr>
        <tr>
          <td align="center" style="background:#1a1a2e;padding:28px 32px;text-align:center;">
            <p style="margin:0 0 6px;font-family:Arial,Helvetica,sans-serif;font-size:13px;line-height:1.5;color:#cbd5e1;">&copy; ' . $year . ' {from_name}</p>
            <p style="margin:0 0 12px;font-family:Arial,Helvetica,sans-serif;font-size:11px;line-height:1.5;color:#94a3b8;">You received this newsletter from VolunteerOps.</p>
            <p style="margin:0;padding-top:12px;border-top:1px solid #334155;font-family:Arial,Helvetica,sans-serif;font-size:11px;line-height:1.5;color:#64748b;">Powered by <span style="color:#e74c3c;">VolunteerOps</span></p>
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>
</body>
</html>';

                $default = dbFetchOne("SELECT id FROM newsletter_templates WHERE is_default = 1 ORDER BY id LIMIT 1");
                if ($default) {
                    dbExecute("UPDATE newsletter_templates SET body_html = ?, updated_at = NOW() WHERE id = ?", [$bodyHtml, $default['id']]);
                } else {
                    dbInsert(
                        "INSERT INTO newsletter_templates (name, body_html, is_default, created_at, updated_at) VALUES (?, ?, 1, NOW(), NOW())",
                        ['Email Safe Blue', $bodyHtml]
                    );
                }
            },
        ],

        [
            'version'     => 52,
            'description' => 'Normalize newsletter default template selection',
            'up' => function () {
                $fallbackBodyHtml = '<!DOCTYPE html>
<html lang="el">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{title}</title>
</head>
<body style="margin:0;padding:0;background:#eef1f6;font-family:Arial,Helvetica,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100%;background:#eef1f6;border-collapse:collapse;">
  <tr><td align="center" style="padding:30px 12px;">
    <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;border-collapse:collapse;background:#ffffff;">
      <tr><td style="height:6px;line-height:6px;font-size:1px;background:#2980b9;">&nbsp;</td></tr>
      <tr><td align="center" style="background:#0f3460;padding:38px 32px 30px;text-align:center;">
        <table role="presentation" cellpadding="0" cellspacing="0" align="center" style="margin:0 auto 16px;border-collapse:collapse;"><tr><td align="center" valign="middle" style="width:76px;height:76px;border-radius:38px;background:#ffffff;padding:10px;text-align:center;">{logo_url}</td></tr></table>
        <h1 style="margin:0;color:#ffffff;font-size:26px;line-height:1.3;font-weight:700;letter-spacing:.4px;">{from_name}</h1>
        <p style="margin:8px 0 0;color:#dbeafe;font-size:13px;line-height:1.5;letter-spacing:1px;text-transform:uppercase;">Newsletter</p>
      </td></tr>
      <tr><td style="height:4px;line-height:4px;font-size:1px;background:#e74c3c;">&nbsp;</td></tr>
      <tr><td style="background:#ffffff;padding:40px 36px;color:#2c3e50;font-size:15px;line-height:1.8;">{content}</td></tr>
      <tr><td align="center" style="background:#1a1a2e;padding:28px 32px;text-align:center;">
        <p style="margin:0 0 6px;font-size:13px;line-height:1.5;color:#cbd5e1;">&copy; ' . date('Y') . ' {from_name}</p>
        <p style="margin:0 0 12px;font-size:11px;line-height:1.5;color:#94a3b8;">You received this newsletter from VolunteerOps.</p>
        <p style="margin:0;padding-top:12px;border-top:1px solid #334155;font-size:11px;line-height:1.5;color:#64748b;">Powered by <span style="color:#e74c3c;">VolunteerOps</span></p>
      </td></tr>
    </table>
  </td></tr>
</table>
</body>
</html>';

                $oldDefaults = dbFetchAll("SELECT id FROM newsletter_templates WHERE is_default = 1 ORDER BY id ASC");
                $oldDefaultIds = array_map('intval', array_column($oldDefaults, 'id'));

                $blue = dbFetchOne(
                    "SELECT id, body_html FROM newsletter_templates
                     WHERE body_html LIKE '%background:#0f3460%' AND body_html LIKE '%{content}%'
                     ORDER BY is_default DESC, id ASC LIMIT 1"
                );

                if ($blue) {
                    $targetId = (int)$blue['id'];
                    $bodyHtml = (string)$blue['body_html'];
                } else {
                    $targetId = (int)dbFetchValue("SELECT id FROM newsletter_templates WHERE is_default = 1 ORDER BY id ASC LIMIT 1");
                    if ($targetId <= 0) {
                        $targetId = (int)dbFetchValue("SELECT id FROM newsletter_templates ORDER BY id ASC LIMIT 1");
                    }
                    if ($targetId <= 0) {
                        $targetId = dbInsert(
                            "INSERT INTO newsletter_templates (name, body_html, is_default, created_at, updated_at) VALUES (?, ?, 1, NOW(), NOW())",
                            ['Email Safe Blue', $fallbackBodyHtml]
                        );
                    }
                    $bodyHtml = $fallbackBodyHtml;
                }

                dbExecute(
                    "UPDATE newsletter_templates SET name = ?, body_html = ?, is_default = 1, updated_at = NOW() WHERE id = ?",
                    ['Email Safe Blue', $bodyHtml, $targetId]
                );
                dbExecute("UPDATE newsletter_templates SET is_default = 0 WHERE id != ?", [$targetId]);

                if (!empty($oldDefaultIds)) {
                    $placeholders = implode(',', array_fill(0, count($oldDefaultIds), '?'));
                    dbExecute(
                        "UPDATE newsletters
                         SET template_id = ?
                         WHERE status = 'draft'
                           AND (template_id IS NULL OR template_id IN ($placeholders))",
                        array_merge([$targetId], $oldDefaultIds)
                    );
                } else {
                    dbExecute(
                        "UPDATE newsletters SET template_id = ? WHERE status = 'draft' AND template_id IS NULL",
                        [$targetId]
                    );
                }
            },
        ],

        [
            'version'     => 53,
            'description' => 'Backfill QR check-ins as attended participation rows',
            'up' => function () {
                dbExecute(
                    "UPDATE participation_requests pr
                     JOIN shifts s ON s.id = pr.shift_id
                     SET pr.attended = 1,
                         pr.actual_hours = COALESCE(pr.actual_hours, ROUND(TIMESTAMPDIFF(MINUTE, s.start_time, s.end_time) / 60, 2)),
                         pr.actual_start_time = COALESCE(pr.actual_start_time, TIME(s.start_time)),
                         pr.actual_end_time = COALESCE(pr.actual_end_time, TIME(s.end_time)),
                         pr.updated_at = NOW()
                     WHERE pr.status = ?
                       AND pr.attendance_confirmed_at IS NOT NULL
                       AND pr.attended = 0
                       AND pr.attendance_confirmed_by = pr.volunteer_id",
                    [PARTICIPATION_APPROVED]
                );
            },
        ],

        [
            'version'     => 54,
            'description' => 'Repair admin-confirmed absences after QR attendance backfill',
            'up' => function () {
                dbExecute(
                    "UPDATE participation_requests pr
                     JOIN shifts s ON s.id = pr.shift_id
                     SET pr.attended = 0,
                         pr.actual_hours = NULL,
                         pr.actual_start_time = NULL,
                         pr.actual_end_time = NULL,
                         pr.updated_at = NOW()
                     WHERE pr.status = ?
                       AND pr.attendance_confirmed_at IS NOT NULL
                       AND pr.attendance_confirmed_by IS NOT NULL
                       AND pr.attendance_confirmed_by != pr.volunteer_id
                       AND pr.points_awarded = 0
                       AND pr.updated_at > pr.attendance_confirmed_at
                       AND pr.actual_hours = ROUND(TIMESTAMPDIFF(MINUTE, s.start_time, s.end_time) / 60, 2)
                       AND pr.actual_start_time = TIME(s.start_time)
                       AND pr.actual_end_time = TIME(s.end_time)",
                    [PARTICIPATION_APPROVED]
                );
            },
        ],

        [
            'version'     => 55,
            'description' => 'Add seminar type to citizens',
            'up' => function () {
                $cols = dbFetchAll("SHOW COLUMNS FROM citizens LIKE 'seminar_type'");
                if (empty($cols)) {
                    dbExecute("ALTER TABLE citizens ADD COLUMN seminar_type VARCHAR(30) NULL AFTER last_name_lat");
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
            // Log but don't crash the app — migration will retry after cooldown
            error_log(
                "[migrations] Failed migration v{$migration['version']} " .
                "({$migration['description']}): " . $e->getMessage()
            );
            // Record failure time to enable cooldown (5 min before retry)
            try {
                dbExecute(
                    "INSERT INTO settings (setting_key, setting_value, updated_at)
                     VALUES ('migration_last_failure', ?, NOW())
                     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()",
                    [(string)time()]
                );
            } catch (Exception $ignore) {}
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
