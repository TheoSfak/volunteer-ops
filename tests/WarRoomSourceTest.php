<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The tests that pin the war-room page's code read it through WarRoomSource (tests/WarRoomSource.php),
 * so they keep guarding the same code when pieces of its script move into files of their own.
 * That only works if nobody goes around the helper, and a test that goes around it still passes -
 * it just stops guarding anything. These two checks are what make going around it fail.
 */
final class WarRoomSourceTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__);
    }

    public function testFullStartsWithThePageItself(): void
    {
        $page = (string) file_get_contents(self::root() . '/war-room.php');
        $this->assertNotSame('', $page);
        // Whatever is later moved out of the page is appended after it, never put in front of it.
        $this->assertSame($page, substr(WarRoomSource::full(), 0, strlen($page)));
    }

    public function testReadMeansFullForThePageAndTheFileItselfForAnythingElse(): void
    {
        $this->assertSame(WarRoomSource::full(), WarRoomSource::read('war-room.php'));
        $this->assertSame(file_get_contents(self::root() . '/config.php'), WarRoomSource::read('config.php'));
        $this->assertSame(
            file_get_contents(self::root() . '/includes/lang/war-room.php'),
            WarRoomSource::read('includes/lang/war-room.php'),
            'the translation table shares the page\'s name and is a different file'
        );
        $this->assertFalse(@WarRoomSource::read('no-such-file.php'), 'same answer as file_get_contents() for a missing file');
    }

    public function testNoPhpTestOpensThePageDirectly(): void
    {
        $offenders = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            if (substr($path, -4) !== '.php' || in_array(basename($path), ['WarRoomSource.php', 'WarRoomSourceTest.php'], true)) {
                continue;
            }
            foreach (preg_split('/\r?\n/', (string) file_get_contents($path)) as $index => $line) {
                if (preg_match('~^\s*(//|\*|/\*)~', $line)) {
                    continue;
                }
                if (preg_match('~\b(?:file_get_contents|file|fopen|readfile)\s*\([^;]*(?<!lang/)war-room\.php~', $line)) {
                    $offenders[] = substr($path, strlen(__DIR__) + 1) . ':' . ($index + 1);
                }
            }
        }
        $this->assertSame([], $offenders, 'read war-room.php through WarRoomSource::full() / WarRoomSource::read(), not directly');
    }

    public function testNoNodeTestOpensThePageDirectly(): void
    {
        $offenders = [];
        foreach (glob(__DIR__ . '/js/*.js') ?: [] as $path) {
            if (basename($path) === 'war-room-source.js') {
                continue;
            }
            foreach (preg_split('/\r?\n/', (string) file_get_contents($path)) as $index => $line) {
                if (preg_match('~^\s*(//|\*|/\*)~', $line)) {
                    continue;
                }
                if (preg_match('~\breadFileSync\s*\([^;]*(?<!lang/)war-room\.php~', $line)) {
                    $offenders[] = 'js/' . basename($path) . ':' . ($index + 1);
                }
            }
        }
        $this->assertSame([], $offenders, 'require(\'./war-room-source\').full() instead of reading war-room.php');
    }
}
