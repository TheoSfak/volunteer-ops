<?php
/**
 * VolunteerOps — AI provider client.
 *
 * One narrow job: take a list of chat messages, hand them to whichever
 * OpenAI-compatible provider the admin picked in Settings, and give back the
 * parsed answer. Nothing in here knows what a mission is.
 *
 * WHY OpenAI-compatible and nothing else: every provider this app offers
 * (Google Gemini, Groq, xAI Grok and DeepSeek) speaks the same
 * /chat/completions dialect, so switching between them — or away from all of
 * them, to an EU-hosted endpoint — is a base-URL string, not a rewrite. That is
 * the whole exit strategy, and it costs nothing to keep today.
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
//
// DECLARATION ORDER IS THE FAILOVER ORDER for providers of equal rank, and
// 'failover_rank' overrides it — see aiFailoverOrder() for why a metered
// provider has to sit at the back of the queue no matter where it is written.
function aiProviders(): array {
    return [
        'gemini' => [
            'label'         => 'Google Gemini',
            // Google's own OpenAI-compatibility layer. The native
            // generateContent API is a different shape entirely; this path
            // lets one client serve both providers.
            'base_url'      => 'https://generativelanguage.googleapis.com/v1beta/openai',
            // gemini-2.5-flash was the default here for exactly one release and
            // failed on a fresh key with "no longer available to new users" —
            // Google keeps retired models callable for existing projects, so a
            // name that works on one account 404s on another. Treat every entry
            // below as a suggestion with a shelf life; the Settings test button
            // lists what a given key can actually call.
            'default_model' => 'gemini-3.6-flash',
            'models'        => ['gemini-3.6-flash', 'gemini-3.5-flash', 'gemini-3.1-pro-preview', 'gemini-2.5-pro'],
            'reasoning_effort' => true,
            'key_url'       => 'https://aistudio.google.com/apikey',
            'key_hint'      => 'Διαθέτει δωρεάν επίπεδο με ημερήσιο όριο αιτημάτων — αρκετό για εκθέσεις αποστολών.',
            'jurisdiction'  => 'ΗΠΑ / Google',
            // Free tier: tried before anything metered. Ties are broken by the
            // order written here, so Gemini stays the first one tried.
            'failover_rank' => 10,
        ],
        // GROQ AND GROK ARE DIFFERENT COMPANIES, one letter apart, and they sit
        // next to each other in this list. Groq (groq.com) is an inference host
        // running open-weight models on its own hardware, with a free key; Grok
        // (x.ai) is xAI's own model, billed per call. Both key_hints say so
        // out loud, because the two were already confused once while this
        // provider was being added — by the person adding it.
        'groq' => [
            'label'         => 'Groq',
            // Groq's OpenAI-compatible surface. Note the /openai/ segment: the
            // bare https://api.groq.com is not the API root, and leaving it out
            // 404s every call in a way that reads like a wrong model name.
            'base_url'      => 'https://api.groq.com/openai/v1',
            // Open-weight models on someone else's hardware, so the names here
            // rot even faster than the other providers' — they are whatever
            // Groq is hosting this quarter. The Settings test button lists what
            // a given key can actually call.
            'default_model' => 'llama-3.3-70b-versatile',
            'models'        => ['llama-3.3-70b-versatile', 'openai/gpt-oss-120b', 'openai/gpt-oss-20b',
                                'moonshotai/kimi-k2-instruct', 'qwen/qwen3-32b', 'llama-3.1-8b-instant'],
            // Supported by SOME of the models above and rejected with a 400 by
            // others (the Llama ones), and which is which changes as the
            // catalogue does. Never sent, same as the other two: an
            // optimisation is not worth a failed round trip on every call.
            'reasoning_effort' => false,
            'key_url'       => 'https://console.groq.com/keys',
            'key_hint'      => 'Δωρεάν API key με γενναιόδωρα ημερήσια όρια, και από τους ταχύτερους παρόχους. ΠΡΟΣΟΧΗ: είναι το Groq (groq.com), ΟΧΙ το Grok της xAI παρακάτω.',
            'jurisdiction'  => 'ΗΠΑ / Groq',
            'failover_rank' => 10,
        ],
        'grok' => [
            'label'         => 'xAI Grok',
            // xAI ships an OpenAI-compatible API at this path — same
            // /chat/completions and /models shapes as the others, so it
            // needs no client code of its own.
            'base_url'      => 'https://api.x.ai/v1',
            'default_model' => 'grok-4-fast-reasoning',
            'models'        => ['grok-4-fast-reasoning', 'grok-4-fast-non-reasoning', 'grok-4', 'grok-3-mini', 'grok-3'],
            // false, and not because the models cannot reason: xAI accepts
            // reasoning_effort on its small models and REJECTS it outright on
            // the grok-4 family. Sending it would 400 every call on the
            // default model. aiChatOnce() does retry a reasoning-shaped 400
            // without the parameter, but that is a safety net for a model
            // whose support changed under us, not something to spend a failed
            // round trip on at every single call.
            'reasoning_effort' => false,
            'key_url'       => 'https://console.x.ai',
            'key_hint'      => 'Με χρέωση από προπληρωμένη πίστωση — ελέγξτε το υπόλοιπο στο console πριν από άσκηση ή αποστολή. ΠΡΟΣΟΧΗ: είναι το Grok της xAI, ΟΧΙ το Groq (groq.com) παραπάνω.',
            'jurisdiction'  => 'ΗΠΑ / xAI',
            // 50, between the free tiers and the last resort. It bills per call
            // like DeepSeek, so it has no business being tried before a
            // provider that costs nothing — but it is a deliberate part of the
            // chain, so it goes comfortably ahead of the one kept for last.
            'failover_rank' => 50,
        ],
        'deepseek' => [
            'label'         => 'DeepSeek',
            'base_url'      => 'https://api.deepseek.com/v1',
            'default_model' => 'deepseek-flash',
            'models'        => ['deepseek-flash', 'deepseek-v4-pro'],
            // Whether this provider honours OpenAI's reasoning_effort
            // parameter. Declared per provider rather than sent blindly: an
            // unknown parameter is usually ignored, but a provider that
            // rejects it would fail every call with a 400, and the failover
            // chain would then walk straight into the same wall.
            'reasoning_effort' => false,
            'key_url'       => 'https://platform.deepseek.com/api_keys',
            'key_hint'      => 'Με χρέωση, αλλά πολύ φθηνό: μια πλήρης ανάλυση αποστολής κοστίζει κλάσματα του λεπτού. Χρησιμοποιείται ως τελευταία εφεδρεία, όταν κανένας άλλος πάροχος δεν απαντά.',
            // Where the provider processes and stores the request. Shown in
            // Settings because it is the single fact that decides whether a
            // given deployment may use it at all.
            'jurisdiction'  => 'Λ.Δ. Κίνας',
            // Last resort. Every call here is billed, so it is worth reaching
            // only once the free tiers have actually failed.
            'failover_rank' => 90,
        ],
    ];
}

/**
 * Provider keys in the order failover walks them, cheapest-to-reach first.
 *
 * WHY A RANK AND NOT JUST THE ARRAY ORDER: the array order is easy to change
 * by accident — adding a provider, sorting the list alphabetically, moving a
 * block while editing a comment — and the cost of getting it wrong is silent.
 * Nothing breaks; the app simply starts paying DeepSeek for work a free tier
 * would have done, and no one finds out until the invoice. The rank states the
 * intent where it cannot be lost: metered providers are reached only after the
 * free ones have been tried and failed.
 *
 * Equal ranks keep the order aiProviders() declares them in — PHP's sort has
 * been stable since 8.0, and the app requires 8.2.
 *
 * The admin's own choice still outranks all of this: aiBuildChain() puts the
 * chosen provider first whatever its rank says. Picking DeepSeek as the
 * primary is a deliberate decision to pay for the first attempt, and this must
 * not quietly overrule it.
 */
function aiFailoverOrder(): array {
    $providers = aiProviders();
    $keys      = array_keys($providers);
    usort($keys, fn($a, $b) => ($providers[$a]['failover_rank'] ?? 50) <=> ($providers[$b]['failover_rank'] ?? 50));
    return $keys;
}

/**
 * The admin's chosen primary provider.
 */
function aiPrimaryProvider(): string {
    $provider = (string) getSetting('ai_provider', 'gemini');
    return isset(aiProviders()[$provider]) ? $provider : 'gemini';
}

/**
 * Everything needed to call ONE named provider.
 *
 * Key, model and base URL are all stored PER PROVIDER
 * (ai_api_key_gemini, ai_model_gemini, ai_base_url_gemini, …) rather than in
 * three shared fields. Two reasons, and the second is the load-bearing one:
 * an admin comparing providers flips the dropdown back and forth, and shared
 * fields would make them re-paste a key every time — exactly when a key lands
 * on the wrong provider. And with automatic failover, a shared ai_model would
 * ask DeepSeek for "gemini-3.6-flash" the moment Gemini went down, so the
 * fallback would fail instantly and for a reason that looks like a bug.
 */
function aiProviderConfig(string $provider): array {
    $providers = aiProviders();
    if (!isset($providers[$provider])) {
        $provider = 'gemini';
    }
    $meta = $providers[$provider];

    $baseUrl = trim((string) getSetting('ai_base_url_' . $provider, ''));
    if ($baseUrl === '') $baseUrl = $meta['base_url'];

    $model = trim((string) getSetting('ai_model_' . $provider, ''));
    if ($model === '') $model = $meta['default_model'];

    return [
        'provider'       => $provider,
        'provider_label' => $meta['label'],
        'jurisdiction'   => $meta['jurisdiction'],
        'base_url'       => rtrim($baseUrl, '/'),
        'model'          => $model,
        'api_key'        => trim((string) getSetting('ai_api_key_' . $provider, '')),
        'reasoning_effort' => !empty($meta['reasoning_effort']),
    ];
}

/**
 * The primary provider's configuration plus the master switch. Kept in this
 * shape because settings.php and the connection test both read it.
 */
function aiConfig(): array {
    return ['enabled' => getSetting('ai_enabled', '0') === '1'] + aiProviderConfig(aiPrimaryProvider());
}

/**
 * The order providers are tried in: the chosen one first, then every other
 * provider that has a key, in aiFailoverOrder() — free tiers before metered
 * ones.
 *
 * Holding a key is what makes a provider eligible — there is no separate
 * "use for failover" switch, because an admin who does not want a provider
 * used simply does not store its key, and one less setting to reason about is
 * worth more here than one more degree of control.
 */
function aiFailoverChain(): array {
    $keyed = [];
    foreach (array_keys(aiProviders()) as $provider) {
        if (trim((string) getSetting('ai_api_key_' . $provider, '')) !== '') {
            $keyed[] = $provider;
        }
    }
    return aiBuildChain(aiPrimaryProvider(), aiFailoverOrder(), $keyed);
}

/**
 * The ordering rule on its own, with no settings behind it.
 *
 * Split out to be testable: getSetting() caches statically for the life of the
 * process, so a chain function that read settings directly could not be
 * exercised with more than one configuration in a single test run — and the
 * ordering is the part worth pinning. A primary without a key must not appear
 * (it cannot serve anything), and no provider may appear twice.
 *
 * $allProviders arrives already sorted by the caller (aiFailoverOrder()), so
 * the cost ordering lives in one place and this function stays a pure
 * "primary first, then the rest as given" rule.
 */
function aiBuildChain(string $primary, array $allProviders, array $keyedProviders): array {
    $keyed = array_flip($keyedProviders);
    $chain = [];
    foreach (array_merge([$primary], $allProviders) as $provider) {
        if (isset($chain[$provider]) || !isset($keyed[$provider])) continue;
        $chain[$provider] = true;
    }
    return array_keys($chain);
}

/**
 * Longest organisation playbook that may ride along on a prompt.
 *
 * It is prepended to EVERY operational call — every question, every handover,
 * every drafted order — so this is not a form-validation nicety, it is the
 * per-call price of the feature. A page and a half of doctrine is plenty to
 * change how the assistant answers; a manual is not, and would be paid for
 * hundreds of times a shift.
 */
const AI_PLAYBOOK_CAP = 1500;

/**
 * The organisation's own doctrine, ready to drop into a system prompt, or ''
 * when nothing is configured.
 *
 * WHY THIS EXISTS: without it the assistant is a generic twenty-year rescuer.
 * Its advice is correct and belongs to nobody — "consider rotating the team"
 * rather than "you rotate at ninety minutes and ΑΛΦΑ is at a hundred and ten".
 * One admin-written paragraph is the difference.
 *
 * WHAT IT IS NOT: it is not a way to reconfigure the assistant. The caller
 * places it BEFORE the limits section of each prompt, never after, so an
 * instruction that tried to lift a safety rule is overridden by the rule that
 * follows it. The framing line says the same thing in words, because a model
 * reading two conflicting instructions should be told which one wins rather
 * than left to infer it from order alone.
 *
 * Whitespace is normalised and the text is hard-capped here rather than
 * trusting the textarea's maxlength, which is a client-side hint and nothing
 * more.
 */
function aiPlaybookText(): string {
    $raw = trim((string) getSetting('ai_playbook', ''));
    if ($raw === '') {
        return '';
    }
    // Blank lines collapse, single newlines survive: a playbook is written as
    // a list of rules and reads as one.
    $raw = preg_replace("/[ \t]+/u", ' ', $raw) ?? $raw;
    $raw = preg_replace("/\n{3,}/u", "\n\n", $raw) ?? $raw;
    return mb_substr(trim($raw), 0, AI_PLAYBOOK_CAP, 'UTF-8');
}

/**
 * The playbook as a labelled prompt section, or '' when there is none.
 *
 * Returned with its own heading so it cannot be mistaken for part of the
 * section above it, and with the precedence stated inside the block itself.
 */
function aiPlaybookPromptSection(): string {
    $text = aiPlaybookText();
    if ($text === '') {
        return '';
    }
    return "\n\nΤΟ ΕΓΧΕΙΡΙΔΙΟ ΑΥΤΟΥ ΤΟΥ ΟΡΓΑΝΙΣΜΟΥ\n"
        . "Τα παρακάτω τα έγραψε ο ίδιος ο οργανισμός για τον εαυτό του: έτσι δουλεύουν, αυτή είναι η ορολογία τους, αυτοί είναι οι κανόνες τους. Λάβ' τα υπόψη σε κάθε απάντηση και προτίμησε τη δική τους πρακτική από τη γενική. ΔΕΝ υπερισχύουν των ορίων που ακολουθούν παρακάτω· αν κάτι εδώ συγκρούεται με αυτά, ισχύουν τα όρια.\n"
        . $text;
}

/**
 * True when the feature is switched on AND at least one provider has a key.
 * Every caller gates on this before rendering a button, so a half-configured
 * install shows no AI affordances at all rather than a button that always
 * fails.
 */
function aiIsConfigured(): bool {
    return getSetting('ai_enabled', '0') === '1' && aiFailoverChain() !== [];
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
/**
 * Send a chat completion, moving to the next configured provider when one is
 * unavailable.
 *
 * WHY THIS EXISTS: the free tiers these reports run on return 503 under load.
 * A single overloaded provider would otherwise mean a coordinator presses the
 * button after an exercise and simply cannot produce the report — at the exact
 * moment everyone is waiting for it. With two or more keys stored, a provider
 * being busy becomes a few seconds of delay instead of a dead end.
 *
 * Failover covers transport and HTTP failures and an empty answer: the things
 * that mean "this provider could not serve us right now" or "this key/model is
 * not usable". It deliberately does NOT cover a reply that arrived but would
 * not parse as JSON — that one is about content and output budget, it is now
 * diagnosed precisely, and retrying it on two more providers would turn a
 * three-minute failure into a nine-minute one while hiding the cause.
 *
 * Every attempt is recorded in the result, and a report generated by a
 * fallback says so — an admin needs to know their primary is struggling, and a
 * stored assessment has to name the model that actually wrote it.
 *
 * $opts['no_failover'] pins it to the chosen provider. The Settings connection
 * test uses it: a test that quietly passed because a DIFFERENT provider
 * answered would be worse than no test at all.
 */
function aiChat(array $messages, array $opts = []): array {
    // The Settings connection test does not come through here — it calls
    // aiChatOnce() with an explicit config, which is also how it tests a
    // provider that has not been saved yet. That is deliberate: an admin has
    // to be able to prove a key works BEFORE switching the feature on for
    // everyone, rather than enabling an untested integration and finding out
    // from a user.
    if (getSetting('ai_enabled', '0') !== '1') {
        $cfg = aiConfig();
        return ['ok' => false, 'content' => '', 'json' => null, 'error' => 'Η τεχνητή νοημοσύνη είναι απενεργοποιημένη στις Ρυθμίσεις.',
                'usage' => [], 'ms' => 0, 'model' => $cfg['model'], 'provider' => $cfg['provider'], 'attempts' => []];
    }

    $chain = empty($opts['no_failover']) ? aiFailoverChain() : [aiPrimaryProvider()];
    if (!$chain) {
        $cfg = aiConfig();
        return ['ok' => false, 'content' => '', 'json' => null, 'error' => 'Δεν έχει οριστεί API key για κανέναν πάροχο.',
                'usage' => [], 'ms' => 0, 'model' => $cfg['model'], 'provider' => $cfg['provider'], 'attempts' => []];
    }

    $attempts = [];
    $last     = null;
    foreach ($chain as $i => $provider) {
        $cfg    = aiProviderConfig($provider);
        $result = aiChatOnce($messages, $opts, $cfg);
        $result['attempts'] = $attempts;

        if ($result['ok']) {
            // Tell the caller it is not reading what it asked for.
            $result['fell_back'] = $i > 0;
            return $result;
        }

        $attempts[] = [
            'provider'       => $provider,
            'provider_label' => $cfg['provider_label'],
            'model'          => $cfg['model'],
            'error'          => $result['error'],
        ];
        $last = $result;

        if (empty($result['retryable'])) {
            $last['attempts'] = $attempts;
            return $last;
        }
    }

    // Every provider was tried and every one was unavailable. Report the last
    // failure, with the whole chain attached so the admin can see it was not
    // one provider having a bad minute.
    $last['attempts']  = $attempts;
    $last['fell_back'] = false;
    if (count($attempts) > 1) {
        $names = array_map(fn($a) => $a['provider_label'], $attempts);
        $last['error'] = 'Κανένας πάροχος δεν απάντησε (' . implode(', ', $names) . '). Τελευταίο σφάλμα: ' . $last['error'];
    }
    return $last;
}

/**
 * One attempt against one provider. Never reads settings itself — the chain
 * above decides who it is talking to.
 *
 * Adds 'retryable' to a failure: true means "another provider might serve
 * this", which is what aiChat() loops on.
 */
function aiChatOnce(array $messages, array $opts, array $cfg): array {
    $fail = function (string $msg, bool $retryable) use ($cfg): array {
        return ['ok' => false, 'content' => '', 'json' => null, 'error' => $msg, 'usage' => [], 'ms' => 0,
                'model' => $cfg['model'], 'provider' => $cfg['provider'], 'retryable' => $retryable];
    };

    if ($cfg['api_key'] === '')        return $fail('Δεν έχει οριστεί API key για τον πάροχο «' . $cfg['provider_label'] . '».', true);
    // Not retryable: cURL is missing from the server, so no provider is
    // reachable and trying three of them just wastes the operator's time.
    if (!function_exists('curl_init')) return $fail('Η επέκταση cURL δεν είναι διαθέσιμη στον server.', false);

    $wantJson    = !empty($opts['json']);
    $temperature = isset($opts['temperature']) ? (float) $opts['temperature'] : 0.55;
    // 8000, not 4000. Two things make a tight budget bite harder here than the
    // raw length of the answer suggests: Greek costs roughly two to three times
    // the tokens of the same text in English, and current Gemini and DeepSeek
    // models spend part of this same budget on internal reasoning the reader
    // never sees. A budget that merely fits the finished text will truncate.
    $maxTokens   = isset($opts['max_tokens']) ? (int) $opts['max_tokens'] : 8000;
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

    // Current models spend part of the OUTPUT budget on reasoning nobody
    // reads, and the accounting is invisible until something truncates. On a
    // real translation run Gemini burned 10.956 of 12.000 tokens thinking and
    // ran out mid-JSON at item 53 of 60 — for a task with nothing to reason
    // about. Callers doing mechanical work turn it off; the observer leaves it
    // alone, where the thinking is the point.
    if (!empty($opts['reasoning_effort']) && !empty($cfg['reasoning_effort'])) {
        $body['reasoning_effort'] = (string) $opts['reasoning_effort'];
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
        // Timeout, DNS, TLS. Another provider is on another network path.
        return $fail('Σφάλμα δικτύου προς τον πάροχο: ' . ($curlErr ?: 'άγνωστο σφάλμα'), true);
    }

    $decoded = json_decode((string) $raw, true);

    if ($httpCode !== 200) {
        $detail = aiExtractProviderError($decoded, (string) $raw);

        // reasoning_effort is declared per provider, but a provider can accept
        // the parameter and reject a particular VALUE, or drop support for a
        // given model. Retry once without it rather than failing the whole
        // request over an optimisation: the call then costs more tokens, which
        // is strictly better than not happening.
        if ($httpCode === 400 && isset($body['reasoning_effort']) && stripos($detail, 'reasoning') !== false) {
            unset($opts['reasoning_effort']);
            return aiChatOnce($messages, $opts, $cfg);
        }

        // Everything here is retryable on ANOTHER provider, including the
        // failures that look like configuration mistakes. A revoked key or a
        // retired model name on one provider is precisely when a second key
        // should carry the report — and the attempt is recorded either way, so
        // failing over reports the broken configuration rather than hiding it.
        if ($httpCode === 401 || $httpCode === 403 || $httpCode === 400) {
            return $fail('Ο πάροχος «' . $cfg['provider_label'] . '» απέρριψε το αίτημα: ' . $detail, true);
        }
        if ($httpCode === 404) {
            // Almost always the model name, and the admin cannot guess that
            // from "HTTP 404" — so name what was actually called.
            return $fail('Δεν βρέθηκε το ζητούμενο στον «' . $cfg['provider_label'] . '» (HTTP 404). Συνήθως το όνομα μοντέλου είναι λάθος ή έχει αποσυρθεί. '
                       . 'Ζητήθηκε μοντέλο «' . $cfg['model'] . '» στο ' . $cfg['base_url'] . '. Απάντηση παρόχου: ' . $detail, true);
        }
        if ($httpCode === 429) {
            return $fail('Ο πάροχος «' . $cfg['provider_label'] . '» επέστρεψε υπέρβαση ορίου χρήσης (' . $detail . ').', true);
        }
        if ($httpCode === 503 || $httpCode === 502 || $httpCode === 504 || $httpCode === 500) {
            // The case this whole mechanism was built for: free tiers answer
            // 503 when they are busy, and it clears on its own.
            return $fail('Ο πάροχος «' . $cfg['provider_label'] . '» είναι προσωρινά υπερφορτωμένος (HTTP ' . $httpCode . '): ' . $detail, true);
        }
        return $fail('Ο πάροχος «' . $cfg['provider_label'] . '» απάντησε με σφάλμα (HTTP ' . $httpCode . '): ' . $detail, true);
    }

    $content = $decoded['choices'][0]['message']['content'] ?? null;
    if (!is_string($content) || trim($content) === '') {
        // A 200 with no content is nearly always a safety block or a
        // max_tokens cut mid-object. finish_reason says which. Worth another
        // provider: a different model may not refuse the same prompt.
        $reason = $decoded['choices'][0]['finish_reason'] ?? '';
        return $fail('Ο πάροχος «' . $cfg['provider_label'] . '» επέστρεψε κενή απάντηση' . ($reason ? " (finish_reason: {$reason})" : '') . '.', true);
    }

    $json = null;
    if ($wantJson) {
        $json = aiDecodeJsonLoose($content);
        if ($json === null) {
            // "Δεν ήταν έγκυρο JSON" on its own is the same dead end the bare
            // HTTP 404 was: it names the symptom and hides every fact needed to
            // act. By far the most common cause is the answer being cut off
            // mid-object when the output budget runs out — and on a thinking
            // model the budget is spent on reasoning the reader never sees, so
            // a report that looks short can still have exhausted it.
            $finish    = (string) ($decoded['choices'][0]['finish_reason'] ?? '');
            $completed = (int) ($decoded['usage']['completion_tokens'] ?? 0);
            $reasoning = (int) ($decoded['usage']['completion_tokens_details']['reasoning_tokens'] ?? 0);
            $chars     = mb_strlen($content, 'UTF-8');
            $tail      = mb_substr(trim(preg_replace('/\s+/u', ' ', $content) ?? $content), -180, 180, 'UTF-8');

            $budget = 'Όριο εξόδου ' . $maxTokens . ' tokens· ο πάροχος ανέφερε ' . $completed
                    . ($reasoning > 0 ? " (εκ των οποίων {$reasoning} σε εσωτερική σκέψη)" : '')
                    . ', κείμενο ' . $chars . ' χαρακτήρων.';

            // NOT retryable on another provider. The call succeeded and cost
            // its full time; the problem is content or output budget, it is
            // diagnosed precisely right here, and repeating it down the chain
            // would turn a three-minute failure into a nine-minute one while
            // burying the cause under two more identical messages.
            if (in_array(strtolower($finish), ['length', 'max_tokens'], true)) {
                return $fail('Η απάντηση κόπηκε στη μέση: το μοντέλο εξάντλησε το όριο εξόδου πριν κλείσει το JSON. '
                           . $budget . ' Τέλος απάντησης: …' . $tail, false);
            }
            return $fail('Η απάντηση του παρόχου δεν ήταν έγκυρο JSON (finish_reason: '
                       . ($finish !== '' ? $finish : 'δεν αναφέρθηκε') . '). ' . $budget
                       . ' Τέλος απάντησης: …' . $tail, false);
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
            // Counted inside completion_tokens, not beside it — so this is the
            // number that explains a budget disappearing into nothing visible.
            'reasoning'  => (int) ($decoded['usage']['completion_tokens_details']['reasoning_tokens'] ?? 0),
        ],
        'ms'       => $ms,
        'model'    => $cfg['model'],
        'provider' => $cfg['provider'],
    ];
}

/**
 * Pull the provider's own error text out of a failed response.
 *
 * Every provider agrees the useful part lives at error.message and then
 * disagrees about where error lives. Gemini's OpenAI-compatibility layer
 * returns it wrapped in a TOP-LEVEL ARRAY:
 *
 *     [{ "error": { "code": 400, "message": "Please pass a valid API key" } }]
 *
 * Reading only $decoded['error']['message'] therefore found nothing and the
 * admin got a bare "HTTP 404" — losing the one sentence that said what was
 * wrong. That is a real reported failure, not a hypothetical.
 *
 * Falls back to a truncated slice of the raw body: an unparseable error is
 * still more useful than a status code, and this is only ever shown to an
 * administrator.
 */
function aiExtractProviderError($decoded, string $raw): string {
    $candidates = [];
    if (is_array($decoded)) {
        $candidates[] = $decoded['error']['message'] ?? null;
        $candidates[] = $decoded['error'] ?? null;          // some proxies return a plain string
        $candidates[] = $decoded['message'] ?? null;
        $candidates[] = $decoded[0]['error']['message'] ?? null;
        $candidates[] = $decoded[0]['message'] ?? null;
    }
    foreach ($candidates as $c) {
        if (is_string($c) && trim($c) !== '') {
            return trim($c);
        }
    }
    $raw = trim(preg_replace('/\s+/u', ' ', $raw) ?? $raw);
    return $raw !== '' ? mb_substr($raw, 0, 300, 'UTF-8') : 'χωρίς μήνυμα από τον πάροχο';
}

/**
 * The model ids this key can actually call, newest-looking first.
 *
 * Exists because the single most likely configuration mistake is a model name
 * that has been retired — provider model catalogues turn over every few
 * months, and no default shipped in code stays correct. The settings test
 * button calls this on failure so a 404 answers itself instead of sending an
 * admin to search the provider's docs.
 *
 * Returns ['ok' => bool, 'models' => string[], 'error' => ?string].
 */
function aiListModels(?array $cfg = null): array {
    // Accepts an explicit config so the Settings test can ask the provider
    // currently CHOSEN IN THE FORM, which is not necessarily the saved one.
    $cfg = $cfg ?? aiConfig();
    if ($cfg['api_key'] === '' || !function_exists('curl_init')) {
        return ['ok' => false, 'models' => [], 'error' => 'Δεν έχει οριστεί API key.'];
    }

    $ch = curl_init($cfg['base_url'] . '/models');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_USERAGENT      => 'VolunteerOps/' . APP_VERSION,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $cfg['api_key']],
    ]);
    $raw      = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) {
        return ['ok' => false, 'models' => [], 'error' => 'Αποτυχία δικτύου.'];
    }
    $decoded = json_decode((string) $raw, true);
    if ($httpCode !== 200 || !is_array($decoded)) {
        return ['ok' => false, 'models' => [], 'error' => aiExtractProviderError($decoded, (string) $raw)];
    }

    $ids = [];
    foreach (($decoded['data'] ?? []) as $m) {
        $id = $m['id'] ?? null;
        if (!is_string($id) || $id === '') continue;
        // Gemini returns ids as "models/gemini-2.5-flash" but accepts either
        // form on /chat/completions; show the short one, which is what an
        // admin will paste back into the model field.
        $ids[] = str_starts_with($id, 'models/') ? substr($id, 7) : $id;
    }
    return ['ok' => true, 'models' => $ids, 'error' => null];
}

/**
 * Narrow a provider's raw model catalogue to the ones that could actually
 * write a report, newest first.
 *
 * A real Gemini key returns dozens of ids and most of them cannot: image and
 * audio generators, the Live realtime API, computer-use, deep-research, and
 * tool-specific variants. On the first real key this was tried against, the
 * unfiltered alphabetical list buried the newest generation past the display
 * cap — so the model the provider's OWN retirement notice told the admin to
 * switch to was not among the ones shown.
 *
 * Natural-order descending, so 3.6 sorts above 3.5 above 3.1 above 2.5; plain
 * string sorting puts 3.1 above 3.6 and the oldest generation on top.
 *
 * Never returns an empty list when given a non-empty one: if the filter
 * matches everything, the unfiltered set is better than nothing.
 */
function aiChatModelsFromList(array $ids): array {
    $usable = array_values(array_filter(
        $ids,
        fn($m) => is_string($m) && $m !== '' && !preg_match(
            // whisper/guard are Groq's contribution to this list: a real Groq
            // key returns speech-to-text and moderation models alongside the
            // chat ones, and neither can write a report.
            '/embedding|imagen|veo|aqa|-tts|vision|learnlm|image|audio|-live|computer-use|deep-research|antigravity|customtools|whisper|guard/i',
            $m
        )
    ));
    if (!$usable) {
        $usable = array_values(array_filter($ids, fn($m) => is_string($m) && $m !== ''));
    }
    usort($usable, fn($a, $b) => strnatcasecmp($b, $a));
    return $usable;
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
