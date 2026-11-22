<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class TrainingCaseStarterTest extends TestCase
{
    /** Shipped starter files: the 3 from v3.505.0 and the 21 added in v3.506.0. */
    private const STARTER_COUNT = 24;
    private const ORIGINAL_STARTERS = ['starter-chest-pain', 'starter-heat-collapse', 'starter-unresponsive-adult'];

    protected function setUp(): void
    {
        db()->beginTransaction();
    }

    protected function tearDown(): void
    {
        db()->rollBack();
    }

    /** @return list<string> the slugs of the shipped starter files */
    private function starterSlugs(): array
    {
        $slugs = [];
        foreach (glob(__DIR__ . '/../includes/training-cases/*.json') as $file) {
            $slugs[] = json_decode((string) file_get_contents($file), true)['slug'];
        }
        sort($slugs);
        return $slugs;
    }

    private function firstStarterEntry(): array
    {
        $files = glob(__DIR__ . '/../includes/training-cases/*.json');
        return json_decode((string) file_get_contents($files[0]), true);
    }

    public function test_every_starter_file_is_a_valid_case(): void
    {
        $files = glob(__DIR__ . '/../includes/training-cases/*.json');
        $this->assertCount(self::STARTER_COUNT, $files);
        $slugs = [];
        foreach ($files as $file) {
            $entry = json_decode((string) file_get_contents($file), true);
            $this->assertIsArray($entry, basename($file));
            $doc = trainingCaseNormalize($entry['document']);
            $this->assertSame([], trainingCaseValidate($doc), basename($file) . ' must be a valid case');
            $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', $entry['slug']);
            $this->assertSame(basename($file, '.json'), $entry['slug'], 'the file is named after its slug');
            $this->assertGreaterThanOrEqual(4, count($doc['steps']), basename($file) . ' should have several decisions');
            $slugs[] = $entry['slug'];
        }
        $this->assertSame($slugs, array_values(array_unique($slugs)), 'no two starters share a slug');
    }

    /** Nothing a file says may be silently cut to the editor limits, which would drop the end of a sentence. */
    public function test_no_starter_text_is_longer_than_the_limits_that_would_cut_it(): void
    {
        $limits = ['story' => 1500, 'closing' => 1500];
        foreach (glob(__DIR__ . '/../includes/training-cases/*.json') as $file) {
            $name = basename($file);
            $doc = json_decode((string) file_get_contents($file), true)['document'];
            foreach ($limits as $key => $max) {
                $this->assertLessThanOrEqual($max, mb_strlen($doc[$key]), "$name $key");
            }
            $this->assertLessThanOrEqual(160, mb_strlen($doc['title']), "$name title");
            foreach ($doc['steps'] as $stepId => $step) {
                $this->assertLessThanOrEqual(400, mb_strlen($step['prompt']), "$name $stepId prompt");
                $this->assertLessThanOrEqual(300, mb_strlen($step['hint'] ?? ''), "$name $stepId hint");
                foreach ($step['choices'] as $choice) {
                    $this->assertLessThanOrEqual(200, mb_strlen($choice['label']), "$name $stepId{$choice['id']} label");
                    $this->assertLessThanOrEqual(600, mb_strlen($choice['feedback']), "$name $stepId{$choice['id']} feedback");
                }
            }
            foreach ($doc['findings'] as $finding) {
                $this->assertLessThanOrEqual(60, mb_strlen($finding['label']), "$name finding label");
                $this->assertLessThanOrEqual(60, mb_strlen($finding['value']), "$name finding value");
                $this->assertLessThanOrEqual(20, mb_strlen($finding['unit'] ?? ''), "$name finding unit");
            }
        }
    }

    public function test_every_starter_is_listed_in_the_sources_note_and_says_what_it_is_based_on(): void
    {
        $note = (string) file_get_contents(__DIR__ . '/../docs/training-cases-sources.md');
        $this->assertStringContainsString('None of them has been reviewed', $note, 'the note says plainly that no case is medically reviewed');
        foreach (glob(__DIR__ . '/../includes/training-cases/*.json') as $file) {
            $entry = json_decode((string) file_get_contents($file), true);
            if (in_array($entry['slug'], self::ORIGINAL_STARTERS, true)) {
                continue;
            }
            $this->assertStringContainsString('Βάση:', $entry['document']['closing'], $entry['slug'] . ' names its basis in the closing text');
        }
    }

    /**
     * Choices that would hurt a real patient must stay "harmful" whatever else is edited. Each entry is a case, a step
     * and the choices that must never earn points.
     */
    public function test_choices_that_would_hurt_a_patient_are_never_rated_best_or_acceptable(): void
    {
        $mustBeHarmful = [
            'starter-choking-adult'        => ['s2' => ['a', 'd']],
            'starter-infant-choking'       => ['s2' => ['a', 'b'], 's3' => ['c']],
            'starter-snakebite-trail'      => ['s2' => ['a', 'b', 'c'], 's5' => ['a', 'b']],
            'starter-stroke-hiker'         => ['s4' => ['a', 'b']],
            'starter-seizure-festival'     => ['s1' => ['a', 'b', 'd']],
            'starter-hypoglycemia'         => ['s1' => ['b'], 's4' => ['a', 'b']],
            'starter-hypothermia-hiker'    => ['s1' => ['a', 'b', 'd'], 's4' => ['b', 'c']],
            'starter-burn-scald-child'     => ['s2' => ['a', 'b', 'c']],
            'starter-spinal-fall'          => ['s1' => ['a', 'b'], 's3' => ['a', 'b']],
            'starter-electric-shock'       => ['s1' => ['a', 'c', 'd']],
            'starter-carbon-monoxide-hut'  => ['s1' => ['a', 'b'], 's2' => ['a', 'c']],
            'starter-lightning-ridge'      => ['s1' => ['a', 'b'], 's3' => ['a', 'c']],
            'starter-anaphylaxis-sting'    => ['s1' => ['a', 'b'], 's3' => ['b']],
            'starter-bleeding-leg'         => ['s3' => ['a', 'b'], 's4' => ['a', 'b']],
            'starter-drowning-child'       => ['s1' => ['a', 'b']],
        ];
        foreach ($mustBeHarmful as $slug => $steps) {
            $doc = json_decode((string) file_get_contents(__DIR__ . "/../includes/training-cases/$slug.json"), true)['document'];
            foreach ($steps as $stepId => $ids) {
                $choices = array_column($doc['steps'][$stepId]['choices'], null, 'id');
                foreach ($ids as $id) {
                    $this->assertSame('harmful', $choices[$id]['rating'], "$slug $stepId$id must stay harmful");
                }
            }
        }
    }

    /** In every step of every starter the best answer is one choice, and it is not always the first or the longest. */
    public function test_each_step_has_one_best_choice_and_the_best_is_not_always_first(): void
    {
        $firstIsBest = 0;
        $total = 0;
        foreach (glob(__DIR__ . '/../includes/training-cases/*.json') as $file) {
            $doc = json_decode((string) file_get_contents($file), true)['document'];
            foreach ($doc['steps'] as $stepId => $step) {
                $best = array_values(array_filter($step['choices'], static fn (array $c): bool => $c['rating'] === 'best'));
                $this->assertCount(1, $best, basename($file) . " $stepId has exactly one best choice");
                $total++;
                if ($step['choices'][0]['rating'] === 'best') {
                    $firstIsBest++;
                }
            }
        }
        $this->assertLessThan($total * 0.5, $firstIsBest, 'the best answer is not first in most steps');
    }

    public function test_heat_case_never_rates_drinking_or_getting_up_as_acceptable_for_a_confused_runner(): void
    {
        $entry = json_decode((string) file_get_contents(__DIR__ . '/../includes/training-cases/starter-heat-collapse.json'), true);
        $steps = $entry['document']['steps'];
        $byId = static fn (array $step): array => array_column($step['choices'], null, 'id');
        $this->assertSame('harmful', $byId($steps['s4'])['b']['rating'], 'sips of water for a confused runner');
        $this->assertStringNotContainsString('σηκωθεί σιγά', $byId($steps['s1'])['b']['label'], 'a confused runner is not told to get up');
        $this->assertStringNotContainsString('νυσταγμέν', (string) json_encode($entry['document'], JSON_UNESCAPED_UNICODE));
    }

    public function test_seeding_is_idempotent_and_marks_starters_unreviewed(): void
    {
        $first = trainingCaseSeedStarters();
        $this->assertSame(self::STARTER_COUNT, $first);
        $this->assertSame(0, trainingCaseSeedStarters(), 'a second run changes nothing');
        foreach ($this->starterSlugs() as $slug) {
            $row = dbFetchOne("SELECT is_starter, reviewed FROM training_cases WHERE slug = ?", [$slug]);
            $this->assertNotNull($row, "$slug is in the library");
            $this->assertSame(1, (int) $row['is_starter'], "$slug is a starter");
            $this->assertSame(0, (int) $row['reviewed'], "$slug starts unreviewed");
        }
    }

    public function test_a_starter_insert_that_loses_the_race_does_not_throw(): void
    {
        $entry = $this->firstStarterEntry();
        $doc = trainingCaseNormalize($entry['document']);
        $meta = trainingCaseMeta($entry);
        $json = json_encode($doc, JSON_UNESCAPED_UNICODE);
        $slug = 'race-' . bin2hex(random_bytes(4));

        $this->assertTrue(trainingCaseInsertStarter($slug, $doc, $meta, $json), 'the first insert wins');
        // A second request that checked before the first inserted now hits the UNIQUE slug.
        $this->assertFalse(trainingCaseInsertStarter($slug, $doc, $meta, $json), 'the loser reports false instead of throwing');
        $this->assertSame(1, (int) dbFetchValue("SELECT COUNT(*) FROM training_cases WHERE slug = ?", [$slug]));
    }

    public function test_a_starter_slug_that_collides_with_a_non_starter_is_skipped_and_logged(): void
    {
        $entry = $this->firstStarterEntry();
        $slug = $entry['slug'];
        $user = TrainingFixtures::user();
        $mine = trainingCaseSave(null, [], $entry['document'], $user);
        dbExecute("UPDATE training_cases SET slug = ?, is_starter = 0 WHERE id = ?", [$slug, $mine['id']]);
        $before = dbFetchValue("SELECT document FROM training_cases WHERE id = ?", [$mine['id']]);
        dbExecute("UPDATE training_cases SET title = 'Mine' WHERE id = ?", [$mine['id']]);

        $log = (string) tempnam(sys_get_temp_dir(), 'tcl');
        $old = (string) ini_set('error_log', $log);
        try {
            trainingCaseSeedStarters();
        } finally {
            ini_set('error_log', $old);
        }
        $logged = (string) file_get_contents($log);
        @unlink($log);

        $this->assertStringContainsString($slug, $logged, 'the collision is logged');
        $row = dbFetchOne("SELECT is_starter, title, document FROM training_cases WHERE id = ?", [$mine['id']]);
        $this->assertSame(0, (int) $row['is_starter']);
        $this->assertSame('Mine', $row['title'], 'the non-starter row is not overwritten');
        $this->assertSame($before, $row['document']);
    }

    public function test_a_metadata_only_change_in_a_starter_file_propagates(): void
    {
        trainingCaseSeedStarters();
        $slug = $this->starterSlugs()[0];
        $id = (int) dbFetchValue("SELECT id FROM training_cases WHERE slug = ?", [$slug]);
        $original = dbFetchOne("SELECT type_tag, difficulty, est_minutes, title FROM training_cases WHERE id = ?", [$id]);
        dbExecute("UPDATE training_cases SET reviewed = 1, type_tag = 'other', difficulty = 3, est_minutes = 99, title = 'old title' WHERE id = ?", [$id]);

        $this->assertSame(1, trainingCaseSeedStarters(), 'the file metadata wins again');
        $row = dbFetchOne("SELECT type_tag, difficulty, est_minutes, title, reviewed FROM training_cases WHERE id = ?", [$id]);
        $this->assertSame($original['type_tag'], $row['type_tag']);
        $this->assertSame((int) $original['difficulty'], (int) $row['difficulty']);
        $this->assertSame((int) $original['est_minutes'], (int) $row['est_minutes']);
        $this->assertSame($original['title'], $row['title']);
        $this->assertSame(0, (int) $row['reviewed'], 'a changed starter goes back to unreviewed');
        $this->assertSame(0, trainingCaseSeedStarters());
    }

    public function test_an_invalid_starter_file_is_skipped_without_stopping_the_others(): void
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tc-starters-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $slug = 'temp-starter-' . bin2hex(random_bytes(4));
        $good = $this->firstStarterEntry();
        $good['slug'] = $slug;
        // 'a-broken' sorts first, so the seeder has to carry on past it.
        file_put_contents($dir . '/a-broken.json', json_encode(['slug' => 'broken-' . $slug, 'document' => ['title' => 'No steps']]));
        file_put_contents($dir . '/b-notjson.json', '{not json');
        file_put_contents($dir . '/c-good.json', json_encode($good, JSON_UNESCAPED_UNICODE));

        $log = (string) tempnam(sys_get_temp_dir(), 'tcl');
        $old = (string) ini_set('error_log', $log);
        try {
            $changed = trainingCaseSeedStarters($dir);
        } finally {
            ini_set('error_log', $old);
            array_map('unlink', glob($dir . '/*.json'));
            rmdir($dir);
        }
        $logged = (string) file_get_contents($log);
        @unlink($log);

        $this->assertSame(1, $changed, 'only the valid file is loaded');
        $this->assertSame(1, (int) dbFetchValue("SELECT COUNT(*) FROM training_cases WHERE slug = ? AND is_starter = 1", [$slug]));
        $this->assertSame(0, (int) dbFetchValue("SELECT COUNT(*) FROM training_cases WHERE slug = ?", ['broken-' . $slug]));
        $this->assertStringContainsString('a-broken.json', $logged);
        $this->assertStringContainsString('b-notjson.json', $logged);
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
        $mine = trainingCaseSave(null, [], $this->firstStarterEntry()['document'], $user);
        trainingCaseSeedStarters();
        $this->assertSame(0, (int) dbFetchValue("SELECT is_starter FROM training_cases WHERE id = ?", [$mine['id']]));

        // An archived starter stays archived, and an unchanged archived starter is not touched.
        $id = (int) dbFetchValue("SELECT id FROM training_cases WHERE slug = ?", [$this->starterSlugs()[0]]);
        dbExecute("UPDATE training_cases SET archived_at = NOW() WHERE id = ?", [$id]);
        $this->assertSame(0, trainingCaseSeedStarters());
        $this->assertNotNull(dbFetchValue("SELECT archived_at FROM training_cases WHERE id = ?", [$id]), 'seeding does not unarchive');
        // Even when the file wins a content change, the archive stays.
        dbExecute("UPDATE training_cases SET title = 'changed' WHERE id = ?", [$id]);
        $this->assertSame(1, trainingCaseSeedStarters());
        $this->assertNotNull(dbFetchValue("SELECT archived_at FROM training_cases WHERE id = ?", [$id]), 'an updated starter stays archived');
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
