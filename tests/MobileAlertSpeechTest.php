<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * mobileAlertSpeech() (v3.383.0): which notifications the Android app's
 * background service reads aloud when the screen is off, and in which language.
 *
 * It must say exactly what the page says and no more. Too little leaves the
 * arrival unspoken on a locked phone; too much reads a coordinator's FYI copy
 * of somebody else's announcement out loud to the wrong person, or speaks to an
 * app that never asked.
 */
final class MobileAlertSpeechTest extends TestCase
{
    private const GREEK = 'Έφτασες στο σημείο «Πηγή».';

    private function arrivalNotice(): array
    {
        return ['url' => 'war-room.php?id=1', 'bannerMission' => 1, 'popupInfo' => 'mission_arrived_auto', 'alarmStyle' => 'speak'];
    }

    public function testAnArrivalTheGpsRecordedIsReadInGreek(): void
    {
        $speech = mobileAlertSpeech($this->arrivalNotice(), self::GREEK, 3);

        $this->assertSame(['text' => self::GREEK, 'lang' => 'el-GR'], $speech);
    }

    public function testTheLanguageIsThatOfTheTextNotOfAnyAccount(): void
    {
        $this->assertSame('en-US', mobileAlertSpeech($this->arrivalNotice(), 'You have arrived at the point «Spring».', 3)['lang']);
        $this->assertSame('el-GR', mobileAlertSpeech($this->arrivalNotice(), 'Go to Πηγή now', 3)['lang'], 'one Greek word is enough for the Greek voice, as on the page');
    }

    public function testAVoiceAnnouncementSentToThisPersonIsReadToo(): void
    {
        $data = ['orderId' => 41, 'bannerMission' => 1, 'alarmStyle' => 'speak'];

        $this->assertSame('el-GR', mobileAlertSpeech($data, 'Συγκεντρωθείτε στη βάση', 3)['lang']);
    }

    public function testAnAdminsFyiCopyOfSomebodyElsesAnnouncementIsNotRead(): void
    {
        // The bystander copy carries alarmStyle but no orderId: the page does
        // not speak it either (showWarRoomBanner() wants an order id).
        $fyi = ['bannerMission' => 1, 'alarmStyle' => 'speak'];

        $this->assertNull(mobileAlertSpeech($fyi, 'Ανακοίνωση προς την ομάδα ΑΛΦΑ', 3));
    }

    public function testNothingIsReadForAnAlertThatIsNotAnnouncementStyle(): void
    {
        $this->assertNull(mobileAlertSpeech(['orderId' => 41, 'bannerMission' => 1], 'Στείλε στίγμα', 3));
        $this->assertNull(mobileAlertSpeech(['orderId' => 41, 'alarmStyle' => 'return_to_base'], 'Επιστροφή', 3));
        $this->assertNull(mobileAlertSpeech(null, 'x', 3));
    }

    public function testAnAppThatDidNotAskIsSentNothing(): void
    {
        $this->assertNull(mobileAlertSpeech($this->arrivalNotice(), self::GREEK, 2));
        $this->assertNull(mobileAlertSpeech($this->arrivalNotice(), self::GREEK, 0));
    }

    public function testEmptyTextIsNotSpokenAndLongTextIsCut(): void
    {
        $this->assertNull(mobileAlertSpeech($this->arrivalNotice(), "  \n ", 3));

        $long = mobileAlertSpeech($this->arrivalNotice(), str_repeat('α', 900), 3);
        $this->assertSame(MOBILE_ALERT_SPEAK_MAX_CHARS, mb_strlen($long['text']));
    }
}
