<?php
/**
 * Child process of TrainingCaseConcurrencyTest: answers one step on a connection of its own (the test process holds
 * the other one). It prints two lines: "CONN <connection id>" as soon as its connection is open (the test matches that id
 * against information_schema.innodb_trx to know THIS child is the one waiting on a lock), then the reply as JSON.
 * Usage: php training-case-answer-child.php <placementId> <userId> <stepId> <choiceId>
 */
require __DIR__ . '/../bootstrap.php';

[, $placementId, $userId, $stepId, $choiceId] = $argv + [null, 0, 0, '', ''];
try {
    echo 'CONN ' . (int) dbFetchValue("SELECT CONNECTION_ID()") . "\n";
    flush();
    // The connection is shared by every db*() helper, so the id printed above is the one the answer runs on.
    echo json_encode(trainingCaseAnswer((int) $placementId, (int) $userId, (string) $stepId, (string) $choiceId, 1234), JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['exception' => get_class($e) . ': ' . $e->getMessage()]);
}
