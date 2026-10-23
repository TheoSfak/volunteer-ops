<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The map's two menus in the Konsola — map views and geographic export (v3.403.1).
 *
 * Under the Nocturne and Aurora looks both menus opened BEHIND the map, so
 * picking another map view (or exporting) meant clicking at nothing:
 *
 *  - the skins round the map card with `overflow:hidden` and a selector that
 *    started with an id, so the classic rule that lets an open menu out of the
 *    card (`.card.wr-menu-open`) never won — the 554px layers menu was cut at
 *    the card's edge;
 *  - they blur the card header with `backdrop-filter`, which makes the header a
 *    stacking context, and a menu inside it cannot rise above the map's panes
 *    (z-index 400-1000) or Nocturne's scan lines (450) in the sibling card body;
 *  - Aurora's blurred order form, a later sibling of the card, painted over the
 *    part of the menu that hangs below the card.
 *
 * Where each menu ends up was measured in a browser, every look, three viewport
 * sizes, plus fullscreen (elementFromPoint on a grid of points over the menu);
 * these pin the pieces a later edit would quietly undo.
 */
final class ConsoleMapMenusTest extends TestCase
{
    private string $base;
    private string $warRoom;

    protected function setUp(): void
    {
        $this->base = (string) file_get_contents(__DIR__ . '/../assets/css/war-room-console.css');
        $this->warRoom = (string) file_get_contents(__DIR__ . '/../war-room.php');
    }

    /** (ids, classes + attributes + pseudo-classes, elements) of one simple selector chain. */
    private function specificity(string $selector): array
    {
        $ids = preg_match_all('~#[\w-]+~', $selector);
        $selector = preg_replace('~#[\w-]+~', '', $selector);
        $classes = preg_match_all('~\.[\w-]+|\[[^\]]*\]~', $selector);
        $selector = preg_replace('~\.[\w-]+|\[[^\]]*\]~', '', $selector);
        // :not(x) counts as x; this file has no other functional pseudo-class on these rules.
        $classes += preg_match_all('~:(?!:)[\w-]+~', $selector);
        $selector = preg_replace('~:(?!:)[\w-]+(\([^)]*\))?~', '', $selector);
        $elements = preg_match_all('~(?<![\w-])[a-z][\w-]*~i', $selector);
        return [$ids, $classes, $elements];
    }

    private function outranks(array $a, array $b): bool
    {
        return $a > $b; // PHP compares arrays element by element, in order
    }

    public function testTheOpenCardLetsItsMenuOutAndTheHeaderRisesAboveTheMap(): void
    {
        $this->assertStringContainsString('body.wr-console-view .wrc-mapslot #mapCard.wr-menu-open { overflow: visible; }', $this->base);
        $this->assertMatchesRegularExpression(
            '~body\.wr-console-view \.wrc-mapslot #mapCard\.wr-menu-open \.card-header \{[^}]*position: relative;[^}]*z-index: 1100;~',
            $this->base
        );
        // The card itself goes above the order form under it — but never in fullscreen, where it is fixed at 1040.
        $this->assertStringContainsString('#mapCard.wr-menu-open:not(.map-fullscreen-active) { z-index: 1100; }', $this->base);
    }

    public function testTheRulesOutrankEverySkinRuleThatHidesTheMenu(): void
    {
        $ours = $this->specificity('body.wr-console-view .wrc-mapslot #mapCard.wr-menu-open');
        $this->assertSame([1, 3, 1], $ours);

        $checked = 0;
        foreach (glob(__DIR__ . '/../assets/css/war-room-console-*.css') ?: [] as $file) {
            $css = (string) file_get_contents($file);
            // Every skin rule on the card itself that clips (overflow) or stacks (position/z-index/transform).
            preg_match_all('~([^{}]*#mapCard)\s*\{([^}]*)\}~', $css, $rules, PREG_SET_ORDER);
            foreach ($rules as $rule) {
                if (!preg_match('~overflow:|z-index:~', $rule[2])) {
                    continue;
                }
                foreach (explode(',', trim($rule[1])) as $selector) {
                    $checked++;
                    $this->assertFalse(
                        $this->outranks($this->specificity(trim($selector)), $ours),
                        basename($file) . ': «' . trim($selector) . '» now outranks the open-menu rule and would clip the menu again'
                    );
                }
            }
        }
        $this->assertGreaterThan(0, $checked, 'the skins no longer clip the card — drop this test with the workaround');
    }

    public function testEveryDropdownInTheMapHeaderGetsTheRoom(): void
    {
        // Bootstrap's dropdown events bubble; the listener sits on the card so the
        // export menu — which has no code of its own — is covered as well.
        $this->assertStringContainsString("menuCard?.addEventListener('show.bs.dropdown', () => menuCard.classList.add('wr-menu-open'));", $this->warRoom);
        $this->assertStringContainsString("menuCard?.addEventListener('hidden.bs.dropdown', () => menuCard.classList.remove('wr-menu-open'));", $this->warRoom);
        $this->assertStringNotContainsString("btn.addEventListener('show.bs.dropdown', () => menuCard", $this->warRoom, 'the per-button listener only ever covered the layers menu');
        // …and the export menu really is a dropdown inside the same card as the layers button.
        $this->assertMatchesRegularExpression('~data-bs-toggle="dropdown" title="<\?= t\(\'geo\.export_btn\'\) \?>"~', $this->warRoom);
    }

    public function testTheSkinsStillDoWhatTheWorkaroundIsFor(): void
    {
        // If a skin stops blurring the header, the header z-index rule is dead weight: say so.
        $blurred = 0;
        foreach (glob(__DIR__ . '/../assets/css/war-room-console-*.css') ?: [] as $file) {
            if (preg_match('~#mapCard \.card-header\s*\{[^}]*backdrop-filter~', (string) file_get_contents($file))) {
                $blurred++;
            }
        }
        $this->assertGreaterThan(0, $blurred);
    }
}
