<?php
// Quick fix for install.php column names
$file = __DIR__ . '/install.php';
$content = file_get_contents($file);

// Fix all column name mismatches
$content = str_replace("'start_date'", "'start_datetime'", $content);
$content = str_replace("'end_date'", "'end_datetime'", $content);
$content = str_replace('start_date', 'start_datetime', $content);
$content = str_replace('end_date', 'end_datetime', $content);
$content = str_replace('volunteer_id', 'user_id', $content);
$content = str_replace("'source_type'", "'reason'", $content);
$content = str_replace("'source_id'", "'pointable_id'", $content);

file_put_contents($file, $content);
echo "✅ Fixed install.php column names\n";
echo "- start_date → start_datetime\n";
echo "- end_date → end_datetime\n";
echo "- volunteer_id → user_id\n";
echo "- source_type → reason\n";
echo "- source_id → pointable_id\n";
