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
