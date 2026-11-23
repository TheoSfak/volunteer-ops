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
    public function test_each_call_gets_a_shrinking_timeout_and_the_chain_stops_when_little_time_is_left(): void
    {
        $this->assertSame(35, aiVisionCallTimeout(1075.0, 1000.0), '75 s left: capped at 35');
        $this->assertSame(35, aiVisionCallTimeout(1075.0, 1040.0), '35 s left');
        $this->assertSame(19, aiVisionCallTimeout(1075.0, 1055.5), '19.5 s left: floor');
        $this->assertSame(8, aiVisionCallTimeout(1075.0, 1067.0), 'exactly 8 s left is still allowed');
        $this->assertNull(aiVisionCallTimeout(1075.0, 1067.5), 'under 8 s left: stop trying providers');
        $this->assertNull(aiVisionCallTimeout(1075.0, 1100.0), 'past the deadline');
        $this->assertSame(75, AI_VISION_DEADLINE_SECONDS);
        // Worst case: 35 + 35 then only 5 s left, so the third provider is never started: total <= 75 s.
        $t = 0.0; $calls = 0;
        while (($to = aiVisionCallTimeout(75.0, $t)) !== null) { $t += $to; $calls++; }
        $this->assertSame(2, $calls);
        $this->assertLessThanOrEqual(75.0, $t);
    }

    public function test_the_scan_endpoint_and_the_chain_use_the_deadline_not_a_long_per_call_timeout(): void
    {
        $chat = file_get_contents(__DIR__ . '/../includes/ai-vision.php');
        $this->assertStringContainsString("aiChatOnce(\$messages, ['timeout' => \$timeout] + \$opts, \$cfg)", $chat);
        $scan = file_get_contents(__DIR__ . '/../finance-scan.php');
        $this->assertStringContainsString('set_time_limit(90)', $scan);
        $this->assertStringContainsString("'deadline' => 75", $scan);
        $this->assertStringNotContainsString("'timeout' => 90", $scan);
        $js = file_get_contents(__DIR__ . '/../assets/js/finance-form.js');
        $this->assertStringContainsString('έως ~1 λεπτό', $js);
    }
}
