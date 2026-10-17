<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Command-only map buttons hidden on a volunteer's phone (v3.389.0).
 *
 * The rings hide/show button and the teal Σημεία button are tools for command:
 * the first flips an overlay command uses to plan a search, the second opens
 * the list command imports, assigns and edits points in. On a phone in a
 * volunteer's hand they only crowd the map, so accounts that cannot manage the
 * Action Room get class wr-hide-phone on both, and a phone-width media query
 * hides it. Command keeps both on every screen size.
 *
 * Whether the buttons are actually gone was measured in a browser (375px and
 * 1280px, command and volunteer); these pin the three pieces that rule is made
 * of, so dropping any one of them fails here instead of showing on a phone.
 */
final class MapToolbarPhoneButtonsTest extends TestCase
{
    private string $source;

    protected function setUp(): void
    {
        $this->source = (string) file_get_contents(__DIR__ . '/../war-room.php');
    }

    public function testThePhoneRuleHidesTheClassOnlyAtPhoneWidth(): void
    {
        $this->assertMatchesRegularExpression(
            '~@media \(max-width: 767\.98px\) \{\s*\.wr-hide-phone \{ display: none !important; \}\s*\}~',
            $this->source,
            'the class must be hidden by a phone-width media query, not everywhere'
        );
    }

    public function testTheRingsButtonGetsTheClassOnlyWhenTheAccountCannotManage(): void
    {
        $this->assertMatchesRegularExpression(
            '~id="mapRingsToggle"[^>]*class="[^"]*<\?= \$canManageWarRoom \? \'\' : \' wr-hide-phone\' \?>"~',
            $this->source
        );
    }

    public function testTheSigmeiaControlGetsTheClassOnlyWhenTheAccountCannotManage(): void
    {
        $this->assertStringContainsString(
            "'leaflet-bar leaflet-control wr-mp-control' + (MP_CAN_MANAGE ? '' : ' wr-hide-phone')",
            $this->source
        );
    }
}
