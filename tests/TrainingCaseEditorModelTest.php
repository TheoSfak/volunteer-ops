<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The editor re-draws the form from what the admin posted when a save is refused, so the model must be built from raw
 * (unvalidated, untrusted) JSON without losing what was typed and without ever failing on a odd shape.
 */
final class TrainingCaseEditorModelTest extends TestCase
{
    private function raw(): array
    {
        return [
            'title' => 'T', 'story' => 'S', 'closing' => 'C',
            'findings' => [['label' => 'L', 'value' => 'V', 'unit' => 'u']],
            'start' => 's2',
            'steps' => [
                's1' => ['prompt' => 'P1', 'hint' => 'h', 'choices' => [
                    ['id' => 'a', 'label' => 'A', 'rating' => 'best', 'feedback' => 'F', 'next' => null],
                    ['id' => 'b', 'label' => 'B', 'rating' => 'harmful', 'feedback' => 'G', 'next' => 's9']]],
                's2' => ['prompt' => 'P2', 'hint' => '', 'choices' => []],
            ],
        ];
    }

    public function test_the_start_step_comes_first_and_the_shape_is_a_list(): void
    {
        $m = trainingCaseEditorModel($this->raw());
        $this->assertSame(['s2', 's1'], array_column($m['steps'], 'id'));
        $this->assertSame('P1', $m['steps'][1]['prompt']);
        $this->assertSame('', $m['steps'][1]['choices'][0]['next'], 'a null next reads as the end');
        $this->assertSame('s9', $m['steps'][1]['choices'][1]['next'], 'a next that points nowhere is kept: the save says so');
        $this->assertSame([['label' => 'L', 'value' => 'V', 'unit' => 'u']], $m['findings']);
    }

    public function test_text_that_failed_validation_is_kept_as_typed_not_stripped(): void
    {
        $raw = $this->raw();
        $raw['story'] = 'a <b>bold</b> <a href="';
        $m = trainingCaseEditorModel($raw);
        $this->assertSame('a <b>bold</b> <a href="', $m['story'], 'it is escaped when printed and set with textContent in the page, never interpreted');
    }

    public function test_odd_shapes_never_fail(): void
    {
        $this->assertSame([], trainingCaseEditorModel(null)['steps']);
        $this->assertSame([], trainingCaseEditorModel('x')['steps']);
        $m = trainingCaseEditorModel([
            'title' => ['array'], 'story' => 5, 'findings' => 'no', 'steps' => [
                's1' => 'not an array',
                '9bad' => ['prompt' => 'x', 'choices' => 'no'],
                's2' => ['prompt' => ['x'], 'choices' => ['str', ['id' => ['a'], 'label' => 3, 'rating' => ['x'], 'next' => ['y']]]],
            ],
        ]);
        $this->assertSame('', $m['title']);
        $this->assertSame('5', $m['story']);
        $this->assertSame([], $m['findings']);
        $this->assertSame(['s2'], array_column($m['steps'], 'id'), 'only a step with a usable id and an array body survives');
        $this->assertSame('', $m['steps'][0]['prompt']);
        $this->assertCount(1, $m['steps'][0]['choices']);
        $this->assertSame('3', $m['steps'][0]['choices'][0]['label']);
        $this->assertSame('', $m['steps'][0]['choices'][0]['id']);
    }

    public function test_what_is_drawn_is_capped(): void
    {
        $steps = [];
        for ($i = 1; $i <= 40; $i++) {
            $steps['s' . $i] = ['prompt' => str_repeat('α', 900), 'choices' => array_fill(0, 12, ['id' => 'a', 'label' => 'x', 'rating' => 'best', 'feedback' => 'f', 'next' => null])];
        }
        $m = trainingCaseEditorModel(['title' => str_repeat('τ', 500), 'findings' => array_fill(0, 60, ['label' => 'a', 'value' => 'b']), 'steps' => $steps]);
        $this->assertCount(30, $m['steps']);
        $this->assertCount(8, $m['steps'][0]['choices']);
        $this->assertSame(400, mb_strlen($m['steps'][0]['prompt']));
        $this->assertSame(160, mb_strlen($m['title']));
        $this->assertCount(24, $m['findings']);
    }

    public function test_the_model_survives_json_in_a_page_attribute(): void
    {
        $m = trainingCaseEditorModel($this->raw());
        $this->assertSame($m, json_decode((string) json_encode($m, JSON_UNESCAPED_UNICODE), true));
    }

    public function test_a_document_the_library_stored_round_trips_into_a_model_and_back_to_a_valid_document(): void
    {
        $file = json_decode((string) file_get_contents(__DIR__ . '/../includes/training-cases/starter-chest-pain.json'), true);
        $doc = trainingCaseNormalize($file['document']);
        $this->assertSame([], trainingCaseValidate($doc));
        $m = trainingCaseEditorModel($doc);
        $this->assertSame($doc['start'], $m['steps'][0]['id']);
        $this->assertCount(count($doc['steps']), $m['steps']);
    }

    public function test_every_save_error_code_has_text_in_greek_and_english(): void
    {
        foreach (['not_found', 'unreadable', 'too_big'] as $code) {
            foreach (['el', 'en'] as $lang) {
                $this->assertNotSame('case.err.' . $code, t('case.err.' . $code, [], $lang), "case.err.{$code} has no {$lang} text");
            }
        }
    }

    public function test_the_error_text_helper_covers_the_codes_the_save_returns(): void
    {
        $this->assertNotSame('case.err.not_found', trainingCaseErrorText(['code' => 'not_found', 'where' => '']));
    }
}
