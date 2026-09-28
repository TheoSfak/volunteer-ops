<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../includes/clock-place.php';

/**
 * The place at the end of the clock strip, «Δευτέρα 28/09/2026 - 00:14:05
 * Ηράκλειο» (v3.345.0): the prefecture a mission's map pin is in.
 *
 * Nominatim itself is never called here — each test hands missionClockPlace()
 * its own lookup, because a test that needs the service up fails for reasons
 * that have nothing to do with this code. The names in clockPlaces() were read
 * off OpenStreetMap's own 74 Greek prefecture boundaries.
 *
 * Runs inside a transaction that is always rolled back.
 */
final class ClockPlaceTest extends TestCase
{
    private int $missionId;

    protected function setUp(): void
    {
        db()->beginTransaction();
        $missionTypeId = (int) dbFetchValue("SELECT id FROM mission_types ORDER BY id LIMIT 1");
        $this->missionId = (int) dbInsert(
            "INSERT INTO missions (title, location, latitude, longitude, start_datetime, end_datetime, mission_type_id, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            ['Clock Mission', 'Ζαρός', 35.1385, 24.9062, date('Y-m-d H:i:s'), date('Y-m-d H:i:s', time() + 3600), $missionTypeId, STATUS_OPEN]
        );
    }

    protected function tearDown(): void
    {
        db()->rollBack();
    }

    private function mission(float $lat = 35.1385, float $lng = 24.9062): array
    {
        return ['id' => $this->missionId, 'latitude' => (string) $lat, 'longitude' => (string) $lng];
    }

    public function testAPrefectureIsNamedProperlyInBothLanguages(): void
    {
        // What Nominatim answers is the official genitive; the clock must not
        // say «Ηρακλείου».
        $this->assertSame('Ηράκλειο', clockPlaceName('Περιφερειακή Ενότητα Ηρακλείου', 'el'));
        $this->assertSame('Heraklion', clockPlaceName('Περιφερειακή Ενότητα Ηρακλείου', 'en'));
        $this->assertSame('Ρέθυμνο', clockPlaceName('Περιφερειακή Ενότητα Ρεθύμνης', 'el'));
        $this->assertSame('Λασίθι', clockPlaceName('Περιφερειακή Ενότητα Λασιθίου', 'el'));
        $this->assertSame('Θεσσαλονίκη', clockPlaceName('Μητροπολιτική Ενότητα Θεσσαλονίκης', 'el'));

        // Athens is four prefectures in OSM and one city on the clock.
        foreach (['Κεντρικού', 'Βορείου', 'Νοτίου', 'Δυτικού'] as $sector) {
            $this->assertSame('Αθήνα', clockPlaceName("Περιφερειακή Ενότητα {$sector} Τομέα Αθηνών", 'el'));
            $this->assertSame('Athens', clockPlaceName("Περιφερειακή Ενότητα {$sector} Τομέα Αθηνών", 'en'));
        }
    }

    public function testAnEditorTidyingTheSpellingDoesNotLoseTheName(): void
    {
        // OSM writes «Κέας - Κύθνου» with spaces and «Καρπάθου-Ηρωικής…»
        // without; either may be tidied, and an accent may go missing.
        $this->assertSame('Κέα - Κύθνος', clockPlaceName('Περιφερειακή Ενότητα Κέας-Κύθνου', 'el'));
        $this->assertSame('Κάρπαθος - Κάσος', clockPlaceName('Περιφερειακή Ενότητα Καρπάθου - Ηρωικής Νήσου Κάσου', 'el'));
        $this->assertSame('Χανιά', clockPlaceName('Περιφερειακή Ενότητα Χανιων', 'el'));
    }

    public function testAnUnknownNameIsShownOfficiallyRatherThanGuessed(): void
    {
        $this->assertSame('Π.Ε. Νέας Ενότητας', clockPlaceName('Περιφερειακή Ενότητα Νέας Ενότητας', 'el'));
        $this->assertSame('New Unit', clockPlaceName('Περιφερειακή Ενότητα Νέας Ενότητας', 'en', 'New Unit Regional Unit'));
        $this->assertSame('Central Athens', clockPlaceName('Περιφερειακή Ενότητα Κάπου', 'en', 'Regional Unit of Central Athens'));
        // Outside Greece there is no prefix to take off.
        $this->assertSame('Επαρχία Λευκωσίας', clockPlaceName('Επαρχία Λευκωσίας', 'el'));
        $this->assertSame('', clockPlaceName('', 'el'));
    }

    public function testEveryListedPrefectureNamesItself(): void
    {
        // The Settings dropdown stores a key of clockPlaces(); the clock reads
        // it back through the same function the lookups go through.
        $places = clockPlaces();
        $this->assertCount(74, $places);
        foreach ($places as $key => [$el, $en]) {
            $this->assertSame($el, clockPlaceName($key, 'el'), $key);
            $this->assertSame($en, clockPlaceName($key, 'en'), $key);
            $this->assertSame($el, clockPlaceName('Περιφερειακή Ενότητα ' . $key, 'el'), $key);
        }
    }

    public function testAPinIsLookedUpOnceAndAgainOnlyWhenItMoves(): void
    {
        $calls = 0;
        $lookup = function (float $lat, float $lng) use (&$calls): array {
            $calls++;
            return $lat > 35.2
                ? ['county' => 'Περιφερειακή Ενότητα Ρεθύμνης', 'county_en' => 'Rethymno Regional Unit']
                : ['county' => 'Περιφερειακή Ενότητα Ηρακλείου', 'county_en' => 'Heraklion Regional Unit'];
        };

        $this->assertSame('Ηράκλειο', missionClockPlace($this->mission(), 'el', $lookup));
        $this->assertSame('Heraklion', missionClockPlace($this->mission(), 'en', $lookup));
        $this->assertSame('Ηράκλειο', missionClockPlace($this->mission(), 'el', $lookup));
        $this->assertSame(1, $calls, 'the same pin is read from mission_place_cache');

        // The pin moved onto Psiloritis, across the prefecture line.
        $this->assertSame('Ρέθυμνο', missionClockPlace($this->mission(35.2247, 24.8225), 'el', $lookup));
        $this->assertSame(2, $calls);
        $this->assertSame('Ρέθυμνο', missionClockPlace($this->mission(35.2247, 24.8225), 'el', $lookup));
        $this->assertSame(2, $calls);
    }

    public function testAFailedLookupIsLeftAloneForAnHour(): void
    {
        $calls = 0;
        $down = function () use (&$calls): ?array { $calls++; return null; };

        $this->assertNull(missionClockPlace($this->mission(), 'el', $down));
        $this->assertNull(missionClockPlace($this->mission(), 'el', $down));
        $this->assertSame(1, $calls, 'an unreachable service costs one page, not every page');

        // An hour later it is tried again, and this time it answers.
        dbExecute(
            "UPDATE mission_place_cache SET looked_up_at = ? WHERE mission_id = ?",
            [date('Y-m-d H:i:s', time() - CLOCK_PLACE_RETRY_SECONDS - 60), $this->missionId]
        );
        $up = fn() => ['county' => 'Περιφερειακή Ενότητα Ηρακλείου', 'county_en' => ''];
        $this->assertSame('Ηράκλειο', missionClockPlace($this->mission(), 'el', $up));
    }

    public function testAMissionWithoutAPinFallsBackToTheDefault(): void
    {
        $never = function () { $this->fail('no pin, nothing to look up'); };
        $this->assertNull(missionClockPlace(['id' => $this->missionId, 'latitude' => null, 'longitude' => null], 'el', $never));
        $this->assertNull(missionClockPlace(['id' => $this->missionId, 'latitude' => '0', 'longitude' => '0'], 'el', $never));

        // The key the Settings dropdown stores.
        $this->assertSame('Χανιά', clockPlaceLabel(null, 'el', 'Χανίων'));
        $this->assertSame('Chania', clockPlaceLabel(null, 'en', 'Χανίων'));
        $this->assertSame('Χανιά', clockPlaceLabel(['id' => $this->missionId, 'latitude' => null, 'longitude' => null], 'el', 'Χανίων'));
        $this->assertSame('', clockPlaceLabel(null, 'el', ''));

        // A mission whose prefecture is known names it, not the default.
        $heraklion = fn() => ['county' => 'Περιφερειακή Ενότητα Ηρακλείου', 'county_en' => ''];
        $this->assertSame('Ηράκλειο', clockPlaceLabel($this->mission(), 'el', 'Χανίων', $heraklion));
    }
}
