<?php
/**
 * Enable ZIP Extension Helper
 * This script helps enable the PHP zip extension needed for updates
 */

echo "<!DOCTYPE html>
<html lang='el'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Ενεργοποίηση ZIP Extension</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 800px; margin: 50px auto; padding: 20px; }
        .status { padding: 15px; margin: 20px 0; border-radius: 5px; }
        .success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .info { background: #d1ecf1; color: #0c5460; border: 1px solid #bee5eb; }
        pre { background: #f4f4f4; padding: 15px; border-radius: 5px; overflow-x: auto; }
        .step { margin: 20px 0; padding: 15px; border-left: 4px solid #007bff; }
        code { background: #f4f4f4; padding: 2px 6px; border-radius: 3px; }
    </style>
</head>
<body>
    <h1>Ενεργοποίηση PHP ZIP Extension</h1>";

// Check current status
$zipEnabled = class_exists('ZipArchive');
$phpIniPath = php_ini_loaded_file();

if ($zipEnabled) {
    echo "<div class='status success'>
        <strong>✓ Η επέκταση ZIP είναι ήδη ενεργοποιημένη!</strong><br>
        Μπορείτε να χρησιμοποιήσετε το σύστημα ενημερώσεων.
    </div>";
} else {
    echo "<div class='status error'>
        <strong>✗ Η επέκταση ZIP δεν είναι ενεργοποιημένη</strong>
    </div>";
}

echo "<div class='info status'>
    <strong>Πληροφορίες PHP:</strong><br>
    <strong>PHP Version:</strong> " . phpversion() . "<br>
    <strong>php.ini Location:</strong> " . ($phpIniPath ?: 'Δεν βρέθηκε') . "
</div>";

if (!$zipEnabled) {
    echo "<h2>Οδηγίες Ενεργοποίησης</h2>
    
    <div class='step'>
        <h3>Βήμα 1: Βρείτε το αρχείο php.ini</h3>
        <p>Το αρχείο php.ini βρίσκεται στο:</p>
        <pre>" . ($phpIniPath ?: 'C:\\xampp\\php\\php.ini (για XAMPP)') . "</pre>
    </div>
    
    <div class='step'>
        <h3>Βήμα 2: Επεξεργαστείτε το php.ini</h3>
        <p>Ανοίξτε το αρχείο με έναν text editor (π.χ. Notepad++) και βρείτε τη γραμμή:</p>
        <pre>;extension=zip</pre>
        <p>Αφαιρέστε το <code>;</code> από την αρχή της γραμμής για να γίνει:</p>
        <pre>extension=zip</pre>
    </div>
    
    <div class='step'>
        <h3>Βήμα 3: Επανεκκινήστε τον Apache</h3>
        <p>Από το XAMPP Control Panel:</p>
        <ol>
            <li>Κάντε κλικ στο κουμπί <strong>Stop</strong> δίπλα στο Apache</li>
            <li>Περιμένετε να σταματήσει πλήρως</li>
            <li>Κάντε κλικ στο κουμπί <strong>Start</strong> για να ξεκινήσει ξανά</li>
        </ol>
    </div>
    
    <div class='step'>
        <h3>Βήμα 4: Επαληθεύστε</h3>
        <p>Ανανεώστε αυτή τη σελίδα για να επαληθεύσετε ότι η επέκταση ενεργοποιήθηκε επιτυχώς.</p>
        <p><a href='enable_zip_extension.php' style='display: inline-block; padding: 10px 20px; background: #007bff; color: white; text-decoration: none; border-radius: 5px;'>Ανανέωση</a></p>
    </div>";
}

echo "<hr>
<h2>Εναλλακτική Λύση</h2>
<p>Αν δεν μπορείτε να επεξεργαστείτε το php.ini, μπορείτε:</p>
<ol>
    <li>Να κατεβάσετε τις ενημερώσεις χειροκίνητα από το GitHub</li>
    <li>Να τις εξάγετε με έναν συμπιεστή αρχείων (WinRAR, 7-Zip)</li>
    <li>Να τις αντιγράψετε χειροκίνητα στο φάκελο <code>c:\\xampp\\htdocs\\volunteerops</code></li>
</ol>

</body>
</html>";
