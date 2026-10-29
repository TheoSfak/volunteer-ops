<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The report a team writes when it presses «Ολοκληρώθηκε» (v3.409.0).
 *
 * The server always accepted a note on completing; only an order made from an
 * imported map point ever asked for one, through a browser prompt(), so a team
 * that reached a casualty had nowhere to say what it found. The dialog lives in
 * inline script, so these read the source.
 */
final class CompletionReportDialogTest extends TestCase
{
    private static function source(): string
    {
        return (string) file_get_contents(__DIR__ . '/../war-room.php');
    }

    private static function postDispatchAction(): string
    {
        $src = self::source();
        $start = strpos($src, 'function postDispatchAction(');
        self::assertNotFalse($start, 'postDispatchAction() is gone');
        $end = strpos($src, 'function sendDispatchAction(', $start);
        self::assertNotFalse($end);
        return substr($src, $start, $end - $start);
    }

    public function testAnOrderSentToAnIncidentAsksForAReportBeforeCompleting(): void
    {
        $fn = self::postDispatchAction();
        $this->assertStringContainsString("action === 'complete'", $fn);
        $this->assertStringContainsString('sent.incident || sent.map_point_id', $fn, 'incident orders must ask as well as map-point ones');
        $this->assertStringContainsString('askCompletionReport(', $fn);
        $this->assertStringContainsString('note === null ? {ok: false}', $fn, 'Ακύρωση must leave the order open');
    }

    public function testEveryPlaceThatOffersTheButtonGoesThroughThatOneFunction(): void
    {
        $src = self::source();
        // The popup, the map-point steps, «Οι Εντολές μου» and the order popup.
        foreach (["postDispatchAction('complete', completeBtn.dataset.id", "postDispatchAction('complete', btn.dataset.id", "postDispatchAction('complete', m.id"] as $call) {
            $this->assertStringContainsString($call, $src, "a «Ολοκληρώθηκε» button no longer goes through postDispatchAction: $call");
        }
        $this->assertStringContainsString("postDispatchAction(b.dataset.mpStep, b.dataset.id, b)", $src);
        $this->assertStringNotContainsString("fetch('mission-dispatch.php'", self::postDispatchAction(), 'a completion must not skip the dialog by posting directly');
    }

    public function testTheBrowserPromptIsGone(): void
    {
        $this->assertStringNotContainsString("window.prompt(t('mp.complete_note_prompt'", self::source());
    }

    public function testTheDialogCannotBeLostToAStrayTouchOrAPollTick(): void
    {
        $src = self::source();
        $start = strpos($src, 'function askCompletionReport(');
        $this->assertNotFalse($start);
        $fn = substr($src, $start, strpos($src, 'function sendDispatchAction(', $start) - $start);
        $this->assertStringContainsString('document.body.appendChild(wrap)', $fn, 'it must live outside the fragments the poll rebuilds');
        $this->assertStringNotContainsString("wrap.addEventListener('click'", $fn, 'a tap beside the box must not close it and discard the text');
        $this->assertMatchesRegularExpression('~\.wr-report-dialog\s*\{[^}]*z-index:\s*(\d+)~', $src);
        preg_match('~\.wr-report-dialog\s*\{[^}]*z-index:\s*(\d+)~', $src, $z);
        preg_match('~\.wr-op-root\s*\{[^}]*z-index:\s*(\d+)~', $src, $popup);
        $this->assertGreaterThan((int) $popup[1], (int) $z[1], 'the dialog must sit above the order popup it can be opened from');
    }

    public function testCommandSeesTheReportOnTheDispatchPinAndTheIncidentCard(): void
    {
        $src = self::source();
        $this->assertStringContainsString('💬 «${escapeHtml(p.note)}»', $src);
        $this->assertStringContainsString('💬 «${escapeHtml(l.note)}»', $src);
    }

    public function testTheNewTextsExistInGreekAndEnglish(): void
    {
        $strings = require __DIR__ . '/../includes/lang/war-room.php';
        foreach (['el', 'en'] as $lang) {
            foreach (['dispatch.report_title', 'dispatch.report_placeholder', 'dispatch.report_incident_hint',
                      'dispatch.report_point_hint', 'dispatch.report_submit', 'common.cancel'] as $key) {
                $this->assertArrayHasKey($key, $strings[$lang], "'$lang' has no $key");
                $this->assertNotSame('', $strings[$lang][$key]);
            }
        }
    }
}
