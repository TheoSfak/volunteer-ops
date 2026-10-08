<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * The size-up of a Μαζικό Συμβάν, the parts that need no database:
 * normalizeTriageSizeup() (what a posted form is reduced to), the report
 * wording, and the translations every option needs.
 */
final class TriageSizeupTest extends TestCase
{
    public function testAnEmptyPostIsTheFullShapeWithNothingIn(): void
    {
        $s = normalizeTriageSizeup([]);
        $this->assertSame(
            ['hazards', 'hazards_note', 'access', 'access_note', 'casualties_estimate', 'resources', 'resources_note', 'ekab_notified'],
            array_keys($s)
        );
        $this->assertTrue(triageSizeupIsEmpty($s));
    }

    public function testOnlyKnownOptionsSurviveInCanonicalOrder(): void
    {
        $s = normalizeTriageSizeup([
            'hazards' => ['weather', 'bogus', 'fire', 'fire', 7],
            'resources' => ['police', 'helicopter', 'nope'],
            'access' => 'sideways',
            'unknown_key' => 'x',
        ]);
        $this->assertSame(['fire', 'weather'], $s['hazards'], 'Canonical order, no duplicates, no strangers.');
        $this->assertSame(['helicopter', 'police'], $s['resources']);
        $this->assertNull($s['access']);
        $this->assertArrayNotHasKey('unknown_key', $s);
        $this->assertFalse(triageSizeupIsEmpty($s));
    }

    public function testTheEstimateIsAWholeNumberOrNothing(): void
    {
        $this->assertSame(12, normalizeTriageSizeup(['casualties_estimate' => '12'])['casualties_estimate']);
        $this->assertSame(0, normalizeTriageSizeup(['casualties_estimate' => 0])['casualties_estimate']);
        foreach (['', 'abc', '-3', '1.5', 1000, '1000', null, 7.5] as $bad) {
            $this->assertNull(normalizeTriageSizeup(['casualties_estimate' => $bad])['casualties_estimate'], var_export($bad, true));
        }
    }

    public function testNotesAreTrimmedAndCutAndTheFlagIsABoolean(): void
    {
        $s = normalizeTriageSizeup(['hazards_note' => '  ' . str_repeat('α', 600) . '  ', 'access_note' => '  δρόμος  ', 'ekab_notified' => '1']);
        $this->assertSame(500, mb_strlen($s['hazards_note']));
        $this->assertSame('δρόμος', $s['access_note']);
        $this->assertTrue($s['ekab_notified']);
        foreach ([null, '', '0', 'false', 0, false] as $off) {
            $this->assertFalse(normalizeTriageSizeup(['ekab_notified' => $off])['ekab_notified'], var_export($off, true));
        }
    }

    public function testEveryOptionIsTranslatedInBothLanguages(): void
    {
        $strings = require __DIR__ . '/../includes/lang/war-room.php';
        $groups = ['hazard' => TRIAGE_SIZEUP_HAZARDS, 'resource' => TRIAGE_SIZEUP_RESOURCES, 'access' => TRIAGE_SIZEUP_ACCESS];
        foreach (['el', 'en'] as $lang) {
            foreach ($groups as $group => $keys) {
                foreach ($keys as $key) {
                    $this->assertArrayHasKey("triage.sizeup.$group.$key", $strings[$lang], "$group $key ($lang)");
                }
            }
            foreach (['triage.act_sizeup', 'triage.hazards_title', 'triage.hazards_message', 'triage.sizeup_title', 'triage.sizeup_save'] as $key) {
                $this->assertArrayHasKey($key, $strings[$lang], "$key ($lang)");
            }
        }
    }

    public function testTheReportWordsTheSizeupAndSetsTheEstimateBesideWhatWasRecorded(): void
    {
        $this->assertSame([], triageSizeupLines(null, 9));
        $s = normalizeTriageSizeup([
            'hazards' => ['fire', 'rockfall'], 'hazards_note' => 'καπνός', 'access' => 'partial', 'casualties_estimate' => 12,
            'resources' => ['helicopter'], 'ekab_notified' => true,
        ]);
        $text = implode("\n", triageSizeupLines($s, 15));
        $this->assertStringContainsString('Κίνδυνοι: Φωτιά, Πτώση βράχων — καπνός', $text);
        $this->assertStringContainsString('Πρόσβαση οχημάτων: Μερική', $text);
        $this->assertStringContainsString('Εκτίμηση θυμάτων: 12 · Καταγράφηκαν τελικά: 15', $text);
        $this->assertStringContainsString('Τι ζητήθηκε: Ελικόπτερο', $text);
        $this->assertStringContainsString('Το ΕΚΑΒ ενημερώθηκε.', $text);
        $this->assertStringNotContainsString('triage.', $text, 'Every word is translated.');
    }

    public function testNothingIsClaimedForWhatWasNotFilledIn(): void
    {
        $lines = triageSizeupLines(normalizeTriageSizeup(['hazards' => ['water']]), 4);
        $this->assertSame(['Κίνδυνοι: Νερό / ρεύμα'], $lines, 'No access, estimate, resources or EKAB line when none was given.');
    }
}
