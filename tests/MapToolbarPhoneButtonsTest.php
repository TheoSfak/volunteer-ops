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
 * Since v3.391.1 the rings button is not rendered for a volunteer at all (any
 * screen size); only the Σημεία control still relies on the phone rule.
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

    public function testTheRingsButtonIsRenderedOnlyForAccountsThatCanManage(): void
    {
        // v3.391.1: no longer just hidden on a phone — a volunteer's page has no
        // rings button at all, like the layers button (MapLayersAdminOnlyTest).
        $this->assertMatchesRegularExpression(
            '~<\?php if \(\$searchRingsOn && \$canManageWarRoom\): \?>.*?id="mapRingsToggle".*?<i class="bi bi-bullseye"></i>\s*</button>\s*<\?php endif; \?>~s',
            $this->source,
            'the rings button must sit inside the $canManageWarRoom gate'
        );
        $this->assertSame(1, substr_count($this->source, 'id="mapRingsToggle"'), 'one button, one gate');
        // Everything that reads it tolerates it being absent.
        $this->assertStringContainsString("document.getElementById('mapRingsToggle')?.addEventListener('click'", $this->source);
        $this->assertStringContainsString("document.getElementById('mapRingsToggle')?.classList.toggle('d-none'", $this->source);
        $this->assertMatchesRegularExpression("~const ringsBtn = document\\.getElementById\\('mapRingsToggle'\\);\\s*if \\(ringsBtn\\) \\{~", $this->source);
    }

    public function testTheSigmeiaControlGetsTheClassOnlyWhenTheAccountCannotManage(): void
    {
        $this->assertStringContainsString(
            "'leaflet-bar leaflet-control wr-mp-control' + (MP_CAN_MANAGE ? '' : ' wr-hide-phone')",
            $this->source
        );
    }
}
