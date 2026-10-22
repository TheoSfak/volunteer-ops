<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The «Κονσόλα» looks (Settings → Action Room → «Εμφάνιση Κονσόλας»).
 *
 * A look is one more stylesheet over the same markup, chosen by one setting. What is pinned:
 *  - the setting exists, defaults to the console as it always was, is saved with the Action Room
 *    form and is checked against a closed list on the way in AND on the way out (the value ends up
 *    in a file path and in an HTML attribute);
 *  - war-room.php loads a skin only for the console view, and only a skin that is not 'classic';
 *  - a skin can never leak into another look: every rule in its sheet sits behind
 *    html[data-wrc-skin="<its name>"].
 */
final class ConsoleSkinTest extends TestCase
{
    private const SKINS = ['nocturne', 'aurora'];

    private string $root;

    protected function setUp(): void
    {
        $this->root = (string) realpath(__DIR__ . '/..');
    }

    private function source(string $relative): string
    {
        $text = file_get_contents($this->root . '/' . $relative);
        $this->assertNotFalse($text, $relative);
        return str_replace("\r\n", "\n", (string) $text);
    }

    public function testTheSettingDefaultsToTheClassicConsoleAndIsSavedWithTheActionRoomForm(): void
    {
        $settings = $this->source('settings.php');
        $this->assertStringContainsString("'war_room_console_skin' => 'classic',", $settings);
        $this->assertMatchesRegularExpression("/\\\$actionRoomFields = \\[[^\\]]*'war_room_console_skin'/s", $settings);
        $this->assertStringContainsString(
            "\$field === 'war_room_console_skin' && !in_array(\$value, ['classic', 'nocturne', 'aurora'], true)",
            $settings,
            'anything but a known skin is stored as the classic console'
        );
        foreach (['classic', 'nocturne', 'aurora'] as $skin) {
            $this->assertStringContainsString('name="war_room_console_skin" value="' . $skin . '"', $settings, "the chooser offers $skin");
        }
    }

    public function testWarRoomLoadsASkinForTheConsoleViewOnly(): void
    {
        $page = $this->source('war-room.php');
        $this->assertStringContainsString("in_array(\$wrConsoleSkin, ['classic', 'nocturne', 'aurora'], true)) \$wrConsoleSkin = 'classic';", $page);

        $start = strpos($page, '<?php if ($consoleView): ?>' . "\n" . '<?php /* Empty on purpose');
        $this->assertNotFalse($start, 'the console block');
        $end = strpos($page, '<?php endif; ?>', (int) strpos($page, 'id="wrConsole"', (int) $start));
        $block = substr($page, (int) $start, (int) $end - (int) $start);

        $this->assertStringContainsString("<?php if (\$wrConsoleSkin !== 'classic'): ?>", $block, 'classic loads nothing extra');
        $this->assertStringContainsString("/assets/css/war-room-console-<?= \$wrConsoleSkin ?>.css", $block);
        $this->assertStringContainsString("document.documentElement.setAttribute('data-wrc-skin','<?= \$wrConsoleSkin ?>')", $block);
        // A dark look decides the colour mode itself; every other look keeps the coordinator's own light / dark choice.
        $this->assertStringContainsString("if (\$wrConsoleSkin === 'nocturne')", $block);
        $this->assertStringContainsString("localStorage.getItem('wr-console-theme')==='dark'", $block);
        // Nothing of a skin outside that block (the classic Action Room must not change).
        $outside = substr($page, 0, (int) $start) . substr($page, (int) $end);
        $this->assertStringNotContainsString('war-room-console-<?=', $outside);
        $this->assertStringNotContainsString('data-wrc-skin', $outside);
    }

    /** @return array<string, array{0: string}> */
    public static function skins(): array
    {
        return array_map(fn(string $s) => [$s], array_combine(self::SKINS, self::SKINS));
    }

    #[DataProvider('skins')]
    public function testEveryRuleOfASkinSitsBehindItsOwnAttribute(string $skin): void
    {
        $css = $this->source("assets/css/war-room-console-$skin.css");
        $css = (string) preg_replace('~/\*.*?\*/~s', '', $css);
        $prefix = 'html[data-wrc-skin="' . $skin . '"]';

        $selectors = $this->selectors($css);
        $this->assertGreaterThan(40, count($selectors), 'the sheet has rules');
        foreach ($selectors as $selector) {
            $this->assertStringStartsWith($prefix, $selector, "a rule outside the $skin attribute: $selector");
        }
    }

    #[DataProvider('skins')]
    public function testASkinNeverSwitchesTheConsoleOffOrMovesItsColumns(string $skin): void
    {
        $css = $this->source("assets/css/war-room-console-$skin.css");
        $css = (string) preg_replace('~/\*.*?\*/~s', '', $css);
        // A look, not a layout: nothing here may hide a region, change the three-column grid or take a control away.
        $this->assertStringNotContainsString('grid-template-columns', $css);
        $this->assertDoesNotMatchRegularExpression('/\.wrc(-left|-center|-dock|-composer|-queue|-teams)?\s*\{[^}]*display\s*:\s*none/', $css);
    }

    /**
     * The selectors of every style rule in $css, looking inside @media / @supports and skipping
     * @keyframes and @font-face (whose blocks hold no selectors of ours).
     *
     * @return list<string>
     */
    private function selectors(string $css): array
    {
        $out = [];
        $i = 0;
        $n = strlen($css);
        while ($i < $n) {
            $open = strpos($css, '{', $i);
            if ($open === false) {
                break;
            }
            $head = trim(substr($css, $i, $open - $i));
            // find the matching close
            $depth = 1;
            $j = $open + 1;
            while ($j < $n && $depth > 0) {
                if ($css[$j] === '{') {
                    $depth++;
                } elseif ($css[$j] === '}') {
                    $depth--;
                }
                $j++;
            }
            $body = substr($css, $open + 1, $j - $open - 2);
            if ($head !== '' && $head[0] === '@') {
                if (preg_match('/^@(media|supports)\b/', $head)) {
                    array_push($out, ...$this->selectors($body));
                }
            } elseif ($head !== '') {
                foreach (explode(',', $head) as $one) {
                    $out[] = trim($one);
                }
            }
            $i = $j;
        }
        return $out;
    }
}
