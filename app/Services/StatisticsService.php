<?php

namespace App\Services;

use App\Models\User;
use App\Modules\Missions\Models\Mission;
use App\Modules\Shifts\Models\Shift;
use App\Modules\Participation\Models\ParticipationRequest;
use App\Modules\Directory\Models\Department;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class StatisticsService
{
    /**
     * Στατιστικά για admins (dashboard).
     */
    public function getAdminStats(?int $departmentId = null): array
    {
        $missionQuery = Mission::query();
        $volunteerQuery = User::where('role', User::ROLE_VOLUNTEER);
        $participationQuery = ParticipationRequest::query();
        
        // Φίλτρο τμήματος για Department Admins
        if ($departmentId) {
            $missionQuery->where('department_id', $departmentId);
            $volunteerQuery->where('department_id', $departmentId);
            $participationQuery->whereHas('shift.mission', fn($q) => $q->where('department_id', $departmentId));
        }

        return [
            'missions_total' => (clone $missionQuery)->count(),
            'missions_open' => (clone $missionQuery)->where('status', Mission::STATUS_OPEN)->count(),
            'missions_completed' => (clone $missionQuery)->where('status', Mission::STATUS_COMPLETED)->count(),
            'missions_draft' => (clone $missionQuery)->where('status', Mission::STATUS_DRAFT)->count(),
            'volunteers_total' => (clone $volunteerQuery)->count(),
            'volunteers_active' => (clone $volunteerQuery)->where('is_active', true)->count(),
            'participations_pending' => (clone $participationQuery)->where('status', ParticipationRequest::STATUS_PENDING)->count(),
            'participations_approved' => (clone $participationQuery)->where('status', ParticipationRequest::STATUS_APPROVED)->count(),
            'total_volunteer_hours' => $this->calculateTotalVolunteerHours($departmentId),
            'this_month_hours' => $this->calculateMonthlyVolunteerHours($departmentId),
        ];
    }

    /**
     * Προσωπικά στατιστικά χρήστη.
     */
    public function getPersonalStats(User $user): array
    {
        $participations = ParticipationRequest::where('volunteer_id', $user->id);
        $totalVolunteers = User::where('role', User::ROLE_VOLUNTEER)->where('is_active', true)->count();
        $userRanking = $this->getUserRanking($user);
        
        return [
            'my_total_shifts' => (clone $participations)->count(),
            'my_approved_shifts' => (clone $participations)->where('status', ParticipationRequest::STATUS_APPROVED)->count(),
            'my_pending_shifts' => (clone $participations)->where('status', ParticipationRequest::STATUS_PENDING)->count(),
            'my_completed_shifts' => $this->getCompletedShiftsCount($user),
            'my_hours' => $this->calculateUserHours($user),
            'my_points' => $user->total_points ?? 0,
            'my_monthly_points' => $user->monthly_points ?? 0,
            'monthly_points' => $user->monthly_points ?? 0,
            'my_ranking' => $userRanking,
            'participation_streak' => $this->calculateParticipationStreak($user),
            // Πεδία που χρειάζεται το dashboard
            'member_since' => $user->created_at,
            'days_as_member' => $user->created_at ? $user->created_at->diffInDays(now()) : 0,
            'achievements_count' => $user->achievements()->count(),
            'ranking' => [
                'position' => $userRanking,
                'total' => $totalVolunteers,
                'percentile' => $totalVolunteers > 0 
                    ? round(($totalVolunteers - $userRanking + 1) / $totalVolunteers * 100) 
                    : 0,
            ],
        ];
    }

    /**
     * Εκτεταμένα στατιστικά εθελοντή (για volunteer profile).
     */
    public function getVolunteerExtendedStats(User $user): array
    {
        $basic = $this->getPersonalStats($user);
        
        $approvedParticipations = ParticipationRequest::where('volunteer_id', $user->id)
            ->where('status', ParticipationRequest::STATUS_APPROVED)
            ->with('shift.mission')
            ->get();

        $totalVolunteers = User::where('role', User::ROLE_VOLUNTEER)->where('is_active', true)->count();
        
        return array_merge($basic, [
            'total_hours' => $this->calculateUserHours($user),
            'this_month_hours' => $this->calculateUserMonthlyHours($user),
            'first_shift_date' => $this->getFirstShiftDate($user),
            'last_shift_date' => $this->getLastShiftDate($user),
            'days_since_joined' => $user->created_at ? $user->created_at->diffInDays(now()) : 0,
            'percentile' => $totalVolunteers > 0 
                ? round(($totalVolunteers - $basic['my_ranking'] + 1) / $totalVolunteers * 100) 
                : 0,
            'favorite_department' => $this->getFavoriteDepartment($user),
            'mission_types_distribution' => $this->getMissionTypesDistribution($user),
            'weekday_vs_weekend' => $this->getWeekdayVsWeekendStats($user),
            'current_streak' => $basic['participation_streak'],
            'best_streak' => $this->getBestStreak($user),
            'achievements_count' => $user->achievements()->count(),
        ]);
    }

    /**
     * Υπολογισμός συνολικών ωρών εθελοντισμού.
     */
    protected function calculateTotalVolunteerHours(?int $departmentId = null): float
    {
        $query = ParticipationRequest::where('status', ParticipationRequest::STATUS_APPROVED)
            ->whereHas('shift.mission', function($q) {
                $q->where('status', Mission::STATUS_COMPLETED);
            })
            ->with('shift');
        
        if ($departmentId) {
            $query->whereHas('shift.mission', fn($q) => $q->where('department_id', $departmentId));
        }
        
        return $query->get()->sum(function ($p) {
            if (!$p->shift) return 0;
            return Carbon::parse($p->shift->start_time)->diffInHours(Carbon::parse($p->shift->end_time));
        });
    }

    /**
     * Ώρες εθελοντισμού τρέχοντος μήνα.
     */
    protected function calculateMonthlyVolunteerHours(?int $departmentId = null): float
    {
        $query = ParticipationRequest::where('status', ParticipationRequest::STATUS_APPROVED)
            ->whereHas('shift', fn($q) => $q->where('start_time', '>=', Carbon::now()->startOfMonth()))
            ->with('shift');
        
        if ($departmentId) {
            $query->whereHas('shift.mission', fn($q) => $q->where('department_id', $departmentId));
        }
        
        return $query->get()->sum(function ($p) {
            if (!$p->shift) return 0;
            return Carbon::parse($p->shift->start_time)->diffInHours(Carbon::parse($p->shift->end_time));
        });
    }

    /**
     * Ώρες εθελοντή.
     */
    public function calculateUserHours(User $user): float
    {
        return ParticipationRequest::where('volunteer_id', $user->id)
            ->where('status', ParticipationRequest::STATUS_APPROVED)
            ->with('shift')
            ->get()
            ->sum(function ($p) {
                if (!$p->shift) return 0;
                return Carbon::parse($p->shift->start_time)->diffInHours(Carbon::parse($p->shift->end_time));
            });
    }

    /**
     * Μηνιαίες ώρες εθελοντή.
     */
    protected function calculateUserMonthlyHours(User $user): float
    {
        return ParticipationRequest::where('volunteer_id', $user->id)
            ->where('status', ParticipationRequest::STATUS_APPROVED)
            ->whereHas('shift', fn($q) => $q->where('start_time', '>=', Carbon::now()->startOfMonth()))
            ->with('shift')
            ->get()
            ->sum(function ($p) {
                if (!$p->shift) return 0;
                return Carbon::parse($p->shift->start_time)->diffInHours(Carbon::parse($p->shift->end_time));
            });
    }

    /**
     * Ολοκληρωμένες βάρδιες χρήστη.
     */
    protected function getCompletedShiftsCount(User $user): int
    {
        return ParticipationRequest::where('volunteer_id', $user->id)
            ->where('status', ParticipationRequest::STATUS_APPROVED)
            ->whereHas('shift', fn($q) => $q->where('end_time', '<', now()))
            ->count();
    }

    /**
     * Κατάταξη χρήστη.
     */
    public function getUserRanking(User $user): int
    {
        return User::where('role', User::ROLE_VOLUNTEER)
            ->where('is_active', true)
            ->where('total_points', '>', $user->total_points ?? 0)
            ->count() + 1;
    }

    /**
     * Υπολογισμός streak συμμετοχής.
     */
    protected function calculateParticipationStreak(User $user): int
    {
        $completedShifts = ParticipationRequest::where('volunteer_id', $user->id)
            ->where('status', ParticipationRequest::STATUS_APPROVED)
            ->whereHas('shift', fn($q) => $q->where('end_time', '<', now()))
            ->with('shift')
            ->get()
            ->sortByDesc(fn($p) => $p->shift->start_time);

        $streak = 0;
        $lastWeek = null;

        foreach ($completedShifts as $participation) {
            $shiftWeek = Carbon::parse($participation->shift->start_time)->weekOfYear;
            $shiftYear = Carbon::parse($participation->shift->start_time)->year;
            $weekKey = $shiftYear . '-' . $shiftWeek;

            if ($lastWeek === null) {
                $streak = 1;
                $lastWeek = $weekKey;
                continue;
            }

            $lastWeekCarbon = Carbon::now()->setISODate(
                (int) explode('-', $lastWeek)[0], 
                (int) explode('-', $lastWeek)[1]
            );
            $currentWeekCarbon = Carbon::now()->setISODate(
                (int) explode('-', $weekKey)[0],
                (int) explode('-', $weekKey)[1]
            );

            if ($lastWeekCarbon->diffInWeeks($currentWeekCarbon) === 1) {
                $streak++;
                $lastWeek = $weekKey;
            } else {
                break;
            }
        }

        return $streak;
    }

    /**
     * Καλύτερο streak εθελοντή.
     */
    protected function getBestStreak(User $user): int
    {
        // Simplified: return current streak for now
        // Could be expanded to calculate historical best
        return $this->calculateParticipationStreak($user);
    }

    /**
     * Ημερομηνία πρώτης βάρδιας.
     */
    protected function getFirstShiftDate(User $user): ?string
    {
        $first = ParticipationRequest::where('volunteer_id', $user->id)
            ->where('status', ParticipationRequest::STATUS_APPROVED)
            ->with('shift')
            ->get()
            ->sortBy(fn($p) => $p->shift?->start_time)
            ->first();

        return $first?->shift?->start_time?->format('d/m/Y');
    }

    /**
     * Ημερομηνία τελευταίας βάρδιας.
     */
    protected function getLastShiftDate(User $user): ?string
    {
        $last = ParticipationRequest::where('volunteer_id', $user->id)
            ->where('status', ParticipationRequest::STATUS_APPROVED)
            ->with('shift')
            ->get()
            ->sortByDesc(fn($p) => $p->shift?->start_time)
            ->first();

        return $last?->shift?->start_time?->format('d/m/Y');
    }

    /**
     * Αγαπημένο τμήμα εθελοντή.
     */
    protected function getFavoriteDepartment(User $user): ?string
    {
        $result = ParticipationRequest::where('volunteer_id', $user->id)
            ->where('status', ParticipationRequest::STATUS_APPROVED)
            ->with('shift.mission.department')
            ->get()
            ->groupBy(fn($p) => $p->shift?->mission?->department?->name)
            ->map->count()
            ->sortDesc()
            ->keys()
            ->first();

        return $result;
    }

    /**
     * Κατανομή τύπων αποστολών.
     */
    protected function getMissionTypesDistribution(User $user): array
    {
        return ParticipationRequest::where('volunteer_id', $user->id)
            ->where('status', ParticipationRequest::STATUS_APPROVED)
            ->with('shift.mission')
            ->get()
            ->groupBy(fn($p) => $p->shift?->mission?->type)
            ->map->count()
            ->toArray();
    }

    /**
     * Στατιστικά καθημερινές vs Σαββατοκύριακο.
     */
    protected function getWeekdayVsWeekendStats(User $user): array
    {
        $participations = ParticipationRequest::where('volunteer_id', $user->id)
            ->where('status', ParticipationRequest::STATUS_APPROVED)
            ->with('shift')
            ->get();

        $weekday = 0;
        $weekend = 0;

        foreach ($participations as $p) {
            if (!$p->shift) continue;
            $dayOfWeek = Carbon::parse($p->shift->start_time)->dayOfWeek;
            if ($dayOfWeek === Carbon::SATURDAY || $dayOfWeek === Carbon::SUNDAY) {
                $weekend++;
            } else {
                $weekday++;
            }
        }

        return ['weekday' => $weekday, 'weekend' => $weekend];
    }

    /**
     * Top εθελοντές (για leaderboard).
     */
    public function getTopVolunteers(int $limit = 10, string $period = 'all'): array
    {
        $query = User::where('role', User::ROLE_VOLUNTEER)
            ->where('is_active', true);

        if ($period === 'monthly') {
            $query->orderByDesc('monthly_points');
        } else {
            $query->orderByDesc('total_points');
        }

        return $query->take($limit)->get()->map(fn($user) => [
            'id' => $user->id,
            'name' => $user->name,
            'points' => $period === 'monthly' ? $user->monthly_points : $user->total_points,
            'department' => $user->department?->name,
        ])->toArray();
    }
}
