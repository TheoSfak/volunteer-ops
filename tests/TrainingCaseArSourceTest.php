<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** The victim on the AR screen (v3.507.0): how war-room.php, assets/js/war-room-ar.js and the case player fit together. */
final class TrainingCaseArSourceTest extends TestCase
{
    private string $page;
    private string $ar;
    private string $player;
    private string $lang;

    protected function setUp(): void
    {
        $read = static fn (string $path): string => str_replace("\r\n", "\n", (string) file_get_contents(__DIR__ . '/../' . $path));
        $this->page = $read('war-room.php');
        $this->ar = $read('assets/js/war-room-ar.js');
        $this->player = $read('assets/js/training-case.js');
        $this->lang = $read('includes/lang/war-room.php');
    }

    public function test_the_ar_never_reads_the_spot_of_a_case_card(): void
    {
        // The hidden spot must not reach any drawing code: the AR asks victimView, which never returns it.
        $this->assertStringNotContainsString('.spot', $this->ar);
        $this->assertStringNotContainsString('spot_lat', $this->ar);
        $this->assertStringContainsString('VoCaseFind.victimView(', $this->ar);
    }

    public function test_the_order_model_carries_the_case_card_to_the_ar(): void
    {
        $start = strpos($this->page, 'function opModelFromDispatch(');
        $this->assertNotFalse($start);
        $model = substr($this->page, $start, 3000);
        $this->assertStringContainsString('case: d.case || null,', $model);
    }

    public function test_a_known_victim_is_drawn_red_only_on_a_point_order(): void
    {
        $this->assertStringContainsString("victim.mode === 'marker' && t0.kind === 'point' ? AR_COL.red : AR_COL.green", $this->ar);
        $this->assertStringContainsString("t('ar.victim')", $this->ar);
    }

    public function test_the_near_frame_and_the_banner_exist_and_the_frame_is_dimmed_with_the_rest(): void
    {
        $this->assertStringContainsString('wr-ar-casenear', $this->ar);
        $this->assertStringContainsString('arState.caseNear', $this->ar);
        $this->assertStringContainsString("t('case.close_toast')", $this->ar);
        $this->assertMatchesRegularExpression('/\.wr-ar-casenear \{[^}]*pointer-events: none;/', $this->page);
        $this->assertStringContainsString('.wr-ar-dim .wr-ar-casenear', $this->page);
        $this->assertMatchesRegularExpression('/\.wr-ar-dim \.wr-ar-casenear \{[^}]*animation: none;/', $this->page);
    }

    public function test_the_case_button_is_shown_by_canOpen_and_opens_the_same_player_as_the_order_row(): void
    {
        $this->assertStringContainsString('data-ar="case"', $this->ar);
        $this->assertStringContainsString('VoCaseFind.canOpen(', $this->ar);
        $this->assertStringContainsString('wrCaseFoundLocally', $this->ar);
        $this->assertStringContainsString("case 'case':", $this->ar);
        $this->assertStringContainsString('openTrainingCase(', $this->ar);
        $this->assertStringContainsString("t('ar.case_open')", $this->ar);
        $this->assertStringContainsString('.wr-ar-casebtn', $this->page);
    }

    public function test_the_case_player_sits_above_the_ar_screen(): void
    {
        preg_match('/\.wr-ar \{[^}]*z-index: (\d+);/', $this->page, $ar);
        preg_match('/id = \'voCaseOverlay\'.*?z-index:(\d+);/s', $this->player, $player);
        $this->assertNotEmpty($ar, 'the AR layer rule');
        $this->assertNotEmpty($player, 'the player overlay rule');
        $this->assertGreaterThan((int) $ar[1], (int) $player[1], 'a case found with the AR open must show over it');
        $this->assertLessThan(100001, (int) $player[1], 'the find toast stays above the player');
    }

    public function test_escape_leaves_the_ar_open_while_the_case_player_is_up(): void
    {
        $this->assertStringContainsString("e.key === 'Escape' && !document.getElementById('voCaseOverlay')", $this->ar);
    }

    public function test_the_new_texts_exist_in_both_languages(): void
    {
        foreach (['ar.victim', 'ar.case_open'] as $key) {
            $this->assertSame(2, substr_count($this->lang, "'$key' =>"), "$key in Greek and English");
        }
    }
}
