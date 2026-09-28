<?php
/**
 * The place named at the end of the clock strip (includes/header.php):
 * «Δευτέρα 28/09/2026 - 00:14:05 Ηράκλειο».
 *
 * On a page about one mission it is the prefecture (Περιφερειακή Ενότητα)
 * the mission's map pin falls in; everywhere else it is the organisation's
 * own, picked once in Settings (clock_default_place). The strip used to say
 * «Ώρα Αθήνας», which named the time zone, not where anybody was.
 *
 * Prefecture rather than town because OpenStreetMap names Greek towns and
 * municipalities officially and in the genitive («Δήμος Ανωγείων»,
 * «Δημοτική Ενότητα Γαζίου»), and a seafront pin a few hundred metres past
 * the city line comes back as the next municipality over. The 74 prefectures
 * are a closed list, so each gets a proper name here in both languages.
 */

if (!defined('VOLUNTEEROPS')) {
    die('Direct access not permitted');
}

/** How long a failed lookup is left alone before it is tried again. */
const CLOCK_PLACE_RETRY_SECONDS = 3600;

/**
 * Every Greek prefecture, keyed by its OpenStreetMap name with the
 * «Περιφερειακή Ενότητα» / «Μητροπολιτική Ενότητα» prefix taken off (the
 * genitive, exactly as OSM spells it), => [Greek name, English name].
 * The keys were read off OSM's 74 admin_level=6 boundaries in Greece on
 * 2026-09-28. The four Athens sectors are all «Αθήνα»: they are one city.
 */
function clockPlaces(): array
{
    return [
        'Αιτωλοακαρνανίας'              => ['Αιτωλοακαρνανία', 'Aetolia-Acarnania'],
        'Ανατολικής Αττικής'            => ['Ανατολική Αττική', 'East Attica'],
        'Άνδρου'                        => ['Άνδρος', 'Andros'],
        'Αργολίδος'                     => ['Αργολίδα', 'Argolis'],
        'Αρκαδίας'                      => ['Αρκαδία', 'Arcadia'],
        'Άρτας'                         => ['Άρτα', 'Arta'],
        'Αχαΐας'                        => ['Αχαΐα', 'Achaea'],
        'Βοιωτίας'                      => ['Βοιωτία', 'Boeotia'],
        'Βορείου Τομέα Αθηνών'          => ['Αθήνα', 'Athens'],
        'Γρεβενών'                      => ['Γρεβενά', 'Grevena'],
        'Δράμας'                        => ['Δράμα', 'Drama'],
        'Δυτικής Αττικής'               => ['Δυτική Αττική', 'West Attica'],
        'Δυτικού Τομέα Αθηνών'          => ['Αθήνα', 'Athens'],
        'Έβρου'                         => ['Έβρος', 'Evros'],
        'Ευβοίας'                       => ['Εύβοια', 'Euboea'],
        'Ευρυτανίας'                    => ['Ευρυτανία', 'Evrytania'],
        'Ζακύνθου'                      => ['Ζάκυνθος', 'Zakynthos'],
        'Ηλείας'                        => ['Ηλεία', 'Elis'],
        'Ημαθίας'                       => ['Ημαθία', 'Imathia'],
        'Ηρακλείου'                     => ['Ηράκλειο', 'Heraklion'],
        'Θάσου'                         => ['Θάσος', 'Thasos'],
        'Θεσπρωτίας'                    => ['Θεσπρωτία', 'Thesprotia'],
        'Θεσσαλονίκης'                  => ['Θεσσαλονίκη', 'Thessaloniki'],
        'Θήρας'                         => ['Θήρα', 'Thira'],
        'Ιθάκης'                        => ['Ιθάκη', 'Ithaca'],
        'Ικαρίας'                       => ['Ικαρία', 'Ikaria'],
        'Ιωαννίνων'                     => ['Ιωάννινα', 'Ioannina'],
        'Καβάλας'                       => ['Καβάλα', 'Kavala'],
        'Καλύμνου'                      => ['Κάλυμνος', 'Kalymnos'],
        'Καρδίτσας'                     => ['Καρδίτσα', 'Karditsa'],
        'Καρπάθου-Ηρωικής Νήσου Κάσου'  => ['Κάρπαθος - Κάσος', 'Karpathos - Kasos'],
        'Καστοριάς'                     => ['Καστοριά', 'Kastoria'],
        'Κέας - Κύθνου'                 => ['Κέα - Κύθνος', 'Kea - Kythnos'],
        'Κεντρικού Τομέα Αθηνών'        => ['Αθήνα', 'Athens'],
        'Κέρκυρας'                      => ['Κέρκυρα', 'Corfu'],
        'Κεφαλληνίας'                   => ['Κεφαλονιά', 'Kefalonia'],
        'Κιλκίς'                        => ['Κιλκίς', 'Kilkis'],
        'Κοζάνης'                       => ['Κοζάνη', 'Kozani'],
        'Κορινθίας'                     => ['Κορινθία', 'Corinthia'],
        'Κω'                            => ['Κως', 'Kos'],
        'Λακωνίας'                      => ['Λακωνία', 'Laconia'],
        'Λάρισας'                       => ['Λάρισα', 'Larissa'],
        'Λασιθίου'                      => ['Λασίθι', 'Lasithi'],
        'Λέσβου'                        => ['Λέσβος', 'Lesbos'],
        'Λευκάδας'                      => ['Λευκάδα', 'Lefkada'],
        'Λήμνου'                        => ['Λήμνος', 'Lemnos'],
        'Μαγνησίας'                     => ['Μαγνησία', 'Magnesia'],
        'Μεσσηνίας'                     => ['Μεσσηνία', 'Messenia'],
        'Μήλου'                         => ['Μήλος', 'Milos'],
        'Μυκόνου'                       => ['Μύκονος', 'Mykonos'],
        'Νάξου'                         => ['Νάξος', 'Naxos'],
        'Νήσων'                         => ['Νησιά Αττικής', 'Attica Islands'],
        'Νοτίου Τομέα Αθηνών'           => ['Αθήνα', 'Athens'],
        'Ξάνθης'                        => ['Ξάνθη', 'Xanthi'],
        'Πάρου'                         => ['Πάρος', 'Paros'],
        'Πειραιώς'                      => ['Πειραιάς', 'Piraeus'],
        'Πέλλας'                        => ['Πέλλα', 'Pella'],
        'Πιερίας'                       => ['Πιερία', 'Pieria'],
        'Πρέβεζας'                      => ['Πρέβεζα', 'Preveza'],
        'Ρεθύμνης'                      => ['Ρέθυμνο', 'Rethymno'],
        'Ροδόπης'                       => ['Ροδόπη', 'Rhodope'],
        'Ρόδου'                         => ['Ρόδος', 'Rhodes'],
        'Σάμου'                         => ['Σάμος', 'Samos'],
        'Σερρών'                        => ['Σέρρες', 'Serres'],
        'Σποράδων'                      => ['Σποράδες', 'Sporades'],
        'Σύρου'                         => ['Σύρος', 'Syros'],
        'Τήνου'                         => ['Τήνος', 'Tinos'],
        'Τρικάλων'                      => ['Τρίκαλα', 'Trikala'],
        'Φθιώτιδας'                     => ['Φθιώτιδα', 'Phthiotis'],
        'Φλώρινας'                      => ['Φλώρινα', 'Florina'],
        'Φωκίδας'                       => ['Φωκίδα', 'Phocis'],
        'Χαλκιδικής'                    => ['Χαλκιδική', 'Chalkidiki'],
        'Χανίων'                        => ['Χανιά', 'Chania'],
        'Χίου'                          => ['Χίος', 'Chios'],
    ];
}

/**
 * A prefecture name reduced to what cannot be spelt two ways: no accents, no
 * case, no spaces or hyphens. OSM writes «Κέας - Κύθνου» but
 * «Καρπάθου-Ηρωικής Νήσου Κάσου», and an editor may tidy either one.
 */
function clockPlaceMatchKey(string $name): string
{
    $name = mb_strtolower(trim($name), 'UTF-8');
    $name = strtr($name, [
        'ά' => 'α', 'έ' => 'ε', 'ή' => 'η', 'ί' => 'ι', 'ό' => 'ο', 'ύ' => 'υ', 'ώ' => 'ω',
        'ϊ' => 'ι', 'ΐ' => 'ι', 'ϋ' => 'υ', 'ΰ' => 'υ', 'ς' => 'σ',
    ]);
    return preg_replace('/[\s\-‐–—]+/u', '', $name);
}

/**
 * The name to show for a prefecture, from the OpenStreetMap name of its
 * boundary («Περιφερειακή Ενότητα Ηρακλείου» → «Ηράκλειο» / «Heraklion»).
 * A key from clockPlaces() is taken as it is. Anything not in the list — a
 * pin outside Greece, or a boundary OSM has since renamed — is shown in its
 * official form, «Π.Ε. <genitive>», rather than guessed at.
 */
function clockPlaceName(string $osmName, string $lang, string $osmNameEn = ''): string
{
    $osmName = trim($osmName);
    if ($osmName === '') return '';
    $short = preg_replace('/^(Περιφερειακή|Μητροπολιτική)\s+Ενότητα\s+/u', '', $osmName);

    static $index = null;
    if ($index === null) {
        $index = [];
        foreach (clockPlaces() as $key => $names) {
            $index[clockPlaceMatchKey($key)] = $names;
        }
    }
    $names = $index[clockPlaceMatchKey($short)] ?? null;
    if ($names) {
        return $lang === 'en' ? $names[1] : $names[0];
    }

    if ($lang === 'en' && trim($osmNameEn) !== '') {
        return trim(preg_replace('/^Regional Unit of\s+|\s+Regional Unit$/u', '', trim($osmNameEn)));
    }
    return $short !== $osmName ? 'Π.Ε. ' . $short : $osmName;
}

/**
 * Ask OpenStreetMap which prefecture a point is in. At zoom 8 Nominatim
 * answers with the prefecture's own boundary, so its name is the result.
 * Returns ['county' => Greek name, 'county_en' => English name or ''],
 * ['county' => null] when the point is in no prefecture (the sea), or null
 * when the service could not be reached.
 */
function clockPlaceLookup(float $lat, float $lng): ?array
{
    if (!function_exists('curl_init')) return null;
    $ch = curl_init('https://nominatim.openstreetmap.org/reverse?' . http_build_query([
        'format'          => 'jsonv2',
        'lat'             => $lat,
        'lon'             => $lng,
        'zoom'            => 8,
        'addressdetails'  => 1,
        'namedetails'     => 1,
        'accept-language' => 'el',
    ]));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT        => 3,
        // Nominatim's usage policy requires an identifying agent; the same
        // one includes/ai-places.php sends.
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (compatible; VolunteerOps/' . APP_VERSION . ')',
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $status !== 200) return null;

    $data = json_decode((string) $body, true);
    if (!is_array($data)) return null;
    if (isset($data['error'])) return ['county' => null, 'county_en' => ''];

    $county = $data['address']['county'] ?? (($data['addresstype'] ?? '') === 'county' ? ($data['name'] ?? '') : '');
    if ($county === '') return ['county' => null, 'county_en' => ''];
    $en = (($data['addresstype'] ?? '') === 'county') ? ($data['namedetails']['name:en'] ?? '') : '';
    return ['county' => mb_substr($county, 0, 150), 'county_en' => mb_substr((string) $en, 0, 150)];
}

/**
 * The prefecture a mission's map pin is in, named in $lang, or null when the
 * mission has no pin or it is not known yet.
 *
 * Looked up once per pin and kept in mission_place_cache; moving the pin
 * looks it up again. A failed lookup is left alone for an hour, so an
 * unreachable Nominatim costs one page a few seconds per hour, not every
 * page. Only one request at a time looks up a given mission (GET_LOCK): at
 * the start of a drill thirty people open the Action Room together, and the
 * other twenty-nine get the default place for that one page rather than
 * thirty requests to a service that asks for one a second.
 *
 * $lookup replaces the Nominatim call (tests).
 */
function missionClockPlace(array $mission, string $lang, ?callable $lookup = null): ?string
{
    $missionId = (int) ($mission['id'] ?? 0);
    $lat = $mission['latitude'] ?? null;
    $lng = $mission['longitude'] ?? null;
    if ($missionId <= 0 || $lat === null || $lng === null || $lat === '' || $lng === '') return null;
    $lat = (float) $lat;
    $lng = (float) $lng;
    if (abs($lat) > 90 || abs($lng) > 180 || ($lat == 0.0 && $lng == 0.0)) return null;

    try {
        $row = dbFetchOne(
            "SELECT lat, lng, county, county_en, looked_up_at FROM mission_place_cache WHERE mission_id = ?",
            [$missionId]
        );
    } catch (Throwable $e) {
        return null; // table not there yet (migration pending)
    }

    $samePin = $row && abs((float) $row['lat'] - $lat) < 0.000001 && abs((float) $row['lng'] - $lng) < 0.000001;
    if ($samePin && $row['county'] !== null) {
        return clockPlaceName($row['county'], $lang, (string) $row['county_en']) ?: null;
    }
    if ($samePin && strtotime($row['looked_up_at']) > time() - CLOCK_PLACE_RETRY_SECONDS) {
        return null;
    }

    $lockName = 'vo_clock_place_' . $missionId;
    try {
        if ((int) dbFetchValue("SELECT GET_LOCK(?, 0)", [$lockName]) !== 1) return null;
    } catch (Throwable $e) {
        return null;
    }
    try {
        $found = $lookup ? $lookup($lat, $lng) : clockPlaceLookup($lat, $lng);
        $county = $found['county'] ?? null;
        $countyEn = $found['county_en'] ?? '';
        dbExecute(
            "INSERT INTO mission_place_cache (mission_id, lat, lng, county, county_en, looked_up_at)
             VALUES (?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE lat = VALUES(lat), lng = VALUES(lng), county = VALUES(county),
                                     county_en = VALUES(county_en), looked_up_at = VALUES(looked_up_at)",
            [$missionId, $lat, $lng, $county, $countyEn !== '' ? $countyEn : null]
        );
    } catch (Throwable $e) {
        $county = null;
    } finally {
        try { dbFetchValue("SELECT RELEASE_LOCK(?)", [$lockName]); } catch (Throwable $e) {}
    }
    return $county !== null ? (clockPlaceName($county, $lang, $countyEn) ?: null) : null;
}

/**
 * What the clock strip names: the mission's prefecture on a page about one
 * mission (a page sets $clockMission before including header.php), the
 * default from Settings otherwise, or '' when neither is known.
 * $default replaces the setting (tests: getSetting() caches for the process).
 */
function clockPlaceLabel(?array $mission, string $lang, ?string $default = null, ?callable $lookup = null): string
{
    if ($mission) {
        $place = missionClockPlace($mission, $lang, $lookup);
        if ($place !== null && $place !== '') return $place;
    }
    $default = trim($default ?? (string) getSetting('clock_default_place', ''));
    return $default !== '' ? clockPlaceName($default, $lang) : '';
}
