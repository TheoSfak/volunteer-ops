<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The unread-messages counter in the console's counter bar (v3.412.0). Which room it
 * opens is pure and tested in tests/js/war-room-console.test.js (wrcChatTarget); this
 * pins the wiring and the texts.
 */
final class ConsoleChatCounterTest extends TestCase
{
    public function testTheCounterIsFedByTheSameCountAsTheDockTab(): void
    {
        $js = (string) file_get_contents(__DIR__ . '/../assets/js/war-room-console.js');
        $this->assertStringContainsString("setCount('wrcChatCnt', chat.total);\n", str_replace("\r\n", "\n", $js));
        $this->assertStringContainsString('lastChat = chat;', $js, 'the bar must show what the dock tab shows');
        $this->assertStringContainsString("if (b.dataset.kpi === 'chat') openUnreadChat();", $js);
        $this->assertStringContainsString('wrcChatTarget(lastChat.byRoom', $js);
    }

    public function testEveryTextOfTheCounterExistsInBothLanguages(): void
    {
        $strings = require __DIR__ . '/../includes/lang/war-room.php';
        foreach (['el', 'en'] as $lang) {
            foreach (['console.kpi_chat', 'console.kpi_chat_on', 'console.kpi_chat_none'] as $key) {
                $this->assertArrayHasKey($key, $strings[$lang], "'$lang' has no $key");
                $this->assertNotSame('', $strings[$lang][$key]);
            }
            $this->assertStringContainsString('{n}', $strings[$lang]['console.kpi_chat_on']);
        }
    }
}
