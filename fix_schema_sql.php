<?php
/**
 * Fix schema.sql by properly escaping email template HTML
 * Run this once, then re-upload schema.sql to server
 */

$schemaFile = __DIR__ . '/sql/schema.sql';
$sql = file_get_contents($schemaFile);

// Read current templates from schema (we'll extract and re-insert properly)
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
        <div style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0;">
            <p><strong>Αποστολή:</strong> {{mission_title}}</p>
            <p><strong>Βάρδια:</strong> {{shift_date}} ({{shift_time}})</p>
            <p><strong>Τοποθεσία:</strong> {{location}}</p>
        </div>
        <p>Παρακαλούμε να είστε στην τοποθεσία έγκαιρα.</p>
    </div>
    <div style="padding: 15px; background: #f8f9fa; text-align: center; font-size: 12px; color: #666;">
        {{app_name}}
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
        <h1>Απόρριψη Αίτησης</h1>
    </div>
    <div style="padding: 30px; background: #fff;">
        <h2>Γεια σας {{user_name}},</h2>
        <p>Λυπούμαστε, αλλά η αίτησή σας για τη βάρδια δεν εγκρίθηκε.</p>
        <div style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0;">
            <p><strong>Αποστολή:</strong> {{mission_title}}</p>
            <p><strong>Βάρδια:</strong> {{shift_date}} ({{shift_time}})</p>
            <p><strong>Λόγος:</strong> {{rejection_reason}}</p>
        </div>
        <p>Μπορείτε να δηλώσετε συμμετοχή σε άλλες διαθέσιμες βάρδιες.</p>
    </div>
    <div style="padding: 15px; background: #f8f9fa; text-align: center; font-size: 12px; color: #666;">
        {{app_name}}
    </div>
</div>',
        'description' => 'Αποστέλλεται όταν απορρίπτεται η συμμετοχή εθελοντή',
        'available_variables' => '{{app_name}}, {{user_name}}, {{mission_title}}, {{shift_date}}, {{shift_time}}, {{rejection_reason}}'
    ]
];

// Function to properly escape for SQL
function sqlEscape($str) {
    return str_replace("'", "''", $str);
}

// Find the email_templates INSERT line
$pattern = '/INSERT INTO `email_templates`.*?VALUES.*?;/s';

if (preg_match($pattern, $sql, $matches)) {
    // Build new INSERT statement
    $values = [];
    foreach ($templates as $t) {
        $values[] = sprintf(
            "('%s', '%s', '%s', '%s', '%s', '%s')",
            sqlEscape($t['code']),
            sqlEscape($t['name']),
            sqlEscape($t['subject']),
            sqlEscape($t['body_html']),
            sqlEscape($t['description']),
            sqlEscape($t['available_variables'])
        );
    }
    
    $newInsert = "INSERT INTO `email_templates` (`code`, `name`, `subject`, `body_html`, `description`, `available_variables`) VALUES\n"
        . implode(",\n\n", $values) . ";";
    
    // Replace in SQL
    $sql = preg_replace($pattern, $newInsert, $sql);
    
    // Write back
    file_put_contents($schemaFile, $sql);
    
    echo "✅ schema.sql fixed successfully!\n";
    echo "Email templates properly escaped.\n";
    echo "Re-upload sql/schema.sql to your server.\n";
} else {
    echo "❌ Could not find email_templates INSERT statement.\n";
}
