<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * The GDPR gate for the AI observer (includes/ai-context.php).
 *
 * This is not a nice-to-have test. VolunteerOps holds volunteer names, live
 * GPS, and mission_incidents.patient_name / phone / estimated_age — Article 9
 * special-category health data. The AI feature sends a mission digest to a
 * third-country provider. The ONLY thing standing between those two facts is
 * aiRedactText() and aiScanDigestForLeaks(), so a regression in either must
 * fail the build rather than ship quietly.
 *
 * Every assertion here is deliberately about BEHAVIOUR (does a real Greek
 * name in a real sentence survive?) rather than about the shape of the regex,
 * so the implementation can be rewritten without rewriting the test.
 *
 * Both functions are pure: no database, no network.
 */
final class AiRedactionTest extends TestCase
{
    private const NAMES = ['Γιώργος', 'Παπαδόπουλος', 'Ελένη', 'Κωνσταντινίδου', 'Smith'];

    // ─── aiRedactText ────────────────────────────────────────────────────

    public function testRemovesANameInItsNominativeForm(): void
    {
        $out = aiRedactText('Ο Γιώργος ανέφερε βλάβη στον ασύρματο.', self::NAMES);
        $this->assertStringNotContainsString('Γιώργος', $out);
        $this->assertStringContainsString('ασύρματο', $out, 'Redaction must not eat the rest of the sentence');
    }

    /**
     * The case a naive equality check misses. Greek inflects, so the text says
     * "του Γιώργου" while the users table holds "Γιώργος" — and a surname in
     * the genitive ("Παπαδόπουλου") is the same problem one word later.
     */
    public function testRemovesANameInInflectedForms(): void
    {
        foreach (['του Γιώργου', 'στον Γιώργο', 'ο Παπαδόπουλος', 'του Παπαδόπουλου', 'της Κωνσταντινίδου'] as $phrase) {
            $out = aiRedactText("Αναφορά: {$phrase} στο σημείο.", self::NAMES);
            $this->assertStringNotContainsString('ιώργ', $out, "Leaked a first-name stem from: {$phrase}");
            $this->assertStringNotContainsString('απαδόπ', $out, "Leaked a surname stem from: {$phrase}");
            $this->assertStringNotContainsString('ωνσταντινίδ', $out, "Leaked a surname stem from: {$phrase}");
        }
    }

    public function testRemovesANameWrittenInCapitalsOrWithoutAccents(): void
    {
        foreach (['ΓΙΩΡΓΟΣ ΠΑΠΑΔΟΠΟΥΛΟΣ', 'γιωργος παπαδοπουλος', 'Γιωργος Παπαδοπουλος'] as $written) {
            $out = aiFoldGreek(aiRedactText("Παρέλαβε ο {$written}.", self::NAMES));
            $this->assertStringNotContainsString('γιωργ', $out, "Leaked from: {$written}");
            $this->assertStringNotContainsString('παπαδοπ', $out, "Leaked from: {$written}");
        }
    }

    public function testRemovesLatinScriptGuestNames(): void
    {
        $out = aiRedactText('Handover to Smith at the staging area.', self::NAMES);
        $this->assertStringNotContainsString('Smith', $out);
    }

    public function testRemovesContactDetails(): void
    {
        $cases = [
            'Τηλ. 6971234567 για επικοινωνία.'        => '6971234567',
            'Καλέστε στο +30 697 123 4567.'           => '4567',
            'Σταθερό 2810123456 στο κέντρο.'          => '2810123456',
            'Στείλε στο test.user@example.gr άμεσα.'  => 'test.user@example.gr',
        ];
        foreach ($cases as $input => $secret) {
            $this->assertStringNotContainsString($secret, aiRedactText($input), "Leaked from: {$input}");
        }
    }

    public function testRemovesCoordinatesAndIdentityNumbers(): void
    {
        $this->assertStringNotContainsString('35.3387123', aiRedactText('Θέση 35.3387123, 24.8271904.'));
        $this->assertStringNotContainsString('24.8271904', aiRedactText('Θέση 35.3387123, 24.8271904.'));
        $this->assertStringNotContainsString('12345678901', aiRedactText('ΑΜΚΑ 12345678901 καταγράφηκε.'));
    }

    /**
     * Over-redaction would make the feature useless, so the numbers a report
     * is actually about have to survive. Two decimals is a measurement; five
     * is a coordinate.
     */
    public function testKeepsOperationalNumbers(): void
    {
        $out = aiRedactText('Ολοκληρώθηκαν 18 από 25 εντολές σε 12.5 λεπτά, κάλυψη 87%.');
        foreach (['18', '25', '12.5', '87%'] as $keep) {
            $this->assertStringContainsString($keep, $out, "Redaction destroyed an operational figure: {$keep}");
        }
    }

    public function testShortTokensAreNotTreatedAsNames(): void
    {
        // "Νίκ" is under the 4-character floor; redacting on it would blank
        // half the Greek language ("νίκη", "Νικήτας", "τεχνική").
        $out = aiRedactText('Η τεχνική υποστήριξη ολοκληρώθηκε.', ['Νίκ', 'Ανά']);
        $this->assertSame('Η τεχνική υποστήριξη ολοκληρώθηκε.', $out);
    }

    public function testCapsLongText(): void
    {
        $out = aiRedactText(str_repeat('α', 5000), [], 100);
        $this->assertLessThanOrEqual(101, mb_strlen($out, 'UTF-8'));
    }

    public function testNullAndEmptyAreSafe(): void
    {
        $this->assertSame('', aiRedactText(null, self::NAMES));
        $this->assertSame('', aiRedactText('   ', self::NAMES));
    }

    // ─── aiScanDigestForLeaks — the hard gate ────────────────────────────

    public function testCleanDigestPasses(): void
    {
        $digest = [
            'αποστολη' => ['ref' => 'MISSION', 'διαρκεια_ωρες' => 6.5, 'αριθμος_ομαδων' => 3],
            'ομαδες'   => [
                ['ref' => 'TEAM-1', 'κωδικο_ονομα' => 'ΑΕΤΟΣ', 'βαθμος' => 92.4, 'παραληπτης' => 'ΜΕΛΟΣ-1'],
                ['ref' => 'TEAM-2', 'κωδικο_ονομα' => 'ΚΟΡΑΚΑΣ', 'βαθμος' => 78.1],
            ],
        ];
        $this->assertSame([], aiScanDigestForLeaks($digest, self::NAMES));
    }

    public function testCatchesANameHidingDeepInsideTheStructure(): void
    {
        $digest = ['ομαδες' => [['εντολες' => ['παραδειγμα' => ['περιγραφη' => 'Παράδοση στον Γιώργο']]]]];
        $this->assertNotEmpty(
            aiScanDigestForLeaks($digest, self::NAMES),
            'A leak nested four levels down must still be caught'
        );
    }

    /**
     * The scan serialises the whole digest rather than walking known keys,
     * precisely so a field nobody remembered to redact — or a leak in a KEY
     * rather than a value — is caught anyway.
     */
    public function testCatchesANameUsedAsAnArrayKey(): void
    {
        $this->assertNotEmpty(aiScanDigestForLeaks(['Παπαδόπουλος' => ['λεπτα' => 12]], self::NAMES));
    }

    public function testCatchesContactDetailsAndCoordinatesRegardlessOfNameList(): void
    {
        $this->assertNotEmpty(aiScanDigestForLeaks(['x' => 'επικοινωνία 6971234567'], []));
        $this->assertNotEmpty(aiScanDigestForLeaks(['x' => 'a@b.gr'], []));
        $this->assertNotEmpty(aiScanDigestForLeaks(['x' => 'θέση 35.3387123'], []));
        $this->assertNotEmpty(aiScanDigestForLeaks(['x' => 'ΑΜΚΑ 12345678901'], []));
    }

    public function testCatchesAnAccentStrippedOrUppercasedName(): void
    {
        $this->assertNotEmpty(aiScanDigestForLeaks(['x' => 'ΠΑΠΑΔΟΠΟΥΛΟΣ'], self::NAMES));
        $this->assertNotEmpty(aiScanDigestForLeaks(['x' => 'παπαδοπουλος'], self::NAMES));
    }

    /**
     * Pseudonyms are the whole point of the gateway — they must not be
     * mistaken for the thing they replace.
     */
    public function testPseudonymsArePermitted(): void
    {
        $this->assertSame([], aiScanDigestForLeaks(['x' => 'Η εντολή προς ΜΕΛΟΣ-7 έμεινε αναπάντητη'], self::NAMES));
    }

    /**
     * Documents a deliberate trade-off rather than an oversight: a team
     * call-sign that happens to match a participant's surname stays in the
     * digest, because blanking it would gut every report to protect a name
     * the coordinator themselves chose to broadcast on the radio.
     */
    public function testATeamCodenameSurvivesEvenWhenItLooksLikeASurname(): void
    {
        // aiMissionForbiddenNames() is what drops such tokens; simulate its
        // output by simply not listing the codename.
        $this->assertSame([], aiScanDigestForLeaks(['κωδικο_ονομα' => 'ΑΕΤΟΣ'], ['Παπαδόπουλος']));
    }
}
