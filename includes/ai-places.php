<?php
/**
 * VolunteerOps — "how far is ALPHA from the Pankritio Stadium".
 *
 * The assistant has no tool calling, so a place named in a question has to be
 * pulled out and resolved BEFORE the answering call is made. Two steps:
 *
 *   1. a small, cheap model call that returns ONLY the place names it sees in
 *      the question — no reasoning, no prose, a list or nothing;
 *   2. Nominatim (OpenStreetMap), biased to the mission's own corner of the
 *      map, turning each name into a point.
 *
 * WHAT WAS FOUND IS ALWAYS NAMED BACK, and that is not a nicety. Measured
 * against the real service before any of this was built:
 *
 *   «Παγκρήτιο Στάδιο»            → correct
 *   «Μονή Βροντησίου»             → correct
 *   «κέντρο Ηρακλείου Κρήτης»     → «Κέντρο διασκέδασης Αστερούσια», 23 km away
 *   «παραλία»                     → a beach 39 km away, chosen arbitrarily
 *   «Παγκρήτιο Στάδιο Ηράκλειο»   → nothing, though the same name without the
 *                                    city worked
 *
 * So the geocoder is confidently wrong often enough that a coordinator must be
 * able to see what was measured to. Every resolved place travels with the full
 * name the service returned and its distance from the mission, so a match in
 * the wrong prefecture is obvious on sight rather than after a vehicle has
 * been sent.
 */

if (!defined('VOLUNTEEROPS')) {
    die('Direct access not permitted');
}

/** At most this many places are resolved for one question. */
const AI_PLACES_MAX = 2;

/** Seconds allowed for one Nominatim lookup. */
const AI_PLACES_TIMEOUT = 6;

/**
 * How far around the mission the geocoder is told to prefer results, in
 * degrees. Roughly 60 km — wide enough for the nearest city and its hospitals,
 * narrow enough that a common name does not resolve to the other end of the
 * country.
 */
const AI_PLACES_VIEWBOX_DEGREES = 0.55;

/**
 * Names too vague to resolve to anything meaningful on their own.
 *
 * Nominatim answers all of these — with an arbitrary one of the hundreds that
 * match. An arbitrary answer stated as a distance is worse than no answer, so
 * these are refused unless something else is said with them.
 */
const AI_PLACES_TOO_VAGUE = [
    'παραλια', 'θαλασσα', 'βουνο', 'ποταμι', 'φαραγγι', 'δασος', 'χωριο',
    'εκκλησια', 'σχολειο', 'νοσοκομειο', 'πλατεια', 'λιμανι', 'γεφυρα',
    'καταφυγιο', 'μοναστηρι', 'κεντρο', 'παρκο', 'σταδιο',
];

function aiPlacesExtractionPrompt(): string {
    return <<<'PROMPT'
Διαβάζεις ΜΙΑ ερώτηση συντονιστή έρευνας και διάσωσης και βγάζεις ΜΟΝΟ τα ονόματα τόπων που αναφέρει.

ΤΙ ΕΙΝΑΙ ΤΟΠΟΣ: πόλη, χωριό, οικισμός, βουνό, φαράγγι, μοναστήρι, στάδιο, νοσοκομείο, λιμάνι, παραλία με όνομα, δρόμος με όνομα — οτιδήποτε θα έβρισκε κανείς σε χάρτη.

ΤΙ ΔΕΝ ΕΙΝΑΙ ΤΟΠΟΣ:
- κωδικά ονόματα ομάδων (ΑΛΦΑ, ΒΗΤΑ, ΓΑΜΑ, Alpha 1, Bravo 2)
- ονόματα προσώπων ή ψευδώνυμα (ΜΕΛΟΣ-4, ΟΜΑΔΑ-2)
- ονόματα τομέων έρευνας («Τομέας Γ», «Ζώνη 75%»)
- η βάση, το σημείο εστίασης, το σημείο τελευταίας εμφάνισης — αυτά τα ξέρει ήδη το σύστημα

Γράψε το όνομα ΟΠΩΣ ΤΟ ΕΙΠΕ ο συντονιστής, χωρίς άρθρα και χωρίς προθέσεις: από «από το κέντρο του Ηρακλείου» γράφεις «κέντρο Ηρακλείου».

Αν δεν αναφέρεται κανένας τόπος, γύρνα κενή λίστα. Μην μαντεύεις και μην προσθέτεις τόπους που δεν ειπώθηκαν.

Απαντάς αποκλειστικά με ένα έγκυρο αντικείμενο json, χωρίς κείμενο πριν ή μετά:

{"places": ["κέντρο Ηρακλείου"]}
PROMPT;
}

/**
 * The place names a question mentions, or [].
 *
 * Deliberately its own small call rather than a field on the main answer: by
 * the time the answering model has replied it is too late to geocode anything
 * and put real distances in front of it. Kept cheap — a tiny output cap, zero
 * temperature, and a short timeout, because this sits in front of a request
 * the coordinator is already waiting on.
 */
function aiPlacesFromQuestion(string $safeQuestion): array {
    $safeQuestion = trim($safeQuestion);
    if ($safeQuestion === '' || !aiIsConfigured()) return [];

    $result = aiChat([
        ['role' => 'system', 'content' => aiPlacesExtractionPrompt()],
        ['role' => 'user',   'content' => $safeQuestion],
    ], ['json' => true, 'temperature' => 0, 'max_tokens' => 400, 'timeout' => 20]);

    if (!$result['ok'] || !is_array($result['json'])) return [];

    $out = [];
    foreach ((array) ($result['json']['places'] ?? []) as $name) {
        if (!is_string($name)) continue;
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
        if ($name === '' || mb_strlen($name, 'UTF-8') < 3) continue;
        $out[] = mb_substr($name, 0, 80, 'UTF-8');
        if (count($out) >= AI_PLACES_MAX) break;
    }
    return $out;
}

/**
 * Whether a name is too generic to resolve on its own.
 *
 * One word, and that word a common noun for a KIND of place rather than a
 * particular one. «παραλία» matches hundreds; «παραλία Αμμουδάρα» does not.
 */
function aiPlaceIsTooVague(string $name): bool {
    $folded = aiFoldGreek(trim($name));
    if ($folded === '') return true;
    $words = preg_split('/\s+/u', $folded, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (count($words) > 1) return false;
    return in_array($words[0], AI_PLACES_TOO_VAGUE, true);
}

/**
 * One name into one point, preferring results near the mission.
 *
 * Returns ['ζητηθηκε' => what was asked for, 'βρεθηκε_ως' => what the service
 * actually returned, 'lat', 'lng'] or null. The two names are kept separate on
 * purpose: they are frequently not the same place, and the caller has to be
 * able to show both.
 */
function aiGeocodePlaceNearMission(string $name, ?float $nearLat, ?float $nearLng): ?array {
    if (!function_exists('curl_init')) return null;

    $params = [
        'format'          => 'json',
        'limit'           => '1',
        'countrycodes'    => 'gr',
        'accept-language' => 'el',
        'q'               => $name,
    ];
    if ($nearLat !== null && $nearLng !== null) {
        // A box around the mission. Not `bounded=1`: a coordinator legitimately
        // asks about the city two hours away that the ambulance is coming from,
        // and hard-clipping would simply lose it.
        $d = AI_PLACES_VIEWBOX_DEGREES;
        $params['viewbox'] = ($nearLng - $d) . ',' . ($nearLat + $d) . ',' . ($nearLng + $d) . ',' . ($nearLat - $d);
    }

    $ch = curl_init('https://nominatim.openstreetmap.org/search?' . http_build_query($params));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => AI_PLACES_TIMEOUT,
        CURLOPT_SSL_VERIFYPEER => true,
        // Nominatim's usage policy requires an identifying agent; the same one
        // geocode-address.php has always sent.
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; VolunteerOps/' . APP_VERSION . ')',
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200 || !$body) return null;
    $rows = json_decode((string) $body, true);
    if (!is_array($rows) || !isset($rows[0]['lat'], $rows[0]['lon'])) return null;

    return [
        'ζητηθηκε'   => $name,
        'βρεθηκε_ως' => aiPlaceShortName((string) ($rows[0]['display_name'] ?? $name)),
        'lat'        => (float) $rows[0]['lat'],
        'lng'        => (float) $rows[0]['lon'],
    ];
}

/**
 * The useful head of a Nominatim display name.
 *
 * The full string runs to the decentralised administration and the country —
 * «Παγκρήτιο Στάδιο, Βοιωτίας, 3η Κοινότητα Ηρακλείου - Δυτική, Δημοτική
 * Ενότητα Ηρακλείου, Δήμος Ηρακλείου, Περιφερειακή Ενότητα…» — and a hard
 * character cut lands mid-word in the middle of that boilerplate. The first
 * few parts are what actually identify the place, and they are what tells a
 * coordinator the match is wrong.
 */
function aiPlaceShortName(string $displayName): string {
    $parts = array_slice(array_map('trim', explode(',', $displayName)), 0, 4);
    return mb_substr(implode(', ', array_filter($parts, fn($p) => $p !== '')), 0, 110, 'UTF-8');
}

/**
 * Everything the question named, resolved to points.
 *
 * Nominatim asks for no more than one request a second and this is a free
 * public service, so the lookups are spaced — with AI_PLACES_MAX at two that
 * is one extra second on a question that mentions two places, and none on the
 * overwhelming majority that mention one or none.
 */
function aiResolveQuestionPlaces(string $safeQuestion, ?float $nearLat, ?float $nearLng): array {
    $resolved = [];
    $first = true;
    foreach (aiPlacesFromQuestion($safeQuestion) as $name) {
        if (aiPlaceIsTooVague($name)) {
            $resolved[] = ['ζητηθηκε' => $name, 'ασαφες' => true];
            continue;
        }
        if (!$first) usleep(1100000);
        $first = false;
        $hit = aiGeocodePlaceNearMission($name, $nearLat, $nearLng);
        $resolved[] = $hit ?? ['ζητηθηκε' => $name, 'δεν_βρεθηκε' => true];
    }
    return $resolved;
}
