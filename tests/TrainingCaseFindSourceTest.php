<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** The scripts that carry finding the victim, read from their source. */
final class TrainingCaseFindSourceTest extends TestCase
{
    private function src(string $file): string
    {
        return str_replace("\r\n", "\n", (string) file_get_contents(dirname(__DIR__) . '/' . $file));
    }

    public function test_the_found_action_is_post_only_behind_the_csrf_check_and_validates_the_position(): void
    {
        $src = $this->src('training-case.php');
        $csrf = strpos($src, 'hash_equals($sessionToken');
        $found = strpos($src, "\$action === 'found' && isPost()");
        $this->assertNotFalse($csrf);
        $this->assertNotFalse($found);
        $this->assertGreaterThan($csrf, $found, 'the CSRF check comes first');
        $branch = substr($src, $found, 900);
        $this->assertStringContainsString('trainingCaseRecordFound(', $branch);
        $this->assertStringContainsString("is_numeric(post('lat'))", $branch);
        $this->assertStringContainsString("'code' => 'refused'", $branch, 'a position that can never be valid is dropped, not retried');
    }

    public function test_the_page_loads_the_module_and_wires_the_watcher_the_queue_and_the_button(): void
    {
        $page = str_replace("\r\n", "\n", (string) WarRoomSource::read('war-room.php'));
        $this->assertStringContainsString('assets/js/training-case-find.js', $page);
        $this->assertStringContainsString('wrCaseFindSync(items);', substr($page, (int) strpos($page, 'function renderDispatches(items)'), 400));
        $this->assertStringContainsString("if (item.kind === 'case_found') return sendQueuedCaseFound(item);", $page);
        $this->assertStringContainsString('enableHighAccuracy: true', substr($page, (int) strpos($page, 'function wrCaseFindStart'), 900));
        $this->assertStringContainsString('function openTrainingCase(', $page);
        $this->assertStringContainsString("kind: 'case_found'", $page);
        $this->assertMatchesRegularExpression('/cs\.available \|\| wrCaseFoundLocally\(cs\.placement_id\)/', $page);
        $this->assertStringNotContainsString("d.my_ack || cs.available", $page, 'arriving no longer opens a hidden case');
    }

    public function test_the_queue_keeps_a_found_report_until_the_server_refuses_it(): void
    {
        $page = str_replace("\r\n", "\n", (string) WarRoomSource::read('war-room.php'));
        $start = strpos($page, 'function sendQueuedCaseFound(');
        $this->assertNotFalse($start);
        $body = substr($page, $start, 1400);
        $this->assertStringContainsString("action: 'found'", $body);
        $this->assertStringContainsString('VoTrainingCase.queueOutcome(result)', $body);
        $this->assertStringContainsString('deferCaseAnswers(item)', $body);
    }

    public function test_every_new_text_exists_in_both_languages(): void
    {
        $lang = (string) file_get_contents(dirname(__DIR__) . '/includes/lang/war-room.php');
        // case.spot_pin, case.spot_pin_help and case.spot_pinned join this list with the pin control (Task 10).
        foreach (['case.close_toast', 'case.found_toast', 'case.opens_on_find', 'case.found_title', 'case.found_message', 'case.err_too_far',
                  'case.err_not_found_yet', 'case.err_spot_needed', 'case.err_spot_outside', 'case.overview_found'] as $key) {
            $this->assertSame(2, substr_count($lang, "'" . $key . "' =>"), $key . ' in el and en');
        }
    }
}
