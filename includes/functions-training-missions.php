<?php
/**
 * VolunteerOps - Training missions (v3.504.0).
 *
 * A training mission is a drill: the flag `missions.is_training` is chosen when
 * the mission is created and never changes. Every participation row of such a
 * mission carries the same flag (trigger trg_pr_set_training, migration 224), so
 * operational statistics, points and achievements filter `pr.is_training = 0`
 * and the volunteer's training record reads `pr.is_training = 1` - the same rows,
 * one source of truth.
 *
 * Deliberately NOT named training-functions.php: that file is the education
 * module (courses, quizzes, exams).
 */

if (!defined('VOLUNTEEROPS')) {
    die('Direct access not permitted');
}

if (!defined('TRAINING_MISSION_PREFIX')) {
    define('TRAINING_MISSION_PREFIX', '[ΑΣΚΗΣΗ]');
}

if (!function_exists('isTrainingMission')) {

    /**
     * @param int|array $mission a missions row (its is_training, else its id) or an id
     */
    function isTrainingMission($mission): bool
    {
        if (is_array($mission)) {
            if (array_key_exists('is_training', $mission)) {
                return (int) $mission['is_training'] === 1;
            }
            $mission = (int) ($mission['id'] ?? 0);
        }
        $id = (int) $mission;
        if ($id <= 0) {
            return false;
        }
        return (int) dbFetchValue("SELECT is_training FROM missions WHERE id = ?", [$id]) === 1;
    }

    /**
     * The value to store for missions.is_training. An existing mission keeps what it
     * was created with (the flag is locked); only a new one reads the checkbox.
     */
    function trainingMissionFlagForSave(?array $existingMission, array $post): int
    {
        if ($existingMission !== null) {
            return (int) ($existingMission['is_training'] ?? 0) === 1 ? 1 : 0;
        }
        return !empty($post['is_training']) ? 1 : 0;
    }

    /**
     * Does the trigger that copies missions.is_training onto participation_requests exist?
     * Without it a drill's participations would be counted as real ones, so every gate
     * asks this and fails closed: a query that throws or returns nothing means "no".
     * Cached per request, per trigger name. Known limit: information_schema.TRIGGERS only
     * lists the triggers the database user holds the TRIGGER privilege for, so on a host
     * where the user lacks that privilege this returns false even if the trigger exists;
     * that is the safe direction (drills stay unavailable), never the dangerous one.
     */
    function trainingTriggerExists(?string $triggerName = null): bool
    {
        static $cache = [];
        $name = $triggerName ?? 'trg_pr_set_training';
        if (!array_key_exists($name, $cache)) {
            try {
                $cache[$name] = (int) dbFetchValue(
                    "SELECT COUNT(*) FROM information_schema.TRIGGERS
                     WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ?
                       AND EVENT_OBJECT_TABLE = 'participation_requests'",
                    [$name]
                ) > 0;
            } catch (Throwable $e) {
                $cache[$name] = false;
            }
        }
        return $cache[$name];
    }

    /** Can a training mission be created right now? One name for call sites to read as intent. */
    function trainingMissionsAvailable(): bool
    {
        return trainingTriggerExists();
    }

    /** '[ΑΣΚΗΣΗ] ' for a training mission, '' otherwise. */
    function trainingTitlePrefix($mission): string
    {
        return isTrainingMission($mission) ? TRAINING_MISSION_PREFIX . ' ' : '';
    }

    /**
     * The reconciliation UPDATE migration 224 runs right after it creates the trigger:
     * if CREATE TRIGGER failed on an earlier request (shared-host privilege), the
     * columns already existed and the app kept running, so participation rows created
     * in that gap carry is_training = 0 even for a drill. Idempotent. One shared
     * string so the migration and its test execute exactly the same SQL.
     * (Points already granted in the gap need manual reversal.)
     */
    function trainingReconcileParticipationsSql(): string
    {
        return "UPDATE participation_requests pr
                JOIN shifts s ON s.id = pr.shift_id
                JOIN missions m ON m.id = s.mission_id
                SET pr.is_training = m.is_training
                WHERE pr.is_training <> m.is_training";
    }

    /** A small badge for lists and headers, '' for a real mission. */
    function trainingMissionBadgeHtml(array $mission): string
    {
        if (!isTrainingMission($mission)) {
            return '';
        }
        return '<span class="badge bg-warning text-dark ms-1" title="Εκπαιδευτική άσκηση - δεν επηρεάζει τα στατιστικά">'
             . '<i class="bi bi-mortarboard-fill me-1"></i>ΑΣΚΗΣΗ</span>';
    }

    /**
     * A volunteer's training record: attended participations of training missions.
     * Hours use the same fallback profile.php uses (recorded hours, else the shift's length).
     *
     * @return array{drills:int, hours:float, rows:array<int,array{mission_id:int,title:string,first_start:string,hours:float,certificate_id:?int}>}
     */
    function getVolunteerTrainingRecord(int $userId): array
    {
        $rows = dbFetchAll(
            "SELECT m.id AS mission_id, m.title, MIN(s.start_time) AS first_start,
                    COALESCE(SUM(CASE WHEN pr.actual_hours IS NOT NULL THEN pr.actual_hours
                                      ELSE TIMESTAMPDIFF(HOUR, s.start_time, s.end_time) END), 0) AS hours,
                    (SELECT mc.id FROM mission_certificates mc
                      WHERE mc.mission_id = m.id AND mc.recipient_user_id = pr.volunteer_id
                      ORDER BY mc.id DESC LIMIT 1) AS certificate_id
             FROM participation_requests pr
             JOIN shifts s ON s.id = pr.shift_id
             JOIN missions m ON m.id = s.mission_id AND m.deleted_at IS NULL
             WHERE pr.volunteer_id = ? AND pr.is_training = 1 AND pr.attended = 1 AND pr.status = ?
             GROUP BY m.id, m.title, pr.volunteer_id
             ORDER BY first_start DESC",
            [$userId, PARTICIPATION_APPROVED]
        );

        $hours = 0.0;
        $out = [];
        foreach ($rows as $r) {
            $h = (float) $r['hours'];
            $hours += $h;
            $out[] = [
                'mission_id'     => (int) $r['mission_id'],
                'title'          => (string) $r['title'],
                'first_start'    => (string) $r['first_start'],
                'hours'          => $h,
                'certificate_id' => $r['certificate_id'] !== null ? (int) $r['certificate_id'] : null,
            ];
        }
        return ['drills' => count($out), 'hours' => $hours, 'rows' => $out];
    }

    /** One volunteer's recorded hours in one training mission (for the certificate). */
    function getTrainingHoursForMission(int $missionId, int $userId): float
    {
        return (float) dbFetchValue(
            "SELECT COALESCE(SUM(CASE WHEN pr.actual_hours IS NOT NULL THEN pr.actual_hours
                                      ELSE TIMESTAMPDIFF(HOUR, s.start_time, s.end_time) END), 0)
             FROM participation_requests pr
             JOIN shifts s ON s.id = pr.shift_id
             WHERE s.mission_id = ? AND pr.volunteer_id = ? AND pr.attended = 1 AND pr.status = ?",
            [$missionId, $userId, PARTICIPATION_APPROVED]
        );
    }
}
