<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The Action Room must never be wider than the phone it is shown on (v3.505.1).
 *
 * On a training mission the hero showed the whole sentence «ΑΣΚΗΣΗ · Εκπαιδευτική άσκηση - δεν
 * επηρεάζει τα στατιστικά» in a Bootstrap .badge, which is white-space:nowrap. At 360 px that one
 * line was 417 px wide and stretched the page to 457 px; a page wider than the screen makes the phone
 * lay out every position:fixed layer at the wider size, so the clock bar, the order popup and the AR
 * screen lost their right and bottom parts (the AR screen's bottom bar, with the «Ελήφθη» button,
 * ended up off screen). Measured in a browser at 360 px; these pin the three rules that fixed it.
 */
final class ActionRoomFitsPhoneTest extends TestCase
{
    private string $warRoom;

    protected function setUp(): void
    {
        $this->warRoom = (string) WarRoomSource::full();
    }

    private function rule(string $selector): string
    {
        $this->assertSame(1, preg_match('~' . preg_quote($selector, '~') . ' \{([^}]*)\}~', $this->warRoom, $m), $selector . ' has a rule');
        return $m[1];
    }

    public function testTheTrainingNoticeWrapsInsteadOfStretchingThePage(): void
    {
        $css = $this->rule('.wr-training-badge');
        $this->assertStringContainsString('white-space: normal', $css);
        $this->assertStringContainsString('max-width: 100%', $css);
        $this->assertStringContainsString('wr-training-badge', $this->warRoom);
    }

    public function testTheArScreenStartsBelowTheClockBarLikeTheOtherFixedLayers(): void
    {
        foreach (['.wr-ar', '.wr-arrow', '.wr-op-root'] as $selector) {
            $this->assertStringContainsString('top: var(--app-clock-h, 0px)', $this->rule($selector), $selector);
        }
    }

    public function testTheHeroStatusGroupWrapsInsteadOfRunningPastTheCard(): void
    {
        $this->assertStringContainsString('<div class="d-flex gap-2 align-items-center flex-wrap mw-100">', $this->warRoom);
        $this->assertStringNotContainsString('<div class="d-flex gap-2 align-items-center flex-shrink-0">', $this->warRoom);
    }
}
