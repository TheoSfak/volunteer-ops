<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** The endpoint's guards, read from its source (it is a script, not a function). */
final class TrainingCaseEndpointSourceTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $this->src = str_replace("\r\n", "\n", (string) file_get_contents(__DIR__ . '/../training-case.php'));
    }

    public function test_it_requires_login_and_an_open_training_mission(): void
    {
        $this->assertStringContainsString('requireLogin();', $this->src);
        $this->assertStringContainsString("STATUS_OPEN", $this->src);
        $this->assertStringContainsString('isTrainingMission(', $this->src);
    }

    public function test_posts_check_the_csrf_token(): void
    {
        $this->assertMatchesRegularExpression('/csrf_token.*hash_equals|hash_equals.*csrf_token/s', $this->src);
    }

    public function test_an_empty_session_token_never_passes_the_csrf_check(): void
    {
        // hash_equals('', '') is true: a session without a token would accept a post without one.
        $this->assertMatchesRegularExpression('/\$sessionToken\s*=\s*\$_SESSION\[\'csrf_token\'\]/', $this->src);
        $this->assertStringContainsString("\$sessionToken === ''", $this->src);
        $this->assertStringContainsString('hash_equals($sessionToken, (string) $_POST[\'csrf_token\'])', $this->src);
        $this->assertStringNotContainsString("hash_equals(\$_SESSION['csrf_token'] ?? ''", $this->src);
        $at = strpos($this->src, "\$sessionToken === ''");
        $this->assertLessThan(strpos($this->src, 'hash_equals($sessionToken'), $at, 'the empty-token refusal is checked before the comparison');
    }

    public function test_the_reply_is_never_cached(): void
    {
        // The load reply carries the answer key.
        $this->assertStringContainsString("header('Cache-Control: private, no-store');", $this->src);
        $this->assertLessThan(strpos($this->src, 'trainingCasePlacement('), strpos($this->src, 'Cache-Control'), 'sent before anything can be echoed');
    }

    public function test_the_whole_dispatch_is_inside_one_try_that_answers_json(): void
    {
        $try = strpos($this->src, "\ntry {");
        $catch = strpos($this->src, '} catch (Throwable $e) {');
        $this->assertNotFalse($try);
        $this->assertNotFalse($catch);
        $this->assertSame(1, substr_count($this->src, '} catch (Throwable $e) {'), 'one outer catch');
        // Everything that reads or writes - the mission lookup, the overview, load, start, answer - is between them.
        foreach (['dbFetchOne(', 'trainingCaseOverview(', 'trainingCasePlacement(', 'trainingCaseRunState(', 'trainingCaseStartRun(', 'trainingCaseAnswer('] as $call) {
            $at = strpos($this->src, $call);
            $this->assertNotFalse($at, $call);
            $this->assertGreaterThan($try, $at, "$call is inside the try");
            $this->assertLessThan($catch, $at, "$call is inside the try");
        }
        $handler = substr($this->src, $catch);
        $this->assertStringContainsString('error_log(', $handler, 'the exception is logged');
        $this->assertStringContainsString("t('common.error')", $handler, 'and answered with the generic JSON error');
        $this->assertStringContainsString('echo json_encode(', $handler);
    }

    public function test_every_reply_is_encoded_with_the_substitute_and_unescaped_unicode_flags(): void
    {
        $this->assertStringContainsString('JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE', $this->src);
        // No json_encode() call without the flags: a malformed byte in a title must not make json_encode() return false.
        preg_match_all('/json_encode\(.*?\);/s', $this->src, $calls);
        $this->assertNotEmpty($calls[0]);
        foreach ($calls[0] as $call) {
            $this->assertStringContainsString('TRAINING_CASE_JSON_FLAGS', $call, $call);
        }
    }

    public function test_the_overview_is_for_command_only(): void
    {
        $this->assertMatchesRegularExpression('/action === \'overview\'.*canManageActionRoom/s', $this->src);
    }

    public function test_answers_and_start_go_through_the_server_functions(): void
    {
        $this->assertStringContainsString('trainingCaseAnswer(', $this->src);
        $this->assertStringContainsString('trainingCaseStartRun(', $this->src);
        $this->assertStringContainsString('trainingCaseGate(', $this->src, 'load applies the same gate without the arrival');
    }

    public function test_it_never_reads_a_rating_or_score_from_the_request(): void
    {
        $this->assertStringNotContainsString("post('rating')", $this->src);
        $this->assertStringNotContainsString("post('score", $this->src);
    }

    public function test_load_gates_the_audience_but_not_the_arrival_and_reads_the_run_from_the_server(): void
    {
        $this->assertStringContainsString('trainingCaseGate($placement, $userId, false)', $this->src);
        $this->assertStringNotContainsString('trainingCaseGate($placement, $userId, true)', $this->src);
        $this->assertStringContainsString('trainingCaseRunState(', $this->src);
    }

    public function test_the_answer_is_retried_once_after_a_deadlock_or_lock_wait(): void
    {
        $this->assertStringContainsString('trainingCaseIsLockError(', $this->src);
        $at = strpos($this->src, "if (\$action === 'answer' && isPost())");
        $this->assertNotFalse($at);
        $block = substr($this->src, $at);
        $this->assertStringContainsString('$attempt <= 2', $block, 'one attempt and one retry');
        $this->assertStringContainsString('catch (PDOException', $block);
        $this->assertStringContainsString("'ok' => false", $block, 'and a standard JSON error when the retry fails too');
    }

    public function test_start_and_answer_are_post_only(): void
    {
        $this->assertStringContainsString("\$action === 'start' && isPost()", $this->src);
        $this->assertStringContainsString("\$action === 'answer' && isPost()", $this->src);
    }

    public function test_the_two_lock_error_codes_are_recognised_and_other_errors_are_not(): void
    {
        $this->assertTrue(trainingCaseIsLockError($this->pdoError('40001', 1213, 'Deadlock found when trying to get lock')));
        $this->assertTrue(trainingCaseIsLockError($this->pdoError('HY000', 1205, 'Lock wait timeout exceeded')));
        $this->assertFalse(trainingCaseIsLockError($this->pdoError('42S02', 1146, "Table 'x' doesn't exist")));
        $this->assertFalse(trainingCaseIsLockError(new RuntimeException('Deadlock')));
    }

    private function pdoError(string $state, int $code, string $message): PDOException
    {
        $e = new PDOException($message);
        $e->errorInfo = [$state, $code, $message];
        return $e;
    }
}
