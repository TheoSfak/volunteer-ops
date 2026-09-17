<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * The page-translation machinery (includes/ai-translate.php) — the parts that
 * decide what leaves the server and what must survive untouched.
 *
 * Two bugs these tests exist to keep dead:
 *
 *   1. Setting a DOMText node's value by clearing it and appending a child
 *      silently produced an EMPTY element. A text node cannot have children,
 *      so every translated heading and table cell came out blank while the
 *      page still looked structurally fine.
 *   2. A team call-sign translated into the target language ("ΑΕΤΟΣ" →
 *      "EAGLE") does not make a report foreign, it makes it wrong — nobody in
 *      the field would recognise the team being discussed.
 *
 * No database and no provider: the cache lookup is skipped when nothing
 * matches, so the document walk can be exercised on its own.
 */
final class AiTranslateTest extends TestCase
{
    // ─── what gets sent ──────────────────────────────────────────────────

    public function testNumbersDatesAndSymbolsAreNeverSent(): void
    {
        foreach (['91.6', '12.5', '25/07/2026', '08:30', '—', '·', '100%', '  ', ''] as $noise) {
            $this->assertTrue(
                aiTranslationSkip($noise, []),
                "Sending {$noise} to a translator is waste with a chance of corruption"
            );
        }
    }

    public function testRealTextIsSent(): void
    {
        foreach (['Βασικά Μεγέθη', 'Αξιολόγηση Παρατηρητή', '8 hours'] as $text) {
            $this->assertFalse(aiTranslationSkip($text, []));
        }
    }

    public function testProtectedTermsAreNeverSent(): void
    {
        $protected = array_map('aiFoldGreek', ['ΑΕΤΟΣ', 'Επίδραση']);
        $this->assertTrue(aiTranslationSkip('ΑΕΤΟΣ', $protected));
        $this->assertTrue(aiTranslationSkip('Αετός', $protected), 'Accents and case must not defeat protection');
        $this->assertTrue(aiTranslationSkip('Επίδραση', $protected));
    }

    /**
     * The language picker's own options are endonyms. A menu that renamed
     * "Deutsch" into the chosen language would stop being a way to pick one.
     */
    public function testLanguageNamesAreProtectedByDefault(): void
    {
        $terms = aiTranslationProtectedTerms(null);
        foreach (['English', 'Deutsch', 'Français'] as $endonym) {
            $this->assertContains($endonym, $terms);
        }
    }

    /**
     * The first list was assembled by eye and silently omitted Maltese and
     * Irish — both official EU languages. A Maltese crew at a joint exercise
     * would have opened a menu advertised as European and not found their own
     * language in it. Pinned against the official 24 so the next addition is
     * checked against the list rather than against intuition.
     */
    public function testEveryOfficialEuLanguageIsOffered(): void
    {
        $official = [
            'bg' => 'Bulgarian', 'hr' => 'Croatian', 'cs' => 'Czech',    'da' => 'Danish',
            'nl' => 'Dutch',     'en' => 'English',  'et' => 'Estonian', 'fi' => 'Finnish',
            'fr' => 'French',    'de' => 'German',   'el' => 'Greek',    'hu' => 'Hungarian',
            'ga' => 'Irish',     'it' => 'Italian',  'lv' => 'Latvian',  'lt' => 'Lithuanian',
            'mt' => 'Maltese',   'pl' => 'Polish',   'pt' => 'Portuguese', 'ro' => 'Romanian',
            'sk' => 'Slovak',    'sl' => 'Slovenian','es' => 'Spanish',  'sv' => 'Swedish',
        ];
        $offered = aiTranslationLanguages();

        foreach ($official as $code => $name) {
            $this->assertArrayHasKey($code, $offered, "{$name} is an official EU language and must be offered");
        }
    }

    public function testEveryLanguageIsNamedInItsOwnTongue(): void
    {
        // Endonyms: a reader finds their own language faster than a Greek
        // transliteration of it.
        $offered = aiTranslationLanguages();
        $this->assertSame('Malti', $offered['mt']);
        $this->assertSame('Gaeilge', $offered['ga']);
        $this->assertSame('Deutsch', $offered['de']);
        $this->assertSame('Ελληνικά', $offered['el']);
    }

    public function testOnlyEuropeanTargetsAreAcceptedAndGreekIsNotOne(): void
    {
        $this->assertTrue(aiIsTranslatableLanguage('en'));
        $this->assertTrue(aiIsTranslatableLanguage('de'));
        $this->assertFalse(aiIsTranslatableLanguage('el'), 'Greek is the source, not a translation target');
        $this->assertFalse(aiIsTranslatableLanguage('xx'));
        $this->assertFalse(aiIsTranslatableLanguage(''));
    }

    // ─── pseudonymisation round trip ─────────────────────────────────────

    public function testANameIsReplacedBeforeSendingAndRestoredAfterwards(): void
    {
        $map = [];
        $sent = aiPseudonymiseText('Η εντολή προς ΘΕΟΔΩΡΟ έμεινε αναπάντητη.', ['ΘΕΟΔΩΡΟΣ'], $map);

        $this->assertStringNotContainsString('ΘΕΟΔΩΡΟ', $sent, 'The real name must not leave the server');
        $this->assertStringContainsString('ΜΕΛΟΣ-1', $sent);

        $restored = str_replace(array_keys($map), array_values($map), $sent);
        $this->assertSame('Η εντολή προς ΘΕΟΔΩΡΟ έμεινε αναπάντητη.', $restored);
    }

    public function testTheSamePersonKeepsOneTokenAcrossSeveralStrings(): void
    {
        $map = [];
        $a = aiPseudonymiseText('Ο ΘΕΟΔΩΡΟΣ ανέφερε βλάβη.', ['ΘΕΟΔΩΡΟΣ'], $map);
        $b = aiPseudonymiseText('Ο ΘΕΟΔΩΡΟΣ ολοκλήρωσε την εντολή.', ['ΘΕΟΔΩΡΟΣ'], $map);

        $this->assertStringContainsString('ΜΕΛΟΣ-1', $a);
        $this->assertStringContainsString('ΜΕΛΟΣ-1', $b);
        $this->assertCount(1, $map, 'One person must not become two tokens across a document');
    }

    public function testTextWithNoNamesIsUnchanged(): void
    {
        $map = [];
        $this->assertSame('Βασικά Μεγέθη', aiPseudonymiseText('Βασικά Μεγέθη', ['ΘΕΟΔΩΡΟΣ'], $map));
        $this->assertSame([], $map);
    }

    // ─── output budget ───────────────────────────────────────────────────

    /**
     * A real run truncated mid-JSON at item 53 of 60. The answer itself was
     * only about 1.400 tokens; the model had spent 10.956 of a 12.000 budget
     * on reasoning before writing a character, for a task with nothing to
     * reason about.
     *
     * Reasoning is switched off for translation, which is the actual fix. This
     * guards the margin behind it: raising the chunk size without raising the
     * ceiling would quietly bring the truncation back on any provider that
     * ignores the setting, and it would look like the translator is broken
     * rather than mis-budgeted.
     */
    public function testAChunkStillFitsEvenIfTheProviderIgnoresReasoningEffort(): void
    {
        $tokensPerItem   = 1400 / 60;   // measured on the run that truncated
        $observedThinking = 10956;      // measured on the same run
        $budget           = 16000;      // aiTranslateCached()'s max_tokens

        $needed = (int) ceil(AI_TRANSLATE_CHUNK * $tokensPerItem);

        $this->assertLessThan(
            $budget - $observedThinking,
            $needed,
            'A chunk must fit the output budget even when the model spends the observed amount on reasoning'
        );
    }

    // ─── reading the provider's reply ────────────────────────────────────

    /**
     * A page that came back untranslated after forty seconds of waiting, with
     * no explanation anywhere the operator could see. The request was built
     * with keys "0", "1", … — which PHP turns back into integers, so
     * json_encode emitted a JSON ARRAY while the prompt asked for an object
     * with the same keys. The model was handed one shape and asked for
     * another, and whatever it chose to return, nothing read it.
     *
     * The keys are now "t0", "t1", …, and the reader accepts every shape a
     * provider actually uses rather than only the one that was requested.
     */
    public function testTheShapeTheRequestAsksForIsRead(): void
    {
        $this->assertSame(
            ['t0' => 'Key Figures', 't1' => 'Observer'],
            aiTranslateNormaliseReply(['t0' => 'Key Figures', 't1' => 'Observer'], 2)
        );
    }

    public function testABareArrayInOriginalOrderIsRead(): void
    {
        $this->assertSame(
            ['0' => 'Key Figures', '1' => 'Observer'],
            aiTranslateNormaliseReply(['Key Figures', 'Observer'], 2)
        );
    }

    public function testAPayloadWrappedInASingleContainerKeyIsRead(): void
    {
        $this->assertSame(
            ['t0' => 'Key Figures'],
            aiTranslateNormaliseReply(['translations' => ['t0' => 'Key Figures']], 1)
        );
    }

    public function testValuesThatAreThemselvesObjectsAreUnwrapped(): void
    {
        $this->assertSame(
            ['t0' => 'Key Figures'],
            aiTranslateNormaliseReply(['t0' => ['text' => 'Key Figures']], 1)
        );
    }

    /**
     * Returning null is the point: an unreadable reply must be REPORTED, not
     * absorbed. Silently returning an empty map is what produced a Greek page
     * and no reason for it.
     */
    public function testAnUnreadableReplyIsRejectedRatherThanAbsorbed(): void
    {
        $this->assertNull(aiTranslateNormaliseReply(['ok' => true], 2));
        $this->assertNull(aiTranslateNormaliseReply([], 2));
    }

    /**
     * PHP's own trap, and the reason the keys carry a letter: an array keyed
     * "0","1" is a list, and json_encode writes it as an array.
     */
    public function testNumericStringKeysWouldHaveSerialisedAsAnArray(): void
    {
        $numeric = [];
        foreach (['a', 'b'] as $i => $s) { $numeric[(string) $i] = $s; }
        $this->assertSame('["a","b"]', json_encode($numeric));

        $prefixed = [];
        foreach (['a', 'b'] as $i => $s) { $prefixed['t' . $i] = $s; }
        $this->assertSame('{"t0":"a","t1":"b"}', json_encode($prefixed));
    }

    // ─── the document walk ───────────────────────────────────────────────

    public function testGreekIsNeverTouchedAndTheDocumentComesBackWhole(): void
    {
        $html = '<div><h2>Βασικά Μεγέθη</h2><script>var x = "Βασικά";</script></div>';
        $out  = aiTranslateHtmlDocument($html, 'el', null, true);

        $this->assertSame($html, $out['html'], 'A Greek page must be returned byte-identical, with no work done');
        $this->assertSame(0, $out['total']);
    }

    public function testAnUnknownLanguageLeavesThePageAlone(): void
    {
        $html = '<p>Δοκιμή</p>';
        $this->assertSame($html, aiTranslateHtmlDocument($html, 'xx', null, true)['html']);
    }

    /**
     * With nothing in the cache and no provider configured nothing can be
     * translated — and the page must still come back intact and readable in
     * Greek rather than blank. This is the regression guard for the empty-node
     * bug: a broken writer showed up as missing text, not as an error.
     */
    public function testAFailedTranslationStillReturnsAReadablePage(): void
    {
        $html = '<div><h2>Βασικά Μεγέθη</h2><p>Αξιολόγηση Παρατηρητή</p><span>91.6</span></div>';
        $out  = aiTranslateHtmlDocument($html, 'en', null, true);

        $this->assertStringContainsString('Βασικά Μεγέθη', $out['html']);
        $this->assertStringContainsString('Αξιολόγηση Παρατηρητή', $out['html']);
        $this->assertStringContainsString('91.6', $out['html']);
        $this->assertStringNotContainsString('<h2></h2>', $out['html'], 'Empty elements are the blanking bug returning');
    }

    public function testScriptAndStyleContentIsNeverCounted(): void
    {
        $html = '<div><script>var t = "Αξιολόγηση";</script><style>.x{content:"Αξιολόγηση"}</style><p>Αξιολόγηση</p></div>';
        $out  = aiTranslateHtmlDocument($html, 'en', null, true);

        $this->assertSame(1, $out['total'], 'Only the paragraph is text a reader sees');
    }

    public function testAFragmentIsNotWrappedInASecondDocument(): void
    {
        $out = aiTranslateHtmlDocument('<div><p>Δοκιμή κειμένου</p></div>', 'en', null, true)['html'];

        $this->assertStringNotContainsString('<html', $out);
        $this->assertStringNotContainsString('<body', $out);
        $this->assertStringContainsString('<div><p>', $out);
    }

    public function testGreekSurvivesTheDomRoundTripWithoutMojibake(): void
    {
        $out = aiTranslateHtmlDocument('<p>Επιχειρησιακή Αξιολόγηση Ομάδας</p>', 'en', null, true)['html'];

        $this->assertStringContainsString('Επιχειρησιακή Αξιολόγηση Ομάδας', $out);
        $this->assertStringNotContainsString('Î', $out, 'UTF-8 read as ISO-8859-1 is the classic DOMDocument trap');
    }

    public function testTitleAndAltAttributesAreCountedAsVisibleText(): void
    {
        $html = '<img alt="Χάρτης αποστολής"><span title="Βαθμολογία ομάδας">x</span>';
        $this->assertSame(2, aiTranslateHtmlDocument($html, 'en', null, true)['total']);
    }
}
