<?php
/**
 * The one place that knows what "the source of the war-room page" is.
 *
 * Dozens of test classes pin the page's code by reading the text of war-room.php
 * (a regex over its script, a strpos between two functions, a "this call is
 * guarded" check). While the page's script is a single inline block, that text
 * is simply the file. When pieces of that script are later moved into files of
 * their own (the agreed plan for shrinking the page), the tests must keep
 * guarding the very same code. So they all read through here:
 *
 *   full()  = the page followed by every file its script was moved to, in the
 *             order the browser runs them. Today: the page itself, byte for byte.
 *   read()  = any repo file by its relative path, except that 'war-room.php'
 *             means full(). Same return type as file_get_contents().
 *
 * A test that opens war-room.php directly would silently stop guarding the code
 * that moved out, and still pass. Do not do that; use this class.
 *
 * Global namespace on purpose: the test classes are a mix of `namespace Tests;`
 * ones (which write \WarRoomSource) and plain global ones.
 */
final class WarRoomSource
{
    /** The repository root. */
    public static function root(): string
    {
        return dirname(__DIR__);
    }

    /** The page and everything its script lives in, as one text. */
    public static function full(): string
    {
        $text = file_get_contents(self::root() . '/war-room.php');
        if ($text === false) {
            throw new RuntimeException('war-room.php is missing');
        }
        return $text;
    }

    /**
     * A repository file by relative path ('includes/functions-warroom.php').
     * 'war-room.php' stands for full(). Anything else is the file as it is.
     *
     * @return string|false like file_get_contents()
     */
    public static function read(string $relative)
    {
        if ($relative === 'war-room.php') {
            return self::full();
        }
        return file_get_contents(self::root() . '/' . $relative);
    }
}
