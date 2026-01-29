<?php

namespace App\Services;

use App\Models\Achievement;
use App\Models\User;
use App\Models\VolunteerPoint;
use App\Modules\Shifts\Models\Shift;
use App\Modules\Participation\Models\ParticipationRequest;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GamificationService
{
    /**
     * Απονέμει πόντους σε εθελοντή για ολοκληρωμένη βάρδια.
     * Χρησιμοποιεί τις πραγματικές ώρες από το participation αν υπάρχουν.
     */
    public function awardPointsForShift(User $user, Shift $shift, ParticipationRequest $participation): int
    {
        // Αν ο εθελοντής δεν ήρθε (no-show), δεν παίρνει πόντους
        if (!$participation->attended) {
            return 0;
        }

        $totalPoints = 0;
        $points = [];

        // Χρήση πραγματικών ωρών αν υπάρχουν, αλλιώς ωρών βάρδιας
        $hours = $participation->calculated_hours;
        
        // Αν δεν υπάρχουν ώρες, δεν δίνουμε πόντους
        if ($hours <= 0) {
            return 0;
        }
        
        $basePoints = (int) ($hours * VolunteerPoint::POINTS_PER_HOUR);
        
        if ($basePoints > 0) {
            $points[] = [
                'points' => $basePoints,
                'reason' => VolunteerPoint::REASON_SHIFT_COMPLETED,
                'description' => "Ολοκλήρωση βάρδιας ({$hours} ώρες)",
            ];
            $totalPoints += $basePoints;
        }

        // Μπόνους Σαββατοκύριακου
        if ($this->isWeekend($shift)) {
            $weekendBonus = (int) ($basePoints * (VolunteerPoint::WEEKEND_MULTIPLIER - 1));
            if ($weekendBonus > 0) {
                $points[] = [
                    'points' => $weekendBonus,
                    'reason' => VolunteerPoint::REASON_WEEKEND_BONUS,
                    'description' => 'Μπόνους Σαββατοκύριακου',
                ];
                $totalPoints += $weekendBonus;
            }
        }

        // Μπόνους Νυχτερινής
        if ($this->isNightShift($shift)) {
            $nightBonus = (int) ($basePoints * (VolunteerPoint::NIGHT_MULTIPLIER - 1));
            if ($nightBonus > 0) {
                $points[] = [
                    'points' => $nightBonus,
                    'reason' => VolunteerPoint::REASON_NIGHT_BONUS,
                    'description' => 'Μπόνους Νυχτερινής Βάρδιας',
                ];
                $totalPoints += $nightBonus;
            }
        }

        // Μπόνους Υγειονομικής Αποστολής
        if ($this->isMedicalMission($shift)) {
            $medicalBonus = (int) ($basePoints * (VolunteerPoint::MEDICAL_MULTIPLIER - 1));
            if ($medicalBonus > 0) {
                $points[] = [
                    'points' => $medicalBonus,
                    'reason' => VolunteerPoint::REASON_MEDICAL_BONUS,
                    'description' => 'Μπόνους Υγειονομικής Αποστολής',
                ];
                $totalPoints += $medicalBonus;
            }
        }

        // Αποθήκευση πόντων
        foreach ($points as $pointData) {
            VolunteerPoint::create([
                'user_id' => $user->id,
                'points' => $pointData['points'],
                'reason' => $pointData['reason'],
                'description' => $pointData['description'],
                'pointable_type' => get_class($participation),
                'pointable_id' => $participation->id,
            ]);
        }

        // Ενημέρωση συνόλων στον χρήστη
        $this->updateUserPoints($user);

        // Έλεγχος για νέα επιτεύγματα
        $this->checkAndAwardAchievements($user);

        return $totalPoints;
    }

    /**
     * Χειροκίνητη απονομή πόντων.
     */
    public function awardManualPoints(User $user, int $points, string $description): void
    {
        VolunteerPoint::create([
            'user_id' => $user->id,
            'points' => $points,
            'reason' => VolunteerPoint::REASON_MANUAL,
            'description' => $description,
        ]);

        $this->updateUserPoints($user);
        $this->checkAndAwardAchievements($user);
    }

    /**
     * Ενημέρωση συνολικών πόντων χρήστη.
     */
    public function updateUserPoints(User $user): void
    {
        $totalPoints = VolunteerPoint::where('user_id', $user->id)->sum('points');
        
        $monthlyPoints = VolunteerPoint::where('user_id', $user->id)
            ->where('created_at', '>=', Carbon::now()->startOfMonth())
            ->sum('points');

        $user->update([
            'total_points' => $totalPoints,
            'monthly_points' => $monthlyPoints,
        ]);
    }

    /**
     * Έλεγχος και απονομή επιτευγμάτων.
     */
    public function checkAndAwardAchievements(User $user): array
    {
        $newAchievements = [];
        $achievements = Achievement::active()->get();
        $earnedCodes = $user->achievements()->pluck('code')->toArray();

        foreach ($achievements as $achievement) {
            if (in_array($achievement->code, $earnedCodes)) {
                continue;
            }

            if ($this->checkAchievementCriteria($user, $achievement)) {
                $this->awardAchievement($user, $achievement);
                $newAchievements[] = $achievement;
            }
        }

        return $newAchievements;
    }

    /**
     * Έλεγχος κριτηρίων επιτεύγματος.
     */
    protected function checkAchievementCriteria(User $user, Achievement $achievement): bool
    {
        $stats = $this->getUserStats($user);

        switch ($achievement->code) {
            // Ώρες εθελοντισμού
            case Achievement::CODE_HOURS_50:
                return $stats['total_hours'] >= 50;
            case Achievement::CODE_HOURS_100:
                return $stats['total_hours'] >= 100;
            case Achievement::CODE_HOURS_250:
                return $stats['total_hours'] >= 250;
            case Achievement::CODE_HOURS_500:
                return $stats['total_hours'] >= 500;
            case Achievement::CODE_HOURS_1000:
                return $stats['total_hours'] >= 1000;

            // Βάρδιες
            case Achievement::CODE_FIRST_SHIFT:
                return $stats['completed_shifts'] >= 1;
            case Achievement::CODE_SHIFTS_10:
                return $stats['completed_shifts'] >= 10;
            case Achievement::CODE_SHIFTS_25:
                return $stats['completed_shifts'] >= 25;
            case Achievement::CODE_SHIFTS_50:
                return $stats['completed_shifts'] >= 50;
            case Achievement::CODE_SHIFTS_100:
                return $stats['completed_shifts'] >= 100;

            // Συνέπεια
            case Achievement::CODE_RELIABLE_10:
                return $stats['consecutive_completed'] >= 10;
            case Achievement::CODE_RELIABLE_25:
                return $stats['consecutive_completed'] >= 25;
            case Achievement::CODE_RELIABLE_50:
                return $stats['consecutive_completed'] >= 50;

            // Ειδικά
            case Achievement::CODE_WEEKEND_WARRIOR:
                return $stats['weekend_shifts'] >= 10;
            case Achievement::CODE_NIGHT_OWL:
                return $stats['night_shifts'] >= 10;
            case Achievement::CODE_MEDICAL_HERO:
                return $stats['medical_shifts'] >= 10;
            case Achievement::CODE_EARLY_ADOPTER:
                return $user->id <= 100;
            case Achievement::CODE_TEAM_PLAYER:
                return $stats['large_team_shifts'] >= 5;

            default:
                return false;
        }
    }

    /**
     * Απονομή επιτεύγματος.
     */
    protected function awardAchievement(User $user, Achievement $achievement): void
    {
        // Προσθήκη επιτεύγματος
        $user->achievements()->attach($achievement->id, [
            'earned_at' => now(),
            'notified' => false,
        ]);

        // Απονομή πόντων για το επίτευγμα
        if ($achievement->points_reward > 0) {
            VolunteerPoint::create([
                'user_id' => $user->id,
                'points' => $achievement->points_reward,
                'reason' => VolunteerPoint::REASON_ACHIEVEMENT,
                'description' => "Επίτευγμα: {$achievement->name}",
                'pointable_type' => get_class($achievement),
                'pointable_id' => $achievement->id,
            ]);

            $this->updateUserPoints($user);
        }

        Log::info("Achievement awarded", [
            'user_id' => $user->id,
            'achievement' => $achievement->code,
        ]);
    }

    /**
     * Στατιστικά χρήστη για έλεγχο επιτευγμάτων.
     */
    public function getUserStats(User $user): array
    {
        $participations = ParticipationRequest::where('volunteer_id', $user->id)
            ->where('status', ParticipationRequest::STATUS_APPROVED)
            ->with('shift.mission')
            ->get();

        $totalHours = 0;
        $weekendShifts = 0;
        $nightShifts = 0;
        $medicalShifts = 0;
        $largeTeamShifts = 0;
        $consecutiveCompleted = 0;
        $maxConsecutive = 0;

        foreach ($participations as $participation) {
            $shift = $participation->shift;
            if (!$shift) continue;

            $hours = $this->calculateShiftHours($shift);
            $totalHours += $hours;

            if ($this->isWeekend($shift)) {
                $weekendShifts++;
            }

            if ($this->isNightShift($shift)) {
                $nightShifts++;
            }

            if ($this->isMedicalMission($shift)) {
                $medicalShifts++;
            }

            // Έλεγχος για μεγάλη ομάδα (10+ εθελοντές)
            $shiftParticipants = ParticipationRequest::where('shift_id', $shift->id)
                ->where('status', ParticipationRequest::STATUS_APPROVED)
                ->count();
            if ($shiftParticipants >= 10) {
                $largeTeamShifts++;
            }
        }

        // Υπολογισμός συνεχόμενων βαρδιών χωρίς ακύρωση
        $allParticipations = ParticipationRequest::where('volunteer_id', $user->id)
            ->orderBy('created_at', 'asc')
            ->get();

        foreach ($allParticipations as $p) {
            if ($p->status === ParticipationRequest::STATUS_APPROVED) {
                $consecutiveCompleted++;
                $maxConsecutive = max($maxConsecutive, $consecutiveCompleted);
            } else if (in_array($p->status, [ParticipationRequest::STATUS_CANCELED_BY_USER, ParticipationRequest::STATUS_CANCELED_BY_ADMIN])) {
                $consecutiveCompleted = 0;
            }
        }

        return [
            'total_hours' => $totalHours,
            'completed_shifts' => $participations->count(),
            'weekend_shifts' => $weekendShifts,
            'night_shifts' => $nightShifts,
            'medical_shifts' => $medicalShifts,
            'large_team_shifts' => $largeTeamShifts,
            'consecutive_completed' => $maxConsecutive,
            'total_points' => $user->total_points ?? 0,
        ];
    }

    /**
     * Leaderboard - Κατάταξη.
     */
    public function getLeaderboard(string $period = 'all', int $limit = 10): array
    {
        $query = User::where('is_active', true)
            ->whereHas('volunteerProfile');

        if ($period === 'monthly') {
            $users = $query->orderBy('monthly_points', 'desc')
                ->limit($limit)
                ->get(['id', 'name', 'monthly_points as points']);
        } else {
            $users = $query->orderBy('total_points', 'desc')
                ->limit($limit)
                ->get(['id', 'name', 'total_points as points']);
        }

        return $users->map(function ($user, $index) {
            return [
                'rank' => $index + 1,
                'user_id' => $user->id,
                'name' => $user->name,
                'points' => $user->points ?? 0,
                'achievements_count' => $user->achievements()->count(),
            ];
        })->toArray();
    }

    /**
     * Θέση χρήστη στην κατάταξη.
     */
    public function getUserRank(User $user, string $period = 'all'): int
    {
        $pointsColumn = $period === 'monthly' ? 'monthly_points' : 'total_points';
        $userPoints = $user->$pointsColumn ?? 0;

        return User::where('is_active', true)
            ->where($pointsColumn, '>', $userPoints)
            ->count() + 1;
    }

    /**
     * Υπολογισμός ωρών βάρδιας.
     */
    protected function calculateShiftHours(Shift $shift): float
    {
        $start = Carbon::parse($shift->start_time);
        $end = Carbon::parse($shift->end_time);
        
        return $start->diffInMinutes($end) / 60;
    }

    /**
     * Έλεγχος αν η βάρδια είναι Σαββατοκύριακο.
     */
    protected function isWeekend(Shift $shift): bool
    {
        $date = Carbon::parse($shift->start_time);
        return $date->isWeekend();
    }

    /**
     * Έλεγχος αν η βάρδια είναι νυχτερινή (μετά τις 22:00 ή πριν τις 06:00).
     */
    protected function isNightShift(Shift $shift): bool
    {
        $startHour = Carbon::parse($shift->start_time)->hour;
        return $startHour >= 22 || $startHour < 6;
    }

    /**
     * Έλεγχος αν είναι υγειονομική αποστολή.
     */
    protected function isMedicalMission(Shift $shift): bool
    {
        if (!$shift->mission) {
            return false;
        }
        
        return str_contains(strtolower($shift->mission->mission_type ?? ''), 'υγειονομική');
    }

    /**
     * Ανανέωση μηνιαίων πόντων για όλους (για cron job).
     */
    public function refreshMonthlyPoints(): void
    {
        $users = User::whereHas('volunteerProfile')->get();

        foreach ($users as $user) {
            $monthlyPoints = VolunteerPoint::where('user_id', $user->id)
                ->where('created_at', '>=', Carbon::now()->startOfMonth())
                ->sum('points');

            $user->update(['monthly_points' => $monthlyPoints]);
        }
    }
}
