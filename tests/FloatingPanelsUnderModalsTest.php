<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The «Εντολές ομάδων» board and the acknowledgement panel float at z-index 1150, which is above Bootstrap's
 * modal (1055) and its backdrop (1050). Until v3.452.1 that put them on top of every window that opens over
 * the page: the board sat across the map of the point/area window, hiding the very place a coordinator was
 * about to click. While a modal is open they now go one step under the backdrop and come back when it closes.
 *
 * The alarms are NOT in this list on purpose (the ticker 1900, the SOS and return-to-base overlays 2000, the
 * order popup 1905): an alarm stays on top of everything.
 */
final class FloatingPanelsUnderModalsTest extends TestCase
{
    private function css(): string
    {
        return str_replace("\r\n", "\n", WarRoomSource::read('war-room.php'));
    }

    /** The z-index of the first rule in the page's CSS whose selector list is exactly $selector. */
    private function zIndexOf(string $selector): ?int
    {
        if (!preg_match('/(?:^|\n)\s*' . preg_quote($selector, '/') . '\s*\{[^}]*?z-index:\s*(\d+)/', $this->css(), $m)) {
            return null;
        }
        return (int) $m[1];
    }

    public function testThePanelsStillFloatAboveThePageAndAboveAModalWhenNoneIsOpen(): void
    {
        // The reason the rule below is needed at all: with no modal open they keep their place above the map.
        $this->assertSame(1150, $this->zIndexOf('.team-board'));
        $this->assertSame(1150, $this->zIndexOf('.ack-tracker'));
        $this->assertSame(1151, $this->zIndexOf('.ack-tracker-float'));
    }

    public function testWhileAModalIsOpenTheyGoUnderItsBackdropButAboveTheNavbar(): void
    {
        $css = $this->css();
        $this->assertSame(1, preg_match(
            '/body\.modal-open \.ack-tracker,\s*body\.modal-open \.ack-tracker-float,\s*body\.modal-open \.team-board\s*\{\s*z-index:\s*(\d+);\s*\}/',
            $css,
            $m
        ), 'all three floating panels are lowered by one rule');
        $z = (int) $m[1];
        $this->assertLessThan(1050, $z, 'under Bootstrap\'s modal backdrop (--bs-backdrop-zindex 1050), so a modal and its backdrop cover them');
        $this->assertGreaterThan(1030, $z, 'but still above the sticky navbar (1020) and fixed elements (1030), as they always were');
    }

    public function testTheRuleOutranksTheBaseRulesBySpecificityNotByOrderAlone(): void
    {
        // `body.modal-open .team-board` (0,2,1) beats `.team-board` (0,1,0) wherever the two rules sit.
        $css = $this->css();
        $this->assertGreaterThan(strpos($css, '.team-board {'), strpos($css, 'body.modal-open .team-board'));
        $this->assertGreaterThan(strpos($css, '.ack-tracker {'), strpos($css, 'body.modal-open .ack-tracker'));
    }

    public function testNoAlarmWasLoweredWithThem(): void
    {
        $css = $this->css();
        $rule = substr($css, (int) strpos($css, 'body.modal-open .ack-tracker,'), 400);
        foreach (['war-room-banner', 'sosOverlay', 'returnToBaseOverlay', 'restrictedAreaOverlay', 'wr-op-root', 'wr-arrow'] as $alarm) {
            $this->assertStringNotContainsString($alarm, strstr($rule, '}', true), "$alarm stays on top of a modal");
        }
        $this->assertSame(1900, $this->zIndexOf('.war-room-banner'));
        $this->assertSame(1905, $this->zIndexOf('.wr-op-root'));
    }
}
