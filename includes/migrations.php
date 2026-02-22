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
