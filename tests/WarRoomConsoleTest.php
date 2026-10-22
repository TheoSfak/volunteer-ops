<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * The admin «Κονσόλα» view (war-room.php + assets/js/war-room-console.js).
 *
 * What is pinned, and why:
 *  - every translation key the console names exists in both languages, and the
 *    two languages carry the same set: a missing key prints the raw key on the
 *    coordinator's screen in the middle of an operation;
 *  - the console is command staff's own choice and nobody else's: a cookie asking
 *    for it from anyone else is ignored, and the view-mode form only accepts the
 *    three views it knows;
 *  - the console's scripts and styles are loaded for the console view only;
 *  - redirect() answers a console order with the outcome as JSON (tested in a
 *    fresh process, because redirect() ends the request), and keeps sending a
 *    Location header for everyone else.
 */
final class WarRoomConsoleTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = (string) realpath(__DIR__ . '/..');
    }

    private function source(string $relative): string
    {
        $text = file_get_contents($this->root . '/' . $relative);
        $this->assertNotFalse($text, $relative);
        return str_replace("\r\n", "\n", (string) $text);
    }

    public function testEveryConsoleKeyIsTranslatedInBothLanguages(): void
    {
        $strings = require $this->root . '/includes/lang/war-room.php';

        $used = [];
        foreach (['assets/js/war-room-console.js', 'war-room.php'] as $file) {
            preg_match_all("/'(console\\.[a-z0-9_]+)'/", $this->source($file), $m);
            $used = array_merge($used, $m[1]);
        }
        $used = array_values(array_unique($used));
        $this->assertNotEmpty($used);

        foreach (['el', 'en'] as $lang) {
            foreach ($used as $key) {
                $this->assertArrayHasKey($key, $strings[$lang], "$key is missing in $lang");
                $this->assertNotSame('', trim((string) $strings[$lang][$key]), "$key is empty in $lang");
            }
        }

        $own = fn(string $lang): array => array_values(array_filter(array_keys($strings[$lang]), fn($k) => str_starts_with($k, 'console.')));
        $el = $own('el');
        $en = $own('en');
        sort($el);
        sort($en);
        $this->assertSame($el, $en, 'the two languages carry different console keys');
        // ...and nothing is defined that nothing uses (a rename leaves these behind).
        $this->assertSame([], array_values(array_diff($el, $used)), 'console keys that no code names');
    }

    public function testPlaceholdersMatchBetweenLanguages(): void
    {
        $strings = require $this->root . '/includes/lang/war-room.php';
        foreach ($strings['el'] as $key => $el) {
            if (!str_starts_with($key, 'console.')) {
                continue;
            }
            preg_match_all('/\{[a-z_]+\}/', $el, $a);
            preg_match_all('/\{[a-z_]+\}/', $strings['en'][$key], $b);
            sort($a[0]);
            sort($b[0]);
            $this->assertSame($a[0], $b[0], "$key uses different placeholders in el and en");
        }
    }

    public function testTheConsoleIsCommandStaffsOwnChoice(): void
    {
        $page = $this->source('war-room.php');

        // A cookie asking for the console is dropped for anyone who cannot manage the room.
        $this->assertStringContainsString("if (\$viewModeCookie === 'console' && !\$canManageWarRoom) {", $page);
        $this->assertStringContainsString("\$consoleView = \$viewModeCookie === 'console';", $page);

        // The form that sets the cookie is command-only and takes only the three views.
        $handler = substr($page, (int) strpos($page, "post('action') === 'toggle_view_mode'"), 1800);
        $this->assertStringContainsString('if (!$canManageWarRoom) {', $handler);
        $this->assertStringContainsString("in_array(\$askedMode, ['console', 'full', 'tabs'], true)", $handler);
    }

    public function testTheConsoleAssetsLoadForTheConsoleViewOnly(): void
    {
        $page = $this->source('war-room.php');
        foreach (['war-room-console.js?v=', 'war-room-console.css?v='] as $asset) {
            $this->assertSame(1, substr_count($page, $asset), "$asset should be named once");
            $at = (int) strpos($page, $asset);
            $before = substr($page, max(0, $at - 700), 700);
            $this->assertMatchesRegularExpression('/if \(\$consoleView\): \?>(?!.*<\?php endif; \?>)/s', $before, "$asset is not inside an if (\$consoleView) block");
        }
        // And the zone view is what is left when the console is not on.
        $this->assertStringContainsString('$zoneView = $canManageWarRoom && !$volunteerTabs && !$consoleView;', $page);
    }

    public function testRedirectAnswersAConsoleOrderWithJson(): void
    {
        $success = $this->runRedirect("setFlash('success', 'Στάλθηκε εντολή σε 2 ενεργούς εθελοντές.');");
        $decoded = json_decode($this->jsonLine($success), true);
        $this->assertIsArray($decoded, $success);
        $this->assertTrue($decoded['ok']);
        $this->assertSame('Στάλθηκε εντολή σε 2 ενεργούς εθελοντές.', $decoded['flash']['success']);

        // A warning means nothing went out: not ok, so the caller does not say "sent".
        $warning = json_decode($this->jsonLine($this->runRedirect("setFlash('warning', 'Επιλέξτε τουλάχιστον έναν εθελοντή με ενεργή βάρδια.');")), true);
        $this->assertFalse($warning['ok']);
        $this->assertArrayHasKey('warning', $warning['flash']);

        $error = json_decode($this->jsonLine($this->runRedirect("setFlash('error', 'Δεν επιτρέπεται.');")), true);
        $this->assertFalse($error['ok']);

        // The flash is consumed, not left in the session for the next page load.
        $out = $this->runRedirect("setFlash('success', 'x');");
        $this->assertStringContainsString('@@session=[]', $out);
    }

    public function testRedirectStillSendsALocationHeaderOtherwise(): void
    {
        $core = $this->source('includes/functions-core.php');
        $this->assertStringContainsString('header("Location: $url");', $core);
        // The JSON branch is behind a flag that only war-room.php sets, and only for console_ajax=1.
        $this->assertSame(1, substr_count($core, "WR_JSON_REDIRECT"));
        $page = $this->source('war-room.php');
        $this->assertMatchesRegularExpression('/if \(isPost\(\) && post\(\'console_ajax\'\) === \'1\'\) \{\s+\$GLOBALS\[\'WR_JSON_REDIRECT\'\] = true;/', $page);
    }

    public function testAnAreaIsDrawnFirstAndTheSectorsInsideIt(): void
    {
        $page = $this->source('war-room.php');
        $js = $this->source('assets/js/war-room-console.js');

        // The seed the console fills and the search-area window consumes, declared once at the top level.
        $this->assertSame(1, substr_count($page, 'let pendingAreaSeed = null;'));
        $this->assertStringContainsString('pendingAreaSeed = {', $js);
        $this->assertStringContainsString('areaId: area.id, areaLabel: area.label', $js);
        $this->assertStringContainsString("getOrCreateInstance(document.getElementById('searchAreaMapModal')).show()", $js);

        // Without a seed the window saves an AREA, as it always did; with one it saves a SECTOR of that area,
        // with the team when there is one. The old way (an area plus a whole-area sector) is gone.
        $this->assertSame(1, substr_count($page, "activeSeed = pendingAreaSeed;"));
        $this->assertStringContainsString("action: 'create', mission_id: <?= \$missionId ?>, area_id: seed.areaId, label: label, geo: geo, team_id: seed.teamId || ''", $page);
        $this->assertStringContainsString("action: 'create_area', mission_id: <?= \$missionId ?>, geo: geo, label: label", $page);
        $this->assertStringNotContainsString("action: 'delete_area', mission_id: <?= \$missionId ?>, id: result.id", $page);
        // The area's outline is drawn dashed on the map, and the view closes on it.
        $this->assertStringContainsString('seedAreaLayer = L.polygon(seedArea.geo', $page);
        $this->assertStringContainsString('composerMap.fitBounds(seedAreaLayer.getBounds()', $page);
        // A sector mostly outside its area is asked about, not refused.
        $this->assertStringContainsString("t('console.sector_outside_confirm'", $page);
        // The console is told what was made, to say it and to carry on from there.
        $this->assertStringContainsString("new CustomEvent('wr-area-created'", $page);
        $this->assertStringContainsString("new CustomEvent('wr-sector-sent'", $page);
        $this->assertStringContainsString("document.addEventListener('wr-area-created'", $js);
        $this->assertStringContainsString("document.addEventListener('wr-sector-sent'", $js);

        // The sector tab: pick an area, make a new one, cut it up (only while it has no sectors), draw in it.
        foreach (['wrcAreaPick', 'wrcGoNewArea', 'wrcGoDivide', 'wrcGoDrawSector', 'wrcGoSector'] as $id) {
            $this->assertStringContainsString($id, $js);
        }
        $this->assertStringContainsString('openDivideSectorsForArea(area.id)', $js);
        $this->assertStringContainsString('inArea.length === 0 && !Number(area.sector_count)', $js);

        // The server actions it rides on exist and take what is sent.
        $endpoint = $this->source('mission-sector.php');
        foreach (["\$action === 'create_area'", "\$action === 'create'"] as $needle) {
            $this->assertStringContainsString($needle, $endpoint);
        }
    }

    public function testThePollDoesNotRedrawAComposerThatHasNotChanged(): void
    {
        $js = $this->source('assets/js/war-room-console.js');
        // refresh() redraws the composer on every poll. Replacing it unchanged reset the chosen sector
        // to the first one and dropped the cursor out of a half-typed order, so the redraw goes through
        // one function that leaves an unchanged body alone.
        $this->assertStringContainsString('function putComposerBody(body, html)', $js);
        $this->assertStringContainsString('if (sig === bodyDone) return;', $js);
        $start = strpos($js, 'function renderComposerBody(keepInputs)');
        $end = strpos($js, "cmp.addEventListener('change'", $start);
        $fn = substr($js, $start, $end - $start);
        $this->assertStringNotContainsString('body.innerHTML', $fn, 'every branch must build `html` and let putComposerBody place it');
        // What is typed goes in as a property afterwards: markup that carried it would differ on every key.
        $this->assertStringNotContainsString('value="${esc(bodyDraft', $fn);
        $this->assertStringContainsString('taskBox.value = bodyDraft.task', $fn);
    }

    public function testAnAreaIsNeverCalledASectorOnItsSaveButton(): void
    {
        $page = $this->source('war-room.php');
        $lang = $this->source('includes/lang/war-room.php');
        // «Αποθήκευση Τομέα» sat on the buttons that save an AREA (and a restricted area), so the
        // area and the sector read as the same thing. Each window now has its own text.
        $this->assertStringNotContainsString("t('sector.save_btn')", $page);
        $this->assertMatchesRegularExpression('/id="areaSendBtn"[^>]*>.*?t\(\'sector\.area_save_btn\'\)/', $page);
        $this->assertMatchesRegularExpression('/id="restrictedAreaSendBtn"[^>]*>.*?t\(\'restricted_area\.save_btn\'\)/', $page);
        $this->assertStringContainsString("'sector.area_save_btn' => 'Αποθήκευση Περιοχής'", $lang);
        $this->assertStringNotContainsString("'sector.save_btn'", $lang);
        $this->assertSame(2, substr_count($lang, "'sector.area_save_btn' =>"));
        $this->assertSame(2, substr_count($lang, "'restricted_area.save_btn' =>"));
    }

    public function testEveryNewConsoleStringExistsInBothLanguages(): void
    {
        $lang = $this->source('includes/lang/war-room.php');
        $js = $this->source('assets/js/war-room-console.js');
        $page = $this->source('war-room.php');
        foreach (['area_l', 'area_option', 'area_new_btn', 'area_divide_btn', 'area_none', 'area_made',
                  'sector_none', 'sector_draw_btn', 'sector_draw_title', 'sector_draw_title_team', 'sector_draw_send',
                  'sector_draw_save', 'sector_draw_hint', 'sector_draw_help', 'sector_label_placeholder',
                  'sector_outside_confirm', 'sector_made', 'sector_help', 'sector_needs_team', 'sector_sent'] as $key) {
            $this->assertSame(2, substr_count($lang, "'console.$key' =>"), "console.$key must be in el and en");
            $this->assertTrue(
                str_contains($js, "console.$key") || str_contains($page, "console.$key"),
                "console.$key is defined but nothing uses it"
            );
        }
    }

    /** The JSON redirect() printed, without the probe line the helper adds after it. */
    private function jsonLine(string $output): string
    {
        return trim(explode('@@session=', $output)[0]);
    }

    /**
     * Runs redirect() with the console's flag set in a process of its own,
     * because redirect() ends the request. $before runs first (to set a flash);
     * what is left in the session afterwards is printed after the answer.
     */
    private function runRedirect(string $before): string
    {
        $script = <<<'PHP'
define('VOLUNTEEROPS', true);
define('DEBUG_MODE', true);
require_once $argv[1] . '/config.php';
require_once $argv[1] . '/includes/functions-core.php';
$_SESSION = [];
$GLOBALS['WR_JSON_REDIRECT'] = true;
register_shutdown_function(function () { echo "
@@session=" . json_encode($_SESSION['flash'] ?? []); });
PHP;
        $script .= "
" . $before . "
redirect('war-room.php?id=1');
";
        $file = tempnam(sys_get_temp_dir(), 'vo_redir_');
        file_put_contents($file, "<?php
" . $script);
        try {
            $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file) . ' ' . escapeshellarg($this->root) . ' 2>&1';
            return trim((string) shell_exec($cmd));
        } finally {
            @unlink($file);
        }
    }
}
