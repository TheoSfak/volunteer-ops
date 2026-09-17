<?php
/**
 * VolunteerOps — AI provider client.
 *
 * One narrow job: take a list of chat messages, hand them to whichever
 * OpenAI-compatible provider the admin picked in Settings, and give back the
 * parsed answer. Nothing in here knows what a mission is.
 *
 * WHY OpenAI-compatible and nothing else: both providers this app offers
 * (DeepSeek and Google Gemini) speak the same /chat/completions dialect, so
 * switching between them — or away from both, to an EU-hosted endpoint — is a
 * base-URL string, not a rewrite. That is the whole exit strategy, and it
 * costs nothing to keep today.
 *
 * Follows includes/weather.php's contract exactly: no key configured means
 * the feature is ABSENT, not broken — every entry point returns null/ok=false
 * and the caller renders nothing. An admin never has to "turn off" a feature
 * they never configured.
 *
 * NEVER call anything in this file from war-room.php's 5-second poll. A
 * 20-second HTTP call there holds one DB connection per open tab; see the
 * connection-exhaustion notes on that page. On-demand endpoints only.
 */

// ─── Providers ───────────────────────────────────────────────────────────────
// 'base_url' is only the DEFAULT shown in Settings — the stored ai_base_url
// always wins, so an admin can point either provider at a proxy or an
// EU-hosted gateway without a code change.
//
// Model names date faster than anything else here. They are defaults and
// datalist suggestions, never a closed list: the field is free text and the
// Settings "Έλεγχος σύνδεσης" button reports the provider's own error
// verbatim, so a retired model name is a 5-second fix instead of a mystery.
function aiProviders(): array {
    return [
        'deepseek' => [
            'label'         => 'DeepSeek',
            'base_url'      => 'https://api.deepseek.com/v1',
            'default_model' => 'deepseek-flash',
            'models'        => ['deepseek-flash', 'deepseek-v4-pro'],
            'key_url'       => 'https://platform.deepseek.com/api_keys',
            'key_hint'      => 'Με χρέωση, αλλά πολύ φθηνό: μια πλήρης ανάλυση αποστολής κοστίζει κλάσματα του λεπτού.',
            // Where the provider processes and stores the request. Shown in
            // Settings because it is the single fact that decides whether a
            // given deployment may use it at all.
            'jurisdiction'  => 'Λ.Δ. Κίνας',
        ],
        'gemini' => [
            'label'         => 'Google Gemini',
            // Google's own OpenAI-compatibility layer. The native
            // generateContent API is a different shape entirely; this path
            // lets one client serve both providers.
            'base_url'      => 'https://generativelanguage.googleapis.com/v1beta/openai',
            'default_model' => 'gemini-2.5-flash',
            'models'        => ['gemini-2.5-flash', 'gemini-3.5-flash', 'gemini-3.8-flash', 'gemini-2.5-pro'],
            'key_url'       => 'https://aistudio.google.com/apikey',
            'key_hint'      => 'Διαθέτει δωρεάν επίπεδο με ημερήσιο όριο αιτημάτων — αρκετό για εκθέσεις αποστολών.',
            'jurisdiction'  => 'ΗΠΑ / Google',
        ],
    ];
}

/**
 * The admin's current AI configuration, already resolved: which provider,
 * which key, which URL, which model.
 *
 * The API key is stored PER PROVIDER (ai_api_key_deepseek /
 * ai_api_key_gemini), not in one shared field, on purpose: an admin
 * comparing the two flips the dropdown back and forth, and a single field
 * would make them re-paste a key every time — which is exactly the moment a
 * key gets pasted into the wrong provider.
 */
function aiConfig(): array {
    $providers = aiProviders();
    $provider  = (string) getSetting('ai_provider', 'gemini');
    if (!isset($providers[$provider])) {
        $provider = 'gemini';
    }
    $meta = $providers[$provider];

    $baseUrl = trim((string) getSetting('ai_base_url', ''));
    if ($baseUrl === '') {
        $baseUrl = $meta['base_url'];
    }
    $model = trim((string) getSetting('ai_model', ''));
    if ($model === '') {
        $model = $meta['default_model'];
    }

    return [
        'enabled'        => getSetting('ai_enabled', '0') === '1',
        'provider'       => $provider,
        'provider_label' => $meta['label'],
        'jurisdiction'   => $meta['jurisdiction'],
        'base_url'       => rtrim($baseUrl, '/'),
        'model'          => $model,
        'api_key'        => trim((string) getSetting('ai_api_key_' . $provider, '')),
    ];
}

/**
 * True only when the feature is switched on AND has a key to use. Every
 * caller gates on this before rendering a button, so a half-configured
 * install shows no AI affordances at all rather than a button that always
 * fails.
 */
function aiIsConfigured(): bool {
    $cfg = aiConfig();
    return $cfg['enabled'] && $cfg['api_key'] !== '';
}

/**
 * Send a chat completion and return a structured result.
 *
 * Always returns an array; never throws, never warns — a provider outage must
 * degrade a report section, not fatal a page.
 *
 *   ok        bool
 *   content   string  assistant text ('' on failure)
 *   json      ?array  decoded object when $opts['json'] is true and it parsed
 *   error     ?string Greek, safe to show an admin
 *   usage     array   prompt/completion/total tokens when the provider reports them
 *   ms        int     wall-clock milliseconds, for the stored stamp
 *
 * $opts: json(bool) temperature(float) max_tokens(int) timeout(int)
 */
function aiChat(array $messages, array $opts = []): array {
    $cfg = aiConfig();
    $fail = function (string $msg) use ($cfg): array {
        return ['ok' => false, 'content' => '', 'json' => null, 'error' => $msg, 'usage' => [], 'ms' => 0, 'model' => $cfg['model'], 'provider' => $cfg['provider']];
    };

    // ignore_master_switch exists for exactly one caller: the Settings
    // connection test. An admin has to be able to prove a key works BEFORE
    // switching the feature on for everyone; requiring the switch first would
    // mean enabling an untested integration and finding out from a user.
    if (!$cfg['enabled'] && empty($opts['ignore_master_switch'])) {
        return $fail('Η τεχνητή νοημοσύνη είναι απενεργοποιημένη στις Ρυθμίσεις.');
    }
    if ($cfg['api_key'] === '')        return $fail('Δεν έχει οριστεί API key για τον πάροχο «' . $cfg['provider_label'] . '».');
    if (!function_exists('curl_init')) return $fail('Η επέκταση cURL δεν είναι διαθέσιμη στον server.');

    $wantJson    = !empty($opts['json']);
    $temperature = isset($opts['temperature']) ? (float) $opts['temperature'] : 0.55;
    $maxTokens   = isset($opts['max_tokens']) ? (int) $opts['max_tokens'] : 4000;
    $timeout     = isset($opts['timeout']) ? (int) $opts['timeout'] : 120;

    $body = [
        'model'       => $cfg['model'],
        'messages'    => $messages,
        'temperature' => $temperature,
        'max_tokens'  => $maxTokens,
        'stream'      => false,
    ];
    if ($wantJson) {
        // DeepSeek additionally requires the literal word "json" somewhere in
        // the prompt for this to be accepted; the observer prompt says so
        // explicitly. Gemini's compatibility layer accepts it either way.
        $body['response_format'] = ['type' => 'json_object'];
    }

    $started = microtime(true);
    $ch = curl_init($cfg['base_url'] . '/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_USERAGENT      => 'VolunteerOps/' . APP_VERSION,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $cfg['api_key'],
        ],
    ]);
    $raw      = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);
    $ms = (int) round((microtime(true) - $started) * 1000);

    if ($raw === false) {
        return $fail('Σφάλμα δικτύου προς τον πάροχο: ' . ($curlErr ?: 'άγνωστο σφάλμα'));
    }

    $decoded = json_decode((string) $raw, true);

    if ($httpCode !== 200) {
        // Providers disagree on everything except that the useful part lives
        // under error.message — surface it verbatim rather than a generic
        // "failed", because for the two most common mistakes (wrong key,
        // retired model name) their own message names the problem exactly.
        $detail = $decoded['error']['message'] ?? $decoded['message'] ?? '';
        $detail = is_string($detail) ? trim($detail) : '';
        if ($detail === '') $detail = 'HTTP ' . $httpCode;
        if ($httpCode === 401 || $httpCode === 403) {
            return $fail('Το API key απορρίφθηκε από τον πάροχο (' . $detail . ').');
        }
        if ($httpCode === 429) {
            return $fail('Ο πάροχος επέστρεψε υπέρβαση ορίου χρήσης. Δοκιμάστε ξανά σε λίγο (' . $detail . ').');
        }
        return $fail('Ο πάροχος απάντησε με σφάλμα: ' . $detail);
    }

    $content = $decoded['choices'][0]['message']['content'] ?? null;
    if (!is_string($content) || trim($content) === '') {
        // A 200 with no content is nearly always a safety block or a
        // max_tokens cut mid-object. finish_reason says which.
        $reason = $decoded['choices'][0]['finish_reason'] ?? '';
        return $fail('Ο πάροχος επέστρεψε κενή απάντηση' . ($reason ? " (finish_reason: {$reason})" : '') . '.');
    }

    $json = null;
    if ($wantJson) {
        $json = aiDecodeJsonLoose($content);
        if ($json === null) {
            return $fail('Η απάντηση του παρόχου δεν ήταν έγκυρο JSON.');
        }
    }

    return [
        'ok'       => true,
        'content'  => $content,
        'json'     => $json,
        'error'    => null,
        'usage'    => [
            'prompt'     => (int) ($decoded['usage']['prompt_tokens'] ?? 0),
            'completion' => (int) ($decoded['usage']['completion_tokens'] ?? 0),
            'total'      => (int) ($decoded['usage']['total_tokens'] ?? 0),
        ],
        'ms'       => $ms,
        'model'    => $cfg['model'],
        'provider' => $cfg['provider'],
    ];
}

/**
 * Decode a JSON object out of a model reply.
 *
 * response_format:json_object is honoured by both providers, but not
 * absolutely — a fenced code block or a leading sentence still shows up often
 * enough that failing the whole report over it would be a bad trade. So: try
 * the string as-is, then strip a fence, then take the outermost
 * brace-to-brace slice. Anything beyond that is a genuine failure and is
 * reported as one.
 */
function aiDecodeJsonLoose(string $text): ?array {
    $try = function (string $s): ?array {
        $d = json_decode($s, true);
        return is_array($d) ? $d : null;
    };

    $trimmed = trim($text);
    if (($d = $try($trimmed)) !== null) return $d;

    if (preg_match('/```(?:json)?\s*(.+?)\s*```/s', $trimmed, $m)) {
        if (($d = $try(trim($m[1]))) !== null) return $d;
    }

    $first = strpos($trimmed, '{');
    $last  = strrpos($trimmed, '}');
    if ($first !== false && $last !== false && $last > $first) {
        if (($d = $try(substr($trimmed, $first, $last - $first + 1))) !== null) return $d;
    }

    return null;
}
