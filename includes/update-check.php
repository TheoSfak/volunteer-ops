<?php
/**
 * VolunteerOps - the "is there a newer release?" check of update.php.
 *
 * update.php used to ask GitHub every time the page was opened, waiting up to
 * 30 s for each of two requests while holding the administrator's session file
 * and a database connection. On a host that cannot reach api.github.com that
 * was a minute of every other tab of the same person frozen behind one page
 * view. The answer changes a few times a month, so it is asked for once every
 * few hours (or when «Έλεγχος Τώρα» is pressed), and a failed attempt is
 * remembered for a few minutes so a blocked host is not retried on every view.
 *
 * The answer is kept in a row of the settings table (no table of its own).
 */

if (!defined('VOLUNTEEROPS')) {
    die('Direct access not permitted');
}

const UPDATE_CHECK_KEY = 'update_release_cache';
/** How long a good answer is trusted, and how long a failure is remembered, in seconds. */
const UPDATE_CHECK_OK_TTL = 21600;
const UPDATE_CHECK_FAIL_TTL = 600;
/** Seconds one request to GitHub may take. */
const UPDATE_CHECK_TIMEOUT = 5;
/** Release notes longer than this are cut when stored; the page shows far less. */
const UPDATE_CHECK_BODY_MAX = 2000;

/** The status code of the last response in $http_response_header, or 0 when there was none. */
function updateCheckHttpStatus(array $headers): int {
    $status = 0;
    foreach ($headers as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string) $line, $m)) $status = (int) $m[1]; // the last one wins: redirects add lines
    }
    return $status;
}

/** What was stored: ['at' => Unix time, 'release' => array|null, 'error' => string|null], or null. */
function updateCheckStored(): ?array {
    $raw = dbFetchValue("SELECT setting_value FROM settings WHERE setting_key = ?", [UPDATE_CHECK_KEY]);
    if (!is_string($raw) || $raw === '') return null;
    $data = json_decode($raw, true);
    return is_array($data) && isset($data['at']) ? $data : null;
}

function updateCheckStore(?array $release, ?string $error, int $now): void {
    if ($release !== null && isset($release['body']) && is_string($release['body'])) {
        $release['body'] = mb_substr($release['body'], 0, UPDATE_CHECK_BODY_MAX);
    }
    dbExecute(
        "INSERT INTO settings (setting_key, setting_value, created_at, updated_at) VALUES (?, ?, NOW(), NOW())
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()",
        [UPDATE_CHECK_KEY, json_encode(['at' => $now, 'release' => $release, 'error' => $error], JSON_UNESCAPED_UNICODE)]
    );
}

/**
 * The latest release as getLatestRelease() returns it (or ['error' => ...]),
 * from the stored answer while it is recent and from $fetch otherwise.
 * $force skips the stored answer: it is what the button does.
 */
function updateCheckCached(bool $force, callable $fetch, ?int $now = null): array {
    $now = $now ?? time();
    $stored = $force ? null : updateCheckStored();
    if ($stored !== null) {
        $age = $now - (int) $stored['at'];
        if (is_array($stored['release'] ?? null) && $age >= 0 && $age < UPDATE_CHECK_OK_TTL) {
            return $stored['release'];
        }
        if (is_string($stored['error'] ?? null) && $age >= 0 && $age < UPDATE_CHECK_FAIL_TTL) {
            return ['error' => $stored['error'] . ' (Νέα αυτόματη προσπάθεια σε λίγα λεπτά, ή πατήστε «Έλεγχος Τώρα».)'];
        }
    }

    $result = $fetch();
    if (!is_array($result)) $result = ['error' => 'Αδυναμία ανάγνωσης της απάντησης του GitHub.'];
    if (isset($result['error'])) {
        updateCheckStore(null, (string) $result['error'], $now);
        return $result;
    }
    updateCheckStore($result, null, $now);
    return $result;
}
