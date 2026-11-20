<?php

declare(strict_types=1);

/**
 * Shared builders for the training-mission tests. Every test that uses them
 * runs inside a transaction that is rolled back, like ParCheckinTest.
 */
final class TrainingFixtures
{
    public static function user(string $name = 'Training Vol'): int
    {
        return (int) dbInsert(
            "INSERT INTO users (name, email, password) VALUES (?, ?, ?)",
            [$name, 'trn-' . uniqid('', true) . '@example.invalid', 'x']
        );
    }

    /** @return array{mission:int, shift:int} */
    public static function mission(bool $training, string $status = STATUS_OPEN): array
    {
        $typeId = (int) dbFetchValue("SELECT id FROM mission_types ORDER BY id LIMIT 1");
        $missionId = (int) dbInsert(
            "INSERT INTO missions (title, location, start_datetime, end_datetime, mission_type_id, status, is_training)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [
                ($training ? 'Drill ' : 'Real ') . uniqid(),
                'Ηράκλειο',
                date('Y-m-d H:i:s', time() - 7200),
                date('Y-m-d H:i:s', time() - 3600),
                $typeId,
                $status,
                $training ? 1 : 0,
            ]
        );
        $shiftId = (int) dbInsert(
            "INSERT INTO shifts (mission_id, start_time, end_time) VALUES (?, ?, ?)",
            [$missionId, date('Y-m-d H:i:s', time() - 7200), date('Y-m-d H:i:s', time() - 3600)]
        );
        return ['mission' => $missionId, 'shift' => $shiftId];
    }

    public static function participation(int $shiftId, int $userId, bool $attended = true, ?float $hours = 2.0): int
    {
        return (int) dbInsert(
            "INSERT INTO participation_requests (shift_id, volunteer_id, status, attended, actual_hours)
             VALUES (?, ?, ?, ?, ?)",
            [$shiftId, $userId, PARTICIPATION_APPROVED, $attended ? 1 : 0, $hours]
        );
    }
}
