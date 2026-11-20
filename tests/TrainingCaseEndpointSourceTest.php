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
