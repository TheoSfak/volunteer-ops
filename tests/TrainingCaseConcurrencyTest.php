<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * trainingCaseAnswer() against a REAL concurrent request: two separate connections, the second one a child PHP process
 * that really blocks on the run row's lock. Like TrainingCaseTransactionTest this works outside the harness's
 * rolled-back transaction (a transaction cannot be shared with another connection), so it COMMITS its fixture rows and
 * deletes them again in tearDown.
 */
final class TrainingCaseConcurrencyTest extends TestCase
{
    private array $drill;
    private int $userId;
    private int $teamId;
    private int $placementId;
    private ?PDO $a = null;
    /** @var resource|null */
    private $proc = null;
    private array $pipes = [];

    protected function setUp(): void
    {
        $this->assertFalse(db()->inTransaction(), 'this test needs a connection with no transaction open');
        $admin = TrainingFixtures::user('Admin');
        $this->drill = TrainingFixtures::mission(true);
        $this->teamId = (int) dbInsert("INSERT INTO mission_teams (mission_id, codename, team_number, created_by) VALUES (?, 'ΑΛΦΑ', 1, ?)", [$this->drill['mission'], $admin]);
        $this->userId = TrainingFixtures::user('Member');
        TrainingFixtures::participation($this->drill['shift'], $this->userId, false, null);
        dbExecute("INSERT INTO mission_team_members (mission_id, team_id, user_id) VALUES (?, ?, ?)", [$this->drill['mission'], $this->teamId, $this->userId]);
        $dispatchId = (int) dbInsert(
            "INSERT INTO mission_dispatch_points (mission_id, team_id, type, geo, label, created_by) VALUES (?, ?, 'point', ?, 'Περιστατικό', ?)",
            [$this->drill['mission'], $this->teamId, json_encode(['lat' => 35.3, 'lng' => 25.1]), $admin]
        );
        dbExecute("INSERT INTO mission_dispatch_progress (dispatch_id, team_id, scope_key, arrived_at) VALUES (?, ?, ?, NOW())", [$dispatchId, $this->teamId, 't' . $this->teamId]);
        $doc = [
            'title' => 'Δοκιμή', 'story' => 'Ιστορία.', 'closing' => 'Τέλος.', 'findings' => [], 'start' => 's1',
            'steps' => [
                's1' => ['prompt' => 'Πρώτο;', 'hint' => '', 'choices' => [
                    ['id' => 'a', 'label' => 'Α', 'rating' => 'best', 'feedback' => 'Σωστά.', 'next' => 's2'],
                    ['id' => 'b', 'label' => 'Β', 'rating' => 'harmful', 'feedback' => 'Λάθος.', 'next' => 's2'],
                ]],
                's2' => ['prompt' => 'Δεύτερο;', 'hint' => '', 'choices' => [
                    ['id' => 'a', 'label' => 'Α', 'rating' => 'acceptable', 'feedback' => 'Εντάξει.', 'next' => null],
                ]],
            ],
        ];
        $this->placementId = (int) trainingCaseCreatePlacement(
            $this->drill['mission'], 0, $dispatchId,
            ['case' => ['case_id' => 0, 'title' => 'Δοκιμή', 'draw_mode' => 'chosen', 'briefing' => 'Φωνή', 'document' => $doc]]
        );
        $this->assertGreaterThan(0, $this->placementId);

        $this->a = new PDO(
            'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
        );
    }

    protected function tearDown(): void
    {
        if ($this->proc !== null) {
            @proc_terminate($this->proc);
            @proc_close($this->proc);
            $this->proc = null;
        }
        if ($this->a !== null && $this->a->inTransaction()) {
            $this->a->rollBack();
        }
        $this->a = null;
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        // The committed fixture rows go: the mission cascades to its shifts, teams, orders, placements, runs and answers.
        if (isset($this->drill['mission'])) {
            dbExecute("DELETE FROM missions WHERE id = ?", [$this->drill['mission']]);
        }
        dbExecute("DELETE FROM users WHERE email LIKE 'trn-%@example.invalid'");
    }

    /** Starts the child: the same answer, on a connection of its own. */
    private function startChild(string $stepId, string $choiceId): void
    {
        $this->proc = proc_open(
            [PHP_BINARY, __DIR__ . '/fixtures/training-case-answer-child.php', (string) $this->placementId, (string) $this->userId, $stepId, $choiceId],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $this->pipes
        );
        $this->assertIsResource($this->proc);
    }

    /** Waits until some transaction is blocked on a lock (the child), up to ~10 s. */
    private function waitUntilBlocked(): void
    {
        for ($i = 0; $i < 100; $i++) {
            if ((int) dbFetchValue("SELECT COUNT(*) FROM information_schema.innodb_trx WHERE trx_state = 'LOCK WAIT'") > 0) {
                return;
            }
            usleep(100000);
        }
        $this->fail('the child never blocked on the run lock');
    }

    private function childReply(): array
    {
        $out = (string) stream_get_contents($this->pipes[1]);
        $err = (string) stream_get_contents($this->pipes[2]);
        proc_close($this->proc);
        $this->proc = null;
        $reply = json_decode($out, true);
        $this->assertIsArray($reply, 'the child printed JSON, got: ' . $out . $err);
        return $reply;
    }

    public function test_an_answer_that_waited_on_the_lock_sees_the_answer_committed_meanwhile_and_returns_the_duplicate(): void
    {
        $this->assertTrue(trainingCaseStartRun($this->placementId, $this->userId)['ok']);
        $runId = (int) dbFetchValue("SELECT id FROM mission_case_runs WHERE placement_id = ? AND user_id = ?", [$this->placementId, $this->userId]);

        // Connection A holds the run lock, as a request in the middle of answering would.
        $this->a->beginTransaction();
        $this->a->query("SELECT * FROM mission_case_runs WHERE id = $runId FOR UPDATE")->fetch();

        // Connection B (the child): a DIFFERENT choice for the same step. It has to wait for A.
        $this->startChild('s1', 'b');
        $this->waitUntilBlocked();

        // A stores its answer, advances the run, and lets go.
        $this->a->exec("INSERT INTO mission_case_answers (run_id, step_id, choice_id, rating) VALUES ($runId, 's1', 'a', 'best')");
        $this->a->exec("UPDATE mission_case_runs SET score_points = 2, score_max = 2, current_step = 's2' WHERE id = $runId");
        $this->a->commit();

        $reply = $this->childReply();
        $this->assertArrayNotHasKey('exception', $reply);
        $this->assertTrue($reply['ok'], 'the waiting answer is not refused: ' . json_encode($reply, JSON_UNESCAPED_UNICODE));
        $this->assertTrue($reply['duplicate'], 'it is recognised as a duplicate of the answer that got in first');
        $this->assertSame('a', $reply['choice_id'], 'the STORED choice is the result, not the waiting caller\'s');
        $this->assertSame('best', $reply['rating']);
        $this->assertSame('s2', $reply['next']);
        $this->assertSame(2, $reply['score_points']);

        $this->assertSame(1, (int) dbFetchValue("SELECT COUNT(*) FROM mission_case_answers WHERE run_id = ?", [$runId]), 'nothing was added twice');
        $this->assertSame(2, (int) dbFetchValue("SELECT score_points FROM mission_case_runs WHERE id = ?", [$runId]), 'and the score was not added twice');
    }

    public function test_an_answer_that_waited_on_a_run_the_other_one_finished_returns_the_stored_result_not_an_error(): void
    {
        $this->assertTrue(trainingCaseStartRun($this->placementId, $this->userId)['ok']);
        $runId = (int) dbFetchValue("SELECT id FROM mission_case_runs WHERE placement_id = ? AND user_id = ?", [$this->placementId, $this->userId]);
        // The first step is answered already; the run stands at s2.
        $this->assertTrue(trainingCaseAnswer($this->placementId, $this->userId, 's1', 'a')['ok']);

        $this->a->beginTransaction();
        $this->a->query("SELECT * FROM mission_case_runs WHERE id = $runId FOR UPDATE")->fetch();
        $this->startChild('s2', 'a');
        $this->waitUntilBlocked();
        $this->a->exec("INSERT INTO mission_case_answers (run_id, step_id, choice_id, rating) VALUES ($runId, 's2', 'a', 'acceptable')");
        $this->a->exec("UPDATE mission_case_runs SET score_points = 3, score_max = 4, current_step = 's2', finished_at = NOW() WHERE id = $runId");
        $this->a->commit();

        $reply = $this->childReply();
        $this->assertTrue($reply['ok'], json_encode($reply, JSON_UNESCAPED_UNICODE));
        $this->assertTrue($reply['duplicate']);
        $this->assertTrue($reply['finished'], 'the result carries the run as A left it, finished');
        $this->assertSame(3, $reply['score_points']);
    }
}
