<?php
/**
 * VolunteerOps - Achievement Checking & Awarding Functions
 */

if (!defined('VOLUNTEEROPS')) {
    die('Direct access not permitted');
}

if (!function_exists('checkAndAwardAchievements')) {
/**
 * Check all achievements for a user and award any newly earned ones.
 * Uses INSERT IGNORE so it's safe to call multiple times.
 * Returns array of newly awarded achievement rows (for display purposes).
 */
function checkAndAwardAchievements(int $userId): array
{
    $newlyAwarded = [];

    try {
        $achievements = dbFetchAll("SELECT * FROM achievements WHERE is_active = 1");
        if (empty($achievements)) return [];

        $earnedIds = array_map('intval', array_column(
            dbFetchAll("SELECT achievement_id FROM user_achievements WHERE user_id = ?", [$userId]),
            'achievement_id'
        ));

        // --- Collect user stats (only query what we need) ---

        $user = dbFetchOne("SELECT total_points, created_at FROM users WHERE id = ?", [$userId]);
        if (!$user) return [];
        $totalPoints = (int)($user['total_points'] ?? 0);

        // Combined stats query — replaces 8 separate queries with 1
        $stats = dbFetchOne(
            "SELECT 
                COUNT(*) as completed_shifts,
                COUNT(DISTINCT s.mission_id) as completed_missions,
                COALESCE(SUM(CASE WHEN pr.actual_hours > 0 THEN pr.actual_hours
                              ELSE TIMESTAMPDIFF(MINUTE, s.start_time, s.end_time) / 60.0 END), 0) as total_hours,
                SUM(DAYOFWEEK(s.start_time) IN (1, 7)) as weekend_shifts,
                SUM(HOUR(s.start_time) >= 22 OR HOUR(s.start_time) < 6) as night_shifts,
                SUM(HOUR(s.start_time) < 8) as morning_shifts,
                COUNT(DISTINCT DATE_FORMAT(s.start_time, '%Y-%m')) as distinct_months
             FROM participation_requests pr
             JOIN shifts s ON pr.shift_id = s.id
             WHERE pr.volunteer_id = ? AND pr.status = 'APPROVED' AND pr.attended = 1",
            [$userId]
        );
        $completedShifts = (int)($stats['completed_shifts'] ?? 0);
        $completedMissions = (int)($stats['completed_missions'] ?? 0);
        $totalHours = (float)($stats['total_hours'] ?? 0);
        $weekendShifts = (int)($stats['weekend_shifts'] ?? 0);
        $nightShifts = (int)($stats['night_shifts'] ?? 0);
        $morningShifts = (int)($stats['morning_shifts'] ?? 0);
        $distinctMonths = (int)($stats['distinct_months'] ?? 0);

        // Medical missions (needs missions join, kept separate)
        $medicalMissions = (int)dbFetchValue(
            "SELECT COUNT(DISTINCT m.id) FROM participation_requests pr
             JOIN shifts s ON pr.shift_id = s.id
             JOIN missions m ON s.mission_id = m.id
             WHERE pr.volunteer_id = ? AND pr.status = 'APPROVED' AND pr.attended = 1
               AND (m.title LIKE '%υγει%' OR m.title LIKE '%ιατρ%'
                 OR m.title LIKE '%νοσηλ%' OR m.title LIKE '%αίμα%'
                 OR m.title LIKE '%ΤΕΠΥ%'  OR m.title LIKE '%βοήθ%'
                 OR m.title LIKE '%πρώτ%'  OR m.title LIKE '%ΠΒ%')",
            [$userId]
        );

        // Account age in days (already fetched user above)
        $daysSinceRegistration = (int)dbFetchValue(
            "SELECT DATEDIFF(NOW(), created_at) FROM users WHERE id = ?",
            [$userId]
        );

        // --- Check each achievement ---
        foreach ($achievements as $ach) {
            if (in_array((int)$ach['id'], $earnedIds)) continue;

            $earned = false;
            switch ($ach['code']) {
                // Shifts milestones
                case 'first_shift':     $earned = $completedShifts >= 1; break;
                case 'shifts_5':        $earned = $completedShifts >= 5; break;
                case 'shifts_10':       $earned = $completedShifts >= 10; break;
                case 'shifts_25':       $earned = $completedShifts >= 25; break;
                case 'shifts_50':       $earned = $completedShifts >= 50; break;
                case 'shifts_100':      $earned = $completedShifts >= 100; break;

                // Mission milestones
                case 'first_mission':   $earned = $completedMissions >= 1; break;
                case 'missions_3':      $earned = $completedMissions >= 3; break;
                case 'missions_10':     $earned = $completedMissions >= 10; break;
                case 'missions_25':     $earned = $completedMissions >= 25; break;
                case 'missions_50':     $earned = $completedMissions >= 50; break;

                // Hours milestones
                case 'hours_10':        $earned = $totalHours >= 10;   break;
                case 'hours_50':        $earned = $totalHours >= 50;   break;
                case 'hours_100':       $earned = $totalHours >= 100;  break;
                case 'hours_250':       $earned = $totalHours >= 250;  break;
                case 'hours_500':       $earned = $totalHours >= 500;  break;
                case 'hours_1000':      $earned = $totalHours >= 1000; break;

                // Points milestones
                case 'points_100':      $earned = $totalPoints >= 100;  break;
                case 'points_500':      $earned = $totalPoints >= 500;  break;
                case 'points_1000':     $earned = $totalPoints >= 1000; break;
                case 'points_2000':     $earned = $totalPoints >= 2000; break;
                case 'points_5000':     $earned = $totalPoints >= 5000; break;

                // Special
                case 'weekend_warrior': $earned = $weekendShifts >= 10;          break;
                case 'night_owl':       $earned = $nightShifts >= 10;            break;
                case 'medical_hero':    $earned = $medicalMissions >= 10;        break;
                case 'early_bird':      $earned = $morningShifts >= 5;           break;
                case 'dedicated':       $earned = $distinctMonths >= 5;          break;
                case 'loyal_member':    $earned = $daysSinceRegistration >= 365; break;
                case 'rescuer_elite':   $earned = ($totalHours >= 250 && $completedMissions >= 50); break;
            }

            if ($earned) {
                $inserted = dbExecute(
                    "INSERT IGNORE INTO user_achievements (user_id, achievement_id, earned_at, notified)
                     VALUES (?, ?, NOW(), 0)",
                    [$userId, $ach['id']]
                );
                if ($inserted > 0) {
                    $newlyAwarded[] = $ach;
                }
            }
        }
    } catch (Exception $e) {
        // Silent fail – never break page flow
        error_log('[achievements] checkAndAwardAchievements error for user ' . $userId . ': ' . $e->getMessage());
    }

    return $newlyAwarded;
}
} // end function_exists
