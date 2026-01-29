<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Achievement;
use App\Models\User;
use App\Models\VolunteerYearlyStat;
use App\Services\GamificationService;
use App\Services\StatisticsService;
use Illuminate\Http\Request;

class GamificationController extends Controller
{
    protected GamificationService $gamificationService;
    protected StatisticsService $statisticsService;

    public function __construct(GamificationService $gamificationService, StatisticsService $statisticsService)
    {
        $this->gamificationService = $gamificationService;
        $this->statisticsService = $statisticsService;
    }

    /**
     * Σελίδα Leaderboard (ετήσια κατάταξη).
     */
    public function leaderboard(Request $request)
    {
        $currentYear = now()->year;
        $selectedYear = (int) $request->get('year', $currentYear);
        $period = $request->get('period', 'yearly');
        
        // Διαθέσιμα έτη
        $availableYears = $this->getLeaderboardYears();
        
        // Leaderboard με βάση το έτος
        $leaderboard = $this->statisticsService->getTopVolunteers(20, $period, $selectedYear);
        
        // Θέση του τρέχοντος χρήστη
        $currentUser = auth()->user();
        $userRank = null;
        $userStats = null;
        
        if ($currentUser) {
            $userRank = $this->statisticsService->getUserRanking($currentUser, $selectedYear);
            $userStats = $this->gamificationService->getUserStats($currentUser);
            $userStats['yearly_hours'] = $this->statisticsService->calculateUserHours($currentUser, $selectedYear);
            $userStats['yearly_points'] = $this->statisticsService->getUserYearlyPoints($currentUser->id, $selectedYear);
        }

        return view('gamification.leaderboard', compact(
            'leaderboard',
            'period',
            'userRank',
            'userStats',
            'currentUser',
            'selectedYear',
            'currentYear',
            'availableYears'
        ));
    }
    
    /**
     * Λίστα διαθέσιμων ετών για leaderboard.
     */
    protected function getLeaderboardYears(): array
    {
        $years = VolunteerYearlyStat::distinct()->pluck('year')->toArray();
        $currentYear = now()->year;
        
        if (!in_array($currentYear, $years)) {
            $years[] = $currentYear;
        }
        
        rsort($years);
        return $years;
    }

    /**
     * Σελίδα Επιτευγμάτων.
     */
    public function achievements()
    {
        $user = auth()->user();
        
        // Όλα τα ενεργά επιτεύγματα
        $allAchievements = Achievement::active()
            ->orderBy('category')
            ->orderBy('threshold')
            ->get();
        
        // Κερδισμένα επιτεύγματα του χρήστη
        $earnedAchievementIds = $user->achievements()->pluck('achievements.id')->toArray();
        
        // Ομαδοποίηση ανά κατηγορία
        $achievementsByCategory = $allAchievements->groupBy('category');
        
        // Στατιστικά χρήστη
        $userStats = $this->gamificationService->getUserStats($user);
        
        // Πρόοδος για το επόμενο επίτευγμα
        $nextAchievements = $this->getNextAchievements($user, $allAchievements, $earnedAchievementIds, $userStats);

        return view('gamification.achievements', compact(
            'achievementsByCategory',
            'earnedAchievementIds',
            'userStats',
            'nextAchievements'
        ));
    }

    /**
     * Ιστορικό πόντων χρήστη.
     */
    public function pointsHistory()
    {
        $user = auth()->user();
        
        $points = $user->volunteerPoints()
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        $totalPoints = $user->total_points ?? 0;
        $monthlyPoints = $user->monthly_points ?? 0;

        return view('gamification.points-history', compact(
            'points',
            'totalPoints',
            'monthlyPoints'
        ));
    }

    /**
     * Χειροκίνητη απονομή πόντων (μόνο για admins).
     */
    public function awardPoints(Request $request)
    {
        $this->authorize('viewAny', User::class);

        $request->validate([
            'user_id' => 'required|exists:users,id',
            'points' => 'required|integer|min:1|max:1000',
            'description' => 'required|string|max:255',
        ]);

        $user = User::findOrFail($request->user_id);
        
        $this->gamificationService->awardManualPoints(
            $user,
            $request->points,
            $request->description
        );

        return redirect()->back()->with('success', "Απονεμήθηκαν {$request->points} πόντοι στον {$user->name}");
    }

    /**
     * Επόμενα επιτεύγματα προς κατάκτηση.
     */
    protected function getNextAchievements(User $user, $allAchievements, array $earnedIds, array $stats): array
    {
        $next = [];

        foreach ($allAchievements as $achievement) {
            if (in_array($achievement->id, $earnedIds)) {
                continue;
            }

            $progress = $this->calculateProgress($achievement, $stats);
            
            if ($progress !== null && $progress < 100) {
                $next[] = [
                    'achievement' => $achievement,
                    'progress' => $progress,
                    'current' => $this->getCurrentValue($achievement, $stats),
                    'target' => $achievement->threshold,
                ];
            }
        }

        // Ταξινόμηση κατά πρόοδο (φθίνουσα)
        usort($next, fn($a, $b) => $b['progress'] <=> $a['progress']);

        return array_slice($next, 0, 5);
    }

    /**
     * Υπολογισμός προόδου για επίτευγμα.
     */
    protected function calculateProgress(Achievement $achievement, array $stats): ?float
    {
        $current = $this->getCurrentValue($achievement, $stats);
        if ($current === null) {
            return null;
        }

        return min(100, ($current / $achievement->threshold) * 100);
    }

    /**
     * Τρέχουσα τιμή για επίτευγμα.
     */
    protected function getCurrentValue(Achievement $achievement, array $stats): ?int
    {
        return match ($achievement->code) {
            Achievement::CODE_HOURS_50,
            Achievement::CODE_HOURS_100,
            Achievement::CODE_HOURS_250,
            Achievement::CODE_HOURS_500,
            Achievement::CODE_HOURS_1000 => (int) $stats['total_hours'],

            Achievement::CODE_FIRST_SHIFT,
            Achievement::CODE_SHIFTS_10,
            Achievement::CODE_SHIFTS_25,
            Achievement::CODE_SHIFTS_50,
            Achievement::CODE_SHIFTS_100 => $stats['completed_shifts'],

            Achievement::CODE_RELIABLE_10,
            Achievement::CODE_RELIABLE_25,
            Achievement::CODE_RELIABLE_50 => $stats['consecutive_completed'],

            Achievement::CODE_WEEKEND_WARRIOR => $stats['weekend_shifts'],
            Achievement::CODE_NIGHT_OWL => $stats['night_shifts'],
            Achievement::CODE_MEDICAL_HERO => $stats['medical_shifts'],
            Achievement::CODE_TEAM_PLAYER => $stats['large_team_shifts'],

            default => null,
        };
    }
}
