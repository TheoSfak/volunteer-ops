<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The library page and the editor, read from their sources (they are scripts, not functions): the system admin gate, the
 * CSRF check on every POST, escaping of every printed value, the size caps in front of every raw JSON entry point, and the
 * wiring to the library functions. Case text is untrusted: an unterminated fragment such as '<a href="' survives the tag
 * stripper, so nothing may be printed raw.
 */
final class TrainingCasePagesSourceTest extends TestCase
{
    private const ROOT = __DIR__ . '/..';

    private function src(string $file): string
    {
        return str_replace("\r\n", "\n", (string) file_get_contents(self::ROOT . '/' . $file));
    }

    /** @return array<string, string> */
    private function pages(): array
    {
        return ['training-cases.php' => $this->src('training-cases.php'), 'training-case-edit.php' => $this->src('training-case-edit.php')];
    }

    private function lang(): array
    {
        return loadLangStrings('war-room');
    }

    public function test_both_pages_are_for_the_system_admin_only_and_check_it_before_anything_renders(): void
    {
        foreach ($this->pages() as $name => $src) {
            $this->assertStringContainsString('requireLogin();', $src, $name);
            $gate = strpos($src, '!isSystemAdmin()');
            $this->assertNotFalse($gate, "$name checks isSystemAdmin()");
            $this->assertLessThan(strpos($src, "include __DIR__ . '/includes/header.php'"), $gate, "$name: the gate is before the header");
            $this->assertLessThan(strpos($src, 'if (isPost()) {'), $gate, "$name: the gate is before any POST handling");
                        $between = substr($src, $gate, 200);
            $this->assertStringContainsString("redirect('dashboard.php')", $between, "$name sends a non admin away");
            $this->assertStringNotContainsString('hasPagePermission', $src, "$name is not delegable to a page permission");
        }
    }

    public function test_every_post_checks_the_csrf_token_before_it_does_anything(): void
    {
        foreach ($this->pages() as $name => $src) {
            $post = strpos($src, "
if (isPost()) {");
            $csrf = strpos($src, 'verifyCsrf();');
            $this->assertNotFalse($post, $name);
            $this->assertNotFalse($csrf, $name);
            $this->assertGreaterThan($post, $csrf);
            $this->assertSame($csrf, strpos(substr($src, $post), 'verifyCsrf();') + $post, "$name: verifyCsrf() is the first thing in the POST block");
            foreach (['trainingCaseSave(', 'trainingCaseDuplicate(', 'trainingCaseArchive(', 'trainingCaseSetReviewed(', 'trainingCaseImport(', 'trainingCaseExport(', 'trainingCaseImportRead('] as $call) {
                // Every call outside the docblock and the page's own helper functions is inside the POST block.
                $at = strpos($src, $call, $post);
                if ($at !== false) {
                    $this->assertGreaterThan($csrf, $at, "$name: $call runs after the CSRF check");
                }
            }
        }
        $library = $this->pages()['training-cases.php'];
        // Every form in the library carries the token (the row buttons come from one helper that adds it).
        $this->assertSame(substr_count($library, '<form'), substr_count($library, 'csrfField()'), 'every form prints csrfField()');
        $this->assertSame(substr_count($this->pages()['training-case-edit.php'], '<form'), substr_count($this->pages()['training-case-edit.php'], 'csrfField()'));
        $this->assertStringContainsString("'<form method=\"post\" class=\"d-inline\">' . csrfField()", $library);
        $this->assertStringContainsString('<?= csrfField() ?>', $this->pages()['training-case-edit.php']);
    }

    public function test_the_library_is_wired_to_the_library_functions(): void
    {
        $src = $this->pages()['training-cases.php'];
        foreach (['trainingCaseSeedStarters(', 'trainingCaseList(', 'trainingCaseExport(', 'trainingCaseImportRead(', 'trainingCaseImport(',
                  'trainingCaseDuplicate(', 'trainingCaseArchive(', 'trainingCaseSetReviewed('] as $call) {
            $this->assertStringContainsString($call, $src, $call);
        }
        $this->assertLessThan(strpos($src, 'trainingCaseList('), strpos($src, 'trainingCaseSeedStarters('), 'the starters are seeded before the list is read');
        foreach (['import_preview', 'import_confirm'] as $action) {
            $this->assertStringContainsString("'$action'", $src, $action);
        }
    }

    public function test_the_editor_posts_through_the_save_and_shows_its_errors_in_plain_language(): void
    {
        $src = $this->pages()['training-case-edit.php'];
        $this->assertStringContainsString('trainingCaseSave(', $src);
        $this->assertStringContainsString('trainingCaseErrorText(', $src);
        $this->assertStringContainsString('trainingCaseEditorModel(', $src);
        $this->assertStringContainsString("trainingCaseLoad(\$id)", $src);
    }

    public function test_the_editor_cuts_the_posted_document_to_a_size_limit_before_decoding_it(): void
    {
        $src = $this->pages()['training-case-edit.php'];
        $cap = strpos($src, 'strlen($raw) > TRAINING_CASE_MAX_EDITOR_BYTES');
        $decode = strpos($src, '$decoded = json_decode(');
        $save = strpos($src, '$result = trainingCaseSave(');
        $this->assertNotFalse($cap, 'the cap exists');
        $this->assertLessThan($decode, $cap, 'the cap is before json_decode');
        $this->assertLessThan($save, $cap, 'the cap is before the normalising save');
        $this->assertLessThan(strpos($src, '$model = trainingCaseEditorModel('), $cap);
        $this->assertLessThanOrEqual(100000, TRAINING_CASE_MAX_EDITOR_BYTES, 'a sane limit');
        $this->assertGreaterThan(TRAINING_CASE_MAX_DOC_BYTES, TRAINING_CASE_MAX_EDITOR_BYTES, 'room for any case the library accepts');
    }

    public function test_the_import_is_cut_to_the_import_limit_before_anything_reads_the_file(): void
    {
        $src = $this->pages()['training-cases.php'];
        $sizeCheck = strpos($src, "(int) (\$file['size'] ?? 0) > TRAINING_CASE_MAX_IMPORT_BYTES");
        $this->assertNotFalse($sizeCheck, 'the uploaded size is checked');
        $this->assertLessThan(strpos($src, 'file_get_contents('), $sizeCheck, 'the size check is before the file is read');
        $this->assertStringContainsString('file_get_contents($tmp, false, null, 0, TRAINING_CASE_MAX_IMPORT_BYTES + 1)', $src, 'the read itself is bounded');
        $this->assertLessThan(strpos($src, 'trainingCaseImportRead('), $sizeCheck);
        $this->assertStringContainsString('is_uploaded_file(', $src);
        // The confirm step takes the file's text back from a form field: capped too.
        $confirm = strpos($src, "case 'import_confirm'");
        $this->assertNotFalse($confirm);
        $cap = strpos($src, 'strlen($json) > TRAINING_CASE_MAX_IMPORT_BYTES', $confirm);
        $this->assertNotFalse($cap, 'the confirm step is capped');
        $this->assertLessThan(strpos($src, 'trainingCaseImport($json', $confirm), $cap);
    }

    public function test_every_printed_value_is_escaped(): void
    {
        foreach ($this->pages() as $name => $src) {
            preg_match_all('/<\?=\s*(.*?)\s*\?>/s', $src, $m);
            $this->assertNotEmpty($m[1], $name);
            foreach ($m[1] as $expr) {
                $ok = str_starts_with($expr, 'h(') || str_starts_with($expr, '(int)') || $expr === 'csrfField()' || $expr === 'showFlash()'
                    || str_starts_with($expr, 'trainingCaseActionForm(') || str_starts_with($expr, "rtrim(BASE_URL, '/')") || str_starts_with($expr, 'APP_VERSION');
                $this->assertTrue($ok, "$name prints an unescaped expression: <?= $expr ?>");
            }
            $this->assertDoesNotMatchRegularExpression('/\becho\s+\$(?!json;)|\bprint\s+\$|\bprint_r\(|\bvar_dump\(/', $src, "$name echoes a raw variable (only the JSON download may)");
            // The one helper that builds HTML in the library escapes what it prints itself.
            $this->assertStringNotContainsString('innerHTML', $src, "$name does not build markup from case text in the browser");
        }
        $helper = $this->pages()['training-cases.php'];
        $start = strpos($helper, 'function trainingCaseActionForm(');
        $body = substr($helper, $start, strpos($helper, "\n}\n", $start) - $start);
        foreach (['$action', '$label', '$icon', '$buttonClass'] as $var) {
            $this->assertStringContainsString("h($var)", $body, "$var is escaped in the action form");
        }
        $this->assertStringContainsString('(int) $id', $body);
    }

    public function test_the_editor_hands_the_model_to_the_script_through_an_escaped_attribute(): void
    {
        $src = $this->pages()['training-case-edit.php'];
        $this->assertStringContainsString('data-model="<?= h((string) json_encode($model, $jsonFlags)) ?>"', $src);
        $this->assertStringContainsString('JSON_INVALID_UTF8_SUBSTITUTE', $src);
    }

    public function test_starters_are_not_edited_in_place_and_editing_says_what_it_resets(): void
    {
        $edit = $this->pages()['training-case-edit.php'];
        $this->assertMatchesRegularExpression("/is_starter'\\]\s*===\s*1\)\s*\{\s*setFlash\('error',\s*t\('case.err.starter_locked'\)\);\s*redirect\('training-cases.php'\);/", $edit);
        $this->assertStringContainsString("case.ed.reviewed_reset", $edit);
        $library = $this->pages()['training-cases.php'];
        $this->assertMatchesRegularExpression('/if \(\$isStarter\):.*?else:.*?training-case-edit\.php\?id=/s', $library, 'the Edit button is for non starters only');
        $this->assertStringContainsString("trainingCaseActionForm('duplicate'", $library, 'every case, a starter included, can be duplicated');
    }

    public function test_marking_a_case_reviewed_needs_an_explicit_confirmation_the_server_checks(): void
    {
        $src = $this->pages()['training-cases.php'];
        $case = strpos($src, "case 'review':");
        $this->assertNotFalse($case);
        $check = strpos($src, "post('confirm_review') !== '1'", $case);
        $this->assertNotFalse($check, 'the review action refuses a post without the ticked confirmation');
        $this->assertLessThan(strpos($src, 'trainingCaseSetReviewed($id, true', $case), $check);
        $this->assertStringContainsString('name="confirm_review" value="1"', $src);
        $this->assertStringContainsString("t('case.lib.review_check')", $src);
        foreach (['el', 'en'] as $lang) {
            $text = $this->lang()[$lang]['case.lib.review_check'] . ' ' . $this->lang()[$lang]['case.lib.review_text'];
            $this->assertMatchesRegularExpression($lang === 'el' ? '/ιατρικά καταρτισμένο άτομο/u' : '/medically qualified person/', $text, "$lang says who must have checked");
        }
        $this->assertStringContainsString("'unreview'", $src, 'a mark can be taken back without a confirmation');
    }

    public function test_the_export_downloads_one_json_file(): void
    {
        $src = $this->pages()['training-cases.php'];
        $this->assertStringContainsString("header('Content-Type: application/json; charset=utf-8');", $src);
        $this->assertStringContainsString('Content-Disposition: attachment; filename="training-cases.json"', $src);
        $this->assertStringContainsString("header('X-Content-Type-Options: nosniff');", $src);
        $this->assertLessThan(strpos($src, "include __DIR__ . '/includes/header.php'"), strpos($src, 'Content-Disposition'), 'sent before any page output');
    }

    public function test_the_pages_never_read_participation_attendance_or_points(): void
    {
        foreach (['training-cases.php', 'training-case-edit.php', 'assets/js/training-case-editor.js'] as $file) {
            $this->assertDoesNotMatchRegularExpression('/participation_requests|actual_hours|attended|points_awarded/', $this->src($file), $file);
        }
    }

    public function test_the_nav_link_is_for_system_admins_only(): void
    {
        $header = $this->src('includes/header.php');
        $link = strpos($header, 'href="training-cases.php"');
        $this->assertNotFalse($link);
        $open = strrpos(substr($header, 0, $link), '<?php if (');
        $this->assertSame('<?php if (isSystemAdmin()): ?>', substr($header, $open, strlen('<?php if (isSystemAdmin()): ?>')), 'the link sits in a block for system admins only');
        $this->assertStringNotContainsString('<?php endif; ?>', substr($header, $open, $link - $open), 'the link is inside that block');
        $this->assertStringContainsString("'training-case-edit'", $header, 'the editor keeps the entry highlighted');
    }

    public function test_every_label_the_pages_and_the_scripts_use_exists_in_both_languages(): void
    {
        $lang = $this->lang();
        $keys = [];
        foreach ($this->pages() as $src) {
            preg_match_all("/'(case\\.(?:lib|ed|type|lang|err|rating|label)\\.[a-z0-9_.]+)'/", $src, $m);
            $keys = array_merge($keys, $m[1]);
        }
        foreach (TRAINING_CASE_TYPES as $tag) {
            $keys[] = 'case.type.' . $tag;
        }
        foreach ([1, 2, 3] as $d) {
            $keys[] = 'case.ed.difficulty_' . $d;
        }
        foreach (array_unique($keys) as $key) {
            if (str_ends_with($key, '_') || str_ends_with($key, '.')) {
                continue;   // the start of a built key (case.ed.difficulty_ . $d): the full keys are added above
            }
            foreach (['el', 'en'] as $l) {
                $this->assertArrayHasKey($key, $lang[$l], "$key is missing in $l");
            }
        }
        // The editor script's own labels come from the page's list (case.ed.<name>).
        $page = $this->pages()['training-case-edit.php'];
        $this->assertSame(1, preg_match('/\$edLabelNames = \[(.*?)\];/s', $page, $list));
        preg_match_all("/'([a-z_]+)'/", $list[1], $names);
        $js = $this->src('assets/js/training-case-editor.js');
        preg_match_all("/\\bL\\('([a-z_]+)'\\)/", $js, $used);
        foreach (array_unique($used[1]) as $name) {
            $this->assertContains($name, $names[1], "the script asks for the label '$name' but the page does not send it");
        }
        foreach (['best', 'acceptable', 'harmful'] as $rating) {
            $this->assertContains('rating_' . $rating, $names[1]);
        }
        foreach ($names[1] as $name) {
            foreach (['el', 'en'] as $l) {
                $this->assertArrayHasKey('case.ed.' . $name, $lang[$l], "case.ed.$name is missing in $l");
            }
        }
    }

    public function test_the_try_out_gets_every_word_the_player_uses(): void
    {
        $page = $this->pages()['training-case-edit.php'];
        $this->assertSame(1, preg_match('/\$playerKeys = \[(.*?)\];/s', $page, $list));
        preg_match_all("/'(case\\.[a-z_.]+)'/", $list[1], $sent);
        $player = $this->src('assets/js/training-case.js');
        $start = strpos($player, 'function open(opts)');
        $end = strpos($player, "// ── Command's side");
        $body = substr($player, $start, $end - $start);
        preg_match_all("/\\bt\\('(case\\.[a-z_.]+)'\\)/", $body, $static);
        foreach (array_unique($static[1]) as $key) {
            $this->assertContains($key, $sent[1], "the player asks for $key and the try-out would show the raw key");
        }
        foreach (['case.rating.best', 'case.rating.acceptable', 'case.rating.harmful', 'case.label.excellent', 'case.label.good', 'case.label.practice'] as $key) {
            $this->assertContains($key, $sent[1], 'the dynamic ' . $key);
        }
        foreach ($sent[1] as $key) {
            foreach (['el', 'en'] as $l) {
                $this->assertArrayHasKey($key, $this->lang()[$l], "$key is missing in $l");
            }
        }
    }

    public function test_the_editor_script_is_a_plain_form_with_a_try_out_through_the_players_preview_mode(): void
    {
        $js = $this->src('assets/js/training-case-editor.js');
        $this->assertStringContainsString('preview: true', $js);
        $this->assertStringContainsString('VoTrainingCase', $js);
        $this->assertStringNotContainsString('innerHTML', $js, 'case text is set with textContent/value only');
        $this->assertStringNotContainsString('draggable', $js, 'no drag and drop');
        $player = $this->src('assets/js/training-case.js');
        $this->assertStringContainsString('previewOptions(', $player);
    }
}
