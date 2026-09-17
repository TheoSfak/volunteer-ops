<?php
/**
 * AJAX endpoint — test one AI provider end to end.
 * POST only, system admin required.
 * Returns JSON: { ok: bool, message: string }
 *
 * TESTS WHAT IS ON SCREEN, NOT WHAT IS SAVED. The earlier version read the
 * stored settings, so an admin who picked DeepSeek in the dropdown and pressed
 * the button was told "connected to Google Gemini" — the saved provider — and
 * reasonably concluded the feature was broken. A test button that answers about
 * something other than what the operator is looking at is worse than no button,
 * so the form posts its own provider, key, model and base URL and those are
 * what get called.
 *
 * Deliberately a real (tiny) chat completion rather than a model list or a
 * ping: key, base URL and model each fail differently, and only an actual
 * completion proves all three together. The provider's own error text is passed
 * through, because for the two mistakes that actually happen — wrong key,
 * retired model name — their message names the problem exactly.
 *
 * No failover: this endpoint exists to prove ONE provider works. A test that
 * quietly passed because a different provider answered would be worse than no
 * test at all.
 */
require_once __DIR__ . '/bootstrap.php';
requireRole([ROLE_SYSTEM_ADMIN]);

header('Content-Type: application/json; charset=utf-8');

$fail = function (string $message): void {
    echo json_encode(['ok' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $fail('Μη έγκυρη μέθοδος');
}
if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string) $_POST['csrf_token'])) {
    $fail('Μη έγκυρο αίτημα');
}

$providers = aiProviders();
$provider  = (string) post('provider', '');
if (!isset($providers[$provider])) {
    $provider = aiPrimaryProvider();
}

// Start from what is stored for that provider, then let the form override.
// An untouched key field posts empty and keeps the stored key, exactly as
// saving does — so an admin can test a stored key without re-typing it, and
// test a freshly pasted one without saving it first.
$cfg = aiProviderConfig($provider);

$typedKey = trim((string) post('api_key', ''));
if ($typedKey !== '')      $cfg['api_key'] = $typedKey;

$typedModel = trim((string) post('model', ''));
if ($typedModel !== '')    $cfg['model'] = $typedModel;

$typedBase = trim((string) post('base_url', ''));
if ($typedBase !== '' && preg_match('#^https?://#i', $typedBase)) {
    $cfg['base_url'] = rtrim($typedBase, '/');
}

if ($cfg['api_key'] === '') {
    $fail('Δεν έχει οριστεί API key για τον πάροχο «' . $cfg['provider_label'] . '».');
}

$result = aiChatOnce([
    ['role' => 'system', 'content' => 'Απαντάς μόνο στα ελληνικά, με μία σύντομη πρόταση.'],
    ['role' => 'user',   'content' => 'Γράψε μία πρόταση που επιβεβαιώνει ότι η σύνδεση λειτουργεί.'],
], ['temperature' => 0.2, 'max_tokens' => 60, 'timeout' => 30], $cfg);

if (!$result['ok']) {
    // A failed test is usually a retired model name, and the provider's own
    // 404 does not say what to use instead. Ask the key what it can actually
    // call, so the answer arrives with the fix in it.
    $message = $result['error'];
    $listing = aiListModels($cfg);
    if ($listing['ok'] && $listing['models']) {
        $usable = aiChatModelsFromList($listing['models']);
        $message .= ' — Μοντέλα συνομιλίας διαθέσιμα για αυτό το key (νεότερα πρώτα): '
                  . implode(', ', array_slice($usable, 0, 30))
                  . (count($usable) > 30 ? ' …' : '')
                  . '. Αντιγράψτε ένα στο πεδίο «Μοντέλο» και αποθηκεύστε.';
    }
    $fail($message);
}

$reply   = trim(preg_replace('/\s+/u', ' ', $result['content']) ?? '');
$reply   = mb_substr($reply, 0, 160, 'UTF-8');
$seconds = number_format($result['ms'] / 1000, 1);

echo json_encode([
    'ok'      => true,
    'message' => 'Σύνδεση επιτυχής με ' . $cfg['provider_label'] . ' / ' . $cfg['model']
               . ' σε ' . $seconds . ' δευτ. — «' . $reply . '»',
], JSON_UNESCAPED_UNICODE);
