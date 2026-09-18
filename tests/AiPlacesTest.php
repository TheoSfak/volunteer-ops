<?php

use PHPUnit\Framework\TestCase;

/**
 * Resolving a place a coordinator named in a question.
 *
 * Pure parts only — the geocoder itself is exercised by a scratchpad probe
 * against the real service, because a test that needs Nominatim to be up fails
 * for reasons that have nothing to do with this code.
 */
final class AiPlacesTest extends TestCase
{
    public function testAGenericWordIsRefusedRatherThanResolvedArbitrarily(): void
    {
        // Measured against the real service: «παραλία» alone came back as a
        // beach 39 km away, chosen from the hundreds that match. An arbitrary
        // answer stated as a distance is worse than no answer — a coordinator
        // can act on it.
        foreach (['παραλία', 'Παραλία', 'νοσοκομείο', 'σχολείο', 'κέντρο', 'βουνό'] as $vague) {
            $this->assertTrue(aiPlaceIsTooVague($vague), $vague);
        }

        // Two words is somebody naming a particular place.
        foreach (['παραλία Αμμουδάρα', 'Παγκρήτιο Στάδιο', 'Νοσοκομείο Βενιζέλειο', 'Μονή Βροντησίου'] as $specific) {
            $this->assertFalse(aiPlaceIsTooVague($specific), $specific);
        }

        // A single distinctive name is fine — it is the common NOUNS that are
        // ambiguous, not every one-word name.
        $this->assertFalse(aiPlaceIsTooVague('Ζαρός'));
        $this->assertFalse(aiPlaceIsTooVague('Ψηλορείτης'));

        $this->assertTrue(aiPlaceIsTooVague(''));
        $this->assertTrue(aiPlaceIsTooVague('   '));
    }

    public function testTheMatchIsNamedByItsHeadNotItsAdministrativeTail(): void
    {
        // Nominatim runs a display name out to the decentralised
        // administration and the country. A hard character cut lands mid-word
        // inside that boilerplate, and it is the FIRST parts that tell a
        // coordinator the match is wrong.
        $full = 'Κέντρο διασκέδασης Αστερούσια, Αγιά Φωτιά, Κοινότητα Χάρακος, '
              . 'Δημοτική Ενότητα Αστερουσίων, Δήμος Αρχανών - Αστερουσίων, '
              . 'Περιφερειακή Ενότητα Ηρακλείου, Περιφέρεια Κρήτης, Ελλάδα';
        $short = aiPlaceShortName($full);

        $this->assertStringStartsWith('Κέντρο διασκέδασης Αστερούσια', $short);
        $this->assertStringNotContainsString('Ελλάδα', $short);
        $this->assertLessThanOrEqual(110, mb_strlen($short, 'UTF-8'));
        // Whatever survives is whole — the cut is at a comma, not mid-word.
        $this->assertStringEndsNotWith(' ', $short);

        // A short name is left exactly alone.
        $this->assertSame('Ζαρός', aiPlaceShortName('Ζαρός'));
    }

    public function testGreekStreetsAreNamedAfterPeopleAndMustSurviveTheJourney(): void
    {
        // «Αντωνίου Καστρινάκη», «Νικολάου Πλαστήρα» — and those same surnames
        // belong to volunteers on the mission, so they sit on the forbidden
        // list. Running the question through the ordinary redactor turned
        // «από Αντωνίου Καστρινάκη 65» into «από [όνομα] Καστρινάκη 65» and the
        // geocoder never saw the street at all.
        //
        // Masked, not erased: a token goes to the model and the real word comes
        // back before the map service is asked.
        $names = ['Αντωνίου', 'Νικολάου', 'Γιώργος'];

        foreach ([
            'πόσο απέχει η ΑΛΦΑ από Αντωνίου Καστρινάκη 65',
            'στείλε ομάδα στην Νικολάου Πλαστήρα 14 Ηράκλειο',
            'τι κάνει ο Γιώργος;',
        ] as $question) {
            [$masked, $map] = aiPlacesMaskNames($question, $names);

            // Nothing a provider sees is a real name…
            $this->assertSame([], aiScanDigestForLeaks(['q' => $masked], $names), $question);
            // …and the street comes back exactly as it was typed.
            $this->assertSame($question, aiPlacesUnmaskNames($masked, $map), $question);
        }

        // A question naming nobody is left completely alone.
        [$plain, $map] = aiPlacesMaskNames('απόσταση από το Παγκρήτιο Στάδιο', $names);
        $this->assertSame('απόσταση από το Παγκρήτιο Στάδιο', $plain);
        $this->assertSame([], $map);
    }

    public function testAFullGreekStreetNameIsSimplifiedUntilTheMapAnswers(): void
    {
        // Measured against the live service: «Αντωνίου Καστρινάκη 65» returns
        // nothing in every form — with the city, without the number, on the
        // structured endpoint — while «Καστρινάκη 65» returns the street at
        // once. OpenStreetMap holds these streets under a different
        // patronymic, so the surname is the only part both sides agree on.
        //
        // The coordinator types the address as they know it; the ladder does
        // the simplifying, which is the part they should not have to know.
        $this->assertSame(
            ['Αντωνίου Καστρινάκη 65', 'Καστρινάκη 65', 'Καστρινάκη'],
            aiPlaceQueryLadder('Αντωνίου Καστρινάκη 65')
        );
        $this->assertSame(
            ['Λεωφόρος Κνωσού 120', 'Κνωσού 120', 'Κνωσού'],
            aiPlaceQueryLadder('Λεωφόρος Κνωσού 120')
        );
        // Two words and a number: one step down, then the street alone.
        $this->assertSame(['Πλαστήρα 14', 'Πλαστήρα'], aiPlaceQueryLadder('Πλαστήρα 14'));
    }

    public function testOnlyThingsThatLookLikeAddressesAreLaddered(): void
    {
        // «Παγκρήτιο Στάδιο» laddered would end at «Στάδιο», which matches any
        // stadium in Greece — and every extra rung is a second of a
        // coordinator's time mid-operation. A house number is what says this
        // is an address.
        $this->assertSame(['Παγκρήτιο Στάδιο'], aiPlaceQueryLadder('Παγκρήτιο Στάδιο'));
        $this->assertSame(['Μονή Βροντησίου'], aiPlaceQueryLadder('Μονή Βροντησίου'));
        $this->assertSame(['Ζαρός'], aiPlaceQueryLadder('Ζαρός'));
        $this->assertSame([], aiPlaceQueryLadder('   '));
    }

    public function testTheLadderNeverEndsOnACommonNoun(): void
    {
        // «Οδός Παραλίας 12» must not fall back to «Παραλίας»… and must never
        // reach a bare «παραλία», which resolves to an arbitrary one of
        // hundreds.
        $ladder = aiPlaceQueryLadder('Λεωφόρος Παραλία 12');
        $this->assertNotContains('παραλία', $ladder);
        $this->assertNotContains('Παραλία', $ladder);
    }

    public function testTheLadderIsCappedSoAnAddressCannotCostTenLookups(): void
    {
        // Each rung is a lookup a second apart, in the middle of an operation.
        $long = aiPlaceQueryLadder('Στρατηγού Νικολάου Γεωργίου Παπαδοπούλου Πλαστήρα 14');
        $this->assertLessThanOrEqual(AI_PLACES_LADDER_MAX, count($long));
        $this->assertSame('Στρατηγού Νικολάου Γεωργίου Παπαδοπούλου Πλαστήρα 14', $long[0]);
    }

    public function testAHouseNumberThatWasNotFoundIsSaidOutLoud(): void
    {
        // Measured against the real service: «Καστρινάκη 65 Ηράκλειο» resolves
        // to the street «Καστρινάκη Εμμ.» and «Πλαστήρα 14 Ηράκλειο» to
        // «Νικολάου Πλαστήρα» — the number is dropped. A city street runs a
        // kilometre or two, so a distance to it is a distance to somewhere
        // along it. Unsaid, that is a wrong number with a confident face.
        $note = aiPlaceMissingHouseNumber('Καστρινάκη 65 Ηράκλειο', 'Καστρινάκη Εμμ., 1η Κοινότητα Ηρακλείου');
        $this->assertNotNull($note);
        $this->assertStringContainsString('65', $note);
        $this->assertStringContainsString('ΔΡΟΜΟΣ', $note);

        // The number IS in the match: nothing to warn about.
        $this->assertNull(aiPlaceMissingHouseNumber('Κνωσού 120', 'Λεωφόρος Κνωσού 120, Ηράκλειο'));
        // No number asked for: nothing to warn about.
        $this->assertNull(aiPlaceMissingHouseNumber('Παγκρήτιο Στάδιο', 'Παγκρήτιο Στάδιο, Ηράκλειο'));
        $this->assertNull(aiPlaceMissingHouseNumber('Ζαρός', 'Ζαρός, Δήμος Φαιστού'));
    }

    public function testTheExtractionPromptForbidsTheThingsThatLookLikePlaces(): void
    {
        // Team codenames, pseudonyms and sector labels all read like proper
        // nouns and none of them is on a map. Geocoding «ΑΛΦΑ» would return
        // somewhere, and that somewhere would be reported as a distance.
        $prompt = aiPlacesExtractionPrompt();

        foreach (['ΑΛΦΑ', 'ΜΕΛΟΣ-4', 'Τομέας', 'Ζώνη'] as $mustBeExcluded) {
            $this->assertStringContainsString($mustBeExcluded, $prompt, $mustBeExcluded);
        }
        $this->assertStringContainsString('κενή λίστα', $prompt, 'it must be allowed to find nothing');
        $this->assertStringContainsString('"places"', $prompt);
    }
}
