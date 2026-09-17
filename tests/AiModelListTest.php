<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * aiChatModelsFromList() (includes/ai.php).
 *
 * The fixture below is the REAL catalogue a Gemini key returned on
 * yphresies.gr in September 2026, kept verbatim. It is the whole reason this
 * function exists: the first version of the settings diagnostic sorted these
 * alphabetically and showed the first 25, which put the oldest generation on
 * top and pushed gemini-3.6-flash — the model the provider's own retirement
 * notice told the admin to switch to — past the end of the list. The
 * diagnostic named the problem and then hid the answer.
 */
final class AiModelListTest extends TestCase
{
    /** Verbatim from a live key, plus the newer tail that was being cut off. */
    private const REAL_CATALOGUE = [
        'antigravity-preview-05-2026',
        'antigravity-preview-09-2026',
        'deep-research-max-preview-04-2026',
        'deep-research-preview-04-2026',
        'deep-research-pro-preview-12-2025',
        'gemini-2.5-computer-use-preview-10-2025',
        'gemini-2.5-flash',
        'gemini-2.5-flash-image',
        'gemini-2.5-flash-lite',
        'gemini-2.5-flash-native-audio-latest',
        'gemini-2.5-flash-native-audio-preview-09-2025',
        'gemini-2.5-pro',
        'gemini-3-flash-preview',
        'gemini-3-pro-image',
        'gemini-3.1-flash-image',
        'gemini-3.1-flash-lite',
        'gemini-3.1-flash-lite-image',
        'gemini-3.1-flash-live-preview',
        'gemini-3.1-pro-preview',
        'gemini-3.1-pro-preview-customtools',
        'gemini-3.5-flash',
        'gemini-3.6-flash',
        'text-embedding-004',
    ];

    public function testTheRecommendedReplacementIsNotBuriedPastTheDisplayCap(): void
    {
        $out = aiChatModelsFromList(self::REAL_CATALOGUE);
        $position = array_search('gemini-3.6-flash', $out, true);

        $this->assertNotFalse($position, 'The model the provider recommends must survive filtering');
        $this->assertLessThan(5, $position, 'It must be near the top, not past a 30-item cap');
    }

    public function testNewestGenerationSortsFirst(): void
    {
        $out = aiChatModelsFromList(self::REAL_CATALOGUE);
        // Natural order, not string order: plain sorting puts 3.1 above 3.6.
        $this->assertSame('gemini-3.6-flash', $out[0]);
        $this->assertSame('gemini-3.5-flash', $out[1]);
        $this->assertLessThan(
            array_search('gemini-2.5-pro', $out, true),
            array_search('gemini-3.1-pro-preview', $out, true)
        );
    }

    public function testDropsEverythingThatCannotWriteAReport(): void
    {
        $out = aiChatModelsFromList(self::REAL_CATALOGUE);
        foreach ([
            'text-embedding-004',                            // embeddings
            'gemini-2.5-flash-image',                        // image generation
            'gemini-2.5-flash-native-audio-latest',          // audio
            'gemini-3.1-flash-live-preview',                 // realtime Live API
            'gemini-2.5-computer-use-preview-10-2025',       // computer use
            'deep-research-preview-04-2026',                 // research agent
            'antigravity-preview-09-2026',                   // not a chat model
            'gemini-3.1-pro-preview-customtools',            // tool-specific variant
        ] as $unusable) {
            $this->assertNotContains($unusable, $out, "{$unusable} should not be offered as a report writer");
        }
    }

    public function testKeepsTheOrdinaryChatModels(): void
    {
        $out = aiChatModelsFromList(self::REAL_CATALOGUE);
        foreach (['gemini-3.6-flash', 'gemini-3.5-flash', 'gemini-3.1-pro-preview', 'gemini-2.5-pro', 'gemini-2.5-flash'] as $keep) {
            $this->assertContains($keep, $out);
        }
    }

    public function testDeepSeekCatalogueSurvivesUntouched(): void
    {
        $out = aiChatModelsFromList(['deepseek-flash', 'deepseek-v4-pro']);
        $this->assertSame(['deepseek-v4-pro', 'deepseek-flash'], $out);
    }

    /**
     * An unknown provider whose every id trips the filter must still produce a
     * usable list — showing something the admin can paste beats showing
     * nothing at all.
     */
    public function testFallsBackToTheUnfilteredListRatherThanReturningNothing(): void
    {
        $out = aiChatModelsFromList(['some-image-model', 'another-audio-model']);
        $this->assertCount(2, $out);
    }

    public function testEmptyAndMalformedInputAreSafe(): void
    {
        $this->assertSame([], aiChatModelsFromList([]));
        $this->assertSame(['ok-model'], aiChatModelsFromList(['ok-model', '', null, 42]));
    }
}
