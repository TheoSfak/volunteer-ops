<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The training record carries each drill's case results (count and average percent), and the case scores never reach
 * the attendance hours (they live in mission_case_* tables, the hours in participation_requests).
 */
final class TrainingCaseRecordTest extends TestCase
{
    protected function setUp(): void
    {
        db()->beginTransaction();
    }

    protected function tearDown(): void
    {
        db()->rollBack();
    }

    private function finishedRun(int $missionId, int $userId, int $points, int $max): void
    {
        $placement = (int) dbInsert("INSERT INTO mission_case_placements (mission_id, title, snapshot, audience) VALUES (?, 'T', '{}', 'all')", [$missionId]);
        dbInsert("INSERT INTO mission_case_runs (placement_id, user_id, finished_at, score_points, score_max) VALUES (?, ?, NOW(), ?, ?)", [$placement, $userId, $points, $max]);
    }

    public function test_the_training_record_carries_the_case_results_of_each_drill(): void
    {
        $user = TrainingFixtures::user();
        $drill = TrainingFixtures::mission(true);
        TrainingFixtures::participation($drill['shift'], $user);
        $this->finishedRun($drill['mission'], $user, 7, 8);

        $row = getVolunteerTrainingRecord($user)['rows'][0];
        $this->assertSame(1, $row['cases']);
        $this->assertSame(88, $row['case_percent']);
    }

    public function test_a_drill_without_cases_has_none(): void
    {
        $user = TrainingFixtures::user();
        $drill = TrainingFixtures::mission(true);
        TrainingFixtures::participation($drill['shift'], $user);
        $row = getVolunteerTrainingRecord($user)['rows'][0];
        $this->assertSame(0, $row['cases']);
        $this->assertNull($row['case_percent']);
    }

    public function test_an_unfinished_run_is_not_counted(): void
    {
        $user = TrainingFixtures::user();
        $drill = TrainingFixtures::mission(true);
        TrainingFixtures::participation($drill['shift'], $user);
        $placement = (int) dbInsert("INSERT INTO mission_case_placements (mission_id, title, snapshot, audience) VALUES (?, 'T', '{}', 'all')", [$drill['mission']]);
        dbInsert("INSERT INTO mission_case_runs (placement_id, user_id, score_points, score_max) VALUES (?, ?, 1, 2)", [$placement, $user]);
        $this->assertSame(0, getVolunteerTrainingRecord($user)['rows'][0]['cases']);
    }

    public function test_case_scores_never_reach_the_attendance_hours(): void
    {
        $user = TrainingFixtures::user();
        $drill = TrainingFixtures::mission(true);
        TrainingFixtures::participation($drill['shift'], $user, true, 2.0);
        $before = getVolunteerTrainingRecord($user)['hours'];
        $rowBefore = dbFetchOne("SELECT attended, actual_hours, points_awarded FROM participation_requests WHERE volunteer_id = ?", [$user]);
        $this->finishedRun($drill['mission'], $user, 2, 2);
        $this->assertSame($before, getVolunteerTrainingRecord($user)['hours']);
        $this->assertSame(2.0, getVolunteerTrainingRecord($user)['hours']);
        $this->assertEquals(
            $rowBefore,
            dbFetchOne("SELECT attended, actual_hours, points_awarded FROM participation_requests WHERE volunteer_id = ?", [$user]),
            'the participation row (attendance, hours, points) is exactly as it was recorded'
        );
    }

    public function test_the_record_section_prints_the_line_through_translation_keys(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../includes/training-record-section.php');
        $this->assertStringContainsString("t('case.record.line'", $src);
        $this->assertStringNotContainsString('Περιστατικά:', $src, 'no hard coded text for the new line');
        $lang = loadLangStrings('war-room');
        foreach (['el', 'en'] as $code) {
            $this->assertArrayHasKey('case.record.line', $lang[$code]);
            $this->assertStringContainsString('{cases}', $lang[$code]['case.record.line']);
            $this->assertStringContainsString('{percent}', $lang[$code]['case.record.line']);
        }
    }
}
