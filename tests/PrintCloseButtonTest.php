<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The Close button of the standalone print documents.
 *
 * It called window.close(), which a browser honours only for a window a script opened:
 * a tab opened from a plain link ignores it, and inside the Android app the document
 * opens in the same WebView, so on a phone the button did nothing. Every document now
 * calls vopsClosePage(), which falls back to going back (or to the listing page).
 */
final class PrintCloseButtonTest extends TestCase
{
    private const PAGES = [
        'mission-certificate-print.php',
        'mission-report-print.php',
        'mission-team-debrief-print.php',
        'inventory-print.php',
        'inventory-shelf-print.php',
        'shift-participants-print.php',
    ];

    private static function read(string $file): string
    {
        return (string) file_get_contents(__DIR__ . '/../' . $file);
    }

    public function testNoPrintDocumentCallsWindowCloseDirectly(): void
    {
        foreach (self::PAGES as $page) {
            $src = self::read($page);
            self::assertStringNotContainsString('onclick="window.close()"', $src, "$page still has the dead Close button");
            self::assertStringContainsString('onclick="vopsClosePage()"', $src, "$page lost its Close button");
        }
    }

    public function testEveryPrintDocumentLoadsTheHelperItsButtonCalls(): void
    {
        foreach (self::PAGES as $page) {
            self::assertStringContainsString(
                "includes/print-close-script.php",
                self::read($page),
                "$page calls vopsClosePage() but never includes the script that defines it"
            );
        }
    }

    public function testCloseFallsBackToBackThenReferrerThenListing(): void
    {
        $src = self::read('includes/print-close-script.php');
        self::assertStringContainsString('function vopsClosePage()', $src);
        $close = strpos($src, 'window.close()');
        $back = strpos($src, 'history.back()');
        $referrer = strpos($src, 'document.referrer');
        $listing = strpos($src, 'location.href = back');
        self::assertNotFalse($close);
        self::assertNotFalse($back);
        self::assertNotFalse($referrer);
        self::assertNotFalse($listing);
        self::assertLessThan($back, $close, 'it must try to close the window first');
        self::assertLessThan($referrer, $back, 'going back comes before the referrer');
        self::assertLessThan($listing, $referrer, 'the listing page is the last resort');
    }

    public function testCertificateReturnsARecipientToTheirProfileAndAManagerToTheMission(): void
    {
        $src = self::read('mission-certificate-print.php');
        self::assertMatchesRegularExpression(
            "/\\\$printBackUrl\s*=\s*\\\$isRecipient\s*\?\s*'profile\.php'\s*:\s*'mission-certificates\.php\?mission_id='/",
            $src
        );
    }

    public function testCertificateButtonsAreBigEnoughForAThumbOnAPhone(): void
    {
        $src = self::read('mission-certificate-print.php');
        $start = strpos($src, '@media screen and (max-width: 768px)');
        self::assertNotFalse($start);
        self::assertMatchesRegularExpression(
            '/\.screen-notice button\s*\{[^}]*padding:\s*1[01]px[^}]*\}/',
            substr($src, $start, 1500)
        );
    }

    public function testTheAppReplacesWindowPrintWithTheNativePrintDialogInBothApps(): void
    {
        foreach ([
            'mobile-app/android/app/src/main/java/gr/volunteerops/epidrasis/MainActivity.java',
            'mobile-app-yphresies/android/app/src/main/java/gr/volunteerops/yphresies/MainActivity.java',
        ] as $java) {
            $src = self::read($java);
            self::assertStringContainsString('public void printPage()', $src, "$java has no native print");
            self::assertStringContainsString('createPrintDocumentAdapter', $src);
            self::assertStringContainsString('window.print=function()', $src, "$java never replaces window.print");
            // The auto-print-on-load of the print pages must not open a dialog unasked.
            self::assertStringContainsString('navigator.userActivation', $src);
            self::assertStringContainsString('evaluateJavascript(PRINT_PATCH', $src);
        }
    }
}
