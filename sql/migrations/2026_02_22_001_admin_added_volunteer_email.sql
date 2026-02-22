-- Migration: Add admin_added_volunteer email template and notification setting

-- 1. Insert email templates if they don't exist
INSERT INTO `email_templates` (`code`, `name`, `subject`, `body_html`, `description`, `available_variables`)
SELECT 'admin_added_volunteer', 'Προσθήκη από Διαχειριστή', 'Ο διαχειριστής σας τοποθέτησε απευθείας σε βάρδια',
'<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
    <div style="background: #2c3e50; color: white; padding: 20px; text-align: center;">
        <h1>📋 Τοποθέτηση σε Βάρδια</h1>
    </div>
    <div style="padding: 30px; background: #fff;">
        <h2>Γεια σας {{user_name}},</h2>
        <p>Ο διαχειριστής σας τοποθέτησε απευθείας στην παρακάτω βάρδια:</p>
        <div style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #2c3e50;">
            <p><strong>Αποστολή:</strong> {{mission_title}}</p>
            <p><strong>Ημερομηνία:</strong> {{shift_date}}</p>
            <p><strong>Ώρα:</strong> {{shift_time}}</p>
            <p><strong>Τοποθεσία:</strong> {{location}}</p>
        </div>
        {{#admin_notes}}<div style="background: #fff3cd; padding: 15px; border-radius: 8px; margin: 15px 0;">
            <p><strong>Σημείωση διαχειριστή:</strong> {{admin_notes}}</p>
        </div>{{/admin_notes}}
        <p>Παρακαλούμε να είστε στην τοποθεσία έγκαιρα.</p>
        <p style="text-align: center; margin-top: 30px;">
            <a href="{{login_url}}" style="background: #2c3e50; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px;">Σύνδεση στην Πλατφόρμα</a>
        </p>
    </div>
    <div style="padding: 15px; background: #f8f9fa; text-align: center; font-size: 12px; color: #666;">
        {{app_name}} - Σύστημα Διαχείρισης Εθελοντών
    </div>
</div>',
'Αποστέλλεται στον εθελοντή όταν ο διαχειριστής τον προσθέτει απευθείας σε βάρδια (shift-view ή mission-view)',
'{{app_name}}, {{user_name}}, {{mission_title}}, {{shift_date}}, {{shift_time}}, {{location}}, {{admin_notes}}, {{login_url}}'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `email_templates` WHERE `code` = 'admin_added_volunteer');

INSERT INTO `email_templates` (`code`, `name`, `subject`, `body_html`, `description`, `available_variables`)
SELECT 'points_earned', 'Κέρδος Πόντων', 'Κερδίσατε {{points}} πόντους!',
'<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
    <div style="background: #27ae60; color: white; padding: 20px; text-align: center;">
        <h1>🎉 Συγχαρητήρια!</h1>
    </div>
    <div style="padding: 30px; background: #fff;">
        <h2>Γεια σας {{user_name}},</h2>
        <p style="font-size: 24px; text-align: center; color: #27ae60;">
            <strong>+{{points}} πόντοι</strong>
        </p>
        <div style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0;">
            <p><strong>Βάρδια:</strong> {{shift_date}}</p>
            <p><strong>Αποστολή:</strong> {{mission_title}}</p>
        </div>
        <p>Συνολικοί πόντοι: <strong>{{total_points}}</strong></p>
    </div>
    <div style="padding: 15px; background: #f8f9fa; text-align: center; font-size: 12px; color: #666;">
        {{app_name}}
    </div>
</div>',
'Αποστέλλεται όταν ο εθελοντής κερδίζει πόντους',
'{{app_name}}, {{user_name}}, {{points}}, {{mission_title}}, {{shift_date}}, {{total_points}}'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `email_templates` WHERE `code` = 'points_earned');

-- 2. Insert notification settings if they don't exist
INSERT INTO `notification_settings` (`code`, `name`, `description`, `email_enabled`, `email_template_id`)
SELECT 'admin_added_volunteer', 'Προσθήκη από Διαχειριστή', 'Όταν ο διαχειριστής προσθέτει εθελοντή απευθείας σε βάρδια', 1, (SELECT id FROM email_templates WHERE code = 'admin_added_volunteer')
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `notification_settings` WHERE `code` = 'admin_added_volunteer');

INSERT INTO `notification_settings` (`code`, `name`, `description`, `email_enabled`, `email_template_id`)
SELECT 'points_earned', 'Κέρδος Πόντων', 'Όταν ο εθελοντής κερδίζει πόντους', 0, (SELECT id FROM email_templates WHERE code = 'points_earned')
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM `notification_settings` WHERE `code` = 'points_earned');
