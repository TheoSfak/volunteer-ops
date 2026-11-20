<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class TrainingMissionHelpersTest extends TestCase
{
    protected function setUp(): void
    {
        db()->beginTransaction();
    }

    protected function tearDown(): void
    {
        db()->rollBack();
    }

    public function test_isTrainingMission_reads_a_row_or_an_id(): void
    {
        $drill = TrainingFixtures::mission(true)['mission'];
        $real = TrainingFixtures::mission(false)['mission'];

        $this->assertTrue(isTrainingMission($drill));
        $this->assertFalse(isTrainingMission($real));
        $this->assertTrue(isTrainingMission(['is_training' => 1]));
        $this->assertFalse(isTrainingMission(['is_training' => '0']));
        $this->assertTrue(isTrainingMission(['id' => $drill]));
        $this->assertFalse(isTrainingMission(0));
        $this->assertFalse(isTrainingMission(999999999));
    }

    public function test_flag_is_taken_from_the_form_only_for_a_new_mission(): void
    {
        $this->assertSame(1, trainingMissionFlagForSave(null, ['is_training' => '1']));
        $this->assertSame(0, trainingMissionFlagForSave(null, []));
        // An existing mission keeps what it was created with, whatever is posted.
        $this->assertSame(0, trainingMissionFlagForSave(['is_training' => 0], ['is_training' => '1']));
        $this->assertSame(1, trainingMissionFlagForSave(['is_training' => 1], []));
    }

    public function test_prefix_and_badge(): void
    {
        $this->assertSame('[ΑΣΚΗΣΗ] ', trainingTitlePrefix(['is_training' => 1]));
        $this->assertSame('', trainingTitlePrefix(['is_training' => 0]));
        $this->assertStringContainsString('ΑΣΚΗΣΗ', trainingMissionBadgeHtml(['is_training' => 1]));
        $this->assertSame('', trainingMissionBadgeHtml(['is_training' => 0]));
    }

    public function test_training_record_counts_only_attended_training_rows(): void
    {
        $user = TrainingFixtures::user();
        $drillA = TrainingFixtures::mission(true);
        $drillB = TrainingFixtures::mission(true);
        $drillNoShow = TrainingFixtures::mission(true);
        $real = TrainingFixtures::mission(false);

        TrainingFixtures::participation($drillA['shift'], $user, true, 2.5);
        TrainingFixtures::participation($drillB['shift'], $user, true, 1.0);
        TrainingFixtures::participation($drillNoShow['shift'], $user, false, null);
        TrainingFixtures::participation($real['shift'], $user, true, 8.0);

        $record = getVolunteerTrainingRecord($user);

        $this->assertSame(2, $record['drills']);
        $this->assertEqualsWithDelta(3.5, $record['hours'], 0.001);
        $this->assertCount(2, $record['rows']);
        $this->assertNull($record['rows'][0]['certificate_id']);
    }

    public function test_training_record_links_the_issued_certificate(): void
    {
        $user = TrainingFixtures::user();
        $drill = TrainingFixtures::mission(true, STATUS_COMPLETED);
        TrainingFixtures::participation($drill['shift'], $user, true, 2.0);
        $certId = (int) dbInsert(
            "INSERT INTO mission_certificates (mission_id, recipient_user_id, language, certificate_number) VALUES (?, ?, 'el', ?)",
            [$drill['mission'], $user, 'TRN-' . uniqid()]
        );

        $record = getVolunteerTrainingRecord($user);

        $this->assertSame($certId, $record['rows'][0]['certificate_id']);
    }

    public function test_training_hours_for_a_mission(): void
    {
        $user = TrainingFixtures::user();
        $drill = TrainingFixtures::mission(true);
        TrainingFixtures::participation($drill['shift'], $user, true, 4.0);

        $this->assertEqualsWithDelta(4.0, getTrainingHoursForMission($drill['mission'], $user), 0.001);
        $this->assertSame(0.0, getTrainingHoursForMission($drill['mission'], TrainingFixtures::user('Other')));
    }
}
