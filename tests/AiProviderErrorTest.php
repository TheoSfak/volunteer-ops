<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * aiExtractProviderError() (includes/ai.php).
 *
 * Written after a real failure: a Gemini key on yphresies.gr produced the
 * message "Ο πάροχος απάντησε με σφάλμα: HTTP 404" and nothing else, because
 * Google's OpenAI-compatibility layer wraps its error object in a TOP-LEVEL
 * ARRAY and the extractor only looked at $decoded['error']['message']. The one
 * sentence that said what was actually wrong was thrown away.
 *
 * The lesson these tests hold in place: a diagnostic path is a feature, and a
 * provider's own wording is worth more than any message this app can invent.
 */
final class AiProviderErrorTest extends TestCase
{
    /** The shape that caused the bug — confirmed against the live endpoint. */
    public function testReadsGeminiStyleArrayWrappedError(): void
    {
        $body = '[{"error":{"code":400,"message":"Please pass a valid API key","status":"INVALID_ARGUMENT"}}]';
        $this->assertSame(
            'Please pass a valid API key',
            aiExtractProviderError(json_decode($body, true), $body)
        );
    }

    public function testReadsTheConventionalObjectShape(): void
    {
        $body = '{"error":{"message":"Model not found","type":"invalid_request_error"}}';
        $this->assertSame('Model not found', aiExtractProviderError(json_decode($body, true), $body));
    }

    public function testReadsATopLevelMessage(): void
    {
        $body = '{"message":"Authentication Fails"}';
        $this->assertSame('Authentication Fails', aiExtractProviderError(json_decode($body, true), $body));
    }

    /** Some gateways return error as a plain string rather than an object. */
    public function testReadsAStringError(): void
    {
        $body = '{"error":"upstream timeout"}';
        $this->assertSame('upstream timeout', aiExtractProviderError(json_decode($body, true), $body));
    }

    /**
     * An HTML error page from a proxy is still worth more than a status code,
     * so it falls through to a whitespace-collapsed slice of the body rather
     * than being discarded.
     */
    public function testFallsBackToTheRawBodyWhenNothingParses(): void
    {
        $body = "<html>\n  <body>502 Bad Gateway</body>\n</html>";
        $out  = aiExtractProviderError(null, $body);
        $this->assertStringContainsString('502 Bad Gateway', $out);
        $this->assertStringNotContainsString("\n", $out, 'Multi-line bodies must be collapsed for a one-line alert');
    }

    public function testNeverReturnsAnEmptyStringForAnEmptyBody(): void
    {
        $this->assertNotSame('', aiExtractProviderError(null, ''));
        $this->assertNotSame('', aiExtractProviderError([], '   '));
    }

    public function testTruncatesAVeryLongBody(): void
    {
        $out = aiExtractProviderError(null, str_repeat('x', 5000));
        $this->assertLessThanOrEqual(300, mb_strlen($out, 'UTF-8'));
    }
}
