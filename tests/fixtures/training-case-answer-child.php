<?php
/**
 * Child process of TrainingCaseConcurrencyTest: answers one step on a connection of its own (the test process holds
 * the other one) and prints the reply as JSON. Usage: php training-case-answer-child.php <placementId> <userId> <stepId> <choiceId>
 */
require __DIR__ . '/../bootstrap.php';

[, $placementId, $userId, $stepId, $choiceId] = $argv + [null, 0, 0, '', ''];
try {
    echo json_encode(trainingCaseAnswer((int) $placementId, (int) $userId, (string) $stepId, (string) $choiceId, 1234), JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    echo json_encode(['exception' => get_class($e) . ': ' . $e->getMessage()]);
}
