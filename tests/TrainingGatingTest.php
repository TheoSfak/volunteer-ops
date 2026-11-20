<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The "fail closed" gate for training missions: a drill may only be created while
 * the trigger that copies missions.is_training onto participation_requests exists.
 * Without it the participations of a drill would be counted as real ones.
 *
 * Nothing here drops the trigger or alters the schema (DDL auto-commits); the
 * negative case is a trigger name that cannot exist.
 */
final class TrainingGatingTest extends TestCase
{
    private const UNAVAILABLE_MESSAGE = 'Η δημιουργία εκπαιδευτικής άσκησης δεν είναι διαθέσιμη: η βάση δεδομένων δεν έχει ενεργό τον μηχανισμό (trigger) που απομονώνει τις ασκήσεις από τα πραγματικά στατιστικά. Ενημερώστε τον διαχειριστή συστήματος.';

    private function src(string $file): string
    {
        $s = file_get_contents(__DIR__ . '/../' . $file);
        $this->assertNotFalse($s, "cannot read {$file}");
        return $s;
    }

    public function test_the_trigger_exists_on_the_test_database(): void
    {
        $this->assertTrue(trainingTriggerExists(), 'migration 224 should have created trg_pr_set_training');
    }

    public function test_an_unknown_trigger_name_is_reported_missing(): void
    {
        $this->assertFalse(trainingTriggerExists('no_such_trigger_xyz'));
    }

    public function test_the_trigger_name_is_matched_exactly_and_the_result_is_cached_per_name(): void
    {
        // A prefix of the real name must not match, and asking twice gives the same answer.
        $this->assertFalse(trainingTriggerExists('trg_pr_set_trainin'));
        $this->assertFalse(trainingTriggerExists('trg_pr_set_trainin'));
        $this->assertTrue(trainingTriggerExists('trg_pr_set_training'));
        $this->assertTrue(trainingTriggerExists());
    }

    public function test_missions_available_reads_as_the_trigger_check(): void
    {
        $this->assertSame(trainingTriggerExists(), trainingMissionsAvailable());
    }

    public function test_the_check_is_one_query_with_a_fail_closed_catch_and_a_static_cache(): void
    {
        $s = $this->src('includes/functions-training-missions.php');
        $this->assertSame(1, preg_match('/function trainingTriggerExists\(.*?\n    \}\n/s', $s, $m), 'trainingTriggerExists not found');
        $body = $m[0];
        $this->assertStringContainsString('information_schema.TRIGGERS', $body);
        $this->assertStringContainsString("EVENT_OBJECT_TABLE = 'participation_requests'", $body);
        $this->assertStringContainsString('TRIGGER_SCHEMA = DATABASE()', $body);
        $this->assertStringContainsString('static $cache', $body);
        $this->assertMatchesRegularExpression('/catch\s*\(\s*Throwable/', $body);
        $this->assertStringContainsString('TRIGGER privilege', $s, 'the privilege limit must stay documented');
    }

    public function test_mission_form_gates_both_the_render_and_the_save(): void
    {
        $s = $this->src('mission-form.php');
        $this->assertGreaterThanOrEqual(2, substr_count($s, 'trainingMissionsAvailable()'), 'render + save must both ask');
        $this->assertStringContainsString(self::UNAVAILABLE_MESSAGE, $s);

        // Save: a requested drill with no trigger is an error, never a silent real mission.
        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*!\$isEdit\s*&&\s*!empty\(\$_POST\[\'is_training\'\]\)\s*&&\s*!trainingMissionsAvailable\(\)\s*\)\s*\{\s*\$errors\[\]\s*=/s',
            $s
        );
        // ... and that check sits before the "no errors, so write" branch.
        $this->assertLessThan(
            strpos($s, 'if (empty($errors)) {'),
            strpos($s, "!empty(\$_POST['is_training']) && !trainingMissionsAvailable()"),
            'the gate must run before the mission is written'
        );
    }

    public function test_the_checkbox_is_still_create_only_and_hidden_when_unavailable(): void
    {
        $s = $this->src('mission-form.php');
        $checkbox = strpos($s, 'id="is_training"');
        $this->assertNotFalse($checkbox);
        // Create-only: the chain is  if (!$isEdit && unavailable) note / elseif (!$isEdit) checkbox / else locked note.
        $noteOpen = strrpos(substr($s, 0, $checkbox), '<?php if (!$isEdit && !trainingMissionsAvailable()): ?>');
        $this->assertNotFalse($noteOpen, 'the unavailable note must be a create-only branch');
        $checkboxBranch = strrpos(substr($s, 0, $checkbox), '<?php elseif (!$isEdit): ?>');
        $this->assertNotFalse($checkboxBranch, 'the checkbox must sit inside a create-only (!$isEdit) branch');
        $this->assertGreaterThan($noteOpen, $checkboxBranch);
        $elseEdit = strpos($s, '<?php else: ?>', $checkbox);
        $this->assertGreaterThan($checkbox, $elseEdit);

        $noteBranch = substr($s, $noteOpen, $checkboxBranch - $noteOpen);
        $this->assertStringContainsString(self::UNAVAILABLE_MESSAGE, $noteBranch);
        $this->assertStringNotContainsString('name="is_training"', $noteBranch, 'no checkbox when drills are unavailable');
        // Nothing between the note branch and the checkbox opens another branch.
        $this->assertStringNotContainsString('<?php else', substr($s, $checkboxBranch + 10, $checkbox - $checkboxBranch - 10));
        // The locked note for an existing mission is unchanged.
        $this->assertStringContainsString('Αυτή είναι εκπαιδευτική άσκηση.', substr($s, $elseEdit, 600));
    }

    public function test_the_flag_helper_and_its_call_are_unchanged(): void
    {
        $s = $this->src('mission-form.php');
        $this->assertStringContainsString("'is_training' => trainingMissionFlagForSave(\$mission, \$_POST),", $s);

        // An existing mission keeps its flag, edit ignores the POST; a new one reads the checkbox.
        $this->assertSame(1, trainingMissionFlagForSave(['is_training' => 1], []));
        $this->assertSame(0, trainingMissionFlagForSave(['is_training' => 0], ['is_training' => '1']));
        $this->assertSame(1, trainingMissionFlagForSave(null, ['is_training' => '1']));
        $this->assertSame(0, trainingMissionFlagForSave(null, []));
    }

    public function test_the_admin_banner_is_one_include_used_by_the_dashboard_and_the_mission_form(): void
    {
        $banner = $this->src('includes/training-gate-banner.php');
        $this->assertStringContainsString('migration_last_error', $banner);
        $this->assertStringContainsString("getSetting('migration_last_error', '')", $banner);
        $this->assertStringContainsString('trainingMissionsAvailable()', $banner);
        $this->assertStringContainsString('alert alert-warning', $banner);
        $this->assertStringContainsString('trg_pr_set_training', $banner);
        $this->assertStringContainsString('mb_substr(', $banner);
        $this->assertMatchesRegularExpression('/h\(\$[a-zA-Z]+\)/', $banner, 'the error text must be escaped with h()');

        foreach (['dashboard.php', 'mission-form.php'] as $page) {
            $p = $this->src($page);
            $this->assertStringContainsString("include __DIR__ . '/includes/training-gate-banner.php';", $p, $page);
            // Admins only: the include sits behind isAdmin().
            $at = strpos($p, "includes/training-gate-banner.php");
            $this->assertStringContainsString('isAdmin()', substr($p, max(0, $at - 200), 200), "{$page}: banner must be admin-only");
        }
    }

    public function test_the_banner_prints_nothing_when_everything_is_fine(): void
    {
        if (!trainingMissionsAvailable() || trim((string) getSetting('migration_last_error', '')) !== '') {
            $this->markTestSkipped('the test database has a recorded migration error');
        }
        ob_start();
        include __DIR__ . '/../includes/training-gate-banner.php';
        $this->assertSame('', trim((string) ob_get_clean()));
    }
}
