<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * What a drawn zone is (v3.417.0): «Επικίνδυνη ζώνη», «Γκρεμός / απότομη πλαγιά» or
 * «Απαγορευμένη ζώνη». Every kind is still a polygon and still raises the same breach
 * alarm; the kind is what the AR view, the map popup and the list say about it, and it
 * is what lets command mark an exposed ledge so a phone can show «ΑΠΟΚΡΗΜΝΟΣ ΓΚΡΕΜΟΣ».
 *
 * A zone drawn before the column existed must still be a plain danger zone, which is
 * the column's default. Runs inside a transaction that is always rolled back.
 */
final class RestrictedAreaKindTest extends TestCase
{
    private int $missionId;

    protected function setUp(): void
    {
        db()->beginTransaction();
        $missionTypeId = (int) dbFetchValue("SELECT id FROM mission_types ORDER BY id LIMIT 1");
        $this->missionId = (int) dbInsert(
            "INSERT INTO missions (title, location, start_datetime, end_datetime, mission_type_id, status, show_in_ops) VALUES (?, ?, ?, ?, ?, ?, 1)",
            ['Zone Kind Mission', 'Ηράκλειο', date('Y-m-d H:i:s', time() - 7200), date('Y-m-d H:i:s', time() + 7200), $missionTypeId, STATUS_OPEN]
        );
    }

    protected function tearDown(): void
    {
        db()->rollBack();
    }

    private function zone(string $label, ?string $kind): int
    {
        $geo = json_encode([[35.2, 24.9], [35.2, 24.901], [35.201, 24.9]]);
        return $kind === null
            ? (int) dbInsert("INSERT INTO mission_restricted_areas (mission_id, label, geo, created_at) VALUES (?, ?, ?, NOW())", [$this->missionId, $label, $geo])
            : (int) dbInsert("INSERT INTO mission_restricted_areas (mission_id, label, kind, geo, created_at) VALUES (?, ?, ?, ?, NOW())", [$this->missionId, $label, $kind, $geo]);
    }

    public function testAZoneWithoutAKindIsADangerZone(): void
    {
        $this->zone('Παλιά ζώνη', null);
        $rows = loadMissionRestrictedAreasForUser($this->missionId);
        $this->assertCount(1, $rows);
        $this->assertSame('danger', $rows[0]['kind']);
    }

    public function testTheLoaderCarriesEachZonesKind(): void
    {
        $this->zone('Ρέμα', 'danger');
        $this->zone('Άκρη φαραγγιού', 'cliff');
        $this->zone('Εργοτάξιο', 'forbidden');
        $kinds = array_column(loadMissionRestrictedAreasForUser($this->missionId), 'kind', 'label');
        $this->assertSame(['Ρέμα' => 'danger', 'Άκρη φαραγγιού' => 'cliff', 'Εργοτάξιο' => 'forbidden'], $kinds);
    }

    public function testTheEndpointValidatesTheKindBeforeInsertingAndLogsIt(): void
    {
        // v3.458.0: the endpoint's `create` body moved into prepare/createMissionRestrictedArea() (includes/functions-warroom.php).
        $all = (string) file_get_contents(__DIR__ . '/../includes/functions-warroom.php');
        $from = (int) strpos($all, 'function prepareMissionRestrictedArea(');
        $src = substr($all, $from, (int) strpos($all, 'function deleteMissionRestrictedArea(') - $from);
        $this->assertGreaterThan(0, $from);
        $this->assertStringContainsString("in_array(\$kind, ['danger', 'cliff', 'forbidden'], true)", $src);
        $this->assertStringContainsString('INSERT INTO mission_restricted_areas (mission_id, label, kind, shape, geo, created_by, created_at)', $src);
        $this->assertLessThan(
            strpos($src, 'INSERT INTO mission_restricted_areas'),
            strpos($src, "in_array(\$kind"),
            'the kind is checked before the row is written'
        );
        $endpoint = (string) file_get_contents(__DIR__ . '/../mission-restricted-area.php');
        $this->assertStringContainsString('createMissionRestrictedArea($mission, (int) $userId', $endpoint);
        $this->assertStringNotContainsString('INSERT INTO mission_restricted_areas', $endpoint, 'the endpoint no longer writes the zone itself');
    }

    public function testTheSchemaFileAndTheMigrationAgree(): void
    {
        $schema = (string) file_get_contents(__DIR__ . '/../sql/schema.sql');
        $this->assertMatchesRegularExpression("~`kind` ENUM\('danger','cliff','forbidden'\) NOT NULL DEFAULT 'danger'~", $schema);
        $migrations = (string) file_get_contents(__DIR__ . '/../includes/migrations.php');
        $this->assertStringContainsString("ADD COLUMN kind ENUM('danger','cliff','forbidden') NOT NULL DEFAULT 'danger' AFTER label", $migrations);
        $this->assertGreaterThanOrEqual(195, DB_SCHEMA_VERSION);
    }

    public function testTheComposerOffersTheThreeKindsAndSendsTheChosenOne(): void
    {
        $page = (string) WarRoomSource::full();
        foreach (['danger', 'cliff', 'forbidden'] as $kind) {
            $this->assertStringContainsString('<option value="' . $kind . '"><?= t(\'restricted_area.kind_' . $kind . '\') ?></option>', $page);
        }
        $this->assertStringContainsString('geo: JSON.stringify(drawPoints), label: label, kind: kindSelect.value,', $page);
        // The map popup and the list both name the kind.
        $this->assertGreaterThanOrEqual(2, substr_count($page, 'restrictedKindLabel('));
    }

    public function testEveryKindHasItsStringsInBothLanguages(): void
    {
        $lang = (string) file_get_contents(__DIR__ . '/../includes/lang/war-room.php');
        $keys = [];
        foreach (['danger', 'cliff', 'forbidden'] as $kind) {
            foreach (['restricted_area.kind_', 'ar.kind_', 'ar.ahead_', 'ar.inside_'] as $prefix) {
                $keys[] = $prefix . $kind;
            }
        }
        $keys[] = 'restricted_area.kind_label';
        foreach ($keys as $key) {
            $this->assertSame(2, substr_count($lang, "'$key' =>"), "$key must exist once in Greek and once in English");
        }
    }
}
