# Training Missions Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a mission be marked as a training exercise (`is_training`, locked at creation) that is fully isolated from real statistics, points, achievements and reports, while volunteers who attend get a separate training record and a participation certificate.

**Architecture:** `missions.is_training` is chosen once at creation. A `BEFORE INSERT` trigger copies it onto every `participation_requests` row, so operational queries filter on `pr.is_training = 0` with no extra JOIN and no insert site can forget it. A new `includes/functions-training-missions.php` holds the helpers; a guard test scans the source so a file that reads hours/attendance/points without filtering fails the build.

**Tech Stack:** PHP 8.2, MySQL (PDO), PHPUnit 11.5, plain PHP pages (no framework). Spec: `docs/superpowers/specs/2026-11-20-training-missions-design.md`.

## Global Constraints

- Flag is set on mission **creation only**; `mission-form.php`'s UPDATE statement must never write `is_training` (spec section 1).
- Operational numbers (hours, attendance, points, achievements, mission counts, reports, municipality reports) **exclude** `is_training = 1` (spec section 2).
- Training missions give **no points**: never touch `volunteer_points`, `users.total_points`, `participation_requests.points_awarded` for a training row (spec section 2).
- Notifications about a training mission are prefixed `[ΑΣΚΗΣΗ]` (spec section 2, amended below).
- Greek UI strings are written literally in the PHP pages as the surrounding pages do (only the Action Room uses `t()`).
- New migration is **version 224**; `DB_SCHEMA_VERSION` in `config.php` must be bumped to 224 in the same change (the migration runner and CI depend on it).
- Commit messages carry **no AI attribution lines** (owner's standing instruction). No `Co-Authored-By`, no "Generated with" trailer.
- PHP tests run with `C:\xampp\php\php.exe` against the XAMPP MySQL test database `volunteer_ops_test` (root, empty password).
- Line numbers below are from commit `83b5f98`. They drift as you edit; match on the quoted SQL/text, not the number.

## Deviations from the spec (found while planning; the spec is amended in Task 13)

1. **`new_mission` notification is not suppressed.** `sendMissionOpenedNotifications()` sends only to the audience the admin picks in the form (`getMissionNotifyRecipients($_POST)`), so for a drill it *is* the invitation. It stays, with the `[ΑΣΚΗΣΗ]` prefix. The spec's "org-wide broadcast suppressed" applies to nothing automatic here; newsletters and calendar feeds were checked and are unaffected (the ICS/JSON calendar feeds are login-gated and show the user's own shifts, so a drill shift correctly appears in the volunteer's own calendar).
2. **Action Room notifications are not prefixed in this sub-project.** `includes/functions-warroom.php` has 31 `sendNotification` call sites with no common choke point; the Action Room itself will carry the "ΑΣΚΗΣΗ" banner (Task 10). Prefixing those belongs with sub-projects 2/3 where the drill runs in the Action Room.
3. **`tests/fixtures/schema-structure.sql` is not regenerated.** It lags by design (see the docblock of `tests/migrate-fixture.php`); CI and local runs bring it forward by running migrations.
4. **Volunteer export:** only the isolation part is done (its participation count excludes training). Adding training columns to that export is left as a small follow-up.
5. **The "attended people pre-selected" certificate behaviour already exists** (`mission-certificates.php`, `has_checked_in`); Task 9 only adds wording and hours.

## File Structure

| File | Responsibility |
|---|---|
| `includes/migrations.php` (modify) | Migration 224: two columns, one index, one trigger |
| `config.php` (modify) | `DB_SCHEMA_VERSION` 224, version bump at the end |
| `sql/schema.sql` (modify) | Same columns/trigger for fresh installs |
| `includes/functions-training-missions.php` (create) | `isTrainingMission`, `trainingMissionFlagForSave`, `trainingTitlePrefix`, `trainingMissionBadgeHtml`, `getVolunteerTrainingRecord`, `getTrainingHoursForMission` |
| `includes/training-record-section.php` (create) | The "Εκπαιδευτικές ασκήσεις" HTML card, shared by profile and volunteer-view |
| `bootstrap.php`, `tests/bootstrap.php` (modify) | Load the helper file (and the test fixtures) |
| `mission-form.php`, `missions.php` (modify) | Checkbox on create, INSERT paths, duplicate copies the flag, list badge + filter |
| `includes/functions-core.php`, `leaderboard.php`, `volunteers.php`, `inactive-volunteers.php` (modify) | Participation-number isolation |
| `includes/achievements-functions.php`, `attendance.php`, `dashboard.php`, `shift-view.php` (modify) | No points/achievements from training |
| `dashboard.php`, `reports.php`, `report-municipality.php`, `includes/export-functions.php` (modify) | Operational aggregates exclude training |
| `profile.php`, `volunteer-view.php`, `volunteer-report.php` (modify) | Personal operational totals exclude training; training record shown separately |
| `mission-certificate-print.php`, `certificate-verify.php`, `mission-certificates.php` (modify) | Training certificate wording and hours |
| `mission-view.php`, `war-room.php` (modify) | Badge/banner |
| `includes/email.php`, `cron_shift_reminders.php`, `participation-action.php` (modify) | `[ΑΣΚΗΣΗ]` prefix |
| `tests/TrainingFixtures.php` (create) | Shared test builders |
| `tests/TrainingMissionSchemaTest.php`, `TrainingMissionHelpersTest.php`, `TrainingMissionSourceTest.php`, `TrainingIsolationTest.php`, `TrainingIsolationGuardTest.php` (create) | Tests |

---

### Task 0: Prepare the local test environment (one time)

**Files:** none changed (installs `vendor/`, which is not tracked).

- [ ] **Step 1: Confirm MySQL is up and the test database exists**

```bash
/c/xampp/mysql/bin/mysql.exe -u root -e "SHOW DATABASES LIKE 'volunteer_ops_test'"
```
Expected: one row `volunteer_ops_test`.

- [ ] **Step 2: Install PHPUnit via Composer**

```bash
cd /c/Users/user/Desktop/Projects/volunteer-ops
/c/xampp/php/php.exe /c/xampp/php/composer install --prefer-dist --no-progress
```
Expected: `vendor/bin/phpunit` exists afterwards. If `/c/xampp/php/composer` is not runnable, stop and ask the owner before downloading Composer's installer from getcomposer.org (it is a download).

- [ ] **Step 3: Bring the test database up to the current schema**

```bash
cd /c/Users/user/Desktop/Projects/volunteer-ops
/c/xampp/php/php.exe tests/migrate-fixture.php
```
Expected: `fixture schema: 190 -> 223 (target 223)` (190 is today's value in the local test DB). If it exits non-zero, print `SELECT setting_value FROM volunteer_ops_test.settings WHERE setting_key='migration_last_error'` and stop: the baseline is broken before our change.

- [ ] **Step 4: Baseline test run**

```bash
cd /c/Users/user/Desktop/Projects/volunteer-ops
/c/xampp/php/php.exe vendor/phpunit/phpunit/phpunit --no-progress 2>&1 | tail -8
```
Expected: note the totals (tests/failures). Any failures here are pre-existing; record them so later runs can be compared. No commit.

---

### Task 1: Schema - migration 224, trigger, schema.sql

**Files:**
- Modify: `includes/migrations.php` (append after the version 223 entry, before the closing `];`)
- Modify: `config.php:15`
- Modify: `sql/schema.sql` (missions table, participation_requests table, trigger)
- Create: `tests/TrainingFixtures.php`, `tests/TrainingMissionSchemaTest.php`
- Modify: `tests/bootstrap.php`

**Interfaces:**
- Produces: columns `missions.is_training`, `participation_requests.is_training`; trigger `trg_pr_set_training`; `TrainingFixtures::user()`, `::mission()`, `::participation()` used by every later test.

- [ ] **Step 1: Create the shared test fixtures**

Create `tests/TrainingFixtures.php`:

```php
<?php

declare(strict_types=1);

/**
 * Shared builders for the training-mission tests. Every test that uses them
 * runs inside a transaction that is rolled back, like ParCheckinTest.
 */
final class TrainingFixtures
{
    public static function user(string $name = 'Training Vol'): int
    {
        return (int) dbInsert(
            "INSERT INTO users (name, email, password) VALUES (?, ?, ?)",
            [$name, 'trn-' . uniqid('', true) . '@example.invalid', 'x']
        );
    }

    /** @return array{mission:int, shift:int} */
    public static function mission(bool $training, string $status = STATUS_OPEN): array
    {
        $typeId = (int) dbFetchValue("SELECT id FROM mission_types ORDER BY id LIMIT 1");
        $missionId = (int) dbInsert(
            "INSERT INTO missions (title, location, start_datetime, end_datetime, mission_type_id, status, is_training)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [
                ($training ? 'Drill ' : 'Real ') . uniqid(),
                'Ηράκλειο',
                date('Y-m-d H:i:s', time() - 7200),
                date('Y-m-d H:i:s', time() - 3600),
                $typeId,
                $status,
                $training ? 1 : 0,
            ]
        );
        $shiftId = (int) dbInsert(
            "INSERT INTO shifts (mission_id, start_time, end_time) VALUES (?, ?, ?)",
            [$missionId, date('Y-m-d H:i:s', time() - 7200), date('Y-m-d H:i:s', time() - 3600)]
        );
        return ['mission' => $missionId, 'shift' => $shiftId];
    }

    public static function participation(int $shiftId, int $userId, bool $attended = true, ?float $hours = 2.0): int
    {
        return (int) dbInsert(
            "INSERT INTO participation_requests (shift_id, volunteer_id, status, attended, actual_hours)
             VALUES (?, ?, ?, ?, ?)",
            [$shiftId, $userId, PARTICIPATION_APPROVED, $attended ? 1 : 0, $hours]
        );
    }
}
```

In `tests/bootstrap.php`, directly below the line `require_once __DIR__ . '/WarRoomSource.php';` add:

```php
require_once __DIR__ . '/TrainingFixtures.php';
```

- [ ] **Step 2: Write the failing schema test**

Create `tests/TrainingMissionSchemaTest.php`:

```php
<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Migration 224: missions.is_training, participation_requests.is_training and
 * the BEFORE INSERT trigger that copies the first onto the second.
 */
final class TrainingMissionSchemaTest extends TestCase
{
    protected function setUp(): void
    {
        db()->beginTransaction();
    }

    protected function tearDown(): void
    {
        db()->rollBack();
    }

    public function test_both_columns_exist_and_default_to_zero(): void
    {
        foreach (['missions', 'participation_requests'] as $table) {
            $col = dbFetchOne("SHOW COLUMNS FROM {$table} LIKE 'is_training'");
            $this->assertNotNull($col, "{$table}.is_training is missing");
            $this->assertSame('0', (string) $col['Default'], "{$table}.is_training must default to 0");
            $this->assertSame('NO', $col['Null']);
        }
    }

    public function test_trigger_copies_the_training_flag_onto_a_new_participation(): void
    {
        $user = TrainingFixtures::user();
        $drill = TrainingFixtures::mission(true);
        $real = TrainingFixtures::mission(false);

        $drillPr = TrainingFixtures::participation($drill['shift'], $user);
        $realPr = TrainingFixtures::participation($real['shift'], $user);

        $this->assertSame(1, (int) dbFetchValue("SELECT is_training FROM participation_requests WHERE id = ?", [$drillPr]));
        $this->assertSame(0, (int) dbFetchValue("SELECT is_training FROM participation_requests WHERE id = ?", [$realPr]));
    }

    public function test_trigger_ignores_a_value_supplied_on_insert(): void
    {
        $user = TrainingFixtures::user();
        $real = TrainingFixtures::mission(false);

        $id = (int) dbInsert(
            "INSERT INTO participation_requests (shift_id, volunteer_id, status, is_training) VALUES (?, ?, ?, 1)",
            [$real['shift'], $user, PARTICIPATION_APPROVED]
        );

        $this->assertSame(0, (int) dbFetchValue("SELECT is_training FROM participation_requests WHERE id = ?", [$id]));
    }
}
```

- [ ] **Step 3: Run it to verify it fails**

```bash
cd /c/Users/user/Desktop/Projects/volunteer-ops
/c/xampp/php/php.exe vendor/phpunit/phpunit/phpunit tests/TrainingMissionSchemaTest.php --no-progress
```
Expected: FAIL (`Unknown column 'is_training'` or `is_training is missing`).

- [ ] **Step 4: Add the migration**

In `includes/migrations.php`, immediately after the closing `],` of the `'version' => 223` entry (and before the blank line and `];` that ends the `$migrations` array), add:

```php
        [
            'version'     => 224,
            'description' => 'Training missions: missions.is_training (set at creation, locked) + participation_requests.is_training, ' .
                'copied from the mission by a BEFORE INSERT trigger so every insert path gets it. Operational stats, ' .
                'points and achievements filter on it; the volunteer\'s training record reads the same rows.',
            'up' => function () {
                $cols = dbFetchAll("SHOW COLUMNS FROM missions LIKE 'is_training'");
                if (empty($cols)) {
                    dbExecute("ALTER TABLE missions
                        ADD COLUMN is_training TINYINT(1) NOT NULL DEFAULT 0
                        COMMENT 'Training exercise: set at creation, never changed; isolated from real stats/points/reports'
                        AFTER is_locked");
                }
                $cols = dbFetchAll("SHOW COLUMNS FROM participation_requests LIKE 'is_training'");
                if (empty($cols)) {
                    dbExecute("ALTER TABLE participation_requests
                        ADD COLUMN is_training TINYINT(1) NOT NULL DEFAULT 0
                        COMMENT 'Copied from missions.is_training by trg_pr_set_training',
                        ADD INDEX idx_pr_training (volunteer_id, is_training, attended)");
                }

                // Single-statement trigger (no BEGIN/END), same style as trg_booking_insert.
                // If this CREATE fails the migration is NOT recorded as applied: falling back
                // silently would leave is_training at 0 and leak drills into real statistics.
                $pdo = db();
                $pdo->exec("DROP TRIGGER IF EXISTS `trg_pr_set_training`");
                $pdo->exec("
                    CREATE TRIGGER `trg_pr_set_training`
                    BEFORE INSERT ON `participation_requests`
                    FOR EACH ROW
                      SET NEW.is_training = COALESCE(
                          (SELECT m.is_training FROM shifts s JOIN missions m ON m.id = s.mission_id WHERE s.id = NEW.shift_id),
                          0)
                ");
            },
        ],
```

In `config.php` change line 15 to:

```php
define('DB_SCHEMA_VERSION', 224);
```

- [ ] **Step 5: Mirror the schema for fresh installs**

In `sql/schema.sql`:
1. In `CREATE TABLE IF NOT EXISTS \`missions\``, directly after the `is_locked` column line, add:
```sql
    `is_training` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Training exercise: set at creation, never changed; isolated from real stats/points/reports',
```
2. In `CREATE TABLE IF NOT EXISTS \`participation_requests\``, directly after the `attended` column line, add:
```sql
    `is_training` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Copied from missions.is_training by trg_pr_set_training',
```
and, next to the existing `INDEX \`idx_pr_vol_attended\` (\`volunteer_id\`, \`attended\`)` line, add `INDEX \`idx_pr_training\` (\`volunteer_id\`, \`is_training\`, \`attended\`),` (keep the comma placement valid for the last-index rule: put it before that line).
3. After the `participation_requests` table definition add:
```sql
DROP TRIGGER IF EXISTS `trg_pr_set_training`;
CREATE TRIGGER `trg_pr_set_training`
BEFORE INSERT ON `participation_requests`
FOR EACH ROW
  SET NEW.is_training = COALESCE(
      (SELECT m.is_training FROM shifts s JOIN missions m ON m.id = s.mission_id WHERE s.id = NEW.shift_id),
      0);
```
(`install.php` runs the file through its own statement splitter. Open `install.php` around the `schema.sql` loading code and confirm it handles a statement with no `DELIMITER`; the trigger is one statement, same as the existing booking triggers in migrations. If `install.php` cannot split it, leave the trigger out of `schema.sql` and rely on migration 224, which runs on every fresh install right after the schema; say so in the commit message.)

- [ ] **Step 6: Apply the migration to the test database and run the test**

```bash
cd /c/Users/user/Desktop/Projects/volunteer-ops
/c/xampp/php/php.exe tests/migrate-fixture.php
/c/xampp/php/php.exe vendor/phpunit/phpunit/phpunit tests/TrainingMissionSchemaTest.php --no-progress
```
Expected: `fixture schema: 223 -> 224 (target 224)` then `OK (3 tests, ...)`.

- [ ] **Step 7: Run the migrations runner test and the lint check**

```bash
cd /c/Users/user/Desktop/Projects/volunteer-ops
/c/xampp/php/php.exe vendor/phpunit/phpunit/phpunit tests/Migrations --no-progress
/c/xampp/php/php.exe -l includes/migrations.php && /c/xampp/php/php.exe -l config.php
```
Expected: OK / `No syntax errors detected`.

- [ ] **Step 8: Commit**

```bash
git add includes/migrations.php config.php sql/schema.sql tests/TrainingFixtures.php tests/TrainingMissionSchemaTest.php tests/bootstrap.php
git commit -m "Add training-mission flag columns and the participation trigger (migration 224)"
```

---

### Task 2: Helper functions

**Files:**
- Create: `includes/functions-training-missions.php`
- Modify: `bootstrap.php` (after line 27), `tests/bootstrap.php` (after the `functions-scenario.php` require)
- Test: `tests/TrainingMissionHelpersTest.php`

**Interfaces:**
- Produces (all in the global namespace):
  - `isTrainingMission(int|array $mission): bool` - accepts a missions row (uses its `is_training`, or its `id`) or an id.
  - `trainingMissionFlagForSave(?array $existingMission, array $post): int` - existing mission => its stored flag (locked); new mission => 1 only if `$post['is_training']` is truthy.
  - `trainingTitlePrefix(int|array $mission): string` - `'[ΑΣΚΗΣΗ] '` or `''`.
  - `trainingMissionBadgeHtml(array $mission): string` - a Bootstrap badge or `''`.
  - `getVolunteerTrainingRecord(int $userId): array{drills:int, hours:float, rows:list<array{mission_id:int,title:string,first_start:string,hours:float,certificate_id:?int}>}`
  - `getTrainingHoursForMission(int $missionId, int $userId): float`
  - constant `TRAINING_MISSION_PREFIX = '[ΑΣΚΗΣΗ]'`

- [ ] **Step 1: Write the failing tests**

Create `tests/TrainingMissionHelpersTest.php`:

```php
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
```

- [ ] **Step 2: Run it to verify it fails**

```bash
cd /c/Users/user/Desktop/Projects/volunteer-ops
/c/xampp/php/php.exe vendor/phpunit/phpunit/phpunit tests/TrainingMissionHelpersTest.php --no-progress
```
Expected: FAIL (`Call to undefined function isTrainingMission()`).

- [ ] **Step 3: Write the helper file**

Create `includes/functions-training-missions.php`:

```php
<?php
/**
 * VolunteerOps - Training missions (v3.504.0).
 *
 * A training mission is a drill: the flag `missions.is_training` is chosen when
 * the mission is created and never changes. Every participation row of such a
 * mission carries the same flag (trigger trg_pr_set_training, migration 224), so
 * operational statistics, points and achievements filter `pr.is_training = 0`
 * and the volunteer's training record reads `pr.is_training = 1` - the same rows,
 * one source of truth.
 *
 * Deliberately NOT named training-functions.php: that file is the education
 * module (courses, quizzes, exams).
 */

if (!defined('VOLUNTEEROPS')) {
    die('Direct access not permitted');
}

if (!defined('TRAINING_MISSION_PREFIX')) {
    define('TRAINING_MISSION_PREFIX', '[ΑΣΚΗΣΗ]');
}

if (!function_exists('isTrainingMission')) {

    /**
     * @param int|array $mission a missions row (its is_training, else its id) or an id
     */
    function isTrainingMission($mission): bool
    {
        if (is_array($mission)) {
            if (array_key_exists('is_training', $mission)) {
                return (int) $mission['is_training'] === 1;
            }
            $mission = (int) ($mission['id'] ?? 0);
        }
        $id = (int) $mission;
        if ($id <= 0) {
            return false;
        }
        return (int) dbFetchValue("SELECT is_training FROM missions WHERE id = ?", [$id]) === 1;
    }

    /**
     * The value to store for missions.is_training. An existing mission keeps what it
     * was created with (the flag is locked); only a new one reads the checkbox.
     */
    function trainingMissionFlagForSave(?array $existingMission, array $post): int
    {
        if ($existingMission !== null) {
            return (int) ($existingMission['is_training'] ?? 0) === 1 ? 1 : 0;
        }
        return !empty($post['is_training']) ? 1 : 0;
    }

    /** '[ΑΣΚΗΣΗ] ' for a training mission, '' otherwise. */
    function trainingTitlePrefix($mission): string
    {
        return isTrainingMission($mission) ? TRAINING_MISSION_PREFIX . ' ' : '';
    }

    /** A small badge for lists and headers, '' for a real mission. */
    function trainingMissionBadgeHtml(array $mission): string
    {
        if (!isTrainingMission($mission)) {
            return '';
        }
        return '<span class="badge bg-warning text-dark ms-1" title="Εκπαιδευτική άσκηση - δεν επηρεάζει τα στατιστικά">'
             . '<i class="bi bi-mortarboard-fill me-1"></i>ΑΣΚΗΣΗ</span>';
    }

    /**
     * A volunteer's training record: attended participations of training missions.
     * Hours use the same fallback profile.php uses (recorded hours, else the shift's length).
     *
     * @return array{drills:int, hours:float, rows:array<int,array{mission_id:int,title:string,first_start:string,hours:float,certificate_id:?int}>}
     */
    function getVolunteerTrainingRecord(int $userId): array
    {
        $rows = dbFetchAll(
            "SELECT m.id AS mission_id, m.title, MIN(s.start_time) AS first_start,
                    COALESCE(SUM(CASE WHEN pr.actual_hours IS NOT NULL THEN pr.actual_hours
                                      ELSE TIMESTAMPDIFF(HOUR, s.start_time, s.end_time) END), 0) AS hours,
                    (SELECT mc.id FROM mission_certificates mc
                      WHERE mc.mission_id = m.id AND mc.recipient_user_id = pr.volunteer_id
                      ORDER BY mc.id DESC LIMIT 1) AS certificate_id
             FROM participation_requests pr
             JOIN shifts s ON s.id = pr.shift_id
             JOIN missions m ON m.id = s.mission_id AND m.deleted_at IS NULL
             WHERE pr.volunteer_id = ? AND pr.is_training = 1 AND pr.attended = 1 AND pr.status = ?
             GROUP BY m.id, m.title, pr.volunteer_id
             ORDER BY first_start DESC",
            [$userId, PARTICIPATION_APPROVED]
        );

        $hours = 0.0;
        $out = [];
        foreach ($rows as $r) {
            $h = (float) $r['hours'];
            $hours += $h;
            $out[] = [
                'mission_id'     => (int) $r['mission_id'],
                'title'          => (string) $r['title'],
                'first_start'    => (string) $r['first_start'],
                'hours'          => $h,
                'certificate_id' => $r['certificate_id'] !== null ? (int) $r['certificate_id'] : null,
            ];
        }
        return ['drills' => count($out), 'hours' => $hours, 'rows' => $out];
    }

    /** One volunteer's recorded hours in one training mission (for the certificate). */
    function getTrainingHoursForMission(int $missionId, int $userId): float
    {
        return (float) dbFetchValue(
            "SELECT COALESCE(SUM(CASE WHEN pr.actual_hours IS NOT NULL THEN pr.actual_hours
                                      ELSE TIMESTAMPDIFF(HOUR, s.start_time, s.end_time) END), 0)
             FROM participation_requests pr
             JOIN shifts s ON s.id = pr.shift_id
             WHERE s.mission_id = ? AND pr.volunteer_id = ? AND pr.attended = 1 AND pr.status = ?",
            [$missionId, $userId, PARTICIPATION_APPROVED]
        );
    }
}
```

- [ ] **Step 4: Load it**

In `bootstrap.php`, below `require_once __DIR__ . '/includes/functions-scenario.php';` add:
```php
require_once __DIR__ . '/includes/functions-training-missions.php';
```
In `tests/bootstrap.php`, below `require_once __DIR__ . '/../includes/functions-scenario.php';` add:
```php
require_once __DIR__ . '/../includes/functions-training-missions.php';
```

- [ ] **Step 5: Run the tests to verify they pass**

```bash
cd /c/Users/user/Desktop/Projects/volunteer-ops
/c/xampp/php/php.exe vendor/phpunit/phpunit/phpunit tests/TrainingMissionHelpersTest.php --no-progress
```
Expected: `OK (6 tests, ...)`.

- [ ] **Step 6: Commit**

```bash
git add includes/functions-training-missions.php bootstrap.php tests/bootstrap.php tests/TrainingMissionHelpersTest.php
git commit -m "Add training-mission helper functions"
```

---

### Task 3: Mission form - checkbox on create, locked afterwards; duplicate keeps the flag

**Files:**
- Modify: `mission-form.php` (data array ~line 78, INSERT at ~286 and ~318, checkbox ~line 648), `missions.php:21-42`
- Test: `tests/TrainingMissionSourceTest.php` (create; later tasks append to it)

- [ ] **Step 1: Write the failing source test**

Create `tests/TrainingMissionSourceTest.php`:

```php
<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Source-level checks for page scripts whose logic is inline (not callable from a
 * test). Each assertion names the behaviour it protects.
 */
final class TrainingMissionSourceTest extends TestCase
{
    private function src(string $file): string
    {
        $s = file_get_contents(__DIR__ . '/../' . $file);
        $this->assertNotFalse($s, "cannot read {$file}");
        return $s;
    }

    public function test_mission_form_writes_the_flag_on_both_inserts_but_never_on_update(): void
    {
        $s = $this->src('mission-form.php');

        // INSERT statements (recurring + single) include the column.
        $this->assertSame(2, preg_match_all('/INSERT INTO missions[^"]*is_training/s', $s));

        // The UPDATE statement must not touch it: the flag is locked after creation.
        $this->assertSame(1, preg_match('/UPDATE missions SET(.*?)WHERE id = \?/s', $s, $m));
        $this->assertStringNotContainsString('is_training', $m[1]);

        // The value comes from the helper, not straight from $_POST.
        $this->assertStringContainsString("'is_training' => trainingMissionFlagForSave(", $s);
        // The checkbox is only offered when creating.
        $this->assertMatchesRegularExpression('/name="is_training"/', $s);
    }

    public function test_duplicating_a_mission_copies_its_training_flag(): void
    {
        $s = $this->src('missions.php');
        $this->assertMatchesRegularExpression('/INSERT INTO missions[^"]*is_training/s', $s);
        $this->assertStringContainsString("\$src['is_training']", $s);
    }
}
```

- [ ] **Step 2: Run it to verify it fails**

```bash
cd /c/Users/user/Desktop/Projects/volunteer-ops
/c/xampp/php/php.exe vendor/phpunit/phpunit/phpunit tests/TrainingMissionSourceTest.php --no-progress
```
Expected: 2 FAIL.

- [ ] **Step 3: Edit `mission-form.php`**

(a) In the `$data = [ ... ]` array, directly after the line `'is_locked' => isset($_POST['is_locked']) ? 1 : 0,` add:
```php
        'is_training' => trainingMissionFlagForSave($mission, $_POST),
```
(`$mission` is `null` when creating and the loaded row when editing - see the top of the file.)

(b) Recurring INSERT (the one with `recurrence_id`): change
`is_urgent, is_special_mission, is_locked, show_in_ops, status, responsible_user_id, created_by, recurrence_id, recurrence_instance_date, created_at, updated_at)`
to
`is_urgent, is_special_mission, is_locked, is_training, show_in_ops, status, responsible_user_id, created_by, recurrence_id, recurrence_instance_date, created_at, updated_at)`
and the placeholders `VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())` to 23 placeholders (add one `?` before `NOW(), NOW()`); in its params array change
`$data['is_urgent'], $data['is_special_mission'], $data['is_locked'], $data['show_in_ops'], STATUS_OPEN, $data['responsible_user_id'], $user['id'],`
to
`$data['is_urgent'], $data['is_special_mission'], $data['is_locked'], $data['is_training'], $data['show_in_ops'], STATUS_OPEN, $data['responsible_user_id'], $user['id'],`.

(c) Single INSERT: change
`is_urgent, is_special_mission, is_locked, show_in_ops, status, responsible_user_id, created_by, created_at, updated_at)`
to
`is_urgent, is_special_mission, is_locked, is_training, show_in_ops, status, responsible_user_id, created_by, created_at, updated_at)`,
its `VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())` to 21 placeholders, and its params
`$data['is_urgent'], $data['is_special_mission'], $data['is_locked'], $data['show_in_ops'], $data['status'], $data['responsible_user_id'], $user['id']`
to
`$data['is_urgent'], $data['is_special_mission'], $data['is_locked'], $data['is_training'], $data['show_in_ops'], $data['status'], $data['responsible_user_id'], $user['id']`.

Count the columns against the `?` list after editing: each INSERT must have exactly as many `?` as bound values (a mismatch is a PDO error).

(d) Leave the `UPDATE missions SET ...` statement exactly as it is.

(e) Checkbox: directly after the `is_locked` block (the `<div class="form-check mb-3 border rounded p-3 bg-light">` that contains `id="is_locked"`, ending with its `</div>`), add:
```php
                    <?php if (!$isEdit): ?>
                    <div class="form-check mb-3 border rounded p-3 bg-light">
                        <input class="form-check-input" type="checkbox" id="is_training" name="is_training" value="1"
                               <?= post('is_training') ? 'checked' : '' ?>>
                        <label class="form-check-label fw-semibold" for="is_training">
                            <i class="bi bi-mortarboard-fill text-warning me-1"></i>Εκπαιδευτική Άσκηση (δοκιμαστική αποστολή)
                        </label>
                        <div class="form-text">Δεν επηρεάζει στατιστικά, πόντους, επιτεύγματα ή αναφορές. Οι εθελοντές που συμμετέχουν παίρνουν εκπαιδευτικές ώρες και βεβαίωση. <strong>Δεν αλλάζει μετά τη δημιουργία.</strong></div>
                    </div>
                    <?php else: ?>
                    <div class="mb-3 border rounded p-3 bg-light small">
                        <i class="bi bi-mortarboard-fill text-warning me-1"></i>
                        <?= !empty($mission['is_training']) ? 'Αυτή είναι εκπαιδευτική άσκηση.' : 'Αυτή είναι πραγματική αποστολή.' ?>
                        Η επιλογή αυτή ορίζεται κατά τη δημιουργία και δεν αλλάζει.
                    </div>
                    <?php endif; ?>
```

- [ ] **Step 4: Edit `missions.php` (duplicate)**

Change `is_urgent, status, responsible_user_id, created_by, created_at, updated_at)` to `is_urgent, is_training, status, responsible_user_id, created_by, created_at, updated_at)`, the `VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())` to 18 placeholders, and in the params array replace
```php
                $src['is_urgent'],
                STATUS_DRAFT,
```
with
```php
                $src['is_urgent'],
                $src['is_training'],
                STATUS_DRAFT,
```

- [ ] **Step 5: Run tests and lint**

```bash
cd /c/Users/user/Desktop/Projects/volunteer-ops
/c/xampp/php/php.exe vendor/phpunit/phpunit/phpunit tests/TrainingMissionSourceTest.php --no-progress
/c/xampp/php/php.exe -l mission-form.php && /c/xampp/php/php.exe -l missions.php
```
Expected: `OK (2 tests, ...)` and no syntax errors.

- [ ] **Step 6: Commit**

```bash
git add mission-form.php missions.php tests/TrainingMissionSourceTest.php
git commit -m "Mission form: training checkbox on create (locked after), duplicate keeps the flag"
```

---

### Task 4: Isolate participation numbers (core functions, leaderboard, volunteer lists, achievements)

**Files:**
- Modify: `includes/functions-core.php`, `leaderboard.php`, `volunteers.php`, `inactive-volunteers.php`, `includes/achievements-functions.php`
- Test: `tests/TrainingIsolationTest.php` (create)

- [ ] **Step 1: Write the failing functional tests**

Create `tests/TrainingIsolationTest.php`:

```php
<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * A drill's attendance must not show up in any real number. Every test seeds one
 * real and one training mission for the same volunteer and asserts only the real
 * one is counted.
 */
final class TrainingIsolationTest extends TestCase
{
    private int $user;
    private array $real;
    private array $drill;

    protected function setUp(): void
    {
        db()->beginTransaction();
        $this->user = TrainingFixtures::user('Isolation Vol');
        $this->real = TrainingFixtures::mission(false);
        $this->drill = TrainingFixtures::mission(true);
    }

    protected function tearDown(): void
    {
        db()->rollBack();
    }

    public function test_hours_rank_ignores_training_hours(): void
    {
        TrainingFixtures::participation($this->real['shift'], $this->user, true, 2.0);
        TrainingFixtures::participation($this->drill['shift'], $this->user, true, 10.0);

        $pos = hoursRankPosition($this->user);

        $this->assertNotNull($pos);
        $this->assertEqualsWithDelta(2.0, $pos['hours'], 0.001);
    }

    public function test_a_volunteer_with_only_drills_is_not_on_the_hours_rank(): void
    {
        TrainingFixtures::participation($this->drill['shift'], $this->user, true, 10.0);

        $this->assertNull(hoursRankPosition($this->user));
    }

    public function test_leaderboard_top_counts_only_real_shifts_and_hours(): void
    {
        dbExecute("UPDATE users SET total_points = 99999999 WHERE id = ?", [$this->user]);
        TrainingFixtures::participation($this->real['shift'], $this->user, true, 2.0);
        TrainingFixtures::participation($this->drill['shift'], $this->user, true, 10.0);

        $row = null;
        foreach (leaderboardTop(100) as $r) {
            if ((int) $r['id'] === $this->user) {
                $row = $r;
            }
        }

        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row['shifts_count']);
        $this->assertEqualsWithDelta(2.0, (float) $row['total_hours'], 0.001);
    }

    public function test_training_attendance_earns_no_achievement(): void
    {
        TrainingFixtures::participation($this->drill['shift'], $this->user, true, 2.0);

        $earned = array_column(checkAndAwardAchievements($this->user), 'code');

        $this->assertNotContains('first_shift', $earned);
        $this->assertNotContains('first_mission', $earned);
    }

    public function test_real_attendance_still_earns_the_first_shift_achievement(): void
    {
        // Control: proves the previous test is not passing only because achievements are off.
        TrainingFixtures::participation($this->real['shift'], $this->user, true, 2.0);

        $earned = array_column(checkAndAwardAchievements($this->user), 'code');

        $this->assertContains('first_shift', $earned);
    }
}
```

- [ ] **Step 2: Run it to verify the failures**

```bash
cd /c/Users/user/Desktop/Projects/volunteer-ops
/c/xampp/php/php.exe vendor/phpunit/phpunit/phpunit tests/TrainingIsolationTest.php --no-progress
```
Expected: the three isolation tests and the achievement test FAIL; the control test passes. (If the control also fails, `achievements_enabled` is off or `first_shift` is unseeded in the test DB: run `SELECT code FROM volunteer_ops_test.achievements LIMIT 3` and `SELECT setting_value FROM volunteer_ops_test.settings WHERE setting_key='achievements_enabled'`; fix the fixture data, not the code.)

- [ ] **Step 3: Edit `includes/functions-core.php`**

Replace
`LEFT JOIN participation_requests pr ON pr.volunteer_id = t.id AND pr.attended = 1`
with
`LEFT JOIN participation_requests pr ON pr.volunteer_id = t.id AND pr.attended = 1 AND pr.is_training = 0`

Replace
`JOIN participation_requests pr ON pr.volunteer_id = u.id AND pr.attended = 1`
with
`JOIN participation_requests pr ON pr.volunteer_id = u.id AND pr.attended = 1 AND pr.is_training = 0`

- [ ] **Step 4: Edit `leaderboard.php`**

Replace
`LEFT JOIN participation_requests pr ON pr.volunteer_id = u.id AND pr.attended = 1`
with
`LEFT JOIN participation_requests pr ON pr.volunteer_id = u.id AND pr.attended = 1 AND pr.is_training = 0`

- [ ] **Step 5: Edit `volunteers.php` and `inactive-volunteers.php`**

`volunteers.php`: replace `WHERE status = ? OR attended = 1` with `WHERE (status = ? OR attended = 1) AND is_training = 0`.

`inactive-volunteers.php`: replace
`WHERE status = '" . PARTICIPATION_APPROVED . "' OR attended = 1`
with
`WHERE (status = '" . PARTICIPATION_APPROVED . "' OR attended = 1) AND is_training = 0`

- [ ] **Step 6: Edit `includes/achievements-functions.php`**

There are two identical lines `WHERE pr.volunteer_id = ? AND pr.status = 'APPROVED' AND pr.attended = 1` (the combined stats query and the medical-missions query). Replace **both** (replace all) with
`WHERE pr.volunteer_id = ? AND pr.status = 'APPROVED' AND pr.attended = 1 AND pr.is_training = 0`.

- [ ] **Step 7: Run the tests and lint**

```bash
cd /c/Users/user/Desktop/Projects/volunteer-ops
/c/xampp/php/php.exe vendor/phpunit/phpunit/phpunit tests/TrainingIsolationTest.php --no-progress
for f in includes/functions-core.php leaderboard.php volunteers.php inactive-volunteers.php includes/achievements-functions.php; do /c/xampp/php/php.exe -l $f; done
```
Expected: `OK (5 tests, ...)`; no syntax errors.

- [ ] **Step 8: Commit**

```bash
git add includes/functions-core.php leaderboard.php volunteers.php inactive-volunteers.php includes/achievements-functions.php tests/TrainingIsolationTest.php
git commit -m "Exclude training attendance from hours rank, leaderboard, volunteer lists and achievements"
```

---

### Task 5: No points for training attendance

**Files:**
- Modify: `attendance.php` (~line 233), `dashboard.php` (~line 147), `shift-view.php` (~line 284)
- Test: append to `tests/TrainingMissionSourceTest.php`

- [ ] **Step 1: Add the failing source tests**

Append inside the class `TrainingMissionSourceTest`:

```php
    public function test_attendance_page_never_selects_training_rows_for_points(): void
    {
        $s = $this->src('attendance.php');
        $this->assertStringContainsString('AND pr.attended = 1 AND pr.points_awarded = 0 AND pr.is_training = 0', $s);
    }

    public function test_dashboard_auto_award_skips_training_rows(): void
    {
        $s = $this->src('dashboard.php');
        $this->assertMatchesRegularExpression('/AND pr\.points_awarded = 0\s+AND pr\.is_training = 0/', $s);
    }

    public function test_manual_attendance_in_shift_view_awards_no_points_for_training(): void
    {
        $s = $this->src('shift-view.php');
        $this->assertStringContainsString("if (getSetting('points_enabled', '1') === '1' && empty(\$pr['is_training'])) {", $s);
    }
```

- [ ] **Step 2: Run to verify they fail**

```bash
cd /c/Users/user/Desktop/Projects/volunteer-ops
/c/xampp/php/php.exe vendor/phpunit/phpunit/phpunit tests/TrainingMissionSourceTest.php --no-progress
```
Expected: 3 new FAIL.

- [ ] **Step 3: Edit `attendance.php`**

Replace `AND pr.attended = 1 AND pr.points_awarded = 0",` with `AND pr.attended = 1 AND pr.points_awarded = 0 AND pr.is_training = 0",`.

- [ ] **Step 4: Edit `dashboard.php`**

In the auto-award query replace
```
                       AND pr.points_awarded = 0",
```
with
```
                       AND pr.points_awarded = 0
                       AND pr.is_training = 0",
```

- [ ] **Step 5: Edit `shift-view.php`**

`$pr` there is `SELECT * FROM participation_requests`, so it already carries `is_training`. Replace the single line
`if (getSetting('points_enabled', '1') === '1') {`
with
`if (getSetting('points_enabled', '1') === '1' && empty($pr['is_training'])) {`

(Attendance and hours are still recorded by `recordManualAttendance()`; only the points block is skipped.)

- [ ] **Step 6: Run tests and lint**

```bash
cd /c/Users/user/Desktop/Projects/volunteer-ops
/c/xampp/php/php.exe vendor/phpunit/phpunit/phpunit tests/TrainingMissionSourceTest.php --no-progress
for f in attendance.php dashboard.php shift-view.php; do /c/xampp/php/php.exe -l $f; done
```
Expected: `OK (5 tests, ...)`.

- [ ] **Step 7: Commit**

```bash
git add attendance.php dashboard.php shift-view.php tests/TrainingMissionSourceTest.php
git commit -m "Award no points for training attendance"
```

---

### Task 6: dashboard.php - admin aggregates and personal totals

**Files:**
- Modify: `dashboard.php`
- Test: append to `tests/TrainingMissionSourceTest.php`

- [ ] **Step 1: Add the failing source test**

Append inside the class:

```php
    public function test_dashboard_admin_filter_and_personal_stats_exclude_training(): void
    {
        $s = $this->src('dashboard.php');

        // One shared filter feeds every admin query (missions_total, hours, volunteers, monthly chart...).
        $this->assertStringContainsString("\$departmentFilter = 'AND m.is_training = 0';", $s);
        $this->assertStringContainsString("\$departmentFilter = 'AND m.department_id = ? AND m.is_training = 0';", $s);
        $this->assertStringContainsString("\$departmentFilter = 'AND m.branch_id = ? AND m.is_training = 0';", $s);

        // Personal totals: hours, yearly attendance, TEP hours, shift count.
        $this->assertGreaterThanOrEqual(3, substr_count($s, 'pr.attended = 1 AND pr.is_training = 0'));
    }
```

- [ ] **Step 2: Run to verify it fails**

```bash
cd /c/Users/user/Desktop/Projects/volunteer-ops
/c/xampp/php/php.exe vendor/phpunit/phpunit/phpunit tests/TrainingMissionSourceTest.php --no-progress
```
Expected: the new test FAILS.

- [ ] **Step 3: Edit the admin filter**

Three single-line replacements (each string is unique in the file):
- `$departmentFilter = '';` -> `$departmentFilter = 'AND m.is_training = 0';`
- `$departmentFilter = 'AND m.department_id = ?';` -> `$departmentFilter = 'AND m.department_id = ? AND m.is_training = 0';`
- `$departmentFilter = 'AND m.branch_id = ?';` -> `$departmentFilter = 'AND m.branch_id = ? AND m.is_training = 0';`

This works because every admin query appends `$departmentFilter` after a `WHERE ... m.deleted_at IS NULL` and the string has no placeholder (so `$params` is unchanged).

- [ ] **Step 4: Edit the volunteer's personal totals**

(a) `total_hours`: replace the line (exactly one occurrence ends with `",`)
`            WHERE pr.volunteer_id = ? AND pr.status = ? AND pr.attended = 1",`
with
`            WHERE pr.volunteer_id = ? AND pr.status = ? AND pr.attended = 1 AND pr.is_training = 0",`

(b) yearly mission attendance: replace
`         WHERE pr.volunteer_id = ? AND pr.attended = 1`
(the one followed by `AND YEAR(m.start_datetime) = ?`) with
`         WHERE pr.volunteer_id = ? AND pr.attended = 1 AND pr.is_training = 0`

(c) TEP hours: replace
```
            WHERE pr.volunteer_id = ? AND pr.status = ? AND pr.attended = 1
              AND m.mission_type_id = ?",
```
with
```
            WHERE pr.volunteer_id = ? AND pr.status = ? AND pr.attended = 1 AND pr.is_training = 0
              AND m.mission_type_id = ?",
```

(d) total shifts: the first of the two `SELECT COUNT(*) FROM participation_requests WHERE volunteer_id = ? AND status = ?` (~line 402, the one for approved shifts, not the `pending_requests` one) becomes
`SELECT COUNT(*) FROM participation_requests WHERE volunteer_id = ? AND status = ? AND is_training = 0`.
Open the file at that spot and confirm which of the two is `total_shifts` before editing.

- [ ] **Step 5: Verify no admin query was missed**

```bash
cd /c/Users/user/Desktop/Projects/volunteer-ops
grep -n "participation_requests pr" dashboard.php | wc -l
grep -n "departmentFilter" dashboard.php | wc -l
```
Read every `participation_requests` query in `dashboard.php` once more: each is either (a) admin and uses `$departmentFilter`, (b) the volunteer's personal totals just edited, (c) the per-mission `attended_count` subqueries (`s.mission_id = m.id`), which are a per-mission view and stay as they are, or (d) the attendance auto-mark / points block. Anything else gets the same filter.

- [ ] **Step 6: Run the test and lint**

```bash
cd /c/Users/user/Desktop/Projects/volunteer-ops
/c/xampp/php/php.exe vendor/phpunit/phpunit/phpunit tests/TrainingMissionSourceTest.php --no-progress
/c/xampp/php/php.exe -l dashboard.php
```
Expected: `OK`, no syntax errors.

- [ ] **Step 7: Commit**

```bash
git add dashboard.php tests/TrainingMissionSourceTest.php
git commit -m "Dashboard: exclude training missions from admin aggregates and personal totals"
```

---

### Task 7: reports.php, report-municipality.php, export-functions.php

**Files:**
- Modify: `reports.php`, `report-municipality.php`, `includes/export-functions.php`
- Test: append to `tests/TrainingMissionSourceTest.php`

- [ ] **Step 1: Add the failing source test**

Append inside the class:

```php
    public function test_reports_and_exports_exclude_training(): void
    {
        $r = $this->src('reports.php');
        $this->assertStringContainsString('m.deleted_at IS NULL AND m.is_training = 0 AND m.start_datetime >= ? AND m.start_datetime < ? + INTERVAL 1 DAY', $r);
        $this->assertSame(2, substr_count($r, 'ON pr.volunteer_id=u.id AND pr.attended=1 AND pr.is_training=0'));
        $this->assertStringContainsString('WHERE m.deleted_at IS NULL AND m.is_training = 0 AND m.start_datetime >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)', $r);

        $m = $this->src('report-municipality.php');
        $this->assertStringContainsString('m.deleted_at IS NULL AND m.is_training = 0 AND m.start_datetime >= ? AND m.start_datetime <= ? AND m.mission_type_id IN', $m);
        foreach (['m2', 'm3', 'm4'] as $alias) {
            $this->assertStringContainsString("{$alias}.deleted_at IS NULL AND {$alias}.is_training = 0 AND", $m);
        }

        $e = $this->src('includes/export-functions.php');
        $this->assertSame(2, substr_count($e, 'WHERE m.is_training = 0'));
        $this->assertStringContainsString('pr.is_training = 0', $e); // the volunteer export's participation count
    }
```

- [ ] **Step 2: Run to verify it fails**

```bash
cd /c/Users/user/Desktop/Projects/volunteer-ops
/c/xampp/php/php.exe vendor/phpunit/phpunit/phpunit tests/TrainingMissionSourceTest.php --no-progress
```
Expected: the new test FAILS.

- [ ] **Step 3: Edit `reports.php`**

(a) The shared WHERE (covers the KPI hours/points/attendance queries, hours by department and the participation tab, because they all embed `$mWhere`):
`$mWhere = "m.deleted_at IS NULL AND m.start_datetime >= ? AND m.start_datetime < ? + INTERVAL 1 DAY";`
becomes
`$mWhere = "m.deleted_at IS NULL AND m.is_training = 0 AND m.start_datetime >= ? AND m.start_datetime < ? + INTERVAL 1 DAY";`

(b) Two queries join users straight to participation (no `$mWhere`): the CSV "volunteers" rows and the "Top 15 volunteers" table. The exact text `LEFT JOIN participation_requests pr ON pr.volunteer_id=u.id AND pr.attended=1` appears **twice**; replace **both** (replace all) with
`LEFT JOIN participation_requests pr ON pr.volunteer_id=u.id AND pr.attended=1 AND pr.is_training=0`.

(c) Monthly trend (12 months), which has its own WHERE:
`WHERE m.deleted_at IS NULL AND m.start_datetime >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)`
becomes
`WHERE m.deleted_at IS NULL AND m.is_training = 0 AND m.start_datetime >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)`

- [ ] **Step 4: Edit `report-municipality.php`**

(a) Base WHERE, which feeds every query that embeds `$baseWhere`:
`$baseWhere = "m.deleted_at IS NULL AND m.start_datetime >= ? AND m.start_datetime <= ? AND m.mission_type_id IN ($typePlaceholders)" . $deptFilter;`
becomes
`$baseWhere = "m.deleted_at IS NULL AND m.is_training = 0 AND m.start_datetime >= ? AND m.start_datetime <= ? AND m.mission_type_id IN ($typePlaceholders)" . $deptFilter;`

(b) The by-type breakdown has three correlated subqueries with their own aliases that do not use `$baseWhere`. In each, add the filter right after the `deleted_at` test (three unique strings):
- `m2.deleted_at IS NULL AND` -> `m2.deleted_at IS NULL AND m2.is_training = 0 AND`
- `m3.deleted_at IS NULL AND` -> `m3.deleted_at IS NULL AND m3.is_training = 0 AND`
- `m4.deleted_at IS NULL AND` -> `m4.deleted_at IS NULL AND m4.is_training = 0 AND`

(c) Check the remaining queries in the file: every other `participation_requests`/`missions` query embeds `$baseWhere` (monthly trend, volunteer ranking, department breakdown). Read them once to be sure none builds its own WHERE.

- [ ] **Step 5: Edit `includes/export-functions.php`**

The statistics export has two queries (monthly and yearly). Replace
`WHERE m.start_datetime >= DATE_SUB(NOW(), INTERVAL 12 MONTH)" . $deptWhere`
with
`WHERE m.is_training = 0 AND m.start_datetime >= DATE_SUB(NOW(), INTERVAL 12 MONTH)" . $deptWhere`
and
`WHERE 1=1" . $deptWhere`
with
`WHERE m.is_training = 0" . $deptWhere`

Then open the function around line 60-75 (the volunteer export's participation-count subquery, `(SELECT COUNT(*) FROM shifts s JOIN participation_requests pr ON pr.shift_id = s.id ...`) and add `AND pr.is_training = 0` to its WHERE. (This is the only part of the volunteer export touched in this sub-project; see deviation 4.) Update the test's expected count of `AND m.is_training = 0` only if you formatted it differently; the two statistics queries above give 2.

- [ ] **Step 6: Run the test and lint**

```bash
cd /c/Users/user/Desktop/Projects/volunteer-ops
/c/xampp/php/php.exe vendor/phpunit/phpunit/phpunit tests/TrainingMissionSourceTest.php --no-progress
for f in reports.php report-municipality.php includes/export-functions.php; do /c/xampp/php/php.exe -l $f; done
```
Expected: `OK`, no syntax errors.

- [ ] **Step 7: Commit**

```bash
git add reports.php report-municipality.php includes/export-functions.php tests/TrainingMissionSourceTest.php
git commit -m "Reports, municipality report and statistics export exclude training missions"
```

---

### Task 8: Personal totals and the training record (profile, volunteer-view, volunteer-report)

**Files:**
- Create: `includes/training-record-section.php`
- Modify: `profile.php`, `volunteer-view.php`, `volunteer-report.php`
- Test: append to `tests/TrainingMissionSourceTest.php`

**Interfaces:**
- Consumes: `getVolunteerTrainingRecord(int $userId)` from Task 2.
- Produces: `includes/training-record-section.php` expects `$trainingRecord` (the array above) and `$trainingRecordCanViewCertificate` (bool) to be defined by the including page, and echoes one card or nothing.

- [ ] **Step 1: Add the failing source tests**

Append inside the class:

```php
    public function test_personal_pages_exclude_training_from_totals_and_show_the_record(): void
    {
        foreach (['profile.php', 'volunteer-view.php'] as $file) {
            $s = $this->src($file);
            $this->assertGreaterThanOrEqual(3, substr_count($s, 'is_training = 0'), "{$file}: totals must filter training");
            $this->assertStringContainsString('getVolunteerTrainingRecord(', $s, "{$file}: record missing");
            $this->assertStringContainsString("includes/training-record-section.php", $s, "{$file}: card missing");
        }

        $r = $this->src('volunteer-report.php');
        $this->assertGreaterThanOrEqual(2, substr_count($r, 'pr.is_training = 0'));
        $this->assertStringContainsString('getVolunteerTrainingRecord(', $r);
        $this->assertStringContainsString('Εκπαιδευτικές Ασκήσεις', $r);
    }
```

- [ ] **Step 2: Run to verify it fails**

```bash
cd /c/Users/user/Desktop/Projects/volunteer-ops
/c/xampp/php/php.exe vendor/phpunit/phpunit/phpunit tests/TrainingMissionSourceTest.php --no-progress
```
Expected: the new test FAILS.

- [ ] **Step 3: Create the shared card**

Create `includes/training-record-section.php`:

```php
<?php
/**
 * The «Εκπαιδευτικές ασκήσεις» card: drills attended, hours, dated list with a
 * link to the certificate when one was issued. Included by profile.php and
 * volunteer-view.php, which define $trainingRecord (getVolunteerTrainingRecord())
 * and $trainingRecordCanViewCertificate first. Prints nothing when there is no
 * record, so volunteers who never took part see no empty card.
 */
if (empty($trainingRecord) || ($trainingRecord['drills'] ?? 0) < 1) {
    return;
}
?>
<div class="card mb-4">
    <div class="card-header">
        <h5 class="mb-0"><i class="bi bi-mortarboard-fill text-warning me-2"></i>Εκπαιδευτικές ασκήσεις</h5>
    </div>
    <div class="card-body">
        <div class="row text-center mb-3">
            <div class="col-6">
                <div class="fs-3 fw-bold"><?= (int) $trainingRecord['drills'] ?></div>
                <div class="text-muted small">Ασκήσεις</div>
            </div>
            <div class="col-6">
                <div class="fs-3 fw-bold"><?= number_format((float) $trainingRecord['hours'], 1) ?></div>
                <div class="text-muted small">Εκπαιδευτικές ώρες</div>
            </div>
        </div>
        <ul class="list-group list-group-flush">
            <?php foreach ($trainingRecord['rows'] as $row): ?>
                <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                    <span>
                        <strong><?= h($row['title']) ?></strong><br>
                        <small class="text-muted"><?= formatDate($row['first_start']) ?> · <?= number_format($row['hours'], 1) ?> ώρες</small>
                    </span>
                    <?php if (!empty($row['certificate_id']) && !empty($trainingRecordCanViewCertificate)): ?>
                        <a class="btn btn-sm btn-outline-success" target="_blank"
                           href="mission-certificate-print.php?id=<?= (int) $row['certificate_id'] ?>">
                            <i class="bi bi-patch-check me-1"></i>Βεβαίωση
                        </a>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <div class="form-text mt-2">Οι εκπαιδευτικές ώρες καταγράφονται χωριστά και δεν προστίθενται στις ώρες εθελοντισμού.</div>
    </div>
</div>
```

(`mission-certificate-print.php` reads the certificate id from `$certId = (int) get('id')`, so the link above is right.)

- [ ] **Step 4: Edit `profile.php`**

(a) `total_shifts`: replace
`"SELECT COUNT(*) FROM participation_requests WHERE volunteer_id = ? AND status = '" . PARTICIPATION_APPROVED . "'",`
with
`"SELECT COUNT(*) FROM participation_requests WHERE volunteer_id = ? AND status = '" . PARTICIPATION_APPROVED . "' AND is_training = 0",`

(b) `total_hours`: replace
`        WHERE pr.volunteer_id = ? AND pr.status = '" . PARTICIPATION_APPROVED . "' AND pr.attended = 1",`
with
`        WHERE pr.volunteer_id = ? AND pr.status = '" . PARTICIPATION_APPROVED . "' AND pr.attended = 1 AND pr.is_training = 0",`

(c) Yearly mission attendance and the per-type counts (the two queries that begin `WHERE pr.volunteer_id = ? AND pr.attended = 1` followed by `AND YEAR(m.start_datetime) = ?`): replace **both** occurrences (replace all) of
`     WHERE pr.volunteer_id = ? AND pr.attended = 1`
and
`         WHERE pr.volunteer_id = ? AND pr.attended = 1`
adding ` AND pr.is_training = 0` at the end of each. (Open the file and edit each by hand; their indentation differs.)

(d) TEP hours: replace
`        WHERE pr.volunteer_id = ? AND pr.status = ? AND pr.attended = 1`
(the one followed by `AND m.mission_type_id = ?",`) with the same text plus ` AND pr.is_training = 0`.

(e) Show the record: directly above `<!-- My Certificates -->` add
```php
<?php
$trainingRecord = getVolunteerTrainingRecord((int) $user['id']);
$trainingRecordCanViewCertificate = true; // the volunteer's own certificates
include __DIR__ . '/includes/training-record-section.php';
?>
```

- [ ] **Step 5: Edit `volunteer-view.php`**

Apply the same filters to the queries at ~lines 470-540: `total_shifts` (`... status = '" . PARTICIPATION_APPROVED . "'"` gets `AND is_training = 0`), `attended_shifts` and `total_hours` (`WHERE volunteer_id = ? AND attended = 1` becomes `WHERE volunteer_id = ? AND attended = 1 AND is_training = 0`, both), and the three `pr.attended = 1` queries on `participation_requests pr` (`... AND pr.is_training = 0`). Then, directly above `<!-- Mission Certificates (Βεβαιώσεις)` add:
```php
<?php
$trainingRecord = getVolunteerTrainingRecord((int) $id);
$trainingRecordCanViewCertificate = true; // admin viewing a volunteer
include __DIR__ . '/includes/training-record-section.php';
?>
```
(`$id` is the volunteer's id in this page, as the existing queries bind it.)

- [ ] **Step 6: Edit `volunteer-report.php`**

(a) In the two queries at lines ~34-56 change `AND pr.attended = 1` to `AND pr.attended = 1 AND pr.is_training = 0` (both).
(b) Above the `<!-- ── Achievements ── -->` comment add a separate block:
```php
        <?php $trainingRecord = getVolunteerTrainingRecord((int) $id); ?>
        <?php if (!empty($trainingRecord['rows'])): ?>
        <div class="section-title"><i class="bi bi-mortarboard me-1"></i>Εκπαιδευτικές Ασκήσεις</div>
        <p class="small text-muted mb-2">Δεν περιλαμβάνονται στις ώρες εθελοντισμού παραπάνω.
            Σύνολο: <?= (int) $trainingRecord['drills'] ?> ασκήσεις, <?= number_format($trainingRecord['hours'], 1) ?> ώρες.</p>
        <table class="table table-sm">
            <?php foreach ($trainingRecord['rows'] as $row): ?>
                <tr>
                    <td><?= formatDate($row['first_start']) ?></td>
                    <td><?= h($row['title']) ?></td>
                    <td class="text-end"><?= number_format($row['hours'], 1) ?>h</td>
                </tr>
            <?php endforeach; ?>
        </table>
        <?php endif; ?>
```
Confirm the report's volunteer-id variable is `$id` (the queries above use `[$id]`).

- [ ] **Step 7: Run the test and lint**

```bash
cd /c/Users/user/Desktop/Projects/volunteer-ops
/c/xampp/php/php.exe vendor/phpunit/phpunit/phpunit tests/TrainingMissionSourceTest.php --no-progress
for f in profile.php volunteer-view.php volunteer-report.php includes/training-record-section.php; do /c/xampp/php/php.exe -l $f; done
```
Expected: `OK`, no syntax errors.

- [ ] **Step 8: Commit**

```bash
git add includes/training-record-section.php profile.php volunteer-view.php volunteer-report.php tests/TrainingMissionSourceTest.php
git commit -m "Show a separate training record; personal totals exclude training"
```

---

### Task 9: Certificate wording and hours for training missions

**Files:**
- Modify: `mission-certificate-print.php`, `certificate-verify.php`, `mission-certificates.php`
- Test: append to `tests/TrainingMissionSourceTest.php`

**Interfaces:**
- Consumes: `isTrainingMission()`, `getTrainingHoursForMission()` (Task 2).

- [ ] **Step 1: Add the failing source test**

Append inside the class:

```php
    public function test_certificate_documents_say_training_exercise_and_show_hours(): void
    {
        $p = $this->src('mission-certificate-print.php');
        $this->assertStringContainsString('Βεβαίωση Συμμετοχής σε Εκπαιδευτική Άσκηση', $p);
        $this->assertStringContainsString('Certificate of Participation in a Training Exercise', $p);
        $this->assertStringContainsString('getTrainingHoursForMission(', $p);
        $this->assertStringContainsString('m.is_training', $p);

        $v = $this->src('certificate-verify.php');
        $this->assertStringContainsString('m.is_training', $v);
        $this->assertStringContainsString('Εκπαιδευτική Άσκηση', $v);

        $i = $this->src('mission-certificates.php');
        $this->assertStringContainsString('getTrainingHoursForMission(', $i);
    }
```

- [ ] **Step 2: Run to verify it fails**

```bash
cd /c/Users/user/Desktop/Projects/volunteer-ops
/c/xampp/php/php.exe vendor/phpunit/phpunit/phpunit tests/TrainingMissionSourceTest.php --no-progress
```
Expected: the new test FAILS.

- [ ] **Step 3: Edit `mission-certificate-print.php`**

(a) In the certificate query add `m.is_training` to the select list: change `m.start_datetime, m.end_datetime, m.responsible_user_id,` to `m.start_datetime, m.end_datetime, m.responsible_user_id, m.is_training,`.

(b) Directly after the `$copy = [ ... ][$lang];` statement (before `$printDate = ...`) add:
```php
$isTrainingCert = (int) ($cert['is_training'] ?? 0) === 1;
$trainingHours = $isTrainingCert ? getTrainingHoursForMission((int) $cert['mission_id'], (int) $cert['recipient_user_id']) : 0.0;
if ($isTrainingCert) {
    if ($lang === 'en') {
        $copy['doc_title']  = 'Certificate of Participation in a Training Exercise';
        $copy['body_lead']  = 'in recognition of participation in the training exercise';
        $copy['notice']     = 'Print Preview — Certificate of Participation in a Training Exercise';
        $copy['hours_line'] = 'Training hours completed';
    } else {
        $copy['doc_title']  = 'Βεβαίωση Συμμετοχής σε Εκπαιδευτική Άσκηση';
        $copy['body_lead']  = 'για τη συμμετοχή του/της στην εκπαιδευτική άσκηση';
        $copy['notice']     = 'Προεπισκόπηση Εκτύπωσης — Βεβαίωση Συμμετοχής σε Εκπαιδευτική Άσκηση';
        $copy['hours_line'] = 'Ώρες εκπαίδευσης';
    }
}
```
(`$cert` is `SELECT mc.*, m...`, so `mission_id` and `recipient_user_id` are present.)

(c) In the body, directly after the `<?= h($copy['at_location']) ?> ...<?php endif; ?>.` paragraph content (the line ending `<?php endif; ?>.` at ~line 299), add:
```php
                <?php if ($isTrainingCert && $trainingHours > 0): ?>
                    <br><?= h($copy['hours_line']) ?>: <strong><?= number_format($trainingHours, 1) ?></strong>
                <?php endif; ?>
```
Place it inside the same `<div class="cert-body">` so it renders under the sentence; open the file and confirm the `.cert-body` closing tag is after your insertion.

- [ ] **Step 4: Edit `certificate-verify.php`**

In the verify query add `m.is_training` next to `m.title AS mission_title, m.start_datetime,`, so it reads `m.title AS mission_title, m.start_datetime, m.is_training,`. Directly after the line that prints `Αποστολή / Mission` (`<div class="verify-row"><span class="label">Αποστολή / Mission</span>...`) add:
```php
            <?php if (!empty($cert['is_training'])): ?>
            <div class="verify-row"><span class="label">Τύπος / Type</span><span class="value">Εκπαιδευτική Άσκηση / Training Exercise</span></div>
            <?php endif; ?>
```

- [ ] **Step 5: Edit `mission-certificates.php`**

In the recipient picker loop (where each user `$u` of `$pickableUsers` is printed as a checkbox row), when `isTrainingMission($mission)` is true append the recorded hours next to the name. Open the file at the `foreach ($pickableUsers as $u)` that renders the checkbox list (below the `$preselectedCount` block) and, next to where the user's name is output, add:
```php
<?php if (isTrainingMission($mission) && $u['has_checked_in']): ?>
    <span class="badge bg-light text-dark border ms-1"><?= number_format(getTrainingHoursForMission($missionId, (int) $u['id']), 1) ?> ώρες</span>
<?php endif; ?>
```
Above the form, when the mission is a training one, add a one-line note under the existing blue alert:
```php
<?php if (isTrainingMission($mission)): ?>
    <div class="alert alert-warning py-2 small"><i class="bi bi-mortarboard-fill me-1"></i>Εκπαιδευτική άσκηση: οι βεβαιώσεις θα αναφέρουν «Βεβαίωση Συμμετοχής σε Εκπαιδευτική Άσκηση» και τις ώρες εκπαίδευσης κάθε συμμετέχοντα.</div>
<?php endif; ?>
```
(`$mission` here is `SELECT m.*, ...`, so it carries `is_training`. Confirm the picker's name cell by reading the loop before editing.)

- [ ] **Step 6: Run the test and lint**

```bash
cd /c/Users/user/Desktop/Projects/volunteer-ops
/c/xampp/php/php.exe vendor/phpunit/phpunit/phpunit tests/TrainingMissionSourceTest.php --no-progress
for f in mission-certificate-print.php certificate-verify.php mission-certificates.php; do /c/xampp/php/php.exe -l $f; done
```
Expected: `OK`, no syntax errors.

- [ ] **Step 7: Manual check of the printed certificate**

Open a training mission (create one through the form with the box ticked, complete it, mark one volunteer attended with hours, issue a certificate) and open `mission-certificate-print.php?id=<id>` in the browser. Expected: the title reads «Βεβαίωση Συμμετοχής σε Εκπαιδευτική Άσκηση», the hours line shows, the QR link opens the verify page showing «Εκπαιδευτική Άσκηση». Report what you saw; do not claim this step passed without looking.

- [ ] **Step 8: Commit**

```bash
git add mission-certificate-print.php certificate-verify.php mission-certificates.php tests/TrainingMissionSourceTest.php
git commit -m "Training certificates: exercise wording and recorded hours"
```

---

### Task 10: Badges, banners and the missions-list filter

**Files:**
- Modify: `missions.php`, `mission-view.php`, `war-room.php`
- Test: append to `tests/TrainingMissionSourceTest.php`

- [ ] **Step 1: Add the failing source test**

Append inside the class:

```php
    public function test_badges_banners_and_list_filter(): void
    {
        $l = $this->src('missions.php');
        $this->assertStringContainsString("\$trainingFilter = get('training');", $l);
        $this->assertStringContainsString("'m.is_training = 1'", $l);
        $this->assertStringContainsString("'m.is_training = 0'", $l);
        $this->assertSame(2, substr_count($l, 'trainingMissionBadgeHtml($mission)'));

        $v = $this->src('mission-view.php');
        $this->assertStringContainsString('trainingMissionBadgeHtml($mission)', $v);
        $this->assertStringContainsString('Εκπαιδευτική άσκηση - δεν επηρεάζει τα στατιστικά', $v);

        $w = $this->src('war-room.php');
        $this->assertStringContainsString('Εκπαιδευτική άσκηση - δεν επηρεάζει τα στατιστικά', $w);
    }
```

- [ ] **Step 2: Run to verify it fails**

```bash
cd /c/Users/user/Desktop/Projects/volunteer-ops
/c/xampp/php/php.exe vendor/phpunit/phpunit/phpunit tests/TrainingMissionSourceTest.php --no-progress
```
Expected: the new test FAILS.

- [ ] **Step 3: Edit `missions.php` - filter and badge**

(a) Below `$missionType = (int)get('mission_type', 0);` add:
```php
$trainingFilter = get('training'); // '' = all, '0' = operational only, '1' = training only
```
(b) Below the block that ends `$params[] = $missionType;\n}` (the `if ($missionType) { ... }` block), add:
```php
if ($trainingFilter === '1') {
    $where[] = 'm.is_training = 1';
} elseif ($trainingFilter === '0') {
    $where[] = 'm.is_training = 0';
}
```
(c) Filter control: directly after the `</div>` that closes the column containing `<select class="form-select form-select-sm" name="mission_type">`, add a sibling column:
```php
            <div class="col-md-2">
                <select class="form-select form-select-sm" name="training">
                    <option value="">Όλες</option>
                    <option value="0" <?= $trainingFilter === '0' ? 'selected' : '' ?>>Επιχειρησιακές</option>
                    <option value="1" <?= $trainingFilter === '1' ? 'selected' : '' ?>>Εκπαιδευτικές</option>
                </select>
            </div>
```
Check the surrounding Bootstrap grid after adding it (the row may wrap; adjust a neighbouring `col-md-*` if needed) and confirm that the form's reset/pagination links keep or drop `training` consistently with how they treat `mission_type`.

(d) Badge: the two occurrences of `<strong><?= h($mission['title']) ?></strong>` (replace all) become
`<strong><?= h($mission['title']) ?></strong><?= trainingMissionBadgeHtml($mission) ?>`

- [ ] **Step 4: Edit `mission-view.php`**

Directly after `<?= h($mission['title']) ?>` inside the `<h1 class="h3 mb-0">` (the line before `<?php if (!empty($mission['type_name'])): ?>`) add `<?= trainingMissionBadgeHtml($mission) ?>`. Directly after the `</div>` that closes that header row (`<div class="d-flex justify-content-between align-items-center mb-4">`), add:
```php
<?php if (!empty($mission['is_training'])): ?>
<div class="alert alert-warning d-flex align-items-center mb-4">
    <i class="bi bi-mortarboard-fill fs-4 me-3"></i>
    <div><strong>Εκπαιδευτική άσκηση - δεν επηρεάζει τα στατιστικά.</strong>
    Οι ώρες σας καταγράφονται ως εκπαιδευτικές και δεν δίνουν πόντους ή επιτεύγματα.</div>
</div>
<?php endif; ?>
```
(`$mission` is `SELECT m.*`, so `is_training` is present.)

- [ ] **Step 5: Edit `war-room.php`**

Directly after `<h1 class="h3 mb-2"><?= h($mission['title']) ?></h1>` add:
```php
            <?php if (!empty($mission['is_training'])): ?>
                <div class="badge bg-warning text-dark mb-2"><i class="bi bi-mortarboard-fill me-1"></i>ΑΣΚΗΣΗ · Εκπαιδευτική άσκηση - δεν επηρεάζει τα στατιστικά</div>
            <?php endif; ?>
```
(`$mission` is `SELECT m.*, d.name ...` at war-room.php:34.)

- [ ] **Step 6: Run the test, lint, and look at it**

```bash
cd /c/Users/user/Desktop/Projects/volunteer-ops
/c/xampp/php/php.exe vendor/phpunit/phpunit/phpunit tests/TrainingMissionSourceTest.php --no-progress
for f in missions.php mission-view.php war-room.php; do /c/xampp/php/php.exe -l $f; done
```
Expected: `OK`. Then open `missions.php?training=1`, a training `mission-view.php?id=...` and its Action Room in the browser and confirm the badge, banner and filter render. Report what you saw.

- [ ] **Step 7: Commit**

```bash
git add missions.php mission-view.php war-room.php tests/TrainingMissionSourceTest.php
git commit -m "Training missions: badge, banner and a missions-list filter"
```

---

### Task 11: «[ΑΣΚΗΣΗ]» prefix on participant notifications

**Files:**
- Modify: `includes/email.php` (`sendMissionOpenedNotifications`), `cron_shift_reminders.php`, `participation-action.php`
- Test: append to `tests/TrainingMissionSourceTest.php`

**Interfaces:**
- Consumes: `trainingTitlePrefix(int|array $mission): string` (Task 2).

- [ ] **Step 1: Add the failing source test**

Append inside the class:

```php
    public function test_participant_notifications_carry_the_exercise_prefix(): void
    {
        $e = $this->src('includes/email.php');
        $this->assertStringContainsString("trainingTitlePrefix(\$mission) . 'Νέα Αποστολή: '", $e);
        $this->assertStringContainsString("'mission_title'       => trainingTitlePrefix(\$mission) . \$mission['title']", $e);

        $c = $this->src('cron_shift_reminders.php');
        $this->assertStringContainsString('m.is_training', $c);
        $this->assertStringContainsString('trainingTitlePrefix(', $c);

        $p = $this->src('participation-action.php');
        $this->assertStringContainsString('trainingTitlePrefix(', $p);
    }
```

- [ ] **Step 2: Run to verify it fails**

```bash
cd /c/Users/user/Desktop/Projects/volunteer-ops
/c/xampp/php/php.exe vendor/phpunit/phpunit/phpunit tests/TrainingMissionSourceTest.php --no-progress
```
Expected: the new test FAILS.

- [ ] **Step 3: Edit `sendMissionOpenedNotifications()` in `includes/email.php`**

In the in-app notification call replace the title argument
`'Νέα Αποστολή: ' . $mission['title'],`
with
`trainingTitlePrefix($mission) . 'Νέα Αποστολή: ' . $mission['title'],`
and in the email variables replace
`'mission_title'       => $mission['title'],`
with
`'mission_title'       => trainingTitlePrefix($mission) . $mission['title'],`

Both callers pass a mission array: `mission-form.php` passes `$data` (which now has `is_training`) and `mission-view.php` passes the `SELECT m.*` row. `trainingTitlePrefix()` accepts an array with `is_training`, and falls back to a lookup by `id` when the array has none, so no caller needs changing.

- [ ] **Step 4: Edit `cron_shift_reminders.php`**

The shift query selects `m.title as mission_title, m.description as mission_description`. Change it to also select `m.is_training` (`m.title as mission_title, m.is_training, m.description as mission_description`). Then use `$missionLabel = trainingTitlePrefix(['is_training' => $shift['is_training']]) . $shift['mission_title'];` once per shift and use `$missionLabel` in the two places that currently use `$shift['mission_title']` (the email variable `'mission_title'` and the in-app message string `...στην αποστολή '{$shift['mission_title']}'...`). Read the file first; the variable holding the shift row is `$shift`.

- [ ] **Step 5: Edit `participation-action.php`**

At the `participation_approved` and `participation_rejected` email/notification calls (around lines 105-150), the mission title is passed to the email variables and the in-app message. Find where the mission is loaded in that file (it already needs the title), make sure that query selects `m.is_training`, and wrap the title the same way: `trainingTitlePrefix(['is_training' => $row['is_training']]) . $title`. Read the file first to use its real variable names (`$volunteer`, the mission row); apply the prefix to both the email `mission_title` variable and the in-app message title, for both approved and rejected.

- [ ] **Step 6: Run the test and lint**

```bash
cd /c/Users/user/Desktop/Projects/volunteer-ops
/c/xampp/php/php.exe vendor/phpunit/phpunit/phpunit tests/TrainingMissionSourceTest.php --no-progress
for f in includes/email.php cron_shift_reminders.php participation-action.php; do /c/xampp/php/php.exe -l $f; done
```
Expected: `OK`, no syntax errors.

- [ ] **Step 7: Commit**

```bash
git add includes/email.php cron_shift_reminders.php participation-action.php tests/TrainingMissionSourceTest.php
git commit -m "Prefix participant notifications of training missions with [ΑΣΚΗΣΗ]"
```

---

### Task 12: Guard test - no file may read hours/attendance/points unfiltered

**Files:**
- Create: `tests/TrainingIsolationGuardTest.php`

- [ ] **Step 1: Write the guard test**

Create `tests/TrainingIsolationGuardTest.php`:

```php
<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Fails when a PHP file reads participation hours/attendance/points but neither
 * filters on is_training nor sits on the reviewed allow-list below. This is a
 * per-FILE check (a file with the filter in one query and a missed second query
 * would pass); the aggregate and source tests cover the per-query cases.
 *
 * Every allow-list entry must keep a reason. Adding a file here is a decision,
 * not a way to silence the test.
 */
final class TrainingIsolationGuardTest extends TestCase
{
    /** Files that legitimately read participation numbers without filtering, and why. */
    private const ALLOWED = [
        'includes/functions-warroom.php'      => 'QR/GPS check-in writes attendance for the mission being run; a drill must record attendance and hours too',
        'mission-stats.php'                   => 'per-mission page: the mission is the scope, a drill shows its own stats',
        'mission-report-print.php'            => 'per-mission report: the mission is the scope',
        'shift-participants-print.php'        => 'per-shift roster print',
        'ops-dashboard.php'                   => 'live per-mission operations board; drills appear with their badge',
        'inventory-view.php'                  => 'inventory bookings have their own actual_hours column; unrelated table',
        'includes/inventory-functions.php'    => 'inventory bookings have their own actual_hours column; unrelated table',
    ];

    /** Pages that are reporting surfaces and must filter (checked by name, not by pattern). */
    private const MUST_FILTER = [
        'dashboard.php', 'reports.php', 'report-municipality.php', 'includes/export-functions.php',
        'includes/functions-core.php', 'includes/achievements-functions.php', 'leaderboard.php',
        'volunteers.php', 'inactive-volunteers.php', 'profile.php', 'volunteer-view.php',
        'volunteer-report.php', 'attendance.php', 'shift-view.php',
    ];

    private function projectFiles(): array
    {
        $root = realpath(__DIR__ . '/..');
        $out = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            $path = str_replace('\\', '/', $f->getPathname());
            $rel = ltrim(substr($path, strlen(str_replace('\\', '/', $root))), '/');
            if (substr($rel, -4) !== '.php') {
                continue;
            }
            if (preg_match('#^(tests|vendor|node_modules|\.git|\.phpunit\.cache)/#', $rel)) {
                continue;
            }
            if (in_array($rel, ['includes/migrations.php', 'install.php'], true)) {
                continue; // schema history and the installer's seed data, not stats readers
            }
            $out[$rel] = $path;
        }
        return $out;
    }

    public function test_every_reader_of_participation_numbers_filters_training_or_is_allow_listed(): void
    {
        $offenders = [];
        foreach ($this->projectFiles() as $rel => $path) {
            $src = file_get_contents($path);
            $reads = preg_match('/actual_hours|attended\s*=\s*1|attended=1|points_awarded/', $src) === 1;
            if (!$reads) {
                continue;
            }
            if (strpos($src, 'is_training') !== false || isset(self::ALLOWED[$rel])) {
                continue;
            }
            $offenders[] = $rel;
        }
        $this->assertSame(
            [],
            $offenders,
            "These files read hours/attendance/points but neither filter is_training nor are allow-listed with a reason:\n  "
            . implode("\n  ", $offenders)
        );
    }

    public function test_the_reporting_surfaces_all_filter(): void
    {
        $root = realpath(__DIR__ . '/..');
        foreach (self::MUST_FILTER as $rel) {
            $src = file_get_contents($root . '/' . $rel);
            $this->assertNotFalse($src, "cannot read {$rel}");
            $this->assertStringContainsString('is_training', $src, "{$rel} is a reporting surface and must filter training");
        }
    }

    public function test_the_allow_list_has_no_stale_entries(): void
    {
        $root = realpath(__DIR__ . '/..');
        foreach (array_keys(self::ALLOWED) as $rel) {
            $this->assertFileExists($root . '/' . $rel, "allow-list names a file that no longer exists: {$rel}");
        }
    }
}
```

- [ ] **Step 2: Run the guard**

```bash
cd /c/Users/user/Desktop/Projects/volunteer-ops
/c/xampp/php/php.exe vendor/phpunit/phpunit/phpunit tests/TrainingIsolationGuardTest.php --no-progress
```
Expected: `OK (3 tests, ...)`. If the first test lists a file, **open that file and decide**: if it aggregates real numbers, add the filter (and a source assertion in Task 6/7/8's style); if it is genuinely a per-mission or unrelated read, add it to `ALLOWED` with a one-line reason. Do not add a file without reading its query.

- [ ] **Step 3: Prove the guard bites**

Temporarily delete the string `AND pr.is_training = 0` from one line in `leaderboard.php` *and* delete every other `is_training` mention in that file's copy (or simply run the guard against a scratch copy), run the test, confirm it names `leaderboard.php`, then `git checkout leaderboard.php` to restore it. Expected: FAIL naming the file, then PASS after the restore. Report the output.

- [ ] **Step 4: Commit**

```bash
git add tests/TrainingIsolationGuardTest.php
git commit -m "Add the guard test: participation readers must filter training missions"
```

---

### Task 13: Version bump, amend the spec, full verification

**Files:**
- Modify: `config.php:14`, `sw.js:28`, `docs/superpowers/specs/2026-11-20-training-missions-design.md`

- [ ] **Step 1: Amend the spec with the deviations**

In the spec's section 2 ("Outbound notifications"), replace that paragraph with:

```
**Outbound notifications.** Notifications about a training mission carry the "[ΑΣΚΗΣΗ]" prefix
(in-app title, push title, email subject/title) where they go to participants: the "new mission"
notification (whose audience the admin picks in the form - it is the drill's invitation), shift
reminders, and participation approved/rejected. Calendar feeds are login-gated and show the user's own
shifts, so a drill shift correctly appears in the volunteer's own calendar. Action Room notifications
(31 call sites in functions-warroom.php, no common choke point) are not prefixed in sub-project 1; the
Action Room carries the "ΑΣΚΗΣΗ" banner instead, and prefixing them is revisited with sub-projects 2/3.
```

In section 4 ("Delivery") replace "`tests/fixtures/schema-structure.sql` is regenerated." with "`tests/fixtures/schema-structure.sql` is not regenerated: it lags by design and `tests/migrate-fixture.php` brings it forward." Add under section 3 a line: "The volunteer export gets only the isolation filter in this sub-project; training columns in that export are a follow-up."

- [ ] **Step 2: Bump the version**

Change `define('APP_VERSION', '3.503.0');` to `define('APP_VERSION', '3.504.0');` in `config.php`, and `const CACHE_VERSION = 'vo-v3.503.0';` to `const CACHE_VERSION = 'vo-v3.504.0';` in `sw.js` (CI fails when they differ).

- [ ] **Step 3: Lint every changed PHP file and run the whole suite**

```bash
cd /c/Users/user/Desktop/Projects/volunteer-ops
git diff --name-only 83b5f98 -- '*.php' | while read f; do /c/xampp/php/php.exe -l "$f" | grep -v "No syntax errors" ; done
/c/xampp/php/php.exe tests/migrate-fixture.php
/c/xampp/php/php.exe vendor/phpunit/phpunit/phpunit --no-progress 2>&1 | tail -12
node --test tests/js 2>&1 | tail -6
```
Expected: no lint output; `fixture schema: 224 -> 224`; PHPUnit totals equal the Task 0 baseline plus the new tests with no new failures; node tests unchanged. Compare the failure list with Task 0's baseline and report any difference honestly.

- [ ] **Step 4: End-to-end manual check (owner-visible behaviour)**

On a local copy (XAMPP, `volunteer_ops` database) do this once and write down what you saw:
1. Create a mission with "Εκπαιδευτική Άσκηση" ticked; open it for edit and confirm the box is gone and the note says it cannot change.
2. Add a volunteer, mark attended with 3 hours; confirm the dashboard hours, the leaderboard and the municipality report do **not** change, and the volunteer's profile shows «Εκπαιδευτικές ασκήσεις: 1 άσκηση, 3.0 ώρες».
3. Complete the mission, issue a certificate, open it and its verify page.
4. Confirm no row was added to `volunteer_points` for that volunteer.

- [ ] **Step 5: Deployment check (Hostinger)**

The migration creates a trigger. After deploying, run `SHOW TRIGGERS LIKE 'participation_requests'` (phpMyAdmin) and confirm `trg_pr_set_training` exists, and `SELECT setting_value FROM settings WHERE setting_key='migration_last_error'` is empty. If the trigger is missing, stop: do not let volunteers join a training mission until it is fixed (drills would be counted as real). Raise it with the owner; the fallback of setting the column in each insert site is a deliberate decision for them.

- [ ] **Step 6: Commit**

```bash
git add config.php sw.js docs/superpowers/specs/2026-11-20-training-missions-design.md
git commit -m "v3.504.0: Training missions - an is_training flag, isolation from real stats and points, a separate training record and certificate"
```

---

## Self-review (done while writing)

**Spec coverage**
- Flag at creation, locked (spec 1) -> Tasks 1, 2, 3. UPDATE never writes it (Task 3 test).
- Trigger copy, one migration, schema.sql (spec 1) -> Task 1. Fixture not regenerated: deviation 3.
- Participation numbers, points, achievements (spec 2 tier 1) -> Tasks 4, 5, 6, 7, 8.
- Mission-level aggregates (tier 2) -> Tasks 6 (dashboard filter), 7 (reports, municipality, statistics export); missions pages keep showing training with badge and filter -> Task 10.
- Notifications (tier 3) -> Task 11 + deviations 1 and 2 (amended in Task 13).
- Guard test + its limitation -> Task 12; per-query cases -> source tests in Tasks 5-8.
- Training record (spec 3) -> Task 8 (profile, volunteer-view, volunteer-report); volunteer export columns -> deviation 4.
- Certificate (spec 3) -> Task 9. Pre-selection already exists: deviation 5.
- Badges/banners/filter (spec 3) -> Task 10.
- Error handling / trigger fails loudly / deployment check (spec 4) -> Task 1 Step 4 comment, Task 13 Step 5.
- Tests (spec 4): trigger (Task 1), aggregate (Task 4), notification and certificate (source tests, Tasks 9, 11), guard (Task 12).

**Placeholder scan:** every step shows its code or the exact text to replace. Steps that say "open the file and confirm" (certificate query-string name, notification variable names in `participation-action.php`, the picker's name cell, the Bootstrap grid) are verifications of a name the plan could not read at planning time, each with a stated fallback; they are the only places where the executor reads before editing.

**Type consistency:** `isTrainingMission`, `trainingMissionFlagForSave`, `trainingTitlePrefix`, `trainingMissionBadgeHtml`, `getVolunteerTrainingRecord`, `getTrainingHoursForMission` are defined in Task 2 and used with those exact names and shapes in Tasks 3, 8, 9, 10, 11. `TrainingFixtures::user/mission/participation` are defined in Task 1 and used with those signatures in Tasks 2 and 4.

**Known limits, stated plainly:** the Action Room's 31 notification sites are not prefixed (deviation 2); the per-file guard cannot catch a second unfiltered query inside a file that filters elsewhere; the browser checks in Tasks 9, 10 and 13 are manual and must be reported as observed, not assumed.

## Execution notes (added after implementation)

Where execution diverged from the plan text above:

- **Task 7:** the line-69 export edit was reverted. That subquery is the per-mission headcount of the missions CSV, not a volunteer-export count, so drills keep their real headcount there.
- **Task 10:** the Action Room uses a `hero.training_badge` t() key (Greek and English) rather than a hard-coded string. The task also adds the ΑΣΚΗΣΗ badge on the dashboard (including the shift-swap rows), makes the missions CSV honour the `training` filter, and makes the attendance flash in `shift-view.php` accurate for drills.
- **Task 12:** the guard test dropped the `migrations.php` / `install.php` skip and added a `.superpowers` skip.
- **Task 1:** also bumped the schema-version pin in `tests/ScenarioPageTest.php`.
- **Task 4:** the test seeds the missing achievement rows inside its transaction.

Final whole-branch review wave (fixes after the review):

- **Action Room scorecard:** `computeWarRoomTypeAggregates()` (reports.php "Action Room" tab, warroom CSV) now filters `m.is_training = 0` in its shared `$where`, which every pillar query uses; a functional test seeds a real and a drill COMPLETED mission of one type. The `includes/functions-warroom.php` entry left the guard allow-list (the file filters on its own now).
- **Notifications:** `cron_incomplete_missions.php` skips drills (`m.is_training = 0`). The "[ΑΣΚΗΣΗ]" prefix is now on the remaining participant messages in `shift-view.php`, `mission-view.php`, `includes/shift-swap-functions.php` and `shift-swap.php`, each through a `$missionLabel` built once; the assertions for `cron_shift_reminders.php` and `participation-action.php` were tightened.
- **Migration 224:** after the trigger is created it runs an idempotent reconciliation UPDATE (see the second fix wave below: the SQL is now a literal inside the migration; `trainingReconcileParticipationsSql()` in `includes/functions-training-missions.php` stays only so tests can call it without loading `migrations.php`, which runs the migrations as a side effect). `FreshInstallTest` asserts `trg_pr_set_training` exists after loading `sql/schema.sql`.
- **Minor:** `volunteer-view.php` shows certificate links only to `missions_manage` users. The missions-list pagination already keeps `training` and `mission_type` (`paginationLinks()` rebuilds from `$_GET`, ignoring its second argument), so only a test was added.
- **Docs:** the spec now describes the failure mode as it is (exception reaches the runner, `migration_last_error`, retry, reconciliation), drops the calendar-feed contradiction, lists every notification site, the skipped cron broadcast and the Action Room scorecard.

Fail-closed gate (commit b6e04ae):

- **Gate:** `trainingTriggerExists()` / `trainingMissionsAvailable()` in `includes/functions-training-missions.php` check `information_schema.TRIGGERS` for `trg_pr_set_training` (cached per request; fails closed, including on a host where the DB user lacks the TRIGGER privilege). `mission-form.php` hides the checkbox with an explanation and rejects a posted `is_training` on create with an error, never saving a real mission silently. Admins get a warning banner (`includes/training-gate-banner.php`) on `dashboard.php` and `mission-form.php`. Tests: `tests/TrainingGatingTest.php`.

Second fix wave (after the gate review):

- **Migration 224 is self-sufficient:** `update.php` (step 5b) evals a freshly downloaded `includes/migrations.php` inside the OLD running process, whose bootstrap predates `functions-training-missions.php`, so the migration can no longer call `trainingReconcileParticipationsSql()`. The reconcile `UPDATE` is a literal inside the migration closure (canonical copy, still only after a successful `CREATE TRIGGER`, no try/catch). The helper stays for the functional test; `TrainingMissionSchemaTest` extracts the literal from the migration 224 block and asserts it equals the helper's SQL after whitespace normalisation (drift guard).
- **Banner condition:** `training-gate-banner.php` shows only when `!trainingMissionsAvailable()`. `migration_last_error` is never cleared by the runner, so as an OR condition it would have shown the banner forever after any past failure of any migration; it is now read only for the detail line (trimmed, 200 characters, escaped with `h()`), and the Greek wording no longer names a migration error as an independent trigger.
- **Cleanups:** blank line and trailing newline in `TrainingMissionSchemaTest.php`; the spec's calendar-feed sentence now says what the feeds really list (own shifts by default, otherwise open missions' shifts, so a drill shift can appear).
- **Docs:** the spec gained a "Fail-closed gate" paragraph and its failure-mode text now describes the literal reconcile SQL.

Duplicate gate (third fix wave):

- **Duplicate gate:** the "duplicate" action in `missions.php` copied `is_training` with no gate check, so duplicating a drill while the trigger was missing created a new drill whose participations would count as real. It now refuses (error flash with the same message as `mission-form.php`, redirect to `missions.php`) when the source is a drill and `trainingMissionsAvailable()` is false; duplicating a real mission and duplicating a drill while the trigger exists are unchanged. Test: `test_duplicating_a_drill_is_refused_while_the_trigger_is_missing` in `tests/TrainingGatingTest.php`. Also: the "cached per name" test was renamed to what it asserts (exact-name matching, repeated calls agree), and the spec's deployment check now reads "the trigger exists" because the runner never clears `migration_last_error`.
