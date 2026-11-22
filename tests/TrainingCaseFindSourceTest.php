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
        // (guarded: a throw in the find sync must never blank the order list that is drawn right after it)
        $this->assertStringContainsString("try { wrCaseFindSync(items); } catch (e) { console.error('[training-cases] find sync failed', e); }", substr($page, (int) strpos($page, 'function renderDispatches(items)'), 400));
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

    public function test_the_queued_found_report_sends_the_raw_accuracy_the_tracker_classified_with(): void
    {
        $page = str_replace("\r\n", "\n", (string) WarRoomSource::read('war-room.php'));
        $start = strpos($page, 'function sendQueuedCaseFound(');
        $this->assertNotFalse($start);
        $body = substr($page, $start, 900);
        $this->assertStringContainsString("accuracy: item.acc != null && isFinite(item.acc) ? String(item.acc) : ''", $body);
        $this->assertStringNotContainsString('Math.round', $body, 'rounding the accuracy lets the server disagree with the phone by up to half a metre');
    }

    public function test_a_refused_found_report_takes_the_local_marker_back_and_relocks_the_row(): void
    {
        $page = str_replace("\r\n", "\n", (string) WarRoomSource::read('war-room.php'));
        $start = strpos($page, 'function sendQueuedCaseFound(');
        $this->assertNotFalse($start);
        $body = substr($page, $start, 2200);
        $this->assertStringContainsString("if (verdict === 'drop')", $body);
        $this->assertStringContainsString('localStorage.removeItem(wrCaseFoundKey(item.placementId))', $body);
        $this->assertStringContainsString('refreshMyOrdersCard();', substr($body, (int) strpos($body, "if (verdict === 'drop')")));
        // ... and the tracker stops being latched on it, so the phone watches that placement again without a reload
        $this->assertStringContainsString('caseFindTracker.forget(item.placementId)', substr($body, (int) strpos($body, "if (verdict === 'drop')")));
        $this->assertStringContainsString('{check() {}, forget() {}}', $page, 'the fallback stub of a failed script load has forget() too');
    }

    public function test_the_prefetch_is_guarded_and_the_tracker_survives_a_failed_script_load(): void
    {
        $page = str_replace("\r\n", "\n", (string) WarRoomSource::read('war-room.php'));
        $this->assertStringContainsString('const caseFindPrefetch = {};', $page);
        $sync = substr($page, (int) strpos($page, 'function wrCaseFindSync('), 1500);
        $this->assertStringContainsString('caseFindPrefetch[', $sync);
        $this->assertStringContainsString('60000', $sync);
        $this->assertStringContainsString('const caseFindTracker = window.VoCaseFind ? VoCaseFind.createTracker({', $page);
    }

    public function test_every_new_text_exists_in_both_languages(): void
    {
        $lang = (string) file_get_contents(dirname(__DIR__) . '/includes/lang/war-room.php');
        foreach (['case.close_toast', 'case.found_toast', 'case.opens_on_find', 'case.found_title', 'case.found_message', 'case.err_too_far',
                  'case.err_not_found_yet', 'case.err_spot_needed', 'case.err_spot_outside', 'case.overview_found',
                  'case.spot_pin', 'case.spot_pin_help', 'case.spot_pinned'] as $key) {
            $this->assertSame(2, substr_count($lang, "'" . $key . "' =>"), $key . ' in el and en');
        }
    }

    public function test_the_dispatch_window_stages_a_case_on_an_area_with_a_pinned_spot(): void
    {
        $page = str_replace("\r\n", "\n", (string) WarRoomSource::read('war-room.php'));
        $this->assertStringNotContainsString("if (wantsCase && isClosed) { alert(t('case.err_needs_point')); return; }", $page);
        $this->assertStringContainsString("t('case.err_spot_needed')", $page);
        $this->assertStringContainsString('pointInPolygon(', substr($page, (int) strpos($page, 'dispatchCaseSpotBtn')));
        $this->assertStringContainsString('id="dispatchCaseSpotBtn"', $page);
        $mission = str_replace("\r\n", "\n", (string) file_get_contents(dirname(__DIR__) . '/mission-scenario.php'));
        // (the array is column-aligned, so allow any run of spaces before the arrow)
        $this->assertMatchesRegularExpression("/'spot_lat'\\s+=> post\\('spot_lat'\\)/", $mission);
        $this->assertMatchesRegularExpression("/'spot_lng'\\s+=> post\\('spot_lng'\\)/", $mission);
    }
}
