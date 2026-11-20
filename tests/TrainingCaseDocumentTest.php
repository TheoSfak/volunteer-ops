<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** The case document: normalising, the rules every case must meet, and scoring. */
final class TrainingCaseDocumentTest extends TestCase
{
    /** A small valid two-step case; tests break one thing at a time. */
    private function doc(): array
    {
        return [
            'title' => 'Αναίσθητος άνδρας',
            'story' => 'Βρίσκετε έναν άνδρα 40 ετών αναίσθητο.',
            'closing' => 'Ασφαλής αεραγωγός πρώτα.',
            'findings' => [['label' => 'Σφυγμοί', 'value' => '140', 'unit' => '/λεπτό']],
            'start' => 's1',
            'steps' => [
                's1' => ['prompt' => 'Τι κάνετε πρώτα;', 'hint' => '', 'choices' => [
                    ['id' => 'a', 'label' => 'Ελέγχω αεραγωγό', 'rating' => 'best', 'feedback' => 'Σωστά.', 'next' => 's2'],
                    ['id' => 'b', 'label' => 'Τον μετακινώ', 'rating' => 'harmful', 'feedback' => 'Κίνδυνος.', 'next' => 's2'],
                ]],
                's2' => ['prompt' => 'Και μετά;', 'hint' => '', 'choices' => [
                    ['id' => 'a', 'label' => 'Καλώ ασθενοφόρο', 'rating' => 'best', 'feedback' => 'Σωστά.', 'next' => null],
                    ['id' => 'b', 'label' => 'Περιμένω', 'rating' => 'acceptable', 'feedback' => 'Αργά.', 'next' => null],
                ]],
            ],
        ];
    }

    private function codes(array $doc): array
    {
        return array_column(trainingCaseValidate($doc), 'code');
    }

    public function test_a_sound_case_has_no_errors(): void
    {
        $this->assertSame([], trainingCaseValidate($this->doc()));
    }

    public function test_normalize_strips_markup_and_unknown_keys_and_keeps_order(): void
    {
        $raw = $this->doc();
        $raw['title'] = '<b>Τίτλος</b>';
        $raw['evil'] = 'x';
        $raw['steps']['s1']['choices'][0]['label'] = '<script>alert(1)</script>Ελέγχω';
        $doc = trainingCaseNormalize($raw);
        $this->assertSame('Τίτλος', $doc['title']);
        $this->assertArrayNotHasKey('evil', $doc);
        $this->assertSame('alert(1)Ελέγχω', $doc['steps']['s1']['choices'][0]['label']);
        $this->assertSame(['s1', 's2'], array_keys($doc['steps']));
    }

    public function test_normalize_rejects_a_non_array(): void
    {
        $this->assertNull(trainingCaseNormalize('nope'));
    }

    public function test_ids_must_start_with_a_letter(): void
    {
        $this->assertSame('', trainingCaseId('1'));
        $this->assertSame('', trainingCaseId('Has Space'));
        $this->assertSame('s1', trainingCaseId(' S1 '));
    }

    public function test_a_step_needs_two_to_five_choices(): void
    {
        $doc = $this->doc();
        $doc['steps']['s2']['choices'] = [$doc['steps']['s2']['choices'][0]];
        $this->assertContains('choice_count', $this->codes($doc));
    }

    public function test_a_step_needs_a_best_choice(): void
    {
        $doc = $this->doc();
        $doc['steps']['s2']['choices'][0]['rating'] = 'acceptable';
        $this->assertContains('no_best', $this->codes($doc));
    }

    public function test_a_next_must_point_at_an_existing_step(): void
    {
        $doc = $this->doc();
        $doc['steps']['s1']['choices'][0]['next'] = 'ghost';
        $this->assertContains('next_missing', $this->codes($doc));
    }

    public function test_a_loop_is_refused(): void
    {
        $doc = $this->doc();
        $doc['steps']['s2']['choices'][1]['next'] = 's1';
        $this->assertContains('loop', $this->codes($doc));
    }

    public function test_an_unreachable_step_is_refused(): void
    {
        $doc = $this->doc();
        $doc['steps']['s3'] = $doc['steps']['s2'];
        $this->assertContains('unreachable', $this->codes($doc));
    }

    public function test_the_start_step_must_exist(): void
    {
        $doc = $this->doc();
        $doc['start'] = 'nowhere';
        $this->assertContains('start_missing', $this->codes($doc));
    }

    public function test_an_unknown_rating_is_refused(): void
    {
        $doc = $this->doc();
        $doc['steps']['s1']['choices'][0]['rating'] = 'great';
        $this->assertContains('rating_invalid', $this->codes($doc));
    }

    public function test_a_document_over_the_size_limit_is_refused(): void
    {
        // Twelve steps of five maximum-length choices is far past 24000 bytes of Greek text.
        $steps = [];
        for ($i = 1; $i <= 12; $i++) {
            $choices = [];
            foreach (['a', 'b', 'c', 'd', 'e'] as $k => $id) {
                $choices[] = ['id' => $id, 'label' => str_repeat('α', 200), 'rating' => $k === 0 ? 'best' : 'acceptable',
                              'feedback' => str_repeat('β', 600), 'next' => $i < 12 ? 's' . ($i + 1) : null];
            }
            $steps['s' . $i] = ['prompt' => str_repeat('γ', 400), 'hint' => '', 'choices' => $choices];
        }
        $doc = trainingCaseNormalize(['title' => 'T', 'story' => 'S', 'closing' => '', 'findings' => [], 'start' => 's1', 'steps' => $steps]);
        $this->assertContains('too_big', $this->codes($doc));
        $this->assertNotContains('too_big', $this->codes($this->doc()), 'a normal case is far under the limit');
    }

    public function test_every_error_code_has_a_greek_text(): void
    {
        foreach (['title_missing', 'story_missing', 'no_steps', 'too_many_steps', 'start_missing', 'prompt_missing', 'choice_count',
                  'choice_id', 'rating_invalid', 'choice_label_missing', 'feedback_missing', 'next_missing', 'no_best', 'loop', 'unreachable', 'too_big', 'unreadable'] as $code) {
            $text = trainingCaseErrorText(['code' => $code, 'where' => 's1']);
            $this->assertNotSame('case.err.' . $code, $text, "case.err.{$code} has no text");
        }
    }

    public function test_scoring_points_and_labels(): void
    {
        $this->assertSame(2, trainingCaseRatingPoints('best'));
        $this->assertSame(1, trainingCaseRatingPoints('acceptable'));
        $this->assertSame(0, trainingCaseRatingPoints('harmful'));
        $this->assertSame(0, trainingCaseRatingPoints('nonsense'));
        $this->assertSame('excellent', trainingCaseScoreLabel(8, 10));
        $this->assertSame('good', trainingCaseScoreLabel(5, 10));
        $this->assertSame('practice', trainingCaseScoreLabel(4, 10));
        $this->assertSame('practice', trainingCaseScoreLabel(0, 0));
        $this->assertSame(88, trainingCaseScorePercent(7, 8));
        $this->assertSame(0, trainingCaseScorePercent(0, 0));
    }

    public function test_the_best_path_follows_the_first_best_choice(): void
    {
        $this->assertSame([['s1', 'a'], ['s2', 'a']], trainingCaseBestPath($this->doc()));
    }
}
