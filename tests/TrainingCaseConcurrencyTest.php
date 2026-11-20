<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * trainingCaseAnswer() against a REAL concurrent request: two separate connections, the second one a child PHP process
 * that really blocks on the run row's lock. Like TrainingCaseTransactionTest this works outside the harness's
 * rolled-back transaction (a transaction cannot be shared with another connection), so it COMMITS its fixture rows and
 * deletes them again in tearDown.
 *
 * The child writes to temp FILES, not pipes: reads from a pipe cannot be given a deadline on Windows (no non-blocking
 * pipes, no stream_select on them), and a reply that never comes must fail the test, not hang it.
 */
final class TrainingCaseConcurrencyTest extends TestCase
{
    private const CHILD_DEADLINE_SECONDS = 15;

    private array $drill;
    private int $userId;
    private int $teamId;
    private int $placementId;
    private ?PDO $a = null;
    /** @var resource|null */
    private $proc = null;
    private ?string $outFile = null;
    private ?string $errFile = null;

    protected function setUp(): void
    {
        $this->requireChildProcessSupport();
        $this->requireInnodbTrx();
        $this->assertFalse(db()->inTransaction(), 'this test needs a connection with no transaction open');
        $admin = TrainingFixtures::user('Admin');
        $this->drill = TrainingFixtures::mission(true);
        $this->teamId = (int) dbInsert("INSERT INTO mission_teams (mission_id, codename, team_number, created_by) VALUES (?, 'ΑΛΦΑ', 1, ?)", [$this->drill['mission'], $admin]);
        $this->userId = $this->volunteer();
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
        // The child first: it may be parked on a lock the rollbacks below would release anyway, but a killed child is certain.
        if ($this->proc !== null) {
            @proc_terminate($this->proc);
            @proc_close($this->proc);
            $this->proc = null;
        }
        foreach ([$this->outFile, $this->errFile] as $file) {
            if ($file !== null && is_file($file)) {
                @unlink($file);
            }
        }
        $this->outFile = $this->errFile = null;
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

    /** The child needs a CLI php that can load pdo_mysql: not phpdbg, and not a build/ini without the driver. */
    private function requireChildProcessSupport(): void
    {
        if (PHP_BINARY === '' || stripos(basename(PHP_BINARY), 'phpdbg') !== false) {
            $this->markTestSkipped('the child process needs a php CLI binary; PHP_BINARY is "' . PHP_BINARY . '" (phpdbg cannot run the child script)');
        }
        $probe = proc_open([PHP_BINARY, '-r', 'exit(extension_loaded("pdo_mysql") ? 0 : 7);'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($probe)) {
            $this->markTestSkipped('could not start ' . PHP_BINARY . ' as a child process');
        }
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($probe);
        if ($code !== 0) {
            $this->markTestSkipped('the child php (' . PHP_BINARY . ') cannot load pdo_mysql (exit code ' . $code . ')' . ($out !== '' ? ': ' . trim($out) : ''));
        }
    }

    /** The test tells a child that is waiting on a lock from one that is not by innodb_trx, which needs the PROCESS privilege. */
    private function requireInnodbTrx(): void
    {
        try {
            dbFetchValue("SELECT COUNT(*) FROM information_schema.innodb_trx");
        } catch (Throwable $e) {
            $this->markTestSkipped('information_schema.innodb_trx is not readable by this database user (needs the PROCESS privilege): ' . $e->getMessage());
        }
    }

    /** An approved participant of the drill, in the team that has arrived at the order. */
    private function volunteer(): int
    {
        $userId = TrainingFixtures::user('Member');
        TrainingFixtures::participation($this->drill['shift'], $userId, false, null);
        dbExecute("INSERT INTO mission_team_members (mission_id, team_id, user_id) VALUES (?, ?, ?)", [$this->drill['mission'], $this->teamId, $userId]);
        return $userId;
    }

    /** Starts the child: the same answer, on a connection of its own. */
    private function startChild(string $stepId, string $choiceId, ?int $userId = null): void
    {
        $this->outFile = (string) tempnam(sys_get_temp_dir(), 'tcc');
        $this->errFile = (string) tempnam(sys_get_temp_dir(), 'tce');
        $this->proc = proc_open(
            [PHP_BINARY, __DIR__ . '/fixtures/training-case-answer-child.php', (string) $this->placementId, (string) ($userId ?? $this->userId), $stepId, $choiceId],
            [1 => ['file', $this->outFile, 'w'], 2 => ['file', $this->errFile, 'w']],
            $pipes
        );
        $this->assertIsResource($this->proc);
    }

    private function childRunning(): bool
    {
        return $this->proc !== null && (proc_get_status($this->proc)['running'] ?? false);
    }

    private function childOutput(): string
    {
        return (string) @file_get_contents((string) $this->outFile) . (string) @file_get_contents((string) $this->errFile);
    }

    /** The MySQL connection id the child announced, waiting (bounded) for it. */
    private function childConnectionId(): int
    {
        $deadline = microtime(true) + self::CHILD_DEADLINE_SECONDS;
        do {
            if (preg_match('/^CONN (\d+)\n/', (string) @file_get_contents((string) $this->outFile), $m)) {
                return (int) $m[1];
            }
            if (!$this->childRunning()) {
                break;
            }
            usleep(50000);
        } while (microtime(true) < $deadline);
        $this->fail('the child never opened its connection; it printed: ' . $this->childOutput());
    }

    /** Waits until THE CHILD'S OWN transaction is blocked on a lock (not just any transaction), up to ~10 s. */
    private function waitUntilBlocked(): void
    {
        $connection = $this->childConnectionId();
        for ($i = 0; $i < 100; $i++) {
            if ((int) dbFetchValue("SELECT COUNT(*) FROM information_schema.innodb_trx WHERE trx_mysql_thread_id = ? AND trx_state = 'LOCK WAIT'", [$connection]) > 0) {
                return;
            }
            if (!$this->childRunning()) {
                $this->fail('the child finished without ever waiting on the run lock; it printed: ' . $this->childOutput());
            }
            usleep(100000);
        }
        $this->fail('the child (connection ' . $connection . ') never blocked on the run lock');
    }

    /** The child's reply, waiting (bounded): a child that does not finish is killed and fails the test. */
    private function childReply(string $whenStuck = 'the child did not finish'): array
    {
        $deadline = microtime(true) + self::CHILD_DEADLINE_SECONDS;
        while ($this->childRunning() && microtime(true) < $deadline) {
            usleep(50000);
        }
        if ($this->childRunning()) {
            $printed = $this->childOutput();
            @proc_terminate($this->proc);
            @proc_close($this->proc);
            $this->proc = null;
            $this->fail($whenStuck . ' within ' . self::CHILD_DEADLINE_SECONDS . ' s (killed); it printed: ' . $printed);
        }
        proc_close($this->proc);
        $this->proc = null;
        $all = $this->childOutput();
        $out = (string) preg_replace('/^CONN \d+\n/', '', (string) @file_get_contents((string) $this->outFile));
        $reply = json_decode($out, true);
        $this->assertIsArray($reply, 'the child printed JSON, got: ' . $all);
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

    /**
     * Two DIFFERENT runs of the same placement answering at once, in the same gap of the unique index (run_id, step_id):
     * existing answers (R1,s1) and (R3,s1); R1 answers s2 and R2 answers s1, both new rows in the gap between them. A
     * locking read of a row that is not there would take a gap lock in each, and each insert would then wait on the
     * other's gap lock (deadlock 1213). This is driven deterministically: R1's request is run by the real function
     * inside an outer transaction that is left OPEN (it holds every lock it took), and the child answers R2 meanwhile -
     * it must go through without waiting for R1 to commit. With a gap lock left behind by R1 it would be stuck.
     */
    public function test_two_runs_answering_in_the_same_index_gap_do_not_block_each_other(): void
    {
        $r1 = $this->userId;
        $r2 = $this->volunteer();
        $r3 = $this->volunteer();
        foreach ([$r1, $r2, $r3] as $user) {
            $this->assertTrue(trainingCaseStartRun($this->placementId, $user)['ok']);
        }
        $this->assertTrue(trainingCaseAnswer($this->placementId, $r1, 's1', 'a')['ok']);
        $this->assertTrue(trainingCaseAnswer($this->placementId, $r3, 's1', 'a')['ok']);
        $ids = array_map(
            fn(int $user): int => (int) dbFetchValue("SELECT id FROM mission_case_runs WHERE placement_id = ? AND user_id = ?", [$this->placementId, $user]),
            [$r1, $r2, $r3]
        );
        $this->assertTrue($ids[0] < $ids[1] && $ids[1] < $ids[2], 'R2 sorts between R1 and R3 in the index: ' . implode(',', $ids));

        // R1's request, still open: it has answered s2 (a new row right after (R1,s1)) and not committed.
        db()->beginTransaction();
        $first = trainingCaseAnswer($this->placementId, $r1, 's2', 'a');
        $this->assertTrue($first['ok'], json_encode($first, JSON_UNESCAPED_UNICODE));
        $this->assertFalse($first['duplicate']);
        $this->assertTrue(db()->inTransaction(), 'the function joined the open transaction and did not commit it');

        // R2's request, on its own connection, while R1's locks are still held.
        $this->startChild('s1', 'a', $r2);
        $reply = $this->childReply('the child answering another run is blocked behind the open request (a gap lock on the answers index?)');
        $this->assertArrayNotHasKey('exception', $reply, 'no deadlock (1213) or lock timeout: ' . json_encode($reply, JSON_UNESCAPED_UNICODE));
        $this->assertTrue($reply['ok'], json_encode($reply, JSON_UNESCAPED_UNICODE));
        $this->assertFalse($reply['duplicate']);
        $this->assertSame('s2', $reply['next']);

        db()->commit();
        $this->assertSame(4, (int) dbFetchValue("SELECT COUNT(*) FROM mission_case_answers WHERE run_id IN (?, ?, ?)", $ids), 'R1 two answers, R2 and R3 one each');
    }
}
