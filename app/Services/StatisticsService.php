<?php

namespace App\Services;

use App\Models\User;
use App\Models\VolunteerYearlyStat;
use App\Modules\Missions\Models\Mission;
use App\Modules\Shifts\Models\Shift;
use App\Modules\Participation\Models\ParticipationRequest;
use App\Modules\Directory\Models\Department;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class StatisticsService
{
    /**
     * Τρέχον έτος για στατιστικά.
     */
    protected int $currentYear;

    public function __construct()
    {
        $this->currentYear = (int) date('Y');
    }

    /**
     * Στατιστικά για admins (dashboard) - ΕΤΗΣΙΑ.
     */
    public function getAdminStats(?int $departmentId = null, ?int $year = null): array
    {
        $year = $year ?? $this->currentYear;
        $startOfYear = Carbon::create($year, 1, 1)->startOfDay();
        $endOfYear = Carbon::create($year, 12, 31)->endOfDay();

        $missionQuery = Mission::whereBetween('start_datetime', [$startOfYear, $endOfYear]);
        $volunteerQuery = User::where('role', User::ROLE_VOLUNTEER);
        $participationQuery = ParticipationRequest::whereHas('shift', function($q) use ($startOfYear, $endOfYear) {
            $q->whereBetween('start_time', [$startOfYear, $endOfYear]);
        });
        
        // Φίλτρο τμήματος για Department Admins
        if ($departmentId) {
            $missionQuery->where('department_id', $departmentId);
            $volunteerQuery->where('department_id', $departmentId);
            $participationQuery->whereHas('shift.mission', fn($q) => $q->where('department_id', $departmentId));
        }

        return [
            'year' => $year,
            'missions_total' => (clone $missionQuery)->count(),
            'missions_open' => (clone $missionQuery)->where('status', Mission::STATUS_OPEN)->count(),
            'missions_completed' => (clone $missionQuery)->where('status', Mission::STATUS_COMPLETED)->count(),
            'missions_draft' => (clone $missionQuery)->where('status', Mission::STATUS_DRAFT)->count(),
            'volunteers_total' => (clone $volunteerQuery)->count(),
            'volunteers_active' => (clone $volunteerQuery)->where('is_active', true)->count(),
            'participations_pending' => (clone $participationQuery)->where('status', ParticipationRequest::STATUS_PENDING)->count(),
            'participations_approved' => (clone $participationQuery)->where('status', ParticipationRequest::STATUS_APPROVED)->count(),
            'total_volunteer_hours' => $this->calculateTotalVolunteerHours($departmentId, $year),
            'this_month_hours' => $this->calculateMonthlyVolunteerHours($departmentId),
        ];
    }

    /**
     * Προσωπικά στατιστικά χρήστη - ΕΤΗΣΙΑ.
     */
    public function getPersonalStats(User $user, ?int $year = null): array
    {
        $year = $year ?? $this->currentYear;
        $startOfYear = Carbon::create($year, 1, 1)->startOfDay();
        $endOfYear = Carbon::create($year, 12, 31)->endOfDay();

        $participations = ParticipationRequest::where('volunteer_id', $user->id)
            ->whereHas('shift', fn($q) => $q->whereBetween('start_time', [$startOfYear, $endOfYear]));
        
        $totalVolunteers = User::where('role', User::ROLE_VOLUNTEER)->where('is_active', true)->count();
        $userRanking = $this->getUserRanking($user, $year);
        
        return [
            'year' => $year,
            'my_total_shifts' => (clone $participations)->count(),
            'my_approved_shifts' => (clone $participations)->where('status', ParticipationRequest::STATUS_APPROVED)->count(),
            'my_pending_shifts' => (clone $participations)->where('status', ParticipationRequest::STATUS_PENDING)->count(),
            'my_completed_shifts' => $this->getCompletedShiftsCount($user, $year),
            'my_hours' => $this->calculateUserHours($user, $year),
            'my_points' => $this->getUserYearlyPoints($user, $year),
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
     * Λήψη ετήσιων πόντων χρήστη.
     */
    public function getUserYearlyPoints(User $user, ?int $year = null): int
    {
        $year = $year ?? $this->currentYear;
        $startOfYear = Carbon::create($year, 1, 1)->startOfDay();
        $endOfYear = Carbon::create($year, 12, 31)->endOfDay();

        return (int) \App\Models\VolunteerPoint::where('user_id', $user->id)
            ->whereBetween('created_at', [$startOfYear, $endOfYear])
            ->sum('points');
    }

    /**
     * Λήψη ιστορικού ετήσιων στατιστικών.
     */
    public function getYearlyHistory(User $user): array
    {
        return VolunteerYearlyStat::where('user_id', $user->id)
            ->orderByDesc('year')
            ->get()
            ->toArray();
    }

    /**
     * Λήψη διαθέσιμων ετών με στατιστικά.
     */
    public function getAvailableYears(): array
    {
        $years = [];
        
        // Τρέχον έτος πάντα διαθέσιμο
        $years[] = $this->currentYear;
        
        // Έτη από αρχειοθετημένα στατιστικά
        $archivedYears = VolunteerYearlyStat::select('year')
            ->distinct()
            ->orderByDesc('year')
            ->pluck('year')
            ->toArray();
        
        return array_unique(array_merge($years, $archivedYears));
    }

    /**
     * Εκτεταμένα στατιστικά εθελοντή (για volunteer profile).
     */
    public function getVolunteerExtendedStats(User $user, ?int $year = null): array
    {
        $year = $year ?? $this->currentYear;
        $basic = $this->getPersonalStats($user, $year);
        
        $totalVolunteers = User::where('role', User::ROLE_VOLUNTEER)->where('is_active', true)->count();
        
        return array_merge($basic, [
            'year' => $year,
            'total_hours' => $this->calculateUserHours($user, $year),
            'this_month_hours' => $this->calculateUserMonthlyHours($user),
            'first_shift_date' => $this->getFirstShiftDate($user, $year),
            'last_shift_date' => $this->getLastShiftDate($user, $year),
            'days_since_joined' => $user->created_at ? $user->created_at->diffInDays(now()) : 0,
            'percentile' => $totalVolunteers > 0 
                ? round(($totalVolunteers - $basic['my_ranking'] + 1) / $totalVolunteers * 100) 
                : 0,
            'favorite_department' => $this->getFavoriteDepartment($user, $year),
            'mission_types_distribution' => $this->getMissionTypesDistribution($user, $year),
            'weekday_vs_weekend' => $this->getWeekdayVsWeekendStats($user, $year),
            'current_streak' => $basic['participation_streak'],
            'best_streak' => $this->getBestStreak($user),
            'achievements_count' => $user->achievements()->count(),
            'yearly_history' => $this->getYearlyHistory($user->id),
            'available_years' => $this->getAvailableYears($user->id),
        ]);
    }

    /**
     * Υπολογισμός συνολικών ωρών εθελοντισμού.
     */
    protected function calculateTotalVolunteerHours(?int $departmentId = null, ?int $year = null): float
    {
        $year = $year ?? $this->currentYear;
        $startOfYear = Carbon::create($year, 1, 1)->startOfDay();
        $endOfYear = Carbon::create($year, 12, 31)->endOfDay();
        
        $query = ParticipationRequest::where('status', ParticipationRequest::STATUS_APPROVED)
            ->whereHas('shift.mission', function($q) use ($startOfYear, $endOfYear) {
                $q->where('status', Mission::STATUS_COMPLETED)
                  ->whereBetween('start_datetime', [$startOfYear, $endOfYear]);
            })
            ->with('shift');
        
        if ($departmentId) {
            $query->whereHas('shift.mission', fn($q) => $q->where('department_id', $departmentId));
        }
        
        return $query->get()->sum(function ($p) {
            if (!$p->shift) return 0;
            // Χρήση πραγματικών ωρών αν υπάρχουν
            if ($p->actual_hours !== null) {
                return $p->actual_hours;
            }
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
            if ($p->actual_hours !== null) {
                return $p->actual_hours;
            }
            return Carbon::parse($p->shift->start_time)->diffInHours(Carbon::parse($p->shift->end_time));
        });
    }

    /**
     * Ώρες εθελοντή για συγκεκριμένο έτος.
     */
    public function calculateUserHours(User $user, ?int $year = null): float
    {
        $year = $year ?? $this->currentYear;
        $startOfYear = Carbon::create($year, 1, 1)->startOfDay();
        $endOfYear = Carbon::create($year, 12, 31)->endOfDay();
        
        return ParticipationRequest::where('volunteer_id', $user->id)
            ->where('status', ParticipationRequest::STATUS_APPROVED)
            ->whereHas('shift', fn($q) => $q->whereBetween('start_time', [$startOfYear, $endOfYear]))
            ->with('shift')
            ->get()
            ->sum(function ($p) {
                if (!$p->shift) return 0;
                // Χρήση πραγματικών ωρών αν υπάρχουν
                if ($p->actual_hours !== null) {
                    return $p->actual_hours;
                }
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
                if ($p->actual_hours !== null) {
                    return $p->actual_hours;
                }
                return Carbon::parse($p->shift->start_time)->diffInHours(Carbon::parse($p->shift->end_time));
            });
    }

    /**
     * Ολοκληρωμένες βάρδιες χρήστη για συγκεκριμένο έτος.
     */
    protected function getCompletedShiftsCount(User $user, ?int $year = null): int
    {
        $year = $year ?? $this->currentYear;
        $startOfYear = Carbon::create($year, 1, 1)->startOfDay();
        $endOfYear = Carbon::create($year, 12, 31)->endOfDay();
        
        return ParticipationRequest::where('volunteer_id', $user->id)
            ->where('status', ParticipationRequest::STATUS_APPROVED)
            ->whereHas('shift', fn($q) => $q
                ->where('end_time', '<', now())
                ->whereBetween('start_time', [$startOfYear, $endOfYear])
            )
            ->count();
    }

    /**
     * Κατάταξη χρήστη (βάσει ετήσιων πόντων).
     */
    public function getUserRanking(User $user, ?int $year = null): int
    {
        $year = $year ?? $this->currentYear;
        
        // Για το τρέχον έτος, χρήση total_points
        if ($year === $this->currentYear) {
            return User::where('role', User::ROLE_VOLUNTEER)
                ->where('is_active', true)
                ->where('total_points', '>', $user->total_points ?? 0)
                ->count() + 1;
        }
        
        // Για παλαιότερα έτη, υπολογισμός από ιστορικό
        $userYearPoints = VolunteerYearlyStat::where('user_id', $user->id)
            ->where('year', $year)
            ->value('total_points') ?? 0;
            
        return VolunteerYearlyStat::where('year', $year)
            ->where('total_points', '>', $userYearPoints)
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
     * Ημερομηνία πρώτης βάρδιας για συγκεκριμένο έτος.
     */
    protected function getFirstShiftDate(User $user, ?int $year = null): ?string
    {
        $query = ParticipationRequest::where('volunteer_id', $user->id)
            ->where('status', ParticipationRequest::STATUS_APPROVED)
            ->with('shift');
            
        if ($year) {
            $startOfYear = Carbon::create($year, 1, 1)->startOfDay();
            $endOfYear = Carbon::create($year, 12, 31)->endOfDay();
            $query->whereHas('shift', fn($q) => $q->whereBetween('start_time', [$startOfYear, $endOfYear]));
        }
        
        $first = $query->get()
            ->sortBy(fn($p) => $p->shift?->start_time)
            ->first();

        return $first?->shift?->start_time?->format('d/m/Y');
    }

    /**
     * Ημερομηνία τελευταίας βάρδιας για συγκεκριμένο έτος.
     */
    protected function getLastShiftDate(User $user, ?int $year = null): ?string
    {
        $query = ParticipationRequest::where('volunteer_id', $user->id)
            ->where('status', ParticipationRequest::STATUS_APPROVED)
            ->with('shift');
            
        if ($year) {
            $startOfYear = Carbon::create($year, 1, 1)->startOfDay();
            $endOfYear = Carbon::create($year, 12, 31)->endOfDay();
            $query->whereHas('shift', fn($q) => $q->whereBetween('start_time', [$startOfYear, $endOfYear]));
        }
        
        $last = $query->get()
            ->sortByDesc(fn($p) => $p->shift?->start_time)
            ->first();

        return $last?->shift?->start_time?->format('d/m/Y');
    }

    /**
     * Αγαπημένο τμήμα εθελοντή για συγκεκριμένο έτος.
     */
    protected function getFavoriteDepartment(User $user, ?int $year = null): ?string
    {
        $query = ParticipationRequest::where('volunteer_id', $user->id)
            ->where('status', ParticipationRequest::STATUS_APPROVED)
            ->with('shift.mission.department');
            
        if ($year) {
            $startOfYear = Carbon::create($year, 1, 1)->startOfDay();
            $endOfYear = Carbon::create($year, 12, 31)->endOfDay();
            $query->whereHas('shift', fn($q) => $q->whereBetween('start_time', [$startOfYear, $endOfYear]));
        }
        
        $result = $query->get()
            ->groupBy(fn($p) => $p->shift?->mission?->department?->name)
            ->map->count()
            ->sortDesc()
            ->keys()
            ->first();

        return $result;
    }

    /**
     * Κατανομή τύπων αποστολών για συγκεκριμένο έτος.
     */
    protected function getMissionTypesDistribution(User $user, ?int $year = null): array
    {
        $query = ParticipationRequest::where('volunteer_id', $user->id)
            ->where('status', ParticipationRequest::STATUS_APPROVED)
            ->with('shift.mission');
            
        if ($year) {
            $startOfYear = Carbon::create($year, 1, 1)->startOfDay();
            $endOfYear = Carbon::create($year, 12, 31)->endOfDay();
            $query->whereHas('shift', fn($q) => $q->whereBetween('start_time', [$startOfYear, $endOfYear]));
        }
        
        return $query->get()
            ->groupBy(fn($p) => $p->shift?->mission?->type)
            ->map->count()
            ->toArray();
    }

    /**
     * Στατιστικά καθημερινές vs Σαββατοκύριακο για συγκεκριμένο έτος.
     */
    protected function getWeekdayVsWeekendStats(User $user, ?int $year = null): array
    {
        $query = ParticipationRequest::where('volunteer_id', $user->id)
            ->where('status', ParticipationRequest::STATUS_APPROVED)
            ->with('shift');
            
        if ($year) {
            $startOfYear = Carbon::create($year, 1, 1)->startOfDay();
            $endOfYear = Carbon::create($year, 12, 31)->endOfDay();
            $query->whereHas('shift', fn($q) => $q->whereBetween('start_time', [$startOfYear, $endOfYear]));
        }
        
        $participations = $query->get();

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
     * Top εθελοντές (για leaderboard) - ετήσια κατάταξη.
     */
    public function getTopVolunteers(int $limit = 10, string $period = 'yearly', ?int $year = null): array
    {
        $year = $year ?? $this->currentYear;
        
        // Για το τρέχον έτος
        if ($year === $this->currentYear) {
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
                'hours' => $this->calculateUserHours($user, $year),
                'department' => $user->department?->name,
            ])->toArray();
        }
        
        // Για παλαιότερα έτη - από αρχείο
        return VolunteerYearlyStat::where('year', $year)
            ->orderByDesc('total_points')
            ->take($limit)
            ->with('user')
            ->get()
            ->map(fn($stat) => [
                'id' => $stat->user_id,
                'name' => $stat->user?->name ?? 'Άγνωστος',
                'points' => $stat->total_points,
                'hours' => $stat->total_hours,
                'department' => $stat->favorite_department,
            ])->toArray();
    }
    
    /**
     * Αρχειοθέτηση ετήσιων στατιστικών για όλους τους εθελοντές.
     */
    public function archiveYearlyStats(int $year): int
    {
        $volunteers = User::where('role', User::ROLE_VOLUNTEER)->get();
        $archived = 0;
        
        foreach ($volunteers as $volunteer) {
            $stats = $this->getVolunteerExtendedStats($volunteer, $year);
            
            VolunteerYearlyStat::updateOrCreate(
                ['user_id' => $volunteer->id, 'year' => $year],
                [
                    'total_shifts' => $stats['my_total_shifts'] ?? 0,
                    'completed_shifts' => $stats['my_approved_shifts'] ?? 0,
                    'no_show_count' => $this->getNoShowCount($volunteer, $year),
                    'total_hours' => $stats['total_hours'] ?? 0,
                    'total_points' => $stats['my_points'] ?? 0,
                    'achievements_earned' => $stats['achievements_count'] ?? 0,
                    'final_ranking' => $stats['my_ranking'] ?? 0,
                    'weekend_shifts' => $stats['weekday_vs_weekend']['weekend'] ?? 0,
                    'night_shifts' => $this->getNightShiftsCount($volunteer, $year),
                    'medical_missions' => $this->getMedicalMissionsCount($volunteer, $year),
                    'best_streak' => $stats['best_streak'] ?? 0,
                    'favorite_department' => $stats['favorite_department'],
                ]
            );
            $archived++;
        }
        
        return $archived;
    }
    
    /**
     * Αριθμός no-shows για χρήστη σε συγκεκριμένο έτος.
     */
    protected function getNoShowCount(User $user, int $year): int
    {
        $startOfYear = Carbon::create($year, 1, 1)->startOfDay();
        $endOfYear = Carbon::create($year, 12, 31)->endOfDay();
        
        return ParticipationRequest::where('volunteer_id', $user->id)
            ->where('status', ParticipationRequest::STATUS_APPROVED)
            ->where('attended', false)
            ->whereHas('shift', fn($q) => $q->whereBetween('start_time', [$startOfYear, $endOfYear]))
            ->count();
    }
    
    /**
     * Αριθμός νυχτερινών βαρδιών για χρήστη σε συγκεκριμένο έτος.
     */
    protected function getNightShiftsCount(User $user, int $year): int
    {
        $startOfYear = Carbon::create($year, 1, 1)->startOfDay();
        $endOfYear = Carbon::create($year, 12, 31)->endOfDay();
        
        return ParticipationRequest::where('volunteer_id', $user->id)
            ->where('status', ParticipationRequest::STATUS_APPROVED)
            ->whereHas('shift', function($q) use ($startOfYear, $endOfYear) {
                $q->whereBetween('start_time', [$startOfYear, $endOfYear])
                  ->whereRaw('HOUR(start_time) >= 22 OR HOUR(start_time) < 6');
            })
            ->count();
    }
    
    /**
     * Αριθμός ιατρικών αποστολών για χρήστη σε συγκεκριμένο έτος.
     */
    protected function getMedicalMissionsCount(User $user, int $year): int
    {
        $startOfYear = Carbon::create($year, 1, 1)->startOfDay();
        $endOfYear = Carbon::create($year, 12, 31)->endOfDay();
        
        return ParticipationRequest::where('volunteer_id', $user->id)
            ->where('status', ParticipationRequest::STATUS_APPROVED)
            ->whereHas('shift.mission', function($q) use ($startOfYear, $endOfYear) {
                $q->where('type', Mission::TYPE_MEDICAL)
                  ->whereBetween('start_datetime', [$startOfYear, $endOfYear]);
            })
            ->count();
    }
}
