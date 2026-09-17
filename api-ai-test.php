<?php
/**
 * AJAX endpoint — test the stored AI provider settings end to end.
 * POST only, system admin required.
 * Returns JSON: { ok: bool, message: string }
 *
 * Deliberately a real (tiny) chat completion rather than a model list or a
 * ping: key, base URL and model name each fail differently, and only an
 * actual completion proves all three together. The provider's own error text
 * is passed through by aiChat(), because for the two mistakes that actually
 * happen — wrong key, retired model name — their message names the problem
 * exactly and a generic "failed" would send an admin guessing.
 */
require_once __DIR__ . '/bootstrap.php';
requireRole([ROLE_SYSTEM_ADMIN]);

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'message' => 'Μη έγκυρη μέθοδος'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string) $_POST['csrf_token'])) {
    echo json_encode(['ok' => false, 'message' => 'Μη έγκυρο αίτημα'], JSON_UNESCAPED_UNICODE);
    exit;
}

$cfg = aiConfig();
if ($cfg['api_key'] === '') {
    echo json_encode(['ok' => false, 'message' => 'Δεν έχει οριστεί API key για τον πάροχο «' . $cfg['provider_label'] . '».'], JSON_UNESCAPED_UNICODE);
    exit;
}

// The master switch gates the feature, not the test — an admin has to be able
// to verify a key BEFORE turning anything on for everyone.
$result = aiChat([
    ['role' => 'system', 'content' => 'Απαντάς μόνο στα ελληνικά, με μία σύντομη πρόταση.'],
    ['role' => 'user',   'content' => 'Γράψε μία πρόταση που επιβεβαιώνει ότι η σύνδεση λειτουργεί.'],
], ['temperature' => 0.2, 'max_tokens' => 60, 'timeout' => 30, 'ignore_master_switch' => true]);

if (!$result['ok']) {
    // A failed test is usually a retired model name, and the provider's own
    // 404 does not say what to use instead. Ask the key what it can actually
    // call, so the answer arrives with the fix in it.
    $message = $result['error'];
    $listing = aiListModels();
    if ($listing['ok'] && $listing['models']) {
        $usable = array_values(array_filter(
            $listing['models'],
            fn($m) => !preg_match('/embedding|imagen|veo|aqa|-tts|vision-exp|learnlm/i', $m)
        ));
        if (!$usable) $usable = $listing['models'];
        $message .= ' — Διαθέσιμα μοντέλα για αυτό το key: ' . implode(', ', array_slice($usable, 0, 25))
                  . (count($usable) > 25 ? ' …' : '')
                  . '. Αντιγράψτε ένα από αυτά στο πεδίο «Μοντέλο» και αποθηκεύστε.';
    }
    echo json_encode(['ok' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

$reply   = trim(preg_replace('/\s+/u', ' ', $result['content']) ?? '');
$reply   = mb_substr($reply, 0, 160, 'UTF-8');
$seconds = number_format($result['ms'] / 1000, 1);

echo json_encode([
    'ok'      => true,
    'message' => 'Σύνδεση επιτυχής με ' . $cfg['provider_label'] . ' / ' . $cfg['model']
               . ' σε ' . $seconds . ' δευτ. — «' . $reply . '»',
], JSON_UNESCAPED_UNICODE);
