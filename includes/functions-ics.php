<?php
/**
 * VolunteerOps - Shared RFC 5545 (iCalendar) building blocks.
 * Extracted from api-shifts-calendar-ics.php once briefing-view.php became
 * a second consumer, rather than duplicating the fold/escape helpers and
 * the Europe/Athens VTIMEZONE block a second time. Loaded on demand by
 * each consumer (like includes/inventory-functions.php), not required
 * globally from bootstrap.php.
 */

if (!defined('VOLUNTEEROPS')) {
    die('Direct access not permitted');
}

/**
 * Fold long ICS lines at 75 octets (RFC 5545 §3.1).
 */
function icsFold(string $line): string {
    $out   = '';
    $bytes = 0;
    $chars = mb_str_split($line);
    foreach ($chars as $ch) {
        $len = strlen($ch); // byte length
        if ($bytes + $len > 75) {
            $out  .= "\r\n ";
            $bytes = 1; // leading space counts
        }
        $out   .= $ch;
        $bytes += $len;
    }
    return $out;
}

/**
 * Escape ICS text values (commas, semicolons, backslashes, newlines).
 */
function icsText(string $val): string {
    $val = str_replace('\\', '\\\\', $val);
    $val = str_replace(',',  '\\,',  $val);
    $val = str_replace(';',  '\\;',  $val);
    $val = str_replace("\n", '\\n',  $val);
    return $val;
}

/**
 * Format a MySQL datetime as iCalendar DATETIME (local, no UTC suffix).
 */
function icsDate(string $mysqlDt): string {
    return date('Ymd\THis', strtotime($mysqlDt));
}

/**
 * VTIMEZONE block lines for Europe/Athens (EET/EEST) — the only timezone
 * this app's ICS exports need, since every mission/shift datetime is
 * already stored and displayed in Greek local time.
 */
function icsAthensTimezoneLines(): array {
    return [
        'BEGIN:VTIMEZONE',
        'TZID:Europe/Athens',
        'BEGIN:STANDARD',
        'DTSTART:19701025T040000',
        'RRULE:FREQ=YEARLY;BYDAY=-1SU;BYMONTH=10',
        'TZNAME:EET',
        'TZOFFSETFROM:+0300',
        'TZOFFSETTO:+0200',
        'END:STANDARD',
        'BEGIN:DAYLIGHT',
        'DTSTART:19700329T030000',
        'RRULE:FREQ=YEARLY;BYDAY=-1SU;BYMONTH=3',
        'TZNAME:EEST',
        'TZOFFSETFROM:+0200',
        'TZOFFSETTO:+0300',
        'END:DAYLIGHT',
        'END:VTIMEZONE',
    ];
}
