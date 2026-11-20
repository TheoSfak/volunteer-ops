# Training Cases Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A commander stages a "training case" (a virtual patient with multiple-choice decisions) at a GPS spot of a drill; each volunteer of the audience works through it on their own phone, with immediate feedback, and the result lands in their training record.

**Architecture:** A case is a JSON document in a library table. Staging it adds an ordinary scenario point order (`kind = 'dispatch'`) whose payload carries a snapshot of the document, so navigation, the AR beacon, per-team release, withdrawal and arrival detection are all reused unchanged. Playing the phase sends the point order as usual and records a `mission_case_placements` row linking the order to the snapshot. Each volunteer's run and answers are small relational tables; the server recomputes ratings and scores itself.

**Tech Stack:** PHP 8.2 / MySQL (Hostinger), PHPUnit, plain JS with `node --test`, Bootstrap 5, the existing Action Room (`war-room.php`).

## Where this plan differs from the approved spec

Reading the code (the spec's "Points to verify") changed six details. Task 12 folds them back into the spec.

1. **No new scenario item `kind`.** A case item is a `dispatch` item whose payload has a `case` block. A new ENUM value would have to be added to about twelve places (play, release, gating facts, live states, withdraw, print, duplicate...); the payload approach reuses all of them. No ENUM migration.
2. **Arrival is a team fact.** `recordDispatchProgress()` records one arrival per team ("the first member moves the whole team on"), so "arrival opens the case for each volunteer" cannot be a per-person hook. Instead: the case becomes available to every audience volunteer once their team's arrival is recorded (the dispatch row already exposes it as `my_ack`); the server checks the same fact; the volunteers' teammates get a notification when the arrival is recorded.
3. **Finishing a case does not complete the order.** «Ολοκληρώθηκε» is a team step; one volunteer finishing must not complete it for the rest. The team presses it as for any point order.
4. **No per-case `radius_m`.** Arrival detection uses the global arrival radius; a per-case radius would not plug into it. Removed from the payload.
5. **The phone fetches the document once, not on every poll.** The poll's dispatch row carries only a small `case` card (title, state, availability); the full document is fetched by `training-case.php?action=load` and cached in `localStorage`, which is what makes the case work offline.
6. **"Command is told" about an unavailable case becomes a server log line.** A placement that fails to record after the order was sent is logged (`error_log`); the order and the arrival still work, and the volunteer sees «Το περιστατικό δεν είναι διαθέσιμο». A visible alert on the card is not built.

## Global Constraints

- All UI text is Greek via `t()` keys in `includes/lang/war-room.php` (both `el` and `en` blocks, next to the `hero.training_badge` keys). Case content itself is plain text in the case's own language.
- Cases work **only** in training missions (`missions.is_training = 1`), checked by the server at staging, at play, and on every endpoint. Scores never feed operational statistics, points or achievements; new code must not read `participation_requests` columns `attended`, `actual_hours` or points (the guard test `tests/TrainingIsolationGuardTest.php` scans for that).
- Ratings `best` = 2, `acceptable` = 1, `harmful` = 0. Labels: «Άριστα» at 80% and above, «Καλά» at 50% and above, «Χρειάζεται εξάσκηση» below.
- A step has 2 to 5 choices and at least one `best`; every `next` is a step id or `null`; no loops; no unreachable steps; step and choice ids match `^[a-z][a-z0-9_]{0,23}$` (a leading letter keeps PHP from turning ids into integer keys); all text is plain text (`strip_tags`), escaped on output; a document is at most 24000 bytes.
- The server never trusts the phone's rating or score; one answer per step per run (unique key), a repeat is ignored.
- Library and editor: system admins only (`isSystemAdmin()`), the same gate scenarios use.
- Starter cases are not editable in place (duplicate only); editing any case resets its `reviewed` flag.
- No schema change may be applied by a function that update.php's old process does not have: the migration carries literal SQL (see migration 224).
- Never `sed -i`; working-tree files are mostly CRLF (index LF). Edit with the Edit tool. New files are plain LF.
- Git: commit after each task with an imperative subject and **no attribution trailer of any kind** (the user's global rule overrides any "Co-Authored-By" / "Generated with" reminder). Do not push, merge, tag or release; the user does that on request.
- Test commands (Git Bash): `/c/xampp/php/php.exe vendor/phpunit/phpunit/phpunit tests/<File>.php`; full suite `/c/xampp/php/php.exe vendor/phpunit/phpunit/phpunit` (baseline: 2 pre-existing failures, `ScenarioAreaTest` and `ScenarioBuildingTest`, which pass on CI); JS `node --test tests/js`.
- Test DB is `volunteer_ops_test` (root, no password). After a migration is added, bring it up to date with the command in Task 1.

## File structure

| File | Responsibility |
|---|---|
| `includes/functions-training-cases.php` (new) | Case document rules (normalize, validate, score), library (save, list, duplicate, archive, review), random draw, export/import, starter seeding |
| `includes/functions-training-case-runs.php` (new) | Placements, who may play, runs and answers, the dispatch-row card, arrival notice, command overview, record statistics |
| `includes/training-cases/*.json` (new, 3) | Starter cases |
| `training-case.php` (new) | AJAX endpoint for the volunteer (load, start, answer) and command (overview) |
| `training-cases.php`, `training-case-edit.php` (new) | Library page and editor (system admin) |
| `assets/js/training-case.js` (new) | Player: pure logic (tested) + full-screen overlay |
| `assets/js/training-case-editor.js` (new) | Editor form: build and serialise the document |
| `includes/functions-scenario.php` | `addScenarioDispatchItem` takes an extra payload; `addScenarioCaseItem`; play hook; item card title |
| `mission-scenario.php` | `add_item` branch for cases; `case_list`, `case_draw` |
| `includes/functions-warroom.php` | `case` block on each dispatch row; arrival notice hook |
| `war-room.php` | Player button and overlay, offline replay, command card, dispatch-window case picker |
| `assets/js/war-room-scenario.js` | Passes case fields when staging; shows the case on the plan card |
| `includes/migrations.php`, `config.php`, `sql/schema.sql` | Migration 225 |
| `includes/training-record-section.php`, `includes/functions-training-missions.php` | The record line |

---

### Task 1: Schema (migration 225)

**Files:**
- Modify: `includes/migrations.php` (append after migration 224, before the closing `];` at about line 8579)
- Modify: `config.php:15` (`DB_SCHEMA_VERSION` 224 to 225)
- Modify: `sql/schema.sql` (the `mission_scenario_items.payload` column at about line 3776; new tables before the final `SET FOREIGN_KEY_CHECKS = 1;`)
- Test: `tests/TrainingCaseSchemaTest.php` (new)

**Interfaces:**
- Produces: tables `training_cases`, `mission_case_placements` (columns `id, mission_id, scenario_item_id, dispatch_id, case_id, title, snapshot, audience, team_id, briefing, draw_mode, created_at`), `mission_case_runs` (`id, placement_id, user_id, started_at, finished_at, score_points, score_max, current_step`), `mission_case_answers` (`id, run_id, step_id, choice_id, rating, answered_at, ms_taken`); `mission_scenario_items.payload` becomes MEDIUMTEXT.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Migration 225: the training-case tables and the wider scenario payload column. */
final class TrainingCaseSchemaTest extends TestCase
{
    protected function setUp(): void
    {
        db()->beginTransaction();
    }

    protected function tearDown(): void
    {
        db()->rollBack();
    }

    public function test_the_four_tables_exist(): void
    {
        foreach (['training_cases', 'mission_case_placements', 'mission_case_runs', 'mission_case_answers'] as $table) {
            $this->assertNotNull(dbFetchOne("SHOW TABLES LIKE '{$table}'"), "{$table} is missing");
        }
    }

    public function test_slug_is_unique(): void
    {
        $this->expectException(PDOException::class);
        dbInsert("INSERT INTO training_cases (slug, title, document) VALUES ('dup-slug', 'A', '{}')");
        dbInsert("INSERT INTO training_cases (slug, title, document) VALUES ('dup-slug', 'B', '{}')");
    }

    public function test_a_run_is_unique_per_placement_and_user_and_an_answer_per_step(): void
    {
        $user = TrainingFixtures::user();
        $drill = TrainingFixtures::mission(true);
        $placement = (int) dbInsert(
            "INSERT INTO mission_case_placements (mission_id, title, snapshot, audience) VALUES (?, 'T', '{}', 'all')",
            [$drill['mission']]
        );
        $run = (int) dbInsert("INSERT INTO mission_case_runs (placement_id, user_id) VALUES (?, ?)", [$placement, $user]);
        $this->assertSame(0, dbExecute("INSERT IGNORE INTO mission_case_runs (placement_id, user_id) VALUES (?, ?)", [$placement, $user]));

        dbExecute("INSERT INTO mission_case_answers (run_id, step_id, choice_id, rating) VALUES (?, 's1', 'c1', 'best')", [$run]);
        $this->assertSame(0, dbExecute("INSERT IGNORE INTO mission_case_answers (run_id, step_id, choice_id, rating) VALUES (?, 's1', 'c2', 'harmful')", [$run]));
    }

    public function test_deleting_the_order_keeps_the_placement_and_its_runs(): void
    {
        $user = TrainingFixtures::user();
        $drill = TrainingFixtures::mission(true);
        $admin = TrainingFixtures::user('Admin');
        $dispatch = (int) dbInsert(
            "INSERT INTO mission_dispatch_points (mission_id, type, geo, created_by) VALUES (?, 'point', '{\"lat\":35,\"lng\":25}', ?)",
            [$drill['mission'], $admin]
        );
        $placement = (int) dbInsert(
            "INSERT INTO mission_case_placements (mission_id, dispatch_id, title, snapshot, audience) VALUES (?, ?, 'T', '{}', 'all')",
            [$drill['mission'], $dispatch]
        );
        dbInsert("INSERT INTO mission_case_runs (placement_id, user_id) VALUES (?, ?)", [$placement, $user]);

        dbExecute("DELETE FROM mission_dispatch_points WHERE id = ?", [$dispatch]);

        $this->assertNull(dbFetchValue("SELECT dispatch_id FROM mission_case_placements WHERE id = ?", [$placement]));
        $this->assertSame(1, (int) dbFetchValue("SELECT COUNT(*) FROM mission_case_runs WHERE placement_id = ?", [$placement]));
    }

    public function test_the_scenario_payload_column_holds_a_whole_document(): void
    {
        $col = dbFetchOne("SHOW COLUMNS FROM mission_scenario_items LIKE 'payload'");
        $this->assertSame('mediumtext', strtolower((string) $col['Type']));
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `/c/xampp/php/php.exe vendor/phpunit/phpunit/phpunit tests/TrainingCaseSchemaTest.php`
Expected: FAIL (`training_cases is missing`).

- [ ] **Step 3: Add the migration** (after migration 224's closing `],`, before `];`)

```php
        [
            'version'     => 225,
            'description' => 'Training cases: a library of case documents (training_cases), where a case was placed in a drill ' .
                '(mission_case_placements, one per sent point order), each volunteer\'s run and answers. ' .
                'mission_scenario_items.payload becomes MEDIUMTEXT: a staged case carries its whole document.',
            'up' => function () {
                dbExecute("CREATE TABLE IF NOT EXISTS `training_cases` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `slug` VARCHAR(80) NOT NULL,
                    `title` VARCHAR(160) NOT NULL,
                    `type_tag` VARCHAR(40) NOT NULL DEFAULT 'other',
                    `difficulty` TINYINT UNSIGNED NOT NULL DEFAULT 1,
                    `language` ENUM('el','en') NOT NULL DEFAULT 'el',
                    `est_minutes` TINYINT UNSIGNED NOT NULL DEFAULT 5,
                    `document` MEDIUMTEXT NOT NULL,
                    `format_version` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
                    `is_starter` TINYINT(1) NOT NULL DEFAULT 0,
                    `reviewed` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'A medically qualified person has checked the content',
                    `reviewed_by` INT UNSIGNED NULL,
                    `reviewed_at` DATETIME NULL,
                    `archived_at` DATETIME NULL,
                    `created_by` INT UNSIGNED NULL,
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY `uq_training_case_slug` (`slug`),
                    FOREIGN KEY (`reviewed_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
                    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

                dbExecute("CREATE TABLE IF NOT EXISTS `mission_case_placements` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `mission_id` INT UNSIGNED NOT NULL,
                    `scenario_item_id` INT UNSIGNED NULL COMMENT 'The staged item; no foreign key',
                    `dispatch_id` INT UNSIGNED NULL COMMENT 'The point order sent for it; NULL once that order is withdrawn (runs are kept)',
                    `case_id` INT UNSIGNED NULL COMMENT 'The library case it came from; NULL if that was deleted',
                    `title` VARCHAR(160) NOT NULL,
                    `snapshot` MEDIUMTEXT NOT NULL COMMENT 'The case document as it was when staged',
                    `audience` VARCHAR(10) NOT NULL DEFAULT 'all' COMMENT 'team|all',
                    `team_id` INT UNSIGNED NULL,
                    `briefing` VARCHAR(300) NULL,
                    `draw_mode` ENUM('chosen','random') NOT NULL DEFAULT 'chosen',
                    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY `uq_case_placement_dispatch` (`dispatch_id`),
                    INDEX `idx_case_placement_mission` (`mission_id`),
                    FOREIGN KEY (`mission_id`) REFERENCES `missions`(`id`) ON DELETE CASCADE,
                    FOREIGN KEY (`dispatch_id`) REFERENCES `mission_dispatch_points`(`id`) ON DELETE SET NULL,
                    FOREIGN KEY (`case_id`) REFERENCES `training_cases`(`id`) ON DELETE SET NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

                dbExecute("CREATE TABLE IF NOT EXISTS `mission_case_runs` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `placement_id` INT UNSIGNED NOT NULL,
                    `user_id` INT UNSIGNED NOT NULL,
                    `started_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `finished_at` DATETIME NULL,
                    `score_points` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                    `score_max` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                    `current_step` VARCHAR(24) NULL,
                    UNIQUE KEY `uq_case_run` (`placement_id`, `user_id`),
                    FOREIGN KEY (`placement_id`) REFERENCES `mission_case_placements`(`id`) ON DELETE CASCADE,
                    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

                dbExecute("CREATE TABLE IF NOT EXISTS `mission_case_answers` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `run_id` INT UNSIGNED NOT NULL,
                    `step_id` VARCHAR(24) NOT NULL,
                    `choice_id` VARCHAR(24) NOT NULL,
                    `rating` ENUM('best','acceptable','harmful') NOT NULL,
                    `answered_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `ms_taken` INT UNSIGNED NULL,
                    UNIQUE KEY `uq_case_answer` (`run_id`, `step_id`),
                    FOREIGN KEY (`run_id`) REFERENCES `mission_case_runs`(`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

                dbExecute("ALTER TABLE `mission_scenario_items` MODIFY COLUMN `payload` MEDIUMTEXT NOT NULL");
            },
        ],
```

- [ ] **Step 4: Bump the schema version and mirror in `sql/schema.sql`**

In `config.php` change `define('DB_SCHEMA_VERSION', 224);` to `225`.

In `sql/schema.sql`: in the `CREATE TABLE IF NOT EXISTS mission_scenario_items` statement change `` `payload` TEXT NOT NULL `` to `` `payload` MEDIUMTEXT NOT NULL ``. Then, immediately before the final `SET FOREIGN_KEY_CHECKS = 1;`, add the four `CREATE TABLE IF NOT EXISTS` statements exactly as in the migration (same columns, keys and comments, without the PHP `dbExecute` wrapper), under a heading comment `-- TRAINING CASES - v3.505.0 (migration 225)`.

- [ ] **Step 5: Apply to the test DB and run the test**

Run: `/c/xampp/php/php.exe -r "define('VOLUNTEEROPS', true); require 'tests/bootstrap.php'; require 'includes/migrations.php'; echo dbFetchValue('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = \'training_cases\''), PHP_EOL;"`
Expected: `1`. (If it prints `0`, run the migration up function by hand with the same bootstrap and check `SELECT MAX(version) FROM schema_migrations` or the table this app uses.)

Run: `/c/xampp/php/php.exe vendor/phpunit/phpunit/phpunit tests/TrainingCaseSchemaTest.php`
Expected: PASS (5 tests).

- [ ] **Step 6: Commit**

```bash
git add includes/migrations.php config.php sql/schema.sql tests/TrainingCaseSchemaTest.php
git commit -m "Add the training case tables (migration 225)"
```

---

### Task 2: The case document - normalise, validate, score

**Files:**
- Create: `includes/functions-training-cases.php`
- Modify: `bootstrap.php` (after line 28), `tests/bootstrap.php` (after line 38): `require_once` the new file
- Test: `tests/TrainingCaseDocumentTest.php` (new)

**Interfaces:**
- Produces (all in `includes/functions-training-cases.php`):
  - constants `TRAINING_CASE_FORMAT_VERSION = 1`, `TRAINING_CASE_MAX_DOC_BYTES = 24000`, `TRAINING_CASE_MAX_STEPS = 12`, `TRAINING_CASE_MAX_FINDINGS = 12`, `TRAINING_CASE_RATING_POINTS = ['best' => 2, 'acceptable' => 1, 'harmful' => 0]`
  - `trainingCasePlain($value, int $max): string`
  - `trainingCaseId($value): string`
  - `trainingCaseNormalize($raw): ?array` (document array with keys `title, story, closing, findings, start, steps`, or null if not an array)
  - `trainingCaseValidate(array $doc): array` (list of `['code' => string, 'where' => string]`, empty when valid)
  - `trainingCaseErrorText(array $error): string` (Greek text via `t('case.err.<code>')`)
  - `trainingCaseRatingPoints(string $rating): int`
  - `trainingCaseScoreLabel(int $points, int $max): string` (`'excellent' | 'good' | 'practice'`)
  - `trainingCaseScorePercent(int $points, int $max): int`
  - `trainingCaseBestPath(array $doc): array` (list of `[stepId, choiceId]`)
- Consumes: nothing.

- [ ] **Step 1: Write the failing test**

```php
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
```

- [ ] **Step 2: Run it to verify it fails**

Run: `/c/xampp/php/php.exe vendor/phpunit/phpunit/phpunit tests/TrainingCaseDocumentTest.php`
Expected: FAIL (`Call to undefined function trainingCaseValidate`).

- [ ] **Step 3: Create `includes/functions-training-cases.php`**

```php
<?php
/**
 * VolunteerOps - Training cases (v3.505.0).
 *
 * A case is a short case study at a place: a story, some findings, and a branching
 * sequence of multiple-choice decisions, each with a rating and feedback. It is
 * stored as a JSON document (format_version 1) in training_cases; staging it in a
 * scenario takes a SNAPSHOT of the document, so a later library edit never changes
 * a prepared or running drill. See docs/superpowers/specs/2026-11-20-training-cases-design.md.
 *
 * This file: the document rules (normalise, validate, score), the library, the
 * random draw, export/import and the starter cases. Runs and answers are in
 * functions-training-case-runs.php.
 */

if (!defined('VOLUNTEEROPS')) {
    die('Direct access not permitted');
}

const TRAINING_CASE_FORMAT_VERSION = 1;
const TRAINING_CASE_MAX_DOC_BYTES = 24000;
const TRAINING_CASE_MAX_STEPS = 12;
const TRAINING_CASE_MAX_FINDINGS = 12;
const TRAINING_CASE_RATING_POINTS = ['best' => 2, 'acceptable' => 1, 'harmful' => 0];

/** Plain text only: tags removed, runs of spaces collapsed (line breaks kept), cut to $max characters. */
function trainingCasePlain($value, int $max): string {
    $text = trim(strip_tags((string) $value));
    $text = (string) preg_replace('/[ \t]+/u', ' ', $text);
    return mb_substr($text, 0, $max);
}

/** A step or choice id: a lowercase letter then up to 23 letters, digits or underscores, else ''. */
function trainingCaseId($value): string {
    $id = strtolower(trim((string) $value));
    return preg_match('/^[a-z][a-z0-9_]{0,23}$/', $id) ? $id : '';
}

/**
 * Reads a decoded document into the one shape every other function relies on: known keys only, plain text, every
 * field cut to its limit, steps kept in the order given. Returns null when $raw is not an array. It never repairs
 * the STRUCTURE (a missing step stays missing): trainingCaseValidate() says what is wrong.
 */
function trainingCaseNormalize($raw): ?array {
    if (!is_array($raw)) {
        return null;
    }
    $doc = [
        'title'    => trainingCasePlain($raw['title'] ?? '', 160),
        'story'    => trainingCasePlain($raw['story'] ?? '', 1500),
        'closing'  => trainingCasePlain($raw['closing'] ?? '', 1500),
        'findings' => [],
        'start'    => trainingCaseId($raw['start'] ?? ''),
        'steps'    => [],
    ];
    $findings = is_array($raw['findings'] ?? null) ? array_values($raw['findings']) : [];
    foreach (array_slice($findings, 0, TRAINING_CASE_MAX_FINDINGS) as $finding) {
        if (!is_array($finding)) {
            continue;
        }
        $label = trainingCasePlain($finding['label'] ?? '', 60);
        $value = trainingCasePlain($finding['value'] ?? '', 60);
        if ($label === '' || $value === '') {
            continue;
        }
        $doc['findings'][] = ['label' => $label, 'value' => $value, 'unit' => trainingCasePlain($finding['unit'] ?? '', 20)];
    }
    $steps = is_array($raw['steps'] ?? null) ? $raw['steps'] : [];
    foreach ($steps as $stepId => $step) {
        $stepId = trainingCaseId($stepId);
        if ($stepId === '' || !is_array($step) || isset($doc['steps'][$stepId])) {
            continue;
        }
        $choices = [];
        foreach (is_array($step['choices'] ?? null) ? array_values($step['choices']) : [] as $choice) {
            if (!is_array($choice)) {
                continue;
            }
            $next = $choice['next'] ?? null;
            $choices[] = [
                'id'       => trainingCaseId($choice['id'] ?? ''),
                'label'    => trainingCasePlain($choice['label'] ?? '', 200),
                'rating'   => (string) ($choice['rating'] ?? ''),
                'feedback' => trainingCasePlain($choice['feedback'] ?? '', 600),
                'next'     => ($next === null || $next === '') ? null : trainingCaseId($next),
            ];
        }
        $doc['steps'][$stepId] = [
            'prompt'  => trainingCasePlain($step['prompt'] ?? '', 400),
            'hint'    => trainingCasePlain($step['hint'] ?? '', 300),
            'choices' => array_slice($choices, 0, 8),
        ];
    }
    return $doc;
}

/**
 * Every rule a case must meet, on a NORMALISED document. Returns a list of ['code' => string, 'where' => step id or ''],
 * empty when the case is sound. The loop and reachability checks run only once the rest is sound, since they
 * need every next to point somewhere real.
 */
function trainingCaseValidate(array $doc): array {
    $errors = [];
    $add = function (string $code, string $where = '') use (&$errors): void {
        $errors[] = ['code' => $code, 'where' => $where];
    };
    if ($doc['title'] === '') {
        $add('title_missing');
    }
    if ($doc['story'] === '') {
        $add('story_missing');
    }
    if (count($doc['steps']) < 1) {
        $add('no_steps');
    }
    if (count($doc['steps']) > TRAINING_CASE_MAX_STEPS) {
        $add('too_many_steps');
    }
    if (!isset($doc['steps'][$doc['start']])) {
        $add('start_missing');
    }
    foreach ($doc['steps'] as $stepId => $step) {
        $stepId = (string) $stepId;
        if ($step['prompt'] === '') {
            $add('prompt_missing', $stepId);
        }
        $count = count($step['choices']);
        if ($count < 2 || $count > 5) {
            $add('choice_count', $stepId);
        }
        $seen = [];
        $hasBest = false;
        foreach ($step['choices'] as $choice) {
            if ($choice['id'] === '' || isset($seen[$choice['id']])) {
                $add('choice_id', $stepId);
            }
            $seen[$choice['id']] = true;
            if (!isset(TRAINING_CASE_RATING_POINTS[$choice['rating']])) {
                $add('rating_invalid', $stepId);
            }
            if ($choice['rating'] === 'best') {
                $hasBest = true;
            }
            if ($choice['label'] === '') {
                $add('choice_label_missing', $stepId);
            }
            if ($choice['feedback'] === '') {
                $add('feedback_missing', $stepId);
            }
            if ($choice['next'] !== null && !isset($doc['steps'][$choice['next']])) {
                $add('next_missing', $stepId);
            }
        }
        if (!$hasBest) {
            $add('no_best', $stepId);
        }
    }
    if (!$errors) {
        $state = [];
        $visit = function (string $stepId) use (&$visit, &$state, $doc, $add): void {
            $state[$stepId] = 1;
            foreach ($doc['steps'][$stepId]['choices'] as $choice) {
                $next = $choice['next'];
                if ($next === null) {
                    continue;
                }
                if (($state[$next] ?? 0) === 1) {
                    $add('loop', $stepId);
                } elseif (!isset($state[$next])) {
                    $visit($next);
                }
            }
            $state[$stepId] = 2;
        };
        $visit($doc['start']);
        foreach (array_keys($doc['steps']) as $stepId) {
            if (!isset($state[(string) $stepId])) {
                $add('unreachable', (string) $stepId);
            }
        }
    }
    if (strlen((string) json_encode($doc, JSON_UNESCAPED_UNICODE)) > TRAINING_CASE_MAX_DOC_BYTES) {
        $add('too_big');
    }
    return $errors;
}

/** The sentence for one validation error, in the viewer's language. */
function trainingCaseErrorText(array $error): string {
    return t('case.err.' . $error['code'], ['step' => (string) ($error['where'] ?? '')]);
}

function trainingCaseRatingPoints(string $rating): int {
    return TRAINING_CASE_RATING_POINTS[$rating] ?? 0;
}

/** 'excellent' from 80%, 'good' from 50%, else 'practice' (also for a run with nothing answered). */
function trainingCaseScoreLabel(int $points, int $max): string {
    if ($max <= 0) {
        return 'practice';
    }
    $ratio = $points / $max;
    return $ratio >= 0.8 ? 'excellent' : ($ratio >= 0.5 ? 'good' : 'practice');
}

function trainingCaseScorePercent(int $points, int $max): int {
    return $max > 0 ? (int) round(100 * $points / $max) : 0;
}

/** The ideal route through a valid case: from start, the first `best` choice of each step. [[stepId, choiceId], ...]. */
function trainingCaseBestPath(array $doc): array {
    $path = [];
    $stepId = $doc['start'];
    while ($stepId !== null && isset($doc['steps'][$stepId])) {
        $best = null;
        foreach ($doc['steps'][$stepId]['choices'] as $choice) {
            if ($choice['rating'] === 'best') {
                $best = $choice;
                break;
            }
        }
        if ($best === null) {
            break;
        }
        $path[] = [$stepId, $best['id']];
        $stepId = $best['next'];
    }
    return $path;
}
```

- [ ] **Step 4: Register the file and add the error texts**

In `bootstrap.php` after the `functions-training-missions.php` line, and in `tests/bootstrap.php` after the same line, add:
`require_once __DIR__ . '/includes/functions-training-cases.php';` (in `tests/bootstrap.php` use `__DIR__ . '/../includes/functions-training-cases.php'`).

In `includes/lang/war-room.php` add to the `el` block (next to `hero.training_badge`, line ~531) and the `en` block (next to line ~4297):

```php
        // Training cases (v3.505.0): validation messages
        'case.err.unreadable' => 'Το αρχείο ή η φόρμα δεν διαβάστηκε.',
        'case.err.title_missing' => 'Το περιστατικό χρειάζεται τίτλο.',
        'case.err.story_missing' => 'Το περιστατικό χρειάζεται ιστορία.',
        'case.err.no_steps' => 'Χρειάζεται τουλάχιστον ένα βήμα.',
        'case.err.too_many_steps' => 'Πάρα πολλά βήματα (το πολύ 12).',
        'case.err.start_missing' => 'Το βήμα εκκίνησης δεν υπάρχει.',
        'case.err.prompt_missing' => 'Το βήμα «{step}» δεν έχει ερώτηση.',
        'case.err.choice_count' => 'Το βήμα «{step}» χρειάζεται 2 έως 5 επιλογές.',
        'case.err.choice_id' => 'Το βήμα «{step}» έχει επιλογή χωρίς μοναδικό αναγνωριστικό.',
        'case.err.rating_invalid' => 'Το βήμα «{step}» έχει επιλογή με άκυρη βαθμολογία.',
        'case.err.choice_label_missing' => 'Το βήμα «{step}» έχει επιλογή χωρίς κείμενο.',
        'case.err.feedback_missing' => 'Το βήμα «{step}» έχει επιλογή χωρίς σχόλιο.',
        'case.err.next_missing' => 'Το βήμα «{step}» οδηγεί σε βήμα που δεν υπάρχει.',
        'case.err.no_best' => 'Το βήμα «{step}» χρειάζεται τουλάχιστον μία «άριστη» επιλογή.',
        'case.err.loop' => 'Το βήμα «{step}» δημιουργεί κύκλο· το περιστατικό πρέπει να τελειώνει.',
        'case.err.unreachable' => 'Το βήμα «{step}» δεν το φτάνει καμία επιλογή.',
        'case.err.too_big' => 'Το περιστατικό είναι πολύ μεγάλο.',
```
and the English twins in the `en` block:
```php
        'case.err.unreadable' => 'The file or form could not be read.',
        'case.err.title_missing' => 'The case needs a title.',
        'case.err.story_missing' => 'The case needs a story.',
        'case.err.no_steps' => 'At least one step is needed.',
        'case.err.too_many_steps' => 'Too many steps (12 at most).',
        'case.err.start_missing' => 'The start step does not exist.',
        'case.err.prompt_missing' => 'Step "{step}" has no question.',
        'case.err.choice_count' => 'Step "{step}" needs 2 to 5 choices.',
        'case.err.choice_id' => 'Step "{step}" has a choice without a unique id.',
        'case.err.rating_invalid' => 'Step "{step}" has a choice with an invalid rating.',
        'case.err.choice_label_missing' => 'Step "{step}" has a choice with no text.',
        'case.err.feedback_missing' => 'Step "{step}" has a choice with no feedback.',
        'case.err.next_missing' => 'Step "{step}" leads to a step that does not exist.',
        'case.err.no_best' => 'Step "{step}" needs at least one "best" choice.',
        'case.err.loop' => 'Step "{step}" makes a loop; a case must end.',
        'case.err.unreachable' => 'Step "{step}" cannot be reached from any choice.',
        'case.err.too_big' => 'The case is too large.',
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `/c/xampp/php/php.exe vendor/phpunit/phpunit/phpunit tests/TrainingCaseDocumentTest.php`
Expected: PASS (14 tests).

- [ ] **Step 6: Commit**

```bash
git add includes/functions-training-cases.php bootstrap.php tests/bootstrap.php includes/lang/war-room.php tests/TrainingCaseDocumentTest.php
git commit -m "Add the training case document rules and scoring"
```

---

### Task 3: The library - storage, export, import

**Files:**
- Modify: `includes/functions-training-cases.php` (append)
- Test: `tests/TrainingCaseLibraryTest.php` (new)

**Interfaces:**
- Consumes: Task 2 (`trainingCaseNormalize`, `trainingCaseValidate`, `TRAINING_CASE_*`).
- Produces:
  - `trainingCaseMeta(array $meta): array` returns `['type_tag', 'difficulty', 'language', 'est_minutes']` clamped to allowed values (`TRAINING_CASE_TYPES = ['trauma','cardiac','respiratory','neuro','environmental','other']`)
  - `trainingCaseUniqueSlug(string $title): string`
  - `trainingCaseSave(?int $id, array $meta, $rawDoc, int $userId): array` returns `['ok' => true, 'id' => int]` or `['ok' => false, 'errors' => [...]]` (an error `['code' => 'starter_locked'|'not_found', 'where' => '']` for those two cases)
  - `trainingCaseLoad(int $id): ?array` returns the row plus `'doc'` (decoded document)
  - `trainingCaseList(array $filter = []): array` rows without the document; filter keys `type`, `difficulty`, `language`, `include_archived`
  - `trainingCaseDuplicate(int $id, int $userId): array` same return shape as save
  - `trainingCaseArchive(int $id, bool $archive): void`
  - `trainingCaseSetReviewed(int $id, bool $reviewed, int $userId): void`
  - `trainingCaseExport(array $ids): string` (JSON pack text)
  - `trainingCaseImportRead(string $json): array` returns `['ok' => false, 'error' => key]` or `['ok' => true, 'cases' => [['title', 'meta', 'doc', 'errors', 'clash']]]` (the preview)
  - `trainingCaseImport(string $json, int $userId): array` returns `['saved' => n, 'skipped' => n, 'ids' => [...]]`

- [ ] **Step 1: Write the failing test**

```php
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

    public function test_editing_resets_the_reviewed_flag_and_a_starter_cannot_be_edited(): void
    {
        $saved = trainingCaseSave(null, [], $this->doc(), $this->admin);
        trainingCaseSetReviewed($saved['id'], true, $this->admin);
        $this->assertSame(1, (int) trainingCaseLoad($saved['id'])['reviewed']);
        $this->assertTrue(trainingCaseSave($saved['id'], [], $this->doc('Νέος'), $this->admin)['ok']);
        $this->assertSame(0, (int) trainingCaseLoad($saved['id'])['reviewed']);

        dbExecute("UPDATE training_cases SET is_starter = 1 WHERE id = ?", [$saved['id']]);
        $locked = trainingCaseSave($saved['id'], [], $this->doc('Άλλος'), $this->admin);
        $this->assertFalse($locked['ok']);
        $this->assertSame('starter_locked', $locked['errors'][0]['code']);
    }

    public function test_duplicate_makes_an_editable_unreviewed_copy(): void
    {
        $saved = trainingCaseSave(null, [], $this->doc('Πρωτότυπο'), $this->admin);
        dbExecute("UPDATE training_cases SET is_starter = 1, reviewed = 1 WHERE id = ?", [$saved['id']]);
        $copy = trainingCaseDuplicate($saved['id'], $this->admin);
        $row = trainingCaseLoad($copy['id']);
        $this->assertSame(0, (int) $row['is_starter']);
        $this->assertSame(0, (int) $row['reviewed']);
        $this->assertStringContainsString('Πρωτότυπο', $row['doc']['title']);
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
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `/c/xampp/php/php.exe vendor/phpunit/phpunit/phpunit tests/TrainingCaseLibraryTest.php`
Expected: FAIL (`Call to undefined function trainingCaseSave`).

- [ ] **Step 3: Append to `includes/functions-training-cases.php`**

```php
const TRAINING_CASE_TYPES = ['trauma', 'cardiac', 'respiratory', 'neuro', 'environmental', 'other'];
const TRAINING_CASE_MAX_IMPORT_BYTES = 200000;
const TRAINING_CASE_PACK_FORMAT = 'volunteerops-training-cases';

/** type, difficulty (1 to 3), language (el|en) and minutes (1 to 30), clamped to what the library allows. */
function trainingCaseMeta(array $meta): array {
    $type = (string) ($meta['type_tag'] ?? 'other');
    $lang = (string) ($meta['language'] ?? 'el');
    return [
        'type_tag'    => in_array($type, TRAINING_CASE_TYPES, true) ? $type : 'other',
        'difficulty'  => max(1, min(3, (int) ($meta['difficulty'] ?? 1))),
        'language'    => in_array($lang, ['el', 'en'], true) ? $lang : 'el',
        'est_minutes' => max(1, min(30, (int) ($meta['est_minutes'] ?? 5))),
    ];
}

/** A slug nobody uses yet. Greek titles have no latin letters, so the base falls back to 'case'. */
function trainingCaseUniqueSlug(string $title, string $preferred = ''): string {
    $base = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', $preferred !== '' ? $preferred : $title), '-'));
    if ($base === '') {
        $base = 'case';
    }
    $base = substr($base, 0, 60);
    $slug = $base;
    $n = 1;
    while (dbFetchValue("SELECT 1 FROM training_cases WHERE slug = ?", [$slug])) {
        $n++;
        $slug = $base . '-' . $n;
    }
    return $slug;
}

/**
 * Creates ($id null) or edits a case. The document is normalised and validated first; nothing is written when it
 * has errors. Editing resets `reviewed` (the content changed) and a starter case cannot be edited, only duplicated.
 */
function trainingCaseSave(?int $id, array $meta, $rawDoc, int $userId): array {
    $doc = trainingCaseNormalize($rawDoc);
    if ($doc === null) {
        return ['ok' => false, 'errors' => [['code' => 'unreadable', 'where' => '']]];
    }
    $errors = trainingCaseValidate($doc);
    if ($errors) {
        return ['ok' => false, 'errors' => $errors];
    }
    $fields = trainingCaseMeta($meta);
    $json = json_encode($doc, JSON_UNESCAPED_UNICODE);
    if ($id === null) {
        $newId = (int) dbInsert(
            "INSERT INTO training_cases (slug, title, type_tag, difficulty, language, est_minutes, document, format_version, is_starter, reviewed, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 0, ?)",
            [trainingCaseUniqueSlug($doc['title']), $doc['title'], $fields['type_tag'], $fields['difficulty'], $fields['language'],
             $fields['est_minutes'], $json, TRAINING_CASE_FORMAT_VERSION, $userId]
        );
        logAudit('create_training_case', 'training_cases', $newId, null, ['title' => $doc['title']]);
        return ['ok' => true, 'id' => $newId];
    }
    $row = dbFetchOne("SELECT id, is_starter FROM training_cases WHERE id = ?", [$id]);
    if (!$row) {
        return ['ok' => false, 'errors' => [['code' => 'not_found', 'where' => '']]];
    }
    if ((int) $row['is_starter'] === 1) {
        return ['ok' => false, 'errors' => [['code' => 'starter_locked', 'where' => '']]];
    }
    dbExecute(
        "UPDATE training_cases SET title = ?, type_tag = ?, difficulty = ?, language = ?, est_minutes = ?, document = ?, format_version = ?,
                reviewed = 0, reviewed_by = NULL, reviewed_at = NULL
         WHERE id = ?",
        [$doc['title'], $fields['type_tag'], $fields['difficulty'], $fields['language'], $fields['est_minutes'], $json, TRAINING_CASE_FORMAT_VERSION, $id]
    );
    logAudit('update_training_case', 'training_cases', $id, null, ['title' => $doc['title']]);
    return ['ok' => true, 'id' => $id];
}

/** One case with its document decoded into ['doc'], or null. */
function trainingCaseLoad(int $id): ?array {
    $row = dbFetchOne("SELECT * FROM training_cases WHERE id = ?", [$id]);
    if (!$row) {
        return null;
    }
    $row['doc'] = trainingCaseNormalize(json_decode((string) $row['document'], true)) ?? [];
    return $row;
}

/** The library, without documents: starters first, then by title. Archived ones only with include_archived. */
function trainingCaseList(array $filter = []): array {
    $where = [];
    $args = [];
    if (empty($filter['include_archived'])) {
        $where[] = 'archived_at IS NULL';
    }
    if (!empty($filter['type'])) {
        $where[] = 'type_tag = ?';
        $args[] = (string) $filter['type'];
    }
    if (!empty($filter['difficulty'])) {
        $where[] = 'difficulty = ?';
        $args[] = (int) $filter['difficulty'];
    }
    if (!empty($filter['language'])) {
        $where[] = 'language = ?';
        $args[] = (string) $filter['language'];
    }
    return dbFetchAll(
        "SELECT id, slug, title, type_tag, difficulty, language, est_minutes, is_starter, reviewed, reviewed_at, archived_at
         FROM training_cases" . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . "
         ORDER BY is_starter DESC, title, id",
        $args
    );
}

/** An editable, unreviewed copy of any case (a starter included). */
function trainingCaseDuplicate(int $id, int $userId): array {
    $row = trainingCaseLoad($id);
    if (!$row) {
        return ['ok' => false, 'errors' => [['code' => 'not_found', 'where' => '']]];
    }
    $doc = $row['doc'];
    $doc['title'] = mb_substr($doc['title'] . ' (αντίγραφο)', 0, 160);
    return trainingCaseSave(null, $row, $doc, $userId);
}

function trainingCaseArchive(int $id, bool $archive): void {
    dbExecute("UPDATE training_cases SET archived_at = " . ($archive ? 'NOW()' : 'NULL') . " WHERE id = ?", [$id]);
}

function trainingCaseSetReviewed(int $id, bool $reviewed, int $userId): void {
    if ($reviewed) {
        dbExecute("UPDATE training_cases SET reviewed = 1, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?", [$userId, $id]);
    } else {
        dbExecute("UPDATE training_cases SET reviewed = 0, reviewed_by = NULL, reviewed_at = NULL WHERE id = ?", [$id]);
    }
}

/** One file holding any number of cases: {format, format_version, cases: [{slug, type_tag, difficulty, language, est_minutes, document}]}. */
function trainingCaseExport(array $ids): string {
    $cases = [];
    foreach ($ids as $id) {
        $row = trainingCaseLoad((int) $id);
        if (!$row) {
            continue;
        }
        $cases[] = [
            'slug' => $row['slug'], 'type_tag' => $row['type_tag'], 'difficulty' => (int) $row['difficulty'],
            'language' => $row['language'], 'est_minutes' => (int) $row['est_minutes'], 'document' => $row['doc'],
        ];
    }
    return (string) json_encode(
        ['format' => TRAINING_CASE_PACK_FORMAT, 'format_version' => TRAINING_CASE_FORMAT_VERSION, 'cases' => $cases],
        JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
    );
}

/**
 * Reads an import file WITHOUT saving: ['ok' => false, 'error' => t() key] for a file that is too big, not JSON, not
 * one of ours or from a newer format; else ['ok' => true, 'cases' => [['title', 'meta', 'doc', 'errors', 'clash']]],
 * where `clash` says the slug is already in the library (the import renames it) and `errors` is the validation list.
 * Anything the format does not know is ignored.
 */
function trainingCaseImportRead(string $json): array {
    if (strlen($json) > TRAINING_CASE_MAX_IMPORT_BYTES) {
        return ['ok' => false, 'error' => 'case.import_too_big'];
    }
    $pack = json_decode($json, true);
    if (!is_array($pack) || ($pack['format'] ?? '') !== TRAINING_CASE_PACK_FORMAT || !is_array($pack['cases'] ?? null)) {
        return ['ok' => false, 'error' => 'case.import_unreadable'];
    }
    if ((int) ($pack['format_version'] ?? 0) > TRAINING_CASE_FORMAT_VERSION) {
        return ['ok' => false, 'error' => 'case.import_newer'];
    }
    $out = [];
    foreach (array_slice($pack['cases'], 0, 50) as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $doc = trainingCaseNormalize($entry['document'] ?? null);
        $errors = $doc === null ? [['code' => 'unreadable', 'where' => '']] : trainingCaseValidate($doc);
        $slug = strtolower(trim((string) ($entry['slug'] ?? '')));
        $out[] = [
            'title'  => $doc['title'] ?? '',
            'slug'   => $slug,
            'meta'   => trainingCaseMeta($entry),
            'doc'    => $doc,
            'errors' => $errors,
            'clash'  => $slug !== '' && (bool) dbFetchValue("SELECT 1 FROM training_cases WHERE slug = ?", [$slug]),
        ];
    }
    return ['ok' => true, 'cases' => $out];
}

/** Saves every valid case of an import file as a new, unreviewed, non-starter case. Returns ['saved' => n, 'skipped' => n, 'ids' => []]. */
function trainingCaseImport(string $json, int $userId): array {
    $read = trainingCaseImportRead($json);
    if (!$read['ok']) {
        return ['saved' => 0, 'skipped' => 0, 'ids' => [], 'error' => $read['error']];
    }
    $saved = [];
    $skipped = 0;
    foreach ($read['cases'] as $case) {
        if ($case['errors'] || $case['doc'] === null) {
            $skipped++;
            continue;
        }
        $result = trainingCaseSave(null, $case['meta'], $case['doc'], $userId);
        if ($result['ok']) {
            // trainingCaseSave() names the slug from the title; keep the file's own slug when it is free.
            if ($case['slug'] !== '' && !dbFetchValue("SELECT 1 FROM training_cases WHERE slug = ?", [$case['slug']])) {
                dbExecute("UPDATE training_cases SET slug = ? WHERE id = ?", [substr($case['slug'], 0, 80), $result['id']]);
            }
            $saved[] = $result['id'];
        } else {
            $skipped++;
        }
    }
    return ['saved' => count($saved), 'skipped' => $skipped, 'ids' => $saved];
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `/c/xampp/php/php.exe vendor/phpunit/phpunit/phpunit tests/TrainingCaseLibraryTest.php`
Expected: PASS (9 tests).

- [ ] **Step 5: Add the import messages and commit**

Add to both language blocks: `'case.import_too_big'` (el «Το αρχείο είναι πολύ μεγάλο.» / en "The file is too large."), `'case.import_unreadable'` (el «Το αρχείο δεν είναι έγκυρο αρχείο περιστατικών.» / en "That is not a valid case file."), `'case.import_newer'` (el «Το αρχείο είναι από νεότερη έκδοση της εφαρμογής.» / en "The file comes from a newer version of the app.").

```bash
git add includes/functions-training-cases.php includes/lang/war-room.php tests/TrainingCaseLibraryTest.php
git commit -m "Add the training case library with export and import"
```

---

### Task 4: Starter cases and the random draw

**Files:**
- Create: `includes/training-cases/starter-unresponsive-adult.json`, `starter-chest-pain.json`, `starter-heat-collapse.json`
- Modify: `includes/functions-training-cases.php` (append `trainingCaseSeedStarters`, `trainingCaseDraw`)
- Test: `tests/TrainingCaseStarterTest.php` (new)

**Interfaces:**
- Consumes: Tasks 2 and 3.
- Produces: `trainingCaseSeedStarters(): int` (rows inserted or updated; idempotent by slug; starter rows become `is_starter = 1`, `reviewed = 0`); `trainingCaseDraw(array $filter, array $excludeCaseIds = [], bool $allowUnreviewed = false): ?array` (a `trainingCaseLoad()` row or null; `$filter` keys `type`, `difficulty`, `language`).

Starter file format: one case per file, the same as one entry of an export pack: `{"slug": "...", "type_tag": "...", "difficulty": 1, "language": "el", "est_minutes": 5, "document": {...}}`.

- [ ] **Step 1: Write the failing test**

```php
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
```

- [ ] **Step 2: Run it to verify it fails**

Run: `/c/xampp/php/php.exe vendor/phpunit/phpunit/phpunit tests/TrainingCaseStarterTest.php`
Expected: FAIL (`Failed asserting that actual size 0 matches expected size 3` for the files).

- [ ] **Step 3: Write the three starter files**

Each file is plain JSON (UTF-8, LF). Write real content in Greek, 5 to 7 steps, a branch in at least one step, three or four choices per step with plausible wrong answers, feedback of one or two sentences that teaches, closing lessons of 3 to 4 short sentences. They are first-aid / volunteer-rescue exercises, **not** clinical protocols: keep them to widely taught first-aid actions (check scene safety and responsiveness, airway and breathing, call 166 or 112, recovery position, stop bleeding, keep the person still and warm, cool a heat casualty, do not give food or drink to an unconscious person). Each document must have `title`, `story`, `closing`, a `findings` list (3 to 6 rows with label, value, unit) and the step map. Use step ids `s1` to `s7`.

1. `starter-unresponsive-adult.json`: slug `starter-unresponsive-adult`, `type_tag` `trauma` is wrong for this; use `neuro`, difficulty 2, `est_minutes` 6. Story: the user's own example, a man about 40 found in front of the volunteer, no obvious injuries, breathing, pulse 140, very low blood pressure, not responding. Steps: scene safety and responsiveness; airway and breathing check; call for an ambulance (166) and what to report; recovery position versus leaving him flat (branch); a bystander offers a stretcher car, what do you decide (move or wait); handover to the ambulance crew.
2. `starter-chest-pain.json`: slug `starter-chest-pain`, `type_tag` `cardiac`, difficulty 2, `est_minutes` 5. Story: a hiker, 58, sits down with crushing chest pain, sweating, pale. Steps: first action; position; call; what to give or not give; he collapses and stops breathing (branch to CPR steps at the level of "start chest compressions and send for a defibrillator"); handover.
3. `starter-heat-collapse.json`: slug `starter-heat-collapse`, `type_tag` `environmental`, difficulty 1, `est_minutes` 5. Story: a runner collapses at a summer event, hot, confused. Steps: scene and responsiveness; move to shade; cooling actions; fluids when confused (do not force drink); when to call 166; monitoring until the ambulance.

Mark in the file's `closing` of each case nothing about review; review is the `reviewed` flag in the database.

- [ ] **Step 4: Append the seeder and the draw to `includes/functions-training-cases.php`**

```php
/**
 * Loads the starter cases shipped in includes/training-cases/*.json into the library, by slug. A new slug is inserted
 * as an unreviewed starter; an existing starter whose document differs from the file is updated and goes back to
 * unreviewed (the content changed, so the medical check no longer applies); anything else is left alone. Never touches
 * a case that is not a starter. Returns how many rows were inserted or updated. Cheap enough to call when the library
 * or the staging list opens.
 */
function trainingCaseSeedStarters(): int {
    $changed = 0;
    foreach (glob(__DIR__ . '/training-cases/*.json') ?: [] as $file) {
        $entry = json_decode((string) file_get_contents($file), true);
        $doc = is_array($entry) ? trainingCaseNormalize($entry['document'] ?? null) : null;
        $slug = is_array($entry) ? strtolower(trim((string) ($entry['slug'] ?? ''))) : '';
        if ($doc === null || $slug === '' || trainingCaseValidate($doc)) {
            error_log('[training-cases] starter file ' . basename($file) . ' is not a valid case');
            continue;
        }
        $meta = trainingCaseMeta($entry);
        $json = json_encode($doc, JSON_UNESCAPED_UNICODE);
        $existing = dbFetchOne("SELECT id, is_starter, document FROM training_cases WHERE slug = ?", [$slug]);
        if (!$existing) {
            dbInsert(
                "INSERT INTO training_cases (slug, title, type_tag, difficulty, language, est_minutes, document, format_version, is_starter, reviewed)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, 0)",
                [$slug, $doc['title'], $meta['type_tag'], $meta['difficulty'], $meta['language'], $meta['est_minutes'], $json, TRAINING_CASE_FORMAT_VERSION]
            );
            $changed++;
        } elseif ((int) $existing['is_starter'] === 1 && $existing['document'] !== $json) {
            dbExecute(
                "UPDATE training_cases SET title = ?, type_tag = ?, difficulty = ?, language = ?, est_minutes = ?, document = ?,
                        format_version = ?, reviewed = 0, reviewed_by = NULL, reviewed_at = NULL WHERE id = ?",
                [$doc['title'], $meta['type_tag'], $meta['difficulty'], $meta['language'], $meta['est_minutes'], $json, TRAINING_CASE_FORMAT_VERSION, (int) $existing['id']]
            );
            $changed++;
        }
    }
    return $changed;
}

/**
 * Picks one case from the library for "random": not archived, matching the filters (type, difficulty, language), reviewed
 * unless $allowUnreviewed. Cases already staged in this scenario ($excludeCaseIds) are avoided while others remain; when
 * nothing else is left the whole pool is used. Returns a trainingCaseLoad() row, or null when the pool is empty.
 */
function trainingCaseDraw(array $filter, array $excludeCaseIds = [], bool $allowUnreviewed = false): ?array {
    $where = ['archived_at IS NULL'];
    $args = [];
    if (!empty($filter['type'])) {
        $where[] = 'type_tag = ?';
        $args[] = (string) $filter['type'];
    }
    if (!empty($filter['difficulty'])) {
        $where[] = 'difficulty = ?';
        $args[] = (int) $filter['difficulty'];
    }
    if (!empty($filter['language'])) {
        $where[] = 'language = ?';
        $args[] = (string) $filter['language'];
    }
    if (!$allowUnreviewed) {
        $where[] = 'reviewed = 1';
    }
    $ids = array_map('intval', array_column(dbFetchAll("SELECT id FROM training_cases WHERE " . implode(' AND ', $where), $args), 'id'));
    if (!$ids) {
        return null;
    }
    $fresh = array_values(array_diff($ids, array_map('intval', $excludeCaseIds)));
    $pool = $fresh ?: $ids;
    return trainingCaseLoad($pool[random_int(0, count($pool) - 1)]);
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `/c/xampp/php/php.exe vendor/phpunit/phpunit/phpunit tests/TrainingCaseStarterTest.php`
Expected: PASS (6 tests).

- [ ] **Step 6: Commit**

```bash
git add includes/training-cases includes/functions-training-cases.php tests/TrainingCaseStarterTest.php
git commit -m "Add three starter training cases, their seeder and the random draw"
```

---

### Task 5: Staging and playing a case in the scenario

**Files:**
- Create: `includes/functions-training-case-runs.php` (placement functions only in this task)
- Modify: `bootstrap.php`, `tests/bootstrap.php` (require the new file after `functions-training-cases.php`)
- Modify: `includes/functions-scenario.php` (`addScenarioDispatchItem` at line 839; `scenarioSendItem` at line 1750; `loadMissionScenario` item building)
- Modify: `mission-scenario.php` (`add_item` at line 108; new `case_list` and `case_draw` actions)
- Test: `tests/TrainingCaseScenarioTest.php` (new)

**Interfaces:**
- Consumes: Tasks 2 to 4.
- Produces:
  - `addScenarioDispatchItem(array $mission, int $phaseId, int $userId, array $input, array $extraPayload = []): array` (new optional 5th parameter, merged into the payload; payload now encoded with `JSON_UNESCAPED_UNICODE`)
  - `addScenarioCaseItem(array $mission, int $phaseId, int $userId, array $input): array` where `$input` = the dispatch form (`team_id`, `type`, `label`, `geo`, `travel_mode`) plus `case_id`, `case_mode` (`chosen|random`), `case_pool` (array), `briefing`; returns `['ok' => true, 'id' => n]` or `['ok' => false, 'error' => text]`
  - `scenarioStagedCaseIds(int $missionId): array` (library case ids already staged in this mission's scenario)
  - `trainingCaseCreatePlacement(int $missionId, int $itemId, int $dispatchId, array $payload): ?int`
  - `trainingCasePlacement(int $placementId): ?array` and `trainingCasePlacementForDispatch(int $dispatchId): ?array` (row plus `'doc'`)
  - payload shape: `$payload['case'] = ['case_id' => int, 'format_version' => int, 'title' => string, 'draw_mode' => 'chosen'|'random', 'pool' => array, 'briefing' => string, 'document' => array]`

- [ ] **Step 1: Write the failing test**

Model the fixtures on `tests/ScenarioOrderTest.php` setUp (a mission with a shift, a team with an on-duty member in the Action Room, a scenario phase). Use `TrainingFixtures::mission(true)` for the drill and `TrainingFixtures::mission(false)` for the real mission.

```php
<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Staging a case (a point order carrying a snapshot) and playing it (the order is sent, a placement is recorded). */
final class TrainingCaseScenarioTest extends TestCase
{
    private int $admin;
    private array $drill;
    private array $real;
    private int $teamId;
    private int $caseId;

    protected function setUp(): void
    {
        db()->beginTransaction();
        $this->admin = TrainingFixtures::user('Case Stager');
        $this->drill = TrainingFixtures::mission(true);
        $this->real = TrainingFixtures::mission(false);
        $this->teamId = (int) dbInsert(
            "INSERT INTO mission_teams (mission_id, codename, team_number) VALUES (?, 'ΑΛΦΑ', 1)",
            [$this->drill['mission']]
        );
        trainingCaseSeedStarters();
        $this->caseId = (int) dbFetchValue("SELECT id FROM training_cases WHERE is_starter = 1 ORDER BY id LIMIT 1");
    }

    protected function tearDown(): void
    {
        db()->rollBack();
    }

    private function mission(array $m): array
    {
        return ['id' => $m['mission'], 'title' => 'T', 'status' => STATUS_OPEN, 'responsible_user_id' => $this->admin];
    }

    private function phase(array $m): int
    {
        return (int) addScenarioPhase($this->mission($m), $this->admin, '')['id'];
    }

    private function form(array $extra = []): array
    {
        return $extra + [
            'team_id' => (string) $this->teamId, 'type' => 'point', 'geo' => json_encode(['lat' => 35.33, 'lng' => 25.14]),
            'label' => '', 'travel_mode' => 'foot', 'case_id' => $this->caseId, 'case_mode' => 'chosen',
        ];
    }

    public function test_staging_stores_a_snapshot_in_an_ordinary_dispatch_item(): void
    {
        $phase = $this->phase($this->drill);
        $result = addScenarioCaseItem($this->mission($this->drill), $phase, $this->admin, $this->form(['briefing' => 'Ακούστηκε φωνή']));
        $this->assertTrue($result['ok'], (string) ($result['error'] ?? ''));
        $row = dbFetchOne("SELECT kind, payload FROM mission_scenario_items WHERE id = ?", [$result['id']]);
        $this->assertSame('dispatch', $row['kind']);
        $payload = json_decode($row['payload'], true);
        $this->assertSame($this->caseId, $payload['case']['case_id']);
        $this->assertSame('chosen', $payload['case']['draw_mode']);
        $this->assertSame('Ακούστηκε φωνή', $payload['case']['briefing']);
        $this->assertNotEmpty($payload['case']['document']['steps']);
        $this->assertSame('point', $payload['type']);
        $this->assertNotSame('', $payload['label'], 'a neutral label is filled in so the order never spoils the case');
    }

    public function test_the_snapshot_does_not_change_when_the_library_case_is_edited(): void
    {
        $phase = $this->phase($this->drill);
        $result = addScenarioCaseItem($this->mission($this->drill), $phase, $this->admin, $this->form());
        $before = dbFetchValue("SELECT payload FROM mission_scenario_items WHERE id = ?", [$result['id']]);
        dbExecute("UPDATE training_cases SET document = REPLACE(document, 'story', 'sXXXX') WHERE id = ?", [$this->caseId]);
        $this->assertSame($before, dbFetchValue("SELECT payload FROM mission_scenario_items WHERE id = ?", [$result['id']]));
    }

    public function test_a_real_mission_refuses_a_case(): void
    {
        $phase = $this->phase($this->real);
        $result = addScenarioCaseItem($this->mission($this->real), $phase, $this->admin, $this->form(['team_id' => 'all']));
        $this->assertFalse($result['ok']);
        $this->assertSame(0, (int) dbFetchValue("SELECT COUNT(*) FROM mission_scenario_items WHERE phase_id = ?", [$phase]));
    }

    public function test_an_area_or_a_missing_or_archived_case_is_refused(): void
    {
        $phase = $this->phase($this->drill);
        $m = $this->mission($this->drill);
        $this->assertFalse(addScenarioCaseItem($m, $phase, $this->admin, $this->form(['type' => 'polygon']))['ok']);
        $this->assertFalse(addScenarioCaseItem($m, $phase, $this->admin, $this->form(['case_id' => 999999]))['ok']);
        trainingCaseArchive($this->caseId, true);
        $this->assertFalse(addScenarioCaseItem($m, $phase, $this->admin, $this->form())['ok']);
    }

    public function test_scenario_staged_case_ids_lists_what_is_already_in_the_plan(): void
    {
        $phase = $this->phase($this->drill);
        addScenarioCaseItem($this->mission($this->drill), $phase, $this->admin, $this->form());
        $this->assertSame([$this->caseId], scenarioStagedCaseIds($this->drill['mission']));
    }

    public function test_play_sends_the_order_and_records_the_placement(): void
    {
        $member = TrainingFixtures::user('Member');
        dbExecute("INSERT INTO mission_team_members (mission_id, team_id, user_id) VALUES (?, ?, ?)", [$this->drill['mission'], $this->teamId, $member]);
        dbExecute("UPDATE shifts SET start_time = ?, end_time = ? WHERE id = ?", [date('Y-m-d H:i:s', time() - 3600), date('Y-m-d H:i:s', time() + 3600), $this->drill['shift']]);
        TrainingFixtures::participation($this->drill['shift'], $member, false, null);
        dbExecute("INSERT INTO mission_action_room_participants (mission_id, user_id) VALUES (?, ?)", [$this->drill['mission'], $member]);

        $m = $this->mission($this->drill);
        $phase = $this->phase($this->drill);
        $staged = addScenarioCaseItem($m, $phase, $this->admin, $this->form(['briefing' => 'Βρέθηκε άνδρας']));
        $played = playScenarioPhase($m, $phase, $this->admin, 'Case Stager');

        $this->assertTrue($played['ok'], (string) ($played['error'] ?? ''));
        $this->assertSame(1, $played['sent']);
        $item = dbFetchOne("SELECT sent_ref_id FROM mission_scenario_items WHERE id = ?", [$staged['id']]);
        $dispatchId = (int) $item['sent_ref_id'];
        $placement = trainingCasePlacementForDispatch($dispatchId);
        $this->assertNotNull($placement);
        $this->assertSame($this->drill['mission'], (int) $placement['mission_id']);
        $this->assertSame($staged['id'], (int) $placement['scenario_item_id']);
        $this->assertSame($this->teamId, (int) $placement['team_id']);
        $this->assertSame('team', $placement['audience']);
        $this->assertNotEmpty($placement['doc']['steps']);
    }

    public function test_an_order_to_every_team_that_is_split_makes_one_placement_per_team(): void
    {
        $teamB = (int) dbInsert("INSERT INTO mission_teams (mission_id, codename, team_number) VALUES (?, 'ΒΗΤΑ', 2)", [$this->drill['mission']]);
        foreach ([$this->teamId, $teamB] as $team) {
            $u = TrainingFixtures::user('M' . $team);
            dbExecute("INSERT INTO mission_team_members (mission_id, team_id, user_id) VALUES (?, ?, ?)", [$this->drill['mission'], $team, $u]);
        }
        $m = $this->mission($this->drill);
        $phase1 = $this->phase($this->drill);
        $phase2 = $this->phase($this->drill);
        // Phase 1 holds team A's task; team A has not finished it, so a phase-2 order to everyone is split per team.
        addScenarioDispatchItem($m, $phase1, $this->admin, ['team_id' => (string) $this->teamId, 'type' => 'point', 'geo' => json_encode(['lat' => 35.3, 'lng' => 25.1]), 'label' => 'x']);
        addScenarioCaseItem($m, $phase2, $this->admin, $this->form(['team_id' => 'all']));
        playScenarioPhase($m, $phase1, $this->admin, 'A');
        $played = playScenarioPhase($m, $phase2, $this->admin, 'A');
        $this->assertTrue($played['ok']);
        $placements = (int) dbFetchValue("SELECT COUNT(*) FROM mission_case_placements WHERE mission_id = ?", [$this->drill['mission']]);
        $this->assertSame(1, $placements, 'only team B was ready: its child order is sent now, team A\'s waits for its phase');
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `/c/xampp/php/php.exe vendor/phpunit/phpunit/phpunit tests/TrainingCaseScenarioTest.php`
Expected: FAIL (`Call to undefined function addScenarioCaseItem`).

- [ ] **Step 3: Create `includes/functions-training-case-runs.php` with the placement functions**

```php
<?php
/**
 * VolunteerOps - Training cases: placements, runs, answers (v3.505.0).
 *
 * A PLACEMENT links one sent point order (mission_dispatch_points) to the snapshot of the case staged for it. A RUN is
 * one volunteer's attempt at a placement; an ANSWER is one decision in a run. Everything is keyed by the mission and
 * works only in training missions. See functions-training-cases.php for the document rules.
 */

if (!defined('VOLUNTEEROPS')) {
    die('Direct access not permitted');
}

/**
 * Records the placement of a case whose point order has just been sent. $payload is the staged item's payload (its
 * `case` block holds the snapshot); who it is for is read from the live order (team_id NULL = every team). Returns
 * the placement id, or null when the payload carries no case.
 */
function trainingCaseCreatePlacement(int $missionId, int $itemId, int $dispatchId, array $payload): ?int {
    $case = $payload['case'] ?? null;
    if (!is_array($case) || !is_array($case['document'] ?? null)) {
        return null;
    }
    $teamId = dbFetchValue("SELECT team_id FROM mission_dispatch_points WHERE id = ? AND mission_id = ?", [$dispatchId, $missionId]);
    $caseId = (int) ($case['case_id'] ?? 0);
    return (int) dbInsert(
        "INSERT INTO mission_case_placements (mission_id, scenario_item_id, dispatch_id, case_id, title, snapshot, audience, team_id, briefing, draw_mode)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        [
            $missionId, $itemId, $dispatchId,
            $caseId > 0 && dbFetchValue("SELECT 1 FROM training_cases WHERE id = ?", [$caseId]) ? $caseId : null,
            mb_substr((string) ($case['title'] ?? ($case['document']['title'] ?? '')), 0, 160),
            json_encode($case['document'], JSON_UNESCAPED_UNICODE),
            $teamId !== null && $teamId !== false ? 'team' : 'all',
            $teamId !== null && $teamId !== false ? (int) $teamId : null,
            mb_substr((string) ($case['briefing'] ?? ''), 0, 300),
            ($case['draw_mode'] ?? '') === 'random' ? 'random' : 'chosen',
        ]
    );
}

/** A placement with its snapshot decoded into ['doc'], or null. */
function trainingCasePlacement(int $placementId): ?array {
    $row = dbFetchOne("SELECT * FROM mission_case_placements WHERE id = ?", [$placementId]);
    return $row ? trainingCasePlacementWithDoc($row) : null;
}

function trainingCasePlacementForDispatch(int $dispatchId): ?array {
    $row = dbFetchOne("SELECT * FROM mission_case_placements WHERE dispatch_id = ?", [$dispatchId]);
    return $row ? trainingCasePlacementWithDoc($row) : null;
}

function trainingCasePlacementWithDoc(array $row): array {
    $row['doc'] = trainingCaseNormalize(json_decode((string) $row['snapshot'], true)) ?? [];
    return $row;
}
```

Add `require_once` of this file to `bootstrap.php` and `tests/bootstrap.php` after the `functions-training-cases.php` line.

- [ ] **Step 4: Change `addScenarioDispatchItem` and add the case functions in `includes/functions-scenario.php`**

(a) Edit the signature and the payload encoding of `addScenarioDispatchItem` (line 839 onward). Change `function addScenarioDispatchItem(array $mission, int $phaseId, int $userId, array $input): array {` to take `array $extraPayload = []` as a fifth parameter. After the block that fills `$payload['slot_id']` / `['slot_label']`, add `if ($extraPayload) { $payload += $extraPayload; }`, and change `json_encode($payload)` in the INSERT to `json_encode($payload, JSON_UNESCAPED_UNICODE)`. Add `Update the docblock: "$extraPayload is merged into the payload (a staged case's `case` block)."`

(b) Append after `addScenarioDispatchItem`:

```php
/**
 * Stages a training case at a point: the same order «Στο σενάριο» writes for a point, plus a `case` block holding a
 * SNAPSHOT of the library case (so a later library edit never changes a prepared drill). $input is the dispatch form
 * (team_id, type, label, geo, travel_mode) plus case_id, case_mode ('chosen'|'random', only recorded), case_pool (the
 * filter a random draw used, only recorded) and briefing (a short line the people receive with the order). Only in a
 * training mission, only at a POINT, and only a case that exists and is not archived. An empty label becomes the neutral
 * «Περιστατικό», which never gives the case away. ['ok' => true, 'id' => n] or ['ok' => false, 'error' => text].
 */
function addScenarioCaseItem(array $mission, int $phaseId, int $userId, array $input): array {
    if (!isTrainingMission($mission)) {
        return ['ok' => false, 'error' => t('case.err_not_training')];
    }
    if (($input['type'] ?? '') !== 'point') {
        return ['ok' => false, 'error' => t('case.err_needs_point')];
    }
    $case = trainingCaseLoad((int) ($input['case_id'] ?? 0));
    if (!$case || $case['archived_at'] !== null || trainingCaseValidate($case['doc'])) {
        return ['ok' => false, 'error' => t('case.err_not_found')];
    }
    if (trim((string) ($input['label'] ?? '')) === '') {
        $input['label'] = t('case.default_label');
    }
    $pool = is_array($input['case_pool'] ?? null) ? array_intersect_key($input['case_pool'], array_flip(['type', 'difficulty', 'language'])) : [];
    return addScenarioDispatchItem($mission, $phaseId, $userId, $input, ['case' => [
        'case_id'        => (int) $case['id'],
        'format_version' => (int) $case['format_version'],
        'title'          => $case['title'],
        'draw_mode'      => ($input['case_mode'] ?? '') === 'random' ? 'random' : 'chosen',
        'pool'           => $pool,
        'briefing'       => trainingCasePlain($input['briefing'] ?? '', 300),
        'document'       => $case['doc'],
    ]]);
}

/** The library ids of the cases already staged in this mission's scenario (so a random draw prefers others). */
function scenarioStagedCaseIds(int $missionId): array {
    $ids = [];
    foreach (dbFetchAll("SELECT payload FROM mission_scenario_items WHERE mission_id = ? AND kind = 'dispatch' AND payload LIKE '%\"case\":%'", [$missionId]) as $row) {
        $payload = json_decode((string) $row['payload'], true);
        $caseId = (int) ($payload['case']['case_id'] ?? 0);
        if ($caseId > 0) {
            $ids[$caseId] = true;
        }
    }
    return array_map('intval', array_keys($ids));
}
```

(c) In `scenarioSendItem` (line 1800 onward), the `try { $result = match ($kind) {...} }` block ends with the `catch`. Directly after that whole `try/catch` (before `if ($result['ok']) {` at line 1815) add:

```php
    // A staged TRAINING CASE rides on an ordinary point order (v3.505.0): once the order exists, record which case it carries.
    if (($result['ok'] ?? false) && $kind === 'dispatch' && is_array($payload) && !empty($payload['case'])) {
        try {
            trainingCaseCreatePlacement((int) $mission['id'], $itemId, (int) $result['id'], $payload);
        } catch (Throwable $e) {
            // The order and the arrival still work; the volunteers just get no case. Command sees the log.
            error_log('[scenario] recording the case of item ' . $itemId . ' failed: ' . $e->getMessage());
        }
    }
```

(d) In `loadMissionScenario` find where each item array is built for a `dispatch` item (search for `'travel_mode'` inside the function) and add `'case_title' => is_array($payload) && !empty($payload['case']) ? (string) ($payload['case']['title'] ?? '') : null,` and `'case_briefing' => ...same shape with 'briefing'`. If `$payload` is not already decoded in that loop, decode it once at the top of the loop body (`$payload = json_decode((string) $row['payload'], true);`).

- [ ] **Step 5: `mission-scenario.php` actions**

In the `add_item` action (line 108), replace the `addScenarioDispatchItem(...)` call with:

```php
    $form = [
        'team_id'     => post('team_id'),
        'type'        => post('type'),
        'label'       => post('label'),
        'geo'         => post('geo'),
        'travel_mode' => post('travel_mode'),
    ];
    if (post('case_id') !== '' && post('case_id') !== null) {
        $pool = json_decode((string) post('case_pool'), true);
        $result = addScenarioCaseItem($mission, $phaseId, (int) $userId, $form + [
            'case_id'   => post('case_id'),
            'case_mode' => post('case_mode'),
            'case_pool' => is_array($pool) ? $pool : [],
            'briefing'  => post('briefing'),
        ]);
    } else {
        $result = addScenarioDispatchItem($mission, $phaseId, (int) $userId, $form);
    }
```

Before the final `echo json_encode(['ok' => false, 'error' => t('common.unknown_action')]);` add two read-only actions:

```php
if ($action === 'case_list') {
    // The library for the staging picker: seeded on first use, no documents.
    trainingCaseSeedStarters();
    echo json_encode(['ok' => true, 'cases' => array_map(fn($c) => [
        'id' => (int) $c['id'], 'title' => $c['title'], 'type' => $c['type_tag'], 'difficulty' => (int) $c['difficulty'],
        'language' => $c['language'], 'minutes' => (int) $c['est_minutes'], 'starter' => (bool) $c['is_starter'], 'reviewed' => (bool) $c['reviewed'],
    ], trainingCaseList()), 'training' => isTrainingMission($mission)]);
    exit;
}

if ($action === 'case_draw') {
    // «Τυχαίο»: one case from the library, preferring ones not yet staged here. Nothing is saved; the commander sees it
    // and either accepts it (staging it with case_mode=random) or draws again.
    trainingCaseSeedStarters();
    $filter = array_filter([
        'type' => (string) post('type'), 'difficulty' => (int) post('difficulty'), 'language' => (string) post('language'),
    ]);
    $case = trainingCaseDraw($filter, scenarioStagedCaseIds($missionId), !empty(post('allow_unreviewed')));
    echo $case
        ? json_encode(['ok' => true, 'case' => ['id' => (int) $case['id'], 'title' => $case['title'], 'type' => $case['type_tag'], 'difficulty' => (int) $case['difficulty'], 'reviewed' => (bool) $case['reviewed']]])
        : json_encode(['ok' => false, 'error' => t('case.err_pool_empty')]);
    exit;
}
```

- [ ] **Step 6: Add the texts**

Add to both language blocks: `case.err_not_training` (el «Τα περιστατικά λειτουργούν μόνο σε εκπαιδευτικές ασκήσεις.» / en "Cases only work in training exercises."), `case.err_needs_point` (el «Το περιστατικό μπαίνει σε σημείο, όχι σε περιοχή.» / en "A case goes at a point, not an area."), `case.err_not_found` (el «Το περιστατικό δεν βρέθηκε ή είναι αρχειοθετημένο.» / en "The case was not found or is archived."), `case.err_pool_empty` (el «Δεν υπάρχει περιστατικό που να ταιριάζει. Δοκίμασε χωρίς φίλτρα ή συμπερίλαβε τα μη ελεγμένα.» / en "No case matches. Try without filters or include the unreviewed ones."), `case.default_label` (el «Περιστατικό» / en "Case").

- [ ] **Step 7: Run the tests**

Run: `/c/xampp/php/php.exe vendor/phpunit/phpunit/phpunit tests/TrainingCaseScenarioTest.php`
Expected: PASS (7 tests). Then run the existing scenario suite to confirm nothing regressed: `/c/xampp/php/php.exe vendor/phpunit/phpunit/phpunit --filter "Scenario"` (expected: only the 2 baseline failures, `ScenarioAreaTest` and `ScenarioBuildingTest`).

- [ ] **Step 8: Commit**

```bash
git add includes/functions-training-case-runs.php includes/functions-scenario.php mission-scenario.php bootstrap.php tests/bootstrap.php includes/lang/war-room.php tests/TrainingCaseScenarioTest.php
git commit -m "Stage a training case in the scenario and record its placement at play"
```

---

### Task 6: Runs, answers, availability and the command overview (server)

**Files:**
- Modify: `includes/functions-training-case-runs.php` (append)
- Modify: `includes/functions-warroom.php` (`loadMissionDispatchesForUser` at line 1495; `advanceMissionDispatch` at line 5048)
- Test: `tests/TrainingCaseRunTest.php` (new)

**Interfaces:**
- Consumes: Task 5 (`trainingCasePlacement`, `trainingCasePlacementForDispatch`), Task 2 (scoring).
- Produces:
  - `trainingCaseGate(array $placement, int $userId, bool $needArrival): ?string` returns `null` when allowed, else a `t()` key (`case.err_not_training`, `case.err_gone`, `case.err_not_participant`, `case.err_not_audience`, `case.err_not_arrived`)
  - `trainingCaseStartRun(int $placementId, int $userId): array` returns `['ok' => true, 'run' => runState]` or `['ok' => false, 'error' => text]`; idempotent
  - `trainingCaseAnswer(int $placementId, int $userId, string $stepId, string $choiceId, ?int $msTaken = null): array` returns `['ok' => true, 'duplicate' => bool, 'step_id', 'choice_id', 'rating', 'feedback', 'next' => string|null, 'finished' => bool, 'score_points' => int, 'score_max' => int, 'percent' => int, 'label' => 'excellent'|'good'|'practice']` or `['ok' => false, 'error' => text]`
  - `trainingCaseRunState(int $placementId, int $userId): ?array` returns `['current_step', 'finished', 'score_points', 'score_max', 'percent', 'label', 'answers' => [['step_id','choice_id','rating']...]]` or null when the volunteer has no run
  - `trainingCaseCardsForDispatches(array $dispatchIds, int $userId): array` returns `dispatch id => ['placement_id', 'title', 'briefing', 'state' => 'new'|'running'|'done', 'percent' => int|null, 'label' => string|null]`; returns `[]` on any database error
  - `trainingCaseNotifyArrival(int $missionId, int $dispatchId, ?int $teamId, int $arrivedUserId): void`
  - `trainingCaseOverview(int $missionId): array` (see Step 3; each volunteer carries `answers`: `[['step_id', 'choice_id', 'choice_label', 'rating', 'ms_taken']]` so command can open one run)
  - `trainingCaseRecordForUser(int $userId): array` returns `mission id => ['cases' => n, 'percent' => int]`
- The dispatch row (`loadMissionDispatchesForUser`) gains `'case' => null | ['placement_id','title','briefing','state','percent','label','can_play' => bool,'available' => bool]`.

Server rules (all tested): the answer is accepted only for the run's `current_step` (an earlier, already-answered step returns the stored result with `duplicate = true`); the rating and next step are read from the snapshot, never from the caller; one INSERT IGNORE makes two simultaneous identical posts safe; a finished run takes no more answers; `score_max` grows by 2 per answered step.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class TrainingCaseRunTest extends TestCase
{
    private array $drill;
    private int $admin;
    private int $teamId;
    private int $otherTeamId;
    private int $member;
    private int $mate;
    private int $stranger;
    private int $dispatchId;
    private int $placementId;

    protected function setUp(): void
    {
        db()->beginTransaction();
        $this->admin = TrainingFixtures::user('Admin');
        $this->drill = TrainingFixtures::mission(true);
        $this->teamId = (int) dbInsert("INSERT INTO mission_teams (mission_id, codename, team_number) VALUES (?, 'ΑΛΦΑ', 1)", [$this->drill['mission']]);
        $this->otherTeamId = (int) dbInsert("INSERT INTO mission_teams (mission_id, codename, team_number) VALUES (?, 'ΒΗΤΑ', 2)", [$this->drill['mission']]);
        $this->member = $this->volunteer('Member', $this->teamId);
        $this->mate = $this->volunteer('Mate', $this->teamId);
        $this->stranger = $this->volunteer('Stranger', $this->otherTeamId);

        $this->dispatchId = (int) dbInsert(
            "INSERT INTO mission_dispatch_points (mission_id, team_id, type, geo, label, created_by) VALUES (?, ?, 'point', ?, 'Περιστατικό', ?)",
            [$this->drill['mission'], $this->teamId, json_encode(['lat' => 35.3, 'lng' => 25.1]), $this->admin]
        );
        $doc = [
            'title' => 'Δοκιμή', 'story' => 'Ιστορία.', 'closing' => 'Τέλος.', 'findings' => [], 'start' => 's1',
            'steps' => [
                's1' => ['prompt' => 'Πρώτο;', 'hint' => '', 'choices' => [
                    ['id' => 'a', 'label' => 'Α', 'rating' => 'best', 'feedback' => 'Σωστά.', 'next' => 's2'],
                    ['id' => 'b', 'label' => 'Β', 'rating' => 'harmful', 'feedback' => 'Λάθος.', 'next' => 's2'],
                ]],
                's2' => ['prompt' => 'Δεύτερο;', 'hint' => '', 'choices' => [
                    ['id' => 'a', 'label' => 'Α', 'rating' => 'acceptable', 'feedback' => 'Εντάξει.', 'next' => null],
                    ['id' => 'b', 'label' => 'Β', 'rating' => 'best', 'feedback' => 'Άριστα.', 'next' => null],
                ]],
            ],
        ];
        $this->placementId = (int) trainingCaseCreatePlacement(
            $this->drill['mission'], 0, $this->dispatchId,
            ['case' => ['case_id' => 0, 'title' => 'Δοκιμή', 'draw_mode' => 'chosen', 'briefing' => 'Φωνή', 'document' => $doc]]
        );
    }

    protected function tearDown(): void
    {
        db()->rollBack();
    }

    private function volunteer(string $name, int $team): int
    {
        $u = TrainingFixtures::user($name);
        TrainingFixtures::participation($this->drill['shift'], $u, false, null);
        dbExecute("INSERT INTO mission_team_members (mission_id, team_id, user_id) VALUES (?, ?, ?)", [$this->drill['mission'], $team, $u]);
        return $u;
    }

    private function arrive(int $teamId): void
    {
        dbExecute("INSERT INTO mission_dispatch_progress (dispatch_id, team_id, scope_key, arrived_at) VALUES (?, ?, ?, NOW())", [$this->dispatchId, $teamId, 't' . $teamId]);
    }

    public function test_nobody_can_play_before_their_team_arrives(): void
    {
        $placement = trainingCasePlacement($this->placementId);
        $this->assertSame('case.err_not_arrived', trainingCaseGate($placement, $this->member, true));
        $this->assertNull(trainingCaseGate($placement, $this->member, false), 'loading the document needs no arrival');
        $this->arrive($this->teamId);
        $this->assertNull(trainingCaseGate($placement, $this->member, true));
        $this->assertNull(trainingCaseGate($placement, $this->mate, true), 'the whole team may play once the team arrived');
    }

    public function test_the_wrong_team_and_non_participants_are_refused(): void
    {
        $placement = trainingCasePlacement($this->placementId);
        $this->arrive($this->teamId);
        $this->assertSame('case.err_not_audience', trainingCaseGate($placement, $this->stranger, false));
        $outsider = TrainingFixtures::user('Outsider');
        $this->assertSame('case.err_not_participant', trainingCaseGate($placement, $outsider, false));
    }

    public function test_a_real_mission_or_a_withdrawn_order_closes_the_gate(): void
    {
        $this->arrive($this->teamId);
        dbExecute("UPDATE missions SET is_training = 0 WHERE id = ?", [$this->drill['mission']]);
        $this->assertSame('case.err_not_training', trainingCaseGate(trainingCasePlacement($this->placementId), $this->member, true));
        dbExecute("UPDATE missions SET is_training = 1 WHERE id = ?", [$this->drill['mission']]);
        dbExecute("DELETE FROM mission_dispatch_points WHERE id = ?", [$this->dispatchId]);
        $this->assertSame('case.err_gone', trainingCaseGate(trainingCasePlacement($this->placementId), $this->member, true));
    }

    public function test_a_full_run_is_scored_by_the_server(): void
    {
        $this->arrive($this->teamId);
        $first = trainingCaseAnswer($this->placementId, $this->member, 's1', 'a', 4200);
        $this->assertTrue($first['ok']);
        $this->assertSame('best', $first['rating']);
        $this->assertSame('s2', $first['next']);
        $this->assertFalse($first['finished']);
        $this->assertSame(2, $first['score_points']);
        $this->assertSame(2, $first['score_max']);

        $second = trainingCaseAnswer($this->placementId, $this->member, 's2', 'a');
        $this->assertSame('acceptable', $second['rating']);
        $this->assertTrue($second['finished']);
        $this->assertSame(3, $second['score_points']);
        $this->assertSame(4, $second['score_max']);
        $this->assertSame(75, $second['percent']);
        $this->assertSame('good', $second['label']);

        $state = trainingCaseRunState($this->placementId, $this->member);
        $this->assertTrue($state['finished']);
        $this->assertCount(2, $state['answers']);
    }

    public function test_a_repeated_answer_is_ignored_and_returns_the_stored_result(): void
    {
        $this->arrive($this->teamId);
        trainingCaseAnswer($this->placementId, $this->member, 's1', 'b');
        $again = trainingCaseAnswer($this->placementId, $this->member, 's1', 'a');
        $this->assertTrue($again['ok']);
        $this->assertTrue($again['duplicate']);
        $this->assertSame('harmful', $again['rating'], 'the first choice stands');
        $this->assertSame('b', $again['choice_id']);
        $this->assertSame(0, $again['score_points']);
        $this->assertSame(2, $again['score_max']);
    }

    public function test_out_of_order_unknown_and_finished_answers_are_refused(): void
    {
        $this->arrive($this->teamId);
        $this->assertFalse(trainingCaseAnswer($this->placementId, $this->member, 's2', 'a')['ok'], 'step 2 before step 1');
        $this->assertFalse(trainingCaseAnswer($this->placementId, $this->member, 's1', 'zzz')['ok'], 'a choice that does not exist');
        trainingCaseAnswer($this->placementId, $this->member, 's1', 'a');
        trainingCaseAnswer($this->placementId, $this->member, 's2', 'b');
        // The run is finished: a different choice for the last step changes nothing and returns the stored one.
        $again = trainingCaseAnswer($this->placementId, $this->member, 's2', 'a');
        $this->assertTrue($again['ok']);
        $this->assertTrue($again['duplicate']);
        $this->assertSame('best', $again['rating']);
        $this->assertSame(4, $again['score_points']);
    }

    public function test_each_volunteer_has_their_own_run(): void
    {
        $this->arrive($this->teamId);
        trainingCaseAnswer($this->placementId, $this->member, 's1', 'a');
        trainingCaseAnswer($this->placementId, $this->mate, 's1', 'b');
        $this->assertSame(2, trainingCaseRunState($this->placementId, $this->member)['score_points']);
        $this->assertSame(0, trainingCaseRunState($this->placementId, $this->mate)['score_points']);
        $this->assertNull(trainingCaseRunState($this->placementId, $this->stranger));
    }

    public function test_the_dispatch_row_card_reflects_the_volunteers_own_state(): void
    {
        $cards = trainingCaseCardsForDispatches([$this->dispatchId], $this->member);
        $this->assertSame('new', $cards[$this->dispatchId]['state']);
        $this->assertSame('Φωνή', $cards[$this->dispatchId]['briefing']);
        $this->arrive($this->teamId);
        trainingCaseAnswer($this->placementId, $this->member, 's1', 'a');
        $this->assertSame('running', trainingCaseCardsForDispatches([$this->dispatchId], $this->member)[$this->dispatchId]['state']);
        trainingCaseAnswer($this->placementId, $this->member, 's2', 'b');
        $done = trainingCaseCardsForDispatches([$this->dispatchId], $this->member)[$this->dispatchId];
        $this->assertSame('done', $done['state']);
        $this->assertSame(100, $done['percent']);
        $this->assertSame([], trainingCaseCardsForDispatches([], $this->member));
    }

    public function test_the_loaded_dispatch_list_carries_the_card_and_can_play(): void
    {
        $rows = loadMissionDispatchesForUser($this->drill['mission'], $this->member, false, true);
        $row = array_values(array_filter($rows, fn($r) => $r['id'] === $this->dispatchId))[0];
        $this->assertNotNull($row['case']);
        $this->assertTrue($row['case']['can_play']);
        $this->assertFalse($row['case']['available'], 'the team has not arrived');
        $this->arrive($this->teamId);
        $rows = loadMissionDispatchesForUser($this->drill['mission'], $this->member, false, true);
        $row = array_values(array_filter($rows, fn($r) => $r['id'] === $this->dispatchId))[0];
        $this->assertTrue($row['case']['available']);
    }

    public function test_the_overview_lists_every_audience_volunteer_and_the_choices_made(): void
    {
        $this->arrive($this->teamId);
        trainingCaseAnswer($this->placementId, $this->member, 's1', 'a');
        trainingCaseAnswer($this->placementId, $this->mate, 's1', 'b');
        $overview = trainingCaseOverview($this->drill['mission']);
        $this->assertCount(1, $overview['placements']);
        $p = $overview['placements'][0];
        $byUser = array_column($p['volunteers'], null, 'user_id');
        $this->assertSame('running', $byUser[$this->member]['state']);
        $this->assertSame('running', $byUser[$this->mate]['state']);
        $this->assertArrayNotHasKey($this->stranger, $byUser, 'another team is not in the audience');
        $s1 = array_column($p['steps'], null, 'step_id')['s1'];
        $counts = array_column($s1['choices'], 'count', 'id');
        $this->assertSame(1, $counts['a']);
        $this->assertSame(1, $counts['b']);
        // One run can be opened: each answer with the choice's text, its rating and the time taken.
        $answer = $byUser[$this->member]['answers'][0];
        $this->assertSame('s1', $answer['step_id']);
        $this->assertSame('Α', $answer['choice_label']);
        $this->assertSame('best', $answer['rating']);
    }

    public function test_the_record_counts_finished_runs_only(): void
    {
        $this->arrive($this->teamId);
        trainingCaseAnswer($this->placementId, $this->member, 's1', 'a');
        $this->assertSame([], trainingCaseRecordForUser($this->member), 'a run in progress is not in the record');
        trainingCaseAnswer($this->placementId, $this->member, 's2', 'a');
        $record = trainingCaseRecordForUser($this->member);
        $this->assertSame(1, $record[$this->drill['mission']]['cases']);
        $this->assertSame(75, $record[$this->drill['mission']]['percent']);
    }

    public function test_the_arrival_notice_goes_to_the_on_duty_teammates_only(): void
    {
        // "On duty" is activeMissionRecipientIds(): an approved participant whose shift is running now and who is in the Action Room.
        dbExecute("UPDATE shifts SET start_time = ?, end_time = ? WHERE id = ?", [date('Y-m-d H:i:s', time() - 3600), date('Y-m-d H:i:s', time() + 3600), $this->drill['shift']]);
        foreach ([$this->member, $this->mate, $this->stranger] as $u) {
            dbExecute("INSERT INTO mission_action_room_participants (mission_id, user_id) VALUES (?, ?)", [$this->drill['mission'], $u]);
        }

        trainingCaseNotifyArrival($this->drill['mission'], $this->dispatchId, $this->teamId, $this->member);

        $count = fn(int $u): int => (int) dbFetchValue("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND data LIKE ?", [$u, '%case-' . $this->placementId . '%']);
        $this->assertSame(1, $count($this->mate), 'the teammate is told the case is open');
        $this->assertSame(0, $count($this->member), 'the one who arrived already knows');
        $this->assertSame(0, $count($this->stranger), 'another team is not told');
    }

    public function test_an_order_without_a_case_sends_no_arrival_notice(): void
    {
        $plain = (int) dbInsert(
            "INSERT INTO mission_dispatch_points (mission_id, team_id, type, geo, created_by) VALUES (?, ?, 'point', ?, ?)",
            [$this->drill['mission'], $this->teamId, json_encode(['lat' => 35.4, 'lng' => 25.2]), $this->admin]
        );
        trainingCaseNotifyArrival($this->drill['mission'], $plain, $this->teamId, $this->member);
        $this->assertSame(0, (int) dbFetchValue("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND data LIKE '%case-%'", [$this->mate]));
    }
}
```

If inserting into `mission_action_room_participants` fails on a NOT NULL column, copy the column list from how `tests/ArrivalPromptTest.php` sets its participants up.

- [ ] **Step 2: Run it to verify it fails**

Run: `/c/xampp/php/php.exe vendor/phpunit/phpunit/phpunit tests/TrainingCaseRunTest.php`
Expected: FAIL (`Call to undefined function trainingCaseGate`).

- [ ] **Step 3: Append to `includes/functions-training-case-runs.php`**

```php
/**
 * May this volunteer open (and, with $needArrival, play) this placement? Returns null when yes, else the t() key of
 * why not. Checked on every request of the endpoint: a drill only, the order still exists, the person is an approved
 * participant of the mission and in the order's audience (its team, or any team for an order to every team), and - to
 * PLAY - their team has arrived (arrival is a team fact, see recordDispatchProgress()).
 */
function trainingCaseGate(array $placement, int $userId, bool $needArrival): ?string {
    $missionId = (int) $placement['mission_id'];
    if (!isTrainingMission($missionId)) {
        return 'case.err_not_training';
    }
    if ($placement['dispatch_id'] === null || !dbFetchValue("SELECT 1 FROM mission_dispatch_points WHERE id = ? AND mission_id = ?", [(int) $placement['dispatch_id'], $missionId])) {
        return 'case.err_gone';
    }
    $participant = dbFetchValue(
        "SELECT 1 FROM participation_requests pr JOIN shifts s ON s.id = pr.shift_id
         WHERE s.mission_id = ? AND pr.volunteer_id = ? AND pr.status = ? LIMIT 1",
        [$missionId, $userId, PARTICIPATION_APPROVED]
    );
    if (!$participant) {
        return 'case.err_not_participant';
    }
    $myTeam = getUserTeamIdForMission($missionId, $userId);
    if ($placement['team_id'] !== null && (int) $placement['team_id'] !== $myTeam) {
        return 'case.err_not_audience';
    }
    if ($needArrival) {
        $arrived = dbFetchValue(
            "SELECT 1 FROM mission_dispatch_progress WHERE dispatch_id = ? AND scope_key = ? AND (arrived_at IS NOT NULL OR completed_at IS NOT NULL)",
            [(int) $placement['dispatch_id'], dispatchProgressScopeKey($myTeam, $userId)]
        ) || dbFetchValue(
            $myTeam ? "SELECT 1 FROM mission_dispatch_acks WHERE dispatch_id = ? AND team_id = ? LIMIT 1"
                    : "SELECT 1 FROM mission_dispatch_acks WHERE dispatch_id = ? AND user_id = ? AND team_id IS NULL LIMIT 1",
            [(int) $placement['dispatch_id'], $myTeam ?: $userId]
        );
        if (!$arrived) {
            return 'case.err_not_arrived';
        }
    }
    return null;
}

/** Score fields for a run row. */
function trainingCaseScoreFields(array $run): array {
    $points = (int) $run['score_points'];
    $max = (int) $run['score_max'];
    return ['score_points' => $points, 'score_max' => $max, 'percent' => trainingCaseScorePercent($points, $max), 'label' => trainingCaseScoreLabel($points, $max)];
}

/** One volunteer's run of a placement, with what they have answered so far; null when they have not started. */
function trainingCaseRunState(int $placementId, int $userId): ?array {
    $run = dbFetchOne("SELECT * FROM mission_case_runs WHERE placement_id = ? AND user_id = ?", [$placementId, $userId]);
    if (!$run) {
        return null;
    }
    $answers = dbFetchAll("SELECT step_id, choice_id, rating FROM mission_case_answers WHERE run_id = ? ORDER BY id", [(int) $run['id']]);
    return [
        'run_id' => (int) $run['id'], 'current_step' => $run['current_step'], 'finished' => $run['finished_at'] !== null,
        'answers' => $answers,
    ] + trainingCaseScoreFields($run);
}

/** Starts (or returns) the volunteer's run. Idempotent; needs the team to have arrived. */
function trainingCaseStartRun(int $placementId, int $userId): array {
    $placement = trainingCasePlacement($placementId);
    if (!$placement || !$placement['doc']) {
        return ['ok' => false, 'error' => t('case.err_gone')];
    }
    $gate = trainingCaseGate($placement, $userId, true);
    if ($gate !== null) {
        return ['ok' => false, 'error' => t($gate)];
    }
    dbExecute(
        "INSERT IGNORE INTO mission_case_runs (placement_id, user_id, current_step) VALUES (?, ?, ?)",
        [$placementId, $userId, $placement['doc']['start']]
    );
    return ['ok' => true, 'run' => trainingCaseRunState($placementId, $userId)];
}

/**
 * One decision. The rating, the feedback and the next step are read from the SNAPSHOT, never from the caller; the
 * score is added here. Idempotent: an answer to a step that already has one returns the stored result with
 * duplicate = true and changes nothing (a double tap, an offline replay). Only the run's current step can be
 * answered, and a finished run takes nothing.
 */
function trainingCaseAnswer(int $placementId, int $userId, string $stepId, string $choiceId, ?int $msTaken = null): array {
    $placement = trainingCasePlacement($placementId);
    if (!$placement || !$placement['doc']) {
        return ['ok' => false, 'error' => t('case.err_gone')];
    }
    $gate = trainingCaseGate($placement, $userId, true);
    if ($gate !== null) {
        return ['ok' => false, 'error' => t($gate)];
    }
    $doc = $placement['doc'];
    dbExecute("INSERT IGNORE INTO mission_case_runs (placement_id, user_id, current_step) VALUES (?, ?, ?)", [$placementId, $userId, $doc['start']]);
    $run = dbFetchOne("SELECT * FROM mission_case_runs WHERE placement_id = ? AND user_id = ?", [$placementId, $userId]);

    $step = $doc['steps'][$stepId] ?? null;
    $choice = null;
    foreach ($step['choices'] ?? [] as $candidate) {
        if ($candidate['id'] === $choiceId) {
            $choice = $candidate;
        }
    }
    $result = function (array $c, bool $duplicate, array $runRow) use ($stepId): array {
        return [
            'ok' => true, 'duplicate' => $duplicate, 'step_id' => $stepId, 'choice_id' => $c['id'], 'rating' => $c['rating'],
            'feedback' => $c['feedback'], 'next' => $c['next'], 'finished' => $runRow['finished_at'] !== null,
        ] + trainingCaseScoreFields($runRow);
    };

    $stored = dbFetchOne("SELECT choice_id FROM mission_case_answers WHERE run_id = ? AND step_id = ?", [(int) $run['id'], $stepId]);
    if ($stored) {
        foreach ($step['choices'] ?? [] as $candidate) {
            if ($candidate['id'] === $stored['choice_id']) {
                return $result($candidate, true, $run);
            }
        }
        return ['ok' => false, 'error' => t('case.err_gone')];
    }
    if ($step === null || $choice === null) {
        return ['ok' => false, 'error' => t('case.err_bad_answer')];
    }
    if ($run['finished_at'] !== null || $run['current_step'] !== $stepId) {
        return ['ok' => false, 'error' => t('case.err_bad_answer')];
    }
    $inserted = dbExecute(
        "INSERT IGNORE INTO mission_case_answers (run_id, step_id, choice_id, rating, ms_taken) VALUES (?, ?, ?, ?, ?)",
        [(int) $run['id'], $stepId, $choiceId, $choice['rating'], $msTaken !== null ? max(0, min(3600000, $msTaken)) : null]
    );
    if ($inserted > 0) {
        dbExecute(
            "UPDATE mission_case_runs SET score_points = score_points + ?, score_max = score_max + 2, current_step = ?,
                    finished_at = " . ($choice['next'] === null ? 'NOW()' : 'NULL') . "
             WHERE id = ?",
            [trainingCaseRatingPoints($choice['rating']), $choice['next'] ?? $stepId, (int) $run['id']]
        );
    }
    $run = dbFetchOne("SELECT * FROM mission_case_runs WHERE id = ?", [(int) $run['id']]);
    return $result($choice, $inserted === 0, $run);
}

/**
 * What the Action Room's dispatch list carries for each order that has a case, for ONE volunteer: the placement, the
 * title and briefing, and their own state ('new', 'running', 'done' + their percent). No document: the phone fetches that
 * once (training-case.php?action=load) and keeps it. Any database error returns [] so a site that has not migrated yet
 * never loses its Action Room over this.
 */
function trainingCaseCardsForDispatches(array $dispatchIds, int $userId): array {
    if (!$dispatchIds) {
        return [];
    }
    try {
        $in = implode(',', array_fill(0, count($dispatchIds), '?'));
        $rows = dbFetchAll(
            "SELECT p.id, p.dispatch_id, p.title, p.briefing, r.finished_at, r.current_step, r.score_points, r.score_max, r.id AS run_id
             FROM mission_case_placements p
             LEFT JOIN mission_case_runs r ON r.placement_id = p.id AND r.user_id = ?
             WHERE p.dispatch_id IN ($in)",
            array_merge([$userId], array_map('intval', $dispatchIds))
        );
    } catch (Throwable $e) {
        error_log('[training-cases] cards: ' . $e->getMessage());
        return [];
    }
    $out = [];
    foreach ($rows as $row) {
        $state = $row['run_id'] === null ? 'new' : ($row['finished_at'] !== null ? 'done' : 'running');
        $out[(int) $row['dispatch_id']] = [
            'placement_id' => (int) $row['id'], 'title' => $row['title'], 'briefing' => (string) $row['briefing'], 'state' => $state,
            'percent' => $state === 'done' ? trainingCaseScorePercent((int) $row['score_points'], (int) $row['score_max']) : null,
            'label' => $state === 'done' ? trainingCaseScoreLabel((int) $row['score_points'], (int) $row['score_max']) : null,
        ];
    }
    return $out;
}

/**
 * The team has just arrived at a case's order: tell its other on-duty members that the case is open (arrival is the
 * team's, so only one person's press or GPS fix records it). Never throws; sends nothing for an order without a case.
 */
function trainingCaseNotifyArrival(int $missionId, int $dispatchId, ?int $teamId, int $arrivedUserId): void {
    try {
        $placement = trainingCasePlacementForDispatch($dispatchId);
        if (!$placement || !$teamId) {
            return;
        }
        $members = array_map('intval', array_column(
            dbFetchAll("SELECT user_id FROM mission_team_members WHERE mission_id = ? AND team_id = ?", [$missionId, $teamId]),
            'user_id'
        ));
        $recipients = array_values(array_diff(array_intersect(activeMissionRecipientIds($missionId), $members), [$arrivedUserId]));
        foreach ($recipients as $uid) {
            $lang = getUserLanguage($uid);
            sendNotification($uid, t('case.arrived_title', [], $lang), t('case.arrived_message', ['title' => (string) $placement['briefing'] !== '' ? $placement['briefing'] : $placement['title']], $lang), 'info', '', [
                'url' => rtrim(BASE_URL, '/') . '/war-room.php?id=' . $missionId,
                'tag' => 'case-' . (int) $placement['id'],
                'bannerMission' => $missionId,
            ]);
        }
    } catch (Throwable $e) {
        error_log('[training-cases] arrival notice: ' . $e->getMessage());
    }
}

/**
 * The «Περιστατικά» card for command: for each case placed in the drill, every volunteer in its audience with their state
 * (new / running at a step / done with a score) and, for each step, how many chose each option (for the group debrief).
 * ['placements' => [['id', 'title', 'label', 'audience', 'volunteers' => [['user_id', 'name', 'state', 'step', 'score_points',
 * 'score_max', 'percent', 'label', 'finished_at', 'answers' => [['step_id', 'choice_id', 'choice_label', 'rating', 'ms_taken']]]],
 * 'steps' => [['step_id', 'prompt', 'choices' => [['id', 'label', 'rating', 'count']]]]]]].
 */
function trainingCaseOverview(int $missionId): array {
    $out = [];
    $placements = dbFetchAll(
        "SELECT p.*, d.label AS dispatch_label FROM mission_case_placements p
         LEFT JOIN mission_dispatch_points d ON d.id = p.dispatch_id
         WHERE p.mission_id = ? ORDER BY p.id",
        [$missionId]
    );
    foreach ($placements as $row) {
        $placement = trainingCasePlacementWithDoc($row);
        $people = dbFetchAll(
            "SELECT DISTINCT u.id, u.name FROM participation_requests pr
             JOIN shifts s ON s.id = pr.shift_id JOIN users u ON u.id = pr.volunteer_id
             WHERE s.mission_id = ? AND pr.status = ?"
            . ($placement['team_id'] !== null ? " AND EXISTS (SELECT 1 FROM mission_team_members m WHERE m.mission_id = s.mission_id AND m.team_id = " . (int) $placement['team_id'] . " AND m.user_id = u.id)" : "")
            . " ORDER BY u.name",
            [$missionId, PARTICIPATION_APPROVED]
        );
        $runs = [];
        foreach (dbFetchAll("SELECT * FROM mission_case_runs WHERE placement_id = ?", [(int) $placement['id']]) as $run) {
            $runs[(int) $run['user_id']] = $run;
        }
        // Every answer of the placement, grouped by volunteer, with the text of the choice from the snapshot.
        $answersByUser = [];
        foreach (dbFetchAll(
            "SELECT r.user_id, a.step_id, a.choice_id, a.rating, a.ms_taken FROM mission_case_answers a
             JOIN mission_case_runs r ON r.id = a.run_id WHERE r.placement_id = ? ORDER BY a.id",
            [(int) $placement['id']]
        ) as $a) {
            $label = '';
            foreach ($placement['doc']['steps'][$a['step_id']]['choices'] ?? [] as $c) {
                if ($c['id'] === $a['choice_id']) {
                    $label = $c['label'];
                }
            }
            $answersByUser[(int) $a['user_id']][] = [
                'step_id' => $a['step_id'], 'choice_id' => $a['choice_id'], 'choice_label' => $label, 'rating' => $a['rating'],
                'ms_taken' => $a['ms_taken'] !== null ? (int) $a['ms_taken'] : null,
            ];
        }
        $volunteers = [];
        foreach ($people as $person) {
            $run = $runs[(int) $person['id']] ?? null;
            $volunteers[] = [
                'user_id' => (int) $person['id'], 'name' => $person['name'],
                'state' => $run === null ? 'new' : ($run['finished_at'] !== null ? 'done' : 'running'),
                'step' => $run['current_step'] ?? null,
                'finished_at' => $run['finished_at'] ?? null,
                'answers' => $answersByUser[(int) $person['id']] ?? [],
            ] + ($run !== null ? trainingCaseScoreFields($run) : ['score_points' => 0, 'score_max' => 0, 'percent' => 0, 'label' => null]);
        }
        $counts = [];
        foreach (dbFetchAll(
            "SELECT a.step_id, a.choice_id, COUNT(*) AS n FROM mission_case_answers a JOIN mission_case_runs r ON r.id = a.run_id
             WHERE r.placement_id = ? GROUP BY a.step_id, a.choice_id",
            [(int) $placement['id']]
        ) as $c) {
            $counts[$c['step_id']][$c['choice_id']] = (int) $c['n'];
        }
        $steps = [];
        foreach ($placement['doc']['steps'] ?? [] as $stepId => $step) {
            $steps[] = ['step_id' => (string) $stepId, 'prompt' => $step['prompt'], 'choices' => array_map(fn($c) => [
                'id' => $c['id'], 'label' => $c['label'], 'rating' => $c['rating'], 'count' => $counts[$stepId][$c['id']] ?? 0,
            ], $step['choices'])];
        }
        $out[] = [
            'id' => (int) $placement['id'], 'title' => $placement['title'], 'label' => (string) ($row['dispatch_label'] ?? ''),
            'audience' => $placement['audience'], 'volunteers' => $volunteers, 'steps' => $steps,
        ];
    }
    return ['placements' => $out];
}

/**
 * A volunteer's finished case runs per drill, for the training record: [mission id => ['cases' => n, 'percent' => int]].
 * A run that is not finished is not in the record. Reads only the case tables, never participation numbers.
 */
function trainingCaseRecordForUser(int $userId): array {
    $out = [];
    foreach (dbFetchAll(
        "SELECT p.mission_id, COUNT(*) AS n, SUM(r.score_points) AS pts, SUM(r.score_max) AS mx
         FROM mission_case_runs r JOIN mission_case_placements p ON p.id = r.placement_id
         WHERE r.user_id = ? AND r.finished_at IS NOT NULL GROUP BY p.mission_id",
        [$userId]
    ) as $row) {
        $out[(int) $row['mission_id']] = ['cases' => (int) $row['n'], 'percent' => trainingCaseScorePercent((int) $row['pts'], (int) $row['mx'])];
    }
    return $out;
}
```

- [ ] **Step 4: Hook the dispatch list and the arrival**

In `includes/functions-warroom.php`:

(a) In `loadMissionDispatchesForUser`, right before the `return array_map(function ($row) use (...` at line 1495 add `$caseCards = trainingCaseCardsForDispatches($dispatchIds, $userId);`, add `$caseCards` to that closure's `use (...)` list, and in the returned array after the `'can_ack'` line (line 1571) add:

```php
            // A staged training case rides on this order (v3.505.0): the volunteer's own card for it. `available` is the TEAM's
            // arrival, the same fact as my_ack; the document itself is fetched once by training-case.php, not carried by the poll.
            'case'         => isset($caseCards[$dispatchId])
                ? $caseCards[$dispatchId] + ['can_play' => $isApprovedParticipant && $eligible, 'available' => $isApprovedParticipant && $eligible && (bool) $teamArrived]
                : null,
```

(b) In `advanceMissionDispatch`, inside the `if ($recorded) {` block, directly after the `logAudit($auditAction, ...)` call (line 5058) add:

```php
        // A team that arrives at a training case opens it for every member (v3.505.0): the other on-duty members are told.
        if ($step === 'arrive' && in_array('arrive', $recorded, true) && !$legacyArrived) {
            trainingCaseNotifyArrival($missionId, $dispatchId, $myTeamId, $userId);
        }
```

- [ ] **Step 5: Texts**

Add to both blocks: `case.err_gone` (el «Το περιστατικό δεν είναι διαθέσιμο.» / en "The case is not available."), `case.err_not_participant` (el «Δεν συμμετέχεις σε αυτή την άσκηση.» / en "You are not taking part in this exercise."), `case.err_not_audience` (el «Αυτό το περιστατικό δεν είναι για την ομάδα σου.» / en "This case is not for your team."), `case.err_not_arrived` (el «Η ομάδα σου δεν έχει φτάσει ακόμα στο σημείο.» / en "Your team has not arrived at the spot yet."), `case.err_bad_answer` (el «Αυτή η απάντηση δεν είναι έγκυρη τώρα.» / en "That answer is not valid right now."), `case.arrived_title` (el «Περιστατικό στο σημείο» / en "Case at the spot"), `case.arrived_message` (el «Η ομάδα σου έφτασε. Άνοιξε το περιστατικό: {title}» / en "Your team has arrived. Open the case: {title}").

- [ ] **Step 6: Run the tests**

Run: `/c/xampp/php/php.exe vendor/phpunit/phpunit/phpunit tests/TrainingCaseRunTest.php`
Expected: PASS (13 tests). Then `/c/xampp/php/php.exe vendor/phpunit/phpunit/phpunit --filter "Dispatch|Arrival|Scenario|Training"` (expected: only the 2 baseline failures).

- [ ] **Step 7: Commit**

```bash
git add includes/functions-training-case-runs.php includes/functions-warroom.php includes/lang/war-room.php tests/TrainingCaseRunTest.php
git commit -m "Add training case runs, server-side scoring and the dispatch-row card"
```

---

### Task 7: The endpoint

**Files:**
- Create: `training-case.php`
- Test: `tests/TrainingCaseEndpointSourceTest.php` (new)

**Interfaces:**
- Consumes: Task 6.
- Produces: the JSON API used by the player and the command card:
  - `GET training-case.php?action=load&mission_id=N&placement_id=N` returns `{ok, placement: {id, title, briefing}, document, run: runState|null}`; allowed to any audience member (no arrival needed, so the phone can cache it)
  - `POST action=start` (`mission_id`, `placement_id`, `csrf_token`) returns `{ok, run}`
  - `POST action=answer` (`mission_id`, `placement_id`, `step_id`, `choice_id`, `ms_taken`, `queued`) returns the result of `trainingCaseAnswer()`
  - `GET action=overview&mission_id=N` (command: `canManageActionRoom`) returns `{ok, ...trainingCaseOverview}`

- [ ] **Step 1: Write the failing source test**

```php
<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** The endpoint's guards, read from its source (it is a script, not a function). */
final class TrainingCaseEndpointSourceTest extends TestCase
{
    private string $src;

    protected function setUp(): void
    {
        $this->src = (string) file_get_contents(__DIR__ . '/../training-case.php');
    }

    public function test_it_requires_login_and_an_open_training_mission(): void
    {
        $this->assertStringContainsString('requireLogin();', $this->src);
        $this->assertStringContainsString("STATUS_OPEN", $this->src);
        $this->assertStringContainsString('isTrainingMission(', $this->src);
    }

    public function test_posts_check_the_csrf_token(): void
    {
        $this->assertMatchesRegularExpression('/csrf_token.*hash_equals|hash_equals.*csrf_token/s', $this->src);
    }

    public function test_the_overview_is_for_command_only(): void
    {
        $this->assertMatchesRegularExpression('/action === \'overview\'.*canManageActionRoom/s', $this->src);
    }

    public function test_answers_and_start_go_through_the_server_functions(): void
    {
        $this->assertStringContainsString('trainingCaseAnswer(', $this->src);
        $this->assertStringContainsString('trainingCaseStartRun(', $this->src);
        $this->assertStringContainsString('trainingCaseGate(', $this->src, 'load applies the same gate without the arrival');
    }

    public function test_it_never_reads_a_rating_or_score_from_the_request(): void
    {
        $this->assertStringNotContainsString("post('rating')", $this->src);
        $this->assertStringNotContainsString("post('score", $this->src);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

Run: `/c/xampp/php/php.exe vendor/phpunit/phpunit/phpunit tests/TrainingCaseEndpointSourceTest.php`
Expected: FAIL (the file does not exist).

- [ ] **Step 3: Create `training-case.php`**

```php
<?php
/**
 * VolunteerOps - Training case endpoint (v3.505.0).
 * The volunteer's side of a training case (load the snapshot, start a run, answer a step) and command's overview.
 * AJAX only, JSON out. The rules (who may play, the scoring) are in includes/functions-training-case-runs.php; nothing
 * here trusts a rating or a score from the request.
 */

require_once __DIR__ . '/bootstrap.php';
requireLogin();

header('Content-Type: application/json');

$userId = (int) getCurrentUserId();
$missionId = (int) (isPost() ? post('mission_id') : get('mission_id'));

$mission = dbFetchOne(
    "SELECT id, title, status, show_in_ops, responsible_user_id FROM missions WHERE id = ? AND deleted_at IS NULL",
    [$missionId]
);
if (!$mission || $mission['status'] !== STATUS_OPEN || empty($mission['show_in_ops'])) {
    echo json_encode(['ok' => false, 'error' => t('common.mission_not_found_or_inactive')]);
    exit;
}
// Cases exist only in drills.
if (!isTrainingMission($missionId)) {
    echo json_encode(['ok' => false, 'error' => t('case.err_not_training')]);
    exit;
}

$action = isPost() ? post('action') : get('action');

// ── Command's overview ─────────────────────────────────────────────────────
if ($action === 'overview') {
    if (!canManageActionRoom($mission['responsible_user_id'] ? (int) $mission['responsible_user_id'] : null, $userId)) {
        echo json_encode(['ok' => false, 'error' => t('common.no_access_action_room')]);
        exit;
    }
    echo json_encode(['ok' => true] + trainingCaseOverview($missionId));
    exit;
}

if (isPost() && (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', (string) $_POST['csrf_token']))) {
    echo json_encode(['ok' => false, 'error' => t('common.invalid_request')]);
    exit;
}

$placementId = (int) (isPost() ? post('placement_id') : get('placement_id'));
$placement = $placementId > 0 ? trainingCasePlacement($placementId) : null;
if (!$placement || (int) $placement['mission_id'] !== $missionId) {
    echo json_encode(['ok' => false, 'error' => t('case.err_gone')]);
    exit;
}

// ── Load: the snapshot and my run, for the phone to keep ───────────────────
if ($action === 'load' && !isPost()) {
    $gate = trainingCaseGate($placement, $userId, false);
    if ($gate !== null) {
        echo json_encode(['ok' => false, 'error' => t($gate)]);
        exit;
    }
    echo json_encode([
        'ok' => true,
        'placement' => ['id' => (int) $placement['id'], 'title' => $placement['title'], 'briefing' => (string) $placement['briefing']],
        'document' => $placement['doc'],
        'run' => trainingCaseRunState((int) $placement['id'], $userId),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'start') {
    echo json_encode(trainingCaseStartRun((int) $placement['id'], $userId), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'answer') {
    $ms = post('ms_taken');
    echo json_encode(trainingCaseAnswer(
        (int) $placement['id'], $userId, (string) post('step_id'), (string) post('choice_id'),
        $ms !== '' && $ms !== null ? (int) $ms : null
    ), JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['ok' => false, 'error' => t('common.unknown_action')]);
```

- [ ] **Step 4: Run the test and lint the file**

Run: `/c/xampp/php/php.exe vendor/phpunit/phpunit/phpunit tests/TrainingCaseEndpointSourceTest.php` (expected PASS, 5 tests) and `/c/xampp/php/php.exe -l training-case.php` (expected `No syntax errors detected`).

- [ ] **Step 5: Commit**

```bash
git add training-case.php tests/TrainingCaseEndpointSourceTest.php
git commit -m "Add the training case endpoint"
```

---

### Task 8: The volunteer's case screen (player, offline, Action Room wiring)

**Files:**
- Create: `assets/js/training-case.js`
- Modify: `war-room.php` (script include near line 6949; `myOrderEntriesFromDispatches` at line 15827; `sendQueuedItem` at line 17804; a click handler)
- Modify: `includes/lang/war-room.php` (player texts)
- Test: `tests/js/training-case.test.js` (new), `tests/TrainingCaseWarRoomSourceTest.php` (new)

**Interfaces:**
- Consumes: Task 7 endpoint; the dispatch row's `case` block (`placement_id, title, briefing, state, percent, label, can_play, available`) and `my_ack`.
- Produces (`assets/js/training-case.js`, UMD: `window.VoTrainingCase` and `module.exports`):
  - pure: `choiceOutcome(doc, stepId, choiceId)` returns `{rating, feedback, next}` or null; `runFromAnswers(doc, answers)` returns `{currentStep, finished, points, max, percent, label}`; `bestPathLabels(doc)`; `mergeAnswers(serverAnswers, localAnswers)` (server wins per step); `answerQueueItem(placementId, stepId, choiceId, ms)` returns `{kind: 'case_answer', placementId, stepId, choiceId, ms, at}`; `storageKeys(placementId)` returns `{doc, answers}`
  - DOM: `open({placementId, missionId, csrf, t, enqueue, cardTitle})` shows the full-screen overlay `#voCaseOverlay`
- Queue item shape added to war-room.php's replay: `{kind: 'case_answer', placementId, stepId, choiceId, ms, at}`.

- [ ] **Step 1: Write the failing JS test** (`tests/js/training-case.test.js`)

```js
// Run with: node --test tests/js
const test = require('node:test');
const assert = require('node:assert/strict');
const TC = require('../../assets/js/training-case.js');

const doc = {
    title: 'T', story: 'S', closing: 'C', findings: [], start: 's1',
    steps: {
        s1: {prompt: 'P1', hint: '', choices: [
            {id: 'a', label: 'A', rating: 'best', feedback: 'fa', next: 's2'},
            {id: 'b', label: 'B', rating: 'harmful', feedback: 'fb', next: 's2'}]},
        s2: {prompt: 'P2', hint: '', choices: [
            {id: 'a', label: 'A', rating: 'acceptable', feedback: 'fa2', next: null},
            {id: 'b', label: 'B', rating: 'best', feedback: 'fb2', next: null}]},
    },
};

test('choiceOutcome reads rating, feedback and next from the document', () => {
    assert.deepEqual(TC.choiceOutcome(doc, 's1', 'b'), {rating: 'harmful', feedback: 'fb', next: 's2'});
    assert.equal(TC.choiceOutcome(doc, 's1', 'zzz'), null);
    assert.equal(TC.choiceOutcome(doc, 'nope', 'a'), null);
});

test('runFromAnswers walks the case and scores like the server', () => {
    assert.deepEqual(TC.runFromAnswers(doc, []), {currentStep: 's1', finished: false, points: 0, max: 0, percent: 0, label: 'practice'});
    const half = TC.runFromAnswers(doc, [{stepId: 's1', choiceId: 'a'}]);
    assert.equal(half.currentStep, 's2');
    assert.equal(half.points, 2);
    assert.equal(half.max, 2);
    const done = TC.runFromAnswers(doc, [{stepId: 's1', choiceId: 'a'}, {stepId: 's2', choiceId: 'a'}]);
    assert.equal(done.finished, true);
    assert.equal(done.currentStep, null);
    assert.equal(done.points, 3);
    assert.equal(done.max, 4);
    assert.equal(done.percent, 75);
    assert.equal(done.label, 'good');
});

test('runFromAnswers ignores an answer that does not follow the path', () => {
    const run = TC.runFromAnswers(doc, [{stepId: 's2', choiceId: 'b'}]);
    assert.equal(run.currentStep, 's1');
    assert.equal(run.points, 0);
});

test('labels: excellent from 80 percent, good from 50', () => {
    assert.equal(TC.scoreLabel(8, 10), 'excellent');
    assert.equal(TC.scoreLabel(5, 10), 'good');
    assert.equal(TC.scoreLabel(4, 10), 'practice');
    assert.equal(TC.scoreLabel(0, 0), 'practice');
});

test('mergeAnswers lets the server win per step and keeps local-only steps', () => {
    const merged = TC.mergeAnswers(
        [{stepId: 's1', choiceId: 'a'}],
        [{stepId: 's1', choiceId: 'b'}, {stepId: 's2', choiceId: 'b'}]);
    assert.deepEqual(merged, [{stepId: 's1', choiceId: 'a'}, {stepId: 's2', choiceId: 'b'}]);
});

test('bestPathLabels lists the best choice of each step on the path', () => {
    assert.deepEqual(TC.bestPathLabels(doc), [{stepId: 's1', label: 'A'}, {stepId: 's2', label: 'B'}]);
});

test('answerQueueItem has the shape war-room.php replays', () => {
    const item = TC.answerQueueItem(7, 's1', 'a', 1234);
    assert.equal(item.kind, 'case_answer');
    assert.equal(item.placementId, 7);
    assert.equal(item.stepId, 's1');
    assert.equal(item.choiceId, 'a');
    assert.equal(item.ms, 1234);
    assert.equal(typeof item.at, 'number');
});

test('escapeHtml neutralises markup (case text is rendered through it)', () => {
    assert.equal(TC.escapeHtml('<b>"x" & y</b>'), '&lt;b&gt;&quot;x&quot; &amp; y&lt;/b&gt;');
});
```

- [ ] **Step 2: Run it to verify it fails**

Run: `node --test tests/js/training-case.test.js`
Expected: FAIL (`Cannot find module '../../assets/js/training-case.js'`).

- [ ] **Step 3: Create `assets/js/training-case.js`**

```js
/**
 * Training case player (v3.505.0). The volunteer's full-screen case: story, findings, then one decision at a time with
 * immediate feedback. The pure rules (outcome of a choice, the run from a list of answers, scoring, merging answers)
 * are exported for Node (tests/js/training-case.test.js) and mirror includes/functions-training-case-runs.php, which
 * recomputes everything itself: what this page shows is a courtesy, the server's answer is the record.
 *
 * Offline: the document is fetched once (training-case.php?action=load) and kept in localStorage; each answer is shown at
 * once from the document and queued ({kind: 'case_answer', ...}) for war-room.php's offline queue, which replays it.
 * Case text is plain text and ALWAYS goes through escapeHtml().
 */
(function (root) {
    'use strict';

    const POINTS = {best: 2, acceptable: 1, harmful: 0};

    function escapeHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
    }

    function scoreLabel(points, max) {
        if (max <= 0) return 'practice';
        const ratio = points / max;
        return ratio >= 0.8 ? 'excellent' : (ratio >= 0.5 ? 'good' : 'practice');
    }

    function choiceOutcome(doc, stepId, choiceId) {
        const step = doc && doc.steps && doc.steps[stepId];
        const choice = step && step.choices.find(c => c.id === choiceId);
        return choice ? {rating: choice.rating, feedback: choice.feedback, next: choice.next} : null;
    }

    /** Walks the case from its start through the answers that follow the path; anything else is ignored. */
    function runFromAnswers(doc, answers) {
        let current = doc.start, points = 0, max = 0, finished = false;
        const byStep = {};
        (answers || []).forEach(a => { if (!byStep[a.stepId]) byStep[a.stepId] = a; });
        while (current && byStep[current]) {
            const out = choiceOutcome(doc, current, byStep[current].choiceId);
            if (!out) break;
            points += POINTS[out.rating] || 0;
            max += 2;
            current = out.next;
            if (current === null) { finished = true; break; }
        }
        return {
            currentStep: finished ? null : current, finished: finished, points: points, max: max,
            percent: max > 0 ? Math.round(100 * points / max) : 0, label: scoreLabel(points, max),
        };
    }

    function bestPathLabels(doc) {
        const out = [];
        let current = doc.start;
        while (current && doc.steps[current]) {
            const best = doc.steps[current].choices.find(c => c.rating === 'best');
            if (!best) break;
            out.push({stepId: current, label: best.label});
            current = best.next;
        }
        return out;
    }

    /** Server answers win per step; answers only this phone has (not synced yet) are kept after them. */
    function mergeAnswers(serverAnswers, localAnswers) {
        const seen = {};
        const out = [];
        (serverAnswers || []).concat(localAnswers || []).forEach(a => {
            if (seen[a.stepId]) return;
            seen[a.stepId] = true;
            out.push({stepId: a.stepId, choiceId: a.choiceId});
        });
        return out;
    }

    function answerQueueItem(placementId, stepId, choiceId, ms) {
        return {kind: 'case_answer', placementId: placementId, stepId: stepId, choiceId: choiceId, ms: ms || 0, at: Date.now()};
    }

    function storageKeys(placementId) {
        return {doc: 'wr_case_doc_' + placementId, answers: 'wr_case_answers_' + placementId};
    }

    // ── The overlay (browser only) ─────────────────────────────────────────
    function open(opts) {
        const t = opts.t;
        const keys = storageKeys(opts.placementId);
        const read = k => { try { return JSON.parse(localStorage.getItem(k) || 'null'); } catch (e) { return null; } };
        const write = (k, v) => { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) { /* storage full: the server still has the answers */ } };

        let doc = read(keys.doc);
        let answers = read(keys.answers) || [];
        let stepStart = Date.now();
        let pendingOutcome = null;   // {stepId, choiceId, outcome} shown, waiting for «Συνέχεια»

        const overlay = document.createElement('div');
        overlay.id = 'voCaseOverlay';
        overlay.style.cssText = 'position:fixed;inset:0;z-index:2000;background:#fff;color:#212529;overflow-y:auto;-webkit-overflow-scrolling:touch;';
        document.body.appendChild(overlay);
        document.body.style.overflow = 'hidden';

        function close() {
            overlay.remove();
            document.body.style.overflow = '';
            if (typeof opts.onClose === 'function') opts.onClose();
        }

        function shell(inner) {
            overlay.innerHTML = '<div class="container py-3" style="max-width:640px;">'
                + '<div class="alert alert-warning py-2 d-flex justify-content-between align-items-center mb-3">'
                + '<span><i class="bi bi-mortarboard-fill me-1"></i>' + escapeHtml(t('case.banner')) + '</span>'
                // SOS stays reachable (the overlay covers the page's own button): a real emergency beats the exercise.
                + '<span class="d-flex gap-1">' + (opts.onSos ? '<button type="button" class="btn btn-sm btn-danger" data-vc="sos">SOS</button>' : '')
                + '<button type="button" class="btn btn-sm btn-outline-dark" data-vc="stop">' + escapeHtml(t('case.stop')) + '</button></span></div>'
                + inner + '</div>';
            overlay.querySelector('[data-vc="stop"]').addEventListener('click', close);
            const sos = overlay.querySelector('[data-vc="sos"]');
            if (sos) sos.addEventListener('click', () => opts.onSos());
        }

        function findingsHtml() {
            if (!doc.findings || !doc.findings.length) return '';
            return '<table class="table table-sm mb-3"><tbody>' + doc.findings.map(f =>
                '<tr><th class="fw-semibold">' + escapeHtml(f.label) + '</th><td>' + escapeHtml(f.value) + (f.unit ? ' ' + escapeHtml(f.unit) : '') + '</td></tr>').join('') + '</tbody></table>';
        }

        function ratingBadge(rating) {
            const cls = {best: 'bg-success', acceptable: 'bg-warning text-dark', harmful: 'bg-danger'}[rating] || 'bg-secondary';
            return '<span class="badge ' + cls + ' fs-6 mb-2">' + escapeHtml(t('case.rating.' + rating)) + '</span>';
        }

        function render() {
            const run = runFromAnswers(doc, answers);
            if (pendingOutcome) return renderFeedback(run);
            if (run.finished) return renderEnd(run);
            if (!answers.length && !overlay.dataset.started) return renderIntro();
            return renderStep(run);
        }

        function renderIntro() {
            shell('<h4>' + escapeHtml(doc.title) + '</h4>'
                + (opts.briefing ? '<p class="text-muted">' + escapeHtml(opts.briefing) + '</p>' : '')
                + '<p style="white-space:pre-line;">' + escapeHtml(doc.story) + '</p>' + findingsHtml()
                + '<button type="button" class="btn btn-primary btn-lg w-100" data-vc="begin">' + escapeHtml(t('case.begin')) + '</button>');
            overlay.querySelector('[data-vc="begin"]').addEventListener('click', () => { overlay.dataset.started = '1'; stepStart = Date.now(); render(); });
        }

        function renderStep(run) {
            const step = doc.steps[run.currentStep];
            shell('<div class="small text-muted mb-1">' + escapeHtml(doc.title) + '</div>'
                + '<h5 class="mb-3">' + escapeHtml(step.prompt) + '</h5>'
                + (step.hint ? '<p class="small text-muted">' + escapeHtml(step.hint) + '</p>' : '')
                + step.choices.map(c => '<button type="button" class="btn btn-outline-primary btn-lg w-100 text-start mb-2" data-vc-choice="' + escapeHtml(c.id) + '">' + escapeHtml(c.label) + '</button>').join(''));
            overlay.querySelectorAll('[data-vc-choice]').forEach(btn => btn.addEventListener('click', () => answer(run.currentStep, btn.dataset.vcChoice)));
        }

        function answer(stepId, choiceId) {
            const out = choiceOutcome(doc, stepId, choiceId);
            if (!out) return;
            const ms = Date.now() - stepStart;
            answers = mergeAnswers(answers, [{stepId: stepId, choiceId: choiceId}]);
            write(keys.answers, answers);
            opts.enqueue(answerQueueItem(opts.placementId, stepId, choiceId, ms));
            pendingOutcome = {stepId: stepId, choiceId: choiceId, outcome: out};
            render();
        }

        function renderFeedback(run) {
            const o = pendingOutcome.outcome;
            shell(ratingBadge(o.rating) + '<p class="fs-5">' + escapeHtml(o.feedback) + '</p>'
                + '<button type="button" class="btn btn-primary btn-lg w-100" data-vc="next">' + escapeHtml(t('case.continue')) + '</button>');
            overlay.querySelector('[data-vc="next"]').addEventListener('click', () => { pendingOutcome = null; stepStart = Date.now(); render(); });
        }

        function renderEnd(run) {
            const mine = answers.map(a => {
                const out = choiceOutcome(doc, a.stepId, a.choiceId);
                const choice = doc.steps[a.stepId].choices.find(c => c.id === a.choiceId);
                return '<li class="mb-1">' + escapeHtml(choice.label) + ' ' + ratingBadge(out.rating) + '</li>';
            }).join('');
            const best = bestPathLabels(doc).map(b => '<li>' + escapeHtml(b.label) + '</li>').join('');
            shell('<h4>' + escapeHtml(t('case.end_title')) + '</h4>'
                + '<p class="fs-3 mb-1">' + run.points + ' / ' + run.max + ' (' + run.percent + '%)</p>'
                + '<p class="fw-semibold">' + escapeHtml(t('case.label.' + run.label)) + '</p>'
                + '<h6>' + escapeHtml(t('case.your_path')) + '</h6><ul class="small">' + mine + '</ul>'
                + '<h6>' + escapeHtml(t('case.best_path')) + '</h6><ul class="small">' + best + '</ul>'
                + (doc.closing ? '<h6>' + escapeHtml(t('case.lessons')) + '</h6><p style="white-space:pre-line;">' + escapeHtml(doc.closing) + '</p>' : '')
                + '<button type="button" class="btn btn-primary btn-lg w-100" data-vc="done">' + escapeHtml(t('case.close')) + '</button>');
            overlay.querySelector('[data-vc="done"]').addEventListener('click', close);
        }

        function load() {
            const url = 'training-case.php?action=load&mission_id=' + encodeURIComponent(opts.missionId) + '&placement_id=' + encodeURIComponent(opts.placementId);
            return fetch(url).then(r => r.json()).then(res => {
                if (!res || !res.ok) throw new Error((res && res.error) || 'load');
                doc = res.document;
                write(keys.doc, doc);
                const serverAnswers = ((res.run && res.run.answers) || []).map(a => ({stepId: a.step_id, choiceId: a.choice_id}));
                answers = mergeAnswers(serverAnswers, answers);
                write(keys.answers, answers);
                if (answers.length) overlay.dataset.started = '1';
            });
        }

        shell('<p class="text-muted">' + escapeHtml(t('case.loading')) + '</p>');
        // A document kept from an earlier visit opens at once (and works with no signal); the server's copy refreshes it.
        if (doc) { if (answers.length) overlay.dataset.started = '1'; render(); load().then(render).catch(() => {}); }
        else load().then(render).catch(err => shell('<p class="text-danger">' + escapeHtml(t('case.err_gone')) + '</p>'));
        return {close: close};
    }

    const api = {escapeHtml, scoreLabel, choiceOutcome, runFromAnswers, bestPathLabels, mergeAnswers, answerQueueItem, storageKeys, open};
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    root.VoTrainingCase = api;
})(typeof window !== 'undefined' ? window : globalThis);
```

- [ ] **Step 4: Run the JS test to verify it passes**

Run: `node --test tests/js/training-case.test.js`
Expected: PASS (8 tests).

- [ ] **Step 5: Wire it into `war-room.php`**

(a) After the `war-room-ar.js` script tag (line ~6949) add:
`<script src="<?= rtrim(BASE_URL, '/') ?>/assets/js/training-case.js?v=<?= APP_VERSION ?>.<?= (int) @filemtime(__DIR__ . '/assets/js/training-case.js') ?>"></script>`

(b) In `myOrderEntriesFromDispatches` (line 15827), after the `const directionsHtml = ...` line, add the case row, and include it in the returned `html` of both return statements for the non-declined row (the declined row keeps its notice only):

```js
            // A training case rides on this order (v3.505.0): once my team has arrived it opens here, for me alone.
            let caseHtml = '';
            if (d.case && d.case.can_play) {
                const cs = d.case;
                const label = cs.state === 'done'
                    ? t('case.btn_done', {percent: cs.percent})
                    : (cs.state === 'running' ? t('case.btn_resume') : t('case.btn_open'));
                caseHtml = d.my_ack || cs.available
                    ? `<button type="button" class="btn btn-sm btn-primary w-100 mt-1 vo-case-open-btn" data-placement="${cs.placement_id}" data-title="${escapeHtml(cs.title)}" data-briefing="${escapeHtml(cs.briefing || '')}"><i class="bi bi-clipboard2-pulse me-1"></i>${escapeHtml(label)}</button>`
                    : `<div class="small text-muted mt-1"><i class="bi bi-clipboard2-pulse me-1"></i>${escapeHtml(t('case.opens_on_arrival'))}</div>`;
            }
```
and change the last `html:` expression to `... + receiptHtml + actionHtml + caseHtml + directionsHtml + declineHtml`.

(c) Near the other delegated click handlers (next to the `wr-decline-open-btn` listener at line 15545) add:

```js
document.addEventListener('click', e => {
    const btn = e.target.closest('.vo-case-open-btn');
    if (!btn || !window.VoTrainingCase) return;
    window.VoTrainingCase.open({
        placementId: Number(btn.dataset.placement), missionId: WR_MISSION_ID, briefing: btn.dataset.briefing || '',
        t: t, enqueue: enqueueAction, onClose: () => { renderQueueStatus(); flushQueue(); refreshMyOrdersCard(); },
        // The page's own SOS button (the volunteer's status row, markup at about line 4529) sits under the overlay: reuse it.
        onSos: () => { const sos = document.querySelector('button[onclick*="\'needs_help\'"]'); if (sos) sos.click(); },
    });
});
```

(`enqueueAction` already de-duplicates only `status` items, so every `case_answer` is queued; the server's unique key makes a replay harmless.)
(`enqueueAction` is the page's offline-queue function at line 17516; the player calls it with the queue item.)

(d) In `sendQueuedItem` (line 17804), before the `if (item.kind === 'order')` branch add:

```js
    // A training case answer (v3.505.0): the server recomputes the rating and the score from its own copy; a replay
    // that already landed comes back as a duplicate, which is success.
    if (item.kind === 'case_answer') {
        return postQueuedForm('training-case.php', {
            mission_id: String(WR_MISSION_ID), action: 'answer', placement_id: String(item.placementId),
            step_id: item.stepId, choice_id: item.choiceId, ms_taken: String(item.ms || 0), queued: '1'
        });
    }
```
Two things to check by reading, and fix if they do not hold: (1) `postQueuedForm` (grep its definition) must not retry an `{ok:false}` answer forever: a refusal such as `case.err_not_arrived` or `case.err_gone` has to drop the item the way the `decline` kind's refusals do; (2) `VoMediaQueue.nextQueueIndex(queue, lane)` (assets/js/media-queue.js, tests in tests/js/media-queue.test.js) must treat an unknown `kind` as the fast lane, so a `case_answer` is sent in order with the other text items and never stuck behind a video. If either needs a change, add a test beside the existing ones.

(e) The offline field page (`offline.html` / `assets/js/field-mode.js`) is out of scope: the case opens from the live page, which is cached by the phone while a case is under way.

- [ ] **Step 6: Texts and the source test**

Add to both blocks (el first):
`case.banner` «Άσκηση, δεν είναι πραγματικό περιστατικό» / "Exercise, not a real incident"; `case.stop` «Σταμάτα» / "Stop"; `case.begin` «Ξεκινάω» / "Start"; `case.continue` «Συνέχεια» / "Continue"; `case.close` «Κλείσιμο» / "Close"; `case.loading` «Φόρτωση περιστατικού…» / "Loading the case..."; `case.rating.best` «Άριστη επιλογή» / "Best choice"; `case.rating.acceptable` «Αποδεκτή επιλογή» / "Acceptable choice"; `case.rating.harmful` «Επικίνδυνη επιλογή» / "Harmful choice"; `case.end_title` «Τέλος περιστατικού» / "Case finished"; `case.label.excellent` «Άριστα» / "Excellent"; `case.label.good` «Καλά» / "Good"; `case.label.practice` «Χρειάζεται εξάσκηση» / "Needs practice"; `case.your_path` «Η διαδρομή σου» / "Your path"; `case.best_path` «Η ιδανική διαδρομή» / "The ideal path"; `case.lessons` «Τι να θυμάσαι» / "What to remember"; `case.btn_open` «Άνοιγμα περιστατικού» / "Open the case"; `case.btn_resume` «Συνέχιση περιστατικού» / "Resume the case"; `case.btn_done` «Περιστατικό ολοκληρώθηκε ({percent}%)» / "Case finished ({percent}%)"; `case.opens_on_arrival` «Το περιστατικό ανοίγει όταν φτάσει η ομάδα» / "The case opens when your team arrives".

Write `tests/TrainingCaseWarRoomSourceTest.php` asserting that `war-room.php` contains `training-case.js`, `vo-case-open-btn`, `case_answer`, and `training-case.php`; that `assets/js/training-case.js` contains no `innerHTML` assignment that is not wrapped by `escapeHtml(` for case fields (assert the literal strings `escapeHtml(doc.story)`, `escapeHtml(step.prompt)`, `escapeHtml(o.feedback)`, `escapeHtml(c.label)`); and that every `case.*` key used by the JS (`t('case.` occurrences) exists in both language blocks (read `includes/lang/war-room.php`, extract keys with a regex, compare).

- [ ] **Step 7: Run the tests**

Run: `node --test tests/js` (expected: all pass) and `/c/xampp/php/php.exe vendor/phpunit/phpunit/phpunit tests/TrainingCaseWarRoomSourceTest.php` (expected PASS).

- [ ] **Step 8: Commit**

```bash
git add assets/js/training-case.js war-room.php includes/lang/war-room.php tests/js/training-case.test.js tests/TrainingCaseWarRoomSourceTest.php
git commit -m "Add the volunteer's training case screen with offline answers"
```

---

### Task 9: Command's side - staging picker and the «Περιστατικά» card

**Files:**
- Modify: `war-room.php` (dispatch window row near line 6305; staging handler at line 28825; new command card after the scenario card at line 5987; its script)
- Modify: `assets/js/war-room-scenario.js` (`stage()` at line 1199; the item title at line 330 and the list around line 447)
- Modify: `assets/js/training-case.js` (add `renderOverview(data, t)` returning HTML; export it)
- Modify: `includes/lang/war-room.php`
- Test: extend `tests/js/training-case.test.js` and `tests/TrainingCaseWarRoomSourceTest.php`

**Interfaces:**
- Consumes: `mission-scenario.php` actions `case_list`, `case_draw`, `add_item` with `case_id` (Task 5); `training-case.php?action=overview` (Task 7).
- Produces: `VoTrainingCase.renderOverview(data, t)` returns an HTML string for the card body (pure, tested).

- [ ] **Step 1: Failing JS test for the overview HTML** (append to `tests/js/training-case.test.js`)

```js
test('renderOverview shows each volunteer with a state and the per-step breakdown, and escapes names', () => {
    const t = (k, v) => k + (v ? JSON.stringify(v) : '');
    const html = TC.renderOverview({placements: [{
        id: 1, title: 'Περιστατικό <1>', label: 'Σημείο', audience: 'team',
        volunteers: [
            {user_id: 1, name: '<b>Άννα</b>', state: 'done', step: null, score_points: 3, score_max: 4, percent: 75, label: 'good',
             answers: [{step_id: 's1', choice_id: 'a', choice_label: 'Ελέγχω <i>αεραγωγό</i>', rating: 'best', ms_taken: 4200}]},
            {user_id: 2, name: 'Γιώργος', state: 'running', step: 's2', score_points: 2, score_max: 2, percent: 100, label: 'excellent'},
            {user_id: 3, name: 'Μαρία', state: 'new', step: null, score_points: 0, score_max: 0, percent: 0, label: null},
        ],
        steps: [{step_id: 's1', prompt: 'Πρώτο;', choices: [{id: 'a', label: 'Α', rating: 'best', count: 2}, {id: 'b', label: 'Β', rating: 'harmful', count: 1}]}],
    }]}, t);
    assert.ok(!html.includes('<b>Άννα</b>'), 'names are escaped');
    assert.ok(html.includes('&lt;b&gt;Άννα&lt;/b&gt;'));
    assert.ok(html.includes('75%'));
    assert.ok(html.includes('case.state.running'));
    assert.ok(html.includes('case.state.new'));
    assert.ok(html.includes('Πρώτο;'));
    assert.ok(html.includes('>2<'), 'the count of the best choice');
    assert.ok(html.includes('Ελέγχω &lt;i&gt;αεραγωγό&lt;/i&gt;'), 'a run opens to show its choices, escaped');
    assert.ok(html.includes('4s'), 'and the time taken');
});

test('renderOverview says when nothing has been placed', () => {
    assert.ok(TC.renderOverview({placements: []}, k => k).includes('case.overview_empty'));
});
```

- [ ] **Step 2: Run it to verify it fails**, then **Step 3: implement** `renderOverview` in `assets/js/training-case.js` (before the `api` object) and add it to `api`:

```js
    function renderOverview(data, t) {
        const placements = (data && data.placements) || [];
        if (!placements.length) return '<div class="small text-muted">' + escapeHtml(t('case.overview_empty')) + '</div>';
        const stateBadge = v => {
            const cls = {done: 'bg-success', running: 'bg-primary', new: 'bg-secondary'}[v.state] || 'bg-secondary';
            const extra = v.state === 'done' ? ' ' + v.percent + '%' : (v.state === 'running' ? ' · ' + escapeHtml(v.step || '') : '');
            return '<span class="badge ' + cls + '">' + escapeHtml(t('case.state.' + v.state)) + extra + '</span>';
        };
        return placements.map(p => {
            // One run opens to show each step: the choice, its rating and the time taken.
            const runHtml = v => (v.answers && v.answers.length)
                ? '<details class="ms-2"><summary class="small text-muted">' + escapeHtml(t('case.overview_run')) + '</summary><ol class="small mb-1">'
                    + v.answers.map(a => '<li>' + escapeHtml(a.choice_label) + ' <span class="badge '
                        + ({best: 'bg-success', acceptable: 'bg-warning text-dark', harmful: 'bg-danger'}[a.rating] || 'bg-secondary') + '">' + escapeHtml(t('case.rating.' + a.rating)) + '</span>'
                        + (a.ms_taken != null ? ' <span class="text-muted">' + Math.round(a.ms_taken / 1000) + 's</span>' : '') + '</li>').join('')
                    + '</ol></details>'
                : '';
            const people = p.volunteers.map(v => '<li><div class="d-flex justify-content-between"><span>' + escapeHtml(v.name) + '</span>' + stateBadge(v) + '</div>' + runHtml(v) + '</li>').join('');
            const steps = p.steps.map(s => '<div class="small mt-2"><div class="fw-semibold">' + escapeHtml(s.prompt) + '</div>'
                + s.choices.map(c => '<div class="d-flex justify-content-between"><span>' + escapeHtml(c.label) + '</span><span class="badge '
                    + ({best: 'bg-success', acceptable: 'bg-warning text-dark', harmful: 'bg-danger'}[c.rating] || 'bg-secondary') + '">' + c.count + '</span></div>').join('') + '</div>').join('');
            return '<div class="border rounded p-2 mb-2"><div class="fw-semibold">' + escapeHtml(p.title) + '</div>'
                + '<div class="small text-muted mb-1">' + escapeHtml(p.label) + '</div>'
                + '<ul class="list-unstyled small mb-1">' + people + '</ul>'
                + '<details><summary class="small">' + escapeHtml(t('case.overview_breakdown')) + '</summary>' + steps + '</details></div>';
        }).join('');
    }
```

Run `node --test tests/js/training-case.test.js` (expected PASS, 10 tests).

- [ ] **Step 4: The command card in `war-room.php`**

After the scenario card block (after line 5987), add a command-only card for drills:

```php
        <?php if ($canManageWarRoom && !empty($mission['is_training'])): ?>
        <div class="card shadow-sm mb-4 border-warning" data-card-id="trainingCasesCard">
            <div class="card-header bg-warning bg-opacity-10 d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-clipboard2-pulse-fill me-1"></i><?= t('case.card_title') ?></h5>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="trainingCasesRefresh" title="<?= t('case.overview_refresh') ?>"><i class="bi bi-arrow-clockwise"></i></button>
            </div>
            <div class="card-body py-2"><div id="trainingCasesBody" class="small text-muted"><?= t('case.overview_empty') ?></div></div>
        </div>
        <?php endif; ?>
```
and, in the page script near the scenario code, a loader that asks `training-case.php?action=overview&mission_id=` once on load, on the refresh button, and every 20 seconds while the card is visible and the document is not hidden (its own light request, not the 5-second poll), then sets `#trainingCasesBody.innerHTML = VoTrainingCase.renderOverview(res, t)`. Guard on `document.getElementById('trainingCasesBody')`.

- [ ] **Step 5: The staging picker**

In the dispatch window (war-room.php, inside the first `bg-light` row after `dispatchIncidentLine`, line 6304), add, only for a system admin in a drill:

```php
                    <?php if (isSystemAdmin() && !empty($mission['is_training'])): ?>
                    <label class="small fw-semibold mb-0" for="dispatchCaseSelect"><i class="bi bi-clipboard2-pulse me-1"></i><?= t('case.picker_label') ?></label>
                    <select class="form-select form-select-sm" id="dispatchCaseSelect" style="max-width:260px;">
                        <option value=""><?= t('case.picker_none') ?></option>
                    </select>
                    <span class="small text-muted d-none" id="dispatchCaseDrawn"></span>
                    <button type="button" class="btn btn-outline-secondary btn-sm d-none" id="dispatchCaseRedraw"><?= t('case.picker_redraw') ?></button>
                    <label class="small d-none" id="dispatchCaseUnrevWrap"><input type="checkbox" id="dispatchCaseUnrev"> <?= t('case.picker_include_unreviewed') ?></label>
                    <input type="text" id="dispatchCaseBriefing" class="form-control form-control-sm d-none" style="max-width:260px;" maxlength="300" placeholder="<?= t('case.picker_briefing') ?>">
                    <?php endif; ?>
```

In the script (near line 28581) add a small block, active only when `#dispatchCaseSelect` exists: on first show of the modal it POSTs `case_list` to `mission-scenario.php` (CSRF as `wrScenario` does: reuse its `request` through a new exported `window.wrScenario.caseList()`, `caseDraw(filter)`) and fills the select with the cases (`★`/difficulty and a «μη ελεγμένο» suffix when `reviewed` is false), plus an option «Τυχαίο από τη βιβλιοθήκη» with value `random`; choosing `random` calls `caseDraw`, shows the drawn title in `#dispatchCaseDrawn` with the «Άλλο» button to draw again and the include-unreviewed checkbox; an unreviewed case shows the warning `case.picker_unreviewed_warning`. While a case is chosen: `sendBtn` is hidden (a case is staged only, never sent at once) and the briefing field shows. In the staging handler at line 28825 pass the extra fields:

```js
            window.wrScenario.stage(choice.dataset.scPhase, Object.assign({
                team_id: teamSelect.value, type: type, geo: JSON.stringify(geo), label: combinedLabel,
                travel_mode: teamSelect.value === 'all' ? '' : travelSelect.value,
            }, window.wrDispatchCaseFields ? window.wrDispatchCaseFields() : {})).then(res => {
```
where `window.wrDispatchCaseFields()` returns `{}` when no case is chosen, else `{case_id, case_mode: 'chosen'|'random', case_pool: JSON.stringify(filter), briefing}`. Also make the type check: a case needs a point, so when a case is chosen and `isClosed` is true show `case.err_needs_point` instead of staging.

In `assets/js/war-room-scenario.js`: add `caseList()` and `caseDraw(filter)` that call `request('case_list', {})` / `request('case_draw', filter)` and return the parsed answer, export them on `window.wrScenario`; in the item title function (line ~330) return `t('case.plan_title', {title: item.case_title})` when `item.case_title` is set, and give those items the `bi-clipboard2-pulse-fill` icon in the icon expression at line ~447.

- [ ] **Step 6: Texts and tests**

Add to both blocks: `case.card_title` «Περιστατικά» / "Cases"; `case.overview_empty` «Δεν έχει τοποθετηθεί περιστατικό ακόμα.» / "No case has been placed yet."; `case.overview_refresh` «Ανανέωση» / "Refresh"; `case.overview_breakdown` «Επιλογές ανά βήμα» / "Choices per step"; `case.overview_run` «Απαντήσεις» / "Answers"; `case.state.new` «Δεν ξεκίνησε» / "Not started"; `case.state.running` «Σε εξέλιξη» / "In progress"; `case.state.done` «Ολοκληρώθηκε» / "Finished"; `case.picker_label` «Περιστατικό» / "Case"; `case.picker_none` «— χωρίς περιστατικό —» / "- no case -"; `case.picker_random` «Τυχαίο από τη βιβλιοθήκη» / "Random from the library"; `case.picker_redraw` «Άλλο» / "Another"; `case.picker_include_unreviewed` «και μη ελεγμένα» / "include unreviewed"; `case.picker_briefing` «Σύντομη πληροφορία προς τους εθελοντές (προαιρετικό)» / "Short briefing for the volunteers (optional)"; `case.picker_unreviewed_warning` «Αυτό το περιστατικό δεν έχει ελεγχθεί από ιατρικά καταρτισμένο άτομο.» / "This case has not been checked by a medically qualified person."; `case.plan_title` «Περιστατικό: {title}» / "Case: {title}".

Extend `tests/TrainingCaseWarRoomSourceTest.php` to assert: `war-room.php` contains `trainingCasesBody`, `dispatchCaseSelect`, `wrDispatchCaseFields`, and `training-case.php?action=overview`; `assets/js/war-room-scenario.js` contains `case_list`, `case_draw` and `case_title`; the card block is wrapped in `!empty($mission['is_training'])`; and `tests/js/war-room-scenario.test.js` still passes (`node --test tests/js`).

- [ ] **Step 7: Run everything touched, then commit**

Run: `node --test tests/js` and `/c/xampp/php/php.exe vendor/phpunit/phpunit/phpunit tests/TrainingCaseWarRoomSourceTest.php` (expected PASS).

```bash
git add war-room.php assets/js/war-room-scenario.js assets/js/training-case.js includes/lang/war-room.php tests/js/training-case.test.js tests/TrainingCaseWarRoomSourceTest.php
git commit -m "Add the case picker to staging and the Cases card to the Action Room"
```

---

### Task 10: The library page and the editor

**Files:**
- Create: `training-cases.php` (library), `training-case-edit.php` (editor), `assets/js/training-case-editor.js`
- Modify: `includes/header.php` (nav entry next to «Τύποι Αποστολών», line ~1717)
- Modify: `includes/lang/war-room.php` is not used here: these are admin pages written in Greek like `mission-types.php`; use plain Greek strings in the pages (as the training record section does) except validation messages, which come from `trainingCaseErrorText()`.
- Test: `tests/js/training-case-editor.test.js` (new), `tests/TrainingCasePagesSourceTest.php` (new)

**Interfaces:**
- Consumes: Tasks 2 to 4 library functions.
- Produces (`assets/js/training-case-editor.js`, UMD with `module.exports`): `buildDocument(form)` returns the document object from a plain description of the form (`{title, story, closing, findings: [{label,value,unit}], steps: [{id, prompt, hint, choices: [{id,label,rating,feedback,next}]}]}`), turning the ordered `steps` array into the `start` + `steps` map (the first step is the start); `nextStepId(existingIds)` returns `s<n>` not in use; `nextChoiceId(choiceIds)` returns the next letter id.

- [ ] **Step 1: Failing JS test**

```js
const test = require('node:test');
const assert = require('node:assert/strict');
const E = require('../../assets/js/training-case-editor.js');

test('buildDocument makes the first step the start and a map of steps', () => {
    const doc = E.buildDocument({
        title: 'T', story: 'S', closing: 'C', findings: [{label: 'L', value: 'V', unit: ''}, {label: '', value: '', unit: ''}],
        steps: [
            {id: 's1', prompt: 'P', hint: '', choices: [{id: 'a', label: 'A', rating: 'best', feedback: 'F', next: 's2'}, {id: 'b', label: 'B', rating: 'harmful', feedback: 'F', next: ''}]},
            {id: 's2', prompt: 'Q', hint: 'h', choices: []},
        ],
    });
    assert.equal(doc.start, 's1');
    assert.deepEqual(Object.keys(doc.steps), ['s1', 's2']);
    assert.equal(doc.findings.length, 1, 'an empty finding row is dropped');
    assert.equal(doc.steps.s1.choices[1].next, null, 'an empty next means the end');
});

test('nextStepId and nextChoiceId pick unused ids', () => {
    assert.equal(E.nextStepId(['s1', 's2']), 's3');
    assert.equal(E.nextStepId([]), 's1');
    assert.equal(E.nextChoiceId(['a', 'b']), 'c');
    assert.equal(E.nextChoiceId([]), 'a');
});
```

Run `node --test tests/js/training-case-editor.test.js` (expected FAIL, module missing).

- [ ] **Step 2: Create `assets/js/training-case-editor.js`**

```js
/**
 * Training case editor (v3.505.0). One plain form: details, story, findings as rows, ordered step cards with 2 to 5 choices
 * each, closing lessons. This file builds the document from the form and drives the add/remove buttons; the server
 * (trainingCaseSave) validates everything again, so nothing here is a gate. The first step is the start step.
 */
(function (root) {
    'use strict';

    function nextStepId(ids) {
        let n = 1;
        while (ids.indexOf('s' + n) !== -1) n++;
        return 's' + n;
    }

    function nextChoiceId(ids) {
        for (let code = 97; code < 123; code++) {
            const id = String.fromCharCode(code);
            if (ids.indexOf(id) === -1) return id;
        }
        return 'x' + ids.length;
    }

    function buildDocument(form) {
        const steps = {};
        (form.steps || []).forEach(step => {
            steps[step.id] = {
                prompt: step.prompt || '', hint: step.hint || '',
                choices: (step.choices || []).map(c => ({id: c.id, label: c.label || '', rating: c.rating, feedback: c.feedback || '', next: c.next ? c.next : null})),
            };
        });
        return {
            title: form.title || '', story: form.story || '', closing: form.closing || '',
            findings: (form.findings || []).filter(f => (f.label || '').trim() !== '' && (f.value || '').trim() !== ''),
            start: form.steps && form.steps.length ? form.steps[0].id : '',
            steps: steps,
        };
    }

    // The page wiring lives in training-case-edit.php (inline, small): it reads the DOM into the shape above, calls
    // buildDocument() and puts the JSON in the hidden field before the form posts.
    const api = {nextStepId, nextChoiceId, buildDocument};
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    root.VoTrainingCaseEditor = api;
})(typeof window !== 'undefined' ? window : globalThis);
```

Run `node --test tests/js/training-case-editor.test.js` (expected PASS, 2 tests).

- [ ] **Step 3: The library page `training-cases.php`**

A normal admin page in the style of `mission-types.php` (open it and copy its header, `requireLogin()`, layout includes and CSRF helper usage). Requirements:
- `requireLogin(); if (!isSystemAdmin()) { redirect to dashboard with the app's usual no-permission flash }`.
- On load call `trainingCaseSeedStarters()`.
- POST actions (CSRF-checked, then redirect back with a flash): `duplicate` (`trainingCaseDuplicate`), `archive` / `unarchive` (`trainingCaseArchive`), `review` / `unreview` (`trainingCaseSetReviewed`, only for system admins), `export` (download `trainingCaseExport($ids)` as `training-cases.json`, `Content-Type: application/json`, `Content-Disposition: attachment`, for one id or all selected), `import_preview` (reads the uploaded file, `trainingCaseImportRead`, shows the preview table with a per-case verdict and the errors via `trainingCaseErrorText`, with the JSON kept in a hidden field), `import_confirm` (`trainingCaseImport`, shows how many were saved and skipped).
- The list: title, a type tag, difficulty as 1 to 3 stars, language, minutes, «Έναρξη» badge for starters, «Ελεγμένο» badge (with who and when) or «Μη ελεγμένο», archived rows greyed (shown with a «Εμφάνιση αρχειοθετημένων» toggle), and actions: Επεξεργασία (not for starters), Αντιγραφή, Εξαγωγή, Αρχειοθέτηση, Σήμανση ως ελεγμένο.
- All output through `h()`.

- [ ] **Step 4: The editor page `training-case-edit.php`**

`?id=N` edits, no id creates; system admin only; starters redirect to the list with the message «Τα περιστατικά εκκίνησης δεν επεξεργάζονται· κάνε αντιγραφή.». One `<form method="post">` with: details (title, type select from `TRAINING_CASE_TYPES` with Greek labels, difficulty 1 to 3, language, minutes), story textarea, findings rows (label, value, unit, add/remove), step cards (prompt, hint, choices with label, rating select Άριστη/Αποδεκτή/Επικίνδυνη, feedback, next-step select listing the other steps and «Τέλος»; add/remove choice, add/remove step), closing textarea. A small inline script reads the DOM into the `VoTrainingCaseEditor.buildDocument` shape on submit and writes JSON to a hidden `document` field; the server side does `json_decode($_POST['document'])` into `trainingCaseSave($id, $_POST, $decoded, $userId)` and on errors re-renders the form with the entered values and the messages from `trainingCaseErrorText()`. A «Δοκιμή» button opens `VoTrainingCase.open({...})` against the in-memory document without saving a run: add an option `preview: true` to `open()` in `training-case.js` (skips `load()` and `opts.enqueue`, using `opts.document`), and a unit test for that option in `tests/js/training-case.test.js` (`open` needs a DOM, so test only that `answerQueueItem` is not called by checking the exported pure helper `previewOptions({preview:true})` returns `{enqueue: false, load: false}`; add that tiny helper to the module).

- [ ] **Step 5: Nav entry**

In `includes/header.php` after the «Τύποι Αποστολών» item (line ~1722) add, inside its own `<?php if (isSystemAdmin()): ?>` block:

```php
            <li class="nav-item">
                <a class="nav-link <?= $currentPage === 'training-cases' || $currentPage === 'training-case-edit' ? 'active' : '' ?>" href="training-cases.php">
                    <i class="bi bi-clipboard2-pulse"></i> Περιστατικά Εκπαίδευσης
                </a>
            </li>
```

- [ ] **Step 6: Source test and run**

`tests/TrainingCasePagesSourceTest.php` asserts, from the two page sources: both call `isSystemAdmin()` before anything else renders; both check the CSRF token on POST; the library calls `trainingCaseSeedStarters(`, `trainingCaseExport(`, `trainingCaseImportRead(` and `trainingCaseImport(`; the editor posts through `trainingCaseSave(` and renders errors with `trainingCaseErrorText(`; every interpolated variable in a `<?= ... ?>` is wrapped in `h(` or cast to `(int)` (assert there is no `<?= $` that is not followed by a `h(`/`(int)` form, allowing `$csrf`/`csrfField()` helpers by name after you check how `mission-types.php` prints its token); `includes/header.php` links `training-cases.php` inside a `isSystemAdmin()` block.

Run: `node --test tests/js`, `/c/xampp/php/php.exe vendor/phpunit/phpunit/phpunit tests/TrainingCasePagesSourceTest.php`, and `/c/xampp/php/php.exe -l training-cases.php && /c/xampp/php/php.exe -l training-case-edit.php` (expected: pass, no syntax errors).

- [ ] **Step 7: Commit**

```bash
git add training-cases.php training-case-edit.php assets/js/training-case-editor.js assets/js/training-case.js includes/header.php tests/js tests/TrainingCasePagesSourceTest.php
git commit -m "Add the training case library page and editor"
```

---

### Task 11: The training record line and the isolation guard

**Files:**
- Modify: `includes/functions-training-missions.php` (`getVolunteerTrainingRecord`)
- Modify: `includes/training-record-section.php`
- Modify: `tests/TrainingIsolationGuardTest.php`
- Test: `tests/TrainingCaseRecordTest.php` (new)

**Interfaces:**
- Consumes: `trainingCaseRecordForUser(int $userId): array` (Task 6).
- Produces: each row of `getVolunteerTrainingRecord()['rows']` gains `'cases' => int` and `'case_percent' => int|null`.

- [ ] **Step 1: Failing test**

```php
<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

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

    public function test_the_training_record_carries_the_case_results_of_each_drill(): void
    {
        $user = TrainingFixtures::user();
        $drill = TrainingFixtures::mission(true);
        TrainingFixtures::participation($drill['shift'], $user);
        $placement = (int) dbInsert("INSERT INTO mission_case_placements (mission_id, title, snapshot, audience) VALUES (?, 'T', '{}', 'all')", [$drill['mission']]);
        dbInsert("INSERT INTO mission_case_runs (placement_id, user_id, finished_at, score_points, score_max) VALUES (?, ?, NOW(), 7, 8)", [$placement, $user]);

        $record = getVolunteerTrainingRecord($user);
        $row = $record['rows'][0];
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

    public function test_case_scores_never_reach_the_attendance_hours(): void
    {
        $user = TrainingFixtures::user();
        $drill = TrainingFixtures::mission(true);
        TrainingFixtures::participation($drill['shift'], $user, true, 2.0);
        $before = getVolunteerTrainingRecord($user)['hours'];
        $placement = (int) dbInsert("INSERT INTO mission_case_placements (mission_id, title, snapshot, audience) VALUES (?, 'T', '{}', 'all')", [$drill['mission']]);
        dbInsert("INSERT INTO mission_case_runs (placement_id, user_id, finished_at, score_points, score_max) VALUES (?, ?, NOW(), 2, 2)", [$placement, $user]);
        $this->assertSame($before, getVolunteerTrainingRecord($user)['hours']);
    }
}
```

Run (expected FAIL: undefined index `cases`).

- [ ] **Step 2: Implement**

In `getVolunteerTrainingRecord` (functions-training-missions.php), after the `$rows` query add `$caseStats = trainingCaseRecordForUser($userId);` inside a `function_exists('trainingCaseRecordForUser')` guard (this file is also loaded where the new file may not be: keep the guard), and in the loop add `'cases' => $caseStats[(int) $r['mission_id']]['cases'] ?? 0, 'case_percent' => $caseStats[(int) $r['mission_id']]['percent'] ?? null,` to each output row; update the `@return` docblock.

In `includes/training-record-section.php`, inside the `foreach ($trainingRecord['rows'] as $row)` item, after the existing line content, add:

```php
                    <?php if (!empty($row['cases'])): ?>
                        <div class="small text-muted">Περιστατικά: <?= (int) $row['cases'] ?>, μέσος όρος <?= (int) $row['case_percent'] ?>%</div>
                    <?php endif; ?>
```
(adapt the markup to the list item's existing structure; read the rest of the file first).

- [ ] **Step 3: Extend the guard**

In `tests/TrainingIsolationGuardTest.php` add a test that none of the new case files read participation hours or attendance: assert that `includes/functions-training-case-runs.php`, `includes/functions-training-cases.php` and `training-case.php` do not contain `actual_hours`, `attended` or `points` as column reads of `participation_requests` (a regex `participation_requests[^;]*(actual_hours|attended)`), and that `trainingCaseRecordForUser` reads only `mission_case_*` tables. Run the whole guard: `/c/xampp/php/php.exe vendor/phpunit/phpunit/phpunit tests/TrainingIsolationGuardTest.php tests/TrainingCaseRecordTest.php tests/TrainingMissionSourceTest.php` (expected PASS).

- [ ] **Step 4: Commit**

```bash
git add includes/functions-training-missions.php includes/training-record-section.php tests/TrainingIsolationGuardTest.php tests/TrainingCaseRecordTest.php
git commit -m "Show training case results in the volunteer's training record"
```

---

### Task 12: Spec update, release preparation and verification

**Files:**
- Modify: `docs/superpowers/specs/2026-11-20-training-cases-design.md`
- Modify: `config.php` (`APP_VERSION` 3.504.1 to 3.505.0), `sw.js` (`CACHE_VERSION` `vo-v3.504.1` to `vo-v3.505.0`)
- Create: `docs/superpowers/specs/2026-11-20-training-cases-browser-checklist.md`

- [ ] **Step 1: Fold the five differences into the spec**

Edit the spec so it matches what was built (the six differences listed at the top of this plan): in "Decisions taken" item 7 and in section 1 replace "a new kind of scenario item (`case`) / added to the `kind` ENUM" with "a `dispatch` scenario item whose payload carries a `case` block (no new kind, no ENUM migration)"; remove `radius_m` everywhere; in section 2 step 2 replace the per-person arrival hook with the team arrival and the teammates' notification; in section 2 step 5 replace "the order is completed for that volunteer" with "the order's «Ολοκληρώθηκε» stays the team's step"; add the document fetch and the MEDIUMTEXT widening to section 1; replace the "Points to verify" list with a short "Verified while planning" list pointing at the plan. Set `Status:` to "approved, plan written".

- [ ] **Step 2: Version bump**

Bump `APP_VERSION` and `CACHE_VERSION` together (CI fails if they disagree). Check `config.php` for any other version marker with `grep -n "3.504.1" config.php sw.js`.

- [ ] **Step 3: Write the browser checklist** (what the automated run cannot do)

A checklist with these items: create a drill, stage a starter case at a point for a team through the dispatch window; check the plan card shows «Περιστατικό: ...»; play the phase; on a second device or profile as a team member: receive the order, no case button before arrival, «Έφτασα», the case opens, answer all steps (feedback after each, no going back), see the score and paths; go offline mid-case (browser offline mode), answer, go back online and confirm the answers appear in command's «Περιστατικά» card; a second volunteer of the same team gets their own run and the arrival notification; command's card shows the per-step counts; the training record shows «Περιστατικά: n, μέσος όρος»; the library page: starters present and unreviewed, duplicate then edit, validation errors for a loop, export then import; a real (non-training) mission offers no case picker and the endpoint refuses; the staged unreviewed case shows the warning; the case text renders as text (an `<b>` typed in the editor shows literally).

- [ ] **Step 4: Full verification**

Run: `/c/xampp/php/php.exe vendor/phpunit/phpunit/phpunit` (expected: only the 2 baseline failures, `ScenarioAreaTest` and `ScenarioBuildingTest`) and `node --test tests/js` (expected: all pass). Run `/c/xampp/php/php.exe -l` on every new or changed PHP file.

- [ ] **Step 5: Commit, and stop**

```bash
git add docs/superpowers/specs config.php sw.js
git commit -m "Prepare v3.505.0: sync the spec, bump the version, add the browser checklist"
```

Do not push, open a PR, merge or release: those happen only when the user asks. Report what was verified automatically and point at the browser checklist for what was not.
