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
