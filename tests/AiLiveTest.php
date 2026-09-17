<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * includes/ai-live.php — «Ρώτα τον Βοηθό», the Action Room's live AI chat.
 *
 * What is worth pinning here is not the prose the model writes; it is
 * everything that decides WHAT LEAVES THIS SERVER and what a claim is allowed
 * to assert. Those are pure functions and they are tested without a database
 * and without a provider.
 *
 * The three properties these tests exist to defend:
 *   1. A position can never be expressed as a coordinate.
 *   2. A person named in free text resolves to the pseudonym that person
 *      already has in the data — not to a stranger, and not to nothing.
 *   3. A citation the coordinator is shown always resolves to a real record.
 */
final class AiLiveTest extends TestCase
{
    // ── Positions leave as words, never as numbers ──────────────────────────

    public function testAPositionIsNeverExpressedAsACoordinate(): void
    {
        // Heraklion base, a point a few kilometres away. Whatever comes back,
        // it must not contain anything a coordinate could hide in — the leak
        // gate treats five decimal places as a GPS fix, and a raw lat/lng
        // would stop the whole feature rather than leak, but the real point is
        // that live rescuer GPS must not reach a third-country provider at all.
        $out = aiLiveRelativePosition(35.3387, 25.1442, 35.3100, 25.1000);

        $this->assertIsString($out);
        $this->assertDoesNotMatchRegularExpression('/\d+\.\d{3,}/', $out);
        $this->assertStringNotContainsString('35.3', $out);
        $this->assertStringNotContainsString('25.1', $out);
        $this->assertStringContainsString('από τη βάση', $out);
    }

    public function testAnUnknownPositionSaysNothingRatherThanGuessing(): void
    {
        // An invented position in a live search sends people to the wrong
        // place. Null is the caller's signal to omit the field entirely.
        $this->assertNull(aiLiveRelativePosition(null, null, 35.3, 25.1));
        $this->assertNull(aiLiveRelativePosition(35.3, 25.1, null, null));
        $this->assertNull(aiLiveRelativePosition(35.3, null, 35.3, 25.1));
    }

    public function testShortDistancesAreMetresAndLongOnesAreKilometres(): void
    {
        // Same point: zero metres, and crucially not "0 χλμ", which reads as
        // "somewhere within a kilometre" when it means "right here".
        $this->assertStringContainsString('μ', aiLiveRelativePosition(35.3387, 25.1442, 35.3387, 25.1442));
        $this->assertStringNotContainsString('χλμ', aiLiveRelativePosition(35.3387, 25.1442, 35.3387, 25.1442));

        // ~11 km due north of the base.
        $far = aiLiveRelativePosition(35.4387, 25.1442, 35.3387, 25.1442);
        $this->assertStringContainsString('χλμ', $far);
        $this->assertStringContainsString('Β', $far);
    }

    public function testTheCompassCoversEveryQuadrantAndWrapsCleanly(): void
    {
        $this->assertSame('Β',  aiLiveCompassLabel(0.0));
        $this->assertSame('ΒΑ', aiLiveCompassLabel(45.0));
        $this->assertSame('Α',  aiLiveCompassLabel(90.0));
        $this->assertSame('Ν',  aiLiveCompassLabel(180.0));
        $this->assertSame('Δ',  aiLiveCompassLabel(270.0));
        // 359° is north, not north-west, and 360/720 must not index past the end.
        $this->assertSame('Β', aiLiveCompassLabel(359.0));
        $this->assertSame('Β', aiLiveCompassLabel(360.0));
        $this->assertSame('Β', aiLiveCompassLabel(720.0));
        $this->assertSame('Β', aiLiveCompassLabel(-1.0));
    }

    // ── People in free text ────────────────────────────────────────────────

    public function testANameInFreeTextResolvesToThatPersonsOwnPseudonym(): void
    {
        // The case this exists for: a field message referring to the man whose
        // SOS is two sections above. Plain redaction would turn him into an
        // anonymous [όνομα] and the model could no longer join the two.
        $map = ['ΜΕΛΟΣ-9' => 'Νίκος Βαρδάκης', 'ΜΕΛΟΣ-4' => 'Μαρία Παπαδάκη'];
        $out = aiLivePseudonymiseText(
            'ΒΡΑΒΟ αλλάξτε κατεύθυνση στο σημείο του Βαρδάκη.',
            $map,
            ['Βαρδάκης', 'Νίκος', 'Παπαδάκη', 'Μαρία']
        );

        $this->assertStringContainsString('ΜΕΛΟΣ-9', $out);
        $this->assertStringNotContainsString('Βαρδάκη', $out);
        $this->assertStringNotContainsString('[όνομα]', $out);
    }

    public function testTheGenitiveOfANameStillResolvesToTheSamePerson(): void
    {
        // Greek inflects at the ending, and a report or a radio message uses
        // the genitive far more than the nominative. Matching the stored form
        // only would leave "Γιώργου" untouched while looking for "Γιώργος".
        $map = ['ΜΕΛΟΣ-2' => 'Γιώργος Παπαδάκης'];
        $names = ['Γιώργος', 'Παπαδάκης'];

        foreach (['Γιώργος', 'Γιώργου', 'Γιώργο'] as $form) {
            $out = aiLivePseudonymiseText("Ο {$form} έφτασε.", $map, $names);
            $this->assertStringContainsString('ΜΕΛΟΣ-2', $out, "failed on the form «{$form}»");
        }
    }

    public function testSomebodyWeDoNotHaveBecomesAnonymousRatherThanLeaking(): void
    {
        // A name we hold but did not put in this digest. It must not travel,
        // and it must not silently become somebody else's pseudonym.
        $out = aiLivePseudonymiseText(
            'Μίλησα με τον Κωνσταντίνο Σταυράκη.',
            ['ΜΕΛΟΣ-1' => 'Νίκος Βαρδάκης'],
            ['Κωνσταντίνος', 'Σταυράκης', 'Νίκος', 'Βαρδάκης']
        );

        $this->assertStringContainsString('[όνομα]', $out);
        $this->assertStringNotContainsString('Σταυράκη', $out);
        $this->assertStringNotContainsString('ΜΕΛΟΣ-1', $out, 'an unknown person must not inherit a pseudonym');
    }

    public function testPhonesIdsAndCoordinatesInTypedTextNeverSurvive(): void
    {
        $out = aiLivePseudonymiseText(
            'Τηλ 6941234567, στίγμα 35.33871 25.14425, ΑΜΚΑ 12345678901',
            [],
            []
        );

        $this->assertStringNotContainsString('6941234567', $out);
        $this->assertStringNotContainsString('35.33871', $out);
        $this->assertStringNotContainsString('12345678901', $out);
    }

    public function testEmptyTextStaysEmpty(): void
    {
        $this->assertSame('', aiLivePseudonymiseText(null, [], []));
        $this->assertSame('', aiLivePseudonymiseText('   ', [], []));
    }

    // ── The leak gate's word boundary ──────────────────────────────────────

    public function testAnOrdinaryWordContainingANameStemDoesNotBlockTheRequest(): void
    {
        // "Νίκος" stems to "νικο", which sits inside "γενικό", "τεχνικό" and
        // "μηχανικό". Without a word boundary the gate fires on the ordinary
        // Greek for "general" — so an organisation with a volunteer called
        // Νίκος would have had every report and every question blocked by a
        // name that was never there. This is the failure that a hard gate
        // turns from a nuisance into a total outage.
        $clean = ['δωματιο' => 'ΓΕΝΙΚΟ', 'σημειωση' => 'Τεχνικό πρόβλημα στον ασύρματο.'];
        $this->assertSame([], aiScanDigestForLeaks($clean, ['Νίκος']));

        // The real thing is still caught, in the nominative and the genitive.
        $this->assertNotEmpty(aiScanDigestForLeaks(['x' => 'Ο Νίκος έφτασε.'], ['Νίκος']));
        $this->assertNotEmpty(aiScanDigestForLeaks(['x' => 'στο σημείο του Νίκου'], ['Νίκος']));
        // And glued to punctuation, which is not a word boundary problem.
        $this->assertNotEmpty(aiScanDigestForLeaks(['x' => '(Νίκος)'], ['Νίκος']));
    }

    // ── What a citation is allowed to be ───────────────────────────────────

    public function testACitationThatDoesNotResolveIsDroppedAndCounted(): void
    {
        // Every ref shown to a coordinator must point at a record they can
        // open. An invented one is worse than no citation at all, because it
        // looks checkable and is not.
        $out = aiLiveValidate(
            ['answer' => 'Η ΑΛΦΑ είναι πιο κοντά.', 'evidence' => ['TEAM-1', 'TEAM-99', 'ΦΑΝΤΑΣΜΑ']],
            ['TEAM-1' => 'Ομάδα ΑΛΦΑ', 'ORD-3' => 'Εντολή 10:00']
        );

        $this->assertSame(['TEAM-1'], $out['evidence']);
        $this->assertSame(2, $out['dropped']);
        $this->assertSame('Η ΑΛΦΑ είναι πιο κοντά.', $out['answer']);
    }

    public function testAnUnevidencedAnswerSurvivesSoItCanBeShownAsUnevidenced(): void
    {
        // Deliberately unlike the report observer, which deletes unevidenced
        // claims. Deleting a chat reply leaves the coordinator staring at
        // nothing, which reads as a broken feature rather than a refusal — and
        // "how long has the mission been running?" is answered from the clock,
        // not from a citable record. The teeth are in the UI saying so.
        $out = aiLiveValidate(['answer' => 'Τρέχει 3 ώρες.', 'evidence' => []], ['TEAM-1' => 'x']);

        $this->assertSame('Τρέχει 3 ώρες.', $out['answer']);
        $this->assertSame([], $out['evidence']);
    }

    public function testJunkFromTheProviderDegradesToAnEmptyAnswerRatherThanAFatal(): void
    {
        foreach ([null, 'a string', 42, []] as $junk) {
            $out = aiLiveValidate($junk, ['TEAM-1' => 'x']);
            $this->assertSame('', $out['answer']);
            $this->assertSame([], $out['evidence']);
        }
    }

    public function testAnAdmittedGapIsCarriedThroughInsteadOfBeingSmoothedOver(): void
    {
        $out = aiLiveValidate(
            ['answer' => 'Δεν ξέρω πού είναι η ΤΣΑΡΛΙ.', 'answerable' => false,
             'missing' => 'στίγμα από την ομάδα ΤΣΑΡΛΙ', 'evidence' => []],
            ['TEAM-1' => 'x']
        );

        $this->assertFalse($out['answerable']);
        $this->assertSame('στίγμα από την ομάδα ΤΣΑΡΛΙ', $out['missing']);
    }

    public function testAnswerableDefaultsToTrueWhenTheProviderOmitsIt(): void
    {
        $out = aiLiveValidate(['answer' => 'Ναι.'], []);
        $this->assertTrue($out['answerable']);
    }

    // ── Throttle ───────────────────────────────────────────────────────────

    public function testTheThrottleAllowsABurstThenHoldsTheLine(): void
    {
        // A stuck client loop must not be able to spend an organisation's
        // whole free tier in an afternoon.
        $_SESSION = [];
        for ($i = 0; $i < AI_LIVE_RATE_MAX; $i++) {
            $this->assertNull(aiLiveRateLimit(1), "question " . ($i + 1) . " should have been allowed");
        }
        $wait = aiLiveRateLimit(1);
        $this->assertIsInt($wait);
        $this->assertGreaterThan(0, $wait);
        $this->assertLessThanOrEqual(AI_LIVE_RATE_WINDOW, $wait);
    }

    public function testTheThrottleIsPerMissionSoOneOperationCannotStarveAnother(): void
    {
        $_SESSION = [];
        for ($i = 0; $i < AI_LIVE_RATE_MAX; $i++) {
            aiLiveRateLimit(1);
        }
        $this->assertNotNull(aiLiveRateLimit(1));
        $this->assertNull(aiLiveRateLimit(2), 'a different mission has its own budget');
    }

    public function testStaleCallsFallOutOfTheWindow(): void
    {
        $_SESSION = ['ai_live_calls_1' => array_fill(0, AI_LIVE_RATE_MAX, time() - AI_LIVE_RATE_WINDOW - 60)];
        $this->assertNull(aiLiveRateLimit(1), 'calls older than the window must not count');
    }
}
