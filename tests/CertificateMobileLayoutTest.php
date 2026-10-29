<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The certificate document (mission-certificate-print.php) on a phone.
 *
 * It was one fixed A4-landscape layout: 56px of side padding, point-sized type and
 * the QR pinned over the text, so on a 390px screen the title and the name ran off
 * the right edge and the page scrolled sideways. The phone rules are screen-only,
 * which is what keeps the printed page exactly as it was — the one-page A4
 * landscape sheet whose QR is deliberately out of the flow.
 */
final class CertificateMobileLayoutTest extends TestCase
{
    private static function page(): string
    {
        return (string) file_get_contents(__DIR__ . '/../mission-certificate-print.php');
    }

    /** The CSS inside the first `@media screen and (max-width: 768px) { … }` block. */
    private static function phoneBlock(): string
    {
        $page = self::page();
        $start = strpos($page, '@media screen and (max-width: 768px)');
        self::assertNotFalse($start, 'the certificate has no phone layout');
        $open = strpos($page, '{', $start);
        $depth = 0;
        for ($i = $open, $n = strlen($page); $i < $n; $i++) {
            if ($page[$i] === '{') {
                $depth++;
            } elseif ($page[$i] === '}' && --$depth === 0) {
                return substr($page, $open + 1, $i - $open - 1);
            }
        }
        self::fail('the phone layout block never closes');
    }

    public function testThePageDeclaresAMobileViewport(): void
    {
        $this->assertStringContainsString('name="viewport" content="width=device-width, initial-scale=1.0"', self::page());
    }

    public function testThePhoneLayoutReflowsTheThingsThatOverflowed(): void
    {
        $css = self::phoneBlock();
        // the side padding that left ~100px of text column
        $this->assertMatchesRegularExpression('~\.cert-border-inner\s*\{[^}]*padding:\s*22px 18px~', $css);
        // the QR pinned over the text moves into the flow
        $this->assertMatchesRegularExpression('~\.cert-qr\s*\{[^}]*position:\s*static~', $css);
        // two signatures side by side become a column
        $this->assertMatchesRegularExpression('~\.cert-signatures\s*\{[^}]*flex-direction:\s*column~', $css);
        // a long double-barrelled name wraps instead of running off the edge
        $this->assertMatchesRegularExpression('~\.cert-recipient-name\s*\{[^}]*overflow-wrap:\s*anywhere~', $css);
        // the fixed notice bar no longer covers the top of the certificate
        $this->assertMatchesRegularExpression('~\.screen-notice\s*\{[^}]*position:\s*static~', $css);
    }

    public function testNoPhoneRuleLeaksIntoThePrintedPage(): void
    {
        $page = self::page();
        // every phone rule sits in a screen-only query …
        $this->assertDoesNotMatchRegularExpression('~@media\s*\(max-width~', $page, 'a width query without "screen" would also apply when printing');
        // … and the print rules are still the ones that give one A4 landscape sheet
        $this->assertStringContainsString('@page { size: A4 landscape; margin: 8mm; }', $page);
        $this->assertMatchesRegularExpression('~@media print\s*\{.*?\.cert-page\s*\{[^}]*box-shadow:\s*none~s', $page);
    }

    public function testThePrintDialogOnlyOpensByItselfOnAWideScreen(): void
    {
        $this->assertMatchesRegularExpression(
            "~matchMedia\\('\\(min-width: 769px\\)'\\)\\.matches\\)\\s*\\{\\s*setTimeout\\(function \\(\\) \\{ window\\.print\\(\\); \\}, 400\\);~",
            self::page(),
            'on a phone the dialog would cover the certificate the moment it opens'
        );
    }
}
