<?php
/**
 * VolunteerOps — reading an image with the AI providers from Settings (v3.510.0, receipt scanning).
 *
 * The rule the user set: the FREE providers first. So unlike aiChat(), this ignores the admin's primary-provider
 * choice and walks aiFailoverOrder() (failover_rank: Gemini and Groq free tiers, then Grok, then DeepSeek),
 * keeping only providers that have a key AND can read an image (aiProviders()[*]['vision_model'] !== false).
 * One attempt per provider through aiChatOnce(), with aiChat()'s failover rule: move on only when the failure
 * is marked retryable.
 */

if (!defined('VOLUNTEEROPS')) {
    die('Direct access not permitted');
}

require_once __DIR__ . '/ai.php';

function aiVisionBuildChain(array $ordered, array $keyed, array $capable): array {
    return array_values(array_filter($ordered, fn($p) => in_array($p, $keyed, true) && in_array($p, $capable, true)));
}

function aiVisionCapableProviders(): array {
    return array_keys(array_filter(aiProviders(), fn($p) => ($p['vision_model'] ?? false) !== false));
}

function aiVisionChain(): array {
    $keyed = [];
    foreach (array_keys(aiProviders()) as $provider) {
        if (trim((string) getSetting('ai_api_key_' . $provider, '')) !== '') $keyed[] = $provider;
    }
    return aiVisionBuildChain(aiFailoverOrder(), $keyed, aiVisionCapableProviders());
}

function aiVisionProviderConfig(string $provider): array {
    $cfg = aiProviderConfig($provider);
    $model = trim((string) getSetting('ai_vision_model_' . $provider, ''));
    if ($model === '') $model = (string) (aiProviders()[$provider]['vision_model'] ?? '');
    if ($model !== '') $cfg['model'] = $model;
    return $cfg;
}

function aiVisionAvailable(): bool {
    return getSetting('ai_enabled', '0') === '1' && aiVisionChain() !== [];
}

const AI_VISION_DEADLINE_SECONDS = 75;
const AI_VISION_CALL_MAX_SECONDS = 35;
const AI_VISION_MIN_REMAINING_SECONDS = 8;

/**
 * Timeout for the next provider call given the overall deadline (a unix time, float): the per-call maximum, shrunk to
 * what is left; null when less than the minimum remains, which is the signal to stop trying further providers.
 */
function aiVisionCallTimeout(float $deadline, float $now, int $perCallMax = AI_VISION_CALL_MAX_SECONDS, int $minRemaining = AI_VISION_MIN_REMAINING_SECONDS): ?int {
    $left = $deadline - $now;
    if ($left < $minRemaining) return null;
    return max(1, min($perCallMax, (int) floor($left)));
}

/**
 * Walks the chain, but within ONE overall deadline ($opts['deadline'] seconds, default 75): each call gets a shrinking
 * timeout (at most 35 s) and no further provider is tried when under ~8 s remain, so a request never outlives the web
 * server's own limit and a slow chain cannot run for minutes.
 */
function aiVisionChat(array $messages, array $opts = []): array {
    $none = ['ok' => false, 'content' => '', 'json' => null, 'usage' => [], 'ms' => 0, 'model' => '', 'provider' => '', 'provider_label' => '', 'attempts' => []];
    if (getSetting('ai_enabled', '0') !== '1') return ['error' => 'Η τεχνητή νοημοσύνη είναι απενεργοποιημένη στις Ρυθμίσεις.'] + $none;
    $chain = aiVisionChain();
    if (!$chain) return ['error' => 'Δεν υπάρχει πάροχος AI με κλειδί που να διαβάζει εικόνες (Gemini, Groq ή Grok).'] + $none;

    $deadline = microtime(true) + (float) ($opts['deadline'] ?? AI_VISION_DEADLINE_SECONDS);
    unset($opts['deadline']);
    $attempts = [];
    $last = null;
    foreach ($chain as $provider) {
        $timeout = aiVisionCallTimeout($deadline, microtime(true));
        if ($timeout === null) break; // out of time: stop here rather than start a call that cannot finish
        $cfg = aiVisionProviderConfig($provider);
        $result = aiChatOnce($messages, ['timeout' => $timeout] + $opts, $cfg);
        $result['provider_label'] = $cfg['provider_label'];
        if ($result['ok']) {
            $result['attempts'] = $attempts;
            return $result;
        }
        $attempts[] = ['provider' => $provider, 'provider_label' => $cfg['provider_label'], 'model' => $cfg['model'], 'error' => $result['error']];
        $last = $result;
        if (empty($result['retryable'])) break;
    }
    if ($last === null) return ['error' => 'Το AI δεν πρόλαβε να απαντήσει. Δοκιμάστε ξανά.'] + $none;
    $last['attempts'] = $attempts;
    if (count($attempts) > 1) {
        $last['error'] = 'Κανένας πάροχος δεν απάντησε (' . implode(', ', array_column($attempts, 'provider_label')) . '). Τελευταίο σφάλμα: ' . $last['error'];
    }
    return $last;
}
