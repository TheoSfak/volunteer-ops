<?php
/**
 * VolunteerOps — how far a person is from where they were sent.
 *
 * Two numbers, always both, because in mountain search they answer different
 * questions. The straight line is what a team on foot actually faces and it
 * never fails; the routed distance is what a vehicle drives and it is the one
 * a coordinator can hold against a clock. Given only the first nobody can plan
 * a pickup; given only the second a team four hundred metres up a slope reads
 * as eleven kilometres away, because the router went round by the road.
 *
 * WHAT LEAVES THIS BUILDING. A pair of coordinates and nothing else — no name,
 * no pseudonym, no team, no mission, no identifier of any kind. The router is
 * asked "how far between these two spots" and cannot tell who is standing on
 * either of them. That is a deliberately smaller exposure than the AI digest,
 * which is why this path may carry real coordinates while that one is
 * forbidden them (see the leak gate in ai-context.php).
 *
 * OSRM by default: free, keyless, already used by the Action Room for team
 * ETA, and driving-only. A Google Routes key in Settings switches to WALK
 * mode, which is what actually matches a search team on foot, at the
 * organisation's own cost and against its own account.
 */

if (!defined('VOLUNTEEROPS')) {
    die('Direct access not permitted');
}

/**
 * The whole batch's budget, in seconds.
 *
 * One number for every leg together rather than per leg, because the legs run
 * in parallel. This sits inside a request that is already waiting on an AI
 * provider, and a coordinator asking a question mid-operation will not wait
 * twice.
 */
const ROUTE_DISTANCE_TIMEOUT = 5;

/**
 * How many legs one digest may route.
 *
 * A large operation has sixty people on shift. Routing every one of them would
 * be sixty outbound calls per question, to a free public router that asks not
 * to be used that way, from a machine this app has already been taken down on
 * once by connection exhaustion. Everyone still gets the straight line, which
 * costs nothing but arithmetic.
 */
const ROUTE_DISTANCE_MAX_LEGS = 8;

/** Below this the two points are the same place and routing is theatre. */
const ROUTE_DISTANCE_MIN_METRES = 150;

/**
 * Which router is in use, and in what mode.
 *
 * Keyed on the key being present rather than on a separate switch: an
 * organisation that does not want Google used simply does not store a key, and
 * one less setting to reason about is worth more than one more degree of
 * control — the same rule the AI provider chain follows.
 */
function routeDistanceProvider(): array {
    return routeDistanceProviderFor((string) getSetting('google_maps_api_key', ''));
}

/**
 * The same decision without the settings lookup, so it can be exercised with
 * both answers in one test run — getSetting() caches statically per process
 * and could not otherwise be made to say two different things.
 */
function routeDistanceProviderFor(string $key): array {
    $key = trim($key);
    return $key !== ''
        ? ['name' => 'google', 'mode' => 'walking', 'key' => $key]
        : ['name' => 'osrm',   'mode' => 'driving', 'key' => null];
}

/** Whether a routed figure can be obtained at all on this install. */
function routeDistanceAvailable(): bool {
    return function_exists('curl_init') && function_exists('curl_multi_init');
}

/**
 * The body Google is sent for one leg.
 *
 * Its own function so it can be read back in a test. The cURL handle below
 * swallows it into CURLOPT_POSTFIELDS where nothing can inspect it, and this
 * is the path an organisation actually pays for — a silently malformed body
 * would show up as an invoice and an assistant that never has a routed
 * distance, which is not a symptom anybody traces to a JSON shape.
 *
 * WALK, not DRIVE: the whole reason for supporting Google at all is that a
 * search team is on foot, and the free router cannot say that.
 */
function routeDistanceGoogleBody(float $fromLat, float $fromLng, float $toLat, float $toLng): string {
    return json_encode([
        'origin'      => ['location' => ['latLng' => ['latitude' => $fromLat, 'longitude' => $fromLng]]],
        'destination' => ['location' => ['latLng' => ['latitude' => $toLat,   'longitude' => $toLng]]],
        'travelMode'  => 'WALK',
    ]);
}

/**
 * Build the request for one leg, for whichever provider is configured.
 */
function routeDistanceHandle(array $provider, float $fromLat, float $fromLng, float $toLat, float $toLng) {
    if ($provider['name'] === 'google') {
        $ch = curl_init('https://routes.googleapis.com/directions/v2:computeRoutes');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => ROUTE_DISTANCE_TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-Goog-Api-Key: ' . $provider['key'],
                // Two fields rather than the whole route: it keeps the response
                // small and, on Google's billing, keeps the request in the
                // cheapest tier the Routes API has.
                'X-Goog-FieldMask: routes.distanceMeters,routes.duration',
            ],
            CURLOPT_POSTFIELDS     => routeDistanceGoogleBody($fromLat, $fromLng, $toLat, $toLng),
        ]);
        return $ch;
    }

    // OSRM wants lng,lat — the opposite order to everything else in this app,
    // which is exactly the kind of mistake that returns a plausible wrong
    // answer rather than an error.
    $url = sprintf(
        'https://router.project-osrm.org/route/v1/driving/%s,%s;%s,%s?overview=false',
        sprintf('%.6f', $fromLng), sprintf('%.6f', $fromLat),
        sprintf('%.6f', $toLng),   sprintf('%.6f', $toLat)
    );
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => ROUTE_DISTANCE_TIMEOUT,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; VolunteerOps/' . APP_VERSION . ')',
    ]);
    return $ch;
}

/**
 * Pull metres and minutes out of whichever provider answered.
 *
 * Returns null for anything unexpected rather than guessing. A routed distance
 * that is quietly wrong is worse than one that is absent, because the absent
 * one leaves the straight line on screen and says that is what it is.
 */
function routeDistanceParse(string $provider, ?string $body): ?array {
    if ($body === null || $body === '') return null;
    $data = json_decode($body, true);
    if (!is_array($data)) return null;

    if ($provider === 'google') {
        $route = $data['routes'][0] ?? null;
        if (!is_array($route) || !isset($route['distanceMeters'])) return null;
        // Google states a duration as the string "1234s".
        $seconds = isset($route['duration']) ? (int) rtrim((string) $route['duration'], 's') : 0;
        return ['meters' => (int) $route['distanceMeters'], 'minutes' => (int) round($seconds / 60)];
    }

    if (($data['code'] ?? '') !== 'Ok' || !isset($data['routes'][0]['distance'])) return null;
    return [
        'meters'  => (int) round((float) $data['routes'][0]['distance']),
        'minutes' => (int) round(((float) ($data['routes'][0]['duration'] ?? 0)) / 60),
    ];
}

/**
 * Two points on a real road, for the settings page's connection test.
 *
 * Heraklion centre to Knossos: about five kilometres, unambiguously connected
 * by asphalt, so a failure here is the key or the network and never "there is
 * no route". The same city the weather test uses, for the same reason.
 */
const ROUTE_DISTANCE_PROBE_LEG = [35.3387, 25.1442, 35.2980, 25.1630];

/**
 * One real routing call, with the provider's own error kept.
 *
 * Separate from routeDistanceBatch() precisely because that one SWALLOWS
 * failures — it has a straight line to fall back on and a coordinator who
 * cannot act on "HTTP 403". An admin checking a key can act on it, and
 * "PERMISSION_DENIED: Routes API has not been used in project…" is the
 * difference between five seconds and an afternoon.
 *
 * Returns ['ok' => bool, 'message' => string, 'provider' => string].
 */
function routeDistanceProbe(?string $apiKey = null): array {
    if (!routeDistanceAvailable()) {
        return ['ok' => false, 'provider' => 'none',
                'message' => 'Η επέκταση cURL δεν είναι διαθέσιμη στον server'];
    }

    $provider = routeDistanceProviderFor((string) ($apiKey ?? getSetting('google_maps_api_key', '')));
    [$fromLat, $fromLng, $toLat, $toLng] = ROUTE_DISTANCE_PROBE_LEG;

    $ch = routeDistanceHandle($provider, $fromLat, $fromLng, $toLat, $toLng);
    $body  = curl_exec($ch);
    $code  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($body === false || $error !== '') {
        return ['ok' => false, 'provider' => $provider['name'],
                'message' => 'Σφάλμα δικτύου: ' . ($error !== '' ? $error : 'άγνωστο σφάλμα')];
    }

    if ($code !== 200) {
        return ['ok' => false, 'provider' => $provider['name'],
                'message' => 'HTTP ' . $code . ' — ' . routeDistanceErrorText($provider['name'], (string) $body)];
    }

    $parsed = routeDistanceParse($provider['name'], (string) $body);
    if ($parsed === null) {
        return ['ok' => false, 'provider' => $provider['name'],
                'message' => 'Η απάντηση δεν περιείχε διαδρομή — ' . routeDistanceErrorText($provider['name'], (string) $body)];
    }

    $how = $provider['mode'] === 'walking' ? 'με τα πόδια' : 'οδικώς';
    $km  = round($parsed['meters'] / 1000, 1);
    return [
        'ok'       => true,
        'provider' => $provider['name'],
        'message'  => ($provider['name'] === 'google' ? 'Google Routes' : 'OSRM')
            . ': Ηράκλειο → Κνωσός ' . $km . ' χλμ ' . $how
            . ($parsed['minutes'] ? ', ' . $parsed['minutes'] . ' λεπτά' : ''),
    ];
}

/**
 * The provider's own complaint, in as few words as it can be said.
 *
 * Google buries the useful sentence inside error.message and it is the one
 * that names the actual problem — a key restricted to the wrong referrer, the
 * Routes API never enabled on the project, billing not set up. Passed through
 * rather than replaced with something reassuring: whoever pressed the button
 * is the person who can go and fix it.
 */
function routeDistanceErrorText(string $provider, string $body): string {
    $data = json_decode($body, true);
    if ($provider === 'google' && is_array($data)) {
        $status  = (string) ($data['error']['status'] ?? '');
        $message = (string) ($data['error']['message'] ?? '');
        if ($message !== '') {
            return trim($status !== '' ? $status . ': ' . $message : $message);
        }
    }
    if (is_array($data) && isset($data['code'])) {
        return trim((string) $data['code'] . ' ' . (string) ($data['message'] ?? ''));
    }
    // Never the raw body: it can be a page of HTML from something in the way.
    return mb_substr(trim(strip_tags($body)), 0, 200);
}

/**
 * Route several legs at once.
 *
 * In parallel, deliberately. Eight legs run one after another at a five second
 * timeout each is forty seconds added to a question the coordinator is already
 * waiting on; run together they cost one timeout for the lot.
 *
 * $legs is [key => [fromLat, fromLng, toLat, toLng]]. The result is keyed the
 * same way and simply omits any leg that did not come back — every caller has
 * to handle a missing routed figure anyway, because the default router is a
 * free public service that is allowed to be down.
 */
function routeDistanceBatch(array $legs): array {
    if (!$legs || !routeDistanceAvailable()) return [];

    $legs = array_slice($legs, 0, ROUTE_DISTANCE_MAX_LEGS, true);

    // BOTH MODES, NOT ONE. A coordinator deciding who goes needs the two
    // numbers side by side: on foot is who can actually get there in this
    // terrain, by vehicle is who arrives first when a road happens to go the
    // right way. Reporting only one makes the other invisible, and the choice
    // between them is the decision being made.
    //
    // The two come from different routers because neither does both: the free
    // OSRM demo serves the driving profile only, and walking needs the Google
    // key. So an install with no key gets driving alone — which is stated as
    // driving, never passed off as the whole answer.
    $walking = [];
    $google  = routeDistanceProvider();
    if ($google['name'] === 'google') {
        // Google WALK FINDS NOTHING IN THE MOUNTAINS and says so by answering
        // 200 with an empty list rather than by failing, so this legitimately
        // comes back empty for a crew on a ridge. Reported from a live
        // mission: the settings button walked Heraklion to Knossos perfectly
        // well, because a city has a pedestrian network, while Psiloritis has
        // none mapped — and the figure then vanished with nothing to say why.
        $walking = routeDistanceRunBatch($google, $legs);
    }
    $driving = routeDistanceRunBatch(routeDistanceProviderFor(''), $legs);

    $out = [];
    foreach ($legs as $key => $_) {
        $w = $walking[$key] ?? null;
        $d = $driving[$key] ?? null;
        if ($w === null && $d === null) continue;
        // Whether a walking route was ASKED FOR matters as much as whether one
        // came back. With no Google key none is ever requested, and saying "no
        // walking time available" on every row would be reporting the absence
        // of something nobody looked for.
        $out[$key] = ['walking' => $w, 'driving' => $d, 'walk_tried' => $google['name'] === 'google'];
    }
    return $out;
}

/**
 * One parallel pass against one provider. Returns only the legs it answered.
 */
function routeDistanceRunBatch(array $provider, array $legs): array {
    if (!$legs) return [];

    $multi   = curl_multi_init();
    $handles = [];
    foreach ($legs as $key => $leg) {
        [$fromLat, $fromLng, $toLat, $toLng] = $leg;
        $ch = routeDistanceHandle($provider, (float) $fromLat, (float) $fromLng, (float) $toLat, (float) $toLng);
        if ($ch === null) continue;
        $handles[$key] = $ch;
        curl_multi_add_handle($multi, $ch);
    }

    $running = null;
    do {
        curl_multi_exec($multi, $running);
        if ($running) curl_multi_select($multi, 0.5);
    } while ($running > 0);

    $out = [];
    foreach ($handles as $key => $ch) {
        $body = curl_multi_getcontent($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_multi_remove_handle($multi, $ch);
        curl_close($ch);
        if ($code !== 200) {
            // Logged, not surfaced. A coordinator cannot act on "the router
            // said 429" and the straight line is still in front of them; an
            // admin reading the log can act on it.
            if ($code !== 0) error_log('[route-distance] ' . $provider['name'] . ' HTTP ' . $code);
            continue;
        }
        $parsed = routeDistanceParse($provider['name'], $body === false ? null : $body);
        if ($parsed !== null) {
            $out[$key] = $parsed + ['source' => $provider['name'], 'mode' => $provider['mode']];
        }
    }
    curl_multi_close($multi);

    return $out;
}
