<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The console's «Προετοιμασία» checklist now also asks whether everybody is in a
 * team and whether every team has someone on GPS (v3.410.0). The logic is pure
 * and tested in tests/js/war-room-console.test.js; this pins the two things only
 * the page can get wrong: who is told the roster, and that every line of the
 * checklist has a text in both languages.
 */
final class ConsoleReadinessTeamsTest extends TestCase
{
    public function testTheRosterIdsGoOnlyToCommandStaff(): void
    {
        $page = (string) file_get_contents(__DIR__ . '/../war-room.php');
        $this->assertStringContainsString(
            "const ROSTER_PERSON_IDS = <?= json_encode(\$canManageWarRoom ? array_values(array_map('intval', array_keys(\$distinctApprovedById))) : []) ?>;",
            $page,
            'a volunteer\'s page must never receive the list of everybody on the roster'
        );
    }

    public function testEveryChecklistRowHasATextInBothLanguages(): void
    {
        $js = (string) file_get_contents(__DIR__ . '/../assets/js/war-room-console.js');
        $start = strpos($js, 'const CHECK_TEXT = {');
        $this->assertNotFalse($start);
        $block = substr($js, $start, strpos($js, '};', $start) - $start);
        preg_match_all("~'(console\\.chk_[a-z_]+)'~", $block, $m);
        $keys = array_unique($m[1]);
        $this->assertContains('console.chk_unteamed_warn', $keys);
        $this->assertContains('console.chk_teamgps_bad', $keys);

        $strings = require __DIR__ . '/../includes/lang/war-room.php';
        foreach (['el', 'en'] as $lang) {
            foreach ($keys as $key) {
                $this->assertArrayHasKey($key, $strings[$lang], "'$lang' has no $key");
                $this->assertNotSame('', $strings[$lang][$key]);
            }
        }
    }

    public function testTheTeamGpsTextNamesTheTeamsAndTheCountsAreFilledIn(): void
    {
        $strings = require __DIR__ . '/../includes/lang/war-room.php';
        foreach (['el', 'en'] as $lang) {
            $this->assertStringContainsString('{names}', $strings[$lang]['console.chk_teamgps_bad']);
            foreach (['console.chk_teamgps_bad', 'console.chk_unteamed_warn'] as $key) {
                $this->assertStringContainsString('{n}', $strings[$lang][$key]);
                $this->assertStringContainsString('{of}', $strings[$lang][$key]);
            }
        }
    }
}
