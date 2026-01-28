<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\StatisticsService;
use App\Modules\Missions\Models\Mission;
use App\Modules\Participation\Models\ParticipationRequest;
use Illuminate\Support\Facades\Auth;

class DashboardController extends Controller
{
    public function __construct(
        protected StatisticsService $statisticsService
    ) {}

    /**
     * Εμφάνιση κεντρικού dashboard.
     */
    public function index()
    {
        $user = Auth::user();
        
        // Στατιστικά για admins (χρήση service)
        $adminStats = [];
        if ($user->isAdmin()) {
            $departmentId = $user->hasRole(User::ROLE_DEPARTMENT_ADMIN) ? $user->department_id : null;
            $adminStats = $this->statisticsService->getAdminStats($departmentId);
        }
        
        // Προσωπικά στατιστικά (χρήση service)
        $personalStats = $this->statisticsService->getPersonalStats($user);
        
        // Πρόσφατες αποστολές - με eager loading (χρήση config για count)
        $recentMissionsCount = config('volunteerops.dashboard.recent_missions_count', 5);
        $recentMissions = Mission::with('department')
            ->where('status', Mission::STATUS_OPEN)
            ->orderBy('created_at', 'desc')
            ->take($recentMissionsCount)
            ->get();
        
        // Επερχόμενες βάρδιες του χρήστη - με πλήρες eager loading
        $upcomingShiftsCount = config('volunteerops.dashboard.upcoming_shifts_count', 5);
        $upcomingShifts = ParticipationRequest::where('volunteer_id', $user->id)
            ->where('status', ParticipationRequest::STATUS_APPROVED)
            ->whereHas('shift', function ($q) {
                $q->where('start_time', '>=', now());
            })
            ->with(['shift.mission.department'])
            ->orderBy('created_at', 'desc')
            ->take($upcomingShiftsCount)
            ->get();
        
        return view('dashboard', compact('user', 'adminStats', 'personalStats', 'recentMissions', 'upcomingShifts'));
    }
}
