<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The voice-message button must arm like the SOS button (v3.408.2).
 *
 * It used to start recording on the press itself, so a light tap or a pocket
 * brush that lasted 700 ms recorded a clip and raised the siren at command. It
 * now needs the same one-second hold as SOS before the microphone opens, and a
 * press that ends earlier does nothing but show a hint.
 *
 * The gesture lives in inline script, so these read the source: the point is
 * that nobody quietly puts `voiceStartRecording()` back on the press.
 */
final class VoiceHoldToArmTest extends TestCase
{
    private static function source(): string
    {
        return (string) file_get_contents(__DIR__ . '/../war-room.php');
    }

    private static function wiring(): string
    {
        $src = self::source();
        $start = strpos($src, '(function wireVoiceHoldButton()');
        self::assertNotFalse($start, 'wireVoiceHoldButton() is gone');
        $end = strpos($src, '// ── Command side', $start);
        self::assertNotFalse($end);
        return substr($src, $start, $end - $start);
    }

    public function testThePressOnlyArmsAndRecordingStartsWhenTheHoldIsComplete(): void
    {
        $wiring = self::wiring();
        $down = strpos($wiring, "addEventListener('pointerdown'");
        $arm = strpos($wiring, '}, VOICE_ARM_MS);');
        $record = strpos($wiring, 'voiceStartRecording()');

        $this->assertNotFalse($down);
        $this->assertNotFalse($arm, 'the press no longer waits VOICE_ARM_MS before doing anything');
        $this->assertNotFalse($record);
        $this->assertGreaterThan($down, $record);
        $this->assertLessThan($arm, $record, 'voiceStartRecording() must run only inside the arm timer, never straight from the press');
        $this->assertSame(1, substr_count($wiring, 'voiceStartRecording()'), 'recording must have exactly one way to start');
    }

    public function testLettingGoBeforeTheBarIsFullCancelsInsteadOfSending(): void
    {
        $wiring = self::wiring();
        $this->assertMatchesRegularExpression(
            "~'pointerup', 'pointerleave', 'pointercancel'.*?if \\(voiceArmTimer\\) \\{\\s*disarm\\(\\);.*?return;~s",
            $wiring,
            'a press that ends while arming must disarm and return, not fall through to voiceStopRecording()'
        );
    }

    public function testItNeedsAtLeastTheSameHoldAsSos(): void
    {
        $src = self::source();
        $this->assertSame(1, preg_match('~const HOLD_MS = (\d+);~', $src, $sos), 'the SOS hold constant moved');
        $this->assertSame(1, preg_match('~const VOICE_ARM_MS = (\d+);~', $src, $voice), 'VOICE_ARM_MS is gone');
        $this->assertGreaterThanOrEqual((int) $sos[1], (int) $voice[1], 'the voice button must not be easier to trigger than SOS');
    }

    public function testReleasingWhileTheMicrophoneOpensDoesNotLeaveARecorderRunning(): void
    {
        // The release arrives while getUserMedia is still pending (the
        // permission prompt), finds no recorder to stop, and used to leave one
        // recording until the 60 s cap with nobody holding the button.
        $src = self::source();
        $this->assertMatchesRegularExpression(
            '~getUserMedia\(.*?if \(!voiceHeld\) \{\s*voiceStream\.getTracks\(\)\.forEach\(tr => tr\.stop\(\)\);.*?return;~s',
            $src
        );
    }

    public function testTheButtonIsRenderedOnceSoTheRuleIsTheSameForEveryone(): void
    {
        $this->assertSame(1, substr_count(self::source(), 'id="voiceHoldBtn"'), 'a second voice button would need its own copy of the rule');
    }

    public function testTheNewTextsExistInGreekAndEnglish(): void
    {
        $strings = require __DIR__ . '/../includes/lang/war-room.php';
        foreach (['el', 'en'] as $lang) {
            foreach (['voice.hold_hint', 'voice.arming', 'voice.arm_hint'] as $key) {
                $this->assertArrayHasKey($key, $strings[$lang], "'$lang' has no $key");
                $this->assertNotSame('', $strings[$lang][$key]);
            }
        }
    }
}
