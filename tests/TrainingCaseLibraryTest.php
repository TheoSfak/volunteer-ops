<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class TrainingCaseLibraryTest extends TestCase
{
    private int $admin;

    protected function setUp(): void
    {
        db()->beginTransaction();
        $this->admin = TrainingFixtures::user('Case Admin');
    }

    protected function tearDown(): void
    {
        db()->rollBack();
    }

    private function doc(string $title = 'Περιστατικό'): array
    {
        return [
            'title' => $title, 'story' => 'Ιστορία.', 'closing' => '', 'findings' => [], 'start' => 's1',
            'steps' => ['s1' => ['prompt' => 'Τι κάνετε;', 'hint' => '', 'choices' => [
                ['id' => 'a', 'label' => 'Α', 'rating' => 'best', 'feedback' => 'Ναι.', 'next' => null],
                ['id' => 'b', 'label' => 'Β', 'rating' => 'harmful', 'feedback' => 'Όχι.', 'next' => null],
            ]]],
        ];
    }

    public function test_save_then_load_round_trips_and_slugs_are_unique(): void
    {
        $a = trainingCaseSave(null, ['type_tag' => 'trauma', 'difficulty' => 2, 'language' => 'el', 'est_minutes' => 6], $this->doc('Ίδιος τίτλος'), $this->admin);
        $b = trainingCaseSave(null, [], $this->doc('Ίδιος τίτλος'), $this->admin);
        $this->assertTrue($a['ok']);
        $this->assertTrue($b['ok']);
        $rowA = trainingCaseLoad($a['id']);
        $rowB = trainingCaseLoad($b['id']);
        $this->assertSame('Ίδιος τίτλος', $rowA['doc']['title']);
        $this->assertSame('trauma', $rowA['type_tag']);
        $this->assertSame(2, (int) $rowA['difficulty']);
        $this->assertNotSame($rowA['slug'], $rowB['slug']);
    }

    public function test_meta_is_clamped(): void
    {
        $meta = trainingCaseMeta(['type_tag' => 'bogus', 'difficulty' => 9, 'language' => 'fr', 'est_minutes' => 0]);
        $this->assertSame(['type_tag' => 'other', 'difficulty' => 3, 'language' => 'el', 'est_minutes' => 1], $meta);
    }

    public function test_an_invalid_case_is_refused_with_errors_and_nothing_is_written(): void
    {
        $bad = $this->doc();
        $bad['steps']['s1']['choices'][0]['rating'] = 'acceptable';
        $before = (int) dbFetchValue("SELECT COUNT(*) FROM training_cases");
        $result = trainingCaseSave(null, [], $bad, $this->admin);
        $this->assertFalse($result['ok']);
        $this->assertContains('no_best', array_column($result['errors'], 'code'));
        $this->assertSame($before, (int) dbFetchValue("SELECT COUNT(*) FROM training_cases"));
    }

    public function test_a_case_can_be_edited_and_so_can_a_starter(): void
    {
        $saved = trainingCaseSave(null, [], $this->doc(), $this->admin);
        $this->assertTrue(trainingCaseSave($saved['id'], [], $this->doc('Νέος'), $this->admin)['ok']);
        $this->assertSame('Νέος', trainingCaseLoad($saved['id'])['title']);

        dbExecute("UPDATE training_cases SET is_starter = 1 WHERE id = ?", [$saved['id']]);
        $edited = trainingCaseSave($saved['id'], ['difficulty' => 3], $this->doc('Άλλος'), $this->admin);
        $this->assertTrue($edited['ok'], 'a starter is edited in place like any other case');
        $row = trainingCaseLoad($saved['id']);
        $this->assertSame('Άλλος', $row['title']);
        $this->assertSame(3, (int) $row['difficulty']);
        $this->assertSame(1, (int) $row['is_starter'], 'editing keeps the «came with the app» mark');
        $this->assertFalse(trainingCaseSave(999999, [], $this->doc(), $this->admin)['ok'], 'a case that does not exist is still refused');
    }

    public function test_duplicate_makes_an_editable_non_starter_copy(): void
    {
        $saved = trainingCaseSave(null, [], $this->doc('Πρωτότυπο'), $this->admin);
        dbExecute("UPDATE training_cases SET is_starter = 1 WHERE id = ?", [$saved['id']]);
        $copy = trainingCaseDuplicate($saved['id'], $this->admin);
        $row = trainingCaseLoad($copy['id']);
        $this->assertSame(0, (int) $row['is_starter']);
        $this->assertStringContainsString('Πρωτότυπο', $row['doc']['title']);
    }

    public function test_delete_removes_a_normal_case_and_a_starter_and_reports_a_missing_one(): void
    {
        $normal = trainingCaseSave(null, [], $this->doc('Κανονικό'), $this->admin);
        $starter = trainingCaseSave(null, [], $this->doc('Εκκίνησης'), $this->admin);
        dbExecute("UPDATE training_cases SET is_starter = 1 WHERE id = ?", [$starter['id']]);

        $this->assertTrue(trainingCaseDelete($normal['id']));
        $this->assertTrue(trainingCaseDelete($starter['id']), 'a starter is deleted like any other case');
        $this->assertNull(trainingCaseLoad($normal['id']));
        $this->assertNull(trainingCaseLoad($starter['id']));
        $this->assertFalse(trainingCaseDelete($normal['id']), 'nothing left to delete');
        $this->assertFalse(trainingCaseDelete(999999));
    }

    public function test_archive_hides_a_case_from_the_default_list(): void
    {
        $saved = trainingCaseSave(null, [], $this->doc(), $this->admin);
        trainingCaseArchive($saved['id'], true);
        $ids = array_map('intval', array_column(trainingCaseList(), 'id'));
        $this->assertNotContains($saved['id'], $ids);
        $all = array_map('intval', array_column(trainingCaseList(['include_archived' => true]), 'id'));
        $this->assertContains($saved['id'], $all);
        trainingCaseArchive($saved['id'], false);
        $this->assertContains($saved['id'], array_map('intval', array_column(trainingCaseList(), 'id')));
    }

    public function test_export_import_round_trip_renames_on_a_slug_clash(): void
    {
        $saved = trainingCaseSave(null, ['type_tag' => 'cardiac'], $this->doc('Εξαγώγιμο'), $this->admin);
        $json = trainingCaseExport([$saved['id']]);
        $pack = json_decode($json, true);
        $this->assertSame(TRAINING_CASE_FORMAT_VERSION, $pack['format_version']);
        $this->assertCount(1, $pack['cases']);

        $preview = trainingCaseImportRead($json);
        $this->assertTrue($preview['ok']);
        $this->assertTrue($preview['cases'][0]['clash'], 'the slug is already in the library');
        $this->assertSame([], $preview['cases'][0]['errors']);

        $result = trainingCaseImport($json, $this->admin);
        $this->assertSame(1, $result['saved']);
        $copy = trainingCaseLoad($result['ids'][0]);
        $this->assertNotSame(trainingCaseLoad($saved['id'])['slug'], $copy['slug']);
        $this->assertSame('cardiac', $copy['type_tag']);
    }

    public function test_import_refuses_garbage_oversize_and_newer_formats(): void
    {
        $this->assertFalse(trainingCaseImportRead('not json')['ok']);
        $this->assertFalse(trainingCaseImportRead(str_repeat('x', TRAINING_CASE_MAX_IMPORT_BYTES + 1))['ok']);
        $newer = json_encode(['format' => 'volunteerops-training-cases', 'format_version' => 99, 'cases' => []]);
        $this->assertFalse(trainingCaseImportRead($newer)['ok']);
    }

    public function test_import_skips_invalid_cases_and_ignores_unknown_keys(): void
    {
        $good = ['slug' => 'imp-good', 'type_tag' => 'neuro', 'difficulty' => 1, 'language' => 'el', 'est_minutes' => 4, 'document' => $this->doc('Καλό'), 'surprise' => 'ignored'];
        $badDoc = $this->doc('Κακό');
        $badDoc['start'] = 'ghost';
        $bad = ['slug' => 'imp-bad', 'document' => $badDoc];
        $json = json_encode(['format' => 'volunteerops-training-cases', 'format_version' => 1, 'cases' => [$good, $bad]]);
        $result = trainingCaseImport($json, $this->admin);
        $this->assertSame(1, $result['saved']);
        $this->assertSame(1, $result['skipped']);
    }

    public function test_plain_text_stays_fast_on_nested_angle_brackets(): void
    {
        $nasty = str_repeat('<', 100000) . str_repeat('b>', 50000);
        $this->assertLessThanOrEqual(200000, strlen($nasty));

        $start = microtime(true);
        $plain = trainingCasePlain($nasty, 1500);
        $this->assertLessThan(2.0, microtime(true) - $start, 'trainingCasePlain must not go quadratic on its input');
        $this->assertLessThanOrEqual(1500, mb_strlen($plain));

        $doc = $this->doc('Εχθρικό');
        $doc['story'] = str_repeat('<', 100000) . str_repeat('b>', 45000);
        $json = json_encode(['format' => TRAINING_CASE_PACK_FORMAT, 'format_version' => 1, 'cases' => [['slug' => 'nasty', 'document' => $doc]]]);
        $this->assertLessThanOrEqual(TRAINING_CASE_MAX_IMPORT_BYTES, strlen($json));
        $start = microtime(true);
        $read = trainingCaseImportRead($json);
        $this->assertLessThan(2.0, microtime(true) - $start, 'import must not go quadratic on a hostile file');
        $this->assertTrue($read['ok']);
    }

    public function test_plain_text_keeps_thresholds_and_strips_real_tags_after_the_bound(): void
    {
        $this->assertSame('SpO2 <90 και SBP <50', trainingCasePlain('SpO2 <90 και SBP <50', 100));
        $this->assertSame('x', trainingCasePlain('<b>x</b>', 100));
        $long = str_repeat('a', 300) . '<i>b</i>';
        $this->assertSame(str_repeat('a', 300) . 'b', trainingCasePlain($long, 400));
    }

    public function test_import_size_is_checked_before_any_normalising(): void
    {
        $read = trainingCaseImportRead(str_repeat('<', TRAINING_CASE_MAX_IMPORT_BYTES + 1));
        $this->assertFalse($read['ok']);
        $this->assertSame('case.import_too_big', $read['error']);
    }
}
