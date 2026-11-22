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
