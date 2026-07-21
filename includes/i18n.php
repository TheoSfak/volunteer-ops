<?php
/**
 * VolunteerOps - Action Room i18n
 * Scoped translation helpers for war-room.php + its endpoints (not app-wide).
 * Strings live in includes/lang/*.php, each returning ['el' => [...], 'en' => [...]].
 */

if (!defined('VOLUNTEEROPS')) {
    die('Direct access not permitted');
}

const SUPPORTED_LANGUAGES = ['el', 'en'];
const DEFAULT_LANGUAGE = 'el';

/**
 * Loads and caches a lang file's combined ['el'=>[...], 'en'=>[...]] array.
 */
function loadLangStrings(string $domain): array {
    static $cache = [];
    if (!isset($cache[$domain])) {
        $path = __DIR__ . "/lang/{$domain}.php";
        $cache[$domain] = file_exists($path) ? require $path : ['el' => [], 'en' => []];
    }
    return $cache[$domain];
}

/**
 * Translate $key from the 'war-room' domain for $lang (defaults to the current
 * viewer's language). Falls back el -> raw key, so a missing translation is
 * never a blank string or a broken page — at worst it's visibly wrong.
 */
function t(string $key, array $vars = [], ?string $lang = null): string {
    if ($lang === null) {
        $user = getCurrentUser();
        $lang = $user['language'] ?? DEFAULT_LANGUAGE;
    }
    $strings = loadLangStrings('war-room');
    $text = $strings[$lang][$key] ?? $strings[DEFAULT_LANGUAGE][$key] ?? $key;
    foreach ($vars as $k => $v) {
        $text = str_replace('{' . $k . '}', (string) $v, $text);
    }
    return $text;
}

/**
 * Single user's language, per-request cached (mirrors getCurrentUser()'s own caching).
 */
function getUserLanguage(int $userId): string {
    static $cache = [];
    if (!isset($cache[$userId])) {
        $lang = dbFetchValue("SELECT language FROM users WHERE id = ?", [$userId]);
        $cache[$userId] = in_array($lang, SUPPORTED_LANGUAGES, true) ? $lang : DEFAULT_LANGUAGE;
    }
    return $cache[$userId];
}

/**
 * SHORTAGE_TYPE_LABELS/SHORTAGE_SEVERITY_LABELS (config.php) stay untouched —
 * they're also consumed by computeMissionResponseReport(), shared with the
 * out-of-scope mission-stats.php/mission-report-print.php. These wrap them
 * with a translated lookup that falls back to the original Greek constant if
 * a key is ever missing, so a future shortage type added to config.php but
 * forgotten here still renders correctly instead of a raw "shortage.type.x".
 */
function shortageTypeLabel(string $type, ?string $lang = null): string {
    $key = 'shortage.type.' . $type;
    $translated = t($key, [], $lang);
    return $translated !== $key ? $translated : (SHORTAGE_TYPE_LABELS[$type] ?? $type);
}

function shortageSeverityLabel(string $severity, ?string $lang = null): string {
    $key = 'shortage.severity.' . $severity;
    $translated = t($key, [], $lang);
    return $translated !== $key ? $translated : (SHORTAGE_SEVERITY_LABELS[$severity] ?? $severity);
}

/**
 * Batch version for notification fan-out: one query for however many recipients,
 * never N+1. Returns [user_id => 'el'|'en'] for every id in $userIds.
 */
function getUserLanguages(array $userIds): array {
    $userIds = array_values(array_unique(array_map('intval', $userIds)));
    if (empty($userIds)) return [];

    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    $rows = dbFetchAll("SELECT id, language FROM users WHERE id IN ($placeholders)", $userIds);

    $result = [];
    foreach ($userIds as $id) {
        $result[$id] = DEFAULT_LANGUAGE;
    }
    foreach ($rows as $row) {
        $lang = $row['language'];
        $result[(int) $row['id']] = in_array($lang, SUPPORTED_LANGUAGES, true) ? $lang : DEFAULT_LANGUAGE;
    }
    return $result;
}
