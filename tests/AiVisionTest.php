<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The receipt scanner's provider chain (v3.510.0). The user's rule: the free AIs from Settings first.
 * It walks aiFailoverOrder() (free tiers rank 10, Grok 50, DeepSeek 90) and NOT the admin's primary choice,
 * keeping only providers that have a key and can read an image.
 */
final class AiVisionTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap.php';
        require_once __DIR__ . '/../includes/ai-vision.php';
    }

    public function test_every_provider_declares_whether_it_reads_images(): void
    {
        foreach (aiProviders() as $key => $p) {
            $this->assertArrayHasKey('vision_model', $p, $key);
        }
        $this->assertFalse(aiProviders()['deepseek']['vision_model']);
        $this->assertSame(['gemini', 'groq', 'grok'], aiVisionCapableProviders());
    }

    public function test_free_tiers_come_first_whatever_order_keys_were_stored_in(): void
    {
        $this->assertSame(['gemini', 'groq', 'grok'],
            aiVisionBuildChain(aiFailoverOrder(), ['grok', 'deepseek', 'groq', 'gemini'], aiVisionCapableProviders()));
    }

    public function test_unkeyed_and_image_blind_providers_are_left_out(): void
    {
        $this->assertSame(['gemini', 'grok'],
            aiVisionBuildChain(['gemini', 'groq', 'grok', 'deepseek'], ['deepseek', 'grok', 'gemini'], ['gemini', 'groq', 'grok']));
        $this->assertSame([], aiVisionBuildChain(['gemini', 'deepseek'], ['deepseek'], ['gemini']));
    }

    public function test_the_vision_model_replaces_the_chat_model_only_when_one_is_declared(): void
    {
        $this->assertSame(aiProviderConfig('gemini')['model'], aiVisionProviderConfig('gemini')['model']);
        $groq = aiVisionProviderConfig('groq');
        $this->assertNotSame('', $groq['model']);
        $this->assertSame(aiProviders()['groq']['vision_model'], $groq['model']);
    }
}
