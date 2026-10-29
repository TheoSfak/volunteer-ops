<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The outcomes command can close an incident with. Four things have to agree or
 * an option fails quietly: the database ENUM (an unknown value is stored as an
 * empty string or rejected), the label constant mission-incident.php validates
 * against, the Greek and English texts, and the dropdown the page offers.
 * «Αποχώρησε περιπατητικός» (v3.409.1) is the fifth value.
 */
final class IncidentOutcomeOptionsTest extends TestCase
{
    private static function enumValues(): array
    {
        $type = (string) dbFetchValue(
            "SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'mission_incidents' AND COLUMN_NAME = 'outcome'"
        );
        preg_match_all("~'([a-z_]+)'~", $type, $m);
        return $m[1];
    }

    public function testTheDatabaseTheLabelsAndThePageOfferTheSameOutcomes(): void
    {
        $labels = array_keys(INCIDENT_OUTCOME_LABELS);
        $this->assertEqualsCanonicalizing($labels, self::enumValues(), 'the outcome ENUM and INCIDENT_OUTCOME_LABELS drifted apart');

        $src = (string) file_get_contents(__DIR__ . '/../war-room.php');
        $start = strpos($src, 'const INCIDENT_OUTCOME_OPTIONS = [');
        $this->assertNotFalse($start);
        preg_match_all("~\\['([a-z_]+)', 'incident\\.outcome\\.~", substr($src, $start, 600), $m);
        $this->assertEqualsCanonicalizing($labels, $m[1], 'the dropdown does not offer exactly the outcomes the server accepts');
    }

    public function testWalkedAwayIsReadableInBothLanguages(): void
    {
        $this->assertSame('Αποχώρησε περιπατητικός', incidentOutcomeLabel('walked_away', 'el'));
        $this->assertSame('Left on foot', incidentOutcomeLabel('walked_away', 'en'));
    }

    public function testEveryOutcomeHasATextInBothLanguages(): void
    {
        $strings = require __DIR__ . '/../includes/lang/war-room.php';
        foreach (array_keys(INCIDENT_OUTCOME_LABELS) as $outcome) {
            foreach (['el', 'en'] as $lang) {
                $this->assertArrayHasKey('incident.outcome.' . $outcome, $strings[$lang], "'$lang' has no text for the outcome $outcome");
            }
        }
    }

    public function testTheDatabaseStoresWalkedAwayAndDoesNotTurnItIntoNothing(): void
    {
        db()->beginTransaction();
        try {
            $adminId = (int) dbInsert("INSERT INTO users (name, email, password) VALUES (?, ?, ?)", ['Outcome Admin', 'out-' . uniqid('', true) . '@example.invalid', 'x']);
            $typeId = (int) dbFetchValue("SELECT id FROM mission_types ORDER BY id LIMIT 1");
            $missionId = (int) dbInsert(
                "INSERT INTO missions (title, location, start_datetime, end_datetime, mission_type_id) VALUES (?, ?, ?, ?, ?)",
                ['Outcome Mission', 'Ηράκλειο', date('Y-m-d H:i:s', time() - 3600), date('Y-m-d H:i:s', time() + 3600), $typeId]
            );
            $incidentId = (int) dbInsert(
                "INSERT INTO mission_incidents (mission_id, reporter_id, incident_type, severity, is_unknown_patient) VALUES (?, ?, 'trauma', 'low', 1)",
                [$missionId, $adminId]
            );
            dbExecute("UPDATE mission_incidents SET outcome = 'walked_away', resolved_at = NOW() WHERE id = ?", [$incidentId]);

            $this->assertSame('walked_away', dbFetchValue("SELECT outcome FROM mission_incidents WHERE id = ?", [$incidentId]));
            $this->assertSame('Αποχώρησε περιπατητικός', incidentOutcomeText(['outcome' => 'walked_away', 'outcome_location' => null], 'el'));
        } finally {
            db()->rollBack();
        }
    }
}
