<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * The PHP half of the shared triage fixture check.
 *
 * triageEvaluate() / normalizeTriageCardNo() (includes/functions-triage.php)
 * and their twins in assets/js/triage.js are the same logic written twice:
 * the phone asks the START/JumpSTART questions (it has to work with no
 * signal), the server recomputes the colour from the answers. If the two ever
 * disagree, a rescuer is shown one colour and the command post another — so
 * both are pinned to tests/fixtures/triage-cases.json, this file asserting
 * the PHP side and tests/js/triage.test.js the JS side.
 */
final class TriageProtocolTest extends TestCase
{
    private static function fixture(): array
    {
        $fixture = json_decode(file_get_contents(__DIR__ . '/fixtures/triage-cases.json'), true);
        self::assertIsArray($fixture, 'tests/fixtures/triage-cases.json is not readable JSON');
        return $fixture;
    }

    /** @return array<string, array{0: array<mixed>}> */
    public static function protocolProvider(): array
    {
        $cases = [];
        foreach (self::fixture()['protocol_cases'] as $case) {
            $cases[$case['name']] = [$case];
        }
        return $cases;
    }

    /** @return array<string, array{0: array<mixed>}> */
    public static function cardProvider(): array
    {
        $cases = [];
        foreach (self::fixture()['card_cases'] as $case) {
            $cases[$case['name']] = [$case];
        }
        return $cases;
    }

    /**
     * @param array<mixed> $case
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('protocolProvider')]
    public function testProtocolMatchesTheSharedFixture(array $case): void
    {
        $result = triageEvaluate($case['protocol'], $case['answers']);
        if ($case['expect'] === null) {
            $this->assertNull($result);
            return;
        }
        $this->assertNotNull($result);
        $this->assertSame($case['expect']['category'], $result['category']);
        $this->assertSame($case['expect']['reason'], $result['reason']);
        $this->assertSame($case['expect']['path'], $result['path']);
    }

    /** @return array<string, array{0: array<mixed>}> */
    public static function secondaryProvider(): array
    {
        $cases = [];
        foreach (self::fixture()['secondary_cases'] as $case) {
            $cases[$case['name']] = [$case];
        }
        return $cases;
    }

    /**
     * @param array<mixed> $case
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('secondaryProvider')]
    public function testSecondaryScoreMatchesTheSharedFixture(array $case): void
    {
        $result = triageSecondaryScore($case['vitals']);
        if ($case['expect'] === null) {
            $this->assertNull($result);
            return;
        }
        $this->assertNotNull($result);
        $this->assertSame($case['expect']['rts'], $result['rts']);
        $this->assertSame($case['expect']['category'], $result['category']);
    }

    /**
     * @param array<mixed> $case
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('cardProvider')]
    public function testCardNumberMatchesTheSharedFixture(array $case): void
    {
        $this->assertSame($case['expect'], normalizeTriageCardNo($case['raw']));
    }

    /**
     * Every leaf of both trees has to be reachable from the fixture, or a
     * branch could be changed in one language and nobody would notice. Walks
     * the trees and checks each [category, reason] leaf appears in a case.
     */
    public function testFixtureCoversEveryLeaf(): void
    {
        $covered = [];
        foreach (self::fixture()['protocol_cases'] as $case) {
            if ($case['expect'] !== null) {
                $covered[$case['protocol'] . ':' . $case['expect']['category'] . ':' . $case['expect']['reason']] = true;
            }
        }
        foreach (triageProtocols() as $name => $tree) {
            foreach ($tree['nodes'] as $node => $branches) {
                foreach ($branches as $branch) {
                    if (is_array($branch)) {
                        $this->assertArrayHasKey("$name:{$branch[0]}:{$branch[1]}", $covered, "leaf $name/$node -> {$branch[0]} ({$branch[1]}) has no fixture case");
                    }
                }
            }
        }
    }

    /**
     * An assessment queued offline on a phone from before the bleeding step
     * existed has no answer for it. The server takes that as «no» (what the old
     * tree did) instead of refusing the casualty, and the stored path shows the
     * question was not asked. The plain evaluator, which the phone's logic
     * mirrors, still treats the route as incomplete.
     */
    public function testOldOfflineAssessmentWithoutTheBleedingAnswerIsTakenAsNo(): void
    {
        $old = ['walk' => false, 'breathing' => true, 'rr_over_30' => false, 'perfusion' => false, 'obeys' => true];
        $this->assertNull(triageEvaluate('start', $old));
        $result = triageEvaluate('start', $old, true);
        $this->assertSame('yellow', $result['category']);
        $this->assertSame('obeys', $result['reason']);
        $this->assertArrayNotHasKey('bleeding', $result['path']);

        $oldChild = ['walk' => false, 'breathing' => true, 'rr_child' => false, 'pulse' => true, 'avpu' => true];
        $this->assertSame('yellow', triageEvaluate('jumpstart', $oldChild, true)['category']);
    }

    /** The words the secondary-triage screens and history lines use. */
    public function testSecondaryTriageReasonsAreTranslated(): void
    {
        $strings = require __DIR__ . '/../includes/lang/war-room.php';
        foreach (['el', 'en'] as $lang) {
            foreach (['trts', 'secondary_override', 'secondary_manual'] as $reason) {
                $this->assertArrayHasKey('triage.reason.' . $reason, $strings[$lang], "reason $reason ($lang)");
            }
            $this->assertArrayHasKey('triage.err_secondary_unknown', $strings[$lang], "error ($lang)");
        }
    }

    /** The tolerance is for the missing question only, never for other gaps. */
    public function testLegacyToleranceDoesNotExcuseOtherMissingAnswers(): void
    {
        $this->assertNull(triageEvaluate('start', ['walk' => false, 'breathing' => true, 'rr_over_30' => false], true));
        $this->assertNull(triageEvaluate('start', ['walk' => false, 'breathing' => true, 'rr_over_30' => false, 'bleeding' => 'maybe'], true));
    }

    /**
     * Every reason a tree can produce needs words on both languages' screens.
     */
    public function testEveryReasonAndQuestionIsTranslated(): void
    {
        $strings = require __DIR__ . '/../includes/lang/war-room.php';
        foreach (triageProtocols() as $tree) {
            foreach ($tree['nodes'] as $node => $branches) {
                foreach (['el', 'en'] as $lang) {
                    $this->assertArrayHasKey('triage.q.' . $node, $strings[$lang], "question $node ($lang)");
                }
                foreach ($branches as $branch) {
                    if (is_array($branch)) {
                        foreach (['el', 'en'] as $lang) {
                            $this->assertArrayHasKey('triage.reason.' . $branch[1], $strings[$lang], "reason {$branch[1]} ($lang)");
                        }
                    }
                }
            }
        }
    }
}
