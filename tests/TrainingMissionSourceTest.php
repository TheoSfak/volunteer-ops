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
        $this->assertStringNotContainsString('pr.is_training', $e); // the volunteer export's participation count is unfiltered (drills keep their real headcount)
    }

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

        // The Action Room's text goes through t(): the banner is a lang key, defined once per language.
        $w = $this->src('war-room.php');
        $this->assertStringContainsString("t('hero.training_badge')", $w);
        $this->assertStringContainsString("!empty(\$mission['is_training'])", $w);
        $this->assertStringNotContainsString('ΑΣΚΗΣΗ · Εκπαιδευτική άσκηση', $w, 'the banner text must not be hard-coded');
        $lang = $this->src('includes/lang/war-room.php');
        $this->assertSame(2, substr_count($lang, "'hero.training_badge'"));
        $strings = require __DIR__ . '/../includes/lang/war-room.php';
        $this->assertSame('ΑΣΚΗΣΗ · Εκπαιδευτική άσκηση - δεν επηρεάζει τα στατιστικά', $strings['el']['hero.training_badge']);
        $this->assertSame('EXERCISE · Training exercise - does not affect statistics', $strings['en']['hero.training_badge']);
    }

    public function test_dashboard_shows_the_badge_where_a_volunteer_can_see_a_drill(): void
    {
        $d = $this->src('dashboard.php');

        // Volunteer-side lists only: "my overdue missions", "my upcoming shifts", "available missions".
        // The admin lists (recent/overdue missions, pending requests) are already filtered by $departmentFilter.
        // Plus the three shift-swap rows (awaiting admin, incoming, outgoing).
        $this->assertSame(6, substr_count($d, 'trainingMissionBadgeHtml('));
        $this->assertStringContainsString('trainingMissionBadgeHtml($om)', $d);
        $this->assertStringContainsString('trainingMissionBadgeHtml($shift)', $d);
        $this->assertStringContainsString('trainingMissionBadgeHtml($mission)', $d);

        // The swap rows read the flag from these queries: every one that selects the mission title
        // must also select m.is_training, or a drill would show unbadged.
        $swap = $this->src('includes/shift-swap-functions.php');
        $this->assertGreaterThanOrEqual(
            substr_count($swap, 'm.title as mission_title'),
            substr_count($swap, 'm.is_training')
        );

        // Queries that name their columns must select the flag the badge reads.
        $this->assertStringContainsString('SELECT m.id, m.title, m.status, m.end_datetime, m.is_training,', $d);
        $this->assertStringContainsString('SELECT s.*, m.title as mission_title, m.is_training, m.location,', $d);
    }

    public function test_missions_csv_export_carries_and_honours_the_training_filter(): void
    {
        $l = $this->src('missions.php');
        $this->assertStringContainsString('&mission_type=<?= h($missionType) ?>&training=<?= h($trainingFilter) ?>', $l);

        $x = $this->src('exports/export-missions.php');
        $this->assertStringContainsString("'training'      => get('training')", $x);

        $e = $this->src('includes/export-functions.php');
        $this->assertStringContainsString("\$where[] = 'm.is_training = 1';", $e);
        $this->assertStringContainsString("\$where[] = 'm.is_training = 0';", $e);
        $this->assertStringContainsString("(string) (\$filters['training'] ?? '')", $e);
        // Static SQL only: the filter value never reaches the query text.
        $this->assertStringNotContainsString("is_training = ' .", $e);
    }

    public function test_shift_view_manual_attendance_flash_is_accurate_for_training_rows(): void
    {
        $s = $this->src('shift-view.php');
        $this->assertStringContainsString('Η παρουσία καταγράφηκε (εκπαιδευτική άσκηση, χωρίς πόντους).', $s);
        $this->assertStringContainsString("!empty(\$pr['is_training']) ? 'Η παρουσία καταγράφηκε (εκπαιδευτική άσκηση, χωρίς πόντους).'", $s);
        $this->assertStringContainsString('Η παρουσία καταγράφηκε και δόθηκαν πόντοι.', $s);
    }

    public function test_participant_notifications_carry_the_exercise_prefix(): void
    {
        $e = $this->src('includes/email.php');
        $this->assertStringContainsString("trainingTitlePrefix(\$mission) . 'Νέα Αποστολή: '", $e);
        $this->assertStringContainsString("'mission_title'       => trainingTitlePrefix(\$mission) . \$mission['title']", $e);

        $c = $this->src('cron_shift_reminders.php');
        $this->assertStringContainsString('m.is_training', $c);
        $this->assertStringContainsString('$missionLabel = trainingTitlePrefix(', $c);
        $this->assertStringContainsString("'mission_title' => \$missionLabel", $c);

        $p = $this->src('participation-action.php');
        $this->assertStringContainsString('m.is_training', $p);
        $this->assertStringContainsString('$missionLabel = trainingTitlePrefix(', $p);
        $this->assertStringContainsString("'mission_title' => \$missionLabel", $p);
    }

    public function test_shift_view_notifications_carry_the_exercise_prefix(): void
    {
        $s = $this->src('shift-view.php');

        // The shift row carries the flag and the label is built once.
        $this->assertMatchesRegularExpression('/SELECT s\.\*, m\.title as mission_title,[^"]*m\.is_training/', $s);
        $this->assertStringContainsString('$missionLabel = trainingTitlePrefix($shift) . $shift[\'mission_title\'];', $s);

        // approve, reject, reactivate, add_volunteer, mass_add: every email variable uses the label.
        $this->assertSame(0, preg_match_all("/'mission_title'\s*=>\s*\\\$shift\['mission_title'\]/", $s));
        $this->assertGreaterThanOrEqual(5, preg_match_all("/'mission_title'\s*=>\s*\\\$missionLabel/", $s));

        // The in-app messages and the calendar title too.
        foreach ([
            '\'Η αίτησή σας για τη βάρδια "\' . $missionLabel',
            '\'Η αίτηση συμμετοχής σας στη βάρδια "\' . $missionLabel',
            '\'Η συμμετοχή σας στη βάρδια "\' . $missionLabel',
            '\'Η βάρδια στην αποστολή "\' . $missionLabel',
            '\'Ο διαχειριστής σας τοποθέτησε στη βάρδια: \' . $missionLabel',
            'buildGcalLink($missionLabel,',
        ] as $needle) {
            $this->assertStringContainsString($needle, $s, "shift-view.php must use the label in: {$needle}");
        }
        $this->assertSame(0, preg_match_all('/Η (?:αίτησή|αίτηση συμμετοχής|συμμετοχή) σας[^;\n]*\$shift\[\'mission_title\'\]/u', $s));
        $this->assertSame(0, preg_match_all('/buildGcalLink\(\$shift\[\'mission_title\'\]/', $s));
    }

    public function test_mission_view_notifications_carry_the_exercise_prefix(): void
    {
        $s = $this->src('mission-view.php');

        // $mission is m.*, so it carries is_training already.
        $this->assertStringContainsString('$missionLabel = trainingTitlePrefix($mission) . $mission[\'title\'];', $s);
        // reminder, cancel and delete: subject lines, bodies and the mission_canceled / mission_reminder emails.
        $this->assertSame(0, preg_match_all("/'mission_title'\s*=>\s*\\\$mission\['title'\]/", $s));
        $this->assertGreaterThanOrEqual(3, preg_match_all("/'mission_title'\s*=>\s*\\\$missionLabel/", $s));
        $this->assertStringContainsString("'Ακύρωση Αποστολής: ' . \$missionLabel", $s);
        $this->assertStringContainsString("'Διαγραφή Αποστολής: ' . \$missionLabel", $s);
        $this->assertStringContainsString("'Υπενθύμιση Αποστολής: ' . \$missionLabel", $s);

        // admin_added_volunteer reloads its own shift row: it must select the flag and prefix.
        $this->assertMatchesRegularExpression('/SELECT s\.\*, m\.title as mission_title, m\.location, m\.is_training/', $s);
        $this->assertStringContainsString('$shiftLabel = trainingTitlePrefix($shift) . $shift[\'mission_title\'];', $s);
        $this->assertSame(0, preg_match_all("/'mission_title'\s*=>\s*\\\$shift\['mission_title'\]/", $s));
        $this->assertStringContainsString("'Ο διαχειριστής σας τοποθέτησε στη βάρδια: ' . \$shiftLabel", $s);
    }

    public function test_shift_swap_notifications_carry_the_exercise_prefix(): void
    {
        $s = $this->src('includes/shift-swap-functions.php');

        $this->assertGreaterThanOrEqual(2, preg_match_all('/\$missionLabel = trainingTitlePrefix\(\$swap\) \. \$swap\[\'mission_title\'\];/', $s));
        $this->assertSame(0, preg_match_all("/'mission_title'\s*=>\s*\\\$swap\['mission_title'\]/", $s));
        $this->assertSame(0, preg_match_all("/\. \\\$swap\['mission_title'\] \./", $s));
        $this->assertGreaterThanOrEqual(3, preg_match_all("/\\\$missionLabel/", $s));

        $r = $this->src('shift-swap.php');
        $this->assertStringContainsString('m.is_training', $r);
        $this->assertStringContainsString('$missionLabel = trainingTitlePrefix($participation) . $participation[\'mission_title\'];', $r);
        $this->assertStringContainsString("'mission_title'  => \$missionLabel", $r);
    }

    public function test_the_needs_volunteers_cron_never_broadcasts_for_a_drill(): void
    {
        $s = $this->src('cron_incomplete_missions.php');
        $this->assertMatchesRegularExpression('/FROM shifts s\s+INNER JOIN missions m.*?WHERE s\.start_time.*?m\.is_training = 0.*?GROUP BY s\.id/s', $s);
    }

    public function test_certificate_links_in_the_training_record_follow_who_may_open_them(): void
    {
        // mission-certificate-print.php admits the recipient, missions_manage users and the mission's responsible user.
        $v = $this->src('volunteer-view.php');
        $this->assertStringContainsString('$trainingRecordCanViewCertificate = hasPagePermission(\'missions_manage\');', $v);
        $this->assertStringNotContainsString('$trainingRecordCanViewCertificate = true;', $v);

        // profile.php shows the volunteer their own certificates.
        $this->assertStringContainsString('$trainingRecordCanViewCertificate = true;', $this->src('profile.php'));
    }

    public function test_missions_pagination_keeps_the_training_and_type_filters(): void
    {
        // paginationLinks() ignores its second argument and rebuilds every link from $_GET, so page 2
        // keeps training and mission_type; this pins the function the missions list depends on.
        $old = $_GET;
        $_GET = ['status' => 'OPEN', 'training' => '1', 'mission_type' => '3', 'page' => '1'];
        try {
            $html = paginationLinks(['total_pages' => 3, 'current_page' => 1, 'has_prev' => false, 'has_next' => true]);
        } finally {
            $_GET = $old;
        }
        $this->assertStringContainsString('training=1', $html);
        $this->assertStringContainsString('mission_type=3', $html);
        $this->assertStringContainsString('page=2', $html);
    }

    public function test_training_badge_shrinks_to_its_column_in_the_console_header(): void
    {
        // In the console header the title column is only 300px (170px on a laptop); the badge is one long
        // line and used to run under the buttons beside it. It must shrink to its column and end in an
        // ellipsis, with the full text in a tooltip.
        $w = $this->src('war-room.php');
        $this->assertStringContainsString('class="badge bg-warning text-dark mb-2 wr-training-badge"', $w);
        $this->assertStringContainsString("title=\"<?= h(t('hero.training_badge')) ?>\"", $w);

        $css = $this->src('assets/css/war-room-console.css');
        $this->assertSame(1, preg_match('/\.wr-training-badge\s*\{([^}]*)\}/s', $css, $m), 'rule for .wr-training-badge missing');
        foreach (['max-width: 100%', 'overflow: hidden', 'text-overflow: ellipsis', 'white-space: nowrap'] as $declaration) {
            $this->assertStringContainsString($declaration, $m[1]);
        }
    }
}
