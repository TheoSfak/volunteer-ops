<?php
/**
 * VolunteerOps - the pieces of the Action Room page's script that travel as files of their own.
 *
 * The page (war-room.php) is one long HTML document whose script is far bigger than the rest of it, and all of it is
 * sent again on every open and every reload. A piece that never changes for a viewer can instead be a file the phone
 * keeps: it is downloaded once, and every later open of the page reads it from the phone. This file builds those
 * pieces. war-room-js.php sends them; war-room.php only says where they are.
 *
 * Today there is one piece, "strings": the translation tables (WR_STRINGS and WR_STRINGS_FALLBACK), about 70 KB
 * compressed, a different text per language but the same for every mission and every user who reads that language.
 *
 * Two rules keep a file that is cached for a year from ever being the wrong one:
 *  - its address carries a version made of what it is built from (the translation file and this file), computed here
 *    by the page and by the loader alike, so a changed translation is a new address, never a stale file at the old one;
 *  - the loader sends the long cache lifetime only when the version asked for is the one it would build now. If the
 *    two differ (an update landed between the page and the file) it still answers, with the current text, but tells
 *    the phone not to keep it.
 *
 * Nothing in here starts a session or touches the database, so the loader that includes it can stay as light as a
 * static file with a few headers: a session started by a script file would hold the same session lock that the page's
 * poll needs. The functions that read the translations (loadLangStrings(), SUPPORTED_LANGUAGES, DEFAULT_LANGUAGE) come
 * from includes/i18n.php, which both callers include first.
 */

/** The pieces that exist. Anything else asked of the loader is a 404. */
function wrBundleExists(string $bundle): bool
{
    return $bundle === 'strings';
}

/** The file the translation tables come from; the version of the "strings" piece follows it. */
function wrBundleLangFile(): string
{
    return __DIR__ . '/lang/war-room.php';
}

/**
 * What a piece is built from, as 16 hex characters; '' when that cannot be told (a file that cannot be read), which
 * callers read as "do not serve this as a file". The optional paths are for tests that build from other files.
 */
function wrBundleVersion(string $bundle, string $lang, ?string $langFile = null, ?string $codeFile = null): string
{
    $langSha = @sha1_file($langFile ?? wrBundleLangFile());
    $codeSha = @sha1_file($codeFile ?? __FILE__);
    if ($langSha === false || $codeSha === false) {
        return '';
    }
    return substr(sha1($langSha . '|' . $codeSha . '|' . $bundle . '|' . $lang), 0, 16);
}

/**
 * The script text of a piece, or null when there is no such piece or language (or the translations cannot be encoded).
 *
 * "strings" is what the page used to print in the middle of its script: the viewer's table as WR_STRINGS and, for the
 * fallback, either the default language's table or - for a viewer who reads the default language - the same table
 * said once (`const WR_STRINGS_FALLBACK = WR_STRINGS;`, which is what the light script setting already did).
 * The flags are the page's own. $all is the ['el' => [...], 'en' => [...]] array; tests pass their own.
 */
function wrBundleSource(string $bundle, string $lang, ?array $all = null): ?string
{
    if (!wrBundleExists($bundle) || !in_array($lang, SUPPORTED_LANGUAGES, true)) {
        return null;
    }
    $all = $all ?? loadLangStrings('war-room');
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    $own = json_encode($all[$lang] ?? [], $flags);
    if ($own === false) {
        return null;
    }
    $js = 'const WR_STRINGS = ' . $own . ";\n";
    if ($lang === DEFAULT_LANGUAGE) {
        return $js . "const WR_STRINGS_FALLBACK = WR_STRINGS;\n";
    }
    $fallback = json_encode($all[DEFAULT_LANGUAGE] ?? [], $flags);
    if ($fallback === false) {
        return null;
    }
    return $js . 'const WR_STRINGS_FALLBACK = ' . $fallback . ";\n";
}

/**
 * The address of a piece, for the page's <script src>; '' when it cannot be offered (an unknown piece or language, or
 * a version that cannot be computed), in which case the page keeps the piece inline. $baseUrl has no trailing slash.
 * The "&" are plain: the page escapes the whole address.
 */
function wrBundleUrl(string $baseUrl, string $bundle, string $lang): string
{
    if (!wrBundleExists($bundle) || !in_array($lang, SUPPORTED_LANGUAGES, true)) {
        return '';
    }
    $version = wrBundleVersion($bundle, $lang);
    if ($version === '') {
        return '';
    }
    return $baseUrl . '/war-room-js.php?b=' . $bundle . '&l=' . $lang . '&v=' . $version;
}

/** Whether an Accept-Encoding header offers gzip (a gzip token whose q is not 0). */
function wrBundleAcceptsGzip(string $acceptEncoding): bool
{
    foreach (explode(',', $acceptEncoding) as $part) {
        $bits = explode(';', $part);
        $name = strtolower(trim($bits[0]));
        if ($name !== 'gzip' && $name !== 'x-gzip') {
            continue;
        }
        $q = 1.0;
        foreach (array_slice($bits, 1) as $param) {
            $kv = explode('=', $param, 2);
            if (count($kv) === 2 && strtolower(trim($kv[0])) === 'q') {
                $q = (float) trim($kv[1]);
            }
        }
        return $q > 0.0;
    }
    return false;
}

/** Whether an If-None-Match header names $etag (strong or weak form, one of a list, or "*"). */
function wrBundleEtagMatches(string $ifNoneMatch, string $etag): bool
{
    if (trim($ifNoneMatch) === '*') {
        return true;
    }
    foreach (explode(',', $ifNoneMatch) as $candidate) {
        $candidate = trim($candidate);
        if (strncmp($candidate, 'W/', 2) === 0) {
            $candidate = substr($candidate, 2);
        }
        if ($candidate !== '' && $candidate === $etag) {
            return true;
        }
    }
    return false;
}

/**
 * Answers one request for a piece, as data - ['status' => int, 'headers' => [name => value], 'body' => string] - so
 * the loader only has to send it and tests can read every part of it. $query is $_GET, $server is $_SERVER,
 * $cacheDir is where the compressed text is kept between requests (made on first use; if it cannot be written the
 * answer is simply built each time).
 *
 *  - 404 for a piece or language that does not exist, 405 for a method other than GET and HEAD;
 *  - the version asked for is the current one: the text, with a year of cache lifetime, an ETag, "Vary: Accept-Encoding",
 *    and 304 for a browser that already holds it. Compressed (gzip, level 9) for a client that accepts it - the
 *    compressed text is what is kept on disk, written whole under a temporary name and moved into place - and plain
 *    text for one that does not;
 *  - any other version: the current text, compressed or not as asked, with "Cache-Control: no-store".
 */
function wrBundleRespond(array $query, array $server, string $cacheDir, ?int $now = null): array
{
    $now = $now ?? time();
    $method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));
    $plain = ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff'];
    if ($method !== 'GET' && $method !== 'HEAD') {
        return ['status' => 405, 'headers' => $plain + ['Allow' => 'GET, HEAD'], 'body' => ''];
    }

    // ?b[]=x is an array, not text: it asks for nothing that exists
    $bundle = is_string($query['b'] ?? null) ? $query['b'] : '';
    $lang = is_string($query['l'] ?? null) ? $query['l'] : '';
    $asked = is_string($query['v'] ?? null) ? $query['v'] : '';
    if (!wrBundleExists($bundle) || !in_array($lang, SUPPORTED_LANGUAGES, true)) {
        return ['status' => 404, 'headers' => $plain, 'body' => $method === 'HEAD' ? '' : "Not found\n"];
    }

    $current = wrBundleVersion($bundle, $lang);
    $keep = $current !== '' && hash_equals($current, $asked);
    $gzip = function_exists('gzencode') && wrBundleAcceptsGzip((string) ($server['HTTP_ACCEPT_ENCODING'] ?? ''));

    $headers = [
        'Content-Type' => 'application/javascript; charset=utf-8',
        'X-Content-Type-Options' => 'nosniff',
        'Vary' => 'Accept-Encoding',
    ];
    $etag = '';
    if ($keep) {
        $etag = '"wr-' . $bundle . '-' . $lang . '-' . $current . ($gzip ? '-gz' : '') . '"';
        $headers['Cache-Control'] = 'public, max-age=31536000, immutable';
        $headers['ETag'] = $etag;
        if (wrBundleEtagMatches((string) ($server['HTTP_IF_NONE_MATCH'] ?? ''), $etag)) {
            return ['status' => 304, 'headers' => $headers, 'body' => ''];
        }
    } else {
        $headers['Cache-Control'] = 'no-store';
    }

    $body = null;
    $cacheFile = $cacheDir . '/' . $bundle . '-' . $lang . '-' . $current . '.js.gz';
    if ($keep && $gzip) {
        $kept = is_file($cacheFile) ? @file_get_contents($cacheFile) : false;
        if (is_string($kept) && strlen($kept) > 18 && strncmp($kept, "\x1f\x8b", 2) === 0) {
            $body = $kept;
        }
    }
    if ($body === null) {
        $js = wrBundleSource($bundle, $lang);
        if ($js === null) {
            return ['status' => 500, 'headers' => $plain, 'body' => $method === 'HEAD' ? '' : "Could not build this file\n"];
        }
        $body = $gzip ? gzencode($js, 9) : $js;
        if ($body === false) {
            $gzip = false;
            $body = $js;
            if ($keep) {
                $headers['ETag'] = '"wr-' . $bundle . '-' . $lang . '-' . $current . '"';
            }
        } elseif ($keep && $gzip) {
            wrBundleKeep($cacheDir, $cacheFile, $bundle . '-' . $lang, $body, $now);
        }
    }
    if ($gzip) {
        $headers['Content-Encoding'] = 'gzip';
    }
    $headers['Content-Length'] = (string) strlen($body);
    return ['status' => 200, 'headers' => $headers, 'body' => $method === 'HEAD' ? '' : $body];
}

/**
 * Writes the compressed text to $cacheFile (in the folder $cacheDir, made if needed) and tidies the folder: files of
 * the same piece and language ($stem) that belong to other versions and are over a day old go, as do leftover
 * temporary files over an hour old. Every failure is silent: the answer does not depend on the file being kept.
 */
function wrBundleKeep(string $cacheDir, string $cacheFile, string $stem, string $compressed, int $now): void
{
    if (!is_dir($cacheDir) && !@mkdir($cacheDir, 0755, true) && !is_dir($cacheDir)) {
        return;
    }
    $tmp = $cacheFile . '.' . getmypid() . '.' . bin2hex(random_bytes(3)) . '.tmp';
    if (@file_put_contents($tmp, $compressed, LOCK_EX) === strlen($compressed) && @rename($tmp, $cacheFile)) {
        foreach (glob($cacheDir . '/' . $stem . '-*.js.gz') ?: [] as $other) {
            if ($other !== $cacheFile && (int) @filemtime($other) < $now - 86400) {
                @unlink($other);
            }
        }
    } else {
        @unlink($tmp);
    }
    foreach (glob($cacheDir . '/' . $stem . '-*.tmp') ?: [] as $leftover) {
        if ((int) @filemtime($leftover) < $now - 3600) {
            @unlink($leftover);
        }
    }
}
