<?php
/**
 * One-time fix: correct garbled Greek text in shift_swap email templates
 * Run once then delete.
 */
require_once __DIR__ . '/bootstrap.php';
requireRole([ROLE_SYSTEM_ADMIN]);

$templates = [
    'shift_swap_requested' => [
        'name'        => 'Αίτημα Αντικατάστασης Βάρδιας',
        'subject'     => 'Αίτημα αντικατάστασης για αποστολή {{mission_title}}',
        'description' => 'Αποστέλλεται στον εθελοντή που ζητήθηκε να καλύψει τη βάρδια',
        'vars'        => '{{user_name}}, {{requester_name}}, {{mission_title}}, {{shift_date}}, {{shift_time}}, {{message}}',
        'html'        => '<div style="font-family:Arial,sans-serif"><div style="background:#8e44ad;color:#fff;padding:16px 20px"><h2 style="margin:0">&#128257; Αίτημα Αντικατάστασης</h2></div><div style="padding:20px"><p>Γεια, <strong>{{user_name}}</strong>!</p><p>Ο/Η <strong>{{requester_name}}</strong> σας ζητά να τον/την αντικαταστήσετε στη βάρδια:</p><ul><li><strong>Αποστολή:</strong> {{mission_title}}</li><li><strong>Ημ/νία:</strong> {{shift_date}}</li><li><strong>Ώρα:</strong> {{shift_time}}</li></ul><p>Συνδεθείτε για να αποδεχτείτε ή να αρνηθείτε το αίτημα.</p></div></div>',
    ],
    'shift_swap_accepted' => [
        'name'        => 'Αποδοχή Αιτήματος Αντικατάστασης',
        'subject'     => 'Ο/Η {{replacement_name}} αποδέχτηκε το αίτημα αντικατάστασης',
        'description' => 'Αποστέλλεται στον αιτούντα όταν ο αντικατάστατης αποδεχτεί',
        'vars'        => '{{user_name}}, {{replacement_name}}, {{mission_title}}, {{shift_date}}, {{shift_time}}',
        'html'        => '<div style="font-family:Arial,sans-serif"><div style="background:#27ae60;color:#fff;padding:16px 20px"><h2 style="margin:0">&#10003; Αποδοχή Αντικατάστασης</h2></div><div style="padding:20px"><p>Γεια, <strong>{{user_name}}</strong>!</p><p>Ο/Η <strong>{{replacement_name}}</strong> αποδέχτηκε το αίτημά σας για αντικατάσταση στη βάρδια:</p><ul><li><strong>Αποστολή:</strong> {{mission_title}}</li><li><strong>Ημ/νία:</strong> {{shift_date}}</li><li><strong>Ώρα:</strong> {{shift_time}}</li></ul><p>Αναμένεται η τελική έγκριση από τον διαχειριστή.</p></div></div>',
    ],
    'shift_swap_approved' => [
        'name'        => 'Έγκριση Αντικατάστασης Βάρδιας',
        'subject'     => 'Η αντικατάσταση για {{mission_title}} εγκρίθηκε',
        'description' => 'Αποστέλλεται και στους δύο εθελοντές όταν ο διαχειριστής εγκρίνει',
        'vars'        => '{{user_name}}, {{replacement_name}}, {{mission_title}}, {{shift_date}}, {{shift_time}}',
        'html'        => '<div style="font-family:Arial,sans-serif"><div style="background:#2980b9;color:#fff;padding:16px 20px"><h2 style="margin:0">&#9989; Αντικατάσταση Εγκρίθηκε</h2></div><div style="padding:20px"><p>Γεια, <strong>{{user_name}}</strong>!</p><p>Η αντικατάσταση για τη βάρδια εγκρίθηκε από τον διαχειριστή:</p><ul><li><strong>Αποστολή:</strong> {{mission_title}}</li><li><strong>Ημ/νία:</strong> {{shift_date}}</li><li><strong>Ώρα:</strong> {{shift_time}}</li></ul></div></div>',
    ],
];

$fixed = 0;
foreach ($templates as $code => $t) {
    $rows = dbExecute(
        "UPDATE email_templates
         SET name = ?, subject = ?, description = ?, available_variables = ?, body_html = ?
         WHERE code = ?",
        [$t['name'], $t['subject'], $t['description'], $t['vars'], $t['html'], $code]
    );
    echo "<p>✓ <strong>{$code}</strong>: {$t['name']}</p>";
    $fixed += $rows;
}

echo "<p><strong>Done. {$fixed} row(s) updated.</strong></p>";
echo "<p><a href='email-template-edit.php?code=shift_swap_requested'>Preview template</a></p>";
