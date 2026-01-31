<?php
/**
 * Standalone Script - Import Email Templates
 * Τρέξε αυτό το αρχείο μέσω browser για να κάνεις import όλα τα email templates
 */

require_once __DIR__ . '/bootstrap.php';

echo "<!DOCTYPE html><html><head><meta charset='utf-8'><title>Email Templates Import</title>";
echo "<style>body{font-family:Arial;margin:40px;} .success{color:green;} .error{color:red;}</style></head><body>";
echo "<h1>🔧 Import Email Templates</h1>";

try {
    // Έλεγχος αν υπάρχουν ήδη templates
    $existing = dbFetchValue("SELECT COUNT(*) FROM email_templates");
    if ($existing > 0) {
        echo "<p class='error'>⚠️ Υπάρχουν ήδη {$existing} email templates στη βάση!</p>";
        echo "<p>Αν θες να τα αντικαταστήσεις, τρέξε πρώτα: <code>DELETE FROM email_templates;</code></p>";
        exit;
    }

    echo "<h2>Εγκατάσταση Email Templates...</h2>";

    // Email Templates
    $templates = [
        [
            'code' => 'welcome',
            'name' => 'Καλωσόρισμα',
            'subject' => 'Καλώς ήρθατε στο {{app_name}}!',
            'body_html' => '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
    <div style="background: #3498db; color: white; padding: 20px; text-align: center;">
        <h1>{{app_name}}</h1>
    </div>
    <div style="padding: 30px; background: #fff;">
        <h2>Καλώς ήρθατε, {{user_name}}!</h2>
        <p>Ευχαριστούμε για την εγγραφή σας στην πλατφόρμα εθελοντισμού.</p>
        <p>Μπορείτε τώρα να:</p>
        <ul>
            <li>Δείτε τις διαθέσιμες αποστολές</li>
            <li>Δηλώσετε συμμετοχή σε βάρδιες</li>
            <li>Κερδίσετε πόντους και επιτεύγματα</li>
        </ul>
        <p style="text-align: center; margin-top: 30px;">
            <a href="{{login_url}}" style="background: #3498db; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px;">Σύνδεση</a>
        </p>
    </div>
    <div style="padding: 15px; background: #f8f9fa; text-align: center; font-size: 12px; color: #666;">
        {{app_name}} - Σύστημα Διαχείρισης Εθελοντών
    </div>
</div>',
            'description' => 'Αποστέλλεται σε νέους χρήστες μετά την εγγραφή',
            'available_variables' => '{{app_name}}, {{user_name}}, {{user_email}}, {{login_url}}'
        ],
        [
            'code' => 'participation_approved',
            'name' => 'Έγκριση Συμμετοχής',
            'subject' => 'Η συμμετοχή σας εγκρίθηκε - {{mission_title}}',
            'body_html' => '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
    <div style="background: #27ae60; color: white; padding: 20px; text-align: center;">
        <h1>✓ Εγκρίθηκε!</h1>
    </div>
    <div style="padding: 30px; background: #fff;">
        <h2>Γεια σας {{user_name}},</h2>
        <p>Η συμμετοχή σας στη βάρδια εγκρίθηκε!</p>
        <p><strong>Αποστολή:</strong> {{mission_title}}</p>
        <p><strong>Βάρδια:</strong> {{shift_date}} ({{shift_time}})</p>
        <p><strong>Τοποθεσία:</strong> {{location}}</p>
        <p style="margin-top: 20px; padding: 15px; background: #d5f4e6; border-left: 4px solid #27ae60;">
            💡 Παρακαλούμε παρουσιαστείτε 10 λεπτά νωρίτερα.
        </p>
    </div>
</div>',
            'description' => 'Αποστέλλεται όταν εγκρίνεται η συμμετοχή εθελοντή σε βάρδια',
            'available_variables' => '{{app_name}}, {{user_name}}, {{mission_title}}, {{shift_date}}, {{shift_time}}, {{location}}'
        ],
        [
            'code' => 'participation_rejected',
            'name' => 'Απόρριψη Συμμετοχής',
            'subject' => 'Η συμμετοχή σας δεν εγκρίθηκε - {{mission_title}}',
            'body_html' => '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
    <div style="background: #e74c3c; color: white; padding: 20px; text-align: center;">
        <h1>Ενημέρωση Συμμετοχής</h1>
    </div>
    <div style="padding: 30px; background: #fff;">
        <h2>Γεια σας {{user_name}},</h2>
        <p>Δυστυχώς η αίτηση συμμετοχής σας δεν μπόρεσε να εγκριθεί.</p>
        <p><strong>Αποστολή:</strong> {{mission_title}}</p>
        <p><strong>Βάρδια:</strong> {{shift_date}}</p>
        <p>{{rejection_reason}}</p>
        <p style="margin-top: 20px;">
            Μπορείτε να δείτε άλλες διαθέσιμες βάρδιες <a href="{{missions_url}}">εδώ</a>.
        </p>
    </div>
</div>',
            'description' => 'Αποστέλλεται όταν απορρίπτεται η συμμετοχή εθελοντή',
            'available_variables' => '{{app_name}}, {{user_name}}, {{mission_title}}, {{shift_date}}, {{rejection_reason}}, {{missions_url}}'
        ],
        [
            'code' => 'shift_reminder',
            'name' => 'Υπενθύμιση Βάρδιας',
            'subject' => 'Υπενθύμιση: Αύριο έχετε βάρδια - {{mission_title}}',
            'body_html' => '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
    <div style="background: #f39c12; color: white; padding: 20px; text-align: center;">
        <h1>⏰ Υπενθύμιση</h1>
    </div>
    <div style="padding: 30px; background: #fff;">
        <h2>Γεια σας {{user_name}},</h2>
        <p>Σας υπενθυμίζουμε ότι αύριο έχετε βάρδια.</p>
        <p><strong>Αποστολή:</strong> {{mission_title}}</p>
        <p><strong>Ώρα:</strong> {{shift_time}}</p>
        <p><strong>Τοποθεσία:</strong> {{location}}</p>
        <p style="margin-top: 20px; padding: 15px; background: #fff3cd; border-left: 4px solid #f39c12;">
            ⚠️ Σε περίπτωση που δεν μπορείτε να παρευρεθείτε, παρακαλούμε ενημερώστε μας το συντομότερο.
        </p>
    </div>
</div>',
            'description' => 'Αποστέλλεται την προηγούμενη μέρα της βάρδιας',
            'available_variables' => '{{app_name}}, {{user_name}}, {{mission_title}}, {{shift_date}}, {{shift_time}}, {{location}}'
        ],
        [
            'code' => 'new_mission',
            'name' => 'Νέα Αποστολή',
            'subject' => 'Νέα αποστολή: {{mission_title}}',
            'body_html' => '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
    <div style="background: #3498db; color: white; padding: 20px; text-align: center;">
        <h1>🚀 Νέα Αποστολή!</h1>
    </div>
    <div style="padding: 30px; background: #fff;">
        <h2>{{mission_title}}</h2>
        <p>{{mission_description}}</p>
        <p><strong>Τοποθεσία:</strong> {{location}}</p>
        <p><strong>Περίοδος:</strong> {{start_date}} - {{end_date}}</p>
        <p style="text-align: center; margin-top: 30px;">
            <a href="{{mission_url}}" style="background: #3498db; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px;">Δείτε Λεπτομέρειες</a>
        </p>
    </div>
</div>',
            'description' => 'Αποστέλλεται σε εθελοντές όταν δημοσιεύεται νέα αποστολή',
            'available_variables' => '{{app_name}}, {{mission_title}}, {{mission_description}}, {{location}}, {{start_date}}, {{end_date}}, {{mission_url}}'
        ],
        [
            'code' => 'mission_canceled',
            'name' => 'Ακύρωση Αποστολής',
            'subject' => 'Ακυρώθηκε η αποστολή: {{mission_title}}',
            'body_html' => '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
    <div style="background: #e74c3c; color: white; padding: 20px; text-align: center;">
        <h1>❌ Ακύρωση Αποστολής</h1>
    </div>
    <div style="padding: 30px; background: #fff;">
        <h2>Γεια σας {{user_name}},</h2>
        <p>Σας ενημερώνουμε ότι η αποστολή <strong>{{mission_title}}</strong> ακυρώθηκε.</p>
        <p><strong>Λόγος:</strong> {{cancellation_reason}}</p>
        <p>Ζητούμε συγγνώμη για την όποια αναστάτωση.</p>
        <p style="margin-top: 20px;">
            Μπορείτε να βρείτε άλλες διαθέσιμες αποστολές <a href="{{missions_url}}">εδώ</a>.
        </p>
    </div>
</div>',
            'description' => 'Αποστέλλεται σε εθελοντές όταν ακυρώνεται αποστολή',
            'available_variables' => '{{app_name}}, {{user_name}}, {{mission_title}}, {{cancellation_reason}}, {{missions_url}}'
        ],
        [
            'code' => 'shift_canceled',
            'name' => 'Ακύρωση Βάρδιας',
            'subject' => 'Ακυρώθηκε η βάρδια: {{shift_date}} - {{mission_title}}',
            'body_html' => '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
    <div style="background: #e74c3c; color: white; padding: 20px; text-align: center;">
        <h1>❌ Ακύρωση Βάρδιας</h1>
    </div>
    <div style="padding: 30px; background: #fff;">
        <h2>Γεια σας {{user_name}},</h2>
        <p>Η βάρδια στις <strong>{{shift_date}}</strong> ({{shift_time}}) για την αποστολή <strong>{{mission_title}}</strong> ακυρώθηκε.</p>
        <p>{{cancellation_reason}}</p>
        <p style="margin-top: 20px;">
            Δείτε άλλες διαθέσιμες βάρδιες <a href="{{missions_url}}">εδώ</a>.
        </p>
    </div>
</div>',
            'description' => 'Αποστέλλεται σε εθελοντές όταν ακυρώνεται βάρδια',
            'available_variables' => '{{app_name}}, {{user_name}}, {{mission_title}}, {{shift_date}}, {{shift_time}}, {{cancellation_reason}}, {{missions_url}}'
        ],
        [
            'code' => 'points_earned',
            'name' => 'Κέρδος Πόντων',
            'subject' => 'Κερδίσατε {{points}} πόντους!',
            'body_html' => '<div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;">
    <div style="background: #27ae60; color: white; padding: 20px; text-align: center;">
        <h1>🎉 Συγχαρητήρια!</h1>
    </div>
    <div style="padding: 30px; background: #fff;">
        <h2>Γεια σας {{user_name}},</h2>
        <p>Ολοκληρώσατε τη βάρδια σας στην αποστολή <strong>{{mission_title}}</strong>!</p>
        <p style="font-size: 24px; color: #27ae60; text-align: center; margin: 30px 0;">
            <strong>+{{points}} πόντοι</strong>
        </p>
        <p><strong>Ημερομηνία:</strong> {{shift_date}}</p>
        <p style="text-align: center; margin-top: 30px;">
            Συνολικοί πόντοι: <strong style="font-size: 20px; color: #27ae60;">{{total_points}}</strong>
        </p>
        <p style="text-align: center;">
            <a href="{{leaderboard_url}}" style="background: #27ae60; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px;">Δείτε την Κατάταξη</a>
        </p>
    </div>
</div>',
            'description' => 'Αποστέλλεται όταν ο εθελοντής κερδίζει πόντους',
            'available_variables' => '{{app_name}}, {{user_name}}, {{points}}, {{mission_title}}, {{shift_date}}, {{total_points}}, {{leaderboard_url}}'
        ]
    ];

    $count = 0;
    foreach ($templates as $t) {
        dbInsert(
            "INSERT INTO email_templates (code, name, subject, body_html, description, available_variables, is_active, created_at, updated_at) 
             VALUES (?, ?, ?, ?, ?, ?, 1, NOW(), NOW())",
            [$t['code'], $t['name'], $t['subject'], $t['body_html'], $t['description'], $t['available_variables']]
        );
        echo "<p class='success'>✓ {$t['name']} ({$t['code']})</p>";
        $count++;
    }

    echo "<h2>Σύνδεση με Notification Settings...</h2>";

    // Notification settings
    $notifications = [
        ['code' => 'welcome', 'name' => 'Καλωσόρισμα', 'description' => 'Μετά την εγγραφή νέου χρήστη', 'enabled' => 1],
        ['code' => 'new_mission', 'name' => 'Νέα Αποστολή', 'description' => 'Όταν δημοσιεύεται νέα αποστολή', 'enabled' => 1],
        ['code' => 'participation_approved', 'name' => 'Έγκριση Συμμετοχής', 'description' => 'Όταν εγκρίνεται η συμμετοχή εθελοντή σε βάρδια', 'enabled' => 1],
        ['code' => 'participation_rejected', 'name' => 'Απόρριψη Συμμετοχής', 'description' => 'Όταν απορρίπτεται η συμμετοχή εθελοντή', 'enabled' => 1],
        ['code' => 'shift_reminder', 'name' => 'Υπενθύμιση Βάρδιας', 'description' => 'Μία μέρα πριν τη βάρδια', 'enabled' => 1],
        ['code' => 'mission_canceled', 'name' => 'Ακύρωση Αποστολής', 'description' => 'Όταν ακυρώνεται αποστολή', 'enabled' => 1],
        ['code' => 'shift_canceled', 'name' => 'Ακύρωση Βάρδιας', 'description' => 'Όταν ακυρώνεται βάρδια', 'enabled' => 1],
        ['code' => 'points_earned', 'name' => 'Κέρδος Πόντων', 'description' => 'Όταν ο εθελοντής κερδίζει πόντους', 'enabled' => 0],
    ];

    $countNotif = 0;
    foreach ($notifications as $n) {
        $templateId = dbFetchValue("SELECT id FROM email_templates WHERE code = ?", [$n['code']]);
        if ($templateId) {
            // Έλεγχος αν υπάρχει ήδη
            $exists = dbFetchValue("SELECT id FROM notification_settings WHERE code = ?", [$n['code']]);
            
            if ($exists) {
                // UPDATE existing
                dbExecute(
                    "UPDATE notification_settings SET name = ?, description = ?, email_enabled = ?, email_template_id = ?, updated_at = NOW() WHERE code = ?",
                    [$n['name'], $n['description'], $n['enabled'], $templateId, $n['code']]
                );
                echo "<p class='success'>✓ {$n['name']} ενημερώθηκε</p>";
            } else {
                // INSERT new
                dbInsert(
                    "INSERT INTO notification_settings (code, name, description, email_enabled, email_template_id, created_at, updated_at) 
                     VALUES (?, ?, ?, ?, ?, NOW(), NOW())",
                    [$n['code'], $n['name'], $n['description'], $n['enabled'], $templateId]
                );
                echo "<p class='success'>✓ {$n['name']} συνδέθηκε με template</p>";
            }
            $countNotif++;
        }
    }

    echo "<hr>";
    echo "<h2 class='success'>✅ Ολοκληρώθηκε!</h2>";
    echo "<p>✓ {$count} email templates εγκαταστάθηκαν</p>";
    echo "<p>✓ {$countNotif} notification settings συνδέθηκαν</p>";
    echo "<p><a href='settings.php'>Πήγαινε στις Ρυθμίσεις</a> | <a href='dashboard.php'>Dashboard</a></p>";

} catch (Exception $e) {
    echo "<h2 class='error'>❌ Σφάλμα!</h2>";
    echo "<p class='error'>" . h($e->getMessage()) . "</p>";
    echo "<pre>" . h($e->getTraceAsString()) . "</pre>";
}

echo "</body></html>";
