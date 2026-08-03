<?php
/**
 * Used by install.php to load sql/schema.sql and sql/inventory_schema.sql,
 * and by tests/FreshInstallTest.php to verify those files load cleanly.
 * Standalone (no other app dependencies) so both can require it safely.
 */

// Splits a .sql file's contents into individual statements on top-level ';'
// characters only — unlike a plain explode(';', ...), this ignores ';' that
// appears inside a '...', "..." or `...` literal (e.g. CSS/HTML seed data
// like "font-family: Arial, sans-serif;"), which a naive split would cut
// mid-statement and turn into a syntax error. Also skips over '--' line
// comments itself (needed even though callers separately strip column-0 '--'
// comments before calling this: an indented '-- ...' comment containing an
// odd number of apostrophes, e.g. "-- data's own 'thing'", would otherwise
// desync the quote-tracking below for the rest of the file). Does not handle
// '#' or '/* */' comments — not used anywhere in this project's .sql files.
function splitSqlStatements($sql) {
    $statements = [];
    $current = '';
    $len = strlen($sql);
    $quoteChar = null; // null, or the ', " or ` we're currently inside

    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];

        if ($quoteChar === null && $ch === '-' && $i + 1 < $len && $sql[$i + 1] === '-') {
            $nl = strpos($sql, "\n", $i);
            if ($nl === false) {
                break; // rest of the file is a trailing comment
            }
            $current .= "\n";
            $i = $nl;
            continue;
        }

        $current .= $ch;

        if ($quoteChar !== null) {
            if ($ch === '\\' && $quoteChar !== '`') {
                if ($i + 1 < $len) {
                    $current .= $sql[++$i];
                }
            } elseif ($ch === $quoteChar) {
                if ($i + 1 < $len && $sql[$i + 1] === $quoteChar) {
                    $current .= $sql[++$i]; // doubled quote = literal escaped quote
                } else {
                    $quoteChar = null;
                }
            }
        } else {
            if ($ch === "'" || $ch === '"' || $ch === '`') {
                $quoteChar = $ch;
            } elseif ($ch === ';') {
                $statements[] = $current;
                $current = '';
            }
        }
    }
    if (trim($current) !== '') {
        $statements[] = $current;
    }

    return $statements;
}
