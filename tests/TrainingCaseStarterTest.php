<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class TrainingCaseStarterTest extends TestCase
{
    /** Shipped starter files: the 3 from v3.505.0 and the 21 added in v3.506.0. */
    private const STARTER_COUNT = 24;
    private const ORIGINAL_STARTERS = ['starter-chest-pain', 'starter-heat-collapse', 'starter-unresponsive-adult'];

    private const SEEDED_KEY = 'training_cases_seeded_slugs';

    protected function setUp(): void
    {
        db()->beginTransaction();
        // The list of what was seeded lives in the settings table; start each test from "never seeded" (rolled back with the rest).
        dbExecute("DELETE FROM settings WHERE setting_key = ?", [self::SEEDED_KEY]);
    }

    /** @return list<string> the slugs the seeder has recorded, sorted */
    private function seededSlugs(): array
    {
        $list = json_decode((string) dbFetchValue("SELECT setting_value FROM settings WHERE setting_key = ?", [self::SEEDED_KEY]), true);
        $this->assertIsArray($list, 'the seeded slugs are stored as a JSON list');
        sort($list);
        return array_values($list);
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
        $this->assertStringContainsString('reviewed by a doctor offline', $note, 'the note says the doctor reviewed the shipped cases offline');
        $this->assertStringNotContainsString('None of them has been reviewed', $note, 'the old claim that no case is reviewed is gone');
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

    public function test_seeding_inserts_every_shipped_case_once_and_records_the_slugs(): void
    {
        $first = trainingCaseSeedStarters();
        $this->assertSame(self::STARTER_COUNT, $first);
        $this->assertSame(0, trainingCaseSeedStarters(), 'a second run changes nothing');
        foreach ($this->starterSlugs() as $slug) {
            $row = dbFetchOne("SELECT is_starter, archived_at FROM training_cases WHERE slug = ?", [$slug]);
            $this->assertNotNull($row, "$slug is in the library");
            $this->assertSame(1, (int) $row['is_starter'], "$slug is a starter");
            $this->assertNull($row['archived_at'], "$slug is an ordinary active case");
        }
        $this->assertSame($this->starterSlugs(), $this->seededSlugs(), 'the slugs that were seeded are remembered');
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

    public function test_an_edited_starter_is_kept_as_the_admin_left_it_when_seeding_runs_again(): void
    {
        trainingCaseSeedStarters();
        $slug = $this->starterSlugs()[0];
        $id = (int) dbFetchValue("SELECT id FROM training_cases WHERE slug = ?", [$slug]);
        $row = trainingCaseLoad($id);
        $doc = $row['doc'];
        $doc['title'] = 'Επεξεργασμένο εκκίνησης';
        $doc['story'] = 'Η ιστορία που άφησε ο διαχειριστής.';

        $saved = trainingCaseSave($id, ['type_tag' => 'other', 'difficulty' => 3, 'language' => 'el', 'est_minutes' => 17], $doc, TrainingFixtures::user());
        $this->assertTrue($saved['ok'], 'a starter is edited like any other case');

        $this->assertSame(0, trainingCaseSeedStarters(), 'the shipped file does not overwrite the edit');
        $after = trainingCaseLoad($id);
        $this->assertSame('Επεξεργασμένο εκκίνησης', $after['title']);
        $this->assertSame('Η ιστορία που άφησε ο διαχειριστής.', $after['doc']['story']);
        $this->assertSame('other', $after['type_tag']);
        $this->assertSame(17, (int) $after['est_minutes']);
        $this->assertSame(1, (int) $after['is_starter'], 'it is still the case that came with the app');
    }

    public function test_a_starter_that_was_deleted_is_not_inserted_again(): void
    {
        trainingCaseSeedStarters();
        $slug = $this->starterSlugs()[0];
        $this->assertTrue(trainingCaseDelete((int) dbFetchValue("SELECT id FROM training_cases WHERE slug = ?", [$slug])));
        $this->assertSame(0, trainingCaseSeedStarters(), 'a deleted starter is not resurrected');
        $this->assertSame(0, (int) dbFetchValue("SELECT COUNT(*) FROM training_cases WHERE slug = ?", [$slug]));
        $this->assertSame(self::STARTER_COUNT - 1, (int) dbFetchValue("SELECT COUNT(*) FROM training_cases WHERE is_starter = 1"));
    }

    public function test_an_installation_that_already_has_the_starters_gets_the_slugs_recorded_and_the_rows_untouched(): void
    {
        trainingCaseSeedStarters();
        $slug = $this->starterSlugs()[0];
        // The state of a site that was updated from v3.505/3.506: the rows are there, the setting is not.
        dbExecute("DELETE FROM settings WHERE setting_key = ?", [self::SEEDED_KEY]);
        dbExecute("UPDATE training_cases SET title = 'Άλλαξε από διαχειριστή', difficulty = 3 WHERE slug = ?", [$slug]);

        $this->assertSame(0, trainingCaseSeedStarters(), 'nothing is inserted or updated');
        $row = dbFetchOne("SELECT title, difficulty FROM training_cases WHERE slug = ?", [$slug]);
        $this->assertSame('Άλλαξε από διαχειριστή', $row['title']);
        $this->assertSame(3, (int) $row['difficulty']);
        $this->assertSame($this->starterSlugs(), $this->seededSlugs(), 'every shipped slug is now on the list');
        $this->assertSame(self::STARTER_COUNT, (int) dbFetchValue("SELECT COUNT(*) FROM training_cases WHERE is_starter = 1"));
    }

    public function test_a_new_shipped_slug_is_inserted_on_such_an_installation_and_a_known_one_is_not_touched(): void
    {
        trainingCaseSeedStarters();
        $known = $this->firstStarterEntry();
        dbExecute("DELETE FROM settings WHERE setting_key = ?", [self::SEEDED_KEY]);
        dbExecute("UPDATE training_cases SET title = 'Δικό μου' WHERE slug = ?", [$known['slug']]);

        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'tc-starters-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $new = $known;
        $new['slug'] = 'brand-new-' . bin2hex(random_bytes(4));
        file_put_contents($dir . '/known.json', json_encode($known, JSON_UNESCAPED_UNICODE));
        file_put_contents($dir . '/new.json', json_encode($new, JSON_UNESCAPED_UNICODE));
        try {
            $this->assertSame(1, trainingCaseSeedStarters($dir), 'only the new slug is inserted');
            $this->assertSame(0, trainingCaseSeedStarters($dir), 'and only once');
        } finally {
            array_map('unlink', glob($dir . '/*.json'));
            rmdir($dir);
        }
        $this->assertSame(1, (int) dbFetchValue("SELECT COUNT(*) FROM training_cases WHERE slug = ? AND is_starter = 1", [$new['slug']]));
        $this->assertSame('Δικό μου', dbFetchValue("SELECT title FROM training_cases WHERE slug = ?", [$known['slug']]));
        $this->assertEqualsCanonicalizing([$known['slug'], $new['slug']], $this->seededSlugs());
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

    public function test_an_archived_starter_stays_archived_and_user_cases_are_left_alone(): void
    {
        $user = TrainingFixtures::user();
        $mine = trainingCaseSave(null, [], $this->firstStarterEntry()['document'], $user);
        trainingCaseSeedStarters();
        $this->assertSame(0, (int) dbFetchValue("SELECT is_starter FROM training_cases WHERE id = ?", [$mine['id']]));

        // An archived starter stays archived, whatever the seeder does afterwards.
        $id = (int) dbFetchValue("SELECT id FROM training_cases WHERE slug = ?", [$this->starterSlugs()[0]]);
        dbExecute("UPDATE training_cases SET archived_at = NOW() WHERE id = ?", [$id]);
        $this->assertSame(0, trainingCaseSeedStarters());
        $this->assertNotNull(dbFetchValue("SELECT archived_at FROM training_cases WHERE id = ?", [$id]), 'seeding does not unarchive');
    }

    public function test_draw_does_not_filter_on_review_and_prefers_fresh_cases(): void
    {
        trainingCaseSeedStarters();
        $any = trainingCaseDraw([]);
        $this->assertNotNull($any, 'a draw over the freshly seeded starters needs no flag');
        $this->assertSame(2, (new ReflectionFunction('trainingCaseDraw'))->getNumberOfParameters(), 'the allow-unreviewed switch is gone');

        $ids = array_map('intval', array_column(dbFetchAll("SELECT id FROM training_cases WHERE is_starter = 1"), 'id'));
        $others = array_slice(array_diff($ids, [(int) $any['id']]), 0, 2);
        for ($i = 0; $i < 20; $i++) {
            $draw = trainingCaseDraw([], $others);
            $this->assertNotContains((int) $draw['id'], $others, 'cases already staged are avoided while others remain');
        }
        // When everything is excluded the draw falls back to the whole pool rather than failing.
        $this->assertNotNull(trainingCaseDraw([], $ids));
    }

    public function test_draw_honours_the_filters_and_ignores_archived_cases(): void
    {
        trainingCaseSeedStarters();
        $this->assertNull(trainingCaseDraw(['language' => 'en']));
        $row = dbFetchOne("SELECT id, type_tag FROM training_cases WHERE is_starter = 1 LIMIT 1");
        $draw = trainingCaseDraw(['type' => $row['type_tag']]);
        $this->assertSame($row['type_tag'], $draw['type_tag']);
        dbExecute("UPDATE training_cases SET archived_at = NOW()");
        $this->assertNull(trainingCaseDraw([]));
    }
}
