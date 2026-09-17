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
