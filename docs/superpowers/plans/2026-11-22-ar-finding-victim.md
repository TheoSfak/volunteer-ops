# Finding the victim (sub-project 3a) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A rescue team finds a training-case victim with the phone's GPS: «Είσαι κοντά» at 50 m, «Βρέθηκε» at 10 m, which opens the case. A polygon (area) order hides the victim's spot; a point order shows it.

**Architecture:** A placement (`mission_case_placements`) gets a spot and a `spot_hidden` flag; a new table `mission_case_finds` records, per team, who found it. The server gate opens the case on arrival (point order only) or on a find. The phone watches its own high-accuracy GPS only while an un-found case with a spot exists, classifies the distance with a small pure module, queues a `case_found` report like it queues answers, and opens the player. The server re-checks the distance before recording a find.

**Tech Stack:** PHP 8.2 (procedural, PDO helpers `dbFetchOne/dbFetchAll/dbFetchValue/dbExecute/dbInsert`), MariaDB/MySQL, PHPUnit, vanilla JS (classic scripts, Node `node:test` for pure parts), Leaflet.

Spec: `docs/superpowers/specs/2026-11-22-ar-finding-victim-design.md`. Read it first.

## Global Constraints

- Distances are the named constants `TRAINING_CASE_FOUND_M = 10`, `TRAINING_CASE_CLOSE_M = 50`, `TRAINING_CASE_ACCURACY_CREDIT_M = 15` (PHP) and `FOUND_M`, `CLOSE_M`, `ACCURACY_CREDIT_M` (JS); never literals elsewhere. A test pins the two sets equal.
- Proximity rule, identical on phone and server: `effective = distance - min(max(accuracy, 0), 15)`; `found` when `effective <= 10`, `close` when `effective <= 50`, else `far`.
- Training missions only. A real mission never gets a spot, a find or a card.
- Every user-visible string is a `t()` key in both the Greek and English tables of `includes/lang/war-room.php`; Greek is the default.
- The «close» cue never shows a distance or a direction. A hidden spot is never drawn on any rescuer screen.
- Working-tree files are mostly CRLF: use the Edit tool, never `sed -i`.
- Git: work on branch `feature/ar-finding-victim`; commit after each task; commit messages carry **no** `Co-Authored-By` / "Generated with" lines (the user's global rule overrides the harness reminder). Do **not** push, merge, tag or release.
- Run PHPUnit as `/c/xampp/php/php.exe vendor/phpunit/phpunit/phpunit <file>` and JS as `node --test tests/js/<file>`. Six known baseline failures (TrainingCasePagesSourceTest 2, TrainingGatingTest 1, TrainingMissionSchemaTest 3) are CRLF-related and not yours.
- After changing the schema, bring the local test DB forward: `/c/xampp/php/php.exe tests/migrate-fixture.php`.

## File Structure

| File | Responsibility |
|---|---|
| `includes/migrations.php`, `config.php`, `sql/schema.sql` | Migration 226 (spot columns, `mission_case_finds`) |
| `includes/functions-training-case-runs.php` | Constants, `trainingCaseProximity()`, placement spot, gate, `trainingCaseRecordFound()`, notice, cards, overview |
| `includes/functions-scenario.php` | `addScenarioCaseItem()` accepts a polygon + hidden spot |
| `mission-scenario.php` | Passes `spot_lat`/`spot_lng` to staging |
| `includes/functions-warroom.php` | The poll's `case` block (spot, found, available) |
| `training-case.php` | `action=found` |
| `assets/js/training-case-find.js` (new) | Pure distance/classify/tracker module |
| `assets/js/training-case.js` | `prefetch()`, `caseStageFields` spot, overview «found» line |
| `assets/js/media-queue.js` | `caseAnswerDeferral` also keeps a placement's `case_found` first |
| `war-room.php` | Script tag, GPS watcher, found handler, queue sender, order-row button, staging spot pin |
| `includes/lang/war-room.php` | New keys (el + en) |
| `tests/TrainingCaseSchemaTest.php`, `tests/TrainingCaseFindTest.php` (new), `tests/TrainingCaseFindSourceTest.php` (new), `tests/js/training-case-find.test.js` (new) and neighbours | Tests |

---

### Task 1: Schema (migration 226)

**Files:**
- Modify: `includes/migrations.php` (new entry after version 225, before the closing `];`)
- Modify: `config.php:15` (`DB_SCHEMA_VERSION` 225 to 226)
- Modify: `sql/schema.sql` (placements table; new table before the final `SET FOREIGN_KEY_CHECKS = 1;`)
- Test: `tests/TrainingCaseSchemaTest.php`

**Interfaces:**
- Produces: columns `mission_case_placements.spot_lat DECIMAL(9,6) NULL`, `.spot_lng DECIMAL(9,6) NULL`, `.spot_hidden TINYINT(1) NOT NULL DEFAULT 0`; table `mission_case_finds (id, placement_id, scope_key VARCHAR(20), found_by, found_at, lat, lng, accuracy_m)` with `UNIQUE (placement_id, scope_key)`.

- [ ] **Step 1: Write the failing tests** — add to `tests/TrainingCaseSchemaTest.php` before the closing brace:

```php
    public function test_a_placement_holds_a_spot_and_a_team_finds_it_once(): void
    {
        $user = TrainingFixtures::user();
        $drill = TrainingFixtures::mission(true);
        $placement = (int) dbInsert(
            "INSERT INTO mission_case_placements (mission_id, title, snapshot, audience, spot_lat, spot_lng, spot_hidden) VALUES (?, 'T', '{}', 'all', 35.300000, 25.100000, 1)",
            [$drill['mission']]
        );
        $row = dbFetchOne("SELECT spot_lat, spot_lng, spot_hidden FROM mission_case_placements WHERE id = ?", [$placement]);
        $this->assertEqualsWithDelta(35.3, (float) $row['spot_lat'], 0.000001);
        $this->assertEqualsWithDelta(25.1, (float) $row['spot_lng'], 0.000001);
        $this->assertSame(1, (int) $row['spot_hidden']);

        dbExecute("INSERT INTO mission_case_finds (placement_id, scope_key, found_by, lat, lng, accuracy_m) VALUES (?, 't7', ?, 35.3, 25.1, 8)", [$placement, $user]);
        $this->assertSame(0, dbExecute("INSERT IGNORE INTO mission_case_finds (placement_id, scope_key, found_by) VALUES (?, 't7', ?)", [$placement, $user]));
        dbExecute("INSERT INTO mission_case_finds (placement_id, scope_key, found_by) VALUES (?, 't8', ?)", [$placement, $user]);
        $this->assertSame(2, (int) dbFetchValue("SELECT COUNT(*) FROM mission_case_finds WHERE placement_id = ?", [$placement]));
    }

    public function test_a_placement_with_no_spot_is_valid_and_deleting_it_deletes_its_finds(): void
    {
        $drill = TrainingFixtures::mission(true);
        $placement = (int) dbInsert("INSERT INTO mission_case_placements (mission_id, title, snapshot, audience) VALUES (?, 'T', '{}', 'all')", [$drill['mission']]);
        $row = dbFetchOne("SELECT spot_lat, spot_hidden FROM mission_case_placements WHERE id = ?", [$placement]);
        $this->assertNull($row['spot_lat']);
        $this->assertSame(0, (int) $row['spot_hidden']);
        dbExecute("INSERT INTO mission_case_finds (placement_id, scope_key) VALUES (?, 'u1')", [$placement]);
        dbExecute("DELETE FROM mission_case_placements WHERE id = ?", [$placement]);
        $this->assertSame(0, (int) dbFetchValue("SELECT COUNT(*) FROM mission_case_finds WHERE placement_id = ?", [$placement]));
    }

    public function test_the_migration_and_the_schema_agree_on_the_spot_columns(): void
    {
        $root = dirname(__DIR__);
        $schema = (string) file_get_contents($root . '/sql/schema.sql');
        $migrations = (string) file_get_contents($root . '/includes/migrations.php');
        foreach (['spot_lat', 'spot_lng', 'spot_hidden', 'mission_case_finds', 'uq_case_find'] as $needle) {
            $this->assertStringContainsString($needle, $schema, "schema.sql: $needle");
            $this->assertStringContainsString($needle, $migrations, "migrations.php: $needle");
        }
        $this->assertSame(226, DB_SCHEMA_VERSION);
    }
```

- [ ] **Step 2: Run to verify they fail**

Run: `/c/xampp/php/php.exe vendor/phpunit/phpunit/phpunit tests/TrainingCaseSchemaTest.php`
Expected: the three new tests FAIL (unknown column / 225 vs 226).

- [ ] **Step 3: Implement.** In `config.php:15` set `define('DB_SCHEMA_VERSION', 226);`.

In `includes/migrations.php`, after the closing `],` of the version-225 entry and before the `];` that ends `$migrations`, add:

```php
        [
            'version'     => 226,
            'description' => 'Training cases: finding the victim - where a placement\'s victim is (spot_lat, spot_lng, spot_hidden) ' .
                'and which team found it (mission_case_finds, one row per placement and team).',
            'up' => function () {
                if (!dbFetchOne("SHOW COLUMNS FROM `mission_case_placements` LIKE 'spot_lat'")) {
                    dbExecute("ALTER TABLE `mission_case_placements`
                        ADD COLUMN `spot_lat` DECIMAL(9,6) NULL COMMENT 'Where the victim is: a point order = the point, an area order = the hidden spot' AFTER `draw_mode`,
                        ADD COLUMN `spot_lng` DECIMAL(9,6) NULL AFTER `spot_lat`,
                        ADD COLUMN `spot_hidden` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = an area order: the spot is never shown, only found' AFTER `spot_lng`");
                }
                dbExecute("CREATE TABLE IF NOT EXISTS `mission_case_finds` (
                    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                    `placement_id` INT UNSIGNED NOT NULL,
                    `scope_key` VARCHAR(20) NOT NULL COMMENT 't<team_id>, or u<user_id> for a volunteer with no team',
                    `found_by` INT UNSIGNED NULL,
                    `found_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `lat` DECIMAL(9,6) NULL,
                    `lng` DECIMAL(9,6) NULL,
                    `accuracy_m` SMALLINT UNSIGNED NULL,
                    UNIQUE KEY `uq_case_find` (`placement_id`, `scope_key`),
                    FOREIGN KEY (`placement_id`) REFERENCES `mission_case_placements`(`id`) ON DELETE CASCADE,
                    FOREIGN KEY (`found_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
            },
        ],
```

In `sql/schema.sql`, inside `CREATE TABLE IF NOT EXISTS \`mission_case_placements\`` add after the `draw_mode` line:

```sql
    `spot_lat` DECIMAL(9,6) NULL COMMENT 'Where the victim is: a point order = the point, an area order = the hidden spot',
    `spot_lng` DECIMAL(9,6) NULL,
    `spot_hidden` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = an area order: the spot is never shown, only found',
```

and after the `mission_case_answers` table (before `SET FOREIGN_KEY_CHECKS = 1;`):

```sql
CREATE TABLE IF NOT EXISTS `mission_case_finds` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `placement_id` INT UNSIGNED NOT NULL,
    `scope_key` VARCHAR(20) NOT NULL COMMENT 't<team_id>, or u<user_id> for a volunteer with no team',
    `found_by` INT UNSIGNED NULL,
    `found_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `lat` DECIMAL(9,6) NULL,
    `lng` DECIMAL(9,6) NULL,
    `accuracy_m` SMALLINT UNSIGNED NULL,
    UNIQUE KEY `uq_case_find` (`placement_id`, `scope_key`),
    FOREIGN KEY (`placement_id`) REFERENCES `mission_case_placements`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`found_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- [ ] **Step 4: Migrate the test DB and run**

Run: `/c/xampp/php/php.exe tests/migrate-fixture.php` then `/c/xampp/php/php.exe vendor/phpunit/phpunit/phpunit tests/TrainingCaseSchemaTest.php tests/MigrationVersionsTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add includes/migrations.php config.php sql/schema.sql tests/TrainingCaseSchemaTest.php
git commit -m "Training cases: migration 226 (spot columns, mission_case_finds)"
```

---

### Task 2: Proximity rule (PHP)

**Files:**
- Modify: `includes/functions-training-case-runs.php` (top, after the `defined` guard)
- Create: `tests/TrainingCaseFindTest.php`

**Interfaces:**
- Produces: constants `TRAINING_CASE_FOUND_M`, `TRAINING_CASE_CLOSE_M`, `TRAINING_CASE_ACCURACY_CREDIT_M`; `trainingCaseProximity(float $distanceM, ?float $accuracyM): string` returning `'found'|'close'|'far'`. Uses the existing `gpsDistanceMeters()` (`includes/functions-warroom.php`) for distances in later tasks.

- [ ] **Step 1: Write the failing test** — create `tests/TrainingCaseFindTest.php`:

```php
<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Finding the victim (v3.506.0): the proximity rule, the gate, the found report, the card, the overview. */
final class TrainingCaseFindTest extends TestCase
{
    public function test_the_distances_are_the_agreed_ones(): void
    {
        $this->assertSame(10, TRAINING_CASE_FOUND_M);
        $this->assertSame(50, TRAINING_CASE_CLOSE_M);
        $this->assertSame(15, TRAINING_CASE_ACCURACY_CREDIT_M);
    }

    public function test_the_boundaries(): void
    {
        $this->assertSame('found', trainingCaseProximity(10.0, null));
        $this->assertSame('close', trainingCaseProximity(10.1, null));
        $this->assertSame('close', trainingCaseProximity(50.0, null));
        $this->assertSame('far', trainingCaseProximity(50.1, null));
    }

    public function test_gps_error_is_credited_but_capped(): void
    {
        $this->assertSame('found', trainingCaseProximity(14.0, 10.0));   // 14 - 10 = 4
        $this->assertSame('found', trainingCaseProximity(25.0, 15.0));   // credit 15 -> 10
        $this->assertSame('close', trainingCaseProximity(25.1, 100.0));  // credit capped at 15 -> 10.1
        $this->assertSame('found', trainingCaseProximity(9.0, -5.0));    // a negative accuracy credits nothing
        $this->assertSame('close', trainingCaseProximity(64.9, 15.0));   // 49.9
        $this->assertSame('far', trainingCaseProximity(65.1, 15.0));     // 50.1
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `/c/xampp/php/php.exe vendor/phpunit/phpunit/phpunit tests/TrainingCaseFindTest.php`
Expected: FAIL, undefined constant `TRAINING_CASE_FOUND_M`.

- [ ] **Step 3: Implement** — in `includes/functions-training-case-runs.php`, directly after the `if (!defined('VOLUNTEEROPS')) { die(...); }` block add:

```php
/**
 * Finding the victim (v3.506.0). Within CLOSE_M of the spot the phone says «κοντά», within FOUND_M «βρέθηκε». A phone's GPS
 * error is credited to the volunteer, capped so one bad fix cannot find the victim from afar. The same three numbers and the
 * same rule are in assets/js/training-case-find.js; a test keeps them equal.
 */
const TRAINING_CASE_FOUND_M = 10;
const TRAINING_CASE_CLOSE_M = 50;
const TRAINING_CASE_ACCURACY_CREDIT_M = 15;

/** 'found', 'close' or 'far' for a distance in metres and the fix's reported accuracy in metres (null = unknown). */
function trainingCaseProximity(float $distanceM, ?float $accuracyM): string {
    $credit = min(max((float) $accuracyM, 0.0), (float) TRAINING_CASE_ACCURACY_CREDIT_M);
    $effective = $distanceM - $credit;
    if ($effective <= TRAINING_CASE_FOUND_M) {
        return 'found';
    }
    return $effective <= TRAINING_CASE_CLOSE_M ? 'close' : 'far';
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `/c/xampp/php/php.exe vendor/phpunit/phpunit/phpunit tests/TrainingCaseFindTest.php`
Expected: 3 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add includes/functions-training-case-runs.php tests/TrainingCaseFindTest.php
git commit -m "Training cases: the proximity rule (found 10 m, close 50 m, GPS credit)"
```

---

### Task 3: Staging a polygon case with a hidden spot, and recording the spot on the placement

**Files:**
- Modify: `includes/functions-scenario.php` (`addScenarioCaseItem()` at ~line 942)
- Modify: `mission-scenario.php` (the `add_item` case branch at ~line 129)
- Modify: `includes/functions-training-case-runs.php` (`trainingCaseCreatePlacement()`)
- Modify: `includes/lang/war-room.php` (el near line 554, en near line 4527)
- Test: `tests/TrainingCaseFindTest.php`

**Interfaces:**
- Consumes: `pointInPolygon(float $lat, float $lng, array $geo): bool` (`includes/functions-warroom.php`, geo = list of `[lat, lng]`).
- Produces: `addScenarioCaseItem()` accepts `type` `'point'` or `'polygon'`; `$input['spot_lat']`, `$input['spot_lng']` (required for a polygon, must be inside it). The staged `case` block gains `'spot' => ['lat' => float, 'lng' => float]` and `'spot_hidden' => bool`. `trainingCaseCreatePlacement()` stores them in `spot_lat/spot_lng/spot_hidden`. New lang keys `case.err_needs_point_or_area`, `case.err_spot_needed`, `case.err_spot_outside`.

- [ ] **Step 1: Write the failing tests** — add to `TrainingCaseFindTest.php` (add the setUp/helpers shown here to the class, above the existing tests):

```php
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
        $this->teamId = (int) dbInsert("INSERT INTO mission_teams (mission_id, codename, team_number, created_by) VALUES (?, 'ΑΛΦΑ', 1, ?)", [$this->drill['mission'], $this->admin]);
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

    private const SQUARE = [[35.40, 25.20], [35.40, 25.21], [35.41, 25.21], [35.41, 25.20]];

    private function pointForm(array $extra = []): array
    {
        return $extra + [
            'team_id' => (string) $this->teamId, 'type' => 'point', 'geo' => json_encode(['lat' => 35.33, 'lng' => 25.14]),
            'label' => '', 'travel_mode' => 'foot', 'case_id' => $this->caseId, 'case_mode' => 'chosen',
        ];
    }

    private function areaForm(array $extra = []): array
    {
        return $extra + [
            'team_id' => (string) $this->teamId, 'type' => 'polygon', 'geo' => json_encode(self::SQUARE),
            'label' => '', 'travel_mode' => 'foot', 'case_id' => $this->caseId, 'case_mode' => 'chosen',
        ];
    }

    private function stagedCase(int $itemId): array
    {
        return json_decode((string) dbFetchValue("SELECT payload FROM mission_scenario_items WHERE id = ?", [$itemId]), true)['case'];
    }

    public function test_a_point_case_takes_the_point_as_its_spot(): void
    {
        $r = addScenarioCaseItem($this->mission($this->drill), $this->phase($this->drill), $this->admin, $this->pointForm());
        $this->assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        $case = $this->stagedCase($r['id']);
        $this->assertEqualsWithDelta(35.33, $case['spot']['lat'], 0.000001);
        $this->assertEqualsWithDelta(25.14, $case['spot']['lng'], 0.000001);
        $this->assertFalse($case['spot_hidden']);
    }

    public function test_an_area_case_needs_a_hidden_spot_inside_the_area(): void
    {
        $m = $this->mission($this->drill);
        $phase = $this->phase($this->drill);
        $none = addScenarioCaseItem($m, $phase, $this->admin, $this->areaForm());
        $this->assertFalse($none['ok']);
        $this->assertSame(t('case.err_spot_needed'), $none['error']);

        $outside = addScenarioCaseItem($m, $phase, $this->admin, $this->areaForm(['spot_lat' => '35.50', 'spot_lng' => '25.20']));
        $this->assertFalse($outside['ok']);
        $this->assertSame(t('case.err_spot_outside'), $outside['error']);

        $ok = addScenarioCaseItem($m, $phase, $this->admin, $this->areaForm(['spot_lat' => '35.4050', 'spot_lng' => '25.2050']));
        $this->assertTrue($ok['ok'], (string) ($ok['error'] ?? ''));
        $case = $this->stagedCase($ok['id']);
        $this->assertTrue($case['spot_hidden']);
        $this->assertEqualsWithDelta(35.405, $case['spot']['lat'], 0.000001);
        $this->assertSame(0, (int) dbFetchValue("SELECT COUNT(*) FROM mission_scenario_items WHERE phase_id = ? AND payload LIKE '%35.5%'", [$phase]), 'a refused spot leaves nothing behind');
    }

    public function test_a_spot_given_for_a_point_is_ignored(): void
    {
        $r = addScenarioCaseItem($this->mission($this->drill), $this->phase($this->drill), $this->admin, $this->pointForm(['spot_lat' => '35.9', 'spot_lng' => '25.9']));
        $this->assertTrue($r['ok']);
        $this->assertEqualsWithDelta(35.33, $this->stagedCase($r['id'])['spot']['lat'], 0.000001);
    }

    public function test_a_real_mission_refuses_both_kinds(): void
    {
        $m = $this->mission($this->real);
        $phase = $this->phase($this->real);
        $this->assertFalse(addScenarioCaseItem($m, $phase, $this->admin, $this->pointForm())['ok']);
        $this->assertFalse(addScenarioCaseItem($m, $phase, $this->admin, $this->areaForm(['spot_lat' => '35.405', 'spot_lng' => '25.205']))['ok']);
    }

    public function test_a_sent_order_records_the_spot_on_its_placement(): void
    {
        $m = $this->mission($this->drill);
        $phase = $this->phase($this->drill);
        $point = addScenarioCaseItem($m, $phase, $this->admin, $this->pointForm());
        $area = addScenarioCaseItem($m, $phase, $this->admin, $this->areaForm(['spot_lat' => '35.405', 'spot_lng' => '25.205']));
        foreach ([$point['id'], $area['id']] as $itemId) {
            $item = dbFetchOne("SELECT * FROM mission_scenario_items WHERE id = ?", [$itemId]);
            $payload = json_decode($item['payload'], true);
            $dispatch = (int) dbInsert(
                "INSERT INTO mission_dispatch_points (mission_id, team_id, type, geo, label, created_by) VALUES (?, ?, ?, ?, 'Περιστατικό', ?)",
                [$this->drill['mission'], $this->teamId, $payload['type'], json_encode($payload['geo']), $this->admin]
            );
            trainingCaseCreatePlacement($this->drill['mission'], (int) $itemId, $dispatch, $payload);
        }
        $rows = dbFetchAll("SELECT spot_lat, spot_lng, spot_hidden FROM mission_case_placements WHERE mission_id = ? ORDER BY id", [$this->drill['mission']]);
        $this->assertCount(2, $rows);
        $this->assertEqualsWithDelta(35.33, (float) $rows[0]['spot_lat'], 0.000001);
        $this->assertSame(0, (int) $rows[0]['spot_hidden']);
        $this->assertEqualsWithDelta(35.405, (float) $rows[1]['spot_lat'], 0.000001);
        $this->assertSame(1, (int) $rows[1]['spot_hidden']);
    }

    public function test_an_older_staged_point_without_a_spot_block_still_gets_its_point_as_spot(): void
    {
        $dispatch = (int) dbInsert(
            "INSERT INTO mission_dispatch_points (mission_id, team_id, type, geo, label, created_by) VALUES (?, ?, 'point', ?, 'x', ?)",
            [$this->drill['mission'], $this->teamId, json_encode(['lat' => 35.2, 'lng' => 25.3]), $this->admin]
        );
        $payload = ['case' => ['case_id' => 0, 'title' => 'T', 'draw_mode' => 'chosen', 'briefing' => '', 'document' => ['title' => 'T', 'start' => 's1', 'steps' => []]]];
        $id = (int) trainingCaseCreatePlacement($this->drill['mission'], 0, $dispatch, $payload);
        $row = dbFetchOne("SELECT spot_lat, spot_lng, spot_hidden FROM mission_case_placements WHERE id = ?", [$id]);
        $this->assertEqualsWithDelta(35.2, (float) $row['spot_lat'], 0.000001);
        $this->assertSame(0, (int) $row['spot_hidden']);
    }
```

- [ ] **Step 2: Run to verify they fail**

Run: `/c/xampp/php/php.exe vendor/phpunit/phpunit/phpunit tests/TrainingCaseFindTest.php`
Expected: the new tests FAIL (polygon refused with `case.err_needs_point`, no `spot` key).

- [ ] **Step 3: Implement.**

`includes/lang/war-room.php`: in the Greek table replace the `case.err_needs_point` line (554) with the three lines below and keep nothing else on it; in the English table (4527) likewise.

Greek:
```php
        'case.err_needs_point' => 'Το περιστατικό μπαίνει σε σημείο ή σε περιοχή.',
        'case.err_needs_point_or_area' => 'Το περιστατικό μπαίνει σε σημείο ή σε περιοχή.',
        'case.err_spot_needed' => 'Βάλτε το κρυφό σημείο του θύματος μέσα στην περιοχή.',
        'case.err_spot_outside' => 'Το κρυφό σημείο του θύματος πρέπει να είναι μέσα στην περιοχή.',
```
English:
```php
        'case.err_needs_point' => 'A case goes at a point or in an area.',
        'case.err_needs_point_or_area' => 'A case goes at a point or in an area.',
        'case.err_spot_needed' => 'Place the victim\'s hidden spot inside the area.',
        'case.err_spot_outside' => 'The victim\'s hidden spot must be inside the area.',
```
(`case.err_needs_point` is kept because the dispatch window still references it until Task 10 replaces that reference with the new key.)

`includes/functions-scenario.php` — replace the type check in `addScenarioCaseItem()` and add the spot handling. The body from `if (($input['type'] ?? '') !== 'point') {` down to the `return addScenarioDispatchItem(...)` becomes:

```php
    $type = $input['type'] ?? '';
    if (!in_array($type, ['point', 'polygon'], true)) {
        return ['ok' => false, 'error' => t('case.err_needs_point_or_area')];
    }
    $case = trainingCaseLoad((int) ($input['case_id'] ?? 0));
    if (!$case || $case['archived_at'] !== null || trainingCaseValidate($case['doc'])) {
        return ['ok' => false, 'error' => t('case.err_not_found')];
    }
    // Where the victim is. A point order: the point itself. An area order: a hidden spot the commander pinned, which must lie
    // inside the area (an area that cannot be read is left to prepareMissionDispatch(), which refuses it with its own message).
    $geo = is_array($input['geo'] ?? null) ? $input['geo'] : json_decode((string) ($input['geo'] ?? ''), true);
    $spot = null;
    if ($type === 'point') {
        if (is_array($geo) && isset($geo['lat'], $geo['lng']) && is_numeric($geo['lat']) && is_numeric($geo['lng'])) {
            $spot = ['lat' => round((float) $geo['lat'], 6), 'lng' => round((float) $geo['lng'], 6)];
        }
    } elseif (is_array($geo) && count($geo) >= 3) {
        $spotLat = $input['spot_lat'] ?? '';
        $spotLng = $input['spot_lng'] ?? '';
        if (!is_numeric($spotLat) || !is_numeric($spotLng) || abs((float) $spotLat) > 90 || abs((float) $spotLng) > 180) {
            return ['ok' => false, 'error' => t('case.err_spot_needed')];
        }
        if (!pointInPolygon((float) $spotLat, (float) $spotLng, $geo)) {
            return ['ok' => false, 'error' => t('case.err_spot_outside')];
        }
        $spot = ['lat' => round((float) $spotLat, 6), 'lng' => round((float) $spotLng, 6)];
    }
    if (trim((string) ($input['label'] ?? '')) === '') {
        $input['label'] = t('case.default_label');
    }
    $pool = is_array($input['case_pool'] ?? null) ? array_intersect_key($input['case_pool'], array_flip(['type', 'difficulty', 'language'])) : [];
    $block = [
        'case_id'        => (int) $case['id'],
        'format_version' => (int) $case['format_version'],
        'title'          => $case['title'],
        'draw_mode'      => ($input['case_mode'] ?? '') === 'random' ? 'random' : 'chosen',
        'pool'           => $pool,
        'briefing'       => trainingCasePlain($input['briefing'] ?? '', 300),
        // Whether the case had been reviewed when it was staged, recorded beside the snapshot (never inside the document, and
        // never copied to a placement): editing or re-reviewing the library case later does not change what this order plays.
        'reviewed'       => !empty($case['reviewed']),
        'reviewed_at'    => $case['reviewed_at'] ?? null,
        'document'       => $case['doc'],
    ];
    if ($spot !== null) {
        $block['spot'] = $spot;
        $block['spot_hidden'] = $type === 'polygon';
    }
    return addScenarioDispatchItem($mission, $phaseId, $userId, $input, ['case' => $block]);
}
```
(Keep the docblock above the function; update its first sentences to say "at a point or in an area" and document `spot_lat`/`spot_lng`. Keep the `isTrainingMission` guard at the top unchanged.)

`mission-scenario.php`: in the `add_item` case branch add `'spot_lat' => post('spot_lat'), 'spot_lng' => post('spot_lng'),` to the array merged into `$form`.

`includes/functions-training-case-runs.php` — in `trainingCaseCreatePlacement()`: before the insert compute

```php
    // The victim's spot (v3.506.0). A staged block carries it; an older staged POINT order (staged before this release) has
    // none, and its own point is the spot. An area order without one gets none and keeps opening on arrival.
    $spot = is_array($case['spot'] ?? null) && is_numeric($case['spot']['lat'] ?? null) && is_numeric($case['spot']['lng'] ?? null)
        ? [(float) $case['spot']['lat'], (float) $case['spot']['lng']] : null;
    $hidden = $spot !== null && !empty($case['spot_hidden']) ? 1 : 0;
    if ($spot === null) {
        $order = dbFetchOne("SELECT type, geo FROM mission_dispatch_points WHERE id = ? AND mission_id = ?", [$dispatchId, $missionId]);
        $geo = $order ? json_decode((string) $order['geo'], true) : null;
        if ($order && $order['type'] === 'point' && is_array($geo) && isset($geo['lat'], $geo['lng'])) {
            $spot = [(float) $geo['lat'], (float) $geo['lng']];
        }
    }
```
and extend the INSERT: column list `... draw_mode, spot_lat, spot_lng, spot_hidden)` with `?` placeholders (13 total) and the values `$spot ? round($spot[0], 6) : null, $spot ? round($spot[1], 6) : null, $hidden` appended after the draw-mode value.

- [ ] **Step 4: Run to verify they pass, and the neighbours still do**

Run: `/c/xampp/php/php.exe vendor/phpunit/phpunit/phpunit tests/TrainingCaseFindTest.php tests/TrainingCaseScenarioTest.php tests/TrainingCaseRunTest.php`
Expected: PASS. If `TrainingCaseScenarioTest` pins the old `case.err_needs_point` text for a polygon, update that assertion to `case.err_needs_point_or_area`'s text only when it is about an area being refused; a polygon is no longer refused, so rewrite that test to expect the new `err_spot_needed` instead.

- [ ] **Step 5: Commit**

```bash
git add includes/functions-scenario.php includes/functions-training-case-runs.php mission-scenario.php includes/lang/war-room.php tests/TrainingCaseFindTest.php tests/TrainingCaseScenarioTest.php
git commit -m "Training cases: an area order carries a hidden victim spot; placements record it"
```

---

### Task 4: The gate and the team's find (server core)

**Files:**
- Modify: `includes/functions-training-case-runs.php` (`trainingCaseGate()`, new `trainingCaseTeamFound()`, `trainingCaseRecordFound()`, notice refactor)
- Modify: `includes/lang/war-room.php`
- Test: `tests/TrainingCaseFindTest.php`

**Interfaces:**
- Consumes: `gpsDistanceMeters()`, `dispatchProgressScopeKey(?int, int): string`, `getUserTeamIdForMission()`, `trainingCaseProximity()`.
- Produces: `trainingCaseTeamFound(int $placementId, string $scopeKey): bool`; `trainingCaseRecordFound(int $placementId, int $userId, float $lat, float $lng, ?float $accuracyM): array` returning `['ok' => true, 'duplicate' => bool]` or `['ok' => false, 'error' => text, 'code' => 'refused'|'not_found']`; `trainingCaseNotifyFound(int $missionId, int $dispatchId, ?int $teamId, int $foundByUserId): void`; gate error key `case.err_not_found_yet`. Lang keys `case.err_not_found_yet`, `case.err_too_far`, `case.err_no_spot`, `case.found_title`, `case.found_message`.

- [ ] **Step 1: Write the failing tests** — add to `TrainingCaseFindTest.php` a second fixture block and tests (put the helper methods beside the existing ones; the class now has two setUps' worth of fields, so add these fields and extend `setUp()` with the lines marked NEW):

Add fields: `private int $otherTeamId; private int $member; private int $mate; private int $stranger; private int $pointDispatch; private int $areaDispatch; private int $pointPlacement; private int $areaPlacement;`

Append to `setUp()` (NEW):

```php
        $this->otherTeamId = (int) dbInsert("INSERT INTO mission_teams (mission_id, codename, team_number, created_by) VALUES (?, 'ΒΗΤΑ', 2, ?)", [$this->drill['mission'], $this->admin]);
        $this->member = $this->volunteer('Member', $this->teamId);
        $this->mate = $this->volunteer('Mate', $this->teamId);
        $this->stranger = $this->volunteer('Stranger', $this->otherTeamId);
        $doc = ['title' => 'Δοκιμή', 'story' => 'Ιστορία.', 'closing' => 'Τέλος.', 'findings' => [], 'start' => 's1', 'steps' => [
            's1' => ['prompt' => 'Πρώτο;', 'hint' => '', 'choices' => [
                ['id' => 'a', 'label' => 'Α', 'rating' => 'best', 'feedback' => 'Σωστά.', 'next' => null],
                ['id' => 'b', 'label' => 'Β', 'rating' => 'harmful', 'feedback' => 'Λάθος.', 'next' => null],
            ]],
        ]];
        $this->pointDispatch = $this->order('point', ['lat' => 35.3, 'lng' => 25.1]);
        $this->areaDispatch = $this->order('polygon', self::SQUARE);
        $this->pointPlacement = (int) trainingCaseCreatePlacement($this->drill['mission'], 0, $this->pointDispatch, ['case' => [
            'case_id' => 0, 'title' => 'Σημείο', 'draw_mode' => 'chosen', 'briefing' => 'Φωνή', 'document' => $doc,
            'spot' => ['lat' => 35.3, 'lng' => 25.1], 'spot_hidden' => false]]);
        $this->areaPlacement = (int) trainingCaseCreatePlacement($this->drill['mission'], 0, $this->areaDispatch, ['case' => [
            'case_id' => 0, 'title' => 'Τομέας', 'draw_mode' => 'chosen', 'briefing' => 'Αγνοείται', 'document' => $doc,
            'spot' => ['lat' => 35.405, 'lng' => 25.205], 'spot_hidden' => true]]);
```

Helpers:

```php
    private function volunteer(string $name, int $team): int
    {
        $u = TrainingFixtures::user($name);
        TrainingFixtures::participation($this->drill['shift'], $u, false, null);
        dbExecute("INSERT INTO mission_team_members (mission_id, team_id, user_id) VALUES (?, ?, ?)", [$this->drill['mission'], $team, $u]);
        return $u;
    }

    private function order(string $type, array $geo): int
    {
        return (int) dbInsert(
            "INSERT INTO mission_dispatch_points (mission_id, team_id, type, geo, label, created_by) VALUES (?, ?, ?, ?, 'Περιστατικό', ?)",
            [$this->drill['mission'], $this->teamId, $type, json_encode($geo), $this->admin]
        );
    }

    private function arrive(int $dispatchId, int $teamId): void
    {
        dbExecute("INSERT INTO mission_dispatch_progress (dispatch_id, team_id, scope_key, arrived_at) VALUES (?, ?, ?, NOW())", [$dispatchId, $teamId, 't' . $teamId]);
    }

    /** A point `$metres` north of (lat, lng). One degree of latitude is 111194.9 m on the sphere gpsDistanceMeters() uses. */
    private function north(float $lat, float $metres): float
    {
        return $lat + $metres / 111194.9;
    }
```

Tests:

```php
    public function test_a_point_case_opens_on_arrival_or_on_a_find(): void
    {
        $p = trainingCasePlacement($this->pointPlacement);
        $this->assertSame('case.err_not_arrived', trainingCaseGate($p, $this->member, true));
        $this->arrive($this->pointDispatch, $this->teamId);
        $this->assertNull(trainingCaseGate($p, $this->member, true));
    }

    public function test_an_area_case_does_not_open_on_arrival(): void
    {
        $p = trainingCasePlacement($this->areaPlacement);
        $this->arrive($this->areaDispatch, $this->teamId);
        $this->assertSame('case.err_not_found_yet', trainingCaseGate($p, $this->member, true));
        $this->assertNull(trainingCaseGate($p, $this->member, false), 'the document may still be loaded (prefetch)');
    }

    public function test_a_find_opens_the_case_for_the_whole_team_only(): void
    {
        $p = trainingCasePlacement($this->areaPlacement);
        $r = trainingCaseRecordFound($this->areaPlacement, $this->member, 35.405, 25.205, 8.0);
        $this->assertTrue($r['ok'], (string) ($r['error'] ?? ''));
        $this->assertFalse($r['duplicate']);
        $this->assertNull(trainingCaseGate($p, $this->member, true));
        $this->assertNull(trainingCaseGate($p, $this->mate, true), 'a teammate who did not walk there may play');
        $this->assertSame('case.err_not_audience', trainingCaseGate($p, $this->stranger, false));
        $this->assertSame('t' . $this->teamId, dbFetchValue("SELECT scope_key FROM mission_case_finds WHERE placement_id = ?", [$this->areaPlacement]));
    }

    public function test_the_server_rechecks_the_distance_with_the_same_rule(): void
    {
        $lat = 35.405;
        $forget = fn() => dbExecute("DELETE FROM mission_case_finds WHERE placement_id = ?", [$this->areaPlacement]);

        $this->assertTrue(trainingCaseRecordFound($this->areaPlacement, $this->member, $this->north($lat, 9.0), 25.205, null)['ok']);
        $forget();

        $this->assertTrue(trainingCaseRecordFound($this->areaPlacement, $this->member, $this->north($lat, 14.0), 25.205, 10.0)['ok'], '14 m with a 10 m error is a find');
        $forget();

        $far = trainingCaseRecordFound($this->areaPlacement, $this->member, $this->north($lat, 30.0), 25.205, null);
        $this->assertFalse($far['ok']);
        $this->assertSame('refused', $far['code'], 'a refusal the phone must drop, not retry for ever');

        // The credit for a poor fix is capped at 15 m: 30 m with a 100 m error is 15 m effective, not found.
        $this->assertFalse(trainingCaseRecordFound($this->areaPlacement, $this->member, $this->north($lat, 30.0), 25.205, 100.0)['ok']);
        $this->assertSame(0, (int) dbFetchValue("SELECT COUNT(*) FROM mission_case_finds WHERE placement_id = ?", [$this->areaPlacement]));
    }

    public function test_a_second_report_is_a_quiet_duplicate_and_another_team_cannot_report(): void
    {
        $first = trainingCaseRecordFound($this->areaPlacement, $this->member, 35.405, 25.205, 5.0);
        $again = trainingCaseRecordFound($this->areaPlacement, $this->mate, 35.405, 25.205, 5.0);
        $this->assertTrue($again['ok']);
        $this->assertTrue($again['duplicate']);
        $this->assertSame(1, (int) dbFetchValue("SELECT COUNT(*) FROM mission_case_finds WHERE placement_id = ?", [$this->areaPlacement]));
        $foreign = trainingCaseRecordFound($this->areaPlacement, $this->stranger, 35.405, 25.205, 5.0);
        $this->assertFalse($foreign['ok']);
        $this->assertSame('refused', $foreign['code']);
    }

    public function test_a_real_mission_records_nothing(): void
    {
        dbExecute("UPDATE missions SET is_training = 0 WHERE id = ?", [$this->drill['mission']]);
        $this->assertFalse(trainingCaseRecordFound($this->areaPlacement, $this->member, 35.405, 25.205, 5.0)['ok']);
        dbExecute("UPDATE missions SET is_training = 1 WHERE id = ?", [$this->drill['mission']]);
        $this->assertSame(0, (int) dbFetchValue("SELECT COUNT(*) FROM mission_case_finds"));
    }

    public function test_a_placement_with_no_spot_cannot_be_found(): void
    {
        $dispatch = $this->order('point', ['lat' => 35.6, 'lng' => 25.6]);
        $id = (int) trainingCaseCreatePlacement($this->drill['mission'], 0, $dispatch, ['case' => ['case_id' => 0, 'title' => 'X', 'draw_mode' => 'chosen', 'briefing' => '', 'document' => ['title' => 'X', 'start' => 's1', 'steps' => []]]]);
        dbExecute("UPDATE mission_case_placements SET spot_lat = NULL, spot_lng = NULL WHERE id = ?", [$id]);
        $this->assertFalse(trainingCaseRecordFound($id, $this->member, 35.6, 25.6, 5.0)['ok']);
    }

    public function test_the_other_members_are_told_once_and_the_finder_is_not(): void
    {
        dbExecute("UPDATE shifts SET start_time = ?, end_time = ? WHERE id = ?", [date('Y-m-d H:i:s', time() - 3600), date('Y-m-d H:i:s', time() + 3600), $this->drill['shift']]);
        foreach ([$this->member, $this->mate, $this->stranger] as $u) {
            dbExecute("INSERT INTO mission_action_room_participants (mission_id, user_id) VALUES (?, ?)", [$this->drill['mission'], $u]);
        }
        trainingCaseRecordFound($this->areaPlacement, $this->member, 35.405, 25.205, 5.0);
        trainingCaseRecordFound($this->areaPlacement, $this->member, 35.405, 25.205, 5.0);
        $count = fn(int $u): int => (int) dbFetchValue("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND data LIKE ?", [$u, '%case-' . $this->areaPlacement . '%']);
        $this->assertSame(1, $count($this->mate));
        $this->assertSame(0, $count($this->member));
        $this->assertSame(0, $count($this->stranger));
    }
```

- [ ] **Step 2: Run to verify they fail**

Run: `/c/xampp/php/php.exe vendor/phpunit/phpunit/phpunit tests/TrainingCaseFindTest.php`
Expected: FAIL, undefined function `trainingCaseRecordFound`.

- [ ] **Step 3: Implement.**

Lang keys (Greek then English tables, next to the other `case.*` keys):

```php
        'case.err_not_found_yet' => 'Το περιστατικό ανοίγει όταν βρείτε το θύμα.',
        'case.err_too_far' => 'Δεν είστε αρκετά κοντά στο θύμα.',
        'case.err_no_spot' => 'Αυτό το περιστατικό δεν έχει σημείο θύματος.',
        'case.found_title' => 'Βρέθηκε το θύμα',
        'case.found_message' => 'Η ομάδα σου βρήκε το θύμα. Άνοιξε το περιστατικό: {title}',
```
```php
        'case.err_not_found_yet' => 'The case opens when you find the victim.',
        'case.err_too_far' => 'You are not close enough to the victim.',
        'case.err_no_spot' => 'This case has no victim spot.',
        'case.found_title' => 'Victim found',
        'case.found_message' => 'Your team found the victim. Open the case: {title}',
```

In `includes/functions-training-case-runs.php`:

Add after `trainingCasePlacementWithDoc()`:

```php
/** Has this team (or lone volunteer: the scope key from dispatchProgressScopeKey()) found the victim of the placement? */
function trainingCaseTeamFound(int $placementId, string $scopeKey): bool {
    return (bool) dbFetchValue("SELECT 1 FROM mission_case_finds WHERE placement_id = ? AND scope_key = ?", [$placementId, $scopeKey]);
}
```

In `trainingCaseGate()` replace the `if ($needArrival) { ... }` block with:

```php
    if ($needArrival) {
        // Found is the team's, like arrival (v3.506.0): once anyone on the team has found the victim, the whole team may play.
        if (trainingCaseTeamFound((int) $placement['id'], dispatchProgressScopeKey($myTeam, $userId))) {
            return null;
        }
        // An area order hides its victim: arriving somewhere in the area proves nothing, only finding opens it.
        if (!empty($placement['spot_hidden'])) {
            return 'case.err_not_found_yet';
        }
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
```
Update the docblock of the gate: "...and - to PLAY - their team has arrived (a point order) or found the victim."

Refactor the notice: rename the body of `trainingCaseNotifyArrival()` into a private helper and add the found wrapper. Replace the whole `trainingCaseNotifyArrival` function with:

```php
/**
 * Tells the other on-duty members of a team that a case is now open for them (arrival is the team's, and so is a find, so only one
 * person's press or GPS fix records it). Never throws; sends nothing for an order without a case.
 */
function trainingCaseNotifyTeam(int $missionId, int $dispatchId, ?int $teamId, int $exceptUserId, string $titleKey, string $messageKey): void {
    try {
        $placement = trainingCasePlacementForDispatch($dispatchId);
        if (!$placement || !$teamId) {
            return;
        }
        $members = array_map('intval', array_column(
            dbFetchAll("SELECT user_id FROM mission_team_members WHERE mission_id = ? AND team_id = ?", [$missionId, $teamId]),
            'user_id'
        ));
        $recipients = array_values(array_diff(array_intersect(activeMissionRecipientIds($missionId), $members), [$exceptUserId]));
        foreach ($recipients as $uid) {
            $lang = getUserLanguage($uid);
            sendNotification($uid, t($titleKey, [], $lang), t($messageKey, ['title' => (string) $placement['briefing'] !== '' ? $placement['briefing'] : $placement['title']], $lang), 'info', '', [
                'url' => rtrim(BASE_URL, '/') . '/war-room.php?id=' . $missionId,
                'tag' => 'case-' . (int) $placement['id'],
                'bannerMission' => $missionId,
            ]);
        }
    } catch (Throwable $e) {
        error_log('[training-cases] team notice: ' . $e->getMessage());
    }
}

/** The team has just arrived at a case's order: tell its other on-duty members that the case is open. */
function trainingCaseNotifyArrival(int $missionId, int $dispatchId, ?int $teamId, int $arrivedUserId): void {
    trainingCaseNotifyTeam($missionId, $dispatchId, $teamId, $arrivedUserId, 'case.arrived_title', 'case.arrived_message');
}

/** The team has just found the victim of a case: tell its other on-duty members that the case is open. */
function trainingCaseNotifyFound(int $missionId, int $dispatchId, ?int $teamId, int $foundByUserId): void {
    trainingCaseNotifyTeam($missionId, $dispatchId, $teamId, $foundByUserId, 'case.found_title', 'case.found_message');
}
```
(The existing `TrainingCaseRunTest` source test at ~line 454 only needs the string `trainingCaseNotifyArrival(` in `advanceMissionDispatch`; unchanged. If a test greps the old log prefix `[training-cases] arrival notice`, change it to `[training-cases] team notice`.)

Add after `trainingCaseStartRun()`:

```php
/**
 * A volunteer's phone reports that the team has found the victim (v3.506.0). Needs a placement the volunteer may open (the gate,
 * without the arrival) and that has a spot. The server does not take the phone's word: it measures the reported position against
 * the spot with the same rule as the phone (trainingCaseProximity()), so a hand-made request from the sofa cannot open a hidden
 * case. A report for a team that already found it is a quiet duplicate. The first find is audited and the rest of the team told.
 * ['ok' => true, 'duplicate' => bool] or ['ok' => false, 'error' => text, 'code' => 'refused'|'not_found']. A refusal carries the
 * code 'refused' on purpose: that is what makes the phone's offline queue DROP the report instead of retrying it for ever.
 */
function trainingCaseRecordFound(int $placementId, int $userId, float $lat, float $lng, ?float $accuracyM): array {
    $placement = trainingCasePlacement($placementId);
    if (!$placement || !$placement['doc']) {
        return ['ok' => false, 'error' => t('case.err_gone'), 'code' => 'not_found'];
    }
    $gate = trainingCaseGate($placement, $userId, false);
    if ($gate !== null) {
        return ['ok' => false, 'error' => t($gate), 'code' => 'refused'];
    }
    if ($placement['spot_lat'] === null || $placement['spot_lng'] === null) {
        return ['ok' => false, 'error' => t('case.err_no_spot'), 'code' => 'refused'];
    }
    $distance = gpsDistanceMeters($lat, $lng, (float) $placement['spot_lat'], (float) $placement['spot_lng']);
    if (trainingCaseProximity($distance, $accuracyM) !== 'found') {
        return ['ok' => false, 'error' => t('case.err_too_far'), 'code' => 'refused'];
    }
    $missionId = (int) $placement['mission_id'];
    $teamId = getUserTeamIdForMission($missionId, $userId) ?: null;
    $inserted = dbExecute(
        "INSERT IGNORE INTO mission_case_finds (placement_id, scope_key, found_by, lat, lng, accuracy_m) VALUES (?, ?, ?, ?, ?, ?)",
        [$placementId, dispatchProgressScopeKey($teamId, $userId), $userId, round($lat, 6), round($lng, 6), $accuracyM !== null ? (int) round(min(max($accuracyM, 0.0), 65000.0)) : null]
    );
    if ($inserted > 0) {
        logAudit('team_found_case', 'mission_case_placements', $placementId, null, ['mission_id' => $missionId, 'team_id' => $teamId, 'user_id' => $userId]);
        if ($placement['dispatch_id'] !== null) {
            trainingCaseNotifyFound($missionId, (int) $placement['dispatch_id'], $teamId, $userId);
        }
    }
    return ['ok' => true, 'duplicate' => $inserted === 0];
}
```

- [ ] **Step 4: Run to verify they pass**

Run: `/c/xampp/php/php.exe vendor/phpunit/phpunit/phpunit tests/TrainingCaseFindTest.php tests/TrainingCaseRunTest.php tests/TrainingCaseConcurrencyTest.php`
Expected: PASS. (If `dbExecute` returns 0 for a no-op `INSERT IGNORE` and 1 for a new row, as `TrainingCaseSchemaTest` already relies on, the duplicate logic is right.)

- [ ] **Step 5: Commit**

```bash
git add includes/functions-training-case-runs.php includes/lang/war-room.php tests/TrainingCaseFindTest.php
git commit -m "Training cases: the gate opens on a find; the server records and re-checks it"
```

---

### Task 5: The `found` endpoint

**Files:**
- Modify: `training-case.php`
- Test: `tests/TrainingCaseFindSourceTest.php` (new)

**Interfaces:**
- Consumes: `trainingCaseRecordFound()`.
- Produces: `POST training-case.php` `action=found`, params `mission_id`, `placement_id`, `lat`, `lng`, `accuracy`; replies the function's array as JSON. Unreadable coordinates reply `ok:false, code:'refused'`.

- [ ] **Step 1: Write the failing test** — create `tests/TrainingCaseFindSourceTest.php`:

```php
<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** The scripts that carry finding the victim, read from their source. */
final class TrainingCaseFindSourceTest extends TestCase
{
    private function src(string $file): string
    {
        return str_replace("\r\n", "\n", (string) file_get_contents(dirname(__DIR__) . '/' . $file));
    }

    public function test_the_found_action_is_post_only_behind_the_csrf_check_and_validates_the_position(): void
    {
        $src = $this->src('training-case.php');
        $csrf = strpos($src, 'hash_equals($sessionToken');
        $found = strpos($src, "\$action === 'found' && isPost()");
        $this->assertNotFalse($csrf);
        $this->assertNotFalse($found);
        $this->assertGreaterThan($csrf, $found, 'the CSRF check comes first');
        $branch = substr($src, $found, 900);
        $this->assertStringContainsString('trainingCaseRecordFound(', $branch);
        $this->assertStringContainsString("is_numeric(post('lat'))", $branch);
        $this->assertStringContainsString("'code' => 'refused'", $branch, 'a position that can never be valid is dropped, not retried');
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `/c/xampp/php/php.exe vendor/phpunit/phpunit/phpunit tests/TrainingCaseFindSourceTest.php`
Expected: FAIL, `$found` is false.

- [ ] **Step 3: Implement** — in `training-case.php`, update the header docblock with the new line `POST action=found  mission_id, placement_id, lat, lng, accuracy   the team found the victim (re-checked against the spot)`, and add before the final `echo json_encode(['ok' => false, 'error' => t('common.unknown_action'), ...` line:

```php
    // The team found the victim (v3.506.0). The position is the phone's; the server measures it against the spot itself.
    if ($action === 'found' && isPost()) {
        if (!is_numeric(post('lat')) || !is_numeric(post('lng')) || abs((float) post('lat')) > 90 || abs((float) post('lng')) > 180) {
            echo json_encode(['ok' => false, 'error' => t('common.invalid_request'), 'code' => 'refused'], TRAINING_CASE_JSON_FLAGS);
            exit;
        }
        $accuracy = is_numeric(post('accuracy')) ? (float) post('accuracy') : null;
        echo json_encode(trainingCaseRecordFound((int) $placement['id'], $userId, (float) post('lat'), (float) post('lng'), $accuracy), TRAINING_CASE_JSON_FLAGS);
        exit;
    }

```

- [ ] **Step 4: Run to verify it passes**

Run: `/c/xampp/php/php.exe vendor/phpunit/phpunit/phpunit tests/TrainingCaseFindSourceTest.php tests/TrainingCasePagesSourceTest.php`
Expected: the new test PASSES; `TrainingCasePagesSourceTest` shows only its 2 known baseline failures.

- [ ] **Step 5: Commit**

```bash
git add training-case.php tests/TrainingCaseFindSourceTest.php
git commit -m "Training cases: the found action of the case endpoint"
```

---

### Task 6: The poll's case card (spot, found, available)

**Files:**
- Modify: `includes/functions-training-case-runs.php` (`trainingCaseCardsForDispatches()`)
- Modify: `includes/functions-warroom.php` (the `'case'` block at ~line 1578)
- Test: `tests/TrainingCaseFindTest.php`

**Interfaces:**
- Produces: each card gains `spot` (`['lat' => float, 'lng' => float]` or `null`), `hidden` bool, `found` bool, `found_at` (string or null); for a hidden placement not yet found `title` is the neutral `t('case.default_label')`. The dispatch row's `case` block: `spot` is `null` unless `can_play`; `available` = `can_play` and (`found` or (not hidden and (team arrived or order completed))).

- [ ] **Step 1: Write the failing tests** — add to `TrainingCaseFindTest.php`:

```php
    public function test_the_card_carries_the_spot_the_found_state_and_a_neutral_title_while_hidden(): void
    {
        $cards = trainingCaseCardsForDispatches([$this->pointDispatch, $this->areaDispatch], $this->member);
        $point = $cards[$this->pointDispatch];
        $this->assertEqualsWithDelta(35.3, $point['spot']['lat'], 0.000001);
        $this->assertFalse($point['hidden']);
        $this->assertFalse($point['found']);
        $this->assertSame('Σημείο', $point['title']);

        $area = $cards[$this->areaDispatch];
        $this->assertTrue($area['hidden']);
        $this->assertSame(t('case.default_label'), $area['title'], 'the title never gives a hidden victim away');
        $this->assertEqualsWithDelta(35.405, $area['spot']['lat'], 0.000001);

        trainingCaseRecordFound($this->areaPlacement, $this->mate, 35.405, 25.205, 5.0);
        $after = trainingCaseCardsForDispatches([$this->areaDispatch], $this->member)[$this->areaDispatch];
        $this->assertTrue($after['found'], 'found is the team\'s: the other member sees it too');
        $this->assertNotNull($after['found_at']);
        $this->assertSame('Τομέας', $after['title'], 'the title comes back once found');
        $other = trainingCaseCardsForDispatches([$this->areaDispatch], $this->stranger)[$this->areaDispatch];
        $this->assertFalse($other['found'], 'another team has not found it');
    }

    public function test_the_poll_block_gives_the_spot_and_availability_only_to_those_who_may_play(): void
    {
        $src = str_replace("\r\n", "\n", (string) file_get_contents(dirname(__DIR__) . '/includes/functions-warroom.php'));
        $at = strpos($src, "'case'         => isset(\$caseCards[\$dispatchId])");
        $this->assertNotFalse($at);
        $block = substr($src, $at, 900);
        $this->assertStringContainsString("'spot' => \$isApprovedParticipant && \$eligible ? \$caseCards[\$dispatchId]['spot'] : null", $block);
        $this->assertStringContainsString("\$caseCards[\$dispatchId]['found']", $block);
        $this->assertStringContainsString("!\$caseCards[\$dispatchId]['hidden']", $block);
    }
```

- [ ] **Step 2: Run to verify they fail**

Run: `/c/xampp/php/php.exe vendor/phpunit/phpunit/phpunit tests/TrainingCaseFindTest.php`
Expected: FAIL, undefined index `spot`.

- [ ] **Step 3: Implement.**

`trainingCaseCardsForDispatches()` — change the SELECT to also read `p.mission_id, p.spot_lat, p.spot_lng, p.spot_hidden`, and after `$rows` is loaded (inside the `try`, so a not-yet-migrated site still returns `[]` silently) add the finds lookup:

```php
        // Which of these the volunteer's team has already found (v3.506.0): the scope is the team's of that mission.
        $found = [];
        $scopeOfPlacement = [];
        $scopeByMission = [];
        foreach ($rows as $r) {
            $m = (int) $r['mission_id'];
            $scopeByMission[$m] ??= dispatchProgressScopeKey(getUserTeamIdForMission($m, $userId) ?: null, $userId);
            $scopeOfPlacement[(int) $r['id']] = $scopeByMission[$m];
        }
        if ($scopeOfPlacement) {
            $placementIds = array_keys($scopeOfPlacement);
            $inP = implode(',', array_fill(0, count($placementIds), '?'));
            foreach (dbFetchAll("SELECT placement_id, found_at, scope_key FROM mission_case_finds WHERE placement_id IN ($inP)", $placementIds) as $f) {
                if (($scopeOfPlacement[(int) $f['placement_id']] ?? null) === $f['scope_key']) {
                    $found[(int) $f['placement_id']] = $f['found_at'];
                }
            }
        }
```
and in the output loop build the card as:

```php
        $state = $row['run_id'] === null ? 'new' : ($row['finished_at'] !== null ? 'done' : 'running');
        $hidden = (int) $row['spot_hidden'] === 1;
        $foundAt = $found[(int) $row['id']] ?? null;
        $out[(int) $row['dispatch_id']] = [
            'placement_id' => (int) $row['id'],
            // A hidden victim's case title could give it away: the card says the neutral word until the team has found it.
            'title' => $hidden && $foundAt === null ? t('case.default_label') : $row['title'],
            'briefing' => (string) $row['briefing'], 'state' => $state,
            'percent' => $state === 'done' ? trainingCaseScorePercent((int) $row['score_points'], (int) $row['score_max']) : null,
            'label' => $state === 'done' ? trainingCaseScoreLabel((int) $row['score_points'], (int) $row['score_max']) : null,
            'spot' => $row['spot_lat'] !== null && $row['spot_lng'] !== null ? ['lat' => (float) $row['spot_lat'], 'lng' => (float) $row['spot_lng']] : null,
            'hidden' => $hidden, 'found' => $foundAt !== null, 'found_at' => $foundAt,
        ];
```
`includes/functions-warroom.php` — replace the `'case'` entry (lines ~1578-1580) with:

```php
            'case'         => isset($caseCards[$dispatchId])
                ? array_merge($caseCards[$dispatchId], [
                    'can_play' => $isApprovedParticipant && $eligible,
                    // The victim's spot only goes to someone who may play it (v3.506.0); it is used by the phone's own GPS check and is never drawn for a hidden case.
                    'spot' => $isApprovedParticipant && $eligible ? $caseCards[$dispatchId]['spot'] : null,
                    // Open: my team has found the victim, or (a point order, whose victim is shown) has arrived or finished it.
                    'available' => $isApprovedParticipant && $eligible && ($caseCards[$dispatchId]['found']
                        || (!$caseCards[$dispatchId]['hidden'] && (bool) ($teamArrived || $mine['completed']))),
                ])
                : null,
```
Update the comment above it: "`available` is the TEAM's find, or for a point order its arrival (or finishing) - the same facts the gate of training-case.php checks".

- [ ] **Step 4: Run to verify they pass**

Run: `/c/xampp/php/php.exe vendor/phpunit/phpunit/phpunit tests/TrainingCaseFindTest.php tests/TrainingCaseRunTest.php`
Expected: PASS. Update any `TrainingCaseRunTest` assertion that pins the exact card array to `assertSame`-compare only the keys it cares about.

- [ ] **Step 5: Commit**

```bash
git add includes/functions-training-case-runs.php includes/functions-warroom.php tests/TrainingCaseFindTest.php tests/TrainingCaseRunTest.php
git commit -m "Training cases: the poll's case card carries the spot, the team's find and availability"
```

---

### Task 7: Command's overview shows who found it

**Files:**
- Modify: `includes/functions-training-case-runs.php` (`trainingCaseOverview()`)
- Modify: `assets/js/training-case.js` (`renderOverview`)
- Modify: `includes/lang/war-room.php`
- Test: `tests/TrainingCaseFindTest.php`, `tests/js/training-case.test.js`

**Interfaces:**
- Produces: each overview placement gains `'finds' => [['scope_key' => string, 'found_at' => 'Y-m-d H:i:s', 'by' => string|null], ...]` and `'hidden' => bool`. `renderOverview` prints «Βρέθηκε HH:MM από <όνομα>» per find. Key `case.overview_found` with `{time}` and `{name}`.

- [ ] **Step 1: Write the failing tests.**

PHP, add to `TrainingCaseFindTest.php`:

```php
    public function test_the_overview_lists_who_found_each_case_and_when(): void
    {
        trainingCaseRecordFound($this->areaPlacement, $this->member, 35.405, 25.205, 5.0);
        $byId = [];
        foreach (trainingCaseOverview($this->drill['mission'])['placements'] as $p) {
            $byId[$p['id']] = $p;
        }
        $this->assertSame([], $byId[$this->pointPlacement]['finds']);
        $this->assertFalse($byId[$this->pointPlacement]['hidden']);
        $this->assertTrue($byId[$this->areaPlacement]['hidden']);
        $this->assertCount(1, $byId[$this->areaPlacement]['finds']);
        $this->assertSame('Member', $byId[$this->areaPlacement]['finds'][0]['by']);
        $this->assertSame('t' . $this->teamId, $byId[$this->areaPlacement]['finds'][0]['scope_key']);
    }
```

JS, append to `tests/js/training-case.test.js`:

```js
test('renderOverview says who found the victim and when, escaping the name', () => {
    const t = (k, v) => k === 'case.overview_found' ? 'FOUND ' + v.time + ' ' + v.name : k;
    const html = TC.renderOverview({placements: [{
        id: 1, title: 'Τ', label: '', audience: 'all', volunteers: [], steps: [],
        finds: [{scope_key: 't3', found_at: '2026-11-22 14:32:10', by: '<b>Ann</b>'}],
    }]}, t);
    assert.match(html, /FOUND 14:32 &lt;b&gt;Ann&lt;\/b&gt;/);
    const none = TC.renderOverview({placements: [{id: 1, title: 'Τ', label: '', audience: 'all', volunteers: [], steps: [], finds: []}]}, t);
    assert.doesNotMatch(none, /FOUND/);
});
```

- [ ] **Step 2: Run to verify they fail**

Run: `/c/xampp/php/php.exe vendor/phpunit/phpunit/phpunit tests/TrainingCaseFindTest.php` and `node --test tests/js/training-case.test.js`
Expected: both FAIL (`finds` undefined / no «FOUND» text).

- [ ] **Step 3: Implement.**

Lang (Greek / English):
```php
        'case.overview_found' => 'Βρέθηκε {time} από {name}',
```
```php
        'case.overview_found' => 'Found {time} by {name}',
```

`trainingCaseOverview()` — inside the placement loop, before `$out[] = [...]`:

```php
        $finds = [];
        foreach (dbFetchAll(
            "SELECT f.scope_key, f.found_at, u.name AS by_name FROM mission_case_finds f LEFT JOIN users u ON u.id = f.found_by
             WHERE f.placement_id = ? ORDER BY f.found_at, f.id",
            [(int) $placement['id']]
        ) as $f) {
            $finds[] = ['scope_key' => $f['scope_key'], 'found_at' => $f['found_at'], 'by' => $f['by_name']];
        }
```
and add `'hidden' => !empty($placement['spot_hidden']), 'finds' => $finds,` to the `$out[] = [...]` array.

`assets/js/training-case.js` `renderOverview` — in the returned template, after the `p.label` line and before `<ul class="list-unstyled ...">`, insert:

```js
                + (p.finds || []).map(f => '<div class="small text-success"><i class="bi bi-geo-alt-fill me-1"></i>'
                    + escapeHtml(t('case.overview_found', {time: String(f.found_at || '').slice(11, 16), name: f.by || ''})) + '</div>').join('')
```

- [ ] **Step 4: Run to verify they pass**

Run: `/c/xampp/php/php.exe vendor/phpunit/phpunit/phpunit tests/TrainingCaseFindTest.php` and `node --test tests/js/training-case.test.js`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add includes/functions-training-case-runs.php assets/js/training-case.js includes/lang/war-room.php tests/TrainingCaseFindTest.php tests/js/training-case.test.js
git commit -m "Training cases: command's overview shows who found each victim"
```

---

### Task 8: The phone's pure finding module

**Files:**
- Create: `assets/js/training-case-find.js`
- Create: `tests/js/training-case-find.test.js`

**Interfaces:**
- Produces (global `VoCaseFind`, also `module.exports`): `FOUND_M`, `CLOSE_M`, `ACCURACY_CREDIT_M`; `distanceM(a, b)` (points `{lat, lng}`); `classify(distanceM, accuracyM)` → `'far'|'close'|'found'`; `watchList(dispatches)` → `[{dispatch_id, placement_id, spot: {lat, lng}, briefing}]` for each dispatch whose `case` has `can_play`, a `spot`, is not `found` and its state is not `'done'`; `createTracker({onClose(card, distance), onFound(card, fix, distance)})` returning `{check(cards, fix)}` where `fix = {lat, lng, acc}`; `close` fires once per placement, `found` fires once and latches. Never throws on a malformed card.

- [ ] **Step 1: Write the failing test** — create `tests/js/training-case-find.test.js`:

```js
// Run with: node --test tests/js
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const F = require('../../assets/js/training-case-find.js');

const north = (lat, metres) => lat + metres / 111194.9;

test('the constants are the agreed ones and match the PHP side', () => {
    assert.equal(F.FOUND_M, 10);
    assert.equal(F.CLOSE_M, 50);
    assert.equal(F.ACCURACY_CREDIT_M, 15);
    const php = fs.readFileSync(path.join(__dirname, '../../includes/functions-training-case-runs.php'), 'utf8');
    assert.match(php, /const TRAINING_CASE_FOUND_M = 10;/);
    assert.match(php, /const TRAINING_CASE_CLOSE_M = 50;/);
    assert.match(php, /const TRAINING_CASE_ACCURACY_CREDIT_M = 15;/);
});

test('distanceM is metres on the sphere', () => {
    const d = F.distanceM({lat: 35.3, lng: 25.1}, {lat: north(35.3, 100), lng: 25.1});
    assert.ok(Math.abs(d - 100) < 0.01, String(d));
    assert.equal(F.distanceM({lat: 35.3, lng: 25.1}, {lat: 35.3, lng: 25.1}), 0);
});

test('classify at the boundaries, like trainingCaseProximity()', () => {
    assert.equal(F.classify(10, null), 'found');
    assert.equal(F.classify(10.1, null), 'close');
    assert.equal(F.classify(50, null), 'close');
    assert.equal(F.classify(50.1, null), 'far');
});

test('classify credits GPS error, capped, and ignores a negative or missing one', () => {
    assert.equal(F.classify(14, 10), 'found');
    assert.equal(F.classify(25, 15), 'found');
    assert.equal(F.classify(25.1, 100), 'close');
    assert.equal(F.classify(9, -5), 'found');
    assert.equal(F.classify(64.9, 15), 'close');
    assert.equal(F.classify(65.1, 15), 'far');
    assert.equal(F.classify(30, undefined), 'close');
});

test('watchList keeps only the cases worth watching', () => {
    const spot = {lat: 35.3, lng: 25.1};
    const list = F.watchList([
        {id: 1, case: {placement_id: 11, can_play: true, spot, found: false, state: 'new', briefing: 'a'}},
        {id: 2, case: {placement_id: 12, can_play: true, spot, found: true, state: 'new'}},
        {id: 3, case: {placement_id: 13, can_play: true, spot, found: false, state: 'done'}},
        {id: 4, case: {placement_id: 14, can_play: false, spot, found: false, state: 'new'}},
        {id: 5, case: {placement_id: 15, can_play: true, spot: null, found: false, state: 'new'}},
        {id: 6, case: null},
        {id: 7},
        null,
    ]);
    assert.deepEqual(list, [{dispatch_id: 1, placement_id: 11, spot, briefing: 'a'}]);
    assert.deepEqual(F.watchList(null), []);
});

test('the tracker says close once and found once, and found never reverts', () => {
    const calls = [];
    const tracker = F.createTracker({
        onClose: (card, d) => calls.push(['close', card.placement_id, Math.round(d)]),
        onFound: (card, fix, d) => calls.push(['found', card.placement_id, Math.round(d)]),
    });
    const card = {placement_id: 11, spot: {lat: 35.3, lng: 25.1}};
    const at = metres => ({lat: north(35.3, metres), lng: 25.1, acc: 5});
    tracker.check([card], at(200));
    assert.deepEqual(calls, []);
    tracker.check([card], at(45));
    tracker.check([card], at(40));
    assert.deepEqual(calls, [['close', 11, 45]]);
    tracker.check([card], at(8));
    tracker.check([card], at(30));
    tracker.check([card], at(6));
    assert.deepEqual(calls, [['close', 11, 45], ['found', 11, 8]]);
});

test('a find from afar goes straight to found without a separate close', () => {
    const calls = [];
    const tracker = F.createTracker({onClose: () => calls.push('close'), onFound: () => calls.push('found')});
    tracker.check([{placement_id: 1, spot: {lat: 35.3, lng: 25.1}}], {lat: 35.3, lng: 25.1, acc: 5});
    assert.deepEqual(calls, ['found']);
});

test('the tracker ignores malformed input and keeps each placement apart', () => {
    const calls = [];
    const tracker = F.createTracker({onClose: c => calls.push(['close', c.placement_id]), onFound: c => calls.push(['found', c.placement_id])});
    tracker.check(null, {lat: 1, lng: 1});
    tracker.check([{placement_id: 1}, null, {placement_id: 2, spot: {lat: 'x', lng: 1}}], {lat: 1, lng: 1});
    tracker.check([{placement_id: 3, spot: {lat: 35.3, lng: 25.1}}], {lat: NaN, lng: 25.1});
    assert.deepEqual(calls, []);
    tracker.check([{placement_id: 3, spot: {lat: 35.3, lng: 25.1}}, {placement_id: 4, spot: {lat: north(35.3, 1000), lng: 25.1}}], {lat: 35.3, lng: 25.1, acc: 5});
    assert.deepEqual(calls, [['found', 3]]);
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `node --test tests/js/training-case-find.test.js`
Expected: FAIL, cannot find module.

- [ ] **Step 3: Implement** — create `assets/js/training-case-find.js`:

```js
/**
 * Finding the victim (v3.506.0): the phone's side of «Είσαι κοντά» / «Βρέθηκε». Pure rules, exported for Node
 * (tests/js/training-case-find.test.js) and mirrored by includes/functions-training-case-runs.php, which re-checks every
 * report itself (trainingCaseProximity()): what this page decides is a courtesy, the server's answer is the record.
 *
 * Within CLOSE_M of the spot the phone says «κοντά» (never a distance or a direction); within FOUND_M it says «βρέθηκε».
 * A fix's reported accuracy is credited to the volunteer, capped at ACCURACY_CREDIT_M so one bad fix cannot find the
 * victim from afar. The spot of an area order is hidden: nothing here ever draws it.
 */
(function (root) {
    'use strict';

    const FOUND_M = 10;
    const CLOSE_M = 50;
    const ACCURACY_CREDIT_M = 15;

    function num(v) { return typeof v === 'number' && isFinite(v) ? v : (typeof v === 'string' && v.trim() !== '' && isFinite(Number(v)) ? Number(v) : null); }

    /** Great-circle distance in metres between two {lat, lng} points (the same sphere as gpsDistanceMeters() in PHP). */
    function distanceM(a, b) {
        const R = 6371000;
        const rad = Math.PI / 180;
        const dLat = (b.lat - a.lat) * rad;
        const dLng = (b.lng - a.lng) * rad;
        const h = Math.sin(dLat / 2) ** 2 + Math.cos(a.lat * rad) * Math.cos(b.lat * rad) * Math.sin(dLng / 2) ** 2;
        return R * 2 * Math.atan2(Math.sqrt(h), Math.sqrt(1 - h));
    }

    /** 'found', 'close' or 'far' for a distance in metres and the fix's accuracy in metres (missing or negative credits nothing). */
    function classify(distance, accuracy) {
        const acc = num(accuracy);
        const credit = Math.min(Math.max(acc === null ? 0 : acc, 0), ACCURACY_CREDIT_M);
        const effective = distance - credit;
        if (effective <= FOUND_M) return 'found';
        return effective <= CLOSE_M ? 'close' : 'far';
    }

    /** The cards worth watching in a poll's dispatch list: mine to play, with a spot, not found yet, not finished. */
    function watchList(dispatches) {
        const out = [];
        (Array.isArray(dispatches) ? dispatches : []).forEach(d => {
            const c = d && d.case;
            if (!c || !c.can_play || c.found || c.state === 'done' || !c.spot) return;
            if (num(c.spot.lat) === null || num(c.spot.lng) === null) return;
            out.push({dispatch_id: d.id, placement_id: c.placement_id, spot: c.spot, briefing: c.briefing || ''});
        });
        return out;
    }

    /**
     * Remembers what has been announced per placement, so a GPS that jitters around 50 m does not buzz again and again and a
     * later bad fix never un-finds. fix = {lat, lng, acc}.
     */
    function createTracker(handlers) {
        const closeSent = {};
        const foundSent = {};
        function check(cards, fix) {
            if (!Array.isArray(cards) || !fix) return;
            const lat = num(fix.lat);
            const lng = num(fix.lng);
            if (lat === null || lng === null) return;
            cards.forEach(card => {
                if (!card || !card.spot || foundSent[card.placement_id]) return;
                const sLat = num(card.spot.lat);
                const sLng = num(card.spot.lng);
                if (sLat === null || sLng === null) return;
                const d = distanceM({lat: lat, lng: lng}, {lat: sLat, lng: sLng});
                const where = classify(d, fix.acc);
                if (where === 'found') {
                    foundSent[card.placement_id] = true;
                    if (handlers && handlers.onFound) handlers.onFound(card, {lat: lat, lng: lng, acc: num(fix.acc)}, d);
                } else if (where === 'close' && !closeSent[card.placement_id]) {
                    closeSent[card.placement_id] = true;
                    if (handlers && handlers.onClose) handlers.onClose(card, d);
                }
            });
        }
        return {check: check};
    }

    const api = {FOUND_M, CLOSE_M, ACCURACY_CREDIT_M, distanceM, classify, watchList, createTracker};
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    root.VoCaseFind = api;
})(typeof window !== 'undefined' ? window : globalThis);
```

- [ ] **Step 4: Run to verify it passes**

Run: `node --test tests/js/training-case-find.test.js`
Expected: all PASS.

- [ ] **Step 5: Commit**

```bash
git add assets/js/training-case-find.js tests/js/training-case-find.test.js
git commit -m "Training cases: the phone's pure find module (distance, classify, tracker)"
```

---

### Task 9: Phone wiring (watcher, queue, prefetch, order row)

**Files:**
- Modify: `assets/js/media-queue.js` (`caseAnswerDeferral`)
- Modify: `assets/js/training-case.js` (add `prefetch`)
- Modify: `war-room.php` (script tag at ~6991; `renderDispatches` ~9606; click handler ~15596; order row ~15928; queue sender ~17899/17918)
- Modify: `includes/lang/war-room.php`
- Test: `tests/js/media-queue.test.js`, `tests/js/training-case.test.js`, `tests/TrainingCaseFindSourceTest.php`

**Interfaces:**
- Consumes: `VoCaseFind` (Task 8), `VoTrainingCase`, the page's `enqueueAction`, `flushQueue`, `loadQueue`, `postQueuedForm`, `deferCaseAnswers`, `dispatches`, `WR_MISSION_ID`, `WR_MY_USER_ID`, `t()`.
- Produces: `VoTrainingCase.prefetch({placementId, missionId, userId})` (resolves `true` when a valid document is kept on the phone); queue item `{kind: 'case_found', placementId, lat, lng, acc, at}`; page functions `openTrainingCase(placementId, briefing)`, `wrCaseFindSync(items)`; lang keys `case.close_toast`, `case.found_toast`, `case.opens_on_find`.

- [ ] **Step 1: Write the failing tests.**

`tests/js/media-queue.test.js` — find the existing `caseAnswerDeferral` tests and add:

```js
test('caseAnswerDeferral keeps a placement\'s found report ahead of its answers', () => {
    const found = {kind: 'case_found', placementId: 7};
    const a1 = {kind: 'case_answer', placementId: 7, stepId: 's1'};
    const sos = {kind: 'status', prId: 1, status: 'needs_help'};
    const reordered = MQ.caseAnswerDeferral([found, a1, sos], 7, 0, 100000);
    assert.deepEqual(reordered, [sos, found, a1], 'the SOS goes first; the found report stays before the answers');
    assert.equal(MQ.caseAnswerDeferral([found, a1], 7, 0, 100000), null, 'nothing else to let through');
});
```
(Use the same module alias the file already uses for `media-queue.js`; if it is not `MQ`, use that name.)

`tests/js/training-case.test.js` — append:

```js
test('prefetch keeps a valid document under the player\'s own key and refuses an invalid one', async () => {
    const store = {};
    global.localStorage = {getItem: k => (k in store ? store[k] : null), setItem: (k, v) => { store[k] = v; }};
    const urls = [];
    global.fetch = async url => { urls.push(url); return {json: async () => ({ok: true, document: doc})}; };
    assert.equal(await TC.prefetch({placementId: 5, missionId: 9, userId: 3}), true);
    assert.deepEqual(JSON.parse(store[TC.storageKeys(3, 5).doc]), doc);
    assert.match(urls[0], /action=load&mission_id=9&placement_id=5/);
    urls.length = 0;
    assert.equal(await TC.prefetch({placementId: 5, missionId: 9, userId: 3}), true);
    assert.equal(urls.length, 0, 'a document already kept is not fetched again');
    global.fetch = async () => ({json: async () => ({ok: true, document: {nope: 1}})});
    assert.equal(await TC.prefetch({placementId: 6, missionId: 9, userId: 3}), false);
    global.fetch = async () => { throw new Error('offline'); };
    assert.equal(await TC.prefetch({placementId: 7, missionId: 9, userId: 3}), false);
    delete global.fetch;
    delete global.localStorage;
});
```

`tests/TrainingCaseFindSourceTest.php` — add:

```php
    public function test_the_page_loads_the_module_and_wires_the_watcher_the_queue_and_the_button(): void
    {
        $page = str_replace("\r\n", "\n", (string) WarRoomSource::read('war-room.php'));
        $this->assertStringContainsString('assets/js/training-case-find.js', $page);
        $this->assertStringContainsString('wrCaseFindSync(items);', substr($page, (int) strpos($page, 'function renderDispatches(items)'), 400));
        $this->assertStringContainsString("if (item.kind === 'case_found') return sendQueuedCaseFound(item);", $page);
        $this->assertStringContainsString('enableHighAccuracy: true', substr($page, (int) strpos($page, 'function wrCaseFindStart'), 900));
        $this->assertStringContainsString('function openTrainingCase(', $page);
        $this->assertStringContainsString("kind: 'case_found'", $page);
        $this->assertMatchesRegularExpression('/cs\.available \|\| wrCaseFoundLocally\(cs\.placement_id\)/', $page);
        $this->assertStringNotContainsString("d.my_ack || cs.available", $page, 'arriving no longer opens a hidden case');
    }

    public function test_the_queue_keeps_a_found_report_until_the_server_refuses_it(): void
    {
        $page = str_replace("\r\n", "\n", (string) WarRoomSource::read('war-room.php'));
        $start = strpos($page, 'function sendQueuedCaseFound(');
        $this->assertNotFalse($start);
        $body = substr($page, $start, 1400);
        $this->assertStringContainsString("action: 'found'", $body);
        $this->assertStringContainsString('VoTrainingCase.queueOutcome(result)', $body);
        $this->assertStringContainsString('deferCaseAnswers(item)', $body);
    }

    public function test_every_new_text_exists_in_both_languages(): void
    {
        $lang = (string) file_get_contents(dirname(__DIR__) . '/includes/lang/war-room.php');
        foreach (['case.close_toast', 'case.found_toast', 'case.opens_on_find', 'case.found_title', 'case.found_message', 'case.err_too_far',
                  'case.err_not_found_yet', 'case.err_spot_needed', 'case.err_spot_outside', 'case.overview_found', 'case.spot_pin', 'case.spot_pin_help', 'case.spot_pinned'] as $key) {
            $this->assertSame(2, substr_count($lang, "'" . $key . "' =>"), $key . ' in el and en');
        }
    }
```

- [ ] **Step 2: Run to verify they fail**

Run: `node --test tests/js/media-queue.test.js tests/js/training-case.test.js` and `/c/xampp/php/php.exe vendor/phpunit/phpunit/phpunit tests/TrainingCaseFindSourceTest.php`
Expected: FAIL (`prefetch` is not a function; reordering missing; source assertions).

- [ ] **Step 3: Implement.**

`assets/js/media-queue.js` — in `caseAnswerDeferral`, change the two spots:

```js
        const isMine = q => (q.kind === 'case_answer' || q.kind === 'case_found') && q.placementId === placementId;
```
and the line `if (nextQueueIndex(others.filter(q => q.kind !== 'case_answer'), 'fast') === -1) return null;` becomes `if (nextQueueIndex(others.filter(q => q.kind !== 'case_answer' && q.kind !== 'case_found'), 'fast') === -1) return null;`. Update the comment above to say "this placement's found report and answers (in their own order...)".

`assets/js/training-case.js` — add before `const api = {` and add `prefetch` to `api`:

```js
    /**
     * Keeps a placement's document on the phone before the team has found the victim, so the case opens and plays with no
     * signal at the moment of the find (the load endpoint allows the audience before arrival). Resolves true when a valid
     * document is kept (already or just now), false on any failure: it is a courtesy, never an error. {placementId, missionId, userId}.
     */
    function prefetch(o) {
        const key = storageKeys(o.userId, o.placementId).doc;
        try {
            if (validDoc(JSON.parse(localStorage.getItem(key) || 'null'))) return Promise.resolve(true);
        } catch (e) { /* unreadable: fetch it again */ }
        const url = 'training-case.php?action=load&mission_id=' + encodeURIComponent(o.missionId) + '&placement_id=' + encodeURIComponent(o.placementId);
        return Promise.resolve().then(() => fetch(url)).then(r => r.json()).then(res => {
            if (!res || !res.ok || !validDoc(res.document)) return false;
            try { localStorage.setItem(key, JSON.stringify(res.document)); } catch (e) { return false; }
            return true;
        }).catch(() => false);
    }
```

`includes/lang/war-room.php` (el, then en):
```php
        'case.close_toast' => 'Είσαι κοντά - ψάξτε γύρω σας',
        'case.found_toast' => 'Βρέθηκε το θύμα!',
        'case.opens_on_find' => 'Το περιστατικό ανοίγει όταν βρείτε το θύμα',
```
```php
        'case.close_toast' => 'You are close - look around you',
        'case.found_toast' => 'Victim found!',
        'case.opens_on_find' => 'The case opens when you find the victim',
```
(`case.spot_pin`, `case.spot_pin_help`, `case.spot_pinned` are added in Task 10; the language test above passes only after Task 10, so run that test in Task 10 too.)

`war-room.php`:

1. After the `training-case.js` script tag (line ~6991) add the same style tag for `training-case-find.js`:
```php
<script src="<?= rtrim(BASE_URL, '/') ?>/assets/js/training-case-find.js?v=<?= APP_VERSION ?>.<?= (int) @filemtime(__DIR__ . '/assets/js/training-case-find.js') ?>"></script>
```
2. At the very top of `renderDispatches(items)`, as its first statement: `wrCaseFindSync(items);`
3. Replace the click handler (lines ~15594-15610) with a named function plus the handler:

```js
// The training case's player (v3.505.0, assets/js/training-case.js). Answers are queued like every other field action
// (sendQueuedItem() replays {kind:'case_answer'} to training-case.php), so the case plays with no signal. Opened by the
// button on an order's row and, since v3.506.0, by the phone itself the moment the team finds the victim.
function openTrainingCase(placementId, briefing) {
    if (!window.VoTrainingCase || document.getElementById('voCaseOverlay')) return;
    // The page's own SOS button (the volunteer's status row) sits under the overlay: the overlay's SOS forwards to it,
    // so the offline queue, the badge and the poll reconciliation stay the one code path. The button is looked up when
    // the SOS FIRES (the status row is redrawn by the poll, a node captured now may be gone by then); false = none.
    const findRealSos = () => document.querySelector('[onclick*="setFieldStatus"][onclick*="needs_help"]');
    window.VoTrainingCase.open({
        placementId: Number(placementId), missionId: WR_MISSION_ID, briefing: briefing || '',
        userId: WR_MY_USER_ID, t: t, enqueue: enqueueAction, flush: flushQueue,
        isQueued: (pid, stepId) => loadQueue().some(q => q.kind === 'case_answer' && q.placementId === pid && q.stepId === stepId),
        onClose: () => { renderQueueStatus(); flushQueue(); refreshMyOrdersCard(); },
        onSos: findRealSos() ? () => { const sos = findRealSos(); if (sos) sos.click(); return !!sos; } : null,
    });
}
document.addEventListener('click', e => {
    const caseBtn = e.target.closest('.vo-case-open-btn');
    if (caseBtn) openTrainingCase(caseBtn.dataset.placement, caseBtn.dataset.briefing);
});

// Finding the victim (v3.506.0, assets/js/training-case-find.js). The phone watches its OWN high-accuracy GPS, only while a
// case it may play has a spot its team has not found: the passive location capture can be switched off, is tied to a
// three-minute send cadence and may run at a battery-saving accuracy. «Κοντά» (50 m) is a toast and a buzz, never a number or a
// direction; «βρέθηκε» (10 m) queues the report, remembers it on this phone and opens the case.
let caseFindCards = [];
let caseFindWatchId = null;
const caseFindTracker = VoCaseFind.createTracker({
    onClose: () => { wrCaseFindToast(t('case.close_toast')); if (navigator.vibrate) navigator.vibrate(200); },
    onFound: (card, fix) => {
        if (navigator.vibrate) navigator.vibrate([200, 100, 200]);
        wrCaseFindToast(t('case.found_toast'));
        try { localStorage.setItem(wrCaseFoundKey(card.placement_id), '1'); } catch (e) {}
        if (!loadQueue().some(q => q.kind === 'case_found' && q.placementId === card.placement_id)) {
            enqueueAction({kind: 'case_found', placementId: card.placement_id, lat: fix.lat, lng: fix.lng, acc: fix.acc, at: Date.now()});
        }
        flushQueue();
        refreshMyOrdersCard();
        openTrainingCase(card.placement_id, card.briefing);
    },
});
function wrCaseFoundKey(placementId) { return 'wr_case_found_' + WR_MY_USER_ID + '_' + placementId; }
function wrCaseFoundLocally(placementId) {
    try { return localStorage.getItem(wrCaseFoundKey(placementId)) === '1'; } catch (e) { return false; }
}
function wrCaseFindToast(text) {
    const el = document.createElement('div');
    el.setAttribute('role', 'status');
    el.className = 'alert alert-success shadow position-fixed start-50 translate-middle-x fw-semibold py-2 px-3';
    el.style.cssText = 'top:calc(var(--app-clock-h, 0px) + 12px);z-index:100001;max-width:90vw;';
    el.textContent = text;
    document.body.appendChild(el);
    setTimeout(() => el.remove(), 4000);
}
function wrCaseFindStart() {
    if (caseFindWatchId !== null || !navigator.geolocation) return;
    caseFindWatchId = navigator.geolocation.watchPosition(
        pos => caseFindTracker.check(caseFindCards, {lat: pos.coords.latitude, lng: pos.coords.longitude, acc: pos.coords.accuracy}),
        () => {},
        {enableHighAccuracy: true, maximumAge: 2000, timeout: 20000}
    );
}
function wrCaseFindStop() {
    if (caseFindWatchId === null) return;
    navigator.geolocation.clearWatch(caseFindWatchId);
    caseFindWatchId = null;
}
// Called with every dispatch list the page renders (the poll). Starts or stops the watcher, and keeps the documents of the
// watched cases on the phone so a find with no signal still opens and plays them.
function wrCaseFindSync(items) {
    if (!window.VoCaseFind || !window.VoTrainingCase) return;
    caseFindCards = VoCaseFind.watchList(items).filter(c => !wrCaseFoundLocally(c.placement_id));
    caseFindCards.forEach(c => VoTrainingCase.prefetch({placementId: c.placement_id, missionId: WR_MISSION_ID, userId: WR_MY_USER_ID}));
    if (caseFindCards.length && !document.hidden) wrCaseFindStart(); else wrCaseFindStop();
}
document.addEventListener('visibilitychange', () => { if (typeof dispatches !== 'undefined') wrCaseFindSync(dispatches); });
```
(`caseFindTracker` is created at load; `VoCaseFind` is loaded by the script tag above, which precedes this block.)

4. In the order row (~15927-15936) replace the `caseHtml` computation with:

```js
            let caseHtml = '';
            if (d.case && d.case.can_play) {
                const cs = d.case;
                const caseLabel = cs.state === 'done'
                    ? t('case.btn_done', {percent: cs.percent})
                    : (cs.state === 'running' ? t('case.btn_resume') : t('case.btn_open'));
                // Open: my team found the victim (the server's word, or this phone's own until the queue has landed it), or - a point
                // order, whose victim is shown - my team has arrived.
                const open = cs.available || wrCaseFoundLocally(cs.placement_id) || (!cs.hidden && d.my_ack);
                caseHtml = open
                    ? `<button type="button" class="btn btn-sm btn-primary w-100 mt-1 vo-case-open-btn" data-placement="${Number(cs.placement_id)}" data-briefing="${escapeHtml(cs.briefing || '')}"><i class="bi bi-clipboard2-pulse me-1"></i>${escapeHtml(caseLabel)}</button>`
                    : `<div class="small text-muted mt-1"><i class="bi bi-clipboard2-pulse me-1"></i>${escapeHtml(t(cs.hidden ? 'case.opens_on_find' : 'case.opens_on_arrival'))}</div>`;
            }
```
The test above looks for the literal `cs.available || wrCaseFoundLocally(cs.placement_id)`: keep exactly that spelling.

5. In `sendQueuedItem()` add, beside the `case_answer` line: `if (item.kind === 'case_found') return sendQueuedCaseFound(item);` and add after `sendQueuedCaseAnswer()`:

```js
// The team found the victim (v3.506.0): the same outcome rules as an answer. The server re-checks the distance; a refusal
// (code 'refused': not the audience, too far, no spot) drops the report, everything else waits for the next tick.
function sendQueuedCaseFound(item) {
    if (!window.VoTrainingCase) return Promise.resolve({ok: false, networkError: true});
    return postQueuedForm('training-case.php', {
        mission_id: String(WR_MISSION_ID), action: 'found', placement_id: String(item.placementId),
        lat: String(item.lat), lng: String(item.lng), accuracy: String(Math.round(item.acc || 0)), queued: '1'
    }).then(result => {
        const verdict = VoTrainingCase.queueOutcome(result);
        if (verdict === 'retry') {
            if (result && result.networkError) return result;
            if (result && deferCaseAnswers(item)) setTimeout(flushQueue, 500);
            return null;
        }
        return result;
    });
}
```

- [ ] **Step 4: Run to verify they pass**

Run: `node --test tests/js/media-queue.test.js tests/js/training-case.test.js tests/js/training-case-find.test.js` and `/c/xampp/php/php.exe vendor/phpunit/phpunit/phpunit tests/TrainingCaseFindSourceTest.php tests/TrainingCaseWarRoomSourceTest.php`
Expected: PASS except the language-key test (`case.spot_pin*` arrives in Task 10). Update `TrainingCaseWarRoomSourceTest` where it pins `d.my_ack || cs.available` or the click handler's inline code (it asserts `VoTrainingCase.open(` is in the page: still true).

- [ ] **Step 5: Commit**

```bash
git add assets/js/media-queue.js assets/js/training-case.js war-room.php includes/lang/war-room.php tests/js/media-queue.test.js tests/js/training-case.test.js tests/TrainingCaseFindSourceTest.php tests/TrainingCaseWarRoomSourceTest.php
git commit -m "Training cases: the phone watches its GPS, says close, opens the case on a find and queues the report"
```

---

### Task 10: Commander pins the hidden spot when staging

**Files:**
- Modify: `war-room.php` (picker HTML ~6343; staging handler ~28970-28990; the case picker IIFE ~29047; `resetDrawing` ~28795)
- Modify: `assets/js/training-case.js` (`caseStageFields`)
- Modify: `includes/lang/war-room.php`
- Test: `tests/js/training-case.test.js`, `tests/TrainingCaseFindSourceTest.php`

**Interfaces:**
- Consumes: `pointInPolygon(lat, lng, geo)` from `assets/js/war-room-utils.js`; the dispatch modal's `isClosed`, `drawPoints`, `dispatchMap`, `resetDrawing()`.
- Produces: `caseStageFields(sel)` also returns `spot_lat`/`spot_lng` (strings) when `sel.spot` is `{lat, lng}`; lang keys `case.spot_pin`, `case.spot_pin_help`, `case.spot_pinned`; staging no longer refuses a case on a polygon.

- [ ] **Step 1: Write the failing tests.**

`tests/js/training-case.test.js`:

```js
test('caseStageFields carries the hidden spot of an area order', () => {
    const f = TC.caseStageFields({caseId: 4, mode: 'chosen', briefing: ' b ', spot: {lat: 35.405, lng: 25.205}});
    assert.equal(f.spot_lat, '35.405');
    assert.equal(f.spot_lng, '25.205');
    const plain = TC.caseStageFields({caseId: 4, mode: 'chosen', briefing: ''});
    assert.equal('spot_lat' in plain, false);
    assert.deepEqual(TC.caseStageFields({spot: {lat: 1, lng: 2}}), {});
});
```

`tests/TrainingCaseFindSourceTest.php`:

```php
    public function test_the_dispatch_window_stages_a_case_on_an_area_with_a_pinned_spot(): void
    {
        $page = str_replace("\r\n", "\n", (string) WarRoomSource::read('war-room.php'));
        $this->assertStringNotContainsString("if (wantsCase && isClosed) { alert(t('case.err_needs_point')); return; }", $page);
        $this->assertStringContainsString("t('case.err_spot_needed')", $page);
        $this->assertStringContainsString('pointInPolygon(', substr($page, (int) strpos($page, 'dispatchCaseSpotBtn')));
        $this->assertStringContainsString('id="dispatchCaseSpotBtn"', $page);
        $mission = str_replace("\r\n", "\n", (string) file_get_contents(dirname(__DIR__) . '/mission-scenario.php'));
        $this->assertStringContainsString("'spot_lat' => post('spot_lat')", $mission);
    }
```

- [ ] **Step 2: Run to verify they fail**

Run: `node --test tests/js/training-case.test.js` and `/c/xampp/php/php.exe vendor/phpunit/phpunit/phpunit tests/TrainingCaseFindSourceTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement.**

Lang (el / en):
```php
        'case.spot_pin' => 'Κρυφό σημείο θύματος',
        'case.spot_pin_help' => 'Πατήστε εδώ και μετά στο χάρτη, μέσα στην περιοχή. Οι διασώστες δεν θα το δουν.',
        'case.spot_pinned' => 'Κρυφό σημείο: ορίστηκε ✓',
```
```php
        'case.spot_pin' => 'Hidden victim spot',
        'case.spot_pin_help' => 'Press here, then tap the map inside the area. Rescuers will not see it.',
        'case.spot_pinned' => 'Hidden spot: set ✓',
```

`assets/js/training-case.js` `caseStageFields` — extend the returned object:

```js
        const fields = {
            case_id: String(sel.caseId), case_mode: mode,
            case_pool: JSON.stringify(mode === 'random' ? (sel.filter || {}) : {}),
            briefing: String(sel.briefing || '').trim(),
        };
        // An area order's hidden victim spot (v3.506.0); a point order needs none (the point is the spot).
        if (sel.spot && isFinite(Number(sel.spot.lat)) && isFinite(Number(sel.spot.lng))) {
            fields.spot_lat = String(sel.spot.lat);
            fields.spot_lng = String(sel.spot.lng);
        }
        return fields;
```
(replace the existing `return {...};`).

`war-room.php`:
- After the `dispatchCaseBriefing` input (line ~6343) add:
```php
                    <button type="button" class="btn btn-outline-danger btn-sm d-none" id="dispatchCaseSpotBtn" title="<?= t('case.spot_pin_help') ?>"><i class="bi bi-geo-alt-fill me-1"></i><span id="dispatchCaseSpotLabel"><?= t('case.spot_pin') ?></span></button>
```
- In the dispatch modal IIFE (the one with `isClosed`, `drawPoints`, `dispatchMap`), add module state and expose it, near `let lastAddressLabel = '';`:

```js
    // The hidden victim spot of a training case staged on an AREA (v3.506.0): pinned with one tap inside the polygon, seen only here.
    let casePinMode = false;
    let caseSpot = null;
    let caseSpotMarker = null;
    function clearCaseSpot() {
        casePinMode = false;
        caseSpot = null;
        if (caseSpotMarker) { dispatchMap.removeLayer(caseSpotMarker); caseSpotMarker = null; }
        if (window.wrDispatchCaseSpotChanged) window.wrDispatchCaseSpotChanged();
    }
    window.wrDispatchCaseSpot = () => caseSpot ? {lat: caseSpot.lat, lng: caseSpot.lng} : null;
    window.wrDispatchCaseStartPin = () => { casePinMode = true; };
    window.wrDispatchAreaClosed = () => isClosed;
```
  In `resetDrawing()` add `clearCaseSpot();` as its first statement (guard: `if (dispatchMap) clearCaseSpot();`).
  In `onMapClick(e)` at the very top add:

```js
        if (casePinMode) {
            casePinMode = false;
            if (!isClosed || !pointInPolygon(e.latlng.lat, e.latlng.lng, drawPoints)) { alert(t('case.err_spot_outside')); if (window.wrDispatchCaseSpotChanged) window.wrDispatchCaseSpotChanged(); return; }
            caseSpot = {lat: Number(e.latlng.lat.toFixed(6)), lng: Number(e.latlng.lng.toFixed(6))};
            if (caseSpotMarker) dispatchMap.removeLayer(caseSpotMarker);
            caseSpotMarker = L.circleMarker([caseSpot.lat, caseSpot.lng], {radius: 8, color: '#dc3545', fillColor: '#dc3545', fillOpacity: 0.9, weight: 2}).addTo(dispatchMap);
            if (window.wrDispatchCaseSpotChanged) window.wrDispatchCaseSpotChanged();
            return;
        }
```
- In the scenario staging handler replace the line `if (wantsCase && isClosed) { alert(t('case.err_needs_point')); return; }` with:

```js
            // An area takes a case too (v3.506.0), with the victim's hidden spot pinned inside it.
            if (wantsCase && isClosed && !caseSpot) { alert(t('case.err_spot_needed')); return; }
```
  and make `caseFields` include the spot: change the line computing `caseFields` so that after it the staging call merges the spot — the simplest is in the picker IIFE below (`wrDispatchCaseFields`) which reads `window.wrDispatchCaseSpot()` and `window.wrDispatchAreaClosed()`.
  Also add `clearCaseSpot();` in the success branch of staging (beside `resetDrawing();` — `resetDrawing()` already does it).
- In the case-picker IIFE (line ~29047): grab `const spotBtn = document.getElementById('dispatchCaseSpotBtn'); const spotLabel = document.getElementById('dispatchCaseSpotLabel');`; add

```js
    function syncSpotButton() {
        const need = state.mode !== null && !!(window.wrDispatchAreaClosed && window.wrDispatchAreaClosed());
        show(spotBtn, need);
        spotLabel.textContent = window.wrDispatchCaseSpot && window.wrDispatchCaseSpot() ? t('case.spot_pinned') : t('case.spot_pin');
    }
    spotBtn.addEventListener('click', () => { window.wrDispatchCaseStartPin(); alert(t('case.spot_pin_help')); });
    window.wrDispatchCaseSpotChanged = syncSpotButton;
```
  call `syncSpotButton()` at the end of `syncView()`, and also whenever the shape closes: in the modal IIFE, after `isClosed = true; updateShapePreview(); updateSendState();` inside `onMapClick`, add `if (window.wrDispatchCaseSpotChanged) window.wrDispatchCaseSpotChanged();`. Change `window.wrDispatchCaseFields` to:

```js
    window.wrDispatchCaseFields = () => TC.caseStageFields(state.mode ? {
        caseId: state.caseId, mode: state.mode, filter: state.filter, briefing: briefing.value,
        spot: window.wrDispatchAreaClosed && window.wrDispatchAreaClosed() ? window.wrDispatchCaseSpot() : null,
    } : null);
```
  (`window.wrDispatchCaseAfterStage` keeps working; the spot is cleared by `resetDrawing()`.)

- [ ] **Step 4: Run to verify they pass**

Run: `node --test tests/js/*.test.js` and `/c/xampp/php/php.exe vendor/phpunit/phpunit/phpunit tests/TrainingCaseFindSourceTest.php tests/TrainingCaseWarRoomSourceTest.php tests/ActionRoomFitsPhoneTest.php`
Expected: PASS (including the language-key test from Task 9).

- [ ] **Step 5: Commit**

```bash
git add war-room.php assets/js/training-case.js includes/lang/war-room.php tests/js/training-case.test.js tests/TrainingCaseFindSourceTest.php
git commit -m "Training cases: the commander pins the victim's hidden spot when staging an area"
```

---

### Task 11: Version, full run, browser check, docs

**Files:**
- Modify: `config.php` (`APP_VERSION` 3.505.1 to 3.506.0), `sw.js` (`CACHE_VERSION = 'vo-v3.506.0'`)

- [ ] **Step 1: Bump the version markers** — both files, the same value; CI fails if they differ.

- [ ] **Step 2: Run everything**

```
/c/xampp/php/php.exe tests/migrate-fixture.php
/c/xampp/php/php.exe vendor/phpunit/phpunit/phpunit
node --test tests/js/*.test.js
```
Expected: JS all PASS; PHPUnit shows only the 6 known baseline failures (TrainingCasePagesSourceTest 2, TrainingGatingTest 1, TrainingMissionSchemaTest 3). Compare against a clean `main` run if any other test fails.

- [ ] **Step 3: Browser check at 360 px.** Serve this checkout (the local Apache copy is stale): `/c/xampp/php/php.exe -S 127.0.0.1:8765 -t .`; log in with the local seeded admin (see `sql/schema.sql`; do not repeat the credentials in any message); open mission 4 as a training mission (set `missions.is_training = 1` for id 4 and restore it to 0 afterwards). Stage a polygon case with a hidden spot, send it, then in the browser console replace `navigator.geolocation.watchPosition` with a function that feeds `{coords: {latitude, longitude, accuracy: 5}}` at 200 m, 45 m and 8 m from the spot. Verify: nothing at 200 m; the toast «Είσαι κοντά - ψάξτε γύρω σας» once at 45 m with no number or arrow anywhere; at 8 m the player opens with the briefing and question; no element on any rescuer screen shows the spot; pressing «Έφτασα» on the area order does not open the case. Check a point order opens on arrival and on a find. Stop the server and restore the DB flag.

- [ ] **Step 4: Commit**

```bash
git add config.php sw.js
git commit -m "v3.506.0: Finding the victim - close at 50 m, found at 10 m, hidden spot in an area order"
```
Do not push, open a PR, tag or release; wait for the user.
