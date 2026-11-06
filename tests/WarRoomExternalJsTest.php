<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

// The page requires this itself (war-room.php); tests/bootstrap.php does not load it, and the loader's own
// process test below proves that the loader needs nothing more than the translation machinery.
require_once __DIR__ . '/../includes/war-room-bundles.php';

/**
 * The Action Room page's translation tables as a file of their own (v3.449.0, step R2 of the plan for moving the
 * page's script out of war-room.php): war-room-js.php sends them, the phone keeps them for a year, and the page only
 * says where they are. Off by default (setting war_room_external_js).
 *
 * Pinned here: how the file is built (the text is the page's own, down to the JSON flags), its version, every answer
 * the loader can give (compressed or plain, 304, a version that is not the current one, 404, 405, HEAD), the disk
 * cache and its tidying, the rule that decides whether a request uses the file, the canary cookie, the shape of the
 * page's wiring and of the setting, and that the loader needs nothing but the translation machinery (a fresh process).
 * That the page renders to the same script with the file as without it is checked on rendered pages (token streams) and
 * in a real browser, which no unit test here can do.
 */
final class WarRoomExternalJsTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/wr_bundle_test_' . bin2hex(random_bytes(4));
        unset($_GET['rawjs'], $_GET['wrext'], $_COOKIE['wr_ext_js']);
    }

    protected function tearDown(): void
    {
        unset($_GET['rawjs'], $_GET['wrext'], $_COOKIE['wr_ext_js']);
        if (is_dir($this->dir)) {
            foreach (glob($this->dir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->dir);
        }
    }

    private const TABLES = ['el' => ['a' => 'α/β', 'b' => 'δ'], 'en' => ['a' => 'a/b', 'b' => 'd']];

    private function page(): string
    {
        return str_replace("\r\n", "\n", WarRoomSource::full());
    }

    /** @return array{status:int, headers:array<string,string>, body:string} */
    private function ask(array $query, array $server = [], ?string $dir = null): array
    {
        return wrBundleRespond($query, $server + ['REQUEST_METHOD' => 'GET'], $dir ?? $this->dir);
    }

    private function current(string $lang = 'el'): string
    {
        return wrBundleVersion('strings', $lang);
    }

    // ── what the file says ─────────────────────────────────────────────────

    public function testTheDefaultLanguageSaysItsTableOnceAndTheFallbackIsTheSameTable(): void
    {
        $this->assertSame(
            "const WR_STRINGS = {\"a\":\"α/β\",\"b\":\"δ\"};\nconst WR_STRINGS_FALLBACK = WR_STRINGS;\n",
            wrBundleSource('strings', 'el', self::TABLES)
        );
    }

    public function testAnotherLanguageCarriesItsOwnTableAndTheDefaultLanguagesAsTheFallback(): void
    {
        $this->assertSame(
            "const WR_STRINGS = {\"a\":\"a/b\",\"b\":\"d\"};\nconst WR_STRINGS_FALLBACK = {\"a\":\"α/β\",\"b\":\"δ\"};\n",
            wrBundleSource('strings', 'en', self::TABLES)
        );
    }

    public function testTheTextIsEncodedWithTheFlagsThePageUses(): void
    {
        // not α and not \/ : the page prints these tables with exactly these two flags, and 190 KB of escapes is bigger
        $js = (string) wrBundleSource('strings', 'el', self::TABLES);
        $this->assertStringContainsString('"α/β"', $js);
        $this->assertStringNotContainsString('\\u', $js);
        $this->assertStringNotContainsString('\\/', $js);
        $this->assertStringContainsString(
            'json_encode($__wrStrings[$__viewerLang] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)',
            $this->page(),
            'the page still prints the table with the flags the file is built with'
        );
    }

    public function testTheRealTablesComeOutTheSameAsTheOnesThePagePrints(): void
    {
        $all = loadLangStrings('war-room');
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        $this->assertSame(
            'const WR_STRINGS = ' . json_encode($all['el'], $flags) . ";\nconst WR_STRINGS_FALLBACK = WR_STRINGS;\n",
            wrBundleSource('strings', 'el')
        );
        $this->assertSame(
            'const WR_STRINGS = ' . json_encode($all['en'], $flags) . ";\nconst WR_STRINGS_FALLBACK = " . json_encode($all['el'], $flags) . ";\n",
            wrBundleSource('strings', 'en')
        );
    }

    public function testThereIsNoSuchPieceOrLanguageToBuild(): void
    {
        $this->assertNull(wrBundleSource('nope', 'el', self::TABLES));
        $this->assertNull(wrBundleSource('strings', 'fr', self::TABLES));
        $this->assertNull(wrBundleSource('strings', '../el', self::TABLES));
        $this->assertFalse(wrBundleExists('core'));
        $this->assertTrue(wrBundleExists('strings'));
    }

    public function testAnUnencodableTableIsNoFileRatherThanABrokenOne(): void
    {
        $this->assertNull(wrBundleSource('strings', 'el', ['el' => ['a' => "\xB1\x31"], 'en' => []]));
        $this->assertNull(wrBundleSource('strings', 'en', ['el' => ['a' => "\xB1\x31"], 'en' => ['a' => 'ok']]));
    }

    // ── its version and address ────────────────────────────────────────────

    public function testTheVersionIsSixteenHexCharactersAndDependsOnLanguageAndPiece(): void
    {
        $el = wrBundleVersion('strings', 'el');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $el);
        $this->assertSame($el, wrBundleVersion('strings', 'el'));
        $this->assertNotSame($el, wrBundleVersion('strings', 'en'));
        $this->assertNotSame($el, wrBundleVersion('other', 'el'));
    }

    public function testTheVersionFollowsTheTranslationFileAndTheCodeThatBuildsFromIt(): void
    {
        @mkdir($this->dir);
        $lang = $this->dir . '/lang.php';
        $code = $this->dir . '/code.php';
        file_put_contents($lang, '<?php return 1;');
        file_put_contents($code, '<?php // a');
        $one = wrBundleVersion('strings', 'el', $lang, $code);
        file_put_contents($lang, '<?php return 2;');
        $two = wrBundleVersion('strings', 'el', $lang, $code);
        file_put_contents($code, '<?php // b');
        $three = wrBundleVersion('strings', 'el', $lang, $code);
        $this->assertCount(3, array_unique([$one, $two, $three]), 'a changed translation and a changed builder are both a new address');
        @unlink($lang);
        @unlink($code);
    }

    public function testAFileThatCannotBeReadMeansNoVersionAndSoNoFile(): void
    {
        $this->assertSame('', wrBundleVersion('strings', 'el', $this->dir . '/missing.php'));
        $this->assertSame('', wrBundleVersion('strings', 'el', null, $this->dir . '/missing.php'));
    }

    public function testTheAddressCarriesPieceLanguageAndVersion(): void
    {
        $url = wrBundleUrl('https://x.test/app', 'strings', 'en');
        $this->assertSame('https://x.test/app/war-room-js.php?b=strings&l=en&v=' . $this->current('en'), $url);
        $this->assertSame('', wrBundleUrl('https://x.test', 'strings', 'fr'), 'a language that has no table keeps the page as it is');
        $this->assertSame('', wrBundleUrl('https://x.test', 'core', 'el'));
    }

    // ── the small header rules ─────────────────────────────────────────────

    #[DataProvider('acceptEncodingCases')]
    public function testGzipIsOfferedOnlyByAGzipTokenThatIsNotZero(string $header, bool $expected): void
    {
        $this->assertSame($expected, wrBundleAcceptsGzip($header));
    }

    public static function acceptEncodingCases(): array
    {
        return [
            'empty' => ['', false],
            'identity only' => ['identity', false],
            'gzip' => ['gzip', true],
            'a browser list' => ['gzip, deflate, br', true],
            'upper case' => ['GZIP', true],
            'with q' => ['deflate, gzip;q=0.5', true],
            'q zero refuses' => ['gzip;q=0, deflate', false],
            'q zero with spaces' => ['gzip ; q=0.0', false],
            'only deflate and br' => ['deflate, br', false],
            'x-gzip' => ['x-gzip', true],
            'a different token that contains the word' => ['notgzip', false],
        ];
    }

    public function testAnEtagIsMatchedInEitherFormOneOfSeveralOrStar(): void
    {
        $this->assertTrue(wrBundleEtagMatches('"a"', '"a"'));
        $this->assertTrue(wrBundleEtagMatches('W/"a"', '"a"'));
        $this->assertTrue(wrBundleEtagMatches('"x", W/"a", "y"', '"a"'));
        $this->assertTrue(wrBundleEtagMatches('*', '"a"'));
        $this->assertFalse(wrBundleEtagMatches('"b"', '"a"'));
        $this->assertFalse(wrBundleEtagMatches('', '"a"'));
        $this->assertFalse(wrBundleEtagMatches('"a-gz"', '"a"'), 'the plain and the compressed copy are different representations');
    }

    // ── the answers ────────────────────────────────────────────────────────

    public function testACurrentVersionAskedByAGzipClientGetsTheCompressedFileWithAYearOfCache(): void
    {
        $v = $this->current();
        $r = $this->ask(['b' => 'strings', 'l' => 'el', 'v' => $v], ['HTTP_ACCEPT_ENCODING' => 'gzip, deflate']);
        $this->assertSame(200, $r['status']);
        $this->assertSame('gzip', $r['headers']['Content-Encoding']);
        $this->assertSame('public, max-age=31536000, immutable', $r['headers']['Cache-Control']);
        $this->assertSame('"wr-strings-el-' . $v . '-gz"', $r['headers']['ETag']);
        $this->assertSame('Accept-Encoding', $r['headers']['Vary']);
        $this->assertSame('nosniff', $r['headers']['X-Content-Type-Options']);
        $this->assertSame('application/javascript; charset=utf-8', $r['headers']['Content-Type']);
        $this->assertSame((string) strlen($r['body']), $r['headers']['Content-Length']);
        $this->assertSame(wrBundleSource('strings', 'el'), gzdecode($r['body']));
    }

    public function testAClientWithoutGzipGetsThePlainTextAndItsOwnEtag(): void
    {
        $v = $this->current('en');
        $r = $this->ask(['b' => 'strings', 'l' => 'en', 'v' => $v]);
        $this->assertSame(200, $r['status']);
        $this->assertArrayNotHasKey('Content-Encoding', $r['headers']);
        $this->assertSame('"wr-strings-en-' . $v . '"', $r['headers']['ETag']);
        $this->assertSame('public, max-age=31536000, immutable', $r['headers']['Cache-Control']);
        $this->assertSame(wrBundleSource('strings', 'en'), $r['body']);
        $this->assertSame((string) strlen($r['body']), $r['headers']['Content-Length']);
        $this->assertDirectoryDoesNotExist($this->dir, 'the plain copy is not kept on disk: only the compressed one is');
    }

    public function testAnEtagTheClientAlreadyHoldsIsAnsweredWith304AndNoBody(): void
    {
        $v = $this->current();
        $etag = '"wr-strings-el-' . $v . '-gz"';
        foreach ([$etag, 'W/' . $etag] as $held) {
            $r = $this->ask(['b' => 'strings', 'l' => 'el', 'v' => $v], ['HTTP_ACCEPT_ENCODING' => 'gzip', 'HTTP_IF_NONE_MATCH' => $held]);
            $this->assertSame(304, $r['status']);
            $this->assertSame('', $r['body']);
            $this->assertSame($etag, $r['headers']['ETag']);
            $this->assertSame('public, max-age=31536000, immutable', $r['headers']['Cache-Control']);
        }
        $this->assertDirectoryDoesNotExist($this->dir, 'a 304 builds nothing');
    }

    public function testAnEtagFromTheOtherRepresentationIsNotAHit(): void
    {
        $v = $this->current();
        $r = $this->ask(['b' => 'strings', 'l' => 'el', 'v' => $v], ['HTTP_ACCEPT_ENCODING' => 'gzip', 'HTTP_IF_NONE_MATCH' => '"wr-strings-el-' . $v . '"']);
        $this->assertSame(200, $r['status']);
    }

    public function testAVersionThatIsNotTheCurrentOneGetsTheCurrentTextAndIsToldNotToKeepIt(): void
    {
        foreach (['0000000000000000', '', 'x'] as $stale) {
            $r = $this->ask(['b' => 'strings', 'l' => 'el', 'v' => $stale], ['HTTP_ACCEPT_ENCODING' => 'gzip']);
            $this->assertSame(200, $r['status']);
            $this->assertSame('no-store', $r['headers']['Cache-Control']);
            $this->assertArrayNotHasKey('ETag', $r['headers']);
            $this->assertSame(wrBundleSource('strings', 'el'), gzdecode($r['body']));
        }
        $this->assertDirectoryDoesNotExist($this->dir, 'nothing is kept under a version that is not the current one');
        $plain = $this->ask(['b' => 'strings', 'l' => 'el', 'v' => 'old']);
        $this->assertSame('no-store', $plain['headers']['Cache-Control']);
        $this->assertSame(wrBundleSource('strings', 'el'), $plain['body']);
    }

    public function testAMatchingEtagDoesNotHelpAVersionThatIsNotCurrent(): void
    {
        $r = $this->ask(['b' => 'strings', 'l' => 'el', 'v' => 'ffffffffffffffff'], ['HTTP_ACCEPT_ENCODING' => 'gzip', 'HTTP_IF_NONE_MATCH' => '*']);
        $this->assertSame(200, $r['status'], 'a version nobody built is never "unchanged"');
    }

    public function testUnknownPiecesLanguagesAndAnArrayInsteadOfTextAreA404(): void
    {
        $v = $this->current();
        $asks = [
            ['b' => 'core', 'l' => 'el', 'v' => $v],
            ['b' => 'strings', 'l' => 'fr', 'v' => $v],
            ['b' => 'strings', 'l' => '../../config', 'v' => $v],
            ['b' => ['strings'], 'l' => 'el', 'v' => $v],
            ['b' => 'strings', 'l' => ['el'], 'v' => $v],
            [],
        ];
        foreach ($asks as $query) {
            $r = $this->ask($query);
            $this->assertSame(404, $r['status'], json_encode($query));
            $this->assertSame('no-store', $r['headers']['Cache-Control']);
        }
        $this->assertSame(200, $this->ask(['b' => 'strings', 'l' => 'el', 'v' => ['x']])['status'], 'an array for the version is just a version that is not current');
    }

    public function testOnlyGetAndHeadAreAnswered(): void
    {
        $r = wrBundleRespond(['b' => 'strings', 'l' => 'el', 'v' => $this->current()], ['REQUEST_METHOD' => 'POST'], $this->dir);
        $this->assertSame(405, $r['status']);
        $this->assertSame('GET, HEAD', $r['headers']['Allow']);
    }

    public function testHeadSendsTheHeadersOfAGetWithoutTheBody(): void
    {
        $query = ['b' => 'strings', 'l' => 'el', 'v' => $this->current()];
        $get = wrBundleRespond($query, ['REQUEST_METHOD' => 'GET', 'HTTP_ACCEPT_ENCODING' => 'gzip'], $this->dir);
        $head = wrBundleRespond($query, ['REQUEST_METHOD' => 'HEAD', 'HTTP_ACCEPT_ENCODING' => 'gzip'], $this->dir);
        $this->assertSame(200, $head['status']);
        $this->assertSame('', $head['body']);
        $this->assertSame($get['headers'], $head['headers'], 'the headers, Content-Length included, are the GET\'s');
    }

    // ── the disk cache ─────────────────────────────────────────────────────

    public function testTheCompressedTextIsKeptOnDiskAndReadBackNotBuiltAgain(): void
    {
        $v = $this->current();
        $query = ['b' => 'strings', 'l' => 'el', 'v' => $v];
        $server = ['HTTP_ACCEPT_ENCODING' => 'gzip'];
        $first = $this->ask($query, $server);
        $file = $this->dir . '/strings-el-' . $v . '.js.gz';
        $this->assertFileExists($file);
        $this->assertSame($first['body'], file_get_contents($file));

        // proof that the second answer comes from the file: put another valid gzip body under the same name
        $marker = gzencode('/* from the disk */', 9);
        file_put_contents($file, $marker);
        $second = $this->ask($query, $server);
        $this->assertSame($marker, $second['body']);
        $this->assertSame('/* from the disk */', gzdecode($second['body']));
    }

    public function testAKeptFileThatIsNotGzipIsIgnoredAndReplaced(): void
    {
        $v = $this->current();
        @mkdir($this->dir);
        $file = $this->dir . '/strings-el-' . $v . '.js.gz';
        foreach (['', 'garbage that is not gzip at all, long enough to pass a length check', "\x1f\x8b"] as $bad) {
            file_put_contents($file, $bad);
            $r = $this->ask(['b' => 'strings', 'l' => 'el', 'v' => $v], ['HTTP_ACCEPT_ENCODING' => 'gzip']);
            $this->assertSame(wrBundleSource('strings', 'el'), gzdecode($r['body']));
            $this->assertSame($r['body'], file_get_contents($file), 'the good copy replaced the bad one');
        }
    }

    public function testAFolderThatCannotBeWrittenStillGetsTheRightAnswer(): void
    {
        $blocker = $this->dir . '_file';
        file_put_contents($blocker, 'x');
        try {
            $r = $this->ask(['b' => 'strings', 'l' => 'el', 'v' => $this->current()], ['HTTP_ACCEPT_ENCODING' => 'gzip'], $blocker . '/cache');
            $this->assertSame(200, $r['status']);
            $this->assertSame(wrBundleSource('strings', 'el'), gzdecode($r['body']));
        } finally {
            @unlink($blocker);
        }
    }

    public function testOldCopiesOfOtherVersionsAndLeftoverTemporaryFilesAreTidiedAway(): void
    {
        @mkdir($this->dir);
        $now = time();
        $oldOther = $this->dir . '/strings-el-aaaaaaaaaaaaaaaa.js.gz';
        $newOther = $this->dir . '/strings-el-bbbbbbbbbbbbbbbb.js.gz';
        $otherLang = $this->dir . '/strings-en-cccccccccccccccc.js.gz';
        $oldTmp = $this->dir . '/strings-el-aaaaaaaaaaaaaaaa.js.gz.1.abc.tmp';
        $newTmp = $this->dir . '/strings-el-bbbbbbbbbbbbbbbb.js.gz.2.def.tmp';
        foreach ([$oldOther, $newOther, $otherLang, $oldTmp, $newTmp] as $f) {
            file_put_contents($f, 'x');
        }
        touch($oldOther, $now - 2 * 86400);
        touch($otherLang, $now - 5 * 86400);
        touch($oldTmp, $now - 7200);

        $this->ask(['b' => 'strings', 'l' => 'el', 'v' => $this->current()], ['HTTP_ACCEPT_ENCODING' => 'gzip']);

        $this->assertFileDoesNotExist($oldOther, 'another version of this piece, over a day old');
        $this->assertFileDoesNotExist($oldTmp, 'a temporary file over an hour old');
        $this->assertFileExists($newOther, 'another version, but recent: a page built just before an update may still ask for it');
        $this->assertFileExists($newTmp, 'a temporary file that may be being written right now');
        $this->assertFileExists($otherLang, 'another language is not this piece');
        foreach ([$otherLang, $newOther, $newTmp] as $f) {
            @unlink($f);
        }
    }

    // ── whether a request uses the file ────────────────────────────────────

    #[DataProvider('decisionCases')]
    public function testTheDecisionFollowsTheSettingTheCanaryAndTheRawSwitch(string $setting, string $cookie, bool $raw, bool $expected): void
    {
        $this->assertSame($expected, wrExternalJsDecision($setting, $cookie, $raw));
    }

    public static function decisionCases(): array
    {
        return [
            'off, nothing else' => ['0', '', false, false],
            'off, even with the canary' => ['0', '1', false, false],
            'canary mode, a browser that did not ask' => ['1', '', false, false],
            'canary mode, a browser that asked' => ['1', '1', false, true],
            'canary mode, a browser that took it back' => ['1', '0', false, false],
            'everybody' => ['2', '', false, true],
            'everybody, but this request wants the raw page' => ['2', '', true, false],
            'canary mode, raw page' => ['1', '1', true, false],
            'an unknown value is off' => ['3', '1', false, false],
            'an empty value is off' => ['', '1', false, false],
        ];
    }

    public function testTheCanaryCookieIsSetFromTheAddressAndSeenByTheSameRequest(): void
    {
        $_GET['wrext'] = '1';
        wrExternalJsApplyCanary();
        $this->assertSame('1', $_COOKIE['wr_ext_js']);
        $this->assertTrue(wrExternalJsDecision('1', (string) $_COOKIE['wr_ext_js'], false));

        $_GET['wrext'] = '0';
        wrExternalJsApplyCanary();
        $this->assertSame('0', $_COOKIE['wr_ext_js']);
        $this->assertFalse(wrExternalJsDecision('1', (string) $_COOKIE['wr_ext_js'], false));
    }

    public function testAnyOtherValueOfTheCanaryParameterChangesNothing(): void
    {
        $_COOKIE['wr_ext_js'] = '1';
        foreach (['2', '', 'yes', '1 ', ['1']] as $value) {
            $_GET['wrext'] = $value;
            wrExternalJsApplyCanary();
            $this->assertSame('1', $_COOKIE['wr_ext_js'], is_array($value) ? 'array' : "'$value'");
        }
        unset($_GET['wrext'], $_COOKIE['wr_ext_js']);
        wrExternalJsApplyCanary();
        $this->assertArrayNotHasKey('wr_ext_js', $_COOKIE);
    }

    // ── the page ───────────────────────────────────────────────────────────

    public function testThePageRequiresTheHelperAndAsksForTheCanaryBeforeItPrintsAnything(): void
    {
        $page = $this->page();
        $this->assertStringContainsString("require_once __DIR__ . '/includes/war-room-bundles.php';", $page);
        $canary = strpos($page, "\nwrExternalJsApplyCanary();\n");
        $this->assertNotFalse($canary);
        $this->assertSame(1, substr_count($page, 'wrExternalJsApplyCanary();'));
        $firstOutput = strpos($page, "include __DIR__ . '/includes/header.php';");
        $this->assertNotFalse($firstOutput);
        $this->assertLessThan($firstOutput, $canary, 'the canary sends a cookie: it must run before the header prints');
    }

    public function testThePageDecidesOnceNextToTheLanguageAndPrintsNothingWhileDoingIt(): void
    {
        $page = $this->page();
        $this->assertSame(1, substr_count($page, '$__wrExtUrl = wrExternalJsEnabled() ? wrBundleUrl('));
        $this->assertSame(1, substr_count($page, '$__wrExt = $__wrExtUrl !== \'\'; ?>'));
        $start = (int) strpos($page, '<?php $__wrStrings = loadLangStrings');
        $block = substr($page, $start, (int) strpos($page, '?>', $start) - $start);
        $this->assertStringNotContainsString('<?=', $block, 'the decision prints nothing');
        $this->assertStringContainsString("wrBundleUrl(rtrim(BASE_URL, '/'), 'strings', (string) \$__viewerLang)", $block);
    }

    public function testTheFilesTagSitsBetweenTheBootAndTheBigScriptAndIsCheckedRightAfter(): void
    {
        $page = $this->page();
        $tag = strpos($page, '<script src="<?= h($__wrExtUrl) ?>"></script>');
        $this->assertNotFalse($tag);
        $this->assertSame(1, substr_count($page, '<script src="<?= h($__wrExtUrl) ?>"></script>'));
        $bootEnd = strpos($page, "};\n</script>\n<?php if (\$__wrExt): ?>\n");
        $this->assertNotFalse($bootEnd, 'right after the boot object and before anything else');
        $bigOpen = strpos($page, "<script>\n<?php \$__wrLight = wrLightScriptEnabled();");
        $this->assertNotFalse($bigOpen);
        $this->assertTrue($bootEnd < $tag && $tag < $bigOpen);

        $check = substr($page, $tag, $bigOpen - $tag);
        $this->assertStringContainsString("if (typeof WR_STRINGS === 'undefined')", $check);
        $this->assertStringContainsString("searchParams.set('rawjs', '1')", $check, 'the way back is the page the old way');
        $this->assertStringContainsString('location.replace(', $check);
        $this->assertStringContainsString('window.WR_STRINGS = {};', $check, 'until the old page arrives the script finds a table, empty');
        $this->assertStringContainsString('window.WR_STRINGS_FALLBACK = window.WR_STRINGS;', $check);
        // No guard: the page it asks for (?rawjs=1) never carries the check, so it cannot ask again; wrExternalJsDecision() and
        // its test pin that ?rawjs=1 beats every setting. A guard that stopped a reload only left a half-working page.
        $this->assertStringNotContainsString('sessionStorage', $check);
        $this->assertStringContainsString('(function () {', $check, 'the check keeps its variable out of the global scope: a global var u would clash with a let u of the big script');
        $this->assertStringNotContainsString('var ', substr($check, 0, (int) strpos($check, '(function () {')), 'nothing declared outside that function, at the top level of the check');
        $this->assertStringNotContainsString('var ', substr($check, (int) strpos($check, '})();')), 'nor after it');
        $this->assertStringNotContainsString('<?=', substr($check, strlen('<script src="<?= h($__wrExtUrl) ?>"></script>')), 'the check is the same text for everybody');
    }

    public function testTheTablesStayInTheScriptOnlyWhenTheFileIsNotUsed(): void
    {
        $page = $this->page();
        $open = strpos($page, "<?php if (!\$__wrExt): /* with the file in use, war-room-js.php has already defined both tables */ ?>\n");
        $this->assertNotFalse($open);
        $table = strpos($page, 'const WR_STRINGS = <?= json_encode($__wrStrings[$__viewerLang]');
        $fallback = strpos($page, 'const WR_STRINGS_FALLBACK = <?= json_encode($__wrStrings[DEFAULT_LANGUAGE]');
        $close = strpos($page, "<?php endif; ?>\n<?php endif; ?>\nfunction t(key, vars = {}) {");
        $this->assertNotFalse($table);
        $this->assertNotFalse($fallback);
        $this->assertNotFalse($close, 'the guard closes right before t()');
        $this->assertTrue($open < $table && $table < $fallback && $fallback < $close);
        $this->assertSame(1, substr_count($page, 'const WR_STRINGS = '), 'the table is defined in one place in the page');
        $this->assertSame(1, substr_count($page, 'function t(key, vars = {}) {'));
    }

    public function testTheLoaderAndItsHelperNeedNoSessionNoDatabaseAndNoBootstrap(): void
    {
        foreach (['war-room-js.php', 'includes/war-room-bundles.php'] as $file) {
            $code = (string) file_get_contents(__DIR__ . '/../' . $file);
            $code = (string) preg_replace('~/\*.*?\*/~s', '', $code);          // the comments say what it does not do
            $code = (string) preg_replace('~^\s*//.*$~m', '', $code);
            foreach (['bootstrap.php', 'session_start', 'db.php', 'dbFetch', 'dbExecute', 'getDb(', 'db()', 'getSetting', 'getCurrentUser', 'config.php', 'csrf'] as $needle) {
                $this->assertStringNotContainsString($needle, $code, "$file must not use $needle");
            }
        }
    }

    public function testTheServiceWorkerLetsAPhpScriptRequestGoToTheNetworkAndTheHttpCache(): void
    {
        $sw = str_replace("\r\n", "\n", (string) file_get_contents(__DIR__ . '/../sw.js'));
        $this->assertMatchesRegularExpression(
            "~url\\.pathname\\.endsWith\\('\\.php'\\)[^\\n]*\\{\\s*if \\(request\\.mode !== 'navigate'\\) return;~",
            $sw,
            'war-room-js.php is a .php non-navigation: the worker must leave it alone, or its long cache lifetime is not the one in force'
        );
    }

    // ── the setting ────────────────────────────────────────────────────────

    public function testTheSettingIsOffByDefaultOwnedByTheActionRoomTabAndAClosedList(): void
    {
        $settings = str_replace("\r\n", "\n", (string) file_get_contents(__DIR__ . '/../settings.php'));
        $this->assertStringContainsString("'war_room_external_js' => '0',", $settings);
        $this->assertStringContainsString("'arrival_auto_enabled', 'war_room_light_script', 'war_room_external_js',", $settings);
        $this->assertStringContainsString("if (\$field === 'war_room_external_js' && !in_array(\$value, ['0', '1', '2'], true)) {\n                        \$value = '0';", $settings);
        $this->assertMatchesRegularExpression('~<select[^>]*name="war_room_external_js"~', $settings);
        foreach (['0', '1', '2'] as $v) {
            $this->assertStringContainsString('<option value="' . $v . '" <?= $extJs === \'' . $v . '\'', $settings);
        }
        $checkboxList = (string) preg_match("~in_array\\(\\\$field, \\[('achievements_enabled'[^\\]]*)\\]~", $settings, $m) ? $m[1] : '';
        $this->assertNotSame('', $checkboxList);
        $this->assertStringNotContainsString('war_room_external_js', $checkboxList, 'a three-way select is not a checkbox');
    }

    // ── the loader as a whole, in a process of its own ─────────────────────

    public function testTheLoaderRunsOnItsOwnWithOnlyTheTranslationMachinery(): void
    {
        // tests/bootstrap.php loads the whole app, so a missing require in the loader would pass here and fatal live
        $root = realpath(__DIR__ . '/..');
        $script = <<<'PHP'
<?php
$_GET = ['b' => 'strings', 'l' => $argv[2], 'v' => $argv[3]];
$_SERVER['REQUEST_METHOD'] = 'GET';
require $argv[1] . '/war-room-js.php';
PHP;
        $file = tempnam(sys_get_temp_dir(), 'vo_wrjs_');
        $log = tempnam(sys_get_temp_dir(), 'vo_wrjs_log_');
        file_put_contents($file, $script);
        try {
            foreach (['el', 'en'] as $lang) {
                $cmd = escapeshellarg(PHP_BINARY) . ' -d error_log=' . escapeshellarg($log) . ' ' . escapeshellarg($file) . ' '
                    . escapeshellarg((string) $root) . ' ' . $lang . ' ' . $this->current($lang) . ' 2>&1';
                $output = (string) shell_exec($cmd);
                $logged = (string) @file_get_contents($log);
                $this->assertSame(wrBundleSource('strings', $lang), $output, "the loader's own process, $lang: " . $logged);
                $this->assertStringNotContainsString('Fatal error', $logged);
                $this->assertStringNotContainsString('Warning', $logged);
            }
        } finally {
            @unlink($file);
            @unlink($log);
        }
    }
}
