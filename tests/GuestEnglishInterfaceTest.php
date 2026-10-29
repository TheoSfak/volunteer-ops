<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The shared interface an English-language account sees outside the Action Room
 * (v3.407.0): the header menu and notification bell, the footer, the notifications
 * page, and the status / role / volunteer-type labels. A partner-org guest who
 * chose English read all of it in Greek.
 *
 * Greek stays the source language: with nobody signed in (a cron job, an email,
 * this test) every helper must hand back exactly the Greek text it always did.
 */
final class GuestEnglishInterfaceTest extends TestCase
{
    private static function strings(): array
    {
        return require __DIR__ . '/../includes/lang/war-room.php';
    }

    private static function root(): string
    {
        return dirname(__DIR__);
    }

    public function testEveryGuiKeyThePagesUseExistsInGreekAndEnglish(): void
    {
        $strings = self::strings();
        $used = [];
        foreach (['includes/header.php', 'includes/footer.php', 'notifications.php', 'missions.php'] as $file) {
            preg_match_all("~'(gui\\.[a-z_]+)'~", (string) file_get_contents(self::root() . '/' . $file), $m);
            $used = array_merge($used, $m[1]);
        }
        $used = array_unique($used);
        $this->assertGreaterThan(30, count($used), 'the scan found too few keys to prove anything');

        foreach (['el', 'en'] as $lang) {
            foreach ($used as $key) {
                $this->assertArrayHasKey($key, $strings[$lang], "a page uses $key but '$lang' has no such text");
                $this->assertNotSame('', $strings[$lang][$key]);
            }
        }
    }

    public function testNoGuiTextExistsInOneLanguageOnly(): void
    {
        $strings = self::strings();
        $greek = array_filter(array_keys($strings['el']), fn($k) => str_starts_with($k, 'gui.'));
        $english = array_filter(array_keys($strings['en']), fn($k) => str_starts_with($k, 'gui.'));
        $this->assertSame([], array_values(array_diff($greek, $english)));
        $this->assertSame([], array_values(array_diff($english, $greek)));
    }

    public function testEveryStatusRoleAndVolunteerTypeHasAnEnglishLabel(): void
    {
        $en = self::strings()['en'];
        $groups = [
            'gui.status.' => array_keys(STATUS_LABELS),
            'gui.part.' => array_keys(PARTICIPATION_LABELS),
            'gui.role.' => array_keys(ROLE_LABELS),
            'gui.vtype.' => array_keys(VOLUNTEER_TYPE_LABELS),
        ];
        foreach ($groups as $prefix => $values) {
            foreach ($values as $value) {
                $this->assertArrayHasKey($prefix . $value, $en, "'$value' shows in Greek to an English-language account");
            }
        }
    }

    public function testTheEnglishLabelsAreRealTranslationsNotTheGreekText(): void
    {
        $en = self::strings()['en'];
        foreach ($en as $key => $text) {
            if (!str_starts_with($key, 'gui.')) {
                continue;
            }
            $this->assertDoesNotMatchRegularExpression('/[\x{0370}-\x{03FF}]/u', $text, "$key is still Greek in the English list");
        }
    }

    public function testGreekIsUnchangedWhenNobodyIsSignedIn(): void
    {
        $this->assertSame('el', uiLang());
        $this->assertSame('Ανοιχτή', localizedLabel('gui.status.OPEN', 'Ανοιχτή'));
        $this->assertStringContainsString('Ανοιχτή', statusBadge(STATUS_OPEN));
        $this->assertStringContainsString('Εθελοντής', roleBadge(ROLE_VOLUNTEER));
        $this->assertSame('Πέμπτη', formatDayShort('2026-10-29'));
        $this->assertStringStartsWith('Πέμπτη, 29 Οκτωβρίου 2026', formatDateGreek('2026-10-29'));
    }

    public function testAnUntranslatedKeyFallsBackToTheGreekText(): void
    {
        $this->assertSame('Κάτι', localizedLabel('gui.no_such_key_at_all', 'Κάτι'));
    }
}
