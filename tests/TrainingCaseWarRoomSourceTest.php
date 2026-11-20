<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** The volunteer's case screen: how war-room.php wires assets/js/training-case.js, and its texts. */
final class TrainingCaseWarRoomSourceTest extends TestCase
{
    private string $page;
    private string $js;
    private string $lang;

    protected function setUp(): void
    {
        $this->page = str_replace("\r\n", "\n", (string) WarRoomSource::read('war-room.php'));
        $this->js = str_replace("\r\n", "\n", (string) file_get_contents(__DIR__ . '/../assets/js/training-case.js'));
        $this->lang = str_replace("\r\n", "\n", (string) WarRoomSource::read('includes/lang/war-room.php'));
    }

    public function test_the_page_loads_the_player_and_wires_the_button_and_the_queue(): void
    {
        foreach (['training-case.js', 'vo-case-open-btn', 'case_answer', 'training-case.php'] as $needle) {
            $this->assertStringContainsString($needle, $this->page, $needle);
        }
        $this->assertStringContainsString('VoTrainingCase.open(', $this->page);
    }

    public function test_the_order_button_shows_only_for_a_dispatch_row_that_carries_a_case_card(): void
    {
        $this->assertMatchesRegularExpression('/if \(d\.case && d\.case\.can_play\)/', $this->page);
        $this->assertStringContainsString('actionHtml + caseHtml + directionsHtml', $this->page);
    }

    public function test_a_queued_answer_is_kept_unless_the_server_refused_it(): void
    {
        $this->assertStringContainsString("if (item.kind === 'case_answer') return sendQueuedCaseAnswer(item);", $this->page);
        $start = strpos($this->page, 'function sendQueuedCaseAnswer(');
        $this->assertNotFalse($start);
        $branch = substr($this->page, $start, 1800);
        $this->assertStringContainsString('VoTrainingCase.queueOutcome(result)', $branch);
        $this->assertStringContainsString("action: 'answer'", $branch);
        $this->assertStringContainsString('forgetLocalAnswer(WR_MY_USER_ID, item.placementId, item.stepId)', $branch);
        $this->assertStringContainsString('deferCaseAnswers(item)', $branch);
        // The retry-vs-drop decision rests on the endpoint's machine code, never on translated text.
        $this->assertStringNotContainsString('mission_not_found_or_inactive', $branch);
        $this->assertStringNotContainsString("t('common.", $branch);
        $this->assertStringContainsString("code 'refused'", substr($this->page, $start - 900, 900));
    }

    public function test_deferring_a_stuck_answer_asks_the_queue_helper_and_is_rate_limited(): void
    {
        $start = strpos($this->page, 'function deferCaseAnswers(');
        $this->assertNotFalse($start);
        $fn = substr($this->page, $start, 700);
        $this->assertStringContainsString('VoMediaQueue.caseAnswerDeferral(', $fn);
        $this->assertStringContainsString('caseDeferredAt', $fn);
        $this->assertStringContainsString('if (!reordered) return false;', $fn);
        // The re-flush happens only when the queue was really reordered.
        $this->assertStringContainsString('if (result && deferCaseAnswers(item)) setTimeout(flushQueue, 500);', $this->page);
    }

    public function test_the_overlay_sos_looks_the_real_button_up_when_it_fires_and_the_viewer_scopes_the_cache(): void
    {
        $start = strpos($this->page, "caseBtn = e.target.closest('.vo-case-open-btn')");
        $this->assertNotFalse($start);
        $handler = substr($this->page, $start, 1700);
        $this->assertStringContainsString('const findRealSos = () => document.querySelector(', $handler);
        $this->assertStringContainsString('const sos = findRealSos(); if (sos) sos.click(); return !!sos;', $handler);
        $this->assertStringNotContainsString('realSos', $handler, 'the node is not captured when the overlay opens');
        $this->assertStringContainsString('userId: WR_MY_USER_ID', $handler);
    }

    public function test_the_player_is_accessible_and_scopes_its_cache_by_viewer(): void
    {
        foreach (["aria-modal', 'true'", "'aria-label', t('case.dialog_label')", 'overlay.focus(', 'previousFocus', 'storageKeys(opts.userId, opts.placementId)', 'refreshIfChanged'] as $needle) {
            $this->assertStringContainsString($needle, $this->js, $needle);
        }
        // The overlay's SOS is a normal-sized target and can be held from the keyboard too.
        $this->assertDoesNotMatchRegularExpression('/btn-sm btn-danger/', $this->js);
        $this->assertStringContainsString("e.key !== 'Enter'", $this->js);
    }

    public function test_case_text_only_reaches_innerhtml_through_escapeHtml(): void
    {
        foreach (['escapeHtml(doc.story)', 'escapeHtml(step.prompt)', 'escapeHtml(o.feedback)', 'escapeHtml(c.label)', 'escapeHtml(doc.title)'] as $needle) {
            $this->assertStringContainsString($needle, $this->js, $needle);
        }
        // No field of the case document is concatenated into markup bare.
        $this->assertDoesNotMatchRegularExpression('/\+\s*(?:doc|step|o|f|c|b|choice)\.(?:title|story|closing|prompt|hint|label|value|unit|feedback)\b/', $this->js);
        // The overlay must never take case text as markup.
        $this->assertDoesNotMatchRegularExpression('/\.innerHTML\s*=\s*(?!\s*[\'"]<div class="container)/', $this->js);
    }

    public function test_every_case_key_the_player_and_the_page_use_exists_in_both_languages(): void
    {
        $used = [];
        foreach ([$this->js, $this->page] as $source) {
            preg_match_all("/\\bt\\('(case\\.[a-z_.]*[a-z_])'/", $source, $m);
            $used = array_merge($used, $m[1]);
        }
        // The rating and label keys are built from a value: list the ones that can occur.
        foreach (['best', 'acceptable', 'harmful'] as $r) {
            $used[] = 'case.rating.' . $r;
        }
        foreach (['excellent', 'good', 'practice'] as $l) {
            $used[] = 'case.label.' . $l;
        }
        $used = array_values(array_unique($used));
        $this->assertGreaterThan(15, count($used));

        $parts = preg_split("/^\\s*'en' => \\[/m", $this->lang);
        $this->assertCount(2, $parts, 'the language file has an el block and an en block');
        foreach ($used as $key) {
            foreach (['el' => $parts[0], 'en' => $parts[1]] as $name => $block) {
                $this->assertStringContainsString("'" . $key . "' =>", $block, "$key missing in $name");
            }
        }
    }
}
