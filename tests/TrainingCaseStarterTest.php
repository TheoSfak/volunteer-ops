<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class TrainingCaseStarterTest extends TestCase
{
    protected function setUp(): void
    {
        db()->beginTransaction();
    }

    protected function tearDown(): void
    {
        db()->rollBack();
    }

    public function test_every_starter_file_is_a_valid_case(): void
    {
        $files = glob(__DIR__ . '/../includes/training-cases/*.json');
        $this->assertCount(3, $files);
        foreach ($files as $file) {
            $entry = json_decode((string) file_get_contents($file), true);
            $this->assertIsArray($entry, basename($file));
            $doc = trainingCaseNormalize($entry['document']);
            $this->assertSame([], trainingCaseValidate($doc), basename($file) . ' must be a valid case');
            $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', $entry['slug']);
            $this->assertGreaterThanOrEqual(4, count($doc['steps']), basename($file) . ' should have several decisions');
        }
    }

    public function test_seeding_is_idempotent_and_marks_starters_unreviewed(): void
    {
        $first = trainingCaseSeedStarters();
        $this->assertSame(3, $first);
        $this->assertSame(0, trainingCaseSeedStarters(), 'a second run changes nothing');
        $this->assertSame(3, (int) dbFetchValue("SELECT COUNT(*) FROM training_cases WHERE is_starter = 1 AND reviewed = 0"));
    }

    public function test_seeding_resets_review_when_a_starter_changes_but_not_otherwise(): void
    {
        trainingCaseSeedStarters();
        $id = (int) dbFetchValue("SELECT id FROM training_cases WHERE is_starter = 1 ORDER BY id LIMIT 1");
        dbExecute("UPDATE training_cases SET reviewed = 1 WHERE id = ?", [$id]);
        $this->assertSame(0, trainingCaseSeedStarters());
        $this->assertSame(1, (int) dbFetchValue("SELECT reviewed FROM training_cases WHERE id = ?", [$id]), 'unchanged content keeps its review');
        dbExecute("UPDATE training_cases SET document = REPLACE(document, 'story', 'stor_') WHERE id = ?", [$id]);
        $this->assertSame(1, trainingCaseSeedStarters(), 'the file wins again');
        $this->assertSame(0, (int) dbFetchValue("SELECT reviewed FROM training_cases WHERE id = ?", [$id]));
    }

    public function test_a_starter_is_never_archived_by_seeding_and_user_cases_are_left_alone(): void
    {
        $user = TrainingFixtures::user();
        $mine = trainingCaseSave(null, [], json_decode((string) file_get_contents(glob(__DIR__ . '/../includes/training-cases/*.json')[0]), true)['document'], $user);
        trainingCaseSeedStarters();
        $this->assertSame(0, (int) dbFetchValue("SELECT is_starter FROM training_cases WHERE id = ?", [$mine['id']]));
    }

    public function test_draw_skips_unreviewed_unless_allowed_and_prefers_fresh_cases(): void
    {
        trainingCaseSeedStarters();
        $this->assertNull(trainingCaseDraw([], [], false), 'every starter is unreviewed');
        $any = trainingCaseDraw([], [], true);
        $this->assertNotNull($any);

        $ids = array_map('intval', array_column(dbFetchAll("SELECT id FROM training_cases WHERE is_starter = 1"), 'id'));
        $others = array_slice(array_diff($ids, [(int) $any['id']]), 0, 2);
        for ($i = 0; $i < 20; $i++) {
            $draw = trainingCaseDraw([], $others, true);
            $this->assertNotContains((int) $draw['id'], $others, 'cases already staged are avoided while others remain');
        }
        // When everything is excluded the draw falls back to the whole pool rather than failing.
        $this->assertNotNull(trainingCaseDraw([], $ids, true));
    }

    public function test_draw_honours_the_filters_and_ignores_archived_cases(): void
    {
        trainingCaseSeedStarters();
        dbExecute("UPDATE training_cases SET reviewed = 1");
        $this->assertNull(trainingCaseDraw(['language' => 'en'], [], false));
        $row = dbFetchOne("SELECT id, type_tag FROM training_cases WHERE is_starter = 1 LIMIT 1");
        $draw = trainingCaseDraw(['type' => $row['type_tag']], [], false);
        $this->assertSame($row['type_tag'], $draw['type_tag']);
        dbExecute("UPDATE training_cases SET archived_at = NOW()");
        $this->assertNull(trainingCaseDraw([], [], false));
    }
}
