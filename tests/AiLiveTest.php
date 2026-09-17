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

    // ── A position field is never empty ────────────────────────────────────

    public function testAPositionFieldNeverComesBackAsNull(): void
    {
        // The digest reaches the model as pretty-printed JSON, so a
        // `"θεση": null` was read back verbatim and reported to a coordinator
        // as the literal word "null" — while the person's pin sat on the map.
        // Every one of the three causes now has its own sentence.
        $noFix = aiLivePositionText(null, null, 35.31, 25.10, AI_LIVE_POS_NO_FIX);
        $this->assertSame(AI_LIVE_POS_NO_FIX, $noFix);

        $noBase = aiLivePositionText(35.3387, 25.1442, null, null);
        $this->assertSame(AI_LIVE_POS_NO_BASE, $noBase);

        $noPlace = aiLivePositionText(null, null, 35.31, 25.10);
        $this->assertSame(AI_LIVE_POS_NONE, $noPlace);

        // And none of them is the string "null" in any casing, which is the
        // thing the coordinator must never be shown.
        foreach ([$noFix, $noBase, $noPlace] as $text) {
            $this->assertNotSame('', $text);
            $this->assertStringNotContainsStringIgnoringCase('null', $text);
        }
    }

    public function testAKnownPositionStillReadsAsDistanceFromTheBase(): void
    {
        $out = aiLivePositionText(35.3387, 25.1442, 35.3100, 25.1000);
        $this->assertStringContainsString('από τη βάση', $out);
        $this->assertDoesNotMatchRegularExpression('/\d+\.\d{3,}/', $out);
    }

    // ── Distance from the point the coordinator is looking at ──────────────

    public function testDistanceIsMeasuredFromAnArbitraryPointNotOnlyTheBase(): void
    {
        // Two polar positions read from one origin are not something a model
        // can combine: it has an eight-point bearing, not a vector. So the
        // server measures from the focus point and hands over the answer.
        // ~11 km due north of the reference point, read FROM that point.
        $out = aiLiveRelativeTo(35.4387, 25.1442, 35.3387, 25.1442);

        $this->assertIsString($out);
        $this->assertStringContainsString('χλμ', $out);
        $this->assertStringContainsString('Β', $out);
        // Bare: this one is not measured from the base and must not claim to be.
        $this->assertStringNotContainsString('βάση', $out);
        $this->assertDoesNotMatchRegularExpression('/\d+\.\d{3,}/', $out);
    }

    public function testTheDirectionIsFromTheReferencePointToTheRecord(): void
    {
        // Read as "the record is X, in this direction, FROM the focus point".
        // Getting this backwards would send a team the wrong way, so it is
        // pinned in both directions rather than assumed.
        $southOfRef = aiLiveRelativeTo(35.2500, 24.8100, 35.2600, 24.8100);
        $northOfRef = aiLiveRelativeTo(35.2600, 24.8100, 35.2500, 24.8100);

        $this->assertStringContainsString('Ν', $southOfRef);
        $this->assertStringContainsString('Β', $northOfRef);
    }

    public function testNoFocusPointMeansNoDistanceRatherThanAWrongOne(): void
    {
        // The tabbed volunteer layout may have no map, so no point is sent.
        // The caller omits the field on null; it must never fall back to the
        // base and silently answer a different question.
        $this->assertNull(aiLiveRelativeTo(35.3387, 25.1442, null, null));
        $this->assertNull(aiLiveRelativeTo(null, null, 35.3387, 25.1442));
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

    // ── The shift handover ─────────────────────────────────────────────────

    public function testAHandoverLineWithNoEvidenceIsDeleted(): void
    {
        // Deliberately the OPPOSITE of a chat answer, which is kept and marked.
        // A chat reply is read in context with the question still on screen; a
        // handover line is read hours later by somebody who was not here, as a
        // statement of fact about an operation they are now responsible for.
        // There is nothing for them to weigh it against.
        $out = aiHandoverValidate([
            'situation' => 'Τρεις ομάδες στο πεδίο.',
            'open' => [
                ['text' => 'Τεκμηριωμένο.', 'evidence' => ['SHORT-42']],
                ['text' => 'Ακούγεται σωστό αλλά δεν δείχνει πουθενά.', 'evidence' => []],
                ['text' => 'Παραπέμπει σε ανύπαρκτη εγγραφή.', 'evidence' => ['TEAM-9999']],
            ],
        ], ['SHORT-42' => 'Έλλειψη']);

        $this->assertCount(1, $out['open']);
        $this->assertSame('Τεκμηριωμένο.', $out['open'][0]['text']);
        $this->assertSame(2, $out['dropped']);
        $this->assertSame('Τρεις ομάδες στο πεδίο.', $out['situation']);
    }

    public function testAHandoverKeepsItsThreeSectionsSeparate(): void
    {
        // The fixed skeleton is the point of a handover — the person receiving
        // it reads the same order every time, tired, at 4am.
        $out = aiHandoverValidate([
            'situation' => 'Κατάσταση.',
            'open'    => [['text' => 'Α', 'evidence' => ['R1']]],
            'ongoing' => [['text' => 'Β', 'evidence' => ['R1']]],
            'watch'   => [['text' => 'Γ', 'evidence' => ['R1']]],
        ], ['R1' => 'x']);

        $this->assertSame('Α', $out['open'][0]['text']);
        $this->assertSame('Β', $out['ongoing'][0]['text']);
        $this->assertSame('Γ', $out['watch'][0]['text']);
    }

    public function testAnEmptySectionIsAValidHandoverAnswer(): void
    {
        // "Nothing open" is useful information for the next coordinator, and
        // the prompt says so — the validator must not treat it as a failure.
        $out = aiHandoverValidate(['situation' => 'Ήσυχη βάρδια.', 'open' => [], 'ongoing' => [], 'watch' => []], []);

        $this->assertSame('Ήσυχη βάρδια.', $out['situation']);
        $this->assertSame([], $out['open']);
        $this->assertSame(0, $out['dropped']);
    }

    public function testAHandoverIsCappedSoItStaysReadableStandingUp(): void
    {
        $lines = [];
        for ($i = 0; $i < 20; $i++) {
            $lines[] = ['text' => 'Γραμμή ' . $i, 'evidence' => ['R1']];
        }
        $out = aiHandoverValidate(['open' => $lines], ['R1' => 'x']);

        $this->assertCount(8, $out['open']);
    }

    public function testJunkFromTheProviderDoesNotFatalTheHandover(): void
    {
        foreach ([null, 'text', 7, ['open' => 'not a list']] as $junk) {
            $out = aiHandoverValidate($junk, ['R1' => 'x']);
            $this->assertSame('', $out['situation']);
            $this->assertSame([], $out['open']);
        }
    }

    // ── Order drafting: what reaches a field with a send button under it ───

    public function testADraftIsCappedNoMatterWhatTheModelReturns(): void
    {
        // The prompt asks for brevity; this enforces it. A model that ignored
        // the instruction would otherwise drop a page of prose into a textarea
        // whose send button is directly underneath it.
        $out = aiDraftValidate(['text' => str_repeat('Πολύ μακρύ κείμενο. ', 200)]);

        $this->assertLessThanOrEqual(AI_DRAFT_MAX_CHARS, mb_strlen($out['text'], 'UTF-8'));
        $this->assertNotSame('', $out['text']);
    }

    public function testADraftIsFlattenedAndUnquotedBeforeItReachesTheField(): void
    {
        // Models wrap the whole thing in quotes despite being told not to, and
        // those quotes would be sent to the field verbatim. Newlines read
        // badly in a three-row textarea and worse when spoken aloud.
        $out = aiDraftValidate(['text' => "\"ΑΛΦΑ 1: κινηθείτε βόρεια\nκαι αναφέρετε άφιξη.\""]);

        $this->assertStringNotContainsString("\n", $out['text']);
        $this->assertStringStartsWith('ΑΛΦΑ', $out['text']);
        $this->assertStringEndsNotWith('"', $out['text']);
    }

    public function testTheNoteToTheCoordinatorIsSeparateFromTheMessage(): void
    {
        // "There is no team by that name" is advice to the sender. It must
        // never end up inside the text that goes to the field.
        $out = aiDraftValidate([
            'text' => 'ΑΛΦΑ 1: κινηθείτε βόρεια.',
            'note' => 'Δεν υπάρχει ομάδα ΔΕΛΤΑ σε αυτή την αποστολή.',
        ]);

        $this->assertSame('ΑΛΦΑ 1: κινηθείτε βόρεια.', $out['text']);
        $this->assertStringNotContainsString('ΔΕΛΤΑ', $out['text']);
        $this->assertStringContainsString('ΔΕΛΤΑ', $out['note']);
    }

    public function testJunkFromTheProviderProducesNoDraftRatherThanABadOne(): void
    {
        // An empty text is refused by the caller; a half-parsed one would be
        // sent to people in the field.
        foreach ([null, 'plain string', 42, ['text' => 123], ['text' => '   ']] as $junk) {
            $out = aiDraftValidate($junk);
            $this->assertSame('', $out['text']);
            $this->assertNull($out['note']);
        }
    }

    public function testEveryDraftKindHasItsOwnWordingAndTheSameLimits(): void
    {
        // A spoken announcement and a written order are read by different
        // machinery — one by eyes in sunlight, one aloud by a phone — so the
        // prompts differ. What must NOT differ is the cap the validator
        // enforces, which is why the prompt interpolates the same constant.
        $prompts = [];
        foreach (AI_DRAFT_KINDS as $kind) {
            $p = aiDraftSystemPrompt($kind);
            $this->assertStringContainsString((string) AI_DRAFT_MAX_CHARS, $p, "cap missing from {$kind}");
            // The line that does the actual work of this feature.
            $this->assertStringContainsString('ΜΗΝ ΓΡΑΨΕΙΣ ΚΑΤΙ ΛΑΘΟΣ', $p);
            $this->assertStringContainsString('Δεν προσθέτεις παραλήπτες', $p);
            $prompts[$kind] = $p;
        }
        $this->assertStringContainsString('ΦΩΝΗΤΙΚΗ', $prompts['speak']);
        $this->assertNotSame($prompts['order'], $prompts['speak']);
        $this->assertNotSame($prompts['order'], $prompts['broadcast']);
    }

    // ── The ref vocabulary shared with the panel ───────────────────────────

    public function testThePanelAndTheDigestNameTheSameRecordTheSameWay(): void
    {
        // «Εξήγησέ μου» lifts a ref off a row of the deterministic panel and
        // hands it to the model, which only knows the ids the digest built. If
        // the two ever disagreed the symptom would be an assistant insisting
        // it cannot find a record the coordinator is looking straight at — so
        // both go through this one function, and these are its ids.
        $this->assertSame('INC-17',   assistantRecordRef('incident', 17));
        $this->assertSame('SHORT-42', assistantRecordRef('shortage', 42));
        $this->assertSame('ORD-100',  assistantRecordRef('order', 100));
        $this->assertSame('SOS-3',    assistantRecordRef('sos', 3));
        $this->assertSame('POI-7',    assistantRecordRef('poi', 7));
        $this->assertSame('TEAM-39',  assistantRecordRef('team', 39));
        $this->assertSame('SECT-5',   assistantRecordRef('sector', 5));
        $this->assertSame('ZONE-2',   assistantRecordRef('zone', 2));
    }

    public function testAnUnknownKindStillProducesAUsableRef(): void
    {
        // A new record type must not produce an empty prefix that collides
        // with every other one.
        $this->assertSame('WIDGET-1', assistantRecordRef('widget', 1));
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
